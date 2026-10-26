<?php

declare(strict_types=1);

/**
 * ClientFence: ticket priorities as WHMCS's Low, Medium and High (Support\TicketPriority).
 */

use ClientFence\InformationException;
use ClientFence\Mod\Support\TicketPriority;

test('levels and their numbers', function (): void {
    expect(TicketPriority::fromInput('low'))->toBe(50)
        ->and(TicketPriority::fromInput(' HIGH '))->toBe(150)
        ->and(TicketPriority::fromInput('Medium'))->toBe(100)
        ->and(TicketPriority::fromInput(null))->toBe(100)
        ->and(TicketPriority::fromInput(''))->toBe(100)
        ->and(TicketPriority::fromInput(150))->toBe(150)
        ->and(TicketPriority::fromInput('50'))->toBe(50);
});

test('anything else is refused', function (mixed $value): void {
    expect(fn () => TicketPriority::fromInput($value))->toThrow(InformationException::class);
})->with([['urgent'], [99], ['101'], [-1], [['high']], [1.5]]);

test('a stored number reads as the nearest level', function (): void {
    expect(TicketPriority::level(100))->toBe('medium')
        ->and(TicketPriority::level('100'))->toBe('medium')
        ->and(TicketPriority::level(null))->toBe('medium')
        ->and(TicketPriority::level(50))->toBe('low')
        ->and(TicketPriority::level(1))->toBe('low')
        ->and(TicketPriority::level(150))->toBe('high')
        ->and(TicketPriority::level(200))->toBe('high');
});

test('a level filter matches the numbers it reads as', function (): void {
    expect(TicketPriority::filter('low'))->toBe(['<', 100])
        ->and(TicketPriority::filter('medium'))->toBe(['=', 100])
        ->and(TicketPriority::filter('High'))->toBe(['>', 100])
        ->and(fn () => TicketPriority::filter('urgent'))->toThrow(InformationException::class);
});
