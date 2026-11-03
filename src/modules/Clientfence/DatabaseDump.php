<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence;

use Doctrine\DBAL\Connection;

/**
 * ClientFence: a gzip SQL dump of the whole database in PHP (Backups.php), as WHMCS makes its
 * database backups: hosts often disable exec(), so mysqldump cannot be run. Tables (structure and
 * rows) and views, read in one consistent snapshot; restore with
 * `gunzip < file.sql.gz | mysql dbname`. Binary values are written as hex, times in UTC
 * (TIMESTAMP columns), as mysqldump does.
 *
 * Left out: the rows of `session` (live sign-ins must not travel in an unencrypted file; after a
 * restore everyone signs in again) and the values of generated columns (the server computes
 * them). Not dumped: triggers, stored routines and events; FOSSBilling uses none.
 */
final class DatabaseDump
{
    /** Rows of one INSERT statement stop growing past this many bytes. */
    private const STATEMENT_BYTES = 1024 * 1024;
    /** Written as _binary strings, as mysqldump: about their own size, so a large value stays under max_allowed_packet. */
    private const BINARY_TYPES = ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob'];
    /** Written as hex (small values whose bytes are not text). */
    private const HEX_TYPES = ['bit', 'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection'];
    /** Tables written without their rows. */
    private const NO_ROWS = ['session'];

    /**
     * Writes the dump to $file (gzip); returns the number of tables written.
     */
    public static function write(Connection $db, string $file): int
    {
        $gz = @gzopen($file, 'wb6');
        if ($gz === false) {
            throw new \RuntimeException('The backup file could not be created.');
        }
        $zone = (string) $db->fetchOne('SELECT @@session.time_zone');
        $isolation = self::isolation($db);
        $native = $db->getNativeConnection();
        $pdo = $native instanceof \PDO && $native->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? $native : null;
        $buffered = $pdo?->getAttribute(self::bufferedAttribute());
        $snapshot = !$db->isTransactionActive();
        $tables = 0;

        try {
            $db->executeStatement("SET SESSION time_zone = '+00:00'");
            if ($snapshot) {
                $db->executeStatement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $db->executeStatement('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            }
            self::put($gz, "-- ClientFence database backup\n-- Database: " . $db->getDatabase() . "\n-- Server: " . $db->fetchOne('SELECT VERSION()') . "\n-- Made: " . gmdate('Y-m-d H:i:s') . " UTC\n\n"
                . "/*!40101 SET NAMES utf8mb4 */;\n/*!40103 SET TIME_ZONE='+00:00' */;\n/*!40014 SET FOREIGN_KEY_CHECKS=0 */;\n/*!40014 SET UNIQUE_CHECKS=0 */;\n/*!40101 SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n\n");

            $views = [];
            foreach ($db->fetchAllNumeric('SHOW FULL TABLES') as [$name, $type]) {
                if (strtoupper((string) $type) === 'VIEW') {
                    $views[] = (string) $name;

                    continue;
                }
                self::table($db, $pdo, $gz, (string) $name);
                ++$tables;
            }
            foreach ($views as $view) {
                $create = (string) ($db->fetchNumeric('SHOW CREATE VIEW ' . self::name($view))[1] ?? '');
                // The definer may not exist where the backup is restored.
                $create = (string) preg_replace('/\sDEFINER=`[^`]*`@`[^`]*`/', '', $create);
                self::put($gz, '-- View ' . $view . "\nDROP VIEW IF EXISTS " . self::name($view) . ";\n" . $create . ";\n\n");
            }
            self::put($gz, "/*!40014 SET FOREIGN_KEY_CHECKS=1 */;\n/*!40014 SET UNIQUE_CHECKS=1 */;\n-- Dump completed " . gmdate('Y-m-d H:i:s') . " UTC\n");
        } finally {
            if ($snapshot) {
                try {
                    $db->executeStatement('COMMIT');
                } catch (\Throwable) {
                    // Nothing was written in the snapshot.
                }
            }
            if ($pdo !== null && $buffered !== null) {
                $pdo->setAttribute(self::bufferedAttribute(), $buffered);
            }
            try {
                $db->executeStatement('SET SESSION time_zone = ?', [$zone]);
                if ($snapshot && $isolation !== null) {
                    $db->executeStatement('SET SESSION TRANSACTION ISOLATION LEVEL ' . $isolation);
                }
            } catch (\Throwable) {
                // The connection is gone; a new one starts with the server's zone.
            }
            gzclose($gz);
        }
        // zlib writes its last block on close, and gzclose() reports success even when that write fails (full
        // disk): the finished file is read back to its last line.
        if (!self::complete($file)) {
            throw new \RuntimeException('Writing the backup file failed (disk full?).');
        }

        return $tables;
    }

    /** @param resource $gz */
    private static function table(Connection $db, ?\PDO $pdo, $gz, string $table): void
    {
        $create = (string) ($db->fetchNumeric('SHOW CREATE TABLE ' . self::name($table))[1] ?? '');
        self::put($gz, '-- Table ' . $table . "\nDROP TABLE IF EXISTS " . self::name($table) . ";\n" . $create . ";\n");

        if (in_array(strtolower($table), self::NO_ROWS, true)) {
            self::put($gz, "\n");

            return;
        }
        $kinds = [];
        $columns = [];
        foreach ($db->fetchAllAssociative('SHOW COLUMNS FROM ' . self::name($table)) as $column) {
            if (preg_match('/\b(VIRTUAL|STORED|PERSISTENT)\b/i', (string) ($column['Extra'] ?? '')) === 1) {
                continue;
            }
            $columns[] = self::name((string) $column['Field']);
            $type = strtolower((string) preg_replace('/[\s(].*$/s', '', (string) $column['Type']));
            $kinds[] = in_array($type, self::BINARY_TYPES, true) ? 'binary' : (in_array($type, self::HEX_TYPES, true) ? 'hex' : 'text');
        }
        if ($columns === []) {
            return;
        }

        // Rows stream from the server (unbuffered), so a large table never fills memory.
        $pdo?->setAttribute(self::bufferedAttribute(), false);
        $head = 'INSERT INTO ' . self::name($table) . ' (' . implode(', ', $columns) . ') VALUES ';
        $statement = '';
        $result = $db->executeQuery('SELECT ' . implode(', ', $columns) . ' FROM ' . self::name($table));
        try {
            while (($row = $result->fetchNumeric()) !== false) {
                $values = [];
                foreach ($row as $i => $value) {
                    $kind = $kinds[$i] ?? 'text';
                    if ($value === null) {
                        $values[] = 'NULL';
                    } elseif ($kind === 'binary') {
                        $values[] = '_binary ' . self::quote((string) $value);
                    } elseif ($kind === 'hex') {
                        $values[] = $value === '' ? "''" : '0x' . bin2hex((string) $value);
                    } else {
                        $values[] = self::quote((string) $value);
                    }
                }
                $tuple = '(' . implode(',', $values) . ')';
                if ($statement !== '' && strlen($statement) + strlen($tuple) > self::STATEMENT_BYTES) {
                    self::put($gz, $statement . ";\n");
                    $statement = '';
                }
                $statement .= ($statement === '' ? $head : ',') . $tuple;
            }
        } finally {
            $result->free();
            $pdo?->setAttribute(self::bufferedAttribute(), true);
        }
        if ($statement !== '') {
            self::put($gz, $statement . ";\n");
        }
        self::put($gz, "\n");
    }

    /**
     * Loads a dump write() made back into the database (the automatic rollback of a failed update,
     * Update::performUpdate()): its tables and views replace those of the same name, and the ones
     * made since (not in the dump) are dropped, so the database is as it was when the dump was made.
     */
    public static function restore(Connection $db, string $file): void
    {
        if (!self::complete($file) || ($gz = @gzopen($file, 'rb')) === false) {
            throw new \RuntimeException('The database backup is missing or incomplete: ' . $file);
        }
        [$mode, $zone] = $db->fetchNumeric('SELECT @@session.sql_mode, @@session.time_zone');
        $dumped = [];
        $statement = '';
        try {
            // write() escapes line breaks in values, so every statement ends with ";" at the end of a line.
            while (($line = gzgets($gz)) !== false) {
                if ($statement === '' && preg_match('/\A-- (?:Table|View) (.+)\n\z/', $line, $m) === 1) {
                    $dumped[] = $m[1];
                }
                if ($statement === '' && (str_starts_with($line, '--') || trim($line) === '')) {
                    continue;
                }
                $statement .= $line;
                if (str_ends_with(rtrim($line), ';')) {
                    $db->executeStatement(substr(rtrim($statement), 0, -1));
                    $statement = '';
                }
            }
            $db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($db->fetchAllNumeric('SHOW FULL TABLES') as [$name, $type]) {
                if (!in_array((string) $name, $dumped, true)) {
                    $db->executeStatement((strtoupper((string) $type) === 'VIEW' ? 'DROP VIEW ' : 'DROP TABLE ') . self::name((string) $name));
                }
            }
        } finally {
            gzclose($gz);
            $db->executeStatement('SET FOREIGN_KEY_CHECKS = 1, UNIQUE_CHECKS = 1');
            $db->executeStatement('SET SESSION sql_mode = ?, time_zone = ?', [$mode, $zone]);
        }
    }

    /** A MySQL string literal (as mysqldump escapes: works whatever the restoring SQL mode). */
    public static function quote(string $value): string
    {
        return "'" . strtr($value, ["\\" => '\\\\', "'" => "\\'", "\0" => '\\0', "\n" => '\\n', "\r" => '\\r', "\x1a" => '\\Z']) . "'";
    }

    /** The session's isolation level as SQL ("REPEATABLE READ"), to put back after the snapshot. */
    private static function isolation(Connection $db): ?string
    {
        foreach (['@@session.transaction_isolation', '@@session.tx_isolation'] as $variable) {
            try {
                $level = strtoupper(str_replace('-', ' ', (string) $db->fetchOne('SELECT ' . $variable)));
            } catch (\Throwable) {
                continue;
            }
            if (in_array($level, ['READ UNCOMMITTED', 'READ COMMITTED', 'REPEATABLE READ', 'SERIALIZABLE'], true)) {
                return $level;
            }
        }

        return null;
    }

    /** PHP 8.4 moved the MySQL attributes to Pdo\\Mysql (the PDO::MYSQL_* names are deprecated in 8.5). */
    private static function bufferedAttribute(): int
    {
        return class_exists(\Pdo\Mysql::class) ? \Pdo\Mysql::ATTR_USE_BUFFERED_QUERY : \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY;
    }

    private static function name(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /** Whether the gzip file reads back to the end and ends with the dump's last line. */
    private static function complete(string $file): bool
    {
        $gz = @gzopen($file, 'rb');
        if ($gz === false) {
            return false;
        }
        $tail = '';
        while (!gzeof($gz)) {
            $chunk = gzread($gz, 1 << 20);
            if ($chunk === false) {
                gzclose($gz);

                return false;
            }
            $tail = substr($tail . $chunk, -200);
        }
        gzclose($gz);

        return str_contains($tail, "\n-- Dump completed ");
    }

    /** @param resource $gz */
    private static function put($gz, string $text): void
    {
        // A full disk makes gzwrite() write less (0), not return false: a cut-off dump was reported as good.
        $written = gzwrite($gz, $text);
        if ($written === false || $written < strlen($text)) {
            throw new \RuntimeException('Writing the backup file failed (disk full?).');
        }
    }
}
