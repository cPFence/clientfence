<?php

declare(strict_types=1);

/**
 * ClientFence: the WHMCS import plan (Whmcs\Inventory) on a small invented WHMCS database in
 * SQLite: WHMCS's column names only, no WHMCS code or data.
 */

use ClientFence\Mod\Clientfence\Whmcs\Import;
use ClientFence\Mod\Clientfence\Whmcs\Inventory;
use ClientFence\Mod\Clientfence\Whmcs\Source;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

function whmcsFixture(string $version = '9.0.4-release.1'): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
        'CREATE TABLE tblconfiguration (setting TEXT, value TEXT)',
        'CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)',
        'CREATE TABLE tblclients (id INTEGER PRIMARY KEY, status TEXT, currency INTEGER, credit REAL)',
        'CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, total REAL, status TEXT, paymentmethod TEXT)',
        'CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, type TEXT, servertype TEXT)',
        'CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, packageid INTEGER, domainstatus TEXT, paymentmethod TEXT)',
        'CREATE TABLE tbltickets (id INTEGER PRIMARY KEY, status TEXT)',
        'CREATE TABLE tbladdonmodules (module TEXT, setting TEXT, value TEXT)',
        'CREATE TABLE tblpaymentgateways (gateway TEXT, setting TEXT, value TEXT)',
        'CREATE TABLE mod_licensing (id INTEGER PRIMARY KEY)',
        'CREATE TABLE mod_licensinglog (id INTEGER PRIMARY KEY)',
        'CREATE TABLE WordpressManager_Jobs (id INTEGER PRIMARY KEY)',
        'CREATE TABLE WordpressManager_Logger (id INTEGER PRIMARY KEY)',
        'CREATE TABLE rsthemes_html_cache (id INTEGER PRIMARY KEY)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    $db->insert('tblconfiguration', ['setting' => 'Version', 'value' => $version]);
    $db->insert('tblcurrencies', ['id' => 1, 'code' => 'USD']);
    $db->insert('tblcurrencies', ['id' => 2, 'code' => 'EGP']);
    foreach ([[1, 'Active', 1, 10.5], [2, 'Active', 1, 0], [3, 'Inactive', 2, 100], [4, 'Closed', 1, 0]] as [$id, $status, $currency, $credit]) {
        $db->insert('tblclients', ['id' => $id, 'status' => $status, 'currency' => $currency, 'credit' => $credit]);
    }
    foreach ([[1, 20, 'Paid', 'paypal'], [1, 5.25, 'Unpaid', 'paypal'], [2, 30, 'Paid', 'banktransfer'], [3, 900, 'Unpaid', 'banktransfer'], [4, 7, 'Cancelled', 'paypal']] as [$client, $total, $status, $gateway]) {
        $db->insert('tblinvoices', ['userid' => $client, 'total' => $total, 'status' => $status, 'paymentmethod' => $gateway]);
    }
    $db->insert('tblproducts', ['id' => 1, 'type' => 'other', 'servertype' => 'licensing']);
    $db->insert('tblproducts', ['id' => 2, 'type' => 'hostingaccount', 'servertype' => 'cpanel']);
    $db->insert('tblproducts', ['id' => 3, 'type' => 'other', 'servertype' => '']);
    foreach ([[1, 'Active', 'paypal'], [1, 'Active', 'paypal'], [1, 'Suspended', 'banktransfer'], [2, 'Cancelled', 'paypal']] as [$product, $status, $gateway]) {
        $db->insert('tblhosting', ['packageid' => $product, 'domainstatus' => $status, 'paymentmethod' => $gateway]);
    }
    $db->insert('tbltickets', ['status' => 'Closed']);
    $db->insert('tbladdonmodules', ['module' => 'licensing', 'setting' => 'version', 'value' => '3.2']);
    $db->insert('tbladdonmodules', ['module' => 'WordpressManager', 'setting' => 'version', 'value' => '1.0']);
    $db->insert('tbladdonmodules', ['module' => 'project_management', 'setting' => 'version', 'value' => '2.0']);
    $db->insert('tbladdonmodules', ['module' => 'old_thing', 'setting' => 'access', 'value' => '1']);
    foreach (['paypal' => 'PayPal', 'banktransfer' => 'Bank transfer', 'unused' => 'Unused'] as $gateway => $name) {
        $db->insert('tblpaymentgateways', ['gateway' => $gateway, 'setting' => 'name', 'value' => $name]);
    }
    foreach (range(1, 3) as $id) {
        $db->insert('mod_licensing', ['id' => $id]);
    }
    $db->insert('mod_licensinglog', ['id' => 1]);
    $db->insert('WordpressManager_Jobs', ['id' => 1]);

    return $db;
}

test('the plan counts each area with its statuses, and the money per currency', function (): void {
    $plan = Inventory::plan(new Source(whmcsFixture()));

    expect($plan)->toMatchArray(['version' => '9.0.4-release.1', 'major' => 9, 'supported' => true, 'warnings' => []])
        ->and($plan['areas']['clients'])->toMatchArray(['group' => 'clients', 'rows' => 4, 'by_status' => ['Active' => 2, 'Closed' => 1, 'Inactive' => 1]])
        ->and($plan['areas']['services']['by_status'])->toBe(['Active' => 2, 'Cancelled' => 1, 'Suspended' => 1])
        // Tables this WHMCS lacks count 0.
        ->and($plan['areas']['domains'])->toMatchArray(['rows' => 0, 'by_status' => []])
        ->and($plan['money'])->toBe([
            ['currency' => 'USD', 'unpaid_invoices' => 5.25, 'client_credit' => 10.5, 'paid_invoices' => 50.0],
            ['currency' => 'EGP', 'unpaid_invoices' => 900.0, 'client_credit' => 100.0, 'paid_invoices' => 0.0],
        ]);
});

test('add-on modules: mapped ones are imported, the others archived with their tables', function (): void {
    $modules = array_column(Inventory::plan(new Source(whmcsFixture()))['modules'], null, 'module');

    expect($modules['licensing'])->toMatchArray(['handling' => 'import', 'active' => true, 'rows' => 4, 'tables' => [['table' => 'mod_licensing', 'rows' => 3], ['table' => 'mod_licensinglog', 'rows' => 1]]])
        ->and($modules['wordpressmanager'])->toMatchArray(['label' => 'WordpressManager', 'handling' => 'archive', 'active' => true, 'rows' => 1])
        ->and(array_column($modules['wordpressmanager']['tables'], 'table'))->toBe(['WordpressManager_Jobs', 'WordpressManager_Logger'])
        // Switched on without tables: its settings are all it has.
        ->and($modules['project_management'])->toMatchArray(['handling' => 'settings', 'active' => true, 'tables' => []])
        // Caches are not data.
        ->and(isset($modules['rsthemes']))->toBeFalse()
        ->and(array_key_first($modules))->toBe('licensing');
});

test('gateways and server modules are listed with what uses them', function (): void {
    $plan = Inventory::plan(new Source(whmcsFixture()));

    expect($plan['gateways'])->toBe([
        ['gateway' => 'paypal', 'label' => 'PayPal', 'services' => 3, 'invoices' => 3],
        ['gateway' => 'banktransfer', 'label' => 'Bank transfer', 'services' => 1, 'invoices' => 2],
        ['gateway' => 'unused', 'label' => 'Unused', 'services' => 0, 'invoices' => 0],
    ])->and($plan['server_modules'])->toBe([
        ['module' => 'licensing', 'products' => 1, 'services' => 3],
        ['module' => 'cpanel', 'products' => 1, 'services' => 1],
    ]);
});

test('old WHMCS versions are warned about or refused', function (string $version, bool $supported, string $warning): void {
    $plan = Inventory::plan(new Source(whmcsFixture($version)));

    expect($plan['supported'])->toBe($supported)
        ->and($plan['warnings'])->toBe($warning === '' ? [] : [$warning]);
})->with([
    '8.x' => ['8.13.1-release.1', true, ''],
    '7.x best effort' => ['7.10.2-release.1', false, 'WHMCS 7.10.2-release.1 imports on a best effort basis: check the result carefully, or update WHMCS to 8 or later first.'],
    '6.x too old' => ['6.3.2-release.1', false, 'WHMCS 6.3.2-release.1 is too old to import: update it to 8 or later first.'],
]);

test('a database without WHMCS tables is not WHMCS', function (): void {
    $source = new Source(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));

    expect($source->isWhmcs())->toBeFalse()
        ->and($source->version())->toBe('')
        ->and(Inventory::plan($source)['warnings'])->toBe(['The WHMCS version is unknown.']);
});

test('an import needs a fresh install', function (): void {
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach (['client', 'client_order', 'invoice', 'support_ticket'] as $table) {
        $db->executeStatement("CREATE TABLE {$table} (id INTEGER PRIMARY KEY)");
    }
    $di = new Pimple\Container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;

    expect(Import::target($di))->toBe(['fresh' => true, 'counts' => ['clients' => 0, 'orders' => 0, 'invoices' => 0, 'tickets' => 0]]);
    $db->insert('invoice', ['id' => 1]);
    expect(Import::target($di))->toBe(['fresh' => false, 'counts' => ['clients' => 0, 'orders' => 0, 'invoices' => 1, 'tickets' => 0]]);
});

test('settings for the page never carry the password or the encryption key', function (): void {
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE mod_clientfence_state (name TEXT PRIMARY KEY, value TEXT, updated_at TEXT)');
    $db->insert('mod_clientfence_state', ['name' => Import::SETTINGS, 'value' => json_encode(['host' => 'db.example', 'username' => 'reader', 'password' => 'ENCRYPTED', 'encryption_key' => 'ENCRYPTED-TOO']), 'updated_at' => '2026-10-18']);
    foreach (['client', 'client_order', 'invoice', 'support_ticket'] as $table) {
        $db->executeStatement("CREATE TABLE {$table} (id INTEGER PRIMARY KEY)");
    }
    $di = new Pimple\Container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;

    $page = Import::forStaff($di);
    expect($page['settings'])->toMatchArray(['host' => 'db.example', 'port' => 3306, 'username' => 'reader', 'has_password' => true, 'has_encryption_key' => true])
        ->and($page['settings'])->not->toHaveKey('password')
        ->and($page['settings'])->not->toHaveKey('encryption_key')
        ->and(json_encode($page))->not->toContain('ENCRYPTED');
});
