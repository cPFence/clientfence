<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use ClientFence\Module;

use function Tests\Helpers\container;

test('empty config', function (): void {
    $dbMock = Mockery::mock('ClientFence\Database');
    /** @var Mockery\Expectation $expectation */
    $expectation = $dbMock->shouldReceive('findOne');
    $expectation->atLeast()->once();
    $expectation->andReturn(null);

    $di = container();
    $di['db'] = $dbMock;

    $mod = new Module('api');
    $mod->setDi($di);
    $array = $mod->getConfig();
    expect($array)->toBe([]);
});

test('core mod', function (): void {
    $mod = new Module('api');
    expect($mod->isCore())->toBeTrue();

    $array = $mod->getCoreModules();
    expect($array)->toBeArray();

    $mod = new Module('Cookieconsent');
    expect($mod->isCore())->toBeFalse();
});

test('manifest', function (): void {
    $di = container();
    $di['url'] = new \ClientFence\Url();

    $mod = new Module('Cookieconsent');
    $mod->setDi($di);

    $bool = $mod->hasManifest();
    expect($bool)->toBeTrue();

    $array = $mod->getManifest();
    expect($array)->toBeArray();
});

test('get service sub', function (): void {
    $mod = new Module('Invoice');
    $subServiceName = 'transaction';

    $di = container();
    $mod->setDi($di);

    $subService = $mod->getService($subServiceName);
    expect($subService)->toBeInstanceOf(ClientFence\Mod\Invoice\ServiceTransaction::class);
});

test('module manifests reject non-object JSON as an information error', function (string $json): void {
    $di = container();
    $filesystem = Mockery::mock(Symfony\Component\Filesystem\Filesystem::class);
    $filesystem->shouldReceive('exists')->andReturnTrue();
    $filesystem->shouldReceive('readFile')->andReturn($json);
    $di['filesystem'] = $filesystem;
    $module = new Module('Client');
    $module->setDi($di);
    expect(fn () => $module->getManifest())->toThrow(ClientFence\InformationException::class, 'Invalid manifest file');
})->with(['null', '[]', '"module"', '1', '{broken']);
