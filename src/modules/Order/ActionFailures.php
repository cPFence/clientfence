<?php

declare(strict_types=1);

namespace ClientFence\Mod\Order;

use ClientFence\Mod\Order\Entity\Order;
use ClientFence\Mod\Servicehosting\Panel\StatusCheck;
use Doctrine\DBAL\Connection;
use ClientFence\InformationException;

/**
 * ClientFence: failed order actions (like WHMCS's Module Queue).
 *
 * Every create, activate, renew, suspend, unsuspend, cancel, uncancel and delete an order sends
 * to its service (hosting panel, registrar, ...) runs through Order\Service::_callOnService();
 * a core patch there reports each failure here and each success after it. A failure opens an
 * entry in client_order_action_failure (one open entry per order and action: a repeated failure
 * counts another attempt and keeps the latest error), tells staff once by email
 * (mod_staff_order_action_failed) and shows on the dashboard alerts, Orders > Failed actions and
 * the order's page. A later success of the same action closes the entry, and so does a success
 * that makes it stale (fixedBy(): a renewal closes a failed suspension); entries of removed
 * orders close on their own. Staff can retry an entry (the same order action again, only while
 * the order's status still fits: retryStatuses()) or dismiss it. Nothing is retried on its own.
 *
 * Recording never breaks the order action it watches: errors here are logged and ignored.
 */
final class ActionFailures
{
    public const TABLE = 'client_order_action_failure';

    /** Order actions that reach a service (Order::ACTION_*). */
    public const ACTIONS = [
        Order::ACTION_CREATE, Order::ACTION_ACTIVATE, Order::ACTION_RENEW, Order::ACTION_SUSPEND,
        Order::ACTION_UNSUSPEND, Order::ACTION_CANCEL, Order::ACTION_UNCANCEL, Order::ACTION_DELETE,
        // A paid upgrade or downgrade that could not be applied (Upgrades.php).
        Upgrades::ACTION,
        StatusCheck::ACTION,
    ];

    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_DISMISSED = 'dismissed';

    /** An entry with its order and client (both null when the order is gone). */
    private const SELECT = 'SELECT f.*, o.title AS order_title, o.status AS order_status, o.client_id,'
        . ' c.first_name AS client_first_name, c.last_name AS client_last_name'
        . ' FROM ' . self::TABLE . ' f LEFT JOIN client_order o ON o.id = f.client_order_id'
        . ' LEFT JOIN client c ON c.id = o.client_id';

    /** Longest error text kept. */
    public const MAX_MESSAGE = 2000;

    /** A failed service call: record it (and tell staff when it opens a new entry). */
    public static function failed(\Pimple\Container $di, int $orderId, string $action, \Throwable $error): void
    {
        try {
            $id = self::record(self::db($di), $orderId, $action, $error->getMessage(), date('Y-m-d H:i:s'));
            if ($id !== null) {
                self::notify($di, $id);
                $title = (string) self::db($di)->fetchOne('SELECT title FROM client_order WHERE id = ?', [$orderId]);
                \ClientFence\Mod\Clientfence\Notifications::staff($di, 'action_failed', ['id' => $orderId, 'title' => $title], 'order/failed-actions', 'order', null, 'action_failed:' . $id);
            }
        } catch (\Throwable $e) {
            self::log($di, 'could not record a failed order action: ' . $e->getMessage());
        }
    }

    /** A successful service call: close what it fixed. */
    public static function succeeded(\Pimple\Container $di, int $orderId, string $action): void
    {
        try {
            self::closeAfterSuccess(self::db($di), $orderId, $action, date('Y-m-d H:i:s'), null);
        } catch (\Throwable $e) {
            self::log($di, 'could not close a failed order action: ' . $e->getMessage());
        }
    }

    /**
     * Records a failure. Returns the id of a new open entry, or null when an open entry for the
     * order and action was updated instead (another attempt).
     */
    public static function record(Connection $db, int $orderId, string $action, string $message, string $now): ?int
    {
        if (!in_array($action, self::ACTIONS, true)) {
            return null;
        }
        $message = self::clip($message);
        $open = $db->fetchOne(
            'SELECT id FROM ' . self::TABLE . ' WHERE client_order_id = ? AND action = ? AND status = ? ORDER BY id DESC',
            [$orderId, $action, self::STATUS_OPEN],
        );
        if ($open !== false) {
            $db->executeStatement(
                'UPDATE ' . self::TABLE . ' SET attempts = attempts + 1, message = ?, last_failed_at = ? WHERE id = ?',
                [$message, $now, (int) $open],
            );

            return null;
        }
        $db->insert(self::TABLE, [
            'client_order_id' => $orderId,
            'action' => $action,
            'message' => $message,
            'attempts' => 1,
            'status' => self::STATUS_OPEN,
            'first_failed_at' => $now,
            'last_failed_at' => $now,
        ]);

        return (int) $db->lastInsertId();
    }

    /**
     * Closes the open entries of an order for these actions; returns how many.
     *
     * @param string[] $actions
     */
    public static function resolve(Connection $db, int $orderId, array $actions, string $now, ?int $adminId = null, string $status = self::STATUS_RESOLVED): int
    {
        if ($actions === []) {
            return 0;
        }
        $marks = implode(', ', array_fill(0, count($actions), '?'));

        return (int) $db->executeStatement(
            'UPDATE ' . self::TABLE . " SET status = ?, resolved_at = ?, resolved_by = ? WHERE client_order_id = ? AND status = ? AND action IN ({$marks})",
            [$status, $now, $adminId, $orderId, self::STATUS_OPEN, ...$actions],
        );
    }

    /**
     * Actions a success of $action fixes or makes stale, so a retry can never run an old action
     * against an order that has moved on (a paid renewal must not let staff retry the suspension
     * that failed before it). Activating is the second half of setting an order up, so it also
     * fixes a failed create; a delete closes everything of the order.
     *
     * @return string[]
     */
    public static function fixedBy(string $action): array
    {
        return match ($action) {
            Order::ACTION_ACTIVATE => [Order::ACTION_CREATE, Order::ACTION_ACTIVATE],
            Order::ACTION_RENEW => [Order::ACTION_RENEW, Order::ACTION_SUSPEND, Order::ACTION_CANCEL],
            Order::ACTION_UNSUSPEND => [Order::ACTION_UNSUSPEND, Order::ACTION_SUSPEND, Order::ACTION_CANCEL],
            Order::ACTION_UNCANCEL => [Order::ACTION_UNCANCEL, Order::ACTION_CANCEL, Order::ACTION_SUSPEND],
            Order::ACTION_SUSPEND => [Order::ACTION_SUSPEND, Order::ACTION_UNSUSPEND, Order::ACTION_UNCANCEL],
            Order::ACTION_CANCEL => [Order::ACTION_CANCEL, Order::ACTION_SUSPEND, Order::ACTION_UNSUSPEND, Order::ACTION_UNCANCEL],
            Order::ACTION_DELETE => self::ACTIONS,
            default => [$action],
        };
    }

    /**
     * After a success of $action: the entries it fixes are resolved (by $adminId when staff
     * retried); the ones it only made stale are dismissed without a name, which the list shows
     * as closed by a later action.
     */
    public static function closeAfterSuccess(Connection $db, int $orderId, string $action, string $now, ?int $adminId): void
    {
        $fixed = $action === Order::ACTION_ACTIVATE ? [Order::ACTION_CREATE, Order::ACTION_ACTIVATE] : [$action];
        self::resolve($db, $orderId, $fixed, $now, $adminId);
        self::resolve($db, $orderId, array_values(array_diff(self::fixedBy($action), $fixed)), $now, null, self::STATUS_DISMISSED);
    }

    /**
     * Order statuses in which a retry of $action still makes sense (the checks the admin API
     * applies to the same actions); null: any status.
     *
     * @return string[]|null
     */
    public static function retryStatuses(string $action): ?array
    {
        return match ($action) {
            Order::ACTION_CREATE, Order::ACTION_ACTIVATE => [Order::STATUS_PENDING_SETUP, Order::STATUS_FAILED_SETUP],
            Order::ACTION_RENEW => [Order::STATUS_ACTIVE, Order::STATUS_SUSPENDED, Order::STATUS_FAILED_RENEW],
            Order::ACTION_SUSPEND => [Order::STATUS_ACTIVE],
            Order::ACTION_UNSUSPEND => [Order::STATUS_SUSPENDED],
            Order::ACTION_CANCEL => [Order::STATUS_ACTIVE, Order::STATUS_SUSPENDED],
            Order::ACTION_UNCANCEL => [Order::STATUS_CANCELED],
            Upgrades::ACTION => [Order::STATUS_ACTIVE],
            StatusCheck::ACTION => [Order::STATUS_ACTIVE, Order::STATUS_SUSPENDED],
            default => null,
        };
    }

    /**
     * Closes (as dismissed) the open entries of orders that no longer exist: removed with their
     * client, or force deleted after the service refused. Returns how many.
     */
    public static function closeRemoved(Connection $db, string $now): int
    {
        return (int) $db->executeStatement(
            'UPDATE ' . self::TABLE . ' SET status = ?, resolved_at = ? WHERE status = ?'
            . ' AND NOT EXISTS (SELECT 1 FROM client_order o WHERE o.id = ' . self::TABLE . '.client_order_id)',
            [self::STATUS_DISMISSED, $now, self::STATUS_OPEN],
        );
    }

    public static function openCount(\Pimple\Container $di): int
    {
        try {
            $db = self::db($di);
            self::closeRemoved($db, date('Y-m-d H:i:s'));

            return (int) $db->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE status = ?', [self::STATUS_OPEN]);
        } catch (\Throwable) {
            // Table not made yet: nothing to show rather than a broken dashboard.
            return 0;
        }
    }

    /**
     * Entries with their order and client (null when the order is gone), newest first.
     *
     * @return array{list: array<int, array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public static function getList(Connection $db, string $status, int $page, int $perPage, ?int $orderId = null): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        try {
            self::closeRemoved($db, date('Y-m-d H:i:s'));
        } catch (\Doctrine\DBAL\Exception\TableNotFoundException) {
            // Table not made yet (the pack's tables are made on the next health run): nothing failed.
            return ['list' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'pages' => 1];
        }
        $where = [];
        $params = [];
        if ($status !== '') {
            $where[] = 'f.status = ?';
            $params[] = $status;
        }
        if ($orderId !== null) {
            $where[] = 'f.client_order_id = ?';
            $params[] = $orderId;
        }
        $sqlWhere = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $total = (int) $db->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . " f{$sqlWhere}", $params);
        $rows = $db->fetchAllAssociative(
            self::SELECT . $sqlWhere
            . ' ORDER BY f.last_failed_at DESC, f.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $params,
        );

        return [
            'list' => array_map(self::toArray(...), $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => (int) max(1, ceil($total / $perPage)),
        ];
    }

    /**
     * Runs the entry's order action again (as staff would from the order page). The service call
     * reports its own outcome; a retry that succeeds without reaching the service (the order is
     * already in the wanted state) closes the entry too.
     */
    public static function retry(\Pimple\Container $di, int $id, ?int $adminId): bool
    {
        $db = self::db($di);
        $entry = self::openEntry($db, $id);
        $orderId = (int) $entry['client_order_id'];
        $order = $di['db']->load('ClientOrder', $orderId);
        if (!$order instanceof \Model_ClientOrder) {
            throw new InformationException('The order of this failed action no longer exists. Dismiss it instead.');
        }
        $statuses = self::retryStatuses((string) $entry['action']);
        if ($statuses !== null && !in_array($order->status, $statuses, true)) {
            throw new InformationException('The order is :status now, so this action no longer fits. Dismiss it, or act from the order page.', [':status' => str_replace('_', ' ', (string) $order->status)]);
        }
        $service = $di['mod_service']('order');
        // ClientFence core patch: a waiting paid renewal line is what is retried (Order\Service::applyPaidRenewal).
        if (!in_array($entry['action'], [Order::ACTION_RENEW, Order::ACTION_UNSUSPEND], true) || !$service->applyPaidRenewal($orderId)) {
            match ($entry['action']) {
                Order::ACTION_CREATE, Order::ACTION_ACTIVATE => $service->activateOrder($order),
                Order::ACTION_RENEW => $service->renewOrder($order),
                Order::ACTION_SUSPEND => $service->suspendFromOrder($order),
                Order::ACTION_UNSUSPEND => $service->unsuspendFromOrder($order),
                Order::ACTION_CANCEL => $service->cancelFromOrder($order),
                Order::ACTION_UNCANCEL => $service->uncancelFromOrder($order),
                Order::ACTION_DELETE => $service->deleteFromOrder($order),
                Upgrades::ACTION => Upgrades::retryForOrder($di, $orderId),
                StatusCheck::ACTION => StatusCheck::check($di, $orderId) || throw new InformationException('The hosting status still differs from the panel.'),
                default => throw new InformationException('This action cannot be retried.'),
            };
        }
        $now = date('Y-m-d H:i:s');
        self::closeAfterSuccess($db, $orderId, (string) $entry['action'], $now, $adminId);
        // The service call's success closed the entry already, without knowing who retried it.
        $db->executeStatement(
            'UPDATE ' . self::TABLE . ' SET resolved_by = ? WHERE id = ? AND status = ? AND resolved_by IS NULL',
            [$adminId, $id, self::STATUS_RESOLVED],
        );

        return true;
    }

    public static function dismiss(\Pimple\Container $di, int $id, ?int $adminId): bool
    {
        $db = self::db($di);
        self::openEntry($db, $id);
        $db->executeStatement(
            'UPDATE ' . self::TABLE . ' SET status = ?, resolved_at = ?, resolved_by = ? WHERE id = ?',
            [self::STATUS_DISMISSED, date('Y-m-d H:i:s'), $adminId, $id],
        );

        return true;
    }

    /** @return array<string, mixed> */
    public static function toArray(array $row): array
    {
        $name = trim(($row['client_first_name'] ?? '') . ' ' . ($row['client_last_name'] ?? ''));

        return [
            'id' => (int) $row['id'],
            'order_id' => (int) $row['client_order_id'],
            'order_title' => $row['order_title'] ?? null,
            'order_status' => $row['order_status'] ?? null,
            'client_id' => isset($row['client_id']) ? (int) $row['client_id'] : null,
            'client_name' => $name === '' ? null : $name,
            'action' => (string) $row['action'],
            'message' => (string) $row['message'],
            'attempts' => (int) $row['attempts'],
            'status' => (string) $row['status'],
            'first_failed_at' => $row['first_failed_at'],
            'last_failed_at' => $row['last_failed_at'],
            'resolved_at' => $row['resolved_at'] ?? null,
            'resolved_by' => isset($row['resolved_by']) ? (int) $row['resolved_by'] : null,
        ];
    }

    private static function openEntry(Connection $db, int $id): array
    {
        $entry = $db->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE id = ?', [$id]);
        if ($entry === false) {
            throw new InformationException('Failed action not found.');
        }
        if ($entry['status'] !== self::STATUS_OPEN) {
            throw new InformationException('This failed action is already closed.');
        }

        return $entry;
    }

    /** Emails staff about a new entry (template mod_staff_order_action_failed). */
    private static function notify(\Pimple\Container $di, int $id): void
    {
        try {
            $row = self::db($di)->fetchAssociative(self::SELECT . ' WHERE f.id = ?', [$id]);
            if ($row === false) {
                return;
            }
            $failure = self::toArray($row);
            $failure['order_url'] = $di['url']->adminLink('order/manage/' . $failure['order_id']);
            $failure['list_url'] = $di['url']->adminLink('order/failed-actions');
            $di['mod_service']('email')->sendTemplate([
                'to_staff' => true,
                'code' => 'mod_staff_order_action_failed',
                'failure' => $failure,
            ]);
        } catch (\Throwable $e) {
            self::log($di, 'could not email staff about a failed order action: ' . $e->getMessage());
        }
    }

    private static function clip(string $message): string
    {
        $message = trim($message) === '' ? 'No error message was given.' : trim($message);

        return mb_strlen($message) > self::MAX_MESSAGE ? mb_substr($message, 0, self::MAX_MESSAGE - 1) . '…' : $message;
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
            error_log('ClientFence: ' . $message);
        }
    }
}
