<?php

declare(strict_types=1);

/**
 * ClientFence: fixed currency prices (Product\CurrencyPrices) on an in-memory SQLite database.
 *
 * Currencies: USD (default), EGP (rate 51.81), EUR (rate 0.92, no fixed prices). Product 10 costs
 * 10 USD a month (setup 5) and 100 a year; it has a fixed EGP monthly price of 500 (setup 250).
 */

use ClientFence\InformationException;
use ClientFence\Mod\Order\Upgrades;
use ClientFence\Mod\Product\CurrencyPrices;
use ClientFence\Mod\Product\Entity\Product;
use ClientFence\Mod\Product\Entity\ProductPayment;
use ClientFence\Mod\Product\Repository\ProductPaymentRepository;
use ClientFence\Mod\Product\Service;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

function currencyPricesDb(): Connection
{
    CurrencyPrices::reset();
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE currency (id INTEGER PRIMARY KEY, code TEXT NOT NULL, conversion_rate REAL NOT NULL, is_default INTEGER NOT NULL DEFAULT 0)');
    $db->executeStatement('CREATE TABLE product_currency_price (id INTEGER PRIMARY KEY, kind TEXT NOT NULL, ref_id INTEGER NOT NULL, currency TEXT NOT NULL, period TEXT NOT NULL,'
        . ' price REAL NULL, setup_price REAL NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (kind, ref_id, currency, period))');
    $db->insert('currency', ['code' => 'USD', 'conversion_rate' => 1, 'is_default' => 1]);
    $db->insert('currency', ['code' => 'EGP', 'conversion_rate' => 51.81]);
    $db->insert('currency', ['code' => 'EUR', 'conversion_rate' => 0.92]);

    return $db;
}

function currencyPricesService(Connection $db): Service
{
    $payment = new ProductPayment();
    $payment->setType(ProductPayment::RECURRENT);
    $payment->setPeriodPricing('m', 10.0, 5.0, true);
    $payment->setPeriodPricing('a', 100.0, 0.0, true);
    foreach (['w', 'q', 'b', 'bia', 'tria'] as $key) {
        $payment->setPeriodPricing($key, 0.0, 0.0, false);
    }
    $repo = Mockery::mock(ProductPaymentRepository::class);
    $repo->shouldReceive('find')->andReturn($payment);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $em->shouldReceive('getRepository')->with(ProductPayment::class)->andReturn($repo);
    $di = new Pimple\Container();
    $di['em'] = $em;
    $di['dbal'] = $db;
    $service = new Service();
    $service->setDi($di);

    return $service;
}

function currencyPricesProduct(int $id = 10): Product
{
    $product = (new Product())->setType(Service::CUSTOM)->setProductPaymentId(1);
    (new ReflectionProperty($product, 'id'))->setValue($product, $id);

    return $product;
}

test('fixed prices are saved per currency and period; blank converts; the default currency is never fixed', function (): void {
    $db = currencyPricesDb();
    CurrencyPrices::save($db, 'product', 10, ['egp' => ['1M' => ['price' => '500', 'setup_price' => '250'], '1Y' => ['price' => '', 'setup_price' => '']]]);

    expect(CurrencyPrices::forItem($db, 'product', 10, 'EGP'))->toBe(['1M' => ['price' => 500.0, 'setup_price' => 250.0]])
        ->and(CurrencyPrices::rate($db, 'EGP'))->toBe(51.81)
        // No fixed price in EUR, and USD is the default: both convert.
        ->and(CurrencyPrices::rate($db, 'EUR'))->toBeNull()
        ->and(CurrencyPrices::rate($db, 'USD'))->toBeNull()
        ->and(CurrencyPrices::base($db, 'product', 10, 'EGP', '1M', 'price', 10.0) * 51.81)->toEqualWithDelta(500.0, 1e-9)
        ->and(CurrencyPrices::base($db, 'product', 10, 'EGP', '1Y', 'price', 100.0))->toBe(100.0)
        ->and(CurrencyPrices::base($db, 'product', 10, 'USD', '1M', 'price', 10.0))->toBe(10.0);

    $page = CurrencyPrices::page($db, 'product', 10);
    expect($page['default'])->toBe('USD')
        ->and(array_column($page['currencies'], 'code'))->toBe(['EGP', 'EUR'])
        ->and($page['currencies'][0]['prices'])->toBe(['1M' => ['price' => 500.0, 'setup_price' => 250.0]]);

    // Saving a currency again replaces its rows; a currency left out keeps them.
    CurrencyPrices::save($db, 'product', 10, ['EGP' => ['1Y' => ['price' => '4800']]]);
    CurrencyPrices::save($db, 'product', 10, ['EUR' => []]);
    expect(CurrencyPrices::forItem($db, 'product', 10, 'EGP'))->toBe(['1Y' => ['price' => 4800.0, 'setup_price' => null]]);

    expect(fn () => CurrencyPrices::save($db, 'product', 10, ['USD' => ['1M' => ['price' => '9']]]))->toThrow(InformationException::class)
        ->and(fn () => CurrencyPrices::save($db, 'product', 10, ['GBP' => ['1M' => ['price' => '9']]]))->toThrow(InformationException::class)
        ->and(fn () => CurrencyPrices::save($db, 'product', 10, ['EGP' => ['5Y' => ['price' => '9']]]))->toThrow(InformationException::class)
        ->and(fn () => CurrencyPrices::save($db, 'product', 10, ['EGP' => ['1M' => ['price' => '-1']]]))->toThrow(InformationException::class)
        ->and(fn () => CurrencyPrices::save($db, 'tld', 3, ['EGP' => ['1M' => ['price' => '9']]]))->toThrow(InformationException::class)
        ->and(fn () => CurrencyPrices::save($db, 'option', 3, []))->toThrow(InformationException::class);
    // A failed save changes nothing.
    expect(CurrencyPrices::forItem($db, 'product', 10, 'EGP'))->toBe(['1Y' => ['price' => 4800.0, 'setup_price' => null]]);

    CurrencyPrices::forget($db, 'product', 10);
    expect(CurrencyPrices::forItem($db, 'product', 10, 'EGP'))->toBe([]);
});

test('a TLD has a fixed price per action, without setup fees; a deleted currency drops its prices', function (): void {
    $db = currencyPricesDb();
    CurrencyPrices::save($db, 'tld', 3, ['EGP' => ['register' => ['price' => '950', 'setup_price' => '7'], 'renew' => ['price' => '1000']]]);

    $tld = CurrencyPrices::tld($db, 'EGP', ['id' => 3, 'tld' => '.com', 'price_registration' => '19.00', 'price_renew' => '19.00', 'price_transfer' => '19.00']);
    expect(round($tld['price_registration'] * 51.81, 2))->toBe(950.0)
        ->and(round($tld['price_renew'] * 51.81, 2))->toBe(1000.0)
        ->and($tld['price_transfer'])->toBe(19.0)
        ->and(CurrencyPrices::forItem($db, 'tld', 3, 'EGP')['register']['setup_price'])->toBeNull()
        ->and(CurrencyPrices::tld($db, 'USD', ['id' => 3, 'price_registration' => '19.00'])['price_registration'])->toBe('19.00');

    CurrencyPrices::forgetCurrency($db, 'EGP');
    expect(CurrencyPrices::rate($db, 'EGP'))->toBeNull();
});

test('a product priced in a currency lands on its fixed prices after conversion', function (): void {
    $db = currencyPricesDb();
    CurrencyPrices::save($db, 'product', 10, ['EGP' => ['1M' => ['price' => '500', 'setup_price' => '250']]]);
    $service = currencyPricesService($db);
    $product = currencyPricesProduct();

    // The default currency and a currency without fixed prices keep the service as it is.
    expect($service->inCurrency('USD'))->toBe($service)
        ->and($service->inCurrency('EUR'))->toBe($service)
        ->and($service->inCurrency(null))->toBe($service);

    $egp = $service->inCurrency('egp');
    $monthly = $egp->getProductOrderLineConfig($product, ['period' => '1M']);
    expect(round($monthly['price'] * 51.81, 2))->toBe(500.0)
        ->and(round($monthly['setup_price'] * 51.81, 2))->toBe(250.0)
        // A period without a fixed price converts.
        ->and($egp->getProductOrderLineConfig($product, ['period' => '1Y'])['price'])->toBe(100.0)
        // The service itself is unchanged.
        ->and($service->getProductOrderLineConfig($product, ['period' => '1M'])['price'])->toBe(10.0);

    // The store's pricing and lowest price in that currency.
    $pricing = $egp->getProductPricingArray($product);
    expect(round($pricing['recurrent']['1M']['price'] * 51.81, 2))->toBe(500.0)
        ->and(round($pricing['recurrent']['1M']['setup'] * 51.81, 2))->toBe(250.0)
        ->and($pricing['recurrent']['1Y']['price'])->toBe(100.0)
        ->and(round($egp->getStartingFromPrice($product) * 51.81, 2))->toBe(500.0)
        ->and($service->getProductPricingArray($product)['recurrent']['1M']['price'])->toBe(10.0);

    // The fixed price stays when the rate changes.
    $db->update('currency', ['conversion_rate' => 60.0], ['code' => 'EGP']);
    CurrencyPrices::reset();
    $line = $service->inCurrency('EGP')->getProductOrderLineConfig($product, ['period' => '1M']);
    expect(round($line['price'] * 60.0, 2))->toBe(500.0);
});

test('upgrades and bulk price updates read the fixed prices of the order currency', function (): void {
    $db = currencyPricesDb();
    $db->executeStatement('CREATE TABLE product (id INTEGER PRIMARY KEY, title TEXT, type TEXT, status TEXT, is_addon INTEGER, config TEXT, upgrades TEXT, product_payment_id INTEGER)');
    $columns = implode(', ', array_map(fn (string $k): string => "{$k}_price REAL NOT NULL DEFAULT 0, {$k}_enabled INTEGER NOT NULL DEFAULT 0", array_keys(Upgrades::PERIODS)));
    $db->executeStatement("CREATE TABLE product_payment (id INTEGER PRIMARY KEY, type TEXT, {$columns})");
    $db->insert('product_payment', ['id' => 1, 'type' => 'recurrent', 'm_price' => 10, 'm_enabled' => 1, 'a_price' => 100, 'a_enabled' => 1]);
    $db->insert('product', ['id' => 10, 'title' => 'Licence', 'type' => 'license', 'status' => 'enabled', 'is_addon' => 0, 'config' => '{}', 'upgrades' => '[]', 'product_payment_id' => 1]);
    CurrencyPrices::save($db, 'product', 10, ['EGP' => ['1Y' => ['price' => '4800']]]);

    $egp = Upgrades::catalog($db, 10, true, 'EGP');
    expect($egp['prices']['1M'])->toBe(10.0)
        ->and(round($egp['prices']['1Y'] * 51.81, 2))->toBe(4800.0)
        ->and(Upgrades::catalog($db, 10)['prices'])->toBe(['1M' => 10.0, '1Y' => 100.0]);
});
