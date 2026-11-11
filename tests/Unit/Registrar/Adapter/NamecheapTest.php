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

test('the Namecheap price list reads the account price of one year plus the ICANN fee, without logging the reply', function (): void {
    $price = fn (int $years, string $yours, string $fee = '0.18'): string => '<Price Duration="' . $years . '" DurationType="YEAR" Price="' . $yours . '" RegularPrice="15.98"'
        . ' YourPrice="' . $yours . '" YourAdditonalCost="' . $fee . '" PromotionPrice="0.0" Currency="USD"/>';
    $this->httpClient->setResponseFactory(function (string $method, string $url) use ($price): MockResponse {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
        expect($params)->toMatchArray(['Command' => 'namecheap.users.getPricing', 'ProductType' => 'DOMAIN']);

        return new MockResponse('<ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response"><CommandResponse Type="namecheap.users.getPricing">'
            . '<UserGetPricingResult><ProductType Name="domains">'
            . '<ProductCategory Name="register"><Product Name="com">' . $price(2, '22.40') . $price(1, '11.20') . '</Product><Product Name="ch">' . $price(1, '9.98', '0') . '</Product></ProductCategory>'
            . '<ProductCategory Name="renew"><Product Name="com">' . $price(1, '12.20') . '</Product><Product Name="ch">' . $price(1, '9.98', '0') . '</Product></ProductCategory>'
            . '<ProductCategory Name="transfer"><Product Name="com">' . $price(1, '10.20') . '</Product></ProductCategory>'
            . '<ProductCategory Name="reactivate"><Product Name="com">' . $price(1, '99.00') . '</Product></ProductCategory>'
            . '</ProductType></UserGetPricingResult></CommandResponse></ApiResponse>');
    });

    $result = $this->adapter->getTldPrices();
    expect($result['currency'])->toBe('USD')
        ->and($result['remaining'])->toBe([])
        ->and(array_keys($result['prices']))->toBe(['.com', '.ch'])
        ->and($result['prices']['.com']['register'])->toEqualWithDelta(11.38, 0.0001)
        ->and($result['prices']['.com']['renew'])->toEqualWithDelta(12.38, 0.0001)
        ->and($result['prices']['.com']['transfer'])->toEqualWithDelta(10.38, 0.0001)
        ->and($result['prices']['.ch'])->toBe(['register' => 9.98, 'renew' => 9.98, 'transfer' => null])
        ->and(array_keys($this->adapter->getTldPrices(['.ch'])['prices']))->toBe(['.ch'])
        ->and(json_encode($this->logger->calls))->not->toContain('API RESULT');
});
