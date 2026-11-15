<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Request;

use function Tests\Helpers\container;

test('dependency injection', function (): void {
    $service = new ClientFence\Mod\Antispam\Service();
    $di = container();
    $service->setDi($di);
    $getDi = $service->getDi();
    expect($getDi)->toEqual($di);
});

test('on before client sign up', function (): void {
    $service = new ClientFence\Mod\Antispam\Service();
    $spamCheckerService = Mockery::mock(ClientFence\Mod\Antispam\Service::class);
    $spamCheckerService->shouldReceive('isBlockedIp')
        ->atLeast()->once();
    $spamCheckerService->shouldReceive('isSpam')
        ->atLeast()->once();
    $spamCheckerService->shouldReceive('isTemp')
        ->atLeast()->once();
    $spamCheckerService->shouldReceive('checkHoneypot')
        ->atLeast()->once();
    $spamCheckerService->shouldReceive('isBanned')->once();
    $spamCheckerService->shouldReceive('isHostingNetwork')->once();

    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $spamCheckerService);
    $boxEventMock = Mockery::mock('ClientFence\Event');
    $boxEventMock->shouldReceive('getDi')
        ->atLeast()->once()
        ->andReturn($di);

    $service->onBeforeClientSignUp($boxEventMock);
});

test('on before client open ticket checks guest submissions', function (): void {
    $service = new ClientFence\Mod\Antispam\Service();
    $spamCheckerService = Mockery::mock(ClientFence\Mod\Antispam\Service::class);
    $spamCheckerService->shouldReceive('isBlockedIp')
        ->atLeast()->once();
    $spamCheckerService->shouldReceive('checkCaptcha')
        ->once();
    $spamCheckerService->shouldReceive('isSpam')
        ->atLeast()->once();
    $spamCheckerService->shouldReceive('isTemp')
        ->atLeast()->once();

    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $spamCheckerService);
    $boxEventMock = Mockery::mock('ClientFence\Event');
    $boxEventMock->shouldReceive('getDi')
        ->atLeast()->once()
        ->andReturn($di);
    $boxEventMock->shouldReceive('getParameters')
        ->atLeast()->once()
        ->andReturn(['author_role' => 'guest', 'email' => 'guest@example.com']);

    $service->onBeforeClientOpenTicket($boxEventMock);
});

test('on before client open ticket skips client submissions', function (): void {
    $service = new ClientFence\Mod\Antispam\Service();
    $spamCheckerService = Mockery::mock(ClientFence\Mod\Antispam\Service::class);
    $spamCheckerService->shouldReceive('isBlockedIp')->never();
    $spamCheckerService->shouldReceive('checkCaptcha')->never();
    $spamCheckerService->shouldReceive('isSpam')->never();
    $spamCheckerService->shouldReceive('isTemp')->never();

    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $spamCheckerService);
    $boxEventMock = Mockery::mock('ClientFence\Event');
    $boxEventMock->shouldReceive('getParameters')
        ->atLeast()->once()
        ->andReturn(['author_role' => 'client', 'client_id' => 1]);

    $service->onBeforeClientOpenTicket($boxEventMock);
});

test('is spam does not re-verify the captcha during signup', function (): void {
    // The signup API already verifies the CAPTCHA before this event fires;
    // isSpam() re-checking it would fail every signup since CAPTCHA tokens
    // are single-use.
    $service = Mockery::mock(ClientFence\Mod\Antispam\Service::class)->makePartial();
    $service->shouldReceive('checkCaptcha')->never();

    $di = container();
    $di['mod_config'] = $di->protect(fn (): array => ['sfs' => false]);

    $boxEventMock = Mockery::mock('ClientFence\Event');
    $boxEventMock->shouldReceive('getDi')
        ->atLeast()->once()
        ->andReturn($di);
    $boxEventMock->shouldReceive('getParameters')
        ->atLeast()->once()
        ->andReturn(['ip' => '1.2.3.4', 'email' => 'test@example.com']);

    $service->isSpam($boxEventMock);
});

test('is blocked ip ip not blocked', function (): void {
    $service = new ClientFence\Mod\Antispam\Service();
    $clientIp = '214.1.4.99';
    $modConfig = [
        'block_ips' => true,
        'blocked_ips' => '1.1.1.1' . PHP_EOL . '2.2.2.2',
    ];

    $di = container();
    $di['request'] = Request::createFromGlobals();
    $di['mod_config'] = $di->protect(function ($modName) use ($modConfig) {
        if ($modName == 'Antispam') {
            return $modConfig;
        }
    });

    $boxEventMock = Mockery::mock('ClientFence\Event');
    $boxEventMock->shouldReceive('getDi')
        ->atLeast()->once()
        ->andReturn($di);

    $service->isBlockedIp($boxEventMock);
});

test('is blocked ip block ips not enabled', function (): void {
    $service = new ClientFence\Mod\Antispam\Service();
    $modConfig = [
        'block_ips' => false,
    ];

    $di = container();
    $di['mod_config'] = $di->protect(function ($modName) use ($modConfig) {
        if ($modName == 'Antispam') {
            return $modConfig;
        }
    });

    $boxEventMock = Mockery::mock('ClientFence\Event');
    $boxEventMock->shouldReceive('getDi')
        ->atLeast()->once()
        ->andReturn($di);

    $service->isBlockedIp($boxEventMock);
});

dataset('spam responses', fn (): array => [
    [
        '{"success" : "true", "username" : {"appears" : "true" }}', 'Your username is blacklisted in the Stop Forum Spam database',
    ],
    [
        '{"success" : "true", "email" : {"appears" : "true" }}', 'Your e-mail is blacklisted in the Stop Forum Spam database',
    ],
    [
        '{"success" : "true", "ip" : {"appears" : "true" }}', 'Your IP address is blacklisted in the Stop Forum Spam database',
    ],
]);

test('the blocked IP list takes ranges and wildcards', function (string $ip, bool $blocked): void {
    $di = container();
    $di['request'] = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);
    $di['mod_config'] = $di->protect(fn (): array => ['block_ips' => true, 'blocked_ips' => implode(PHP_EOL, ['192.0.2.7', '198.51.100.0/24', '203.0.113.*', '10.*.*.*', '2001:db8::/32'])]);
    $event = new ClientFence\Event(null, 'onBeforeClientLogin');
    $event->setDi($di);

    $check = fn () => (new ClientFence\Mod\Antispam\Service())->isBlockedIp($event);
    $blocked ? expect($check)->toThrow(ClientFence\InformationException::class) : expect($check())->toBeNull();
})->with([
    ['192.0.2.7', true],
    ['192.0.2.8', false],
    ['198.51.100.200', true],
    ['203.0.113.9', true],
    ['203.0.114.9', false],
    ['10.20.30.40', true],
    ['2001:db8::1', true],
    ['2001:db9::1', false],
]);

test('an unreachable Stop Forum Spam lets the sign-up through', function (): void {
    $di = container();
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['http_client'] = new Symfony\Component\HttpClient\MockHttpClient(function (string $method, string $url, array $options): never {
        // A short timeout is set, so a hung service fails fast.
        expect($options['timeout'])->toBeLessThanOrEqual(5.0);

        throw new Symfony\Component\HttpClient\Exception\TransportException('Idle timeout reached');
    });
    $service = new ClientFence\Mod\Antispam\Service();
    $service->setDi($di);

    expect($service->isInStopForumSpamDatabase(['ip' => '192.0.2.1', 'email' => 'a@example.com']))->toBeFalse();
});
