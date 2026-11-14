<?php

declare(strict_types=1);

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

function createEmailAdapter(array $options, ?callable $respond = null): Registrar_Adapter_Email
{
    $httpClient = new MockHttpClient(fn (string $method, string $url): MockResponse => $respond !== null
        ? $respond($method, $url)
        : new MockResponse('{}'));

    return new class(['email' => 'registrar@example.com', ...$options], $httpClient) extends Registrar_Adapter_Email {
        public function __construct(array $options, private readonly MockHttpClient $httpClient)
        {
            parent::__construct($options);
        }

        public function getHttpClient(): Symfony\Contracts\HttpClient\HttpClientInterface
        {
            return $this->httpClient;
        }
    };
}

function createEmailDomain(): Registrar_Domain
{
    return (new Registrar_Domain())->setSld('example')->setTld('.com');
}

test('the Email adapter refuses availability checks when RDAP lookups are disabled', function (): void {
    $adapter = createEmailAdapter([]);

    $adapter->isDomainAvailable(createEmailDomain());
})->throws(Registrar_Exception::class);

function createEmailRdapResponder(callable $domainResponse): callable
{
    $bootstrap = json_encode(['version' => '1.0', 'services' => [[['com'], ['https://rdap.example.com/com/v1/']]]], JSON_THROW_ON_ERROR);

    return fn (string $method, string $url): MockResponse => str_contains($url, '/domain/')
        ? $domainResponse()
        : new MockResponse($bootstrap);
}

test('the Email adapter reports an unregistered domain as available via RDAP', function (): void {
    $adapter = createEmailAdapter(['use_rdap' => '1'], createEmailRdapResponder(fn (): MockResponse => new MockResponse('', ['http_code' => 404])));

    expect($adapter->isDomainAvailable(createEmailDomain()))->toBeTrue();
});

test('the Email adapter reports a registered domain as unavailable via RDAP', function (): void {
    $adapter = createEmailAdapter(['use_whois' => '1'], createEmailRdapResponder(fn (): MockResponse => new MockResponse('{"objectClassName":"domain"}', ['http_code' => 200])));

    expect($adapter->isDomainAvailable(createEmailDomain()))->toBeFalse();
});

test('the Email adapter throws when RDAP cannot determine availability', function (): void {
    $adapter = createEmailAdapter(['use_rdap' => '1'], createEmailRdapResponder(fn (): MockResponse => new MockResponse('', ['http_code' => 429])));

    $adapter->isDomainAvailable(createEmailDomain());
})->throws(Registrar_Exception::class);

test('the Email adapter allows transfers when RDAP lookups are disabled', function (): void {
    $adapter = createEmailAdapter([]);

    expect($adapter->isDomaincanBeTransferred(createEmailDomain()))->toBeTrue();
});

test('the Email adapter allows transferring a registered domain via RDAP', function (): void {
    $adapter = createEmailAdapter(['use_rdap' => '1'], createEmailRdapResponder(fn (): MockResponse => new MockResponse('{"objectClassName":"domain"}', ['http_code' => 200])));

    expect($adapter->isDomaincanBeTransferred(createEmailDomain()))->toBeTrue();
});

test('the Email adapter refuses transferring an unregistered domain via RDAP', function (): void {
    $adapter = createEmailAdapter(['use_rdap' => '1'], createEmailRdapResponder(fn (): MockResponse => new MockResponse('', ['http_code' => 404])));

    $adapter->isDomaincanBeTransferred(createEmailDomain());
})->throws(Registrar_Exception::class, 'not registered');

test('the Email adapter falls back to allowing a transfer when RDAP cannot determine availability', function (): void {
    $adapter = createEmailAdapter(['use_rdap' => '1'], createEmailRdapResponder(fn (): MockResponse => new MockResponse('', ['http_code' => 429])));

    expect($adapter->isDomaincanBeTransferred(createEmailDomain()))->toBeTrue();
});

test('the Email adapter configuration form offers an RDAP toggle', function (): void {
    $form = Registrar_Adapter_Email::getConfig();

    expect(array_keys($form['form']))->toBe(['use_rdap'])
        ->and($form['form']['use_rdap'][1]['label'])->toContain('RDAP');
});

test('the Email adapter sends no mail: a domain action outside the ticket glue fails instead of pretending success', function (string $method): void {
    $adapter = new Registrar_Adapter_Email([]);

    $adapter->{$method}(createEmailDomain());
})->throws(Registrar_Exception::class, 'support tickets')->with([
    'modifyNs', 'transferDomain', 'deleteDomain', 'registerDomain', 'renewDomain', 'modifyContact',
    'enablePrivacyProtection', 'disablePrivacyProtection', 'getEpp', 'lock', 'unlock',
]);

test('the Email adapter source has no mail() call', function (): void {
    expect((string) file_get_contents(dirname(__DIR__, 4) . '/src/library/Registrar/Adapter/Email.php'))->not->toMatch('/\\bmail\\s*\\(/');
});
