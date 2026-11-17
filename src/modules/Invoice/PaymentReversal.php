<?php

declare(strict_types=1);

/**
 * ClientFence (CF-144): "Reverse payment" undoes a payment staff recorded by mistake (a bank transfer that never arrived),
 * as WHMCS's Mark Unpaid with the payment transaction deleted, and also gives back what that payment moved. Only when no
 * gateway money is behind the invoice: every processed transaction on it was recorded by staff (Mark as Paid, or a staff
 * transaction on a Custom method), so nothing is sent anywhere. A gateway payment still needs Refund, and so does a payment
 * that renewed a domain at its registrar (paying again would renew it twice).
 *
 * What a payment leaves, and what the reversal does with it:
 * - the invoice's balance rows (client_balance type invoice, rel_id the invoice): the debits that paid it (its lines, account
 *   credit, payments recorded earlier) are removed, so that credit is the client's again; the credit an Add funds invoice
 *   added is removed too (refused when the client has spent it);
 * - its staff transactions are marked reversed, never deleted; one that put its amount on the balance (a transaction
 *   processed by Payment_Adapter_Custom, not Mark as Paid's record) has that amount taken back by a balance row;
 * - services: renewals go back by their period as in a chargeback (Service::moveRenewalsBack(): Renewal Day, add-ons on
 *   their service's day); a service the payment set up is suspended (its activation line lifts it when paid again);
 * - affiliate commissions are reversed, promotion uses reserved again, the credit applied to the invoice freed, the orders
 *   point to the invoice as their unpaid one, and the credit batch leaves it (staff took that payment back);
 * - the accounting sync (Accounting/Sync.php paymentReversed()) stops a payment (or an overpayment) it already sent, for staff
 *   to delete there.
 */

namespace ClientFence\Mod\Invoice;

use ClientFence\InformationException;
use ClientFence\Mod\Client\Affiliates\Commissions;
use ClientFence\Mod\Invoice\Accounting\Sync;
use ClientFence\Tools;

final class PaymentReversal
{
    /** Whether Reverse payment applies to the invoice (the invoice page shows the button only then). */
    public static function allowed(\Pimple\Container $di, \Model_Invoice $invoice): bool
    {
        try {
            self::plan($di, $invoice);

            return true;
        } catch (InformationException) {
            return false;
        }
    }

    public static function reverse(\Pimple\Container $di, \Model_Invoice $invoice): bool
    {
        $db = $di['dbal'];
        // Under the client's credit lock, with the invoice as it is now (a second click finds it unpaid and is refused).
        [$plan, $activated] = Tools::withLock($di, 'fb:credit_payment:client:' . (int) $invoice->client_id, fn (): array => $db->transactional(function () use ($di, $db, $invoice): array {
            $current = $db->fetchAssociative('SELECT * FROM invoice WHERE id = ?', [(int) $invoice->id]) ?: throw new InformationException('Invoice not found');
            foreach ($current as $column => $value) {
                $invoice->{$column} = $value;
            }
            $plan = self::plan($di, $invoice);
            $id = (int) $invoice->id;
            $now = date('Y-m-d H:i:s');

            Commissions::reverse($di, $id);
            // Their history stays, off the invoice, so paying it again earns the commission again (one per invoice and order,
            // and the one-time rule skips rows with this note; an imported note is kept and still holds it).
            $db->executeStatement("UPDATE client_affiliate_entry SET invoice_id = NULL, note = COALESCE(note, ?), updated_at = ? WHERE invoice_id = ? AND type = 'commission'", [Commissions::PAYMENT_REVERSED, $now, $id]);
            $plan['moved'] = $di['mod_service']('Invoice')->moveRenewalsBack($invoice);
            $activated = array_map('intval', $db->fetchFirstColumn(
                "SELECT o.id FROM invoice_item ii JOIN client_order o ON o.id = ii.rel_id JOIN product p ON p.id = o.product_id
                 WHERE ii.invoice_id = ? AND ii.type = 'order' AND ii.task = ? AND ii.status = ? AND o.status = ? AND p.setup = ?",
                [$id, \Model_InvoiceItem::TASK_ACTIVATE, \Model_InvoiceItem::STATUS_EXECUTED, \Model_ClientOrder::STATUS_ACTIVE, \ClientFence\Mod\Product\Service::SETUP_AFTER_PAYMENT],
            ));
            // The lines wait for payment again; a line whose debit goes is charged again by the next payment.
            $db->executeStatement('UPDATE invoice_item SET status = ?, attempts = 0, updated_at = ? WHERE invoice_id = ?', [\Model_InvoiceItem::STATUS_PENDING_PAYMENT, $now, $id]);
            $charged = array_filter(array_column($plan['rows'], 'invoice_item_id'));
            if ($charged !== []) {
                $db->executeStatement('UPDATE invoice_item SET charged = 0 WHERE id IN (' . implode(',', array_map('intval', $charged)) . ')');
            }
            // Removed, not offset: a line's debit is unique per line, and the sync tells a credit payment by its row.
            $db->executeStatement("DELETE FROM client_balance WHERE type = 'invoice' AND rel_id = ?", [(string) $id]);
            foreach ($plan['staff'] as $tx) {
                self::takeBack($di, (int) $invoice->client_id, $tx, $now);
            }
            $db->delete('invoice_credit', ['invoice_id' => $id]);
            // A Mass Pay invoice unpaid again pays its invoices when it is paid again (MassPay::settle() runs once per payment).
            $db->delete('extension_meta', ['extension' => 'mod_invoice', 'rel_type' => 'invoice', 'rel_id' => (string) $id, 'meta_key' => MassPay::SETTLED]);
            $db->executeStatement("UPDATE promo_redemption SET status = 'reserved', committed_at = NULL WHERE invoice_id = ? AND status = 'committed'", [$id]);
            $db->executeStatement("UPDATE client_order SET unpaid_invoice_id = ? WHERE unpaid_invoice_id IS NULL AND id IN (SELECT rel_id FROM invoice_item WHERE invoice_id = ? AND type = 'order')", [$id, $id]);
            if ($db->fetchOne("SELECT 1 FROM extension_meta WHERE extension = 'mod_invoice' AND meta_key = 'no_auto_credit' AND rel_id = ?", [(string) $id]) === false) {
                $di['mod_service']('Invoice')->skipAutoCredit($invoice);
            }

            $note = sprintf('Payment of %s %s reversed by staff.', number_format($plan['total'], 2), $invoice->currency)
                . ($plan['staff'] === [] ? '' : ' Transactions marked reversed: #' . implode(', #', array_column($plan['staff'], 'id')) . '.')
                . ($plan['give'] > 0 ? sprintf(' Account credit given back: %s.', number_format($plan['give'], 2)) : '')
                . ($plan['take'] > 0 ? sprintf(' Account credit taken back: %s.', number_format($plan['take'], 2)) : '')
                . ($plan['moved'] === [] ? '' : ' Due dates moved back: ' . implode(', ', $plan['moved']) . '.');
            $invoice->status = \Model_Invoice::STATUS_UNPAID;
            $invoice->paid_at = $invoice->base_income = $invoice->base_refund = null;
            $invoice->notes = $invoice->notes . $now . ': ' . $note . '       ' . PHP_EOL;
            $invoice->updated_at = $now;
            $db->update('invoice', ['status' => $invoice->status, 'paid_at' => null, 'base_income' => null, 'base_refund' => null, 'notes' => $invoice->notes, 'updated_at' => $now], ['id' => $id]);
            Sync::paymentReversed($di, $id, array_column($plan['staff'], 'id'));

            return [$plan, $activated];
        }));

        $di['logger']->info('Invoice #%s: payment of %s %s reversed (transactions: %s; credit given back %s, taken back %s).', $invoice->id, number_format($plan['total'], 2), $invoice->currency, $plan['staff'] === [] ? 'none' : '#' . implode(', #', array_column($plan['staff'], 'id')), number_format($plan['give'], 2), number_format($plan['take'], 2));
        // Server calls after the money is settled and the lock released; one that fails is left to staff.
        foreach ($activated as $orderId) {
            try {
                $di['mod_service']('Order')->suspendFromOrder($di['db']->getExistingModelById('ClientOrder', $orderId, 'Order not found'), 'Payment reversed');
            } catch (\Throwable $e) {
                $di['logger']->warning('Invoice #%s: order #%s was not suspended after its payment was reversed: %s', $invoice->id, $orderId, $e->getMessage());
                $di['mod_service']('Invoice')->addNote($invoice, sprintf('Order #%d set up by the reversed payment was not suspended: %s', $orderId, $e->getMessage()));
            }
        }

        return true;
    }

    /**
     * What the reversal undoes, or why it cannot: {total, staff: [{id, amount, note, funded}], rows: the invoice's balance
     * rows, give: credit back to the client, take: credit taken back}.
     */
    private static function plan(\Pimple\Container $di, \Model_Invoice $invoice): array
    {
        $db = $di['dbal'];
        $service = $di['mod_service']('Invoice');
        $id = (int) $invoice->id;
        if ($invoice->status !== \Model_Invoice::STATUS_PAID) {
            throw new InformationException('Only the payment of a paid invoice can be reversed.');
        }
        if ((float) $invoice->refund > 0) {
            throw new InformationException('This invoice was partly refunded, so its payment cannot be reversed. Refund the rest instead.');
        }
        $staff = [];
        foreach ($db->fetchAllAssociative("SELECT id, gateway_id, amount, ipn, note, created_at FROM `transaction` WHERE invoice_id = ? AND status = 'processed' ORDER BY id", [$id]) as $tx) {
            $ipn = json_decode((string) $tx['ipn'], true);
            // Mark as Paid records the payment on any method (Service::markAsPaidByAdmin()): no gateway took that money.
            $markedPaid = ($ipn['source'] ?? null) === 'admin' && isset($ipn['post']['invoice_id']);
            if (($ipn['source'] ?? null) !== 'admin' || (!$markedPaid && !self::isCustom($di, (int) $tx['gateway_id']))) {
                throw new InformationException('This invoice has a payment through a payment gateway or an imported payment. Refund it instead.');
            }
            if ((string) $tx['created_at'] > (string) $invoice->paid_at) {
                throw new InformationException('A payment was recorded on this invoice after it was paid, so its payment cannot be reversed. Refund it instead.');
            }
            // Mark as Paid's record (post[invoice_id], Service::markAsPaidByAdmin()) adds nothing to the balance; a staff
            // transaction processed by the Custom adapter credited its amount first.
            // shortcut: an API transaction sent with post[invoice_id] counts as Mark as Paid's; read the balance row if that matters.
            $staff[] = ['id' => (int) $tx['id'], 'amount' => round((float) $tx['amount'], 2), 'note' => (string) $tx['note'], 'funded' => !isset($ipn['post']['invoice_id'])];
        }
        if ($db->fetchOne('SELECT 1 FROM invoice_item WHERE invoice_id = ? AND type = ? AND status = ?', [$id, \Model_InvoiceItem::TYPE_HOOK_CALL, \Model_InvoiceItem::STATUS_EXECUTED]) !== false) {
            throw new InformationException('This payment changed a service (an upgrade), which cannot be undone here. Refund the invoice instead.');
        }
        // Paying it again would renew the domain at the registrar a second time (bought twice for one payment).
        if ($db->fetchOne(
            "SELECT 1 FROM invoice_item ii JOIN client_order o ON o.id = ii.rel_id WHERE ii.invoice_id = ? AND ii.type = 'order' AND ii.task = ? AND ii.status = ? AND o.service_type = 'domain'",
            [$id, \Model_InvoiceItem::TASK_RENEW, \Model_InvoiceItem::STATUS_EXECUTED],
        ) !== false) {
            throw new InformationException('This payment renewed a domain at its registrar, which cannot be undone here. Refund the invoice instead.');
        }
        // A downgrade after it gave credit out of what this payment paid for (as Service::refundPartLocked()).
        if ($db->fetchOne(
            "SELECT 1 FROM invoice cn JOIN invoice_item ci ON ci.invoice_id = cn.id JOIN invoice_item ii ON ii.rel_id = ci.rel_id AND ii.type = 'order'
             WHERE ii.invoice_id = ? AND ci.task = ? AND cn.created_at >= ? LIMIT 1",
            [$id, Service::CREDIT_NOTE_TASK, (string) ($invoice->paid_at ?: $invoice->created_at)],
        ) !== false) {
            throw new InformationException('A downgrade gave account credit for this service. Refund this invoice by hand.');
        }
        $rows = $db->fetchAllAssociative("SELECT id, invoice_item_id, amount FROM client_balance WHERE type = 'invoice' AND rel_id = ?", [(string) $id]);
        $amounts = array_map('floatval', array_column($rows, 'amount'));
        $give = round(-array_sum(array_filter($amounts, fn (float $a): bool => $a < 0)), 2);
        $take = round(array_sum(array_filter($amounts, fn (float $a): bool => $a > 0)) + array_sum(array_map(fn (array $tx): float => $tx['funded'] ? $tx['amount'] : 0.0, $staff)), 2);
        // The invoice holds no credit afterwards (its applied credit is freed), so the free credit changes by give - take.
        if ($take > $give && round($service->getFreeCredit((int) $invoice->client_id) + $give - $take, 2) < 0) {
            throw new InformationException('The client has spent the credit this payment added, so it cannot be reversed. Refund this invoice by hand.');
        }

        return ['total' => round($service->getTotalWithTax($invoice), 2), 'staff' => $staff, 'rows' => $rows, 'give' => $give, 'take' => $take];
    }

    /**
     * ClientFence (CF-177): the payments staff recorded on an unpaid invoice that Reverse takes back one by one (WHMCS
     * "Manual refund processed externally"): processed, made by staff (Mark as Paid with an amount, or the transactions
     * page) and credited to the client's balance, where they wait for the invoice. [id => {id, amount, note, funded}]
     */
    public static function partPayments(\Pimple\Container $di, int $invoiceId): array
    {
        $payments = [];
        foreach ($di['dbal']->fetchAllAssociative("SELECT t.id, t.amount, t.note, t.ipn FROM `transaction` t JOIN invoice i ON i.id = t.invoice_id
            WHERE t.invoice_id = ? AND t.status = 'processed' AND i.status = ? ORDER BY t.id", [$invoiceId, \Model_Invoice::STATUS_UNPAID]) as $tx) {
            $ipn = json_decode((string) $tx['ipn'], true);
            // Mark as Paid's full record (post[invoice_id]) added nothing to the balance, so there is nothing to take back.
            if (($ipn['source'] ?? null) === 'admin' && !isset($ipn['post']['invoice_id'])) {
                $payments[(int) $tx['id']] = ['id' => (int) $tx['id'], 'amount' => round((float) $tx['amount'], 2), 'note' => (string) $tx['note'], 'funded' => true];
            }
        }

        return $payments;
    }

    /** ClientFence (CF-177): takes back one payment of partPayments(); the invoice stays unpaid and the gateway is never called. */
    public static function reversePartPayment(\Pimple\Container $di, \Model_Invoice $invoice, int $transactionId): bool
    {
        $db = $di['dbal'];
        $service = $di['mod_service']('Invoice');
        $clientId = (int) $invoice->client_id;
        $id = (int) $invoice->id;
        [$tx, $paid, $due] = Tools::withLock($di, 'fb:credit_payment:client:' . $clientId, function () use ($di, $db, $service, $invoice, $clientId, $id, $transactionId): array {
            $current = $db->fetchAssociative('SELECT * FROM invoice WHERE id = ?', [$id]) ?: throw new InformationException('Invoice not found');
            foreach ($current as $column => $value) {
                $invoice->{$column} = $value;
            }
            $tx = self::partPayments($di, $id)[$transactionId] ?? throw new InformationException('Only a payment staff recorded on an unpaid invoice can be reversed here, and only once.');
            $paid = $service->getAmountPaid($invoice);
            $due = $service->getBalanceDue($invoice);
            // What stays set aside afterwards (this invoice's other payments, other unpaid invoices) must still be on the balance.
            $kept = $service->isInvoiceTypeDeposit($invoice) ? 0.0 : min(max(0.0, round($paid - $tx['amount'], 2)), round($service->getTotalWithTax($invoice), 2));
            $balance = (float) $db->fetchOne('SELECT COALESCE(SUM(amount), 0) FROM client_balance WHERE client_id = ?', [$clientId]);
            if (round($balance - $tx['amount'] - $service->getReservedCredit($clientId, $id) - $kept, 2) < 0) {
                throw new InformationException('The client has spent the credit this payment added, or other invoices have it set aside, so it cannot be reversed.');
            }
            $db->transactional(function () use ($di, $clientId, $tx, $id): void {
                self::takeBack($di, $clientId, $tx, date('Y-m-d H:i:s'));
                Sync::paymentReversed($di, $id, [$tx['id']]);
            });

            return [$tx, $paid, $due];
        });
        // As after a gateway part payment (CF-171): the fee follows what is due now.
        if ($invoice->gateway_id && PendingPayments::forInvoice($di, $id) === null) {
            GatewayFees::apply($di, $invoice, (int) $invoice->gateway_id);
        }
        $di['logger']->info('Invoice #%s: payment transaction #%s of %s %s reversed by staff (amount paid was %s, balance due was %s).', $id, $tx['id'], number_format($tx['amount'], 2, '.', ''), $invoice->currency, number_format($paid, 2, '.', ''), number_format($due, 2, '.', ''));

        return true;
    }

    /** Marks a staff transaction reversed (kept as a record) and takes back from the balance what it credited there. */
    private static function takeBack(\Pimple\Container $di, int $clientId, array $tx, string $now): void
    {
        $db = $di['dbal'];
        if ($tx['funded']) {
            $db->insert('client_balance', ['client_id' => $clientId, 'type' => 'transaction', 'rel_id' => (string) $tx['id'], 'description' => sprintf('Payment reversed: transaction #%d', $tx['id']), 'amount' => -$tx['amount'], 'created_at' => $now, 'updated_at' => $now]);
        }
        $db->update($db->quoteSingleIdentifier('transaction'), ['status' => \Model_Transaction::STATUS_REVERSED, 'note' => trim($tx['note'] . "\nReversed by staff on " . $now . '.'), 'updated_at' => $now], ['id' => $tx['id']]);
    }

    /** A Custom payment method (CustomEgp extends it): staff record its payments, no gateway takes money. */
    private static function isCustom(\Pimple\Container $di, int $gatewayId): bool
    {
        $gateway = $di['db']->load('PayGateway', $gatewayId);
        $code = $gateway instanceof \Model_PayGateway ? (string) $gateway->gateway : '';

        return $code === 'Custom' || (preg_match('/^[A-Za-z0-9_]+$/', $code) === 1 && is_subclass_of($di['mod_service']('Invoice', 'PayGateway')->getAdapterClassName($gateway), \Payment_Adapter_Custom::class));
    }
}
