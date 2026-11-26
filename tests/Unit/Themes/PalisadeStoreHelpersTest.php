<?php

declare(strict_types=1);

/**
 * ClientFence: the scripts of extension modules import the theme's store helpers by name
 * ('clientfence/store-utils', 'clientfence/product-options') through the import map of partials/head.html.twig,
 * built as their own entries of the theme script (build.mjs), so they share the theme's copy.
 */

use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

test('the client area head maps the store helpers by name before the theme script', function (): void {
    $guest = new class {
        public function __call(string $name, array $args): mixed
        {
            return null;
        }
    };
    $twig = new Environment(new ChainLoader([
        new ArrayLoader(['partials/asset_version.html.twig' => 'abc123', 'partials/theme_init.html.twig' => '', 'partials/i18n.html.twig' => '']),
        new FilesystemLoader(PATH_THEMES . '/palisade/html'),
    ]), ['autoescape' => 'html']);
    $twig->addFilter(new TwigFilter('asset_url', static fn (string $value): string => 'https://billing.example.com/themes/palisade/assets/' . $value));
    foreach (['public_asset_url', 'script_tag', 'stylesheet_tag', 'url'] as $filter) {
        $twig->addFilter(new TwigFilter($filter, static fn (string $value): string => $value));
    }

    $html = $twig->render('partials/head.html.twig', ['settings' => [], 'page_title' => 'Home', 'is_rtl' => false, 'csp_nonce' => 'n0nce', 'guest' => $guest]);

    expect(preg_match_all('#<script nonce="n0nce" type="importmap">(.*?)</script>#s', $html, $maps))->toBe(1)
        ->and(json_decode($maps[1][0], true, 512, JSON_THROW_ON_ERROR))->toBe(['imports' => [
            'clientfence/store-utils' => 'https://billing.example.com/themes/palisade/assets/build/js/lib/store-utils.js?v=abc123',
            'clientfence/product-options' => 'https://billing.example.com/themes/palisade/assets/build/js/lib/product-options.js?v=abc123',
        ]])
        ->and(strpos($html, 'type="importmap"'))->toBeLessThan(strpos($html, 'type="module"'));

    // The test image holds the built theme (Dockerfile release-tree) and the script sources, not build.mjs.
    expect(PATH_THEMES . '/palisade/assets/build/js/lib/store-utils.js')->toBeFile()
        ->and(PATH_THEMES . '/palisade/assets/build/js/lib/product-options.js')->toBeFile()
        ->and((string) file_get_contents(PATH_THEMES . '/palisade/assets/src/js/lib/store-utils.js'))->toContain("export * from '../store-utils.js';")
        ->and((string) file_get_contents(PATH_THEMES . '/palisade/assets/src/js/lib/product-options.js'))->toContain("export * from '../product-options.js';")
        ->toContain("export { default } from '../product-options.js';");
});
