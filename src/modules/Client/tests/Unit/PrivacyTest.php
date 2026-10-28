<?php

declare(strict_types=1);

use ClientFence\Mod\Client\Privacy;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

use function Tests\Helpers\container;

/**
 * A client with an order, an invoice and its payment, a quote, a domain, a ticket, sent email and
 * sign-ins, on SQLite.
 */
function privacyDb(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $custom = implode(', ', array_map(fn (int $i): string => "custom_$i TEXT", range(1, 20)));
    foreach ([
        "CREATE TABLE client (id INTEGER PRIMARY KEY, aid TEXT, email TEXT, billing_email TEXT, pass TEXT, salt TEXT, status TEXT, email_approved INTEGER, first_name TEXT, last_name TEXT, gender TEXT, birthday TEXT, phone_cc TEXT, phone TEXT, company TEXT, company_vat TEXT, company_number TEXT, address_1 TEXT, address_2 TEXT, city TEXT, state TEXT, postcode TEXT, country TEXT, notes TEXT, ip TEXT, api_token TEXT, referred_by TEXT, auth_type TEXT, $custom, updated_at TEXT)",
        'CREATE TABLE client_order (id INTEGER PRIMARY KEY, client_id INTEGER, title TEXT, status TEXT, period TEXT, quantity INTEGER, price REAL, discount REAL, currency TEXT, config TEXT, notes TEXT, created_at TEXT, activated_at TEXT, expires_at TEXT, suspended_at TEXT, canceled_at TEXT)',
        'CREATE TABLE invoice (id INTEGER PRIMARY KEY, client_id INTEGER, serie TEXT, nr TEXT, currency TEXT, status TEXT, notes TEXT, buyer_first_name TEXT, buyer_last_name TEXT, buyer_company TEXT, buyer_company_vat TEXT, buyer_company_number TEXT, buyer_address TEXT, buyer_city TEXT, buyer_state TEXT, buyer_country TEXT, buyer_zip TEXT, buyer_phone TEXT, buyer_phone_cc TEXT, buyer_email TEXT, taxname TEXT, taxrate REAL, due_at TEXT, paid_at TEXT, created_at TEXT)',
        'CREATE TABLE invoice_item (id INTEGER PRIMARY KEY, invoice_id INTEGER, title TEXT, period TEXT, quantity INTEGER, unit TEXT, price REAL, taxed INTEGER, created_at TEXT)',
        'CREATE TABLE "transaction" (id INTEGER PRIMARY KEY, invoice_id INTEGER, txn_id TEXT, amount REAL, currency TEXT, type TEXT, status TEXT, ip TEXT, ipn TEXT, output TEXT, created_at TEXT)',
        'CREATE TABLE client_balance (id INTEGER PRIMARY KEY, client_id INTEGER, type TEXT, amount REAL, description TEXT, created_at TEXT)',
        'CREATE TABLE client_note (id INTEGER PRIMARY KEY, client_id INTEGER, admin_id INTEGER, note TEXT, pinned INTEGER, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE client_file (id INTEGER PRIMARY KEY, client_id INTEGER, admin_id INTEGER, name TEXT, stored_name TEXT, size INTEGER, mime TEXT, created_at TEXT)',
        'CREATE TABLE admin (id INTEGER PRIMARY KEY, name TEXT)',
        'CREATE TABLE invoice_quote (id INTEGER PRIMARY KEY, client_id INTEGER, first_name TEXT, last_name TEXT, company TEXT, email TEXT, country TEXT, currency TEXT, subject TEXT, stage TEXT, valid_until TEXT, customer_notes TEXT, admin_notes TEXT, sent_at TEXT, accepted_at TEXT, declined_at TEXT, created_at TEXT)',
        'CREATE TABLE service_domain (id INTEGER PRIMARY KEY, client_id INTEGER, sld TEXT, tld TEXT, ns1 TEXT, ns2 TEXT, ns3 TEXT, ns4 TEXT, period INTEGER, privacy INTEGER, transfer_code TEXT, contact_email TEXT, contact_company TEXT, contact_first_name TEXT, contact_last_name TEXT, contact_address1 TEXT, contact_address2 TEXT, contact_city TEXT, contact_state TEXT, contact_postcode TEXT, contact_country TEXT, contact_phone_cc TEXT, contact_phone TEXT, registered_at TEXT, expires_at TEXT)',
        'CREATE TABLE support_ticket (id INTEGER PRIMARY KEY, client_id INTEGER, subject TEXT, status TEXT, priority INTEGER, author_name TEXT, author_email TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE support_ticket_message (id INTEGER PRIMARY KEY, support_ticket_id INTEGER, client_id INTEGER, admin_id INTEGER, content TEXT, ip TEXT, created_at TEXT)',
        'CREATE TABLE support_ticket_note (id INTEGER PRIMARY KEY, support_ticket_id INTEGER, note TEXT)',
        'CREATE TABLE activity_system (id INTEGER PRIMARY KEY, client_id INTEGER, message TEXT, ip TEXT)',
        'CREATE TABLE activity_client_history (id INTEGER PRIMARY KEY, client_id INTEGER, ip TEXT, created_at TEXT)',
        'CREATE TABLE activity_client_email (id INTEGER PRIMARY KEY, client_id INTEGER, sender TEXT, recipients TEXT, subject TEXT, content_text TEXT, created_at TEXT)',
        'CREATE TABLE client_password_reset (id INTEGER PRIMARY KEY, client_id INTEGER)',
        'CREATE TABLE email_queue (id INTEGER PRIMARY KEY, client_id INTEGER)',
        'CREATE TABLE extension_meta (id INTEGER PRIMARY KEY, client_id INTEGER, meta_key TEXT)',
        'CREATE TABLE client_user (id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT)',
        'CREATE TABLE client_user_account (id INTEGER PRIMARY KEY, user_id INTEGER, client_id INTEGER, owner INTEGER, permissions TEXT, created_at TEXT, last_login_at TEXT)',
        'CREATE TABLE ' . Privacy::OPTOUT_TABLE . ' (client_id INTEGER PRIMARY KEY, source TEXT, opted_out_at TEXT)',
        'CREATE TABLE ' . Privacy::UNSUBSCRIBE_TABLE . ' (queue_id INTEGER PRIMARY KEY, url TEXT, created_at TEXT)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    $db->insert('client', ['id' => 7, 'email' => 'alice@client.test', 'pass' => 'hash', 'salt' => 's', 'api_token' => 'secret-token', 'status' => 'active', 'first_name' => 'Alice', 'last_name' => 'Smith', 'phone' => '5550100', 'company' => 'Alice Co', 'address_1' => '1 Main St', 'city' => 'Town', 'country' => 'US', 'notes' => 'VIP', 'ip' => '203.0.113.7', 'custom_1' => 'passport 123']);
    $db->insert('client_order', ['id' => 1, 'client_id' => 7, 'title' => 'Hosting', 'status' => 'canceled', 'price' => 10, 'currency' => 'USD', 'notes' => 'call Alice']);
    $db->insert('invoice', ['id' => 3, 'client_id' => 7, 'status' => 'paid', 'currency' => 'USD', 'buyer_first_name' => 'Alice', 'buyer_last_name' => 'Smith', 'buyer_company' => 'Alice Co', 'buyer_company_vat' => 'US123', 'buyer_address' => '1 Main St', 'buyer_country' => 'US', 'buyer_email' => 'alice@client.test']);
    $db->insert('invoice_item', ['invoice_id' => 3, 'title' => 'Hosting', 'price' => 10, 'quantity' => 1]);
    $db->executeStatement('INSERT INTO "transaction" (invoice_id, txn_id, amount, ip, ipn) VALUES (3, ?, 10, ?, ?)', ['T1', '203.0.113.7', 'payer=alice@client.test']);
    $db->insert('invoice_quote', ['client_id' => 7, 'first_name' => 'Alice', 'last_name' => 'Smith', 'email' => 'alice@client.test', 'admin_notes' => 'staff only']);
    $db->insert('service_domain', ['client_id' => 7, 'sld' => 'alice', 'tld' => '.test', 'contact_first_name' => 'Alice', 'contact_email' => 'alice@client.test', 'transfer_code' => 'EPP-SECRET']);
    $db->insert('support_ticket', ['id' => 9, 'client_id' => 7, 'subject' => 'My password', 'status' => 'closed']);
    $db->insert('support_ticket_message', ['support_ticket_id' => 9, 'client_id' => 7, 'content' => 'Here is my address', 'ip' => '203.0.113.7']);
    $db->insert('support_ticket_message', ['support_ticket_id' => 9, 'admin_id' => 1, 'content' => 'Thanks Alice']);
    $db->insert('support_ticket_note', ['support_ticket_id' => 9, 'note' => 'Alice is nice']);
    $db->insert('activity_client_history', ['client_id' => 7, 'ip' => '203.0.113.7']);
    $db->insert('activity_client_email', ['client_id' => 7, 'subject' => 'Welcome Alice', 'content_text' => 'Hi Alice']);
    $db->insert('extension_meta', ['client_id' => 7, 'meta_key' => 'confirm_email']);
    $db->insert('client', ['id' => 8, 'email' => 'bob@client.test', 'status' => 'active', 'first_name' => 'Bob']);

    return $db;
}

function privacyDi(Connection $db, array $config = ['privacy_tools' => '1', 'marketing_optout' => '1']): Pimple\Container
{
    $di = container();
    $di['dbal'] = $db;
    $filesystem = Mockery::mock(Symfony\Component\Filesystem\Filesystem::class);
    $filesystem->shouldReceive('remove')->with(ClientFence\Mod\Client\Files::dir() . '/7');
    $di['filesystem'] = $filesystem;
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;
    $di['mod_config'] = $di->protect(fn (string $name): array => $name === 'client' ? $config : []);

    return $di;
}

test('both privacy switches are off by default', function (): void {
    $di = privacyDi(privacyDb(), []);

    expect(Privacy::toolsOn($di))->toBeFalse()->and(Privacy::optOutOn($di))->toBeFalse();
});

test('staff privacy exports list private notes and documents while client exports omit them', function (): void {
    $db = privacyDb();
    $db->insert('admin', ['id' => 2, 'name' => 'Jane Staff']);
    $db->insert('client_note', ['client_id' => 7, 'admin_id' => 2, 'note' => 'Private note']);
    $token = str_repeat('a', 32);
    $db->insert('client_file', ['client_id' => 7, 'admin_id' => 2, 'name' => 'contract.txt', 'stored_name' => $token]);
    $di = privacyDi($db);

    $client = Privacy::export($di, 7);
    $staff = Privacy::export($di, 7, true);

    expect($client)->not->toHaveKeys(['notes', 'files'])
        ->and($staff['notes'][0])->toMatchArray(['note' => 'Private note', 'author' => 'Jane Staff'])
        ->and($staff['files'][0])->toMatchArray(['name' => 'contract.txt', 'author' => 'Jane Staff'])
        ->and($staff['files'][0])->not->toHaveKey('stored_name');
});

test('the export holds the client\'s data and no secrets or staff notes', function (): void {
    $data = Privacy::export(privacyDi(privacyDb()), 7);
    $json = (string) json_encode($data);

    expect($data['client']['email'])->toBe('alice@client.test')
        ->and($data['orders'])->toHaveCount(1)
        ->and($data['invoices'][0]['buyer_last_name'])->toBe('Smith')
        ->and($data['tickets'][0]['messages'])->toHaveCount(2)
        ->and($data['tickets'][0]['messages'][0]['author'])->toBe('you')
        ->and($data['emails_sent'][0]['subject'])->toBe('Welcome Alice')
        ->and($data['marketing_email'])->toBe('on')
        ->and($json)->not->toContain('secret-token')
        ->and($json)->not->toContain('"pass"')
        ->and($data['client'])->not->toHaveKey('notes')
        ->and($json)->not->toContain('VIP')
        ->and($json)->not->toContain('call Alice')
        ->and($json)->not->toContain('EPP-SECRET')
        ->and($json)->not->toContain('staff only')
        ->and($json)->not->toContain('Alice is nice')
        ->and($json)->not->toContain('bob@client.test');
});

test('anonymizing erases the person and keeps the money records', function (): void {
    $db = privacyDb();
    $db->insert('client_note', ['client_id' => 7, 'note' => 'Private note']);
    $db->insert('client_file', ['client_id' => 7, 'name' => 'contract.pdf']);
    Privacy::anonymize(privacyDi($db), 7, 1);

    expect((int) $db->fetchOne('SELECT COUNT(*) FROM client_note'))->toBe(0)
        ->and((int) $db->fetchOne('SELECT COUNT(*) FROM client_file'))->toBe(0);

    $client = $db->fetchAssociative('SELECT * FROM client WHERE id = 7');
    expect($client['email'])->toBe('anonymized-7@anonymized.invalid')
        ->and($client['first_name'] . ' ' . $client['last_name'])->toBe('Anonymized Client #7')
        ->and($client['status'])->toBe('canceled')
        ->and($client['pass'])->not->toBe('hash')
        ->and($client['country'])->toBe('US')
        ->and(array_filter([$client['phone'], $client['company'], $client['address_1'], $client['city'], $client['notes'], $client['ip'], $client['api_token'], $client['custom_1']]))->toBe([]);

    $invoice = $db->fetchAssociative('SELECT * FROM invoice WHERE id = 3');
    expect($invoice['status'])->toBe('paid')
        ->and($invoice['buyer_first_name'] . ' ' . $invoice['buyer_last_name'])->toBe('Anonymized Client #7')
        ->and($invoice['buyer_address'])->toBeNull()
        ->and($invoice['buyer_company_vat'])->toBe('US123')
        ->and($invoice['buyer_country'])->toBe('US')
        ->and((float) $db->fetchOne('SELECT amount FROM "transaction"'))->toBe(10.0)
        ->and($db->fetchOne('SELECT ipn FROM "transaction"'))->toBeNull()
        ->and($db->fetchOne('SELECT notes FROM client_order'))->toBeNull()
        ->and($db->fetchOne('SELECT contact_email FROM service_domain'))->toBeNull()
        ->and($db->fetchOne('SELECT transfer_code FROM service_domain'))->toBeNull()
        ->and($db->fetchFirstColumn('SELECT DISTINCT content FROM support_ticket_message'))->toBe(['[Removed: the client was anonymized.]'])
        ->and($db->fetchOne('SELECT subject FROM support_ticket'))->toBe('Ticket (removed)')
        ->and($db->fetchOne('SELECT note FROM support_ticket_note'))->toBe('[Removed: the client was anonymized.]');
    foreach (['activity_client_history', 'activity_client_email', 'extension_meta'] as $table) {
        expect((int) $db->fetchOne("SELECT COUNT(*) FROM $table"))->toBe(0);
    }
    // Another client is untouched; a second run is refused.
    expect($db->fetchOne('SELECT email FROM client WHERE id = 8'))->toBe('bob@client.test')
        ->and(fn () => Privacy::anonymize(privacyDi($db), 7, 1))->toThrow(ClientFence\InformationException::class, 'anonymized already');
});

test('anonymizing waits for live services and unpaid invoices', function (): void {
    $db = privacyDb();
    $db->update('client_order', ['status' => 'suspended'], ['id' => 1]);
    expect(fn () => Privacy::anonymize(privacyDi($db), 7, 1))->toThrow(ClientFence\InformationException::class, 'live services');

    $db->update('client_order', ['status' => 'canceled'], ['id' => 1]);
    $db->update('invoice', ['status' => 'unpaid'], ['id' => 3]);
    expect(fn () => Privacy::anonymize(privacyDi($db), 7, 1))->toThrow(ClientFence\InformationException::class, 'unpaid invoices')
        ->and($db->fetchOne('SELECT email FROM client WHERE id = 7'))->toBe('alice@client.test');
});

test('the unsubscribe link is signed per client and opting out is kept', function (): void {
    $db = privacyDb();
    $di = privacyDi($db);
    $previousSalt = ClientFence\Config::getProperty('info.salt');
    ClientFence\Config::setProperty('info.salt', 'test-salt', false);

    try {
        $token = Privacy::token($di, 7);
        expect($token)->toBe(substr(hash_hmac('sha256', 'unsubscribe|7', 'test-salt'), 0, 32))
            ->and(Privacy::tokenValid($di, 7, $token))->toBeTrue()
            ->and(Privacy::tokenValid($di, 8, $token))->toBeFalse()
            ->and(Privacy::tokenValid($di, 7, str_repeat('0', 32)))->toBeFalse()
            ->and(Privacy::unsubscribeUrl($di, 7))->toEndWith('/unsubscribe/7/' . $token);
    } finally {
        ClientFence\Config::setProperty('info.salt', $previousSalt, false);
    }

    Privacy::setOptOut($di, 7, true, 'link');
    expect(Privacy::optedOut($db, 7))->toBeTrue()->and(Privacy::optedOut($db, 8))->toBeFalse();
    Privacy::setOptOut($di, 7, false, 'profile');
    expect(Privacy::optedOut($db, 7))->toBeFalse();
});

test('the footer escapes its link and the queue keeps the unsubscribe link until the email is sent', function (): void {
    $db = privacyDb();
    $di = privacyDi($db);

    expect(Privacy::footer('https://x.test/unsubscribe/7/ab"><script>'))->not->toContain('"><script>')
        ->and(Privacy::footer('https://x.test/u'))->toContain('href="https://x.test/u"');

    Privacy::rememberUnsubscribe($di, 41, 'https://x.test/unsubscribe/7/abc');
    expect(Privacy::queuedUnsubscribe($di, 41))->toBe('https://x.test/unsubscribe/7/abc')
        ->and(Privacy::queuedUnsubscribe($di, 42))->toBeNull();
    Privacy::forgetUnsubscribe($di, 41);
    expect(Privacy::queuedUnsubscribe($di, 41))->toBeNull();
});
