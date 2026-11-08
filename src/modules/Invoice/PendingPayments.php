<?php

declare(strict_types=1);

/**
 * ClientFence: bank debits (Stripe BECS, SEPA and Bacs Direct Debit) take days to clear. Until Stripe confirms one, its
 * invoice stays unpaid but shows "Payment pending", as WHMCS does: no reminders, no automatic charge, no credit applied,
 * and its orders are neither suspended nor removed. A Stripe payment still clearing is a received transaction with
 * txn_status processing (Payment_Adapter_Stripe); Stripe's answer marks the invoice paid or ends the wait.
 */

namespace ClientFence\Mod\Invoice;

final class PendingPayments
{
    // shortcut: a debit Stripe never answers for stops counting after this (SEPA takes up to 14 business days).
    private const DAYS = 21;

    /** SQL condition: the invoice in $column has a payment still clearing. */
    public static function sql(string $column): string
    {
        return "EXISTS (SELECT 1 FROM `transaction` pp WHERE pp.invoice_id = {$column} AND pp.status = 'received' AND pp.txn_status = 'processing' AND pp.created_at > '" . self::since() . "')";
    }

    /** The invoice's payment still clearing ({amount, currency, created_at}), or null. */
    public static function forInvoice(\Pimple\Container $di, int $invoiceId): ?array
    {
        $row = $di['dbal']->fetchAssociative(
            "SELECT amount, currency, created_at FROM `transaction` WHERE invoice_id = ? AND status = 'received' AND txn_status = 'processing' AND created_at > ? ORDER BY id DESC LIMIT 1",
            [$invoiceId, self::since()]
        );

        return $row ?: null;
    }

    /** Whether an unpaid invoice of the order has a payment still clearing. */
    public static function forOrder(\Pimple\Container $di, int $orderId): bool
    {
        return (bool) $di['dbal']->fetchOne(
            "SELECT 1 FROM invoice_item ii JOIN invoice i ON i.id = ii.invoice_id WHERE ii.type = 'order' AND ii.rel_id = ? AND i.status = 'unpaid' AND " . self::sql('i.id') . ' LIMIT 1',
            [(string) $orderId]
        );
    }

    private static function since(): string
    {
        return date('Y-m-d H:i:s', strtotime('-' . self::DAYS . ' days'));
    }
}
