<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use function Tests\Helpers\container;
use function Tests\Helpers\createEntity;
use function Tests\Helpers\moduleService;

test('getDi returns dependency injection container', function (): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());
    $di = container();
    $adminClient->setDi($di);
    $getDi = $adminClient->getDi();
    expect($getDi)->toEqual($di);
});

test('getList returns array', function (): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());
    $identity = new Model_Admin();
    $identity->loadBean(new Tests\Helpers\DummyBean());
    $adminClient->setIdentity($identity);
    $queryBuilder = Mockery::mock(Doctrine\ORM\QueryBuilder::class);
    $simpleResultArr = [
        'list' => [
            ['id' => 1, 'group' => null],
        ],
    ];

    $repository = Mockery::mock(ClientFence\Mod\Client\Repository\ClientRepository::class);
    $repository->shouldReceive('getSearchQueryBuilder')
        ->once()
        ->with([])
        ->andReturn($queryBuilder);
    $repository->shouldReceive('getListContext')
        ->once()
        ->with([1])
        ->andReturn([1 => ['balance' => 10.0, 'group' => 'VIP']]);

    $serviceMock = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $serviceMock->shouldReceive('revokeEmailConfirmations')->byDefault();
    $serviceMock
    ->shouldReceive('getClientRepository')
    ->once()
    ->andReturn($repository);

    $pagerMock = Mockery::mock(ClientFence\Pagination::class)->makePartial();

    $pagerMock
    ->shouldReceive('paginateDoctrineQuery')
    ->once()
    ->with($queryBuilder, Mockery::type(ClientFence\PaginationOptions::class), Mockery::type(Model_Admin::class))
    ->andReturn($simpleResultArr);

    $di = container();
    $di['pager'] = $pagerMock;

    $adminClient->setService($serviceMock);
    $adminClient->setDi($di);
    $data = [];

    $result = $adminClient->get_list($data);
    expect($result['list'])->toBe([['id' => 1, 'group' => 'VIP', 'balance' => 10.0]]);
});

test('getPairs returns array', function (): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());
    $serviceMock = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $serviceMock->shouldReceive('revokeEmailConfirmations')->byDefault();
    $serviceMock->shouldReceive('getPairs')->atLeast()->once()->andReturn([]);

    $di = container();
    $di['mod_service'] = $di->protect(moduleService(['client' => $serviceMock]));

    $adminClient->setDi($di);

    $data = ['id' => 1];
    $result = $adminClient->get_pairs($data);
    expect($result)->toBeArray();
});

test('get includes the last login with a profile IP fallback', function (?array $history, ?string $ip, ?string $expectedIp, ?string $expectedAt): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());
    $model = createEntity(ClientFence\Mod\Client\Entity\Client::class, ['id' => 7]);
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE activity_client_history (id INTEGER PRIMARY KEY, client_id INTEGER, ip TEXT, created_at TEXT)');
    $db->insert('activity_client_history', ['id' => 4, 'client_id' => 8, 'ip' => '203.0.113.8', 'created_at' => '2026-10-28 13:00:00']);
    if ($history !== null) {
        $db->insert('activity_client_history', ['id' => 1, 'client_id' => 7, 'ip' => '203.0.113.1', 'created_at' => '2026-10-26 12:00:00']);
        $db->insert('activity_client_history', ['id' => 2, 'client_id' => 7] + $history);
        $db->insert('activity_client_history', ['id' => 3, 'client_id' => 7, 'ip' => '203.0.113.3', 'created_at' => '2026-10-25 12:00:00']);
    }

    $serviceMock = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $serviceMock->shouldReceive('revokeEmailConfirmations')->byDefault();
    $serviceMock->shouldReceive('get')->atLeast()->once()->andReturn($model);
    $serviceMock
    ->shouldReceive('toApiArray')
    ->atLeast()->once()
    ->andReturn(['id' => 7, 'ip' => $ip]);

    $di = container();
    $di['dbal'] = $db;
    $adminClient->setDi($di);
    $adminClient->setService($serviceMock);

    $result = $adminClient->get(['id' => 7]);
    expect($result['last_login_ip'])->toBe($expectedIp)
        ->and($result['last_login_at'])->toBe($expectedAt);
})->with([
    'history' => [['ip' => '2001:db8::7', 'created_at' => '2026-10-27 12:00:00'], '', '2001:db8::7', '2026-10-27 12:00:00'],
    'profile IP only' => [null, '203.0.113.7', '203.0.113.7', null],
    'unknown' => [null, '', null, null],
]);

test('login returns array', function (): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());

    $sessionArray = [
        'id' => 1,
        'email' => 'email@example.com',
        'name' => 'John Smith',
        'role' => 'client',
    ];
    $serviceMock = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $serviceMock->shouldReceive('revokeEmailConfirmations')->byDefault();
    $serviceMock->shouldReceive('toSessionArray')->atLeast()->once()->andReturn($sessionArray);

    $sessionMock = Mockery::mock(ClientFence\Session::class);
    $sessionMock->shouldReceive('set')->atLeast()->once();
    // ClientFence: staff sign in as the account owner, never as an authorized user.
    $sessionMock->shouldReceive('delete')->with('clientfence_user')->once();

    $di = container();
    $di['mod_service'] = $di->protect(moduleService(['client' => $serviceMock]));
    $di['session'] = $sessionMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $validatorStub = $this->createStub(ClientFence\Validate::class);
    $di['validator'] = $validatorStub;

    $adminClient->setDi($di);

    $data = ['id' => 1];
    $result = $adminClient->login($data);
    expect($result)->toBeArray();
});

test('create returns int', function (): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());
    $data = [
        'email' => 'email@example.com',
        'first_name' => 'John', 'password' => 'StrongPass123',
    ];

    $model = createEntity(ClientFence\Mod\Client\Entity\Client::class);

    $serviceMock = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $serviceMock->shouldReceive('revokeEmailConfirmations')->byDefault();
    $serviceMock->shouldReceive('emailAlreadyRegistered')->atLeast()->once()->andReturn(false);
    $serviceMock->shouldReceive('adminCreateClient')->atLeast()->once()->andReturn(1);

    $eventMock = Mockery::mock('ClientFence\EventManager');
    $eventMock->shouldReceive('fire')->atLeast()->once();

    $toolsMock = Mockery::mock(ClientFence\Tools::class);
    $toolsMock->shouldReceive('validateAndSanitizeEmail')->atLeast()->once();

    $di = container();
    $di['events_manager'] = $eventMock;
    $di['tools'] = $toolsMock;

    $adminClient->setDi($di);
    $adminClient->setService($serviceMock);

    $result = $adminClient->create($data);

    expect($result)->toBeInt();
});

test('create throws exception when email is already registered', function (): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());
    $data = [
        'email' => 'email@example.com',
        'first_name' => 'John', 'password' => 'StrongPass123',
    ];

    $serviceMock = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $serviceMock->shouldReceive('revokeEmailConfirmations')->byDefault();
    $serviceMock->shouldReceive('emailAlreadyRegistered')->atLeast()->once()->andReturn(true);

    $toolsMock = Mockery::mock(ClientFence\Tools::class);
    $toolsMock->shouldReceive('validateAndSanitizeEmail')->atLeast()->once();

    $di = container();
    $di['tools'] = $toolsMock;

    $adminClient->setDi($di);
    $adminClient->setService($serviceMock);

    $adminClient->create($data);
})->throws(ClientFence\Exception::class, 'This email address is already registered.');

test('delete returns true', function (): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());
    $data = ['id' => 1];

    $eventMock = Mockery::mock('ClientFence\EventManager');
    $eventMock->shouldReceive('fire')->atLeast()->once();

    $serviceMock = Mockery::mock(ClientFence\Mod\Client\Service::class)->makePartial();
    $serviceMock->shouldReceive('remove')->atLeast()->once();

    $di = container();
    $di['events_manager'] = $eventMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $validatorStub = $this->createStub(ClientFence\Validate::class);
    $di['validator'] = $validatorStub;

    $adminClient->setDi($di);
    $adminClient->setService($serviceMock);
    $result = $adminClient->delete($data);
    expect($result)->toBeTrue();
});

test('update returns true', function (): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());
    $data = [
        'id' => 1,
        'first_name' => 'John', 'password' => 'StrongPass123',
        'last_name' => 'Smith',
        'aid' => '0',
        'gender' => 'male',
        'birthday' => '1999-01-01',
        'company' => 'LTD Testing',
        'company_vat' => 'VAT0007',
        'address_1' => 'United States',
        'address_2' => 'Utah',
        'phone_cc' => '+1',
        'phone' => '555-345-345',
        'notes' => 'none',
        'country' => 'US',
        'postcode' => 'IL-11123',
        'city' => 'Chicago',
        'state' => 'IL',
        'currency' => 'USD',
        'tax_exempt' => 'N/A',
        'created_at' => '2012-05-10',
        'email' => 'test@example.com',
        'group_id' => 1,
        'status' => 'test status',
        'company_number' => '1234',
        'type' => '',
        'lang' => 'en',
        'custom_1' => '',
        'custom_2' => '',
        'custom_3' => '',
        'custom_4' => '',
        'custom_5' => '',
        'custom_6' => '',
        'custom_7' => '',
        'custom_8' => '',
        'custom_9' => '',
        'custom_10' => '',
    ];

    $serviceMock = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $serviceMock->shouldReceive('revokeEmailConfirmations')->byDefault();
    $serviceMock->shouldReceive('emailAlreadyRegistered')->atLeast()->once()->andReturn(false);
    $serviceMock->shouldReceive('canChangeCurrency')->atLeast()->once()->andReturn(true);

    $eventMock = Mockery::mock('ClientFence\EventManager');
    $eventMock->shouldReceive('fire')->atLeast()->once();

    $toolsMock = Mockery::mock(ClientFence\Tools::class);
    $toolsMock->shouldReceive('validateAndSanitizeEmail')->atLeast()->once();

    $di = container();
    $di['mod_service'] = $di->protect(moduleService(['client' => $serviceMock]));
    $di['events_manager'] = $eventMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['tools'] = $toolsMock;

    $adminClient->setDi($di);
    $result = $adminClient->update($data);
    expect($result)->toBeTrue();
});

test('update validates and assigns client_group_id through the group repository', function (): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());
    $client = createEntity(ClientFence\Mod\Client\Entity\Client::class, ['id' => 1, 'client_group_id' => 3]);
    $group = createEntity(ClientFence\Mod\Client\Entity\ClientGroup::class, ['id' => 7]);

    $clientRepository = Mockery::mock(ClientFence\Mod\Client\Repository\ClientRepository::class);
    $clientRepository->shouldReceive('find')->once()->with(1)->andReturn($client);
    $groupRepository = Mockery::mock(ClientFence\Mod\Client\Repository\ClientGroupRepository::class);
    $groupRepository->shouldReceive('find')->once()->with(7)->andReturn($group);

    $di = container();
    $em = $di['em'];
    $em->shouldReceive('getRepository')->with(ClientFence\Mod\Client\Entity\Client::class)->andReturn($clientRepository);
    $em->shouldReceive('getRepository')->with(ClientFence\Mod\Client\Entity\ClientGroup::class)->andReturn($groupRepository);
    $em->shouldReceive('persist')->once()->with($client);
    $em->shouldReceive('flush')->once();
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['mod_service'] = $di->protect(moduleService(['client' => Mockery::mock(ClientFence\Mod\Client\Service::class)]));

    $adminClient->setDi($di);

    expect($adminClient->update(['id' => 1, 'client_group_id' => '7']))->toBeTrue()
        ->and($client->getClientGroupId())->toBe(7);
});

// ClientFence (QA r4 F-03): a new address is unconfirmed even when staff change it and the form posts "confirmed" back.
test('update unconfirms a changed email, and keeps the confirmation of an unchanged one', function (string $email, bool $approved): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());
    $client = createEntity(ClientFence\Mod\Client\Entity\Client::class, ['id' => 1, 'email' => 'old@example.com', 'emailApproved' => true]);
    $clientRepository = Mockery::mock(ClientFence\Mod\Client\Repository\ClientRepository::class);
    $clientRepository->shouldReceive('find')->once()->with(1)->andReturn($client);
    $serviceMock = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $serviceMock->shouldReceive('revokeEmailConfirmations')->byDefault();
    $serviceMock->shouldReceive('emailAlreadyRegistered')->andReturn(false);
    $toolsMock = Mockery::mock(ClientFence\Tools::class);
    $toolsMock->shouldReceive('validateAndSanitizeEmail')->andReturnArg(0);

    $di = container();
    $di['em']->shouldReceive('getRepository')->with(ClientFence\Mod\Client\Entity\Client::class)->andReturn($clientRepository);
    $di['em']->shouldReceive('persist')->once()->with($client);
    $di['em']->shouldReceive('flush')->once();
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['tools'] = $toolsMock;
    $di['mod_service'] = $di->protect(moduleService(['client' => $serviceMock]));
    $adminClient->setDi($di);

    expect($adminClient->update(['id' => 1, 'email' => $email, 'email_approved' => '1']))->toBeTrue()
        ->and($client->getEmailApproved())->toBe($approved);
})->with([
    'changed' => ['new@example.com', false],
    'same, other case' => ['OLD@example.com', true],
]);

test('update clears client_group_id when the alias is empty', function (): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());
    $client = createEntity(ClientFence\Mod\Client\Entity\Client::class, ['id' => 1, 'client_group_id' => 3]);

    $clientRepository = Mockery::mock(ClientFence\Mod\Client\Repository\ClientRepository::class);
    $clientRepository->shouldReceive('find')->once()->with(1)->andReturn($client);

    $di = container();
    $em = $di['em'];
    $em->shouldReceive('getRepository')->with(ClientFence\Mod\Client\Entity\Client::class)->andReturn($clientRepository);
    $em->shouldReceive('persist')->once()->with($client);
    $em->shouldReceive('flush')->once();
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['mod_service'] = $di->protect(moduleService(['client' => Mockery::mock(ClientFence\Mod\Client\Service::class)]));

    $adminClient->setDi($di);

    expect($adminClient->update(['id' => 1, 'client_group_id' => '']))->toBeTrue()
        ->and($client->getClientGroupId())->toBeNull();
});

test('update rejects a non-integer client_group_id alias', function (): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());
    $client = createEntity(ClientFence\Mod\Client\Entity\Client::class, ['id' => 1]);

    $clientRepository = Mockery::mock(ClientFence\Mod\Client\Repository\ClientRepository::class);
    $clientRepository->shouldReceive('find')->once()->with(1)->andReturn($client);

    $di = container();
    $em = $di['em'];
    $em->shouldReceive('getRepository')->with(ClientFence\Mod\Client\Entity\Client::class)->andReturn($clientRepository);
    $di['mod_service'] = $di->protect(moduleService(['client' => Mockery::mock(ClientFence\Mod\Client\Service::class)]));

    $adminClient->setDi($di);

    expect(fn () => $adminClient->update(['id' => 1, 'client_group_id' => 'invalid']))
        ->toThrow(ClientFence\InformationException::class, 'Invalid client group ID');
});

test('update throws exception when email is already registered', function (): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());
    $data = [
        'id' => 1,
        'first_name' => 'John', 'password' => 'StrongPass123',
        'last_name' => 'Smith',
        'aid' => '0',
        'gender' => 'male',
        'birthday' => '1999-01-01',
        'company' => 'LTD Testing',
        'company_vat' => 'VAT0007',
        'address_1' => 'United States',
        'address_2' => 'Utah',
        'phone_cc' => '+1',
        'phone' => '555-345-345',
        'document_type' => 'doc',
        'document_nr' => '1',
        'notes' => 'none',
        'country' => 'Moon',
        'postcode' => 'IL-11123',
        'city' => 'Chicago',
        'state' => 'IL',
        'currency' => 'USD',
        'tax_exempt' => 'N/A',
        'created_at' => '2012-05-10',
        'email' => 'test@example.com',
        'group_id' => 1,
        'status' => 'test status',
        'company_number' => '1234',
        'type' => '',
        'lang' => 'en',
        'custom_1' => '',
        'custom_2' => '',
        'custom_3' => '',
        'custom_4' => '',
        'custom_5' => '',
        'custom_6' => '',
        'custom_7' => '',
        'custom_8' => '',
        'custom_9' => '',
        'custom_10' => '',
    ];

    $serviceMock = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $serviceMock->shouldReceive('revokeEmailConfirmations')->byDefault();
    $serviceMock->shouldReceive('emailAlreadyRegistered')->atLeast()->once()->andReturn(true);

    $eventMock = Mockery::mock('ClientFence\EventManager');
    $eventMock->shouldReceive('fire');

    $di = container();
    $di['mod_service'] = $di->protect(moduleService(['client' => $serviceMock]));
    $di['events_manager'] = $eventMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['validator'] = new ClientFence\Validate();

    $toolsMock = Mockery::mock(ClientFence\Tools::class);
    $toolsMock->shouldReceive('validateAndSanitizeEmail')->atLeast()->once();
    $di['tools'] = $toolsMock;

    $adminClient->setDi($di);

    $adminClient->update($data);
})->throws(ClientFence\Exception::class, 'This email address is already registered.');

test('update throws exception when id is not passed', function (): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());
    $data = [];

    $di = container();

    $di['validator'] = new ClientFence\Validate();
    $adminClient->setDi($di);

    // Validate required parameters before calling update
    $validator = $di['validator'];
    $validator->checkRequiredParamsForArray(['id' => 'Client ID was not passed'], $data);

    $adminClient->update($data);
})->throws(ClientFence\Exception::class, 'Client ID was not passed');

test('changePassword returns true', function (): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());
    $data = [
        'id' => 1,
        'password' => 'strongPass',
        'password_confirm' => 'strongPass',
    ];

    $eventMock = Mockery::mock('ClientFence\EventManager');
    $eventMock->shouldReceive('fire')->atLeast()->once();

    $passwordMock = Mockery::mock(ClientFence\PasswordManager::class);
    $passwordMock->shouldReceive('hashIt')->atLeast()->once()->with($data['password']);

    $profileService = Mockery::mock(ClientFence\Mod\Profile\Service::class);
    $profileService->shouldReceive('invalidateSessions')->atLeast()->once();

    $di = container();
    $di['events_manager'] = $eventMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['password'] = $passwordMock;
    $validatorStub = $this->createStub(ClientFence\Validate::class);
    $di['validator'] = $validatorStub;
    $di['mod_service'] = $di->protect(moduleService(['profile' => $profileService]));

    $adminClient->setDi($di);

    $result = $adminClient->change_password($data);
    expect($result)->toBeTrue();
});

test('changePassword throws exception when passwords do not match', function (): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());
    $data = [
        'id' => 1,
        'password' => 'strongPass',
        'password_confirm' => 'NotIdentical',
    ];

    $validatorStub = new ClientFence\Validate();

    $di = container();
    $di['validator'] = $validatorStub;
    $di['mod_service'] = $di->protect(moduleService());
    $adminClient->setDi($di);

    $adminClient->change_password($data);
})->throws(ClientFence\Exception::class, 'Passwords do not match');

test('balanceGetList returns array', function (): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());
    $simpleResultArr = [
        'list' => [
            [
                'id' => 1,
                'description' => 'Testing',
                'amount' => '1.00',
                'currency' => 'USD',
                'created_at' => date('Y:m:d H:i:s'),
            ],
        ],
    ];

    $data = [];

    $serviceMock = Mockery::mock(ClientFence\Mod\Client\ServiceBalance::class);
    $serviceMock
    ->shouldReceive('getSearchQuery')
    ->atLeast()->once()
    ->andReturn(['String', []]);

    $pagerMock = Mockery::mock(ClientFence\Pagination::class)->makePartial();

    $pagerMock
    ->shouldReceive('getPaginatedResultSet')
    ->atLeast()->once()
    ->andReturn($simpleResultArr);

    $model = createEntity(ClientFence\Mod\Client\Entity\ClientBalance::class);

    $di = container();
    $di['mod_service'] = $di->protect(moduleService(['client:balance' => $serviceMock, 'client' => $serviceMock]));
    $di['pager'] = $pagerMock;

    $adminClient->setDi($di);

    $result = $adminClient->balance_get_list($data);
    expect($result)->toBeArray();
});

test('balanceDelete returns true', function (): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());
    $data = [
        'id' => 1,
    ];

    $di = container();
    $di['logger'] = new Tests\Helpers\TestLogger();
    $validatorStub = $this->createStub(ClientFence\Validate::class);
    $di['validator'] = $validatorStub;

    $adminClient->setDi($di);

    $result = $adminClient->balance_delete($data);
    expect($result)->toBeTrue();
});

test('balanceAddFunds returns true', function (): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());
    $data = [
        'id' => 1,
        'amount' => '1.00',
        'description' => 'testDescription',
    ];

    $serviceMock = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $serviceMock->shouldReceive('revokeEmailConfirmations')->byDefault();
    $serviceMock->shouldReceive('addFunds')->atLeast()->once();

    $di = container();
    $di['mod_service'] = $di->protect(moduleService(['client' => $serviceMock]));

    $validatorStub = $this->createStub(ClientFence\Validate::class);
    $di['validator'] = $validatorStub;

    $adminClient->setDi($di);

    $result = $adminClient->balance_add_funds($data);
    expect($result)->toBeTrue();
});

test('batchExpirePasswordReminders returns true', function (): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());
    $expiredArr = [
        createEntity(ClientFence\Mod\Client\Entity\ClientPasswordReset::class),
    ];

    $serviceMock = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $serviceMock->shouldReceive('revokeEmailConfirmations')->byDefault();
    $serviceMock->shouldReceive('getExpiredPasswordReminders')->atLeast()->once()->andReturn($expiredArr);

    $di = container();
    $di['mod_service'] = $di->protect(moduleService(['client' => $serviceMock]));
    $di['logger'] = new Tests\Helpers\TestLogger();

    $adminClient->setDi($di);

    $result = $adminClient->batch_expire_password_reminders();
    expect($result)->toBeTrue();
});

test('loginHistoryGetList returns array', function (): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());
    $data = [];
    $pagerResultSet = [
        'list' => [],
    ];

    $serviceMock = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $serviceMock->shouldReceive('revokeEmailConfirmations')->byDefault();
    $serviceMock
    ->shouldReceive('getHistorySearchQuery')
    ->atLeast()->once()
    ->andReturn(['String', []]);

    $pagerMock = Mockery::mock(ClientFence\Pagination::class)->makePartial();

    $pagerMock
    ->shouldReceive('getPaginatedResultSet')
    ->atLeast()->once()
    ->andReturn($pagerResultSet);

    $di = container();
    $di['pager'] = $pagerMock;

    $adminClient->setDi($di);
    $adminClient->setService($serviceMock);

    $result = $adminClient->login_history_get_list($data);
    expect($result)->toBeArray();
});

test('getStatuses returns array', function (): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());
    $serviceMock = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $serviceMock->shouldReceive('revokeEmailConfirmations')->byDefault();
    $serviceMock->shouldReceive('counter')->atLeast()->once()->andReturn([]);

    $di = container();
    $di['mod_service'] = $di->protect(moduleService(['client' => $serviceMock]));

    $adminClient->setDi($di);

    $result = $adminClient->get_statuses([]);
    expect($result)->toBeArray();
});

test('groupGetPairs returns array', function (): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());
    $serviceMock = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $serviceMock->shouldReceive('revokeEmailConfirmations')->byDefault();
    $serviceMock->shouldReceive('getGroupPairs')->atLeast()->once()->andReturn([]);

    $di = container();
    $di['mod_service'] = $di->protect(moduleService(['client' => $serviceMock]));

    $adminClient->setDi($di);

    $result = $adminClient->group_get_pairs([]);
    expect($result)->toBeArray();
});

test('groupCreate returns int', function (): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());
    $data['title'] = 'test Group';

    $newGroupId = 1;
    $serviceMock = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $serviceMock->shouldReceive('revokeEmailConfirmations')->byDefault();
    $serviceMock->shouldReceive('createGroup')->atLeast()->once()->andReturn($newGroupId);

    $di = container();
    $adminClient->setService($serviceMock);
    $adminClient->setDi($di);
    $result = $adminClient->group_create($data);

    expect($result)->toBeInt();
    expect($result)->toEqual($newGroupId);
});

test('groupUpdate returns true', function (): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());
    $data['id'] = '2';
    $data['title'] = 'test Group updated';

    $di = container();

    $validatorStub = $this->createStub(ClientFence\Validate::class);
    $di['validator'] = $validatorStub;

    $adminClient->setDi($di);

    $result = $adminClient->group_update($data);

    expect($result)->toBeTrue();
});

test('groupDelete returns true', function (): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());
    $data['id'] = '2';

    $serviceMock = Mockery::mock(ClientFence\Mod\Client\Service::class)->makePartial();
    $serviceMock
    ->shouldReceive('deleteGroup')
    ->atLeast()->once()
    ->andReturn(true);

    $di = container();
    $di['logger'] = new Tests\Helpers\TestLogger();
    $validatorStub = $this->createStub(ClientFence\Validate::class);
    $di['validator'] = $validatorStub;

    $adminClient->setDi($di);
    $adminClient->setService($serviceMock);

    $result = $adminClient->group_delete($data);

    expect($result)->toBeTrue();
});

test('groupGet returns array', function (): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());
    $data['id'] = '2';

    $di = container();
    $validatorStub = $this->createStub(ClientFence\Validate::class);
    $di['validator'] = $validatorStub;

    $adminClient->setDi($di);

    $result = $adminClient->group_get($data);

    expect($result)->toBeArray();
});

test('batchDelete returns true', function (): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());
    $activityMock = Mockery::mock(ClientFence\Mod\Client\Api\Admin::class)->makePartial();
    $activityMock->shouldReceive('delete')->atLeast()->once()->andReturn(true);

    $validatorStub = $this->createStub(ClientFence\Validate::class);

    $di = container();
    $di['validator'] = $validatorStub;
    $activityMock->setDi($di);

    $result = $activityMock->batch_delete(['ids' => [1, 2, 3]]);
    expect($result)->toBeTrue();
});

test('export_csv requires both view and export permissions', function (): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());

    $serviceMock = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $serviceMock->shouldReceive('revokeEmailConfirmations')->byDefault();
    $serviceMock->shouldReceive('exportCSV')->never();

    $di = container();
    $staffServiceMock = $di['mod_service']('staff');
    $staffServiceMock->shouldReceive('checkPermissionsAndThrowException')->byDefault()->andReturn(true);
    $staffServiceMock->shouldReceive('checkPermissionsAndThrowException')
        ->once()
        ->with('client', 'view', null, Mockery::any())
        ->andThrow(new ClientFence\InformationException('You need the "client.view" permission to perform this action', [], 403));

    $adminClient->setDi($di);
    $adminClient->setService($serviceMock);

    expect(fn () => $adminClient->export_csv(['headers' => ['id']]))
        ->toThrow(ClientFence\InformationException::class);
});

test('export_csv delegates to service when permissions granted', function (): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());

    $response = new Symfony\Component\HttpFoundation\Response('id,email', 200, ['Content-Type' => 'text/csv']);
    $serviceMock = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $serviceMock->shouldReceive('revokeEmailConfirmations')->byDefault();
    $serviceMock->shouldReceive('exportCSV')
        ->once()
        ->with(['email'])
        ->andReturn($response);

    $di = container();
    $staffServiceMock = $di['mod_service']('staff');
    $staffServiceMock->shouldReceive('checkPermissionsAndThrowException')
        ->once()
        ->with('client', 'view', null, Mockery::any())
        ->andReturn(true)
        ->ordered();
    $staffServiceMock->shouldReceive('checkPermissionsAndThrowException')
        ->once()
        ->with('client', 'export', null, Mockery::any())
        ->andReturn(true)
        ->ordered();

    $adminClient->setDi($di);
    $adminClient->setService($serviceMock);

    $result = $adminClient->export_csv(['headers' => ['email']]);

    expect($result)->toBeInstanceOf(Symfony\Component\HttpFoundation\Response::class);
});

test('delete rejects malformed client IDs before accessing the repository', function (mixed $id): void {
    $adminClient = apiEndpoint(new ClientFence\Mod\Client\Api\Admin());
    $di = container();
    $entityManager = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $entityManager->shouldNotReceive('getRepository');
    $di['em'] = $entityManager;
    $adminClient->setDi($di);

    (new ClientFence\Api\Dispatcher())->validateRequiredParams($adminClient, 'delete', ['id' => $id]);

    expect(fn () => $adminClient->delete(['id' => $id]))
        ->toThrow(ClientFence\InformationException::class, 'Invalid client ID');
})->with([
    'array without identifier' => [['unexpected' => 1]],
    'identifier array' => [['id' => 1]],
    'boolean' => [true],
    'fraction' => [1.5],
    'fraction string' => ['1.5'],
    'zero' => [0],
    'negative' => [-1],
    'text' => ['invalid'],
    'overflow' => ['9223372036854775808'],
]);
