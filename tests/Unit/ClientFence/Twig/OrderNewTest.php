<?php

declare(strict_types=1);

// ClientFence: staff Add New Order (mod_order_new.html.twig) labels "Already set up" by what the product type's module does with it.
test('Add New Order marks the products whose type links an existing service on "Already set up", with the fields it asks for', function (): void {
    $twig = new Twig\Environment(new Twig\Loader\ChainLoader([
        new Twig\Loader\ArrayLoader([
            'layout_default.html.twig' => '{% block content %}{% endblock %}{% block js %}{% endblock %}',
            'macro_functions.html.twig' => '{% macro selectbox(name, options, selected, required, placeholder) %}{% endmacro %}',
        ]),
        new Twig\Loader\FilesystemLoader(PATH_MODS . '/Order/templates/admin'),
    ]));
    foreach (['trans', 'url'] as $filter) {
        $twig->addFilter(new Twig\TwigFilter($filter, fn ($value) => $value));
    }
    $twig->addFunction(new Twig\TwigFunction('has_permission', fn (): bool => true));
    // A module's type that declares "import" (ServiceTypes) with the fields that identify the existing service, and one that does not.
    $fields = [['key' => 'account_id', 'label' => 'Account ID', 'hint' => 'As shown in the panel.'], ['key' => 'region', 'label' => 'Region']];
    $twig->addFunction(new Twig\TwigFunction('service_type', fn (?string $type): array => $type === 'example' ? ['import' => true, 'import_fields' => $fields] : []));
    $product = fn (int $id, string $type): array => ['id' => $id, 'title' => 'P' . $id, 'type' => $type, 'pricing' => ['type' => 'once'], 'allow_quantity_select' => false];
    $admin = new class([$product(1, 'example'), $product(2, 'custom')]) {
        public function __construct(private readonly array $list)
        {
        }

        public function __call(string $name, array $args): array
        {
            return match ($name) {
                'product_get_pairs' => [1 => 'P1', 2 => 'P2'],
                'product_get_list' => ['list' => $this->list, 'pages' => 1],
                default => ['list' => []],
            };
        }
    };
    $guest = new class {
        public function __call(string $name, array $args): array
        {
            return [];
        }
    };

    $html = $twig->render('mod_order_new.html.twig', ['request' => ['ajax' => false], 'admin' => $admin, 'guest' => $guest, 'today' => '2026-11-24', 'csp_nonce' => 'n']);
    preg_match('/data-products="([^"]*)"/', $html, $m);

    $products = json_decode(html_entity_decode($m[1]), true);

    expect(array_column($products, 'imports', 'id'))->toBe([1 => true, 2 => false])
        ->and(array_column($products, 'import_fields', 'id'))->toBe([1 => [['key' => 'account_id', 'label' => 'Account ID', 'hint' => 'As shown in the panel.'], ['key' => 'region', 'label' => 'Region', 'hint' => '']], 2 => []])
        // Ticked, each field is an input; the values go into the item's config under "_import_fields", as typed.
        ->and($html)->toContain('${it.import ? p.import_fields.map((f) =>')
        ->and($html)->toContain("item.config._import_fields = Object.fromEntries(p.import_fields.map((f) => [f.key, Object.hasOwn(it.fields, f.key) ? it.fields[f.key] : '']))")
        // Another product on the card clears them.
        ->and($html)->toContain('it.fields = {};')
        ->and($html)->toContain("p.type === 'hosting' ? text.import : (p.imports ? text.link : text.setUp)")
        ->and($html)->toContain('Already set up (link the existing service, do not create it)');
});
