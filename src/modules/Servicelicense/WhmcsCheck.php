<?php

declare(strict_types=1);

namespace ClientFence\Mod\Servicelicense;

use ClientFence\Mod\Order\Entity\Order;
use ClientFence\Mod\Servicelicense\Entity\ServiceLicense;

/**
 * ClientFence: answers checks in the WHMCS Licensing Addon format at its old address
 * (/modules/servers/licensing/verify.php, see .htaccess), for software that was sold with WHMCS.
 * Only licences of products with "Answer WHMCS Licensing Addon checks" on are answered.
 */
final class WhmcsCheck
{
    private const CYCLES = [1 => 'Monthly', 3 => 'Quarterly', 6 => 'Semi-Annually', 12 => 'Annually', 24 => 'Biennially', 36 => 'Triennially'];

    /**
     * No md5hash: the product config (where a secret would live) is copied into orders that clients can
     * read, and the clients this was built for send an empty check_token.
     *
     * The caller's address is checked, not the posted ip, which anyone could set to a licensed IP
     * (the clients this was built for connect over IPv4 only, so both are the same).
     *
     * @param array<string, mixed> $post the POST fields: licensekey, and optionally domain and dir
     */
    public static function answer(\Pimple\Container $di, array $post, string $ip): string
    {
        $field = static fn (string $name): string => is_string($post[$name] ?? null) ? trim($post[$name]) : '';
        $key = $field('licensekey');
        $domain = $field('domain');
        $dir = $field('dir');

        $model = $key === '' || strlen($key) > 255 ? null : $di['em']->getRepository(ServiceLicense::class)->findByLicenseKey($key);
        $service = $di['mod_service']('servicelicense');
        $order = $model instanceof ServiceLicense ? $di['mod_service']('order')->getServiceOrder($model) : null;
        $config = $order instanceof Order ? $service->productConfig($model) : [];
        if (!$order instanceof Order || empty($config['whmcs_check'])) {
            // As WHMCS: unknown keys are logged under licence 0.
            CheckLog::record($di, 0, $domain, $ip, $dir, '', 'Invalid Key - ' . $key);

            return self::tags(['status' => 'Invalid', 'description' => 'License key is invalid']);
        }

        $model->setPingedAt(new \DateTime());
        $di['em']->flush();

        $failure = self::failure($service, $model, $order, Bans::match($di, $domain, $ip), $ip, $domain, $dir);
        CheckLog::record($di, (int) $model->getId(), $domain, $ip, $dir, '', $failure[1] ?? 'Valid');
        if ($failure !== null) {
            return self::tags(['status' => $failure[0], 'description' => $failure[1]]);
        }

        [$serviceId, $productId] = Units::answerIds($model, $order);

        return self::tags([
            'status' => 'Active',
            'serviceid' => (string) $serviceId,
            'productid' => (string) $productId,
            'billingcycle' => self::cycle($di, $order, $model),
            'customfields' => self::customFields($model, $config),
        ]);
    }

    /**
     * WHMCS's "Name=value|Name=value" list: the per-unit switch and count under the names the
     * product gives them (empty names: not sent).
     */
    private static function customFields(ServiceLicense $model, array $config): string
    {
        $units = Units::of($model);
        $fields = [];
        if (trim((string) ($config['unit_field'] ?? '')) !== '') {
            $fields[] = trim($config['unit_field']) . '=' . (empty($units['billing']) ? '' : 'on');
        }
        if (trim((string) ($config['unit_count_field'] ?? '')) !== '') {
            $fields[] = trim($config['unit_count_field']) . '=' . ($units['count'] ?? '');
        }

        return implode('|', $fields);
    }

    /**
     * The refusal as [status, WHMCS log message], or null when the licence passes. The order's status
     * decides, not its expiry date: as in WHMCS, a licence stays Active until the order is suspended.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function failure(Service $service, ServiceLicense $model, Order $order, ?string $banned, string $ip, string $domain, string $dir): ?array
    {
        if ($banned !== null) {
            return ['Invalid', 'Banned'];
        }

        return match ($order->getStatus()) {
            Order::STATUS_ACTIVE => match (true) {
                !$service->isAllowedIp($model, $ip) => ['Invalid', 'IP Address Invalid'],
                $domain !== '' && !$service->isValidHost($model, $domain) => ['Invalid', 'Domain Invalid'],
                $dir !== '' && !$service->isValidPath($model, $dir) => ['Invalid', 'Directory Invalid'],
                default => null,
            },
            Order::STATUS_SUSPENDED => ['Suspended', 'License Suspended'],
            Order::STATUS_CANCELED => ['Expired', 'License Expired'],
            default => ['Invalid', 'License Not Active'],
        };
    }

    /**
     * The WHMCS billing cycle name of the order.
     */
    private static function cycle(\Pimple\Container $di, Order $order, ServiceLicense $model): string
    {
        // An imported order without a period keeps WHMCS's name ("Free Account" can have a price).
        $whmcs = (json_decode($model->getConfig() ?? '', true) ?: [])['whmcs']['billingcycle'] ?? '';
        if ($order->getPeriod() === null && $whmcs !== '') {
            return $whmcs;
        }
        $type = $di['dbal']->fetchOne('SELECT pp.type FROM product p JOIN product_payment pp ON pp.id = p.product_payment_id WHERE p.id = ?', [$order->getProductId()]);
        if ($type === 'free') {
            return 'Free Account';
        }
        if ($type === 'once' || $order->getPeriod() === null) {
            return 'One Time';
        }
        $period = new \ClientFence\Period($order->getPeriod());

        return self::CYCLES[$period->getMonths()] ?? $period->getTitle();
    }

    /**
     * WHMCS's answer: adjacent tags with no root element.
     *
     * @param array<string, string> $tags
     */
    private static function tags(array $tags): string
    {
        $xml = '';
        foreach ($tags as $name => $value) {
            $xml .= '<' . $name . '>' . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</' . $name . '>';
        }

        return $xml;
    }
}
