<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use ClientFence\Mod\Invoice\Api\Guest;
use ClientFence\Mod\Invoice\Service;
use ClientFence\Mod\Invoice\ServicePayGateway;

use function Tests\Helpers\container;
use function Tests\Helpers\moduleService;

test('gets dependency injection container', function (): void {
    $api = apiEndpoint(new Guest());
    $di = container();
    $api->setDi($di);
    $getDi = $api->getDi();
    expect($getDi)->toBe($di);
});

test('gets an invoice', function (): void {
    $api = apiEndpoint(new Guest());
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('checkInvoiceAuth')
        ->atLeast()->once();
    $serviceMock->shouldReceive('toApiArray')
        ->atLeast()->once()
        ->andReturn([]);

    $dbMock = Mockery::mock('ClientFence\Database');
    $model = new Model_Invoice();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('findOne')
        ->atLeast()->once()
        ->andReturn($model);

    $di = container();
    $di['db'] = $dbMock;

    $api->setDi($di);
    $api->setService($serviceMock);
    $api->setIdentity(new Model_Admin());

    $data['hash'] = md5('1');
    $result = $api->get($data);
    expect($result)->toBeArray();
});

test('throws exception when invoice is not found', function (): void {
    $api = apiEndpoint(new Guest());
    $dbMock = Mockery::mock('ClientFence\Database');
    $model = new Model_Invoice();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('findOne')
        ->atLeast()->once()
        ->andReturn(null);

    $di = container();
    $di['db'] = $dbMock;

    $api->setDi($di);
    $api->setIdentity(new Model_Admin());

    $data['hash'] = md5('1');
    expect(fn () => $api->get($data))
        ->toThrow(fn (ClientFence\InformationException $e) => expect([$e->getMessage(), $e->getCode()])->toBe(['Invoice was not found', 404]));
});

// ClientFence: a malformed invoice link answers 404 (was a server error on the invoice page).
test('a malformed invoice hash is not found', function (): void {
    $api = apiEndpoint(new Guest());

    expect(fn () => $api->get(['hash' => 'not-a-hash']))
        ->toThrow(fn (ClientFence\InformationException $e) => expect([$e->getMessage(), $e->getCode()])->toBe(['Invalid invoice hash', 404]));
});

test('gets active gateways', function (): void {
    $api = apiEndpoint(new Guest());
    $gatewayServiceMock = Mockery::mock(ServicePayGateway::class);
    $gatewayServiceMock->shouldReceive('getActive')
        ->atLeast()->once()
        ->andReturn([]);

    $di = container();
    $di['mod_service'] = $di->protect(moduleService(['invoice:paygateway' => $gatewayServiceMock]));

    $api->setDi($di);

    $result = $api->gateways([]);
    expect($result)->toBeArray();
});

test('processes payment', function (): void {
    $api = apiEndpoint(new Guest());
    $data = [
        'hash' => str_repeat('a', 32),
        'gateway_id' => 1,
    ];
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('processInvoice')
        ->atLeast()->once()
        ->andReturn([]);

    $api->setService($serviceMock);

    $result = $api->payment($data);
    expect($result)->toBeArray();
});

test('throws exception when payment hash is missing', function (): void {
    $api = apiEndpoint(new Guest());
    $data = [
        'gateway_id' => '',
    ];

    expect(fn () => $api->payment($data))
        ->toThrow(ClientFence\Exception::class, 'Invoice hash not passed. Missing param hash');
});

test('throws exception when payment gateway id is missing', function (): void {
    $api = apiEndpoint(new Guest());
    $data = [
        'hash' => str_repeat('a', 32),
    ];

    expect(fn () => $api->payment($data))
        ->toThrow(ClientFence\InformationException::class, 'Payment method not found. Missing param gateway_id');
});

test('gets whether add funds is enabled', function (): void {
    $api = apiEndpoint(new Guest());
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('isFundsEnabled')
        ->atLeast()->once()
        ->andReturn(false);

    $api->setService($serviceMock);

    $result = $api->funds_enabled();
    expect($result)->toBeFalse();
});

test('generates PDF', function (): void {
    $api = apiEndpoint(new Guest());
    $data = [
        'hash' => str_repeat('a', 32),
    ];
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('generatePDF')
        ->atLeast()->once();

    $di = container();
    $api->setDi($di);

    $api->setService($serviceMock);

    $api->pdf($data);
});

// Track 5 F2: the client area opens the signed-in client's own invoices through the guest routes; only other links are limited.
test('invoice links are rate limited except for the signed-in owner', function (int $ownerId, int $limited): void {
    $api = apiEndpoint(new Guest());
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('generatePDF')->once();
    $auth = Mockery::mock();
    $auth->shouldReceive('isClientLoggedIn')->andReturn(true);
    $dbal = Mockery::mock(Doctrine\DBAL\Connection::class);
    $dbal->shouldReceive('fetchOne')->with('SELECT client_id FROM invoice WHERE hash = ?', [str_repeat('a', 32)])->andReturn($ownerId);
    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->id = 5;
    $limiter = Mockery::mock();
    $limiter->shouldReceive('consumeOrThrow')->times($limited);
    $di = container();
    $di['auth'] = $auth;
    $di['dbal'] = $dbal;
    $di['loggedin_client'] = $client;
    $di['rate_limiter'] = $limiter;
    $api->setDi($di);
    $api->setService($serviceMock);

    $api->pdf(['hash' => str_repeat('a', 32)]);
})->with(['own invoice' => [5, 0], 'another client' => [6, 2]]);
