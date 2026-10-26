<?php

declare(strict_types=1);

use ClientFence\Http\CookieNames;

function createSession(): ClientFence\Session
{
    $handler = new class extends PdoSessionHandler {
        public function __construct()
        {
        }
    };

    return new ClientFence\Session($handler);
}

function invokePrivate(object $instance, string $method, array $args = []): mixed
{
    $reflection = new ReflectionClass($instance);
    $methodReflection = $reflection->getMethod($method);

    return $methodReflection->invokeArgs($instance, $args);
}

afterEach(function (): void {
    $_SESSION = [];
    foreach ([
        CookieNames::SESSION,
        'PHPSESSID',
    ] as $sessionName) {
        unset($_COOKIE[$sessionName]);
    }
    if (session_status() === PHP_SESSION_NONE) {
        session_id('');
        session_name('PHPSESSID');
    }
});

test('session cookie name migrates and expires the previous session cookie', function (): void {
    $_COOKIE['PHPSESSID'] = 'legacy-session';
    $session = createSession();

    invokePrivate($session, 'configureCookieName');

    expect(session_name())->toBe(CookieNames::SESSION)
        ->and(session_id())->toBe('legacy-session');

    invokePrivate($session, 'expireLegacySessionCookies');

    expect($_COOKIE)->not->toHaveKey('PHPSESSID');
});

test('obsolete session is detected', function (): void {
    $session = createSession();

    $result = invokePrivate($session, 'isObsoleteSession', [[
        'fb_session_obsolete' => true,
    ]]);

    expect($result)->toBeTrue();
});

test('obsolete session without expiry is expired', function (): void {
    $session = createSession();

    $result = invokePrivate($session, 'isObsoleteSessionExpired', [[
        'fb_session_obsolete' => true,
    ], 100]);

    expect($result)->toBeTrue();
});

test('obsolete session expiry honors grace window', function (): void {
    $session = createSession();

    $active = invokePrivate($session, 'isObsoleteSessionExpired', [[
        'fb_session_obsolete' => true,
        'fb_session_obsolete_expires_at' => 150,
    ], 100]);

    $expired = invokePrivate($session, 'isObsoleteSessionExpired', [[
        'fb_session_obsolete' => true,
        'fb_session_obsolete_expires_at' => 150,
    ], 151]);

    expect($active)->toBeFalse();
    expect($expired)->toBeTrue();
});

test('destroying a client login preserves the admin login', function (): void {
    $_SESSION = [
        'admin' => ['id' => 1],
        'client' => ['id' => 2],
        'client_id' => 2,
    ];

    $result = createSession()->destroy('client');

    expect($result)->toBeTrue()
        ->and($_SESSION)->toHaveKey('admin')
        ->not->toHaveKeys(['client', 'client_id']);
});

test('destroying an admin login preserves the client login', function (): void {
    $_SESSION = [
        'admin' => ['id' => 1],
        'client' => ['id' => 2],
        'client_id' => 2,
    ];

    $result = createSession()->destroy('admin');

    expect($result)->toBeTrue()
        ->and($_SESSION)->not->toHaveKey('admin')
        ->and($_SESSION)->toHaveKeys(['client', 'client_id']);
});

test('destroying a client login regenerates the session with the configured grace period, not zero', function (): void {
    $handler = new class extends PdoSessionHandler {
        public function __construct()
        {
        }
    };

    $session = Mockery::mock(ClientFence\Session::class, [$handler])->makePartial();
    $session->shouldReceive('regenerateId')->withNoArgs()->once();

    $_SESSION = [
        'client' => ['id' => 2],
        'client_id' => 2,
    ];

    $session->destroy('client');
});

test('destroying an admin login regenerates the session with the configured grace period, not zero', function (): void {
    $handler = new class extends PdoSessionHandler {
        public function __construct()
        {
        }
    };

    $session = Mockery::mock(ClientFence\Session::class, [$handler])->makePartial();
    $session->shouldReceive('regenerateId')->withNoArgs()->once();

    $_SESSION = [
        'admin' => ['id' => 1],
    ];

    $session->destroy('admin');
});

test('session validation ignores a non-string session cookie', function (): void {
    session_name('PHPSESSID');
    session_id('');
    $_COOKIE['PHPSESSID'] = ['malformed-session'];

    expect(invokePrivate(createSession(), 'canUseSession'))->toBeNull();
});

test('a session deleted while a request ran is not written back by that request', function (): void {
    // QA r6 A-01: a password change deletes the other sessions; a request of one of them still running wrote its row back.
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE session (id TEXT PRIMARY KEY, content TEXT, modified_at INTEGER)');
    $handler = new PdoSessionHandler($pdo);

    $handler->read('stolen');
    $handler->write('stolen', 'client_id|i:5;');
    $running = new PdoSessionHandler($pdo);
    expect($running->read('stolen'))->toBe('client_id|i:5;');
    $pdo->exec("DELETE FROM session WHERE id = 'stolen'");

    $running->write('stolen', 'client_id|i:5;');
    expect($pdo->query("SELECT COUNT(*) FROM session WHERE id = 'stolen'")->fetchColumn())->toBe(0)
        // A new session id that was never read (session_regenerate_id) is still written.
        ->and($running->write('fresh', 'x|i:1;'))->toBeTrue()
        ->and($pdo->query("SELECT COUNT(*) FROM session WHERE id = 'fresh'")->fetchColumn())->toBe(1);
});
