<?php

declare(strict_types=1);

/**
 * ClientFence: the Palisade menu and footer list the custom pages set to show there, only while the
 * Custom Pages extension is on.
 */

use Twig\Environment;
use Twig\Extra\Intl\IntlExtension;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class PalisadeCustomPagesGuestStub
{
    public function __construct(private bool $on)
    {
    }

    public function __call(string $name, array $args): mixed
    {
        return match ($name) {
            'extension_is_on' => $this->on && $args[0]['mod'] === 'custompages',
            'custompages_links' => [
                ['title' => 'Terms', 'slug' => 'terms', 'menu' => false, 'footer' => true],
                ['title' => 'Guide', 'slug' => 'guide', 'menu' => true, 'footer' => false],
            ],
            'system_company' => ['name' => 'Acme'],
            default => false,
        };
    }
}

function renderPalisadeCustomPages(string $template, bool $on): string
{
    $twig = new Environment(new FilesystemLoader(PATH_THEMES . '/palisade/html'), ['strict_variables' => true, 'autoescape' => 'html']);
    $twig->addExtension(new IntlExtension());
    $twig->addFilter(new TwigFilter('trans', static fn (string $text): string => $text));
    $twig->addFilter(new TwigFilter('url', static fn (string $path): string => '/' . ltrim($path, '/')));
    $twig->addFunction(new TwigFunction('render_widgets', static fn (): string => ''));

    return $twig->render($template, [
        'client' => null,
        'settings' => ['require_login' => '0'],
        'current_active_menu' => 'page-guide',
        'guest' => new PalisadeCustomPagesGuestStub($on),
    ]);
}

test('the menu and the footer link the custom pages set to show there', function (): void {
    $menu = renderPalisadeCustomPages('partial_menu.html.twig', true);
    $footer = renderPalisadeCustomPages('partials/footer.html.twig', true);

    expect($menu)->toContain('<a href="/page/guide" title="Guide" class="active" aria-current="page">')
        ->not->toContain('/page/terms')
        ->and($footer)->toContain('<a href="/page/terms">Terms</a>')
        ->not->toContain('/page/guide');
});

test('no custom page links while the extension is off', function (): void {
    expect(renderPalisadeCustomPages('partial_menu.html.twig', false))->not->toContain('/page/')
        ->and(renderPalisadeCustomPages('partials/footer.html.twig', false))->not->toContain('/page/');
});
