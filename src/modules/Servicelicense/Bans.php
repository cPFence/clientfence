<?php

declare(strict_types=1);

namespace ClientFence\Mod\Servicelicense;

use ClientFence\InformationException;
use Doctrine\DBAL\Connection;

/**
 * ClientFence: licence bans, as WHMCS Licensing's "Ban Control": a domain or IP address that never
 * passes a licence check, whatever licence key it sends (Server::process() refuses it with error
 * 1011 before anything is bound to the licence). A domain matches its host with or without "www.";
 * an IP address matches the address the check came from.
 *
 * Managed in System Settings > Licences; table service_license_ban.
 */
final class Bans
{
    public const TABLE = 'service_license_ban';

    /**
     * Every ban, in the order they were added.
     *
     * @return list<array{id: int, value: string, kind: string, notes: string, created_at: string}>
     */
    public static function all(\Pimple\Container $di): array
    {
        try {
            $rows = self::db($di)->fetchAllAssociative('SELECT id, value, notes, created_at FROM ' . self::TABLE . ' ORDER BY id');
        } catch (\Throwable) {
            return [];
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'value' => (string) $row['value'], 'kind' => self::kind((string) $row['value']),
            'notes' => (string) $row['notes'], 'created_at' => (string) $row['created_at'],
        ], $rows);
    }

    /** Adds a ban (a domain or an IP address); returns its id. */
    public static function add(\Pimple\Container $di, string $value, string $notes = ''): int
    {
        $value = self::normalize($value);
        if ($value === '' || mb_strlen($value) > 255 || preg_match('/\s/', $value) === 1) {
            throw new InformationException('Enter one domain or IP address to ban.');
        }
        if (self::kind($value) === 'domain' && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $value) !== 1) {
            throw new InformationException('":value" is not a domain or an IP address.', [':value' => $value]);
        }
        $db = self::db($di);
        $id = 0;
        self::withTable($di, static function () use ($db, $value, $notes, &$id): void {
            $found = $db->fetchOne('SELECT id FROM ' . self::TABLE . ' WHERE value = ?', [$value]);
            if ($found !== false) {
                throw new InformationException('":value" is already banned.', [':value' => $value]);
            }
            $db->insert(self::TABLE, ['value' => $value, 'notes' => mb_substr(trim($notes), 0, 1000), 'created_at' => date('Y-m-d H:i:s')]);
            $id = (int) $db->lastInsertId();
        });

        return $id;
    }

    public static function delete(\Pimple\Container $di, int $id): void
    {
        try {
            self::db($di)->delete(self::TABLE, ['id' => $id]);
        } catch (\Throwable) {
        }
    }

    /** The ban a check from $host at $ip falls under, or null. */
    public static function match(\Pimple\Container $di, string $host, string $ip): ?string
    {
        $values = array_column(self::all($di), 'value');
        if ($values === []) {
            return null;
        }
        $host = self::normalize($host);
        $ip = strtolower(trim($ip));
        foreach ($values as $value) {
            if (self::kind($value) === 'ip' ? $value === $ip : $host !== '' && $value === $host) {
                return $value;
            }
        }

        return null;
    }

    /** "ip" or "domain". */
    public static function kind(string $value): string
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false ? 'ip' : 'domain';
    }

    /** Lower case, without a scheme, path, port (domains) or leading "www.". */
    public static function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return $value;
        }
        $value = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $value) ?? $value;
        $value = explode('/', $value, 2)[0];
        $value = preg_replace('/:\d+$/', '', $value) ?? $value;
        $value = rtrim($value, '.');

        return str_starts_with($value, 'www.') ? substr($value, 4) : $value;
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
