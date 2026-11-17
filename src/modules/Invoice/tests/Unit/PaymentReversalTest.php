<?php

declare(strict_types=1);

use ClientFence\Mod\Invoice\PaymentReversal;
use ClientFence\Mod\Invoice\Service;
use ClientFence\Mod\Order\Service as OrderService;

use function Tests\Helpers\container;
use function Tests\Helpers\moduleService;

/**
 * CF-144: the tables a reversal reads and writes, in SQLite. Invoice #10 of client #7 (USD), total 100.00, paid on
 * 2026-11-10 10:00 by staff; gateway #5 is a Custom method (bank transfer), #6 a card gateway.
 */
function reversalDb(): Doctrine\DBAL\Connection
{
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
        'CREATE TABLE invoice (id INTEGER PRIMARY KEY, client_id INTEGER, status TEXT, currency TEXT, paid_at TEXT, created_at TEXT, updated_at TEXT, refund REAL, notes TEXT, base_income REAL, base_refund REAL)',
        'CREATE TABLE "transaction" (id INTEGER PRIMARY KEY, invoice_id INTEGER, gateway_id INTEGER, amount TEXT, status TEXT, txn_status TEXT, ipn TEXT, note TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE client_balance (id INTEGER PRIMARY KEY, client_id INTEGER, type TEXT, rel_id TEXT, invoice_item_id INTEGER UNIQUE, amount REAL, description TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE invoice_item (id INTEGER PRIMARY KEY, invoice_id INTEGER, type TEXT, rel_id INTEGER, task TEXT, status TEXT, period TEXT, charged INTEGER DEFAULT 0, attempts INTEGER DEFAULT 0, updated_at TEXT)',
        'CREATE TABLE client_order (id INTEGER PRIMARY KEY, product_id INTEGER, service_type TEXT, status TEXT, period TEXT, expires_at TEXT, activated_at TEXT, config TEXT, unpaid_invoice_id INTEGER, updated_at TEXT)',
        'CREATE TABLE product (id INTEGER PRIMARY KEY, setup TEXT)',
        'CREATE TABLE invoice_credit (id INTEGER PRIMARY KEY, invoice_id INTEGER, client_id INTEGER, amount REAL, created_at TEXT)',
        'CREATE TABLE promo_redemption (id INTEGER PRIMARY KEY, invoice_id INTEGER, status TEXT, committed_at TEXT)',
        'CREATE TABLE extension_meta (id INTEGER PRIMARY KEY, client_id INTEGER, extension TEXT, rel_type TEXT, rel_id TEXT, meta_key TEXT, meta_value TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE invoice_accounting_sync (id INTEGER PRIMARY KEY, program TEXT, entity TEXT, local_id INTEGER, status TEXT, remote_id TEXT, last_error TEXT, updated_at TEXT)',
        'CREATE TABLE client_affiliate_entry (id INTEGER PRIMARY KEY, affiliate_id INTEGER, invoice_id INTEGER, type TEXT, status TEXT, note TEXT, updated_at TEXT)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    $db->insert('invoice', ['id' => 10, 'client_id' => 7, 'status' => 'paid', 'currency' => 'USD', 'paid_at' => '2026-11-10 10:00:00', 'created_at' => '2026-11-01 09:00:00', 'notes' => '', 'base_income' => 100]);

    return $db;
}

/** A staff transaction: Mark as Paid's record ($markAsPaid, post[invoice_id]) or one processed by the Custom adapter. */
function reversalTx(Doctrine\DBAL\Connection $db, int $id, float $amount, bool $markAsPaid, string $createdAt = '2026-11-10 10:00:00', int $gateway = 5, ?string $source = 'admin'): void
{
    $ipn = ['source' => $source, 'post' => $markAsPaid ? ['invoice_id' => 10, 'txn_id' => 'REF' . $id] : null];
    $db->insert('"transaction"', ['id' => $id, 'invoice_id' => 10, 'gateway_id' => $gateway, 'amount' => (string) $amount, 'status' => 'processed', 'ipn' => json_encode($ipn), 'note' => 'Bank transfer transaction No: REF' . $id, 'created_at' => $createdAt]);
}

function reversalService(Doctrine\DBAL\Connection $db, float $freeCredit = 0.0, ?OrderService $orders = null): array
{
    $custom = new Model_PayGateway();
    $custom->loadBean(new Tests\Helpers\DummyBean());
    $custom->gateway = 'Custom';
    $card = new Model_PayGateway();
    $card->loadBean(new Tests\Helpers\DummyBean());
    $card->gateway = 'Stripe';
    $payGateways = Mockery::mock(ClientFence\Mod\Invoice\ServicePayGateway::class);
    $payGateways->shouldReceive('getAdapterClassName')->andReturn(stdClass::class);

    $di = container();
    $di['dbal'] = $db;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['db']->shouldReceive('load')->with('PayGateway', 5)->andReturn($custom);
    $di['db']->shouldReceive('load')->with('PayGateway', 6)->andReturn($card);
    $di['mod_config'] = $di->protect(fn (string $name): array => ['renewal_day' => 'keep']);
    $di['period'] = $di->protect(fn (string $code): ClientFence\Period => new ClientFence\Period($code));
    $orders ??= Mockery::mock(OrderService::class)->makePartial();
    $orders->setDi($di);
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('getTotalWithTax')->andReturn(100.0);
    $service->shouldReceive('getFreeCredit')->with(7)->andReturn($freeCredit);
    $service->setDi($di);
    $di['mod_service'] = $di->protect(moduleService(['invoice' => $service, 'invoice:paygateway' => $payGateways, 'order' => $orders]));

    return [$di, $service];
}

/** Invoice #10 as the page loaded it: as stored, or only its id, client and a paid status (a stale page). */
function reversalInvoice(?Doctrine\DBAL\Connection $db = null): Model_Invoice
{
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    foreach ($db?->fetchAssociative('SELECT * FROM invoice WHERE id = 10') ?: ['id' => 10, 'client_id' => 7, 'status' => Model_Invoice::STATUS_PAID] as $column => $value) {
        $invoice->{$column} = $value;
    }

    return $invoice;
}

test('a payment staff marked paid by mistake is reversed: unpaid again, credit back, renewal moved back, setup suspended', function (): void {
    $db = reversalDb();
    // Before: 25.00 of the client's credit applied, then Mark as Paid recorded the other 75.00 (bank transfer REF2).
    $db->insert('client_balance', ['client_id' => 7, 'type' => 'gift', 'amount' => 25, 'description' => 'Gift']);
    $db->insert('invoice_credit', ['invoice_id' => 10, 'client_id' => 7, 'amount' => 25]);
    $db->insert('client_balance', ['client_id' => 7, 'type' => 'invoice', 'rel_id' => '10', 'amount' => -25, 'description' => 'Payments recorded on the invoice, applied to it']);
    reversalTx($db, 2, 75.0, true);
    // A monthly service started on 31 January: this invoice renewed it to 28 February (Renewal Day "keep").
    $db->insert('product', ['id' => 1, 'setup' => 'after_payment']);
    $db->insert('product', ['id' => 2, 'setup' => 'after_order']);
    $db->insert('client_order', ['id' => 5, 'product_id' => 1, 'status' => 'active', 'period' => '1M', 'expires_at' => '2027-02-28 09:00:00', 'activated_at' => '2027-01-31 08:00:00', 'config' => '{}']);
    $db->insert('invoice_item', ['id' => 1, 'invoice_id' => 10, 'type' => 'order', 'rel_id' => 5, 'task' => 'renew', 'status' => 'executed', 'period' => '1M']);
    // A new service the payment set up, and one set up when ordered (the payment did not set it up).
    $db->insert('client_order', ['id' => 6, 'product_id' => 1, 'status' => 'active', 'config' => '{}']);
    $db->insert('invoice_item', ['id' => 2, 'invoice_id' => 10, 'type' => 'order', 'rel_id' => 6, 'task' => 'activate', 'status' => 'executed']);
    $db->insert('client_order', ['id' => 8, 'product_id' => 2, 'status' => 'active', 'config' => '{}']);
    $db->insert('invoice_item', ['id' => 3, 'invoice_id' => 10, 'type' => 'order', 'rel_id' => 8, 'task' => 'activate', 'status' => 'executed']);
    $db->insert('promo_redemption', ['id' => 1, 'invoice_id' => 10, 'status' => 'committed', 'committed_at' => '2026-11-10 10:00:00']);
    $db->insert('invoice_accounting_sync', ['id' => 1, 'program' => 'xero', 'entity' => 'payment', 'local_id' => 10, 'status' => 'sent']);
    $db->insert('invoice_accounting_sync', ['id' => 2, 'program' => 'quickbooks', 'entity' => 'payment', 'local_id' => 10, 'status' => 'failed']);
    // The overpayment of transaction #2 sent as credit, one only noted (nothing at the program), and the payment of an earlier
    // reversal still waiting for staff at FreeAgent.
    $db->insert('invoice_accounting_sync', ['id' => 3, 'program' => 'xero', 'entity' => 'surplus', 'local_id' => 2, 'status' => 'sent', 'remote_id' => 'CN-1']);
    $db->insert('invoice_accounting_sync', ['id' => 4, 'program' => 'quickbooks', 'entity' => 'surplus', 'local_id' => 2, 'status' => 'sent']);
    $db->insert('invoice_accounting_sync', ['id' => 5, 'program' => 'freeagent', 'entity' => 'payment', 'local_id' => 10, 'status' => 'stopped', 'last_error' => 'earlier']);
    // A commission of the payment (already cancelled): it leaves the invoice so paying it again earns again.
    $db->insert('client_affiliate_entry', ['id' => 1, 'affiliate_id' => 3, 'invoice_id' => 10, 'type' => 'commission', 'status' => 'cancelled']);
    $order6 = new Model_ClientOrder();
    $orders = Mockery::mock(OrderService::class)->makePartial();
    $orders->shouldReceive('suspendFromOrder')->once()->with($order6, 'Payment reversed')->andReturnTrue();
    [$di] = reversalService($db, 0.0, $orders);
    $di['db']->shouldReceive('getExistingModelById')->with('ClientOrder', 6, Mockery::any())->andReturn($order6);
    $invoice = reversalInvoice();

    expect(PaymentReversal::reverse($di, $invoice))->toBeTrue()
        ->and($db->fetchAssociative('SELECT status, paid_at, base_income FROM invoice WHERE id = 10'))->toBe(['status' => 'unpaid', 'paid_at' => null, 'base_income' => null])
        ->and($invoice->status)->toBe(Model_Invoice::STATUS_UNPAID)
        // The transaction is kept, marked reversed, so it no longer counts as paid.
        ->and($db->fetchAssociative('SELECT status, note FROM "transaction" WHERE id = 2'))->toMatchArray(['status' => Model_Transaction::STATUS_REVERSED])
        ->and($db->fetchOne('SELECT note FROM "transaction" WHERE id = 2'))->toContain('Reversed by staff on')
        // The 25.00 of credit is the client's again, free (no longer applied to the invoice).
        ->and((float) $db->fetchOne('SELECT SUM(amount) FROM client_balance WHERE client_id = 7'))->toBe(25.0)
        ->and($db->fetchOne('SELECT COUNT(*) FROM invoice_credit'))->toEqual(0)
        // Back one period on the service's renewal day: 31 January, not 28.
        ->and(substr((string) $db->fetchOne('SELECT expires_at FROM client_order WHERE id = 5'), 0, 10))->toBe('2027-01-31')
        ->and($db->fetchFirstColumn('SELECT status FROM invoice_item ORDER BY id'))->toBe(['pending_payment', 'pending_payment', 'pending_payment'])
        ->and($db->fetchFirstColumn('SELECT unpaid_invoice_id FROM client_order ORDER BY id'))->toEqual([10, 10, 10])
        ->and($db->fetchAssociative('SELECT status, committed_at FROM promo_redemption'))->toBe(['status' => 'reserved', 'committed_at' => null])
        // The credit batch leaves it: staff took that payment back.
        ->and($db->fetchOne("SELECT meta_value FROM extension_meta WHERE meta_key = 'no_auto_credit' AND rel_id = '10'"))->toBe('1')
        // A payment already at Xero stops there for staff; one not sent yet is dropped.
        ->and($db->fetchAssociative('SELECT status, last_error FROM invoice_accounting_sync WHERE id = 1'))->toBe(['status' => 'stopped', 'last_error' => 'The payment was reversed here: delete it at Xero by hand, then choose Send again.'])
        ->and($db->fetchOne('SELECT COUNT(*) FROM invoice_accounting_sync WHERE id = 2'))->toEqual(0)
        ->and($db->fetchAssociative('SELECT status, last_error FROM invoice_accounting_sync WHERE id = 3'))->toBe(['status' => 'stopped', 'last_error' => 'The payment was reversed here: delete it at Xero by hand.'])
        ->and($db->fetchFirstColumn('SELECT id FROM invoice_accounting_sync WHERE id IN (4, 5)'))->toEqual([5])
        ->and($db->fetchOne('SELECT last_error FROM invoice_accounting_sync WHERE id = 5'))->toBe('earlier')
        ->and($db->fetchAssociative('SELECT invoice_id, note FROM client_affiliate_entry WHERE id = 1'))->toBe(['invoice_id' => null, 'note' => 'Payment reversed'])
        ->and($db->fetchOne('SELECT notes FROM invoice WHERE id = 10'))->toContain('Payment of 100.00 USD reversed by staff. Transactions marked reversed: #2. Account credit given back: 25.00. Due dates moved back: order #5 due 2027-01-31.');

    // The activity log line: staff (added by the logger), invoice, amount.
    $info = array_values(array_filter($di['logger']->calls, fn (array $call): bool => $call['method'] === 'info'));
    expect($info[0]['params'][0])->toBe('Invoice #%s: payment of %s %s reversed (transactions: %s; credit given back %s, taken back %s).')
        ->and(array_slice($info[0]['params'], 1))->toEqual([10, '100.00', 'USD', '#2', '25.00', '0.00']);
});

test('a staff transaction that credited more than the total has the rest taken back, refused when the client spent it', function (string $case, float $free, ?string $refused, float $balanceAfter): void {
    $db = reversalDb();
    if ($case === 'overpaid') {
        // A staff transaction of 120.00 (Custom adapter): 120.00 credited, 100.00 of it paid the line, 20.00 left as credit.
        reversalTx($db, 3, 120.0, false);
        $db->insert('client_balance', ['client_id' => 7, 'type' => 'gift', 'amount' => 120, 'description' => 'Bank transfer transaction No: REF3']);
        $db->insert('invoice_item', ['id' => 1, 'invoice_id' => 10, 'type' => 'custom', 'status' => 'executed', 'charged' => 1]);
        $db->insert('client_balance', ['client_id' => 7, 'type' => 'invoice', 'rel_id' => '10', 'invoice_item_id' => 1, 'amount' => -100, 'description' => 'Hosting']);
    } else {
        // An Add funds invoice of 100.00 marked paid: the 100.00 went to the client's credit.
        reversalTx($db, 3, 100.0, true);
        $db->insert('invoice_item', ['id' => 1, 'invoice_id' => 10, 'type' => 'deposit', 'status' => 'executed', 'charged' => 1]);
        $db->insert('client_balance', ['client_id' => 7, 'type' => 'invoice', 'rel_id' => '10', 'amount' => 100, 'description' => 'Bank transfer transaction No: REF3']);
    }
    $before = (float) $db->fetchOne('SELECT SUM(amount) FROM client_balance');
    [$di] = reversalService($db, $free);
    $invoice = reversalInvoice();

    if ($refused !== null) {
        expect(fn () => PaymentReversal::reverse($di, $invoice))->toThrow(ClientFence\InformationException::class, $refused)
            ->and($db->fetchOne('SELECT status FROM invoice WHERE id = 10'))->toBe('paid')
            ->and((float) $db->fetchOne('SELECT SUM(amount) FROM client_balance'))->toBe($before)
            ->and(PaymentReversal::allowed($di, reversalInvoice($db)))->toBeFalse();

        return;
    }
    expect(PaymentReversal::allowed($di, reversalInvoice($db)))->toBeTrue()
        ->and(PaymentReversal::reverse($di, $invoice))->toBeTrue()
        ->and($db->fetchOne('SELECT status FROM invoice WHERE id = 10'))->toBe('unpaid')
        ->and(round((float) $db->fetchOne('SELECT SUM(amount) FROM client_balance'), 2))->toBe($balanceAfter)
        // The deposit line stays charged (it always is); a line whose debit went is charged again by the next payment.
        ->and((int) $db->fetchOne('SELECT charged FROM invoice_item WHERE id = 1'))->toBe($case === 'overpaid' ? 0 : 1);
})->with([
    'overpaid, the 20.00 still there' => ['overpaid', 20.0, null, 0.0],
    'overpaid, the client spent 15.00 of it' => ['overpaid', 5.0, 'The client has spent the credit this payment added', 0.0],
    'Add funds, its credit still there' => ['deposit', 100.0, null, 0.0],
    'Add funds, the client spent it' => ['deposit', 30.0, 'The client has spent the credit this payment added', 0.0],
]);

test('a payment that is not only staff money, or that more happened to since, is refused with what to do instead', function (callable $setup, string $message): void {
    $db = reversalDb();
    $setup($db);
    $db->insert('client_order', ['id' => 5, 'status' => 'active', 'expires_at' => '2027-02-28 09:00:00']);
    [$di] = reversalService($db);
    $invoice = reversalInvoice($db);

    expect(PaymentReversal::allowed($di, $invoice))->toBeFalse()
        ->and(fn () => PaymentReversal::reverse($di, $invoice))->toThrow(ClientFence\InformationException::class, $message)
        ->and($db->fetchOne('SELECT expires_at FROM client_order WHERE id = 5'))->toBe('2027-02-28 09:00:00')
        ->and($db->fetchFirstColumn('SELECT status FROM "transaction"'))->not->toContain(Model_Transaction::STATUS_REVERSED);
})->with([
    'a card payment' => [fn ($db) => reversalTx($db, 1, 100.0, false, '2026-11-10 10:00:00', 6, null), 'payment through a payment gateway'],
    'a staff transaction on a card gateway' => [fn ($db) => reversalTx($db, 1, 100.0, false, '2026-11-10 10:00:00', 6), 'payment through a payment gateway'],
    'an imported payment' => [fn ($db) => $db->insert('"transaction"', ['invoice_id' => 10, 'gateway_id' => 5, 'amount' => '100', 'status' => 'processed', 'txn_status' => 'imported', 'created_at' => '2026-11-01 09:00:00']), 'imported payment'],
    'a part payment by card before staff marked the rest paid' => [function ($db): void {
        reversalTx($db, 1, 40.0, false, '2026-11-09 10:00:00', 6, null);
        reversalTx($db, 2, 60.0, true);
    }, 'payment through a payment gateway'],
    'paid again after it was marked paid' => [function ($db): void {
        reversalTx($db, 1, 100.0, true);
        reversalTx($db, 2, 100.0, false, '2026-11-12 10:00:00');
    }, 'recorded on this invoice after it was paid'],
    'refunded' => [fn ($db) => $db->update('invoice', ['status' => 'refunded'], ['id' => 10]), 'Only the payment of a paid invoice'],
    'a credit note' => [fn ($db) => $db->update('invoice', ['status' => 'refunded', 'paid_at' => '2026-11-10 10:00:00'], ['id' => 10]), 'Only the payment of a paid invoice'],
    'unpaid' => [fn ($db) => $db->update('invoice', ['status' => 'unpaid', 'paid_at' => null], ['id' => 10]), 'Only the payment of a paid invoice'],
    'partly refunded' => [fn ($db) => $db->update('invoice', ['refund' => 10], ['id' => 10]), 'partly refunded'],
    'a domain renewal it paid for' => [function ($db): void {
        reversalTx($db, 2, 100.0, true);
        $db->insert('client_order', ['id' => 9, 'product_id' => 1, 'service_type' => 'domain', 'status' => 'active', 'config' => '{}']);
        $db->insert('invoice_item', ['invoice_id' => 10, 'type' => 'order', 'rel_id' => 9, 'task' => 'renew', 'status' => 'executed', 'period' => '1Y']);
    }, 'renewed a domain'],
    'an upgrade it applied' => [fn ($db) => $db->insert('invoice_item', ['invoice_id' => 10, 'type' => 'hook_call', 'status' => 'executed']), 'changed a service'],
    'a downgrade credit since' => [function ($db): void {
        $db->insert('invoice_item', ['invoice_id' => 10, 'type' => 'order', 'rel_id' => 5, 'task' => 'renew', 'status' => 'executed']);
        $db->insert('invoice', ['id' => 11, 'client_id' => 7, 'status' => 'refunded', 'created_at' => '2026-11-12 10:00:00']);
        $db->insert('invoice_item', ['invoice_id' => 11, 'type' => 'custom', 'rel_id' => 5, 'task' => Service::CREDIT_NOTE_TASK, 'status' => 'executed']);
    }, 'A downgrade gave account credit'],
]);

test('a second reversal of the same payment is refused and changes nothing', function (): void {
    $db = reversalDb();
    reversalTx($db, 2, 100.0, true);
    $db->insert('client_balance', ['client_id' => 7, 'type' => 'gift', 'amount' => 30, 'description' => 'Gift']);
    [$di] = reversalService($db);

    PaymentReversal::reverse($di, reversalInvoice());
    $notes = $db->fetchOne('SELECT notes FROM invoice WHERE id = 10');

    // The button's stale page sends the invoice as paid; the reversal reads it again under the lock.
    expect(fn () => PaymentReversal::reverse($di, reversalInvoice()))->toThrow(ClientFence\InformationException::class, 'Only the payment of a paid invoice')
        ->and($db->fetchOne('SELECT notes FROM invoice WHERE id = 10'))->toBe($notes)
        ->and((float) $db->fetchOne('SELECT SUM(amount) FROM client_balance'))->toBe(30.0)
        ->and($db->fetchOne("SELECT COUNT(*) FROM extension_meta WHERE meta_key = 'no_auto_credit'"))->toEqual(1);
});

test('a reversed staff transaction no longer counts as paid, so the invoice is due in full again', function (): void {
    $db = reversalDb();
    reversalTx($db, 2, 100.0, true);
    [$di, $service] = reversalService($db);
    PaymentReversal::reverse($di, reversalInvoice());

    // Service::getAmountPaid() counts processed transactions and applied credit only.
    expect($service->getAmountPaid(reversalInvoice()))->toBe(0.0);
});

// Mark as Paid records a payment on a card gateway too (staff chose the invoice's Stripe method): still reversible.
test('a payment staff marked paid on a card gateway is reversed', function (): void {
    $db = reversalDb();
    reversalTx($db, 2, 100.0, true, '2026-11-10 10:00:00', 6);
    [$di, $service] = reversalService($db);

    expect(PaymentReversal::allowed($di, reversalInvoice($db)))->toBeTrue();
    PaymentReversal::reverse($di, reversalInvoice());
    expect($db->fetchOne('SELECT status FROM invoice WHERE id = 10'))->toBe(Model_Invoice::STATUS_UNPAID)
        ->and($service->getAmountPaid(reversalInvoice()))->toBe(0.0);
});

// CF-177: a payment staff recorded on an unpaid invoice (a part payment, here on a card gateway) is taken back on its own.
test('a part payment on an unpaid invoice is reversed once: unpaid, no longer paid, its credit taken back', function (): void {
    $db = reversalDb();
    $db->update('invoice', ['status' => 'unpaid', 'paid_at' => null], ['id' => 10]);
    reversalTx($db, 3, 40.0, false, '2026-11-10 10:00:00', 6);
    $db->insert('client_balance', ['client_id' => 7, 'type' => 'transaction', 'rel_id' => '3', 'amount' => 40, 'description' => 'Invoice #10 payment received from transaction #3']);
    [$di, $service] = reversalService($db); // its container logs to Tests\Helpers\TestLogger
    $service->shouldReceive('getReservedCredit')->with(7, 10)->andReturn(0.0);
    $invoice = reversalInvoice($db);

    expect(PaymentReversal::partPayments($di, 10))->toHaveKey(3)
        ->and(PaymentReversal::reversePartPayment($di, $invoice, 3))->toBeTrue()
        ->and($db->fetchOne('SELECT status FROM invoice WHERE id = 10'))->toBe('unpaid')
        ->and($db->fetchOne('SELECT status FROM "transaction" WHERE id = 3'))->toBe(Model_Transaction::STATUS_REVERSED)
        ->and($service->getAmountPaid($invoice))->toBe(0.0)
        ->and($db->fetchAllAssociative('SELECT rel_id, amount FROM client_balance WHERE amount < 0'))->toBe([['rel_id' => '3', 'amount' => -40.0]])
        ->and(PaymentReversal::partPayments($di, 10))->toBe([]);
    expect(fn () => PaymentReversal::reversePartPayment($di, reversalInvoice($db), 3))->toThrow(ClientFence\InformationException::class, 'only once');
    expect((int) $db->fetchOne('SELECT COUNT(*) FROM client_balance WHERE amount < 0'))->toBe(1);
});

test('a part payment whose credit other unpaid invoices have set aside is not reversed', function (): void {
    $db = reversalDb();
    $db->update('invoice', ['status' => 'unpaid', 'paid_at' => null], ['id' => 10]);
    reversalTx($db, 3, 40.0, false);
    $db->insert('client_balance', ['client_id' => 7, 'type' => 'transaction', 'rel_id' => '3', 'amount' => 40, 'description' => 'Invoice #10 payment received from transaction #3']);
    [$di, $service] = reversalService($db); // its container logs to Tests\Helpers\TestLogger
    // The client's other unpaid invoice holds 30.00 of the 40.00 on the balance.
    $service->shouldReceive('getReservedCredit')->with(7, 10)->andReturn(30.0);

    expect(fn () => PaymentReversal::reversePartPayment($di, reversalInvoice($db), 3))->toThrow(ClientFence\InformationException::class, 'set aside');
    expect($db->fetchOne('SELECT status FROM "transaction" WHERE id = 3'))->toBe('processed')
        ->and((float) $db->fetchOne('SELECT SUM(amount) FROM client_balance'))->toBe(40.0);
});
