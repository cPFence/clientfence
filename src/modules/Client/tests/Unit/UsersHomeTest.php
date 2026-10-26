<?php

declare(strict_types=1);

use ClientFence\Mod\Client\Google;
use ClientFence\Mod\Client\Service;
use ClientFence\Mod\Client\Users;

use function Tests\Helpers\container;

// Authorized users, two-factor sign-in and Google sign-in live in the Client module.
test('Google sign-in reads its settings from the Client settings, with defaults', function (array $client, array $expected): void {
    $di = container();
    $di['mod_config'] = $di->protect(fn (string $name): array => $name === 'client' ? $client : ['google_signin' => '1']);

    expect(array_intersect_key(Google::config($di), $expected))->toEqual($expected);
})->with([
    'nothing saved' => [[], ['google_signin' => '0', 'google_mode' => 'both', 'google_client_id' => '']],
    'saved' => [['google_signin' => '1', 'google_mode' => 'signin', 'google_client_id' => 'x'], ['google_signin' => '1', 'google_mode' => 'signin', 'google_client_id' => 'x']],
    'empty means the default' => [['google_mode' => ''], ['google_mode' => 'both']],
    'other client settings are not Google settings' => [['disable_signup' => '1'], ['google_signin' => '0']],
]);

test('Google sign-in is off without a client id and secret', function (): void {
    $di = container();
    $di['mod_config'] = $di->protect(fn (string $name): array => $name === 'client' ? ['google_signin' => '1', 'google_client_id' => 'id'] : []);

    expect(Google::on($di))->toBeFalse();
});

test('authorized users reach their own calls in the client module', function (): void {
    // The bell: every user reads the account's notifications (filtered by page permission);
    // the order page reads the tax rate for any user who opens it.
    expect(Users::CALLS['clientfence'])->toBe(['notifications_get' => '*', 'notifications_read' => '*', 'notifications_dismiss' => '*', 'tax' => '*'])
        ->and(Users::CALLS['client']['user_session'])->toBe('*')
        ->and(Users::CALLS['client']['twofactor_setup'])->toBe('*')
        ->and(Users::CALLS['client']['google_link'])->toBe('*')
        ->and(Users::CALLS['client']['balance_get_list'])->toBe('invoices')
        // The Users page is the owner's: its calls are not listed.
        ->and(Users::CALLS['client'])->not->toHaveKey('users_get_list');
});

test('the client module shows the staff Users and Contacts tabs and the Google buttons', function (): void {
    expect((new Service())->getWidgets())->toBe([
        ['slot' => 'admin.client.manage.tabs', 'template' => 'mod_client_users_tab'],
        ['slot' => 'admin.client.manage.panes', 'template' => 'mod_client_users_pane'],
        ['slot' => 'admin.client.manage.tabs', 'template' => 'mod_client_contacts_tab'],
        ['slot' => 'admin.client.manage.panes', 'template' => 'mod_client_contacts_pane'],
        ['slot' => 'client.page.login.form.after', 'template' => 'mod_client_google_button'],
        ['slot' => 'client.page.signup.form.after', 'template' => 'mod_client_google_signup_button'],
    ]);
});

test('the generic /client/<page> route cannot open the moved pages', function (): void {
    // ClientFence\Mod\Client\Controller\Client::get_client_page renders mod_client_<page> for [a-z0-9-]+.
    $dir = dirname(__DIR__, 2) . '/templates/client';
    foreach (['users', 'contacts', 'twofactor', 'google', 'no-access', 'user-profile'] as $page) {
        expect(file_exists("{$dir}/mod_client_{$page}.html.twig"))->toBeFalse();
    }
});

test('no user, owner links included, sets the owner first password or profile after a Google sign-up', function (): void {
    expect(Users::OWNER_LOGIN_CALLS['client'])->toContain('google_password')
        ->and(Users::OWNER_LOGIN_CALLS['client'])->toContain('google_profile_update');
});

test('Google sign-in links only a client account whose email is confirmed', function (): void {
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE client (id INTEGER PRIMARY KEY, email TEXT, email_approved INTEGER)');
    $db->executeStatement('CREATE TABLE client_user (id INTEGER PRIMARY KEY, email TEXT)');
    $db->executeStatement("INSERT INTO client VALUES (1, 'confirmed@example.com', 1), (2, 'unconfirmed@example.com', NULL), (3, 'zero@example.com', 0)");
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di = container();
    $di['em'] = $em;
    $di['mod_config'] = $di->protect(fn (string $name): array => ['google_signin' => '1', 'google_mode' => 'signin']);
    $match = new ReflectionMethod(Google::class, 'matchEmail');
    $claims = static fn (string $email): array => ['sub' => 's', 'email' => $email, 'email_verified' => true];

    expect($match->invoke(null, $di, $claims('confirmed@example.com')))->toBe(['client', 1])
        ->and(fn () => $match->invoke(null, $di, $claims('unconfirmed@example.com')))->toThrow(ClientFence\InformationException::class, 'not confirmed')
        ->and(fn () => $match->invoke(null, $di, $claims('zero@example.com')))->toThrow(ClientFence\InformationException::class, 'not confirmed');
});

test('Google session recovery uses an opaque callback token bound to state and browser cookie once', function (): void {
    $di = container();
    $di['dbal']->shouldReceive('getDatabasePlatform')->andReturn(new Doctrine\DBAL\Platforms\MariaDBPlatform());
    $di['dbal']->shouldReceive('fetchOne')->times(3)->with('SELECT GET_LOCK(?, ?)', Mockery::on(fn ($args) => strlen($args[0]) <= 64))->andReturn(1);
    $di['dbal']->shouldReceive('fetchOne')->times(3)->with('SELECT RELEASE_LOCK(?)', Mockery::any())->andReturn(1);
    $di['cache'] = new Symfony\Component\Cache\Adapter\ArrayAdapter();
    $state = str_repeat('a', 64);
    $key = (new ReflectionMethod(Google::class, 'cacheKey'))->invoke(null, $state);
    $item = $di['cache']->getItem($key);
    $item->set(['session' => 'original-browser-session', 'bind' => hash('sha256', 'browser-cookie')])->expiresAfter(300);
    $di['cache']->save($item);
    $url = Mockery::mock(ClientFence\Url::class);
    $url->shouldReceive('link')->andReturnUsing(fn ($path, $query) => '/' . $path . '?' . http_build_query($query));
    $di['url'] = $url;
    $next = Google::restoreUrl($di, ['state' => $state, 'code' => 'authorization-code'], 'browser-cookie');
    parse_str(parse_url($next, PHP_URL_QUERY), $query);
    expect($next)->not->toContain('original-browser-session')
        ->and($query['restore_token'])->toMatch('/^[a-f0-9]{64}$/')
        ->and(Google::consumeRestoreToken($di, $query, 'another-cookie'))->toBeNull()
        ->and(Google::consumeRestoreToken($di, $query, 'browser-cookie'))->toBe('original-browser-session')
        ->and(Google::consumeRestoreToken($di, $query, 'browser-cookie'))->toBeNull();
});

test('authorized-user password and token consumption roll back together on a storage failure', function (): void {
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE client_user (id INTEGER PRIMARY KEY, status TEXT, pass TEXT, session_version INTEGER, updated_at TEXT)');
    $db->executeStatement('CREATE TABLE client_user_password_reset (user_id INTEGER, token_hash TEXT, expires_at TEXT)');
    $db->insert('client_user', ['id' => 1, 'status' => 'active', 'pass' => 'old', 'session_version' => 0]);
    $token = str_repeat('a', 64);
    $db->insert('client_user_password_reset', ['user_id' => 1, 'token_hash' => hash('sha256', $token), 'expires_at' => date('Y-m-d H:i:s', time() + 300)]);
    $db->executeStatement("CREATE TRIGGER fail_reset_delete BEFORE DELETE ON client_user_password_reset BEGIN SELECT RAISE(ABORT, 'test storage failure'); END");
    $di = container();
    $di['em']->shouldReceive('getConnection')->andReturn($db);
    $di['password'] = Mockery::mock(ClientFence\PasswordManager::class);
    $di['password']->shouldReceive('hashIt')->andReturn('new');
    expect(fn () => Users::resetPassword($di, $token, 'NewPassword123!', 'NewPassword123!', '127.0.0.1'))->toThrow(Exception::class, 'test storage failure');
    expect($db->fetchOne('SELECT pass FROM client_user'))->toBe('old')
        ->and((int) $db->fetchOne('SELECT COUNT(*) FROM client_user_password_reset'))->toBe(1);
});
