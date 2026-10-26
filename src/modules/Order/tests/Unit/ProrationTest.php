<?php

declare(strict_types=1);

/**
 * ClientFence: upgrade and downgrade prices (Order\Proration). The expected values were computed
 * independently (calendar dates in Python, decimal half-up rounding), not from this code.
 * Prices: cPFence tiers 1 server 5/month or 48/year, 10 servers 45/month or 300/year, 20 servers
 * 85/month or 600/year.
 */

use ClientFence\Mod\Order\Proration;
use ClientFence\InformationException;

/**
 * @return array<string, mixed>
 */
function prorate(float $oldPrice, string $oldPeriod, string $expires, float $newPrice, string $newPeriod, string $now, ?float $sameCycle = null): array
{
    return Proration::quote($oldPrice, $oldPeriod, $expires, $newPrice, $newPeriod, $now, $sameCycle);
}

dataset('same cycle', [
    'monthly upgrade mid-period' => [10, '1M', '2026-11-16 00:00:00', 20, '1M', '2026-11-01 10:00:00', ['period_start' => '2026-10-16', 'days_total' => 31, 'days_left' => 15, 'credit' => 4.84, 'charge' => 9.68, 'amount' => 4.84, 'kind' => 'upgrade']],
    'monthly downgrade mid-period' => [20, '1M', '2026-11-16 00:00:00', 10, '1M', '2026-11-01 10:00:00', ['days_left' => 15, 'credit' => 9.68, 'charge' => 4.84, 'amount' => -4.84, 'kind' => 'downgrade']],
    'yearly tier 1 to tier 10' => [48, '1Y', '2027-03-01 00:00:00', 300, '1Y', '2026-10-16 12:00:00', ['period_start' => '2026-03-01', 'days_total' => 365, 'days_left' => 136, 'credit' => 17.88, 'charge' => 111.78, 'amount' => 93.90, 'kind' => 'upgrade']],
    'yearly period with 29 February' => [100, '1Y', '2028-06-01 00:00:00', 200, '1Y', '2028-02-28 08:00:00', ['period_start' => '2027-06-01', 'days_total' => 366, 'days_left' => 94, 'credit' => 25.68, 'charge' => 51.37, 'amount' => 25.69]],
    'month ending 31 March' => [31, '1M', '2026-03-31 00:00:00', 62, '1M', '2026-03-15 00:00:00', ['period_start' => '2026-02-28', 'days_total' => 31, 'days_left' => 16, 'credit' => 16.00, 'charge' => 32.00, 'amount' => 16.00]],
    'month ending 31 March, leap year' => [31, '1M', '2028-03-31 00:00:00', 62, '1M', '2028-03-15 00:00:00', ['period_start' => '2028-02-29', 'days_total' => 31]],
    'month ending 30 March' => [30, '1M', '2026-03-30 00:00:00', 60, '1M', '2026-03-15 00:00:00', ['period_start' => '2026-02-28', 'days_total' => 30, 'days_left' => 15, 'amount' => 15.00]],
    'month across new year' => [12, '1M', '2027-01-31 00:00:00', 24, '1M', '2027-01-15 00:00:00', ['period_start' => '2026-12-31', 'days_total' => 31, 'days_left' => 16, 'credit' => 6.19, 'charge' => 12.39, 'amount' => 6.20]],
    'request on 31 January' => [5, '1M', '2027-02-10 00:00:00', 45, '1M', '2027-01-31 10:00:00', ['period_start' => '2027-01-10', 'days_left' => 10, 'credit' => 1.61, 'charge' => 14.52, 'amount' => 12.91]],
    'expires today' => [10, '1M', '2026-11-16 00:00:00', 20, '1M', '2026-11-16 09:00:00', ['days_left' => 0, 'credit' => 0.0, 'charge' => 0.0, 'amount' => 0.0, 'kind' => 'upgrade']],
    'already expired' => [10, '1M', '2026-11-16 00:00:00', 20, '1M', '2026-11-20 09:00:00', ['days_left' => 0, 'amount' => 0.0]],
    'last second of the day before expiry' => [365, '1Y', '2026-10-17 00:00:00', 730, '1Y', '2026-10-16 23:59:59', ['days_total' => 365, 'days_left' => 1, 'credit' => 1.00, 'charge' => 2.00, 'amount' => 1.00]],
    'paid more than a year ahead' => [100, '1Y', '2028-01-01 00:00:00', 200, '1Y', '2026-12-01 00:00:00', ['period_start' => '2027-01-01', 'days_total' => 365, 'days_left' => 396, 'credit' => 108.49, 'charge' => 216.99, 'amount' => 108.50]],
    'from a free product' => [0, '1M', '2026-11-16 00:00:00', 20, '1M', '2026-11-01 00:00:00', ['credit' => 0.0, 'charge' => 9.68, 'amount' => 9.68, 'kind' => 'upgrade']],
    'to a free product' => [20, '1M', '2026-11-16 00:00:00', 0, '1M', '2026-11-01 00:00:00', ['credit' => 9.68, 'charge' => 0.0, 'amount' => -9.68, 'kind' => 'downgrade']],
    'weekly' => [7, '1W', '2026-10-20 00:00:00', 14, '1W', '2026-10-16 00:00:00', ['period_start' => '2026-10-13', 'days_total' => 7, 'days_left' => 4, 'credit' => 4.00, 'charge' => 8.00, 'amount' => 4.00]],
    'biennial across 29 February' => [200, '2Y', '2028-03-01 00:00:00', 400, '2Y', '2027-03-01 00:00:00', ['period_start' => '2026-03-01', 'days_total' => 731, 'days_left' => 366, 'credit' => 100.14, 'charge' => 200.27, 'amount' => 100.13]],
    'half a cent rounds up' => [0.01, '2D', '2026-11-02 00:00:00', 0.03, '2D', '2026-11-01 00:00:00', ['days_total' => 2, 'days_left' => 1, 'credit' => 0.01, 'charge' => 0.02, 'amount' => 0.01]],
    'same price, nothing to pay' => [5, '1M', '2026-11-10 00:00:00', 5, '1M', '2026-10-25 00:00:00', ['credit' => 2.58, 'charge' => 2.58, 'amount' => 0.0, 'kind' => 'upgrade']],
]);

test('same billing cycle: prorated for the days left, expiry date kept', function (float $old, string $oldPeriod, string $expires, float $new, string $newPeriod, string $now, array $expected): void {
    $quote = prorate($old, $oldPeriod, $expires, $new, $newPeriod, $now);

    expect($quote)->toMatchArray($expected)
        ->and($quote['same_cycle'])->toBeTrue()
        ->and($quote['new_expires_at'])->toBe($expires)
        ->and($quote['amount'])->toBe(round($quote['charge'] - $quote['credit'], 2));
})->with('same cycle');

dataset('other cycle', [
    'tier 1 monthly to tier 1 yearly' => [5, '1M', '2026-11-10 00:00:00', 48, '1Y', '2026-10-25 14:30:00', 48, ['period_start' => '2026-10-10', 'days_total' => 31, 'days_left' => 16, 'credit' => 2.58, 'charge' => 48.00, 'amount' => 45.42, 'new_expires_at' => '2027-10-25 14:30:00', 'kind' => 'upgrade']],
    'tier 1 yearly to tier 10 monthly' => [48, '1Y', '2027-01-01 00:00:00', 45, '1M', '2026-10-16 09:15:00', 5, ['period_start' => '2026-01-01', 'days_total' => 365, 'days_left' => 77, 'credit' => 10.13, 'charge' => 45.00, 'amount' => 34.87, 'new_expires_at' => '2026-11-16 09:15:00', 'kind' => 'upgrade']],
    'tier 10 yearly to tier 1 monthly' => [300, '1Y', '2027-01-01 00:00:00', 5, '1M', '2026-10-16 09:15:00', 45, ['credit' => 63.29, 'charge' => 5.00, 'amount' => -58.29, 'kind' => 'downgrade']],
    'tier 10 yearly to tier 20 monthly: an upgrade that refunds' => [300, '1Y', '2027-07-01 00:00:00', 85, '1M', '2026-10-16 00:00:00', 45, ['days_left' => 258, 'credit' => 212.05, 'charge' => 85.00, 'amount' => -127.05, 'new_expires_at' => '2026-11-16 00:00:00', 'kind' => 'upgrade']],
    'tier 1 yearly to tier 1 monthly on 31 January' => [48, '1Y', '2027-06-01 00:00:00', 5, '1M', '2027-01-31 10:00:00', 5, ['days_total' => 365, 'days_left' => 121, 'credit' => 15.91, 'charge' => 5.00, 'amount' => -10.91, 'new_expires_at' => '2027-02-28 10:00:00', 'kind' => 'downgrade']],
    'same, leap year: ends 29 February' => [48, '1Y', '2028-06-01 00:00:00', 5, '1M', '2028-01-31 10:00:00', 5, ['days_total' => 366, 'days_left' => 122, 'credit' => 16.00, 'amount' => -11.00, 'new_expires_at' => '2028-02-29 10:00:00', 'kind' => 'downgrade']],
    'monthly to yearly on 29 February' => [5, '1M', '2028-03-10 00:00:00', 48, '1Y', '2028-02-29 10:00:00', 48, ['period_start' => '2028-02-10', 'days_total' => 29, 'days_left' => 10, 'credit' => 1.72, 'amount' => 46.28, 'new_expires_at' => '2029-02-28 10:00:00', 'kind' => 'upgrade']],
    'quarterly to half-yearly' => [30, '3M', '2026-12-01 00:00:00', 50, '6M', '2026-10-16 00:00:00', 60, ['period_start' => '2026-09-01', 'days_total' => 91, 'days_left' => 46, 'credit' => 15.16, 'charge' => 50.00, 'amount' => 34.84, 'new_expires_at' => '2027-04-16 00:00:00', 'kind' => 'downgrade']],
    'expired monthly to yearly' => [10, '1M', '2026-11-16 00:00:00', 100, '1Y', '2026-11-20 09:00:00', 120, ['days_left' => 0, 'credit' => 0.0, 'charge' => 100.00, 'amount' => 100.00, 'new_expires_at' => '2027-11-20 09:00:00', 'kind' => 'downgrade']],
]);

test('another billing cycle: a whole new cycle from today, minus the unused credit', function (float $old, string $oldPeriod, string $expires, float $new, string $newPeriod, string $now, float $sameCycle, array $expected): void {
    $quote = prorate($old, $oldPeriod, $expires, $new, $newPeriod, $now, $sameCycle);

    expect($quote)->toMatchArray($expected)
        ->and($quote['same_cycle'])->toBeFalse()
        ->and($quote['amount'])->toBe(round($quote['charge'] - $quote['credit'], 2));
})->with('other cycle');

test('without the current product price for the new cycle, products compare per day', function (): void {
    // 5 a month is more per day than 48 a year: by rate alone a cheaper yearly plan is a downgrade.
    expect(prorate(5, '1M', '2026-11-10 00:00:00', 48, '1Y', '2026-10-25 00:00:00')['kind'])->toBe('downgrade')
        ->and(prorate(5, '1M', '2026-11-10 00:00:00', 300, '1Y', '2026-10-25 00:00:00')['kind'])->toBe('upgrade')
        // The same yearly price passed as the current product's: a change of cycle the client pays for.
        ->and(prorate(5, '1M', '2026-11-10 00:00:00', 48, '1Y', '2026-10-25 00:00:00', 48)['kind'])->toBe('upgrade');
});

test('the time of day never changes the result', function (): void {
    $early = prorate(48, '1Y', '2027-03-01 00:00:00', 300, '1Y', '2026-10-16 00:00:01');
    $late = prorate(48, '1Y', '2027-03-01 23:59:59', 300, '1Y', '2026-10-16 23:59:59');

    expect(array_diff_key($late, ['expires_at' => 1, 'new_expires_at' => 1]))->toBe(array_diff_key($early, ['expires_at' => 1, 'new_expires_at' => 1]));
});

test('a renewal later lands where the order expires: credit and charge use the same days', function (): void {
    // Every day of a monthly period from its first to its last: the credit never exceeds the
    // order price and is the order price on the first day.
    foreach (range(0, 30) as $offset) {
        $now = (new DateTimeImmutable('2026-10-16'))->modify("+{$offset} days")->format('Y-m-d') . ' 12:00:00';
        $quote = prorate(31, '1M', '2026-11-16 00:00:00', 62, '1M', $now);
        expect($quote['days_left'])->toBe(31 - $offset)
            ->and($quote['credit'])->toBe((float) (31 - $offset))
            ->and($quote['charge'])->toBe((float) (2 * (31 - $offset)));
    }
});

test('months and years move to the same day, clamped to the month end', function (string $from, int $qty, string $unit, int $sign, string $to): void {
    expect(Proration::shift(new DateTimeImmutable($from), $qty, $unit, $sign)->format('Y-m-d'))->toBe($to);
})->with([
    ['2027-01-31', 1, 'M', 1, '2027-02-28'],
    ['2028-01-31', 1, 'M', 1, '2028-02-29'],
    ['2026-03-31', 1, 'M', -1, '2026-02-28'],
    ['2026-05-31', 1, 'M', -1, '2026-04-30'],
    ['2027-01-15', 1, 'M', -1, '2026-12-15'],
    ['2026-02-15', 3, 'M', -1, '2025-11-15'],
    ['2026-11-30', 3, 'M', 1, '2027-02-28'],
    ['2028-02-29', 1, 'Y', 1, '2029-02-28'],
    ['2028-02-29', 1, 'Y', -1, '2027-02-28'],
    ['2028-02-29', 4, 'Y', 1, '2032-02-29'],
    ['2026-12-31', 12, 'M', 1, '2027-12-31'],
    ['2026-10-16', 2, 'W', 1, '2026-10-30'],
    ['2026-03-01', 1, 'D', -1, '2026-02-28'],
]);

test('bad input is refused', function (float $old, string $oldPeriod, string $expires, float $new, string $newPeriod, string $now): void {
    expect(fn () => prorate($old, $oldPeriod, $expires, $new, $newPeriod, $now))->toThrow(InformationException::class);
})->with([
    'negative old price' => [-1, '1M', '2026-11-16 00:00:00', 20, '1M', '2026-11-01 00:00:00'],
    'negative new price' => [10, '1M', '2026-11-16 00:00:00', -20, '1M', '2026-11-01 00:00:00'],
    'unknown period unit' => [10, '1X', '2026-11-16 00:00:00', 20, '1M', '2026-11-01 00:00:00'],
    'zero period' => [10, '0M', '2026-11-16 00:00:00', 20, '1M', '2026-11-01 00:00:00'],
    'empty new period' => [10, '1M', '2026-11-16 00:00:00', 20, '', '2026-11-01 00:00:00'],
    'impossible expiry date' => [10, '1M', '2026-02-30 00:00:00', 20, '1M', '2026-02-01 00:00:00'],
    'garbage date' => [10, '1M', 'soon', 20, '1M', '2026-11-01 00:00:00'],
]);

test('period codes are read in any case and with spaces', function (): void {
    expect(Proration::parsePeriod(' 1y '))->toBe([1, 'Y'])
        ->and(Proration::parsePeriod('12M'))->toBe([12, 'M'])
        ->and(Proration::parsePeriod('30D'))->toBe([30, 'D']);
});

test('a period begun on the 29th-31st counts from that day, its periods walked from the anchor (QA r10 D-01)', function (?string $activated, string $expires, string $now, array $expected, string $period = '1M'): void {
    expect(Proration::quote(30, $period, $expires, 10, $period, $now, null, $activated))->toMatchArray($expected);
})->with([
    // strtotime(): 31 January + 1 month = 3 March.
    'activated 31 January, on that day' => ['2027-01-31 10:00:00', '2027-03-03 10:00:00', '2027-01-31 11:00:00', ['period_start' => '2027-01-31', 'days_total' => 31, 'days_left' => 31, 'credit' => 30.0, 'charge' => 10.0, 'amount' => -20.0]],
    'activated 31 January, on 10 February' => ['2027-01-31 10:00:00', '2027-03-03 10:00:00', '2027-02-10 11:00:00', ['period_start' => '2027-01-31', 'days_total' => 31, 'days_left' => 21, 'credit' => 20.32, 'charge' => 6.77, 'amount' => -13.55]],
    'activated 29 February: + 1 year is 1 March' => ['2028-02-29 10:00:00', '2029-03-01 10:00:00', '2029-02-01 11:00:00', ['period_start' => '2028-02-29', 'days_total' => 366], '1Y'],
    // 31 August + 1 month is 1 October too.
    'activated on the 1st' => ['2026-09-01 10:00:00', '2026-10-01 10:00:00', '2026-09-11 11:00:00', ['period_start' => '2026-09-01', 'days_total' => 30]],
    'activation unknown: the clamped month' => [null, '2027-03-03 10:00:00', '2027-02-10 11:00:00', ['period_start' => '2027-02-03', 'days_total' => 28]],
    // Activated 31 August: 1 October, 1 November, 1 December; that last period is November's 30 days.
    'a later period of a service activated on the 31st' => ['2026-08-31 10:00:00', '2026-12-01 10:00:00', '2026-11-11 11:00:00', ['period_start' => '2026-11-01', 'days_total' => 30, 'days_left' => 20, 'credit' => 20.0]],
    'the walk misses the expiry (moved by staff): the clamped month' => ['2027-01-31 10:00:00', '2027-03-05 10:00:00', '2027-02-10 11:00:00', ['period_start' => '2027-02-05', 'days_total' => 28]],
]);
