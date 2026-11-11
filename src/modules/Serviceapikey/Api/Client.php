<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Serviceapikey\Api;

class Client extends \ClientFence\Api\AbstractApi
{
    /**
     * Used to reset / regenerate an API key. Useful in the event one is accidentally leaked.
     *
     * @param array $data - An associative array containing either the key or ID of whatever API key you want to reset.
     *                    - string 'key' The API key to reset.
     *                    - int 'order_id' The order ID of the API key to reset.
     */
    public function reset($data): bool
    {
        return $this->getService()->resetApiKey($this->getOwnApiKey($data));
    }

    /**
     * ClientFence core patch (ext serviceapikey): the client sets the IPs or CIDR ranges (order_id, allowed_ips: one per line, at most 20, empty: all) a key may be checked from.
     */
    public function update_ips($data): bool
    {
        return $this->getService()->setAllowedIps($this->getOwnApiKey($data), $data['allowed_ips'] ?? '');
    }

    /**
     * ClientFence core patch: adds an IP address (order_id, ip) into a free place of a product with "Servers (IP
     * addresses)"; true when done or waiting for staff approval (order_service seats.pending).
     */
    public function ip_add($data): bool
    {
        return $this->getService()->ipAction($this->getOwnApiKey($data), \ClientFence\IpSeats::ADD, (string) ($data['ip'] ?? ''));
    }

    /** ClientFence: removes an IP address (order_id, ip): one IP change, free in its first 24 hours while no check has used it. */
    public function ip_remove($data): bool
    {
        return $this->getService()->ipAction($this->getOwnApiKey($data), \ClientFence\IpSeats::REMOVE, (string) ($data['ip'] ?? ''));
    }

    /** ClientFence: takes back the IP change request waiting for staff approval (order_id). */
    public function ip_request_withdraw($data): bool
    {
        return $this->getService()->ipAction($this->getOwnApiKey($data), 'withdraw');
    }

    private function getOwnApiKey(array $data): \ClientFence\Mod\Serviceapikey\Entity\ServiceApiKey
    {
        $model = $this->getService()->findApiKey($data);
        if ($model->getClientId() !== (int) $this->getIdentity()->id) {
            throw new \ClientFence\Exception('API key does not exist');
        }

        return $model;
    }
}
