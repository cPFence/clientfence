<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0
 */

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Request;

/**
 * Build a \ClientFence\AppClient that overrides render() with a caller-supplied
 * callback, so the get_custom_page catch-block logic can be exercised
 * without spinning up a Twig environment.
 *
 * @param list<string> $activeExtensions extensions the stub reports as on (ClientFence)
 */
function appClientWithRender(callable $render, bool $clientLoggedIn = false, array $activeExtensions = [], ?object $module = null, ?Request $request = null): \ClientFence\AppClient
{
    $app = new class($render) extends \ClientFence\AppClient {
        /** @var callable */
        private $renderCallback;

        public function __construct(callable $render)
        {
            parent::__construct();
            $this->renderCallback = $render;
        }

        public function render($fileName, $variableArray = [], $ext = 'html.twig'): string
        {
            return ($this->renderCallback)($fileName, $variableArray, $ext);
        }
    };

    $di = new Pimple\Container();
    $di['logger'] = new class {
        public function setChannel(string $channel): self
        {
            return $this;
        }

        public function error(string|Stringable $message, array $context = []): void
        {
        }

        public function info(string|Stringable $message, array $context = []): void
        {
        }
    };
    $extensionService = new class($activeExtensions) {
        /** @param list<string> $active */
        public function __construct(private readonly array $active)
        {
        }

        public function isExtensionActive(string $type, string $id): bool
        {
            return in_array($id, $this->active, true);
        }
    };
    $di['request'] = $request ?? Request::create('http://localhost/test');
    // ClientFence's checks on every client page: nobody signed in, default settings.
    $di['session'] = Mockery::mock(ClientFence\Session::class)->shouldIgnoreMissing();
    $di['mod'] = $di->protect(static fn (): object => $module ?? Mockery::mock(ClientFence\Module::class)
        ->shouldReceive('getConfig')->andReturn([])->getMock());
    $di['mod_service'] = $di->protect(static fn (): object => $extensionService);
    $di['auth'] = Mockery::mock(\ClientFence\Authorization::class)
        ->shouldReceive('isClientLoggedIn')->andReturn($clientLoggedIn)->getMock();
    $di['url'] = Mockery::mock(\ClientFence\Url::class)
        ->shouldReceive('link')->andReturnArg(0)->getMock();
    $app->setDi($di);
    $app->setUrl('/test');

    return $app;
}

test('get_custom_page returns 200 on successful render', function (): void {
    $app = appClientWithRender(fn (): string => '<html>ok</html>');

    $response = $app->get_custom_page('signup');

    expect($response->getStatusCode())->toBe(200);
    expect($response->getContent())->toBe('<html>ok</html>');
});

test('get_custom_page returns XML content type for sitemap', function (): void {
    $app = appClientWithRender(fn (): string => '<urlset></urlset>');

    $response = $app->get_custom_page('sitemap.xml');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toContain('application/xml')
        ->and($response->getContent())->toBe('<urlset></urlset>');
});

test('get_custom_page sends signed-in clients from sign-in and sign-up to their back page', function (string $page, string $query, string $location): void {
    $app = appClientWithRender(
        static fn (): never => throw new RuntimeException('The page should not be rendered.'),
        clientLoggedIn: true,
        // ClientFence: the checkout's ?back= (an unconfirmed client may open /cart, not / or the sign-in page).
        request: Request::create('http://localhost/' . $page . $query),
    );

    $response = $app->get_custom_page($page);

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toBe($location);
})->with([
    'sign-in' => ['login', '', '/'],
    'sign-in back to the cart' => ['login', '?back=/cart', '/cart'],
    'sign-up back to the cart' => ['signup', '?back=/cart', '/cart'],
    'another site' => ['signup', '?back=//evil.example/cart', '/'],
    'a full address' => ['login', '?back=/x?u=https://evil.example', '/'],
]);

test('get_custom_page returns 500 (not 404) when the template throws a RuntimeError (regression for #3818)', function (): void {
    $app = appClientWithRender(function (string $fileName): string {
        if ($fileName === 'error') {
            return 'error body';
        }

        throw new Twig\Error\RuntimeError('Variable "company" does not exist.');
    });

    $response = $app->get_custom_page('signup');

    expect($response->getStatusCode())->toBe(500);
});

test('get_custom_page returns 500 (not 404) when the template throws a SyntaxError', function (): void {
    $app = appClientWithRender(function (string $fileName): string {
        if ($fileName === 'error') {
            return 'error body';
        }

        throw new Twig\Error\SyntaxError('Unexpected token.');
    });

    $response = $app->get_custom_page('signup');

    expect($response->getStatusCode())->toBe(500);
});

test('get_custom_page returns 500 (not 404) when a nested template is missing during render', function (): void {
    $app = appClientWithRender(function (string $fileName): string {
        if ($fileName === 'error') {
            return 'error body';
        }

        throw new Twig\Error\LoaderError('Template "missing-partial.html.twig" is not defined.');
    });

    $response = $app->get_custom_page('signup');

    expect($response->getStatusCode())->toBe(500);
});

test('get_custom_page still returns 404 when the top-level template is missing', function (): void {
    $app = appClientWithRender(function (string $fileName): string {
        if ($fileName === 'error') {
            return 'error body';
        }

        throw new ClientFence\InformationException('Page not found', null, 404);
    });

    $response = $app->get_custom_page('signup');

    expect($response->getStatusCode())->toBe(404);
});

test('render() converts a Twig cache write failure into a report:false exception', function (string $message): void {
    $app = new class extends \ClientFence\AppClient {
        public function triggerCacheWriteFailure(RuntimeException $e): never
        {
            $this->convertCacheWriteFailure($e);
        }
    };
    $di = new Pimple\Container();
    $di['request'] = Request::create('http://localhost/test');
    $di['logger'] = new class {
        public function setChannel(string $channel): self
        {
            return $this;
        }

        public function error(string|Stringable $message, array $context = []): void
        {
        }
    };
    $app->setDi($di);

    try {
        $app->triggerCacheWriteFailure(new RuntimeException($message));
        expect(false)->toBeTrue('Expected a ClientFence\Exception to be thrown.');
    } catch (ClientFence\Exception $e) {
        expect($e->getCode())->toBe(5002)
            ->and(ClientFence\ErrorPage::getCodeInfo($e->getCode())['report'])->toBeFalse();
    }
})->with([
    'directory does not exist and cannot be created' => ['Unable to create the cache directory (/var/www/data/cache/7d).'],
    'directory exists but is not writable' => ['Unable to write in the cache directory (/var/www/data/cache/7d).'],
    'cache file itself could not be written' => ['Failed to write cache file "/var/www/data/cache/7d/abc123.php".'],
]);

test('render() rethrows a RuntimeException unrelated to the Twig cache unchanged', function (): void {
    $app = new class extends \ClientFence\AppClient {
        public function triggerCacheWriteFailure(RuntimeException $e): never
        {
            $this->convertCacheWriteFailure($e);
        }
    };
    $di = new Pimple\Container();
    $di['request'] = Request::create('http://localhost/test');
    $app->setDi($di);

    expect(fn () => $app->triggerCacheWriteFailure(new RuntimeException('Something else entirely.')))
        ->toThrow(RuntimeException::class, 'Something else entirely.');
});

test('setUrl normalizes null and empty string to /', function (): void {
    $app = new \ClientFence\AppClient();
    $url = new ReflectionProperty(\ClientFence\App::class, 'url');

    $app->setUrl(null);
    expect($url->getValue($app))->toBe('/');

    $app->setUrl('');
    expect($url->getValue($app))->toBe('/');

    $app->setUrl('//.env');
    expect($url->getValue($app))->toBe('//.env');
});

test('numeric custom page paths return a themed 404', function (): void {
    $app = appClientWithRender(static function (string $fileName): string {
        if ($fileName === 'error') {
            return 'error body';
        }

        throw new ClientFence\InformationException('Page not found', null, 404);
    });
    $app->setUrl('/12345');

    $response = $app->run();

    expect($response->getStatusCode())->toBe(404)
        ->and($response->getContent())->toBe('error body');
});

/**
 * A module with client pages, for the ClientFence check that only an active extension registers them.
 */
function appClientModule(bool $registers): ClientFence\Module
{
    $module = Mockery::mock(ClientFence\Module::class);
    $module->shouldReceive('getConfig')->andReturn([]);
    $module->shouldReceive('hasClientController')->andReturn(true);
    $module->shouldReceive('registerClientRoutes')->times($registers ? 1 : 0)->andReturn(true);

    return $module;
}

test('a switched-off extension registers no client pages, so its URLs answer a themed 404', function (string $url): void {
    $app = appClientWithRender(static function (string $fileName): string {
        if ($fileName === 'error') {
            return 'error body';
        }

        throw new ClientFence\InformationException('Page not found', null, 404);
    }, module: appClientModule(registers: false));
    $app->setUrl($url);

    $response = $app->run();

    expect($response->getStatusCode())->toBe(404)
        ->and($response->getContent())->toBe('error body');
})->with(['index' => '/news', 'item' => '/news/a-post']);

test('an active extension registers its client pages', function (): void {
    $app = appClientWithRender(static function (string $fileName): string {
        if ($fileName === 'error') {
            return 'error body';
        }

        throw new ClientFence\InformationException('Page not found', null, 404);
    }, activeExtensions: ['news'], module: appClientModule(registers: true));
    $app->setUrl('/news');

    expect($app->run()->getStatusCode())->toBe(404);
});

test('top-level client pages are registered by the module that owns them (ClientFence)', function (string $url, string $owner): void {
    $app = appClientWithRender(static function (string $fileName): string {
        if ($fileName === 'error') {
            return 'error body';
        }

        throw new ClientFence\InformationException('Page not found', null, 404);
    });
    $module = Mockery::mock(ClientFence\Module::class);
    $module->shouldReceive('getConfig')->andReturn([]);
    $module->shouldReceive('hasClientController')->andReturn(false);
    $registered = [];
    $di = (fn () => $this->di)->call($app);
    $di['mod'] = $di->protect(function (string $name) use ($module, &$registered): object {
        $registered[] = $name;

        return $module;
    });
    $module->shouldReceive('registerClientRoutes')->once()->andReturn(true);
    $app->setUrl($url);

    $app->run();

    expect($registered)->toContain($owner);
})->with([
    'payment receipt' => ['/receipt/' . str_repeat('a', 32), 'invoice'],
    'ticket file' => ['/ticket-file/' . str_repeat('a', 32), 'support'],
    'users' => ['/users', 'client'],
    'two-factor code' => ['/two-factor/code', 'client'],
    'hosting account' => ['/hosting/12', 'servicehosting'],
]);
