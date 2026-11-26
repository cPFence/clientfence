<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 */

declare(strict_types=1);

use Tests\Support\PermissiveStub;
use Tests\Support\StrictTemplateRenderer;

// ClientFence: the staff product page's Configuration tab (mod_product_manage.html.twig).
test('the Configuration tab includes the product type\'s config section only while its module is on', function (bool $on): void {
    $html = (new StrictTemplateRenderer())->renderTemplate(PATH_MODS . '/Product/templates/admin/mod_product_manage.html.twig', [
        'product' => new PermissiveStub(['id' => 4, 'type' => 'custom']),
        'guest' => new PermissiveStub(['extension_is_on' => $on]),
    ]);
    $pane = substr($html, (int) strpos($html, 'id="tab-config"'), 3000);

    // The custom type's config section (mod_servicecustom_config.html.twig) has the plugin field.
    expect($pane)->toContain('id="tab-config"')
        ->and(str_contains($pane, 'name="plugin"'))->toBe($on);
})->with([
    'module on' => [true],
    'module off' => [false],
]);
