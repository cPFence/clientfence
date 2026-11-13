<?php

declare(strict_types=1);

/**
 * ClientFence: the accounting sync engine (Invoice/Accounting/Sync.php) on in-memory SQLite, with a fake program that
 * records what it is sent. Client #7 (Acme Ltd); start date 2026-11-01; gateway #3 PayPal (bank "090"), account credit
 * "850", sales "200", tax "VAT|7.5" => OUTPUT2 and "none" => NONE.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Invoice\Accounting\Program;
use ClientFence\Mod\Invoice\Accounting\RateLimited;
use ClientFence\Mod\Invoice\Accounting\Sync;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

function acctSyncWorld(array $params = []): object
{
    $world = new stdClass();
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
        'CREATE TABLE invoice (id INTEGER PRIMARY KEY, client_id INTEGER, serie TEXT, nr TEXT, currency TEXT, status TEXT, approved INTEGER, taxname TEXT, taxrate TEXT, tax_adjust REAL DEFAULT 0,
            gateway_id INTEGER, notes TEXT, buyer_country TEXT, buyer_company_vat TEXT, due_at TEXT, paid_at TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE invoice_item (id INTEGER PRIMARY KEY, invoice_id INTEGER, type TEXT, task TEXT, title TEXT, price REAL, quantity INTEGER, taxed INTEGER, updated_at TEXT)',
        'CREATE TABLE client (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, company TEXT, email TEXT, billing_email TEXT, company_vat TEXT, phone TEXT, phone_cc TEXT,
            address_1 TEXT, address_2 TEXT, city TEXT, state TEXT, postcode TEXT, country TEXT, updated_at TEXT)',
        'CREATE TABLE "transaction" (id INTEGER PRIMARY KEY, invoice_id INTEGER, gateway_id INTEGER, amount REAL, status TEXT, txn_status TEXT, type TEXT, currency TEXT, created_at TEXT)',
        'CREATE TABLE client_balance (id INTEGER PRIMARY KEY, client_id INTEGER, type TEXT, rel_id TEXT, description TEXT, amount REAL)',
        'CREATE TABLE invoice_credit (id INTEGER PRIMARY KEY, invoice_id INTEGER, amount REAL)',
        'CREATE TABLE pay_gateway (id INTEGER PRIMARY KEY, name TEXT)',
        'CREATE TABLE tax (id INTEGER PRIMARY KEY, name TEXT, taxrate TEXT)',
        'CREATE TABLE currency (id INTEGER PRIMARY KEY, code TEXT, conversion_rate REAL)',
        'CREATE TABLE invoice_accounting_sync (id INTEGER PRIMARY KEY AUTOINCREMENT, program TEXT, entity TEXT, local_id INTEGER, remote_id TEXT, status TEXT, attempts INTEGER DEFAULT 0, revision INTEGER DEFAULT 0,
            last_error TEXT, fingerprint TEXT, synced_at TEXT, created_at TEXT, updated_at TEXT, UNIQUE (program, entity, local_id))',
        'CREATE TABLE admin (id INTEGER PRIMARY KEY, status TEXT, system_name TEXT)',
        'CREATE TABLE mod_clientfence_notification (id INTEGER PRIMARY KEY AUTOINCREMENT, audience TEXT, recipient_id INTEGER, kind TEXT, params TEXT, url TEXT, dedupe TEXT, created_at TEXT, read_at TEXT,
            UNIQUE (audience, recipient_id, dedupe))',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    $db->insert('client', ['id' => 7, 'first_name' => 'Ann', 'last_name' => 'Lee', 'company' => 'Acme Ltd', 'email' => 'ann@example.com', 'country' => 'GB', 'updated_at' => '2026-10-01 00:00:00']);
    $db->insert('pay_gateway', ['id' => 3, 'name' => 'PayPal']);
    $db->insert('admin', ['id' => 1, 'status' => 'active']);
    $world->db = $db;

    $system = new class(['invoice_number_padding' => '5'] + array_combine(array_map(static fn (string $k): string => 'accounting_' . $k, array_keys($params + acctSyncParams())), array_values($params + acctSyncParams()))) {
        public function __construct(public array $params)
        {
        }

        public function getParamValue(string $param, mixed $default = null): mixed
        {
            return $this->params[$param] ?? $default;
        }

        public function setParamValue(string $param, mixed $value, bool $create = true): bool
        {
            $this->params[$param] = $value;

            return true;
        }
    };
    $world->system = $system;
    $staff = Mockery::mock();
    $staff->allows('hasPermission')->andReturnTrue();
    $di = Tests\Helpers\container();
    $di['dbal'] = $db;
    $di['em'] = Mockery::mock();
    $di['em']->allows('getConnection')->andReturn($db);
    $di['db'] = Mockery::mock();
    $di['db']->allows('load')->andReturnUsing(function (): Model_Admin {
        $admin = new Model_Admin();
        $admin->loadBean(new Tests\Helpers\DummyBean());

        return $admin;
    });
    $di['mod_service'] = $di->protect(fn (string $name): object => strtolower($name) === 'staff' ? $staff : $system);
    $di['crypt'] = new class {
        public function encrypt(string $text): string
        {
            return 'enc:' . $text;
        }

        public function decrypt(?string $text): string|false
        {
            return is_string($text) && str_starts_with($text, 'enc:') ? substr($text, 4) : false;
        }
    };
    $di['logger'] = new Tests\Helpers\TestLogger();
    $world->di = $di;
    $world->program = acctSyncProgram();
    $world->sync = new Sync($di, $world->program);

    return $world;
}

function acctSyncParams(): array
{
    return [
        'program' => 'xero', 'client_id' => 'app-1', 'client_secret' => 'enc:app-secret-9', 'tokens' => 'enc:{"refresh_token":"refresh-secret-9","tenant_id":"t"}',
        'start_date' => '2026-11-01', 'paid_only' => '0', 'sales_account' => '200',
        'bank_accounts' => '{"3":"090","credit":"850"}', 'tax_map' => '{"VAT|7.5":"OUTPUT2","none":"NONE"}',
    ];
}

/** A program that records each call ([method, data]) and fails a method while $fail[method] is set. */
function acctSyncProgram(): Program
{
    return new class(new MockHttpClient(), '', '', [], static fn (): null => null) implements Program {
        public array $calls = [];
        public array $fail = [];
        public string $tenant = 't';

        public function __construct(HttpClientInterface $http, string $clientId, string $clientSecret, array $tokens, Closure $saveTokens, bool $sandbox = false)
        {
        }

        public static function label(): string
        {
            return 'Xero';
        }

        public static function hasSandbox(): bool
        {
            return false;
        }

        public function authorizeUrl(string $state, string $redirect): string
        {
            return 'https://login.example/?state=' . $state;
        }

        public function connect(string $code, string $redirect, array $callback): array
        {
            return ['access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => time() + 1800, 'tenant_id' => $this->tenant, 'tenant_name' => 'Demo'];
        }

        public function accounts(): array
        {
            return [];
        }

        public function taxRates(): array
        {
            return [];
        }

        public function contact(array $client): string
        {
            return $this->record('contact', $client, 'contact-' . $client['id']);
        }

        public function invoice(array $doc): string
        {
            return $this->record('invoice', $doc, $doc['remote_id'] ?? 'inv-' . $doc['number']);
        }

        public function payment(array $payment): string
        {
            return $this->record('payment', $payment, 'pay-' . count($this->calls));
        }

        public function creditNote(array $credit): string
        {
            return $this->record('creditNote', $credit, 'cn-' . $credit['number']);
        }

        public function void(string $remoteInvoiceId): void
        {
            $this->record('void', ['id' => $remoteInvoiceId], '');
        }

        public function voidCreditNote(string $remoteCreditNoteId): void
        {
            $this->record('voidCreditNote', ['id' => $remoteCreditNoteId], '');
        }

        private function record(string $method, array $data, string $id): string
        {
            $this->calls[] = [$method, $data];
            if (isset($this->fail[$method])) {
                throw $this->fail[$method];
            }

            return $id;
        }
    };
}

function acctSyncInvoice(object $world, int $id, array $row, array $items = [['title' => 'Hosting', 'price' => 10.0, 'quantity' => 1, 'taxed' => 0]]): void
{
    $world->db->insert('invoice', $row + ['id' => $id, 'client_id' => 7, 'serie' => 'INV', 'nr' => (string) $id, 'currency' => 'GBP', 'status' => 'unpaid', 'approved' => 1, 'taxname' => 'VAT', 'taxrate' => '0',
        'gateway_id' => 3, 'due_at' => '2026-11-12 00:00:00', 'created_at' => '2026-11-05 10:00:00', 'updated_at' => '2026-11-05 10:00:00']);
    foreach ($items as $item) {
        $world->db->insert('invoice_item', $item + ['invoice_id' => $id, 'type' => 'custom', 'updated_at' => '2026-11-05 10:00:00']);
    }
}

function acctSyncRow(object $world, string $entity, int $id): array|false
{
    return $world->db->fetchAssociative('SELECT * FROM invoice_accounting_sync WHERE entity = ? AND local_id = ?', [$entity, $id]);
}

test('the scan sends approved invoices from the start date, the client first, then the invoice, then its payment', function (): void {
    $world = acctSyncWorld();
    acctSyncInvoice($world, 1, []);
    acctSyncInvoice($world, 2, ['created_at' => '2026-10-31 23:59:59']);
    acctSyncInvoice($world, 3, ['approved' => 0]);
    acctSyncInvoice($world, 4, ['status' => 'canceled']);
    acctSyncInvoice($world, 5, ['status' => 'paid', 'serie' => 'PAID', 'paid_at' => '2026-11-06 09:00:00']);
    // PayPal's IPN stores its own txn_type ("web_accept"): still money in through the gateway, not account credit.
    $world->db->insert('`transaction`', ['invoice_id' => 5, 'gateway_id' => 3, 'amount' => 10.0, 'status' => 'processed', 'type' => 'web_accept', 'created_at' => '2026-11-06 08:59:00']);
    $world->db->insert('client_balance', ['client_id' => 7, 'type' => 'invoice', 'rel_id' => '5', 'description' => 'Payment for invoice #5 using account credit.', 'amount' => -10.0]);

    $result = $world->sync->run();

    expect(array_map(static fn (array $c): string => $c[0] . ($c[1]['number'] ?? $c[1]['reference'] ?? '#' . ($c[1]['id'] ?? '')), $world->program->calls))
        ->toBe(['contact#7', 'invoiceINV00001', 'invoicePAID00005', 'paymentTransaction 1'])
        ->and($result)->toMatchArray(['sent' => 4, 'failed' => 0])
        ->and($world->program->calls[1][1]['contact'])->toBe('contact-7')
        ->and($world->program->calls[3][1])->toMatchArray(['invoice' => 'inv-PAID00005', 'account' => '090', 'amount' => 10.0, 'date' => '2026-11-06', 'currency' => 'GBP'])
        ->and(acctSyncRow($world, 'payment', 5))->toMatchArray(['status' => 'sent', 'remote_id' => 'pay-3']);

    // Nothing changed: nothing is sent again.
    $world->program->calls = [];
    $world->sync->run();
    expect($world->program->calls)->toBe([]);
});

test('with paid invoices only, an unpaid invoice waits', function (): void {
    $world = acctSyncWorld(['paid_only' => '1']);
    acctSyncInvoice($world, 1, []);
    acctSyncInvoice($world, 5, ['status' => 'paid', 'paid_at' => '2026-11-06 09:00:00']);
    $world->db->insert('client_balance', ['client_id' => 7, 'type' => 'invoice', 'rel_id' => '5', 'description' => 'Payment for invoice #5 using account credit.', 'amount' => -10.0]);

    $world->sync->run();

    expect(array_column($world->program->calls, 0))->toBe(['contact', 'invoice', 'payment'])
        ->and($world->program->calls[1][1]['number'])->toBe('INV00005')
        // Paid from account credit, so into the account credit account.
        ->and($world->program->calls[2][1])->toMatchArray(['account' => '850', 'amount' => 10.0, 'reference' => 'Account credit']);
});

test('a changed unpaid invoice is sent again to the same record', function (): void {
    $world = acctSyncWorld();
    acctSyncInvoice($world, 1, []);
    $world->sync->run();
    $world->db->executeStatement("UPDATE invoice_item SET price = 12.5, updated_at = ? WHERE invoice_id = 1", [date('Y-m-d H:i:s', time() + 5)]);
    $world->program->calls = [];

    $world->sync->run();

    expect(array_column($world->program->calls, 0))->toBe(['invoice'])
        ->and($world->program->calls[0][1])->toMatchArray(['remote_id' => 'inv-INV00001', 'total' => 12.5]);
});

test('a record stops after 10 failed attempts with one staff notice, and send again starts it over', function (): void {
    $world = acctSyncWorld();
    acctSyncInvoice($world, 1, []);
    $world->program->fail['invoice'] = new InformationException('Xero refused the request: contact Acme Ltd is archived');

    for ($run = 0; $run < 12; ++$run) {
        $world->sync->run();
    }
    $row = acctSyncRow($world, 'invoice', 1);
    $log = json_encode($world->di['logger']->calls);

    expect(count(array_filter($world->program->calls, static fn (array $c): bool => $c[0] === 'invoice')))->toBe(10)
        ->and($row)->toMatchArray(['status' => 'stopped', 'attempts' => 10, 'last_error' => 'Xero refused the request: contact Acme Ltd is archived'])
        ->and($world->db->fetchAllAssociative('SELECT kind, url FROM mod_clientfence_notification'))->toBe([['kind' => 'accounting_stopped', 'url' => 'extension/settings/invoice#accounting']])
        // The log names the record, never the program's message (client data) or a secret.
        ->and($log)->toContain('was not sent to %s (attempt')->not->toContain('Acme')->not->toContain('app-secret-9')->not->toContain('refresh-secret-9');

    unset($world->program->fail['invoice']);
    $world->sync->resend((int) $row['id']);
    $world->sync->run();
    expect(acctSyncRow($world, 'invoice', 1))->toMatchArray(['status' => 'sent', 'attempts' => 0, 'last_error' => null]);
});

test('a 429 stops the run and the next runs wait', function (): void {
    $world = acctSyncWorld();
    acctSyncInvoice($world, 1, []);
    acctSyncInvoice($world, 2, []);
    $world->program->fail['invoice'] = new RateLimited(60, 'Xero');

    expect($world->sync->run())->toMatchArray(['sent' => 1, 'failed' => 0, 'waiting' => true])
        ->and(acctSyncRow($world, 'invoice', 1))->toBeFalse();

    $world->program->calls = [];
    expect($world->sync->run()['waiting'])->toBeTrue()
        ->and($world->program->calls)->toBe([]);
});

test('remote totals equal ClientFence\'s for an invoice with tax, a discount and a gateway fee; the payment is split by source', function (): void {
    $world = acctSyncWorld();
    // 59.97 + 1.83 fee - 5.00 discount taxed at 7.5% (4.26) and 1 cent of tax_adjust, plus 10.00 untaxed: 71.07.
    acctSyncInvoice($world, 8, ['status' => 'paid', 'taxrate' => '7.5', 'tax_adjust' => 0.01, 'paid_at' => '2026-11-07 12:00:00'], [
        ['title' => 'Hosting', 'price' => 19.99, 'quantity' => 3, 'taxed' => 1, 'type' => 'order'],
        ['title' => 'Discount: Hosting', 'price' => -5.0, 'quantity' => 1, 'taxed' => 1],
        ['title' => 'Domain', 'price' => 10.0, 'quantity' => 1, 'taxed' => 0],
        ['title' => 'PayPal fee', 'price' => 1.83, 'quantity' => 1, 'taxed' => 1, 'task' => 'gateway_fee'],
    ]);
    $world->db->insert('`transaction`', ['invoice_id' => 8, 'gateway_id' => 3, 'amount' => 50.0, 'status' => 'processed', 'created_at' => '2026-11-06 10:00:00']);
    $world->db->insert('`transaction`', ['invoice_id' => 8, 'gateway_id' => 3, 'amount' => 9.0, 'status' => 'processed', 'type' => 'refund', 'created_at' => '2026-11-06 11:00:00']);
    $world->db->insert('client_balance', ['client_id' => 7, 'type' => 'invoice', 'rel_id' => '8', 'description' => 'Payment for invoice #8 using account credit.', 'amount' => -71.07]);

    $world->sync->run();
    $doc = $world->program->calls[1][1];
    $payments = array_values(array_filter($world->program->calls, static fn (array $c): bool => $c[0] === 'payment'));

    expect($doc['total'])->toBe(71.07)
        ->and(round(array_sum(array_column($doc['lines'], 'amount')) + array_sum(array_column($doc['lines'], 'tax_amount')), 2))->toBe(71.07)
        ->and(array_column($doc['lines'], 'tax'))->toBe(['OUTPUT2', 'OUTPUT2', 'NONE', 'OUTPUT2'])
        ->and(array_column($doc['lines'], 'tax_amount'))->toBe([4.5, -0.38, 0.0, 0.15])
        ->and(array_map(static fn (array $c): array => [$c[1]['account'], $c[1]['amount'], $c[1]['reference']], $payments))
        ->toBe([['090', 50.0, 'Transaction 1'], ['850', 21.07, 'Account credit']]);
});

test('a refund becomes a credit note paid back through the payment method, a refund to credit into account credit', function (): void {
    $world = acctSyncWorld();
    acctSyncInvoice($world, 5, ['status' => 'refunded', 'paid_at' => '2026-11-06 09:00:00', 'notes' => "2026-11-08 10:00:00: Refunded 10.00 GBP, credit note #6.       \n"], [['title' => 'Hosting', 'price' => 30.0, 'quantity' => 1, 'taxed' => 0]]);
    $world->db->insert('`transaction`', ['invoice_id' => 5, 'gateway_id' => 3, 'amount' => 30.0, 'status' => 'processed', 'created_at' => '2026-11-06 08:59:00']);
    acctSyncInvoice($world, 6, ['status' => 'refunded', 'serie' => 'CN-', 'nr' => '3', 'created_at' => '2026-11-08 10:00:00'], [['title' => 'Refund of invoice INV5', 'price' => -30.0, 'quantity' => 1, 'taxed' => 0]]);
    $world->db->insert('client_balance', ['client_id' => 7, 'type' => 'invoice', 'rel_id' => '6', 'description' => 'Refund of invoice INV5 to account credit', 'amount' => 12.0]);
    // A downgrade's credit note names no refunded invoice: all of it is account credit.
    acctSyncInvoice($world, 9, ['status' => 'refunded', 'serie' => 'CN-', 'nr' => '4', 'created_at' => '2026-11-09 10:00:00'], [['title' => 'Credit for the unused time of order #2 (downgrade)', 'price' => -4.0, 'quantity' => 1, 'taxed' => 0, 'task' => 'account_credit']]);

    $world->sync->run();
    $notes = array_values(array_filter($world->program->calls, static fn (array $c): bool => $c[0] === 'creditNote'));

    expect(array_column($world->program->calls, 0))->toBe(['contact', 'invoice', 'payment', 'creditNote', 'creditNote'])
        ->and($notes[0][1])->toMatchArray(['number' => 'CN-00003', 'reference' => 'INV00005', 'total' => 30.0, 'contact' => 'contact-7'])
        ->and($notes[0][1]['lines'][0])->toMatchArray(['amount' => 30.0, 'unit_price' => 30.0, 'account' => '200', 'tax' => 'NONE'])
        ->and($notes[0][1]['refunds'])->toBe([
            ['account' => '850', 'amount' => 12.0, 'date' => '2026-11-08', 'reference' => 'Account credit'],
            ['account' => '090', 'amount' => 18.0, 'date' => '2026-11-08', 'reference' => 'Refund CN-00003'],
        ])
        ->and($notes[1][1]['refunds'])->toBe([['account' => '850', 'amount' => 4.0, 'date' => '2026-11-09', 'reference' => 'Account credit']])
        ->and(acctSyncRow($world, 'credit', 6))->toMatchArray(['status' => 'sent', 'remote_id' => 'cn-CN-00003']);
});

test('a part refund of an Add funds invoice comes out of the account credit account, not sales', function (): void {
    $world = acctSyncWorld();
    acctSyncInvoice($world, 5, ['status' => 'paid', 'paid_at' => '2026-11-06 09:00:00', 'notes' => "2026-11-08 10:00:00: Refunded 10.00 GBP, credit note #6.       \n"], [['title' => 'Add funds', 'price' => 30.0, 'quantity' => 1, 'taxed' => 0, 'type' => 'deposit']]);
    $world->db->insert('`transaction`', ['invoice_id' => 5, 'gateway_id' => 3, 'amount' => 30.0, 'status' => 'processed', 'type' => 'paypal_rest', 'created_at' => '2026-11-06 08:59:00']);
    // Service::creditNote() makes a Custom line for a part refund.
    acctSyncInvoice($world, 6, ['status' => 'refunded', 'serie' => 'CN-', 'nr' => '3', 'created_at' => '2026-11-08 10:00:00'], [['title' => 'Refund of invoice INV5', 'price' => -10.0, 'quantity' => 1, 'taxed' => 0]]);

    $world->sync->run();
    $note = array_values(array_filter($world->program->calls, static fn (array $c): bool => $c[0] === 'creditNote'))[0][1];
    $invoice = array_values(array_filter($world->program->calls, static fn (array $c): bool => $c[0] === 'invoice'))[0][1];

    expect($invoice['lines'][0]['account'])->toBe('850')
        ->and($note['lines'][0]['account'])->toBe('850')
        ->and($note['refunds'])->toBe([['account' => '090', 'amount' => 10.0, 'date' => '2026-11-08', 'reference' => 'Refund CN-00003']]);
});

test('a deleted or cancelled invoice is voided unless its payment is at the program', function (): void {
    $world = acctSyncWorld();
    acctSyncInvoice($world, 1, []);
    acctSyncInvoice($world, 2, []);
    $world->sync->run();
    $world->db->executeStatement("UPDATE invoice SET status = 'canceled' WHERE id = 1");
    $world->db->executeStatement('DELETE FROM invoice WHERE id = 2');
    $world->db->insert('invoice_accounting_sync', ['program' => 'xero', 'entity' => 'payment', 'local_id' => 2, 'remote_id' => 'pay-x', 'status' => 'sent', 'created_at' => '2026-11-06', 'updated_at' => '2026-11-06']);
    $world->program->calls = [];

    $world->sync->run();

    expect($world->program->calls)->toBe([['void', ['id' => 'inv-INV00001']]])
        ->and(acctSyncRow($world, 'invoice', 1)['status'])->toBe('voided')
        ->and(acctSyncRow($world, 'invoice', 2))->toMatchArray(['status' => 'stopped', 'last_error' => 'Deleted or cancelled here, but kept at Xero because it has a payment there.']);
});

test('a missing tax rate choice fails the record with a clear message', function (): void {
    $world = acctSyncWorld(['tax_map' => '{"none":"NONE"}']);
    acctSyncInvoice($world, 1, ['taxrate' => '20'], [['title' => 'Hosting', 'price' => 10.0, 'quantity' => 1, 'taxed' => 1]]);

    $world->sync->run();

    expect(acctSyncRow($world, 'invoice', 1))->toMatchArray(['status' => 'failed', 'attempts' => 1, 'last_error' => 'Choose the Xero tax rate for "VAT|20" in the accounting settings.']);
});

test('the connection state is single use and the tokens are stored encrypted', function (): void {
    $world = acctSyncWorld(['tokens' => '']);
    $session = [];
    $world->di['session'] = new class($session) {
        public function __construct(public array $values)
        {
        }

        public function get(string $key): mixed
        {
            return $this->values[$key] ?? null;
        }

        public function set(string $key, mixed $value): void
        {
            $this->values[$key] = $value;
        }

        public function delete(string $key): void
        {
            unset($this->values[$key]);
        }
    };
    $world->di['url'] = new class {
        public function adminLink(string $path): string
        {
            return 'https://billing.example.com/admin/' . $path;
        }
    };
    parse_str((string) parse_url($world->sync->connectUrl(), PHP_URL_QUERY), $query);

    expect(fn () => $world->sync->finishConnect(['state' => 'forged', 'code' => 'c']))->toThrow(InformationException::class);
    // The forged try used the state up.
    expect(fn () => $world->sync->finishConnect(['state' => $query['state'], 'code' => 'c']))->toThrow(InformationException::class);

    parse_str((string) parse_url($world->sync->connectUrl(), PHP_URL_QUERY), $query);
    $world->sync->finishConnect(['state' => $query['state'], 'code' => 'c']);

    expect($world->system->params['accounting_tokens'])->toStartWith('enc:')
        ->and(json_decode(substr($world->system->params['accounting_tokens'], 4), true))->toMatchArray(['refresh_token' => 'r', 'tenant_id' => 't']);
});

test('connecting another organisation clears the choices and sends everything again; reconnecting the same one keeps them', function (): void {
    $world = acctSyncWorld(['tenant_xero' => 't']);
    $world->di['session'] = new class {
        public array $values = [];

        public function get(string $key): mixed
        {
            return $this->values[$key] ?? null;
        }

        public function set(string $key, mixed $value): void
        {
            $this->values[$key] = $value;
        }

        public function delete(string $key): void
        {
            unset($this->values[$key]);
        }
    };
    $world->di['url'] = new class {
        public function adminLink(string $path): string
        {
            return 'https://billing.example.com/admin/' . $path;
        }
    };
    $connect = function () use ($world): void {
        parse_str((string) parse_url($world->sync->connectUrl(), PHP_URL_QUERY), $query);
        $world->sync->finishConnect(['state' => $query['state'], 'code' => 'c']);
    };
    acctSyncInvoice($world, 1, []);
    $world->sync->run();
    expect(acctSyncRow($world, 'invoice', 1))->toMatchArray(['status' => 'sent']);

    $choices = array_intersect_key($world->system->params, array_flip(['accounting_sales_account', 'accounting_bank_accounts', 'accounting_tax_map']));
    $connect();
    expect(acctSyncRow($world, 'invoice', 1))->toMatchArray(['status' => 'sent'])
        ->and(array_intersect_key($world->system->params, $choices))->toBe($choices);

    $world->program->tenant = 'other-org';
    $connect();
    expect(acctSyncRow($world, 'invoice', 1))->toBeFalse()
        ->and($world->system->params['accounting_tenant_xero'])->toBe('other-org')
        ->and(array_values(array_intersect_key($world->system->params, $choices)))->toBe(['', '', '']);
    $world->system->params = $choices + $world->system->params;
    $world->sync->run();
    expect(acctSyncRow($world, 'invoice', 1))->toMatchArray(['status' => 'sent'])
        ->and(count(array_filter($world->program->calls, static fn (array $c): bool => $c[0] === 'invoice')))->toBe(2);
});

test('a client the program refuses is tried once per run, not once per invoice', function (): void {
    $world = acctSyncWorld();
    acctSyncInvoice($world, 1, []);
    acctSyncInvoice($world, 2, []);
    acctSyncInvoice($world, 3, []);
    $world->program->fail['contact'] = new InformationException('Xero refused the request: contact is archived');

    $world->sync->run();

    expect(count(array_filter($world->program->calls, static fn (array $c): bool => $c[0] === 'contact')))->toBe(1)
        ->and(acctSyncRow($world, 'client', 7))->toMatchArray(['status' => 'failed', 'attempts' => 1]);
});

test('the settings form keeps each gateway under its own id', function (): void {
    $world = acctSyncWorld();
    $world->db->insert('pay_gateway', ['id' => 5, 'name' => 'Stripe']);
    $world->di['session'] = new class {
        public function get(string $key): mixed
        {
            return null;
        }

        public function delete(string $key): void
        {
        }
    };
    $world->di['url'] = new class {
        public function adminLink(string $path): string
        {
            return '/admin/' . $path;
        }
    };
    $twig = new Twig\Environment(new Twig\Loader\FilesystemLoader(__DIR__ . '/../../../templates/admin'));
    $twig->addFilter(new Twig\TwigFilter('trans', static fn (string $text, array $params = []): string => strtr($text, $params)));
    $twig->addFilter(new Twig\TwigFilter('api_url', static fn (string $path): string => '/api/' . $path));
    $twig->addFilter(new Twig\TwigFilter('format_datetime', static fn (mixed $date): string => (string) $date));
    $twig->addFunction(new Twig\TwigFunction('fb_api_form', static fn (): string => '', ['is_safe' => ['html']]));

    $html = $twig->render('mod_invoice_settings_accounting.html.twig', ['admin' => ['invoice_accounting_get' => $world->sync->get()]]);

    expect($html)->toContain('name="bank_accounts[credit]"')->toContain('name="bank_accounts[3]"')->toContain('name="bank_accounts[5]"')
        ->not->toContain('name="bank_accounts[0]"');
});

test('the return from the program is answered before any session, with a same-site hop that keeps only its own fields', function (): void {
    $url = Mockery::mock(ClientFence\Url::class);
    $url->expects('adminLink')->with('invoice/accounting/callback', ['state' => 's1', 'code' => 'c1', 'moved' => 1])->andReturn('https://billing.example/admin/invoice/accounting/callback?moved=1');
    $request = Symfony\Component\HttpFoundation\Request::create(ADMIN_PREFIX . '/invoice/accounting/callback', 'GET', ['state' => 's1', 'code' => 'c1', 'scope' => 'x', 'session_state' => ['a']]);

    $response = Sync::returnHop($request, $url);

    expect($response?->getContent())->toContain('location.replace("https://billing.example/admin/invoice/accounting/callback?moved=1")')
        ->and($response->headers->get('Content-Security-Policy'))->toContain(ClientFence\Csp::nonce())
        ->and(Sync::returnHop(Symfony\Component\HttpFoundation\Request::create(ADMIN_PREFIX . '/invoice/accounting/callback', 'GET', ['moved' => '1']), $url))->toBeNull()
        ->and(Sync::returnHop(Symfony\Component\HttpFoundation\Request::create('/invoice/accounting/callback'), $url))->toBeNull();
});

test('a gateway payment whose transaction is not processed yet waits, then goes to the bank account, not account credit', function (): void {
    $world = acctSyncWorld();
    acctSyncInvoice($world, 5, ['status' => 'paid', 'paid_at' => date('Y-m-d H:i:s')]);
    // As every gateway does it: the balance pays the invoice, the transaction is set processed last.
    $world->db->insert('client_balance', ['client_id' => 7, 'type' => 'invoice', 'rel_id' => '5', 'description' => 'Payment for invoice #5 using account credit.', 'amount' => -10.0]);
    $world->db->insert('`transaction`', ['id' => 40, 'invoice_id' => 5, 'gateway_id' => 3, 'amount' => 10.0, 'status' => 'received', 'type' => 'payment', 'created_at' => date('Y-m-d H:i:s')]);

    $world->sync->run();
    expect(array_column($world->program->calls, 0))->toBe(['contact', 'invoice'])
        ->and(acctSyncRow($world, 'payment', 5))->toBeFalse();

    $world->db->update('`transaction`', ['status' => 'processed'], ['id' => 40]);
    $world->sync->run();
    expect($world->program->calls[2][0])->toBe('payment')
        ->and($world->program->calls[2][1])->toMatchArray(['invoice' => 'inv-INV00005', 'account' => '090', 'amount' => 10.0, 'date' => substr(date('Y-m-d'), 0, 10), 'reference' => 'Transaction 40', 'currency' => 'GBP']);

    // Its rate to another currency is ClientFence's, from the default one's; null for one not set up.
    $world->db->insert('currency', ['code' => 'USD', 'conversion_rate' => 1.25]);
    $world->db->insert('currency', ['code' => 'GBP', 'conversion_rate' => 1.0]);
    expect($world->program->calls[2][1]['rate']('USD'))->toBe(1.25)
        ->and($world->program->calls[2][1]['rate']('EUR'))->toBeNull();
});

test('an unpaid invoice left without lines is voided there', function (): void {
    $world = acctSyncWorld();
    acctSyncInvoice($world, 1, []);
    $world->sync->run();
    $world->db->executeStatement('DELETE FROM invoice_item WHERE invoice_id = 1');

    $world->sync->run();

    expect(end($world->program->calls))->toBe(['void', ['id' => 'inv-INV00001']])
        ->and(acctSyncRow($world, 'invoice', 1))->toMatchArray(['status' => 'voided']);
});

test('failed and stopped records lead the log, and Send all again starts them over', function (): void {
    $world = acctSyncWorld();
    $world->db->insert('invoice_accounting_sync', ['program' => 'xero', 'entity' => 'invoice', 'local_id' => 900, 'status' => 'stopped', 'attempts' => 10, 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00']);
    for ($i = 1; $i <= 60; ++$i) {
        $world->db->insert('invoice_accounting_sync', ['program' => 'xero', 'entity' => 'client', 'local_id' => $i, 'status' => 'sent', 'remote_id' => 'c' . $i, 'created_at' => '2026-11-10 00:00:00', 'updated_at' => '2026-11-10 00:00:00']);
    }
    $world->di['session'] = new class {
        public function get(string $key): mixed
        {
            return null;
        }
    };
    $world->di['url'] = new class {
        public function adminLink(string $path): string
        {
            return '/admin/' . $path;
        }
    };

    expect($world->sync->get()['rows'][0])->toMatchArray(['local_id' => 900, 'status' => 'stopped'])
        ->and($world->sync->resendAll())->toBe(1)
        ->and(acctSyncRow($world, 'invoice', 900))->toMatchArray(['status' => 'queued', 'attempts' => 0]);
});

test('a program that cannot be reached stops the run without using up the tries, with one notice, until it waits out', function (): void {
    $world = acctSyncWorld();
    acctSyncInvoice($world, 1, []);
    acctSyncInvoice($world, 2, []);
    $world->program->fail['contact'] = new RateLimited(300, 'Xero', new InformationException('Could not reach Xero: timeout'));

    $world->sync->run();
    $world->sync->run();

    expect(acctSyncRow($world, 'client', 7))->toBeFalse()
        ->and(count($world->program->calls))->toBe(1)
        ->and((int) $world->system->params['accounting_wait_until'])->toBeGreaterThan(time() + 200)
        ->and($world->system->params['accounting_wait_reason'])->toBe('Could not reach Xero: timeout')
        ->and($world->db->fetchFirstColumn('SELECT kind FROM mod_clientfence_notification'))->toBe(['accounting_paused']);
});

test('an invoice cancelled and then open again goes as a new invoice, with -2 on its number', function (): void {
    $world = acctSyncWorld();
    acctSyncInvoice($world, 1, []);
    $world->sync->run();
    $world->db->update('invoice', ['status' => 'canceled'], ['id' => 1]);
    $world->sync->run();
    expect(acctSyncRow($world, 'invoice', 1))->toMatchArray(['status' => 'voided']);

    $world->db->update('invoice', ['status' => 'unpaid', 'updated_at' => date('Y-m-d H:i:s')], ['id' => 1]);
    $world->program->calls = [];
    $world->sync->run();

    expect($world->program->calls[0][0])->toBe('invoice')
        ->and($world->program->calls[0][1])->toMatchArray(['number' => 'INV00001-2', 'remote_id' => null])
        ->and(acctSyncRow($world, 'invoice', 1))->toMatchArray(['status' => 'sent', 'remote_id' => 'inv-INV00001-2', 'revision' => 1]);
});

test('a downgrade credit note that a refund took back is voided there', function (): void {
    $world = acctSyncWorld();
    acctSyncInvoice($world, 8, ['status' => 'refunded', 'serie' => 'CN'], [['title' => 'Downgrade', 'price' => -40.0, 'quantity' => 1, 'taxed' => 0, 'task' => 'account_credit']]);
    $world->db->insert('client_balance', ['client_id' => 7, 'type' => 'invoice', 'rel_id' => '8', 'description' => 'Downgrade credit', 'amount' => 40.0]);
    $world->sync->run();
    expect(acctSyncRow($world, 'credit', 8))->toMatchArray(['status' => 'sent', 'remote_id' => 'cn-CN00008']);

    $world->db->executeStatement("UPDATE invoice_item SET task = 'account_credit_taken_back' WHERE invoice_id = 8");
    $world->sync->run();

    expect(end($world->program->calls))->toBe(['voidCreditNote', ['id' => 'cn-CN00008']])
        ->and(acctSyncRow($world, 'credit', 8))->toMatchArray(['status' => 'voided']);
});

test('money beyond an invoice and a payment without one go into the account credit account', function (): void {
    $world = acctSyncWorld();
    acctSyncInvoice($world, 5, ['status' => 'paid', 'paid_at' => '2026-11-06 09:00:00']);
    $world->db->insert('client_balance', ['client_id' => 7, 'type' => 'invoice', 'rel_id' => '5', 'description' => 'Payment for invoice #5 using account credit.', 'amount' => -10.0]);
    $world->db->insert('`transaction`', ['id' => 41, 'invoice_id' => 5, 'gateway_id' => 3, 'amount' => 15.0, 'status' => 'processed', 'type' => 'payment', 'created_at' => '2026-11-06 08:59:00']);
    // A gateway payment with no invoice that credited the client (Stripe, PayPal subscriptions).
    $world->db->insert('`transaction`', ['id' => 42, 'gateway_id' => 3, 'amount' => 7.0, 'status' => 'processed', 'type' => 'payment', 'currency' => 'GBP', 'created_at' => '2026-11-07 08:00:00']);
    $world->db->insert('client_balance', ['client_id' => 7, 'type' => 'transaction', 'rel_id' => '42', 'description' => 'Stripe transaction', 'amount' => 7.0]);

    $world->sync->run();

    $calls = array_map(static fn (array $c): array => [$c[0], $c[1]['number'] ?? $c[1]['reference'] ?? '', $c[1]['account'] ?? ($c[1]['lines'][0]['account'] ?? ''), $c[1]['amount'] ?? $c[1]['total'] ?? null], $world->program->calls);
    expect($calls)->toBe([
        ['contact', '', '', null],
        ['invoice', 'INV00005', '200', 10.0],
        ['payment', 'Transaction 41', '090', 10.0],
        ['invoice', 'CREDIT-41', '850', 5.0],
        ['payment', 'Transaction 41', '090', 5.0],
        ['invoice', 'CREDIT-42', '850', 7.0],
        ['payment', 'Transaction 42', '090', 7.0],
    ]);

    // A later payment on the paid invoice is all extra; nothing is sent twice.
    $world->db->insert('`transaction`', ['id' => 43, 'invoice_id' => 5, 'gateway_id' => 3, 'amount' => 2.0, 'status' => 'processed', 'type' => 'payment', 'created_at' => date('Y-m-d H:i:s', time() + 5)]);
    $world->program->calls = [];
    $world->sync->run();
    expect(array_column($world->program->calls, 0))->toBe(['invoice', 'payment'])
        ->and($world->program->calls[0][1])->toMatchArray(['number' => 'CREDIT-43', 'total' => 2.0]);
});

test('switching the test environment on or off ends the connection and clears the choices; the same choice keeps them', function (): void {
    $world = acctSyncWorld(['sandbox' => '0']);

    $world->sync->save(['sandbox' => '0']);
    expect($world->system->params['accounting_tokens'])->not->toBe('')
        ->and($world->system->params['accounting_sales_account'])->not->toBe('');

    $world->sync->save(['sandbox' => '1']);
    expect($world->system->params['accounting_tokens'])->toBe('')
        ->and($world->system->params['accounting_sandbox'])->toBe('1')
        ->and([$world->system->params['accounting_sales_account'], $world->system->params['accounting_bank_accounts'], $world->system->params['accounting_tax_map']])->toBe(['', '', '']);
});

test('the program learns which contacts other clients already have, so one is never shared', function (): void {
    $world = acctSyncWorld();
    $world->db->insert('invoice_accounting_sync', ['program' => 'xero', 'entity' => 'client', 'local_id' => 8, 'remote_id' => 'contact-of-8', 'status' => 'sent', 'attempts' => 0, 'created_at' => '2026-11-05 10:00:00', 'updated_at' => '2026-11-05 10:00:00']);
    acctSyncInvoice($world, 1, []);
    $world->sync->run();
    $taken = $world->program->calls[0][1]['taken'];

    expect($world->program->calls[0][0])->toBe('contact')
        ->and($taken('contact-of-8'))->toBeTrue()
        ->and($taken('contact-7'))->toBeFalse()
        ->and($taken('elsewhere'))->toBeFalse();
});
