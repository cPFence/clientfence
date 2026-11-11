<?php

declare(strict_types=1);

/**
 * ClientFence: TLD prices from a registrar's price list (Servicedomain\PriceSync), on an in-memory
 * SQLite install: default currency EUR, the registrar (#1) charges USD at 1 EUR = 1.25 USD, and the
 * store sells .com (#1) and .net (#2) on it, .org (#3) on registrar #2.
 */

use ClientFence\Mod\Servicedomain\PriceSync;

final class PriceSyncFakeRegistrar implements Registrar_TldPrices
{
    public int $calls = 0;

    /** @var list<string> TLDs listed before the priced ones, with no price */
    public array $unpriced = [];

    /** @param array<string, array{register: ?float, renew: ?float, transfer: ?float}> $prices */
    public function __construct(public array $prices, public string $currency = 'USD', public int $slice = 0)
    {
    }

    public ?string $fail = null;

    public function getTldPrices(array $tlds = []): array
    {
        ++$this->calls;
        if ($this->fail !== null) {
            throw new Registrar_Exception($this->fail);
        }
        $wanted = $tlds === [] ? [...$this->unpriced, ...array_keys($this->prices)] : $tlds;
        $now = $this->slice > 0 ? array_slice($wanted, 0, $this->slice) : $wanted;

        return [
            'currency' => $this->currency,
            'prices' => array_intersect_key($this->prices, array_flip($now)),
            'remaining' => array_values(array_diff($wanted, $now)),
        ];
    }
}

const PRICE_LIST = [
    '.com' => ['register' => 10.00, 'renew' => 12.50, 'transfer' => 10.00],
    '.net' => ['register' => 12.50, 'renew' => 12.50, 'transfer' => 12.50],
    '.org' => ['register' => 11.00, 'renew' => 11.00, 'transfer' => null],
    '.io' => ['register' => 40.00, 'renew' => 40.00, 'transfer' => null],
    '.xyz' => ['register' => 0.0, 'renew' => 2.00, 'transfer' => 2.00],
];

/**
 * @return array{0: Pimple\Container, 1: Doctrine\DBAL\Connection, 2: PriceSyncFakeRegistrar}
 */
function priceSyncFixture(?PriceSyncFakeRegistrar $registrar = null): array
{
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE currency (id INTEGER PRIMARY KEY, code TEXT, is_default INTEGER, conversion_rate REAL)');
    $db->executeStatement("CREATE TABLE tld_registrar (id INTEGER PRIMARY KEY, name TEXT, registrar TEXT, price_sync TEXT)");
    $db->executeStatement('CREATE TABLE tld (id INTEGER PRIMARY KEY AUTOINCREMENT, tld TEXT UNIQUE, tld_registrar_id INTEGER, price_registration REAL, price_renew REAL, price_transfer REAL, active INTEGER, allow_register INTEGER, allow_transfer INTEGER, require_transfer_code INTEGER, min_years INTEGER, price_locked INTEGER NOT NULL DEFAULT 0)');
    $db->executeStatement('CREATE TABLE tld_registrar_cost (id INTEGER PRIMARY KEY AUTOINCREMENT, tld_registrar_id INTEGER NOT NULL, tld TEXT NOT NULL, currency TEXT NOT NULL, cost_register REAL, cost_renew REAL, cost_transfer REAL, applied_register REAL, applied_renew REAL, applied_transfer REAL, applied_rate REAL, fetched_at TEXT NOT NULL, UNIQUE (tld_registrar_id, tld))');
    $db->executeStatement('CREATE TABLE mod_clientfence_state (name TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $db->executeStatement('CREATE TABLE product_currency_price (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT, ref_id INTEGER, currency TEXT, period TEXT, price REAL, setup_price REAL, created_at TEXT, updated_at TEXT)');
    $db->insert('currency', ['id' => 1, 'code' => 'EUR', 'is_default' => 1, 'conversion_rate' => 1]);
    $db->insert('currency', ['id' => 2, 'code' => 'USD', 'is_default' => 0, 'conversion_rate' => 1.25]);
    $db->insert('tld_registrar', ['id' => 1, 'name' => 'Main', 'registrar' => 'Fake']);
    $db->insert('tld_registrar', ['id' => 2, 'name' => 'Other', 'registrar' => 'Custom']);
    foreach ([['.com', 1, 9.99, 11.99, 9.99], ['.net', 1, 10.00, 10.00, 10.00], ['.org', 2, 15.00, 15.00, 15.00]] as [$tld, $registrarId, $register, $renew, $transfer]) {
        $db->insert('tld', ['tld' => $tld, 'tld_registrar_id' => $registrarId, 'price_registration' => $register, 'price_renew' => $renew, 'price_transfer' => $transfer, 'active' => 1, 'allow_register' => 1, 'allow_transfer' => 1, 'require_transfer_code' => 0, 'min_years' => 1]);
    }

    $registrar ??= new PriceSyncFakeRegistrar(PRICE_LIST);
    $service = Mockery::mock(ClientFence\Mod\Servicedomain\Service::class)->makePartial();
    $service->shouldReceive('registrarGetRegistrarAdapter')->andReturnUsing(static fn ($model) => (int) $model->id === 1 ? $registrar : new stdClass());
    $service->shouldReceive('tldCreate')->andReturnUsing(static function (array $data) use ($db): int {
        $db->insert('tld', [
            'tld' => $data['tld'], 'tld_registrar_id' => $data['tld_registrar_id'], 'price_registration' => $data['price_registration'],
            'price_renew' => $data['price_renew'], 'price_transfer' => $data['price_transfer'], 'active' => (int) $data['active'],
            'allow_register' => (int) $data['allow_register'], 'allow_transfer' => (int) $data['allow_transfer'],
            'require_transfer_code' => (int) $data['require_transfer_code'], 'min_years' => (int) $data['min_years'],
        ]);

        return (int) $db->lastInsertId();
    });
    $service->shouldReceive('tldFindOneById')->andReturnUsing(static function ($id): Model_Tld {
        $model = new Model_Tld();
        $model->loadBean(new Tests\Helpers\DummyBean());
        $model->id = (int) $id;

        return $model;
    });
    $service->shouldReceive('tldUpdate')->andReturnUsing(static function ($model, array $data) use ($db): bool {
        $db->update('tld', array_intersect_key($data, array_flip(['price_registration', 'price_renew', 'price_transfer', 'tld_registrar_id'])), ['id' => $model->id]);

        return true;
    });

    $di = new Pimple\Container();
    $di['em'] = new class($db) {
        public function __construct(private Doctrine\DBAL\Connection $db)
        {
        }

        public function getConnection(): Doctrine\DBAL\Connection
        {
            return $this->db;
        }
    };
    $rb = Mockery::mock();
    $rb->shouldReceive('getExistingModelById')->andReturnUsing(static function (string $type, $id) use ($db) {
        if ($db->fetchOne('SELECT 1 FROM tld_registrar WHERE id = ?', [$id]) === false) {
            throw new ClientFence\InformationException('Registrar not found');
        }
        $model = new Model_TldRegistrar();
        $model->loadBean(new Tests\Helpers\DummyBean());
        $model->id = (int) $id;

        return $model;
    });
    $di['db'] = $rb;
    $di['logger'] = new class {
        public array $lines = [];

        public function __call(string $name, array $arguments): void
        {
            $this->lines[] = vsprintf((string) array_shift($arguments), $arguments);
        }
    };
    $di['mod_service'] = $di->protect(static fn (string $name) => $service);

    return [$di, $db, $registrar];
}

function priceRow(array $preview, string $tld): array
{
    return array_column($preview['rows'], null, 'tld')[$tld];
}

test('a price is the cost converted, marked up and rounded up, never below cost', function (?float $cost, float $rate, array $markup, string $rounding, ?float $expected): void {
    expect(PriceSync::price($cost, $rate, $markup, $rounding))->toBe($expected);
})->with([
    'no markup' => [10.00, 1.0, ['type' => 'percent', 'value' => 0.0], '', 10.00],
    'percent' => [10.00, 1.0, ['type' => 'percent', 'value' => 20.0], '', 12.00],
    'decimal percent, up to the cent' => [9.99, 1.0, ['type' => 'percent', 'value' => 12.5], '', 11.24],
    'fixed amount' => [10.00, 1.0, ['type' => 'fixed', 'value' => 2.5], '', 12.50],
    'converted: 1 EUR = 1.25 USD' => [12.50, 1.25, ['type' => 'percent', 'value' => 0.0], '', 10.00],
    'converted up to the cent' => [10.00, 1.3, ['type' => 'percent', 'value' => 0.0], '', 7.70],
    'rounding .99' => [10.00, 1.0, ['type' => 'percent', 'value' => 20.0], '.99', 12.99],
    'rounding .00' => [10.01, 1.0, ['type' => 'percent', 'value' => 0.0], '.00', 11.00],
    'rounding .00 keeps a whole price' => [10.00, 1.0, ['type' => 'percent', 'value' => 0.0], '.00', 10.00],
    'rounding .50 past .50' => [10.60, 1.0, ['type' => 'percent', 'value' => 0.0], '.50', 11.50],
    'rounding .95' => [10.10, 1.0, ['type' => 'percent', 'value' => 0.0], '.95', 10.95],
    'zero cost' => [0.0, 1.0, ['type' => 'percent', 'value' => 20.0], '', null],
    'no cost' => [null, 1.0, ['type' => 'fixed', 'value' => 5.0], '.99', null],
]);

test('settings default to off with no markup and refuse bad values', function (): void {
    [$di] = priceSyncFixture();
    expect(PriceSync::settings($di, 1))->toBe(['mode' => 'off', 'markup' => [
        'register' => ['type' => 'percent', 'value' => 0.0], 'renew' => ['type' => 'percent', 'value' => 0.0], 'transfer' => ['type' => 'percent', 'value' => 0.0],
    ], 'rounding' => '']);

    $saved = PriceSync::saveSettings($di, 1, ['mode' => 'notify', 'rounding' => '.99', 'markup' => ['register' => ['type' => 'fixed', 'value' => '2.50']]]);
    expect($saved['markup']['register'])->toBe(['type' => 'fixed', 'value' => 2.5])
        ->and(PriceSync::settings($di, 1)['mode'])->toBe('notify');

    expect(fn () => PriceSync::saveSettings($di, 1, ['markup' => ['renew' => ['type' => 'percent', 'value' => '-5']]]))->toThrow(ClientFence\InformationException::class);
    expect(fn () => PriceSync::saveSettings($di, 1, ['rounding' => '.42']))->toThrow(ClientFence\InformationException::class);
});

test('the price list is fetched in slices, kept a day, and needs a store currency', function (): void {
    [$di, $db, $registrar] = priceSyncFixture(new PriceSyncFakeRegistrar(PRICE_LIST, 'USD', 2));

    $first = PriceSync::fetch($di, 1, 0);
    expect($first)->toMatchArray(['done' => false, 'priced' => 2, 'remaining' => 3]);
    expect(fn () => PriceSync::preview($di, 1))->toThrow(ClientFence\InformationException::class, 'Get the price list first.');
    PriceSync::fetch($di, 1, 0);
    expect(PriceSync::fetch($di, 1, 0))->toMatchArray(['done' => true, 'priced' => 5, 'remaining' => 0])
        ->and($registrar->calls)->toBe(3);

    // Reused for a day; "Get it again" asks the registrar again.
    PriceSync::fetch($di, 1, 30);
    expect($registrar->calls)->toBe(3);
    PriceSync::fetch($di, 1, 30, true);
    expect($registrar->calls)->toBe(6);

    $registrar->currency = 'GBP';
    expect(fn () => PriceSync::fetch($di, 1, 30, true))->toThrow(ClientFence\InformationException::class, 'GBP');
    $registrar->currency = 'USD';
    $registrar->prices = [];
    expect(fn () => PriceSync::fetch($di, 1, 30, true))->toThrow(ClientFence\InformationException::class, 'no prices');
    expect(fn () => PriceSync::fetch($di, 2, 30))->toThrow(ClientFence\InformationException::class, 'Not supported');
});

test('a slice of TLDs without prices does not stop the list, a list without any does', function (): void {
    $registrar = new PriceSyncFakeRegistrar(PRICE_LIST, 'USD', 2);
    $registrar->unpriced = ['.aa', '.bb'];
    [$di] = priceSyncFixture($registrar);
    expect(PriceSync::fetch($di, 1, 30))->toMatchArray(['done' => true, 'priced' => 5]);

    $registrar->prices = [];
    expect(fn () => PriceSync::fetch($di, 1, 30, true))->toThrow(ClientFence\InformationException::class, 'no prices');
});

test('the preview gives each TLD its status, and names fixed currency prices', function (): void {
    [$di, $db] = priceSyncFixture();
    $db->insert('product_currency_price', ['kind' => 'tld', 'ref_id' => 1, 'currency' => 'GBP', 'period' => 'renew', 'price' => 9.0]);
    PriceSync::fetch($di, 1, 30);
    $preview = PriceSync::preview($di, 1);

    // USD costs at 1.25: .com 8.00 / 10.00 / 8.00 against 9.99 / 11.99 / 9.99.
    expect(priceRow($preview, '.com'))->toMatchArray(['status' => 'change', 'new' => ['register' => 8.0, 'renew' => 10.0, 'transfer' => 8.0], 'fixed_currencies' => ['GBP']])
        ->and(priceRow($preview, '.net'))->toMatchArray(['status' => 'same'])
        ->and(priceRow($preview, '.org'))->toMatchArray(['status' => 'other', 'registrar' => 'Other'])
        // No transfer cost: a new TLD gets 0 and transfers off.
        ->and(priceRow($preview, '.io'))->toMatchArray(['status' => 'new', 'new' => ['register' => 32.0, 'renew' => 32.0, 'transfer' => 0.0], 'offers_transfer' => false])
        ->and(priceRow($preview, '.xyz'))->toMatchArray(['status' => 'no_price'])
        ->and($preview['counts'])->toMatchArray(['change' => 1, 'same' => 1, 'new' => 1, 'other' => 1, 'no_price' => 1, 'locked' => 0]);

    $db->update('tld', ['price_locked' => 1], ['id' => 1]);
    expect(priceRow(PriceSync::preview($di, 1), '.com')['status'])->toBe('locked');
});

test('apply creates new TLDs inactive, updates and moves only what is ticked, and records the costs', function (): void {
    [$di, $db] = priceSyncFixture();
    PriceSync::fetch($di, 1, 30);
    $preview = PriceSync::preview($di, 1);

    expect(PriceSync::apply($di, 1, ['.com', '.io', '.org'], ['.org'], $preview['fingerprint'], 5))->toBe(['created' => 1, 'updated' => 1, 'moved' => 1]);
    expect($db->fetchAssociative("SELECT tld_registrar_id, price_registration, price_renew, active, allow_register, allow_transfer, require_transfer_code FROM tld WHERE tld = '.io'"))
        ->toEqual(['tld_registrar_id' => 1, 'price_registration' => 32.0, 'price_renew' => 32.0, 'active' => 0, 'allow_register' => 1, 'allow_transfer' => 0, 'require_transfer_code' => 1])
        ->and((float) $db->fetchOne("SELECT price_renew FROM tld WHERE tld = '.com'"))->toBe(10.0)
        ->and((int) $db->fetchOne("SELECT tld_registrar_id FROM tld WHERE tld = '.org'"))->toBe(1)
        ->and((float) $db->fetchOne("SELECT applied_renew FROM tld_registrar_cost WHERE tld = '.com'"))->toBe(12.5)
        ->and($db->fetchOne("SELECT applied_renew FROM tld_registrar_cost WHERE tld = '.net'"))->toBeNull()
        ->and($di['logger']->lines[0])->toContain('by staff #5');

    // Only the exchange rate moved: still a change, marked as such.
    $db->update('currency', ['conversion_rate' => 1.0], ['code' => 'USD']);
    expect(priceRow(PriceSync::preview($di, 1), '.com'))->toMatchArray(['status' => 'change', 'rate_only' => true]);

    // A markup change at the old rate is not one.
    $db->update('currency', ['conversion_rate' => $preview['rate']], ['code' => 'USD']);
    PriceSync::saveSettings($di, 1, ['markup' => ['renew' => ['type' => 'percent', 'value' => '10']]]);
    expect(priceRow(PriceSync::preview($di, 1), '.com'))->toMatchArray(['status' => 'change', 'rate_only' => false]);
});

test('an unfinished refresh is not shown as the price list', function (): void {
    [$di, , $registrar] = priceSyncFixture(new PriceSyncFakeRegistrar(PRICE_LIST, 'USD', 2));
    PriceSync::fetch($di, 1, 30);
    PriceSync::preview($di, 1);
    PriceSync::fetch($di, 1, 0, true);

    expect(fn () => PriceSync::preview($di, 1))->toThrow(ClientFence\InformationException::class, 'Get the price list first.');
    PriceSync::fetch($di, 1, 30);
    expect(PriceSync::preview($di, 1)['rows'])->toHaveCount(5);
});

test('apply changes nothing when the preview is stale or a ticked row cannot be applied', function (): void {
    [$di, $db] = priceSyncFixture();
    PriceSync::fetch($di, 1, 30);
    $preview = PriceSync::preview($di, 1);
    $before = $db->fetchAllAssociative('SELECT * FROM tld ORDER BY id');

    // .org belongs to another registrar and is not ticked to move; .net has nothing to change.
    expect(fn () => PriceSync::apply($di, 1, ['.com', '.org'], [], $preview['fingerprint'], null))->toThrow(ClientFence\InformationException::class, '.org cannot be applied')
        ->and(fn () => PriceSync::apply($di, 1, ['.com', '.net'], [], $preview['fingerprint'], null))->toThrow(ClientFence\InformationException::class, '.net cannot be applied');

    PriceSync::saveSettings($di, 1, ['markup' => ['renew' => ['type' => 'percent', 'value' => '10']]]);
    expect(fn () => PriceSync::apply($di, 1, ['.com'], [], $preview['fingerprint'], null))->toThrow(ClientFence\InformationException::class, 'changed since the preview')
        ->and($db->fetchAllAssociative('SELECT * FROM tld ORDER BY id'))->toBe($before)
        ->and($db->fetchOne('SELECT COUNT(*) FROM tld_registrar_cost WHERE applied_renew IS NOT NULL'))->toEqual(0);
});

/**
 * The import applied .com and created .io for registrar 1 at USD 1.25; the sync then runs with $mode.
 *
 * @return array{0: Pimple\Container, 1: Doctrine\DBAL\Connection, 2: PriceSyncFakeRegistrar, 3: ArrayObject}
 */
function priceSyncScheduled(string $mode, int $slice = 0): array
{
    [$di, $db, $registrar] = priceSyncFixture(new PriceSyncFakeRegistrar(PRICE_LIST, 'USD', $slice));
    PriceSync::fetch($di, 1, 30);
    PriceSync::apply($di, 1, ['.com', '.io'], [], PriceSync::preview($di, 1)['fingerprint'], null);
    // A TLD priced by hand on the same registrar: never followed.
    $db->insert('tld', ['tld' => '.dev', 'tld_registrar_id' => 1, 'price_registration' => 1.0, 'price_renew' => 1.0, 'price_transfer' => 1.0, 'active' => 1, 'allow_register' => 1, 'allow_transfer' => 1, 'require_transfer_code' => 0, 'min_years' => 1]);
    $registrar->prices['.dev'] = ['register' => 50.0, 'renew' => 50.0, 'transfer' => 50.0];
    PriceSync::saveSettings($di, 1, ['mode' => $mode]);
    $registrar->calls = 0;

    $emails = new ArrayObject();
    $service = $di['mod_service']('servicedomain');
    $email = new class($emails) {
        public function __construct(private ArrayObject $emails)
        {
        }

        public function sendTemplate(array $data): bool
        {
            $this->emails->append($data);

            return true;
        }
    };
    $di['mod_service'] = $di->protect(static fn (string $name) => $name === 'email' ? $email : $service);
    $di['url'] = new class {
        public function adminLink(string $path): string
        {
            return '/admin/' . $path;
        }
    };

    return [$di, $db, $registrar, $emails];
}

function priceSyncTomorrow(Doctrine\DBAL\Connection $db): void
{
    $db->update('mod_clientfence_state', ['value' => json_encode(['remaining' => null, 'done_at' => date('Y-m-d H:i:s', strtotime('-1 day'))])], ['name' => 'tld_sync_1']);
}

test('the scheduled sync is off by default and then calls no registrar', function (): void {
    [$di, , $registrar, $emails] = priceSyncScheduled('off');
    PriceSync::runIfDue($di);

    expect($registrar->calls)->toBe(0)->and($emails)->toHaveCount(0);
});

test('notify only emails a cost change once and writes nothing; an exchange rate move alone is ignored', function (): void {
    [$di, $db, $registrar, $emails] = priceSyncScheduled('notify');
    $db->update('currency', ['conversion_rate' => 1.5], ['code' => 'USD']);
    PriceSync::runIfDue($di);
    expect($emails)->toHaveCount(0)->and($registrar->calls)->toBe(1);
    $db->update('currency', ['conversion_rate' => 1.25], ['code' => 'USD']);

    $registrar->prices['.com']['renew'] = 13.75;
    priceSyncTomorrow($db);
    PriceSync::runIfDue($di);
    PriceSync::runIfDue($di);
    expect($emails)->toHaveCount(1)->and($registrar->calls)->toBe(2)
        ->and($emails[0]['code'])->toBe('mod_staff_tld_price_changes')
        ->and(array_column($emails[0]['sync']['rows'], 'tld'))->toBe(['.com'])
        ->and($emails[0]['sync']['rows'][0]['what'])->toBe('suggested')
        ->and($emails[0]['sync']['rows'][0]['actions']['renew'])->toMatchArray(['cost_old' => 12.5, 'cost_new' => 13.75, 'price_now' => 10.0, 'price_new' => 11.0])
        ->and((float) $db->fetchOne("SELECT price_renew FROM tld WHERE tld = '.com'"))->toBe(10.0);

    // The next day the same set is not emailed again, even when the daily rate update moved the suggested price.
    $db->update('currency', ['conversion_rate' => 1.26], ['code' => 'USD']);
    priceSyncTomorrow($db);
    PriceSync::runIfDue($di);
    expect($emails)->toHaveCount(1)->and($registrar->calls)->toBe(3);
});

test('apply writes changes and below-cost prices, holds a jump over half, and skips locked and hand-priced TLDs', function (): void {
    [$di, $db, $registrar, $emails] = priceSyncScheduled('apply');
    // The rate moved too: only the renewal, whose cost changed, gets a new price (at today's rate).
    $db->update('currency', ['conversion_rate' => 1.3], ['code' => 'USD']);
    $registrar->prices['.com']['renew'] = 13.75;
    $registrar->prices['.io']['register'] = 80.0;
    $registrar->prices['.dev']['register'] = 99.0;
    PriceSync::runIfDue($di);

    $rows = array_column($emails[0]['sync']['rows'], null, 'tld');
    expect(array_keys($rows))->toBe(['.com', '.io'])
        ->and($rows['.com']['what'])->toBe('applied')
        ->and($db->fetchAssociative("SELECT price_registration, price_renew FROM tld WHERE tld = '.com'"))->toEqual(['price_registration' => 8.0, 'price_renew' => 10.58])
        ->and($rows['.io']['what'])->toBe('review')
        ->and((float) $db->fetchOne("SELECT price_registration FROM tld WHERE tld = '.io'"))->toBe(32.0)
        ->and((float) $db->fetchOne("SELECT price_registration FROM tld WHERE tld = '.dev'"))->toBe(1.0);

    // Applied changes are the new baseline; the held one stays and is not emailed again.
    priceSyncTomorrow($db);
    PriceSync::runIfDue($di);
    expect($emails)->toHaveCount(1);

    // A price staff lowered below cost is raised back; a locked TLD is left alone.
    $db->update('tld', ['price_renew' => 10.0], ['tld' => '.com']);
    $db->update('tld', ['price_locked' => 1], ['tld' => '.io']);
    priceSyncTomorrow($db);
    PriceSync::runIfDue($di);
    expect($emails)->toHaveCount(2)
        ->and(array_column($emails[1]['sync']['rows'], 'tld'))->toBe(['.com'])
        ->and($emails[1]['sync']['rows'][0])->toMatchArray(['below' => true, 'what' => 'applied'])
        ->and((float) $db->fetchOne("SELECT price_renew FROM tld WHERE tld = '.com'"))->toBe(10.58);
});

test('a registrar error changes nothing and is tried again the next day; the time limit stops a run', function (): void {
    [$di, $db, $registrar, $emails] = priceSyncScheduled('apply', 1);
    $registrar->fail = 'down';
    PriceSync::runIfDue($di);
    PriceSync::runIfDue($di);
    expect($registrar->calls)->toBe(1)->and($emails)->toHaveCount(0)
        ->and($di['logger']->lines)->toContain('TLD price sync of registrar #1 failed: down');

    $registrar->fail = null;
    $registrar->prices['.com']['renew'] = 13.75;
    priceSyncTomorrow($db);
    PriceSync::runIfDue($di, 0);
    expect($registrar->calls)->toBe(2)->and($emails)->toHaveCount(0);
    PriceSync::runIfDue($di, 0);
    expect($registrar->calls)->toBe(3)->and($emails)->toHaveCount(1)
        ->and((float) $db->fetchOne("SELECT price_renew FROM tld WHERE tld = '.com'"))->toBe(11.0);
});

test('a sync that sees a new registrar currency makes the page get its list again', function (): void {
    [$di, $db, $registrar] = priceSyncScheduled('notify');
    $db->insert('currency', ['id' => 3, 'code' => 'GBP', 'is_default' => 0, 'conversion_rate' => 0.9]);
    $registrar->currency = 'GBP';
    PriceSync::runIfDue($di);

    expect(fn () => PriceSync::preview($di, 1))->toThrow(ClientFence\InformationException::class, 'Get the price list first.')
        ->and($db->fetchOne("SELECT applied_rate FROM tld_registrar_cost WHERE tld = '.com'"))->toBeNull();
});
