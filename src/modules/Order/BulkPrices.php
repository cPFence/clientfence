<?php

declare(strict_types=1);

namespace ClientFence\Mod\Order;

use ClientFence\InformationException;
use Doctrine\DBAL\Connection;

/**
 * ClientFence: Orders > Bulk price update, as WHMCS's Bulk Pricing Updater add-on.
 *
 * Changes the recurring price of existing services: the orders of the chosen products, billing
 * periods, statuses and currency (and, optionally, only those at one current price) get a new
 * fixed price, an amount added to their price, or their product's current price for their period
 * (in the order's currency; a service's configurable options stay on top, at the prices it was
 * sold with: Product/Options.php). Staff preview the matches first; applying recomputes them and refuses
 * when anything changed since the preview (fingerprint()). Each changed order gets a line in its
 * status history, and staff may email the clients their old and new prices
 * (mod_order_price_changed, one email per client).
 *
 * As WHMCS, the new price starts with the next invoice: unpaid invoices already issued keep the
 * old amount (the preview counts them), and so do gateway subscriptions with a fixed amount.
 * Domains are left out: their renewals are priced from the TLD price, not the order
 * (Invoice\Service::generateForOrder()). Orders without a billing period never renew.
 */
final class BulkPrices
{
    public const MODE_PRICE = 'price';
    public const MODE_INCREASE = 'increase';
    public const MODE_PRODUCT = 'product';
    public const MODES = [self::MODE_PRICE, self::MODE_INCREASE, self::MODE_PRODUCT];

    /** Order statuses that can be updated; the first two are ticked by default. */
    public const STATUSES = ['active', 'suspended', 'pending_setup', 'failed_setup', 'failed_renew'];
    public const DEFAULT_STATUSES = ['active', 'suspended'];

    /** Product kinds whose renewals use the order's price (domains do not). */
    public const KINDS = ['hosting', 'license', 'custom', 'membership', 'downloadable'];

    /** Rows a skipped order can carry. */
    public const SKIP_NO_PRODUCT_PRICE = 'no_product_price';
    public const SKIP_BELOW_ZERO = 'below_zero';

    public const MAX_PRICE = 1000000;

    /**
     * What the form offers: the products that can be updated (with how many orders each has),
     * the billing periods in use, the statuses and the currencies.
     *
     * @return array{products: list<array{id: int, title: string, is_addon: bool, orders: int}>, periods: list<string>, statuses: list<string>, default_statuses: list<string>, currencies: list<string>, default_currency: string}
     */
    public static function options(\Pimple\Container $di): array
    {
        $db = self::db($di);
        $kinds = self::quoteList($db, self::KINDS);
        $statuses = self::quoteList($db, self::STATUSES);
        $products = $db->fetchAllAssociative(
            "SELECT p.id, p.title, p.is_addon, COUNT(o.id) AS orders FROM product p LEFT JOIN client_order o ON o.product_id = p.id AND o.status IN ({$statuses}) AND o.period IS NOT NULL AND o.period <> ''"
            . " WHERE p.type IN ({$kinds}) GROUP BY p.id, p.title, p.is_addon ORDER BY p.is_addon, p.title, p.id"
        );
        $periods = $db->fetchFirstColumn(
            "SELECT DISTINCT o.period FROM client_order o JOIN product p ON p.id = o.product_id WHERE p.type IN ({$kinds}) AND o.period IS NOT NULL AND o.period <> ''"
        );
        $order = array_flip(array_values(Upgrades::PERIODS));
        usort($periods, static fn ($a, $b): int => [$order[$a] ?? 99, $a] <=> [$order[$b] ?? 99, $b]);
        $currencies = $db->fetchAllAssociative('SELECT code, is_default FROM currency ORDER BY is_default DESC, code');

        return [
            'products' => array_map(static fn (array $p): array => [
                'id' => (int) $p['id'],
                'title' => (string) $p['title'],
                'is_addon' => (bool) (int) $p['is_addon'],
                'orders' => (int) $p['orders'],
            ], $products),
            'periods' => array_map('strval', $periods),
            'statuses' => self::STATUSES,
            'default_statuses' => self::DEFAULT_STATUSES,
            'currencies' => array_map(static fn (array $c): string => (string) $c['code'], $currencies),
            'default_currency' => (string) ($currencies[0]['code'] ?? ''),
        ];
    }

    /**
     * The checked filters and price change.
     *
     * @param array<string, mixed> $data
     *
     * @return array{product_ids: list<int>, periods: list<string>, statuses: list<string>, currency: string, current_price: ?float, mode: string, amount: ?float}
     */
    public static function filters(\Pimple\Container $di, array $data): array
    {
        $db = self::db($di);
        $productIds = array_values(array_unique(array_filter(array_map('intval', self::listOf($data['product_ids'] ?? [])), static fn (int $id): bool => $id > 0)));
        if ($productIds === []) {
            throw new InformationException('Choose at least one product.');
        }
        $known = array_map('intval', $db->fetchFirstColumn(
            'SELECT id FROM product WHERE type IN (' . self::quoteList($db, self::KINDS) . ') AND id IN (' . implode(',', $productIds) . ')'
        ));
        if (count($known) !== count($productIds)) {
            throw new InformationException('Unknown product, or a product whose renewals do not use the order price (domains).');
        }
        sort($productIds);

        $periods = array_values(array_unique(array_map('strval', self::listOf($data['periods'] ?? []))));
        foreach ($periods as $period) {
            if (preg_match('/^[1-9][0-9]{0,2}[DWMY]$/', $period) !== 1) {
                throw new InformationException('Unknown billing period.');
            }
        }
        sort($periods);

        $statuses = array_values(array_intersect(self::STATUSES, array_map('strval', self::listOf($data['statuses'] ?? []))));
        if ($statuses === []) {
            throw new InformationException('Choose at least one order status.');
        }

        $currency = strtoupper(trim((string) ($data['currency'] ?? '')));
        if ($currency === '' || $db->fetchOne('SELECT 1 FROM currency WHERE code = ?', [$currency]) === false) {
            throw new InformationException('Unknown currency.');
        }

        $currentPrice = trim((string) ($data['current_price'] ?? ''));
        $currentPrice = $currentPrice === '' ? null : self::money($currentPrice, 'current price', false);

        $mode = (string) ($data['mode'] ?? '');
        if (!in_array($mode, self::MODES, true)) {
            throw new InformationException('Choose how to set the new price.');
        }
        $amount = null;
        if ($mode === self::MODE_PRICE) {
            $amount = self::money((string) ($data['amount'] ?? ''), 'new price', false);
        } elseif ($mode === self::MODE_INCREASE) {
            $amount = self::money((string) ($data['amount'] ?? ''), 'amount to add', true);
            if ($amount == 0.0) {
                throw new InformationException('Enter an amount to add other than 0 (a negative amount lowers the price).');
            }
        }

        return [
            'product_ids' => $productIds,
            'periods' => $periods,
            'statuses' => $statuses,
            'currency' => $currency,
            'current_price' => $currentPrice,
            'mode' => $mode,
            'amount' => $amount,
        ];
    }

    /**
     * The matched orders with their old and new prices, and what the update leaves as it is.
     *
     * @param array{product_ids: list<int>, periods: list<string>, statuses: list<string>, currency: string, current_price: ?float, mode: string, amount: ?float} $filters
     *
     * @return array{rows: list<array<string, mixed>>, matched: int, change: int, unchanged: int, skipped: int, unpaid_invoices: int, subscriptions: int, clients: int, fingerprint: string}
     */
    public static function preview(\Pimple\Container $di, array $filters): array
    {
        $db = self::db($di);
        $sql = 'SELECT o.id, o.client_id, o.product_id, o.title, o.period, o.currency, o.price, o.quantity, o.status, o.expires_at, o.unpaid_invoice_id,'
            . ' o.promo_recurring, o.discount, o.config, c.first_name, c.last_name, c.company, p.title AS product_title,'
            . " (SELECT i.id FROM invoice i WHERE i.id = o.unpaid_invoice_id AND i.status = 'unpaid') AS unpaid_invoice"
            . ' FROM client_order o JOIN product p ON p.id = o.product_id LEFT JOIN client c ON c.id = o.client_id'
            . ' WHERE o.product_id IN (' . implode(',', $filters['product_ids']) . ')'
            . ' AND p.type IN (' . self::quoteList($db, self::KINDS) . ')'
            . ' AND o.status IN (' . self::quoteList($db, $filters['statuses']) . ')'
            . " AND o.period IS NOT NULL AND o.period <> '' AND o.currency = ?";
        $params = [$filters['currency']];
        if ($filters['periods'] !== []) {
            $sql .= ' AND o.period IN (' . self::quoteList($db, $filters['periods']) . ')';
        }
        if ($filters['current_price'] !== null) {
            $sql .= ' AND ABS(o.price - ?) < 0.005';
            $params[] = number_format($filters['current_price'], 2, '.', '');
        }
        $orders = $db->fetchAllAssociative($sql . ' ORDER BY p.title, o.period, o.id', $params);

        $subscribed = [];
        if ($orders !== []) {
            $subscribed = array_flip(array_map('intval', $db->fetchFirstColumn(
                "SELECT DISTINCT ii.rel_id FROM subscription s JOIN invoice_item ii ON ii.invoice_id = s.rel_id AND ii.type = 'order'"
                . " WHERE s.rel_type = 'invoice' AND s.status = 'active' AND ii.rel_id IN (" . implode(',', array_map(static fn (array $o): int => (int) $o['id'], $orders)) . ')'
            )));
        }

        $rate = null;
        $catalog = [];
        $rows = [];
        $counts = ['change' => 0, 'unchanged' => 0, 'skipped' => 0, 'unpaid_invoices' => 0, 'subscriptions' => 0];
        $clients = [];
        foreach ($orders as $order) {
            $old = round((float) $order['price'], 2);
            $new = null;
            $skip = null;
            if ($filters['mode'] === self::MODE_PRICE) {
                $new = (float) $filters['amount'];
            } elseif ($filters['mode'] === self::MODE_INCREASE) {
                $new = round($old + (float) $filters['amount'], 2);
                if ($new < 0) {
                    [$new, $skip] = [null, self::SKIP_BELOW_ZERO];
                }
            } else {
                $productId = (int) $order['product_id'];
                // ClientFence: in the order's currency, with its fixed prices (Product/CurrencyPrices.php).
                $catalog[$productId] ??= Upgrades::catalog($db, $productId, false, (string) $filters['currency']) ?? [];
                $rate ??= self::rate($db, $filters['currency']);
                $base = $catalog[$productId]['prices'][(string) $order['period']] ?? null;
                if ($base === null || $rate === null) {
                    $skip = self::SKIP_NO_PRODUCT_PRICE;
                } else {
                    // ClientFence: the service's configurable options as sold (default currency).
                    $options = 0.0;
                    $config = json_decode((string) $order['config'], true);
                    foreach (is_array($config['option_lines'] ?? null) ? $config['option_lines'] : [] as $line) {
                        $options += is_array($line) ? (float) ($line['price'] ?? 0) : 0.0;
                    }
                    $new = round(($base + $options) * $rate, 2);
                }
            }

            $state = $skip !== null ? 'skipped' : (abs($new - $old) < 0.005 ? 'unchanged' : 'change');
            ++$counts[$state];
            $unpaid = $order['unpaid_invoice'] !== null ? (int) $order['unpaid_invoice'] : null;
            $subscription = isset($subscribed[(int) $order['id']]);
            if ($state === 'change') {
                $clients[(int) $order['client_id']] = true;
                $counts['unpaid_invoices'] += $unpaid !== null ? 1 : 0;
                $counts['subscriptions'] += $subscription ? 1 : 0;
            }
            $rows[] = [
                'id' => (int) $order['id'],
                'client_id' => (int) $order['client_id'],
                'client' => trim($order['first_name'] . ' ' . $order['last_name']) ?: (string) $order['company'],
                'title' => (string) $order['title'],
                'product_title' => (string) $order['product_title'],
                'period' => (string) $order['period'],
                'status' => (string) $order['status'],
                'quantity' => max(1, (int) $order['quantity']),
                'currency' => (string) $order['currency'],
                'expires_at' => $order['expires_at'] !== null ? (string) $order['expires_at'] : null,
                'old_price' => $old,
                'new_price' => $new,
                'state' => $state,
                'skip' => $skip,
                'unpaid_invoice_id' => $unpaid,
                'subscription' => $subscription,
                'recurring_discount' => (int) $order['promo_recurring'] === 1 ? round((float) $order['discount'], 2) : 0.0,
            ];
        }

        return [
            'rows' => $rows,
            'matched' => count($rows),
            'change' => $counts['change'],
            'unchanged' => $counts['unchanged'],
            'skipped' => $counts['skipped'],
            'unpaid_invoices' => $counts['unpaid_invoices'],
            'subscriptions' => $counts['subscriptions'],
            'clients' => count($clients),
            'fingerprint' => self::fingerprint($rows),
        ];
    }

    /**
     * Applies a previewed update. The orders are checked again: if anything the preview showed
     * changed, nothing is applied.
     *
     * @param array{product_ids: list<int>, periods: list<string>, statuses: list<string>, currency: string, current_price: ?float, mode: string, amount: ?float} $filters
     *
     * @return array{updated: int, emailed: int, unpaid_invoices: int, subscriptions: int}
     */
    public static function apply(\Pimple\Container $di, array $filters, string $fingerprint, bool $email, ?int $adminId): array
    {
        $db = self::db($di);
        $now = date('Y-m-d H:i:s');
        $by = $adminId !== null ? sprintf(' (staff #%d)', $adminId) : '';
        $db->beginTransaction();
        try {
            // Lock the matched orders, then price them again under the lock.
            $ids = array_map(static fn (array $r): int => $r['id'], self::preview($di, $filters)['rows']);
            if ($ids !== []) {
                $db->fetchFirstColumn('SELECT id FROM client_order WHERE id IN (' . implode(',', $ids) . ')' . (self::isMysql($db) ? ' FOR UPDATE' : ''));
            }
            $preview = self::preview($di, $filters);
            if ($fingerprint === '' || !hash_equals($preview['fingerprint'], $fingerprint)) {
                throw new InformationException('The matching services changed since the preview. Preview again before applying.');
            }
            if ($preview['change'] === 0) {
                throw new InformationException('No service gets a new price.');
            }
            $changed = array_values(array_filter($preview['rows'], static fn (array $r): bool => $r['state'] === 'change'));
            foreach ($changed as $row) {
                $updated = $db->executeStatement(
                    'UPDATE client_order SET price = ?, updated_at = ? WHERE id = ? AND ABS(price - ?) < 0.005',
                    [number_format((float) $row['new_price'], 2, '.', ''), $now, $row['id'], number_format($row['old_price'], 2, '.', '')]
                );
                if ($updated !== 1) {
                    throw new InformationException(sprintf('Order #%d changed while applying. Nothing was changed; preview again.', $row['id']));
                }
                $db->insert('client_order_status', [
                    'client_order_id' => $row['id'],
                    'status' => $row['status'],
                    'notes' => sprintf('Recurring price changed from %s to %s %s by a bulk price update%s.', self::format($row['old_price']), self::format((float) $row['new_price']), $row['currency'], $by),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }

            throw $e;
        }

        self::log($di, sprintf('changed the recurring price of %d orders%s: %s', count($changed), $by, implode(', ', array_map(static fn (array $r): string => sprintf('#%d %s→%s', $r['id'], self::format($r['old_price']), self::format((float) $r['new_price'])), $changed))));

        return [
            'updated' => count($changed),
            'emailed' => $email ? self::emailClients($di, $changed) : 0,
            'unpaid_invoices' => $preview['unpaid_invoices'],
            'subscriptions' => $preview['subscriptions'],
        ];
    }

    /**
     * One email per client with its changed services. Returns how many were sent.
     *
     * @param list<array<string, mixed>> $changed
     */
    public static function emailClients(\Pimple\Container $di, array $changed): int
    {
        $byClient = [];
        foreach ($changed as $row) {
            $byClient[(int) $row['client_id']][] = [
                'id' => $row['id'],
                'title' => $row['title'],
                'period' => $row['period'],
                'quantity' => $row['quantity'],
                'currency' => $row['currency'],
                'old_price' => $row['old_price'],
                'new_price' => $row['new_price'],
                // What the client pays: a recurring promo's fixed discount still comes off each
                // renewal, capped at the total (Product\Service::getRenewalPromoAdjustment()).
                'old_total' => max(0.0, round($row['old_price'] * $row['quantity'] - $row['recurring_discount'], 2)),
                'new_total' => max(0.0, round((float) $row['new_price'] * $row['quantity'] - $row['recurring_discount'], 2)),
                'discount' => $row['recurring_discount'],
                'next_invoice' => $row['unpaid_invoice_id'] === null ? $row['expires_at'] : null,
                'unpaid_invoice_id' => $row['unpaid_invoice_id'],
            ];
        }
        $email = $di['mod_service']('email');
        $sent = 0;
        foreach ($byClient as $clientId => $services) {
            try {
                $email->sendTemplate([
                    'code' => 'mod_order_price_changed',
                    'to_client' => $clientId,
                    'services' => $services,
                ]);
                ++$sent;
            } catch (\Throwable $e) {
                self::log($di, sprintf('the price change email to client #%d failed: %s', $clientId, $e->getMessage()));
            }
        }

        return $sent;
    }

    /**
     * What the preview showed that applying depends on: every matched order with its state and
     * old and new price.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function fingerprint(array $rows): string
    {
        $parts = array_map(static fn (array $r): string => implode(':', [
            $r['id'], $r['state'], number_format((float) $r['old_price'], 2, '.', ''),
            $r['new_price'] === null ? '-' : number_format((float) $r['new_price'], 2, '.', ''),
        ]), $rows);

        return hash('sha256', implode('|', $parts));
    }

    /** @return list<mixed> */
    private static function listOf(mixed $value): array
    {
        if (is_string($value)) {
            $value = $value === '' ? [] : explode(',', $value);
        }

        return is_array($value) ? array_values($value) : [];
    }

    private static function money(string $value, string $name, bool $signed): float
    {
        $value = str_replace(' ', '', trim($value));
        if (preg_match($signed ? '/^-?[0-9]+(\.[0-9]{1,2})?$/' : '/^[0-9]+(\.[0-9]{1,2})?$/', $value) !== 1) {
            throw new InformationException(sprintf('Enter the %s as a number with up to 2 decimals.', $name));
        }
        $number = round((float) $value, 2);
        if (abs($number) > self::MAX_PRICE) {
            throw new InformationException(sprintf('The %s is too large.', $name));
        }

        return $number;
    }

    private static function format(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /** @param list<string> $values */
    private static function quoteList(Connection $db, array $values): string
    {
        return implode(',', array_map(static fn (string $v): string => (string) $db->quote($v), $values));
    }

    private static function rate(Connection $db, string $currency): ?float
    {
        $rate = $db->fetchOne('SELECT conversion_rate FROM currency WHERE code = ?', [$currency]);

        return $rate === false || (float) $rate <= 0 ? null : (float) $rate;
    }

    private static function isMysql(Connection $db): bool
    {
        return $db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
    }

    private static function log(\Pimple\Container $di, string $message): void
    {
        try {
            $di['logger']->info('Bulk price update: %s', $message);
        } catch (\Throwable) {
            // Logging never stops an update.
        }
    }

    private static function db(\Pimple\Container $di): Connection
    {
        return $di['em']->getConnection();
    }
}
