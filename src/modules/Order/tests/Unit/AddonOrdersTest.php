<?php

declare(strict_types=1);

/**
 * ClientFence: add-ons ordered for a client's service (Order\AddonOrders): which add-ons the service offers, and the
 * part of a period the first invoice charges (to the service's due date).
 */

use ClientFence\Mod\Order\AddonOrders;
use ClientFence\Mod\Product\Entity\Product;

use function Tests\Helpers\container;

/** A service on product 1 with add-on 2 (monthly), 3 (yearly only) and 4 (one time). */
function addonOrdersDi(bool $parentIsAddon = false): Pimple\Container
{
    $product = fn (int $id, bool $addon = false): Product => Mockery::mock(Product::class, ['getId' => $id, 'isAddon' => $addon]);
    [$monthly, $yearly, $once] = [$product(2, true), $product(3, true), $product(4, true)];
    $products = Mockery::mock(ClientFence\Mod\Product\Service::class);
    $products->shouldReceive('findProductById')->with(1)->andReturn($product(1, $parentIsAddon));
    $products->shouldReceive('getProductAddons')->andReturn([$monthly, $yearly, $once]);
    $products->shouldReceive('isRecurrentProductPricing')->andReturnUsing(fn (Product $p): bool => $p !== $once);
    $products->shouldReceive('isProductPeriodEnabled')->andReturnUsing(fn (Product $p, string $period): bool => $p === $monthly ? $period === '1M' : $period === '1Y');
    $products->shouldReceive('inCurrency')->andReturnSelf();
    $products->shouldReceive('toAddonArray')->andReturnUsing(fn (Product $p): array => ['title' => 'Add-on ' . $p->getId(), 'description' => '', 'pricing' => $p === $once
        ? ['type' => 'once', 'once' => ['price' => 4, 'setup' => 0]]
        : ['type' => 'recurrent', 'recurrent' => ['1M' => ['price' => 10, 'setup' => 1]]]]);
    // Prices come in default-currency terms; the service is billed in EGP at 50.
    $currencies = Mockery::mock(['getCurrencyRepository' => Mockery::mock(['getRateByCode' => 50.0])]);

    $db = Mockery::mock(Doctrine\DBAL\Connection::class);
    $db->shouldReceive('fetchAssociative')->andReturn(false); // no plan change: periods count from the due date
    $di = container();
    $di['em'] = Mockery::mock(['getConnection' => $db]);
    $di['mod_service'] = $di->protect(fn (string $name) => strtolower($name) === 'currency' ? $currencies : $products);

    return $di;
}

function addonOrdersService(string $status = 'active', string $expires = '+15 days'): Model_ClientOrder
{
    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->id = 7;
    $order->product_id = 1;
    $order->status = $status;
    $order->period = '1M';
    $order->currency = 'EGP';
    $order->expires_at = date('Y-m-d 12:00:00', strtotime($expires));
    $order->activated_at = null;

    return $order;
}

test('a service offers its add-ons priced for its cycle or once, and only while it runs', function (): void {
    expect(array_keys(AddonOrders::products(addonOrdersDi(), addonOrdersService())))->toBe([2, 4])
        ->and(AddonOrders::products(addonOrdersDi(), addonOrdersService('suspended')))->toBe([])
        ->and(AddonOrders::products(addonOrdersDi(), addonOrdersService('active', '-1 day')))->toBe([])
        // An add-on is not offered add-ons of its own.
        ->and(AddonOrders::products(addonOrdersDi(true), addonOrdersService()))->toBe([])
        ->and(fn () => AddonOrders::offered(addonOrdersDi(), addonOrdersService(), 3))
        ->toThrow(ClientFence\InformationException::class, 'This add-on cannot be ordered for this service.');
});

test('the first invoice charges the days left to the service due date', function (): void {
    $order = addonOrdersService();
    // The month that ends on the due date, keeping its day (31 March starts on 28 February).
    $expiry = new DateTimeImmutable(substr((string) $order->expires_at, 0, 10));
    $start = $expiry->modify('first day of last month');
    $start = $start->setDate((int) $start->format('Y'), (int) $start->format('m'), min((int) $expiry->format('d'), (int) $start->format('t')));
    $days = (int) $start->diff($expiry)->days;

    expect(AddonOrders::factor(addonOrdersDi(), $order))->toBe(15 / $days);
});

test('offers are priced in the service currency, the first charge to its due date', function (): void {
    $order = addonOrdersService();
    $factor = AddonOrders::factor(addonOrdersDi(), $order);
    $offers = AddonOrders::offers(addonOrdersDi(), $order);

    expect(array_column($offers, 'price', 'id'))->toBe([2 => 500.0, 4 => 200.0])
        ->and(array_column($offers, 'setup', 'id'))->toBe([2 => 50.0, 4 => 0.0])
        ->and(array_column($offers, 'now', 'id'))->toBe([2 => round(500 * $factor, 2), 4 => 200.0]);
});
