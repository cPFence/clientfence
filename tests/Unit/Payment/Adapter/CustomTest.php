<?php

declare(strict_types=1);

/** ClientFence: a bank payment is credited in the client's account currency, never 1:1 from another one. */
test('a payment on an invoice in another currency than the account is refused before anything is credited', function (): void {
    $tx = new Model_Transaction();
    $tx->loadBean(new Tests\Helpers\DummyBean());
    $tx->id = 7;
    $tx->invoice_id = 9;
    $tx->status = Model_Transaction::STATUS_RECEIVED;
    $tx->amount = 100;
    $tx->currency = 'EGP';

    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    $invoice->id = 9;
    $invoice->client_id = 5;
    $invoice->currency = 'EGP';
    $invoice->status = Model_Invoice::STATUS_UNPAID;

    $db = Mockery::mock('ClientFence\Database');
    $db->shouldReceive('getExistingModelById')->with('Transaction', 7)->andReturn($tx);
    $db->shouldReceive('getExistingModelById')->with('Invoice', 9)->andReturn($invoice);
    $db->shouldReceive('getCell')->with('SELECT currency FROM client WHERE id = :id', ['id' => 5])->andReturn('USD');
    $db->shouldReceive('store')->never();

    $di = new Pimple\Container();
    $di['db'] = $db;
    $di['mod_service'] = $di->protect(fn () => throw new RuntimeException('nothing may be credited'));
    $adapter = new Payment_Adapter_Custom([]);
    $adapter->setDi($di);

    expect(fn () => $adapter->processTransaction((new ReflectionClass(ClientFence\Api\Proxy::class))->newInstanceWithoutConstructor(), 7, ['source' => 'admin'], 1))
        ->toThrow(Payment_Exception::class, 'The invoice is in EGP but the client account is in USD. Record this payment by hand.');
});

test('an Add funds invoice is paid once its own transfers add up to it', function (float $before, string $status, bool $paid): void {
    // QA r7 X-01: two partial transfers ($4 + $6) on a $10 Add funds invoice credited $10 and left it unpaid.
    $tx = new Model_Transaction();
    $tx->loadBean(new Tests\Helpers\DummyBean());
    $tx->id = 8;
    $tx->invoice_id = 9;
    $tx->gateway_id = 1;
    $tx->status = Model_Transaction::STATUS_RECEIVED;
    $tx->amount = 6;
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

    $db = Mockery::mock('ClientFence\Database');
    $db->shouldReceive('getExistingModelById')->with('Transaction', 8)->andReturn($tx);
    $db->shouldReceive('getExistingModelById')->with('Invoice', 9)->andReturn($invoice);
    $db->shouldReceive('getCell')->with('SELECT currency FROM client WHERE id = :id', ['id' => 5])->andReturn('USD');
    $db->shouldReceive('getCell')->with(Mockery::pattern('/SUM\(amount\)/'), ['invoice' => 9, 'status' => Model_Transaction::STATUS_PROCESSED, 'id' => 8])->andReturn($before);
    $db->shouldReceive('load')->with('PayGateway', 1)->andReturn($gateway);
    $db->shouldReceive('store')->once()->andReturn(8);

    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $clients = Mockery::mock();
    $clients->shouldReceive('get')->andReturn($client);
    $clients->shouldReceive('addFunds')->once()->with($client, 6.0, Mockery::any(), []);
    $invoices = Mockery::mock();
    $invoices->shouldReceive('getTotalWithTax')->andReturn(10.0);
    $invoices->shouldReceive('isInvoiceTypeDeposit')->andReturn(true);
    $invoices->shouldReceive('tryPayWithCredits')->never();
    $invoices->shouldReceive('markAsPaid')->times($paid ? 1 : 0)->with($invoice, true, true);

    $di = new Pimple\Container();
    $di['db'] = $db;
    $di['mod_service'] = $di->protect(fn (string $name) => $name === 'Client' ? $clients : $invoices);
    $adapter = new Payment_Adapter_Custom([]);
    $adapter->setDi($di);

    expect($adapter->processTransaction((new ReflectionClass(ClientFence\Api\Proxy::class))->newInstanceWithoutConstructor(), 8, ['source' => 'admin'], 1))->toBeTruthy();
})->with([
    'second transfer completes it' => [4.0, Model_Invoice::STATUS_UNPAID, true],
    'still short' => [3.0, Model_Invoice::STATUS_UNPAID, false],
    // QA r7 X-01-R1: a refunded one's transfers were paid back; a new one must not mark it paid again.
    'refunded' => [10.0, Model_Invoice::STATUS_REFUNDED, false],
]);
