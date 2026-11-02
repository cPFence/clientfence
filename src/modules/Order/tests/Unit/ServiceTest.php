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
use ClientFence\Mod\Order\Repository\OrderRepository;
use ClientFence\Mod\Order\Service;
use ClientFence\Mod\Product\Entity\Product;

use function Tests\Helpers\container;
use function Tests\Helpers\createEntity;

function orderServiceCreateProductEntity(?int $id = null, ?string $type = null): Product
{
    $product = new Product();
    if ($id !== null) {
        $reflection = new ReflectionProperty($product, 'id');
        $reflection->setValue($product, $id);
    }
    if ($type !== null) {
        $product->setType($type);
    }

    return $product;
}

function orderServiceCreateInvoiceModel(int $id): Model_Invoice
{
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    $invoice->id = $id;

    return $invoice;
}

function orderServiceCreateLegacyOrderModel(int $id): Model_ClientOrder
{
    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->id = $id;

    return $order;
}

test('counter returns status counts', function (): void {
    $service = new Service();

    $counter = [Order::STATUS_ACTIVE => 1];
    $connectionMock = Mockery::mock(Doctrine\DBAL\Connection::class);
    $connectionMock->shouldReceive('fetchAllKeyValue')->atLeast()->once()->andReturn($counter);
    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('getConnection')->atLeast()->once()->andReturn($connectionMock);

    $di = container();
    $di['em'] = $emMock;
    $service->setDi($di);

    $result = $service->counter();

    expect($result)->toBeArray();
    expect($result)->toHaveKey('total');
    expect($result['total'])->toEqual(array_sum($counter));
    expect($result)->toHaveKey(Order::STATUS_PENDING_SETUP);
    expect($result)->toHaveKey(Order::STATUS_FAILED_SETUP);
    expect($result)->toHaveKey(Order::STATUS_ACTIVE);
    expect($result)->toHaveKey(Order::STATUS_SUSPENDED);
    expect($result)->toHaveKey(Order::STATUS_CANCELED);
});

test('batch order serialization does not expose admin-only client details', function (): void {
    $client = createEntity(ClientFence\Mod\Client\Entity\Client::class, ['id' => 7]);
    $admin = createEntity(ClientFence\Mod\Staff\Entity\Admin::class);

    $clientRepository = Mockery::mock(ClientFence\Mod\Client\Repository\ClientRepository::class);
    $clientRepository->shouldReceive('findBy')->once()->with(['id' => [7]])->andReturn([$client]);

    // Realistic non-admin client payload: the fields the admin order list UI needs
    // (see mod_order_index.html.twig and partial_dashboard_orders_card.html.twig,
    // which read order.client.email/first_name/last_name). An ID-only reference
    // would break those templates, so the boundary must keep general fields while
    // excluding admin-only ones.
    $generalClient = [
        'id' => 7,
        'email' => 'jane@example.com',
        'first_name' => 'Jane',
        'last_name' => 'Doe',
    ];

    $clientService = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $clientService->shouldReceive('toApiArray')
        ->once()
        ->withArgs(fn (...$args) => count($args) === 2 && $args[0] === $client && $args[1] === false)
        ->andReturn($generalClient);

    $productService = Mockery::mock(ClientFence\Mod\Product\Service::class);
    $productService->shouldReceive('getProductPluginMap')->once()->with([3])->andReturn([]);

    $connection = Mockery::mock(Doctrine\DBAL\Connection::class);
    $connection->shouldReceive('fetchAllAssociative')
        ->once()
        ->with('SELECT * FROM client_order WHERE id IN (?)', [11])
        ->andReturn([[
            'id' => 11,
            'client_id' => 7,
            'product_id' => 3,
            'config' => '{}',
            'price' => 10,
            'quantity' => 1,
            'title' => 'Example order',
        ]]);
    $connection->shouldReceive('fetchAllAssociative')->twice()->andReturn([]);

    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($connection);
    $em->shouldReceive('getRepository')
        ->once()
        ->with(ClientFence\Mod\Client\Entity\Client::class)
        ->andReturn($clientRepository);

    $di = container();
    $di['em'] = $em;
    $di['mod_service'] = $di->protect(fn (string $name) => match ($name) {
        'client' => $clientService,
        'product' => $productService,
    });

    $service = new Service();
    $service->setDi($di);

    $result = $service->getBatchForApi([11], $admin);

    // The batch boundary forwards no identity, so the embedded client must be
    // the general (non-admin) representation: UI fields present, admin-only
    // fields absent.
    expect($result[0]['client'])->toBe($generalClient);
    foreach (['aid', 'status', 'notes', 'ip', 'billing_email', 'group', 'client_group', 'api_token'] as $adminOnlyKey) {
        expect($result[0]['client'])->not->toHaveKey($adminOnlyKey);
    }
});

test('order serialization exposes provisioning passwords only to staff', function (string $who): void {
    $config = ['password' => 'hosting-secret', 'domain' => 'example.test'];
    $row = [
        'id' => 11, 'client_id' => 7, 'product_id' => 3, 'title' => 'Hosting',
        'config' => json_encode($config), 'price' => 10, 'quantity' => 1,
    ];
    $order = createEntity(Order::class, $row);
    $client = createEntity(ClientFence\Mod\Client\Entity\Client::class, ['id' => 7]);
    $identity = match ($who) {
        'client' => $client,
        'admin entity' => createEntity(ClientFence\Mod\Staff\Entity\Admin::class),
        'staff session' => new Model_Admin(),
    };

    $di = container();
    $clientRepository = $di['em']->getRepository(ClientFence\Mod\Client\Entity\Client::class);
    $clientRepository->shouldReceive('findBy')->with(['id' => [7]])->andReturn([$client]);
    $metaRepository = Mockery::mock(ClientFence\Mod\Order\Repository\OrderMetaRepository::class);
    $metaRepository->shouldReceive('getPairsForOrder')->with(11)->andReturn([]);
    $di['em']->shouldReceive('getRepository')->with(ClientFence\Mod\Order\Entity\OrderMeta::class)->andReturn($metaRepository);

    $connection = Mockery::mock(Doctrine\DBAL\Connection::class);
    $connection->shouldReceive('fetchAllAssociative')->with('SELECT * FROM client_order WHERE id IN (?)', [11])->andReturn([$row]);
    $connection->shouldReceive('fetchAllAssociative')->with(Mockery::type('string'), [11])->andReturn([]);
    $di['em']->shouldReceive('getConnection')->andReturn($connection);

    $clientService = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $clientService->shouldReceive('toApiArray')->andReturn([]);
    $ticketRepository = Mockery::mock(ClientFence\Mod\Support\Repository\SupportTicketRepository::class);
    $ticketRepository->shouldReceive('countActiveTicketsForOrder')->with(11)->andReturn(0);
    $supportService = Mockery::mock(ClientFence\Mod\Support\Service::class);
    $supportService->shouldReceive('getSupportTicketRepository')->andReturn($ticketRepository);
    $productService = Mockery::mock(ClientFence\Mod\Product\Service::class);
    $productService->shouldReceive('getProductPluginById')->andReturn(null);
    $productService->shouldReceive('getProductPluginMap')->andReturn([]);
    $di['mod_service'] = $di->protect(fn (string $name) => match ($name) {
        'client' => $clientService,
        'support' => $supportService,
        'product' => $productService,
    });

    $service = new Service();
    $service->setDi($di);
    $expected = $who === 'client' ? ['domain' => 'example.test'] : $config;

    expect($service->toApiArray($order, true, $identity)['config'])->toBe($expected)
        ->and($service->getBatchForApi([11], $identity)[0]['config'])->toBe($expected);
})->with(['client', 'admin entity', 'staff session']);

test('batch client serialization excludes admin-only fields on the real path', function (): void {
    $client = createEntity(ClientFence\Mod\Client\Entity\Client::class);
    $client->setEmail('jane@example.com');
    $client->setFirstName('Jane');
    $client->setLastName('Doe');

    $di = container();
    $di['mod_config'] = $di->protect(fn (string $name) => []);

    $clientService = new ClientFence\Mod\Client\Service();
    $clientService->setDi($di);

    $admin = createEntity(ClientFence\Mod\Staff\Entity\Admin::class);
    $general = $clientService->toApiArray($client, false);
    $adminView = $clientService->toApiArray($client, false, $admin);

    expect($general['email'])->toBe('jane@example.com');
    foreach (['aid', 'status', 'notes', 'ip', 'billing_email'] as $adminOnlyKey) {
        expect($general)->not->toHaveKey($adminOnlyKey);
        expect($adminView)->toHaveKey($adminOnlyKey);
    }
});

test('onAfterAdminOrderActivate fires template', function (): void {
    $params = ['id' => 1];

    $eventMock = Mockery::mock(\ClientFence\Event::class);
    $eventMock->shouldReceive('getParameters')->atLeast()->once()->andReturn($params);

    $emailServiceMock = Mockery::mock(ClientFence\Mod\Email\Service::class);
    $emailServiceMock->shouldReceive('sendTemplate')->atLeast()->once()->andReturn(true);

    $orderArr = [
        'id' => 1,
        'client' => ['id' => 1],
        'service_type' => 'domain',
    ];

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getOrderServiceData')->atLeast()->once()->andReturn([]);
    $serviceMock->shouldReceive('toApiArray')->atLeast()->once()->andReturn($orderArr);

    $admin = createEntity(ClientFence\Mod\Staff\Entity\Admin::class);

    $di = container();
    $di['em']->getRepository(Order::class)->shouldReceive('find')->byDefault()->andReturn(createEntity(Order::class, ['id' => 1]));
    $di['loggedin_admin'] = $admin;
    $di['mod_service'] = $di->protect(function ($serviceName) use ($emailServiceMock, $serviceMock) {
        if ($serviceName == 'email') {
            return $emailServiceMock;
        }
        if ($serviceName == 'order') {
            return $serviceMock;
        }
    });

    $eventMock->shouldReceive('getDi')->atLeast()->once()->andReturn($di);
    $serviceMock->setDi($di);

    $serviceMock->onAfterAdminOrderActivate($eventMock);
});

test('onAfterAdminOrderActivate logs exceptions', function (): void {
    $params = ['id' => 1];

    $eventMock = Mockery::mock(\ClientFence\Event::class);
    $eventMock->shouldReceive('getParameters')->atLeast()->once()->andReturn($params);

    $emailServiceMock = Mockery::mock(ClientFence\Mod\Email\Service::class);
    $emailServiceMock->shouldReceive('sendTemplate')
        ->atLeast()->once()
        ->andThrow(new Exception('PHPUnit controlled exception'));

    $orderArr = [
        'id' => 1,
        'client' => ['id' => 1],
        'service_type' => 'domain',
    ];

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getOrderServiceData')->atLeast()->once()->andReturn([]);
    $serviceMock->shouldReceive('toApiArray')->atLeast()->once()->andReturn($orderArr);

    $admin = createEntity(ClientFence\Mod\Staff\Entity\Admin::class);

    $di = container();
    $di['em']->getRepository(Order::class)->shouldReceive('find')->byDefault()->andReturn(createEntity(Order::class, ['id' => 1]));
    $di['loggedin_admin'] = $admin;
    $di['mod_service'] = $di->protect(function ($serviceName) use ($emailServiceMock, $serviceMock) {
        if ($serviceName == 'email') {
            return $emailServiceMock;
        }
        if ($serviceName == 'order') {
            return $serviceMock;
        }
    });

    $eventMock->shouldReceive('getDi')->atLeast()->once()->andReturn($di);
    $serviceMock->setDi($di);

    $serviceMock->onAfterAdminOrderActivate($eventMock);
});

test('onAfterAdminOrderRenew fires template', function (): void {
    $params = ['id' => 1];

    $eventMock = Mockery::mock(\ClientFence\Event::class);
    $eventMock->shouldReceive('getParameters')->atLeast()->once()->andReturn($params);

    $emailServiceMock = Mockery::mock(ClientFence\Mod\Email\Service::class);
    $emailServiceMock->shouldReceive('sendTemplate')->atLeast()->once()->andReturn(true);

    $orderArr = [
        'id' => 1,
        'client' => ['id' => 1],
        'service_type' => 'domain',
    ];

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getOrderServiceData')->atLeast()->once()->andReturn([]);
    $serviceMock->shouldReceive('toApiArray')->atLeast()->once()->andReturn($orderArr);

    $admin = createEntity(ClientFence\Mod\Staff\Entity\Admin::class);

    $di = container();
    $di['em']->getRepository(Order::class)->shouldReceive('find')->byDefault()->andReturn(createEntity(Order::class, ['id' => 1]));
    $di['loggedin_admin'] = $admin;
    $di['mod_service'] = $di->protect(function ($serviceName) use ($emailServiceMock, $serviceMock) {
        if ($serviceName == 'email') {
            return $emailServiceMock;
        }
        if ($serviceName == 'order') {
            return $serviceMock;
        }
    });

    $serviceMock->setDi($di);
    $eventMock->shouldReceive('getDi')->atLeast()->once()->andReturn($di);

    $serviceMock->onAfterAdminOrderRenew($eventMock);
});

test('onAfterAdminOrderRenew fires template without an admin session', function (): void {
    $params = ['id' => 1];

    $eventMock = Mockery::mock(\ClientFence\Event::class);
    $eventMock->shouldReceive('getParameters')->once()->andReturn($params);

    $order = createEntity(Order::class, ['id' => 1]);
    $orderArr = [
        'id' => 1,
        'client' => ['id' => 1],
        'service_type' => 'domain',
    ];

    $emailServiceMock = Mockery::mock(ClientFence\Mod\Email\Service::class);
    $emailServiceMock->shouldReceive('sendTemplate')->once()->with([
        'to_client' => 1,
        'code' => 'mod_servicedomain_renewed',
        'service' => [],
        'order' => $orderArr,
    ])->andReturn(true);

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getOrderServiceData')->once()->with($order, null)->andReturn([]);
    $serviceMock->shouldReceive('toApiArray')->once()->with($order, true, null)->andReturn($orderArr);

    $di = container();
    $di['em']->getRepository(Order::class)->shouldReceive('find')->once()->with(1)->andReturn($order);
    $di['mod_service'] = $di->protect(function ($serviceName) use ($emailServiceMock, $serviceMock) {
        if ($serviceName == 'email') {
            return $emailServiceMock;
        }
        if ($serviceName == 'order') {
            return $serviceMock;
        }
    });

    $serviceMock->setDi($di);
    $eventMock->shouldReceive('getDi')->once()->andReturn($di);

    $serviceMock->onAfterAdminOrderRenew($eventMock);
});

test('onAfterAdminOrderRenew logs exceptions', function (): void {
    $params = ['id' => 1];

    $eventMock = Mockery::mock(\ClientFence\Event::class);
    $eventMock->shouldReceive('getParameters')->atLeast()->once()->andReturn($params);

    $emailServiceMock = Mockery::mock(ClientFence\Mod\Email\Service::class);
    $emailServiceMock->shouldReceive('sendTemplate')
        ->atLeast()->once()
        ->andThrow(new Exception('PHPUnit controlled exception'));

    $orderArr = [
        'id' => 1,
        'client' => ['id' => 1],
        'service_type' => 'domain',
    ];

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getOrderServiceData')->atLeast()->once()->andReturn([]);
    $serviceMock->shouldReceive('toApiArray')->atLeast()->once()->andReturn($orderArr);

    $admin = createEntity(ClientFence\Mod\Staff\Entity\Admin::class);

    $di = container();
    $di['em']->getRepository(Order::class)->shouldReceive('find')->byDefault()->andReturn(createEntity(Order::class, ['id' => 1]));
    $di['loggedin_admin'] = $admin;
    $di['mod_service'] = $di->protect(function ($serviceName) use ($emailServiceMock, $serviceMock) {
        if ($serviceName == 'email') {
            return $emailServiceMock;
        }
        if ($serviceName == 'order') {
            return $serviceMock;
        }
    });

    $serviceMock->setDi($di);
    $eventMock->shouldReceive('getDi')->atLeast()->once()->andReturn($di);

    $serviceMock->onAfterAdminOrderRenew($eventMock);
});

test('onAfterAdminOrderSuspend fires template', function (): void {
    $params = ['id' => 1];

    $eventMock = Mockery::mock(\ClientFence\Event::class);
    $eventMock->shouldReceive('getParameters')->atLeast()->once()->andReturn($params);

    $emailServiceMock = Mockery::mock(ClientFence\Mod\Email\Service::class);
    $emailServiceMock->shouldReceive('sendTemplate')->atLeast()->once()->andReturn(true);

    $orderArr = [
        'id' => 1,
        'client' => ['id' => 1],
        'service_type' => 'domain',
    ];

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getOrderServiceData')->atLeast()->once()->andReturn([]);
    $serviceMock->shouldReceive('toApiArray')->atLeast()->once()->andReturn($orderArr);

    $admin = createEntity(ClientFence\Mod\Staff\Entity\Admin::class);

    $di = container();
    $di['em']->getRepository(Order::class)->shouldReceive('find')->byDefault()->andReturn(createEntity(Order::class, ['id' => 1]));
    $di['loggedin_admin'] = $admin;
    $di['mod_service'] = $di->protect(function ($serviceName) use ($emailServiceMock, $serviceMock) {
        if ($serviceName == 'email') {
            return $emailServiceMock;
        }
        if ($serviceName == 'order') {
            return $serviceMock;
        }
    });

    $serviceMock->setDi($di);
    $eventMock->shouldReceive('getDi')->atLeast()->once()->andReturn($di);

    $serviceMock->onAfterAdminOrderSuspend($eventMock);
});

test('onAfterAdminOrderSuspend fires template without an admin session', function (): void {
    $params = ['id' => 1];

    $eventMock = Mockery::mock(\ClientFence\Event::class);
    $eventMock->shouldReceive('getParameters')->once()->andReturn($params);

    $order = createEntity(Order::class, ['id' => 1]);
    $orderArr = [
        'id' => 1,
        'client' => ['id' => 1],
        'service_type' => 'domain',
    ];

    $emailServiceMock = Mockery::mock(ClientFence\Mod\Email\Service::class);
    $emailServiceMock->shouldReceive('sendTemplate')->once()->with([
        'to_client' => 1,
        'code' => 'mod_servicedomain_suspended',
        'service' => [],
        'order' => $orderArr,
    ])->andReturn(true);

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getOrderServiceData')->once()->with($order, null)->andReturn([]);
    $serviceMock->shouldReceive('toApiArray')->once()->with($order, true, null)->andReturn($orderArr);

    $di = container();
    $di['em']->getRepository(Order::class)->shouldReceive('find')->once()->with(1)->andReturn($order);
    $di['mod_service'] = $di->protect(function ($serviceName) use ($emailServiceMock, $serviceMock) {
        if ($serviceName == 'email') {
            return $emailServiceMock;
        }
        if ($serviceName == 'order') {
            return $serviceMock;
        }
    });

    $serviceMock->setDi($di);
    $eventMock->shouldReceive('getDi')->once()->andReturn($di);

    $serviceMock->onAfterAdminOrderSuspend($eventMock);
});

test('onAfterAdminOrderSuspend logs exceptions', function (): void {
    $params = ['id' => 1];

    $eventMock = Mockery::mock(\ClientFence\Event::class);
    $eventMock->shouldReceive('getParameters')->atLeast()->once()->andReturn($params);

    $emailServiceMock = Mockery::mock(ClientFence\Mod\Email\Service::class);
    $emailServiceMock->shouldReceive('sendTemplate')
        ->atLeast()->once()
        ->andThrow(new Exception('PHPUnit controlled exception'));

    $orderArr = [
        'id' => 1,
        'client' => ['id' => 1],
        'service_type' => 'domain',
    ];

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getOrderServiceData')->atLeast()->once()->andReturn([]);
    $serviceMock->shouldReceive('toApiArray')->atLeast()->once()->andReturn($orderArr);

    $admin = createEntity(ClientFence\Mod\Staff\Entity\Admin::class);

    $di = container();
    $di['em']->getRepository(Order::class)->shouldReceive('find')->byDefault()->andReturn(createEntity(Order::class, ['id' => 1]));
    $di['loggedin_admin'] = $admin;
    $di['mod_service'] = $di->protect(function ($serviceName) use ($emailServiceMock, $serviceMock) {
        if ($serviceName == 'email') {
            return $emailServiceMock;
        }
        if ($serviceName == 'order') {
            return $serviceMock;
        }
    });

    $serviceMock->setDi($di);
    $eventMock->shouldReceive('getDi')->atLeast()->once()->andReturn($di);

    $serviceMock->onAfterAdminOrderSuspend($eventMock);
});

test('onAfterAdminOrderUnsuspend fires template', function (): void {
    $params = ['id' => 1];

    $eventMock = Mockery::mock(\ClientFence\Event::class);
    $eventMock->shouldReceive('getParameters')->atLeast()->once()->andReturn($params);

    $emailServiceMock = Mockery::mock(ClientFence\Mod\Email\Service::class);
    $emailServiceMock->shouldReceive('sendTemplate')->atLeast()->once()->andReturn(true);

    $orderArr = [
        'id' => 1,
        'client' => ['id' => 1],
        'service_type' => 'domain',
    ];

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getOrderServiceData')->atLeast()->once()->andReturn([]);
    $serviceMock->shouldReceive('toApiArray')->atLeast()->once()->andReturn($orderArr);

    $admin = createEntity(ClientFence\Mod\Staff\Entity\Admin::class);

    $di = container();
    $di['em']->getRepository(Order::class)->shouldReceive('find')->byDefault()->andReturn(createEntity(Order::class, ['id' => 1]));
    $di['loggedin_admin'] = $admin;
    $di['mod_service'] = $di->protect(function ($serviceName) use ($emailServiceMock, $serviceMock) {
        if ($serviceName == 'email') {
            return $emailServiceMock;
        }
        if ($serviceName == 'order') {
            return $serviceMock;
        }
    });

    $serviceMock->setDi($di);
    $eventMock->shouldReceive('getDi')->atLeast()->once()->andReturn($di);

    $serviceMock->onAfterAdminOrderUnsuspend($eventMock);
});

test('onAfterAdminOrderUnsuspend fires template without an admin session', function (): void {
    $params = ['id' => 1];

    $eventMock = Mockery::mock(\ClientFence\Event::class);
    $eventMock->shouldReceive('getParameters')->once()->andReturn($params);

    $order = createEntity(Order::class, ['id' => 1]);
    $orderArr = [
        'id' => 1,
        'client' => ['id' => 1],
        'service_type' => 'domain',
    ];

    $emailServiceMock = Mockery::mock(ClientFence\Mod\Email\Service::class);
    $emailServiceMock->shouldReceive('sendTemplate')->once()->with([
        'to_client' => 1,
        'code' => 'mod_servicedomain_unsuspended',
        'service' => [],
        'order' => $orderArr,
    ])->andReturn(true);

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getOrderServiceData')->once()->with($order, null)->andReturn([]);
    $serviceMock->shouldReceive('toApiArray')->once()->with($order, true, null)->andReturn($orderArr);

    $di = container();
    $di['em']->getRepository(Order::class)->shouldReceive('find')->once()->with(1)->andReturn($order);
    $di['mod_service'] = $di->protect(function ($serviceName) use ($emailServiceMock, $serviceMock) {
        if ($serviceName == 'email') {
            return $emailServiceMock;
        }
        if ($serviceName == 'order') {
            return $serviceMock;
        }
    });

    $serviceMock->setDi($di);
    $eventMock->shouldReceive('getDi')->once()->andReturn($di);

    $serviceMock->onAfterAdminOrderUnsuspend($eventMock);
});

test('onAfterAdminOrderUnsuspend logs exceptions', function (): void {
    $params = ['id' => 1];

    $eventMock = Mockery::mock(\ClientFence\Event::class);
    $eventMock->shouldReceive('getParameters')->atLeast()->once()->andReturn($params);

    $emailServiceMock = Mockery::mock(ClientFence\Mod\Email\Service::class);
    $emailServiceMock->shouldReceive('sendTemplate')
        ->atLeast()->once()
        ->andThrow(new Exception('PHPUnit controlled exception'));

    $orderArr = [
        'id' => 1,
        'client' => ['id' => 1],
        'service_type' => 'domain',
    ];

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getOrderServiceData')->atLeast()->once()->andReturn([]);
    $serviceMock->shouldReceive('toApiArray')->atLeast()->once()->andReturn($orderArr);

    $admin = createEntity(ClientFence\Mod\Staff\Entity\Admin::class);

    $di = container();
    $di['em']->getRepository(Order::class)->shouldReceive('find')->byDefault()->andReturn(createEntity(Order::class, ['id' => 1]));
    $di['loggedin_admin'] = $admin;
    $di['mod_service'] = $di->protect(function ($serviceName) use ($emailServiceMock, $serviceMock) {
        if ($serviceName == 'email') {
            return $emailServiceMock;
        }
        if ($serviceName == 'order') {
            return $serviceMock;
        }
    });

    $serviceMock->setDi($di);
    $eventMock->shouldReceive('getDi')->atLeast()->once()->andReturn($di);

    $serviceMock->onAfterAdminOrderUnsuspend($eventMock);
});

test('onAfterAdminOrderCancel fires template', function (): void {
    $params = ['id' => 1];

    $eventMock = Mockery::mock(\ClientFence\Event::class);
    $eventMock->shouldReceive('getParameters')->atLeast()->once()->andReturn($params);

    $emailServiceMock = Mockery::mock(ClientFence\Mod\Email\Service::class);
    $emailServiceMock->shouldReceive('sendTemplate')->atLeast()->once()->andReturn(true);

    $orderArr = [
        'id' => 1,
        'client' => ['id' => 1],
        'service_type' => 'domain',
    ];

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('toApiArray')->atLeast()->once()->andReturn($orderArr);

    $admin = createEntity(ClientFence\Mod\Staff\Entity\Admin::class);

    $di = container();
    $di['em']->getRepository(Order::class)->shouldReceive('find')->byDefault()->andReturn(createEntity(Order::class, ['id' => 1]));
    $di['loggedin_admin'] = $admin;
    $di['mod_service'] = $di->protect(function ($serviceName) use ($emailServiceMock, $serviceMock) {
        if ($serviceName == 'email') {
            return $emailServiceMock;
        }
        if ($serviceName == 'order') {
            return $serviceMock;
        }
    });

    $serviceMock->setDi($di);
    $eventMock->shouldReceive('getDi')->atLeast()->once()->andReturn($di);

    $serviceMock->onAfterAdminOrderCancel($eventMock);
});

test('onAfterAdminOrderCancel fires template without an admin session', function (): void {
    $params = ['id' => 1];

    $eventMock = Mockery::mock(\ClientFence\Event::class);
    $eventMock->shouldReceive('getParameters')->once()->andReturn($params);

    $order = createEntity(Order::class, ['id' => 1]);
    $orderArr = [
        'id' => 1,
        'client' => ['id' => 1],
        'service_type' => 'domain',
    ];

    $emailServiceMock = Mockery::mock(ClientFence\Mod\Email\Service::class);
    $emailServiceMock->shouldReceive('sendTemplate')->once()->with([
        'to_client' => 1,
        'code' => 'mod_servicedomain_canceled',
        'order' => $orderArr,
    ])->andReturn(true);

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('toApiArray')->once()->with($order, true, null)->andReturn($orderArr);

    $di = container();
    $di['em']->getRepository(Order::class)->shouldReceive('find')->once()->with(1)->andReturn($order);
    $di['mod_service'] = $di->protect(function ($serviceName) use ($emailServiceMock, $serviceMock) {
        if ($serviceName == 'email') {
            return $emailServiceMock;
        }
        if ($serviceName == 'order') {
            return $serviceMock;
        }
    });

    $serviceMock->setDi($di);
    $eventMock->shouldReceive('getDi')->once()->andReturn($di);

    $serviceMock->onAfterAdminOrderCancel($eventMock);
});

test('onAfterAdminOrderCancel logs exceptions', function (): void {
    $params = ['id' => 1];

    $eventMock = Mockery::mock(\ClientFence\Event::class);
    $eventMock->shouldReceive('getParameters')->atLeast()->once()->andReturn($params);

    $emailServiceMock = Mockery::mock(ClientFence\Mod\Email\Service::class);
    $emailServiceMock->shouldReceive('sendTemplate')
        ->atLeast()->once()
        ->andThrow(new Exception('PHPUnit controlled exception'));

    $orderArr = [
        'id' => 1,
        'client' => ['id' => 1],
        'service_type' => 'domain',
    ];

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('toApiArray')->atLeast()->once()->andReturn($orderArr);

    $admin = createEntity(ClientFence\Mod\Staff\Entity\Admin::class);

    $di = container();
    $di['em']->getRepository(Order::class)->shouldReceive('find')->byDefault()->andReturn(createEntity(Order::class, ['id' => 1]));
    $di['loggedin_admin'] = $admin;
    $di['mod_service'] = $di->protect(function ($serviceName) use ($emailServiceMock, $serviceMock) {
        if ($serviceName == 'email') {
            return $emailServiceMock;
        }
        if ($serviceName == 'order') {
            return $serviceMock;
        }
    });

    $serviceMock->setDi($di);
    $eventMock->shouldReceive('getDi')->atLeast()->once()->andReturn($di);

    $serviceMock->onAfterAdminOrderCancel($eventMock);
});

test('onAfterAdminOrderUncancel fires template', function (): void {
    $params = ['id' => 1];

    $eventMock = Mockery::mock(\ClientFence\Event::class);
    $eventMock->shouldReceive('getParameters')->atLeast()->once()->andReturn($params);

    $emailServiceMock = Mockery::mock(ClientFence\Mod\Email\Service::class);
    $emailServiceMock->shouldReceive('sendTemplate')->atLeast()->once()->andReturn(true);

    $orderArr = [
        'id' => 1,
        'client' => ['id' => 1],
        'service_type' => 'domain',
    ];

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getOrderServiceData')->atLeast()->once()->andReturn([]);
    $serviceMock->shouldReceive('toApiArray')->atLeast()->once()->andReturn($orderArr);

    $admin = createEntity(ClientFence\Mod\Staff\Entity\Admin::class);

    $di = container();
    $di['em']->getRepository(Order::class)->shouldReceive('find')->byDefault()->andReturn(createEntity(Order::class, ['id' => 1]));
    $di['loggedin_admin'] = $admin;
    $di['mod_service'] = $di->protect(function ($serviceName) use ($emailServiceMock, $serviceMock) {
        if ($serviceName == 'email') {
            return $emailServiceMock;
        }
        if ($serviceName == 'order') {
            return $serviceMock;
        }
    });

    $serviceMock->setDi($di);
    $eventMock->shouldReceive('getDi')->atLeast()->once()->andReturn($di);

    $serviceMock->onAfterAdminOrderUncancel($eventMock);
});

test('onAfterAdminOrderUncancel fires template without an admin session', function (): void {
    $params = ['id' => 1];

    $eventMock = Mockery::mock(\ClientFence\Event::class);
    $eventMock->shouldReceive('getParameters')->once()->andReturn($params);

    $order = createEntity(Order::class, ['id' => 1]);
    $orderArr = [
        'id' => 1,
        'client' => ['id' => 1],
        'service_type' => 'domain',
    ];

    $emailServiceMock = Mockery::mock(ClientFence\Mod\Email\Service::class);
    $emailServiceMock->shouldReceive('sendTemplate')->once()->with([
        'to_client' => 1,
        'code' => 'mod_servicedomain_renewed',
        'order' => $orderArr,
        'service' => [],
    ])->andReturn(true);

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getOrderServiceData')->once()->with($order, null)->andReturn([]);
    $serviceMock->shouldReceive('toApiArray')->once()->with($order, true, null)->andReturn($orderArr);

    $di = container();
    $di['em']->getRepository(Order::class)->shouldReceive('find')->once()->with(1)->andReturn($order);
    $di['mod_service'] = $di->protect(function ($serviceName) use ($emailServiceMock, $serviceMock) {
        if ($serviceName == 'email') {
            return $emailServiceMock;
        }
        if ($serviceName == 'order') {
            return $serviceMock;
        }
    });

    $serviceMock->setDi($di);
    $eventMock->shouldReceive('getDi')->once()->andReturn($di);

    $serviceMock->onAfterAdminOrderUncancel($eventMock);
});

test('onAfterAdminOrderUncancel logs exceptions', function (): void {
    $params = ['id' => 1];

    $eventMock = Mockery::mock(\ClientFence\Event::class);
    $eventMock->shouldReceive('getParameters')->atLeast()->once()->andReturn($params);

    $emailServiceMock = Mockery::mock(ClientFence\Mod\Email\Service::class);
    $emailServiceMock->shouldReceive('sendTemplate')
        ->atLeast()->once()
        ->andThrow(new Exception('PHPUnit controlled exception'));

    $orderArr = [
        'id' => 1,
        'client' => ['id' => 1],
        'service_type' => 'domain',
    ];

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getOrderServiceData')->atLeast()->once()->andReturn([]);
    $serviceMock->shouldReceive('toApiArray')->atLeast()->once()->andReturn($orderArr);

    $admin = createEntity(ClientFence\Mod\Staff\Entity\Admin::class);

    $di = container();
    $di['em']->getRepository(Order::class)->shouldReceive('find')->byDefault()->andReturn(createEntity(Order::class, ['id' => 1]));
    $di['loggedin_admin'] = $admin;
    $di['mod_service'] = $di->protect(function ($serviceName) use ($emailServiceMock, $serviceMock) {
        if ($serviceName == 'email') {
            return $emailServiceMock;
        }
        if ($serviceName == 'order') {
            return $serviceMock;
        }
    });

    $serviceMock->setDi($di);
    $eventMock->shouldReceive('getDi')->atLeast()->once()->andReturn($di);

    $serviceMock->onAfterAdminOrderUncancel($eventMock);
});

test('getOrderService returns core service', function (): void {
    $serviceEntity = new ClientFence\Mod\Servicecustom\Entity\ServiceCustom();

    $serviceRepo = Mockery::mock(Doctrine\ORM\EntityRepository::class);
    $serviceRepo->shouldReceive('find')->once()->with(1)->andReturn($serviceEntity);

    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getRepository')
        ->once()
        ->with(ClientFence\Mod\Servicecustom\Entity\ServiceCustom::class)
        ->andReturn($serviceRepo);

    $di = container();
    $di['em'] = $em;

    $svc = new Service();
    $svc->setDi($di);

    $order = createEntity(Order::class, [
        'service_id' => 1,
        'service_type' => ClientFence\Mod\Product\Service::CUSTOM,
    ]);

    $result = $svc->getOrderService($order);

    expect($result)->toBeInstanceOf(ClientFence\Mod\Servicecustom\Entity\ServiceCustom::class);
});

test('getOrderService returns non-core service', function (): void {
    $serviceData = ['id' => 1, 'product_id' => 5];

    $di = container();
    $dbMock = Mockery::mock(\ClientFence\Database::class);
    $dbMock->shouldReceive('findOne')->once()->with('service_external', 'id = :id', [':id' => 1])->andReturn($serviceData);
    $di['db'] = $dbMock;

    $svc = new Service();
    $svc->setDi($di);

    $order = createEntity(Order::class, [
        'service_id' => 1,
        'service_type' => 'external',
    ]);

    $result = $svc->getOrderService($order);

    expect($result)->toBeArray();
});

test('getOrderService returns null when service id is not set', function (): void {
    $dbMock = Mockery::mock(\ClientFence\Database::class);
    $dbMock->shouldReceive('getExistingModelById')->never();
    $dbMock->shouldReceive('findOne')->never();

    $di = container();
    $di['db'] = $dbMock;

    $svc = new Service();
    $svc->setDi($di);

    $order = createEntity(Order::class);

    $result = $svc->getOrderService($order);

    expect($result)->toBeNull();
});

test('getServiceOrder returns order', function (): void {
    $toolsMock = Mockery::mock(ClientFence\Tools::class);
    $toolsMock->shouldReceive('from_camel_case')->atLeast()->once()->andReturn('custom');

    $dbMock = Mockery::mock(\ClientFence\Database::class);

    $di = container();
    $di['db'] = $dbMock;
    $di['tools'] = $toolsMock;

    $svc = new Service();
    $svc->setDi($di);

    $service = new Model_ServiceCustom();
    $service->loadBean(new Tests\Helpers\DummyBean());
    $service->id = 1;

    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->id = 1;
    $dbMock->shouldReceive('findOne')->once()->with('ClientOrder', 'service_type = :service_type AND service_id = :service_id', [
        ':service_type' => 'custom',
        ':service_id' => 1,
    ])->andReturn($order);

    $result = $svc->getServiceOrder($service);

    expect($result)->toBeInstanceOf(Model_ClientOrder::class);
});

test('keeps legacy order lookups available alongside entity lookups', function (): void {
    $client = createEntity(ClientFence\Mod\Client\Entity\Client::class, ['id' => 5]);
    $entityOrder = createEntity(Order::class, ['id' => 10, 'client_id' => 5]);
    $legacyOrder = orderServiceCreateLegacyOrderModel(10);

    $orderRepository = Mockery::mock(OrderRepository::class);
    $orderRepository->shouldReceive('findForClientById')->twice()->with(5, 10)->andReturn($entityOrder);

    $entityManager = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $entityManager->shouldReceive('getRepository')->once()->with(Order::class)->andReturn($orderRepository);

    $database = Mockery::mock(\ClientFence\Database::class);
    $database->shouldReceive('getExistingModelById')->once()->with('ClientOrder', 10)->andReturn($legacyOrder);

    $di = container();
    $di['em'] = $entityManager;
    $di['db'] = $database;

    $service = new Service();
    $service->setDi($di);

    expect($service->findEntityForClientById($client, 10))->toBe($entityOrder)
        ->and($service->findForClientById($client, 10))->toBe($legacyOrder);
});

test('getConfig returns config', function (): void {
    $svc = new Service();
    $di = container();
    $svc->setDi($di);

    $order = createEntity(Order::class);

    $result = $svc->getConfig($order);

    expect($result)->toBeArray();
});

dataset('productHasOrdersProvider', function (): array {
    $orderEntity = new Order();
    $idProp = new ReflectionProperty($orderEntity, 'id');
    $idProp->setValue($orderEntity, 1);

    return [
        'order present' => [$orderEntity, true],
        'order absent' => [null, false],
    ];
});

test('productHasOrders returns expected result', function (?Order $order, bool $expectedResult): void {
    $orderRepoMock = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepoMock->shouldReceive('findOneByProductId')->atLeast()->once()->andReturn($order);

    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepoMock);
    $emMock->shouldIgnoreMissing();

    $di = container();
    $di['em'] = $emMock;

    $svc = new Service();
    $svc->setDi($di);

    $product = orderServiceCreateProductEntity(1);

    $result = $svc->productHasOrders($product);

    expect($result)->toEqual($expectedResult);
})->with('productHasOrdersProvider');

test('saveStatusChange records history', function (): void {
    $persistedEntities = [];
    $nextOrderId = 1;
    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('persist')->atLeast()->once()->andReturnUsing(function ($entity) use (&$persistedEntities): void {
        $persistedEntities[] = $entity;
    });
    $emMock->shouldReceive('flush')->atLeast()->once()->andReturnUsing(function () use (&$persistedEntities, &$nextOrderId): void {
        foreach ($persistedEntities as $entity) {
            $refl = new ReflectionClass($entity);
            if ($refl->hasProperty('id')) {
                $prop = $refl->getProperty('id');
                if ($prop->getValue($entity) === null) {
                    $prop->setValue($entity, $nextOrderId++);
                }
            }
        }
        $persistedEntities = [];
    });
    $emMock->shouldReceive('remove')->andReturnNull();
    $orderRepoMock = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepoMock->shouldReceive('find')->andReturnUsing(function (?int $id) use (&$nextOrderId): ?object {
        if ($id === null) {
            return null;
        }
        $order = new Order();
        $prop = new ReflectionProperty($order, 'id');
        $prop->setValue($order, $id);

        return $order;
    });
    $orderRepoMock->shouldReceive('findOneByOrderIdAndName')->byDefault()->andReturn(null);
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepoMock);
    $emMock->shouldReceive('getRepository')->with(ClientFence\Mod\Order\Entity\OrderMeta::class)->andReturn(Mockery::mock(ClientFence\Mod\Order\Repository\OrderMetaRepository::class)->shouldIgnoreMissing());
    $emMock->shouldIgnoreMissing();

    $di = container();
    $di['em'] = $emMock;

    $svc = new Service();
    $svc->setDi($di);

    $order = createEntity(Order::class);

    $result = $svc->saveStatusChange($order);

    expect($result)->toBeNull();
});

test('getSoonExpiringActiveOrders executes query', function (): void {
    $connectionMock = Mockery::mock(Doctrine\DBAL\Connection::class);
    $connectionMock->shouldReceive('fetchAllAssociative')->atLeast()->once()->andReturn([[], []]);
    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('getConnection')->atLeast()->once()->andReturn($connectionMock);

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getSoonExpiringActiveOrdersQuery')->atLeast()->once()->andReturn(['query', []]);

    $di = container();
    $di['em'] = $emMock;
    $serviceMock->setDi($di);

    $serviceMock->getSoonExpiringActiveOrders();
});

test('getSoonExpiringActiveOrdersQuery excludes orders with scheduled cancellations or a waiting cancellation request', function (): void {
    $randId = 1;

    $systemService = Mockery::mock(ClientFence\Mod\System\Service::class);
    $systemService->shouldReceive('getParamValue')->atLeast()->once()->andReturn($randId);

    $di = container();
    $di['mod_service'] = $di->protect(fn (string $name): Mockery\MockInterface => match (strtolower($name)) {
        'system' => $systemService,
        default => Mockery::mock()->shouldIgnoreMissing(),
    });

    $svc = new Service();
    $svc->setDi($di);

    $data = ['client_id' => $randId];
    $result = $svc->getSoonExpiringActiveOrdersQuery($data);

    $expectedQuery = "SELECT co.*
                FROM client_order co
                LEFT JOIN invoice i ON i.id = co.unpaid_invoice_id AND i.status = :unpaid_invoice_status
                WHERE co.status = :status
                AND co.invoice_option = :invoice_option
                AND co.period IS NOT NULL
                AND co.expires_at IS NOT NULL
                AND i.id IS NULL
                AND NOT EXISTS (
                    SELECT 1
                    FROM client_order_meta cancellation_meta
                    WHERE cancellation_meta.client_order_id = co.id
                    AND cancellation_meta.name = :cancellation_meta_name
                    AND cancellation_meta.value = :cancellation_meta_value
                )
                /* ClientFence core patch: as WHMCS, no renewal invoice while a cancellation request waits (Order\\CancelRequests). */
                AND NOT EXISTS (
                    SELECT 1
                    FROM client_order_cancel_request cancel_request
                    WHERE cancel_request.client_order_id = co.id
                    AND cancel_request.status = :cancel_request_status
                )
                /* Pair non-executed renewal items with paid invoices to skip renewals already queued for activation. */
                AND NOT EXISTS (
                    SELECT 1
                    FROM invoice_item pending_item
                    INNER JOIN invoice pending_invoice ON pending_invoice.id = pending_item.invoice_id
                    WHERE pending_item.rel_id = co.id
                    AND pending_item.type = :pending_item_type
                    AND pending_item.task = :pending_item_task
                    AND pending_item.status != :pending_item_status
                    AND pending_invoice.status = :pending_invoice_status
                ) AND co.client_id = :client_id HAVING DATEDIFF(co.expires_at, NOW()) <= CASE
                WHEN co.period LIKE '%W' AND (co.period + 0) * 7 / 2 < :days_until_expiration THEN FLOOR((co.period + 0) * 7 / 2)
                WHEN co.period LIKE '%D' AND (co.period + 0) / 2 < :days_short THEN FLOOR((co.period + 0) / 2)
                ELSE :days_long END
            ORDER BY co.client_id DESC";

    $expectedBindings = [
        'client_id' => $randId,
        'unpaid_invoice_status' => Model_Invoice::STATUS_UNPAID,
        'cancellation_meta_name' => Service::META_CANCEL_AT_PERIOD_END,
        'cancellation_meta_value' => '1',
        'cancel_request_status' => ClientFence\Mod\Order\CancelRequests::STATUS_PENDING,
        'pending_item_type' => Model_InvoiceItem::TYPE_ORDER,
        'pending_item_task' => Model_InvoiceItem::TASK_RENEW,
        'pending_item_status' => Model_InvoiceItem::STATUS_EXECUTED,
        'pending_invoice_status' => Model_Invoice::STATUS_PAID,
        'status' => Order::STATUS_ACTIVE,
        'invoice_option' => 'issue-invoice',
        'days_until_expiration' => $randId,
        'days_short' => $randId,
        'days_long' => $randId,
    ];

    expect($result[0])->toBeString();
    expect($result[1])->toBeArray();
    expect($result[0])->toEqual($expectedQuery);
    expect($result[1])->toEqual($expectedBindings);
});

test('getRelatedOrderIdByType returns id', function (): void {
    $id = 1;
    $model = createEntity(Order::class, ['id' => $id]);

    $orderEntity = new Order();
    $idProp = new ReflectionProperty($orderEntity, 'id');
    $idProp->setValue($orderEntity, $id);

    $orderRepoMock = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepoMock->shouldReceive('findOneBy')->atLeast()->once()->andReturn($orderEntity);

    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepoMock);
    $emMock->shouldIgnoreMissing();

    $di = container();
    $di['em'] = $emMock;

    $svc = new Service();
    $svc->setDi($di);

    $result = $svc->getRelatedOrderIdByType($model, 'domain');

    expect($result)->toBeInt();
    expect($result)->toEqual($id);
});

test('getRelatedOrderIdByType returns null when not found', function (): void {
    $id = 1;
    $model = createEntity(Order::class, ['id' => $id]);

    $orderRepoMock = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepoMock->shouldReceive('findOneBy')->atLeast()->once()->andReturn(null);

    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepoMock);
    $emMock->shouldIgnoreMissing();

    $di = container();
    $di['em'] = $emMock;

    $svc = new Service();
    $svc->setDi($di);

    $result = $svc->getRelatedOrderIdByType($model, 'domain');

    expect($result)->toBeNull();
});

test('getLogger returns logger with event items', function (): void {
    $model = createEntity(Order::class, [
        'id' => 5,
        'status' => 'active',
    ]);

    $capturedItems = [];
    $logger = new class($capturedItems) extends \ClientFence\Log {
        public function __construct(public array &$capturedItems)
        {
        }

        public function addWriter($writer): static
        {
            return $this;
        }

        public function setEventItem(string $name, mixed $value): static
        {
            $this->capturedItems[] = [$name, $value];

            return $this;
        }
    };

    $di = container();
    $di['logger'] = $logger;

    $svc = new Service();
    $svc->setDi($di);

    $result = $svc->getLogger($model);

    expect($result)->toBeInstanceOf(\ClientFence\Log::class);
    expect($capturedItems)->toHaveCount(2);
    expect($capturedItems[0])->toEqual(['client_order_id', 5]);
    expect($capturedItems[1])->toEqual(['status', 'active']);
});

test('toApiArray returns expected keys', function (): void {
    $model = createEntity(Order::class, [
        'id' => 1,
        'config' => '{}',
        'price' => 10,
        'quantity' => 1,
        'client_id' => 1,
    ]);

    $clientService = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $clientService->shouldReceive('toApiArray')->atLeast()->once()->andReturn([]);

    $supportService = Mockery::mock(ClientFence\Mod\Support\Service::class);
    $supportTicketRepo = Mockery::mock(ClientFence\Mod\Support\Repository\SupportTicketRepository::class);
    $supportTicketRepo->shouldReceive('countActiveTicketsForOrder')->atLeast()->once()->andReturn(1);
    $supportService->shouldReceive('getSupportTicketRepository')->atLeast()->once()->andReturn($supportTicketRepo);

    $dbMock = Mockery::mock(\ClientFence\Database::class);
    $dbMock->shouldNotReceive('toArray');

    $clientEntity = new ClientFence\Mod\Client\Entity\Client();

    $clientRepoMock = Mockery::mock(ClientFence\Mod\Client\Repository\ClientRepository::class);
    $clientRepoMock->shouldReceive('find')->with(1)->atLeast()->once()->andReturn($clientEntity);

    $orderMetaRepoMock = Mockery::mock(ClientFence\Mod\Order\Repository\OrderMetaRepository::class);
    $orderMetaRepoMock->shouldReceive('getPairsForOrder')->atLeast()->once()->andReturn([]);
    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('getRepository')->with(ClientFence\Mod\Order\Entity\OrderMeta::class)->atLeast()->once()->andReturn($orderMetaRepoMock);
    $emMock->shouldReceive('getRepository')->with(ClientFence\Mod\Client\Entity\Client::class)->atLeast()->once()->andReturn($clientRepoMock);
    $emMock->shouldIgnoreMissing();

    $productService = Mockery::mock(ClientFence\Mod\Product\Service::class);
    $productService->shouldReceive('getProductPluginById')->once()->with((int) $model->product_id)->andReturn(null);

    $di = container();
    $di['mod_service'] = $di->protect(function ($serviceName) use ($clientService, $supportService, $productService) {
        if ($serviceName == 'client') {
            return $clientService;
        }
        if ($serviceName == 'support') {
            return $supportService;
        }
        if ($serviceName == 'product') {
            return $productService;
        }
    });
    $di['db'] = $dbMock;
    $di['em'] = $emMock;

    $svc = new Service();
    $svc->setDi($di);

    $result = $svc->toApiArray($model, true, createEntity(ClientFence\Mod\Staff\Entity\Admin::class));

    expect($result)->toHaveKey('config');
    expect($result)->toHaveKey('total');
    expect($result)->toHaveKey('title');
    expect($result)->toHaveKey('meta');
    expect($result)->toHaveKey('active_tickets');
    expect($result)->toHaveKey('plugin');
    expect($result)->toHaveKey('client');
});

dataset('searchQueryData', fn (): array => [
    'no data' => [[], 'SELECT co.* from client_order co', []],
    'client_id' => [
        ['client_id' => 1],
        'co.client_id = :client_id',
        ['client_id' => '1'],
    ],
    'invoice_option' => [
        ['invoice_option' => 'issue-invoice'],
        'co.invoice_option = :invoice_option',
        ['invoice_option' => 'issue-invoice'],
    ],
    'id' => [
        ['id' => 1],
        'co.id = :id',
        ['id' => '1'],
    ],
    'status' => [
        ['status' => 'pending_setup'],
        'co.status = :status',
        ['status' => 'pending_setup'],
    ],
    'product_id' => [
        ['product_id' => 1],
        'co.product_id = :product_id',
        ['product_id' => '1'],
    ],
    'type' => [
        ['type' => 'custom'],
        'co.service_type = :service_type',
        ['service_type' => 'custom'],
    ],
    'title' => [
        ['title' => 'titleField'],
        'co.title LIKE :title',
        ['title' => '%titleField%'],
    ],
    'period' => [
        ['period' => '1Y'],
        'co.period = :period',
        ['period' => '1Y'],
    ],
    'hide_addons' => [
        ['hide_addons' => true],
        'co.group_master = 1',
        [],
    ],
    'created_at' => [
        ['created_at' => '2012-12-11'],
        "DATE_FORMAT(co.created_at, '%Y-%m-%d') = :created_at",
        ['created_at' => '2012-12-11'],
    ],
    'date_from' => [
        ['date_from' => '2012-12-11'],
        'UNIX_TIMESTAMP(co.created_at) >= :date_from',
        ['date_from' => strtotime('2012-12-11')],
    ],
    'date_to' => [
        ['date_to' => '2012-12-11'],
        'UNIX_TIMESTAMP(co.created_at) <= :date_to',
        ['date_to' => strtotime('2012-12-11')],
    ],
    'search numeric' => [
        ['search' => 120],
        'co.id = :search',
        ['search' => 120],
    ],
    'search string' => [
        ['search' => 'John'],
        '(c.first_name LIKE :first_name OR c.last_name LIKE :last_name OR co.title LIKE :title)',
        [
            'first_name' => '%John%',
            'last_name' => '%John%',
            'title' => '%John%',
        ],
    ],
    'ids' => [
        ['ids' => [1, 2, 3]],
        'co.id IN (:ids)',
        ['ids' => '1, 2, 3'],
    ],
    'promo_id' => [
        ['promo_id' => 9],
        'co.promo_id = :promo_id',
        ['promo_id' => 9],
    ],
    'meta' => [
        ['meta' => ['param' => 'value']],
        'EXISTS (SELECT 1 FROM client_order_meta meta1 WHERE meta1.client_order_id = co.id AND meta1.name = :meta_name1 AND meta1.value LIKE :meta_value1)',
        [
            'meta_name1' => 'param',
            'meta_value1' => 'value%',
        ],
    ],
]);

test('getSearchQuery returns expected query and bindings', function (array $data, string $expectedStr, array $expectedParams): void {
    $di = container();

    $svc = new Service();
    $svc->setDi($di);

    $result = $svc->getSearchQuery($data);

    expect($result[0])->toBeString();
    expect($result[1])->toBeArray();
    expect($result[0])->toContain($expectedStr);
    expect($result[1])->toEqual($expectedParams);
})->with('searchQueryData');

test('getSearchQuery keeps client scope when action required filter is used', function (): void {
    $di = container();

    $svc = new Service();
    $svc->setDi($di);

    [$query, $bindings] = $svc->getSearchQuery([
        'client_id' => 42,
        'show_action_required' => true,
    ]);

    expect($query)->toContain('co.client_id = :client_id');
    expect($query)->toContain("(co.status = 'pending_setup' OR co.status = 'failed_setup' OR co.status ='failed_renew')");
    expect($bindings['client_id'])->toBe(42);
});

test('createOrder throws when no order currency is set', function (): void {
    $modelClient = createEntity(ClientFence\Mod\Client\Entity\Client::class);

    $modelProduct = orderServiceCreateProductEntity();

    $currencyRepositoryMock = Mockery::mock(ClientFence\Mod\Currency\Repository\CurrencyRepository::class);
    $currencyRepositoryMock->shouldReceive('findDefault')->atLeast()->once()->andReturn(null);

    $currencyServiceMock = Mockery::mock(ClientFence\Mod\Currency\Service::class);
    $currencyServiceMock->shouldReceive('getCurrencyRepository')->atLeast()->once()->andReturn($currencyRepositoryMock);

    $di = container();
    $di['mod_service'] = $di->protect(function ($serviceName) use ($currencyServiceMock) {
        if ($serviceName == 'currency') {
            return $currencyServiceMock;
        }
    });

    $svc = new Service();
    $svc->setDi($di);

    expect(fn () => $svc->createOrder($modelClient, $modelProduct, []))
        ->toThrow(ClientFence\Exception::class, 'Currency could not be determined for order');
});

test('createOrder throws when out of stock', function (): void {
    $modelClient = createEntity(ClientFence\Mod\Client\Entity\Client::class, ['currency' => 'USD']);

    $modelProduct = orderServiceCreateProductEntity(1);

    $currencyModel = Mockery::mock(ClientFence\Mod\Currency\Entity\Currency::class)->shouldIgnoreMissing();

    $currencyRepositoryMock = Mockery::mock(ClientFence\Mod\Currency\Repository\CurrencyRepository::class);
    $currencyRepositoryMock->shouldReceive('findOneByCode')->atLeast()->once()->andReturn($currencyModel);

    $currencyServiceMock = Mockery::mock(ClientFence\Mod\Currency\Service::class);
    $currencyServiceMock->shouldReceive('getCurrencyRepository')->atLeast()->once()->andReturn($currencyRepositoryMock);

    $cartServiceMock = Mockery::mock(ClientFence\Mod\Cart\Service::class);
    $cartServiceMock->shouldReceive('isStockAvailable')
        ->atLeast()->once()
        ->with($modelProduct, Mockery::any())
        ->andReturn(false);

    $eventMock = Mockery::mock(\ClientFence\EventManager::class);
    $eventMock->shouldReceive('fire')->atLeast()->once();

    $di = container();
    $di['mod_service'] = $di->protect(function ($serviceName) use ($currencyServiceMock, $cartServiceMock) {
        if ($serviceName == 'currency') {
            return $currencyServiceMock;
        }
        if ($serviceName == 'cart') {
            return $cartServiceMock;
        }
    });
    $di['events_manager'] = $eventMock;

    $svc = new Service();
    $svc->setDi($di);

    expect(fn () => $svc->createOrder($modelClient, $modelProduct, []))
        ->toThrow(ClientFence\Exception::class, 'Product 1 is out of stock.');
});

test('createOrder refuses a period the product does not sell unless staff set a price', function (): void {
    $modelClient = createEntity(ClientFence\Mod\Client\Entity\Client::class, ['currency' => 'USD']);
    $modelProduct = orderServiceCreateProductEntity(1);

    $currencyRepositoryMock = Mockery::mock(ClientFence\Mod\Currency\Repository\CurrencyRepository::class);
    $currencyRepositoryMock->shouldReceive('findOneByCode')->andReturn(Mockery::mock(ClientFence\Mod\Currency\Entity\Currency::class)->shouldIgnoreMissing());
    $currencyServiceMock = Mockery::mock(ClientFence\Mod\Currency\Service::class);
    $currencyServiceMock->shouldReceive('getCurrencyRepository')->andReturn($currencyRepositoryMock);

    $cartServiceMock = Mockery::mock(ClientFence\Mod\Cart\Service::class);
    $cartServiceMock->shouldReceive('isStockAvailable')->andReturn(true);
    $cartServiceMock->shouldReceive('isRecurrentPricing')->andReturn(true);
    $cartServiceMock->shouldReceive('isPeriodEnabledForProduct')->with($modelProduct, '1W')->andReturn(false);

    $di = container();
    $di['mod_service'] = $di->protect(fn ($serviceName) => $serviceName == 'currency' ? $currencyServiceMock : $cartServiceMock);
    $di['events_manager'] = Mockery::mock(\ClientFence\EventManager::class)->shouldIgnoreMissing();

    $svc = new Service();
    $svc->setDi($di);

    expect(fn () => $svc->createOrder($modelClient, $modelProduct, ['period' => '1W']))
        ->toThrow(ClientFence\InformationException::class, 'Choose a billing period this product sells')
        ->and(fn () => $svc->createOrder($modelClient, $modelProduct, []))
        ->toThrow(ClientFence\InformationException::class, 'Choose a billing period this product sells');
});

test('createOrder throws when group id missing for addon', function (): void {
    $modelClient = createEntity(ClientFence\Mod\Client\Entity\Client::class, ['currency' => 'USD']);

    $modelProduct = orderServiceCreateProductEntity(1);
    $modelProduct->setIsAddon(true);

    $currencyModel = Mockery::mock(ClientFence\Mod\Currency\Entity\Currency::class)->shouldIgnoreMissing();

    $currencyRepositoryMock = Mockery::mock(ClientFence\Mod\Currency\Repository\CurrencyRepository::class);
    $currencyRepositoryMock->shouldReceive('findOneByCode')->atLeast()->once()->andReturn($currencyModel);

    $currencyServiceMock = Mockery::mock(ClientFence\Mod\Currency\Service::class);
    $currencyServiceMock->shouldReceive('getCurrencyRepository')->atLeast()->once()->andReturn($currencyRepositoryMock);

    $cartServiceMock = Mockery::mock(ClientFence\Mod\Cart\Service::class);
    $cartServiceMock->shouldReceive('isStockAvailable')
        ->atLeast()->once()
        ->with($modelProduct, Mockery::any())
        ->andReturn(true);

    $eventMock = Mockery::mock(\ClientFence\EventManager::class);
    $eventMock->shouldReceive('fire')->atLeast()->once();

    $di = container();
    $di['mod_service'] = $di->protect(function ($serviceName) use ($currencyServiceMock, $cartServiceMock) {
        if ($serviceName == 'currency') {
            return $currencyServiceMock;
        }
        if ($serviceName == 'cart') {
            return $cartServiceMock;
        }
    });
    $di['events_manager'] = $eventMock;

    $svc = new Service();
    $svc->setDi($di);

    expect(fn () => $svc->createOrder($modelClient, $modelProduct, []))
        ->toThrow(ClientFence\Exception::class, 'Group ID parameter is missing for addon product order');
});

test('createOrder throws when parent order not found', function (): void {
    $modelClient = createEntity(ClientFence\Mod\Client\Entity\Client::class, ['currency' => 'USD']);

    $modelProduct = orderServiceCreateProductEntity(1);

    $currencyModel = Mockery::mock(ClientFence\Mod\Currency\Entity\Currency::class)->shouldIgnoreMissing();

    $currencyRepositoryMock = Mockery::mock(ClientFence\Mod\Currency\Repository\CurrencyRepository::class);
    $currencyRepositoryMock->shouldReceive('findOneByCode')->atLeast()->once()->andReturn($currencyModel);

    $currencyServiceMock = Mockery::mock(ClientFence\Mod\Currency\Service::class);
    $currencyServiceMock->shouldReceive('getCurrencyRepository')->atLeast()->once()->andReturn($currencyRepositoryMock);

    $cartServiceMock = Mockery::mock(ClientFence\Mod\Cart\Service::class);
    $cartServiceMock->shouldReceive('isStockAvailable')
        ->atLeast()->once()
        ->with($modelProduct, Mockery::any())
        ->andReturn(true);

    $eventMock = Mockery::mock(\ClientFence\EventManager::class);
    $eventMock->shouldReceive('fire')->atLeast()->once();

    $di = container();
    $di['mod_service'] = $di->protect(function ($serviceName) use ($currencyServiceMock, $cartServiceMock) {
        if ($serviceName == 'currency') {
            return $currencyServiceMock;
        }
        if ($serviceName == 'cart') {
            return $cartServiceMock;
        }
    });
    $di['events_manager'] = $eventMock;

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getMasterOrderForClient')
        ->atLeast()->once()
        ->andReturn(null);

    $serviceMock->setDi($di);

    expect(fn () => $serviceMock->createOrder($modelClient, $modelProduct, ['group_id' => 1]))
        ->toThrow(ClientFence\Exception::class, 'Parent order 1 was not found');
});

test('createOrder creates order', function (?string $clientCurrency, array $extra): void {
    $modelClient = createEntity(ClientFence\Mod\Client\Entity\Client::class, ['currency' => $clientCurrency]);

    $modelProduct = orderServiceCreateProductEntity(1, 'custom');

    $currencyModel = Mockery::mock(ClientFence\Mod\Currency\Entity\Currency::class)->shouldIgnoreMissing();
    $currencyModel->shouldReceive('getCode')->andReturn('USD');

    $currencyRepositoryMock = Mockery::mock(ClientFence\Mod\Currency\Repository\CurrencyRepository::class);
    $currencyRepositoryMock->shouldReceive('findOneByCode')->atLeast()->once()->andReturn($currencyModel);

    $currencyServiceMock = Mockery::mock(ClientFence\Mod\Currency\Service::class);
    $currencyServiceMock->shouldReceive('getCurrencyRepository')->atLeast()->once()->andReturn($currencyRepositoryMock);

    $cartServiceMock = Mockery::mock(ClientFence\Mod\Cart\Service::class);
    $cartServiceMock->shouldReceive('isStockAvailable')
        ->atLeast()->once()
        ->with($modelProduct, Mockery::any())
        ->andReturn(true);

    $eventMock = Mockery::mock(\ClientFence\EventManager::class);
    $eventMock->shouldReceive('fire')->atLeast()->once();

    $productServiceMock = Mockery::mock(ClientFence\Mod\Servicecustom\Service::class);
    $pricingServiceMock = Mockery::mock(ClientFence\Mod\Product\Service::class);
    $pricingServiceMock->shouldReceive('getProductOrderLineConfig')->never();

    $persistedEntities = [];
    $nextOrderId = 1;
    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('persist')->atLeast()->once()->andReturnUsing(function ($entity) use (&$persistedEntities): void {
        $persistedEntities[] = $entity;
    });
    $emMock->shouldReceive('flush')->atLeast()->once()->andReturnUsing(function () use (&$persistedEntities, &$nextOrderId): void {
        foreach ($persistedEntities as $entity) {
            $refl = new ReflectionClass($entity);
            if ($refl->hasProperty('id')) {
                $prop = $refl->getProperty('id');
                if ($prop->getValue($entity) === null) {
                    $prop->setValue($entity, $nextOrderId++);
                }
            }
        }
        $persistedEntities = [];
    });
    $emMock->shouldReceive('wrapInTransaction')->once()->andReturnUsing(fn (callable $callback) => $callback());
    $emMock->shouldReceive('remove')->andReturnNull();
    $orderRepoMock = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepoMock->shouldReceive('find')->andReturnUsing(function (?int $id) use (&$nextOrderId): ?object {
        if ($id === null) {
            return null;
        }
        $order = new Order();
        $prop = new ReflectionProperty($order, 'id');
        $prop->setValue($order, $id);

        return $order;
    });
    $orderRepoMock->shouldReceive('findOneByOrderIdAndName')->byDefault()->andReturn(null);
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepoMock);
    $emMock->shouldReceive('getRepository')->with(ClientFence\Mod\Order\Entity\OrderMeta::class)->andReturn(Mockery::mock(ClientFence\Mod\Order\Repository\OrderMetaRepository::class)->shouldIgnoreMissing());
    $emMock->shouldIgnoreMissing();

    $newId = 1;

    $periodMock = Mockery::mock(\ClientFence\Period::class);
    $periodMock->shouldReceive('getCode')->atLeast()->once()->andReturn('1Y');

    $di = container();
    $di['mod_service'] = $di->protect(function ($serviceName) use ($currencyServiceMock, $cartServiceMock, $productServiceMock, $pricingServiceMock) {
        if ($serviceName == 'currency') {
            return $currencyServiceMock;
        }
        if ($serviceName == 'cart') {
            return $cartServiceMock;
        }
        if ($serviceName == 'Product') {
            return $pricingServiceMock;
        }
        if ($serviceName == 'servicecustom') {
            return $productServiceMock;
        }
    });
    $di['events_manager'] = $eventMock;
    $di['em'] = $emMock;
    $di['period'] = $di->protect(fn (): Mockery\MockInterface => $periodMock);
    $di['logger'] = new \ClientFence\Log();

    $svc = new Service();
    $svc->setDi($di);

    $result = $svc->createOrder($modelClient, $modelProduct, ['period' => '1Y', 'price' => '10', 'notes' => 'test'] + $extra);

    expect($result)->toEqual($newId);
    // A client without a currency takes the order's (adding funds needs one).
    expect($modelClient->getCurrency())->toBe('USD');
})->with([
    'client currency' => ['USD', []],
    'no client currency' => [null, ['currency' => 'USD']],
]);

test('createOrder refuses a currency other than the client\'s', function (): void {
    $modelClient = createEntity(ClientFence\Mod\Client\Entity\Client::class, ['currency' => 'USD']);
    $currencyServiceMock = Mockery::mock(ClientFence\Mod\Currency\Service::class);
    $currencyServiceMock->shouldReceive('getCurrencyRepository')->andReturn(Mockery::mock(ClientFence\Mod\Currency\Repository\CurrencyRepository::class));
    $di = container();
    $di['mod_service'] = $di->protect(fn ($serviceName) => $serviceName == 'currency' ? $currencyServiceMock : null);
    $svc = new Service();
    $svc->setDi($di);

    expect(fn () => $svc->createOrder($modelClient, orderServiceCreateProductEntity(), ['currency' => 'EGP']))
        ->toThrow(ClientFence\InformationException::class, "The order must be in the client's currency (USD).");
});

test('createOrder sets form id from product', function (): void {
    $modelClient = createEntity(ClientFence\Mod\Client\Entity\Client::class, ['currency' => 'USD']);

    $modelProduct = orderServiceCreateProductEntity(1, 'custom');
    $modelProduct->setFormId(42);

    $currencyModel = Mockery::mock(ClientFence\Mod\Currency\Entity\Currency::class)->shouldIgnoreMissing();

    $currencyRepositoryMock = Mockery::mock(ClientFence\Mod\Currency\Repository\CurrencyRepository::class);
    $currencyRepositoryMock->shouldReceive('findOneByCode')->atLeast()->once()->andReturn($currencyModel);

    $currencyServiceMock = Mockery::mock(ClientFence\Mod\Currency\Service::class);
    $currencyServiceMock->shouldReceive('getCurrencyRepository')->atLeast()->once()->andReturn($currencyRepositoryMock);

    $cartServiceMock = Mockery::mock(ClientFence\Mod\Cart\Service::class);
    $cartServiceMock->shouldReceive('isStockAvailable')
        ->atLeast()->once()
        ->with($modelProduct, Mockery::any())
        ->andReturn(true);

    $eventMock = Mockery::mock(\ClientFence\EventManager::class);
    $eventMock->shouldReceive('fire')->atLeast()->once();

    $productServiceMock = Mockery::mock(ClientFence\Mod\Servicecustom\Service::class);
    $pricingServiceMock = Mockery::mock(ClientFence\Mod\Product\Service::class);
    $pricingServiceMock->shouldReceive('getProductOrderLineConfig')->never();

    $persistedEntities = [];
    $nextOrderId = 1;
    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('persist')->atLeast()->once()->andReturnUsing(function ($entity) use (&$persistedEntities): void {
        $persistedEntities[] = $entity;
    });
    $emMock->shouldReceive('flush')->atLeast()->once()->andReturnUsing(function () use (&$persistedEntities, &$nextOrderId): void {
        foreach ($persistedEntities as $entity) {
            $refl = new ReflectionClass($entity);
            if ($refl->hasProperty('id')) {
                $prop = $refl->getProperty('id');
                if ($prop->getValue($entity) === null) {
                    $prop->setValue($entity, $nextOrderId++);
                }
            }
        }
        $persistedEntities = [];
    });
    $emMock->shouldReceive('wrapInTransaction')->once()->andReturnUsing(fn (callable $callback) => $callback());
    $emMock->shouldReceive('remove')->andReturnNull();
    $orderRepoMock = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepoMock->shouldReceive('find')->andReturnUsing(function (?int $id) use (&$nextOrderId): ?object {
        if ($id === null) {
            return null;
        }
        $order = new Order();
        $prop = new ReflectionProperty($order, 'id');
        $prop->setValue($order, $id);

        return $order;
    });
    $orderRepoMock->shouldReceive('findOneByOrderIdAndName')->byDefault()->andReturn(null);
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepoMock);
    $emMock->shouldReceive('getRepository')->with(ClientFence\Mod\Order\Entity\OrderMeta::class)->andReturn(Mockery::mock(ClientFence\Mod\Order\Repository\OrderMetaRepository::class)->shouldIgnoreMissing());
    $emMock->shouldIgnoreMissing();

    $newId = 1;

    $periodMock = Mockery::mock(\ClientFence\Period::class);
    $periodMock->shouldReceive('getCode')->atLeast()->once()->andReturn('1Y');

    $di = container();
    $di['mod_service'] = $di->protect(function ($serviceName) use ($currencyServiceMock, $cartServiceMock, $productServiceMock, $pricingServiceMock) {
        if ($serviceName == 'currency') {
            return $currencyServiceMock;
        }
        if ($serviceName == 'cart') {
            return $cartServiceMock;
        }
        if ($serviceName == 'Product') {
            return $pricingServiceMock;
        }
        if ($serviceName == 'servicecustom') {
            return $productServiceMock;
        }
    });
    $di['events_manager'] = $eventMock;
    $di['em'] = $emMock;
    $di['period'] = $di->protect(fn (): Mockery\MockInterface => $periodMock);
    $di['logger'] = new \ClientFence\Log();

    $svc = new Service();
    $svc->setDi($di);

    $svc->createOrder($modelClient, $modelProduct, ['period' => '1Y', 'price' => '10']);

    expect(true)->toBeTrue();
});

test('createOrder returns success when invoice follow up fails', function (): void {
    $modelClient = createEntity(ClientFence\Mod\Client\Entity\Client::class, ['currency' => 'USD']);

    $modelProduct = orderServiceCreateProductEntity(1, 'custom');

    $currencyModel = Mockery::mock(ClientFence\Mod\Currency\Entity\Currency::class)->shouldIgnoreMissing();

    $currencyRepositoryMock = Mockery::mock(ClientFence\Mod\Currency\Repository\CurrencyRepository::class);
    $currencyRepositoryMock->shouldReceive('findOneByCode')->atLeast()->once()->andReturn($currencyModel);

    $currencyServiceMock = Mockery::mock(ClientFence\Mod\Currency\Service::class);
    $currencyServiceMock->shouldReceive('getCurrencyRepository')->atLeast()->once()->andReturn($currencyRepositoryMock);

    $cartServiceMock = Mockery::mock(ClientFence\Mod\Cart\Service::class);
    $cartServiceMock->shouldReceive('isStockAvailable')
        ->atLeast()->once()
        ->with($modelProduct, Mockery::any())
        ->andReturn(true);

    $eventMock = Mockery::mock(\ClientFence\EventManager::class);
    $eventMock->shouldReceive('fire')->atLeast()->once();

    $productServiceMock = Mockery::mock(ClientFence\Mod\Servicecustom\Service::class);
    $pricingServiceMock = Mockery::mock(ClientFence\Mod\Product\Service::class);
    $pricingServiceMock->shouldReceive('getProductOrderLineConfig')->never();

    $clientOrderModel = orderServiceCreateLegacyOrderModel(1);

    $invoiceModel = orderServiceCreateInvoiceModel(10);

    $invoiceServiceMock = Mockery::mock();
    $invoiceServiceMock->shouldReceive('generateForOrder')
        ->once()
        ->with(Mockery::any())
        ->andReturn($invoiceModel);
    $invoiceServiceMock->shouldReceive('approveInvoice')
        ->once()
        ->with($invoiceModel, ['id' => $invoiceModel->id, 'use_credits' => true])
        ->andReturn(true);
    $invoiceServiceMock->shouldReceive('markAsPaidByAdmin')
        ->once()
        ->with($invoiceModel, Mockery::on(fn (array $data): bool => $data['invoice_option'] === 'issue-invoice'
            && $data['mark_invoice_paid'] === true
            && $data['gateway_id'] === 7))
        ->andThrow(new Exception('Payment follow-up failed'));
    $invoiceServiceMock->shouldReceive('addNote')
        ->once()
        ->with(
            $invoiceModel,
            Mockery::on(fn (string $note): bool => str_contains($note, 'Order was created, but invoice follow-up failed: Payment follow-up failed'))
        )
        ->andReturn(true);

    $persistedEntities = [];
    $nextOrderId = 1;
    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('persist')->atLeast()->once()->andReturnUsing(function ($entity) use (&$persistedEntities): void {
        $persistedEntities[] = $entity;
    });
    $emMock->shouldReceive('flush')->atLeast()->once()->andReturnUsing(function () use (&$persistedEntities, &$nextOrderId): void {
        foreach ($persistedEntities as $entity) {
            $refl = new ReflectionClass($entity);
            if ($refl->hasProperty('id')) {
                $prop = $refl->getProperty('id');
                if ($prop->getValue($entity) === null) {
                    $prop->setValue($entity, $nextOrderId++);
                }
            }
        }
        $persistedEntities = [];
    });
    $emMock->shouldReceive('wrapInTransaction')->once()->andReturnUsing(fn (callable $callback) => $callback());
    $emMock->shouldReceive('remove')->andReturnNull();
    $orderRepoMock = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepoMock->shouldReceive('find')->andReturnUsing(function (?int $id) use (&$nextOrderId): ?object {
        if ($id === null) {
            return null;
        }
        $order = new Order();
        $prop = new ReflectionProperty($order, 'id');
        $prop->setValue($order, $id);

        return $order;
    });
    $orderRepoMock->shouldReceive('findOneByOrderIdAndName')->byDefault()->andReturn(null);
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepoMock);
    $emMock->shouldReceive('getRepository')->with(ClientFence\Mod\Order\Entity\OrderMeta::class)->andReturn(Mockery::mock(ClientFence\Mod\Order\Repository\OrderMetaRepository::class)->shouldIgnoreMissing());
    $emMock->shouldIgnoreMissing();
    $dbMock = Mockery::mock(\ClientFence\Database::class);
    $dbMock->shouldReceive('getExistingModelById')->once()->with('ClientOrder', 1)->andReturn($clientOrderModel);

    $newId = 1;

    $periodMock = Mockery::mock(\ClientFence\Period::class);
    $periodMock->shouldReceive('getCode')->atLeast()->once()->andReturn('1Y');

    $di = container();
    $di['mod_service'] = $di->protect(function ($serviceName) use ($cartServiceMock, $currencyServiceMock, $invoiceServiceMock, $productServiceMock, $pricingServiceMock) {
        if ($serviceName == 'currency') {
            return $currencyServiceMock;
        }
        if ($serviceName == 'cart') {
            return $cartServiceMock;
        }
        if ($serviceName == 'Product') {
            return $pricingServiceMock;
        }
        if ($serviceName == 'invoice') {
            return $invoiceServiceMock;
        }
        if ($serviceName == 'servicecustom') {
            return $productServiceMock;
        }
    });
    $di['events_manager'] = $eventMock;
    $di['db'] = $dbMock;
    $di['em'] = $emMock;
    $di['period'] = $di->protect(fn (): Mockery\MockInterface => $periodMock);
    $di['logger'] = new \ClientFence\Log();

    $svc = new Service();
    $svc->setDi($di);

    $result = $svc->createOrder($modelClient, $modelProduct, [
        'period' => '1Y',
        'price' => '10',
        'invoice_option' => 'issue-invoice',
        'mark_invoice_paid' => true,
        'gateway_id' => 7,
    ]);

    expect($result)->toBe($newId);
});

test('createOrder keeps the validated staff domain period and uses domain pricing', function (): void {
    $modelClient = createEntity(ClientFence\Mod\Client\Entity\Client::class, ['currency' => 'USD']);

    $modelProduct = orderServiceCreateProductEntity(10, ClientFence\Mod\Product\Service::DOMAIN);
    $modelProduct->setUnit('year');

    $currencyModel = Mockery::mock(ClientFence\Mod\Currency\Entity\Currency::class)->shouldIgnoreMissing();
    $currencyModel->shouldReceive('getCode')->andReturn('USD');

    $currencyRepositoryMock = Mockery::mock(ClientFence\Mod\Currency\Repository\CurrencyRepository::class);
    $currencyRepositoryMock->shouldReceive('findOneByCode')->once()->with('USD')->andReturn($currencyModel);
    $currencyRepositoryMock->shouldReceive('getRateByCode')->once()->with('USD')->andReturn(1.0);

    $currencyServiceMock = Mockery::mock(ClientFence\Mod\Currency\Service::class);
    $currencyServiceMock->shouldReceive('getCurrencyRepository')->atLeast()->once()->andReturn($currencyRepositoryMock);

    $cartServiceMock = Mockery::mock(ClientFence\Mod\Cart\Service::class);
    $cartServiceMock->shouldReceive('isRecurrentPricing')->andReturn(false);
    $cartServiceMock->shouldReceive('isStockAvailable')
        ->atLeast()->once()
        ->with($modelProduct, Mockery::any())
        ->andReturn(true);

    $eventMock = Mockery::mock(\ClientFence\EventManager::class);
    $eventMock->shouldReceive('fire')->atLeast()->once();

    $tld = new Model_Tld();
    $tld->loadBean(new Tests\Helpers\DummyBean());
    $tld->tld = '.com';
    $tld->active = true;
    $tld->min_years = 1;
    $domainServiceMock = Mockery::mock(ClientFence\Mod\Servicedomain\Service::class)->makePartial();
    $domainServiceMock->shouldReceive('tldFindOneByTld')->once()->with('.com')->andReturn($tld);
    $domainServiceMock->shouldReceive('isDomainAvailable')->once()->with($tld, 'example')->andReturnTrue();

    $pricingServiceMock = Mockery::mock(ClientFence\Mod\Product\Service::class);
    $pricingServiceMock->shouldReceive('inCurrency')->andReturnSelf();
    $pricingServiceMock->shouldReceive('getProductOrderLineConfig')
        ->once()
        ->with(
            $modelProduct,
            Mockery::on(static fn (array $config): bool => ($config['quantity'] ?? null) === 1)
        )
        ->andReturn([
            'price' => 22.0,
            'quantity' => 2,
            'setup_price' => 0.0,
        ]);

    $createdOrder = null;
    $persistedEntities = [];
    $nextOrderId = 1;
    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('persist')->atLeast()->once()->andReturnUsing(function ($entity) use (&$persistedEntities, &$createdOrder): void {
        $persistedEntities[] = $entity;
        if ($entity instanceof Order) {
            $createdOrder = $entity;
        }
    });
    $emMock->shouldReceive('flush')->atLeast()->once()->andReturnUsing(function () use (&$persistedEntities, &$nextOrderId): void {
        foreach ($persistedEntities as $entity) {
            $refl = new ReflectionClass($entity);
            if ($refl->hasProperty('id')) {
                $prop = $refl->getProperty('id');
                if ($prop->getValue($entity) === null) {
                    $prop->setValue($entity, $nextOrderId++);
                }
            }
        }
        $persistedEntities = [];
    });
    $emMock->shouldReceive('wrapInTransaction')->once()->andReturnUsing(fn (callable $callback) => $callback());
    $emMock->shouldReceive('remove')->andReturnNull();
    $orderRepoMock = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepoMock->shouldReceive('find')->andReturnUsing(function (?int $id) use (&$nextOrderId): ?object {
        if ($id === null) {
            return null;
        }
        $order = new Order();
        $prop = new ReflectionProperty($order, 'id');
        $prop->setValue($order, $id);

        return $order;
    });
    $orderRepoMock->shouldReceive('findOneByOrderIdAndName')->byDefault()->andReturn(null);
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepoMock);
    $emMock->shouldReceive('getRepository')->with(ClientFence\Mod\Order\Entity\OrderMeta::class)->andReturn(Mockery::mock(ClientFence\Mod\Order\Repository\OrderMetaRepository::class)->shouldIgnoreMissing());
    $emMock->shouldIgnoreMissing();

    $newId = 1;

    $di = container();
    $di['mod_service'] = $di->protect(function ($serviceName) use ($currencyServiceMock, $cartServiceMock, $domainServiceMock, $pricingServiceMock) {
        if ($serviceName == 'currency') {
            return $currencyServiceMock;
        }
        if ($serviceName == 'cart') {
            return $cartServiceMock;
        }
        if ($serviceName == 'Product') {
            return $pricingServiceMock;
        }
        if ($serviceName == 'servicedomain') {
            return $domainServiceMock;
        }
    });
    $di['events_manager'] = $eventMock;
    $di['em'] = $emMock;
    $di['logger'] = new \ClientFence\Log();
    $di['period'] = $di->protect(fn (string $period): ClientFence\Period => new ClientFence\Period($period));
    $domainServiceMock->setDi($di);

    $svc = new Service();
    $svc->setDi($di);

    $result = $svc->createOrder($modelClient, $modelProduct, [
        'config' => [
            'action' => 'register',
            'register_tld' => '.com',
            'register_sld' => 'example',
            'register_years' => 2,
        ],
    ]);

    expect($result)->toBe($newId)
        ->and($createdOrder->getPeriod())->toBe('2Y');
});

test('createFromOrder activates the order after successful provisioning', function (): void {
    $order = createEntity(Order::class, [
        'id' => 1,
        'period' => '1Y',
        'productId' => 7,
        'quantity' => 2,
        'serviceType' => 'hosting',
    ]);

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getOrderService')->atLeast()->once()->andReturn(new stdClass());
    $serviceMock->shouldReceive('_callOnService')
        ->once()
        ->with($order, Order::ACTION_ACTIVATE)
        ->andReturn(['username' => 'created']);
    $serviceMock->shouldReceive('saveStatusChange')->once()->with($order, 'Order activated');

    $periodMock = Mockery::mock(\ClientFence\Period::class);
    $periodMock->shouldReceive('getExpirationTime')->once()->andReturn(strtotime('2027-01-01 00:00:00'));

    $productServiceMock = Mockery::mock();
    $productServiceMock->shouldReceive('reduceStock')->once()->with(7, 2);

    $di = container();
    $di['period'] = $di->protect(fn (): Mockery\MockInterface => $periodMock);
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $productServiceMock);

    $serviceMock->setDi($di);

    $result = $serviceMock->createFromOrder($order);

    expect($result)->toBe(['username' => 'created'])
        ->and($order->getStatus())->toBe(Order::STATUS_ACTIVE);
});

test('createFromOrder takes the stock before provisioning', function (): void {
    $order = createEntity(Order::class, ['id' => 1, 'productId' => 7, 'quantity' => 1, 'serviceType' => 'hosting']);

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('_callOnService')->never();

    $productServiceMock = Mockery::mock();
    $productServiceMock->shouldReceive('reduceStock')->once()->with(7, 1)->andThrow(new ClientFence\InformationException('Product 7 is out of stock.'));

    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $productServiceMock);
    $serviceMock->setDi($di);

    expect(fn (): mixed => $serviceMock->createFromOrder($order))->toThrow(ClientFence\InformationException::class, 'out of stock');
});

test('createFromOrder gives the stock back when provisioning fails', function (): void {
    $order = createEntity(Order::class, ['id' => 1, 'productId' => 7, 'quantity' => 2, 'serviceType' => 'hosting']);

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getOrderService')->andThrow(new ClientFence\Exception('Server down'));

    $productServiceMock = Mockery::mock();
    $productServiceMock->shouldReceive('reduceStock')->once()->with(7, 2)->ordered();
    $productServiceMock->shouldReceive('returnStock')->once()->with(7, 2)->ordered();

    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $productServiceMock);
    $serviceMock->setDi($di);

    expect(fn (): mixed => $serviceMock->createFromOrder($order))->toThrow(ClientFence\Exception::class, 'Server down');
});

test('createFromOrder marks the order failed_setup when provisioning succeeds but activation bookkeeping fails', function (): void {
    // Regression test: the remote account is created successfully by
    // _callOnService(), but computing the new expiry date afterwards throws.
    // The order must be recorded as failed_setup instead of being left in
    // pending_setup - otherwise a retry would call _callOnService() again
    // against a service that already exists on the remote server.
    $order = createEntity(Order::class, [
        'id' => 1,
        'period' => '1Y',
        'serviceType' => 'hosting',
    ]);

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getOrderService')->atLeast()->once()->andReturn(new stdClass());
    $serviceMock->shouldReceive('_callOnService')
        ->once()
        ->with($order, Order::ACTION_ACTIVATE)
        ->andReturn(['username' => 'created-before-the-failure']);
    $serviceMock->shouldReceive('saveStatusChange')
        ->once()
        ->with($order, 'Simulated post-provisioning failure');

    $orderRepoMock = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepoMock->shouldReceive('find')->with(1)->andReturn($order);

    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class)->shouldIgnoreMissing();
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepoMock);
    $emMock->shouldReceive('persist')->once()->with($order);
    $emMock->shouldReceive('flush')->once();

    $di = container();
    $di['em'] = $emMock;
    $di['period'] = $di->protect(function (): never {
        throw new ClientFence\Exception('Simulated post-provisioning failure');
    });
    $serviceMock->setDi($di);

    expect(fn (): mixed => $serviceMock->createFromOrder($order))
        ->toThrow(ClientFence\Exception::class, 'Simulated post-provisioning failure');

    // Confirm persistOrder() actually stored the failure - not just that the
    // in-memory $order object was mutated - by reloading it through the
    // repository.
    $reloadedOrder = $di['em']->getRepository(Order::class)->find(1);
    expect($reloadedOrder->getStatus())->toBe(Order::STATUS_FAILED_SETUP);
});

test('createFromOrder marks the order failed_setup when activation bookkeeping raises a TypeError', function (): void {
    // Same regression as above, but for the wider \Throwable hierarchy: an
    // \Error/\TypeError after a successful provisioning call must also be
    // caught, otherwise the order is left in pending_setup with the remote
    // account already created and a retry would call the provisioning
    // action again against a service that already exists.
    $order = createEntity(Order::class, [
        'id' => 1,
        'period' => '1Y',
        'serviceType' => 'hosting',
    ]);

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getOrderService')->atLeast()->once()->andReturn(new stdClass());
    $serviceMock->shouldReceive('_callOnService')
        ->once()
        ->with($order, Order::ACTION_ACTIVATE)
        ->andReturn(['username' => 'created-before-the-failure']);
    $serviceMock->shouldReceive('saveStatusChange')
        ->once()
        ->with($order, 'Simulated TypeError after provisioning');

    $orderRepoMock = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepoMock->shouldReceive('find')->with(1)->andReturn($order);

    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class)->shouldIgnoreMissing();
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepoMock);
    $emMock->shouldReceive('persist')->once()->with($order);
    $emMock->shouldReceive('flush')->once();

    $di = container();
    $di['em'] = $emMock;
    $di['period'] = $di->protect(function (): never {
        throw new TypeError('Simulated TypeError after provisioning');
    });
    $serviceMock->setDi($di);

    expect(fn (): mixed => $serviceMock->createFromOrder($order))
        ->toThrow(TypeError::class, 'Simulated TypeError after provisioning');

    // Confirm persistOrder() actually stored the failure - not just that the
    // in-memory $order object was mutated - by reloading it through the
    // repository.
    $reloadedOrder = $di['em']->getRepository(Order::class)->find(1);
    expect($reloadedOrder->getStatus())->toBe(Order::STATUS_FAILED_SETUP);
});

test('getMasterOrderForClient returns master order', function (): void {
    $clientModel = createEntity(ClientFence\Mod\Client\Entity\Client::class);

    $orderEntity = new Order();
    $idProp = new ReflectionProperty($orderEntity, 'id');
    $idProp->setValue($orderEntity, 1);

    $orderRepoMock = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepoMock->shouldReceive('findOneBy')->atLeast()->once()->andReturn($orderEntity);

    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepoMock);
    $emMock->shouldIgnoreMissing();

    $di = container();
    $di['em'] = $emMock;

    $svc = new Service();
    $svc->setDi($di);

    $result = $svc->getMasterOrderForClient($clientModel, 1);

    expect($result)->toBeInstanceOf(Order::class);
});

test('activateOrder throws for non-pending order', function (): void {
    $clientOrderModel = createEntity(Order::class);
    $clientOrderModel->status = Order::STATUS_CANCELED;
    $clientOrderModel->id = 1;

    $orderEntity = new Order();
    $idProp = new ReflectionProperty($orderEntity, 'id');
    $idProp->setValue($orderEntity, 1);
    $statusProp = new ReflectionProperty($orderEntity, 'status');
    $statusProp->setValue($orderEntity, Order::STATUS_CANCELED);

    $orderRepoMock = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepoMock->shouldReceive('find')->with(1)->andReturn($orderEntity);

    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepoMock);
    $emMock->shouldIgnoreMissing();

    $di = container();
    $di['em'] = $emMock;

    $svc = new Service();
    $svc->setDi($di);

    expect(fn (): bool => $svc->activateOrder($clientOrderModel))
        ->toThrow(ClientFence\Exception::class, 'Only pending setup or failed orders can be activated');
});

test('activateOrder activates pending order', function (): void {
    $clientOrderModel = createEntity(Order::class);
    $clientOrderModel->status = Order::STATUS_PENDING_SETUP;
    $clientOrderModel->group_master = 1;
    $clientOrderModel->id = 1;

    $orderEntity = new Order();
    $idProp = new ReflectionProperty($orderEntity, 'id');
    $idProp->setValue($orderEntity, 1);
    $statusProp = new ReflectionProperty($orderEntity, 'status');
    $statusProp->setValue($orderEntity, Order::STATUS_PENDING_SETUP);

    $orderRepoMock = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepoMock->shouldReceive('find')->with(1)->andReturn($orderEntity);

    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepoMock);
    $emMock->shouldIgnoreMissing();

    $eventMock = Mockery::mock(\ClientFence\EventManager::class);
    $eventMock->shouldReceive('fire')->atLeast()->once();

    $di = container();
    $di['em'] = $emMock;
    $di['events_manager'] = $eventMock;
    $di['logger'] = new \ClientFence\Log();

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('createFromOrder')->atLeast()->once()->andReturn([]);
    $serviceMock->shouldReceive('activateOrderAddons')->atLeast()->once();

    $serviceMock->setDi($di);

    $result = $serviceMock->activateOrder($clientOrderModel);

    expect($result)->toBeTrue();
});

test('activateOrder is a no-op when order was already activated by a stale reference', function (): void {
    $staleOrderModel = createEntity(Order::class, [
        'status' => Order::STATUS_PENDING_SETUP,
        'id' => 1,
    ]);

    $activeOrderEntity = new Order();
    $idProp = new ReflectionProperty($activeOrderEntity, 'id');
    $idProp->setValue($activeOrderEntity, 1);
    $statusProp = new ReflectionProperty($activeOrderEntity, 'status');
    $statusProp->setValue($activeOrderEntity, Order::STATUS_ACTIVE);

    $orderRepoMock = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepoMock->shouldReceive('find')->with(1)->andReturn($activeOrderEntity);

    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepoMock);
    $emMock->shouldIgnoreMissing();

    $di = container();
    $di['em'] = $emMock;

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('createFromOrder')->never();
    $serviceMock->shouldReceive('activateOrderAddons')->never();

    $serviceMock->setDi($di);

    $result = $serviceMock->activateOrder($staleOrderModel);

    expect($result)->toBeTrue();
});

test('activateOrder reads the current status of a cached order before provisioning', function (): void {
    $cachedOrder = new Order();
    (new ReflectionProperty($cachedOrder, 'id'))->setValue($cachedOrder, 1);
    $status = new ReflectionProperty($cachedOrder, 'status');
    $status->setValue($cachedOrder, Order::STATUS_PENDING_SETUP);

    $orderRepoMock = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepoMock->shouldReceive('find')->with(1)->andReturn($cachedOrder);

    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepoMock);
    // The paid invoice activated the order in the database; the cached entity still says pending setup.
    $emMock->shouldReceive('refresh')->once()->with($cachedOrder)->andReturnUsing(fn (Order $o) => $status->setValue($o, Order::STATUS_ACTIVE));
    $emMock->shouldIgnoreMissing();

    $di = container();
    $di['em'] = $emMock;

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('createFromOrder')->never();
    $serviceMock->setDi($di);

    expect($serviceMock->activateOrder($cachedOrder))->toBeTrue();
});

test('activateOrder force re-activates an already active order', function (): void {
    $activeOrderModel = createEntity(Order::class, [
        'status' => Order::STATUS_ACTIVE,
        'group_master' => 1,
        'id' => 1,
    ]);

    $orderEntity = new Order();
    $idProp = new ReflectionProperty($orderEntity, 'id');
    $idProp->setValue($orderEntity, 1);
    $statusProp = new ReflectionProperty($orderEntity, 'status');
    $statusProp->setValue($orderEntity, Order::STATUS_ACTIVE);

    $orderRepoMock = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepoMock->shouldReceive('find')->with(1)->andReturn($orderEntity);

    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepoMock);
    $emMock->shouldIgnoreMissing();

    $eventMock = Mockery::mock(\ClientFence\EventManager::class);
    $eventMock->shouldReceive('fire')->atLeast()->once();

    $di = container();
    $di['em'] = $emMock;
    $di['events_manager'] = $eventMock;
    $di['logger'] = new \ClientFence\Log();

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('createFromOrder')->atLeast()->once()->andReturn([]);
    $serviceMock->shouldReceive('activateOrderAddons')->atLeast()->once();

    $serviceMock->setDi($di);

    $result = $serviceMock->activateOrder($activeOrderModel, ['force' => true]);

    expect($result)->toBeTrue();
});

test('activateOrderAddons respects the fresh status and product setup policy', function (string $status, string $setup, bool $activate): void {
    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $master = createEntity(Order::class, ['id' => 1, 'group_master' => true]);
    $addon = createEntity(Order::class, ['id' => 2, 'product_id' => 5, 'status' => Order::STATUS_PENDING_SETUP]);
    $serviceMock->shouldReceive('getOrderAddonsList')->once()->with($master)->andReturn([$addon]);
    $serviceMock->shouldReceive('createFromOrder')->times($activate ? 1 : 0)->with($addon)->andReturn([]);

    $product = orderServiceCreateProductEntity(5);
    $product->setSetup($setup);
    $productService = Mockery::mock(ClientFence\Mod\Product\Service::class);
    $productService->shouldReceive('findProductById')->times($status === Order::STATUS_ACTIVE ? 0 : 1)->with(5)->andReturn($product);
    $dbal = Mockery::mock(Doctrine\DBAL\Connection::class);
    $dbal->shouldReceive('fetchOne')->once()->with('SELECT status FROM client_order WHERE id = ?', [2])->andReturn($status);

    $eventMock = Mockery::mock(\ClientFence\EventManager::class);
    $eventMock->shouldReceive('fire')->times($activate ? 2 : 0);

    $di = container();
    $di['dbal'] = $dbal;
    $di['mod_service'] = $di->protect(fn (string $name) => $productService);
    $di['events_manager'] = $eventMock;
    $serviceMock->setDi($di);

    expect($serviceMock->activateOrderAddons($master))->toBeTrue();
})->with([
    'already active' => [Order::STATUS_ACTIVE, ClientFence\Mod\Product\Service::SETUP_AFTER_ORDER, false],
    'awaiting payment' => [Order::STATUS_PENDING_SETUP, ClientFence\Mod\Product\Service::SETUP_AFTER_PAYMENT, false],
    'after order' => [Order::STATUS_PENDING_SETUP, ClientFence\Mod\Product\Service::SETUP_AFTER_ORDER, true],
]);

test('getOrderAddonsList returns addons', function (): void {
    $modelClientOrder = createEntity(Order::class);

    $orderEntity = new Order();
    $idProp = new ReflectionProperty($orderEntity, 'id');
    $idProp->setValue($orderEntity, 1);

    $orderRepoMock = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepoMock->shouldReceive('findBy')->atLeast()->once()->andReturn([$orderEntity]);

    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepoMock);
    $emMock->shouldIgnoreMissing();

    $di = container();
    $di['em'] = $emMock;

    $svc = new Service();
    $svc->setDi($di);

    $result = $svc->getOrderAddonsList($modelClientOrder);

    expect($result)->toBeArray();
    expect($result[0])->toBeInstanceOf(Order::class);
});

test('stockSale reduces stock', function (): void {
    $productModel = orderServiceCreateProductEntity();

    $productService = Mockery::mock(ClientFence\Mod\Product\Service::class);
    $productService->shouldReceive('reduceStock')->once()->with($productModel, 2)->andReturn(true);

    $di = container();
    $di['mod_service'] = $di->protect(function ($serviceName) use ($productService) {
        if ($serviceName == 'product') {
            return $productService;
        }
    });

    $svc = new Service();
    $svc->setDi($di);

    $result = $svc->stockSale($productModel, 2);

    expect($result)->toBeTrue();
});

test('stockSale throws when quantity would go negative', function (): void {
    $productModel = orderServiceCreateProductEntity(1);

    $productService = Mockery::mock(ClientFence\Mod\Product\Service::class);
    $productService->shouldReceive('reduceStock')
        ->once()
        ->with($productModel, 2)
        ->andThrow(new ClientFence\InformationException('Product :id is out of stock.', [':id' => 1], 831));

    $di = container();
    $di['mod_service'] = $di->protect(function ($serviceName) use ($productService) {
        if ($serviceName == 'product') {
            return $productService;
        }
    });

    $svc = new Service();
    $svc->setDi($di);

    expect(fn (): bool => $svc->stockSale($productModel, 2))
        ->toThrow(ClientFence\InformationException::class, 'Product 1 is out of stock.');
});

test('updateOrder updates fields', function (): void {
    $clientOrderModel = createEntity(Order::class);

    $eventMock = Mockery::mock(\ClientFence\EventManager::class);
    $eventMock->shouldReceive('fire')->atLeast()->once();

    $di = container();
    $di['events_manager'] = $eventMock;
    // An expiry set where there was none is time no invoice paid for (QA r6 U-04-R1): the marker is written.
    $dbal = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $dbal->executeStatement('CREATE TABLE client_order_meta (id INTEGER PRIMARY KEY, client_order_id INTEGER, name TEXT, value TEXT, created_at TEXT, updated_at TEXT)');
    $di['em'] = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class)->shouldIgnoreMissing();
    $di['em']->shouldReceive('getConnection')->andReturn($dbal);
    $di['logger'] = new \ClientFence\Log();

    $data = [
        'period' => '1Y',
        'created_at' => '2012-12-01',
        'activated_at' => '2012-12-01',
        'expires_at' => '2013-12-01',
        'invoice_option' => 'issue-invoice',
        'title' => 'Testing',
        'price' => 10,
        'status' => 'active',
        'notes' => 'Empty note',
        'reason' => 'non',
        'meta' => [],
    ];

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('updatePeriod')->atLeast()->once()->with($clientOrderModel, $data['period']);
    $serviceMock->shouldReceive('updateOrderMeta')->atLeast()->once()->with($clientOrderModel, $data['meta']);

    $serviceMock->setDi($di);

    $result = $serviceMock->updateOrder($clientOrderModel, $data);

    // QA r10 C-1: the new price, cycle and expiry are a staff money edit too.
    expect($result)->toBeTrue()
        ->and($dbal->fetchFirstColumn('SELECT name FROM client_order_meta ORDER BY name'))->toBe([]);
});

test('renewOrder renews order', function (): void {
    $clientOrderModel = createEntity(Order::class);
    $clientOrderModel->group_master = 1;
    $clientOrderModel->status = Order::STATUS_PENDING_SETUP;

    $eventMock = Mockery::mock(\ClientFence\EventManager::class);
    $eventMock->shouldReceive('fire')->atLeast()->once();

    $di = container();
    $di['events_manager'] = $eventMock;
    $di['logger'] = new \ClientFence\Log();

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('renewFromOrder')->atLeast()->once()->with($clientOrderModel);
    $serviceMock->shouldReceive('getOrderAddonsList')->atLeast()->once()->andReturn([$clientOrderModel]);

    $serviceMock->setDi($di);

    $result = $serviceMock->renewOrder($clientOrderModel);

    expect($result)->toBeTrue();
});

test('renewFromOrder extends expiration', function (): void {
    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('_callOnService')->atLeast()->once();

    $clientOrderModel = createEntity(Order::class);
    $clientOrderModel->period = '1Y';
    $clientOrderModel->expires_at = '2026-01-01 00:00:00';

    $expectedExpiration = strtotime('2027-01-01 00:00:00');
    $periodMock = Mockery::mock(\ClientFence\Period::class);
    $periodMock->shouldReceive('getExpirationTime')
        ->atLeast()->once()
        ->with(strtotime('2026-01-01 00:00:00'))
        ->andReturn($expectedExpiration);

    $serviceMock->shouldReceive('saveStatusChange')
        ->atLeast()->once()
        ->with($clientOrderModel, 'Order renewed');

    $di = container();
    $di['mod_config'] = $di->protect(fn ($name): array => []);
    $di['period'] = $di->protect(fn (): Mockery\MockInterface => $periodMock);

    $serviceMock->setDi($di);
    $serviceMock->renewFromOrder($clientOrderModel);

    expect($clientOrderModel->expires_at)->toEqual(new DateTime('2027-01-01 00:00:00'));
    expect($clientOrderModel->status)->toEqual(Order::STATUS_ACTIVE);
});

test('renewFromOrder treats a missing Doctrine expiration as now', function (): void {
    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('_callOnService')->once();
    $serviceMock->shouldReceive('saveStatusChange')->once()->with(Mockery::type(Order::class), 'Order renewed');

    $order = createEntity(Order::class, ['period' => '1Y']);
    $periodMock = Mockery::mock(\ClientFence\Period::class);
    $periodMock->shouldReceive('getExpirationTime')
        ->once()
        ->with(Mockery::on(static fn (int $from): bool => abs(time() - $from) <= 1))
        ->andReturn(strtotime('2027-01-01 00:00:00'));

    $di = container();
    $di['mod_config'] = $di->protect(fn ($name): array => ['order_renewal_logic' => 'from_greater']);
    $di['period'] = $di->protect(fn (): Mockery\MockInterface => $periodMock);

    $serviceMock->setDi($di);
    $serviceMock->renewFromOrder($order);

    expect($order->getExpiresAt())->toEqual(new DateTime('2027-01-01 00:00:00'))
        ->and($order->getStatus())->toBe(Order::STATUS_ACTIVE);
});

test('renewFromOrder extends free first term on first paid renewal', function (): void {
    $clientOrderModel = createEntity(Order::class);
    $clientOrderModel->period = '1Y';
    $clientOrderModel->status = Order::STATUS_ACTIVE;
    $clientOrderModel->activated_at = '2025-01-01 00:00:00';
    $clientOrderModel->expires_at = '2026-01-01 00:00:00';

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('_callOnService')
        ->once()
        ->with(Mockery::on(fn ($order): bool => $order === $clientOrderModel), Order::ACTION_RENEW);

    $expectedExpiration = strtotime('2027-01-01 00:00:00');
    $periodMock = Mockery::mock(\ClientFence\Period::class);
    $periodMock->shouldReceive('getExpirationTime')
        ->once()
        ->with(strtotime('2026-01-01 00:00:00'))
        ->andReturn($expectedExpiration);

    $serviceMock->shouldReceive('saveStatusChange')
        ->once()
        ->with($clientOrderModel, 'Order renewed');

    $di = container();
    $di['mod_config'] = $di->protect(fn ($name): array => []);
    $di['period'] = $di->protect(fn (): Mockery\MockInterface => $periodMock);

    $serviceMock->setDi($di);
    $serviceMock->renewFromOrder($clientOrderModel);

    expect($clientOrderModel->expires_at)->toEqual(new DateTime('2027-01-01 00:00:00'));
    expect($clientOrderModel->status)->toEqual(Order::STATUS_ACTIVE);
});

test('suspendFromOrder throws for non-active order', function (): void {
    $clientOrderModel = createEntity(Order::class);
    $clientOrderModel->status = Order::STATUS_SUSPENDED;

    $eventMock = Mockery::mock(\ClientFence\EventManager::class);
    $eventMock->shouldReceive('fire')->atLeast()->once();

    $di = container();
    $di['events_manager'] = $eventMock;

    $svc = new Service();
    $svc->setDi($di);

    expect(fn (): bool => $svc->suspendFromOrder($clientOrderModel))
        ->toThrow(ClientFence\Exception::class, 'Only active orders can be suspended');
});

test('suspendFromOrder suspends active order', function (): void {
    $clientOrderModel = createEntity(Order::class);
    $clientOrderModel->status = Order::STATUS_ACTIVE;

    $eventMock = Mockery::mock(\ClientFence\EventManager::class);
    $eventMock->shouldReceive('fire')->atLeast()->once();

    $di = container();
    $di['events_manager'] = $eventMock;
    $di['logger'] = new \ClientFence\Log();

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('_callOnService')->atLeast()->once();
    $serviceMock->shouldReceive('saveStatusChange')->atLeast()->once();

    $serviceMock->setDi($di);

    $result = $serviceMock->suspendFromOrder($clientOrderModel);

    expect($result)->toBeTrue();
});

// ClientFence: a blank suspend reason (the auto suspend setting left empty) writes the plain note.
test('suspendFromOrder with a blank reason writes the plain note', function (): void {
    $clientOrderModel = createEntity(Order::class);
    $clientOrderModel->status = Order::STATUS_ACTIVE;

    $di = container();
    $di['events_manager'] = Mockery::mock(\ClientFence\EventManager::class)->shouldIgnoreMissing();
    $di['logger'] = new \ClientFence\Log();

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('_callOnService')->once();
    $serviceMock->shouldReceive('saveStatusChange')->once()->with(Mockery::any(), 'Order suspended');
    $serviceMock->setDi($di);

    expect($serviceMock->suspendFromOrder($clientOrderModel, ' '))->toBeTrue();
});

// ClientFence: the active addons are suspended with their service and only those come back with it.
test('suspendFromOrder suspends the active addons and unsuspendFromOrder brings back only those', function (): void {
    $master = orderServiceCreateLegacyOrderModel(10);
    $master->status = Order::STATUS_ACTIVE;
    $master->group_master = 1;
    $addon = orderServiceCreateLegacyOrderModel(11);
    $addon->status = Order::STATUS_ACTIVE;
    $own = orderServiceCreateLegacyOrderModel(12);
    $own->status = Order::STATUS_SUSPENDED;

    $dbal = Mockery::mock(Doctrine\DBAL\Connection::class)->shouldIgnoreMissing();
    $dbal->shouldReceive('fetchOne')->with(Mockery::pattern('/FROM client_order_meta/'), [11, Service::META_SUSPENDED_WITH])->andReturn('10');
    $dbal->shouldReceive('fetchOne')->with(Mockery::pattern('/FROM client_order_meta/'), [12, Service::META_SUSPENDED_WITH])->andReturn(false);
    $di = container();
    $di['dbal'] = $dbal;
    $di['db'] = Mockery::mock(\ClientFence\Database::class)->shouldIgnoreMissing();
    $di['events_manager'] = Mockery::mock(\ClientFence\EventManager::class)->shouldIgnoreMissing();
    $di['logger'] = new \ClientFence\Log();

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('_callOnService');
    $serviceMock->shouldReceive('saveStatusChange');
    $serviceMock->shouldReceive('getOrderAddonsList')->with($master)->andReturn([$addon, $own]);
    $serviceMock->shouldReceive('suspendFromOrder')->with($master, null, false)->passthru();
    $serviceMock->shouldReceive('suspendFromOrder')->once()->with($addon, null, false)->andReturnUsing(function () use ($addon): bool {
        $addon->status = Order::STATUS_SUSPENDED;

        return true;
    });
    $serviceMock->shouldNotReceive('suspendFromOrder')->with($own, Mockery::any(), Mockery::any());
    $serviceMock->shouldReceive('updateOrderMeta')->once()->with($addon, [Service::META_SUSPENDED_WITH => '10']);
    $serviceMock->shouldReceive('unsuspendFromOrder')->with($master)->passthru();
    $serviceMock->shouldReceive('unsuspendFromOrder')->once()->with($addon)->andReturn(true);
    $serviceMock->shouldNotReceive('unsuspendFromOrder')->with($own);
    $serviceMock->setDi($di);

    expect($serviceMock->suspendFromOrder($master))->toBeTrue()
        ->and($master->status)->toBe(Order::STATUS_SUSPENDED)
        ->and($serviceMock->unsuspendFromOrder($master))->toBeTrue()
        ->and($master->status)->toBe(Order::STATUS_ACTIVE);
});

test('a cancellation scheduled at the period end covers the active addons, and the gateway end cancels them', function (): void {
    $master = orderServiceCreateLegacyOrderModel(10);
    $master->status = Order::STATUS_ACTIVE;
    $master->group_master = 1;
    $addon = orderServiceCreateLegacyOrderModel(11);
    $addon->status = Order::STATUS_ACTIVE;
    $gone = orderServiceCreateLegacyOrderModel(12);
    $gone->status = Order::STATUS_CANCELED;

    $master->expires_at = '2027-01-01 00:00:00';
    $subscriptionService = Mockery::mock(ClientFence\Mod\Invoice\ServiceSubscription::class);
    $subscriptionService->shouldReceive('hasGatewaySubscriptionForOrder')->andReturn(true);
    $subscriptionService->shouldReceive('canCancelAtPeriodEndForOrder')->andReturn(true);
    $subscriptionService->shouldReceive('scheduleCancellationForOrder')->andReturn(1);
    $dbal = Mockery::mock(Doctrine\DBAL\Connection::class)->shouldIgnoreMissing();
    $dbal->shouldReceive('fetchAllAssociative')->andReturn([]);
    // The open renewal invoices of the service and of its active addon are cancelled.
    $cancelled = [];
    $dbal->shouldReceive('executeStatement')->with(Mockery::pattern('/^UPDATE invoice i SET i.status = :canceled/'), Mockery::any())
        ->andReturnUsing(function (string $sql, array $p) use (&$cancelled): int {
            $cancelled[] = $p['order_id'];

            return 1;
        });
    $di = container();
    $di['dbal'] = $dbal;
    $di['db'] = Mockery::mock(\ClientFence\Database::class)->shouldIgnoreMissing();
    $di['events_manager'] = Mockery::mock(\ClientFence\EventManager::class)->shouldIgnoreMissing();
    $di['logger'] = new \ClientFence\Log();
    $di['mod_service'] = $di->protect(fn () => $subscriptionService);

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('_callOnService');
    $service->shouldReceive('saveStatusChange');
    $service->shouldReceive('getOrderAddonsList')->with($master)->andReturn([$addon, $gone]);
    $service->shouldReceive('updateOrderMeta')->once()->with($master, [Service::META_CANCEL_AT_PERIOD_END => '1']);
    $service->shouldReceive('updateOrderMeta')->once()->with($addon, [Service::META_CANCEL_AT_PERIOD_END => '1']);
    $service->shouldReceive('cancelFromOrder')->once()->with($addon, 'Subscription ended at the payment gateway', false)->andReturn(true);
    $service->shouldNotReceive('cancelFromOrder')->with($gone, Mockery::any(), Mockery::any());
    $service->setDi($di);

    expect($service->scheduleCancellationFromOrder($master))->toBeTrue()
        ->and($cancelled)->toBe(['10', '11'])
        ->and($service->finalizeCancellationFromGateway($master, 'Subscription ended at the payment gateway'))->toBeTrue()
        ->and($master->status)->toBe(Order::STATUS_CANCELED);
});

// ClientFence: deleting an order cancels its open renewal and waiting upgrade invoices.
test('deleteFromOrder cancels subscriptions before deletion and cancels open invoices', function (): void {
    $order = orderServiceCreateLegacyOrderModel(10);
    $order->status = Order::STATUS_ACTIVE;
    $dbal = Mockery::mock(Doctrine\DBAL\Connection::class)->shouldIgnoreMissing();
    $dbal->shouldReceive('fetchAllAssociative')->once()->with(Mockery::pattern('/FROM client_order_upgrade u/'), [10, 'pending', Model_Invoice::STATUS_UNPAID])->andReturn([['id' => 3, 'invoice_id' => 40]]);
    $dbal->shouldReceive('update')->once()->with('invoice', Mockery::on(fn (array $v): bool => $v['status'] === Model_Invoice::STATUS_CANCELED), ['id' => 40, 'status' => Model_Invoice::STATUS_UNPAID]);
    $dbal->shouldReceive('update')->once()->with('client_order_upgrade', Mockery::on(fn (array $v): bool => $v['error'] === 'The order was deleted.'), Mockery::any());
    $dbal->shouldReceive('executeStatement')->once()->with(Mockery::pattern('/^UPDATE invoice i SET i.status = :canceled/'), Mockery::on(fn (array $p): bool => $p['order_id'] === '10'));
    $subscriptionService = Mockery::mock(ClientFence\Mod\Invoice\ServiceSubscription::class);
    $subscriptionService->shouldReceive('cancelForOrder')->once()->with($order)->globally()->ordered()->andReturn(1);
    $di = container();
    $di['dbal'] = $dbal;
    $di['events_manager'] = Mockery::mock(\ClientFence\EventManager::class)->shouldIgnoreMissing();
    $di['logger'] = new \ClientFence\Log();
    $di['mod_service'] = $di->protect(fn (string $module, string $sub = '') => $module === 'Invoice' && $sub === 'Subscription'
        ? $subscriptionService
        : Mockery::mock(ClientFence\Mod\Product\Service::class)->shouldIgnoreMissing());

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('_callOnService')->once()->with($order, Order::ACTION_DELETE)->globally()->ordered();
    $serviceMock->shouldReceive('rmClientOrderStatusByOrder');
    $serviceMock->shouldReceive('rmOrder')->once()->with($order)->globally()->ordered();
    $serviceMock->shouldNotReceive('rmInvoiceItemByOrder');
    $serviceMock->setDi($di);

    expect($serviceMock->deleteFromOrder($order))->toBeTrue();
});

test('cancelOpenInvoices cancels discounted renewals and preserves invoices shared with another order', function (): void {
    $connection = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $connection->executeStatement('CREATE TABLE invoice (id INTEGER PRIMARY KEY, status TEXT, updated_at TEXT)');
    $connection->executeStatement('CREATE TABLE invoice_item (invoice_id INTEGER, type TEXT, rel_id TEXT, task TEXT, unit TEXT)');
    foreach ([40, 41] as $id) {
        $connection->insert('invoice', ['id' => $id, 'status' => 'unpaid']);
        $connection->insert('invoice_item', ['invoice_id' => $id, 'type' => 'order', 'rel_id' => '1', 'task' => 'renew', 'unit' => 'service']);
        $connection->insert('invoice_item', ['invoice_id' => $id, 'type' => 'custom', 'rel_id' => '1', 'unit' => 'discount']);
    }
    $connection->insert('invoice_item', ['invoice_id' => 41, 'type' => 'order', 'rel_id' => '2', 'task' => 'renew', 'unit' => 'service']);

    $dbal = Mockery::mock(Doctrine\DBAL\Connection::class);
    $dbal->shouldReceive('fetchAllAssociative')->once()->andReturn([]);
    $dbal->shouldReceive('executeStatement')->once()->andReturnUsing(function (string $sql, array $params) use ($connection): int {
        // SQLite requires an explicit alias and unqualified assignment columns.
        $sql = str_replace(['UPDATE invoice i SET i.status', 'i.updated_at ='], ['UPDATE invoice AS i SET status', 'updated_at ='], $sql);

        return $connection->executeStatement($sql, $params);
    });
    $di = container();
    $di['dbal'] = $dbal;
    $service = new Service();
    $service->setDi($di);

    (new ReflectionMethod(Service::class, 'cancelOpenInvoices'))->invoke($service, 1, 'The order was cancelled.');

    expect($connection->fetchFirstColumn('SELECT status FROM invoice ORDER BY id'))->toBe(['canceled', 'unpaid']);
});

test('cancelFromOrder cancels linked subscriptions before the service action', function (): void {
    $clientOrderModel = createEntity(Order::class);
    $clientOrderModel->id = 10;
    $clientOrderModel->status = Order::STATUS_ACTIVE;
    $legacyOrder = orderServiceCreateLegacyOrderModel(10);

    $calls = [];
    $subscriptionService = Mockery::mock(ClientFence\Mod\Invoice\ServiceSubscription::class);
    $subscriptionService->shouldReceive('cancelForOrder')
        ->once()
        ->with($legacyOrder)
        ->andReturnUsing(function () use (&$calls): int {
            $calls[] = 'subscriptions';

            return 1;
        });

    $productService = Mockery::mock(ClientFence\Mod\Product\Service::class);
    $productService->shouldReceive('releaseReservedPromoRedemptionsForOrder')
        ->once()
        ->with($clientOrderModel, 'order_canceled');

    $connectionMock = Mockery::mock(Doctrine\DBAL\Connection::class);
    $connectionMock->shouldReceive('executeStatement')
        ->once()
        ->with(
            'DELETE FROM client_order_meta WHERE client_order_id = :order_id AND name = :name',
            ['order_id' => $clientOrderModel->id, 'name' => Service::META_CANCEL_AT_PERIOD_END],
        );
    // The order's open renewal invoice is cancelled with it.
    $connectionMock->shouldReceive('executeStatement')
        ->once()
        ->with(Mockery::pattern('/^UPDATE invoice i SET i.status = :canceled/'), Mockery::on(fn (array $p): bool => $p['canceled'] === Model_Invoice::STATUS_CANCELED && $p['unpaid'] === Model_Invoice::STATUS_UNPAID && $p['order_id'] === (string) $clientOrderModel->id && $p['renew'] === Model_InvoiceItem::TASK_RENEW));
    // ... and its waiting upgrade invoice.
    $connectionMock->shouldReceive('fetchAllAssociative')->once()->with(Mockery::pattern('/FROM client_order_upgrade u/'), [10, 'pending', Model_Invoice::STATUS_UNPAID])->andReturn([]);
    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldIgnoreMissing();
    $dbMock = Mockery::mock(\ClientFence\Database::class);
    $dbMock->shouldReceive('getExistingModelById')->once()->with('ClientOrder', 10)->andReturn($legacyOrder);

    $di = container();
    $di['db'] = $dbMock;
    $di['em'] = $emMock;
    $di['dbal'] = $connectionMock;
    $di['logger'] = new \ClientFence\Log();
    $di['mod_service'] = $di->protect(function (string $module, string $service = '') use ($productService, $subscriptionService) {
        if ($module === 'Invoice' && $service === 'Subscription') {
            return $subscriptionService;
        }

        return $productService;
    });

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('_callOnService')
        ->once()
        ->andReturnUsing(function () use (&$calls): void {
            $calls[] = 'service';
        });
    $serviceMock->shouldReceive('saveStatusChange')->once();
    $serviceMock->setDi($di);

    expect($serviceMock->cancelFromOrder($clientOrderModel, skipEvent: true))->toBeTrue()
        ->and($clientOrderModel->status)->toBe(Order::STATUS_CANCELED)
        ->and($calls)->toBe(['subscriptions', 'service']);
});

test('cancelFromOrder cancels the addons of a RedBean order too (staff Retry)', function (): void {
    $master = orderServiceCreateLegacyOrderModel(10);
    $master->status = Order::STATUS_ACTIVE;
    $master->group_master = 1;
    $addon = orderServiceCreateLegacyOrderModel(11);
    $addon->status = Order::STATUS_ACTIVE;
    $done = orderServiceCreateLegacyOrderModel(12);
    $done->status = Order::STATUS_CANCELED;

    $subscriptionService = Mockery::mock(ClientFence\Mod\Invoice\ServiceSubscription::class)->shouldIgnoreMissing();
    $productService = Mockery::mock(ClientFence\Mod\Product\Service::class)->shouldIgnoreMissing();
    $di = container();
    $di['db'] = Mockery::mock(\ClientFence\Database::class)->shouldIgnoreMissing();
    $di['logger'] = new \ClientFence\Log();
    $di['mod_service'] = $di->protect(fn (string $module, string $service = '') => $module === 'Invoice' ? $subscriptionService : $productService);

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('_callOnService');
    $serviceMock->shouldReceive('saveStatusChange');
    $serviceMock->shouldReceive('getOrderAddonsList')->once()->with($master)->andReturn([$addon, $done]);
    $serviceMock->shouldReceive('cancelFromOrder')->with($master, null, true)->passthru();
    $serviceMock->shouldReceive('cancelFromOrder')->once()->with($addon, null, true)->andReturn(true);
    $serviceMock->shouldNotReceive('cancelFromOrder')->with($done, Mockery::any(), Mockery::any());
    $serviceMock->setDi($di);

    expect($serviceMock->cancelFromOrder($master, null, true))->toBeTrue()
        ->and($master->status)->toBe(Order::STATUS_CANCELED);
});

test('scheduleCancellationFromOrder keeps the service active, with or without a gateway subscription', function (bool $withSubscription): void {
    $order = createEntity(Order::class, [
        'id' => 10,
        'status' => Order::STATUS_ACTIVE,
    ]);
    $legacyOrder = orderServiceCreateLegacyOrderModel(10);
    $legacyOrder->expires_at = '2027-01-01 00:00:00';

    $subscriptionService = Mockery::mock(ClientFence\Mod\Invoice\ServiceSubscription::class);
    $subscriptionService->shouldReceive('hasGatewaySubscriptionForOrder')->once()->with($legacyOrder)->andReturn($withSubscription);
    $subscriptionService->shouldReceive('canCancelAtPeriodEndForOrder')->times($withSubscription ? 1 : 0)->with($legacyOrder)->andReturn(true);
    $subscriptionService->shouldReceive('scheduleCancellationForOrder')->times($withSubscription ? 1 : 0)->with($legacyOrder)->andReturn(1);

    $di = container();
    $dbMock = Mockery::mock(\ClientFence\Database::class);
    $dbMock->shouldReceive('getExistingModelById')->once()->with('ClientOrder', 10)->andReturn($legacyOrder);
    $di['db'] = $dbMock;
    $di['logger'] = new \ClientFence\Log();
    $di['mod_service'] = $di->protect(fn () => $subscriptionService);
    // The open renewal invoice is cancelled: paid now, it would be lost when the service ends.
    $dbal = Mockery::mock(Doctrine\DBAL\Connection::class);
    $dbal->shouldReceive('fetchAllAssociative')->once()->andReturn([]);
    $dbal->shouldReceive('executeStatement')->once()->with(Mockery::pattern('/^UPDATE invoice i SET i.status = :canceled/'), Mockery::on(fn (array $p): bool => $p['order_id'] === '10' && $p['renew'] === 'renew'));
    $di['dbal'] = $dbal;

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldNotReceive('_callOnService');
    $service->shouldReceive('updateOrderMeta')
        ->once()
        ->with($order, [Service::META_CANCEL_AT_PERIOD_END => '1'])
        ->andReturn(2);
    $service->shouldReceive('saveStatusChange')
        ->once()
        ->with($order, 'Cancellation scheduled at the end of the current billing period');
    $service->setDi($di);

    expect($service->scheduleCancellationFromOrder($order, 'Customer request'))->toBeTrue()
        ->and($order->status)->toBe(Order::STATUS_ACTIVE)
        ->and($order->reason)->toBe('Customer request');
})->with(['gateway subscription' => [true], 'no subscription (bank transfer)' => [false]]);

test('scheduleCancellationFromOrder does not mark the order when no subscription was scheduled', function (): void {
    $order = createEntity(Order::class, [
        'status' => Order::STATUS_ACTIVE,
    ]);
    $legacyOrder = orderServiceCreateLegacyOrderModel(0);
    $legacyOrder->expires_at = '2027-01-01 00:00:00';

    $subscriptionService = Mockery::mock(ClientFence\Mod\Invoice\ServiceSubscription::class);
    $subscriptionService->shouldReceive('hasGatewaySubscriptionForOrder')->once()->with($legacyOrder)->andReturn(true);
    $subscriptionService->shouldReceive('canCancelAtPeriodEndForOrder')->once()->with($legacyOrder)->andReturn(true);
    $subscriptionService->shouldReceive('scheduleCancellationForOrder')->once()->with($legacyOrder)->andReturn(0);

    $di = container();
    $dbMock = Mockery::mock(\ClientFence\Database::class);
    $dbMock->shouldReceive('getExistingModelById')->once()->with('ClientOrder', 0)->andReturn($legacyOrder);
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(fn () => $subscriptionService);

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldNotReceive('updateOrderMeta');
    $service->setDi($di);

    expect(fn () => $service->scheduleCancellationFromOrder($order))
        ->toThrow(ClientFence\InformationException::class, 'No active gateway subscription is linked to this order.');
});

test('cancelFromOrder keeps the order open when the service action fails after subscription cancellation', function (): void {
    $clientOrderModel = createEntity(Order::class);
    $clientOrderModel->status = Order::STATUS_ACTIVE;
    $legacyOrder = orderServiceCreateLegacyOrderModel(0);

    $subscriptionService = Mockery::mock(ClientFence\Mod\Invoice\ServiceSubscription::class);
    $subscriptionService->shouldReceive('cancelForOrder')->once()->with($legacyOrder)->andReturn(1);

    $dbMock = Mockery::mock(\ClientFence\Database::class);
    $dbMock->shouldNotReceive('store');
    $dbMock->shouldReceive('getExistingModelById')->once()->with('ClientOrder', 0)->andReturn($legacyOrder);

    $di = container();
    $di['db'] = $dbMock;
    $di['em'] = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class)->shouldIgnoreMissing();
    $di['mod_service'] = $di->protect(fn () => $subscriptionService);

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('_callOnService')
        ->once()
        ->andThrow(new RuntimeException('Service cancellation failed'));
    $serviceMock->setDi($di);

    expect(fn () => $serviceMock->cancelFromOrder($clientOrderModel, skipEvent: true))
        ->toThrow(RuntimeException::class, 'Service cancellation failed')
        ->and($clientOrderModel->status)->toBe(Order::STATUS_ACTIVE);
});

test('cancelFromOrder remains retryable when subscription cancellation fails', function (): void {
    $clientOrderModel = createEntity(Order::class);
    $clientOrderModel->status = Order::STATUS_ACTIVE;
    $legacyOrder = orderServiceCreateLegacyOrderModel(0);

    $subscriptionService = Mockery::mock(ClientFence\Mod\Invoice\ServiceSubscription::class);
    $subscriptionService->shouldReceive('cancelForOrder')
        ->once()
        ->with(Mockery::any())
        ->andThrow(new RuntimeException('Subscription cancellation failed'));

    $dbMock = Mockery::mock(\ClientFence\Database::class);
    $dbMock->shouldNotReceive('store');
    $dbMock->shouldReceive('getExistingModelById')->once()->with('ClientOrder', 0)->andReturn($legacyOrder);

    $di = container();
    $di['db'] = $dbMock;
    $di['em'] = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class)->shouldIgnoreMissing();
    $di['mod_service'] = $di->protect(fn () => $subscriptionService);

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldNotReceive('_callOnService');
    $serviceMock->setDi($di);

    expect(fn () => $serviceMock->cancelFromOrder($clientOrderModel, skipEvent: true))
        ->toThrow(RuntimeException::class, 'Subscription cancellation failed')
        ->and($clientOrderModel->status)->toBe(Order::STATUS_ACTIVE);
});

test('rmByClient removes all client orders', function (): void {
    $clientModel = createEntity(ClientFence\Mod\Client\Entity\Client::class, ['id' => 100]);

    $orderModel = createEntity(Order::class);

    $queryBuilderMock = new class {
        private bool $deleteCalled = false;
        private bool $whereCalled = false;
        private bool $setParamCalled = false;
        private mixed $deleteTable = null;
        private mixed $whereCond = null;
        private mixed $paramId = null;

        public function delete($table)
        {
            $this->deleteCalled = true;
            $this->deleteTable = $table;

            return $this;
        }

        public function where($cond)
        {
            $this->whereCalled = true;
            $this->whereCond = $cond;

            return $this;
        }

        public function setParameter($key, $val)
        {
            $this->setParamCalled = true;
            $this->paramId = $val;

            return $this;
        }

        public function executeStatement(): int
        {
            return 1;
        }

        public function getDeleteTable()
        {
            return $this->deleteTable;
        }

        public function getWhereCond()
        {
            return $this->whereCond;
        }

        public function getParamId()
        {
            return $this->paramId;
        }

        public function wasDeleteCalled(): bool
        {
            return $this->deleteCalled;
        }

        public function wasWhereCalled(): bool
        {
            return $this->whereCalled;
        }

        public function wasSetParamCalled(): bool
        {
            return $this->setParamCalled;
        }
    };

    $dbalMock = new class($queryBuilderMock) {
        public function __construct(private $qb)
        {
        }

        public function createQueryBuilder()
        {
            return $this->qb;
        }
    };

    $orderRepoMock = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepoMock->shouldReceive('findByClientId')->once()->with(100)->andReturn([$orderModel]);

    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepoMock);
    $emMock->shouldIgnoreMissing();

    $productServiceMock = Mockery::mock(ClientFence\Mod\Product\Service::class);
    $productServiceMock->shouldReceive('releaseReservedPromoRedemptionsForOrder')
        ->once()
        ->with($orderModel, 'client_deleted');

    $di = container();
    $di['em'] = $emMock;
    $di['dbal'] = $dbalMock;
    $di['mod_service'] = $di->protect(fn (string $name): Mockery\MockInterface => match (strtolower($name)) {
        'product' => $productServiceMock,
        default => Mockery::mock()->shouldIgnoreMissing(),
    });

    $svc = new Service();
    $svc->setDi($di);

    $svc->rmByClient($clientModel);

    expect($queryBuilderMock->getDeleteTable())->toBe('client_order');
    expect($queryBuilderMock->getWhereCond())->toBe('client_id = :id');
    expect($queryBuilderMock->getParamId())->toBe($clientModel->id);
});

test('updatePeriod sets period when given', function (): void {
    $period = '1Y';
    $di = container();

    $periodMock = Mockery::mock(\ClientFence\Period::class);
    $periodMock->shouldReceive('getCode')->atLeast()->once();
    $di['period'] = $di->protect(fn (): Mockery\MockInterface => $periodMock);

    $svc = new Service();
    $svc->setDi($di);

    $clientOrder = createEntity(Order::class);

    $result = $svc->updatePeriod($clientOrder, $period);

    expect($result)->toEqual(1);
});

test('updatePeriod clears period when empty string', function (): void {
    $period = '';
    $di = container();

    $periodMock = Mockery::mock(\ClientFence\Period::class);
    $periodMock->shouldReceive('getCode')->never();
    $di['period'] = $di->protect(fn (): Mockery\MockInterface => $periodMock);

    $svc = new Service();
    $svc->setDi($di);

    $clientOrder = createEntity(Order::class);

    $result = $svc->updatePeriod($clientOrder, $period);

    expect($result)->toEqual(2);
});

test('updatePeriod does nothing when null', function (): void {
    $period = null;
    $di = container();

    $periodMock = Mockery::mock(\ClientFence\Period::class);
    $periodMock->shouldReceive('getCode')->never();
    $di['period'] = $di->protect(fn (): Mockery\MockInterface => $periodMock);

    $svc = new Service();
    $svc->setDi($di);

    $clientOrder = createEntity(Order::class);

    $result = $svc->updatePeriod($clientOrder, $period);

    expect($result)->toEqual(0);
});

test('updateOrderMeta returns 0 when meta is not an array', function (): void {
    $meta = null;
    $clientOrder = createEntity(Order::class);

    $svc = new Service();

    $result = $svc->updateOrderMeta($clientOrder, $meta);

    expect($result)->toEqual(0);
});

test('updateOrderMeta clears the existing meta of the order when empty', function (): void {
    $dbal = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $dbal->executeStatement('CREATE TABLE client_order_meta (id INTEGER PRIMARY KEY, client_order_id INTEGER, name TEXT, value TEXT, created_at TEXT, updated_at TEXT)');
    $dbal->insert('client_order_meta', ['client_order_id' => 1, 'name' => 'note', 'value' => 'y']);
    $dbal->insert('client_order_meta', ['client_order_id' => 2, 'name' => 'note', 'value' => 'x']);
    $di = container();
    $di['dbal'] = $dbal;

    $svc = new Service();
    $svc->setDi($di);
    $clientOrder = createEntity(Order::class);
    $clientOrder->id = 1;

    expect($svc->updateOrderMeta($clientOrder, []))->toEqual(1)
        ->and((int) $dbal->fetchOne('SELECT COUNT(*) FROM client_order_meta WHERE client_order_id = 1'))->toBe(0)
        ->and((int) $dbal->fetchOne('SELECT COUNT(*) FROM client_order_meta WHERE client_order_id = 2'))->toBe(1);
});

test('updateOrderMeta stores new meta entries', function (): void {
    $meta = ['key' => 'value'];

    $metaRepoMock = Mockery::mock(ClientFence\Mod\Order\Repository\OrderMetaRepository::class)->shouldIgnoreMissing();
    $metaRepoMock->shouldReceive('findOneByOrderIdAndName')->with(1, 'key')->once()->andReturn(null);

    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('getRepository')->with(ClientFence\Mod\Order\Entity\OrderMeta::class)->andReturn($metaRepoMock);
    $emMock->shouldReceive('persist')->once();
    $emMock->shouldReceive('flush')->once();
    $emMock->shouldIgnoreMissing();

    $di = container();
    $di['em'] = $emMock;

    $svc = new Service();
    $svc->setDi($di);

    $clientOrder = createEntity(Order::class);
    $clientOrder->id = 1;

    $result = $svc->updateOrderMeta($clientOrder, $meta);

    expect($result)->toEqual(2);
});

test('updateOrderConfig succeeds when no form id is set', function (): void {
    $di = container();
    $di['logger'] = new \ClientFence\Log();

    $svc = new Service();
    $svc->setDi($di);

    $order = createEntity(Order::class);
    $order->form_id = null;

    $result = $svc->updateOrderConfig($order, ['key' => 'value']);

    expect($result)->toBeTrue();
});

test('updateOrderConfig keeps the configurable options: they change on their own tab only', function (): void {
    $di = container();
    $di['logger'] = new \ClientFence\Log();

    $svc = new Service();
    $svc->setDi($di);

    $order = createEntity(Order::class);
    $order->form_id = null;
    $order->setConfig(json_encode(['key' => 'old', 'options' => [3 => 7], 'option_lines' => [['title' => 'RAM', 'price' => 5]]]));

    // The raw editor never sends them back intact (the lines are nested arrays).
    expect($svc->updateOrderConfig($order, ['key' => 'value', 'option_lines' => ['Array']]))->toBeTrue()
        ->and(json_decode((string) $order->getConfig(), true))->toBe(['key' => 'value', 'options' => [3 => 7], 'option_lines' => [['title' => 'RAM', 'price' => 5]]]);
});

test('updateOrderConfig throws when required field is missing', function (): void {
    $form = [
        'fields' => [
            ['name' => 'hostname', 'label' => 'Hostname', 'type' => 'text', 'required' => true, 'options' => []],
        ],
    ];

    $formbuilderServiceMock = Mockery::mock(ClientFence\Mod\Formbuilder\Service::class);
    $formbuilderServiceMock->shouldReceive('getForm')->once()->with(7)->andReturn($form);

    $di = container();
    $di['mod_service'] = $di->protect(function ($serviceName) use ($formbuilderServiceMock) {
        if ($serviceName === 'formbuilder') {
            return $formbuilderServiceMock;
        }
    });

    $svc = new Service();
    $svc->setDi($di);

    $order = createEntity(Order::class);
    $order->form_id = 7;

    expect(fn (): bool => $svc->updateOrderConfig($order, []))
        ->toThrow(ClientFence\Exception::class, '', 4892);
});

test('updateOrderConfig throws for invalid select option', function (): void {
    $form = [
        'fields' => [
            ['name' => 'plan', 'label' => 'Plan', 'type' => 'select', 'required' => false, 'options' => ['basic' => 'Basic', 'pro' => 'Pro']],
        ],
    ];

    $formbuilderServiceMock = Mockery::mock(ClientFence\Mod\Formbuilder\Service::class);
    $formbuilderServiceMock->shouldReceive('getForm')->once()->andReturn($form);

    $di = container();
    $di['mod_service'] = $di->protect(function ($serviceName) use ($formbuilderServiceMock) {
        if ($serviceName === 'formbuilder') {
            return $formbuilderServiceMock;
        }
    });

    $svc = new Service();
    $svc->setDi($di);

    $order = createEntity(Order::class);
    $order->form_id = 8;

    expect(fn (): bool => $svc->updateOrderConfig($order, ['plan' => 'enterprise']))
        ->toThrow(ClientFence\Exception::class, '', 4893);
});

test('updateOrderConfig select rejects array value', function (): void {
    $form = [
        'fields' => [
            ['name' => 'plan', 'label' => 'Plan', 'type' => 'select', 'required' => false, 'options' => ['basic' => 'Basic', 'pro' => 'Pro']],
        ],
    ];

    $formbuilderServiceMock = Mockery::mock(ClientFence\Mod\Formbuilder\Service::class);
    $formbuilderServiceMock->shouldReceive('getForm')->once()->andReturn($form);

    $di = container();
    $di['mod_service'] = $di->protect(function ($serviceName) use ($formbuilderServiceMock) {
        if ($serviceName === 'formbuilder') {
            return $formbuilderServiceMock;
        }
    });

    $svc = new Service();
    $svc->setDi($di);

    $order = createEntity(Order::class);
    $order->form_id = 11;

    expect(fn (): bool => $svc->updateOrderConfig($order, ['plan' => ['pro']]))
        ->toThrow(ClientFence\Exception::class, '', 4893);
});

test('updateOrderConfig throws for invalid radio option', function (): void {
    $form = [
        'fields' => [
            ['name' => 'os', 'label' => 'OS', 'type' => 'radio', 'required' => false, 'options' => ['linux' => 'Linux', 'windows' => 'Windows']],
        ],
    ];

    $formbuilderServiceMock = Mockery::mock(ClientFence\Mod\Formbuilder\Service::class);
    $formbuilderServiceMock->shouldReceive('getForm')->once()->andReturn($form);

    $di = container();
    $di['mod_service'] = $di->protect(function ($serviceName) use ($formbuilderServiceMock) {
        if ($serviceName === 'formbuilder') {
            return $formbuilderServiceMock;
        }
    });

    $svc = new Service();
    $svc->setDi($di);

    $order = createEntity(Order::class);
    $order->form_id = 9;

    expect(fn (): bool => $svc->updateOrderConfig($order, ['os' => 'macos']))
        ->toThrow(ClientFence\Exception::class, '', 4893);
});

test('updateOrderConfig throws for invalid checkbox option', function (): void {
    $form = [
        'fields' => [
            ['name' => 'addons', 'label' => 'Addons', 'type' => 'checkbox', 'required' => false, 'options' => ['backup', 'ssl']],
        ],
    ];

    $formbuilderServiceMock = Mockery::mock(ClientFence\Mod\Formbuilder\Service::class);
    $formbuilderServiceMock->shouldReceive('getForm')->once()->andReturn($form);

    $di = container();
    $di['mod_service'] = $di->protect(function ($serviceName) use ($formbuilderServiceMock) {
        if ($serviceName === 'formbuilder') {
            return $formbuilderServiceMock;
        }
    });

    $svc = new Service();
    $svc->setDi($di);

    $order = createEntity(Order::class);
    $order->form_id = 10;

    expect(fn (): bool => $svc->updateOrderConfig($order, ['addons' => ['backup', 'ddos-protection']]))
        ->toThrow(ClientFence\Exception::class, '', 4894);
});

test('updateOrderConfig succeeds with valid form data', function (): void {
    $form = [
        'fields' => [
            ['name' => 'hostname', 'label' => 'Hostname', 'type' => 'text', 'required' => true, 'options' => []],
            ['name' => 'plan', 'label' => 'Plan', 'type' => 'select', 'required' => false, 'options' => ['basic' => 'Basic', 'pro' => 'Pro']],
            ['name' => 'addons', 'label' => 'Addons', 'type' => 'checkbox', 'required' => false, 'options' => ['backup', 'ssl']],
        ],
    ];

    $formbuilderServiceMock = Mockery::mock(ClientFence\Mod\Formbuilder\Service::class);
    $formbuilderServiceMock->shouldReceive('getForm')->once()->andReturn($form);

    $di = container();
    $di['mod_service'] = $di->protect(function ($serviceName) use ($formbuilderServiceMock) {
        if ($serviceName === 'formbuilder') {
            return $formbuilderServiceMock;
        }
    });
    $di['em'] = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class)->shouldIgnoreMissing();
    $di['logger'] = new \ClientFence\Log();

    $svc = new Service();
    $svc->setDi($di);

    $order = createEntity(Order::class);
    $order->form_id = 11;

    $result = $svc->updateOrderConfig($order, ['hostname' => 'myhost.example.com', 'plan' => 'pro', 'addons' => ['backup', 'ssl']]);

    expect($result)->toBeTrue();
});

test('createOrder rejects invalid price and quantity', function (array $data, string $message): void {
    $service = new Service();
    $client = createEntity(ClientFence\Mod\Client\Entity\Client::class);
    $product = orderServiceCreateProductEntity(1, 'custom');

    expect(fn () => $service->createOrder($client, $product, $data))
        ->toThrow(ClientFence\InformationException::class, $message);
})->with([
    'negative price' => [['price' => -1], 'Price cannot be negative'],
    'invalid price' => [['price' => 'invalid'], 'Price must be a valid number'],
    'invalid quantity' => [['quantity' => 'invalid'], 'Quantity must be a valid number'],
]);

test('updateOrder rejects a negative price', function (): void {
    $order = createEntity(Order::class);

    $events = Mockery::mock(\ClientFence\EventManager::class);
    $events->shouldReceive('fire')->once();

    $di = container();
    $di['events_manager'] = $events;

    $service = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('updatePeriod')->once();
    $service->setDi($di);

    expect(fn () => $service->updateOrder($order, ['price' => -1]))
        ->toThrow(ClientFence\InformationException::class, 'Price cannot be negative');
});

test('createOrder generates an invoice for a zero-price order with issue-invoice', function (): void {
    $modelClient = createEntity(ClientFence\Mod\Client\Entity\Client::class, ['currency' => 'USD']);

    $modelProduct = orderServiceCreateProductEntity(1, 'custom');

    $currencyModel = Mockery::mock(ClientFence\Mod\Currency\Entity\Currency::class)->shouldIgnoreMissing();

    $currencyRepositoryMock = Mockery::mock(ClientFence\Mod\Currency\Repository\CurrencyRepository::class);
    $currencyRepositoryMock->shouldReceive('findOneByCode')->atLeast()->once()->andReturn($currencyModel);

    $currencyServiceMock = Mockery::mock(ClientFence\Mod\Currency\Service::class);
    $currencyServiceMock->shouldReceive('getCurrencyRepository')->atLeast()->once()->andReturn($currencyRepositoryMock);

    $cartServiceMock = Mockery::mock(ClientFence\Mod\Cart\Service::class);
    $cartServiceMock->shouldReceive('isStockAvailable')
        ->atLeast()->once()
        ->with($modelProduct, Mockery::any())
        ->andReturn(true);

    $eventMock = Mockery::mock(\ClientFence\EventManager::class);
    $eventMock->shouldReceive('fire')->atLeast()->once();

    $productServiceMock = Mockery::mock(ClientFence\Mod\Servicecustom\Service::class);
    $pricingServiceMock = Mockery::mock(ClientFence\Mod\Product\Service::class);
    $pricingServiceMock->shouldReceive('getProductOrderLineConfig')->never();

    $clientOrderModel = orderServiceCreateLegacyOrderModel(1);

    $invoiceModel = orderServiceCreateInvoiceModel(10);

    $invoiceServiceMock = Mockery::mock();
    $invoiceServiceMock->shouldReceive('generateForOrder')
        ->once()
        ->with(Mockery::any())
        ->andReturn($invoiceModel);
    $invoiceServiceMock->shouldReceive('approveInvoice')
        ->once()
        ->with($invoiceModel, ['id' => $invoiceModel->id, 'use_credits' => true])
        ->andReturn(true);

    $persistedEntities = [];
    $nextOrderId = 1;
    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('persist')->atLeast()->once()->andReturnUsing(function ($entity) use (&$persistedEntities): void {
        $persistedEntities[] = $entity;
    });
    $emMock->shouldReceive('flush')->atLeast()->once()->andReturnUsing(function () use (&$persistedEntities, &$nextOrderId): void {
        foreach ($persistedEntities as $entity) {
            $refl = new ReflectionClass($entity);
            if ($refl->hasProperty('id')) {
                $prop = $refl->getProperty('id');
                if ($prop->getValue($entity) === null) {
                    $prop->setValue($entity, $nextOrderId++);
                }
            }
        }
        $persistedEntities = [];
    });
    $emMock->shouldReceive('wrapInTransaction')->once()->andReturnUsing(fn (callable $callback) => $callback());
    $emMock->shouldReceive('remove')->andReturnNull();
    $orderRepoMock = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepoMock->shouldReceive('find')->andReturnUsing(function (?int $id) use (&$nextOrderId): ?object {
        if ($id === null) {
            return null;
        }
        $order = new Order();
        $prop = new ReflectionProperty($order, 'id');
        $prop->setValue($order, $id);

        return $order;
    });
    $orderRepoMock->shouldReceive('findOneByOrderIdAndName')->byDefault()->andReturn(null);
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepoMock);
    $emMock->shouldReceive('getRepository')->with(ClientFence\Mod\Order\Entity\OrderMeta::class)->andReturn(Mockery::mock(ClientFence\Mod\Order\Repository\OrderMetaRepository::class)->shouldIgnoreMissing());
    $emMock->shouldIgnoreMissing();
    $dbMock = Mockery::mock(\ClientFence\Database::class);
    $dbMock->shouldReceive('getExistingModelById')->once()->with('ClientOrder', 1)->andReturn($clientOrderModel);

    $newId = 1;

    $periodMock = Mockery::mock(\ClientFence\Period::class);
    $periodMock->shouldReceive('getCode')->atLeast()->once()->andReturn('1Y');

    $di = container();
    $di['mod_service'] = $di->protect(function ($serviceName) use ($cartServiceMock, $currencyServiceMock, $invoiceServiceMock, $productServiceMock, $pricingServiceMock) {
        if ($serviceName == 'currency') {
            return $currencyServiceMock;
        }
        if ($serviceName == 'cart') {
            return $cartServiceMock;
        }
        if ($serviceName == 'Product') {
            return $pricingServiceMock;
        }
        if ($serviceName == 'invoice') {
            return $invoiceServiceMock;
        }
        if ($serviceName == 'servicecustom') {
            return $productServiceMock;
        }
    });
    $di['events_manager'] = $eventMock;
    $di['db'] = $dbMock;
    $di['em'] = $emMock;
    $di['period'] = $di->protect(fn (): Mockery\MockInterface => $periodMock);
    $di['logger'] = new \ClientFence\Log();

    $svc = new Service();
    $svc->setDi($di);

    $result = $svc->createOrder($modelClient, $modelProduct, [
        'period' => '1Y',
        'price' => 0,
        'invoice_option' => 'issue-invoice',
    ]);

    expect($result)->toBe($newId);
});

test('createOrder does not roll back when invoice generation fails for a negative resolved price', function (): void {
    $modelClient = createEntity(ClientFence\Mod\Client\Entity\Client::class, ['currency' => 'USD']);

    $modelProduct = orderServiceCreateProductEntity(1, 'custom');

    $currencyModel = Mockery::mock(ClientFence\Mod\Currency\Entity\Currency::class)->shouldIgnoreMissing();

    $currencyRepositoryMock = Mockery::mock(ClientFence\Mod\Currency\Repository\CurrencyRepository::class);
    $currencyRepositoryMock->shouldReceive('findOneByCode')->atLeast()->once()->andReturn($currencyModel);
    $currencyRepositoryMock->shouldReceive('getRateByCode')->atLeast()->once()->andReturn(1.0);

    $currencyServiceMock = Mockery::mock(ClientFence\Mod\Currency\Service::class);
    $currencyServiceMock->shouldReceive('getCurrencyRepository')->atLeast()->once()->andReturn($currencyRepositoryMock);

    $cartServiceMock = Mockery::mock(ClientFence\Mod\Cart\Service::class);
    $cartServiceMock->shouldReceive('isRecurrentPricing')->andReturn(false);
    $cartServiceMock->shouldReceive('isStockAvailable')
        ->atLeast()->once()
        ->with($modelProduct, Mockery::any())
        ->andReturn(true);

    $eventMock = Mockery::mock(\ClientFence\EventManager::class);
    $eventMock->shouldReceive('fire')->atLeast()->once();

    $productServiceMock = Mockery::mock(ClientFence\Mod\Servicecustom\Service::class);
    $pricingServiceMock = Mockery::mock(ClientFence\Mod\Product\Service::class);
    $pricingServiceMock->shouldReceive('inCurrency')->andReturnSelf();
    $pricingServiceMock->shouldReceive('getProductOrderLineConfig')
        ->atLeast()->once()
        ->andReturn(['price' => -5.0, 'quantity' => 1]);

    $clientOrderModel = orderServiceCreateLegacyOrderModel(1);

    $invoiceServiceMock = Mockery::mock();
    $invoiceServiceMock->shouldReceive('generateForOrder')
        ->once()
        ->with(Mockery::any())
        ->andThrow(new ClientFence\InformationException('Invoices are not generated for negative amount orders.'));
    $invoiceServiceMock->shouldReceive('approveInvoice')->never();

    $persistedEntities = [];
    $nextOrderId = 1;
    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('persist')->atLeast()->once()->andReturnUsing(function ($entity) use (&$persistedEntities): void {
        $persistedEntities[] = $entity;
    });
    $emMock->shouldReceive('flush')->atLeast()->once()->andReturnUsing(function () use (&$persistedEntities, &$nextOrderId): void {
        foreach ($persistedEntities as $entity) {
            $refl = new ReflectionClass($entity);
            if ($refl->hasProperty('id')) {
                $prop = $refl->getProperty('id');
                if ($prop->getValue($entity) === null) {
                    $prop->setValue($entity, $nextOrderId++);
                }
            }
        }
        $persistedEntities = [];
    });
    $emMock->shouldReceive('wrapInTransaction')->once()->andReturnUsing(fn (callable $callback) => $callback());
    $emMock->shouldReceive('remove')->andReturnNull();
    $orderRepoMock = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepoMock->shouldReceive('find')->andReturnUsing(function (?int $id) use (&$nextOrderId): ?object {
        if ($id === null) {
            return null;
        }
        $order = new Order();
        $prop = new ReflectionProperty($order, 'id');
        $prop->setValue($order, $id);

        return $order;
    });
    $orderRepoMock->shouldReceive('findOneByOrderIdAndName')->byDefault()->andReturn(null);
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepoMock);
    $emMock->shouldReceive('getRepository')->with(ClientFence\Mod\Order\Entity\OrderMeta::class)->andReturn(Mockery::mock(ClientFence\Mod\Order\Repository\OrderMetaRepository::class)->shouldIgnoreMissing());
    $emMock->shouldIgnoreMissing();
    $dbMock = Mockery::mock(\ClientFence\Database::class);
    $dbMock->shouldReceive('getExistingModelById')->once()->with('ClientOrder', 1)->andReturn($clientOrderModel);

    $newId = 1;

    $periodMock = Mockery::mock(\ClientFence\Period::class);
    $periodMock->shouldReceive('getCode')->atLeast()->once()->andReturn('1Y');

    $di = container();
    $di['mod_service'] = $di->protect(function ($serviceName) use ($cartServiceMock, $currencyServiceMock, $invoiceServiceMock, $productServiceMock, $pricingServiceMock) {
        if ($serviceName == 'currency') {
            return $currencyServiceMock;
        }
        if ($serviceName == 'cart') {
            return $cartServiceMock;
        }
        if ($serviceName == 'Product') {
            return $pricingServiceMock;
        }
        if ($serviceName == 'invoice') {
            return $invoiceServiceMock;
        }
        if ($serviceName == 'servicecustom') {
            return $productServiceMock;
        }
    });
    $di['events_manager'] = $eventMock;
    $di['db'] = $dbMock;
    $di['em'] = $emMock;
    $di['period'] = $di->protect(fn (): Mockery\MockInterface => $periodMock);
    $di['logger'] = new \ClientFence\Log();

    $svc = new Service();
    $svc->setDi($di);

    $result = $svc->createOrder($modelClient, $modelProduct, [
        'period' => '1Y',
        'invoice_option' => 'issue-invoice',
    ]);

    expect($result)->toBe($newId);
});

test('getExpiredOrders uses strict expires_at <= NOW() filter', function (): void {
    $service = new Service();

    $orderRepository = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepository->shouldReceive('getExpired')
        ->once()
        ->andReturn([]);

    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepository);

    $di = container();
    $di['em'] = $emMock;
    $service->setDi($di);

    expect($service->getExpiredOrders())->toBe([]);
});

test('exportCSV strips config from numeric-array headers', function (): void {
    $service = new Service();

    $capturedHeaders = null;
    $factoryMock = Mockery::mock();
    $factoryMock->shouldReceive('create')
        ->once()
        ->andReturnUsing(function (string $table, string $name, array $headers) use (&$capturedHeaders): Symfony\Component\HttpFoundation\Response {
            $capturedHeaders = $headers;

            return new Symfony\Component\HttpFoundation\Response();
        });

    $di = container();
    $di['csv_response_factory'] = $factoryMock;
    $service->setDi($di);

    $service->exportCSV(['config', 'id', 'title']);

    expect($capturedHeaders)->not->toContain('config')
        ->and($capturedHeaders)->toContain('id')
        ->and($capturedHeaders)->toContain('title');
});

test('exportCSV falls back to defaults when only config is requested', function (): void {
    $service = new Service();

    $capturedHeaders = null;
    $factoryMock = Mockery::mock();
    $factoryMock->shouldReceive('create')
        ->once()
        ->andReturnUsing(function (string $table, string $name, array $headers) use (&$capturedHeaders): Symfony\Component\HttpFoundation\Response {
            $capturedHeaders = $headers;

            return new Symfony\Component\HttpFoundation\Response();
        });

    $di = container();
    $di['csv_response_factory'] = $factoryMock;
    $service->setDi($di);

    $service->exportCSV(['config']);

    expect($capturedHeaders)->toContain('id')
        ->and($capturedHeaders)->toContain('title')
        ->and($capturedHeaders)->not->toContain('config');
});

test('batchCancelUnpaid returns false and does not query orders when auto removal is disabled', function (): void {
    $service = new Service();

    $di = container();
    $di['events_manager'] = Mockery::mock('ClientFence\EventManager')->shouldIgnoreMissing();
    $di['mod'] = $di->protect(fn (string $name): object => new class {
        public function getConfig(): array
        {
            return [];
        }
    });

    $service->setDi($di);

    expect($service->batchCancelUnpaid())->toBeFalse();
});

test('batchCancelUnpaid removes each stale unpaid order and fires events', function (): void {
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();

    $orderA = createEntity(Order::class, ['id' => 1, 'status' => Order::STATUS_PENDING_SETUP]);
    $orderB = createEntity(Order::class, ['id' => 2, 'status' => Order::STATUS_PENDING_SETUP]);

    $orderRepository = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepository->shouldReceive('getStaleUnpaid')
        ->once()
        ->with(10)
        ->andReturn([$orderA, $orderB]);

    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepository);
    $emMock->shouldReceive('refresh')->twice();

    $eventsManager = Mockery::mock('ClientFence\EventManager');
    $eventsManager->shouldReceive('fire')->once()->with(['event' => 'onBeforeAdminBatchCancelUnpaidOrders']);
    $eventsManager->shouldReceive('fire')->once()->with(['event' => 'onAfterAdminBatchCancelUnpaidOrders']);

    $di = container();
    $di['em'] = $emMock;
    $di['events_manager'] = $eventsManager;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['mod'] = $di->protect(fn (string $name): object => new class {
        public function getConfig(): array
        {
            return ['batch_cancel_unpaid' => '1', 'batch_cancel_unpaid_after_days' => 10];
        }
    });

    $serviceMock->shouldReceive('deleteFromOrder')->once()->with($orderA);
    $serviceMock->shouldReceive('deleteFromOrder')->once()->with($orderB);

    $serviceMock->setDi($di);

    expect($serviceMock->batchCancelUnpaid())->toBeTrue();
});

test('batchCancelUnpaid falls back to the 7 day default when the configured value is blank', function (): void {
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();

    $orderRepository = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepository->shouldReceive('getStaleUnpaid')
        ->once()
        ->with(7)
        ->andReturn([]);

    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepository);

    $di = container();
    $di['em'] = $emMock;
    $di['events_manager'] = Mockery::mock('ClientFence\EventManager')->shouldIgnoreMissing();
    $di['mod'] = $di->protect(fn (string $name): object => new class {
        public function getConfig(): array
        {
            // An admin who enables the setting but leaves the days field
            // untouched submits it as an empty string, not an absent key.
            return ['batch_cancel_unpaid' => '1', 'batch_cancel_unpaid_after_days' => ''];
        }
    });

    $serviceMock->setDi($di);

    expect($serviceMock->batchCancelUnpaid())->toBeTrue();
});

test('batchCancelUnpaid removes the linked unpaid invoice before deleting the order', function (): void {
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();

    $order = createEntity(Order::class, ['id' => 1, 'unpaid_invoice_id' => 55, 'status' => Order::STATUS_PENDING_SETUP]);
    $invoiceModel = orderServiceCreateInvoiceModel(55);
    $invoiceModel->status = Model_Invoice::STATUS_UNPAID;

    $orderRepository = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepository->shouldReceive('getStaleUnpaid')
        ->once()
        ->andReturn([$order]);

    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepository);
    $emMock->shouldReceive('refresh')->once();

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('find')
        ->once()
        ->with('Invoice', 'id IN (:invoice_id_0)', ['invoice_id_0' => 55])
        ->andReturn([$invoiceModel]);

    $invoiceServiceMock = Mockery::mock(ClientFence\Mod\Invoice\Service::class);
    $invoiceServiceMock->shouldReceive('deleteInvoiceByAdmin')
        ->once()
        ->with($invoiceModel)
        ->andReturn(true);

    $di = container();
    $di['em'] = $emMock;
    $di['db'] = $dbMock;
    $di['events_manager'] = Mockery::mock('ClientFence\EventManager')->shouldIgnoreMissing();
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['mod'] = $di->protect(fn (string $name): object => new class {
        public function getConfig(): array
        {
            return ['batch_cancel_unpaid' => true, 'batch_cancel_unpaid_after_days' => 7];
        }
    });
    $di['mod_service'] = $di->protect(fn (string $name = ''): object => strtolower($name) === 'invoice' ? $invoiceServiceMock : Mockery::mock()->shouldIgnoreMissing());

    $serviceMock->shouldReceive('deleteFromOrder')->once()->with($order);

    $serviceMock->setDi($di);

    expect($serviceMock->batchCancelUnpaid())->toBeTrue();
});

test('batchCancelUnpaid resolves a shared invoice once and still removes every sibling order', function (): void {
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();

    // Two orders from the same cart checkout, both referencing the same invoice.
    $orderA = createEntity(Order::class, ['id' => 1, 'unpaid_invoice_id' => 55, 'status' => Order::STATUS_PENDING_SETUP]);
    $orderB = createEntity(Order::class, ['id' => 2, 'unpaid_invoice_id' => 55, 'status' => Order::STATUS_PENDING_SETUP]);
    $invoiceModel = orderServiceCreateInvoiceModel(55);
    $invoiceModel->status = Model_Invoice::STATUS_UNPAID;

    $orderRepository = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepository->shouldReceive('getStaleUnpaid')
        ->once()
        ->andReturn([$orderA, $orderB]);

    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepository);
    $emMock->shouldReceive('refresh')->twice();

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('find')
        ->once() // resolved once, not once per sibling order
        ->with('Invoice', 'id IN (:invoice_id_0)', ['invoice_id_0' => 55])
        ->andReturn([$invoiceModel]);

    $invoiceServiceMock = Mockery::mock(ClientFence\Mod\Invoice\Service::class);
    $invoiceServiceMock->shouldReceive('deleteInvoiceByAdmin')->once()->with($invoiceModel)->andReturn(true);

    $di = container();
    $di['em'] = $emMock;
    $di['db'] = $dbMock;
    $di['events_manager'] = Mockery::mock('ClientFence\EventManager')->shouldIgnoreMissing();
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['mod'] = $di->protect(fn (string $name): object => new class {
        public function getConfig(): array
        {
            return ['batch_cancel_unpaid' => true, 'batch_cancel_unpaid_after_days' => 7];
        }
    });
    $di['mod_service'] = $di->protect(fn (string $name = ''): object => strtolower($name) === 'invoice' ? $invoiceServiceMock : Mockery::mock()->shouldIgnoreMissing());

    $serviceMock->shouldReceive('deleteFromOrder')->once()->with($orderA);
    $serviceMock->shouldReceive('deleteFromOrder')->once()->with($orderB);

    $serviceMock->setDi($di);

    expect($serviceMock->batchCancelUnpaid())->toBeTrue();
});

test('batchCancelUnpaid does not delete a sibling order when removing the shared invoice fails', function (): void {
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();

    // Two orders from the same cart checkout, both referencing the same invoice.
    $orderA = createEntity(Order::class, ['id' => 1, 'unpaid_invoice_id' => 55, 'status' => Order::STATUS_PENDING_SETUP]);
    $orderB = createEntity(Order::class, ['id' => 2, 'unpaid_invoice_id' => 55, 'status' => Order::STATUS_PENDING_SETUP]);
    $invoiceModel = orderServiceCreateInvoiceModel(55);
    $invoiceModel->status = Model_Invoice::STATUS_UNPAID;

    $orderRepository = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepository->shouldReceive('getStaleUnpaid')
        ->once()
        ->andReturn([$orderA, $orderB]);

    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepository);
    $emMock->shouldReceive('refresh')->twice();

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('find')
        ->once()
        ->with('Invoice', 'id IN (:invoice_id_0)', ['invoice_id_0' => 55])
        ->andReturn([$invoiceModel]);

    $invoiceServiceMock = Mockery::mock(ClientFence\Mod\Invoice\Service::class);
    // The first attempt (while processing orderA) fails; the retry while
    // processing orderB is the second, successful attempt.
    $invoiceServiceMock->shouldReceive('deleteInvoiceByAdmin')
        ->once()
        ->with($invoiceModel)
        ->andThrow(new ClientFence\Exception('db went away'));
    $invoiceServiceMock->shouldReceive('deleteInvoiceByAdmin')
        ->once()
        ->with($invoiceModel)
        ->andReturn(true);

    $di = container();
    $di['em'] = $emMock;
    $di['db'] = $dbMock;
    $di['events_manager'] = Mockery::mock('ClientFence\EventManager')->shouldIgnoreMissing();
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['mod'] = $di->protect(fn (string $name): object => new class {
        public function getConfig(): array
        {
            return ['batch_cancel_unpaid' => true, 'batch_cancel_unpaid_after_days' => 7];
        }
    });
    $di['mod_service'] = $di->protect(fn (string $name = ''): object => strtolower($name) === 'invoice' ? $invoiceServiceMock : Mockery::mock()->shouldIgnoreMissing());

    // orderA is left alone this round since the invoice removal that would have
    // covered it failed; orderB retries the same invoice and, once it succeeds,
    // is removed.
    $serviceMock->shouldNotReceive('deleteFromOrder')->with($orderA);
    $serviceMock->shouldReceive('deleteFromOrder')->once()->with($orderB);

    $serviceMock->setDi($di);

    expect($serviceMock->batchCancelUnpaid())->toBeTrue();
});

test('batchCancelUnpaid leaves the order alone when its invoice was paid since selection', function (): void {
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();

    $order = createEntity(Order::class, ['id' => 1, 'unpaid_invoice_id' => 55, 'status' => Order::STATUS_PENDING_SETUP]);
    $invoiceModel = orderServiceCreateInvoiceModel(55);
    $invoiceModel->status = Model_Invoice::STATUS_PAID;

    $orderRepository = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepository->shouldReceive('getStaleUnpaid')
        ->once()
        ->andReturn([$order]);

    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepository);
    $emMock->shouldReceive('refresh')->once();

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('find')->once()->with('Invoice', 'id IN (:invoice_id_0)', ['invoice_id_0' => 55])->andReturn([$invoiceModel]);

    $invoiceServiceMock = Mockery::mock(ClientFence\Mod\Invoice\Service::class);
    $invoiceServiceMock->shouldNotReceive('deleteInvoiceByAdmin');

    $di = container();
    $di['em'] = $emMock;
    $di['db'] = $dbMock;
    $di['events_manager'] = Mockery::mock('ClientFence\EventManager')->shouldIgnoreMissing();
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['mod'] = $di->protect(fn (string $name): object => new class {
        public function getConfig(): array
        {
            return ['batch_cancel_unpaid' => true, 'batch_cancel_unpaid_after_days' => 7];
        }
    });
    $di['mod_service'] = $di->protect(fn (string $name = ''): object => strtolower($name) === 'invoice' ? $invoiceServiceMock : Mockery::mock()->shouldIgnoreMissing());

    $serviceMock->shouldNotReceive('deleteFromOrder');

    $serviceMock->setDi($di);

    expect($serviceMock->batchCancelUnpaid())->toBeTrue();
});

test('batchCancelUnpaid removes the order without touching the invoice when it was canceled rather than paid', function (): void {
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();

    // getStaleUnpaid() already includes this order because its invoice is no
    // longer a live unpaid one - it must actually be removed, not skipped
    // forever the way a still-unpaid-but-just-paid invoice would be.
    $order = createEntity(Order::class, ['id' => 1, 'unpaid_invoice_id' => 55, 'status' => Order::STATUS_PENDING_SETUP]);
    $invoiceModel = orderServiceCreateInvoiceModel(55);
    $invoiceModel->status = Model_Invoice::STATUS_CANCELED;

    $orderRepository = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepository->shouldReceive('getStaleUnpaid')
        ->once()
        ->andReturn([$order]);

    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepository);
    $emMock->shouldReceive('refresh')->once();

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('find')->once()->with('Invoice', 'id IN (:invoice_id_0)', ['invoice_id_0' => 55])->andReturn([$invoiceModel]);

    $invoiceServiceMock = Mockery::mock(ClientFence\Mod\Invoice\Service::class);
    $invoiceServiceMock->shouldNotReceive('deleteInvoiceByAdmin');

    $di = container();
    $di['em'] = $emMock;
    $di['db'] = $dbMock;
    $di['events_manager'] = Mockery::mock('ClientFence\EventManager')->shouldIgnoreMissing();
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['mod'] = $di->protect(fn (string $name): object => new class {
        public function getConfig(): array
        {
            return ['batch_cancel_unpaid' => true, 'batch_cancel_unpaid_after_days' => 7];
        }
    });
    $di['mod_service'] = $di->protect(fn (string $name = ''): object => strtolower($name) === 'invoice' ? $invoiceServiceMock : Mockery::mock()->shouldIgnoreMissing());

    $serviceMock->shouldReceive('deleteFromOrder')->once()->with($order);

    $serviceMock->setDi($di);

    expect($serviceMock->batchCancelUnpaid())->toBeTrue();
});

test('batchCancelUnpaid logs and continues when removing one stale order fails', function (): void {
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();

    $orderA = createEntity(Order::class, ['id' => 1, 'status' => Order::STATUS_PENDING_SETUP]);
    $orderB = createEntity(Order::class, ['id' => 2, 'status' => Order::STATUS_PENDING_SETUP]);

    $orderRepository = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepository->shouldReceive('getStaleUnpaid')
        ->once()
        ->andReturn([$orderA, $orderB]);

    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepository);
    $emMock->shouldReceive('refresh')->twice();

    $di = container();
    $di['em'] = $emMock;
    $di['events_manager'] = Mockery::mock('ClientFence\EventManager')->shouldIgnoreMissing();
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['mod'] = $di->protect(fn (string $name): object => new class {
        public function getConfig(): array
        {
            return ['batch_cancel_unpaid' => true, 'batch_cancel_unpaid_after_days' => 7];
        }
    });

    $serviceMock->shouldReceive('deleteFromOrder')
        ->once()
        ->with($orderA)
        ->andThrow(new ClientFence\Exception('boom'));
    $serviceMock->shouldReceive('deleteFromOrder')->once()->with($orderB);

    $serviceMock->setDi($di);

    expect($serviceMock->batchCancelUnpaid())->toBeTrue();
});

test('batchCancelUnpaid skips an order that changed status while the batch was running', function (): void {
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();

    $order = createEntity(Order::class, ['id' => 1, 'status' => Order::STATUS_PENDING_SETUP]);

    $orderRepository = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepository->shouldReceive('getStaleUnpaid')
        ->once()
        ->andReturn([$order]);

    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepository);
    // The refresh reveals the order was activated by something else (e.g. a
    // concurrent payment webhook) since getStaleUnpaid() selected it.
    $emMock->shouldReceive('refresh')
        ->once()
        ->with($order)
        ->andReturnUsing(function (Order $order): void {
            $order->setStatus(Order::STATUS_ACTIVE);
        });

    $di = container();
    $di['em'] = $emMock;
    $di['events_manager'] = Mockery::mock('ClientFence\EventManager')->shouldIgnoreMissing();
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['mod'] = $di->protect(fn (string $name): object => new class {
        public function getConfig(): array
        {
            return ['batch_cancel_unpaid' => true, 'batch_cancel_unpaid_after_days' => 7];
        }
    });

    $serviceMock->shouldNotReceive('deleteFromOrder');

    $serviceMock->setDi($di);

    expect($serviceMock->batchCancelUnpaid())->toBeTrue();
});

test('applyPaidRenewal runs the oldest waiting paid renewal line once, or reports none', function (): void {
    $dbal = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $dbal->executeStatement('CREATE TABLE invoice (id INTEGER PRIMARY KEY, status TEXT)');
    $dbal->executeStatement('CREATE TABLE invoice_item (id INTEGER PRIMARY KEY, invoice_id INTEGER, type TEXT, rel_id TEXT, task TEXT, status TEXT)');
    $dbal->insert('invoice', ['id' => 40, 'status' => 'paid']);
    $dbal->insert('invoice', ['id' => 41, 'status' => 'unpaid']);
    $dbal->insert('invoice_item', ['id' => 30, 'invoice_id' => 40, 'type' => 'order', 'rel_id' => '7', 'task' => 'renew', 'status' => 'failed']);
    $dbal->insert('invoice_item', ['id' => 31, 'invoice_id' => 41, 'type' => 'order', 'rel_id' => '8', 'task' => 'renew', 'status' => 'pending_payment']);
    $item = new Model_InvoiceItem();
    $item->loadBean(new Tests\Helpers\DummyBean());
    $item->attempts = 3;
    $outcome = Model_InvoiceItem::STATUS_FAILED;
    $itemService = Mockery::mock();
    $itemService->shouldReceive('executeTask')->twice()->with($item)->andReturnUsing(function (Model_InvoiceItem $i) use (&$outcome): void {
        expect($i->attempts)->toBe(0);
        $i->status = $outcome;
    });
    $db = Mockery::mock(\ClientFence\Database::class);
    $db->shouldReceive('getExistingModelById')->with('InvoiceItem', 30)->andReturn($item);
    $di = container();
    $di['dbal'] = $dbal;
    $di['db'] = $db;
    $di['mod_service'] = $di->protect(fn (): object => $itemService);
    $service = new Service();
    $service->setDi($di);

    expect(fn () => $service->applyPaidRenewal(7))->toThrow(ClientFence\InformationException::class, 'could not be applied');
    $outcome = Model_InvoiceItem::STATUS_EXECUTED;
    expect($service->applyPaidRenewal(7))->toBeTrue()
        ->and($service->applyPaidRenewal(8))->toBeFalse();
});

test('form answer updates retain service config and clear omitted choices', function (): void {
    $order = createEntity(Order::class, ['id' => 7, 'form_id' => 11, 'config' => json_encode([
        'hostname' => 'old', 'addons' => ['backup'], 'server_id' => 4, 'options' => [2 => 3], 'option_lines' => ['saved'],
    ])]);
    $formService = Mockery::mock(ClientFence\Mod\Formbuilder\Service::class);
    $formService->shouldReceive('getForm')->once()->with(11)->andReturn(['fields' => [
        ['name' => 'hostname', 'label' => 'Hostname', 'type' => 'text'],
        ['name' => 'addons', 'label' => 'Addons', 'type' => 'checkbox'],
    ]]);
    $di = container();
    $di['mod_service'] = $di->protect(fn () => $formService);
    $di['em'] = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class)->shouldIgnoreMissing();
    $service = new Service();
    $service->setDi($di);
    $service->updateOrderConfig($order, ['hostname' => 'new', 'options' => [], 'option_lines' => []]);
    expect(json_decode($order->getConfig(), true))->toEqual([
        'server_id' => 4, 'hostname' => 'new', 'options' => [2 => 3], 'option_lines' => ['saved'],
    ]);
});
