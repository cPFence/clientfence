<?php

declare(strict_types=1);

test('saving the allowed websites needs the manage settings permission', function (): void {
    $staff = Mockery::mock(ClientFence\Mod\Staff\Service::class);
    $staff->shouldReceive('checkPermissionsAndThrowException')->with('embed', 'manage_settings', Mockery::any(), Mockery::any())->once()
        ->andThrow(new ClientFence\InformationException('No permission', [], 403));
    $service = Mockery::mock(ClientFence\Mod\Embed\Service::class);
    $service->shouldNotReceive('saveAllowedOrigins');

    $di = new Pimple\Container();
    $di['mod_service'] = $di->protect(fn (string $name): object => $staff);
    $api = apiEndpoint(new ClientFence\Mod\Embed\Api\Admin());
    $api->setDi($di);
    $api->setService($service);

    expect(fn () => $api->save_settings(['allowed_origins' => 'https://example.com']))->toThrow(ClientFence\InformationException::class);
});
