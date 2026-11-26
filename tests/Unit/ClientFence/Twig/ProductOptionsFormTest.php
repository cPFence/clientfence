<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 */

declare(strict_types=1);

use Tests\Support\StrictTemplateRenderer;

test('product options form skips the specified option codes', function (array $context, bool $showA, bool $showB, array $codes = ['a', 'b']): void {
    $html = (new StrictTemplateRenderer())->renderTemplate(
        PATH_MODS . '/Product/templates/client/mod_product_options_form.html.twig',
        $context + [
            'app_area' => 'client',
            'options_list' => [
                ['id' => 1, 'code' => $codes[0], 'type' => 'yesno', 'title' => 'Option A', 'values' => []],
                ['id' => 2, 'code' => $codes[1], 'type' => 'yesno', 'title' => 'Option B', 'values' => []],
            ],
        ],
    );

    if (!$showA && !$showB) {
        expect(trim($html))->toBe('');

        return;
    }

    expect(str_contains($html, 'name="options[1]"'))->toBe($showA)
        ->and(str_contains($html, 'name="options[2]"'))->toBe($showB);
})->with([
    'skip_codes omitted' => [[], true, true],
    'skip a' => [['skip_codes' => ['a']], false, true],
    'skip both' => [['skip_codes' => ['a', 'b']], false, false],
    'skip 01 keeps 1' => [['skip_codes' => ['01']], true, false, ['1', '01']],
]);
