<?php

declare(strict_types=1);

namespace ClientFence\Mod\Support;

use ClientFence\InformationException;
use ClientFence\Tools;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * ClientFence: network issues, as WHMCS (Support > Network Issues; the client area's Network Status
 * page). Staff post planned work and outages: a title and description, the type (a server, the
 * whole system or something else they name), the priority, the status and when it starts and ends.
 *
 * An issue of a server affects the clients with an active or suspended hosting service on it; a
 * system or other issue affects every client with an active or suspended service. Signed-in
 * clients see a banner for the open and the scheduled issues that affect them, and staff may email
 * them when they save an issue (mod_support_network_issue).
 *
 * Support settings > Network Status switches the feature on (off by default) and may open the
 * page to visitors who are not signed in (as WHMCS, it needs signing in by default).
 */
final class NetworkIssues
{
    public const TABLE = 'support_network_issue';

    public const TYPES = ['server', 'system', 'other'];
    public const PRIORITIES = ['critical', 'high', 'medium', 'low'];
    public const STATUSES = ['reported', 'investigating', 'in_progress', 'outage', 'scheduled', 'resolved'];
    /** Not resolved and not only planned. */
    public const OPEN = ['reported', 'investigating', 'in_progress', 'outage'];
    /** The client page's tabs. */
    public const GROUPS = ['open', 'scheduled', 'resolved'];

    /** The Support settings this feature saves with its own form (kept by Extension\Service::setConfig()). */
    public const SETTINGS = ['network_status', 'network_status_public'];

    public const MAX_TITLE = 255;
    public const MAX_AFFECTING = 100;
    public const MAX_DESCRIPTION = 20000;

    /** Whether the feature is on (Support settings > Network Status). */
    public static function enabled(\Pimple\Container $di): bool
    {
        return self::setting($di, 'network_status');
    }

    /** Whether visitors who are not signed in may read the Network Status page. */
    public static function isPublic(\Pimple\Container $di): bool
    {
        return self::enabled($di) && self::setting($di, 'network_status_public');
    }

    /** @return array<string, string> */
    public static function types(): array
    {
        return ['server' => __trans('Server'), 'system' => __trans('System'), 'other' => __trans('Other')];
    }

    /** @return array<string, string> */
    public static function priorities(): array
    {
        return ['critical' => __trans('Critical'), 'high' => __trans('High'), 'medium' => __trans('Medium'), 'low' => __trans('Low')];
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            'reported' => __trans('Reported'),
            'investigating' => __trans('Investigating'),
            'in_progress' => __trans('In Progress'),
            'outage' => __trans('Outage'),
            'scheduled' => __trans('Scheduled'),
            'resolved' => __trans('Resolved'),
        ];
    }

    /**
     * Servers an issue can be about: id => name.
     *
     * @return array<int, string>
     */
    public static function servers(\Pimple\Container $di): array
    {
        $pairs = [];
        foreach (self::db($di)->fetchAllAssociative('SELECT id, name FROM service_hosting_server ORDER BY name, id') as $row) {
            $pairs[(int) $row['id']] = (string) $row['name'];
        }

        return $pairs;
    }

    /** @return array<string, mixed> */
    public static function get(\Pimple\Container $di, int $id): array
    {
        $row = self::db($di)->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE id = ?', [$id]);
        if ($row === false) {
            throw new InformationException('Network issue not found', [], 404);
        }

        return self::toArray($di, $row);
    }

    /**
     * Issues of a tab (`open`, `scheduled`, `resolved`; anything else: all), newest start first.
     *
     * @return array{list: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public static function getList(\Pimple\Container $di, string $group, int $page = 1, int $perPage = 25): array
    {
        $db = self::db($di);
        [$where, $params, $types] = self::groupWhere($group);
        $total = (int) $db->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE ' . $where, $params, $types);
        $perPage = max(1, min(100, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        // Scheduled work soonest first; the rest newest first.
        $order = $group === 'scheduled' ? 'start_at ASC, id ASC' : 'start_at DESC, id DESC';
        $rows = $db->fetchAllAssociative(
            'SELECT * FROM ' . self::TABLE . ' WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $params,
            $types
        );

        return [
            'list' => array_map(static fn (array $row): array => self::toArray($di, $row), $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    /** The number of issues in each tab. @return array<string, int> */
    public static function counts(\Pimple\Container $di): array
    {
        $counts = [];
        foreach (self::GROUPS as $group) {
            [$where, $params, $types] = self::groupWhere($group);
            $counts[$group] = (int) self::db($di)->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE ' . $where, $params, $types);
        }

        return $counts;
    }

    /**
     * Creates (no `id`) or updates an issue. `email` (on): email the affected clients after saving.
     * Returns the id and how many clients were emailed.
     *
     * @return array{id: int, emailed: int}
     */
    public static function save(\Pimple\Container $di, array $data, ?int $adminId = null): array
    {
        $db = self::db($di);
        $id = (int) ($data['id'] ?? 0);
        if ($id > 0) {
            self::get($di, $id);
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > self::MAX_TITLE) {
            throw new InformationException('Enter a title of at most :max characters.', [':max' => self::MAX_TITLE]);
        }
        $description = trim(str_replace(["\r\n", "\r"], "\n", (string) ($data['description'] ?? '')));
        if (mb_strlen($description) > self::MAX_DESCRIPTION) {
            throw new InformationException('The description can be at most :max characters.', [':max' => self::MAX_DESCRIPTION]);
        }
        $type = (string) ($data['type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            throw new InformationException('Choose what the issue is about: a server, the system or something else.');
        }
        $serverId = null;
        $affecting = '';
        if ($type === 'server') {
            $serverId = (int) ($data['server_id'] ?? 0);
            if (!array_key_exists($serverId, self::servers($di))) {
                throw new InformationException('Choose the server.');
            }
        } elseif ($type === 'other') {
            $affecting = trim((string) ($data['affecting'] ?? ''));
            if ($affecting === '' || mb_strlen($affecting) > self::MAX_AFFECTING) {
                throw new InformationException('Enter what the issue affects, in at most :max characters.', [':max' => self::MAX_AFFECTING]);
            }
        }
        $priority = (string) ($data['priority'] ?? '');
        if (!in_array($priority, self::PRIORITIES, true)) {
            throw new InformationException('Choose the priority.');
        }
        $status = (string) ($data['status'] ?? '');
        if (!in_array($status, self::STATUSES, true)) {
            throw new InformationException('Choose the status.');
        }
        // The form shows times in the staff member's time zone and says which; stored in server time.
        $zone = self::zone($data['timezone'] ?? null);
        $start = self::dateTime($data['start_at'] ?? '', $zone);
        if ($start === null) {
            throw new InformationException('Enter when the issue starts.');
        }
        $end = null;
        if (trim((string) ($data['end_at'] ?? '')) !== '') {
            $end = self::dateTime($data['end_at'], $zone);
            if ($end === null || $end < $start) {
                throw new InformationException('The end must be a date and time after the start.');
            }
        }

        $now = date('Y-m-d H:i:s');
        $row = [
            'title' => $title,
            'description' => $description,
            'type' => $type,
            'affecting' => $affecting,
            'server_id' => $serverId,
            'priority' => $priority,
            'status' => $status,
            'start_at' => $start,
            'end_at' => $end,
            'updated_at' => $now,
        ];
        if ($id > 0) {
            $db->update(self::TABLE, $row, ['id' => $id]);
        } else {
            $db->insert(self::TABLE, $row + ['created_by' => $adminId, 'created_at' => $now]);
            $id = (int) $db->lastInsertId();
        }
        $emailed = Tools::normalizeBoolean($data['email'] ?? false, false) ? self::emailAffected($di, $id) : 0;
        // The affected clients' bell, once per issue, while the Network Status page is on.
        if ($status !== 'resolved' && self::enabled($di)) {
            foreach (self::affectedClients($di, ['type' => $type, 'server_id' => $serverId]) as $clientId) {
                \ClientFence\Mod\Clientfence\Notifications::client($di, $clientId, 'network_issue', ['title' => $title], 'network-status', 'network:' . $id);
            }
        }

        return ['id' => $id, 'emailed' => $emailed];
    }

    public static function delete(\Pimple\Container $di, int $id): void
    {
        self::get($di, $id);
        self::db($di)->delete(self::TABLE, ['id' => $id]);
    }

    /**
     * The clients an issue affects: of a server, the clients with an active or suspended hosting
     * service on it; otherwise every client with an active or suspended service.
     *
     * @return list<int>
     */
    public static function affectedClients(\Pimple\Container $di, array $issue): array
    {
        $db = self::db($di);
        if ($issue['type'] === 'server') {
            $ids = $db->fetchFirstColumn(
                "SELECT DISTINCT o.client_id FROM client_order o JOIN service_hosting h ON h.id = o.service_id WHERE o.service_type = 'hosting' AND o.status IN ('active', 'suspended') AND h.service_hosting_server_id = ? ORDER BY o.client_id",
                [(int) $issue['server_id']]
            );
        } else {
            $ids = $db->fetchFirstColumn("SELECT DISTINCT client_id FROM client_order WHERE status IN ('active', 'suspended') ORDER BY client_id");
        }

        return array_map('intval', $ids);
    }

    /**
     * Emails the affected clients about an issue (queued, as other client emails); returns how
     * many. The issue keeps when and to how many it was last emailed.
     */
    public static function emailAffected(\Pimple\Container $di, int $id): int
    {
        $issue = self::get($di, $id);
        $email = $di['mod_service']('email');
        $sent = 0;
        foreach (self::affectedClients($di, $issue) as $clientId) {
            try {
                $email->sendTemplate([
                    'code' => 'mod_support_network_issue',
                    'to_client' => $clientId,
                    'issue' => self::forClients($issue),
                    'status_url' => (string) $di['url']->link('network-status'),
                ]);
                ++$sent;
            } catch (\Throwable $e) {
                self::log($di, sprintf('network issue #%d: the email to client #%d failed: %s', $id, $clientId, $e->getMessage()));
            }
        }
        self::db($di)->update(self::TABLE, ['emailed_at' => date('Y-m-d H:i:s'), 'emailed_count' => $sent], ['id' => $id]);

        return $sent;
    }

    /**
     * What the client area shows of a tab, newest first (scheduled: soonest first).
     *
     * @return array{list: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public static function forPage(\Pimple\Container $di, string $group, int $page = 1): array
    {
        $result = self::getList($di, in_array($group, self::GROUPS, true) ? $group : 'open', $page, 10);
        $result['list'] = array_map(self::forClients(...), $result['list']);

        return $result;
    }

    /**
     * The open issues and the scheduled work not yet over that affect a client, for its banner.
     *
     * @return list<array<string, mixed>>
     */
    public static function alertsFor(\Pimple\Container $di, int $clientId): array
    {
        $db = self::db($di);
        $servers = $db->fetchFirstColumn(
            "SELECT DISTINCT h.service_hosting_server_id FROM client_order o JOIN service_hosting h ON h.id = o.service_id WHERE o.client_id = ? AND o.service_type = 'hosting' AND o.status IN ('active', 'suspended') AND h.service_hosting_server_id IS NOT NULL",
            [$clientId]
        );
        $hasServices = (int) $db->fetchOne("SELECT COUNT(*) FROM client_order WHERE client_id = ? AND status IN ('active', 'suspended')", [$clientId]) > 0;
        if (!$hasServices) {
            return [];
        }
        $rows = $db->fetchAllAssociative(
            'SELECT * FROM ' . self::TABLE . " WHERE (status IN (?) OR (status = 'scheduled' AND (end_at IS NULL OR end_at > ?)))"
            . " AND (type <> 'server' OR server_id IN (?)) ORDER BY start_at ASC, id ASC LIMIT 10",
            [self::OPEN, date('Y-m-d H:i:s'), $servers === [] ? [0] : array_map('intval', $servers)],
            [ArrayParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, ArrayParameterType::INTEGER]
        );

        return array_map(static fn (array $row): array => self::forClients(self::toArray($di, $row)), $rows);
    }

    /** What clients see of an issue: no staff fields. */
    public static function forClients(array $issue): array
    {
        unset($issue['created_by'], $issue['emailed_at'], $issue['emailed_count']);

        return $issue;
    }

    /** @return array{0: string, 1: list<mixed>, 2: list<mixed>} */
    private static function groupWhere(string $group): array
    {
        return match ($group) {
            'open' => ['status IN (?)', [self::OPEN], [ArrayParameterType::STRING]],
            'scheduled' => ["status = 'scheduled'", [], []],
            'resolved' => ["status = 'resolved'", [], []],
            default => ['1 = 1', [], []],
        };
    }

    /** @return array<string, mixed> */
    private static function toArray(\Pimple\Container $di, array $row): array
    {
        $serverId = $row['server_id'] !== null ? (int) $row['server_id'] : null;
        $serverName = '';
        if ($serverId !== null) {
            $serverName = (string) (self::db($di)->fetchOne('SELECT name FROM service_hosting_server WHERE id = ?', [$serverId]) ?: '');
        }
        $type = (string) $row['type'];

        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'description' => (string) $row['description'],
            'type' => $type,
            'type_title' => self::types()[$type] ?? $type,
            'affecting' => (string) $row['affecting'],
            'server_id' => $serverId,
            'server_name' => $serverName,
            // What it is about, as the client page and the list show it.
            'about' => match ($type) {
                'server' => $serverName !== '' ? $serverName : __trans('Server'),
                'system' => __trans('All services'),
                default => (string) $row['affecting'],
            },
            'priority' => (string) $row['priority'],
            'priority_title' => self::priorities()[$row['priority']] ?? (string) $row['priority'],
            'status' => (string) $row['status'],
            'status_title' => self::statuses()[$row['status']] ?? (string) $row['status'],
            'open' => in_array($row['status'], self::OPEN, true),
            'start_at' => (string) $row['start_at'],
            'end_at' => $row['end_at'] !== null ? (string) $row['end_at'] : null,
            'emailed_at' => $row['emailed_at'] !== null ? (string) $row['emailed_at'] : null,
            'emailed_count' => (int) $row['emailed_count'],
            'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * "2026-10-17T14:30" (a datetime-local field) or "2026-10-17 14:30[:00]" in `$zone`, as server
     * time (Y-m-d H:i:s).
     */
    private static function dateTime(mixed $value, \DateTimeZone $zone): ?string
    {
        $value = str_replace('T', ' ', trim((string) $value));
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value, $zone);
            if ($date !== false && $date->format($format) === $value) {
                return $date->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
            }
        }

        return null;
    }

    /** A time zone the form names (a PHP identifier), else the server's. */
    private static function zone(mixed $name): \DateTimeZone
    {
        $name = is_string($name) ? trim($name) : '';
        if ($name !== '' && in_array($name, \DateTimeZone::listIdentifiers(), true)) {
            return new \DateTimeZone($name);
        }

        return new \DateTimeZone(date_default_timezone_get());
    }

    private static function setting(\Pimple\Container $di, string $key): bool
    {
        try {
            $config = $di['mod_service']('extension')->getConfig('mod_support');
        } catch (\Throwable) {
            return false;
        }

        return Tools::normalizeBoolean($config[$key] ?? false, false);
    }

    /**
     * The table, made on first use (Clientfence\Service::ensureTables()), once a request. The API
     * calls it before using the issues.
     */
    public static function ensure(\Pimple\Container $di): void
    {
        /** @var \WeakMap<\Pimple\Container, true>|null $ready */
        static $ready = null;
        $ready ??= new \WeakMap();
        if (!isset($ready[$di])) {
            \ClientFence\Mod\Clientfence\Service::ensureTables($di, true);
            $ready[$di] = true;
        }
    }

    private static function db(\Pimple\Container $di): Connection
    {
        return $di['em']->getConnection();
    }

    private static function log(\Pimple\Container $di, string $message): void
    {
        try {
            $di['logger']->warning('ClientFence: ' . $message);
        } catch (\Throwable) {
        }
    }
}
