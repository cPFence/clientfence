<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use ClientFence\Mod\Invoice\Api\Admin;
use ClientFence\Mod\Invoice\Service;
use ClientFence\Mod\Invoice\ServicePayGateway;
use ClientFence\Mod\Invoice\ServiceSubscription;
use ClientFence\Mod\Invoice\ServiceTax;
use ClientFence\Mod\Invoice\ServiceTransaction;

use function Tests\Helpers\container;
use function Tests\Helpers\moduleService;

test('invoice view does not grant support report access or list support reports', function (): void {
    $di = container();
    $staff = Mockery::mock(ClientFence\Mod\Staff\Service::class)->makePartial();
    $staff->shouldReceive('hasPermission')->andReturnUsing(
        static fn ($member, string $module): bool => $module !== 'support'
    );
    $di['mod_service'] = $di->protect(static fn () => $staff);
    $api = new Admin();
    $api->setDi($di);
    $api->setIdentity(new Model_Admin());

    foreach (['report', 'report_csv'] as $method) {
        expect(fn () => $api->{$method}(['report' => 'ticket_replies']))->toThrow(
            fn (ClientFence\InformationException $exception) => expect([$exception->getCode(), $exception->getMessage()])
                ->toBe([403, 'You need the "support.view" permission to perform this action'])
        );
    }
    $catalog = $api->report_catalog([]);
    expect($catalog)->not->toHaveKey('support')
        ->and($catalog['billing']['reports'])->toHaveKey('statement');
});

test('gets dependency injection container', function (): void {
    $api = apiEndpoint(new Admin());
    $di = container();
    $api->setDi($di);
    $getDi = $api->getDi();
    expect($getDi)->toBe($di);
});

test('gets invoice list', function (): void {
    $api = apiEndpoint(new Admin());
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('getSearchQuery')
        ->atLeast()->once()
        ->andReturn(['SqlString', []]);

    $paginatorMock = Mockery::mock(ClientFence\Pagination::class);
    $paginatorMock->shouldReceive('getDefaultPerPage')
        ->byDefault()
        ->andReturn(25);
    $paginatorMock->shouldReceive('getPaginatedResultSet')
        ->atLeast()->once()
        ->andReturn(['list' => []]);

    $di = container();
    $di['pager'] = $paginatorMock;

    $api->setDi($di);
    $api->setService($serviceMock);
    $result = $api->get_list([]);
    expect($result)->toBeArray();
});

test('gets invoice summaries without loading invoice models', function (): void {
    $api = apiEndpoint(new Admin());
    $summary = ['id' => 1, 'total' => 10.0];

    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('getSearchQuery')
        ->once()
        ->andReturn(['SqlString', []]);
    $serviceMock->shouldReceive('toApiSummaryArray')
        ->once()
        ->with(['id' => 1])
        ->andReturn($summary);

    $paginatorMock = Mockery::mock(ClientFence\Pagination::class);
    $paginatorMock->shouldReceive('getDefaultPerPage')
        ->byDefault()
        ->andReturn(25);
    $paginatorMock->shouldReceive('getPaginatedResultSet')
        ->once()
        ->andReturn(['list' => [['id' => 1]]]);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldNotReceive('getExistingModelById');

    $di = container();
    $di['pager'] = $paginatorMock;
    $di['db'] = $dbMock;

    $api->setDi($di);
    $api->setService($serviceMock);

    $result = $api->get_list(['summary' => 1]);

    expect($result['list'])->toBe([$summary]);
});

test('gets an invoice', function (): void {
    $api = apiEndpoint(new Admin());
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('toApiArray')
        ->atLeast()->once()
        ->andReturn([]);

    $dbMock = Mockery::mock('ClientFence\Database');
    $model = new Model_Invoice();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($model);

    $di = container();
    $di['db'] = $dbMock;

    $api->setDi($di);
    $api->setService($serviceMock);
    $api->setIdentity(new Model_Admin());

    $data['id'] = 1;
    $result = $api->get($data);
    expect($result)->toBeArray();
});

test('marks invoice as paid', function (): void {
    $api = apiEndpoint(new Admin());
    $data = [
        'id' => 1,
        'execute' => true,
    ];

    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('markAsPaidByAdmin')
        ->atLeast()->once()
        ->andReturn(true);
    $serviceMock->shouldReceive('isInvoiceTypeDeposit')
        ->atLeast()->once()
        ->andReturn(false);

    $gatewayServiceMock = Mockery::mock(ServicePayGateway::class);
    $gatewayServiceMock->shouldReceive('toApiArray')
        ->byDefault()
        ->andReturn(['code' => 'PayPal', 'enabled' => 1]);

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceModel->gateway_id = '1';

    $gatewayModel = new Model_PayGateway();
    $gatewayModel->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturnUsing(function ($type) use ($invoiceModel, $gatewayModel) {
            if ($type === 'PayGateway') {
                return $gatewayModel;
            }

            return $invoiceModel;
        });

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(moduleService([
        'invoice' => $serviceMock,
        'invoice:paygateway' => $gatewayServiceMock,
    ]));
    $api->setDi($di);
    $api->setService($serviceMock);

    $result = $api->mark_as_paid($data);
    expect($result)->toBeTrue();
});

test('marking a deposit invoice as paid requires the balance permission', function (): void {
    $api = apiEndpoint(new Admin());
    $data = [
        'id' => 1,
        'execute' => true,
    ];

    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('isInvoiceTypeDeposit')
        ->once()
        ->andReturn(true);
    $serviceMock->shouldReceive('markAsPaidByAdmin')->never();

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceModel->gateway_id = '1';

    $staffServiceMock = Mockery::mock(ClientFence\Mod\Staff\Service::class);
    $staffServiceMock->shouldReceive('checkPermissionsAndThrowException')
        ->byDefault()
        ->andReturn(true);
    $staffServiceMock->shouldReceive('checkPermissionsAndThrowException')
        ->once()
        ->with('client', 'manage_balance', null, Mockery::any())
        ->andThrow(new ClientFence\InformationException('You do not have permission to manage the client balance', [], 403));

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($invoiceModel);

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(moduleService([
        'invoice' => $serviceMock,
        'staff' => $staffServiceMock,
    ]));
    $api->setDi($di);
    $api->setService($serviceMock);

    expect(fn (): bool => $api->mark_as_paid($data))
        ->toThrow(ClientFence\InformationException::class, 'client balance');
});

test('prepares an invoice', function (): void {
    $api = apiEndpoint(new Admin());
    $data = [
        'client_id' => 1,
    ];
    $newInvoiceId = 1;

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceModel->id = $newInvoiceId;

    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('prepareInvoice')
        ->atLeast()->once()
        ->andReturn($invoiceModel);

    $dbMock = Mockery::mock('ClientFence\Database');
    $model = new Model_Client();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($model);

    $di = container();
    $di['db'] = $dbMock;

    $api->setDi($di);
    $api->setService($serviceMock);

    $result = $api->prepare($data);
    expect($result)->toBeInt()->toBe($newInvoiceId);
});

test('approves an invoice', function (): void {
    $api = apiEndpoint(new Admin());
    $data = [
        'id' => 1,
    ];

    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('approveInvoice')
        ->atLeast()->once()
        ->andReturn(true);

    $dbMock = Mockery::mock('ClientFence\Database');
    $model = new Model_Invoice();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($model);

    $di = container();
    $di['db'] = $dbMock;

    $api->setDi($di);
    $api->setService($serviceMock);

    $result = $api->approve($data);
    expect($result)->toBeBool()->toBeTrue();
});

test('refunds an invoice', function (): void {
    $api = apiEndpoint(new Admin());
    $data = [
        'id' => 1,
    ];
    $newNegativeInvoiceId = 2;
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('refundInvoice')
        ->atLeast()->once()
        ->andReturn($newNegativeInvoiceId);

    $dbMock = Mockery::mock('ClientFence\Database');
    $model = new Model_Invoice();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($model);

    $di = container();
    $di['db'] = $dbMock;

    $api->setDi($di);
    $api->setService($serviceMock);

    $result = $api->refund($data);
    expect($result)->toBeInt()->toBe($newNegativeInvoiceId);
});

test('updates an invoice', function (): void {
    $api = apiEndpoint(new Admin());
    $data = [
        'id' => 1,
    ];

    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('updateInvoice')
        ->atLeast()->once()
        ->andReturn(true);

    $dbMock = Mockery::mock('ClientFence\Database');
    $model = new Model_Invoice();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($model);

    $di = container();
    $di['db'] = $dbMock;

    $api->setDi($di);
    $api->setService($serviceMock);

    $result = $api->update($data);
    expect($result)->toBeBool()->toBeTrue();
});

test('updates an invoice before approving it', function (): void {
    $api = apiEndpoint(new Admin());
    $data = [
        'id' => 1,
        'approve' => 1,
        'new_item' => [
            'title' => 'Hosting',
            'quantity' => 1,
            'price' => '10.00',
        ],
    ];
    $model = new Model_Invoice();
    $model->loadBean(new Tests\Helpers\DummyBean());

    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('updateInvoice')
        ->once()
        ->ordered()
        ->with($model, $data)
        ->andReturn(true);
    $serviceMock->shouldReceive('approveInvoice')
        ->once()
        ->ordered()
        ->with($model, $data)
        ->andReturn(true);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getExistingModelById')
        ->once()
        ->andReturn($model);

    $di = container();
    $di['db'] = $dbMock;

    $api->setDi($di);
    $api->setService($serviceMock);

    $result = $api->update($data);
    expect($result)->toBeTrue();
});

test('deletes an invoice item', function (): void {
    $api = apiEndpoint(new Admin());
    $data = [
        'id' => 1,
    ];

    $invoiceItemService = Mockery::mock(ClientFence\Mod\Invoice\ServiceInvoiceItem::class);
    $invoiceItemService->shouldReceive('remove')
        ->atLeast()->once()
        ->andReturn(true);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('load')->with('Invoice', Mockery::any())->andReturn(null);
    $model = new Model_InvoiceItem();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($model);

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(moduleService(['invoice:invoiceitem' => $invoiceItemService]));

    $api->setDi($di);

    $result = $api->item_delete($data);
    expect($result)->toBeBool()->toBeTrue();
});

test('deletes an invoice', function (): void {
    $api = apiEndpoint(new Admin());
    $data = [
        'id' => 1,
    ];

    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('deleteInvoiceByAdmin')
        ->atLeast()->once()
        ->andReturn(true);

    $dbMock = Mockery::mock('ClientFence\Database');
    $model = new Model_Invoice();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($model);

    $di = container();
    $di['db'] = $dbMock;

    $api->setDi($di);
    $api->setService($serviceMock);

    $result = $api->delete($data);
    expect($result)->toBeBool()->toBeTrue();
});

test('creates renewal invoice', function (): void {
    $api = apiEndpoint(new Admin());
    $data = [
        'id' => 1,
    ];
    $newInvoiceId = 3;
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('renewInvoice')
        ->atLeast()->once()
        ->andReturn($newInvoiceId);

    $dbMock = Mockery::mock('ClientFence\Database');
    $model = new Model_ClientOrder();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->price = 10;
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($model);

    $di = container();
    $di['db'] = $dbMock;

    $api->setDi($di);
    $api->setService($serviceMock);

    $result = $api->renewal_invoice($data);
    expect($result)->toBeInt()->toBe($newInvoiceId);
});

test('creates renewal invoice for free order', function (): void {
    $api = apiEndpoint(new Admin());
    $data = [
        'id' => 1,
    ];
    $newInvoiceId = 3;
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('renewInvoice')
        ->atLeast()->once()
        ->andReturn($newInvoiceId);

    $dbMock = Mockery::mock('ClientFence\Database');
    $model = new Model_ClientOrder();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->id = 1;
    $model->price = 0;
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($model);

    $di = container();
    $di['db'] = $dbMock;

    $api->setDi($di);
    $api->setService($serviceMock);

    $result = $api->renewal_invoice($data);
    expect($result)->toBeInt()->toBe($newInvoiceId);
});

test('processes batch pay with credits', function (): void {
    $api = apiEndpoint(new Admin());
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('doBatchPayWithCredits')
        ->atLeast()->once()
        ->andReturn(true);

    $api->setService($serviceMock);

    $result = $api->batch_pay_with_credits([]);
    expect($result)->toBeBool()->toBeTrue();
});

test('pays invoice with credits', function (): void {
    $api = apiEndpoint(new Admin());
    $data = [
        'id' => 1,
    ];

    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('isInvoiceTypeDeposit')->once()->andReturn(false);
    $serviceMock->shouldReceive('payInvoiceWithCredits')
        ->atLeast()->once()
        ->andReturn(true);

    $dbMock = Mockery::mock('ClientFence\Database');
    $model = new Model_Invoice();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($model);

    $di = container();
    $di['db'] = $dbMock;

    $api->setDi($di);
    $api->setService($serviceMock);

    $result = $api->pay_with_credits($data);
    expect($result)->toBeBool()->toBeTrue();
});

test('staff cannot pay an Add funds invoice with account credit', function (): void {
    $api = apiEndpoint(new Admin());
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    $service = Mockery::mock(Service::class);
    $service->shouldReceive('isInvoiceTypeDeposit')->once()->with($invoice)->andReturn(true);
    $service->shouldNotReceive('payInvoiceWithCredits');
    $db = Mockery::mock(ClientFence\Database::class);
    $db->shouldReceive('getExistingModelById')->with('Invoice', 1, 'Invoice was not found')->andReturn($invoice);
    $di = container();
    $di['db'] = $db;
    $api->setDi($di);
    $api->setService($service);

    expect(fn () => $api->pay_with_credits(['id' => 1]))
        ->toThrow(ClientFence\InformationException::class, 'Client credit: You may not pay a deposit invoice with this payment gateway.');
});

test('generates batch invoices', function (): void {
    $api = apiEndpoint(new Admin());
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('generateInvoicesForExpiringOrders')
        ->atLeast()->once()
        ->andReturn(true);

    $api->setService($serviceMock);

    $result = $api->batch_generate();
    expect($result)->toBeBool()->toBeTrue();
});

test('activates paid invoices in batch', function (): void {
    $api = apiEndpoint(new Admin());
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('doBatchPaidInvoiceActivation')
        ->atLeast()->once()
        ->andReturn(true);

    $api->setService($serviceMock);

    $result = $api->batch_activate_paid();
    expect($result)->toBeBool()->toBeTrue();
});

test('sends reminders in batch', function (): void {
    $api = apiEndpoint(new Admin());
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('doBatchRemindersSend')
        ->atLeast()->once()
        ->andReturn(true);

    $api->setService($serviceMock);

    $result = $api->batch_send_reminders([]);
    expect($result)->toBeBool()->toBeTrue();
});

test('invokes due event in batch', function (): void {
    $api = apiEndpoint(new Admin());
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('doBatchInvokeDueEvent')
        ->atLeast()->once()
        ->andReturn(true);

    $api->setService($serviceMock);

    $result = $api->batch_invoke_due_event([]);
    expect($result)->toBeBool()->toBeTrue();
});

test('sends reminder for an invoice', function (): void {
    $api = apiEndpoint(new Admin());
    $data = [
        'id' => 1,
    ];

    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('sendInvoiceReminder')
        ->atLeast()->once()
        ->andReturn(true);

    $dbMock = Mockery::mock('ClientFence\Database');
    $model = new Model_Invoice();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($model);

    $di = container();
    $di['db'] = $dbMock;

    $api->setDi($di);
    $api->setService($serviceMock);

    $result = $api->send_reminder($data);
    expect($result)->toBeBool()->toBeTrue();
});

test('gets invoice statuses', function (): void {
    $api = apiEndpoint(new Admin());
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('counter')
        ->atLeast()->once()
        ->andReturn([]);

    $api->setService($serviceMock);

    $result = $api->get_statuses([]);
    expect($result)->toBeArray();
});

test('processes all transactions', function (): void {
    $api = apiEndpoint(new Admin());
    $transactionService = Mockery::mock(ServiceTransaction::class);
    $transactionService->shouldReceive('processReceivedATransactions')
        ->atLeast()->once()
        ->andReturn(true);

    $di = container();
    $di['mod_service'] = $di->protect(moduleService(['invoice:transaction' => $transactionService]));

    $api->setDi($di);
    $result = $api->transaction_process_all([]);
    expect($result)->toBeBool()->toBeTrue();
});

test('processes a transaction', function (): void {
    $api = apiEndpoint(new Admin());
    $data = [
        'id' => 1,
    ];

    $transactionService = Mockery::mock(ServiceTransaction::class);
    $transactionService->shouldReceive('preProcessTransaction')
        ->atLeast()->once()
        ->andReturn(true);

    $dbMock = Mockery::mock('ClientFence\Database');
    $model = new Model_Transaction();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($model);

    $eventsMock = Mockery::mock('ClientFence\EventManager');
    $eventsMock->shouldReceive('fire')
        ->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['events_manager'] = $eventsMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['mod_service'] = $di->protect(moduleService(['invoice:transaction' => $transactionService]));

    $api->setDi($di);

    $result = $api->transaction_process($data);
    expect($result)->toBeBool()->toBeTrue();
});

test('updates a transaction', function (): void {
    $api = apiEndpoint(new Admin());
    $data = [
        'id' => 1,
    ];

    $transactionService = Mockery::mock(ServiceTransaction::class);
    $transactionService->shouldReceive('update')
        ->atLeast()->once()
        ->andReturn(true);

    $dbMock = Mockery::mock('ClientFence\Database');
    $model = new Model_Transaction();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($model);

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(moduleService(['invoice:transaction' => $transactionService]));

    $api->setDi($di);

    $result = $api->transaction_update($data);
    expect($result)->toBeBool()->toBeTrue();
});

test('a staff-created manual transaction pays the invoice and runs its renewal without cron', function (): void {
    $api = apiEndpoint(new Admin());
    $invoice = Tests\Helpers\model(Model_Invoice::class, ['id' => 10, 'client_id' => 5, 'currency' => 'USD', 'status' => Model_Invoice::STATUS_UNPAID]);
    $item = Tests\Helpers\model(Model_InvoiceItem::class, ['id' => 11, 'invoice_id' => 10, 'type' => Model_InvoiceItem::TYPE_ORDER, 'rel_id' => 12, 'task' => Model_InvoiceItem::TASK_RENEW, 'price' => 62, 'quantity' => 1]);
    $order = Tests\Helpers\model(Model_ClientOrder::class, ['id' => 12, 'status' => Model_ClientOrder::STATUS_ACTIVE]);
    $gateway = Tests\Helpers\model(Model_PayGateway::class, ['id' => 3, 'gateway' => 'Custom', 'name' => 'Bank transfer']);
    $client = Tests\Helpers\model(Model_Client::class, ['id' => 5]);
    $transaction = Tests\Helpers\model(Model_Transaction::class, ['id' => 20]);
    $models = ['Invoice' => $invoice, 'ClientOrder' => $order, 'PayGateway' => $gateway, 'Client' => $client, 'Transaction' => $transaction];

    $db = Mockery::mock(ClientFence\Database::class);
    $db->shouldReceive('getExistingModelById')->andReturnUsing(fn (string $type) => $models[$type]);
    $db->shouldReceive('load')->andReturnUsing(fn (string $type) => $models[$type]);
    $db->shouldReceive('dispense')->with('Transaction')->once()->andReturn($transaction);
    $db->shouldReceive('find')->with('InvoiceItem', 'invoice_id = ?', [10])->andReturn([$item]);
    $db->shouldReceive('getCell')->with('SELECT currency FROM client WHERE id = :id', ['id' => 5])->andReturn('USD');
    $db->shouldReceive('store')->andReturnUsing(static fn (object $model): int => (int) $model->id);

    $transactionService = new ServiceTransaction();
    $invoices = Mockery::mock(Service::class)->makePartial();
    $invoices->shouldReceive('countIncome')->once()->with($invoice);
    $items = Mockery::mock(ClientFence\Mod\Invoice\ServiceInvoiceItem::class)->makePartial();
    $items->shouldReceive('creditInvoiceItem')->once()->with($item);
    $orders = Mockery::mock(ClientFence\Mod\Order\Service::class);
    $orders->shouldReceive('unsetUnpaidInvoice')->once()->with($order);
    $orders->shouldReceive('renewOrder')->once()->with($order)->andReturn(true);
    $clients = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $clients->shouldReceive('get')->with(['id' => 5])->andReturn($client);
    $clients->shouldReceive('addFunds')->once()->with($client, 62.0, Mockery::any(), []);
    $adapter = new Payment_Adapter_Custom([]);
    $gateways = Mockery::mock(ServicePayGateway::class);
    $gateways->shouldReceive('getPaymentAdapter')->with($gateway)->andReturn($adapter);
    $system = Mockery::mock(ClientFence\Mod\System\Service::class);
    $system->shouldReceive('getParamValue')->with('invoice_series_paid')->andReturn('');
    $rates = Mockery::mock(ClientFence\Mod\Currency\Repository\CurrencyRepository::class);
    $rates->shouldReceive('getRateByCode')->with('USD')->andReturn(1.0);
    $currency = Mockery::mock(ClientFence\Mod\Currency\Service::class);
    $currency->shouldReceive('getCurrencyRepository')->andReturn($rates);
    $products = Mockery::mock(ClientFence\Mod\Product\Service::class);
    $products->shouldReceive('commitReservedPromoRedemptionsForInvoice')->once()->with($invoice);

    $di = container();
    $di['db'] = $db;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['dbal'] = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $di['dbal']->executeStatement('CREATE TABLE "transaction" (id INTEGER PRIMARY KEY)');
    $di['dbal']->executeStatement('CREATE TABLE invoice_item (id INTEGER PRIMARY KEY, status TEXT)');
    $di['api_system'] = (new ReflectionClass(ClientFence\Api\Proxy::class))->newInstanceWithoutConstructor();
    $di['mod_service'] = $di->protect(moduleService([
        'invoice' => $invoices, 'invoice:transaction' => $transactionService,
        'invoice:invoiceitem' => $items, 'invoice:paygateway' => $gateways,
        'order' => $orders, 'client' => $clients, 'system' => $system,
        'currency' => $currency, 'product' => $products,
    ]));
    foreach ([$transactionService, $invoices, $items, $adapter] as $service) {
        $service->setDi($di);
    }
    $api->setDi($di);

    $result = $api->transaction_create(['invoice_id' => 10, 'gateway_id' => 3, 'amount' => 62, 'currency' => 'USD']);

    expect($result)->toBe(20)
        ->and($transaction->status)->toBe(Model_Transaction::STATUS_PROCESSED)
        ->and($invoice->status)->toBe(Model_Invoice::STATUS_PAID)
        ->and($item->status)->toBe(Model_InvoiceItem::STATUS_EXECUTED)
        ->and($di['events_manager']->events)->toContain(['event' => 'onAfterAdminInvoicePaymentReceived', 'params' => ['id' => 10]]);
});

test('deletes a transaction', function (): void {
    $api = apiEndpoint(new Admin());
    $data = [
        'id' => 1,
    ];

    $transactionService = Mockery::mock(ServiceTransaction::class);
    $transactionService->shouldReceive('delete')
        ->atLeast()->once()
        ->andReturn(true);

    $dbMock = Mockery::mock('ClientFence\Database');
    $model = new Model_Transaction();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($model);

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(moduleService(['invoice:transaction' => $transactionService]));

    $api->setDi($di);

    $result = $api->transaction_delete($data);
    expect($result)->toBeBool()->toBeTrue();
});

test('gets a transaction', function (): void {
    $api = apiEndpoint(new Admin());
    $data = [
        'id' => 1,
    ];

    $transactionService = Mockery::mock(ServiceTransaction::class);
    $transactionService->shouldReceive('toApiArray')
        ->atLeast()->once()
        ->andReturn([]);

    $dbMock = Mockery::mock('ClientFence\Database');
    $model = new Model_Transaction();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($model);

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(moduleService(['invoice:transaction' => $transactionService]));

    $api->setDi($di);

    $result = $api->transaction_get($data);
    expect($result)->toBeArray();
});

test('gets transaction list', function (): void {
    $api = apiEndpoint(new Admin());
    $transactionService = Mockery::mock(ServiceTransaction::class);
    $transactionService->shouldReceive('getSearchQuery')
        ->atLeast()->once()
        ->andReturn(['SqlString', []]);
    $transactionService->shouldReceive('searchResultToApiArray')
        ->once()
        ->with(['id' => 1])
        ->andReturn(['id' => 1, 'gateway' => 'Stripe']);

    $paginatorMock = Mockery::mock(ClientFence\Pagination::class);
    $paginatorMock->shouldReceive('getDefaultPerPage')
        ->byDefault()
        ->andReturn(25);
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
    $result = $api->transaction_get_list([]);
    expect($result['list'])->toBe([['id' => 1, 'gateway' => 'Stripe']]);
});

test('gets transaction statuses', function (): void {
    $api = apiEndpoint(new Admin());
    $transactionService = Mockery::mock(ServiceTransaction::class);
    $transactionService->shouldReceive('counter')
        ->atLeast()->once()
        ->andReturn([]);

    $di = container();
    $di['mod_service'] = $di->protect(moduleService(['invoice:transaction' => $transactionService]));

    $api->setDi($di);

    $result = $api->transaction_get_statuses([]);
    expect($result)->toBeArray();
});

test('gets transaction status pairs', function (): void {
    $api = apiEndpoint(new Admin());
    $transactionService = Mockery::mock(ServiceTransaction::class);
    $transactionService->shouldReceive('getStatusPairs')
        ->atLeast()->once()
        ->andReturn([]);

    $di = container();
    $di['mod_service'] = $di->protect(moduleService(['invoice:transaction' => $transactionService]));

    $api->setDi($di);

    $result = $api->transaction_get_statuses_pairs([]);
    expect($result)->toBeArray();
});

test('gets transaction statuses list', function (): void {
    $api = apiEndpoint(new Admin());
    $transactionService = Mockery::mock(ServiceTransaction::class);
    $transactionService->shouldReceive('getStatuses')
        ->atLeast()->once()
        ->andReturn([]);

    $di = container();
    $di['mod_service'] = $di->protect(moduleService(['invoice:transaction' => $transactionService]));

    $api->setDi($di);

    $result = $api->transaction_statuses([]);
    expect($result)->toBeArray();
});

test('gets transaction gateway statuses', function (): void {
    $api = apiEndpoint(new Admin());
    $transactionService = Mockery::mock(ServiceTransaction::class);
    $transactionService->shouldReceive('getGatewayStatuses')
        ->atLeast()->once()
        ->andReturn([]);

    $di = container();
    $di['mod_service'] = $di->protect(moduleService(['invoice:transaction' => $transactionService]));

    $api->setDi($di);

    $result = $api->transaction_gateway_statuses([]);
    expect($result)->toBeArray();
});

test('gets transaction types', function (): void {
    $api = apiEndpoint(new Admin());
    $transactionService = Mockery::mock(ServiceTransaction::class);
    $transactionService->shouldReceive('getTypes')
        ->atLeast()->once()
        ->andReturn([]);

    $di = container();
    $di['mod_service'] = $di->protect(moduleService(['invoice:transaction' => $transactionService]));

    $api->setDi($di);

    $result = $api->transaction_types([]);
    expect($result)->toBeArray();
});

test('gets gateway list', function (): void {
    $api = apiEndpoint(new Admin());
    $gatewayService = Mockery::mock(ServicePayGateway::class);
    $gatewayService->shouldReceive('getSearchQuery')
        ->atLeast()->once()
        ->andReturn(['SqlString', []]);

    $paginatorMock = Mockery::mock(ClientFence\Pagination::class);
    $paginatorMock->shouldReceive('getDefaultPerPage')
        ->byDefault()
        ->andReturn(25);
    $paginatorMock->shouldReceive('getPaginatedResultSet')
        ->atLeast()->once()
        ->andReturn(['list' => []]);

    $di = container();
    $di['pager'] = $paginatorMock;
    $di['mod_service'] = $di->protect(moduleService(['invoice:paygateway' => $gatewayService]));

    $api->setDi($di);
    $result = $api->gateway_get_list([]);
    expect($result)->toBeArray();
});

test('gets gateway pairs', function (): void {
    $api = apiEndpoint(new Admin());
    $gatewayService = Mockery::mock(ServicePayGateway::class);
    $gatewayService->shouldReceive('getPairs')
        ->atLeast()->once()
        ->andReturn([]);

    $di = container();
    $di['mod_service'] = $di->protect(moduleService(['invoice:paygateway' => $gatewayService]));
    $api->setDi($di);

    $result = $api->gateway_get_pairs([]);
    expect($result)->toBeArray();
});

test('gets available gateways', function (): void {
    $api = apiEndpoint(new Admin());
    $gatewayService = Mockery::mock(ServicePayGateway::class);
    $gatewayService->shouldReceive('getAvailable')
        ->atLeast()->once()
        ->andReturn([]);

    $di = container();
    $di['mod_service'] = $di->protect(moduleService(['invoice:paygateway' => $gatewayService]));
    $api->setDi($di);

    $result = $api->gateway_get_available([]);
    expect($result)->toBeArray();
});

test('installs a gateway', function (): void {
    $api = apiEndpoint(new Admin());
    $data = [
        'code' => 'PP',
    ];

    $gatewayService = Mockery::mock(ServicePayGateway::class);
    $gatewayService->shouldReceive('install')
        ->atLeast()->once()
        ->andReturn(true);

    $di = container();
    $di['mod_service'] = $di->protect(moduleService(['invoice:paygateway' => $gatewayService]));
    $api->setDi($di);

    $result = $api->gateway_install($data);
    expect($result)->toBeBool()->toBeTrue();
});

test('gets a gateway', function (): void {
    $api = apiEndpoint(new Admin());
    $data = [
        'id' => 1,
    ];

    $gatewayService = Mockery::mock(ServicePayGateway::class);
    $gatewayService->shouldReceive('toApiArray')
        ->atLeast()->once()
        ->andReturn([]);

    $dbMock = Mockery::mock('ClientFence\Database');
    $model = new Model_PayGateway();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($model);

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(moduleService(['invoice:paygateway' => $gatewayService]));

    $api->setDi($di);

    $result = $api->gateway_get($data);
    expect($result)->toBeArray();
});

test('copies a gateway', function (): void {
    $api = apiEndpoint(new Admin());
    $data = [
        'id' => 1,
    ];
    $newGatewayId = 1;
    $gatewayService = Mockery::mock(ServicePayGateway::class);
    $gatewayService->shouldReceive('copy')
        ->atLeast()->once()
        ->andReturn($newGatewayId);

    $dbMock = Mockery::mock('ClientFence\Database');
    $model = new Model_PayGateway();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($model);

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(moduleService(['invoice:paygateway' => $gatewayService]));

    $api->setDi($di);

    $result = $api->gateway_copy($data);
    expect($result)->toBeInt()->toBe($newGatewayId);
});

test('updates a gateway', function (): void {
    $api = apiEndpoint(new Admin());
    $data = [
        'id' => 1,
    ];

    $gatewayService = Mockery::mock(ServicePayGateway::class);
    $gatewayService->shouldReceive('update')
        ->atLeast()->once()
        ->andReturn(true);

    $dbMock = Mockery::mock('ClientFence\Database');
    $model = new Model_PayGateway();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($model);

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(moduleService(['invoice:paygateway' => $gatewayService]));

    $api->setDi($di);

    $result = $api->gateway_update($data);
    expect($result)->toBeBool()->toBeTrue();
});

test('deletes a gateway', function (): void {
    $api = apiEndpoint(new Admin());
    $data = [
        'id' => 1,
    ];

    $gatewayService = Mockery::mock(ServicePayGateway::class);
    $gatewayService->shouldReceive('delete')
        ->atLeast()->once()
        ->andReturn(true);

    $dbMock = Mockery::mock('ClientFence\Database');
    $model = new Model_PayGateway();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($model);

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(moduleService(['invoice:paygateway' => $gatewayService]));

    $api->setDi($di);

    $result = $api->gateway_delete($data);
    expect($result)->toBeBool()->toBeTrue();
});

test('gets subscription list', function (): void {
    $api = apiEndpoint(new Admin());
    $subscriptionService = Mockery::mock(ServiceSubscription::class);
    $subscriptionService->shouldReceive('getSearchQuery')
        ->atLeast()->once()
        ->andReturn(['SqlString', []]);

    $paginatorMock = Mockery::mock(ClientFence\Pagination::class);
    $paginatorMock->shouldReceive('getDefaultPerPage')
        ->byDefault()
        ->andReturn(25);
    $paginatorMock->shouldReceive('getPaginatedResultSet')
        ->atLeast()->once()
        ->andReturn([]);

    $di = container();
    $di['pager'] = $paginatorMock;
    $di['mod_service'] = $di->protect(moduleService(['invoice:subscription' => $subscriptionService]));

    $api->setDi($di);
    $result = $api->subscription_get_list([]);
    expect($result)->toBeArray();
});

test('creates a subscription', function (): void {
    $api = apiEndpoint(new Admin());
    $data = [
        'client_id' => 1,
        'gateway_id' => 1,
        'currency' => 'EU',
    ];
    $newSubscriptionId = 1;
    $subscriptionService = Mockery::mock(ServiceSubscription::class);
    $subscriptionService->shouldReceive('create')
        ->atLeast()->once()
        ->andReturn($newSubscriptionId);

    $dbMock = Mockery::mock('ClientFence\Database');
    $model = new Model_PayGateway();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->currency = 'EU';

    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($client, $model);

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(moduleService(['invoice:subscription' => $subscriptionService]));

    $api->setDi($di);

    $result = $api->subscription_create($data);
    expect($result)->toBeInt()->toBe($newSubscriptionId);
});

test('throws exception when creating subscription with currency mismatch', function (): void {
    $api = apiEndpoint(new Admin());
    $data = [
        'client_id' => 1,
        'gateway_id' => 1,
        'currency' => 'EU',
    ];

    $dbMock = Mockery::mock('ClientFence\Database');
    $model = new Model_PayGateway();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());

    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($client, $model);

    $di = container();
    $di['db'] = $dbMock;

    $api->setDi($di);

    expect(fn () => $api->subscription_create($data))
        ->toThrow(ClientFence\Exception::class, 'Client currency must match subscription currency. Check if clients currency is defined.');
});

test('creates a subscription with case-insensitive currency match', function (): void {
    $api = apiEndpoint(new Admin());
    $data = [
        'client_id' => 1,
        'gateway_id' => 1,
        'currency' => 'usd',
    ];
    $newSubscriptionId = 2;
    $subscriptionService = Mockery::mock(ServiceSubscription::class);
    $subscriptionService->shouldReceive('create')
        ->atLeast()->once()
        ->andReturn($newSubscriptionId);

    $dbMock = Mockery::mock('ClientFence\Database');
    $model = new Model_PayGateway();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->currency = 'USD';

    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($client, $model);

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(moduleService(['invoice:subscription' => $subscriptionService]));

    $api->setDi($di);

    $result = $api->subscription_create($data);
    expect($result)->toBeInt()->toBe($newSubscriptionId);
});

test('updates a subscription', function (): void {
    $api = apiEndpoint(new Admin());
    $data = [
        'id' => 1,
    ];

    $subscriptionService = Mockery::mock(ServiceSubscription::class);
    $subscriptionService->shouldReceive('update')
        ->atLeast()->once()
        ->andReturn(true);

    $dbMock = Mockery::mock('ClientFence\Database');
    $model = new Model_Subscription();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($model);

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(moduleService(['invoice:subscription' => $subscriptionService]));

    $api->setDi($di);

    $result = $api->subscription_update($data);
    expect($result)->toBeBool()->toBeTrue();
});

test('gets a subscription', function (): void {
    $api = apiEndpoint(new Admin());
    $data = [
        'id' => 1,
    ];

    $subscriptionService = Mockery::mock(ServiceSubscription::class);
    $subscriptionService->shouldReceive('toApiArray')
        ->atLeast()->once()
        ->andReturn([]);

    $dbMock = Mockery::mock('ClientFence\Database');
    $model = new Model_Subscription();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('load')
        ->atLeast()->once()
        ->andReturn($model);

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(moduleService(['invoice:subscription' => $subscriptionService]));

    $api->setDi($di);

    $result = $api->subscription_get($data);
    expect($result)->toBeArray();
});

test('deletes a subscription', function (): void {
    $api = apiEndpoint(new Admin());
    $data = [
        'id' => 1,
    ];

    $subscriptionService = Mockery::mock(ServiceSubscription::class);
    $subscriptionService->shouldReceive('delete')
        ->atLeast()->once()
        ->andReturn(true);

    $dbMock = Mockery::mock('ClientFence\Database');
    $model = new Model_Subscription();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($model);

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(moduleService(['invoice:subscription' => $subscriptionService]));

    $api->setDi($di);

    $result = $api->subscription_delete($data);
    expect($result)->toBeBool()->toBeTrue();
});

test('deletes a tax', function (): void {
    $api = apiEndpoint(new Admin());
    $data = [
        'id' => 1,
    ];

    $taxService = Mockery::mock(ServiceTax::class);
    $taxService->shouldReceive('delete')
        ->atLeast()->once()
        ->andReturn(true);

    $dbMock = Mockery::mock('ClientFence\Database');
    $model = new Model_Tax();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($model);

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(moduleService(['invoice:tax' => $taxService]));

    $api->setDi($di);

    $result = $api->tax_delete($data);
    expect($result)->toBeBool()->toBeTrue();
});

test('creates a tax', function (): void {
    $api = apiEndpoint(new Admin());
    $data = [
        'id' => 1,
    ];
    $newTaxId = 1;
    $taxService = Mockery::mock(ServiceTax::class);
    $taxService->shouldReceive('create')
        ->atLeast()->once()
        ->andReturn($newTaxId);

    $di = container();
    $di['mod_service'] = $di->protect(moduleService(['invoice:tax' => $taxService]));

    $api->setDi($di);

    $result = $api->tax_create($data);
    expect($result)->toBeInt()->toBe($newTaxId);
});

test('gets tax list', function (): void {
    $api = apiEndpoint(new Admin());
    $taxService = Mockery::mock(ServiceTax::class);
    $taxService->shouldReceive('getSearchQuery')
        ->atLeast()->once()
        ->andReturn(['SqlString', []]);

    $paginatorMock = Mockery::mock(ClientFence\Pagination::class);
    $paginatorMock->shouldReceive('getDefaultPerPage')
        ->byDefault()
        ->andReturn(25);
    $paginatorMock->shouldReceive('getPaginatedResultSet')
        ->atLeast()->once()
        ->andReturn([]);

    $di = container();
    $di['pager'] = $paginatorMock;
    $di['mod_service'] = $di->protect(moduleService(['invoice:tax' => $taxService]));

    $api->setDi($di);

    $result = $api->tax_get_list([]);
    expect($result)->toBeArray();
});

test('deletes invoices in batch', function (): void {
    $api = apiEndpoint(new Admin());
    $activityMock = Mockery::mock(Admin::class)->makePartial();
    $activityMock->shouldReceive('delete')->atLeast()->once()->andReturn(true);

    $di = container();
    $activityMock->setDi($di);

    $result = $activityMock->batch_delete(['ids' => [1, 2, 3]]);
    expect($result)->toBeTrue();
});

test('deletes subscriptions in batch', function (): void {
    $api = apiEndpoint(new Admin());
    $activityMock = Mockery::mock(Admin::class)->makePartial();
    $activityMock->shouldReceive('subscription_delete')->atLeast()->once()->andReturn(true);

    $di = container();
    $activityMock->setDi($di);

    $result = $activityMock->batch_delete_subscription(['ids' => [1, 2, 3]]);
    expect($result)->toBeTrue();
});

test('deletes transactions in batch', function (): void {
    $api = apiEndpoint(new Admin());
    $activityMock = Mockery::mock(Admin::class)->makePartial();
    $activityMock->shouldReceive('transaction_delete')->atLeast()->once()->andReturn(true);

    $di = container();
    $activityMock->setDi($di);

    $result = $activityMock->batch_delete_transaction(['ids' => [1, 2, 3]]);
    expect($result)->toBeTrue();
});

test('deletes taxes in batch', function (): void {
    $api = apiEndpoint(new Admin());
    $activityMock = Mockery::mock(Admin::class)->makePartial();
    $activityMock->shouldReceive('tax_delete')->atLeast()->once()->andReturn(true);

    $di = container();
    $activityMock->setDi($di);

    $result = $activityMock->batch_delete_tax(['ids' => [1, 2, 3]]);
    expect($result)->toBeTrue();
});

test('gets a tax', function (): void {
    $api = apiEndpoint(new Admin());
    $taxService = Mockery::mock(ServiceTax::class);
    $taxService->shouldReceive('toApiArray')
        ->atLeast()->once()
        ->andReturn([]);

    $dbMock = Mockery::mock('ClientFence\Database');
    $model = new Model_Tax();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($model);

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(moduleService(['invoice:tax' => $taxService]));

    $api->setDi($di);
    $api->setService($taxService);
    $api->setIdentity(new Model_Admin());

    $data['id'] = 1;
    $result = $api->tax_get($data);
    expect($result)->toBeArray();
});

test('updates a tax', function (): void {
    $api = apiEndpoint(new Admin());
    $taxService = Mockery::mock(ServiceTax::class);
    $taxService->shouldReceive('update')
        ->atLeast()->once()
        ->andReturn(true);

    $dbMock = Mockery::mock('ClientFence\Database');
    $model = new Model_Tax();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($model);

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(moduleService(['invoice:tax' => $taxService]));

    $api->setDi($di);
    $api->setService($taxService);
    $api->setIdentity(new Model_Admin());

    $data['id'] = 1;
    $result = $api->tax_update($data);
    expect($result)->toBeBool()->toBeTrue();
});

test('export_csv requires both view and export permissions', function (): void {
    $api = apiEndpoint(new Admin());

    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('exportCSV')->never();

    $di = container();
    $staffServiceMock = $di['mod_service']('staff');
    $staffServiceMock->shouldReceive('checkPermissionsAndThrowException')->byDefault()->andReturn(true);
    $staffServiceMock->shouldReceive('checkPermissionsAndThrowException')
        ->once()
        ->with('invoice', 'view', null, Mockery::any())
        ->andThrow(new ClientFence\InformationException('You need the "invoice.view" permission to perform this action', [], 403));

    $api->setDi($di);
    $api->setService($serviceMock);

    expect(fn () => $api->export_csv(['headers' => ['id']]))
        ->toThrow(ClientFence\InformationException::class);
});

test('export_csv delegates to service when permissions granted', function (): void {
    $api = apiEndpoint(new Admin());

    $response = new Symfony\Component\HttpFoundation\Response('id,total', 200, ['Content-Type' => 'text/csv']);
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('exportCSV')
        ->once()
        ->with(['id'])
        ->andReturn($response);

    $di = container();
    $staffServiceMock = $di['mod_service']('staff');
    $staffServiceMock->shouldReceive('checkPermissionsAndThrowException')
        ->once()
        ->with('invoice', 'view', null, Mockery::any())
        ->andReturn(true)
        ->ordered();
    $staffServiceMock->shouldReceive('checkPermissionsAndThrowException')
        ->once()
        ->with('invoice', 'export', null, Mockery::any())
        ->andReturn(true)
        ->ordered();

    $api->setDi($di);
    $api->setService($serviceMock);

    $result = $api->export_csv(['headers' => ['id']]);

    expect($result)->toBeInstanceOf(Symfony\Component\HttpFoundation\Response::class);
});
