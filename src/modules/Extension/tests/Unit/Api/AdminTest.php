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

test('getDi returns the dependency injection container', function (): void {
    $api = apiEndpoint(new ClientFence\Mod\Extension\Api\Admin());
    $di = container();
    $api->setDi($di);
    $getDi = $api->getDi();
    expect($getDi)->toBe($di);
});

test('activate activates an extension', function (): void {
    $api = apiEndpoint(new ClientFence\Mod\Extension\Api\Admin());
    $serviceMock = Mockery::mock(ClientFence\Mod\Extension\Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('activateExistingExtension')
        ->atLeast()
        ->once()
        ->andReturn([]);

    $api->setService($serviceMock);

    $result = $api->activate([]);
    expect($result)->toBeArray();
});

test('configGet gets extension config', function (): void {
    $api = apiEndpoint(new ClientFence\Mod\Extension\Api\Admin());
    $serviceMock = Mockery::mock(ClientFence\Mod\Extension\Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getConfig')
        ->atLeast()
        ->once()
        ->with('mod_example')
        ->andReturn(['key' => 'value']);
    $serviceMock->shouldReceive('hasManagePermission')
        ->with('mod_example')
        ->atLeast()
        ->once()
        ->andReturn(true);

    $api->setService($serviceMock);

    $result = $api->config_get(['ext' => 'mod_example']);
    expect($result)->toBeArray();
    expect($result)->toBe(['key' => 'value']);
});

test('email config exposes transport secrets only to Super Administrators', function (bool $superAdministrator): void {
    $api = apiEndpoint(new ClientFence\Mod\Extension\Api\Admin());
    $api->getDi()['mod_service']('Staff')->shouldReceive('isSuperAdministrator')->andReturn($superAdministrator);
    $config = [
        'ext' => 'mod_email',
        'from_email' => 'billing@example.com',
        'smtp_password' => 'smtp-secret',
        'sendgrid_key' => 'sendgrid-secret',
        'custom_dsn' => 'smtp://user:secret@mail.example.com',
    ];
    $service = Mockery::mock(ClientFence\Mod\Extension\Service::class);
    $service->shouldReceive('hasManagePermission')->with('mod_email')->andReturnNull();
    $service->shouldReceive('getConfig')->with('mod_email')->andReturn($config);
    $api->setService($service);

    expect($api->config_get(['ext' => 'mod_email']))->toBe($superAdministrator ? $config : [
        'ext' => 'mod_email',
        'from_email' => 'billing@example.com',
    ]);
})->with(['limited staff' => [false], 'Super Administrator' => [true]]);

test('configSave saves extension config', function (): void {
    $api = apiEndpoint(new ClientFence\Mod\Extension\Api\Admin());
    $serviceMock = Mockery::mock(ClientFence\Mod\Extension\Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('hasManagePermission')
        ->with('mod_example')
        ->atLeast()
        ->once()
        ->andReturn(true);
    $serviceMock->shouldReceive('setConfig')
        ->atLeast()
        ->once()
        ->andReturn(true);

    $api->setService($serviceMock);

    $result = $api->config_save(['ext' => 'mod_example']);
    expect($result)->toBeTrue();
});

test('getList returns extensions list', function (): void {
    $api = apiEndpoint(new ClientFence\Mod\Extension\Api\Admin());
    $serviceMock = Mockery::mock(ClientFence\Mod\Extension\Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getExtensionsList')
        ->atLeast()
        ->once()
        ->andReturn([]);

    $api->setService($serviceMock);

    $result = $api->get_list([]);
    expect($result)->toBeArray();
});

test('getNavigation returns admin navigation', function (): void {
    $api = apiEndpoint(new ClientFence\Mod\Extension\Api\Admin());
    $serviceMock = Mockery::mock(ClientFence\Mod\Extension\Service::class)->makePartial();
    $serviceMock->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getAdminNavigation')
        ->atLeast()
        ->once()
        ->andReturn([]);

    $api->setService($serviceMock);

    $result = $api->get_navigation([]);
    expect($result)->toBeArray();
});
