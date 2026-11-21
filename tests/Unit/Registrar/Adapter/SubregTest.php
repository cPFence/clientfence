<?php

declare(strict_types=1);

/**
 * A Subreg adapter whose SOAP client answers from $answers (command => response, or a closure given the request data)
 * and records [location, command, data]. Unanswered calls get {status: ok}; Login gets a session token.
 */
function subregAdapter(array $answers = [], bool $testMode = false): Registrar_Adapter_Subreg
{
    $adapter = new class(['login' => 'reseller', 'password' => 'subreg-secret']) extends Registrar_Adapter_Subreg {
        public array $answers = [];
        public array $calls = [];

        protected function client(string $location): object
        {
            return new class($this, $location) {
                public function __construct(private readonly object $adapter, private readonly string $location)
                {
                }

                public function __soapCall(string $command, array $args): mixed
                {
                    $this->adapter->calls[] = [$this->location, $command, $args['data']];
                    $answer = $this->adapter->answers[$command] ?? ($command === 'Login' ? ['status' => 'ok', 'data' => ['ssid' => 'sess-1']] : ['status' => 'ok', 'data' => ['orderid' => '24']]);

                    return $answer instanceof Closure ? $answer($args['data']) : $answer;
                }
            };
        }
    };
    $adapter->answers = $answers;
    $adapter->setLog(new Tests\Helpers\TestLogger());
    if ($testMode) {
        $adapter->enableTestMode();
    }

    return $adapter;
}

function subregDomain(string $tld = '.com', int $years = 2): Registrar_Domain
{
    $contact = (new Registrar_Domain_Contact())->setFirstName('Jana')->setLastName('Novakova')->setCompany('Acme s.r.o.')->setEmail('jana@example.cz')
        ->setAddress1('Vodickova 1')->setAddress2('Floor 2')->setCity('Praha')->setZip('11000')->setCountry('cz')->setTelCc('420')->setTel('222 123 456');

    return (new Registrar_Domain())->setSld('example')->setTld($tld)->setRegistrationPeriod($years)->setEpp('Ab1#cd')
        ->setNs1('ns1.example.net')->setNs2('ns2.example.net')->setContactRegistrar($contact);
}

/** The Make_Order calls: [type, params]. */
function subregOrders(Registrar_Adapter_Subreg $adapter): array
{
    return array_values(array_map(fn (array $c) => [$c[2]['order']['type'], $c[2]['order']['params']], array_filter($adapter->calls, fn (array $c) => $c[1] === 'Make_Order')));
}

/** The test contact as Type_Contact "new" (no state: empty fields are left out). */
function subregContact(): array
{
    return ['new' => ['name' => 'Jana', 'surname' => 'Novakova', 'org' => 'Acme s.r.o.', 'street' => 'Vodickova 1, Floor 2', 'city' => 'Praha',
        'pc' => '11000', 'cc' => 'CZ', 'phone' => '+420.222123456', 'email' => 'jana@example.cz']];
}

test('the form has the login and a secret password, both required', function (): void {
    $form = Registrar_Adapter_Subreg::getConfig()['form'];

    expect(array_keys($form))->toBe(['login', 'password'])
        ->and($form['password'][1]['secret'])->toBeTrue()
        ->and(Registrar_Adapter_Subreg::getSecretFields())->toBe(['password'])
        ->and(fn () => new Registrar_Adapter_Subreg([]))->toThrow(Registrar_Exception::class, 'Username')
        ->and(fn () => new Registrar_Adapter_Subreg(['login' => 'reseller']))->toThrow(Registrar_Exception::class, 'Password');
});

test('the first call logs in once and every call carries the session token, to the live endpoint', function (): void {
    $adapter = subregAdapter(['Check_Domain' => ['status' => 'ok', 'data' => ['name' => 'example.com', 'avail' => 1]]]);
    $adapter->isDomainAvailable(subregDomain());
    $adapter->isDomainAvailable(subregDomain());

    expect(array_column($adapter->calls, 1))->toBe(['Login', 'Check_Domain', 'Check_Domain'])
        ->and($adapter->calls[0][2])->toBe(['login' => 'reseller', 'password' => 'subreg-secret'])
        ->and($adapter->calls[1][2])->toBe(['ssid' => 'sess-1', 'domain' => 'example.com'])
        ->and(array_unique(array_column($adapter->calls, 0)))->toBe(['https://soap.subreg.cz/cmd.php']);
});

test('test mode sends every call to the OT&E system, never to the live one', function (): void {
    $adapter = subregAdapter([], true);
    $adapter->registerDomain(subregDomain());

    expect(array_unique(array_column($adapter->calls, 0)))->toBe(['https://soap.demoreg.net/cmd.php']);
});

test('a registration orders Create_Domain with the period, the contact for every role and the name servers', function (): void {
    $adapter = subregAdapter();

    expect($adapter->registerDomain(subregDomain('.com', 3)))->toBeTrue()
        ->and($adapter->calls[1][2]['order']['domain'])->toBe('example.com')
        ->and(subregOrders($adapter))->toBe([['Create_Domain', [
            'period' => 3,
            'registrant' => subregContact(),
            'contacts' => ['admin' => subregContact(), 'tech' => subregContact(), 'billing' => subregContact()],
            'ns' => ['hosts' => [['hostname' => 'ns1.example.net'], ['hostname' => 'ns2.example.net']]],
        ]]]);
});

test('a .us registration asks the nexus category and purpose and sends them as params', function (): void {
    $adapter = subregAdapter();

    $adapter->registerDomain(subregDomain('.us')->setFields(['nexus_category' => 'C11', 'app_purpose' => 'P3']));
    expect(array_keys(Registrar_Adapter_Subreg::getDomainFields('.us')))->toBe(['nexus_category', 'app_purpose'])
        ->and(Registrar_Adapter_Subreg::getDomainFields('.com'))->toBe([])
        ->and(subregOrders($adapter)[0][1]['params'])->toBe(['us-nexus-category' => 'C11', 'us-nexus-apppurpose' => 'P3']);
});

test('a transfer sends the code and the new registrant and admin', function (): void {
    $adapter = subregAdapter();
    $adapter->transferDomain(subregDomain());

    expect(subregOrders($adapter))->toBe([['Transfer_Domain', ['authid' => 'Ab1#cd', 'new' => ['registrant' => subregContact(), 'admin' => subregContact()]]]]);
});

test('a renewal sends the period and the registry expiry as curExpDate', function (): void {
    $adapter = subregAdapter(['Info_Domain' => ['status' => 'ok', 'data' => ['domain' => 'example.com', 'exDate' => '2027-01-06']]]);
    $adapter->renewDomain(subregDomain('.com', 1));

    expect(subregOrders($adapter))->toBe([['Renew_Domain', ['period' => 1, 'curExpDate' => '2027-01-06']]]);
});

test('name servers, contacts and a delete are their own orders', function (): void {
    $adapter = subregAdapter();
    $adapter->modifyNs(subregDomain()->setNs3('ns3.example.net'));
    $adapter->modifyContact(subregDomain());
    $adapter->deleteDomain(subregDomain());

    expect(subregOrders($adapter))->toBe([
        ['ModifyNS_Domain', ['ns' => ['hosts' => [['hostname' => 'ns1.example.net'], ['hostname' => 'ns2.example.net'], ['hostname' => 'ns3.example.net']]]]],
        ['Modify_Domain', ['registrant' => subregContact(), 'contacts' => ['admin' => subregContact(), 'tech' => subregContact(), 'billing' => subregContact()]]],
        ['Delete_Domain', ['reason' => 'Order cancelled']],
    ]);
});

test('domain details set the expiry and creation at noon UTC, the lock, privacy and name servers', function (): void {
    $adapter = subregAdapter(['Info_Domain' => ['status' => 'ok', 'data' => ['domain' => 'example.com', 'exDate' => '2027-01-06', 'crDate' => '2011-01-06',
        'authid' => 'secret-code', 'status' => ['clientTransferProhibited'], 'whoisproxy' => 1, 'hosts' => ['ns1.subreg.cz', 'ns2.subreg.cz']]]]);
    $domain = $adapter->getDomainDetails(subregDomain()->setNs3('old.example.net'));

    expect($domain->getExpirationTime())->toBe(gmmktime(12, 0, 0, 1, 6, 2027))
        ->and($domain->getRegistrationTime())->toBe(gmmktime(12, 0, 0, 1, 6, 2011))
        ->and($domain->getLocked())->toBeTrue()
        ->and($domain->getPrivacyEnabled())->toBeTrue()
        ->and([$domain->getNs1(), $domain->getNs2(), $domain->getNs3()])->toBe(['ns1.subreg.cz', 'ns2.subreg.cz', ''])
        ->and($domain->getEpp())->toBe('Ab1#cd');
});

test('domain details without an expiry throw instead of keeping the stored one', function (): void {
    $adapter = subregAdapter(['Info_Domain' => ['status' => 'ok', 'data' => ['domain' => 'example.com', 'exDate' => '']]]);

    expect(fn () => $adapter->getDomainDetails(subregDomain()))->toThrow(Registrar_Exception::class, 'did not report an expiry date');
});

test('the transfer code is the authid; none is a clear error', function (): void {
    expect(subregAdapter(['Info_Domain' => ['status' => 'ok', 'data' => ['authid' => 'rjI?5sjb']]])->getEpp(subregDomain()))->toBe('rjI?5sjb')
        ->and(fn () => subregAdapter(['Info_Domain' => ['status' => 'ok', 'data' => []]])->getEpp(subregDomain()))->toThrow(Registrar_Exception::class, 'did not return a transfer code');
});

test('lock and unlock send the client statuses with clientTransferProhibited added or removed, never ok or server statuses', function (): void {
    $info = ['Info_Domain' => fn () => ['status' => 'ok', 'data' => ['status' => ['ok', 'clientDeleteProhibited', 'serverUpdateProhibited', 'clientTransferProhibited']]]];
    $lock = subregAdapter($info);
    $lock->lock(subregDomain());
    $unlock = subregAdapter($info);
    $unlock->unlock(subregDomain());

    expect(subregOrders($lock))->toBe([['Modify_Domain', ['statuses' => ['clientDeleteProhibited', 'clientTransferProhibited']]]])
        ->and(subregOrders($unlock))->toBe([['Modify_Domain', ['statuses' => ['clientDeleteProhibited']]]]);
});

test('availability reads avail; no answer, a trademark claim and a premium name are errors, not "taken"', function (): void {
    $answer = fn (array $data) => ['Check_Domain' => ['status' => 'ok', 'data' => $data]];

    expect(subregAdapter($answer(['avail' => 1]))->isDomainAvailable(subregDomain()))->toBeTrue()
        ->and(subregAdapter($answer(['avail' => '0']))->isDomainAvailable(subregDomain()))->toBeFalse()
        ->and(fn () => subregAdapter($answer([]))->isDomainAvailable(subregDomain()))->toThrow(Registrar_Exception::class, 'did not say whether')
        ->and(fn () => subregAdapter($answer(['avail' => 1, 'existing_claim_id' => '2013/z/u/qv']))->isDomainAvailable(subregDomain()))->toThrow(Registrar_Exception::class, 'trademark claim')
        ->and(fn () => subregAdapter($answer(['avail' => 1, 'price' => ['amount' => '2500', 'premium' => 1, 'currency' => 'USD']]))->isDomainAvailable(subregDomain()))->toThrow(Registrar_Exception::class, 'Premium domains cannot be registered.')
        ->and(fn () => subregAdapter($answer(['avail' => 0, 'price_transfer' => ['amount' => '900', 'premium' => '1', 'currency' => 'USD']]))->isDomaincanBeTransferred(subregDomain()))->toThrow(Registrar_Exception::class, 'Premium domains cannot be transferred.');
});

test('a transfer is possible for a taken name not already in this account', function (array $inSubreg, int $avail, bool $expected): void {
    $adapter = subregAdapter(['Check_Domain' => ['status' => 'ok', 'data' => ['avail' => $avail]], 'In_Subreg' => $inSubreg]);

    expect($adapter->isDomaincanBeTransferred(subregDomain()))->toBe($expected);
})->with([
    'not at Subreg' => [['status' => 'error', 'error' => ['errormsg' => 'Domain does not exist', 'errorcode' => ['major' => 507, 'minor' => 1009]]], 0, true],
    'this account' => [['status' => 'ok', 'data' => ['myaccount' => 'yes']], 0, false],
    'free name' => [['status' => 'error'], 1, false],
]);

test('a name in another Subreg account is refused at checkout: it needs Subreg\'s internal transfer', function (): void {
    $adapter = subregAdapter(['Check_Domain' => ['status' => 'ok', 'data' => ['avail' => 0]], 'In_Subreg' => ['status' => 'ok', 'data' => ['myaccount' => 'no']]]);

    expect(fn () => $adapter->isDomaincanBeTransferred(subregDomain()))->toThrow(Registrar_Exception::class, 'internal transfer');
});

test('the transfer status is completed once the name is in this account, else pending', function (): void {
    expect(subregAdapter(['In_Subreg' => ['status' => 'ok', 'data' => ['myaccount' => 'yes']]])->getTransferStatus(subregDomain())['status'])->toBe(Registrar_TransferStatus::COMPLETED)
        ->and(subregAdapter(['In_Subreg' => ['status' => 'error']])->getTransferStatus(subregDomain())['status'])->toBe(Registrar_TransferStatus::PENDING);
});

test('prices come from Pricelist per TLD with the leading dot, filtered, a 0 register or renew is not offered, a 0 transfer is free; two currencies are refused', function (): void {
    $list = ['status' => 'ok', 'data' => [
        'com' => ['currency' => 'USD', 'prices' => ['register' => '19.07', 'renew' => '19.07', 'transfer' => '0', 'restore' => '121.44']],
        'org' => ['currency' => 'USD', 'prices' => ['register' => '18.00', 'renew' => '18.00', 'transfer' => '18.00']],
        'cz' => ['currency' => 'CZK', 'prices' => ['register' => '200']],
    ]];

    expect(subregAdapter(['Pricelist' => $list])->getTldPrices(['.com', 'org']))->toBe(['currency' => 'USD', 'prices' => [
        '.com' => ['register' => 19.07, 'renew' => 19.07, 'transfer' => 0.0],
        '.org' => ['register' => 18.0, 'renew' => 18.0, 'transfer' => 18.0],
    ], 'remaining' => []])
        ->and(fn () => subregAdapter(['Pricelist' => $list])->getTldPrices())->toThrow(Registrar_Exception::class, 'more than one currency');
});

test('an API error is a registrar error with its message, from "error" or "data", and the password stays out of the log', function (array $reply): void {
    $adapter = subregAdapter(['Make_Order' => $reply]);

    expect(fn () => $adapter->renewDomain(subregDomain()))->toThrow(Registrar_Exception::class, 'Not enough credit')
        ->and(json_encode($adapter->getLog()->calls))->toContain('Not enough credit')->not->toContain('subreg-secret')->not->toContain('jana@example.cz');
})->with([
    'error' => [['status' => 'error', 'error' => ['errormsg' => 'Not enough credit', 'errorcode' => ['major' => 506, 'minor' => 1001]]]],
    'data' => [['status' => 'error', 'data' => ['errormsg' => 'Not enough credit', 'errorcode' => 1002]]],
]);

test('a refused login stops before the command', function (): void {
    $adapter = subregAdapter(['Login' => ['status' => 'error', 'error' => ['errormsg' => 'Incorrect login or password']]]);

    expect(fn () => $adapter->modifyNs(subregDomain()))->toThrow(Registrar_Exception::class, 'Incorrect login or password')
        ->and(array_column($adapter->calls, 1))->toBe(['Login']);
});

test('privacy is not supported', function (): void {
    $adapter = subregAdapter();

    expect(fn () => $adapter->enablePrivacyProtection(subregDomain()))->toThrow(Registrar_Exception::class, 'does not support')
        ->and(fn () => $adapter->disablePrivacyProtection(subregDomain()))->toThrow(Registrar_Exception::class, 'does not support')
        ->and($adapter->calls)->toBe([]);
});

test('without the soap extension the adapter says so', function (): void {
    expect(fn () => (new Registrar_Adapter_Subreg(['login' => 'reseller', 'password' => 'x']))->isDomainAvailable(subregDomain()))
        ->toThrow(Registrar_Exception::class, 'needs the PHP soap extension');
})->skip(class_exists('SoapClient'), 'the soap extension is installed: this would reach the network');
