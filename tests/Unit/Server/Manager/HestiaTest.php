<?php

declare(strict_types=1);

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

test('Hestia rejects missing hostnames during initialization', function (array $config): void {
    expect(fn (): Server_Manager_Hestia => new Server_Manager_Hestia($config))->toThrow(
        Server_Exception::class,
        'The HestiaCP server hostname is missing. Please configure it in the server settings.',
    );
})->with([
    'missing' => [[]],
    'null' => [['host' => null]],
    'empty' => [['host' => '']],
    'whitespace' => [['host' => " \t\n"]],
    'IP without hostname' => [['ip' => '192.0.2.1']],
]);

function createHestiaManager(MockResponse $response): Server_Manager_Hestia
{
    return new class(new MockHttpClient($response)) extends Server_Manager_Hestia {
        public function __construct(private readonly HttpClientInterface $httpClient)
        {
            parent::__construct(['host' => 'hestia.example.com', 'username' => 'admin', 'password' => 'secret', 'accesshash' => '']);
            $this->setLog(new ClientFence\Log());
        }

        public function getHttpClient(): HttpClientInterface
        {
            return $this->httpClient;
        }
    };
}

test('Hestia HTTP and connection errors give a clear message, not the raw HTTP client one', function (MockResponse $response, string $message): void {
    expect(fn (): bool => createHestiaManager($response)->testConnection())->toThrow(Server_Exception::class, $message);
})->with([
    'HTTP 500' => [new MockResponse('oops', ['http_code' => 500]), 'The HestiaCP server returned HTTP status 500.'],
    'no connection' => [new MockResponse('', ['error' => 'Could not resolve host']), 'Could not connect to the HestiaCP server.'],
]);

test('Hestia error codes go to the ClientFence log', function (): void {
    $manager = createHestiaManager(new MockResponse('3'));
    $log = new class extends ClientFence\Log {
        public array $messages = [];

        public function log($message, $priority, array|string|null $extras = null): void
        {
            $this->messages[] = $message;
        }
    };
    $manager->setLog($log);

    expect(fn (): bool => $manager->unsuspendAccount((new Server_Account())->setUsername('example')))->toThrow(Server_Exception::class)
        ->and($log->messages)->toContain('HestiaCP returned error code 3 for the v-unsuspend-user command');
});
