<?php

declare(strict_types=1);

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

function cyberPanelManager(MockHttpClient $http): Server_Manager_CyberPanel
{
    return new class($http) extends Server_Manager_CyberPanel {
        public function __construct(private readonly HttpClientInterface $http)
        {
            parent::__construct(['host' => 'panel.example.com', 'username' => 'admin', 'password' => 'api-password']);
        }

        public function getHttpClient(): HttpClientInterface
        {
            return $this->http;
        }
    };
}

test('CyberPanel sends the expected requests for :dataset', function (Closure $operation, array $requests): void {
    $steps = new ArrayIterator($requests);
    $http = new MockHttpClient(function (string $method, string $url, array $options) use ($steps): MockResponse {
        [$path, $body, $answer] = $steps->current();
        $steps->next();
        expect($method)->toBe('POST')
            ->and($url)->toBe('https://panel.example.com:8090/api/' . $path)
            ->and(json_decode($options['body'], true))->toEqual(['adminUser' => 'admin', 'adminPass' => 'api-password'] + $body);

        return new MockResponse(json_encode($answer, JSON_THROW_ON_ERROR));
    });

    $account = (new Server_Account())
        ->setDomain('site.example.com')
        ->setUsername('customer')
        ->setPassword('account-password')
        ->setClient((new Server_Client())->setFullName('Alice')->setEmail('alice@example.com'))
        ->setPackage((new Server_Package())->setName('Starter')->setMaxDomains('3'));

    expect($operation(cyberPanelManager($http), $account))->toBeTrue()
        ->and($http->getRequestsCount())->toBe(count($requests));
})->with([
    'connection' => [
        fn (Server_Manager_CyberPanel $manager): bool => $manager->testConnection(),
        [['verifyConn', [], ['verifyConn' => 1]]],
    ],
    'creating a reseller and its website' => [
        fn (Server_Manager_CyberPanel $manager, Server_Account $account): bool => $manager->createAccount($account->setReseller(true)),
        [
            ['submitUserCreation', [
                'firstName' => 'Alice',
                'lastName' => 'Unknown',
                'email' => 'alice@example.com',
                'userName' => 'customer',
                'password' => 'account-password',
                'websitesLimit' => 3,
                'selectedACL' => 'reseller',
                'securityLevel' => 'HIGH',
            ], ['status' => 1]],
            ['createWebsite', [
                'domainName' => 'site.example.com',
                'ownerEmail' => 'alice@example.com',
                'packageName' => 'Starter',
                'websiteOwner' => 'customer',
                'ownerPassword' => 'account-password',
                'acl' => 'reseller',
            ], ['status' => 1, 'createWebSiteStatus' => 1]],
        ],
    ],
    'suspension' => [
        fn (Server_Manager_CyberPanel $manager, Server_Account $account): bool => $manager->suspendAccount($account),
        [['submitWebsiteStatus', ['websiteName' => 'site.example.com', 'state' => 'Suspend'], ['websiteStatus' => 1]]],
    ],
    'unsuspension' => [
        fn (Server_Manager_CyberPanel $manager, Server_Account $account): bool => $manager->unsuspendAccount($account),
        [['submitWebsiteStatus', ['websiteName' => 'site.example.com', 'state' => 'Activate'], ['websiteStatus' => 1]]],
    ],
    'cancellation' => [
        fn (Server_Manager_CyberPanel $manager, Server_Account $account): bool => $manager->cancelAccount($account),
        [
            ['deleteWebsite', ['domainName' => 'site.example.com'], ['websiteDeleteStatus' => 1]],
            ['submitUserDeletion', ['accountUsername' => 'customer'], ['status' => 1]],
        ],
    ],
    'package change' => [
        fn (Server_Manager_CyberPanel $manager, Server_Account $account): bool => $manager->changeAccountPackage($account, (new Server_Package())->setName('Plus')),
        [['changePackageAPI', ['websiteName' => 'site.example.com', 'packageName' => 'Plus'], ['changePackage' => 1]]],
    ],
    'password change' => [
        fn (Server_Manager_CyberPanel $manager, Server_Account $account): bool => $manager->changeAccountPassword($account, 'new-password'),
        [['changeUserPassAPI', ['websiteOwner' => 'customer', 'ownerPassword' => 'new-password'], ['changeStatus' => 1]]],
    ],
]);

test('CyberPanel stops cancellation when website deletion fails', function (): void {
    $http = new MockHttpClient(new MockResponse('{"websiteDeleteStatus":0,"error_message":"Website is busy"}'));

    expect(fn () => cyberPanelManager($http)->cancelAccount((new Server_Account())->setDomain('site.example.com')))
        ->toThrow(Server_Exception::class, 'CyberPanel refused the request: Website is busy')
        ->and($http->getRequestsCount())->toBe(1);
});

test('CyberPanel refuses :dataset', function (string $method, array $arguments, string $action): void {
    $http = new MockHttpClient([]);

    expect(fn () => cyberPanelManager($http)->$method(new Server_Account(), ...$arguments))
        ->toThrow(Server_Exception::class, 'CyberPanel does not support ' . $action)
        ->and($http->getRequestsCount())->toBe(0);
})->with([
    'synchronization' => ['synchronizeAccount', [], 'account synchronization'],
    'username changes' => ['changeAccountUsername', ['new-user'], 'username changes'],
    'domain changes' => ['changeAccountDomain', ['new.example.com'], 'changing the account domain'],
    'IP changes' => ['changeAccountIp', ['192.0.2.2'], 'changing the account IP'],
]);

test('CyberPanel sends a plan without a website limit as the number 0 (unlimited)', function (): void {
    $limits = [];
    $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$limits): MockResponse {
        $limits[] = json_decode($options['body'], true)['websitesLimit'] ?? 'none';

        return new MockResponse('{"status":1}');
    });
    $account = (new Server_Account())->setUsername('alice')->setPassword('secret')->setDomain('example.com')
        ->setClient((new Server_Client())->setFirstName('Alice')->setLastName('Smith')->setEmail('alice@example.com'))
        ->setPackage((new Server_Package())->setName('Starter'));

    expect(cyberPanelManager($http)->createAccount($account))->toBeTrue()
        ->and($limits[0])->toBe(0);
});
