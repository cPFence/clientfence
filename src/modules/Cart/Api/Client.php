<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Cart\Api;

/**
 * Shopping cart management.
 */
class Client extends \ClientFence\Api\AbstractApi
{
    /**
     * Checkout a shopping cart that has products in it.
     *
     * @param array $data Checkout data
     */
    public function checkout($data)
    {
        $this->getDi()['rate_limiter']->consumeOrThrow('order_generation_ip', (string) $this->getIp());

        $gateway_id = $data['gateway_id'] ?? null;
        $cart = $this->getService()->getSessionCart();
        $client = $this->getIdentity();

        return $this->getService()->checkoutCart($cart, $client, $gateway_id);
    }

    /**
     * ClientFence core patch (CF-239): the registrant details a domain registration or transfer needs that the client's
     * profile lacks (the checkout and cart pages ask them).
     *
     * @return list<string> field names (address_1, city, postcode, phone, country)
     */
    public function registrant_missing($data): array
    {
        return $this->getService()->registrantMissing($this->getIdentity());
    }
}
