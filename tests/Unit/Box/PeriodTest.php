<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use function Tests\Datasets\periodCodes;

test('parses valid period codes correctly', function (string $code, string $expectedUnit, int $expectedQty, float $expectedDays): void {
    $period = new \ClientFence\Period($code);

    expect($period->getUnit())->toBe($expectedUnit);
    expect($period->getQty())->toBe($expectedQty);
    expect($period->getDays())->toEqual($expectedDays);
    expect($period->getCode())->toBe($code);
})->with(periodCodes());

test('contains the quantity in period titles', function (): void {
    expect((new \ClientFence\Period('1D'))->getCode())->toContain('1');
    expect((new \ClientFence\Period('7D'))->getCode())->toContain('7');
    expect((new \ClientFence\Period('1W'))->getCode())->toContain('1');
    expect((new \ClientFence\Period('1M'))->getCode())->toContain('1');
    expect((new \ClientFence\Period('1Y'))->getCode())->toContain('1');
});

test('calculates months correctly', function (): void {
    expect((new \ClientFence\Period('1M'))->getMonths())->toBe(1);
    expect((new \ClientFence\Period('3M'))->getMonths())->toBe(3);
    expect((new \ClientFence\Period('6M'))->getMonths())->toBe(6);
    expect((new \ClientFence\Period('1Y'))->getMonths())->toBe(12);
});

test('has expiration time in the future', function (): void {
    $now = time();
    $period = new \ClientFence\Period('1M');

    $expiration = $period->getExpirationTime();
    expect($expiration)->toBeGreaterThanOrEqual($now);
    expect($expiration)->toBeLessThan($now + 35 * 24 * 60 * 60); // Should be less than 35 days
});

test('keeps the anchor day through short months, or adds calendar months without one', function (string $code, string $from, ?int $anchor, int $count, string $expected): void {
    expect(date('Y-m-d H:i', (int) (new \ClientFence\Period($code))->getExpirationTime((int) strtotime($from), $anchor, $count)))->toBe($expected);
})->with([
    'Jan 31 + 1M keeps the day' => ['1M', '2026-01-31 10:15', 31, 1, '2026-02-28 10:15'],
    'Feb 28 + 1M back to the 31st' => ['1M', '2026-02-28 10:15', 31, 1, '2026-03-31 10:15'],
    'Mar 31 + 1M to Apr 30' => ['1M', '2026-03-31 00:00', 31, 1, '2026-04-30 00:00'],
    'Nov 30 + 3M over the year' => ['3M', '2026-11-30 00:00', 30, 1, '2027-02-28 00:00'],
    'leap day + 1Y' => ['1Y', '2028-02-29 00:00', 29, 1, '2029-02-28 00:00'],
    'Mar 31 - 1M to Feb 28' => ['1M', '2026-03-31 00:00', 31, -1, '2026-02-28 00:00'],
    'Feb 28 - 1M back to Jan 31' => ['1M', '2026-02-28 00:00', 31, -1, '2026-01-31 00:00'],
    'days ignore the anchor' => ['7D', '2026-01-31 00:00', 31, 1, '2026-02-07 00:00'],
    'calendar months' => ['1M', '2026-01-31 00:00', null, 1, '2026-03-03 00:00'],
    'calendar months back' => ['1M', '2026-03-31 00:00', null, -1, '2026-03-03 00:00'],
]);

test('throws exception for invalid period code length', function (): void {
    expect(fn (): \ClientFence\Period => new \ClientFence\Period('1'))->toThrow(ClientFence\Exception::class, 'Invalid period code. Period definition must be 2 chars length');
});

test('throws exception for invalid period unit', function (): void {
    expect(fn (): \ClientFence\Period => new \ClientFence\Period('1Z'))->toThrow(ClientFence\Exception::class, 'Period Error. Unit Z is not defined');
});

test('throws exception for empty period code', function (): void {
    expect(fn (): \ClientFence\Period => new \ClientFence\Period(''))->toThrow(ClientFence\Exception::class, 'Invalid period code. Period definition must be 2 chars length');
});

test('throws exception for period code that is too long', function (): void {
    expect(fn (): \ClientFence\Period => new \ClientFence\Period('123'))->toThrow(ClientFence\Exception::class, 'Invalid period code. Period definition must be 2 chars length');
});
