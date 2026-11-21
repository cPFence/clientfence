<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 */

declare(strict_types=1);

use ClientFence\Mod\Cart\Service;
use ClientFence\Mod\Product\Entity\Product;
use ClientFence\Mod\Product\Entity\Promo;
use ClientFence\Mod\Product\Service as ProductService;

use function Tests\Helpers\container;

// CF-165: the checkout's domain renewal line takes off what the renewal invoice will (getRenewalProductDiscount()).
test('a cart domain line carries its renewal discount only under a recurring promotion that covers it', function (string $type, bool $recurring, bool $promoLine, float $expected, float $discount = 2.0): void {
    $promo = (new Promo())->setRecurring($recurring);
    $product = new Product();
    $productService = Mockery::mock(ProductService::class);
    $productService->shouldReceive('getCartProductViewData')->andReturn([
        'config' => ['action' => 'register', 'register_sld' => 'example', 'register_tld' => '.com', 'register_years' => 2],
        'setup_price' => 0.0, 'price' => 10.0, 'quantity' => 1, 'options_setup_price' => 0.0,
        'product_id' => 3, 'form_id' => null, 'title' => 'example.com', 'type' => $type, 'unit' => 'year',
    ]);
    $productService->shouldReceive('findPromoById')->with(7)->andReturn($promo);
    $productService->shouldReceive('findProductById')->with(3)->andReturn($product);
    $productService->shouldReceive('getRenewalProductDiscount')->with($product, $promo, Mockery::on(fn (array $c): bool => $c['register_years'] === 2))->andReturn(4.5);
    $di = container();
    $di['mod_service'] = $di->protect(fn () => $productService);
    $service = Mockery::mock(Service::class)->makePartial();
    $service->setDi($di);
    $service->shouldReceive('getProductDiscount')->andReturn([$discount, 0, $promoLine]);
    $cart = new Model_Cart();
    $cart->loadBean(new Tests\Helpers\DummyBean());
    $cart->promo_id = 7;
    $item = new Model_CartProduct();
    $item->loadBean(new Tests\Helpers\DummyBean());
    $item->id = 11;

    expect($service->cartProductToApiArray($item, $cart)['renewal_discount'])->toBe($expected);
})->with([
    'recurring promotion on the domain' => ['domain', true, true, 4.5],
    'promotion for the first period only' => ['domain', false, true, 0.0],
    'promotion that does not cover the domain' => ['domain', true, false, 0.0],
    'not a domain' => ['hosting', true, true, 0.0],
    // A free line keeps no promotion on its order (createFromCart() reserves it only for a discounted line).
    'covered line with no discount (a free transfer)' => ['domain', true, true, 0.0, 0.0],
]);
