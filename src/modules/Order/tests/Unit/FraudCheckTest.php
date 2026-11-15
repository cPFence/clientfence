<?php

declare(strict_types=1);

/**
 * ClientFence: the fraud check of new orders (Order\FraudCheck) on in-memory SQLite, with a mock HTTP client that
 * records each request: the MaxMind and FraudLabs Pro bodies (hashed email and its domain, no plain email), the
 * score limit, the provider's own rules, the skip rule, failures that let the order through with a staff bell, the
 * one-hour verdict cache and Off.
 */

use ClientFence\Config;
use ClientFence\Mod\Clientfence\Notifications;
use ClientFence\Mod\Order\FraudCheck;
use ClientFence\Mod\Order\Holds;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Client 9 with an address in New York; staff member 1 gets the bell. $sent collects every request; $answer
 * is the provider's reply (a MockResponse) or a Throwable for a transport failure.
 */
function fraudContainer(array &$sent, MockResponse|Throwable $answer): array
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE client (id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT, company TEXT, address_1 TEXT, address_2 TEXT, city TEXT, state TEXT, postcode TEXT, country TEXT, phone_cc TEXT, phone TEXT)');
    $db->executeStatement('CREATE TABLE client_order (id INTEGER PRIMARY KEY, client_id INTEGER, status TEXT)');
    $db->executeStatement("CREATE TABLE admin (id INTEGER PRIMARY KEY, status TEXT, system_name TEXT)");
    $db->executeStatement("INSERT INTO admin VALUES (1, 'active', NULL)");
    $db->executeStatement('CREATE TABLE ' . Notifications::TABLE . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, audience TEXT NOT NULL, recipient_id INTEGER NOT NULL, kind TEXT NOT NULL, params TEXT NOT NULL, url TEXT NOT NULL, dedupe TEXT, created_at TEXT NOT NULL, read_at TEXT, UNIQUE (audience, recipient_id, dedupe))');
    $db->insert('client', ['id' => 9, 'email' => ' Buyer@Example.COM ', 'first_name' => 'Ann', 'last_name' => 'Buyer', 'address_1' => '1 Main St', 'city' => 'New York', 'state' => 'NY', 'postcode' => '10001', 'country' => 'US', 'phone_cc' => '+1', 'phone' => '2125550100']);

    $di = new Pimple\Container();
    $di['dbal'] = $db;
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['cache'] = new ArrayAdapter();
    $di['request'] = Request::create('https://billing.example.com/api/client/cart/checkout', 'POST', [], [], [], ['HTTP_USER_AGENT' => 'Firefox', 'HTTP_ACCEPT_LANGUAGE' => 'en-US']);
    $crypt = Mockery::mock();
    $crypt->shouldReceive('encrypt')->andReturnUsing(fn (string $p): string => 'enc:' . strrev($p));
    $crypt->shouldReceive('decrypt')->andReturnUsing(fn (string $p): string => strrev(substr($p, 4)));
    $di['crypt'] = $crypt;
    $di['db'] = new class {
        public function load(string $type, int $id): Model_Admin
        {
            $admin = new Model_Admin();
            $admin->loadBean(new Tests\Helpers\DummyBean());
            $admin->id = $id;

            return $admin;
        }
    };
    $di['mod_service'] = $di->protect(fn () => new class {
        public function hasPermission(Model_Admin $admin, string $module): bool
        {
            return true;
        }
    });
    $di['http_client'] = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent, $answer): MockResponse {
        $sent[] = ['url' => $url, 'options' => $options];
        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return $answer;
    });

    return [$di, $db];
}

/** Runs $test with these fraud check settings in config.php, then puts the file back. */
function withFraudSettings(array $settings, callable $test): void
{
    $before = Config::getConfig();
    $config = $before;
    $config['security']['fraud_check'] = $settings + ['account_id' => '123456', 'key' => 'enc:' . strrev('licence-key'), 'limit' => 20, 'skip_active' => true];

    try {
        Config::setConfig($config, false);
        $test();
    } finally {
        Config::setConfig($before, false);
    }
}

function fraudCart(): Closure
{
    return fn (): array => [49.9, 'EUR'];
}

test('MaxMind gets the email as MD5 with its domain, the device, the address and the amount, with basic auth', function (): void {
    $sent = [];
    [$di] = fraudContainer($sent, new MockResponse((string) json_encode(['risk_score' => 0.42, 'funds_remaining' => 9.5]), ['http_code' => 200]));

    withFraudSettings(['provider' => FraudCheck::MAXMIND], function () use ($di, &$sent): void {
        expect(FraudCheck::hold($di, 9, '203.0.113.7', fraudCart()))->toBeNull();
        $body = json_decode((string) $sent[0]['options']['body'], true);
        $headers = implode("\n", $sent[0]['options']['headers']);

        expect($sent)->toHaveCount(1)
            ->and($sent[0]['url'])->toBe('https://minfraud.maxmind.com/minfraud/v2.0/score')
            ->and($headers)->toContain('Authorization: Basic ' . base64_encode('123456:licence-key'))
            ->and($body['email'])->toBe(['address' => md5('buyer@example.com'), 'domain' => 'example.com'])
            ->and($body['device'])->toBe(['ip_address' => '203.0.113.7', 'user_agent' => 'Firefox', 'accept_language' => 'en-US'])
            ->and($body['account'])->toBe(['user_id' => '9'])
            ->and($body['billing'])->toMatchArray(['first_name' => 'Ann', 'city' => 'New York', 'region' => 'NY', 'postal' => '10001', 'country' => 'US', 'phone_country_code' => '1'])
            ->and($body['order'])->toBe(['amount' => 49.9, 'currency' => 'EUR'])
            ->and((string) $sent[0]['options']['body'])->not->toContain('buyer@example.com');
    });
});

test('MaxMind gets the state only when it is a subdivision code', function (): void {
    $client = ['id' => 9, 'country' => 'US'];
    $device = ['ip' => '203.0.113.7', 'user_agent' => '', 'accept_language' => ''];

    expect(FraudCheck::maxmindBody($client + ['state' => 'US-CA'], $device, 1.0, 'USD')['billing']['region'])->toBe('CA')
        ->and(FraudCheck::maxmindBody($client + ['state' => 'New York'], $device, 1.0, 'USD')['billing'])->not->toHaveKey('region');
});

test('FraudLabs Pro gets the key and the email as SHA-256 with its domain, as a form', function (): void {
    $sent = [];
    [$di] = fraudContainer($sent, new MockResponse((string) json_encode(['fraudlabspro_score' => 5, 'fraudlabspro_status' => 'APPROVE', 'remaining_credits' => 480]), ['http_code' => 200]));

    withFraudSettings(['provider' => FraudCheck::FRAUDLABS, 'account_id' => ''], function () use ($di, &$sent): void {
        expect(FraudCheck::hold($di, 9, '203.0.113.7', fraudCart()))->toBeNull();
        parse_str((string) $sent[0]['options']['body'], $form);

        expect($sent[0]['url'])->toBe('https://api.fraudlabspro.com/v2/order/screen')
            ->and($form)->toMatchArray(['key' => 'licence-key', 'format' => 'json', 'ip' => '203.0.113.7', 'email_hash' => flpHash('buyer@example.com'), 'email_domain' => 'example.com', 'user_phone' => '12125550100', 'bill_country' => 'US', 'amount' => '49.90', 'currency' => 'EUR'])
            ->and($form)->not->toHaveKey('email');
    });
});

test('a score above the limit holds the checkout with the provider, score and limit; at the limit it goes through', function (): void {
    foreach ([[20.5, true], [20.0, false]] as [$score, $held]) {
        $sent = [];
        [$di] = fraudContainer($sent, new MockResponse((string) json_encode(['risk_score' => $score]), ['http_code' => 200]));
        withFraudSettings(['provider' => FraudCheck::MAXMIND], function () use ($di, $score, $held): void {
            expect(FraudCheck::hold($di, 9, '203.0.113.7', fraudCart()))->toBe($held ? ['reason' => Holds::REASON_FRAUD, 'note' => 'MaxMind ' . $score . ' / 20'] : null);
        });
    }
});

test('the provider\'s own rules saying review hold the checkout, with its status and rules', function (): void {
    $sent = [];
    [$di] = fraudContainer($sent, new MockResponse((string) json_encode(['fraudlabspro_score' => 8, 'fraudlabspro_status' => 'REVIEW', 'fraudlabspro_rules' => ['Free email']]), ['http_code' => 200]));

    withFraudSettings(['provider' => FraudCheck::FRAUDLABS], function () use ($di): void {
        expect(FraudCheck::hold($di, 9, '203.0.113.7', fraudCart()))->toBe(['reason' => 'fraud', 'note' => 'FraudLabs Pro 8 / 20: REVIEW Free email']);
    });
});

test('a client with an active order is not checked (setting on), and is when it is off', function (): void {
    $sent = [];
    [$di, $db] = fraudContainer($sent, new MockResponse((string) json_encode(['risk_score' => 1]), ['http_code' => 200]));
    $db->insert('client_order', ['id' => 1, 'client_id' => 9, 'status' => 'active']);

    withFraudSettings(['provider' => FraudCheck::MAXMIND, 'skip_active' => true], function () use ($di): void {
        FraudCheck::hold($di, 9, '203.0.113.7', fraudCart());
    });
    expect($sent)->toBe([]);
    withFraudSettings(['provider' => FraudCheck::MAXMIND, 'skip_active' => false], function () use ($di): void {
        FraudCheck::hold($di, 9, '203.0.113.7', fraudCart());
    });
    expect($sent)->toHaveCount(1);
});

test('no credit, a refused key or a timeout let the order through and ring the staff bell', function (): void {
    $answers = [
        'INSUFFICIENT_FUNDS' => [FraudCheck::MAXMIND, new MockResponse((string) json_encode(['code' => 'INSUFFICIENT_FUNDS', 'error' => 'No funds']), ['http_code' => 402])],
        '10007 INSUFFICIENT CREDIT' => [FraudCheck::FRAUDLABS, new MockResponse((string) json_encode(['error' => ['error_code' => 10007, 'error_message' => 'INSUFFICIENT CREDIT']]), ['http_code' => 200])],
        'Idle timeout' => [FraudCheck::MAXMIND, new TransportException('Idle timeout reached for "https://minfraud.maxmind.com/minfraud/v2.0/score".')],
    ];
    foreach ($answers as $code => [$provider, $answer]) {
        $sent = [];
        [$di, $db] = fraudContainer($sent, $answer);
        withFraudSettings(['provider' => $provider], function () use ($di): void {
            expect(FraudCheck::hold($di, 9, '203.0.113.7', fraudCart()))->toBeNull();
        });
        $bell = $db->fetchAssociative('SELECT kind, params FROM ' . Notifications::TABLE);

        expect($bell['kind'])->toBe('fraud_check_failed')
            ->and(json_decode($bell['params'], true)['error'])->toContain($code);
    }
});

test('the same checkout is checked once an hour (a double submit is not charged twice); a failure is not kept', function (): void {
    $sent = [];
    [$di] = fraudContainer($sent, new MockResponse((string) json_encode(['risk_score' => 1]), ['http_code' => 200]));
    withFraudSettings(['provider' => FraudCheck::MAXMIND], function () use ($di): void {
        FraudCheck::hold($di, 9, '203.0.113.7', fraudCart());
        FraudCheck::hold($di, 9, '203.0.113.7', fraudCart());
        FraudCheck::hold($di, 9, '203.0.113.7', fn (): array => [99.0, 'EUR']);
    });
    expect($sent)->toHaveCount(2);

    $sent = [];
    [$di] = fraudContainer($sent, new MockResponse('', ['http_code' => 503]));
    withFraudSettings(['provider' => FraudCheck::MAXMIND], function () use ($di): void {
        FraudCheck::hold($di, 9, '203.0.113.7', fraudCart());
        FraudCheck::hold($di, 9, '203.0.113.7', fraudCart());
    });
    expect($sent)->toHaveCount(2);
});

test('off: no call, and the cart total is not even worked out', function (): void {
    $sent = [];
    [$di] = fraudContainer($sent, new MockResponse('', ['http_code' => 200]));
    withFraudSettings(['provider' => FraudCheck::OFF], function () use ($di): void {
        expect(FraudCheck::hold($di, 9, '203.0.113.7', fn (): array => throw new LogicException('cart read')))->toBeNull();
    });
    expect($sent)->toBe([]);
});

test('saving keeps the key encrypted and never shows it; a test call saves nothing', function (): void {
    $sent = [];
    [$di] = fraudContainer($sent, new MockResponse((string) json_encode(['risk_score' => 0.1, 'funds_remaining' => 4.25]), ['http_code' => 200]));
    withFraudSettings(['provider' => FraudCheck::OFF, 'key' => ''], function () use ($di, &$sent): void {
        expect(FraudCheck::save($di, ['provider' => 'maxmind', 'account_id' => '42', 'key' => 'typed-key', 'limit' => '25', 'skip_active' => '1'], true))->toContain('USD 4.25')
            ->and(FraudCheck::settings()['provider'])->toBe(FraudCheck::OFF)
            ->and(implode("\n", $sent[0]['options']['headers']))->toContain(base64_encode('42:typed-key'));

        FraudCheck::save($di, ['provider' => 'maxmind', 'account_id' => '42', 'key' => 'typed-key', 'limit' => '25', 'skip_active' => '1']);
        expect(FraudCheck::settings())->toMatchArray(['provider' => 'maxmind', 'account_id' => '42', 'key' => 'enc:' . strrev('typed-key'), 'limit' => 25.0, 'skip_active' => true])
            ->and(FraudCheck::forPage())->not->toHaveKey('key')
            ->and(FraudCheck::forPage()['has_key'])->toBeTrue();
        // An empty key keeps the saved one of the same service; a bad limit is refused.
        FraudCheck::save($di, ['provider' => 'maxmind', 'account_id' => '42', 'key' => '', 'limit' => '30']);
        expect(FraudCheck::settings()['key'])->toBe('enc:' . strrev('typed-key'))
            ->and(fn () => FraudCheck::save($di, ['provider' => 'maxmind', 'account_id' => '42', 'limit' => '150']))->toThrow(ClientFence\InformationException::class)
            ->and(fn () => FraudCheck::save($di, ['provider' => 'fraudlabs', 'key' => '', 'limit' => '20']))->toThrow(ClientFence\InformationException::class, 'key');
    });
});

/** The FraudLabs Pro SDK's hash (fraudlabspro-php Order::doHash), written out here to check ours against it. */
function flpHash(string $value): string
{
    $hash = 'fraudlabspro_' . $value;
    for ($i = 0; $i < 65536; ++$i) {
        $hash = sha1('fraudlabspro_' . $hash);
    }

    return $hash;
}
