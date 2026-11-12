<?php

declare(strict_types=1);

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

function createDynadotAdapter(HttpClientInterface $httpClient): Registrar_Adapter_Dynadot
{
    $adapter = new class($httpClient) extends Registrar_Adapter_Dynadot {
        public function __construct(private readonly HttpClientInterface $httpClient)
        {
            parent::__construct(['api_key' => 'live-secret', 'currency' => 'eur']);
        }

        public function getHttpClient(): HttpClientInterface
        {
            return $this->httpClient;
        }
    };
    $adapter->setLog(new Tests\Helpers\TestLogger());

    return $adapter;
}

test('the price list reads the one-year price per action from tld_price, without logging the reply', function (): void {
    $httpClient = new MockHttpClient(function (string $method, string $url): MockResponse {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        expect($query)->toMatchArray(['command' => 'tld_price', 'currency' => 'EUR']);

        return new MockResponse((string) json_encode(['TldPriceResponse' => ['ResponseCode' => 0, 'Status' => 'success', 'PriceLevel' => 'Regular Pricing', 'Currency' => 'EUR', 'TldPrice' => [
            ['Tld' => 'com', 'Usage' => 'General', 'Price' => ['Unit' => '(Price/1 year)', 'Register' => '9.99', 'Renew' => '10.49', 'Transfer' => '9.49', 'Restore' => '60.00']],
            ['Tld' => 'ch', 'Usage' => 'Country', 'Price' => ['Unit' => '(Price/1 year)', 'Register' => '8.20', 'Renew' => '8.20', 'Transfer' => '--', 'Restore' => '--']],
        ]]]));
    });
    $adapter = createDynadotAdapter($httpClient);

    expect($adapter->getTldPrices())->toBe(['currency' => 'EUR', 'prices' => [
        '.com' => ['register' => 9.99, 'renew' => 10.49, 'transfer' => 9.49],
        '.ch' => ['register' => 8.2, 'renew' => 8.2, 'transfer' => null],
    ], 'remaining' => []])
        ->and(array_keys($adapter->getTldPrices(['.ch'])['prices']))->toBe(['.ch'])
        ->and(json_encode($adapter->getLog()->calls))->not->toContain('9.99');
});

test('the transfer status is the newest incoming transfer\'s: completed, cancelled failed, anything else pending', function (string $text, string $status): void {
    $httpClient = new MockHttpClient(function (string $method, string $url) use ($text): MockResponse {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        expect($query)->toMatchArray(['command' => 'get_transfer_status', 'domain' => 'example.com', 'transfer_type' => 'in']);

        return new MockResponse((string) json_encode(['GetTransferStatusResponse' => ['ResponseCode' => 0, 'Status' => 'success', 'TransferList' => [
            ['OrderId' => '100', 'TransferStatus' => 'completed'],
            ['OrderId' => '200', 'TransferStatus' => $text],
        ]]]));
    });

    expect(createDynadotAdapter($httpClient)->getTransferStatus((new Registrar_Domain())->setSld('example')->setTld('.com')))
        ->toBe(['status' => $status, 'message' => $text]);
})->with([
    'completed' => ['completed', Registrar_TransferStatus::COMPLETED],
    'cancelled' => ['cancelled', Registrar_TransferStatus::FAILED],
    'pending' => ['waiting', Registrar_TransferStatus::PENDING],
    'bad code' => ['auth code needed', Registrar_TransferStatus::PENDING],
    'unknown' => ['something new', Registrar_TransferStatus::PENDING],
]);
