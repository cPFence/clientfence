<?php

declare(strict_types=1);

/**
 * ClientFence: the WHMCS import's catalogue and service steps (Whmcs\Catalogue, Whmcs\Services)
 * from a small invented WHMCS database into a ClientFence database, both SQLite: WHMCS's column
 * names only, no WHMCS code or data. Currencies USD (default) and EGP (rate 50).
 */

use ClientFence\Mod\Clientfence\Whmcs\Catalogue;
use ClientFence\Mod\Clientfence\Whmcs\Cipher;
use ClientFence\Mod\Clientfence\Whmcs\Map;
use ClientFence\Mod\Clientfence\Whmcs\Money;
use ClientFence\Mod\Clientfence\Whmcs\Services;
use ClientFence\Mod\Clientfence\Whmcs\Source;
use ClientFence\Mod\Product\CurrencyPrices;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

const WHMCS_CATALOGUE_KEY = 'catalogue-test-key';

/** A tblpricing row: cycles (monthly…triennially) and setup fees; -1 where not offered. */
function whmcsPricing(Connection $db, string $type, int $relid, int $currency, array $values): void
{
    $row = ['type' => $type, 'relid' => $relid, 'currency' => $currency];
    foreach (['msetupfee', 'qsetupfee', 'ssetupfee', 'asetupfee', 'bsetupfee', 'tsetupfee'] as $c) {
        // Domain rows hold a price per year in every column (-1: not offered).
        $row[$c] = $values[$c] ?? (str_starts_with($type, 'domain') ? -1 : 0);
    }
    foreach (['monthly', 'quarterly', 'semiannually', 'annually', 'biennially', 'triennially'] as $c) {
        $row[$c] = $values[$c] ?? -1;
    }
    $db->insert('tblpricing', $row);
}

function whmcsCatalogueSource(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
        'CREATE TABLE tblconfiguration (setting TEXT, value TEXT)',
        'CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT, rate REAL, "default" INTEGER)',
        'CREATE TABLE tblproductgroups (id INTEGER PRIMARY KEY, name TEXT, slug TEXT, headline TEXT, tagline TEXT, hidden INTEGER DEFAULT 0, "order" INTEGER DEFAULT 0)',
        "CREATE TABLE tblservers (id INTEGER PRIMARY KEY, name TEXT, ipaddress TEXT, assignedips TEXT, hostname TEXT, statusaddress TEXT, nameserver1 TEXT, nameserver2 TEXT, nameserver3 TEXT, nameserver4 TEXT, maxaccounts INTEGER, type TEXT, username TEXT, password TEXT, accesshash TEXT, secure TEXT, port TEXT, active INTEGER, disabled INTEGER DEFAULT 0)",
        'CREATE TABLE tblservergroups (id INTEGER PRIMARY KEY, name TEXT, filltype INTEGER)',
        'CREATE TABLE tblservergroupsrel (id INTEGER PRIMARY KEY, groupid INTEGER, serverid INTEGER)',
        "CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, type TEXT, gid INTEGER, name TEXT, slug TEXT DEFAULT '', description TEXT, hidden INTEGER DEFAULT 0, stockcontrol INTEGER DEFAULT 0, qty INTEGER DEFAULT 0, proratabilling INTEGER DEFAULT 0, paytype TEXT, allowqty INTEGER DEFAULT 0, autosetup TEXT DEFAULT '', servertype TEXT DEFAULT '', servergroup INTEGER DEFAULT 0, configoption1 TEXT DEFAULT '', freedomain TEXT DEFAULT '', freedomainpaymentterms TEXT DEFAULT '', freedomaintlds TEXT DEFAULT '', recurringcycles INTEGER DEFAULT 0, autoterminatedays INTEGER DEFAULT 0, overagesenabled INTEGER DEFAULT 0, \"order\" INTEGER DEFAULT 0, retired INTEGER DEFAULT 0)",
        'CREATE TABLE tblpricing (id INTEGER PRIMARY KEY, type TEXT, currency INTEGER, relid INTEGER, msetupfee REAL, qsetupfee REAL, ssetupfee REAL, asetupfee REAL, bsetupfee REAL, tsetupfee REAL, monthly REAL, quarterly REAL, semiannually REAL, annually REAL, biennially REAL, triennially REAL)',
        "CREATE TABLE tblcustomfields (id INTEGER PRIMARY KEY, type TEXT, relid INTEGER, fieldname TEXT, fieldtype TEXT, description TEXT DEFAULT '', fieldoptions TEXT DEFAULT '', adminonly TEXT DEFAULT '', required TEXT DEFAULT '', showorder TEXT DEFAULT 'on', sortorder INTEGER DEFAULT 0)",
        "CREATE TABLE tbladdons (id INTEGER PRIMARY KEY, packages TEXT, name TEXT, description TEXT, billingcycle TEXT, allowqty INTEGER DEFAULT 0, showorder INTEGER DEFAULT 1, hidden INTEGER DEFAULT 0, retired INTEGER DEFAULT 0, autoactivate TEXT DEFAULT '', module TEXT DEFAULT '', weight INTEGER DEFAULT 0)",
        'CREATE TABLE tblproduct_upgrade_products (id INTEGER PRIMARY KEY, product_id INTEGER, upgrade_product_id INTEGER)',
        "CREATE TABLE tblpromotions (id INTEGER PRIMARY KEY, code TEXT, type TEXT, recurring INTEGER DEFAULT 0, value REAL, cycles TEXT DEFAULT '', appliesto TEXT DEFAULT '', requires TEXT DEFAULT '', requiresexisting INTEGER DEFAULT 0, startdate TEXT, expirationdate TEXT, maxuses INTEGER DEFAULT 0, uses INTEGER DEFAULT 0, applyonce INTEGER DEFAULT 0, newsignups INTEGER DEFAULT 0, existingclient INTEGER DEFAULT 0, onceperclient INTEGER DEFAULT 0, recurfor INTEGER DEFAULT 0, upgrades INTEGER DEFAULT 0, notes TEXT DEFAULT '')",
        "CREATE TABLE tbldomainpricing (id INTEGER PRIMARY KEY, extension TEXT, dnsmanagement INTEGER DEFAULT 0, emailforwarding INTEGER DEFAULT 0, idprotection INTEGER DEFAULT 0, eppcode INTEGER DEFAULT 0, autoreg TEXT DEFAULT '', \"order\" INTEGER DEFAULT 0)",
        'CREATE TABLE tblbundles (id INTEGER PRIMARY KEY, name TEXT, itemdata TEXT)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    $db->insert('tblconfiguration', ['setting' => 'Version', 'value' => '9.0.4-release.1']);
    $db->executeStatement('INSERT INTO tblcurrencies VALUES (1, \'USD\', 1, 1), (2, \'EGP\', 50, 0)');
    $db->insert('tblproductgroups', ['id' => 1, 'name' => 'Hosting', 'headline' => 'Fast hosting', 'tagline' => 'From $10']);
    $db->insert('tblproductgroups', ['id' => 2, 'name' => 'Secret deals', 'hidden' => 1]);

    $cipher = Cipher::withKey(WHMCS_CATALOGUE_KEY);
    $db->insert('tblservers', ['id' => 1, 'name' => 'cp1', 'ipaddress' => '203.0.113.5', 'assignedips' => "203.0.113.6\nnot-an-ip", 'hostname' => 'cp1.host.test', 'nameserver1' => 'ns1.host.test', 'type' => 'cpanel', 'username' => 'root', 'password' => $cipher->encrypt('server-pass', 'iv-of-the-server-01'), 'accesshash' => 'whm-token', 'secure' => 'on', 'port' => '', 'active' => 1, 'maxaccounts' => 200]);
    $db->insert('tblservers', ['id' => 2, 'name' => 'odd', 'ipaddress' => '203.0.113.9', 'type' => 'virtualmin', 'username' => 'x', 'password' => '', 'accesshash' => '', 'secure' => '', 'port' => '', 'active' => 1]);
    $db->insert('tblservergroups', ['id' => 1, 'name' => 'cPanel', 'filltype' => 1]);
    $db->insert('tblservergroupsrel', ['groupid' => 1, 'serverid' => 1]);

    $db->insert('tblproducts', ['id' => 1, 'type' => 'hostingaccount', 'gid' => 1, 'name' => 'Starter', 'slug' => 'starter', 'description' => 'Small sites', 'paytype' => 'recurring', 'autosetup' => 'payment', 'servertype' => 'cpanel', 'servergroup' => 1, 'configoption1' => 'starter_pkg', 'freedomain' => 'once', 'freedomainpaymentterms' => 'annually', 'freedomaintlds' => '.com,net', '"order"' => 2]);
    whmcsPricing($db, 'product', 1, 1, ['monthly' => 10, 'msetupfee' => 5, 'annually' => 100]);
    whmcsPricing($db, 'product', 1, 2, ['monthly' => 500, 'msetupfee' => 250, 'annually' => 5000]);
    $db->insert('tblproducts', ['id' => 2, 'type' => 'other', 'gid' => 2, 'name' => 'App licence', 'paytype' => 'recurring', 'autosetup' => 'order', 'servertype' => 'licensing']);
    whmcsPricing($db, 'product', 2, 1, ['monthly' => 5]);
    $db->insert('tblproducts', ['id' => 3, 'type' => 'other', 'gid' => 1, 'name' => 'Setup service', 'paytype' => 'onetime', 'servertype' => 'mymodule', 'proratabilling' => 1, 'retired' => 1]);
    whmcsPricing($db, 'product', 3, 1, ['monthly' => 99, 'msetupfee' => 1]);
    $db->insert('tblproducts', ['id' => 4, 'type' => 'other', 'gid' => 1, 'name' => 'Starter', 'paytype' => 'free']);
    $db->insert('tblcustomfields', ['id' => 7, 'type' => 'product', 'relid' => 1, 'fieldname' => 'Plan size', 'fieldtype' => 'dropdown', 'fieldoptions' => 'S|Small,M|Medium', 'required' => 'on', 'sortorder' => 1]);
    $db->insert('tblcustomfields', ['id' => 8, 'type' => 'product', 'relid' => 1, 'fieldname' => 'Notes for staff', 'fieldtype' => 'textarea', 'adminonly' => 'on', 'sortorder' => 2]);
    $db->insert('tblcustomfields', ['id' => 9, 'type' => 'client', 'relid' => 0, 'fieldname' => 'Not a product field', 'fieldtype' => 'text']);

    $db->insert('tbladdons', ['id' => 1, 'packages' => '1,2,99', 'name' => 'Backups', 'description' => 'Daily', 'billingcycle' => 'recurring', 'autoactivate' => 'payment']);
    whmcsPricing($db, 'addon', 1, 1, ['monthly' => 2]);
    whmcsPricing($db, 'addon', 1, 2, ['monthly' => 90]);
    $db->insert('tbladdons', ['id' => 2, 'packages' => '1', 'name' => 'SSL', 'billingcycle' => 'Annually', 'module' => 'marketconnect']);
    whmcsPricing($db, 'addon', 2, 1, ['monthly' => 3, 'annually' => 30]);

    $db->insert('tblproduct_upgrade_products', ['id' => 1, 'product_id' => 1, 'upgrade_product_id' => 3]);
    $db->insert('tblproduct_upgrade_products', ['id' => 2, 'product_id' => 1, 'upgrade_product_id' => 98]);

    $db->insert('tblpromotions', ['id' => 1, 'code' => 'TEN', 'type' => 'Percentage', 'value' => 10, 'recurring' => 1, 'recurfor' => 3, 'cycles' => 'Monthly,Annually,1Years', 'appliesto' => '1,A1,D.com', 'startdate' => '2026-01-01', 'expirationdate' => '2026-12-31', 'maxuses' => 50, 'uses' => 4, 'onceperclient' => 1, 'notes' => 'Launch']);
    $db->insert('tblpromotions', ['id' => 2, 'code' => 'FIVE', 'type' => 'Fixed Amount', 'value' => 5, 'startdate' => '0000-00-00', 'expirationdate' => '0000-00-00']);
    $db->insert('tblpromotions', ['id' => 3, 'code' => 'NOSETUP', 'type' => 'Free Setup', 'value' => 0, 'newsignups' => 1]);
    $db->insert('tblpromotions', ['id' => 4, 'code' => 'FIXED9', 'type' => 'Price Override', 'value' => 9]);
    $db->insert('tblpromotions', ['id' => 5, 'code' => 'DOMAIN50', 'type' => 'Percentage', 'value' => 50, 'appliesto' => 'D.com']);

    $db->insert('tbldomainpricing', ['id' => 1, 'extension' => '.com', 'autoreg' => 'resellerclub', 'eppcode' => 1]);
    whmcsPricing($db, 'domainregister', 1, 1, ['msetupfee' => 19, 'qsetupfee' => 38, 'ssetupfee' => 50]);
    whmcsPricing($db, 'domainrenew', 1, 1, ['msetupfee' => 19, 'qsetupfee' => 38]);
    whmcsPricing($db, 'domaintransfer', 1, 1, ['msetupfee' => 15]);
    whmcsPricing($db, 'domainregister', 1, 2, ['msetupfee' => 950, 'qsetupfee' => 1900]);
    whmcsPricing($db, 'domainrenew', 1, 2, ['msetupfee' => 1000]);
    $db->insert('tbldomainpricing', ['id' => 2, 'extension' => 'net', 'autoreg' => 'enom']);
    whmcsPricing($db, 'domainregister', 2, 1, ['msetupfee' => -1, 'qsetupfee' => 30]);
    whmcsPricing($db, 'domainrenew', 2, 1, ['msetupfee' => 14]);
    whmcsPricing($db, 'domaintransfer', 2, 1, ['msetupfee' => -1]);
    $db->insert('tbldomainpricing', ['id' => 3, 'extension' => '.org']);

    $db->insert('tblbundles', ['id' => 1, 'name' => 'Starter pack', 'itemdata' => '']);

    return $db;
}

function whmcsCatalogueTarget(): Connection
{
    CurrencyPrices::reset();
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $payment = implode(', ', array_map(fn (string $k): string => "{$k}_price REAL DEFAULT 0, {$k}_setup_price REAL DEFAULT 0, {$k}_enabled INTEGER DEFAULT 1", ['w', 'm', 'q', 'b', 'a', 'bia', 'tria']));
    foreach ([
        'CREATE TABLE currency (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT UNIQUE, is_default INTEGER DEFAULT 0, conversion_rate REAL DEFAULT 1)',
        'CREATE TABLE product_category (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, description TEXT, icon_url TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE product (id INTEGER PRIMARY KEY AUTOINCREMENT, product_category_id INTEGER, product_payment_id INTEGER, form_id INTEGER, title TEXT, slug TEXT UNIQUE, description TEXT, unit TEXT, active INTEGER, status TEXT, hidden INTEGER, is_addon INTEGER, setup TEXT, addons TEXT, icon_url TEXT, allow_quantity_select INTEGER, stock_control INTEGER, quantity_in_stock INTEGER, plugin TEXT, plugin_config TEXT, upgrades TEXT, priority INTEGER, config TEXT, created_at TEXT, updated_at TEXT, type TEXT)',
        "CREATE TABLE product_payment (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, once_price REAL DEFAULT 0, once_setup_price REAL DEFAULT 0, {$payment})",
        'CREATE TABLE service_hosting_server (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, ip TEXT, hostname TEXT, assigned_ips TEXT, status_url TEXT, active INTEGER, max_accounts INTEGER, ns1 TEXT, ns2 TEXT, ns3 TEXT, ns4 TEXT, manager TEXT, username TEXT, password TEXT, accesshash TEXT, password_length INTEGER, port TEXT, config TEXT, secure INTEGER, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE service_hosting_hp (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, quota TEXT, bandwidth TEXT, max_ftp TEXT, max_sql TEXT, max_pop TEXT, max_sub TEXT, max_park TEXT, max_addon TEXT, config TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE form (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, style TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE form_field (id INTEGER PRIMARY KEY AUTOINCREMENT, form_id INTEGER, name TEXT, label TEXT, hide_label INTEGER, description TEXT, type TEXT, default_value TEXT, required INTEGER, hidden INTEGER, readonly INTEGER, is_unique INTEGER, prefix TEXT, suffix TEXT, options TEXT, show_initial TEXT, show_middle TEXT, show_prefix TEXT, show_suffix TEXT, text_size INTEGER, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE promo (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, description TEXT, type TEXT, value REAL, maxuses INTEGER, used INTEGER, freesetup INTEGER, once_per_client INTEGER, recurring INTEGER, active INTEGER, products TEXT, periods TEXT, client_groups TEXT, start_at TEXT, end_at TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE tld (id INTEGER PRIMARY KEY AUTOINCREMENT, tld_registrar_id INTEGER, tld TEXT UNIQUE, price_registration REAL, price_renew REAL, price_transfer REAL, allow_register INTEGER, allow_transfer INTEGER, require_transfer_code INTEGER, active INTEGER, min_years INTEGER, periods TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE tld_registrar (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, registrar TEXT, test_mode INTEGER, config TEXT)',
        'CREATE TABLE tax (id INTEGER PRIMARY KEY AUTOINCREMENT, level INTEGER, name TEXT, country TEXT, state TEXT, taxrate TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE product_currency_price (id INTEGER PRIMARY KEY, kind TEXT NOT NULL, ref_id INTEGER NOT NULL, currency TEXT NOT NULL, period TEXT NOT NULL, price REAL NULL, setup_price REAL NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (kind, ref_id, currency, period))',
        'CREATE TABLE ' . Map::TABLE . ' (source TEXT, entity TEXT, source_id TEXT, target_id INTEGER, created_at TEXT, PRIMARY KEY (source, entity, source_id))',
        'CREATE TABLE product_option_group (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, description TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE product_option_group_product (group_id INTEGER, product_id INTEGER, PRIMARY KEY (group_id, product_id))',
        "CREATE TABLE product_option (id INTEGER PRIMARY KEY AUTOINCREMENT, group_id INTEGER, title TEXT, code TEXT DEFAULT '', type TEXT, qty_min INTEGER DEFAULT 0, qty_max INTEGER DEFAULT 0, unit TEXT DEFAULT '', hidden INTEGER DEFAULT 0, position INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT)",
        "CREATE TABLE product_option_value (id INTEGER PRIMARY KEY AUTOINCREMENT, option_id INTEGER, title TEXT, code TEXT DEFAULT '', hidden INTEGER DEFAULT 0, position INTEGER DEFAULT 0, "
            . implode(', ', array_map(fn (string $k): string => "{$k}_price REAL DEFAULT 0, {$k}_setup_price REAL DEFAULT 0", ['once', 'w', 'm', 'q', 'b', 'a', 'bia', 'tria'])) . ', created_at TEXT, updated_at TEXT)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    // As after the currencies step, with a fresh install's .com and registrars.
    $db->insert('currency', ['code' => 'USD', 'is_default' => 1, 'conversion_rate' => 1]);
    $db->insert('currency', ['code' => 'EGP', 'is_default' => 0, 'conversion_rate' => 50]);
    $db->insert('tld_registrar', ['name' => 'Custom', 'registrar' => 'Custom', 'test_mode' => 0]);
    $db->insert('tld_registrar', ['name' => 'Reseller Club', 'registrar' => 'Resellerclub', 'test_mode' => 0]);
    $db->insert('tld', ['tld_registrar_id' => 1, 'tld' => '.com', 'price_registration' => 11.99, 'price_renew' => 11.99, 'price_transfer' => 11.99, 'allow_register' => 1, 'allow_transfer' => 1, 'active' => 1, 'min_years' => 1]);
    $db->insert('product_category', ['title' => 'Default category']);
    $db->insert('product', ['title' => 'Domains registration and transfer', 'slug' => 'domain-checker', 'type' => 'domain', 'status' => 'enabled']);

    return $db;
}

/** Runs every catalogue step to the end in batches of $batch, as Run::work does. */
function whmcsCatalogueAll(Connection $source, Connection $target, ?Cipher $cipher, int $batch = 2): Catalogue
{
    $catalogue = new Catalogue(
        new Source($source),
        $target,
        new Map($target),
        $cipher,
        static fn (string $v): string => 'sealed:' . $v,
        static fn (string $manager): array => $manager === 'Whm' ? ['username', 'password', 'accesshash'] : ['password', 'accesshash'],
        ['Custom', 'Resellerclub', 'Dynadot'],
        '2026-10-19 00:00:00',
    );
    foreach (array_keys(Catalogue::STEPS) as $step) {
        $after = 0;
        do {
            [$after] = $catalogue->batch($step, (int) $after, $batch);
        } while ($after !== null);
    }

    return $catalogue;
}

/** @return array<string, mixed> */
function whmcsProductByTitle(Connection $db, string $title, int $isAddon = 0): array
{
    return $db->fetchAssociative('SELECT p.*, pp.type AS payment_type, pp.m_price, pp.m_setup_price, pp.m_enabled, pp.a_price, pp.a_enabled, pp.q_enabled, pp.once_price, pp.once_setup_price FROM product p JOIN product_payment pp ON pp.id = p.product_payment_id WHERE p.title = ? AND p.is_addon = ? ORDER BY p.id', [$title, $isAddon]) ?: [];
}

test('long import map keys fit the source id column and can be read back', function (): void {
    $target = whmcsCatalogueTarget();
    $map = new Map($target);
    $key = str_repeat('a', 100);
    $map->put('customaddon', $key, 42);

    expect($map->get('customaddon', $key))->toBe(42)
        ->and((new Map($target))->get('customaddon', $key))->toBe(42)
        ->and($map->has('customaddon', $key))->toBeTrue()
        ->and(strlen((string) $target->fetchOne('SELECT source_id FROM ' . Map::TABLE . ' WHERE entity = ?', ['customaddon'])))->toBeLessThanOrEqual(64);
});

test('groups, servers (switched off, keys kept encrypted) and hosting products', function (): void {
    $target = whmcsCatalogueTarget();
    whmcsCatalogueAll(whmcsCatalogueSource(), $target, Cipher::withKey(WHMCS_CATALOGUE_KEY));

    expect($target->fetchFirstColumn('SELECT title FROM product_category ORDER BY id'))->toBe(['Default category', 'Hosting', 'Secret deals'])
        ->and($target->fetchOne("SELECT description FROM product_category WHERE title = 'Hosting'"))->toBe("Fast hosting\n\nFrom \$10");

    $servers = $target->fetchAllAssociative('SELECT * FROM service_hosting_server');
    expect($servers)->toHaveCount(1)
        ->and($servers[0])->toMatchArray([
            'name' => 'cp1', 'ip' => '203.0.113.5', 'hostname' => 'cp1.host.test', 'manager' => 'Whm', 'active' => 0, 'secure' => 1, 'port' => null,
            'username' => 'sealed:root', 'password' => 'sealed:server-pass', 'accesshash' => 'sealed:whm-token', 'ns1' => 'ns1.host.test', 'max_accounts' => 200,
        ])
        ->and(json_decode((string) $servers[0]['assigned_ips'], true))->toBe(['203.0.113.6']);

    $starter = whmcsProductByTitle($target, 'Starter');
    $config = json_decode((string) $starter['config'], true);
    $plan = $target->fetchAssociative('SELECT * FROM service_hosting_hp');
    expect($starter)->toMatchArray(['type' => 'hosting', 'slug' => 'starter', 'status' => 'enabled', 'hidden' => 0, 'setup' => 'after_payment', 'payment_type' => 'recurrent', 'm_price' => 10.0, 'm_setup_price' => 5.0, 'm_enabled' => 1, 'a_price' => 100.0, 'a_enabled' => 1, 'q_enabled' => 0, 'priority' => 2])
        ->and($plan['name'])->toBe('starter_pkg')
        // WHM's own name for the plan would be "<server user>_starter_pkg": the plan names it exactly.
        ->and(json_decode((string) $plan['config'], true))->toBe(['package' => 'starter_pkg'])
        ->and($config)->toMatchArray(['server_id' => (int) $servers[0]['id'], 'hosting_plan_id' => (int) $plan['id'], 'reseller' => false, 'free_domain' => true, 'free_tlds' => ['.com', '.net'], 'free_domain_periods' => ['1Y'], 'free_domain_renewals' => false])
        // EGP prices become fixed prices.
        ->and(CurrencyPrices::forItem($target, 'product', (int) $starter['id'], 'EGP'))->toBe(['1M' => ['price' => 500.0, 'setup_price' => 250.0], '1Y' => ['price' => 5000.0, 'setup_price' => 0.0]]);

    // Its custom fields are its order form.
    $fields = $target->fetchAllAssociative('SELECT name, label, type, required, hidden, options FROM form_field WHERE form_id = ? ORDER BY id', [$starter['form_id']]);
    expect($fields)->toHaveCount(2)
        ->and($fields[0])->toMatchArray(['name' => 'whmcs_field_7', 'label' => 'Plan size', 'type' => 'select', 'required' => 1, 'hidden' => 0])
        ->and(json_decode((string) $fields[0]['options'], true))->toBe(['Small' => 'S', 'Medium' => 'M'])
        ->and($fields[1])->toMatchArray(['name' => 'whmcs_field_8', 'type' => 'textarea', 'hidden' => 1]);
});

test('licence, custom, one-time and free products; add-ons and upgrade paths', function (): void {
    $target = whmcsCatalogueTarget();
    $catalogue = whmcsCatalogueAll(whmcsCatalogueSource(), $target, Cipher::withKey(WHMCS_CATALOGUE_KEY));

    $licence = whmcsProductByTitle($target, 'App licence');
    $setup = whmcsProductByTitle($target, 'Setup service');
    $free = $target->fetchAssociative("SELECT p.slug, pp.type FROM product p JOIN product_payment pp ON pp.id = p.product_payment_id WHERE p.title = 'Starter' ORDER BY p.id DESC");
    expect($licence)->toMatchArray(['type' => 'license', 'hidden' => 1, 'setup' => 'after_order'])
        ->and(json_decode((string) $licence['config'], true)['plugin'])->toBe('Simple')
        ->and($setup)->toMatchArray(['type' => 'custom', 'status' => 'disabled', 'payment_type' => 'once', 'once_price' => 99.0, 'once_setup_price' => 1.0, 'setup' => 'manual'])
        // Two WHMCS products may share a name: the slug does not.
        ->and($free)->toBe(['slug' => 'starter-2', 'type' => 'free']);

    $starter = whmcsProductByTitle($target, 'Starter');
    $backups = whmcsProductByTitle($target, 'Backups', 1);
    $ssl = whmcsProductByTitle($target, 'SSL', 1);
    expect($backups)->toMatchArray(['is_addon' => 1, 'm_price' => 2.0, 'm_enabled' => 1, 'a_enabled' => 0, 'setup' => 'after_payment'])
        ->and(CurrencyPrices::forItem($target, 'product', (int) $backups['id'], 'EGP'))->toBe(['1M' => ['price' => 90.0, 'setup_price' => 0.0]])
        // An older add-on with one cycle keeps that cycle only.
        ->and($ssl)->toMatchArray(['a_price' => 30.0, 'a_enabled' => 1, 'm_enabled' => 0])
        ->and(json_decode((string) $starter['addons'], true))->toBe([(int) $backups['id'], (int) $ssl['id']])
        ->and(json_decode((string) $licence['addons'], true))->toBe([(int) $backups['id']])
        ->and(json_decode((string) $starter['upgrades'], true))->toBe([(int) $setup['id']]);

    $warnings = implode("\n", $catalogue->warnings());
    expect($warnings)->toContain('Hosting server "odd" uses the WHMCS module "virtualmin"')
        ->toContain('switched off')
        ->toContain('Product "Setup service" uses the WHMCS module "mymodule"')
        ->toContain('Product "Setup service": prorata billing is not imported.')
        ->toContain('Product "App licence" is a WHMCS Licensing product')
        ->toContain('Add-on "SSL" uses the WHMCS module "marketconnect"')
        ->toContain('Bundle "Starter pack" was not imported');
});

test('promotions and domain prices', function (): void {
    $target = whmcsCatalogueTarget();
    $catalogue = whmcsCatalogueAll(whmcsCatalogueSource(), $target, Cipher::withKey(WHMCS_CATALOGUE_KEY));

    $starter = (int) whmcsProductByTitle($target, 'Starter')['id'];
    $backups = (int) whmcsProductByTitle($target, 'Backups', 1)['id'];
    $ten = $target->fetchAssociative("SELECT * FROM promo WHERE code = 'TEN'");
    expect($ten)->toMatchArray(['type' => 'percentage', 'value' => 10.0, 'recurring' => 1, 'maxuses' => 50, 'used' => 4, 'once_per_client' => 1, 'active' => 0, 'description' => 'Launch', 'start_at' => '2026-01-01 00:00:00', 'end_at' => '2026-12-31 23:59:59'])
        ->and(json_decode((string) $ten['products'], true))->toBe([$starter, $backups])
        ->and(json_decode((string) $ten['periods'], true))->toBe(['1M', '1Y'])
        ->and($target->fetchAssociative("SELECT type, value, start_at, end_at, products FROM promo WHERE code = 'FIVE'"))->toBe(['type' => 'absolute', 'value' => 5.0, 'start_at' => null, 'end_at' => null, 'products' => null])
        ->and($target->fetchAssociative("SELECT type, value, freesetup, active FROM promo WHERE code = 'NOSETUP'"))->toBe(['type' => 'percentage', 'value' => 0.0, 'freesetup' => 1, 'active' => 0])
        ->and($target->fetchOne("SELECT active FROM promo WHERE code = 'FIVE'"))->toBe(1)
        // Only for .com in WHMCS: without a product it would discount everything.
        ->and($target->fetchAssociative("SELECT products, active FROM promo WHERE code = 'DOMAIN50'"))->toBe(['products' => null, 'active' => 0])
        ->and($target->fetchOne("SELECT COUNT(*) FROM promo WHERE code = 'FIXED9'"))->toBe(0);

    $com = $target->fetchAssociative("SELECT * FROM tld WHERE tld = '.com'");
    $net = $target->fetchAssociative("SELECT * FROM tld WHERE tld = '.net'");
    expect($target->fetchOne('SELECT COUNT(*) FROM tld'))->toBe(2)
        ->and($com)->toMatchArray(['tld_registrar_id' => 2, 'price_registration' => 19.0, 'price_renew' => 19.0, 'price_transfer' => 15.0, 'allow_transfer' => 1, 'require_transfer_code' => 1, 'min_years' => 1, 'periods' => '1,2,3'])
        ->and(CurrencyPrices::forItem($target, 'tld', (int) $com['id'], 'EGP'))->toBe(['register' => ['price' => 950.0, 'setup_price' => null], 'renew' => ['price' => 1000.0, 'setup_price' => null]])
        // From two years: registration + renewal = WHMCS's two-year price.
        ->and($net)->toMatchArray(['tld_registrar_id' => 1, 'price_registration' => 16.0, 'price_renew' => 14.0, 'allow_transfer' => 0, 'min_years' => 2, 'periods' => '2']);

    $warnings = implode("\n", $catalogue->warnings());
    expect($warnings)->toContain('Promotion "FIXED9" was not imported: ClientFence has no "Price Override" promotions.')
        // QA r4 W-03: a code that would discount more than in WHMCS waits, switched off.
        ->toContain('Promotion "TEN" was imported switched off: ClientFence does not keep it also applied to D.com, its domain years, the limit of 3 recurring cycles')
        ->toContain('Promotion "NOSETUP" was imported switched off: ClientFence does not keep new clients only, so it would apply more widely')
        ->toContain('Domain .com: WHMCS priced 3 years differently')
        ->toContain('Registrar "resellerclub": enter its API settings')
        ->toContain('the WHMCS registrar "enom" use the Custom registrar')
        ->toContain('Domain .org was not imported');
});

test('without the WHMCS key a server keeps no password; a second run imports nothing again', function (): void {
    $source = whmcsCatalogueSource();
    $target = whmcsCatalogueTarget();
    $first = whmcsCatalogueAll($source, $target, null);
    expect($target->fetchOne('SELECT password FROM service_hosting_server'))->toBeNull()
        ->and(implode("\n", $first->warnings()))->toContain('Hosting server "cp1": its password could not be read (no WHMCS encryption key)');

    $counts = static fn (): array => array_map(static fn (string $t): int => (int) $target->fetchOne('SELECT COUNT(*) FROM ' . $t), ['product', 'product_payment', 'promo', 'tld', 'service_hosting_server', 'form_field', 'product_currency_price']);
    $before = $counts();
    $second = whmcsCatalogueAll($source, $target, null, 50);
    expect($counts())->toBe($before)
        ->and($second->warnings())->toBe([]);
});

test('WHMCS custom field types map to order form fields', function (): void {
    expect(Catalogue::fieldType(['fieldtype' => 'tickbox', 'fieldname' => 'Agree']))->toBe(['checkbox', '{"Agree":"on"}'])
        ->and(Catalogue::fieldType(['fieldtype' => 'dropdown', 'fieldoptions' => 'One, Two']))->toBe(['select', '{"One":"One","Two":"Two"}'])
        ->and(Catalogue::fieldType(['fieldtype' => 'password'])[0])->toBe('text')
        ->and(Catalogue::fieldType(['fieldtype' => 'link'])[0])->toBe('text');
});

test('WHMCS automatic setup maps to the ClientFence setup', function (): void {
    expect(Catalogue::setup('order'))->toBe('after_order')
        ->and(Catalogue::setup('payment'))->toBe('after_payment')
        // "on": set up when staff accept the order.
        ->and(Catalogue::setup('on'))->toBe('manual')
        ->and(Catalogue::setup(''))->toBe('manual');
});

/** The catalogue fixture plus services, service add-ons, domains and their side tables. */
function whmcsServicesSource(): Connection
{
    $db = whmcsCatalogueSource();
    foreach ([
        "CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INTEGER, orderid INTEGER DEFAULT 0, packageid INTEGER, server INTEGER DEFAULT 0, regdate TEXT, domain TEXT DEFAULT '', paymentmethod TEXT DEFAULT '', qty INTEGER DEFAULT 1, firstpaymentamount REAL DEFAULT 0, amount REAL DEFAULT 0, billingcycle TEXT, nextduedate TEXT, termination_date TEXT DEFAULT '0000-00-00', domainstatus TEXT, username TEXT DEFAULT '', password TEXT DEFAULT '', notes TEXT DEFAULT '', subscriptionid TEXT DEFAULT '', promoid INTEGER DEFAULT 0, suspendreason TEXT DEFAULT '', overideautosuspend TEXT DEFAULT '', overidesuspenduntil TEXT DEFAULT '0000-00-00', dedicatedip TEXT DEFAULT '')",
        "CREATE TABLE tblhostingaddons (id INTEGER PRIMARY KEY, orderid INTEGER DEFAULT 0, hostingid INTEGER, addonid INTEGER, userid INTEGER, name TEXT DEFAULT '', qty INTEGER DEFAULT 1, recurring REAL DEFAULT 0, billingcycle TEXT, status TEXT, regdate TEXT, nextduedate TEXT, termination_date TEXT DEFAULT '0000-00-00', notes TEXT DEFAULT '', subscriptionid TEXT DEFAULT '')",
        "CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INTEGER, orderid INTEGER DEFAULT 0, type TEXT, registrationdate TEXT, domain TEXT, firstpaymentamount REAL DEFAULT 0, recurringamount REAL DEFAULT 0, registrar TEXT DEFAULT '', registrationperiod INTEGER DEFAULT 1, expirydate TEXT, subscriptionid TEXT DEFAULT '', promoid INTEGER DEFAULT 0, status TEXT, nextduedate TEXT, additionalnotes TEXT DEFAULT '', dnsmanagement INTEGER DEFAULT 0, emailforwarding INTEGER DEFAULT 0, idprotection INTEGER DEFAULT 0, donotrenew INTEGER DEFAULT 0)",
        'CREATE TABLE tblcancelrequests (id INTEGER PRIMARY KEY, date TEXT, relid INTEGER, reason TEXT, type TEXT)',
        'CREATE TABLE tblcustomfieldsvalues (id INTEGER PRIMARY KEY, fieldid INTEGER, relid INTEGER, value TEXT)',
        'CREATE TABLE mod_licensing (id INTEGER PRIMARY KEY, serviceid INTEGER, licensekey TEXT, validdomain TEXT, validip TEXT, validdirectory TEXT, reissues INTEGER DEFAULT 0, status TEXT, lastaccess TEXT)',
        "CREATE TABLE tblproductconfiggroups (id INTEGER PRIMARY KEY, name TEXT, description TEXT DEFAULT '')",
        'CREATE TABLE tblproductconfiglinks (id INTEGER PRIMARY KEY, gid INTEGER, pid INTEGER)',
        'CREATE TABLE tblproductconfigoptions (id INTEGER PRIMARY KEY, gid INTEGER, optionname TEXT, optiontype INTEGER, qtyminimum INTEGER DEFAULT 0, qtymaximum INTEGER DEFAULT 0, "order" INTEGER DEFAULT 0, hidden INTEGER DEFAULT 0)',
        'CREATE TABLE tblproductconfigoptionssub (id INTEGER PRIMARY KEY, configid INTEGER, optionname TEXT, sortorder INTEGER DEFAULT 0, hidden INTEGER DEFAULT 0)',
        'CREATE TABLE tblhostingconfigoptions (id INTEGER PRIMARY KEY, relid INTEGER, configid INTEGER, optionid INTEGER, qty INTEGER DEFAULT 0)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    // Configurable options on Starter (product 1): memory, backups (yes/no), extra IPs (quantity).
    $db->insert('tblproductconfiggroups', ['id' => 1, 'name' => 'VPS extras', 'description' => 'More of everything']);
    $db->insert('tblproductconfiglinks', ['gid' => 1, 'pid' => 1]);
    $db->insert('tblproductconfiglinks', ['gid' => 1, 'pid' => 99]);
    $db->insert('tblproductconfigoptions', ['id' => 1, 'gid' => 1, 'optionname' => 'ram|Memory', 'optiontype' => 1, '"order"' => 1]);
    $db->insert('tblproductconfigoptionssub', ['id' => 1, 'configid' => 1, 'optionname' => '2gb|2 GB', 'sortorder' => 1]);
    $db->insert('tblproductconfigoptionssub', ['id' => 2, 'configid' => 1, 'optionname' => '4gb|4 GB', 'sortorder' => 2]);
    whmcsPricing($db, 'configoptions', 1, 1, ['monthly' => 0, 'annually' => 0]);
    whmcsPricing($db, 'configoptions', 2, 1, ['monthly' => 5, 'msetupfee' => 1, 'annually' => 50]);
    whmcsPricing($db, 'configoptions', 2, 2, ['monthly' => 250, 'msetupfee' => 50, 'annually' => 2500]);
    $db->insert('tblproductconfigoptions', ['id' => 2, 'gid' => 1, 'optionname' => 'Daily backups', 'optiontype' => 3, '"order"' => 2]);
    $db->insert('tblproductconfigoptionssub', ['id' => 3, 'configid' => 2, 'optionname' => 'Yes']);
    whmcsPricing($db, 'configoptions', 3, 1, ['monthly' => 3]);
    // Its own EGP price (not 3 × 50): converted at the rate in ClientFence, with a warning.
    whmcsPricing($db, 'configoptions', 3, 2, ['monthly' => 200]);
    $db->insert('tblproductconfigoptions', ['id' => 3, 'gid' => 1, 'optionname' => 'ips|Extra IPs', 'optiontype' => 4, 'qtyminimum' => 0, 'qtymaximum' => 8, '"order"' => 3, 'hidden' => 1]);
    $db->insert('tblproductconfigoptionssub', ['id' => 4, 'configid' => 3, 'optionname' => 'IP']);
    whmcsPricing($db, 'configoptions', 4, 1, ['monthly' => 2]);
    $db->insert('tblproductconfigoptions', ['id' => 4, 'gid' => 1, 'optionname' => 'Odd', 'optiontype' => 9]);
    $db->insert('tblhostingconfigoptions', ['relid' => 1, 'configid' => 1, 'optionid' => 2, 'qty' => 0]);
    $db->insert('tblhostingconfigoptions', ['relid' => 1, 'configid' => 2, 'optionid' => 3, 'qty' => 1]);
    $db->insert('tblhostingconfigoptions', ['relid' => 1, 'configid' => 3, 'optionid' => 4, 'qty' => 2]);
    $db->insert('tblhostingconfigoptions', ['relid' => 1, 'configid' => 4, 'optionid' => 0, 'qty' => 1]);
    // 1: hosting with a promotion, a subscription, answers and an end-of-period cancellation request.
    $db->insert('tblhosting', ['id' => 1, 'userid' => 1, 'packageid' => 1, 'server' => 1, 'regdate' => '2025-11-01', 'domain' => 'Shop.Example.co.uk', 'amount' => 9, 'billingcycle' => 'Monthly', 'nextduedate' => '2026-11-01', 'domainstatus' => 'Active', 'username' => 'shopuser', 'password' => 'encrypted-in-whmcs', 'notes' => 'VIP', 'subscriptionid' => 'I-ABC123', 'promoid' => 1]);
    $db->insert('tblcustomfieldsvalues', ['fieldid' => 7, 'relid' => 1, 'value' => 'M']);
    $db->insert('tblcustomfieldsvalues', ['fieldid' => 8, 'relid' => 1, 'value' => 'Call first']);
    // A client field's answer with the same relid is not the service's.
    $db->insert('tblcustomfieldsvalues', ['fieldid' => 9, 'relid' => 1, 'value' => 'client value']);
    $db->insert('tblcancelrequests', ['date' => '2026-10-01', 'relid' => 1, 'reason' => 'Too expensive', 'type' => 'End of Billing Period']);
    // 2: a licence in EGP with its WHMCS Licensing record.
    $db->insert('tblhosting', ['id' => 2, 'userid' => 2, 'packageid' => 2, 'regdate' => '2025-01-10', 'amount' => 2400, 'billingcycle' => 'Annually', 'nextduedate' => '2027-01-10', 'domainstatus' => 'Active']);
    $db->insert('mod_licensing', ['serviceid' => 2, 'licensekey' => 'LIC-ONE', 'validdomain' => 'a.test', 'validip' => "1.2.3.4,5.6.7.8\n1.2.3.4", 'validdirectory' => '/home/a', 'status' => 'Active', 'lastaccess' => '2026-10-18 10:00:00']);
    // 3: a completed one-time service; 4: a terminated one; 5: of a client that was not imported.
    $db->insert('tblhosting', ['id' => 3, 'userid' => 1, 'packageid' => 3, 'regdate' => '2024-03-01', 'amount' => 99, 'billingcycle' => 'One Time', 'nextduedate' => '0000-00-00', 'domainstatus' => 'Completed']);
    $db->insert('tblhosting', ['id' => 4, 'userid' => 1, 'packageid' => 1, 'regdate' => '2023-05-01', 'domain' => 'old.example.com', 'amount' => 100, 'billingcycle' => 'Annually', 'nextduedate' => '2025-05-01', 'termination_date' => '2025-05-02', 'domainstatus' => 'Terminated']);
    $db->insert('tblcancelrequests', ['date' => '2025-04-20', 'relid' => 4, 'reason' => 'Moved away', 'type' => 'Immediate']);
    $db->insert('tblhosting', ['id' => 5, 'userid' => 3, 'packageid' => 1, 'regdate' => '2025-01-01', 'amount' => 10, 'billingcycle' => 'Monthly', 'nextduedate' => '2026-11-01', 'domainstatus' => 'Active']);
    // 7 and 8: scaled quantities (WHMCS's amount is the total for all units).
    $db->insert('tblhosting', ['id' => 7, 'userid' => 1, 'packageid' => 3, 'regdate' => '2025-06-01', 'qty' => 5, 'amount' => 50, 'billingcycle' => 'One Time', 'nextduedate' => '0000-00-00', 'domainstatus' => 'Active']);
    $db->insert('tblhosting', ['id' => 8, 'userid' => 1, 'packageid' => 3, 'regdate' => '2025-06-01', 'qty' => 3, 'amount' => 10, 'billingcycle' => 'One Time', 'nextduedate' => '0000-00-00', 'domainstatus' => 'Active']);
    // 6: suspended, without a domain.
    $db->insert('tblhosting', ['id' => 6, 'userid' => 1, 'packageid' => 1, 'server' => 1, 'regdate' => '2025-06-01', 'amount' => 10, 'billingcycle' => 'Monthly', 'nextduedate' => '2026-09-01', 'domainstatus' => 'Suspended', 'suspendreason' => 'Overdue on payment']);

    $db->insert('tblhostingaddons', ['id' => 1, 'hostingid' => 1, 'addonid' => 1, 'userid' => 1, 'recurring' => 2, 'billingcycle' => 'Monthly', 'status' => 'Active', 'regdate' => '2025-11-01', 'nextduedate' => '2026-11-01']);
    $db->insert('tblhostingaddons', ['id' => 2, 'hostingid' => 1, 'addonid' => 0, 'userid' => 1, 'name' => 'Priority support', 'recurring' => 5, 'billingcycle' => 'Monthly', 'status' => 'Active', 'regdate' => '2025-11-01', 'nextduedate' => '2026-11-01']);
    $db->insert('tblhostingaddons', ['id' => 3, 'hostingid' => 5, 'addonid' => 1, 'userid' => 3, 'recurring' => 2, 'billingcycle' => 'Monthly', 'status' => 'Active', 'regdate' => '2025-01-01', 'nextduedate' => '2026-11-01']);

    $db->insert('tbldomains', ['id' => 1, 'userid' => 1, 'type' => 'Register', 'registrationdate' => '2024-01-01', 'domain' => 'example.com', 'recurringamount' => 21, 'registrar' => 'resellerclub', 'registrationperiod' => 1, 'expirydate' => '2027-01-01', 'status' => 'Active', 'nextduedate' => '2026-12-15', 'dnsmanagement' => 1, 'idprotection' => 1]);
    $db->insert('tbldomains', ['id' => 2, 'userid' => 2, 'type' => 'Transfer', 'registrationdate' => '2025-02-01', 'domain' => 'masr.com', 'recurringamount' => 2000, 'registrar' => '', 'registrationperiod' => 2, 'expirydate' => '2027-02-01', 'status' => 'Active', 'nextduedate' => '2027-01-20']);
    $db->insert('tbldomains', ['id' => 3, 'userid' => 1, 'type' => 'Register', 'registrationdate' => '2020-01-01', 'domain' => 'gone.net', 'recurringamount' => 14, 'registrar' => 'enom', 'expirydate' => '2024-01-01', 'status' => 'Expired', 'nextduedate' => '2024-01-01', 'donotrenew' => 1]);

    return $db;
}

/** The catalogue target plus clients (WHMCS 1 => 1 in USD, 2 => 2 in EGP), orders and services. */
function whmcsServicesTarget(): Connection
{
    $db = whmcsCatalogueTarget();
    foreach ([
        'CREATE TABLE client (id INTEGER PRIMARY KEY, currency TEXT, email TEXT, company TEXT, first_name TEXT, last_name TEXT, address_1 TEXT, address_2 TEXT, city TEXT, state TEXT, postcode TEXT, country TEXT, phone_cc TEXT, phone TEXT)',
        'CREATE TABLE client_order (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, product_id INTEGER, form_id INTEGER, promo_id INTEGER, promo_recurring INTEGER, promo_used INTEGER, group_id TEXT, group_master INTEGER, invoice_option TEXT, title TEXT, currency TEXT, unpaid_invoice_id INTEGER, service_id INTEGER, service_type TEXT, period TEXT, quantity INTEGER, unit TEXT, price REAL, discount REAL, status TEXT, reason TEXT, notes TEXT, config TEXT, referred_by TEXT, expires_at TEXT, activated_at TEXT, suspended_at TEXT, unsuspended_at TEXT, canceled_at TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE client_order_status (id INTEGER PRIMARY KEY AUTOINCREMENT, client_order_id INTEGER, status TEXT, notes TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE client_order_meta (id INTEGER PRIMARY KEY AUTOINCREMENT, client_order_id INTEGER, name TEXT, value TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE service_hosting (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, service_hosting_server_id INTEGER, service_hosting_hp_id INTEGER, sld TEXT, tld TEXT, ip TEXT, username TEXT, pass TEXT, reseller INTEGER, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE service_license (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, license_key TEXT UNIQUE, validate_ip INTEGER, validate_host INTEGER, validate_path INTEGER, validate_version INTEGER, ips TEXT, hosts TEXT, paths TEXT, versions TEXT, config TEXT, plugin TEXT, checked_at TEXT, pinged_at TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE service_custom (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, plugin TEXT, plugin_config TEXT, f1 TEXT, config TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE service_domain (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, tld_registrar_id INTEGER, sld TEXT, tld TEXT, ns1 TEXT, ns2 TEXT, ns3 TEXT, ns4 TEXT, period INTEGER, privacy INTEGER, locked INTEGER, transfer_code TEXT, action TEXT, contact_email TEXT, contact_company TEXT, contact_first_name TEXT, contact_last_name TEXT, contact_address1 TEXT, contact_address2 TEXT, contact_city TEXT, contact_state TEXT, contact_postcode TEXT, contact_country TEXT, contact_phone_cc TEXT, contact_phone TEXT, details TEXT, synced_at TEXT, registered_at TEXT, expires_at TEXT, created_at TEXT, updated_at TEXT)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    $db->insert('client', ['id' => 1, 'currency' => 'USD', 'email' => 'avery@client.test', 'first_name' => 'Avery', 'last_name' => 'Stone', 'country' => 'GB']);
    $db->insert('client', ['id' => 2, 'currency' => 'EGP', 'email' => 'omar@client.test', 'first_name' => 'Omar', 'last_name' => 'Said', 'country' => 'EG']);
    $db->insert('tld', ['tld_registrar_id' => 1, 'tld' => '.co.uk', 'price_registration' => 8, 'price_renew' => 8, 'price_transfer' => 8, 'allow_register' => 1, 'allow_transfer' => 1, 'active' => 1, 'min_years' => 1]);
    $map = new Map($db);
    $map->put('client', 1, 1);
    $map->put('client', 2, 2);

    return $db;
}

/** Runs the service steps to the end in batches of $batch. */
function whmcsServicesAll(Connection $source, Connection $target, int $batch = 2): Services
{
    $catalogue = new Catalogue(new Source($source), $target, new Map($target), null, static fn (string $v): string => $v, static fn (string $m): array => [], ['Custom', 'Resellerclub', 'Dynadot'], '2026-10-19 00:00:00');
    $services = new Services(new Source($source), $target, new Map($target), $catalogue, '2026-10-19 00:00:00');
    foreach (array_keys(Services::STEPS) as $step) {
        $after = 0;
        do {
            [$after] = $services->batch($step, (int) $after, $batch);
        } while ($after !== null);
    }

    return $services;
}

/** @return array<string, mixed> */
function whmcsOrderOf(Connection $db, string $entity, int $whmcsId): array
{
    $orderId = (new Map($db))->get($entity, $whmcsId);

    return $orderId === null ? [] : ($db->fetchAssociative('SELECT * FROM client_order WHERE id = ?', [$orderId]) ?: []);
}

test('services become orders with their hosting account, licence or custom service', function (): void {
    $source = whmcsServicesSource();
    $target = whmcsServicesTarget();
    $catalogueWarnings = whmcsCatalogueAll($source, $target, Cipher::withKey(WHMCS_CATALOGUE_KEY))->warnings();
    $warnings = [...$catalogueWarnings, ...whmcsServicesAll($source, $target)->warnings()];
    $starter = whmcsProductByTitle($target, 'Starter');
    $plan = (int) $target->fetchOne('SELECT id FROM service_hosting_hp');
    $server = (int) $target->fetchOne('SELECT id FROM service_hosting_server');

    // 1: renews at its own amount (promotion already in it), with its answers and subscription id.
    $hosting = whmcsOrderOf($target, 'service', 1);
    $config = json_decode((string) $hosting['config'], true);
    expect($hosting)->toMatchArray([
        'client_id' => 1, 'product_id' => (int) $starter['id'], 'form_id' => (int) $starter['form_id'], 'group_id' => 'whmcs_s1', 'group_master' => 1,
        'title' => 'Starter for shop.example.co.uk', 'currency' => 'USD', 'service_type' => 'hosting', 'period' => '1M', 'price' => 9.0, 'discount' => 0.0,
        'promo_recurring' => 0, 'status' => 'active', 'reason' => 'Too expensive', 'notes' => 'VIP', 'invoice_option' => 'issue-invoice',
        'expires_at' => '2026-11-01 00:00:00', 'activated_at' => '2025-11-01 00:00:00', 'canceled_at' => null,
    ])
        ->and($hosting['promo_id'])->not->toBeNull()
        ->and($config)->toMatchArray(['whmcs_field_7' => 'M', 'whmcs_field_8' => 'Call first', 'server_id' => $server, 'hosting_plan_id' => $plan, 'whmcs_service_id' => 1, 'domain' => ['action' => 'owndomain', 'owndomain_sld' => 'shop.example', 'owndomain_tld' => '.co.uk']])
        ->and($config)->not->toHaveKey('whmcs_field_9')
        ->and($target->fetchAllKeyValue('SELECT name, value FROM client_order_meta WHERE client_order_id = ?', [$hosting['id']]))->toBe(['whmcs_subscription_id' => 'I-ABC123', 'cancel_at_period_end' => '1'])
        ->and($target->fetchAssociative('SELECT * FROM service_hosting WHERE id = ?', [$hosting['service_id']]))->toMatchArray([
            'client_id' => 1, 'service_hosting_server_id' => $server, 'service_hosting_hp_id' => $plan, 'sld' => 'shop.example', 'tld' => '.co.uk',
            'ip' => '203.0.113.5', 'username' => 'shopuser', 'pass' => '********', 'reseller' => 0,
        ]);

    // 2: a licence keeps its key and bindings, in the client's currency.
    $licence = whmcsOrderOf($target, 'service', 2);
    expect($licence)->toMatchArray(['client_id' => 2, 'currency' => 'EGP', 'service_type' => 'license', 'period' => '1Y', 'price' => 2400.0, 'status' => 'active'])
        ->and($target->fetchAssociative('SELECT * FROM service_license WHERE id = ?', [$licence['service_id']]))->toMatchArray([
            'license_key' => 'LIC-ONE', 'validate_ip' => 1, 'validate_host' => 1, 'validate_path' => 0, 'plugin' => 'Simple',
            'ips' => '["1.2.3.4","5.6.7.8"]', 'hosts' => '["a.test"]', 'paths' => '["\/home\/a"]', 'checked_at' => '2026-10-18 10:00:00',
        ]);

    // 3: completed one-time work stays active and never renews; 4: terminated is canceled with its reason.
    expect(whmcsOrderOf($target, 'service', 3))->toMatchArray(['service_type' => 'custom', 'status' => 'active', 'period' => null, 'expires_at' => null, 'price' => 99.0])
        ->and(whmcsOrderOf($target, 'service', 4))->toMatchArray(['status' => 'canceled', 'reason' => 'Terminated in WHMCS: Moved away', 'canceled_at' => '2025-05-02 00:00:00', 'expires_at' => '2025-05-01 00:00:00'])
        ->and(whmcsOrderOf($target, 'service', 6))->toMatchArray(['status' => 'suspended', 'reason' => 'Overdue on payment', 'suspended_at' => '2026-10-19 00:00:00'])
        ->and(whmcsOrderOf($target, 'service', 5))->toBe([])
        // Per unit, so price × quantity is what WHMCS billed; else one unit at the total.
        ->and(whmcsOrderOf($target, 'service', 7))->toMatchArray(['quantity' => 5, 'price' => 10.0])
        ->and(whmcsOrderOf($target, 'service', 8))->toMatchArray(['quantity' => 1, 'price' => 10.0])
        ->and($target->fetchOne('SELECT COUNT(*) FROM client_order_status'))->toBeGreaterThanOrEqual(5);

    // Service add-ons sit under their service; one typed in on the service gets a hidden product.
    $backups = whmcsOrderOf($target, 'serviceaddon', 1);
    $typed = whmcsOrderOf($target, 'serviceaddon', 2);
    expect($backups)->toMatchArray(['group_id' => 'whmcs_s1', 'group_master' => 0, 'title' => 'Backups', 'price' => 2.0, 'period' => '1M', 'status' => 'active', 'service_type' => 'custom'])
        ->and($typed)->toMatchArray(['group_id' => 'whmcs_s1', 'group_master' => 0, 'title' => 'Priority support', 'price' => 5.0])
        ->and($target->fetchAssociative('SELECT status, hidden, is_addon, type FROM product WHERE id = ?', [$typed['product_id']]))->toBe(['status' => 'disabled', 'hidden' => 1, 'is_addon' => 1, 'type' => 'custom'])
        ->and(whmcsOrderOf($target, 'serviceaddon', 3))->toBe([]);

    // Configurable options: the group on Starter, its options and values with their prices.
    $group = $target->fetchAssociative('SELECT * FROM product_option_group');
    $options = $target->fetchAllAssociative('SELECT title, code, type, qty_min, qty_max, hidden FROM product_option ORDER BY position');
    $memory4 = $target->fetchAssociative("SELECT v.* FROM product_option_value v WHERE v.code = '4gb'");
    expect($group)->toMatchArray(['title' => 'VPS extras', 'description' => 'More of everything'])
        ->and($target->fetchFirstColumn('SELECT product_id FROM product_option_group_product'))->toBe([(int) $starter['id']])
        ->and($options)->toBe([
            ['title' => 'Memory', 'code' => 'ram', 'type' => 'select', 'qty_min' => 0, 'qty_max' => 0, 'hidden' => 0],
            ['title' => 'Daily backups', 'code' => '', 'type' => 'yesno', 'qty_min' => 0, 'qty_max' => 0, 'hidden' => 0],
            ['title' => 'Extra IPs', 'code' => 'ips', 'type' => 'quantity', 'qty_min' => 0, 'qty_max' => 8, 'hidden' => 1],
        ])
        ->and($memory4)->toMatchArray(['title' => '4 GB', 'm_price' => 5.0, 'm_setup_price' => 1.0, 'a_price' => 50.0, 'once_price' => 5.0, 'q_price' => 0.0]);

    // Service 1 keeps its choice and lines; its price is still what WHMCS billed.
    $lines = $config['option_lines'];
    expect(array_values($config['options']))->toBe([(int) $memory4['id'], 1, 2])
        ->and(array_map(fn (array $l): array => [$l['title'], $l['value'], $l['quantity'], $l['price']], $lines))->toBe([
            // (the stored config is JSON: whole amounts read back as integers)
            ['Memory', '4 GB', 1, 5], ['Daily backups', 'Yes', 1, 3], ['Extra IPs', 'IP', 2, 4],
        ])
        ->and($lines[0])->toMatchArray(['code' => 'ram', 'value_code' => '4gb', 'setup_price' => 1]);

    expect(implode("\n", $warnings))->toContain('Service #5 was not imported: its client was not imported.')
        ->toContain('Option "Daily backups" (VPS extras) had its own prices in other currencies')
        ->not->toContain('Option "Memory" (VPS extras) had its own prices')
        ->toContain('Option "Odd" (VPS extras) was not imported: WHMCS option type 9 is not known.')
        ->toContain('Hosting account passwords were not imported')
        ->toContain('gateway subscription')
        ->toContain('Service #1 (shop.example.co.uk) has a cancellation request (End of Billing Period)')
        ->toContain('Service #6 is a hosting account without a domain')
        ->toContain('Add-on "Priority support" was typed in on services')
        ->toContain('Service add-on #3 was not imported')
        ->toContain('Service #8: WHMCS billed 3 units for 10.00 in all');
});

test('service suspension overrides keep their flag and end date', function (string $until, string $expected): void {
    $source = whmcsServicesSource();
    $source->update('tblhosting', ['overideautosuspend' => 'on', 'overidesuspenduntil' => $until], ['id' => 2]);
    $target = whmcsServicesTarget();
    whmcsCatalogueAll($source, $target, Cipher::withKey(WHMCS_CATALOGUE_KEY));
    whmcsServicesAll($source, $target);
    $order = whmcsOrderOf($target, 'service', 2);

    expect($target->fetchAllKeyValue('SELECT name, value FROM client_order_meta WHERE client_order_id = ?', [$order['id']]))->toBe([
        \ClientFence\Mod\Order\Service::META_AUTO_SUSPEND_OVERRIDE => '1',
        \ClientFence\Mod\Order\Service::META_AUTO_SUSPEND_UNTIL => $expected,
    ]);
})->with([
    'dated' => ['2027-01-10', '2027-01-10'],
    'zero date' => ['0000-00-00', ''],
    'empty date' => ['', ''],
]);

test('domains become domain orders that renew at the domain price', function (): void {
    $source = whmcsServicesSource();
    $target = whmcsServicesTarget();
    whmcsCatalogueAll($source, $target, Cipher::withKey(WHMCS_CATALOGUE_KEY));
    $services = whmcsServicesAll($source, $target);
    $warnings = implode("\n", $services->warnings());
    $domainProduct = (int) $target->fetchOne("SELECT id FROM product WHERE type = 'domain'");

    $com = whmcsOrderOf($target, 'domain', 1);
    expect($com)->toMatchArray(['client_id' => 1, 'product_id' => $domainProduct, 'title' => 'Domain example.com registration', 'service_type' => 'domain', 'period' => '1Y', 'price' => 21.0, 'status' => 'active', 'expires_at' => '2026-12-15 00:00:00', 'invoice_option' => 'issue-invoice'])
        ->and(json_decode((string) $com['config'], true))->toMatchArray(['action' => 'register', 'register_sld' => 'example', 'register_tld' => '.com', 'register_years' => 1, 'period' => '1Y'])
        ->and($target->fetchAssociative('SELECT sld, tld, period, privacy, action, contact_email, contact_first_name, registered_at, expires_at FROM service_domain WHERE id = ?', [$com['service_id']]))->toBe([
            'sld' => 'example', 'tld' => '.com', 'period' => 1, 'privacy' => 1, 'action' => 'register', 'contact_email' => 'avery@client.test',
            'contact_first_name' => 'Avery', 'registered_at' => '2024-01-01 00:00:00', 'expires_at' => '2027-01-01 00:00:00',
        ])
        ->and((int) $target->fetchOne('SELECT tld_registrar_id FROM service_domain WHERE id = ?', [$com['service_id']]))->toBe((int) $target->fetchOne("SELECT id FROM tld_registrar WHERE registrar = 'Resellerclub'"));

    // In EGP for two years: the fixed EGP renewal price (1000 a year) is what WHMCS billed.
    $masr = whmcsOrderOf($target, 'domain', 2);
    expect($masr)->toMatchArray(['currency' => 'EGP', 'period' => '2Y', 'price' => 2000.0, 'title' => 'Domain masr.com transfer'])
        ->and(json_decode((string) $masr['config'], true))->toMatchArray(['action' => 'transfer', 'transfer_sld' => 'masr', 'transfer_tld' => '.com', 'register_years' => 2])
        // Expired: canceled, and not renewed.
        ->and(whmcsOrderOf($target, 'domain', 3))->toMatchArray(['status' => 'canceled', 'reason' => 'Expired in WHMCS', 'invoice_option' => 'no-invoice']);

    expect($warnings)->toContain('Domain example.com: WHMCS renewed it for 21.00 USD; ClientFence renews it at the .com price, 19.00 USD.')
        ->toContain('Domain example.com had DNS management in WHMCS')
        ->not->toContain('Domain masr.com: WHMCS renewed');

    // A second run imports nothing again.
    whmcsServicesAll($source, $target);
    expect((int) $target->fetchOne('SELECT COUNT(*) FROM client_order'))->toBe(12)
        ->and((new Map($target))->count('service'))->toBe(7)
        ->and((new Map($target))->count('serviceaddon'))->toBe(2)
        ->and((new Map($target))->count('domain'))->toBe(3);
});

/** The services fixture plus invoices, transactions, the credit log, credit and billing notes. */
function whmcsMoneySource(): Connection
{
    $db = whmcsServicesSource();
    foreach ([
        'CREATE TABLE tblclients (id INTEGER PRIMARY KEY, credit REAL DEFAULT 0)',
        "CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, invoicenum TEXT DEFAULT '', date TEXT, duedate TEXT, datepaid TEXT DEFAULT '0000-00-00 00:00:00', subtotal REAL DEFAULT 0, credit REAL DEFAULT 0, tax REAL DEFAULT 0, tax2 REAL DEFAULT 0, total REAL DEFAULT 0, taxrate REAL DEFAULT 0, status TEXT, paymentmethod TEXT DEFAULT '', notes TEXT DEFAULT '')",
        "CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INTEGER, userid INTEGER, type TEXT DEFAULT '', relid INTEGER DEFAULT 0, description TEXT, amount REAL, taxed INTEGER DEFAULT 0)",
        "CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INTEGER, currency INTEGER DEFAULT 0, gateway TEXT DEFAULT '', date TEXT, description TEXT DEFAULT '', amountin REAL DEFAULT 0, fees REAL DEFAULT 0, amountout REAL DEFAULT 0, transid TEXT DEFAULT '', invoiceid INTEGER DEFAULT 0)",
        'CREATE TABLE tblcredit (id INTEGER PRIMARY KEY, clientid INTEGER, date TEXT, description TEXT, amount REAL, relid INTEGER DEFAULT 0)',
        "CREATE TABLE tblbillingnotes (id INTEGER PRIMARY KEY, note_type TEXT, custom_number TEXT DEFAULT '', client_id INTEGER, date_issued TEXT, subtotal REAL, total REAL, taxrate REAL DEFAULT 0, status TEXT, notes TEXT DEFAULT '')",
        'CREATE TABLE tblbillingnoteitems (id INTEGER PRIMARY KEY, billingnote_id INTEGER, description TEXT, amount REAL, taxed INTEGER DEFAULT 0)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    $db->executeStatement("INSERT INTO tblconfiguration VALUES ('SequentialInvoiceNumbering', 'on'), ('SequentialInvoiceNumberFormat', '{NUMBER}'), ('SequentialInvoiceNumberValue', '103')");
    // Client 1 holds 15.00, which its credit log adds up to; client 2 holds 50.00 without a log.
    $db->executeStatement('INSERT INTO tblclients VALUES (1, 15), (2, 50), (3, 5)');

    // 10: paid with a promotion line; 11: unpaid renewal of service 1 and domain 1.
    $db->insert('tblinvoices', ['id' => 10, 'userid' => 1, 'invoicenum' => '101', 'date' => '2026-09-01', 'duedate' => '2026-09-05', 'datepaid' => '2026-09-02 10:00:00', 'subtotal' => 10, 'total' => 10, 'status' => 'Paid', 'paymentmethod' => 'paypal', 'notes' => 'Thanks']);
    $db->insert('tblinvoiceitems', ['invoiceid' => 10, 'userid' => 1, 'type' => 'Hosting', 'relid' => 1, 'description' => "Starter - shop.example.co.uk\n(01/09/2026 - 30/09/2026)", 'amount' => 9]);
    $db->insert('tblinvoiceitems', ['invoiceid' => 10, 'userid' => 1, 'type' => 'Addon', 'relid' => 1, 'description' => 'Backups', 'amount' => 2]);
    $db->insert('tblinvoiceitems', ['invoiceid' => 10, 'userid' => 1, 'type' => 'PromoHosting', 'relid' => 1, 'description' => 'Promotion TEN', 'amount' => -1]);
    $db->insert('tblinvoices', ['id' => 11, 'userid' => 1, 'date' => '2026-10-15', 'duedate' => '2026-11-01', 'subtotal' => 30, 'credit' => 5, 'total' => 25, 'status' => 'Unpaid', 'paymentmethod' => 'paypal']);
    $db->insert('tblinvoiceitems', ['invoiceid' => 11, 'userid' => 1, 'type' => 'Hosting', 'relid' => 1, 'description' => 'Starter renewal', 'amount' => 9]);
    $db->insert('tblinvoiceitems', ['invoiceid' => 11, 'userid' => 1, 'type' => 'Domain', 'relid' => 1, 'description' => 'Domain renewal - example.com', 'amount' => 21]);
    // 12: paid funds in EGP with a prefixed number; 13: of a client that was not imported.
    $db->insert('tblinvoices', ['id' => 12, 'userid' => 2, 'invoicenum' => 'INV-0102', 'date' => '2026-08-01', 'duedate' => '2026-08-01', 'datepaid' => '2026-08-01 09:00:00', 'subtotal' => 100, 'total' => 100, 'status' => 'Paid', 'paymentmethod' => 'banktransfer']);
    $db->insert('tblinvoiceitems', ['invoiceid' => 12, 'userid' => 2, 'type' => 'AddFunds', 'description' => 'Add Funds', 'amount' => 100]);
    $db->insert('tblinvoices', ['id' => 13, 'userid' => 3, 'date' => '2026-08-01', 'duedate' => '2026-08-01', 'subtotal' => 5, 'total' => 5, 'status' => 'Unpaid']);
    // 14: cancelled; 15: refunded.
    $db->insert('tblinvoices', ['id' => 14, 'userid' => 1, 'date' => '2026-07-01', 'duedate' => '2026-07-01', 'subtotal' => 5, 'total' => 5, 'status' => 'Cancelled']);
    $db->insert('tblinvoiceitems', ['invoiceid' => 14, 'userid' => 1, 'description' => 'Setup help', 'amount' => 5]);
    $db->insert('tblinvoices', ['id' => 15, 'userid' => 1, 'invoicenum' => '102', 'date' => '2026-06-01', 'duedate' => '2026-06-01', 'datepaid' => '2026-06-02 00:00:00', 'subtotal' => 20, 'total' => 20, 'status' => 'Refunded']);
    $db->insert('tblinvoiceitems', ['invoiceid' => 15, 'userid' => 1, 'description' => 'Consulting', 'amount' => 20]);
    // 17: refunded with its 20% tax: income and refund leave the tax out.
    $db->insert('tblinvoices', ['id' => 17, 'userid' => 1, 'invoicenum' => '100', 'date' => '2026-05-01', 'duedate' => '2026-05-01', 'datepaid' => '2026-05-01 00:00:00', 'subtotal' => 100, 'tax' => 20, 'total' => 120, 'taxrate' => 20, 'status' => 'Refunded']);
    $db->insert('tblinvoiceitems', ['invoiceid' => 17, 'userid' => 1, 'description' => 'Design', 'amount' => 100, 'taxed' => 1]);
    $db->insert('tblaccounts', ['id' => 6, 'userid' => 1, 'gateway' => 'paypal', 'date' => '2026-05-01 00:00:00', 'amountin' => 120, 'transid' => 'PP-6', 'invoiceid' => 17]);
    $db->insert('tblaccounts', ['id' => 7, 'userid' => 1, 'gateway' => 'paypal', 'date' => '2026-05-03 00:00:00', 'amountout' => 120, 'transid' => 'PP-7', 'invoiceid' => 17]);
    // 16: unpaid, partly paid already (20 of 50).
    $db->insert('tblinvoices', ['id' => 16, 'userid' => 1, 'date' => '2026-10-01', 'duedate' => '2026-10-10', 'subtotal' => 50, 'total' => 50, 'status' => 'Unpaid']);
    $db->insert('tblinvoiceitems', ['invoiceid' => 16, 'userid' => 1, 'description' => 'Migration work', 'amount' => 50]);

    $db->insert('tblaccounts', ['id' => 1, 'userid' => 1, 'gateway' => 'paypal', 'date' => '2026-09-02 10:00:00', 'amountin' => 10, 'fees' => 0.59, 'transid' => 'PP-1', 'invoiceid' => 10]);
    $db->insert('tblaccounts', ['id' => 2, 'userid' => 1, 'gateway' => 'paypal', 'date' => '2026-06-10 00:00:00', 'description' => 'Refund', 'amountout' => 20, 'transid' => 'PP-2', 'invoiceid' => 15]);
    $db->insert('tblaccounts', ['id' => 3, 'userid' => 3, 'gateway' => 'banktransfer', 'date' => '2026-08-01 00:00:00', 'amountin' => 5, 'invoiceid' => 13]);
    $db->insert('tblaccounts', ['id' => 4, 'userid' => 2, 'currency' => 2, 'gateway' => 'banktransfer', 'date' => '2026-08-01 09:00:00', 'amountin' => 100, 'invoiceid' => 12]);
    $db->insert('tblaccounts', ['id' => 5, 'userid' => 1, 'gateway' => 'paypal', 'date' => '2026-10-02 00:00:00', 'amountin' => 20, 'transid' => 'PP-5', 'invoiceid' => 16]);

    $db->insert('tblcredit', ['id' => 1, 'clientid' => 1, 'date' => '2026-05-01', 'description' => 'Goodwill credit', 'amount' => 20]);
    $db->insert('tblcredit', ['id' => 2, 'clientid' => 1, 'date' => '2026-10-15', 'description' => 'Credit Applied to Invoice #11', 'amount' => -5, 'relid' => 11]);
    $db->insert('tblcredit', ['id' => 3, 'clientid' => 3, 'date' => '2026-09-01', 'description' => 'Not imported', 'amount' => 5]);

    $db->insert('tblbillingnotes', ['id' => 1, 'note_type' => 'credit', 'custom_number' => '7', 'client_id' => 1, 'date_issued' => '2026-06-10 00:00:00', 'subtotal' => 20, 'total' => 20, 'status' => 'closed', 'notes' => 'Refund of consulting']);
    $db->insert('tblbillingnoteitems', ['billingnote_id' => 1, 'description' => 'Refund for invoice 102', 'amount' => 20]);
    $db->insert('tblbillingnotes', ['id' => 2, 'note_type' => 'credit', 'client_id' => 1, 'date_issued' => '2026-10-01 00:00:00', 'subtotal' => 3, 'total' => 3, 'status' => 'draft']);
    $db->insert('tblbillingnotes', ['id' => 3, 'note_type' => 'debit', 'client_id' => 2, 'date_issued' => '2026-10-02 00:00:00', 'subtotal' => 40, 'total' => 40, 'status' => 'closed']);
    $db->insert('tblbillingnoteitems', ['billingnote_id' => 3, 'description' => 'Extra work', 'amount' => 40]);

    return $db;
}

/** The services target plus invoices, transactions, balances, gateways and settings. */
function whmcsMoneyTarget(): Connection
{
    $db = whmcsServicesTarget();
    $buyer = implode(', ', array_map(fn (string $c): string => "{$c} TEXT", ['seller_company', 'seller_company_vat', 'seller_company_number', 'seller_address', 'seller_phone', 'seller_email', 'buyer_first_name', 'buyer_last_name', 'buyer_company', 'buyer_company_vat', 'buyer_company_number', 'buyer_address', 'buyer_city', 'buyer_state', 'buyer_country', 'buyer_zip', 'buyer_phone', 'buyer_phone_cc', 'buyer_email']));
    foreach ([
        "CREATE TABLE invoice (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, serie TEXT, nr TEXT, hash TEXT, currency TEXT, currency_rate REAL, credit REAL, base_income REAL, base_refund REAL, refund REAL, notes TEXT, text_1 TEXT, text_2 TEXT, status TEXT, {$buyer}, gateway_id INTEGER, approved INTEGER, taxname TEXT, taxrate TEXT, due_at TEXT, reminded_at TEXT, paid_at TEXT, created_at TEXT, updated_at TEXT, hash_expires_at TEXT)",
        'CREATE TABLE invoice_item (id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INTEGER, type TEXT, rel_id TEXT, task TEXT, status TEXT, title TEXT, period TEXT, quantity INTEGER, unit TEXT, price REAL, charged INTEGER, taxed INTEGER, attempts INTEGER, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE "transaction" (id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INTEGER, gateway_id INTEGER, txn_id TEXT, txn_status TEXT, s_id TEXT, s_period TEXT, amount TEXT, currency TEXT, type TEXT, status TEXT, ip TEXT, error TEXT, error_code INTEGER, validate_ipn INTEGER, ipn TEXT, ipn_hash TEXT, output TEXT, note TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE client_balance (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, type TEXT, rel_id TEXT, invoice_item_id INTEGER, amount REAL, description TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE pay_gateway (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, gateway TEXT)',
        'CREATE TABLE setting (id INTEGER PRIMARY KEY AUTOINCREMENT, param TEXT UNIQUE, value TEXT, public INTEGER, category TEXT, hash TEXT, created_at TEXT, updated_at TEXT)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    $db->executeStatement("INSERT INTO pay_gateway (name, gateway) VALUES ('Custom', 'Custom'), ('PayPal', 'PayPalEmail')");
    foreach (['invoice_series' => 'CF', 'invoice_starting_number' => '1', 'invoice_cn_series' => 'CN-', 'invoice_cn_starting_number' => '1', 'company_name' => 'Host Co', 'company_email' => 'billing@host.test', 'invoice_hash_lifetime_days' => '90'] as $param => $value) {
        $db->insert('setting', ['param' => $param, 'value' => $value]);
    }

    return $db;
}

/** Runs the money steps to the end in batches of $batch. */
function whmcsMoneyAll(Connection $source, Connection $target, int $batch = 2): Money
{
    $money = new Money(new Source($source), $target, new Map($target), '2026-10-19 00:00:00');
    foreach (array_keys(Money::STEPS) as $step) {
        $after = 0;
        do {
            [$after] = $money->batch($step, (int) $after, $batch);
        } while ($after !== null);
    }

    return $money;
}

test('invoices keep their ids, numbers and lines; unpaid ones renew their orders when paid', function (): void {
    $source = whmcsMoneySource();
    $target = whmcsMoneyTarget();
    whmcsCatalogueAll($source, $target, Cipher::withKey(WHMCS_CATALOGUE_KEY));
    whmcsServicesAll($source, $target);
    $warnings = implode("\n", whmcsMoneyAll($source, $target)->warnings());
    $hosting = whmcsOrderOf($target, 'service', 1);
    $domain = whmcsOrderOf($target, 'domain', 1);

    $paid = $target->fetchAssociative('SELECT * FROM invoice WHERE id = 10');
    expect($paid)->toMatchArray([
        'client_id' => 1, 'serie' => '', 'nr' => '101', 'currency' => 'USD', 'status' => 'paid', 'approved' => 1, 'notes' => 'Thanks',
        'gateway_id' => (int) $target->fetchOne("SELECT id FROM pay_gateway WHERE gateway = 'PayPalEmail'"), 'base_income' => 10.0,
        'due_at' => '2026-09-05 00:00:00', 'paid_at' => '2026-09-02 10:00:00', 'created_at' => '2026-09-01 00:00:00',
        'seller_company' => 'Host Co', 'buyer_first_name' => 'Avery', 'buyer_email' => 'avery@client.test', 'hash_expires_at' => '2027-01-17 00:00:00',
    ])
        ->and($paid['hash'])->toHaveLength(40)
        ->and($target->fetchAllAssociative('SELECT type, rel_id, task, status, title, price, charged FROM invoice_item WHERE invoice_id = 10 ORDER BY id'))->toBe([
            ['type' => 'order', 'rel_id' => (string) $hosting['id'], 'task' => 'renew', 'status' => 'executed', 'title' => 'Starter - shop.example.co.uk (01/09/2026 - 30/09/2026)', 'price' => 9.0, 'charged' => 1],
            ['type' => 'order', 'rel_id' => (string) whmcsOrderOf($target, 'serviceaddon', 1)['id'], 'task' => 'renew', 'status' => 'executed', 'title' => 'Backups', 'price' => 2.0, 'charged' => 1],
            // The promotion names the service it lowers, as the cart's discount lines do (QA r6 R-01).
            ['type' => 'custom', 'rel_id' => (string) $hosting['id'], 'task' => null, 'status' => 'executed', 'title' => 'Promotion TEN', 'price' => -1.0, 'charged' => 1],
        ])
        ->and($target->fetchOne("SELECT unit FROM invoice_item WHERE invoice_id = 10 AND title = 'Promotion TEN'"))->toBe('discount');

    // The unpaid invoice renews the service and the domain when paid; both orders wait for it.
    // The 5.00 of credit WHMCS applied to it is a line of its own: 25.00 is due, as in WHMCS.
    expect($target->fetchAllAssociative('SELECT type, rel_id, task, status, period, price FROM invoice_item WHERE invoice_id = 11 ORDER BY id'))->toBe([
        ['type' => 'order', 'rel_id' => (string) $hosting['id'], 'task' => 'renew', 'status' => 'pending_payment', 'period' => '1M', 'price' => 9.0],
        ['type' => 'order', 'rel_id' => (string) $domain['id'], 'task' => 'renew', 'status' => 'pending_payment', 'period' => '1Y', 'price' => 21.0],
        ['type' => 'custom', 'rel_id' => null, 'task' => null, 'status' => 'pending_payment', 'period' => null, 'price' => -5.0],
    ])
        ->and((float) $target->fetchOne('SELECT SUM(price) FROM invoice_item WHERE invoice_id = 11'))->toBe(25.0)
        // WHMCS numbers it when paid; here it takes the next number, after WHMCS's 103.
        ->and($target->fetchAssociative('SELECT serie, nr, status, paid_at FROM invoice WHERE id = 11'))->toBe(['serie' => '', 'nr' => '103', 'status' => 'unpaid', 'paid_at' => null])
        ->and(whmcsOrderOf($target, 'service', 1)['unpaid_invoice_id'])->toBe(11)
        ->and(whmcsOrderOf($target, 'domain', 1)['unpaid_invoice_id'])->toBe(11);

    // A prefixed number keeps its prefix; funds are a deposit; EGP income counts in USD.
    expect($target->fetchAssociative('SELECT serie, nr, currency, base_income, gateway_id FROM invoice WHERE id = 12'))->toBe(['serie' => 'INV-', 'nr' => '0102', 'currency' => 'EGP', 'base_income' => 2.0, 'gateway_id' => null])
        ->and($target->fetchOne('SELECT type FROM invoice_item WHERE invoice_id = 12'))->toBe('deposit')
        ->and($target->fetchOne('SELECT COUNT(*) FROM invoice WHERE id = 13'))->toBe(0)
        ->and($target->fetchAssociative('SELECT status, paid_at FROM invoice WHERE id = 14'))->toBe(['status' => 'canceled', 'paid_at' => null])
        ->and($target->fetchAssociative('SELECT status, refund, base_refund FROM invoice WHERE id = 15'))->toBe(['status' => 'refunded', 'refund' => 20.0, 'base_refund' => 20.0])
        // Partly paid: only the rest is due.
        ->and($target->fetchAllAssociative('SELECT title, price, unit FROM invoice_item WHERE invoice_id = 16 ORDER BY id'))->toBe([['title' => 'Migration work', 'price' => 50.0, 'unit' => null], ['title' => 'Payments received', 'price' => -20.0, 'unit' => Money::RECEIVED_UNIT]])
        ->and($target->fetchOne('SELECT nr FROM invoice WHERE id = 16'))->toBe('104')
        ->and($target->fetchAssociative('SELECT base_income, base_refund, refund, taxrate FROM invoice WHERE id = 17'))->toBe(['base_income' => 100.0, 'base_refund' => 100.0, 'refund' => 100.0, 'taxrate' => '20'])
        ->and($target->fetchOne('SELECT nr FROM invoice WHERE id = 14'))->toBeNull();

    // New invoices continue WHMCS's numbering, without a prefix.
    // (shown as long as WHMCS's zero-padded "0102")
    expect($target->fetchAllKeyValue("SELECT param, value FROM setting WHERE param IN ('invoice_series', 'invoice_series_paid', 'invoice_starting_number', 'invoice_number_padding') ORDER BY param"))->toBe([
        // 103 and 104 went to the unpaid invoices, 105 to the debit note.
        'invoice_number_padding' => '4', 'invoice_series' => '', 'invoice_series_paid' => '', 'invoice_starting_number' => '106',
    ])
        ->and($warnings)->toContain('New invoices continue WHMCS\'s numbering at 105')
        ->toContain('2 unpaid invoice(s) had no WHMCS number yet')
        ->toContain('Invoice #16 was partly paid in WHMCS (20.00 USD): the rest is due.')
        ->toContain('Invoice #13 was not imported: its client was not imported.')
        ->toContain('Payment gateway "banktransfer": this install has no such gateway');
});

test('transactions, the credit log, balances and credit notes', function (): void {
    $source = whmcsMoneySource();
    $target = whmcsMoneyTarget();
    whmcsCatalogueAll($source, $target, Cipher::withKey(WHMCS_CATALOGUE_KEY));
    whmcsServicesAll($source, $target);
    $warnings = implode("\n", whmcsMoneyAll($source, $target)->warnings());

    // Transactions are history: processed, so never applied again.
    expect($target->fetchAllAssociative('SELECT invoice_id, txn_id, amount, currency, type, status, validate_ipn, note FROM "transaction" ORDER BY id'))->toBe([
        ['invoice_id' => 10, 'txn_id' => 'PP-1', 'amount' => '10.00', 'currency' => 'USD', 'type' => 'payment', 'status' => 'processed', 'validate_ipn' => 0, 'note' => "Fees: 0.59\nWHMCS gateway: paypal"],
        ['invoice_id' => 15, 'txn_id' => 'PP-2', 'amount' => '20.00', 'currency' => 'USD', 'type' => 'refund', 'status' => 'processed', 'validate_ipn' => 0, 'note' => "Refund\nWHMCS gateway: paypal"],
        ['invoice_id' => null, 'txn_id' => null, 'amount' => '5.00', 'currency' => 'USD', 'type' => 'payment', 'status' => 'processed', 'validate_ipn' => 0, 'note' => "WHMCS gateway: banktransfer\nWHMCS invoice #13 (not imported)"],
        ['invoice_id' => 12, 'txn_id' => null, 'amount' => '100.00', 'currency' => 'EGP', 'type' => 'payment', 'status' => 'processed', 'validate_ipn' => 0, 'note' => 'WHMCS gateway: banktransfer'],
        ['invoice_id' => 16, 'txn_id' => 'PP-5', 'amount' => '20.00', 'currency' => 'USD', 'type' => 'payment', 'status' => 'processed', 'validate_ipn' => 0, 'note' => 'WHMCS gateway: paypal'],
        ['invoice_id' => 17, 'txn_id' => 'PP-6', 'amount' => '120.00', 'currency' => 'USD', 'type' => 'payment', 'status' => 'processed', 'validate_ipn' => 0, 'note' => 'WHMCS gateway: paypal'],
        ['invoice_id' => 17, 'txn_id' => 'PP-7', 'amount' => '120.00', 'currency' => 'USD', 'type' => 'refund', 'status' => 'processed', 'validate_ipn' => 0, 'note' => 'WHMCS gateway: paypal'],
    ]);

    // Client 1's log adds up to its 15.00; client 2 gets an opening balance of 50.00.
    expect($target->fetchAllAssociative('SELECT client_id, type, rel_id, amount, description FROM client_balance ORDER BY id'))->toBe([
        ['client_id' => 1, 'type' => 'default', 'rel_id' => null, 'amount' => 20.0, 'description' => 'Goodwill credit'],
        ['client_id' => 1, 'type' => 'invoice', 'rel_id' => '11', 'amount' => -5.0, 'description' => 'Credit Applied to Invoice #11'],
        ['client_id' => 2, 'type' => 'default', 'rel_id' => null, 'amount' => 50.0, 'description' => 'Opening balance from WHMCS'],
    ]);

    // A credit note is a refund invoice with negative lines in the credit note series; a debit note an invoice.
    $credit = $target->fetchAssociative("SELECT * FROM invoice WHERE serie = 'CN-'");
    // (a document: the refund already counts on invoice 15)
    expect($credit)->toMatchArray(['client_id' => 1, 'nr' => '7', 'status' => 'refunded', 'paid_at' => '2026-06-10 00:00:00', 'base_income' => null])
        ->and($credit['notes'])->toContain('Refund of consulting')
        ->and($target->fetchAllAssociative('SELECT title, price, status FROM invoice_item WHERE invoice_id = ?', [$credit['id']]))->toBe([['title' => 'Refund for invoice 102', 'price' => -20.0, 'status' => 'executed']])
        ->and($target->fetchOne("SELECT value FROM setting WHERE param = 'invoice_cn_starting_number'"))->toBe('8')
        ->and($target->fetchOne("SELECT COUNT(*) FROM invoice WHERE notes LIKE 'Credit note #2%'"))->toBe(0)
        ->and($target->fetchAssociative("SELECT client_id, serie, nr, status FROM invoice WHERE notes LIKE 'Debit note%'"))->toBe(['client_id' => 2, 'serie' => '', 'nr' => '105', 'status' => 'paid'])
        ->and($warnings)->toContain('Credit note #2 was not imported: it is a draft.')
        ->toContain('Debit note #3 is invoice #');

    // A second run imports nothing again.
    whmcsMoneyAll($source, $target);
    expect((int) $target->fetchOne('SELECT COUNT(*) FROM invoice'))->toBe(9)
        ->and((int) $target->fetchOne('SELECT COUNT(*) FROM "transaction"'))->toBe(7)
        ->and((int) $target->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(3);
});

test('WHMCS invoice numbers split into series and number', function (): void {
    expect(Money::number('408'))->toBe(['', '408'])
        ->and(Money::number('INV-2026-0012'))->toBe(['INV-2026-', '0012'])
        ->and(Money::number(''))->toBe(['', null])
        ->and(Money::number('DRAFT'))->toBe(['', null]);
});

// ClientFence (QA r4 W-08): WHMCS's tax rules come over (first level), with its tax switch and Inclusive Tax.
test('tax rules and the tax switch', function (string $taxType, string $taxDomains, ?string $switch, string $warning, int $productTax = 1): void {
    $source = whmcsCatalogueSource();
    $source->executeStatement('ALTER TABLE tblproducts ADD COLUMN tax INTEGER DEFAULT 1');
    $source->executeStatement('UPDATE tblproducts SET tax = ? WHERE id = 1', [$productTax]);
    $source->executeStatement('CREATE TABLE tbltax (id INTEGER PRIMARY KEY, level INTEGER, name TEXT, state TEXT, country TEXT, taxrate REAL)');
    $source->insert('tbltax', ['id' => 1, 'level' => 1, 'name' => 'VAT', 'state' => '', 'country' => 'gb', 'taxrate' => 20]);
    $source->insert('tbltax', ['id' => 2, 'level' => 1, 'name' => 'Sales tax', 'state' => 'Texas', 'country' => 'US', 'taxrate' => 6.25]);
    $source->insert('tbltax', ['id' => 3, 'level' => 2, 'name' => 'Extra', 'state' => '', 'country' => '', 'taxrate' => 5]);
    $source->executeStatement("INSERT INTO tblconfiguration VALUES ('TaxEnabled', 'on'), ('TaxType', ?), ('TaxDomains', ?)", [$taxType, $taxDomains]);
    $target = whmcsCatalogueTarget();
    $target->executeStatement('CREATE TABLE setting (id INTEGER PRIMARY KEY AUTOINCREMENT, param TEXT UNIQUE, value TEXT, public INTEGER, created_at TEXT, updated_at TEXT)');
    $catalogue = whmcsCatalogueAll($source, $target, Cipher::withKey(WHMCS_CATALOGUE_KEY));
    whmcsCatalogueAll($source, $target, Cipher::withKey(WHMCS_CATALOGUE_KEY));

    expect($target->fetchAllAssociative('SELECT level, name, country, state, taxrate FROM tax ORDER BY id'))->toBe([
        ['level' => 1, 'name' => 'VAT', 'country' => 'GB', 'state' => null, 'taxrate' => '20'],
        ['level' => 1, 'name' => 'Sales tax', 'country' => 'US', 'state' => 'Texas', 'taxrate' => '6.25'],
    ])
        ->and($target->fetchOne("SELECT value FROM setting WHERE param = 'tax_enabled'"))->toBe($switch ?? false)
        ->and($target->fetchOne("SELECT value FROM setting WHERE param = 'tax_inclusive'"))->toBe($switch === null ? false : ($taxType === 'Inclusive' ? '1' : '0'))
        ->and(implode("\n", $catalogue->warnings()))->toContain('Tax rule "Extra" is a second-level tax')->toContain($warning);
})->with([
    'exclusive, everything taxed' => ['Exclusive', 'on', '1', ''],
    'inclusive' => ['Inclusive', 'on', '1', ''],
    // QA r4 review: ClientFence taxes every item, so untaxed domains keep tax off.
    'domains untaxed' => ['Exclusive', '', null, 'WHMCS did not tax some items (domains)'],
    'a product untaxed' => ['Exclusive', 'on', null, 'WHMCS did not tax some items (1 products)', 0],
]);

/** An imported invoice's total as Invoice\Service::getTotalWithTax() makes it. */
function whmcsInvoiceTotal(Connection $target, int $id): float
{
    $rate = (float) $target->fetchOne('SELECT taxrate FROM invoice WHERE id = ?', [$id]);
    $lines = $taxed = 0.0;
    foreach ($target->fetchAllAssociative("SELECT price, quantity, taxed FROM invoice_item WHERE invoice_id = ? AND (unit IS NULL OR unit <> 'received')", [$id]) as $item) {
        $lines += $item['price'] * $item['quantity'];
        $taxed += $item['taxed'] ? $item['price'] * $item['quantity'] : 0;
    }

    return round($lines + round($taxed * $rate / 100, 2), 2);
}

// ClientFence (QA r4 W-01/W-02): WHMCS's total stays when its tax was compound (a second tax) or inside the prices.
test('invoices keep the WHMCS total with a second tax or with tax inside the prices', function (string $taxType): void {
    $source = whmcsMoneySource();
    $source->executeStatement("INSERT INTO tblconfiguration VALUES ('TaxType', ?)", [$taxType]);
    $inclusive = $taxType === 'Inclusive';
    // 30: 10% then 5% compound on 100 net; 31: 20% on two taxed lines and an untaxed one, odd cents.
    $source->insert('tblinvoices', ['id' => 30, 'userid' => 1, 'date' => '2026-09-01', 'duedate' => '2026-09-05', 'subtotal' => 100, 'tax' => 10, 'tax2' => 5.5, 'taxrate' => 10, 'total' => 115.5, 'status' => 'Unpaid']);
    $source->insert('tblinvoiceitems', ['invoiceid' => 30, 'userid' => 1, 'description' => 'Work', 'amount' => $inclusive ? 115.5 : 100, 'taxed' => 1]);
    $source->insert('tblinvoices', ['id' => 31, 'userid' => 1, 'date' => '2026-09-01', 'duedate' => '2026-09-05', 'subtotal' => 23.33, 'tax' => 4.04, 'taxrate' => 20, 'total' => 27.37, 'status' => 'Unpaid']);
    $source->insert('tblinvoiceitems', ['invoiceid' => 31, 'userid' => 1, 'description' => 'A', 'amount' => $inclusive ? 12.12 : 10.10, 'taxed' => 1]);
    $source->insert('tblinvoiceitems', ['invoiceid' => 31, 'userid' => 1, 'description' => 'B', 'amount' => $inclusive ? 12.13 : 10.11, 'taxed' => 1]);
    $source->insert('tblinvoiceitems', ['invoiceid' => 31, 'userid' => 1, 'description' => 'C', 'amount' => 3.12, 'taxed' => 0]);
    $target = whmcsMoneyTarget();
    $money = whmcsMoneyAll($source, $target);

    expect(whmcsInvoiceTotal($target, 30))->toBe(115.5)
        ->and(whmcsInvoiceTotal($target, 31))->toBe(27.37)
        ->and((float) $target->fetchOne('SELECT taxrate FROM invoice WHERE id = 30'))->toBe(15.5)
        ->and((float) $target->fetchOne('SELECT taxrate FROM invoice WHERE id = 31'))->toBe(20.0)
        ->and(implode("\n", $money->warnings()))->toContain('Invoice #30 had a second tax (5.5): ClientFence keeps one tax rate, so it shows both as 15.5%');
    if ($inclusive) {
        expect((float) $target->fetchOne('SELECT price FROM invoice_item WHERE invoice_id = 30 AND taxed = 1'))->toBe(100.0);
    }
})->with(['Exclusive', 'Inclusive']);
