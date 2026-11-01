<?php

declare(strict_types=1);

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

beforeEach(function (): void {
    $this->httpClient = new MockHttpClient();
    $this->adapter = new class($this->httpClient) extends Registrar_Adapter_Namecheap {
        public function __construct(private readonly HttpClientInterface $httpClient)
        {
            parent::__construct(['api-key' => 'test-secret-key', 'username' => 'test-user', 'ip' => '192.0.2.1']);
        }

        public function getHttpClient(): HttpClientInterface
        {
            return $this->httpClient;
        }
    };
    $this->logger = new Tests\Helpers\TestLogger();
    $this->adapter->setLog($this->logger);
});

test('Namecheap rejects error envelopes with case-sensitive status attributes', function (string $status): void {
    $this->httpClient->setResponseFactory(new MockResponse('<ApiResponse Status="' . $status . '"><Errors><Error>Registrar rejected the request</Error></Errors></ApiResponse>'));
    $domain = (new Registrar_Domain())->setSld('example')->setTld('.com');

    expect(fn () => $this->adapter->isDomainAvailable($domain))->toThrow(
        Registrar_Exception::class,
        'Failed to namecheap.domains.check with the Namecheap registrar, check the error logs for further details',
    );
})->with(['ERROR', 'FAILED']);

test('Namecheap HTTP failures keep credentials out of exceptions and logs', function (): void {
    $this->httpClient->setResponseFactory(new MockResponse('Service unavailable', ['http_code' => 503]));
    $domain = (new Registrar_Domain())->setSld('example')->setTld('.com');

    expect(fn () => $this->adapter->isDomainAvailable($domain))->toThrow(function (Registrar_Exception $exception): void {
        expect($exception->getMessage())->toBe('Namecheap command namecheap.domains.check failed with HTTP status 503.');
    });

    $messages = array_map(fn (array $call): string => (string) $call['params'][0], $this->logger->calls);
    expect($messages)->toContain('API REQUEST: namecheap.domains.check')
        ->toContain('Namecheap command namecheap.domains.check failed with HTTP status 503.')
        ->and(implode("\n", $messages))->not->toContain('test-secret-key')
        ->not->toContain('ApiKey=')
        ->not->toContain('https://');
});

test('Namecheap sends the requested registrar lock change', function (string $operation, string $locked, string $action): void {
    $requests = [];
    $responses = [
        '<ApiResponse Status="OK"><CommandResponse><DomainGetRegistrarLockResult RegistrarLockStatus="' . $locked . '"/></CommandResponse></ApiResponse>',
        '<ApiResponse Status="OK"><CommandResponse><DomainSetRegistrarLockResult IsSuccess="true"/></CommandResponse></ApiResponse>',
    ];
    $this->httpClient->setResponseFactory(function (string $method, string $url) use (&$requests, &$responses): MockResponse {
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $requests[] = $params;

        return new MockResponse(array_shift($responses));
    });
    $domain = (new Registrar_Domain())->setSld('example')->setTld('.com');

    expect($this->adapter->{$operation}($domain))->toBeTrue()
        ->and($requests)->toHaveCount(2)
        ->and($requests[1]['Command'])->toBe('namecheap.domains.setRegistrarLock')
        ->and($requests[1]['LockAction'])->toBe($action);
})->with([
    'unlock a locked domain' => ['unlock', 'true', 'UNLOCK'],
    'lock an unlocked domain' => ['lock', 'false', 'LOCK'],
]);
