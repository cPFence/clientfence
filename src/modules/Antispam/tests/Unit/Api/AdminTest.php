<?php

declare(strict_types=1);

test('get_config shows the CAPTCHA secret keys only to staff who may change them', function (bool $canManage): void {
    $staff = Mockery::mock(ClientFence\Mod\Staff\Service::class);
    $staff->shouldReceive('checkPermissionsAndThrowException')->with('antispam', 'view', Mockery::any(), Mockery::any());
    $staff->shouldReceive('hasPermission')->with(Mockery::any(), 'antispam', 'manage_settings')->andReturn($canManage);

    $di = new Pimple\Container();
    $di['mod_service'] = $di->protect(fn (string $name): object => $staff);
    $di['mod_config'] = $di->protect(fn (string $name): array => [
        'captcha_recaptcha_publickey' => 'site', 'captcha_recaptcha_privatekey' => 'secret-1',
        'turnstile_secret_key' => 'secret-2', 'hcaptcha_secret_key' => 'secret-3',
    ]);
    $api = apiEndpoint(new ClientFence\Mod\Antispam\Api\Admin());
    $api->setDi($di);

    $config = $api->get_config([]);

    expect($config['captcha_recaptcha_publickey'])->toBe('site')
        ->and(array_key_exists('hcaptcha_secret_key', $config))->toBe($canManage)
        ->and(array_key_exists('turnstile_secret_key', $config))->toBe($canManage)
        ->and(array_key_exists('captcha_recaptcha_privatekey', $config))->toBe($canManage);
})->with([true, false]);
