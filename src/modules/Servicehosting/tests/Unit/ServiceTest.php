<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use ClientFence\Mod\Servicehosting\Service;

use function Tests\Helpers\container;
use function Tests\Helpers\createEntity;
use function Tests\Helpers\moduleService;

/** ClientFence: a stand-in for the install's encryption (ServerSecrets.php). */
function hostingTestCrypt(): object
{
    return new class {
        public function encrypt(string $text): string
        {
            return 'enc(' . $text . ')';
        }

        public function decrypt(?string $text): string|false
        {
            // Like ClientFence\Crypt, the result is trimmed.
            return is_string($text) && str_starts_with($text, 'enc(') ? trim(substr($text, 4, -1)) : false;
        }
    };
}

test('batch enriches hosting accounts with orders and clients', function (): void {
    $service = new Service();
    $account = [
        'id' => 10,
        'sld' => 'example',
        'tld' => '.com',
        'client_id' => 7,
        'service_hosting_server_id' => 2,
        'service_hosting_hp_id' => 3,
        'reseller' => 0,
        'ip' => '192.0.2.10',
        'username' => 'example',
        'created_at' => '2026-07-19 10:00:00',
        'updated_at' => '2026-07-19 10:01:00',
    ];

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getAll')
        ->once()
        ->with(Mockery::pattern('/FROM client_order/'), ['hosting', 10])
        ->andReturn([['id' => 50, 'service_id' => 10]]);
    $dbMock->shouldNotReceive('findOne');
    $dbMock->shouldNotReceive('dispense');

    $orderService = Mockery::mock(ClientFence\Mod\Order\Service::class);
    $orderService->shouldReceive('getBatchForApi')
        ->once()
        ->with([50], Mockery::type(Model_Admin::class))
        ->andReturn([[
            'id' => 50,
            'service_id' => 10,
            'title' => 'Example hosting',
            'client' => ['id' => 7, 'email' => 'client@example.com'],
        ]]);

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(moduleService(['order' => $orderService]));
    $service->setDi($di);

    $admin = new Model_Admin();
    $admin->loadBean(new Tests\Helpers\DummyBean());
    $result = $service->getAccountsBatchForApi([$account], $admin);

    expect($result[0])
        ->toMatchArray([
            'id' => 10,
            'server_id' => 2,
            'plan_id' => 3,
            'client' => ['id' => 7, 'email' => 'client@example.com'],
            'order' => ['id' => 50, 'service_id' => 10, 'title' => 'Example hosting'],
        ])
        ->and($result[0]['order'])->not->toHaveKey('client');
});

test('batch returns hosting accounts without orders', function (): void {
    $service = new Service();
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getAll')->once()->andReturn([]);

    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    $result = $service->getAccountsBatchForApi([[
        'id' => 10,
        'sld' => 'example',
        'tld' => '.com',
        'client_id' => 7,
        'service_hosting_server_id' => 2,
        'service_hosting_hp_id' => 3,
        'reseller' => 0,
    ]]);

    expect($result[0]['order'])->toBeNull()
        ->and($result[0])->not->toHaveKey('client');
});

test('validate order data', function (string $field, string $exceptionMessage, int $excCode): void {
    $service = new Service();
    $data = [
        'server_id' => 1,
        'hosting_plan_id' => 2,
        'sld' => 'great',
        'tld' => 'com',
    ];

    unset($data[$field]);

    try {
        $service->validateOrderData($data);
        expect(true)->toBeFalse('Expected ClientFence\Exception was not thrown.');
    } catch (ClientFence\Exception $e) {
        expect($e->getMessage())->toBe($exceptionMessage);
        expect($e->getCode())->toBe($excCode);
    }
})->with([
    ['server_id', 'Hosting product is not configured completely. Configure server for hosting product.', 701],
    ['hosting_plan_id', 'Hosting product is not configured completely. Configure hosting plan for hosting product.', 702],
    ['sld', 'Domain name is invalid.', 703],
    ['tld', 'Domain extension is invalid.', 704],
]);

test('action create', function (): void {
    $service = new Service();
    $orderModel = new Model_ClientOrder();
    $orderModel->loadBean(new Tests\Helpers\DummyBean());
    $confArr = [
        'server_id' => 1,
        'hosting_plan_id' => 2,
        'sld' => 'great',
        'tld' => 'com',
    ];
    $orderServiceMock = Mockery::mock(ClientFence\Mod\Order\Service::class);
    $orderServiceMock->shouldReceive('getConfig')->atLeast()->once()->andReturn($confArr);

    $hostingServerModel = new Model_ServiceHostingServer();
    $hostingServerModel->loadBean(new Tests\Helpers\DummyBean());
    $hostingServerModel->id = $confArr['server_id'];
    $hostingPlansModel = new Model_ServiceHostingHp();
    $hostingPlansModel->loadBean(new Tests\Helpers\DummyBean());
    $hostingPlansModel->id = $confArr['hosting_plan_id'];
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getExistingModelById')->atLeast()->once()->andReturn($hostingServerModel, $hostingPlansModel);
    $dbMock->shouldReceive('getCell')->once()->andReturn(0);

    $servhostingModel = new Model_ServiceHosting();
    $servhostingModel->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('dispense')->atLeast()->once()->andReturn($servhostingModel);

    $newserviceHostingId = 4;
    $dbMock->shouldReceive('store')->atLeast()->once()->andReturn($newserviceHostingId);

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $orderServiceMock);

    $service->setDi($di);
    $service->action_create($orderModel);

    expect($servhostingModel->service_hosting_server_id)->toBe($confArr['server_id']);
    expect($servhostingModel->service_hosting_hp_id)->toBe($confArr['hosting_plan_id']);
    expect($servhostingModel->sld)->toBe($confArr['sld']);
    expect($servhostingModel->tld)->toBe($confArr['tld']);
});

test('action create refuses an inactive or full server', function (int $active, int $maxAccounts): void {
    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $server = new Model_ServiceHostingServer();
    $server->loadBean(new Tests\Helpers\DummyBean());
    $server->id = 1;
    $server->active = $active;
    $server->max_accounts = $maxAccounts;

    $orderService = Mockery::mock(ClientFence\Mod\Order\Service::class);
    $orderService->shouldReceive('getConfig')->once()->with($order)->andReturn(['server_id' => 1, 'hosting_plan_id' => 2, 'sld' => 'site', 'tld' => 'test']);
    $db = Mockery::mock(ClientFence\Database::class);
    $db->shouldReceive('getCell')->once()->andReturn(0);
    $db->shouldReceive('getExistingModelById')->once()->with('ServiceHostingServer', 1, Mockery::any())->andReturn($server);
    $db->shouldNotReceive('dispense');
    $dbal = Mockery::mock(Doctrine\DBAL\Connection::class);
    $dbal->shouldReceive('fetchOne')->times($active)->with("SELECT COUNT(*) FROM service_hosting sh JOIN client_order co ON co.service_type = 'hosting' AND co.service_id = sh.id WHERE sh.service_hosting_server_id = ? AND co.status <> 'canceled'", [1])->andReturn(2);

    $di = container();
    $di['db'] = $db;
    $di['dbal'] = $dbal;
    $di['mod_service'] = $di->protect(fn () => $orderService);
    $service = new Service();
    $service->setDi($di);

    expect(fn () => $service->action_create($order))->toThrow(ClientFence\InformationException::class, 'This hosting plan cannot be ordered right now. Please contact us.');
})->with([
    'inactive server' => [0, 0],
    'full server' => [1, 2],
]);

test('action renew', function (): void {
    $service = new Service();
    $orderModel = new Model_ClientOrder();
    $orderModel->loadBean(new Tests\Helpers\DummyBean());

    $hostingServiceModel = new Model_ServiceHosting();
    $hostingServiceModel->loadBean(new Tests\Helpers\DummyBean());
    // A provisioned account: it has its login on the server.
    $hostingServiceModel->username = 'client1';

    $orderServiceMock = Mockery::mock(ClientFence\Mod\Order\Service::class);
    $orderServiceMock->shouldReceive('getOrderService')->atLeast()->once()->andReturn($hostingServiceModel);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $orderServiceMock);

    $service->setDi($di);
    $result = $service->action_renew($orderModel);
    expect($result)->toBeTrue();
});

test('action renew refuses an account that was never created on the server', function (): void {
    // ClientFence: renewing it would mark the order active with nothing provisioned.
    $service = new Service();
    $orderModel = new Model_ClientOrder();
    $orderModel->loadBean(new Tests\Helpers\DummyBean());
    $orderModel->id = 7;

    $hostingServiceModel = new Model_ServiceHosting();
    $hostingServiceModel->loadBean(new Tests\Helpers\DummyBean());

    $orderServiceMock = Mockery::mock(ClientFence\Mod\Order\Service::class);
    $orderServiceMock->shouldReceive('getOrderService')->atLeast()->once()->andReturn($hostingServiceModel);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldNotReceive('store');

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $orderServiceMock);

    $service->setDi($di);
    expect(fn (): bool => $service->action_renew($orderModel))
        ->toThrow(ClientFence\Exception::class, 'Order 7 has no hosting account on the server yet. Activate the order first.');
});

test('action renew order without active service', function (): void {
    $service = new Service();
    $orderModel = new Model_ClientOrder();
    $orderModel->loadBean(new Tests\Helpers\DummyBean());
    $orderModel->id = 1;

    $orderServiceMock = Mockery::mock(ClientFence\Mod\Order\Service::class);
    $orderServiceMock->shouldReceive('getOrderService')->atLeast()->once()->andReturnNull();

    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $orderServiceMock);

    $service->setDi($di);
    expect(fn (): bool => $service->action_renew($orderModel))
        ->toThrow(ClientFence\Exception::class, sprintf('Order %d has no active service', $orderModel->id));
});

test('action activate stores the created panel subscription id', function (): void {
    $orderModel = new Model_ClientOrder();
    $orderModel->loadBean(new Tests\Helpers\DummyBean());

    $model = new Model_ServiceHosting();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->sld = 'example';
    $model->tld = '.com';

    $hostingServerModel = new Model_ServiceHostingServer();
    $hostingServerModel->loadBean(new Tests\Helpers\DummyBean());

    $orderServiceMock = Mockery::mock(ClientFence\Mod\Order\Service::class);
    $orderServiceMock->shouldReceive('getOrderService')->atLeast()->once()->andReturn($model);
    $orderServiceMock->shouldReceive('getConfig')->atLeast()->once()->andReturn([]);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getExistingModelById')->atLeast()->once()->with('ServiceHostingServer', Mockery::any(), Mockery::any())->andReturn($hostingServerModel);
    $dbMock->shouldReceive('store')->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $orderServiceMock);

    $serverManagerMock = Mockery::mock('\Server_Manager_Custom');
    $serverManagerMock->shouldReceive('getPasswordLength')->atLeast()->once()->andReturn(12);
    $serverManagerMock->shouldReceive('generateUsername')->atLeast()->once()->with('example.com')->andReturn('example');

    $adapterMock = Mockery::mock('\Server_Manager_Custom');
    $adapterMock->shouldReceive('createAccount')->once()->andReturnUsing(function (Server_Account $account): bool {
        $account->setPanelOrgId('B')->setPanelSubscriptionId(7);

        return true;
    });

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getServerManager')->atLeast()->once()->andReturn($serverManagerMock);
    $serviceMock->shouldReceive('_getAM')->once()->andReturn([$adapterMock, new Server_Account()]);
    $serviceMock->setDi($di);

    $result = $serviceMock->action_activate($orderModel);

    expect($result)->toBe(['username' => 'example'])
        ->and($model->username)->toBe('example')
        ->and($model->panel_subscription_id)->toBe(7)
        ->and($model->panel_org_id)->toBe('B');
});

test('action activate does not recreate an account that was already provisioned', function (): void {
    // Regression test: if a previous activation attempt already created the
    // account on the server (its username was persisted), retrying must not
    // call createAccount() again - the account already exists remotely and
    // doing so only fails with a duplicate-account server error.
    $orderModel = new Model_ClientOrder();
    $orderModel->loadBean(new Tests\Helpers\DummyBean());

    $model = new Model_ServiceHosting();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->sld = 'example';
    $model->tld = '.com';
    $model->username = 'example';

    $hostingServerModel = new Model_ServiceHostingServer();
    $hostingServerModel->loadBean(new Tests\Helpers\DummyBean());

    $orderServiceMock = Mockery::mock(ClientFence\Mod\Order\Service::class);
    $orderServiceMock->shouldReceive('getOrderService')->atLeast()->once()->andReturn($model);
    $orderServiceMock->shouldReceive('getConfig')->atLeast()->once()->andReturn([]);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getExistingModelById')->atLeast()->once()->with('ServiceHostingServer', Mockery::any(), Mockery::any())->andReturn($hostingServerModel);
    $dbMock->shouldReceive('store')->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $orderServiceMock);

    $serverManagerMock = Mockery::mock('\Server_Manager_Custom');
    $serverManagerMock->shouldReceive('getPasswordLength')->atLeast()->once()->andReturn(12);
    $serverManagerMock->shouldNotReceive('generateUsername');

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getServerManager')->atLeast()->once()->andReturn($serverManagerMock);
    $serviceMock->shouldNotReceive('_getAM');
    $serviceMock->setDi($di);

    $result = $serviceMock->action_activate($orderModel);

    expect($result)->toBe(['username' => 'example']);
});

test('action suspend', function (): void {
    $service = new Service();
    $orderModel = new Model_ClientOrder();
    $orderModel->loadBean(new Tests\Helpers\DummyBean());

    $model = new Model_ServiceHosting();
    $model->loadBean(new Tests\Helpers\DummyBean());

    $orderServiceMock = Mockery::mock(ClientFence\Mod\Order\Service::class);
    $orderServiceMock->shouldReceive('getOrderService')->atLeast()->once()->andReturn($model);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $orderServiceMock);

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serverManagerMock = Mockery::mock('\Server_Manager_Custom');
    $serverManagerMock->shouldReceive('suspendAccount')->atLeast()->once();
    $AMresultArray = [$serverManagerMock, new Server_Account()];
    $serviceMock->shouldReceive('_getAM')->atLeast()->once()->andReturn($AMresultArray);

    $serviceMock->setDi($di);
    $result = $serviceMock->action_suspend($orderModel);
    expect($result)->toBeTrue();
});

test('action suspend order without active service', function (): void {
    $service = new Service();
    $orderModel = new Model_ClientOrder();
    $orderModel->loadBean(new Tests\Helpers\DummyBean());
    $orderModel->id = 1;

    $orderServiceMock = Mockery::mock(ClientFence\Mod\Order\Service::class);
    $orderServiceMock->shouldReceive('getOrderService')->atLeast()->once()->andReturnNull();

    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $orderServiceMock);

    $service->setDi($di);
    expect(fn (): bool => $service->action_suspend($orderModel))
        ->toThrow(ClientFence\Exception::class, sprintf('Order %d has no active service', $orderModel->id));
});

test('action unsuspend', function (): void {
    $service = new Service();
    $orderModel = new Model_ClientOrder();
    $orderModel->loadBean(new Tests\Helpers\DummyBean());

    $model = new Model_ServiceHosting();
    $model->loadBean(new Tests\Helpers\DummyBean());

    $orderServiceMock = Mockery::mock(ClientFence\Mod\Order\Service::class);
    $orderServiceMock->shouldReceive('getOrderService')->atLeast()->once()->andReturn($model);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $orderServiceMock);

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serverManagerMock = Mockery::mock('\Server_Manager_Custom');
    $serverManagerMock->shouldReceive('unsuspendAccount')->atLeast()->once();
    $AMresultArray = [$serverManagerMock, new Server_Account()];
    $serviceMock->shouldReceive('_getAM')->atLeast()->once()->andReturn($AMresultArray);

    $serviceMock->setDi($di);
    $result = $serviceMock->action_unsuspend($orderModel);
    expect($result)->toBeTrue();
});

test('action unsuspend order without active service', function (): void {
    $service = new Service();
    $orderModel = new Model_ClientOrder();
    $orderModel->loadBean(new Tests\Helpers\DummyBean());
    $orderModel->id = 1;

    $orderServiceMock = Mockery::mock(ClientFence\Mod\Order\Service::class);
    $orderServiceMock->shouldReceive('getOrderService')->atLeast()->once()->andReturnNull();

    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $orderServiceMock);

    $service->setDi($di);
    expect(fn (): bool => $service->action_unsuspend($orderModel))
        ->toThrow(ClientFence\Exception::class, sprintf('Order %d has no active service', $orderModel->id));
});

test('action cancel', function (): void {
    $service = new Service();
    $orderModel = new Model_ClientOrder();
    $orderModel->loadBean(new Tests\Helpers\DummyBean());

    $model = new Model_ServiceHosting();
    $model->loadBean(new Tests\Helpers\DummyBean());

    $orderServiceMock = Mockery::mock(ClientFence\Mod\Order\Service::class);
    $orderServiceMock->shouldReceive('getOrderService')->atLeast()->once()->andReturn($model);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $orderServiceMock);

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serverManagerMock = Mockery::mock('\Server_Manager_Custom');
    $serverManagerMock->shouldReceive('cancelAccount')->atLeast()->once();
    $AMresultArray = [$serverManagerMock, new Server_Account()];
    $serviceMock->shouldReceive('_getAM')->atLeast()->once()->andReturn($AMresultArray);

    $serviceMock->setDi($di);
    $result = $serviceMock->action_cancel($orderModel);
    expect($result)->toBeTrue();
});

test('action cancel order without active service', function (): void {
    $service = new Service();
    $orderModel = new Model_ClientOrder();
    $orderModel->loadBean(new Tests\Helpers\DummyBean());
    $orderModel->id = 1;

    $orderServiceMock = Mockery::mock(ClientFence\Mod\Order\Service::class);
    $orderServiceMock->shouldReceive('getOrderService')->atLeast()->once()->andReturnNull();

    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $orderServiceMock);

    $service->setDi($di);
    expect(fn (): bool => $service->action_cancel($orderModel))
        ->toThrow(ClientFence\Exception::class, sprintf('Order %d has no active service', $orderModel->id));
});

test('action delete', function (): void {
    $service = new Service();
    $orderModel = new Model_ClientOrder();
    $orderModel->loadBean(new Tests\Helpers\DummyBean());
    $orderModel->status = 'active';

    $model = new Model_ServiceHosting();
    $model->loadBean(new Tests\Helpers\DummyBean());

    $orderServiceMock = Mockery::mock(ClientFence\Mod\Order\Service::class);
    $orderServiceMock->shouldReceive('getOrderService')->atLeast()->once()->andReturn($model);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('trash')->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $orderServiceMock);

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldReceive('action_cancel')->atLeast()->once();

    $serviceMock->setDi($di);
    $serviceMock->action_delete($orderModel);
});

test('change account plan', function (): void {
    $service = new Service();
    $orderModel = new Model_ClientOrder();
    $orderModel->loadBean(new Tests\Helpers\DummyBean());

    $model = new Model_ServiceHosting();
    $model->loadBean(new Tests\Helpers\DummyBean());

    $modelHp = new Model_ServiceHostingHp();
    $modelHp->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new \ClientFence\Log();

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serverManagerMock = Mockery::mock('\Server_Manager_Custom');
    $serverManagerMock->shouldReceive('changeAccountPackage')->atLeast()->once();
    $AMresultArray = [$serverManagerMock, new Server_Account()];
    $serviceMock->shouldReceive('_getAM')->atLeast()->once()->andReturn($AMresultArray);
    $serviceMock->shouldReceive('getServerPackage')->atLeast()->once()->andReturn(new Server_Package());

    $serviceMock->setDi($di);
    $result = $serviceMock->changeAccountPlan($orderModel, $model, $modelHp);
    expect($result)->toBeTrue();
});

test('change account plan reaches the panel of suspended accounts and saves pending setup plans', function (string $status, bool $onPanel): void {
    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->status = $status;
    $model = new Model_ServiceHosting();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->service_hosting_hp_id = 7;
    $hp = new Model_ServiceHostingHp();
    $hp->loadBean(new Tests\Helpers\DummyBean());
    $hp->id = 8;
    $db = Mockery::mock(ClientFence\Database::class);
    $db->shouldReceive('store')->once()->with($model);
    $di = container();
    $di['db'] = $db;
    $di['logger'] = new ClientFence\Log();
    $service = Mockery::mock(Service::class)->makePartial();
    $manager = Mockery::mock('\Server_Manager_Custom');
    $manager->shouldReceive('changeAccountPackage')->times($onPanel ? 1 : 0);
    $service->shouldReceive('_getAM')->times($onPanel ? 1 : 0)->andReturn([$manager, new Server_Account()]);
    $service->shouldReceive('getServerPackage')->times($onPanel ? 1 : 0)->andReturn(new Server_Package());
    $service->setDi($di);

    expect($service->changeAccountPlan($order, $model, $hp))->toBeTrue()
        ->and($model->service_hosting_hp_id)->toBe(8);
})->with([
    'suspended' => [Model_ClientOrder::STATUS_SUSPENDED, true],
    'pending setup' => [Model_ClientOrder::STATUS_PENDING_SETUP, false],
]);

test('change account username', function (): void {
    $service = new Service();
    $data = [
        'username' => 'u123456',
    ];

    $orderModel = new Model_ClientOrder();
    $orderModel->loadBean(new Tests\Helpers\DummyBean());

    $model = new Model_ServiceHosting();
    $model->loadBean(new Tests\Helpers\DummyBean());

    $serviceMock = Mockery::mock(Service::class)->makePartial();

    $serverManagerMock = Mockery::mock('\Server_Manager_Custom');
    $serverManagerMock->shouldReceive('changeAccountUsername')->atLeast()->once();

    $AMresultArray = [$serverManagerMock, new Server_Account()];
    $serviceMock->shouldReceive('_getAM')->atLeast()->once()->andReturn($AMresultArray);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new \ClientFence\Log();

    $serviceMock->setDi($di);

    $result = $serviceMock->changeAccountUsername($orderModel, $model, $data);
    expect($result)->toBeTrue();
});

test('change account username missing username', function (): void {
    $service = new Service();
    $orderModel = new Model_ClientOrder();
    $orderModel->loadBean(new Tests\Helpers\DummyBean());

    $model = new Model_ServiceHosting();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $data = [];

    expect(fn (): bool => $service->changeAccountUsername($orderModel, $model, $data))
        ->toThrow(ClientFence\Exception::class, 'Account username is missing or is invalid');
});

test('change account ip', function (): void {
    $service = new Service();
    $data = [
        'ip' => '1.1.1.1',
    ];

    $orderModel = new Model_ClientOrder();
    $orderModel->loadBean(new Tests\Helpers\DummyBean());

    $model = new Model_ServiceHosting();
    $model->loadBean(new Tests\Helpers\DummyBean());

    $serviceMock = Mockery::mock(Service::class)->makePartial();

    $serverManagerMock = Mockery::mock('\Server_Manager_Custom');
    $serverManagerMock->shouldReceive('changeAccountIp')->atLeast()->once();

    $AMresultArray = [$serverManagerMock, new Server_Account()];
    $serviceMock->shouldReceive('_getAM')->atLeast()->once()->andReturn($AMresultArray);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new \ClientFence\Log();

    $serviceMock->setDi($di);

    $result = $serviceMock->changeAccountIp($orderModel, $model, $data);
    expect($result)->toBeTrue();
});

test('change account ip missing ip', function (): void {
    $service = new Service();
    $data = [];
    $orderModel = new Model_ClientOrder();
    $orderModel->loadBean(new Tests\Helpers\DummyBean());

    $model = new Model_ServiceHosting();
    $model->loadBean(new Tests\Helpers\DummyBean());

    expect(fn (): bool => $service->changeAccountIp($orderModel, $model, $data))
        ->toThrow(ClientFence\Exception::class, 'Account IP address is missing or is invalid');
});

test('change account domain', function (): void {
    $service = new Service();
    $data = [
        'tld' => 'com',
        'sld' => 'testingSld',
    ];

    $orderModel = new Model_ClientOrder();
    $orderModel->loadBean(new Tests\Helpers\DummyBean());

    $model = new Model_ServiceHosting();
    $model->loadBean(new Tests\Helpers\DummyBean());

    $serviceMock = Mockery::mock(Service::class)->makePartial();

    $serverManagerMock = Mockery::mock('\Server_Manager_Custom');
    $serverManagerMock->shouldReceive('changeAccountDomain')->atLeast()->once();

    $AMresultArray = [$serverManagerMock, new Server_Account()];
    $serviceMock->shouldReceive('_getAM')->atLeast()->once()->andReturn($AMresultArray);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new \ClientFence\Log();

    $serviceMock->setDi($di);

    $result = $serviceMock->changeAccountDomain($orderModel, $model, $data);
    expect($result)->toBeTrue();
});

test('change account domain missing params', function (): void {
    $service = new Service();
    $data = [];
    $orderModel = new Model_ClientOrder();
    $orderModel->loadBean(new Tests\Helpers\DummyBean());

    $model = new Model_ServiceHosting();
    $model->loadBean(new Tests\Helpers\DummyBean());

    expect(fn (): bool => $service->changeAccountDomain($orderModel, $model, $data))
        ->toThrow(ClientFence\Exception::class, 'Domain SLD or TLD is missing');
});

test('change account password', function (): void {
    $service = new Service();
    $data = [
        'password' => 'topsecret',
        'password_confirm' => 'topsecret',
    ];

    $orderModel = new Model_ClientOrder();
    $orderModel->loadBean(new Tests\Helpers\DummyBean());

    $model = new Model_ServiceHosting();
    $model->loadBean(new Tests\Helpers\DummyBean());

    $serviceMock = Mockery::mock(Service::class)->makePartial();

    $serverManagerMock = Mockery::mock('\Server_Manager_Custom');
    $serverManagerMock->shouldReceive('changeAccountPassword')->atLeast()->once();

    $AMresultArray = [$serverManagerMock, new Server_Account()];
    $serviceMock->shouldReceive('_getAM')->atLeast()->once()->andReturn($AMresultArray);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new \ClientFence\Log();

    $serviceMock->setDi($di);

    $result = $serviceMock->changeAccountPassword($orderModel, $model, $data);
    expect($result)->toBeTrue();
});

test('change account password missing params', function (): void {
    $service = new Service();
    $data = [];
    $orderModel = new Model_ClientOrder();
    $orderModel->loadBean(new Tests\Helpers\DummyBean());

    $model = new Model_ServiceHosting();
    $model->loadBean(new Tests\Helpers\DummyBean());

    expect(fn (): bool => $service->changeAccountPassword($orderModel, $model, $data))
        ->toThrow(ClientFence\Exception::class, 'Account password is missing or is invalid');
});

test('sync', function (): void {
    $service = new Service();
    $data = [
        'password' => 'topsecret',
        'password_confirm' => 'topsecret',
    ];

    $orderModel = new Model_ClientOrder();
    $orderModel->loadBean(new Tests\Helpers\DummyBean());

    $model = new Model_ServiceHosting();
    $model->loadBean(new Tests\Helpers\DummyBean());

    $serviceMock = Mockery::mock(Service::class)->makePartial();

    $accountObj = new Server_Account();
    $accountObj->setUsername('testUser1');
    $accountObj->setIp('1.1.1.1');

    $accountObj2 = new Server_Account();
    $accountObj2->setUsername('testUser2');
    $accountObj2->setIp('2.2.2.2');

    $serverManagerMock = Mockery::mock('\Server_Manager_Custom');
    $serverManagerMock->shouldReceive('synchronizeAccount')->atLeast()->once()->andReturn($accountObj2);

    $AMresultArray = [$serverManagerMock, $accountObj];
    $serviceMock->shouldReceive('_getAM')->atLeast()->once()->andReturn($AMresultArray);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new \ClientFence\Log();

    $serviceMock->setDi($di);

    $result = $serviceMock->sync($orderModel, $model, $data);
    expect($result)->toBeTrue();
});

test('to api array', function (): void {
    $service = new Service();
    $model = new Model_ServiceHosting();
    $model->loadBean(new Tests\Helpers\DummyBean());

    $hostingServer = new Model_ServiceHostingServer();
    $hostingServer->loadBean(new Tests\Helpers\DummyBean());
    $hostingServer->manager = 'Custom';
    $hostingHp = new Model_ServiceHostingHp();
    $hostingHp->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('load')->atLeast()->once()->andReturn($hostingServer, $hostingHp);

    $orderServiceMock = Mockery::mock(ClientFence\Mod\Order\Service::class);
    $orderServiceMock->shouldReceive('getServiceOrder')->atLeast()->once();

    $serverManagerCustomMock = Mockery::mock('\Server_Manager_Custom')->shouldIgnoreMissing();

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $orderServiceMock);
    $di['server_manager'] = $di->protect(fn ($manager, $config) => $serverManagerCustomMock);

    $service->setDi($di);

    $result = $service->toApiArray($model, false, new Model_Admin());
    expect($result)->toBeArray();
});

test('update', function (): void {
    $service = new Service();
    $data = [
        'username' => 'testUser',
        'ip' => '1.1.1.1',
    ];
    $model = new Model_ServiceHosting();
    $model->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new \ClientFence\Log();
    $service->setDi($di);

    $result = $service->update($model, $data);
    expect($result)->toBeTrue();
});

test('get server managers', function (): void {
    $service = new Service();
    $result = $service->getServerManagers();
    expect($result)->toBeArray();
});

test('get server manager config', function (): void {
    $service = new Service();
    $manager = 'Custom';

    $expected = [
        'label' => 'Custom Server Manager',
    ];

    $result = $service->getServerManagerConfig($manager);
    expect($result)->toBeArray();
    expect($result)->toEqual($expected);
});

test('get server pairs', function (): void {
    $service = new Service();
    $expected = [
        '1' => 'name',
        '2' => 'ding',
    ];

    $queryResult = [
        [
            'id' => 1,
            'name' => 'name',
        ], [
            'id' => 2,
            'name' => 'ding',
        ],
    ];

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getAll')->atLeast()->once()->andReturn($queryResult);

    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    $result = $service->getServerPairs();
    expect($result)->toBeArray();
    expect($result)->toEqual($expected);
});

test('get server search query', function (): void {
    $service = new Service();
    $result = $service->getServersSearchQuery([]);
    expect($result[0])->toBeString();
    expect($result[1])->toBeArray();
    expect($result[1])->toEqual([]);
});

test('create server', function (): void {
    $service = new Service();
    $dbMock = Mockery::mock('ClientFence\Database');

    $hostingServerModel = new Model_ServiceHostingServer();
    $hostingServerModel->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('dispense')->atLeast()->once()->andReturn($hostingServerModel);

    $newId = 1;
    $dbMock->shouldReceive('store')->atLeast()->once()->andReturn($newId);

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new \ClientFence\Log();

    $service->setDi($di);

    $name = 'newSuperFastServer';
    $ip = '1.1.1.1';
    $manager = 'Custom';
    $data = [];
    $result = $service->createServer($name, $ip, $manager, $data);
    expect($result)->toBeInt();
    expect($result)->toBe($newId);
});

test('delete server', function (): void {
    $service = new Service();
    $hostingServerModel = new Model_ServiceHostingServer();
    $hostingServerModel->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('trash')->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new \ClientFence\Log();
    $service->setDi($di);

    $result = $service->deleteServer($hostingServerModel);
    expect($result)->toBeTrue();
});

test('update server', function (): void {
    $service = new Service();
    $data = [
        'name' => 'newName',
        'ip' => '1.1.1.1',
        'hostname' => 'unknownStar',
        'active' => 1,
        'status_url' => 'na',
        'ns1' => 'ns1.testserver.eu',
        'ns2' => 'ns2.testserver.eu',
        'ns3' => 'ns3.testserver.eu',
        'ns4' => 'ns4.testserver.eu',
        'manager' => 'Custom',
        'username' => 'testingJohn',
        'password' => 'hardToGuess',
        'accesshash' => 'secret',
        'port' => '23',
        'secure' => 0,
    ];

    $hostingServerModel = new Model_ServiceHostingServer();
    $hostingServerModel->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new \ClientFence\Log();
    $di['crypt'] = hostingTestCrypt();

    $service->setDi($di);

    $result = $service->updateServer($hostingServerModel, $data);
    expect($result)->toBeTrue()
        // ClientFence: the password and API key are stored encrypted (ServerSecrets.php).
        ->and($hostingServerModel->password)->toBe('cfsecret:enc(' . base64_encode('hardToGuess') . ')')
        ->and($hostingServerModel->accesshash)->toBe('cfsecret:enc(' . base64_encode('secret') . ')')
        ->and($hostingServerModel->username)->toBe('testingJohn');
});

test('get server manager', function (): void {
    $service = new Service();
    $hostingServerModel = new Model_ServiceHostingServer();
    $hostingServerModel->loadBean(new Tests\Helpers\DummyBean());
    $hostingServerModel->manager = 'Custom';

    $serverManagerCustom = Mockery::mock('\Server_Manager_Custom')->shouldIgnoreMissing();

    $di = container();
    $di['server_manager'] = $di->protect(fn ($manager, $config) => $serverManagerCustom);
    $service->setDi($di);

    $result = $service->getServerManager($hostingServerModel);
    expect($result)->toBeInstanceOf('\Server_Manager_Custom');
});

test('get server manager manager not defined', function (): void {
    $service = new Service();
    $hostingServerModel = new Model_ServiceHostingServer();
    $hostingServerModel->loadBean(new Tests\Helpers\DummyBean());

    expect(fn () => $service->getServerManager($hostingServerModel))
        ->toThrow(ClientFence\Exception::class);
});

test('get server manager server manager invalid', function (): void {
    $service = new Service();
    $hostingServerModel = new Model_ServiceHostingServer();
    $hostingServerModel->loadBean(new Tests\Helpers\DummyBean());
    $hostingServerModel->manager = 'Custom';

    $di = container();
    $di['server_manager'] = $di->protect(fn ($manager, $config): null => null);
    $service->setDi($di);

    expect(fn () => $service->getServerManager($hostingServerModel))
        ->toThrow(ClientFence\Exception::class, "Server manager {$hostingServerModel->manager} is invalid.");
});

test('test connection', function (): void {
    $serverManagerMock = Mockery::mock('\Server_Manager_Custom');
    $serverManagerMock->shouldReceive('testConnection')->atLeast()->once()->andReturn(true);

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldReceive('getServerManager')->atLeast()->once()->andReturn($serverManagerMock);

    $hostingServerModel = new Model_ServiceHostingServer();
    $result = $serviceMock->testConnection($hostingServerModel);
    expect($result)->toBeBool();
    expect($result)->toBeTrue();
});

test('get hp pairs', function (): void {
    $service = new Service();
    $expected = [
        '1' => 'free',
        '2' => 'paid',
    ];

    $queryResult = [
        [
            'id' => 1,
            'name' => 'free',
        ], [
            'id' => 2,
            'name' => 'paid',
        ],
    ];

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getAll')->atLeast()->once()->andReturn($queryResult);

    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    $result = $service->getHpPairs();
    expect($result)->toBeArray();
    expect($result)->toEqual($expected);
});

test('get hp search query', function (): void {
    $service = new Service();
    $result = $service->getHpSearchQuery([]);
    expect($result[0])->toBeString();
    expect($result[1])->toBeArray();
    expect($result[1])->toEqual([]);
});

test('delete hp', function (): void {
    $service = new Service();
    $model = new Model_ServiceHostingHp();
    $model->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('trash')->atLeast()->once();
    $dbMock->shouldReceive('getAll')->atLeast()->once()->andReturn([]);

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new \ClientFence\Log();
    $service->setDi($di);

    $result = $service->deleteHp($model);
    expect($result)->toBeTrue();
});

test('delete hp rejects orphaned accounts', function (): void {
    $service = new Service();
    $model = new Model_ServiceHostingHp();
    $model->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getAll')->atLeast()->once()->andReturn([['id' => 11]]);
    $dbMock->shouldNotReceive('trash');

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new \ClientFence\Log();
    $service->setDi($di);

    expect(fn (): bool => $service->deleteHp($model))
        ->toThrow(ClientFence\InformationException::class, 'Cannot remove hosting plan which has orphaned accounts; detach them first');
});

test('server usage stats split active from orphaned usages', function (): void {
    $service = new Service();
    $server = new Model_ServiceHostingServer();
    $server->loadBean(new Tests\Helpers\DummyBean());
    $server->id = 1;

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getAll')
        ->once()
        ->with('SELECT id FROM service_hosting WHERE service_hosting_server_id = ?', [1])
        ->andReturn([['id' => 11], ['id' => 12]]);
    $dbMock->shouldReceive('getAll')
        ->once()
        ->withArgs(function (string $sql, array $values): bool {
            return str_contains($sql, 'client_order')
                && $values === [ClientFence\Mod\Product\Service::HOSTING, 11, 12];
        })
        ->andReturn([['service_id' => 11]]);

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new \ClientFence\Log();
    $service->setDi($di);

    expect($service->getServerUsageStats($server))->toBe([
        'total' => 2,
        'active' => 1,
        'orphaned' => 1,
        'orphanedIds' => [12],
    ]);
});

test('detach orphaned server usages nulls the server link', function (): void {
    $service = new Service();
    $server = new Model_ServiceHostingServer();
    $server->loadBean(new Tests\Helpers\DummyBean());
    $server->id = 1;

    $orphan = new Model_ServiceHosting();
    $orphan->loadBean(new Tests\Helpers\DummyBean());
    $orphan->id = 12;
    $orphan->service_hosting_server_id = 1;

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getAll')
        ->once()
        ->with('SELECT id FROM service_hosting WHERE service_hosting_server_id = ?', [1])
        ->andReturn([['id' => 12]]);
    $dbMock->shouldReceive('getAll')
        ->once()
        ->withArgs(function (string $sql, array $values): bool {
            return str_contains($sql, 'client_order')
                && $values === [ClientFence\Mod\Product\Service::HOSTING, 12];
        })
        ->andReturn([]);
    $dbMock->shouldReceive('load')->once()->with('ServiceHosting', 12)->andReturn($orphan);
    $dbMock->shouldReceive('store')->once()->with($orphan);

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new \ClientFence\Log();
    $service->setDi($di);

    expect($service->detachOrphanedServerUsages($server))->toBe(1);
    expect($orphan->service_hosting_server_id)->toBeNull();
});

test('to hosting hp api array', function (): void {
    $service = new Service();
    $model = new Model_ServiceHostingHp();
    $model->loadBean(new Tests\Helpers\DummyBean());

    $result = $service->toHostingHpApiArray($model);
    expect($result)->toBeArray();
});

test('update hp', function (): void {
    $service = new Service();
    $data = [
        'name' => 'firstPlan',
        'bandwidth' => '100000',
        'quota' => '1000',
        'max_addon' => '0',
        'max_ft' => '1',
        'max_sql' => '2',
        'max_pop' => '1',
        'max_sub' => '2',
        'max_park' => '1',
    ];

    $model = new Model_ServiceHostingHp();
    $model->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new \ClientFence\Log();

    $service->setDi($di);

    $result = $service->updateHp($model, $data);
    expect($result)->toBeTrue();
});

test('create hp', function (): void {
    $service = new Service();
    $model = new Model_ServiceHostingHp();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $newId = 1;

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('dispense')->atLeast()->once()->andReturn($model);
    $dbMock->shouldReceive('store')->atLeast()->once()->andReturn($newId);

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new \ClientFence\Log();

    $service->setDi($di);

    $result = $service->createHp('Free Plan', []);
    expect($result)->toBeInt();
    expect($result)->toBe($newId);
});

test('get server package', function (): void {
    $service = new Service();
    $model = new Model_ServiceHostingHp();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->config = '{}';

    $di = container();

    $service->setDi($di);
    $result = $service->getServerPackage($model);
    expect($result)->toBeInstanceOf('\Server_Package');
});

test('get server manager with log', function (): void {
    $hostingServerModel = new Model_ServiceHostingServer();
    $hostingServerModel->loadBean(new Tests\Helpers\DummyBean());
    $hostingServerModel->manager = 'Custom';

    $clientOrderModel = new Model_ClientOrder();
    $clientOrderModel->loadBean(new Tests\Helpers\DummyBean());

    $serverManagerMock = Mockery::mock('\Server_Manager_Custom')->shouldIgnoreMissing();
    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldReceive('getServerManager')->atLeast()->once()->andReturn($serverManagerMock);

    $orderServiceMock = Mockery::mock(ClientFence\Mod\Order\Service::class);
    $orderServiceMock->shouldReceive('getLogger')->atLeast()->once()->andReturn(new \ClientFence\Log());

    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $orderServiceMock);

    $serviceMock->setDi($di);
    $result = $serviceMock->getServerManagerWithLog($hostingServerModel, $clientOrderModel);
    expect($result)->toBeInstanceOf('\Server_Manager_Custom');
});

test('get manager urls', function (): void {
    $hostingServerModel = new Model_ServiceHostingServer();
    $hostingServerModel->loadBean(new Tests\Helpers\DummyBean());
    $hostingServerModel->manager = 'Custom';

    $serverManagerMock = Mockery::mock('\Server_Manager_Custom');
    $serverManagerMock->shouldReceive('getLoginUrl')->atLeast()->once()->andReturn('/login');
    $serverManagerMock->shouldReceive('getResellerLoginUrl')->atLeast()->once()->andReturn('/admin/login');

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldReceive('getServerManager')->atLeast()->once()->andReturn($serverManagerMock);

    $result = $serviceMock->getManagerUrls($hostingServerModel);
    expect($result)->toBeArray();
    expect($result[0])->toBeString();
    expect($result[1])->toBeString();
});

test('get manager urls exception', function (): void {
    $hostingServerModel = new Model_ServiceHostingServer();
    $hostingServerModel->loadBean(new Tests\Helpers\DummyBean());
    $hostingServerModel->manager = 'Custom';

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldReceive('getServerManager')->atLeast()->once()->andThrow(new Exception('Controlled unit test exception'));
    $serviceMock->setDi(container());

    $result = $serviceMock->getManagerUrls($hostingServerModel);
    expect($result)->toBeArray();
    expect($result[0])->toBeFalse();
    expect($result[1])->toBeFalse();
});

test('get free tlds free tlds are not set', function (): void {
    $service = new Service();
    $di = container();

    $tldArray = ['tld' => '.com'];
    $serviceDomainServiceMock = Mockery::mock(ClientFence\Mod\Servicedomain\Service::class);
    $serviceDomainServiceMock->shouldReceive('tldToApiArray')->atLeast()->once()->andReturn($tldArray);
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $serviceDomainServiceMock);

    $tldModel = new Model_Tld();
    $tldModel->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('find')->atLeast()->once()->andReturn([$tldModel]);
    $di['db'] = $dbMock;

    $service->setDi($di);
    $product = new ClientFence\Mod\Product\Entity\Product();
    $result = $service->getFreeTlds($product);
    expect($result)->toBeArray();
});

test('get free tlds', function (): void {
    $service = new Service();
    $config = [
        'free_tlds' => ['.com'],
    ];
    $di = container();

    $service->setDi($di);
    $product = new ClientFence\Mod\Product\Entity\Product();
    $product->setConfig(json_encode($config));

    $result = $service->getFreeTlds($product);
    expect($result)->toBeArray();
    expect($result)->not->toBeEmpty();
});

test('get server manager secret fields', function (string $manager, array $expected): void {
    $service = new Service();
    $di = container();
    $service->setDi($di);

    $result = $service->getServerManagerSecretFields($manager);

    sort($expected);
    $sorted = $result;
    sort($sorted);

    expect($sorted)->toBe($expected);
})->with([
    'WHM' => ['Whm', ['username', 'accesshash', 'password']],
    'Hestia' => ['Hestia', ['username', 'accesshash', 'password']],
    'CWP' => ['CWP', ['accesshash', 'password']],
    'DirectAdmin' => ['Directadmin', ['username', 'password', 'accesshash']],
    'Plesk' => ['Plesk', ['username', 'password', 'accesshash']],
    'unknown manager' => ['DoesNotExist', ['password', 'accesshash']],
]);

test('to hosting server api array masks secrets for an admin', function (): void {
    $service = new Service();

    $identity = new Model_Admin();
    $identity->loadBean(new Tests\Helpers\DummyBean());

    $hostingServerModel = new Model_ServiceHostingServer();
    $hostingServerModel->loadBean(new Tests\Helpers\DummyBean());
    $hostingServerModel->id = 1;
    $hostingServerModel->name = 'Test';
    $hostingServerModel->hostname = 'host.example.com';
    $hostingServerModel->ip = '127.0.0.1';
    $hostingServerModel->manager = 'Whm';
    $hostingServerModel->username = 'real-admin';
    $hostingServerModel->accesshash = 'super-secret-hash';

    $di = container();
    $service->setDi($di);

    $result = $service->toHostingServerApiArray($hostingServerModel, true, $identity);

    expect($result['username'])->toBeNull();
    expect($result['accesshash'])->toBeNull();
    expect($result['password'])->toBeNull();
    expect($result['username_set'])->toBeTrue();
    expect($result['accesshash_set'])->toBeTrue();
    expect($result['password_set'])->toBeFalse();
    expect($result['secret_fields'])->toContain('username');
    expect($result['secret_fields'])->toContain('accesshash');
});

test('to hosting server api array does not leak secrets to non-admin callers', function (): void {
    $service = new Service();

    $identity = new Model_Client();
    $identity->loadBean(new Tests\Helpers\DummyBean());

    $hostingServerModel = new Model_ServiceHostingServer();
    $hostingServerModel->loadBean(new Tests\Helpers\DummyBean());
    $hostingServerModel->id = 1;
    $hostingServerModel->name = 'Test';
    $hostingServerModel->ip = '127.0.0.1';
    $hostingServerModel->manager = 'Whm';
    $hostingServerModel->accesshash = 'super-secret-hash';

    $di = container();
    $service->setDi($di);

    $result = $service->toHostingServerApiArray($hostingServerModel, true, $identity);

    expect($result)->not->toHaveKey('username');
    expect($result)->not->toHaveKey('password');
    expect($result)->not->toHaveKey('accesshash');
    expect($result)->not->toHaveKey('secret_fields');
});

test('updateServer keeps the existing secret when the incoming value is blank', function (): void {
    $service = new Service();
    $data = [
        'name' => 'Test',
        'ip' => '127.0.0.1',
        'manager' => 'Whm',
        'username' => '',
        'accesshash' => Service::CREDENTIAL_KEEP_SENTINEL,
        'password' => '   ',
    ];

    $hostingServerModel = new Model_ServiceHostingServer();
    $hostingServerModel->loadBean(new Tests\Helpers\DummyBean());
    $hostingServerModel->id = 1;
    $hostingServerModel->name = 'Test';
    $hostingServerModel->ip = '127.0.0.1';
    $hostingServerModel->manager = 'Whm';
    $hostingServerModel->username = 'real-admin';
    $hostingServerModel->accesshash = 'super-secret-hash';

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new \ClientFence\Log();
    $di['loggedin_admin'] = (object) ['id' => 7];
    $di['crypt'] = hostingTestCrypt();
    $service->setDi($di);

    $result = $service->updateServer($hostingServerModel, $data);
    expect($result)->toBeTrue();
    // ClientFence: older plain secrets are kept, now encrypted (ServerSecrets.php); WHM marks its
    // username secret too.
    expect($hostingServerModel->username)->toBe('cfsecret:enc(' . base64_encode('real-admin') . ')');
    expect($hostingServerModel->accesshash)->toBe('cfsecret:enc(' . base64_encode('super-secret-hash') . ')');
    expect($hostingServerModel->password)->toBeNull();
});

test('updateServer replaces the stored secret when a new value is submitted', function (): void {
    $service = new Service();
    $data = [
        'name' => 'Test',
        'ip' => '127.0.0.1',
        'manager' => 'Whm',
        'username' => 'new-admin',
        'accesshash' => 'new-hash',
    ];

    $hostingServerModel = new Model_ServiceHostingServer();
    $hostingServerModel->loadBean(new Tests\Helpers\DummyBean());
    $hostingServerModel->id = 1;
    $hostingServerModel->name = 'Test';
    $hostingServerModel->ip = '127.0.0.1';
    $hostingServerModel->manager = 'Whm';
    $hostingServerModel->username = 'real-admin';
    $hostingServerModel->accesshash = 'old-hash';

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new \ClientFence\Log();
    $di['loggedin_admin'] = (object) ['id' => 7];
    $di['crypt'] = hostingTestCrypt();
    $service->setDi($di);

    $result = $service->updateServer($hostingServerModel, $data);
    expect($result)->toBeTrue();
    expect($hostingServerModel->username)->toBe('cfsecret:enc(' . base64_encode('new-admin') . ')');
    expect($hostingServerModel->accesshash)->toBe('cfsecret:enc(' . base64_encode('new-hash') . ')');
});

test('_performOnService rejects expired active order', function (): void {
    $service = new Service();

    $reflection = new ReflectionMethod($service, '_performOnService');

    $expiredOrder = new Model_ClientOrder();
    $expiredOrder->loadBean(new Tests\Helpers\DummyBean());
    $expiredOrder->status = Model_ClientOrder::STATUS_ACTIVE;
    $expiredOrder->expires_at = date('Y-m-d H:i:s', time() - 3600);

    expect($reflection->invoke($service, $expiredOrder))->toBeFalse();
});

test('_performOnService rejects suspended order', function (): void {
    $service = new Service();

    $reflection = new ReflectionMethod($service, '_performOnService');

    $suspendedOrder = new Model_ClientOrder();
    $suspendedOrder->loadBean(new Tests\Helpers\DummyBean());
    $suspendedOrder->status = Model_ClientOrder::STATUS_SUSPENDED;

    expect($reflection->invoke($service, $suspendedOrder))->toBeFalse();
});

test('_performOnService accepts active order with future expires_at', function (): void {
    $service = new Service();

    $reflection = new ReflectionMethod($service, '_performOnService');

    $activeOrder = new Model_ClientOrder();
    $activeOrder->loadBean(new Tests\Helpers\DummyBean());
    $activeOrder->status = Model_ClientOrder::STATUS_ACTIVE;
    $activeOrder->expires_at = date('Y-m-d H:i:s', time() + 86400);

    expect($reflection->invoke($service, $activeOrder))->toBeTrue();
});

test('_performOnService accepts one-time active order with null expires_at', function (): void {
    $service = new Service();

    $reflection = new ReflectionMethod($service, '_performOnService');

    $oneTimeOrder = new Model_ClientOrder();
    $oneTimeOrder->loadBean(new Tests\Helpers\DummyBean());
    $oneTimeOrder->status = Model_ClientOrder::STATUS_ACTIVE;
    $oneTimeOrder->expires_at = null;

    expect($reflection->invoke($service, $oneTimeOrder))->toBeTrue();
});

test('clientSettableConfigKeys returns the hosting allowlist', function (): void {
    $service = new Service();
    $allowed = $service->clientSettableConfigKeys();

    expect($allowed)->toBeArray();
    // Client may choose billing period, domain options, quantity, and
    // whether multiple items are allowed in a single cart-add. Admin-
    // controlled fields (hosting_plan_id, server_id, reseller, ...) are
    // deliberately absent and will be stripped by the central filter in
    // Product\Service::prepareCartProductConfig before attachOrderConfig runs.
    expect($allowed)->toContain('period');
    expect($allowed)->toContain('domain');
    expect($allowed)->toContain('quantity');
    expect($allowed)->toContain('multiple');
    expect($allowed)->not->toContain('hosting_plan_id');
    expect($allowed)->not->toContain('server_id');
    expect($allowed)->not->toContain('reseller');
});

test('validateOrderData rejects admin-controlled values differing from product config', function (string $field, mixed $injectedValue): void {
    $service = new Service();
    $di = container();
    $di['db'] = Mockery::mock('ClientFence\Database');
    $di['db']->shouldReceive('getCell')->andReturn(0);
    $service->setDi($di);
    $product = createEntity(ClientFence\Mod\Product\Entity\Product::class, [
        'config' => json_encode(['server_id' => 1, 'hosting_plan_id' => 2, 'reseller' => false]),
    ]);
    $data = [
        'server_id' => 1,
        'hosting_plan_id' => 2,
        'reseller' => false,
        'sld' => 'great',
        'tld' => 'com',
    ];

    $data[$field] = $injectedValue;

    try {
        $service->validateOrderData($data, $product);
        expect(true)->toBeFalse('Expected ClientFence\InformationException was not thrown.');
    } catch (ClientFence\InformationException $e) {
        expect($e->getMessage())->toBe('The requested configuration does not match the selected product.');
        expect($e->getCode())->toBe(705);
    }
})->with([
    ['hosting_plan_id', 999],
    ['server_id', 42],
    ['reseller', true],
]);

// ClientFence: a domain hosted by a live order cannot be ordered again (the second order shared its website).
test('validateOrderData refuses a domain another live hosting order has', function (): void {
    $service = new Service();
    $di = container();
    $di['db'] = Mockery::mock('ClientFence\Database');
    $di['db']->shouldReceive('getCell')->once()->with(Mockery::type('string'), Mockery::on(fn (array $p): bool => $p[':sld'] === 'great' && $p[':tld'] === '.com'))->andReturn(1);
    $service->setDi($di);
    $data = ['server_id' => 1, 'hosting_plan_id' => 2, 'sld' => 'great', 'tld' => 'com', 'domain' => ['action' => 'owndomain']];

    expect(fn () => $service->validateOrderData($data))
        ->toThrow(ClientFence\InformationException::class, 'This domain is already hosted with us.');
});

// ClientFence (QA r4 F-02): Unicode, punycode, case and outer dots are one domain for that check.
test('the domain check compares one ASCII spelling', function (string $sld, string $tld): void {
    $service = new Service();
    $di = container();
    $di['db'] = Mockery::mock('ClientFence\Database');
    $di['db']->shouldReceive('getCell')->once()->with(Mockery::type('string'), Mockery::on(fn (array $p): bool => $p[':sld'] === 'xn--bcher-kva' && $p[':tld'] === '.com'))->andReturn(1);
    $service->setDi($di);
    $data = ['server_id' => 1, 'hosting_plan_id' => 2, 'sld' => $sld, 'tld' => $tld, 'domain' => ['action' => 'owndomain']];

    expect(fn () => $service->validateOrderData($data))->toThrow(ClientFence\InformationException::class, 'This domain is already hosted with us.');
})->with([
    'unicode' => ['bücher', '.com'],
    'upper unicode' => ['BÜCHER', '.COM'],
    'leading dot' => ['.xn--bcher-kva', '.com'],
    'spaces and dots' => [' bücher. ', 'com.'],
]);

test('asciiDomain keeps ASCII names and strips outer dots', function (): void {
    expect(Service::asciiDomain('.Example.'))->toBe('example')
        ->and(Service::asciiDomain('münchen.de'))->toBe('xn--mnchen-3ya.de')
        ->and(Service::asciiDomain('Straße.de'))->toBe('xn--strae-oqa.de')
        ->and(Service::asciiDomain(''))->toBe('');
});

test('validateOrderData accepts values matching product config', function (): void {
    $service = new Service();
    $di = container();
    $di['db'] = Mockery::mock('ClientFence\Database');
    $di['db']->shouldReceive('getCell')->andReturn(0);
    $service->setDi($di);
    $product = createEntity(ClientFence\Mod\Product\Entity\Product::class, [
        'config' => json_encode(['server_id' => 1, 'hosting_plan_id' => 2, 'reseller' => false]),
    ]);
    $data = [
        'server_id' => 1,
        'hosting_plan_id' => 2,
        'reseller' => false,
        'sld' => 'great',
        'tld' => 'com',
    ];

    $service->validateOrderData($data, $product);
    expect(true)->toBeTrue();
});

test('getOrderableHpPairs returns only plans referenced by enabled products', function (): void {
    $service = new Service();

    $hostingProduct = createEntity(ClientFence\Mod\Product\Entity\Product::class, [
        'config' => json_encode(['server_id' => 1, 'hosting_plan_id' => 3]),
    ]);
    $unrelatedProduct = createEntity(ClientFence\Mod\Product\Entity\Product::class, [
        'config' => '{}',
    ]);

    $plan = new Model_ServiceHostingHp();
    $plan->loadBean(new Tests\Helpers\DummyBean());
    $plan->id = 3;
    $plan->name = 'Gold';

    $productRepo = Mockery::mock(ClientFence\Mod\Product\Repository\ProductRepository::class);
    $productRepo->shouldReceive('findBy')
        ->once()
        ->with([
            'type' => ClientFence\Mod\Product\Service::HOSTING,
            'active' => true,
            'status' => 'enabled',
            'isAddon' => false,
        ])
        ->andReturn([$hostingProduct, $unrelatedProduct]);

    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('getRepository')
        ->with(ClientFence\Mod\Product\Entity\Product::class)
        ->andReturn($productRepo);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('find')
        ->once()
        ->with('ServiceHostingHp', 'id IN (:hp_id_0)', ['hp_id_0' => 3])
        ->andReturn([$plan]);

    $di = container();
    $di['em'] = $emMock;
    $di['db'] = $dbMock;
    $service->setDi($di);

    expect($service->getOrderableHpPairs())->toBe([3 => 'Gold']);
});

test('getOrderableHpPairs returns empty array when no products reference plans', function (): void {
    $service = new Service();

    $productRepo = Mockery::mock(ClientFence\Mod\Product\Repository\ProductRepository::class);
    $productRepo->shouldReceive('findBy')->once()->andReturn([]);

    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('getRepository')
        ->with(ClientFence\Mod\Product\Entity\Product::class)
        ->andReturn($productRepo);

    $di = container();
    $di['em'] = $emMock;
    $service->setDi($di);

    expect($service->getOrderableHpPairs())->toBe([]);
});

test('ServerSecrets: encrypted values open, older plain values read as they are, a bad one is refused', function (): void {
    $di = container();
    $di['crypt'] = hostingTestCrypt();

    $sealed = ClientFence\Mod\Servicehosting\ServerSecrets::seal($di, 'api-token');
    expect($sealed)->toBe('cfsecret:enc(' . base64_encode('api-token') . ')')
        // Spaces at either end survive (the install's decryption trims what it returns).
        ->and(ClientFence\Mod\Servicehosting\ServerSecrets::open($di, ClientFence\Mod\Servicehosting\ServerSecrets::seal($di, ' pass ')))->toBe(' pass ')
        ->and(ClientFence\Mod\Servicehosting\ServerSecrets::seal($di, $sealed))->toBe($sealed)
        ->and(ClientFence\Mod\Servicehosting\ServerSecrets::seal($di, ''))->toBe('')
        ->and(ClientFence\Mod\Servicehosting\ServerSecrets::seal($di, null))->toBeNull()
        ->and(ClientFence\Mod\Servicehosting\ServerSecrets::open($di, $sealed))->toBe('api-token')
        ->and(ClientFence\Mod\Servicehosting\ServerSecrets::open($di, 'plain-old'))->toBe('plain-old')
        ->and(fn () => ClientFence\Mod\Servicehosting\ServerSecrets::open($di, 'cfsecret:garbage'))->toThrow(ClientFence\InformationException::class);
});

test('getServerManager gives the server manager the plain credentials', function (): void {
    $service = new Service();
    $model = new Model_ServiceHostingServer();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->manager = 'Custom';
    $model->username = 'admin';
    $model->password = 'cfsecret:enc(' . base64_encode('pw') . ')';
    $model->accesshash = 'cfsecret:enc(' . base64_encode('token') . ')';

    $seen = null;
    $di = container();
    $di['crypt'] = hostingTestCrypt();
    $di['server_manager'] = $di->protect(function (string $manager, array $config) use (&$seen) {
        $seen = $config;

        return Mockery::mock('\Server_Manager_Custom')->shouldIgnoreMissing();
    });
    $service->setDi($di);

    $service->getServerManager($model);
    expect([$seen['username'], $seen['password'], $seen['accesshash']])->toBe(['admin', 'pw', 'token']);
});

test('updateServer replaces a stored key that cannot be read', function (): void {
    $service = new Service();
    $model = new Model_ServiceHostingServer();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->id = 1;
    $model->name = 'Test';
    $model->ip = '127.0.0.1';
    $model->manager = 'Custom';
    $model->accesshash = 'cfsecret:unreadable';

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')->atLeast()->once();
    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new \ClientFence\Log();
    $di['loggedin_admin'] = (object) ['id' => 7];
    $di['crypt'] = hostingTestCrypt();
    $service->setDi($di);

    expect($service->updateServer($model, ['accesshash' => 'fresh-key']))->toBeTrue()
        ->and(ClientFence\Mod\Servicehosting\ServerSecrets::open($di, $model->accesshash))->toBe('fresh-key');
    // Keeping it (blank) still says it must be entered again.
    $model->accesshash = 'cfsecret:unreadable';
    expect(fn () => $service->updateServer($model, ['accesshash' => '']))->toThrow(ClientFence\InformationException::class);
});

// ClientFence: a server hands over an existing login only for a trusted email (confirmed, or a stored hosting login
// of this client, this service's own included: an uncancel creates the account again).
test('_getAM trusts the client email by confirmation or a stored hosting login of the client', function (int $approved, int $logins, bool $trusted): void {
    $hp = new Model_ServiceHostingHp();
    $hp->loadBean(new Tests\Helpers\DummyBean());
    $server = new Model_ServiceHostingServer();
    $server->loadBean(new Tests\Helpers\DummyBean());
    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->email = 'b@example.test';
    $client->email_approved = $approved;
    $model = new Model_ServiceHosting();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->id = 4;
    $model->client_id = 9;
    $model->panel_subscription_id = '7';
    $model->panel_org_id = 'B';

    $db = Mockery::mock('ClientFence\Database');
    $db->shouldReceive('getExistingModelById')->andReturn($hp, $server, $client);
    $db->shouldReceive('getCell')
        ->with(Mockery::on(fn (string $sql): bool => !str_contains($sql, 'id !=')), [':client' => 9, ':email' => 'b@example.test'])
        ->andReturn($logins);
    $orderService = Mockery::mock(ClientFence\Mod\Order\Service::class);
    $orderService->shouldReceive('getServiceOrder')->andReturn(null);
    $di = container();
    $di['db'] = $db;
    $di['mod_service'] = $di->protect(fn () => $orderService);

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('getServerPackage')->andReturn(new Server_Package());
    $service->shouldReceive('getServerManager')->andReturn(Mockery::mock(Server_Manager::class));
    $service->setDi($di);

    [, $account] = $service->_getAM($model);
    expect($account->getClient()->isEmailTrusted())->toBe($trusted)
        ->and($account->getPanelSubscriptionId())->toBe(7)
        ->and($account->getPanelOrgId())->toBe('B');
})->with([
    'confirmed' => [1, 0, true],
    'a stored login' => [0, 1, true],
    'neither' => [0, 0, false],
]);

test('_getAM persists an automatic panel link and leaves failed lookups unlinked', function (bool $found): void {
    $hp = new Model_ServiceHostingHp();
    $hp->loadBean(new Tests\Helpers\DummyBean());
    $server = new Model_ServiceHostingServer();
    $server->loadBean(new Tests\Helpers\DummyBean());
    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->email = 'b@example.test';
    $client->email_approved = 1;
    $model = new Model_ServiceHosting();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->id = 4;
    $model->client_id = 9;
    $model->sld = 'site';
    $model->tld = '.example';
    $model->username = 'b@example.test';

    // Mockery cannot mock the manager (its `never` methods), so a stand-in answers the lookup.
    $adapter = new class($found) extends Server_Manager_Enhance {
        public function __construct(private readonly bool $found)
        {
        }

        public function linkAccount(Server_Account $account): array
        {
            expect($account->getDomain())->toBe('site.example');

            return $this->found ? ['org' => 'B', 'subscription' => 7] : throw new Server_Exception('Panel unavailable');
        }
    };
    $logger = new class {
        public array $warnings = [];

        public function warning(string $message, mixed ...$args): void
        {
            $this->warnings[] = vsprintf($message, $args);
        }
    };
    $db = Mockery::mock(ClientFence\Database::class);
    $db->shouldReceive('getExistingModelById')->andReturn($hp, $server, $client);
    if ($found) {
        $db->shouldReceive('store')->once()->with(Mockery::on(fn ($m): bool => $m === $model && $m->panel_org_id === 'B' && $m->panel_subscription_id === 7));
    } else {
        $db->shouldNotReceive('store');
    }
    $orderService = Mockery::mock(ClientFence\Mod\Order\Service::class);
    $orderService->shouldReceive('getServiceOrder')->andReturn(null);
    $di = container();
    $di['db'] = $db;
    $di['logger'] = $logger;
    $di['mod_service'] = $di->protect(fn () => $orderService);

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('getServerPackage')->andReturn(new Server_Package());
    $service->shouldReceive('getServerManager')->andReturn($adapter);
    $service->setDi($di);

    [, $account] = $service->_getAM($model);

    expect($logger->warnings)->toBe($found ? [] : ['Hosting account #4 could not be linked to its panel subscription: Panel unavailable'])
        ->and($model->panel_org_id)->toBe($found ? 'B' : null)
        ->and($model->panel_subscription_id)->toBe($found ? 7 : null)
        ->and($account->getPanelOrgId())->toBe($found ? 'B' : null)
        ->and($account->getPanelSubscriptionId())->toBe($found ? 7 : null);
})->with(['linked' => true, 'lookup failed' => false]);

test('get manager urls logs adapter failures and returns unavailable links', function (Throwable $error): void {
    $hostingServerModel = new Model_ServiceHostingServer();
    $hostingServerModel->loadBean(new Tests\Helpers\DummyBean());
    $hostingServerModel->id = 42;
    $hostingServerModel->manager = 'Custom';

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldReceive('getServerManager')->once()->with($hostingServerModel)->andThrow($error);

    $di = container();
    $serviceMock->setDi($di);

    expect($serviceMock->getManagerUrls($hostingServerModel))->toBe([false, false]);
    $message = $di['logger']->calls[0]['params'][0];
    expect($message)->toContain('server #42 (Custom)', $error::class, $error->getFile())
        ->not->toContain('Adapter credentials: secret');
})->with([
    'exception' => [new Exception('Adapter credentials: secret')],
    'parse error' => [new ParseError('Adapter credentials: secret')],
    'type error' => [new TypeError('Adapter credentials: secret')],
]);
