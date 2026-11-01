<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use ClientFence\Mod\Invoice\Api\Client;
use ClientFence\Mod\Invoice\Service;
use ClientFence\Mod\Invoice\ServiceTax;
use ClientFence\Mod\Invoice\ServiceTransaction;

use function Tests\Helpers\container;
use function Tests\Helpers\moduleService;

test('gets dependency injection container', function (): void {
    $api = apiEndpoint(new Client());
    $di = container();
    $api->setDi($di);
    $getDi = $api->getDi();
    expect($getDi)->toBe($di);
});

test('gets an invoice', function (): void {
    $api = apiEndpoint(new Client());
    $serviceMock = Mockery::mock(Service::class);
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
    $identity = new Model_Client();
    $identity->loadBean(new Tests\Helpers\DummyBean());
    $api->setIdentity($identity);

    $data['hash'] = md5('1');
    $result = $api->get($data);
    expect($result)->toBeArray();
});

test('throws exception when invoice is not found', function (): void {
    $api = apiEndpoint(new Client());
    $dbMock = Mockery::mock('ClientFence\Database');
    $model = new Model_Invoice();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('findOne')
        ->atLeast()->once()
        ->andReturn(null);

    $di = container();
    $di['db'] = $dbMock;

    $api->setDi($di);
    $identity = new Model_Client();
    $identity->loadBean(new Tests\Helpers\DummyBean());
    $api->setIdentity($identity);

    $data['hash'] = md5('1');
    expect(fn () => $api->get($data))
        ->toThrow(ClientFence\InformationException::class, 'Invoice was not found');
});

test('creates renewal invoice', function (): void {
    $api = apiEndpoint(new Client());
    $generatedHash = 'generatedHashString';

    $serviceMock = Mockery::mock(Service::class);
    $model = new Model_Invoice();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->hash = $generatedHash;
    $serviceMock->shouldReceive('generateForOrder')
        ->atLeast()->once()
        ->andReturn($model);
    $serviceMock->shouldReceive('approveInvoice');

    $dbMock = Mockery::mock('ClientFence\Database');
    $clientOrder = new Model_ClientOrder();
    $clientOrder->loadBean(new Tests\Helpers\DummyBean());
    $clientOrder->price = 10;
    $clientOrder->status = Model_ClientOrder::STATUS_ACTIVE;
    $clientOrder->period = '1M';
    $dbMock->shouldReceive('findOne')
        ->atLeast()->once()
        ->andReturn($clientOrder);

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();

    $api->setDi($di);
    $api->setService($serviceMock);
    $identity = new Model_Admin();
    $identity->loadBean(new Tests\Helpers\DummyBean());
    $api->setIdentity($identity);

    $data['order_id'] = 1;
    $result = $api->renewal_invoice($data);
    expect($result)->toBeString()->toBe($generatedHash);
});

test('creates renewal invoice for free order', function (): void {
    $api = apiEndpoint(new Client());
    $generatedHash = 'generatedHashString';

    $serviceMock = Mockery::mock(Service::class);
    $model = new Model_Invoice();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->hash = $generatedHash;
    $serviceMock->shouldReceive('generateForOrder')
        ->atLeast()->once()
        ->andReturn($model);
    $serviceMock->shouldReceive('approveInvoice');

    $dbMock = Mockery::mock('ClientFence\Database');
    $clientOrder = new Model_ClientOrder();
    $clientOrder->loadBean(new Tests\Helpers\DummyBean());
    $clientOrder->id = 1;
    $clientOrder->price = 0;
    $clientOrder->status = Model_ClientOrder::STATUS_ACTIVE;
    $clientOrder->period = '1M';

    $dbMock->shouldReceive('findOne')
        ->atLeast()->once()
        ->andReturn($clientOrder);

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();

    $api->setDi($di);
    $api->setService($serviceMock);
    $identity = new Model_Admin();
    $identity->loadBean(new Tests\Helpers\DummyBean());
    $api->setIdentity($identity);

    $data['order_id'] = 1;
    $result = $api->renewal_invoice($data);
    expect($result)->toBeString()->toBe($generatedHash);
});

test('renewal invoices use the order reloaded inside the lock', function (): void {
    $before = new Model_ClientOrder();
    $before->loadBean(new Tests\Helpers\DummyBean());
    $before->id = 1;
    $before->status = Model_ClientOrder::STATUS_ACTIVE;
    $before->product_id = 5;
    $before->price = 10;
    $before->period = '1M';
    $before->expires_at = '2026-11-01 10:00:00';
    $after = new Model_ClientOrder();
    $after->loadBean(new Tests\Helpers\DummyBean());
    $after->id = 1;
    $after->status = Model_ClientOrder::STATUS_ACTIVE;
    $after->product_id = 6;
    $after->price = 100;
    $after->period = '1Y';
    $after->expires_at = $before->expires_at;
    $db = Mockery::mock(ClientFence\Database::class);
    $db->shouldReceive('findOne')->twice()->with('ClientOrder', 'client_id = ? and id = ?', [3, 1])->andReturn($before, $after);
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    $invoice->id = 9;
    $invoice->hash = 'renewal-hash';
    $service = Mockery::mock(Service::class);
    $service->shouldReceive('generateForOrder')->once()->with($after)->andReturn($invoice);
    $service->shouldReceive('approveInvoice')->once()->with($invoice, ['id' => 9, 'use_credits' => true]);
    $di = container();
    $di['db'] = $db;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $api = apiEndpoint(new Client());
    $api->setDi($di);
    $api->setService($service);
    $identity = new Model_Client();
    $identity->loadBean(new Tests\Helpers\DummyBean());
    $identity->id = 3;
    $api->setIdentity($identity);

    expect($api->renewal_invoice(['order_id' => 1]))->toBe('renewal-hash');
});

test('refuses a renewal click made before another click renewed the order', function (): void {
    $api = apiEndpoint(new Client());
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldNotReceive('generateForOrder');

    $clientOrder = new Model_ClientOrder();
    $clientOrder->loadBean(new Tests\Helpers\DummyBean());
    $clientOrder->id = 1;
    $clientOrder->expires_at = '2026-11-01 10:00:00';
    $clientOrder->status = Model_ClientOrder::STATUS_ACTIVE;
    $clientOrder->period = '1M';
    $renewedOrder = new Model_ClientOrder();
    $renewedOrder->loadBean(new Tests\Helpers\DummyBean());
    $renewedOrder->id = 1;
    $renewedOrder->status = Model_ClientOrder::STATUS_ACTIVE;
    $renewedOrder->period = '1M';
    $renewedOrder->expires_at = '2026-12-01 10:00:00';
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('findOne')->twice()->andReturn($clientOrder, $renewedOrder);

    $di = container();
    $di['db'] = $dbMock;
    $api->setDi($di);
    $api->setService($serviceMock);
    $identity = new Model_Admin();
    $identity->loadBean(new Tests\Helpers\DummyBean());
    $api->setIdentity($identity);

    expect(fn () => $api->renewal_invoice(['order_id' => 1]))
        ->toThrow(ClientFence\InformationException::class, 'renewed a moment ago');
});

test('refuses to renew a cancelled order', function (): void {
    $api = apiEndpoint(new Client());
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldNotReceive('generateForOrder');
    $clientOrder = new Model_ClientOrder();
    $clientOrder->loadBean(new Tests\Helpers\DummyBean());
    $clientOrder->id = 1;
    $clientOrder->status = Model_ClientOrder::STATUS_CANCELED;
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('findOne')->andReturn($clientOrder);

    $di = container();
    $di['db'] = $dbMock;
    $api->setDi($di);
    $api->setService($serviceMock);
    $identity = new Model_Client();
    $identity->loadBean(new Tests\Helpers\DummyBean());
    $api->setIdentity($identity);

    expect(fn () => $api->renewal_invoice(['order_id' => 1]))
        ->toThrow(ClientFence\InformationException::class, 'Only an active or suspended service can be renewed.');
});

test('refuses to renew a service scheduled for cancellation at period end', function (): void {
    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->id = 1;
    $order->status = Model_ClientOrder::STATUS_ACTIVE;
    $order->period = '1M';
    $db = Mockery::mock(ClientFence\Database::class);
    $db->shouldReceive('findOne')->twice()->with('ClientOrder', 'client_id = ? and id = ?', [3, 1])->andReturn($order);
    $dbal = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $dbal->executeStatement('CREATE TABLE client_order_meta (client_order_id INTEGER, name TEXT, value TEXT)');
    $dbal->insert('client_order_meta', ['client_order_id' => 1, 'name' => ClientFence\Mod\Order\Service::META_CANCEL_AT_PERIOD_END, 'value' => '1']);
    $service = Mockery::mock(Service::class);
    $service->shouldNotReceive('generateForOrder');
    $service->shouldNotReceive('approveInvoice');
    $di = container();
    $di['db'] = $db;
    $di['dbal'] = $dbal;
    $api = apiEndpoint(new Client());
    $api->setDi($di);
    $api->setService($service);
    $identity = new Model_Client();
    $identity->loadBean(new Tests\Helpers\DummyBean());
    $identity->id = 3;
    $api->setIdentity($identity);

    expect(fn () => $api->renewal_invoice(['order_id' => 1]))
        ->toThrow(ClientFence\InformationException::class, 'This service is set to be cancelled at the end of its period, so it cannot be renewed.');
});

test('refuses to renew a service staff suspended', function (): void {
    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->id = 1;
    $order->status = Model_ClientOrder::STATUS_SUSPENDED;
    $order->period = '1M';
    $db = Mockery::mock(ClientFence\Database::class);
    $db->shouldReceive('findOne')->twice()->with('ClientOrder', 'client_id = ? and id = ?', [3, 1])->andReturn($order);
    $dbal = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $dbal->executeStatement('CREATE TABLE client_order_meta (client_order_id INTEGER, name TEXT, value TEXT)');
    $dbal->insert('client_order_meta', ['client_order_id' => 1, 'name' => ClientFence\Mod\Order\Service::META_SUSPENDED_BY_STAFF, 'value' => '1']);
    $service = Mockery::mock(Service::class);
    $service->shouldNotReceive('generateForOrder');
    $service->shouldNotReceive('approveInvoice');
    $di = container();
    $di['db'] = $db;
    $di['dbal'] = $dbal;
    $api = apiEndpoint(new Client());
    $api->setDi($di);
    $api->setService($service);
    $identity = new Model_Client();
    $identity->loadBean(new Tests\Helpers\DummyBean());
    $identity->id = 3;
    $api->setIdentity($identity);

    expect(fn () => $api->renewal_invoice(['order_id' => 1]))
        ->toThrow(ClientFence\InformationException::class, 'This service was suspended by our staff, so it cannot be renewed here. Please contact us.');
});

test('refuses to renew a one-time order', function (): void {
    $api = apiEndpoint(new Client());
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldNotReceive('generateForOrder');
    $clientOrder = new Model_ClientOrder();
    $clientOrder->loadBean(new Tests\Helpers\DummyBean());
    $clientOrder->id = 1;
    $clientOrder->status = Model_ClientOrder::STATUS_ACTIVE;
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('findOne')->andReturn($clientOrder);

    $di = container();
    $di['db'] = $dbMock;
    $api->setDi($di);
    $api->setService($serviceMock);
    $identity = new Model_Client();
    $identity->loadBean(new Tests\Helpers\DummyBean());
    $api->setIdentity($identity);

    expect(fn () => $api->renewal_invoice(['order_id' => 1]))
        ->toThrow(ClientFence\InformationException::class, 'Only an active or suspended service can be renewed.');
});

test('throws exception when creating renewal invoice for order not found', function (): void {
    $api = apiEndpoint(new Client());
    $dbMock = Mockery::mock('ClientFence\Database');
    $clientOrder = new Model_ClientOrder();
    $clientOrder->loadBean(new Tests\Helpers\DummyBean());
    $clientOrder->price = 10;

    $dbMock->shouldReceive('findOne')
        ->atLeast()->once()
        ->andReturn(null);

    $di = container();
    $di['db'] = $dbMock;

    $api->setDi($di);
    $identity = new Model_Admin();
    $identity->loadBean(new Tests\Helpers\DummyBean());
    $api->setIdentity($identity);

    $data['order_id'] = 1;

    expect(fn () => $api->renewal_invoice($data))
        ->toThrow(ClientFence\InformationException::class, 'Order not found');
});

test('creates funds invoice', function (): void {
    $api = apiEndpoint(new Client());
    $generatedHash = 'generatedHashString';

    $serviceMock = Mockery::mock(Service::class);
    $model = new Model_Invoice();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->hash = $generatedHash;
    $serviceMock->shouldReceive('generateFundsInvoice')
        ->atLeast()->once()
        ->andReturn($model);
    $serviceMock->shouldReceive('approveInvoice');

    $di = container();
    $di['logger'] = new Tests\Helpers\TestLogger();

    $api->setDi($di);
    $api->setService($serviceMock);
    $identity = new Model_Client();
    $identity->loadBean(new Tests\Helpers\DummyBean());
    $api->setIdentity($identity);

    $data['amount'] = 10;
    $result = $api->funds_invoice($data);
    expect($result)->toBeString()->toBe($generatedHash);
});

test('gets transaction list', function (): void {
    $api = apiEndpoint(new Client());
    $transactionService = Mockery::mock(ServiceTransaction::class);
    $transactionService->shouldReceive('getSearchQuery')
        ->atLeast()->once()
        ->andReturn(['SqlString', []]);
    $transactionService->shouldReceive('searchResultToApiArray')
        ->once()
        ->with(['id' => 1])
        ->andReturn(['id' => 1, 'gateway' => 'Stripe']);

    $paginatorMock = Mockery::mock(ClientFence\Pagination::class);
    $paginatorMock->shouldReceive('getPaginatedResultSet')
        ->atLeast()->once()
        ->andReturn(['list' => [['id' => 1]]]);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldNotReceive('getExistingModelById');

    $di = container();
    $di['pager'] = $paginatorMock;
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(moduleService(['invoice:transaction' => $transactionService]));

    $api->setDi($di);

    $identity = new Model_Client();
    $identity->loadBean(new Tests\Helpers\DummyBean());
    $api->setIdentity($identity);
    $result = $api->transaction_get_list([]);
    expect($result['list'])->toBe([['id' => 1, 'gateway' => 'Stripe']]);
});

test('gets tax rate for client', function (): void {
    $api = apiEndpoint(new Client());
    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());

    $taxRate = 20;

    $invoiceTaxService = Mockery::mock(ServiceTax::class);
    $invoiceTaxService->shouldReceive('getTaxRateForClient')
        ->atLeast()->once()
        ->andReturn($taxRate);

    $di = container();
    $di['mod_service'] = $di->protect(moduleService(['invoice:tax' => $invoiceTaxService]));
    $api->setDi($di);
    $api->setIdentity($client);

    $result = $api->get_tax_rate();
    expect($result)->toBe($taxRate);
});
