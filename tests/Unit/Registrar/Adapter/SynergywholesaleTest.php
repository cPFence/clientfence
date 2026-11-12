<?php

declare(strict_types=1);

/** A Synergy Wholesale adapter whose SOAP client answers from $answers (command => response) and records the calls. */
function synergyAdapter(array $answers): Registrar_Adapter_Synergywholesale
{
    $adapter = new class(['reseller_id' => '1234', 'api_key' => 'secret']) extends Registrar_Adapter_Synergywholesale {
        public array $answers = [];
        public array $calls = [];

        protected function client(): object
        {
            return new class($this) {
                public function __construct(private readonly object $adapter)
                {
                }

                public function __call(string $command, array $args): object
                {
                    $this->adapter->calls[] = [$command, $args[0]];

                    return $this->adapter->answers[$command] ?? (object) ['status' => 'OK'];
                }
            };
        }
    };
    $adapter->answers = $answers;
    $adapter->setLog(new Tests\Helpers\TestLogger());

    return $adapter;
}

function synergyDomain(string $tld): Registrar_Domain
{
    $contact = (new Registrar_Domain_Contact())->setFirstName('Ann')->setLastName('Lee')->setEmail('ann@example.com')
        ->setCompany('Example Pty Ltd')->setAddress1('1 George St')->setCity('Sydney')->setState('New South Wales')
        ->setZip('2000')->setCountry('AU')->setTelCc('61')->setTel('02 9999 9999');

    return (new Registrar_Domain())->setSld('example')->setTld($tld)->setRegistrationPeriod(2)
        ->setNs1('ns1.example.net')->setNs2('ns2.example.net')->setContactRegistrar($contact);
}

test('a .com.au registration sends the eligibility answers and the contact in the API format', function (): void {
    $adapter = synergyAdapter([]);
    $domain = synergyDomain('.com.au')->setFields(['registrant_name' => 'Example Pty Ltd', 'registrant_id' => '12345678901', 'registrant_id_type' => 'ABN', 'eligibility_type' => 'Company']);

    expect($adapter->registerDomain($domain))->toBeTrue();
    [$command, $request] = $adapter->calls[0];
    expect($command)->toBe('domainRegister')
        ->and($request)->toMatchArray(['resellerID' => '1234', 'apiKey' => 'secret', 'domainName' => 'example.com.au', 'years' => 2,
            'nameServers' => ['ns1.example.net', 'ns2.example.net'], 'registrant_state' => 'NSW', 'registrant_phone' => '+61.299999999',
            'registrant_address' => ['1 George St']])
        ->and(json_decode($request['eligibility'], true))->toBe(['registrantName' => 'Example Pty Ltd', 'registrantID' => '12345678901',
            'registrantIDType' => 'ABN', 'eligibilityType' => 'Company']);
});

test('a .us registration sends the nexus answers', function (): void {
    $adapter = synergyAdapter([]);
    $adapter->registerDomain(synergyDomain('.us')->setFields(['nexus_category' => 'C31', 'nexus_country' => 'au', 'app_purpose' => 'P1']));

    expect(json_decode($adapter->calls[0][1]['eligibility'], true))->toBe(['nexusCategory' => 'C31', 'nexusCountry' => 'AU', 'appPurpose' => 'P1']);
});

test('an error status from the API is a registrar error with its message', function (): void {
    $adapter = synergyAdapter(['renewDomain' => (object) ['status' => 'ERR_RENEWDOMAIN_FAILED', 'errorMessage' => 'Insufficient funds']]);

    expect(fn () => $adapter->renewDomain(synergyDomain('.com')))->toThrow(Registrar_Exception::class, 'Insufficient funds');
});

test('availability and transfers are checked with bulkCheckDomain', function (): void {
    $adapter = synergyAdapter(['bulkCheckDomain' => (object) ['status' => 'OK', 'domainList' => [(object) ['domain' => 'example.com', 'available' => true]]]]);

    expect($adapter->isDomainAvailable(synergyDomain('.com')))->toBeTrue()
        ->and($adapter->isDomaincanBeTransferred(synergyDomain('.com')))->toBeTrue()
        ->and(array_map(fn ($call) => $call[1]['command'], $adapter->calls))->toBe(['create', 'transfer']);
});

test('a .au transfer does not renew and never asks whether it must', function (): void {
    $adapter = synergyAdapter([]);
    $adapter->transferDomain(synergyDomain('.com.au')->setEpp('abc'));

    expect(array_column($adapter->calls, 0))->toBe(['transferDomain'])
        ->and($adapter->calls[0][1])->toMatchArray(['authInfo' => 'abc', 'doRenewal' => false, 'firstname' => 'Ann']);
});

test('the order form asks for the .au eligibility details only', function (): void {
    $types = fn (string $tld) => array_keys(Registrar_Adapter_Synergywholesale::getDomainFields($tld)['eligibility_type']['options']);

    expect(Registrar_Adapter_Synergywholesale::getDomainFields('.com'))->toBe([])
        ->and(array_keys(Registrar_Adapter_Synergywholesale::getDomainFields('.id.au')))->toBe(['registrant_name', 'eligibility_type'])
        ->and($types('.id.au'))->toBe(['Citizen/Resident'])
        ->and($types('.com.au'))->not->toContain('Citizen/Resident')->toContain('Company')
        ->and($types('.au'))->toContain('Citizen/Resident')
        ->and(Registrar_Adapter_Synergywholesale::getDomainFields('.com.au')['registrant_id']['required'])->toBeTrue()
        ->and(Registrar_Adapter_Synergywholesale::getDomainFields('.au')['registrant_id']['required'])->toBeFalse()
        ->and(array_keys(Registrar_Adapter_Synergywholesale::getDomainFields('.us')))->toBe(['nexus_category', 'nexus_country', 'app_purpose']);
});

test('domain details come from domainInfo', function (): void {
    $adapter = synergyAdapter(['domainInfo' => (object) ['status' => 'OK', 'createdDate' => '2024-01-02 10:00:00', 'domain_expiry' => '2027-01-02 10:00:00',
        'domain_status' => 'clientTransferProhibited', 'idProtect' => 'Enabled', 'domainPassword' => 'Ab1!', 'nameServers' => ['NS1.EXAMPLE.NET', 'ns2.example.net']]]);
    $details = $adapter->getDomainDetails(synergyDomain('.com'));

    expect(date('Y-m-d', $details->getRegistrationTime()))->toBe('2024-01-02')
        ->and(date('Y-m-d', $details->getExpirationTime()))->toBe('2027-01-02')
        ->and($details->getLocked())->toBeTrue()
        ->and($details->getPrivacyEnabled())->toBeTrue()
        ->and($details->getEpp())->toBe('Ab1!')
        ->and($details->getNs1())->toBe('ns1.example.net')
        ->and($details->getContactRegistrar()->getEmail())->toBe('ann@example.com');
});

test('a cancelled domain is left to expire: the API cannot delete it', function (): void {
    $adapter = synergyAdapter([]);

    expect($adapter->deleteDomain(synergyDomain('.com')))->toBeTrue()->and($adapter->calls)->toBe([]);
});

test('test mode refuses every call', function (): void {
    $adapter = synergyAdapter([]);
    $adapter->enableTestMode();

    expect(fn () => $adapter->renewDomain(synergyDomain('.com')))->toThrow(Registrar_Exception::class)
        ->and($adapter->calls)->toBe([]);
});

test('the price list reads the one-year costs in AUD from getDomainPricing; a missing price is null', function (): void {
    $adapter = synergyAdapter(['getDomainPricing' => (object) ['status' => 'OK', 'errorMessage' => '', 'pricing' => [
        (object) ['tld' => 'com.au', 'minPeriod' => 1, 'maxPeriod' => 5, 'register_1_year' => '11.25', 'register_2_year' => '22.50', 'renew' => '11.25', 'transfer' => '0.00'],
        (object) ['tld' => 'ai', 'minPeriod' => 2, 'maxPeriod' => 10, 'register_1_year' => null, 'register_2_year' => '150.00', 'renew' => '75.00', 'transfer' => '75.00'],
    ]]]);

    expect($adapter->getTldPrices())->toBe(['currency' => 'AUD', 'prices' => [
        '.com.au' => ['register' => 11.25, 'renew' => 11.25, 'transfer' => 0.0],
        '.ai' => ['register' => null, 'renew' => 75.0, 'transfer' => 75.0],
    ], 'remaining' => []])
        ->and(array_keys($adapter->getTldPrices(['.ai'])['prices']))->toBe(['.ai']);
});

test('the transfer status reads domainInfo: an active name completed, a rejected, cancelled or timed out transfer failed, anything else pending', function (array $info, string $status, string $message): void {
    $adapter = synergyAdapter(['domainInfo' => (object) $info]);

    expect($adapter->getTransferStatus(synergyDomain('.com.au')))->toBe(['status' => $status, 'message' => $message])
        ->and($adapter->calls[0])->toBe(['domainInfo', ['resellerID' => '1234', 'apiKey' => 'secret', 'domainName' => 'example.com.au']]);
})->with([
    'completed' => [['status' => 'OK', 'domain_status' => 'clientTransferProhibited'], Registrar_TransferStatus::COMPLETED, 'clientTransferProhibited'],
    'failed' => [['status' => 'OK', 'domain_status' => 'transfer_rejected'], Registrar_TransferStatus::FAILED, 'transfer_rejected'],
    'failed, no domain status' => [['status' => 'OK_TRANSFER_TIMEOUT', 'transfer_status' => 'Transfer timed out'], Registrar_TransferStatus::FAILED, 'Transfer timed out'],
    'pending' => [['status' => 'OK', 'transfer_status' => 'Pending registrant approval'], Registrar_TransferStatus::PENDING, 'Pending registrant approval'],
    'unknown' => [['status' => 'OK', 'domain_status' => 'somethingNew'], Registrar_TransferStatus::PENDING, 'somethingNew'],
]);
