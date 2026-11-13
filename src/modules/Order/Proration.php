<?php

declare(strict_types=1);

namespace ClientFence\Mod\Order;

use ClientFence\InformationException;

/**
 * ClientFence: the price of an upgrade or downgrade, as WHMCS prorates it (Upgrades.php). Pure:
 * no database, so every case can be tested.
 *
 * The current period runs from one billing period before the expiry date to the expiry date;
 * months and years keep the day of the month, clamped to the month's last day (a monthly period
 * ending 31 March started on 28 or 29 February; see shift()). Days count by calendar date, so the
 * time of day never matters.
 *
 * - Unused credit: the order's price × days left ÷ days in the period.
 * - Same billing cycle: the new price for the days left, minus the credit; the expiry date stays.
 * - Another billing cycle: the new price for a whole new cycle, minus the credit; the new cycle
 *   starts today (the expiry date becomes today + the new cycle).
 * - Amount > 0: the client pays it (an invoice); amount < 0: the client gets it as account credit.
 *
 * Upgrade or downgrade: the new product's price against the current product's price for the same
 * (new) cycle when the caller knows it, else the price per day of each (the new price over its
 * cycle against the order's price over its cycle). Equal prices (the same product on another
 * cycle) count as an upgrade when the client pays now and a downgrade when the client is credited.
 * Amounts are rounded to cents (half up) one by one, then subtracted.
 */
final class Proration
{
    public const UPGRADE = 'upgrade';
    public const DOWNGRADE = 'downgrade';

    /** Average days per unit, only to compare prices of different cycles. */
    private const UNIT_DAYS = ['D' => 1.0, 'W' => 7.0, 'M' => 30.436875, 'Y' => 365.2425];

    /**
     * @return array{kind: string, same_cycle: bool, days_total: int, days_left: int, credit: float, charge: float, amount: float, period_start: string, expires_at: string, new_expires_at: string}
     */
    public static function quote(float $oldPrice, string $oldPeriod, string $expiresAt, float $newPrice, string $newPeriod, string $now, ?float $currentProductNewCyclePrice = null, ?string $anchor = null, bool $keepDay = false): array
    {
        if ($oldPrice < 0 || $newPrice < 0 || !is_finite($oldPrice) || !is_finite($newPrice) || ($currentProductNewCyclePrice !== null && ($currentProductNewCyclePrice < 0 || !is_finite($currentProductNewCyclePrice)))) {
            throw new InformationException('Prices cannot be negative.');
        }
        [$oldQty, $oldUnit] = self::parsePeriod($oldPeriod);
        [$newQty, $newUnit] = self::parsePeriod($newPeriod);
        $expiry = self::day($expiresAt, 'expiry date');
        $today = self::day($now, 'current date');

        $start = self::start($expiry, $oldQty, $oldUnit, $anchor, $keepDay);
        $daysTotal = max(1, self::daysBetween($start, $expiry));
        // Not capped: an order paid more than one period ahead is credited for every day left.
        $daysLeft = max(0, self::daysBetween($today, $expiry));

        $sameCycle = $oldQty === $newQty && $oldUnit === $newUnit;
        $credit = self::money($oldPrice * $daysLeft / $daysTotal);
        if ($sameCycle) {
            $charge = self::money($newPrice * $daysLeft / $daysTotal);
            $newExpires = self::format(self::at($expiresAt));
        } else {
            $charge = self::money($newPrice);
            $newExpires = self::format(self::shift(self::at($now), $newQty, $newUnit, 1));
        }
        $amount = round($charge - $credit, 2);

        // Products compare on one cycle: the new price against the current product's price for the
        // new cycle when it has one (a cheaper yearly price is no downgrade), else per day.
        if ($currentProductNewCyclePrice !== null) {
            $oldRate = $currentProductNewCyclePrice;
            $newRate = $newPrice;
        } else {
            $oldRate = $oldPrice / ($oldQty * self::UNIT_DAYS[$oldUnit]);
            $newRate = $newPrice / ($newQty * self::UNIT_DAYS[$newUnit]);
        }
        $epsilon = 1e-9;
        if (abs($newRate - $oldRate) > $epsilon) {
            $kind = $newRate > $oldRate ? self::UPGRADE : self::DOWNGRADE;
        } else {
            $kind = $amount < 0 ? self::DOWNGRADE : self::UPGRADE;
        }

        return [
            'kind' => $kind,
            'same_cycle' => $sameCycle,
            'days_total' => $daysTotal,
            'days_left' => $daysLeft,
            'credit' => $credit,
            'charge' => $charge,
            'amount' => $amount == 0.0 ? 0.0 : $amount,
            'period_start' => $start->format('Y-m-d'),
            'expires_at' => self::format(self::at($expiresAt)),
            'new_expires_at' => $newExpires,
        ];
    }

    /**
     * A period code (1M, 3M, 6M, 1Y, 2Y, 1W, 30D...) as [quantity, unit].
     *
     * @return array{0: int, 1: string}
     */
    public static function parsePeriod(string $period): array
    {
        if (preg_match('/^([1-9][0-9]{0,2})([DWMY])$/', strtoupper(trim($period)), $m) !== 1) {
            throw new InformationException('The billing period :period is not valid.', [':period' => $period]);
        }

        return [(int) $m[1], $m[2]];
    }

    /**
     * Moves a date by a number of periods ($sign 1 forward, -1 back). Months and years keep the
     * day of the month, clamped to the month's last day (31 January + 1 month = 28/29 February;
     * 29 February + 1 year = 28 February).
     */
    public static function shift(\DateTimeImmutable $date, int $qty, string $unit, int $sign): \DateTimeImmutable
    {
        if ($unit === 'D' || $unit === 'W') {
            $days = $qty * ($unit === 'W' ? 7 : 1) * $sign;

            return $date->modify(($days >= 0 ? '+' : '') . $days . ' days');
        }
        $months = $qty * ($unit === 'Y' ? 12 : 1) * $sign;
        $total = (int) $date->format('Y') * 12 + (int) $date->format('n') - 1 + $months;
        $y = intdiv($total, 12);
        $m = $total % 12 + 1;
        $day = min((int) $date->format('j'), (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m)))->format('t'));

        return $date->setDate($y, $m, $day);
    }

    /** Whole days from now to the expiry date, as quote() counts the days left. */
    public static function daysLeft(string $expiresAt, string $now): int
    {
        return max(0, self::daysBetween(self::day($now, 'current date'), self::day($expiresAt, 'expiry date')));
    }

    /** The days of the period that ends on the expiry date, as quote() counts them. */
    public static function daysTotal(string $period, string $expiresAt, ?string $anchor = null, bool $keepDay = false): int
    {
        [$qty, $unit] = self::parsePeriod($period);
        $expiry = self::day($expiresAt, 'expiry date');

        return max(1, self::daysBetween(self::start($expiry, $qty, $unit, $anchor, $keepDay), $expiry));
    }

    /**
     * The first day of the period ending on the expiry date. QA r10 D-01: renewals add months as strtotime() does
     * (Period::getExpirationTime() with calendar months: 31 January + 1 month = 3 March, a period of 31 days that a clamped
     * month reads as 28), and days alone cannot tell where such a period began (31 August and 1 September + 1 month are both
     * 1 October). So the periods are walked forward from the anchor, a day a period of the service began (its
     * activation, or its last change of cycle); the clamped month is used when the walk does not reach the expiry
     * (an expiry staff moved, or set by another system).
     */
    private static function start(\DateTimeImmutable $expiry, int $qty, string $unit, ?string $anchor, bool $keepDay): \DateTimeImmutable
    {
        $start = self::shift($expiry, $qty, $unit, -1);
        if ($anchor === null || ($unit !== 'M' && $unit !== 'Y')) {
            return $start;
        }
        try {
            $from = self::day($anchor, 'period start');
        } catch (InformationException) {
            return $start;
        }
        // Order settings "Renewal Day": the walk of the current rule first (keep the anchor's day: 31 January, 28 February,
        // 31 March), then the other, as dates made before a change of rule stay.
        $kept = static function () use ($from, $expiry, $qty, $unit): ?\DateTimeImmutable {
            for ($i = 1; $i <= 1200 && ($next = self::shift($from, $qty * $i, $unit, 1)) <= $expiry; ++$i) {
                if ($next == $expiry) {
                    return self::shift($from, $qty * ($i - 1), $unit, 1);
                }
            }

            return null;
        };
        $added = static function () use ($from, $expiry, $qty, $unit): ?\DateTimeImmutable {
            $step = '+' . $qty . ($unit === 'M' ? ' months' : ' years');
            for ($i = 0, $at = $from; $i < 1200 && $at < $expiry; ++$i) {
                $next = $at->modify($step);
                if ($next == $expiry) {
                    return $at;
                }
                $at = $next;
            }

            return null;
        };

        return ($keepDay ? $kept() ?? $added() : $added() ?? $kept()) ?? $start;
    }

    private static function daysBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $from->diff($to)->format('%r%a');
    }

    private static function money(float $value): float
    {
        return round($value + 1e-9, 2);
    }

    /** The calendar day of a date and time, at midnight UTC (so DST never shifts a day count). */
    private static function day(string $value, string $what): \DateTimeImmutable
    {
        $at = self::at($value, $what);

        return new \DateTimeImmutable($at->format('Y-m-d'), new \DateTimeZone('UTC'));
    }

    private static function at(string $value, string $what = 'date'): \DateTimeImmutable
    {
        $value = trim($value);
        $at = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'))
            ?: \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if ($at === false || $at->format(strlen($value) === 10 ? 'Y-m-d' : 'Y-m-d H:i:s') !== $value) {
            throw new InformationException('The :what is not valid.', [':what' => $what]);
        }

        return $at;
    }

    private static function format(\DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }
}
