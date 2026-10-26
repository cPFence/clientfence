<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 */

declare(strict_types=1);

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

test('API key settings render missing defaults and explicit overrides', function (array $config, array $requestConfig, int $length, bool $split, int $interval, string $case): void {
    $twig = new Environment(new Twig\Loader\ChainLoader([new Twig\Loader\ArrayLoader(['macro_functions.html.twig' => '{% macro status_badge(status, type) %}{% endmacro %}']), new FilesystemLoader(PATH_MODS . '/Serviceapikey/templates/admin')]), ['strict_variables' => true]);
    $twig->addFilter(new TwigFilter('format_datetime', static fn ($value) => (string) $value));
    $twig->addFilter(new TwigFilter('trans', static fn (string $value): string => $value));
    $twig->addFilter(new TwigFilter('api_url', static fn (string $value, ?array $query = null): string => $value));
    foreach (['fb_api_link', 'fb_api_form'] as $function) {
        $twig->addFunction(new TwigFunction($function, static fn (mixed ...$arguments): string => ''));
    }
    $html = $twig->render(
        'mod_serviceapikey_manage.html.twig',
        [
            'request' => ['config' => $requestConfig],
            'service' => ['api_key' => 'test-key', 'config' => $config, 'last_used_at' => null, 'use_count' => 0, 'allowed_ips' => []],
            'order' => ['id' => 1, 'status' => 'active'],
            'csp_nonce' => 'test-nonce',
        ],
    );

    $document = new DOMDocument();
    @$document->loadHTML($html, LIBXML_NONET);
    $xpath = new DOMXPath($document);
    expect($xpath->evaluate('string(//input[@name="config[length]"]/@value)'))->toBe((string) $length)
        ->and($xpath->evaluate('string(//input[@name="config[split_interval]"]/@value)'))->toBe((string) $interval)
        ->and($xpath->evaluate('string(//input[@name="config[split]"][@checked]/@value)'))->toBe($split ? '1' : '0')
        ->and($xpath->evaluate('string(//select[@name="config[case]"]/option[@selected]/@value)'))->toBe($case);
})->with([
    'defaults' => [[], [], 32, true, 8, 'upper'],
    'stored options' => [['length' => 16, 'split' => false, 'split_interval' => 4, 'case' => 'lower'], [], 16, false, 4, 'lower'],
    'request overrides' => [['length' => 16, 'split' => true], ['length' => 24, 'split' => '0', 'split_interval' => 6, 'case' => 'mixed'], 24, false, 6, 'mixed'],
]);
