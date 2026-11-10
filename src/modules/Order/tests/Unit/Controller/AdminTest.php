<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use ClientFence\Mod\Order\Controller\Admin;
use Symfony\Component\HttpFoundation\Request;

use function Tests\Helpers\container;

test('register configures routes', function (): void {
    $controller = new Admin();
    $boxAppMock = Mockery::mock('ClientFence\App');
    $boxAppMock->shouldReceive('get')->atLeast()->once();

    $controller->register($boxAppMock);
});

test('get new loads the client from the query', function (): void {
    $controller = new Admin();
    $di = container();
    $di['is_admin_logged'] = true;

    $client = ['id' => 9, 'email' => 'client@example.com'];
    $apiAdmin = Mockery::mock();
    $apiAdmin->shouldReceive('client_get')->once()->with(['id' => 9])->andReturn($client);
    $di['api_admin'] = $apiAdmin;
    $controller->setDi($di);

    $boxAppMock = Mockery::mock('ClientFence\App');
    $boxAppMock->shouldReceive('getRequest')->once()->andReturn(Request::create('/order/new', 'GET', ['client_id' => '9']));
    $boxAppMock->shouldReceive('render')->once()->with('mod_order_new', ['client' => $client])->andReturn('rendered');

    expect($controller->get_new($boxAppMock))->toBe('rendered');
});

test('get new opens without a client', function (): void {
    $controller = new Admin();
    $di = container();
    $di['is_admin_logged'] = true;
    $apiAdmin = Mockery::mock();
    $apiAdmin->shouldNotReceive('client_get');
    $di['api_admin'] = $apiAdmin;
    $controller->setDi($di);

    $boxAppMock = Mockery::mock('ClientFence\App');
    $boxAppMock->shouldReceive('getRequest')->once()->andReturn(Request::create('/order/new'));
    $boxAppMock->shouldReceive('render')->once()->with('mod_order_new', ['client' => null])->andReturn('rendered');

    expect($controller->get_new($boxAppMock))->toBe('rendered');
});
