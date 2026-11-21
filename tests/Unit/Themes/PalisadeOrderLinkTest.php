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
 * @param array<string, mixed>|null $client the signed-in client's API answers (null: a guest)
 */
function renderPalisadeOrderLink(string $template, array $query, string $type = 'custom', array $config = [], ?array $cart = null, ?array $client = null): string
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
        'client' => $client === null ? null : new PalisadeOrderLinkGuestStub($client),
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
            'cart_get' => $cart ?? ['promocode' => null, 'subtotal' => 0, 'discount' => 0, 'total' => 0, 'items' => [
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
    expect($data['product']['freeDomain'])->toBe(['domain' => true, 'transfer' => false, 'tlds' => ['.com'], 'periods' => ['1Y'], 'renewals' => false])
        ->and($data['texts']['domainCheck'])->toBe('Check the domain to continue')
        ->and($data['period'])->toBe('1Y')
        ->and($data['promo'])->toBe('FREETRIAL');

    // A domain product is configured and goes to the cart page; other products have no domain section.
    $other = renderPalisadeOrderLink('mod_order_product.html.twig', []);
    expect(renderPalisadeOrderLink('mod_order_product.html.twig', [], 'domain'))->toContain('data-redirect="/cart"')->not->toContain('data-bf-checkout-data')
        ->and($other)->not->toContain('data-cf-hosting-domain')
        ->and(palisadeOrderLinkData($other)['product']['freeDomain'])->toBeNull();
});

test('the summary has a line for the domain renewal, free under the plan\'s free domain renewals (CF-165)', function (): void {
    $html = renderPalisadeOrderLink('mod_order_product.html.twig', [], 'hosting', [
        'free_domain' => '1', 'free_tlds' => [], 'free_domain_periods' => ['1Y'], 'free_domain_renewals' => '1',
    ]);
    $data = palisadeOrderLinkData($html);

    expect($html)->toContain('<span class="bf-hint" data-bf-domain-renew hidden></span>')
        ->and($data['product']['freeDomain']['renewals'])->toBeTrue()
        ->and($data['texts']['domainRenew'])->toBe('The domain renews at :amount a year.')
        ->and($data['texts']['domainRenewYears'])->toBe('The domain renews at :amount every :years years.')
        ->and($data['texts']['domainRenewFree'])->toBe('The domain renews free while this plan is active.');
});

test('a promo link reapplies its code without asking only over the plan and its own domain (CF-146)', function (): void {
    $plan = ['id' => 1, 'product_id' => 7, 'type' => 'hosting', 'domain' => ['action' => 'register', 'register_sld' => 'mine', 'register_tld' => '.com']];
    $ownDomain = ['id' => 2, 'product_id' => 3, 'type' => 'domain', 'register_sld' => 'mine', 'register_tld' => '.com'];
    $cart = fn (array $items): array => ['promocode' => 'FREETRIAL', 'items' => $items];
    $redo = fn (array $items): bool => palisadeOrderLinkData(renderPalisadeOrderLink('mod_order_product.html.twig', ['promo' => 'FREETRIAL'], 'hosting', [], $cart($items)))['linkRedo'];

    expect($redo([$plan, $ownDomain]))->toBeTrue()
        // A domain the client added on its own would be emptied with the cart: the link asks first.
        ->and($redo([$plan, $ownDomain, ['id' => 3, 'product_id' => 3, 'type' => 'domain']]))->toBeFalse()
        ->and($redo([['domain' => ['action' => 'owndomain']] + $plan, $ownDomain]))->toBeFalse()
        // The plan's own domain was removed and another one added: not the plan's, so the link asks first.
        ->and($redo([$plan, ['register_sld' => 'other'] + $ownDomain]))->toBeFalse();
});

// ClientFence (CF-239): a domain registration or transfer needs the registrant details (Cart\Service::REGISTRANT_FIELDS).
/** The registrant box of a page, or '' without one. */
function palisadeRegistrantBox(string $html): string
{
    $start = strpos($html, '<div data-cf-registrant');

    return $start === false ? '' : substr($html, $start, strpos($html, '</div></div>', $start) - $start);
}

test('a new client ordering a hosting plan gives the registrant details with the sign-up, shown for a registered or transferred domain', function (): void {
    $box = palisadeRegistrantBox(renderPalisadeOrderLink('mod_order_product.html.twig', [], 'hosting'));

    // Hidden until checkout.js sees a registration or transfer chosen in the domain section; the state is optional.
    // The hidden box has no display class: Bootstrap's .d-flex (!important) would win over [hidden].
    expect($box)->toStartWith('<div data-cf-registrant hidden><div class="d-flex flex-column gap-2 mt-3">')
        ->toContain('name="address_1" autocomplete="address-line1" maxlength="100" required')
        ->toContain('name="city" autocomplete="address-level2" maxlength="100" required')
        ->toContain('name="state" autocomplete="address-level1" maxlength="100">')
        ->toContain('name="postcode" autocomplete="postal-code" maxlength="100" required')
        ->toContain('<input type="hidden" name="phone_cc" value="">')
        ->toContain('class="form-control js-phone-input" id="cf-registrant-phone" type="tel" name="phone"')
        ->toContain('data-initial-country="us" required')
        // The sign-up has its own country field.
        ->not->toContain('name="country"')
        ->not->toContain(' style=');
    // A product without a domain section asks none of them.
    expect(palisadeRegistrantBox(renderPalisadeOrderLink('mod_order_product.html.twig', [])))->toBe('');

    $js = (string) file_get_contents(PATH_THEMES . '/palisade/assets/src/js/checkout.js');
    expect($js)->toContain("registrantBox.hidden = state.accountReady || !['register', 'transfer'].includes(registrantAction?.value);")
        ->toContain('Object.assign(account, registrant());')
        ->toContain("await api('client', 'profile/update', saved);")
        // A saved country that changes the tax shows the new total before the order (the client selects Pay again).
        ->toContain("setTax(await api('client', 'clientfence/tax'));");
});

test('a signed-in client is asked only the registrant details the profile lacks, on the checkout and the cart page', function (): void {
    $client = fn (array $missing): array => [
        'profile_get' => ['first_name' => 'Ann', 'last_name' => 'Lee', 'country' => 'DE', 'balance' => 0, 'currency' => 'USD'],
        'clientfence_tax' => ['name' => '', 'rate' => 0],
        'client_balance_get_total' => 0,
        'cart_registrant_missing' => $missing,
    ];
    $checkout = palisadeRegistrantBox(renderPalisadeOrderLink('mod_order_product.html.twig', [], 'hosting', [], null, $client(['city', 'phone', 'country'])));
    expect($checkout)->toContain('data-cf-registrant hidden>')
        ->toContain('name="city"')
        ->toContain('data-initial-country="de" required')
        ->toContain('<select class="form-select" name="country" autocomplete="country" required>')
        ->not->toContain('name="address_1"')
        ->not->toContain('name="postcode"')
        ->not->toContain('name="state"');
    // A complete profile is asked nothing.
    expect(palisadeRegistrantBox(renderPalisadeOrderLink('mod_order_product.html.twig', [], 'hosting', [], null, $client([]))))->toBe('');

    // The cart page shows them (not hidden) when it holds a domain registration or transfer, never for an own domain.
    $line = ['product_id' => 3, 'title' => 'mine.com', 'type' => 'domain', 'period' => '1Y', 'quantity' => 1, 'setup_price' => 0, 'discount_setup' => 0, 'total' => 0, 'discount_price' => 0];
    $cart = fn (array ...$items): array => ['promocode' => null, 'subtotal' => 0, 'discount' => 0, 'total' => 0, 'items' => $items];
    $page = fn (array $items, array $missing): string => palisadeRegistrantBox(renderPalisadeOrderLink('mod_cart_index.html.twig', [], 'custom', [], $cart(...$items), $client($missing)));
    foreach (['register', 'transfer'] as $action) {
        expect($page([['id' => 2, 'action' => $action] + $line], ['postcode']))->toContain('data-cf-registrant>')
            ->toContain('name="postcode"')
            ->not->toContain('name="city"');
    }
    $hosting = ['id' => 1, 'product_id' => 7, 'title' => 'Plan', 'type' => 'hosting', 'domain' => ['action' => 'owndomain']] + $line;
    expect($page([$hosting], ['postcode']))->toBe('')
        ->and($page([['id' => 2, 'action' => 'register'] + $line], []))->toBe('')
        ->and((string) file_get_contents(PATH_THEMES . '/palisade/assets/src/js/cart.js'))
        ->toContain("await api('client', 'profile/update', Object.fromEntries(registrant.map((input) => [input.name, input.value.trim()])));");
});
