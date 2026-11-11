<?php

declare(strict_types=1);

/**
 * ClientFence: the Palisade sign-up page (/signup) goes on to ?back= (the checkout's "Create an account")
 * under the sign-in page's rule, and keeps it on its "Sign in" links.
 */

use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class PalisadeSignupGuestStub
{
    public function __call(string $name, array $args): mixed
    {
        return match ($name) {
            'clientfence_signup_info' => ['open' => true, 'terms_url' => '', 'privacy_url' => ''],
            'system_company' => ['email' => ''],
            'client_required', 'system_countries', 'client_custom_fields' => [],
            default => false,
        };
    }
}

function renderPalisadeSignup(string $back): string
{
    $loader = new ChainLoader([
        new ArrayLoader([
            'layout_public.html.twig' => '{% block body %}{% endblock %}',
            'partials/auth_shell.html.twig' => '{% block auth_content %}{% endblock %}',
            'macro_functions.html.twig' => '{% macro recaptcha() %}{% endmacro %}',
        ]),
        new FilesystemLoader(PATH_THEMES . '/palisade/html'),
    ]);
    $twig = new Environment($loader, ['strict_variables' => true, 'autoescape' => 'html']);
    $twig->addFilter(new TwigFilter('trans', static fn (string $text): string => $text));
    $twig->addFilter(new TwigFilter('url', static fn (string $path, array $query = []): string => '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '')));
    $twig->addFilter(new TwigFilter('api_url', static fn (string $path, array $query = [], string $role = 'client'): string => "/api/{$role}/{$path}"));
    $twig->addFunction(new TwigFunction('fb_api_form', static fn (): string => ''));
    $twig->addFunction(new TwigFunction('antispam_honeypot', static fn (): array => ['enabled' => false]));
    $twig->addFunction(new TwigFunction('render_widgets', static fn (): string => ''));

    return $twig->render('mod_page_signup.html.twig', [
        'client' => null,
        'settings' => [],
        'CSRFToken' => 'token',
        'request' => ['back' => $back],
        'guest' => new PalisadeSignupGuestStub(),
    ]);
}

test('the sign-up goes on to a safe back path and keeps it on its sign-in links, else to the client area', function (): void {
    expect(renderPalisadeSignup('/cart'))->toContain('data-redirect="/cart"')
        ->toContain('href="/login?back=%2Fcart"')
        ->and(renderPalisadeSignup('//evil.example'))->toContain('data-redirect="/"')->not->toContain('evil.example')
        ->and(renderPalisadeSignup('https://x'))->toContain('data-redirect="/"')->not->toContain('https://x');
});
