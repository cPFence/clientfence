<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use ClientFence\Mod\Servicedomain\Api\Guest;
use ClientFence\Mod\Servicedomain\Service;

use function Tests\Helpers\container;
use function Tests\Helpers\moduleService;

test('gets tlds', function (): void {
    $guestApi = apiEndpoint(new Guest());
    $api = apiEndpoint(new Guest());
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('tldToApiArray')
        ->atLeast()->once()
        ->andReturn([]);

    $guestApi->setService($serviceMock);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('find')
        ->atLeast()->once()
        ->andReturn([new Model_Tld()]);

    $di = container();
    $di['db'] = $dbMock;

    $guestApi->setDi($di);

    $result = $guestApi->tlds([]);
    expect($result)->toBeArray();
    expect($result[0])->toBeArray();
});

test('gets pricing', function (): void {
    $guestApi = apiEndpoint(new Guest());
    $api = apiEndpoint(new Guest());
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('tldFindOneByTld')
        ->atLeast()->once()
        ->andReturn(new Model_Tld());
    $serviceMock->shouldReceive('tldToApiArray')
        ->atLeast()->once()
        ->andReturn([]);

    $di = container();
    $guestApi->setDi($di);
    $guestApi->setService($serviceMock);

    $data = [
        'tld' => '.com',
    ];

    $result = $guestApi->pricing($data);
    expect($result)->toBeArray();
});

test('throws exception when getting pricing for tld not found', function (): void {
    $guestApi = apiEndpoint(new Guest());
    $api = apiEndpoint(new Guest());
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('tldFindOneByTld')
        ->atLeast()->once()
        ->andReturn(null);
    $serviceMock->shouldReceive('tldToApiArray')
        ->never();

    $validatorMock = Mockery::mock(ClientFence\Validate::class);

    $di = container();
    $di['validator'] = $validatorMock;
    $guestApi->setDi($di);
    $guestApi->setService($serviceMock);

    $data = [
        'tld' => '.com',
    ];

    expect(fn () => $guestApi->pricing($data))
        ->toThrow(ClientFence\InformationException::class);
});

test('checks domain availability', function (): void {
    $guestApi = apiEndpoint(new Guest());
    $api = apiEndpoint(new Guest());
    $tld = new Model_Tld();
    $tld->loadBean(new Tests\Helpers\DummyBean());
    $tld->active = true;
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('tldFindOneByTld')
        ->atLeast()->once()
        ->andReturn($tld);
    $serviceMock->shouldReceive('isDomainAvailable')
        ->atLeast()->once()
        ->andReturn(true);

    $guestApi->setService($serviceMock);

    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('isSldValid')
        ->atLeast()->once()
        ->andReturn(true);

    $di = container();
    $di['validator'] = $validatorMock;
    $guestApi->setDi($di);

    $data = [
        'tld' => '.com',
        'sld' => 'example',
    ];

    $result = $guestApi->check($data);
    expect($result)->toBeTrue();
});

test('invalid sld error carries the raw domain value', function (): void {
    $guestApi = apiEndpoint(new Guest());
    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('isSldValid')
        ->atLeast()->once()
        ->andReturn(false);

    $di = container();
    $di['validator'] = $validatorMock;
    $guestApi->setDi($di);

    expect(fn (): bool => $guestApi->check(['tld' => '.com', 'sld' => 'ex&ample']))
        ->toThrow(ClientFence\InformationException::class, 'Domain ex&ample is invalid');
});

test('throws exception when checking sld not valid', function (): void {
    $guestApi = apiEndpoint(new Guest());
    $api = apiEndpoint(new Guest());
    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('isSldValid')
        ->atLeast()->once()
        ->andReturn(false);

    $di = container();
    $di['validator'] = $validatorMock;
    $guestApi->setDi($di);

    $data = [
        'tld' => '.com',
        'sld' => 'example',
    ];

    expect(fn (): bool => $guestApi->check($data))
        ->toThrow(ClientFence\Exception::class);
});

test('throws exception when checking tld not found', function (): void {
    $guestApi = apiEndpoint(new Guest());
    $api = apiEndpoint(new Guest());
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('tldFindOneByTld')
        ->atLeast()->once()
        ->andReturn(null);
    $serviceMock->shouldReceive('isDomainAvailable')
        ->never();

    $guestApi->setService($serviceMock);

    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('isSldValid')
        ->atLeast()->once()
        ->andReturn(true);

    $di = container();
    $di['validator'] = $validatorMock;
    $guestApi->setDi($di);

    $data = [
        'tld' => '.com',
        'sld' => 'example',
    ];

    expect(fn (): bool => $guestApi->check($data))
        ->toThrow(ClientFence\Exception::class);
});

test('throws exception when checking domain not available', function (): void {
    $guestApi = apiEndpoint(new Guest());
    $api = apiEndpoint(new Guest());
    $tld = new Model_Tld();
    $tld->loadBean(new Tests\Helpers\DummyBean());
    $tld->active = true;
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('tldFindOneByTld')
        ->atLeast()->once()
        ->andReturn($tld);
    $serviceMock->shouldReceive('isDomainAvailable')
        ->atLeast()->once()
        ->andReturn(false);

    $guestApi->setService($serviceMock);

    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('isSldValid')
        ->atLeast()->once()
        ->andReturn(true);

    $di = container();
    $di['validator'] = $validatorMock;
    $guestApi->setDi($di);

    $data = [
        'tld' => '.com',
        'sld' => 'example',
    ];

    expect(fn (): bool => $guestApi->check($data))
        ->toThrow(ClientFence\Exception::class);
});

test('throws exception when checking an inactive tld', function (): void {
    $guestApi = apiEndpoint(new Guest());
    $tld = new Model_Tld();
    $tld->loadBean(new Tests\Helpers\DummyBean());
    $tld->active = false;

    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('tldFindOneByTld')
        ->once()
        ->andReturn($tld);
    $serviceMock->shouldReceive('isDomainAvailable')
        ->never();

    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('isSldValid')
        ->once()
        ->andReturn(true);

    $di = container();
    $di['validator'] = $validatorMock;
    $guestApi->setDi($di);
    $guestApi->setService($serviceMock);

    expect(fn (): bool => $guestApi->check(['tld' => '.com', 'sld' => 'example']))
        ->toThrow(ClientFence\InformationException::class, 'TLD is not active');
});

test('checks if domain can be transferred', function (): void {
    $guestApi = apiEndpoint(new Guest());
    $api = apiEndpoint(new Guest());
    $tld = new Model_Tld();
    $tld->loadBean(new Tests\Helpers\DummyBean());
    $tld->active = true;
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('tldFindOneByTld')
        ->atLeast()->once()
        ->andReturn($tld);
    $serviceMock->shouldReceive('canBeTransferred')
        ->atLeast()->once()
        ->andReturn(true);

    $validatorMock = Mockery::mock(ClientFence\Validate::class);

    $di = container();
    $di['validator'] = $validatorMock;
    $guestApi->setDi($di);

    $guestApi->setService($serviceMock);

    $data = [
        'tld' => '.com',
        'sld' => 'example',
    ];

    $result = $guestApi->can_be_transferred($data);
    expect($result)->toBeTrue();
});

test('throws exception when checking transfer for tld not found', function (): void {
    $guestApi = apiEndpoint(new Guest());
    $api = apiEndpoint(new Guest());
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('tldFindOneByTld')
        ->atLeast()->once()
        ->andReturn(null);
    $serviceMock->shouldReceive('canBeTransferred')
        ->never();

    $validatorMock = Mockery::mock(ClientFence\Validate::class);

    $di = container();
    $di['validator'] = $validatorMock;
    $guestApi->setDi($di);
    $guestApi->setService($serviceMock);

    $data = [
        'tld' => '.com',
        'sld' => 'example',
    ];

    expect(fn (): bool => $guestApi->can_be_transferred($data))
        ->toThrow(ClientFence\Exception::class);
});

test('throws exception when checking domain cannot be transferred', function (): void {
    $guestApi = apiEndpoint(new Guest());
    $api = apiEndpoint(new Guest());
    $tld = new Model_Tld();
    $tld->loadBean(new Tests\Helpers\DummyBean());
    $tld->active = true;
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('tldFindOneByTld')
        ->atLeast()->once()
        ->andReturn($tld);
    $serviceMock->shouldReceive('canBeTransferred')
        ->atLeast()->once()
        ->andReturn(false);

    $validatorMock = Mockery::mock(ClientFence\Validate::class);

    $di = container();
    $di['validator'] = $validatorMock;
    $guestApi->setDi($di);
    $guestApi->setService($serviceMock);

    $data = [
        'tld' => '.com',
        'sld' => 'example',
    ];

    expect(fn (): bool => $guestApi->can_be_transferred($data))
        ->toThrow(ClientFence\Exception::class);
});

test('guests answer the domain search CAPTCHA once an hour, signed-in clients never', function (bool $signedIn, int $passedUntil, bool $asked): void {
    $guestApi = apiEndpoint(new Guest());
    $tld = new Model_Tld();
    $tld->loadBean(new Tests\Helpers\DummyBean());
    $tld->active = true;
    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('tldFindOneByTld')->andReturn($tld);
    $serviceMock->shouldReceive('isDomainAvailable')->andReturn(true);
    $guestApi->setService($serviceMock);

    $extension = Mockery::mock();
    $extension->shouldReceive('isExtensionActive')->with('mod', 'antispam')->andReturn(true);
    $antispam = Mockery::mock(ClientFence\Mod\Antispam\Service::class);
    $antispam->shouldReceive('checkCaptcha')->times($asked ? 1 : 0)->with(Mockery::on(fn (array $data): bool => $data['cf-turnstile-response'] === 'token'));
    $session = Mockery::mock(ClientFence\Session::class);
    $session->shouldReceive('get')->with('domain_lookup_captcha_until')->andReturn($passedUntil);
    $session->shouldReceive('set')->times($asked ? 1 : 0)->with('domain_lookup_captcha_until', Mockery::on(fn (int $until): bool => abs($until - (time() + 3600)) < 5));
    $auth = Mockery::mock();
    $auth->shouldReceive('isClientLoggedIn')->andReturn($signedIn);
    $validator = Mockery::mock(ClientFence\Validate::class);
    $validator->shouldReceive('isSldValid')->andReturn(true);

    $di = container();
    $di['mod_service'] = $di->protect(moduleService(['extension' => $extension, 'antispam' => $antispam]));
    $di['mod_config'] = $di->protect(fn (string $name): array => $name === 'Antispam' ? ['captcha_enabled' => '1'] : []);
    $di['session'] = $session;
    $di['auth'] = $auth;
    $di['validator'] = $validator;
    $guestApi->setDi($di);

    expect($guestApi->check(['tld' => '.com', 'sld' => 'example', 'cf-turnstile-response' => 'token']))->toBeTrue();
})->with([
    'first search' => [false, 0, true],
    'passed an hour ago' => [false, time() - 1, true],
    'passed within the hour' => [false, time() + 600, false],
    'signed-in client' => [true, 0, false],
]);
