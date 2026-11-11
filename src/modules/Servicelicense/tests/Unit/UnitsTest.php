<?php

declare(strict_types=1);

/**
 * ClientFence: per-unit billing of licences (Servicelicense\Units): usage reports, the order price,
 * held drops, the warning and the suspension when reports stop, on an in-memory SQLite install with
 * one licence (IP 203.0.113.5 bound) of order 7 (product 90, monthly, quantity 1, USD).
 */

use ClientFence\Mod\Order\Entity\Order;
use ClientFence\Mod\Servicelicense\Entity\ServiceLicense;
use ClientFence\Mod\Servicelicense\Units;
use ClientFence\Mod\Servicelicense\WhmcsCheck;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Symfony\Component\Filesystem\Path;

const UNITS_PRODUCT = ['whmcs_check' => 1, 'unit_name' => 'website', 'unit_price' => '0.10', 'unit_minimum' => '5', 'unit_field' => 'Unit Billing', 'unit_count_field' => 'Units'];

/**
 * @return array{0: Pimple\Container, 1: ServiceLicense, 2: Order, 3: Mockery\MockInterface}
 */
function unitsFixture(array $product = UNITS_PRODUCT, array $units = ['billing' => true, 'since' => '2026-11-01 00:00:00']): array
{
    $config = ORMSetup::createAttributeMetadataConfig([Path::join(__DIR__, '..', '..', 'Entity'), Path::join(__DIR__, '..', '..', '..', 'Order', 'Entity')], true);
    $config->setProxyDir(sys_get_temp_dir());
    $config->setProxyNamespace('ClientFence\\Tests\\DoctrineProxies');
    $em = new EntityManager(Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
    $db = $em->getConnection();
    // Both tables name an index client_id_idx; SQLite index names are global.
    foreach ((new Doctrine\ORM\Tools\SchemaTool($em))->getCreateSchemaSql([$em->getClassMetadata(ServiceLicense::class), $em->getClassMetadata(Order::class)]) as $sql) {
        $db->executeStatement((string) preg_replace('/CREATE (UNIQUE )?INDEX (\w+) ON (\w+)/', 'CREATE $1INDEX $3_$2 ON $3', $sql));
    }
    $db->executeStatement('CREATE TABLE setting (id INTEGER PRIMARY KEY AUTOINCREMENT, param TEXT UNIQUE, value TEXT, public INTEGER, category TEXT, hash TEXT, created_at TEXT, updated_at TEXT)');
    $db->executeStatement('CREATE TABLE currency (id INTEGER PRIMARY KEY, code TEXT, conversion_rate REAL)');
    $db->executeStatement('CREATE TABLE service_license_log (id INTEGER PRIMARY KEY AUTOINCREMENT, service_license_id INTEGER NOT NULL, host TEXT NOT NULL, ip TEXT NOT NULL, path TEXT NOT NULL, version TEXT NOT NULL, result TEXT NOT NULL, created_at TEXT NOT NULL)');
    $db->executeStatement('CREATE TABLE mod_servicelicense_ban (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT NOT NULL UNIQUE, notes TEXT NOT NULL, created_at TEXT NOT NULL)');
    $db->executeStatement('CREATE TABLE product_payment (id INTEGER PRIMARY KEY, type TEXT)');
    $db->executeStatement('CREATE TABLE product (id INTEGER PRIMARY KEY, product_payment_id INTEGER)');
    $db->insert('setting', ['param' => Units::SECRET, 'value' => 'the-secret', 'public' => 0]);
    $db->insert('currency', ['id' => 1, 'code' => 'USD', 'conversion_rate' => 1]);
    $db->insert('currency', ['id' => 2, 'code' => 'EUR', 'conversion_rate' => 0.9]);
    $db->insert('product_payment', ['id' => 1, 'type' => 'recurrent']);
    $db->insert('product', ['id' => 90, 'product_payment_id' => 1]);

    $licence = (new ServiceLicense())->setLicenseKey('LIC-KEY1')->setValidateIp(true)->setIps('["203.0.113.5"]')->setHosts('[]')->setPaths('[]')->setVersions('[]')->setConfig(json_encode(['units' => $units]));
    $em->persist($licence);
    $em->flush();
    $db->insert('client_order', ['id' => 7, 'client_id' => 3, 'product_id' => 90, 'service_id' => $licence->getId(), 'service_type' => 'license', 'title' => 'Licence', 'currency' => 'USD', 'period' => '1M', 'quantity' => 1, 'price' => 5, 'status' => Order::STATUS_ACTIVE]);
    $order = $em->find(Order::class, 7);

    $di = new Pimple\Container();
    $di['em'] = $em;
    $di['dbal'] = $db;
    $di['logger'] = new class {
        public function setChannel(string $channel): self
        {
            return $this;
        }

        public function __call(string $name, array $arguments): void
        {
        }
    };
    $service = Mockery::mock(ClientFence\Mod\Servicelicense\Service::class)->makePartial();
    $service->setDi($di);
    $service->shouldReceive('productConfig')->andReturn($product);
    $orders = Mockery::mock();
    $orders->shouldReceive('getServiceOrder')->andReturn($order);
    $email = Mockery::mock();
    $email->shouldReceive('sendTemplate')->byDefault();
    $di['mod_service'] = $di->protect(static fn (string $name): object => match ($name) {
        'order' => $orders,
        'email' => $email,
        default => $service,
    });

    return [$di, $licence, $order, $orders, $email];
}

function unitsReport(Pimple\Container $di, array $data = [], string $ip = '203.0.113.5'): array
{
    return Units::report($di, $data + ['secret' => 'the-secret', 'service_id' => '7', 'product_id' => '90', 'units' => '509', 'ips' => '203.0.113.5,203.0.113.6'], $ip);
}

function unitsPrice(Pimple\Container $di): float
{
    return (float) $di['dbal']->fetchOne('SELECT price FROM client_order WHERE id = 7');
}

test('a report sets the count, the price and the reported servers as the licence IPs', function (): void {
    [$di, $licence] = unitsFixture();

    expect(unitsReport($di))->toBe([200, 'success'])
        ->and(unitsPrice($di))->toBe(50.9)
        ->and($licence->getAllowedIps())->toBe(['203.0.113.5', '203.0.113.6'])
        ->and(Units::of($licence))->toMatchArray(['count' => 509, 'ips' => ['203.0.113.5', '203.0.113.6']]);

    // The minimum, and the order's period and currency.
    expect(unitsReport($di, ['units' => '3']))->toBe([200, 'success'])->and(unitsPrice($di))->toBe(5.0);
    $di['dbal']->update('client_order', ['period' => '3M', 'currency' => 'EUR'], ['id' => 7]);
    $di['em']->clear();
    expect(unitsReport($di, ['units' => '100']))->toBe([200, 'success'])->and(unitsPrice($di))->toBe(27.0);
});

test('a report is refused without the secret, with bad fields, for another product or from a server not on the licence', function (): void {
    [$di] = unitsFixture();

    expect(unitsReport($di, ['secret' => 'wrong']))->toBe([403, 'Unauthorized request'])
        ->and(unitsReport($di, ['service_id' => 'x']))->toBe([400, 'Invalid service ID'])
        ->and(unitsReport($di, ['product_id' => '']))->toBe([400, 'Invalid product ID'])
        ->and(unitsReport($di, ['units' => '-1']))->toBe([400, 'Invalid request data'])
        ->and(unitsReport($di, ['product_id' => '91']))->toBe([403, 'Unauthorized request'])
        ->and(unitsReport($di, [], '198.51.100.9'))->toBe([403, 'Unauthorized request'])
        ->and(unitsPrice($di))->toBe(5.0);
});

test('without the staff switch a report only records the count', function (): void {
    [$di, $licence] = unitsFixture(UNITS_PRODUCT, []);

    expect(unitsReport($di))->toBe([200, 'success'])
        ->and(unitsPrice($di))->toBe(5.0)
        ->and($licence->getAllowedIps())->toBe(['203.0.113.5'])
        ->and(Units::of($licence)['count'])->toBe(509);
});

test('an imported licence is found by its WHMCS ids', function (): void {
    [$di, $licence] = unitsFixture();
    $licence->setConfig(json_encode(['whmcs' => ['service_id' => 1226, 'product_id' => 94], 'units' => ['billing' => true]]));
    $di['em']->flush();

    expect(unitsReport($di))->toBe([403, 'Unauthorized request'])
        ->and(unitsReport($di, ['service_id' => '1226', 'product_id' => '94']))->toBe([200, 'success'])
        ->and(unitsPrice($di))->toBe(50.9);
});

test('a big drop waits for staff; a rise applies at once', function (): void {
    [$di, $licence, $order] = unitsFixture(UNITS_PRODUCT + ['unit_hold' => '1', 'unit_hold_percent' => '50']);
    unitsReport($di, ['units' => '400']);

    expect(unitsReport($di, ['units' => '100']))->toBe([200, 'success'])
        ->and(unitsPrice($di))->toBe(40.0)
        ->and(Units::of($licence)['held'])->toMatchArray(['count' => 100]);

    unitsReport($di, ['units' => '450']);
    expect(unitsPrice($di))->toBe(45.0)->and(Units::of($licence)['held'])->toBeNull();

    unitsReport($di, ['units' => '100']);
    Units::update($di, $licence, $order, ['held' => 'approve']);
    expect(unitsPrice($di))->toBe(10.0)->and(Units::of($licence))->toMatchArray(['count' => 100, 'held' => null]);
});

test('reports that stop warn once per gap, then suspend; an unsuspend starts the time again', function (): void {
    [$di, $licence, $order, $orders, $email] = unitsFixture(UNITS_PRODUCT + ['unit_warn' => '1', 'unit_warn_days' => '2', 'unit_suspend' => '1', 'unit_suspend_days' => '7']);
    unitsReport($di);
    $at = strtotime(Units::of($licence)['at']);

    $email->shouldReceive('sendTemplate')->once()->with(Mockery::on(static fn (array $p): bool => $p['code'] === 'mod_servicelicense_usage_missing'));
    Units::checkReport($di, $licence, $order, $at + 86400);
    Units::checkReport($di, $licence, $order, $at + 3 * 86400);
    Units::checkReport($di, $licence, $order, $at + 4 * 86400);

    $orders->shouldReceive('suspendFromOrder')->once()->with($order, 'No usage report');
    Units::checkReport($di, $licence, $order, $at + 8 * 86400);

    Units::unsuspended($di, $licence);
    expect(strtotime(Units::of($licence)['grace']))->toBeGreaterThanOrEqual($at);
});

test('the WHMCS answer carries the switch and the count under the product\'s field names', function (): void {
    [$di] = unitsFixture();
    unitsReport($di);

    expect(WhmcsCheck::answer($di, ['licensekey' => 'LIC-KEY1'], '203.0.113.5'))->toContain('<customfields>Unit Billing=on|Units=509</customfields>');

    [$di] = unitsFixture(['whmcs_check' => 1]);
    expect(WhmcsCheck::answer($di, ['licensekey' => 'LIC-KEY1'], '203.0.113.5'))->toContain('<customfields></customfields>');
});

test('after a WHMCS import the switch and the count come from the imported answers', function (): void {
    [$di, $licence] = unitsFixture(UNITS_PRODUCT, []);
    $db = $di['dbal'];
    $db->executeStatement('CREATE TABLE form_field (id INTEGER PRIMARY KEY, form_id INTEGER, name TEXT, label TEXT)');
    $db->insert('form_field', ['id' => 1, 'form_id' => 4, 'name' => 'whmcs_field_116', 'label' => 'Unit Billing']);
    $db->insert('form_field', ['id' => 2, 'form_id' => 4, 'name' => 'whmcs_field_110', 'label' => 'Units']);
    $db->update('client_order', ['form_id' => 4, 'config' => json_encode(['whmcs_field_116' => ['on'], 'whmcs_field_110' => '909'])], ['id' => 7]);

    expect(Units::fromAnswers($di, 90, UNITS_PRODUCT))->toBe(1)
        ->and(Units::of($licence))->toMatchArray(['billing' => true, 'count' => 909])
        ->and(unitsPrice($di))->toBe(5.0)
        // Set once: staff's later choice is kept.
        ->and(Units::fromAnswers($di, 90, UNITS_PRODUCT))->toBe(0);
});

test('no warning or suspension without a unit price, with a staff count, and the reporter stays on its licence', function (): void {
    [$di, $licence, $order, $orders, $email] = unitsFixture(['unit_suspend' => '1', 'unit_suspend_days' => '7', 'unit_warn' => '1']);
    $orders->shouldNotReceive('suspendFromOrder');
    $email->shouldNotReceive('sendTemplate');
    Units::checkReport($di, $licence, $order, time() + 30 * 86400);

    [$di, $licence, $order, $orders, $email] = unitsFixture(UNITS_PRODUCT + ['unit_suspend' => '1', 'unit_suspend_days' => '7']);
    Units::update($di, $licence, $order, ['count' => '20']);
    $orders->shouldNotReceive('suspendFromOrder');
    Units::checkReport($di, $licence, $order, time() + 30 * 86400);
    expect(unitsPrice($di))->toBe(5.0);

    // Saving the card with the same count leaves the protections on.
    [$di2, $licence2, $order2] = unitsFixture(UNITS_PRODUCT);
    unitsReport($di2);
    Units::update($di2, $licence2, $order2, ['billing' => '1', 'count' => '509']);
    expect(Units::of($licence2)['manual'])->toBeFalse();

    unitsReport($di, ['ips' => '203.0.113.7']);
    expect($licence->getAllowedIps())->toBe(['203.0.113.7', '203.0.113.5'])
        ->and(Units::of($licence)['manual'])->toBeFalse();
});

test('a native and an imported licence with the same ids are told apart by the reporting server', function (): void {
    [$di, $licence] = unitsFixture();
    $other = (new ServiceLicense())->setLicenseKey('LIC-KEY2')->setValidateIp(true)->setIps('["198.51.100.20"]')->setHosts('[]')->setPaths('[]')->setVersions('[]')
        ->setConfig(json_encode(['whmcs' => ['service_id' => 7, 'product_id' => 90], 'units' => ['billing' => true]]));
    $di['em']->persist($other);
    $di['em']->flush();
    $di['dbal']->insert('client_order', ['id' => 8, 'client_id' => 4, 'product_id' => 91, 'service_id' => $other->getId(), 'service_type' => 'license', 'title' => 'Imported', 'currency' => 'USD', 'period' => '1M', 'quantity' => 1, 'price' => 5, 'status' => Order::STATUS_ACTIVE]);

    expect(unitsReport($di, ['units' => '200'], '198.51.100.20'))->toBe([200, 'success'])
        ->and((float) $di['dbal']->fetchOne('SELECT price FROM client_order WHERE id = 8'))->toBe(20.0)
        ->and(unitsPrice($di))->toBe(5.0);
});
