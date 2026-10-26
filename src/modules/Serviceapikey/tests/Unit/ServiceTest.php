<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use ClientFence\Mod\Order\Entity\Order;
use ClientFence\Mod\Serviceapikey\Entity\ServiceApiKey;
use ClientFence\Mod\Serviceapikey\Repository\ServiceApiKeyRepository;
use ClientFence\Mod\Serviceapikey\Service;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Tests\Support\StrictTemplateRenderer;

use function Tests\Helpers\container;
use function Tests\Helpers\createEntity;

test('new API key product configuration renders with uppercase selected', function (): void {
    $renderer = new StrictTemplateRenderer();

    $html = $renderer->renderTemplate(PATH_MODS . '/Serviceapikey/templates/admin/mod_serviceapikey_config.html.twig', [
        'product' => ['id' => 1, 'config' => []],
    ]);

    expect($html)->toContain('<option selected value="upper">');
});

test('toApiArray serializes service api key entity', function (): void {
    $service = new Service();
    $service->setDi(container());

    $entity = createEntity(ServiceApiKey::class);
    $entity->id = 1;
    $entity->setApiKey('KEY-123');
    $entity->setConfig('{"length":32}');
    $entity->onPrePersist();

    $result = $service->toApiArray($entity);

    expect($result['id'])->toBe(1)
        ->and($result['api_key'])->toBe('KEY-123')
        ->and($result['config'])->toBe(['length' => 32])
        ->and($result['allowed_ips'])->toBe([])
        ->and($result['last_used_at'])->toBeNull()
        ->and($result['use_count'])->toBe(0)
        ->and($result)->toHaveKey('created_at')
        ->and($result)->toHaveKey('updated_at');
});

test('isValid refuses a missing, empty or non-string key', function (array $data): void {
    $service = new Service();
    $service->setDi(container());

    expect(fn (): bool => $service->isValid($data))
        ->toThrow(ClientFence\Exception::class, 'You must provide an API key');
})->with([
    'missing' => [[]],
    'empty' => [['key' => '']],
    'array' => [['key' => ['KEY']]],
]);

test('isValid throws exception when api key not found', function (): void {
    $service = new Service();

    $repo = Mockery::mock(ServiceApiKeyRepository::class);
    $repo->shouldReceive('findByApiKey')->once()->with('missing')->andReturn(null);

    $em = Mockery::mock(EntityManagerInterface::class);
    $em->shouldReceive('getRepository')->with(ServiceApiKey::class)->andReturn($repo);

    $di = container();
    $di['em'] = $em;
    $service->setDi($di);

    expect(fn (): bool => $service->isValid(['key' => 'missing']))
        ->toThrow(Exception::class, 'API key does not exist');
});

test('isActive returns true for active order with future expires_at', function (): void {
    $service = new Service();

    $entity = createEntity(ServiceApiKey::class);
    $entity->id = 7;

    $order = new Order();
    $order->setStatus(Order::STATUS_ACTIVE);
    $order->setExpiresAt(new DateTime(date('Y-m-d H:i:s', time() + 86400)));

    $orderRepo = Mockery::mock(EntityRepository::class);
    $orderRepo->shouldReceive('findOneBy')
        ->once()
        ->with(['serviceId' => 7, 'serviceType' => 'apikey'])
        ->andReturn($order);

    $em = Mockery::mock(EntityManagerInterface::class);
    $em->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepo);

    $di = container();
    $di['em'] = $em;
    $service->setDi($di);

    $reflection = new ReflectionMethod($service, 'isActive');
    expect($reflection->invoke($service, $entity))->toBeTrue();
});

test('isActive returns false for expired order', function (): void {
    $service = new Service();

    $entity = createEntity(ServiceApiKey::class);
    $entity->id = 7;

    $order = new Order();
    $order->setStatus(Order::STATUS_ACTIVE);
    $order->setExpiresAt(new DateTime(date('Y-m-d H:i:s', time() - 3600)));

    $orderRepo = Mockery::mock(EntityRepository::class);
    $orderRepo->shouldReceive('findOneBy')->once()->andReturn($order);

    $em = Mockery::mock(EntityManagerInterface::class);
    $em->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepo);

    $di = container();
    $di['em'] = $em;
    $service->setDi($di);

    $reflection = new ReflectionMethod($service, 'isActive');
    expect($reflection->invoke($service, $entity))->toBeFalse();
});

test('isActive returns false for inactive order status', function (): void {
    $service = new Service();

    $entity = createEntity(ServiceApiKey::class);
    $entity->id = 9;

    $order = new Order();
    $order->setStatus(Order::STATUS_SUSPENDED);

    $orderRepo = Mockery::mock(EntityRepository::class);
    $orderRepo->shouldReceive('findOneBy')->once()->andReturn($order);

    $em = Mockery::mock(EntityManagerInterface::class);
    $em->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepo);

    $di = container();
    $di['em'] = $em;
    $service->setDi($di);

    $reflection = new ReflectionMethod($service, 'isActive');
    expect($reflection->invoke($service, $entity))->toBeFalse();
});

test('isActive returns true for active order with null expires_at', function (): void {
    $service = new Service();

    $entity = createEntity(ServiceApiKey::class);
    $entity->id = 11;

    $order = new Order();
    $order->setStatus(Order::STATUS_ACTIVE);

    $orderRepo = Mockery::mock(EntityRepository::class);
    $orderRepo->shouldReceive('findOneBy')->once()->andReturn($order);

    $em = Mockery::mock(EntityManagerInterface::class);
    $em->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepo);

    $di = container();
    $di['em'] = $em;
    $service->setDi($di);

    $reflection = new ReflectionMethod($service, 'isActive');
    expect($reflection->invoke($service, $entity))->toBeTrue();
});

test('isActive throws when no matching order found', function (): void {
    $service = new Service();

    $entity = createEntity(ServiceApiKey::class);
    $entity->id = 15;

    $orderRepo = Mockery::mock(EntityRepository::class);
    $orderRepo->shouldReceive('findOneBy')->once()->andReturn(null);

    $em = Mockery::mock(EntityManagerInterface::class);
    $em->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepo);

    $di = container();
    $di['em'] = $em;
    $service->setDi($di);

    $reflection = new ReflectionMethod($service, 'isActive');
    expect(fn (): bool => $reflection->invoke($service, $entity))
        ->toThrow(Exception::class, 'API key does not exist');
});

test('clientSettableConfigKeys returns the apikey allowlist', function (): void {
    $service = new Service();
    $allowed = $service->clientSettableConfigKeys();

    expect($allowed)->toBeArray();
    expect($allowed)->toContain('period');
    expect($allowed)->toContain('quantity');
    // Admin-controlled keys must not appear - the central filter strips them
    // before the merge, preventing client override of key generation params
    // (length, split, split_interval, case, custom_* fields).
    expect($allowed)->not->toContain('length');
    expect($allowed)->not->toContain('split');
    expect($allowed)->not->toContain('split_interval');
    expect($allowed)->not->toContain('case');
});

/**
 * A service whose key KEY-1 (client 5) belongs to an order with the given status.
 *
 * @return array{Service, ServiceApiKey, Mockery\MockInterface}
 */
function apiKeyService(string $status = Order::STATUS_ACTIVE, string $ip = '127.0.0.1', array $config = []): array
{
    $entity = createEntity(ServiceApiKey::class);
    $entity->id = 3;
    $entity->setClientId(5)->setApiKey('KEY-1')->setConfig(json_encode($config + ['length' => '16', 'split' => '1', 'split_interval' => '4', 'case' => 'upper']));

    $order = new Order();
    $order->setStatus($status);

    $repo = Mockery::mock(ServiceApiKeyRepository::class);
    $repo->shouldReceive('findByApiKey')->with('KEY-1')->andReturn($entity);
    $repo->shouldReceive('findByApiKey')->andReturn(null);
    $orderRepo = Mockery::mock(EntityRepository::class);
    $orderRepo->shouldReceive('findOneBy')->andReturn($order);

    $em = Mockery::mock(EntityManagerInterface::class);
    $em->shouldReceive('getRepository')->with(ServiceApiKey::class)->andReturn($repo);
    $em->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepo);
    $em->shouldReceive('flush');

    $di = container();
    $di['em'] = $em;
    $di['request'] = fn (): Symfony\Component\HttpFoundation\Request => Symfony\Component\HttpFoundation\Request::create('http://localhost/', server: ['REMOTE_ADDR' => $ip]);
    $service = new Service();
    $service->setDi($di);

    return [$service, $entity, $em];
}

test('a product configured in the admin form (numbers as strings) generates its key', function (): void {
    [$service, $entity] = apiKeyService();

    expect($service->resetApiKey($entity))->toBeTrue()
        ->and($entity->getApiKey())->toMatch('/^[0-9A-F]{4}(-[0-9A-F]{4}){3}$/');
});

test('out-of-range generation settings produce a key that fits the column', function (array $config): void {
    [$service, $entity] = apiKeyService(config: $config);

    expect($service->resetApiKey($entity))->toBeTrue()
        ->and(strlen($entity->getApiKey()))->toBeLessThanOrEqual(255);
})->with([
    'oversized key' => [['length' => '256', 'split_interval' => '8']],
    'zero split interval' => [['length' => '32', 'split_interval' => '0']],
]);

test('staff reset a key while a client is signed in in the same browser', function (): void {
    [$service, $entity] = apiKeyService();
    $di = $service->getDi();
    $di['auth'] = new class {
        public function isClientLoggedIn(): bool
        {
            return true;
        }
    };
    $di['loggedin_client'] = (object) ['id' => 99];
    $staff = Mockery::mock();
    $staff->shouldReceive('checkPermissionsAndThrowException')->with('serviceapikey', 'manage', null, Mockery::any());
    $di['mod_service'] = $di->protect(fn (string $name) => $staff);
    $api = new ClientFence\Mod\Serviceapikey\Api\Admin();
    $api->setDi($di);
    $api->setService($service);

    expect($api->reset(['key' => 'KEY-1']))->toBeTrue()
        ->and($entity->getApiKey())->not->toBe('KEY-1');
});

test('a client cannot reset or restrict another client\'s key', function (string $method, array $data): void {
    [$service] = apiKeyService();
    $api = new ClientFence\Mod\Serviceapikey\Api\Client();
    $api->setDi($service->getDi());
    $api->setService($service);
    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->id = 6;
    $api->setIdentity($client);

    expect(fn () => $api->$method($data))->toThrow(ClientFence\Exception::class, 'API key does not exist');
})->with([
    'reset' => ['reset', ['key' => 'KEY-1']],
    'update_ips' => ['update_ips', ['key' => 'KEY-1', 'allowed_ips' => '10.0.0.1']],
]);

test('a check from an allowed IP is valid and recorded with one update', function (string $ip, bool $valid): void {
    [$service, $entity, $em] = apiKeyService(Order::STATUS_ACTIVE, $ip);
    $entity->setAllowedIps(['203.0.113.10', '10.0.0.0/8', '2001:db8::/32']);

    $connection = Mockery::mock(Doctrine\DBAL\Connection::class);
    $connection->shouldReceive('executeStatement')
        ->times($valid ? 1 : 0)
        ->with('UPDATE service_apikey SET last_used_at = ?, use_count = use_count + 1 WHERE id = ?', Mockery::on(fn (array $params): bool => $params[1] === 3));
    $em->shouldReceive('getConnection')->andReturn($connection);

    expect($service->isValid(['key' => 'KEY-1']))->toBe($valid);
})->with([
    'listed IPv4' => ['203.0.113.10', true],
    'IPv4 range' => ['10.20.30.40', true],
    'IPv6 range' => ['2001:db8::5', true],
    'other IPv4' => ['203.0.113.11', false],
    'other IPv6' => ['2001:db9::5', false],
]);

test('a check of a suspended key is invalid and not recorded', function (): void {
    [$service, , $em] = apiKeyService(Order::STATUS_SUSPENDED);
    $em->shouldNotReceive('getConnection');

    expect($service->isValid(['key' => 'KEY-1']))->toBeFalse();
});

test('allowed IPs accept addresses and CIDR ranges, one per line or comma', function (): void {
    [$service, $entity] = apiKeyService();

    $service->setAllowedIps($entity, "203.0.113.10\n10.0.0.0/8, 2001:db8::/32\n203.0.113.10");

    expect($entity->getAllowedIps())->toBe(['203.0.113.10', '10.0.0.0/8', '2001:db8::/32']);

    $service->setAllowedIps($entity, '');
    expect($entity->getAllowedIps())->toBe([]);
});

test('allowed IPs refuse what is not an address or range, and more than 20', function (mixed $input, string $message): void {
    [$service, $entity] = apiKeyService();

    expect(fn (): bool => $service->setAllowedIps($entity, $input))->toThrow(ClientFence\InformationException::class, $message);
})->with([
    'hostname' => ['example.com', 'not an IP address or range'],
    'IPv4 prefix too long' => ['10.0.0.0/33', 'not an IP address or range'],
    'IPv6 prefix too long' => ['2001:db8::/129', 'not an IP address or range'],
    'bad prefix' => ['10.0.0.0/x', 'not an IP address or range'],
    'nested list' => [[['10.0.0.1']], 'not an IP address or range'],
    'too many' => [implode("\n", array_map(fn (int $i): string => "10.0.0.{$i}", range(1, 21))), 'at most 20'],
]);
