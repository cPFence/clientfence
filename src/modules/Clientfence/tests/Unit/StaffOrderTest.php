<?php

declare(strict_types=1);

use ClientFence\Mod\Clientfence\Service;

test('a staff order cart skips the store mode and order form checks', function (bool $staff): void {
    $event = Mockery::mock(\ClientFence\Event::class);
    $event->shouldReceive('getParameters')->andReturn(['product_id' => 1, 'staff_order' => $staff]);
    // The client's checks start by reading the container: a staff order never gets there.
    $staff
        ? $event->shouldNotReceive('getDi')
        : $event->shouldReceive('getDi')->once()->andThrow(new RuntimeException('checked'));

    $run = fn () => Service::onBeforeProductAddedToCart($event);
    $staff ? expect($run())->toBeNull() : expect($run)->toThrow(RuntimeException::class, 'checked');
})->with(['staff order' => [true], 'client cart' => [false]]);
