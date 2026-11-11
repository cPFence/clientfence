<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Servicelicense\Api;

use ClientFence\Mod\Servicelicense\Bans;
use ClientFence\Mod\Servicelicense\CheckLog;
use ClientFence\Mod\Servicelicense\Entity\ServiceLicense;

/**
 *Service license management.
 */
class Admin extends \ClientFence\Api\AbstractApi
{
    /**
     * Get available licensing plugins.
     */
    public function plugin_get_pairs(array $data): array
    {
        $this->checkPermissions('servicelicense', 'manage');

        $plugins = $this->getService()->getLicensePlugins();
        $result = [];
        foreach ($plugins as $plugin) {
            $filename = $plugin['filename'];
            $result[$filename] = $filename;
        }

        return $result;
    }

    /**
     * Update license parameters. Set which validation rules must be applied
     * for license.
     *
     * @optional string $plugin - New license plugin name
     * @optional bool $validate_ip - True to validate IP; False - to allow all IPs for this license
     * @optional bool $validate_host - True to validate hostname; False - to allow all hostnames for this license
     * @optional bool $validate_path - True to validate install paths; False - to allow all paths for this license
     * @optional bool $validate_version - True to validate version; False - to allow all versions for this license
     * @optional array $ips - List of allowed IPs for this license
     * @optional array $hosts - List of allowed hosts for this license
     * @optional array $paths - List of allowed paths for this license
     * @optional array $versions - List of allowed versions for this license
     *
     * @return bool
     */
    public function update($data)
    {
        $this->checkPermissions('servicelicense', 'manage');

        $s = $this->_getService($data);

        return $this->getService()->update($s, $data);
    }

    /**
     * Reset license validation rules.
     *
     * @return bool
     */
    public function reset($data)
    {
        $this->checkPermissions('servicelicense', 'manage');

        $s = $this->_getService($data);

        return $this->getService()->reset($s);
    }

    /**
     * ClientFence core patch: clears the client's IP change count of a licence (order_id; IpSeats, CF-56).
     */
    public function ip_changes_clear($data): bool
    {
        $this->checkPermissions('servicelicense', 'manage');

        return $this->getService()->ipAction($this->_getService($data), 'clear');
    }

    /**
     * ClientFence core patch: per-unit billing of a licence (order_id; Units.php): billing (the staff switch),
     * count (staff's own count), held (approve or refuse a held drop). The order's price follows.
     */
    public function units_update($data): bool
    {
        $this->checkPermissions('servicelicense', 'manage');
        $licence = $this->_getService($data);
        $order = $this->getDi()['mod_service']('order')->getServiceOrder($licence);
        \ClientFence\Mod\Servicelicense\Units::update($this->getDi(), $licence, $order, $data);
        $this->getDi()['logger']->info('Updated per-unit billing of order #%s', $order->getId());

        return true;
    }

    /**
     * ClientFence core patch: after a WHMCS import, takes the per-unit switch and counts of a product's
     * licences from their imported answers (product_id; Units::fromAnswers()). Returns how many were set.
     */
    public function units_from_answers($data): int
    {
        $this->checkPermissions('servicelicense', 'manage');
        $product = $this->getDi()['em']->getRepository(\ClientFence\Mod\Product\Entity\Product::class)->find((int) ($data['product_id'] ?? 0));
        if (!$product instanceof \ClientFence\Mod\Product\Entity\Product) {
            throw new \ClientFence\InformationException('Product not found');
        }
        $set = \ClientFence\Mod\Servicelicense\Units::fromAnswers($this->getDi(), (int) $product->getId(), json_decode($product->getConfig() ?? '', true) ?: []);
        $this->getDi()['logger']->info('Per-unit billing of product #%s: %s licences set from imported answers', $product->getId(), $set);

        return $set;
    }

    /** ClientFence: applies the client's IP change request (order_id); false when it could no longer be applied and was refused. */
    public function ip_request_approve($data): bool
    {
        $this->checkPermissions('servicelicense', 'manage');

        return $this->getService()->ipAction($this->_getService($data), 'approve');
    }

    /** ClientFence: refuses the client's IP change request (order_id, reason: optional, sent to the client). */
    public function ip_request_refuse($data): bool
    {
        $this->checkPermissions('servicelicense', 'manage');

        return $this->getService()->ipAction($this->_getService($data), 'refuse', '', (string) ($data['reason'] ?? ''));
    }

    /**
     * ClientFence: a licence's latest checks, newest first (CheckLog).
     *
     * @return list<array<string, string>>
     */
    public function check_log($data): array
    {
        $this->checkPermissions('servicelicense', 'manage');

        return CheckLog::recent($this->getDi(), (int) $this->_getService($data)->getId(), (int) ($data['limit'] ?? 50));
    }

    /**
     * ClientFence: the licence settings of System Settings > Licences: how many days the check
     * log keeps (0: every check) and the bans.
     */
    public function settings_get($data): array
    {
        $this->checkPermissions('servicelicense', 'manage');

        $secret = $this->getDi()['dbal']->fetchOne('SELECT value FROM setting WHERE param = ?', [\ClientFence\Mod\Servicelicense\Units::SECRET]);

        return ['log_days' => CheckLog::days($this->getDi()), 'bans' => Bans::all($this->getDi()), 'report_secret_set' => (string) $secret !== ''];
    }

    /**
     * ClientFence: saves "Keep the licence check log" (days, 0 keeps every check).
     */
    public function settings_update($data): bool
    {
        $this->checkPermissions('servicelicense', 'manage');
        CheckLog::saveDays($this->getDi(), $data['log_days'] ?? '');
        // ClientFence core patch: the usage report secret (Units.php): empty keeps the saved one; it is never shown again.
        $secret = is_string($data['report_secret'] ?? null) ? trim($data['report_secret']) : '';
        if ($secret !== '') {
            $db = $this->getDi()['dbal'];
            $param = \ClientFence\Mod\Servicelicense\Units::SECRET;
            $now = date('Y-m-d H:i:s');
            if ($db->fetchOne('SELECT 1 FROM setting WHERE param = ?', [$param]) === false) {
                $db->insert('setting', ['param' => $param, 'value' => $secret, 'public' => 0, 'created_at' => $now, 'updated_at' => $now]);
            } else {
                $db->update('setting', ['value' => $secret, 'updated_at' => $now], ['param' => $param]);
            }
            \ClientFence\RequestCache::forget($this->getDi(), 'setting.');
        }
        $this->getDi()['logger']->info('Updated the licence check log setting');

        return true;
    }

    /**
     * ClientFence: bans a domain or IP address from every licence check (Bans).
     */
    public function ban_add($data): int
    {
        $this->checkPermissions('servicelicense', 'manage');
        $id = Bans::add($this->getDi(), (string) ($data['value'] ?? ''), (string) ($data['notes'] ?? ''));
        $this->getDi()['logger']->info('Added licence ban #%s', $id);

        return $id;
    }

    /**
     * ClientFence: lifts a licence ban.
     */
    public function ban_delete($data): bool
    {
        $this->checkPermissions('servicelicense', 'manage');
        Bans::delete($this->getDi(), (int) ($data['id'] ?? 0));
        $this->getDi()['logger']->info('Removed licence ban #%s', (int) ($data['id'] ?? 0));

        return true;
    }

    /**
     * @throws \ClientFence\Exception
     */
    public function _getService(array $data): ServiceLicense
    {
        $required = ['order_id' => 'Order ID is required'];
        $this->getDi()['validator']->checkRequiredParamsForArray($required, $data);

        $order = $this->getDi()['em']->getRepository(\ClientFence\Mod\Order\Entity\Order::class)->find($data['order_id']);
        if ($order === null) {
            throw new \ClientFence\Exception('Order not found');
        }

        $orderService = $this->getDi()['mod_service']('order');
        $s = $orderService->getOrderService($order);
        if (!$s instanceof ServiceLicense) {
            throw new \ClientFence\Exception('Order is not activated');
        }

        return $s;
    }
}
