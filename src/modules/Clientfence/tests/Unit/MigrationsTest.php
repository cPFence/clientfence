<?php

declare(strict_types=1);

/**
 * ClientFence: the migration runner (Mod\Clientfence\Migrations) on an in-memory SQLite `setting`
 * table, and how the list fits a fresh install.
 */

use ClientFence\Mod\Clientfence\Migrations;
use Symfony\Component\Filesystem\Path;

function migrationsPdo(?string $level = null): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE setting (id INTEGER PRIMARY KEY, param TEXT, value TEXT, public INTEGER, category TEXT, hash TEXT, created_at TEXT, updated_at TEXT)');
    if ($level !== null) {
        $pdo->prepare("INSERT INTO setting (param, value, public, created_at, updated_at) VALUES (?, ?, 0, '2026-10-01', '2026-10-01')")->execute([Migrations::SETTING, $level]);
    }

    return $pdo;
}

test('pending migrations run in order and the level is recorded after each', function (): void {
    $pdo = migrationsPdo();
    $log = [];
    $steps = [3 => function () use (&$log): void { $log[] = 3; }, 1 => function () use (&$log): void { $log[] = 1; }, 2 => function () use (&$log): void { $log[] = 2; }];

    expect(Migrations::current($pdo))->toBe(0)
        ->and(Migrations::run($pdo, $steps))->toBe([1, 2, 3])
        ->and($log)->toBe([1, 2, 3])
        ->and(Migrations::current($pdo))->toBe(3)
        ->and((int) $pdo->query("SELECT COUNT(*) FROM setting WHERE param = 'clientfence_patch'")->fetchColumn())->toBe(1)
        // A second run has nothing left to do.
        ->and(Migrations::run($pdo, $steps))->toBe([])
        ->and($log)->toBe([1, 2, 3]);
});

test('only the migrations above the recorded level run', function (): void {
    $pdo = migrationsPdo('2');
    $log = [];
    $steps = [1 => function () use (&$log): void { $log[] = 1; }, 2 => function () use (&$log): void { $log[] = 2; }, 3 => function () use (&$log): void { $log[] = 3; }];

    expect(Migrations::run($pdo, $steps))->toBe([3])
        ->and($log)->toBe([3])
        ->and(Migrations::current($pdo))->toBe(3);
});

test('a failed migration stops the run at the last one that finished', function (): void {
    $pdo = migrationsPdo();
    $steps = [1 => function (): void {}, 2 => function (): void { throw new RuntimeException('disk full'); }, 3 => function (): void {}];

    expect(fn () => Migrations::run($pdo, $steps))->toThrow(RuntimeException::class, 'disk full')
        ->and(Migrations::current($pdo))->toBe(1);
});

test('the pending count compares the recorded level with the list', function (): void {
    expect(Migrations::pending(migrationsPdo()))->toBe(Migrations::latest())
        ->and(Migrations::pending(migrationsPdo((string) Migrations::latest())))->toBe(0);
});

test('migrations are numbered from 1 without gaps, and each one exists', function (): void {
    $list = (new ReflectionClassConstant(Migrations::class, 'LIST'))->getValue();

    expect(array_keys($list))->toBe(range(1, count($list)))
        ->and(Migrations::latest())->toBe(count($list));
    foreach ($list as $method) {
        expect(method_exists(Migrations::class, $method))->toBeTrue();
    }
});

test('fresh installs start at the latest ClientFence migration', function (): void {
    $content = (string) file_get_contents(Path::join(PATH_ROOT, 'install', 'sql', 'content.sql'));
    preg_match("/'clientfence_patch','(\\d+)'/", $content, $matches);

    expect((int) ($matches[1] ?? 0))->toBe(Migrations::latest());
});

test('every ClientFence table is also created by a fresh install', function (): void {
    $structure = (string) file_get_contents(Path::join(PATH_ROOT, 'install', 'sql', 'structure.sql'));
    $missing = array_filter(array_keys(ClientFence\Mod\Clientfence\Service::tables()), static fn (string $table): bool => !str_contains($structure, "CREATE TABLE `{$table}`") && !str_contains($structure, "CREATE TABLE IF NOT EXISTS `{$table}`"));

    expect(array_values($missing))->toBe([]);
});

test('migration 10 gives every client but the first a new PIN where PINs are shared, then makes PINs unique', function (): void {
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE setting (id INTEGER PRIMARY KEY, param TEXT, value TEXT, public INTEGER, category TEXT, hash TEXT, created_at TEXT, updated_at TEXT)');
    $db->insert('setting', ['param' => Migrations::SETTING, 'value' => '9', 'public' => 0, 'created_at' => '2026-10-01', 'updated_at' => '2026-10-01']);
    $db->executeStatement('CREATE TABLE client (id INTEGER PRIMARY KEY)');
    $db->executeStatement('CREATE TABLE client_support_pin (client_id INTEGER PRIMARY KEY, pin TEXT NOT NULL)');
    foreach ([3 => '11111111', 5 => '22222222', 8 => '11111111', 9 => '11111111', 12 => '22222222', 20 => '33333333'] as $clientId => $pin) {
        $db->insert('client', ['id' => $clientId]);
        $db->insert('client_support_pin', ['client_id' => $clientId, 'pin' => $pin]);
    }
    $di = Tests\Helpers\container();
    $di['dbal'] = $db;
    $di['pdo'] = $db->getNativeConnection();

    // Migration 10 alone: the later ones need tables this test does not create.
    expect(Migrations::run($di['pdo'], [10 => fn () => (new ReflectionMethod(Migrations::class, 'm10SupportPinUnique'))->invoke(null, $di)]))->toBe([10]);
    $pins = $db->fetchAllKeyValue('SELECT client_id, pin FROM client_support_pin ORDER BY client_id');
    expect(array_keys($pins))->toBe([3, 5, 8, 9, 12, 20])
        // The oldest client keeps a shared PIN and a PIN nobody shared is kept.
        ->and([$pins[3], $pins[5], $pins[20]])->toBe(['11111111', '22222222', '33333333'])
        ->and(array_unique($pins))->toHaveCount(6)
        ->and(array_filter($pins, static fn ($pin): bool => preg_match('/^\d{8}$/', (string) $pin) !== 1))->toBe([])
        ->and(fn () => $db->insert('client_support_pin', ['client_id' => 30, 'pin' => '33333333']))->toThrow(Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);

    // Run again (an interrupted update repeats it): the unique key is there, nothing changes.
    (new ReflectionMethod(Migrations::class, 'm10SupportPinUnique'))->invoke(null, $di);
    expect($db->fetchAllKeyValue('SELECT client_id, pin FROM client_support_pin ORDER BY client_id'))->toBe($pins);
});

test('migration 11 makes each client\'s group their group list and adds the automatic promotion switch', function (): void {
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE setting (id INTEGER PRIMARY KEY, param TEXT, value TEXT, public INTEGER, category TEXT, hash TEXT, created_at TEXT, updated_at TEXT)');
    $db->insert('setting', ['param' => Migrations::SETTING, 'value' => '10', 'public' => 0, 'created_at' => '2026-10-01', 'updated_at' => '2026-10-01']);
    $db->executeStatement('CREATE TABLE client_group (id INTEGER PRIMARY KEY)');
    $db->executeStatement('CREATE TABLE client (id INTEGER PRIMARY KEY, client_group_id INTEGER)');
    $db->executeStatement('CREATE TABLE client_group_members (client_id INTEGER NOT NULL, client_group_id INTEGER NOT NULL, PRIMARY KEY (client_id, client_group_id))');
    $db->executeStatement('CREATE TABLE promo (id INTEGER PRIMARY KEY, code TEXT)');
    $db->executeStatement('INSERT INTO client_group (id) VALUES (1), (2)');
    // Client 3 has no group, client 4's group was deleted.
    $db->executeStatement('INSERT INTO client (id, client_group_id) VALUES (1, 1), (2, 2), (3, NULL), (4, 9)');
    $di = Tests\Helpers\container();
    $di['dbal'] = $db;
    $di['pdo'] = $db->getNativeConnection();

    // Migration 11 alone: the later ones need tables this test does not create.
    expect(Migrations::run($di['pdo'], [11 => fn () => (new ReflectionMethod(Migrations::class, 'm11GroupsAutoPromos'))->invoke(null, $di)]))->toBe([11])
        ->and($db->fetchAllNumeric('SELECT client_id, client_group_id FROM client_group_members ORDER BY client_id'))->toBe([[1, 1], [2, 2]])
        ->and(array_map(static fn ($c): string => $c->getName(), $db->createSchemaManager()->listTableColumns('promo')))->toContain('auto_apply');

    // Run again (an interrupted update repeats it): nothing is added twice.
    (new ReflectionMethod(Migrations::class, 'm11GroupsAutoPromos'))->invoke(null, $di);
    expect((int) $db->fetchOne('SELECT COUNT(*) FROM client_group_members'))->toBe(2);
});

test('migration 12 adds the sign-in details to both login histories, once', function (): void {
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE setting (id INTEGER PRIMARY KEY, param TEXT, value TEXT, public INTEGER, category TEXT, hash TEXT, created_at TEXT, updated_at TEXT)');
    $db->insert('setting', ['param' => Migrations::SETTING, 'value' => '11', 'public' => 0, 'created_at' => '2026-10-01', 'updated_at' => '2026-10-01']);
    // Migrations 13 to 17 (MySQL DDL) then find their tables there; 18 adds columns to the gateway, client and invoice tables.
    $db->executeStatement('CREATE TABLE client_order_hold (id INTEGER PRIMARY KEY)');
    $db->executeStatement('CREATE TABLE client_passkey (id INTEGER PRIMARY KEY)');
    $db->executeStatement('CREATE TABLE client_id_document (id INTEGER PRIMARY KEY)');
    $db->executeStatement('CREATE TABLE activity_api (id INTEGER PRIMARY KEY)');
    $db->executeStatement('CREATE TABLE client_balance_alert (client_id INTEGER PRIMARY KEY)');
    $db->executeStatement('CREATE TABLE pay_gateway (id INTEGER PRIMARY KEY, gateway TEXT)');
    $db->executeStatement('CREATE TABLE client (id INTEGER PRIMARY KEY)');
    $db->executeStatement('CREATE TABLE invoice (id INTEGER PRIMARY KEY)');
    // 19 adds the client profile switches and checks the import's state.
    $db->executeStatement('CREATE TABLE mod_clientfence_state (name TEXT PRIMARY KEY, value TEXT, updated_at TEXT)');
    // 20 adds the service's payment method.
    $db->executeStatement('CREATE TABLE client_order (id INTEGER PRIMARY KEY)');
    $db->executeStatement('CREATE TABLE activity_client_history (id INTEGER PRIMARY KEY, client_id INTEGER, ip TEXT, created_at TEXT)');
    $db->executeStatement('CREATE TABLE activity_admin_history (id INTEGER PRIMARY KEY, admin_id INTEGER, ip TEXT, created_at TEXT)');
    $db->insert('activity_client_history', ['client_id' => 7, 'ip' => '203.0.113.7', 'created_at' => '2026-11-01 10:00:00']);
    $di = Tests\Helpers\container();
    $di['dbal'] = $db;
    $di['pdo'] = $db->getNativeConnection();
    $columns = static fn (string $table): array => array_map(static fn ($c): string => $c->getName(), array_values($db->createSchemaManager()->listTableColumns($table)));
    $details = ['country', 'asn', 'network', 'browser', 'session_id'];

    // 21 adds the invoice's copy of the custom client fields shown on it; 24 (and 25) the RP ID each passkey was made for;
    // 26 the site of each record (the tables this database lacks are left out).
    expect(Migrations::apply($di))->toBe([12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26])
        ->and($columns('client_order'))->toBe(['id', 'gateway_id', 'site'])
        ->and($columns('client_passkey'))->toBe(['id', 'rp_id'])
        ->and([$columns('pay_gateway'), $columns('client'), $columns('invoice')])->toBe([['id', 'gateway', 'position'], ['id', 'po_number', 'overdue_notices', 'separate_invoices', 'auto_charge', 'allow_sso', 'status_update', 'site'], ['id', 'po_number', 'buyer_custom', 'site']])
        ->and($columns('activity_client_history'))->toBe(['id', 'client_id', 'ip', 'created_at', 'user_id', ...$details])
        ->and($columns('activity_admin_history'))->toBe(['id', 'admin_id', 'ip', 'created_at', ...$details])
        ->and($db->createSchemaManager()->listTableIndexes('activity_client_history'))->toHaveKey('user_id_idx')
        // Rows already there get no details.
        ->and($db->fetchAssociative('SELECT client_id, user_id, country, session_id FROM activity_client_history'))->toBe(['client_id' => 7, 'user_id' => null, 'country' => null, 'session_id' => null]);

    // Run again (an interrupted update repeats it): nothing is added twice.
    (new ReflectionMethod(Migrations::class, 'm12SignInHistory'))->invoke(null, $di);
    (new ReflectionMethod(Migrations::class, 'm18GatewayOrderPoNumber'))->invoke(null, $di);
    (new ReflectionMethod(Migrations::class, 'm20OrderPaymentMethod'))->invoke(null, $di);
    (new ReflectionMethod(Migrations::class, 'm21InvoiceCustomFields'))->invoke(null, $di);
    (new ReflectionMethod(Migrations::class, 'm24PasskeyRpId'))->invoke(null, $di);
    expect($columns('client_passkey'))->toBe(['id', 'rp_id'])
        ->and($columns('activity_client_history'))->toBe(['id', 'client_id', 'ip', 'created_at', 'user_id', ...$details])
        ->and($columns('invoice'))->toBe(['id', 'po_number', 'buyer_custom', 'site'])
        ->and($columns('client_order'))->toBe(['id', 'gateway_id', 'site']);
});

test('migration 26 adds the site of each record as an empty column, once', function (): void {
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $tables = ['client', 'client_order', 'invoice', 'invoice_quote', 'support_ticket', 'email_queue'];
    foreach ($tables as $table) {
        $db->executeStatement("CREATE TABLE {$table} (id INTEGER PRIMARY KEY, client_id INTEGER)");
        $db->insert($table, ['id' => 1, 'client_id' => 7]);
    }
    $di = Tests\Helpers\container();
    $di['dbal'] = $db;
    $migration = new ReflectionMethod(Migrations::class, 'm26RecordSite');

    // Run twice (an interrupted update repeats it).
    $migration->invoke(null, $di);
    $migration->invoke(null, $di);
    foreach ($tables as $table) {
        $site = array_values(array_filter($db->createSchemaManager()->listTableColumns($table), static fn ($c): bool => $c->getName() === 'site'));
        expect(array_map(static fn ($c): string => $c->getName(), array_values($db->createSchemaManager()->listTableColumns($table))))->toBe(['id', 'client_id', 'site'])
            ->and($site[0]->getNotnull())->toBeFalse()
            ->and($site[0]->getDefault())->toBeNull()
            // Rows already there stay on the primary site.
            ->and($db->fetchAssociative("SELECT * FROM {$table}"))->toBe(['id' => 1, 'client_id' => 7, 'site' => null]);
    }
});

test('a database at level 24 that ran its first form gets the RP ID from step 25, once', function (): void {
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE setting (id INTEGER PRIMARY KEY, param TEXT, value TEXT, public INTEGER, category TEXT, hash TEXT, created_at TEXT, updated_at TEXT)');
    $db->insert('setting', ['param' => Migrations::SETTING, 'value' => '24', 'public' => 0, 'created_at' => '2026-11-24', 'updated_at' => '2026-11-24']);
    // A copy that ran the first form of 24: a site key per passkey, NULL on the primary site.
    $db->executeStatement('CREATE TABLE client_passkey (id INTEGER PRIMARY KEY, name TEXT, site TEXT)');
    $db->insert('client_passkey', ['id' => 1, 'name' => 'primary']);
    $db->insert('client_passkey', ['id' => 2, 'name' => 'other host', 'site' => 'shop/shop']);
    $di = Tests\Helpers\container();
    $di['dbal'] = $db;
    $columns = static fn (): array => array_map(static fn ($c): string => $c->getName(), array_values($db->createSchemaManager()->listTableColumns('client_passkey')));
    // The steps as apply() runs them, through the level check.
    $steps = array_map(static fn (string $method): Closure => static fn () => (new ReflectionMethod(Migrations::class, $method))->invoke(null, $di), (new ReflectionClassConstant(Migrations::class, 'LIST'))->getValue());

    // (26 then finds none of its tables here.)
    expect(Migrations::run($db->getNativeConnection(), $steps))->toBe([25, 26])
        ->and($columns())->toBe(['id', 'name', 'rp_id'])
        // The other host's passkey stays (it still counts for two-factor sign-in) with an RP ID no host has.
        ->and($db->fetchAllAssociative('SELECT * FROM client_passkey'))->toBe([['id' => 1, 'name' => 'primary', 'rp_id' => null], ['id' => 2, 'name' => 'other host', 'rp_id' => '']])
        ->and(Migrations::run($db->getNativeConnection(), $steps))->toBe([]);
});

test('migration 19 adds the profile switches once, and removes the opt-outs only a non-WHMCS import wrote by mistake', function (string $system, array $kept): void {
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE client (id INTEGER PRIMARY KEY)');
    $db->executeStatement('CREATE TABLE mod_massmailer (id INTEGER PRIMARY KEY)');
    $db->executeStatement('CREATE TABLE mod_clientfence_state (name TEXT PRIMARY KEY, value TEXT, updated_at TEXT)');
    $db->executeStatement('CREATE TABLE client_marketing_optout (client_id INTEGER PRIMARY KEY, source TEXT, opted_out_at TEXT)');
    $db->insert('client', ['id' => 1]);
    $db->insert('mod_clientfence_state', ['name' => ClientFence\Mod\Clientfence\Whmcs\Run::STATE, 'value' => '{}']);
    $db->insert('mod_clientfence_state', ['name' => ClientFence\Mod\Clientfence\Whmcs\Import::SETTINGS, 'value' => json_encode([$system => 1])]);
    foreach ([[1, 'whmcs'], [2, 'profile'], [3, 'link']] as [$client, $source]) {
        $db->insert('client_marketing_optout', ['client_id' => $client, 'source' => $source, 'opted_out_at' => '2026-11-01']);
    }
    $di = Tests\Helpers\container();
    $di['dbal'] = $db;
    $di['em']->shouldReceive('getConnection')->andReturn($db);
    $di['logger'] = new Tests\Helpers\TestLogger();
    $migration = new ReflectionMethod(Migrations::class, 'm19ClientProfileOptions');

    $migration->invoke(null, $di);
    $migration->invoke(null, $di);

    expect($db->fetchAllAssociative('SELECT * FROM client'))->toBe([['id' => 1, 'overdue_notices' => 1, 'separate_invoices' => 0, 'auto_charge' => 1, 'allow_sso' => 1, 'status_update' => 1]])
        ->and(array_map(static fn ($c): string => $c->getName(), array_values($db->createSchemaManager()->listTableColumns('mod_massmailer'))))->toBe(['id', 'marketing'])
        ->and($db->fetchFirstColumn('SELECT client_id FROM client_marketing_optout ORDER BY client_id'))->toBe($kept);
})->with([
    'a Blesta import' => ['blesta', [2, 3]],
    'a WHMCS import keeps its clients\' choices' => ['none', [1, 2, 3]],
]);
