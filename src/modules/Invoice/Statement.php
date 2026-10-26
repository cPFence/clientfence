<?php

declare(strict_types=1);

namespace ClientFence\Mod\Invoice;

use ClientFence\InformationException;
use Doctrine\DBAL\Connection;

/**
 * ClientFence: a client's account statement, as WHMCS's "Client Account Register Balance": what
 * was billed and paid, with a running balance (negative: the client owes; positive: in credit).
 * Staff see it in Invoices > Reports; clients on their Statement page and as a PDF (StatementPdf).
 *
 * Lines, in the client's currency (approved invoices only, as the client area lists them):
 * - each unpaid or paid invoice: billed (a debit), on its date;
 * - each paid invoice's payment: paid (a credit), on the day it was paid. A gateway payment that
 *   passed through the account balance (ServiceTransaction::debitTransaction() adds it, then
 *   tryPayWithCredits() takes the invoice from it) is the amount received, so an overpayment shows;
 *   an invoice paid from account credit has no payment line, as the credit was counted when added;
 * - account credit added or removed (client_balance), without those payment movements.
 * Cancelled and refunded invoices are left out, with their payments (WHMCS does the same).
 */
final class Statement
{
    /**
     * @return array{client: array<string, mixed>, currency: string, from: string, to: string, opening: float, lines: list<array<string, mixed>>, debit: float, credit: float, closing: float}
     */
    public static function build(\Pimple\Container $di, int $clientId, string $from = '', string $to = ''): array
    {
        $db = $di['em']->getConnection();
        $client = $db->fetchAssociative('SELECT id, first_name, last_name, company, email, currency FROM client WHERE id = ?', [$clientId]);
        if ($client === false) {
            throw new InformationException('Client not found');
        }
        foreach ([$from, $to] as $date) {
            if ($date !== '' && (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $date, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]))) {
                throw new InformationException('Enter the dates as year-month-day.');
            }
        }
        if ($from !== '' && $to !== '' && $from > $to) {
            throw new InformationException('The start date is after the end date.');
        }
        $currency = trim((string) $client['currency']);
        if ($currency === '') {
            $code = $db->fetchOne('SELECT code FROM currency ORDER BY is_default DESC, code');
            $currency = $code === false ? 'USD' : (string) $code;
        }

        $lines = self::lines($db, $clientId, $currency);
        usort($lines, static fn (array $a, array $b): int => [$a['at'], $a['order'], $a['seq']] <=> [$b['at'], $b['order'], $b['seq']]);

        $opening = 0.0;
        $shown = [];
        $balance = 0.0;
        $debit = $credit = 0.0;
        foreach ($lines as $line) {
            $balance = round($balance + $line['credit'] - $line['debit'], 2);
            if ($from !== '' && $line['date'] < $from) {
                $opening = $balance;

                continue;
            }
            if ($to !== '' && $line['date'] > $to) {
                break;
            }
            $debit += $line['debit'];
            $credit += $line['credit'];
            unset($line['at'], $line['order'], $line['seq']);
            $shown[] = $line + ['balance' => $balance];
        }

        return [
            'client' => [
                'id' => (int) $client['id'],
                'name' => trim($client['first_name'] . ' ' . $client['last_name']),
                'company' => (string) $client['company'],
                'email' => (string) $client['email'],
            ],
            'currency' => $currency,
            'from' => $from,
            'to' => $to,
            'opening' => $opening,
            'lines' => $shown,
            'debit' => round($debit, 2),
            'credit' => round($credit, 2),
            'closing' => round($opening + $credit - $debit, 2),
        ];
    }

    /**
     * Every line of the account, unsorted. `description` is the whole text; `title` and `detail`
     * (the payment method or the credit note, or null) are its parts, which the PDF sets on two
     * lines so mixed right-to-left text stays short. `at` is the full time (lines are sorted by
     * it), `order` puts a payment after its invoice made at the same second.
     *
     * @return list<array<string, mixed>>
     */
    private static function lines(Connection $db, int $clientId, string $currency): array
    {
        $invoices = $db->fetchAllAssociative(
            'SELECT i.id, i.serie, i.nr, i.status, i.created_at, i.paid_at, g.name AS gateway, '
            // Tax once on the taxed subtotal, as Invoice\Service::getTax() (what the client was asked to pay).
            . '(SELECT COALESCE(SUM(ii.price * ii.quantity), 0) + CASE WHEN (i.taxrate + 0) > 0 THEN ROUND(COALESCE(SUM(CASE WHEN ii.taxed <> 0 THEN ii.price * ii.quantity ELSE 0 END), 0) * (i.taxrate + 0) / 100, 2) ELSE 0 END FROM invoice_item ii WHERE ii.invoice_id = i.id) AS total
             FROM invoice i LEFT JOIN pay_gateway g ON g.id = i.gateway_id
             WHERE i.client_id = ? AND i.approved = 1 AND i.currency = ? AND i.status IN (?, ?)',
            [$clientId, $currency, 'unpaid', 'paid']
        );
        $balanceRows = $db->fetchAllAssociative('SELECT id, type, rel_id, amount, description, created_at FROM client_balance WHERE client_id = ?', [$clientId]);

        // Balance rows that carry a gateway payment to its invoice, and invoices paid from the balance.
        $txIds = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['rel_id'], array_filter($balanceRows, static fn (array $r): bool => $r['type'] === 'transaction'))));
        $txInvoice = [];
        if ($txIds !== []) {
            $quoted = $db->quoteSingleIdentifier('transaction');
            foreach ($db->fetchAllAssociative('SELECT id, invoice_id FROM ' . $quoted . ' WHERE id IN (' . implode(',', $txIds) . ')') as $tx) {
                $txInvoice[(int) $tx['id']] = (int) $tx['invoice_id'];
            }
        }
        $received = $usedFor = [];
        foreach ($balanceRows as $row) {
            if ($row['type'] === 'transaction' && (float) $row['amount'] > 0 && isset($txInvoice[(int) $row['rel_id']])) {
                $received[$txInvoice[(int) $row['rel_id']]][] = $row;
            } elseif ($row['type'] === 'invoice' && (float) $row['amount'] < 0) {
                $usedFor[(int) $row['rel_id']][] = $row;
            }
        }

        $lines = [];
        $skip = [];
        $seq = 0;
        foreach ($invoices as $invoice) {
            $id = (int) $invoice['id'];
            $number = self::number($db, $invoice);
            $total = round((float) $invoice['total'], 2);
            $fromCredit = $invoice['status'] === 'paid' && isset($usedFor[$id]) && !isset($received[$id]);
            $lines[] = [
                'date' => substr((string) $invoice['created_at'], 0, 10),
                'at' => (string) $invoice['created_at'],
                'type' => 'invoice',
                'description' => $invoiceText = $fromCredit ? __trans('Invoice :number (paid from account credit)', [':number' => $number]) : __trans('Invoice :number', [':number' => $number]),
                'title' => $invoiceText,
                'detail' => null,
                'invoice_id' => $id,
                'debit' => $total,
                'credit' => 0.0,
                'order' => 0,
                'seq' => ++$seq,
            ];
            if ($invoice['status'] !== 'paid') {
                continue;
            }
            foreach ($usedFor[$id] ?? [] as $row) {
                $skip[(int) $row['id']] = true;
            }
            if ($fromCredit) {
                continue;
            }
            $paidAt = (string) ($invoice['paid_at'] ?? $invoice['created_at']);
            $payment = $invoice['gateway'] ? __trans('Payment for invoice :number (:method)', [':number' => $number, ':method' => $invoice['gateway']]) : __trans('Payment for invoice :number', [':number' => $number]);
            $paymentTitle = __trans('Payment for invoice :number', [':number' => $number]);
            $method = $invoice['gateway'] ? (string) $invoice['gateway'] : null;
            if (isset($received[$id], $usedFor[$id])) {
                foreach ($received[$id] as $row) {
                    $skip[(int) $row['id']] = true;
                    $lines[] = ['date' => substr((string) $row['created_at'], 0, 10), 'at' => (string) $row['created_at'], 'type' => 'payment', 'description' => $payment, 'title' => $paymentTitle, 'detail' => $method, 'invoice_id' => $id, 'debit' => 0.0, 'credit' => round((float) $row['amount'], 2), 'order' => 1, 'seq' => ++$seq];
                }
                continue;
            }
            $lines[] = ['date' => substr($paidAt, 0, 10), 'at' => $paidAt, 'type' => 'payment', 'description' => $payment, 'title' => $paymentTitle, 'detail' => $method, 'invoice_id' => $id, 'debit' => 0.0, 'credit' => $total, 'order' => 1, 'seq' => ++$seq];
        }

        foreach ($balanceRows as $row) {
            if (isset($skip[(int) $row['id']])) {
                continue;
            }
            $amount = round((float) $row['amount'], 2);
            if ($amount == 0.0) {
                continue;
            }
            $text = trim((string) $row['description']);
            $lines[] = [
                'date' => substr((string) $row['created_at'], 0, 10),
                'at' => (string) $row['created_at'],
                'type' => 'credit',
                'description' => $amount > 0
                    ? ($text === '' ? __trans('Account credit added') : __trans('Account credit added: :text', [':text' => $text]))
                    : ($text === '' ? __trans('Account credit used') : __trans('Account credit used: :text', [':text' => $text])),
                'title' => $amount > 0 ? __trans('Account credit added') : __trans('Account credit used'),
                'detail' => $text === '' ? null : $text,
                'invoice_id' => null,
                'debit' => $amount < 0 ? -$amount : 0.0,
                'credit' => $amount > 0 ? $amount : 0.0,
                'order' => 2,
                'seq' => ++$seq,
            ];
        }

        return $lines;
    }

    /** The invoice number as FOSSBilling shows it (`serie_nr`, with the invoice_number_padding setting). */
    public static function number(Connection $db, array $invoice): string
    {
        /** @var \WeakMap<Connection, int>|null $padding */
        static $padding = null;
        $padding ??= new \WeakMap();
        $key = $db;
        if (!isset($padding[$key])) {
            try {
                $value = $db->fetchOne("SELECT value FROM setting WHERE param = 'invoice_number_padding'");
            } catch (\Doctrine\DBAL\Exception) {
                $value = false;
            }
            $padding[$key] = $value !== false && $value !== null && $value !== '' ? (int) $value : 5;
        }
        $nr = is_numeric($invoice['nr'] ?? null) ? (int) $invoice['nr'] : (int) $invoice['id'];

        return (string) $invoice['serie'] . sprintf('%0' . $padding[$key] . 's', $nr);
    }
}
