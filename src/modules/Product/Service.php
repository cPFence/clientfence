<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Product;

use ClientFence\Mod\Cart\Entity\CartProduct;
use ClientFence\Mod\Client\Entity\Client;
use ClientFence\Mod\Order\Entity\Order;
use ClientFence\Mod\Product\Entity\Product;
use ClientFence\Mod\Product\Entity\ProductCategory;
use ClientFence\Mod\Product\Entity\ProductPayment;
use ClientFence\Mod\Product\Entity\Promo;
use ClientFence\Mod\Product\Entity\PromoRedemption;
use ClientFence\Mod\Product\Repository\DomainPricingRepository;
use ClientFence\Mod\Product\Repository\ProductCategoryRepository;
use ClientFence\Mod\Product\Repository\ProductOrderRepository;
use ClientFence\Mod\Product\Repository\ProductPaymentRepository;
use ClientFence\Mod\Product\Repository\ProductRepository;
use ClientFence\Mod\Product\Repository\PromoRedemptionRepository;
use ClientFence\Mod\Product\Repository\PromoRepository;
use ClientFence\Mod\Staff\Entity\Admin;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\QueryBuilder;
use ClientFence\InjectionAwareInterface;
use ClientFence\PaginationOptions;

class Service implements InjectionAwareInterface
{
    final public const string CUSTOM = 'custom';
    final public const string LICENSE = 'license';
    final public const string ADDON = 'addon';
    final public const string DOMAIN = 'domain';
    final public const string DOWNLOADABLE = 'downloadable';
    final public const string HOSTING = 'hosting';
    final public const string VPS = 'vps';
    final public const string APIKEY = 'apikey';

    final public const string SETUP_AFTER_ORDER = 'after_order';
    final public const string SETUP_AFTER_PAYMENT = 'after_payment';
    final public const string SETUP_MANUAL = 'manual';

    protected ?\Pimple\Container $di = null;
    protected ?ProductRepository $productRepository = null;
    protected ?ProductCategoryRepository $productCategoryRepository = null;
    protected ?ProductPaymentRepository $productPaymentRepository = null;
    protected ?PromoRepository $promoRepository = null;
    protected ?PromoRedemptionRepository $promoRedemptionRepository = null;
    protected ?DomainPricingRepository $domainPricingRepository = null;
    protected ?ProductOrderRepository $productOrderRepository = null;
    /** ClientFence: the currency of inCurrency() (null: the default currency). */
    private ?string $priceCurrency = null;

    /**
     * ClientFence core patch: fixed currency prices (Product/CurrencyPrices.php). This service with
     * product and TLD prices in $currency: its fixed prices as their default-currency equivalents,
     * the rest unchanged. Callers convert at the rate as before; null or a currency without fixed
     * prices returns the service as it is.
     */
    public function inCurrency(?string $currency): static
    {
        $currency = $currency !== null && $currency !== '' && CurrencyPrices::rate($this->getDbalConnection(), $currency) !== null ? strtoupper($currency) : null;
        if ($currency === $this->priceCurrency) {
            return $this;
        }
        $copy = clone $this;
        $copy->priceCurrency = $currency;

        return $copy;
    }

    /** ClientFence: a default-currency amount, or the fixed price of inCurrency()'s currency (CurrencyPrices.php). */
    private function currencyBase(string $kind, int $refId, string $period, string $field, float $base): float
    {
        if ($this->priceCurrency === null) {
            return $base;
        }

        return CurrencyPrices::base($this->getDbalConnection(), $kind, $refId, $this->priceCurrency, $period, $field, $base);
    }

    /** ClientFence: a TLD's price for an action (register, renew, transfer), per year, in inCurrency()'s terms. */
    private function tldPrice(\Model_Tld $tld, string $action): float
    {
        $field = match ($action) {
            'register' => 'price_registration',
            'renew' => 'price_renew',
            default => 'price_transfer',
        };

        return $this->currencyBase('tld', (int) $tld->id, $action, 'price', (float) $tld->{$field});
    }

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function getPromoRedemptionRepository(): PromoRedemptionRepository
    {
        if ($this->promoRedemptionRepository === null) {
            if ($this->di === null) {
                throw new \ClientFence\Exception('The dependency injection container has not been set.');
            }

            $this->promoRedemptionRepository = $this->di['em']->getRepository(PromoRedemption::class);
        }

        return $this->promoRedemptionRepository;
    }

    public function getPromoRepository(): PromoRepository
    {
        if ($this->promoRepository === null) {
            if ($this->di === null) {
                throw new \ClientFence\Exception('The dependency injection container has not been set.');
            }

            $this->promoRepository = $this->di['em']->getRepository(Promo::class);
        }

        return $this->promoRepository;
    }

    public function getProductRepository(): ProductRepository
    {
        if ($this->productRepository === null) {
            if ($this->di === null) {
                throw new \ClientFence\Exception('The dependency injection container has not been set.');
            }

            $this->productRepository = $this->di['em']->getRepository(Product::class);
        }

        return $this->productRepository;
    }

    public function getProductCategoryRepository(): ProductCategoryRepository
    {
        if ($this->productCategoryRepository === null) {
            if ($this->di === null) {
                throw new \ClientFence\Exception('The dependency injection container has not been set.');
            }

            $this->productCategoryRepository = $this->di['em']->getRepository(ProductCategory::class);
        }

        return $this->productCategoryRepository;
    }

    public function getProductPaymentRepository(): ProductPaymentRepository
    {
        if ($this->productPaymentRepository === null) {
            if ($this->di === null) {
                throw new \ClientFence\Exception('The dependency injection container has not been set.');
            }

            $this->productPaymentRepository = $this->di['em']->getRepository(ProductPayment::class);
        }

        return $this->productPaymentRepository;
    }

    public function getDomainPricingRepository(): DomainPricingRepository
    {
        $this->domainPricingRepository ??= new DomainPricingRepository($this->getDbalConnection());

        return $this->domainPricingRepository;
    }

    public function getProductOrderRepository(): ProductOrderRepository
    {
        $this->productOrderRepository ??= new ProductOrderRepository($this->getDbalConnection());

        return $this->productOrderRepository;
    }

    /**
     * @return mixed[]
     */
    public function getModulePermissions(): array
    {
        return [
            'view' => [
                'type' => 'bool',
                'display_name' => __trans('View products'),
                'description' => __trans('Allows the staff member to view products, categories, and promotions.'),
            ],
            'manage_products' => [
                'type' => 'bool',
                'display_name' => __trans('Manage products'),
                'description' => __trans('Allows the staff member to create, update, and delete products.'),
            ],
            'manage_categories' => [
                'type' => 'bool',
                'display_name' => __trans('Manage product categories'),
                'description' => __trans('Allows the staff member to create, update, and delete product categories.'),
            ],
            'manage_promos' => [
                'type' => 'bool',
                'display_name' => __trans('Manage promotions'),
                'description' => __trans('Allows the staff member to create, update, and delete promotional codes.'),
            ],
        ];
    }

    public function getPairs($data): array
    {
        return $this->getProductRepository()->getPairs($data);
    }

    public function toApiArray(Product $model, $deep = true, $identity = null): array
    {
        $config = json_decode($model->getConfig() ?? '', true) ?? [];
        $pricing = $this->getProductPricingArray($model);
        $starting_from = $this->getStartingFromPrice($model);
        $isAdmin = $identity instanceof Admin || $identity instanceof \Model_Admin;
        $addons = $this->getAddonsApiArray($model, $isAdmin);

        $result = [
            'id' => $model->getId(),
            'product_category_id' => $model->getProductCategoryId(),
            'type' => $model->getType(),
            'title' => $model->getTitle(),
            'slug' => $model->getSlug(),
            'description' => $model->getDescription(),
            'unit' => $this->getProductUnit($model),
            'priority' => $model->getPriority(),
            'pricing' => $isAdmin ? $pricing : $this->getPublicPricing($pricing),
            'config' => $isAdmin ? $config : $this->getPublicConfig($config),
            'addons' => $addons,

            'price_starting_from' => $starting_from,
            'icon_url' => $model->getIconUrl(),

            // stock control
            'allow_quantity_select' => $model->isAllowQuantitySelect(),

            // Exposed publicly so the order form can be fetched during guest checkout.
            'form_id' => $model->getFormId(),
        ];

        if ($isAdmin) {
            $result['created_at'] = $this->formatDateTimeValue($model->getCreatedAt());
            $result['updated_at'] = $this->formatDateTimeValue($model->getUpdatedAt());
            $result['addons'] = $addons;
            $result['quantity_in_stock'] = $model->getQuantityInStock();
            $result['stock_control'] = $model->isStockControl();
            $result['upgrades'] = $this->getUpgradablePairs($model);
            $result['status'] = $model->getStatus();
            $result['hidden'] = $model->isHidden();
            $result['setup'] = $model->getSetup();
            if ($model->getProductCategoryId()) {
                $productCategory = $this->findProductCategoryById((int) $model->getProductCategoryId());
                $result['category'] = [
                    'id' => $productCategory->getId(),
                    'title' => $productCategory->getTitle(),
                ];
            }
        }

        return $result;
    }

    private function getPublicConfig(array $config): array
    {
        $publicConfigKeys = [
            'allow_domain_register',
            'allow_domain_transfer',
            'allow_domain_own',
            'allow_subdomain',
            'subdomain_base_domain',
        ];

        return array_intersect_key($config, array_flip($publicConfigKeys));
    }

    private function getPublicPricing(array $pricing): array
    {
        foreach ($pricing as $key => $value) {
            if ($key === 'registrar') {
                unset($pricing[$key]);

                continue;
            }

            if (is_array($value)) {
                $pricing[$key] = $this->getPublicPricing($value);
            }
        }

        return $pricing;
    }

    public function getTypes(): array
    {
        // ClientFence core patch: translated labels (the product list showed them in English in every language).
        $data = [
            self::CUSTOM => __trans('Custom'),
            self::LICENSE => __trans('License'),
            self::DOWNLOADABLE => __trans('Downloadable'),
            self::HOSTING => __trans('Hosting'),
            self::DOMAIN => __trans('Domain'),
        ];

        // attach service modules
        $extensionService = $this->di['mod_service']('extension');
        $list = $extensionService->getInstalledMods();
        foreach ($list as $mod) {
            if (str_starts_with((string) $mod, 'service')) {
                $n = substr((string) $mod, strlen('service'));
                $data[$n] = $n === 'apikey' ? __trans('API Key') : ucfirst($n);
            }
        }

        return $data;
    }

    public function getMainDomainProduct(): ?Product
    {
        return $this->getProductRepository()->findMainDomainProduct();
    }

    public function getCartProductTitle(Product $product, ?array $config = null): string
    {
        $service = $this->getProductModuleService($product);
        if (method_exists($service, 'getCartProductTitle')) {
            return $service->getCartProductTitle($product, $config ?? []);
        }

        return (string) $product->getTitle();
    }

    public function validateSelectedAddonsForProduct(Product $product, array $addons): void
    {
        $validAddons = json_decode($product->getAddons() ?? '', true);
        if (empty($validAddons)) {
            $validAddons = [];
        }

        foreach ($addons as $addonId => $properties) {
            if (!($properties['selected'] ?? false)) {
                continue;
            }

            $addon = $this->getAddonById((int) $addonId);
            if (!$addon instanceof Product || $addon->getStatus() !== 'enabled' || !in_array((int) $addonId, $validAddons)) {
                throw new \ClientFence\InformationException('One or more of your selected add-ons are invalid for the associated product.');
            }

            $requestedQuantity = max(1, (int) ($properties['quantity'] ?? 1));
            if ($requestedQuantity > 1 && !$addon->isAllowQuantitySelect()) {
                throw new \ClientFence\InformationException('One or more of your selected add-ons are invalid for the associated product.');
            }
        }
    }

    /**
     * @param bool $staff ClientFence core patch: a staff order (Cart\Service::staffOrder()) keeps staff fields (hosting import,
     *                    username) and skips the client order form check, as order/create
     */
    public function prepareCartProductConfig(Product $product, array $config, bool $staff = false): array
    {
        $service = $this->getProductModuleService($product);

        // Strip client-supplied keys the product-type service hasn't declared
        // as client-settable, preventing mass-assignment of admin-controlled
        // fields (e.g. hosting_plan_id, server_id) via the cart/add_item endpoint.
        if (!$staff && method_exists($service, 'clientSettableConfigKeys')) {
            // ClientFence core patch: order form answers. The product's own order form fields are kept too
            // (modules/Clientfence/Service.php orderFormKeys(): never a product setting or an order key).
            $allowedKeys = $service->clientSettableConfigKeys();
            $allowedKeys = array_merge($allowedKeys, \ClientFence\Mod\Clientfence\Service::orderFormKeys($this->di, $product, $config));
            // ClientFence core patch: configurable options (Product/Options.php): the client's choice.
            $allowedKeys[] = 'options';
            $config = $this->stripUnauthorizedConfigKeys($config, $allowedKeys);
        }

        // ClientFence core patch: configurable options (Product/Options.php). The choice is checked and
        // completed with the defaults here, so a wrong one fails when it is added to the cart; the
        // priced lines are made from the catalog only (never taken from the client).
        unset($config['option_lines']);
        $choice = is_array($config['options'] ?? null) ? $config['options'] : [];
        if (\ClientFence\Mod\Product\Options::forProduct($this->di, (int) $product->getId(), true) !== []) {
            $config['options'] = \ClientFence\Mod\Product\Options::resolve($this->di, (int) $product->getId(), $choice, $this->optionsPeriod($product, $config))['choice'];
        } else {
            unset($config['options']);
        }

        if (method_exists($service, 'attachOrderConfig')) {
            $config = $service->attachOrderConfig($product, $config);
        }

        if (method_exists($service, 'validateOrderData')) {
            $service->validateOrderData($config, $product);
        }

        if (!$staff && method_exists($service, 'validateCustomForm')) {
            $service->validateCustomForm($config, $this->getProductValidationData($product));
        }

        return $config;
    }

    /**
     * Remove top-level keys from the client-supplied cart config that are not
     * in the service's `clientSettableConfigKeys()` allowlist.
     *
     * @param array<string, mixed> $config      client-supplied cart config
     * @param list<string>         $allowedKeys service-defined allowlist of client-settable keys
     *
     * @return array<string, mixed>
     */
    private function stripUnauthorizedConfigKeys(array $config, array $allowedKeys): array
    {
        $allowedKeys = array_flip($allowedKeys);

        foreach (array_keys($config) as $key) {
            if (!isset($allowedKeys[$key])) {
                unset($config[$key]);
            }
        }

        return $config;
    }

    /**
     * @return array<mixed, array<'active'|'allow_register'|'allow_transfer'|'id'|'min_years'|'price_registration'|'price_renew'|'price_transfer'|'registrar'|'tld', mixed>>
     */
    public function getDomainPricingArray(): array
    {
        $pricing = $this->getDomainPricingRepository()->getActivePricingByTld();
        // ClientFence core patch: fixed currency prices (Product/CurrencyPrices.php).
        if ($this->priceCurrency !== null) {
            foreach ($pricing as $key => $tld) {
                $pricing[$key] = CurrencyPrices::tld($this->getDbalConnection(), $this->priceCurrency, $tld);
            }
        }

        return $pricing;
    }

    public function getProductPricingArray(Product $product): array
    {
        if ($product->getType() === self::DOMAIN) {
            return $this->getDomainPricingArray();
        }

        if ($product->getProductPaymentId()) {
            $productPayment = $this->getProductPaymentById((int) $product->getProductPaymentId());

            // ClientFence core patch: fixed currency prices (Product/CurrencyPrices.php).
            return CurrencyPrices::pricing($this->getDbalConnection(), (int) $product->getId(), $this->priceCurrency, $this->toProductPaymentApiArray($productPayment));
        }

        throw new \ClientFence\Exception('Product pricing could not be determined.');
    }

    public function getProductUnit(Product $product): string
    {
        if ($product->getType() === self::DOMAIN) {
            return 'year';
        }

        return $product->getUnit();
    }

    public function isRecurrentProductPricing(Product $product): bool
    {
        $pricing = $this->getProductPricingArray($product);

        return isset($pricing['type']) && $pricing['type'] == ProductPayment::RECURRENT;
    }

    public function isProductPeriodEnabled(Product $product, string $period): bool
    {
        $pricing = $this->getProductPricingArray($product);
        if (($pricing['type'] ?? null) == ProductPayment::RECURRENT) {
            return (bool) ($pricing['recurrent'][$period]['enabled'] ?? false);
        }

        return true;
    }

    public function getRelatedProductDiscount(Product $product, array $items, ?array $config = null): float
    {
        if ($product->getType() === self::DOMAIN) {
            return $this->getDomainRelatedDiscount($items, $config ?? []);
        }

        return 0.0;
    }

    public function getPaymentTypes(): array
    {
        return [
            ProductPayment::FREE => 'Free',
            ProductPayment::ONCE => 'One time',
            ProductPayment::RECURRENT => 'Recurrent',
        ];
    }

    public function createProduct($title, $type, $categoryId = null): int
    {
        $priority = $this->getProductRepository()->getMaxPriority();

        $productPayment = $this->createDefaultProductPayment();
        $paymentId = (int) $productPayment->getId();

        $slug = $this->generateUniqueProductSlug($title);

        $model = new Product();
        $model
            ->setProductPaymentId($paymentId)
            ->setProductCategoryId($categoryId !== null ? (int) $categoryId : null)
            ->setStatus('disabled')
            ->setTitle($title)
            ->setSlug($slug)
            ->setType($type)
            ->setSetup(self::SETUP_AFTER_PAYMENT)
            ->setPriority($priority + 10);

        $this->di['em']->persist($model);
        $this->di['em']->flush();
        $productId = $model->getId();
        $this->di['logger']->info('Created new product #%s', $model->getId());

        return (int) $productId;
    }

    public function updateProduct(Product $model, $data): bool
    {
        $this->validateIconUrl($data['icon_url'] ?? $model->getIconUrl());

        // pricing
        if (isset($data['pricing'])) {
            $types = $this->getPaymentTypes();

            if (!isset($data['pricing']['type']) || !array_key_exists($data['pricing']['type'], $types)) {
                throw new \ClientFence\InformationException('Pricing type is required');
            }
            $productPayment = $this->getProductPaymentById((int) $model->getProductPaymentId());
            $this->applyPricingToProductPayment($productPayment, $data['pricing']);
            $this->di['em']->flush();
        }

        if (isset($data['config']) && is_array($data['config'])) {
            $current = json_decode($model->getConfig() ?? '', true) ?? [];
            $c = array_merge($current, $data['config']);
            $model->setConfig(json_encode($c));
        }

        $form_id = $data['form_id'] ?? $model->getFormId();
        $productCategoryId = $data['product_category_id'] ?? $model->getProductCategoryId();

        $model->setProductCategoryId(empty($productCategoryId) ? null : (int) $productCategoryId);
        $model->setFormId(empty($form_id) ? null : (int) $form_id);
        $model->setIconUrl($data['icon_url'] ?? $model->getIconUrl());
        $model->setStatus((string) ($data['status'] ?? $model->getStatus()));
        $model->setHidden((bool) ($data['hidden'] ?? $model->isHidden()));
        $model->setSlug($data['slug'] ?? $model->getSlug());
        $model->setSetup((string) ($data['setup'] ?? $model->getSetup()));
        // remove empty value in data['upgrades];
        if (is_array($data['upgrades'] ?? null)) {
            $upgrades = array_values(array_filter($data['upgrades']));
            if (empty($upgrades)) {
                $model->setUpgrades(null);
            } else {
                $model->setUpgrades(json_encode($upgrades));
            }
        }
        if (is_array($data['addons'] ?? null)) {
            $addons = array_values(array_filter($data['addons']));
            if (empty($addons)) {
                $model->setAddons(null);
            } else {
                $model->setAddons(json_encode($addons));
            }
        }

        $model->setTitle($data['title'] ?? $model->getTitle());
        $model->setStockControl((bool) ($data['stock_control'] ?? $model->isStockControl()));
        $model->setAllowQuantitySelect((bool) ($data['allow_quantity_select'] ?? $model->isAllowQuantitySelect()));
        $model->setQuantityInStock((int) ($data['quantity_in_stock'] ?? $model->getQuantityInStock()));
        $model->setDescription($data['description'] ?? $model->getDescription());
        $model->setPlugin($data['plugin'] ?? $model->getPlugin());
        $model->setUpdatedAt(new \DateTime());

        $this->di['em']->flush();

        $this->di['logger']->info('Updated product #%s configuration', $model->getId());

        return true;
    }

    public function updatePriority($data): bool
    {
        foreach ($data['priority'] as $id => $p) {
            $model = $this->getProductRepository()->find((int) $id);
            if ($model instanceof Product) {
                $model->setPriority((int) $p);
                $model->setUpdatedAt(new \DateTime());
            }
        }

        $this->di['em']->flush();

        $this->di['logger']->info('Changed product priorities');

        return true;
    }

    public function updateConfig(Product $model, $data): bool
    {
        /* add new config value */
        $config = json_decode($model->getConfig() ?? '', true) ?? [];

        if (isset($data['config']) && is_array($data['config'])) {
            $config = array_intersect_key((array) $config, $data['config']);
            foreach ($data['config'] as $key => $val) {
                $config[$key] = $val;
                if (isset($config[$key]) && empty($val) && !is_numeric($val)) {
                    unset($config[$key]);
                }
            }
        }

        if (
            isset($data['new_config_name'])
            && isset($data['new_config_value'])
            && !empty($data['new_config_name'])
            && !empty($data['new_config_value'])
        ) {
            $config[$data['new_config_name']] = $data['new_config_value'];
        }

        $model->setConfig(json_encode($config));
        $model->setUpdatedAt(new \DateTime());
        $this->di['em']->flush();

        $this->di['logger']->info('Updated product #%s configuration', $model->getId());

        return true;
    }

    /**
     * @return mixed[]
     */
    public function getAddons(): array
    {
        return $this->getProductRepository()->getAddonPairs();
    }

    public function createAddon($title, $description = null, $setup = null, $status = null, $iconUrl = null): ?int
    {
        $this->validateIconUrl($iconUrl);

        $productPayment = $this->createDefaultProductPayment();
        $paymentId = (int) $productPayment->getId();

        $slug = $this->generateUniqueProductSlug($title);

        $model = new Product();
        $model
            ->setProductPaymentId($paymentId)
            ->setProductCategoryId(null)
            ->setStatus($status ?? 'disabled')
            ->setTitle($title)
            ->setSlug($slug)
            ->setType(self::CUSTOM)
            ->setSetup($setup ?? self::SETUP_AFTER_PAYMENT)
            ->setIsAddon(true)
            ->setIconUrl($iconUrl)
            ->setDescription($description);

        $this->di['em']->persist($model);
        $this->di['em']->flush();
        $productId = $model->getId();

        $this->di['logger']->info('Created new addon #%s', $productId);

        return $productId;
    }

    public function deleteProduct(Product $product): bool
    {
        $orderService = $this->di['mod_service']('order');
        if ($orderService->productHasOrders($product)) {
            throw new \ClientFence\InformationException('Cannot remove product which has active orders.');
        }
        $id = $product->getId();
        $this->di['em']->remove($product);
        $this->di['em']->flush();
        // ClientFence core patch: its fixed currency prices (Product/CurrencyPrices.php) go with it.
        CurrencyPrices::forgetIn($this->di, 'product', (int) $id);
        $this->di['logger']->info('Deleted product #%s', $id);

        return true;
    }

    public function getPaginatedProducts(array $data, $identity = null): array
    {
        return $this->di['pager']->paginateMappedQuery(
            $this->getProductSearchQueryBuilder($data),
            PaginationOptions::fromArray($data),
            fn (Product $product): array => $this->toApiArray($product, false, $identity),
        );
    }

    /**
     * @return mixed[]
     */
    public function getProductCategoryPairs(array $data = []): array
    {
        return $this->getProductCategoryRepository()->getPairs();
    }

    public function updateCategory(ProductCategory $productCategory, $title = null, $description = null, $icon_url = null): bool
    {
        $this->validateIconUrl($icon_url);

        // ClientFence core patch (0.8.9 port): omitted category fields retain their saved values.
        if ($title !== null) {
            $productCategory->setTitle($title);
        }
        if ($icon_url !== null) {
            $productCategory->setIconUrl($icon_url);
        }
        if ($description !== null) {
            $productCategory->setDescription($description);
        }
        $productCategory->setUpdatedAt(new \DateTime());
        $this->di['em']->flush();

        $this->di['logger']->info('Updated product category #%s', $productCategory->getId());

        return true;
    }

    public function createCategory($title, $description = null, $icon_url = null): ?int
    {
        $this->validateIconUrl($icon_url);

        $model = (new ProductCategory())
            ->setTitle($title)
            ->setDescription($description)
            ->setIconUrl($icon_url);
        $this->di['em']->persist($model);
        $this->di['em']->flush();
        $id = $model->getId();

        $this->di['logger']->info('Created new product category #%s', $id);

        return $id;
    }

    public function removeProductCategory(ProductCategory $category): bool
    {
        if ($this->getProductRepository()->hasProductsInCategory((int) $category->getId())) {
            throw new \ClientFence\InformationException('Cannot remove product category with products');
        }
        $id = $category->getId();
        $this->di['em']->remove($category);
        $this->di['em']->flush();

        $this->di['logger']->info('Deleted product category #%s', $id);

        return true;
    }

    public function getProductSearchQueryBuilder(array $data): QueryBuilder
    {
        return $this->getProductRepository()->getSearchQueryBuilder($data);
    }

    public function toProductCategoryApiArray(ProductCategory $model, $deep = true, $identity = null): array
    {
        $min_price = 0;
        $products = [];
        $pr = $this->getCategoryProducts($model);

        $type = null; // identified by first product in category
        foreach ($pr as $p) {
            $pa = $this->toApiArray($p, false, $identity);
            if (reset($pr) == $p) {
                $type = $p->getType();
            }
            $products[] = $pa;
            $startingPrice = $pa['price_starting_from'] ?? 0;

            if ($min_price == 0) {
                $min_price = $startingPrice;
            } elseif ($startingPrice < $min_price) {
                $min_price = $startingPrice;
            }
        }

        return [
            'id' => $model->getId(),
            'title' => $model->getTitle(),
            'description' => $model->getDescription(),
            'icon_url' => $model->getIconUrl(),
            'created_at' => $this->formatDateTimeValue($model->getCreatedAt()),
            'updated_at' => $this->formatDateTimeValue($model->getUpdatedAt()),
            'price_starting_from' => $min_price,
            'type' => $type,
            'products' => $products,
        ];
    }

    /**
     * @param int $id
     */
    public function findOneActiveById($id): ?Product
    {
        return $this->getProductRepository()->findActiveById((int) $id);
    }

    /**
     * @param string $slug
     */
    public function findOneActiveBySlug($slug): ?Product
    {
        return $this->getProductRepository()->findActiveBySlug((string) $slug);
    }

    public function getPaginatedProductCategories(array $data, $identity = null): array
    {
        return $this->di['pager']->paginateMappedQuery(
            $this->getProductCategorySearchQueryBuilder($data),
            PaginationOptions::fromArray($data),
            fn (ProductCategory $category): array => $this->toProductCategoryApiArray($category, true, $identity),
        );
    }

    public function getStartingFromPrice(Product $model)
    {
        // ClientFence core patch: fixed currency prices (Product/CurrencyPrices.php): the lowest
        // price as the pricing in that currency has it.
        if ($this->priceCurrency !== null) {
            return $this->startingFromPricing($model);
        }

        if ($model->getType() == self::DOMAIN) {
            return $this->getStartingDomainPrice();
        }

        if ($model->getProductPaymentId()) {
            $productPaymentModel = $this->getProductPaymentById((int) $model->getProductPaymentId());

            return $this->getStartingPrice($productPaymentModel);
        }

        return null;
    }

    /**
     * @return mixed[]
     */
    public function getUpgradablePairs(Product $model): array
    {
        $ids = json_decode($model->getUpgrades() ?? '', true);
        $pids = $this->getProductTitlesByIds($ids);
        unset($pids[$model->getId()]);

        return $pids;
    }

    public function getUpgradablePairsByProductId(int $productId): array
    {
        return $this->getUpgradablePairs($this->findProductById($productId));
    }

    public function canUpgradeTo(Product $model, Product $new): bool
    {
        if ($model->getId() === $new->getId()) {
            return false;
        }

        $pairs = $this->getUpgradablePairs($model);

        return array_key_exists($new->getId(), $pairs);
    }

    public function assertUpgradeAllowedByIds(int $productId, int $upgradeProductId): void
    {
        $product = $this->findProductById($productId);
        $allowedUpgrades = $this->getUpgradablePairsByProductId($productId);

        if (array_key_exists($upgradeProductId, $allowedUpgrades)) {
            return;
        }

        $upgrade = $this->findProductById($upgradeProductId);

        throw new \ClientFence\InformationException('Sorry, but ":product" is not allowed to be upgraded to ":upgrade"', [':product' => $product->getTitle() ?? 'unknown', ':upgrade' => $upgrade->getTitle() ?? 'unknown']);
    }

    /**
     * @return mixed[]
     */
    public function getProductTitlesByIds($ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $result = [];
        foreach ($this->getProductRepository()->findByIds($this->normalizeProductIds((array) $ids)) as $product) {
            $productId = $product->getId();
            if ($productId === null) {
                continue;
            }

            $result[$productId] = $product->getTitle();
        }

        return $result;
    }

    public function getCategoryProducts(ProductCategory $model)
    {
        return $this->getProductRepository()->findEnabledVisibleByCategoryId((int) $model->getId());
    }

    public function getProductCategorySearchQueryBuilder(array $data): QueryBuilder
    {
        return $this->getProductCategoryRepository()->getEnabledVisibleSearchQueryBuilder();
    }

    public function findProductCategoryById(int $id): ProductCategory
    {
        $category = $this->getProductCategoryRepository()->findById($id);
        if (!$category instanceof ProductCategory) {
            throw new \ClientFence\InformationException('Category not found');
        }

        return $category;
    }

    public function toProductPaymentApiArray(ProductPayment $model): array
    {
        $periods = [];
        $periods['1W'] = ['price' => $model->getPeriodPrice('w'), 'setup' => $model->getPeriodSetupPrice('w'), 'enabled' => $model->isPeriodEnabled('w')];
        $periods['1M'] = ['price' => $model->getPeriodPrice('m'), 'setup' => $model->getPeriodSetupPrice('m'), 'enabled' => $model->isPeriodEnabled('m')];
        $periods['3M'] = ['price' => $model->getPeriodPrice('q'), 'setup' => $model->getPeriodSetupPrice('q'), 'enabled' => $model->isPeriodEnabled('q')];
        $periods['6M'] = ['price' => $model->getPeriodPrice('b'), 'setup' => $model->getPeriodSetupPrice('b'), 'enabled' => $model->isPeriodEnabled('b')];
        $periods['1Y'] = ['price' => $model->getPeriodPrice('a'), 'setup' => $model->getPeriodSetupPrice('a'), 'enabled' => $model->isPeriodEnabled('a')];
        $periods['2Y'] = ['price' => $model->getPeriodPrice('bia'), 'setup' => $model->getPeriodSetupPrice('bia'), 'enabled' => $model->isPeriodEnabled('bia')];
        $periods['3Y'] = ['price' => $model->getPeriodPrice('tria'), 'setup' => $model->getPeriodSetupPrice('tria'), 'enabled' => $model->isPeriodEnabled('tria')];

        return [
            'type' => $model->getType(),
            ProductPayment::FREE => ['price' => 0, 'setup' => 0],
            ProductPayment::ONCE => ['price' => $model->getOncePrice(), 'setup' => $model->getOnceSetupPrice()],
            ProductPayment::RECURRENT => $periods,
        ];
    }

    /** ClientFence: getStartingFromPrice() from the product's pricing in inCurrency()'s terms. */
    private function startingFromPricing(Product $model): float|int|null
    {
        if ($model->getType() !== self::DOMAIN && !$model->getProductPaymentId()) {
            return null;
        }
        $pricing = $this->getProductPricingArray($model);
        if ($model->getType() === self::DOMAIN) {
            $prices = array_map('floatval', array_column($pricing, 'price_registration'));

            return $prices === [] ? 0.0 : min($prices);
        }

        return match ($pricing['type'] ?? null) {
            ProductPayment::FREE => 0,
            ProductPayment::ONCE => (float) ($pricing[ProductPayment::ONCE]['price'] ?? 0),
            ProductPayment::RECURRENT => ($enabled = array_filter((array) ($pricing[ProductPayment::RECURRENT] ?? []), static fn (mixed $row): bool => is_array($row) && !empty($row['enabled']))) === []
                ? null
                : min(array_map(static fn (array $row): float => (float) $row['price'], $enabled)),
            default => null,
        };
    }

    public function getStartingDomainPrice(): float
    {
        $pricing = $this->getDomainPricingArray();
        $registrationPrices = array_column($pricing, 'price_registration');

        return $registrationPrices === [] ? 0.0 : (float) min($registrationPrices);
    }

    public function getStartingPrice(ProductPayment $model)
    {
        if ($model->getType() == ProductPayment::FREE) {
            return 0;
        }

        if ($model->getType() == ProductPayment::ONCE) {
            return $model->getOncePrice();
        }

        if ($model->getType() == ProductPayment::RECURRENT) {
            $p = [];

            if ($model->isPeriodEnabled('w')) {
                $p[] = $model->getPeriodPrice('w');
            }

            if ($model->isPeriodEnabled('m')) {
                $p[] = $model->getPeriodPrice('m');
            }

            if ($model->isPeriodEnabled('q')) {
                $p[] = $model->getPeriodPrice('q');
            }

            if ($model->isPeriodEnabled('b')) {
                $p[] = $model->getPeriodPrice('b');
            }

            if ($model->isPeriodEnabled('a')) {
                $p[] = $model->getPeriodPrice('a');
            }

            if ($model->isPeriodEnabled('bia')) {
                $p[] = $model->getPeriodPrice('bia');
            }

            if ($model->isPeriodEnabled('tria')) {
                $p[] = $model->getPeriodPrice('tria');
            }

            if ($p) {
                return min($p);
            }

            return null;
        }

        return null;
    }

    public function getAddonById(int $id): ?Product
    {
        return $this->getProductRepository()->findEnabledAddonById($id);
    }

    /**
     * @return list<array{product: Product, config: array}>
     */
    public function getSelectedAddonsForCart(Product $parentProduct, array $addons): array
    {
        $selectedAddons = [];
        foreach ($addons as $id => $addonConfig) {
            if (!isset($addonConfig['selected']) || !(bool) $addonConfig['selected']) {
                continue;
            }

            $addon = $this->getAddonById($id);
            if (!$addon instanceof Product) {
                $this->di['logger']->warning('Addon not found by id %s', $id);

                continue;
            }

            if ($this->isRecurrentProductPricing($addon)) {
                $required = [
                    'period' => 'Addon period parameter not passed',
                ];
                $this->di['validator']->checkRequiredParamsForArray($required, $addonConfig);

                if (!$this->isProductPeriodEnabled($addon, (string) $addonConfig['period'])) {
                    throw new \ClientFence\InformationException('Selected billing period is invalid for the selected add-on');
                }
            }

            $addonConfig['quantity'] = $addon->isAllowQuantitySelect() ? max(1, (int) ($addonConfig['quantity'] ?? 1)) : 1;
            $addonConfig['parent_id'] = $parentProduct->getId();
            $selectedAddons[] = [
                'product' => $addon,
                'config' => $addonConfig,
            ];
        }

        return $selectedAddons;
    }

    public function isStockAvailable(Product|int $product, $qty): bool
    {
        $resolvedProduct = $this->resolveStockProduct($product);
        if ($resolvedProduct->isStockControl()) {
            return $resolvedProduct->getQuantityInStock() >= (int) $qty;
        }

        return true;
    }

    /**
     * Gives back stock taken for an order whose provisioning failed.
     */
    public function returnStock(Product|int $product, int $qty): void
    {
        $resolvedProduct = $this->resolveStockProduct($product);
        if ($resolvedProduct->isStockControl() && $qty > 0) {
            $this->getProductRepository()->incrementStock((int) $resolvedProduct->getId(), $qty, new \DateTime());
            $this->di['em']->refresh($resolvedProduct);
        }
    }

    public function reduceStock(Product|int $product, $qty): bool
    {
        $resolvedProduct = $this->resolveStockProduct($product);
        if (!$resolvedProduct->isStockControl()) {
            return true;
        }

        $quantity = (int) $qty;
        if ($quantity <= 0) {
            // Subtracting a non-positive quantity would leave stock unchanged or inflate it.
            return true;
        }

        // A single statement, otherwise concurrent orders both read the same quantity and each
        // subtract from it, overselling the product. Zero rows means stock ran out meanwhile.
        $updated = $this->getProductRepository()->decrementStockIfAvailable(
            (int) $resolvedProduct->getId(),
            $quantity,
            new \DateTime()
        );

        if ($updated === 0) {
            throw new \ClientFence\InformationException('Product :id is out of stock.', [':id' => $resolvedProduct->getId()], 831);
        }

        // The statement above bypassed the entity, so bring the in-memory copy back in line.
        $this->di['em']->refresh($resolvedProduct);

        return true;
    }

    private function getPeriods(Promo $model): array
    {
        return $this->decodePromoSelection($this->getPromoSourceArray($model)['periods'] ?? null);
    }

    private function getProducts(Promo $model): array
    {
        return $this->decodePromoSelection($this->getPromoSourceArray($model)['products'] ?? null);
    }

    /**
     * @return mixed[]
     */
    private function getAddonsApiArray(Product $model, bool $isAdmin = false): array
    {
        $addons = [];
        foreach ($this->getProductAddons($model, $isAdmin) as $addon) {
            $d = $this->toAddonArray($addon, true, $isAdmin);
            $addons[] = $d;
        }

        return $addons;
    }

    /**
     * @return mixed[]
     */
    public function getProductAddons(Product $model, bool $includeUnavailable = false): array
    {
        $ids = $this->normalizeProductIds(json_decode($model->getAddons() ?? '', true) ?? []);

        if ($ids === []) {
            return [];
        }

        return $this->getProductRepository()->findAddonsByIds($ids, (int) $model->getId(), $includeUnavailable);
    }

    /**
     * @param list<int|string> $ids
     *
     * @return array<int, array{id: int, title: ?string, type: ?string, plugin: ?string}>
     */
    public function getProductSnapshotMap(array $ids): array
    {
        $result = [];
        foreach ($this->getProductRepository()->findByIds($this->normalizeProductIds($ids)) as $product) {
            $productId = $product->getId();
            if ($productId === null) {
                continue;
            }

            $result[$productId] = [
                'id' => $productId,
                'title' => $product->getTitle(),
                'type' => $product->getType(),
                'plugin' => $product->getPlugin(),
            ];
        }

        return $result;
    }

    public function getRelatedProductDiscountByProductId(int $productId, array $items, ?array $config = null): float
    {
        return $this->getRelatedProductDiscount($this->findProductById($productId), $items, $config);
    }

    public function getCartProductTitleById(int $productId, ?array $config = null): string
    {
        return $this->getCartProductTitle($this->findProductById($productId), $config);
    }

    public function getProductDiscountById(int $productId, Promo $promo, ?array $config = null)
    {
        return $this->getProductDiscount($this->findProductById($productId), $promo, $config);
    }

    /**
     * @return array{
     *   product_id: int,
     *   form_id: ?int,
     *   type: ?string,
     *   quantity: int|float|string,
     *   unit: string,
     *   price: float|int|string,
     *   setup_price: float|int|string,
     *   options_setup_price: float,
     *   title: string,
     *   config: array
     * }
     */
    public function getCartProductViewData(CartProduct|\Model_CartProduct $item): array
    {
        $productId = $item instanceof CartProduct ? $item->getProductId() : $item->product_id;
        $configValue = $item instanceof CartProduct ? $item->getConfig() : $item->config;
        $product = $this->findProductById((int) $productId);
        $config = json_decode($configValue ?? '', true) ?? [];
        $line = $this->getProductOrderLineConfig($product, $config);
        // ClientFence core patch: configurable options (Product/Options.php): the chosen lines go with
        // the cart item into the order's config (Cart\Service::createFromCart()); their setup fees
        // stay out of a free-setup promotion unless it covers the options (Cart\Service).
        if (isset($line['option_lines'])) {
            $config['option_lines'] = $line['option_lines'];
        }

        return [
            'product_id' => (int) $product->getId(),
            'form_id' => $product->getFormId(),
            'type' => $product->getType(),
            'quantity' => $line['quantity'],
            'unit' => $this->getProductUnit($product),
            'price' => $line['price'],
            'setup_price' => $line['setup_price'],
            'options_setup_price' => (float) ($line['options_setup_price'] ?? 0),
            'title' => $this->getCartProductTitle($product, $config),
            'config' => $config,
        ];
    }

    /**
     * @param list<int|string> $ids
     *
     * @return array<int, ?string>
     */
    public function getProductPluginMap(array $ids): array
    {
        $result = [];
        foreach ($this->getProductSnapshotMap($ids) as $productId => $product) {
            $result[$productId] = $product['plugin'];
        }

        return $result;
    }

    public function getProductPluginById(int $id): ?string
    {
        return $this->getProductPluginMap([$id])[$id] ?? null;
    }

    public function toAddonArray(Product $model, $deep = true, bool $isAdmin = false): array
    {
        $productPayment = $this->getProductPaymentById((int) $model->getProductPaymentId());
        // ClientFence core patch: fixed currency prices (Product/CurrencyPrices.php).
        $pricing = CurrencyPrices::pricing($this->getDbalConnection(), (int) $model->getId(), $this->priceCurrency, $this->toProductPaymentApiArray($productPayment));
        $config = json_decode($model->getConfig() ?? '', true) ?? [];

        $result = [
            'id' => $model->getId(),
            'type' => $model->getType(),
            'title' => $model->getTitle(),
            'slug' => $model->getSlug(),
            'description' => $model->getDescription(),
            'unit' => $this->getProductUnit($model),
            'plugin' => $model->getPlugin(),
            'allow_quantity_select' => $model->isAllowQuantitySelect(),
            'created_at' => $this->formatDateTimeValue($model->getCreatedAt()),
            'updated_at' => $this->formatDateTimeValue($model->getUpdatedAt()),
            'icon_url' => $model->getIconUrl(),

            'pricing' => $isAdmin ? $pricing : $this->getPublicPricing($pricing),
            'config' => $isAdmin ? $config : $this->getPublicConfig($config),
        ];

        if (!$isAdmin) {
            unset($result['plugin'], $result['created_at'], $result['updated_at']);
        }

        return $result;
    }

    /*
     * Product Promotion Functions
     */
    public function getPromoSearchQueryBuilder(array $data): QueryBuilder
    {
        return $this->getPromoRepository()->getSearchQueryBuilder($data);
    }

    /** ClientFence core patch: a percentage discount is 0 to 100 (150% was accepted), and no discount is negative. */
    private static function checkPromoValue(mixed $type, mixed $value): void
    {
        if (is_numeric($value) && ((float) $value < 0 || ($type === Promo::PERCENTAGE && (float) $value > 100))) {
            throw new \ClientFence\InformationException('A discount is from 0 to 100 percent, or a positive amount.');
        }
    }

    public function createPromo($code, $type, $value, $products, $periods, $clientGroups, $data): int
    {
        self::checkPromoValue($type, $value);
        if ($this->getPromoRepository()->findOneBy(['code' => $code]) instanceof Promo) {
            throw new \ClientFence\InformationException('This promotion code already exists.');
        }

        $promo = new Promo();
        $promo->setCode($code);
        $this->applyPromoDataToEntity($promo, [
            ...$data,
            'type' => $type,
            'value' => $value,
            'products' => $products,
            'periods' => $periods,
            'client_groups' => $clientGroups,
        ]);

        $this->di['em']->persist($promo);
        $this->di['em']->flush();
        $promoId = (int) $promo->getId();

        $this->di['logger']->info('Created new promotion code %s', $promo->getCode());

        return $promoId;
    }

    public function duplicatePromo(Promo $model): int
    {
        $promo = new Promo();
        $promo
            ->setCode($this->generateUniquePromoCode($model->getCode()))
            ->setDescription($model->getDescription())
            ->setType($model->getType())
            ->setValue($model->getValue())
            ->setActive(false)
            ->setFreeSetup($model->isFreeSetup())
            ->setOncePerClient($model->isOncePerClient())
            ->setRecurring($model->isRecurring())
            ->setUsed(0)
            ->setMaxUses($model->getMaxUses())
            ->setProducts($model->getProducts())
            ->setPeriods($model->getPeriods())
            ->setClientGroups($model->getClientGroups())
            ->setStartAt($model->getStartAt() !== null ? clone $model->getStartAt() : null)
            ->setEndAt($model->getEndAt() !== null ? clone $model->getEndAt() : null);

        $this->di['em']->persist($promo);
        $this->di['em']->flush();
        $promoId = (int) $promo->getId();

        $this->di['logger']->info('Duplicated promotion code %s into new promotion code %s', $model->getCode(), $promo->getCode());

        return $promoId;
    }

    public function findActivePromoByCode($code): ?Promo
    {
        return $this->getPromoRepository()->findActiveByCode((string) $code);
    }

    public function findPromoById(int $id): Promo
    {
        $promo = $this->getPromoRepository()->find($id);
        if (!$promo instanceof Promo) {
            throw new \ClientFence\InformationException('Promo not found');
        }

        return $promo;
    }

    public function promoCanBeApplied(Promo $promo): bool
    {
        $promoData = $this->getPromoSourceArray($promo);

        if (empty($promoData['active'])) {
            return false;
        }

        $maxUses = (int) ($promoData['maxuses'] ?? 0);
        $used = (int) ($promoData['used'] ?? 0);
        if ($maxUses > 0 && $maxUses <= $used) {
            return false;
        }

        if (!empty($promoData['start_at']) && (strtotime((string) $promoData['start_at']) - time() > 0)) {
            return false;
        }

        if (!empty($promoData['end_at']) && (strtotime((string) $promoData['end_at']) - time() < 0)) {
            return false;
        }

        return true;
    }

    public function isPromoAvailableForClientGroup(Promo $promo, Client|\Model_Client|null $client = null): bool
    {
        $promoData = $this->getPromoSourceArray($promo);
        $clientGroups = $this->decodePromoSelection($promoData['client_groups'] ?? null);

        if (empty($clientGroups)) {
            return true;
        }

        if ($client === null) {
            try {
                $client = $this->di['loggedin_client'];
            } catch (\Exception) {
                $client = null;
            }
        }

        if (is_null($client)) {
            return false;
        }

        $clientGroupId = $client instanceof Client ? $client->getClientGroupId() : $client->client_group_id;
        if (!$clientGroupId) {
            return false;
        }

        return in_array($clientGroupId, $clientGroups);
    }

    public function canClientUsePromo(Client|\Model_Client $client, Promo $promo): bool
    {
        if (!$this->promoCanBeApplied($promo)) {
            return false;
        }

        $promoData = $this->getPromoSourceArray($promo);
        if (empty($promoData['once_per_client'])) {
            return true;
        }

        return !$this->clientHasActivePromoApplication($client, $promo);
    }

    public function usePromo(Promo $promo): void
    {
        $promoId = (int) ($this->getPromoSourceArray($promo)['id'] ?? 0);
        $affectedRows = $this->getPromoRepository()->incrementUsageIfAvailable($promoId, new \DateTimeImmutable());
        if ($affectedRows === 0) {
            throw new \ClientFence\InformationException('This promo code has reached its maximum number of uses.');
        }
    }

    public function reservePromoForOrder(Promo $promo, Order|\Model_ClientOrder $order): void
    {
        $this->usePromo($promo);
        $promoData = $this->getPromoSourceArray($promo);

        if ($order instanceof Order) {
            $order->setPromoRecurring(!empty($promoData['recurring']));
            $order->setPromoUsed(1);
            $this->di['em']->persist($order);
        } else {
            $order->promo_recurring = (int) !empty($promoData['recurring']);
            $order->promo_used = 1;
            $this->di['db']->store($order);
        }
    }

    public function getPromoDiscountTitle(Promo $promo, string $currency): string
    {
        $api_guest = $this->di['api_guest'];
        $promoData = $this->getPromoSourceArray($promo);

        return match ($promoData['type'] ?? null) {
            Promo::ABSOLUTE => __trans('Promotional Code: :code - :value Discount', [
                ':code' => $promoData['code'] ?? '',
                ':value' => $api_guest->currency_format(['code' => $currency, 'price' => $promoData['value'] ?? 0]),
            ]),
            Promo::PERCENTAGE => __trans('Promotional Code: :code - :value%', [
                ':code' => $promoData['code'] ?? '',
                ':value' => $promoData['value'] ?? 0,
            ]),
            default => __trans('Promotional Code: :code', [':code' => $promoData['code'] ?? '']),
        };
    }

    /**
     * @param list<Order|\Model_ClientOrder> $orders
     */
    public function createCheckoutPromoRedemptions(
        Promo $promo,
        Client|\Model_Client $client,
        array $orders,
        ?\Model_Invoice $invoice,
        string $status,
    ): void {
        if ($orders === []) {
            return;
        }

        foreach ($orders as $order) {
            $discount = $order instanceof Order ? $order->getDiscount() : (float) $order->discount;
            $currency = $order instanceof Order ? $order->getCurrency() : $order->currency;
            $createdAt = $order instanceof Order
                ? $order->getCreatedAt()?->format('Y-m-d H:i:s')
                : $order->created_at;

            $redemption = $this->newPromoRedemption(
                $promo,
                $client,
                $order,
                $invoice,
                PromoRedemption::PHASE_CHECKOUT,
                $discount,
                $currency,
                $createdAt,
                $status,
            );

            $this->di['em']->persist($redemption);
        }

        $this->di['em']->flush();
    }

    /**
     * Compensate for a failed checkout by removing orphaned promo redemption
     * rows and decrementing the promo usage counter.
     *
     * Needed because RedBean's transaction (orders/invoices) operates on a
     * separate database connection from Doctrine (promo redemptions, promo.used).
     * When the RedBean transaction rolls back, Doctrine-side changes persist
     * orphaned unless explicitly cleaned up.
     *
     * Idempotent: safe to call multiple times. Returns early if redemptions
     * were already cleaned up by a previous invocation.
     *
     * @param int[] $orderIds      Order IDs from the rolled-back RedBean transaction
     * @param int   $reservedCount Number of successful reservePromoForOrder() calls
     */
    public function compensateCheckoutPromoFailure(Promo $promo, array $orderIds, int $reservedCount): void
    {
        if ($reservedCount <= 0) {
            return;
        }

        $promoId = (int) ($this->getPromoSourceArray($promo)['id'] ?? 0);
        if ($promoId <= 0) {
            return;
        }

        if ($orderIds === []) {
            return;
        }

        $redemptions = $this->getPromoRedemptionRepository()->findBy([
            'promoId' => $promoId,
            'clientOrderId' => $orderIds,
        ]);
        if ($redemptions === []) {
            return;
        }

        foreach ($redemptions as $redemption) {
            $this->di['em']->remove($redemption);
        }
        $this->di['em']->flush();

        $this->getPromoRepository()->decrementUsage($promoId, count($redemptions), new \DateTimeImmutable());
    }

    /**
     * @return array{
     *     promo: Promo,
     *     discount_amount: float,
     *     title: string,
     *     currency: string
     * }|null
     */
    public function getRenewalPromoAdjustment(Order|\Model_ClientOrder $order, float $price, float $quantity): ?array
    {
        $promoRecurring = $order instanceof Order ? $order->isPromoRecurring() : (bool) $order->promo_recurring;
        $promoId = $order instanceof Order ? $order->getPromoId() : $order->promo_id;
        if (!$promoRecurring || !$promoId) {
            return null;
        }

        $productId = $order instanceof Order ? $order->getProductId() : $order->product_id;
        $discountAmount = (float) ($order instanceof Order ? $order->getDiscount() : $order->discount);
        $currency = (string) ($order instanceof Order ? $order->getCurrency() : $order->currency);
        $product = $this->findProductById((int) $productId);

        if ($product->getType() !== self::DOMAIN) {
            try {
                $promo = $this->findPromoById((int) $promoId);
            } catch (\ClientFence\Exception) {
                return null;
            }
        } else {
            $promo = $this->findPromoById((int) $promoId);
        }

        if ($product->getType() === self::DOMAIN) {
            $configValue = $order instanceof Order ? $order->getConfig() : $order->config;
            $config = json_decode($configValue ?? '', true) ?? [];
            // ClientFence core patch: fixed currency prices (Product/CurrencyPrices.php): a percentage of
            // the TLD's renewal price in the order's currency.
            $discountAmount = $this->inCurrency($currency)->getRenewalProductDiscount($product, $promo, $config);

            $currencyService = $this->di['mod_service']('Currency');
            $currencyRepository = $currencyService->getCurrencyRepository();
            $rate = $currencyRepository->getRateByCode($currency);
            if ($rate === null) {
                throw new \ClientFence\Exception("Currency conversion rate cannot be determined for code {$currency}");
            }

            $discountAmount *= $rate;
        }

        $orderTotal = $price * $quantity;
        $discountAmount = min($discountAmount, $orderTotal);
        if ($discountAmount <= 0) {
            return null;
        }

        return [
            'promo' => $promo,
            'discount_amount' => $discountAmount,
            'title' => $this->getPromoDiscountTitle($promo, $currency),
            'currency' => $currency,
        ];
    }

    public function toPromoApiArray(Promo $model, $deep = false, $identity = null)
    {
        return $this->enrichPromoApiArray($this->getPromoApiSourceArray($model), $deep, $identity);
    }

    public function enrichPromoApiArray(array $result, $deep = false, $identity = null): array
    {
        $products = !empty($result['products']) ? $this->getProductTitlesByIds($this->decodePromoSelection($result['products'])) : null;
        $clientGroups = !empty($result['client_groups']) ? $this->di['tools']->getPairsForTableByIds('client_group', $this->decodePromoSelection($result['client_groups'])) : null;
        $usageStats = $deep ? $this->getPromoUsageStatsByValues((int) $result['id'], (int) ($result['used'] ?? 0), isset($result['maxuses']) ? (int) $result['maxuses'] : null) : null;
        $redemptionCount = $usageStats['recorded_applications'] ?? $this->getPromoRedemptionCountById((int) $result['id']);

        $result['applies_to'] = $products;
        $result['cgroups'] = $clientGroups;
        $result['products'] = $this->decodePromoSelection($result['products'] ?? null);
        $result['periods'] = $this->decodePromoSelection($result['periods'] ?? null);
        $result['client_groups'] = $this->decodePromoSelection($result['client_groups'] ?? null);
        $result['redemption_count'] = $redemptionCount;
        $result['can_be_deleted'] = !$this->hasPromoRedemptionHistoryById((int) $result['id'], $redemptionCount);
        if ($usageStats !== null) {
            $result['usage_stats'] = $usageStats;
        }

        return $result;
    }

    public function enrichPromoRedemptionApiArray(array $row): array
    {
        $result = $row;
        $result['client'] = null;
        $result['order'] = null;
        $result['invoice'] = null;

        if (!empty($result['client_id'])) {
            $client = $this->getPromoRedemptionRepository()->findClientSummary((int) $result['client_id']);

            $result['client'] = [
                'id' => (int) $result['client_id'],
                'first_name' => $client['first_name'] ?? null,
                'last_name' => $client['last_name'] ?? null,
                'email' => $client['email'] ?? null,
            ];
        }

        if (!empty($result['client_order_id'])) {
            $order = $this->getPromoRedemptionRepository()->findOrderSummary((int) $result['client_order_id']);

            $result['order'] = [
                'id' => (int) $result['client_order_id'],
                'title' => $order['title'] ?? null,
                'created_at' => $order['created_at'] ?? null,
            ];
        }

        if (!empty($result['invoice_id'])) {
            $invoice = $this->getPromoRedemptionRepository()->findInvoiceSummary((int) $result['invoice_id']);

            // serie_nr is not a stored column; it is the invoice serie followed
            // by the zero-padded number, matching how the Invoice module builds it.
            $serieNr = null;
            if ($invoice !== null && isset($invoice['serie'])) {
                $padding = $this->di['mod_service']('system')->getParamValue('invoice_number_padding');
                $padding = ($padding !== null && $padding !== '') ? (int) $padding : 5;
                $nr = is_numeric($invoice['nr'] ?? null) ? (int) $invoice['nr'] : (int) ($invoice['id'] ?? $result['invoice_id']);
                $serieNr = $invoice['serie'] . sprintf('%0' . $padding . 's', $nr);
            }

            $result['invoice'] = [
                'id' => (int) $result['invoice_id'],
                'serie_nr' => $serieNr,
                'status' => $invoice['status'] ?? null,
                'created_at' => $invoice['created_at'] ?? null,
            ];
        }

        return $result;
    }

    public function createPromoRedemption(
        Promo $promo,
        Client|\Model_Client $client,
        Order|\Model_ClientOrder|null $order,
        ?\Model_Invoice $invoice,
        string $phase,
        ?float $discountAmount,
        ?string $currency,
        ?string $createdAt = null,
        string $status = PromoRedemption::STATUS_COMMITTED,
    ): int {
        $redemption = $this->newPromoRedemption($promo, $client, $order, $invoice, $phase, $discountAmount, $currency, $createdAt, $status);
        $this->di['em']->persist($redemption);
        $this->di['em']->flush();

        return (int) $redemption->getId();
    }

    public function clientHasActivePromoApplication(Client|\Model_Client $client, Promo $promo): bool
    {
        $promoId = (int) ($this->getPromoSourceArray($promo)['id'] ?? 0);

        $clientId = $client instanceof Client ? $client->getId() : $client->id;

        return $this->getPromoRedemptionRepository()->clientHasActiveCheckoutApplication($promoId, (int) $clientId);
    }

    /**
     * Locking variant of clientHasActivePromoApplication(), for the checkout transaction.
     * Promos without the once-per-client flag never lock: there is nothing to serialize.
     */
    public function clientHasActivePromoApplicationForUpdate(Client|\Model_Client $client, Promo $promo): bool
    {
        $promoData = $this->getPromoSourceArray($promo);
        if (empty($promoData['once_per_client'])) {
            return false;
        }

        $promoId = (int) ($promoData['id'] ?? 0);

        $clientId = $client instanceof Client ? $client->getId() : $client->id;

        return $this->getPromoRedemptionRepository()->clientHasActiveCheckoutApplicationForUpdate($promoId, (int) $clientId);
    }

    public function commitReservedPromoRedemptionsForInvoice(\Model_Invoice $invoice): void
    {
        $redemptions = $this->getPromoRedemptionRepository()->findBy([
            'invoiceId' => (int) $invoice->id,
            'status' => PromoRedemption::STATUS_RESERVED,
        ]);

        if ($redemptions === []) {
            return;
        }

        $committedAt = !empty($invoice->paid_at) ? new \DateTime((string) $invoice->paid_at) : new \DateTime();
        foreach ($redemptions as $redemption) {
            if (!$redemption instanceof PromoRedemption) {
                continue;
            }

            $redemption
                ->setStatus(PromoRedemption::STATUS_COMMITTED)
                ->setCommittedAt(clone $committedAt)
                ->setReleasedAt(null)
                ->setReleaseReason(null);
        }

        $this->di['em']->flush();
    }

    public function releaseReservedPromoRedemptionsForInvoice(\Model_Invoice $invoice, string $reason): void
    {
        $redemptions = $this->getPromoRedemptionRepository()->findBy([
            'invoiceId' => (int) $invoice->id,
            'status' => PromoRedemption::STATUS_RESERVED,
        ]);

        $this->releasePromoRedemptions($redemptions, $reason);
    }

    public function releaseReservedPromoRedemptionsForOrder(Order|\Model_ClientOrder $order, string $reason): void
    {
        $orderId = $order instanceof Order ? $order->getId() : $order->id;
        $redemptions = $this->getPromoRedemptionRepository()->findBy([
            'clientOrderId' => (int) $orderId,
            'status' => PromoRedemption::STATUS_RESERVED,
        ]);

        $this->releasePromoRedemptions($redemptions, $reason);
    }

    public function updatePromo(Promo $model, array $data = []): bool
    {
        $promo = $model;
        if (($data['code'] ?? null) !== null && $data['code'] !== $promo->getCode()) {
            $existing = $this->getPromoRepository()->findOneBy(['code' => $data['code']]);
            if ($existing instanceof Promo && $existing->getId() !== $promo->getId()) {
                throw new \ClientFence\InformationException('This promotion code already exists.');
            }
        }

        self::checkPromoValue($data['type'] ?? $promo->getType(), $data['value'] ?? $promo->getValue());
        $this->applyPromoDataToEntity($promo, $data);
        $this->di['em']->flush();

        $this->di['logger']->info('Update promo code %s', $promo->getCode());

        return true;
    }

    public function deletePromo(Promo $model): bool
    {
        $promo = $model;
        $promoId = (int) $promo->getId();
        if ($this->hasPromoRedemptionHistoryById($promoId)) {
            throw new \ClientFence\InformationException('Promotions with redemption history cannot be deleted. Disable the promotion instead.');
        }

        $this->di['em']->remove($promo);
        $this->di['em']->flush();

        $this->di['logger']->info('Removed promo code %s', $promo->getCode());

        return true;
    }

    public function isPromoLinkedToProduct(Promo $promo, Product $product)
    {
        if ($product->isAddon()) {
            return false;
        }

        $products = $this->getProducts($promo);
        if (empty($products)) {
            return true;
        }

        return in_array($product->getId(), $products);
    }

    /**
     * Resolve initial order line pricing for a product from the active pricing backend.
     *
     * @return array{price: float|int|string, quantity: int|float|string, setup_price?: float|int|string, options_price?: float, options_setup_price?: float, option_lines?: list<array<string, mixed>>}
     */
    public function getProductOrderLineConfig(Product $product, ?array $config = null): array
    {
        if ($product->getType() === self::DOMAIN) {
            return $this->getDomainOrderLineConfig($config ?? []);
        }

        $quantity = max(1, (int) ($config['quantity'] ?? 1));

        $line = [
            'price' => (float) $this->getProductPrice($product, $config),
            'quantity' => $quantity,
            'setup_price' => $this->getProductSetupPrice($product, $config),
        ];

        // ClientFence core patch: configurable options (Product/Options.php). A service keeps the option
        // lines it was sold with (`option_lines`; catalog changes never reprice it); a cart item (with
        // `options`) is priced from the catalog. `options_price` lets promotions leave the options out.
        $options = $this->getOptionsLine($product, $config ?? []);
        if ($options !== null) {
            // ClientFence: a fixed currency price (CurrencyPrices.php) is an unrounded default-currency
            // equivalent; rounding it to cents here would miss the fixed price after conversion.
            $places = $this->priceCurrency === null ? 2 : 8;
            $line['price'] = round($line['price'] + $options['price'], $places);
            $line['setup_price'] = round($line['setup_price'] + $options['setup_price'], $places);
            $line['options_price'] = $options['price'];
            $line['options_setup_price'] = $options['setup_price'];
            $line['option_lines'] = $options['lines'];
        }

        return $line;
    }

    /**
     * ClientFence: the configurable options of a cart item or an order config (Product/Options.php),
     * or null when it has none.
     *
     * @return array{price: float, setup_price: float, lines: list<array<string, mixed>>}|null
     */
    private function getOptionsLine(Product $product, array $config): ?array
    {
        if (isset($config['option_lines']) && is_array($config['option_lines'])) {
            $lines = array_values(array_filter($config['option_lines'], 'is_array'));
            $price = 0.0;
            $setup = 0.0;
            foreach ($lines as $stored) {
                $price += (float) ($stored['price'] ?? 0);
                $setup += (float) ($stored['setup_price'] ?? 0);
            }

            return ['price' => round($price, 2), 'setup_price' => round($setup, 2), 'lines' => $lines];
        }
        if (!isset($config['options']) || !is_array($config['options'])) {
            return null;
        }
        $resolved = \ClientFence\Mod\Product\Options::resolve($this->di, (int) $product->getId(), $config['options'], $this->optionsPeriod($product, $config));

        return ['price' => $resolved['price'], 'setup_price' => $resolved['setup_price'], 'lines' => $resolved['lines']];
    }

    /** ClientFence: the option price column for a product and its chosen period: "once" unless recurrent. */
    private function optionsPeriod(Product $product, array $config): string
    {
        $pp = $this->getProductPaymentById((int) $product->getProductPaymentId());
        if ($pp->getType() !== ProductPayment::RECURRENT) {
            return 'once';
        }

        return (new \ClientFence\Period((string) ($config['period'] ?? '')))->getCode();
    }

    /**
     * Resolve renewal line pricing for a product from the active pricing backend.
     *
     * @return array{price: float|int|string, quantity: int|float|string, setup_price?: float|int|string, options_price?: float, options_setup_price?: float, option_lines?: list<array<string, mixed>>}
     */
    public function getProductRenewalLineConfig(Product $product, ?array $config = null): array
    {
        if ($product->getType() === self::DOMAIN) {
            return $this->getDomainRenewalLineConfig($config ?? []);
        }

        return $this->getProductOrderLineConfig($product, $config);
    }

    public function getProductSetupPrice(Product $product, ?array $config = null): float
    {
        if ($product->getType() === self::DOMAIN) {
            return 0.0;
        }

        $pp = $this->getProductPaymentById((int) $product->getProductPaymentId());

        if ($pp->getType() == ProductPayment::FREE) {
            return 0.0;
        }

        // ClientFence core patch: fixed currency prices (Product/CurrencyPrices.php) via inCurrency().
        if ($pp->getType() == ProductPayment::ONCE) {
            return $this->currencyBase('product', (int) $product->getId(), 'once', 'setup_price', (float) $pp->getOnceSetupPrice());
        }

        if ($pp->getType() == ProductPayment::RECURRENT) {
            $period = new \ClientFence\Period((string) ($config['period'] ?? ''));
            $key = $this->getProductPaymentPeriodKey($period);

            return $this->currencyBase('product', (int) $product->getId(), self::periodOfKey($key), 'setup_price', (float) $pp->getPeriodSetupPrice($key));
        }

        throw new \ClientFence\Exception('Unknown period selected for setup price');
    }

    public function getProductPrice(Product $product, ?array $config = null): float|int|string
    {
        if ($product->getType() === self::DOMAIN) {
            return $this->getDomainProductPrice($config ?? []);
        }

        $pp = $this->getProductPaymentById((int) $product->getProductPaymentId());

        if ($pp->getType() == ProductPayment::FREE) {
            return 0.0;
        }

        // ClientFence core patch: fixed currency prices (Product/CurrencyPrices.php) via inCurrency().
        if ($pp->getType() == ProductPayment::ONCE) {
            return $this->priceCurrency === null ? $pp->getOncePrice() : $this->currencyBase('product', (int) $product->getId(), 'once', 'price', (float) $pp->getOncePrice());
        }

        if ($pp->getType() == ProductPayment::RECURRENT) {
            if (!isset($config['period'])) {
                throw new \ClientFence\Exception('Product :id payment type is recurrent, but period was not selected', [':id' => $product->getId()]);
            }

            $period = new \ClientFence\Period((string) $config['period']);
            $key = $this->getProductPaymentPeriodKey($period);

            return $this->priceCurrency === null ? $pp->getPeriodPrice($key) : $this->currencyBase('product', (int) $product->getId(), self::periodOfKey($key), 'price', (float) $pp->getPeriodPrice($key));
        }

        throw new \ClientFence\Exception('Unknown Period selected for price');
    }

    /** ClientFence: the billing period of a product_payment column key (CurrencyPrices.php periods). */
    private static function periodOfKey(string $key): string
    {
        return match ($key) {
            'w' => '1W',
            'm' => '1M',
            'q' => '3M',
            'b' => '6M',
            'a' => '1Y',
            'bia' => '2Y',
            default => '3Y',
        };
    }

    private function getProductPaymentPeriodKey(\ClientFence\Period $period): string
    {
        $code = $period->getCode();

        try {
            return match ($code) {
                '1W' => 'w',
                '1M' => 'm',
                '3M' => 'q',
                '6M' => 'b',
                '12M', '1Y' => 'a',
                '2Y' => 'bia',
                '3Y' => 'tria',
            };
        } catch (\UnhandledMatchError) {
            throw new \ClientFence\Exception('Unknown period selected ' . $code);
        }
    }

    private function getProductPaymentById(int $id): ProductPayment
    {
        $productPayment = $this->getProductPaymentRepository()->find($id);
        if (!$productPayment instanceof ProductPayment) {
            throw new \ClientFence\InformationException('Product payment not found');
        }

        return $productPayment;
    }

    private function getDbalConnection(): Connection
    {
        if ($this->di === null) {
            throw new \ClientFence\Exception('The dependency injection container has not been set.');
        }

        if (isset($this->di['dbal']) && $this->di['dbal'] instanceof Connection) {
            return $this->di['dbal'];
        }

        return $this->di['em']->getConnection();
    }

    private function validateIconUrl(?string $iconUrl): void
    {
        if ($iconUrl !== null && mb_strlen($iconUrl, 'UTF-8') > 255) {
            throw new \ClientFence\InformationException('Icon URL must not exceed 255 characters.');
        }
    }

    private function createDefaultProductPayment(): ProductPayment
    {
        $productPayment = new ProductPayment();
        $productPayment->setType(ProductPayment::FREE);
        $this->di['em']->persist($productPayment);
        $this->di['em']->flush();

        return $productPayment;
    }

    private function generateUniqueProductSlug(string $title): string
    {
        $slug = $this->di['tools']->slug($title);

        while ($this->getProductRepository()->findOneBy(['slug' => $slug]) instanceof Product) {
            $slug = $this->di['tools']->slug($title) . '-' . random_int(1, 9999);
        }

        return $slug;
    }

    private function resolveStockProduct(Product|int $product): Product
    {
        if ($product instanceof Product) {
            return $product;
        }

        return $this->findProductById($product);
    }

    /**
     * @param mixed[] $ids
     *
     * @return list<int>
     */
    private function normalizeProductIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map(intval(...), $ids),
            static fn (int $id): bool => $id > 0
        )));
    }

    /**
     * @param array<string, mixed> $pricing
     */
    private function applyPricingToProductPayment(ProductPayment $productPayment, array $pricing): void
    {
        $productPayment->setType((string) $pricing['type']);

        if ($pricing['type'] == ProductPayment::ONCE) {
            $productPayment
                ->setOnceSetupPrice((float) ($pricing['once']['setup'] ?? 0))
                ->setOncePrice((float) ($pricing['once']['price'] ?? 0));
        }

        if ($pricing['type'] == ProductPayment::RECURRENT) {
            $periodMap = [
                '1W' => 'w',
                '1M' => 'm',
                '3M' => 'q',
                '6M' => 'b',
                '1Y' => 'a',
                '2Y' => 'bia',
                '3Y' => 'tria',
            ];

            foreach ($periodMap as $period => $prefix) {
                if (!isset($pricing['recurrent'][$period])) {
                    continue;
                }

                $periodPricing = $pricing['recurrent'][$period];
                $productPayment->setPeriodPricing(
                    $prefix,
                    (float) ($periodPricing['price'] ?? 0),
                    (float) ($periodPricing['setup'] ?? 0),
                    $periodPricing['enabled'] ?? false
                );
            }
        }
    }

    public function findProductById(int $id): Product
    {
        $product = $this->getProductRepository()->find($id);
        if (!$product instanceof Product) {
            throw new \ClientFence\InformationException('Product not found');
        }

        return $product;
    }

    public function getProductModuleService(Product $product): object
    {
        $type = $product->getType();
        if ($type === null || $type === '') {
            throw new \ClientFence\Exception('Product type could not be determined.');
        }

        return $this->di['mod_service']('service' . $type);
    }

    /**
     * @return array<string, mixed>
     */
    private function getProductValidationData(Product $product): array
    {
        return [
            'id' => $product->getId(),
            'product_category_id' => $product->getProductCategoryId(),
            'product_payment_id' => $product->getProductPaymentId(),
            'form_id' => $product->getFormId(),
            'title' => $product->getTitle(),
            'description' => $product->getDescription(),
            'unit' => $this->getProductUnit($product),
            'status' => $product->getStatus(),
            'config' => json_decode($product->getConfig() ?? '', true) ?? [],
            'plugin' => $product->getPlugin(),
            'type' => $product->getType(),
        ];
    }

    private function formatDateTimeValue(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value !== null ? (string) $value : null;
    }

    public function isPromoApplicableToProduct(Promo $promo, Product $product, ?array $config = null): bool
    {
        if (!$this->isPromoLinkedToProduct($promo, $product)) {
            return false;
        }

        // ClientFence core patch: a period-restricted promotion cannot discount a one-time cart line.
        if ($config !== null) {
            $periods = $this->getPeriods($promo);
            if (!empty($periods) && (!isset($config['period']) || !in_array($config['period'], $periods))) {
                return false;
            }
        }

        return true;
    }

    public function isPromoApplicableToProductById(int $productId, Promo $promo, ?array $config = null): bool
    {
        return $this->isPromoApplicableToProduct($promo, $this->findProductById($productId), $config);
    }

    /**
     * ClientFence core patch: configurable options (Product/Options.php). The price a promotion
     * discounts: the line's price without its options, unless the promotion's "Also discount
     * configurable options" is on.
     */
    private function getPromoBasePrice(Promo $promo, array $line): float
    {
        $price = (float) $line['price'];
        $options = (float) ($line['options_price'] ?? 0);
        if ($options > 0 && !\ClientFence\Mod\Product\Options::promoCoversOptions($this->di, (int) $promo->getId())) {
            $price -= $options;
        }

        return max(0.0, round($price, 2));
    }

    public function getProductDiscount(Product $product, Promo $promo, ?array $config = null)
    {
        if (!$this->isPromoApplicableToProduct($promo, $product, $config)) {
            return 0;
        }

        $line = $this->getProductOrderLineConfig($product, $config);
        $price = $this->getPromoBasePrice($promo, $line) * $line['quantity'];

        if ($price == 0) {
            return 0;
        }

        $discount = 0;

        $promoData = $this->getPromoSourceArray($promo);

        switch ($promoData['type'] ?? null) {
            case Promo::ABSOLUTE:
                $discount += (float) ($promoData['value'] ?? 0);

                break;

            case Promo::PERCENTAGE:
                $discount += round($price * (float) ($promoData['value'] ?? 0) / 100, 2);

                break;

            default:
                break;
        }

        return $this->capOptionsDiscount($promo, $line, (float) $discount, (float) $price);
    }

    /**
     * ClientFence core patch: configurable options (Product/Options.php). A fixed-amount discount larger
     * than the product's own price would otherwise reach its options; it stops at the product price
     * unless the promotion also covers the options. Lines without options keep FOSSBilling's rule.
     */
    private function capOptionsDiscount(Promo $promo, array $line, float $discount, float $price): float
    {
        if ((float) ($line['options_price'] ?? 0) > 0 && !\ClientFence\Mod\Product\Options::promoCoversOptions($this->di, (int) $promo->getId())) {
            return min($discount, $price);
        }

        return $discount;
    }

    public function getRenewalProductDiscount(Product $product, Promo $promo, ?array $config = null): float
    {
        if (!$this->isPromoApplicableToProduct($promo, $product, $config)) {
            return 0;
        }

        $line = $this->getProductRenewalLineConfig($product, $config);
        $price = $this->getPromoBasePrice($promo, $line) * $line['quantity'];

        if ($price == 0) {
            return 0;
        }

        $promoData = $this->getPromoSourceArray($promo);

        $discount = match ($promoData['type'] ?? null) {
            Promo::ABSOLUTE => (float) ($promoData['value'] ?? 0),
            Promo::PERCENTAGE => round($price * (float) ($promoData['value'] ?? 0) / 100, 2),
            default => 0,
        };

        return $this->capOptionsDiscount($promo, $line, (float) $discount, (float) $price);
    }

    public function isPromoLinkedToTld(Promo $promo, \Model_Tld $tld): bool
    {
        unset($tld);

        if ($this->getProducts($promo) === []) {
            return true;
        }

        $domainProduct = $this->getMainDomainProduct();
        if (!$domainProduct instanceof Product) {
            return false;
        }

        return $this->isPromoLinkedToProduct($promo, $domainProduct);
    }

    // Function to get all orders for a product
    public function getOrdersForProduct(Product $product)
    {
        return $this->getProductOrderRepository()->getRowsByProductId((int) $product->getId());
    }

    /**
     * @param list<PromoRedemption> $redemptions
     */
    private function releasePromoRedemptions(array $redemptions, string $reason): void
    {
        if ($redemptions === []) {
            return;
        }

        $releasedAt = new \DateTime();
        $checkoutReleaseCounts = [];
        foreach ($redemptions as $redemption) {
            if ($redemption->getStatus() !== PromoRedemption::STATUS_RESERVED) {
                continue;
            }

            $redemption
                ->setStatus(PromoRedemption::STATUS_RELEASED)
                ->setReleasedAt(clone $releasedAt)
                ->setReleaseReason($reason);

            if ($redemption->getPhase() === PromoRedemption::PHASE_CHECKOUT && $redemption->getPromoId() !== null) {
                $promoId = (int) $redemption->getPromoId();
                $checkoutReleaseCounts[$promoId] = ($checkoutReleaseCounts[$promoId] ?? 0) + 1;
            }
        }

        foreach ($checkoutReleaseCounts as $promoId => $releaseCount) {
            $this->getPromoRepository()->decrementUsage($promoId, $releaseCount, $releasedAt);
        }

        $this->di['em']->flush();
    }

    private function newPromoRedemption(
        Promo $promo,
        Client|\Model_Client $client,
        Order|\Model_ClientOrder|null $order,
        ?\Model_Invoice $invoice,
        string $phase,
        ?float $discountAmount,
        ?string $currency,
        ?string $createdAt,
        string $status,
    ): PromoRedemption {
        $promoId = (int) ($this->getPromoSourceArray($promo)['id'] ?? 0);
        $timestamp = $createdAt ?? date('Y-m-d H:i:s');
        $dateTime = new \DateTime($timestamp);
        $redemption = new PromoRedemption();
        $clientId = $client instanceof Client ? $client->getId() : $client->id;
        $orderId = $order instanceof Order ? $order->getId() : $order?->id;
        $redemption
            ->setPromoId($promoId)
            ->setClientId((int) $clientId)
            ->setClientOrderId($orderId !== null ? (int) $orderId : null)
            ->setInvoiceId($invoice !== null ? (int) $invoice->id : null)
            ->setPhase($phase)
            ->setStatus($status)
            ->setDiscountAmount($discountAmount)
            ->setCurrency($currency);
        $redemption->setCreatedAt($dateTime);
        $redemption->setUpdatedAt(clone $dateTime);
        if ($status === PromoRedemption::STATUS_COMMITTED) {
            $redemption->setCommittedAt(clone $dateTime);
        }

        return $redemption;
    }

    private function getPromoApiSourceArray(Promo $model): array
    {
        return $model->toApiArray();
    }

    private function getPromoSourceArray(Promo $model): array
    {
        return $this->getPromoApiSourceArray($model);
    }

    private function getPromoRedemptionCountById(int $promoId): int
    {
        return $this->getPromoRedemptionRepository()->countByPromoId($promoId);
    }

    private function getDomainRegistrationYears(array $config): int
    {
        if (isset($config['period']) && is_string($config['period']) && $config['period'] !== '') {
            $period = $this->di['period']($config['period']);

            return max(1, $period->getQty());
        }

        return max(1, (int) ($config['register_years'] ?? $config['quantity'] ?? 1));
    }

    private function getDomainTldModel(array $config): \Model_Tld
    {
        $tldService = $this->di['mod_service']('servicedomain', 'Tld');
        $tld = '';

        if (!isset($config['action'])) {
            throw new \ClientFence\Exception('Could not determine domain price. Domain action is missing', null, 498);
        }

        if ($config['action'] === 'register') {
            $tld = $config['register_tld'] ?? '';
        }

        if ($config['action'] === 'transfer') {
            $tld = $config['transfer_tld'] ?? '';
        }

        $tld = $tldService->findOneByTld($tld);
        if (!$tld instanceof \Model_Tld) {
            throw new \ClientFence\Exception('Unknown TLD. Could not determine registration price');
        }

        return $tld;
    }

    private function getDomainRegistrationTotal(\Model_Tld $tld, int $years): float
    {
        if ($years <= 0) {
            return 0.0;
        }

        // ClientFence core patch: fixed currency prices (Product/CurrencyPrices.php) via inCurrency().
        if ($years <= 1) {
            return $this->tldPrice($tld, 'register');
        }

        return $this->tldPrice($tld, 'register') + (($years - 1) * $this->tldPrice($tld, 'renew'));
    }

    /**
     * @return array{price: float, quantity: int, setup_price: float}
     */
    private function getDomainOrderLineConfig(array $config): array
    {
        if (($config['action'] ?? null) === 'owndomain') {
            return [
                'price' => 0.0,
                'quantity' => 1,
                'setup_price' => 0.0,
            ];
        }

        $tld = $this->getDomainTldModel($config);
        if (($config['action'] ?? null) === 'register') {
            return [
                'price' => $this->getDomainRegistrationTotal($tld, $this->getDomainRegistrationYears($config)),
                'quantity' => 1,
                'setup_price' => 0.0,
            ];
        }

        return [
            'price' => $this->tldPrice($tld, 'transfer'),
            'quantity' => 1,
            'setup_price' => 0.0,
        ];
    }

    /**
     * @return array{price: float, quantity: int}
     */
    private function getDomainRenewalLineConfig(array $config): array
    {
        $tld = $this->getDomainTldModel($config);

        return [
            'price' => $this->tldPrice($tld, 'renew'),
            'quantity' => $this->getDomainRegistrationYears($config),
        ];
    }

    public function getDomainProductPrice(array $config): float
    {
        if (($config['action'] ?? null) === 'owndomain') {
            return 0.0;
        }

        $tld = $this->getDomainTldModel($config);

        return match ($config['action'] ?? null) {
            'register' => $this->tldPrice($tld, 'register'),
            'transfer' => $this->tldPrice($tld, 'transfer'),
            default => 0.0,
        };
    }

    private function getDomainRelatedDiscount(array $items, array $config): float
    {
        foreach ($items as $addon) {
            $addon = $this->withHostingFreeDomainSettings($addon);
            if ($addon === null) {
                continue;
            }
            if (
                $this->isDomainActionNameSet($addon, 'register')
                && $this->isFreeDomainEnabled($addon)
                && $this->registerDomainMatches($addon, $config)
            ) {
                if ($this->hasFreeDomainPeriod($addon)) {
                    $discountYears = $this->getFreeDomainDiscountYears($addon, (string) ($config['period'] ?? ''));
                    $tld = $this->getDomainTldModel($config);

                    return $this->getDomainRegistrationTotal($tld, $discountYears);
                }

                return 0.0;
            }

            if (
                $this->isDomainActionNameSet($addon, 'transfer')
                && $this->isFreeTransferEnabled($addon)
                && $this->transferDomainMatches($addon, $config)
            ) {
                return $this->getDomainProductPrice($config);
            }
        }

        return 0.0;
    }

    /**
     * ClientFence core patch: a free domain or transfer comes only from a hosting product in the cart,
     * with the free domain settings of that product. A cart item's config holds what the client sent
     * (a domain product keeps every key), so its own free_* keys are never trusted.
     */
    private function withHostingFreeDomainSettings(array $item): ?array
    {
        $productId = (int) ($item['product_id'] ?? 0);
        $product = $productId > 0 ? $this->getProductRepository()->find($productId) : null;
        if (!$product instanceof Product || $product->getType() !== self::HOSTING) {
            return null;
        }
        $settings = json_decode($product->getConfig() ?? '', true);
        $settings = is_array($settings) ? $settings : [];
        $config = is_array($item['config'] ?? null) ? $item['config'] : [];
        foreach (['free_domain', 'free_transfer', 'free_tlds', 'free_domain_periods'] as $key) {
            unset($config[$key]);
            if (array_key_exists($key, $settings)) {
                $config[$key] = $settings[$key];
            }
        }
        $config['free_tlds'] = is_array($config['free_tlds'] ?? null) ? $config['free_tlds'] : [];
        $config['free_domain_periods'] = is_array($config['free_domain_periods'] ?? null) ? $config['free_domain_periods'] : [];
        $item['config'] = $config;

        return $item;
    }

    /**
     * ClientFence: whether a domain order renews free (WHMCS free domain renewal): it was ordered with a hosting
     * order (same order group) whose product gives it free with "Free Domain Renewals" on, by the same rules as
     * at checkout, and that hosting is still active or suspended.
     */
    public function hasFreeDomainRenewal(int $orderId): bool
    {
        $order = $this->di['dbal']->fetchAssociative('SELECT client_id, group_id, config FROM client_order WHERE id = ?', [$orderId]);
        $config = $order === false ? null : json_decode((string) $order['config'], true);
        $action = is_array($config) ? ($config['action'] ?? null) : null;
        if (!in_array($action, ['register', 'transfer'], true) || (string) $order['group_id'] === '') {
            return false;
        }
        $sld = strtolower((string) ($config[$action . '_sld'] ?? ''));
        $tld = strtolower((string) ($config[$action . '_tld'] ?? ''));
        $hostings = $this->di['dbal']->fetchAllAssociative(
            "SELECT co.period, co.config, p.config AS product_config FROM client_order co JOIN product p ON p.id = co.product_id
             WHERE co.group_id = ? AND co.client_id = ? AND co.id <> ? AND p.type = ? AND co.status IN ('active', 'suspended')",
            [(string) $order['group_id'], (int) $order['client_id'], $orderId, self::HOSTING],
        );
        foreach ($hostings as $hosting) {
            $settings = json_decode((string) $hosting['product_config'], true);
            $domain = json_decode((string) $hosting['config'], true)['domain'] ?? null;
            if (!is_array($settings) || empty($settings['free_domain_renewals']) || !is_array($domain) || ($domain['action'] ?? null) !== $action
                || strtolower((string) ($domain[$action . '_sld'] ?? '')) !== $sld || strtolower((string) ($domain[$action . '_tld'] ?? '')) !== $tld) {
                continue;
            }
            if ($action === 'transfer' ? !empty($settings['free_transfer'])
                : !empty($settings['free_domain']) && (empty($settings['free_tlds']) || in_array($tld, (array) $settings['free_tlds'], true))
                    && in_array($hosting['period'], (array) ($settings['free_domain_periods'] ?? []), true)) {
                return true;
            }
        }

        return false;
    }

    private function hasFreeDomainPeriod(array $addon): bool
    {
        $freeDomainPeriods = $addon['config']['free_domain_periods'] ?? [];
        $addonPeriod = $addon['config']['period'] ?? null;

        return $addonPeriod !== null && in_array($addonPeriod, $freeDomainPeriods, true);
    }

    private function getFreeDomainDiscountYears(array $addon, string $period): int
    {
        $referencePeriod = $this->di['period']($period);
        $referenceQty = $referencePeriod->getQty();

        $addonPeriod = $addon['config']['period'];
        $addonQty = $this->di['period']($addonPeriod)->getQty();

        $freeDomainPeriods = $addon['config']['free_domain_periods'] ?? [];
        if (\ClientFence\Tools::safeCount($freeDomainPeriods) > 0) {
            if ($addonPeriod === $period && in_array($addonPeriod, $freeDomainPeriods, true)) {
                return $referenceQty;
            }

            if (str_contains((string) $addonPeriod, 'Y')) {
                if (min($referenceQty, $addonQty) === 1) {
                    return 1;
                }

                $freeDomainQtys = [];
                foreach ($freeDomainPeriods as $freePeriod) {
                    $quantity = $this->di['period']($freePeriod)->getQty();
                    if ($referenceQty - $quantity > 0) {
                        $freeDomainQtys[] = $quantity;
                    }
                }

                if ($freeDomainQtys !== []) {
                    return min($referenceQty, min($freeDomainQtys));
                }
            }
        }

        return 0;
    }

    private function isDomainActionNameSet(array $item, string $actionName): bool
    {
        return isset($item['config']['domain']['action']) && $item['config']['domain']['action'] === $actionName;
    }

    private function isFreeDomainEnabled(array $item): bool
    {
        $freeDomain = $item['config']['free_domain'] ?? false;
        if (!$freeDomain) {
            return false;
        }

        $tld = $item['config']['tld'] ?? null;
        $freeTlds = $item['config']['free_tlds'] ?? [];
        if ($freeTlds === []) {
            return true;
        }

        return $tld !== null && in_array($tld, $freeTlds, true);
    }

    private function registerDomainMatches(array $item, array $config): bool
    {
        if (!isset($item['config']['domain']['register_sld'])) {
            return false;
        }

        return ($item['config']['domain']['register_sld'] ?? null) === ($config['register_sld'] ?? null)
            && ($item['config']['domain']['register_tld'] ?? null) === ($config['register_tld'] ?? null);
    }

    private function transferDomainMatches(array $item, array $config): bool
    {
        if (!isset($item['config']['domain']['transfer_sld'])) {
            return false;
        }

        return ($item['config']['domain']['transfer_sld'] ?? null) === ($config['transfer_sld'] ?? null)
            && ($item['config']['domain']['transfer_tld'] ?? null) === ($config['transfer_tld'] ?? null);
    }

    private function isFreeTransferEnabled(array $item): bool
    {
        return !empty($item['config']['free_transfer']);
    }

    /**
     * @return array{
     *     operational_use_count: int,
     *     max_uses: int|null,
     *     remaining_operational_uses: int|null,
     *     recorded_applications: int,
     *     checkout_applications: int,
     *     renewal_applications: int,
     *     active_checkout_applications: int,
     *     reserved_applications: int,
     *     committed_applications: int,
     *     released_applications: int,
     *     distinct_clients: int,
     *     orders_using_promo: int
     * }
     */
    private function getPromoUsageStatsByValues(int $promoId, int $operationalUseCount, ?int $maxUses): array
    {
        $repoStats = $this->getPromoRedemptionRepository()->getUsageStatsByPromoId($promoId);

        return [
            'operational_use_count' => $operationalUseCount,
            'max_uses' => $maxUses,
            'remaining_operational_uses' => $maxUses !== null ? max($maxUses - $operationalUseCount, 0) : null,
            'recorded_applications' => $repoStats['recorded_applications'],
            'checkout_applications' => $repoStats['checkout_applications'],
            'renewal_applications' => $repoStats['renewal_applications'],
            'active_checkout_applications' => $repoStats['active_checkout_applications'],
            'reserved_applications' => $repoStats['reserved_applications'],
            'committed_applications' => $repoStats['committed_applications'],
            'released_applications' => $repoStats['released_applications'],
            'distinct_clients' => $repoStats['distinct_clients'],
            'orders_using_promo' => $repoStats['orders_using_promo'],
        ];
    }

    private function hasPromoRedemptionHistoryById(int $promoId, ?int $redemptionCount = null): bool
    {
        $redemptionCount ??= $this->getPromoRedemptionCountById($promoId);
        if ($redemptionCount > 0) {
            return true;
        }

        $orderCount = $this->getPromoRepository()->countLinkedOrdersByPromoId($promoId);

        return $orderCount > 0;
    }

    private function applyPromoDataToEntity(Promo $promo, array $data): void
    {
        $promo
            ->setCode($data['code'] ?? $promo->getCode())
            ->setDescription($data['description'] ?? $promo->getDescription())
            ->setType((string) ($data['type'] ?? $promo->getType()))
            ->setValue($data['value'] ?? $promo->getValue())
            ->setActive((bool) ($data['active'] ?? $promo->isActive()))
            ->setFreeSetup((bool) ($data['freesetup'] ?? $promo->isFreeSetup()))
            ->setOncePerClient((bool) ($data['once_per_client'] ?? $promo->isOncePerClient()))
            ->setRecurring((bool) ($data['recurring'] ?? $promo->isRecurring()))
            ->setUsed(isset($data['used']) ? (int) $data['used'] : $promo->getUsed())
            ->setMaxUses(isset($data['maxuses']) ? (int) $data['maxuses'] : $promo->getMaxUses())
            ->setProducts($this->encodePromoSelection($data['products'] ?? $this->decodePromoSelection($promo->getProducts())))
            ->setPeriods($this->encodePromoSelection($data['periods'] ?? $this->decodePromoSelection($promo->getPeriods())))
            ->setClientGroups($this->encodePromoSelection($data['client_groups'] ?? $this->decodePromoSelection($promo->getClientGroups())))
            ->setStartAt($this->normalizePromoDateTimeObject($data['start_at'] ?? $promo->getStartAt()))
            ->setEndAt($this->normalizePromoDateTimeObject($data['end_at'] ?? $promo->getEndAt()));
    }

    /**
     * @param array<mixed>|null $selection
     */
    private function encodePromoSelection(mixed $selection): ?string
    {
        if (!is_array($selection)) {
            return null;
        }

        $selection = array_values(array_filter($selection));

        return $selection === [] ? null : json_encode($selection);
    }

    /**
     * @return array<mixed>
     */
    private function decodePromoSelection(?string $selection): array
    {
        return json_decode($selection ?? '', true) ?? [];
    }

    private function generateUniquePromoCode(?string $code): string
    {
        $baseCode = trim((string) $code) !== '' ? (string) $code : 'PROMO';

        $attempt = 1;
        do {
            $suffix = $attempt === 1 ? '-COPY' : sprintf('-COPY-%d', $attempt);
            $candidate = substr($baseCode, 0, 100 - strlen($suffix)) . $suffix;
            ++$attempt;
        } while ($this->getPromoRepository()->findOneBy(['code' => $candidate]) instanceof Promo);

        return $candidate;
    }

    private function normalizePromoDateTime(mixed $value): ?string
    {
        return !empty($value) ? date('Y-m-d H:i:s', strtotime((string) $value)) : null;
    }

    private function normalizePromoDateTimeObject(mixed $value): ?\DateTime
    {
        if ($value instanceof \DateTime) {
            return clone $value;
        }

        $normalized = $this->normalizePromoDateTime($value);

        return $normalized !== null ? new \DateTime($normalized) : null;
    }
}
