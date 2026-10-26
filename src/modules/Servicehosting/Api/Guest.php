<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Servicehosting\Api;

use ClientFence\Mod\Product\Entity\Product;
use ClientFence\Validation\Api\RequiredParams;

/**
 * Hosting service management.
 */
class Guest extends \ClientFence\Api\AbstractApi
{
    /**
     * @param array $data
     *
     * @return array
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['product_id' => 'Product ID is missing'])]
    public function free_tlds($data = [])
    {
        $product_id = $data['product_id'] ?? 0;
        $product = $this->di['mod_service']('product')->findProductById((int) $product_id);

        if (!$product instanceof Product || $product->getType() !== \ClientFence\Mod\Product\Service::HOSTING) {
            $friendlyName = ucfirst(__trans('Product type'));

            throw new \ClientFence\Exception(':friendlyName: is invalid', [':friendlyName:' => $friendlyName]);
        }

        return $this->getService()->getFreeTlds($product);
    }

    /**
     * ClientFence: whether a hosting panel is on, so the client area shows the Hosting accounts page
     * in its menu (Panel/Panels.php).
     */
    public function panel_on(): bool
    {
        return \ClientFence\Mod\Servicehosting\Panel\Panels::anyOn($this->getDi());
    }
}
