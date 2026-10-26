<?php

declare(strict_types=1);

test('saving the settings needs the manage settings permission', function (): void {
    $staff = Mockery::mock(ClientFence\Mod\Staff\Service::class);
    $staff->shouldReceive('checkPermissionsAndThrowException')->with('cookieconsent', 'manage_settings', Mockery::any(), Mockery::any())->once()
        ->andThrow(new ClientFence\InformationException('No permission', [], 403));
    $service = Mockery::mock(ClientFence\Mod\Cookieconsent\Service::class);
    $service->shouldNotReceive('saveSettings');

    $di = new Pimple\Container();
    $di['mod_service'] = $di->protect(fn (string $name): object => $staff);
    $api = apiEndpoint(new ClientFence\Mod\Cookieconsent\Api\Admin());
    $api->setDi($di);
    $api->setService($service);

    expect(fn () => $api->save_settings(['message' => 'We use cookies.']))->toThrow(ClientFence\InformationException::class);
});
