<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use ClientFence\Mod\Cart\Entity\Cart;
use ClientFence\Mod\Cart\Entity\CartProduct;
use ClientFence\Mod\Cart\Repository\CartProductRepository;
use ClientFence\Mod\Cart\Repository\CartRepository;
use ClientFence\Mod\Cart\Service;
use ClientFence\Mod\Product\Service as ProductService;

use function Tests\Helpers\container;
use function Tests\Helpers\createEntity;

test('cartProductToApiArray uses resolved initial domain term pricing', function (): void {
    $service = new Service();

    $cart = new Cart();
    $cartReflection = new ReflectionProperty($cart, 'id');
    $cartReflection->setValue($cart, 20);

    $cartProduct = createEntity(CartProduct::class);
    $cartProduct->id = 10;
    $cartProduct->cart_id = 20;
    $cartProduct->product_id = 1;
    $cartProduct->config = json_encode([
        'action' => 'register',
        'register_sld' => 'example',
        'register_tld' => '.com',
        'register_years' => 2,
        'period' => '2Y',
    ]);

    $cartRepo = Mockery::mock(CartRepository::class);
    $cartRepo->shouldReceive('find')->once()->with(20)->andReturn($cart);

    $cartProductRepo = Mockery::mock(CartProductRepository::class);
    $cartProductRepo->shouldReceive('findByCartId')->once()->with(20)->andReturn([]);

    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('getRepository')->with(Cart::class)->andReturn($cartRepo);
    $emMock->shouldReceive('getRepository')->with(CartProduct::class)->andReturn($cartProductRepo);

    $productService = Mockery::mock(ProductService::class);
    $productService->shouldReceive('getCartProductViewData')->once()->with($cartProduct)->andReturn([
        'product_id' => 1,
        'form_id' => 2,
        'type' => 'domain',
        'quantity' => 1,
        'unit' => 'year',
        'price' => 33.0,
        'setup_price' => 0.0,
        'options_setup_price' => 0.0,
        'title' => 'Domain example.com registration',
        'config' => [
            'action' => 'register',
            'register_sld' => 'example',
            'register_tld' => '.com',
            'register_years' => 2,
            'period' => '2Y',
        ],
    ]);
    $productService->shouldReceive('getRelatedProductDiscountByProductId')
        ->once()
        ->with(1, [], [
            'action' => 'register',
            'register_sld' => 'example',
            'register_tld' => '.com',
            'register_years' => 2,
            'period' => '2Y',
        ])
        ->andReturn(0.0);

    $di = container();
    $di['em'] = $emMock;
    $di['mod_service'] = $di->protect(function (string $serviceName) use ($productService) {
        if ($serviceName === 'Product') {
            return $productService;
        }

        throw new RuntimeException('Unexpected service request');
    });
    $service->setDi($di);

    $result = $service->cartProductToApiArray($cartProduct);

    expect($result['quantity'])->toBe(1);
    expect($result['price'])->toBe(33.0);
    expect($result['total'])->toBe(33.0);
    expect($result['title'])->toBe('Domain example.com registration');
    expect($result['unit'])->toBe('year');
});

test('cartProductToApiArray prices the item and its discounts in the cart currency (fixed currency prices)', function (): void {
    $service = new Service();

    $cart = new Cart();
    (new ReflectionProperty($cart, 'id'))->setValue($cart, 20);

    $cartProduct = createEntity(CartProduct::class);
    $cartProduct->id = 10;
    $cartProduct->cart_id = 20;
    $cartProduct->product_id = 1;
    $cartProduct->config = json_encode(['period' => '1M']);

    $cartRepo = Mockery::mock(CartRepository::class);
    $cartRepo->shouldReceive('find')->with(20)->andReturn($cart);
    $cartProductRepo = Mockery::mock(CartProductRepository::class);
    $cartProductRepo->shouldReceive('findByCartId')->with(20)->andReturn([]);
    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('getRepository')->with(Cart::class)->andReturn($cartRepo);
    $emMock->shouldReceive('getRepository')->with(CartProduct::class)->andReturn($cartProductRepo);

    // The product service in EGP (Product\Service::inCurrency()) prices the item and its discounts.
    $inEgp = Mockery::mock(ProductService::class);
    $inEgp->shouldReceive('getCartProductViewData')->once()->with($cartProduct)->andReturn([
        'product_id' => 1, 'form_id' => null, 'type' => 'custom', 'quantity' => 1, 'unit' => 'service',
        'price' => 19.28, 'setup_price' => 0.0, 'options_setup_price' => 0.0, 'title' => 'Care', 'config' => ['period' => '1M'],
    ]);
    $inEgp->shouldReceive('getRelatedProductDiscountByProductId')->once()->andReturn(0.0);
    $productService = Mockery::mock(ProductService::class);
    $productService->shouldReceive('inCurrency')->with('EGP')->andReturn($inEgp);

    $di = container();
    $di['em'] = $emMock;
    $di['mod_service'] = $di->protect(fn (string $name) => $name === 'Product' ? $productService : throw new RuntimeException('Unexpected service request'));
    $service->setDi($di);

    expect($service->cartProductToApiArray($cartProduct, null, null, 'EGP')['price'])->toBe(19.28);

    // Afterwards the cart prices in the default currency again.
    $productService->shouldReceive('getCartProductViewData')->once()->with($cartProduct)->andReturn([
        'product_id' => 1, 'form_id' => null, 'type' => 'custom', 'quantity' => 1, 'unit' => 'service',
        'price' => 20.0, 'setup_price' => 0.0, 'options_setup_price' => 0.0, 'title' => 'Care', 'config' => ['period' => '1M'],
    ]);
    $productService->shouldReceive('getRelatedProductDiscountByProductId')->once()->andReturn(0.0);
    expect($service->cartProductToApiArray($cartProduct)['price'])->toBe(20.0);
});
