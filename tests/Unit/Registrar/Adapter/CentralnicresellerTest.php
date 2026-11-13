<?php

declare(strict_types=1);

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** A reply in the API's plain-text format; $properties are "name][index] = value" lines without the leading "property[". */
function centralnicresellerReply(array $properties = [], string $code = '200', string $description = 'Command completed successfully'): string
{
    return "[RESPONSE]\r\ncode = {$code}\r\ndescription = {$description}\r\n"
        . implode('', array_map(fn (string $line) => "property[{$line}\r\n", $properties)) . "queuetime = 0\r\nruntime = 0.05\r\nEOF\r\n";
}

/** An adapter answering each COMMAND from $replies (command => reply text, code 200 without properties unless given). */
function centralnicresellerAdapter(array $replies): Registrar_Adapter_Centralnicreseller
{
    $client = new MockHttpClient(function (string $method, string $url, array $options) use ($replies): MockResponse {
        parse_str((string) $options['body'], $form);
        $command = [];
        foreach (explode("\n", (string) $form['s_command']) as $line) {
            [$key, $value] = explode('=', $line, 2);
            $command[$key] = $value;
        }
        $GLOBALS['centralnicreseller_calls'][] = ['command' => $command, 'form' => $form, 'url' => $url, 'method' => $method];
        return new MockResponse($replies[$command['COMMAND']] ?? centralnicresellerReply());
    });
    $adapter = new class(['username' => 'reseller', 'password' => 'live-secret'], $client) extends Registrar_Adapter_Centralnicreseller {
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
    $GLOBALS['centralnicreseller_calls'] = [];

    return $adapter;
}

function centralnicresellerDomain(string $tld): Registrar_Domain
{
    $contact = (new Registrar_Domain_Contact())->setFirstName('Ann')->setLastName('Lee')->setEmail('ann@example.com')->setCompany('Lee Ltd')
        ->setAddress1('1 Main St')->setAddress2('Floor 2')->setCity('London')->setZip('N1 1AA')->setCountry('gb')->setTelCc('44')->setTel('020 7946 0000');

    return (new Registrar_Domain())->setSld('example')->setTld($tld)->setRegistrationPeriod(2)
        ->setNs1('ns1.example.net')->setNs2('ns2.example.net')->setContactRegistrar($contact);
}

/** The commands sent, by name. */
function centralnicresellerCommands(): array
{
    return array_map(fn (array $call) => $call['command']['COMMAND'], $GLOBALS['centralnicreseller_calls']);
}

test('a .us registration sends the contact inline for all four roles, the name servers and the nexus answers, then turns auto-renewal off', function (): void {
    $adapter = centralnicresellerAdapter([]);
    $domain = centralnicresellerDomain('.us')->setFields(['nexus_category' => 'C31', 'nexus_country' => 'de', 'app_purpose' => 'P1']);
    $domain->getContactRegistrar()->setFirstName("Ann\nCOMMAND=DeleteDomain");
    $adapter->registerDomain($domain);

    $call = $GLOBALS['centralnicreseller_calls'][0];
    expect(centralnicresellerCommands())->toBe(['AddDomain', 'SetDomainRenewalMode'])
        ->and($call['url'])->toBe('https://api.rrpproxy.net/api/call.cgi')
        ->and($call['method'])->toBe('POST')
        ->and($call['form'])->toMatchArray(['s_login' => 'reseller', 's_pw' => 'live-secret'])
        ->and($call['command'])->toMatchArray(['DOMAIN' => 'example.us', 'PERIOD' => '2', 'NAMESERVER0' => 'ns1.example.net', 'NAMESERVER1' => 'ns2.example.net',
            'OWNERCONTACT0FIRSTNAME' => 'AnnCOMMAND=DeleteDomain', 'OWNERCONTACT0STREET' => '1 Main St , Floor 2', 'OWNERCONTACT0COUNTRY' => 'GB',
            'OWNERCONTACT0PHONE' => '+44.2079460000', 'ADMINCONTACT0EMAIL' => 'ann@example.com', 'TECHCONTACT0ORGANIZATION' => 'Lee Ltd', 'BILLINGCONTACT0ZIP' => 'N1 1AA',
            'X-US-NEXUS-CATEGORY' => 'C31', 'X-US-NEXUS-VALIDATOR' => 'DE', 'X-US-NEXUS-APPPURPOSE' => 'P1'])
        ->and($call['command']['COMMAND'])->toBe('AddDomain')
        ->and($GLOBALS['centralnicreseller_calls'][1]['command'])->toMatchArray(['DOMAIN' => 'example.us', 'RENEWALMODE' => 'AUTOEXPIRE']);
});

test('a refused renewal-mode follow-up does not fail the registration', function (): void {
    $adapter = centralnicresellerAdapter(['SetDomainRenewalMode' => centralnicresellerReply([], '549', 'Command failed')]);

    expect($adapter->registerDomain(centralnicresellerDomain('.com')))->toBeTrue();
});

test('a transfer sends the code with the period the zone allows; gTLDs get no contacts, .fr no owner or billing contact', function (): void {
    $adapter = centralnicresellerAdapter(['GetZoneInfo' => centralnicresellerReply(['TRANSFERPERIODS][0] = 1Y', 'RENEWALATTRANSFER][0] = 1'])]);
    $adapter->transferDomain(centralnicresellerDomain('.com')->setRegistrationPeriod(1)->setEpp('Ab1-xyz'));
    $adapter->transferDomain(centralnicresellerDomain('.fr')->setRegistrationPeriod(1)->setEpp('fr-code'));

    $com = $GLOBALS['centralnicreseller_calls'][1]['command'];
    $fr = $GLOBALS['centralnicreseller_calls'][3]['command'];
    expect(centralnicresellerCommands())->toBe(['GetZoneInfo', 'TransferDomain', 'GetZoneInfo', 'TransferDomain'])
        ->and($com)->toMatchArray(['DOMAIN' => 'example.com', 'ACTION' => 'REQUEST', 'FORCEREQUEST' => '1', 'AUTH' => 'Ab1-xyz', 'PERIOD' => '1', 'NAMESERVER0' => 'ns1.example.net'])
        ->and(array_filter(array_keys($com), fn (string $key) => str_contains($key, 'CONTACT')))->toBe([])
        ->and($fr)->toHaveKeys(['ADMINCONTACT0FIRSTNAME', 'TECHCONTACT0EMAIL'])
        ->and($fr)->not->toHaveKeys(['OWNERCONTACT0FIRSTNAME', 'BILLINGCONTACT0FIRSTNAME']);
});

test('a transfer in a zone that adds no year sends period 0; one it does not offer is refused; .eu sends the citizenship', function (): void {
    $adapter = centralnicresellerAdapter(['GetZoneInfo' => centralnicresellerReply(['TRANSFERPERIODS][0] = 0Y'])]);
    $adapter->transferDomain(centralnicresellerDomain('.es')->setEpp('code'));
    $adapter->transferDomain(centralnicresellerDomain('.eu')->setEpp('code')->setFields(['eu_citizenship' => 'DE']));

    expect($GLOBALS['centralnicreseller_calls'][1]['command'])->toMatchArray(['PERIOD' => '0'])
        ->and($GLOBALS['centralnicreseller_calls'][1]['command'])->not->toHaveKey('OWNERCONTACT0FIRSTNAME')
        ->and($GLOBALS['centralnicreseller_calls'][3]['command'])->toMatchArray(['X-EU-REGISTRANT-CITIZENSHIP' => 'de', 'OWNERCONTACT0FIRSTNAME' => 'Ann']);

    $adapter = centralnicresellerAdapter(['GetZoneInfo' => centralnicresellerReply(['TRANSFERPERIODS][0] = 1Y', 'RENEWALATTRANSFER][0] = 1'])]);
    expect(fn () => $adapter->transferDomain(centralnicresellerDomain('.com')->setEpp('code')))->toThrow(Registrar_Exception::class, 'does not offer a 2-year transfer')
        ->and(centralnicresellerCommands())->toBe(['GetZoneInfo']);
});

test('a renewal sends the paid-until year as EXPIRATION; a zone without explicit renewal renews once, one year only', function (): void {
    $adapter = centralnicresellerAdapter(['GetZoneInfo' => centralnicresellerReply(['RRPSUPPORTSRENEWAL][0] = 1']),
        'StatusDomain' => centralnicresellerReply(['paiduntil date][0] = 2027-03-01 10:00:00.0'])]);
    $adapter->renewDomain(centralnicresellerDomain('.com'));
    expect(centralnicresellerCommands())->toBe(['GetZoneInfo', 'StatusDomain', 'RenewDomain'])
        ->and($GLOBALS['centralnicreseller_calls'][2]['command'])->toMatchArray(['DOMAIN' => 'example.com', 'PERIOD' => '2', 'EXPIRATION' => '2027']);

    $adapter = centralnicresellerAdapter(['GetZoneInfo' => centralnicresellerReply(['RRPSUPPORTSRENEWAL][0] = 0'])]);
    $adapter->renewDomain(centralnicresellerDomain('.de')->setRegistrationPeriod(1));
    expect(centralnicresellerCommands())->toBe(['GetZoneInfo', 'SetDomainRenewalMode'])
        ->and($GLOBALS['centralnicreseller_calls'][1]['command'])->toMatchArray(['RENEWALMODE' => 'RENEWONCE'])
        ->and(fn () => $adapter->renewDomain(centralnicresellerDomain('.de')))->toThrow(Registrar_Exception::class, 'one year at a time');
});

test('name servers, lock and privacy are ModifyDomain changes; a cancel lets the domain expire', function (): void {
    $adapter = centralnicresellerAdapter([]);
    $adapter->modifyNs(centralnicresellerDomain('.com')->setNs3('ns3.example.net'));
    $adapter->lock(centralnicresellerDomain('.com'));
    $adapter->disablePrivacyProtection(centralnicresellerDomain('.com'));
    $adapter->deleteDomain(centralnicresellerDomain('.com'));

    $calls = array_column($GLOBALS['centralnicreseller_calls'], 'command');
    expect($calls[0])->toBe(['COMMAND' => 'ModifyDomain', 'DOMAIN' => 'example.com', 'NAMESERVER0' => 'ns1.example.net', 'NAMESERVER1' => 'ns2.example.net', 'NAMESERVER2' => 'ns3.example.net'])
        ->and($calls[1])->toMatchArray(['TRANSFERLOCK' => '1'])
        ->and($calls[2])->toMatchArray(['X-WHOIS-PRIVACY' => '0'])
        ->and($calls[3])->toMatchArray(['COMMAND' => 'SetDomainRenewalMode', 'RENEWALMODE' => 'AUTOEXPIRE'])
        // No longer in the account: the cancel still goes through, with a warning.
        ->and(centralnicresellerAdapter(['SetDomainRenewalMode' => centralnicresellerReply([], '545', 'Entity reference not found')])->deleteDomain(centralnicresellerDomain('.com')))->toBeTrue();
});

test('domain details come from StatusDomain: the earlier of paid-until and renewal date, lock, privacy, name servers without glue', function (): void {
    $adapter = centralnicresellerAdapter(['StatusDomain' => centralnicresellerReply([
        'created date][0] = 2024-01-02 08:00:00.0', 'paiduntil date][0] = 2027-01-02 08:00:00.0', 'renewal date][0] = 2026-12-20 08:00:00.0',
        'registration expiration date][0] = 2027-01-02 08:00:00.0', 'transferlock][0] = 1', 'x-whois-privacy][0] = 0',
        'nameserver][0] = NS1.EXAMPLE.NET', 'nameserver][1] = ns2.example.net 192.0.2.1',
    ])]);
    $details = $adapter->getDomainDetails(centralnicresellerDomain('.com'));

    expect(gmdate('Y-m-d', $details->getRegistrationTime()))->toBe('2024-01-02')
        ->and(gmdate('Y-m-d', $details->getExpirationTime()))->toBe('2026-12-20')
        ->and($details->getLocked())->toBeTrue()
        ->and($details->getPrivacyEnabled())->toBeFalse()
        ->and([$details->getNs1(), $details->getNs2(), $details->getNs3()])->toBe(['ns1.example.net', 'ns2.example.net', null]);
});

test('the transfer code: StatusDomain AUTH, SetAuthCode for .de, the registrant email when no code is returned', function (): void {
    $adapter = centralnicresellerAdapter(['StatusDomain' => centralnicresellerReply(['auth][0] = Xy7-code']), 'SetAuthCode' => centralnicresellerReply(['auth][0] = de-new'])]);
    expect($adapter->getEpp(centralnicresellerDomain('.com')))->toBe('Xy7-code')
        ->and($adapter->getEpp(centralnicresellerDomain('.de')))->toBe('de-new')
        ->and(centralnicresellerCommands())->toBe(['StatusDomain', 'SetAuthCode'])
        ->and(centralnicresellerAdapter([])->getEpp(centralnicresellerDomain('.com')))->toContain('emailed');
});

test('availability: available, taken, premium and a failed lookup', function (): void {
    $check = fn (array $properties) => centralnicresellerAdapter(['CheckDomains' => centralnicresellerReply($properties)])->isDomainAvailable(centralnicresellerDomain('.com'));

    expect($check(['domaincheck][0] = 210 Domain name available']))->toBeTrue()
        ->and($GLOBALS['centralnicreseller_calls'][0]['command'])->toMatchArray(['DOMAIN0' => 'example.com', 'X-FEE-COMMAND0' => 'create', 'X-FEE-PERIOD0' => '1'])
        ->and($check(['domaincheck][0] = 211 Domain name not available']))->toBeFalse()
        ->and(fn () => $check(['domaincheck][0] = 210 Domain name available', 'x-fee-class][0] = premium', 'x-fee-amount][0] = 2000.0000']))->toThrow(Registrar_Exception::class, 'Premium')
        ->and(fn () => $check(['domaincheck][0] = 549 Command failed; Unsupported TLD']))->toThrow(Registrar_Exception::class, 'Unsupported TLD');
});

test('transferability: request available, not available, a locked name and a premium name', function (): void {
    $adapter = fn (string $code, array $transfer = [], array $fee = []) => centralnicresellerAdapter([
        'GetZoneInfo' => centralnicresellerReply(['authcode][0] = required']),
        'CheckDomainTransfer' => centralnicresellerReply($transfer, $code, $code === '218' ? 'Request is available' : 'Request is not available'),
        'CheckDomains' => centralnicresellerReply(['domaincheck][0] = 211 Domain name not available', ...$fee]),
    ]);

    expect($adapter('218')->isDomaincanBeTransferred(centralnicresellerDomain('.com')->setEpp('abc')))->toBeTrue()
        ->and(centralnicresellerCommands())->toBe(['GetZoneInfo', 'CheckDomainTransfer', 'CheckDomains'])
        ->and($GLOBALS['centralnicreseller_calls'][1]['command'])->toMatchArray(['COMMAND' => 'CheckDomainTransfer', 'DOMAIN' => 'example.com', 'AUTH' => 'abc'])
        ->and($GLOBALS['centralnicreseller_calls'][2]['command'])->toMatchArray(['X-FEE-COMMAND0' => 'transfer'])
        ->and($adapter('219')->isDomaincanBeTransferred(centralnicresellerDomain('.com')->setEpp('abc')))->toBeFalse()
        ->and(fn () => $adapter('218', ['transferlock][0] = 1'])->isDomaincanBeTransferred(centralnicresellerDomain('.com')->setEpp('abc')))->toThrow(Registrar_Exception::class, 'transfer lock')
        ->and(fn () => $adapter('218', [], ['x-fee-class][0] = premium'])->isDomaincanBeTransferred(centralnicresellerDomain('.com')->setEpp('abc')))
        ->toThrow(Registrar_Exception::class, 'Premium domains cannot be transferred');
});

test('transferability without a pre-check (.co.uk): a registered name can be transferred, a free one cannot', function (): void {
    $adapter = fn (string $check) => centralnicresellerAdapter(['CheckDomains' => centralnicresellerReply(["domaincheck][0] = {$check}"])]);

    expect($adapter('211 Domain name not available')->isDomaincanBeTransferred(centralnicresellerDomain('.co.uk')->setEpp('TAG')))->toBeTrue()
        ->and(centralnicresellerCommands())->toBe(['GetZoneInfo', 'CheckDomains'])
        ->and($adapter('210 Domain name available')->isDomaincanBeTransferred(centralnicresellerDomain('.co.uk')->setEpp('TAG')))->toBeFalse();
});

test('a refused command is a registrar error with the API\'s code and text, and the password stays out of the log and the error', function (): void {
    $adapter = centralnicresellerAdapter(['ModifyDomain' => centralnicresellerReply([], '531', 'Authorization failed')]);

    try {
        $adapter->unlock(centralnicresellerDomain('.com'));
        $this->fail('No exception');
    } catch (Registrar_Exception $e) {
        expect($e->getMessage())->toContain('531 Authorization failed')->not->toContain('live-secret');
    }
    expect(json_encode($adapter->getLog()->calls))->toContain('531')->not->toContain('live-secret');
});

test('test mode uses the OT&E system', function (): void {
    $adapter = centralnicresellerAdapter([]);
    $adapter->enableTestMode();
    $adapter->enablePrivacyProtection(centralnicresellerDomain('.com'));

    expect($GLOBALS['centralnicreseller_calls'][0]['url'])->toBe('https://api-ote.rrpproxy.net/api/call.cgi')
        ->and($GLOBALS['centralnicreseller_calls'][0]['command'])->toMatchArray(['X-WHOIS-PRIVACY' => '1']);
});

test('the order form asks the .us and .ca questions on registration and the .eu citizenship on both', function (): void {
    expect(array_keys(Registrar_Adapter_Centralnicreseller::getDomainFields('.us')))->toBe(['nexus_category', 'nexus_country', 'app_purpose'])
        ->and(Registrar_Adapter_Centralnicreseller::getDomainFields('.us', 'transfer'))->toBe([])
        ->and(Registrar_Adapter_Centralnicreseller::getDomainFields('.CA')['ca_legal_type']['options'])->toHaveKeys(['CCT', 'CCO', 'TDM'])
        ->and(Registrar_Adapter_Centralnicreseller::getDomainFields('.eu', 'transfer')['eu_citizenship']['options'])->toHaveKey('DE')->toHaveKey('LI')
        ->and(Registrar_Adapter_Centralnicreseller::getDomainFields('.com'))->toBe([]);
});

test('the price list: setup plus annual to register, annual to renew, the transfer fee, in the account currency; inactive, premium-tier and foreign-currency zones left out', function (): void {
    $adapter = centralnicresellerAdapter([
        'StatusAccount' => centralnicresellerReply(['currency][0] = USD']),
        'QueryZoneList' => centralnicresellerReply([
            'zone][0] = com', '3rds][0] = com', 'active][0] = 1', 'periodtype][0] = YEAR', 'setup][0] = 0.00', 'annual][0] = 10.50', 'transfer][0] = 10.50', 'currency][0] = USD',
            'zone][1] = uk', '3rds][1] = co.uk, org.uk', 'active][1] = 1', 'periodtype][1] = YEAR', 'setup][1] = 1.00', 'annual][1] = 6.00', 'transfer][1] = 0.00', 'currency][1] = USD',
            'zone][2] = net', '3rds][2] = net', 'active][2] = 0', 'periodtype][2] = YEAR', 'setup][2] = 0', 'annual][2] = 11', 'transfer][2] = 11', 'currency][2] = USD',
            'zone][3] = com_prem1', '3rds][3] = com', 'active][3] = 1', 'periodtype][3] = YEAR', 'setup][3] = 0', 'annual][3] = 900', 'transfer][3] = 900', 'currency][3] = USD',
            'zone][4] = de', '3rds][4] = de', 'active][4] = 1', 'periodtype][4] = YEAR', 'setup][4] = 0', 'annual][4] = 5', 'transfer][4] = 5', 'currency][4] = EUR',
        ]),
    ]);

    expect($adapter->getTldPrices())->toBe(['currency' => 'USD', 'prices' => [
        '.com' => ['register' => 10.5, 'renew' => 10.5, 'transfer' => 10.5],
        '.co.uk' => ['register' => 7.0, 'renew' => 6.0, 'transfer' => 0.0],
        '.org.uk' => ['register' => 7.0, 'renew' => 6.0, 'transfer' => 0.0],
    ], 'remaining' => []])
        ->and(array_keys($adapter->getTldPrices(['.co.uk', '.net'])['prices']))->toBe(['.co.uk']);
});
