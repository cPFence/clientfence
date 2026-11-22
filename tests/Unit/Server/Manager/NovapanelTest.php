<?php

declare(strict_types=1);

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

function novapanelManager(MockHttpClient $http): Server_Manager_Novapanel
{
    return new class($http) extends Server_Manager_Novapanel {
        public function __construct(private readonly HttpClientInterface $http)
        {
            parent::__construct(['host' => 'panel.example.com', 'secure' => true, 'accesshash' => 'np_live_token']);
        }

        public function getHttpClient(): HttpClientInterface
        {
            return $this->http;
        }
    };
}

test('NovaPanel sends the expected requests for :dataset', function (Closure $operation, array $requests): void {
    $steps = new ArrayIterator($requests);
    $http = new MockHttpClient(function (string $method, string $url, array $options) use ($steps): MockResponse {
        [$verb, $path, $body, $answer] = $steps->current();
        $steps->next();
        expect($method)->toBe($verb)
            ->and($url)->toBe('https://panel.example.com:2087/api/v1' . $path)
            ->and($options['headers'])->toContain('X-API-Key: np_live_token')
            ->and(json_decode($options['body'] ?? '', true))->toEqual($body);

        return new MockResponse(json_encode(['data' => $answer], JSON_THROW_ON_ERROR));
    });
    $account = (new Server_Account())
        ->setDomain('example.com')
        ->setUsername('customer')
        ->setPassword('account-password')
        ->setClient((new Server_Client())->setEmail('alice@example.com'))
        ->setPackage((new Server_Package())->setName('Starter'));

    expect($operation(novapanelManager($http), $account))->toBeTrue()
        ->and($http->getRequestsCount())->toBe(count($requests));
})->with([
    'connection' => [
        fn (Server_Manager_Novapanel $manager): bool => $manager->testConnection(),
        [['GET', '/packages', null, []]],
    ],
    'provisioning' => [
        fn (Server_Manager_Novapanel $manager, Server_Account $account): bool => $manager->createAccount($account),
        [
            ['GET', '/packages', null, [['id' => 3, 'name' => 'starter']]],
            ['POST', '/accounts', ['domain' => 'example.com', 'username' => 'customer', 'password' => 'account-password', 'email' => 'alice@example.com', 'package_id' => '3'], ['id' => 7]],
        ],
    ],
    'suspension' => [
        fn (Server_Manager_Novapanel $manager, Server_Account $account): bool => $manager->suspendAccount($account),
        [['GET', '/users/lookup?username=customer', null, ['id' => 7]], ['POST', '/users/7/suspend', null, []]],
    ],
    'unsuspension' => [
        fn (Server_Manager_Novapanel $manager, Server_Account $account): bool => $manager->unsuspendAccount($account),
        [['GET', '/users/lookup?username=customer', null, ['id' => 7]], ['POST', '/users/7/unsuspend', null, []]],
    ],
    'cancellation' => [
        fn (Server_Manager_Novapanel $manager, Server_Account $account): bool => $manager->cancelAccount($account),
        [['GET', '/users/lookup?username=customer', null, ['id' => 7]], ['DELETE', '/users/7', null, null]],
    ],
    'package change' => [
        fn (Server_Manager_Novapanel $manager, Server_Account $account): bool => $manager->changeAccountPackage($account, (new Server_Package())->setName('Plus')),
        [
            ['GET', '/users/lookup?username=customer', null, ['id' => 7]],
            ['GET', '/packages', null, [['id' => 4, 'name' => 'Plus']]],
            ['PUT', '/users/7', ['package_id' => '4'], []],
        ],
    ],
    'password change' => [
        fn (Server_Manager_Novapanel $manager, Server_Account $account): bool => $manager->changeAccountPassword($account, 'new-password'),
        [['GET', '/users/lookup?username=customer', null, ['id' => 7]], ['POST', '/users/7/password', ['password' => 'new-password'], []]],
    ],
]);

test('NovaPanel sync returns the username from the panel', function (): void {
    $http = new MockHttpClient(function (string $method, string $url): MockResponse {
        expect($method)->toBe('GET')->and($url)->toBe('https://panel.example.com:2087/api/v1/users/lookup?username=Customer');

        return new MockResponse('{"data":{"id":7,"username":"customer","suspended":true}}');
    });
    $account = (new Server_Account())->setUsername('Customer');
    $updated = novapanelManager($http)->synchronizeAccount($account);

    expect($updated->getUsername())->toBe('customer')
        ->and($account->getUsername())->toBe('Customer');
});

test('NovaPanel maps cancellation lookup failures without treating them as a deleted account', function (): void {
    $http = new MockHttpClient(new MockResponse('{"error":{"message":"Service unavailable"}}', ['http_code' => 503]));

    expect(fn () => novapanelManager($http)->cancelAccount((new Server_Account())->setUsername('customer')))
        ->toThrow(Server_Exception::class, 'NovaPanel refused the request: Service unavailable')
        ->and($http->getRequestsCount())->toBe(1);
});

test('NovaPanel names the supported release when password change is unavailable', function (): void {
    $http = new MockHttpClient([
        new MockResponse('{"data":{"id":7}}'),
        new MockResponse('', ['http_code' => 404]),
    ]);

    expect(fn () => novapanelManager($http)->changeAccountPassword((new Server_Account())->setUsername('customer'), 'new-password'))
        ->toThrow(Server_Exception::class, 'Changing passwords from billing requires NovaPanel 1.8.3 or newer.');
});

test('NovaPanel refuses :dataset', function (string $method, array $arguments, string $action): void {
    $http = new MockHttpClient([]);

    expect(fn () => novapanelManager($http)->$method(new Server_Account(), ...$arguments))
        ->toThrow(Server_Exception::class, 'NovaPanel does not support ' . $action)
        ->and($http->getRequestsCount())->toBe(0);
})->with([
    'username changes' => ['changeAccountUsername', ['new-user'], 'username changes'],
    'domain changes' => ['changeAccountDomain', ['new.example.com'], 'changing the account domain'],
    'IP changes' => ['changeAccountIp', ['192.0.2.2'], 'changing the account IP'],
]);
