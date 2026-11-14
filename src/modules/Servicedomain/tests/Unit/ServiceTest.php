<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use ClientFence\Mod\Order\Service as OrderService;
use ClientFence\Mod\Servicedomain\DomainSync;
use ClientFence\Mod\Servicedomain\Service;
use ClientFence\Mod\System\Service as SystemService;

use function Tests\Helpers\container;

class ServicedomainServiceSyncProbe extends Service
{
    public function syncWhoisPublic(Model_ServiceDomain $model, Model_ClientOrder $order): void
    {
        $this->syncWhois($model, $order);
    }
}

afterEach(function (): void {
    Mockery::close();
});

test('gets dependency injection container', function (): void {
    $service = new Service();
    $di = container();
    $service->setDi($di);
    $getDi = $service->getDi();
    expect($getDi)->toBe($di);
});

test('gets cart product title', function (array $data, string $expected): void {
    $service = new Service();
    $service->setDi(container());
    $product = (new ClientFence\Mod\Product\Entity\Product())->setTitle('Example.com Registration');

    $result = $service->getCartProductTitle($product, $data);

    expect($result)->toBe($expected);
})->with([
    [
        [
            'action' => 'register',
            'register_tld' => '.com',
            'register_sld' => 'example',
        ],
        'Domain example.com registration',
    ],
    [
        [
            'action' => 'transfer',
            'transfer_tld' => '.com',
            'transfer_sld' => 'example',
        ],
        'Domain example.com transfer',
    ],
    [
        [],
        'Example.com Registration',
    ],
]);

test('throws exception for invalid order data action', function (): void {
    $service = new Service();
    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('checkRequiredParamsForArray')
        ->atLeast()->once();

    $di = container();
    $di['validator'] = $validatorMock;
    $service->setDi($di);

    $data = [
        'action' => 'NonExistingAction',
    ];

    expect(fn () => $service->validateOrderData($data))
        ->toThrow(ClientFence\Exception::class);
});

test('cart domain products refuse own domains while hosting accepts them', function (): void {
    $domainService = new Service();
    $hostingService = new ClientFence\Mod\Servicehosting\Service();
    $productService = new ClientFence\Mod\Product\Service();
    $di = container();
    $di['mod_service'] = $di->protect(fn (string $name): object => $domainService);
    $validator = Mockery::mock(ClientFence\Validate::class)->shouldIgnoreMissing();
    $validator->shouldReceive('isSldValid')->andReturn(true);
    $validator->shouldReceive('isTldValid')->andReturn(true);
    $di['validator'] = $validator;
    $server = new Model_ServiceHostingServer();
    $server->loadBean(new Tests\Helpers\DummyBean());
    $di['db']->shouldReceive('getExistingModelById')->with('ServiceHostingServer', 1, Mockery::any())->andReturn($server);
    $domainService->setDi($di);
    $hostingService->setDi($di);
    $productService->setDi($di);

    $domainProduct = (new ClientFence\Mod\Product\Entity\Product())->setType('domain');
    $domainConfig = ['action' => 'owndomain', 'owndomain_sld' => 'example', 'owndomain_tld' => '.com'];

    expect(fn () => $productService->prepareCartProductConfig($domainProduct, $domainConfig))
        ->toThrow(ClientFence\InformationException::class, 'Domain products must be registered or transferred.');

    $hostingProduct = (new ClientFence\Mod\Product\Entity\Product())
        ->setType('hosting')->setConfig('{"server_id":1,"hosting_plan_id":2}');
    $hostingConfig = ['domain' => $domainConfig];

    expect($hostingService->getDomainProductFromConfig($hostingProduct, $hostingConfig))->toBeFalse()
        ->and($hostingConfig['sld'])->toBe('example')
        ->and($hostingConfig['tld'])->toBe('.com');
});

test('throws exception for transfer order data with invalid tld', function (array $data, array $isSldValidArr, array $tldFindOneByTldArr, array $canBeTransferred): void {
    $service = new Service();
    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('isSldValid')
        ->{$isSldValidArr['called']}()
        ->andReturn($isSldValidArr['returns']);
    $validatorMock->shouldReceive('checkRequiredParamsForArray')
        ->zeroOrMoreTimes();

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('tldFindOneByTld')
        ->{$tldFindOneByTldArr['called']}()
        ->andReturn($tldFindOneByTldArr['returns']);
    $serviceMock->shouldReceive('canBeTransferred')
        ->{$canBeTransferred['called']}()
        ->andReturn($canBeTransferred['returns']);

    $di = container();
    $di['validator'] = $validatorMock;
    $serviceMock->setDi($di);

    expect(fn () => $serviceMock->validateOrderData($data))
        ->toThrow(ClientFence\Exception::class);
})->with(function () {
    $tldModel = new Model_Tld();
    $tldModel->loadBean(new Tests\Helpers\DummyBean());
    $tldModel->tld = '.com';
    $tldModel->active = true;

    return [
        [
            [
                'action' => 'transfer',
                'transfer_sld' => 'example',
                'transfer_tld' => '.com',
            ],
            ['called' => 'atLeast', 'returns' => true],
            ['called' => 'atLeast', 'returns' => null],
            ['called' => 'never', 'returns' => true],
        ],
        [
            [
                'action' => 'transfer',
                'transfer_sld' => 'example',
                'transfer_tld' => '.com',
            ],
            ['called' => 'atLeast', 'returns' => true],
            ['called' => 'atLeast', 'returns' => $tldModel],
            ['called' => 'atLeast', 'returns' => false],
        ],
    ];
});

test('throws exception when a tld requires a transfer code and none is provided', function (?string $transferCode): void {
    $tldModel = new Model_Tld();
    $tldModel->loadBean(new Tests\Helpers\DummyBean());
    $tldModel->tld = '.com';
    $tldModel->active = true;
    $tldModel->require_transfer_code = true;

    $data = [
        'action' => 'transfer',
        'transfer_sld' => 'example',
        'transfer_tld' => '.com',
    ];
    if ($transferCode !== null) {
        $data['transfer_code'] = $transferCode;
    }

    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('isSldValid')->atLeast()->once()->andReturn(true);
    $validatorMock->shouldReceive('checkRequiredParamsForArray')->zeroOrMoreTimes();

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('tldFindOneByTld')->atLeast()->once()->andReturn($tldModel);
    $serviceMock->shouldReceive('canBeTransferred')->atLeast()->once()->andReturn(true);

    $di = container();
    $di['validator'] = $validatorMock;
    $serviceMock->setDi($di);

    expect(fn () => $serviceMock->validateOrderData($data))
        ->toThrow(ClientFence\InformationException::class, 'A transfer code (EPP/auth code) is required to transfer example.com');
})->with([
    'missing entirely' => [null],
    'blank string' => [''],
    'whitespace only' => ['   '],
]);

test('accepts a transfer order data when the transfer code requirement is satisfied', function (bool $requireTransferCode, ?string $transferCode): void {
    $tldModel = new Model_Tld();
    $tldModel->loadBean(new Tests\Helpers\DummyBean());
    $tldModel->tld = '.com';
    $tldModel->active = true;
    $tldModel->require_transfer_code = $requireTransferCode;

    $data = [
        'action' => 'transfer',
        'transfer_sld' => 'example',
        'transfer_tld' => '.com',
    ];
    if ($transferCode !== null) {
        $data['transfer_code'] = $transferCode;
    }

    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('isSldValid')->atLeast()->once()->andReturn(true);
    $validatorMock->shouldReceive('checkRequiredParamsForArray')->zeroOrMoreTimes();

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('tldFindOneByTld')->atLeast()->once()->andReturn($tldModel);
    $serviceMock->shouldReceive('canBeTransferred')->atLeast()->once()->andReturn(true);

    $di = container();
    $di['validator'] = $validatorMock;
    $serviceMock->setDi($di);

    $serviceMock->validateOrderData($data);

    expect($data['period'])->toBe('1Y');
})->with([
    'code required and provided' => [true, 'EPPCODE123'],
    'code not required and omitted' => [false, null],
]);

test('throws exception for register order data with invalid tld', function (array $data, array $isSldValidArr, array $tldFindOneByTldArr, array $isDomainAvailable): void {
    $service = new Service();
    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('isSldValid')
        ->{$isSldValidArr['called']}()
        ->andReturn($isSldValidArr['returns']);
    $validatorMock->shouldReceive('checkRequiredParamsForArray')
        ->zeroOrMoreTimes();

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('tldFindOneByTld')
        ->{$tldFindOneByTldArr['called']}()
        ->andReturn($tldFindOneByTldArr['returns']);
    $serviceMock->shouldReceive('isDomainAvailable')
        ->{$isDomainAvailable['called']}()
        ->andReturn($isDomainAvailable['returns']);

    $di = container();
    $di['validator'] = $validatorMock;
    $serviceMock->setDi($di);

    expect(fn () => $serviceMock->validateOrderData($data))
        ->toThrow(ClientFence\Exception::class);
})->with(function () {
    $tldModel = new Model_Tld();
    $tldModel->loadBean(new Tests\Helpers\DummyBean());
    $tldModel->tld = '.com';
    $tldModel->min_years = 2;
    $tldModel->active = true;

    return [
        [
            [
                'action' => 'register',
                'register_sld' => 'example',
                'register_years' => 2,
                'register_tld' => '.com',
            ],
            ['called' => 'atLeast', 'returns' => true],
            ['called' => 'atLeast', 'returns' => null],
            ['called' => 'never', 'returns' => true],
        ],
        [
            [
                'action' => 'register',
                'register_sld' => 'example',
                'register_years' => $tldModel->min_years - 1,
                'register_tld' => '.com',
            ],
            ['called' => 'atLeast', 'returns' => true],
            ['called' => 'atLeast', 'returns' => $tldModel],
            ['called' => 'never', 'returns' => true],
        ],
        [
            [
                'action' => 'register',
                'register_sld' => 'example',
                'register_tld' => '.com',
                'register_years' => 2,
            ],
            ['called' => 'atLeast', 'returns' => true],
            ['called' => 'atLeast', 'returns' => $tldModel],
            ['called' => 'atLeast', 'returns' => false],
        ],
    ];
});

test('rejects a registration period outside the tld allowed periods list', function (): void {
    $tldModel = new Model_Tld();
    $tldModel->loadBean(new Tests\Helpers\DummyBean());
    $tldModel->tld = '.com';
    $tldModel->min_years = 1;
    $tldModel->periods = '1,2,5';
    $tldModel->active = true;

    $data = [
        'action' => 'register',
        'register_sld' => 'example',
        'register_years' => 3,
        'register_tld' => '.com',
    ];

    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('isSldValid')->atLeast()->once()->andReturn(true);
    $validatorMock->shouldReceive('checkRequiredParamsForArray')->zeroOrMoreTimes();

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('tldFindOneByTld')->atLeast()->once()->andReturn($tldModel);

    $di = container();
    $di['validator'] = $validatorMock;
    $serviceMock->setDi($di);

    expect(fn () => $serviceMock->validateOrderData($data))
        ->toThrow(ClientFence\Exception::class);
});

test('rejects a registration longer than five years, which no period code can hold', function (?string $periods): void {
    $tldModel = new Model_Tld();
    $tldModel->loadBean(new Tests\Helpers\DummyBean());
    $tldModel->tld = '.com';
    $tldModel->min_years = 1;
    $tldModel->periods = $periods;
    $tldModel->active = true;

    $data = ['action' => 'register', 'register_sld' => 'example', 'register_years' => 10, 'register_tld' => '.com'];

    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('isSldValid')->andReturn(true);
    $validatorMock->shouldReceive('checkRequiredParamsForArray')->zeroOrMoreTimes();

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('tldFindOneByTld')->andReturn($tldModel);
    $serviceMock->shouldReceive('isDomainAvailable')->never();

    $di = container();
    $di['validator'] = $validatorMock;
    $serviceMock->setDi($di);

    expect(fn () => $serviceMock->validateOrderData($data))->toThrow(ClientFence\InformationException::class, 'at most');
})->with(['no periods set' => [null], 'ten years listed' => ['1,10']]);

test('accepts a registration period within the tld allowed periods list', function (): void {
    $tldModel = new Model_Tld();
    $tldModel->loadBean(new Tests\Helpers\DummyBean());
    $tldModel->tld = '.com';
    $tldModel->min_years = 1;
    $tldModel->periods = '1,2,5';
    $tldModel->active = true;

    $data = [
        'action' => 'register',
        'register_sld' => 'example',
        'register_years' => 5,
        'register_tld' => '.com',
    ];

    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('isSldValid')->atLeast()->once()->andReturn(true);
    $validatorMock->shouldReceive('checkRequiredParamsForArray')->zeroOrMoreTimes();

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('tldFindOneByTld')->atLeast()->once()->andReturn($tldModel);
    $serviceMock->shouldReceive('isDomainAvailable')->atLeast()->once()->andReturn(true);

    $di = container();
    $di['validator'] = $validatorMock;
    $serviceMock->setDi($di);

    $serviceMock->validateOrderData($data);

    expect($data['period'])->toBe('5Y');
});

test('rejects a crafted order for an inactive tld before contacting the registrar', function (string $action): void {
    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('checkRequiredParamsForArray')->atLeast()->once();
    $validatorMock->shouldReceive('isSldValid')->once()->andReturnTrue();

    $tld = new Model_Tld();
    $tld->loadBean(new Tests\Helpers\DummyBean());
    $tld->tld = '.com';
    $tld->active = false;
    $tld->min_years = 1;

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('tldFindOneByTld')->once()->andReturn($tld);
    $service->shouldReceive('isDomainAvailable')->never();
    $service->shouldReceive('canBeTransferred')->never();

    $di = container();
    $di['validator'] = $validatorMock;
    $service->setDi($di);

    $data = $action === 'register'
        ? ['action' => 'register', 'register_sld' => 'example', 'register_tld' => '.com', 'register_years' => 1]
        : ['action' => 'transfer', 'transfer_sld' => 'example', 'transfer_tld' => '.com'];

    expect(fn () => $service->validateOrderData($data))
        ->toThrow(ClientFence\InformationException::class, 'TLD is not active');
})->with(['register', 'transfer']);

test('creates a transfer service with one year despite client-supplied registration years', function (): void {
    $service = new Service();
    $tldModel = new Model_Tld();
    $tldModel->loadBean(new Tests\Helpers\DummyBean());
    $tldModel->tld_registrar_id = 1;
    $tldModel->tld = '.com';
    $tldModel->active = true;

    $data = [
        'action' => 'transfer',
        'transfer_sld' => 'example',
        'transfer_tld' => '.com',
        'register_years' => 5,
    ];

    $orderServiceMock = Mockery::mock(OrderService::class);
    $orderServiceMock->shouldReceive('getConfig')
        ->atLeast()->once()
        ->andReturn($data);

    $nameservers = [
        'nameserver_1' => 'ns1.example.com',
        'nameserver_2' => 'ns2.example.com',
        'nameserver_3' => 'ns3.example.com',
        'nameserver_4' => 'ns4.example.com',
    ];
    $systemServiceMock = Mockery::mock(SystemService::class);
    $systemServiceMock->shouldReceive('getNameservers')
        ->atLeast()->once()
        ->andReturn($nameservers);

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('tldFindOneByTld')
        ->atLeast()->once()
        ->andReturn($tldModel);
    $serviceMock->shouldReceive('canBeTransferred')->once()->andReturnTrue();
    $serviceMock->shouldReceive('tldDomainFields')->andReturn([]);

    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->first_name = 'first_name';
    $client->last_name = 'last_name';
    $client->email = 'email';
    $client->company = 'company';
    $client->address_1 = 'address_1';
    $client->address_2 = 'address_2';
    $client->country = 'country';
    $client->city = 'city';
    $client->state = 'state';
    $client->postcode = 'postcode';
    $client->phone_cc = 'phone_cc';
    $client->phone = 'phone';

    $serviceDomainModel = new Model_ServiceDomain();
    $serviceDomainModel->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($client);
    $dbMock->shouldReceive('dispense')
        ->atLeast()->once()
        ->andReturn($serviceDomainModel);
    $dbMock->shouldReceive('store')
        ->atLeast()->once()
        ->andReturn(1);

    $di = container();
    $di['mod_service'] = $di->protect(function ($name) use ($orderServiceMock, $systemServiceMock) {
        if ($name == 'order') {
            return $orderServiceMock;
        }

        return $systemServiceMock;
    });
    $di['db'] = $dbMock;

    $serviceMock->setDi($di);

    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->client_id = 1;

    $result = $serviceMock->action_create($order);
    expect($result)->toBeInstanceOf(Model_ServiceDomain::class)
        ->and($result->period)->toBe(1);
});

test('throws exception when creating action with missing nameservers', function (): void {
    $service = new Service();
    $tldModel = new Model_Tld();
    $tldModel->loadBean(new Tests\Helpers\DummyBean());
    $tldModel->tld_registrar_id = 1;

    $data = [
        'action' => 'register',
        'register_sld' => 'example',
        'register_tld' => '.com',
        'register_years' => 2,
    ];

    $orderServiceMock = Mockery::mock(OrderService::class);
    $orderServiceMock->shouldReceive('getConfig')
        ->atLeast()->once()
        ->andReturn($data);

    $systemServiceMock = Mockery::mock(SystemService::class);
    $systemServiceMock->shouldReceive('getNameservers')
        ->atLeast()->once()
        ->andReturn([]);

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('validateOrderData')
        ->atLeast()->once();

    $di = container();
    $di['mod_service'] = $di->protect(function ($name) use ($orderServiceMock, $systemServiceMock) {
        if ($name == 'order') {
            return $orderServiceMock;
        }

        return $systemServiceMock;
    });
    $serviceMock->setDi($di);

    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->client_id = 1;

    expect(fn () => $serviceMock->action_create($order))
        ->toThrow(ClientFence\Exception::class);
});

test('activates action', function (string $action, string $registerDomainCalled, string $transferDomainCalled): void {
    $service = new Service();
    $tldModel = new Model_Tld();
    $tldModel->loadBean(new Tests\Helpers\DummyBean());
    $tldModel->tld_registrar_id = 1;

    $domainModel = new Model_ServiceDomain();
    $domainModel->loadBean(new Tests\Helpers\DummyBean());
    $domainModel->tld_registrar_id = 1;
    $domainModel->action = $action;

    $orderServiceMock = Mockery::mock(OrderService::class);
    $orderServiceMock->shouldReceive('getOrderService')
        ->atLeast()->once()
        ->andReturn($domainModel);

    $registrarAdapterMock = Mockery::mock('Registrar_Adapter_Custom');
    $registrarAdapterMock->shouldReceive('registerDomain')
        ->{$registerDomainCalled}()->andReturn(true);
    $registrarAdapterMock->shouldReceive('transferDomain')
        ->{$transferDomainCalled}()->andReturn(true);

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('_getD')
        ->atLeast()->once()
        ->andReturn([new Registrar_Domain(), $registrarAdapterMock]);
    $serviceMock->shouldReceive('syncWhois')
        ->atLeast()->once()
        ->andReturn(null);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')
        ->atLeast()->once()
        ->andReturn(1);

    $di = container();
    $di['mod_service'] = $di->protect(fn ($name) => $orderServiceMock);
    $di['db'] = $dbMock;
    $serviceMock->setDi($di);

    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->client_id = 1;
    $result = $serviceMock->action_activate($order);
    expect($result)->toBeInstanceOf(Model_ServiceDomain::class);
})->with([
    ['register', 'atLeast', 'never'],
    ['transfer', 'never', 'atLeast'],
]);

test('rejected domain activation keeps its registrar action for retry', function (string $action): void {
    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $domainModel = new Model_ServiceDomain();
    $domainModel->loadBean(new Tests\Helpers\DummyBean());
    $domainModel->action = $action;
    $orderService = Mockery::mock(OrderService::class);
    $orderService->shouldReceive('getOrderService')->once()->with($order)->andReturn($domainModel);
    $domain = new Registrar_Domain();
    $adapter = Mockery::mock(Registrar_Adapter_Custom::class);
    $adapter->shouldReceive($action . 'Domain')->once()->with($domain)->andReturn(false);
    $service = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('_getD')->once()->with($domainModel)->andReturn([$domain, $adapter]);
    $service->shouldNotReceive('syncWhois');

    $di = container();
    $di['mod_service'] = $di->protect(fn (string $name): OrderService => $orderService);
    $di['db']->shouldNotReceive('store');
    $service->setDi($di);

    expect(fn () => $service->action_activate($order))->toThrow(Registrar_Exception::class)
        ->and($domainModel->action)->toBe($action);
})->with(['register', 'transfer']);

test('throws exception when activating without order service', function (): void {
    $service = new Service();
    $orderServiceMock = Mockery::mock(OrderService::class);
    $orderServiceMock->shouldReceive('getOrderService')
        ->atLeast()->once()
        ->andReturn(null);

    $di = container();
    $di['mod_service'] = $di->protect(fn ($name) => $orderServiceMock);
    $service->setDi($di);

    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->client_id = 1;

    expect(fn () => $service->action_activate($order))
        ->toThrow(ClientFence\Exception::class);
});

test('renewal retries recognize an advanced registrar expiry after a lost reply', function (bool $lostReply): void {
    $domainModel = new Model_ServiceDomain();
    $domainModel->loadBean(new Tests\Helpers\DummyBean());
    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->id = 7;
    $metaOrder = Tests\Helpers\createEntity(ClientFence\Mod\Order\Entity\Order::class, ['id' => 7]);
    $expiry = strtotime('2030-06-15 00:00:00');
    $storedMeta = null;
    $repository = Mockery::mock(ClientFence\Mod\Order\Repository\OrderMetaRepository::class);
    $repository->shouldReceive('findOneByOrderIdAndName')->times($lostReply ? 4 : 3)
        ->with(7, 'domain_renewal_expiry')
        ->andReturnUsing(function () use (&$storedMeta) {
            return $storedMeta;
        });
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getReference')->times($lostReply ? 2 : 1)
        ->with(ClientFence\Mod\Order\Entity\Order::class, 7)->andReturn($metaOrder);
    $em->shouldReceive('persist')->twice()->andReturnUsing(function ($entity) use (&$storedMeta): void {
        $storedMeta = $entity;
    });
    $em->shouldReceive('flush')->twice();
    $orderService = Mockery::mock(OrderService::class)->makePartial();
    $orderService->shouldReceive('getOrderService')->andReturn($domainModel);
    $orderService->shouldReceive('getOrderMetaRepository')->andReturn($repository);

    $domain = new Registrar_Domain();
    $adapter = Mockery::mock(Registrar_Adapter_Custom::class);
    $adapter->shouldReceive('getDomainDetails')->times($lostReply ? 2 : 1)->with($domain)
        ->andReturnUsing(function () use (&$expiry): Registrar_Domain {
            return (new Registrar_Domain())->setExpirationTime($expiry);
        });
    $adapter->shouldReceive('renewDomain')->once()->with($domain)
        ->andReturnUsing(function () use (&$expiry, $lostReply): bool {
            $expiry = strtotime('2031-06-15 00:00:00');
            if ($lostReply) {
                throw new RuntimeException('Renewal reply timed out');
            }

            return true;
        });
    $service = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('_getD')->andReturn([$domain, $adapter]);
    $service->shouldReceive('syncWhois')->once()->with($domainModel, $order);

    $di = container();
    $di['em'] = $em;
    $di['mod_service'] = $di->protect(fn (string $name): OrderService => $orderService);
    $orderService->setDi($di);
    $service->setDi($di);

    if ($lostReply) {
        expect(fn () => $service->action_renew($order))->toThrow(RuntimeException::class, 'Renewal reply timed out');
        expect($storedMeta->getValue())->toBe(date('Y-m-d H:i:s', strtotime('2030-06-15 00:00:00')) . '|');
    }
    expect($service->action_renew($order))->toBeTrue()
        ->and($storedMeta->getValue())->toBeNull();
})->with(['normal renewal' => [false], 'lost reply and retry' => [true]]);

test('failed renewal attempts retain the saved expiry mark', function (bool $lookupFails): void {
    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->id = 7;
    $order->expires_at = '2030-06-15 00:00:00';
    $metaOrder = Tests\Helpers\createEntity(ClientFence\Mod\Order\Entity\Order::class, ['id' => 7]);
    $domainModel = new Model_ServiceDomain();
    $domainModel->loadBean(new Tests\Helpers\DummyBean());
    $saved = '2030-06-15 00:00:00|' . $order->expires_at;
    $mark = new ClientFence\Mod\Order\Entity\OrderMeta();
    $mark->setValue($saved);
    $repository = Mockery::mock(ClientFence\Mod\Order\Repository\OrderMetaRepository::class);
    $repository->shouldReceive('findOneByOrderIdAndName')->once()->with(7, 'domain_renewal_expiry')->andReturn($mark);
    $orderService = Mockery::mock(OrderService::class);
    $orderService->shouldReceive('getOrderService')->once()->with($order)->andReturn($domainModel);
    $orderService->shouldReceive('getOrderMetaRepository')->once()->andReturn($repository);
    $orderService->shouldNotReceive('updateOrderMeta');
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getReference')->once()->with(ClientFence\Mod\Order\Entity\Order::class, 7)->andReturn($metaOrder);

    $domain = (new Registrar_Domain())->setExpirationTime(strtotime('2030-06-15 00:00:00'));
    $adapter = Mockery::mock(Registrar_Adapter_Custom::class);
    $lookup = $adapter->shouldReceive('getDomainDetails')->once()->with($domain);
    if ($lookupFails) {
        $lookup->andThrow(new RuntimeException('Expiry lookup failed'));
        $adapter->shouldNotReceive('renewDomain');
    } else {
        $lookup->andReturn($domain);
        $adapter->shouldReceive('renewDomain')->once()->with($domain)->andReturn(false);
    }
    $service = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('_getD')->once()->with($domainModel)->andReturn([$domain, $adapter]);
    $service->shouldNotReceive('syncWhois');

    $di = container();
    $di['em'] = $em;
    $di['mod_service'] = $di->protect(fn (string $name): OrderService => $orderService);
    $service->setDi($di);

    expect(fn () => $service->action_renew($order))->toThrow(Registrar_Exception::class)
        ->and($mark->getValue())->toBe($saved);
})->with(['expiry lookup fails' => [true], 'registrar rejects renewal' => [false]]);

test('a renewal mark left by an earlier renewal does not skip the registrar renewal', function (): void {
    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->id = 7;
    $order->expires_at = '2027-06-15 00:00:00';
    $metaOrder = Tests\Helpers\createEntity(ClientFence\Mod\Order\Entity\Order::class, ['id' => 7]);
    $domainModel = new Model_ServiceDomain();
    $domainModel->loadBean(new Tests\Helpers\DummyBean());
    // Last year's renewal failed and was settled by hand: its mark names last year's order expiry.
    $mark = Mockery::mock(ClientFence\Mod\Order\Entity\OrderMeta::class);
    $mark->shouldReceive('getValue')->andReturn('2026-06-15 00:00:00|2026-06-15 00:00:00');
    $repository = Mockery::mock(ClientFence\Mod\Order\Repository\OrderMetaRepository::class);
    $repository->shouldReceive('findOneByOrderIdAndName')->once()->with(7, 'domain_renewal_expiry')->andReturn($mark);
    $orderService = Mockery::mock(OrderService::class);
    $orderService->shouldReceive('getOrderService')->once()->with($order)->andReturn($domainModel);
    $orderService->shouldReceive('getOrderMetaRepository')->once()->andReturn($repository);
    $orderService->shouldReceive('updateOrderMeta')->once()->with($metaOrder, ['domain_renewal_expiry' => '2027-06-15 00:00:00|2027-06-15 00:00:00'])->andReturn(1);
    $orderService->shouldReceive('updateOrderMeta')->once()->with($metaOrder, ['domain_renewal_expiry' => null])->andReturn(1);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getReference')->once()->with(ClientFence\Mod\Order\Entity\Order::class, 7)->andReturn($metaOrder);

    $domain = new Registrar_Domain();
    $adapter = Mockery::mock(Registrar_Adapter_Custom::class);
    $adapter->shouldReceive('getDomainDetails')->with($domain)->andReturn((new Registrar_Domain())->setExpirationTime(strtotime('2027-06-15 00:00:00')));
    $adapter->shouldReceive('renewDomain')->once()->with($domain)->andReturn(true);
    $service = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('_getD')->andReturn([$domain, $adapter]);
    $service->shouldReceive('syncWhois')->once();

    $di = container();
    $di['em'] = $em;
    $di['mod_service'] = $di->protect(fn (string $name): OrderService => $orderService);
    $service->setDi($di);

    expect($service->action_renew($order))->toBeTrue();
});

test('renewal succeeds when the registrar renews but the WHOIS refresh fails', function (): void {
    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->id = 7;
    $metaOrder = Tests\Helpers\createEntity(ClientFence\Mod\Order\Entity\Order::class, ['id' => 7]);
    $domainModel = new Model_ServiceDomain();
    $domainModel->loadBean(new Tests\Helpers\DummyBean());
    $orderService = Mockery::mock(OrderService::class);
    $orderService->shouldReceive('getOrderService')->once()->with($order)->andReturn($domainModel);
    $repository = Mockery::mock(ClientFence\Mod\Order\Repository\OrderMetaRepository::class);
    $repository->shouldReceive('findOneByOrderIdAndName')->once()->with(7, 'domain_renewal_expiry')->andReturnNull();
    $orderService->shouldReceive('getOrderMetaRepository')->once()->andReturn($repository);
    $orderService->shouldReceive('updateOrderMeta')->once()->with($metaOrder, ['domain_renewal_expiry' => null])->andReturn(2);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getReference')->once()->with(ClientFence\Mod\Order\Entity\Order::class, 7)->andReturn($metaOrder);

    $domain = new Registrar_Domain();
    $adapter = Mockery::mock(Registrar_Adapter_Custom::class);
    $adapter->shouldReceive('getDomainDetails')->once()->with($domain)->ordered()->andReturn(new Registrar_Domain());
    $adapter->shouldReceive('renewDomain')->once()->with($domain)->ordered()->andReturn(true);
    $adapter->shouldReceive('getDomainDetails')->once()->with($domain)->ordered()->andThrow(new RuntimeException('WHOIS refresh failed'));
    $service = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('_getD')->twice()->with($domainModel)->andReturn([$domain, $adapter]);

    $di = container();
    $di['em'] = $em;
    $di['mod_service'] = $di->protect(fn (string $name): OrderService => $orderService);
    $service->setDi($di);

    expect($service->action_renew($order))->toBeTrue();
});

test('throws exception when renewing without order service', function (): void {
    $service = new Service();
    $orderServiceMock = Mockery::mock(OrderService::class);
    $orderServiceMock->shouldReceive('getOrderService')
        ->atLeast()->once()
        ->andReturn(null);

    $di = container();
    $di['mod_service'] = $di->protect(fn ($name) => $orderServiceMock);
    $service->setDi($di);

    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->id = 1;
    $order->client_id = 1;

    expect(fn (): bool => $service->action_renew($order))
        ->toThrow(ClientFence\Exception::class);
});

test('suspends action', function (): void {
    $service = new Service();
    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $result = $service->action_suspend($order);
    expect($result)->toBeTrue();
});

test('unsuspends action', function (): void {
    $service = new Service();
    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $result = $service->action_unsuspend($order);
    expect($result)->toBeTrue();
});

test('cancels action', function (): void {
    $service = new Service();
    $tldModel = new Model_Tld();
    $tldModel->loadBean(new Tests\Helpers\DummyBean());
    $tldModel->tld_registrar_id = 1;

    $domainModel = new Model_ServiceDomain();
    $domainModel->loadBean(new Tests\Helpers\DummyBean());
    $domainModel->tld_registrar_id = 1;
    $domainModel->action = 'register';

    $orderServiceMock = Mockery::mock(OrderService::class);
    $orderServiceMock->shouldReceive('getOrderService')
        ->atLeast()->once()
        ->andReturn($domainModel);

    $registrarAdapterMock = Mockery::mock('Registrar_Adapter_Custom');
    $registrarAdapterMock->shouldReceive('deleteDomain')
        ->atLeast()->once();

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('_getD')
        ->atLeast()->once()
        ->andReturn([new Registrar_Domain(), $registrarAdapterMock]);

    $di = container();
    $di['mod_service'] = $di->protect(fn ($name) => $orderServiceMock);
    $serviceMock->setDi($di);

    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->client_id = 1;
    $result = $serviceMock->action_cancel($order);

    expect($result)->toBeTrue();
});

test('throws exception when canceling without order service', function (): void {
    $service = new Service();
    $orderServiceMock = Mockery::mock(OrderService::class);
    $orderServiceMock->shouldReceive('getOrderService')
        ->atLeast()->once()
        ->andReturn(null);

    $di = container();
    $di['mod_service'] = $di->protect(fn ($name) => $orderServiceMock);
    $service->setDi($di);

    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->id = 1;
    $order->client_id = 1;

    expect(fn (): bool => $service->action_cancel($order))
        ->toThrow(ClientFence\Exception::class);
});

// ClientFence: the cancel deleted the domain at the registrar, so an uncancel is refused, never shown as active.
test('refuses to uncancel a domain', function (): void {
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldNotReceive('action_activate');

    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    expect(fn (): bool => $serviceMock->action_uncancel($order))
        ->toThrow(ClientFence\InformationException::class, 'cannot be brought back here');
});

test('deletes action', function (): void {
    $service = new Service();
    $tldModel = new Model_Tld();
    $tldModel->loadBean(new Tests\Helpers\DummyBean());
    $tldModel->tld_registrar_id = 1;

    $domainModel = new Model_ServiceDomain();
    $domainModel->loadBean(new Tests\Helpers\DummyBean());
    $domainModel->tld_registrar_id = 1;
    $domainModel->action = 'register';

    $orderServiceMock = Mockery::mock(OrderService::class);
    $orderServiceMock->shouldReceive('getOrderService')
        ->atLeast()->once()
        ->andReturn($domainModel);

    $registrarAdapterMock = Mockery::mock('Registrar_Adapter_Custom');
    $registrarAdapterMock->shouldReceive('deleteDomain')
        ->atLeast()->once();

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('_getD')
        ->atLeast()->once()
        ->andReturn([new Registrar_Domain(), $registrarAdapterMock]);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('trash')
        ->atLeast()->once()
        ->andReturn(null);

    $di = container();
    $di['mod_service'] = $di->protect(fn ($name) => $orderServiceMock);
    $di['db'] = $dbMock;
    $serviceMock->setDi($di);

    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->status = Model_ClientOrder::STATUS_ACTIVE;
    $result = $serviceMock->action_delete($order);

    expect($result)->toBeNull();
});

test('updates nameservers', function (): void {
    $service = new Service();
    $registrarAdapterMock = Mockery::mock('Registrar_Adapter_Custom');
    $registrarAdapterMock->shouldReceive('modifyNs')
        ->atLeast()->once();

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('_getD')
        ->atLeast()->once()
        ->andReturn([new Registrar_Domain(), $registrarAdapterMock]);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')
        ->atLeast()->once()
        ->andReturn(1);

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $serviceMock->setDi($di);

    $data = [
        'ns1' => 'ns1.example.com',
        'ns2' => 'ns2.example.com',
        'ns3' => 'ns3.example.com',
        'ns4' => 'ns4.example.com',
    ];

    $serviceDomainModel = new Model_ServiceDomain();
    $serviceDomainModel->loadBean(new Tests\Helpers\DummyBean());
    $result = $serviceMock->updateNameservers($serviceDomainModel, $data);

    expect($result)->toBeTrue();
});

test('throws exception when updating nameservers with missing ns1 or ns2', function (array $data): void {
    $service = new Service();
    $serviceDomainModel = new Model_ServiceDomain();
    $serviceDomainModel->loadBean(new Tests\Helpers\DummyBean());

    expect(fn (): bool => $service->updateNameservers($serviceDomainModel, $data))
        ->toThrow(ClientFence\Exception::class);
})->with([
    [['ns2' => 'ns2.example.com']],
    [['ns1' => 'ns1.example.com']],
]);

test('updates contacts', function (): void {
    $service = new Service();
    $registrarAdapterMock = Mockery::mock('Registrar_Adapter_Custom');
    $registrarAdapterMock->shouldReceive('modifyContact')
        ->atLeast()->once();

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('_getD')
        ->atLeast()->once()
        ->andReturn([new Registrar_Domain(), $registrarAdapterMock]);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')
        ->atLeast()->once()
        ->andReturn(1);

    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('checkRequiredParamsForArray')
        ->zeroOrMoreTimes();

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['validator'] = $validatorMock;
    $serviceMock->setDi($di);

    $data = [
        'contact' => [
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'email' => 'email',
            'company' => 'company',
            'address1' => 'address1',
            'address2' => 'address2',
            'country' => 'country',
            'city' => 'city',
            'state' => 'state',
            'postcode' => 'postcode',
            'phone_cc' => 'phone_cc',
            'phone' => 'phone',
        ],
    ];
    $serviceDomainModel = new Model_ServiceDomain();
    $serviceDomainModel->loadBean(new Tests\Helpers\DummyBean());
    $result = $serviceMock->updateContacts($serviceDomainModel, $data);

    expect($result)->toBeTrue();
});

test('gets transfer code', function (): void {
    $service = new Service();
    $epp = 'EPPCODE';

    $registrarAdapterMock = Mockery::mock('Registrar_Adapter_Custom');
    $registrarAdapterMock->shouldReceive('getEpp')
        ->atLeast()->once()
        ->andReturn($epp);

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('_getD')
        ->atLeast()->once()
        ->andReturn([new Registrar_Domain(), $registrarAdapterMock]);

    $serviceDomainModel = new Model_ServiceDomain();
    $serviceDomainModel->loadBean(new Tests\Helpers\DummyBean());
    $result = $serviceMock->getTransferCode($serviceDomainModel);

    expect($result)->toBeString();
    expect($result)->toBe($epp);
});

test('locks domain', function (): void {
    $service = new Service();
    $registrarAdapterMock = Mockery::mock('Registrar_Adapter_Custom');
    $registrarAdapterMock->shouldReceive('lock')
        ->atLeast()->once();

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('_getD')
        ->atLeast()->once()
        ->andReturn([new Registrar_Domain(), $registrarAdapterMock]);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')
        ->atLeast()->once()
        ->andReturn(1);

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $serviceMock->setDi($di);

    $serviceDomainModel = new Model_ServiceDomain();
    $serviceDomainModel->loadBean(new Tests\Helpers\DummyBean());
    $result = $serviceMock->lock($serviceDomainModel);

    expect($result)->toBeTrue();
});

test('unlocks domain', function (): void {
    $service = new Service();
    $registrarAdapterMock = Mockery::mock('Registrar_Adapter_Custom');
    $registrarAdapterMock->shouldReceive('unlock')
        ->atLeast()->once();

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('_getD')
        ->atLeast()->once()
        ->andReturn([new Registrar_Domain(), $registrarAdapterMock]);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')
        ->atLeast()->once()
        ->andReturn(1);

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $serviceMock->setDi($di);

    $serviceDomainModel = new Model_ServiceDomain();
    $serviceDomainModel->loadBean(new Tests\Helpers\DummyBean());
    $result = $serviceMock->unlock($serviceDomainModel);

    expect($result)->toBeTrue();
});

test('enables privacy protection', function (): void {
    $service = new Service();
    $registrarAdapterMock = Mockery::mock('Registrar_Adapter_Custom');
    $registrarAdapterMock->shouldReceive('enablePrivacyProtection')
        ->atLeast()->once();

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('_getD')
        ->atLeast()->once()
        ->andReturn([new Registrar_Domain(), $registrarAdapterMock]);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')
        ->atLeast()->once()
        ->andReturn(1);

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $serviceMock->setDi($di);

    $serviceDomainModel = new Model_ServiceDomain();
    $serviceDomainModel->loadBean(new Tests\Helpers\DummyBean());
    $result = $serviceMock->enablePrivacyProtection($serviceDomainModel);

    expect($result)->toBeTrue();
});

test('disables privacy protection', function (): void {
    $service = new Service();
    $registrarAdapterMock = Mockery::mock('Registrar_Adapter_Custom');
    $registrarAdapterMock->shouldReceive('disablePrivacyProtection')
        ->atLeast()->once();

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('_getD')
        ->atLeast()->once()
        ->andReturn([new Registrar_Domain(), $registrarAdapterMock]);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')
        ->atLeast()->once()
        ->andReturn(1);

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $serviceMock->setDi($di);

    $serviceDomainModel = new Model_ServiceDomain();
    $serviceDomainModel->loadBean(new Tests\Helpers\DummyBean());
    $result = $serviceMock->disablePrivacyProtection($serviceDomainModel);

    expect($result)->toBeTrue();
});

test('checks if domain can be transferred', function (): void {
    $service = new Service();
    $registrarAdapterMock = Mockery::mock('Registrar_Adapter_Custom');
    $registrarAdapterMock->shouldReceive('isDomaincanBeTransferred')
        ->atLeast()->once()
        ->andReturn(true);

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('registrarValidateConfiguration')->once();
    $serviceMock->shouldReceive('registrarGetRegistrarAdapter')
        ->once()
        ->andReturn($registrarAdapterMock);

    $tldRegistrar = new Model_TldRegistrar();
    $tldRegistrar->loadBean(new Tests\Helpers\DummyBean());
    $tldRegistrar->tld_registrar_id = 1;

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->with('TldRegistrar', 1, 'Registrar not found')
        ->andReturn($tldRegistrar);

    $di = container();
    $di['db'] = $dbMock;
    $serviceMock->setDi($di);

    $tld = new Model_Tld();
    $tld->loadBean(new Tests\Helpers\DummyBean());
    $tld->allow_transfer = true;
    $tld->tld = '.com';
    $tld->tld_registrar_id = 1;

    $result = $serviceMock->canBeTransferred($tld, 'example');

    expect($result)->toBeTrue();
});

test('throws exception when checking transfer with empty sld', function (): void {
    $service = new Service();
    expect(fn () => $service->canBeTransferred(new Model_Tld(), ''))
        ->toThrow(ClientFence\Exception::class);
});

test('throws exception when checking transfer not allowed', function (): void {
    $service = new Service();
    $tldModel = new Model_Tld();
    $tldModel->loadBean(new Tests\Helpers\DummyBean());
    $tldModel->allow_transfer = false;

    expect(fn () => $service->canBeTransferred($tldModel, 'example'))
        ->toThrow(ClientFence\Exception::class);
});

test('checks if domain is available', function (): void {
    $service = new Service();
    $registrarAdapterMock = Mockery::mock('Registrar_Adapter_Custom');
    $registrarAdapterMock->shouldReceive('isDomainAvailable')
        ->atLeast()->once()
        ->andReturn(true);

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('registrarValidateConfiguration')->once();
    $serviceMock->shouldReceive('registrarGetRegistrarAdapter')
        ->once()
        ->andReturn($registrarAdapterMock);

    $tldRegistrar = new Model_TldRegistrar();
    $tldRegistrar->loadBean(new Tests\Helpers\DummyBean());
    $tldRegistrar->tld_registrar_id = 1;

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->with('TldRegistrar', 1, 'Registrar not found')
        ->andReturn($tldRegistrar);

    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('isSldValid')
        ->atLeast()->once()
        ->andReturn(true);

    $di = container();
    $di['db'] = $dbMock;
    $di['validator'] = $validatorMock;
    $serviceMock->setDi($di);

    $tld = new Model_Tld();
    $tld->loadBean(new Tests\Helpers\DummyBean());
    $tld->allow_register = true;
    $tld->tld = '.com';
    $tld->tld_registrar_id = 1;

    $result = $serviceMock->isDomainAvailable($tld, 'example');

    expect($result)->toBeTrue();
});

test('throws exception when checking availability with empty sld', function (): void {
    $service = new Service();
    $tldModel = new Model_Tld();
    $tldModel->loadBean(new Tests\Helpers\DummyBean());

    expect(fn () => $service->isDomainAvailable($tldModel, ''))
        ->toThrow(ClientFence\Exception::class);
});

test('throws exception when checking availability with invalid sld', function (): void {
    $service = new Service();
    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('isSldValid')
        ->atLeast()->once()
        ->andReturn(false);

    $di = container();
    $di['validator'] = $validatorMock;
    $service->setDi($di);

    $tldModel = new Model_Tld();
    $tldModel->loadBean(new Tests\Helpers\DummyBean());

    expect(fn () => $service->isDomainAvailable($tldModel, 'example'))
        ->toThrow(ClientFence\Exception::class);
});

test('throws exception when checking availability not allowed to register', function (): void {
    $service = new Service();
    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('isSldValid')
        ->atLeast()->once()
        ->andReturn(true);

    $di = container();
    $di['validator'] = $validatorMock;
    $service->setDi($di);

    $model = new Model_Tld();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->allow_register = false;

    expect(fn () => $service->isDomainAvailable($model, 'example'))
        ->toThrow(ClientFence\Exception::class);
});

test('syncs expiration date from the registrar', function (): void {
    $whois = new Registrar_Domain();
    $whois->setExpirationTime((new DateTime('2030-06-15 00:00:00', new DateTimeZone('UTC')))->getTimestamp());

    $adapter = Mockery::mock(Registrar_Adapter_Custom::class);
    $adapter->shouldReceive('getDomainDetails')
        ->once()
        ->andReturn($whois);

    $service = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('_getD')
        ->once()
        ->andReturn([new Registrar_Domain(), $adapter]);

    $model = new Model_ServiceDomain();
    $model->loadBean(new Tests\Helpers\DummyBean());

    $db = Mockery::mock(\ClientFence\Database::class);
    $db->shouldReceive('store')->once()->with($model);

    $di = container();
    $di['db'] = $db;
    $service->setDi($di);

    $service->syncExpirationDate($model);

    expect($model->expires_at)->toBe('2030-06-15 00:00:00');
});

test('preserves the existing expiration date when the registrar has none', function (): void {
    $whois = new Registrar_Domain();

    $adapter = Mockery::mock(Registrar_Adapter_Custom::class);
    $adapter->shouldReceive('getDomainDetails')
        ->once()
        ->andReturn($whois);

    $service = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('_getD')
        ->once()
        ->andReturn([new Registrar_Domain(), $adapter]);

    $model = new Model_ServiceDomain();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->expires_at = '2030-06-15 00:00:00';

    $db = Mockery::mock(\ClientFence\Database::class);
    $db->shouldReceive('store')->once()->with($model);

    $di = container();
    $di['db'] = $db;
    $service->setDi($di);

    $service->syncExpirationDate($model);

    expect($model->expires_at)->toBe('2030-06-15 00:00:00');
});

test('syncWhois replaces stored nameservers with the registrar nameservers', function (): void {
    $domain = new Registrar_Domain();
    foreach (range(1, 4) as $index) {
        $domain->{'setNs' . $index}('ns' . $index . '.stored.example');
    }
    $adapter = Mockery::mock(Registrar_Adapter_Custom::class);
    $adapter->shouldReceive('getDomainDetails')->once()->with($domain)
        ->andReturnUsing(fn (Registrar_Domain $domain) => $domain
            ->setNs1('ns1.registrar.example')->setNs2('ns2.registrar.example')
            ->setContactRegistrar(new Registrar_Domain_Contact()));
    $service = Mockery::mock(ServicedomainServiceSyncProbe::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('_getD')->once()->andReturn([$domain, $adapter]);

    $model = new Model_ServiceDomain();
    $model->loadBean(new Tests\Helpers\DummyBean());
    foreach (range(1, 4) as $index) {
        $model->{'ns' . $index} = 'ns' . $index . '.stored.example';
    }
    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $db = Mockery::mock(ClientFence\Database::class);
    $db->shouldReceive('store')->once()->with($model);
    $di = container();
    $di['db'] = $db;
    $service->setDi($di);

    $service->syncWhoisPublic($model, $order);

    expect([$model->ns1, $model->ns2, $model->ns3, $model->ns4])
        ->toBe(['ns1.registrar.example', 'ns2.registrar.example', '', '']);
});

test('syncWhois stores null dates when registrar dates are unavailable', function (): void {
    $whois = new Registrar_Domain();
    $contact = new Registrar_Domain_Contact();
    $contact->setName('Test User')
        ->setEmail('test@example.com')
        ->setCompany('Example')
        ->setAddress1('Address 1')
        ->setAddress2('Address 2')
        ->setCountry('US')
        ->setCity('City')
        ->setState('State')
        ->setZip('12345')
        ->setTelCc('1')
        ->setTel('5551234567');
    $whois->setContactRegistrar($contact);

    $adapter = Mockery::mock(Registrar_Adapter_Custom::class);
    $adapter->shouldReceive('getDomainDetails')
        ->once()
        ->andReturn($whois);

    $service = Mockery::mock(ServicedomainServiceSyncProbe::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('_getD')
        ->once()
        ->andReturn([new Registrar_Domain(), $adapter]);

    $model = new Model_ServiceDomain();
    $model->loadBean(new Tests\Helpers\DummyBean());

    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());

    $db = Mockery::mock(\ClientFence\Database::class);
    $db->shouldReceive('store')
        ->once()
        ->with($model);

    $di = container();
    $di['db'] = $db;
    $service->setDi($di);

    $service->syncWhoisPublic($model, $order);

    expect($model->expires_at)->toBeNull()
        ->and($model->registered_at)->toBeNull()
        ->and($model->synced_at)->not->toBeNull();

    // A registrar without a registration date (Enom) keeps the stored one.
    $model->registered_at = '2024-01-02 10:00:00';
    $service->shouldReceive('_getD')->andReturn([new Registrar_Domain(), $adapter]);
    $adapter->shouldReceive('getDomainDetails')->andReturn($whois);
    $db->shouldReceive('store');
    $service->syncWhoisPublic($model, $order);
    expect($model->registered_at)->toBe('2024-01-02 10:00:00');
});

test('synchronizes domain with registrar', function (): void {
    $model = new Model_ServiceDomain();
    $model->loadBean(new Tests\Helpers\DummyBean());

    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());

    $orderServiceMock = Mockery::mock(OrderService::class);
    $orderServiceMock->shouldReceive('getServiceOrder')
        ->once()
        ->with($model)
        ->andReturn($order);

    $service = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('syncWhois')
        ->once()
        ->with($model, $order);

    $di = container();
    $di['mod_service'] = $di->protect(fn ($name): Mockery\MockInterface => $orderServiceMock);
    $service->setDi($di);

    $service->synchronizeDomain($model);
});

test('throws when synchronizing a domain without an order', function (): void {
    $model = new Model_ServiceDomain();
    $model->loadBean(new Tests\Helpers\DummyBean());

    $orderServiceMock = Mockery::mock(OrderService::class);
    $orderServiceMock->shouldReceive('getServiceOrder')
        ->once()
        ->with($model)
        ->andReturn(null);

    $service = new Service();
    $di = container();
    $di['mod_service'] = $di->protect(fn ($name): Mockery\MockInterface => $orderServiceMock);
    $service->setDi($di);

    expect(fn () => $service->synchronizeDomain($model))
        ->toThrow(ClientFence\Exception::class);
});

test('converts to api array', function (?Model_Admin $identity, string $dbLoadCalled): void {
    $service = new Service();
    $model = new Model_ServiceDomain();
    $model->loadBean(new Tests\Helpers\DummyBean());

    $model->sld = 'sld';
    $model->tld = 'tld';
    $model->ns1 = 'ns1.example.com';
    $model->ns2 = 'ns2.example.com';
    $model->ns3 = 'ns3.example.com';
    $model->ns4 = 'ns4.example.com';
    $model->period = 'period';
    $model->privacy = 'privacy';
    $model->locked = 'locked';
    $model->registered_at = date('Y-m-d H:i:s');
    $model->expires_at = date('Y-m-d H:i:s');
    $model->synced_at = date('Y-m-d H:i:s');

    $model->contact_first_name = 'first_name';
    $model->contact_last_name = 'last_name';
    $model->contact_email = 'email';
    $model->contact_company = 'company';
    $model->contact_address1 = 'address1';
    $model->contact_address2 = 'address2';
    $model->contact_country = 'country';
    $model->contact_city = 'city';
    $model->contact_state = 'state';
    $model->contact_postcode = 'postcode';
    $model->contact_phone_cc = 'phone_cc';
    $model->contact_phone = 'phone';
    $model->transfer_code = 'EPPCODE';
    $model->tld_registrar_id = 1;

    $tldRegistrar = new Model_TldRegistrar();
    $tldRegistrar->loadBean(new Tests\Helpers\DummyBean());
    $tldRegistrar->name = 'ResellerClub';

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('load')
        ->{$dbLoadCalled}()
        ->andReturn($tldRegistrar);

    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    $result = $service->toApiArray($model, true, $identity);

    expect($result)->toBeArray();
    expect($result)->toHaveKey('domain');
    expect($result)->toHaveKey('sld');
    expect($result)->toHaveKey('tld');
    expect($result)->toHaveKey('ns1');
    expect($result)->toHaveKey('ns2');
    expect($result)->toHaveKey('ns3');
    expect($result)->toHaveKey('ns4');
    expect($result)->toHaveKey('period');
    expect($result)->toHaveKey('privacy');
    expect($result)->toHaveKey('locked');
    expect($result)->toHaveKey('registered_at');
    expect($result)->toHaveKey('expires_at');
    expect($result)->toHaveKey('synced_at');
    expect($result)->toHaveKey('contact');

    $contact = $result['contact'];
    expect($contact)->toBeArray();
    expect($contact)->toHaveKey('first_name');
    expect($contact)->toHaveKey('last_name');
    expect($contact)->toHaveKey('email');
    expect($contact)->toHaveKey('company');
    expect($contact)->toHaveKey('address1');
    expect($contact)->toHaveKey('address2');
    expect($contact)->toHaveKey('country');
    expect($contact)->toHaveKey('city');
    expect($contact)->toHaveKey('state');
    expect($contact)->toHaveKey('postcode');
    expect($contact)->toHaveKey('phone_cc');
    expect($contact)->toHaveKey('phone');

    expect($result['domain'])->toBe($model->sld . $model->tld);
    expect($result['sld'])->toBe($model->sld);
    expect($result['tld'])->toBe($model->tld);
    expect($result['ns1'])->toBe($model->ns1);
    expect($result['ns2'])->toBe($model->ns2);
    expect($result['ns3'])->toBe($model->ns3);
    expect($result['ns4'])->toBe($model->ns4);
    expect($result['period'])->toBe($model->period);
    expect($result['privacy'])->toBe($model->privacy);
    expect($result['locked'])->toBe($model->locked);
    expect($result['registered_at'])->toBe($model->registered_at);
    expect($result['expires_at'])->toBe($model->expires_at);
    expect($result['synced_at'])->toBe($model->synced_at);

    expect($contact['first_name'])->toBe($model->contact_first_name);
    expect($contact['last_name'])->toBe($model->contact_last_name);
    expect($contact['email'])->toBe($model->contact_email);
    expect($contact['company'])->toBe($model->contact_company);
    expect($contact['address1'])->toBe($model->contact_address1);
    expect($contact['address2'])->toBe($model->contact_address2);
    expect($contact['country'])->toBe($model->contact_country);
    expect($contact['city'])->toBe($model->contact_city);
    expect($contact['state'])->toBe($model->contact_state);
    expect($contact['postcode'])->toBe($model->contact_postcode);
    expect($contact['phone_cc'])->toBe($model->contact_phone_cc);
    expect($contact['phone'])->toBe($model->contact_phone);

    if ($identity instanceof Model_Admin) {
        expect($result)->toHaveKey('transfer_code');
        expect($result)->toHaveKey('registrar');
        expect($result['transfer_code'])->toBe($model->transfer_code);
        expect($result['registrar'])->toBe($tldRegistrar->name);
    }
})->with([
    [function () {
        $model = new Model_Admin();
        $model->loadBean(new Tests\Helpers\DummyBean());

        return $model;
    }, 'atLeast'],
    [null, 'once'],
]);

test('handles on before admin cron run event', function (): void {
    $service = new Service();
    $di = container();
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('batchSyncExpirationDates')
        ->atLeast()->once()
        ->andReturn(true);
    $systemServiceMock = Mockery::mock(SystemService::class);
    $systemServiceMock->shouldReceive('getParamValue')->with(DomainSync::DAILY, '0')->andReturn('0');
    $di['mod_service'] = $di->protect(fn ($serviceName): Mockery\MockInterface => $serviceName === 'system' ? $systemServiceMock : $serviceMock);

    $boxEventMock = Mockery::mock('ClientFence\Event');
    $boxEventMock->shouldReceive('getDi')
        ->atLeast()->once()
        ->andReturn($di);

    $result = $service->onBeforeAdminCronRun($boxEventMock);

    expect($result)->toBeTrue();
});

test('batch syncs expiration dates', function (): void {
    $service = new Service();
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('syncExpirationDate')
        ->atLeast()->once();

    $systemServiceMock = Mockery::mock(SystemService::class);
    $systemServiceMock->shouldReceive('getParamValue')
        ->atLeast()->once()
        ->andReturn(null);
    $systemServiceMock->shouldReceive('setParamValue')
        ->atLeast()->once();

    $domains = [
        new Model_ServiceDomain(),
        new Model_ServiceDomain(),
        new Model_ServiceDomain(),
        new Model_ServiceDomain(),
    ];
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('find')
        ->atLeast()->once()
        ->andReturn($domains);

    $di = container();
    $di['mod_service'] = $di->protect(fn ($name) => $systemServiceMock);
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $serviceMock->setDi($di);

    $result = $serviceMock->batchSyncExpirationDates();

    expect($result)->toBeTrue();
});

test('advances the last sync marker when a domain sync fails', function (): void {
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('syncExpirationDate')
        ->once()
        ->andThrow(new Exception('registrar unavailable'));

    $lastSync = null;
    $systemServiceMock = Mockery::mock(SystemService::class);
    // The daily sync (DomainSync.php) is off: the monthly run below.
    $systemServiceMock->shouldReceive('getParamValue')->with(DomainSync::DAILY, '0')->andReturn('0');
    $systemServiceMock->shouldReceive('getParamValue')
        ->twice()
        ->andReturnUsing(static function () use (&$lastSync): ?string {
            return $lastSync;
        });
    $systemServiceMock->shouldReceive('setParamValue')
        ->once()
        ->with('servicedomain_last_sync', Mockery::type('string'))
        ->andReturnUsing(static function (string $key, string $value) use (&$lastSync): bool {
            $lastSync = $value;

            return true;
        });

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('find')
        ->atLeast()->once()
        ->andReturn([new Model_ServiceDomain()]);

    $di = container();
    $di['mod_service'] = $di->protect(fn ($name) => $systemServiceMock);
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $serviceMock->setDi($di);

    $firstResult = $serviceMock->batchSyncExpirationDates();
    $secondResult = $serviceMock->batchSyncExpirationDates();

    expect($firstResult)->toBeTrue()
        ->and($secondResult)->toBeFalse();
});

test('returns false when batch sync already run today', function (): void {
    $service = new Service();
    $systemServiceMock = Mockery::mock(SystemService::class);
    // The daily sync (DomainSync.php) is off: the monthly run below.
    $systemServiceMock->shouldReceive('getParamValue')->with(DomainSync::DAILY, '0')->andReturn('0');
    $systemServiceMock->shouldReceive('getParamValue')
        ->atLeast()->once()
        ->andReturn(date('Y-m-d H:i:s'));
    $systemServiceMock->shouldReceive('setParamValue')
        ->never();

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('find')
        ->never()
        ->andReturn([]);

    $di = container();
    $di['mod_service'] = $di->protect(fn ($name) => $systemServiceMock);
    $di['db'] = $dbMock;
    $service->setDi($di);

    $result = $service->batchSyncExpirationDates();

    expect($result)->toBeFalse();
});

test('gets tld search query', function (array $data, string $expectedQuery, array $expectedBindings): void {
    $service = new Service();
    $di = container();

    $service->setDi($di);
    [$query, $bindings] = $service->tldGetSearchQuery($data);

    expect($query)->toBe($expectedQuery);
    expect($bindings)->toBeArray();
    expect($bindings)->toBe($expectedBindings);
})->with([
    [
        [],
        'SELECT * FROM tld ORDER BY id ASC',
        [],
    ],
    [
        ['hide_inactive' => true],
        'SELECT * FROM tld WHERE active = 1 ORDER BY id ASC',
        [],
    ],
    [
        ['allow_register' => true],
        'SELECT * FROM tld WHERE allow_register = 1 ORDER BY id ASC',
        [],
    ],
    [
        ['allow_transfer' => true],
        'SELECT * FROM tld WHERE allow_transfer = 1 ORDER BY id ASC',
        [],
    ],
    [
        ['hide_inactive' => true, 'allow_register' => true, 'allow_transfer' => true],
        'SELECT * FROM tld WHERE active = 1 AND allow_register = 1 AND allow_transfer = 1 ORDER BY id ASC',
        [],
    ],
]);

test('finds all active tlds', function (): void {
    $service = new Service();
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('find')
        ->atLeast()->once()
        ->andReturn([]);

    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    $result = $service->tldFindAllActive();

    expect($result)->toBeArray();
});

test('finds one active tld by id', function (): void {
    $service = new Service();
    $tldModel = new Model_Tld();
    $tldModel->loadBean(new Tests\Helpers\DummyBean());
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('findOne')
        ->atLeast()->once()
        ->andReturn($tldModel);

    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    $result = $service->tldFindOneActiveById(1);

    expect($result)->toBeInstanceOf(Model_Tld::class);
});

test('gets tld pairs', function (): void {
    $service = new Service();
    $returns = [
        0 => '.com',
    ];
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getAssoc')
        ->atLeast()->once()
        ->andReturn($returns);

    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    $result = $service->tldGetPairs();

    expect($result)->toBeArray();
    expect($result)->toBe($returns);
});

test('checks if tld is already registered', function (): void {
    $service = new Service();
    $tldModel = new Model_Tld();
    $tldModel->loadBean(new Tests\Helpers\DummyBean());
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('findOne')
        ->atLeast()->once()
        ->andReturn($tldModel);

    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    $result = $service->tldAlreadyRegistered('.com');

    expect($result)->toBeTrue();
});

test('checks if tld is not registered', function (): void {
    $service = new Service();
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('findOne')
        ->atLeast()->once()
        ->andReturn(null);

    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    $result = $service->tldAlreadyRegistered('.com');

    expect($result)->toBeFalse();
});

test('removes tld', function (): void {
    $service = new Service();
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('trash')
        ->atLeast()->once()
        ->andReturn(null);

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $service->setDi($di);

    $model = new Model_Tld();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->id = 1;

    $result = $service->tldRm($model);

    expect($result)->toBeTrue();
});

test('converts tld to api array', function (): void {
    $service = new Service();
    $tldRegistrar = new Model_TldRegistrar();
    $tldRegistrar->loadBean(new Tests\Helpers\DummyBean());
    $tldRegistrar->name = 'ResellerClub';

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('load')
        ->atLeast()->once()
        ->andReturn($tldRegistrar);

    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    $model = new Model_Tld();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->tld = '.com';
    $model->price_registration = 1;
    $model->price_renew = 1;
    $model->price_transfer = 1;
    $model->active = 1;
    $model->allow_register = 1;
    $model->allow_transfer = 1;
    $model->require_transfer_code = 1;
    $model->min_years = 2;
    $model->periods = '5,2,2,10';
    $model->tld_registrar_id = 1;

    $result = $service->tldToApiArray($model, new Model_Admin());
    expect($result)->toBeArray();

    expect($result)->toHaveKey('tld');
    expect($result)->toHaveKey('price_registration');
    expect($result)->toHaveKey('price_renew');
    expect($result)->toHaveKey('price_transfer');
    expect($result)->toHaveKey('active');
    expect($result)->toHaveKey('allow_register');
    expect($result)->toHaveKey('allow_transfer');
    expect($result)->toHaveKey('require_transfer_code');
    expect($result)->toHaveKey('min_years');
    expect($result)->toHaveKey('periods');
    expect($result)->toHaveKey('registrar');

    $registrar = $result['registrar'];
    expect($registrar)->toBeArray();
    expect($registrar)->toHaveKey('id');
    expect($registrar)->toHaveKey('title');

    expect($result['tld'])->toBe($model->tld);
    expect($result['price_registration'])->toBe($model->price_registration);
    expect($result['price_renew'])->toBe($model->price_renew);
    expect($result['price_transfer'])->toBe($model->price_transfer);
    expect($result['active'])->toBe($model->active);
    expect($result['allow_register'])->toBe($model->allow_register);
    expect($result['allow_transfer'])->toBe($model->allow_transfer);
    expect($result['require_transfer_code'])->toBe((bool) $model->require_transfer_code);
    expect($result['min_years'])->toBe($model->min_years);
    expect($result['periods'])->toBe([2, 5, 10]);

    expect($registrar['id'])->toBe($model->tld_registrar_id);
    expect($registrar['title'])->toBe($tldRegistrar->name);
});

test('finds one tld by tld', function (): void {
    $service = new Service();
    $tldModel = new Model_Tld();
    $tldModel->loadBean(new Tests\Helpers\DummyBean());
    $tldModel->tld = 'com';
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('findOne')
        ->once()
        ->with('Tld', 'tld = ? ORDER by id ASC', ['.com'])
        ->andReturn(null);
    $dbMock->shouldReceive('findOne')
        ->once()
        ->with('Tld', 'tld = ? ORDER by id ASC', ['com'])
        ->andReturn($tldModel);

    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    $result = $service->tldFindOneByTld(' COM. ');

    expect($result)->toBeInstanceOf(Model_Tld::class);
    expect($result->tld)->toBe('.com');
});

test('finds a non-canonical legacy tld as a compatibility fallback', function (): void {
    $service = new Service();
    $tldModel = new Model_Tld();
    $tldModel->loadBean(new Tests\Helpers\DummyBean());
    $tldModel->tld = ' .COM. ';

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('findOne')->twice()->andReturnNull();
    $dbMock->shouldReceive('findOne')
        ->once()
        ->with(
            'Tld',
            "LOWER(TRIM(TRAILING '.' FROM TRIM(tld))) IN (?, ?) ORDER by id ASC",
            ['.com', 'com'],
        )
        ->andReturn($tldModel);

    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    $result = $service->tldFindOneByTld('.com');

    expect($result)->toBe($tldModel);
    expect($result->tld)->toBe('.com');
});

test('normalizes tlds', function (string $input, string $expected): void {
    $service = new Service();

    expect($service->normalizeTld($input))->toBe($expected);
})->with([
    'leading dot' => ['.com', '.com'],
    'missing leading dot' => ['com', '.com'],
    'case and whitespace' => [' .COM.UA. ', '.com.ua'],
    'internationalized tld' => ['.рф', '.xn--p1ai'],
]);

test('keeps a malformed legacy tld accessible when finding it by id', function (): void {
    $service = new Service();
    $model = new Model_Tld();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->tld = 'not a valid tld';

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('findOne')->once()->andReturn($model);

    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    expect($service->tldFindOneById(1))->toBe($model);
    expect($model->tld)->toBe('not a valid tld');
});

test('rejects invalid tlds', function (string $input): void {
    $service = new Service();

    expect(fn (): string => $service->normalizeTld($input))
        ->toThrow(ClientFence\InformationException::class, 'TLD is invalid');
})->with([
    'empty' => '',
    'only dots' => '..',
    'empty label' => '.com..ua',
    'whitespace within label' => '.com ua',
    'leading hyphen' => '.-com',
    'trailing hyphen' => '.com-',
]);

test('gets registrar search query', function (): void {
    $service = new Service();
    [$query, $bindings] = $service->registrarGetSearchQuery([]);

    expect($query)->toBe('SELECT * FROM tld_registrar ORDER BY name ASC');
    expect($bindings)->toBeArray();
    expect($bindings)->toBe([]);
});

test('gets available registrars', function (): void {
    $service = new Service();
    $registrars = [
        'Resellerclub' => 'Reseller Club',
    ];

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getAssoc')
        ->atLeast()->once()
        ->andReturn($registrars);

    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    $result = $service->registrarGetAvailable();
    expect($result)->toBeArray();
});

test('gets registrar pairs', function (): void {
    $service = new Service();
    $registrars = [
        1 => 'Resellerclub',
        2 => 'Email',
        3 => 'Custom',
    ];

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getAssoc')
        ->atLeast()->once()
        ->andReturn($registrars);

    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    $result = $service->registrarGetPairs();

    expect($result)->toBeArray();
    expect($result)->toHaveCount(3);
});

test('gets active registrar', function (): void {
    $service = new Service();
    $tldRegistrarModel = new Model_TldRegistrar();
    $tldRegistrarModel->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('findOne')
        ->atLeast()->once()
        ->andReturn($tldRegistrarModel);

    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    $result = $service->registrarGetActiveRegistrar();

    expect($result)->toBeInstanceOf(Model_TldRegistrar::class);
});

test('gets registrar configuration', function (): void {
    $service = new Service();
    $config = [
        'config_param' => 'config_value',
    ];

    $di = container();
    $service->setDi($di);

    $model = new Model_TldRegistrar();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->config = json_encode($config);

    $result = $service->registrarGetConfiguration($model);

    expect($result)->toBeArray();
    expect($result)->toBe($config);
});

test('an older registrar config keeps its use_whois switch as use_rdap', function (): void {
    $service = new Service();
    $model = new Model_TldRegistrar();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->config = json_encode(['email' => 'ops@example.com', 'use_whois' => '1']);

    expect($service->registrarGetConfiguration($model))->toBe(['email' => 'ops@example.com', 'use_whois' => '1', 'use_rdap' => '1']);
});

test('gets registrar adapter config', function (): void {
    $service = new Service();
    $model = new Model_TldRegistrar();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->registrar = 'Custom';

    $result = $service->registrarGetRegistrarAdapterConfig($model);
    expect($result)->toBeArray();
});

test('throws exception when getting registrar adapter config for non-existing registrar', function (): void {
    $service = new Service();
    $model = new Model_TldRegistrar();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->registrar = 'NonExisting';
    $service->setDi(container());

    expect(fn () => $service->registrarGetRegistrarAdapterConfig($model))
        ->toThrow(ClientFence\Exception::class);
});

test('gets registrar adapter', function (): void {
    $service = new Service();
    $di = container();
    $di['logger'] = new Tests\Helpers\TestLogger();
    $service->setDi($di);
    $model = new Model_TldRegistrar();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->registrar = 'Custom';

    $result = $service->registrarGetRegistrarAdapter($model);

    expect($result)->toBeInstanceOf('Registrar_Adapter_' . $model->registrar);
});

test('validates required registrar fields even when the adapter constructor does not', function (): void {
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('registrarGetConfiguration')->once()->andReturn([]);
    $service->shouldReceive('registrarGetRegistrarAdapterConfig')->once()->andReturn([
        'form' => [
            'host' => ['text', ['label' => 'EPP host', 'required' => true]],
        ],
    ]);
    $model = new Model_TldRegistrar();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->name = 'Namingo EPP';
    $model->registrar = 'Namingo';
    $model->test_mode = false;

    expect(fn () => $service->registrarValidateConfiguration($model))
        ->toThrow(ClientFence\InformationException::class, 'missing required configuration');
});

test('throws exception when getting registrar adapter for non-existing registrar', function (): void {
    $service = new Service();
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('registrarGetConfiguration')
        ->atLeast()->once()
        ->andReturn([]);

    $model = new Model_TldRegistrar();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->registrar = 'NonExisting';

    expect(fn () => $serviceMock->registrarGetRegistrarAdapter($model))
        ->toThrow(Error::class);
});

test('removes registrar', function (): void {
    $service = new Service();
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('find')
        ->atLeast()->once()
        ->andReturn([]);
    $dbMock->shouldReceive('trash')
        ->atLeast()->once()
        ->andReturn(null);

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    // The registrar's price list and fetch state go with it.
    $dbal = Mockery::mock(Doctrine\DBAL\Connection::class);
    $dbal->shouldReceive('delete')->once()->with('tld_registrar_cost', ['tld_registrar_id' => 1])->andReturn(1);
    foreach (['tld_prices_1', 'tld_sync_1', 'tld_sync_mail_1'] as $name) {
        $dbal->shouldReceive('delete')->once()->with('mod_clientfence_state', ['name' => $name])->andReturn(1);
    }
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($dbal);
    $di['em'] = $em;
    $service->setDi($di);

    $model = new Model_TldRegistrar();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->id = 1;
    $model->name = 'ResellerClub';

    $result = $service->registrarRm($model);

    expect($result)->toBeTrue();
});

test('throws exception when removing registrar with domains', function (): void {
    $service = new Service();
    $serviceDomainModel = new Model_ServiceDomain();
    $serviceDomainModel->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('find')
        ->with('ServiceDomain', 'tld_registrar_id = :registrar_id', [':registrar_id' => 1])
        ->atLeast()->once()
        ->andReturn([$serviceDomainModel]);

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $service->setDi($di);

    $model = new Model_TldRegistrar();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->id = 1;

    expect(fn (): bool => $service->registrarRm($model))
        ->toThrow(ClientFence\InformationException::class, 'Registrar is used by 1 domains');
});

test('converts registrar to api array', function (): void {
    $service = new Service();
    $config = [
        'label' => 'Label',
        'form' => 1,
    ];

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('registrarGetRegistrarAdapterConfig')
        ->atLeast()->once()
        ->andReturn($config);
    $serviceMock->shouldReceive('registrarGetConfiguration')
        ->atLeast()->once()
        ->andReturn(['param1' => 'value1']);

    $model = new Model_TldRegistrar();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->id = 1;
    $model->name = 'ResellerClub';
    $model->test_mode = true;

    $serviceMock->registrarToApiArray($model);
});

test('converts registrar to api array masks secrets for an admin', function (): void {
    $service = new Service();
    $di = container();
    $service->setDi($di);

    $model = new Model_TldRegistrar();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->id = 1;
    $model->name = 'Namecheap';
    $model->registrar = 'Namecheap';
    $model->test_mode = false;
    $model->config = json_encode([
        'api-user-id' => 'reseller-id',
        'api-key' => 'super-secret-key',
        'username' => 'my-username',
    ]);

    $result = $service->registrarToApiArray($model);

    expect($result['secret_fields'])->toContain('api-key');
    expect($result['secret_fields'])->not->toContain('api-user-id');
    expect($result['secret_fields'])->not->toContain('username');
    expect($result['config']['api-user-id'])->toBe('reseller-id');
    expect($result['config']['api-key'])->toBeNull();
    expect($result['config']['api-key_set'])->toBeTrue();
});

test('registrarUpdate keeps the existing secret when the incoming value is blank', function (): void {
    $service = new Service();

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['loggedin_admin'] = new Model_Admin();
    $service->setDi($di);

    $model = new Model_TldRegistrar();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->id = 1;
    $model->registrar = 'Namecheap';
    $model->config = json_encode([
        'api-user-id' => 'reseller-id',
        'api-key' => 'existing-secret',
        'username' => 'existing-username',
        'ip' => '127.0.0.1',
    ]);

    $result = $service->registrarUpdate($model, [
        'config' => [
            'api-key' => Service::REGISTRAR_CREDENTIAL_KEEP_SENTINEL,
            'api-user-id' => 'new-reseller-id',
        ],
    ]);

    expect($result)->toBeTrue();
    $config = json_decode((string) $model->config, true);
    expect($config['api-key'])->toBe('existing-secret');
    expect($config['api-user-id'])->toBe('new-reseller-id');
});

test('registrarUpdate rotates a secret when a new value is submitted', function (): void {
    $service = new Service();

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['loggedin_admin'] = new Model_Admin();
    $service->setDi($di);

    $model = new Model_TldRegistrar();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->id = 1;
    $model->registrar = 'Namecheap';
    $model->config = json_encode([
        'api-user-id' => 'reseller-id',
        'api-key' => 'old-secret',
        'username' => 'existing-username',
        'ip' => '127.0.0.1',
    ]);

    $result = $service->registrarUpdate($model, ['config' => ['api-key' => 'new-secret']]);

    expect($result)->toBeTrue();
    $config = json_decode((string) $model->config, true);
    expect($config['api-key'])->toBe('new-secret');
});

test('creates tld', function (): void {
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('registrarValidateConfiguration')->once();
    $data = [
        'tld' => ' COM.UA. ',
        'tld_registrar_id' => 1,
        'price_registration' => 1,
        'price_renew' => 1,
        'price_transfer' => 1,
        'min_years' => random_int(1, 5),
        'periods' => '5,1,1,3',
        'allow_register' => 1,
        'allow_transfer' => 1,
        'require_transfer_code' => 1,
        'updated_at' => date('Y-m-d H:i:s'),
        'created_at' => date('Y-m-d H:i:s'),
    ];

    $randId = 1;

    $tldModel = new Model_Tld();
    $tldModel->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getExistingModelById')
        ->once()
        ->with('TldRegistrar', 1, 'Registrar not found')
        ->andReturn(new Model_TldRegistrar());
    $dbMock->shouldReceive('store')
        ->atLeast()->once()
        ->andReturn($randId);
    $dbMock->shouldReceive('dispense')
        ->atLeast()->once()
        ->andReturn($tldModel);

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $service->setDi($di);

    $result = $service->tldCreate($data);

    expect($result)->toBeInt();
    expect($result)->toBe($randId);
    expect($tldModel->tld)->toBe('.com.ua');
    expect($tldModel->active)->toBeTruthy();
    expect($tldModel->periods)->toBe('1,3,5');
    expect($tldModel->require_transfer_code)->toBeTruthy();
});

test('defaults require transfer code to false when creating a tld', function (): void {
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('registrarValidateConfiguration')->once();
    $data = [
        'tld' => '.com',
        'tld_registrar_id' => 1,
        'price_registration' => 1,
        'price_renew' => 1,
        'price_transfer' => 1,
    ];

    $tldModel = new Model_Tld();
    $tldModel->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getExistingModelById')
        ->once()
        ->with('TldRegistrar', 1, 'Registrar not found')
        ->andReturn(new Model_TldRegistrar());
    $dbMock->shouldReceive('store')
        ->atLeast()->once()
        ->andReturn(1);
    $dbMock->shouldReceive('dispense')
        ->atLeast()->once()
        ->andReturn($tldModel);

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $service->setDi($di);

    $service->tldCreate($data);

    expect($tldModel->require_transfer_code)->toBeFalsy();
});

test('updates tld', function (): void {
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('registrarValidateConfiguration')->once();
    $data = [
        'tld' => '.com',
        'tld_registrar_id' => 1,
        'price_registration' => 1,
        'price_renew' => 1,
        'price_transfer' => 1,
        'min_years' => random_int(1, 5),
        'periods' => '10,2',
        'allow_register' => true,
        'allow_transfer' => true,
        'require_transfer_code' => true,
        'active' => true,
        'updated_at' => date('Y-m-d H:i:s'),
        'created_at' => date('Y-m-d H:i:s'),
    ];

    $randId = 1;

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getExistingModelById')
        ->once()
        ->with('TldRegistrar', 1, 'Registrar not found')
        ->andReturn(new Model_TldRegistrar());
    $dbMock->shouldReceive('store')
        ->atLeast()->once()
        ->andReturn($randId);

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();

    $service->setDi($di);

    $model = new Model_Tld();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->tld = '.com';

    $result = $service->tldUpdate($model, $data);

    expect($result)->toBeTrue();
    expect($model->periods)->toBe('2,10');
    expect($model->require_transfer_code)->toBeTruthy();
});

test('preserves require transfer code when updating a tld without the field', function (): void {
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('registrarValidateConfiguration')->never();

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $service->setDi($di);

    $model = new Model_Tld();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->tld = '.com';
    $model->require_transfer_code = true;

    $service->tldUpdate($model, ['tld' => '.com']);

    expect($model->require_transfer_code)->toBeTruthy();
});

test('clears tld periods when an empty value is provided', function (): void {
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('registrarValidateConfiguration')->never();

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $service->setDi($di);

    $model = new Model_Tld();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->tld = '.com';
    $model->periods = '1,2,5';

    $result = $service->tldUpdate($model, ['tld' => '.com', 'periods' => '']);

    expect($result)->toBeTrue();
    expect($model->periods)->toBeNull();
});

test('rejects invalid tld pricing and minimum periods', function (array $data): void {
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('registrarValidateConfiguration')->never();

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldNotReceive('getExistingModelById');

    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    expect(fn () => $service->tldCreate(array_replace([
        'tld' => '.com',
        'tld_registrar_id' => 1,
        'price_registration' => 1,
        'price_renew' => 1,
        'price_transfer' => 1,
        'min_years' => 1,
    ], $data)))->toThrow(ClientFence\InformationException::class);
})->with([
    'negative registration price' => [['price_registration' => -1]],
    'non-numeric renewal price' => [['price_renew' => 'free']],
    'non-positive minimum period' => [['min_years' => 0]],
    'non-numeric period in periods list' => [['periods' => '1,2,five']],
    'non-positive period in periods list' => [['periods' => '1,0,3']],
]);

test('creates registrar', function (): void {
    $service = new Service();
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')
        ->atLeast()->once()
        ->andReturn(1);

    $model = new Model_TldRegistrar();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('dispense')
        ->atLeast()->once()
        ->andReturn($model);

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $service->setDi($di);

    $result = $service->registrarCreate('ResellerClub');

    expect($result)->toBeTrue();
});

test('copies registrar', function (): void {
    $service = new Service();
    $newId = 1;
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')
        ->atLeast()->once()
        ->andReturn($newId);

    $model = new Model_TldRegistrar();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('dispense')
        ->atLeast()->once()
        ->andReturn($model);

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $service->setDi($di);

    $model = new Model_TldRegistrar();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->name = 'ResellerClub';
    $model->registrar = 'ResellerClub';
    $model->test_mode = 1;

    $result = $service->registrarCopy($model);

    expect($result)->toBeInt();
    expect($result)->toBe($newId);
});

test('updates registrar', function (): void {
    $service = new Service();
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')
        ->atLeast()->once()
        ->andReturn(1);

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();

    $service->setDi($di);

    $data = [
        'title' => 'ResellerClub',
        'test_mode' => 1,
        'config' => [
            'param1' => 'value1',
        ],
    ];

    $model = new Model_TldRegistrar();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->registrar = 'Custom';

    $result = $service->registrarUpdate($model, $data);

    expect($result)->toBeTrue();
});

test('preserves registrar configuration fields omitted from an update', function (): void {
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('registrarGetRegistrarAdapterConfig')
        ->once()
        ->andReturn(['form' => []]);
    $service->shouldReceive('registrarValidateConfiguration')->once();

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')->once()->andReturn(1);
    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $service->setDi($di);

    $model = new Model_TldRegistrar();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->registrar = 'Custom';
    $model->config = json_encode(['existing' => 'value']);

    $service->registrarUpdate($model, ['config' => ['new' => 'value']]);

    expect(json_decode($model->config, true))->toBe([
        'existing' => 'value',
        'new' => 'value',
    ]);
});

test('allows registrar metadata updates with an incomplete existing configuration', function (): void {
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('registrarValidateConfiguration')->never();

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')->once()->andReturn(1);

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $service->setDi($di);

    $model = new Model_TldRegistrar();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->name = 'Old name';
    $model->registrar = 'Custom';
    $model->config = null;

    expect($service->registrarUpdate($model, ['title' => 'New name']))->toBeTrue();
    expect($model->name)->toBe('New name');
});

test('updates domain', function (): void {
    $service = new Service();
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')
        ->atLeast()->once()
        ->andReturn(1);

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();

    $service->setDi($di);

    $data = [
        'ns1' => 'ns1.example.com',
        'ns2' => 'ns2.example.com',
        'ns3' => 'ns3.example.com',
        'ns4' => 'ns4.example.com',
        'period' => 1,
        'privacy' => 1,
        'locked' => 1,
        'transfer_code' => 'EPPCODE',
    ];

    $model = new Model_ServiceDomain();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->id = 1;

    $result = $service->updateDomain($model, $data);

    expect($result)->toBeTrue();
});

test('handles domain sync failures without blocking other domains', function (Throwable $failure): void {
    $retryAfterFailure = $failure instanceof Error;
    $failedDomain = new Model_ServiceDomain();
    $healthyDomain = new Model_ServiceDomain();
    $failedDomain->loadBean(new Tests\Helpers\DummyBean());
    $healthyDomain->loadBean(new Tests\Helpers\DummyBean());
    $attempts = 0;
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('syncExpirationDate')
        ->times($retryAfterFailure ? 2 : 1)
        ->with($failedDomain)
        ->andReturnUsing(static function () use (&$attempts, $failure): void {
            if (++$attempts === 1) {
                throw $failure;
            }
        });
    $serviceMock->shouldReceive('syncExpirationDate')
        ->times($retryAfterFailure ? 2 : 1)
        ->with($healthyDomain);

    $lastSync = null;
    $systemServiceMock = Mockery::mock(SystemService::class);
    // The daily sync (DomainSync.php) is off: the monthly run below.
    $systemServiceMock->shouldReceive('getParamValue')->with(DomainSync::DAILY, '0')->andReturn('0');
    $systemServiceMock->shouldReceive('getParamValue')
        ->times(3)
        ->andReturnUsing(static function () use (&$lastSync): ?string {
            return $lastSync;
        });
    $systemServiceMock->shouldReceive('setParamValue')
        ->once()
        ->with('servicedomain_last_sync', Mockery::type('string'))
        ->andReturnUsing(static function (string $key, string $value) use (&$lastSync): bool {
            $lastSync = $value;

            return true;
        });

    $dbMock = Mockery::mock(ClientFence\Database::class);
    $dbMock->shouldReceive('find')->with('ServiceDomain')->times($retryAfterFailure ? 2 : 1)->andReturn([$failedDomain, $healthyDomain]);

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(fn ($name) => $systemServiceMock);
    $di['logger'] = new Tests\Helpers\TestLogger();
    $serviceMock->setDi($di);

        $firstResult = $serviceMock->batchSyncExpirationDates();
        expect($lastSync === null)->toBe($retryAfterFailure);

        $secondResult = $serviceMock->batchSyncExpirationDates();
        expect($lastSync)->not->toBeNull();

        $thirdResult = $serviceMock->batchSyncExpirationDates();

    expect($firstResult)->toBeTrue()
        ->and($secondResult)->toBe($retryAfterFailure)
        ->and($thirdResult)->toBeFalse();
})->with([
    'registrar exception' => [new Exception('registrar unavailable')],
    'adapter type error' => [new TypeError('broken registrar adapter')],
]);

test('the registrar domain fields of a registration are checked and kept in the order data', function (): void {
    $tldModel = new Model_Tld();
    $tldModel->loadBean(new Tests\Helpers\DummyBean());
    $tldModel->tld = '.com.au';
    $tldModel->min_years = 1;
    $tldModel->active = true;
    $fields = [
        'registrant_name' => ['label' => 'Registrant name', 'required' => true],
        'eligibility_type' => ['label' => 'Eligibility type', 'required' => true, 'options' => ['Company' => 'Company']],
        'registrant_id' => ['label' => 'Registrant ID'],
    ];

    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('isSldValid')->andReturn(true);
    $validatorMock->shouldReceive('checkRequiredParamsForArray');
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('tldFindOneByTld')->andReturn($tldModel);
    $serviceMock->shouldReceive('isDomainAvailable')->andReturn(true);
    $serviceMock->shouldReceive('tldDomainFields')->andReturnUsing(fn ($tld, string $action = 'register') => $action === 'register' ? $fields : ['registrant_name' => ['label' => 'Registrant name', 'required' => true]]);
    $di = container();
    $di['validator'] = $validatorMock;
    $serviceMock->setDi($di);
    $order = fn (array $answers) => ['action' => 'register', 'register_sld' => 'example', 'register_tld' => '.com.au', 'register_years' => 1, 'domain_fields' => $answers];

    $data = $order(['registrant_name' => ' Example Pty Ltd ', 'eligibility_type' => 'Company', 'registrant_id' => '', 'price' => 0]);
    $serviceMock->validateOrderData($data);
    expect($data['domain_fields'])->toBe(['registrant_name' => 'Example Pty Ltd', 'eligibility_type' => 'Company']);

    foreach ([['eligibility_type' => 'Company'], ['registrant_name' => 'X', 'eligibility_type' => 'Citizen/Resident'], ['registrant_name' => str_repeat('x', 256), 'eligibility_type' => 'Company']] as $answers) {
        $data = $order($answers);
        expect(fn () => $serviceMock->validateOrderData($data))->toThrow(ClientFence\InformationException::class);
    }

    // A transfer is checked against its own fields: the form sends transfer_fields, the stored order keeps domain_fields.
    $data = ['action' => 'transfer', 'transfer_sld' => 'example', 'transfer_tld' => '.com.au', 'transfer_fields' => ['registrant_name' => 'X', 'eligibility_type' => 'Company']];
    $serviceMock->shouldReceive('canBeTransferred')->andReturn(true);
    $serviceMock->validateOrderData($data);
    expect($data['domain_fields'])->toBe(['registrant_name' => 'X'])->and($data)->not->toHaveKey('transfer_fields');
    $serviceMock->validateOrderData($data);
    expect($data['domain_fields'])->toBe(['registrant_name' => 'X']);
    $data = ['action' => 'transfer', 'transfer_sld' => 'example', 'transfer_tld' => '.com.au'];
    expect(fn () => $serviceMock->validateOrderData($data))->toThrow(ClientFence\InformationException::class, 'required to transfer');
});

test('staff saving a transferred domain are checked against the transfer fields, not the registration ones', function (): void {
    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $orderService = Mockery::mock();
    $orderService->shouldReceive('getServiceOrder')->andReturn($order);
    $orderService->shouldReceive('getConfig')->andReturn(['action' => 'transfer']);
    $orderService->shouldReceive('updateOrderConfig')->once()->withArgs(fn ($o, array $config) => $config['domain_fields'] === []);
    $di = container();
    $di['db'] = Mockery::mock('ClientFence\Database')->shouldReceive('store')->getMock();
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['mod_service'] = $di->protect(fn () => $orderService);
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('tldDomainFields')->andReturnUsing(fn ($m, string $action = 'register') => $action === 'register' ? ['registrant_name' => ['label' => 'Registrant name', 'required' => true]] : []);
    $service->setDi($di);
    $model = new Model_ServiceDomain();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->tld = '.com.au';

    expect($service->updateDomain($model, ['period' => 1, 'domain_fields' => ['registrant_name' => '']]))->toBeTrue();
});
