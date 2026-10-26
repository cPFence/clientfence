<?php

declare(strict_types=1);

/**
 * ClientFence: who opens a custom page. Drafts are 404 except in a staff preview; a page a visitor may
 * not see sends the visitor to sign in, and a signed-in client without access gets a 404.
 */

use ClientFence\Mod\Custompages\Entity\CustomPage;
use ClientFence\Security\AuthenticationRequiredException;
use Symfony\Component\HttpFoundation\Request;

function openCustomPage(array $page, array $query = [], bool $staff = false, ?int $clientId = null, array $visibilities = ['everyone']): string
{
    $entity = Tests\Helpers\createEntity(CustomPage::class, $page + ['id' => 1, 'title' => 'Terms', 'slug' => 'terms', 'description' => '', 'keywords' => '', 'content' => '']);
    $repo = Mockery::mock(ClientFence\Mod\Custompages\Repository\CustomPageRepository::class);
    $repo->allows('findOneBySlug')->with('terms')->andReturn($entity);
    $service = Mockery::mock(ClientFence\Mod\Custompages\Service::class);
    $service->allows('getPageRepository')->andReturn($repo);
    $service->allows('viewerClientId')->andReturn($clientId);
    $service->allows('visibilitiesFor')->with($clientId)->andReturn($visibilities);
    $auth = Mockery::mock(ClientFence\Authorization::class);
    $auth->allows('isAdminLoggedIn')->andReturn($staff);

    $di = new Pimple\Container();
    $di['mod_service'] = $di->protect(static fn (): object => $service);
    $di['auth'] = $auth;
    $di['is_client_logged'] = static fn () => throw new AuthenticationRequiredException('client');

    $app = Mockery::mock(ClientFence\App::class);
    $app->allows('getRequest')->andReturn(new Request($query));
    $app->allows('render')->andReturnUsing(static fn (string $template, array $vars): string => $template . ($vars['preview'] ? ' preview' : ''));

    $controller = new ClientFence\Mod\Custompages\Controller\Client();
    $controller->setDi($di);

    return $controller->get_page($app, 'terms');
}

test('a draft is not found, also with a preview link, unless staff open the preview', function (): void {
    expect(fn (): string => openCustomPage(['status' => 'draft']))->toThrow(fn (ClientFence\InformationException $e): bool => $e->getCode() === 404)
        ->and(fn (): string => openCustomPage(['status' => 'draft'], ['preview' => '1'], clientId: 5, visibilities: ['everyone', 'clients']))->toThrow(fn (ClientFence\InformationException $e): bool => $e->getCode() === 404)
        ->and(openCustomPage(['status' => 'draft'], ['preview' => '1'], staff: true))->toBe('mod_custompages_content preview')
        ->and(openCustomPage([]))->toBe('mod_custompages_content');
});

test('a visitor is sent to sign in and a client without access gets a 404', function (): void {
    expect(fn (): string => openCustomPage(['visibility' => 'clients']))->toThrow(AuthenticationRequiredException::class)
        ->and(fn (): string => openCustomPage(['visibility' => 'active'], clientId: 5, visibilities: ['everyone', 'clients']))->toThrow(fn (ClientFence\InformationException $e): bool => $e->getCode() === 404)
        ->and(openCustomPage(['visibility' => 'active'], clientId: 5, visibilities: ['everyone', 'clients', 'active']))->toBe('mod_custompages_content');
});
