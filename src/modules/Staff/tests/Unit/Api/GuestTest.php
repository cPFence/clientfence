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
use function Tests\Helpers\moduleService;

test('get di', function (): void {
    $api = apiEndpoint(new ClientFence\Mod\Staff\Api\Guest());
    $di = container();
    $api->setDi($di);
    $getDi = $api->getDi();
    expect($getDi)->toEqual($di);
});

test('login without email', function (): void {
    $api = apiEndpoint(new ClientFence\Mod\Staff\Api\Guest());
    $guestApi = apiEndpoint(new ClientFence\Mod\Staff\Api\Guest());

    $dispatcher = new ClientFence\Api\Dispatcher();

    expect(fn () => $dispatcher->validateRequiredParams($guestApi, 'login', ['email' => null, 'password' => 'pass']))
        ->toThrow(ClientFence\InformationException::class);
});

test('login without password', function (): void {
    $api = apiEndpoint(new ClientFence\Mod\Staff\Api\Guest());
    $guestApi = apiEndpoint(new ClientFence\Mod\Staff\Api\Guest());

    $di = container();
    $di['validator'] = new ClientFence\Validate();

    $guestApi->setDi($di);
    expect(fn () => $guestApi->login(['email' => 'email@domain.com']))->toThrow(ClientFence\Exception::class);
});

test('successful login', function (): void {
    $api = apiEndpoint(new ClientFence\Mod\Staff\Api\Guest());
    $modMock = Mockery::mock('\\' . ClientFence\Module::class);
    $modMock
    ->shouldReceive('getConfig')
    ->atLeast()->once()
    ->andReturn([]);

    $serviceMock = Mockery::mock(ClientFence\Mod\Staff\Service::class);
    $serviceMock
    ->shouldReceive('login')
    ->atLeast()->once()
    ->andReturn([]);

    $sessionMock = Mockery::mock(ClientFence\Session::class);
    $sessionMock->shouldReceive('delete')->atLeast()->once();

    $di = container();

    $toolsMock = Mockery::mock(ClientFence\Tools::class);
    $toolsMock->shouldReceive('validateAndSanitizeEmail')->atLeast()->once();
    $di['tools'] = $toolsMock;
    $di['session'] = $sessionMock;
    $di['validator'] = new ClientFence\Validate();

    $guestApi = apiEndpoint(new ClientFence\Mod\Staff\Api\Guest());
    $guestApi->setMod($modMock);
    $guestApi->setService($serviceMock);
    $guestApi->setDi($di);
    $result = $guestApi->login(['email' => 'email@domain.com', 'password' => 'pass']);
    expect($result)->toBeArray();
});

test('login check ip exception', function (): void {
    $api = apiEndpoint(new ClientFence\Mod\Staff\Api\Guest());
    $modMock = Mockery::mock('\\' . ClientFence\Module::class);
    $configArr = [
        'allowed_ips' => '1.1.1.1' . PHP_EOL . '2.2.2.2',
        'check_ip' => true,
    ];
    $modMock
    ->shouldReceive('getConfig')
    ->atLeast()->once()
    ->andReturn($configArr);

    $di = container();

    $toolsMock = Mockery::mock(ClientFence\Tools::class);
    $toolsMock->shouldReceive('validateAndSanitizeEmail')->atLeast()->once();
    $di['tools'] = $toolsMock;
    $di['validator'] = new ClientFence\Validate();

    $guestApi = apiEndpoint(new ClientFence\Mod\Staff\Api\Guest());
    $guestApi->setMod($modMock);
    $guestApi->setDi($di);
    $ip = '192.168.0.1';
    $guestApi->setIp($ip);

    $data = [
        'email' => 'email@domain.com',
        'password' => 'pass',
    ];
    expect(fn () => $guestApi->login($data))
        ->toThrow(ClientFence\Exception::class, 'You are not allowed to login to admin area from this IP address.');
});

test('updatePassword invalidates existing sessions', function (): void {
    $guestApi = apiEndpoint(new ClientFence\Mod\Staff\Api\Guest());

    $modMock = Mockery::mock('\\' . ClientFence\Module::class);
    $modMock->shouldReceive('getConfig')->atLeast()->once()->andReturn([]);

    $modelPasswordReset = new Model_AdminPasswordReset();
    $modelPasswordReset->loadBean(new Tests\Helpers\DummyBean());
    $modelPasswordReset->created_at = date('Y-m-d H:i:s', time() - 300);

    $admin = new Model_Admin();
    $admin->loadBean(new Tests\Helpers\DummyBean());
    $admin->id = 1;
    $admin->status = Model_Admin::STATUS_ACTIVE;

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('findOne')->atLeast()->once()->andReturn($modelPasswordReset);
    $dbMock->shouldReceive('getExistingModelById')->atLeast()->once()->andReturn($admin);

    $eventMock = Mockery::mock('ClientFence\EventManager');
    $eventMock->shouldReceive('fire')->times(2);

    $passwordMock = Mockery::mock(ClientFence\PasswordManager::class);
    $passwordMock->shouldReceive('hashIt')->atLeast()->once();

    $emailServiceMock = Mockery::mock(ClientFence\Mod\Email\Service::class);
    $emailServiceMock->shouldReceive('sendTemplate')->atLeast()->once();

    $profileServiceMock = Mockery::mock(ClientFence\Mod\Profile\Service::class);
    $profileServiceMock->shouldReceive('invalidateSessions')->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['em']->getRepository(ClientFence\Mod\Staff\Entity\Admin::class)->shouldReceive('find')->andReturn(Tests\Helpers\createEntity(ClientFence\Mod\Staff\Entity\Admin::class, ['id' => 1]));
    $di['em']->getRepository(ClientFence\Mod\Staff\Entity\AdminPasswordReset::class)->shouldReceive('changePassword')->once();
    $di['events_manager'] = $eventMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['password'] = $passwordMock;
    $di['mod_service'] = $di->protect(moduleService(['email' => $emailServiceMock, 'profile' => $profileServiceMock]));

    $guestApi->setMod($modMock);
    $guestApi->setDi($di);

    $guestApi->update_password([
        'code' => 'hashedString',
        'password' => 'NewPassword1',
        'password_confirm' => 'NewPassword1',
    ]);
});
