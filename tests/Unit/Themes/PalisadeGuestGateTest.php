<?php

declare(strict_types=1);

/**
 * ClientFence: a page a signed-out visitor may not see shows only the sign-in gate. Its own scripts
 * stay out too: they expect the page's content (the contact form's anti-spam script failed on the
 * gate with "Cannot read properties of null").
 */

use Twig\Environment;
use Twig\Extra\Intl\IntlExtension;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class PalisadeGateGuestStub
{
    public function __construct(private bool $guestTickets, private string $locale = 'en_US')
    {
    }

    public function __call(string $name, array $args): mixed
    {
        if ($name === 'system_locale') {
            return $this->locale;
        }

        return $name === 'support_guest_tickets_enabled' ? $this->guestTickets : false;
    }
}

function renderPalisadeGatedPage(string $layout, bool $guestTickets, string $locale = 'en_US'): string
{
    $loader = new ChainLoader([
        new ArrayLoader([
            'page.html.twig' => '{% extends "' . $layout . '" %}{% block js %}<script>pageScript()</script>{% endblock %}{% block body %}<form id="page-form"></form>{% endblock %}{% block content %}<form id="page-form"></form>{% endblock %}',
            'partials/head.html.twig' => '<title>{{ page_title }}</title>',
            'partials/guest_gate.html.twig' => '<main id="gate"></main>',
            'partials/toasts.html.twig' => '',
            'partials/page_end.html.twig' => '',
        ]),
        new FilesystemLoader(PATH_THEMES . '/palisade/html'),
    ]);
    $twig = new Environment($loader, ['strict_variables' => true, 'autoescape' => 'html']);
    $twig->addExtension(new IntlExtension());
    $twig->addFilter(new TwigFilter('trans', static fn (string $text): string => $text));
    $twig->addFilter(new TwigFilter('url', static fn (string $path): string => '/' . ltrim($path, '/')));
    $twig->addFunction(new TwigFunction('render_widgets', static fn (): string => ''));
    $twig->addFunction(new TwigFunction('svg_sprite', static fn (): string => ''));

    return $twig->render('page.html.twig', [
        'client' => null,
        'settings' => ['require_login' => '1'],
        'request_path' => '/support/contact-us',
        'guest' => new PalisadeGateGuestStub($guestTickets, $locale),
    ]);
}

test('a gated page leaves out its own scripts', function (string $layout): void {
    $html = renderPalisadeGatedPage($layout, false);

    expect($html)->toContain('<main id="gate"></main>')
        ->and($html)->not->toContain('page-form')
        ->and($html)->not->toContain('pageScript()');
})->with(['layout_public.html.twig', 'layout_default.html.twig']);

test('an open page keeps its scripts', function (string $layout): void {
    $html = renderPalisadeGatedPage($layout, true);

    expect($html)->toContain('page-form')
        ->and($html)->toContain('pageScript()')
        ->and($html)->not->toContain('id="gate"');
})->with(['layout_public.html.twig']);

test('both client layouts follow the selected language direction', function (string $locale, string $direction): void {
    foreach (['layout_public.html.twig', 'layout_default.html.twig'] as $layout) {
        $html = renderPalisadeGatedPage($layout, false, $locale);

        expect($html)->toContain('<html lang="' . str_replace('_', '-', $locale) . '" dir="' . $direction . '"');
    }
})->with([
    'Arabic' => ['ar_SA', 'rtl'],
    'Persian' => ['fa_IR', 'rtl'],
    'Hebrew' => ['he_IL', 'rtl'],
    'English' => ['en_US', 'ltr'],
]);
