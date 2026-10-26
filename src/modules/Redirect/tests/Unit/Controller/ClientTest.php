<?php

declare(strict_types=1);

/**
 * ClientFence: the Redirect module's short addresses: /blog only while News is on, /me and /balance to
 * the profile and wallet pages, and every alias names a controller method that exists.
 */
function redirectClientRoutes(bool $newsActive, bool $targets = false): array
{
    $app = new class extends \ClientFence\AppClient {
        /** @var list<string> */
        public array $urls = [];

        /** @var array<string, string> url => controller class::method */
        public array $targets = [];

        public function get(string $url, string $methodName, ?array $conditions = [], ?string $class = null): void
        {
            $this->urls[] = $url;
            $this->targets[$url] = ltrim((string) $class, '\\') . '::' . $methodName;
        }
    };
    $extensionService = Mockery::mock();
    $extensionService->shouldReceive('isExtensionActive')->with('mod', 'news')->andReturn($newsActive);
    $redirectService = Mockery::mock(ClientFence\Mod\Redirect\Service::class);
    $redirectService->shouldReceive('getRedirects')->andReturn([['path' => 'old-page', 'enabled' => true], ['path' => 'switched-off', 'enabled' => false]]);

    $di = new Pimple\Container();
    $di['mod_service'] = $di->protect(static fn (string $mod): object => $mod === 'extension' ? $extensionService : $redirectService);

    $controller = new ClientFence\Mod\Redirect\Controller\Client();
    $controller->setDi($di);
    $controller->register($app);

    return $targets ? $app->targets : $app->urls;
}

test('the /blog aliases are not registered while the News extension is off', function (): void {
    $urls = redirectClientRoutes(newsActive: false);

    expect($urls)->not->toContain('/blog')
        ->and($urls)->not->toContain('/blog/:slug')
        ->and($urls)->toContain('/contact-us')
        ->and($urls)->toContain('/old-page');
});

test('a switched-off redirect is not routed', function (): void {
    expect(redirectClientRoutes(newsActive: false))->not->toContain('/switched-off');
});

test('a redirect answers with its type, and a target without a scheme is a client-area link', function (string $target, int $type, string $location): void {
    $service = Mockery::mock(ClientFence\Mod\Redirect\Service::class);
    $service->shouldReceive('getRedirectByPath')->with('docs/old')->andReturn(['target' => $target, 'type' => $type, 'enabled' => true]);
    $url = new ClientFence\Url();
    $url->setBaseUri('https://portal.example/billing/');
    $di = new Pimple\Container();
    $di['mod_service'] = $di->protect(static fn (): object => $service);
    $di['url'] = $url;
    $controller = new ClientFence\Mod\Redirect\Controller\Client();
    $controller->setDi($di);
    $app = new ClientFence\App();
    $app->uri = 'docs/old';

    $response = $controller->do_redirect($app);

    expect($response->getStatusCode())->toBe($type)
        ->and($response->headers->get('Location'))->toBe($location);
})->with([
    ['new-page', 301, 'https://portal.example/billing/new-page'],
    ['/new-page?x=1', 302, 'https://portal.example/billing/new-page?x=1'],
    ['https://example.com/a', 302, 'https://example.com/a'],
]);

test('the /blog aliases are registered while the News extension is on', function (): void {
    expect(redirectClientRoutes(newsActive: true))->toContain('/blog')->toContain('/blog/:slug');
});

test('/me and /balance lead to the profile and wallet pages (ClientFence)', function (): void {
    $targets = redirectClientRoutes(newsActive: false, targets: true);
    $controller = new ClientFence\Mod\Redirect\Controller\Client();
    $app = Mockery::mock(\ClientFence\App::class);
    $app->shouldReceive('permanentRedirect')->with('client/profile')->once()->andReturn(new Symfony\Component\HttpFoundation\RedirectResponse('/client/profile', 301));
    $app->shouldReceive('permanentRedirect')->with('client/balance')->once()->andReturn(new Symfony\Component\HttpFoundation\RedirectResponse('/client/balance', 301));

    expect($targets['/me'])->toBe(ClientFence\Mod\Redirect\Controller\Client::class . '::get_profile')
        ->and($targets['/balance'])->toBe(ClientFence\Mod\Redirect\Controller\Client::class . '::get_balance')
        ->and($controller->get_profile($app)->getStatusCode())->toBe(301)
        ->and($controller->get_balance($app)->getStatusCode())->toBe(301);

    // Every other alias names a controller method that exists.
    foreach ($targets as $url => $target) {
        [$class, $method] = explode('::', $target);
        expect(method_exists($class, $method))->toBeTrue("{$url} → {$target}");
    }
});
