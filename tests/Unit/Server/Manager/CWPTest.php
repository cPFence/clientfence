<?php

declare(strict_types=1);

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

function createCwpManager(string $body, int $statusCode = 200): Server_Manager_CWP
{
    $client = new MockHttpClient(new MockResponse($body, ['http_code' => $statusCode]));

    return new class($client) extends Server_Manager_CWP {
        public function __construct(private readonly HttpClientInterface $httpClient)
        {
            parent::__construct(['ip' => '127.0.0.1', 'host' => 'cwp.example.com', 'accesshash' => 'test-key']);
            $this->setLog(new ClientFence\Log());
        }

        public function getHttpClient(): HttpClientInterface
        {
            return $this->httpClient;
        }
    };
}


test('CWP reports invalid JSON as a server error', function (string $body): void {
    $manager = createCwpManager($body);

    expect(fn () => $manager->testConnection())->toThrow(Server_Exception::class, 'The CWP server returned an invalid JSON response');
})->with(['empty' => '', 'HTML' => '<html>Not found</html>']);
