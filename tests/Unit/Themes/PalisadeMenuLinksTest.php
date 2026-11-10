<?php

declare(strict_types=1);

/**
 * ClientFence: staff add their own links to the client menu (Palisade theme settings "Menu Links").
 */

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

function renderPalisadeMenuLinks(bool $signedIn): string
{
    $twig = new Environment(new FilesystemLoader(PATH_THEMES . '/palisade/html'), ['strict_variables' => true, 'autoescape' => 'html']);
    $twig->addFilter(new TwigFilter('trans', static fn (string $text): string => $text));
    $twig->addFilter(new TwigFilter('url', static fn (string $path): string => '/' . ltrim($path, '/')));
    $twig->addFunction(new TwigFunction('render_widgets', static fn (): string => ''));
    $api = new class {
        public function __call(string $name, array $args): mixed
        {
            return false;
        }
    };

    return $twig->render('partial_menu.html.twig', [
        'client' => $signedIn ? $api : null,
        'guest' => $api,
        'current_active_menu' => 'dashboard',
        'settings' => [
            'menu_link_1_title' => 'Docs & Help', 'menu_link_1_url' => 'https://docs.example.com/?a=1&b=2', 'menu_link_1_new_tab' => '1',
            'menu_link_2_title' => 'Status', 'menu_link_2_url' => ' http://status.example.com ', 'menu_link_2_new_tab' => '0',
            'menu_link_3_title' => 'Script', 'menu_link_3_url' => 'javascript:alert(1)', 'menu_link_3_new_tab' => '1',
            'menu_link_4_title' => '', 'menu_link_4_url' => 'https://untitled.example.com', 'menu_link_4_new_tab' => '0',
            'menu_link_5_title' => 'Files', 'menu_link_5_url' => 'ftp://files.example.com', 'menu_link_5_new_tab' => '0',
        ],
    ]);
}

test('the menu shows the configured links and skips empty or non-http ones', function (): void {
    $menu = renderPalisadeMenuLinks(true);

    expect($menu)->toContain('<a href="https://docs.example.com/?a=1&amp;b=2" title="Docs &amp; Help" target="_blank" rel="noopener noreferrer">')
        ->toContain('<use href="#external-link"/>')
        ->toContain('<a href="http://status.example.com" title="Status">')
        ->not->toContain('javascript:')
        ->not->toContain('untitled.example.com')
        ->not->toContain('ftp://');
});

test('visitors who are not signed in get no menu links', function (): void {
    expect(renderPalisadeMenuLinks(false))->not->toContain('example.com');
});
