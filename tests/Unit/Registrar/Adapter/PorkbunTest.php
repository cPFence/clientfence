<?php

declare(strict_types=1);

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A Porkbun adapter answering "METHOD /path" (a purchase's dry run as "POST /path dry") from $replies ([http code, JSON body]
 * or the body alone); unanswered calls succeed, a dry run quotes one year at 973 cents. Records [route, body, headers].
 */
function porkbunAdapter(array $replies = [], array $options = ['api_key' => 'pk1_live', 'secret_api_key' => 'sk1_secret'], ?int $orderId = 42): Registrar_Adapter_Porkbun
{
    $client = new MockHttpClient(function (string $method, string $url, array $request) use ($replies): MockResponse {
        $body = json_decode((string) ($request['body'] ?? ''), true);
        $route = $method . ' ' . substr((string) parse_url($url, PHP_URL_PATH), strlen('/api/json/v3')) . (empty($body['dryRun']) ? '' : ' dry');
        $headers = array_map(fn (array $lines) => explode(': ', $lines[0], 2)[1] ?? '', $request['normalized_headers']);
        // The key pair in a write's body is recorded apart (index 3), so the bodies compare as the request's own fields.
        $GLOBALS['porkbun_calls'][] = [$route, is_array($body) ? array_diff_key($body, ['apikey' => 1, 'secretapikey' => 1]) : $body, $headers, is_array($body) ? array_intersect_key($body, ['apikey' => 1, 'secretapikey' => 1]) : []];
        $reply = $replies[$route] ?? (str_ends_with($route, ' dry') ? ['status' => 'SUCCESS', 'dryRun' => true, 'wouldSucceed' => true, 'duration' => 1, 'cost' => 973] : ['status' => 'SUCCESS']);
        [$code, $reply] = is_int($reply[0] ?? null) ? $reply : [200, $reply];

        return new MockResponse((string) json_encode($reply), ['http_code' => $code]);
    });
    $adapter = new class($options, $client) extends Registrar_Adapter_Porkbun {
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
    if ($orderId !== null) {
        $order = new Model_ClientOrder();
        $order->loadBean(new Tests\Helpers\DummyBean());
        $order->id = $orderId;
        $adapter->setOrder($order);
    }
    $GLOBALS['porkbun_calls'] = [];

    return $adapter;
}

function porkbunDomain(): Registrar_Domain
{
    $contact = (new Registrar_Domain_Contact())->setFirstName('Ann')->setLastName('Lee')->setEmail('ann@example.com')
        ->setAddress1('1 Main St')->setCity('London')->setZip('N1 1AA')->setCountry('gb')->setTelCc('44')->setTel('020 7946 0000');

    return (new Registrar_Domain())->setSld('example')->setTld('.com')->setRegistrationPeriod(1)->setPrivacyEnabled(true)
        ->setNs1('ns1.example.net')->setNs2('NS2.example.net')->setContactRegistrar($contact)->setExpirationTime(1800000000);
}

test('a registration is quoted by a dry run, bought at the quoted cost with an idempotency key, then gets the client\'s contact and name servers', function (): void {
    porkbunAdapter()->registerDomain(porkbunDomain());

    [$dry, $create, $contacts, $ns] = $GLOBALS['porkbun_calls'];
    expect(array_column($GLOBALS['porkbun_calls'], 0))->toBe(['POST /domain/create/example.com dry', 'POST /domain/create/example.com', 'POST /domain/updateContacts/example.com', 'POST /domain/updateNs/example.com'])
        ->and($dry[1])->toBe(['agreeToTerms' => 'yes', 'whoisPrivacy' => true, 'cost' => 0, 'dryRun' => true])
        ->and($dry[2])->not->toHaveKey('idempotency-key')
        ->and($create[1])->toBe(['agreeToTerms' => 'yes', 'whoisPrivacy' => true, 'cost' => 973])
        ->and($create[2])->toMatchArray(['x-api-key' => 'pk1_live', 'x-secret-api-key' => 'sk1_secret'])
        ->and($create[2]['idempotency-key'])->toStartWith('clientfence-')
        ->and($contacts[1])->toBe(['contact' => ['firstName' => 'Ann', 'lastName' => 'Lee', 'organization' => '', 'address1' => '1 Main St', 'address2' => '',
            'city' => 'London', 'state' => '', 'postalCode' => 'N1 1AA', 'country' => 'GB', 'phone' => '2079460000', 'phoneCountryCode' => '44', 'email' => 'ann@example.com']])
        ->and($ns[1])->toBe(['ns' => ['ns1.example.net', 'ns2.example.net']])
        ->and($create[3])->toBe(['apikey' => 'pk1_live', 'secretapikey' => 'sk1_secret']);
});

test('a retried purchase of the same order sends the same idempotency key; another order, cost or renewal term gets a new one', function (): void {
    $key = function (string $action, ?int $order = 42, int $cost = 973, int $expiry = 1800000000): string {
        $adapter = porkbunAdapter(['POST /domain/' . $action . '/example.com dry' => ['status' => 'SUCCESS', 'wouldSucceed' => true, 'duration' => 1, 'cost' => $cost]], orderId: $order);
        $action === 'renew' ? $adapter->renewDomain(porkbunDomain()->setExpirationTime($expiry)) : $adapter->registerDomain(porkbunDomain());

        return $GLOBALS['porkbun_calls'][1][2]['idempotency-key'];
    };

    expect($key('create'))->toBe($key('create'))
        ->and($key('create', 43))->not->toBe($key('create'))
        ->and($key('create', cost: 1099))->not->toBe($key('create'))
        ->and($key('renew'))->toBe($key('renew'))
        ->and($key('renew'))->not->toBe($key('create'))
        ->and($key('renew', expiry: 1831536000))->not->toBe($key('renew'));
});

test('nothing is bought when the dry run would fail or Porkbun sells another term than the order\'s', function (): void {
    $short = porkbunAdapter(['POST /domain/create/example.com dry' => ['status' => 'SUCCESS', 'wouldSucceed' => false, 'duration' => 1, 'cost' => 973, 'message' => 'Not enough account credit.']]);
    expect(fn () => $short->registerDomain(porkbunDomain()))->toThrow(Registrar_Exception::class, 'Not enough account credit.')
        ->and(array_column($GLOBALS['porkbun_calls'], 0))->toBe(['POST /domain/create/example.com dry']);

    $twoYears = porkbunAdapter();
    expect(fn () => $twoYears->renewDomain(porkbunDomain()->setRegistrationPeriod(2)))->toThrow(Registrar_Exception::class, 'not for the ordered term (in years: 2)')
        ->and(array_column($GLOBALS['porkbun_calls'], 0))->toBe(['POST /domain/renew/example.com dry']);
});

test('a refused request is a registrar error with Porkbun\'s message, and the secret key stays out of the log', function (): void {
    $adapter = porkbunAdapter(['POST /domain/renew/example.com' => [400, ['status' => 'ERROR', 'code' => 'RENEWAL_TOO_SOON', 'message' => 'Renewed within the last 30 days.']]]);

    expect(fn () => $adapter->renewDomain(porkbunDomain()))->toThrow(Registrar_Exception::class, 'Renewed within the last 30 days.');
    expect($GLOBALS['porkbun_calls'][1][1])->toBe(['cost' => 973])
        ->and(json_encode($adapter->getLog()->calls))->toContain('RENEWAL_TOO_SOON')->not->toContain('sk1_secret');
});

test('a failed follow-up after the registration is logged, not thrown, so the order does not register again', function (): void {
    $adapter = porkbunAdapter(['POST /domain/updateNs/example.com' => [400, ['status' => 'ERROR', 'code' => 'API_ACCESS_DISABLED', 'message' => 'Not opted in.']]]);

    expect($adapter->registerDomain(porkbunDomain()))->toBeTrue()
        ->and(json_encode($adapter->getLog()->calls))->toContain('Not opted in.');
});

test('a transfer needs the code and buys at the quoted transfer cost', function (): void {
    $adapter = porkbunAdapter(['POST /domain/transfer/example.com dry' => ['status' => 'SUCCESS', 'wouldSucceed' => true, 'duration' => 1, 'cost' => 899]]);
    expect(fn () => $adapter->transferDomain(porkbunDomain()))->toThrow(Registrar_Exception::class, 'authorization code')
        ->and($GLOBALS['porkbun_calls'])->toBe([]);

    $adapter->transferDomain(porkbunDomain()->setEpp('abc123'));
    expect($GLOBALS['porkbun_calls'][0][1])->toBe(['authCode' => 'abc123', 'cost' => 0, 'dryRun' => true])
        ->and($GLOBALS['porkbun_calls'][1][1])->toBe(['authCode' => 'abc123', 'cost' => 899])
        ->and($GLOBALS['porkbun_calls'][1][2])->toHaveKey('idempotency-key');
});

test('availability: available, taken, premium and TLDs the API cannot register', function (): void {
    $check = fn (array $response, array $rules = ['status' => 'SUCCESS', 'apiRegisterable' => true]) => porkbunAdapter([
        'POST /domain/checkDomain/example.com' => ['status' => 'SUCCESS', 'response' => $response],
        'GET /domain/getRegistrationRequirements/com' => $rules,
    ]);

    expect($check(['avail' => 'yes', 'premium' => 'no'])->isDomainAvailable(porkbunDomain()))->toBeTrue()
        ->and($GLOBALS['porkbun_calls'][0][1])->toBe([])
        ->and($check(['avail' => 'no', 'premium' => 'no'])->isDomainAvailable(porkbunDomain()))->toBeFalse()
        ->and(fn () => $check(['avail' => 'yes', 'premium' => 'yes'])->isDomainAvailable(porkbunDomain()))->toThrow(Registrar_Exception::class, 'Premium')
        ->and(fn () => $check(['avail' => 'yes', 'premium' => 'no'], ['status' => 'SUCCESS', 'apiRegisterable' => false, 'notApiRegisterableReason' => 'Registry eligibility.'])->isDomainAvailable(porkbunDomain()))
        ->toThrow(Registrar_Exception::class, 'Registry eligibility.')
        ->and($check(['avail' => 'no', 'premium' => 'no'])->isDomaincanBeTransferred(porkbunDomain()))->toBeTrue()
        ->and($check(['avail' => 'no', 'premium' => 'no', 'inYourAccount' => true])->isDomaincanBeTransferred(porkbunDomain()))->toBeFalse()
        ->and($check(['avail' => 'yes', 'premium' => 'no'])->isDomaincanBeTransferred(porkbunDomain()))->toBeFalse()
        ->and(fn () => $check(['avail' => 'no', 'premium' => 'yes'])->isDomaincanBeTransferred(porkbunDomain()))->toThrow(Registrar_Exception::class, 'Premium domains cannot be transferred');
});

test('a contact change on an address-checked TLD is sent again with the address as entered', function (): void {
    $adapter = porkbunAdapter(['POST /domain/updateContacts/example.com' => [400, ['status' => 'ERROR', 'code' => 'ADDRESS_VALIDATION_REQUIRED', 'message' => 'Check the address.']]]);

    // The mock answers both sends the same, so the second refusal is the registrar error.
    expect(fn () => $adapter->modifyContact(porkbunDomain()))->toThrow(Registrar_Exception::class, 'Check the address.')
        ->and($GLOBALS['porkbun_calls'])->toHaveCount(2)
        ->and($GLOBALS['porkbun_calls'][1][1]['addressValidationChoice'])->toBe('use_as_entered');

    porkbunAdapter()->modifyContact(porkbunDomain());
    expect($GLOBALS['porkbun_calls'])->toHaveCount(1);
});

test('domain details come from domain/get, getNs and getContacts; Porkbun auto-renewal is turned off', function (): void {
    $adapter = porkbunAdapter([
        'GET /domain/get/example.com' => ['status' => 'SUCCESS', 'domain' => ['createDate' => '2024-01-02 10:00:00', 'expireDate' => '2027-01-02 10:00:00', 'securityLock' => 1, 'whoisPrivacy' => 0, 'autoRenew' => 1]],
        'GET /domain/getNs/example.com' => ['status' => 'SUCCESS', 'ns' => ['NS2.EXAMPLE.NET', 'ns1.example.net']],
        'GET /domain/getContacts/example.com' => ['status' => 'SUCCESS', 'contacts' => ['registrant' => ['firstName' => 'Bob', 'lastName' => 'Ray', 'email' => 'bob@example.com', 'country' => 'us', 'phone' => '5551234567', 'phoneCountryCode' => '1']]],
    ]);
    $details = $adapter->getDomainDetails(porkbunDomain());

    expect($details->getRegistrationTime())->toBe(strtotime('2024-01-02 10:00:00 UTC'))
        ->and($details->getExpirationTime())->toBe(strtotime('2027-01-02 10:00:00 UTC'))
        ->and($details->getLocked())->toBeTrue()
        ->and($details->getPrivacyEnabled())->toBeFalse()
        ->and([$details->getNs1(), $details->getNs2()])->toBe(['ns2.example.net', 'ns1.example.net'])
        ->and([$details->getContactRegistrar()->getEmail(), $details->getContactRegistrar()->getCountry()])->toBe(['bob@example.com', 'US'])
        ->and(array_column($GLOBALS['porkbun_calls'], 0))->toContain('POST /domain/updateAutoRenew/example.com')
        ->and($GLOBALS['porkbun_calls'][1][1])->toBe(['status' => 'off']);
});

test('the transfer status maps Porkbun\'s transfer status: DONE completed, CANCELED failed, anything else pending', function (string $text, string $status): void {
    $adapter = porkbunAdapter(['GET /domain/getTransfer/example.com' => ['status' => 'SUCCESS', 'transfer' => ['status' => $text, 'statusDescription' => 'Details.']]]);

    expect($adapter->getTransferStatus(porkbunDomain()))->toBe(['status' => $status, 'message' => $text . ' Details.']);
})->with([
    'done' => ['DONE', Registrar_TransferStatus::COMPLETED],
    'canceled' => ['CANCELED', Registrar_TransferStatus::FAILED],
    'pending' => ['PENDINGTRANSFER', Registrar_TransferStatus::PENDING],
    'unknown' => ['SOMETHING_NEW', Registrar_TransferStatus::PENDING],
]);

test('the price list reads pricing/get in USD, skips Handshake names and keeps the asked TLDs', function (): void {
    $adapter = porkbunAdapter(['GET /pricing/get' => ['status' => 'SUCCESS', 'pricing' => [
        'com' => ['registration' => '9.73', 'renewal' => '10.37', 'transfer' => '10.37'],
        'co.uk' => ['registration' => '5.13', 'renewal' => '5.13', 'transfer' => ''],
        'hns' => ['registration' => '1.00', 'renewal' => '1.00', 'transfer' => '1.00', 'specialType' => 'handshake'],
    ]]]);

    expect($adapter->getTldPrices())->toBe(['currency' => 'USD', 'prices' => [
        '.com' => ['register' => 9.73, 'renew' => 10.37, 'transfer' => 10.37],
        '.co.uk' => ['register' => 5.13, 'renew' => 5.13, 'transfer' => null],
    ], 'remaining' => []])
        ->and(array_keys($adapter->getTldPrices(['.co.uk', '.net'])['prices']))->toBe(['.co.uk']);
});

test('internationalized names and .uk transfers are refused before the client pays', function (): void {
    $idn = porkbunAdapter(['POST /domain/checkDomain/example.com' => ['status' => 'SUCCESS', 'response' => ['avail' => 'yes', 'idnLanguages' => [['name' => 'French', 'code' => 'FRE']]]]]);
    expect(fn () => $idn->isDomainAvailable(porkbunDomain()))->toThrow(Registrar_Exception::class, 'Internationalized domain names');
    $uk = porkbunAdapter();
    expect(fn () => $uk->isDomaincanBeTransferred(porkbunDomain()->setTld('.co.uk')))->toThrow(Registrar_Exception::class, 'cannot be transferred')
        ->and($GLOBALS['porkbun_calls'])->toBe([]);
});

test('a retry after a lost reply goes on when Porkbun took the first request, and fails otherwise', function (): void {
    $refused = [400, ['status' => 'ERROR', 'code' => 'DOMAIN_NOT_AVAILABLE', 'message' => 'Not available.']];
    $owned = porkbunAdapter(['POST /domain/create/example.com dry' => $refused, 'POST /domain/checkDomain/example.com' => ['status' => 'SUCCESS', 'response' => ['avail' => 'no', 'inYourAccount' => true]]]);
    expect($owned->registerDomain(porkbunDomain()))->toBeTrue()
        ->and(array_column($GLOBALS['porkbun_calls'], 0))->toBe(['POST /domain/create/example.com dry', 'POST /domain/checkDomain/example.com', 'POST /domain/updateContacts/example.com', 'POST /domain/updateNs/example.com']);

    $moving = porkbunAdapter(['POST /domain/transfer/example.com dry' => $refused, 'POST /domain/checkDomain/example.com' => ['status' => 'SUCCESS', 'response' => ['avail' => 'no']],
        'GET /domain/getTransfer/example.com' => ['status' => 'SUCCESS', 'transfer' => ['status' => 'PENDINGTRANSFER']]]);
    expect($moving->transferDomain(porkbunDomain()->setEpp('abc123')))->toBeTrue()
        ->and(array_column($GLOBALS['porkbun_calls'], 0))->not->toContain('POST /domain/transfer/example.com');

    $taken = porkbunAdapter(['POST /domain/create/example.com dry' => $refused, 'POST /domain/checkDomain/example.com' => ['status' => 'SUCCESS', 'response' => ['avail' => 'no']]]);
    expect(fn () => $taken->registerDomain(porkbunDomain()))->toThrow(Registrar_Exception::class, 'Not available.');
});

test('lock, privacy and the transfer code are not in the API; a cancel turns auto-renewal off and survives a refusal', function (): void {
    $adapter = porkbunAdapter(['POST /domain/updateAutoRenew/example.com' => [404, ['status' => 'ERROR', 'code' => 'DOMAIN_NOT_FOUND', 'message' => 'Not in this account.']]]);
    foreach (['lock', 'unlock', 'enablePrivacyProtection', 'disablePrivacyProtection', 'getEpp'] as $action) {
        expect(fn () => $adapter->{$action}(porkbunDomain()))->toThrow(Registrar_Exception::class, 'does not support this action');
    }

    expect($adapter->deleteDomain(porkbunDomain()))->toBeTrue()
        ->and($GLOBALS['porkbun_calls'][0][0])->toBe('POST /domain/updateAutoRenew/example.com');
});

test('test mode refuses a live key; both keys are required', function (): void {
    $live = porkbunAdapter();
    $live->enableTestMode();
    expect(fn () => $live->renewDomain(porkbunDomain()))->toThrow(Registrar_Exception::class, 'sandbox API key')
        ->and($GLOBALS['porkbun_calls'])->toBe([]);

    $sandbox = porkbunAdapter(options: ['api_key' => 'pk1_sb_test', 'secret_api_key' => 'sk1_sb_test']);
    $sandbox->enableTestMode();
    expect($sandbox->renewDomain(porkbunDomain()))->toBeTrue()
        ->and(fn () => new Registrar_Adapter_Porkbun(['api_key' => 'pk1_live']))->toThrow(Registrar_Exception::class, 'secret API key');
});
