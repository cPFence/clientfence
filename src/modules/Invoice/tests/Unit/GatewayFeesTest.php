<?php

declare(strict_types=1);

/**
 * ClientFence: payment gateway fees (GatewayFees.php): the settings, the fee of an amount, and the fee line that follows
 * an unpaid invoice's gateway. The invoice and client rows are in in-memory SQLite, the fee line in $world->line.
 */

use ClientFence\Mod\Invoice\GatewayFees;

use function Tests\Helpers\container;

function feeGateway(int $id, array $fee, string $name = 'PayPal'): Model_PayGateway
{
    $gateway = new Model_PayGateway();
    $gateway->loadBean(new Tests\Helpers\DummyBean());
    $gateway->id = $id;
    $gateway->name = $name;
    $gateway->config = json_encode(['email' => 'pay@example.com', 'fee' => $fee]);

    return $gateway;
}

/**
 * Invoice #5 of client #7 (group 2), 100.00 AUD due before any fee, unpaid. Gateways: #3 PayPal 2.5% + 0.30 AUD-default
 * (default currency USD, 1 USD = 1.5 AUD), taxable; #4 bank transfer without a fee; #6 Stripe 1% that group 2 pays none of.
 */
function feeWorld(string $status = 'unpaid', bool $deposit = false): object
{
    $world = new stdClass();
    $world->line = null;
    $world->due = null;
    $world->added = 0;
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE invoice (id INTEGER PRIMARY KEY, status TEXT, gateway_id INTEGER, updated_at TEXT)');
    $db->executeStatement('CREATE TABLE client_group_members (client_id INTEGER NOT NULL, client_group_id INTEGER NOT NULL, PRIMARY KEY (client_id, client_group_id))');
    $db->executeStatement('CREATE TABLE invoice_item (invoice_id INTEGER, type TEXT, price REAL, quantity INTEGER, taxed INTEGER, task TEXT)');
    $db->insert('invoice', ['id' => 5, 'status' => $status]);
    // Client 7 is in groups 5 and 2 (2 is exempt from the Stripe fee), client 8 in group 5 only (Client/Groups.php).
    $db->executeStatement('INSERT INTO client_group_members (client_id, client_group_id) VALUES (7, 5), (7, 2), (8, 5)');
    $world->db = $db;
    $gateways = [3 => feeGateway(3, ['percent' => 2.5, 'fixed' => 0.3, 'taxable' => 1]), 4 => feeGateway(4, [], 'Bank transfer'), 6 => feeGateway(6, ['percent' => 1, 'exempt_groups' => ['2']], 'Stripe')];

    $lineTotal = fn (?Model_InvoiceItem $line): float => $line === null ? 0.0 : round((float) $line->price * ($line->taxed ? 1.1 : 1), 2);
    $invoices = Mockery::mock();
    $invoices->allows('getBalanceDue')->andReturnUsing(fn (): float => $world->due ?? 100.0 + $lineTotal($world->line));
    $invoices->allows('isInvoiceTypeDeposit')->andReturn($deposit);
    $items = Mockery::mock();
    $items->allows('getTotalWithTax')->andReturnUsing($lineTotal);
    $items->allows('remove')->andReturnUsing(function () use ($world): bool {
        $world->line = null;

        return true;
    });
    $items->allows('addNew')->andReturnUsing(function (Model_Invoice $invoice, array $data) use ($world): int {
        $line = new Model_InvoiceItem();
        $line->loadBean(new Tests\Helpers\DummyBean());
        foreach ($data + ['type' => Model_InvoiceItem::TYPE_CUSTOM] as $key => $value) {
            $line->{$key} = $value;
        }
        $world->line = $line;
        ++$world->added;

        return 1;
    });
    $di = container();
    $di['dbal'] = $db;
    $di['mod_service'] = $di->protect(fn (string $module, string $sub = ''): object => match (true) {
        $module === 'Currency' => new class {
            public function getBaseCurrencyRate(string $code): float
            {
                return ['USD' => 1.0, 'AUD' => 1 / 1.5][$code];
            }
        },
        $sub === 'InvoiceItem' => $items,
        default => $invoices,
    });
    $di['db'] = Mockery::mock();
    $di['db']->allows('findOne')->andReturnUsing(fn (): ?Model_InvoiceItem => $world->line);
    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->id = 7;
    $di['db']->allows('load')->andReturnUsing(fn (string $type, int $id): ?object => match ($type) {
        'Client' => $id === 7 ? $client : null,
        'Invoice' => $id === 5 ? $world->invoice : null,
        'Transaction' => $world->tx ?? null,
        default => $gateways[$id] ?? null,
    });
    $di['db']->allows('find')->andReturn(array_values($gateways));
    $world->invoices = $invoices;
    $world->di = $di;
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    $invoice->id = 5;
    $invoice->client_id = 7;
    $invoice->currency = 'AUD';
    $invoice->taxrate = 10;
    $world->invoice = $invoice;
    $world->gateways = $gateways;

    return $world;
}

test('fee settings are kept in bounds', function (): void {
    expect(GatewayFees::clean(['percent' => '250', 'fixed' => '-3', 'taxable' => '0', 'exempt_groups' => ['2', 'x', '2']]))
        ->toBe(['percent' => 100.0, 'fixed' => 0.0, 'taxable' => false, 'exempt_groups' => [2]])
        ->and(GatewayFees::clean([]))->toBe(['percent' => 0.0, 'fixed' => 0.0, 'taxable' => false, 'exempt_groups' => []]);
});

test('the fee is a share of the amount plus the fixed amount converted, none for exempt groups', function (): void {
    $world = feeWorld();
    // 2.5% of 100 + 0.30 USD in AUD (0.45).
    expect(GatewayFees::fee($world->di, $world->gateways[3], 100.0, 'AUD', 7))->toBe(2.95)
        ->and(GatewayFees::fee($world->di, $world->gateways[3], 100.0, 'USD', null))->toBe(2.8)
        ->and(GatewayFees::fee($world->di, $world->gateways[4], 100.0, 'AUD', 7))->toBe(0.0)
        ->and(GatewayFees::fee($world->di, $world->gateways[6], 100.0, 'AUD', 7))->toBe(0.0)
        ->and(GatewayFees::fee($world->di, $world->gateways[6], 100.0, 'AUD', null))->toBe(1.0)
        ->and(GatewayFees::fee($world->di, $world->gateways[6], 100.0, 'AUD', 8))->toBe(1.0)
        ->and(GatewayFees::fee($world->di, $world->gateways[3], 0.0, 'AUD', 7))->toBe(0.0);
});

test('the fee line follows the invoice\'s gateway, once', function (): void {
    $world = feeWorld();
    GatewayFees::apply($world->di, $world->invoice, 3);
    expect((float) $world->line->price)->toBe(2.95)
        ->and((bool) $world->line->taxed)->toBeTrue()
        ->and($world->line->rel_id)->toBeNull()
        ->and($world->line->task)->toBe(GatewayFees::TASK)
        ->and($world->line->title)->toBe('PayPal fee')
        ->and((int) $world->db->fetchOne('SELECT gateway_id FROM invoice WHERE id = 5'))->toBe(3)
        // The fee is a share of the balance without it.
        ->and(GatewayFees::base($world->di, $world->invoice))->toBe(100.0);

    GatewayFees::apply($world->di, $world->invoice, 3);
    expect($world->added)->toBe(1);

    GatewayFees::apply($world->di, $world->invoice, 4);
    expect($world->line)->toBeNull()
        ->and((int) $world->db->fetchOne('SELECT gateway_id FROM invoice WHERE id = 5'))->toBe(4);

    GatewayFees::apply($world->di, $world->invoice, 3);
    GatewayFees::remove($world->di, $world->invoice);
    expect($world->line)->toBeNull();
});

test('a paid invoice and an Add funds invoice get no fee line', function (string $status, bool $deposit): void {
    $world = feeWorld($status, $deposit);
    GatewayFees::apply($world->di, $world->invoice, 3);
    expect($world->line)->toBeNull()
        ->and($world->db->fetchOne('SELECT gateway_id FROM invoice WHERE id = 5'))->toBeNull();
})->with([
    'paid' => ['paid', false],
    'add funds' => ['unpaid', true],
]);

test('the payment methods show each fee and the total with its tax, none on an Add funds invoice', function (bool $deposit): void {
    $world = feeWorld('unpaid', $deposit);
    foreach ($world->gateways as $gateway) {
        $gateway->allow_single = 1;
    }
    $world->invoices->allows('getAcceptedCurrencies')->andReturn(['AUD']);
    $world->invoices->allows('getAdapterClassName')->andReturn(stdClass::class);
    $world->invoice->taxrate = 15;
    // The invoice's tax is rounded once over its taxed lines: 10.36 + the 2.95 fee is 2.00 tax, 0.45 more than 1.55 (0.44 alone).
    $world->db->insert('invoice_item', ['invoice_id' => 5, 'price' => 10.36, 'quantity' => 1, 'taxed' => 1]);
    $methods = array_column(ClientFence\Mod\Invoice\Receipts::paymentMethods($world->di, 0, 'AUD', false, $world->invoice), null, 'id');

    expect([$methods[3]['fee'], $methods[3]['total']])->toBe($deposit ? [0.0, 100.0] : [2.95, 103.4])
        ->and([$methods[4]['fee'], $methods[4]['total']])->toBe([0.0, 100.0]);
})->with([
    'invoice' => [false],
    'add funds' => [true],
]);

test('the fee is a share of the balance without the fee and the tax it adds to the invoice', function (): void {
    $world = feeWorld();
    // 10.51 taxed at 19% is 2.00 tax; with a taxed 0.30 fee 2.05, so the fee adds 0.35 (0.36 rounded alone).
    $world->invoice->taxrate = 19;
    $world->db->insert('invoice_item', ['invoice_id' => 5, 'price' => 10.51, 'quantity' => 1, 'taxed' => 1]);
    $world->line = new Model_InvoiceItem();
    $world->line->loadBean(new Tests\Helpers\DummyBean());
    $world->line->price = 0.3;
    $world->line->quantity = 1;
    $world->line->taxed = 1;
    $world->due = 12.86;

    expect(GatewayFees::base($world->di, $world->invoice))->toBe(12.51);
});

test('the staff order summary adds what the fee line will add: the fee and its tax', function (): void {
    $world = feeWorld();
    // 2.95 on 100.00 plus 10% tax on it (0.295, rounded with the invoice's other taxed lines: none here).
    expect(GatewayFees::charge($world->di, $world->invoice, 3, 100.0))->toBe(3.25)
        // On what credit leaves: 2.5% of 40 + 0.45 = 1.45, plus 0.15 tax.
        ->and(GatewayFees::charge($world->di, $world->invoice, 3, 40.0))->toBe(1.6)
        ->and(GatewayFees::charge($world->di, $world->invoice, 4, 100.0))->toBe(0.0)
        ->and(GatewayFees::charge($world->di, $world->invoice, 6, 100.0))->toBe(0.0)
        ->and(GatewayFees::charge($world->di, $world->invoice, 99, 100.0))->toBe(0.0);

    // The same as the line the approval adds.
    GatewayFees::apply($world->di, $world->invoice, 3);
    expect(round((float) $world->line->price * 1.1, 2))->toBe(3.25);
});

test('the method picked on the invoice page becomes the invoice\'s, and its fee follows', function (): void {
    $world = feeWorld();
    $world->invoice->status = 'unpaid';
    foreach ($world->gateways as $gateway) {
        $gateway->allow_single = 1;
    }
    $world->invoices->allows('getAcceptedCurrencies')->andReturn(['AUD']);
    $world->invoices->allows('getAdapterClassName')->andReturn(stdClass::class);
    $world->invoices->allows('isSubscribable')->andReturn(false);
    $world->db->executeStatement('CREATE TABLE `transaction` (id INTEGER PRIMARY KEY, invoice_id INTEGER, amount REAL, currency TEXT, status TEXT, txn_status TEXT, created_at TEXT)');

    GatewayFees::choose($world->di, $world->invoice, 3);
    expect((float) $world->line->price)->toBe(2.95)
        ->and((int) $world->db->fetchOne('SELECT gateway_id FROM invoice WHERE id = 5'))->toBe(3);

    // Bank transfer has no fee: the fee line goes; picking PayPal again brings it back.
    GatewayFees::choose($world->di, $world->invoice, 4);
    expect($world->line)->toBeNull()
        ->and((int) $world->db->fetchOne('SELECT gateway_id FROM invoice WHERE id = 5'))->toBe(4);
    GatewayFees::choose($world->di, $world->invoice, 3);
    expect((float) $world->line->price)->toBe(2.95);

    // Not a method the page offers (disabled or not for this currency): the enabled list (find) does not have it.
    expect(fn () => GatewayFees::choose($world->di, $world->invoice, 99))->toThrow(ClientFence\InformationException::class, 'Payment method not found');

    // A payment still clearing.
    $world->db->executeStatement("INSERT INTO `transaction` (invoice_id, amount, currency, status, txn_status, created_at) VALUES (5, 103.4, 'AUD', 'received', 'processing', ?)", [date('Y-m-d H:i:s')]);
    expect(fn () => GatewayFees::choose($world->di, $world->invoice, 4))->toThrow(ClientFence\InformationException::class, 'A payment for this invoice is still clearing.');

    // A paid invoice.
    $world->invoice->status = 'paid';
    expect(fn () => GatewayFees::choose($world->di, $world->invoice, 4))->toThrow(ClientFence\InformationException::class, 'This invoice is already paid or cancelled.');
    expect((float) $world->line->price)->toBe(2.95);
});

test('without an invoice, the cart page\'s total per method adds the tax of a taxed fee at the client\'s rate', function (float $rate, float $paypal): void {
    $world = feeWorld();
    foreach ($world->gateways as $gateway) {
        $gateway->allow_single = 1;
    }
    $world->invoices->allows('getAcceptedCurrencies')->andReturn(['AUD']);
    $world->invoices->allows('getAdapterClassName')->andReturn(stdClass::class);
    $world->invoices->allows('getTaxRateForClient')->andReturn($rate);
    $session = Mockery::mock();
    $session->allows('get')->with('client_id')->andReturn(7);
    $world->di['session'] = $session;
    // 100.00 of taxed lines, 110.00 due with 10% tax. PayPal: 2.5% of 110 + 0.45 = 3.20, taxed: 10.32 - 10.00 = 0.32 more.
    $methods = array_column(ClientFence\Mod\Invoice\Receipts::paymentMethods($world->di, 110.0, 'AUD', false, null, 100.0), null, 'id');

    expect([$methods[3]['fee'], $methods[3]['total']])->toBe([3.2, $paypal])
        ->and($methods[3]['fee_terms']['taxable'])->toBeTrue()
        // Bank transfer has no fee; client 7's group pays no Stripe fee.
        ->and($methods[4]['total'])->toBe(110.0)
        ->and($methods[6]['total'])->toBe(110.0)
        // The page that does not know its taxed lines (no $taxed) adds no fee tax.
        ->and(ClientFence\Mod\Invoice\Receipts::paymentMethods($world->di, 110.0, 'AUD')[0]['total'])->toBe(113.2);
})->with([
    'taxed client' => [10.0, 113.52],
    'tax-exempt client' => [0.0, 113.2],
]);

test('the fee tax before the invoice exists is the same as the invoice adds, rounded once', function (): void {
    $world = feeWorld();
    $world->invoice->taxrate = 15;
    $world->db->insert('invoice_item', ['invoice_id' => 5, 'price' => 10.36, 'quantity' => 1, 'taxed' => 1]);

    // 10.36 + 2.95 at 15% is 2.00 tax, 0.45 more than 1.55 (0.44 alone).
    expect(GatewayFees::taxOn(10.36, 15, 2.95))->toBe(0.45)
        ->and(GatewayFees::tax($world->di, $world->invoice, 2.95))->toBe(0.45)
        // The worked example: 20.00 at 10% with a 1.16 fee adds 0.12.
        ->and(GatewayFees::taxOn(20.0, 10, 1.16))->toBe(0.12)
        ->and(GatewayFees::taxOn(20.0, 0, 1.16))->toBe(0.0);
});

test('a payment that leaves the invoice part-paid puts the fee on what is left, not while one is clearing (CF-171)', function (bool $clearing, float $fee): void {
    $world = feeWorld();
    $world->db->executeStatement('CREATE TABLE "transaction" (id INTEGER PRIMARY KEY, invoice_id INTEGER, amount REAL, currency TEXT, status TEXT, txn_status TEXT, created_at TEXT)');
    if ($clearing) {
        $world->db->executeStatement('INSERT INTO "transaction" (invoice_id, amount, currency, status, txn_status, created_at) VALUES (5, 50, ?, ?, ?, ?)', ['AUD', 'received', 'processing', date('Y-m-d H:i:s')]);
    }
    $world->di['dbal'] = $world->db;
    GatewayFees::apply($world->di, $world->invoice, 3);
    // 100.00 + the 2.95 fee and its 0.30 tax; the adapter records a 20.00 transfer, which leaves 80.00 without the fee.
    $world->due = 83.25;
    $tx = new Model_Transaction();
    $tx->loadBean(new Tests\Helpers\DummyBean());
    $tx->id = 1;
    $tx->gateway_id = 4;
    $tx->invoice_id = 5;
    $world->tx = $tx;
    $world->invoices->allows('getPaymentAdapter')->andReturn(new class {
        public function processTransaction($api, int $id, array $ipn, int $gatewayId): bool
        {
            return true;
        }
    });
    $world->di['api_system'] = new stdClass();
    // What the payment left due is settled again once the fee changed (a payment of the amount without the fee pays it).
    $world->invoices->shouldReceive('tryPayWithCredits')->times($clearing ? 0 : 1)->andReturn(false);
    $service = new ClientFence\Mod\Invoice\ServiceTransaction();
    $service->setDi($world->di);

    expect((new ReflectionMethod($service, 'processTransactionLocked'))->invoke($service, 1))->toBeTrue()
        ->and((float) $world->line->price)->toBe($fee);
})->with([
    // 2.5% of 80 + 0.45.
    'part-paid' => [false, 2.45],
    'a payment still clearing' => [true, 2.95],
]);
