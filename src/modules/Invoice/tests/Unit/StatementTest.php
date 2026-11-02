<?php

declare(strict_types=1);

/**
 * ClientFence: the account statement (Invoice\Statement) and its PDF (Invoice\StatementPdf), on an
 * in-memory SQLite copy of the tables.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Invoice\Reports;
use ClientFence\Mod\Invoice\Statement;
use ClientFence\Mod\Invoice\StatementPdf;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

function statementDb(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
        'CREATE TABLE currency (code TEXT PRIMARY KEY, is_default INTEGER, conversion_rate REAL)',
        'CREATE TABLE setting (param TEXT PRIMARY KEY, value TEXT)',
        'CREATE TABLE client (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, company TEXT, email TEXT, currency TEXT)',
        'CREATE TABLE pay_gateway (id INTEGER PRIMARY KEY, name TEXT)',
        'CREATE TABLE invoice (id INTEGER PRIMARY KEY, client_id INTEGER, serie TEXT, nr TEXT, currency TEXT, status TEXT, approved INTEGER, gateway_id INTEGER, taxrate TEXT, tax_adjust REAL DEFAULT 0, paid_at TEXT, created_at TEXT)',
        'CREATE TABLE invoice_item (id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INTEGER, price REAL, quantity INTEGER, taxed INTEGER)',
        'CREATE TABLE "transaction" (id INTEGER PRIMARY KEY, invoice_id INTEGER)',
        'CREATE TABLE client_balance (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, type TEXT, rel_id INTEGER, amount REAL, description TEXT, created_at TEXT)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    $db->insert('currency', ['code' => 'USD', 'is_default' => 1, 'conversion_rate' => 1]);
    $db->insert('currency', ['code' => 'EGP', 'is_default' => 0, 'conversion_rate' => 50]);
    $db->insert('pay_gateway', ['id' => 1, 'name' => 'Stripe']);
    $db->insert('client', ['id' => 1, 'first_name' => 'Alice', 'last_name' => 'Smith', 'company' => '', 'email' => 'alice@example.com', 'currency' => 'USD']);
    $db->insert('client', ['id' => 2, 'first_name' => 'Bob', 'last_name' => '', 'company' => 'Bob Ltd', 'email' => 'bob@example.com', 'currency' => '']);

    return $db;
}

function statementInvoice(Connection $db, int $id, string $created, float $price, array $invoice = []): void
{
    $db->insert('invoice', $invoice + ['id' => $id, 'client_id' => 1, 'serie' => 'CF-', 'nr' => (string) $id, 'currency' => 'USD', 'status' => 'paid', 'approved' => 1, 'gateway_id' => null, 'taxrate' => '0', 'paid_at' => null, 'created_at' => $created . ' 10:00:00']);
    $db->insert('invoice_item', ['invoice_id' => $id, 'price' => $price, 'quantity' => 1, 'taxed' => 0]);
}

function statementBalance(Connection $db, string $type, ?int $rel, float $amount, string $date, string $description = ''): void
{
    $db->insert('client_balance', ['client_id' => 1, 'type' => $type, 'rel_id' => $rel, 'amount' => $amount, 'description' => $description, 'created_at' => $date . ' 10:00:00']);
}

/** A year of one client's account: gateway payments, an overpayment, a deposit, credit, an unpaid invoice. */
function statementAccount(): Connection
{
    $db = statementDb();
    // Paid by a gateway with no balance movement.
    statementInvoice($db, 1, '2026-01-10', 100, ['gateway_id' => 1, 'paid_at' => '2026-01-12 09:00:00']);
    // Paid through the balance: 120 received, 100 taken for the invoice; 20 stays as credit.
    statementInvoice($db, 2, '2026-02-01', 100, ['gateway_id' => 1, 'paid_at' => '2026-02-03 09:00:00']);
    $db->insert('"transaction"', ['id' => 1, 'invoice_id' => 2]);
    statementBalance($db, 'transaction', 1, 120, '2026-02-03');
    statementBalance($db, 'invoice', 2, -100, '2026-02-03');
    // An add-funds invoice of 50: received, taken for the invoice, then added to the balance.
    statementInvoice($db, 3, '2026-03-01', 50, ['gateway_id' => 1, 'paid_at' => '2026-03-01 11:00:00']);
    $db->insert('"transaction"', ['id' => 2, 'invoice_id' => 3]);
    statementBalance($db, 'transaction', 2, 50, '2026-03-01');
    statementBalance($db, 'invoice', 3, -50, '2026-03-01');
    statementBalance($db, 'invoice', 3, 50, '2026-03-01', 'Added funds');
    // Paid from the balance.
    statementInvoice($db, 4, '2026-04-01', 30, ['paid_at' => '2026-04-01 10:00:00']);
    statementBalance($db, 'invoice', 4, -30, '2026-04-01');
    // Credit given and taken by staff.
    statementBalance($db, 'gift', null, 10, '2026-04-15', 'Goodwill');
    statementBalance($db, 'default', null, -5, '2026-04-20');
    statementInvoice($db, 5, '2026-05-01', 40, ['status' => 'unpaid']);
    // Left out: refunded, cancelled, not approved, another currency, another client.
    statementInvoice($db, 6, '2026-05-02', 70, ['status' => 'refunded']);
    statementInvoice($db, 7, '2026-05-03', 70, ['status' => 'canceled']);
    statementInvoice($db, 8, '2026-05-04', 70, ['status' => 'unpaid', 'approved' => 0]);
    statementInvoice($db, 9, '2026-05-05', 70, ['status' => 'unpaid', 'currency' => 'EGP']);
    statementInvoice($db, 10, '2026-05-06', 70, ['status' => 'unpaid', 'client_id' => 2]);

    return $db;
}

function statementContainer(Connection $db): Pimple\Container
{
    $di = new Pimple\Container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;
    $system = new class {
        public function getCompany(): array
        {
            return ['name' => 'Example Hosting', 'email' => 'billing@example.com', 'tel' => '', 'address_1' => '1 Example Street', 'address_2' => '', 'address_3' => ''];
        }

        public function getParamValue(string $param, mixed $default = null): mixed
        {
            return $default;
        }
    };
    $currency = new class {
        public function formatCurrency(mixed $amount, string $code, array $attributes = [], ?string $locale = null): string
        {
            return number_format((float) $amount, 2) . ' ' . $code;
        }
    };
    $di['mod_service'] = $di->protect(fn (string $mod): ?object => match (strtolower($mod)) {
        'system' => $system,
        'currency' => $currency,
        default => null,
    });

    return $di;
}

test('the statement lists invoices, payments and credit with the running balance', function (): void {
    $statement = Statement::build(statementContainer(statementAccount()), 1);

    expect(array_map(fn (array $l): array => [$l['date'], $l['description'], $l['debit'], $l['credit'], $l['balance']], $statement['lines']))->toBe([
        ['2026-01-10', 'Invoice CF-00001', 100.0, 0.0, -100.0],
        ['2026-01-12', 'Payment for invoice CF-00001 (Stripe)', 0.0, 100.0, 0.0],
        ['2026-02-01', 'Invoice CF-00002', 100.0, 0.0, -100.0],
        ['2026-02-03', 'Payment for invoice CF-00002 (Stripe)', 0.0, 120.0, 20.0],
        ['2026-03-01', 'Invoice CF-00003', 50.0, 0.0, -30.0],
        ['2026-03-01', 'Payment for invoice CF-00003 (Stripe)', 0.0, 50.0, 20.0],
        ['2026-03-01', 'Account credit added: Added funds', 0.0, 50.0, 70.0],
        ['2026-04-01', 'Invoice CF-00004 (paid from account credit)', 30.0, 0.0, 40.0],
        ['2026-04-15', 'Account credit added: Goodwill', 0.0, 10.0, 50.0],
        ['2026-04-20', 'Account credit used', 5.0, 0.0, 45.0],
        ['2026-05-01', 'Invoice CF-00005', 40.0, 0.0, 5.0],
    ])
        ->and($statement['opening'])->toBe(0.0)
        ->and($statement['debit'])->toBe(325.0)
        ->and($statement['credit'])->toBe(330.0)
        ->and($statement['closing'])->toBe(5.0)
        ->and($statement['currency'])->toBe('USD')
        ->and($statement['client'])->toBe(['id' => 1, 'name' => 'Alice Smith', 'company' => '', 'email' => 'alice@example.com'])
        ->and(array_column($statement['lines'], 'invoice_id'))->toBe([1, 1, 2, 2, 3, 3, null, 4, null, null, 5])
        ->and(array_column($statement['lines'], 'title'))->toBe([
            'Invoice CF-00001', 'Payment for invoice CF-00001', 'Invoice CF-00002', 'Payment for invoice CF-00002',
            'Invoice CF-00003', 'Payment for invoice CF-00003', 'Account credit added', 'Invoice CF-00004 (paid from account credit)',
            'Account credit added', 'Account credit used', 'Invoice CF-00005',
        ])
        ->and(array_column($statement['lines'], 'detail'))->toBe([null, 'Stripe', null, 'Stripe', null, 'Stripe', 'Added funds', null, 'Goodwill', null, null]);
});

test('a period starts from the opening balance and ends at its last day', function (): void {
    $statement = Statement::build(statementContainer(statementAccount()), 1, '2026-03-01', '2026-04-30');

    expect($statement['opening'])->toBe(20.0)
        ->and(array_column($statement['lines'], 'date'))->toBe(['2026-03-01', '2026-03-01', '2026-03-01', '2026-04-01', '2026-04-15', '2026-04-20'])
        ->and($statement['debit'])->toBe(85.0)
        ->and($statement['credit'])->toBe(110.0)
        ->and($statement['closing'])->toBe(45.0)
        ->and(end($statement['lines'])['balance'])->toBe(45.0);
});

test('on a busy day each payment follows its invoice, in time order', function (): void {
    $db = statementDb();
    statementInvoice($db, 1, '2026-10-16', 20, ['gateway_id' => 1, 'paid_at' => '2026-10-16 10:05:00']);
    statementInvoice($db, 2, '2026-10-16', 30, ['gateway_id' => 1, 'paid_at' => '2026-10-16 11:05:00']);
    $db->update('invoice', ['created_at' => '2026-10-16 11:00:00'], ['id' => 2]);

    $statement = Statement::build(statementContainer($db), 1);

    expect(array_column($statement['lines'], 'description'))->toBe([
        'Invoice CF-00001', 'Payment for invoice CF-00001 (Stripe)', 'Invoice CF-00002', 'Payment for invoice CF-00002 (Stripe)',
    ])->and(array_column($statement['lines'], 'balance'))->toBe([-20.0, 0.0, -30.0, 0.0]);
});

test('a client without a currency gets the default one; the number follows the padding setting', function (): void {
    $db = statementDb();
    $db->insert('setting', ['param' => 'invoice_number_padding', 'value' => '3']);
    statementInvoice($db, 1, '2026-01-10', 15, ['client_id' => 2, 'status' => 'unpaid']);

    $statement = Statement::build(statementContainer($db), 2);

    expect($statement['currency'])->toBe('USD')
        ->and($statement['lines'][0]['description'])->toBe('Invoice CF-001')
        ->and($statement['closing'])->toBe(-15.0);
});

test('the statement checks the client and the dates', function (string $from, string $to, int $client, string $message): void {
    expect(fn () => Statement::build(statementContainer(statementDb()), $client, $from, $to))
        ->toThrow(InformationException::class, $message);
})->with([
    'unknown client' => ['', '', 99, 'Client not found'],
    'not a date' => ['2026-02-30', '', 1, 'Enter the dates as year-month-day.'],
    'wrong format' => ['', '17/10/2026', 1, 'Enter the dates as year-month-day.'],
    'reversed' => ['2026-05-01', '2026-04-01', 1, 'The start date is after the end date.'],
]);

test('the Client Statement report asks for a client, then shows the statement with a PDF', function (): void {
    $di = statementContainer(statementAccount());

    $empty = Reports::run($di, 'statement', [], (int) strtotime('2026-10-17 12:00:00'));
    expect($empty['notice'])->toBe('Enter a client ID to see the client\'s statement.')
        ->and($empty['rows'])->toBe([])
        ->and($empty['pdf'])->toBeFalse()
        ->and($empty['fields'][0]['name'])->toBe('client_id');

    $report = Reports::run($di, 'statement', ['client_id' => '1', 'from' => '2026-04-01'], (int) strtotime('2026-10-17 12:00:00'));
    expect($report['rows'][0])->toBe(['2026-04-01', 'Opening balance', null, null, 70.0])
        ->and($report['rows'][1])->toBe(['2026-04-01', ['Invoice CF-00004 (paid from account credit)', 'invoice/manage/4'], 30.0, null, 40.0])
        ->and($report['rows'][2])->toBe(['2026-04-15', 'Account credit added: Goodwill', null, 10.0, 50.0])
        ->and($report['total'])->toBe([null, 'Closing balance', 75.0, 10.0, 5.0])
        ->and($report['client']['id'])->toBe(1)
        ->and($report['pdf'])->toBeTrue()
        ->and($report['notice'])->toBeNull();
});

test('the statement PDF renders left to right and right to left', function (string $locale, bool $rtl): void {
    $db = statementAccount();
    $db->update('client', ['first_name' => 'عميل', 'last_name' => 'לקוח'], ['id' => 1]);
    $di = statementContainer($db);
    $statement = Statement::build($di, 1, '2026-02-01', '');

    $pdf = StatementPdf::render($di, $statement, $locale);

    expect(StatementPdf::isRtl($locale))->toBe($rtl)
        ->and(substr($pdf, 0, 5))->toBe('%PDF-')
        ->and(strlen($pdf))->toBeGreaterThan(5000);

    $response = StatementPdf::response($di, $statement, $locale);
    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename=statement-1.pdf')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
})->with([
    'English' => ['en_US', false],
    'Arabic' => ['ar_SA', true],
    'Hebrew' => ['he_IL', true],
]);

test('client users with invoice access may see the statement', function (): void {
    expect(ClientFence\Mod\Client\Users::CALLS['invoice']['statement'])->toBe('invoices')
        ->and(ClientFence\Mod\Client\Users::CALLS['invoice']['statement_pdf'])->toBe('invoices');
});
