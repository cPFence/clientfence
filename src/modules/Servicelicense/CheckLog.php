<?php

declare(strict_types=1);

namespace ClientFence\Mod\Servicelicense;

use Doctrine\DBAL\Connection;

/**
 * ClientFence: the licence check log, as WHMCS Licensing's: every check of a known licence key
 * (Server::process()) is one line with the host, IP, path and version it came from and the answer
 * ("Valid" or why it failed). Staff see a licence's recent checks on its order page; checks with a
 * key no licence has are not logged.
 *
 * Lines older than "Keep the licence check log" (System Settings > Licences; days, 0 keeps them)
 * are removed after each cron run (Service::onAfterAdminCronRun()). Table service_license_log; the
 * setting is the system parameter servicelicense_log_days.
 */
final class CheckLog
{
    public const TABLE = 'service_license_log';
    public const PARAM = 'servicelicense_log_days';
    public const KEEP_DAYS = 90;
    public const MAX_DAYS = 3650;

    /** One check of a licence; a log that cannot be written never stops the check. */
    public static function record(\Pimple\Container $di, int $licenseId, string $host, string $ip, string $path, string $version, string $result): void
    {
        $row = [
            'service_license_id' => $licenseId,
            'host' => self::cut($host),
            'ip' => self::cut($ip),
            'path' => self::cut($path),
            'version' => self::cut($version, 100),
            'result' => self::cut($result),
            'created_at' => date('Y-m-d H:i:s'),
        ];
        try {
            self::withTable($di, static fn () => self::db($di)->insert(self::TABLE, $row));
        } catch (\Throwable $e) {
            try {
                $di['logger']->setChannel('license')->warning('The licence check log could not be written: %s', $e->getMessage());
            } catch (\Throwable) {
            }
        }
    }

    /**
     * A licence's latest checks, newest first.
     *
     * @return list<array{host: string, ip: string, path: string, version: string, result: string, created_at: string}>
     */
    public static function recent(\Pimple\Container $di, int $licenseId, int $limit = 50): array
    {
        try {
            $rows = self::db($di)->fetchAllAssociative('SELECT host, ip, path, version, result, created_at FROM ' . self::TABLE . ' WHERE service_license_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(500, $limit)), [$licenseId]);
        } catch (\Throwable) {
            return [];
        }

        return array_map(static fn (array $row): array => [
            'host' => (string) $row['host'], 'ip' => (string) $row['ip'], 'path' => (string) $row['path'],
            'version' => (string) $row['version'], 'result' => (string) $row['result'], 'created_at' => (string) $row['created_at'],
        ], $rows);
    }

    /**
     * How many checks came since `$since`, and each IP address's last check (the service page's IP seats).
     *
     * @return array{count: int, last: array<string, string>}
     */
    public static function stats(\Pimple\Container $di, int $licenseId, string $since): array
    {
        try {
            $db = self::db($di);

            return [
                'count' => (int) $db->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE service_license_id = ? AND created_at >= ?', [$licenseId, $since]),
                'last' => $db->fetchAllKeyValue('SELECT ip, MAX(created_at) FROM ' . self::TABLE . ' WHERE service_license_id = ? GROUP BY ip', [$licenseId]),
            ];
        } catch (\Throwable) {
            return ['count' => 0, 'last' => []];
        }
    }

    /** How many days lines are kept (0: kept). */
    public static function days(\Pimple\Container $di): int
    {
        try {
            $value = self::db($di)->fetchOne('SELECT value FROM setting WHERE param = ?', [self::PARAM]);
        } catch (\Throwable) {
            $value = false;
        }
        if ($value === false || $value === null || trim((string) $value) === '') {
            return self::KEEP_DAYS;
        }

        return max(0, min(self::MAX_DAYS, (int) $value));
    }

    /** Saves "Keep the licence check log" (days; 0 keeps every line). */
    public static function saveDays(\Pimple\Container $di, mixed $days): void
    {
        if (!is_numeric($days) || (int) $days != $days || (int) $days < 0 || (int) $days > self::MAX_DAYS) {
            throw new \ClientFence\InformationException('Keep the licence check log for 0 to :max days (0 keeps every check).', [':max' => self::MAX_DAYS]);
        }
        self::storeDays(self::db($di), (int) $days);
        \ClientFence\RequestCache::forget($di, 'setting.');
    }

    /** Writes the setting (saveDays(), and the WHMCS import with WHMCS's "Log Prune" days). */
    public static function storeDays(Connection $db, int $days): void
    {
        $days = max(0, min(self::MAX_DAYS, $days));
        $now = date('Y-m-d H:i:s');
        if ($db->fetchOne('SELECT 1 FROM setting WHERE param = ?', [self::PARAM]) === false) {
            $db->insert('setting', ['param' => self::PARAM, 'value' => (string) $days, 'public' => 0, 'created_at' => $now, 'updated_at' => $now]);
        } else {
            $db->update('setting', ['value' => (string) $days, 'updated_at' => $now], ['param' => self::PARAM]);
        }
    }

    /** Removes lines older than the setting; returns how many went. */
    public static function prune(\Pimple\Container $di): int
    {
        $days = self::days($di);
        if ($days === 0) {
            return 0;
        }
        try {
            return (int) self::db($di)->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE created_at < ?', [date('Y-m-d H:i:s', time() - $days * 86400)]);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Lines of deleted licences (Service::action_delete()). */
    public static function forget(\Pimple\Container $di, int $licenseId): void
    {
        try {
            self::db($di)->delete(self::TABLE, ['service_license_id' => $licenseId]);
        } catch (\Throwable) {
        }
    }

    private static function cut(string $value, int $length = 255): string
    {
        return mb_substr(trim($value), 0, $length);
    }

    private static function withTable(\Pimple\Container $di, callable $query): void
    {
        try {
            $query();
        } catch (\Doctrine\DBAL\Exception\TableNotFoundException) {
            \ClientFence\Mod\Clientfence\Service::ensureTables($di, true);
            $query();
        }
    }

    private static function db(\Pimple\Container $di): Connection
    {
        return $di['em']->getConnection();
    }
}
