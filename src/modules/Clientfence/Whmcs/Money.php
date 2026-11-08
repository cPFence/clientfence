<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Whmcs;

use Doctrine\DBAL\Connection;

/**
 * ClientFence: the WHMCS import's money steps (Run.php runs them in batches, after the service
 * steps): invoices with their lines, transactions, the credit log, each client's credit balance and
 * WHMCS 9's credit and debit notes. Rows are written directly, as in Accounts.php; nothing is
 * charged, emailed or sent to a gateway.
 *
 * Invoices keep their WHMCS id (the address clients know) and their number: WHMCS's invoice number
 * when it gave one (paid invoices, with sequential numbering). An unpaid invoice WHMCS had not
 * numbered yet takes the next number, as ClientFence numbers invoices when made; a cancelled one
 * shows its id, as in WHMCS. New invoices continue WHMCS's numbering (owner decision 2026-10-19:
 * WHMCS's prefix, padding and next number).
 *
 * Invoice lines of a service, add-on or domain point at its imported order. An unpaid invoice's
 * lines renew (or set up) their order when it is paid, as WHMCS would, and the order knows the
 * invoice is waiting; a paid invoice's lines are done. What WHMCS deducted (credit applied, payments
 * already received on a partly paid invoice) are lines of their own, so what is due matches WHMCS.
 * Transactions are history: they are marked processed, so ClientFence never applies them again;
 * income counts each invoice's payments less its refunds.
 *
 * Credit: the credit log becomes the client's balance history; where it does not add up to the
 * credit WHMCS holds, an opening balance line makes up the difference. Credit and debit notes (owner
 * decision 2026-10-19): a credit note becomes a ClientFence credit note (a refund invoice with
 * negative lines in the credit note series; a document, outside income, which counts the refund on
 * its invoice), a debit note an invoice.
 */
final class Money
{
    /** The steps in order: name => [label, WHMCS table read]. */
    public const STEPS = [
        'invoices' => ['Invoices', 'tblinvoices'],
        'transactions' => ['Transactions', 'tblaccounts'],
        'credits' => ['Credit log', 'tblcredit'],
        'balances' => ['Client credit balances', 'tblclients'],
        'notes' => ['Credit and debit notes', 'tblbillingnotes'],
        'agreements' => ['PayPal Auto-Pay billing agreements', 'tblpaymethods'],
    ];

    /** WHMCS's PayPal billing agreement gateway (LCWSoft PayPal Billing Agreements, "PayPal Auto-Pay"). */
    public const AGREEMENT_GATEWAY = 'lcwsoftpaypalbillingagreements';

    /**
     * The unit of a line for money received before the invoice came here (a partly paid WHMCS
     * invoice): its negative price keeps the amount due right, and Invoice\Service::countIncome()
     * counts it as income.
     */
    public const RECEIVED_UNIT = 'received';

    /** WHMCS invoice statuses => invoice statuses. */
    public const STATUSES = [
        'paid' => 'paid', 'unpaid' => 'unpaid', 'cancelled' => 'canceled', 'refunded' => 'refunded',
        'collections' => 'unpaid', 'payment pending' => 'unpaid', 'draft' => 'unpaid',
    ];

    /** Lines the import writes that are how an invoice was paid or rounded, not a discount (Order\Upgrades reads them). */
    public const CREDIT_TITLE = 'Credit applied';
    public const ROUNDING_TITLE = 'Rounding to the WHMCS total';

    /** WHMCS promotion line types => the id map entity of the order whose price they lower. */
    public const LINE_PROMOTIONS = ['promohosting' => 'service', 'promodomain' => 'domain'];

    /** WHMCS invoice line types => the id map entity of the order they bill. */
    public const LINE_ORDERS = [
        'hosting' => 'service', 'addon' => 'serviceaddon',
        'domain' => 'domain', 'domainregister' => 'domain', 'domaintransfer' => 'domain', 'domainrenew' => 'domain',
    ];

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<string, mixed>|null this install's company details for the seller fields */
    private ?array $seller = null;

    private ?bool $inclusive = null;

    public function __construct(
        private readonly Source $source,
        private readonly Connection $db,
        private readonly Map $map,
        private readonly string $now,
        // The import's "PayPal billing agreements" setting (Import::DEFAULTS paypal_agreements).
        private readonly bool $agreements = false,
    ) {
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function total(string $step): int
    {
        $table = self::STEPS[$step][1];
        if ($step === 'agreements') {
            return $this->agreements && $this->source->hasTable($table) ? $this->source->count($table, self::agreementRows()) : 0;
        }

        return $this->source->hasTable($table) ? $this->source->count($table) : 0;
    }

    /**
     * Imports up to $limit rows of a step after the WHMCS id $after: the last id read (null: done)
     * and how many rows were imported.
     *
     * @return array{0: ?int, 1: int}
     */
    public function batch(string $step, int $after, int $limit): array
    {
        $table = self::STEPS[$step][1] ?? throw new \InvalidArgumentException('Unknown step ' . $step);
        if (!$this->source->hasTable($table)) {
            return [null, 0];
        }
        if ($step === 'agreements' && (!$this->agreements || $this->gateway(self::AGREEMENT_GATEWAY) === null)) {
            return [null, 0];
        }
        $rows = $this->source->rows('SELECT * FROM ' . $table . ' WHERE id > ?' . ($step === 'agreements' ? ' AND ' . self::agreementRows() : '') . ' ORDER BY id LIMIT ' . $limit, [$after]);
        if ($rows === []) {
            if ($step === 'invoices' && $this->source->count('tblinvoices') > 0) {
                $this->numbering();
            }

            return [null, 0];
        }
        $done = 0;
        foreach ($rows as $row) {
            $done += match ($step) {
                'invoices' => $this->invoice($row),
                'transactions' => $this->transaction($row),
                'credits' => $this->credit($row),
                'balances' => $this->balance($row),
                'notes' => $this->note($row),
                'agreements' => $this->agreement($row),
            };
        }

        return [(int) end($rows)['id'], $done];
    }

    /** WHMCS payment methods that are live billing agreements of AGREEMENT_GATEWAY. */
    private static function agreementRows(): string
    {
        return "gateway_name = '" . self::AGREEMENT_GATEWAY . "' AND deleted_at IS NULL";
    }

    /**
     * A WHMCS billing agreement becomes the client's saved PayPal Auto-Pay method, so renewals go on without the
     * client agreeing again (owner decision 2026-11-08). WHMCS keeps the agreement ID encrypted in the payment method;
     * the gateway's own table (mod_lcwsoftpaypalbillingagreements) has it plain, also for agreements removed since.
     *
     * @param array<string, mixed> $row
     */
    private function agreement(array $row): int
    {
        $id = (int) $row['id'];
        if ($this->map->has('agreement', $id)) {
            return 0;
        }
        $clientId = $this->map->get('client', (int) ($row['userid'] ?? 0));
        $gatewayId = (int) $this->gateway(self::AGREEMENT_GATEWAY);
        if ($clientId === null) {
            $this->warn(sprintf('WHMCS billing agreement of client #%d was not imported: its client was not imported.', (int) ($row['userid'] ?? 0)));

            return 0;
        }
        $ids = $this->source->hasTable('mod_lcwsoftpaypalbillingagreements')
            ? array_values(array_filter(array_map('strval', array_column($this->source->rows('SELECT agreementID FROM mod_lcwsoftpaypalbillingagreements WHERE userid = ?', [(int) $row['userid']]), 'agreementID')), static fn (string $agreement): bool => str_starts_with($agreement, 'B-')))
            : [];
        if ($ids === []) {
            $this->warn(sprintf('WHMCS billing agreement of client #%d was not imported: its ID is not in WHMCS\'s PayPal billing agreements table.', (int) $row['userid']));

            return 0;
        }
        // shortcut: the table has no dates, so with several the last one written is taken; a cancelled one is removed at its first charge.
        $agreement = end($ids);
        if (count($ids) > 1) {
            $this->warn(sprintf('Client #%d had %d PayPal billing agreements in WHMCS: the newest, %s, was kept. Check it on the client\'s payment methods if a charge fails.', (int) $row['userid'], count($ids), $agreement));
        }
        $values = ['external_customer_id' => '', 'saved_cards' => 1, 'default_payment_method' => $agreement, 'default_card' => 'PayPal', 'auto_pay_at' => $this->now, 'updated_at' => $this->now];
        // WHMCS's agreements are live ones, also while the gateway is still in test mode.
        $existing = $this->db->fetchOne('SELECT id FROM pay_gateway_customer WHERE pay_gateway_id = ? AND client_id = ? AND test_mode = 0', [$gatewayId, $clientId]);
        if ($existing === false) {
            $this->db->insert('pay_gateway_customer', $values + ['pay_gateway_id' => $gatewayId, 'client_id' => $clientId, 'test_mode' => 0, 'created_at' => $this->now]);
            $existing = $this->db->lastInsertId();
        } else {
            $this->db->update('pay_gateway_customer', $values, ['id' => (int) $existing]);
        }
        $this->map->put('agreement', $id, (int) $existing);

        return 1;
    }

    /** @param array<string, mixed> $row */
    private function invoice(array $row): int
    {
        $id = (int) $row['id'];
        if ($this->map->has('invoice', $id) || $this->map->has('skipped', 'invoice:' . $id)) {
            return 0;
        }
        $clientId = $this->map->get('client', (int) ($row['userid'] ?? 0));
        $client = $clientId === null ? false : $this->db->fetchAssociative('SELECT * FROM client WHERE id = ?', [$clientId]);
        if ($client === false) {
            $this->map->put('skipped', 'invoice:' . $id, 0);
            $this->warn(sprintf('Invoice #%d was not imported: its client was not imported.', $id));

            return 0;
        }
        $whmcsStatus = strtolower(trim((string) ($row['status'] ?? '')));
        $status = self::STATUSES[$whmcsStatus] ?? null;
        if ($status === null) {
            $status = 'unpaid';
            $this->warn(sprintf('Invoice #%d: WHMCS status "%s" is not known; it was imported as unpaid.', $id, (string) $row['status']));
        } elseif ($whmcsStatus === 'collections' || $whmcsStatus === 'payment pending') {
            $this->warn(sprintf('Invoice #%d was "%s" in WHMCS: it is unpaid.', $id, (string) $row['status']));
        }
        [$serie, $nr] = self::number((string) ($row['invoicenum'] ?? ''));
        if ($nr === null && trim((string) ($row['invoicenum'] ?? '')) !== '') {
            $this->warn(sprintf('Invoice #%d: its WHMCS number "%s" has no digits at the end; it shows as #%d.', $id, (string) $row['invoicenum'], $id));
        }
        $currency = (string) ($client['currency'] ?? '') ?: $this->defaultCurrency();
        $rate = $this->rate($currency);
        // WHMCS's total is its lines less the credit applied to it; ClientFence totals the lines, so
        // the credit becomes a line of its own (the client's balance history already has it spent).
        $applied = max(0.0, round((float) ($row['credit'] ?? 0), 2));
        $subtotal = round((float) ($row['subtotal'] ?? 0) - $applied, 2);
        $paid = in_array($status, ['paid', 'refunded'], true);
        // The money WHMCS recorded against it: payments in (an unpaid invoice may be partly paid)
        // and refunds out (a paid invoice may be partly refunded). Income counts each once.
        $money = $this->source->rows('SELECT COALESCE(SUM(amountin), 0) AS paid_in, COALESCE(SUM(amountout), 0) AS paid_out FROM tblaccounts WHERE invoiceid = ?', [$id])[0] ?? [];
        $paidIn = round((float) ($money['paid_in'] ?? 0) - (float) ($money['paid_out'] ?? 0), 2);
        // Income leaves tax out (as countIncome() does), so a refund counts its share without tax.
        $refunded = round((float) ($money['paid_out'] ?? 0), 2);
        $total = round((float) ($row['total'] ?? 0), 2);
        if ($refunded > 0 && $total > $subtotal && $total > 0) {
            $refunded = round($refunded * $subtotal / $total, 2);
        }
        $refunded = min($refunded, max(0.0, $subtotal));
        if ($status === 'refunded' && $refunded <= 0) {
            $refunded = $subtotal;
        }
        $items = $this->source->rows('SELECT * FROM tblinvoiceitems WHERE invoiceid = ? ORDER BY id', [$id]);
        [$taxRate, $netShare] = $this->tax($row, $items);
        if ((float) ($row['tax2'] ?? 0) > 0) {
            $this->warn(sprintf('Invoice #%d had a second tax (%s): ClientFence keeps one tax rate, so it shows both as %s%%; its total is as WHMCS billed it.', $id, (string) $row['tax2'], (string) $taxRate));
        }
        $this->db->insert('invoice', $this->seller() + $this->buyer($client) + [
            'id' => $id,
            'client_id' => $clientId,
            'serie' => $serie,
            'nr' => $nr,
            'hash' => bin2hex(random_bytes(20)),
            'currency' => $currency,
            'currency_rate' => $rate,
            'credit' => null,
            'base_income' => $paid ? round($subtotal / ($rate ?: 1), 2) : null,
            'base_refund' => $paid && $refunded > 0 ? round($refunded / ($rate ?: 1), 2) : null,
            'refund' => $paid && $refunded > 0 ? $refunded : null,
            'notes' => trim((string) ($row['notes'] ?? '')) ?: null,
            'status' => $status,
            'gateway_id' => $this->gateway((string) ($row['paymentmethod'] ?? '')),
            'approved' => $whmcsStatus === 'draft' ? 0 : 1,
            'taxname' => $taxRate > 0 ? 'Tax' : null,
            'taxrate' => $taxRate > 0 ? (string) $taxRate : null,
            'due_at' => Accounts::date($row['duedate'] ?? null),
            'paid_at' => $paid ? Accounts::date($row['datepaid'] ?? null) ?? Accounts::date($row['date'] ?? null) : null,
            'created_at' => Accounts::date($row['date'] ?? null) ?? $this->now,
            'updated_at' => $this->now,
            'hash_expires_at' => $this->hashExpiry(),
        ]);
        $lines = $taxedLines = 0.0;
        foreach ($items as $item) {
            if ((int) ($item['taxed'] ?? 0) === 1) {
                $item['amount'] = round((float) ($item['amount'] ?? 0) * $netShare, 2);
                $taxedLines += $taxRate > 0 ? $item['amount'] : 0.0;
            }
            $this->line($id, $item, $status, $taxRate > 0);
            $lines += round((float) ($item['amount'] ?? 0), 2);
        }
        if ($applied > 0) {
            $this->line($id, ['type' => 'credit', 'description' => self::CREDIT_TITLE, 'amount' => -$applied, 'taxed' => 0], $status, false);
            $lines -= $applied;
        }
        // The total as Invoice\Service::getTotalWithTax() makes it (tax rounded once on the taxed lines); a last line
        // keeps WHMCS's total where its rounding differs.
        $difference = round($total - ($lines + round($taxedLines * $taxRate / 100, 2)), 2);
        if (abs($difference) >= 0.01) {
            $this->line($id, ['type' => 'adjustment', 'description' => self::ROUNDING_TITLE, 'amount' => $difference, 'taxed' => 0], $status, false);
            if (abs($difference) > 0.05) {
                $this->warn(sprintf('Invoice #%d: its WHMCS total (%s) differs from its lines and tax by %s; a line of that amount keeps the WHMCS total.', $id, number_format($total, 2, '.', ''), number_format($difference, 2, '.', '')));
            }
        }
        // A partly paid invoice: what was paid is a line of its own, so only the rest is due.
        if ($status === 'unpaid' && $paidIn > 0.005) {
            $this->line($id, ['type' => 'payment', 'description' => 'Payments received', 'amount' => -$paidIn, 'taxed' => 0], $status, false, self::RECEIVED_UNIT);
            $this->warn(sprintf('Invoice #%d was partly paid in WHMCS (%s %s): the rest is due.', $id, number_format($paidIn, 2, '.', ''), $currency));
        }
        $this->map->put('invoice', $id, $id);

        return 1;
    }

    /**
     * An invoice line. A line of a service, add-on or domain bills its order: on an unpaid
     * invoice it renews (or sets up) the order when paid and the order waits for the invoice.
     *
     * @param array<string, mixed> $item
     */
    private function line(int $invoiceId, array $item, string $status, bool $taxedInvoice, ?string $unit = null): void
    {
        $kind = strtolower(trim((string) ($item['type'] ?? '')));
        // A promotion names the order it lowers, as the cart's own discount lines do (Order\Upgrades counts it there).
        $promoted = isset(self::LINE_PROMOTIONS[$kind]) ? $this->map->get(self::LINE_PROMOTIONS[$kind], (int) ($item['relid'] ?? 0)) : null;
        if ($promoted !== null && $this->db->fetchOne('SELECT 1 FROM client_order WHERE id = ?', [$promoted]) === false) {
            $promoted = null;
        }
        $entity = self::LINE_ORDERS[$kind] ?? null;
        $orderId = $entity === null ? null : $this->map->get($entity, (int) ($item['relid'] ?? 0));
        $order = $orderId === null ? false : $this->db->fetchAssociative('SELECT id, status, period FROM client_order WHERE id = ?', [$orderId]);
        $type = $order !== false ? 'order' : ($kind === 'addfunds' ? 'deposit' : 'custom');
        $paid = in_array($status, ['paid', 'refunded'], true);
        $task = null;
        if ($type === 'order') {
            $task = in_array($order['status'], ['pending_setup', 'failed_setup'], true) ? 'activate' : 'renew';
        }
        $this->db->insert('invoice_item', [
            'invoice_id' => $invoiceId,
            'type' => $type,
            'rel_id' => $order !== false ? (string) $order['id'] : ($promoted !== null ? (string) $promoted : null),
            'task' => $task,
            'status' => $paid ? 'executed' : 'pending_payment',
            'title' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($item['description'] ?? '')) ?? '') ?: 'Invoice line', 0, 255),
            'period' => $order !== false ? $order['period'] : null,
            'quantity' => 1,
            'unit' => $unit ?? ($order !== false ? 'product' : ($promoted !== null ? 'discount' : null)),
            'price' => round((float) ($item['amount'] ?? 0), 2),
            'charged' => $paid ? 1 : 0,
            'taxed' => $taxedInvoice && (int) ($item['taxed'] ?? 0) === 1 ? 1 : 0,
            'attempts' => 0,
            'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
        // The order waits for its unpaid invoice instead of getting another renewal invoice.
        if ($order !== false && $status === 'unpaid' && $order['status'] !== 'canceled') {
            $this->db->update('client_order', ['unpaid_invoice_id' => $invoiceId], ['id' => $order['id']]);
        }
    }

    /** @param array<string, mixed> $row */
    private function transaction(array $row): int
    {
        $id = (int) $row['id'];
        if ($this->map->has('transaction', $id)) {
            return 0;
        }
        $invoiceId = (int) ($row['invoiceid'] ?? 0);
        $invoice = $invoiceId > 0 && $this->map->has('invoice', $invoiceId) ? $invoiceId : null;
        $in = round((float) ($row['amountin'] ?? 0), 2);
        $out = round((float) ($row['amountout'] ?? 0), 2);
        $refund = $out > 0 && $in <= 0;
        $note = trim(implode("\n", array_filter([
            trim((string) ($row['description'] ?? '')),
            (float) ($row['fees'] ?? 0) != 0.0 ? sprintf('Fees: %s', number_format((float) $row['fees'], 2, '.', '')) : '',
            trim((string) ($row['gateway'] ?? '')) !== '' ? sprintf('WHMCS gateway: %s', trim((string) $row['gateway'])) : '',
            $invoiceId > 0 && $invoice === null ? sprintf('WHMCS invoice #%d (not imported)', $invoiceId) : '',
        ])));
        // "transaction" is a keyword in some databases: the table name is quoted.
        $this->db->insert($this->db->quoteSingleIdentifier('transaction'), [
            'invoice_id' => $invoice,
            'gateway_id' => $this->gateway((string) ($row['gateway'] ?? '')),
            'txn_id' => mb_substr(trim((string) ($row['transid'] ?? '')), 0, 255) ?: null,
            'txn_status' => 'imported',
            'amount' => number_format($refund ? $out : $in - $out, 2, '.', ''),
            'currency' => $this->transactionCurrency($row, $invoice),
            'type' => $refund ? 'refund' : 'payment',
            // History: never applied to an invoice or a balance again.
            'status' => 'processed',
            'validate_ipn' => 0,
            'note' => $note === '' ? null : $note,
            'created_at' => Accounts::date($row['date'] ?? null) ?? $this->now,
            'updated_at' => $this->now,
        ]);
        $this->map->put('transaction', $id, (int) $this->db->lastInsertId());

        return 1;
    }

    /** @param array<string, mixed> $row */
    private function credit(array $row): int
    {
        $id = (int) $row['id'];
        if ($this->map->has('credit', $id) || $this->map->has('skipped', 'credit:' . $id)) {
            return 0;
        }
        $clientId = $this->map->get('client', (int) ($row['clientid'] ?? 0));
        if ($clientId === null) {
            $this->map->put('skipped', 'credit:' . $id, 0);

            return 0;
        }
        $invoiceId = (int) ($row['relid'] ?? 0);
        $this->db->insert('client_balance', [
            'client_id' => $clientId,
            'type' => $invoiceId > 0 ? 'invoice' : 'default',
            'rel_id' => $invoiceId > 0 ? (string) $invoiceId : null,
            'amount' => number_format(round((float) ($row['amount'] ?? 0), 2), 2, '.', ''),
            'description' => trim((string) ($row['description'] ?? '')) ?: 'Credit (WHMCS)',
            'created_at' => Accounts::date($row['date'] ?? null) ?? $this->now,
            'updated_at' => $this->now,
        ]);
        $this->map->put('credit', $id, (int) $this->db->lastInsertId());

        return 1;
    }

    /**
     * A client's credit: where the imported credit log does not add up to what WHMCS holds, an
     * opening balance line makes up the difference.
     *
     * @param array<string, mixed> $row a tblclients row
     */
    private function balance(array $row): int
    {
        $whmcsId = (int) $row['id'];
        $clientId = $this->map->get('client', $whmcsId);
        if ($clientId === null || $this->map->has('balance', $whmcsId)) {
            return 0;
        }
        $credit = round((float) ($row['credit'] ?? 0), 2);
        $sum = round((float) $this->db->fetchOne('SELECT COALESCE(SUM(amount), 0) FROM client_balance WHERE client_id = ?', [$clientId]), 2);
        $balanceId = 0;
        if (abs($credit - $sum) >= 0.005) {
            $this->db->insert('client_balance', [
                'client_id' => $clientId, 'type' => 'default', 'rel_id' => null,
                'amount' => number_format($credit - $sum, 2, '.', ''),
                'description' => 'Opening balance from WHMCS',
                'created_at' => $this->now, 'updated_at' => $this->now,
            ]);
            $balanceId = (int) $this->db->lastInsertId();
        }
        $this->map->put('balance', $whmcsId, $balanceId);

        return 1;
    }

    /**
     * A WHMCS 9 credit or debit note: a credit note becomes a credit note (a refund invoice with
     * negative lines in the credit note series), a debit note an invoice. Drafts are left out.
     *
     * @param array<string, mixed> $row
     */
    private function note(array $row): int
    {
        $id = (int) $row['id'];
        if ($this->map->has('note', $id) || $this->map->has('skipped', 'note:' . $id)) {
            return 0;
        }
        $kind = strtolower(trim((string) ($row['note_type'] ?? 'credit')));
        $clientId = $this->map->get('client', (int) ($row['client_id'] ?? 0));
        $client = $clientId === null ? false : $this->db->fetchAssociative('SELECT * FROM client WHERE id = ?', [$clientId]);
        $noteStatus = strtolower(trim((string) ($row['status'] ?? '')));
        if ($client === false || $noteStatus === 'draft') {
            $this->map->put('skipped', 'note:' . $id, 0);
            $this->warn(sprintf('%s note #%d was not imported: %s.', ucfirst($kind), $id, $client === false ? 'its client was not imported' : 'it is a draft'));

            return 0;
        }
        $credit = $kind !== 'debit';
        $number = trim((string) ($row['custom_number'] ?? ''));
        [$serie, $nr] = self::number($number);
        if ($credit) {
            $serie = $this->setting('invoice_cn_series', 'CN-') . $serie;
            $nr ??= (string) $id;
        } elseif ($nr === null) {
            // A debit note without its own number takes the next invoice number.
            [$serie, $nr] = [$this->setting('invoice_series'), (string) $this->nextNumber()];
        }
        $currency = (string) ($client['currency'] ?? '') ?: $this->defaultCurrency();
        $rate = $this->rate($currency);
        $total = round((float) ($row['subtotal'] ?? 0), 2);
        $date = Accounts::date($row['date_issued'] ?? null) ?? Accounts::date($row['created_at'] ?? null) ?? $this->now;
        $status = $credit ? 'refunded' : ($noteStatus === 'closed' ? 'paid' : 'unpaid');
        $items = $this->source->hasTable('tblbillingnoteitems')
            ? $this->source->rows('SELECT * FROM tblbillingnoteitems WHERE billingnote_id = ? ORDER BY id', [$id])
            : [];
        [$taxRate, $netShare] = $this->tax($row, $items);
        $this->db->insert('invoice', $this->seller() + $this->buyer($client) + [
            'client_id' => $clientId,
            'serie' => $serie,
            'nr' => $nr,
            'hash' => bin2hex(random_bytes(20)),
            'currency' => $currency,
            'currency_rate' => $rate,
            'credit' => null,
            // A credit note is a document only: the money it returned is a refund on its invoice (or
            // credit on the balance), which income already counts.
            'base_income' => !$credit && $status === 'paid' ? round($total / ($rate ?: 1), 2) : null,
            'base_refund' => null,
            'refund' => null,
            'notes' => trim(sprintf('%s note %s from WHMCS. %s', ucfirst($kind), $number !== '' ? $number : '#' . $id, trim((string) ($row['notes'] ?? '')))),
            'status' => $status,
            'gateway_id' => null,
            'approved' => 1,
            'taxname' => $taxRate > 0 ? 'Tax' : null,
            'taxrate' => $taxRate > 0 ? (string) $taxRate : null,
            'due_at' => $date,
            'paid_at' => $status === 'unpaid' ? null : $date,
            'created_at' => $date,
            'updated_at' => $this->now,
            'hash_expires_at' => $this->hashExpiry(),
        ]);
        $invoiceId = (int) $this->db->lastInsertId();
        foreach ($items as $item) {
            $amount = round((float) ($item['amount'] ?? 0) * ((int) ($item['taxed'] ?? 0) === 1 ? $netShare : 1.0), 2);
            $this->db->insert('invoice_item', [
                'invoice_id' => $invoiceId, 'type' => 'custom', 'rel_id' => null, 'task' => null,
                'status' => $status === 'unpaid' ? 'pending_payment' : 'executed',
                'title' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($item['description'] ?? '')) ?? '') ?: ucfirst($kind) . ' note', 0, 255),
                'period' => null, 'quantity' => 1, 'unit' => null,
                // A credit note's lines are negative, as ClientFence's own credit notes.
                'price' => $credit ? -abs($amount) : $amount,
                'charged' => $status === 'unpaid' ? 0 : 1,
                'taxed' => $taxRate > 0 && (int) ($item['taxed'] ?? 0) === 1 ? 1 : 0,
                'attempts' => 0,
                'created_at' => $date, 'updated_at' => $this->now,
            ]);
        }
        if ($credit && is_numeric($nr)) {
            $this->raiseSetting('invoice_cn_starting_number', (int) $nr + 1);
        }
        if (!$credit) {
            $this->warn(sprintf('Debit note %s is invoice #%d: check it against WHMCS.', $number !== '' ? $number : '#' . $id, $invoiceId));
        }
        $this->map->put('note', $id, $invoiceId);

        return 1;
    }

    /**
     * After the invoices: new invoices continue WHMCS's numbering (owner decision 2026-10-19),
     * from WHMCS's next number or after the highest imported one, with the prefix of WHMCS's number
     * format ("INV-{NUMBER}": "INV-") or none. Without sequential numbering WHMCS showed invoice
     * ids, so new numbers start above the highest id.
     */
    private function numbering(): void
    {
        if ($this->map->has('skipped', 'notice:numbering')) {
            return;
        }
        $this->map->put('skipped', 'notice:numbering', 0);
        $setting = fn (string $name): string => trim((string) $this->source->value('SELECT value FROM tblconfiguration WHERE setting = ?', [$name]));
        $sequential = in_array(strtolower($setting('SequentialInvoiceNumbering')), ['on', '1', 'yes'], true);
        $format = $setting('SequentialInvoiceNumberFormat') ?: '{NUMBER}';
        $prefix = '';
        if (preg_match('/^([^{}]*)\{NUMBER\}$/', $format, $m) === 1) {
            $prefix = mb_substr($m[1], 0, 50);
        } else {
            $this->warn(sprintf('WHMCS numbered invoices as "%s": new invoices are numbered with a plain number; set a series under Settings > Invoices if needed.', $format));
        }
        $next = $sequential ? (int) $setting('SequentialInvoiceNumberValue') : 0;
        // Numbers show as WHMCS showed them: unpadded, or as long as its zero-padded ones ("0102").
        $padding = 1;
        foreach ($this->db->fetchFirstColumn('SELECT nr FROM invoice WHERE nr IS NOT NULL') as $nr) {
            if (is_numeric($nr) && str_starts_with((string) $nr, '0')) {
                $padding = max($padding, strlen((string) $nr));
            }
        }
        foreach ($this->db->fetchFirstColumn('SELECT nr FROM invoice WHERE serie = ? AND nr IS NOT NULL', [$prefix]) as $nr) {
            if (is_numeric($nr)) {
                $next = max($next, (int) $nr + 1);
            }
        }
        if (!$sequential) {
            $next = max($next, (int) $this->db->fetchOne('SELECT COALESCE(MAX(id), 0) FROM invoice') + 1);
        }
        $next = max(1, $next);
        // An unpaid invoice WHMCS had not numbered yet (it numbers them when paid) takes the next
        // numbers now, as ClientFence numbers invoices when they are made: none shares a number.
        $unnumbered = 0;
        $imported = array_values($this->map->all('invoice'));
        sort($imported);
        foreach ($imported as $invoiceId) {
            if ($this->db->fetchOne("SELECT id FROM invoice WHERE id = ? AND nr IS NULL AND status = 'unpaid'", [$invoiceId]) !== false) {
                $this->db->update('invoice', ['serie' => $prefix, 'nr' => (string) $next], ['id' => $invoiceId]);
                ++$next;
                ++$unnumbered;
            }
        }
        if ($unnumbered > 0) {
            $this->warn(sprintf('%d unpaid invoice(s) had no WHMCS number yet: they took the next numbers.', $unnumbered));
        }
        $this->writeSetting('invoice_series', $prefix);
        $this->writeSetting('invoice_series_paid', $prefix);
        $this->writeSetting('invoice_starting_number', (string) $next);
        $this->writeSetting('invoice_number_padding', (string) $padding);
        $this->warn(sprintf('New invoices continue WHMCS\'s numbering at %s%d (Settings > Invoices).', $prefix, $next));
    }

    /**
     * A WHMCS invoice number as [series, number]: a number as it is, "INV-2026-0012" as series
     * "INV-2026-" and number "0012"; [no series, null] when it has no number.
     *
     * @return array{0: string, 1: ?string}
     */
    public static function number(string $number): array
    {
        $number = trim($number);
        if ($number === '') {
            return ['', null];
        }
        if (preg_match('/^(.*?)(\d+)$/', $number, $m) !== 1) {
            return ['', null];
        }

        return [mb_substr($m[1], 0, 50), $m[2]];
    }

    /**
     * The seller fields of an invoice: this install's company details.
     *
     * @return array<string, mixed>
     */
    private function seller(): array
    {
        if ($this->seller === null) {
            $s = fn (string $param): ?string => $this->setting($param) ?: null;
            $this->seller = [
                'seller_company' => $s('company_name'),
                'seller_company_vat' => $s('company_vat_number'),
                'seller_company_number' => $s('company_number'),
                'seller_address' => trim(implode(' ', array_filter([$s('company_address_1'), $s('company_address_2'), $s('company_address_3')]))) ?: null,
                'seller_phone' => $s('company_tel'),
                'seller_email' => $s('company_email'),
            ];
        }

        return $this->seller;
    }

    /**
     * The buyer fields of an invoice: the client's details.
     *
     * @param array<string, mixed> $client
     *
     * @return array<string, mixed>
     */
    private function buyer(array $client): array
    {
        return [
            'buyer_first_name' => $client['first_name'] ?? null,
            'buyer_last_name' => $client['last_name'] ?? null,
            'buyer_company' => $client['company'] ?? null,
            'buyer_company_vat' => $client['company_vat'] ?? null,
            'buyer_company_number' => $client['company_number'] ?? null,
            'buyer_address' => trim(($client['address_1'] ?? '') . ' ' . ($client['address_2'] ?? '')) ?: null,
            'buyer_city' => $client['city'] ?? null,
            'buyer_state' => $client['state'] ?? null,
            'buyer_country' => $client['country'] ?? null,
            'buyer_zip' => $client['postcode'] ?? null,
            'buyer_phone' => trim(($client['phone_cc'] ?? '') . ' ' . ($client['phone'] ?? '')) ?: null,
            'buyer_email' => $client['email'] ?? null,
        ];
    }

    /**
     * This install's payment gateway for a WHMCS gateway module: PayPal's, or one of the same
     * name; none (with a warning, once) for the others.
     */
    private function gateway(string $module): ?int
    {
        $module = strtolower(trim($module));
        if ($module === '') {
            return null;
        }
        $known = $this->map->get('gateway', $module);
        if ($known !== null) {
            return $known === 0 ? null : $known;
        }
        $names = match ($module) {
            'paypal', 'paypalcheckout' => ['paypalemail', 'paypal'],
            self::AGREEMENT_GATEWAY => ['paypalautopay'],
            default => [$module],
        };
        $id = false;
        foreach ($names as $name) {
            $id = $this->db->fetchOne('SELECT id FROM pay_gateway WHERE LOWER(gateway) = ? OR LOWER(name) = ? ORDER BY id', [$name, $name]);
            if ($id !== false) {
                break;
            }
        }
        $this->map->put('gateway', $module, $id === false ? 0 : (int) $id);
        if ($id === false) {
            $this->warn(sprintf('Payment gateway "%s": this install has no such gateway; its invoices and transactions show none (the transactions name it in their note).', $module));
        }

        return $id === false ? null : (int) $id;
    }

    /**
     * A transaction's currency: its own WHMCS currency, else its invoice's or client's.
     *
     * @param array<string, mixed> $row
     */
    private function transactionCurrency(array $row, ?int $invoiceId): string
    {
        $currencyId = (int) ($row['currency'] ?? 0);
        if ($currencyId > 0) {
            $code = $this->source->value('SELECT code FROM tblcurrencies WHERE id = ?', [$currencyId]);
            if (is_string($code) && $code !== '') {
                return strtoupper(trim($code));
            }
        }
        if ($invoiceId !== null) {
            return (string) $this->db->fetchOne('SELECT currency FROM invoice WHERE id = ?', [$invoiceId]);
        }
        $clientId = $this->map->get('client', (int) ($row['userid'] ?? 0));
        $code = $clientId === null ? false : $this->db->fetchOne('SELECT currency FROM client WHERE id = ?', [$clientId]);

        return is_string($code) && $code !== '' ? $code : $this->defaultCurrency();
    }

    private function rate(string $code): float
    {
        return (float) ($this->db->fetchOne('SELECT conversion_rate FROM currency WHERE code = ?', [$code]) ?: 1.0);
    }

    private function defaultCurrency(): string
    {
        return (string) $this->db->fetchOne('SELECT code FROM currency WHERE is_default = 1 ORDER BY id');
    }

    /** When an invoice's public link stops working, as for a new invoice (Invoice\Service). */
    private function hashExpiry(): ?string
    {
        $days = (int) $this->setting('invoice_hash_lifetime_days', '90');

        return $days <= 0 ? null : date('Y-m-d H:i:s', strtotime($this->now . ' +' . $days . ' days') ?: time());
    }

    /**
     * The one tax rate ClientFence keeps for a WHMCS invoice or note, and the share of a taxed line that is net. WHMCS's
     * tax may include a second (compound) tax, or be inside the line amounts (TaxType Inclusive): the rate is then the
     * one that gives WHMCS's tax on the net lines.
     *
     * @param array<string, mixed>             $row
     * @param list<array<string, mixed>>       $items
     *
     * @return array{0: float, 1: float}
     */
    private function tax(array $row, array $items): array
    {
        $rate = (float) ($row['taxrate'] ?? 0);
        $tax = round(abs((float) ($row['tax'] ?? 0)) + abs((float) ($row['tax2'] ?? 0)), 2);
        $gross = round(abs(array_sum(array_map(fn (array $item): float => (int) ($item['taxed'] ?? 0) === 1 ? (float) ($item['amount'] ?? 0) : 0.0, $items))), 2);
        $this->inclusive ??= strtolower(trim((string) $this->source->value("SELECT value FROM tblconfiguration WHERE setting = 'TaxType'"))) === 'inclusive';
        if ($tax <= 0 || $gross <= 0) {
            return [$rate, 1.0];
        }
        if ((float) ($row['tax2'] ?? 0) == 0) {
            // One tax: its own rate; inside the prices, the net part of a line is 100 / (100 + rate).
            return [$rate, $this->inclusive && $rate > 0 ? 100 / (100 + $rate) : 1.0];
        }
        $net = $this->inclusive ? $gross - $tax : $gross;

        return $net > 0 ? [round($tax / $net * 100, 4), $net / $gross] : [$rate, 1.0];
    }

    private function setting(string $param, string $default = ''): string
    {
        $value = $this->db->fetchOne('SELECT value FROM setting WHERE param = ?', [$param]);

        return $value === false || $value === null ? $default : (string) $value;
    }

    private function writeSetting(string $param, string $value): void
    {
        if ($this->db->update('setting', ['value' => $value, 'updated_at' => $this->now], ['param' => $param]) === 0
            && $this->db->fetchOne('SELECT 1 FROM setting WHERE param = ?', [$param]) === false) {
            $this->db->insert('setting', ['param' => $param, 'value' => $value, 'public' => 0, 'created_at' => $this->now, 'updated_at' => $this->now]);
        }
    }

    /** Takes the next invoice number (invoice_starting_number) and moves the counter on. */
    private function nextNumber(): int
    {
        $next = max(1, (int) $this->setting('invoice_starting_number', '1'));
        $this->writeSetting('invoice_starting_number', (string) ($next + 1));

        return $next;
    }

    private function raiseSetting(string $param, int $atLeast): void
    {
        if ((int) $this->setting($param, '0') < $atLeast) {
            $this->writeSetting($param, (string) $atLeast);
        }
    }

    private function warn(string $message): void
    {
        $this->warnings[] = $message;
    }
}
