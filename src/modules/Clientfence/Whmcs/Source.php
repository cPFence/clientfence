<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Whmcs;

use ClientFence\InformationException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * ClientFence: a WHMCS database, read only (the WHMCS import, Import.php). WHMCS code never runs:
 * the importer reads WHMCS's tables directly, so a retired WHMCS (or one without ionCube on this
 * server) imports as well as a live one, and nothing can change on WHMCS's side.
 *
 * Every read happens inside a read-only transaction (MySQL refuses any write in it), rolled back
 * when the source is closed. The database password never reaches an error message or a stack
 * trace: it is decrypted inside open() and never passed as an argument.
 */
final class Source
{
    /** WHMCS versions the importer is built for; older ones import on a best effort basis. */
    public const SUPPORTED_MAJOR = 8;
    public const OLDEST_MAJOR = 7;

    private ?string $version = null;
    /** @var array<string, true>|null */
    private ?array $tables = null;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Connects with the saved settings (Import::settings(): host, port, database, username and the
     * encrypted password) and starts a read-only transaction.
     *
     * @param array<string, mixed> $settings
     */
    public static function open(\Pimple\Container $di, array $settings): self
    {
        // The Upmind import: what was read from Upmind, laid out as WHMCS's tables (Upmind/Snapshot.php).
        if ((int) ($settings['upmind'] ?? 0) === 1) {
            if (empty(\ClientFence\Mod\Clientfence\Upmind\Snapshot::state($di)['finished_at'])) {
                throw new InformationException('Read Upmind first, until it finishes.');
            }

            return new self(\ClientFence\Mod\Clientfence\Upmind\Snapshot::open());
        }
        $host = trim((string) ($settings['host'] ?? ''));
        $name = trim((string) ($settings['database'] ?? ''));
        $user = trim((string) ($settings['username'] ?? ''));
        if ($host === '' || $name === '' || $user === '') {
            throw new InformationException('Enter the WHMCS database host, name and username first.');
        }
        $port = (int) ($settings['port'] ?? 3306) ?: 3306;
        // "localhost" means the MySQL socket, whose path PHP may not know: TCP to 127.0.0.1 works
        // wherever the socket does.
        $hosts = $host === 'localhost' ? ['localhost', '127.0.0.1'] : [$host];
        $error = '';
        foreach ($hosts as $try) {
            try {
                $db = DriverManager::getConnection([
                    'driver' => 'pdo_mysql',
                    'host' => $try,
                    'port' => $port,
                    'dbname' => $name,
                    'user' => $user,
                    'password' => self::password($di, $settings),
                    'charset' => 'utf8mb4',
                    'driverOptions' => [\PDO::ATTR_TIMEOUT => 10],
                ]);
                $db->executeStatement('START TRANSACTION READ ONLY');

                return new self($db);
            } catch (\Throwable $e) {
                // The message only: the exception's trace holds the connection parameters.
                $error = self::cleanMessage($e->getMessage());
            }
        }

        throw new InformationException('Could not connect to the WHMCS database: :error', [':error' => $error]);
    }

    /** The connection (tests hand in their own). */
    public function connection(): Connection
    {
        return $this->db;
    }

    /** Ends the read-only transaction. */
    public function close(): void
    {
        try {
            if ($this->isMysql()) {
                $this->db->executeStatement('ROLLBACK');
            }
            $this->db->close();
        } catch (\Throwable) {
            // Nothing was written: there is nothing to lose.
        }
    }

    /** WHMCS's version, such as "9.0.4-release.1" ('' when unknown). */
    public function version(): string
    {
        if ($this->version === null) {
            $this->version = '';
            if ($this->hasTable('tblconfiguration')) {
                $value = $this->db->fetchOne("SELECT value FROM tblconfiguration WHERE setting = 'Version'");
                $this->version = is_string($value) ? trim($value) : '';
            }
        }

        return $this->version;
    }

    /** The major version (9 for "9.0.4"), or 0. */
    public function major(): int
    {
        return (int) explode('.', $this->version())[0];
    }

    /** Whether this looks like a WHMCS database at all. */
    public function isWhmcs(): bool
    {
        return $this->hasTable('tblconfiguration') && $this->hasTable('tblclients') && $this->hasTable('tblinvoices');
    }

    public function hasTable(string $table): bool
    {
        return isset($this->tables()[$table]);
    }

    /** @return array<string, true> every table name */
    public function tables(): array
    {
        if ($this->tables === null) {
            $names = $this->isMysql()
                ? $this->db->fetchFirstColumn('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')
                : $this->db->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'table'");
            $this->tables = array_fill_keys(array_map('strval', $names), true);
        }

        return $this->tables;
    }

    /** @return list<string> the columns of a table ([] when it does not exist) */
    public function columns(string $table): array
    {
        if (!$this->hasTable($table)) {
            return [];
        }

        $names = $this->isMysql()
            ? $this->db->fetchFirstColumn('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table])
            : array_column($this->db->fetchAllAssociative('PRAGMA table_info(' . $this->quote($table) . ')'), 'name');

        return array_map('strval', $names);
    }

    public function count(string $table, string $where = '', array $params = []): int
    {
        if (!$this->hasTable($table)) {
            return 0;
        }

        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . $this->quote($table) . ($where !== '' ? ' WHERE ' . $where : ''), $params);
    }

    /**
     * Row counts by the values of one column ("Active" => 12), most first.
     *
     * @return array<string, int>
     */
    public function countBy(string $table, string $column): array
    {
        if (!$this->hasTable($table) || !in_array($column, $this->columns($table), true)) {
            return [];
        }
        $out = [];
        $q = $this->quote($column);
        foreach ($this->db->fetchAllAssociative('SELECT ' . $q . ' AS v, COUNT(*) AS n FROM ' . $this->quote($table) . ' GROUP BY ' . $q . ' ORDER BY n DESC, v') as $row) {
            $out[(string) ($row['v'] ?? '') === '' ? '(none)' : (string) $row['v']] = (int) $row['n'];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function rows(string $sql, array $params = []): array
    {
        return $this->db->fetchAllAssociative($sql, $params);
    }

    public function value(string $sql, array $params = []): mixed
    {
        return $this->db->fetchOne($sql, $params);
    }

    private function quote(string $identifier): string
    {
        return $this->db->quoteSingleIdentifier($identifier);
    }

    private function isMysql(): bool
    {
        return $this->db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
    }

    /** The saved password, decrypted here so it is never an argument of anything that may throw. */
    private static function password(\Pimple\Container $di, array $settings): string
    {
        $stored = (string) ($settings['password'] ?? '');

        return $stored === '' ? '' : (string) $di['crypt']->decrypt($stored);
    }

    /** A driver error without anything that is not the reason (no DSN, no SQL). */
    private static function cleanMessage(string $message): string
    {
        $message = preg_replace('/^An exception occurred in the driver: /', '', $message) ?? $message;
        $message = preg_replace('/SQLSTATE\[[^\]]*\]\s*(\[\d+\])?\s*/', '', $message) ?? $message;

        return mb_substr(trim($message), 0, 300);
    }
}
