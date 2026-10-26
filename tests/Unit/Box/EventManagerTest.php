<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use function Tests\Helpers\container;

test('empty fire', function (): void {
    $manager = new \ClientFence\EventManager();
    expect($manager->fire([]))->toBeFalse();
});

test('fire', function (): void {
    $dbMock = Mockery::mock('ClientFence\Database');
    /** @var Mockery\Expectation $expectation */
    $expectation = $dbMock->shouldReceive('getAll');
    $expectation->atLeast()->once();
    $expectation->andReturn([]);

    $di = container();
    $di['logger'] = new \ClientFence\Log();
    $di['db'] = $dbMock;

    $manager = new \ClientFence\EventManager();
    $manager->setDi($di);

    $manager->fire(['event' => 'onBeforeClientSignup']);
});

test('listeners are read once per request and matched by event name, ignoring case', function (): void {
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getAll')->once()->andReturn([
        ['id' => 1, 'rel_id' => 'signups', 'meta_value' => 'onbeforeclientsignup'],
        ['id' => 2, 'rel_id' => 'orders', 'meta_value' => 'onAfterAdminOrderCreate'],
    ]);
    $asked = [];
    $di = container();
    $di['logger'] = new \ClientFence\Log();
    $di['db'] = $dbMock;
    $di['request_cache'] = new ArrayObject();
    $di['mod_service'] = $di->protect(function (string $mod) use (&$asked): stdClass {
        $asked[] = $mod;

        return new stdClass();
    });

    $manager = new \ClientFence\EventManager();
    $manager->setDi($di);
    $manager->fire(['event' => 'onBeforeClientSignup']);
    $manager->fire(['event' => 'onBeforeClientSignup']);
    $manager->fire(['event' => 'onAfterClientSignup']);

    expect($asked)->toBe(['signups', 'signups']);
});
