<?php

declare(strict_types=1);

/**
 * ClientFence: sign-in security (Mod\Clientfence\SignInSecurity): the settings and their lockout
 * checks, staff sign-in from chosen countries, the email PIN and trusted browsers, on an in-memory
 * SQLite database with fake services. APP_ENV is "test", so PINs are rendered but not sent. Also the
 * sign-in history (Mod\Clientfence\SignInHistory) and the PIN only from a new place: the networks come
 * from the bundled GeoIP ASN database (8.8.8.8 and 1.1.1.1, two networks), the countries from the fake.
 * Also two-factor sign-in required of clients (Mod\Client\TwoFactor::checkRequired()).
 */

use ClientFence\InformationException;
use ClientFence\Mod\Client\TwoFactor;
use ClientFence\Mod\Client\Users;
use ClientFence\Mod\Clientfence\Setup;
use ClientFence\Mod\Clientfence\SignInHistory;
use ClientFence\Mod\Clientfence\SignInSecurity;
use Symfony\Component\HttpFoundation\Request;

final class SignInSecurityTestWorld
{
    public array $session = [];
    public array $pins = [];
    public array $cookies = [];
    public array $queued = [];
    public array $sent = [];
    // Documentation addresses stand in for public ones here; 192.168.1.5 is private (the bundled
    // database gives it a country all the same).
    public array $countries = ['198.51.100.1' => 'DE', '203.0.113.9' => 'US', '192.0.2.5' => 'EG', '192.168.1.5' => 'AU', '8.8.8.8' => 'US', '1.1.1.1' => 'US'];
    public string $sessionId = 'session-now';
    public string $agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
    public array $staffConfig = [];
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
        $db->executeStatement('CREATE TABLE activity_client_history (id INTEGER PRIMARY KEY, client_id INTEGER, ip TEXT, created_at TEXT, user_id INTEGER, country TEXT, asn INTEGER, network TEXT, browser TEXT, session_id TEXT)');
        $db->executeStatement('CREATE TABLE activity_admin_history (id INTEGER PRIMARY KEY, admin_id INTEGER, ip TEXT, created_at TEXT, country TEXT, asn INTEGER, network TEXT, browser TEXT, session_id TEXT)');
        $db->executeStatement('CREATE TABLE session (id TEXT PRIMARY KEY, modified_at INTEGER, created_at INTEGER, content TEXT, fingerprint TEXT)');
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

            public function getId(): string
            {
                return $this->world->sessionId;
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
                return $this->name === 'staff' ? $this->world->staffConfig : ['from_email' => 'billing@example.test', 'mailer' => 'sendmail'];
            }
        });
        $extension = new class($world) {
            public function __construct(private readonly SignInSecurityTestWorld $world)
            {
            }

            public function getConfig(string $ext): array
            {
                return $this->world->staffConfig;
            }

            public function setConfig(array $data): bool
            {
                $this->world->staffConfig = $data;

                return true;
            }
        };
        $email = new class($world) {
            public function __construct(private readonly SignInSecurityTestWorld $world)
            {
            }

            public function renderTemplate(string $code, array $vars, array $preview = [], ?string $timezone = null, ?string $locale = null): array
            {
                $this->world->pins[] = ['code' => $code, 'locale' => $locale] + $vars;

                return ['Your PIN', '<p>' . $vars['pin'] . '</p>'];
            }

            public function recipientLocale(?string $lang): string
            {
                return $lang ?? 'en_US';
            }

            public function sendTemplate(array $data): bool
            {
                $this->world->sent[] = $data;

                return true;
            }
        };
        $system = new class {
            public function getParamValue(string $name, $default = null)
            {
                return $name === 'company_name' ? 'Example' : $default;
            }
        };
        $di['mod_service'] = $di->protect(fn (string $name) => match ($name) {
            'email' => $email,
            'extension' => $extension,
            'profile' => new ClientFence\Mod\Profile\Service(),
            default => $system,
        });
        $di['request'] = $di->factory(fn () => new Request([], [], [], $world->cookies, [], ['HTTP_USER_AGENT' => $world->agent]));
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

    expect(SignInSecurity::settings($world->di))->toBe(['pin' => 'off', 'countries_on' => false, 'countries' => [], 'ips_on' => false, 'ips' => [], 'staff_reset' => true, 'pin_when' => 'every', 'history' => false, 'twofactor' => 'off', 'twofactor_groups' => []])
        ->and(SignInSecurity::pinOn($world->di, 'staff'))->toBeFalse()
        ->and(SignInSecurity::challenge($world->di, 'client', ['kind' => 'client', 'id' => 7, 'email' => 'c@example.test'], [], '192.0.2.5'))->toBeFalse()
        ->and($world->pins)->toBe([]);
    SignInSecurity::checkStaffCountry($world->di, '192.0.2.99');
});

test('settings that would lock the saving admin out are refused', function (array $data, string $ip, string $message): void {
    $world = new SignInSecurityTestWorld();

    expect(fn () => $world->save($data, $ip))->toThrow(InformationException::class, $message)
        ->and(SignInSecurity::settings($world->di)['countries_on'])->toBeFalse()
        ->and($world->staffConfig)->toBe([])
        ->and($world->pins)->toBe([]);
})->with([
    'an unknown mode' => [['pin' => 'everyone'], '198.51.100.1', 'Choose who is asked'],
    'an unknown country' => [['countries_on' => 1, 'countries' => ['XX']], '198.51.100.1', 'Unknown country code'],
    'no country' => [['countries_on' => 1, 'countries' => []], '198.51.100.1', 'at least one country'],
    'not your own country' => [['countries_on' => 1, 'countries' => ['US']], '198.51.100.1', 'would lock you out'],
    'an address of no known country' => [['countries_on' => 1, 'countries' => ['DE']], '10.0.0.8', 'is not known'],
    'an invalid IP address' => [['ips_on' => 0, 'ips' => ['not-an-address']], '198.51.100.1', 'Not an IP address: not-an-address'],
    'no IP address' => [['ips_on' => 1, 'ips' => " , \n"], '198.51.100.1', 'Add at least one IP address'],
    'not your own IP address' => [['pin' => 'staff', 'ips_on' => 1, 'ips' => ['203.0.113.9']], '198.51.100.1', 'would lock you out'],
]);

test('staff IP settings use the stored switch and non-empty trimmed lines', function (array $config, bool $on, array $ips): void {
    $world = new SignInSecurityTestWorld();
    $world->staffConfig = $config;

    expect(SignInSecurity::settings($world->di))->toMatchArray(['ips_on' => $on, 'ips' => $ips]);
})->with([
    'on with a list' => [['check_ip' => 1, 'allowed_ips' => " 198.51.100.1 \r\n\n 2001:db8::1 \n"], true, ['198.51.100.1', '2001:db8::1']],
    'off with a list' => [['check_ip' => 0, 'allowed_ips' => '198.51.100.1'], false, ['198.51.100.1']],
    'on without a list' => [['check_ip' => 1, 'allowed_ips' => " \r\n\n"], false, []],
]);

test('saving staff IPs uses the new list and keeps other staff settings', function (array|string $ips): void {
    $world = new SignInSecurityTestWorld();
    $world->staffConfig = ['check_ip' => 0, 'allowed_ips' => '192.0.2.5', 'login_page_show_remember' => 1];
    $list = ['198.51.100.1', '203.0.113.9', '2001:db8::1'];

    expect($world->save(['ips_on' => 1, 'ips' => $ips, 'countries_on' => 1, 'countries' => ['US']]))->toBe(['saved' => true])
        ->and($world->staffConfig)->toBe(['check_ip' => 1, 'allowed_ips' => implode(PHP_EOL, $list), 'login_page_show_remember' => 1, 'ext' => 'mod_staff'])
        ->and(SignInSecurity::settings($world->di))->toMatchArray(['ips_on' => true, 'ips' => $list]);
})->with([
    'text' => [" 198.51.100.1,203.0.113.9\r\n198.51.100.1\t2001:db8::1 \n"],
    'array' => [[' 198.51.100.1 ', '203.0.113.9', '198.51.100.1', '', '2001:db8::1']],
]);

test('turning staff IPs off checks the country rule without the stored exemption', function (): void {
    $world = new SignInSecurityTestWorld();
    $world->staffConfig = ['check_ip' => 1, 'allowed_ips' => '198.51.100.1'];

    expect(fn () => $world->save(['ips_on' => 0, 'ips' => ['198.51.100.1'], 'countries_on' => 1, 'countries' => ['US']]))
        ->toThrow(InformationException::class, 'would lock you out')
        ->and($world->staffConfig['check_ip'])->toBe(1);
});

test('a save without the IP switch leaves the staff config untouched', function (): void {
    $world = new SignInSecurityTestWorld();
    $config = ['check_ip' => 1, 'allowed_ips' => '198.51.100.1', 'login_page_show_remember' => 1];
    $world->staffConfig = $config;

    expect($world->save(['pin' => 'clients']))->toBe(['saved' => true])
        ->and($world->staffConfig)->toBe($config);
});

test('staff password reset is on unless saved off, and saving it keeps the other staff settings', function (): void {
    $world = new SignInSecurityTestWorld();
    $world->staffConfig = ['check_ip' => 1, 'allowed_ips' => '198.51.100.1', 'public' => ['other' => 'x']];

    expect(SignInSecurity::settings($world->di)['staff_reset'])->toBeTrue()
        ->and($world->save(['staff_reset' => '0']))->toBe(['saved' => true])
        ->and($world->staffConfig)->toBe(['check_ip' => 1, 'allowed_ips' => '198.51.100.1', 'public' => ['reset_pw' => '0', 'other' => 'x'], 'ext' => 'mod_staff'])
        ->and(SignInSecurity::settings($world->di)['staff_reset'])->toBeFalse();
});

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

    $world->staffConfig['allowed_ips'] = "10.0.0.8\n203.0.113.9";
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
        if ($kind === 'staff') {
            ClientFence\Mod\Client\TwoFactor::challengeStaff($world->di, $id);
            $key = ClientFence\Mod\Client\TwoFactor::STAFF_PENDING;
            $complete = fn () => ClientFence\Mod\Client\TwoFactor::verifyStaff($world->di, '123456', '192.0.2.5');
        } else {
            ClientFence\Mod\Client\TwoFactor::challenge($world->di, 7, $user);
            $key = ClientFence\Mod\Client\TwoFactor::PENDING;
            $complete = fn () => ClientFence\Mod\Client\TwoFactor::verify($world->di, '123456', '192.0.2.5');
        }
        // Disabling the factor meanwhile must not bypass the password check.
        $world->db->delete('client_twofactor', ['kind' => $kind, 'subject_id' => $id]);
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
    'staff authenticator' => ['totp', 'staff', 1],
]);

test('staff with the authenticator app sign in with its code, or switch to the email PIN and back', function (): void {
    $world = new SignInSecurityTestWorld();
    $world->di['crypt'] = new class {
        public function decrypt(string $value): string
        {
            return $value;
        }
    };
    $world->db->executeStatement('CREATE TABLE client_twofactor (id INTEGER PRIMARY KEY, kind TEXT, subject_id INTEGER, secret TEXT, backup_hash TEXT, last_step INTEGER)');
    $world->db->insert('client_twofactor', ['id' => 1, 'kind' => 'staff', 'subject_id' => 1, 'secret' => 'JBSWY3DPEHPK3PXP', 'backup_hash' => null, 'last_step' => 0]);
    $login = ['kind' => 'staff', 'id' => 1, 'email' => 'owner@example.test', 'name' => 'Owner'];

    // The staff PIN setting is off: "Email me a PIN instead" still sends one, and only one step waits.
    expect(TwoFactor::challengeStaff($world->di, 1))->toBeTrue()
        ->and(SignInSecurity::challenge($world->di, 'staff', $login, [], '192.0.2.5'))->toBeFalse()
        ->and(SignInSecurity::challenge($world->di, 'staff', $login, [], '192.0.2.5', true))->toBeTrue()
        ->and(TwoFactor::staffPending($world->di))->toBeNull()
        ->and(TwoFactor::challengeStaff($world->di, 1))->toBeTrue()
        ->and(SignInSecurity::pending($world->di, 'staff'))->toBeNull();

    $code = TwoFactor::code(TwoFactor::base32Decode('JBSWY3DPEHPK3PXP'), intdiv(time(), 30));
    $wrong = $code === '000000' ? '111111' : '000000';
    expect(fn () => TwoFactor::verifyStaff($world->di, $wrong, '192.0.2.5'))->toThrow(InformationException::class, 'not correct')
        ->and(TwoFactor::verifyStaff($world->di, $code, '192.0.2.5'))->toBe(['id' => 1, 'backup_used' => false, 'off' => false])
        ->and(TwoFactor::staffPending($world->di))->toBeNull()
        ->and(TwoFactor::isOn($world->di, 'client', 1))->toBeFalse();

    // Switched off while a sign-in waits: any code passes, flagged `off` so the caller asks for the usual step.
    TwoFactor::challengeStaff($world->di, 1);
    $world->db->delete('client_twofactor', ['kind' => 'staff', 'subject_id' => 1]);
    expect(TwoFactor::verifyStaff($world->di, 'x', '192.0.2.5'))->toBe(['id' => 1, 'backup_used' => false, 'off' => true]);
});

test('the console command turns sign-in security off', function (): void {
    $world = new SignInSecurityTestWorld();
    $world->save(['pin' => 'clients', 'countries_on' => '1', 'countries' => ['DE'], 'ips_on' => 1, 'ips' => ['198.51.100.1']]);

    expect(SignInSecurity::turnOff($world->di, false, false, true, false))->toBe(['pin' => 'clients', 'countries_on' => true, 'countries' => ['DE'], 'ips_on' => false, 'ips' => ['198.51.100.1'], 'staff_reset' => true, 'pin_when' => 'every', 'history' => false, 'twofactor' => 'off', 'twofactor_groups' => []])
        ->and($world->staffConfig['check_ip'])->toBe(0)
        ->and($world->staffConfig['allowed_ips'])->toBe('198.51.100.1')
        ->and(SignInSecurity::turnOff($world->di, false, true, false, false))->toBe(['pin' => 'clients', 'countries_on' => false, 'countries' => ['DE'], 'ips_on' => false, 'ips' => ['198.51.100.1'], 'staff_reset' => true, 'pin_when' => 'every', 'history' => false, 'twofactor' => 'off', 'twofactor_groups' => []])
        ->and(SignInSecurity::turnOff($world->di, true, false, false, false)['pin'])->toBe('off');
});

test('the setup wizard sets the staff side only and saves nothing until the PIN is confirmed', function (): void {
    $world = new SignInSecurityTestWorld();
    expect($world->save(['pin' => 'clients']))->toBe(['saved' => true]);
    $admin = ['id' => 1, 'email' => 'owner@example.test', 'name' => 'Owner', 'timezone' => ''];
    $wizard = ['captcha_provider' => 'none', 'admin_path' => '', 'staff_pin' => 1, 'countries_on' => 1, 'countries' => ['DE']];

    expect(Setup::saveSecurity($world->di, $wizard, $admin, '198.51.100.1'))->toBe(['admin_path' => null, 'confirm' => true, 'email' => 'o••••@example.test'])
        ->and(SignInSecurity::settings($world->di))->toBe(['pin' => 'clients', 'countries_on' => false, 'countries' => [], 'ips_on' => false, 'ips' => [], 'staff_reset' => true, 'pin_when' => 'every', 'history' => false, 'twofactor' => 'off', 'twofactor_groups' => []]);

    expect(Setup::saveSecurity($world->di, $wizard + ['confirm_pin' => $world->lastPin()], $admin, '198.51.100.1'))->toBe(['admin_path' => null])
        ->and(SignInSecurity::settings($world->di))->toBe(['pin' => 'both', 'countries_on' => true, 'countries' => ['DE'], 'ips_on' => false, 'ips' => [], 'staff_reset' => true, 'pin_when' => 'every', 'history' => false, 'twofactor' => 'off', 'twofactor_groups' => []]);

    // No country fields (no GeoIP database): the country rule stays; the client PIN always does.
    Setup::saveSecurity($world->di, ['captcha_provider' => 'none', 'staff_pin' => 0], $admin, '198.51.100.1');
    expect(SignInSecurity::settings($world->di))->toBe(['pin' => 'clients', 'countries_on' => true, 'countries' => ['DE'], 'ips_on' => false, 'ips' => [], 'staff_reset' => true, 'pin_when' => 'every', 'history' => false, 'twofactor' => 'off', 'twofactor_groups' => []])
        ->and(fn () => Setup::saveSecurity($world->di, ['captcha_provider' => 'none', 'countries_on' => 1, 'countries' => ['US']], $admin, '198.51.100.1'))
        ->toThrow(InformationException::class, 'would lock you out');

    // Switched off, the rule keeps its list for next time.
    Setup::saveSecurity($world->di, ['captcha_provider' => 'none', 'countries_on' => 0], $admin, '198.51.100.1');
    expect(SignInSecurity::settings($world->di))->toBe(['pin' => 'clients', 'countries_on' => false, 'countries' => ['DE'], 'ips_on' => false, 'ips' => [], 'staff_reset' => true, 'pin_when' => 'every', 'history' => false, 'twofactor' => 'off', 'twofactor_groups' => []]);
});

test('a client\'s PIN email is in the client\'s language, also when resent; the staff PIN in the default', function (): void {
    $world = new SignInSecurityTestWorld();
    $world->save(['pin' => 'clients']);
    SignInSecurity::challenge($world->di, 'client', ['kind' => 'client', 'id' => 7, 'email' => 'client@example.test', 'lang' => 'de_DE'], [], '192.0.2.5');
    $world->session[SignInSecurity::PENDING]['sent_at'] -= SignInSecurity::RESEND_SECONDS;
    SignInSecurity::resend($world->di, 'client', '192.0.2.5');
    $staff = new SignInSecurityTestWorld();
    $staff->save(['pin' => 'staff']);

    expect(array_column($world->pins, 'locale'))->toBe(['de_DE', 'de_DE'])
        ->and($staff->pins[0])->toMatchArray(['code' => 'mod_staff_clientfence_signin_pin', 'locale' => 'en_US']);
});

// ----- sign-in history and the PIN only from a new place -----------------------------------------

afterEach(function (): void {
    // A test that stops between record() and started() must not hand its row to the next one.
    (new ReflectionProperty(SignInHistory::class, 'row'))->setValue(null, null);
});

test('"When to ask" and the sign-in history are saved, checked and kept by saves that leave them out', function (): void {
    $world = new SignInSecurityTestWorld();

    expect(fn () => $world->save(['pin' => 'clients', 'pin_when' => 'sometimes']))->toThrow(InformationException::class, 'Choose when to ask')
        ->and($world->save(['pin' => 'clients', 'pin_when' => 'new', 'history' => '1']))->toBe(['saved' => true])
        ->and(SignInSecurity::settings($world->di))->toMatchArray(['pin' => 'clients', 'pin_when' => 'new', 'history' => true])
        // Quick setup and older forms send neither.
        ->and($world->save(['pin' => 'clients']))->toBe(['saved' => true])
        ->and(SignInSecurity::settings($world->di))->toMatchArray(['pin_when' => 'new', 'history' => true])
        ->and($world->save(['pin' => 'clients', 'history' => '0']))->toBe(['saved' => true])
        ->and(SignInSecurity::settings($world->di)['history'])->toBeFalse()
        // The console command puts the PIN back to every sign-in and leaves the history alone.
        ->and($world->save(['pin' => 'clients', 'history' => '1']))->toBe(['saved' => true])
        ->and(SignInSecurity::turnOff($world->di, true, false, false, false))->toMatchArray(['pin' => 'off', 'pin_when' => 'every', 'history' => true]);
});

test('the browser label names the browser and the system', function (string $agent, ?string $label): void {
    expect(SignInHistory::browser($agent))->toBe($label);
})->with([
    'Chrome on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36', 'Chrome on Windows'],
    'Edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0', 'Edge on Windows'],
    'Opera' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 OPR/115.0.0.0', 'Opera on macOS'],
    'Samsung Internet' => ['Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36', 'Samsung Internet on Android'],
    'Firefox on Linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:133.0) Gecko/20100101 Firefox/133.0', 'Firefox on Linux'],
    'Firefox on Android' => ['Mozilla/5.0 (Android 14; Mobile; rv:133.0) Gecko/133.0 Firefox/133.0', 'Firefox on Android'],
    'Safari on iOS' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1', 'Safari on iOS'],
    'Chrome on iOS' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/131.0.6778.73 Mobile/15E148 Safari/604.1', 'Chrome on iOS'],
    'Safari on macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15', 'Safari on macOS'],
    'ChromeOS' => ['Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36', 'Chrome on ChromeOS'],
    'an unknown browser' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) SomeApp/1.0', 'Windows'],
    'neither' => ['curl/8.5.0', null],
    'no agent' => ['', null],
]);

test('the history row is FOSSBilling\'s own while both are off, and has the details and session while one is on', function (array $saved, bool $details): void {
    $world = new SignInSecurityTestWorld();
    $world->save(['pin' => 'off'] + $saved);
    $world->db->insert('client_user', ['id' => 8, 'pass' => 'hash-user', 'email' => 'user@example.test']);

    SignInHistory::record($world->di, 'activity_client_history', ['client_id' => 7, 'ip' => '8.8.8.8', 'created_at' => date('Y-m-d H:i:s')]);
    SignInHistory::started($world->di, 8);
    SignInHistory::record($world->di, 'activity_admin_history', ['admin_id' => 1, 'ip' => '192.168.1.5', 'created_at' => date('Y-m-d H:i:s')]);
    SignInHistory::started($world->di, null);
    $client = $world->db->fetchAssociative('SELECT user_id, country, asn, network, browser, session_id FROM activity_client_history');
    $staff = $world->db->fetchAssociative('SELECT country, asn, network, browser, session_id FROM activity_admin_history');

    if (!$details) {
        // Off: only the authorized user (its sign-ins must never count as the owner's places for the PIN).
        expect($client)->toBe(['user_id' => 8, 'country' => null, 'asn' => null, 'network' => null, 'browser' => null, 'session_id' => null])
            ->and($staff)->toBe(['country' => null, 'asn' => null, 'network' => null, 'browser' => null, 'session_id' => null]);

        return;
    }
    expect($client)->toMatchArray(['user_id' => 8, 'country' => 'US', 'browser' => 'Chrome on Windows', 'session_id' => 'session-now'])
        ->and($client['asn'])->toBeInt()
        ->and($client['network'])->not->toBeEmpty()
        // A private address: looked up (country ''), no network.
        ->and($staff)->toBe(['country' => '', 'asn' => null, 'network' => null, 'browser' => 'Chrome on Windows', 'session_id' => 'session-now']);
})->with([
    'both off' => [[], false],
    'sign-in history on' => [['history' => '1'], true],
    'PIN only from a new place' => [['pin_when' => 'new'], true],
]);

test('with the PIN only from a new place, a known country and network signs in without one', function (string $when, string $ip, bool $asked): void {
    $world = new SignInSecurityTestWorld();
    $google = SignInHistory::locate($world->di, '8.8.8.8');
    expect($google['asn'])->toBeInt()->not->toBe(SignInHistory::locate($world->di, '1.1.1.1')['asn']);
    $world->save(['pin' => 'clients', 'pin_when' => $when]);
    $world->db->insert('activity_client_history', ['client_id' => 7, 'ip' => '8.8.4.4', 'created_at' => date('Y-m-d H:i:s', time() - 86400)] + $google);
    // The same place long ago, or for another login, does not count.
    $world->db->insert('activity_client_history', ['client_id' => 7, 'ip' => '1.1.1.1', 'created_at' => date('Y-m-d H:i:s', time() - 100 * 86400), 'country' => 'US', 'asn' => SignInHistory::locate($world->di, '1.1.1.1')['asn']]);
    $world->db->insert('activity_client_history', ['client_id' => 9, 'ip' => '1.1.1.1', 'created_at' => date('Y-m-d H:i:s'), 'country' => 'US', 'asn' => SignInHistory::locate($world->di, '1.1.1.1')['asn']]);

    expect(SignInSecurity::challenge($world->di, 'client', ['kind' => 'client', 'id' => 7, 'email' => 'client@example.test'], [], $ip))->toBe($asked)
        ->and($world->pins)->toHaveCount($asked ? 1 : 0);
})->with([
    'known place' => ['new', '8.8.8.8', false],
    'same country, new network' => ['new', '1.1.1.1', true],
    'unknown country' => ['new', '9.9.9.9', true],
    'private address' => ['new', '192.168.1.5', true],
    'every sign-in' => ['every', '8.8.8.8', true],
]);

test('sign-ins logged before details were kept are learned at the next sign-in', function (): void {
    $world = new SignInSecurityTestWorld();
    $world->save(['pin' => 'clients', 'pin_when' => 'new']);
    $world->db->insert('activity_client_history', ['id' => 1, 'client_id' => 7, 'ip' => '8.8.8.8', 'created_at' => date('Y-m-d H:i:s', time() - 86400)]);
    $world->db->insert('activity_client_history', ['id' => 2, 'client_id' => 7, 'ip' => '10.0.0.8', 'created_at' => date('Y-m-d H:i:s', time() - 86400)]);
    $login = ['kind' => 'client', 'id' => 7, 'email' => 'client@example.test'];

    expect(SignInSecurity::challenge($world->di, 'client', $login, [], '8.8.8.8'))->toBeFalse()
        ->and($world->db->fetchAllKeyValue('SELECT id, country FROM activity_client_history ORDER BY id'))->toBe([1 => 'US', 2 => ''])
        ->and((int) $world->db->fetchOne('SELECT asn FROM activity_client_history WHERE id = 1'))->toBe(SignInHistory::locate($world->di, '8.8.8.8')['asn'])
        // An authorized user does not sign in from the owner's places.
        ->and(SignInSecurity::challenge($world->di, 'client', ['kind' => 'user', 'id' => 8, 'email' => 'user@example.test'], [], '8.8.8.8'))->toBeTrue();
});

test('the Sign-ins tab lists the login\'s own sign-ins, and Sign out ends only one of them that is not this one', function (): void {
    $world = new SignInSecurityTestWorld();
    $now = date('Y-m-d H:i:s');
    foreach ([
        1 => ['client_id' => 7, 'session_id' => 'session-old'],
        2 => ['client_id' => 7, 'session_id' => 'session-phone', 'browser' => 'Safari on iOS', 'country' => 'DE'],
        3 => ['client_id' => 7, 'session_id' => 'session-now', 'browser' => 'Chrome on Windows', 'country' => ''],
        4 => ['client_id' => 7, 'user_id' => 8, 'session_id' => 'session-user'],
        5 => ['client_id' => 7],
        // Signed out: the browser kept its session id as a guest.
        9 => ['client_id' => 7, 'session_id' => 'session-guest'],
    ] as $id => $row) {
        $world->db->insert('activity_client_history', ['id' => $id, 'ip' => '198.51.100.1', 'created_at' => $now] + $row);
    }
    $world->db->insert('activity_admin_history', ['id' => 6, 'admin_id' => 1, 'ip' => '198.51.100.1', 'created_at' => $now, 'session_id' => 'session-staff']);
    $client = base64_encode('client_id|i:7;');
    foreach (['session-phone' => $client, 'session-now' => $client, 'session-user' => base64_encode('client_id|i:9;clientfence_user|a:1:{s:7:"user_id";i:8;}'), 'session-staff' => base64_encode('admin|a:1:{s:2:"id";i:1;}'), 'session-guest' => base64_encode('cart|i:0;')] as $session => $content) {
        $world->db->insert('session', ['id' => $session, 'created_at' => time() - 60, 'content' => $content]);
    }

    expect(SignInHistory::recent($world->di, 'client', 7))->toBeNull();
    $world->save(['history' => '1']);
    $list = SignInHistory::recent($world->di, 'client', 7);
    expect(array_map(static fn (array $row): array => [$row['id'], $row['current'], $row['active']], $list))->toBe([[9, false, false], [3, true, true], [2, false, true], [1, false, false]])
        ->and($list[2])->toMatchArray(['country' => 'Germany', 'browser' => 'Safari on iOS'])
        ->and($list[1]['country'])->toBeNull()
        ->and((string) json_encode($list))->not->toContain('session-')
        // Still active after switching to another of its accounts.
        ->and(array_map(static fn (array $row): array => [$row['id'], $row['active']], SignInHistory::recent($world->di, 'user', 8)))->toBe([[4, true]])
        ->and(array_column(SignInHistory::recent($world->di, 'staff', 1), 'id'))->toBe([6])
        ->and(SignInHistory::recent($world->di, 'staff', 1)[0]['active'])->toBeTrue();

    // Another login's row, by id, is not found, whoever asks; this session is not ended here.
    foreach ([['client', 7, 4], ['user', 8, 2], ['client', 7, 6], ['staff', 1, 2], ['client', 7, 3]] as [$kind, $id, $row]) {
        expect(fn () => SignInHistory::end($world->di, $kind, $id, $row))->toThrow(InformationException::class);
    }
    expect($world->db->fetchFirstColumn('SELECT id FROM session ORDER BY id'))->toBe(['session-guest', 'session-now', 'session-phone', 'session-staff', 'session-user']);

    SignInHistory::end($world->di, 'client', 7, 2);
    expect($world->db->fetchFirstColumn('SELECT id FROM session ORDER BY id'))->toBe(['session-guest', 'session-now', 'session-staff', 'session-user']);
});

test('a new session id takes along the sign-in rows of the logins still signed in, not those signed out', function (bool $on): void {
    $world = new SignInSecurityTestWorld();
    $world->save($on ? ['history' => '1'] : []);
    $now = date('Y-m-d H:i:s');
    foreach ([1 => ['client_id' => 7], 2 => ['client_id' => 7, 'user_id' => 8], 3 => ['client_id' => 5]] as $id => $row) {
        $world->db->insert('activity_client_history', ['id' => $id, 'created_at' => $now, 'session_id' => 'old'] + $row);
    }
    $world->db->insert('activity_admin_history', ['id' => 1, 'admin_id' => 1, 'created_at' => $now, 'session_id' => 'old']);

    // Staff and authorized user 8 of client 7 still signed in (a Google sign-in, a switch of account).
    SignInHistory::moved($world->di, 'old', 'new', ['admin' => ['id' => 1], 'client_id' => 7, 'clientfence_user' => ['user_id' => 8]]);

    expect($world->db->fetchAllKeyValue('SELECT id, session_id FROM activity_client_history ORDER BY id'))->toBe($on ? [1 => 'old', 2 => 'new', 3 => 'old'] : [1 => 'old', 2 => 'old', 3 => 'old'])
        ->and($world->db->fetchOne('SELECT session_id FROM activity_admin_history'))->toBe($on ? 'new' : 'old');

    // The user switched again, to account 9: its row (account 7) still follows.
    SignInHistory::moved($world->di, 'new', 'third', ['client_id' => 9, 'clientfence_user' => ['user_id' => 8]]);
    expect($world->db->fetchOne('SELECT session_id FROM activity_client_history WHERE id = 2'))->toBe($on ? 'third' : 'old');

    // The account owner signed in (no authorized user); a signed-out browser moves nothing.
    SignInHistory::moved($world->di, 'old', 'newer', ['client_id' => 7]);
    SignInHistory::moved($world->di, 'old', 'guest', ['cart' => 0]);
    expect($world->db->fetchOne('SELECT session_id FROM activity_client_history WHERE id = 1'))->toBe($on ? 'newer' : 'old')
        ->and((int) $world->db->fetchOne("SELECT COUNT(*) FROM activity_client_history WHERE session_id = 'guest'"))->toBe(0);
})->with(['on' => [true], 'off' => [false]]);

// ----- two-factor sign-in required of clients -----------------------------------------------------

/** Client 7 is in group 2 of groups 1 and 2; nobody has the authenticator app yet. */
function twofactorRequiredWorld(): SignInSecurityTestWorld
{
    $world = new SignInSecurityTestWorld();
    $world->di['dbal'] = $world->db;
    $world->db->executeStatement('CREATE TABLE client_group (id INTEGER PRIMARY KEY)');
    $world->db->executeStatement('CREATE TABLE client_group_members (client_id INTEGER, client_group_id INTEGER)');
    $world->db->executeStatement('CREATE TABLE client_twofactor (id INTEGER PRIMARY KEY, kind TEXT, subject_id INTEGER, secret TEXT, backup_hash TEXT, last_step INTEGER, enabled_at TEXT, updated_at TEXT)');
    $world->db->executeStatement('INSERT INTO client_group (id) VALUES (1), (2)');
    $world->db->insert('client_group_members', ['client_id' => 7, 'client_group_id' => 2]);

    return $world;
}

test('two-factor sign-in for clients is off, all clients or chosen groups that exist, and kept by saves without it', function (): void {
    $world = twofactorRequiredWorld();

    expect(SignInSecurity::settings($world->di))->toMatchArray(['twofactor' => 'off', 'twofactor_groups' => []])
        ->and(fn () => $world->save(['twofactor' => 'some']))->toThrow(InformationException::class, 'Choose which clients')
        ->and(fn () => $world->save(['twofactor' => 'groups', 'twofactor_groups' => ['']]))->toThrow(InformationException::class, 'at least one client group')
        ->and(fn () => $world->save(['twofactor' => 'groups', 'twofactor_groups' => ['', '3']]))->toThrow(InformationException::class, 'Client group not found')
        ->and($world->save(['twofactor' => 'groups', 'twofactor_groups' => ['', '2', '1']]))->toBe(['saved' => true])
        ->and(SignInSecurity::settings($world->di))->toMatchArray(['twofactor' => 'groups', 'twofactor_groups' => [2, 1]])
        // Quick setup sends neither.
        ->and($world->save(['pin' => 'clients']))->toBe(['saved' => true])
        ->and(SignInSecurity::settings($world->di))->toMatchArray(['twofactor' => 'groups', 'twofactor_groups' => [2, 1]]);
});

test('a sign-in in scope without the app must set it up; with the app, out of scope or off it need not', function (array $saved, ?array $user, ?string $app, bool $required): void {
    $world = twofactorRequiredWorld();
    $world->save($saved);
    if ($app !== null) {
        [$kind, $id] = explode(':', $app);
        $world->db->insert('client_twofactor', ['kind' => $kind, 'subject_id' => (int) $id]);
    }

    TwoFactor::checkRequired($world->di, 7, $user);

    expect(isset($world->session[TwoFactor::REQUIRED]))->toBe($required);
})->with([
    'all clients, no app' => [['twofactor' => 'all'], null, null, true],
    'a chosen group of the client' => [['twofactor' => 'groups', 'twofactor_groups' => ['2']], null, null, true],
    'an authorized user, only the owner has the app' => [['twofactor' => 'all'], ['user_id' => 8], 'client:7', true],
    'the app on' => [['twofactor' => 'all'], null, 'client:7', false],
    'an authorized user with the app' => [['twofactor' => 'all'], ['user_id' => 8], 'user:8', false],
    'another group' => [['twofactor' => 'groups', 'twofactor_groups' => ['1']], null, null, false],
    'off' => [[], null, null, false],
]);

test('while two-factor sign-in must be set up, only the client calls of its page are made', function (): void {
    $world = twofactorRequiredWorld();
    $world->session[TwoFactor::REQUIRED] = true;
    $identity = (object) ['id' => 7];

    expect(fn () => Users::checkCall($world->di, $identity, 'invoice', 'get_list'))->toThrow(InformationException::class, 'requires two-factor sign-in')
        ->and(fn () => Users::checkCall($world->di, $identity, 'profile', 'update'))->toThrow(InformationException::class, 'requires two-factor sign-in')
        ->and(Users::checkCall($world->di, $identity, 'client', 'twofactor_setup'))->toBeNull()
        ->and(Users::checkCall($world->di, $identity, 'client', 'twofactor_enable'))->toBeNull()
        ->and(Users::checkCall($world->di, $identity, 'profile', 'logout'))->toBeNull()
        // A Google sign-up chooses its first password, which the setup asks for.
        ->and(Users::checkCall($world->di, $identity, 'client', 'google_password'))->toBeNull();
});

test('switching the app on ends the requirement and emails the login once', function (): void {
    $world = twofactorRequiredWorld();
    foreach (['email', 'first_name', 'last_name', 'lang'] as $column) {
        $world->db->executeStatement("ALTER TABLE client ADD COLUMN {$column} TEXT");
    }
    $world->db->update('client', ['email' => 'client@example.test', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'lang' => 'de_DE'], ['id' => 7]);
    $world->di['password'] = new class {
        public function verify(string $password, string $hash): bool
        {
            return $password === 'right';
        }

        public function hashIt(string $password): string
        {
            return 'hash-' . $password;
        }
    };
    $world->di['crypt'] = new class {
        public function encrypt(string $value): string
        {
            return $value;
        }
    };
    $world->session[TwoFactor::REQUIRED] = true;
    $secret = str_replace(' ', '', TwoFactor::setup($world->di, 7)['secret']);

    TwoFactor::enable($world->di, 7, ['password' => 'right', 'code' => TwoFactor::code(TwoFactor::base32Decode($secret), intdiv(time(), 30))], '198.51.100.1');

    expect($world->session)->not->toHaveKey(TwoFactor::REQUIRED)
        ->and($world->sent)->toHaveCount(1)
        ->and($world->sent[0])->toMatchArray(['code' => 'mod_client_twofactor_switched_on', 'to' => 'client@example.test', 'to_lang' => 'de_DE', 'name' => 'Ada Lovelace', 'ip' => '198.51.100.1', 'country' => 'Germany']);
});

test('the console command also turns the required two-factor sign-in for clients off', function (): void {
    $world = twofactorRequiredWorld();
    $world->save(['pin' => 'clients', 'twofactor' => 'groups', 'twofactor_groups' => ['2']]);

    expect(SignInSecurity::turnOff($world->di, false, false, false, true))->toMatchArray(['pin' => 'clients', 'twofactor' => 'off', 'twofactor_groups' => [2]]);
});
