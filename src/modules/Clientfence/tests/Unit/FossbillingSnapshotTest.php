<?php

declare(strict_types=1);

/**
 * ClientFence: the FOSSBilling import's read (Fossbilling\Snapshot) from an invented FOSSBilling
 * database (its tables as FOSSBilling 0.8 names them, on SQLite) into the WHMCS-shaped file the
 * WHMCS import reads.
 */

use ClientFence\Mod\Clientfence\Fossbilling\Snapshot;
use ClientFence\Mod\Clientfence\Whmcs\Import;
use ClientFence\Mod\Clientfence\Whmcs\Inventory;
use ClientFence\Mod\Clientfence\Whmcs\Run;
use ClientFence\Mod\Clientfence\Whmcs\Source;
use Doctrine\DBAL\DriverManager;

/** FOSSBilling's tables (the columns the read uses) and rows, keyed by table. */
function fossbillingFake(): array
{
    $pay = fn (int $id, string $type, array $prices = []): array => $prices + [
        'id' => $id, 'type' => $type, 'once_price' => 0.0, 'once_setup_price' => 0.0, 'w_price' => 0.0, 'w_setup_price' => 0.0, 'w_enabled' => 0,
        'm_price' => 0.0, 'q_price' => 0.0, 'b_price' => 0.0, 'a_price' => 0.0, 'bia_price' => 0.0, 'tria_price' => 0.0,
        'm_setup_price' => 0.0, 'q_setup_price' => 0.0, 'b_setup_price' => 0.0, 'a_setup_price' => 0.0, 'bia_setup_price' => 0.0, 'tria_setup_price' => 0.0,
        'm_enabled' => 0, 'q_enabled' => 0, 'b_enabled' => 0, 'a_enabled' => 0, 'bia_enabled' => 0, 'tria_enabled' => 0,
    ];
    $product = fn (int $id, string $title, string $type, int $payment, array $more = []): array => $more + [
        'id' => $id, 'product_category_id' => 2, 'product_payment_id' => $payment, 'title' => $title, 'slug' => strtolower($title), 'description' => '',
        'status' => 'enabled', 'hidden' => 0, 'is_addon' => 0, 'addons' => null, 'upgrades' => null, 'allow_quantity_select' => 0, 'stock_control' => 0,
        'quantity_in_stock' => 0, 'priority' => 1, 'type' => $type,
    ];
    $order = fn (int $id, int $client, int $product, string $type, ?string $period, float $price, string $status, array $more = []): array => $more + [
        'id' => $id, 'client_id' => $client, 'product_id' => $product, 'group_id' => 'g' . $id, 'group_master' => 1, 'title' => 'Order ' . $id,
        'service_id' => 0, 'service_type' => $type, 'period' => $period, 'quantity' => 1, 'price' => $price, 'status' => $status, 'reason' => null, 'notes' => null,
        'expires_at' => '2026-12-05 00:00:00', 'activated_at' => '2025-01-05 10:00:00', 'canceled_at' => null, 'created_at' => '2025-01-05 10:00:00',
        'promo_id' => null, 'promo_recurring' => 0, 'discount' => 0.0, 'invoice_option' => 'issue-invoice',
    ];
    $invoice = fn (int $id, int $client, string $status, array $more = []): array => $more + [
        'id' => $id, 'client_id' => $client, 'serie' => 'INV-', 'nr' => sprintf('%04d', $id), 'currency' => 'USD', 'status' => $status, 'approved' => 1,
        'gateway_id' => null, 'taxrate' => null, 'refund' => null, 'notes' => null, 'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-03 10:00:00',
        'due_at' => '2026-11-08 00:00:00', 'paid_at' => null,
    ];
    $item = 0;
    $line = function (int $invoice, string $type, ?int $rel, string $title, float $price, int $taxed = 0) use (&$item): array {
        return ['id' => ++$item, 'invoice_id' => $invoice, 'type' => $type, 'rel_id' => $rel, 'title' => $title, 'price' => $price, 'quantity' => 1, 'taxed' => $taxed];
    };

    return [
        'setting' => [['param' => 'tax_enabled', 'value' => '1'], ['param' => 'last_patch', 'value' => '121']],
        'currency' => [
            ['id' => 1, 'code' => 'USD', 'is_default' => 1, 'conversion_rate' => 1.0, 'format_pattern' => '${amount}', 'fraction_digits' => 2],
            ['id' => 2, 'code' => 'EUR', 'is_default' => 0, 'conversion_rate' => 0.9, 'format_pattern' => '{amount} €', 'fraction_digits' => 2],
        ],
        'tax' => [['id' => 1, 'level' => 0, 'name' => 'VAT', 'country' => '', 'state' => '', 'taxrate' => '20']],
        'client_group' => [['id' => 3, 'title' => 'VIP']],
        'product_category' => [['id' => 2, 'title' => 'Hosting', 'priority' => 1]],
        'support_helpdesk' => [['id' => 1, 'name' => 'General', 'email' => 'help@example.test']],
        'promo' => [['id' => 1, 'code' => 'SAVE']],
        'service_hosting_server' => [['id' => 1, 'name' => 'Server 1']],
        'service_hosting_hp' => [['id' => 1, 'name' => 'Plan 1']],
        'subscription' => [['id' => 1, 'status' => 'active'], ['id' => 2, 'status' => 'canceled']],
        'product_payment' => [
            // Periods FOSSBilling left on at 0.00 are not offered.
            $pay(1, 'recurrent', ['m_price' => 5.0, 'm_setup_price' => 1.0, 'm_enabled' => 1, 'a_price' => 50.0, 'a_enabled' => 1, 'q_enabled' => 1, 'w_price' => 1.5, 'w_enabled' => 1]),
            $pay(2, 'once', ['once_price' => 49.0]),
            $pay(3, 'recurrent', ['m_price' => 2.0, 'm_enabled' => 1, 'w_enabled' => 1]),
            $pay(4, 'free'),
        ],
        'product' => [
            $product(1, 'Starter', 'hosting', 1, ['addons' => '[3]', 'upgrades' => '["2"]']),
            $product(2, 'Migration', 'custom', 2, ['hidden' => 1]),
            $product(3, 'Extra IP', 'custom', 3, ['is_addon' => 1]),
            $product(5, 'Key', 'license', 4, ['status' => 'disabled']),
            // Domains are sold by their extensions.
            $product(6, 'Domains', 'domain', 4),
        ],
        'tld' => [
            ['id' => 1, 'tld' => '.com', 'price_registration' => 10.0, 'price_renew' => 12.0, 'price_transfer' => 9.0, 'allow_register' => 1, 'allow_transfer' => 1, 'require_transfer_code' => 1, 'active' => 1, 'min_years' => 1],
            ['id' => 2, 'tld' => '.org', 'price_registration' => 10.0, 'price_renew' => 10.0, 'price_transfer' => 10.0, 'allow_register' => 1, 'allow_transfer' => 1, 'require_transfer_code' => 1, 'active' => 0, 'min_years' => 1],
        ],
        'tld_registrar' => [['id' => 1, 'registrar' => 'Resellerclub']],
        'pay_gateway' => [['id' => 1, 'gateway' => 'Stripe'], ['id' => 2, 'gateway' => 'Custom']],
        'admin' => [['id' => 1, 'name' => 'Sam Ops']],
        'client' => [
            ['id' => 101, 'client_group_id' => 3, 'email' => 'ann@example.test', 'pass' => password_hash('secret', PASSWORD_BCRYPT), 'status' => 'active', 'tax_exempt' => 0,
                'first_name' => 'Ann', 'last_name' => 'Lee', 'phone_cc' => '44', 'phone' => '2071234567', 'company' => 'Lee Ltd', 'company_vat' => 'GB123',
                'address_1' => '1 Road', 'address_2' => '', 'city' => 'London', 'state' => '', 'postcode' => 'N1', 'country' => 'gb', 'notes' => 'Pays early.',
                'currency' => 'USD', 'lang' => 'en_US', 'created_at' => '2025-01-05 10:00:00'],
            ['id' => 102, 'client_group_id' => null, 'email' => 'eve@example.test', 'pass' => '', 'status' => 'suspended', 'tax_exempt' => 1,
                'first_name' => 'Eve', 'last_name' => 'Fox', 'phone_cc' => null, 'phone' => null, 'company' => '', 'company_vat' => '',
                'address_1' => '', 'address_2' => '', 'city' => '', 'state' => '', 'postcode' => '', 'country' => '', 'notes' => null,
                'currency' => 'EUR', 'lang' => null, 'created_at' => '2025-02-01 10:00:00'],
        ],
        // A gateway payment goes through the balance: in, then out to the invoice; 25 added stays.
        'client_balance' => [
            ['id' => 1, 'client_id' => 101, 'type' => 'transaction', 'rel_id' => '1', 'amount' => 9.6, 'description' => 'Payment received', 'created_at' => '2026-11-02 09:00:00'],
            ['id' => 2, 'client_id' => 101, 'type' => 'invoice', 'rel_id' => '1', 'amount' => -9.6, 'description' => 'Payment for invoice #INV-0001', 'created_at' => '2026-11-02 09:00:00'],
            ['id' => 3, 'client_id' => 101, 'type' => 'default', 'rel_id' => null, 'amount' => 25.0, 'description' => 'Added by staff', 'created_at' => '2026-11-03 09:00:00'],
        ],
        'client_order' => [
            $order(1, 101, 1, 'hosting', '1M', 5.0, 'active', ['service_id' => 11]),
            // The add-on of order 1 (same group, not its main order).
            $order(2, 101, 3, 'custom', '1M', 2.0, 'active', ['group_id' => 'g1', 'group_master' => 0, 'title' => 'Extra IP']),
            // As FOSSBilling leaves a domain's order: no period, no expiry (the domain has it).
            $order(3, 101, 6, 'domain', null, 12.0, 'active', ['service_id' => 21, 'expires_at' => null]),
            $order(4, 101, 1, 'hosting', '1W', 1.0, 'active', ['title' => 'Weekly']),
            $order(5, 102, 2, 'custom', null, 49.0, 'canceled', ['canceled_at' => '2026-02-01 08:00:00']),
            // A domain ordered in the cart for 5 years: its order's period is the years.
            $order(6, 101, 6, 'domain', '5Y', 58.0, 'active', ['service_id' => 22, 'expires_at' => null]),
            // A recurring promotion's discount comes off every renewal; staff made it without invoices (no-invoice).
            $order(7, 101, 1, 'custom', '1M', 10.0, 'active', ['promo_id' => 1, 'promo_recurring' => 1, 'discount' => 3.0, 'invoice_option' => 'no-invoice']),
        ],
        'service_hosting' => [['id' => 11, 'sld' => 'site', 'tld' => '.example', 'username' => 'annlee']],
        'service_domain' => [['id' => 21, 'tld_registrar_id' => 1, 'sld' => 'ann', 'tld' => '.com', 'period' => 1, 'action' => 'register', 'registered_at' => '2025-01-05 10:00:00', 'expires_at' => '2027-01-05 00:00:00'],
            ['id' => 22, 'tld_registrar_id' => 1, 'sld' => 'lee', 'tld' => '.com', 'period' => 5, 'action' => 'register', 'registered_at' => '2026-01-05 10:00:00', 'expires_at' => '2031-01-05 00:00:00']],
        'invoice' => [
            $invoice(1, 101, 'paid', ['gateway_id' => 1, 'taxrate' => 20.0, 'paid_at' => '2026-11-02 09:00:00']),
            $invoice(2, 101, 'unpaid', ['currency' => 'EUR']),
            $invoice(3, 101, 'refunded', ['refund' => 5.0, 'paid_at' => '2026-11-02 09:00:00']),
            // Not approved: a draft. An Add Funds invoice.
            $invoice(4, 101, 'unpaid', ['approved' => 0]),
            // Its client was not imported.
            $invoice(5, 999, 'unpaid'),
        ],
        'invoice_item' => [
            $line(1, 'order', 1, 'Starter', 5.0, 1), $line(1, 'order', 2, 'Extra IP', 2.0, 1), $line(1, 'custom', null, 'Setup', 1.0, 1),
            $line(2, 'order', 3, 'ann.com', 12.0),
            $line(3, 'custom', null, 'Old', 5.0),
            $line(4, 'deposit', null, 'Add funds', 25.0),
        ],
        'transaction' => [
            ['id' => 1, 'invoice_id' => 1, 'gateway_id' => 1, 'txn_id' => 'ch_1', 'amount' => '9.6', 'status' => 'processed', 'created_at' => '2026-11-02 09:00:00'],
            // Paid, but its invoice is unpaid: the money is in the client's balance.
            ['id' => 2, 'invoice_id' => 2, 'gateway_id' => 1, 'txn_id' => 'ch_2', 'amount' => '5', 'status' => 'processed', 'created_at' => '2026-11-04 09:00:00'],
            ['id' => 3, 'invoice_id' => 1, 'gateway_id' => 1, 'txn_id' => 'ch_x', 'amount' => '9.6', 'status' => 'error', 'created_at' => '2026-11-02 08:00:00'],
        ],
        'support_ticket' => [['id' => 7, 'support_helpdesk_id' => 1, 'client_id' => 101, 'author_name' => 'Ann Lee', 'author_email' => 'ann@example.test', 'priority' => 50, 'subject' => 'Help', 'status' => 'on_hold', 'rel_type' => 'order', 'rel_id' => 3, 'created_at' => '2026-11-05 09:00:00']],
        'support_ticket_message' => [
            ['id' => 1, 'support_ticket_id' => 7, 'client_id' => 101, 'admin_id' => null, 'content' => 'My site is down.', 'attachment' => null, 'created_at' => '2026-11-05 09:00:00'],
            ['id' => 2, 'support_ticket_id' => 7, 'client_id' => null, 'admin_id' => 1, 'content' => 'We are on it.', 'attachment' => 'log.txt', 'created_at' => '2026-11-05 09:30:00'],
        ],
        'support_ticket_note' => [['id' => 1, 'support_ticket_id' => 7, 'admin_id' => 1, 'note' => 'Server 3.', 'created_at' => '2026-11-05 09:40:00']],
    ];
}

/** The fake FOSSBilling as an SQLite file, and the run state on an in-memory database. */
function fossbillingDi(): array
{
    $dir = sys_get_temp_dir() . '/cf-fossbilling-test-' . getmypid();
    putenv('CLIENTFENCE_BACKUP_DIR=' . $dir . '/backups');
    @mkdir($dir, 0700, true);
    @unlink($dir . '/fossbilling.sqlite');
    $fb = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dir . '/fossbilling.sqlite']);
    foreach (fossbillingFake() as $table => $rows) {
        // Typed as the first row's values, so numbers compare as numbers (as in MySQL).
        $first = $rows[0];
        $fb->executeStatement(sprintf('CREATE TABLE "%s" (%s)', $table, implode(', ', array_map(fn (string $c): string => sprintf('"%s" %s', $c, is_int($first[$c]) ? 'INTEGER' : (is_float($first[$c]) ? 'REAL' : '')), array_keys($first)))));
        foreach ($rows as $row) {
            $fb->insert('"' . $table . '"', array_combine(array_map(fn (string $c): string => '"' . $c . '"', array_keys($row)), $row));
        }
    }
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE mod_clientfence_state (name TEXT PRIMARY KEY, value TEXT, updated_at TEXT)');
    $db->executeStatement('CREATE TABLE ' . Run::WARNINGS . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT, step TEXT, message TEXT, created_at TEXT)');
    $di = new Pimple\Container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;

    return [$di, new Source($fb)];
}

afterEach(function (): void {
    $dir = sys_get_temp_dir() . '/cf-fossbilling-test-' . getmypid();
    @unlink(Snapshot::path());
    @rmdir(dirname(Snapshot::path()));
    @unlink($dir . '/fossbilling.sqlite');
    @rmdir($dir . '/backups');
    @rmdir($dir);
    putenv('CLIENTFENCE_BACKUP_DIR');
});

test('FOSSBilling is read a page at a time into WHMCS\'s tables, and what cannot come over is left out with a warning', function (): void {
    [$di, $source] = fossbillingDi();
    expect(Snapshot::isFossbilling($source))->toBeTrue();
    $calls = 0;
    do {
        $state = Snapshot::fetch($di, [], 0.000001, false, $source);
        ++$calls;
    } while ($state['finished_at'] === null && $calls < 50);
    $db = Snapshot::open();
    $warnings = implode("\n", Run::warnings($di['em']->getConnection()));

    // Clients keep their details, password hash, group and credit; the balance history comes along.
    expect($state['finished_at'])->not->toBeNull()
        ->and($calls)->toBeGreaterThan(5)
        ->and(substr(sprintf('%o', fileperms(Snapshot::path())), -4))->toBe('0600')
        ->and($db->fetchFirstColumn('SELECT id FROM tblclients ORDER BY id'))->toBe([101, 102])
        ->and($db->fetchAssociative('SELECT companyname, address1, city, country, phonenumber, tax_id, credit, status, groupid, taxexempt, language, datecreated FROM tblclients WHERE id = 101'))
        ->toBe(['companyname' => 'Lee Ltd', 'address1' => '1 Road', 'city' => 'London', 'country' => 'GB', 'phonenumber' => '+44.2071234567', 'tax_id' => 'GB123', 'credit' => 25.0, 'status' => 'Active', 'groupid' => 3, 'taxexempt' => 0, 'language' => 'en_US', 'datecreated' => '2025-01-05'])
        ->and($db->fetchAllKeyValue('SELECT c.id, cur.code FROM tblclients c JOIN tblcurrencies cur ON cur.id = c.currency ORDER BY c.id'))->toBe([101 => 'USD', 102 => 'EUR'])
        ->and($db->fetchAssociative('SELECT status, taxexempt, groupid FROM tblclients WHERE id = 102'))->toBe(['status' => 'Inactive', 'taxexempt' => 1, 'groupid' => 0])
        ->and(password_verify('secret', (string) $db->fetchOne('SELECT password FROM tblusers WHERE id = 101')))->toBeTrue()
        ->and($db->fetchAllAssociative('SELECT amount, relid FROM tblcredit ORDER BY id'))->toBe([['amount' => 9.6, 'relid' => 0], ['amount' => -9.6, 'relid' => 1], ['amount' => 25.0, 'relid' => 0]])
        ->and($db->fetchFirstColumn('SELECT note FROM tblnotes'))->toBe(['Pays early.'])
        ->and($db->fetchAllKeyValue('SELECT code, prefix || \'|\' || suffix FROM tblcurrencies ORDER BY code'))->toBe(['EUR' => '| €', 'USD' => '$|'])
        ->and($db->fetchFirstColumn('SELECT groupname FROM tblclientgroups'))->toBe(['VIP'])
        ->and($db->fetchAllKeyValue("SELECT setting, value FROM tblconfiguration WHERE setting LIKE 'Tax%' ORDER BY setting"))->toBe(['TaxDomains' => 'on', 'TaxEnabled' => 'on', 'TaxType' => 'Exclusive'])
        ->and($warnings)->toContain('FOSSBilling has 1 promotions: they were not imported.')
        ->and($warnings)->toContain('FOSSBilling has 1 hosting servers')
        ->and($warnings)->toContain('FOSSBilling has 1 gateway subscriptions')
        ->and($warnings)->toContain('FOSSBilling has 1 ticket attachments');

    // The catalogue: products with their enabled prices, add-ons with the products that offer them, upgrade paths, domain prices by years.
    expect($db->fetchAllAssociative('SELECT id, type, name, gid, hidden, retired, paytype FROM tblproducts ORDER BY id'))->toBe([
        ['id' => 1, 'type' => 'hostingaccount', 'name' => 'Starter', 'gid' => 2, 'hidden' => 0, 'retired' => 0, 'paytype' => 'recurring'],
        ['id' => 2, 'type' => 'other', 'name' => 'Migration', 'gid' => 2, 'hidden' => 1, 'retired' => 0, 'paytype' => 'onetime'],
        ['id' => 5, 'type' => 'other', 'name' => 'Key', 'gid' => 2, 'hidden' => 0, 'retired' => 1, 'paytype' => 'free'],
    ])
        ->and($warnings)->toContain('Product "Key" is a license product in FOSSBilling')
        ->and($db->fetchAllAssociative('SELECT id, packages, name FROM tbladdons'))->toBe([['id' => 3, 'packages' => '1', 'name' => 'Extra IP']])
        ->and($db->fetchAllAssociative('SELECT product_id, upgrade_product_id FROM tblproduct_upgrade_products'))->toBe([['product_id' => 1, 'upgrade_product_id' => 2]])
        ->and($db->fetchAssociative("SELECT monthly, quarterly, annually, msetupfee FROM tblpricing WHERE type = 'product' AND relid = 1"))->toBe(['monthly' => 5.0, 'quarterly' => -1.0, 'annually' => 50.0, 'msetupfee' => 1.0])
        ->and($db->fetchOne("SELECT monthly FROM tblpricing WHERE type = 'product' AND relid = 2"))->toBe(49.0)
        ->and($db->fetchOne("SELECT monthly FROM tblpricing WHERE type = 'addon' AND relid = 3"))->toBe(2.0)
        ->and($warnings)->toContain('Product "Starter": its weekly price was not imported')
        ->and($warnings)->not->toContain('Product "Extra IP": its weekly price')
        ->and($db->fetchAllAssociative('SELECT level, taxrate FROM tbltax'))->toBe([['level' => '1', 'taxrate' => 20.0]])
        ->and($db->fetchFirstColumn('SELECT extension FROM tbldomainpricing'))->toBe(['.com'])
        ->and($db->fetchAllKeyValue("SELECT type, msetupfee || '/' || qsetupfee || '/' || biennially FROM tblpricing WHERE type LIKE 'domain%' ORDER BY type"))->toBe(['domainregister' => '10.0/22.0/118.0', 'domainrenew' => '12.0/24.0/120.0', 'domaintransfer' => '9.0/-1.0/-1.0']);

    // Orders: hosting with its account, an add-on on its main order, a domain with its registrar, weekly ones left out.
    expect($db->fetchAllAssociative('SELECT id, userid, packageid, amount, billingcycle, domainstatus, nextduedate, termination_date, domain, username FROM tblhosting ORDER BY id'))->toBe([
        ['id' => 1, 'userid' => 101, 'packageid' => 1, 'amount' => 5.0, 'billingcycle' => 'Monthly', 'domainstatus' => 'Active', 'nextduedate' => '2026-12-05', 'termination_date' => null, 'domain' => 'site.example', 'username' => 'annlee'],
        ['id' => 5, 'userid' => 102, 'packageid' => 2, 'amount' => 49.0, 'billingcycle' => 'One Time', 'domainstatus' => 'Cancelled', 'nextduedate' => '2026-12-05', 'termination_date' => '2026-02-01', 'domain' => '', 'username' => ''],
        ['id' => 7, 'userid' => 101, 'packageid' => 1, 'amount' => 7.0, 'billingcycle' => 'Monthly', 'domainstatus' => 'Active', 'nextduedate' => '2026-12-05', 'termination_date' => null, 'domain' => '', 'username' => ''],
    ])
        ->and($db->fetchAllAssociative('SELECT id, hostingid, addonid, name, recurring, billingcycle, status FROM tblhostingaddons'))->toBe([
            ['id' => 2, 'hostingid' => 1, 'addonid' => 3, 'name' => 'Extra IP', 'recurring' => 2.0, 'billingcycle' => 'Monthly', 'status' => 'Active'],
        ])
        ->and($db->fetchAllAssociative('SELECT id, domain, registrar, registrationperiod, expirydate, nextduedate, status FROM tbldomains'))->toBe([
            ['id' => 3, 'domain' => 'ann.com', 'registrar' => 'resellerclub', 'registrationperiod' => 1, 'expirydate' => '2027-01-05', 'nextduedate' => '2027-01-05', 'status' => 'Active'],
            ['id' => 6, 'domain' => 'lee.com', 'registrar' => 'resellerclub', 'registrationperiod' => 5, 'expirydate' => '2031-01-05', 'nextduedate' => '2031-01-05', 'status' => 'Active'],
        ])
        ->and($db->fetchAllKeyValue('SELECT id, donotrenew FROM tblhosting ORDER BY id'))->toBe([1 => 0, 5 => 0, 7 => 1])
        ->and($warnings)->toContain('Order #4 (Weekly) of client #101 was not imported: it renews every 1W');

    // Billing: totals as FOSSBilling makes them; only real payments of paid invoices; the refund goes out.
    expect($db->fetchAllAssociative('SELECT id, invoicenum, date, duedate, datepaid, status, subtotal, tax, total, taxrate, paymentmethod FROM tblinvoices ORDER BY id'))->toBe([
        ['id' => 1, 'invoicenum' => 'INV-0001', 'date' => '2026-11-01', 'duedate' => '2026-11-08', 'datepaid' => '2026-11-02 00:00:00', 'status' => 'Paid', 'subtotal' => 8.0, 'tax' => 1.6, 'total' => 9.6, 'taxrate' => 20.0, 'paymentmethod' => 'stripe'],
        ['id' => 2, 'invoicenum' => 'INV-0002', 'date' => '2026-11-01', 'duedate' => '2026-11-08', 'datepaid' => '0000-00-00 00:00:00', 'status' => 'Unpaid', 'subtotal' => 12.0, 'tax' => 0.0, 'total' => 12.0, 'taxrate' => 0.0, 'paymentmethod' => ''],
        ['id' => 3, 'invoicenum' => 'INV-0003', 'date' => '2026-11-01', 'duedate' => '2026-11-08', 'datepaid' => '2026-11-02 00:00:00', 'status' => 'Refunded', 'subtotal' => 5.0, 'tax' => 0.0, 'total' => 5.0, 'taxrate' => 0.0, 'paymentmethod' => ''],
        ['id' => 4, 'invoicenum' => 'INV-0004', 'date' => '2026-11-01', 'duedate' => '2026-11-08', 'datepaid' => '0000-00-00 00:00:00', 'status' => 'Draft', 'subtotal' => 25.0, 'tax' => 0.0, 'total' => 25.0, 'taxrate' => 0.0, 'paymentmethod' => ''],
    ])
        ->and($warnings)->toContain('Client #101 is billed in USD, but its invoice #2 is in EUR in FOSSBilling')
        ->and($db->fetchAllAssociative('SELECT invoiceid, type, relid, description, amount, taxed FROM tblinvoiceitems ORDER BY id'))->toBe([
            ['invoiceid' => 1, 'type' => 'Hosting', 'relid' => 1, 'description' => 'Starter', 'amount' => 5.0, 'taxed' => 1],
            ['invoiceid' => 1, 'type' => 'Addon', 'relid' => 2, 'description' => 'Extra IP', 'amount' => 2.0, 'taxed' => 1],
            ['invoiceid' => 1, 'type' => '', 'relid' => 0, 'description' => 'Setup', 'amount' => 1.0, 'taxed' => 1],
            ['invoiceid' => 2, 'type' => 'Domain', 'relid' => 3, 'description' => 'ann.com', 'amount' => 12.0, 'taxed' => 0],
            ['invoiceid' => 3, 'type' => '', 'relid' => 0, 'description' => 'Old', 'amount' => 5.0, 'taxed' => 0],
            ['invoiceid' => 4, 'type' => 'AddFunds', 'relid' => 0, 'description' => 'Add funds', 'amount' => 25.0, 'taxed' => 0],
        ])
        ->and($db->fetchOne("SELECT value FROM tblconfiguration WHERE setting = 'SequentialInvoiceNumberFormat'"))->toBe('INV-{NUMBER}')
        ->and($db->fetchAllAssociative('SELECT invoiceid, gateway, amountin, amountout, transid FROM tblaccounts ORDER BY id'))->toBe([
            ['invoiceid' => 3, 'gateway' => '', 'amountin' => 0.0, 'amountout' => 5.0, 'transid' => 'fossbilling-refund-3'],
            ['invoiceid' => 1, 'gateway' => 'stripe', 'amountin' => 9.6, 'amountout' => 0.0, 'transid' => 'ch_1'],
        ]);

    // Support: the first message opens the ticket; staff replies and notes keep their staff member.
    expect($db->fetchAssociative('SELECT tid, did, title, message, status, urgency, email, service, lastreply FROM tbltickets'))->toBe(['tid' => '7', 'did' => 1, 'title' => 'Help', 'message' => 'My site is down.', 'status' => 'Answered', 'urgency' => 'High', 'email' => 'ann@example.test', 'service' => 'D3', 'lastreply' => '2026-11-05 09:30:00'])
        ->and($db->fetchAllAssociative('SELECT admin, message FROM tblticketreplies'))->toBe([['admin' => 'Sam Ops', 'message' => 'We are on it.']])
        ->and($db->fetchAllAssociative('SELECT admin, message FROM tblticketnotes'))->toBe([['admin' => 'Sam Ops', 'message' => 'Server 3.']]);

    $plan = Inventory::plan(new Source($db));
    expect($plan['version'])->toBe('8.0.0 (FOSSBilling)')
        ->and($plan['modules'])->toBe([]);
});

test('the import reads FOSSBilling only once a read has finished, and reading needs the saved details', function (): void {
    [$di, $source] = fossbillingDi();
    expect(fn () => Import::fetchFossbilling($di, 1.0))->toThrow(ClientFence\InformationException::class, 'Save the FOSSBilling database details first.')
        ->and(fn () => Source::open($di, ['fossbilling' => 1]))->toThrow(ClientFence\InformationException::class, 'Read FOSSBilling first, until it finishes.')
        ->and(Import::system(['fossbilling' => 1] + Import::DEFAULTS))->toBe('FOSSBilling');

    Snapshot::fetch($di, [], 0.000001, false, $source);
    expect(fn () => Source::open($di, ['fossbilling' => 1]))->toThrow(ClientFence\InformationException::class, 'Read FOSSBilling first, until it finishes.');

    Snapshot::fetch($di, [], 0.0, false, $source);
    $snapshot = Source::open($di, ['fossbilling' => 1]);
    expect($snapshot->isWhmcs())->toBeTrue();
    $snapshot->close();
});
