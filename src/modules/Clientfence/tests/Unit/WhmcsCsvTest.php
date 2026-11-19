<?php

declare(strict_types=1);

/**
 * ClientFence (CF-213): the import's CSV downloads (Whmcs\Csv, Controller\Admin): the plan before
 * the import, the audit after it, formula escaping, no secrets, the id map read in chunks, and
 * only Super Administrators.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Clientfence\Whmcs\Csv;
use ClientFence\Mod\Clientfence\Whmcs\Import;
use ClientFence\Mod\Clientfence\Whmcs\Map;
use ClientFence\Mod\Clientfence\Whmcs\Run;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\HttpFoundation\Request;

function whmcsCsvDb(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE mod_clientfence_state (name TEXT PRIMARY KEY, value TEXT, updated_at TEXT)');
    $db->executeStatement('CREATE TABLE ' . Map::TABLE . ' (source TEXT NOT NULL, entity TEXT NOT NULL, source_id TEXT NOT NULL, target_id INTEGER NOT NULL, created_at TEXT NOT NULL, PRIMARY KEY (source, entity, source_id))');
    $db->executeStatement('CREATE TABLE ' . Run::WARNINGS . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT NOT NULL, step TEXT NOT NULL, message TEXT NOT NULL, created_at TEXT NOT NULL)');

    return $db;
}

/**
 * The CSV a writer made, as rows.
 *
 * @return list<list<string>>
 */
function whmcsCsvRows(callable $write): array
{
    $out = fopen('php://memory', 'w+');
    $write($out);
    rewind($out);
    $rows = [];
    while (($row = fgetcsv($out, null, ',', '"', '')) !== false) {
        $rows[] = array_map('strval', $row);
    }
    fclose($out);

    return $rows;
}

/** @return array<string, mixed> a saved plan as Import::plan() stores it */
function whmcsCsvPlan(): array
{
    return [
        'version' => '8.10.1',
        'areas' => [
            'clients' => ['group' => 'clients', 'label' => 'Clients', 'table' => 'tblclients', 'rows' => 12, 'by_status' => ['Active' => 10, 'Closed' => 2]],
            'users' => ['group' => 'clients', 'label' => 'Logins (users)', 'table' => 'tblusers', 'rows' => 9, 'by_status' => []],
            'cancel_requests' => ['group' => 'services', 'label' => 'Cancellation requests', 'table' => 'tblcancelrequests', 'rows' => 2, 'by_status' => ['Immediate' => 2]],
            'orders' => ['group' => 'services', 'label' => 'Orders', 'table' => 'tblorders', 'rows' => 6, 'by_status' => ['Active' => 6]],
            'quotes' => ['group' => 'billing', 'label' => 'Quotes', 'table' => 'tblquotes', 'rows' => 3, 'by_status' => []],
        ],
        'modules' => [
            ['module' => 'licensing', 'label' => 'WHMCS Licensing (licence keys, check log and bans)', 'handling' => 'import', 'active' => true, 'tables' => [['table' => 'mod_licensing', 'rows' => 5]], 'rows' => 5],
            ['module' => 'project', 'label' => '=HYPERLINK("http://evil.test","x")', 'handling' => 'archive', 'active' => true, 'tables' => [['table' => 'mod_project', 'rows' => 7]], 'rows' => 7],
            ['module' => 'hello', 'label' => 'Hello', 'handling' => 'settings', 'active' => true, 'tables' => [], 'rows' => 0],
        ],
        'preflight' => [
            ['check' => 'PHP memory limit', 'ok' => true, 'detail' => '512 MB (the website\'s PHP).', 'fix' => ''],
            ['check' => 'WHMCS currency codes', 'ok' => false, 'detail' => 'Not a three-letter code: #2 "€".', 'fix' => 'In WHMCS (System Settings > Currencies), give each its ISO code.'],
        ],
        'warnings' => [],
        'made_at' => '2026-11-19 10:00:00',
    ];
}

test('the plan CSV has a row per area and add-on module, then the server checks', function (): void {
    $rows = whmcsCsvRows(fn ($out) => Csv::plan(whmcsCsvPlan(), 'WHMCS', $out));

    expect($rows[0])->toBe(['Section', 'Source system', 'Area', 'Handling', 'Found in the source', 'Will be imported', 'Archived only', 'Settings only', 'Detail'])
        ->and($rows[1])->toBe(['Area', 'WHMCS', 'Clients', 'Imported', '12', '12', '0', '0', 'Active 10, Closed 2'])
        // Areas read only in part (Csv::PARTLY) make no count of what is taken, and say which part.
        ->and($rows[2])->toBe(['Area', 'WHMCS', 'Logins (users)', 'Partly imported', '9', '', '0', '0', 'Only logins linked to a client.'])
        ->and($rows[3])->toBe(['Area', 'WHMCS', 'Cancellation requests', 'Partly imported', '2', '', '0', '0', 'Only the newest request of each service. Immediate 2'])
        // The areas the import does not read (Csv::NOT_IMPORTED) say so.
        ->and($rows[4])->toBe(['Area', 'WHMCS', 'Orders', 'Not imported', '6', '0', '0', '0', 'Its services and domains become ClientFence orders. Active 6'])
        ->and($rows[5])->toBe(['Area', 'WHMCS', 'Quotes', 'Not imported', '3', '0', '0', '0', ''])
        ->and($rows[6])->toBe(['Add-on module', 'WHMCS', 'WHMCS Licensing (licence keys, check log and bans)', 'Imported', '5', '5', '0', '0', 'mod_licensing (5)'])
        ->and($rows[7])->toBe(['Add-on module', 'WHMCS', '\'=HYPERLINK("http://evil.test","x")', 'Archived, not imported', '7', '0', '7', '0', 'mod_project (7)'])
        ->and($rows[8])->toBe(['Add-on module', 'WHMCS', 'Hello', 'Settings only', '0', '0', '0', '0', ''])
        ->and($rows[9])->toBe([''])
        ->and($rows[10])->toBe(['Section', 'Check', 'Result', 'Detail', 'Fix'])
        ->and($rows[11])->toBe(['Server check', 'PHP memory limit', 'Pass', '512 MB (the website\'s PHP).', ''])
        ->and($rows[12])->toBe(['Server check', 'WHMCS currency codes', 'Fail', 'Not a three-letter code: #2 "€".', 'In WHMCS (System Settings > Currencies), give each its ISO code.'])
        ->and($rows)->toHaveCount(13)
        // Every key in both lists is a plan area, and no area is in both.
        ->and(array_diff([...Csv::NOT_IMPORTED, ...Csv::PARTLY], array_keys(ClientFence\Mod\Clientfence\Whmcs\Inventory::AREAS)))->toBe([])
        ->and(array_intersect(Csv::NOT_IMPORTED, Csv::PARTLY))->toBe([])
        ->and(Csv::name('plan', 'ClientExec'))->toBe('clientfence-import-plan-clientexec-' . date('Y-m-d') . '.csv');
});

test('the audit CSV has the check against the source, every warning and every id map row', function (): void {
    $db = whmcsCsvDb();
    Run::addWarnings($db, 'clients', ['Client #4 has no owner login in WHMCS; it signs in with a@example.com after "Forgot password".']);
    Run::addWarnings($db, 'nosuchstep', ['A warning of a step this build does not have.', 'Add-on "a\\"b" was kept.', 'Client #2 had 2 PayPal billing agreements in WHMCS: the newest, B-1AB23456CD789012E, was kept.']);
    $map = new Map($db);
    $map->put('client', 4, 4);
    $map->put('skipped', 'invoice:9', 0);
    // The import's bookkeeping is left out.
    $map->put('archive', '500', 2048);
    $map->put('notice', 'unsent-emails', 0);
    $verify = ['rows' => [
        ['step' => 'clients', 'label' => 'Clients and their owner logins', 'whmcs' => 5, 'imported' => 4],
        ['step' => 'twofactor', 'label' => 'Two-factor sign-in carried over', 'whmcs' => 0, 'imported' => 0],
    ], 'missing_clients' => 0, 'warnings' => 2];

    $rows = whmcsCsvRows(fn ($out) => Csv::audit($db, 'Blesta', $verify, $out));

    expect($rows)->toBe([
        ['Section', 'Area', 'In the source', 'Imported', 'Difference'],
        // A negative difference stays a number (no apostrophe).
        ['Check against the source', 'Clients and their owner logins', '5', '4', '-1'],
        ['Check against the source', 'Two-factor sign-in carried over', '0', '0', '0'],
        [''],
        ['Section', 'Step', 'Warning'],
        ['Warning', 'Clients and their owner logins', 'Client #4 has no owner login in WHMCS; it signs in with a@example.com after "Forgot password".'],
        ['Warning', 'nosuchstep', 'A warning of a step this build does not have.'],
        // A backslash before a quote does not end the field (RFC 4180 quoting).
        ['Warning', 'nosuchstep', 'Add-on "a\\"b" was kept.'],
        // A full agreement ID from an earlier import keeps only its end.
        ['Warning', 'nosuchstep', 'Client #2 had 2 PayPal billing agreements in WHMCS: the newest, …012E, was kept.'],
        [''],
        ['Section', 'Source system', 'Entity', 'Source ID', 'ClientFence ID', 'Result'],
        ['Record', 'Blesta', 'client', '4', '4', 'Imported'],
        ['Record', 'Blesta', 'skipped', 'invoice:9', '0', 'Not imported'],
    ]);
});

test('a source that cannot be read leaves the check out with a note, and the rest is still written', function (): void {
    $db = whmcsCsvDb();
    Run::addWarnings($db, 'clients', ['One warning.']);

    $rows = whmcsCsvRows(fn ($out) => Csv::audit($db, 'WHMCS', null, $out));

    expect($rows[1])->toBe(['Check against the source', 'The source could not be read, so this check is left out.'])
        ->and($rows[4])->toBe(['Warning', 'Clients and their owner logins', 'One warning.']);
});

test('values a spreadsheet would run as a formula start with an apostrophe; numbers do not', function (): void {
    $db = whmcsCsvDb();
    $evil = ['=1+1', '+SUM(A1)', '-2+3', '@cmd', "\tTAB", "\rCR"];
    Run::addWarnings($db, 'clients', $evil);
    $map = new Map($db);
    $map->put('client', '=CMD()', 7);
    $map->put('client', '-5', 8);

    $rows = whmcsCsvRows(fn ($out) => Csv::audit($db, 'WHMCS', ['rows' => [['step' => 'clients', 'label' => '@label', 'whmcs' => 1, 'imported' => 3]]], $out));
    $warnings = array_values(array_filter($rows, static fn (array $r): bool => $r[0] === 'Warning'));
    $records = array_values(array_filter($rows, static fn (array $r): bool => $r[0] === 'Record'));

    expect(array_column($warnings, 2))->toBe(array_map(static fn (string $v): string => "'" . $v, $evil))
        ->and($rows[1])->toBe(['Check against the source', "'@label", '1', '3', '2'])
        // A numeric source id is a number, not a formula.
        ->and(array_column($records, 3))->toBe(['-5', "'=CMD()"]);
});

test('no import secret reaches either file', function (): void {
    $db = whmcsCsvDb();
    $secrets = ['host' => 'db.secret-host.test', 'username' => 'whmcs_dbuser', 'password' => 'S3cret-Passw0rd', 'encryption_key' => 'cc-hash-123456', 'upmind_token' => 'upm-token-xyz', 'blesta_key' => 'blesta-key-789'];
    $db->insert('mod_clientfence_state', ['name' => Import::SETTINGS, 'value' => json_encode($secrets), 'updated_at' => '2026-11-19 00:00:00']);
    $db->insert('mod_clientfence_state', ['name' => Import::PLAN, 'value' => json_encode(whmcsCsvPlan()), 'updated_at' => '2026-11-19 00:00:00']);
    Run::addWarnings($db, 'servers', ['Hosting server "web1": its password could not be read (no WHMCS encryption key); enter it on the server page.']);
    (new Map($db))->put('server', 1, 1);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di = new Pimple\Container();
    $di['em'] = $em;

    $text = json_encode(whmcsCsvRows(fn ($out) => Csv::plan(Import::state($di, Import::PLAN), 'WHMCS', $out)))
        . json_encode(whmcsCsvRows(fn ($out) => Csv::audit($db, 'WHMCS', null, $out)));

    foreach ($secrets as $secret) {
        expect($text)->not->toContain($secret);
    }
});

test('the id map and the warnings are read in chunks, every row once, across entities', function (): void {
    $db = whmcsCsvDb();
    $map = new Map($db);
    $expected = [];
    foreach (['client' => 9, 'contact' => 4, 'invoice' => 8] as $entity => $count) {
        for ($i = 1; $i <= $count; ++$i) {
            $map->put($entity, $i, $i + 100);
            $expected[] = $entity . ':' . $i;
        }
    }
    Run::addWarnings($db, 'clients', array_map(static fn (int $i): string => 'Warning ' . $i, range(1, 10)));
    // Other systems' rows are never written.
    $db->insert(Map::TABLE, ['source' => 'other', 'entity' => 'client', 'source_id' => '1', 'target_id' => 1, 'created_at' => '2026-11-19 00:00:00']);

    // 21 rows in chunks of 3 (an exact multiple) and of 4.
    foreach ([3, 4] as $chunk) {
        $rows = whmcsCsvRows(fn ($out) => Csv::audit($db, 'WHMCS', ['rows' => []], $out, $chunk));
        $records = array_values(array_filter($rows, static fn (array $r): bool => $r[0] === 'Record'));
        $warnings = array_values(array_filter($rows, static fn (array $r): bool => $r[0] === 'Warning'));
        $got = array_map(static fn (array $r): string => $r[2] . ':' . $r[3], $records);
        sort($got);
        $want = $expected;
        sort($want);

        expect($got)->toBe($want)
            ->and(array_column($warnings, 2))->toBe(array_map(static fn (int $i): string => 'Warning ' . $i, range(1, 10)));
    }
});

test('only a Super Administrator with the page\'s CSRF token gets the downloads', function (): void {
    $db = whmcsCsvDb();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $staff = new class {
        public function isSuperAdministrator(int $id): bool
        {
            return $id === 1;
        }
    };
    $session = new class {
        public function get(string $key): ?string
        {
            return $key === 'csrf_token' ? 'token-1' : null;
        }
    };
    $controller = function (int $adminId, string $token) use ($em, $staff, $session): ClientFence\Mod\Clientfence\Controller\Admin {
        $di = new Pimple\Container();
        $di['em'] = $em;
        $di['is_admin_logged'] = true;
        $di['loggedin_admin'] = (object) ['id' => $adminId];
        $di['mod_service'] = $di->protect(fn (string $name) => $staff);
        $di['session'] = $session;
        $di['request'] = Request::create('/clientfence/import/plan-csv', 'GET', ['CSRFToken' => $token]);
        $controller = new ClientFence\Mod\Clientfence\Controller\Admin();
        $controller->setDi($di);

        return $controller;
    };
    $app = Mockery::mock(ClientFence\App::class);

    foreach (['get_import_plan_csv', 'get_import_audit_csv'] as $download) {
        // Other staff are refused before anything is read or sent.
        expect(fn () => $controller(2, 'token-1')->{$download}($app))->toThrow(InformationException::class, 'Only Super Administrators can import from WHMCS.')
            ->and(fn () => $controller(1, 'wrong')->{$download}($app))->toThrow(InformationException::class, 'CSRF token invalid');
    }
    // A Super Administrator gets past both checks (to "nothing to download yet" here).
    expect(fn () => $controller(1, 'token-1')->get_import_plan_csv($app))->toThrow(InformationException::class, 'Make the plan first.')
        ->and(fn () => $controller(1, 'token-1')->get_import_audit_csv($app))->toThrow(InformationException::class, 'The import has not finished yet.');
});
