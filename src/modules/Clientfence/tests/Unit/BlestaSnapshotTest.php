<?php

declare(strict_types=1);

/**
 * ClientFence: the Blesta import's read (Blesta\Snapshot) from an invented Blesta database (its
 * tables as Blesta 6 names them, on SQLite) into the WHMCS-shaped file the WHMCS import reads, and
 * the sign-in with a password kept from Blesta.
 */

use ClientFence\Mod\Clientfence\Blesta\Snapshot;
use ClientFence\Mod\Clientfence\Whmcs\Import;
use ClientFence\Mod\Clientfence\Whmcs\Inventory;
use ClientFence\Mod\Clientfence\Whmcs\Run;
use ClientFence\Mod\Clientfence\Whmcs\Source;
use Doctrine\DBAL\DriverManager;

/** Blesta's tables (the columns the read uses) and rows, keyed by table. */
function blestaFake(): array
{
    return [
        'companies' => [['id' => 1]],
        'settings' => [['key' => 'database_version', 'value' => '6.0.4']],
        'company_settings' => [
            ['company_id' => 1, 'key' => 'inv_format', 'value' => '{num}'],
            ['company_id' => 1, 'key' => 'default_currency', 'value' => 'USD'],
            ['company_id' => 1, 'key' => 'enable_tax', 'value' => 'true'],
        ],
        'currencies' => [
            ['code' => 'USD', 'company_id' => 1, 'precision' => 2, 'prefix' => '$', 'suffix' => '', 'exchange_rate' => 1],
            ['code' => 'EUR', 'company_id' => 1, 'precision' => 2, 'prefix' => '€', 'suffix' => '', 'exchange_rate' => 0.9],
        ],
        'taxes' => [
            ['id' => 1, 'company_id' => 1, 'level' => 1, 'name' => 'VAT', 'amount' => 20, 'type' => 'exclusive', 'country' => 'GB', 'state' => null, 'status' => 'active'],
            // Blesta's "Inclusive": the tax is inside the price.
            ['id' => 2, 'company_id' => 1, 'level' => 1, 'name' => 'GST', 'amount' => 10, 'type' => 'inclusive_calculated', 'country' => 'AU', 'state' => null, 'status' => 'active'],
        ],
        'client_groups' => [['id' => 1, 'company_id' => 1, 'name' => 'General', 'color' => 'dd5555']],
        'gateways' => [['id' => 1, 'company_id' => 1, 'name' => 'Stripe', 'class' => 'Stripe_payments']],
        'package_groups' => [['id' => 1, 'company_id' => 1, 'type' => 'standard', 'hidden' => 0], ['id' => 2, 'company_id' => 1, 'type' => 'standard', 'hidden' => 0]],
        'package_group_names' => [['package_group_id' => 1, 'lang' => 'en_us', 'name' => 'Hosting'], ['package_group_id' => 2, 'lang' => 'en_us', 'name' => 'Domains']],
        'package_group' => [['package_id' => 1, 'package_group_id' => 1, 'order' => 0], ['package_id' => 2, 'package_group_id' => 2, 'order' => 0], ['package_id' => 3, 'package_group_id' => 1, 'order' => 1]],
        'packages' => [
            ['id' => 1, 'company_id' => 1, 'qty' => null, 'taxable' => 1, 'status' => 'active', 'hidden' => 0],
            ['id' => 2, 'company_id' => 1, 'qty' => null, 'taxable' => 1, 'status' => 'active', 'hidden' => 0],
            ['id' => 3, 'company_id' => 1, 'qty' => null, 'taxable' => 0, 'status' => 'active', 'hidden' => 0],
        ],
        'package_names' => [['package_id' => 1, 'lang' => 'en_us', 'name' => 'Starter'], ['package_id' => 2, 'lang' => 'en_us', 'name' => '.com'], ['package_id' => 3, 'lang' => 'en_us', 'name' => 'Weekly']],
        'package_descriptions' => [['package_id' => 1, 'lang' => 'en_us', 'text' => 'Small sites']],
        'pricings' => [
            ['id' => 1, 'company_id' => 1, 'term' => 1, 'period' => 'month', 'price' => 5, 'price_renews' => null, 'price_transfer' => null, 'setup_fee' => 2, 'currency' => 'USD'],
            ['id' => 2, 'company_id' => 1, 'term' => 1, 'period' => 'year', 'price' => 50, 'price_renews' => null, 'price_transfer' => null, 'setup_fee' => 0, 'currency' => 'USD'],
            ['id' => 3, 'company_id' => 1, 'term' => 1, 'period' => 'year', 'price' => 10, 'price_renews' => 12, 'price_transfer' => 8, 'setup_fee' => 0, 'currency' => 'USD'],
            ['id' => 4, 'company_id' => 1, 'term' => 2, 'period' => 'year', 'price' => 18, 'price_renews' => 22, 'price_transfer' => null, 'setup_fee' => 0, 'currency' => 'USD'],
            ['id' => 5, 'company_id' => 1, 'term' => 1, 'period' => 'week', 'price' => 3, 'price_renews' => null, 'price_transfer' => null, 'setup_fee' => 0, 'currency' => 'USD'],
            ['id' => 6, 'company_id' => 1, 'term' => 1, 'period' => 'week', 'price' => 3, 'price_renews' => null, 'price_transfer' => null, 'setup_fee' => 0, 'currency' => 'EUR'],
            ['id' => 7, 'company_id' => 1, 'term' => 0, 'period' => 'onetime', 'price' => 100, 'price_renews' => null, 'price_transfer' => null, 'setup_fee' => 0, 'currency' => 'USD'],
        ],
        'package_pricing' => [['id' => 1, 'package_id' => 1, 'pricing_id' => 1], ['id' => 2, 'package_id' => 1, 'pricing_id' => 2], ['id' => 3, 'package_id' => 2, 'pricing_id' => 3], ['id' => 4, 'package_id' => 2, 'pricing_id' => 4], ['id' => 5, 'package_id' => 3, 'pricing_id' => 5], ['id' => 6, 'package_id' => 3, 'pricing_id' => 6], ['id' => 7, 'package_id' => 1, 'pricing_id' => 7]],
        'domains_tlds' => [['tld' => '.com', 'company_id' => 1, 'package_id' => 2]],
        'domains_domains' => [],
        'users' => [
            ['id' => 1, 'password' => password_hash(hash_hmac('sha256', 'secret', 'k3y'), PASSWORD_BCRYPT), 'two_factor_mode' => 'none', 'date_added' => '2025-01-01 10:00:00'],
            ['id' => 2, 'password' => password_hash('x', PASSWORD_BCRYPT), 'two_factor_mode' => 'motp', 'date_added' => '2025-02-01 10:00:00'],
        ],
        'clients' => [['id' => 1, 'user_id' => 1, 'client_group_id' => 1, 'status' => 'active'], ['id' => 2, 'user_id' => 2, 'client_group_id' => 1, 'status' => 'fraud']],
        'client_settings' => [
            ['client_id' => 1, 'key' => 'tax_id', 'value' => 'GB123', 'encrypted' => 0],
            ['client_id' => 1, 'key' => 'default_currency', 'value' => 'USD', 'encrypted' => 0],
            ['client_id' => 1, 'key' => 'language', 'value' => 'bg_bg', 'encrypted' => 0],
            ['client_id' => 2, 'key' => 'default_currency', 'value' => 'EUR', 'encrypted' => 0],
        ],
        'contacts' => [
            ['id' => 1, 'client_id' => 1, 'user_id' => null, 'contact_type' => 'primary', 'first_name' => 'Ann', 'last_name' => 'Lee', 'company' => 'Lee Ltd', 'email' => 'ann@example.test', 'address1' => '1 Road', 'address2' => null, 'city' => 'London', 'state' => 'LND', 'zip' => 'N1', 'country' => 'GB', 'date_added' => '2025-01-01 10:00:00'],
            ['id' => 2, 'client_id' => 1, 'user_id' => null, 'contact_type' => 'billing', 'first_name' => 'Bob', 'last_name' => 'Lee', 'company' => null, 'email' => 'bills@example.test', 'address1' => null, 'address2' => null, 'city' => null, 'state' => null, 'zip' => null, 'country' => 'GB', 'date_added' => '2025-01-02 10:00:00'],
            ['id' => 3, 'client_id' => 2, 'user_id' => null, 'contact_type' => 'primary', 'first_name' => 'Eve', 'last_name' => 'Fox', 'company' => null, 'email' => 'eve@example.test', 'address1' => null, 'address2' => null, 'city' => null, 'state' => null, 'zip' => null, 'country' => 'DE', 'date_added' => '2025-02-01 10:00:00'],
        ],
        'contact_numbers' => [['id' => 1, 'contact_id' => 1, 'number' => '+44.2071234567', 'type' => 'phone']],
        'client_notes' => [
            ['id' => 1, 'client_id' => 1, 'title' => 'VIP', 'description' => 'Pays early.', 'stickied' => 1, 'encrypted' => 0, 'date_added' => '2025-03-01 10:00:00', 'date_updated' => null],
            ['id' => 2, 'client_id' => 1, 'title' => 'Secret', 'description' => 'xyz', 'stickied' => 0, 'encrypted' => 1, 'date_added' => '2025-03-01 10:00:00', 'date_updated' => null],
        ],
        'services' => [
            ['id' => 1, 'pricing_id' => 1, 'client_id' => 1, 'qty' => 1, 'override_price' => null, 'override_currency' => null, 'status' => 'active', 'suspension_reason' => null, 'cancellation_reason' => null, 'date_added' => '2025-01-05 10:00:00', 'date_renews' => '2026-12-05 10:00:00', 'date_canceled' => null],
            ['id' => 2, 'pricing_id' => 3, 'client_id' => 1, 'qty' => 1, 'override_price' => null, 'override_currency' => null, 'status' => 'active', 'suspension_reason' => null, 'cancellation_reason' => null, 'date_added' => '2025-01-05 10:00:00', 'date_renews' => '2027-01-05 10:00:00', 'date_canceled' => null],
            ['id' => 3, 'pricing_id' => 5, 'client_id' => 1, 'qty' => 1, 'override_price' => null, 'override_currency' => null, 'status' => 'active', 'suspension_reason' => null, 'cancellation_reason' => null, 'date_added' => '2025-01-05 10:00:00', 'date_renews' => '2026-11-12 10:00:00', 'date_canceled' => null],
            ['id' => 4, 'pricing_id' => 2, 'client_id' => 1, 'qty' => 2, 'override_price' => 40, 'override_currency' => null, 'status' => 'active', 'suspension_reason' => null, 'cancellation_reason' => 'Moving away', 'date_added' => '2025-01-05 10:00:00', 'date_renews' => '2027-01-05 10:00:00', 'date_canceled' => '2027-01-05 10:00:00'],
        ],
        'service_fields' => [['service_id' => 1, 'key' => 'domain', 'value' => 'site.example', 'encrypted' => 0], ['service_id' => 2, 'key' => 'domain', 'value' => 'Ann.com', 'encrypted' => 0]],
        'invoices' => [
            ['id' => 1, 'client_id' => 1, 'id_format' => '{num}', 'id_value' => 1001, 'status' => 'active', 'date_billed' => '2026-11-01 00:00:00', 'date_due' => '2026-11-08 00:00:00', 'date_closed' => '2026-11-02 09:00:00', 'subtotal' => 7, 'total' => 8.4, 'paid' => 8.4, 'currency' => 'USD', 'note_private' => null],
            ['id' => 2, 'client_id' => 1, 'id_format' => '{num}', 'id_value' => 1002, 'status' => 'active', 'date_billed' => '2026-11-03 00:00:00', 'date_due' => '2026-11-10 00:00:00', 'date_closed' => null, 'subtotal' => 10, 'total' => 12, 'paid' => 5, 'currency' => 'USD', 'note_private' => 'Call first'],
            ['id' => 3, 'client_id' => 1, 'id_format' => '{num}', 'id_value' => 1003, 'status' => 'void', 'date_billed' => '2026-11-03 00:00:00', 'date_due' => '2026-11-10 00:00:00', 'date_closed' => null, 'subtotal' => 1, 'total' => 1, 'paid' => 0, 'currency' => 'USD', 'note_private' => null],
            ['id' => 5, 'client_id' => 1, 'id_format' => '{num}', 'id_value' => 1005, 'status' => 'active', 'date_billed' => '2026-11-03 00:00:00', 'date_due' => '2026-11-10 00:00:00', 'date_closed' => null, 'subtotal' => 10, 'total' => 11, 'paid' => 0, 'currency' => 'USD', 'note_private' => null],
            ['id' => 4, 'client_id' => 1, 'id_format' => 'DRAFT-{num}', 'id_value' => 4, 'status' => 'draft', 'date_billed' => '2026-11-03 00:00:00', 'date_due' => '2026-11-10 00:00:00', 'date_closed' => null, 'subtotal' => 1, 'total' => 1, 'paid' => 0, 'currency' => 'USD', 'note_private' => null],
        ],
        'invoice_lines' => [
            ['id' => 1, 'invoice_id' => 1, 'service_id' => 1, 'description' => 'Starter', 'qty' => 1, 'amount' => 5, 'order' => 0],
            ['id' => 2, 'invoice_id' => 1, 'service_id' => null, 'description' => 'Setup', 'qty' => 1, 'amount' => 2, 'order' => 1],
            ['id' => 3, 'invoice_id' => 2, 'service_id' => 2, 'description' => 'Ann.com', 'qty' => 2, 'amount' => 5, 'order' => 0],
            ['id' => 4, 'invoice_id' => 2, 'service_id' => 2, 'description' => 'Transfer lock', 'qty' => 1, 'amount' => 0, 'order' => 1],
            ['id' => 5, 'invoice_id' => 5, 'service_id' => null, 'description' => 'Support hours', 'qty' => 1, 'amount' => 11, 'order' => 0],
        ],
        'invoice_line_taxes' => [['line_id' => 1, 'tax_id' => 1], ['line_id' => 2, 'tax_id' => 1], ['line_id' => 3, 'tax_id' => 1], ['line_id' => 5, 'tax_id' => 2]],
        'transaction_types' => [['id' => 1, 'name' => 'cash']],
        'transactions' => [
            ['id' => 1, 'client_id' => 1, 'amount' => 8.4, 'currency' => 'USD', 'type' => 'cc', 'transaction_type_id' => null, 'gateway_id' => 1, 'transaction_id' => 'ch_1', 'status' => 'approved', 'date_added' => '2026-11-02 09:00:00'],
            ['id' => 2, 'client_id' => 1, 'amount' => 30, 'currency' => 'USD', 'type' => 'other', 'transaction_type_id' => 1, 'gateway_id' => null, 'transaction_id' => null, 'status' => 'approved', 'date_added' => '2026-11-04 09:00:00'],
            ['id' => 3, 'client_id' => 1, 'amount' => 4, 'currency' => 'USD', 'type' => 'other', 'transaction_type_id' => 1, 'gateway_id' => null, 'transaction_id' => null, 'status' => 'refunded', 'date_added' => '2026-11-04 09:00:00'],
            ['id' => 4, 'client_id' => 1, 'amount' => 9, 'currency' => 'USD', 'type' => 'cc', 'transaction_type_id' => null, 'gateway_id' => 1, 'transaction_id' => 'ch_x', 'status' => 'declined', 'date_added' => '2026-11-04 09:00:00'],
        ],
        'transaction_applied' => [['transaction_id' => 1, 'invoice_id' => 1, 'amount' => 8.4, 'date' => '2026-11-02 09:00:00'], ['transaction_id' => 2, 'invoice_id' => 2, 'amount' => 5, 'date' => '2026-11-04 09:00:00']],
        'staff' => [['id' => 1, 'first_name' => 'Sam', 'last_name' => 'Ops']],
        'support_departments' => [['id' => 1, 'company_id' => 1, 'name' => 'Support', 'description' => 'Help', 'email' => 'help@example.test', 'status' => 'visible']],
        'support_tickets' => [['id' => 1, 'code' => 4242, 'department_id' => 1, 'client_id' => 1, 'email' => null, 'summary' => 'Help', 'priority' => 'high', 'status' => 'awaiting_reply', 'service_id' => 1, 'date_added' => '2026-11-05 09:00:00', 'date_updated' => '2026-11-05 10:00:00']],
        'support_replies' => [
            ['id' => 1, 'ticket_id' => 1, 'staff_id' => null, 'contact_id' => 1, 'type' => 'reply', 'details' => 'My site is down.', 'date_added' => '2026-11-05 09:00:00'],
            ['id' => 2, 'ticket_id' => 1, 'staff_id' => 1, 'contact_id' => null, 'type' => 'reply', 'details' => 'We are on it.', 'date_added' => '2026-11-05 09:30:00'],
            ['id' => 3, 'ticket_id' => 1, 'staff_id' => 1, 'contact_id' => null, 'type' => 'note', 'details' => 'Server 3.', 'date_added' => '2026-11-05 09:40:00'],
            ['id' => 4, 'ticket_id' => 1, 'staff_id' => 1, 'contact_id' => null, 'type' => 'log', 'details' => 'Status changed.', 'date_added' => '2026-11-05 10:00:00'],
        ],
        'support_attachments' => [
            ['id' => 7, 'reply_id' => 1, 'name' => 'shot.png', 'file_name' => '/var/blesta/uploads/1/support_manager_files/a1b2c3'],
            // A path outside the support folder (a hostile source naming a local file) is never copied.
            ['id' => 8, 'reply_id' => 1, 'name' => 'notes.txt', 'file_name' => sys_get_temp_dir() . '/cf-blesta-test-' . getmypid() . '/secret.txt'],
        ],
    ];
}

/** The fake Blesta as an SQLite file, its attachment in $files, and the run state on an in-memory database. */
function blestaDi(): array
{
    $dir = sys_get_temp_dir() . '/cf-blesta-test-' . getmypid();
    putenv('CLIENTFENCE_BACKUP_DIR=' . $dir . '/backups');
    @mkdir($dir . '/files', 0700, true);
    file_put_contents($dir . '/files/a1b2c3', 'PNG');
    file_put_contents($dir . '/secret.txt', 'DB_PASSWORD');
    @unlink($dir . '/blesta.sqlite');
    $blesta = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dir . '/blesta.sqlite']);
    foreach (blestaFake() as $table => $rows) {
        // Typed as the first row's values, so numbers compare as numbers (as in MySQL).
        $first = $rows[0] ?? ['service_id' => 0, 'registration_date' => '', 'expiration_date' => ''];
        $blesta->executeStatement(sprintf('CREATE TABLE %s (%s)', $table, implode(', ', array_map(fn (string $c): string => sprintf('"%s" %s', $c, is_int($first[$c]) ? 'INTEGER' : (is_float($first[$c]) ? 'REAL' : '')), array_keys($first)))));
        foreach ($rows as $row) {
            $blesta->insert($table, array_combine(array_map(fn (string $c): string => '"' . $c . '"', array_keys($row)), $row));
        }
    }
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE mod_clientfence_state (name TEXT PRIMARY KEY, value TEXT, updated_at TEXT)');
    $db->executeStatement('CREATE TABLE ' . Run::WARNINGS . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT, step TEXT, message TEXT, created_at TEXT)');
    $di = new Pimple\Container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;

    return [$di, new Source($blesta), ['attachments_dir' => $dir . '/files']];
}

afterEach(function (): void {
    $dir = sys_get_temp_dir() . '/cf-blesta-test-' . getmypid();
    array_map('unlink', array_merge(glob(Snapshot::files() . '/*') ?: [], glob($dir . '/files/*') ?: []));
    @rmdir(Snapshot::files());
    @unlink(Snapshot::path());
    @rmdir(dirname(Snapshot::path()));
    @unlink($dir . '/secret.txt');
    @rmdir($dir . '/files');
    @unlink($dir . '/blesta.sqlite');
    @rmdir($dir);
    putenv('CLIENTFENCE_BACKUP_DIR');
});

test('Blesta is read a page at a time into WHMCS\'s tables, and what cannot come over is left out with a warning', function (): void {
    [$di, $source, $settings] = blestaDi();
    $calls = 0;
    do {
        $state = Snapshot::fetch($di, $settings, 0.000001, false, $source);
        ++$calls;
    } while ($state['finished_at'] === null && $calls < 50);
    $db = Snapshot::open();
    $warnings = implode("\n", Run::warnings($di['em']->getConnection()));

    expect($state['finished_at'])->not->toBeNull()
        ->and($calls)->toBeGreaterThan(5)
        ->and(substr(sprintf('%o', fileperms(Snapshot::path())), -4))->toBe('0600')
        ->and($db->fetchAssociative('SELECT firstname, companyname, email, tax_id, phonenumber, state, credit, status, groupid, language FROM tblclients WHERE id = 1'))
        ->toBe(['firstname' => 'Ann', 'companyname' => 'Lee Ltd', 'email' => 'ann@example.test', 'tax_id' => 'GB123', 'phonenumber' => '+44.2071234567', 'state' => 'LND', 'credit' => 25.0, 'status' => 'Active', 'groupid' => 1, 'language' => 'bg_bg'])
        // Blesta's language code is kept as it is; the WHMCS import maps it (WhmcsAccountsTest). A client without one has none.
        ->and($db->fetchOne('SELECT language FROM tblclients WHERE id = 2'))->toBe('')
        // A client's currency is its own Blesta default.
        ->and($db->fetchAllKeyValue('SELECT c.id, cur.code FROM tblclients c JOIN tblcurrencies cur ON cur.id = c.currency ORDER BY c.id'))->toBe([1 => 'USD', 2 => 'EUR'])
        ->and($db->fetchOne('SELECT status FROM tblclients WHERE id = 2'))->toBe('Closed')
        ->and($warnings)->toContain('Client #2 is marked as fraud in Blesta')
        ->and($warnings)->toContain('Client eve@example.test used two-factor sign-in in Blesta')
        // The kept hash is Blesta's own: the sign-in checks it with the system key (blestaPassword()).
        ->and($db->fetchOne('SELECT password FROM tblusers WHERE id = 1'))->toBe($db->fetchOne('SELECT password FROM tblclients WHERE id = 1'))
        ->and($db->fetchAllAssociative('SELECT userid, email, invoiceemails FROM tblcontacts'))->toBe([['userid' => 1, 'email' => 'bills@example.test', 'invoiceemails' => 1]])
        ->and($db->fetchAllAssociative('SELECT note, sticky FROM tblnotes'))->toBe([['note' => "VIP\n\nPays early.", 'sticky' => 1]])
        ->and($warnings)->toContain('A note of client #1 is encrypted in Blesta');

    // The catalogue: packages (the TLD packages become domain prices), groups without the Domains plugin's own.
    expect($db->fetchAllAssociative('SELECT id, name, gid, tax, paytype FROM tblproducts ORDER BY id'))->toBe([
        ['id' => 1, 'name' => 'Starter', 'gid' => 1, 'tax' => 1, 'paytype' => 'recurring'],
        ['id' => 3, 'name' => 'Weekly', 'gid' => 1, 'tax' => 0, 'paytype' => 'recurring'],
    ])
        ->and($db->fetchFirstColumn('SELECT name FROM tblproductgroups'))->toBe(['Hosting'])
        ->and($db->fetchAssociative("SELECT monthly, annually, msetupfee, asetupfee, quarterly FROM tblpricing WHERE type = 'product' AND relid = 1"))->toBe(['monthly' => 5.0, 'annually' => 50.0, 'msetupfee' => 2.0, 'asetupfee' => 0.0, 'quarterly' => -1.0])
        // Named once, though priced in two currencies.
        ->and(substr_count($warnings, 'Package "Weekly": its 1-week price was not imported'))->toBe(1)
        // A one-time price of a renewing package is left out, not taken for the monthly one.
        ->and($warnings)->toContain('Package "Starter": its one-time price was not imported')
        ->and($db->fetchFirstColumn('SELECT extension FROM tbldomainpricing'))->toBe(['.com'])
        ->and($db->fetchAllKeyValue("SELECT type, msetupfee || '/' || qsetupfee FROM tblpricing WHERE type LIKE 'domain%' ORDER BY type"))->toBe(['domainregister' => '10.0/18.0', 'domainrenew' => '12.0/22.0', 'domaintransfer' => '8.0/-1.0'])
        ->and($db->fetchAllKeyValue("SELECT setting, value FROM tblconfiguration WHERE setting LIKE 'Tax%' ORDER BY setting"))->toBe(['TaxDomains' => 'on', 'TaxEnabled' => 'on', 'TaxType' => 'Exclusive'])
        ->and($warnings)->toContain('Blesta has taxes added to prices and taxes included in them')
        ->and($db->fetchAllAssociative('SELECT level, name, country, taxrate FROM tbltax'))->toBe([['level' => '1', 'name' => 'VAT', 'country' => 'GB', 'taxrate' => 20.0], ['level' => '1', 'name' => 'GST', 'country' => 'AU', 'taxrate' => 10.0]]);

    // Services renew at their price (an override price times the quantity); a domain package's service is a domain.
    expect($db->fetchAllAssociative('SELECT id, packageid, amount, billingcycle, domainstatus, nextduedate, domain FROM tblhosting ORDER BY id'))->toBe([
        ['id' => 1, 'packageid' => 1, 'amount' => 5.0, 'billingcycle' => 'Monthly', 'domainstatus' => 'Active', 'nextduedate' => '2026-12-05', 'domain' => 'site.example'],
        ['id' => 4, 'packageid' => 1, 'amount' => 80.0, 'billingcycle' => 'Annually', 'domainstatus' => 'Active', 'nextduedate' => '2027-01-05', 'domain' => ''],
    ])
        ->and($warnings)->toContain('Service #3 of client #1 was not imported: it renews every 1 week(s)')
        // A cancellation set for a later date waits in Cancellation Requests.
        ->and($db->fetchAllAssociative('SELECT relid, reason, type FROM tblcancelrequests'))->toBe([['relid' => 4, 'reason' => 'Moving away', 'type' => 'End of Billing Period']])
        ->and($db->fetchAssociative('SELECT domain, recurringamount, registrationperiod, expirydate, status FROM tbldomains'))->toBe(['domain' => 'ann.com', 'recurringamount' => 12.0, 'registrationperiod' => 1, 'expirydate' => '2027-01-05', 'status' => 'Active']);

    // Billing: Blesta's numbers, statuses, the tax of the taxed lines; payments on invoices, the rest is credit.
    expect($db->fetchAllAssociative('SELECT invoicenum, status, subtotal, tax, total, taxrate FROM tblinvoices ORDER BY id'))->toBe([
        ['invoicenum' => '1001', 'status' => 'Paid', 'subtotal' => 7.0, 'tax' => 1.4, 'total' => 8.4, 'taxrate' => 20.0],
        ['invoicenum' => '1002', 'status' => 'Unpaid', 'subtotal' => 10.0, 'tax' => 2.0, 'total' => 12.0, 'taxrate' => 20.0],
        ['invoicenum' => '1003', 'status' => 'Cancelled', 'subtotal' => 1.0, 'tax' => 0.0, 'total' => 1.0, 'taxrate' => 0.0],
        ['invoicenum' => 'DRAFT-4', 'status' => 'Draft', 'subtotal' => 1.0, 'tax' => 0.0, 'total' => 1.0, 'taxrate' => 0.0],
        // Tax inside the price: Blesta's subtotal is the net; the rate is the rule's, not the tax over the gross line.
        ['invoicenum' => '1005', 'status' => 'Unpaid', 'subtotal' => 10.0, 'tax' => 1.0, 'total' => 11.0, 'taxrate' => 10.0],
    ])
        ->and($db->fetchAllAssociative('SELECT type, relid, description, amount, taxed FROM tblinvoiceitems ORDER BY id'))->toBe([
            ['type' => 'Hosting', 'relid' => 1, 'description' => 'Starter', 'amount' => 5.0, 'taxed' => 1],
            ['type' => '', 'relid' => 0, 'description' => 'Setup', 'amount' => 2.0, 'taxed' => 1],
            ['type' => 'Domain', 'relid' => 2, 'description' => '2 x Ann.com', 'amount' => 10.0, 'taxed' => 1],
            // A second line of the same service is a plain item: paying the invoice renews the domain once.
            ['type' => '', 'relid' => 0, 'description' => 'Transfer lock', 'amount' => 0.0, 'taxed' => 0],
            ['type' => '', 'relid' => 0, 'description' => 'Support hours', 'amount' => 11.0, 'taxed' => 1],
        ])
        ->and($db->fetchOne("SELECT value FROM tblconfiguration WHERE setting = 'SequentialInvoiceNumberFormat'"))->toBe('{NUMBER}')
        ->and($db->fetchAllAssociative('SELECT invoiceid, gateway, amountin, transid FROM tblaccounts ORDER BY id'))->toBe([
            ['invoiceid' => 1, 'gateway' => 'stripe_payments', 'amountin' => 8.4, 'transid' => 'ch_1'],
            ['invoiceid' => 2, 'gateway' => 'cash', 'amountin' => 5.0, 'transid' => 'blesta-2'],
        ])
        ->and($db->fetchFirstColumn('SELECT amount FROM tblcredit'))->toBe([25.0])
        ->and($warnings)->toContain('Payment #3 of client #1 was refunded in Blesta');

    // Support: the first reply opens the ticket; staff replies, notes and attachments keep their kind; logs are left out.
    expect($db->fetchAssociative('SELECT tid, title, message, status, urgency, email, attachment, service FROM tbltickets'))->toBe(['tid' => '4242', 'title' => 'Help', 'message' => 'My site is down.', 'status' => 'Answered', 'urgency' => 'High', 'email' => 'ann@example.test', 'attachment' => '7_shot.png|8_notes.txt', 'service' => 'S1'])
        ->and($db->fetchAllAssociative('SELECT admin, message FROM tblticketreplies'))->toBe([['admin' => 'Sam Ops', 'message' => 'We are on it.']])
        ->and($db->fetchFirstColumn('SELECT message FROM tblticketnotes'))->toBe(['Server 3.'])
        // Found in the attachments folder by its stored name, copied under the name the WHMCS step reads.
        ->and(file_get_contents(Snapshot::files() . '/7_shot.png'))->toBe('PNG')
        ->and(substr(sprintf('%o', fileperms(Snapshot::files() . '/7_shot.png')), -4))->toBe('0600')
        ->and(file_exists(Snapshot::files() . '/8_notes.txt'))->toBeFalse();

    $plan = Inventory::plan(new Source($db));
    expect($plan['version'])->toBe('8.0.0 (Blesta 6.0.4)')
        ->and($plan['modules'])->toBe([]);
});

test('the import reads Blesta only once a read has finished, and reading needs the saved details', function (): void {
    [$di, $source, $settings] = blestaDi();
    expect(fn () => Import::fetchBlesta($di, 1.0))->toThrow(ClientFence\InformationException::class, 'Save the Blesta database details first.')
        ->and(fn () => Source::open($di, ['blesta' => 1]))->toThrow(ClientFence\InformationException::class, 'Read Blesta first, until it finishes.');

    Snapshot::fetch($di, $settings, 0.000001, false, $source);
    expect(fn () => Source::open($di, ['blesta' => 1]))->toThrow(ClientFence\InformationException::class, 'Read Blesta first, until it finishes.');

    Snapshot::fetch($di, $settings, 0.0, false, $source);
    $snapshot = Source::open($di, ['blesta' => 1]);
    expect($snapshot->isWhmcs())->toBeTrue();
    $snapshot->close();
});

test('a password kept from Blesta signs in once with the saved system key, then is rehashed', function (): void {
    [$di] = blestaDi();
    $di['crypt'] = new class {
        public function decrypt(string $value): string
        {
            return substr($value, 4);
        }
    };
    $di['password'] = new ClientFence\PasswordManager();
    $stored = [];
    $di['db'] = Mockery::mock();
    $di['db']->shouldReceive('store')->andReturnUsing(function (object $client) use (&$stored): int {
        $stored[] = $client->pass;

        return 1;
    });
    $client = (object) ['pass' => password_hash(hash_hmac('sha256', 'secret', 'k3y'), PASSWORD_BCRYPT)];

    // No saved key: nothing to check with.
    expect(Import::blestaPassword($di, $client, 'secret'))->toBeFalse();

    $di['em']->getConnection()->insert('mod_clientfence_state', ['name' => Import::SETTINGS, 'value' => json_encode(['blesta' => 1, 'blesta_key' => 'enc:k3y']), 'updated_at' => '2026-11-09 10:00:00']);
    expect(Import::blestaPassword($di, $client, 'wrong'))->toBeFalse()
        ->and($stored)->toBe([])
        ->and(Import::blestaPassword($di, $client, 'secret'))->toBeTrue()
        ->and($stored)->toHaveCount(1)
        // Rehashed the normal way: the next sign-in is a plain password check.
        ->and(password_verify('secret', $client->pass))->toBeTrue()
        ->and(Import::blestaPassword($di, $client, 'secret'))->toBeFalse();
});
