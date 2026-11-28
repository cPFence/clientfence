<?php

declare(strict_types=1);

namespace ClientFence\Mod\Order;

use ClientFence\InformationException;
use ClientFence\Mod\Order\Entity\Order;
use ClientFence\Mod\Product\Service as ProductService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Connection;

/**
 * ClientFence: orders that wait for staff review (WHMCS's Fraud status), shared by the checks that hold an
 * order: hosting networks (CF-119, Antispam/Networks.php), the fraud check (CF-116, FraudCheck.php), new clients
 * (newClient(), forum #188) and ID documents (CF-124, Client/IdDocuments.php). A checkout held by several checks is one hold: the first check's reason, the
 * others in the note (combine()).
 *
 * A held checkout's orders stay in Pending setup and its invoice stays a draft (clients cannot see or pay
 * it, no reminders, no credit taken, nothing set up). They wait in Orders > Waiting for review, and staff
 * hear of it in the bell. Approving makes the invoice a normal unpaid one (paid from credit when it covers
 * it, with its email) and carries on with the setup checkout would have done; refusing cancels the orders and
 * the invoice, and may ban the client's email (Antispam/Banned.php). The client gets an email either way.
 * The orders of one checkout share their invoice and are decided together.
 */
final class Holds
{
    public const TABLE = 'client_order_hold';

    public const REASON_NETWORK = 'network';
    public const REASON_FRAUD = 'fraud';
    public const REASON_ID_DOCUMENT = 'id_document';
    public const REASON_NEW_CLIENT = 'new_client';

    public const STATUS_HELD = 'held';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REFUSED = 'refused';

    /** Holds a checkout's orders (Cart\Service::createFromCart(), in its transaction). */
    public static function add(Connection $db, array $orderIds, int $clientId, ?int $invoiceId, string $reason, string $note): void
    {
        $now = date('Y-m-d H:i:s');
        foreach ($orderIds as $orderId) {
            $db->insert(self::TABLE, [
                'client_order_id' => $orderId, 'client_id' => $clientId, 'invoice_id' => $invoiceId, 'reason' => $reason,
                'note' => mb_substr($note, 0, 255), 'status' => self::STATUS_HELD, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /**
     * The checks' answers (Networks::hold(), FraudCheck::hold(), IdDocuments::hold()) as one hold, or null: the first
     * reason, and the others after the note as "[reason] note", so approving an ID document releases only a checkout
     * that waits for nothing else.
     *
     * @param array{reason: string, note: string}|null ...$holds
     *
     * @return array{reason: string, note: string}|null
     */
    public static function combine(?array ...$holds): ?array
    {
        $holds = array_values(array_filter($holds));
        if ($holds === []) {
            return null;
        }
        $note = $holds[0]['note'];
        foreach (array_slice($holds, 1) as $other) {
            $note = trim($note . ' [' . $other['reason'] . '] ' . $other['note']);
        }

        return ['reason' => $holds[0]['reason'], 'note' => $note];
    }

    /** Orders settings > Order Review: a client without an active order waits for staff review (forum #188). */
    public static function newClient(\Pimple\Container $di, int $clientId): ?array
    {
        if (empty($di['mod_config']('order')['hold_new_clients'])) {
            return null;
        }

        return $di['dbal']->fetchOne("SELECT 1 FROM client_order WHERE client_id = ? AND status = 'active' LIMIT 1", [$clientId]) === false
            ? ['reason' => self::REASON_NEW_CLIENT, 'note' => ''] : null;
    }

    /** The order page: the order's hold while it waits, or null. */
    public static function forOrder(\Pimple\Container $di, int $orderId): ?array
    {
        $hold = self::db($di)->fetchAssociative('SELECT id, reason, note, created_at FROM ' . self::TABLE . ' WHERE client_order_id = ? AND status = ?', [$orderId, self::STATUS_HELD]);

        return $hold === false ? null : $hold;
    }

    /** After the checkout: staff hear of it in the bell (and chat alerts). */
    public static function notify(\Pimple\Container $di, int $orderId, string $title): void
    {
        \ClientFence\Mod\Clientfence\Notifications::staff($di, 'order_held', ['id' => $orderId, 'title' => $title], 'order#tab-holds', 'order', null, 'order_held:' . $orderId);
    }

    /** The orders waiting for review, oldest first, with their client (an order staff deleted is left out). */
    public static function heldList(\Pimple\Container $di): array
    {
        return self::db($di)->fetchAllAssociative(
            'SELECT h.*, o.title AS order_title, c.first_name AS client_first_name, c.last_name AS client_last_name, c.email AS client_email
             FROM ' . self::TABLE . ' h JOIN client_order o ON o.id = h.client_order_id LEFT JOIN client c ON c.id = h.client_id
             WHERE h.status = ? ORDER BY h.id',
            [self::STATUS_HELD],
        );
    }

    /** Staff approve a held order and the others of its checkout: the invoice is issued and setup carries on. */
    public static function approve(\Pimple\Container $di, int $id, ?int $adminId): void
    {
        [$hold, $orderIds] = self::claim($di, $id, self::STATUS_APPROVED, $adminId);
        try {
            $db = self::db($di);
            $paid = true;
            if ($hold['invoice_id'] !== null) {
                $invoice = $di['db']->load('Invoice', (int) $hold['invoice_id']);
                if ($invoice instanceof \Model_Invoice && !$invoice->approved && $invoice->status === \Model_Invoice::STATUS_UNPAID) {
                    // The client gets the payment term from now, not from the checkout.
                    $invoice->due_at = date('Y-m-d H:i:s', time() + max(0, strtotime((string) $invoice->due_at) - strtotime((string) $invoice->created_at)));
                    $db->executeStatement('UPDATE invoice SET due_at = ? WHERE id = ?', [$invoice->due_at, (int) $invoice->id]);
                    $di['mod_service']('Invoice')->approveInvoice($invoice, ['id' => $invoice->id, 'use_credits' => true]);
                }
                $paid = $db->fetchOne('SELECT status FROM invoice WHERE id = ?', [$hold['invoice_id']]) === \Model_Invoice::STATUS_PAID;
                if (!$paid) {
                    $db->executeStatement('UPDATE client_order SET unpaid_invoice_id = ? WHERE id IN (?)', [(int) $hold['invoice_id'], $orderIds], [ParameterType::INTEGER, ArrayParameterType::INTEGER]);
                }
            } else {
                // A free checkout's promo uses were reserved for the decision (Cart\Service::createFromCart()).
                $db->executeStatement("UPDATE promo_redemption SET status = 'committed', committed_at = ?, updated_at = ? WHERE client_order_id IN (?) AND invoice_id IS NULL AND status = 'reserved'", [date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $orderIds], [ParameterType::STRING, ParameterType::STRING, ArrayParameterType::INTEGER]);
            }
            self::setUp($di, $orderIds, $paid);
        } catch (\Throwable $e) {
            self::release($di, $orderIds, self::STATUS_APPROVED);

            throw $e;
        }
        $di['logger']->info('Approved held orders #%s', implode(', #', $orderIds));
        self::email($di, 'mod_order_hold_approved', $hold, $orderIds);
    }

    /** Staff refuse a held order and the others of its checkout: they and their invoice are cancelled. */
    public static function refuse(\Pimple\Container $di, int $id, ?int $adminId, bool $ban): void
    {
        [$hold, $orderIds] = self::claim($di, $id, self::STATUS_REFUSED, $adminId);
        $db = self::db($di);
        $orderService = $di['mod_service']('order');
        $now = date('Y-m-d H:i:s');
        try {
            foreach ($orderIds as $orderId) {
                $order = $di['db']->load('ClientOrder', $orderId);
                if (!$order instanceof \Model_ClientOrder || $order->status !== Order::STATUS_PENDING_SETUP) {
                    continue;
                }
                $db->executeStatement('UPDATE client_order SET status = ?, reason = ?, canceled_at = ?, updated_at = ? WHERE id = ?', [Order::STATUS_CANCELED, 'Refused after review', $now, $now, $orderId]);
                $orderService->orderStatusAdd($order, Order::STATUS_CANCELED, 'Refused after review');
                $di['mod_service']('Product')->releaseReservedPromoRedemptionsForOrder($order, 'order_refused');
            }
            if ($hold['invoice_id'] !== null) {
                $db->executeStatement('UPDATE invoice SET status = ?, updated_at = ? WHERE id = ? AND approved = 0 AND status = ?', [\Model_Invoice::STATUS_CANCELED, $now, (int) $hold['invoice_id'], \Model_Invoice::STATUS_UNPAID]);
            }
        } catch (\Throwable $e) {
            self::release($di, $orderIds, self::STATUS_REFUSED);

            throw $e;
        }
        if ($ban && $hold['client_email'] !== null) {
            \ClientFence\Mod\Antispam\Banned::add($di, (string) $hold['client_email']);
        }
        $di['logger']->info('Refused held orders #%s', implode(', #', $orderIds));
        self::email($di, 'mod_order_hold_refused', $hold, $orderIds);
    }

    /**
     * Marks the held orders of the hold's checkout decided; a second click finds nothing waiting.
     *
     * @return array{0: array, 1: list<int>}
     */
    private static function claim(\Pimple\Container $di, int $id, string $status, ?int $adminId): array
    {
        $db = self::db($di);
        $hold = $db->fetchAssociative('SELECT h.*, c.email AS client_email FROM ' . self::TABLE . ' h LEFT JOIN client c ON c.id = h.client_id WHERE h.id = ? AND h.status = ?', [$id, self::STATUS_HELD]);
        if ($hold === false) {
            throw new InformationException('This order is no longer waiting for review.');
        }
        // A free checkout has no invoice: its rows share the client and the time add() wrote them.
        // shortcut: two free checkouts of one client in the same second are decided together; add a checkout column if that matters.
        [$where, $key] = $hold['invoice_id'] !== null ? ['invoice_id = ?', [$hold['invoice_id']]] : ['invoice_id IS NULL AND client_id = ? AND created_at = ?', [$hold['client_id'], $hold['created_at']]];
        $orderIds = array_map(intval(...), $db->fetchFirstColumn('SELECT client_order_id FROM ' . self::TABLE . " WHERE {$where} AND status = ? ORDER BY client_order_id", [...$key, self::STATUS_HELD]));
        $claimed = $db->executeStatement('UPDATE ' . self::TABLE . " SET status = ?, admin_id = ?, updated_at = ? WHERE {$where} AND status = ?", [$status, $adminId, date('Y-m-d H:i:s'), ...$key, self::STATUS_HELD]);
        if ($claimed === 0) {
            throw new InformationException('This order is no longer waiting for review.');
        }

        return [$hold, $orderIds];
    }

    /** @param list<int> $orderIds */
    private static function release(\Pimple\Container $di, array $orderIds, string $status): void
    {
        self::db($di)->executeStatement('UPDATE ' . self::TABLE . ' SET status = ?, admin_id = NULL WHERE client_order_id IN (?) AND status = ?', [self::STATUS_HELD, $orderIds, $status], [ParameterType::STRING, ArrayParameterType::INTEGER, ParameterType::STRING]);
    }

    /**
     * The setup checkout skipped (Cart\Service::createFromCart()): activated after the order, or after payment
     * once paid (credit already ran the invoice's activation) or free.
     *
     * @param list<int> $orderIds
     */
    private static function setUp(\Pimple\Container $di, array $orderIds, bool $paid): void
    {
        $orderService = $di['mod_service']('order');
        foreach ($orderIds as $orderId) {
            $order = $di['db']->load('ClientOrder', $orderId);
            if (!$order instanceof \Model_ClientOrder || $order->status !== Order::STATUS_PENDING_SETUP) {
                continue;
            }
            $setup = self::db($di)->fetchOne('SELECT setup FROM product WHERE id = ?', [(int) $order->product_id]);
            if ($setup !== ProductService::SETUP_AFTER_ORDER && !($paid && $setup === ProductService::SETUP_AFTER_PAYMENT)) {
                continue;
            }

            try {
                $orderService->activateOrder($order);
            } catch (\Throwable $e) {
                // As at checkout: one failed setup does not undo the approval of the others.
                $di['logger']->error('Order activation failed after review: %s', $e->getMessage());
                $orderService->orderStatusAdd($order, Order::STATUS_FAILED_SETUP, 'Order could not be activated after review due to error: ' . rtrim($e->getMessage(), '.') . '.');
            }
        }
    }

    /** @param list<int> $orderIds */
    private static function email(\Pimple\Container $di, string $code, array $hold, array $orderIds): void
    {
        try {
            $orders = self::db($di)->fetchAllAssociative('SELECT id, title FROM client_order WHERE id IN (?) ORDER BY id', [$orderIds], [ArrayParameterType::INTEGER]);
            $di['mod_service']('email')->sendTemplate(['to_client' => (int) $hold['client_id'], 'code' => $code, 'orders' => $orders]);
        } catch (\Throwable $e) {
            $di['logger']->warning('Held order #%s: client email %s not sent: %s', $hold['client_order_id'], $code, $e->getMessage());
        }
    }

    private static function db(\Pimple\Container $di): Connection
    {
        return $di['em']->getConnection();
    }
}
