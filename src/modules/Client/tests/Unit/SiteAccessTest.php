<?php

declare(strict_types=1);

/**
 * ClientFence: the client's site at the account's boundaries. A site module may refuse a client on the request's site
 * once the sign-in details are checked, before the session starts (ClientFence\Site::admitsClient()); staff may make a
 * client on a site they choose (ClientFence\Site::onKey()).
 */

use ClientFence\InformationException;
use ClientFence\Site;

use function Tests\Helpers\container;
use function Tests\Helpers\createEntity;
use function Tests\Helpers\moduleService;

/**
 * A request on site B of the module "shop", whose clientSiteAccess() answers `$answer` (a Closure: its answer for the
 * client ID and the operation); `$services` are other modules. Returns [$di, the module's service (what it was asked), what the session
 * was given].
 */
function siteAccessWorld(bool|Closure|null $answer, array $services = []): array
{
    $di = container();
    $shop = new class($answer) {
        public array $asked = [];

        public function __construct(private readonly bool|Closure|null $answer)
        {
        }

        public function siteByKey(string $key): ?Site
        {
            return in_array($key, ['a', 'b'], true) ? new Site("https://{$key}.example.test/", "{$key}.example.test", $key) : null;
        }

        public function clientSiteAccess(int $clientId, Site $site, string $operation): ?bool
        {
            $this->asked[] = [$clientId, $site->key, $operation];

            return $this->answer instanceof Closure ? ($this->answer)($clientId, $operation) : $this->answer;
        }
    };
    $extension = Tests\Helpers\extensionServiceWithoutClientfence();
    $extension->allows('getCoreAndActiveModules')->andReturn(['shop']);
    $di['mod_service'] = $di->protect(moduleService($services + ['extension' => $extension, 'shop' => $shop]));
    $modules = $di['mod'];
    $module = Mockery::mock(ClientFence\Module::class);
    $module->allows('hasManifest')->andReturn(true);
    $module->allows('getManifest')->andReturn(['sites' => true]);
    $module->allows('cannotRun')->andReturn(null);
    $di['mod'] = $di->protect(fn (string $name): object => $name === 'shop' ? $module : $modules($name));
    $di['site'] = new Site('https://b.example.test/', 'b.example.test', 'shop/b');
    $given = new ArrayObject();
    $session = Mockery::mock(ClientFence\Session::class)->shouldIgnoreMissing();
    $session->allows('set')->andReturnUsing(function (string $key) use ($given): void {
        $given[] = $key;
    });
    $session->allows('regenerateId')->andReturnUsing(function () use ($given): void {
        $given[] = 'new session';
    });
    $di['session'] = $session;

    return [$di, $shop, $given];
}

test('a client the site\'s module refuses there is not signed in by password, with the wrong-details answer', function (): void {
    $cart = Mockery::mock(ClientFence\Mod\Cart\Service::class);
    $cart->shouldNotReceive('transferFromOtherSession');
    [$di, $shop, $given] = siteAccessWorld(false, ['cart' => $cart]);
    $tools = Mockery::mock(ClientFence\Tools::class);
    $tools->allows('validateAndSanitizeEmail')->andReturn('client@example.test');
    $di['tools'] = $tools;
    $service = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $service->allows('authorizeClient')->andReturn(createEntity(ClientFence\Mod\Client\Entity\Client::class, ['id' => 7]));
    $service->shouldNotReceive('toSessionArray');
    $guest = new ClientFence\Mod\Client\Api\Guest();
    $guest->setDi($di);
    $guest->setService($service);

    expect(fn () => $guest->login(['email' => 'client@example.test', 'password' => 'sezam']))->toThrow(InformationException::class, 'Please check your login details.')
        ->and($shop->asked)->toBe([[7, 'shop/b', 'sign-in']])
        ->and(in_array('client_id', $given->getArrayCopy(), true) || in_array('new session', $given->getArrayCopy(), true))->toBeFalse()
        ->and(array_column($di['events_manager']->events, 'event'))->not->toContain('onAfterClientLogin');
});

test('the second step of a sign-in (two-factor code, email PIN, passkey, Google) starts no session for a client the site\'s module refuses', function (): void {
    [$di, $shop, $given] = siteAccessWorld(false);

    expect(fn () => ClientFence\Mod\Client\TwoFactor::signIn($di, 7, null, '192.0.2.1'))->toThrow(InformationException::class, 'Please check your login details.')
        ->and($shop->asked)->toBe([[7, 'shop/b', 'sign-in']])
        ->and($given->getArrayCopy())->toBe([])
        ->and($di['events_manager']->events)->toBe([]);
});

test('staff make a client on the site they choose; a site no module serves is refused before the client is made', function (): void {
    [$di] = siteAccessWorld(null);
    $tools = Mockery::mock(ClientFence\Tools::class);
    $tools->allows('validateAndSanitizeEmail')->andReturn('client@example.test');
    $di['tools'] = $tools;
    $made = [];
    $service = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $service->allows('emailAlreadyRegistered')->andReturn(false);
    $service->allows('adminCreateClient')->andReturnUsing(function () use ($di, &$made): int {
        $made[] = Site::current($di)->key;

        return 5;
    });
    $admin = new ClientFence\Mod\Client\Api\Admin();
    $admin->setDi($di);
    $admin->setService($service);
    $data = ['email' => 'client@example.test', 'first_name' => 'Jane', 'password' => 'StrongPass123'];
    // Staff on the primary site.
    unset($di['site']);

    expect($admin->create($data + ['site' => 'shop/b']))->toBe(5)
        ->and($admin->create($data))->toBe(5)
        ->and(fn () => $admin->create($data + ['site' => 'shop/zz']))->toThrow(InformationException::class, 'This site is not available.')
        ->and($made)->toBe(['shop/b', null]);
});

/** Authorized users on $di: user #1 is linked to accounts #7 (used last) and #8; #7 is on site A, #8 on B. */
function siteUsersDb(Pimple\Container $di): Doctrine\DBAL\Connection
{
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE client (id INTEGER PRIMARY KEY, status TEXT, site TEXT, email TEXT, first_name TEXT, last_name TEXT, company TEXT, lang TEXT)');
    $db->executeStatement('CREATE TABLE client_user (id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT, pass TEXT, status TEXT, session_version INTEGER, lang TEXT, created_at TEXT, updated_at TEXT)');
    $db->executeStatement('CREATE TABLE client_user_account (id INTEGER PRIMARY KEY, user_id INTEGER, client_id INTEGER, owner INTEGER DEFAULT 0, permissions TEXT, last_login_at TEXT, created_at TEXT, updated_at TEXT)');
    $db->executeStatement('CREATE TABLE client_user_invite (id INTEGER PRIMARY KEY, client_id INTEGER, email TEXT, permissions TEXT, token_hash TEXT, created_at TEXT, expires_at TEXT)');
    $db->insert('client', ['id' => 7, 'status' => 'active', 'site' => 'shop/a', 'email' => 'a@example.test', 'first_name' => 'Ann', 'last_name' => 'A', 'company' => '', 'lang' => 'en_US']);
    $db->insert('client', ['id' => 8, 'status' => 'active', 'site' => 'shop/b', 'email' => 'b@example.test', 'first_name' => 'Ben', 'last_name' => 'B', 'company' => '', 'lang' => 'en_US']);
    $db->insert('client_user', ['id' => 1, 'email' => 'user@example.test', 'first_name' => 'Uma', 'last_name' => 'U', 'status' => 'active', 'session_version' => 1]);
    $db->insert('client_user_account', ['user_id' => 1, 'client_id' => 7, 'permissions' => '', 'last_login_at' => '2026-11-27 10:00:00']);
    $db->insert('client_user_account', ['user_id' => 1, 'client_id' => 8, 'permissions' => '', 'last_login_at' => '2026-11-01 10:00:00']);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class)->shouldIgnoreMissing();
    $em->allows('getConnection')->andReturn($db);
    $di['em'] = $em;
    $di['dbal'] = $db;
    ClientFence\Mod\Client\Users::forget();

    return $db;
}

test('a user signing in on a site gets the newest account that site lets in, not the last one used on another site', function (): void {
    // Site B lets in only account #8; #7, used last, is site A's.
    [$di, $shop] = siteAccessWorld(fn (int $clientId): bool => $clientId === 8);
    siteUsersDb($di);

    expect(ClientFence\Mod\Client\Users::loginFor($di, 1))->toBe(['user_id' => 1, 'client_id' => 8, 'version' => 1])
        ->and($shop->asked)->toBe([[7, 'shop/b', 'choose'], [8, 'shop/b', 'choose']]);

    // A site that lets in none of them: no sign-in.
    [$di] = siteAccessWorld(false);
    siteUsersDb($di);

    expect(ClientFence\Mod\Client\Users::loginFor($di, 1))->toBeNull();
});

test('the accounts a user can switch to on a site are the ones that site lets in', function (): void {
    [$di] = siteAccessWorld(fn (int $clientId): bool => $clientId === 8);
    siteUsersDb($di);
    $session = Mockery::mock(ClientFence\Session::class)->shouldIgnoreMissing();
    $session->allows('get')->andReturnUsing(fn (string $key): mixed => match ($key) {
        ClientFence\Mod\Client\Users::SESSION => ['user_id' => 1, 'client_id' => 8, 'version' => 1],
        'client_id' => 8,
        default => null,
    });
    $di['session'] = $session;

    expect(array_column(ClientFence\Mod\Client\Users::sessionView($di)['accounts'], 'id'))->toBe([8]);
});

test('an invitation to an account is emailed with a link on the account\'s site, also when staff send it on the primary site', function (): void {
    $email = Mockery::mock();
    $sites = [];
    [$di] = siteAccessWorld(null, ['email' => $email]);
    $email->allows('sendTemplate')->andReturnUsing(function () use ($di, &$sites): bool {
        $sites[] = ClientFence\Site::current($di)->key;

        return true;
    });
    siteUsersDb($di);
    unset($di['site']);
    $client = ['id' => 7, 'email' => 'a@example.test', 'first_name' => 'Ann', 'last_name' => 'A', 'company' => '', 'lang' => 'en_US'];

    (new ReflectionMethod(ClientFence\Mod\Client\Users::class, 'sendInvite'))->invoke(null, $di, $client, 'new@example.test', str_repeat('a', 64));

    expect($sites)->toBe(['shop/a']);
});

test('an invitation opened on a site that does not let its account in is refused before anything is made, and still works', function (): void {
    [$di] = siteAccessWorld(false);
    $db = siteUsersDb($di);
    $token = str_repeat('c', 64);
    $db->insert('client_user_invite', ['id' => 3, 'client_id' => 7, 'email' => 'new@example.test', 'permissions' => '', 'token_hash' => hash('sha256', $token), 'created_at' => date('Y-m-d H:i:s'), 'expires_at' => date('Y-m-d H:i:s', time() + 3600)]);
    $data = ['first_name' => 'Nia', 'last_name' => 'N', 'password' => 'StrongPass123', 'password_confirm' => 'StrongPass123'];

    expect(fn () => ClientFence\Mod\Client\Users::acceptInvite($di, $token, $data, '192.0.2.1'))->toThrow(InformationException::class, 'Open this invitation from the link in its email.')
        ->and($db->fetchOne('SELECT COUNT(*) FROM client_user_invite'))->toBe(1)
        ->and($db->fetchOne('SELECT COUNT(*) FROM client_user'))->toBe(1)
        ->and($db->fetchOne('SELECT COUNT(*) FROM client_user_account'))->toBe(2);
});

test('a two-factor code (a backup code works once) is not used up by a sign-in the site refuses', function (): void {
    [$di, $shop] = siteAccessWorld(false);
    $deleted = [];
    $session = Mockery::mock(ClientFence\Session::class)->shouldIgnoreMissing();
    $session->allows('get')->andReturnUsing(fn (string $key): mixed => $key === ClientFence\Mod\Client\TwoFactor::PENDING
        ? ['kind' => 'client', 'id' => 7, 'client_id' => 7, 'user' => null, 'password_fingerprint' => 'x', 'expires' => time() + 60, 'tries' => 0]
        : null);
    $session->allows('delete')->andReturnUsing(function (string $key) use (&$deleted): void {
        $deleted[] = $key;
    });
    $di['session'] = $session;
    $di['em'] = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $di['em']->shouldNotReceive('getConnection');

    expect(fn () => ClientFence\Mod\Client\TwoFactor::verify($di, 'ABCD-EFGH', '192.0.2.1'))->toThrow(InformationException::class, 'Please check your login details.')
        ->and($shop->asked)->toBe([[7, 'shop/b', 'choose']])
        ->and($deleted)->toBe([]);
});

test('a backup code stays usable when the site refuses the sign-in at its final word, after the code is right', function (): void {
    // The module lets the account be chosen, then fails to record the sign-in.
    [$di, $shop] = siteAccessWorld(fn (int $clientId, string $operation): bool => $operation === 'choose');
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE client (id INTEGER PRIMARY KEY, pass TEXT)');
    $db->executeStatement('CREATE TABLE client_twofactor (id INTEGER PRIMARY KEY, kind TEXT, subject_id INTEGER, secret TEXT, last_step INTEGER, backup_hash TEXT, updated_at TEXT)');
    $db->insert('client', ['id' => 7, 'pass' => 'password-hash']);
    $db->insert('client_twofactor', ['id' => 1, 'kind' => 'client', 'subject_id' => 7, 'secret' => '', 'last_step' => 0, 'backup_hash' => 'backup-hash']);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class)->shouldIgnoreMissing();
    $em->allows('getConnection')->andReturn($db);
    $di['em'] = $em;
    $crypt = Mockery::mock(ClientFence\Crypt::class);
    $crypt->allows('decrypt')->andReturn('');
    $di['crypt'] = $crypt;
    $password = Mockery::mock();
    $password->allows('verify')->andReturnUsing(fn (string $plain, string $hash): bool => $plain === 'ABCDEFGH' && $hash === 'backup-hash');
    $di['password'] = $password;
    $di['rate_limiter'] = new class {
        public function consumeOrThrow(): void
        {
        }

        public function consume(): void
        {
        }

        public function getStatus(): object
        {
            return new class {
                public function isLimited(): bool
                {
                    return false;
                }
            };
        }
    };
    $pending = ['kind' => 'client', 'id' => 7, 'client_id' => 7, 'user' => null, 'password_fingerprint' => hash('sha256', (string) ClientFence\Mod\Clientfence\SignInSecurity::passwordHash($di, 'client', 7)), 'expires' => time() + 60, 'tries' => 0];
    $session = Mockery::mock(ClientFence\Session::class)->shouldIgnoreMissing();
    $session->allows('get')->andReturnUsing(fn (string $key): mixed => $key === ClientFence\Mod\Client\TwoFactor::PENDING ? $pending : null);
    $di['session'] = $session;

    expect(fn () => ClientFence\Mod\Client\TwoFactor::verify($di, 'ABCD-EFGH', '192.0.2.1'))->toThrow(InformationException::class, 'Please check your login details.')
        ->and($shop->asked)->toBe([[7, 'shop/b', 'choose'], [7, 'shop/b', 'sign-in']])
        ->and($db->fetchOne('SELECT backup_hash FROM client_twofactor WHERE id = 1'))->toBe('backup-hash');
});

test('an invitation stays usable when the site refuses the sign-in at its final word, after the details are checked', function (): void {
    [$di, $shop] = siteAccessWorld(fn (int $clientId, string $operation): bool => $operation === 'choose');
    $db = siteUsersDb($di);
    $password = Mockery::mock();
    $password->allows('hashIt')->andReturn('new-hash');
    $di['password'] = $password;
    $token = str_repeat('d', 64);
    $db->insert('client_user_invite', ['id' => 4, 'client_id' => 7, 'email' => 'new@example.test', 'permissions' => '', 'token_hash' => hash('sha256', $token), 'created_at' => date('Y-m-d H:i:s'), 'expires_at' => date('Y-m-d H:i:s', time() + 3600)]);
    $data = ['first_name' => 'Nia', 'last_name' => 'N', 'password' => 'StrongPass123', 'password_confirm' => 'StrongPass123'];

    expect(fn () => ClientFence\Mod\Client\Users::acceptInvite($di, $token, $data, '192.0.2.1'))->toThrow(InformationException::class, 'Please check your login details.')
        ->and($shop->asked)->toBe([[7, 'shop/b', 'choose'], [7, 'shop/b', 'sign-in']])
        ->and($db->fetchOne('SELECT COUNT(*) FROM client_user_invite'))->toBe(1)
        ->and($db->fetchOne('SELECT COUNT(*) FROM client_user'))->toBe(1)
        ->and($db->fetchOne('SELECT COUNT(*) FROM client_user_account'))->toBe(2);
});
