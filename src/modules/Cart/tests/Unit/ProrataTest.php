<?php

declare(strict_types=1);

/**
 * ClientFence (CF-145): cart lines of a prorata product (WHMCS Prorata Billing) are priced for the first period to the
 * prorata day (Order\Proration::prorata()), keep the full price for the renewals and carry the first due date and the
 * prorata day into the order. Products without the setting are priced as before.
 */

use ClientFence\Mod\Cart\Entity\Cart;
use ClientFence\Mod\Cart\Entity\CartProduct;
use ClientFence\Mod\Cart\Repository\CartProductRepository;
use ClientFence\Mod\Cart\Repository\CartRepository;
use ClientFence\Mod\Cart\Service;
use ClientFence\Mod\Order\Proration;
use ClientFence\Mod\Product\Service as ProductService;
use Doctrine\DBAL\DriverManager;

use function Tests\Helpers\container;
use function Tests\Helpers\createEntity;

/** Products: 1 monthly on day 1 (charge next month from the 25th), 2 without prorata, 3 a monthly add-on, 4 a one-time add-on. */
function prorataCart(array $lines): array
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE product (id INTEGER PRIMARY KEY, config TEXT, is_addon INTEGER, product_payment_id INTEGER)');
    $db->executeStatement('CREATE TABLE product_payment (id INTEGER PRIMARY KEY, type TEXT)');
    $db->insert('product_payment', ['id' => 1, 'type' => 'recurrent']);
    $db->insert('product_payment', ['id' => 2, 'type' => 'once']);
    $db->insert('product', ['id' => 1, 'config' => json_encode(['prorata_day' => 1, 'prorata_charge_next' => 25]), 'is_addon' => 0, 'product_payment_id' => 1]);
    $db->insert('product', ['id' => 2, 'config' => '{}', 'is_addon' => 0, 'product_payment_id' => 1]);
    $db->insert('product', ['id' => 3, 'config' => '{}', 'is_addon' => 1, 'product_payment_id' => 1]);
    $db->insert('product', ['id' => 4, 'config' => '{}', 'is_addon' => 1, 'product_payment_id' => 2]);

    $cart = new Cart();
    (new ReflectionProperty($cart, 'id'))->setValue($cart, 20);
    $items = [];
    $products = Mockery::mock(ProductService::class);
    foreach ($lines as $id => [$productId, $config, $price]) {
        $item = createEntity(CartProduct::class);
        $item->id = $id;
        $item->cart_id = 20;
        $item->product_id = $productId;
        $item->config = json_encode($config);
        $items[$id] = $item;
        $products->shouldReceive('getCartProductViewData')->with($item)->andReturn([
            'product_id' => $productId, 'form_id' => null, 'type' => 'custom', 'quantity' => 1, 'unit' => 'service',
            'price' => $price, 'setup_price' => 0.0, 'options_setup_price' => 0.0, 'title' => 'Product ' . $productId, 'config' => $config,
        ]);
    }
    $products->shouldReceive('getRelatedProductDiscountByProductId')->andReturn(0.0);
    $cartRepo = Mockery::mock(CartRepository::class);
    $cartRepo->shouldReceive('find')->andReturn($cart);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getRepository')->with(Cart::class)->andReturn($cartRepo);
    $em->shouldReceive('getRepository')->with(CartProduct::class)->andReturn(Mockery::mock(CartProductRepository::class, ['findByCartId' => array_values($items)]));

    $di = container();
    $di['em'] = $em;
    $di['dbal'] = $db;
    $di['mod_service'] = $di->protect(fn (string $name) => $name === 'Product' ? $products : throw new RuntimeException('Unexpected service request'));
    $service = new Service();
    $service->setDi($di);

    return [$service, $cart, $items];
}

test('a prorata product is priced to its prorata day and keeps its full price for the renewals', function (): void {
    [$service, $cart, $items] = prorataCart([1 => [1, ['period' => '1M'], 20.0]]);
    $term = Proration::prorata('1M', 1, 25, date('Y-m-d H:i:s'));
    $line = $service->cartProductToApiArray($items[1], $cart, array_values($items));
    $later = Proration::prorata('1M', 1, 25, date('Y-m-d H:i:s'))['due'];

    expect($line['price'])->toBe(20.0 * $term['factor'])
        ->and($line['total'])->toBe(20.0 * $term['factor'])
        ->and($line['recurring_price'])->toBe(20.0)
        ->and($line['addon_due'])->toBeGreaterThanOrEqual($term['due'])->toBeLessThanOrEqual($later)
        ->and(substr((string) $line['addon_due'], 8, 2))->toBe('01')
        ->and($line['prorata_day'])->toBe(1);
});

test('a product without the setting, or a client\'s own keys, change nothing', function (): void {
    // A client cannot claim a first due date or a prorata day through the cart's data.
    [$service, $cart, $items] = prorataCart([1 => [2, ['period' => '1M', 'addon_due' => '2020-01-01 00:00:00', 'prorata_day' => 5], 20.0]]);
    $line = $service->cartProductToApiArray($items[1], $cart, array_values($items));

    expect($line['price'])->toBe(20.0)
        ->and($line['recurring_price'])->toBe(20.0)
        ->and($line['addon_due'])->toBeNull()
        ->and($line['prorata_day'])->toBeNull();
});

test('an add-on ordered with a prorata product follows its day; a one-time add-on is charged in full', function (): void {
    $family = [Service::CART_FAMILY_KEY => 'f1'];
    [$service, $cart, $items] = prorataCart([
        1 => [1, ['period' => '1M'] + $family, 20.0],
        2 => [3, ['period' => '1M'] + $family, 6.0],
        3 => [4, ['period' => '1M'] + $family, 9.0],
        // Another family: the add-on of a product ordered on its own is not prorated.
        4 => [3, ['period' => '1M', Service::CART_FAMILY_KEY => 'f2'], 6.0],
    ]);
    $term = Proration::prorata('1M', 1, 25, date('Y-m-d H:i:s'));
    $addon = $service->cartProductToApiArray($items[2], $cart, array_values($items));
    $later = Proration::prorata('1M', 1, 25, date('Y-m-d H:i:s'))['due'];

    expect($addon['price'])->toBe(6.0 * $term['factor'])
        ->and($addon['addon_due'])->toBeGreaterThanOrEqual($term['due'])->toBeLessThanOrEqual($later)
        ->and($addon['prorata_day'])->toBe(1)
        ->and($service->cartProductToApiArray($items[3], $cart, array_values($items))['price'])->toBe(9.0)
        ->and($service->cartProductToApiArray($items[4], $cart, array_values($items))['addon_due'])->toBeNull();
});

test('a backdated staff order is prorated from its start date', function (): void {
    $start = date('Y-m-d', strtotime('-40 days'));
    [$service, $cart, $items] = prorataCart([1 => [1, ['period' => '1Y', '_start_date' => $start], 120.0]]);
    $term = Proration::prorata('1Y', 1, 25, $start . date(' H:i:s'));
    $line = $service->cartProductToApiArray($items[1], $cart, array_values($items));

    expect($line['price'])->toBe(120.0 * $term['factor'])
        ->and(substr((string) $line['addon_due'], 0, 10))->toBe(substr($term['due'], 0, 10));
});
