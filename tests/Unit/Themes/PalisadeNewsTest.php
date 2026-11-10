<?php

declare(strict_types=1);

/**
 * ClientFence: the Recent News card on the client dashboard and the page links of /news (News extension).
 */

use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class PalisadeNewsStub
{
    public function __construct(private bool $on, private array $posts, private int $pages = 1)
    {
    }

    public function __call(string $name, array $args): mixed
    {
        return match ($name) {
            'extension_is_on' => $this->on && $args[0]['mod'] === 'news',
            'news_get_list' => ['list' => $this->posts, 'pages' => $this->pages, 'total' => count($this->posts) * $this->pages, 'per_page' => $args[0]['per_page']],
            'index_get_dashboard' => ['profile' => ['first_name' => 'Ann'], 'orders' => null, 'invoices' => null, 'tickets' => null, 'recent_tickets' => []],
            'system_current_url' => '/news',
            'client_support_pin' => '01234567',
            default => [],
        };
    }
}

function renderPalisadeNews(string $template, PalisadeNewsStub $guest): string
{
    $loader = new ChainLoader([
        new ArrayLoader(['layout_default.html.twig' => '{% block topbar_nav %}{% endblock %}{% block content %}{% endblock %}']),
        new FilesystemLoader([PATH_THEMES . '/palisade/html', PATH_MODS . '/News/templates/client']),
    ]);
    $twig = new Environment($loader, ['autoescape' => 'html']);
    $twig->addFilter(new TwigFilter('trans', static fn (string $text): string => $text));
    $twig->addFilter(new TwigFilter('url', static fn (string $path, ?array $query = null): string => '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '')));
    foreach (['format_date', 'format_datetime', 'markdown_to_html', 'timeago'] as $filter) {
        $twig->addFilter(new TwigFilter($filter, static fn (?string $value): string => (string) $value));
    }
    $twig->addFilter(new TwigFilter('truncate', static fn (?string $text, int $length): string => mb_substr((string) $text, 0, $length)));
    $twig->addFilter(new TwigFilter('api_url', static fn (string $path): string => '/api/client/' . $path));
    $twig->addFunction(new TwigFunction('render_widgets', static fn (): string => ''));
    $twig->addFunction(new TwigFunction('fb_api_link', static fn (): string => ''));

    return $twig->render($template, ['client' => $guest, 'guest' => $guest, 'request' => ['page' => 1], 'settings' => []]);
}

test('the dashboard shows the visible posts in a Recent News card while the extension is on', function (): void {
    $posts = [
        ['title' => 'Maintenance', 'slug' => 'maintenance', 'pinned' => true, 'publish_at' => '2026-10-20 09:00:00', 'created_at' => '2026-10-19 09:00:00', 'description' => 'Short downtime', 'excerpt' => null, 'content' => ''],
        ['title' => 'New plans', 'slug' => 'new-plans', 'pinned' => false, 'publish_at' => null, 'created_at' => '2026-10-18 09:00:00', 'description' => null, 'excerpt' => null, 'content' => '**Faster** disks'],
    ];
    $html = renderPalisadeNews('mod_index_dashboard.html.twig', new PalisadeNewsStub(true, $posts));

    expect($html)->toContain('Recent News')
        ->toContain('<a class="btn btn-sm btn-outline-secondary" href="/news">View all</a>')
        ->toContain('href="/news/maintenance"')
        ->toContain('2026-10-20 09:00:00 · Short downtime')
        ->toContain('2026-10-18 09:00:00 · **Faster** disks')
        ->and(substr_count($html, '>Pinned</span>'))->toBe(1)
        ->and(renderPalisadeNews('mod_index_dashboard.html.twig', new PalisadeNewsStub(false, $posts)))->not->toContain('Recent News')
        ->and(renderPalisadeNews('mod_index_dashboard.html.twig', new PalisadeNewsStub(true, [])))->not->toContain('Recent News');
});

test('the news list links its further pages', function (): void {
    $post = ['title' => 'Maintenance', 'slug' => 'maintenance', 'pinned' => true, 'publish_at' => null, 'created_at' => '2026-10-19 09:00:00', 'excerpt' => null, 'content' => 'Body'];

    expect(renderPalisadeNews('mod_news_index.html.twig', new PalisadeNewsStub(true, [$post], 3)))
        ->toContain('href="/news?page=2"')
        ->toContain('>Pinned</span>')
        ->and(renderPalisadeNews('mod_news_index.html.twig', new PalisadeNewsStub(true, [$post])))->not->toContain('aria-label="Pagination"');
});
