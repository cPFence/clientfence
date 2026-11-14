<?php

declare(strict_types=1);

namespace ClientFence\Mod\Invoice;

use ClientFence\InformationException;
use ClientFence\Mod\Product\Entity\Promo;
use ClientFence\Mod\Product\Entity\PromoRedemption;
use ClientFence\Tools;

/**
 * Staff apply an existing promotion to an order on an unpaid invoice (invoice and order pages, Api\Admin::promo_apply()).
 * It is recorded as that promotion, not a typed negative line: the order's promotion and a reserved redemption that the
 * payment commits, so a recurring one carries to the order's renewals. The rules of a code apply (dates, uses, once per
 * client, client groups, products and periods). The discount line comes off the order's own line, never below zero,
 * and an order gets one discount per invoice. What the invoice already received stays on it: the balance due goes down.
 * setOnOrder() sets or removes an order's promotion for its next renewals only (Order\Api\Admin::promo_set()).
 */
final class Promotions
{
    /** Returns the discount taken off the invoice, before tax. */
    public static function apply(\Pimple\Container $di, \Model_Invoice $invoice, Promo $promo, ?int $orderId): float
    {
        $amount = Tools::withLock($di, 'fb:credit_payment:client:' . (int) $invoice->client_id, static fn (): float => $di['dbal']->transactional(static function () use ($di, $invoice, $promo, $orderId): float {
            $current = $di['dbal']->fetchAssociative('SELECT * FROM invoice WHERE id = ?', [$invoice->id]);
            if ($current === false || $current['status'] !== \Model_Invoice::STATUS_UNPAID) {
                throw new InformationException('A promotion can only be applied to an unpaid invoice.');
            }
            foreach ($current as $column => $value) {
                $invoice->{$column} = $value;
            }
            $lines = $di['dbal']->fetchAllAssociative('SELECT type, rel_id, task, unit, price, quantity, taxed FROM invoice_item WHERE invoice_id = ?', [$invoice->id]);
            $orderLines = array_filter($lines, static fn (array $l): bool => $l['type'] === \Model_InvoiceItem::TYPE_ORDER);
            $orderIds = array_values(array_unique(array_map(static fn (array $l): int => (int) $l['rel_id'], $orderLines)));
            if ($orderId === null && count($orderIds) !== 1) {
                throw new InformationException($orderIds === [] ? 'This invoice has no order to apply a promotion to.' : 'Choose the order on this invoice.');
            }
            $orderId ??= $orderIds[0];
            if (!in_array($orderId, $orderIds, true)) {
                throw new InformationException('This order is not on this invoice.');
            }
            foreach ($lines as $line) {
                if ($line['unit'] === 'discount' && (int) $line['rel_id'] === $orderId) {
                    throw new InformationException('This order already has a discount on this invoice.');
                }
            }
            $orderLines = array_filter($orderLines, static fn (array $l): bool => (int) $l['rel_id'] === $orderId);

            $order = $di['db']->getExistingModelById('ClientOrder', $orderId, 'Order not found');
            $client = $di['db']->getExistingModelById('Client', (int) $invoice->client_id, 'Client not found');
            $productService = $di['mod_service']('Product');
            if (!$productService->promoCanBeApplied($promo) || !$productService->isPromoAvailableForClientGroup($promo, $client)
                || $productService->clientHasActivePromoApplicationForUpdate($client, $promo)) {
                throw new InformationException('This promotion code cannot be used for this client.');
            }
            $product = $productService->findProductById((int) $order->product_id);
            $config = json_decode((string) $order->config, true) ?: [];
            if ($product->getType() !== \ClientFence\Mod\Product\Service::DOMAIN && !isset($config['period']) && $order->period) {
                $config['period'] = $order->period;
            }
            if (!$productService->isPromoApplicableToProduct($promo, $product, $config)) {
                throw new InformationException('This promotion does not apply to the product or billing period of this order.');
            }

            // From the order's own price, in its currency (Product\Service::getOrderPromoDiscount()).
            $currency = (string) $order->currency;
            $rate = $di['mod_service']('Currency')->getCurrencyRepository()->getRateByCode($currency)
                ?? throw new \ClientFence\Exception("Currency conversion rate cannot be determined for code {$currency}");
            $full = $productService->getOrderPromoDiscount($product, $promo, $config, (float) $order->price, (float) $order->quantity, (float) $rate);

            // Invoice lines are net when prices include tax (Service::applyInclusiveTax()): so is the discount line.
            $invoiceService = $di['mod_service']('Invoice');
            $taxRate = 0.0;
            if ($invoiceService->pricesIncludeTax()) {
                $taxRate = (float) $invoice->taxrate ?: (float) $di['mod_service']('Invoice', 'Tax')->getLocationTaxRate($client);
            }
            $lines = round(array_sum(array_map(static fn (array $l): float => (float) $l['price'] * (float) $l['quantity'], $orderLines)), 2);
            // A prorated line (an add-on to its parent's due date) takes the same share of the discount, as at checkout;
            // a renewal line is the full price. The options keep their own share (getOrderPromoDiscount()).
            $orderTotal = (float) $order->price * (float) $order->quantity;
            $share = $orderTotal > 0 ? min(1.0, ($taxRate > 0 ? $lines * (100 + $taxRate) / 100 : $lines) / $orderTotal) : 1.0;
            $gross = round($full * $share, 2);
            $net = $taxRate > 0 ? round($gross * 100 / (100 + $taxRate), 2) : $gross;
            $amount = min($net, $lines);
            if ($amount <= 0) {
                throw new InformationException('This promotion takes nothing off this order.');
            }
            $grossTaken = $amount === $net ? $gross : round($amount * (100 + $taxRate) / 100, 2);
            $taxed = (bool) reset($orderLines)['taxed'];

            $productService->usePromo($promo);
            $before = $invoiceService->getTotalWithTax($invoice);
            $di['mod_service']('Invoice', 'InvoiceItem')->addNew($invoice, [
                'title' => $productService->getPromoDiscountTitle($promo, $currency),
                'price' => -$amount,
                'quantity' => 1,
                'unit' => 'discount',
                'rel_id' => $orderId,
                'taxed' => $taxed,
            ]);
            // With prices that include tax the total goes down by the discount as the client sees it, as applyInclusiveTax() keeps it.
            if ($taxed && (float) $invoice->taxrate > 0 && $taxRate > 0) {
                $invoice->tax_adjust = round((float) $invoice->tax_adjust + $before - $grossTaken - $invoiceService->getTotalWithTax($invoice), 2);
                $di['dbal']->update('invoice', ['tax_adjust' => $invoice->tax_adjust], ['id' => (int) $invoice->id]);
            }
            // The order keeps the full-period discount, which its renewals take off when the promotion is recurring.
            $di['dbal']->update('client_order', ['promo_id' => $promo->getId(), 'promo_recurring' => (int) $promo->isRecurring(), 'promo_used' => 1, 'discount' => $full, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $orderId]);
            $productService->createPromoRedemption($promo, $client, $order, $invoice, PromoRedemption::PHASE_CHECKOUT, $grossTaken, $currency, null, PromoRedemption::STATUS_RESERVED);

            return $amount;
        }));

        // The gateway fee follows the new balance due; what the invoice already received may now pay it in full.
        if ($invoice->gateway_id) {
            GatewayFees::apply($di, $invoice, (int) $invoice->gateway_id);
        }
        $invoiceService = $di['mod_service']('Invoice');
        if ($invoiceService->getBalanceDue($invoice) <= 0) {
            $invoiceService->tryPayWithCredits($invoice);
        }
        $di['logger']->info('Applied promotion %s to invoice #%s', $promo->getCode(), $invoice->id);

        return $amount;
    }

    /**
     * Staff set or remove an order's promotion for its next renewals, as on a WHMCS service: invoices already issued stay
     * as they are. A recurring promotion only, under the rules of a code; removed (null), the renewals are charged in full
     * unless the client's automatic promotion applies. A new promotion counts a use and is recorded as applied to the
     * order (a committed checkout redemption, there is no invoice to pay); saving the same one again changes nothing.
     */
    public static function setOnOrder(\Pimple\Container $di, \Model_ClientOrder $order, ?Promo $promo): bool
    {
        if ($promo !== null && (int) $order->promo_id === $promo->getId() && $order->promo_recurring) {
            return true;
        }
        $discount = 0.0;
        if ($promo !== null) {
            $productService = $di['mod_service']('Product');
            $client = $di['db']->getExistingModelById('Client', (int) $order->client_id, 'Client not found');
            if (!$promo->isRecurring()) {
                throw new InformationException('Only a recurring promotion comes off renewals.');
            }
            if (!$productService->isPromoAvailableForClientGroup($promo, $client) || !$productService->canClientUsePromo($client, $promo)) {
                throw new InformationException('This promotion code cannot be used for this client.');
            }
            $product = $productService->findProductById((int) $order->product_id);
            $config = json_decode((string) $order->config, true) ?: [];
            if ($product->getType() !== \ClientFence\Mod\Product\Service::DOMAIN && !isset($config['period']) && $order->period) {
                $config['period'] = $order->period;
            }
            $currency = (string) $order->currency;
            $rate = (float) $di['mod_service']('Currency')->getCurrencyRepository()->getRateByCode($currency);
            $discount = $productService->getOrderPromoDiscount($product, $promo, $config, (float) $order->price, (float) $order->quantity, $rate);
            if ($discount <= 0) {
                throw new InformationException('This promotion does not apply to the product or billing period of this order.');
            }
            $productService->usePromo($promo);
            $productService->createPromoRedemption($promo, $client, $order, null, PromoRedemption::PHASE_CHECKOUT, null, $currency);
        }
        $di['dbal']->update('client_order', ['promo_id' => $promo?->getId(), 'promo_recurring' => (int) ($promo !== null), 'promo_used' => (int) ($promo !== null), 'discount' => $discount, 'updated_at' => date('Y-m-d H:i:s')], ['id' => (int) $order->id]);
        $di['logger']->info('Set promotion %s on order #%s', $promo?->getCode() ?? '-', $order->id);

        return true;
    }
}
