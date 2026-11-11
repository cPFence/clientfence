<?php

declare(strict_types=1);
/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Serviceapikey;

use ClientFence\Mod\Order\Entity\Order;
use ClientFence\Mod\Product\Entity\Product;
use ClientFence\Mod\Serviceapikey\Entity\ServiceApiKey;
use ClientFence\InjectionAwareInterface;
use ClientFence\IpSeats;
use Symfony\Component\HttpFoundation\IpUtils;

class Service implements InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function getModulePermissions(): array
    {
        return [
            'manage' => [
                'type' => 'bool',
                'display_name' => __trans('Manage API keys'),
                'description' => __trans('Allows the staff member to update and reset API keys.'),
            ],
        ];
    }

    /**
     * Top-level cart-config keys a client is authorized to set when ordering
     * an API-key product. Admin-controlled fields (length, split, case, etc.)
     * are stripped from client input before the merge.
     *
     * @return list<string>
     */
    public function clientSettableConfigKeys(): array
    {
        return ['period', 'quantity'];
    }

    public function attachOrderConfig(Product $product, array $data): array
    {
        $config = json_decode($product->getConfig() ?? '', true) ?? [];

        return array_merge($config, $data);
    }

    /**
     * @param \Model_ClientOrder $order with client_id and config properties
     */
    public function action_create(\Model_ClientOrder $order): ServiceApiKey
    {
        $model = new ServiceApiKey();
        $model->setClientId((int) $order->client_id);
        $model->setConfig($order->config);

        $this->di['em']->persist($model);
        $this->di['em']->flush();

        return $model;
    }

    public function action_activate(\Model_ClientOrder $order): bool
    {
        $model = $this->_getService($order);
        $config = json_decode($order->config ?? '', true);
        $model->setApiKey($this->generateKey($config));

        $this->di['em']->flush();

        return true;
    }

    public function action_renew(\Model_ClientOrder $order): bool
    {
        // ClientFence core patch: paid API key renewals extend the order without replacing its key.
        return true;
    }

    public function action_suspend(\Model_ClientOrder $order): bool
    {
        $this->_getService($order);

        return true;
    }

    public function action_unsuspend(\Model_ClientOrder $order): bool
    {
        $this->_getService($order);

        return true;
    }

    public function action_cancel(\Model_ClientOrder $order): bool
    {
        return $this->action_suspend($order);
    }

    public function action_uncancel(\Model_ClientOrder $order): bool
    {
        return $this->action_unsuspend($order);
    }

    public function action_delete(\Model_ClientOrder $order): void
    {
        $model = $this->_getService($order, false);
        if ($model instanceof ServiceApiKey) {
            $this->di['em']->remove($model);
            $this->di['em']->flush();
        }
    }

    public function toApiArray(ServiceApiKey $model, bool $deep = false): array
    {
        // ClientFence core patch: the IP seats of the service page and the order's API key tab (CF-56).
        return ($deep ? ['seats' => $this->ipSeats($model)->view(time())] : []) + [
            'id' => $model->getId(),
            'created_at' => $model->getCreatedAt()?->format('Y-m-d H:i:s'),
            'updated_at' => $model->getUpdatedAt()?->format('Y-m-d H:i:s'),
            'api_key' => $model->getApiKey(),
            'config' => json_decode($model->getConfig() ?? '', true),
            'allowed_ips' => $model->getAllowedIps(),
            'last_used_at' => $model->getLastUsedAt()?->format('Y-m-d H:i:s'),
            'use_count' => $model->getUseCount(),
        ];
    }

    /**
     * Checks if an API key is valid or not.
     *
     *                    - 'key' What API key to check
     */
    public function isValid(array $data): bool
    {
        $key = $data['key'] ?? null;
        if (!is_string($key) || $key === '') {
            throw new \ClientFence\Exception('You must provide an API key to check it\'s validity.');
        }

        $model = $this->getRepository()->findByApiKey($key);
        if ($model === null) {
            throw new \ClientFence\Exception('API key does not exist');
        }

        // ClientFence core patch: with "Servers (IP addresses)" on the product, the key's seat list decides
        // and an active key may give a new IP a free place (IpSeats); without, the IPs or ranges, empty: all.
        $ip = (string) $this->di['request']->getClientIp();
        $seats = $this->ipSeats($model);
        $allowedIps = $model->getAllowedIps();
        if (!$seats->limited() && $allowedIps !== [] && !IpUtils::checkIp($ip, $allowedIps)) {
            return false;
        }

        if (!$this->isActive($model)) {
            return false;
        }

        if ($seats->limited()) {
            $changed = $seats->check($ip, time());
            if ($changed) {
                // A bind is saved under the order's lock, on the list and plan as they are now.
                $changed = IpSeats::locked($this->di, $this->ipOrder($model), $model, function () use ($model, $ip): ?bool {
                    $seats = $this->ipSeats($model);
                    $changed = $seats->check($ip, time());
                    if ($changed) {
                        $this->ipSave($model, $seats);
                    }

                    return $changed;
                });
            }
            if ($changed === null) {
                return false;
            }
        }

        $this->di['em']->getConnection()->executeStatement(
            'UPDATE service_apikey SET last_used_at = ?, use_count = use_count + 1 WHERE id = ?',
            [date('Y-m-d H:i:s'), $model->getId()]
        );

        return true;
    }

    public function findApiKey(array $data): ServiceApiKey
    {
        if (!empty($data['order_id'])) {
            $order = $this->getOrderRepository()->find($data['order_id']);
            if ($order === null) {
                throw new \ClientFence\Exception('Order not found');
            }

            $model = $this->di['mod_service']('order')->getOrderService($order);
        } elseif (!empty($data['key']) && is_string($data['key'])) {
            $model = $this->getRepository()->findByApiKey($data['key']);
        } else {
            throw new \ClientFence\Exception('You must provide either the API key or API key order ID in order to reset it.');
        }

        if (!$model instanceof ServiceApiKey) {
            throw new \ClientFence\Exception('API key does not exist');
        }

        return $model;
    }

    /**
     * Used to reset an API key using the API key generator.
     */
    public function resetApiKey(ServiceApiKey $model): bool
    {
        if (!$this->isActive($model)) {
            throw new \ClientFence\InformationException('Order is not active');
        }

        $config = json_decode($model->getConfig() ?? '', true);

        $model->setApiKey($this->generateKey($config));
        $this->di['em']->flush();

        return true;
    }

    /**
     * ClientFence core patch: with "Servers (IP addresses)" on the product (IpSeats), the list holds
     * single IP addresses, staff may set more than 20 (or than the limit), and clients add and remove
     * one at a time (ip_add, ip_remove) instead.
     */
    public function setAllowedIps(ServiceApiKey $model, mixed $input, bool $staff = false): bool
    {
        $seats = $this->ipSeats($model);
        if ($seats->limited() && !$staff) {
            throw new \ClientFence\InformationException('This plan has a server limit: add or remove one IP address at a time.');
        }
        $entries = is_array($input) ? $input : preg_split('/[\s,]+/', (string) $input, -1, PREG_SPLIT_NO_EMPTY);
        $ips = [];
        foreach ($entries as $entry) {
            if (!is_string($entry) || !self::isIpOrRange($entry)) {
                throw new \ClientFence\InformationException('This is not an IP address or range: :ip', [':ip' => is_string($entry) ? $entry : '']);
            }
            if ($seats->limited() && str_contains($entry, '/')) {
                throw new \ClientFence\InformationException('With a server limit, the list holds single IP addresses, not ranges: :ip', [':ip' => $entry]);
            }
            $ips[] = $entry;
        }

        $ips = array_values(array_unique($ips));
        if (count($ips) > 20 && !$seats->limited()) {
            throw new \ClientFence\InformationException('You can add at most 20 IP addresses or ranges.');
        }

        $seats->setList($ips, time());
        $this->ipSave($model, $seats);

        return true;
    }

    /** ClientFence: the IP seats of the key, with the rules of its product as it is now (IpSeats). */
    public function ipSeats(ServiceApiKey $model): IpSeats
    {
        return IpSeats::of(IpSeats::productConfig($this->di, $this->ipOrder($model)), false, $model->getAllowedIps(), $model->getConfig());
    }

    /**
     * ClientFence: an IP seat action (IpSeats::act()): the client's add, remove or withdraw, staff's
     * approve, refuse or clear; saved, then written to the order's history (and told).
     */
    public function ipAction(ServiceApiKey $model, string $action, string $ip = '', string $reason = ''): bool
    {
        $order = $this->ipOrder($model);
        $event = IpSeats::locked($this->di, $order, $model, function () use ($model, $order, $action, $ip, $reason): array {
            $seats = $this->ipSeats($model);
            $event = $seats->act($action, $ip, $reason, time());
            $this->ipSave($model, $seats);
            IpSeats::tell($this->di, $order, $event);

            return $event;
        });

        return IpSeats::approved($action, $event);
    }

    private function ipSave(ServiceApiKey $model, IpSeats $seats): void
    {
        $model->setAllowedIps($seats->ips);
        $model->setConfig($seats->config($model->getConfig()));
        $this->di['em']->flush();
    }

    private function ipOrder(ServiceApiKey $model): ?Order
    {
        return $this->getOrderRepository()->findOneBy(['serviceId' => $model->getId(), 'serviceType' => \ClientFence\Mod\Product\Service::APIKEY]);
    }

    /**
     * Used to update an API key, but prevents changing the API key so we can ensure they use the reset function.
     *
     * @param array $data An array containing what API key to update and what info to update.
     *                    - int 'order_id' The order ID of the API key to update.
     *                    - array 'config' (optional) The new config to attach to the API key.
     */
    public function updateApiKey(array $data): bool
    {
        if (empty($data['order_id'])) {
            throw new \ClientFence\Exception('You must provide the API key order ID in order to update it.');
        }

        $order = $this->getOrderRepository()->find($data['order_id']);
        if ($order === null) {
            throw new \ClientFence\Exception('Order not found');
        }

        $orderService = $this->di['mod_service']('order');
        $model = $orderService->getOrderService($order);

        if (!$model instanceof ServiceApiKey) {
            throw new \ClientFence\Exception('API key does not exist');
        }

        if (isset($data['api_key']) && $model->getApiKey() !== $data['api_key']) {
            throw new \ClientFence\Exception('To change the API key, please use the reset function rather than updating it.');
        }

        // ClientFence core patch: the key's IP seat state (IpSeats) stays when staff save its settings.
        $state = array_intersect_key(json_decode($model->getConfig() ?? '', true) ?: [], ['ip_state' => true]);
        $config = !empty($data['config']) ? json_encode($data['config'] + $state) : $model->getConfig();
        $model->setConfig($config);
        $this->di['em']->flush();

        return true;
    }

    /**
     * Creates the database structure to store the API keys in.
     */
    public function install(): bool
    {
        $sql = '
        CREATE TABLE IF NOT EXISTS `service_apikey` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT UNIQUE,
            `client_id` bigint(20) NOT NULL,
            `api_key` varchar(255),
            `config` text NOT NULL,
            `allowed_ips` text,
            `last_used_at` datetime,
            `use_count` bigint(20) NOT NULL DEFAULT 0,
            `created_at` datetime,
            `updated_at` datetime,
            PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;';
        $this->di['db']->exec($sql);

        return true;
    }

    /**
     * Removes the API keys from the database.
     */
    public function uninstall(): bool
    {
        $this->di['db']->exec('DROP TABLE IF EXISTS `service_apikey`');

        return true;
    }

    /**
     * Generates a new API using PHP's built-in cryptographically secure random_bytes to ensure the API keys are truly random and not predictable.
     *
     * @param array $config (optional) An array of configuration options. All configuration keys are optional.
     *                      - int 'length' How long of an API key to generate.
     *                      - bool 'split' True to enable splitting of the API key using dashes. Does not count towards the total key length.
     *                      - int 'split_interval' How often the API key should be split with dashes. Example: 8 for every 8 characters.
     *                      - string 'case' What capitalization should be used for the generated API key. 'lower', 'upper', or 'mixed'.
     */
    private function generateKey(array $config = []): string
    {
        // ClientFence core patch (ext serviceapikey): the product form saves numbers as strings.
        // ClientFence core patch (ext serviceapikey): 128 characters with a dash between each fit the 255-character column.
        // ClientFence core patch: API keys need at least 16 random characters even with legacy settings.
        $length = max(16, min(128, (int) ($config['length'] ?? 32)));
        $split = $config['split'] ?? true;
        $splitLength = max(1, (int) ($config['split_interval'] ?? 8));
        $case = $config['case'] ?? 'upper';

        $i = 0;
        do {
            // Try 10 times to generate a unique API key. Fail if we are unable to.
            if ($i++ >= 10) {
                throw new \ClientFence\Exception('Maximum number of iterations reached while generating API key');
            }

            // Generate random bytes half the length of the configured length, as the length will doubled when converted to a hex string.
            $randomBytes = random_bytes((int) ceil($length / 2));
            $apiKey = substr(bin2hex($randomBytes), 0, $length);

            if ($split) {
                $apiKey = chunk_split($apiKey, $splitLength, '-');
                $apiKey = rtrim($apiKey, '-');
            }

            switch ($case) {
                case 'lower':
                    // Do nothing, the API key generated will be lowercase by default.
                    break;
                case 'upper':
                    $apiKey = strtoupper($apiKey);

                    break;
                case 'mixed':
                    $characters = str_split($apiKey);
                    $result = '';

                    foreach ($characters as $character) {
                        if (random_int(0, 1) <= 0.5) {
                            $character = strtoupper($character);
                        }

                        $result .= $character;
                    }
                    $apiKey = $result;

                    break;
                default:
                    throw new \ClientFence\Exception("Unknown uppercase option ':case:'. API generator only accepts 'lower', 'upper', or 'mixed'.", [':case:' => $case]);
            }
        } while ($this->getRepository()->findByApiKey($apiKey) !== null);

        return $apiKey;
    }

    private static function isIpOrRange(string $entry): bool
    {
        [$address, $prefix] = array_pad(explode('/', $entry, 2), 2, null);
        $bits = match (true) {
            filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false => 32,
            filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false => 128,
            default => 0,
        };

        return $bits > 0 && ($prefix === null || (ctype_digit($prefix) && (int) $prefix <= $bits));
    }

    private function isActive(ServiceApiKey $model): bool
    {
        $order = $this->getOrderRepository()->findOneBy([
            'serviceId' => $model->getId(),
            'serviceType' => \ClientFence\Mod\Product\Service::APIKEY,
        ]);
        if (!$order instanceof Order) {
            throw new \ClientFence\Exception('API key does not exist');
        }

        if ($order->getStatus() !== Order::STATUS_ACTIVE) {
            return false;
        }

        $expiresAt = $order->getExpiresAt();
        if ($expiresAt !== null && $expiresAt->getTimestamp() <= time()) {
            return false;
        }

        return true;
    }

    private function _getService(\Model_ClientOrder $order, bool $required = true): ?ServiceApiKey
    {
        $orderService = $this->di['mod_service']('order');
        $model = $orderService->getOrderService($order);
        if (!$model instanceof ServiceApiKey) {
            if ($required) {
                throw new \ClientFence\Exception('Could not find active service');
            }

            return null;
        }

        return $model;
    }

    private function getRepository()
    {
        return $this->di['em']->getRepository(ServiceApiKey::class);
    }

    private function getOrderRepository()
    {
        return $this->di['em']->getRepository(Order::class);
    }
}
