<?php

declare(strict_types=1);

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** A reply shaped as in the ConnectReseller API documentation. */
function connectresellerReply(int $status = 200, mixed $data = null, string $message = 'Success', int $id = 0): array
{
    return ['responseMsg' => ['message' => $message, 'id' => $id, 'reason' => null, 'statusCode' => $status], 'responseData' => $data];
}

/** A ConnectReseller adapter answering each command from $replies (a reply, or a list of replies used in turn), recording each call. */
function connectresellerAdapter(array $replies = []): Registrar_Adapter_Connectreseller
{
    $replies += [
        'ViewClient' => connectresellerReply(200, ['clientId' => 501, 'emailaddress' => 'ann@example.com']),
        'ViewDomain' => connectresellerReply(200, ['domainNameId' => 77, 'websiteName' => 'example.com', 'customerId' => 501]),
    ];
    $client = new MockHttpClient(function (string $method, string $url) use (&$replies): MockResponse {
        $command = basename((string) parse_url($url, PHP_URL_PATH));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $GLOBALS['connectreseller_calls'][] = [$command, $query, $url, $method];
        $reply = $replies[$command] ?? connectresellerReply();
        if (array_is_list($reply) && is_array($reply[0] ?? null) && isset($reply[0]['responseMsg'])) {
            $reply = count($replies[$command]) > 1 ? array_shift($replies[$command]) : $reply[0];
        }

        return new MockResponse((string) json_encode($reply));
    });
    $adapter = new class(['api_key' => 'cr-secret-key'], $client) extends Registrar_Adapter_Connectreseller {
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
    $GLOBALS['connectreseller_calls'] = [];

    return $adapter;
}

function connectresellerDomain(string $tld = '.com'): Registrar_Domain
{
    $contact = (new Registrar_Domain_Contact())->setFirstName('Ann')->setLastName('Lee')->setEmail('ann@example.com')
        ->setAddress1('1 Main St')->setAddress2('Flat 2')->setCity('London')->setZip('N1 1AA')->setCountry('gb')->setTelCc('44')->setTel('020 7946 0000');

    return (new Registrar_Domain())->setSld('example')->setTld($tld)->setRegistrationPeriod(2)
        ->setNs1('ns1.example.net')->setNs2('ns2.example.net')->setContactRegistrar($contact);
}

function connectresellerCall(string $command): array
{
    foreach ($GLOBALS['connectreseller_calls'] as $call) {
        if ($call[0] === $command) {
            return $call;
        }
    }

    throw new RuntimeException('No call ' . $command);
}

test('a .us registration finds the customer and sends the period, name servers, nexus answers and no premium', function (): void {
    connectresellerAdapter()->registerDomain(connectresellerDomain('.us')->setFields(['nexus_category' => 'C31', 'nexus_country' => 'de', 'app_purpose' => 'P1']));

    expect(array_column($GLOBALS['connectreseller_calls'], 0))->toBe(['ViewClient', 'domainorder'])
        ->and(connectresellerCall('ViewClient')[1])->toMatchArray(['UserName' => 'ann@example.com'])
        ->and(connectresellerCall('domainorder')[2])->toStartWith('https://api.connectreseller.com/ConnectReseller/ESHOP/domainorder?')
        ->and(connectresellerCall('domainorder')[3])->toBe('GET')
        ->and(connectresellerCall('domainorder')[1])->toMatchArray([
            'APIKey' => 'cr-secret-key', 'ProductType' => '1', 'Websitename' => 'example.us', 'Duration' => '2', 'IsWhoisProtection' => 'false',
            'isEnablePremium' => '0', 'ns1' => 'ns1.example.net', 'ns2' => 'ns2.example.net', 'Id' => '501',
            'isUs' => '1', 'nexusCategory' => 'C31/DE', 'appPurpose' => 'P1',
        ])
        ->and(connectresellerCall('domainorder')[1])->not->toHaveKey('ns3');
});

test('a transfer for a new client creates the customer from the contact, then sends the code', function (): void {
    $adapter = connectresellerAdapter(['ViewClient' => [
        connectresellerReply(400, null, 'Client not found'),
        connectresellerReply(200, ['clientId' => 900]),
    ]]);
    $adapter->transferDomain(connectresellerDomain()->setEpp(' abc123 ')->setPrivacyEnabled(true));

    expect(array_column($GLOBALS['connectreseller_calls'], 0))->toBe(['ViewClient', 'AddClient', 'ViewClient', 'TransferOrder'])
        ->and(connectresellerCall('AddClient')[1])->toMatchArray([
            'FirstName' => 'Ann Lee', 'UserName' => 'ann@example.com', 'CompanyName' => 'NA', 'Address1' => '1 Main St, Flat 2',
            'City' => 'London', 'CountryName' => 'GB', 'Zip' => 'N1 1AA', 'PhoneNo_cc' => '44', 'PhoneNo' => '2079460000',
        ])
        ->and(connectresellerCall('AddClient')[1]['Password'])->not->toBe('')
        ->and(connectresellerCall('TransferOrder')[1])->toMatchArray(['OrderType' => '4', 'Websitename' => 'example.com', 'Authcode' => 'abc123', 'IsWhoisProtection' => 'true', 'Id' => '900']);
});

test('a renewal uses the domain\'s own customer', function (): void {
    $adapter = connectresellerAdapter();
    $adapter->renewDomain(connectresellerDomain());

    expect(array_column($GLOBALS['connectreseller_calls'], 0))->toBe(['ViewDomain', 'RenewalOrder'])
        ->and(connectresellerCall('RenewalOrder')[1])->toMatchArray(['OrderType' => '2', 'Websitename' => 'example.com', 'Duration' => '2', 'Id' => '501']);
});

test('name servers, lock and privacy go by the domain ID', function (): void {
    $adapter = connectresellerAdapter();
    $adapter->modifyNs(connectresellerDomain()->setNs3('ns3.example.net'));
    $adapter->lock(connectresellerDomain());
    $adapter->disablePrivacyProtection(connectresellerDomain());

    expect(connectresellerCall('UpdateNameServer')[1])->toMatchArray(['domainNameId' => '77', 'websiteName' => 'example.com',
        'nameServer1' => 'ns1.example.net', 'nameServer2' => 'ns2.example.net', 'nameServer3' => 'ns3.example.net'])
        ->and(connectresellerCall('ManageTheftProtection')[1])->toMatchArray(['domainNameId' => '77', 'isTheftProtection' => 'true'])
        ->and(connectresellerCall('ManageDomainPrivacyProtection')[1])->toMatchArray(['domainNameId' => '77', 'iswhoisprotected' => 'false']);
});

test('a contact change makes a new contact for this domain and sets it for all four roles', function (): void {
    $adapter = connectresellerAdapter(['AddRegistrantContact' => connectresellerReply(200, null, 'Contact added', 3456)]);
    $adapter->modifyContact(connectresellerDomain());

    expect(array_column($GLOBALS['connectreseller_calls'], 0))->toBe(['ViewDomain', 'AddRegistrantContact', 'updatecontact'])
        ->and(connectresellerCall('AddRegistrantContact')[1])->toMatchArray(['Name' => 'Ann Lee', 'EmailAddress' => 'ann@example.com', 'Address' => '1 Main St, Flat 2', 'Id' => '501'])
        ->and(connectresellerCall('updatecontact')[1])->toMatchArray(['domainNameId' => '77', 'websiteName' => 'example.com',
            'registrantContactId' => '3456', 'adminContactId' => '3456', 'technicalContactId' => '3456', 'billingContactId' => '3456']);
});

test('domain details and the transfer code come from ViewDomain', function (): void {
    $adapter = connectresellerAdapter(['ViewDomain' => connectresellerReply(200, [
        'domainNameId' => 77, 'websiteName' => 'example.com', 'customerId' => 501,
        'creationDate' => 1704153600000, 'expirationDate' => 1798848000000,
        'nameserver1' => 'NS1.EXAMPLE.NET', 'nameserver2' => 'ns2.example.net', 'nameserver3' => '',
        'isDomainLocked' => false, 'isThiefProtected' => true, 'isPrivacyProtection' => false, 'authCode' => 'Xy7!pq',
    ])]);
    $details = $adapter->getDomainDetails(connectresellerDomain());

    expect(gmdate('Y-m-d', $details->getRegistrationTime()))->toBe('2024-01-02')
        ->and(gmdate('Y-m-d', $details->getExpirationTime()))->toBe('2027-01-02')
        ->and($details->getLocked())->toBeTrue()
        ->and($details->getPrivacyEnabled())->toBeFalse()
        ->and([$details->getNs1(), $details->getNs2(), $details->getNs3()])->toBe(['ns1.example.net', 'ns2.example.net', null])
        ->and($adapter->getEpp(connectresellerDomain()))->toBe('Xy7!pq');
});

test('the price list reads tldsync in its currency; a missing action is null and the filter keeps the asked TLDs', function (): void {
    $adapter = connectresellerAdapter(['tldsync' => [
        ['tld' => '.com', 'minPeriod' => 1, 'maxPeriod' => 10, 'registrationPrice' => 8.99, 'renewalPrice' => 9.49, 'transferPrice' => 8.99, 'currencyCode' => 'USD'],
        ['tld' => 'co.uk', 'minPeriod' => 1, 'maxPeriod' => 10, 'registrationPrice' => 5.25, 'renewalPrice' => 5.25, 'transferPrice' => null, 'currencyCode' => 'USD'],
    ]]);

    expect($adapter->getTldPrices())->toBe(['currency' => 'USD', 'prices' => [
        '.com' => ['register' => 8.99, 'renew' => 9.49, 'transfer' => 8.99],
        '.co.uk' => ['register' => 5.25, 'renew' => 5.25, 'transfer' => null],
    ], 'remaining' => []])
        ->and(array_keys($adapter->getTldPrices(['.co.uk', '.net'])['prices']))->toBe(['.co.uk']);
});

test('a refused request is a registrar error with ConnectReseller\'s message, and the key stays out of it and the log', function (): void {
    $adapter = connectresellerAdapter(['RenewalOrder' => connectresellerReply(400, null, 'Insufficient balance')]);

    try {
        $adapter->renewDomain(connectresellerDomain());
        $this->fail('No error');
    } catch (Registrar_Exception $e) {
        expect($e->getMessage())->toContain('Insufficient balance')->not->toContain('cr-secret-key');
    }
    expect(json_encode($adapter->getLog()->calls))->not->toContain('cr-secret-key');
});

test('a timed-out call logs no contact data, password or key from the request URL', function (): void {
    $client = new MockHttpClient(function (string $method, string $url): MockResponse {
        if (str_contains($url, '/AddClient')) {
            // Symfony's own wording: the transport error names the full URL.
            throw new Symfony\Component\HttpClient\Exception\TransportException('Idle timeout reached for "' . $url . '".');
        }

        return new MockResponse((string) json_encode(connectresellerReply(400, null, 'Not found')));
    });
    $adapter = new class(['api_key' => 'cr-secret-key'], $client) extends Registrar_Adapter_Connectreseller {
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

    expect(fn () => $adapter->registerDomain(connectresellerDomain()))->toThrow(Registrar_Exception::class, 'Could not reach');
    $log = json_encode($adapter->getLog()->calls);
    expect($log)->toContain('AddClient HTTP error')->not->toContain('cr-secret-key')->not->toContain('ann')->not->toContain('Password');
});

test('availability: free, taken and premium names', function (): void {
    $free = connectresellerReply(200, ['domainType' => 'Standard', 'available' => true, 'registrationFees' => 8.99]);
    $taken = connectresellerReply(400, null, 'Domain not available');
    $premium = connectresellerReply(200, ['domainType' => 'Premium', 'available' => true, 'registrationFees' => 2500]);

    expect(connectresellerAdapter(['checkdomainavailable' => $free])->isDomainAvailable(connectresellerDomain()))->toBeTrue()
        ->and(connectresellerCall('checkdomainavailable')[1])->toMatchArray(['websiteName' => 'example.com'])
        ->and(connectresellerAdapter(['checkdomainavailable' => $taken])->isDomainAvailable(connectresellerDomain()))->toBeFalse()
        ->and(fn () => connectresellerAdapter(['checkdomainavailable' => $premium])->isDomainAvailable(connectresellerDomain()))
        ->toThrow(Registrar_Exception::class, 'Premium domains cannot be registered')
        ->and(connectresellerAdapter(['checkdomainavailable' => $taken])->isDomaincanBeTransferred(connectresellerDomain()))->toBeTrue()
        ->and(connectresellerAdapter(['checkdomainavailable' => $free])->isDomaincanBeTransferred(connectresellerDomain()))->toBeFalse()
        ->and(fn () => connectresellerAdapter(['checkdomainavailable' => connectresellerReply(400, ['domainType' => 'Premium', 'available' => false])])->isDomaincanBeTransferred(connectresellerDomain()))
        ->toThrow(Registrar_Exception::class, 'Premium domains cannot be transferred');
});

test('test mode refuses every call (no test system); a cancel sends nothing', function (): void {
    $adapter = connectresellerAdapter();
    expect($adapter->deleteDomain(connectresellerDomain()))->toBeTrue()->and($GLOBALS['connectreseller_calls'])->toBe([]);

    $adapter->enableTestMode();
    expect(fn () => $adapter->lock(connectresellerDomain()))->toThrow(Registrar_Exception::class, 'no test system')
        ->and($GLOBALS['connectreseller_calls'])->toBe([]);
});

test('the order form asks the .us questions on registrations only', function (): void {
    expect(array_keys(Registrar_Adapter_Connectreseller::getDomainFields('.us')))->toBe(['nexus_category', 'nexus_country', 'app_purpose'])
        ->and(Registrar_Adapter_Connectreseller::getDomainFields('.us', 'transfer'))->toBe([])
        ->and(Registrar_Adapter_Connectreseller::getDomainFields('.com'))->toBe([]);
});

test('the transfer status maps syncTransfer\'s status: completed, cancelled or rejected failed, anything else pending', function (string $text, string $status): void {
    $adapter = connectresellerAdapter(['syncTransfer' => connectresellerReply(200, ['Status' => $text, 'Reason' => 'Details.', 'expiryDate' => null])]);

    expect($adapter->getTransferStatus(connectresellerDomain()))->toBe(['status' => $status, 'message' => $text . ' Details.'])
        ->and(connectresellerCall('syncTransfer')[1])->toMatchArray(['domainName' => 'example.com']);
})->with([
    'completed' => ['Completed', Registrar_TransferStatus::COMPLETED],
    'cancelled' => ['Cancelled', Registrar_TransferStatus::FAILED],
    'rejected' => ['Rejected', Registrar_TransferStatus::FAILED],
    'stalled' => ['Stall', Registrar_TransferStatus::PENDING],
    'unknown' => ['In Process', Registrar_TransferStatus::PENDING],
]);
