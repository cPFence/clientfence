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

    private function getOwnApiKey(array $data): \ClientFence\Mod\Serviceapikey\Entity\ServiceApiKey
    {
        $model = $this->getService()->findApiKey($data);
        if ($model->getClientId() !== (int) $this->getIdentity()->id) {
            throw new \ClientFence\Exception('API key does not exist');
        }

        return $model;
    }
}
