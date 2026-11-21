<?php

declare(strict_types=1);

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A Spaceship adapter answering "METHOD /path" (the path after /api, query included) from $replies: [http code, JSON body,
 * headers], [http code, JSON body] or the body alone, or a list of those for successive calls. Unanswered calls: PUT
 * /v1/contacts gives contact ct1, a register, transfer or renew is accepted as operation op1, op1 succeeds, the rest
 * answer {}. Records [route, body, headers] in $adapter->calls; the pause between status checks is skipped.
 */
function spaceshipAdapter(array $replies = [], bool $testMode = false): Registrar_Adapter_Spaceship
{
    $calls = new ArrayObject();
    $client = new MockHttpClient(function (string $method, string $url, array $request) use (&$replies, $calls): MockResponse {
        $route = $method . ' ' . substr($url, strlen('https://spaceship.dev/api'));
        $headers = array_map(fn (array $lines) => explode(': ', $lines[0], 2)[1] ?? '', $request['normalized_headers']);
        $calls[] = [$route, json_decode((string) ($request['body'] ?? ''), true), $headers];
        $reply = $replies[$route] ?? match (true) {
            $route === 'PUT /v1/contacts' => ['contactId' => 'ct1'],
            (bool) preg_match('~^POST /v1/domains/[^/]+(/transfer|/renew)?$~', $route) => [202, null, ['spaceship-async-operationid' => 'op1']],
            $route === 'GET /v1/async-operations/op1' => ['status' => 'success', 'type' => 'domains_Create'],
            default => [],
        };
        if (array_is_list($reply) && is_array($reply[0] ?? null)) {
            $next = array_shift($reply);
            $replies[$route] = $reply === [] ? $next : $reply;
            $reply = $next;
        }
        [$code, $body, $extra] = is_int($reply[0] ?? null) ? $reply + [2 => []] : [200, $reply, []];

        return new MockResponse($body === null ? '' : (string) json_encode($body), ['http_code' => $code, 'response_headers' => $extra]);
    });
    $adapter = new class(['api_key' => 'key-1', 'api_secret' => 'ship-secret'], $client, $calls) extends Registrar_Adapter_Spaceship {
        public function __construct(array $options, private readonly HttpClientInterface $client, public ArrayObject $calls)
        {
            parent::__construct($options);
        }

        public function getHttpClient(): HttpClientInterface
        {
            return $this->client;
        }

        protected function pause(): void
        {
        }
    };
    $adapter->setLog(new Tests\Helpers\TestLogger());
    if ($testMode) {
        $adapter->enableTestMode();
    }

    return $adapter;
}

function spaceshipDomain(string $tld = '.com', int $years = 2): Registrar_Domain
{
    $contact = (new Registrar_Domain_Contact())->setFirstName('Ann')->setLastName('Lee')->setCompany('Acme Ltd')->setEmail('ann@example.com')
        ->setAddress1('1 Main St')->setCity('London')->setZip('N1 1AA')->setCountry('gb')->setTelCc('44')->setTel('20 7946 0000');

    return (new Registrar_Domain())->setSld('example')->setTld($tld)->setRegistrationPeriod($years)->setEpp('Ab1#cd')
        ->setNs1('ns1.example.net')->setNs2('NS2.example.net.')->setContactRegistrar($contact);
}

/** The routes called, in order. */
function spaceshipRoutes(Registrar_Adapter_Spaceship $adapter): array
{
    return array_column($adapter->calls->getArrayCopy(), 0);
}

/** The body sent on $route (the first such call). */
function spaceshipBody(Registrar_Adapter_Spaceship $adapter, string $route): mixed
{
    foreach ($adapter->calls as $call) {
        if ($call[0] === $route) {
            return $call[1];
        }
    }

    return null;
}

const SPACESHIP_CONTACTS = ['registrant' => 'ct1', 'admin' => 'ct1', 'tech' => 'ct1', 'billing' => 'ct1'];

test('the form has the API key and a secret API secret, both required', function (): void {
    $form = Registrar_Adapter_Spaceship::getConfig()['form'];

    expect(array_keys($form))->toBe(['api_key', 'api_secret'])
        ->and($form['api_secret'][1]['secret'])->toBeTrue()
        ->and(Registrar_Adapter_Spaceship::getSecretFields())->toBe(['api_secret'])
        ->and(fn () => new Registrar_Adapter_Spaceship([]))->toThrow(Registrar_Exception::class, 'API key')
        ->and(fn () => new Registrar_Adapter_Spaceship(['api_key' => 'key-1']))->toThrow(Registrar_Exception::class, 'API secret');
});

test('every call carries the key pair in the X-API-Key and X-API-Secret headers', function (): void {
    $adapter = spaceshipAdapter(['GET /v1/domains/example.com/available' => ['domain' => 'example.com', 'result' => 'taken', 'premiumPricing' => []]]);
    $adapter->isDomainAvailable(spaceshipDomain());

    expect($adapter->calls[0][2])->toMatchArray(['x-api-key' => 'key-1', 'x-api-secret' => 'ship-secret']);
});

test('a registration saves the contact, registers with auto-renewal off for every role, waits for the operation, then sets the name servers', function (): void {
    $adapter = spaceshipAdapter();

    expect($adapter->registerDomain(spaceshipDomain()))->toBeTrue()
        ->and(spaceshipRoutes($adapter))->toBe(['PUT /v1/contacts', 'POST /v1/domains/example.com', 'GET /v1/async-operations/op1', 'PUT /v1/domains/example.com/nameservers'])
        ->and(spaceshipBody($adapter, 'PUT /v1/contacts'))->toBe(['firstName' => 'Ann', 'lastName' => 'Lee', 'organization' => 'Acme Ltd', 'email' => 'ann@example.com',
            'address1' => '1 Main St', 'city' => 'London', 'country' => 'GB', 'postalCode' => 'N1 1AA', 'phone' => '+44.2079460000'])
        ->and(spaceshipBody($adapter, 'POST /v1/domains/example.com'))->toBe(['autoRenew' => false, 'years' => 2, 'privacyProtection' => ['level' => 'public', 'userConsent' => true], 'contacts' => SPACESHIP_CONTACTS])
        ->and(spaceshipBody($adapter, 'PUT /v1/domains/example.com/nameservers'))->toBe(['provider' => 'custom', 'hosts' => ['ns1.example.net', 'ns2.example.net']]);
});

test('a failed registration operation fails the order with Spaceship\'s details', function (): void {
    $adapter = spaceshipAdapter(['GET /v1/async-operations/op1' => [['status' => 'pending', 'type' => 'domains_Create'], ['status' => 'failed', 'type' => 'domains_Create', 'details' => ['reason' => 'Insufficient funds']]]]);

    expect(fn () => $adapter->registerDomain(spaceshipDomain()))->toThrow(Registrar_Exception::class, 'Insufficient funds')
        ->and(spaceshipRoutes($adapter))->not->toContain('PUT /v1/domains/example.com/nameservers');
});

test('a registration still running after the checks is reported done, the name servers left for staff in the log', function (): void {
    $adapter = spaceshipAdapter(['GET /v1/async-operations/op1' => ['status' => 'pending', 'type' => 'domains_Create']]);

    expect($adapter->registerDomain(spaceshipDomain()))->toBeTrue()
        ->and(array_count_values(spaceshipRoutes($adapter))['GET /v1/async-operations/op1'])->toBe(5)
        ->and(spaceshipRoutes($adapter))->not->toContain('PUT /v1/domains/example.com/nameservers')
        ->and(json_encode($adapter->getLog()->calls))->toContain('still in progress');
});

test('a .us registration saves the nexus category and purpose as us contact attributes', function (): void {
    $fields = Registrar_Adapter_Spaceship::getDomainFields('.us');
    $adapter = spaceshipAdapter(['PUT /v1/contacts/attributes' => ['contactId' => 'at1']]);
    $adapter->registerDomain(spaceshipDomain('.us', 1)->setFields(['nexus_category' => 'C11', 'app_purpose' => 'P3']));

    expect(array_keys($fields))->toBe(['nexus_category', 'app_purpose'])
        ->and(Registrar_Adapter_Spaceship::getDomainFields('.com'))->toBe([])
        ->and(spaceshipBody($adapter, 'PUT /v1/contacts/attributes'))->toBe(['type' => 'us', 'nexusCategory' => 'C11', 'appPurpose' => 'P3'])
        ->and(spaceshipBody($adapter, 'POST /v1/domains/example.us')['contacts'])->toBe(SPACESHIP_CONTACTS + ['attributes' => ['at1']]);
});

test('a transfer sends the code, the contact and auto-renewal off, and fails when its operation failed', function (): void {
    $adapter = spaceshipAdapter();
    expect($adapter->transferDomain(spaceshipDomain()->setPrivacyEnabled(true)))->toBeTrue()
        ->and(spaceshipBody($adapter, 'POST /v1/domains/example.com/transfer'))->toBe(['autoRenew' => false, 'privacyProtection' => ['level' => 'high', 'userConsent' => true], 'contacts' => SPACESHIP_CONTACTS, 'authCode' => 'Ab1#cd']);

    $noCode = spaceshipAdapter();
    $noCode->transferDomain(spaceshipDomain()->setEpp(''));
    expect(spaceshipBody($noCode, 'POST /v1/domains/example.com/transfer'))->not->toHaveKey('authCode');

    $failed = spaceshipAdapter(['GET /v1/async-operations/op1' => ['status' => 'failed', 'type' => 'domains_Transfer', 'details' => ['reason' => 'Wrong auth code']]]);
    expect(fn () => $failed->transferDomain(spaceshipDomain()))->toThrow(Registrar_Exception::class, 'Wrong auth code');
});

test('a renewal sends the years and the registry\'s current expiry, and waits for the operation', function (): void {
    $adapter = spaceshipAdapter(['GET /v1/domains/example.com' => ['name' => 'example.com', 'expirationDate' => '2027-03-04T10:00:00.000Z']]);

    expect($adapter->renewDomain(spaceshipDomain()))->toBeTrue()
        ->and(spaceshipRoutes($adapter))->toBe(['GET /v1/domains/example.com', 'POST /v1/domains/example.com/renew', 'GET /v1/async-operations/op1'])
        ->and(spaceshipBody($adapter, 'POST /v1/domains/example.com/renew'))->toBe(['years' => 2, 'currentExpirationDate' => '2027-03-04T10:00:00.000Z']);
});

test('test mode refuses registrations, transfers and renewals before any call: Spaceship has no test system', function (string $method): void {
    $adapter = spaceshipAdapter(testMode: true);

    expect(fn () => $adapter->{$method}(spaceshipDomain()))->toThrow(Registrar_Exception::class, 'no test system')
        ->and(spaceshipRoutes($adapter))->toBe([]);
})->with(['registerDomain', 'transferDomain', 'renewDomain']);

test('name servers are unique, lower case, without the trailing dot; fewer than two are refused', function (): void {
    $adapter = spaceshipAdapter();
    $adapter->modifyNs(spaceshipDomain()->setNs3('ns1.example.net'));
    expect(spaceshipBody($adapter, 'PUT /v1/domains/example.com/nameservers'))->toBe(['provider' => 'custom', 'hosts' => ['ns1.example.net', 'ns2.example.net']])
        ->and(fn () => spaceshipAdapter()->modifyNs(spaceshipDomain()->setNs2('')))->toThrow(Registrar_Exception::class, 'At least two name servers');
});

test('a contact change saves the contact and sets it for every role, keeping the domain\'s attributes', function (): void {
    $adapter = spaceshipAdapter(['GET /v1/domains/example.us' => ['contacts' => ['registrant' => 'old', 'attributes' => ['at1']]]]);
    $adapter->modifyContact(spaceshipDomain('.us'));

    expect(spaceshipRoutes($adapter))->toBe(['GET /v1/domains/example.us', 'PUT /v1/contacts', 'PUT /v1/domains/example.us/contacts'])
        ->and(spaceshipBody($adapter, 'PUT /v1/domains/example.us/contacts'))->toBe(SPACESHIP_CONTACTS + ['attributes' => ['at1']]);

    $plain = spaceshipAdapter(['GET /v1/domains/example.com' => ['contacts' => ['registrant' => 'old']]]);
    $plain->modifyContact(spaceshipDomain());
    expect(spaceshipBody($plain, 'PUT /v1/domains/example.com/contacts'))->toBe(SPACESHIP_CONTACTS);
});

test('domain details set the expiry and registration at noon UTC, the lock, privacy and name servers', function (): void {
    $adapter = spaceshipAdapter(['GET /v1/domains/example.com' => [
        'name' => 'example.com', 'registrationDate' => '2025-03-04T23:30:00.000Z', 'expirationDate' => '2027-03-04T23:30:00.000Z',
        'eppStatuses' => ['clientTransferProhibited'], 'privacyProtection' => ['contactForm' => true, 'level' => 'high'],
        'nameservers' => ['provider' => 'custom', 'hosts' => ['NS1.host.net', 'ns2.host.net', 'ns3.host.net']],
    ]]);
    $details = $adapter->getDomainDetails(spaceshipDomain()->setNs4('ns4.old.net'));

    expect($details->getExpirationTime())->toBe(gmmktime(12, 0, 0, 3, 4, 2027))
        ->and($details->getRegistrationTime())->toBe(gmmktime(12, 0, 0, 3, 4, 2025))
        ->and($details->getLocked())->toBeTrue()
        ->and($details->getPrivacyEnabled())->toBeTrue()
        ->and([$details->getNs1(), $details->getNs2(), $details->getNs3(), $details->getNs4()])->toBe(['ns1.host.net', 'ns2.host.net', 'ns3.host.net', '']);

    $unlocked = spaceshipAdapter(['GET /v1/domains/example.com' => ['expirationDate' => '2027-03-04T00:00:00.000Z', 'eppStatuses' => [], 'privacyProtection' => ['level' => 'public']]])->getDomainDetails(spaceshipDomain());
    expect($unlocked->getLocked())->toBeFalse()->and($unlocked->getPrivacyEnabled())->toBeFalse();
});

test('domain details without an expiry throw instead of keeping the stored one', function (): void {
    expect(fn () => spaceshipAdapter(['GET /v1/domains/example.com' => ['name' => 'example.com']])->getDomainDetails(spaceshipDomain()->setExpirationTime(1800000000)))
        ->toThrow(Registrar_Exception::class, 'did not report an expiry date');
});

test('the transfer code comes from the auth-code call; none is a clear error', function (): void {
    expect(spaceshipAdapter(['GET /v1/domains/example.com/transfer/auth-code' => ['authCode' => 'X9#y', 'expires' => '2027-01-01T00:00:00.000Z']])->getEpp(spaceshipDomain()))->toBe('X9#y')
        ->and(fn () => spaceshipAdapter()->getEpp(spaceshipDomain()))->toThrow(Registrar_Exception::class, 'did not return a transfer code');
});

test('lock and unlock set the transfer lock; privacy sets the level with the user\'s consent', function (): void {
    $adapter = spaceshipAdapter();
    $adapter->lock(spaceshipDomain());
    $adapter->unlock(spaceshipDomain());
    $adapter->enablePrivacyProtection(spaceshipDomain());
    $adapter->disablePrivacyProtection(spaceshipDomain());

    expect(array_map(fn (array $c) => [$c[0], $c[1]], $adapter->calls->getArrayCopy()))->toBe([
        ['PUT /v1/domains/example.com/transfer/lock', ['isLocked' => true]],
        ['PUT /v1/domains/example.com/transfer/lock', ['isLocked' => false]],
        ['PUT /v1/domains/example.com/privacy/preference', ['privacyLevel' => 'high', 'userConsent' => true]],
        ['PUT /v1/domains/example.com/privacy/preference', ['privacyLevel' => 'public', 'userConsent' => true]],
    ]);
});

test('a delete turns auto-renewal off (the delete call is not built yet); a failure is logged, the cancel goes on', function (): void {
    $adapter = spaceshipAdapter();
    expect($adapter->deleteDomain(spaceshipDomain()))->toBeTrue()
        ->and($adapter->calls[0][0])->toBe('PUT /v1/domains/example.com/autorenew')
        ->and($adapter->calls[0][1])->toBe(['isEnabled' => false]);

    $gone = spaceshipAdapter(['PUT /v1/domains/example.com/autorenew' => [404, ['detail' => 'Domain not found']]]);
    expect($gone->deleteDomain(spaceshipDomain()))->toBeTrue()
        ->and(json_encode($gone->getLog()->calls))->toContain('Domain not found');
});

test('availability: available, taken, premium, no answer, and a transfer Spaceship does not offer', function (): void {
    $check = fn (string $result, string $class = 'standard', array $prices = ['transfer' => []]) => spaceshipAdapter([
        'GET /v1/domains/example.com/available' => ['domain' => 'example.com', 'result' => $result, 'premiumPricing' => []],
        'GET /v1/domains/example.com/pricing' => ['domain' => 'example.com', 'priceClass' => $class, 'prices' => $prices],
    ]);

    expect($check('available')->isDomainAvailable(spaceshipDomain()))->toBeTrue()
        ->and($check('taken')->isDomainAvailable(spaceshipDomain()))->toBeFalse()
        ->and(fn () => $check('available', 'premium')->isDomainAvailable(spaceshipDomain()))->toThrow(Registrar_Exception::class, 'Premium domains cannot be registered.')
        ->and(fn () => $check('tldNotSupported')->isDomainAvailable(spaceshipDomain()))->toThrow(Registrar_Exception::class, 'did not say whether')
        ->and($check('taken')->isDomaincanBeTransferred(spaceshipDomain()))->toBeTrue()
        ->and($check('available')->isDomaincanBeTransferred(spaceshipDomain()))->toBeFalse()
        ->and(fn () => $check('taken', 'premium')->isDomaincanBeTransferred(spaceshipDomain()))->toThrow(Registrar_Exception::class, 'Premium domains cannot be transferred.')
        ->and($check('taken', 'standard', ['register' => []])->isDomaincanBeTransferred(spaceshipDomain()))->toBeFalse();
});

test('an internationalised name goes as its A-label, a contact in ASCII, and a transfer code "0" is kept', function (): void {
    $idn = spaceshipAdapter(['GET /v1/domains/xn--bcher-kva.com/available' => ['domain' => 'xn--bcher-kva.com', 'result' => 'taken', 'premiumPricing' => []]]);
    $idn->isDomainAvailable((new Registrar_Domain())->setSld('bücher')->setTld('.com'));
    $sharp = spaceshipAdapter(['GET /v1/domains/xn--strae-oqa.de/available' => ['domain' => 'xn--strae-oqa.de', 'result' => 'taken', 'premiumPricing' => []]]);
    $sharp->isDomainAvailable((new Registrar_Domain())->setSld('straße')->setTld('.de'));
    expect(spaceshipRoutes($idn))->toBe(['GET /v1/domains/xn--bcher-kva.com/available'])
        ->and(spaceshipRoutes($sharp))->toBe(['GET /v1/domains/xn--strae-oqa.de/available']);

    $adapter = spaceshipAdapter();
    $domain = spaceshipDomain()->setEpp('0');
    $domain->getContactRegistrar()->setFirstName('José')->setLastName('St. Müller')->setCity('Łódź')->setAddress1('Straße 5, App. #3/b');
    $adapter->transferDomain($domain);
    expect(spaceshipBody($adapter, 'PUT /v1/contacts'))->toMatchArray(['firstName' => 'Jose', 'lastName' => 'St Muller', 'city' => 'Lodz', 'address1' => 'Strasse 5, App. #3/b'])
        ->and(spaceshipBody($adapter, 'POST /v1/domains/example.com/transfer')['authCode'])->toBe('0');
});

test('prices: every TLD from the list, 25 per query, the one-year regular price, the rest left for the next call', function (): void {
    $price = fn (string $amount, ?array $period = ['value' => 1, 'unit' => 'y']) => array_filter(['period' => $period,
        'priceInDefaultCurrency' => ['finalPrice' => ['amount' => '1.00', 'currency' => 'USD'], 'regularPrice' => ['amount' => $amount, 'currency' => 'USD']]]);
    $names = array_map(fn (int $i) => ['name' => 't' . $i], range(1, 120));
    $adapter = spaceshipAdapter([
        'GET /v1/tlds?take=100&skip=0' => ['items' => array_slice($names, 0, 100), 'total' => 120],
        'GET /v1/tlds?take=100&skip=100' => ['items' => array_slice($names, 100), 'total' => 120],
        'POST /v1/tlds/pricing/query' => ['tlds' => [
            ['tld' => 't1', 'prices' => ['register' => [$price('20.00', ['value' => 2, 'unit' => 'y']), $price('10.50')], 'renew' => [$price('12.00')], 'transfer' => [$price('9.00', null)]]],
            ['tld' => 't2', 'errorCode' => 'tldNotSupported'],
        ]],
    ]);
    $result = $adapter->getTldPrices();

    expect(spaceshipBody($adapter, 'POST /v1/tlds/pricing/query'))->toBe(['tlds' => array_map(fn (int $i) => 't' . $i, range(1, 25))])
        ->and($result['currency'])->toBe('USD')
        ->and($result['prices'])->toBe(['.t1' => ['register' => 10.5, 'renew' => 12.0, 'transfer' => 9.0]])
        ->and($result['remaining'])->toHaveCount(95)
        ->and($result['remaining'][0])->toBe('.t26');

    $next = spaceshipAdapter(['POST /v1/tlds/pricing/query' => ['tlds' => []]]);
    expect($next->getTldPrices(['.com', '.CO.UK'])['remaining'])->toBe([])
        ->and(spaceshipRoutes($next))->toBe(['POST /v1/tlds/pricing/query'])
        ->and(spaceshipBody($next, 'POST /v1/tlds/pricing/query'))->toBe(['tlds' => ['com', 'co.uk']]);
});

test('the transfer status maps pending, completed and cancelled', function (string $status, string $expected): void {
    expect(spaceshipAdapter(['GET /v1/domains/example.com/transfer' => ['startedAt' => '2026-11-01T00:00:00.000Z', 'direction' => 'in', 'status' => $status]])->getTransferStatus(spaceshipDomain()))
        ->toBe(['status' => $expected, 'message' => $status]);
})->with([
    ['pending', Registrar_TransferStatus::PENDING],
    ['completed', Registrar_TransferStatus::COMPLETED],
    ['cancelled', Registrar_TransferStatus::FAILED],
]);

test('an API error is a registrar error with the detail and field messages, logged with the error code and without the secret', function (): void {
    $adapter = spaceshipAdapter(['PUT /v1/domains/example.com/nameservers' => [422, ['detail' => 'Validation failed.', 'data' => [['field' => 'hosts', 'details' => 'Unknown host']]], ['spaceship-error-code' => 'business.validation']]]);

    expect(fn () => $adapter->modifyNs(spaceshipDomain()))->toThrow(Registrar_Exception::class, 'Validation failed. hosts: Unknown host');
    expect(json_encode($adapter->getLog()->calls))->toContain('business.validation')->not->toContain('ship-secret');
});

test('with a default contact, admin, tech and billing get its saved contact ID and the registrant the client\'s (CF-240)', function (): void {
    $adapter = spaceshipAdapter(['PUT /v1/contacts' => [['contactId' => 'ct1'], ['contactId' => 'ct2']]]);
    $adapter->registerDomain(Tests\Helpers\withDefaultContact(spaceshipDomain()));
    $saved = array_values(array_filter($adapter->calls->getArrayCopy(), fn (array $call) => $call[0] === 'PUT /v1/contacts'));

    expect(array_map(fn (array $call) => $call[1]['email'], $saved))->toBe(['ann@example.com', 'domains@host.example'])
        ->and(spaceshipBody($adapter, 'POST /v1/domains/example.com')['contacts'])->toBe(['registrant' => 'ct1', 'admin' => 'ct2', 'tech' => 'ct2', 'billing' => 'ct2']);
});
