<?php

declare(strict_types=1);

use ClientFence\Site;
use Symfony\Component\HttpFoundation\Request;

use function Tests\Helpers\container;

/** A test module's service that answers site() with `$answer($host)` and remembers the hosts it was asked. */
function siteProvider(callable $answer): object
{
    return new class($answer) {
        /** @var list<string> */
        public array $asked = [];

        public function __construct(private readonly mixed $answer)
        {
        }

        public function site(string $host): mixed
        {
            $this->asked[] = $host;

            return ($this->answer)($host);
        }
    };
}

/**
 * A container whose switched-on modules are `$providers` (they declare "sites": true) and `$others` (they do not),
 * name => service.
 */
function siteDi(array $providers, array $others = []): Pimple\Container
{
    $di = container();
    $modules = $providers + $others;
    ksort($modules);
    $extension = Mockery::mock();
    $extension->shouldReceive('getCoreAndActiveModules')->andReturn(array_keys($modules));
    $di['mod_service'] = $di->protect(fn (string $name): object => $name === 'extension' ? $extension : $modules[$name]);
    $di['mod'] = $di->protect(function (string $name) use ($providers): object {
        $mod = Mockery::mock(ClientFence\Module::class);
        $mod->shouldReceive('hasManifest')->andReturn(true);
        $mod->shouldReceive('getManifest')->andReturn(['id' => $name] + (isset($providers[$name]) ? ['sites' => true] : []));

        return $mod;
    });

    return $di;
}

function shopSite(string $key = 'shop'): Site
{
    return new Site('https://shop.example.test/', 'shop.example.test', $key);
}

/** shopSite() as the core keeps it: its key names the module that answered. */
function servedShop(string $module = 'shop', string $key = 'shop'): Site
{
    return shopSite($module . '/' . $key);
}

test('the primary site is the portal URL: in the console, on the portal host and on a host no module serves', function (): void {
    $shop = siteProvider(fn (string $host): ?Site => $host === 'shop.example.test' ? shopSite() : null);
    $di = siteDi(['shop' => $shop]);
    $malformed = Request::create(SYSTEM_URL);
    $malformed->headers->set('HOST', 'bad host!');
    $primary = Site::primary();

    expect([$primary->url, $primary->host, $primary->key])->toBe([SYSTEM_URL, parse_url(SYSTEM_URL, PHP_URL_HOST), null])
        ->and(Site::current(null))->toEqual($primary)
        ->and(Site::current(container()))->toEqual($primary)
        ->and(Site::resolve($di, null))->toEqual($primary)
        ->and(Site::resolve($di, Request::create(SYSTEM_URL . 'order')))->toEqual($primary)
        ->and(Site::resolve($di, $malformed))->toEqual($primary)
        ->and($shop->asked)->toBe([])
        ->and(Site::resolve($di, Request::create('https://unknown.example.test/')))->toEqual($primary)
        ->and($shop->asked)->toBe(['unknown.example.test'])
        ->and($di['logger']->calls)->toBe([]);
});

test('a switched-on module that declares "sites" serves its host; a module that does not declare it is not asked', function (): void {
    $other = siteProvider(fn (): never => throw new LogicException('asked'));
    $di = siteDi(['shop' => siteProvider(fn (string $host): ?Site => $host === 'shop.example.test' ? shopSite() : null)], ['client' => $other]);

    expect(Site::resolve($di, Request::create('https://SHOP.example.test/cart')))->toEqual(servedShop())
        ->and(Site::resolve($di, Request::create('http://shop.example.test/')))->toEqual(servedShop())
        ->and($other->asked)->toBe([])
        ->and($di['logger']->calls)->toBe([]);
});

test('a switched-on module with a broken manifest is left out; the other modules still answer', function (): void {
    $di = siteDi(['shop' => siteProvider(fn (string $host): ?Site => $host === 'shop.example.test' ? shopSite() : null)], ['broken' => siteProvider(fn (): never => throw new LogicException('asked'))]);
    $mod = $di['mod'];
    $di['mod'] = $di->protect(function (string $name) use ($mod): object {
        if ($name !== 'broken') {
            return $mod($name);
        }
        $broken = Mockery::mock(ClientFence\Module::class);
        $broken->shouldReceive('hasManifest')->andReturn(true);
        $broken->shouldReceive('getManifest')->andThrow(new ClientFence\InformationException('Invalid manifest file for the :mod module', [':mod' => $name]));

        return $broken;
    });

    expect(Site::resolve($di, Request::create('https://www.example.test/')))->toEqual(Site::primary())
        ->and(Site::resolve($di, Request::create('https://shop.example.test/')))->toEqual(servedShop());
});

test('the first module by name that serves the host wins', function (): void {
    $gamma = siteProvider(fn (): Site => shopSite('gamma'));
    $di = siteDi(['gamma' => $gamma, 'beta' => siteProvider(fn (): Site => shopSite('beta')), 'alpha' => siteProvider(fn (): ?Site => null)]);

    expect(Site::resolve($di, Request::create('https://shop.example.test/'))->key)->toBe('beta/beta')
        ->and($gamma->asked)->toBe([]);
});

test('a module that fails or answers with a site that is not on the host is skipped, with a warning', function (Closure $answer, string $why): void {
    $di = siteDi(['alpha' => siteProvider($answer), 'beta' => siteProvider(fn (): Site => shopSite('beta'))]);

    expect(Site::resolve($di, Request::create('https://shop.example.test/'))->key)->toBe('beta/beta')
        ->and($di['logger']->calls)->toHaveCount(1)
        ->and($di['logger']->calls[0]['method'])->toBe('warning')
        ->and($di['logger']->calls[0]['params'][0])->toBe('The alpha module could not answer for the host shop.example.test: ' . $why);
})->with([
    'it throws' => [fn (): never => throw new RuntimeException('the database is down'), 'the database is down'],
    'another host' => [fn (): Site => new Site('https://other.example.test/', 'other.example.test', 'x'), 'it answered with a site that is not on that host'],
    'a URL on another host' => [fn (): Site => new Site('https://other.example.test/', 'shop.example.test', 'x'), 'it answered with a site that is not on that host'],
    'a URL on another port' => [fn (): Site => new Site('https://shop.example.test:8443/', 'shop.example.test', 'x'), 'it answered with a site that is not on that host'],
    'not http(s)' => [fn (): Site => new Site('ftp://shop.example.test/', 'shop.example.test', 'x'), 'it answered with a site that is not on that host'],
    'no trailing slash' => [fn (): Site => new Site('https://shop.example.test', 'shop.example.test', 'x'), 'it answered with a site that is not on that host'],
    'no key' => [fn (): Site => new Site('https://shop.example.test/', 'shop.example.test'), 'it answered with a site that is not on that host'],
    'not a site' => [fn (): string => 'https://shop.example.test/', 'it answered with a site that is not on that host'],
    'a fragment in the URL' => [fn (): Site => new Site('https://shop.example.test/#preview/', 'shop.example.test', 'x'), 'it answered with a site that is not on that host'],
    'a query in the URL' => [fn (): Site => new Site('https://shop.example.test/?a=/', 'shop.example.test', 'x'), 'it answered with a site that is not on that host'],
    'a backslash before the host' => [fn (): Site => new Site('https://other.example.test\\@shop.example.test/', 'shop.example.test', 'x'), 'it answered with a site that is not on that host'],
    'user info before the host' => [fn (): Site => new Site('https://other.example.test@shop.example.test/', 'shop.example.test', 'x'), 'it answered with a site that is not on that host'],
    'a key over 50 characters' => [fn (): Site => shopSite(str_repeat('k', 51)), 'it answered with a site that is not on that host'],
]);

test('the origin is the lower-case scheme and the host, without the default port, for any host name', function (): void {
    $di = siteDi(['shop' => siteProvider(fn (): Site => new Site('HTTPS://SHOP.example.test/', 'shop.example.test', 'shop'))]);

    expect(Site::resolve($di, Request::create('https://shop.example.test/'))->origin())->toBe('https://shop.example.test')
        ->and((new Site('https://bücher.example/', 'bücher.example', 'x'))->origin())->toBe('https://bücher.example')
        ->and((new Site('http://shop.example.test:8080/', 'shop.example.test:8080', 'x'))->origin())->toBe('http://shop.example.test:8080');
});

test('without the module list (the database is down) a request keeps the primary site', function (): void {
    $di = siteDi([]);
    $extension = Mockery::mock();
    $extension->shouldReceive('getCoreAndActiveModules')->andThrow(new RuntimeException('the database is down'));
    $di['mod_service'] = $di->protect(fn (): object => $extension);

    expect(Site::resolve($di, Request::create('https://www.example.test/invoice/payment-return')))->toEqual(Site::primary());
});

test('$di[\'site\'] is worked out once, on the web only, and links are built on its base', function (): void {
    $source = (string) file_get_contents(PATH_ROOT . '/di.php');
    $block = static function (string $start, string $end) use ($source): string {
        $at = strpos($source, $start);

        return substr($source, $at, strpos($source, $end, $at) + strlen($end) - $at);
    };
    $siteService = str_replace('Environment::', '\ClientFence\Environment::', $block("\$di['site'] =", ";\n"));
    $urlService = $block("\$di['url'] =", "\n};");
    $shop = siteProvider(fn (): Site => shopSite());

    // The web: the suite itself runs in the console.
    $di = siteDi(['shop' => $shop]);
    $di['request'] = Request::create('https://shop.example.test/invoice');
    eval(str_replace('\ClientFence\Environment::isCLI()', 'false', $siteService));
    eval($urlService);
    $tools = new ClientFence\Tools();
    $tools->setDi($di);

    expect($di['site'])->toEqual(servedShop())
        ->and($di['site'])->toBe($di['site'])
        ->and($shop->asked)->toBe(['shop.example.test'])
        ->and($di['url']->link('invoice/abc', ['status' => 'ok']))->toBe('https://shop.example.test/invoice/abc?status=ok')
        ->and($tools->url('/order'))->toBe('https://shop.example.test/order');

    // The console (cron, the command line): the primary site.
    $di = siteDi(['shop' => $shop]);
    $di['request'] = Request::create('https://shop.example.test/invoice');
    eval($siteService);
    eval($urlService);

    expect($di['site'])->toEqual(Site::primary())
        ->and($di['url']->link('order'))->toBe(SYSTEM_URL . 'order');
});
