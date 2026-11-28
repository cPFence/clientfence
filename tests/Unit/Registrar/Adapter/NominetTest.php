<?php

declare(strict_types=1);

/** An EPP reply as Nominet's documentation shows it: result code and message, then the resData. */
function nominetReply(string $resData = '', int $code = 1000, string $msg = 'Command completed successfully', string $extValue = ''): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="no"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="' . $code . '"><msg>' . $msg . '</msg>' . $extValue . '</result>'
        . ($resData !== '' ? '<resData>' . $resData . '</resData>' : '') . '<trID><clTRID>ABC-12345</clTRID><svTRID>54321-XYZ</svTRID></trID></response></epp>';
}

function nominetInfo(string $clId = 'EXAMPLE-TAG', string $hosts = '<domain:hostObj>ns1.example.net</domain:hostObj><domain:hostObj>ns2.example.net</domain:hostObj>', string $statuses = '<domain:status s="ok"/>'): string
{
    return nominetReply('<domain:infData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"><domain:name>example.co.uk</domain:name><domain:roid>7854785487-UK</domain:roid>'
        . $statuses . '<domain:registrant>S70T1</domain:registrant><domain:ns>' . $hosts . '</domain:ns><domain:clID>' . $clId . '</domain:clID><domain:crID>epp@epp-example.</domain:crID>'
        . '<domain:crDate>2008-01-01T13:34:20.0Z</domain:crDate><domain:exDate>2027-01-02T13:34:20.0Z</domain:exDate></domain:infData>');
}

/**
 * A Nominet adapter whose EPP session answers each command (login, domain:check, domain:info, ...) from $replies:
 * a reply, or a list of replies used in turn; 1000 without resData otherwise. Commands go to $GLOBALS['nominet_calls'].
 */
function nominetAdapter(array $replies = [], array $options = ['username' => 'EXAMPLE-TAG', 'password' => 'epp-secret-password-1', 'cost_price' => '3.65'], bool $standardised = false): Registrar_Adapter_Nominet
{
    $adapter = new class($options) extends Registrar_Adapter_Nominet {
        public static bool $standardised = false;
        public array $replies = [];

        public static function ukStandardised(): bool
        {
            return self::$standardised;
        }

        protected function connect(): string
        {
            return '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><greeting><svID>Nominet EPP server epp.nominet.org.uk</svID>'
                . '<svDate>2018-07-24T08:18:36Z</svDate></greeting></epp>';
        }

        protected function exchange(string $xml): string
        {
            preg_match('/<command><(\w+)(?:\s[^>]*)?\/?>(?:<(\w+:\w+))?/', $xml, $m);
            $name = ($m[2] ?? '') !== '' ? $m[2] : $m[1];
            $GLOBALS['nominet_calls'][] = [$name, $xml];
            $reply = $this->replies[$name] ?? nominetReply();

            $reply = is_array($reply) ? array_shift($this->replies[$name]) : $reply;
            if ($reply instanceof Throwable) {
                throw $reply;
            }

            return $reply;
        }
    };
    $adapter::$standardised = $standardised;
    $adapter->replies = $replies;
    $adapter->setLog(new Tests\Helpers\TestLogger());
    $GLOBALS['nominet_calls'] = [];

    return $adapter;
}

function nominetDomain(string $tld = '.co.uk'): Registrar_Domain
{
    $contact = (new Registrar_Domain_Contact())->setFirstName('Ann')->setLastName('Lee')->setCompany('Lee Trading Ltd')->setEmail('ann@example.com')
        ->setAddress1('1 Main St')->setCity('London')->setZip('N1 1AA')->setCountry('gb')->setTelCc('44')->setTel('020 7946 0000');

    return (new Registrar_Domain())->setSld('example')->setTld($tld)->setRegistrationPeriod(2)
        ->setNs1('ns1.example.net')->setNs2('ns2.example.net')->setContactRegistrar($contact);
}

function nominetCall(string $name): string
{
    foreach ($GLOBALS['nominet_calls'] as [$command, $xml]) {
        if ($command === $name) {
            return $xml;
        }
    }

    return '';
}

test('a registration logs in with the tag, creates the registrant account with the .uk type, then the domain on it', function (): void {
    $adapter = nominetAdapter();
    $adapter->registerDomain(nominetDomain()->setFields(['registrant_type' => 'LTD', 'company_number' => '12345678']));

    expect(array_column($GLOBALS['nominet_calls'], 0))->toBe(['login', 'contact:create', 'domain:create']);
    $login = nominetCall('login');
    expect($login)->toContain('<clID>EXAMPLE-TAG</clID>')->toContain('<pw>epp-secret-password-1</pw>')
        ->toContain('<objURI>urn:ietf:params:xml:ns:domain-1.0</objURI>')->toContain('<extURI>http://www.nominet.org.uk/epp/xml/contact-nom-ext-1.0</extURI>');

    $contact = nominetCall('contact:create');
    preg_match('/<contact:id>([^<]+)<\/contact:id>/', $contact, $id);
    expect($contact)->toContain('<contact:name>Ann Lee</contact:name><contact:org>Lee Trading Ltd</contact:org>')
        ->toContain('<contact:street>1 Main St</contact:street><contact:city>London</contact:city><contact:pc>N1 1AA</contact:pc><contact:cc>GB</contact:cc>')
        ->toContain('<contact:voice>+44.2079460000</contact:voice><contact:email>ann@example.com</contact:email>')
        ->toContain('<contact-ext:create xmlns:contact-ext="http://www.nominet.org.uk/epp/xml/contact-nom-ext-1.0"><contact-ext:type>LTD</contact-ext:type><contact-ext:co-no>12345678</contact-ext:co-no></contact-ext:create>');

    expect(nominetCall('domain:create'))->toContain('<domain:name>example.co.uk</domain:name><domain:period unit="y">2</domain:period>')
        ->toContain('<domain:ns><domain:hostObj>ns1.example.net</domain:hostObj><domain:hostObj>ns2.example.net</domain:hostObj></domain:ns>')
        ->toContain('<domain:registrant>' . $id[1] . '</domain:registrant>')
        ->not->toContain('<domain:contact');
});

test('a 2-character tag logs in with a leading #, and the session ends with a logout', function (): void {
    $adapter = nominetAdapter([], ['username' => 'AB', 'password' => 'epp-secret-password-1']);
    $adapter->lock(nominetDomain());
    $adapter->__destruct();

    expect(nominetCall('login'))->toContain('<clID>#AB</clID>')
        ->and(array_column($GLOBALS['nominet_calls'], 0))->toBe(['login', 'domain:update', 'logout'])
        ->and(nominetCall('domain:update'))->toContain('<domain:add><domain:status s="clientTransferProhibited"/></domain:add>');
});

test('a transfer sends nothing and waits until the domain is on the tag', function (): void {
    $notYet = nominetReply('', 2201, 'Authorization error');
    $adapter = nominetAdapter(['domain:info' => [$notYet, $notYet, nominetInfo()]]);

    expect($adapter->transferDomain(nominetDomain()->setEpp('ignored')))->toBeTrue()
        ->and($adapter->getTransferStatus(nominetDomain()))->toBe(['status' => Registrar_TransferStatus::PENDING, 'message' => 'Waiting for the current registrar to change the IPS tag of example.co.uk to EXAMPLE-TAG.'])
        ->and($adapter->getTransferStatus(nominetDomain())['status'])->toBe(Registrar_TransferStatus::COMPLETED)
        ->and(array_column($GLOBALS['nominet_calls'], 0))->toBe(['login', 'domain:info', 'domain:info', 'domain:info']);
});

test('only a registered domain on another tag can be transferred', function (): void {
    $check = fn (string $avail) => nominetReply('<domain:chkData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"><domain:cd><domain:name avail="' . $avail . '">example.co.uk</domain:name></domain:cd></domain:chkData>');

    expect(nominetAdapter(['domain:check' => $check('0'), 'domain:info' => nominetReply('', 2201, 'Authorization error')])->isDomaincanBeTransferred(nominetDomain()))->toBeTrue()
        ->and(nominetAdapter(['domain:check' => $check('0'), 'domain:info' => nominetInfo()])->isDomaincanBeTransferred(nominetDomain()))->toBeFalse()
        ->and(nominetAdapter(['domain:check' => $check('1')])->isDomaincanBeTransferred(nominetDomain()))->toBeFalse()
        ->and(nominetAdapter(['domain:check' => $check('1')])->isDomainAvailable(nominetDomain()))->toBeTrue()
        ->and(nominetAdapter(['domain:check' => $check('0')])->isDomainAvailable(nominetDomain()))->toBeFalse();
});

test('a renewal sends the expiry date Nominet holds and the period', function (): void {
    $adapter = nominetAdapter(['domain:info' => nominetInfo()]);
    $adapter->renewDomain(nominetDomain());

    expect(nominetCall('domain:renew'))->toContain('<domain:name>example.co.uk</domain:name><domain:curExpDate>2027-01-02</domain:curExpDate><domain:period unit="y">2</domain:period>');
});

test('a refused command is a registrar error with Nominet\'s message and code, and the password stays out of the log', function (): void {
    $adapter = nominetAdapter(['domain:info' => nominetInfo(), 'domain:renew' => nominetReply('', 2105, 'Object is not eligible for renewal')]);

    try {
        $adapter->renewDomain(nominetDomain());
        $this->fail('No exception');
    } catch (Registrar_Exception $e) {
        expect($e->getMessage())->toContain('Object is not eligible for renewal')->and($e->getCode())->toBe(2105);
    }

    $login = nominetAdapter(['login' => nominetReply('', 2200, 'Authentication error')]);
    expect(fn () => $login->lock(nominetDomain()))->toThrow(Registrar_Exception::class, 'Authentication error');
    expect(json_encode([$adapter->getLog()->calls, $login->getLog()->calls]))->not->toContain('epp-secret-password-1');

    $postcode = nominetAdapter(['contact:create' => nominetReply('', 2005, 'Parameter value syntax error', '<extValue><value><contact:pc xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">ZZ9</contact:pc></value><reason>V029 Invalid postcode: ZZ9</reason></extValue>')]);
    expect(fn () => $postcode->registerDomain(nominetDomain()))->toThrow(Registrar_Exception::class, 'V029 Invalid postcode: ZZ9')
        ->and(json_encode($postcode->getLog()->calls))->toContain('2005 Parameter value syntax error')->not->toContain('ZZ9');
});

test('a read that times out ends the session: the next command logs in again on a new connection', function (): void {
    $adapter = nominetAdapter(['domain:info' => [new Registrar_Exception('Could not reach :registrar: :error', [':registrar' => 'Nominet', ':error' => 'read']), nominetInfo()]]);

    expect(fn () => $adapter->getDomainDetails(nominetDomain()))->toThrow(Registrar_Exception::class, 'read');
    $adapter->getDomainDetails(nominetDomain());
    expect(array_column($GLOBALS['nominet_calls'], 0))->toBe(['login', 'domain:info', 'login', 'domain:info']);
});

test('name servers: only the changes are sent, and nothing when they are the same', function (): void {
    $adapter = nominetAdapter(['domain:info' => nominetInfo(hosts: '<domain:hostObj>NS0.OLD.NET.</domain:hostObj><domain:hostObj>ns1.example.net</domain:hostObj>')]);
    $adapter->modifyNs(nominetDomain());

    expect(nominetCall('domain:update'))->toContain('<domain:add><domain:ns><domain:hostObj>ns2.example.net</domain:hostObj></domain:ns></domain:add>')
        ->toContain('<domain:rem><domain:ns><domain:hostObj>ns0.old.net</domain:hostObj></domain:ns></domain:rem>');

    $same = nominetAdapter(['domain:info' => nominetInfo()]);
    $same->modifyNs(nominetDomain());
    expect(array_column($GLOBALS['nominet_calls'], 0))->toBe(['login', 'domain:info']);
});

test('domain details come from domain:info', function (): void {
    $details = nominetAdapter(['domain:info' => nominetInfo(statuses: '<domain:status s="clientTransferProhibited"/>')])->getDomainDetails(nominetDomain());

    expect(gmdate('Y-m-d', $details->getRegistrationTime()))->toBe('2008-01-01')
        ->and(gmdate('Y-m-d', $details->getExpirationTime()))->toBe('2027-01-02')
        ->and($details->getLocked())->toBeTrue()
        ->and($details->getPrivacyEnabled())->toBeNull()
        ->and([$details->getNs1(), $details->getNs2(), $details->getNs3()])->toBe(['ns1.example.net', 'ns2.example.net', null])
        ->and($details->getContactRegistrar()->getEmail())->toBe('ann@example.com');
});

test('a contact change updates the registrant account in place, without its name', function (): void {
    $adapter = nominetAdapter(['domain:info' => nominetInfo()]);
    $adapter->modifyContact(nominetDomain()->setFields(['registrant_type' => 'STRA', 'trading_name' => 'Ann\'s Shop']));

    expect(nominetCall('contact:update'))->toContain('<contact:id>S70T1</contact:id><contact:chg><contact:postalInfo type="loc"><contact:name>Ann Lee</contact:name><contact:addr>')
        ->not->toContain('<contact:org>')
        ->toContain('<contact-ext:update xmlns:contact-ext="http://www.nominet.org.uk/epp/xml/contact-nom-ext-1.0"><contact-ext:trad-name>Ann&apos;s Shop</contact-ext:trad-name><contact-ext:type>STRA</contact-ext:type></contact-ext:update>');
});

test('.uk names have no transfer code or privacy service; a cancel sends nothing', function (): void {
    $adapter = nominetAdapter();

    expect(fn () => $adapter->getEpp(nominetDomain()))->toThrow(Registrar_Exception::class, 'no transfer code')
        ->and(fn () => $adapter->enablePrivacyProtection(nominetDomain()))->toThrow(Registrar_Exception::class, 'no privacy service')
        ->and($adapter->deleteDomain(nominetDomain()))->toBeTrue()
        ->and($GLOBALS['nominet_calls'])->toBe([]);
});

test('the order form asks .uk registrations for the registrant type only', function (): void {
    $adapter = nominetAdapter();

    expect(array_keys($adapter::getDomainFields('.co.uk')))->toBe(['registrant_type', 'company_number', 'trading_name'])
        ->and($adapter::getDomainFields('.uk')['registrant_type']['required'])->toBeTrue()
        ->and($adapter::getDomainFields('.co.uk', 'transfer'))->toBe([])
        ->and($adapter::getDomainFields('.wales'))->toBe([]);
});

test('an individual or sole trader is registered without the company as org; .ltd.uk, .net.uk and .plc.uk registrations are refused', function (): void {
    foreach (['IND', 'STRA'] as $type) {
        $adapter = nominetAdapter();
        $adapter->registerDomain(nominetDomain()->setFields(['registrant_type' => $type]));
        expect(nominetCall('contact:create'))->toContain('<contact:name>Ann Lee</contact:name>')->not->toContain('<contact:org>');
    }

    $adapter = nominetAdapter();
    expect(fn () => $adapter->isDomainAvailable(nominetDomain('.ltd.uk')))->toThrow(Registrar_Exception::class, 'checked by hand')
        ->and(array_column($GLOBALS['nominet_calls'], 0))->not->toContain('domain:check');
});

test('the price list is the cost price in GBP for every Nominet extension and action, and keeps the asked TLDs', function (): void {
    $prices = nominetAdapter()->getTldPrices();

    expect($prices['currency'])->toBe('GBP')
        ->and(array_keys($prices['prices']))->toBe(['.uk', '.co.uk', '.org.uk', '.me.uk', '.sch.uk', '.wales', '.cymru'])
        ->and($prices['prices']['.co.uk'])->toBe(['register' => 3.65, 'renew' => 3.65, 'transfer' => 0.0])
        ->and($prices['prices']['.wales'])->toBe(['register' => 3.65, 'renew' => 3.65, 'transfer' => 3.65])
        ->and($prices['remaining'])->toBe([])
        ->and(array_keys(nominetAdapter()->getTldPrices(['.co.uk', '.com'])['prices']))->toBe(['.co.uk'])
        ->and(fn () => nominetAdapter([], ['username' => 'EXAMPLE-TAG', 'password' => 'epp-secret-password-1'])->getTldPrices())
        ->toThrow(Registrar_Exception::class, 'cost price')
        ->and($GLOBALS['nominet_calls'])->toBe([]);
});

test('the IPS tag and EPP password are required', function (): void {
    expect(fn () => new Registrar_Adapter_Nominet(['username' => 'EXAMPLE-TAG']))->toThrow(Registrar_Exception::class)
        ->and(Registrar_Adapter_Nominet::getSecretFields())->toBe(['password']);
});

test('a standard login advertises the transfer code and secDNS extensions', function (): void {
    $adapter = nominetAdapter(standardised: true);
    $adapter->lock(nominetDomain());

    expect(nominetCall('login'))->toContain('<svcExtension><extURI>urn:ietf:params:xml:ns:epp:secure-authinfo-transfer-1.0</extURI>'
        . '<extURI>urn:ietf:params:xml:ns:rgp-1.0</extURI><extURI>urn:ietf:params:xml:ns:secDNS-1.1</extURI></svcExtension>');
});

test('a standard registration uses an empty domain password and drops the registrant extension', function (): void {
    $adapter = nominetAdapter(standardised: true);
    $adapter->registerDomain(nominetDomain()->setFields(['company_number' => '12345678']));

    expect(nominetCall('domain:create'))->toContain('<domain:authInfo><domain:pw/></domain:authInfo>')
        ->and(nominetCall('contact:create'))->toContain('<contact:authInfo><contact:pw/></contact:authInfo>')
        ->and(nominetCall('contact:create'))->toContain('<contact:org>Lee Trading Ltd</contact:org>')->not->toContain('<extension>')
        ->and($adapter::getDomainFields('.co.uk'))->toBe([]);
});

test('the standard platform sets and returns a new 34-character transfer code with every character type', function (): void {
    $adapter = nominetAdapter(standardised: true);
    $code = $adapter->getEpp(nominetDomain());

    expect($code)->toMatch('/^[0-9a-f]{32}X-$/')
        ->and(nominetCall('domain:update'))->toContain('<domain:name>example.co.uk</domain:name><domain:chg><domain:authInfo><domain:pw>'
            . $code . '</domain:pw></domain:authInfo></domain:chg>')
        ->and($adapter::getUnsupportedActions())->not->toContain('getEpp');
});

test('a standard transfer requests the domain with its code and one year, and waits for Nominet', function (): void {
    $adapter = nominetAdapter(['domain:info' => nominetReply('', 2201, 'Authorization error')], standardised: true);
    $domain = nominetDomain()->setEpp('example-transfer-code');

    expect($adapter->transferDomain($domain))->toBeTrue()
        ->and(nominetCall('domain:transfer'))->toContain('<transfer op="request"><domain:transfer xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">'
            . '<domain:name>example.co.uk</domain:name><domain:period unit="y">1</domain:period><domain:authInfo><domain:pw>example-transfer-code</domain:pw></domain:authInfo></domain:transfer></transfer>')
        ->and($adapter->getTransferStatus($domain))->toBe(['status' => Registrar_TransferStatus::PENDING, 'message' => 'Waiting for Nominet to complete the transfer of example.co.uk.']);
});

test('a standard transfer without a code is refused before an EPP command', function (): void {
    $adapter = nominetAdapter(standardised: true);

    expect(fn () => $adapter->transferDomain(nominetDomain()))->toThrow(Registrar_Exception::class, 'A transfer code is required to transfer example.co.uk.')
        ->and($GLOBALS['nominet_calls'])->toBe([]);
});

test('a standard renewal in the auto-renew period back-dates a year, so the auto-renewed year is not bought again', function (): void {
    $grace = str_replace('</resData>', '</resData><extension><rgp:infData xmlns:rgp="urn:ietf:params:xml:ns:rgp-1.0"><rgp:rgpStatus s="autoRenewPeriod"/></rgp:infData></extension>', nominetInfo());
    $adapter = nominetAdapter(['domain:info' => [$grace, nominetInfo()]], standardised: true);
    $adapter->renewDomain(nominetDomain());
    $late = nominetCall('domain:renew');
    $GLOBALS['nominet_calls'] = [];
    $adapter->renewDomain(nominetDomain());

    expect($late)->toContain('<domain:curExpDate>2026-01-02</domain:curExpDate><domain:period unit="y">2</domain:period>')
        ->and(nominetCall('domain:renew'))->toContain('<domain:curExpDate>2027-01-02</domain:curExpDate>');
});

test('a standard .uk transfer costs one year at the cost price', function (): void {
    expect(nominetAdapter(standardised: true)->getTldPrices(['.co.uk'])['prices']['.co.uk'])->toBe(['register' => 3.65, 'renew' => 3.65, 'transfer' => 3.65]);
});

test('a client certificate file that cannot be read is refused before connecting', function (): void {
    $adapter = new Registrar_Adapter_Nominet(['username' => 'EXAMPLE-TAG', 'password' => 'epp-secret-password-1', 'certificate_file' => '/nonexistent/nominet.pem']);
    $adapter->setLog(new Tests\Helpers\TestLogger());

    expect(fn () => $adapter->isDomainAvailable(nominetDomain()))->toThrow(Registrar_Exception::class, 'The Nominet client certificate file cannot be read: /nonexistent/nominet.pem');
});

test('a standard registration creates the name servers the registry does not have yet', function (): void {
    $check = nominetReply('<host:chkData xmlns:host="urn:ietf:params:xml:ns:host-1.0"><host:cd><host:name avail="0">ns1.example.net</host:name></host:cd>'
        . '<host:cd><host:name avail="1">ns2.example.net</host:name></host:cd></host:chkData>');
    $adapter = nominetAdapter(['host:check' => $check], standardised: true);
    $adapter->registerDomain(nominetDomain());
    $names = array_column($GLOBALS['nominet_calls'], 0);

    expect(array_slice($names, array_search('host:check', $names, true), 3))->toBe(['host:check', 'host:create', 'domain:create'])
        ->and(nominetCall('host:create'))->toContain('<host:name>ns2.example.net</host:name>')->not->toContain('ns1.example.net');
});
