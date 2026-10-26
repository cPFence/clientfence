<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use ClientFence\Twig\Enum\AppArea;
use ClientFence\Twig\TwigLoader;
use Twig\Error\LoaderError;

test('templates', function (): void {
    $loader = new TwigLoader(AppArea::CLIENT, PATH_THEMES . DIRECTORY_SEPARATOR . 'palisade');
    $test = $loader->getSourceContext('mod_page_login.html.twig');
    $test2 = $loader->getSourceContext('error.html.twig');

    expect($test)->toBeObject();
    expect($test2)->toBeObject();
});

test('exception', function (): void {
    $loader = new TwigLoader(AppArea::CLIENT, PATH_THEMES . DIRECTORY_SEPARATOR . 'palisade');

    expect(fn (): Twig\Source => $loader->getSourceContext('mod_non_existing_settings.html.twig'))
        ->toThrow(LoaderError::class);
});

test('no two modules ship a template of the same name, so module path order cannot change a page', function (): void {
    foreach (AppArea::cases() as $area) {
        $names = [];
        foreach (glob(PATH_MODS . '/*/templates/' . $area->value, GLOB_ONLYDIR) ?: [] as $dir) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $names[] = substr($file->getPathname(), strlen($dir) + 1);
            }
        }

        expect(array_keys(array_filter(array_count_values($names), static fn (int $n): bool => $n > 1)))->toBe([], $area->value);
    }
});
