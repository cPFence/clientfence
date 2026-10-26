<?php

declare(strict_types=1);

test('each SEO action checks its staff permission', function (string $method, string $permission): void {
    $staff = Mockery::mock(ClientFence\Mod\Staff\Service::class);
    $staff->shouldReceive('checkPermissionsAndThrowException')->with('seo', $permission, Mockery::any(), Mockery::any())->once()
        ->andThrow(new ClientFence\InformationException('No permission', [], 403));
    $service = Mockery::mock(ClientFence\Mod\Seo\Service::class);
    $service->shouldNotReceive('submit', 'saveVerification');

    $di = new Pimple\Container();
    $di['mod_service'] = $di->protect(fn (string $name): object => $staff);
    $api = apiEndpoint(new ClientFence\Mod\Seo\Api\Admin());
    $api->setDi($di);
    $api->setService($service);

    expect(fn () => $api->{$method}([]))->toThrow(ClientFence\InformationException::class);
})->with([
    'submit now' => ['submit', 'manage'],
    'verification codes' => ['save_settings', 'manage_settings'],
]);
