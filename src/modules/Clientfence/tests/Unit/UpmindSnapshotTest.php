<?php

declare(strict_types=1);

/**
 * ClientFence: the Upmind import's read (Upmind\Snapshot) from an invented Upmind API (Symfony's
 * MockHttpClient) into the WHMCS-shaped file the WHMCS import reads.
 */

use ClientFence\Mod\Clientfence\Upmind\Snapshot;
use ClientFence\Mod\Clientfence\Whmcs\Inventory;
use ClientFence\Mod\Clientfence\Whmcs\Source;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Upmind's answers by path (lists are sliced by limit and offset, as Upmind pages them). */
function upmindFake(): array
{
    $clients = [];
    for ($n = 1; $n <= 11; ++$n) {
        $clients[] = ['id' => "cl-$n", 'firstname' => "First$n", 'lastname' => 'Last', 'email' => "c$n@example.test", 'has_login' => $n === 1, 'enabled_2fa' => $n === 1, 'status_id' => 's-client', 'created_at' => '2024-01-02 03:04:05'] + ($n === 1 ? ['interface_language_code' => 'sr'] : []);
    }
    $line = ['from_date' => '2026-11-01', 'to_date' => '2026-12-01'];

    return [
        'currencies' => [['id' => 'cur-gbp', 'code' => 'GBP', 'prefix' => '£', 'suffix' => '', 'decimals' => 2], ['id' => 'cur-usd', 'code' => 'USD', 'prefix' => '$', 'suffix' => '', 'decimals' => 2]],
        'countries' => [['id' => 'c-gb', 'code' => 'gb'], ['id' => 'c-de', 'code' => 'de']],
        // A tag in two templates comes over once.
        'tax_templates' => [
            ['id' => 't-1', 'tax_tags' => [
                ['id' => 'tag-uk', 'name' => 'UK VAT', 'type' => 1, 'amount' => 20, 'company_type' => 1, 'company_amount' => 20, 'locations' => [['country_id' => 'c-gb']]],
                ['id' => 'tag-de', 'name' => 'DE VAT', 'type' => 1, 'amount' => 19, 'company_type' => 1, 'company_amount' => 0, 'locations' => [['country_id' => 'c-de'], ['country_id' => 'c-de', 'region_id' => 'r-by']]],
                ['id' => 'tag-fee', 'name' => 'Eco fee', 'type' => 2, 'amount' => 1, 'locations' => [['country_id' => 'c-gb']]],
            ]],
            ['id' => 't-2', 'tax_tags' => [['id' => 'tag-uk', 'name' => 'UK VAT', 'type' => 1, 'amount' => 20, 'company_type' => 1, 'company_amount' => 20, 'locations' => [['country_id' => 'c-gb']]]]],
        ],
        'statuses' => [
            ['id' => 's-client', 'code' => 'client_active'], ['id' => 's-active', 'code' => 'contract_active'], ['id' => 's-paid', 'code' => 'invoice_paid'],
            ['id' => 's-unpaid', 'code' => 'invoice_unpaid'], ['id' => 's-open', 'code' => 'ticket_open'],
        ],
        'gateways' => [['id' => 'g-off', 'name' => 'Bank - Offline', 'provider' => 'Offline'], ['id' => 'g-card', 'name' => 'Cards', 'provider' => 'Stripe']],
        'brands' => [['id' => 'b', 'currency_id' => 'cur-gbp']],
        'products_categories' => [['id' => 'cat-h', 'name' => 'Hosting'], ['id' => 'cat-d', 'name' => 'Domains', 'module_sub_id' => 'domains']],
        'products' => [
            ['id' => 'p-start', 'name' => 'Starter', 'category_id' => 'cat-h', 'module_sub_id' => null, 'prices' => [
                ['currency_code' => 'GBP', 'billing_cycle_months' => 1, 'price' => 5], ['currency_code' => 'GBP', 'billing_cycle_months' => 12, 'price' => 50],
                ['currency_code' => 'GBP', 'billing_cycle_months' => 13, 'price' => 60], ['currency_code' => 'USD', 'billing_cycle_months' => 1, 'price' => 6],
            ]],
            ['id' => 'p-logo', 'name' => 'Logo', 'category_id' => 'cat-h', 'prices' => [['currency_code' => 'GBP', 'billing_cycle_months' => 0, 'price' => 99]]],
            ['id' => 'p-com', 'name' => '.com', 'category_id' => 'cat-d', 'module_sub_id' => 'tld-com', 'prices' => [
                ['currency_code' => 'GBP', 'billing_cycle_months' => 12, 'price' => 10], ['currency_code' => 'GBP', 'billing_cycle_months' => 24, 'price' => 15],
            ]],
            ['id' => 'p-move', 'name' => 'Transfer', 'module_sub_id' => 'domain_transfer', 'prices' => []],
        ],
        'clients' => $clients,
        'clients/cl-1/addresses' => [['default' => true, 'address_1' => '1 Main St', 'city' => 'London', 'postcode' => 'SW1A 2AA', 'country_id' => 'c-gb']],
        'clients/cl-1/phones' => [['default' => true, 'phone_code' => '+44', 'international_phone' => '+442071234567', 'phone' => '020 7123 4567']],
        'clients/cl-1/companies' => [['name' => 'First Co', 'vat_number' => 'GB123']],
        'clients/cl-1/credit' => ['credit' => 25, 'currency_code' => 'GBP'],
        // Asked again in the client's own currency.
        'clients/cl-2/credit' => ['credit' => 7, 'currency_code' => 'USD'],
        'contracts_products' => [
            // Imported into Upmind: its first invoice charged nothing (net_amount 0).
            ['id' => 'cp-1', 'client_id' => 'cl-1', 'product_id' => 'p-start', 'name' => 'Starter', 'service_identifier' => 'first.example', 'billing_cycle_months' => 1,
                'selling_price' => 5, 'net_amount' => 0, 'quantity' => 1, 'currency_code' => 'GBP', 'status' => ['code' => 'contract_active'], 'activation_date' => '2025-01-01', 'next_due_date' => '2026-12-01', 'renew' => true],
            ['id' => 'cp-2', 'client_id' => 'cl-1', 'product_id' => 'p-com', 'name' => '.com', 'service_identifier' => 'First.com', 'billing_cycle_months' => 12,
                'selling_price' => 10, 'quantity' => 1, 'currency_code' => 'EUR', 'status' => ['code' => 'contract_active'], 'start_date' => '2025-02-01', 'next_due_date' => '2027-02-01', 'renew' => false],
            ['id' => 'cp-4', 'client_id' => 'cl-1', 'product_id' => 'p-start', 'name' => 'Starter', 'service_identifier' => 'leaving.example', 'billing_cycle_months' => 1,
                'selling_price' => 5, 'quantity' => 1, 'currency_code' => 'GBP', 'status' => ['code' => 'contract_cancellation_request'], 'start_date' => '2025-03-01', 'next_due_date' => '2026-12-01', 'updated_at' => '2026-11-05 10:00:00'],
            ['id' => 'cp-5', 'client_id' => 'cl-1', 'product_id' => 'p-com', 'name' => '.com', 'service_identifier' => 'leaving.com', 'billing_cycle_months' => 12,
                'selling_price' => 10, 'quantity' => 1, 'currency_code' => 'GBP', 'status' => ['code' => 'contract_cancellation_request'], 'start_date' => '2025-03-01', 'next_due_date' => '2027-03-01', 'renew' => true],
            ['id' => 'cp-3', 'client_id' => 'cl-2', 'product_id' => 'p-start', 'name' => 'Starter', 'billing_cycle_months' => 2, 'selling_price' => 9, 'currency_code' => 'USD', 'status' => ['code' => 'contract_active'], 'start_date' => '2025-02-01'],
        ],
        'invoices' => [
            ['id' => 'i-0', 'number' => 'Z-INV-00001', 'client_id' => 'cl-1', 'total_amount' => 0, 'status_id' => 's-paid', 'currency_id' => 'cur-gbp'],
            ['id' => 'i-1', 'number' => 'Z-INV-00002', 'client_id' => 'cl-1', 'total_amount' => 58, 'net_amount' => 50, 'tax_amount' => 8, 'status_id' => 's-paid', 'currency_id' => 'cur-gbp',
                'gateway_id' => 'g-card', 'create_datetime' => '2026-11-01 10:00:00', 'due_date' => '2026-11-08', 'paid_datetime' => '2026-11-02 11:00:00'],
            ['id' => 'i-2', 'number' => 'Z-INV-00003', 'client_id' => 'cl-2', 'total_amount' => 5, 'net_amount' => 5, 'status_id' => 's-unpaid', 'currency_id' => 'cur-usd',
                'create_datetime' => '2026-11-03 10:00:00', 'due_date' => '2026-11-10'],
        ],
        'invoices/Z-INV-00002' => ['current_data' => ['content' => ['products' => [['name' => 'Starter', 'service_identifier' => 'first.example', 'net_amount' => 40, 'tax_amount' => 8, 'contracts_product_id' => 'cp-1'] + $line, ['name' => 'Setup', 'net_amount' => 10, 'tax_amount' => 0]]]]],
        'invoices/Z-INV-00003' => ['current_data' => ['content' => json_encode(['products' => [['name' => 'Setup', 'net_amount' => 5, 'tax_amount' => 0]]])]],
        'payments' => [
            ['id' => 'pay-1', 'client_id' => 'cl-1', 'invoice_id' => 'i-1', 'amount' => 58, 'status' => 'OK', 'gateway_id' => 'g-card', 'transaction_id' => 'ch_1', 'created_at' => '2026-11-02 11:00:00'],
            ['id' => 'pay-2', 'client_id' => 'cl-1', 'invoice_id' => null, 'amount' => 25, 'status' => 'OK', 'gateway_id' => 'g-off', 'created_at' => '2026-11-02 12:00:00'],
            ['id' => 'pay-3', 'client_id' => 'cl-2', 'invoice_id' => 'i-2', 'amount' => 5, 'status' => 'FAILED', 'gateway_id' => 'g-card', 'created_at' => '2026-11-04 12:00:00'],
        ],
        'tickets' => [['id' => 't-1', 'client_id' => 'cl-1', 'subject' => 'Help', 'status_id' => 's-open', 'ticket_department_id' => 'd-1', 'department' => ['name' => 'Support'], 'reference' => 'TKT-1', 'created_at' => '2026-11-05 09:00:00']],
        'tickets/t-1/messages' => [
            ['client_id' => null, 'is_private' => false, 'actor_name' => 'Sam', 'body' => 'We are on it.', 'created_at' => '2026-11-05 09:10:00'],
            ['client_id' => 'cl-1', 'is_private' => false, 'body' => 'My site is down.', 'created_at' => '2026-11-05 09:00:00'],
            ['client_id' => null, 'is_private' => true, 'actor_name' => 'Sam', 'body' => 'Server 3.', 'created_at' => '2026-11-05 09:05:00'],
            ['client_id' => null, 'is_log' => true, 'body' => 'Status changed', 'created_at' => '2026-11-05 09:06:00'],
        ],
    ];
}

/** A container with the fake Upmind, and the run state on an in-memory database. */
function upmindDi(): Pimple\Container
{
    $fake = upmindFake();
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE mod_clientfence_state (name TEXT PRIMARY KEY, value TEXT, updated_at TEXT)');
    $db->executeStatement('CREATE TABLE ' . ClientFence\Mod\Clientfence\Whmcs\Run::WARNINGS . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT, step TEXT, message TEXT, created_at TEXT)');
    $di = new Pimple\Container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;
    $di['http_client'] = new MockHttpClient(function (string $method, string $url) use ($fake): MockResponse {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        $path = urldecode(substr($parts['path'], strlen('/api/admin/')));
        if (!array_key_exists($path, $fake)) {
            // Lists Upmind has for every client, empty here.
            return new MockResponse((string) json_encode(['status' => 'ok', 'data' => str_ends_with($path, '/credit') ? ['credit' => 0, 'currency_code' => 'GBP'] : [], 'total' => 0]));
        }
        $data = $fake[$path];
        if (array_is_list($data) && isset($query['limit'])) {
            return new MockResponse((string) json_encode(['status' => 'ok', 'data' => array_slice($data, (int) $query['offset'], (int) $query['limit']), 'total' => count($data)]));
        }

        return new MockResponse((string) json_encode(['status' => 'ok', 'data' => $data]));
    });
    putenv('CLIENTFENCE_BACKUP_DIR=' . sys_get_temp_dir() . '/cf-upmind-test-' . getmypid());

    return $di;
}

afterEach(function (): void {
    @unlink(Snapshot::path());
    @rmdir(dirname(Snapshot::path()));
    putenv('CLIENTFENCE_BACKUP_DIR');
});

test('Upmind is read a page at a time into WHMCS\'s tables, and what cannot come over is left out with a warning', function (): void {
    $di = upmindDi();

    // A stop after every page loses nothing: each call goes on from where the last stopped.
    $calls = 0;
    do {
        $state = Snapshot::fetch($di, 'token', 0.000001);
        ++$calls;
    } while ($state['finished_at'] === null && $calls < 50);
    $db = Snapshot::open();
    $client = $db->fetchAssociative('SELECT * FROM tblclients WHERE id = 1');
    $warnings = implode("\n", ClientFence\Mod\Clientfence\Whmcs\Run::warnings($di['em']->getConnection()));

    expect($state['finished_at'])->not->toBeNull()
        ->and($calls)->toBeGreaterThan(5)
        ->and(substr(sprintf('%o', fileperms(Snapshot::path())), -4))->toBe('0600')
        ->and($state['steps']['clients']['done'])->toBe(11)
        // Eleven clients: numbered 1 to 11 (sorted as numbers, not as text).
        ->and($db->fetchFirstColumn('SELECT id FROM tblclients ORDER BY id'))->toBe(range(1, 11))
        ->and($client)->toMatchArray(['firstname' => 'First1', 'companyname' => 'First Co', 'tax_id' => 'GB123', 'country' => 'GB', 'phonenumber' => '+44.2071234567', 'credit' => 25.0, 'status' => 'Active', 'language' => 'sr'])
        ->and($db->fetchOne('SELECT language FROM tblclients WHERE id = 2'))->toBe('')
        ->and(password_get_info($client['password'])['algo'])->toBe(PASSWORD_BCRYPT)
        ->and($db->fetchFirstColumn('SELECT client_id FROM tblusers_clients WHERE owner = 1'))->toBe([1])
        ->and($warnings)->toContain('Client c1@example.test used two-factor sign-in in Upmind')
        ->and($warnings)->toContain('Product "Starter": its 13-month price was not imported')
        ->and($warnings)->toContain('Service "Starter" of client #2 was not imported: it renews every 2 months')
        ->and($warnings)->toContain('Currency USD: Upmind gives no exchange rate')
        // Upmind keeps no currency on a client: it is the one its services and invoices are billed in.
        ->and($db->fetchAllAssociative('SELECT c.id, cur.code, c.credit FROM tblclients c JOIN tblcurrencies cur ON cur.id = c.currency WHERE c.id <= 2 ORDER BY c.id'))->toBe([['id' => 1, 'code' => 'GBP', 'credit' => 25.0], ['id' => 2, 'code' => 'USD', 'credit' => 7.0]])
        ->and($warnings)->toContain('Client #1 is billed in GBP and EUR in Upmind')
        ->and($warnings)->toContain('which products it taxes is not imported, so tax stays switched off')
        // Country-wide percentages become tax rules; the rest is named.
        ->and($db->fetchAllAssociative('SELECT level, name, state, country, taxrate FROM tbltax ORDER BY id'))->toBe([
            ['level' => '1', 'name' => 'UK VAT', 'state' => '', 'country' => 'GB', 'taxrate' => 20.0],
            ['level' => '1', 'name' => 'DE VAT', 'state' => '', 'country' => 'DE', 'taxrate' => 19.0],
        ])
        ->and($warnings)->toContain('Tax "DE VAT" charges companies another rate')
        ->and($warnings)->toContain('Tax "DE VAT" applies to a region')
        ->and($warnings)->toContain('Tax "Eco fee" is a fixed amount')
        ->and($db->fetchOne("SELECT value FROM tblconfiguration WHERE setting = 'TaxEnabled'"))->toBeFalse()
        // Warnings live with the import's, not in the read's state.
        ->and($state)->not->toHaveKey('warnings');

    // The catalogue: products with their prices per currency, a one-time product, a domain's prices by years.
    expect($db->fetchAssociative("SELECT monthly, annually, quarterly FROM tblpricing WHERE type = 'product' AND relid = 1 AND currency = 1"))->toBe(['monthly' => 5.0, 'annually' => 50.0, 'quarterly' => -1.0])
        ->and($db->fetchOne("SELECT paytype FROM tblproducts WHERE name = 'Logo'"))->toBe('onetime')
        ->and($db->fetchOne("SELECT monthly FROM tblpricing p JOIN tblproducts r ON r.id = p.relid AND p.type = 'product' WHERE r.name = 'Logo'"))->toBe(99.0)
        ->and($db->fetchFirstColumn('SELECT extension FROM tbldomainpricing'))->toBe(['.com'])
        ->and($db->fetchAssociative("SELECT msetupfee, qsetupfee FROM tblpricing WHERE type = 'domainregister'"))->toBe(['msetupfee' => 10.0, 'qsetupfee' => 15.0])
        ->and((int) $db->fetchOne('SELECT COUNT(*) FROM tblproducts'))->toBe(2);

    // Services renew at their price, not at what Upmind's import invoice charged.
    expect($db->fetchAssociative('SELECT packageid, amount, billingcycle, domainstatus, regdate, nextduedate, domain FROM tblhosting ORDER BY id LIMIT 1'))->toBe(['packageid' => 1, 'amount' => 5.0, 'billingcycle' => 'Monthly', 'domainstatus' => 'Active', 'regdate' => '2025-01-01', 'nextduedate' => '2026-12-01', 'domain' => 'first.example'])
        ->and($db->fetchAssociative('SELECT domain, recurringamount, registrationperiod, donotrenew, status FROM tbldomains ORDER BY id LIMIT 1'))->toBe(['domain' => 'first.com', 'recurringamount' => 10.0, 'registrationperiod' => 1, 'donotrenew' => 1, 'status' => 'Active'])
        // A cancellation request waits in Cancellation Requests; such a domain does not renew.
        ->and($db->fetchAllAssociative("SELECT r.date, r.reason, r.type FROM tblcancelrequests r JOIN tblhosting h ON h.id = r.relid WHERE h.domain = 'leaving.example'"))->toBe([['date' => '2026-11-05 10:00:00', 'reason' => 'Cancellation requested in Upmind', 'type' => 'End of Billing Period']])
        ->and((int) $db->fetchOne('SELECT COUNT(*) FROM tblcancelrequests'))->toBe(1)
        ->and($db->fetchOne("SELECT donotrenew FROM tbldomains WHERE domain = 'leaving.com'"))->toBe(1);

    // Billing: the zero invoice is left out; lines point at their service; only money received on an imported invoice.
    expect($db->fetchFirstColumn('SELECT invoicenum FROM tblinvoices ORDER BY id'))->toBe(['Z-INV-00002', 'Z-INV-00003'])
        // The rate is that of the taxed lines: 8 on 40, not 8 on the whole 50.
        ->and($db->fetchAssociative("SELECT status, subtotal, tax, total, taxrate, paymentmethod FROM tblinvoices WHERE invoicenum = 'Z-INV-00002'"))->toBe(['status' => 'Paid', 'subtotal' => 50.0, 'tax' => 8.0, 'total' => 58.0, 'taxrate' => 20.0, 'paymentmethod' => 'stripe'])
        ->and($db->fetchAllAssociative('SELECT type, relid, description, amount, taxed FROM tblinvoiceitems WHERE invoiceid = 1 ORDER BY id'))->toBe([
            ['type' => 'Hosting', 'relid' => 1, 'description' => 'Starter - first.example (2026-11-01 - 2026-12-01)', 'amount' => 40.0, 'taxed' => 1],
            ['type' => '', 'relid' => 0, 'description' => 'Setup', 'amount' => 10.0, 'taxed' => 0],
        ])
        ->and($db->fetchAllAssociative('SELECT gateway, amountin, transid FROM tblaccounts'))->toBe([['gateway' => 'stripe', 'amountin' => 58.0, 'transid' => 'ch_1']])
        ->and($db->fetchOne("SELECT value FROM tblconfiguration WHERE setting = 'SequentialInvoiceNumberFormat'"))->toBe('Z-INV-{NUMBER}')
        ->and($db->fetchOne("SELECT value FROM tblconfiguration WHERE setting = 'TaxType'"))->toBe('Exclusive');

    // Support: the client's first message opens the ticket; staff replies and private notes keep their kind.
    expect($db->fetchAssociative('SELECT tid, title, message, status, email FROM tbltickets'))->toBe(['tid' => 'TKT-1', 'title' => 'Help', 'message' => 'My site is down.', 'status' => 'Open', 'email' => 'c1@example.test'])
        ->and($db->fetchAllAssociative('SELECT admin, message FROM tblticketreplies'))->toBe([['admin' => 'Sam', 'message' => 'We are on it.']])
        ->and($db->fetchFirstColumn('SELECT message FROM tblticketnotes'))->toBe(['Server 3.'])
        ->and($db->fetchFirstColumn('SELECT name FROM tblticketdepartments'))->toBe(['Support']);

    // The import plan reads the file as a WHMCS database: Upmind's own lookups are gone, so no add-on module shows.
    $plan = Inventory::plan(new Source($db));
    expect($plan['modules'])->toBe([])
        ->and($plan['version'])->toBe('8.0.0 (Upmind)');
});

test('a read that has finished is not repeated unless asked', function (): void {
    $di = upmindDi();
    $first = Snapshot::fetch($di, 'token');
    $again = Snapshot::fetch($di, 'token');
    $restart = Snapshot::fetch($di, 'token', 0.000001, true);

    expect($again['finished_at'])->toBe($first['finished_at'])
        ->and($restart['finished_at'])->toBeNull()
        ->and($restart['steps']['setup']['finished'])->toBeTrue()
        ->and($restart['steps']['clients']['done'])->toBe(0);
});

test('the import reads Upmind only once a read has finished, and reading needs the saved token', function (): void {
    $di = upmindDi();
    expect(fn () => ClientFence\Mod\Clientfence\Whmcs\Import::fetchUpmind($di, 1.0))->toThrow(ClientFence\InformationException::class, 'Save the Upmind API token first.')
        ->and(fn () => Source::open($di, ['upmind' => 1]))->toThrow(ClientFence\InformationException::class, 'Read Upmind first, until it finishes.');

    Snapshot::fetch($di, 'token', 0.000001);
    expect(fn () => Source::open($di, ['upmind' => 1]))->toThrow(ClientFence\InformationException::class, 'Read Upmind first, until it finishes.');

    Snapshot::fetch($di, 'token');
    $source = Source::open($di, ['upmind' => 1]);
    expect($source->isWhmcs())->toBeTrue()
        ->and(fn () => $source->connection()->executeStatement('DELETE FROM tblclients'))->toThrow('readonly database');
    $source->close();
});
