<?php

declare(strict_types=1);

/**
 * ClientFence (CF-182): Mass Pay, as WHMCS. On the invoice list the client ticks unpaid invoices (or Pay all) and pays one
 * grouped invoice, with any payment method, that lists each as a line "Invoice #N" for what it owes at that moment.
 *
 * The grouped invoice is an Add funds invoice whose deposit lines carry task "mass_pay:<invoice id>" (no rel_id: order code
 * matches invoice lines to orders by it), so every gateway, bank transfer receipt and Mark as paid takes it as one: its
 * payment goes to the client's balance. settle() then applies each line to its invoice and pays the invoice from that
 * credit, once (a flag written under the client's credit lock). Money:
 * - the lines carry no tax (the invoices carry theirs) and are not income (Service::countIncome() counts only the grouped
 *   invoice's fee, on it and its credit note): each invoice counts its own when paid, and the accounting sync books the
 *   lines to account credit, as any Add funds line;
 * - one payment, one gateway fee: the grouped invoice carries the fee of the method that pays it (GatewayFees::apply()),
 *   and each invoice is paid from credit without its own fee line, as credit always pays (Service::applyCredit());
 * - an invoice paid, cancelled or changed after grouping gets at most what it still owes, and the rest stays account
 *   credit (settle()); starting to pay a grouped invoice whose invoices changed is refused (check()).
 * An unpaid grouped invoice sets no credit aside (an Add funds invoice), gets no reminders or saved-card charge and is not
 * on the statement; the invoices keep their own. Refund and Reverse payment treat it as Add funds: refused once its credit
 * has paid the invoices.
 */

namespace ClientFence\Mod\Invoice;

use ClientFence\InformationException;
use ClientFence\Tools;

final class MassPay
{
    public const TASK = 'mass_pay:';
    public const SETTLED = 'mass_pay_settled';
    private const LINE = "type = 'deposit' AND task LIKE 'mass_pay:%'";

    /** SQL condition: the invoice in $column is a grouped invoice. */
    public static function sql(string $column): string
    {
        return "EXISTS (SELECT 1 FROM invoice_item mp WHERE mp.invoice_id = {$column} AND mp.type = 'deposit' AND mp.task LIKE 'mass_pay:%')";
    }

    /** Whether these invoice lines (models) are a grouped invoice's: callers that hold them need no query. */
    public static function inItems(iterable $items): bool
    {
        foreach ($items as $item) {
            if ($item->type === \Model_InvoiceItem::TYPE_DEPOSIT && str_starts_with((string) $item->task, self::TASK)) {
                return true;
            }
        }

        return false;
    }

    public static function is(\Pimple\Container $di, int $invoiceId): bool
    {
        return (bool) $di['dbal']->fetchOne('SELECT 1 FROM invoice_item WHERE invoice_id = ? AND ' . self::LINE, [$invoiceId]);
    }

    /** The grouped invoice's lines: [invoice id => amount]. */
    public static function lines(\Pimple\Container $di, int $invoiceId): array
    {
        $lines = [];
        foreach ($di['dbal']->fetchAllAssociative('SELECT task, price, quantity FROM invoice_item WHERE invoice_id = ? AND ' . self::LINE . ' ORDER BY id', [$invoiceId]) ?: [] as $line) {
            $id = (int) substr((string) $line['task'], strlen(self::TASK));
            $lines[$id] = round(($lines[$id] ?? 0.0) + (float) $line['price'] * (float) $line['quantity'], 2);
        }

        return $lines;
    }

    /**
     * The grouped invoice of the client's invoices $ids, or of every one that can be grouped ($all): the client's own
     * unpaid issued invoices in their account currency, not Add funds, without a payment clearing, at least two. A second
     * click gets the unpaid grouped invoice already made for the same invoices and amounts.
     */
    public static function create(\Pimple\Container $di, \Model_Client $client, array $ids, bool $all = false): \Model_Invoice
    {
        $db = $di['dbal'];
        if ($all) {
            $ids = $db->fetchFirstColumn("SELECT id FROM invoice WHERE client_id = ? AND status = 'unpaid' AND approved = 1 ORDER BY id", [(int) $client->id]);
        }
        $lines = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $row = $db->fetchAssociative('SELECT id, serie, nr, status, approved, currency FROM invoice WHERE id = ? AND client_id = ?', [$id, (int) $client->id]);
            if ($row === false) {
                throw new InformationException('Invoice was not found');
            }
            $groupable = $row['status'] === \Model_Invoice::STATUS_UNPAID && (int) $row['approved'] === 1
                && (string) $client->currency !== '' && strtoupper((string) $row['currency']) === strtoupper((string) $client->currency)
                && $db->fetchOne("SELECT 1 FROM invoice_item WHERE invoice_id = ? AND type = 'deposit'", [$id]) === false
                && PendingPayments::forInvoice($di, $id) === null;
            // What it owes now: part payments and applied credit taken off, without its own gateway fee.
            $due = $groupable ? GatewayFees::base($di, $di['db']->getExistingModelById('Invoice', $id, 'Invoice not found')) : 0.0;
            if ($due > 0) {
                $lines[$id] = ['amount' => $due, 'number' => Statement::number($db, $row)];
            } elseif (!$all) {
                throw new InformationException('Invoice :number cannot be paid together with other invoices.', [':number' => Statement::number($db, $row)]);
            }
        }
        if (count($lines) < 2) {
            throw new InformationException('Choose at least two unpaid invoices to pay together.');
        }
        ksort($lines);
        $amounts = array_map(static fn (array $line): float => $line['amount'], $lines);

        return Tools::withLock($di, 'cf:mass-pay:client:' . (int) $client->id, static fn (): \Model_Invoice => $db->transactional(static function () use ($di, $db, $client, $lines, $amounts): \Model_Invoice {
            foreach ($db->fetchFirstColumn("SELECT m.id FROM invoice m WHERE m.client_id = ? AND m.status = 'unpaid' AND " . self::sql('m.id') . ' ORDER BY m.id DESC', [(int) $client->id]) as $groupId) {
                $existing = self::lines($di, (int) $groupId);
                ksort($existing);
                if ($existing === $amounts) {
                    return $di['db']->getExistingModelById('Invoice', (int) $groupId, 'Invoice not found');
                }
            }
            $now = date('Y-m-d H:i:s');
            $db->insert('invoice', ['client_id' => (int) $client->id, 'status' => \Model_Invoice::STATUS_UNPAID, 'currency' => $client->currency, 'approved' => 1, 'created_at' => $now, 'updated_at' => $now]);
            $newId = (int) $db->lastInsertId();
            // Before the bean is loaded: saving it again (setInvoiceDefaults()) writes back every column it was loaded with.
            \ClientFence\Site::stamp($di, 'invoice', $newId);
            $invoice = $di['db']->getExistingModelById('Invoice', $newId, 'Invoice not found');
            // Number, buyer, due date and tax rate as any invoice; the lines are untaxed, so only a taxed fee adds tax.
            $di['mod_service']('Invoice')->setInvoiceDefaults($invoice);
            foreach ($lines as $id => $line) {
                $db->insert('invoice_item', [
                    'invoice_id' => (int) $invoice->id, 'type' => \Model_InvoiceItem::TYPE_DEPOSIT, 'task' => self::TASK . $id,
                    'status' => \Model_InvoiceItem::STATUS_PENDING_PAYMENT, 'title' => __trans('Invoice :number', [':number' => $line['number']]),
                    'quantity' => 1, 'price' => $line['amount'], 'charged' => 1, 'taxed' => 0, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $di['events_manager']->fire(['event' => 'onAfterNewInvoice', 'params' => ['id' => (int) $invoice->id], 'isolated' => true]);

            return $invoice;
        }));
    }

    /** Refuses to start paying a grouped invoice whose invoices were paid or changed since: the client chooses them again. */
    public static function check(\Pimple\Container $di, \Model_Invoice $invoice): void
    {
        if ($invoice->status !== \Model_Invoice::STATUS_UNPAID) {
            return;
        }
        foreach (self::lines($di, (int) $invoice->id) as $id => $amount) {
            $original = $di['db']->load('Invoice', $id);
            if (!$original instanceof \Model_Invoice || !$original->id || $original->status !== \Model_Invoice::STATUS_UNPAID
                || PendingPayments::forInvoice($di, $id) !== null || abs(GatewayFees::base($di, $original) - $amount) >= 0.005) {
                throw new InformationException('An invoice in this payment was paid or changed since. Choose the invoices to pay again on your invoice list.');
            }
        }
    }

    /**
     * Once the grouped invoice is paid and its payment is on the client's balance (Service::markAsPaid() for gateways and
     * creditDepositBalanceIfNeeded() for Mark as paid call this): applies each line to its invoice while that one is still unpaid,
     * at most what it owes without its fee, and pays it from the credit. What is left stays account credit. Runs once.
     */
    public static function settle(\Pimple\Container $di, \Model_Invoice $invoice): void
    {
        $id = (int) $invoice->id;
        if (!self::is($di, $id)) {
            return;
        }
        $db = $di['dbal'];
        $applied = Tools::withLock($di, 'fb:credit_payment:client:' . (int) $invoice->client_id, static fn (): array => $db->transactional(static function () use ($di, $db, $id): array {
            $group = $db->fetchAssociative('SELECT client_id, currency, status FROM invoice WHERE id = ?', [$id]);
            if (($group['status'] ?? null) !== \Model_Invoice::STATUS_PAID) {
                return [];
            }
            if ($db->fetchOne("SELECT 1 FROM extension_meta WHERE extension = 'mod_invoice' AND rel_type = 'invoice' AND rel_id = ? AND meta_key = ?", [(string) $id, self::SETTLED]) !== false) {
                return [];
            }
            $now = date('Y-m-d H:i:s');
            $db->insert('extension_meta', ['client_id' => (int) $group['client_id'], 'extension' => 'mod_invoice', 'rel_type' => 'invoice', 'rel_id' => (string) $id, 'meta_key' => self::SETTLED, 'meta_value' => '1', 'created_at' => $now, 'updated_at' => $now]);
            $applied = [];
            foreach (self::lines($di, $id) as $originalId => $amount) {
                $row = $db->fetchAssociative('SELECT status, currency FROM invoice WHERE id = ? AND client_id = ?', [$originalId, (int) $group['client_id']]);
                // Paid, cancelled, refunded or with a payment clearing since: its amount stays account credit.
                if (($row['status'] ?? null) !== \Model_Invoice::STATUS_UNPAID || $row['currency'] !== $group['currency'] || PendingPayments::forInvoice($di, $originalId) !== null) {
                    continue;
                }
                $original = $di['db']->getExistingModelById('Invoice', $originalId, 'Invoice not found');
                $due = GatewayFees::base($di, $original);
                $credit = round(min($amount, $due), 2);
                if ($credit > 0) {
                    // Set aside for the invoice as applied credit is (Service::getReservedCredit()), so nothing else spends it.
                    $db->insert('invoice_credit', ['invoice_id' => $originalId, 'client_id' => (int) $group['client_id'], 'amount' => $credit, 'created_at' => $now]);
                    $applied[] = ['invoice' => $original, 'credit' => $credit, 'covered' => $credit >= $due];
                }
            }

            return $applied;
        }));

        $service = $di['mod_service']('Invoice');
        $notes = [];
        foreach ($applied as $line) {
            if ($line['covered']) {
                // Without its own fee: the grouped invoice carried the one fee of this payment.
                GatewayFees::remove($di, $line['invoice']);
                $service->tryPayWithCredits($line['invoice']);
            } elseif ($line['invoice']->gateway_id) {
                GatewayFees::apply($di, $line['invoice'], (int) $line['invoice']->gateway_id);
            }
            $notes[] = '#' . $line['invoice']->id . ' (' . number_format($line['credit'], 2, '.', '') . ')';
        }
        if ($notes !== []) {
            $service->addNote($invoice, 'Mass payment applied to invoices ' . implode(', ', $notes) . '.');
        }
    }
}
