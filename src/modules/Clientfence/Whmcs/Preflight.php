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

    /** How to fix WHMCS text kept as UTF-8 bytes in latin1 (QA r4 W-09: a plain CONVERT TO CHARACTER SET keeps it garbled). */
    private const CONVERT = 'In a copy of the WHMCS database, change each text column of the affected tables to a binary type (VARBINARY or BLOB) and then to utf8mb4 text, which keeps the bytes and reads them as UTF-8 (a plain CONVERT TO CHARACTER SET keeps the text garbled); then import from the copy.';

    /** How to repair UTF-8 text stored twice encoded in a UTF-8 table (back to its bytes through latin1, only those rows). */
    private const REPAIR = 'In a copy of the WHMCS database, repair only the affected rows, for each column: UPDATE tblclients SET col = CONVERT(CAST(CONVERT(col USING latin1) AS BINARY) USING utf8mb4) WHERE LOCATE(X\'C383C2\', CAST(col AS BINARY)) > 0 OR LOCATE(X\'C382C2\', CAST(col AS BINARY)) > 0; then import from the copy.';

    /** Free disk space kept on top of the ticket files to copy (bytes). */
    public const DISK_MARGIN = 200 * 1024 * 1024;

    /**
     * Each check: what it is, whether it passes, what was found and how to fix it. Free disk space
     * is judged only for a run that has not started ($starting): a started run has already used
     * some of it for the files it copied.
     *
     * @return list<array{check: string, ok: bool, detail: string, fix: string}>
     */
    public static function checks(\Pimple\Container $di, ?Source $source = null, bool $starting = true): array
    {
        $settings = Import::settings($di);
        $checks = [self::memory((string) ini_get('memory_limit'), PHP_SAPI === 'cli')];

        $checks[] = function_exists('gzopen')
            ? self::pass('PHP zlib extension', 'Installed (the add-on module archive is gzipped).')
            : self::fail('PHP zlib extension', 'Missing: the add-on module archive cannot be written.', 'Install or enable PHP\'s zlib extension.');

        $packet = self::packet($di);
        if ($packet !== null) {
            $checks[] = $packet >= self::PACKET
                ? self::pass('Database max_allowed_packet', self::size($packet) . '.')
                : self::fail('Database max_allowed_packet', self::size($packet) . ': some of the import\'s statements are larger.', 'Raise max_allowed_packet to ' . self::size(self::PACKET) . ' or more in the database server\'s settings.');
        }

        $attachments = trim($settings['attachments_dir']);
        $attachmentBytes = 0;
        if ($attachments !== '') {
            if (!is_dir($attachments) || !is_readable($attachments)) {
                $checks[] = self::fail('WHMCS attachments folder', $attachments . ' cannot be read by ' . self::user() . '.', 'Correct the folder, or let ' . self::user() . ' read it.');
            } else {
                $attachmentBytes = self::folderSize($attachments);
                $checks[] = self::pass('WHMCS attachments folder', $attachments . ' (' . self::size($attachmentBytes) . ').');
            }
        } elseif ($source !== null && self::namesAttachments($source)) {
            $checks[] = self::fail('WHMCS attachments folder', 'Not entered, and WHMCS tickets have attachments: their messages would come over without the files.', 'Enter WHMCS\'s attachments folder (console: --attachments). An empty folder imports the messages without their files, on purpose.');
        }

        if ($source !== null) {
            array_push($checks, ...self::data($source, $starting));
        }

        $checks[] = self::folder('Ticket files folder', \ClientFence\Mod\Support\TicketFiles::dir(), $starting ? $attachmentBytes : null);
        $checks[] = self::folder('Backups folder (add-on module archive)', \ClientFence\Mod\Clientfence\Backups::localDir(), $starting ? 0 : null);

        return $checks;
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
                ? self::pass('WHMCS currency codes', 'Every currency has a three-letter code.')
                : self::fail('WHMCS currency codes', 'Not a three-letter code: ' . implode(', ', $bad) . '.', 'In WHMCS (System Settings > Currencies), give each its ISO code (for example CAD), then plan again.');
        }
        try {
            $latin = $source->connection()->fetchFirstColumn(
                "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'tbl%' AND TABLE_COLLATION NOT LIKE 'utf8%' ORDER BY TABLE_NAME"
            );
        } catch (\Throwable) {
            $latin = null; // not MySQL or MariaDB (tests)
        }
        if ($latin !== null) {
            $checks[] = $latin === []
                ? self::pass('WHMCS table encoding', 'Every table is UTF-8.')
                : self::fail('WHMCS table encoding', 'Not UTF-8: ' . implode(', ', array_slice($latin, 0, 10)) . (count($latin) > 10 ? sprintf(' and %d more', count($latin) - 10) : '') . '. Text in them may come over garbled.', self::CONVERT);
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
            $checks[] = $doubled > 0 && $doubled * 2 >= $accented
                ? self::fail('WHMCS text encoding', sprintf('%d of %d client names with accents are double-encoded (such as "JosÃ©" for "José").', $doubled, $accented), self::REPAIR)
                : self::pass('WHMCS text encoding', $doubled === 0 ? 'No double-encoded client names.' : sprintf('%d client names look double-encoded (such as "JosÃ©"); they come over as WHMCS shows them.', $doubled));
        }

        return $checks;
    }

    /** Throws, naming each failed check and its fix, unless every check passes. */
    public static function assert(\Pimple\Container $di, ?Source $source = null, bool $starting = true): void
    {
        $failed = array_filter(self::checks($di, $source, $starting), static fn (array $check): bool => !$check['ok']);
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
     * @return array{check: string, ok: bool, detail: string, fix: string}
     */
    public static function memory(string $memoryLimit, bool $console): array
    {
        $limit = self::bytes($memoryLimit);
        $where = $console ? 'the console\'s PHP' : 'the website\'s PHP';
        if ($limit === null || $limit >= self::MEMORY) {
            return self::pass('PHP memory limit', ($limit === null ? 'Unlimited' : self::size($limit)) . ' (' . $where . ').');
        }
        $fix = $console
            ? 'Raise memory_limit to 256M or more in the php.ini the console uses (php --ini names it), or run the console with php -d memory_limit=512M.'
            : 'Raise memory_limit to 256M or more in this site\'s PHP settings (php.ini or the hosting panel), then reload this page.';

        return self::fail('PHP memory limit', self::size($limit) . ' (' . $where . '); the import needs ' . self::size(self::MEMORY) . '.', $fix);
    }

    /**
     * A folder the import writes: it exists and can be written, or can be made; with room for
     * $bytes and the margin (null: free space is not judged).
     *
     * @return array{check: string, ok: bool, detail: string, fix: string}
     */
    private static function folder(string $label, string $dir, ?int $bytes): array
    {
        $existing = $dir;
        while (!is_dir($existing) && dirname($existing) !== $existing) {
            $existing = dirname($existing);
        }
        if (!is_writable($existing)) {
            return self::fail($label, $dir . ' cannot be ' . ($existing === $dir ? 'written' : 'made') . ' by ' . self::user() . '.', 'Make the folder and let ' . self::user() . ' write it.');
        }
        $free = @disk_free_space($existing);
        if ($bytes !== null && $free !== false && $free < $bytes + self::DISK_MARGIN) {
            return self::fail($label, $dir . ': ' . self::size((int) $free) . ' free, ' . self::size($bytes + self::DISK_MARGIN) . ' needed.', 'Free disk space first.');
        }

        return self::pass($label, $dir . ($free !== false ? ' (' . self::size((int) $free) . ' free).' : '.'));
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

    private static function user(): string
    {
        $user = function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : '';

        return $user !== '' ? 'the user ' . $user : 'the PHP user';
    }

    private static function size(int $bytes): string
    {
        return $bytes >= 1024 * 1024 * 1024 ? round($bytes / 1073741824, 1) . ' GB' : round($bytes / 1048576) . ' MB';
    }

    /** @return array{check: string, ok: bool, detail: string, fix: string} */
    private static function pass(string $check, string $detail): array
    {
        return ['check' => $check, 'ok' => true, 'detail' => $detail, 'fix' => ''];
    }

    /** @return array{check: string, ok: bool, detail: string, fix: string} */
    private static function fail(string $check, string $detail, string $fix): array
    {
        return ['check' => $check, 'ok' => false, 'detail' => $detail, 'fix' => $fix];
    }
}
