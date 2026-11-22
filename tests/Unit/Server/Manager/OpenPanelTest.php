<?php

declare(strict_types=1);

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

function openPanelManager(MockHttpClient $http): Server_Manager_OpenPanel
{
    return new class($http) extends Server_Manager_OpenPanel {
        public function __construct(private readonly HttpClientInterface $http)
        {
            parent::__construct(['host' => 'panel.example.com', 'port' => '8443', 'secure' => true, 'username' => 'admin', 'password' => 'api-password']);
        }

        public function getHttpClient(): HttpClientInterface
        {
            return $this->http;
        }
    };
}

test('OpenPanel authenticates and sends the expected request for :dataset', function (Closure $operation, string $verb, string $path, ?array $body, string $answer): void {
    $http = new MockHttpClient([
        function (string $method, string $url, array $options): MockResponse {
            expect($method)->toBe('POST')
                ->and($url)->toBe('https://panel.example.com:8443/api/')
                ->and(json_decode($options['body'], true))->toBe(['username' => 'admin', 'password' => 'api-password']);

            return new MockResponse('{"access_token":"session-token"}');
        },
        function (string $method, string $url, array $options) use ($verb, $path, $body, $answer): MockResponse {
            expect($method)->toBe($verb)
                ->and($url)->toBe('https://panel.example.com:8443/api/' . $path)
                ->and($options['headers'])->toContain('Authorization: Bearer session-token')
                ->and(json_decode($options['body'] ?? '', true))->toEqual($body);

            return new MockResponse($answer);
        },
    ]);

    $account = (new Server_Account())
        ->setUsername('customer')
        ->setPassword('account-password')
        ->setClient((new Server_Client())->setEmail('alice@example.com'))
        ->setPackage((new Server_Package())->setName('Starter'));

    expect($operation(openPanelManager($http), $account))->toBeTrue()
        ->and($http->getRequestsCount())->toBe(2);
})->with([
    'connection' => [
        fn (Server_Manager_OpenPanel $manager): bool => $manager->testConnection(),
        'GET', '', null, '{"message":"API is working!"}',
    ],
    'provisioning' => [
        fn (Server_Manager_OpenPanel $manager, Server_Account $account): bool => $manager->createAccount($account),
        'POST', 'users', ['username' => 'customer', 'password' => 'account-password', 'plan_name' => 'Starter', 'email' => 'alice@example.com'], '{"success":true}',
    ],
    'provisioning on an older OpenPanel version' => [
        fn (Server_Manager_OpenPanel $manager, Server_Account $account): bool => $manager->createAccount($account),
        'POST', 'users', ['username' => 'customer', 'password' => 'account-password', 'plan_name' => 'Starter', 'email' => 'alice@example.com'], 'Successfully added user customer',
    ],
    'suspension' => [
        fn (Server_Manager_OpenPanel $manager, Server_Account $account): bool => $manager->suspendAccount($account),
        'PATCH', 'users/customer', ['action' => 'suspend'], '{"success":1}',
    ],
    'unsuspension' => [
        fn (Server_Manager_OpenPanel $manager, Server_Account $account): bool => $manager->unsuspendAccount($account),
        'PATCH', 'users/customer', ['action' => 'unsuspend'], '{"success":true}',
    ],
    'cancellation' => [
        fn (Server_Manager_OpenPanel $manager, Server_Account $account): bool => $manager->cancelAccount($account),
        'DELETE', 'users/customer', null, '{"success":true}',
    ],
    'package change' => [
        fn (Server_Manager_OpenPanel $manager, Server_Account $account): bool => $manager->changeAccountPackage($account, (new Server_Package())->setName('Plus')),
        'PUT', 'users/customer', ['plan_name' => 'Plus'], '{"success":true}',
    ],
    'password change' => [
        fn (Server_Manager_OpenPanel $manager, Server_Account $account): bool => $manager->changeAccountPassword($account, 'new-password'),
        'PATCH', 'users/customer', ['password' => 'new-password'], '{"success":true}',
    ],
]);

test('OpenPanel exposes the API error for a refused change', function (): void {
    $manager = openPanelManager(new MockHttpClient([
        new MockResponse('{"access_token":"session-token"}'),
        new MockResponse('{"success":false,"error":"Unknown plan"}', ['http_code' => 422]),
    ]));

    expect(fn () => $manager->changeAccountPackage((new Server_Account())->setUsername('customer'), (new Server_Package())->setName('Plus')))
        ->toThrow(Server_Exception::class, 'OpenPanel refused the request: Unknown plan');
});

test('OpenPanel names the Enterprise requirement when authentication fails', function (): void {
    $http = new MockHttpClient(new MockResponse('{"error":"API disabled"}', ['http_code' => 403]));

    expect(fn () => openPanelManager($http)->testConnection())->toThrow(Server_Exception::class, 'Enable API access on OpenPanel Enterprise')
        ->and($http->getRequestsCount())->toBe(1);
});

test('OpenPanel refuses :dataset', function (string $method, array $arguments, string $action): void {
    $http = new MockHttpClient([]);

    expect(fn () => openPanelManager($http)->$method(new Server_Account(), ...$arguments))
        ->toThrow(Server_Exception::class, 'OpenPanel does not support ' . $action)
        ->and($http->getRequestsCount())->toBe(0);
})->with([
    'synchronization' => ['synchronizeAccount', [], 'account synchronization'],
    'username changes' => ['changeAccountUsername', ['new-user'], 'username changes'],
    'domain changes' => ['changeAccountDomain', ['new.example.com'], 'changing the account domain'],
    'IP changes' => ['changeAccountIp', ['192.0.2.2'], 'changing the account IP'],
]);
