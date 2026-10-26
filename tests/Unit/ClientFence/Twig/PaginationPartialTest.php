<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use ClientFence\Paginator;
use Tests\Support\StrictTemplateRenderer;

function paginationRequest(array $overrides = []): array
{
    return array_replace([
        'page' => 1,
        'per_page' => 25,
    ], $overrides);
}

function paginationList(int $total, int $pages, int $perPage = 25, array $items = []): array
{
    return [
        'list' => $items,
        'total' => $total,
        'pages' => $pages,
        'page' => 1,
        'per_page' => $perPage,
    ];
}

function paginationPaginator(int $total, int $currentPage, int $perPage): array
{
    return (new Paginator($total, $currentPage, $perPage))->toArray();
}

test('admin pagination partial silently skips rendering when url is null and the list has multiple pages', function (): void {
    $html = (new StrictTemplateRenderer())->renderTemplate(
        PATH_THEMES . '/admin_default/html/partial_pagination.html.twig',
        [
            'app_area' => 'admin',
            'request' => paginationRequest(),
            'guest' => new PermissiveCallableStub([
                'system_paginator' => paginationPaginator(50, 1, 25),
            ]),
            'list' => paginationList(50, 2),
            'url' => null,
        ],
        emailMode: false,
    );

    expect(trim($html))->toBe('')
        ->and($html)->not->toContain('class="pagination"');
});

test('admin pagination partial renders paginator when url is provided and the list has multiple pages', function (): void {
    $html = (new StrictTemplateRenderer())->renderTemplate(
        PATH_THEMES . '/admin_default/html/partial_pagination.html.twig',
        [
            'app_area' => 'admin',
            'request' => paginationRequest(),
            'guest' => new PermissiveCallableStub([
                'system_paginator' => paginationPaginator(50, 1, 25),
            ]),
            'list' => paginationList(50, 2),
            'url' => 'client/manage/7',
        ],
        emailMode: false,
    );

    expect($html)->toBeString()
        ->toContain('class="pagination')
        ->toContain('class="page-link"');
});

test('admin pagination partial skips paginator when the list fits on a single page', function (): void {
    $html = (new StrictTemplateRenderer())->renderTemplate(
        PATH_THEMES . '/admin_default/html/partial_pagination.html.twig',
        [
            'app_area' => 'admin',
            'request' => paginationRequest(),
            'guest' => new PermissiveCallableStub([
                'system_paginator' => paginationPaginator(5, 1, 25),
            ]),
            'list' => paginationList(5, 1),
            'url' => 'client',
        ],
    );

    expect($html)->not->toContain('class="pagination"');
});

test('admin pagination partial appends the hash to every paginator link', function (): void {
    $html = (new StrictTemplateRenderer())->renderTemplate(
        PATH_THEMES . '/admin_default/html/partial_pagination.html.twig',
        [
            'app_area' => 'admin',
            'request' => paginationRequest(['page' => 2]),
            'guest' => new PermissiveCallableStub([
                'system_paginator' => paginationPaginator(75, 2, 25),
            ]),
            'list' => paginationList(75, 3),
            'url' => 'client/manage/7',
            'hash' => '#tab-emails',
        ],
        emailMode: false,
    );

    expect($html)->toContain('href="#tab-emails"');
});

test('admin pagination partial emits no hash fragment when none is passed', function (): void {
    $html = (new StrictTemplateRenderer())->renderTemplate(
        PATH_THEMES . '/admin_default/html/partial_pagination.html.twig',
        [
            'app_area' => 'admin',
            'request' => paginationRequest(),
            'guest' => new PermissiveCallableStub([
                'system_paginator' => paginationPaginator(50, 1, 25),
            ]),
            'list' => paginationList(50, 2),
            'url' => 'invoice',
        ],
    );

    expect($html)->not->toContain('href="#"')
        ->and($html)->not->toContain('href="#tab-emails"');
});

test('palisade pagination partial silently skips rendering when url is null and the list has multiple pages', function (): void {
    $html = (new StrictTemplateRenderer())->renderTemplate(
        PATH_THEMES . '/palisade/html/partial_pagination.html.twig',
        [
            'app_area' => 'client',
            'request' => paginationRequest(),
            'guest' => new PermissiveCallableStub([
                'system_paginator' => paginationPaginator(50, 1, 25),
            ]),
            'list' => paginationList(50, 2),
            'url' => null,
        ],
    );

    expect(trim($html))->toBe('')
        ->and($html)->not->toContain('class="pagination"');
});

test('palisade pagination partial renders paginator when url is provided and the list has multiple pages', function (): void {
    $html = (new StrictTemplateRenderer())->renderTemplate(
        PATH_THEMES . '/palisade/html/partial_pagination.html.twig',
        [
            'app_area' => 'client',
            'request' => paginationRequest(),
            'guest' => new PermissiveCallableStub([
                'system_paginator' => paginationPaginator(50, 1, 25),
            ]),
            'list' => paginationList(50, 2),
            'url' => 'invoice',
        ],
    );

    expect($html)->toBeString()
        ->toContain('class="pagination')
        ->toContain('class="page-item"');
});

test('palisade pagination partial skips paginator when the list fits on a single page', function (): void {
    $html = (new StrictTemplateRenderer())->renderTemplate(
        PATH_THEMES . '/palisade/html/partial_pagination.html.twig',
        [
            'app_area' => 'client',
            'request' => paginationRequest(),
            'guest' => new PermissiveCallableStub([
                'system_paginator' => paginationPaginator(5, 1, 25),
            ]),
            'list' => paginationList(5, 1),
            'url' => 'invoice',
        ],
    );

    expect($html)->not->toContain('class="pagination"');
});

test('service pagination links stay on the service list', function (): void {
    $twig = new Twig\Environment(new Twig\Loader\ChainLoader([
        new Twig\Loader\ArrayLoader([
            'layout_default.html.twig' => '{% block content %}{% endblock %}',
            'macro_functions.html.twig' => '',
            'partial_pagination.html.twig' => '<a href="/{{ url }}?page=2">Next</a>',
        ]),
        new Twig\Loader\FilesystemLoader(PATH_MODS . '/Order/templates/client'),
    ]), ['strict_variables' => true]);
    foreach (['trans', 'url', 'format_currency', 'period_title', 'format_date'] as $filter) {
        $twig->addFilter(new Twig\TwigFilter($filter, fn ($value) => $value));
    }
    $client = new class {
        public function order_get_list(): array
        {
            return ['list' => []];
        }
    };
    $html = $twig->render('mod_order_list.html.twig', ['request' => ['ajax' => false, 'page' => 1], 'client' => $client]);
    expect($html)->toContain('href="/order/service?page=2"');
});
