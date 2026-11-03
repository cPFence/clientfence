<?php

declare(strict_types=1);

use ClientFence\Mod\Embed\Service;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

function embedService(array $config, ?object $extension = null): Service
{
    $di = new Pimple\Container();
    $di['mod_config'] = $di->protect(fn (string $name): array => $config);
    $di['mod_service'] = $di->protect(fn (string $name): ?object => $extension);
    $service = new Service();
    $service->setDi($di);

    return $service;
}

test('only valid saved origins reach the frame header', function (): void {
    expect(embedService(['allowed_origins' => ['https://example.com', "* 'unsafe-inline'", 'https://a.example.org:8443', ['x']]])->getAllowedOrigins())
        ->toBe(['https://example.com', 'https://a.example.org:8443'])
        ->and(embedService(['allowed_origins' => '*'])->getAllowedOrigins())->toBe([]);
});

test('the allowed websites are saved one per line', function (): void {
    $extension = Mockery::mock(ClientFence\Mod\Extension\Service::class);
    $extension->shouldReceive('setConfig')->once()->with(['ext' => 'mod_embed', 'allowed_origins' => ['https://example.com', 'http://shop.example.net:8080']]);

    embedService([], $extension)->saveAllowedOrigins(" https://Example.com/\r\n\nhttp://shop.example.net:8080\nhttps://example.com\n");
});

test('invalid websites are refused', function (string $text): void {
    expect(fn () => embedService([])->saveAllowedOrigins($text))->toThrow(ClientFence\InformationException::class);
})->with([
    'a path' => ['https://example.com/page'],
    'ftp' => ['ftp://example.com'],
    'a wildcard' => ['https://*.example.com'],
    'a query' => ['https://example.com?a=1'],
    'more than 20' => [implode("\n", array_map(fn (int $i): string => "https://site{$i}.example.com", range(1, 21)))],
]);

test('the sign-in embed sends the visitor to the sign-in page in the top window, in the chosen theme and direction', function (string $locale, string $direction): void {
    $twig = new Environment(new FilesystemLoader(PATH_MODS . '/Embed/templates/client'), ['autoescape' => 'html']);
    $twig->addFilter(new TwigFilter('trans', static fn (string $text): string => $text));
    $twig->addFilter(new TwigFilter('url', static fn (string $path): string => '/' . $path));

    $html = $twig->render('mod_embed_loginform.html.twig', ['guest' => ['system_locale' => $locale], 'request' => ['theme' => 'dark']]);

    expect($html)->toContain('<html lang="' . str_replace('_', '-', $locale) . '" dir="' . $direction . '" data-theme="dark">')
        ->toContain('<form action="/login" method="get" target="_top"')
        ->not->toContain('name="password"')
        ->not->toContain('client/login');
})->with([
    'Arabic' => ['ar_SA', 'rtl'],
    'Persian' => ['fa_IR', 'rtl'],
    'Hebrew' => ['he_IL', 'rtl'],
    'English' => ['en_US', 'ltr'],
]);
