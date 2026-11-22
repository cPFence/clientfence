<?php

declare(strict_types=1);

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

function dashmoxManager(MockHttpClient $http): Server_Manager_Dashmox
{
    return new class($http) extends Server_Manager_Dashmox {
        public function __construct(private readonly HttpClientInterface $http)
        {
            parent::__construct(['host' => 'panel.example.com', 'secure' => true, 'accesshash' => 'dashmox_token']);
        }

        public function getHttpClient(): HttpClientInterface
        {
            return $this->http;
        }
    };
}

function dashmoxHttp(array $requests): MockHttpClient
{
    $steps = new ArrayIterator($requests);

    return new MockHttpClient(function (string $method, string $url, array $options) use ($steps): MockResponse {
        [$verb, $path, $body, $answer] = $steps->current();
        $info = $steps->current()[4] ?? [];
        $steps->next();
        expect($method)->toBe($verb)
            ->and($url)->toBe('https://panel.example.com:8443/api/v1' . $path)
            ->and($options['headers'])->toContain('Authorization: Bearer dashmox_token')
            ->and(json_decode($options['body'] ?? '', true))->toEqual($body);

        return new MockResponse(json_encode($answer, JSON_THROW_ON_ERROR), $info);
    });
}

test('Dashmox provisions a customer and site in order and records the customer ID', function (): void {
    $http = dashmoxHttp([
        ['GET', '/packages', null, [['id' => 'plan-1', 'name' => 'starter']]],
        ['POST', '/customers', ['name' => 'Alice Smith', 'email' => 'alice@example.com', 'package_id' => 'plan-1'], ['id' => 'customer-1']],
        ['POST', '/sites', ['name' => 'example.com', 'domain' => 'example.com', 'runtime' => 'php', 'ssl' => false, 'customer_id' => 'customer-1'], ['site' => ['id' => 'site-1']], ['http_code' => 202]],
    ]);
    $account = (new Server_Account())
        ->setDomain('example.com')
        ->setClient((new Server_Client())->setFirstName('Alice')->setLastName('Smith')->setEmail('alice@example.com')->setCountry('US'))
        ->setPackage((new Server_Package())->setName('Starter'));

    expect(dashmoxManager($http)->createAccount($account))->toBeTrue()
        ->and($account->getPanelOrgId())->toBe('customer-1')
        ->and($http->getRequestsCount())->toBe(3);
});

test('Dashmox sends the expected requests for :dataset', function (Closure $operation, array $requests): void {
    $http = dashmoxHttp($requests);
    $account = (new Server_Account())->setPanelOrgId('customer-1');

    expect($operation(dashmoxManager($http), $account))->toBeTrue()
        ->and($http->getRequestsCount())->toBe(count($requests));
})->with([
    'connection' => [
        fn (Server_Manager_Dashmox $manager): bool => $manager->testConnection(),
        [['GET', '/server/health', null, ['status' => 'ok']]],
    ],
    'suspension' => [
        fn (Server_Manager_Dashmox $manager, Server_Account $account): bool => $manager->suspendAccount($account),
        [['PATCH', '/customers/customer-1', ['suspended' => true], []]],
    ],
    'unsuspension' => [
        fn (Server_Manager_Dashmox $manager, Server_Account $account): bool => $manager->unsuspendAccount($account),
        [['PATCH', '/customers/customer-1', ['suspended' => false], []]],
    ],
    'cancellation' => [
        fn (Server_Manager_Dashmox $manager, Server_Account $account): bool => $manager->cancelAccount($account),
        [['PATCH', '/customers/customer-1', ['suspended' => true], []]],
    ],
    'restoring a cancelled account' => [
        fn (Server_Manager_Dashmox $manager, Server_Account $account): bool => $manager->createAccount($account),
        [['PATCH', '/customers/customer-1', ['suspended' => false], []]],
    ],
    'package change' => [
        fn (Server_Manager_Dashmox $manager, Server_Account $account): bool => $manager->changeAccountPackage($account, (new Server_Package())->setName('Plus')),
        [
            ['GET', '/packages', null, [['id' => 'plan-2', 'name' => 'Plus']]],
            ['PUT', '/customers/customer-1/package', ['package_id' => 'plan-2'], []],
        ],
    ],
]);

test('Dashmox finds and keeps the customer of an imported account by its domain across pages when cancelling', function (): void {
    $http = dashmoxHttp([
        ['GET', '/sites', null, [['domain' => 'other.example.com', 'customer_id' => 'customer-2']], ['response_headers' => ['X-Next-Cursor: next']]],
        ['GET', '/sites?cursor=next', null, [['domain' => 'site.example.com', 'customer_id' => 'customer-1']]],
        ['PATCH', '/customers/customer-1', ['suspended' => true], []],
    ]);
    $account = (new Server_Account())->setDomain('SITE.example.com');

    expect(dashmoxManager($http)->cancelAccount($account))->toBeTrue()
        ->and($account->getPanelOrgId())->toBe('customer-1')
        ->and($http->getRequestsCount())->toBe(3);
});

test('Dashmox limits one-time login to a site owned by the linked customer', function (): void {
    $http = dashmoxHttp([
        ['GET', '/sites', null, [
            ['id' => 'other-site', 'domain' => 'site.example.com', 'customer_id' => 'customer-2'],
            ['id' => 'site-1', 'domain' => 'site.example.com', 'customer_id' => 'customer-1'],
        ]],
        ['POST', '/sites/site-1/sign-on', ['requested_by' => 'ClientFence'], ['url' => 'https://site.example.com/dashmox/sign-on']],
    ]);
    $account = (new Server_Account())->setDomain('site.example.com')->setPanelOrgId('customer-1');

    expect(dashmoxManager($http)->getLoginUrl($account))->toBe('https://site.example.com/dashmox/sign-on')
        ->and($http->getRequestsCount())->toBe(2);
});

test('Dashmox gives a one-time login to an account without a stored customer by its domain', function (): void {
    $http = dashmoxHttp([
        ['GET', '/sites', null, [['id' => 'site-7', 'domain' => 'old.example.com', 'customer_id' => 'customer-7']]],
        ['POST', '/sites/site-7/sign-on', ['requested_by' => 'ClientFence'], ['url' => 'https://old.example.com/dashmox/sign-on']],
    ]);

    expect(dashmoxManager($http)->getLoginUrl((new Server_Account())->setDomain('old.example.com')))->toBe('https://old.example.com/dashmox/sign-on');
});

test('Dashmox maps a licence refusal to a server exception', function (): void {
    $http = new MockHttpClient(new MockResponse('{"error":"API access requires a Pro or Business licence"}', ['http_code' => 403]));

    expect(fn () => dashmoxManager($http)->testConnection())
        ->toThrow(Server_Exception::class, 'Dashmox requires a Pro or Business licence for API access.');
});

test('Dashmox refuses :dataset', function (string $method, array $arguments, string $action): void {
    $http = new MockHttpClient([]);

    expect(fn () => dashmoxManager($http)->$method(new Server_Account(), ...$arguments))
        ->toThrow(Server_Exception::class, 'Dashmox does not support ' . $action)
        ->and($http->getRequestsCount())->toBe(0);
})->with([
    'password changes' => ['changeAccountPassword', ['new-password'], 'password changes'],
    'username changes' => ['changeAccountUsername', ['new-user'], 'username changes'],
    'domain changes' => ['changeAccountDomain', ['new.example.com'], 'changing the account domain'],
    'IP changes' => ['changeAccountIp', ['192.0.2.2'], 'changing the account IP'],
    'reseller login' => ['getResellerLoginUrl', [], 'reseller accounts'],
]);
