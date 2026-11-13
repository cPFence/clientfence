<?php

declare(strict_types=1);

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** An OVHcloud adapter answering "METHOD /path" from $replies (JSON value, or [HTTP status, JSON value]); others answer null. */
function ovhAdapter(array $replies, array $options = ['application_key' => 'app-key', 'secret_key' => 'app-secret', 'consumer_key' => 'consumer-secret']): Registrar_Adapter_Ovh
{
    $replies += [
        'GET /auth/time' => 1700000000,
        'POST /order/cart' => ['cartId' => 'cart-1', 'items' => []],
        'POST /order/cart/cart-1/domain' => ['cartId' => 'cart-1', 'itemId' => 42, 'prices' => [], 'settings' => ['domain' => 'example.com', 'pricingMode' => 'create-default', 'quantity' => 1]],
        'GET /order/cart/cart-1/item/42/requiredConfiguration' => [['label' => 'OWNER_LEGAL_AGE', 'type' => 'bool'], ['label' => 'OWNER_CONTACT', 'type' => '/me/contact'], ['label' => 'ADMIN_ACCOUNT', 'type' => 'Nichandle'], ['label' => 'DNS', 'type' => 'String']],
        'POST /domain/contact' => ['id' => 77],
        'POST /order/cart/cart-1/checkout' => ['orderId' => 9001, 'url' => null],
        'GET /me/order/9001/status' => 'delivering',
    ];
    $client = new MockHttpClient(function (string $method, string $url, array $request) use (&$replies): MockResponse {
        $key = $method . ' ' . substr((string) parse_url($url, PHP_URL_PATH), strlen('/1.0'));
        $headers = [];
        foreach ($request['headers'] as $line) {
            [$name, $value] = explode(': ', $line, 2);
            $headers[strtolower($name)] = $value;
        }
        $GLOBALS['ovh_calls'][] = ['call' => $key, 'body' => json_decode((string) $request['body'], true), 'raw' => (string) $request['body'], 'url' => $url, 'headers' => $headers];
        $reply = $replies[$key] ?? null;
        [$status, $value] = is_array($reply) && array_keys($reply) === [0, 1] && is_int($reply[0]) && $reply[0] >= 400 ? $reply : [200, $reply];

        return new MockResponse((string) json_encode($value), ['http_code' => $status]);
    });
    $adapter = new class($options, $client) extends Registrar_Adapter_Ovh {
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
    $GLOBALS['ovh_calls'] = [];

    return $adapter;
}

function ovhDomain(string $tld = '.com'): Registrar_Domain
{
    $contact = (new Registrar_Domain_Contact())->setFirstName('Ann')->setLastName('Lee')->setEmail('ann@example.com')
        ->setAddress1('1 Main St')->setCity('London')->setZip('N1 1AA')->setCountry('gb')->setTelCc('44')->setTel('020 7946 0000');

    return (new Registrar_Domain())->setSld('example')->setTld($tld)->setRegistrationPeriod(2)
        ->setNs1('ns1.example.net')->setNs2('ns2.example.net')->setContactRegistrar($contact);
}

/** The recorded calls other than the clock. */
function ovhCalls(): array
{
    return array_values(array_filter($GLOBALS['ovh_calls'], fn (array $c) => $c['call'] !== 'GET /auth/time'));
}

function ovhCall(string $key, int $nth = 0): array
{
    return array_values(array_filter(ovhCalls(), fn (array $c) => $c['call'] === $key))[$nth];
}

test('a registration orders the domain through a cart: item, holder contact, configurations, checkout, payment', function (): void {
    $adapter = ovhAdapter([]);

    expect($adapter->registerDomain(ovhDomain()))->toBeTrue()
        ->and(array_column(ovhCalls(), 'call'))->toBe([
            'POST /order/cart', 'POST /order/cart/cart-1/domain', 'POST /order/cart/cart-1/assign', 'GET /order/cart/cart-1/item/42/requiredConfiguration',
            'POST /order/cart/cart-1/item/42/configuration', 'POST /domain/contact', 'POST /order/cart/cart-1/item/42/configuration',
            'POST /order/cart/cart-1/item/42/configuration', 'POST /order/cart/cart-1/checkout', 'POST /me/order/9001/payWithRegisteredPaymentMean', 'GET /me/order/9001/status',
        ])
        ->and(ovhCall('POST /order/cart')['body'])->toBe(['ovhSubsidiary' => 'FR'])
        ->and(ovhCall('POST /order/cart/cart-1/domain')['body'])->toBe(['domain' => 'example.com', 'duration' => 'P2Y'])
        ->and(array_column(ovhCalls(), 'body'))->toContain(['label' => 'OWNER_LEGAL_AGE', 'value' => 'true'], ['label' => 'OWNER_CONTACT', 'value' => '/me/contact/77'], ['label' => 'DNS', 'value' => 'ns1.example.net;ns2.example.net'])
        ->and(ovhCall('POST /domain/contact')['body'])->toBe(['firstName' => 'Ann', 'lastName' => 'Lee', 'email' => 'ann@example.com', 'phone' => '+44.2079460000', 'legalForm' => 'individual',
            'address' => ['line1' => '1 Main St', 'city' => 'London', 'zip' => 'N1 1AA', 'country' => 'GB'], 'language' => 'en_GB'])
        ->and(ovhCall('POST /order/cart/cart-1/checkout')['body'])->toBe(['autoPayWithPreferredPaymentMethod' => false, 'waiveRetractationPeriod' => true])
        ->and(ovhCall('POST /me/order/9001/payWithRegisteredPaymentMean')['body'])->toBe(['paymentMean' => 'ovhAccount']);
});

test('every call is signed with the application secret, the consumer key and the API clock', function (): void {
    $adapter = ovhAdapter([]);
    $adapter->lock(ovhDomain());

    $call = ovhCall('PUT /domain/example.com');
    $time = $call['headers']['x-ovh-timestamp'];
    expect($call['body'])->toBe(['transferLockStatus' => 'locked'])
        ->and($call['url'])->toBe('https://eu.api.ovh.com/1.0/domain/example.com')
        ->and($call['headers'])->toMatchArray(['x-ovh-application' => 'app-key', 'x-ovh-consumer' => 'consumer-secret',
            'x-ovh-signature' => '$1$' . sha1('app-secret+consumer-secret+PUT+https://eu.api.ovh.com/1.0/domain/example.com+' . $call['raw'] . '+' . $time)])
        ->and(abs((int) $time - 1700000000))->toBeLessThan(5)
        ->and($GLOBALS['ovh_calls'][0]['headers'])->not->toHaveKey('x-ovh-signature');
});

test('a transfer sends the transfer code as AUTH_INFO for one year; the Canada endpoint uses the CA subsidiary', function (): void {
    $adapter = ovhAdapter([
        'POST /order/cart/cart-1/domain' => ['cartId' => 'cart-1', 'itemId' => 42, 'settings' => ['pricingMode' => 'transfer-default']],
        'GET /order/cart/cart-1/item/42/requiredConfiguration' => [['label' => 'AUTH_INFO', 'type' => 'String'], ['label' => 'OWNER_CONTACT', 'type' => '/me/contact']],
    ], ['application_key' => 'app-key', 'secret_key' => 'app-secret', 'consumer_key' => 'consumer-secret', 'endpoint' => 'ovh-ca']);
    $adapter->transferDomain(ovhDomain()->setEpp('abc-123'));

    expect(ovhCall('POST /order/cart')['body'])->toBe(['ovhSubsidiary' => 'CA'])
        ->and(ovhCall('POST /order/cart')['url'])->toStartWith('https://ca.api.ovh.com/1.0/')
        ->and(ovhCall('POST /order/cart/cart-1/domain')['body'])->toBe(['domain' => 'example.com', 'duration' => 'P1Y'])
        ->and(ovhCall('POST /order/cart/cart-1/item/42/configuration')['body'])->toBe(['label' => 'AUTH_INFO', 'value' => 'abc-123'])
        ->and(ovhCall('POST /order/cart/cart-1/item/42/configuration', 1)['body'])->toBe(['label' => 'OWNER_CONTACT', 'value' => '/me/contact/77']);
});

test('premium names are refused before any order; availability reads the pricing mode', function (): void {
    $premium = ['POST /order/cart/cart-1/domain' => ['cartId' => 'cart-1', 'itemId' => 42, 'settings' => ['pricingMode' => 'create-premium']]];
    $adapter = ovhAdapter($premium);

    expect(fn () => $adapter->registerDomain(ovhDomain()))->toThrow(Registrar_Exception::class, 'Premium domains cannot be registered')
        ->and(array_column(ovhCalls(), 'call'))->not->toContain('POST /order/cart/cart-1/checkout')
        ->and(fn () => ovhAdapter($premium)->isDomainAvailable(ovhDomain()))->toThrow(Registrar_Exception::class, 'Premium')
        ->and(ovhAdapter([])->isDomainAvailable(ovhDomain()))->toBeTrue()
        ->and(ovhAdapter(['POST /order/cart/cart-1/domain' => ['cartId' => 'cart-1', 'itemId' => 42, 'settings' => ['pricingMode' => 'transfer-default']]])->isDomainAvailable(ovhDomain()))->toBeFalse()
        ->and(ovhAdapter(['POST /order/cart/cart-1/domain' => ['cartId' => 'cart-1', 'itemId' => 42, 'settings' => ['pricingMode' => 'transfer-default']]])->isDomaincanBeTransferred(ovhDomain()))->toBeTrue()
        ->and(fn () => ovhAdapter(['POST /order/cart/cart-1/domain' => ['cartId' => 'cart-1', 'itemId' => 42, 'settings' => ['pricingMode' => 'transfer-premium']]])->transferDomain(ovhDomain()))
        ->toThrow(Registrar_Exception::class, 'Premium domains cannot be transferred');
});

test('a renewal is a paid renewal order for the domain service; a restorable domain is not renewed', function (): void {
    $adapter = ovhAdapter(['GET /domain/example.com' => ['state' => 'ok'], 'GET /domain/example.com/serviceInfos' => ['serviceId' => 123], 'POST /service/123/renew' => ['orderId' => 9001]]);
    $adapter->renewDomain(ovhDomain());

    expect(ovhCall('POST /service/123/renew')['body'])->toBe(['dryRun' => false, 'duration' => 'P2Y', 'services' => [123]])
        ->and(array_slice(array_column(ovhCalls(), 'call'), -2))->toBe(['POST /me/order/9001/payWithRegisteredPaymentMean', 'GET /me/order/9001/status']);

    $adapter = ovhAdapter(['GET /domain/example.com' => ['state' => 'restorable']]);
    expect(fn () => $adapter->renewDomain(ovhDomain()))->toThrow(Registrar_Exception::class, 'restore period')
        ->and(array_column(ovhCalls(), 'call'))->toBe(['GET /domain/example.com']);
});

test('OVHcloud auto-renewal is turned off on sync and before a renewal; a domain OVHcloud already renewed is not renewed again', function (): void {
    $auto = ['automatic' => true, 'forced' => false, 'deleteAtExpiration' => false, 'period' => 1];
    $off = ['renew' => ['automatic' => false, 'forced' => false, 'deleteAtExpiration' => false]];
    $adapter = ovhAdapter(['GET /domain/example.com' => ['state' => 'ok'], 'GET /domain/example.com/serviceInfos' => ['serviceId' => 123, 'expiration' => '2027-01-02', 'renew' => $auto]]);
    $adapter->getDomainDetails(ovhDomain());
    expect(ovhCall('PUT /domain/example.com/serviceInfos')['body'])->toBe($off);

    $adapter = ovhAdapter(['GET /domain/example.com' => ['state' => 'ok'], 'POST /service/123/renew' => ['orderId' => 9001],
        'GET /domain/example.com/serviceInfos' => ['serviceId' => 123, 'expiration' => '2027-01-02', 'renew' => $auto]]);
    $adapter->renewDomain(ovhDomain()->setExpirationTime((int) strtotime('2027-01-02')));
    expect(ovhCall('PUT /domain/example.com/serviceInfos')['body'])->toBe($off)
        ->and(array_column(ovhCalls(), 'call'))->toContain('POST /service/123/renew');

    // OVHcloud's expiry is a year past ClientFence's: it renewed on its own, so no second paid order.
    $adapter = ovhAdapter(['GET /domain/example.com' => ['state' => 'ok'],
        'GET /domain/example.com/serviceInfos' => ['serviceId' => 123, 'expiration' => '2028-01-02', 'renew' => ['automatic' => false, 'deleteAtExpiration' => false]]]);
    expect($adapter->renewDomain(ovhDomain()->setExpirationTime((int) strtotime('2027-01-02'))))->toBeTrue()
        ->and(array_column(ovhCalls(), 'call'))->not->toContain('POST /service/123/renew')
        ->and(array_column(ovhCalls(), 'call'))->not->toContain('PUT /domain/example.com/serviceInfos');
});

test('an unpaid order is a registrar error with the order number', function (): void {
    $adapter = ovhAdapter(['GET /me/order/9001/status' => 'notPaid']);

    expect(fn () => $adapter->registerDomain(ovhDomain()))->toThrow(Registrar_Exception::class, 'OVHcloud order 9001 is notPaid');
});

test('name servers, contact change, cancel and transfer code', function (): void {
    $adapter = ovhAdapter([
        'GET /domain/example.com' => ['whoisOwner' => '555'],
        'GET /domain/contact/555' => ['id' => 555, 'language' => 'fr_FR', 'firstName' => 'Old', 'nationality' => 'FR'],
        'GET /domain/example.com/authInfo' => 'Xy7-code',
    ]);
    $adapter->modifyNs(ovhDomain()->setNs3('ns3.example.net'));
    $adapter->modifyContact(ovhDomain());
    $adapter->deleteDomain(ovhDomain());

    expect(array_slice(array_column(ovhCalls(), 'call'), 0, 2))->toBe(['PUT /domain/example.com', 'POST /domain/example.com/nameServers/update'])
        ->and(ovhCall('PUT /domain/example.com')['body'])->toBe(['nameServerType' => 'external'])
        ->and(ovhCall('POST /domain/example.com/nameServers/update')['body'])->toBe(['nameServers' => [['host' => 'ns1.example.net'], ['host' => 'ns2.example.net'], ['host' => 'ns3.example.net']]])
        ->and(ovhCall('PUT /domain/contact/555')['body'])->toMatchArray(['language' => 'fr_FR', 'nationality' => 'FR', 'firstName' => 'Ann', 'phone' => '+44.2079460000'])
        ->and(ovhCall('PUT /domain/contact/555')['body'])->not->toHaveKey('id')
        ->and(ovhCall('PUT /domain/example.com/serviceInfos')['body'])->toBe(['renew' => ['automatic' => false, 'forced' => false, 'deleteAtExpiration' => true]])
        ->and($adapter->getEpp(ovhDomain()))->toBe('Xy7-code')
        ->and(fn () => $adapter->enablePrivacyProtection(ovhDomain()))->toThrow(Registrar_Exception::class, 'does not support')
        // No longer at OVHcloud: the cancel still goes through, with a warning.
        ->and(ovhAdapter(['PUT /domain/example.com/serviceInfos' => [404, ['class' => 'Client::NotFound', 'message' => 'The requested object (example.com) does not exist']]])->deleteDomain(ovhDomain()))->toBeTrue();
});

test('domain details come from the domain and its service information', function (): void {
    $adapter = ovhAdapter([
        'GET /domain/example.com' => ['domain' => 'example.com', 'transferLockStatus' => 'locked', 'state' => 'ok',
            'nameServers' => [['id' => 1, 'nameServer' => 'DNS1.EXAMPLE.NET', 'nameServerType' => 'external'], ['id' => 2, 'nameServer' => 'dns2.example.net', 'nameServerType' => 'external']]],
        'GET /domain/example.com/serviceInfos' => ['creation' => '2024-01-02', 'expiration' => '2027-01-02', 'serviceId' => 123],
    ]);
    $details = $adapter->getDomainDetails(ovhDomain());

    expect(date('Y-m-d', $details->getRegistrationTime()))->toBe('2024-01-02')
        ->and(date('Y-m-d', $details->getExpirationTime()))->toBe('2027-01-02')
        ->and($details->getLocked())->toBeTrue()
        ->and($details->getPrivacyEnabled())->toBeNull()
        ->and([$details->getNs1(), $details->getNs2()])->toBe(['dns1.example.net', 'dns2.example.net']);
});

test('the price list reads the catalog: creation, renewal and transfer apart, excluding tax, filtered to the asked TLDs', function (): void {
    // Shaped as OVHcloud's GET /order/catalog/formatted/domain reply (FR subsidiary, 2026-11-13), values in 1/100 000 000.
    $extension = fn (string $tld, int $creation, int $renewal, int $transfer) => ['currency' => 'EUR', 'extension' => $tld, 'hasPromotion' => false, 'planCode' => ltrim($tld, '.'), 'priceUnit' => 'HT/an',
        'prices' => ['creation' => ['formatted' => '', 'value' => $creation, 'valueVat' => $creation * 1.2], 'renewal' => ['formatted' => '', 'value' => $renewal, 'valueVat' => $renewal * 1.2],
            'restore' => ['formatted' => '', 'value' => 7599000000, 'valueVat' => 9118800000], 'trade' => ['formatted' => '', 'value' => 0, 'valueVat' => 0], 'transfer' => ['formatted' => '', 'value' => $transfer, 'valueVat' => $transfer * 1.2]]];
    $adapter = ovhAdapter(['GET /order/catalog/formatted/domain' => ['count' => 3, 'extensions' => [
        $extension('.tech', 799000000, 5629000000, 5509000000), $extension('.com', 849000000, 1099000000, 849000000), $extension('.co.uk', 749000000, 798000000, 0),
        // IDN extensions carry a display label; planCode is the TLD.
        ['extension' => '.xn--e1a4c (.ею)'] + $extension('.xn--e1a4c', 699000000, 699000000, 699000000),
    ]]]);

    expect($adapter->getTldPrices())->toBe(['currency' => 'EUR', 'prices' => [
        '.tech' => ['register' => 7.99, 'renew' => 56.29, 'transfer' => 55.09],
        '.com' => ['register' => 8.49, 'renew' => 10.99, 'transfer' => 8.49],
        '.co.uk' => ['register' => 7.49, 'renew' => 7.98, 'transfer' => 0.0],
        '.xn--e1a4c' => ['register' => 6.99, 'renew' => 6.99, 'transfer' => 6.99],
    ], 'remaining' => []])
        ->and(ovhCall('GET /order/catalog/formatted/domain')['url'])->toContain('ovhSubsidiary=FR')->toContain('count=500')->toContain('offset=0')
        ->and(ovhCall('GET /order/catalog/formatted/domain')['headers'])->not->toHaveKey('x-ovh-signature')
        ->and(array_keys($adapter->getTldPrices(['.TECH', '.org', '.xn--e1a4c'])['prices']))->toBe(['.tech', '.xn--e1a4c']);
});

test('a refused request is a registrar error with OVHcloud\'s message, and no secret reaches the log', function (): void {
    $adapter = ovhAdapter(['PUT /domain/example.com' => [403, ['class' => 'Client::Forbidden', 'message' => 'This call has not been granted']]]);

    expect(fn () => $adapter->unlock(ovhDomain()))->toThrow(Registrar_Exception::class, 'This call has not been granted');
    expect(json_encode($adapter->getLog()->calls))->not->toContain('app-secret')->not->toContain('consumer-secret');
});

test('test mode refuses every call: OVHcloud has no test system', function (): void {
    $adapter = ovhAdapter([]);
    $adapter->enableTestMode();

    expect(fn () => $adapter->lock(ovhDomain()))->toThrow(Registrar_Exception::class, 'no test system')
        ->and($GLOBALS['ovh_calls'])->toBe([]);
});
