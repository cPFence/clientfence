<?php

declare(strict_types=1);

/**
 * ClientFence: a store link to a product's order page (/order/<slug>?period=1Y&promo=CODE, as WHMCS's
 * billingcycle and promocode links) preselects the billing cycle and applies the promo code through
 * the page's own promo box (checkout.js, or cart.js on the cart page for plans with their own order form).
 */

use ClientFence\Twig\RequestDataView;
use Twig\Environment;
use Twig\Extra\Intl\IntlExtension;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class PalisadeOrderLinkGuestStub
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values = [])
    {
    }

    public function __call(string $name, array $args): mixed
    {
        return $this->values[$name] ?? false;
    }
}

/**
 * @param array<string, mixed> $query the link's query string
 * @param bool                 $ownForm a plan with its own order form (hosting), configured on the page and paid on the cart page
 */
function renderPalisadeOrderLink(string $template, array $query, bool $ownForm = false): string
{
    $loader = new ChainLoader([
        new ArrayLoader([
            'layout_blank.html.twig' => '{% block content %}{% endblock %}',
            'macro_functions.html.twig' => '{% macro recaptcha() %}{% endmacro %}',
            'mod_product_options_form.html.twig' => '',
            'mod_order_addons.html.twig' => '',
            'mod_servicehosting_order_form.html.twig' => '',
            'partials/pay_methods.html.twig' => '',
        ]),
        new FilesystemLoader(PATH_THEMES . '/palisade/html'),
    ]);
    $twig = new Environment($loader, ['strict_variables' => true, 'autoescape' => 'html']);
    $twig->addExtension(new IntlExtension());
    $twig->addGlobal('csp_nonce', 'test-nonce');
    $twig->addFilter(new TwigFilter('trans', static fn (string $text, array $values = []): string => strtr($text, $values)));
    $twig->addFilter(new TwigFilter('url', static fn (string $path, ?array $query = null): string => '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '')));
    $twig->addFilter(new TwigFilter('api_url', static fn (string $path, ?array $query = null, string $role = 'client'): string => "/api/{$role}/{$path}"));
    $twig->addFilter(new TwigFilter('markdown_to_html', static fn (string $text): string => $text));
    $twig->addFunction(new TwigFunction('render_widgets', static fn (string $at, array $context = []): string => (string) ($context['google_return'] ?? '')));
    $twig->addFunction(new TwigFunction('fb_api_form', static fn (array $options): string => 'data-redirect="' . htmlspecialchars((string) ($options['redirect'] ?? '')) . '"', ['is_safe' => ['html']]));

    return $twig->render($template, [
        'request' => new RequestDataView(['ajax' => true] + $query),
        'client' => null,
        'settings' => [],
        'product' => [
            'id' => 7, 'type' => $ownForm ? 'hosting' : 'custom', 'title' => 'Trial', 'slug' => 'trial', 'description' => '', 'form_id' => 0,
            'allow_quantity_select' => false, 'addons' => [],
            'pricing' => ['type' => 'recurrent', 'recurrent' => ['1M' => ['enabled' => true, 'price' => 0, 'setup' => 0], '1Y' => ['enabled' => true, 'price' => 0, 'setup' => 0]]],
        ],
        'guest' => new PalisadeOrderLinkGuestStub([
            'clientfence_store_open' => true,
            'clientfence_store_info' => ['signup' => true],
            'clientfence_signup_info' => ['open' => true],
            'system_template_exists' => $ownForm,
            'system_periods' => ['1M' => 'Monthly', '1Y' => 'Every year'],
            'system_countries' => ['US' => 'United States'],
            'system_default_country' => 'US',
            'cart_get_currency' => ['code' => 'USD', 'conversion_rate' => 1],
            'cart_count' => 0,
            'cart_get' => ['promocode' => null, 'subtotal' => 0, 'discount' => 0, 'total' => 0, 'items' => [
                ['id' => 1, 'product_id' => 7, 'title' => 'Trial', 'type' => 'hosting', 'period' => '1Y', 'quantity' => 1, 'setup_price' => 0, 'discount_setup' => 0, 'total' => 0, 'discount_price' => 0],
            ]],
            'invoice_payment_methods' => [],
            'client_required' => [],
            'client_custom_fields' => [],
        ]),
    ]);
}

/** @return array<string, mixed> the checkout page's script data */
function palisadeOrderLinkData(string $html): array
{
    preg_match('#<script nonce="test-nonce" type="application/json" data-bf-checkout-data>(.*?)</script>#s', $html, $match);

    return json_decode($match[1] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
}

test('a promo code in the order link is applied through the promo box and kept through a sign-in', function (): void {
    $html = renderPalisadeOrderLink('mod_order_product.html.twig', ['period' => '1Y', 'promo' => 'FREETRIAL']);

    // checkout.js fills the promo box with the code and applies it (cart/add_item, cart/apply_promo), as a click on Apply does.
    expect(palisadeOrderLinkData($html)['promo'])->toBe('FREETRIAL')
        ->and((string) file_get_contents(PATH_THEMES . '/palisade/assets/src/js/checkout.js'))
        ->toContain("\$('[data-bf-promo-input]').value = data.promo;")
            ->toContain('let pending = !(Number(data.cartCount) > 0) || data.linkRedo;')
        // The sign-in and Google sign-up come back to this order with its cycle and code.
        ->and($html)->toContain('href="/login?back=%2Forder%2Ftrial%3Fperiod%3D1Y%26promo%3DFREETRIAL"')
        ->and($html)->toContain('order/trial?period=1Y&amp;promo=FREETRIAL');

    // A plan with its own order form goes to the cart page with the code, which applies it once through its promo box.
    expect(renderPalisadeOrderLink('mod_order_product.html.twig', ['promo' => 'FREETRIAL'], true))->toContain('data-redirect="/cart?promo=FREETRIAL"');
    $cart = renderPalisadeOrderLink('mod_cart_index.html.twig', ['promo' => 'FREETRIAL']);
    expect($cart)->toContain('data-bf-cart-promo data-bf-cart-promo-auto novalidate')
        ->and($cart)->toContain('name="promocode" value="FREETRIAL"')
        ->and((string) file_get_contents(PATH_THEMES . '/palisade/assets/src/js/cart.js'))->toContain("promoForm.dispatchEvent(new Event('submit'");
});

test('an unusable promo code in the order link is ignored without an error', function (): void {
    // A code the cart refuses only shows the promo box's message (applyPromo catches it); a malformed one is dropped here.
    $html = renderPalisadeOrderLink('mod_order_product.html.twig', ['promo' => ['FREETRIAL']]);
    expect(palisadeOrderLinkData($html)['promo'])->toBe('')
        ->and($html)->toContain('href="/login?back=%2Forder%2Ftrial%3Fperiod%3D1M"')
        ->and(renderPalisadeOrderLink('mod_order_product.html.twig', ['promo' => ['FREETRIAL']], true))->toContain('data-redirect="/cart"')
        ->and(renderPalisadeOrderLink('mod_cart_index.html.twig', ['promo' => ['FREETRIAL']]))->not->toContain('data-bf-cart-promo-auto');
});

test('the billing cycle in the order link is preselected', function (): void {
    $html = renderPalisadeOrderLink('mod_order_product.html.twig', ['period' => '1Y']);

    expect(palisadeOrderLinkData($html)['period'])->toBe('1Y')
        ->and($html)->toMatch('#class="bf-seg-item is-on" aria-pressed="true" data-cycle="1Y"#');
});

test('a billing cycle the product does not offer is ignored', function (): void {
    $html = renderPalisadeOrderLink('mod_order_product.html.twig', ['period' => '2Y']);

    expect(palisadeOrderLinkData($html)['period'])->toBe('1M')
        ->and($html)->toMatch('#class="bf-seg-item is-on" aria-pressed="true" data-cycle="1M"#')
        ->and(renderPalisadeOrderLink('mod_order_product.html.twig', ['period' => '2Y'], true))->not->toContain(' selected>');
});
