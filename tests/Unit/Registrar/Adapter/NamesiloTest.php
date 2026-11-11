<?php

declare(strict_types=1);

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** A NameSilo adapter answering each operation from $replies (operation => inner reply XML, code 300 unless given). */
function namesiloAdapter(array $replies, array $options = ['api_key' => 'live-secret', 'sandbox_api_key' => 'sandbox-secret']): Registrar_Adapter_Namesilo
{
    $client = new MockHttpClient(function (string $method, string $url) use (&$replies): MockResponse {
        $operation = basename((string) parse_url($url, PHP_URL_PATH));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $GLOBALS['namesilo_calls'][] = [$operation, $query, $url];
        $reply = $replies[$operation] ?? '';

        return new MockResponse('<?xml version="1.0"?><namesilo><reply>' . (str_contains($reply, '<code>') ? '' : '<code>300</code><detail>success</detail>') . $reply . '</reply></namesilo>');
    });
    $adapter = new class($options, $client) extends Registrar_Adapter_Namesilo {
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
    $GLOBALS['namesilo_calls'] = [];

    return $adapter;
}

function namesiloDomain(string $tld): Registrar_Domain
{
    $contact = (new Registrar_Domain_Contact())->setFirstName('Ann')->setLastName('Lee')->setEmail('ann@example.com')
        ->setAddress1('1 Main St')->setCity('London')->setZip('N1 1AA')->setCountry('gb')->setTelCc('44')->setTel('020 7946 0000');

    return (new Registrar_Domain())->setSld('example')->setTld($tld)->setRegistrationPeriod(2)
        ->setNs1('ns1.example.net')->setNs2('ns2.example.net')->setContactRegistrar($contact);
}

test('a .us registration sends the nexus answers, the contact and no NameSilo auto-renewal', function (): void {
    $adapter = namesiloAdapter([]);
    $adapter->registerDomain(namesiloDomain('.us')->setFields(['nexus_category' => 'C31', 'app_purpose' => 'P1']));

    [$operation, $query, $url] = $GLOBALS['namesilo_calls'][0];
    expect($operation)->toBe('registerDomain')
        ->and($url)->toStartWith('https://www.namesilo.com/api/')
        ->and($query)->toMatchArray(['key' => 'live-secret', 'domain' => 'example.us', 'years' => '2', 'auto_renew' => '0', 'usnc' => 'C31', 'usap' => 'P1',
            'ns1' => 'ns1.example.net', 'ns2' => 'ns2.example.net', 'fn' => 'Ann', 'ct' => 'GB', 'ph' => '02079460000']);
});

test('a .us transfer sends the nexus answers; reply 302 assigns the client\'s contact at once', function (): void {
    $adapter = namesiloAdapter(['transferDomain' => '<code>302</code><detail>success, default contact used</detail>', 'contactAdd' => '<contact_id>5</contact_id>']);
    $adapter->transferDomain(namesiloDomain('.us')->setEpp('abc')->setFields(['nexus_category' => 'C11', 'app_purpose' => 'P3']));

    expect(array_column($GLOBALS['namesilo_calls'], 0))->toBe(['transferDomain', 'contactAdd', 'contactDomainAssociate'])
        ->and($GLOBALS['namesilo_calls'][0][1])->toMatchArray(['auth' => 'abc', 'usnc' => 'C11', 'usap' => 'P3'])
        ->and($GLOBALS['namesilo_calls'][1][1])->toMatchArray(['usnc' => 'C11', 'usap' => 'P3']);
});

test('.eu names send the citizenship; a transfer without a code (.uk) sends no auth', function (): void {
    $adapter = namesiloAdapter([]);
    $adapter->registerDomain(namesiloDomain('.eu')->setFields(['eu_citizenship' => 'DE']));
    $adapter->transferDomain(namesiloDomain('.uk'));

    expect($GLOBALS['namesilo_calls'][0][1])->toMatchArray(['eucs' => 'DE'])
        ->and($GLOBALS['namesilo_calls'][1][0])->toBe('transferDomain')
        ->and($GLOBALS['namesilo_calls'][1][1])->not->toHaveKey('auth');
});

test('a refused follow-up after reply 301 does not fail the registration NameSilo took', function (): void {
    $adapter = namesiloAdapter(['registerDomain' => '<code>301</code><detail>our nameservers were used</detail>', 'changeNameServers' => '<code>280</code><detail>invalid nameservers</detail>']);

    expect($adapter->registerDomain(namesiloDomain('.com')))->toBeTrue()
        ->and(array_column($GLOBALS['namesilo_calls'], 0))->toBe(['registerDomain', 'changeNameServers']);
});

test('a domain in its restore period is not renewed', function (): void {
    $adapter = namesiloAdapter(['getDomainInfo' => '<status>Expired (restore period)</status>']);

    expect(fn () => $adapter->renewDomain(namesiloDomain('.com')))->toThrow(Registrar_Exception::class, 'restore period')
        ->and(array_column($GLOBALS['namesilo_calls'], 0))->toBe(['getDomainInfo']);
});

test('a refused request is a registrar error with NameSilo\'s detail, and the key stays out of the log', function (): void {
    $adapter = namesiloAdapter(['renewDomain' => '<code>261</code><detail>Insufficient funds</detail>']);

    expect(fn () => $adapter->renewDomain(namesiloDomain('.com')))->toThrow(Registrar_Exception::class, 'Insufficient funds');
    expect(json_encode($adapter->getLog()->calls))->not->toContain('live-secret');
});

test('availability: available, taken and premium names', function (): void {
    expect(namesiloAdapter(['checkRegisterAvailability' => '<available><domain price="9.95" premium="0">example.com</domain></available>'])->isDomainAvailable(namesiloDomain('.com')))->toBeTrue()
        ->and(namesiloAdapter(['checkRegisterAvailability' => '<unavailable><domain>example.com</domain></unavailable>'])->isDomainAvailable(namesiloDomain('.com')))->toBeFalse()
        ->and(fn () => namesiloAdapter(['checkRegisterAvailability' => '<available><domain price="900" premium="1">example.com</domain></available>'])->isDomainAvailable(namesiloDomain('.com')))
        ->toThrow(Registrar_Exception::class, 'Premium')
        ->and(namesiloAdapter(['checkTransferAvailability' => '<available><domain price="9.99">example.com</domain></available>'])->isDomaincanBeTransferred(namesiloDomain('.com')))->toBeTrue()
        ->and(fn () => namesiloAdapter(['checkTransferAvailability' => '<available><domain price="9999.99" premium="1">example.com</domain></available>'])->isDomaincanBeTransferred(namesiloDomain('.com')))
        ->toThrow(Registrar_Exception::class, 'Premium domains cannot be transferred');
});

test('a contact change makes a new contact for this domain only', function (): void {
    $adapter = namesiloAdapter(['contactAdd' => '<contact_id>991</contact_id>']);
    $adapter->modifyContact(namesiloDomain('.com'));

    expect(array_column($GLOBALS['namesilo_calls'], 0))->toBe(['contactAdd', 'contactDomainAssociate'])
        ->and($GLOBALS['namesilo_calls'][1][1])->toMatchArray(['domain' => 'example.com', 'registrant' => '991', 'administrative' => '991', 'billing' => '991', 'technical' => '991']);
});

test('domain details come from getDomainInfo', function (): void {
    $adapter = namesiloAdapter(['getDomainInfo' => '<created>2024-01-02</created><expires>2027-01-02</expires><locked>Yes</locked><private>No</private>'
        . '<nameservers><nameserver position="1">NS1.EXAMPLE.NET</nameserver><nameserver position="2">ns2.example.net</nameserver></nameservers>']);
    $details = $adapter->getDomainDetails(namesiloDomain('.com'));

    expect(date('Y-m-d', $details->getRegistrationTime()))->toBe('2024-01-02')
        ->and(date('Y-m-d', $details->getExpirationTime()))->toBe('2027-01-02')
        ->and($details->getLocked())->toBeTrue()
        ->and($details->getPrivacyEnabled())->toBeFalse()
        ->and([$details->getNs1(), $details->getNs2()])->toBe(['ns1.example.net', 'ns2.example.net']);
});

test('test mode uses the sandbox with its own key; a cancel sends nothing', function (): void {
    $adapter = namesiloAdapter([]);
    $adapter->enableTestMode();
    $adapter->lock(namesiloDomain('.com'));
    expect($GLOBALS['namesilo_calls'][0][2])->toStartWith('https://sandbox.namesilo.com/api/domainLock')
        ->and($GLOBALS['namesilo_calls'][0][1]['key'])->toBe('sandbox-secret');

    $GLOBALS['namesilo_calls'] = [];
    expect($adapter->deleteDomain(namesiloDomain('.com')))->toBeTrue()->and($GLOBALS['namesilo_calls'])->toBe([]);
});

test('the order form asks the .us and .eu questions only', function (): void {
    expect(array_keys(Registrar_Adapter_Namesilo::getDomainFields('.us')))->toBe(['nexus_category', 'app_purpose'])
        ->and(array_keys(Registrar_Adapter_Namesilo::getDomainFields('.us', 'transfer')))->toBe(['nexus_category', 'app_purpose'])
        ->and(Registrar_Adapter_Namesilo::getDomainFields('.eu')['eu_citizenship']['options'])->toHaveKey('DE')
        ->and(Registrar_Adapter_Namesilo::getDomainFields('.com'))->toBe([]);
});

test('the price list reads each TLD of getPrices in USD; a missing action is null and the filter keeps the asked TLDs', function (): void {
    $adapter = namesiloAdapter(['getPrices' => '<com><registration>9.95</registration><transfer>9.95</transfer><renew>10.95</renew></com>'
        . '<co.uk><registration>6.49</registration><renew>6.49</renew></co.uk>']);

    expect($adapter->getTldPrices())->toBe(['currency' => 'USD', 'prices' => [
        '.com' => ['register' => 9.95, 'renew' => 10.95, 'transfer' => 9.95],
        '.co.uk' => ['register' => 6.49, 'renew' => 6.49, 'transfer' => null],
    ], 'remaining' => []])
        ->and(array_keys($adapter->getTldPrices(['.co.uk', '.net'])['prices']))->toBe(['.co.uk']);
});
