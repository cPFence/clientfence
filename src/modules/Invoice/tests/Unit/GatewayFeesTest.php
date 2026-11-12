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
    $db->executeStatement('CREATE TABLE client (id INTEGER PRIMARY KEY, client_group_id INTEGER)');
    $db->executeStatement('CREATE TABLE invoice_item (invoice_id INTEGER, price REAL, quantity INTEGER, taxed INTEGER, task TEXT)');
    $db->insert('invoice', ['id' => 5, 'status' => $status]);
    $db->insert('client', ['id' => 7, 'client_group_id' => 2]);
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
    $di['db']->allows('load')->andReturnUsing(fn (string $type, int $id): ?Model_PayGateway => $gateways[$id] ?? null);
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
