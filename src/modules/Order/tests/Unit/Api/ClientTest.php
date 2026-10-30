<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use ClientFence\Mod\Order\Api\Client;
use ClientFence\Mod\Order\Service;

use function Tests\Helpers\container;
use function Tests\Helpers\createEntity;

test('gets list of orders', function (): void {
    $api = apiEndpoint(new Client());

    $orderArr = ['id' => 1];

    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('getSearchQuery')->atLeast()->once()->andReturn(['query', []]);
    $serviceMock->shouldReceive('getBatchForApi')->atLeast()->once()->andReturn([$orderArr]);

    $pagerMock = Mockery::mock(ClientFence\Pagination::class);
    $pagerMock->shouldReceive('getPaginatedResultSet')->atLeast()->once()->andReturn(['list' => [$orderArr]]);

    $client = createEntity(ClientFence\Mod\Client\Entity\Client::class, ['id' => 1]);

    $di = container();
    $di['pager'] = $pagerMock;

    $api->setDi($di);
    $api->setIdentity($client);
    $api->setService($serviceMock);

    $result = $api->get_list([]);

    expect($result)->toBeArray();
});

test('gets list of expiring orders', function (): void {
    $api = apiEndpoint(new Client());

    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('getSoonExpiringActiveOrdersQuery')->atLeast()->once()->andReturn(['query', []]);
    $serviceMock->shouldReceive('getBatchForApi')->atLeast()->once()->andReturn([]);

    $pagerMock = Mockery::mock(ClientFence\Pagination::class);
    $pagerMock->shouldReceive('getPaginatedResultSet')->atLeast()->once()->andReturn(['list' => []]);

    $client = createEntity(ClientFence\Mod\Client\Entity\Client::class, ['id' => 1]);

    $di = container();
    $di['pager'] = $pagerMock;

    $api->setDi($di);
    $api->setIdentity($client);
    $api->setService($serviceMock);

    $result = $api->get_list(['expiring' => true]);

    expect($result)->toBeArray();
});

test('gets a single order', function (): void {
    $order = createEntity(ClientFence\Mod\Order\Entity\Order::class);

    $apiMock = apiEndpoint(Mockery::mock(Client::class)->makePartial());
    $apiMock->shouldAllowMockingProtectedMethods();
    $apiMock->shouldReceive('_getOrder')->atLeast()->once()->andReturn($order);

    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('toApiArray')->atLeast()->once()->andReturn([]);

    $apiMock->setService($serviceMock);

    $data = ['id' => 1];
    $result = $apiMock->get($data);

    expect($result)->toBeArray();
});

test('gets addons', function (): void {
    $order = createEntity(ClientFence\Mod\Order\Entity\Order::class);

    $apiMock = apiEndpoint(Mockery::mock(Client::class)->makePartial());
    $apiMock->shouldAllowMockingProtectedMethods();
    $apiMock->shouldReceive('_getOrder')->atLeast()->once()->andReturn($order);

    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('getOrderAddonsList')->atLeast()->once()->andReturn([createEntity(ClientFence\Mod\Order\Entity\Order::class)]);
    $serviceMock->shouldReceive('toApiArray')->atLeast()->once()->andReturn([]);

    $apiMock->setService($serviceMock);

    $data = ['status' => ClientFence\Mod\Order\Entity\Order::STATUS_ACTIVE];
    $result = $apiMock->addons($data);

    expect($result)->toBeArray();
    expect($result[0])->toBeArray();
});

test('gets order service', function (): void {
    $order = createEntity(ClientFence\Mod\Order\Entity\Order::class, ['status' => ClientFence\Mod\Order\Entity\Order::STATUS_ACTIVE]);

    $apiMock = apiEndpoint(Mockery::mock(Client::class)->makePartial());
    $apiMock->shouldAllowMockingProtectedMethods();
    $apiMock->shouldReceive('_getOrder')->atLeast()->once()->andReturn($order);

    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('getOrderServiceData')->atLeast()->once()->andReturn([]);

    $client = createEntity(ClientFence\Mod\Client\Entity\Client::class);

    $apiMock->setService($serviceMock);
    $apiMock->setIdentity($client);

    $data = ['id' => 1];
    $result = $apiMock->service($data);

    expect($result)->toBeArray();
});

test('gets upgradables', function (): void {
    $order = createEntity(ClientFence\Mod\Order\Entity\Order::class, ['product_id' => 5]);

    $apiMock = apiEndpoint(Mockery::mock(Client::class)->makePartial());
    $apiMock->shouldAllowMockingProtectedMethods();
    $apiMock->shouldReceive('_getOrder')->atLeast()->once()->andReturn($order);

    $productServiceMock = Mockery::mock(ClientFence\Mod\Product\Service::class);
    $productServiceMock->shouldReceive('getUpgradablePairsByProductId')
        ->atLeast()->once()
        ->with(5)
        ->andReturn([]);

    $di = container();
    $di['mod_service'] = $di->protect(fn (string $name): Mockery\MockInterface => match (strtolower($name)) {
        'product' => $productServiceMock,
        default => Mockery::mock()->shouldIgnoreMissing(),
    });
    $apiMock->setDi($di);

    $data = [];
    $result = $apiMock->upgradables($data);

    expect($result)->toBeArray();
});

test('deletes a pending setup order', function (): void {
    $order = createEntity(ClientFence\Mod\Order\Entity\Order::class, ['status' => ClientFence\Mod\Order\Entity\Order::STATUS_PENDING_SETUP]);

    $apiMock = apiEndpoint(Mockery::mock(Client::class)->makePartial());
    $apiMock->shouldAllowMockingProtectedMethods();
    $apiMock->shouldReceive('_getOrder')->atLeast()->once()->andReturn($order);

    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('deleteFromOrder')->atLeast()->once()->andReturn(true);

    $apiMock->setService($serviceMock);

    $data = ['id' => 1];
    $result = $apiMock->delete($data);

    expect($result)->toBeTrue();
});

test('deleting a family master only removes a sibling with a bundled free-domain discount', function (string $siblingType, array $config, float $discount, bool $deleteSibling): void {
    $master = createEntity(ClientFence\Mod\Order\Entity\Order::class, [
        'id' => 7, 'client_id' => 3, 'group_id' => 'family-7', 'group_master' => true,
        'status' => ClientFence\Mod\Order\Entity\Order::STATUS_PENDING_SETUP, 'unpaid_invoice_id' => 11,
        'service_type' => $siblingType === 'domain' ? 'hosting' : 'domain',
        'config' => '{"free_domain":true}', 'discount' => 10.0,
    ]);
    $sibling = createEntity(ClientFence\Mod\Order\Entity\Order::class, [
        'id' => 8, 'client_id' => 3, 'group_id' => 'family-7', 'group_master' => true,
        'status' => ClientFence\Mod\Order\Entity\Order::STATUS_PENDING_SETUP, 'unpaid_invoice_id' => 11,
        'service_type' => $siblingType, 'config' => json_encode($config), 'discount' => $discount,
    ]);
    $repository = Mockery::mock(ClientFence\Mod\Order\Repository\OrderRepository::class);
    $repository->shouldReceive('findBy')->once()->with([
        'clientId' => 3,
        'groupId' => 'family-7',
        'status' => ['pending_setup', 'failed_setup'],
        'unpaidInvoiceId' => 11,
    ])->andReturn([$master, $sibling]);
    $service = Mockery::mock(Service::class);
    $service->shouldReceive('getOrderRepository')->once()->andReturn($repository);
    $service->shouldReceive('deleteFromOrder')->once()->with($master)->andReturn(true);
    $service->shouldReceive('deleteFromOrder')->times($deleteSibling ? 1 : 0)->with($sibling)->andReturn(true);
    $dbal = Mockery::mock(Doctrine\DBAL\Connection::class);
    $dbal->shouldReceive('fetchOne')->once()->with(Mockery::type('string'), ['order', '7', 'paid', 'refunded'])->andReturn(false);
    $dbal->shouldReceive('fetchOne')->times($deleteSibling ? 1 : 0)->with(Mockery::type('string'), ['order', '8', 'paid', 'refunded'])->andReturn(false);
    $api = apiEndpoint(Mockery::mock(Client::class)->makePartial());
    $api->shouldAllowMockingProtectedMethods();
    $api->shouldReceive('_getOrder')->once()->andReturn($master);
    $api->getDi()['dbal'] = $dbal;
    $api->setService($service);

    expect($api->delete(['id' => 7]))->toBeTrue();
})->with([
    'free domain' => ['domain', ['free_domain' => true], 10.0, true],
    'free transfer' => ['domain', ['free_transfer' => true], 10.0, true],
    'domain without a bundle flag' => ['domain', [], 10.0, false],
    'domain without a discount' => ['domain', ['free_domain' => true], 0.0, false],
    'deleting a domain keeps its discounted hosting' => ['hosting', ['free_domain' => true], 10.0, false],
]);

// ClientFence: a client cannot delete an order that was paid for (staff still can).
test('refuses to delete a failed setup order that was paid for', function (): void {
    $order = createEntity(ClientFence\Mod\Order\Entity\Order::class, ['id' => 7, 'status' => ClientFence\Mod\Order\Entity\Order::STATUS_FAILED_SETUP]);

    $apiMock = apiEndpoint(Mockery::mock(Client::class)->makePartial());
    $apiMock->shouldAllowMockingProtectedMethods();
    $apiMock->shouldReceive('_getOrder')->once()->andReturn($order);
    $dbal = Mockery::mock(Doctrine\DBAL\Connection::class);
    $dbal->shouldReceive('fetchOne')->once()->with(Mockery::type('string'), Mockery::on(fn (array $p): bool => $p[1] === '7' && in_array('paid', $p, true)))->andReturn(1);
    $di = $apiMock->getDi();
    $di['dbal'] = $dbal;

    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('deleteFromOrder')->never();
    $apiMock->setService($serviceMock);

    expect(fn () => $apiMock->delete(['id' => 7]))->toThrow(ClientFence\InformationException::class, 'A paid order cannot be deleted. Please contact support.');
});

test('throws exception when deleting non-pending order', function (): void {
    $order = createEntity(ClientFence\Mod\Order\Entity\Order::class);

    $apiMock = apiEndpoint(Mockery::mock(Client::class)->makePartial());
    $apiMock->shouldAllowMockingProtectedMethods();
    $apiMock->shouldReceive('_getOrder')->atLeast()->once()->andReturn($order);

    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('deleteFromOrder')->never()->andReturn(true);

    $apiMock->setService($serviceMock);

    $data = ['id' => 1];

    expect(fn () => $apiMock->delete($data))->toThrow(ClientFence\Exception::class);
});

test('gets order via getOrder', function (): void {
    $api = apiEndpoint(new Client());

    $order = createEntity(ClientFence\Mod\Order\Entity\Order::class);

    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('findEntityForClientById')->atLeast()->once()->andReturn($order);
    $serviceMock->shouldReceive('toApiArray')->atLeast()->once()->andReturn([]);

    $client = createEntity(ClientFence\Mod\Client\Entity\Client::class);

    $di = container();
    $api->setDi($di);
    $api->setService($serviceMock);
    $api->setIdentity($client);

    $data = ['id' => 1];
    $api->get($data);
});

test('throws exception when order not found', function (): void {
    $api = apiEndpoint(new Client());

    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('findEntityForClientById')->atLeast()->once()->andReturn(null);
    $serviceMock->shouldReceive('toApiArray')->never()->andReturn([]);

    $client = createEntity(ClientFence\Mod\Client\Entity\Client::class);

    $di = container();
    $api->setDi($di);
    $api->setService($serviceMock);
    $api->setIdentity($client);

    $data = ['id' => 1];

    expect(fn () => $api->get($data))->toThrow(ClientFence\Exception::class);
});
