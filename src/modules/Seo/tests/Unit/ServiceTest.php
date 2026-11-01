<?php

declare(strict_types=1);

use ClientFence\Mod\Seo\Service;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

const SEO_SITEMAP = '<?xml version="1.0"?><urlset><url><loc>https://billing.example.com/order/a?x=1&amp;y=2</loc></url><url><loc>https://billing.example.com/news/b</loc></url></urlset>';

/**
 * @param list<array{0: string, 1: string, 2: array}> $requests filled with the requests sent
 * @param list<string>                                $saved    filled with the stored results
 */
function seoService(array $config, ?array $last, array $responses, ?array &$requests = [], ?array &$saved = []): Service
{
    $system = Mockery::mock(ClientFence\Mod\System\Service::class);
    $requests = [];
    $saved = [];
    $system->shouldReceive('getParamValue')->with('mod_seo_indexnow', '')->andReturn($last === null ? '' : json_encode($last));
    $system->shouldReceive('setParamValue')->andReturnUsing(function (string $param, string $value) use (&$saved): bool {
        $saved[] = $value;

        return true;
    });

    $di = new Pimple\Container();
    $di['mod_config'] = $di->protect(fn (string $name): array => $config);
    $di['mod_service'] = $di->protect(fn (string $name): object => $system);
    $di['http_client'] = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests, &$responses): MockResponse {
        $requests[] = [$method, $url, $options];

        return array_shift($responses);
    });
    $service = new Service();
    $service->setDi($di);

    return $service;
}

function seoQuietLog(callable $run): mixed
{
    $log = ini_set('error_log', '/dev/null');
    try {
        return $run();
    } finally {
        ini_set('error_log', (string) $log);
    }
}

test('the sitemap addresses are submitted to IndexNow with the key and its file', function (): void {
    $result = seoService(['indexnow_key' => 'abc123'], null, [new MockResponse(SEO_SITEMAP), new MockResponse('', ['http_code' => 202])], $requests, $saved)->submit();

    expect($requests[0][1])->toBe(SYSTEM_URL . 'sitemap.xml')
        ->and($requests[1][0])->toBe('POST')
        ->and($requests[1][1])->toBe(Service::INDEXNOW_URL)
        ->and(json_decode($requests[1][2]['body'], true))->toBe([
            'host' => parse_url(SYSTEM_URL, PHP_URL_HOST),
            'key' => 'abc123',
            'keyLocation' => SYSTEM_URL . 'abc123.txt',
            'urlList' => ['https://billing.example.com/order/a?x=1&y=2', 'https://billing.example.com/news/b'],
        ])
        ->and($result)->toMatchArray(['ok' => true, 'status' => 202, 'count' => 2])
        ->and(json_decode($saved[0], true))->toBe($result);
});

test('an unchanged sitemap is not submitted again unless forced', function (bool $forced, int $sent): void {
    $last = ['at' => date('Y-m-d H:i:s'), 'ok' => true, 'status' => 200, 'count' => 2, 'error' => '', 'hash' => hash('sha256', SEO_SITEMAP)];
    seoService(['indexnow_key' => 'abc123'], $last, [new MockResponse(SEO_SITEMAP), new MockResponse('')], $requests)->submit($forced);

    expect(count($requests))->toBe($sent);
})->with(['by cron' => [false, 1], 'Submit now' => [true, 2]]);

test('a network error is recorded, not thrown, and the cron waits a day before retrying', function (): void {
    $result = seoQuietLog(fn () => seoService(['indexnow_key' => 'abc123'], null, [new MockResponse(SEO_SITEMAP), new MockResponse('', ['error' => 'Connection timed out'])])->submit());
    $retry = seoService(['indexnow_key' => 'abc123'], $result, [], $requests)->submit();

    expect($result)->toMatchArray(['ok' => false, 'status' => 0])
        ->and($result['error'])->toContain('Connection timed out')
        ->and($retry)->toBeNull()
        ->and($requests)->toBe([]);
});

test('nothing is submitted without a key', function (): void {
    expect(seoService([], null, [], $requests)->submit(true))->toBeNull()
        ->and($requests)->toBe([]);
});

test('saving the verification codes keeps the IndexNow key, or creates it once', function (array $stored): void {
    $extension = Mockery::mock(ClientFence\Mod\Extension\Service::class);
    $extension->shouldReceive('getConfig')->with('mod_seo')->andReturn(['ext' => 'mod_seo', ...$stored]);
    $extension->shouldReceive('setConfig')->once()->with(Mockery::on(fn (array $config): bool => $config['ext'] === 'mod_seo'
        && $config['google_verification'] === 'Ab-1_x' && $config['bing_verification'] === ''
        && preg_match('/\A[a-f0-9]{32}\z/', $config['indexnow_key']) === 1
        && ($stored === [] || $config['indexnow_key'] === $stored['indexnow_key'])));

    $di = new Pimple\Container();
    $di['mod_service'] = $di->protect(fn (string $name): object => $extension);
    $service = new Service();
    $service->setDi($di);

    $service->saveVerification(['google_verification' => ' Ab-1_x ', 'bing_verification' => '']);
})->with(['no key yet' => [[]], 'a key' => [['indexnow_key' => str_repeat('a1', 16)]]]);

test('a verification code is the content value only', function (mixed $code): void {
    expect(fn () => seoService([], null, [])->saveVerification(['google_verification' => $code]))->toThrow(ClientFence\InformationException::class);
})->with(['a meta tag' => ['<meta name="google-site-verification" content="abc">'], 'too long' => [str_repeat('a', 101)], 'an array' => [['abc']]]);

test('the key file holds the key', function (): void {
    $di = new Pimple\Container();
    $di['mod_config'] = $di->protect(fn (string $name): array => ['indexnow_key' => 'abc123']);
    $app = Mockery::mock(ClientFence\App::class);
    $app->shouldReceive('get')->once()->with('/abc123.txt', 'get_key', [], ClientFence\Mod\Seo\Controller\Client::class);
    $controller = new ClientFence\Mod\Seo\Controller\Client();
    $controller->setDi($di);

    $controller->register($app);
    $response = $controller->get_key($app);

    expect($response->getContent())->toBe('abc123')
        ->and($response->headers->get('Content-Type'))->toBe('text/plain; charset=utf-8');
});

test('the client area head carries the verification tags, escaped, only while the extension is on', function (bool $on): void {
    $guest = new class($on) {
        public function __construct(private bool $on)
        {
        }

        public function __call(string $name, array $args): mixed
        {
            return match ($name) {
                'extension_is_on' => $this->on && $args[0]['mod'] === 'seo',
                'seo_verification' => ['google' => 'g"1', 'bing' => 'b-2'],
                default => null,
            };
        }
    };
    $twig = new Environment(new ChainLoader([
        new ArrayLoader(['partials/asset_version.html.twig' => '1', 'partials/theme_init.html.twig' => '', 'partials/i18n.html.twig' => '']),
        new FilesystemLoader(PATH_THEMES . '/palisade/html'),
    ]), ['autoescape' => 'html']);
    foreach (['asset_url', 'public_asset_url', 'script_tag', 'stylesheet_tag', 'url'] as $filter) {
        $twig->addFilter(new TwigFilter($filter, static fn (string $value): string => $value));
    }

    $html = $twig->render('partials/head.html.twig', ['settings' => [], 'page_title' => 'Home', 'is_rtl' => false, 'csp_nonce' => 'n', 'guest' => $guest]);

    if ($on) {
        expect($html)->toContain('<meta name="google-site-verification" content="g&quot;1">')
            ->toContain('<meta name="msvalidate.01" content="b-2">');
    } else {
        expect($html)->not->toContain('google-site-verification')->not->toContain('msvalidate.01');
    }
})->with(['on' => [true], 'off' => [false]]);
