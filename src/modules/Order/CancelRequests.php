<?php

declare(strict_types=1);

namespace ClientFence\Mod\Order;

use ClientFence\InformationException;
use ClientFence\Tools;
use Doctrine\DBAL\Connection;

/**
 * ClientFence: cancellation requests, as WHMCS (Clients > Cancellation Requests).
 *
 * A client asks to cancel a service from its page, with a reason and a type: Immediate, or End of
 * billing period (only for a service billed by period). The request waits in Orders > Cancellation
 * Requests, staff get an email and a bell notice, and the client can withdraw it while it waits.
 * Staff accept it (an Immediate request cancels the service, an End of billing period one schedules
 * the cancellation at the end of the paid period) or stop it. With "Cancellation Requests: processed
 * automatically" (Orders settings, WHMCS Automation Settings) the daily cron accepts the waiting
 * requests once a day, which leaves the client and staff until then to take a request back.
 */
final class CancelRequests
{
    public const TABLE = 'client_order_cancel_request';

    public const IMMEDIATE = 'immediate';
    public const END_OF_PERIOD = 'end_of_period';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_WITHDRAWN = 'withdrawn';

    /** The day the cron last accepted requests (mod_clientfence_state). */
    private const STATE = 'cancel_requests_day';

    private const MAX_REASON = 2000;

    /** The client asks to cancel an active service; returns the request id. */
    public static function create(\Pimple\Container $di, \Model_ClientOrder $order, string $type, string $reason): int
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InformationException('Please tell us why you want to cancel.');
        }
        if (!in_array($type, [self::IMMEDIATE, self::END_OF_PERIOD], true)) {
            throw new InformationException('Invalid cancellation type.');
        }
        if ($order->status !== 'active') {
            throw new InformationException('Only an active service can be cancelled.');
        }
        if ($type === self::END_OF_PERIOD && !$di['mod_service']('order')->canCancelAtPeriodEnd($order)) {
            throw new InformationException('This service cannot be cancelled at the end of its period. Choose Immediate, or open a ticket.');
        }
        $db = self::db($di);
        if (self::pending($di, (int) $order->id) !== null) {
            throw new InformationException('We have already received this request.');
        }
        $id = self::insert($db, (int) $order->id, (int) $order->client_id, $type, mb_substr($reason, 0, self::MAX_REASON), date('Y-m-d H:i:s'));
        $di['logger']->info('Client requested the cancellation of order #%s (%s)', $order->id, $type);
        self::notify($di, $id, $order);

        return $id;
    }

    /** Saves a request as it is (the WHMCS importer keeps the request's own date). */
    public static function insert(Connection $db, int $orderId, int $clientId, string $type, string $reason, string $createdAt): int
    {
        $db->insert(self::TABLE, [
            'client_order_id' => $orderId, 'client_id' => $clientId, 'type' => $type, 'reason' => $reason,
            'status' => self::STATUS_PENDING, 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);

        return (int) $db->lastInsertId();
    }

    /** The waiting request of an order, or null. */
    public static function pending(\Pimple\Container $di, int $orderId): ?array
    {
        $row = self::db($di)->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE client_order_id = ? AND status = ? ORDER BY id DESC LIMIT 1', [$orderId, self::STATUS_PENDING]);

        return $row === false ? null : $row;
    }

    /** The waiting requests, oldest first, with their service and client. */
    public static function pendingList(\Pimple\Container $di): array
    {
        return self::db($di)->fetchAllAssociative(
            'SELECT r.*, o.title AS order_title, o.status AS order_status, o.expires_at, c.first_name AS client_first_name, c.last_name AS client_last_name
             FROM ' . self::TABLE . ' r LEFT JOIN client_order o ON o.id = r.client_order_id LEFT JOIN client c ON c.id = r.client_id
             WHERE r.status = ? ORDER BY r.id',
            [self::STATUS_PENDING],
        );
    }

    /** The client takes back the waiting request of their order. */
    public static function withdraw(\Pimple\Container $di, int $clientId, int $orderId): void
    {
        $request = self::pending($di, $orderId);
        if ($request === null || (int) $request['client_id'] !== $clientId) {
            throw new InformationException('This service has no cancellation request waiting.');
        }
        self::close($di, (int) $request['id'], self::STATUS_WITHDRAWN);
        $di['logger']->info('Client withdrew the cancellation request of order #%s', $orderId);
    }

    /** Staff stop a waiting request; the service is not cancelled. */
    public static function stop(\Pimple\Container $di, int $id): void
    {
        self::close($di, (int) self::find($di, $id)['id'], self::STATUS_STOPPED);
        $di['logger']->info('Stopped cancellation request #%s', $id);
    }

    /** Cancels the service of a waiting request now or at the end of its period, as the client asked. */
    public static function accept(\Pimple\Container $di, int $id): void
    {
        $request = self::find($di, $id);
        $orderService = $di['mod_service']('order');
        $order = $orderService->findByClientIdAndOrderId((int) $request['client_id'], (int) $request['client_order_id']);
        // Staff deleted or cancelled it already: nothing is left to do.
        if ($order instanceof \Model_ClientOrder && $order->status !== 'canceled') {
            $reason = 'Cancellation request #' . $request['id'];
            if ($request['type'] === self::END_OF_PERIOD) {
                $orderService->scheduleCancellationFromOrder($order, $reason);
            } else {
                $orderService->cancelFromOrder($order, $reason);
            }
        }
        self::close($di, (int) $request['id'], self::STATUS_ACCEPTED);
        $di['logger']->info('Accepted cancellation request #%s', $id);
    }

    /** The cron: once a day, accepts the waiting requests when the setting is on; a failing one stays for staff. */
    public static function run(\Pimple\Container $di): void
    {
        if (!Tools::normalizeBoolean($di['mod']('order')->getConfig()['cancel_requests_auto'] ?? false)) {
            return;
        }
        $db = self::db($di);
        $today = date('Y-m-d');
        $day = $db->fetchOne('SELECT value FROM mod_clientfence_state WHERE name = ?', [self::STATE]);
        if ($day === $today) {
            return;
        }
        $day === false
            ? $db->insert('mod_clientfence_state', ['name' => self::STATE, 'value' => $today, 'updated_at' => date('Y-m-d H:i:s')])
            : $db->update('mod_clientfence_state', ['value' => $today, 'updated_at' => date('Y-m-d H:i:s')], ['name' => self::STATE]);
        foreach ($db->fetchFirstColumn('SELECT id FROM ' . self::TABLE . ' WHERE status = ? ORDER BY id', [self::STATUS_PENDING]) as $id) {
            try {
                self::accept($di, (int) $id);
            } catch (\Throwable $e) {
                $di['logger']->error('Order: cancellation request #%s was not processed: %s', $id, $e->getMessage());
            }
        }
    }

    private static function find(\Pimple\Container $di, int $id): array
    {
        $row = self::db($di)->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE id = ? AND status = ?', [$id, self::STATUS_PENDING]);
        if ($row === false) {
            throw new InformationException('This cancellation request is no longer waiting.');
        }

        return $row;
    }

    private static function close(\Pimple\Container $di, int $id, string $status): void
    {
        self::db($di)->update(self::TABLE, ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id, 'status' => self::STATUS_PENDING]);
    }

    /** Staff hear of a new request by email (mod_staff_order_cancel_request) and in the bell. */
    private static function notify(\Pimple\Container $di, int $id, \Model_ClientOrder $order): void
    {
        try {
            $client = $di['db']->load('Client', (int) $order->client_id);
            $di['mod_service']('email')->sendTemplate([
                'to_staff' => true,
                'code' => 'mod_staff_order_cancel_request',
                'request' => self::db($di)->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE id = ?', [$id]),
                'order' => [
                    'id' => (int) $order->id, 'title' => (string) $order->title, 'expires_at' => $order->expires_at,
                    'url' => $di['url']->adminLink('order/manage/' . $order->id), 'list_url' => $di['url']->adminLink('order') . '#tab-cancel-requests',
                ],
                'client' => ['name' => $client instanceof \Model_Client ? trim($client->first_name . ' ' . $client->last_name) : ''],
            ]);
        } catch (\Throwable $e) {
            $di['logger']->warning('Cancellation request #%s: staff email not sent: %s', $id, $e->getMessage());
        }
        \ClientFence\Mod\Clientfence\Notifications::staff($di, 'cancel_request', ['id' => (int) $order->id, 'title' => (string) $order->title], 'order#tab-cancel-requests', 'order', null, 'cancel_request:' . $id);
    }

    private static function db(\Pimple\Container $di): Connection
    {
        return $di['em']->getConnection();
    }
}
