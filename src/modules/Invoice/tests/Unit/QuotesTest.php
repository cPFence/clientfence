<?php

declare(strict_types=1);

/**
 * ClientFence: quotes (Invoice\Quotes) and their PDF (Invoice\QuotePdf), on an in-memory SQLite copy
 * of the tables.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Invoice\QuotePdf;
use ClientFence\Mod\Invoice\Quotes;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

function quotesDb(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
        'CREATE TABLE currency (code TEXT PRIMARY KEY, is_default INTEGER)',
        'CREATE TABLE client (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, company TEXT, email TEXT, country TEXT, currency TEXT, lang TEXT, email_approved INTEGER DEFAULT 0)',
        'CREATE TABLE tax (id INTEGER PRIMARY KEY, name TEXT, taxrate REAL, country TEXT, state TEXT)',
        'CREATE TABLE admin (id INTEGER PRIMARY KEY, name TEXT)',
        'CREATE TABLE invoice (id INTEGER PRIMARY KEY, hash TEXT)',
        'CREATE TABLE invoice_quote (id INTEGER PRIMARY KEY AUTOINCREMENT, hash TEXT NOT NULL UNIQUE, client_id INTEGER, first_name TEXT NOT NULL DEFAULT \'\', last_name TEXT NOT NULL DEFAULT \'\', company TEXT NOT NULL DEFAULT \'\', email TEXT NOT NULL DEFAULT \'\', country TEXT NOT NULL DEFAULT \'\', currency TEXT NOT NULL, subject TEXT NOT NULL, stage TEXT NOT NULL DEFAULT \'draft\', valid_until TEXT, proposal TEXT NOT NULL, customer_notes TEXT NOT NULL, admin_notes TEXT NOT NULL, taxname TEXT NOT NULL DEFAULT \'\', taxrate REAL NOT NULL DEFAULT 0, invoice_id INTEGER, created_by INTEGER, sent_at TEXT, accepted_at TEXT, declined_at TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)',
        'CREATE TABLE invoice_quote_item (id INTEGER PRIMARY KEY AUTOINCREMENT, quote_id INTEGER NOT NULL, position INTEGER NOT NULL DEFAULT 0, description TEXT NOT NULL, quantity INTEGER NOT NULL DEFAULT 1, price REAL NOT NULL DEFAULT 0, discount REAL NOT NULL DEFAULT 0, taxed INTEGER NOT NULL DEFAULT 0)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    $db->insert('currency', ['code' => 'USD', 'is_default' => 1]);
    $db->insert('currency', ['code' => 'EGP', 'is_default' => 0]);
    $db->insert('admin', ['id' => 7, 'name' => 'Staff']);
    $db->insert('client', ['id' => 1, 'first_name' => 'Alice', 'last_name' => 'Smith', 'company' => 'Alice Co', 'email' => 'alice@example.com', 'country' => 'EG', 'currency' => 'USD', 'lang' => 'ar_SA']);

    return $db;
}

/**
 * The container: the client and invoice services record what they are asked to do.
 *
 * @return array{0: Pimple\Container, 1: ArrayObject<string, mixed>}
 */
function quotesContainer(Connection $db, float $clientTax = 0.0): array
{
    $log = new ArrayObject(['invoices' => [], 'approved' => [], 'welcome' => [], 'emails' => [], 'activity' => []]);
    $di = new Pimple\Container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;
    $di['logger'] = new class {
        public function info(...$args): void
        {
        }

        public function warning(...$args): void
        {
        }
    };
    $di['url'] = new class {
        public function link(string $path): string
        {
            return 'https://billing.example.com/' . $path;
        }

        public function adminLink(string $path): string
        {
            return 'https://billing.example.com/admin/' . $path;
        }
    };
    $di['db'] = new class($db) {
        public function __construct(private Connection $db)
        {
        }

        public function load(string $type, int $id): ?Model_Client
        {
            $row = $this->db->fetchAssociative('SELECT * FROM client WHERE id = ?', [$id]);
            if ($row === false) {
                return null;
            }
            $client = new Model_Client();
            $client->loadBean(new Tests\Helpers\DummyBean());
            foreach ($row as $key => $value) {
                $client->{$key} = $value;
            }

            return $client;
        }

        public function store(object $model): void
        {
            $this->db->update('client', ['currency' => $model->currency], ['id' => $model->id]);
        }
    };
    $services = [
        'system' => new class {
            public function getParamValue(string $param, mixed $default = null): mixed
            {
                return $param === 'tax_enabled' ? true : $default;
            }

            public function getCompany(): array
            {
                return ['name' => 'Example Hosting', 'email' => 'billing@example.com', 'tel' => '', 'address_1' => '1 Example Street', 'address_2' => '', 'address_3' => ''];
            }
        },
        'tax' => new class($clientTax) {
            public function __construct(private float $rate)
            {
            }

            public function getTaxRateForClient(Model_Client $client, &$title = null): float
            {
                $title = $this->rate > 0 ? 'VAT' : '';

                return $this->rate;
            }
        },
        'currency' => new class {
            public function formatCurrency(mixed $amount, string $code, array $attributes = [], ?string $locale = null): string
            {
                return number_format((float) $amount, 2) . ' ' . $code;
            }
        },
        'invoice' => new class($db, $log) {
            public function __construct(private Connection $db, private ArrayObject $log)
            {
            }

            public function prepareInvoice(Model_Client $client, array $data): Model_Invoice
            {
                $invoice = new Model_Invoice();
                $invoice->loadBean(new Tests\Helpers\DummyBean());
                $invoice->id = 50 + count($this->log['invoices']);
                $this->db->insert('invoice', ['id' => $invoice->id, 'hash' => 'inv' . $invoice->id]);
                $invoice->client_id = $client->id;
                $this->log['invoices'] = [...$this->log['invoices'], ['client_id' => (int) $client->id, 'currency' => $client->currency] + $data];

                return $invoice;
            }

            public function approveInvoice(Model_Invoice $invoice, array $data): bool
            {
                $this->log['approved'] = [...$this->log['approved'], (int) $invoice->id];

                return true;
            }
        },
        'client' => new class($db, $log) {
            public function __construct(private Connection $db, private ArrayObject $log)
            {
            }

            public function adminCreateClient(array $data): int
            {
                $this->db->insert('client', array_intersect_key($data, array_flip(['email', 'first_name', 'last_name', 'company', 'country', 'currency'])));

                return (int) $this->db->lastInsertId();
            }

            public function sendAdminCreatedWelcomeEmailForClient(object $client): void
            {
                $this->log['welcome'] = [...$this->log['welcome'], (int) $client->id];
            }
        },
        'activity' => new class($log) {
            public function __construct(private ArrayObject $log)
            {
            }

            public function logEvent(array $event): void
            {
                $this->log['activity'] = [...$this->log['activity'], $event];
            }
        },
        'email' => new class($log) {
            public function __construct(private ArrayObject $log)
            {
            }

            public function sendTemplate(array $email): bool
            {
                $this->log['emails'] = [...$this->log['emails'], $email];

                return true;
            }
        },
    ];
    $di['mod'] = $di->protect(fn (string $mod): object => new class {
        public function getConfig(): array
        {
            return ['default_country' => 'EG'];
        }
    });
    $di['mod_service'] = $di->protect(function (string $mod, string $sub = '') use ($services): object {
        $key = strtolower($sub !== '' ? $sub : $mod);

        return $services[$key] ?? throw new RuntimeException('No service ' . $key);
    });

    return [$di, $log];
}

function quotesForm(array $data = []): array
{
    return $data + [
        'client_id' => 1,
        'subject' => 'Security hardening',
        'stage' => 'draft',
        'valid_until' => '2099-12-31',
        'proposal' => "Scope:\nAudit and fixes.",
        'customer_notes' => 'Paid in two parts.',
        'admin_notes' => 'From the call on Monday.',
        'items' => [
            ['description' => 'Audit', 'quantity' => '2', 'price' => '100', 'discount' => '', 'taxed' => '1'],
            ['description' => '', 'quantity' => '1', 'price' => '', 'discount' => ''],     // empty row: left out
            ['description' => 'Fixes', 'quantity' => '1', 'price' => '191.66', 'discount' => '21.73'],
        ],
    ];
}

test('a quote keeps its lines, and its totals follow the discount and the tax rule', function (): void {
    $db = quotesDb();
    [$di] = quotesContainer($db, 14.0);
    $id = Quotes::save($di, quotesForm(), 7);
    $quote = Quotes::get($di, $id);

    expect($quote['items'])->toHaveCount(2)
        ->and(array_column($quote['items'], 'unit_price'))->toBe([100.0, 150.01])
        ->and(array_column($quote['items'], 'amount'))->toBe([200.0, 150.01])
        ->and(array_column($quote['items'], 'tax'))->toBe([28.0, 0.0])
        ->and($quote['subtotal'])->toBe(350.01)
        ->and($quote['tax'])->toBe(28.0)
        ->and($quote['total'])->toBe(378.01)
        ->and($quote['taxname'])->toBe('VAT')
        ->and($quote['currency'])->toBe('USD')
        ->and($quote['recipient'])->toMatchArray(['name' => 'Alice Smith', 'company' => 'Alice Co', 'email' => 'alice@example.com', 'prospect' => false])
        ->and($quote['stage'])->toBe('draft')
        ->and($quote['open'])->toBeTrue()
        ->and($quote['hash'])->toMatch('/^[a-f0-9]{40}$/')
        ->and($db->fetchOne('SELECT created_by FROM invoice_quote'))->toBe(7);
});

test('the staff form is checked', function (array $change, string $message): void {
    [$di] = quotesContainer(quotesDb());

    expect(fn () => Quotes::save($di, array_merge(quotesForm(), $change)))->toThrow(InformationException::class, $message);
})->with([
    'no subject' => [['subject' => ' '], 'Enter the subject of the quote.'],
    'accepted by hand' => [['stage' => 'accepted'], 'Choose a stage'],
    'bad date' => [['valid_until' => '2026-02-30'], 'Enter the dates as year-month-day.'],
    'no lines' => [['items' => []], 'Add at least one line to the quote.'],
    'fraction' => [['items' => [['description' => 'Hours', 'quantity' => '2.5', 'price' => '10']]], 'Line 1: the quantity must be a whole number from 1.'],
    'price' => [['items' => [['description' => 'Hours', 'quantity' => '1', 'price' => '1e3']]], 'Line 1: enter the price as a number with at most two decimals.'],
    'discount' => [['items' => [['description' => 'Hours', 'quantity' => '1', 'price' => '10', 'discount' => '120']]], 'Line 1: the discount is a percentage from 0 to 100.'],
    'no description' => [['items' => [['description' => '', 'quantity' => '1', 'price' => '10']]], 'Line 1: enter a description of at most 255 characters.'],
    'unknown client' => [['client_id' => 9], 'Client not found'],
]);

test('a prospect needs a name and a free email, and gets the chosen currency and the tax of its country', function (): void {
    $db = quotesDb();
    $db->insert('tax', ['name' => 'Egypt VAT', 'taxrate' => 14, 'country' => 'EG', 'state' => '']);
    [$di] = quotesContainer($db);
    $prospect = ['client_id' => '', 'first_name' => 'Bob', 'last_name' => 'Lee', 'company' => 'Bob Ltd', 'email' => ' Bob@Example.com ', 'country' => 'eg', 'currency' => 'EGP'];

    $quote = Quotes::get($di, Quotes::save($di, array_merge(quotesForm(), $prospect)));
    expect($quote['client_id'])->toBeNull()
        ->and($quote['recipient'])->toMatchArray(['name' => 'Bob Lee', 'email' => 'bob@example.com', 'country' => 'EG', 'prospect' => true])
        ->and($quote['currency'])->toBe('EGP')
        ->and([$quote['taxname'], $quote['taxrate']])->toBe(['Egypt VAT', 14.0]);

    // No country: the default country new accounts get, as the invoice on acceptance is taxed.
    $quote = Quotes::get($di, Quotes::save($di, array_merge(quotesForm(), $prospect, ['country' => '', 'email' => 'bob2@example.com'])));
    expect([$quote['recipient']['country'], $quote['taxname'], $quote['taxrate']])->toBe(['', 'Egypt VAT', 14.0]);

    expect(fn () => Quotes::save($di, array_merge(quotesForm(), $prospect, ['email' => 'ALICE@example.com'])))
        ->toThrow(InformationException::class, 'Client #1 has this email: choose that client instead.')
        ->and(fn () => Quotes::save($di, array_merge(quotesForm(), $prospect, ['first_name' => ''])))
        ->toThrow(InformationException::class, 'Choose a client, or enter the first name and email of the prospect.')
        ->and(fn () => Quotes::save($di, array_merge(quotesForm(), $prospect, ['country' => 'XX'])))
        ->toThrow(InformationException::class, 'Choose a country from the list.');
});

test('the list filters by stage and search, newest first', function (): void {
    $db = quotesDb();
    [$di] = quotesContainer($db);
    $a = Quotes::save($di, quotesForm(['subject' => 'Website']));
    $b = Quotes::save($di, quotesForm(['subject' => 'Mobile app', 'stage' => 'on_hold']));
    $c = Quotes::save($di, array_merge(quotesForm(['subject' => 'Audit']), ['client_id' => 0, 'first_name' => 'Carol', 'last_name' => 'Day', 'email' => 'carol@example.com']));

    expect(array_column(Quotes::getList($di, [])['list'], 'id'))->toBe([$c, $b, $a])
        ->and(array_column(Quotes::getList($di, ['stage' => 'on_hold'])['list'], 'id'))->toBe([$b])
        ->and(array_column(Quotes::getList($di, ['search' => 'carol day'])['list'], 'id'))->toBe([$c])
        ->and(array_column(Quotes::getList($di, ['search' => 'alice'])['list'], 'id'))->toBe([$b, $a])
        ->and(array_column(Quotes::getList($di, ['search' => '#' . $a])['list'], 'id'))->toBe([$a])
        ->and(Quotes::getList($di, [], 1, 2)['pages'])->toBe(2);
});

test('sending emails the client the PDF and the link, and a draft becomes delivered', function (): void {
    $db = quotesDb();
    [$di, $log] = quotesContainer($db);
    $id = Quotes::save($di, quotesForm());

    Quotes::send($di, $id);
    $email = $log['emails'][0];
    $quote = Quotes::get($di, $id);

    expect($email['to_client'])->toBe(1)
        ->and($email['code'])->toBe('mod_invoice_quote')
        ->and($email['quote_link'])->toBe('https://billing.example.com/quote/' . $quote['hash'])
        ->and($email['quote'])->not->toHaveKeys(['admin_notes', 'hash'])
        ->and($email['attachment']['name'])->toBe('quote-' . $id . '.pdf')
        ->and(substr($email['attachment']['content'], 0, 5))->toBe('%PDF-')
        ->and($quote['stage'])->toBe('delivered')
        ->and($quote['sent_at'])->not->toBeNull();
});

test('accepting makes the invoice with the discounted lines and emails it after', function (): void {
    $db = quotesDb();
    [$di, $log] = quotesContainer($db);
    $id = Quotes::save($di, quotesForm());

    $invoiceId = Quotes::accept($di, $id, 'client');
    $quote = Quotes::get($di, $id);

    expect($invoiceId)->toBe(50)
        ->and($log['invoices'][0]['client_id'])->toBe(1)
        ->and($log['invoices'][0]['text_1'])->toBe('Quote #' . $id . ': Security hardening')
        ->and(array_map(fn (array $i): array => [$i['title'], $i['price'], $i['quantity'], $i['taxed']], $log['invoices'][0]['items']))
        ->toBe([['Audit', 100.0, 2, true], ['Fixes', 150.01, 1, false]])
        ->and($log['approved'])->toBe([50])
        ->and($log['welcome'])->toBe([])
        ->and($quote['stage'])->toBe('accepted')
        ->and($quote['invoice_id'])->toBe(50)
        ->and($quote['open'])->toBeFalse();

    expect(fn () => Quotes::accept($di, $id, 'client'))->toThrow(InformationException::class, 'This quote is already accepted.')
        ->and($log['invoices'])->toHaveCount(1);
});

test('a prospect who accepts by the link gets an account with a confirmed email and the welcome email', function (): void {
    $db = quotesDb();
    [$di, $log] = quotesContainer($db);
    $id = Quotes::save($di, array_merge(quotesForm(), ['client_id' => 0, 'first_name' => 'Bob', 'last_name' => 'Lee', 'company' => 'Bob Ltd', 'email' => 'bob@example.com', 'country' => 'EG', 'currency' => 'EGP']));

    Quotes::accept($di, $id, 'link');
    $client = $db->fetchAssociative("SELECT * FROM client WHERE email = 'bob@example.com'");

    expect($client)->toMatchArray(['first_name' => 'Bob', 'last_name' => 'Lee', 'company' => 'Bob Ltd', 'country' => 'EG', 'currency' => 'EGP', 'email_approved' => 1])
        ->and($log['welcome'])->toBe([(int) $client['id']])
        ->and($log['invoices'][0]['client_id'])->toBe((int) $client['id'])
        ->and(Quotes::get($di, $id)['client_id'])->toBe((int) $client['id']);
});

test('a prospect whose email got an account in the meantime is quoted to that client', function (): void {
    $db = quotesDb();
    [$di, $log] = quotesContainer($db);
    $id = Quotes::save($di, array_merge(quotesForm(), ['client_id' => 0, 'first_name' => 'Bob', 'email' => 'bob@example.com']));
    $db->insert('client', ['id' => 5, 'first_name' => 'Bob', 'email' => 'bob@example.com', 'currency' => 'USD']);

    Quotes::accept($di, $id, 'staff');

    expect($log['invoices'][0]['client_id'])->toBe(5)
        ->and($log['welcome'])->toBe([])
        ->and($db->fetchOne('SELECT COUNT(*) FROM client'))->toBe(2);
});

test('an expired, lost or other-currency quote is not accepted; staff may accept an expired one', function (): void {
    $db = quotesDb();
    [$di, $log] = quotesContainer($db);
    $expired = Quotes::save($di, quotesForm(['valid_until' => '2020-01-01']));
    $lost = Quotes::save($di, quotesForm(['stage' => 'lost']));
    $egp = Quotes::save($di, array_merge(quotesForm(), ['client_id' => 0, 'first_name' => 'Bob', 'email' => 'bob@example.com', 'currency' => 'EGP']));
    $db->insert('client', ['id' => 5, 'first_name' => 'Bob', 'email' => 'bob@example.com', 'currency' => 'USD']);

    expect(fn () => Quotes::accept($di, $expired, 'link'))->toThrow(InformationException::class, 'This quote has expired.')
        ->and(fn () => Quotes::accept($di, $lost, 'client'))->toThrow(InformationException::class, 'This quote can no longer be accepted.')
        ->and(fn () => Quotes::accept($di, $egp, 'link'))->toThrow(InformationException::class, 'This quote is in EGP, but the account uses USD.')
        ->and(Quotes::get($di, $egp)['stage'])->toBe('draft')
        ->and(Quotes::accept($di, $expired, 'staff'))->toBe(50)
        ->and($log['invoices'])->toHaveCount(1);
});

test('an accepted quote keeps all but its admin notes; declining makes it lost', function (): void {
    $db = quotesDb();
    [$di] = quotesContainer($db);
    $id = Quotes::save($di, quotesForm());
    Quotes::accept($di, $id, 'staff');

    Quotes::save($di, quotesForm(['id' => $id, 'subject' => 'Changed', 'admin_notes' => 'Paid by bank transfer.']));
    expect(Quotes::get($di, $id))->toMatchArray(['subject' => 'Security hardening', 'admin_notes' => 'Paid by bank transfer.', 'stage' => 'accepted']);

    $other = Quotes::save($di, quotesForm());
    Quotes::decline($di, $other);
    expect(Quotes::get($di, $other)['stage'])->toBe('lost')
        ->and(fn () => Quotes::decline($di, $other))->toThrow(InformationException::class, 'This quote can no longer be declined.');
});

test('a new recipient gets a new link; other edits keep it', function (): void {
    $db = quotesDb();
    [$di] = quotesContainer($db);
    $db->insert('client', ['id' => 2, 'first_name' => 'Carl', 'email' => 'carl@example.com', 'currency' => 'USD']);
    $id = Quotes::save($di, quotesForm(['stage' => 'delivered']));
    $first = Quotes::get($di, $id)['hash'];

    Quotes::save($di, quotesForm(['id' => $id, 'stage' => 'delivered', 'subject' => 'Changed']));
    expect(Quotes::get($di, $id)['hash'])->toBe($first);

    Quotes::save($di, quotesForm(['id' => $id, 'stage' => 'delivered', 'client_id' => 2]));
    $second = Quotes::get($di, $id)['hash'];
    expect($second)->not->toBe($first)
        ->and(Quotes::findByHash($di, $first))->toBeNull();

    $prospect = ['client_id' => 0, 'first_name' => 'Bob', 'email' => 'bob@example.com'];
    Quotes::save($di, array_merge(quotesForm(['id' => $id, 'stage' => 'delivered']), $prospect));
    $third = Quotes::get($di, $id)['hash'];
    Quotes::save($di, array_merge(quotesForm(['id' => $id, 'stage' => 'delivered']), $prospect, ['first_name' => 'Robert']));
    expect($third)->not->toBe($second)
        ->and(Quotes::get($di, $id)['hash'])->toBe($third);

    Quotes::save($di, array_merge(quotesForm(['id' => $id, 'stage' => 'delivered']), $prospect, ['email' => 'rob@example.com']));
    expect(Quotes::get($di, $id)['hash'])->not->toBe($third);
});

test('a link finds its quote only by the full hash; the client area lists sent quotes', function (): void {
    $db = quotesDb();
    [$di] = quotesContainer($db);
    $draft = Quotes::save($di, quotesForm());
    $sent = Quotes::save($di, quotesForm(['stage' => 'delivered']));
    $hash = Quotes::get($di, $sent)['hash'];

    expect(Quotes::findByHash($di, $hash)['id'])->toBe($sent)
        ->and(Quotes::findByHash($di, substr($hash, 0, 39)))->toBeNull()
        ->and(Quotes::findByHash($di, strtoupper($hash)))->toBeNull()
        ->and(array_column(Quotes::forClient($di, 1), 'id'))->toBe([$sent])
        ->and($draft)->toBeLessThan($sent);
});

test('the quote page answers: the client it is for accepts as the client, others by the link; staff are told', function (): void {
    $db = quotesDb();
    [$di, $log] = quotesContainer($db);
    $mine = Quotes::get($di, Quotes::save($di, quotesForm(['stage' => 'delivered']), 7));
    $prospect = Quotes::get($di, Quotes::save($di, array_merge(quotesForm(['stage' => 'delivered']), ['client_id' => 0, 'first_name' => 'Bob', 'email' => 'bob@example.com']), 7));
    $declined = Quotes::get($di, Quotes::save($di, quotesForm(['stage' => 'delivered'])));

    expect(Quotes::answer($di, $mine['hash'], true, 1, '203.0.113.5'))->toBe('https://billing.example.com/invoice/inv50')
        ->and(Quotes::answer($di, $prospect['hash'], true, 1, '203.0.113.6'))->toBe('https://billing.example.com/invoice/inv51')
        ->and(Quotes::answer($di, $declined['hash'], false, null, '203.0.113.7'))->toBeNull();

    $bob = (int) $db->fetchOne("SELECT id FROM client WHERE email = 'bob@example.com'");
    expect(array_map(static fn (array $e): array => [$e['client_id'], $e['message']], $log['activity']))->toBe([
        [1, 'Accepted quote #' . $mine['id'] . ' (Security hardening) signed in, from 203.0.113.5'],
        [$bob, 'Accepted quote #' . $prospect['id'] . ' (Security hardening) by the quote link, from 203.0.113.6'],
        [1, 'Declined quote #' . $declined['id'] . ' (Security hardening) by the quote link, from 203.0.113.7'],
    ])
        // A signed-in client who is not the one the quote is for answers by the link: a new
        // account for the prospect gets its email confirmed.
        ->and((int) $db->fetchOne('SELECT email_approved FROM client WHERE id = ?', [$bob]))->toBe(1)
        ->and(Quotes::get($di, $declined['id'])['stage'])->toBe('lost');

    $staff = array_values(array_filter($log['emails'], static fn (array $e): bool => $e['code'] === 'mod_staff_quote_answered'));
    expect(array_map(static fn (array $e): array => [$e['accepted'], $e['to_admin'] ?? null, $e['to_staff'] ?? null], $staff))->toBe([
        [true, 7, null],
        [true, 7, null],
        [false, null, true],
    ])
        ->and($staff[0]['quote_admin_url'])->toBe('https://billing.example.com/admin/invoice/quote/' . $mine['id'])
        ->and($staff[0]['quote'])->not->toHaveKeys(['admin_notes', 'hash']);

    expect(fn () => Quotes::answer($di, $declined['hash'], true, null, '203.0.113.7'))->toThrow(InformationException::class, 'This quote can no longer be accepted.')
        ->and(fn () => Quotes::answer($di, $mine['hash'], false, 1, '203.0.113.5'))->toThrow(InformationException::class, 'This quote can no longer be declined.')
        ->and(fn () => Quotes::answer($di, str_repeat('0', 40), true, null, '203.0.113.7'))->toThrow(InformationException::class, 'Quote not found');
});

test('the quote PDF renders in English, Arabic and Hebrew', function (string $locale): void {
    (new ClientFence\Translate())->setLocale('en_US')->setup();
    $db = quotesDb();
    [$di] = quotesContainer($db, 14.0);
    $quote = Quotes::get($di, Quotes::save($di, quotesForm(['subject' => 'تطوير موقع / פיתוח אתר'])));

    $response = QuotePdf::response($di, $quote, $locale);
    expect(substr((string) $response->getContent(), 0, 5))->toBe('%PDF-')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename=quote-' . $quote['id'] . '.pdf')
        // The PDF is translated into its language; the request's language comes back after.
        ->and(Locale::getDefault())->toBe('en_US');
})->with(['en_US', 'ar_SA', 'he_IL']);

test('right-to-left PDF text keeps Latin values, short Latin phrases and numbered words left to right', function (): void {
    $text = ClientFence\Mod\Invoice\RtlPdf::text(true);
    $e = static fn (string $run): string => "\u{202A}\u{200E}" . $run . "\u{202C}";
    $r = "\u{200F}";

    expect($text('UI design & SEO'))->toBe($e('UI design &amp; SEO'))
        ->and($text('تصميم الواجهة / UI design'))->toBe($r . 'تصميم الواجهة / ' . $e('UI design'))
        ->and($text('دفعة Stripe'))->toBe($r . 'دفعة Stripe')
        ->and($text('فاتورة CF-00002 بقيمة -$80.00'))->toBe($r . 'فاتورة ' . $e('CF-00002') . ' بقيمة ' . $e('-$80.00'))
        // A long Latin run is left to wrap; only its words with digits are embedded.
        ->and($text('دفعة Bank transfer to account number LNK-308 at Example Bank'))
        ->toBe($r . 'دفعة Bank transfer to account number ' . $e('LNK-308') . ' at Example Bank')
        ->and(ClientFence\Mod\Invoice\RtlPdf::text(false)('UI <b>'))->toBe('UI &lt;b&gt;');
});

test('the test tables have the columns of the real tables', function (): void {
    $schema = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/Clientfence/schema.json'), true)['tables'];
    $db = quotesDb();
    foreach (['invoice_quote', 'invoice_quote_item'] as $table) {
        preg_match_all('/^\s*`([a-z_]+)` /m', str_replace([', `', ' (`'], [",\n`", " (\n`"], $schema[$table]), $m);
        expect(array_column($db->fetchAllAssociative("PRAGMA table_info({$table})"), 'name'))->toBe($m[1]);
    }
});

test('quote tax is counted on the taxed subtotal, as the invoice made from it', function (): void {
    $totals = ClientFence\Mod\Invoice\Quotes::totals([
        ['quantity' => 3, 'price' => 0.33, 'discount' => 0.0, 'taxed' => true],
        ['quantity' => 1, 'price' => 10.0, 'discount' => 0.0, 'taxed' => false],
    ], 19.0);

    // Per unit it would be 3 x 0.06 = 0.18; the invoice counts round(0.99 x 19%) = 0.19.
    expect($totals['tax'])->toBe(0.19)
        ->and($totals['total'])->toBe(11.18);
});
