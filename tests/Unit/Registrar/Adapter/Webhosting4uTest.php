<?php

declare(strict_types=1);

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A WebHosting|4U adapter answering "METHOD path" (the path after the endpoint) from $replies: [http code, JSON array or raw
 * string], a closure given the route's call number, or 'transport' for a network error. Unanswered calls get 200
 * {"result": "success"}. Records [route, form body, headers, url].
 */
function wh4uAdapter(array $replies = [], array $options = ['username' => 'reseller@example.com', 'api_key' => 'wh4u-secret-key']): Registrar_Adapter_Webhosting4u
{
    $client = new MockHttpClient(function (string $method, string $url, array $request) use ($replies): MockResponse {
        $route = $method . ' ' . preg_replace('#^https?://[^/]+/(customers/modules/addons/DomainsReseller/api/index\.php|api)/#', '', $url);
        parse_str((string) ($request['body'] ?? ''), $body);
        $headers = array_map(fn (array $lines) => explode(': ', $lines[0], 2)[1] ?? '', $request['normalized_headers']);
        $n = count(array_filter($GLOBALS['wh4u_calls'], fn (array $call) => $call[0] === $route));
        $GLOBALS['wh4u_calls'][] = [$route, $body, $headers, $url];
        $reply = $replies[$route] ?? [200, ['result' => 'success']];
        $reply = $reply instanceof Closure ? $reply($n) : $reply;
        if ($reply === 'transport') {
            return new MockResponse('', ['error' => 'Could not resolve host with token=leaked-token']);
        }

        return new MockResponse(is_string($reply[1]) ? $reply[1] : (string) json_encode($reply[1]), ['http_code' => $reply[0]]);
    });
    $adapter = new class($options, $client) extends Registrar_Adapter_Webhosting4u {
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
    $GLOBALS['wh4u_calls'] = [];

    return $adapter;
}

function wh4uDomain(string $tld = '.com', ?int $years = 1): Registrar_Domain
{
    $contact = (new Registrar_Domain_Contact())->setFirstName('Nikos')->setLastName('Papadakis')->setCompany('Acme AE')->setEmail('nikos@example.gr')
        ->setAddress1('1 Ermou')->setCity('Athens')->setZip('10563')->setCountry('GR')->setTelCc('30')->setTel('2101234567');
    $contact->setCompanyNumber('EL123456789');

    return (new Registrar_Domain())->setSld('example')->setTld($tld)->setRegistrationPeriod($years)->setPrivacyEnabled(false)
        ->setNs1('ns1.example.net')->setNs2('ns2.example.net')->setContactRegistrar($contact);
}

/** @return list<string> the routes called so far */
function wh4uRoutes(): array
{
    return array_column($GLOBALS['wh4u_calls'], 0);
}

test('the form has the username, a secret API key and two optional endpoints; username and key are required', function (): void {
    $form = Registrar_Adapter_Webhosting4u::getConfig()['form'];

    expect(array_keys($form))->toBe(['username', 'api_key', 'api_url', 'test_api_url'])
        ->and($form['api_key'][1]['secret'])->toBeTrue()
        ->and(Registrar_Adapter_Webhosting4u::getSecretFields())->toBe(['api_key'])
        ->and(fn () => new Registrar_Adapter_Webhosting4u([]))->toThrow(Registrar_Exception::class, 'API username')
        ->and(fn () => new Registrar_Adapter_Webhosting4u(['username' => 'reseller@example.com']))->toThrow(Registrar_Exception::class, 'API key');
});

test('an endpoint must be https, or http on a loopback host', function (string $url, bool $accepted): void {
    $make = fn () => new Registrar_Adapter_Webhosting4u(['username' => 'u@example.com', 'api_key' => 'k', 'test_api_url' => $url]);

    $accepted ? expect($make())->toBeInstanceOf(Registrar_Adapter_Webhosting4u::class) : expect($make)->toThrow(Registrar_Exception::class, 'Test API endpoint');
})->with([
    'https' => ['https://staging.example.com/api', true],
    'plain http' => ['http://staging.example.com/api', false],
    'not a URL' => ['not-a-url', false],
    'ftp' => ['ftp://staging.example.com/api', false],
    'localhost' => ['http://localhost/api', true],
    'LOCALHOST' => ['http://LOCALHOST/api', true],
    'IPv6 loopback' => ['http://[::1]/api', true],
]);

test('every call is signed with the email and an HMAC of the key for the UTC hour, and goes to the live endpoint', function (): void {
    wh4uAdapter([], ['username' => 'reseller@example.com', 'api_key' => 'wh4u-secret-key', 'test_api_url' => 'https://staging.example.com/api'])->modifyNs(wh4uDomain());

    [, , $headers, $url] = $GLOBALS['wh4u_calls'][0];
    expect($url)->toBe('https://webhosting4u.gr/customers/modules/addons/DomainsReseller/api/index.php/domains/example.com/nameservers')
        ->and($headers['username'])->toBe('reseller@example.com')
        ->and($headers['token'])->toBe(base64_encode(hash_hmac('sha256', 'wh4u-secret-key', 'reseller@example.com:' . gmdate('y-m-d H', (int) $headers['timestamp']))));
});

test('a refused signature is tried again for the hours either side, but an order is sent only once', function (): void {
    $adapter = wh4uAdapter(['POST domains/lookup' => fn (int $n) => $n === 0 ? [401, ['error' => '']] : [200, [['domainName' => 'example.com', 'isAvailable' => true]]]]);
    expect($adapter->isDomainAvailable(wh4uDomain()))->toBeTrue()
        ->and(wh4uRoutes())->toBe(['POST domains/lookup', 'POST domains/lookup']);
    $retry = $GLOBALS['wh4u_calls'][1][2];
    expect($retry['token'])->toBe(base64_encode(hash_hmac('sha256', 'wh4u-secret-key', 'reseller@example.com:' . gmdate('y-m-d H', (int) $retry['timestamp'] - 3600))));

    $order = wh4uAdapter(['POST order/domains/register' => [401, '']]);
    expect(fn () => $order->registerDomain(wh4uDomain()))->toThrow(Registrar_Exception::class, 'rejected the credentials')
        ->and(wh4uRoutes())->toBe(['POST order/domains/register']);

    $blocked = wh4uAdapter(['POST domains/lookup' => [403, ['error' => 'IP address not allowed']]]);
    expect(fn () => $blocked->isDomainAvailable(wh4uDomain()))->toThrow(Registrar_Exception::class, 'IP address not allowed')
        ->and($GLOBALS['wh4u_calls'])->toHaveCount(3);
});

test('a refusal is "error" or a failed "result"; "message" alone is a success', function (int $code, array|string $body, ?string $error): void {
    $adapter = wh4uAdapter(['POST domains/example.com/nameservers' => [$code, $body]]);

    $error === null ? expect($adapter->modifyNs(wh4uDomain()))->toBeTrue() : expect(fn () => $adapter->modifyNs(wh4uDomain()))->toThrow(Registrar_Exception::class, $error);
})->with([
    'error key' => [409, ['error' => 'Reseller does not have enough credits on his account'], 'Reseller does not have enough credits'],
    'result error at 200' => [200, ['result' => 'error', 'message' => 'Invalid nameserver'], 'Invalid nameserver'],
    'result failed without a message' => [200, ['result' => 'failed'], 'reported as failed without a reason'],
    'success with a message' => [200, ['result' => 'success', 'message' => 'Nameservers updated'], null],
    'HTTP error without an error key' => [500, ['status' => 'x'], 'HTTP 500'],
    'a redirect is not followed' => [301, ['result' => 'success'], 'HTTP 301'],
    'HTML error page' => [500, '<html>Internal Server Error</html>', 'unreadable response (HTTP 500)'],
    '2xx without JSON' => [200, 'OK', null],
]);

test('the API key, the token and the registrant never reach the log', function (): void {
    $adapter = wh4uAdapter(['POST order/domains/register' => [409, ['error' => 'Domain already registered']], 'POST domains/lookup' => 'transport']);
    expect(fn () => $adapter->registerDomain(wh4uDomain()))->toThrow(Registrar_Exception::class, 'Domain already registered')
        ->and(fn () => $adapter->isDomainAvailable(wh4uDomain()))->toThrow(Registrar_Exception::class, 'Could not reach');

    $log = json_encode($adapter->getLog()->calls);
    expect($log)->toContain('Domain already registered')->toContain('TransportException')
        ->not->toContain('wh4u-secret-key')->not->toContain($GLOBALS['wh4u_calls'][0][2]['token'])->not->toContain('leaked-token')
        ->not->toContain('nikos@example.gr')->not->toContain('Papadakis');
});

test('a registration sends the order, the registrant and the name servers, then confirms the account holds the name', function (): void {
    $adapter = wh4uAdapter(['GET domains/example.gr/information' => [200, ['domain' => 'example.gr', 'expiryDate' => '2028-11-20']]]);
    $adapter->registerDomain(wh4uDomain('.gr', 2)->setPrivacyEnabled(true)->setFields(['owner_first_name' => 'Maria', 'owner_country' => 'GR', 'owner_city' => '', 'unknown' => 'x']));

    [$order] = $GLOBALS['wh4u_calls'];
    expect(wh4uRoutes())->toBe(['POST order/domains/register', 'GET domains/example.gr/information'])
        ->and($order[1])->toMatchArray(['domain' => 'example.gr', 'regperiod' => '2', 'idnLanguage' => '',
            'addons' => ['dnsmanagement' => '0', 'emailforwarding' => '0', 'idprotection' => '1'],
            'nameservers' => ['ns1' => 'ns1.example.net', 'ns2' => 'ns2.example.net', 'ns3' => '', 'ns4' => '', 'ns5' => '']])
        ->and(unserialize(base64_decode($order[1]['domainfields'], true)))->toBe(['Owner First Name' => 'Maria', 'Owner Country' => 'GR'])
        ->and($order[1]['contacts']['registrant'])->toBe(['firstname' => 'Nikos', 'lastname' => 'Papadakis', 'companyname' => 'Acme AE', 'email' => 'nikos@example.gr',
            'address1' => '1 Ermou', 'address2' => '', 'city' => 'Athens', 'state' => '', 'postcode' => '10563', 'country' => 'GR', 'phonenumber' => '30.2101234567', 'tax_id' => 'EL123456789']);

    wh4uAdapter()->registerDomain(wh4uDomain());
    expect(unserialize(base64_decode($GLOBALS['wh4u_calls'][0][1]['domainfields'], true)))->toBe([]);
});

test('a registration answered 200 without JSON succeeds unless the follow-up read says the name is not in the account', function (): void {
    expect(wh4uAdapter(['POST order/domains/register' => [200, 'Domain registered']])->registerDomain(wh4uDomain()))->toBeTrue()
        ->and(wh4uRoutes())->toBe(['POST order/domains/register', 'GET domains/example.com/information']);

    // A read that fails for another reason does not fail a charged registration.
    expect(wh4uAdapter(['POST order/domains/register' => [200, ''], 'GET domains/example.com/information' => [500, 'down']])->registerDomain(wh4uDomain()))->toBeTrue();

    $absent = wh4uAdapter(['POST order/domains/register' => [200, ''], 'GET domains/example.com/information' => [404, ['error' => 'Domain has not been found in reseller account']]]);
    expect(fn () => $absent->registerDomain(wh4uDomain()))->toThrow(Registrar_Exception::class, 'does not hold it');
});

test('the Greek registry\'s two-year terms are enforced on registrations and renewals, in both .ελ spellings, but not on transfers', function (string $tld, ?int $years, string $action, bool $sent): void {
    $adapter = wh4uAdapter();
    $call = fn () => $adapter->{$action}(wh4uDomain($tld, $years)->setEpp('auth-1'));

    $sent ? expect($call())->toBeTrue() : expect($call)->toThrow(Registrar_Exception::class);
    expect($GLOBALS['wh4u_calls'] !== [])->toBe($sent);
})->with([
    '.gr 2 years' => ['.gr', 2, 'registerDomain', true],
    '.gr 4 years' => ['.gr', 4, 'renewDomain', true],
    '.gr 1 year' => ['.gr', 1, 'registerDomain', false],
    '.gr 3 years' => ['.gr', 3, 'renewDomain', false],
    '.com.gr 1 year' => ['.com.gr', 1, 'registerDomain', false],
    '.ελ 1 year' => ['.ελ', 1, 'registerDomain', false],
    '.xn--qxam 1 year' => ['.xn--qxam', 1, 'registerDomain', false],
    '.com 3 years' => ['.com', 3, 'registerDomain', true],
    '.gr transfer 1 year' => ['.gr', 1, 'transferDomain', true],
    'no period' => ['.com', null, 'registerDomain', false],
    'no period on a transfer' => ['.gr', null, 'transferDomain', false],
]);

test('a transfer sends the code and the registrant; a renewal the term; .ελ is sent with its language', function (): void {
    wh4uAdapter()->transferDomain(wh4uDomain('.gr', 1)->setEpp('auth-123'));
    expect($GLOBALS['wh4u_calls'][0][0])->toBe('POST order/domains/transfer')
        ->and($GLOBALS['wh4u_calls'][0][1])->toMatchArray(['domain' => 'example.gr', 'regperiod' => '1', 'eppcode' => 'auth-123'])
        ->and($GLOBALS['wh4u_calls'][0][1]['contacts']['registrant']['email'])->toBe('nikos@example.gr');

    wh4uAdapter()->renewDomain(wh4uDomain('.xn--qxam', 2));
    expect($GLOBALS['wh4u_calls'][0][0])->toBe('POST order/domains/renew')
        ->and($GLOBALS['wh4u_calls'][0][1])->toBe(['domain' => 'example.xn--qxam', 'regperiod' => '2', 'idnLanguage' => 'gr',
            'addons' => ['dnsmanagement' => '0', 'emailforwarding' => '0', 'idprotection' => '0']]);
});

test('test mode without a test endpoint refuses registrations, transfers and renewals but still searches; with one it uses it', function (): void {
    $adapter = wh4uAdapter(['POST domains/lookup' => [200, [['domainName' => 'example.com', 'isAvailable' => false]]]]);
    $adapter->enableTestMode();
    foreach (['registerDomain', 'transferDomain', 'renewDomain'] as $action) {
        expect(fn () => $adapter->{$action}(wh4uDomain()->setEpp('auth-1')))->toThrow(Registrar_Exception::class, 'test mode');
    }
    expect($GLOBALS['wh4u_calls'])->toBe([])
        ->and($adapter->isDomainAvailable(wh4uDomain()))->toBeFalse();

    $staging = wh4uAdapter([], ['username' => 'u@example.com', 'api_key' => 'k', 'test_api_url' => 'https://staging.example.com/api']);
    $staging->enableTestMode();
    expect($staging->renewDomain(wh4uDomain()))->toBeTrue()
        ->and($GLOBALS['wh4u_calls'][0][3])->toBe('https://staging.example.com/api/order/domains/renew');
});

test('availability: the SLD is searched in the TLD, the answer must be about the name asked, and no answer is not "taken"', function (): void {
    $answer = fn (array $result) => wh4uAdapter(['POST domains/lookup' => [200, [$result]]]);

    expect($answer(['domainName' => 'example.com', 'isAvailable' => true])->isDomainAvailable(wh4uDomain()))->toBeTrue()
        ->and($GLOBALS['wh4u_calls'][0][1])->toMatchArray(['searchTerm' => 'example', 'tldsToInclude' => ['.com']])
        ->and($answer(['domainName' => 'EXAMPLE.com', 'isAvailable' => false])->isDomainAvailable(wh4uDomain()))->toBeFalse()
        ->and(fn () => $answer(['domainName' => 'example.com'])->isDomainAvailable(wh4uDomain()))->toThrow(Registrar_Exception::class, 'did not say')
        ->and(fn () => $answer(['domainName' => 'other.com', 'isAvailable' => true])->isDomainAvailable(wh4uDomain()))->toThrow(Registrar_Exception::class, 'answered about other.com')
        ->and(fn () => $answer(['isValidDomain' => false, 'domainErrorMessage' => 'Too short'])->isDomainAvailable(wh4uDomain()))->toThrow(Registrar_Exception::class, 'Too short')
        ->and(fn () => wh4uAdapter(['POST domains/lookup' => [200, []]])->isDomainAvailable(wh4uDomain()))->toThrow(Registrar_Exception::class, 'no result')
        ->and($answer(['domainName' => 'example.com', 'isRegistered' => true])->isDomaincanBeTransferred(wh4uDomain()))->toBeTrue()
        ->and($answer(['domainName' => 'example.com', 'isRegistered' => false])->isDomaincanBeTransferred(wh4uDomain()))->toBeFalse();
});

test('domain details set the expiry at noon UTC, the lock, privacy and name servers the reply has', function (): void {
    $details = wh4uAdapter(['GET domains/example.com/information' => [200, ['domain' => 'example.com', 'expiryDate' => '2027-05-05 23:30:00',
        'transferLock' => 'locked', 'idProtectionStatus' => 'enabled', 'nameservers' => ['ns1.host.net', ['host' => 'x'], 'ns2.host.net']]]])->getDomainDetails(wh4uDomain()->setNs3('ns3.old.net'));

    expect(gmdate('Y-m-d H:i', $details->getExpirationTime()))->toBe('2027-05-05 12:00')
        ->and($details->getLocked())->toBeTrue()
        ->and($details->getPrivacyEnabled())->toBeTrue()
        ->and([$details->getNs1(), $details->getNs2(), $details->getNs3()])->toBe(['ns1.host.net', 'ns2.host.net', '']);

    // Unknown privacy and lock words, no name servers: privacy and the name servers are left as they were, the lock unknown.
    $unknown = wh4uAdapter(['GET domains/example.com/information' => [200, ['expiryDate' => '2027-05-05', 'transferLock' => 'pending', 'idProtectionStatus' => 'pending']]])
        ->getDomainDetails(wh4uDomain()->setPrivacyEnabled(true));
    expect($unknown->getLocked())->toBeNull()
        ->and($unknown->getPrivacyEnabled())->toBeTrue()
        ->and($unknown->getNs1())->toBe('ns1.example.net');
});

test('domain details without a real expiry date, or about another name, throw instead of keeping the stored expiry', function (array $reply, string $error): void {
    $adapter = wh4uAdapter(['GET domains/example.com/information' => [200, $reply]]);

    expect(fn () => $adapter->getDomainDetails(wh4uDomain()->setExpirationTime(1800000000)))->toThrow(Registrar_Exception::class, $error);
})->with([
    'no date' => [['domain' => 'example.com'], 'did not report an expiry date'],
    'zero date' => [['expiryDate' => '0000-00-00'], 'did not report an expiry date'],
    'month 13' => [['expiryDate' => '2027-13-01'], 'did not report an expiry date'],
    '31 February' => [['expiryDate' => '2026-02-31'], 'did not report an expiry date'],
    'not ISO' => [['expiryDate' => '05/11/2027'], 'did not report an expiry date'],
    'another name' => [['domain' => 'other.com', 'expiryDate' => '2027-05-05'], 'answered about other.com'],
]);

test('name servers are sent as ns1 to ns5', function (): void {
    wh4uAdapter()->modifyNs(wh4uDomain()->setNs3('ns3.example.net'));

    expect($GLOBALS['wh4u_calls'][0][1])->toBe(['domain' => 'example.com', 'ns1' => 'ns1.example.net', 'ns2' => 'ns2.example.net', 'ns3' => 'ns3.example.net', 'ns4' => '', 'ns5' => '']);
});

test('a contact change is sent as the registrant; a read-back without the new email is logged, not thrown', function (array|string $readBack, bool $logged): void {
    $adapter = wh4uAdapter(['GET domains/example.com/contact' => [is_string($readBack) ? 500 : 200, $readBack]]);

    expect($adapter->modifyContact(wh4uDomain()))->toBeTrue()
        ->and($GLOBALS['wh4u_calls'][0][1]['contactdetails']['Registrant']['email'])->toBe('nikos@example.gr')
        ->and(str_contains((string) json_encode($adapter->getLog()->calls), 'not yet visible'))->toBe($logged);
})->with([
    'shown' => [['Registrant' => ['Email' => 'NIKOS@example.gr']], false],
    'old contact' => [['Registrant' => ['Email' => 'old@example.gr']], true],
    'read fails' => ['<html>error</html>', true],
]);

test('the transfer code is read from eppcode and its spellings; an empty or missing code is a clear error', function (array $reply, ?string $code, string $error = ''): void {
    $adapter = wh4uAdapter(['GET domains/example.com/eppcode' => [200, $reply]]);

    $code !== null ? expect($adapter->getEpp(wh4uDomain()))->toBe($code) : expect(fn () => $adapter->getEpp(wh4uDomain()))->toThrow(Registrar_Exception::class, $error);
})->with([
    'eppcode' => [['status' => 'success', 'eppcode' => ' Ab#123 '], 'Ab#123'],
    'authCode' => [['authCode' => '987654'], '987654'],
    'empty eppcode' => [['status' => 'success', 'eppcode' => ''], null, 'holds no transfer code'],
    'a status code only' => [['code' => 0], null, 'did not return a transfer code'],
]);

test('a lock change is read back: a contradiction throws, an unclear state is logged', function (string $action, mixed $state, ?string $error): void {
    $adapter = wh4uAdapter(['GET domains/example.com/lock' => [200, $state === null ? ['result' => 'success'] : ['lockstatus' => $state]]]);

    $error === null ? expect($adapter->{$action}(wh4uDomain()))->toBeTrue() : expect(fn () => $adapter->{$action}(wh4uDomain()))->toThrow(Registrar_Exception::class, $error);
    expect($GLOBALS['wh4u_calls'][0][1])->toBe(['domain' => 'example.com', 'lockstatus' => $action === 'lock' ? '1' : '0'])
        ->and(str_contains((string) json_encode($adapter->getLog()->calls), 'could not be confirmed'))->toBe($state === null);
})->with([
    'locked' => ['lock', 'locked', null],
    'still unlocked' => ['lock', 0, 'still unlocked'],
    'unlocked' => ['unlock', '0', null],
    'still locked' => ['unlock', true, 'still locked'],
    'unclear' => ['lock', null, null],
]);

test('privacy is refused on the Greek registry and sent for the other TLDs', function (): void {
    $adapter = wh4uAdapter();
    expect(fn () => $adapter->enablePrivacyProtection(wh4uDomain('.gr')))->toThrow(Registrar_Exception::class, 'does not offer privacy protection')
        ->and(fn () => $adapter->disablePrivacyProtection(wh4uDomain('.ελ')))->toThrow(Registrar_Exception::class, 'does not offer privacy protection')
        ->and($GLOBALS['wh4u_calls'])->toBe([])
        ->and($adapter->enablePrivacyProtection(wh4uDomain()))->toBeTrue()
        ->and($GLOBALS['wh4u_calls'][0])->toMatchArray(['POST domains/example.com/protectid', ['domain' => 'example.com', 'status' => '1']])
        ->and($adapter->disablePrivacyProtection(wh4uDomain()))->toBeTrue()
        ->and($GLOBALS['wh4u_calls'][1][1]['status'])->toBe('0');
});

test('a delete is sent once, in test mode too, and never signed again', function (): void {
    $adapter = wh4uAdapter();
    $adapter->enableTestMode();
    expect($adapter->deleteDomain(wh4uDomain()))->toBeTrue()
        ->and(wh4uRoutes())->toBe(['POST domains/example.com/delete']);

    $refused = wh4uAdapter(['POST domains/example.com/delete' => [401, '']]);
    expect(fn () => $refused->deleteDomain(wh4uDomain()))->toThrow(Registrar_Exception::class, 'rejected the credentials')
        ->and($GLOBALS['wh4u_calls'])->toHaveCount(1);
});

test('prices are per year, "not offered" is null, and both .ελ spellings match the asked TLDs', function (): void {
    $adapter = wh4uAdapter(['GET tlds/pricing' => [200, [
        ['tld' => 'com', 'registrationPrice' => '9.50', 'renewalPrice' => '10.00', 'transferPrice' => '0.00', 'currencyCode' => 'eur', 'years' => [1, 2, 3]],
        ['tld' => '.ελ', 'registrationPrice' => '-1.00', 'renewalPrice' => '-1.00', 'transferPrice' => '-1.00', 'currencyCode' => 'EUR'],
        ['tld' => '.GR', 'registrationPrice' => null, 'renewalPrice' => '46.25', 'transferPrice' => '20'],
        'not a row',
    ]]]);

    expect($adapter->getTldPrices())->toBe(['currency' => 'EUR', 'prices' => [
        '.com' => ['register' => 9.5, 'renew' => 10.0, 'transfer' => null],
        '.xn--qxam' => ['register' => null, 'renew' => null, 'transfer' => null],
        '.gr' => ['register' => null, 'renew' => 46.25, 'transfer' => 20.0],
    ], 'remaining' => []])
        ->and(array_keys($adapter->getTldPrices(['.ελ', '.net'])['prices']))->toBe(['.xn--qxam'])
        ->and(array_keys($adapter->getTldPrices(['.xn--qxam'])['prices']))->toBe(['.xn--qxam']);
});

test('prices in two currencies are refused, and no currency stays empty', function (): void {
    $mixed = wh4uAdapter(['GET tlds/pricing' => [200, [['tld' => '.com', 'currencyCode' => 'EUR'], ['tld' => '.net', 'currencyCode' => 'USD']]]]);
    expect(fn () => $mixed->getTldPrices())->toThrow(Registrar_Exception::class, 'more than one currency (EUR and USD)')
        ->and(wh4uAdapter(['GET tlds/pricing' => [200, [['tld' => '.com', 'registrationPrice' => '9']]]])->getTldPrices()['currency'])->toBe('');
});

test('the transfer status reads the flags and every status field; not yet in the account is pending', function (int $code, array $reply, string $status, string $message): void {
    $adapter = wh4uAdapter(['POST domains/example.com/transfersync' => [$code, $reply]]);

    expect($adapter->getTransferStatus(wh4uDomain()))->toBe(['status' => $status, 'message' => $message])
        ->and($GLOBALS['wh4u_calls'])->toHaveCount(1);
})->with([
    'completed flag' => [200, ['completed' => true, 'reason' => 'Done at registry'], Registrar_TransferStatus::COMPLETED, 'Done at registry'],
    'failed flag' => [200, ['failed' => 1, 'reason' => 'Wrong code'], Registrar_TransferStatus::FAILED, 'Wrong code'],
    'status word beside prose' => [200, ['reason' => 'Awaiting registry confirmation', 'status' => 'completed'], Registrar_TransferStatus::COMPLETED, 'Awaiting registry confirmation'],
    'rejected state' => [200, ['state' => 'Rejected'], Registrar_TransferStatus::FAILED, ''],
    'unknown' => [200, ['status' => 'pendingtransfer'], Registrar_TransferStatus::PENDING, 'pendingtransfer'],
    'not in the account' => [404, ['error' => 'Domain has not been found in reseller account'], Registrar_TransferStatus::PENDING, 'Not yet in the reseller account; awaiting the registry.'],
]);

test('the transfer status throws on any other refusal', function (): void {
    $adapter = wh4uAdapter(['POST domains/example.com/transfersync' => [409, ['error' => 'Service unavailable']]]);

    expect(fn () => $adapter->getTransferStatus(wh4uDomain()))->toThrow(Registrar_Exception::class, 'Service unavailable');
});

test('the Greek TLDs ask ten optional registrant fields, in either .ελ spelling and any case; others ask none', function (string $tld, int $count): void {
    $fields = Registrar_Adapter_Webhosting4u::getDomainFields($tld);

    expect($fields)->toHaveCount($count)
        ->and(array_filter($fields, fn (array $f) => !empty($f['required'])))->toBe([]);
    if ($count > 0) {
        expect(array_keys($fields['owner_country']['options']))->toContain('GR');
    }
})->with([
    '.gr' => ['.gr', 10],
    '.com.gr' => ['.com.gr', 10],
    '.ελ' => ['.ελ', 10],
    '.ΕΛ' => ['.ΕΛ', 10],
    '.xn--qxam' => ['.xn--qxam', 10],
    'gr without the dot' => ['gr', 10],
    '.GR' => ['.GR', 10],
    '.com' => ['.com', 0],
]);
