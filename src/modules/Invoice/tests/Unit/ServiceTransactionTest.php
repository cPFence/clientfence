<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use ClientFence\Mod\Invoice\ServiceTransaction;

use function Tests\Helpers\container;

test('gets dependency injection container', function (): void {
    $service = new ServiceTransaction();
    $di = container();
    $service->setDi($di);
    $getDi = $service->getDi();
    expect($getDi)->toBe($di);
});

test('updates a transaction', function (): void {
    $service = new ServiceTransaction();
    $eventsMock = Mockery::mock('ClientFence\EventManager');
    $eventsMock->shouldReceive('fire')
        ->atLeast()->once();

    $transactionModel = new Model_Transaction();
    $transactionModel->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')
        ->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['events_manager'] = $eventsMock;
    $di['logger'] = new Tests\Helpers\TestLogger();

    $service->setDi($di);

    $data = [
        'invoice_id' => 1,
        'txn_id' => 2,
        'txn_status' => '',
        'gateway_id' => 1,
        'amount' => '',
        'currency' => '',
        'type' => '',
        'note' => '',
        'status' => '',
        'validate_ipn' => '',
    ];
    $result = $service->update($transactionModel, $data);
    expect($result)->toBeTrue();
});

test('throws exception when creating transaction with missing invoice id', function (): void {
    $service = new ServiceTransaction();
    $eventsMock = Mockery::mock('ClientFence\EventManager');
    $eventsMock->shouldReceive('fire')
        ->atLeast()->once();

    $di = container();
    $di['events_manager'] = $eventsMock;

    $service->setDi($di);

    $data = [
        'skip_validation' => false,
    ];

    expect(fn () => $service->create($data))
        ->toThrow(ClientFence\Exception::class, 'Transaction invoice ID is missing');
});

test('throws exception when creating transaction with missing gateway id', function (): void {
    $service = new ServiceTransaction();
    $eventsMock = Mockery::mock('ClientFence\EventManager');
    $eventsMock->shouldReceive('fire')
        ->atLeast()->once();

    $di = container();
    $di['events_manager'] = $eventsMock;

    $service->setDi($di);

    $data = [
        'skip_validation' => false,
        'invoice_id' => 2,
    ];

    expect(fn () => $service->create($data))
        ->toThrow(ClientFence\Exception::class, 'Payment gateway ID is missing');
});

test('deletes a transaction', function (): void {
    $service = new ServiceTransaction();
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('trash')
        ->atLeast()->once();

    $di = container();
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['db'] = $dbMock;
    $service->setDi($di);

    $transactionModel = new Model_Transaction();
    $transactionModel->loadBean(new Tests\Helpers\DummyBean());

    $result = $service->delete($transactionModel);
    expect($result)->toBeTrue();
});

test('converts to api array', function (): void {
    $service = new ServiceTransaction();
    $dbMock = Mockery::mock('ClientFence\Database');
    $payGatewayModel = new Model_PayGateway();
    $payGatewayModel->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('load')
        ->atLeast()->once()
        ->andReturn($payGatewayModel);

    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    $expected = [
        'id' => null,
        'invoice_id' => null,
        'txn_id' => null,
        'txn_status' => null,
        'gateway_id' => 1,
        'gateway' => null,
        'amount' => 0.0,
        'currency' => null,
        'type' => null,
        'status' => null,
        'ip' => null,
        'validate_ipn' => null,
        'error' => null,
        'error_code' => null,
        'note' => null,
        'created_at' => null,
        'updated_at' => null,
    ];

    $transactionModel = new Model_Transaction();
    $transactionModel->loadBean(new Tests\Helpers\DummyBean());
    $transactionModel->gateway_id = 1;

    $result = $service->toApiArray($transactionModel, false);
    expect($result)->toBeArray();
    expect($result)->toBe($expected);
});

test('converts a transaction search result without database access', function (): void {
    $service = new ServiceTransaction();
    $di = container();
    $di['db'] = static function (): never {
        throw new RuntimeException('Search result conversion must not access the database');
    };
    $service->setDi($di);

    $result = $service->searchResultToApiArray([
        'id' => 12,
        'invoice_id' => 34,
        'txn_id' => 'txn_123',
        'txn_status' => 'complete',
        'gateway_id' => 2,
        'gateway' => 'Stripe',
        'amount' => '19.95',
        'currency' => 'USD',
        'type' => 'payment',
        'status' => 'processed',
        'ip' => '192.0.2.1',
        'validate_ipn' => 1,
        'error' => null,
        'error_code' => null,
        'note' => 'Test payment',
        'created_at' => '2026-07-19 10:00:00',
        'updated_at' => '2026-07-19 10:01:00',
    ]);

    expect($result)->toMatchArray([
        'id' => 12,
        'gateway' => 'Stripe',
        'amount' => 19.95,
        'status' => 'processed',
    ]);
});

test('gets search query with various parameters', function (array $data, array $expectedParams, string $expectedStringPart): void {
    $service = new ServiceTransaction();
    $di = container();

    $service->setDi($di);
    $result = $service->getSearchQuery($data);
    expect($result[0])->toBeString();
    expect($result[1])->toBeArray();

    expect(str_contains((string) $result[0], $expectedStringPart))->toBeTrue();
    expect($result[1])->toBe($expectedParams);
})->with([
    [
        [], [], 'LEFT JOIN pay_gateway as pg on m.gateway_id = pg.id',
    ],
    [
        ['search' => 'keyword'], ['note' => '%keyword%', 'search_invoice_id' => '%keyword%', 'search_txn_id' => '%keyword%', 'ipn' => '%keyword%'], 'AND (m.note LIKE :note OR m.invoice_id LIKE :search_invoice_id OR m.txn_id LIKE :search_txn_id OR m.ipn LIKE :ipn)',
    ],
    [
        ['invoice_hash' => 'hashString'], ['hash' => 'hashString'], 'AND i.hash = :hash',
    ],
    [
        ['invoice_id' => '1'], ['invoice_id' => '1'], 'AND m.invoice_id = :invoice_id',
    ],
    [
        ['gateway_id' => '2'], ['gateway_id' => '2'], 'AND m.gateway_id = :gateway_id',
    ],
    [
        ['client_id' => '3'], ['client_id' => '3'], 'AND i.client_id = :client_id',
    ],
    [
        ['status' => 'active'], ['status' => 'active'], 'AND m.status = :status',
    ],
    [
        ['currency' => 'Eur'], ['currency' => 'Eur'], 'AND m.currency = :currency',
    ],
    [
        ['type' => 'payment'], ['type' => 'payment'], 'AND m.type = :type',
    ],
    [
        ['txn_id' => 'longTxn_id'], ['txn_id' => 'longTxn_id'], 'AND m.txn_id = :txn_id',
    ],
    [
        ['date_from' => '2012-12-12'], ['date_from' => strtotime('2012-12-12 00:00:00 UTC')], 'AND UNIX_TIMESTAMP(m.created_at) >= :date_from',
    ],
    [
        ['date_to' => '2012-12-12'], ['date_to' => strtotime('2012-12-12 00:00:00 UTC')], 'AND UNIX_TIMESTAMP(m.created_at) <= :date_to',
    ],
]);

test('counts transactions', function (): void {
    $service = new ServiceTransaction();
    $queryResult = [['status' => Model_Transaction::STATUS_RECEIVED, 'counter' => 1]];
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getAll')
        ->atLeast()->once()
        ->andReturn($queryResult);

    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    $result = $service->counter();
    expect($result)->toBeArray();
});

test('createAndProcess marks transaction as error when processing throws', function (): void {
    $transactionModel = new Model_Transaction();
    $transactionModel->loadBean(new Tests\Helpers\DummyBean());
    $transactionModel->id = 1;
    $transactionModel->status = Model_Transaction::STATUS_RECEIVED;

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getExistingModelById')
        ->with('Transaction', 1)
        ->andReturn($transactionModel);
    $dbMock->shouldReceive('load')
        ->with('Transaction', 1)
        ->andReturn($transactionModel);
    $dbMock->shouldReceive('store')
        ->with($transactionModel)
        ->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();

    $service = Mockery::mock(ServiceTransaction::class)->makePartial();
    $service->shouldReceive('create')->once()->andReturn(1);
    $service->shouldReceive('processTransaction')
        ->with(1)
        ->once()
        ->andThrow(new RuntimeException('Processing failed', 1234));
    $service->setDi($di);

    $thrown = null;

    try {
        $service->createAndProcess([]);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown->getMessage())->toBe('Processing failed')
        ->and($transactionModel->status)->toBe(Model_Transaction::STATUS_ERROR)
        ->and($transactionModel->error)->toBe('Processing failed')
        ->and($transactionModel->error_code)->toBe(1234);
});

test('createAndProcess skips processing when transaction is already processed', function (): void {
    $transactionModel = new Model_Transaction();
    $transactionModel->loadBean(new Tests\Helpers\DummyBean());
    $transactionModel->id = 1;
    $transactionModel->status = Model_Transaction::STATUS_PROCESSED;
    $transactionModel->error = null;

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getExistingModelById')
        ->with('Transaction', 1)
        ->andReturn($transactionModel);
    $dbMock->shouldNotReceive('store');

    $di = container();
    $di['db'] = $dbMock;

    $service = Mockery::mock(ServiceTransaction::class)->makePartial();
    $service->shouldReceive('create')->once()->andReturn(1);
    $service->shouldNotReceive('processTransaction');
    $service->setDi($di);

    $result = $service->createAndProcess([]);

    expect($result)->toBe(1);
});

test('preProcessTransaction marks error on a generic exception', function (): void {
    $transactionModel = new Model_Transaction();
    $transactionModel->loadBean(new Tests\Helpers\DummyBean());
    $transactionModel->id = 5;
    $transactionModel->status = Model_Transaction::STATUS_PROCESSING;

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('load')
        ->with('Transaction', 5)
        ->andReturn($transactionModel);
    $dbMock->shouldReceive('store')
        ->with($transactionModel)
        ->once();

    $eventsMock = Mockery::mock('ClientFence\EventManager');
    $eventsMock->shouldNotReceive('fire');

    $di = container();
    $di['db'] = $dbMock;
    $di['events_manager'] = $eventsMock;
    $di['logger'] = new Tests\Helpers\TestLogger();

    $service = Mockery::mock(ServiceTransaction::class)->makePartial();
    $service->shouldReceive('processTransaction')
        ->with(5)
        ->once()
        ->andThrow(new RuntimeException('Unexpected DB error'));
    $service->setDi($di);

    $thrown = null;

    try {
        $service->preProcessTransaction($transactionModel);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($transactionModel->status)->toBe(Model_Transaction::STATUS_ERROR)
        ->and($transactionModel->error)->toBe('Unexpected DB error');
});

test('claimForProcessing includes error status in claim query', function (): void {
    $service = new ServiceTransaction();

    $execArgs = [];
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('exec')
        ->withArgs(function (string $sql, array $bindings) use (&$execArgs): bool {
            $execArgs = ['sql' => $sql, 'bindings' => $bindings];

            return true;
        })
        ->once()
        ->andReturn(1);

    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    $result = $service->claimForProcessing(7);

    expect($result)->toBeTrue()
        ->and($execArgs['bindings'])->toContain(Model_Transaction::STATUS_ERROR)
        ->and($execArgs['bindings'])->toContain(Model_Transaction::STATUS_RECEIVED)
        ->and($execArgs['bindings'])->toContain(Model_Transaction::STATUS_PROCESSING)
        ->and($execArgs['sql'])->toContain('IN (?, ?)');
});

test('markTransactionError does not clobber an already processed transaction', function (): void {
    $transactionModel = new Model_Transaction();
    $transactionModel->loadBean(new Tests\Helpers\DummyBean());
    $transactionModel->id = 3;
    $transactionModel->status = Model_Transaction::STATUS_PROCESSED;

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('load')
        ->with('Transaction', 3)
        ->andReturn($transactionModel);
    $dbMock->shouldNotReceive('store');

    $di = container();
    $di['db'] = $dbMock;

    $service = new ServiceTransaction();
    $service->setDi($di);

    $refl = new ReflectionClass($service);
    $method = $refl->getMethod('markTransactionError');
    $method->invoke($service, 3, new RuntimeException('late error'));

    expect($transactionModel->status)->toBe(Model_Transaction::STATUS_PROCESSED);
});

test('refund handling reuses the invoice refund routine', function (): void {
    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceModel->currency = 'USD';

    $transactionModel = new Model_Transaction();
    $transactionModel->loadBean(new Tests\Helpers\DummyBean());
    $transactionModel->id = 5;
    $transactionModel->status = Model_Transaction::STATUS_APPROVED;
    $transactionModel->invoice_id = 7;
    $transactionModel->currency = 'USD';
    $transactionModel->txn_status = 'complete';

    $invoiceServiceMock = Mockery::mock(ClientFence\Mod\Invoice\Service::class);
    $invoiceServiceMock->shouldReceive('refundInvoice')
        ->once()
        ->with($invoiceModel, Mockery::type('string'))
        ->andReturn(9);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('load')
        ->with('Invoice', 7)
        ->andReturn($invoiceModel);
    $dbMock->shouldReceive('store')
        ->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $invoiceServiceMock);

    $service = new ServiceTransaction();
    $service->setDi($di);

    $refl = new ReflectionClass($service);
    $method = $refl->getMethod('_refund');
    $result = $method->invoke($service, $transactionModel);

    expect($result)->toBe($transactionModel);
    expect($transactionModel->status)->toBe(Model_Transaction::STATUS_PROCESSED);
});

test('custom transfers only credit the client when the invoice is no longer unpaid', function (string $status, float $amount, bool $deposit): void {
    $tx = new Model_Transaction();
    $tx->loadBean(new Tests\Helpers\DummyBean());
    $tx->id = 8;
    $tx->invoice_id = 9;
    $tx->gateway_id = 1;
    $tx->status = Model_Transaction::STATUS_RECEIVED;
    $tx->amount = $amount;
    $tx->currency = 'USD';
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    $invoice->id = 9;
    $invoice->client_id = 5;
    $invoice->currency = 'USD';
    $invoice->status = $status;
    $gateway = new Model_PayGateway();
    $gateway->loadBean(new Tests\Helpers\DummyBean());
    $gateway->name = 'Bank';
    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());

    $db = Mockery::mock(ClientFence\Database::class);
    $db->shouldReceive('getExistingModelById')->with('Transaction', 8)->andReturn($tx);
    $db->shouldReceive('getExistingModelById')->with('Invoice', 9)->andReturn($invoice);
    $db->shouldReceive('getCell')->with('SELECT currency FROM client WHERE id = :id', ['id' => 5])->andReturn('USD');
    $db->shouldReceive('load')->with('PayGateway', 1)->andReturn($gateway);
    $db->shouldReceive('store')->once()->with($tx)->andReturn(8);
    $clients = Mockery::mock();
    $clients->shouldReceive('get')->with(['id' => 5])->andReturn($client);
    $clients->shouldReceive('addFunds')->once()->with($client, $amount, Mockery::any(), []);
    $invoices = Mockery::mock();
    $invoices->shouldReceive('getTotalWithTax')->with($invoice)->andReturn(10.0);
    $invoices->shouldReceive('isInvoiceTypeDeposit')->with($invoice)->andReturn($deposit);
    $invoices->shouldNotReceive('markAsPaid');
    $invoices->shouldNotReceive('tryPayWithCredits');
    $di = container();
    $di['db'] = $db;
    $di['mod_service'] = $di->protect(fn (string $name) => $name === 'Client' ? $clients : $invoices);
    $adapter = new Payment_Adapter_Custom([]);
    $adapter->setDi($di);

    expect($adapter->processTransaction((new ReflectionClass(ClientFence\Api\Proxy::class))->newInstanceWithoutConstructor(), 8, ['source' => 'admin'], 1))->toBe(8)
        ->and($invoice->status)->toBe($status)
        ->and($tx->status)->toBe(Model_Transaction::STATUS_PROCESSED)
        ->and($tx->amount)->toBe($amount);
})->with([Model_Invoice::STATUS_REFUNDED, Model_Invoice::STATUS_CANCELED, Model_Invoice::STATUS_PAID])
    ->with([6.0, 10.0])->with([false, true]);

test('sums a client\'s processed payments and refunds per currency', function (): void {
    $db = Mockery::mock(ClientFence\Database::class);
    $db->shouldReceive('getAll')
        ->once()
        ->with(Mockery::type('string'), [5, 'processed'])
        ->andReturn([
            ['cur' => 'EUR', 'total_in' => null, 'total_out' => '0.00'],
            ['cur' => 'USD', 'total_in' => '105.00', 'total_out' => '0.01'],
        ]);
    $di = container();
    $di['db'] = $db;
    $service = new ServiceTransaction();
    $service->setDi($di);

    expect($service->getClientTotals(5))->toBe([
        ['currency' => 'EUR', 'in' => 0.0, 'out' => 0.0, 'balance' => 0.0],
        ['currency' => 'USD', 'in' => 105.0, 'out' => 0.01, 'balance' => 104.99],
    ]);
});
