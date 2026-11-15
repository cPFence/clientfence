<?php

declare(strict_types=1);

/**
 * ClientFence: staff bell alerts sent to chat (Mod\Clientfence\ChatAlerts) on in-memory SQLite, with
 * a mock HTTP client that records what would be posted: who gets a message, its text and link,
 * repeats, the checks on saving, the encrypted secret and the Super Administrator gate.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Clientfence\Api\Admin;
use ClientFence\Mod\Clientfence\ChatAlerts;
use ClientFence\Mod\Clientfence\Notifications;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Staff 1 (super admin, sees everything) and 2 (sees orders only); $sent collects every request. */
function chatContainer(array &$sent, int $httpCode = 200): array
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE admin (id INTEGER PRIMARY KEY, status TEXT, system_name TEXT)');
    $db->executeStatement("INSERT INTO admin VALUES (1, 'active', NULL), (2, 'active', NULL)");
    $db->executeStatement('CREATE TABLE ' . Notifications::TABLE . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, audience TEXT NOT NULL, recipient_id INTEGER NOT NULL, kind TEXT NOT NULL, params TEXT NOT NULL, url TEXT NOT NULL, dedupe TEXT, created_at TEXT NOT NULL, read_at TEXT, UNIQUE (audience, recipient_id, dedupe))');
    $db->executeStatement('CREATE TABLE mod_clientfence_state (name TEXT PRIMARY KEY, value TEXT, updated_at TEXT)');

    $di = new Pimple\Container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;
    $di['logger'] = Mockery::mock()->shouldIgnoreMissing();
    $di['db'] = new class {
        public function load(string $type, int $id): Model_Admin
        {
            $admin = new Model_Admin();
            $admin->loadBean(new Tests\Helpers\DummyBean());
            $admin->id = $id;

            return $admin;
        }
    };
    $staff = new class {
        public function hasPermission(Model_Admin $admin, string $module): bool
        {
            return (int) $admin->id === 1 || $module === 'order';
        }

        public function isSuperAdministrator(int $id): bool
        {
            return $id === 1;
        }
    };
    $di['mod_service'] = $di->protect(fn () => $staff);
    $crypt = Mockery::mock();
    $crypt->shouldReceive('encrypt')->andReturnUsing(fn (string $p): string => 'enc:' . strrev($p));
    $crypt->shouldReceive('decrypt')->andReturnUsing(fn (string $p): string => strrev(substr($p, 4)));
    $di['crypt'] = $crypt;
    $di['url'] = Mockery::mock(ClientFence\Url::class);
    $di['url']->shouldReceive('adminLink')->andReturnUsing(fn (string $path): string => 'https://billing.example.com/admin/' . $path);
    $di['http_client'] = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent, $httpCode): MockResponse {
        $sent[] = ['url' => $url, 'body' => json_decode($options['body'], true)];

        return new MockResponse($httpCode === 200 ? 'ok' : 'invalid_token', ['http_code' => $httpCode]);
    });

    return [$di, $db];
}

function chatAdminApi(Pimple\Container $di, int $adminId): Admin
{
    $admin = new Model_Admin();
    $admin->loadBean(new Tests\Helpers\DummyBean());
    $admin->id = $adminId;
    $api = new Admin();
    $api->setDi($di);
    $api->setIdentity($admin);

    return $api;
}

test('a staff alert reaches each destination that is on and ticked its kind, once, with the bell text and the admin link', function (): void {
    $sent = [];
    [$di] = chatContainer($sent);
    ChatAlerts::save($di, ['name' => 'Sales', 'type' => 'slack', 'enabled' => '1', 'webhook_url' => 'https://hooks.slack.com/services/T0/B0/x', 'kinds' => ['order_new', 'not_a_kind']]);
    ChatAlerts::save($di, ['name' => 'Ops', 'type' => 'telegram', 'enabled' => '1', 'bot_token' => '123456:ABC-def', 'chat_id' => '-100200', 'kinds' => ['order_new']]);
    ChatAlerts::save($di, ['name' => 'Paused', 'type' => 'discord', 'enabled' => '0', 'webhook_url' => 'https://discord.com/api/webhooks/1/a', 'kinds' => ['order_new']]);
    ChatAlerts::save($di, ['name' => 'Support', 'type' => 'discord', 'enabled' => '1', 'webhook_url' => 'https://discord.com/api/webhooks/2/b', 'kinds' => ['ticket_new']]);

    // Two staff members get the bell; chat gets one message per destination.
    Notifications::staff($di, 'order_new', ['id' => 7, 'title' => 'Hosting <b>'], 'order/manage/7', 'order');
    ChatAlerts::flush($di);

    expect($sent)->toBe([
        ['url' => 'https://hooks.slack.com/services/T0/B0/x', 'body' => ['text' => "New order #7: Hosting &lt;b&gt;\nhttps://billing.example.com/admin/order/manage/7"]],
        ['url' => 'https://api.telegram.org/bot123456:ABC-def/sendMessage', 'body' => ['chat_id' => '-100200', 'text' => "New order #7: Hosting <b>\nhttps://billing.example.com/admin/order/manage/7", 'disable_web_page_preview' => true]],
    ])->and(ChatAlerts::forStaff($di)['destinations'][0]['kinds'])->toBe(['order_new']);
});

test('a repeat with the same dedupe key and a client notification send nothing', function (): void {
    $sent = [];
    [$di] = chatContainer($sent);
    ChatAlerts::save($di, ['name' => 'Billing', 'type' => 'slack', 'enabled' => '1', 'webhook_url' => 'https://hooks.slack.com/services/a', 'kinds' => ['payment_received', 'invoice_paid']]);

    Notifications::staff($di, 'payment_received', ['number' => 'CF-1', 'amount' => '5.00 USD'], 'invoice/manage/1', 'invoice', null, 'invoice_paid:1');
    Notifications::staff($di, 'payment_received', ['number' => 'CF-1', 'amount' => '5.00 USD'], 'invoice/manage/1', 'invoice', null, 'invoice_paid:1');
    Notifications::client($di, 9, 'invoice_paid', ['number' => 'CF-1'], 'invoice/abc');
    ChatAlerts::flush($di);

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['body']['text'])->toContain('Invoice CF-1 paid');
});

test('a daily repeat is not sent again after a new staff member joins', function (): void {
    $sent = [];
    [$di, $db] = chatContainer($sent);
    ChatAlerts::save($di, ['name' => 'Domains', 'type' => 'slack', 'enabled' => '1', 'webhook_url' => 'https://hooks.slack.com/services/a', 'kinds' => ['domain_expiring']]);
    $alert = fn () => Notifications::staff($di, 'domain_expiring', ['domain' => 'example.com', 'date' => '1 Dec'], 'order/manage/5', 'order', null, 'expires:5');

    $alert();
    ChatAlerts::flush($di);
    $db->executeStatement("INSERT INTO admin VALUES (3, 'active', NULL)");
    $alert();
    ChatAlerts::flush($di);

    expect($sent)->toHaveCount(1)
        ->and((int) $db->fetchOne('SELECT COUNT(*) FROM ' . Notifications::TABLE . ' WHERE recipient_id = 3'))->toBe(1);
});

test('Discord shows client text as typed, not as a link, and keeps the admin link working', function (): void {
    $sent = [];
    [$di] = chatContainer($sent);
    ChatAlerts::save($di, ['name' => 'Support', 'type' => 'discord', 'enabled' => '1', 'webhook_url' => 'https://discord.com/api/webhooks/2/b', 'kinds' => ['ticket_new']]);

    Notifications::staff($di, 'ticket_new', ['id' => 12, 'subject' => '[Verify here](https://evil.example/x_y)'], 'support/ticket/12', 'support');
    ChatAlerts::flush($di);

    expect($sent[0]['body']['content'])->toBe("New ticket \\#12: \\[Verify here\\]\\(https://evil.example/x\\_y\\)\nhttps://billing.example.com/admin/support/ticket/12");
});

test('only Slack and Discord webhook addresses and Telegram tokens are saved', function (array $data, string $message): void {
    $sent = [];
    [$di] = chatContainer($sent);
    expect(fn () => ChatAlerts::save($di, $data + ['name' => 'X', 'enabled' => '1']))->toThrow(InformationException::class, $message);
})->with([
    'other host' => [['type' => 'slack', 'webhook_url' => 'https://hooks.slack.com.evil.example/x'], 'https://hooks.slack.com/'],
    'http' => [['type' => 'discord', 'webhook_url' => 'http://discord.com/api/webhooks/1/a'], 'https://discord.com/api/webhooks/'],
    'internal' => [['type' => 'discord', 'webhook_url' => 'https://127.0.0.1/api/webhooks/1'], 'https://discord.com/api/webhooks/'],
    'line break' => [['type' => 'slack', 'webhook_url' => "https://hooks.slack.com/x\nHost: a"], 'https://hooks.slack.com/'],
    'token path' => [['type' => 'telegram', 'bot_token' => '1:a/../../x', 'chat_id' => '5'], 'bot token'],
    'chat id' => [['type' => 'telegram', 'bot_token' => '1:abc', 'chat_id' => 'x y'], 'chat ID'],
    'no secret' => [['type' => 'slack', 'webhook_url' => ''], 'webhook URL'],
]);

test('the secret is kept encrypted, shown masked, and kept when the field is left empty', function (): void {
    $sent = [];
    [$di, $db] = chatContainer($sent);
    $id = ChatAlerts::save($di, ['name' => 'Ops', 'type' => 'slack', 'enabled' => '1', 'webhook_url' => 'https://hooks.slack.com/services/SECRET1234', 'kinds' => ['order_new']]);
    $stored = (string) $db->fetchOne('SELECT value FROM mod_clientfence_state WHERE name = ?', [ChatAlerts::STATE]);

    ChatAlerts::save($di, ['id' => $id, 'name' => 'Ops 2', 'type' => 'slack', 'enabled' => '1', 'webhook_url' => '', 'kinds' => ['order_new']]);
    $view = ChatAlerts::forStaff($di)['destinations'];

    expect($stored)->not->toContain('SECRET1234')
        ->and($view)->toHaveCount(1)
        ->and($view[0])->toMatchArray(['id' => $id, 'name' => 'Ops 2', 'hint' => '…1234'])
        ->and($view[0])->not->toHaveKey('secret')
        ->and(json_encode($view))->not->toContain('SECRET')
        // Another type cannot keep a Slack URL as its token.
        ->and(fn () => ChatAlerts::save($di, ['id' => $id, 'name' => 'Ops', 'type' => 'telegram', 'bot_token' => '', 'chat_id' => '5']))->toThrow(InformationException::class, 'bot token');

    Notifications::staff($di, 'order_new', ['id' => 1, 'title' => 'A'], 'order/manage/1', 'order');
    ChatAlerts::flush($di);
    expect($sent[0]['url'])->toBe('https://hooks.slack.com/services/SECRET1234');
});

test('a failed send keeps the last error without the secret and never throws; a success clears it', function (): void {
    $sent = [];
    [$di] = chatContainer($sent, 403);
    $id = ChatAlerts::save($di, ['name' => 'Ops', 'type' => 'slack', 'enabled' => '1', 'webhook_url' => 'https://hooks.slack.com/services/a', 'kinds' => ['order_new']]);

    Notifications::staff($di, 'order_new', ['id' => 1, 'title' => 'A'], 'order/manage/1', 'order');
    ChatAlerts::flush($di);
    expect(ChatAlerts::forStaff($di)['destinations'][0]['error'])->toBe('HTTP 403: invalid_token')
        ->and(fn () => ChatAlerts::test($di, $id))->toThrow(InformationException::class, 'HTTP 403');

    $di['http_client'] = new MockHttpClient(new MockResponse('ok'));
    ChatAlerts::test($di, $id);
    expect(ChatAlerts::forStaff($di)['destinations'][0]['error'])->toBeNull();

    $di['http_client'] = new MockHttpClient(fn () => throw new Symfony\Component\HttpClient\Exception\TransportException('Timeout for "https://hooks.slack.com/services/a"'));
    Notifications::staff($di, 'order_new', ['id' => 2, 'title' => 'B'], 'order/manage/2', 'order');
    ChatAlerts::flush($di);
    expect(ChatAlerts::forStaff($di)['destinations'][0]['error'])->toBe('Timeout for "…"');
});

test('every chat alert call refuses staff who are not Super Administrators', function (string $method, array $data): void {
    $sent = [];
    [$di] = chatContainer($sent);
    $id = ChatAlerts::save($di, ['name' => 'Ops', 'type' => 'slack', 'enabled' => '1', 'webhook_url' => 'https://hooks.slack.com/services/a', 'kinds' => []]);

    expect(fn () => chatAdminApi($di, 2)->{$method}($data + ['id' => $id]))->toThrow(InformationException::class, 'Only Super Administrators')
        ->and($sent)->toBe([])
        ->and(ChatAlerts::forStaff($di)['destinations'])->toHaveCount(1)
        ->and(chatAdminApi($di, 1)->chat_alerts_get()['destinations'][0]['name'])->toBe('Ops');
})->with([
    'get' => ['chat_alerts_get', []],
    'save' => ['chat_alert_save', ['name' => 'Changed', 'type' => 'slack', 'webhook_url' => 'https://hooks.slack.com/services/b']],
    'delete' => ['chat_alert_delete', []],
    'test' => ['chat_alert_test', []],
]);
