<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

/**
 * Products management api.
 */

namespace ClientFence\Mod\Product\Api;

use ClientFence\Mod\Product\Entity\Product;

class Guest extends \ClientFence\Api\AbstractApi
{
    /**
     * Get paginated list of products.
     *
     * @return array
     */
    public function get_list($data)
    {
        $data['status'] = 'enabled';
        $data['show_hidden'] = false;

        return $this->pricedService()->getPaginatedProducts($data);
    }

    /**
     * Get products pairs. Product id -> title values.
     *
     * @return array
     */
    public function get_pairs($data)
    {
        $data['products_only'] = true;
        $data['active_only'] = true;
        $service = $this->getService();

        return $service->getPairs($data);
    }

    /**
     * Get product by ID.
     *
     * @return array
     *
     * @throws \ClientFence\Exception
     */
    public function get($data)
    {
        if (!isset($data['id']) && !isset($data['slug'])) {
            throw new \ClientFence\Exception('Product ID or slug is missing');
        }

        $id = $data['id'] ?? null;
        $slug = $data['slug'] ?? null;

        $service = $this->pricedService();
        if ($id) {
            $model = $service->findOneActiveById((int) $id);
        } else {
            $model = $service->findOneActiveBySlug($slug);
        }

        if (!$model instanceof Product) {
            // ClientFence core patch: 404, so a missing product page is "not found" rather than a server error.
            throw new \ClientFence\InformationException('Product not found', null, 404);
        }

        return $service->toApiArray($model);
    }

    /**
     * Get paginated list of product categories.
     *
     * @return array
     */
    public function category_get_list($data)
    {
        $data['status'] = 'enabled';

        return $this->pricedService()->getPaginatedProductCategories($data);
    }

    /**
     * Get pairs of product categories.
     *
     * @return array
     */
    public function category_get_pairs($data)
    {
        return $this->getService()->getProductCategoryPairs($data);
    }

    /**
     * ClientFence core patch: fixed currency prices (Product/CurrencyPrices.php). The product service
     * with prices in the visitor's currency (the cart's): the store multiplies them by its rate.
     */
    private function pricedService(): \ClientFence\Mod\Product\Service
    {
        try {
            $cart = $this->getDi()['mod_service']('cart');
            $currency = $cart instanceof \ClientFence\Mod\Cart\Service ? $cart->sessionCurrencyCode() : null;
        } catch (\Throwable) {
            $currency = null;
        }

        return $this->getService()->inCurrency($currency);
    }

    /**
     * ClientFence core patch: configurable options (Product/Options.php). The options an active product
     * offers on the order form, with their values and prices in the default currency (keys per
     * Options::PRICE_KEYS). `product_id`.
     */
    public function options($data): array
    {
        $model = $this->getService()->findOneActiveById((int) ($data['product_id'] ?? 0));
        if (!$model instanceof Product) {
            throw new \ClientFence\InformationException('Product not found');
        }

        return \ClientFence\Mod\Product\Options::forProduct($this->getDi(), (int) $model->getId());
    }
}
