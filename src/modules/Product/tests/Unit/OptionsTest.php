<?php

declare(strict_types=1);

/**
 * ClientFence: configurable options (Product\Options) on an in-memory SQLite copy of their tables.
 *
 * Catalog made by optionsCatalog():
 * - group 1 "VPS resources" on products 10 and 11:
 *   - RAM (select, key ram): 2 GB (1M 0, 1Y 0), 4 GB (1M 5, 1Y 50, setup 1M 2), 8 GB hidden (1M 15)
 *   - Extra IPs (quantity, 0 to 4, unit IP): 1M 2 per IP, 1Y 20, once 3
 *   - Backups (yesno): 1M 3, 1Y 30
 *   - Old disk (select, hidden option): 50 GB (1M 1)
 * - group 2 "Support" on product 11: Priority (radio): Normal (0), Fast (1M 10)
 */

use ClientFence\InformationException;
use ClientFence\Mod\Product\Entity\Product;
use ClientFence\Mod\Product\Entity\ProductPayment;
use ClientFence\Mod\Product\Entity\Promo;
use ClientFence\Mod\Product\Options;
use ClientFence\Mod\Product\Repository\ProductPaymentRepository;
use ClientFence\Mod\Product\Service;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

function optionsDb(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $prices = implode(', ', array_map(fn (string $k): string => "{$k}_price REAL NOT NULL DEFAULT 0, {$k}_setup_price REAL NOT NULL DEFAULT 0", Options::PRICE_KEYS));
    foreach ([
        'CREATE TABLE product_option_group (id INTEGER PRIMARY KEY, title TEXT NOT NULL, description TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)',
        'CREATE TABLE product_option_group_product (group_id INTEGER NOT NULL, product_id INTEGER NOT NULL, PRIMARY KEY (group_id, product_id))',
        'CREATE TABLE product_option (id INTEGER PRIMARY KEY, group_id INTEGER NOT NULL, title TEXT NOT NULL, code TEXT NOT NULL DEFAULT \'\', type TEXT NOT NULL, qty_min INTEGER NOT NULL DEFAULT 0,'
            . ' qty_max INTEGER NOT NULL DEFAULT 0, unit TEXT NOT NULL DEFAULT \'\', hidden INTEGER NOT NULL DEFAULT 0, position INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)',
        "CREATE TABLE product_option_value (id INTEGER PRIMARY KEY, option_id INTEGER NOT NULL, title TEXT NOT NULL, code TEXT NOT NULL DEFAULT '', hidden INTEGER NOT NULL DEFAULT 0, position INTEGER NOT NULL DEFAULT 0, {$prices}, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)",
        'CREATE TABLE promo_option (promo_id INTEGER PRIMARY KEY, include_options INTEGER NOT NULL DEFAULT 0, updated_at TEXT NOT NULL)',
    ] as $sql) {
        $db->executeStatement($sql);
    }

    return $db;
}

function optionsContainer(Connection $db): Pimple\Container
{
    $di = new Pimple\Container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;

    return $di;
}

/** @return array{di: Pimple\Container, db: Connection, ram: int, ips: int, backups: int, old: int, priority: int} */
function optionsCatalog(): array
{
    $db = optionsDb();
    $di = optionsContainer($db);
    $vps = Options::saveGroup($di, ['title' => 'VPS resources']);
    $support = Options::saveGroup($di, ['title' => 'Support']);
    $ram = Options::saveOption($di, ['group_id' => $vps, 'title' => 'RAM', 'code' => 'ram', 'type' => 'select', 'values' => [
        ['title' => '2 GB', 'code' => '2048'],
        ['title' => '4 GB', 'code' => '4096', 'm_price' => '5', 'a_price' => '50', 'm_setup_price' => '2'],
        ['title' => '8 GB', 'code' => '8192', 'm_price' => '15', 'hidden' => true],
    ]]);
    $ips = Options::saveOption($di, ['group_id' => $vps, 'title' => 'Extra IPs', 'code' => 'ips', 'type' => 'quantity', 'qty_min' => '0', 'qty_max' => '4', 'unit' => 'IP', 'position' => 1,
        'values' => [['m_price' => '2', 'a_price' => '20', 'once_price' => '3']]]);
    $backups = Options::saveOption($di, ['group_id' => $vps, 'title' => 'Backups', 'type' => 'yesno', 'position' => 2, 'values' => [['m_price' => '3', 'a_price' => '30']]]);
    $old = Options::saveOption($di, ['group_id' => $vps, 'title' => 'Old disk', 'type' => 'select', 'hidden' => '1', 'position' => 3, 'values' => [['title' => '50 GB', 'm_price' => '1']]]);
    $priority = Options::saveOption($di, ['group_id' => $support, 'title' => 'Priority', 'type' => 'radio', 'values' => [['title' => 'Normal'], ['title' => 'Fast', 'm_price' => '10']]]);
    Options::setProductGroups($di, 10, [$vps]);
    Options::setProductGroups($di, 11, [$vps, $support]);

    return ['di' => $di, 'db' => $db, 'ram' => $ram, 'ips' => $ips, 'backups' => $backups, 'old' => $old, 'priority' => $priority];
}

/** The value id of an option's value by its title. */
function optionsValue(Pimple\Container $di, int $optionId, string $title): int
{
    return (int) $di['em']->getConnection()->fetchOne('SELECT id FROM product_option_value WHERE option_id = ? AND title = ?', [$optionId, $title]);
}

test('groups list their options and products; a product lists its options in order', function (): void {
    $c = optionsCatalog();

    expect(array_map(fn (array $g): array => [$g['title'], $g['options'], $g['products']], Options::groups($c['di'])))
        ->toBe([['Support', 1, [11]], ['VPS resources', 4, [10, 11]]])
        ->and(array_column(Options::forProduct($c['di'], 11), 'title'))->toBe(['RAM', 'Extra IPs', 'Backups', 'Priority'])
        ->and(array_column(Options::forProduct($c['di'], 11, true), 'title'))->toBe(['RAM', 'Extra IPs', 'Backups', 'Old disk', 'Priority'])
        // The order form leaves out hidden values.
        ->and(array_column(Options::forProduct($c['di'], 10)[0]['values'], 'title'))->toBe(['2 GB', '4 GB'])
        ->and(Options::forProduct($c['di'], 99))->toBe([])
        ->and(Options::productGroups($c['di'], 11))->toBe([1, 2]);
});

test('defaults: the first value, no, and the minimum quantity', function (): void {
    $c = optionsCatalog();
    $r = Options::resolve($c['di'], 10, [], '1M');

    expect($r['choice'])->toBe([$c['ram'] => optionsValue($c['di'], $c['ram'], '2 GB'), $c['ips'] => 0, $c['backups'] => 0])
        ->and($r['price'])->toBe(0.0)
        ->and($r['lines'])->toHaveCount(1)
        ->and($r['lines'][0])->toMatchArray(['code' => 'ram', 'value' => '2 GB', 'value_code' => '2048', 'price' => 0.0]);
});

test('a choice is priced for the billing period, with setup fees and quantities', function (): void {
    $c = optionsCatalog();
    $choice = [$c['ram'] => optionsValue($c['di'], $c['ram'], '4 GB'), $c['ips'] => '3', $c['backups'] => '1'];

    $monthly = Options::resolve($c['di'], 10, $choice, '1M');
    $yearly = Options::resolve($c['di'], 10, $choice, '1Y');
    $once = Options::resolve($c['di'], 10, $choice, 'once');

    expect([$monthly['price'], $monthly['setup_price']])->toBe([5.0 + 6.0 + 3.0, 2.0])
        ->and([$yearly['price'], $yearly['setup_price']])->toBe([50.0 + 60.0 + 30.0, 0.0])
        ->and($once['price'])->toBe(9.0)
        ->and(Options::summary($monthly['lines']))->toBe('RAM: 4 GB, Extra IPs: 3 IP, Backups')
        ->and(Options::titleWith('VPS', $monthly['lines']))->toBe('VPS (RAM: 4 GB, Extra IPs: 3 IP, Backups)');
});

test('bad choices are refused', function (): void {
    $c = optionsCatalog();

    expect(fn () => Options::resolve($c['di'], 10, [$c['priority'] => 1], '1M'))->toThrow(InformationException::class, 'not available for this product')
        ->and(fn () => Options::resolve($c['di'], 10, [$c['ram'] => 999], '1M'))->toThrow(InformationException::class, 'Choose a value for RAM')
        ->and(fn () => Options::resolve($c['di'], 10, [$c['ips'] => 5], '1M'))->toThrow(InformationException::class, 'between 0 and 4')
        ->and(fn () => Options::resolve($c['di'], 10, [$c['ips'] => '1.5'], '1M'))->toThrow(InformationException::class, 'whole number')
        ->and(fn () => Options::resolve($c['di'], 10, [], '5M'))->toThrow(InformationException::class, 'Unknown billing period');
});

test('hidden choices: refused on new orders, kept on a service that has them, any for staff', function (): void {
    $c = optionsCatalog();
    $eight = optionsValue($c['di'], $c['ram'], '8 GB');
    $disk = optionsValue($c['di'], $c['old'], '50 GB');

    expect(fn () => Options::resolve($c['di'], 10, [$c['ram'] => $eight], '1M'))->toThrow(InformationException::class, '8 GB is no longer offered')
        ->and(fn () => Options::resolve($c['di'], 10, [$c['old'] => $disk], '1M'))->toThrow(InformationException::class, 'Old disk is no longer offered')
        ->and(Options::resolve($c['di'], 10, [$c['ram'] => $eight, $c['old'] => $disk], '1M', [$c['ram'] => $eight, $c['old'] => $disk])['price'])->toBe(16.0)
        // A service that has a hidden option keeps it when the client changes something else.
        ->and(Options::resolve($c['di'], 10, [$c['backups'] => 1], '1M', [$c['old'] => $disk])['price'])->toBe(4.0)
        ->and(Options::resolve($c['di'], 10, [$c['ram'] => $eight], '1M', [], true)['price'])->toBe(15.0);
});

test('options are saved with their values; a value left out is deleted', function (): void {
    $c = optionsCatalog();
    $two = optionsValue($c['di'], $c['ram'], '2 GB');
    Options::saveOption($c['di'], ['id' => $c['ram'], 'title' => 'Memory', 'code' => 'ram', 'type' => 'select', 'values' => [
        ['id' => $two, 'title' => '2 GB', 'm_price' => '1'],
        ['title' => '16 GB', 'm_price' => '30'],
    ]]);
    $option = Options::group($c['di'], 1)['options'][0];

    expect($option['title'])->toBe('Memory')
        ->and(array_column($option['values'], 'title'))->toBe(['2 GB', '16 GB'])
        ->and($option['values'][0]['id'])->toBe($two)
        ->and($option['values'][0]['prices']['m'])->toBe(['price' => 1.0, 'setup_price' => 0.0]);
});

test('an option is checked before it is saved', function (array $data, string $message): void {
    $c = optionsCatalog();
    expect(fn () => Options::saveOption($c['di'], $data + ['group_id' => 1]))->toThrow(InformationException::class, $message);
})->with([
    'no kind' => [['title' => 'X', 'values' => [['title' => 'a']]], 'Choose the kind of option'],
    'no values' => [['title' => 'X', 'type' => 'select', 'values' => []], 'at least one value'],
    'yes/no with two values' => [['title' => 'X', 'type' => 'yesno', 'values' => [['m_price' => 1], ['m_price' => 2]]], 'exactly one value'],
    'bad key' => [['title' => 'X', 'code' => 'a b', 'type' => 'select', 'values' => [['title' => 'a']]], 'A key may only have'],
    'negative price' => [['title' => 'X', 'type' => 'select', 'values' => [['title' => 'a', 'm_price' => '-1']]], 'Prices must be'],
    'max below min' => [['title' => 'X', 'type' => 'quantity', 'qty_min' => '5', 'qty_max' => '2', 'values' => [['m_price' => 1]]], 'maximum cannot be below'],
    'no name' => [['title' => ' ', 'type' => 'select', 'values' => [['title' => 'a']]], 'Option name is required'],
]);

test('the kind of an option cannot change and an unknown value id is refused', function (): void {
    $c = optionsCatalog();

    expect(fn () => Options::saveOption($c['di'], ['id' => $c['ram'], 'title' => 'RAM', 'type' => 'radio', 'values' => [['title' => 'a']]]))->toThrow(InformationException::class, 'cannot change')
        ->and(fn () => Options::saveOption($c['di'], ['id' => $c['ram'], 'title' => 'RAM', 'type' => 'select', 'values' => [['id' => 999, 'title' => 'a']]]))->toThrow(InformationException::class, 'Value not found');
});

test('deleting a group removes its options, values and product links', function (): void {
    $c = optionsCatalog();
    Options::deleteGroup($c['di'], 1);

    expect((int) $c['db']->fetchOne('SELECT COUNT(*) FROM product_option'))->toBe(1)
        ->and((int) $c['db']->fetchOne('SELECT COUNT(*) FROM product_option_value'))->toBe(2)
        ->and(Options::productGroups($c['di'], 10))->toBe([])
        ->and(fn () => Options::setProductGroups($c['di'], 10, [1]))->toThrow(InformationException::class, 'Option group not found');
});

test('a promotion covers the options only when switched on', function (): void {
    $c = optionsCatalog();
    expect(Options::promoCoversOptions($c['di'], 4))->toBeFalse();
    Options::setPromoCoversOptions($c['di'], 4, true);
    expect(Options::promoCoversOptions($c['di'], 4))->toBeTrue();
    Options::setPromoCoversOptions($c['di'], 4, false);
    expect(Options::promoCoversOptions($c['di'], 4))->toBeFalse();
});

test('without the tables no product has options', function (): void {
    $di = optionsContainer(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));

    expect(Options::forProduct($di, 10))->toBe([])
        ->and(Options::resolve($di, 10, [], '1M')['price'])->toBe(0.0)
        ->and(Options::promoCoversOptions($di, 1))->toBeFalse();
});

test('a long summary is cut to fit an invoice line', function (): void {
    $lines = array_map(fn (int $i): array => ['title' => 'Option ' . $i, 'type' => 'select', 'value' => str_repeat('x', 20)], range(1, 20));
    $title = Options::titleWith('VPS', $lines);

    expect(mb_strlen($title))->toBe(255)
        ->and($title)->toEndWith('…)');
});

test('the test tables have the columns of the real tables', function (): void {
    $schema = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/Clientfence/schema.json'), true)['tables'];
    $db = optionsDb();
    foreach (['product_option_group', 'product_option_group_product', 'product_option', 'product_option_value', 'promo_option'] as $table) {
        preg_match_all('/^\s*`([a-z_]+)` /m', str_replace([', `', ' (`'], [",\n`", " (\n`"], $schema[$table]), $m);
        expect(array_column($db->fetchAllAssociative("PRAGMA table_info({$table})"), 'name'))->toBe($m[1]);
    }
});

/**
 * Product\Service on the catalog: product 10 (or 12, without options) costs 10 a month with no setup
 * fee; the order line, the cart config and promotions are the real code.
 *
 * @return Mockery\MockInterface&Service
 */
function optionsProductService(Pimple\Container $di): Service
{
    $payment = new ProductPayment();
    $payment->setType(ProductPayment::RECURRENT);
    $repo = Mockery::mock(ProductPaymentRepository::class);
    $repo->shouldReceive('find')->andReturn($payment);
    $di['em']->shouldReceive('getRepository')->with(ProductPayment::class)->andReturn($repo);

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('getProductPrice')->andReturn(10.0);
    $service->shouldReceive('getProductSetupPrice')->andReturn(0.0);
    $service->shouldReceive('getProductModuleService')->andReturn(new stdClass());
    $service->setDi($di);

    return $service;
}

function optionsProduct(int $id): Product
{
    $product = (new Product())->setType(Service::CUSTOM)->setProductPaymentId(1);
    (new ReflectionProperty($product, 'id'))->setValue($product, $id);

    return $product;
}

function optionsPromo(int $id, string $type, float $value): Promo
{
    $promo = (new Promo())->setType($type)->setValue($value);
    (new ReflectionProperty($promo, 'id'))->setValue($promo, $id);

    return $promo;
}

test('an order line adds the chosen options; a service keeps the lines it was sold with', function (): void {
    $c = optionsCatalog();
    $service = optionsProductService($c['di']);
    $choice = [$c['ram'] => optionsValue($c['di'], $c['ram'], '4 GB'), $c['ips'] => 2, $c['backups'] => 1];

    $line = $service->getProductOrderLineConfig(optionsProduct(10), ['period' => '1M', 'options' => $choice]);
    expect($line['price'])->toBe(22.0)
        ->and($line['setup_price'])->toBe(2.0)
        ->and($line['options_price'])->toBe(12.0)
        ->and(array_column($line['option_lines'], 'title'))->toBe(['RAM', 'Extra IPs', 'Backups']);

    // Catalog prices do not reprice a service: its stored lines win over the choice.
    $stored = $service->getProductOrderLineConfig(optionsProduct(10), ['period' => '1M', 'options' => $choice, 'option_lines' => [['title' => 'RAM', 'price' => 7, 'setup_price' => 1]]]);
    expect($stored['price'])->toBe(17.0)
        ->and($stored['setup_price'])->toBe(1.0)
        ->and($stored['options_price'])->toBe(7.0);

    // Without options the line is FOSSBilling's.
    expect($service->getProductOrderLineConfig(optionsProduct(12), ['period' => '1M']))->toBe(['price' => 10.0, 'quantity' => 1, 'setup_price' => 0.0]);
});

test('the cart keeps a checked choice and never the priced lines sent by the client', function (): void {
    $c = optionsCatalog();
    $service = optionsProductService($c['di']);

    $config = $service->prepareCartProductConfig(optionsProduct(10), ['period' => '1M', 'options' => [$c['ips'] => 3], 'option_lines' => [['title' => 'Free', 'price' => -50]]]);
    expect($config)->not->toHaveKey('option_lines')
        ->and($config['options'])->toBe([$c['ram'] => optionsValue($c['di'], $c['ram'], '2 GB'), $c['ips'] => 3, $c['backups'] => 0]);

    expect(fn () => $service->prepareCartProductConfig(optionsProduct(10), ['period' => '1M', 'options' => [$c['ips'] => 9]]))
        ->toThrow(InformationException::class);

    // A product without options drops a choice sent for it.
    expect($service->prepareCartProductConfig(optionsProduct(12), ['period' => '1M', 'options' => [$c['ips'] => 3]]))->toBe(['period' => '1M']);
});

test('a promotion leaves the options out unless it covers them', function (): void {
    $c = optionsCatalog();
    $service = optionsProductService($c['di']);
    // 10 for the product + 12 for the options.
    $config = ['period' => '1M', 'options' => [$c['ram'] => optionsValue($c['di'], $c['ram'], '4 GB'), $c['ips'] => 2, $c['backups'] => 1]];
    $percent = optionsPromo(1, Promo::PERCENTAGE, 10);
    $fixed = optionsPromo(2, Promo::ABSOLUTE, 15);

    expect($service->getProductDiscount(optionsProduct(10), $percent, $config))->toBe(1.0)
        ->and($service->getRenewalProductDiscount(optionsProduct(10), $percent, $config))->toBe(1.0)
        // A fixed amount stops at the product's own price.
        ->and($service->getProductDiscount(optionsProduct(10), $fixed, $config))->toBe(10.0)
        ->and($service->getRenewalProductDiscount(optionsProduct(10), $fixed, $config))->toBe(10.0);

    Options::setPromoCoversOptions($c['di'], 1, true);
    Options::setPromoCoversOptions($c['di'], 2, true);
    expect($service->getProductDiscount(optionsProduct(10), $percent, $config))->toBe(2.2)
        ->and($service->getRenewalProductDiscount(optionsProduct(10), $percent, $config))->toBe(2.2)
        ->and($service->getProductDiscount(optionsProduct(10), $fixed, $config))->toBe(15.0);

    // Without options a fixed amount is FOSSBilling's: not capped.
    expect($service->getProductDiscount(optionsProduct(12), $fixed, ['period' => '1M']))->toBe(15.0);
});
