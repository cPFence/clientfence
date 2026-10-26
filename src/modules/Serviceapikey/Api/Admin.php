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

class Admin extends \ClientFence\Api\AbstractApi
{
    /**
     * Update an API key. Can be used to change the config, but not to reset / regenerate the key itself.
     *
     * @param array $data - An associative array
     *                    - int 'order_id' (required) The order ID of the API key to reset.
     *                    - array 'config' (optional) The new configuration for the API key. Overrides the previous one, so you must send the complete config rather than only the parameters you want to change.
     */
    public function update($data): bool
    {
        $this->checkPermissions('serviceapikey', 'manage');

        return $this->getService()->updateApiKey($data);
    }

    /**
     * Used to reset / regenerate an API key. Useful in the event one is accidentally leaked.
     *
     * @param array $data - An associative array containing either the key or ID of whatever API key you want to reset.
     *                    - string 'key' The API key to reset.
     *                    - int 'order_id' The order ID of the API key to reset.
     */
    public function reset($data): bool
    {
        $this->checkPermissions('serviceapikey', 'manage');

        return $this->getService()->resetApiKey($this->getService()->findApiKey($data));
    }

    /**
     * ClientFence core patch (ext serviceapikey): staff set the IPs or CIDR ranges (order_id, allowed_ips: one per line, at most 20, empty: all) a key may be checked from.
     */
    public function update_ips($data): bool
    {
        $this->checkPermissions('serviceapikey', 'manage');

        return $this->getService()->setAllowedIps($this->getService()->findApiKey($data), $data['allowed_ips'] ?? '');
    }
}
