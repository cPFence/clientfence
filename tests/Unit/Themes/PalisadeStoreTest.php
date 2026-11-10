<?php

declare(strict_types=1);

/**
 * ClientFence: the Palisade store page (/order) with every pricing kind. A domain product has a price
 * per TLD instead of a pricing type, which made the page fail under strict variables.
 */

use Twig\Environment;
use Twig\Extra\Intl\IntlExtension;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

// English amounts ("$9"): the test image's default locale (en_US_POSIX) writes "$ 9" with ICU 76.
beforeEach(function (): void {
    $this->locale = Locale::getDefault();
    Locale::setDefault('en_US');
});
afterEach(fn () => Locale::setDefault($this->locale));

final class PalisadeStoreGuestStub
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
 * @param list<array<string, mixed>> $products
 * @param array<string, mixed> $info
 * @param array<string, mixed> $category
 */
function renderPalisadeStore(array $products, array $info = [], array $category = []): string
{
    $loader = new ChainLoader([
        new ArrayLoader(['layout_blank.html.twig' => '{% block content %}{% endblock %}']),
        new FilesystemLoader(PATH_THEMES . '/palisade/html'),
    ]);
    $twig = new Environment($loader, ['strict_variables' => true, 'autoescape' => 'html']);
    $twig->addExtension(new IntlExtension());
    $twig->addGlobal('csp_nonce', 'test-nonce');
    $twig->addFilter(new TwigFilter('trans', static fn (string $text, array $values = []): string => strtr($text, $values)));
    $twig->addFilter(new TwigFilter('url', static fn (string $path): string => '/' . ltrim($path, '/')));
    $twig->addFilter(new TwigFilter('markdown_to_html', static fn (string $text): string => '<p>' . $text . '</p>', ['is_safe' => ['html']]));
    $twig->addFunction(new TwigFunction('render_widgets', static fn (): string => ''));

    return $twig->render('mod_order_index.html.twig', [
        'request' => ['ajax' => true],
        'client' => null,
        'guest' => new PalisadeStoreGuestStub([
            'clientfence_store_open' => true,
            'clientfence_store_info' => $info + ['title' => 'Store', 'subtitle' => ''],
            'cart_get_currency' => ['code' => 'USD', 'conversion_rate' => 1],
            'product_category_get_list' => ['list' => [$category + ['id' => 1, 'title' => 'All', 'products' => $products]]],
        ]),
    ]);
}

test('the store page lists a domain product with its lowest registration price', function (): void {
    $html = renderPalisadeStore([
        [
            'id' => 1, 'type' => 'hosting', 'title' => 'Starter Hosting', 'slug' => 'starter-hosting', 'priority' => 1, 'description' => '- SSD',
            'pricing' => ['type' => 'recurrent', 'recurrent' => ['1M' => ['enabled' => true, 'price' => 5], '1Y' => ['enabled' => true, 'price' => 50]]],
        ],
        [
            'id' => 2, 'type' => 'domain', 'title' => 'Domains', 'slug' => 'domain-checker', 'priority' => 2, 'description' => '',
            'pricing' => ['.com' => ['tld' => '.com', 'price_registration' => 12], '.test' => ['tld' => '.test', 'price_registration' => 9]],
            'price_starting_from' => 9.0,
        ],
        [
            'id' => 3, 'type' => 'custom', 'title' => 'Setup', 'slug' => 'setup', 'priority' => 3, 'description' => '',
            'pricing' => ['type' => 'once', 'once' => ['price' => 30, 'setup' => 0]],
        ],
    ]);

    // The domain card: a fixed card (the cycle switch leaves it alone) priced "From $9 / year",
    // whose Select button opens the domain order form without a period.
    expect($html)->toMatch('#<article class="bf-product"[^>]*data-href="/order/domain-checker"[^>]*data-fixed>#')
        ->and($html)->toContain('From $9')
        ->and($html)->toContain('href="/order/domain-checker" data-select')
        // The other kinds are unchanged: the recurrent card follows the cycle, the one-time card is fixed.
        ->and($html)->toContain('href="/order/starter-hosting?period=1M" data-select')
        ->and($html)->toMatch('#data-href="/order/setup"[^>]*data-once="30"#');
});

test('the store opens on the billing cycle set in the Orders settings, when a product offers it', function (): void {
    $hosting = [[
        'id' => 1, 'type' => 'hosting', 'title' => 'Starter Hosting', 'slug' => 'starter-hosting', 'priority' => 1, 'description' => '',
        'pricing' => ['type' => 'recurrent', 'recurrent' => ['1M' => ['enabled' => true, 'price' => 5], '1Y' => ['enabled' => true, 'price' => 50]]],
    ]];

    expect(renderPalisadeStore($hosting, ['cycle' => '1Y']))->toContain('data-cycle="1Y">')
        ->toContain('href="/order/starter-hosting?period=1Y" data-select')
        // A cycle no product offers falls back to monthly.
        ->and(renderPalisadeStore($hosting, ['cycle' => '2Y']))->toContain('href="/order/starter-hosting?period=1M" data-select');
});

test('a category description shows as the note of its tab', function (): void {
    $html = renderPalisadeStore([[
        'id' => 3, 'type' => 'custom', 'title' => 'Setup', 'slug' => 'setup', 'priority' => 1, 'description' => '',
        'pricing' => ['type' => 'once', 'once' => ['price' => 30, 'setup' => 0]],
    ]], [], ['description' => 'Per-website license? [Read this](https://example.com/guide)']);

    expect($html)->toContain('<div class="alert alert-info bf-category-note" data-category-note="1" hidden><p>Per-website license?');
});

