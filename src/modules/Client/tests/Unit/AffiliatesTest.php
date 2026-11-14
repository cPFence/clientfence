<?php

declare(strict_types=1);

/**
 * ClientFence: the affiliate system (Client/Affiliates): commissions, overrides, currency, refunds, clearing,
 * payouts and referrals, on an in-memory database.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Client\Affiliates\Affiliates;
use ClientFence\Mod\Client\Affiliates\Commissions;
use ClientFence\Mod\Client\Affiliates\Emails;
use ClientFence\Mod\Client\Affiliates\Link;
use ClientFence\Mod\Client\Affiliates\Payouts;
use ClientFence\Mod\Invoice\Service as InvoiceService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\HttpFoundation\Request;

use function Tests\Helpers\container;

function affiliateDb(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
        'CREATE TABLE currency (code TEXT PRIMARY KEY, is_default INTEGER, conversion_rate REAL)',
        'CREATE TABLE client (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, company TEXT, email TEXT, currency TEXT, updated_at TEXT)',
        'CREATE TABLE product (id INTEGER PRIMARY KEY, config TEXT)',
        'CREATE TABLE client_order (id INTEGER PRIMARY KEY, client_id INTEGER, product_id INTEGER, status TEXT, group_master INTEGER, service_type TEXT)',
        'CREATE TABLE invoice (id INTEGER PRIMARY KEY, currency TEXT, status TEXT, refund REAL DEFAULT 0, paid_at TEXT)',
        'CREATE TABLE invoice_item (id INTEGER PRIMARY KEY, invoice_id INTEGER, type TEXT, rel_id TEXT, price REAL, quantity INTEGER)',
        'CREATE TABLE client_balance (id INTEGER PRIMARY KEY, client_id INTEGER, type TEXT, rel_id TEXT, description TEXT, amount REAL, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE client_user_account (id INTEGER PRIMARY KEY, user_id INTEGER, client_id INTEGER)',
        'CREATE TABLE mod_clientfence_state (name TEXT PRIMARY KEY, value TEXT, updated_at TEXT)',
        'CREATE TABLE setting (id INTEGER PRIMARY KEY, param TEXT, value TEXT, updated_at TEXT)',
        'CREATE TABLE client_affiliate (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER UNIQUE, code TEXT UNIQUE, status TEXT, commission_type TEXT, commission_amount REAL, onetime INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE client_affiliate_referral (id INTEGER PRIMARY KEY AUTOINCREMENT, affiliate_id INTEGER, client_id INTEGER, client_order_id INTEGER UNIQUE, source TEXT, created_at TEXT)',
        'CREATE TABLE client_affiliate_entry (id INTEGER PRIMARY KEY AUTOINCREMENT, affiliate_id INTEGER, type TEXT, status TEXT, amount REAL, currency TEXT, source_amount REAL, source_currency TEXT, rate REAL, client_id INTEGER, client_order_id INTEGER, invoice_id INTEGER, reverses_id INTEGER UNIQUE, payout_id INTEGER, admin_id INTEGER, note TEXT, clears_at TEXT, hold_until TEXT, created_at TEXT, updated_at TEXT, UNIQUE (invoice_id, client_order_id))',
        'CREATE TABLE client_affiliate_payout (id INTEGER PRIMARY KEY AUTOINCREMENT, affiliate_id INTEGER, amount REAL, currency TEXT, status TEXT, method TEXT, details TEXT, admin_id INTEGER, created_at TEXT, processed_at TEXT, updated_at TEXT)',
        'CREATE TABLE client_affiliate_visit (affiliate_id INTEGER, day TEXT, hits INTEGER, PRIMARY KEY (affiliate_id, day))',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    // One USD is two EUR's worth of EUR 0.5: the affiliate (client 1) earns in EUR, the referred client (2) pays in USD.
    $db->insert('currency', ['code' => 'USD', 'is_default' => 1, 'conversion_rate' => 1]);
    $db->insert('currency', ['code' => 'EUR', 'is_default' => 0, 'conversion_rate' => 0.5]);
    $db->insert('client', ['id' => 1, 'currency' => 'EUR']);
    $db->insert('client', ['id' => 2, 'currency' => 'USD']);
    $db->insert('client', ['id' => 3, 'currency' => 'USD']);
    $db->insert('product', ['id' => 5, 'config' => '{}']);
    $db->insert('client_order', ['id' => 50, 'client_id' => 2, 'product_id' => 5, 'status' => 'active', 'group_master' => 1, 'service_type' => 'hosting']);

    return $db;
}

function affiliateDi(Connection $db, array $params = []): Pimple\Container
{
    $di = container();
    $di['dbal'] = $db;
    $params += ['affiliate_enabled' => '1', 'affiliate_type' => 'percentage', 'affiliate_amount' => '10', 'invoice_refund_logic' => 'manual'];
    $system = new class($params) {
        public function __construct(public array $params)
        {
        }

        public function getParamValue(string $param, mixed $default = null): mixed
        {
            return array_key_exists($param, $this->params) ? $this->params[$param] : $default;
        }
    };
    $activity = new class {
        public array $log = [];

        public function logEvent(array $event): void
        {
            $this->log[] = $event['message'];
        }
    };
    $di['mod_service'] = $di->protect(fn (string $name): object => match (strtolower($name)) {
        'system' => $system,
        'activity' => $activity,
        default => Mockery::mock()->shouldIgnoreMissing(),
    });
    $di['url'] = new class {
        public function link(string $uri = '', array $params = []): string
        {
            return 'https://billing.example.test/' . trim($uri, '/') . ($params === [] ? '' : '?' . http_build_query($params));
        }

        public function adminLink(string $uri = ''): string
        {
            return 'https://billing.example.test/admin/' . trim($uri, '/');
        }
    };
    $di['events_manager'] = Mockery::mock()->shouldIgnoreMissing();
    $di['logger'] = new class {
        public array $lines = [];

        public function __call(string $level, array $args): void
        {
            $this->lines[] = $level . ': ' . vsprintf((string) array_shift($args), $args);
        }
    };

    return $di;
}

/** Client 1 as an affiliate, referring order 50 of client 2; returns the affiliate id. */
function affiliateReferring(Pimple\Container $di): int
{
    Affiliates::activate($di, 1, 9);
    $id = (int) $di['dbal']->fetchOne('SELECT id FROM client_affiliate WHERE client_id = 1');
    $di['dbal']->insert('client_affiliate_referral', ['affiliate_id' => $id, 'client_id' => 2, 'client_order_id' => 50, 'source' => 'cookie', 'created_at' => '2026-01-01 00:00:00']);

    return $id;
}

/** Invoice $id, paid in USD: order 50 (20) + its setup fee (10) - its discount (5), another order's line and a gateway fee. */
function affiliatePaidInvoice(Connection $db, int $id = 100): void
{
    $db->insert('invoice', ['id' => $id, 'currency' => 'USD', 'status' => 'paid', 'paid_at' => date('Y-m-d H:i:s')]);
    foreach ([['order', '50', 20.0], ['custom', '50', 10.0], ['custom', '50', -5.0], ['order', '51', 100.0], ['custom', null, 3.0], ['hook_call', '50', 40.0]] as [$type, $rel, $price]) {
        $db->insert('invoice_item', ['invoice_id' => $id, 'type' => $type, 'rel_id' => $rel, 'price' => $price, 'quantity' => 1]);
    }
}

function affiliateCommission(Connection $db, int $invoiceId = 100): array|false
{
    return $db->fetchAssociative("SELECT * FROM client_affiliate_entry WHERE type = 'commission' AND invoice_id = ?", [$invoiceId]);
}

/** An Invoice\Service whose refund paths run for real on the database (credit notes and notes are stand-ins). */
function affiliateInvoiceService(Pimple\Container $di): InvoiceService
{
    $service = Mockery::mock(InvoiceService::class)->makePartial();
    $service->shouldReceive('getTotalWithTax')->andReturn(30.0);
    $service->shouldReceive('getTax')->andReturn(0.0);
    $service->shouldReceive('isInvoiceTypeDeposit')->andReturn(false);
    $service->shouldReceive('creditNote')->andReturn(99);
    $service->shouldReceive('toApiArray')->andReturn([]);
    $service->shouldReceive('countIncome', 'addNote');
    $service->setDi($di);

    return $service;
}

function affiliateInvoiceModel(): Model_Invoice
{
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    foreach (['id' => 100, 'client_id' => 2, 'status' => 'paid', 'currency' => 'USD', 'serie' => 'CF', 'nr' => 7, 'taxrate' => '0', 'paid_at' => '2026-10-01 00:00:00'] as $key => $value) {
        $invoice->{$key} = $value;
    }

    return $invoice;
}

test('a paid invoice earns on its referred order\'s net lines, converted to the affiliate\'s currency, once', function (): void {
    $db = affiliateDb();
    $di = affiliateDi($db);
    affiliateReferring($di);
    affiliatePaidInvoice($db);

    Commissions::onPaid($di, 100);
    Commissions::onPaid($di, 100);

    $row = affiliateCommission($db);
    // 10% of 20 + 10 - 5 = 25 USD (not the other order, the gateway fee or the upgrade line), in EUR.
    expect((float) $row['source_amount'])->toBe(25.0)
        ->and($row['source_currency'])->toBe('USD')
        ->and((float) $row['rate'])->toBe(0.5)
        ->and((float) $row['amount'])->toBe(1.25)
        ->and($row['currency'])->toBe('EUR')
        ->and($row['status'])->toBe('pending')
        ->and(substr((string) $row['clears_at'], 0, 10))->toBe(date('Y-m-d', time() + 30 * 86400))
        ->and((int) $db->fetchOne("SELECT COUNT(*) FROM client_affiliate_entry WHERE type = 'commission'"))->toBe(1);
});

test('product No commission wins, then the affiliate\'s rule, then the product\'s, then the default', function (array $product, ?string $type, ?float $amount, ?float $expected): void {
    $db = affiliateDb();
    $di = affiliateDi($db);
    $id = affiliateReferring($di);
    $db->update('product', ['config' => json_encode($product)], ['id' => 5]);
    $db->update('client_affiliate', ['commission_type' => $type, 'commission_amount' => $amount], ['id' => $id]);
    affiliatePaidInvoice($db);

    Commissions::onPaid($di, 100);

    $row = affiliateCommission($db);
    expect($row === false ? null : (float) $row['amount'])->toBe($expected);
})->with([
    'product No commission over the affiliate' => [['affiliate_payout' => 'none'], 'fixed', 7.0, null],
    'affiliate fixed over product percentage (same number in EUR)' => [['affiliate_payout' => 'percentage', 'affiliate_amount' => 50], 'fixed', 7.0, 7.0],
    'product percentage over the default' => [['affiliate_payout' => 'percentage', 'affiliate_amount' => 20], null, null, 2.5],
    'product fixed' => [['affiliate_payout' => 'fixed', 'affiliate_amount' => 4], null, null, 4.0],
    'the default' => [[], null, null, 1.25],
]);

test('a one-time commission is paid on the order\'s first paid invoice only; a recurring one on each', function (bool $onetime, int $rows): void {
    $db = affiliateDb();
    $di = affiliateDi($db, ['affiliate_onetime' => $onetime ? '1' : '0']);
    affiliateReferring($di);
    affiliatePaidInvoice($db, 100);
    affiliatePaidInvoice($db, 101);

    Commissions::onPaid($di, 100);
    Commissions::onPaid($di, 101);

    expect((int) $db->fetchOne("SELECT COUNT(*) FROM client_affiliate_entry WHERE type = 'commission'"))->toBe($rows);
})->with(['one-time' => [true, 1], 'recurring' => [false, 2]]);

test('a full refund reverses the commission once, also when refunds are handled by hand', function (): void {
    $db = affiliateDb();
    $di = affiliateDi($db, ['affiliate_clearing_days' => '0']);
    affiliateReferring($di);
    affiliatePaidInvoice($db);
    Commissions::onPaid($di, 100);
    $service = affiliateInvoiceService($di);

    expect(affiliateCommission($db)['status'])->toBe('cleared');
    $service->refundInvoice(affiliateInvoiceModel());
    $service->refundInvoice(affiliateInvoiceModel());

    $reversals = $db->fetchAllAssociative("SELECT * FROM client_affiliate_entry WHERE type = 'reversal'");
    expect($reversals)->toHaveCount(1)
        ->and((float) $reversals[0]['amount'])->toBe(-1.25)
        ->and((int) $reversals[0]['reverses_id'])->toBe((int) affiliateCommission($db)['id'])
        ->and(Affiliates::balances($db, (int) affiliateCommission($db)['affiliate_id'])['available'])->toBe(0.0);
});

test('a partial refund reverses the commission when staff ask, or when the refunds reach the total', function (float $refunded, bool $reverse, string $status): void {
    $db = affiliateDb();
    $di = affiliateDi($db, ['invoice_refund_logic' => 'credit_note']);
    affiliateReferring($di);
    affiliatePaidInvoice($db);
    $db->update('invoice', ['refund' => $refunded], ['id' => 100]);
    Commissions::onPaid($di, 100);

    expect(affiliateInvoiceService($di)->refundInvoice(affiliateInvoiceModel(), null, 5.0, false, false, $reverse))->toBe(99)
        ->and(affiliateCommission($db)['status'])->toBe($status);
})->with([
    'part, not asked' => [0.0, false, 'pending'],
    'part, asked' => [0.0, true, 'cancelled'],
    'the last part' => [25.0, false, 'cancelled'],
]);

test('a chargeback reverses the commission even when its refund cannot be recorded', function (): void {
    $db = affiliateDb();
    $di = affiliateDi($db);
    affiliateReferring($di);
    affiliatePaidInvoice($db);
    Commissions::onPaid($di, 100);

    // Refunds are handled by hand, so a part refund is refused.
    expect(affiliateInvoiceService($di)->chargeback(affiliateInvoiceModel(), 5.0, 'Dispute D-1.'))->toContain('Not refunded here')
        ->and(affiliateCommission($db)['status'])->toBe('cancelled');
});

test('clearing follows the service: active clears, gone cancels, waiting for setup holds', function (?string $orderStatus, string $status, bool $held): void {
    $db = affiliateDb();
    $di = affiliateDi($db);
    affiliateReferring($di);
    affiliatePaidInvoice($db);
    Commissions::onPaid($di, 100);
    $db->executeStatement("UPDATE client_affiliate_entry SET clears_at = '2026-01-01 00:00:00'");
    $orderStatus === null ? $db->delete('client_order', ['id' => 50]) : $db->update('client_order', ['status' => $orderStatus], ['id' => 50]);

    Commissions::daily($di);

    $row = affiliateCommission($db);
    expect($row['status'])->toBe($status)->and($row['hold_until'] !== null)->toBe($held);
})->with([
    'active' => ['active', 'cleared', false],
    'renewal failed' => ['failed_renew', 'cleared', false],
    'canceled' => ['canceled', 'cancelled', false],
    'suspended' => ['suspended', 'cancelled', false],
    'order gone' => [null, 'cancelled', false],
    'pending setup' => ['pending_setup', 'pending', true],
    'setup failed' => ['failed_setup', 'pending', true],
]);

test('a held commission clears once the service is active, and is cancelled after another clearing period', function (string $orderStatus, string $holdUntil, string $status): void {
    $db = affiliateDb();
    $di = affiliateDi($db);
    affiliateReferring($di);
    affiliatePaidInvoice($db);
    Commissions::onPaid($di, 100);
    $db->executeStatement("UPDATE client_affiliate_entry SET clears_at = '2026-01-01 00:00:00', hold_until = ?", [$holdUntil]);
    $db->update('client_order', ['status' => $orderStatus], ['id' => 50]);

    Commissions::daily($di);

    expect(affiliateCommission($db)['status'])->toBe($status);
})->with([
    'still waiting' => ['pending_setup', '2099-01-01 00:00:00', 'pending'],
    'active in time' => ['active', '2099-01-01 00:00:00', 'cleared'],
    'not active in time' => ['pending_setup', '2026-01-02 00:00:00', 'cancelled'],
]);

test('the daily job writes a commission the payment missed, but not one paid while earning was off', function (bool $offAtPayment, bool $written): void {
    $db = affiliateDb();
    $di = affiliateDi($db);
    $id = affiliateReferring($di);
    affiliatePaidInvoice($db);
    if ($offAtPayment) {
        // Staff deactivated and reactivated the affiliate after the payment (the payment hook wrote nothing then).
        $db->update('client_affiliate', ['updated_at' => date('Y-m-d H:i:s', time() + 60)], ['id' => $id]);
    }

    Commissions::daily($di);

    expect(affiliateCommission($db) !== false)->toBe($written);
})->with([
    'missed by the payment' => [false, true],
    'paid while deactivated' => [true, false],
]);

test('a fixed commission is paid on a fully discounted service of an invoice that took money, never on a 0.00 invoice', function (string $type, float $other, bool $written): void {
    $db = affiliateDb();
    $di = affiliateDi($db, ['affiliate_type' => $type, 'affiliate_amount' => '10']);
    affiliateReferring($di);
    $db->insert('invoice', ['id' => 100, 'currency' => 'USD', 'status' => 'paid', 'paid_at' => date('Y-m-d H:i:s')]);
    foreach ([['order', '50', 20.0], ['custom', '50', -20.0], ['order', '51', $other]] as [$lineType, $rel, $price]) {
        $db->insert('invoice_item', ['invoice_id' => 100, 'type' => $lineType, 'rel_id' => $rel, 'price' => $price, 'quantity' => 1]);
    }

    Commissions::onPaid($di, 100);

    expect(affiliateCommission($db) !== false)->toBe($written);
})->with([
    'fixed, with a paid domain on the invoice' => ['fixed', 15.0, true],
    'percentage' => ['percentage', 15.0, false],
    'fixed, 0.00 renewal invoice' => ['fixed', 0.0, false],
]);

test('a payout request reserves its amount: minimum, one at a time, paid as credit or declined', function (): void {
    $db = affiliateDb();
    // The minimum is 10 USD: 5 EUR for this affiliate.
    $di = affiliateDi($db, ['affiliate_min_payout' => '10']);
    $id = affiliateReferring($di);
    $db->insert('client_affiliate_entry', ['affiliate_id' => $id, 'type' => 'commission', 'status' => 'cleared', 'amount' => 30, 'currency' => 'EUR', 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01']);
    // Nothing paid out yet reads 0, not -0 ("-$0.00" on the pages).
    expect((string) Affiliates::balances($db, $id)['paid_out'])->toBe('0');

    expect(fn () => Payouts::request($di, 1, 4, 'IBAN X'))->toThrow(InformationException::class, 'The minimum payout is 5.00 EUR.')
        ->and(fn () => Payouts::request($di, 1, 31, 'IBAN X'))->toThrow(InformationException::class, 'You can ask for at most 30.00 EUR.')
        ->and(fn () => Payouts::request($di, 1, 20, ''))->toThrow(InformationException::class);
    $payout = Payouts::request($di, 1, 20, 'IBAN X');
    expect(fn () => Payouts::request($di, 1, 5, 'IBAN X'))->toThrow(InformationException::class, 'Your payout request is already waiting.')
        ->and(Affiliates::balances($db, $id))->toMatchArray(['available' => 10.0, 'requested' => 20.0]);

    Payouts::pay($di, $payout, 'credit', 9);
    expect(fn () => Payouts::pay($di, $payout, 'credit', 9))->toThrow(InformationException::class, 'This payout request was already handled.')
        ->and($db->fetchAllAssociative('SELECT client_id, type, rel_id, amount FROM client_balance'))->toBe([['client_id' => 1, 'type' => 'affiliate', 'rel_id' => (string) $payout, 'amount' => 20.0]])
        ->and(Affiliates::balances($db, $id))->toMatchArray(['available' => 10.0, 'requested' => 0.0, 'paid_out' => 20.0]);

    $declined = Payouts::request($di, 1, 10, 'IBAN X');
    Payouts::decline($di, $declined, 9);
    expect($db->fetchOne('SELECT status FROM client_affiliate_payout WHERE id = ?', [$declined]))->toBe('declined')
        ->and((int) $db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(1)
        ->and(Affiliates::balances($db, $id)['available'])->toBe(10.0);
});

test('a payout outside ClientFence adds no credit, and one a reversal uncovered cannot be paid', function (): void {
    $db = affiliateDb();
    $di = affiliateDi($db);
    $id = affiliateReferring($di);
    $db->insert('client_affiliate_entry', ['affiliate_id' => $id, 'type' => 'commission', 'status' => 'cleared', 'amount' => 30, 'currency' => 'EUR', 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01']);
    $payout = Payouts::request($di, 1, 30, 'PayPal me@example.invalid');
    $db->insert('client_affiliate_entry', ['affiliate_id' => $id, 'type' => 'reversal', 'status' => 'cleared', 'amount' => -10, 'currency' => 'EUR', 'created_at' => '2026-01-02', 'updated_at' => '2026-01-02']);

    expect(fn () => Payouts::pay($di, $payout, 'outside', 9))->toThrow(InformationException::class, 'no longer covers');
    $db->insert('client_affiliate_entry', ['affiliate_id' => $id, 'type' => 'manual', 'status' => 'cleared', 'amount' => 10, 'currency' => 'EUR', 'created_at' => '2026-01-03', 'updated_at' => '2026-01-03']);
    Payouts::pay($di, $payout, 'outside', 9);

    expect((int) $db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(0)
        ->and(Affiliates::balances($db, $id))->toMatchArray(['available' => 0.0, 'paid_out' => 30.0]);
});

test('the bonus deposit cannot be paid out before a commission clears', function (): void {
    $db = affiliateDb();
    // 20 USD bonus: 10 EUR.
    $di = affiliateDi($db, ['affiliate_bonus' => '20']);
    $id = affiliateReferring($di);

    expect(Affiliates::balances($db, $id))->toMatchArray(['available' => 0.0, 'held' => 10.0])
        ->and(fn () => Payouts::request($di, 1, 10, 'IBAN X'))->toThrow(InformationException::class, 'You can ask for at most 0.00 EUR.');
    $db->insert('client_affiliate_entry', ['affiliate_id' => $id, 'type' => 'commission', 'status' => 'cleared', 'amount' => 1, 'currency' => 'EUR', 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01']);
    expect(Affiliates::balances($db, $id))->toMatchArray(['available' => 11.0, 'held' => 0.0]);
});

test('the cookie refers a client\'s service orders, never the affiliate\'s own account or a staff session', function (int $clientId, bool $staff, array $expected): void {
    $db = affiliateDb();
    $di = affiliateDi($db);
    Affiliates::activate($di, 1, 9);
    $code = (string) $db->fetchOne('SELECT code FROM client_affiliate WHERE client_id = 1');
    // Client 2 shares an authorized user with the affiliate; client 3 does not.
    $db->insert('client_user_account', ['user_id' => 7, 'client_id' => 1]);
    $db->insert('client_user_account', ['user_id' => 7, 'client_id' => 2]);
    foreach ([[60, 1, 'hosting'], [61, 0, 'custom'], [62, 1, 'domain']] as [$id, $master, $type]) {
        $db->insert('client_order', ['id' => $id, 'client_id' => $clientId, 'product_id' => 5, 'status' => 'pending_setup', 'group_master' => $master, 'service_type' => $type]);
    }
    $di['request'] = Request::create('/', 'POST', [], [Link::COOKIE => $code]);
    $session = Mockery::mock();
    $session->shouldReceive('get')->with('admin')->andReturn($staff ? ['id' => 1] : null);
    $di['session'] = $session;

    Link::attribute($di, $clientId, [60, 61, 62]);

    expect(array_map(intval(...), $db->fetchFirstColumn('SELECT client_order_id FROM client_affiliate_referral')))->toBe($expected);
})->with([
    'another client: the service, not the add-on or domain' => [3, false, [60]],
    'staff signed in as the client' => [3, true, []],
    'the affiliate itself' => [1, false, []],
    'a client sharing a user with the affiliate' => [2, false, []],
]);

test('a Blesta affiliate link (/order/forms/a/CODE) sets the cookie and goes to the home page', function (string $code): void {
    $db = affiliateDb();
    $di = affiliateDi($db);

    $response = Link::hop(Request::create('/order/forms/a/' . $code), $di);

    expect($response?->getTargetUrl())->toBe('https://billing.example.test/')
        ->and($response?->headers->getCookies()[0]->getValue())->toBe($code);
})->with([
    'a chosen code' => ['abc123'],
    // Blesta's default code is base64 of the client id.
    'a default code with padding' => ['MQ=='],
]);

test('staff cannot assign an affiliate to its own order', function (): void {
    $db = affiliateDb();
    $di = affiliateDi($db);
    Affiliates::activate($di, 1, 9);
    $id = (int) $db->fetchOne('SELECT id FROM client_affiliate WHERE client_id = 1');
    $db->insert('client_order', ['id' => 70, 'client_id' => 1, 'product_id' => 5, 'status' => 'active', 'group_master' => 1, 'service_type' => 'hosting']);

    expect(fn () => Link::assign($di, 70, $id, 9))->toThrow(InformationException::class, 'An affiliate cannot be referred to its own orders.');
    Link::assign($di, 50, $id, 9);
    expect($db->fetchOne('SELECT source FROM client_affiliate_referral WHERE client_order_id = 50'))->toBe('staff');
});

test('the monthly report counts the month\'s visitors, signups and commissions, without cancelled ones', function (): void {
    $db = affiliateDb();
    $di = affiliateDi($db);
    $id = affiliateReferring($di);
    foreach ([['2026-01-05', 3], ['2026-01-31', 2], ['2026-02-01', 7]] as [$day, $hits]) {
        $db->insert('client_affiliate_visit', ['affiliate_id' => $id, 'day' => $day, 'hits' => $hits]);
    }
    foreach ([['commission', 'pending', 4.5, '2026-01-10 10:00:00'], ['commission', 'cleared', 1.25, '2026-01-31 23:59:59'], ['commission', 'cancelled', 9.0, '2026-01-12 00:00:00'], ['commission', 'cleared', 6.0, '2026-02-01 00:00:00'], ['manual', 'cleared', 100.0, '2026-01-15 00:00:00']] as $i => [$type, $status, $amount, $at]) {
        $db->insert('client_affiliate_entry', ['affiliate_id' => $id, 'type' => $type, 'status' => $status, 'amount' => $amount, 'currency' => 'EUR', 'client_order_id' => 50, 'invoice_id' => 200 + $i, 'created_at' => $at, 'updated_at' => $at]);
    }

    expect(Emails::report($db, $id, '2026-01'))->toBe(['month' => '2026-01-01', 'visitors' => 5, 'signups' => 1, 'earned' => 5.75])
        ->and(Emails::report($db, $id, '2026-02'))->toBe(['month' => '2026-02-01', 'visitors' => 7, 'signups' => 0, 'earned' => 6.0]);
});

test('the monthly report goes once a month to active affiliates, starting the month after the first run', function (): void {
    $db = affiliateDb();
    $di = affiliateDi($db);
    $mailer = new class {
        public array $sent = [];

        public function sendTemplate(array $data): bool
        {
            $this->sent[] = $data;

            return true;
        }
    };
    $services = $di['mod_service'];
    $di['mod_service'] = $di->protect(fn (string $name): object => strtolower($name) === 'email' ? $mailer : $services($name));
    affiliateReferring($di);
    $code = (string) $db->fetchOne('SELECT code FROM client_affiliate WHERE client_id = 1');
    $db->insert('client_affiliate', ['client_id' => 3, 'code' => 'inactive-one', 'status' => 'inactive', 'onetime' => 0, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
    $last = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
    $state = static fn (): mixed => $db->fetchOne("SELECT value FROM mod_clientfence_state WHERE name = 'affiliates_monthly'");

    // Off: nothing happens. The first run on: it only notes the month.
    Emails::monthly(affiliateDi($db, ['affiliate_enabled' => '0']));
    expect($state())->toBeFalse();
    Emails::monthly($di);
    expect($mailer->sent)->toBe([])->and($state())->toBe($last);

    // A new month: one report per active affiliate, once.
    $db->update('mod_clientfence_state', ['value' => date('Y-m', strtotime(date('Y-m-01') . ' -2 months'))], ['name' => 'affiliates_monthly']);
    Emails::monthly($di);
    Emails::monthly($di);
    expect($mailer->sent)->toHaveCount(1)
        ->and($mailer->sent[0])->toMatchArray(['to_client' => 1, 'code' => 'mod_client_affiliate_report'])
        ->and($mailer->sent[0]['report'])->toMatchArray(['month' => $last . '-01', 'currency' => 'EUR', 'available' => 0.0, 'link' => 'https://billing.example.test/?aff=' . $code])
        ->and($state())->toBe($last);
});
