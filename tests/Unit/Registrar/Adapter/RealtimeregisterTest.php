<?php

declare(strict_types=1);

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** A Realtime Register adapter answering "METHOD /path" (contact handles as *) from $replies ([http code, JSON body]), recording each call. */
function realtimeregisterAdapter(array $replies = []): Registrar_Adapter_Realtimeregister
{
    $client = new MockHttpClient(function (string $method, string $url, array $options) use ($replies): MockResponse {
        $path = (string) preg_replace('#^/v2#', '', (string) parse_url($url, PHP_URL_PATH));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $route = $method . ' ' . preg_replace('#/contacts/[a-z]+-[0-9a-f]{12}#', '/contacts/*', $path);
        $GLOBALS['realtimeregister_calls'][] = [$route, $method === 'GET' ? $query : json_decode((string) ($options['body'] ?? ''), true), $url, $options['normalized_headers']['authorization'][0] ?? '', $path];
        [$code, $body] = $replies[$route] ?? [str_starts_with($route, 'POST /customers/') ? 201 : 200, []];

        return new MockResponse($body === [] && $code === 201 ? '' : (string) json_encode($body), ['http_code' => $code]);
    });
    $adapter = new class(['customer' => 'acme', 'api_key' => 'live-secret'], $client) extends Registrar_Adapter_Realtimeregister {
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
    $GLOBALS['realtimeregister_calls'] = [];

    return $adapter;
}

function realtimeregisterDomain(string $tld = '.com'): Registrar_Domain
{
    $contact = (new Registrar_Domain_Contact())->setFirstName('Ann')->setLastName('Lee')->setCompany('Acme Ltd')->setEmail('ann@example.com')
        ->setAddress1('1 Main St')->setCity('London')->setZip('N1 1AA')->setCountry('gb')->setTelCc('44')->setTel('020 7946 0000');

    return (new Registrar_Domain())->setSld('example')->setTld($tld)->setRegistrationPeriod(2)
        ->setNs1('ns1.example.net')->setNs2('NS2.example.net')->setContactRegistrar($contact);
}

/** @return list<array> the recorded calls of one route */
function realtimeregisterCalls(string $route): array
{
    return array_values(array_filter($GLOBALS['realtimeregister_calls'], fn (array $call) => $call[0] === $route));
}

test('a .us registration creates four contacts, adds the nexus properties to the registrant and orders in months without auto-renewal', function (): void {
    $adapter = realtimeregisterAdapter(['POST /domains/example.us' => [201, ['domainName' => 'example.us', 'expiryDate' => '2028-01-02T00:00:00Z', 'status' => ['OK']]]]);
    $adapter->registerDomain(realtimeregisterDomain('.us')->setFields(['nexus_category' => 'C11', 'app_purpose' => 'P3']));

    $contacts = realtimeregisterCalls('POST /customers/acme/contacts/*');
    expect($contacts)->toHaveCount(4)
        ->and($contacts[0][1])->toBe(['name' => 'Ann Lee', 'organization' => 'Acme Ltd', 'addressLine' => ['1 Main St'], 'postalCode' => 'N1 1AA',
            'city' => 'London', 'country' => 'GB', 'email' => 'ann@example.com', 'voice' => '+44.2079460000'])
        ->and(array_map(fn (array $call) => $call[1]['organization'] ?? null, array_slice($contacts, 1)))->toBe([null, null, null])
        ->and($contacts[0][3])->toBe('Authorization: ApiKey live-secret')
        ->and($contacts[0][2])->toStartWith('https://api.yoursrs.com/v2/');

    $registrant = substr($contacts[0][4], strlen('/customers/acme/contacts/'));
    $properties = realtimeregisterCalls('POST /customers/acme/contacts/*/NeustarEnhanced');
    expect($registrant)->toStartWith('registrant-')
        ->and($properties[0][1])->toBe(['properties' => ['NexusCategory' => 'C11', 'AppPurpose' => 'P3']])
        ->and($properties[0][4])->toContain('/contacts/' . $registrant . '/');

    $order = realtimeregisterCalls('POST /domains/example.us')[0][1];
    expect($order)->toMatchArray(['customer' => 'acme', 'registrant' => $registrant, 'privacyProtect' => false, 'autoRenew' => false, 'period' => 24,
        'ns' => ['ns1.example.net', 'ns2.example.net']])
        ->and(array_column($order['contacts'], 'role'))->toBe(['ADMIN', 'BILLING', 'TECH']);
});

test('a .com registration adds no registry properties; test mode uses OT&E with its test name servers', function (): void {
    $adapter = realtimeregisterAdapter();
    $adapter->enableTestMode();
    $adapter->registerDomain(realtimeregisterDomain());

    $order = realtimeregisterCalls('POST /domains/example.com')[0];
    expect(array_column($GLOBALS['realtimeregister_calls'], 0))->toBe([...array_fill(0, 4, 'POST /customers/acme/contacts/*'), 'POST /domains/example.com'])
        ->and($order[2])->toStartWith('https://api.yoursrs-ote.com/v2/')
        ->and($order[1]['ns'])->toBe(['ns1.realtimeregister-ote.com', 'ns2.realtimeregister-ote.com'])
        ->and($order[1]['zone'])->toBe(['service' => 'BASIC', 'template' => 'standard-template']);
});

test('a transfer sends the EPP code, a .uk one without a code sends none, and a rejected transfer is an error', function (): void {
    $adapter = realtimeregisterAdapter(['POST /domains/example.com/transfer' => [202, ['domainName' => 'example.com', 'status' => 'pendingfoa', 'requestedDate' => '2026-11-13T10:00:00Z', 'type' => 'IN', 'processId' => 7]]]);
    expect($adapter->transferDomain(realtimeregisterDomain()->setEpp('Ab-12')))->toBeTrue()
        ->and(realtimeregisterCalls('POST /domains/example.com/transfer')[0][1])->toMatchArray(['authcode' => 'Ab-12', 'customer' => 'acme', 'period' => 24, 'autoRenew' => false]);

    $adapter->transferDomain(realtimeregisterDomain('.co.uk')->setFields(['uk_type' => 'LTD', 'uk_company_number' => '01234567']));
    expect(realtimeregisterCalls('POST /domains/example.co.uk/transfer')[0][1])->not->toHaveKey('authcode')
        ->and(realtimeregisterCalls('POST /customers/acme/contacts/*/Nominet')[0][1])->toBe(['properties' => ['type' => 'LTD', 'co-no' => '01234567']]);

    $rejected = realtimeregisterAdapter(['POST /domains/example.com/transfer' => [200, ['domainName' => 'example.com', 'status' => 'rejected', 'type' => 'IN', 'processId' => 8]]]);
    expect(fn () => $rejected->transferDomain(realtimeregisterDomain()->setEpp('x')))->toThrow(Registrar_Exception::class, 'transfer rejected');
});

test('renew, name servers, privacy and lock send the documented bodies', function (): void {
    $adapter = realtimeregisterAdapter(['GET /domains/example.com' => [200, ['domainName' => 'example.com', 'status' => ['OK', 'CLIENT_TRANSFER_PROHIBITED']]]]);
    $adapter->renewDomain(realtimeregisterDomain()->setRegistrationPeriod(1));
    $adapter->modifyNs(realtimeregisterDomain()->setNs3('ns3.example.net'));
    $adapter->enablePrivacyProtection(realtimeregisterDomain());
    $adapter->unlock(realtimeregisterDomain());
    $adapter->lock(realtimeregisterDomain());

    $updates = array_column(realtimeregisterCalls('POST /domains/example.com/update'), 1);
    expect(realtimeregisterCalls('POST /domains/example.com/renew')[0][1])->toBe(['period' => 12])
        ->and($updates)->toBe([
            ['ns' => ['ns1.example.net', 'ns2.example.net', 'ns3.example.net']],
            ['privacyProtect' => true],
            ['status' => ['OK']],
            ['status' => ['OK', 'CLIENT_TRANSFER_PROHIBITED']],
        ]);
});

test('a contact change replaces the admin, billing and tech contacts and removes the old ones', function (): void {
    $adapter = realtimeregisterAdapter(['GET /domains/example.com' => [200, ['domainName' => 'example.com', 'registrant' => 'reg-1',
        'contacts' => [['role' => 'ADMIN', 'handle' => 'old-admin'], ['role' => 'TECH', 'handle' => 'old-tech']]]],
        'DELETE /customers/acme/contacts/old-tech' => [400, ['type' => 'ObjectStatusProhibitsOperation', 'message' => 'Contact in use']]]);

    expect($adapter->modifyContact(realtimeregisterDomain()))->toBeTrue()
        ->and(realtimeregisterCalls('POST /customers/acme/contacts/*'))->toHaveCount(3)
        ->and(array_column(realtimeregisterCalls('POST /domains/example.com/update')[0][1]['contacts'], 'role'))->toBe(['ADMIN', 'BILLING', 'TECH'])
        ->and(array_column($GLOBALS['realtimeregister_calls'], 0))->toContain('DELETE /customers/acme/contacts/old-admin', 'DELETE /customers/acme/contacts/old-tech');
});

test('domain details come from the domain', function (): void {
    $adapter = realtimeregisterAdapter(['GET /domains/example.com' => [200, ['domainName' => 'example.com', 'registrant' => 'reg-1', 'privacyProtect' => true,
        'status' => ['OK', 'CLIENT_TRANSFER_PROHIBITED'], 'authcode' => 'Xy9!', 'ns' => ['NS1.EXAMPLE.NET', 'ns2.example.net'],
        'createdDate' => '2024-01-02T10:00:00Z', 'expiryDate' => '2027-01-02T10:00:00Z']]]);
    $details = $adapter->getDomainDetails(realtimeregisterDomain());

    expect(gmdate('Y-m-d', $details->getRegistrationTime()))->toBe('2024-01-02')
        ->and(gmdate('Y-m-d', $details->getExpirationTime()))->toBe('2027-01-02')
        ->and($details->getLocked())->toBeTrue()
        ->and($details->getPrivacyEnabled())->toBeTrue()
        ->and($details->getEpp())->toBe('Xy9!')
        ->and([$details->getNs1(), $details->getNs2()])->toBe(['ns1.example.net', 'ns2.example.net'])
        ->and($adapter->getEpp(realtimeregisterDomain()))->toBe('Xy9!');
});

test('availability: available, taken and premium names', function (): void {
    $check = fn (array $reply) => realtimeregisterAdapter(['GET /domains/example.com/check' => [200, $reply]]);

    expect($check(['available' => true])->isDomainAvailable(realtimeregisterDomain()))->toBeTrue()
        ->and($check(['available' => false, 'reason' => 'Domain exists'])->isDomainAvailable(realtimeregisterDomain()))->toBeFalse()
        ->and($check(['available' => false])->isDomaincanBeTransferred(realtimeregisterDomain()))->toBeTrue()
        ->and(fn () => $check(['available' => true, 'premium' => true, 'currency' => 'EUR', 'price' => 90000])->isDomainAvailable(realtimeregisterDomain()))
        ->toThrow(Registrar_Exception::class, 'Premium domains cannot be registered')
        ->and(fn () => $check(['available' => false, 'premium' => true, 'currency' => 'EUR', 'price' => 90000])->isDomaincanBeTransferred(realtimeregisterDomain()))
        ->toThrow(Registrar_Exception::class, 'Premium domains cannot be transferred');
});

test('a .it registration sends the Nic.it properties; a TLD whose required properties the form does not ask is refused and not priced', function (): void {
    $adapter = realtimeregisterAdapter(['POST /domains/example.it' => [201, ['domainName' => 'example.it', 'status' => ['OK']]]]);
    $adapter->registerDomain(realtimeregisterDomain('.it')->setFields(['it_entity_type' => '2', 'it_reg_code' => 'IT01234567890']));
    expect(realtimeregisterCalls('POST /customers/acme/contacts/*/Nicit')[0][1])->toBe(['properties' => ['entityType' => '2', 'regCode' => 'IT01234567890']])
        ->and(array_keys(Registrar_Adapter_Realtimeregister::getDomainFields('.it')))->toBe(['it_entity_type', 'it_reg_code'])
        ->and(array_keys(Registrar_Adapter_Realtimeregister::getDomainFields('.es')))->toBe(['es_id_number']);

    $cn = realtimeregisterAdapter([
        'GET /customers/acme/pricelist' => [200, ['prices' => [['product' => 'domain_cn', 'action' => 'CREATE', 'currency' => 'EUR', 'price' => 900],
            ['product' => 'domain_it', 'action' => 'CREATE', 'currency' => 'EUR', 'price' => 900]]]],
    ]);
    expect(fn () => $cn->isDomainAvailable(realtimeregisterDomain('.cn')))->toThrow(Registrar_Exception::class, 'registry details')
        ->and(fn () => $cn->isDomaincanBeTransferred(realtimeregisterDomain('.cn')))->toThrow(Registrar_Exception::class, 'registry details')
        // Required for some registrants only (.fi persons and companies, .be organisations): refused as well.
        ->and(fn () => $cn->isDomainAvailable(realtimeregisterDomain('.fi')))->toThrow(Registrar_Exception::class, 'registry details')
        ->and(fn () => $cn->isDomainAvailable(realtimeregisterDomain('.be')))->toThrow(Registrar_Exception::class, 'registry details')
        ->and(realtimeregisterCalls('GET /domains/example.cn/check'))->toBe([])
        ->and(array_keys($cn->getTldPrices()['prices']))->toBe(['.it']);
});

test('an error reply is a registrar error with the message and violations, and the key stays out of the log', function (): void {
    $adapter = realtimeregisterAdapter(['POST /domains/example.com/renew' => [400, ['type' => 'ConstraintViolationException', 'message' => 'Validation failed',
        'violations' => [['field' => 'period', 'message' => 'Invalid period']]]]]);

    expect(fn () => $adapter->renewDomain(realtimeregisterDomain()))->toThrow(Registrar_Exception::class, 'Validation failed; period: Invalid period');
    expect(json_encode($adapter->getLog()->calls))->not->toContain('live-secret');
});

test('the price list reads the domain products in cents; other products, actions and currencies are left out; the filter keeps the asked TLDs', function (): void {
    $adapter = realtimeregisterAdapter(['GET /customers/acme/pricelist' => [200, ['prices' => [
        ['product' => 'domain_com', 'action' => 'CREATE', 'currency' => 'EUR', 'price' => 899],
        ['product' => 'domain_com', 'action' => 'RENEW', 'currency' => 'EUR', 'price' => 950],
        ['product' => 'domain_com', 'action' => 'TRANSFER', 'currency' => 'EUR', 'price' => 899],
        ['product' => 'domain_com', 'action' => 'RESTORE', 'currency' => 'EUR', 'price' => 8000],
        ['product' => 'domain_co_uk', 'action' => 'CREATE', 'currency' => 'EUR', 'price' => 525],
        ['product' => 'domain_co_uk', 'action' => 'TRANSFER', 'currency' => 'EUR', 'price' => 0],
        ['product' => 'centralnic_domain_uk_com', 'action' => 'RENEW', 'currency' => 'EUR', 'price' => 2500],
        ['product' => 'domain_nl', 'action' => 'CREATE', 'currency' => 'USD', 'price' => 400],
        ['product' => 'ssl_comodo_dv', 'action' => 'CREATE', 'currency' => 'EUR', 'price' => 1000],
    ]]]]);

    expect($adapter->getTldPrices())->toBe(['currency' => 'EUR', 'prices' => [
        '.com' => ['register' => 8.99, 'renew' => 9.5, 'transfer' => 8.99],
        '.co.uk' => ['register' => 5.25, 'renew' => null, 'transfer' => null],
        '.uk.com' => ['register' => null, 'renew' => 25.0, 'transfer' => null],
    ], 'remaining' => []])
        ->and(array_keys($adapter->getTldPrices(['.co.uk', '.net'])['prices']))->toBe(['.co.uk']);
});

test('the transfer status maps the latest transfer: completed, cancelled, rejected or failed, anything else pending', function (string $status, string $expected): void {
    $adapter = realtimeregisterAdapter(['GET /domains/example.com/transfer' => [200, ['domainName' => 'example.com', 'status' => $status, 'type' => 'IN', 'processId' => 7]]]);

    expect($adapter->getTransferStatus(realtimeregisterDomain()))->toBe(['status' => $expected, 'message' => $status]);
})->with([
    'completed' => ['completed', Registrar_TransferStatus::COMPLETED],
    'rejected' => ['rejected', Registrar_TransferStatus::FAILED],
    'cancelled' => ['cancelled', Registrar_TransferStatus::FAILED],
    'pending FOA' => ['pendingfoa', Registrar_TransferStatus::PENDING],
]);

test('the order form asks the registry properties of .us, .eu, .ca, .uk and .fr only; a cancel sends nothing', function (): void {
    expect(array_keys(Registrar_Adapter_Realtimeregister::getDomainFields('.us')))->toBe(['nexus_category', 'app_purpose'])
        ->and(Registrar_Adapter_Realtimeregister::getDomainFields('.eu')['eu_citizenship']['options'])->toHaveKeys(['DE', 'NO'])
        ->and(Registrar_Adapter_Realtimeregister::getDomainFields('.ca', 'transfer')['ca_presence']['required'])->toBeTrue()
        ->and(array_keys(Registrar_Adapter_Realtimeregister::getDomainFields('.me.uk')))->toBe(['uk_type', 'uk_company_number'])
        ->and(array_keys(Registrar_Adapter_Realtimeregister::getDomainFields('.fr')))->toBe(['fr_legal_status'])
        ->and(Registrar_Adapter_Realtimeregister::getDomainFields('.com'))->toBe([]);

    $adapter = realtimeregisterAdapter();
    expect($adapter->deleteDomain(realtimeregisterDomain()))->toBeTrue()->and($GLOBALS['realtimeregister_calls'])->toBe([]);
});
