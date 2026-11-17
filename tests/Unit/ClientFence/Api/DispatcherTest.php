<?php

declare(strict_types=1);

use ClientFence\Api\Dispatcher;
use ClientFence\Identity\Guest;

test('a method starting with an underscore is never an API call', function (string $call): void {
    $dispatcher = new Dispatcher();
    $dispatcher->setDi(new Pimple\Container());

    expect(fn () => $dispatcher->dispatch(new Guest(), $call))
        ->toThrow(ClientFence\Exception::class, 'does not exist');
    try {
        $dispatcher->dispatch(new Guest(), $call);
    } catch (ClientFence\Exception $e) {
        expect($e->getCode())->toBe(740);
    }
})->with(['servicehosting__getService', 'servicelicense__getService']);
