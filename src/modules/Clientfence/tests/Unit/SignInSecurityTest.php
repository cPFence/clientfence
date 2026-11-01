<?php

declare(strict_types=1);

/**
 * ClientFence: sign-in security (Mod\Clientfence\SignInSecurity): the settings and their lockout
 * checks, staff sign-in from chosen countries, the email PIN and trusted browsers, on an in-memory
 * SQLite database with fake services. APP_ENV is "test", so PINs are rendered but not sent.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Clientfence\Setup;
use ClientFence\Mod\Clientfence\SignInSecurity;
use Symfony\Component\HttpFoundation\Request;

final class SignInSecurityTestWorld
{
    public array $session = [];
    public array $pins = [];
    public array $cookies = [];
    public array $queued = [];
    // Documentation addresses stand in for public ones here; 192.168.1.5 is private (the bundled
    // database gives it a country all the same).
    public array $countries = ['198.51.100.1' => 'DE', '203.0.113.9' => 'US', '192.0.2.5' => 'EG', '192.168.1.5' => 'AU'];
    public string $allowedIps = '';
    public bool $limited = false;
    public Pimple\Container $di;
    public Doctrine\DBAL\Connection $db;

    public function __construct()
    {
        $world = $this;
        $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE mod_clientfence_state (name TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $db->executeStatement('CREATE TABLE admin (id INTEGER PRIMARY KEY, pass TEXT)');
        $db->executeStatement('CREATE TABLE client (id INTEGER PRIMARY KEY, pass TEXT)');
        $db->executeStatement('CREATE TABLE client_user (id INTEGER PRIMARY KEY, pass TEXT, email TEXT, first_name TEXT, last_name TEXT)');
        $db->executeStatement("INSERT INTO admin VALUES (1, 'hash-admin')");
        $db->executeStatement("INSERT INTO client VALUES (7, 'hash-client')");
        $this->db = $db;
        $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
        $em->shouldReceive('getConnection')->andReturn($db);

        $this->di = $di = new Pimple\Container();
        $di['em'] = $em;
        $di['session'] = new class($world) {
            public function __construct(private readonly SignInSecurityTestWorld $world)
            {
            }

            public function get(string $key): mixed
            {
                return $this->world->session[$key] ?? null;
            }

            public function set(string $key, mixed $value): void
            {
                $this->world->session[$key] = $value;
            }

            public function delete(string $key): void
            {
                unset($this->world->session[$key]);
            }
        };
        $di['rate_limiter'] = new class($world) {
            public function __construct(private readonly SignInSecurityTestWorld $world)
            {
            }

            public function consumeOrThrow(string $policy, string $subject): void
            {
            }

            public function consume(string $policy, string $subject): void
            {
            }

            public function getStatus(string $policy, string $subject): object
            {
                return new class($this->world->limited) {
                    public function __construct(private readonly bool $limited)
                    {
                    }

                    public function isLimited(): bool
                    {
                        return $this->limited;
                    }
                };
            }
        };
        $di['geoip'] = new class($world) {
            public function __construct(private readonly SignInSecurityTestWorld $world)
            {
            }

            public function get(string $ip): array
            {
                $code = $this->world->countries[$ip] ?? null;

                return $code === null ? [] : ['country' => ['iso_code' => $code]];
            }
        };
        $di['mod'] = $di->protect(fn (string $name) => new class($world, $name) {
            public function __construct(private readonly SignInSecurityTestWorld $world, private readonly string $name)
            {
            }

            public function getConfig(): array
            {
                return $this->name === 'staff' ? ['allowed_ips' => $this->world->allowedIps] : ['from_email' => 'billing@example.test', 'mailer' => 'sendmail'];
            }
        });
        $email = new class($world) {
            public function __construct(private readonly SignInSecurityTestWorld $world)
            {
            }

            public function renderTemplate(string $code, array $vars, array $preview = [], ?string $timezone = null): array
            {
                $this->world->pins[] = ['code' => $code] + $vars;

                return ['Your PIN', '<p>' . $vars['pin'] . '</p>'];
            }
        };
        $system = new class {
            public function getParamValue(string $name, $default = null)
            {
                return $name === 'company_name' ? 'Example' : $default;
            }
        };
        $di['mod_service'] = $di->protect(fn (string $name) => $name === 'email' ? $email : $system);
        $di['request'] = $di->factory(fn () => new Request([], [], [], $world->cookies));
        $di['cookie_queue'] = new class($world) {
            public function __construct(private readonly SignInSecurityTestWorld $world)
            {
            }

            public function queue(string $name, string $value = '', int $expires = 0, string $path = '/', ?string $domain = null, bool $secure = false, bool $httpOnly = false, ?string $sameSite = null): void
            {
                $this->world->queued[$name] = $value;
            }
        };
        $di['logger'] = new class {
            public function __call(string $name, array $arguments): mixed
            {
                return $this;
            }
        };
    }

    public function save(array $data, string $ip = '198.51.100.1'): array
    {
        return SignInSecurity::save($this->di, $data, ['id' => 1, 'email' => 'owner@example.test', 'name' => 'Owner', 'timezone' => ''], $ip);
    }

    public function lastPin(): string
    {
        return (string) end($this->pins)['pin'];
    }
}

test('everything is off by default and nobody is asked for a PIN', function (): void {
    $world = new SignInSecurityTestWorld();

    expect(SignInSecurity::settings($world->di))->toBe(['pin' => 'off', 'countries_on' => false, 'countries' => []])
        ->and(SignInSecurity::pinOn($world->di, 'staff'))->toBeFalse()
        ->and(SignInSecurity::challenge($world->di, 'client', ['kind' => 'client', 'id' => 7, 'email' => 'c@example.test'], [], '192.0.2.5'))->toBeFalse()
        ->and($world->pins)->toBe([]);
    SignInSecurity::checkStaffCountry($world->di, '192.0.2.99');
});

test('settings that would lock the saving admin out are refused', function (array $data, string $ip, string $message): void {
    $world = new SignInSecurityTestWorld();

    expect(fn () => $world->save($data, $ip))->toThrow(InformationException::class, $message)
        ->and(SignInSecurity::settings($world->di)['countries_on'])->toBeFalse();
})->with([
    'an unknown mode' => [['pin' => 'everyone'], '198.51.100.1', 'Choose who is asked'],
    'an unknown country' => [['countries_on' => 1, 'countries' => ['XX']], '198.51.100.1', 'Unknown country code'],
    'no country' => [['countries_on' => 1, 'countries' => []], '198.51.100.1', 'at least one country'],
    'not your own country' => [['countries_on' => 1, 'countries' => ['US']], '198.51.100.1', 'would lock you out'],
    'an address of no known country' => [['countries_on' => 1, 'countries' => ['DE']], '10.0.0.8', 'is not known'],
]);

test('a private address has no country', function (): void {
    $world = new SignInSecurityTestWorld();

    expect(SignInSecurity::countryOf($world->di, '192.168.1.5'))->toBeNull()
        ->and(SignInSecurity::countryOf($world->di, '127.0.0.1'))->toBeNull()
        ->and(SignInSecurity::countryOf($world->di, 'not an address'))->toBeNull();
});

test('staff sign in only from the chosen countries, or from an allowed address', function (): void {
    $world = new SignInSecurityTestWorld();
    expect($world->save(['countries_on' => '1', 'countries' => ['de', 'EG']]))->toBe(['saved' => true]);

    SignInSecurity::checkStaffCountry($world->di, '198.51.100.1');
    SignInSecurity::checkStaffCountry($world->di, '192.0.2.5');
    expect(fn () => SignInSecurity::checkStaffCountry($world->di, '203.0.113.9'))->toThrow(InformationException::class, 'not allowed from your location')
        ->and(fn () => SignInSecurity::checkStaffCountry($world->di, '10.0.0.8'))->toThrow(InformationException::class);

    $world->allowedIps = "10.0.0.8\n203.0.113.9";
    SignInSecurity::checkStaffCountry($world->di, '203.0.113.9');
    SignInSecurity::checkStaffCountry($world->di, '10.0.0.8');

    // With an allowed address, even a list without the admin's own country may be saved.
    expect($world->save(['countries_on' => '1', 'countries' => ['EG']], '10.0.0.8'))->toBe(['saved' => true]);
});

test('turning the staff PIN on needs a PIN sent to the saving admin', function (): void {
    $world = new SignInSecurityTestWorld();

    $first = $world->save(['pin' => 'both']);
    expect($first)->toMatchArray(['saved' => false, 'confirm' => true, 'email' => 'o••••@example.test'])
        ->and($world->pins[0])->toMatchArray(['code' => 'mod_staff_clientfence_signin_pin', 'country' => 'Germany'])
        ->and(SignInSecurity::settings($world->di)['pin'])->toBe('off');

    $wrong = $world->lastPin() === '000000' ? '111111' : '000000';
    expect(fn () => $world->save(['pin' => 'both', 'confirm_pin' => $wrong]))->toThrow(InformationException::class, 'not correct');

    expect($world->save(['pin' => 'both', 'confirm_pin' => $world->lastPin()]))->toBe(['saved' => true])
        ->and(SignInSecurity::pinOn($world->di, 'staff'))->toBeTrue()
        ->and(SignInSecurity::pinOn($world->di, 'client'))->toBeTrue();

    // Clients only needs no confirmation (it never locks staff out).
    expect($world->save(['pin' => 'clients']))->toBe(['saved' => true]);
});

test('a sign-in waits for its PIN, which works once and stops after five wrong ones', function (): void {
    $world = new SignInSecurityTestWorld();
    $world->save(['pin' => 'clients']);
    $login = ['kind' => 'client', 'id' => 7, 'email' => 'client@example.test', 'name' => 'Client'];

    expect(SignInSecurity::challenge($world->di, 'client', $login, ['client_id' => 7], '192.0.2.5'))->toBeTrue()
        ->and(SignInSecurity::pending($world->di, 'staff'))->toBeNull()
        ->and(SignInSecurity::pageInfo($world->di, 'client'))->toMatchArray(['email' => 'c•••••@example.test', 'minutes' => 10]);

    $pin = $world->lastPin();
    $wrong = $pin === '000000' ? '111111' : '000000';
    expect(fn () => SignInSecurity::verify($world->di, 'client', $wrong, false, '192.0.2.5'))->toThrow(InformationException::class, 'not correct');
    $pending = SignInSecurity::verify($world->di, 'client', substr($pin, 0, 3) . ' ' . substr($pin, 3), false, '192.0.2.5');
    expect($pending['payload'])->toBe(['client_id' => 7])
        ->and(fn () => SignInSecurity::verify($world->di, 'client', $pin, false, '192.0.2.5'))->toThrow(InformationException::class, 'expired');

    SignInSecurity::challenge($world->di, 'client', $login, [], '192.0.2.5');
    for ($i = 1; $i < SignInSecurity::MAX_TRIES; ++$i) {
        expect(fn () => SignInSecurity::verify($world->di, 'client', 'abcdef', false, '192.0.2.5'))->toThrow(InformationException::class, 'not correct');
    }
    expect(fn () => SignInSecurity::verify($world->di, 'client', 'abcdef', false, '192.0.2.5'))->toThrow(InformationException::class, 'sign in again')
        ->and(SignInSecurity::pending($world->di, 'client'))->toBeNull();
});

test('a PIN typed with Arabic or Persian digits works', function (string $zero): void {
    $world = new SignInSecurityTestWorld();
    $world->save(['pin' => 'clients']);
    SignInSecurity::challenge($world->di, 'client', ['kind' => 'client', 'id' => 7, 'email' => 'client@example.test', 'name' => 'Client'], ['client_id' => 7], '192.0.2.5');
    $local = preg_replace_callback('/\\d/', fn (array $m): string => mb_chr(mb_ord($zero) + (int) $m[0]), $world->lastPin());

    expect(SignInSecurity::verify($world->di, 'client', $local, false, '192.0.2.5')['payload'])->toBe(['client_id' => 7]);
})->with(['Arabic' => ["\u{0660}"], 'Persian' => ["\u{06F0}"]]);

test('only the account a sign-up just made signs in without a PIN', function (): void {
    $world = new SignInSecurityTestWorld();
    $world->save(['pin' => 'clients']);

    SignInSecurity::$newClientId = 8;
    try {
        expect(SignInSecurity::challenge($world->di, 'client', ['kind' => 'client', 'id' => 8, 'email' => 'new@example.test'], [], '192.0.2.5'))->toBeFalse()
            // A sign-up naming an existing account (or another login) is asked as usual.
            ->and(SignInSecurity::challenge($world->di, 'client', ['kind' => 'client', 'id' => 7, 'email' => 'client@example.test'], [], '192.0.2.5'))->toBeTrue()
            ->and(SignInSecurity::challenge($world->di, 'client', ['kind' => 'user', 'id' => 8, 'email' => 'user@example.test'], [], '192.0.2.5'))->toBeTrue();
    } finally {
        SignInSecurity::$newClientId = null;
    }
});

test('a new PIN only after a minute, and the old one stops working', function (): void {
    $world = new SignInSecurityTestWorld();
    $world->save(['pin' => 'clients']);
    SignInSecurity::challenge($world->di, 'client', ['kind' => 'client', 'id' => 7, 'email' => 'client@example.test'], [], '192.0.2.5');
    $old = $world->lastPin();

    expect(fn () => SignInSecurity::resend($world->di, 'client', '192.0.2.5'))->toThrow(InformationException::class, 'wait a minute');

    $world->session[SignInSecurity::PENDING]['sent_at'] -= SignInSecurity::RESEND_SECONDS;
    SignInSecurity::resend($world->di, 'client', '192.0.2.5');
    expect($world->pins)->toHaveCount(2);
    if ($world->lastPin() !== $old) {
        expect(fn () => SignInSecurity::verify($world->di, 'client', $old, false, '192.0.2.5'))->toThrow(InformationException::class, 'not correct');
    }
    expect(SignInSecurity::verify($world->di, 'client', $world->lastPin(), false, '192.0.2.5'))->toHaveKey('payload');
});

test('a trusted browser skips the PIN until the password changes', function (): void {
    $world = new SignInSecurityTestWorld();
    $world->save(['pin' => 'clients']);
    $login = ['kind' => 'client', 'id' => 7, 'email' => 'client@example.test'];

    SignInSecurity::challenge($world->di, 'client', $login, [], '192.0.2.5');
    SignInSecurity::verify($world->di, 'client', $world->lastPin(), true, '192.0.2.5');
    expect($world->queued)->toHaveKey('clientfence_trust_client');

    $world->cookies = ['clientfence_trust_client' => $world->queued['clientfence_trust_client']];
    expect(SignInSecurity::challenge($world->di, 'client', $login, [], '192.0.2.5'))->toBeFalse()
        // Not for another login, nor for the staff area.
        ->and(SignInSecurity::trusted($world->di, 'client', 'user', 7))->toBeFalse()
        ->and(SignInSecurity::trusted($world->di, 'staff', 'client', 7))->toBeFalse();

    $world->db->executeStatement("UPDATE client SET pass = 'a-new-hash' WHERE id = 7");
    expect(SignInSecurity::trusted($world->di, 'client', 'client', 7))->toBeFalse();

    $world->cookies = ['clientfence_trust_client' => 'forged.' . str_repeat('0', 64)];
    expect(SignInSecurity::trusted($world->di, 'client', 'client', 7))->toBeFalse();
});

test('a pending sign-in expires when its password changes', function (string $factor, string $kind, int $id): void {
    $world = new SignInSecurityTestWorld();
    $world->db->insert('client_user', ['id' => 8, 'pass' => 'hash-user', 'email' => 'user@example.test']);
    $area = $kind === 'staff' ? 'staff' : 'client';
    $user = $kind === 'user' ? ['user_id' => $id, 'client_id' => 7, 'version' => 1] : null;
    if ($factor === 'pin') {
        $world->save(['pin' => 'both']);
        $world->save(['pin' => 'both', 'confirm_pin' => $world->lastPin()]);
        SignInSecurity::challenge($world->di, $area, ['kind' => $kind, 'id' => $id, 'email' => 'login@example.test'], [], '192.0.2.5');
        $key = SignInSecurity::PENDING;
        $complete = fn () => SignInSecurity::verify($world->di, $area, $world->lastPin(), true, '192.0.2.5');
    } else {
        $world->db->executeStatement('CREATE TABLE client_twofactor (kind TEXT, subject_id INTEGER)');
        $world->db->insert('client_twofactor', ['kind' => $kind, 'subject_id' => $id]);
        ClientFence\Mod\Client\TwoFactor::challenge($world->di, 7, $user);
        $key = ClientFence\Mod\Client\TwoFactor::PENDING;
        // Disabling the factor meanwhile must not bypass the password check.
        $world->db->delete('client_twofactor', ['kind' => $kind, 'subject_id' => $id]);
        $complete = fn () => ClientFence\Mod\Client\TwoFactor::verify($world->di, '123456', '192.0.2.5');
    }
    $table = ['staff' => 'admin', 'client' => 'client', 'user' => 'client_user'][$kind];
    expect($world->session[$key]['password_fingerprint'])->toBe(hash('sha256', (string) $world->db->fetchOne("SELECT pass FROM {$table} WHERE id = ?", [$id])));
    $world->db->update($table, ['pass' => 'changed-password-hash'], ['id' => $id]);

    expect($complete)->toThrow(InformationException::class, 'Your sign-in has expired. Please sign in again.')
        ->and($world->session)->not->toHaveKey($key)
        ->and($world->session)->not->toHaveKey('client_id')
        ->and($world->session)->not->toHaveKey('admin')
        ->and($world->queued)->toBe([]);
})->with([
    'staff email PIN' => ['pin', 'staff', 1],
    'client email PIN' => ['pin', 'client', 7],
    'user email PIN' => ['pin', 'user', 8],
    'client authenticator' => ['totp', 'client', 7],
    'user authenticator' => ['totp', 'user', 8],
]);

test('the console command turns sign-in security off', function (): void {
    $world = new SignInSecurityTestWorld();
    $world->save(['pin' => 'clients', 'countries_on' => '1', 'countries' => ['DE']]);

    expect(SignInSecurity::turnOff($world->di, false, true))->toBe(['pin' => 'clients', 'countries_on' => false, 'countries' => ['DE']])
        ->and(SignInSecurity::turnOff($world->di, true, false)['pin'])->toBe('off');
});

test('the setup wizard sets the staff side only and saves nothing until the PIN is confirmed', function (): void {
    $world = new SignInSecurityTestWorld();
    expect($world->save(['pin' => 'clients']))->toBe(['saved' => true]);
    $admin = ['id' => 1, 'email' => 'owner@example.test', 'name' => 'Owner', 'timezone' => ''];
    $wizard = ['captcha_provider' => 'none', 'admin_path' => '', 'staff_pin' => 1, 'countries_on' => 1, 'countries' => ['DE']];

    expect(Setup::saveSecurity($world->di, $wizard, $admin, '198.51.100.1'))->toBe(['admin_path' => null, 'confirm' => true, 'email' => 'o••••@example.test'])
        ->and(SignInSecurity::settings($world->di))->toBe(['pin' => 'clients', 'countries_on' => false, 'countries' => []]);

    expect(Setup::saveSecurity($world->di, $wizard + ['confirm_pin' => $world->lastPin()], $admin, '198.51.100.1'))->toBe(['admin_path' => null])
        ->and(SignInSecurity::settings($world->di))->toBe(['pin' => 'both', 'countries_on' => true, 'countries' => ['DE']]);

    // No country fields (no GeoIP database): the country rule stays; the client PIN always does.
    Setup::saveSecurity($world->di, ['captcha_provider' => 'none', 'staff_pin' => 0], $admin, '198.51.100.1');
    expect(SignInSecurity::settings($world->di))->toBe(['pin' => 'clients', 'countries_on' => true, 'countries' => ['DE']])
        ->and(fn () => Setup::saveSecurity($world->di, ['captcha_provider' => 'none', 'countries_on' => 1, 'countries' => ['US']], $admin, '198.51.100.1'))
        ->toThrow(InformationException::class, 'would lock you out');

    // Switched off, the rule keeps its list for next time.
    Setup::saveSecurity($world->di, ['captcha_provider' => 'none', 'countries_on' => 0], $admin, '198.51.100.1');
    expect(SignInSecurity::settings($world->di))->toBe(['pin' => 'clients', 'countries_on' => false, 'countries' => ['DE']]);
});
