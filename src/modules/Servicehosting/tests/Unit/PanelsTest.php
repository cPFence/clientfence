<?php

declare(strict_types=1);

use ClientFence\Mod\Client\Users;
use ClientFence\Mod\Servicehosting\Panel\Panels;

use function Tests\Helpers\container;

test('the panel settings are each panel switch and its options', function (): void {
    expect(Panels::settingKeys())->toBe(['panel_enhance', 'enhance_global_webmail']);
});

test('panels read their switches from the Servicehosting settings', function (array $config, bool $on, bool $webmail): void {
    $di = container();
    $di['mod_config'] = $di->protect(fn (string $name): array => $name === 'servicehosting' ? $config : ['panel_enhance' => '1', 'enhance_global_webmail' => '1']);

    expect(Panels::on($di, 'enhance'))->toBe($on)
        ->and(Panels::option($di, 'enhance', 'enhance_global_webmail'))->toBe($webmail);
})->with([
    'off by default' => [[], false, false],
    'on' => [['panel_enhance' => '1'], true, false],
    'option needs the panel on' => [['panel_enhance' => '0', 'enhance_global_webmail' => '1'], false, false],
    'on with option' => [['panel_enhance' => '1', 'enhance_global_webmail' => '1'], true, true],
]);

test('authorized users reach the hosting calls with the products permissions', function (): void {
    expect(Users::CALLS['servicehosting']['accounts'])->toBe('products')
        ->and(Users::CALLS['servicehosting']['email_create'])->toBe('manageproducts')
        ->and(Users::CALLS['servicehosting']['email_webmail'])->toBe('productsso')
        ->and(Users::CALLS['servicehosting']['change_password'])->toBe('manageproducts')
        ->and(array_filter(array_keys(Users::CALLS['client']), static fn (string $call): bool => str_starts_with($call, 'hosting_')))->toBe([]);
});
