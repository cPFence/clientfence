<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use ClientFence\Twig\Extension\LegacyExtension;

beforeEach(function (): void {
    $this->extension = new LegacyExtension(null);
});

test('modAssetUrl builds URL with ucfirst module name and a version that follows the module', function (): void {
    $di = new Pimple\Container();
    $versions = ['invoice' => '1.0.0', 'serviceexample' => '2.0.0'];
    $di['mod'] = $di->protect(function (string $name) use (&$versions) {
        $mod = Mockery::mock(ClientFence\Module::class);
        $mod->shouldReceive('getManifest')->andReturnUsing(fn () => isset($versions[$name]) ? ['version' => $versions[$name]] : throw new ClientFence\InformationException('Missing manifest file for the :mod module.', [':mod' => $name], 5897));

        return $mod;
    });
    $extension = new LegacyExtension($di);

    $url = $extension->modAssetUrl('img/logo.png', 'invoice');
    expect($url)->toStartWith(SYSTEM_URL . 'modules/Invoice/assets/img/logo.png?v=')
        ->and(strlen($url) - strlen(SYSTEM_URL . 'modules/Invoice/assets/img/logo.png?v='))->toBe(10);

    $before = $extension->modAssetUrl('js/example.js', 'serviceexample');
    $versions['serviceexample'] = '2.0.1';
    expect($extension->modAssetUrl('js/example.js', 'serviceexample'))->not->toBe($before)
        // A module without a manifest still gets a working URL.
        ->and($extension->modAssetUrl('a.css', 'nomodule'))->toStartWith(SYSTEM_URL . 'modules/Nomodule/assets/a.css?v=');
});

test('modAssetUrl returns empty string for null asset', function (): void {
    expect($this->extension->modAssetUrl(null, 'invoice'))->toBe('');
});

test('modAssetUrl returns empty string for null module', function (): void {
    expect($this->extension->modAssetUrl('img/logo.png', null))->toBe('');
});

test('modAssetUrl returns empty string when both are null', function (): void {
    expect($this->extension->modAssetUrl(null, null))->toBe('');
});

test('periodTitle returns empty string for null without calling the API', function (): void {
    $di = Mockery::mock(Pimple\Container::class);
    $di->shouldNotReceive('offsetGet');
    $extension = new LegacyExtension($di);

    expect($extension->periodTitle(null))->toBe('');
});
