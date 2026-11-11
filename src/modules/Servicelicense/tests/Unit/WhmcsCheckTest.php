<?php

declare(strict_types=1);

/**
 * ClientFence: answers to WHMCS Licensing Addon checks (Servicelicense\WhmcsCheck), on an in-memory
 * SQLite install with one licence (key LIC-KEY1, IP validated, nothing bound yet) of order 7.
 */

use ClientFence\Mod\Order\Entity\Order;
use ClientFence\Mod\Servicelicense\Bans;
use ClientFence\Mod\Servicelicense\CheckLog;
use ClientFence\Mod\Servicelicense\Entity\ServiceLicense;
use ClientFence\Mod\Servicelicense\WhmcsCheck;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Symfony\Component\Filesystem\Path;

/**
 * @return array{0: Pimple\Container, 1: ServiceLicense, 2: Order}
 */
function whmcsCheckFixture(array $product = ['whmcs_check' => 1], string $payment = 'recurrent'): array
{
    $config = ORMSetup::createAttributeMetadataConfig([Path::join(__DIR__, '..', '..', 'Entity')], true);
    $config->setProxyDir(sys_get_temp_dir());
    $config->setProxyNamespace('ClientFence\\Tests\\DoctrineProxies');
    $em = new EntityManager(Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
    (new Doctrine\ORM\Tools\SchemaTool($em))->createSchema([$em->getClassMetadata(ServiceLicense::class)]);
    $db = $em->getConnection();
    $db->executeStatement('CREATE TABLE ' . CheckLog::TABLE . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, service_license_id INTEGER NOT NULL, host TEXT NOT NULL, ip TEXT NOT NULL, path TEXT NOT NULL, version TEXT NOT NULL, result TEXT NOT NULL, created_at TEXT NOT NULL)');
    $db->executeStatement('CREATE TABLE ' . Bans::TABLE . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT NOT NULL UNIQUE, notes TEXT NOT NULL, created_at TEXT NOT NULL)');
    $db->executeStatement('CREATE TABLE product_payment (id INTEGER PRIMARY KEY, type TEXT)');
    $db->executeStatement('CREATE TABLE product (id INTEGER PRIMARY KEY, product_payment_id INTEGER)');
    $db->insert('product_payment', ['id' => 1, 'type' => $payment]);
    $db->insert('product', ['id' => 90, 'product_payment_id' => 1]);

    $licence = (new ServiceLicense())->setLicenseKey('LIC-KEY1')->setValidateIp(true)->setIps('[]')->setHosts('[]')->setPaths('[]')->setVersions('[]');
    $em->persist($licence);
    $em->flush();

    $order = new Order();
    (new ReflectionProperty(Order::class, 'id'))->setValue($order, 7);
    $order->setProductId(90);
    $order->setPeriod('1M');
    $order->setStatus(Order::STATUS_ACTIVE);

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
    $di['mod_service'] = $di->protect(static fn (string $name): object => $name === 'order' ? $orders : $service);

    return [$di, $licence, $order];
}

function whmcsTags(string $xml): array
{
    preg_match_all('#<(\w+)>([^<]*)</\1>#', $xml, $m);

    return array_combine($m[1], $m[2]);
}

test('an active licence answers Active with the order and product ids and binds its first IP', function (): void {
    [$di, $licence] = whmcsCheckFixture();
    $tags = whmcsTags(WhmcsCheck::answer($di, ['licensekey' => 'LIC-KEY1', 'check_token' => '', 'ip' => '203.0.113.5'], '203.0.113.5'));

    expect($tags)->toBe(['status' => 'Active', 'serviceid' => '7', 'productid' => '90', 'billingcycle' => 'Monthly', 'customfields' => ''])
        ->and($licence->getAllowedIps())->toBe(['203.0.113.5'])
        ->and($licence->getPingedAt())->not->toBeNull()
        ->and(CheckLog::recent($di, (int) $licence->getId())[0]['result'])->toBe('Valid');

    expect(whmcsTags(WhmcsCheck::answer($di, ['licensekey' => 'LIC-KEY1'], '198.51.100.9')))->toBe(['status' => 'Invalid', 'description' => 'IP Address Invalid']);
});

test('the status follows the order, not its expiry date', function (string $status, string $answer): void {
    [$di, , $order] = whmcsCheckFixture();
    $order->setStatus($status);
    $order->setExpiresAt(new DateTime('-1 day'));

    expect(whmcsTags(WhmcsCheck::answer($di, ['licensekey' => 'LIC-KEY1'], '203.0.113.5'))['status'])->toBe($answer);
})->with([
    'active, past its expiry date' => [Order::STATUS_ACTIVE, 'Active'],
    'suspended' => [Order::STATUS_SUSPENDED, 'Suspended'],
    'canceled' => [Order::STATUS_CANCELED, 'Expired'],
    'pending setup' => [Order::STATUS_PENDING_SETUP, 'Invalid'],
]);

test('unknown keys, products without the switch and bans answer Invalid; unknown keys are logged under licence 0', function (): void {
    [$di] = whmcsCheckFixture();
    expect(whmcsTags(WhmcsCheck::answer($di, ['licensekey' => 'LIC-NOPE'], '203.0.113.5'))['status'])->toBe('Invalid')
        ->and(CheckLog::recent($di, 0)[0]['result'])->toBe('Invalid Key - LIC-NOPE');

    Bans::add($di, '203.0.113.5');
    expect(whmcsTags(WhmcsCheck::answer($di, ['licensekey' => 'LIC-KEY1'], '203.0.113.5')))->toBe(['status' => 'Invalid', 'description' => 'Banned']);

    [$di] = whmcsCheckFixture([]);
    expect(whmcsTags(WhmcsCheck::answer($di, ['licensekey' => 'LIC-KEY1'], '203.0.113.5'))['status'])->toBe('Invalid');
});

test('the billing cycle uses the WHMCS names', function (string $payment, ?string $period, string $cycle): void {
    [$di, , $order] = whmcsCheckFixture(['whmcs_check' => 1], $payment);
    $order->setPeriod($period);

    expect(whmcsTags(WhmcsCheck::answer($di, ['licensekey' => 'LIC-KEY1'], '203.0.113.5'))['billingcycle'])->toBe($cycle);
})->with([
    ['free', null, 'Free Account'],
    ['once', null, 'One Time'],
    ['recurrent', '1Y', 'Annually'],
    ['recurrent', '6M', 'Semi-Annually'],
]);

test('imported licences answer with their WHMCS ids', function (): void {
    [$di, $licence] = whmcsCheckFixture();
    $licence->setConfig(json_encode(['whmcs' => ['service_id' => 1226, 'product_id' => 94]]));

    expect(whmcsTags(WhmcsCheck::answer($di, ['licensekey' => 'LIC-KEY1'], '203.0.113.5')))->toMatchArray(['serviceid' => '1226', 'productid' => '94']);
});

test('an imported order without a period keeps WHMCS\'s billing cycle name', function (): void {
    [$di, $licence, $order] = whmcsCheckFixture();
    $licence->setConfig(json_encode(['whmcs' => ['service_id' => 180, 'product_id' => 90, 'billingcycle' => 'Free Account']]));
    $order->setPeriod(null);

    expect(whmcsTags(WhmcsCheck::answer($di, ['licensekey' => 'LIC-KEY1'], '203.0.113.5'))['billingcycle'])->toBe('Free Account');
    $order->setPeriod('1M');
    expect(whmcsTags(WhmcsCheck::answer($di, ['licensekey' => 'LIC-KEY1'], '203.0.113.5'))['billingcycle'])->toBe('Monthly');
});
