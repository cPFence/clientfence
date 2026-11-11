<?php

declare(strict_types=1);

namespace ClientFence\Mod\Servicelicense;

use ClientFence\IpSeats;
use ClientFence\Mod\Order\Entity\Order;
use ClientFence\Mod\Servicelicense\Entity\ServiceLicense;

/**
 * ClientFence: per-unit billing of licences (for example per website). The licensed software reports
 * its unit count (POST /licence/report); with the staff switch on, the order's price becomes
 * max(count × unit price, minimum) a month, times the months of its period. The product sets the
 * unit price and three protections, each with its own switch: hold big drops for staff, warn the
 * client and staff after N days without a report, suspend the order after N days without a report.
 *
 * State in the licence config, key "units": billing (staff switch), since (switched on), count,
 * at (last report), ips (last reported), held ({count, at}: a drop waiting for staff),
 * grace (last unsuspend), warned (the gap already warned about), manual (staff set the count; the
 * next report clears it).
 */
final class Units
{
    public const SECRET = 'servicelicense_report_secret';
    private const MAX_UNITS = 1000000;

    /** @return array<string, mixed> */
    public static function of(ServiceLicense $licence): array
    {
        return (json_decode($licence->getConfig() ?? '', true) ?: [])['units'] ?? [];
    }

    /** @param array<string, mixed> $units */
    public static function save(ServiceLicense $licence, array $units): void
    {
        $all = json_decode($licence->getConfig() ?? '', true) ?: [];
        $all['units'] = $units;
        $licence->setConfig((string) json_encode($all));
    }

    /**
     * The ids a WHMCS-style answer gives (WhmcsCheck), which the software sends back in its reports:
     * the WHMCS ids of an imported licence, else the order's.
     *
     * @return array{0: int, 1: int}
     */
    public static function answerIds(ServiceLicense $licence, Order $order): array
    {
        $whmcs = (json_decode($licence->getConfig() ?? '', true) ?: [])['whmcs'] ?? [];

        return [(int) ($whmcs['service_id'] ?? $order->getId()), (int) ($whmcs['product_id'] ?? $order->getProductId())];
    }

    /** Monthly price in the default currency, or null when the product has no unit price. */
    public static function monthly(array $product, int $count): ?float
    {
        if (!is_numeric($product['unit_price'] ?? null)) {
            return null;
        }

        return max($count * (float) $product['unit_price'], is_numeric($product['unit_minimum'] ?? null) ? (float) $product['unit_minimum'] : 0.0);
    }

    /**
     * A usage report: secret, service_id, product_id, units (0 to 1,000,000), ips (comma list).
     * Answers [HTTP status, text] as the WHMCS-era endpoint did: "success" or the reason.
     *
     * @param array<string, mixed> $data
     *
     * @return array{0: int, 1: string}
     */
    public static function report(\Pimple\Container $di, array $data, string $ip): array
    {
        $secret = (string) $di['dbal']->fetchOne('SELECT value FROM setting WHERE param = ?', [self::SECRET]);
        if ($secret === '' || !is_string($data['secret'] ?? null) || !hash_equals($secret, $data['secret'])) {
            return [403, 'Unauthorized request'];
        }
        $id = static fn (string $name): int|false => filter_var($data[$name] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $serviceId = $id('service_id');
        $productId = $id('product_id');
        $count = filter_var($data['units'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::MAX_UNITS]]);
        if ($serviceId === false) {
            return [400, 'Invalid service ID'];
        }
        if ($productId === false) {
            return [400, 'Invalid product ID'];
        }
        if ($count === false || !is_string($data['ips'] ?? '')) {
            return [400, 'Invalid request data'];
        }

        // Only a server already on the licence may report: the secret ships with the software.
        [$licence, $order] = self::find($di, $serviceId, $productId, $ip);
        if ($licence === null) {
            $di['logger']->setChannel('license')->warning('Usage report refused from %s for service %s', $ip, $serviceId);

            return [403, 'Unauthorized request'];
        }
        $ips = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($data['ips'] ?? ''))), static fn (string $v): bool => filter_var($v, FILTER_VALIDATE_IP) !== false)));
        if ($ips !== [] && !in_array($ip, $ips, true)) {
            // The reporting server stays on its own licence.
            $ips[] = $ip;
        }

        IpSeats::locked($di, $order, $licence, static function () use ($di, $licence, $order, $count, $ips): void {
            $service = $di['mod_service']('servicelicense');
            $product = $service->productConfig($licence);
            $units = self::of($licence);
            $units['at'] = date('Y-m-d H:i:s');
            $units['ips'] = $ips;
            $units['manual'] = false;
            $billing = !empty($units['billing']) && self::monthly($product, 0) !== null;
            $old = isset($units['count']) ? (int) $units['count'] : null;
            $limit = 1 - (int) ($product['unit_hold_percent'] ?? 50) / 100;
            if ($billing && !empty($product['unit_hold']) && $old !== null && $old > 0 && $count < $old * $limit) {
                // One notice per hold; later low reports only update the waiting count.
                $since = $units['held']['at'] ?? $units['at'];
                if (empty($units['held'])) {
                    \ClientFence\Mod\Clientfence\Notifications::staff($di, 'usage_held', ['id' => $order->getId(), 'title' => (string) $order->getTitle()], 'order/manage/' . $order->getId(), 'order', null, 'usage_held_' . $order->getId() . '_' . $since);
                }
                $units['held'] = ['count' => $count, 'at' => $since];
            } else {
                $units['count'] = $count;
                $units['held'] = null;
            }
            // The licence follows the servers the software reports, unless the product limits them (IpSeats).
            if ($billing && $ips !== [] && !$service->ipSeats($licence)->limited()) {
                $licence->setIps((string) json_encode($ips));
            }
            self::save($licence, $units);
            $di['em']->flush();
            if ($billing) {
                self::price($di, $order, $product, (int) $units['count']);
            }
        });

        return [200, 'success'];
    }

    /**
     * Staff: the switch and the count of the licence tab; a held drop is applied (approve) or dropped.
     */
    public static function update(\Pimple\Container $di, ServiceLicense $licence, Order $order, array $data): void
    {
        IpSeats::locked($di, $order, $licence, static function () use ($di, $licence, $order, $data): void {
            $units = self::of($licence);
            if (array_key_exists('billing', $data)) {
                $on = (bool) $data['billing'];
                if ($on && empty($units['billing'])) {
                    $units['since'] = date('Y-m-d H:i:s');
                }
                $units['billing'] = $on;
            }
            if (isset($data['count']) && $data['count'] !== '') {
                $count = filter_var($data['count'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::MAX_UNITS]]);
                if ($count === false) {
                    throw new \ClientFence\InformationException('The count must be a whole number from 0 to :max.', [':max' => self::MAX_UNITS]);
                }
                // Only a changed count counts as staff's own (the form sends the current one back).
                if ($count !== ($units['count'] ?? null)) {
                    $units['count'] = $count;
                    $units['held'] = null;
                    // Software that cannot report: no warning or suspension until it does.
                    $units['manual'] = true;
                }
            }
            if (isset($data['held']) && !empty($units['held'])) {
                if ($data['held'] === 'approve') {
                    $units['count'] = (int) $units['held']['count'];
                }
                $units['held'] = null;
            }
            self::save($licence, $units);
            $di['em']->flush();
            if (!empty($units['billing']) && isset($units['count'])) {
                self::price($di, $order, $di['mod_service']('servicelicense')->productConfig($licence), (int) $units['count']);
            }
        });
    }

    /**
     * Staff, after a WHMCS import: the switch and the count of each licence of the product, from the
     * imported order form answers under the product's field names (unit_field, unit_count_field).
     * Licences whose switch staff already set are left alone; prices stay the imported ones.
     * Returns how many licences were set.
     */
    public static function fromAnswers(\Pimple\Container $di, int $productId, array $product): int
    {
        $db = $di['dbal'];
        $label = static fn (string $key): string => trim((string) ($product[$key] ?? ''));
        if ($label('unit_field') === '' && $label('unit_count_field') === '') {
            throw new \ClientFence\InformationException('Set the answer field names first.');
        }
        $set = 0;
        $rows = $db->fetchAllAssociative("SELECT id, form_id, config, service_id FROM client_order WHERE product_id = ? AND service_type = 'license' AND status <> ?", [$productId, Order::STATUS_CANCELED]);
        foreach ($rows as $row) {
            $licence = $di['em']->find(ServiceLicense::class, (int) $row['service_id']);
            $units = $licence instanceof ServiceLicense ? self::of($licence) : null;
            if ($units === null || array_key_exists('billing', $units)) {
                continue;
            }
            $answers = json_decode((string) $row['config'], true) ?: [];
            $answer = static fn (string $key): mixed => $label($key) === '' ? null : $answers[(string) $db->fetchOne('SELECT name FROM form_field WHERE form_id = ? AND label = ?', [(int) $row['form_id'], $label($key)])] ?? null;
            $on = in_array('on', (array) $answer('unit_field'), true);
            $count = $answer('unit_count_field');
            if (!$on && !is_numeric($count)) {
                continue;
            }
            $units['billing'] = $on;
            $units['since'] = date('Y-m-d H:i:s');
            if (is_numeric($count) && (int) $count >= 0) {
                $units['count'] = min((int) $count, self::MAX_UNITS);
            }
            self::save($licence, $units);
            ++$set;
        }
        $di['em']->flush();

        return $set;
    }

    /** After a staff unsuspend the report clock starts again, so the cron does not suspend at once. */
    public static function unsuspended(\Pimple\Container $di, ServiceLicense $licence): void
    {
        $units = self::of($licence);
        if (!empty($units['billing'])) {
            $units['grace'] = date('Y-m-d H:i:s');
            self::save($licence, $units);
            $di['em']->flush();
        }
    }

    /**
     * Cron: licences with the switch on and no report for the product's days are warned about once
     * per gap (client email, staff notice) and, with "Suspend" on, their order is suspended.
     */
    public static function cron(\Pimple\Container $di): void
    {
        $rows = $di['dbal']->fetchAllAssociative("SELECT l.id, o.id AS order_id FROM service_license l JOIN client_order o ON o.service_id = l.id AND o.service_type = 'license' WHERE o.status = ? AND l.config LIKE ?", [Order::STATUS_ACTIVE, '%"billing":true%']);
        foreach ($rows as $row) {
            try {
                $licence = $di['em']->find(ServiceLicense::class, (int) $row['id']);
                $order = $di['em']->find(Order::class, (int) $row['order_id']);
                if ($licence instanceof ServiceLicense && $order instanceof Order) {
                    self::checkReport($di, $licence, $order, time());
                }
            } catch (\Throwable $e) {
                $di['logger']->setChannel('license')->error('Usage report check of order #%s failed: %s', $row['order_id'], $e->getMessage());
            }
        }
    }

    public static function checkReport(\Pimple\Container $di, ServiceLicense $licence, Order $order, int $now): void
    {
        $units = self::of($licence);
        $product = $di['mod_service']('servicelicense')->productConfig($licence);
        if (self::monthly($product, 0) === null || !empty($units['manual'])) {
            return;
        }
        $from = max(array_map(strtotime(...), array_filter([$units['at'] ?? null, $units['since'] ?? null, $units['grace'] ?? null])) ?: [$now]);
        $days = ($now - $from) / 86400;
        $params = ['id' => $order->getId(), 'title' => (string) $order->getTitle()];
        if (!empty($product['unit_suspend']) && $days >= (int) ($product['unit_suspend_days'] ?? 7)) {
            $di['mod_service']('order')->suspendFromOrder($order, 'No usage report');

            return;
        }
        $gap = date('Y-m-d H:i:s', $from);
        if (!empty($product['unit_warn']) && $days >= (int) ($product['unit_warn_days'] ?? 2) && ($units['warned'] ?? null) !== $gap) {
            $units['warned'] = $gap;
            self::save($licence, $units);
            $di['em']->flush();
            \ClientFence\Mod\Clientfence\Notifications::staff($di, 'usage_missing', $params, 'order/manage/' . $order->getId(), 'order');
            try {
                $di['mod_service']('email')->sendTemplate([
                    'to_client' => (int) $order->getClientId(),
                    'code' => 'mod_servicelicense_usage_missing',
                    'order' => $params,
                    'last_report' => $units['at'] ?? null,
                    'unit' => (string) ($product['unit_name'] ?? ''),
                ]);
            } catch (\Throwable $e) {
                $di['logger']->warning('Usage report warning of order #%s: client email not sent: %s', $order->getId(), $e->getMessage());
            }
        }
    }

    /**
     * The licence tab and service page view: switch, count, last report, held drop and the monthly price.
     *
     * @return array<string, mixed>
     */
    public static function view(ServiceLicense $licence, array $product): array
    {
        $units = self::of($licence);
        $count = isset($units['count']) ? (int) $units['count'] : null;

        return [
            'priced' => self::monthly($product, 0) !== null,
            'manual' => !empty($units['manual']),
            'billing' => !empty($units['billing']),
            'count' => $count,
            'at' => $units['at'] ?? null,
            'ips' => $units['ips'] ?? [],
            'held' => $units['held'] ?? null,
            'unit' => (string) ($product['unit_name'] ?? ''),
            'unit_price' => is_numeric($product['unit_price'] ?? null) ? (float) $product['unit_price'] : null,
            'minimum' => is_numeric($product['unit_minimum'] ?? null) ? (float) $product['unit_minimum'] : null,
            'monthly' => $count === null ? null : self::monthly($product, $count),
        ];
    }

    /**
     * Sets the order's price for the count: the monthly price × the months of its period, in the
     * order's currency, per unit of its quantity. One-time orders keep theirs. Issued invoices keep theirs.
     */
    private static function price(\Pimple\Container $di, Order $order, array $product, int $count): void
    {
        $monthly = self::monthly($product, $count);
        if ($monthly === null || $order->getPeriod() === null) {
            return;
        }
        $db = $di['dbal'];
        $rate = (float) ($db->fetchOne('SELECT conversion_rate FROM currency WHERE code = ?', [$order->getCurrency()]) ?: 1.0);
        $total = $monthly * (new \ClientFence\Period($order->getPeriod()))->getMonths() * $rate;
        $price = round($total / max(1, (int) $order->getQuantity()), 2);
        $current = (float) $db->fetchOne('SELECT price FROM client_order WHERE id = ?', [$order->getId()]);
        if (abs($current - $price) < 0.005) {
            return;
        }
        $db->update('client_order', ['price' => $price, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $order->getId()]);
        $di['logger']->info('Order #%s: %s units reported, price %s', $order->getId(), $count, $price);
    }

    /**
     * The licence whose answer gives these ids and that has the reporting server on it (a native and an
     * imported licence may give the same ids).
     *
     * @return array{0: ?ServiceLicense, 1: ?Order}
     */
    private static function find(\Pimple\Container $di, int $serviceId, int $productId, string $ip): array
    {
        $db = $di['dbal'];
        $ids = $db->fetchFirstColumn("SELECT o.id FROM client_order o JOIN service_license l ON l.id = o.service_id WHERE o.service_type = 'license' AND o.status <> ? AND (o.id = ? OR l.config LIKE ?)", [Order::STATUS_CANCELED, $serviceId, '%"service_id":' . $serviceId . ',%']);
        foreach ($ids as $orderId) {
            $order = $di['em']->find(Order::class, (int) $orderId);
            $licence = $order instanceof Order ? $di['em']->find(ServiceLicense::class, (int) $order->getServiceId()) : null;
            if ($licence instanceof ServiceLicense && self::answerIds($licence, $order) === [$serviceId, $productId] && in_array($ip, $licence->getAllowedIps(), true)) {
                return [$licence, $order];
            }
        }

        return [null, null];
    }
}
