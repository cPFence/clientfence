<?php

declare(strict_types=1);

/**
 * ClientFence: the ClientExec import's read (Clientexec\Snapshot) from an invented ClientExec
 * database (its tables as ClientExec 7 names them, on SQLite) into the WHMCS-shaped file the WHMCS
 * import reads.
 */

use ClientFence\Mod\Clientfence\Clientexec\Snapshot;
use ClientFence\Mod\Clientfence\Whmcs\Import;
use ClientFence\Mod\Clientfence\Whmcs\Inventory;
use ClientFence\Mod\Clientfence\Whmcs\Run;
use ClientFence\Mod\Clientfence\Whmcs\Source;
use Doctrine\DBAL\DriverManager;

/** ClientExec's tables (the columns the read uses) and rows, keyed by table. */
function clientexecFake(): array
{
    $price = fn (int $item, string $currency, string $cycle, string $field, string $value): array => ['type' => 1, 'itemid' => $item, 'currency_abrv' => $currency, 'billing_cycle' => $cycle, 'field' => $field, 'value' => $value];
    $fee = fn (int $id, int $client, int $service, float $amount, string $next, int $term, int $recurring = 1, int $type = -1): array => ['id' => $id, 'customerid' => $client, 'billingtypeid' => $type, 'amount' => $amount, 'quantity' => 1.0, 'appliestoid' => $service, 'nextbilldate' => $next, 'paymentterm' => $term, 'recurring' => $recurring];

    return [
        'setting' => [['name' => 'ClientExec Version', 'value' => '7.3.0'], ['name' => 'Default Currency', 'value' => 'USD'], ['name' => 'Domains Taxable', 'value' => '0']],
        'currency' => [
            ['abrv' => 'USD', 'symbol' => '$', 'alignment' => 'left', 'precision' => 2, 'rate' => 1.0, 'enabled' => 1],
            ['abrv' => 'EUR', 'symbol' => '€', 'alignment' => 'right', 'precision' => 2, 'rate' => 0.9, 'enabled' => 1],
            ['abrv' => 'JPY', 'symbol' => '¥', 'alignment' => 'left', 'precision' => 0, 'rate' => 150.0, 'enabled' => 0],
        ],
        'taxrule' => [['id' => 1, 'countryiso' => '_ALL', 'state' => '_ALL', 'tax' => 20.0, 'name' => 'VAT', 'level' => 1]],
        'groups' => [
            ['id' => 1, 'name' => 'Client', 'isadmin' => 0, 'iscustomersmaingroup' => 1, 'groupcolor' => null],
            ['id' => 2, 'name' => 'Super Admin', 'isadmin' => 1, 'iscustomersmaingroup' => 0, 'groupcolor' => null],
            ['id' => 6, 'name' => 'Priority Clients', 'isadmin' => 0, 'iscustomersmaingroup' => 0, 'groupcolor' => '#dd5555'],
        ],
        'user_groups' => [['user_id' => 101, 'group_id' => 1], ['user_id' => 101, 'group_id' => 6]],
        'promotion' => [['id' => 5, 'name' => 'Web Hosting', 'type' => 1, 'groupOrder' => 1], ['id' => 2, 'name' => 'Domain Services', 'type' => 3, 'groupOrder' => 99]],
        'departments' => [['id' => 1, 'name' => 'General', 'is_generaldep' => 1], ['id' => 2, 'name' => 'Billing', 'is_generaldep' => 0]],
        'coupons' => [['coupons_id' => 1, 'coupons_archive' => 0], ['coupons_id' => 2, 'coupons_archive' => 1]],
        'packageaddon' => [['id' => 1, 'package_id' => 1]],
        'troubleticket_files' => [],
        'billing_cycle' => [
            ['id' => 0, 'name' => 'One Time', 'time_unit' => 'm', 'amount_of_units' => 0],
            ['id' => 1, 'name' => '1 Month', 'time_unit' => 'm', 'amount_of_units' => 1],
            ['id' => 12, 'name' => '1 Year', 'time_unit' => 'y', 'amount_of_units' => 1],
            ['id' => 24, 'name' => '2 Years', 'time_unit' => 'y', 'amount_of_units' => 2],
            ['id' => 60, 'name' => '5 Years', 'time_unit' => 'y', 'amount_of_units' => 5],
            ['id' => 132, 'name' => '11 Years', 'time_unit' => 'y', 'amount_of_units' => 11],
            ['id' => 121, 'name' => '1 Week', 'time_unit' => 'w', 'amount_of_units' => 1],
        ],
        'package' => [
            ['id' => 1, 'planname' => 'Starter', 'planid' => 5, 'showpackage' => 1, 'description' => '<p>Small sites</p>'],
            ['id' => 2, 'planname' => 'Migration', 'planid' => 5, 'showpackage' => 0, 'description' => ''],
            ['id' => 3, 'planname' => 'Backup Check', 'planid' => 5, 'showpackage' => 1, 'description' => ''],
            ['id' => 9, 'planname' => 'com', 'planid' => 2, 'showpackage' => 0, 'description' => ''],
        ],
        'prices' => [
            $price(1, 'USD', '0', 'included', '0'), $price(1, 'USD', '0', 'price', ''),
            $price(1, 'USD', '1', 'included', '1'), $price(1, 'USD', '1', 'price', '5.0000'), $price(1, 'USD', '1', 'setup', '2.0000'),
            $price(1, 'USD', '12', 'included', '1'), $price(1, 'USD', '12', 'price', '50.0000'), $price(1, 'USD', '12', 'setup', '0.0000'),
            // A cycle that is not offered keeps no price.
            $price(1, 'USD', '24', 'included', '0'), $price(1, 'USD', '24', 'price', '90.0000'),
            $price(1, 'USD', 'all', 'taxable', '1'),
            $price(1, 'EUR', '1', 'included', '1'), $price(1, 'EUR', '1', 'price', '4.5000'), $price(1, 'EUR', 'all', 'taxable', '1'),
            $price(2, 'USD', '0', 'included', '1'), $price(2, 'USD', '0', 'price', '49.0000'), $price(2, 'USD', 'all', 'taxable', '0'),
            $price(3, 'USD', '121', 'included', '1'), $price(3, 'USD', '121', 'price', '2.0000'),
            $price(3, 'EUR', '121', 'included', '1'), $price(3, 'EUR', '121', 'price', '2.0000'),
            $price(9, 'USD', '12', 'price', '11.9500'), $price(9, 'USD', '12', 'renew', '12.9500'), $price(9, 'USD', '12', 'transfer', '9.0000'),
            $price(9, 'USD', '24', 'price', '22.0000'), $price(9, 'USD', '24', 'renew', ''),
            // Domains sell for up to 10 years.
            $price(9, 'USD', '60', 'price', '50.0000'), $price(9, 'USD', '132', 'price', '99.0000'),
            // A coupon's price row (type 4) is not a package's.
            ['type' => 4, 'itemid' => 1, 'currency_abrv' => 'USD', 'billing_cycle' => 'all', 'field' => 'price', 'value' => '0.1'],
        ],
        'users' => [
            ['id' => 100, 'groupid' => 2, 'status' => 1, 'firstname' => 'Sam', 'lastname' => 'Ops', 'email' => 'staff@example.test', 'organization' => '', 'currency' => '0', 'balance' => 0.0, 'taxable' => 0, 'password' => null, 'dateActivated' => null, 'created_at' => '0000-00-00 00:00:00', 'usernotes' => null],
            ['id' => 101, 'groupid' => 1, 'status' => 1, 'firstname' => 'Ann', 'lastname' => 'Lee', 'email' => 'ann@example.test', 'organization' => 'Lee Ltd', 'currency' => 'USD', 'balance' => 25.0, 'taxable' => 1, 'password' => password_hash('secret', PASSWORD_BCRYPT), 'dateActivated' => '2025-01-05', 'created_at' => '0000-00-00 00:00:00', 'usernotes' => 'Pays early.'],
            ['id' => 102, 'groupid' => 1, 'status' => 9, 'firstname' => 'Eve', 'lastname' => 'Fox', 'email' => 'eve@example.test', 'organization' => '', 'currency' => 'EUR', 'balance' => 0.0, 'taxable' => 0, 'password' => null, 'dateActivated' => '0000-00-00', 'created_at' => '2025-02-01 10:00:00', 'usernotes' => ''],
        ],
        'customuserfields' => [['id' => 3, 'name' => 'Address', 'type' => 2], ['id' => 5, 'name' => 'City', 'type' => 3], ['id' => 8, 'name' => 'Phone', 'type' => 7], ['id' => 15, 'name' => 'Country', 'type' => 6], ['id' => 41, 'name' => 'VAT Number', 'type' => 47], ['id' => 62, 'name' => 'TwoFactor Enabled', 'type' => 0]],
        'user_customuserfields' => [
            ['userid' => 101, 'customid' => 3, 'value' => '1 Road'], ['userid' => 101, 'customid' => 5, 'value' => 'London'], ['userid' => 101, 'customid' => 8, 'value' => '+44 20 7123 4567'],
            ['userid' => 101, 'customid' => 15, 'value' => 'gb'], ['userid' => 101, 'customid' => 41, 'value' => 'GB123'], ['userid' => 102, 'customid' => 62, 'value' => '1'],
        ],
        'clients_notes' => [['id' => 1, 'date' => '2025-03-01 10:00:00', 'target_id' => 101, 'is_target_group' => 0, 'note' => 'Called about renewal.', 'subject' => 'Call']],
        'customField' => [['id' => 2, 'groupId' => 2, 'subGroupId' => 3, 'name' => 'Domain Name'], ['id' => 12, 'groupId' => 2, 'subGroupId' => 1, 'name' => 'Domain Name'], ['id' => 10, 'groupId' => 2, 'subGroupId' => 1, 'name' => 'User Name'], ['id' => 21, 'groupId' => 2, 'subGroupId' => 3, 'name' => 'Expiration Date'], ['id' => 34, 'groupId' => 2, 'subGroupId' => 0, 'name' => 'Cancellation Reason']],
        'domains' => [
            ['id' => 1, 'CustomerID' => 101, 'status' => 1, 'Plan' => 1, 'dateActivated' => '2025-01-05 10:00:00', 'use_custom_price' => 1, 'custom_price' => 5.0],
            ['id' => 2, 'CustomerID' => 101, 'status' => 1, 'Plan' => 9, 'dateActivated' => '2025-01-05 10:00:00', 'use_custom_price' => 1, 'custom_price' => 12.95],
            ['id' => 3, 'CustomerID' => 101, 'status' => 1, 'Plan' => 3, 'dateActivated' => '2025-01-05 10:00:00', 'use_custom_price' => 1, 'custom_price' => 2.0],
            ['id' => 4, 'CustomerID' => 101, 'status' => 4, 'Plan' => 1, 'dateActivated' => '2025-01-05 10:00:00', 'use_custom_price' => 1, 'custom_price' => 50.0],
            ['id' => 5, 'CustomerID' => 101, 'status' => 1, 'Plan' => 2, 'dateActivated' => '2025-01-05 10:00:00', 'use_custom_price' => 1, 'custom_price' => 49.0],
            // A five-year domain; a custom package status that stands for Active.
            ['id' => 7, 'CustomerID' => 101, 'status' => 6, 'Plan' => 9, 'dateActivated' => '2025-01-05 10:00:00', 'use_custom_price' => 1, 'custom_price' => 50.0],
            // Staff's own package: not a client's.
            ['id' => 6, 'CustomerID' => 100, 'status' => 1, 'Plan' => 1, 'dateActivated' => '2025-01-05 10:00:00', 'use_custom_price' => 0, 'custom_price' => 0.0],
        ],
        'object_customField' => [
            ['objectid' => 1, 'customFieldId' => 12, 'value' => 'site.example'], ['objectid' => 1, 'customFieldId' => 10, 'value' => 'annlee'],
            ['objectid' => 2, 'customFieldId' => 2, 'value' => 'Ann.com'], ['objectid' => 2, 'customFieldId' => 21, 'value' => '2027-01-05'],
            ['objectid' => 4, 'customFieldId' => 34, 'value' => 'Too slow'], ['objectid' => 7, 'customFieldId' => 2, 'value' => 'lee.org'],
        ],
        'recurringfee' => [
            $fee(1, 101, 1, 5.0, '2026-12-05', 1), $fee(2, 101, 2, 12.95, '2027-01-05', 12), $fee(3, 101, 3, 2.0, '2026-11-12', 121),
            $fee(4, 101, 4, 50.0, '2027-01-05', 12), $fee(5, 101, 5, 49.0, '0000-00-00', 0, 0), $fee(7, 101, 7, 50.0, '2030-01-05', 60),
            // An add-on's fee renews too, but it is not the package's.
            $fee(6, 101, 1, 3.0, '2026-12-05', 1, 1, -4),
        ],
        'cancellations' => [],
        'invoice' => [
            ['id' => 1, 'customerid' => 101, 'billdate' => '2026-11-08', 'datecreated' => '2026-11-01', 'datepaid' => '2026-11-02', 'currency' => 'USD', 'amount' => 8.4, 'subtotal' => 7.0, 'balance_due' => 0.0, 'status' => 1, 'tax' => 20.0, 'tax2' => 0.0, 'pluginused' => 'stripe', 'checknum' => '', 'note' => ''],
            ['id' => 2, 'customerid' => 101, 'billdate' => '2026-11-10', 'datecreated' => '2026-11-03', 'datepaid' => '0000-00-00', 'currency' => 'EUR', 'amount' => 12.0, 'subtotal' => 10.0, 'balance_due' => 7.0, 'status' => 5, 'tax' => 20.0, 'tax2' => 0.0, 'pluginused' => 'none', 'checknum' => 'BT-2', 'note' => 'Call first'],
            ['id' => 3, 'customerid' => 101, 'billdate' => '2026-11-10', 'datecreated' => '2026-11-03', 'datepaid' => null, 'currency' => 'USD', 'amount' => 1.0, 'subtotal' => 1.0, 'balance_due' => 0.0, 'status' => 2, 'tax' => 0.0, 'tax2' => 0.0, 'pluginused' => 'none', 'checknum' => '', 'note' => ''],
            ['id' => 4, 'customerid' => 101, 'billdate' => '2026-11-10', 'datecreated' => '2026-11-03', 'datepaid' => null, 'currency' => 'USD', 'amount' => 1.0, 'subtotal' => 1.0, 'balance_due' => 0.0, 'status' => 3, 'tax' => 0.0, 'tax2' => 0.0, 'pluginused' => 'none', 'checknum' => '', 'note' => ''],
            ['id' => 5, 'customerid' => 100, 'billdate' => '2026-11-10', 'datecreated' => '2026-11-03', 'datepaid' => null, 'currency' => 'USD', 'amount' => 1.0, 'subtotal' => 1.0, 'balance_due' => 1.0, 'status' => 0, 'tax' => 0.0, 'tax2' => 0.0, 'pluginused' => 'none', 'checknum' => '', 'note' => ''],
        ],
        'invoiceentry' => [
            ['id' => 1, 'invoiceid' => 1, 'description' => 'Starter', 'detail' => '', 'billingtypeid' => -1, 'price' => 5.0, 'quantity' => 1.0, 'appliestoid' => 1, 'taxable' => 1],
            ['id' => 2, 'invoiceid' => 1, 'description' => 'Setup fee', 'detail' => '', 'billingtypeid' => -1, 'price' => 2.0, 'quantity' => 1.0, 'appliestoid' => 0, 'taxable' => 1],
            ['id' => 3, 'invoiceid' => 2, 'description' => 'com', 'detail' => 'ann.com', 'billingtypeid' => -1, 'price' => 5.0, 'quantity' => 2.0, 'appliestoid' => 2, 'taxable' => 1],
            ['id' => 4, 'invoiceid' => 2, 'description' => 'Discount', 'detail' => '', 'billingtypeid' => -3, 'price' => 0.0, 'quantity' => 1.0, 'appliestoid' => 2, 'taxable' => 0],
        ],
        'invoicetransaction' => [
            ['id' => 1, 'invoiceid' => 1, 'accepted' => 1, 'transactiondate' => '2026-11-02 09:00:00', 'transactionid' => 'ch_1', 'action' => 'charge', 'amount' => 8.4],
            ['id' => 2, 'invoiceid' => 2, 'accepted' => 1, 'transactiondate' => '2026-11-04 09:00:00', 'transactionid' => 'NA', 'action' => 'none', 'amount' => 5.0],
            ['id' => 3, 'invoiceid' => 1, 'accepted' => 1, 'transactiondate' => '2026-11-05 09:00:00', 'transactionid' => 're_1', 'action' => 'refund', 'amount' => 2.0],
            ['id' => 4, 'invoiceid' => 2, 'accepted' => 0, 'transactiondate' => '2026-11-04 09:00:00', 'transactionid' => 'ch_x', 'action' => 'charge', 'amount' => 9.0],
        ],
        'troubleticket' => [['id' => 7, 'userid' => 101, 'domainid' => 2, 'subject' => 'Help', 'priority' => 1, 'status' => 3, 'assignedtodeptid' => 0, 'datesubmitted' => '2026-11-05 09:00:00', 'lastlog_datetime' => '2026-11-05 10:00:00']],
        'troubleticket_log' => [
            ['id' => 1, 'troubleticketid' => 7, 'logtype' => 0, 'message' => 'My site is down.', 'userid' => 101, 'mydatetime' => '2026-11-05 09:00:00', 'private' => 0, 'email' => ''],
            ['id' => 2, 'troubleticketid' => 7, 'logtype' => 0, 'message' => 'We are on it.', 'userid' => 100, 'mydatetime' => '2026-11-05 09:30:00', 'private' => 0, 'email' => ''],
            ['id' => 3, 'troubleticketid' => 7, 'logtype' => 0, 'message' => 'Server 3.', 'userid' => 100, 'mydatetime' => '2026-11-05 09:40:00', 'private' => 1, 'email' => ''],
            ['id' => 4, 'troubleticketid' => 7, 'logtype' => 2, 'message' => 'Waiting on Customer', 'userid' => 100, 'mydatetime' => '2026-11-05 10:00:00', 'private' => 0, 'email' => ''],
        ],
        // Custom statuses: a package status for Active, a client status for Fraud.
        'statusalias' => [['statusid' => 3, 'aliasto' => null, 'type' => 2], ['statusid' => 6, 'aliasto' => 1, 'type' => 1], ['statusid' => 9, 'aliasto' => -3, 'type' => 3]],
    ];
}

/** The fake ClientExec as an SQLite file, and the run state on an in-memory database. */
function clientexecDi(): array
{
    $dir = sys_get_temp_dir() . '/cf-clientexec-test-' . getmypid();
    putenv('CLIENTFENCE_BACKUP_DIR=' . $dir . '/backups');
    @mkdir($dir, 0700, true);
    @unlink($dir . '/clientexec.sqlite');
    $cexec = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dir . '/clientexec.sqlite']);
    foreach (clientexecFake() as $table => $rows) {
        // Typed as the first row's values, so numbers compare as numbers (as in MySQL).
        $first = $rows[0] ?? ['id' => 0, 'userpackage_id' => 0, 'reason' => '', 'requested_at' => ''];
        $cexec->executeStatement(sprintf('CREATE TABLE "%s" (%s)', $table, implode(', ', array_map(fn (string $c): string => sprintf('"%s" %s', $c, is_int($first[$c]) ? 'INTEGER' : (is_float($first[$c]) ? 'REAL' : '')), array_keys($first)))));
        foreach ($rows as $row) {
            $cexec->insert('"' . $table . '"', array_combine(array_map(fn (string $c): string => '"' . $c . '"', array_keys($row)), $row));
        }
    }
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE mod_clientfence_state (name TEXT PRIMARY KEY, value TEXT, updated_at TEXT)');
    $db->executeStatement('CREATE TABLE ' . Run::WARNINGS . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT, step TEXT, message TEXT, created_at TEXT)');
    $di = new Pimple\Container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;

    return [$di, new Source($cexec)];
}

afterEach(function (): void {
    $dir = sys_get_temp_dir() . '/cf-clientexec-test-' . getmypid();
    @unlink(Snapshot::path());
    @rmdir(dirname(Snapshot::path()));
    @unlink($dir . '/clientexec.sqlite');
    @rmdir($dir . '/backups');
    @rmdir($dir);
    putenv('CLIENTFENCE_BACKUP_DIR');
});

test('ClientExec is read a page at a time into WHMCS\'s tables, and what cannot come over is left out with a warning', function (): void {
    [$di, $source] = clientexecDi();
    $calls = 0;
    do {
        $state = Snapshot::fetch($di, [], 0.000001, false, $source);
        ++$calls;
    } while ($state['finished_at'] === null && $calls < 50);
    $db = Snapshot::open();
    $warnings = implode("\n", Run::warnings($di['em']->getConnection()));

    // Clients: the users of a client group (not staff), their details from the custom user fields.
    expect($state['finished_at'])->not->toBeNull()
        ->and($calls)->toBeGreaterThan(5)
        ->and(substr(sprintf('%o', fileperms(Snapshot::path())), -4))->toBe('0600')
        ->and($db->fetchFirstColumn('SELECT id FROM tblclients ORDER BY id'))->toBe([101, 102])
        ->and($db->fetchAssociative('SELECT firstname, companyname, address1, city, country, phonenumber, tax_id, credit, status, groupid, taxexempt, datecreated FROM tblclients WHERE id = 101'))
        ->toBe(['firstname' => 'Ann', 'companyname' => 'Lee Ltd', 'address1' => '1 Road', 'city' => 'London', 'country' => 'GB', 'phonenumber' => '+44 20 7123 4567', 'tax_id' => 'GB123', 'credit' => 25.0, 'status' => 'Active', 'groupid' => 6, 'taxexempt' => 0, 'datecreated' => '2025-01-05'])
        ->and($db->fetchAllKeyValue('SELECT c.id, cur.code FROM tblclients c JOIN tblcurrencies cur ON cur.id = c.currency ORDER BY c.id'))->toBe([101 => 'USD', 102 => 'EUR'])
        ->and($db->fetchAssociative('SELECT status, taxexempt, datecreated FROM tblclients WHERE id = 102'))->toBe(['status' => 'Closed', 'taxexempt' => 1, 'datecreated' => '2025-02-01'])
        ->and($warnings)->toContain('Client #102 is marked as fraud in ClientExec')
        ->and($warnings)->toContain('Client eve@example.test used two-factor sign-in in ClientExec')
        // The hash as ClientExec keeps it; the WHMCS step keeps one PHP can check.
        ->and(password_verify('secret', (string) $db->fetchOne('SELECT password FROM tblusers WHERE id = 101')))->toBeTrue()
        ->and($db->fetchFirstColumn('SELECT note FROM tblnotes ORDER BY id'))->toBe(['Pays early.', "Call\n\nCalled about renewal."])
        ->and($db->fetchAllKeyValue('SELECT code, prefix || \'|\' || suffix FROM tblcurrencies ORDER BY code'))->toBe(['EUR' => '|€', 'USD' => '$|'])
        ->and($db->fetchFirstColumn('SELECT groupname FROM tblclientgroups'))->toBe(['Priority Clients'])
        ->and($db->fetchAllAssociative('SELECT name, country, taxrate FROM tbltax'))->toBe([['name' => 'VAT', 'country' => '', 'taxrate' => 20.0]])
        ->and($db->fetchAllKeyValue("SELECT setting, value FROM tblconfiguration WHERE setting LIKE 'Tax%' ORDER BY setting"))->toBe(['TaxDomains' => '', 'TaxEnabled' => 'on', 'TaxType' => 'Exclusive'])
        ->and($warnings)->toContain('ClientExec has 1 coupons: they were not imported.')
        ->and($warnings)->toContain('ClientExec has 1 package add-ons')
        ->and($warnings)->toContain('ClientExec has 1 recurring charges that are not a package\'s');

    // The catalogue: packages of the domain groups become domain prices; only offered cycles keep a price.
    expect($db->fetchAllAssociative('SELECT id, name, gid, hidden, tax, paytype FROM tblproducts ORDER BY id'))->toBe([
        ['id' => 1, 'name' => 'Starter', 'gid' => 5, 'hidden' => 0, 'tax' => 1, 'paytype' => 'recurring'],
        ['id' => 2, 'name' => 'Migration', 'gid' => 5, 'hidden' => 1, 'tax' => 0, 'paytype' => 'onetime'],
        ['id' => 3, 'name' => 'Backup Check', 'gid' => 5, 'hidden' => 0, 'tax' => 0, 'paytype' => 'recurring'],
    ])
        ->and($db->fetchFirstColumn('SELECT name FROM tblproductgroups'))->toBe(['Web Hosting'])
        ->and($db->fetchAssociative("SELECT p.monthly, p.annually, p.biennially, p.msetupfee FROM tblpricing p JOIN tblcurrencies c ON c.id = p.currency WHERE p.type = 'product' AND p.relid = 1 AND c.code = 'USD'"))->toBe(['monthly' => 5.0, 'annually' => 50.0, 'biennially' => -1.0, 'msetupfee' => 2.0])
        ->and($db->fetchOne("SELECT monthly FROM tblpricing WHERE type = 'product' AND relid = 2"))->toBe(49.0)
        // Named once, though priced in two currencies.
        ->and(substr_count($warnings, 'Package "Backup Check": its 1 Week price was not imported'))->toBe(1)
        ->and($db->fetchFirstColumn('SELECT extension FROM tbldomainpricing'))->toBe(['.com'])
        ->and($db->fetchAllKeyValue("SELECT type, msetupfee || '/' || qsetupfee || '/' || bsetupfee FROM tblpricing WHERE type LIKE 'domain%' ORDER BY type"))->toBe(['domainregister' => '11.95/22.0/50.0', 'domainrenew' => '12.95/22.0/50.0', 'domaintransfer' => '9.0/-1.0/-1.0'])
        ->and($warnings)->toContain('Domain extension .com: its 11 Years price was not imported');

    // Services renew at their package fee on its cycle; a domain group's package is a domain.
    expect($db->fetchAllAssociative('SELECT id, packageid, amount, billingcycle, domainstatus, nextduedate, domain, username FROM tblhosting ORDER BY id'))->toBe([
        ['id' => 1, 'packageid' => 1, 'amount' => 5.0, 'billingcycle' => 'Monthly', 'domainstatus' => 'Active', 'nextduedate' => '2026-12-05', 'domain' => 'site.example', 'username' => 'annlee'],
        ['id' => 4, 'packageid' => 1, 'amount' => 50.0, 'billingcycle' => 'Annually', 'domainstatus' => 'Active', 'nextduedate' => '2027-01-05', 'domain' => '', 'username' => ''],
        ['id' => 5, 'packageid' => 2, 'amount' => 49.0, 'billingcycle' => 'One Time', 'domainstatus' => 'Active', 'nextduedate' => '2025-01-05', 'domain' => '', 'username' => ''],
    ])
        ->and($warnings)->toContain('Package #3 of client #101 was not imported: it renews every 1 Week')
        // A pending cancellation waits in Cancellation Requests.
        ->and($db->fetchAllAssociative('SELECT relid, reason, type FROM tblcancelrequests'))->toBe([['relid' => 4, 'reason' => 'Too slow', 'type' => 'End of Billing Period']])
        ->and($db->fetchAllAssociative('SELECT domain, recurringamount, registrationperiod, expirydate, nextduedate, status FROM tbldomains ORDER BY id'))->toBe([
            ['domain' => 'ann.com', 'recurringamount' => 12.95, 'registrationperiod' => 1, 'expirydate' => '2027-01-05', 'nextduedate' => '2027-01-05', 'status' => 'Active'],
            ['domain' => 'lee.org', 'recurringamount' => 50.0, 'registrationperiod' => 5, 'expirydate' => '2030-01-05', 'nextduedate' => '2030-01-05', 'status' => 'Active'],
        ]);

    // Billing: ClientExec's ids as numbers, its statuses and rate; payments on invoices, refunds left out.
    expect($db->fetchAllAssociative('SELECT id, invoicenum, date, duedate, status, subtotal, tax, total, taxrate, notes FROM tblinvoices ORDER BY id'))->toBe([
        ['id' => 1, 'invoicenum' => '1', 'date' => '2026-11-01', 'duedate' => '2026-11-08', 'status' => 'Paid', 'subtotal' => 7.0, 'tax' => 1.4, 'total' => 8.4, 'taxrate' => 20.0, 'notes' => ''],
        // Partly paid: unpaid, with its payment.
        ['id' => 2, 'invoicenum' => '2', 'date' => '2026-11-03', 'duedate' => '2026-11-10', 'status' => 'Unpaid', 'subtotal' => 10.0, 'tax' => 2.0, 'total' => 12.0, 'taxrate' => 20.0, 'notes' => 'Call first'],
        ['id' => 3, 'invoicenum' => '3', 'date' => '2026-11-03', 'duedate' => '2026-11-10', 'status' => 'Cancelled', 'subtotal' => 1.0, 'tax' => 0.0, 'total' => 1.0, 'taxrate' => 0.0, 'notes' => ''],
        ['id' => 4, 'invoicenum' => '4', 'date' => '2026-11-03', 'duedate' => '2026-11-10', 'status' => 'Cancelled', 'subtotal' => 1.0, 'tax' => 0.0, 'total' => 1.0, 'taxrate' => 0.0, 'notes' => ''],
    ])
        ->and($warnings)->toContain('Invoice #4 has a status ClientExec numbers 3, which the import does not know')
        ->and($warnings)->toContain('Client #101 is billed in USD, but its invoice #2 is in EUR in ClientExec')
        ->and($db->fetchAllAssociative('SELECT type, relid, description, amount, taxed FROM tblinvoiceitems ORDER BY id'))->toBe([
            ['type' => 'Hosting', 'relid' => 1, 'description' => 'Starter', 'amount' => 5.0, 'taxed' => 1],
            ['type' => '', 'relid' => 0, 'description' => 'Setup fee', 'amount' => 2.0, 'taxed' => 1],
            ['type' => 'Domain', 'relid' => 2, 'description' => "2 x com\nann.com", 'amount' => 10.0, 'taxed' => 1],
            // A discount line of the same package is a plain item: paying renews the domain once.
            ['type' => '', 'relid' => 0, 'description' => 'Discount', 'amount' => 0.0, 'taxed' => 0],
        ])
        ->and($db->fetchOne("SELECT value FROM tblconfiguration WHERE setting = 'SequentialInvoiceNumberFormat'"))->toBe('{NUMBER}')
        ->and($db->fetchAllAssociative('SELECT invoiceid, gateway, amountin, transid FROM tblaccounts ORDER BY id'))->toBe([
            ['invoiceid' => 1, 'gateway' => 'stripe', 'amountin' => 8.4, 'transid' => 'ch_1'],
            ['invoiceid' => 2, 'gateway' => '', 'amountin' => 5.0, 'transid' => 'BT-2'],
        ])
        ->and($warnings)->toContain('Payment #3 of invoice #1 is a refund in ClientExec');

    // Support: the first message opens the ticket; staff replies and private notes keep their kind; events are left out.
    expect($db->fetchAssociative('SELECT tid, did, title, message, status, urgency, email, service, lastreply FROM tbltickets'))->toBe(['tid' => '7', 'did' => 1, 'title' => 'Help', 'message' => 'My site is down.', 'status' => 'Answered', 'urgency' => 'High', 'email' => 'ann@example.test', 'service' => 'D2', 'lastreply' => '2026-11-05 10:00:00'])
        ->and($db->fetchAllAssociative('SELECT admin, message FROM tblticketreplies'))->toBe([['admin' => 'Sam Ops', 'message' => 'We are on it.']])
        ->and($db->fetchFirstColumn('SELECT message FROM tblticketnotes'))->toBe(['Server 3.']);

    $plan = Inventory::plan(new Source($db));
    expect($plan['version'])->toBe('8.0.0 (ClientExec 7.3.0)')
        ->and($plan['modules'])->toBe([]);
});

test('the import reads ClientExec only once a read has finished, and reading needs the saved details', function (): void {
    [$di, $source] = clientexecDi();
    expect(fn () => Import::fetchClientexec($di, 1.0))->toThrow(ClientFence\InformationException::class, 'Save the ClientExec database details first.')
        ->and(fn () => Source::open($di, ['clientexec' => 1]))->toThrow(ClientFence\InformationException::class, 'Read ClientExec first, until it finishes.')
        ->and(Import::system(['clientexec' => 1] + Import::DEFAULTS))->toBe('ClientExec');

    Snapshot::fetch($di, [], 0.000001, false, $source);
    expect(fn () => Source::open($di, ['clientexec' => 1]))->toThrow(ClientFence\InformationException::class, 'Read ClientExec first, until it finishes.');

    Snapshot::fetch($di, [], 0.0, false, $source);
    $snapshot = Source::open($di, ['clientexec' => 1]);
    expect($snapshot->isWhmcs())->toBeTrue();
    $snapshot->close();
});
