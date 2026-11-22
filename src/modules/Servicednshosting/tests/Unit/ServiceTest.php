<?php

declare(strict_types=1);

use ClientFence\Crypt;
use ClientFence\InformationException;
use ClientFence\Mod\Product\Entity\Product;
use ClientFence\Mod\Servicednshosting\Api\Client;
use ClientFence\Mod\Servicednshosting\Entity\ServiceDns;
use ClientFence\Mod\Servicednshosting\Provider\DnsHostingProviderInterface;
use ClientFence\Mod\Servicednshosting\Service;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;

use function Tests\Helpers\container;

function dnsServiceFixture(DnsHostingProviderInterface $provider): array
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $config = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2) . '/Entity'], true);
    $config->setProxyDir(sys_get_temp_dir());
    $config->setProxyNamespace('DnsTestProxies');
    $em = new EntityManager($db, $config);
    (new SchemaTool($em))->createSchema([$em->getClassMetadata(ServiceDns::class)]);
    $db->executeStatement('CREATE TABLE client_order (id INTEGER PRIMARY KEY, client_id INTEGER, product_id INTEGER, service_id INTEGER, service_type TEXT, status TEXT, config TEXT)');
    $db->executeStatement('CREATE TABLE product (id INTEGER PRIMARY KEY, config TEXT)');
    $di = container();
    $di['dbal'] = $db;
    $di['em'] = $em;
    $crypt = new Crypt();
    $crypt->setDi($di);
    $di['crypt'] = $crypt;
    $service = new class($provider) extends Service {
        public function __construct(private readonly DnsHostingProviderInterface $fake)
        {
        }

        protected function provider(ServiceDns $zone): DnsHostingProviderInterface
        {
            return $this->fake;
        }
    };
    $service->setDi($di);
    $product = (new Product())->setType('dnshosting');
    $product->setConfig(json_encode(['dns' => $service->prepareProductConfig([
        'provider' => 'digitalocean', 'token' => 'test-private-token',
    ], [])], JSON_THROW_ON_ERROR));
    $productService = Mockery::mock(ClientFence\Mod\Product\Service::class);
    $productService->shouldReceive('findProductById')->with(4)->andReturn($product);
    $di['mod_service'] = $di->protect(static fn (string $module) => $productService);
    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->id = 5;
    $order->product_id = 4;
    $order->client_id = 6;
    $order->service_type = 'dnshosting';
    $order->config = json_encode(['domain_name' => 'example.com'], JSON_THROW_ON_ERROR);
    $zone = $service->action_create($order);
    $db->insert('client_order', ['id' => 5, 'client_id' => 6, 'product_id' => 4, 'service_id' => $zone->getId(), 'service_type' => 'dnshosting', 'status' => 'active']);
    $db->insert('product', ['id' => 4, 'config' => $product->getConfig()]);

    return [$service, $order, $zone, $db, $product, $di];
}

function dnsClientApi(Service $service, int $id): Client
{
    $api = new Client();
    $api->setService($service);
    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->id = $id;
    $api->setIdentity($client);

    return $api;
}

test('Servicednshosting repeat activation creates the zone once', function (): void {
    $provider = Mockery::mock(DnsHostingProviderInterface::class);
    $provider->shouldReceive('createZone')->once()->with('example.com')->andReturn(['id' => 'remote-zone', 'nameservers' => ['ns.example.org']]);
    [$service, $order, $zone, $db] = dnsServiceFixture($provider);

    expect($service->action_activate($order))->toBeTrue()
        ->and($service->action_activate($order))->toBeTrue()
        ->and($service->toApiArray($zone)['nameservers'])->toBe(['ns.example.org'])
        ->and($db->fetchOne('SELECT zone_id FROM service_dnshosting'))->toBe('remote-zone');
});

test('Servicednshosting cancellation and deletion remove the provisioned zone only once', function (string $action): void {
    $provider = Mockery::mock(DnsHostingProviderInterface::class);
    $provider->shouldReceive('createZone')->once()->andReturn(['id' => 'remote-zone', 'nameservers' => []]);
    $provider->shouldReceive('deleteZone')->once()->with('remote-zone');
    [$service, $order, , $db] = dnsServiceFixture($provider);
    $service->action_activate($order);
    $service->$action($order);
    $service->$action($order);
    $service->action_delete($order);

    expect($db->fetchOne('SELECT id FROM service_dnshosting'))->toBeFalse();
})->with(['action_cancel', 'action_delete']);

test('Servicednshosting refuses invalid record data before changing the provider', function (string $type, string $value, string $operation): void {
    $provider = Mockery::mock(DnsHostingProviderInterface::class);
    $provider->shouldReceive('createZone')->once()->andReturn(['id' => 'remote-zone', 'nameservers' => []]);
    $provider->shouldReceive('records')->with('remote-zone', 'example.com')->andReturn([
        ['id' => 'rec', 'type' => $type, 'name' => 'www', 'value' => 'old', 'ttl' => 3600, 'priority' => 10],
    ]);
    [$service, $order] = dnsServiceFixture($provider);
    $service->action_activate($order);
    $api = dnsClientApi($service, 6);

    expect(fn () => $api->$operation([
        'order_id' => 5, 'record_id' => 'rec', 'record_name' => 'www',
        'record_type' => $type, 'record_value' => $value, 'record_priority' => 10,
    ]))->toThrow(InformationException::class);
})->with([
    'A on add' => ['A', '2001:db8::1', 'add'],
    'AAAA on update' => ['AAAA', '192.0.2.1', 'update'],
    'MX on add' => ['MX', 'mx host.example.com', 'add'],
    'TXT on update' => ['TXT', "invalid\ntext", 'update'],
]);

test('Servicednshosting accepts mail authentication records: a DKIM CNAME target and a 2048-bit DKIM key', function (string $type, string $value, string $stored): void {
    $provider = Mockery::mock(DnsHostingProviderInterface::class);
    $provider->shouldReceive('createZone')->once()->andReturn(['id' => 'remote-zone', 'nameservers' => []]);
    $provider->shouldReceive('addRecord')->once()->with('remote-zone', 'example.com', Mockery::on(fn (array $record): bool => $record['value'] === $stored));
    [$service, $order] = dnsServiceFixture($provider);
    $service->action_activate($order);

    dnsClientApi($service, 6)->add(['order_id' => 5, 'record_name' => 'selector1._domainkey', 'record_type' => $type, 'record_value' => $value]);
})->with([
    'Microsoft 365 DKIM CNAME' => ['CNAME', 'selector1-example-com._domainkey.example.onmicrosoft.com', 'selector1-example-com._domainkey.example.onmicrosoft.com'],
    'DKIM key pasted as two strings' => ['TXT', '"v=DKIM1; k=rsa; " "p=' . str_repeat('A', 392) . '"', 'v=DKIM1; k=rsa; p=' . str_repeat('A', 392)],
]);

test('Servicednshosting changes only a record identified in the selected zone', function (): void {
    $provider = Mockery::mock(DnsHostingProviderInterface::class);
    $old = ['id' => 'rec', 'type' => 'A', 'name' => 'www', 'value' => '192.0.2.1', 'ttl' => 3600, 'priority' => null];
    $new = ['type' => 'A', 'name' => 'www', 'value' => '192.0.2.2', 'ttl' => 3600, 'priority' => null];
    $provider->shouldReceive('createZone')->once()->andReturn(['id' => 'remote-zone', 'nameservers' => []]);
    $provider->shouldReceive('records')->times(3)->with('remote-zone', 'example.com')->andReturn([$old]);
    $provider->shouldReceive('addRecord')->once()->with('remote-zone', 'example.com', Mockery::subset($new));
    $provider->shouldReceive('updateRecord')->once()->with('remote-zone', 'example.com', $old, Mockery::subset($new));
    $provider->shouldReceive('deleteRecord')->once()->with('remote-zone', 'example.com', $old);
    [$service, $order] = dnsServiceFixture($provider);
    $service->action_activate($order);
    $api = dnsClientApi($service, 6);
    $data = ['order_id' => 5, 'record_id' => 'rec', 'record_name' => 'www', 'record_type' => 'A', 'record_value' => '192.0.2.2'];

    expect($api->add($data))->toBeTrue()
        ->and($api->update(array_replace($data, ['record_name' => 'changed', 'record_type' => 'TXT'])))->toBeTrue()
        ->and($api->del($data))->toBeTrue()
        ->and(fn () => $api->del(array_replace($data, ['record_id' => 'other-record'])))->toThrow(InformationException::class, 'DNS record not found.');
});

test('Servicednshosting rejects record access for a foreign client or suspended order', function (string $operation, int $client, string $status): void {
    $provider = Mockery::mock(DnsHostingProviderInterface::class);
    $provider->shouldReceive('createZone')->once()->andReturn(['id' => 'remote-zone', 'nameservers' => []]);
    [$service, $order, , $db] = dnsServiceFixture($provider);
    $service->action_activate($order);
    $db->update('client_order', ['status' => $status], ['id' => 5]);
    $api = dnsClientApi($service, $client);

    expect(fn () => $api->$operation(['order_id' => 5]))->toThrow(InformationException::class);
})->with([
    'foreign read' => ['zone', 7, 'active'],
    'foreign write' => ['add', 7, 'active'],
    'suspended read' => ['zone', 6, 'suspended'],
    'suspended write' => ['add', 6, 'suspended'],
]);

test('Servicednshosting encrypts credentials and excludes them from order config and client answers', function (): void {
    $provider = Mockery::mock(DnsHostingProviderInterface::class);
    $provider->shouldReceive('createZone')->once()->andReturn(['id' => 'remote-zone', 'nameservers' => []]);
    $provider->shouldReceive('records')->once()->andReturn([]);
    [$service, $order, $zone, $db, $product, $di] = dnsServiceFixture($provider);
    $service->action_activate($order);
    $ciphertext = json_decode($db->fetchOne('SELECT config FROM service_dnshosting'), true)['token'];

    expect($di['crypt']->decrypt($ciphertext))->toBe('test-private-token')
        ->and($product->getConfig())->not->toContain('test-private-token')
        ->and($service->attachOrderConfig($product, ['domain_name' => 'EXAMPLE.com.', 'dns' => $ciphertext, 'token' => 'test-private-token']))
        ->toBe(['domain_name' => 'example.com'])
        ->and($service->toApiArray($zone))->toBe(['id' => $zone->getId(), 'domain_name' => 'example.com', 'nameservers' => []])
        ->and(dnsClientApi($service, 6)->zone(['order_id' => 5]))
        ->toBe(['nameservers' => [], 'types' => ['A', 'AAAA', 'CNAME', 'MX', 'TXT'], 'records' => [], 'id' => $zone->getId(), 'domain_name' => 'example.com']);
});

test('Servicednshosting raw order configuration cannot store provider settings', function (): void {
    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->service_type = 'dnshosting';

    expect(fn () => (new ClientFence\Mod\Order\Service())->updateOrderConfig($order, ['dns' => ['token' => 'test-private-token']]))
        ->toThrow(InformationException::class, 'DNS order configuration is set when the order is placed.');
});

test('Servicednshosting refuses a zone held by another order, or one above or below it, until that order is cancelled', function (): void {
    [$service, , , $db] = dnsServiceFixture(Mockery::mock(DnsHostingProviderInterface::class));
    $db->insert('client_order', ['id' => 9, 'client_id' => 7, 'service_type' => 'dnshosting', 'status' => 'pending_setup', 'config' => json_encode(['domain_name' => 'other.org'])]);

    foreach (['example.com', 'www.example.com', 'shop.other.org', 'other.org'] as $domain) {
        $data = ['domain_name' => $domain];
        expect(fn () => $service->validateOrderData($data))->toThrow(InformationException::class, 'This DNS zone, or a zone above or below it, already has an order.');
    }
    $db->update('client_order', ['status' => 'canceled'], ['id' => 5]);
    $data = ['domain_name' => 'www.example.com', 'quantity' => 3];
    $service->validateOrderData($data);
    // One order is one zone, so it is billed as one.
    expect($data['quantity'])->toBe(1);
});

test('Servicednshosting activation refuses a zone another order took since this one was placed', function (): void {
    [$service, $order, , $db] = dnsServiceFixture(Mockery::mock(DnsHostingProviderInterface::class, ['createZone' => ['id' => 'remote-zone', 'nameservers' => []]]));
    $service->action_activate($order);
    $db->insert('client_order', ['id' => 9, 'client_id' => 7, 'service_type' => 'dnshosting', 'status' => 'pending_setup']);
    $db->insert('service_dnshosting', ['order_id' => 9, 'domain_name' => 'www.example.com', 'config' => '{}', 'nameservers' => '[]']);
    $second = clone $order;
    $second->id = 9;

    expect(fn () => $service->action_activate($second))->toThrow(InformationException::class, 'This DNS zone, or a zone above or below it, already has an order.');
});

test('Servicednshosting orders a Unicode domain under its nontransitional name', function (): void {
    [$service, , , , $product] = dnsServiceFixture(Mockery::mock(DnsHostingProviderInterface::class));

    expect($service->attachOrderConfig($product, ['domain_name' => 'straße.de'])['domain_name'])->toBe('xn--strae-oqa.de');
});

test('Servicednshosting keeps its zones in the table client deletion clears for its type', function (): void {
    [, , , , , $di] = dnsServiceFixture(Mockery::mock(DnsHostingProviderInterface::class));

    expect($di['em']->getClassMetadata(ServiceDns::class)->getTableName())->toBe('service_' . ClientFence\Mod\Product\Service::DNS);
});

test('Servicednshosting uses the product\'s current provider credentials for an existing zone', function (): void {
    [$fixture, $order, , $db, $product, $di] = dnsServiceFixture(Mockery::mock(DnsHostingProviderInterface::class, ['createZone' => ['id' => 'remote-zone', 'nameservers' => []]]));
    $fixture->action_activate($order);
    $service = new Service();
    $service->setDi($di);
    $db->update('product', ['config' => json_encode(['dns' => $service->prepareProductConfig(['provider' => 'digitalocean', 'token' => 'rotated-token'], [])])], ['id' => 4]);
    $auth = [];
    $di['http_client'] = new Symfony\Component\HttpClient\MockHttpClient(function (string $method, string $url, array $options) use (&$auth): Symfony\Component\HttpClient\Response\MockResponse {
        $auth[] = implode(' ', array_filter($options['headers'], static fn (string $h): bool => str_starts_with($h, 'Authorization')));

        return new Symfony\Component\HttpClient\Response\MockResponse('{"domain_records":[],"links":{}}');
    });

    $service->getZone(['order_id' => 5], 6);
    expect($auth[0])->toBe('Authorization: Bearer rotated-token');
});
