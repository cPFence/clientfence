<?php

declare(strict_types=1);

/**
 * ClientFence: modules add client menu links through the client.theme.menu.end widget slot.
 */

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

test('the menu ends with a widget slot that knows the active page', function (): void {
    $twig = new Environment(new FilesystemLoader(PATH_THEMES . '/palisade/html'), ['strict_variables' => true, 'autoescape' => 'html']);
    $twig->addFilter(new TwigFilter('trans', static fn (string $text): string => $text));
    $twig->addFilter(new TwigFilter('url', static fn (string $path): string => '/' . ltrim($path, '/')));
    // Plain text: another test may have compiled this template with render_widgets output escaped.
    $twig->addFunction(new TwigFunction('render_widgets', static fn (string $slot, array $context = []): string => $slot === 'client.theme.menu.end' ? 'module-link:' . $context['active_menu'] : ''));
    $guest = new class {
        public function __call(string $name, array $args): mixed
        {
            return false;
        }
    };

    $menu = $twig->render('partial_menu.html.twig', ['client' => null, 'settings' => ['require_login' => '0'], 'current_active_menu' => 'hello', 'guest' => $guest]);

    expect($menu)->toContain('module-link:hello');
});
