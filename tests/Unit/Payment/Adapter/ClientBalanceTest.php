<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use ClientFence\Mod\Client\Users;

use function Tests\Helpers\container;

beforeEach(fn () => Users::forget());
afterEach(fn () => Users::forget());

test('declares support for one-time payments only', function (): void {
    $config = Payment_Adapter_ClientBalance::getConfig();

    expect($config)
        ->toHaveKey('supports_one_time_payments', true)
        ->toHaveKey('supports_subscriptions', false);
});

test('requires invoice permission to pay the accounts invoice from balance', function (?string $permissions): void {
    $allowed = $permissions !== '';
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
    $dbMock->shouldReceive('store')->times($allowed ? 1 : 0)->with($tx);

    $invoiceService = Mockery::mock(ClientFence\Mod\Invoice\Service::class);
    if ($allowed) {
        $invoiceService->shouldReceive('isInvoiceTypeDeposit')->once()->with($invoice)->andReturnFalse();
        $invoiceService->shouldReceive('payInvoiceWithCredits')->once()->with($invoice);
        $invoiceService->shouldReceive('doBatchPayWithCredits')->once()->with(['client_id' => 9]);
    } else {
        $invoiceService->shouldNotReceive('isInvoiceTypeDeposit', 'payInvoiceWithCredits', 'doBatchPayWithCredits');
    }

    $di = container();
    $di['db'] = $dbMock;
    $di['auth']->shouldReceive('isClientLoggedIn')->once()->andReturnTrue();
    $di['loggedin_client'] = (object) ['id' => 9];
    $di['request'] = Symfony\Component\HttpFoundation\Request::create('/', 'POST', [], [], [], ['HTTP_X_CSRF_TOKEN' => 'balance-token']);
    $di['session']->shouldReceive('get')->with('csrf_token')->andReturn('balance-token');
    if ($permissions !== null) {
        $di['session']->shouldReceive('get')->with(Users::SESSION)->andReturn(['user_id' => 7, 'client_id' => 9, 'version' => 1]);
        $di['session']->shouldReceive('get')->with('client_id')->andReturn(9);
        $connection = Mockery::mock(Doctrine\DBAL\Connection::class);
        $connection->shouldReceive('fetchAssociative')->once()->with(Mockery::type('string'), [9, Model_Client::ACTIVE, 7])->andReturn([
            'email' => 'user@example.com', 'first_name' => 'Test', 'last_name' => 'User',
            'status' => 'active', 'session_version' => 1, 'owner' => 0, 'permissions' => $permissions,
        ]);
        $di['em']->shouldReceive('getConnection')->andReturn($connection);
    }
    $di['mod_service'] = $di->protect(fn () => $invoiceService);
    $adapter = new Payment_Adapter_ClientBalance();
    $adapter->setDi($di);

    if (!$allowed) {
        expect(fn () => $adapter->processTransaction(Mockery::mock(), 42, [], 1))
            ->toThrow(new ClientFence\InformationException('You do not have permission to do this. Ask the account owner for access.', null, 403));

        return;
    }

    expect($adapter->processTransaction(Mockery::mock(), 42, [], 1))->toBeTrue()
        ->and($tx->status)->toBe(Model_Transaction::STATUS_PROCESSED);
})->with(['owner session' => null, 'account user without invoices' => '', 'account user with invoices' => 'invoices']);

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
