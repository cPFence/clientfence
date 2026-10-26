<?php

declare(strict_types=1);

/**
 * ClientFence: every email template a module ships loads under the email sandbox
 * (ClientFence\Twig\EmailPolicy), which renders them when they are sent. A disallowed tag, filter
 * or function (capitalize, replace, ...) only shows when the email is sent: the send fails and the
 * email is lost.
 */

use ClientFence\Twig\EmailPolicy;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/** The email environment's own filters and functions (TwigFactory::createEmailEnvironment()), as stubs. */
function emailSandboxEnvironment(): Environment
{
    $twig = new Environment(new ArrayLoader(), ['autoescape' => 'html']);
    $twig->addExtension(new SandboxExtension(EmailPolicy::create(), true));
    foreach ([
        'format_currency', 'format_date', 'format_datetime', 'format_number', 'format_time', 'currency_name',
        'currency_symbol', 'country_name', 'url', 'daysleft', 'trans', 'period_title', 'markdown_to_html',
    ] as $filter) {
        $twig->addFilter(new TwigFilter($filter, static fn (mixed $value, mixed ...$options): mixed => $value, ['is_variadic' => true]));
    }
    $twig->addFunction(new TwigFunction('country_names', static fn (): array => []));

    return $twig;
}

test('shipped email templates use only what the email sandbox allows', function (string $file): void {
    $twig = emailSandboxEnvironment();

    // Rendering runs the sandbox's checks of the template's tags, filters and functions.
    $twig->createTemplate((string) file_get_contents($file), basename($file))->render([]);

    expect(true)->toBeTrue();
})->with(function (): array {
    $files = glob(dirname(__DIR__, 4) . '/src/modules/*/templates/email/*.html.twig') ?: [];
    sort($files);

    return array_combine(array_map(static fn (string $f): string => basename(dirname($f, 3)) . '/' . basename($f), $files), array_map(static fn (string $f): array => [$f], $files));
});

test('the check catches a disallowed filter', function (): void {
    expect(fn () => emailSandboxEnvironment()->createTemplate("{{ 'on_hold'|replace({'_': ' '})|capitalize }}", 'bad')->render([]))
        ->toThrow(Twig\Sandbox\SecurityError::class);
});
