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
 * Products management.
 */

namespace ClientFence\Mod\Product\Api;

use ClientFence\Mod\Product\Entity\Product;
use ClientFence\PaginationOptions;
use ClientFence\Validation\Api\RequiredParams;

class Admin extends \ClientFence\Api\AbstractApi
{
    /**
     * Get paginated list of products.
     *
     * @return array
     */
    public function get_list($data)
    {
        $this->checkPermissions('product', 'view');

        return $this->getService()->getPaginatedProducts($data, $this->getIdentity());
    }

    /**
     * Get product pair. Id -> title values.
     *
     * @return array
     */
    public function get_pairs($data)
    {
        $this->checkPermissions('product', 'view');

        $service = $this->getService();

        return $service->getPairs($data);
    }

    /**
     * Get product details.
     *
     * @return array
     */
    public function get($data)
    {
        $this->checkPermissions('product', 'view');

        $model = $this->getService()->findProductById((int) $data['id']);
        $service = $this->getService();

        return $service->toApiArray($model, true, $this->getIdentity());
    }

    /**
     * Get installed product types.
     *
     * @return array
     */
    public function get_types()
    {
        $this->checkPermissions('product', 'view');

        return $this->getService()->getTypes();
    }

    /**
     * Create new product. Set default values depending on type.
     *
     * @optional string $product_category_id - category id
     *
     * @return int - new product id
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['title' => 'You must specify a title', 'type' => 'Type was not passed'])]
    public function prepare($data): int
    {
        $this->checkPermissions('product', 'manage_products');

        $service = $this->getService();
        // allow having only one domain product
        if ($data['type'] == 'domain') {
            $model = $service->getMainDomainProduct();
            if ($model !== null) {
                throw new \ClientFence\InformationException('You have already created domain product.', null, 413);
            }
        }

        $types = $service->getTypes();
        if (!array_key_exists($data['type'], $types)) {
            throw new \ClientFence\Exception('Product type :type is not registered.', [':type' => $data['type']], 413);
        }

        $categoryId = $data['product_category_id'] ?? null;

        return (int) $service->createProduct($data['title'], $data['type'], $categoryId);
    }

    /**
     * Update product settings.
     *
     * @optional array $pricing - product pricing configuration
     * @optional array $config - product configuration options depending on type
     * @optional array $upgrades - array of upgradable products
     * @optional array $addons - array of addon products
     * @optional int $product_category_id - product category id
     * @optional string $title - product title
     * @optional string $description - detailed product description
     * @optional string $icon_url - product icon
     * @optional string $status - product status
     * @optional string $slug - product slug. Used to create unique link to order page
     * @optional string $setup - product setup option. Define when order must be activated.
     * @optional bool $hidden - product visibility flag
     * @optional bool $stock_control - product stock control flag.
     * @optional bool $allow_quantity_select - client can select product quantity on order form flag
     * @optional bool $quantity_in_stock - quantity available for sale. When out of stock, new order cannot be placed.
     *
     * @return bool
     *
     * @throws \ClientFence\Exception
     */
    // ClientFence core patch (0.8.9 port): the dispatcher validates parameters on the public mutation methods.
    #[RequiredParams(['id' => 'Product ID was not passed'])]
    public function update($data)
    {
        $this->checkPermissions('product', 'manage_products');

        $model = $this->_getProduct($data);
        $service = $this->getService();
        // ClientFence core patch: fixed currency prices (Product/CurrencyPrices.php), saved with the
        // product's pricing (partial_pricing.html.twig): first, so a wrong price changes nothing.
        if (is_array($data['currency_prices'] ?? null)) {
            \ClientFence\Mod\Product\CurrencyPrices::ensure($this->getDi());
            \ClientFence\Mod\Product\CurrencyPrices::save($this->getDi()['em']->getConnection(), 'product', (int) $model->getId(), $data['currency_prices']);
        }

        return $service->updateProduct($model, $data);
    }

    /**
     * Change products sorting order.
     *
     * @return bool
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['priority' => 'priority params is missing'])]
    public function update_priority($data)
    {
        $this->checkPermissions('product', 'manage_products');

        if (!is_array($data['priority'] ?? null)) {
            throw new \ClientFence\Exception('priority params is missing');
        }

        $service = $this->getService();

        return $service->updatePriority($data);
    }

    /**
     * Convenience method to update product config only.
     *
     * @optional array $config - product config key value array
     *
     * @return bool
     */
    #[RequiredParams(['id' => 'Product ID was not passed'])]
    public function update_config($data)
    {
        $this->checkPermissions('product', 'manage_products');

        $model = $this->_getProduct($data);

        $service = $this->getService();

        return $service->updateConfig($model, $data);
    }

    /**
     * Get available addons.
     *
     * @return array
     */
    public function addon_get_pairs($data)
    {
        $this->checkPermissions('product', 'view');

        return $this->getService()->getAddons();
    }

    /**
     * Create new addon.
     *
     * @return int - new addon id
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['title' => 'You must specify a title'])]
    public function addon_create($data)
    {
        $this->checkPermissions('product', 'manage_products');

        $title = $data['title'];
        $status = $data['status'] ?? null;
        $setup = $data['setup'] ?? null;
        $iconUrl = $data['icon_url'] ?? null;
        $description = $data['description'] ?? null;

        $service = $this->getService();

        return $service->createAddon($title, $description, $setup, $status, $iconUrl);
    }

    /**
     * Get addon details.
     *
     * @return array
     *
     * @throws \ClientFence\InformationException
     */
    #[RequiredParams(['id' => 'Addon ID was not passed'])]
    public function addon_get($data)
    {
        $this->checkPermissions('product', 'view');

        $model = $this->getService()->findProductById((int) $data['id']);
        if (!$model instanceof Product || !$model->isAddon()) {
            throw new \ClientFence\InformationException('Addon not found');
        }
        $service = $this->getService();

        return $service->toApiArray($model, true, $this->getIdentity());
    }

    /**
     * Addon update.
     *
     * @optional array $pricing - product pricing configuration
     * @optional array $config - product configuration options depending on type
     * @optional array $upgrades - array of upgradable products
     * @optional array $addons - array of addon products
     * @optional int $product_category_id - product category id
     * @optional string $title - product title
     * @optional string $description - detailed product description
     * @optional string $icon_url - product icon
     * @optional string $status - product status
     * @optional string $slug - product slug. Used to create unique link to order page
     * @optional string $setup - product setup option. Define when order must be activated.
     * @optional bool $hidden - product visibility flag
     * @optional bool $stock_control - product stock control flag.
     * @optional bool $allow_quantity_select - client can select product quantity on order form flag
     * @optional bool $quantity_in_stock - quantity available for sale. When out of stock, new order cannot be placed.
     *
     * @return bool
     *
     * @throws \ClientFence\InformationException
     */
    #[RequiredParams(['id' => 'Addon ID was not passed'])]
    public function addon_update($data)
    {
        $this->checkPermissions('product', 'manage_products');

        $model = $this->getService()->findProductById((int) $data['id']);
        if (!$model instanceof Product || !$model->isAddon()) {
            throw new \ClientFence\InformationException('Addon not found');
        }
        $this->di['logger']->info('Updated addon #%s', $model->getId());

        return $this->update($data);
    }

    /**
     * Remove addon.
     *
     * @return bool
     */
    #[RequiredParams(['id' => 'Addon ID was not passed'])]
    public function addon_delete($data)
    {
        $this->checkPermissions('product', 'manage_products');

        return $this->delete($data);
    }

    /**
     * Remove product.
     *
     * @return bool
     */
    #[RequiredParams(['id' => 'Product ID was not passed'])]
    public function delete($data)
    {
        $this->checkPermissions('product', 'manage_products');

        $model = $this->_getProduct($data);
        $service = $this->getService();
        $productId = (int) $model->getId();

        $result = $service->deleteProduct($model);
        // ClientFence core patch: the deleted product's option groups are detached (Product/Options.php).
        if (\ClientFence\Mod\Product\Options::productGroups($this->getDi(), $productId) !== []) {
            \ClientFence\Mod\Product\Options::setProductGroups($this->getDi(), $productId, []);
        }

        return $result;
    }

    /**
     * Get product category pairs.
     *
     * @return array
     */
    public function category_get_pairs($data)
    {
        $this->checkPermissions('product', 'view');

        return $this->getService()->getProductCategoryPairs($data);
    }

    /**
     * Method to update category.
     *
     * @optional string $title - category title
     * @optional string $icon_url - icon url
     * @optional string $description - description
     *
     * @return bool
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Category ID was not passed'])]
    public function category_update($data)
    {
        $this->checkPermissions('product', 'manage_categories');

        $model = $this->getService()->findProductCategoryById((int) $data['id']);

        $title = $data['title'] ?? null;
        $description = $data['description'] ?? null;
        $icon_url = $data['icon_url'] ?? null;

        $service = $this->getService();

        return $service->updateCategory($model, $title, $description, $icon_url);
    }

    /**
     * Get category details.
     *
     * @return array
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Category ID was not passed'])]
    public function category_get($data)
    {
        $this->checkPermissions('product', 'manage_categories');

        $model = $this->getService()->findProductCategoryById((int) $data['id']);

        return $this->getService()->toProductCategoryApiArray($model, true, $this->getIdentity());
    }

    /**
     * Create new product category.
     *
     * @optional string $icon_url - icon url
     * @optional string $description - description
     *
     * @return int - new category id
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['title' => 'Category title is required'])]
    public function category_create($data): int
    {
        $this->checkPermissions('product', 'manage_categories');

        $service = $this->getService();

        $title = $data['title'] ?? null;
        $description = $data['description'] ?? null;
        $icon_url = $data['icon_url'] ?? null;

        return (int) $service->createCategory($title, $description, $icon_url);
    }

    /**
     * Remove product category.
     *
     * @return bool
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Category ID was not passed'])]
    public function category_delete($data)
    {
        $this->checkPermissions('product', 'manage_categories');

        $model = $this->getService()->findProductCategoryById((int) $data['id']);
        $service = $this->getService();

        return $service->removeProductCategory($model);
    }

    /**
     * Get product promo codes list.
     *
     * @return array
     */
    public function promo_get_list($data)
    {
        $this->checkPermissions('product', 'view');

        $service = $this->getService();
        $qb = $service->getPromoSearchQueryBuilder($data);
        $pager = $this->di['pager']->paginateDoctrineQuery($qb, PaginationOptions::fromArray($data));

        foreach ($pager['list'] as $key => $item) {
            $pager['list'][$key] = $this->getService()->enrichPromoApiArray($item);
        }

        return $pager;
    }

    /**
     * Create new promo code.
     *
     * @optional array $products - list of product ids for which this promo code applies
     * @optional array $periods - list of period codes
     * @optional bool $active - flag to enable/disable promo code
     * @optional bool $freesetup - flag to enable/disable free setup price
     * @optional bool $once_per_client - flag to enable/disable promo code usage once per client
     * @optional bool $recurring - is available for all recurring orders not for first order only
     * @optional int $maxuses - how many times this promo code can be used
     * @optional string $start_at - date (Y-m-d) when will this promo code be active
     * @optional string $end_at - date (Y-m-d) when this promo code expires
     *
     * @return int - new promo code id
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams([
        'code' => 'Promo code is missing',
        'type' => 'Promo type is missing',
        'value' => 'Promo value is missing',
    ])]
    public function promo_create($data): int
    {
        $this->checkPermissions('product', 'manage_promos');

        $products = [];
        if (isset($data['products']) && is_array($data['products'])) {
            $products = $data['products'];
        }
        $periods = [];
        if (isset($data['periods']) && is_array($data['periods'])) {
            $periods = $data['periods'];
        }

        $clientGroups = [];
        if (isset($data['client_groups']) && is_array($data['client_groups'])) {
            $clientGroups = $data['client_groups'];
        }
        $service = $this->getService();

        $id = (int) $service->createPromo($data['code'], $data['type'], $data['value'], $products, $periods, $clientGroups, $data);
        // ClientFence core patch: "Also discount configurable options" (Product/Options.php).
        if (!empty($data['include_options'])) {
            \ClientFence\Mod\Product\Options::ensure($this->getDi());
            \ClientFence\Mod\Product\Options::setPromoCoversOptions($this->getDi(), $id, true);
        }

        return $id;
    }

    /**
     * Get promo code details.
     *
     * @return array
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Promo ID was not passed'])]
    public function promo_get($data)
    {
        $this->checkPermissions('product', 'view');

        $id = $data['id'] ?? null;
        $model = $this->getService()->findPromoById((int) $id);

        $result = $this->getService()->toPromoApiArray($model, true, $this->getIdentity());
        // ClientFence core patch: the promo's "Also discount configurable options" switch (Product/Options.php).
        $result['include_options'] = \ClientFence\Mod\Product\Options::promoCoversOptions($this->getDi(), (int) $model->getId());

        return $result;
    }

    /**
     * Duplicate an existing promo code.
     *
     * @return int - ID of the new promo code
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Promo ID was not passed'])]
    public function promo_duplicate($data)
    {
        $this->checkPermissions('product', 'manage_promos');

        $service = $this->getService();
        $model = $service->findPromoById((int) $data['id']);

        $id = $service->duplicatePromo($model);
        // ClientFence core patch: the copy keeps "Also discount configurable options" (Product/Options.php).
        if (\ClientFence\Mod\Product\Options::promoCoversOptions($this->getDi(), (int) $model->getId())) {
            \ClientFence\Mod\Product\Options::setPromoCoversOptions($this->getDi(), (int) $id, true);
        }

        return $id;
    }

    /**
     * Get promo redemption history.
     *
     * @return array
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['promo_id' => 'Promo ID is missing'])]
    public function promo_redemption_get_list($data)
    {
        $this->checkPermissions('product', 'view');
        $this->checkPermissions('client', 'view');
        $this->checkPermissions('order', 'view');
        $this->checkPermissions('invoice', 'view');

        /** @var \ClientFence\Mod\Product\Repository\PromoRedemptionRepository $repo */
        $repo = $this->getService()->getPromoRedemptionRepository();

        $qb = $repo->getSearchQueryBuilder($data);
        $pager = $this->di['pager']->paginateDoctrineQuery($qb, PaginationOptions::fromArray($data));

        foreach ($pager['list'] as $key => $item) {
            $pager['list'][$key] = $this->getService()->enrichPromoRedemptionApiArray($item);
        }

        return $pager;
    }

    /**
     * Promo code update.
     *
     * @optional string $code - promo code
     * @optional string $type - promo code type: percentage|absolute
     * @optional string $value - promo code value. Percents or discount amount in currency
     * @optional array $products - list of product ids for which this promo code applies
     * @optional array $periods - list of period codes
     * @optional bool $active - flag to enable/disable promo code
     * @optional bool $freesetup - flag to enable/disable free setup price
     * @optional bool $once_per_client - flag to enable/disable promo code usage once per client
     * @optional bool $recurring - is available for all recurring orders not for first order only
     * @optional int $maxuses - how many times this promo code can be used
     * @optional string $start_at - date (Y-m-d) when will this promo code be active
     * @optional string $end_at - date (Y-m-d) when this promo code expires
     * @optional int $used - how many times this promo code was already used
     *
     * @return bool
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Promo ID was not passed'])]
    public function promo_update($data)
    {
        $this->checkPermissions('product', 'manage_promos');

        $service = $this->getService();
        $model = $service->findPromoById((int) $data['id']);

        $result = $service->updatePromo($model, $data);
        // ClientFence core patch: "Also discount configurable options" (Product/Options.php).
        if (array_key_exists('include_options', $data)) {
            \ClientFence\Mod\Product\Options::ensure($this->getDi());
            \ClientFence\Mod\Product\Options::setPromoCoversOptions($this->getDi(), (int) $model->getId(), in_array($data['include_options'], [true, 1, '1', 'on'], true));
        }

        return $result;
    }

    /**
     * Delete promo code.
     *
     * @return bool
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Promo ID was not passed'])]
    public function promo_delete($data)
    {
        $this->checkPermissions('product', 'manage_promos');

        $model = $this->getService()->findPromoById((int) $data['id']);
        $promoId = (int) $model->getId();

        $result = $this->getService()->deletePromo($model);
        // ClientFence core patch: its "Also discount configurable options" switch goes too (Product/Options.php).
        if (\ClientFence\Mod\Product\Options::promoCoversOptions($this->getDi(), $promoId)) {
            \ClientFence\Mod\Product\Options::setPromoCoversOptions($this->getDi(), $promoId, false);
        }

        return $result;
    }

    // ClientFence core patch: configurable options (Product/Options.php), as WHMCS's: option groups with
    // their options and prices (Products > Configurable options) and the groups of a product (its
    // Options tab). Reading needs "View products", changing "Manage products".

    /**
     * ClientFence: the option groups, each with its number of options and its products.
     */
    public function option_group_list($data): array
    {
        $this->checkPermissions('product', 'view');
        \ClientFence\Mod\Product\Options::ensure($this->getDi());

        return \ClientFence\Mod\Product\Options::groups($this->getDi());
    }

    /**
     * ClientFence: an option group with its options, values, prices and products. `id`.
     */
    #[RequiredParams(['id' => 'Option group ID was not passed'])]
    public function option_group_get($data): array
    {
        $this->checkPermissions('product', 'view');
        \ClientFence\Mod\Product\Options::ensure($this->getDi());

        return \ClientFence\Mod\Product\Options::group($this->getDi(), (int) $data['id']);
    }

    /**
     * ClientFence: creates (no `id`) or updates an option group: `title`, `description`. Returns its id.
     */
    public function option_group_save($data): int
    {
        $this->checkPermissions('product', 'manage_products');
        \ClientFence\Mod\Product\Options::ensure($this->getDi());
        $id = \ClientFence\Mod\Product\Options::saveGroup($this->getDi(), (array) $data);
        $this->di['logger']->info('Saved option group #%s', $id);

        return $id;
    }

    /**
     * ClientFence: deletes an option group with its options. Services keep the options they have. `id`.
     */
    #[RequiredParams(['id' => 'Option group ID was not passed'])]
    public function option_group_delete($data): bool
    {
        $this->checkPermissions('product', 'manage_products');
        \ClientFence\Mod\Product\Options::ensure($this->getDi());
        \ClientFence\Mod\Product\Options::deleteGroup($this->getDi(), (int) $data['id']);
        $this->di['logger']->info('Deleted option group #%s', (int) $data['id']);

        return true;
    }

    /**
     * ClientFence: creates (no `id`, with `group_id`) or updates an option with its values
     * (Options::saveOption()). Returns its id.
     */
    public function option_save($data): int
    {
        $this->checkPermissions('product', 'manage_products');
        \ClientFence\Mod\Product\Options::ensure($this->getDi());
        $id = \ClientFence\Mod\Product\Options::saveOption($this->getDi(), (array) $data);
        $this->di['logger']->info('Saved configurable option #%s', $id);

        return $id;
    }

    /**
     * ClientFence: deletes an option with its values. Services keep the option they have. `id`.
     */
    #[RequiredParams(['id' => 'Option ID was not passed'])]
    public function option_delete($data): bool
    {
        $this->checkPermissions('product', 'manage_products');
        \ClientFence\Mod\Product\Options::ensure($this->getDi());
        \ClientFence\Mod\Product\Options::deleteOption($this->getDi(), (int) $data['id']);
        $this->di['logger']->info('Deleted configurable option #%s', (int) $data['id']);

        return true;
    }

    /**
     * ClientFence: the option groups of a product (`product_id`) and all groups to choose from.
     */
    #[RequiredParams(['product_id' => 'Product ID was not passed'])]
    public function product_options_get($data): array
    {
        $this->checkPermissions('product', 'view');
        \ClientFence\Mod\Product\Options::ensure($this->getDi());
        $product = $this->getService()->findProductById((int) $data['product_id']);

        return [
            'groups' => \ClientFence\Mod\Product\Options::productGroups($this->getDi(), (int) $product->getId()),
            'all_groups' => \ClientFence\Mod\Product\Options::groups($this->getDi()),
        ];
    }

    /**
     * ClientFence: sets the option groups of a product: `product_id`, `groups` (a list of group ids).
     */
    #[RequiredParams(['product_id' => 'Product ID was not passed'])]
    public function product_options_save($data): bool
    {
        $this->checkPermissions('product', 'manage_products');
        \ClientFence\Mod\Product\Options::ensure($this->getDi());
        $product = $this->getService()->findProductById((int) $data['product_id']);
        $groups = is_array($data['groups'] ?? null) ? $data['groups'] : [];
        \ClientFence\Mod\Product\Options::setProductGroups($this->getDi(), (int) $product->getId(), $groups);
        $this->di['logger']->info('Saved the option groups of product #%s', (int) $product->getId());

        return true;
    }

    #[RequiredParams(['id' => 'Product ID was not passed'])]
    /**
     * ClientFence core patch: fixed currency prices (Product/CurrencyPrices.php). An item's fixed
     * prices in the currencies other than the default one. `kind` (product or tld), `id`.
     *
     * @return array<string, mixed>
     */
    public function currency_prices_get($data): array
    {
        $kind = $this->currencyPriceItem($data, false);
        \ClientFence\Mod\Product\CurrencyPrices::ensure($this->getDi());

        return \ClientFence\Mod\Product\CurrencyPrices::page($this->getDi()['em']->getConnection(), $kind, (int) $data['id']);
    }

    /**
     * ClientFence core patch: fixed currency prices (Product/CurrencyPrices.php). Replaces an item's
     * fixed prices: `kind`, `id`, `prices[currency][period][price|setup_price]` (blank: converts at
     * the rate).
     */
    public function currency_prices_save($data): bool
    {
        $kind = $this->currencyPriceItem($data, true);
        \ClientFence\Mod\Product\CurrencyPrices::ensure($this->getDi());
        \ClientFence\Mod\Product\CurrencyPrices::save($this->getDi()['em']->getConnection(), $kind, (int) $data['id'], is_array($data['prices'] ?? null) ? $data['prices'] : []);
        $this->getDi()['logger']->info('Updated the fixed currency prices of %s #%s', $kind, (int) $data['id']);

        return true;
    }

    /** The checked kind of a currency price call: the staff permission and the item must exist. */
    private function currencyPriceItem(array $data, bool $change): string
    {
        $kind = (string) ($data['kind'] ?? '');
        $id = (int) ($data['id'] ?? 0);
        if ($kind === 'tld') {
            $this->checkPermissions('servicedomain', 'manage_tlds');
            $tld = $id > 0 ? $this->getDi()['db']->load('Tld', $id) : null;
            if (!$tld instanceof \Model_Tld) {
                throw new \ClientFence\InformationException('TLD not found');
            }

            return $kind;
        }
        if ($kind !== 'product') {
            throw new \ClientFence\InformationException('Unknown item.');
        }
        $this->checkPermissions('product', $change ? 'manage_products' : 'view');
        $this->getService()->findProductById($id);

        return $kind;
    }

    private function _getProduct($data)
    {
        return $this->getService()->findProductById((int) $data['id']);
    }
}
