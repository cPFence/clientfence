<?php

declare(strict_types=1);

/**
 * ClientFence: contacts of a client account (Client/Contacts.php) on SQLite: saving, the email
 * kinds, who gets a copy of which email, and removal with the client.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Client\Contacts;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

use function Tests\Helpers\container;

function contactsDb(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE client (id INTEGER PRIMARY KEY, email TEXT)');
    $db->executeStatement('CREATE TABLE ' . Contacts::TABLE . " (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, first_name TEXT NOT NULL DEFAULT '', last_name TEXT NOT NULL DEFAULT '', company TEXT NOT NULL DEFAULT '', email TEXT NOT NULL, phone_cc TEXT NOT NULL DEFAULT '', phone TEXT NOT NULL DEFAULT '', address_1 TEXT NOT NULL DEFAULT '', address_2 TEXT NOT NULL DEFAULT '', city TEXT NOT NULL DEFAULT '', state TEXT NOT NULL DEFAULT '', postcode TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '', tax_id TEXT NOT NULL DEFAULT '', emails TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
    $db->insert('client', ['id' => 7, 'email' => 'owner@client.test']);
    $db->insert('client', ['id' => 8, 'email' => 'other@client.test']);

    return $db;
}

function contactsDi(Connection $db): Pimple\Container
{
    $di = container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;
    $di['tools'] = new ClientFence\Tools();
    $activity = Mockery::mock();
    $activity->shouldReceive('logEvent')->byDefault();
    $di['mod_service'] = $di->protect(fn (string $name) => $activity);
    $di['session'] = new class {
        public function get(string $key): mixed
        {
            return null;
        }
    };

    return $di;
}

test('each client email has a kind, and sign-in links, codes and staff mail are never copied', function (string $code, ?string $kind): void {
    expect(Contacts::kindOf($code))->toBe($kind);
})->with([
    ['mod_invoice_created', 'invoice'],
    ['mod_invoice_paid', 'invoice'],
    ['mod_invoice_quote', 'invoice'],
    ['mod_order_price_changed', 'invoice'],
    ['mod_order_subscription_stopped', 'invoice'],
    ['mod_servicedomain_renewed', 'domain'],
    ['mod_servicehosting_activated', 'product'],
    ['mod_servicelicense_suspended', 'product'],
    ['mod_support_ticket_staff_reply', 'support'],
    ['mod_support_network_issue', 'general'],
    ['mod_order_cancel_request', 'product'],
    ['mod_some_custom_notice', 'general'],
    ['mod_client_password_reset_request', null],
    ['mod_client_signup', null],
    ['mod_client_confirm', null],
    ['mod_client_clientfence_signin_pin', null],
    ['mod_client_user_invite', null],
    ['mod_client_user_password_reset', null],
    ['mod_support_mail_rejected', null],
    ['mod_staff_ticket_open', null],
]);

test('a contact is saved with its fields and only known email kinds, and listed', function (): void {
    $di = contactsDi(contactsDb());

    $id = Contacts::save($di, 7, ['first_name' => ' Ann ', 'last_name' => 'Lee', 'email' => 'ann@acct.test', 'phone_cc' => '+44', 'phone' => '2071234567', 'country' => 'GB', 'emails' => ['invoice', 'support', 'bogus', '']]);
    $list = Contacts::listFor($di, 7);

    expect($id)->toBeGreaterThan(0)
        ->and($list)->toHaveCount(1)
        ->and($list[0])->toMatchArray(['id' => $id, 'first_name' => 'Ann', 'last_name' => 'Lee', 'email' => 'ann@acct.test', 'phone_cc' => '44', 'country' => 'GB', 'emails' => ['invoice', 'support']])
        ->and(Contacts::listFor($di, 8))->toBe([]);

    Contacts::save($di, 7, ['id' => $id, 'first_name' => 'Ann', 'email' => 'ann@acct.test', 'emails' => '']);
    expect(Contacts::listFor($di, 7)[0]['emails'])->toBe([]);
});

test('a contact phone typed in Arabic digits is saved in Latin digits', function (): void {
    $di = contactsDi(contactsDb());

    Contacts::save($di, 7, ['first_name' => 'Ann', 'email' => 'ann@acct.test', 'phone_cc' => '+٢٠', 'phone' => '١٠٠١٢٣٤٥٦٧']);

    expect(Contacts::listFor($di, 7)[0])->toMatchArray(['phone_cc' => '20', 'phone' => '1001234567']);
});

test('a contact needs a first name and a valid email, unique on the account', function (array $data, string $message): void {
    $di = contactsDi(contactsDb());
    Contacts::save($di, 7, ['first_name' => 'Ann', 'email' => 'ann@acct.test']);

    expect(fn () => Contacts::save($di, 7, $data))->toThrow(InformationException::class, $message);
})->with([
    'no first name' => [['email' => 'x@acct.test'], 'first name'],
    'no email' => [['first_name' => 'X'], 'email'],
    'same email' => [['first_name' => 'X', 'email' => 'ANN@acct.test'], 'already has a contact'],
    'bad phone code' => [['first_name' => 'X', 'email' => 'x@acct.test', 'phone_cc' => '12a'], 'country code'],
    'bad phone' => [['first_name' => 'X', 'email' => 'x@acct.test', 'phone' => 'call me'], 'phone number'],
    'too long' => [['first_name' => str_repeat('a', 101), 'email' => 'x@acct.test'], 'too long'],
]);

test('another account\'s contact cannot be changed or removed, and an account has a limit', function (): void {
    $db = contactsDb();
    $di = contactsDi($db);
    $id = Contacts::save($di, 7, ['first_name' => 'Ann', 'email' => 'ann@acct.test']);

    expect(fn () => Contacts::save($di, 8, ['id' => $id, 'first_name' => 'X', 'email' => 'x@acct.test']))->toThrow(InformationException::class, 'Contact not found')
        ->and(fn () => Contacts::delete($di, 8, $id))->toThrow(InformationException::class, 'Contact not found')
        ->and(fn () => Contacts::save($di, 99, ['first_name' => 'X', 'email' => 'x@acct.test']))->toThrow(InformationException::class, 'Client not found');

    foreach (range(2, Contacts::MAX_PER_CLIENT) as $i) {
        $db->insert(Contacts::TABLE, ['client_id' => 7, 'first_name' => 'C', 'email' => "c{$i}@acct.test", 'created_at' => 'now', 'updated_at' => 'now']);
    }
    expect(fn () => Contacts::save($di, 7, ['first_name' => 'X', 'email' => 'x@acct.test']))->toThrow(InformationException::class, 'up to');

    Contacts::delete($di, 7, $id);
    expect($db->fetchOne('SELECT COUNT(*) FROM ' . Contacts::TABLE . ' WHERE id = ?', [$id]))->toEqual(0);
});

test('copies go to the account\'s contacts with the email\'s kind, once each, never to the address sent to', function (): void {
    $db = contactsDb();
    $di = contactsDi($db);
    Contacts::save($di, 7, ['first_name' => 'Ann', 'last_name' => 'Lee', 'email' => 'ann@acct.test', 'emails' => ['invoice']]);
    Contacts::save($di, 7, ['first_name' => 'Bob', 'email' => 'bob@acct.test', 'emails' => ['invoice', 'support']]);
    Contacts::save($di, 7, ['first_name' => 'Billing', 'email' => 'billing@acct.test', 'emails' => ['invoice']]);
    Contacts::save($di, 8, ['first_name' => 'Eve', 'email' => 'eve@acct.test', 'emails' => ['invoice']]);

    expect(Contacts::copies($di, 7, 'mod_invoice_created', 'Billing@acct.test'))->toBe([
        ['email' => 'ann@acct.test', 'name' => 'Ann Lee'],
        ['email' => 'bob@acct.test', 'name' => 'Bob'],
    ])->and(array_column(Contacts::copies($di, 7, 'mod_support_ticket_staff_reply'), 'email'))->toBe(['bob@acct.test'])
        ->and(Contacts::copies($di, 7, 'mod_servicedomain_renewed'))->toBe([])
        ->and(Contacts::copies($di, 7, 'mod_client_password_reset_request'))->toBe([])
        ->and(Contacts::copies($di, 0, 'mod_invoice_created'))->toBe([]);
});

test('without the table there is nobody to copy, and a deleted client leaves no contacts', function (): void {
    $empty = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    expect(Contacts::copies(contactsDi($empty), 7, 'mod_invoice_created'))->toBe([]);
    Contacts::forgetClient($empty, 7);

    $db = contactsDb();
    $di = contactsDi($db);
    Contacts::save($di, 7, ['first_name' => 'Ann', 'email' => 'ann@acct.test']);
    Contacts::save($di, 8, ['first_name' => 'Eve', 'email' => 'eve@acct.test']);
    Contacts::forgetClient($db, 7);

    expect(Contacts::listFor($di, 7))->toBe([])->and(Contacts::listFor($di, 8))->toHaveCount(1);
});

test('the page lists the kinds with labels in KINDS order', function (): void {
    $page = Contacts::page(contactsDi(contactsDb()), 7);

    expect(array_column($page['kinds'], 'name'))->toBe(array_keys(Contacts::KINDS))
        ->and(array_column($page['kinds'], 'label'))->toBe(array_values(Contacts::KINDS))
        ->and($page)->toMatchArray(['contacts' => [], 'max' => Contacts::MAX_PER_CLIENT]);
});

test('an authorized user sets only the email kinds the user\'s permissions show, and cannot redirect the others', function (): void {
    $all = array_keys(Contacts::KINDS);

    // The owner, owner links and staff: anything.
    expect(Contacts::kindsFor($all, null, null, 'a@acct.test'))->toBe($all)
        // Only `contacts`: nothing, even when every kind is asked for.
        ->and(Contacts::kindsFor($all, ['contacts'], null, 'me@acct.test'))->toBe([])
        // Service copies (welcome emails with sign-in details) need manageproducts, not products.
        ->and(Contacts::kindsFor(['product', 'invoice'], ['contacts', 'products', 'invoices'], null, 'me@acct.test'))->toBe(['invoice'])
        ->and(Contacts::kindsFor($all, ['contacts', 'profile', 'manageproducts', 'domains', 'tickets'], null, 'x@acct.test'))->toBe(['general', 'product', 'domain', 'support'])
        // Kinds the user cannot set stay on the contact while its email stays...
        ->and(Contacts::kindsFor(['support'], ['contacts', 'tickets'], ['email' => 'Ann@acct.test', 'emails' => ['invoice', 'product']], 'ann@acct.test'))->toBe(['invoice', 'product', 'support'])
        // ...and go when the email changes.
        ->and(Contacts::kindsFor(['invoice', 'product'], ['contacts', 'tickets'], ['email' => 'ann@acct.test', 'emails' => ['invoice', 'product']], 'me@acct.test'))->toBe([]);
});

test('the page marks every kind allowed for the owner and staff', function (): void {
    $kinds = Contacts::page(contactsDi(contactsDb()), 7)['kinds'];

    expect(array_column($kinds, 'allowed'))->toBe(array_fill(0, count(Contacts::KINDS), true))
        ->and(array_keys(Contacts::KIND_PERMISSIONS))->toBe(array_keys(Contacts::KINDS));
});
