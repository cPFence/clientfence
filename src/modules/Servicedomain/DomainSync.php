<?php

declare(strict_types=1);

namespace ClientFence\Mod\Servicedomain;

use ClientFence\Mod\Clientfence\Notifications;
use ClientFence\Mod\Order\ActionFailures;
use Doctrine\DBAL\Connection;

/**
 * ClientFence: the daily domain sync and incoming transfers until they complete (Domain Management > Sync).
 *
 * Daily sync (switch servicedomain_daily_sync, Off by default: then the monthly run stays as it was):
 * each day a share of the domains (all of them in about 30 days, at least 20 a day), oldest sync
 * first, get their expiry date from the registrar. It never changes the order's next due date; a
 * registrar date before it is a staff notification, once per domain and date.
 *
 * Transfers (switch servicedomain_track_transfers, Off by default): with a registrar that can tell
 * (Registrar_TransferStatus), an accepted transfer stays "transfer_pending" until the registrar
 * reports it done and the full sync then works; the client is emailed then. A failed one becomes
 * "transfer_failed" and a Failed actions entry (ACTION) that staff retry or dismiss. Pending transfers
 * are checked every hour, also after the switch is turned off.
 */
final class DomainSync
{
    public const DAILY = 'servicedomain_daily_sync';
    public const TRACK = 'servicedomain_track_transfers';
    public const PENDING = 'transfer_pending';
    public const FAILED = 'transfer_failed';
    /** The Failed actions entry of a transfer (Order/ActionFailures.php). */
    public const ACTION = 'domain_transfer';

    private const DAYS = 30;
    private const MIN_PER_DAY = 20;
    private const STUCK_DAYS = 14;

    /** From Service::onAfterAdminCronRun(). */
    public static function runIfDue(\Pimple\Container $di): void
    {
        if (self::on($di, self::DAILY)) {
            self::batch($di, 45);
        }
        $state = self::state($di, 'domain_transfers');
        if (time() - (int) strtotime((string) ($state['at'] ?? '')) >= 3600) {
            self::saveState($di, 'domain_transfers', ['at' => date('Y-m-d H:i:s')]);
            self::checkTransfers($di, 30);
        }
    }

    public static function on(\Pimple\Container $di, string $switch): bool
    {
        return (bool) $di['mod_service']('system')->getParamValue($switch, '0');
    }

    /**
     * Syncs the expiry dates of today's share of the domains, for at most $seconds. Returns how
     * many were synced in this call.
     */
    public static function batch(\Pimple\Container $di, int $seconds): int
    {
        $db = self::db($di);
        $state = self::state($di, 'domain_sync');
        if (($state['day'] ?? '') !== date('Y-m-d')) {
            $count = (int) $db->fetchOne(self::DOMAINS_SQL_COUNT);
            $state = ['day' => date('Y-m-d'), 'quota' => max(self::MIN_PER_DAY, (int) ceil($count / self::DAYS)), 'done' => 0];
        }
        $left = (int) $state['quota'] - (int) $state['done'];
        if ($left <= 0) {
            return 0;
        }
        $service = $di['mod_service']('servicedomain');
        $deadline = microtime(true) + $seconds;
        $done = $failed = 0;
        foreach ($db->fetchAllAssociative(self::DOMAINS_SQL . ' LIMIT ' . $left) as $row) {
            $model = $di['db']->load('ServiceDomain', (int) $row['id']);
            if (!$model instanceof \Model_ServiceDomain) {
                continue;
            }
            $error = null;
            try {
                $service->syncExpirationDate($model);
            } catch (\Throwable $e) {
                $error = mb_substr($e->getMessage() !== '' ? $e->getMessage() : $e::class, 0, 1000);
                ++$failed;
            }
            // A failed domain is stamped too, so it does not hold the queue; its error shows on its page.
            $db->update('service_domain', ['synced_at' => date('Y-m-d H:i:s'), 'sync_error' => $error], ['id' => (int) $row['id']]);
            if ($error === null) {
                self::expiryNotice($di, $model, $row);
            }
            ++$done;
            if (microtime(true) >= $deadline) {
                break;
            }
        }
        $state['done'] = (int) $state['done'] + $done;
        self::saveState($di, 'domain_sync', $state);
        $di['logger']->info('Daily domain sync: %d domain(s) synced (%d failed), %d of %d for today.', $done, $failed, $state['done'], $state['quota']);

        return $done;
    }

    /** Domains of active and suspended orders, never synced first, then the oldest sync; tracked transfers are left to checkTransfers(). */
    private const DOMAINS_FROM = " FROM service_domain d JOIN client_order o ON o.service_id = d.id AND o.service_type = 'domain'"
        . " WHERE o.status IN ('active', 'suspended') AND (d.action IS NULL OR d.action NOT IN ('" . self::PENDING . "', '" . self::FAILED . "'))";
    private const DOMAINS_SQL = 'SELECT d.id, d.sld, d.tld, o.id AS order_id, o.expires_at AS due_at' . self::DOMAINS_FROM . ' ORDER BY d.synced_at IS NOT NULL, d.synced_at, d.id';
    private const DOMAINS_SQL_COUNT = 'SELECT COUNT(*)' . self::DOMAINS_FROM;

    /** A registrar expiry before the order's next due date: staff decide (the order's date is never changed here). */
    private static function expiryNotice(\Pimple\Container $di, \Model_ServiceDomain $model, array $row): void
    {
        $expires = substr((string) $model->expires_at, 0, 10);
        $due = substr((string) ($row['due_at'] ?? ''), 0, 10);
        if ($expires !== '' && $due !== '' && $expires < $due) {
            Notifications::staff($di, 'domain_expires_early', ['domain' => $model->sld . $model->tld, 'date' => $expires, 'due' => $due], 'order/manage/' . $row['order_id'], 'order', null, 'domain_expires_early:' . $model->id . ':' . $expires);
        }
    }

    /** Checks the pending transfers (suspended orders too: the registrar goes on), least recently checked first, for at most $seconds. */
    public static function checkTransfers(\Pimple\Container $di, int $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        $ids = self::db($di)->fetchFirstColumn("SELECT d.id FROM service_domain d JOIN client_order o ON o.service_id = d.id AND o.service_type = 'domain' WHERE d.action = ? AND o.status IN ('active', 'suspended') ORDER BY d.synced_at IS NOT NULL, d.synced_at, d.id", [self::PENDING]);
        foreach ($ids as $id) {
            $model = $di['db']->load('ServiceDomain', (int) $id);
            if ($model instanceof \Model_ServiceDomain) {
                try {
                    self::checkOne($di, $model);
                } catch (\Throwable $e) {
                    $di['logger']->error('Transfer check of domain #%s failed: %s', $id, $e->getMessage());
                }
            }
            if (microtime(true) >= $deadline) {
                return;
            }
        }
    }

    /**
     * Asks the registrar where one pending transfer stands and acts on it. Returns the status
     * (Registrar_TransferStatus::*), or null when the domain is not a tracked transfer.
     */
    public static function checkOne(\Pimple\Container $di, \Model_ServiceDomain $model): ?string
    {
        if ($model->action !== self::PENDING) {
            return null;
        }
        $db = self::db($di);
        $service = $di['mod_service']('servicedomain');
        $order = $di['mod_service']('order')->getServiceOrder($model);
        if (!$order instanceof \Model_ClientOrder) {
            return null;
        }
        $db->update('service_domain', ['synced_at' => date('Y-m-d H:i:s')], ['id' => (int) $model->id]);
        try {
            $answer = $service->transferStatus($model);
        } catch (\Throwable $e) {
            // A registrar that keeps refusing the question still reaches staff after STUCK_DAYS.
            self::stuck($di, $model, $order, $e->getMessage());

            throw $e;
        }
        if ($answer === null) {
            return null;
        }
        $message = trim($answer['message']);

        if ($answer['status'] === \Registrar_TransferStatus::COMPLETED) {
            // "Completed" counts only when the domain is in the account: the full sync must work.
            try {
                $service->synchronizeDomain($model);
            } catch (\Throwable $e) {
                $di['logger']->info('Transfer of domain #%s is reported done, but its sync failed: %s', $model->id, $e->getMessage());
                self::stuck($di, $model, $order, $e->getMessage());

                return \Registrar_TransferStatus::PENDING;
            }
            if (empty($model->expires_at)) {
                self::stuck($di, $model, $order, $message);

                return \Registrar_TransferStatus::PENDING;
            }
            // Only the change from pending goes on, once.
            if ($db->executeStatement('UPDATE service_domain SET action = NULL, transfer_since = NULL WHERE id = ? AND action = ?', [(int) $model->id, self::PENDING]) !== 1) {
                return \Registrar_TransferStatus::COMPLETED;
            }
            self::completed($di, $model, $order);

            return \Registrar_TransferStatus::COMPLETED;
        }

        if ($answer['status'] === \Registrar_TransferStatus::FAILED) {
            if ($db->executeStatement('UPDATE service_domain SET action = ? WHERE id = ? AND action = ?', [self::FAILED, (int) $model->id, self::PENDING]) === 1) {
                ActionFailures::failed($di, (int) $order->id, self::ACTION, new \RuntimeException($message !== '' ? $message : 'The registrar reports that the transfer failed.'));
            }

            return \Registrar_TransferStatus::FAILED;
        }

        self::stuck($di, $model, $order, $message);

        return \Registrar_TransferStatus::PENDING;
    }

    /** Still pending after STUCK_DAYS: one entry for staff per transfer attempt (the checks go on). */
    private static function stuck(\Pimple\Container $di, \Model_ServiceDomain $model, \Model_ClientOrder $order, string $message): void
    {
        $since = (string) $model->transfer_since;
        if ($since !== '' && strtotime($since) < time() - self::STUCK_DAYS * 86400
            && self::db($di)->fetchOne('SELECT 1 FROM ' . ActionFailures::TABLE . ' WHERE client_order_id = ? AND action = ? AND first_failed_at >= ?', [(int) $order->id, self::ACTION, $since]) === false) {
            ActionFailures::failed($di, (int) $order->id, self::ACTION, new \RuntimeException(sprintf('The transfer is still pending after %d days: check it at the registrar.%s', self::STUCK_DAYS, trim($message) !== '' ? ' ' . trim($message) : '')));
        }
    }

    /** Staff retry of a failed or stuck transfer (Failed actions > Retry): sends it again and tracks it. */
    public static function retryTransfer(\Pimple\Container $di, int $orderId): bool
    {
        $order = $di['db']->getExistingModelById('ClientOrder', $orderId, 'Order not found');
        $model = $di['mod_service']('order')->getOrderService($order);
        if (!$model instanceof \Model_ServiceDomain || !in_array($model->action, [self::PENDING, self::FAILED], true)) {
            throw new \ClientFence\InformationException('This domain has no transfer to retry.');
        }
        $di['mod_service']('servicedomain')->transferAgain($model);
        self::db($di)->update('service_domain', ['action' => self::PENDING, 'transfer_since' => date('Y-m-d H:i:s')], ['id' => (int) $model->id]);
        $di['mod_service']('order')->saveStatusChange($order, 'Domain transfer sent to the registrar again.');

        return true;
    }

    /** A completed transfer: order history, the next due date rule, the client email, staff and Failed actions. */
    private static function completed(\Pimple\Container $di, \Model_ServiceDomain $model, \Model_ClientOrder $order): void
    {
        $orderService = $di['mod_service']('order');
        $expires = substr((string) $model->expires_at, 0, 10);
        $note = sprintf('Domain transfer completed: expires at the registrar on %s.', $expires);
        // Some extensions add no year on transfer: the renewal must not come after the registrar's expiry.
        $due = (string) $order->expires_at;
        if ($due !== '' && $expires < substr($due, 0, 10)) {
            $order->expires_at = $expires . substr($due, 10);
            $order->updated_at = date('Y-m-d H:i:s');
            $di['db']->store($order);
            $note .= sprintf(' Next due date moved from %s to %s.', substr($due, 0, 10), $expires);
        }
        $orderService->saveStatusChange($order, $note);
        ActionFailures::succeeded($di, (int) $order->id, self::ACTION);
        $title = (string) $order->title;
        Notifications::staff($di, 'transfer_completed', ['domain' => $model->sld . $model->tld, 'id' => (int) $order->id, 'title' => $title], 'order/manage/' . $order->id, 'order');
        try {
            $model->action = null;
            $di['mod_service']('email')->sendTemplate([
                'to_client' => (int) $order->client_id,
                'code' => 'mod_servicedomain_transfer_completed',
                'order' => $orderService->toApiArray($order, false),
                'service' => $di['mod_service']('servicedomain')->toApiArray($model),
            ]);
        } catch (\Throwable $e) {
            $di['logger']->error('Transfer completed email for order #%s not sent: %s', $order->id, $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private static function state(\Pimple\Container $di, string $name): array
    {
        $state = json_decode((string) self::db($di)->fetchOne('SELECT value FROM mod_clientfence_state WHERE name = ?', [$name]), true);

        return is_array($state) ? $state : [];
    }

    /** @param array<string, mixed> $state */
    private static function saveState(\Pimple\Container $di, string $name, array $state): void
    {
        $db = self::db($di);
        $now = date('Y-m-d H:i:s');
        if ($db->executeStatement('UPDATE mod_clientfence_state SET value = ?, updated_at = ? WHERE name = ?', [json_encode($state), $now, $name]) === 0
            && $db->fetchOne('SELECT 1 FROM mod_clientfence_state WHERE name = ?', [$name]) === false) {
            $db->insert('mod_clientfence_state', ['name' => $name, 'value' => json_encode($state), 'updated_at' => $now]);
        }
    }

    private static function db(\Pimple\Container $di): Connection
    {
        return $di['em']->getConnection();
    }
}
