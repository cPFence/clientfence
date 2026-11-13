<?php

declare(strict_types=1);

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** OPS data (dt_assoc / dt_array) to PHP, to read what the adapter sent. */
function opensrsDecode(SimpleXMLElement $node): array|string
{
    $list = $node->dt_assoc->count() > 0 ? $node->dt_assoc : $node->dt_array;
    if ($list->count() === 0) {
        return trim((string) $node);
    }
    $out = [];
    foreach ($list->item as $item) {
        $out[(string) $item['key']] = opensrsDecode($item);
    }

    return $out;
}

/** An OpenSRS reply envelope (the API guide's shape) with the given attribute items. */
function opensrsReply(string $items = '', string $success = '1', string $code = '200', string $text = 'Command Successful'): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="no" ?><!DOCTYPE OPS_envelope SYSTEM "ops.dtd"><OPS_envelope><header><version>0.9</version></header>'
        . '<body><data_block><dt_assoc><item key="protocol">XCP</item><item key="object">DOMAIN</item><item key="response_text">' . $text . '</item>'
        . '<item key="action">REPLY</item><item key="attributes"><dt_assoc>' . $items . '</dt_assoc></item>'
        . '<item key="response_code">' . $code . '</item><item key="is_success">' . $success . '</item></dt_assoc></data_block></body></OPS_envelope>';
}

/**
 * An OpenSRS adapter answering each call from $replies, keyed "action" or "action:type" (get's type, get_price's reg_type):
 * attribute items, an opensrsReply() argument list, or a closure of the request attributes returning either.
 */
function opensrsAdapter(array $replies = []): Registrar_Adapter_Opensrs
{
    $client = new MockHttpClient(function (string $method, string $url, array $options) use ($replies): MockResponse {
        $request = opensrsDecode(simplexml_load_string($options['body'])->body->data_block);
        $attributes = $request['attributes'] ?? [];
        $key = strtolower($request['action']) . (isset($attributes['type']) ? ':' . $attributes['type'] : (isset($attributes['reg_type']) && $request['action'] === 'GET_PRICE' ? ':' . $attributes['reg_type'] : ''));
        $GLOBALS['opensrs_calls'][] = [$key, $attributes, $url, $options];
        $reply = $replies[$key] ?? '';
        $reply = $reply instanceof Closure ? $reply($attributes) : $reply;

        return new MockResponse(opensrsReply(...(array) $reply));
    });
    $adapter = new class(['username' => 'reseller', 'api_key' => 'secret-key'], $client) extends Registrar_Adapter_Opensrs {
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
    $GLOBALS['opensrs_calls'] = [];

    return $adapter;
}

function opensrsDomain(string $tld = '.com'): Registrar_Domain
{
    $contact = (new Registrar_Domain_Contact())->setFirstName('Ann')->setLastName('Lee')->setEmail('ann@example.com')
        ->setAddress1('1 Main St')->setCity('London')->setZip('N1 1AA')->setCountry('gb')->setTelCc('44')->setTel('020 7946 0000');

    return (new Registrar_Domain())->setSld('example')->setTld($tld)->setRegistrationPeriod(2)
        ->setNs1('ns1.example.net')->setNs2('ns2.example.net')->setContactRegistrar($contact);
}

test('a .us registration posts signed XML with the contact set, the name servers, no auto-renewal and the nexus answers', function (): void {
    opensrsAdapter(['sw_register' => '<item key="id">3735281</item>'])
        ->registerDomain(opensrsDomain('.us')->setFields(['nexus_category' => 'C31', 'nexus_country' => 'de', 'app_purpose' => 'P1']));

    [$key, $attributes, $url, $options] = $GLOBALS['opensrs_calls'][0];
    expect($key)->toBe('sw_register')
        ->and($url)->toStartWith('https://rr-n1-tor.opensrs.net:55443')
        ->and($options['normalized_headers']['x-username'][0])->toBe('X-Username: reseller')
        ->and($options['normalized_headers']['x-signature'][0])->toBe('X-Signature: ' . md5(md5($options['body'] . 'secret-key') . 'secret-key'))
        ->and($options['body'])->not->toContain('secret-key')
        ->and($attributes)->toMatchArray(['domain' => 'example.us', 'reg_type' => 'new', 'period' => '2', 'handle' => 'process', 'auto_renew' => '0',
            'custom_nameservers' => '1', 'custom_tech_contact' => '1', 'f_whois_privacy' => '0'])
        ->and($attributes['nameserver_list'])->toBe([['name' => 'ns1.example.net', 'sortorder' => '1'], ['name' => 'ns2.example.net', 'sortorder' => '2']])
        ->and(array_keys($attributes['contact_set']))->toBe(['owner', 'admin', 'billing', 'tech'])
        ->and($attributes['contact_set']['tech'])->toMatchArray(['first_name' => 'Ann', 'last_name' => 'Lee', 'address1' => '1 Main St', 'city' => 'London',
            'postal_code' => 'N1 1AA', 'country' => 'GB', 'phone' => '+44.2079460000', 'email' => 'ann@example.com'])
        ->and($attributes['contact_set']['owner'])->not->toHaveKey('org_name')
        ->and($attributes['tld_data'])->toBe(['nexus' => ['category' => 'C31', 'app_purpose' => 'P1', 'validator' => 'DE']])
        ->and($attributes['reg_username'])->toStartWith('usr')
        ->and(strlen($attributes['reg_password']))->toBe(16);
});

test('the .uk, .ca and .it answers go where the TLD data guide puts them', function (): void {
    $adapter = opensrsAdapter();
    $adapter->registerDomain(opensrsDomain('.co.uk')->setFields(['registrant_type' => 'LTD', 'registration_number' => '85214792', 'trading_name' => 'Acme']));
    $adapter->registerDomain(opensrsDomain('.ca')->setFields(['legal_type' => 'CCT']));
    $adapter->registerDomain(opensrsDomain('.it')->setFields(['entity_type' => '2', 'reg_code' => '12345678901']));
    $adapter->registerDomain(opensrsDomain('.it')->setFields(['entity_type' => '1', 'reg_code' => 'X123']));

    $sent = array_column($GLOBALS['opensrs_calls'], 1);
    expect($sent[0]['tld_data'])->toBe(['registrant_extra_info' => ['registrant_type' => 'LTD', 'registration_number' => '85214792', 'trading_name' => 'Acme']])
        ->and($sent[1]['registrant_extra_info'])->toBe(['legal_type' => 'CCT'])
        ->and($sent[1])->not->toHaveKey('tld_data')
        ->and($sent[2]['tld_data'])->toBe(['it_registrant_info' => ['entity_type' => '2', 'reg_code' => '12345678901', 'nationality_code' => 'IT']])
        ->and($sent[3]['tld_data']['it_registrant_info']['nationality_code'])->toBe('GB');
});

test('a .uk individual sends the name as org_name; a .it contact change carries the identification; .dk changes and .au orders are refused', function (): void {
    $adapter = opensrsAdapter();
    $adapter->registerDomain(opensrsDomain('.co.uk')->setFields(['registrant_type' => 'IND']));
    $adapter->modifyContact(opensrsDomain('.it')->setFields(['entity_type' => '2', 'reg_code' => '12345678901']));
    $sent = array_column($GLOBALS['opensrs_calls'], 1);
    expect($sent[0]['contact_set']['owner']['org_name'])->toBe('Ann Lee')
        ->and($sent[1]['contact_set']['owner'])->toMatchArray(['entity_type' => '2', 'reg_code' => '12345678901', 'nationality_code' => 'IT'])
        ->and($sent[1]['contact_set']['admin'])->not->toHaveKey('reg_code');

    $adapter = opensrsAdapter();
    expect(fn () => $adapter->modifyNs(opensrsDomain('.dk')))->toThrow(Registrar_Exception::class, 'OpenSRS control panel')
        ->and(fn () => $adapter->modifyContact(opensrsDomain('.dk')))->toThrow(Registrar_Exception::class, 'OpenSRS control panel')
        ->and(fn () => $adapter->isDomainAvailable(opensrsDomain('.com.au')))->toThrow(Registrar_Exception::class, 'registry details')
        ->and(fn () => $adapter->isDomaincanBeTransferred(opensrsDomain('.com.au')))->toThrow(Registrar_Exception::class, 'registry details')
        ->and($GLOBALS['opensrs_calls'])->toBe([])
        ->and(array_keys($adapter->getTldPrices(['.com.au', '.com'])['prices']))->not->toContain('.com.au');
});

test('a transfer sends the code, the client\'s name servers and the .eu owner confirmation; without them it keeps the domain\'s own', function (): void {
    $adapter = opensrsAdapter();
    $adapter->transferDomain(opensrsDomain('.eu')->setEpp('Fk8$%j(!'));
    $adapter->transferDomain((new Registrar_Domain())->setSld('example')->setTld('.uk')->setContactRegistrar(opensrsDomain()->getContactRegistrar()));

    [$eu, $uk] = array_column($GLOBALS['opensrs_calls'], 1);
    expect($eu)->toMatchArray(['reg_type' => 'transfer', 'auth_info' => 'Fk8$%j(!', 'custom_transfer_nameservers' => '1', 'owner_confirm_address' => 'ann@example.com', 'auto_renew' => '0'])
        ->and($eu['nameserver_list'])->toHaveCount(2)
        ->and($eu)->not->toHaveKey('period')
        ->and($uk)->toMatchArray(['reg_type' => 'transfer', 'custom_transfer_nameservers' => '0'])
        ->and($uk)->not->toHaveKey('auth_info')->not->toHaveKey('nameserver_list')->not->toHaveKey('owner_confirm_address')->not->toHaveKey('tld_data');
});

test('a renewal sends the registry\'s expiry year with no auto-renewal', function (): void {
    expect(opensrsAdapter(['get:all_info' => '<item key="expiredate">2027-01-03 06:48:18</item>', 'renew' => '<item key="order_id">682717</item>'])->renewDomain(opensrsDomain()))->toBeTrue()
        ->and(array_column($GLOBALS['opensrs_calls'], 0))->toBe(['get:all_info', 'renew'])
        ->and($GLOBALS['opensrs_calls'][1][1])->toBe(['domain' => 'example.com', 'handle' => 'process', 'auto_renew' => '0', 'currentexpirationyear' => '2027', 'period' => '2']);
});

test('a refused request is a registrar error with OpenSRS\'s text, and the key stays out of the log and the message', function (): void {
    $adapter = opensrsAdapter(['sw_register' => ['<item key="error">Invalid telephone number format</item>', '0', '420', 'Failed to create new registrant contact']]);
    try {
        $adapter->registerDomain(opensrsDomain());
        $this->fail('No exception');
    } catch (Registrar_Exception $e) {
        expect($e->getMessage())->toContain('Invalid telephone number format')->not->toContain('secret-key');
    }

    expect(json_encode($adapter->getLog()->calls))->not->toContain('secret-key')->not->toContain('ann@example.com')
        ->and(fn () => opensrsAdapter(['renew' => ['', '0', '541', 'Domain\'s current expiration year in registry doesn\'t match']])->renewDomain(opensrsDomain()->setExpirationTime(strtotime('2027-05-01'))))
        ->toThrow(Registrar_Exception::class, 'expiration year')
        ->and(fn () => opensrsAdapter(['sw_register' => '<item key="forced_pending">1</item><item key="error">Insufficient funds</item>'])->registerDomain(opensrsDomain()))
        ->toThrow(Registrar_Exception::class, 'Insufficient funds');
});

test('availability: available, taken and premium names, for registration and transfer', function (): void {
    expect(opensrsAdapter(['lookup' => '<item key="status">available</item>'])->isDomainAvailable(opensrsDomain()))->toBeTrue()
        ->and(opensrsAdapter(['lookup' => ['<item key="status">taken</item>', '1', '211', 'Domain taken']])->isDomainAvailable(opensrsDomain()))->toBeFalse()
        ->and(fn () => opensrsAdapter(['lookup' => '<item key="status">available</item><item key="reason">Premium Name</item>'])->isDomainAvailable(opensrsDomain()))
        ->toThrow(Registrar_Exception::class, 'Premium domains cannot be registered')
        ->and(opensrsAdapter(['lookup' => '<item key="status">taken</item>', 'check_transfer' => '<item key="transferrable">1</item><item key="type">reg2reg</item>'])->isDomaincanBeTransferred(opensrsDomain()))->toBeTrue()
        ->and(opensrsAdapter(['lookup' => '<item key="status">taken</item>', 'check_transfer' => '<item key="transferrable">0</item><item key="reason">Transfer in progress</item>'])->isDomaincanBeTransferred(opensrsDomain()))->toBeFalse()
        ->and(fn () => opensrsAdapter(['lookup' => '<item key="status">taken</item><item key="reason">Premium Name</item>'])->isDomaincanBeTransferred(opensrsDomain()))
        ->toThrow(Registrar_Exception::class, 'Premium domains cannot be transferred')
        // A registry that does not answer check_transfer: the taken lookup decides (as Blesta).
        ->and(opensrsAdapter(['lookup' => '<item key="status">taken</item>', 'check_transfer' => ['', '0', '430', 'Invalid command']])->isDomaincanBeTransferred(opensrsDomain()))->toBeTrue();
});

test('name servers, contact, lock, privacy and cancel', function (): void {
    $adapter = opensrsAdapter();
    $adapter->modifyNs(opensrsDomain()->setNs3('ns3.example.net'));
    $adapter->modifyContact(opensrsDomain());
    $adapter->lock(opensrsDomain());
    $adapter->disablePrivacyProtection(opensrsDomain());
    $calls = $GLOBALS['opensrs_calls'];

    expect(array_column($calls, 0))->toBe(['advanced_update_nameservers', 'modify', 'modify', 'modify'])
        ->and($calls[0][1])->toBe(['domain' => 'example.com', 'op_type' => 'assign', 'assign_ns' => ['ns1.example.net', 'ns2.example.net', 'ns3.example.net']])
        ->and($calls[1][1])->toMatchArray(['data' => 'contact_info', 'affect_domains' => '0'])
        ->and($calls[1][1]['contact_set']['owner']['email'])->toBe('ann@example.com')
        ->and($calls[2][1])->toMatchArray(['data' => 'status', 'lock_state' => '1'])
        ->and($calls[3][1])->toMatchArray(['data' => 'whois_privacy_state', 'state' => 'disable'])
        // Already set to expire: OpenSRS answers 220 "Nothing to do".
        ->and(opensrsAdapter(['modify' => ['', '0', '220', 'Nothing to do. Current auto-renew mode is the same']])->deleteDomain(opensrsDomain()))->toBeTrue()
        ->and($GLOBALS['opensrs_calls'][0][1])->toMatchArray(['data' => 'expire_action', 'auto_renew' => '0', 'let_expire' => '1'])
        // No longer at OpenSRS (moved away, expired): the cancel still goes through, with a warning.
        ->and(opensrsAdapter(['modify' => ['', '0', '480', 'Domain example.com not owned by user']])->deleteDomain(opensrsDomain()))->toBeTrue();
});

test('domain details come from all_info, status and whois_privacy_state; the transfer code is emailed', function (): void {
    $adapter = opensrsAdapter([
        'get:all_info' => '<item key="registry_createdate">2010-01-03 06:48:18</item><item key="expiredate">2027-01-03 06:48:18</item>'
            . '<item key="nameserver_list"><dt_array><item key="0"><dt_assoc><item key="name">NS1.EXAMPLE.NET</item><item key="sortorder">1</item></dt_assoc></item>'
            . '<item key="1"><dt_assoc><item key="name">ns2.example.net</item><item key="sortorder">2</item></dt_assoc></item></dt_array></item>',
        'get:status' => '<item key="lock_state">1</item><item key="can_modify">1</item>',
        'get:whois_privacy_state' => '<item key="state">enabled</item>',
    ]);
    $details = $adapter->getDomainDetails(opensrsDomain());

    expect(date('Y-m-d', $details->getRegistrationTime()))->toBe('2010-01-03')
        ->and(date('Y-m-d', $details->getExpirationTime()))->toBe('2027-01-03')
        ->and($details->getLocked())->toBeTrue()
        ->and($details->getPrivacyEnabled())->toBeTrue()
        ->and([$details->getNs1(), $details->getNs2()])->toBe(['ns1.example.net', 'ns2.example.net'])
        ->and($adapter->getEpp(opensrsDomain()))->toContain('emailed')
        ->and(end($GLOBALS['opensrs_calls'])[1])->toBe(['domain_name' => 'example.com']);
});

test('the price list asks get_price for one year per action in USD, in slices, and keeps only the asked TLDs', function (): void {
    $adapter = opensrsAdapter([
        'get_price:new' => fn (array $a) => str_ends_with($a['domain'], '.net') ? ['', '0', '701', 'TLD not serviced'] : '<item key="price">8.50</item>',
        'get_price:renewal' => fn (array $a) => str_ends_with($a['domain'], '.net') ? ['', '0', '701', 'TLD not serviced'] : '<item key="price">9.75</item>',
        'get_price:transfer' => ['', '0', '480', 'Transfers not supported'],
    ]);

    expect($adapter->getTldPrices(['.com', '.net']))->toBe(['currency' => 'USD', 'prices' => ['.com' => ['register' => 8.5, 'renew' => 9.75, 'transfer' => null]], 'remaining' => []])
        ->and($GLOBALS['opensrs_calls'][0][1])->toBe(['domain' => 'clientfencepricecheck.com', 'period' => '1', 'reg_type' => 'new']);

    $all = $adapter->getTldPrices();
    expect(array_keys($all['prices']))->toBe(['.abogado', '.ac', '.ac.mu', '.academy', '.accountant'])
        ->and($all['remaining'])->toContain('.com')->not->toContain('.ac')
        ->and(count($all['remaining']))->toBeGreaterThan(700);
});

test('the transfer status maps check_transfer\'s status', function (string $status, string $expected): void {
    $adapter = opensrsAdapter(['check_transfer' => '<item key="status">' . $status . '</item><item key="reason">Transfer in progress</item><item key="transferrable">0</item>']);

    expect($adapter->getTransferStatus(opensrsDomain()))->toBe(['status' => $expected, 'message' => $status . ' Transfer in progress'])
        ->and($GLOBALS['opensrs_calls'][0][1])->toBe(['domain' => 'example.com', 'check_status' => '1']);
})->with([
    'completed' => ['completed', Registrar_TransferStatus::COMPLETED],
    'cancelled' => ['cancelled', Registrar_TransferStatus::FAILED],
    'owner' => ['pending_owner', Registrar_TransferStatus::PENDING],
    'registry' => ['pending_registry', Registrar_TransferStatus::PENDING],
]);

test('test mode uses the OpenSRS test environment; a missing key is a configuration error', function (): void {
    $adapter = opensrsAdapter(['lookup' => '<item key="status">available</item>']);
    $adapter->enableTestMode();
    $adapter->isDomainAvailable(opensrsDomain());

    expect($GLOBALS['opensrs_calls'][0][2])->toStartWith('https://horizon.opensrs.net:55443')
        ->and(fn () => new Registrar_Adapter_Opensrs(['username' => 'reseller']))->toThrow(Registrar_Exception::class, 'API key');
});

test('the order form asks the registry questions of .us, .uk, .ca, .fr, .it, .law and .xxx only', function (): void {
    expect(array_keys(Registrar_Adapter_Opensrs::getDomainFields('.us')))->toBe(['nexus_category', 'nexus_country', 'app_purpose'])
        ->and(array_keys(Registrar_Adapter_Opensrs::getDomainFields('.co.uk', 'transfer')))->toBe(['registrant_type', 'registration_number', 'trading_name'])
        ->and(Registrar_Adapter_Opensrs::getDomainFields('.ca')['legal_type']['options'])->toHaveKey('CCO')
        ->and(Registrar_Adapter_Opensrs::getDomainFields('.fr')['registrant_type']['options'])->toHaveKeys(['individual', 'organization'])
        ->and(array_keys(Registrar_Adapter_Opensrs::getDomainFields('.it')))->toBe(['entity_type', 'reg_code'])
        ->and(Registrar_Adapter_Opensrs::getDomainFields('.abogado'))->toHaveKey('qli_accreditation_year')
        ->and(Registrar_Adapter_Opensrs::getDomainFields('.xxx'))->toHaveKey('icm_membership_id')
        ->and(Registrar_Adapter_Opensrs::getDomainFields('.com'))->toBe([]);
});
