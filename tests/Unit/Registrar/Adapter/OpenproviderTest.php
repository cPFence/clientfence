<?php

declare(strict_types=1);

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** An Openprovider adapter answering "METHOD /path" from $replies (data, or a full reply with a code), recording each call. */
function openproviderAdapter(array $replies = []): Registrar_Adapter_Openprovider
{
    $replies += [
        'POST /auth/login' => ['token' => 'tok-1'],
        'POST /customers' => ['handle' => 'AL000001-GB'],
        'GET /domains' => ['results' => [['id' => 42, 'domain' => ['name' => 'example', 'extension' => 'com']]]],
    ];
    $client = new MockHttpClient(function (string $method, string $url, array $options) use ($replies): MockResponse {
        $path = (string) preg_replace('#^.*?/v1beta#', '', (string) parse_url($url, PHP_URL_PATH));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $body = json_decode((string) ($options['body'] ?? ''), true);
        $GLOBALS['openprovider_calls'][] = [$method . ' ' . $path, $method === 'GET' ? $query : $body, $url, $options['normalized_headers']['authorization'][0] ?? ''];
        $reply = $replies[$method . ' ' . $path] ?? [];

        return new MockResponse((string) json_encode(isset($reply['code']) ? $reply : ['code' => 0, 'desc' => '', 'data' => $reply]), ['http_code' => isset($reply['code']) ? 500 : 200]);
    });
    $adapter = new class(['username' => 'reseller', 'password' => 'secret-pw'], $client) extends Registrar_Adapter_Openprovider {
        public function __construct(array $options, private readonly HttpClientInterface $client)
        {
            parent::__construct($options);
        }

        public function getHttpClient(): HttpClientInterface
        {
            return $this->client;
        }
    };
    $adapter->setLog(new Tests\Helpers\TestLogger());
    $GLOBALS['openprovider_calls'] = [];

    return $adapter;
}

function openproviderDomain(string $tld = '.com'): Registrar_Domain
{
    $contact = (new Registrar_Domain_Contact())->setFirstName('Ann')->setLastName('Lee')->setEmail('ann@example.com')
        ->setAddress1('12 Main St')->setCity('London')->setZip('N1 1AA')->setCountry('gb')->setTelCc('44')->setTel('020 7946 0000');

    return (new Registrar_Domain())->setSld('example')->setTld($tld)->setRegistrationPeriod(2)
        ->setNs1('ns1.example.net')->setNs2('ns2.example.net')->setContactRegistrar($contact);
}

function openproviderCall(string $route): array
{
    foreach ($GLOBALS['openprovider_calls'] as $call) {
        if ($call[0] === $route) {
            return $call;
        }
    }

    throw new RuntimeException('No call ' . $route);
}

test('a registration logs in, makes the contact handle and registers with the client\'s name servers and no auto-renewal', function (): void {
    openproviderAdapter()->registerDomain(openproviderDomain());

    expect(array_column($GLOBALS['openprovider_calls'], 0))->toBe(['POST /auth/login', 'POST /customers', 'POST /domains'])
        ->and(openproviderCall('POST /auth/login')[1])->toBe(['username' => 'reseller', 'password' => 'secret-pw', 'ip' => '0.0.0.0'])
        ->and(openproviderCall('POST /customers')[3])->toBe('Authorization: Bearer tok-1')
        ->and(openproviderCall('POST /customers')[1]['phone'])->toBe(['country_code' => '+44', 'area_code' => '207', 'subscriber_number' => '9460000'])
        ->and(openproviderCall('POST /customers')[1]['address'])->toMatchArray(['street' => 'Main St', 'number' => '12', 'country' => 'GB'])
        ->and(openproviderCall('POST /domains')[1])->toMatchArray([
            'domain' => ['name' => 'example', 'extension' => 'com'], 'period' => 2, 'autorenew' => 'off', 'owner_handle' => 'AL000001-GB', 'billing_handle' => 'AL000001-GB',
            'name_servers' => [['name' => 'ns1.example.net', 'seq_nr' => 1], ['name' => 'ns2.example.net', 'seq_nr' => 2]],
        ]);
});

test('.us answers go on the handle, with the country for C31; the transfer sends the code only when there is one', function (): void {
    $adapter = openproviderAdapter();
    $adapter->transferDomain(openproviderDomain('.us')->setEpp('abc')->setFields(['nexus_category' => 'C31', 'nexus_country' => 'de', 'app_purpose' => 'P1']));
    expect(openproviderCall('POST /customers')[1]['extension_additional_data'])->toBe([['name' => 'us', 'data' => ['nexus_category' => 'C31/DE', 'applicant_purpose' => 'P1']]])
        ->and(openproviderCall('POST /domains/transfer')[1])->toMatchArray(['auth_code' => 'abc', 'domain' => ['name' => 'example', 'extension' => 'us']]);

    $GLOBALS['openprovider_calls'] = [];
    $adapter->transferDomain(openproviderDomain('.uk'));
    expect(openproviderCall('POST /domains/transfer')[1])->not->toHaveKey('auth_code')
        ->and(array_column($GLOBALS['openprovider_calls'], 0))->not->toContain('POST /auth/login');
});

test('a refused request is a registrar error with Openprovider\'s reason', function (): void {
    $adapter = openproviderAdapter(['POST /domains/42/renew' => ['code' => 399, 'desc' => 'Insufficient balance']]);

    expect(fn () => $adapter->renewDomain(openproviderDomain()))->toThrow(Registrar_Exception::class, 'Insufficient balance');
    expect(openproviderCall('GET /domains')[1])->toMatchArray(['full_name' => 'example.com']);
});

test('a failed login stops before any other call', function (): void {
    $adapter = openproviderAdapter(['POST /auth/login' => ['code' => 196, 'desc' => 'Authentication/Authorization Failed']]);

    expect(fn () => $adapter->lock(openproviderDomain()))->toThrow(Registrar_Exception::class, 'Authentication/Authorization Failed')
        ->and(array_column($GLOBALS['openprovider_calls'], 0))->toBe(['POST /auth/login']);
});

test('availability: free, taken and premium names', function (): void {
    expect(openproviderAdapter(['POST /domains/check' => ['results' => [['domain' => 'example.com', 'status' => 'free']]]])->isDomainAvailable(openproviderDomain()))->toBeTrue()
        ->and(openproviderAdapter(['POST /domains/check' => ['results' => [['domain' => 'example.com', 'status' => 'active']]]])->isDomainAvailable(openproviderDomain()))->toBeFalse()
        ->and(openproviderAdapter(['POST /domains/check' => ['results' => [['domain' => 'example.com', 'status' => 'active']]]])->isDomaincanBeTransferred(openproviderDomain()))->toBeTrue()
        ->and(fn () => openproviderAdapter(['POST /domains/check' => ['results' => [['domain' => 'example.com', 'status' => 'free', 'is_premium' => true]]]])->isDomainAvailable(openproviderDomain()))
        ->toThrow(Registrar_Exception::class, 'Premium')
        ->and(fn () => openproviderAdapter(['POST /domains/check' => ['results' => [['domain' => 'example.com', 'status' => 'active', 'is_premium' => true]]]])->isDomaincanBeTransferred(openproviderDomain()))
        ->toThrow(Registrar_Exception::class, 'Premium domains cannot be transferred');
});

test('the house number comes from either address line; an address without one is refused', function (): void {
    $adapter = openproviderAdapter();
    $domain = openproviderDomain();
    $domain->getContactRegistrar()->setAddress1('Acme House')->setAddress2('12b Main St');
    $adapter->registerDomain($domain);
    expect(openproviderCall('POST /customers')[1]['address'])->toMatchArray(['street' => 'Acme House, Main St', 'number' => '12b']);

    $GLOBALS['openprovider_calls'] = [];
    $domain->getContactRegistrar()->setAddress1('Hauptstr. 7')->setAddress2('Hinterhaus');
    $adapter->registerDomain($domain);
    expect(openproviderCall('POST /customers')[1]['address'])->toMatchArray(['street' => 'Hauptstr., Hinterhaus', 'number' => '7']);

    $GLOBALS['openprovider_calls'] = [];
    $domain->getContactRegistrar()->setAddress1('123 Main St')->setAddress2('Suite 200');
    $adapter->registerDomain($domain);
    expect(openproviderCall('POST /customers')[1]['address'])->toMatchArray(['street' => 'Main St, Suite 200', 'number' => '123']);

    $domain->getContactRegistrar()->setAddress1('Rose Cottage')->setAddress2('High Street');
    expect(fn () => $adapter->registerDomain($domain))->toThrow(Registrar_Exception::class, 'house number');
});

test('a contact change makes a new handle and sets it on the domain', function (): void {
    openproviderAdapter(['POST /customers' => ['handle' => 'AL000099-GB']])->modifyContact(openproviderDomain());

    expect(array_column($GLOBALS['openprovider_calls'], 0))->toBe(['POST /auth/login', 'GET /domains', 'POST /customers', 'PUT /domains/42'])
        ->and(openproviderCall('PUT /domains/42')[1])->toBe(['owner_handle' => 'AL000099-GB', 'admin_handle' => 'AL000099-GB', 'tech_handle' => 'AL000099-GB', 'billing_handle' => 'AL000099-GB']);
});

test('domain details, the transfer code, and a domain missing from the account', function (): void {
    $adapter = openproviderAdapter(['GET /domains/42' => ['creation_date' => '2024-01-02 10:00:00', 'expiration_date' => '2027-01-02 00:00:00', 'is_locked' => true,
        'is_private_whois_enabled' => false, 'name_servers' => [['name' => 'NS1.EXAMPLE.NET', 'seq_nr' => 1], ['name' => 'ns2.example.net', 'seq_nr' => 2]]],
        'GET /domains/42/authcode' => ['auth_code' => 'Xy9#k', 'success' => true]]);
    $details = $adapter->getDomainDetails(openproviderDomain());

    expect(date('Y-m-d', $details->getRegistrationTime()))->toBe('2024-01-02')
        ->and(date('Y-m-d', $details->getExpirationTime()))->toBe('2027-01-02')
        ->and($details->getLocked())->toBeTrue()
        ->and($details->getPrivacyEnabled())->toBeFalse()
        ->and([$details->getNs1(), $details->getNs2()])->toBe(['ns1.example.net', 'ns2.example.net'])
        ->and($adapter->getEpp(openproviderDomain()))->toBe('Xy9#k');

    expect(fn () => openproviderAdapter(['GET /domains' => ['results' => []]])->unlock(openproviderDomain()))->toThrow(Registrar_Exception::class, 'not found');
});

test('test mode uses the sandbox; a cancel sends nothing', function (): void {
    $adapter = openproviderAdapter();
    $adapter->enableTestMode();
    $adapter->enablePrivacyProtection(openproviderDomain());
    expect($GLOBALS['openprovider_calls'][0][2])->toStartWith('http://api.sandbox.openprovider.nl:8480/v1beta/auth/login')
        ->and(openproviderCall('PUT /domains/42')[1])->toBe(['is_private_whois_enabled' => true]);

    $GLOBALS['openprovider_calls'] = [];
    expect($adapter->deleteDomain(openproviderDomain()))->toBeTrue()->and($GLOBALS['openprovider_calls'])->toBe([]);
});

test('the order form asks the .us questions only, for registrations and transfers', function (): void {
    expect(array_keys(Registrar_Adapter_Openprovider::getDomainFields('.us')))->toBe(['nexus_category', 'nexus_country', 'app_purpose'])
        ->and(array_keys(Registrar_Adapter_Openprovider::getDomainFields('.us', 'transfer')))->toBe(['nexus_category', 'nexus_country', 'app_purpose'])
        ->and(Registrar_Adapter_Openprovider::getDomainFields('.com'))->toBe([]);
});
