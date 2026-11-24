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

class EventManagerThrowingListener
{
    public static function onAfterAdminCronRun(ClientFence\Event $event): void
    {
        throw new RuntimeException('module down');
    }

    public static function onBeforeClientSignUp(ClientFence\Event $event): void
    {
        throw new ClientFence\InformationException('Refused');
    }
}

class EventManagerNextListener
{
    public static array $heard = [];

    public static function onAfterAdminCronRun(ClientFence\Event $event): void
    {
        self::$heard[] = $event->getName();
    }

    public static function onBeforeClientSignUp(ClientFence\Event $event): void
    {
        self::$heard[] = $event->getName();
    }
}

test('an isolated fire logs a throwing listener and still runs the next; elsewhere the exception refuses the action', function (): void {
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getAll')->andReturn([
        ['id' => 1, 'rel_id' => 'throwing', 'meta_value' => 'onAfterAdminCronRun'],
        ['id' => 2, 'rel_id' => 'next', 'meta_value' => 'onAfterAdminCronRun'],
        ['id' => 3, 'rel_id' => 'throwing', 'meta_value' => 'onBeforeClientSignUp'],
        ['id' => 4, 'rel_id' => 'next', 'meta_value' => 'onBeforeClientSignUp'],
    ]);
    $di = container();
    $di['logger'] = $logger = new Tests\Helpers\TestLogger();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(fn (string $mod): object => $mod === 'throwing' ? new EventManagerThrowingListener() : new EventManagerNextListener());
    $manager = new ClientFence\EventManager();
    $manager->setDi($di);
    EventManagerNextListener::$heard = [];

    $manager->fire(['event' => 'onAfterAdminCronRun', 'isolated' => true]);

    expect(EventManagerNextListener::$heard)->toBe(['onAfterAdminCronRun'])
        ->and(array_values(array_filter($logger->calls, fn (array $call): bool => $call['method'] === 'warning'))[0]['params'][0])
        ->toBe('Listener EventManagerThrowingListener::onAfterAdminCronRun of onAfterAdminCronRun failed: RuntimeException: module down')
        ->and(fn () => $manager->fire(['event' => 'onBeforeClientSignUp']))->toThrow(ClientFence\InformationException::class, 'Refused')
        ->and(EventManagerNextListener::$heard)->toBe(['onAfterAdminCronRun']);
});
