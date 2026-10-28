<?php

declare(strict_types=1);

use ClientFence\InformationException;
use ClientFence\Mod\Invoice\Api\Admin;
use ClientFence\Mod\Invoice\Service;
use ClientFence\Mod\Order\Service as OrderService;

use function Tests\Helpers\client;
use function Tests\Helpers\container;
use function Tests\Helpers\model;
use function Tests\Helpers\moduleService;
use function Tests\Helpers\order;

test('selected renewal invoices reject mixed clients before creating any invoice', function (): void {
    $di = container();
    $di['db']->shouldReceive('getExistingModelById')->with('ClientOrder', 1, 'Order not found')->andReturn(order(['id' => 1, 'client_id' => 7]));
    $di['db']->shouldReceive('getExistingModelById')->with('ClientOrder', 2, 'Order not found')->andReturn(order(['id' => 2, 'client_id' => 8]));
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldNotReceive('renewInvoice');
    $service->setDi($di);
    $api = apiEndpoint(new Admin());
    $api->setDi($di);
    $api->setService($service);

    expect(fn () => $api->batch_renewal_invoice(['ids' => [1, 2]]))->toThrow(InformationException::class, 'one client');
});

test('selected renewals report existing unpaid orders without approving them again', function (): void {
    $di = container();
    foreach ([1, 2] as $id) {
        $di['db']->shouldReceive('getExistingModelById')->with('ClientOrder', $id, 'Order not found')->andReturn(order(['id' => $id, 'client_id' => 7]));
    }
    $sql = 'SELECT i.id FROM client_order co JOIN invoice i ON i.id = co.unpaid_invoice_id WHERE co.id = ? AND i.status = ?';
    $di['dbal']->shouldReceive('fetchOne')->once()->with($sql, [1, 'unpaid'])->andReturn(12);
    $di['dbal']->shouldReceive('fetchOne')->once()->with($sql, [2, 'unpaid'])->andReturn(false);
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('renewInvoice')->once()->with(Mockery::on(static fn (Model_ClientOrder $order): bool => (int) $order->id === 2), [])->andReturn(13);
    $service->setDi($di);
    $api = apiEndpoint(new Admin());
    $api->setDi($di);
    $api->setService($service);

    expect($api->batch_renewal_invoice(['ids' => [1, 2, 2]]))->toBe(['invoice_ids' => [13], 'skipped_order_ids' => [1]]);
});

test('batch generation passes the client limit to the existing due-order query', function (): void {
    $di = container();
    $orderService = Mockery::mock(OrderService::class)->makePartial();
    $orderService->shouldReceive('getSoonExpiringActiveOrdersQuery')->once()->with(['client_id' => 7])->andReturn(['SELECT id FROM client_order WHERE client_id = :client_id', ['client_id' => 7]]);
    $di['em']->shouldReceive('getConnection')->andReturn($di['dbal']);
    $di['dbal']->shouldReceive('fetchAllAssociative')->once()->with('SELECT id FROM client_order WHERE client_id = :client_id', ['client_id' => 7])->andReturn([['id' => 10]]);
    $di['db']->shouldReceive('getExistingModelById')->once()->with('ClientOrder', 10)->andReturn(order(['id' => 10, 'client_id' => 7]));
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('generateForOrder')->once()->with(Mockery::on(static fn (Model_ClientOrder $order): bool => (int) $order->client_id === 7))->andReturn(model(Model_Invoice::class, ['id' => 11]));
    $service->shouldReceive('approveInvoice')->once()->with(Mockery::type(Model_Invoice::class), ['id' => 11, 'use_credits' => true]);
    $di['mod_service'] = $di->protect(moduleService(['order' => $orderService]));
    $orderService->setDi($di);
    $service->setDi($di);
    $api = apiEndpoint(new Admin());
    $api->setDi($di);
    $api->setService($service);

    expect($api->batch_generate(['client_id' => 7]))->toBeTrue();
});

test('staff funds invoice uses the deposit generator and preserves its amount limits', function (bool $withinLimits): void {
    $di = container();
    $client = client(['id' => 7, 'currency' => 'USD']);
    $di['db']->shouldReceive('getExistingModelById')->once()->with('Client', 7, 'Client not found')->andReturn($client);
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('isFundsEnabled')->once()->andReturn(true);
    $service->shouldReceive('fundsLimits')->once()->with('USD')->andReturn(['min' => 5.0, 'max' => 100.0]);
    if ($withinLimits) {
        $deposit = model(Model_Invoice::class, ['id' => 20]);
        $di['db']->shouldReceive('dispense')->once()->with('Invoice')->andReturn($deposit);
        $service->shouldReceive('setInvoiceDefaults')->once()->with($deposit);
        $service->shouldReceive('approveInvoice')->once()->with($deposit, ['id' => 20]);
        $items = Mockery::mock(ClientFence\Mod\Invoice\ServiceInvoiceItem::class);
        $items->shouldReceive('generateForAddFunds')->once()->with($deposit, 10);
        $di['mod_service'] = $di->protect(moduleService(['invoice:invoiceitem' => $items]));
        $di['logger'] = new Tests\Helpers\TestLogger();
    } else {
        $di['db']->shouldNotReceive('dispense');
        $service->shouldNotReceive('approveInvoice');
    }
    $service->setDi($di);
    $api = apiEndpoint(new Admin());
    $api->setDi($di);
    $api->setService($service);

    if ($withinLimits) {
        expect($api->funds_invoice(['client_id' => 7, 'amount' => 10]))->toBe(20);
    } else {
        expect(fn () => $api->funds_invoice(['client_id' => 7, 'amount' => 101]))->toThrow(InformationException::class, 'cannot exceed');
    }
})->with([true, false]);
