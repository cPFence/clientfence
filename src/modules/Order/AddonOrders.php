<?php

declare(strict_types=1);

namespace ClientFence\Mod\Order;

use ClientFence\InformationException;
use ClientFence\Mod\Product\Entity\Product;

/**
 * ClientFence: an add-on ordered for a service the client already has, as WHMCS and Blesta. The service's product offers
 * its add-ons in the service's billing cycle; the first invoice charges a recurring add-on for the days left to the
 * service's due date (Proration), and the add-on then renews on that date with the service (Cart\Service, activateOrder()).
 */
final class AddonOrders
{
    /**
     * The add-ons the service can take now, by product id: its product's add-ons that are on sale, priced once or free,
     * or priced for the service's billing cycle. None when the service is not active, is itself an add-on, or has no
     * future due date.
     *
     * @return array<int, Product>
     */
    public static function products(\Pimple\Container $di, \Model_ClientOrder $order): array
    {
        $products = $di['mod_service']('product');
        if ($order->status !== Entity\Order::STATUS_ACTIVE || (string) $order->period === '' || (string) $order->expires_at === ''
            || Proration::daysLeft((string) $order->expires_at, date('Y-m-d H:i:s')) < 1) {
            return [];
        }
        try {
            $parent = $products->findProductById((int) $order->product_id);
        } catch (\ClientFence\Exception) {
            return [];
        }
        if ($parent->isAddon()) {
            return [];
        }
        $offered = [];
        foreach ($products->getProductAddons($parent) as $addon) {
            if (!$products->isRecurrentProductPricing($addon) || $products->isProductPeriodEnabled($addon, (string) $order->period)) {
                $offered[(int) $addon->getId()] = $addon;
            }
        }

        return $offered;
    }

    /**
     * The add-ons the service can take, for its page: title, description, the price now (to the service's due date) and
     * the price each period after, in the service's currency.
     *
     * @return list<array{id: int, title: string, description: string, period: ?string, price: float, setup: float, now: float, due: ?string}>
     */
    public static function offers(\Pimple\Container $di, \Model_ClientOrder $order): array
    {
        $products = $di['mod_service']('product');
        $priced = $products instanceof \ClientFence\Mod\Product\Service ? $products->inCurrency((string) $order->currency) : $products;
        $factor = null;
        // Prices come in default-currency terms (fixed prices too): the service's currency is shown.
        $rate = $di['mod_service']('Currency')->getCurrencyRepository()->getRateByCode((string) $order->currency) ?? 1.0;
        $list = [];
        foreach (self::products($di, $order) as $id => $addon) {
            $view = $priced->toAddonArray($addon, false);
            $pricing = $view['pricing'] ?? [];
            $recurring = ($pricing['type'] ?? '') === 'recurrent';
            $row = $recurring ? ($pricing['recurrent'][(string) $order->period] ?? []) : ($pricing['once'] ?? []);
            $price = ($pricing['type'] ?? '') === 'free' ? 0.0 : (float) ($row['price'] ?? 0) * $rate;
            $factor ??= self::factor($di, $order);
            $list[] = [
                'id' => $id,
                'title' => (string) ($view['title'] ?? ''),
                'description' => (string) ($view['description'] ?? ''),
                'period' => $recurring ? (string) $order->period : null,
                'price' => round($price, 2),
                'setup' => ($pricing['type'] ?? '') === 'free' ? 0.0 : round((float) ($row['setup'] ?? 0) * $rate, 2),
                'now' => round($recurring ? $price * $factor : $price, 2),
                'due' => $recurring ? substr((string) $order->expires_at, 0, 10) : null,
            ];
        }

        return $list;
    }

    public static function offered(\Pimple\Container $di, \Model_ClientOrder $order, int $productId): Product
    {
        return self::products($di, $order)[$productId] ?? throw new InformationException('This add-on cannot be ordered for this service.');
    }

    /** The part of a period the first invoice charges: the days left to the service's due date over its period's days. */
    public static function factor(\Pimple\Container $di, \Model_ClientOrder $order): float
    {
        $expires = (string) $order->expires_at;
        $total = Proration::daysTotal((string) $order->period, $expires, Upgrades::anchorFor($di, $order), Service::keepsRenewalDay($di));

        return min(1.0, Proration::daysLeft($expires, date('Y-m-d H:i:s')) / $total);
    }
}
