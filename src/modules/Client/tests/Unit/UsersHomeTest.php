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
