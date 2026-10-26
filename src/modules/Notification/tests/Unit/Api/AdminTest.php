<?php

declare(strict_types=1);

use function Tests\Helpers\moduleService;

test('only staff who manage announcements get every one, and posts carry the poster', function (bool $manage): void {
    $admin = new Model_Admin();
    $staff = Mockery::mock(ClientFence\Mod\Staff\Service::class)->shouldIgnoreMissing();
    $staff->shouldReceive('hasPermission')->with($admin, 'notification', 'manage')->andReturn($manage);

    $service = Mockery::mock(ClientFence\Mod\Notification\Service::class);
    $service->shouldReceive('getList')->with($admin, $manage)->once()->andReturn([]);
    $service->shouldReceive('create')->with($admin, ['title' => 'T'])->once()->andReturn(7);

    $di = new Pimple\Container();
    $di['mod_service'] = $di->protect(moduleService(['staff' => $staff]));

    $api = apiEndpoint(new ClientFence\Mod\Notification\Api\Admin());
    $api->setDi($di);
    $api->setService($service);
    $api->setIdentity($admin);

    expect($api->get_list([]))->toBe([])
        ->and($api->add(['title' => 'T']))->toBe(7);
})->with([true, false]);

test('delete delegates to service', function (): void {
    $service = Mockery::mock(ClientFence\Mod\Notification\Service::class);
    $service->shouldReceive('delete')->with(9)->once()->andReturn(true);

    $api = apiEndpoint(new ClientFence\Mod\Notification\Api\Admin());
    $api->setService($service);

    expect($api->delete(['id' => 9]))->toBeTrue();
});
