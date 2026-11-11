<?php

declare(strict_types=1);

/**
 * ClientFence: the bell (Mod\Clientfence\Notifications) on an in-memory SQLite table: who gets a
 * staff notification, duplicates, unread counts, read marks and the text.
 */

use ClientFence\Mod\Client\Users;
use ClientFence\Mod\Clientfence\Notifications;
use Doctrine\DBAL\DriverManager;

/** Staff 1 (super admin), 2 (orders only), 3 (inactive) and 4 (the cron account). */
function notificationsContainer(): array
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE admin (id INTEGER PRIMARY KEY, status TEXT, system_name TEXT)');
    $db->executeStatement("INSERT INTO admin VALUES (1, 'active', NULL), (2, 'active', NULL), (3, 'inactive', NULL), (4, 'active', 'cron')");
    $db->executeStatement('CREATE TABLE ' . Notifications::TABLE . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, audience TEXT NOT NULL, recipient_id INTEGER NOT NULL, kind TEXT NOT NULL, params TEXT NOT NULL, url TEXT NOT NULL, dedupe TEXT, created_at TEXT NOT NULL, read_at TEXT, UNIQUE (audience, recipient_id, dedupe))');

    $di = new Pimple\Container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;
    $di['logger'] = Mockery::mock()->shouldIgnoreMissing();
    $ids = new SplObjectStorage();
    $di['db'] = new class($ids) {
        public function __construct(private readonly SplObjectStorage $ids)
        {
        }

        public function load(string $type, int $id): Model_Admin
        {
            $admin = new Model_Admin();
            $this->ids[$admin] = $id;

            return $admin;
        }
    };
    $staff = new class($ids) {
        public function __construct(private readonly SplObjectStorage $ids)
        {
        }

        public function hasPermission(Model_Admin $admin, string $module): bool
        {
            return $this->ids[$admin] === 1 || ($this->ids[$admin] === 2 && $module === 'order');
        }
    };
    $di['mod_service'] = $di->protect(fn () => $staff);

    return [$di, $db];
}

function notificationsClientApi(Pimple\Container $di, ?bool $owner): ClientFence\Mod\Clientfence\Api\Client
{
    Users::forget();
    if ($owner !== null) {
        $db = $di['em']->getConnection();
        $db->executeStatement('CREATE TABLE client (id INTEGER PRIMARY KEY, status TEXT)');
        $db->executeStatement("INSERT INTO client VALUES (9, 'active')");
        $db->executeStatement('CREATE TABLE client_user (id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT, status TEXT, session_version INTEGER)');
        $db->executeStatement("INSERT INTO client_user VALUES (1, 'user@example.com', 'Test', 'User', 'active', 1)");
        $db->executeStatement('CREATE TABLE client_user_account (user_id INTEGER, client_id INTEGER, owner INTEGER, permissions TEXT)');
        $db->executeStatement('INSERT INTO client_user_account VALUES (1, 9, ?, ?)', [(int) $owner, 'invoices']);
    }
    $session = Mockery::mock(ClientFence\Session::class);
    $session->shouldReceive('get')->with(Users::SESSION)->andReturn($owner === null ? null : ['user_id' => 1, 'client_id' => 9, 'version' => 1]);
    $session->shouldReceive('get')->with('client_id')->andReturn(9);
    $di['session'] = $session;
    $di['url'] = Mockery::mock(ClientFence\Url::class);
    $di['url']->shouldReceive('link')->andReturnUsing(fn (string $path): string => 'https://site/' . $path);
    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->id = 9;
    $api = new ClientFence\Mod\Clientfence\Api\Client();
    $api->setDi($di);
    $api->setIdentity($client);

    return $api;
}

test('mark all read leaves notices for pages a limited user cannot open unread', function (?bool $owner): void {
    [$di, $db] = notificationsContainer();
    $api = notificationsClientApi($di, $owner);
    Notifications::client($di, 9, 'invoice_paid', ['number' => 'CF-1'], 'invoice/abc');
    Notifications::client($di, 9, 'ticket_answered', ['id' => 4], 'support/ticket/4');
    Notifications::client($di, 10, 'invoice_paid', ['number' => 'CF-2'], 'invoice/def');
    $ticket = (int) $db->fetchOne('SELECT id FROM ' . Notifications::TABLE . ' WHERE url = ?', ['support/ticket/4']);

    expect($api->notifications_read([]))->toBe($owner === false ? 1 : 2)
        ->and(Notifications::unreadRows($di, Notifications::CLIENT, 9))->toBe($owner === false ? [['id' => $ticket, 'url' => 'support/ticket/4']] : [])
        ->and(Notifications::unread($di, Notifications::CLIENT, 10))->toBe(1);
})->with(['owner session' => [null], 'limited user' => [false], 'owner link' => [true]]);

test('the client badge counts every visible unread notice beyond the newest fifty', function (?bool $owner): void {
    [$di] = notificationsContainer();
    $api = notificationsClientApi($di, $owner);
    for ($i = 0; $i < 55; ++$i) {
        Notifications::client($di, 9, 'invoice_paid', ['number' => 'CF-' . $i], 'invoice/' . $i);
    }
    Notifications::markRead($di, Notifications::CLIENT, 9, 1);
    Notifications::dismiss($di, Notifications::CLIENT, 9, 2);
    for ($i = 0; $i < 50; ++$i) {
        Notifications::client($di, 9, 'ticket_answered', ['id' => $i], 'support/ticket/' . $i);
    }
    Notifications::client($di, 10, 'invoice_paid', ['number' => 'Other'], 'invoice/other');

    $result = $api->notifications_get();
    expect($result['unread'])->toBe($owner === false ? 53 : 103)
        ->and($result['items'])->toHaveCount($owner === false ? 0 : Notifications::LIST_SIZE);
})->with(['owner session' => [null], 'limited user' => [false], 'owner link' => [true]]);

test('staff notifications reach the active staff who may open the module', function (): void {
    [$di] = notificationsContainer();

    expect(Notifications::staffWith($di, 'order'))->toBe([1, 2])
        ->and(Notifications::staffWith($di, 'invoice'))->toBe([1])
        ->and(Notifications::staffWith($di, 'order', [2, 3, 4]))->toBe([2]);

    Notifications::staff($di, 'order_new', ['id' => 7, 'title' => 'Hosting'], 'order/manage/7', 'order');
    Notifications::staff($di, 'payment_received', ['number' => 'CF-1', 'amount' => '5.00 USD'], 'invoice/manage/1', 'invoice');

    expect(Notifications::unread($di, Notifications::STAFF, 1))->toBe(2)
        ->and(Notifications::unread($di, Notifications::STAFF, 2))->toBe(1)
        ->and(Notifications::unread($di, Notifications::STAFF, 3))->toBe(0);
});

test('a dedupe key sends a notification once per recipient', function (): void {
    [$di] = notificationsContainer();

    Notifications::client($di, 9, 'invoice_paid', ['number' => 'CF-1'], 'invoice/abc', 'invoice_paid:1');
    Notifications::client($di, 9, 'invoice_paid', ['number' => 'CF-1'], 'invoice/abc', 'invoice_paid:1');
    Notifications::client($di, 10, 'invoice_paid', ['number' => 'CF-2'], 'invoice/def', 'invoice_paid:1');
    Notifications::client($di, 0, 'invoice_paid', ['number' => 'CF-3'], 'invoice/ghi');

    expect(Notifications::unread($di, Notifications::CLIENT, 9))->toBe(1)
        ->and(Notifications::unread($di, Notifications::CLIENT, 10))->toBe(1);
});

test('the list is newest first, with text, link and age; read marks stay with their recipient', function (): void {
    [$di] = notificationsContainer();
    Notifications::client($di, 9, 'ticket_answered', ['id' => 4, 'subject' => 'Mail <b>down</b>'], '/support/ticket/4');
    Notifications::client($di, 9, 'order_suspended', ['title' => 'VPS'], 'order/service/manage/3');
    Notifications::client($di, 10, 'quote_sent', ['number' => '#2'], 'invoice/quotes');

    $items = Notifications::latest($di, Notifications::CLIENT, 9, fn (string $path): string => 'https://site/' . $path);

    expect(array_column($items, 'text'))->toBe(['VPS is suspended', 'New reply on ticket #4: Mail <b>down</b>'])
        ->and($items[1]['url'])->toBe('https://site/support/ticket/4')
        ->and($items[0]['read'])->toBeFalse()
        ->and($items[0]['age'])->toBeLessThan(60);

    // Someone else's notification cannot be marked read.
    $other = Notifications::latest($di, Notifications::CLIENT, 10, fn (string $path): string => $path)[0]['id'];
    expect(Notifications::markRead($di, Notifications::CLIENT, 9, $other))->toBe(0)
        ->and(Notifications::markRead($di, Notifications::CLIENT, 9, $items[0]['id']))->toBe(1)
        ->and(Notifications::unread($di, Notifications::CLIENT, 9))->toBe(1)
        ->and(Notifications::markRead($di, Notifications::CLIENT, 9))->toBe(1)
        ->and(Notifications::unread($di, Notifications::CLIENT, 9))->toBe(0)
        ->and(Notifications::unread($di, Notifications::CLIENT, 10))->toBe(1);
});

test('a dismissed notification leaves the bell for good and is not sent again', function (): void {
    [$di] = notificationsContainer();
    Notifications::client($di, 9, 'order_renews', ['title' => 'VPS', 'date' => '2026-11-01'], 'order/service/manage/3', 'renews:3:2026-11-01');
    Notifications::client($di, 9, 'quote_sent', ['number' => '#2'], 'invoice/quotes');
    Notifications::client($di, 10, 'quote_sent', ['number' => '#3'], 'invoice/quotes');
    [$quote, $renews] = Notifications::latest($di, Notifications::CLIENT, 9, fn (string $path): string => $path);
    $other = Notifications::latest($di, Notifications::CLIENT, 10, fn (string $path): string => $path)[0]['id'];

    // The API checks the page of a notification before marking or dismissing it: only the recipient's own row has one.
    expect(Notifications::url($di, Notifications::CLIENT, 9, $renews['id']))->toBe('order/service/manage/3')
        ->and(Notifications::url($di, Notifications::CLIENT, 9, $other))->toBeNull();

    expect(Notifications::dismiss($di, Notifications::CLIENT, 9, $other))->toBe(0)
        ->and(Notifications::dismiss($di, Notifications::CLIENT, 9, $renews['id']))->toBe(1)
        ->and(Notifications::unread($di, Notifications::CLIENT, 9))->toBe(1);

    // The daily run announces the same renewal again: its dedupe key keeps it away.
    Notifications::client($di, 9, 'order_renews', ['title' => 'VPS', 'date' => '2026-11-01'], 'order/service/manage/3', 'renews:3:2026-11-01');
    expect(array_column(Notifications::latest($di, Notifications::CLIENT, 9, fn (string $path): string => $path), 'id'))->toBe([$quote['id']])
        ->and(Notifications::unread($di, Notifications::CLIENT, 10))->toBe(1);
});

test('old notifications are pruned', function (): void {
    [$di, $db] = notificationsContainer();
    Notifications::client($di, 9, 'invoice_overdue', ['number' => 'CF-1'], 'invoice/abc');
    $db->executeStatement('UPDATE ' . Notifications::TABLE . ' SET created_at = ?', [date('Y-m-d H:i:s', time() - (Notifications::KEEP_DAYS + 1) * 86400)]);
    Notifications::client($di, 9, 'invoice_due', ['number' => 'CF-2', 'date' => '2026-11-01'], 'invoice/def');

    expect(Notifications::prune($di))->toBe(1)
        ->and(Notifications::unread($di, Notifications::CLIENT, 9))->toBe(1);
});

test('a deleted order or invoice takes its notifications with it', function (): void {
    [$di, $db] = notificationsContainer();
    $db->executeStatement('CREATE TABLE invoice (id INTEGER PRIMARY KEY, hash TEXT)');
    $db->executeStatement("INSERT INTO invoice VALUES (7, 'abc')");
    Notifications::client($di, 9, 'order_canceled', ['title' => 'VPS'], 'order/service/manage/3');
    Notifications::staff($di, 'order_new', ['id' => 3, 'title' => 'VPS'], 'order/manage/3', 'order');
    Notifications::client($di, 9, 'order_active', ['title' => 'Mail'], 'order/service/manage/33');
    Notifications::client($di, 9, 'invoice_new', ['number' => 'CF-7', 'amount' => '5.00 USD'], 'invoice/abc', 'invoice_new:7');

    Notifications::orderDeleted($di, 3);
    Notifications::invoiceDeleted($di, 7);

    expect($db->fetchFirstColumn('SELECT url FROM ' . Notifications::TABLE))->toBe(['order/service/manage/33']);
});

test('a deleted ticket takes its notifications with it', function (): void {
    [$di, $db] = notificationsContainer();
    Notifications::staff($di, 'ticket_new', ['id' => 4, 'subject' => 'Help'], 'support/ticket/4', 'support');
    Notifications::client($di, 9, 'ticket_answered', ['id' => 4, 'subject' => 'Help'], 'support/ticket/4');
    Notifications::client($di, 9, 'ticket_answered', ['id' => 44, 'subject' => 'Other'], 'support/ticket/44');

    Notifications::ticketDeleted($di, 4);

    expect($db->fetchFirstColumn('SELECT url FROM ' . Notifications::TABLE))->toBe(['support/ticket/44']);
});

test('every kind has its own text and an unknown kind reads generic', function (): void {
    expect(Notifications::text('ticket_escalated', ['id' => 5, 'rule' => 'No reply in 4 hours']))->toBe('Ticket #5 needs attention: No reply in 4 hours')
        ->and(Notifications::text('credit_added', ['amount' => '3.00 USD']))->toBe('3.00 USD was added to your account credit')
        ->and(Notifications::text('announcement', ['title' => 'Office closed']))->toBe('Announcement: Office closed')
        ->and(Notifications::text('gone_kind', []))->toBe('Notification');
});

test('credit added to an account reaches its bell, a deduction does not', function (): void {
    // The shared test container (entity manager and repositories), on the SQLite table.
    [, $db] = notificationsContainer();
    $di = Tests\Helpers\container();
    $di['em']->shouldReceive('getConnection')->andReturn($db);
    $service = new ClientFence\Mod\Client\Service();
    $service->setDi($di);
    $client = Tests\Helpers\createEntity(ClientFence\Mod\Client\Entity\Client::class, ['id' => 9, 'currency' => 'USD']);

    $service->addFunds($client, '12.5', 'Gift');
    $service->addFunds($client, '-3', 'Correction');

    $items = Notifications::latest($di, Notifications::CLIENT, 9, fn (string $path): string => $path);
    expect(array_column($items, 'text'))->toBe(['12.50 USD was added to your account credit'])
        ->and($items[0]['url'])->toBe('client/balance');
});
