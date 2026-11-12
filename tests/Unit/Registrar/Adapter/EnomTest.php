<?php

declare(strict_types=1);

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** An Enom adapter answering each command from $replies (inner XML; ErrCount 0 unless given), recording each call. */
function enomAdapter(array $replies = []): Registrar_Adapter_Enom
{
    $client = new MockHttpClient(function (string $method, string $url, array $options) use ($replies): MockResponse {
        parse_str((string) ($options['body'] ?? ''), $body);
        $GLOBALS['enom_calls'][] = [$body['command'], $body, $url];
        $reply = $replies[$body['command']] ?? '';

        return new MockResponse('<?xml version="1.0"?><interface-response>' . (str_contains($reply, '<ErrCount>') ? '' : '<ErrCount>0</ErrCount>') . $reply . '<Done>true</Done></interface-response>');
    });
    $adapter = new class(['uid' => 'reseller', 'pw' => 'secret-token'], $client) extends Registrar_Adapter_Enom {
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
    $GLOBALS['enom_calls'] = [];

    return $adapter;
}

function enomDomain(string $tld = '.com'): Registrar_Domain
{
    $contact = (new Registrar_Domain_Contact())->setFirstName('Ann')->setLastName('Lee')->setEmail('ann@example.com')
        ->setAddress1('12 Main St')->setCity('London')->setZip('N1 1AA')->setCountry('gb')->setTelCc('44')->setTel('020 7946 0000');

    return (new Registrar_Domain())->setSld('example')->setTld($tld)->setRegistrationPeriod(2)
        ->setNs1('ns1.example.net')->setNs2('ns2.example.net')->setContactRegistrar($contact);
}

test('a registration posts the token, the client\'s name servers, all four contacts and no auto-renewal', function (): void {
    enomAdapter(['Purchase' => '<RRPCode>200</RRPCode><RRPText>Command completed successfully</RRPText>'])->registerDomain(enomDomain());

    [$command, $body, $url] = $GLOBALS['enom_calls'][0];
    expect($command)->toBe('Purchase')
        ->and($url)->toBe('https://reseller.enom.com/interface.asp')
        ->and($body)->toMatchArray(['uid' => 'reseller', 'pw' => 'secret-token', 'responsetype' => 'xml', 'SLD' => 'example', 'TLD' => 'com', 'NumYears' => '2', 'RenewName' => '0',
            'NS1' => 'ns1.example.net', 'NS2' => 'ns2.example.net', 'RegistrantFirstName' => 'Ann', 'RegistrantPhone' => '+44.2079460000', 'RegistrantFax' => '+44.2079460000',
            'RegistrantStateProvince' => 'London', 'RegistrantCountry' => 'GB', 'AdminEmailAddress' => 'ann@example.com', 'TechLastName' => 'Lee', 'AuxBillingCity' => 'London'])
        ->and($body)->not->toHaveKey('RegistrantJobTitle');
});

test('an organisation gets the job title Enom requires; .us answers go as extended attributes', function (): void {
    $domain = enomDomain('.us')->setFields(['nexus_category' => 'C31', 'nexus_country' => 'de', 'app_purpose' => 'P1']);
    $domain->getContactRegistrar()->setCompany('Acme Ltd');
    enomAdapter(['Purchase' => '<RRPCode>1300</RRPCode>'])->registerDomain($domain);

    expect($GLOBALS['enom_calls'][0][1])->toMatchArray(['RegistrantOrganizationName' => 'Acme Ltd', 'RegistrantJobTitle' => 'N/A', 'us_nexus' => 'C31', 'global_cc_us' => 'DE', 'us_purpose' => 'P1']);
});

test('a transfer sends the code only when there is one, with no auto-renewal and the lock on', function (): void {
    $adapter = enomAdapter(['TP_CreateOrder' => '<transferorder><transferorderid>445413</transferorderid><statusdesc>Processing</statusdesc></transferorder>']);
    $adapter->transferDomain(enomDomain('.us')->setEpp('abc')->setFields(['nexus_category' => 'C11', 'app_purpose' => 'P3']));
    $adapter->transferDomain(enomDomain('.uk'));

    expect($GLOBALS['enom_calls'][0][1])->toMatchArray(['OrderType' => 'Autoverification', 'DomainCount' => '1', 'SLD1' => 'example', 'TLD1' => 'us', 'AuthInfo1' => 'abc',
        'Renew' => '0', 'Lock' => '1', 'RegistrantEmailAddress' => 'ann@example.com', 'us_nexus' => 'C11', 'us_purpose' => 'P3'])
        ->and($GLOBALS['enom_calls'][0][1])->not->toHaveKey('global_cc_us')->not->toHaveKey('AdminFirstName')
        ->and($GLOBALS['enom_calls'][1][1])->not->toHaveKey('AuthInfo1');
});

test('a refused request is a registrar error with Enom\'s messages; a renewal needs RRP code 200', function (): void {
    expect(fn () => enomAdapter(['Extend' => '<ErrCount>1</ErrCount><errors><Err1>Insufficient funds</Err1></errors>'])->renewDomain(enomDomain()))
        ->toThrow(Registrar_Exception::class, 'Insufficient funds')
        ->and(fn () => enomAdapter(['Extend' => '<RRPCode>540</RRPCode><RRPText>Domain not renewable</RRPText>'])->renewDomain(enomDomain()))
        ->toThrow(Registrar_Exception::class, 'Domain not renewable')
        ->and(enomAdapter(['Extend' => '<RRPCode>200</RRPCode>'])->renewDomain(enomDomain()))->toBeTrue()
        ->and($GLOBALS['enom_calls'][0][1])->toMatchArray(['command' => 'Extend', 'SLD' => 'example', 'TLD' => 'com', 'NumYears' => '2']);
});

test('a renewal paid after the expiry date reactivates the name with UpdateExpiredDomains', function (): void {
    expect(enomAdapter(['UpdateExpiredDomains' => '<OrderID>5</OrderID>'])->renewDomain(enomDomain()->setExpirationTime(time() - 86400)))->toBeTrue()
        ->and($GLOBALS['enom_calls'][0][1])->toMatchArray(['command' => 'UpdateExpiredDomains', 'DomainName' => 'example.com', 'NumYears' => '2'])
        ->and($GLOBALS['enom_calls'][0][1])->not->toHaveKey('SLD');
});

test('a reply that is not from Enom (an IP not allowed) is refused', function (): void {
    $client = new MockHttpClient(new MockResponse('<html><body>Access denied</body></html>'));
    $adapter = new class(['uid' => 'reseller', 'pw' => 'secret-token'], $client) extends Registrar_Adapter_Enom {
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

    expect(fn () => $adapter->lock(enomDomain()))->toThrow(Registrar_Exception::class, 'unreadable response');
});

test('availability: free, taken and premium names, for registration and transfer', function (): void {
    expect(enomAdapter(['Check' => '<RRPCode>210</RRPCode>'])->isDomainAvailable(enomDomain()))->toBeTrue()
        ->and(enomAdapter(['Check' => '<RRPCode>211</RRPCode>'])->isDomainAvailable(enomDomain()))->toBeFalse()
        ->and(enomAdapter(['Check' => '<RRPCode>211</RRPCode>'])->isDomaincanBeTransferred(enomDomain()))->toBeTrue()
        ->and(fn () => enomAdapter(['Check' => '<RRPCode>210</RRPCode><IsPremiumName>True</IsPremiumName>'])->isDomainAvailable(enomDomain()))
        ->toThrow(Registrar_Exception::class, 'Premium domains cannot be registered')
        ->and(fn () => enomAdapter(['Check' => '<RRPCode>211</RRPCode><IsPremium>true</IsPremium>'])->isDomaincanBeTransferred(enomDomain()))
        ->toThrow(Registrar_Exception::class, 'Premium domains cannot be transferred')
        ->and(fn () => enomAdapter(['Check' => '<RRPCode>210</RRPCode><IsEAP>true</IsEAP><IsPremiumName>false</IsPremiumName>'])->isDomainAvailable(enomDomain()))
        ->toThrow(Registrar_Exception::class, 'Premium domains cannot be registered');
});

test('contact, name server, lock and privacy changes', function (): void {
    $adapter = enomAdapter();
    $adapter->modifyContact(enomDomain());
    $adapter->modifyNs(enomDomain()->setNs3('ns3.example.net'));
    $adapter->unlock(enomDomain());
    $adapter->disablePrivacyProtection(enomDomain());

    expect(array_column($GLOBALS['enom_calls'], 0))->toBe(['Contacts', 'ModifyNS', 'SetRegLock', 'DisableServices'])
        ->and($GLOBALS['enom_calls'][0][1])->toMatchArray(['RegistrantFirstName' => 'Ann', 'AdminFirstName' => 'Ann', 'TechFirstName' => 'Ann', 'AuxBillingFirstName' => 'Ann'])
        ->and($GLOBALS['enom_calls'][1][1])->toMatchArray(['NS3' => 'ns3.example.net'])
        ->and($GLOBALS['enom_calls'][2][1])->toMatchArray(['UnlockRegistrar' => '1'])
        ->and($GLOBALS['enom_calls'][3][1])->toMatchArray(['Service' => 'WPPS'])
        ->and(fn () => $adapter->enablePrivacyProtection(enomDomain()))->toThrow(Registrar_Exception::class, 'paid service');
});

test('domain details from GetDomainInfo, and the transfer code or a contact-support message', function (): void {
    $adapter = enomAdapter(['GetDomainInfo' => '<GetDomainInfo><status><expiration>4/1/2027 7:34:00 PM</expiration><registrationstatus>Registered</registrationstatus></status>'
        . '<services><entry name="dnsserver"><configuration><dns>DNS1.EXAMPLE.NET</dns><dns>dns2.example.net</dns></configuration></entry></services></GetDomainInfo>',
        'GetAuthCode' => '<AuthCode>Xy9#k</AuthCode>']);
    $details = $adapter->getDomainDetails(enomDomain());

    expect(gmdate('Y-m-d', $details->getExpirationTime()))->toBe('2027-04-01')
        ->and($details->getRegistrationTime())->toBeNull()
        ->and($details->getLocked())->toBeNull()
        ->and([$details->getNs1(), $details->getNs2()])->toBe(['dns1.example.net', 'dns2.example.net'])
        ->and($adapter->getEpp(enomDomain()))->toBe('Xy9#k')
        ->and(fn () => enomAdapter(['GetAuthCode' => '<ErrCount>1</ErrCount><errors><Err1>Command not allowed</Err1></errors>'])->getEpp(enomDomain()))
        ->toThrow(Registrar_Exception::class, 'contact support');
});

test('test mode uses the Enom test system; a cancel sends nothing', function (): void {
    $adapter = enomAdapter();
    $adapter->enableTestMode();
    $adapter->lock(enomDomain());
    expect($GLOBALS['enom_calls'][0][2])->toBe('https://resellertest.enom.com/interface.asp');

    $GLOBALS['enom_calls'] = [];
    expect($adapter->deleteDomain(enomDomain()))->toBeTrue()->and($GLOBALS['enom_calls'])->toBe([]);
});

test('the order form asks the .us questions only, for registrations and transfers', function (): void {
    expect(array_keys(Registrar_Adapter_Enom::getDomainFields('.us', 'transfer')))->toBe(['nexus_category', 'nexus_country', 'app_purpose'])
        ->and(Registrar_Adapter_Enom::getDomainFields('.com'))->toBe([]);
});

test('the price list asks for the account\'s TLDs, then the one-year wholesale cost of each action, a slice at a time', function (): void {
    $calls = [];
    $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls): MockResponse {
        parse_str((string) ($options['body'] ?? ''), $body);
        $calls[] = $body;
        $reply = '';
        if ($body['command'] === 'GetTLDList') {
            $reply = '<tldlist>' . implode('', array_map(static fn (int $i): string => "<tld><tld>t{$i}</tld></tld>", range(1, 11))) . '<tld><tld>com</tld></tld><tldcount>12</tldcount></tldlist>';
        } elseif ($body['command'] === 'PE_GetProductPrice') {
            // .t2 offers no transfer; .t3 has no price at all.
            $enabled = !($body['tld'] === 't2' && $body['ProductType'] === '19') && $body['tld'] !== 't3';
            $reply = '<productprice><price>' . ['10' => '8.95', '16' => '9.50', '19' => '8.00'][$body['ProductType']] . '</price><productenabled>' . ($enabled ? 'True' : 'False') . '</productenabled></productprice>';
        }

        return new MockResponse('<?xml version="1.0"?><interface-response><ErrCount>0</ErrCount>' . $reply . '<Done>true</Done></interface-response>');
    });
    $adapter = new class(['uid' => 'reseller', 'pw' => 'secret-token'], $client) extends Registrar_Adapter_Enom {
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

    $first = $adapter->getTldPrices();
    expect($first['currency'])->toBe('USD')
        ->and($first['prices']['.t1'])->toBe(['register' => 8.95, 'renew' => 9.5, 'transfer' => 8.0])
        ->and($first['prices']['.t2'])->toBe(['register' => 8.95, 'renew' => 9.5, 'transfer' => null])
        ->and($first['prices'])->not->toHaveKey('.t3')
        ->and(array_keys($first['prices']))->toBe(['.t1', '.t2', '.t4', '.t5'])
        ->and($first['remaining'])->toBe(['.t6', '.t7', '.t8', '.t9', '.t10', '.t11', '.com'])
        ->and($calls[1])->toMatchArray(['command' => 'PE_GetProductPrice', 'tld' => 't1', 'ProductType' => '10', 'Years' => '1'])
        ->and(count($calls))->toBe(1 + 15);

    $rest = $adapter->getTldPrices(['.t11', '.com']);
    expect(array_keys($rest['prices']))->toBe(['.t11', '.com'])
        ->and($rest['remaining'])->toBe([]);
});

test('the transfer status is the newest transfer order\'s: 5 completed, a "Canceled" state failed, anything else pending', function (string $latest, string $status, string $message): void {
    $older = '<TransferOrder><orderid>100</orderid><statusid>5</statusid><statusdesc>Transferred successfully</statusdesc></TransferOrder>';
    $adapter = enomAdapter(['TP_GetDetailsByDomain' => $latest . $older]);

    expect($adapter->getTransferStatus(enomDomain()))->toBe(['status' => $status, 'message' => $message])
        ->and($GLOBALS['enom_calls'][0][1])->toMatchArray(['SLD' => 'example', 'TLD' => 'com']);
})->with([
    'completed' => ['<TransferOrder><orderid>200</orderid><statusid>5</statusid><statusdesc>Transferred successfully</statusdesc></TransferOrder>', Registrar_TransferStatus::COMPLETED, 'Transferred successfully'],
    'failed' => ['<TransferOrder><orderid>200</orderid><statusid>32</statusid><statusdesc>Canceled - Invalid EPP/authorization key</statusdesc></TransferOrder>', Registrar_TransferStatus::FAILED, 'Canceled - Invalid EPP/authorization key'],
    'pending' => ['<TransferOrder><orderid>200</orderid><statusid>14</statusid><statusdesc>Domain transfer initiated and awaiting approval</statusdesc></TransferOrder>', Registrar_TransferStatus::PENDING, 'Domain transfer initiated and awaiting approval'],
    'unknown' => ['<TransferOrder><orderid>200</orderid><statusid>77</statusid><statusdesc>Something new</statusdesc></TransferOrder>', Registrar_TransferStatus::PENDING, 'Something new'],
]);
