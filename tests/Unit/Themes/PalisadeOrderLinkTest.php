<?php

declare(strict_types=1);

/**
 * ClientFence: a store link to a product's order page (/order/<slug>?period=1Y&promo=CODE, as WHMCS's
 * billingcycle and promocode links) preselects the billing cycle and applies the promo code through
 * the page's own promo box (checkout.js, or cart.js on the cart page for domain products, configured first).
 * A hosting plan is ordered on the checkout with its domain section (CF-146).
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
 * @param array<string, mixed> $query  the link's query string
 * @param string               $type   the product type: a domain product has its own order form, configured on the page and paid on the cart page
 * @param array<string, mixed> $config the product's public config
 */
function renderPalisadeOrderLink(string $template, array $query, string $type = 'custom', array $config = []): string
{
    $loader = new ChainLoader([
        new ArrayLoader([
            'layout_blank.html.twig' => '{% block content %}{% endblock %}',
            'macro_functions.html.twig' => '{% macro recaptcha() %}{% endmacro %}',
            'mod_product_options_form.html.twig' => '',
            'mod_order_addons.html.twig' => '',
            'mod_servicedomain_order_form.html.twig' => '',
            'partials/pay_methods.html.twig' => '',
        ]),
        new FilesystemLoader(PATH_THEMES . '/palisade/html'),
        new FilesystemLoader([PATH_MODS . '/Servicehosting/templates/client', PATH_MODS . '/Servicedomain/templates/client']),
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
            'id' => 7, 'type' => $type, 'title' => 'Trial', 'slug' => 'trial', 'description' => '', 'form_id' => 0,
            'allow_quantity_select' => false, 'addons' => [], 'config' => $config,
            'pricing' => ['type' => 'recurrent', 'recurrent' => ['1M' => ['enabled' => true, 'price' => 0, 'setup' => 0], '1Y' => ['enabled' => true, 'price' => 0, 'setup' => 0]]],
        ],
        'guest' => new PalisadeOrderLinkGuestStub([
            'clientfence_store_open' => true,
            'clientfence_store_info' => ['signup' => true],
            'clientfence_signup_info' => ['open' => true],
            'system_template_exists' => $type !== 'custom',
            'serviceDomain_tlds' => [['tld' => '.com', 'require_transfer_code' => false]],
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

    // A domain product goes to the cart page with the code, which applies it once through its promo box.
    expect(renderPalisadeOrderLink('mod_order_product.html.twig', ['promo' => 'FREETRIAL'], 'domain'))->toContain('data-redirect="/cart?promo=FREETRIAL"');
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
        ->and(renderPalisadeOrderLink('mod_order_product.html.twig', ['promo' => ['FREETRIAL']], 'domain'))->toContain('data-redirect="/cart"')
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
        ->and(renderPalisadeOrderLink('mod_order_product.html.twig', ['period' => '2Y'], 'domain'))->not->toContain(' selected>');
});

test('a hosting plan is ordered on the checkout with its domain section; a domain product keeps the configure step (CF-146)', function (): void {
    $html = renderPalisadeOrderLink('mod_order_product.html.twig', ['period' => '1Y', 'promo' => 'FREETRIAL'], 'hosting', [
        'allow_domain_transfer' => false, 'allow_subdomain' => true, 'subdomain_base_domain' => 'example.net',
        'free_domain' => '1', 'free_transfer' => '0', 'free_tlds' => ['.com'], 'free_domain_periods' => ['1Y'],
    ]);
    $plan = strpos($html, 'id="bf-h-plan"');
    $start = strpos($html, '<section class="card bf-card bf-section cf-order-domain"');
    $section = substr($html, (int) $start, strpos($html, '</section>', (int) $start) - (int) $start);

    // The domain section follows the plan, with the choices the product allows and the domain[...] fields the cart reads.
    expect($plan)->toBeInt()->and($start)->toBeGreaterThan($plan)
        ->and($section)->toContain('data-cf-hosting-domain')
        ->toContain('data-domain-action="register"')
        ->toContain('data-domain-action="owndomain"')
        ->toContain('data-domain-action="subdomain"')
        ->not->toContain('data-domain-action="transfer"')
        ->toContain('name="domain[register_sld]"')
        ->toContain('<option value=".com">.com</option>')
        ->toContain('name="domain[register_years]"')
        ->toContain('name="domain[owndomain_sld]"')
        ->toContain('.example.net</span>')
        ->toContain('name="domain[action]" value="register"')
        ->toContain('window.ClientFenceDomainCaptcha')
        ->not->toContain(' style=')
        ->and($html)->not->toContain('data-redirect=');

    // checkout.js prices the domain line with the plan's free domain terms, as the cart does, and keeps a link's code.
    $data = palisadeOrderLinkData($html);
    expect($data['product']['freeDomain'])->toBe(['domain' => true, 'transfer' => false, 'tlds' => ['.com'], 'periods' => ['1Y']])
        ->and($data['texts']['domainCheck'])->toBe('Check the domain to continue')
        ->and($data['period'])->toBe('1Y')
        ->and($data['promo'])->toBe('FREETRIAL');

    // A domain product is configured and goes to the cart page; other products have no domain section.
    $other = renderPalisadeOrderLink('mod_order_product.html.twig', []);
    expect(renderPalisadeOrderLink('mod_order_product.html.twig', [], 'domain'))->toContain('data-redirect="/cart"')->not->toContain('data-bf-checkout-data')
        ->and($other)->not->toContain('data-cf-hosting-domain')
        ->and(palisadeOrderLinkData($other)['product']['freeDomain'])->toBeNull();
});
