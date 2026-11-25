<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Whmcs;

use ClientFence\InformationException;

/**
 * ClientFence: what the WHMCS import needs from this server, checked before it writes anything
 * (owner decision 2026-10-19): the plan lists each check with the fix, and apply refuses to start
 * or go on while one fails, so an import never dies halfway on a limit that could have been
 * raised first.
 *
 * The checks are of the PHP process that runs them: the admin tab checks the website's PHP, the
 * console its own (often another php.ini, with a lower memory limit).
 */
final class Preflight
{
    /** PHP memory limit the import needs (bytes). */
    public const MEMORY = 256 * 1024 * 1024;

    /** The database's largest statement the import needs (bytes; licence log batches, emails). */
    public const PACKET = 16 * 1024 * 1024;

    /** Free disk space kept on top of the ticket files to copy (bytes). */
    public const DISK_MARGIN = 200 * 1024 * 1024;

    /**
     * Each check: what it is, whether it passes, what was found and how to fix it. Free disk space
     * is judged only for a run that has not started ($starting): a started run has already used
     * some of it for the files it copied. The texts are in English with their values in params, translated when
     * read (localize()): the plan saves them.
     *
     * @return list<array{check: string, ok: bool, detail: string, fix: string, params: array<string, mixed>}>
     */
    public static function checks(\Pimple\Container $di, ?Source $source = null, bool $starting = true): array
    {
        $settings = Import::settings($di);
        $checks = [self::memory((string) ini_get('memory_limit'), PHP_SAPI === 'cli')];

        $checks[] = function_exists('gzopen')
            ? self::pass(self::msg('PHP zlib extension'), self::msg('Installed (the add-on module archive is gzipped).'))
            : self::fail(self::msg('PHP zlib extension'), self::msg('Missing: the add-on module archive cannot be written.'), self::msg('Install or enable PHP\'s zlib extension.'));

        $packet = self::packet($di);
        if ($packet !== null) {
            $checks[] = $packet >= self::PACKET
                ? self::pass(self::msg('Database max_allowed_packet'), ':size.', [':size' => self::size($packet)])
                : self::fail(self::msg('Database max_allowed_packet'), self::msg(':size: some of the import\'s statements are larger.'), self::msg('Raise max_allowed_packet to :needed or more in the database server\'s settings.'), [':size' => self::size($packet), ':needed' => self::size(self::PACKET)]);
        }

        // A Blesta import reads the attachments its read copied (Run::work()); none were copied when the folder is missing.
        $blesta = $settings['blesta'] === 1;
        $copies = \ClientFence\Mod\Clientfence\Blesta\Snapshot::files();
        $attachments = $blesta ? (is_dir($copies) ? $copies : '') : trim($settings['attachments_dir']);
        $attachmentBytes = 0;
        if ($attachments !== '') {
            if (!is_dir($attachments) || !is_readable($attachments)) {
                $checks[] = self::fail(self::msg(':system attachments folder'), self::msg(':dir cannot be read by :user.'), self::msg('Correct the folder, or let :user read it.'), [':dir' => $attachments, ':user' => self::user()]);
            } else {
                $attachmentBytes = self::folderSize($attachments);
                $checks[] = self::pass(self::msg(':system attachments folder'), ':dir (:size).', [':dir' => $attachments, ':size' => self::size($attachmentBytes)]);
            }
        } elseif (!$blesta && $source !== null && self::namesAttachments($source)) {
            $checks[] = self::fail(self::msg(':system attachments folder'), self::msg('Not entered, and :system tickets have attachments: their messages would come over without the files.'), self::msg('Enter :system\'s attachments folder (console: --attachments). An empty folder imports the messages without their files, on purpose.'));
        }

        if ($source !== null) {
            array_push($checks, ...self::data($source, $starting));
        }

        $checks[] = self::folder(self::msg('Ticket files folder'), \ClientFence\Mod\Support\TicketFiles::dir(), $starting ? $attachmentBytes : null);
        $checks[] = self::folder(self::msg('Backups folder (add-on module archive)'), \ClientFence\Mod\Clientfence\Backups::localDir(), $starting ? 0 : null);

        // :system names the system the data came from (a snapshot import's is not WHMCS).
        $system = Import::system($settings);

        return array_map(static fn (array $c): array => ['params' => $c['params'] + [':system' => $system]] + $c, $checks);
    }

    /**
     * Checks in the reader's language (a saved plan was made in its maker's): a list value is translated item by
     * item. Checks saved before their texts had params are English already.
     *
     * @param list<array<string, mixed>> $checks
     *
     * @return list<array<string, mixed>>
     */
    public static function localize(array $checks): array
    {
        return array_map(static function (array $check): array {
            $params = array_map(static fn ($value): string => is_array($value) ? implode(', ', array_map(static fn ($item): string => __trans((string) $item), $value)) : (string) $value, $check['params'] ?? []);
            // __trans('') would answer the catalogue's header.
            $text = static fn (string $key): string => (string) ($check[$key] ?? '') === '' ? '' : __trans((string) $check[$key], $params);

            return ['check' => $text('check'), 'detail' => $text('detail'), 'fix' => $text('fix')] + $check;
        }, $checks);
    }

    /**
     * WHMCS data the import cannot take as it is: a currency without a three-letter code (its clients and invoices
     * would keep a code ClientFence does not have, and their pages fail), and tables not in UTF-8 (old installs keep
     * UTF-8 text in latin1 tables, which comes over garbled: "José" as "JosÃ©").
     *
     * @return list<array{check: string, ok: bool, detail: string, fix: string}>
     */
    private static function data(Source $source, bool $starting): array
    {
        $checks = [];
        if ($source->hasTable('tblcurrencies')) {
            $bad = [];
            foreach ($source->rows('SELECT id, code FROM tblcurrencies ORDER BY id') as $row) {
                if (preg_match('/^[A-Z]{3}$/', strtoupper(trim((string) $row['code']))) !== 1) {
                    $bad[] = sprintf('#%d "%s"', (int) $row['id'], (string) $row['code']);
                }
            }
            $checks[] = $bad === []
                ? self::pass(self::msg(':system currency codes'), self::msg('Every currency has a three-letter code.'))
                : self::fail(self::msg(':system currency codes'), self::msg('Not a three-letter code: :codes.'), self::msg('In :system (System Settings > Currencies), give each its ISO code (for example CAD), then plan again.'), [':codes' => implode(', ', $bad)]);
        }
        try {
            $latin = $source->connection()->fetchFirstColumn(
                "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'tbl%' AND TABLE_COLLATION NOT LIKE 'utf8%' ORDER BY TABLE_NAME"
            );
        } catch (\Throwable) {
            $latin = null; // not MySQL or MariaDB (tests)
        }
        if ($latin !== null) {
            // QA r4 W-09: a plain CONVERT TO CHARACTER SET keeps UTF-8 kept in latin1 garbled; through a binary type keeps the bytes.
            $convert = self::msg('In a copy of the :system database, change each text column of the affected tables to a binary type (VARBINARY or BLOB) and then to utf8mb4 text, which keeps the bytes and reads them as UTF-8 (a plain CONVERT TO CHARACTER SET keeps the text garbled); then import from the copy.');
            $checks[] = match (true) {
                $latin === [] => self::pass(self::msg(':system table encoding'), self::msg('Every table is UTF-8.')),
                count($latin) <= 10 => self::fail(self::msg(':system table encoding'), self::msg('Not UTF-8: :tables. Text in them may come over garbled.'), $convert, [':tables' => implode(', ', $latin)]),
                default => self::fail(self::msg(':system table encoding'), self::msg('Not UTF-8: :tables and :more more. Text in them may come over garbled.'), $convert, [':tables' => implode(', ', array_slice($latin, 0, 10)), ':more' => count($latin) - 10]),
            };
        }
        // UTF-8 stored through a latin1 connection or table reads as "JosÃ©" even in a UTF-8 table (a plain CONVERT TO
        // CHARACTER SET keeps it so): the UTF-8 of Ã or Â before a second-half Latin-1 character. Judged on the client
        // names, once when a run starts (tickets are large, and a stray reply is what WHMCS shows too): it fails when
        // most client names with accents are so, and only notes a few.
        try {
            $columns = $starting ? array_intersect(['firstname', 'lastname', 'companyname', 'address1', 'city'], $source->columns('tblclients')) : [];
            $counts = $columns === [] ? null : $source->connection()->fetchAssociative('SELECT
                SUM(' . implode(' OR ', array_map(static fn (string $c): string => "LOCATE(X'C383C2', CAST(`{$c}` AS BINARY)) > 0 OR LOCATE(X'C382C2', CAST(`{$c}` AS BINARY)) > 0", $columns)) . ') AS doubled,
                SUM(' . implode(' OR ', array_map(static fn (string $c): string => "LENGTH(`{$c}`) <> CHAR_LENGTH(`{$c}`)", $columns)) . ') AS accented
                FROM tblclients');
        } catch (\Throwable) {
            $counts = null; // not MySQL or MariaDB (tests)
        }
        if (is_array($counts)) {
            [$doubled, $accented] = [(int) $counts['doubled'], (int) $counts['accented']];
            // The repair takes the doubled rows back to their bytes through latin1, only those rows.
            $checks[] = $doubled > 0 && $doubled * 2 >= $accented
                ? self::fail(self::msg(':system text encoding'), self::msg(':doubled of :accented client names with accents are double-encoded (such as "JosÃ©" for "José").'), self::msg('In a copy of the :system database, repair only the affected rows, for each column: UPDATE tblclients SET col = CONVERT(CAST(CONVERT(col USING latin1) AS BINARY) USING utf8mb4) WHERE LOCATE(X\'C383C2\', CAST(col AS BINARY)) > 0 OR LOCATE(X\'C382C2\', CAST(col AS BINARY)) > 0; then import from the copy.'), [':doubled' => $doubled, ':accented' => $accented])
                : self::pass(self::msg(':system text encoding'), $doubled === 0 ? self::msg('No double-encoded client names.') : self::msg(':doubled client names look double-encoded (such as "JosÃ©"); they come over as :system shows them.'), [':doubled' => $doubled]);
        }

        return $checks;
    }

    /** Throws, naming each failed check and its fix, unless every check passes. */
    public static function assert(\Pimple\Container $di, ?Source $source = null, bool $starting = true): void
    {
        $failed = array_filter(self::localize(self::checks($di, $source, $starting)), static fn (array $check): bool => !$check['ok']);
        if ($failed === []) {
            return;
        }
        $lines = array_map(static fn (array $check): string => $check['check'] . ': ' . $check['detail'] . ' ' . $check['fix'], $failed);

        throw new InformationException('The import did not start: fix these first, then start it again. :problems', [':problems' => implode(' | ', $lines)]);
    }

    /** Bytes of a php.ini size ("128M", "1G", "-1": null for unlimited). */
    public static function bytes(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return null;
        }
        $number = (int) $value;
        $unit = strtolower(substr($value, -1));

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * The memory limit check of a php.ini memory_limit, for the console or the website.
     *
     * @return array{check: string, ok: bool, detail: string, fix: string, params: array<string, mixed>}
     */
    public static function memory(string $memoryLimit, bool $console): array
    {
        $limit = self::bytes($memoryLimit);
        $where = [$console ? self::msg('the console\'s PHP') : self::msg('the website\'s PHP')];
        if ($limit === null || $limit >= self::MEMORY) {
            return self::pass(self::msg('PHP memory limit'), ':size (:where).', [':size' => $limit === null ? [self::msg('Unlimited')] : self::size($limit), ':where' => $where]);
        }
        $fix = $console
            ? self::msg('Raise memory_limit to 256M or more in the php.ini the console uses (php --ini names it), or run the console with php -d memory_limit=512M.')
            : self::msg('Raise memory_limit to 256M or more in this site\'s PHP settings (php.ini or the hosting panel), then reload this page.');

        return self::fail(self::msg('PHP memory limit'), self::msg(':size (:where); the import needs :needed.'), $fix, [':size' => self::size($limit), ':where' => $where, ':needed' => self::size(self::MEMORY)]);
    }

    /**
     * A folder the import writes: it exists and can be written, or can be made; with room for
     * $bytes and the margin (null: free space is not judged).
     *
     * @return array{check: string, ok: bool, detail: string, fix: string, params: array<string, mixed>}
     */
    private static function folder(string $label, string $dir, ?int $bytes): array
    {
        $existing = $dir;
        while (!is_dir($existing) && dirname($existing) !== $existing) {
            $existing = dirname($existing);
        }
        if (!is_writable($existing)) {
            return self::fail($label, $existing === $dir ? self::msg(':dir cannot be written by :user.') : self::msg(':dir cannot be made by :user.'), self::msg('Make the folder and let :user write it.'), [':dir' => $dir, ':user' => self::user()]);
        }
        $free = @disk_free_space($existing);
        if ($bytes !== null && $free !== false && $free < $bytes + self::DISK_MARGIN) {
            return self::fail($label, self::msg(':dir: :free free, :needed needed.'), self::msg('Free disk space first.'), [':dir' => $dir, ':free' => self::size((int) $free), ':needed' => self::size($bytes + self::DISK_MARGIN)]);
        }

        return $free !== false ? self::pass($label, self::msg(':dir (:free free).'), [':dir' => $dir, ':free' => self::size((int) $free)]) : self::pass($label, ':dir.', [':dir' => $dir]);
    }

    /** The target database's max_allowed_packet (MySQL and MariaDB; null elsewhere). */
    private static function packet(\Pimple\Container $di): ?int
    {
        $db = $di['em']->getConnection();
        if (!$db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
            return null;
        }
        try {
            return (int) $db->fetchOne('SELECT @@max_allowed_packet');
        } catch (\Throwable) {
            return null;
        }
    }

    private static function namesAttachments(Source $source): bool
    {
        foreach (['tbltickets', 'tblticketreplies'] as $table) {
            if ($source->hasTable($table) && $source->count($table, "attachment <> ''") > 0) {
                return true;
            }
        }

        return false;
    }

    private static function folderSize(string $dir): int
    {
        $bytes = 0;
        try {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile()) {
                    $bytes += (int) $file->getSize();
                }
            }
        } catch (\Throwable) {
        }

        return $bytes;
    }

    /** @return string|list<string> the PHP user's name, or a text that stands for it (a list: translated when read) */
    private static function user(): string|array
    {
        $user = function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : '';

        return $user !== '' ? $user : [self::msg('the PHP user')];
    }

    private static function size(int $bytes): string
    {
        return $bytes >= 1024 * 1024 * 1024 ? round($bytes / 1073741824, 1) . ' GB' : round($bytes / 1048576) . ' MB';
    }

    /** @return array{check: string, ok: bool, detail: string, fix: string, params: array<string, mixed>} */
    private static function pass(string $check, string $detail, array $params = []): array
    {
        return ['check' => $check, 'ok' => true, 'detail' => $detail, 'fix' => '', 'params' => $params];
    }

    /** @return array{check: string, ok: bool, detail: string, fix: string, params: array<string, mixed>} */
    private static function fail(string $check, string $detail, string $fix, array $params = []): array
    {
        return ['check' => $check, 'ok' => false, 'detail' => $detail, 'fix' => $fix, 'params' => $params];
    }

    /** Marks a text for tools/locales.py extract; it is translated when read (localize()). */
    private static function msg(string $text): string
    {
        return $text;
    }
}
