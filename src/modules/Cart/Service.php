<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Cart;

use ClientFence\Mod\Cart\Entity\Cart;
use ClientFence\Mod\Cart\Entity\CartProduct;
use ClientFence\Mod\Cart\Repository\CartProductRepository;
use ClientFence\Mod\Cart\Repository\CartRepository;
use ClientFence\Mod\Client\Entity\Client;
use ClientFence\Mod\Currency\Entity\Currency;
use ClientFence\Mod\Order\Entity\Order;
use ClientFence\Mod\Product\Entity\Product;
use ClientFence\Mod\Product\Entity\Promo;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use ClientFence\Doctrine\EntityManagerFactory;
use ClientFence\InjectionAwareInterface;

class Service implements InjectionAwareInterface
{
    /**
     * Internal cart-item config key recording which order family (one
     * add-to-cart action: the product plus its bundled domain and selected
     * addons) an item belongs to. Server-side bookkeeping only: stamped after
     * the client-input filter in addItem(), consumed and stripped at checkout
     * when per-family order group_ids are assigned.
     */
    public const CART_FAMILY_KEY = '__cart_family';

    /** ClientFence core patch: the client's service an add-on is ordered for (Order/AddonOrders.php); stamped by the server only. */
    public const CART_ADDON_FOR_KEY = '__cart_addon_for';

    protected ?\Pimple\Container $di = null;
    /** ClientFence: the currency cartProductToApiArray() prices an item in (Product/CurrencyPrices.php). */
    private ?string $priceCurrency = null;

    /** ClientFence: staff price overrides (Add New Order) by cart product id, in the cart's units; set only by staffOrder(). */
    private array $staffPrices = [];

    /** ClientFence: true while staffOrder() fills its cart, for the onBeforeProductAddedToCart listeners. */
    private bool $staffOrder = false;

    /** ClientFence: the client's language while staffOrder() runs: its order titles are written in it (Product/Translations.php). */
    private ?string $staffOrderLocale = null;

    /** ClientFence core patch: the automatic promotion of the cart being priced (cart without a code; autoPromo()). */
    private ?Promo $autoPromo = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    protected function resetEntityManager(): void
    {
        unset($this->di['em']);
        $this->di['em'] = EntityManagerFactory::create();
    }

    public function getCartRepository(): CartRepository
    {
        return $this->di['em']->getRepository(Cart::class);
    }

    public function getCartProductRepository(): CartProductRepository
    {
        return $this->di['em']->getRepository(CartProduct::class);
    }

    private function cartId(Cart|\Model_Cart $cart): ?int
    {
        return $cart instanceof Cart ? $cart->getId() : $cart->id;
    }

    private function cartCurrencyId(Cart|\Model_Cart $cart): ?int
    {
        return $cart instanceof Cart ? $cart->getCurrencyId() : $cart->currency_id;
    }

    private function cartPromoId(Cart|\Model_Cart $cart): ?int
    {
        return $cart instanceof Cart ? $cart->getPromoId() : $cart->promo_id;
    }

    private function setCartCurrencyId(Cart|\Model_Cart $cart, ?int $currencyId): void
    {
        if ($cart instanceof Cart) {
            $cart->setCurrencyId($currencyId);
        } else {
            $cart->currency_id = $currencyId;
        }
    }

    private function setCartPromoId(Cart|\Model_Cart $cart, ?int $promoId): void
    {
        if ($cart instanceof Cart) {
            $cart->setPromoId($promoId);
        } else {
            $cart->promo_id = $promoId;
        }
    }

    private function setCartSessionId(Cart|\Model_Cart $cart, ?string $sessionId): void
    {
        if ($cart instanceof Cart) {
            $cart->setSessionId($sessionId);
        } else {
            $cart->session_id = $sessionId;
        }
    }

    private function setCartUpdatedAtNow(Cart|\Model_Cart $cart): void
    {
        if ($cart instanceof Cart) {
            $cart->setUpdatedAt(new \DateTime());
        } else {
            $cart->updated_at = date('Y-m-d H:i:s');
        }
    }

    private function cartProductId(CartProduct|\Model_CartProduct $cp): ?int
    {
        return $cp instanceof CartProduct ? $cp->getId() : $cp->id;
    }

    private function cartProductCartId(CartProduct|\Model_CartProduct $cp): ?int
    {
        return $cp instanceof CartProduct ? $cp->getCartId() : $cp->cart_id;
    }

    private function cartProductProductId(CartProduct|\Model_CartProduct $cp): mixed
    {
        return $cp instanceof CartProduct ? $cp->getProductId() : $cp->product_id;
    }

    private function cartProductConfig(CartProduct|\Model_CartProduct $cp): ?string
    {
        return $cp instanceof CartProduct ? $cp->getConfig() : $cp->config;
    }

    private function persistCart(Cart|\Model_Cart $cart): void
    {
        if ($cart instanceof Cart) {
            $this->di['em']->persist($cart);
            $this->di['em']->flush();

            return;
        }

        $this->di['db']->store($cart);
    }

    private function getLegacyClient(Client|\Model_Client $client): \Model_Client
    {
        if ($client instanceof \Model_Client) {
            return $client;
        }

        $legacyClient = $this->di['db']->getExistingModelById('Client', (int) $client->getId());
        if (!$legacyClient instanceof \Model_Client) {
            throw new \ClientFence\Exception('Client compatibility model not found');
        }

        return $legacyClient;
    }

    private function clientId(Client|\Model_Client $client): int
    {
        return (int) ($client instanceof Client ? $client->getId() : $client->id);
    }

    private function clientCurrency(Client|\Model_Client $client): ?string
    {
        return $client instanceof Client ? $client->getCurrency() : $client->currency;
    }

    private function setClientCurrency(Client|\Model_Client $client, string $currency): void
    {
        if ($client instanceof Client) {
            $client->setCurrency($currency);

            return;
        }

        $client->currency = $currency;
        $this->di['db']->store($client);
    }

    /**
     * @return list<CartProduct|\Model_CartProduct>
     */
    private function findCartProducts(Cart|\Model_Cart $cart): array
    {
        if ($cart instanceof Cart) {
            return $this->getCartProductRepository()->findByCartId((int) $this->cartId($cart));
        }

        return $this->di['db']->find('CartProduct', 'cart_id = ?', [(int) $this->cartId($cart)]);
    }

    private function findCartProduct(Cart|\Model_Cart $cart, int $id): CartProduct|\Model_CartProduct|null
    {
        if ($cart instanceof Cart) {
            $product = $this->getCartProductRepository()->findOneByCartAndId((int) $this->cartId($cart), $id);

            return $product instanceof CartProduct ? $product : null;
        }

        $product = $this->di['db']->findOne('CartProduct', 'cart_id = ? AND id = ?', [(int) $this->cartId($cart), $id]);

        return $product instanceof \Model_CartProduct ? $product : null;
    }

    public function getModulePermissions(): array
    {
        return [
            'hide_permissions' => true,
        ];
    }

    public function getSearchQuery($data): array
    {
        $sql = '
            SELECT cart.id FROM cart
            LEFT JOIN currency ON cart.currency_id = currency.id
            LEFT JOIN promo ON cart.promo_id = promo.id';

        return [$sql, []];
    }

    public function transferFromOtherSession(string $sessionID): bool
    {
        $cart = $this->getSessionCart($sessionID);
        $this->setCartSessionId($cart, $this->di['session']->getId());
        $this->persistCart($cart);

        return true;
    }

    /**
     * ClientFence: the currency the visitor's prices are shown in (fixed currency prices,
     * Product/CurrencyPrices.php): the session cart's, else the signed-in client's; null for the
     * default one. Creates no cart.
     */
    public function sessionCurrencyCode(): ?string
    {
        try {
            $cart = $this->getCartRepository()->findBySessionId((string) $this->di['session']->getId());
            $db = $this->di['em']->getConnection();
            if ($cart instanceof Cart && $cart->getCurrencyId()) {
                $code = $db->fetchOne('SELECT code FROM currency WHERE id = ?', [$cart->getCurrencyId()]);
            } else {
                $clientId = (int) $this->di['session']->get('client_id');
                $code = $clientId > 0 ? $db->fetchOne('SELECT currency FROM client WHERE id = ?', [$clientId]) : null;
            }
        } catch (\Throwable) {
            return null;
        }

        return is_string($code) && $code !== '' ? $code : null;
    }

    // ClientFence core patch: the header counts cart items on every page, so a visitor without a cart gets no cart row.
    public function countSessionCartItems(): int
    {
        $cart = $this->getCartRepository()->findBySessionId((string) $this->di['session']->getId());

        return $cart instanceof Cart ? $this->getCartProductRepository()->count(['cartId' => $cart->getId()]) : 0;
    }

    public function getSessionCart(?string $sessionID = null): Cart
    {
        $sessionID ??= $this->di['session']->getId();
        $cart = $this->getCartRepository()->findBySessionId($sessionID);

        if ($cart instanceof Cart) {
            // ClientFence core patch: a client whose profile has a currency orders only in it (checkout
            // refuses another), so a cart started before signing in follows it.
            $clientId = (int) $this->di['session']->get('client_id');
            if ($clientId > 0) {
                $repository = $this->di['mod_service']('currency')->getCurrencyRepository();
                $code = $repository->getClientCurrencyCode($clientId);
                $currency = $code !== null ? $repository->findOneByCode($code) : null;
                if ($currency instanceof Currency && $cart->getCurrencyId() !== $currency->getId()) {
                    $this->changeCartCurrency($cart, $currency);
                }
            }

            return $cart;
        }

        $currencyService = $this->di['mod_service']('currency');
        /** @var \ClientFence\Mod\Currency\Repository\CurrencyRepository $currencyRepository */
        $currencyRepository = $currencyService->getCurrencyRepository();

        // Try to get client's currency if client is logged in
        $currency = null;
        $clientId = $this->di['session']->get('client_id');
        if ($clientId) {
            $currency = $currencyService->getCurrencyByClientId((int) $clientId);
        }

        // Fallback to default currency
        if (!$currency instanceof Currency) {
            $currency = $currencyRepository->findDefault();
            if (!$currency instanceof Currency) {
                throw new \ClientFence\Exception('Default currency not found');
            }
        }

        $cart = new Cart();
        $cart->setSessionId($sessionID);
        $cart->setCurrencyId($currency->getId());

        try {
            $this->di['em']->persist($cart);
            $this->di['em']->flush();
        } catch (UniqueConstraintViolationException $exception) {
            $this->resetEntityManager();
            $cart = $this->getCartRepository()->findBySessionId($sessionID);
            if (!$cart instanceof Cart) {
                throw $exception;
            }
        }

        return $cart;
    }

    public function addItem(Cart|\Model_Cart $cart, Product $product, array $data, ?\Model_ClientOrder $addonFor = null): bool
    {
        unset($data[self::CART_ADDON_FOR_KEY]);
        if ($addonFor !== null) {
            // ClientFence core patch: an add-on for the client's service (Order/AddonOrders.php) comes in that service's cycle.
            \ClientFence\Mod\Order\AddonOrders::offered($this->di, $addonFor, (int) $product->getId());
            $data['period'] = $this->isRecurrentPricing($product) ? (string) $addonFor->period : null;
            unset($data['addons'], $data['domain']);
        }
        // ClientFence core patch: staff_order is set last, so a client's cart data cannot claim it.
        $event_params = [...$data, 'cart_id' => $this->cartId($cart), 'product_id' => $this->getProductId($product), 'staff_order' => $this->staffOrder];
        $this->di['events_manager']->fire(['event' => 'onBeforeProductAddedToCart', 'params' => $event_params]);

        $productService = $this->getProductService()->getProductModuleService($product);

        if ($this->isRecurrentPricing($product)) {
            $required = [
                'period' => 'Period parameter not passed',
            ];
            $this->di['validator']->checkRequiredParamsForArray($required, $data);

            if (!$this->isPeriodEnabledForProduct($product, $data['period'])) {
                throw new \ClientFence\InformationException('Selected billing period is invalid');
            }
        }

        // ClientFence core patch: the service an add-on is for is stamped by the server only, never read from nested input.
        $addons = array_map(fn (mixed $addon): mixed => is_array($addon) ? array_diff_key($addon, [self::CART_ADDON_FOR_KEY => true]) : $addon, (array) ($data['addons'] ?? []));
        unset($data['id']);
        unset($data['addons']);

        $productConfig = json_decode($product->getConfig() ?? '', true) ?? [];

        // Collect all domains that will be added: top-level (direct domain product) and
        // nested under $data['domain'] (domain bundled with a hosting product).
        $domainsBeingAdded = [];
        $topDomain = $this->extractDomainFromConfig($data, $productConfig);
        if ($topDomain !== null) {
            $domainsBeingAdded[] = $topDomain;
        }
        if (isset($data['domain']) && is_array($data['domain'])) {
            $nestedDomain = $this->extractDomainFromConfig($data['domain'], $data + $productConfig);
            if ($nestedDomain !== null) {
                $domainsBeingAdded[] = $nestedDomain;
            }
        }

        if (!empty($domainsBeingAdded)) {
            $existingItems = $this->findCartProducts($cart);
            foreach ($existingItems as $item) {
                $itemConfig = json_decode((string) $this->cartProductConfig($item), true);
                if (!is_array($itemConfig)) {
                    continue;
                }
                // Check both top-level and nested domain shapes in the existing item's config.
                $candidates = [$this->extractDomainFromConfig($itemConfig)];
                if (isset($itemConfig['domain']) && is_array($itemConfig['domain'])) {
                    $candidates[] = $this->extractDomainFromConfig($itemConfig['domain'], $itemConfig);
                }
                foreach ($candidates as $existing) {
                    if ($existing === null) {
                        continue;
                    }
                    foreach ($domainsBeingAdded as $incoming) {
                        if (strcasecmp($existing, $incoming) === 0) {
                            throw new \ClientFence\InformationException('This domain is already in the cart.');
                        }
                    }
                }
            }
        }

        $list = [];
        $list[] = [
            'product' => $product,
            'config' => $data,
        ];

        // check for required domain product
        if (method_exists($productService, 'getDomainProductFromConfig')) {
            $dc = $productService->getDomainProductFromConfig($product, $data);
            if (isset($dc['config']) && ($dc['product'] ?? null) instanceof Product) {
                $list[] = $dc;
            }
        }

        if ($addons !== []) {
            $productService = $this->di['mod_service']('Product');
            foreach ($productService->getSelectedAddonsForCart($product, $addons) as $selectedAddon) {
                $list[] = $selectedAddon;
            }
        }

        $pendingQuantities = [];
        foreach ($list as $cartItem) {
            /** @var Product $cartProduct */
            $cartProduct = $cartItem['product'];
            $requestedQty = $this->getRequestedQuantity($cartItem['config']);
            $cartProductId = $this->getProductId($cartProduct);
            $pendingQuantities[$cartProductId] = ($pendingQuantities[$cartProductId] ?? 0) + $requestedQty;

            $reservedQty = $this->getReservedQuantityInCart($cart, $cartProductId);
            if (!$this->isStockAvailable($cartProduct, $reservedQty + $pendingQuantities[$cartProductId])) {
                throw new \ClientFence\InformationException('This item is currently out of stock');
            }
        }

        $familyToken = bin2hex(random_bytes(16));

        foreach ($list as $c) {
            $productFromList = $c['product'];
            $productFromListConfig = $this->getProductService()->prepareCartProductConfig($productFromList, $c['config'], $this->staffOrder);
            // One add-to-cart action equals one order family. Stamped after
            // prepareCartProductConfig() so the client-input filter can
            // neither strip it nor be bypassed with a forged value.
            $productFromListConfig[self::CART_FAMILY_KEY] = $familyToken;
            if ($addonFor !== null) {
                $productFromListConfig[self::CART_ADDON_FOR_KEY] = (int) $addonFor->id;
            }
            $this->addProduct($cart, $productFromList, $productFromListConfig);
        }

        $this->di['logger']->info('Added "%s" to shopping cart', $this->getProductTitle($product));

        $this->di['events_manager']->fire(['event' => 'onAfterProductAddedToCart', 'params' => $event_params]);

        return true;
    }

    public function isStockAvailable(Product|int $product, $qty)
    {
        return $this->getProductService()->isStockAvailable($product, $qty);
    }

    public function isRecurrentPricing(Product $model): bool
    {
        return $this->getProductService()->isRecurrentProductPricing($model);
    }

    public function isPeriodEnabledForProduct(Product $model, $period)
    {
        return $this->getProductService()->isProductPeriodEnabled($model, (string) $period);
    }

    protected function addProduct(Cart|\Model_Cart $cart, Product $product, array $data): bool
    {
        if ($cart instanceof Cart) {
            $item = new CartProduct();
            $item->setCartId($this->cartId($cart));
            $item->setProductId($this->getProductId($product));
            $item->setConfig(json_encode($data));
            $this->di['em']->persist($item);
            $this->di['em']->flush();
        } else {
            $item = $this->di['db']->dispense('CartProduct');
            $item->cart_id = $this->cartId($cart);
            $item->product_id = $this->getProductId($product);
            $item->config = json_encode($data);
            $this->di['db']->store($item);
        }

        return true;
    }

    /**
     * Resolve the order group_id for one cart item at checkout.
     *
     * Items stamped with the same family token share one group_id
     * ("<cart_id>_<n>"). Every non-addon order in the family is a master -
     * including a bundled-domain sibling, which is an independently managed
     * service - while only genuine addon products nest under one. Items
     * from carts built before family stamping carry no token: standalone
     * items each start their own family, while an addon row rejoins the most
     * recent family of its parent product when one exists. Anything unmatched
     * gets its own family rather than joining an unrelated one, so a paid
     * item can never be hidden as another family's addon.
     */
    private function resolveFamilyGroupId(
        Cart|\Model_Cart $cart,
        array $item,
        mixed $familyToken,
        array &$familyGroupIds,
        array &$lastGroupIdByProductId,
        int &$familyIndex,
    ): string {
        if (is_string($familyToken) && $familyToken !== '') {
            return $familyGroupIds[$familyToken] ??= $this->nextFamilyGroupId($cart, $familyIndex);
        }

        $parentProductId = isset($item['parent_id']) ? (int) $item['parent_id'] : null;
        if ($parentProductId !== null && isset($lastGroupIdByProductId[$parentProductId])) {
            return $lastGroupIdByProductId[$parentProductId];
        }

        return $this->nextFamilyGroupId($cart, $familyIndex);
    }

    private function nextFamilyGroupId(Cart|\Model_Cart $cart, int &$familyIndex): string
    {
        ++$familyIndex;

        return $this->cartId($cart) . '_' . $familyIndex;
    }

    protected function getReservedQuantityInCart(Cart|\Model_Cart $cart, int $productId): int
    {
        $reservedQty = 0;
        foreach ($this->getCartProducts($cart) as $cartProduct) {
            if ((int) $this->cartProductProductId($cartProduct) !== $productId) {
                continue;
            }

            $config = $this->getItemConfig($cartProduct);
            $reservedQty += $this->getRequestedQuantity($config);
        }

        return $reservedQty;
    }

    protected function getRequestedQuantity(array $config): int
    {
        return max(1, (int) ($config['quantity'] ?? 1));
    }

    /**
     * Extract a normalized "sld+tld" domain string from a config array.
     * Handles register_*, transfer_*, and free subdomain key pairs.
     * Returns null when the config does not describe a domain.
     */
    private function extractDomainFromConfig(array $config, array $parentConfig = []): ?string
    {
        $sld = $config['register_sld'] ?? $config['transfer_sld'] ?? null;
        $tld = $config['register_tld'] ?? $config['transfer_tld'] ?? null;
        if ($sld !== null && $tld !== null) {
            return strtolower($sld . $tld);
        }

        if (($config['action'] ?? null) === 'subdomain' && isset($config['subdomain_sld'])) {
            $baseDomain = $config['subdomain_base_domain'] ?? $parentConfig['subdomain_base_domain'] ?? null;
            if ($baseDomain !== null) {
                return strtolower($config['subdomain_sld'] . '.' . trim((string) $baseDomain, '.'));
            }
        }

        return null;
    }

    public function removeProduct(Cart|\Model_Cart $cart, $id, $removeAddons = true): bool
    {
        $cartProduct = $this->findCartProduct($cart, (int) $id);
        if (!$cartProduct instanceof CartProduct && !$cartProduct instanceof \Model_CartProduct) {
            throw new \ClientFence\Exception('Product not found');
        }

        if ($removeAddons) {
            $config_main = json_decode($this->cartProductConfig($cartProduct) ?? '', true);
            $domain_name = $config_main['domain_name'] ?? '';
            $allCartProducts = $this->findCartProducts($cart);
            foreach ($allCartProducts as $cProduct) {
                $config = json_decode($this->cartProductConfig($cProduct) ?? '', true);
                if (isset($config['parent_id']) && $config['parent_id'] == $this->cartProductProductId($cartProduct)) {
                    $domain_name_addon = $config['domain_name'] ?? '';
                    if ($domain_name && $domain_name != $domain_name_addon) {
                        continue;
                    }
                    if ($cProduct instanceof CartProduct) {
                        $this->di['em']->remove($cProduct);
                    } else {
                        $this->di['db']->trash($cProduct);
                    }
                    $this->di['logger']->info('Removed product addon from shopping cart');
                }
            }
        }

        if ($cartProduct instanceof CartProduct) {
            $this->di['em']->remove($cartProduct);
            $this->di['em']->flush();
        } else {
            $this->di['db']->trash($cartProduct);
        }

        $this->di['logger']->info('Removed product from shopping cart');

        return true;
    }

    public function changeCartCurrency(Cart|\Model_Cart $cart, Currency $currency): bool
    {
        $this->setCartCurrencyId($cart, $currency->getId());
        $this->persistCart($cart);

        $this->di['logger']->info('Changed shopping cart #%s currency to %s', $this->cartId($cart), $currency->getCode());

        return true;
    }

    public function resetCart(Cart|\Model_Cart $cart): bool
    {
        $cartProducts = $this->findCartProducts($cart);
        foreach ($cartProducts as $cartProduct) {
            if ($cartProduct instanceof CartProduct) {
                $this->di['em']->remove($cartProduct);
            } else {
                $this->di['db']->trash($cartProduct);
            }
        }
        $this->setCartPromoId($cart, null);
        $this->setCartUpdatedAtNow($cart);
        $this->persistCart($cart);

        return true;
    }

    public function removePromo(Cart|\Model_Cart $cart): bool
    {
        $this->setCartPromoId($cart, null);
        $this->setCartUpdatedAtNow($cart);
        $this->persistCart($cart);

        $this->di['logger']->info('Removed promo code from shopping cart #%s', $this->cartId($cart));

        return true;
    }

    public function applyPromo(Cart|\Model_Cart $cart, Promo $promo): bool
    {
        $promoId = $promo->getId();
        $promoCode = $promo->getCode();

        if ($this->cartPromoId($cart) == $promoId) {
            return true;
        }

        if ($this->isEmptyCart($cart)) {
            throw new \ClientFence\InformationException('Add products to your cart before applying promo code');
        }

        $this->setCartPromoId($cart, $promoId);
        $this->persistCart($cart);

        $this->di['logger']->info('Applied promo code %s to shopping cart', $promoCode);

        return true;
    }

    protected function isEmptyCart(Cart|\Model_Cart $cart): bool
    {
        $cartProducts = $this->findCartProducts($cart);

        return \ClientFence\Tools::safeCount($cartProducts) == 0;
    }

    public function rm(Cart|\Model_Cart $cart): bool
    {
        $cartProducts = $this->findCartProducts($cart);

        foreach ($cartProducts as $cartProduct) {
            if ($cartProduct instanceof CartProduct) {
                $this->di['em']->remove($cartProduct);
            } else {
                $this->di['db']->trash($cartProduct);
            }
        }

        if ($cart instanceof Cart) {
            $this->di['em']->remove($cart);
            $this->di['em']->flush();
        } else {
            $this->di['db']->trash($cart);
        }

        return true;
    }

    public function toApiArray(Cart|\Model_Cart $model, $deep = false, $identity = null): array
    {
        $products = $this->getCartProducts($model);

        $currencyService = $this->di['mod_service']('currency');
        /** @var \ClientFence\Mod\Currency\Repository\CurrencyRepository $currencyRepository */
        $currencyRepository = $currencyService->getCurrencyRepository();
        $currency = $currencyRepository->find($this->cartCurrencyId($model));
        if (!$currency instanceof Currency) {
            $currency = $currencyRepository->findDefault();
        }

        if (!$currency instanceof Currency) {
            throw new \ClientFence\Exception('Currency not found and no default currency is configured');
        }

        $items = [];
        $total = 0;
        $cart_discount = 0;
        $items_discount = 0;
        // ClientFence core patch: an automatic promotion for the signed-in client, or the client of a checkout or staff order ($identity).
        $previous = $this->autoPromo;
        $this->autoPromo = $this->autoPromo($model, $identity instanceof Client || $identity instanceof \Model_Client ? $identity : $this->signedInClient());
        $autoPromo = $this->autoPromo;
        foreach ($products as $product) {
            $p = $this->cartProductToApiArray($product, $model, $products);
            $total += $p['total'] + $p['setup_price'];
            $items_discount += $p['discount'];
            $items[] = $p;
        }
        $this->autoPromo = $previous;

        $promoId = $this->cartPromoId($model);
        if ($promoId) {
            $promo = $this->getProductService()->findPromoById($promoId);
            $promocode = $promo->getCode();
            $promoRecurring = $promo->isRecurring();
        } else {
            $promocode = null;
            $promoRecurring = $autoPromo?->isRecurring() ?? false;
        }

        return [
            'promocode' => $promocode,
            'auto_promo' => $autoPromo?->getCode(),
            // ClientFence core patch: the checkout shows the renewal after a recurring promo (the orders keep its discount_price).
            'promo_recurring' => $promoRecurring,
            'discount' => $items_discount,
            'subtotal' => $total,
            'total' => $total - $items_discount,
            'items' => $items,
            'currency' => $currency->toApiArray(),
            'subscribable' => $this->getSubscriptionPeriodFromItems($items) !== null,
        ];
    }

    private function getSubscriptionPeriodFromItems(array $items): ?string
    {
        $subscriptionPeriod = null;

        foreach ($items as $item) {
            $netSetupPrice = (float) ($item['setup_price'] ?? 0) - (float) ($item['discount_setup'] ?? 0);
            if ($netSetupPrice > 0) {
                return null;
            }

            if ((float) ($item['total'] ?? 0) <= 0) {
                continue;
            }
            // ClientFence core patch: an add-on's first, prorated price is not what it renews at (Order/AddonOrders.php).
            if (!empty($item['addon_due'])) {
                return null;
            }

            $period = $item['period'] ?? null;
            if (empty($period)) {
                return null;
            }

            if ($subscriptionPeriod === null) {
                $subscriptionPeriod = $period;

                continue;
            }

            if ($subscriptionPeriod !== $period) {
                return null;
            }
        }

        return $subscriptionPeriod;
    }

    /**
     * ClientFence core patch: the automatic promotion of a cart without a code (Product\Service::findAutoPromo()); none
     * for a visitor who is not signed in. A code the client enters always replaces it.
     */
    private function autoPromo(Cart|\Model_Cart $cart, Client|\Model_Client|null $client): ?Promo
    {
        if ($client === null || $this->cartPromoId($cart)) {
            return null;
        }
        // The setup fee counts toward a free-setup promotion's saving, the options' part only where it covers them (findAutoPromo()).
        $lines = array_map(function ($p): array {
            $view = $this->getProductService()->getCartProductViewData($p);

            return ['product_id' => (int) $this->cartProductProductId($p), 'config' => $this->getItemConfig($p), 'setup' => (float) $view['setup_price'], 'options_setup' => $view['options_setup_price']];
        }, $this->findCartProducts($cart));

        return $lines === [] ? null : $this->getProductService()->findAutoPromo($client, $lines);
    }

    private function signedInClient(): ?\Model_Client
    {
        try {
            return $this->di['loggedin_client'];
        } catch (\Exception) {
            return null;
        }
    }

    public function isClientAbleToUsePromo(Client|\Model_Client $client, Promo $promo)
    {
        return $this->getProductService()->canClientUsePromo($client, $promo);
    }

    public function promoCanBeApplied(Promo $promo): bool
    {
        return $this->getProductService()->promoCanBeApplied($promo);
    }

    public function isPromoAvailableForClientGroup(Promo $promo)
    {
        return $this->getProductService()->isPromoAvailableForClientGroup($promo);
    }

    protected function clientHadUsedPromo(Client|\Model_Client $client, Promo $promo): bool
    {
        return $this->getProductService()->clientHasActivePromoApplication($client, $promo);
    }

    /**
     * In-transaction re-check of the once-per-client limit. The fail-fast check in
     * checkoutCart() runs before the transaction opens, so concurrent checkouts can all pass
     * it; the client-row mutex serializes them here instead. Throws the same exception as a
     * normal reuse.
     */
    private function assertClientAbleToUsePromoForUpdate(Client|\Model_Client $client, Promo $promo): void
    {
        if ($this->getProductService()->clientHasActivePromoApplicationForUpdate($client, $promo)) {
            throw new \ClientFence\InformationException('You have already used this promo code. Please remove the promo code and checkout again.', null, 9874);
        }
    }

    public function getCartProducts(Cart|\Model_Cart $model): array
    {
        return $this->findCartProducts($model);
    }

    public function checkoutCart(Cart|\Model_Cart $cart, Client|\Model_Client $client, $gateway_id = null): array
    {
        // ClientFence core patch (CF-172): only a payment method the checkout offers, as invoice/choose_gateway checks.
        if ($gateway_id !== null && $gateway_id !== '') {
            $currency = (string) $this->toApiArray($cart, false, $client)['currency']['code'];
            if (!in_array((int) $gateway_id, array_column(\ClientFence\Mod\Invoice\Receipts::paymentMethods($this->di, 0, $currency, true), 'id'), true)) {
                throw new \ClientFence\InformationException('Payment method not found', null, 813);
            }
        }
        $promoId = $this->cartPromoId($cart);
        if ($promoId) {
            $promo = $this->getProductService()->findPromoById($promoId);
            if (!$this->isClientAbleToUsePromo($client, $promo)) {
                throw new \ClientFence\InformationException('You have already used this promo code. Please remove the promo code and checkout again.', null, 9874);
            }

            if (!$this->isPromoAvailableForClientGroup($promo)) {
                throw new \ClientFence\InformationException('Promo code cannot be applied to your account');
            }
        }

        $this->di['events_manager']->fire(
            [
                'event' => 'onBeforeClientCheckout',
                'params' => [
                    'ip' => $this->di['request']->getClientIp(),
                    'client_id' => $this->clientId($client),
                    'cart_id' => $this->cartId($cart),
                ],
            ]
        );

        // ClientFence core patch: an order from a hosting network may wait for staff review (Antispam/Networks.php, Order/Holds.php).
        // ClientFence core patch: so may one the fraud check scores high (Order/FraudCheck.php, called before any lock) or one waiting for an ID document (Client/IdDocuments.php).
        $ip = (string) $this->di['request']->getClientIp();
        $hold = \ClientFence\Mod\Order\Holds::combine(
            \ClientFence\Mod\Antispam\Networks::hold($this->di, $ip, $this->clientId($client)),
            \ClientFence\Mod\Order\FraudCheck::hold($this->di, $this->clientId($client), $ip, function () use ($cart, $client): array {
                $ca = $this->toApiArray($cart, false, $client);

                return [(float) $ca['total'], (string) $ca['currency']['code']];
            }),
            \ClientFence\Mod\Client\IdDocuments::hold($this->di, $this->clientId($client)),
        );

        // ClientFence core patch: the client's credit lock is held until the orders are committed and the
        // cart is gone, so a double submit finds an empty cart and two checkouts cannot spend the same credit.
        [$order, $invoice, $orders] = \ClientFence\Tools::withLock($this->di, 'fb:credit_payment:client:' . $this->clientId($client), function () use ($client, $gateway_id, $cart, $hold): array {
            $result = $this->createFromCart($client, $gateway_id, null, null, $hold);
            $this->rm($cart);

            return $result;
        });
        // ClientFence core patch: an order sets an Inactive client Active again (Client/StatusUpdate.php).
        \ClientFence\Mod\Client\StatusUpdate::ordered($this->di, $this->clientId($client));

        $this->di['logger']->info('Checked out shopping cart');

        $this->di['events_manager']->fire(
            [
                'event' => 'onAfterClientOrderCreate',
                'params' => [
                    'ip' => $this->di['request']->getClientIp(),
                    'client_id' => $this->clientId($client),
                    'id' => $order->getId(),
                ],
            ]
        );

        $result = [
            'gateway_id' => $gateway_id,
            'invoice_hash' => null,
            'order_id' => $order->getId(),
            'orders' => $orders,
        ];
        // ClientFence core patch: a held order's invoice stays a draft: nothing to pay or set up until staff approve.
        if ($hold !== null) {
            \ClientFence\Mod\Order\Holds::notify($this->di, (int) $order->getId(), (string) $order->getTitle());

            // ClientFence core patch: the checkout page then asks for the ID document (Client/IdDocuments.php).
            return $result + ['held' => true, 'id_document' => \ClientFence\Mod\Client\IdDocuments::needed($this->di, $this->clientId($client))];
        }

        // invoice may not be created if total is 0
        $isInvoiceUnpaid = $invoice instanceof \Model_Invoice
            && $invoice->status === \Model_Invoice::STATUS_UNPAID;

        if ($isInvoiceUnpaid) {
            $result['invoice_hash'] = $invoice->hash;
        }
        // ClientFence core patch: as WHMCS's PayPal billing agreement gateways, a free order that renews sends a client
        // without a saved payment method to PayPal to set up automatic payments (PayPal Auto-Pay "Free orders"), or to
        // PayTabs, eWAY or Mollie to save a card, or to GoCardless to set up a Direct Debit (their "Free orders").
        $user = \ClientFence\Mod\Client\Users::context($this->di);
        foreach (['payment-methods/paypal-autopay' => \ClientFence\Mod\Invoice\SavedCards::autopay($this->di), 'payment-methods/paytabs' => \ClientFence\Mod\Invoice\SavedCards::paytabs($this->di), 'payment-methods/eway' => \ClientFence\Mod\Invoice\SavedCards::eway($this->di), 'payment-methods/mollie' => \ClientFence\Mod\Invoice\SavedCards::mollie($this->di), 'payment-methods/gocardless' => \ClientFence\Mod\Invoice\SavedCards::gocardless($this->di)] as $path => $adapter) {
            if (!$invoice instanceof \Model_Invoice && $adapter !== null && $adapter->option('free_orders') && $adapter->option('save_only')
                && ($user === null || $user['owner']) && $order->getPeriod() && \ClientFence\Mod\Invoice\SavedCards::method($this->di, $this->clientId($client)) === null) {
                $result['redirect'] = $this->di['url']->link($path, ['next' => 'order/service/manage/' . $order->getId()]);

                break;
            }
        }

        return $result;
    }

    /**
     * @param array|null $staff ClientFence core patch: staff Add New Order (staffOrder()): `invoice` (issue the first one), `activate`,
     *                          `quote` (stop once the invoice is prepared) and the Order\Service::approveStaffInvoice() fields
     */
    /**
     * ClientFence core patch: a client cannot order a domain again while their earlier order for it waits for setup.
     *
     * @throws \ClientFence\InformationException
     */
    private function assertDomainNotPending(int $clientId, array $item): void
    {
        // The order form sends both tabs' fields, so the action picks the domain (Servicedomain generateOrderTitle()).
        $name = static fn (array $c): string => strtolower(($c['action'] ?? '') === 'transfer' ? ($c['transfer_sld'] ?? '') . ($c['transfer_tld'] ?? '') : ($c['register_sld'] ?? '') . ($c['register_tld'] ?? ''));
        $domain = $name($item);
        $rows = $this->di['db']->getAll('SELECT config FROM client_order WHERE client_id = :client_id AND service_type = :type AND status IN (:pending, :failed)', [
            ':client_id' => $clientId,
            ':type' => 'domain',
            ':pending' => Order::STATUS_PENDING_SETUP,
            ':failed' => Order::STATUS_FAILED_SETUP,
        ]);
        foreach ($rows as $row) {
            $config = json_decode((string) $row['config'], true) ?: [];
            if ($name($config) === $domain) {
                throw new \ClientFence\InformationException(':domain is already in your orders, waiting for setup.', [':domain' => $domain]);
            }
        }
    }

    /**
     * ClientFence core patch (CF-239): the client details a registered or transferred domain sends the registrar as its
     * contact (Servicedomain\Service::_getD()), by field with its label.
     */
    public const REGISTRANT_FIELDS = ['address_1' => 'Address Line 1', 'city' => 'City', 'postcode' => 'Zip/Postal Code', 'phone' => 'Phone Number', 'country' => 'Country'];

    /**
     * ClientFence core patch (CF-239): the registrant details the client has not filled in (blank, also a no-break space, counts as missing).
     *
     * @return list<string> field names of REGISTRANT_FIELDS
     */
    public function registrantMissing(Client|\Model_Client $client): array
    {
        $legacy = $this->getLegacyClient($client);

        return array_values(array_filter(array_keys(self::REGISTRANT_FIELDS), static fn (string $field): bool => preg_match('/[^\s\p{Z}]/u', (string) $legacy->{$field}) !== 1));
    }

    /**
     * ClientFence core patch (CF-239): a domain registered or transferred in the client's name needs their address and
     * phone, as WHMCS asks at checkout: without them the registrar refused it at activation, after payment. An existing
     * domain (staff "Already set up") gets no registrar action.
     *
     * @throws \ClientFence\InformationException
     */
    private function assertRegistrantDetails(array $items, Client|\Model_Client $client, bool $staff): void
    {
        $registers = array_filter($items, static fn (array $item): bool => ($item['type'] ?? '') === 'domain' && in_array($item['action'] ?? null, ['register', 'transfer'], true) && empty($item['import']));
        $missing = $registers === [] ? [] : $this->registrantMissing($client);
        if ($missing === []) {
            return;
        }
        $fields = implode(', ', array_map(static fn (string $field): string => __trans(self::REGISTRANT_FIELDS[$field]), $missing));

        throw $staff
            ? new \ClientFence\InformationException('The client\'s profile has no :fields. A domain is registered in the client\'s name, so add them to the profile first.', [':fields' => $fields])
            : new \ClientFence\InformationException('Add your :fields to your profile: the domain is registered in your name and the registry needs them.', [':fields' => $fields]);
    }

    /**
     * @param array{reason: string, note: string}|null $hold ClientFence core patch: the orders wait for staff review (Order/Holds.php):
     *                                                    the invoice stays a draft and nothing is paid or set up
     */
    public function createFromCart(Client|\Model_Client $client, $gateway_id = null, Cart|\Model_Cart|null $cart = null, ?array $staff = null, ?array $hold = null): array
    {
        $cart ??= $this->getSessionCart();
        $ca = $this->toApiArray($cart, false, $client);
        if (\ClientFence\Tools::safeCount($ca['items']) == 0) {
            throw new \ClientFence\InformationException('Cannot checkout an empty cart');
        }
        // ClientFence core patch (CF-239): every checkout and staff order (quotes too) checks the registrant details first.
        $this->assertRegistrantDetails($ca['items'], $client, $staff !== null);

        $currencyService = $this->di['mod_service']('currency');
        /** @var \ClientFence\Mod\Currency\Repository\CurrencyRepository $currencyRepository */
        $currencyRepository = $currencyService->getCurrencyRepository();
        $currency = $currencyRepository->find($this->cartCurrencyId($cart));
        if (!$currency instanceof Currency) {
            $currency = $currencyRepository->findDefault();
            if (!$currency instanceof Currency) {
                throw new \ClientFence\Exception('Default currency not found.');
            }
        }
        $currencyCode = $currency->getCode();

        $clientService = $this->di['mod_service']('client');
        $taxed = $clientService->isClientTaxable($client);
        $promoId = $this->cartPromoId($cart);
        // ClientFence core patch: without a code, the client's automatic promotion that toApiArray() found is used as a code would be.
        $promoProductService = $promoId || !empty($ca['auto_promo']) ? $this->getProductService() : null;
        $promo = $promoId ? $promoProductService?->findPromoById($promoId) : ($promoProductService?->findActivePromoByCode($ca['auto_promo']));
        $autoPromo = $promoId ? null : $promo;

        $reservedOrderIds = [];
        $reservedCount = 0;

        if (!$this->clientCurrency($client)) {
            $this->setClientCurrency($client, $currencyCode);
            if ($client instanceof Client) {
                $this->di['em']->persist($client);
            }
        }

        $legacyClient = $this->getLegacyClient($client);
        if (!$legacyClient->currency) {
            $legacyClient->currency = $currencyCode;
            $this->di['db']->store($legacyClient);
        }

        $previousAutoPromo = $this->autoPromo;
        $this->autoPromo = $autoPromo;

        try {
            return $this->di['em']->wrapInTransaction(function () use ($ca, $cart, $client, $legacyClient, $currency, $currencyCode, $gateway_id, $taxed, $promo, $promoProductService, $staff, $hold, &$reservedOrderIds, &$reservedCount) {
                if ($promo instanceof Promo) {
                    $this->assertClientAbleToUsePromoForUpdate($client, $promo);
                }

                if ($this->clientCurrency($client) != $currencyCode) {
                    throw new \ClientFence\InformationException('Selected currency :selected does not match your profile currency :code. Please change cart currency to continue.', [':selected' => $currencyCode, ':code' => $this->clientCurrency($client)]);
                }

                $orders = [];
                $invoice_items = [];
                $firstPeriodOnly = [];
                $invoiceModel = null;
                $master_order = null;
                $requestedProductQuantities = [];
                $familyGroupIds = [];
                $lastGroupIdByProductId = [];
                $familyIndex = 0;

                foreach ($this->getCartProducts($cart) as $p) {
                    $item = $this->cartProductToApiArray($p, $cart, null, $currencyCode);
                    // Family bookkeeping is cart-transient: consume it for
                    // grouping, then keep it out of the stored order config.
                    $familyToken = $item[self::CART_FAMILY_KEY] ?? null;
                    unset($item[self::CART_FAMILY_KEY]);
                    // ClientFence core patch: an add-on for the client's own service (Order/AddonOrders.php), checked again now.
                    $addonFor = (int) ($item[self::CART_ADDON_FOR_KEY] ?? 0) > 0 ? $this->di['db']->load('ClientOrder', (int) $item[self::CART_ADDON_FOR_KEY]) : null;
                    [$recurringPrice, $recurringDiscount, $promoLine] = [$item['recurring_price'], $item['recurring_discount'], $item['promo_line'] ?? false];
                    unset($item[self::CART_ADDON_FOR_KEY], $item['recurring_price'], $item['recurring_discount'], $item['promo_line']);
                    $product = $this->getProductService()->findProductById((int) $item['product_id']);
                    if ($addonFor !== null) {
                        if (!$addonFor instanceof \Model_ClientOrder || (int) $addonFor->client_id !== $this->clientId($client)) {
                            throw new \ClientFence\InformationException('This add-on cannot be ordered for this service.');
                        }
                        \ClientFence\Mod\Order\AddonOrders::offered($this->di, $addonFor, (int) $item['product_id']);
                        if (($item['period'] ?? null) !== ($this->isRecurrentPricing($product) ? (string) $addonFor->period : null)) {
                            throw new \ClientFence\InformationException('This add-on cannot be ordered for this service.');
                        }
                        $item['addon_for'] = (int) $addonFor->id;
                    }

                    // ClientFence core patch: staff may order a disabled product, as with order/create (staffOrder()).
                    if ($product->getStatus() !== 'enabled' && $staff === null) {
                        throw new \ClientFence\InformationException('Unable to complete order. One or more of the selected products are invalid.');
                    }

                    $requestedQty = $this->getRequestedQuantity($item);
                    $productId = (int) $product->getId();
                    $requestedProductQuantities[$productId] = ($requestedProductQuantities[$productId] ?? 0) + $requestedQty;
                    if (!$this->isStockAvailable($product, $requestedProductQuantities[$productId])) {
                        throw new \ClientFence\InformationException('Unable to complete order. One or more selected products are out of stock.');
                    }

                    /*
                     * Convert the domain name to lowercase letters.
                     * Using a capital letter in a domain name still points to the same name, so this isn't going to break anything
                     * It will, however, avoid instances like this when a domain name is entered with a capital letter:
                     * https://github.com/boxbilling/boxbilling/discussions/1022#discussioncomment-1311819
                     */
                    if ($item['type'] === 'domain' || $item['type'] === 'hosting') {
                        $item['register_sld'] = (isset($item['register_sld'])) ? strtolower((string) $item['register_sld']) : null;
                        $item['transfer_sld'] = (isset($item['transfer_sld'])) ? strtolower((string) $item['transfer_sld']) : null;
                        $item['sld'] = (isset($item['sld'])) ? strtolower((string) $item['sld']) : null;
                        $item['domain']['owndomain_sld'] = (isset($item['domain']['owndomain_sld'])) ? strtolower((string) $item['domain']['owndomain_sld']) : null;
                        $item['domain']['register_sld'] = (isset($item['domain']['register_sld'])) ? strtolower((string) $item['domain']['register_sld']) : null;
                        $item['domain']['transfer_sld'] = (isset($item['domain']['transfer_sld'])) ? strtolower((string) $item['domain']['transfer_sld']) : null;

                        // Domain TLD must begin with a period - add if not present for owndomain.
                        $item['domain']['owndomain_tld'] = (isset($item['domain']['owndomain_tld'])) ? (str_contains((string) $item['domain']['owndomain_tld'], '.') ? $item['domain']['owndomain_tld'] : '.' . $item['domain']['owndomain_tld']) : null;
                    }

                    if ($staff === null && $item['type'] === 'domain' && in_array($item['action'] ?? null, ['register', 'transfer'], true)) {
                        $this->assertDomainNotPending($this->clientId($client), $item);
                    }

                    $order = new Order();
                    $order->setClientId($this->clientId($client));
                    $order->setPromoId($promoLine ? $promo?->getId() : null);
                    $order->setProductId($item['product_id']);
                    $order->setFormId($item['form_id']);

                    // group_master marks "is not an addon", not "was first in
                    // the cart": one family shares one group_id, every
                    // non-addon order in it is a master, only genuine addons
                    // nest under one.
                    $groupId = $addonFor !== null ? $this->addonGroupId($addonFor) : $this->resolveFamilyGroupId($cart, $item, $familyToken, $familyGroupIds, $lastGroupIdByProductId, $familyIndex);
                    $order->setGroupId($groupId);
                    $order->setGroupMaster($addonFor === null && !$product->isAddon());
                    $lastGroupIdByProductId[(int) $product->getId()] = $groupId;
                    $order->setInvoiceOption('issue-invoice');
                    $order->setTitle($item['title']);
                    $order->setCurrency($currencyCode);
                    $order->setServiceType($item['type']);
                    $order->setUnit($item['unit'] ?? null);
                    $order->setPeriod($item['period'] ?? null);
                    $order->setQuantity($item['quantity'] ?? null);
                    // ClientFence core patch (CF-145): a first period to a set due date (an add-on, a prorata product) keeps the full price.
                    $firstPart = $addonFor !== null || !empty($item['addon_due']);
                    $order->setPrice(($firstPart ? $recurringPrice : $item['price']) * $currency->getConversionRate());
                    $order->setDiscount(($firstPart ? $recurringDiscount : $item['discount_price']) * $currency->getConversionRate());
                    $order->setStatus(Order::STATUS_PENDING_SETUP);
                    $order->setNotes($item['notes'] ?? null);
                    $order->setConfig(json_encode($item));
                    $this->di['em']->persist($order);
                    $this->di['em']->flush();

                    $orders[] = $order;
                    if ($firstPart) {
                        $firstPeriodOnly[] = $order->getId();
                    }

                    // Reserve promo capacity at order creation time.
                    // ClientFence core patch: only for a line the promo discounts (every line of the cart used one: a
                    // product with its add-on took two uses, and a code with uses left was refused for a larger cart).
                    if ($promo instanceof Promo && $promoProductService !== null && $promoLine && abs((float) ($item['discount'] ?? 0)) > 0) {
                        $promoProductService->reservePromoForOrder($promo, $order);
                        $reservedOrderIds[] = $order->getId();
                        ++$reservedCount;
                    }

                    $orderService = $this->di['mod_service']('order');
                    $orderService->saveStatusChange($order, 'Order Created');

                    $invoice_items[] = [
                        // ClientFence core patch: configurable options (Product/Options.php) are listed on the line.
                        'title' => \ClientFence\Mod\Product\Options::titleWith((string) $order->getTitle(), is_array($item['option_lines'] ?? null) ? $item['option_lines'] : [])
                            . (!empty($item['addon_due']) ? ' (' . ($item['_start_date'] ?? date('Y-m-d')) . ' - ' . substr((string) $item['addon_due'], 0, 10) . ')' : ''),
                        // ClientFence core patch: an add-on for a service is charged to the service's due date (Order/AddonOrders.php).
                        // A prorata product is charged to its prorata day (CF-145).
                        'price' => $firstPart ? $item['price'] * $currency->getConversionRate() : $order->getPrice(),
                        'quantity' => $order->getQuantity(),
                        'unit' => $order->getUnit(),
                        'period' => $order->getPeriod(),
                        'taxed' => $taxed,
                        'type' => \Model_InvoiceItem::TYPE_ORDER,
                        'rel_id' => $order->getId(),
                        'task' => \Model_InvoiceItem::TASK_ACTIVATE,
                    ];

                    $lineDiscount = $firstPart ? $item['discount_price'] * $currency->getConversionRate() : $order->getDiscount();
                    if ($lineDiscount > 0) {
                        $invoice_items[] = [
                            // ClientFence core patch: a promotion's discount is named as on its renewals, so the client sees which one it is.
                            'title' => $promo instanceof Promo && $promoLine
                                ? $this->getProductService()->getPromoDiscountTitle($promo, (string) $order->getCurrency())
                                : __trans('Discount: :product', [':product' => $order->getTitle()]),
                            'price' => $lineDiscount * -1,
                            'quantity' => 1,
                            'unit' => 'discount',
                            'rel_id' => $order->getId(),
                            'taxed' => $taxed,
                        ];
                    }

                    if ($item['setup_price'] > 0) {
                        $setup_price = ($item['setup_price'] * $currency->getConversionRate()) - ($item['discount_setup'] * $currency->getConversionRate());
                        $invoice_items[] = [
                            'title' => __trans(':product setup', [':product' => $order->getTitle()]),
                            'price' => $setup_price,
                            'quantity' => 1,
                            'unit' => 'service',
                            // ClientFence core patch: deleting an unprovisioned order must remove its setup fee too.
                            'rel_id' => $order->getId(),
                            'taxed' => $taxed,
                        ];
                    }

                    $master_order ??= $order;
                }
                // ClientFence core patch: the affiliate cookie refers a client's own checkout, never a staff order (Client/Affiliates/Link.php).
                if ($staff === null) {
                    \ClientFence\Mod\Client\Affiliates\Link::attribute($this->di, $this->clientId($client), array_map(static fn (Order $o): ?int => $o->getId(), $orders));
                }

                // A quote (staffOrder()) stops before anything is paid, used or activated, a free order too.
                if (!empty($staff['quote']) && $ca['total'] <= 0) {
                    return [$master_order, null, []];
                }
                if ($ca['total'] > 0 && ($staff === null || !empty($staff['invoice']))) { // crete invoice if order total > 0
                    $invoiceService = $this->di['mod_service']('Invoice');
                    // ClientFence core patch: catalog prices may include tax (Invoice\Service::applyInclusiveTax()).
                    $invoiceModel = $invoiceService->prepareInvoice($legacyClient, ['client_id' => $this->clientId($client), 'items' => $invoice_items, 'gateway_id' => $gateway_id, 'prices_include_tax' => true]);
                    if (!empty($staff['quote'])) {
                        return [$master_order, $invoiceModel, []];
                    }

                    // ClientFence core patch: tryPayWithCredits() compares the balance with the invoice total in the client's currency (the cart total is in the default one).
                    // A staff order applies the credit, or marks it paid, as staff chose. A held order's invoice stays a draft (Order/Holds.php).
                    if ($hold === null) {
                        $staff === null
                            ? $invoiceService->approveInvoice($invoiceModel, ['id' => $invoiceModel->id, 'use_credits' => true])
                            : $this->di['mod_service']('order')->approveStaffInvoice($invoiceModel, $staff);
                    }

                    $isUnpaid = $hold === null && $invoiceModel instanceof \Model_Invoice
                        && $invoiceModel->status === \Model_Invoice::STATUS_UNPAID;

                    if ($isUnpaid) {
                        // Legacy RedBean models expose ids as numeric strings; the Doctrine
                        // Order entity requires a strict ?int, so normalize it here.
                        $invoiceId = (int) $invoiceModel->id;
                        foreach ($orders as $order) {
                            $order->setUnpaidInvoiceId($invoiceId);
                            $this->di['em']->persist($order);
                        }
                        $this->di['em']->flush();
                    }
                }

                if ($promo instanceof Promo && $promoProductService !== null) {
                    // ClientFence core patch: a held checkout's uses wait for staff, a free one too (Order/Holds.php).
                    $redemptionStatus = $hold !== null || $invoiceModel instanceof \Model_Invoice
                        && $invoiceModel->status === \Model_Invoice::STATUS_UNPAID
                        ? \ClientFence\Mod\Product\Entity\PromoRedemption::STATUS_RESERVED
                        : \ClientFence\Mod\Product\Entity\PromoRedemption::STATUS_COMMITTED;
                    $checkoutInvoice = $invoiceModel instanceof \Model_Invoice ? $invoiceModel : null;

                    $promoProductService->createCheckoutPromoRedemptions($promo, $client, array_values(array_filter($orders, static fn ($o): bool => in_array($o->getId(), $reservedOrderIds, true))), $checkoutInvoice, $redemptionStatus);
                }

                // Order rows have been flushed. The legacy service modules
                // can read them through the shared checkout connection.
                $orderService = $this->di['mod_service']('Order');
                $ids = [];
                foreach ($orders as $order) {
                    $ids[] = $order->getId();
                    if ($hold !== null) {
                        continue;
                    }
                    $oa = $orderService->toApiArray($order, false, $client);
                    $product = $this->getProductService()->findProductById((int) $oa['product_id']);

                    try {
                        if ($product->getSetup() == \ClientFence\Mod\Product\Service::SETUP_AFTER_ORDER) {
                            $orderService->activateOrder($order);
                        }

                        // ClientFence core patch: an add-on charged nothing for the days left to its service's due date is free now (Order/AddonOrders.php).
                        if ($ca['total'] <= 0 && $product->getSetup() == \ClientFence\Mod\Product\Service::SETUP_AFTER_PAYMENT && ($oa['total'] - $oa['discount'] <= 0 || in_array($order->getId(), $firstPeriodOnly, true))) {
                            $orderService->activateOrder($order);
                        }

                        $isPaid = $invoiceModel instanceof \Model_Invoice
                            && $invoiceModel->status === \Model_Invoice::STATUS_PAID;

                        // ClientFence core patch: paying from the balance already ran the invoice's activation
                        // task; activating again sent a second "activated" email (read fresh: it ran outside Doctrine).
                        if ($ca['total'] > 0 && $product->getSetup() == \ClientFence\Mod\Product\Service::SETUP_AFTER_PAYMENT && $isPaid
                            && $this->di['dbal']->fetchOne('SELECT status FROM client_order WHERE id = ?', [$order->getId()]) === Order::STATUS_PENDING_SETUP) {
                            $orderService->activateOrder($order);
                        }

                        // ClientFence core patch: staff may activate the orders at once, before payment (Add New Order "Active").
                        if (!empty($staff['activate']) && $this->di['dbal']->fetchOne('SELECT status FROM client_order WHERE id = ?', [$order->getId()]) === Order::STATUS_PENDING_SETUP) {
                            $orderService->activateOrder($order);
                        }
                    } catch (\Throwable $e) {
                        // An escaped failure here would roll back the whole
                        // wrapInTransaction() below, including every order
                        // already created for this cart - not just this one.
                        $this->di['logger']->error('Order activation failed after checkout: %s', $e->getMessage());
                        $notes = "Order could not be activated after checkout due to error: {$e->getMessage()}.";
                        $orderService->orderStatusAdd($order, Order::STATUS_FAILED_SETUP, $notes);
                    }
                }

                if ($hold !== null) {
                    \ClientFence\Mod\Order\Holds::add($this->di['em']->getConnection(), $ids, $this->clientId($client), isset($invoiceModel) ? (int) $invoiceModel->id : null, $hold['reason'], $hold['note']);
                }

                return [
                    $master_order,
                    $invoiceModel ?? null,
                    $ids,
                ];
            });
        } catch (\Throwable $e) {
            if ($promo instanceof Promo && $reservedCount > 0) {
                try {
                    $promoProductService->compensateCheckoutPromoFailure($promo, $reservedOrderIds, $reservedCount);
                } catch (\Throwable $compensationError) {
                    $this->di['logger']->error('Failed to compensate promo checkout failure', [
                        'exception' => $compensationError->getMessage(),
                        'promo_id' => $promo->getId(),
                    ]);
                }
            }

            throw $e;
        } finally {
            $this->autoPromo = $previousAutoPromo;
        }
    }

    /**
     * ClientFence core patch: staff Add New Order, as WHMCS: products and domains for a client in one order with one
     * invoice, priced and checked as at checkout, in a cart of their own. $items: [{product_id (none: a domain),
     * period, quantity, config, price, domain_price}] (staff overrides of the item's price and of the domain a hosting
     * item registers or transfers, in the client's currency). $staff: the
     * createFromCart() staff options; null quotes the order: it is made and rolled back, and its totals returned.
     */
    public function staffOrder(\Model_Client $client, array $items, string $promoCode, ?int $gatewayId, ?array $staff): array
    {
        if ($items === []) {
            throw new \ClientFence\InformationException('Add a product or a domain to the order.');
        }
        $currencyRepository = $this->di['mod_service']('currency')->getCurrencyRepository();
        $currency = ($client->currency ? $currencyRepository->findOneByCode($client->currency) : null) ?? $currencyRepository->findDefault();
        if (!$currency instanceof Currency) {
            throw new \ClientFence\Exception('Default currency not found.');
        }
        $run = function () use ($client, $items, $promoCode, $gatewayId, $staff, $currency): array {
            $this->staffOrder = true;
            $this->staffOrderLocale = \ClientFence\Mod\Product\Translations::locale($client->lang);
            $cart = new Cart();
            $cart->setSessionId('staff-' . bin2hex(random_bytes(13))); // session_id holds 32 characters
            $cart->setCurrencyId($currency->getId());
            $this->di['em']->persist($cart);
            $this->di['em']->flush();
            foreach ($items as $item) {
                $product = empty($item['product_id'])
                    ? ($this->getProductService()->getMainDomainProduct() ?? throw new \ClientFence\InformationException('Domains are not sold in this store.'))
                    : $this->getProductService()->findProductById((int) $item['product_id']);
                $before = array_map($this->cartProductId(...), $this->findCartProducts($cart));
                $config = is_array($item['config'] ?? null) ? $item['config'] : [];
                // ClientFence core patch: a backdated staff order (Add New Order "Start date"), the item's and the domain it orders.
                $config = self::staffStartDate($config);
                if (is_array($config['domain'] ?? null)) {
                    $config['domain'] = self::staffStartDate($config['domain']);
                }
                // ClientFence core patch: Add Add-on on a service (Order/AddonOrders.php): only one of this client's services.
                $addonFor = empty($item['addon_for']) ? null : $this->di['db']->getExistingModelById('ClientOrder', (int) $item['addon_for'], 'Order not found');
                if ($addonFor !== null && (int) $addonFor->client_id !== (int) $client->id) {
                    throw new \ClientFence\InformationException('Order not found');
                }
                $this->addItem($cart, $product, ['period' => $item['period'] ?? null, 'quantity' => $item['quantity'] ?? 1] + $config, $addonFor);
                // The item's own line is the first one added; a hosting plan adds its domain after it.
                $added = array_values(array_diff(array_map($this->cartProductId(...), $this->findCartProducts($cart)), $before));
                foreach (['price' => 0, 'domain_price' => 1] as $key => $line) {
                    if (isset($item[$key], $added[$line]) && $item[$key] !== '') {
                        $this->staffPrices[$added[$line]] = \ClientFence\Validation\PriceValidator::validateAmount($item[$key]) / $currency->getConversionRate();
                    }
                }
            }
            if ($promoCode !== '') {
                $promo = $this->findActivePromoByCode($promoCode);
                if (!$promo instanceof Promo || !$this->promoCanBeApplied($promo) || !$this->isClientAbleToUsePromo($client, $promo)
                    || !$this->getProductService()->isPromoAvailableForClientGroup($promo, $client)) {
                    throw new \ClientFence\InformationException('This promotion code cannot be used for this client.');
                }
                $this->applyPromo($cart, $promo);
            }
            $quote = $this->toApiArray($cart, false, $client);
            [, $invoice, $ids] = $this->createFromCart($client, $gatewayId, $cart, $staff ?? ['invoice' => true, 'quote' => true]);
            if ($staff !== null) {
                $this->rm($cart);
            }

            return [$quote, $invoice, $ids];
        };

        try {
            if ($staff !== null) {
                // As checkout: the client's credit lock is held until the orders are committed.
                [, $invoice, $ids] = \ClientFence\Tools::withLock($this->di, 'fb:credit_payment:client:' . (int) $client->id, fn (): array => $this->di['dbal']->transactional($run));
                \ClientFence\Mod\Client\StatusUpdate::ordered($this->di, (int) $client->id);

                return ['order_ids' => $ids, 'invoice_id' => $invoice instanceof \Model_Invoice ? (int) $invoice->id : null];
            }
            $this->di['dbal']->beginTransaction();
            try {
                [$cart, $invoice] = $run();
                $invoiceService = $this->di['mod_service']('Invoice');
                $rate = $currency->getConversionRate();
                $total = $invoice instanceof \Model_Invoice ? $invoiceService->getTotalWithTax($invoice) : 0.0;
                $credit = $invoiceService->getFreeCredit((int) $client->id);
                // The invoice gets its payment method's fee on approval (GatewayFees), after the credit staff apply.
                $fee = fn (float $base): float => $invoice instanceof \Model_Invoice && $gatewayId && $base > 0 ? \ClientFence\Mod\Invoice\GatewayFees::charge($this->di, $invoice, $gatewayId, $base) : 0.0;
                $gateway = $gatewayId ? $this->di['db']->load('PayGateway', $gatewayId) : null;

                return [
                    'currency' => $currency->getCode(),
                    'items' => array_map(static fn (array $i): array => [
                        'title' => $i['title'], 'quantity' => $i['quantity'], 'period' => $i['period'] ?? null,
                        'price' => $i['price'] * $rate, 'total' => $i['total'] * $rate, 'setup_price' => $i['setup_price'] * $rate, 'discount' => $i['discount'] * $rate,
                        // ClientFence core patch (CF-145): a first period to a set due date, then the renewal (a recurring promotion's discount off).
                        'due' => empty($i['addon_due']) ? null : substr((string) $i['addon_due'], 0, 10),
                        'renewal' => ($i['recurring_price'] * $i['quantity'] - ($cart['promo_recurring'] ? $i['recurring_discount'] : 0)) * $rate,
                    ], $cart['items']),
                    'promocode' => $cart['promocode'] ?? $cart['auto_promo'],
                    'subtotal' => $cart['subtotal'] * $rate,
                    'discount' => $cart['discount'] * $rate,
                    'tax' => $invoice instanceof \Model_Invoice ? $invoiceService->getTax($invoice) : 0.0,
                    'tax_name' => $invoice instanceof \Model_Invoice ? (string) $invoice->taxname : '',
                    'tax_included' => $invoiceService->pricesIncludeTax(),
                    'total' => $total,
                    'credit' => $credit,
                    'fee' => $fee($total),
                    'fee_with_credit' => $fee(round($total - min($credit, $total), 2)),
                    'fee_title' => $gateway instanceof \Model_PayGateway && $gateway->id ? __trans(':gateway fee', [':gateway' => (string) $gateway->name]) : '',
                ];
            } finally {
                $this->di['dbal']->rollBack();
                $this->resetEntityManager();
            }
        } finally {
            $this->staffPrices = [];
            $this->staffOrder = false;
        }
    }

    /**
     * ClientFence core patch: an order line's start date (staff Add New Order, WHMCS Registration Date): a past day is kept
     * (Order\Service::startTime()); today, or none, is the normal order.
     *
     * @throws \ClientFence\InformationException a date that is not a day or is in the future
     */
    public static function staffStartDate(array $config): array
    {
        $date = trim((string) ($config['_start_date'] ?? ''));
        unset($config['_start_date']);
        if ($date === '') {
            return $config;
        }
        $day = \DateTime::createFromFormat('!Y-m-d', $date);
        if (!$day instanceof \DateTime || $day->format('Y-m-d') !== $date) {
            throw new \ClientFence\InformationException('The start date :date is not a valid date.', [':date' => $date]);
        }
        if ($date > date('Y-m-d')) {
            throw new \ClientFence\InformationException('The start date cannot be in the future.');
        }

        return $date < date('Y-m-d') ? $config + ['_start_date' => $date] : $config;
    }

    public function usePromo(Promo $promo): void
    {
        $this->getProductService()->usePromo($promo);
    }

    public function findActivePromoByCode($code): ?Promo
    {
        return $this->getProductService()->findActivePromoByCode($code);
    }

    /**
     * Function checks if product is related to other products in cart
     * If relation exists then count discount for this.
     */
    protected function getRelatedItemsDiscount(
        Cart|\Model_Cart $cart,
        CartProduct|\Model_CartProduct $model,
        ?array $cartProducts = null,
    ): float {
        $config = $this->getItemConfig($model);

        $list = [];
        $products = $cartProducts ?? $this->getCartProducts($cart);
        foreach ($products as $p) {
            $item = [
                'id' => $this->cartProductId($p),
                'cart_id' => $this->cartProductCartId($p),
                'product_id' => $this->cartProductProductId($p),
                'config' => $this->getItemConfig($p),
            ];
            $list[] = $item;
        }

        return $this->getProductService()->getRelatedProductDiscountByProductId((int) $this->cartProductProductId($model), $list, $config);
    }

    protected function getItemPromoDiscount(CartProduct|\Model_CartProduct $model, Promo $promo)
    {
        $config = $this->getItemConfig($model);

        return $this->getProductService()->getProductDiscountById((int) $this->cartProductProductId($model), $promo, $config);
    }

    public function getItemConfig(CartProduct|\Model_CartProduct $model): array
    {
        return json_decode($this->cartProductConfig($model) ?? '', true) ?? [];
    }

    private function getProductService(): \ClientFence\Mod\Product\Service
    {
        $service = $this->di['mod_service']('Product');

        // ClientFence core patch: fixed currency prices (Product/CurrencyPrices.php), while
        // cartProductToApiArray() prices an item.
        return $this->priceCurrency !== null && $service instanceof \ClientFence\Mod\Product\Service ? $service->inCurrency($this->priceCurrency) : $service;
    }

    private function getProductId(Product $product): int
    {
        return (int) $product->getId();
    }

    private function getProductTitle(Product $product): string
    {
        return (string) $product->getTitle();
    }

    public function cartProductToApiArray(
        CartProduct|\Model_CartProduct $model,
        Cart|\Model_Cart|null $cart = null,
        ?array $cartProducts = null,
        ?string $currency = null,
    ): array {
        // ClientFence core patch: fixed currency prices (Product/CurrencyPrices.php): the item, its
        // discounts and related items are priced in the cart's currency (getProductService()).
        $previous = $this->priceCurrency;
        $this->priceCurrency = $currency ?? $this->cartCurrencyCode($cart);
        try {
            return $this->cartProductApiArray($model, $cart, $cartProducts);
        } finally {
            $this->priceCurrency = $previous;
        }
    }

    /** ClientFence: the code of a cart's currency (null: no cart given, or none set: the default one). */
    private function cartCurrencyCode(Cart|\Model_Cart|null $cart): ?string
    {
        try {
            $currencyId = $cart !== null ? $this->cartCurrencyId($cart) : null;
            if (!$currencyId) {
                return null;
            }
            $code = $this->di['em']->getConnection()->fetchOne('SELECT code FROM currency WHERE id = ?', [$currencyId]);
        } catch (\Throwable) {
            return null;
        }

        return is_string($code) && $code !== '' ? $code : null;
    }

    private function cartProductApiArray(
        CartProduct|\Model_CartProduct $model,
        Cart|\Model_Cart|null $cart = null,
        ?array $cartProducts = null,
    ): array {
        // ClientFence core patch: a staff order's titles are written in the client's language, as at their own checkout.
        $productView = $this->staffOrderLocale === null
            ? $this->getProductService()->getCartProductViewData($model)
            : \ClientFence\Translate::with($this->staffOrderLocale, fn (): array => $this->getProductService()->getCartProductViewData($model));
        $config = $productView['config'];
        $setup = $productView['setup_price'];
        $price = $productView['price'];
        $qty = $productView['quantity'];

        [$discount_price, $discount_setup, $promoLine] = $this->getProductDiscount($model, $setup, $cart, $cartProducts, $productView['options_setup_price']);
        // ClientFence core patch: an add-on for the client's service (Order/AddonOrders.php) first runs to the service's due
        // date: the price and its discount are for those days; the order keeps the full price for its renewals.
        $recurringPrice = $price;
        $recurringDiscount = $discount_price;
        $addonFor = $this->addonParent($config, (int) $productView['product_id']);
        if ($addonFor !== null && !empty($config['period'])) {
            $factor = \ClientFence\Mod\Order\AddonOrders::factor($this->di, $addonFor);
            $price *= $factor;
            $discount_price *= $factor;
        }
        // ClientFence core patch: a staff price override is the item's final price, without a promotion (WHMCS Add New Order).
        if (isset($this->staffPrices[$this->cartProductId($model)])) {
            $price = $this->staffPrices[$this->cartProductId($model)];
            $discount_price = $discount_setup = 0;
        }
        // ClientFence core patch (CF-145): a prorata product, or an add-on ordered with one, first runs to its prorata day
        // (Order\Proration::prorata()): the price and its discount (a staff override too) are for that part; the order keeps
        // the full price for its renewals, which fall on that day (prorata_day).
        $prorata = $addonFor === null && !empty($config['period']) ? $this->prorataLine($config, (int) $productView['product_id'], $cart, $cartProducts) : null;
        if ($prorata !== null) {
            [$recurringPrice, $recurringDiscount] = [$price, $discount_price];
            $price *= $prorata['factor'];
            $discount_price *= $prorata['factor'];
        }

        $discount_total = $discount_price + $discount_setup;

        $subtotal = ($price * $qty);
        if (abs($discount_total) > ($subtotal + $setup)) {
            // ClientFence core patch: the setup discount stays in the capped total, as the invoice waives it
            // (a promo bigger than the price with free setup showed the setup fee due, then made a 0.00 invoice).
            $discount_price = $subtotal;
            $discount_setup = min($discount_setup, $setup);
            $discount_total = $discount_price + $discount_setup;
        }

        return array_merge($config, [
            'id' => $this->cartProductId($model),
            'product_id' => $productView['product_id'],
            'form_id' => $productView['form_id'],
            'title' => $productView['title'],
            'type' => $productView['type'],
            'quantity' => $qty,
            'unit' => $productView['unit'],
            'price' => $price,
            'setup_price' => $setup,
            'discount' => $discount_total,
            'discount_price' => $discount_price,
            'discount_setup' => $discount_setup,
            'total' => $subtotal,
            'recurring_price' => $recurringPrice,
            'recurring_discount' => $recurringDiscount,
            'promo_line' => $promoLine,
            'addon_due' => $addonFor !== null && !empty($config['period']) ? (string) $addonFor->expires_at : ($prorata['due'] ?? null),
            // An add-on for a service renews on that service's prorata day.
            'prorata_day' => $addonFor !== null ? ((int) (json_decode((string) $addonFor->config, true)['prorata_day'] ?? 0) ?: null) : ($prorata['day'] ?? null),
        ]);
    }

    /**
     * ClientFence core patch (CF-145): the first period of a recurring cart line on a prorata product, or of an add-on
     * ordered with one (its own product's day is not asked), from a staff order's start date or today.
     *
     * @return array{factor: float, due: string, day: int}|null
     */
    private function prorataLine(array $config, int $productId, Cart|\Model_Cart|null $cart, ?array $cartProducts): ?array
    {
        $sql = 'SELECT p.config, p.is_addon, pp.type FROM product p LEFT JOIN product_payment pp ON pp.id = p.product_payment_id WHERE p.id = ?';
        $product = $this->di['dbal']->fetchAssociative($sql, [$productId]);
        if (!is_array($product) || ($product['type'] ?? '') !== 'recurrent') {
            return null;
        }
        $settings = json_decode((string) $product['config'], true) ?? [];
        if (!empty($product['is_addon']) && $cart !== null && isset($config[self::CART_FAMILY_KEY])) {
            $settings = [];
            foreach ($cartProducts ?? $this->findCartProducts($cart) as $line) {
                if (($this->getItemConfig($line)[self::CART_FAMILY_KEY] ?? null) !== $config[self::CART_FAMILY_KEY]) {
                    continue;
                }
                $parent = $this->di['dbal']->fetchAssociative($sql, [(int) $this->cartProductProductId($line)]);
                $parentSettings = is_array($parent) && empty($parent['is_addon']) ? json_decode((string) $parent['config'], true) ?? [] : [];
                if ((int) ($parentSettings['prorata_day'] ?? 0) > 0) {
                    $settings = $parentSettings;

                    break;
                }
            }
        }
        $day = (int) ($settings['prorata_day'] ?? 0);
        $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($config['_start_date'] ?? '')) === 1 ? $config['_start_date'] . date(' H:i:s') : date('Y-m-d H:i:s');
        $term = $day > 0 ? \ClientFence\Mod\Order\Proration::prorata((string) $config['period'], $day, (int) ($settings['prorata_charge_next'] ?? 0), $start) : null;

        return $term === null ? null : ['factor' => $term['factor'], 'due' => $term['due'], 'day' => $day];
    }

    /** ClientFence core patch: the order group of the service an add-on joins; a service ordered on its own gets one. */
    private function addonGroupId(\Model_ClientOrder $parent): string
    {
        if ((string) $parent->group_id === '') {
            $this->di['dbal']->update('client_order', ['group_id' => 'o' . $parent->id, 'group_master' => 1], ['id' => (int) $parent->id]);
            $parent->group_id = 'o' . $parent->id;
        }

        return (string) $parent->group_id;
    }

    /** ClientFence core patch: the service an add-on cart item is for (Order/AddonOrders.php), while it can still take it. */
    private function addonParent(array $config, int $productId): ?\Model_ClientOrder
    {
        $id = (int) ($config[self::CART_ADDON_FOR_KEY] ?? 0);
        $order = $id > 0 ? $this->di['db']->load('ClientOrder', $id) : null;

        return $order instanceof \Model_ClientOrder && $order->id && isset(\ClientFence\Mod\Order\AddonOrders::products($this->di, $order)[$productId]) ? $order : null;
    }

    public function getProductDiscount(
        CartProduct|\Model_CartProduct $cartProduct,
        $setup,
        Cart|\Model_Cart|null $cart = null,
        ?array $cartProducts = null,
        float $optionsSetup = 0.0,
    ): array {
        $cart ??= $cartProduct instanceof CartProduct
            ? $this->getCartRepository()->find((int) $this->cartProductCartId($cartProduct))
            : $this->di['db']->findOne('Cart', 'id = ?', [(int) $this->cartProductCartId($cartProduct)]);
        if (!$cart instanceof Cart && !$cart instanceof \Model_Cart) {
            throw new \ClientFence\Exception('Cart not found');
        }
        $discount_price = $this->getRelatedItemsDiscount($cart, $cartProduct, $cartProducts);
        $discount_setup = 0;
        $promo = $this->cartPromoId($cart) ? $this->getProductService()->findPromoById((int) $this->cartPromoId($cart)) : $this->autoPromo;
        $promoLine = false;
        if ($promo instanceof Promo) {
            $promoDiscount = $this->getItemPromoDiscount($cartProduct, $promo);
            $promoApplies = $this->getProductService()->isPromoApplicableToProductById(
                (int) $this->cartProductProductId($cartProduct),
                $promo,
                $this->getItemConfig($cartProduct),
            );
            // Promo discount should override related item discount
            // ClientFence core patch: an automatic promotion only on a line it covers, and only where it takes off at least the line's own discount.
            // ClientFence core patch (CF-146): a typed code too only where it takes off at least the free domain, which it never removes.
            $promoLine = ($this->cartPromoId($cart) || $promoApplies) && $promoDiscount >= $discount_price;
            if ($promoLine) {
                $discount_price = $promoDiscount;
            }

            if ($promoLine && $promo->isFreeSetup() && $promoApplies) {
                $discount_setup = $setup;
                // ClientFence core patch: configurable options (Product/Options.php). A free setup
                // waives the options' setup fees only when the promotion also covers the options.
                if ($optionsSetup > 0 && !\ClientFence\Mod\Product\Options::promoCoversOptions($this->di, (int) $promo->getId())) {
                    $discount_setup = max(0.0, round((float) $setup - $optionsSetup, 2));
                }
            }
        }

        // The third value: whether the line's discount is the promotion's (the order reserves and records it).
        return [$discount_price, $discount_setup, $promoLine];
    }
}
