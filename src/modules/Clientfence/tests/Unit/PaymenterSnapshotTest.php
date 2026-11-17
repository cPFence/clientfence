<?php

declare(strict_types=1);

/**
 * ClientFence: the Paymenter import's read (Paymenter\Snapshot) from an invented Paymenter database
 * (its tables as Paymenter 1.5 names them, on SQLite) into the WHMCS-shaped file the WHMCS import
 * reads.
 */

use ClientFence\Mod\Clientfence\Paymenter\Snapshot;
use ClientFence\Mod\Clientfence\Whmcs\Import;
use ClientFence\Mod\Clientfence\Whmcs\Inventory;
use ClientFence\Mod\Clientfence\Whmcs\Run;
use ClientFence\Mod\Clientfence\Whmcs\Source;
use Doctrine\DBAL\DriverManager;

/** Paymenter's tables (the columns the read uses) and rows, keyed by table. */
function paymenterFake(string $taxType): array
{
    $plan = fn (int $id, string $model, int $item, string $type, ?int $period = null, ?string $unit = null): array => ['id' => $id, 'name' => null, 'priceable_type' => 'App\Models\\' . $model, 'priceable_id' => $item, 'type' => $type, 'billing_period' => $period, 'billing_unit' => $unit, 'sort' => $id];
    $price = fn (int $id, int $plan, string $currency, float $price, float $setup = 0.0): array => ['id' => $id, 'plan_id' => $plan, 'currency_code' => $currency, 'price' => $price, 'setup_fee' => $setup];
    $prop = fn (int $id, string $model, int $of, string $key, string $value, ?int $custom = null, ?string $name = null): array => ['id' => $id, 'custom_property_id' => $custom, 'name' => $name, 'key' => $key, 'value' => $value, 'model_type' => 'App\Models\\' . $model, 'model_id' => $of];
    $service = fn (int $id, int $user, int $product, int $plan, float $price, ?string $expires, int $qty = 1, ?string $subscription = null, ?string $label = null, ?int $coupon = null): array => ['id' => $id, 'coupon_id' => $coupon, 'status' => 'active', 'product_id' => $product, 'user_id' => $user, 'currency_code' => 'USD', 'quantity' => $qty, 'price' => $price, 'plan_id' => $plan, 'expires_at' => $expires, 'subscription_id' => $subscription, 'created_at' => '2025-01-05 10:00:00', 'label' => $label];
    $invoice = fn (int $id, int $user, ?string $number, string $status, string $currency = 'USD'): array => ['id' => $id, 'number' => $number, 'status' => $status, 'due_at' => '2026-11-08', 'currency_code' => $currency, 'user_id' => $user, 'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00'];
    $item = fn (int $id, int $invoice, float $price, int $qty, string $text, ?string $type = null, ?int $ref = null): array => ['id' => $id, 'invoice_id' => $invoice, 'price' => $price, 'quantity' => $qty, 'description' => $text, 'reference_type' => $type === null ? null : 'App\Models\\' . $type, 'reference_id' => $ref];
    $tx = fn (int $id, int $invoice, ?int $gateway, float $amount, ?string $ref, string $status = 'succeeded', int $credit = 0, ?float $fee = null): array => ['id' => $id, 'invoice_id' => $invoice, 'gateway_id' => $gateway, 'amount' => $amount, 'fee' => $fee, 'transaction_id' => $ref, 'status' => $status, 'is_credit_transaction' => $credit, 'created_at' => '2026-11-02 09:00:00'];

    return [
        'settings' => [
            ['key' => 'tax_enabled', 'value' => '1', 'settingable_type' => null], ['key' => 'tax_type', 'value' => $taxType, 'settingable_type' => null],
            ['key' => 'default_currency', 'value' => 'USD', 'settingable_type' => null], ['key' => 'ticket_departments', 'value' => '["Support","Sales"]', 'settingable_type' => null],
        ],
        'currencies' => [['code' => 'EUR', 'prefix' => '', 'suffix' => ' €'], ['code' => 'USD', 'prefix' => '$', 'suffix' => '']],
        'tax_rates' => [['id' => 1, 'name' => 'VAT', 'rate' => 20.0, 'country' => 'GB'], ['id' => 2, 'name' => 'Tax', 'rate' => 10.0, 'country' => 'all']],
        'categories' => [['id' => 1, 'name' => 'Hosting', 'slug' => 'hosting', 'sort' => 1]],
        'config_options' => [
            ['id' => 10, 'name' => 'Disk', 'description' => null, 'env_variable' => 'disk', 'type' => 'select', 'sort' => 1, 'hidden' => 0, 'parent_id' => null],
            ['id' => 11, 'name' => '10 GB', 'description' => null, 'env_variable' => null, 'type' => null, 'sort' => 1, 'hidden' => 0, 'parent_id' => 10],
            ['id' => 12, 'name' => '20 GB', 'description' => null, 'env_variable' => null, 'type' => null, 'sort' => 2, 'hidden' => 0, 'parent_id' => 10],
            ['id' => 13, 'name' => 'Hostname', 'description' => null, 'env_variable' => 'hostname', 'type' => 'text', 'sort' => 2, 'hidden' => 0, 'parent_id' => null],
            // A checkbox: its one child is the price of ticking it.
            ['id' => 14, 'name' => 'Backups', 'description' => null, 'env_variable' => 'backups', 'type' => 'checkbox', 'sort' => 3, 'hidden' => 0, 'parent_id' => null],
            ['id' => 15, 'name' => 'Backups', 'description' => null, 'env_variable' => null, 'type' => null, 'sort' => 1, 'hidden' => 0, 'parent_id' => 14],
        ],
        'config_option_products' => [['id' => 1, 'config_option_id' => 10, 'product_id' => 1], ['id' => 2, 'config_option_id' => 14, 'product_id' => 1]],
        'products' => [
            ['id' => 1, 'category_id' => 1, 'name' => 'Starter', 'slug' => 'starter', 'description' => '<p>Small sites</p>', 'hidden' => 0, 'stock' => null, 'allow_quantity' => 'combined', 'sort' => 1],
            ['id' => 2, 'category_id' => 1, 'name' => 'Setup', 'slug' => 'setup', 'description' => '', 'hidden' => 1, 'stock' => 5, 'allow_quantity' => 'disabled', 'sort' => 2],
        ],
        'plans' => [
            $plan(1, 'Product', 1, 'recurring', 1, 'month'), $plan(2, 'Product', 1, 'recurring', 1, 'year'), $plan(3, 'Product', 1, 'recurring', 1, 'week'),
            $plan(4, 'Product', 2, 'one-time'), $plan(5, 'ConfigOption', 12, 'recurring', 1, 'month'),
        ],
        'prices' => [$price(1, 1, 'USD', 5.0, 2.0), $price(2, 1, 'EUR', 4.5), $price(3, 2, 'USD', 50.0), $price(4, 3, 'USD', 2.0), $price(5, 4, 'USD', 49.0), $price(6, 5, 'USD', 3.0)],
        'product_upgrades' => [['id' => 1, 'product_id' => 1, 'upgrade_id' => 2]],
        'users' => [
            ['id' => 100, 'first_name' => 'Sam', 'last_name' => 'Ops', 'email' => 'staff@example.test', 'role_id' => 1, 'password' => 'x', 'tfa_secret' => null, 'created_at' => '2024-01-01 10:00:00'],
            ['id' => 101, 'first_name' => 'Ann', 'last_name' => 'Lee', 'email' => 'ann@example.test', 'role_id' => null, 'password' => password_hash('secret', PASSWORD_BCRYPT), 'tfa_secret' => null, 'created_at' => '2025-01-05 10:00:00'],
            ['id' => 102, 'first_name' => 'Eve', 'last_name' => 'Fox', 'email' => 'eve@example.test', 'role_id' => null, 'password' => 'x', 'tfa_secret' => 'enc', 'created_at' => '2025-02-01 10:00:00'],
            ['id' => 103, 'first_name' => 'Petr', 'last_name' => 'Novak', 'email' => 'petr@example.test', 'role_id' => null, 'password' => 'x', 'tfa_secret' => null, 'created_at' => '2025-03-01 10:00:00'],
        ],
        'custom_properties' => [['id' => 1, 'name' => 'Company name', 'key' => 'company_name'], ['id' => 3, 'name' => 'VAT number', 'key' => 'vat']],
        'properties' => [
            $prop(1, 'User', 101, 'company_name', 'Lee Ltd', 1), $prop(2, 'User', 101, 'address', '1 Road'), $prop(3, 'User', 101, 'city', 'London'), $prop(4, 'User', 101, 'zip', 'N1'),
            $prop(5, 'User', 101, 'country', 'United Kingdom'), $prop(6, 'User', 101, 'phone', '+44 20 7123 4567'), $prop(7, 'User', 101, 'vat', 'GB123', 3),
            $prop(8, 'User', 102, 'country', 'Nowhere'),
            // Paymenter's own name for Czechia, which is not Symfony's.
            $prop(11, 'User', 103, 'country', 'Czech Republic'),
            $prop(9, 'Service', 1, 'domain', 'site.example', null, 'Domain'), $prop(10, 'Service', 1, 'hostname', 'box1', null, 'Hostname'),
        ],
        'credits' => [['id' => 1, 'user_id' => 101, 'currency_code' => 'USD', 'amount' => 25.0], ['id' => 2, 'user_id' => 101, 'currency_code' => 'EUR', 'amount' => 3.0]],
        // Prices hold the tax: Ann is in the United Kingdom (20%).
        'services' => [
            $service(1, 101, 1, 1, 6.0, '2026-12-05', 1, 'sub_1', 'My site'), $service(2, 101, 1, 3, 2.4, '2026-11-12'),
            $service(3, 101, 2, 4, 58.8, null), $service(4, 101, 1, 2, 60.0, '2027-01-05', 2, null, null, 1),
            // Staff's own service; a service whose plan was deleted.
            $service(5, 100, 1, 1, 6.0, '2026-12-05'), $service(6, 101, 1, 99, 6.0, '2026-12-05'),
        ],
        'service_cancellations' => [['id' => 1, 'service_id' => 4, 'reason' => 'Too slow', 'type' => 'end_of_period', 'created_at' => '2026-11-03 10:00:00']],
        'service_configs' => [['id' => 1, 'configurable_type' => 'App\Models\Service', 'configurable_id' => 1, 'config_option_id' => 10, 'config_value_id' => 12], ['id' => 2, 'configurable_type' => 'App\Models\Service', 'configurable_id' => 1, 'config_option_id' => 14, 'config_value_id' => 15]],
        'invoices' => [
            $invoice(1, 101, 'INV-0001', 'paid'), $invoice(2, 101, null, 'pending', 'EUR'), $invoice(3, 101, 'INV-0002', 'paid'),
            $invoice(4, 101, null, 'pending'), $invoice(5, 100, 'INV-0003', 'pending'), $invoice(6, 101, 'INV-0004', 'void'),
        ],
        'invoice_items' => [
            $item(1, 1, 6.0, 1, 'Starter', 'Service', 1), $item(2, 1, 2.4, 1, 'Setup fee', 'Service', 1),
            $item(3, 2, 30.0, 2, 'Starter', 'Service', 4), $item(4, 3, 10.0, 1, 'Credit deposit', 'Credit'),
            $item(5, 4, 12.0, 1, 'Upgrade Starter to Setup', 'ServiceUpgrade', 1), $item(6, 5, 1.0, 1, 'Staff'), $item(7, 6, 1.0, 1, 'Odd'),
        ],
        // Invoice 1 was paid at 20%; invoice 3 has no snapshot, so the client's rate now applies.
        'invoice_snapshots' => [['id' => 1, 'invoice_id' => 1, 'tax_name' => 'VAT', 'tax_rate' => 20.0]],
        'extensions' => [
            ['id' => 1, 'extension' => 'Stripe', 'type' => 'gateway', 'deleted_at' => null], ['id' => 2, 'extension' => 'PayPal', 'type' => 'gateway', 'deleted_at' => null],
            ['id' => 3, 'extension' => 'Pterodactyl', 'type' => 'server', 'deleted_at' => null],
        ],
        'invoice_transactions' => [
            $tx(1, 1, 1, 8.4, 'ch_1', 'succeeded', 0, 0.5),
            // Invoice 2: part from credit, part paid by hand, one still processing, one failed.
            $tx(2, 2, null, 20.0, null, 'succeeded', 1), $tx(3, 2, null, 15.0, null), $tx(4, 2, 1, 5.0, 'ch_4', 'processing'), $tx(5, 2, 1, 9.0, 'ch_5', 'failed'),
            $tx(6, 3, 2, 10.0, 'PP-1'),
        ],
        'coupons' => [['id' => 1, 'recurring' => 3]],
        'billing_agreements' => [['id' => 1, 'deleted_at' => null], ['id' => 2, 'deleted_at' => '2026-01-01 00:00:00']],
        'ticket_message_attachments' => [['id' => 1]],
        'tickets' => [
            ['id' => 7, 'subject' => 'Help', 'status' => 'replied', 'priority' => 'high', 'department' => 'Sales', 'user_id' => 101, 'service_id' => 1, 'created_at' => '2026-11-05 09:00:00'],
            ['id' => 8, 'subject' => 'Old', 'status' => 'closed', 'priority' => 'normal', 'department' => null, 'user_id' => 101, 'service_id' => null, 'created_at' => '2026-11-06 09:00:00'],
        ],
        'ticket_messages' => [
            ['id' => 1, 'ticket_id' => 7, 'user_id' => 101, 'message' => 'My site is down.', 'created_at' => '2026-11-05 09:00:00'],
            ['id' => 2, 'ticket_id' => 7, 'user_id' => 100, 'message' => 'We are on it.', 'created_at' => '2026-11-05 09:30:00'],
            ['id' => 3, 'ticket_id' => 7, 'user_id' => 101, 'message' => 'Thanks.', 'created_at' => '2026-11-05 10:00:00'],
            ['id' => 4, 'ticket_id' => 8, 'user_id' => 101, 'message' => 'Hi', 'created_at' => '2026-11-06 09:00:00'],
        ],
    ];
}

/** The fake Paymenter as an SQLite file, and the run state on an in-memory database. */
function paymenterDi(string $taxType = 'exclusive'): array
{
    $dir = sys_get_temp_dir() . '/cf-paymenter-test-' . getmypid();
    putenv('CLIENTFENCE_BACKUP_DIR=' . $dir . '/backups');
    @mkdir($dir, 0700, true);
    @unlink($dir . '/paymenter.sqlite');
    $pay = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dir . '/paymenter.sqlite']);
    foreach (paymenterFake($taxType) as $table => $rows) {
        // Typed as the first row's values, so numbers compare as numbers (as in MySQL).
        $first = $rows[0];
        $pay->executeStatement(sprintf('CREATE TABLE "%s" (%s)', $table, implode(', ', array_map(fn (string $c): string => sprintf('"%s" %s', $c, is_int($first[$c]) ? 'INTEGER' : (is_float($first[$c]) ? 'REAL' : '')), array_keys($first)))));
        foreach ($rows as $row) {
            $pay->insert('"' . $table . '"', array_combine(array_map(fn (string $c): string => '"' . $c . '"', array_keys($row)), $row));
        }
    }
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE mod_clientfence_state (name TEXT PRIMARY KEY, value TEXT, updated_at TEXT)');
    $db->executeStatement('CREATE TABLE ' . Run::WARNINGS . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT, step TEXT, message TEXT, created_at TEXT)');
    $di = new Pimple\Container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;

    return [$di, new Source($pay)];
}

afterEach(function (): void {
    $dir = sys_get_temp_dir() . '/cf-paymenter-test-' . getmypid();
    @unlink(Snapshot::path());
    @rmdir(dirname(Snapshot::path()));
    @unlink($dir . '/paymenter.sqlite');
    @rmdir($dir . '/backups');
    @rmdir($dir);
    putenv('CLIENTFENCE_BACKUP_DIR');
});

test('Paymenter is read a page at a time into WHMCS\'s tables, and what cannot come over is left out with a warning', function (): void {
    [$di, $source] = paymenterDi();
    $calls = 0;
    do {
        $state = Snapshot::fetch($di, [], 0.000001, false, $source);
        ++$calls;
    } while ($state['finished_at'] === null && $calls < 50);
    $db = Snapshot::open();
    $warnings = implode("\n", Run::warnings($di['em']->getConnection()));

    // Clients: the users without a staff role, their details from their properties, the credit in their currency.
    expect($state['finished_at'])->not->toBeNull()
        ->and($calls)->toBeGreaterThan(5)
        ->and(substr(sprintf('%o', fileperms(Snapshot::path())), -4))->toBe('0600')
        ->and($db->fetchFirstColumn('SELECT id FROM tblclients ORDER BY id'))->toBe([101, 102, 103])
        ->and($db->fetchOne('SELECT country FROM tblclients WHERE id = 103'))->toBe('CZ')
        ->and($db->fetchAssociative('SELECT firstname, companyname, address1, city, postcode, country, phonenumber, credit, status, datecreated FROM tblclients WHERE id = 101'))
        ->toBe(['firstname' => 'Ann', 'companyname' => 'Lee Ltd', 'address1' => '1 Road', 'city' => 'London', 'postcode' => 'N1', 'country' => 'GB', 'phonenumber' => '+44 20 7123 4567', 'credit' => 25.0, 'status' => 'Active', 'datecreated' => '2025-01-05'])
        ->and($db->fetchAllKeyValue('SELECT c.id, cur.code FROM tblclients c JOIN tblcurrencies cur ON cur.id = c.currency ORDER BY c.id'))->toBe([101 => 'USD', 102 => 'USD', 103 => 'USD'])
        ->and($warnings)->toContain('Client #101 has 3.00 EUR of credit in Paymenter, but is billed in USD')
        ->and($warnings)->toContain('Client #102: its country "Nowhere" is not known')
        ->and($warnings)->toContain('Client eve@example.test used two-factor sign-in in Paymenter')
        ->and(password_verify('secret', (string) $db->fetchOne('SELECT password FROM tblusers WHERE id = 101')))->toBeTrue()
        ->and($db->fetchFirstColumn('SELECT note FROM tblnotes'))->toBe(['VAT number: GB123'])
        ->and($db->fetchAllKeyValue('SELECT code, prefix || \'|\' || suffix FROM tblcurrencies ORDER BY code'))->toBe(['EUR' => '| €', 'USD' => '$|'])
        ->and($db->fetchAllAssociative('SELECT name, country, taxrate FROM tbltax ORDER BY id'))->toBe([['name' => 'VAT', 'country' => 'GB', 'taxrate' => 20.0], ['name' => 'Tax', 'country' => '', 'taxrate' => 10.0]])
        ->and($db->fetchAllKeyValue("SELECT setting, value FROM tblconfiguration WHERE setting LIKE 'Tax%' ORDER BY setting"))->toBe(['TaxDomains' => 'on', 'TaxEnabled' => 'on', 'TaxType' => 'Exclusive'])
        ->and($db->fetchAllKeyValue('SELECT id, name FROM tblticketdepartments ORDER BY id'))->toBe([1 => 'Support', 2 => 'Sales'])
        ->and($warnings)->toContain('Paymenter has 1 coupons: they were not imported.')
        ->and($warnings)->toContain('Paymenter has 1 servers: they were not imported.')
        ->and($warnings)->toContain('Paymenter has 1 saved payment methods: they were not imported.')
        ->and($warnings)->toContain('Paymenter has 1 services renewed by a gateway subscription')
        ->and($warnings)->toContain('Paymenter has 1 ticket attachments: they were not imported.')
        ->and($warnings)->toContain('Paymenter has 1 text and number options');

    // The catalogue: a plan per billing period; options as option groups with their values' prices.
    expect($db->fetchAllAssociative('SELECT id, name, gid, hidden, paytype, stockcontrol, qty, allowqty FROM tblproducts ORDER BY id'))->toBe([
        ['id' => 1, 'name' => 'Starter', 'gid' => 1, 'hidden' => 0, 'paytype' => 'recurring', 'stockcontrol' => 0, 'qty' => 0, 'allowqty' => 1],
        ['id' => 2, 'name' => 'Setup', 'gid' => 1, 'hidden' => 1, 'paytype' => 'onetime', 'stockcontrol' => 1, 'qty' => 5, 'allowqty' => 0],
    ])
        ->and($db->fetchFirstColumn('SELECT name FROM tblproductgroups'))->toBe(['Hosting'])
        ->and($db->fetchAssociative("SELECT p.monthly, p.annually, p.biennially, p.msetupfee FROM tblpricing p JOIN tblcurrencies c ON c.id = p.currency WHERE p.type = 'product' AND p.relid = 1 AND c.code = 'USD'"))->toBe(['monthly' => 5.0, 'annually' => 50.0, 'biennially' => -1.0, 'msetupfee' => 2.0])
        ->and($db->fetchOne("SELECT p.monthly FROM tblpricing p JOIN tblcurrencies c ON c.id = p.currency WHERE p.type = 'product' AND p.relid = 1 AND c.code = 'EUR'"))->toBe(4.5)
        ->and($db->fetchOne("SELECT monthly FROM tblpricing WHERE type = 'product' AND relid = 2"))->toBe(49.0)
        ->and($warnings)->toContain('Product "Starter": its 1 week price was not imported')
        ->and($db->fetchAllAssociative('SELECT product_id, upgrade_product_id FROM tblproduct_upgrade_products'))->toBe([['product_id' => 1, 'upgrade_product_id' => 2]])
        ->and($db->fetchAllAssociative('SELECT id, gid, optionname, optiontype FROM tblproductconfigoptions'))->toBe([['id' => 10, 'gid' => 10, 'optionname' => 'disk|Disk', 'optiontype' => 1], ['id' => 14, 'gid' => 14, 'optionname' => 'backups|Backups', 'optiontype' => 3]])
        ->and($db->fetchAllKeyValue('SELECT id, optionname FROM tblproductconfigoptionssub ORDER BY id'))->toBe([11 => '10 GB', 12 => '20 GB', 15 => 'Backups'])
        ->and($db->fetchAllAssociative('SELECT gid, pid FROM tblproductconfiglinks'))->toBe([['gid' => 10, 'pid' => 1], ['gid' => 14, 'pid' => 1]])
        ->and($db->fetchAllAssociative("SELECT relid, monthly FROM tblpricing WHERE type = 'configoptions'"))->toBe([['relid' => 12, 'monthly' => 3.0]]);

    // Services: Paymenter's price holds the tax; with exclusive tax the amount leaves it out (Ann: 20%).
    expect($db->fetchAllAssociative('SELECT id, packageid, qty, amount, billingcycle, domainstatus, nextduedate, domain, notes FROM tblhosting ORDER BY id'))->toBe([
        ['id' => 1, 'packageid' => 1, 'qty' => 1, 'amount' => 5.0, 'billingcycle' => 'Monthly', 'domainstatus' => 'Active', 'nextduedate' => '2026-12-05', 'domain' => 'site.example', 'notes' => "My site\nDomain: site.example\nHostname: box1"],
        ['id' => 3, 'packageid' => 2, 'qty' => 1, 'amount' => 49.0, 'billingcycle' => 'One Time', 'domainstatus' => 'Active', 'nextduedate' => '2025-01-05', 'domain' => '', 'notes' => ''],
        ['id' => 4, 'packageid' => 1, 'qty' => 2, 'amount' => 100.0, 'billingcycle' => 'Annually', 'domainstatus' => 'Active', 'nextduedate' => '2027-01-05', 'domain' => '', 'notes' => ''],
    ])
        ->and($warnings)->toContain('Service #2 of client #101 was not imported: it renews every 1 week')
        ->and($warnings)->toContain('Service #6 of client #101 was not imported: its plan no longer exists in Paymenter.')
        ->and($warnings)->toContain('Service #4 of client #101 has a Paymenter coupon for its first 3 invoices')
        ->and($db->fetchAllAssociative('SELECT relid, configid, optionid, qty FROM tblhostingconfigoptions'))->toBe([['relid' => 1, 'configid' => 10, 'optionid' => 12, 'qty' => 0], ['relid' => 1, 'configid' => 14, 'optionid' => 15, 'qty' => 1]])
        ->and($db->fetchAllAssociative('SELECT relid, reason, type FROM tblcancelrequests'))->toBe([['relid' => 4, 'reason' => 'Too slow', 'type' => 'End of Billing Period']]);

    // Billing: the total is Paymenter's (its lines), the tax the part its rate gives; credit paid is the credit applied.
    expect($db->fetchAllAssociative('SELECT id, invoicenum, status, subtotal, credit, tax, total, taxrate, datepaid FROM tblinvoices ORDER BY id'))->toBe([
        ['id' => 1, 'invoicenum' => 'INV-0001', 'status' => 'Paid', 'subtotal' => 7.0, 'credit' => 0.0, 'tax' => 1.4, 'total' => 8.4, 'taxrate' => 20.0, 'datepaid' => '2026-11-02 09:00:00'],
        ['id' => 2, 'invoicenum' => '', 'status' => 'Unpaid', 'subtotal' => 50.0, 'credit' => 20.0, 'tax' => 10.0, 'total' => 40.0, 'taxrate' => 20.0, 'datepaid' => '0000-00-00 00:00:00'],
        ['id' => 3, 'invoicenum' => 'INV-0002', 'status' => 'Paid', 'subtotal' => 10.0, 'credit' => 0.0, 'tax' => 0.0, 'total' => 10.0, 'taxrate' => 20.0, 'datepaid' => '2026-11-02 09:00:00'],
        ['id' => 4, 'invoicenum' => '', 'status' => 'Unpaid', 'subtotal' => 10.0, 'credit' => 0.0, 'tax' => 2.0, 'total' => 12.0, 'taxrate' => 20.0, 'datepaid' => '0000-00-00 00:00:00'],
        ['id' => 6, 'invoicenum' => 'INV-0004', 'status' => 'Cancelled', 'subtotal' => 0.83, 'credit' => 0.0, 'tax' => 0.17, 'total' => 1.0, 'taxrate' => 20.0, 'datepaid' => '0000-00-00 00:00:00'],
    ])
        ->and($warnings)->toContain('Invoice #6 has the status "void" in Paymenter')
        ->and($warnings)->toContain('Invoice #4 bills an upgrade in Paymenter')
        ->and($warnings)->toContain('Client #101 is billed in USD, but its invoice #2 is in EUR in Paymenter')
        ->and($db->fetchAllAssociative('SELECT invoiceid, type, relid, description, amount, taxed FROM tblinvoiceitems ORDER BY id'))->toBe([
            ['invoiceid' => 1, 'type' => 'Hosting', 'relid' => 1, 'description' => 'Starter', 'amount' => 5.0, 'taxed' => 1],
            // A second line of the same service is a plain item: paying renews it once.
            ['invoiceid' => 1, 'type' => '', 'relid' => 0, 'description' => 'Setup fee', 'amount' => 2.0, 'taxed' => 1],
            ['invoiceid' => 2, 'type' => 'Hosting', 'relid' => 4, 'description' => '2 x Starter', 'amount' => 50.0, 'taxed' => 1],
            // A credit deposit is untaxed: the tax is on what the credit pays.
            ['invoiceid' => 3, 'type' => 'AddFunds', 'relid' => 0, 'description' => 'Credit deposit', 'amount' => 10.0, 'taxed' => 0],
            ['invoiceid' => 4, 'type' => '', 'relid' => 0, 'description' => 'Upgrade Starter to Setup', 'amount' => 10.0, 'taxed' => 1],
            ['invoiceid' => 6, 'type' => '', 'relid' => 0, 'description' => 'Odd', 'amount' => 0.83, 'taxed' => 1],
        ])
        ->and($db->fetchOne("SELECT value FROM tblconfiguration WHERE setting = 'SequentialInvoiceNumberFormat'"))->toBe('INV-{NUMBER}')
        // Money received only: the credit payment is the invoice's credit, failed and processing ones are left out.
        ->and($db->fetchAllAssociative('SELECT invoiceid, gateway, amountin, fees, transid FROM tblaccounts ORDER BY id'))->toBe([
            ['invoiceid' => 1, 'gateway' => 'stripe', 'amountin' => 8.4, 'fees' => 0.5, 'transid' => 'ch_1'],
            ['invoiceid' => 2, 'gateway' => '', 'amountin' => 15.0, 'fees' => 0.0, 'transid' => 'paymenter-3'],
            ['invoiceid' => 3, 'gateway' => 'paypal', 'amountin' => 10.0, 'fees' => 0.0, 'transid' => 'PP-1'],
        ])
        ->and($warnings)->toContain('Payment #4 of invoice #2 was still processing in Paymenter');

    // Support: the first message opens the ticket; staff replies keep their kind; departments by name.
    expect($db->fetchAllAssociative('SELECT tid, did, title, message, status, urgency, email, service, lastreply FROM tbltickets ORDER BY id'))->toBe([
        ['tid' => '7', 'did' => 2, 'title' => 'Help', 'message' => 'My site is down.', 'status' => 'Answered', 'urgency' => 'High', 'email' => 'ann@example.test', 'service' => 'S1', 'lastreply' => '2026-11-05 10:00:00'],
        ['tid' => '8', 'did' => 1, 'title' => 'Old', 'message' => 'Hi', 'status' => 'Closed', 'urgency' => 'Medium', 'email' => 'ann@example.test', 'service' => '', 'lastreply' => '2026-11-06 09:00:00'],
    ])
        ->and($db->fetchAllAssociative('SELECT userid, admin, message FROM tblticketreplies ORDER BY id'))->toBe([['userid' => 0, 'admin' => 'Sam Ops', 'message' => 'We are on it.'], ['userid' => 101, 'admin' => '', 'message' => 'Thanks.']]);

    $plan = Inventory::plan(new Source($db));
    expect($plan['version'])->toBe('8.0.0 (Paymenter)')
        ->and($plan['modules'])->toBe([]);
});

test('with tax included in prices, Paymenter\'s amounts are kept as they are', function (): void {
    [$di, $source] = paymenterDi('inclusive');
    Snapshot::fetch($di, [], 0.0, false, $source);
    $db = Snapshot::open();

    expect($db->fetchOne("SELECT value FROM tblconfiguration WHERE setting = 'TaxType'"))->toBe('Inclusive')
        ->and($db->fetchOne('SELECT amount FROM tblhosting WHERE id = 1'))->toBe(6.0)
        ->and($db->fetchAllKeyValue('SELECT id, amount FROM tblinvoiceitems WHERE invoiceid = 1 ORDER BY id'))->toBe([1 => 6.0, 2 => 2.4])
        ->and($db->fetchAssociative('SELECT subtotal, tax, total FROM tblinvoices WHERE id = 1'))->toBe(['subtotal' => 7.0, 'tax' => 1.4, 'total' => 8.4]);
});

test('the import reads Paymenter only once a read has finished, and reading needs the saved details', function (): void {
    [$di, $source] = paymenterDi();
    expect(fn () => Import::fetchPaymenter($di, 1.0))->toThrow(ClientFence\InformationException::class, 'Save the Paymenter database details first.')
        ->and(fn () => Source::open($di, ['paymenter' => 1]))->toThrow(ClientFence\InformationException::class, 'Read Paymenter first, until it finishes.')
        ->and(Import::system(['paymenter' => 1] + Import::DEFAULTS))->toBe('Paymenter')
        ->and(Snapshot::isPaymenter($source))->toBeTrue();

    Snapshot::fetch($di, [], 0.000001, false, $source);
    expect(fn () => Source::open($di, ['paymenter' => 1]))->toThrow(ClientFence\InformationException::class, 'Read Paymenter first, until it finishes.');

    Snapshot::fetch($di, [], 0.0, false, $source);
    $snapshot = Source::open($di, ['paymenter' => 1]);
    expect($snapshot->isWhmcs())->toBeTrue();
    $snapshot->close();
});
