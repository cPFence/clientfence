<?php

declare(strict_types=1);

/**
 * ClientFence: the API request log (Activity\ApiLog, CF-123) on an in-memory database, and what a gateway notice
 * keeps of the request (B11).
 */

use ClientFence\Mod\Activity\ApiLog;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\HttpFoundation\Request;

function apiLogDb(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE activity_api (id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT, method TEXT, api_call TEXT, admin_id INTEGER, client_id INTEGER, ip TEXT, status INTEGER, result TEXT, duration_ms INTEGER, params TEXT, created_at TEXT)');

    return $db;
}

/** A container with a request of $method from 203.0.113.9 and a session holding $session. */
function apiLogDi(string $method, array $session, Connection $db): Pimple\Container
{
    $di = new Pimple\Container();
    $di['request'] = Request::create('/api/x/y/z', $method, server: ['REMOTE_ADDR' => '203.0.113.9']);
    $di['session'] = new class($session) {
        public function __construct(private array $data)
        {
        }

        public function get(string $key): mixed
        {
            return $this->data[$key] ?? null;
        }
    };
    $di['dbal'] = $db;

    return $di;
}

afterEach(function (): void {
    ApiLog::$pending = [];
});

test('with every source off nothing is read or queued', function (): void {
    // An empty container: reading the request, the session or the database would throw.
    ApiLog::api(new Pimple\Container(), ['days' => 30], 'admin', 'invoice_get_list', ['id' => 1], 200, null, true);
    ApiLog::api(new Pimple\Container(), null, 'guest', 'cart_add_item', [], 200, null, false);

    expect(ApiLog::$pending)->toBe([]);
});

test('each source is logged only when ticked', function (string $role, bool $viaKey, string $method, ?string $source, string $call = 'some_call'): void {
    $all = array_fill_keys(ApiLog::SOURCES, true);
    expect(ApiLog::source($role, $viaKey, $method === 'POST', $call))->toBe($source);
    if ($source === null) {
        return;
    }
    $di = apiLogDi($method, [], apiLogDb());
    ApiLog::api($di, [$source => false] + $all, $role, $call, [], 200, null, $viaKey);
    expect(ApiLog::$pending)->toBe([]);

    ApiLog::api($di, [$source => true], $role, $call, [], 200, null, $viaKey);
    expect(ApiLog::$pending)->toHaveCount(1)
        ->and(ApiLog::$pending[0]['source'])->toBe($source);
})->with([
    'staff API key' => ['admin', true, 'GET', 'api_key'],
    'staff session' => ['admin', false, 'GET', 'staff'],
    'client change' => ['client', false, 'POST', 'client'],
    'guest change' => ['guest', false, 'POST', 'client'],
    'client read' => ['client', false, 'GET', null],
    'guest read' => ['guest', false, 'GET', null],
    'client read posted' => ['client', false, 'POST', null, 'profile_get'],
    'client list posted' => ['client', false, 'POST', null, 'invoice_get_list'],
    'staff read is logged' => ['admin', false, 'POST', 'staff', 'invoice_get_list'],
]);

test('only harmless values are kept, at every depth; the parameters are cut to 4 KB', function (): void {
    $masked = ApiLog::mask([
        'email' => 'a@example.com',
        'password' => 'p',
        'nested' => ['Stripe-Signature' => 's', 'card' => ['number' => '4111 1111 1111 1111'], 'deeper' => ['confirm_pin' => '123456']],
        // Secrets in fields no name list knows: a ticket secret, a registrar token, a chat webhook, an SMTP DSN, an EPP code.
        'kind' => 'other', 'label' => 'WP admin', 'value' => 'admin / Hunter2!', 'config' => ['pw' => 'enom-token'],
        'webhook_url' => 'https://hooks.example.test/T0/B0/x', 'custom_dsn' => 'smtp://u:p@host', 'transfer_code' => 'EPP-1',
        'invoice_id' => '12345', 'ids' => [3, 4], 'status' => '4242424242424242', 'enabled' => true, 'note' => null,
    ]);

    expect($masked)->toBe([
        'email' => ApiLog::HIDDEN,
        'password' => ApiLog::HIDDEN,
        'nested' => ['Stripe-Signature' => ApiLog::HIDDEN, 'card' => ['number' => ApiLog::HIDDEN], 'deeper' => ['confirm_pin' => ApiLog::HIDDEN]],
        'kind' => 'other', 'label' => ApiLog::HIDDEN, 'value' => ApiLog::HIDDEN, 'config' => ['pw' => ApiLog::HIDDEN],
        'webhook_url' => ApiLog::HIDDEN, 'custom_dsn' => ApiLog::HIDDEN, 'transfer_code' => ApiLog::HIDDEN,
        'invoice_id' => '12345', 'ids' => [3, 4], 'status' => ApiLog::HIDDEN, 'enabled' => true, 'note' => null,
    ]);
    $long = ApiLog::params(['status' => str_repeat('é', 5000)]);
    expect(strlen($long))->toBeLessThanOrEqual(ApiLog::MAX_PARAMS)
        ->and(mb_check_encoding($long, 'UTF-8'))->toBeTrue()
        ->and($long)->toEndWith('...');
});

test('the row keeps the caller, status and result, written after the response', function (): void {
    $db = apiLogDb();
    $di = apiLogDi('POST', ['admin' => ['id' => 4]], $db);
    ApiLog::api($di, ['api_key' => true], 'admin', 'invoice_update', ['id' => 9, 'password' => 'p'], 200, null, true);
    $client = apiLogDi('POST', ['client_id' => 7], $db);
    ApiLog::api($client, ['client' => true], 'client', 'profile_update', ['first_name' => 'Ann'], 200, 'This text is too long: first name.', false);

    expect((int) $db->fetchOne('SELECT COUNT(*) FROM activity_api'))->toBe(0);
    ApiLog::flush($di);

    expect($db->fetchAllAssociative('SELECT source, method, api_call, admin_id, client_id, ip, status, result, params FROM activity_api ORDER BY id'))->toBe([
        ['source' => 'api_key', 'method' => 'POST', 'api_call' => 'invoice_update', 'admin_id' => 4, 'client_id' => null, 'ip' => '203.0.113.9', 'status' => 200, 'result' => 'ok', 'params' => '{"id":9,"password":"********"}'],
        ['source' => 'client', 'method' => 'POST', 'api_call' => 'profile_update', 'admin_id' => null, 'client_id' => 7, 'ip' => '203.0.113.9', 'status' => 200, 'result' => 'This text is too long: first name.', 'params' => '{"first_name":"********"}'],
    ])->and(ApiLog::$pending)->toBe([]);
});

test('cron keeps the rows of the last N days while the log is on', function (): void {
    $db = apiLogDb();
    foreach ([40, 31, 29, 0] as $age) {
        $db->insert('activity_api', ['source' => 'staff', 'method' => 'GET', 'api_call' => 'age_' . $age, 'status' => 200, 'result' => 'ok', 'duration_ms' => 1, 'created_at' => date('Y-m-d H:i:s', time() - $age * 86_400 - 60)]);
    }
    $di = new Pimple\Container();
    $di['dbal'] = $db;

    expect(ApiLog::prune($di, ['days' => 30]))->toBe(0, 'every source off: the cron does nothing')
        ->and(ApiLog::prune($di, ['days' => 30, 'staff' => true]))->toBe(2)
        ->and($db->fetchFirstColumn('SELECT api_call FROM activity_api ORDER BY id'))->toBe(['age_29', 'age_0'])
        ->and(ApiLog::prune($di, ['days' => 7, 'staff' => true]))->toBe(1)
        ->and(ApiLog::settings(['days' => 0])['days'])->toBe(ApiLog::DAYS);
});

test('a gateway notice keeps its headers and signatures, not the environment', function (): void {
    $kept = ApiLog::noticeServer([
        'HTTP_STRIPE_SIGNATURE' => 't=1,v1=abc',
        'HTTP_WEBHOOK_SIGNATURE' => 'def',
        'HTTP_PAYPAL_TRANSMISSION_SIG' => 'ghi',
        'HTTP_USER_AGENT' => 'Stripe/1.0',
        'HTTP_AUTHORIZATION' => 'Basic secret',
        'HTTP_COOKIE' => 'session=1',
        'REQUEST_METHOD' => 'POST',
        'REMOTE_ADDR' => '203.0.113.9',
        'CONTENT_TYPE' => 'application/json',
        'DB_PASSWORD' => 'hunter2',
        'PATH' => '/usr/bin',
        'SCRIPT_FILENAME' => '/home/site/ipn.php',
        'PHP_AUTH_PW' => 'pw',
    ]);

    expect($kept)->toBe([
        'HTTP_STRIPE_SIGNATURE' => 't=1,v1=abc',
        'HTTP_WEBHOOK_SIGNATURE' => 'def',
        'HTTP_PAYPAL_TRANSMISSION_SIG' => 'ghi',
        'HTTP_USER_AGENT' => 'Stripe/1.0',
        'HTTP_AUTHORIZATION' => ApiLog::HIDDEN,
        'HTTP_COOKIE' => ApiLog::HIDDEN,
        'REQUEST_METHOD' => 'POST',
        'REMOTE_ADDR' => '203.0.113.9',
        'CONTENT_TYPE' => 'application/json',
    ])->and(ApiLog::noticeServer(null))->toBeNull();
});
