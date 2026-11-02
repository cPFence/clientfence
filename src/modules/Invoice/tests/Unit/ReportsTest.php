<?php

declare(strict_types=1);

/**
 * ClientFence: Invoices > Reports (Invoice\Reports), as WHMCS's, on an in-memory SQLite copy of the
 * tables. "Now" is 2026-10-17 12:00.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Invoice\Reports;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

function reportsNow(): int
{
    return (int) strtotime('2026-10-17 12:00:00');
}

function reportsDb(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
        'CREATE TABLE currency (code TEXT PRIMARY KEY, is_default INTEGER, conversion_rate REAL)',
        'CREATE TABLE client (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, company TEXT, country TEXT, currency TEXT, status TEXT)',
        'CREATE TABLE invoice (id INTEGER PRIMARY KEY, client_id INTEGER, serie TEXT, nr TEXT, currency TEXT, status TEXT, taxname TEXT, taxrate TEXT, tax_adjust REAL DEFAULT 0, buyer_first_name TEXT, buyer_last_name TEXT, buyer_company TEXT, due_at TEXT, paid_at TEXT, created_at TEXT)',
        'CREATE TABLE invoice_item (id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INTEGER, task TEXT, rel_id TEXT, price REAL, quantity INTEGER, taxed INTEGER)',
        'CREATE TABLE "transaction" (id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INTEGER, amount TEXT, currency TEXT, type TEXT, status TEXT, created_at TEXT)',
        'CREATE TABLE client_balance (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, type TEXT, amount REAL, description TEXT, created_at TEXT)',
        'CREATE TABLE product (id INTEGER PRIMARY KEY, type TEXT)',
        'CREATE TABLE client_order (id INTEGER PRIMARY KEY, client_id INTEGER, product_id INTEGER, title TEXT, currency TEXT, period TEXT, price REAL, quantity INTEGER, config TEXT, status TEXT, reason TEXT, group_master INTEGER, expires_at TEXT, suspended_at TEXT)',
        'CREATE TABLE admin (id INTEGER PRIMARY KEY, name TEXT, system_name TEXT)',
        'CREATE TABLE support_ticket_message (id INTEGER PRIMARY KEY AUTOINCREMENT, admin_id INTEGER, created_at TEXT)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    $db->insert('currency', ['code' => 'USD', 'is_default' => 1, 'conversion_rate' => 1]);
    $db->insert('currency', ['code' => 'EGP', 'is_default' => 0, 'conversion_rate' => 50]);
    $db->insert('client', ['id' => 1, 'first_name' => 'Alice', 'last_name' => 'Smith', 'company' => '', 'country' => 'EG', 'currency' => 'USD', 'status' => 'active']);
    $db->insert('client', ['id' => 2, 'first_name' => '', 'last_name' => '', 'company' => 'Bob Ltd', 'country' => 'US', 'currency' => 'USD', 'status' => 'active']);
    $db->insert('client', ['id' => 3, 'first_name' => 'Old', 'last_name' => 'Client', 'company' => '', 'country' => 'EG', 'currency' => 'USD', 'status' => 'suspended']);

    return $db;
}

function reportsInvoice(Connection $db, int $id, array $invoice, array $prices, bool $taxed = false): void
{
    $db->insert('invoice', $invoice + ['id' => $id, 'client_id' => 1, 'serie' => 'LNK-', 'nr' => (string) $id, 'currency' => 'USD', 'status' => 'paid', 'taxname' => '', 'taxrate' => '0', 'buyer_first_name' => 'Alice', 'buyer_last_name' => 'Smith', 'buyer_company' => '', 'due_at' => null, 'paid_at' => null, 'created_at' => '2026-01-01 00:00:00']);
    foreach ($prices as $price) {
        $db->insert('invoice_item', ['invoice_id' => $id, 'price' => $price, 'quantity' => 1, 'taxed' => $taxed ? 1 : 0]);
    }
}

function reportsContainer(Connection $db): Pimple\Container
{
    $di = new Pimple\Container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;
    $product = new class {
        public function findProductById(int $id): object
        {
            return (object) ['id' => $id];
        }

        public function getProductRenewalLineConfig(object $product, array $config): array
        {
            return ['price' => 12.5, 'quantity' => 1];
        }
    };
    $di['mod_service'] = $di->protect(fn (string $mod): ?object => $mod === 'Product' ? $product : null);

    return $di;
}

test('the Reports page lists the reports by group', function (): void {
    $catalog = Reports::catalog();

    expect(array_keys($catalog))->toBe(['billing', 'clients', 'support'])
        ->and(array_keys($catalog['billing']['reports']))->toBe(['statement', 'aging_invoices', 'monthly_transactions', 'income_forecast', 'sales_tax', 'top_clients', 'credits'])
        ->and($catalog['support']['reports']['ticket_replies']['title'])->toBe('Support Ticket Replies');
});

test('aging invoices puts unpaid invoices by days overdue, in one currency', function (): void {
    $db = reportsDb();
    reportsInvoice($db, 1, ['status' => 'unpaid', 'due_at' => '2026-10-20 00:00:00'], [10]);   // not due yet
    reportsInvoice($db, 2, ['status' => 'unpaid', 'due_at' => '2026-10-17 00:00:00'], [20]);   // due today: 0 days
    reportsInvoice($db, 3, ['status' => 'unpaid', 'due_at' => '2026-08-01 00:00:00'], [30, 5]); // 77 days
    reportsInvoice($db, 4, ['status' => 'unpaid', 'due_at' => '2025-01-01 00:00:00'], [40]);   // over 120
    reportsInvoice($db, 5, ['status' => 'unpaid', 'due_at' => '2026-10-01 00:00:00', 'taxrate' => '10'], [100], true); // 16 days, with tax
    reportsInvoice($db, 6, ['status' => 'paid', 'due_at' => '2025-01-01 00:00:00'], [999]);
    reportsInvoice($db, 7, ['status' => 'unpaid', 'currency' => 'EGP', 'due_at' => '2025-01-01 00:00:00'], [500]);

    $report = Reports::run(reportsContainer($db), 'aging_invoices', [], reportsNow());

    expect(array_column($report['rows'], 1))->toBe([1, 2, 0, 1, 0, 1])
        ->and(array_column($report['rows'], 2))->toBe([10.0, 130.0, 0.0, 35.0, 0.0, 40.0])
        ->and($report['total'])->toBe(['Total', 5, 215.0])
        ->and($report['currency'])->toBe('USD')
        ->and(array_column($report['fields'], 'name'))->toBe(['currency']);

    $egp = Reports::run(reportsContainer($db), 'aging_invoices', ['currency' => 'egp'], reportsNow());
    expect($egp['total'])->toBe(['Total', 1, 500.0]);
});

test('monthly transactions sums payments in and refunds out per day', function (): void {
    $db = reportsDb();
    reportsInvoice($db, 1, [], [50]);
    foreach ([
        [1, '50.00', 'payment', 'processed', '2026-10-02 10:00:00'],
        [null, '25.50', '', 'processed', '2026-10-02 18:00:00'],      // a payment with no type, no invoice
        [1, '-20.00', 'refund', 'processed', '2026-10-05 09:00:00'],
        [1, '70.00', 'payment', 'error', '2026-10-05 09:00:00'],      // not processed
        [1, '30.00', 'payment', 'processed', '2026-11-01 00:00:00'],  // next month
    ] as [$invoice, $amount, $type, $status, $at]) {
        $db->insert('"transaction"', ['invoice_id' => $invoice, 'amount' => $amount, 'currency' => 'USD', 'type' => $type, 'status' => $status, 'created_at' => $at]);
    }

    $report = Reports::run(reportsContainer($db), 'monthly_transactions', ['month' => '2026-10'], reportsNow());

    expect($report['rows'])->toHaveCount(31)
        ->and($report['rows'][1])->toBe(['2026-10-02', 75.5, 0.0, 75.5])
        ->and($report['rows'][4])->toBe(['2026-10-05', 0.0, 20.0, -20.0])
        ->and($report['total'])->toBe(['Total', 75.5, 20.0, 55.5])
        ->and($report['filters'])->toBe(['month' => '2026-10', 'currency' => 'USD']);

    expect(fn () => Reports::run(reportsContainer($db), 'monthly_transactions', ['month' => '2026-13'], reportsNow()))
        ->toThrow(InformationException::class, 'Choose a month.');
});

test('the income forecast counts each renewal in its month for the next 12 months', function (): void {
    $db = reportsDb();
    $db->insert('product', ['id' => 1, 'type' => 'hosting']);
    $db->insert('product', ['id' => 2, 'type' => 'domain']);
    foreach ([
        [1, 1, '1M', 10, 'active', '2026-10-25 00:00:00'],  // every month from October
        [2, 1, '1Y', 100, 'active', '2027-03-10 00:00:00'], // once, in March
        [3, 2, '1Y', 9, 'active', '2026-12-01 00:00:00'],   // a domain: the TLD's renewal price, 12.50
        [4, 1, '3M', 30, 'active', '2026-09-01 00:00:00'],  // overdue: now, then December, March, June, September
        [5, 1, '1M', 99, 'canceled', '2026-10-25 00:00:00'],
        [6, 1, '', 99, 'active', '2026-10-25 00:00:00'],     // one time
    ] as [$id, $product, $period, $price, $status, $expires]) {
        $db->insert('client_order', ['id' => $id, 'client_id' => 1, 'product_id' => $product, 'title' => 'Order ' . $id, 'currency' => 'USD', 'period' => $period, 'price' => $price, 'quantity' => 1, 'config' => '{}', 'status' => $status, 'group_master' => 1, 'expires_at' => $expires]);
    }

    $report = Reports::run(reportsContainer($db), 'income_forecast', [], reportsNow());

    expect(array_column($report['rows'], 0))->toHaveCount(12)
        ->and($report['rows'][0])->toBe(['2026-10', 2, 40.0])
        ->and($report['rows'][2])->toBe(['2026-12', 3, 52.5])
        ->and($report['rows'][5])->toBe(['2027-03', 3, 140.0])
        ->and($report['rows'][11])->toBe(['2027-09', 2, 40.0])
        ->and($report['total'])->toBe(['Total', 19, 382.5]);
});

test('sales tax lists the invoices paid in the period with their tax', function (): void {
    $db = reportsDb();
    reportsInvoice($db, 1, ['paid_at' => '2026-03-01 10:00:00', 'taxname' => 'VAT', 'taxrate' => '14.00'], [100, 33.33], true);
    reportsInvoice($db, 2, ['paid_at' => '2026-12-31 23:00:00'], [50]);
    reportsInvoice($db, 3, ['paid_at' => '2025-12-31 23:00:00'], [70]);   // before the period
    reportsInvoice($db, 4, ['status' => 'unpaid'], [80]);

    $report = Reports::run(reportsContainer($db), 'sales_tax', ['from' => '2026-01-01', 'to' => '2026-12-31'], reportsNow());

    expect($report['rows'][0])->toBe([['LNK-00001', 'invoice/manage/1'], ['Alice Smith', 'client/manage/1'], '2026-03-01', 'VAT 14%', 133.33, 18.67, 152.0])
        ->and($report['rows'][1][3])->toBe('')
        ->and($report['total'])->toBe(['Total', null, null, null, 183.33, 18.67, 202.0]);

    // The default period is this year to today.
    expect(Reports::run(reportsContainer($db), 'sales_tax', [], reportsNow())['filters'])->toMatchArray(['from' => '2026-01-01', 'to' => '2026-10-17']);
    expect(fn () => Reports::run(reportsContainer($db), 'sales_tax', ['from' => '2026-05-01', 'to' => '2026-04-01'], reportsNow()))
        ->toThrow(InformationException::class, 'The start date is after the end date.');
    expect(fn () => Reports::run(reportsContainer($db), 'sales_tax', ['from' => '2026-02-30'], reportsNow()))
        ->toThrow(InformationException::class, 'Enter the dates as year-month-day.');
});

test('sales tax includes refunded sales and takes back the tax of account credit notes', function (): void {
    $db = reportsDb();
    reportsInvoice($db, 1, ['paid_at' => '2026-03-01 10:00:00', 'taxname' => 'VAT', 'taxrate' => '14'], [100], true);
    reportsInvoice($db, 2, ['status' => 'refunded', 'serie' => 'CN-', 'paid_at' => '2026-04-01 10:00:00', 'taxname' => 'VAT', 'taxrate' => '14'], [], true);
    $db->insert('invoice_item', ['invoice_id' => 2, 'task' => ClientFence\Mod\Invoice\Service::CREDIT_NOTE_TASK, 'price' => -40, 'quantity' => 1, 'taxed' => 1]);
    reportsInvoice($db, 3, ['status' => 'refunded', 'paid_at' => '2026-05-01 10:00:00', 'taxname' => 'VAT', 'taxrate' => '14'], [60], true);

    $report = Reports::run(reportsContainer($db), 'sales_tax', ['from' => '2026-01-01', 'to' => '2026-12-31'], reportsNow());

    expect(array_column(array_column($report['rows'], 0), 0))->toBe(['LNK-00001', 'CN-00002', 'LNK-00003'])
        ->and($report['rows'][1][4])->toBe(-40.0)
        ->and($report['rows'][1][5])->toBe(-5.6)
        ->and($report['total'])->toBe(['Total', null, null, null, 120.0, 16.8, 136.8]);
});

test('sales tax leaves out a credit note whose credit was taken back', function (): void {
    $db = reportsDb();
    reportsInvoice($db, 1, ['status' => 'refunded', 'paid_at' => '2026-03-01 10:00:00', 'taxname' => 'VAT', 'taxrate' => '14'], [100], true);
    reportsInvoice($db, 2, ['status' => 'refunded', 'serie' => 'CN-', 'paid_at' => '2026-04-01 10:00:00', 'taxname' => 'VAT', 'taxrate' => '14'], [], true);
    $db->insert('invoice_item', ['invoice_id' => 2, 'task' => ClientFence\Mod\Invoice\Service::CREDIT_NOTE_TAKEN_BACK_TASK, 'rel_id' => '1', 'price' => -40, 'quantity' => 1, 'taxed' => 1]);
    reportsInvoice($db, 3, ['paid_at' => '2026-05-01 10:00:00', 'taxname' => 'VAT', 'taxrate' => '14'], [50], true);
    reportsInvoice($db, 4, ['status' => 'refunded', 'serie' => 'CN-', 'paid_at' => '2026-06-01 10:00:00', 'taxname' => 'VAT', 'taxrate' => '14'], [], true);
    $db->insert('invoice_item', ['invoice_id' => 4, 'task' => ClientFence\Mod\Invoice\Service::CREDIT_NOTE_TASK, 'rel_id' => '3', 'price' => -10, 'quantity' => 1, 'taxed' => 1]);

    $report = Reports::run(reportsContainer($db), 'sales_tax', ['from' => '2026-01-01', 'to' => '2026-12-31'], reportsNow());

    expect(array_column(array_column($report['rows'], 0), 0))->toBe(['LNK-00001', 'LNK-00003', 'CN-00004'])
        ->and($report['total'])->toBe(['Total', null, null, null, 140.0, 19.6, 159.6]);
});

test('a refund preserves sales tax in the paid month and reverses it in the refund month', function (string $serie): void {
    $db = reportsDb();
    reportsInvoice($db, 1, ['status' => 'refunded', 'paid_at' => '2026-01-15 10:00:00', 'taxname' => 'VAT', 'taxrate' => '20'], [100], true);
    reportsInvoice($db, 2, ['status' => 'refunded', 'serie' => $serie, 'paid_at' => '2026-02-15 10:00:00', 'created_at' => '2026-02-15 10:00:00', 'taxname' => 'VAT', 'taxrate' => '20'], [-100], true);

    $january = Reports::run(reportsContainer($db), 'sales_tax', ['from' => '2026-01-01', 'to' => '2026-01-31'], reportsNow());
    $february = Reports::run(reportsContainer($db), 'sales_tax', ['from' => '2026-02-01', 'to' => '2026-02-28'], reportsNow());

    expect($january['total'])->toBe(['Total', null, null, null, 100.0, 20.0, 120.0])
        ->and($february['total'])->toBe(['Total', null, null, null, -100.0, -20.0, -120.0]);
})->with(['credit note' => 'CN-', 'negative invoice' => 'LNK-']);

test('top clients ranks clients by payments net of refunds', function (): void {
    $db = reportsDb();
    reportsInvoice($db, 1, ['client_id' => 1], [100]);
    reportsInvoice($db, 2, ['client_id' => 2], [300]);
    foreach ([[1, '100', 'payment'], [2, '300', 'payment'], [2, '-250', 'refund'], [null, '999', 'payment']] as [$invoice, $amount, $type]) {
        $db->insert('"transaction"', ['invoice_id' => $invoice, 'amount' => $amount, 'currency' => 'USD', 'type' => $type, 'status' => 'processed', 'created_at' => '2026-05-01 00:00:00']);
    }

    $report = Reports::run(reportsContainer($db), 'top_clients', [], reportsNow());

    expect($report['rows'])->toBe([
        [['Alice Smith', 'client/manage/1'], 100.0, 0.0, 100.0],
        [['Bob Ltd', 'client/manage/2'], 300.0, 250.0, 50.0],
    ])->and($report['filters'])->toMatchArray(['from' => '', 'to' => '']);
    expect(Reports::run(reportsContainer($db), 'top_clients', ['to' => '2026-04-30'], reportsNow())['rows'])->toBe([]);
});

test('the credits reviewer lists account credit, not payments passing through', function (): void {
    $db = reportsDb();
    foreach ([[1, 'gift', 15, 'Goodwill', '2026-02-01'], [2, 'default', 40, 'Overpayment', '2026-03-01'], [1, 'transaction', 99, 'Gateway payment', '2026-03-01'], [1, 'invoice', -99, 'Paid invoice', '2026-03-01'], [1, 'gift', 5, 'Last year', '2025-12-01'], [2, 'invoice', 30, 'Add funds invoice', '2026-04-01']] as [$client, $type, $amount, $text, $at]) {
        $db->insert('client_balance', ['client_id' => $client, 'type' => $type, 'amount' => $amount, 'description' => $text, 'created_at' => $at . ' 10:00:00']);
    }

    $report = Reports::run(reportsContainer($db), 'credits', [], reportsNow());
    expect(array_column($report['rows'], 3))->toBe(['Goodwill', 'Overpayment', 'Add funds invoice'])
        ->and($report['total'])->toBe([null, 'Total', null, null, 85.0]);

    expect(array_column(Reports::run(reportsContainer($db), 'credits', ['client_id' => '2'], reportsNow())['rows'], 3))->toBe(['Overpayment', 'Add funds invoice'])
        ->and(array_column(Reports::run(reportsContainer($db), 'credits', ['min' => '20'], reportsNow())['rows'], 3))->toBe(['Overpayment', 'Add funds invoice'])
        ->and(array_column(Reports::run(reportsContainer($db), 'credits', ['max' => '20', 'from' => '2025-01-01'], reportsNow())['rows'], 3))->toBe(['Last year', 'Goodwill']);
    expect(fn () => Reports::run(reportsContainer($db), 'credits', ['client_id' => 'x'], reportsNow()))->toThrow(InformationException::class, 'Enter a client ID.');
});

test('clients by country counts active services and active clients', function (): void {
    $db = reportsDb();
    foreach ([[1, 1, 'active', 1], [2, 1, 'active', 0], [3, 2, 'active', 1], [4, 3, 'active', 1], [5, 2, 'canceled', 1]] as [$id, $client, $status, $master]) {
        $db->insert('client_order', ['id' => $id, 'client_id' => $client, 'product_id' => 1, 'title' => 'x', 'currency' => 'USD', 'status' => $status, 'group_master' => $master]);
    }

    $report = Reports::run(reportsContainer($db), 'clients_by_country', [], reportsNow());

    // EG: Alice's service and the suspended client's; US: Bob's. The addon (not group master) is left out.
    expect($report['rows'])->toBe([['EG', 2, 1], ['US', 1, 1]])
        ->and($report['total'])->toBe(['Total', 3, 2]);
});

test('product suspensions lists suspended services with the reason', function (): void {
    $db = reportsDb();
    $db->insert('client_order', ['id' => 7, 'client_id' => 2, 'product_id' => 1, 'title' => 'Hosting', 'currency' => 'USD', 'status' => 'suspended', 'reason' => 'Unpaid', 'expires_at' => '2026-09-01 00:00:00', 'suspended_at' => '2026-09-05 00:00:00']);
    $db->insert('client_order', ['id' => 8, 'client_id' => 1, 'product_id' => 1, 'title' => 'Other', 'currency' => 'USD', 'status' => 'active']);

    expect(Reports::run(reportsContainer($db), 'suspensions', [], reportsNow())['rows'])->toBe([
        [['#7 Hosting', 'order/manage/7'], ['Bob Ltd', 'client/manage/2'], '2026-09-05', '2026-09-01', 'Unpaid'],
    ]);
});

test('ticket replies counts each staff member\'s replies per day, without the system', function (): void {
    $db = reportsDb();
    $db->insert('admin', ['id' => 1, 'name' => 'Example Admin', 'system_name' => null]);
    $db->insert('admin', ['id' => 2, 'name' => 'System Cron Job', 'system_name' => 'cron']);
    $db->insert('admin', ['id' => 3, 'name' => 'Agent', 'system_name' => null]);
    foreach ([[1, '2026-10-01 09:00:00'], [1, '2026-10-01 10:00:00'], [1, '2026-10-31 23:59:00'], [3, '2026-10-02 08:00:00'], [2, '2026-10-02 08:00:00'], [3, '2026-11-01 00:00:00'], [null, '2026-10-03 00:00:00']] as [$admin, $at]) {
        $db->insert('support_ticket_message', ['admin_id' => $admin, 'created_at' => $at]);
    }

    $report = Reports::run(reportsContainer($db), 'ticket_replies', ['month' => '2026-10'], reportsNow());

    expect($report['columns'])->toHaveCount(33)
        ->and($report['rows'][0][0])->toBe('Example Admin')
        ->and($report['rows'][0][1])->toBe(2)
        ->and($report['rows'][0][31])->toBe(1)
        ->and($report['rows'][0][32])->toBe(3)
        ->and($report['rows'][1][0])->toBe('Agent')
        ->and($report['rows'][1][32])->toBe(1)
        ->and($report['total'][32])->toBe(4);
});

test('an unknown report is not found', function (): void {
    Reports::run(reportsContainer(reportsDb()), 'nope');
})->throws(InformationException::class, 'Report not found');

test('the CSV has a byte order mark, plain numbers and country names in the language', function (): void {
    $db = reportsDb();
    $db->insert('client_order', ['id' => 1, 'client_id' => 1, 'product_id' => 1, 'title' => 'x', 'currency' => 'USD', 'status' => 'active', 'group_master' => 1]);
    $report = Reports::run(reportsContainer($db), 'clients_by_country', [], reportsNow());

    $arabic = Reports::csv($report, 'ar_SA');
    $content = (string) $arabic->getContent();
    expect(str_starts_with($content, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($content)->toContain('مصر')
        ->and($arabic->headers->get('Content-Type'))->toBe('text/csv; charset=utf-8')
        ->and($arabic->headers->get('Content-Disposition'))->toContain('clients_by_country');
    expect((string) Reports::csv($report, 'en_US')->getContent())->toContain('Egypt,1,1');

    $db->insert('"transaction"', ['invoice_id' => null, 'amount' => '1234.5', 'currency' => 'USD', 'type' => 'payment', 'status' => 'processed', 'created_at' => '2026-10-02 10:00:00']);
    $money = (string) Reports::csv(Reports::run(reportsContainer($db), 'monthly_transactions', ['month' => '2026-10'], reportsNow()), 'en_US')->getContent();
    expect($money)->toContain('2026-10-02,1234.50,0.00,1234.50')
        ->and($money)->toContain('Total,1234.50,0.00,1234.50');
});

test('a negative amount stays a number in the CSV', function (): void {
    $db = reportsDb();
    $db->insert('"transaction"', ['invoice_id' => null, 'amount' => '-20', 'currency' => 'USD', 'type' => 'refund', 'status' => 'processed', 'created_at' => '2026-10-05 10:00:00']);
    $report = Reports::run(reportsContainer($db), 'monthly_transactions', ['month' => '2026-10'], reportsNow());
    $report['rows'][0][0] = '=HYPERLINK("x")';

    $content = (string) Reports::csv($report, 'en_US')->getContent();
    expect($content)->toContain('2026-10-05,0.00,20.00,-20.00')
        ->and($content)->toContain("'=HYPERLINK");
});

test('the CSV total row keeps its label in a link column', function (): void {
    $db = reportsDb();
    reportsInvoice($db, 1, ['paid_at' => '2026-03-01 10:00:00'], [10]);
    $report = Reports::run(reportsContainer($db), 'sales_tax', [], reportsNow());

    expect((string) Reports::csv($report, 'en_US')->getContent())->toContain("\nTotal,,,,10.00,0.00,10.00\n");
});

test('a domain renewal in another currency is priced at that currency\'s rate', function (): void {
    $db = reportsDb();
    $db->insert('product', ['id' => 2, 'type' => 'domain']);
    $db->insert('client_order', ['id' => 1, 'client_id' => 1, 'product_id' => 2, 'title' => 'example.com', 'currency' => 'EGP', 'period' => '1Y', 'price' => 400, 'quantity' => 1, 'config' => '{}', 'status' => 'active', 'group_master' => 1, 'expires_at' => '2026-12-01 00:00:00']);

    // The TLD renews at 12.50 in the default currency: 625.00 EGP at 50.
    expect(Reports::run(reportsContainer($db), 'income_forecast', ['currency' => 'EGP'], reportsNow())['total'])->toBe(['Total', 1, 625.0]);
});
