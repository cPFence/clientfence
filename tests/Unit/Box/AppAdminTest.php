<?php

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Request;

/**
 * ClientFence: a switched-off extension has no admin pages, so they cannot fail on its disabled API.
 */
function appAdminInit(bool $active): int
{
    $controller = new class {
        public int $registered = 0;

        public function register(\ClientFence\App &$app): void
        {
            ++$this->registered;
        }
    };
    $module = Mockery::mock(ClientFence\Module::class);
    $module->shouldReceive('getAdminController')->andReturn($controller);
    $extensionService = Mockery::mock();
    $extensionService->shouldReceive('isExtensionActive')->with('mod', 'news')->andReturn($active);

    $di = new Pimple\Container();
    $di['request'] = Request::create('http://localhost/admin/news');
    $di['mod'] = $di->protect(static fn (): object => $module);
    $di['mod_service'] = $di->protect(static fn (): object => $extensionService);

    $app = new \ClientFence\AppAdmin();
    $app->setDi($di);
    (new ReflectionProperty(\ClientFence\App::class, 'mod'))->setValue($app, 'news');
    $app->init();

    return $controller->registered;
}

test('admin pages of a switched-off extension are not registered', function (): void {
    expect(appAdminInit(active: false))->toBe(0);
});

test('admin pages of an active extension are registered', function (): void {
    expect(appAdminInit(active: true))->toBe(1);
});

test('an admin page without a template is a 404 that names no server paths', function (): void {
    $app = new class extends \ClientFence\AppAdmin {
        protected function getTwig(): Twig\Environment
        {
            return new Twig\Environment(new Twig\Loader\FilesystemLoader([sys_get_temp_dir()]));
        }
    };
    $logged = [];
    $logger = Mockery::mock();
    $logger->shouldReceive('setChannel')->with('routing')->andReturnSelf();
    $logger->shouldReceive('info')->andReturnUsing(function (string $message) use (&$logged): void {
        $logged[] = $message;
    });
    $di = new Pimple\Container();
    $di['request'] = Request::create('http://localhost/admin/extension/settings/news');
    $di['logger'] = $logger;
    $app->setDi($di);

    try {
        $app->render('mod_news_settings');
        expect(false)->toBeTrue('Expected a 404.');
    } catch (ClientFence\InformationException $e) {
        expect($e->getCode())->toBe(404)
            ->and($e->getMessage())->not->toContain(sys_get_temp_dir())
            ->and($logged)->toHaveCount(1);
    }
});
