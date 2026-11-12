<?php

declare(strict_types=1);

/**
 * ClientFence: payment gateway fees, as HostBill's Gateway Fees and the WHMCS gateway fee add-ons (owner decisions
 * 2026-11-12). Each gateway may have a percentage and a fixed amount (in the default currency, converted at the
 * portal's exchange rates), taxed or not, and client groups that pay none (its config "fee", saved on the gateway's
 * page). The fee is one line of the invoice (task gateway_fee, titled after the gateway) that follows the invoice's gateway:
 * it is set when an unpaid invoice gets a gateway (issued with one, the client starts paying or sends a receipt, staff
 * change it, a saved method is charged) and replaced or removed when the gateway changes. It is a share of the
 * balance due without the fee, so credit the client applies pays no fee (applyCredit()). Its rel_id stays empty: order
 * code matches invoice lines to orders by rel_id.
 */

namespace ClientFence\Mod\Invoice;

final class GatewayFees
{
    public const TASK = 'gateway_fee';

    /** A gateway's fee settings: {percent, fixed, taxable, exempt_groups}. */
    public static function settings(\Model_PayGateway $gateway): array
    {
        $fee = json_decode((string) $gateway->config, true)['fee'] ?? [];

        return self::clean(is_array($fee) ? $fee : []);
    }

    /** The fee settings as saved from the gateway's page: a percentage of 0-100, a fixed amount of 0 or more. */
    public static function clean(array $fee): array
    {
        $number = static fn (mixed $value): float => is_numeric($value) ? round(max(0.0, (float) $value), 4) : 0.0;

        return [
            'percent' => min(100.0, $number($fee['percent'] ?? 0)),
            'fixed' => round($number($fee['fixed'] ?? 0), 2),
            'taxable' => !empty($fee['taxable']),
            'exempt_groups' => array_values(array_unique(array_map('intval', array_filter((array) ($fee['exempt_groups'] ?? []), 'is_numeric')))),
        ];
    }

    /** The fee of paying $base in $currency with $gateway (client $clientId, or a guest), before tax. */
    public static function fee(\Pimple\Container $di, \Model_PayGateway $gateway, float $base, string $currency, ?int $clientId): float
    {
        $fee = self::settings($gateway);
        if ($base <= 0 || ($fee['percent'] <= 0 && $fee['fixed'] <= 0)) {
            return 0.0;
        }
        if ($clientId !== null && $fee['exempt_groups'] !== []
            && in_array((int) $di['dbal']->fetchOne('SELECT client_group_id FROM client WHERE id = ?', [$clientId]), $fee['exempt_groups'], true)) {
            return 0.0;
        }
        $fixed = $fee['fixed'] > 0 ? $fee['fixed'] / $di['mod_service']('Currency')->getBaseCurrencyRate($currency) : 0.0;

        return round($base * $fee['percent'] / 100 + $fixed, 2);
    }

    /** The invoice's balance due without its fee line: what a fee is a share of. */
    public static function base(\Pimple\Container $di, \Model_Invoice $invoice): float
    {
        $line = self::line($di, (int) $invoice->id);

        return round($di['mod_service']('Invoice')->getBalanceDue($invoice) - ($line === null ? 0.0 : self::total($di, $invoice, $line)), 2);
    }

    /** The tax a taxed $fee adds to the invoice, rounded once over all its taxed lines as Service::getTax() does. */
    public static function tax(\Pimple\Container $di, \Model_Invoice $invoice, float $fee): float
    {
        $taxed = (float) $di['dbal']->fetchOne("SELECT COALESCE(SUM(price * quantity), 0) FROM invoice_item WHERE invoice_id = ? AND taxed = 1 AND COALESCE(task, '') <> ?", [(int) $invoice->id, self::TASK]);
        $rate = (float) $invoice->taxrate;
        $adjust = (float) $invoice->tax_adjust;

        return round(Service::taxOf($taxed + $fee, $rate, $adjust) - Service::taxOf($taxed, $rate, $adjust), 2);
    }

    /**
     * Sets the fee line of an unpaid invoice for the gateway that is to take its payment ($gatewayId; null removes it)
     * and makes that gateway the invoice's. Add funds invoices carry no fee.
     */
    public static function apply(\Pimple\Container $di, \Model_Invoice $invoice, ?int $gatewayId): void
    {
        \ClientFence\Tools::withLock($di, 'cf:gateway-fee:' . (int) $invoice->id, static function () use ($di, $invoice, $gatewayId): void {
            $row = $di['dbal']->fetchAssociative('SELECT status, gateway_id FROM invoice WHERE id = ?', [(int) $invoice->id]) ?: [];
            if (($row['status'] ?? null) !== \Model_Invoice::STATUS_UNPAID || $di['mod_service']('Invoice')->isInvoiceTypeDeposit($invoice)) {
                return;
            }
            if ($gatewayId !== null && (int) $row['gateway_id'] !== $gatewayId) {
                $di['dbal']->update('invoice', ['gateway_id' => $gatewayId, 'updated_at' => date('Y-m-d H:i:s')], ['id' => (int) $invoice->id]);
                $invoice->gateway_id = $gatewayId;
            }
            $gateway = $gatewayId === null ? null : $di['db']->load('PayGateway', $gatewayId);
            $fee = $gateway instanceof \Model_PayGateway && $gateway->id ? self::settings($gateway) : ['percent' => 0, 'fixed' => 0];
            $amount = $fee['percent'] > 0 || $fee['fixed'] > 0 ? self::fee($di, $gateway, self::base($di, $invoice), (string) $invoice->currency, (int) $invoice->client_id) : 0.0;
            $line = self::line($di, (int) $invoice->id);
            if ($amount <= 0) {
                if ($line !== null) {
                    $di['mod_service']('Invoice', 'InvoiceItem')->remove($line);
                }

                return;
            }
            $items = $di['mod_service']('Invoice', 'InvoiceItem');
            $title = __trans(':gateway fee', [':gateway' => (string) $gateway->name]);
            $taxed = self::settings($gateway)['taxable'];
            if ($line !== null && $line->title === $title && round((float) $line->price, 2) === $amount && (bool) $line->taxed === $taxed) {
                return;
            }
            if ($line !== null) {
                $items->remove($line);
            }
            $items->addNew($invoice, ['title' => $title, 'price' => $amount, 'quantity' => 1, 'taxed' => $taxed, 'task' => self::TASK]);
        });
    }

    /** Removes the fee line of an unpaid invoice (credit pays without a fee): the amount it took off, with its tax. */
    public static function remove(\Pimple\Container $di, \Model_Invoice $invoice): float
    {
        $line = self::line($di, (int) $invoice->id);
        if ($line === null || $di['dbal']->fetchOne('SELECT status FROM invoice WHERE id = ?', [(int) $invoice->id]) !== \Model_Invoice::STATUS_UNPAID) {
            return 0.0;
        }
        $total = self::total($di, $invoice, $line);
        $di['mod_service']('Invoice', 'InvoiceItem')->remove($line);

        return $total;
    }

    /** What the fee line adds to the invoice: its price and the tax it adds to the invoice's tax. */
    private static function total(\Pimple\Container $di, \Model_Invoice $invoice, \Model_InvoiceItem $line): float
    {
        $price = (float) $line->price * (float) $line->quantity;

        return round($price + ($line->taxed ? self::tax($di, $invoice, $price) : 0.0), 2);
    }

    private static function line(\Pimple\Container $di, int $invoiceId): ?\Model_InvoiceItem
    {
        $line = $di['db']->findOne('InvoiceItem', 'invoice_id = ? AND type = ? AND task = ?', [$invoiceId, \Model_InvoiceItem::TYPE_CUSTOM, self::TASK]);

        return $line instanceof \Model_InvoiceItem ? $line : null;
    }
}
