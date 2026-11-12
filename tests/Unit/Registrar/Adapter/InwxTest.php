<?php

declare(strict_types=1);

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** An INWX adapter answering each method from $replies (resData, or a full reply with a code), recording each call. */
function inwxAdapter(array $replies = []): Registrar_Adapter_Inwx
{
    $replies += ['contact.create' => ['id' => 501]];
    $client = new MockHttpClient(function (string $method, string $url, array $options) use ($replies): MockResponse {
        $body = json_decode((string) ($options['body'] ?? ''), true);
        $GLOBALS['inwx_calls'][] = [$body['method'], $body['params'], $url, $options['normalized_headers']['cookie'][0] ?? ''];
        $reply = $replies[$body['method']] ?? [];

        return new MockResponse((string) json_encode(isset($reply['code']) ? $reply : ['code' => 1000, 'msg' => 'Command completed successfully', 'resData' => $reply]),
            ['response_headers' => $body['method'] === 'account.login' ? ['Set-Cookie: domrobot=sess-1; path=/; HttpOnly'] : []]);
    });
    $adapter = new class(['username' => 'reseller', 'password' => 'secret-pw'], $client) extends Registrar_Adapter_Inwx {
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
    $GLOBALS['inwx_calls'] = [];

    return $adapter;
}

function inwxDomain(string $tld = '.com'): Registrar_Domain
{
    $contact = (new Registrar_Domain_Contact())->setFirstName('Ann')->setLastName('Lee')->setEmail('ann@example.com')
        ->setAddress1('12 Main St')->setCity('London')->setZip('N1 1AA')->setCountry('gb')->setTelCc('44')->setTel('020 7946 0000');

    return (new Registrar_Domain())->setSld('example')->setTld($tld)->setRegistrationPeriod(2)
        ->setNs1('ns1.example.net')->setNs2('ns2.example.net')->setContactRegistrar($contact);
}

function inwxCall(string $method): array
{
    foreach ($GLOBALS['inwx_calls'] as $call) {
        if ($call[0] === $method) {
            return $call;
        }
    }

    throw new RuntimeException('No call ' . $method);
}

test('a registration signs in, makes the contact and registers to expire, with the client\'s name servers', function (): void {
    inwxAdapter()->registerDomain(inwxDomain());

    expect(array_column($GLOBALS['inwx_calls'], 0))->toBe(['account.login', 'contact.create', 'domain.create'])
        ->and(inwxCall('account.login')[1])->toBe(['user' => 'reseller', 'pass' => 'secret-pw'])
        ->and(inwxCall('contact.create')[3])->toBe('Cookie: domrobot=sess-1')
        ->and(inwxCall('contact.create')[1])->toMatchArray(['type' => 'PERSON', 'name' => 'Ann Lee', 'cc' => 'GB', 'voice' => '+44.2079460000', 'extData' => ['PARSE-VOICE' => true]])
        ->and(inwxCall('domain.create')[1])->toBe(['domain' => 'example.com', 'period' => '2Y', 'ns' => ['ns1.example.net', 'ns2.example.net'], 'renewalMode' => 'AUTOEXPIRE',
            'registrant' => 501, 'admin' => 501, 'tech' => 501, 'billing' => 501]);
});

test('.us and .eu answers and privacy go as extData; a pending transfer (1001) succeeds and sends the code only when there is one', function (): void {
    $adapter = inwxAdapter(['domain.transfer' => ['code' => 1001, 'msg' => 'Command completed successfully; action pending']]);
    $adapter->transferDomain(inwxDomain('.us')->setEpp('abc')->setPrivacyEnabled(true)->setFields(['nexus_category' => 'C11', 'app_purpose' => 'P3']));
    expect(inwxCall('domain.transfer')[1])->toMatchArray(['authCode' => 'abc', 'renewalMode' => 'AUTOEXPIRE', 'extData' => ['US-NEXUS-CATEGORY' => 'C11', 'US-NEXUS-APPPURPOSE' => 'P3', 'WHOIS-PROTECTION' => true]]);

    $GLOBALS['inwx_calls'] = [];
    $adapter->transferDomain(inwxDomain('.uk'));
    $adapter->registerDomain(inwxDomain('.eu')->setFields(['eu_citizenship' => 'DE']));
    $adapter->registerDomain(inwxDomain('.co.at'));
    expect(inwxCall('domain.transfer')[1])->not->toHaveKey('authCode')->not->toHaveKey('extData')
        ->and(inwxCall('domain.create')[1]['extData'])->toBe(['EU-COUNTRY-OF-CITIZENSHIP' => 'DE'])
        ->and(array_column(array_values(array_filter($GLOBALS['inwx_calls'], fn ($c) => $c[0] === 'domain.create')), 1)[1]['renewalMode'])->toBe('AUTODELETE')
        ->and(inwxCall('domain.transfer')[1]['renewalMode'])->toBe('AUTOEXPIRE')
        ->and(array_column($GLOBALS['inwx_calls'], 0))->not->toContain('account.login');
});

test('a renewal sends the registry\'s current expiry date', function (): void {
    $adapter = inwxAdapter(['domain.info' => ['exDate' => '2027-03-04T00:00:00+01:00']]);
    $adapter->renewDomain(inwxDomain());
    expect(inwxCall('domain.renew')[1])->toBe(['domain' => 'example.com', 'period' => '2Y', 'expiration' => '2027-03-04']);

    $adapter = inwxAdapter(['domain.info' => ['exDate' => ['timestamp' => mktime(0, 0, 0, 5, 6, 2027)]]]);
    $adapter->renewDomain(inwxDomain());
    expect(inwxCall('domain.renew')[1]['expiration'])->toBe('2027-05-06');
});

test('a refused request is a registrar error with INWX\'s reason; a failed sign-in stops before any other call', function (): void {
    expect(fn () => inwxAdapter(['domain.renew' => ['code' => 2104, 'msg' => 'Billing failure', 'reason' => 'Insufficient funds'], 'domain.info' => ['exDate' => '2027-03-04']])->renewDomain(inwxDomain()))
        ->toThrow(Registrar_Exception::class, 'Billing failure: Insufficient funds');

    $adapter = inwxAdapter(['account.login' => ['code' => 2200, 'msg' => 'Authentication error']]);
    expect(fn () => $adapter->lock(inwxDomain()))->toThrow(Registrar_Exception::class, 'Authentication error')
        ->and(array_column($GLOBALS['inwx_calls'], 0))->toBe(['account.login']);
});

test('an account with two-factor sign-in is refused with a clear message', function (): void {
    expect(fn () => inwxAdapter(['account.login' => ['tfa' => 'GOOGLE-AUTH']])->lock(inwxDomain()))->toThrow(Registrar_Exception::class, 'two-factor');
});

test('availability: free, taken and premium names', function (): void {
    expect(inwxAdapter(['domain.check' => ['domain' => [['domain' => 'example.com', 'avail' => 1]]]])->isDomainAvailable(inwxDomain()))->toBeTrue()
        ->and(inwxAdapter(['domain.check' => ['domain' => [['domain' => 'example.com', 'avail' => 0]]]])->isDomainAvailable(inwxDomain()))->toBeFalse()
        ->and(inwxAdapter(['domain.check' => ['domain' => [['domain' => 'example.com', 'avail' => 0]]]])->isDomaincanBeTransferred(inwxDomain()))->toBeTrue()
        ->and(inwxCall('domain.check')[1])->toBe(['domain' => ['example.com']])
        ->and(fn () => inwxAdapter(['domain.check' => ['domain' => [['domain' => 'example.com', 'avail' => 1, 'premium' => ['currency' => 'EUR']]]]])->isDomainAvailable(inwxDomain()))
        ->toThrow(Registrar_Exception::class, 'Premium')
        ->and(fn () => inwxAdapter(['domain.check' => ['domain' => [['domain' => 'example.com', 'avail' => 0, 'premium' => ['currency' => 'EUR']]]]])->isDomaincanBeTransferred(inwxDomain()))
        ->toThrow(Registrar_Exception::class, 'Premium domains cannot be transferred');
});

test('contact, name server, lock and privacy changes update the domain', function (): void {
    $adapter = inwxAdapter(['contact.create' => ['id' => 777]]);
    $adapter->modifyContact(inwxDomain());
    $adapter->modifyNs(inwxDomain()->setNs3('ns3.example.net'));
    $adapter->lock(inwxDomain());
    $adapter->disablePrivacyProtection(inwxDomain());

    expect(array_column(array_filter($GLOBALS['inwx_calls'], fn ($c) => $c[0] === 'domain.update'), 1))->toBe([
        ['domain' => 'example.com', 'registrant' => 777, 'admin' => 777, 'tech' => 777, 'billing' => 777],
        ['domain' => 'example.com', 'ns' => ['ns1.example.net', 'ns2.example.net', 'ns3.example.net']],
        ['domain' => 'example.com', 'transferLock' => 1],
        ['domain' => 'example.com', 'extData' => ['WHOIS-PROTECTION' => false]],
    ]);
});

test('domain details and the transfer code come from domain.info', function (): void {
    $adapter = inwxAdapter(['domain.info' => ['crDate' => ['timestamp' => strtotime('2024-01-02 10:00:00 UTC')], 'exDate' => '2027-01-02T00:00:00Z', 'transferLock' => true,
        'ns' => ['NS1.EXAMPLE.NET', 'ns2.example.net'], 'authCode' => 'Xy9#k', 'extData' => ['WHOIS-PROTECTION' => true]]]);
    $details = $adapter->getDomainDetails(inwxDomain());

    expect(gmdate('Y-m-d', $details->getRegistrationTime()))->toBe('2024-01-02')
        ->and(gmdate('Y-m-d', $details->getExpirationTime()))->toBe('2027-01-02')
        ->and($details->getLocked())->toBeTrue()
        ->and($details->getPrivacyEnabled())->toBeTrue()
        ->and([$details->getNs1(), $details->getNs2()])->toBe(['ns1.example.net', 'ns2.example.net'])
        ->and($adapter->getEpp(inwxDomain()))->toBe('Xy9#k');
});

test('test mode uses OT&E; a cancel sends nothing', function (): void {
    $adapter = inwxAdapter();
    $adapter->enableTestMode();
    $adapter->unlock(inwxDomain());
    expect($GLOBALS['inwx_calls'][0][2])->toBe('https://api.ote.domrobot.com/jsonrpc/');

    $GLOBALS['inwx_calls'] = [];
    expect($adapter->deleteDomain(inwxDomain()))->toBeTrue()->and($GLOBALS['inwx_calls'])->toBe([]);
});

test('the order form asks the .us and .eu questions only', function (): void {
    expect(array_keys(Registrar_Adapter_Inwx::getDomainFields('.us', 'transfer')))->toBe(['nexus_category', 'app_purpose'])
        ->and(Registrar_Adapter_Inwx::getDomainFields('.eu')['eu_citizenship']['required'] ?? false)->toBeFalse()
        ->and(Registrar_Adapter_Inwx::getDomainFields('.com'))->toBe([]);
});

test('the price list reads the yearly prices without VAT from domain.getPrices; a missing action is null', function (): void {
    $adapter = inwxAdapter(['domain.getPrices' => ['price' => [
        ['tld' => 'com', 'currency' => 'EUR', 'createPrice' => 9.4, 'renewalPrice' => 10.9, 'transferPrice' => 9.4, 'createPeriod' => 1, 'promo' => ['createPrice' => 1.0]],
        ['tld' => 'de', 'currency' => 'EUR', 'createPrice' => '3.90', 'renewalPrice' => '3.90'],
    ]]]);

    expect($adapter->getTldPrices())->toBe(['currency' => 'EUR', 'prices' => [
        '.com' => ['register' => 9.4, 'renew' => 10.9, 'transfer' => 9.4],
        '.de' => ['register' => 3.9, 'renew' => 3.9, 'transfer' => null],
    ], 'remaining' => []])
        ->and(inwxCall('domain.getPrices')[1])->toBe(['vat' => false])
        ->and(array_keys($adapter->getTldPrices(['.de'])['prices']))->toBe(['.de']);
});

test('the transfer status reads domain.info: OK completed, a domain no longer there (2303) failed, a transfer state pending', function (array $reply, string $status, string $message): void {
    expect(inwxAdapter(['domain.info' => $reply])->getTransferStatus(inwxDomain()))->toBe(['status' => $status, 'message' => $message])
        ->and(inwxCall('domain.info')[1])->toBe(['domain' => 'example.com']);
})->with([
    'completed' => [['status' => 'OK'], Registrar_TransferStatus::COMPLETED, 'OK'],
    'failed' => [['code' => 2303, 'msg' => 'Object does not exist'], Registrar_TransferStatus::FAILED, 'INWX refused the request: Object does not exist'],
    'pending' => [['status' => 'TRANSFER REQUESTED'], Registrar_TransferStatus::PENDING, 'TRANSFER REQUESTED'],
    'unknown' => [['status' => 'SOMETHING NEW'], Registrar_TransferStatus::PENDING, 'SOMETHING NEW'],
]);

test('a refused transfer status request other than 2303 is a registrar error, checked again later', function (): void {
    expect(fn () => inwxAdapter(['domain.info' => ['code' => 2400, 'msg' => 'Command failed']])->getTransferStatus(inwxDomain()))
        ->toThrow(Registrar_Exception::class, 'Command failed');
});
