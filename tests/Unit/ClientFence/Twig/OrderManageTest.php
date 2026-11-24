<?php

declare(strict_types=1);

use Tests\Support\PermissiveStub;
use Tests\Support\StrictTemplateRenderer;

/** An API stub: the calls in `$answers`, anything else a permissive stub (null answers stay null). */
function orderManageApi(array $answers): object
{
    return new class($answers) {
        public function __construct(private readonly array $answers)
        {
        }

        public function __call(string $name, array $args): mixed
        {
            return array_key_exists($name, $this->answers) ? $this->answers[$name] : new PermissiveStub();
        }

        public function __get(string $name): mixed
        {
            return $this->__call($name, []);
        }

        public function __isset(string $name): bool
        {
            return true;
        }
    };
}

// ClientFence: the staff order page's Service Management tab (mod_order_manage.html.twig).
test('the Service Management tab shows the order form answers next to a module\'s admin-only section, not next to a core type\'s', function (bool $clientSection, bool $form): void {
    // The order as order/get gives it: every field the order page and its partials read (unset ones null).
    preg_match_all('/\border\.([a-z_]+)/', implode('', array_map('file_get_contents', glob(PATH_MODS . '/Order/templates/admin/mod_order_*.html.twig'))), $fields);
    $order = ['id' => 1, 'service_type' => 'example', 'form_id' => 5, 'status' => 'pending_setup', 'title' => 'Example', 'created_at' => '2026-11-24 10:00:00',
        'currency' => 'USD', 'price' => 5, 'total' => 5, 'quantity' => 1, 'client' => ['id' => 7, 'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com', 'company' => null], 'meta' => []]
        + array_fill_keys($fields[1], null);
    $html = (new StrictTemplateRenderer())->renderTemplate(PATH_MODS . '/Order/templates/admin/mod_order_manage.html.twig', [
        'order' => $order,
        // The section exists in the admin templates, the service is not provisioned yet.
        'admin' => orderManageApi(['system_template_exists' => true, 'order_service' => null]),
        'guest' => orderManageApi(['system_template_exists' => $clientSection, 'extension_is_on' => true]),
    ]);
    $pane = substr($html, (int) strpos($html, 'id="tab-service"'), 4000);

    expect($pane)->toContain('This order does not have a provisioned service yet.')
        ->and(str_contains($pane, 'name="config[form_id]"'))->toBe($form);
})->with([
    'a module\'s admin-only section' => [false, true],
    'a core type with a client section too (hosting, domain)' => [true, false],
]);
