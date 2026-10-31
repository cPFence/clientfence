<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use function Tests\Helpers\container;

test('declares support for one-time payments only', function (): void {
    $config = Payment_Adapter_ClientBalance::getConfig();

    expect($config)
        ->toHaveKey('supports_one_time_payments', true)
        ->toHaveKey('supports_subscriptions', false);
});

test('pays the clients own invoice from balance when another gateway is saved', function (): void {
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    $invoice->id = 16;
    $invoice->client_id = 9;
    $invoice->gateway_id = 2;
    $tx = new Model_Transaction();
    $tx->loadBean(new Tests\Helpers\DummyBean());
    $tx->invoice_id = 16;

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('load')->once()->with('Transaction', 42)->andReturn($tx);
    $dbMock->shouldReceive('load')->once()->with('Invoice', 16)->andReturn($invoice);
    $dbMock->shouldReceive('store')->once()->with($tx);

    $invoiceService = Mockery::mock(ClientFence\Mod\Invoice\Service::class);
    $invoiceService->shouldReceive('isInvoiceTypeDeposit')->once()->with($invoice)->andReturnFalse();
    $invoiceService->shouldReceive('payInvoiceWithCredits')->once()->with($invoice);
    $invoiceService->shouldReceive('doBatchPayWithCredits')->once()->with(['client_id' => 9]);

    $di = container();
    $di['db'] = $dbMock;
    $di['auth']->shouldReceive('isClientLoggedIn')->once()->andReturnTrue();
    $di['loggedin_client'] = (object) ['id' => 9];
    $di['request'] = Symfony\Component\HttpFoundation\Request::create('/', 'POST', [], [], [], ['HTTP_X_CSRF_TOKEN' => 'balance-token']);
    $di['session']->shouldReceive('get')->with('csrf_token')->andReturn('balance-token');
    $di['mod_service'] = $di->protect(fn () => $invoiceService);
    $adapter = new Payment_Adapter_ClientBalance();
    $adapter->setDi($di);

    expect($adapter->processTransaction(Mockery::mock(), 42, [], 1))->toBeTrue()
        ->and($tx->status)->toBe(Model_Transaction::STATUS_PROCESSED);
});

test('refuses balance payments without a POST and a matching session token', function (string $method, mixed $token): void {
    $di = container();
    $di['auth']->shouldReceive('isClientLoggedIn')->once()->andReturnTrue();
    $di['request'] = Symfony\Component\HttpFoundation\Request::create('/', $method);
    $di['session']->shouldReceive('get')->with('csrf_token')->andReturn('balance-token');
    $di['db']->shouldNotReceive('load', 'store');
    $invoiceService = Mockery::mock(ClientFence\Mod\Invoice\Service::class);
    $invoiceService->shouldNotReceive('payInvoiceWithCredits', 'doBatchPayWithCredits');
    $di['mod_service'] = $di->protect(fn () => $invoiceService);
    $adapter = new Payment_Adapter_ClientBalance();
    $adapter->setDi($di);

    expect(fn () => $adapter->processTransaction(Mockery::mock(), 42, ['post' => ['CSRFToken' => $token]], 1))
        ->toThrow(ClientFence\InformationException::class, $method === 'GET' ? 'This action requires POST' : 'CSRF token invalid');
})->with([
    'GET with token' => ['GET', 'balance-token'],
    'POST without token' => ['POST', null],
    'POST with wrong token' => ['POST', 'wrong-token'],
    'POST with array token' => ['POST', ['balance-token']],
]);
