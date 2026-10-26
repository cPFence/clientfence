<?php

declare(strict_types=1);

namespace ClientFence\Mod\Product;

use ClientFence\InformationException;
use Doctrine\DBAL\Connection;

/**
 * ClientFence: fixed prices in other currencies, as WHMCS and Blesta have them. A product (or an
 * add-on) may have its own price and setup fee per billing period in a currency other than the
 * default one, a TLD its own registration, renewal and transfer price (per year). Whatever is not
 * set is converted from the default currency at the currency's rate, as before. Configurable
 * options always convert.
 *
 * The price code works in the default currency and converts at the end (price × rate). A fixed
 * price joins it as its default-currency equivalent (fixed ÷ rate, unrounded), so every later step
 * (the cart, promotions, the order and its invoice, the store pages, upgrades) lands on the fixed
 * price whatever the rate is at that moment. Product\Service::inCurrency() prices products and
 * TLDs in a currency; Order\Upgrades::catalog() takes the order's currency.
 *
 * Rows: product_currency_price (kind, ref_id, currency, period, price, setup_price); a blank price
 * or setup fee (null) converts. Reads never fail: before the table exists everything converts.
 */
final class CurrencyPrices
{
    public const TABLE = 'product_currency_price';
    /** The periods of each kind: a product's billing periods ("once" for a one-time price), a TLD's actions. */
    public const KINDS = [
        'product' => ['once', '1W', '1M', '3M', '6M', '1Y', '2Y', '3Y'],
        'tld' => ['register', 'renew', 'transfer'],
    ];
    public const MAX_PRICE = 999999999.99;

    /** @var array<string, array{default: string, rates: array<string, float>, fixed: array<string, bool>}> per connection */
    private static array $currencies = [];
    /** @var array<string, array<string, array{price: ?float, setup_price: ?float}>> */
    private static array $rows = [];

    /**
     * The rate of a currency that has fixed prices, or null when its prices convert: the default
     * currency, an unknown one, or one without any fixed price.
     */
    public static function rate(Connection $db, ?string $currency): ?float
    {
        $currency = strtoupper(trim((string) $currency));
        if ($currency === '') {
            return null;
        }
        $known = self::currencies($db);
        if ($currency === $known['default'] || !isset($known['fixed'][$currency]) || ($known['rates'][$currency] ?? 0.0) <= 0) {
            return null;
        }

        return $known['rates'][$currency];
    }

    /**
     * An amount in the default currency, or the default-currency equivalent of the fixed price of
     * $kind $refId for $period ($field: price or setup_price) in $currency when one is set.
     */
    public static function base(Connection $db, string $kind, int $refId, ?string $currency, string $period, string $field, float $base): float
    {
        $rate = self::rate($db, $currency);
        if ($rate === null) {
            return $base;
        }
        $fixed = self::forItem($db, $kind, $refId, (string) $currency)[$period][$field] ?? null;

        return $fixed === null ? $base : $fixed / $rate;
    }

    /**
     * A product's API pricing (Product\Service::toProductPaymentApiArray()) with the fixed prices
     * of $currency as their default-currency equivalents.
     *
     * @param array<string, mixed> $pricing
     *
     * @return array<string, mixed>
     */
    public static function pricing(Connection $db, int $productId, ?string $currency, array $pricing): array
    {
        if (self::rate($db, $currency) === null) {
            return $pricing;
        }
        if (is_array($pricing['once'] ?? null)) {
            $pricing['once']['price'] = self::base($db, 'product', $productId, $currency, 'once', 'price', (float) ($pricing['once']['price'] ?? 0));
            $pricing['once']['setup'] = self::base($db, 'product', $productId, $currency, 'once', 'setup_price', (float) ($pricing['once']['setup'] ?? 0));
        }
        if (is_array($pricing['recurrent'] ?? null)) {
            foreach ($pricing['recurrent'] as $period => $row) {
                if (is_array($row) && in_array((string) $period, self::KINDS['product'], true)) {
                    $pricing['recurrent'][$period]['price'] = self::base($db, 'product', $productId, $currency, (string) $period, 'price', (float) ($row['price'] ?? 0));
                    $pricing['recurrent'][$period]['setup'] = self::base($db, 'product', $productId, $currency, (string) $period, 'setup_price', (float) ($row['setup'] ?? 0));
                }
            }
        }

        return $pricing;
    }

    /**
     * A TLD's API array (price_registration, price_renew, price_transfer) with the fixed prices of
     * $currency as their default-currency equivalents.
     *
     * @param array<string, mixed> $tld with `id`
     *
     * @return array<string, mixed>
     */
    public static function tld(Connection $db, ?string $currency, array $tld): array
    {
        if (self::rate($db, $currency) === null || !isset($tld['id'])) {
            return $tld;
        }
        foreach (['price_registration' => 'register', 'price_renew' => 'renew', 'price_transfer' => 'transfer'] as $field => $period) {
            if (array_key_exists($field, $tld)) {
                $tld[$field] = self::base($db, 'tld', (int) $tld['id'], $currency, $period, 'price', (float) $tld[$field]);
            }
        }

        return $tld;
    }

    /**
     * The fixed prices of an item in one currency, by period.
     *
     * @return array<string, array{price: ?float, setup_price: ?float}>
     */
    public static function forItem(Connection $db, string $kind, int $refId, string $currency): array
    {
        $key = self::dbKey($db) . "|{$kind}|{$refId}|" . strtoupper($currency);
        if (!isset(self::$rows[$key])) {
            $out = [];
            try {
                $rows = $db->fetchAllAssociative('SELECT period, price, setup_price FROM ' . self::TABLE . ' WHERE kind = ? AND ref_id = ? AND currency = ?', [$kind, $refId, strtoupper($currency)]);
            } catch (\Throwable) {
                $rows = [];
            }
            foreach ($rows as $row) {
                $out[(string) $row['period']] = [
                    'price' => $row['price'] === null ? null : (float) $row['price'],
                    'setup_price' => $row['setup_price'] === null ? null : (float) $row['setup_price'],
                ];
            }
            self::$rows[$key] = $out;
        }

        return self::$rows[$key];
    }

    /**
     * Staff view of an item's fixed prices: every currency but the default one, with its rate and
     * its rows by period (blank where it converts).
     *
     * @return array{default: string, currencies: list<array{code: string, rate: float, prices: array<string, array{price: ?float, setup_price: ?float}>}>}
     */
    public static function page(Connection $db, string $kind, int $refId): array
    {
        self::kind($kind);
        $known = self::currencies($db, true);
        $out = [];
        foreach ($known['rates'] as $code => $rate) {
            if ($code !== $known['default']) {
                $out[] = ['code' => $code, 'rate' => $rate, 'prices' => self::forItem($db, $kind, $refId, $code)];
            }
        }

        return ['default' => $known['default'], 'currencies' => $out];
    }

    /**
     * Replaces an item's fixed prices: $prices[currency][period] = {price, setup_price} (blank or
     * missing: converts). A currency left out keeps its rows.
     *
     * @param array<mixed> $prices
     */
    public static function save(Connection $db, string $kind, int $refId, array $prices): void
    {
        $periods = self::kind($kind);
        if ($refId <= 0) {
            throw new InformationException('Unknown item.');
        }
        $known = self::currencies($db, true);
        $now = date('Y-m-d H:i:s');
        $db->transactional(function (Connection $db) use ($prices, $known, $periods, $kind, $refId, $now): void {
            foreach ($prices as $code => $rows) {
                $code = strtoupper(trim((string) $code));
                if (!isset($known['rates'][$code]) || $code === $known['default']) {
                    throw new InformationException('Fixed prices can only be set for a currency other than the default one.');
                }
                if (!is_array($rows)) {
                    continue;
                }
                $db->delete(self::TABLE, ['kind' => $kind, 'ref_id' => $refId, 'currency' => $code]);
                foreach ($rows as $period => $row) {
                    if (!in_array((string) $period, $periods, true)) {
                        throw new InformationException('Unknown billing period :period', [':period' => (string) $period]);
                    }
                    $row = is_array($row) ? $row : [];
                    $price = self::amount($row['price'] ?? null);
                    $setup = $kind === 'tld' ? null : self::amount($row['setup_price'] ?? null);
                    if ($price === null && $setup === null) {
                        continue;
                    }
                    $db->insert(self::TABLE, [
                        'kind' => $kind, 'ref_id' => $refId, 'currency' => $code, 'period' => (string) $period,
                        'price' => $price, 'setup_price' => $setup, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        });
        self::reset();
    }

    /** Drops an item's fixed prices (the item was deleted). */
    public static function forget(Connection $db, string $kind, int $refId): void
    {
        try {
            $db->delete(self::TABLE, ['kind' => $kind, 'ref_id' => $refId]);
        } catch (\Throwable) {
            // No table yet: nothing to drop.
        }
        self::reset();
    }

    /** forget() on the install's database; a failure leaves rows nothing reads any more. */
    public static function forgetIn(\Pimple\Container $di, string $kind, int $refId): void
    {
        try {
            self::forget($di['em']->getConnection(), $kind, $refId);
        } catch (\Throwable) {
            // No database here (a unit test): nothing to drop.
        }
    }

    /** forgetCurrency() on the install's database; a failure leaves rows nothing reads any more. */
    public static function forgetCurrencyIn(\Pimple\Container $di, string $currency): void
    {
        try {
            self::forgetCurrency($di['em']->getConnection(), $currency);
        } catch (\Throwable) {
            // No database here (a unit test): nothing to drop.
        }
    }

    /** Drops a currency's fixed prices (the currency was deleted). */
    public static function forgetCurrency(Connection $db, string $currency): void
    {
        try {
            $db->delete(self::TABLE, ['currency' => strtoupper($currency)]);
        } catch (\Throwable) {
            // No table yet: nothing to drop.
        }
        self::reset();
    }

    /** Creates the table on an install that predates it (API layer: staff pages and saves). */
    public static function ensure(\Pimple\Container $di): void
    {
        /** @var \WeakMap<\Pimple\Container, true>|null $ready */
        static $ready = null;
        $ready ??= new \WeakMap();
        if (!isset($ready[$di])) {
            \ClientFence\Mod\Clientfence\Service::ensureTables($di, true);
            $ready[$di] = true;
        }
    }

    /** Forgets what was read (after a change, and between tests). */
    public static function reset(): void
    {
        self::$currencies = [];
        self::$rows = [];
    }

    /** @return list<string> */
    private static function kind(string $kind): array
    {
        if (!isset(self::KINDS[$kind])) {
            throw new InformationException('Unknown item.');
        }

        return self::KINDS[$kind];
    }

    private static function amount(mixed $value): ?float
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $value = is_string($value) ? str_replace(',', '.', trim($value)) : $value;
        if (!is_numeric($value) || (float) $value < 0 || (float) $value > self::MAX_PRICE) {
            throw new InformationException('Enter a price of 0 or more, or leave it blank to convert at the rate.');
        }

        return round((float) $value, 2);
    }

    /**
     * The currencies (code => rate), the default one, and those with at least one fixed price.
     *
     * @return array{default: string, rates: array<string, float>, fixed: array<string, bool>}
     */
    private static function currencies(Connection $db, bool $fresh = false): array
    {
        $key = self::dbKey($db);
        if ($fresh || !isset(self::$currencies[$key])) {
            $known = ['default' => '', 'rates' => [], 'fixed' => []];
            try {
                foreach ($db->fetchAllAssociative('SELECT code, conversion_rate, is_default FROM currency ORDER BY id') as $row) {
                    $code = strtoupper((string) $row['code']);
                    $known['rates'][$code] = (float) $row['conversion_rate'];
                    if ((int) $row['is_default'] === 1) {
                        $known['default'] = $code;
                    }
                }
            } catch (\Throwable) {
                // No currencies: everything converts.
            }
            try {
                foreach ($db->fetchFirstColumn('SELECT DISTINCT currency FROM ' . self::TABLE) as $code) {
                    $known['fixed'][strtoupper((string) $code)] = true;
                }
            } catch (\Throwable) {
                // No table yet: nothing is fixed.
            }
            self::$currencies[$key] = $known;
        }

        return self::$currencies[$key];
    }

    private static function dbKey(Connection $db): string
    {
        return (string) spl_object_id($db);
    }
}
