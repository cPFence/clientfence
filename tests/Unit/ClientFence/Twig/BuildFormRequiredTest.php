<?php

declare(strict_types=1);

use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

test('settings forms mark a required field required, except a saved secret', function (array $field, array $values, array $secrets, bool $required): void {
    $twig = new Environment(new ChainLoader([
        new ArrayLoader(['form.html.twig' => "{% import 'macro_functions.html.twig' as mf %}{{ mf.build_form({key: field}, values, {}, secrets) }}"]),
        new FilesystemLoader(PATH_ROOT . '/themes/admin_default/html'),
    ]), ['strict_variables' => true]);
    $twig->addFilter(new TwigFilter('trans', static fn (string $text): string => $text));
    $twig->addFilter(new TwigFilter('markdown_to_html', static fn (string $text): string => $text));
    $twig->addFilter(new TwigFilter('url', static fn (string $path, array $query = []): string => $path));
    // Used by other macros of the file, which Twig compiles together.
    foreach (['has_permission', 'extension_is_on', 'ip_lookup_link', 'recaptcha'] as $name) {
        $twig->addFunction(new TwigFunction($name, static fn (): bool => false));
    }

    $html = $twig->render('form.html.twig', ['field' => ['text', ['label' => 'API key'] + $field], 'values' => $values, 'secrets' => $secrets]);

    expect((bool) preg_match('/<input[^>]*\srequired[\s>]/', $html))->toBe($required);
})->with([
    'required' => [['required' => true], [], [], true],
    'not required' => [['required' => false], [], [], false],
    'required as the text false' => [['required' => 'false'], [], [], false],
    'a required secret not saved yet' => [['required' => true], [], ['key'], true],
    'a required secret already saved' => [['required' => true], ['key_set' => true], ['key'], false],
]);
