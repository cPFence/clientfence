<?php

declare(strict_types=1);

/**
 * ClientFence: the WHMCS import's affiliate step (Whmcs\Affiliates), from a small invented WHMCS database into a
 * ClientFence database, both SQLite: WHMCS's column names only, no WHMCS code or data.
 */

use ClientFence\Mod\Client\Affiliates\Affiliates as Program;
use ClientFence\Mod\Clientfence\Whmcs\Affiliates;
use ClientFence\Mod\Clientfence\Whmcs\Map;
use ClientFence\Mod\Clientfence\Whmcs\Run;
use ClientFence\Mod\Clientfence\Whmcs\Source;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

function whmcsAffiliatesSource(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
        'CREATE TABLE tblconfiguration (setting TEXT, value TEXT)',
        'CREATE TABLE tblaffiliates (id INTEGER PRIMARY KEY, date TEXT, clientid INTEGER, visitors INTEGER, paytype TEXT, payamount REAL, onetime INTEGER, balance REAL, withdrawn REAL)',
        'CREATE TABLE tblaffiliatesaccounts (id INTEGER PRIMARY KEY, affiliateid INTEGER, relid INTEGER, lastpaid TEXT, created_at TEXT)',
        'CREATE TABLE tblaffiliatespending (id INTEGER PRIMARY KEY, affaccid INTEGER, invoice_id INTEGER, amount REAL, clearingdate TEXT, created_at TEXT)',
        'CREATE TABLE tblaffiliateshistory (id INTEGER PRIMARY KEY, affiliateid INTEGER, date TEXT, affaccid INTEGER, invoice_id INTEGER, description TEXT, amount REAL)',
        'CREATE TABLE tblaffiliateswithdrawals (id INTEGER PRIMARY KEY, affiliateid INTEGER, date TEXT, amount REAL)',
        'CREATE TABLE tblaffiliates_hits (id INTEGER PRIMARY KEY, affiliate_id INTEGER, referrer_id INTEGER, created_at TEXT)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    $db->executeStatement("INSERT INTO tblconfiguration VALUES ('Version', '8.13.1-release.1'), ('AffiliateEnabled', 'on'), ('AffiliateEarningPercent', '10'), ('AffiliateBonusDeposit', '10.00'), ('AffiliatePayout', '25'), ('AffiliatesDelayCommission', '30')");
    // Affiliate 1 has its own commission and everything; 2 only an old bonus deposit in its balance and a visitor
    // total; 3 belongs to a client that was not imported.
    $db->executeStatement("INSERT INTO tblaffiliates VALUES
        (1, '2025-01-15', 1, 10, 'percentage', 15, 1, 32.50, 20.00),
        (2, '2025-03-01', 2, 4, 'fixedamount', 3, 0, 10.00, 0),
        (3, '2025-04-01', 99, 0, '', 0, 0, 5.00, 0)");
    // Service 7 was imported, service 9 was not.
    $db->executeStatement("INSERT INTO tblaffiliatesaccounts VALUES (1, 1, 7, '2026-10-01', '2025-02-01 10:00:00'), (2, 1, 9, '0000-00-00', '2025-02-02 10:00:00')");
    $db->executeStatement("INSERT INTO tblaffiliatespending VALUES (1, 1, 100, 5.00, '2026-12-01', '2026-11-01 10:00:00'), (2, 2, 0, 3.00, '2026-12-02', '2026-11-02 10:00:00')");
    $db->executeStatement("INSERT INTO tblaffiliateshistory VALUES
        (1, 1, '2026-06-01', 1, 99, 'Commission', 12.50),
        (2, 1, '2026-06-02', 0, 0, 'Manual bonus', 25.00),
        (3, 1, '2026-06-03', 0, 0, 'Refund taken back', -2.50)");
    $db->executeStatement("INSERT INTO tblaffiliateswithdrawals VALUES (1, 1, '2026-07-01', 20.00)");
    $db->executeStatement("INSERT INTO tblaffiliates_hits VALUES (1, 1, 0, '2026-10-01 09:00:00'), (2, 1, 0, '2026-10-01 10:00:00'), (3, 1, 0, '2026-10-01 11:00:00'), (4, 1, 0, '2026-10-02 09:00:00')");

    return $db;
}

function whmcsAffiliatesTarget(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
        'CREATE TABLE ' . Map::TABLE . ' (source TEXT, entity TEXT, source_id TEXT, target_id INTEGER, created_at TEXT, PRIMARY KEY (source, entity, source_id))',
        'CREATE TABLE currency (id INTEGER PRIMARY KEY, code TEXT, is_default INTEGER)',
        'CREATE TABLE client (id INTEGER PRIMARY KEY, currency TEXT)',
        'CREATE TABLE client_order (id INTEGER PRIMARY KEY, client_id INTEGER)',
        'CREATE TABLE setting (id INTEGER PRIMARY KEY AUTOINCREMENT, param TEXT UNIQUE, value TEXT, public INTEGER, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE client_affiliate (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER UNIQUE, code TEXT UNIQUE, status TEXT, commission_type TEXT, commission_amount REAL, onetime INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE client_affiliate_referral (id INTEGER PRIMARY KEY AUTOINCREMENT, affiliate_id INTEGER, client_id INTEGER, client_order_id INTEGER UNIQUE, source TEXT, created_at TEXT)',
        'CREATE TABLE client_affiliate_entry (id INTEGER PRIMARY KEY AUTOINCREMENT, affiliate_id INTEGER, type TEXT, status TEXT, amount REAL, currency TEXT, source_amount REAL, source_currency TEXT, rate REAL, client_id INTEGER, client_order_id INTEGER, invoice_id INTEGER, reverses_id INTEGER UNIQUE, payout_id INTEGER, admin_id INTEGER, note TEXT, clears_at TEXT, hold_until TEXT, created_at TEXT, updated_at TEXT, UNIQUE (invoice_id, client_order_id))',
        'CREATE TABLE client_affiliate_payout (id INTEGER PRIMARY KEY AUTOINCREMENT, affiliate_id INTEGER, amount REAL, currency TEXT, status TEXT, method TEXT, details TEXT, admin_id INTEGER, created_at TEXT, processed_at TEXT, updated_at TEXT)',
        'CREATE TABLE client_affiliate_visit (affiliate_id INTEGER, day TEXT, hits INTEGER, PRIMARY KEY (affiliate_id, day))',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    $db->insert('currency', ['id' => 1, 'code' => 'USD', 'is_default' => 1]);
    $db->executeStatement("INSERT INTO client VALUES (1, 'USD'), (2, 'EUR'), (3, 'USD')");
    $db->executeStatement('INSERT INTO client_order VALUES (70, 3)');
    $map = new Map($db);
    foreach ([1, 2, 3] as $client) {
        $map->put('client', $client, $client);
    }
    $map->put('service', 7, 70);
    $map->put('invoice', 100, 100);

    return $db;
}

/**
 * Runs the affiliate step to the end in batches of $batch; returns the warnings.
 *
 * @return list<string>
 */
function whmcsAffiliatesAll(Connection $source, Connection $target, int $batch = 2): array
{
    $warnings = [];
    $after = 0;
    do {
        $runner = new Affiliates(new Source($source), $target, new Map($target), '2026-11-14 12:00:00');
        [$after] = $runner->batch('affiliates', (int) $after, $batch);
        array_push($warnings, ...$runner->warnings());
    } while ($after !== null);

    return $warnings;
}

test('affiliates come over with their WHMCS id as code, referrals, ledger and visits; the available balance is WHMCS\'s', function (): void {
    $target = whmcsAffiliatesTarget();
    $warnings = implode("\n", whmcsAffiliatesAll(whmcsAffiliatesSource(), $target));
    $map = new Map($target);
    [$first, $second] = [(int) $map->get('affiliate', 1), (int) $map->get('affiliate', 2)];

    expect($target->fetchAllAssociative('SELECT client_id, code, status, commission_type, commission_amount, onetime, created_at, updated_at FROM client_affiliate ORDER BY id'))->toBe([
        ['client_id' => 1, 'code' => '1', 'status' => 'active', 'commission_type' => 'percentage', 'commission_amount' => 15.0, 'onetime' => 1, 'created_at' => '2025-01-15 00:00:00', 'updated_at' => '2026-11-14 12:00:00'],
        ['client_id' => 2, 'code' => '2', 'status' => 'active', 'commission_type' => 'fixed', 'commission_amount' => 3.0, 'onetime' => 0, 'created_at' => '2025-03-01 00:00:00', 'updated_at' => '2026-11-14 12:00:00'],
    ])
        ->and($target->fetchAllAssociative('SELECT affiliate_id, client_id, client_order_id, source FROM client_affiliate_referral'))->toBe([
            ['affiliate_id' => $first, 'client_id' => 3, 'client_order_id' => 70, 'source' => 'import'],
        ])
        ->and($target->fetchAllAssociative('SELECT type, status, amount, currency, client_id, client_order_id, invoice_id, note, clears_at FROM client_affiliate_entry WHERE affiliate_id = ? ORDER BY id', [$first]))->toBe([
            ['type' => 'commission', 'status' => 'pending', 'amount' => 5.0, 'currency' => 'USD', 'client_id' => 3, 'client_order_id' => 70, 'invoice_id' => 100, 'note' => null, 'clears_at' => '2026-12-01 00:00:00'],
            // Invoice 99 was not imported: the commission keeps its service, without an invoice.
            ['type' => 'commission', 'status' => 'cleared', 'amount' => 12.5, 'currency' => 'USD', 'client_id' => 3, 'client_order_id' => 70, 'invoice_id' => null, 'note' => 'Commission', 'clears_at' => null],
            ['type' => 'manual', 'status' => 'cleared', 'amount' => 25.0, 'currency' => 'USD', 'client_id' => null, 'client_order_id' => null, 'invoice_id' => null, 'note' => 'Manual bonus', 'clears_at' => null],
            ['type' => 'manual', 'status' => 'cleared', 'amount' => -2.5, 'currency' => 'USD', 'client_id' => null, 'client_order_id' => null, 'invoice_id' => null, 'note' => 'Refund taken back', 'clears_at' => null],
            ['type' => 'payout', 'status' => 'cleared', 'amount' => -20.0, 'currency' => 'USD', 'client_id' => null, 'client_order_id' => null, 'invoice_id' => null, 'note' => null, 'clears_at' => null],
            // 12.50 + 25 - 2.50 - 20 = 15 in the ledger; WHMCS's balance is 32.50.
            ['type' => 'opening', 'status' => 'cleared', 'amount' => 17.5, 'currency' => 'USD', 'client_id' => null, 'client_order_id' => null, 'invoice_id' => null, 'note' => null, 'clears_at' => null],
        ])
        ->and($target->fetchAllAssociative('SELECT amount, status, method, details, created_at, processed_at FROM client_affiliate_payout WHERE affiliate_id = ?', [$first]))->toBe([
            ['amount' => 20.0, 'status' => 'paid', 'method' => 'outside', 'details' => 'Imported', 'created_at' => '2026-07-01 00:00:00', 'processed_at' => '2026-07-01 00:00:00'],
        ])
        ->and($target->fetchOne("SELECT payout_id FROM client_affiliate_entry WHERE type = 'payout'"))->toBe((int) $target->fetchOne('SELECT id FROM client_affiliate_payout'))
        ->and(Program::balances($target, $first))->toMatchArray(['pending' => 5.0, 'available' => 32.5, 'paid_out' => 20.0])
        // An old bonus deposit lives only in WHMCS's balance: the opening row, in the client's currency.
        ->and($target->fetchAllAssociative('SELECT type, amount, currency FROM client_affiliate_entry WHERE affiliate_id = ?', [$second]))->toBe([['type' => 'opening', 'amount' => 10.0, 'currency' => 'EUR']])
        ->and(Program::balances($target, $second)['available'])->toBe(10.0)
        // Hits per day; visitors counted before WHMCS logged hits go on the first day.
        ->and($target->fetchAllKeyValue('SELECT day, hits FROM client_affiliate_visit WHERE affiliate_id = ? ORDER BY day', [$first]))->toBe(['2025-01-15' => 6, '2026-10-01' => 3, '2026-10-02' => 1])
        ->and($target->fetchAllKeyValue('SELECT day, hits FROM client_affiliate_visit WHERE affiliate_id = ?', [$second]))->toBe(['2025-03-01' => 4])
        ->and($target->fetchAllKeyValue("SELECT param, value FROM setting WHERE param LIKE 'affiliate%' ORDER BY param"))->toBe([
            'affiliate_amount' => '10.00', 'affiliate_bonus' => '10.00', 'affiliate_clearing_days' => '30', 'affiliate_enabled' => '1', 'affiliate_min_payout' => '25.00', 'affiliate_type' => 'percentage',
        ])
        ->and($warnings)->toContain('Affiliate #3 was not imported: its client #99 was not imported.')
        ->and($warnings)->toContain('Affiliate #1: 2 referred services or pending commissions were not imported');

    // The check: both imported affiliates' available balances match; one of two referred services came over.
    $check = array_column(Run::compare(new Source(whmcsAffiliatesSource()), $target, ['steps' => ['affiliates' => ['finished' => true]]])['rows'], null, 'step');
    expect($check['affiliates'])->toMatchArray(['whmcs' => 3, 'imported' => 2])
        ->and($check['affiliatebalances'])->toMatchArray(['whmcs' => 2, 'imported' => 2])
        ->and($check['affiliatereferrals'])->toMatchArray(['whmcs' => 2, 'imported' => 1]);
});

test('a second run imports nothing twice; settings this install saved are kept; a taken code gets a new one', function (): void {
    $source = whmcsAffiliatesSource();
    $target = whmcsAffiliatesTarget();
    $target->insert('setting', ['param' => 'affiliate_enabled', 'value' => '0', 'public' => 0]);
    // Client 3 is already an affiliate with code "1".
    $target->insert('client_affiliate', ['client_id' => 3, 'code' => '1', 'status' => 'active', 'onetime' => 0, 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00']);
    $warnings = implode("\n", whmcsAffiliatesAll($source, $target));
    $counts = static fn (): array => array_map(static fn (string $t): int => (int) $target->fetchOne('SELECT COUNT(*) FROM ' . $t), ['client_affiliate' => 'client_affiliate', 'client_affiliate_referral' => 'client_affiliate_referral', 'client_affiliate_entry' => 'client_affiliate_entry', 'client_affiliate_payout' => 'client_affiliate_payout', 'client_affiliate_visit' => 'client_affiliate_visit', 'setting' => 'setting']);
    $before = $counts();

    expect($target->fetchOne('SELECT code FROM client_affiliate WHERE client_id = 1'))->toMatch('/^[0-9a-f]{12}$/')
        ->and($warnings)->toContain('Affiliate #1: its code "1" is not valid here or is already taken')
        ->and($target->fetchAllKeyValue('SELECT param, value FROM setting'))->toBe(['affiliate_enabled' => '0'])
        ->and(whmcsAffiliatesAll($source, $target, 50))->toBe([])
        ->and($counts())->toBe($before);
});

test('a WHMCS database or snapshot without affiliate tables imports fine', function (): void {
    $source = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $source->executeStatement('CREATE TABLE tblconfiguration (setting TEXT, value TEXT)');
    $source->executeStatement("INSERT INTO tblconfiguration VALUES ('AffiliateEnabled', 'on')");
    $target = whmcsAffiliatesTarget();
    $runner = new Affiliates(new Source($source), $target, new Map($target), '2026-11-14 12:00:00');

    expect($runner->total('affiliates'))->toBe(0)
        ->and($runner->batch('affiliates', 0, 200))->toBe([null, 0])
        ->and($runner->warnings())->toBe([])
        ->and($target->fetchOne('SELECT COUNT(*) FROM client_affiliate'))->toBe(0)
        ->and($target->fetchOne('SELECT COUNT(*) FROM setting'))->toBe(0);
});

test('a snapshot\'s own Blesta columns: codes with base64 padding are kept, domain-only pending commissions clear on the domain', function (): void {
    $source = whmcsAffiliatesSource();
    $source->executeStatement("ALTER TABLE tblaffiliates ADD COLUMN code TEXT DEFAULT ''");
    $source->executeStatement('ALTER TABLE tblaffiliatespending ADD COLUMN affiliateid INTEGER DEFAULT 0');
    $source->executeStatement('ALTER TABLE tblaffiliatespending ADD COLUMN domainid INTEGER DEFAULT 0');
    // Blesta's default code is base64 of the client id.
    $source->executeStatement("UPDATE tblaffiliates SET code = 'Mg==' WHERE id = 2");
    $source->executeStatement("INSERT INTO tblaffiliatespending VALUES (3, 0, 0, 4.00, '2026-12-03', '2026-11-03 10:00:00', 2, 5)");
    $target = whmcsAffiliatesTarget();
    $target->executeStatement('INSERT INTO client_order VALUES (71, 3)');
    (new Map($target))->put('domain', 5, 71);
    whmcsAffiliatesAll($source, $target);
    $second = (int) (new Map($target))->get('affiliate', 2);

    expect($target->fetchOne('SELECT code FROM client_affiliate WHERE id = ?', [$second]))->toBe('Mg==')
        ->and($target->fetchAllAssociative("SELECT type, status, amount, client_id, client_order_id, clears_at FROM client_affiliate_entry WHERE affiliate_id = ? AND type = 'commission'", [$second]))->toBe([
            ['type' => 'commission', 'status' => 'pending', 'amount' => 4.0, 'client_id' => 3, 'client_order_id' => 71, 'clears_at' => '2026-12-03 00:00:00'],
        ])
        // Domains do not earn: no referral for the domain order.
        ->and($target->fetchOne('SELECT COUNT(*) FROM client_affiliate_referral WHERE client_order_id = 71'))->toBe(0);
});
