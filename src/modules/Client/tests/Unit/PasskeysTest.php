<?php

declare(strict_types=1);

/**
 * ClientFence: passkeys (Mod\Client\Passkeys, CF-127) on an in-memory SQLite database with fake services. The
 * "browser" is built here: an EC P-256 and an RSA key made with openssl, the authenticator data and the client
 * data signed as an authenticator signs them.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Client\Passkeys;
use ClientFence\Mod\Client\TwoFactor;
use ClientFence\Mod\Clientfence\SignInSecurity;
use Symfony\Component\HttpFoundation\Request;

final class PasskeysTestWorld
{
    public array $session = [];
    public array $sent = [];
    public Pimple\Container $di;
    public Doctrine\DBAL\Connection $db;

    public function __construct(string $mode = 'both')
    {
        $world = $this;
        $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE mod_clientfence_state (name TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $db->executeStatement('CREATE TABLE client_passkey (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, subject_id INTEGER NOT NULL, credential_id TEXT NOT NULL UNIQUE, public_key TEXT NOT NULL, alg INTEGER NOT NULL, sign_count INTEGER NOT NULL DEFAULT 0, name TEXT NOT NULL, user_handle TEXT NOT NULL, created_at TEXT NOT NULL, last_used_at TEXT, site TEXT)');
        foreach (['client', 'client_user', 'admin'] as $table) {
            $db->executeStatement("CREATE TABLE {$table} (id INTEGER PRIMARY KEY, pass TEXT, email TEXT, first_name TEXT, last_name TEXT, lang TEXT)");
        }
        $db->executeStatement('CREATE TABLE client_user_account (id INTEGER PRIMARY KEY, user_id INTEGER, client_id INTEGER)');
        $db->insert('client_user_account', ['user_id' => 8, 'client_id' => 7]);
        $db->insert('client', ['id' => 7, 'pass' => 'hash', 'email' => 'owner@example.test', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'lang' => 'de_DE']);
        $db->insert('client_user', ['id' => 8, 'pass' => 'hash', 'email' => 'user@example.test']);
        $db->insert('admin', ['id' => 1, 'pass' => 'hash', 'email' => 'staff@example.test']);
        $db->insert('mod_clientfence_state', ['name' => SignInSecurity::STATE, 'value' => json_encode(['passkeys' => $mode]), 'updated_at' => '2026-11-15 10:00:00']);
        $this->db = $db;
        $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
        $em->shouldReceive('getConnection')->andReturn($db);

        $this->di = $di = new Pimple\Container();
        $di['em'] = $em;
        $di['session'] = new class($world) {
            public function __construct(private readonly PasskeysTestWorld $world)
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
                return 'session-now';
            }
        };
        $di['request'] = $di->factory(fn () => new Request([], [], [], [], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36']));
        $di['logger'] = new class {
            public function __call(string $name, array $arguments): mixed
            {
                return $this;
            }
        };
        $di['rate_limiter'] = new class {
            public function consumeOrThrow(string $policy, string $subject): void
            {
            }
        };
        $di['password'] = new class {
            public function verify(string $password, string $hash): bool
            {
                return $password === 'right';
            }
        };
        $di['mod'] = $di->protect(fn (string $name) => new class {
            public function getConfig(): array
            {
                return [];
            }
        });
        $email = new class($world) {
            public function __construct(private readonly PasskeysTestWorld $world)
            {
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
        $di['mod_service'] = $di->protect(fn (string $name) => $name === 'email' ? $email : $system);
    }

    public function mode(string $mode): void
    {
        $this->db->update('mod_clientfence_state', ['value' => json_encode(['passkeys' => $mode])], ['name' => SignInSecurity::STATE]);
    }

    public function row(string $credentialId): array
    {
        return $this->db->fetchAssociative('SELECT * FROM client_passkey WHERE credential_id = ?', [Passkeys::b64($credentialId)]) ?: [];
    }
}

/** @return array{ec: OpenSSLAsymmetricKey, rsa: OpenSSLAsymmetricKey} made once: RSA keys are slow to make. */
function passkeyKeys(): array
{
    static $keys = null;

    return $keys ??= [
        'ec' => openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']),
        'rsa' => openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]),
    ];
}

/** What the browser sends after "create a passkey" (getPublicKey(): the key as SPKI DER). */
function passkeyCreated(PasskeysTestWorld $world, string $kind, int $id, string $credentialId, string $keyName = 'ec', array $override = []): array
{
    $options = Passkeys::createOptions($world->di, $kind, $id, ['password' => 'right'], '198.51.100.1');
    $clientData = json_encode(['type' => 'webauthn.create', 'challenge' => $options['challenge'], 'origin' => Passkeys::origin($world->di)], JSON_UNESCAPED_SLASHES);
    // rpIdHash, flags UP + UV + AT, sign count, AAGUID, the id's length, the id, then the COSE key (not read).
    $authData = hash('sha256', Passkeys::rpId($world->di), true) . chr(0x45) . pack('N', 0) . str_repeat("\0", 16) . pack('n', strlen($credentialId)) . $credentialId . "\xa5cose";
    $spki = base64_decode((string) preg_replace('/-----[^-]+-----|\s/', '', openssl_pkey_get_details(passkeyKeys()[$keyName])['key']));

    return $override + [
        'id' => Passkeys::b64($credentialId),
        'clientDataJSON' => Passkeys::b64($clientData),
        'authenticatorData' => Passkeys::b64($authData),
        'publicKey' => Passkeys::b64($spki),
        'publicKeyAlgorithm' => $keyName === 'ec' ? -7 : -257,
    ];
}

function passkeyAdd(PasskeysTestWorld $world, string $kind, int $id, string $credentialId, string $keyName = 'ec'): array
{
    return Passkeys::add($world->di, $kind, $id, $kind === 'staff' ? 0 : 7, passkeyCreated($world, $kind, $id, $credentialId, $keyName), '198.51.100.1');
}

/** What the browser sends after the sign-in prompt; $o changes one part (area, type, challenge, origin, rp, flags, count, id, key). */
function passkeyAnswer(PasskeysTestWorld $world, string $credentialId, array $o = []): array
{
    $options = Passkeys::signInOptions($world->di, $o['area'] ?? 'client');
    $clientData = json_encode(['type' => $o['type'] ?? 'webauthn.get', 'challenge' => $o['challenge'] ?? $options['challenge'], 'origin' => $o['origin'] ?? Passkeys::origin($world->di)], JSON_UNESCAPED_SLASHES);
    $authData = ($o['rp'] ?? hash('sha256', Passkeys::rpId($world->di), true)) . chr($o['flags'] ?? 0x05) . pack('N', $o['count'] ?? 0);
    openssl_sign($authData . hash('sha256', $clientData, true), $signature, passkeyKeys()[$o['key'] ?? 'ec'], OPENSSL_ALGO_SHA256);

    return ['id' => $o['id'] ?? Passkeys::b64($credentialId), 'clientDataJSON' => Passkeys::b64($clientData), 'authenticatorData' => Passkeys::b64($authData), 'signature' => Passkeys::b64($signature), 'userHandle' => ''];
}

test('a new passkey is saved for the login, ends the two-factor requirement and emails the login', function (): void {
    $world = new PasskeysTestWorld();
    $world->session[TwoFactor::REQUIRED] = true;

    expect(passkeyAdd($world, 'client', 7, 'cred-client'))->toMatchArray(['name' => 'Chrome on Windows'])
        ->and($world->row('cred-client'))->toMatchArray(['kind' => 'client', 'subject_id' => 7, 'alg' => -7, 'sign_count' => 0])
        ->and($world->session)->not->toHaveKey(TwoFactor::REQUIRED)
        ->and($world->sent)->toHaveCount(1)
        ->and($world->sent[0])->toMatchArray(['code' => 'mod_client_passkey_added', 'to' => 'owner@example.test', 'to_lang' => 'de_DE', 'passkey' => 'Chrome on Windows', 'ip' => '198.51.100.1']);
});

test('adding a passkey asks for the password, except just after the sign-in that offered one', function (): void {
    $world = new PasskeysTestWorld();

    expect(fn () => Passkeys::createOptions($world->di, 'client', 7, ['password' => 'wrong'], '198.51.100.1'))->toThrow(InformationException::class, 'password is not correct');
    Passkeys::offer($world->di, 'client', 7);
    expect(Passkeys::createOptions($world->di, 'client', 7, [], '198.51.100.1'))->toHaveKey('challenge')
        // The card shows once.
        ->and(Passkeys::takeOffer($world->di, 'client', 7))->toBeTrue()
        ->and(Passkeys::takeOffer($world->di, 'client', 7))->toBeFalse()
        // Not for another login of the same browser session.
        ->and(fn () => Passkeys::createOptions($world->di, 'user', 8, [], '198.51.100.1'))->toThrow(InformationException::class, 'password is not correct');
});

test('a browser without getPublicKey() cannot add a passkey', function (): void {
    $world = new PasskeysTestWorld();
    $answer = passkeyCreated($world, 'client', 7, 'cred-old', 'ec', ['publicKey' => '']);

    expect(fn () => Passkeys::add($world->di, 'client', 7, 7, $answer, '198.51.100.1'))->toThrow(InformationException::class, 'cannot create a passkey')
        ->and($world->row('cred-old'))->toBe([]);
});

test('a passkey signs in and its sign count is saved', function (): void {
    $world = new PasskeysTestWorld();
    passkeyAdd($world, 'client', 7, 'cred-client');

    expect(Passkeys::verifySignIn($world->di, 'client', passkeyAnswer($world, 'cred-client', ['count' => 5])))->toBe(['client', 7])
        ->and($world->row('cred-client'))->toMatchArray(['sign_count' => 5])
        ->and($world->row('cred-client')['last_used_at'])->not->toBeNull();
});

test('an RSA passkey signs a staff member in', function (): void {
    $world = new PasskeysTestWorld();
    passkeyAdd($world, 'staff', 1, 'cred-staff', 'rsa');

    expect($world->row('cred-staff'))->toMatchArray(['alg' => -257])
        ->and(Passkeys::verifySignIn($world->di, 'staff', passkeyAnswer($world, 'cred-staff', ['area' => 'staff', 'key' => 'rsa'])))->toBe(['staff', 1])
        // Staff are not emailed (the client template).
        ->and($world->sent)->toBe([]);
});

test('a passkey answer that does not fit is refused and changes nothing', function (array $change, string $message): void {
    $world = new PasskeysTestWorld();
    passkeyAdd($world, 'client', 7, 'cred-client');

    expect(fn () => Passkeys::verifySignIn($world->di, $change['area'] ?? 'client', passkeyAnswer($world, 'cred-client', $change)))->toThrow(InformationException::class, $message)
        ->and($world->row('cred-client'))->toMatchArray(['sign_count' => 0, 'last_used_at' => null]);
})->with([
    'another origin' => [['origin' => 'https://evil.example'], 'could not be checked'],
    'another site\'s rpIdHash' => [['rp' => hash('sha256', 'evil.example', true)], 'could not be checked'],
    'a registration answer' => [['type' => 'webauthn.create'], 'could not be checked'],
    'another challenge' => [['challenge' => 'c29tZS1vdGhlci1jaGFsbGVuZ2UtdGhhdC13YXMtbmV2ZXItc2VudA'], 'could not be checked'],
    'no user verification' => [['flags' => 0x01], 'could not be checked'],
    'a signature by another key' => [['key' => 'rsa'], 'could not be checked'],
    'an unknown passkey' => [['id' => 'bm90LWtub3du'], 'not set up for this sign-in'],
    'a client passkey on the staff sign-in' => [['area' => 'staff'], 'not set up for this sign-in'],
]);

test('on another site of the portal, passkeys are made for that site and only its answers sign in', function (): void {
    $world = new PasskeysTestWorld();
    $portal = ['origin' => Passkeys::origin($world->di), 'rp' => hash('sha256', Passkeys::rpId($world->di), true)];
    $world->di['site'] = new ClientFence\Site('https://shop.example.test/', 'shop.example.test', 'shop/shop');
    passkeyAdd($world, 'client', 7, 'cred-client');

    expect(Passkeys::signInOptions($world->di, 'client')['rpId'])->toBe('shop.example.test')
        ->and(Passkeys::origin($world->di))->toBe('https://shop.example.test')
        ->and(fn () => Passkeys::verifySignIn($world->di, 'client', passkeyAnswer($world, 'cred-client', ['origin' => $portal['origin']])))->toThrow(InformationException::class, 'could not be checked')
        ->and(fn () => Passkeys::verifySignIn($world->di, 'client', passkeyAnswer($world, 'cred-client', ['rp' => $portal['rp']])))->toThrow(InformationException::class, 'could not be checked')
        ->and(Passkeys::verifySignIn($world->di, 'client', passkeyAnswer($world, 'cred-client')))->toBe(['client', 7]);
});

test('a passkey works only on the site it was made on: another site does not list, exclude, accept or count it', function (): void {
    $world = new PasskeysTestWorld();
    $shop = new ClientFence\Site('https://shop.example.test/', 'shop.example.test', 'shop/shop');
    $portal = passkeyAdd($world, 'client', 7, 'cred-portal');
    passkeyAdd($world, 'staff', 1, 'cred-staff-portal');
    $world->di['site'] = $shop;
    $own = passkeyAdd($world, 'client', 7, 'cred-shop');
    $onShop = [
        Passkeys::count($world->di, 'client', 7),
        array_column(Passkeys::listFor($world->di, 'client', 7)['passkeys'], 'id'),
        array_column(Passkeys::createOptions($world->di, 'client', 7, ['password' => 'right'], '198.51.100.1')['excludeCredentials'], 'id'),
        Passkeys::count($world->di, 'staff', 1),
    ];

    expect([$world->row('cred-portal')['site'], $world->row('cred-shop')['site']])->toBe([null, 'shop/shop'])
        ->and($onShop)->toBe([1, [$own['id']], [Passkeys::b64('cred-shop')], 0])
        ->and(Passkeys::count($world->di, 'client', 7, allSites: true))->toBe(2)
        ->and(fn () => Passkeys::verifySignIn($world->di, 'client', passkeyAnswer($world, 'cred-portal')))->toThrow(InformationException::class, 'not set up for this sign-in')
        ->and(fn () => Passkeys::verifySignIn($world->di, 'staff', passkeyAnswer($world, 'cred-staff-portal', ['area' => 'staff'])))->toThrow(InformationException::class, 'not set up for this sign-in')
        ->and(fn () => Passkeys::rename($world->di, 'client', 7, $portal['id'], 'Mine'))->toThrow(InformationException::class, 'Passkey not found.')
        ->and(fn () => Passkeys::remove($world->di, 'client', 7, 7, $portal['id'], ['password' => 'right'], '198.51.100.1'))->toThrow(InformationException::class, 'Passkey not found.')
        ->and(Passkeys::verifySignIn($world->di, 'client', passkeyAnswer($world, 'cred-shop')))->toBe(['client', 7]);

    // And the reverse: the primary site does not see the passkey made on the other one.
    unset($world->di['site']);

    expect(Passkeys::count($world->di, 'client', 7))->toBe(1)
        ->and(array_column(Passkeys::listFor($world->di, 'client', 7)['passkeys'], 'id'))->toBe([$portal['id']])
        ->and(fn () => Passkeys::verifySignIn($world->di, 'client', passkeyAnswer($world, 'cred-shop')))->toThrow(InformationException::class, 'not set up for this sign-in')
        ->and(fn () => Passkeys::rename($world->di, 'client', 7, $own['id'], 'Mine'))->toThrow(InformationException::class, 'Passkey not found.')
        ->and(Passkeys::verifySignIn($world->di, 'client', passkeyAnswer($world, 'cred-portal')))->toBe(['client', 7])
        ->and(Passkeys::verifySignIn($world->di, 'staff', passkeyAnswer($world, 'cred-staff-portal', ['area' => 'staff'])))->toBe(['staff', 1])
        ->and($world->row('cred-shop')['name'])->not->toBe('Mine');
});

test('with two-factor sign-in required, a passkey on another site still refuses the password alone', function (): void {
    $world = new PasskeysTestWorld();
    $world->db->update('mod_clientfence_state', ['value' => json_encode(['passkeys' => 'both', 'twofactor' => 'all'])], ['name' => SignInSecurity::STATE]);
    passkeyAdd($world, 'client', 7, 'cred-portal');

    // Not on the primary site, nor on another one: there the password would open a session that enrolls a new factor.
    expect(fn () => TwoFactor::challenge($world->di, 7, null))->toThrow(InformationException::class, 'sign in with your passkey');
    $world->di['site'] = new ClientFence\Site('https://shop.example.test/', 'shop.example.test', 'shop/shop');
    expect(fn () => TwoFactor::challenge($world->di, 7, null))->toThrow(InformationException::class, 'sign in with your passkey');
});

test('before Finalize update adds the site column, a passkey sign-in is refused with the password hint, not a database error', function (): void {
    $world = new PasskeysTestWorld();
    passkeyAdd($world, 'client', 7, 'cred-client');
    $answer = passkeyAnswer($world, 'cred-client');
    $world->db->executeStatement('ALTER TABLE client_passkey DROP COLUMN site');

    expect(fn () => Passkeys::verifySignIn($world->di, 'client', $answer))->toThrow(InformationException::class, 'sign in with your password');
});

test('a challenge works once and for five minutes', function (): void {
    $world = new PasskeysTestWorld();
    passkeyAdd($world, 'client', 7, 'cred-client');
    $answer = passkeyAnswer($world, 'cred-client');
    Passkeys::verifySignIn($world->di, 'client', $answer);

    expect(fn () => Passkeys::verifySignIn($world->di, 'client', $answer))->toThrow(InformationException::class, 'has expired');

    $answer = passkeyAnswer($world, 'cred-client');
    $world->session[Passkeys::CHALLENGE]['expires'] = time() - 1;
    expect(fn () => Passkeys::verifySignIn($world->di, 'client', $answer))->toThrow(InformationException::class, 'has expired');
});

test('a sign count lower than the last one is refused; synced passkeys always send 0', function (): void {
    $world = new PasskeysTestWorld();
    passkeyAdd($world, 'client', 7, 'cred-client');
    Passkeys::verifySignIn($world->di, 'client', passkeyAnswer($world, 'cred-client', ['count' => 5]));

    expect(fn () => Passkeys::verifySignIn($world->di, 'client', passkeyAnswer($world, 'cred-client', ['count' => 3])))->toThrow(InformationException::class, 'could not be checked')
        ->and($world->row('cred-client')['sign_count'])->toBe(5);

    $synced = new PasskeysTestWorld();
    passkeyAdd($synced, 'client', 7, 'cred-synced');
    Passkeys::verifySignIn($synced->di, 'client', passkeyAnswer($synced, 'cred-synced'));
    expect(Passkeys::verifySignIn($synced->di, 'client', passkeyAnswer($synced, 'cred-synced')))->toBe(['client', 7]);
});

test('passkeys off for the area sign nobody in there', function (): void {
    $world = new PasskeysTestWorld('staff');

    expect(fn () => Passkeys::signInOptions($world->di, 'client'))->toThrow(InformationException::class, 'not on');

    $world->mode('both');
    passkeyAdd($world, 'client', 7, 'cred-client');
    $answer = passkeyAnswer($world, 'cred-client');
    $world->mode('staff');
    expect(fn () => Passkeys::verifySignIn($world->di, 'client', $answer))->toThrow(InformationException::class, 'not on');
});

test('a passkey meets the required two-factor sign-in in the session that used it, while passkeys are on for clients', function (): void {
    $world = new PasskeysTestWorld();

    expect(TwoFactor::hasSecondFactor($world->di, 'client', 7))->toBeFalse();
    passkeyAdd($world, 'client', 7, 'cred-client');
    expect(TwoFactor::hasSecondFactor($world->di, 'client', 7))->toBeTrue();
    // A session that did not sign in (or enrol) with the passkey: a password sign-in that switched accounts.
    unset($world->session[Passkeys::SIGNED_IN]);
    expect(TwoFactor::hasSecondFactor($world->di, 'client', 7))->toBeFalse();
    $world->session[Passkeys::SIGNED_IN] = 'client:7';
    $world->mode('staff');
    expect(TwoFactor::hasSecondFactor($world->di, 'client', 7))->toBeFalse()
        ->and(Passkeys::count($world->di, 'client', 7))->toBe(0)
        ->and(Passkeys::listFor($world->di, 'client', 7))->toBe(['on' => false, 'passkeys' => []]);
});

test('with two-factor sign-in required, the password alone of a passkey holder signs nobody in', function (): void {
    $world = new PasskeysTestWorld();
    $world->db->update('mod_clientfence_state', ['value' => json_encode(['passkeys' => 'both', 'twofactor' => 'all'])], ['name' => SignInSecurity::STATE]);

    $world->session[Passkeys::SIGNED_IN] = 'client:7';
    expect(TwoFactor::challenge($world->di, 7, null))->toBeFalse()
        ->and($world->session)->not->toHaveKey(Passkeys::SIGNED_IN);
    passkeyAdd($world, 'client', 7, 'cred-client');
    expect(fn () => TwoFactor::challenge($world->di, 7, null))->toThrow(InformationException::class, 'sign in with your passkey');
});

test('switching to a required account with the password alone is refused to a passkey holder, not after a passkey sign-in', function (): void {
    $world = new PasskeysTestWorld();
    $world->db->update('mod_clientfence_state', ['value' => json_encode(['passkeys' => 'both', 'twofactor' => 'all'])], ['name' => SignInSecurity::STATE]);
    passkeyAdd($world, 'client', 7, 'cred-client');

    unset($world->session[Passkeys::SIGNED_IN]);
    expect(fn () => TwoFactor::refusePasswordOnly($world->di, 7, 'client', 7))->toThrow(InformationException::class, 'sign in with your passkey');
    $world->session[Passkeys::SIGNED_IN] = 'client:7';
    TwoFactor::refusePasswordOnly($world->di, 7, 'client', 7);
    expect(true)->toBeTrue();
});

test('a deleted login leaves no passkeys', function (): void {
    $world = new PasskeysTestWorld();
    passkeyAdd($world, 'client', 7, 'cred-client');
    passkeyAdd($world, 'user', 8, 'cred-user');

    TwoFactor::forgetLogin($world->db, 'user', 8);

    expect(Passkeys::count($world->di, 'user', 8))->toBe(0)
        ->and(Passkeys::count($world->di, 'client', 7))->toBe(1);
});

test('passkeys need an https portal address, or localhost as browsers allow', function (): void {
    expect(array_map(Passkeys::secureUrl(...), ['https://billing.example.test/', 'http://billing.example.test/', 'http://localhost:8080/', 'http://clientfence.localhost:8090/', 'http://localhost.example.test/']))
        ->toBe([true, false, true, true, false]);
});

test('renaming a passkey that is not the login\'s own is refused', function (): void {
    $world = new PasskeysTestWorld();
    $world->db->insert('client_passkey', ['id' => 5, 'kind' => 'staff', 'subject_id' => 1, 'credential_id' => 'c5', 'public_key' => 'k', 'alg' => -7, 'name' => 'Laptop', 'user_handle' => 'h', 'created_at' => '2026-11-15 10:00:00']);

    Passkeys::rename($world->di, 'staff', 1, 5, 'Phone');

    expect($world->db->fetchOne('SELECT name FROM client_passkey WHERE id = 5'))->toBe('Phone')
        ->and(fn () => Passkeys::rename($world->di, 'staff', 1, 99, 'Phone'))->toThrow(InformationException::class, 'Passkey not found.')
        ->and(fn () => Passkeys::rename($world->di, 'staff', 2, 5, 'Phone'))->toThrow(InformationException::class, 'Passkey not found.');
});
