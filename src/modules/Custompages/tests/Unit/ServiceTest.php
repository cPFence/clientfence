<?php

declare(strict_types=1);

use ClientFence\Mod\Custompages\Entity\CustomPage;
use ClientFence\Mod\Custompages\Service;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use ClientFence\Pagination;
use ClientFence\PaginationOptions;

function buildCustompagesService(object $repo, ?EntityManagerInterface $em = null, ?Pimple\Container $extra = null): Service
{
    $di = new Pimple\Container();

    $em ??= Mockery::mock(EntityManagerInterface::class);
    $em->allows('getRepository')->with(CustomPage::class)->andReturn($repo);

    $di['em'] = $em;
    // Logger is a \ClientFence\Log in production (untyped __call dispatch); mock loosely.
    $di['logger'] = Mockery::mock()->shouldIgnoreMissing();
    $di['tools'] = Mockery::mock(ClientFence\Tools::class);
    $di['tools']->allows('slug')->andReturnUsing(fn ($s): string => strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', (string) $s)));

    if ($extra !== null) {
        foreach ($extra->keys() as $key) {
            $di[$key] = $extra[$key];
        }
    }

    $service = new Service();
    $service->setDi($di);

    return $service;
}

test('search pages delegates to repository query builder and doctrine paginator', function (): void {
    $qb = Mockery::mock(QueryBuilder::class);
    $repo = Mockery::mock(ClientFence\Mod\Custompages\Repository\CustomPageRepository::class);
    $repo->expects('getSearchQueryBuilder')->with(['search' => 'landing'])->andReturn($qb);

    $pager = Mockery::mock(Pagination::class);
    $pager->expects('paginateDoctrineQuery')->with($qb, Mockery::on(fn ($o): bool => $o instanceof PaginationOptions))->andReturn(['list' => [], 'total' => 0]);

    $extra = new Pimple\Container();
    $extra['pager'] = $pager;

    $service = buildCustompagesService($repo, extra: $extra);

    expect($service->searchPages(['search' => 'landing']))->toBe(['list' => [], 'total' => 0]);
});

test('get page by id returns api array when found', function (): void {
    $page = Tests\Helpers\createEntity(CustomPage::class, [
        'id' => 7,
        'title' => 'About',
        'description' => 'desc',
        'keywords' => 'kw',
        'content' => 'body',
        'slug' => 'about',
    ]);

    $repo = Mockery::mock(ClientFence\Mod\Custompages\Repository\CustomPageRepository::class);
    $repo->expects('find')->with(7)->andReturn($page);

    $service = buildCustompagesService($repo);

    expect($service->getPage(7))->toBe([
        'id' => 7,
        'title' => 'About',
        'description' => 'desc',
        'keywords' => 'kw',
        'content' => 'body',
        'slug' => 'about',
        'status' => 'published',
        'visibility' => 'everyone',
        'show_in_menu' => false,
        'show_in_footer' => false,
        'sort_order' => 0,
        'created_at' => null,
        'updated_at' => null,
    ]);
});

test('get page by slug uses slug finder', function (): void {
    $page = Tests\Helpers\createEntity(CustomPage::class, ['id' => 3, 'title' => 'T', 'description' => '', 'keywords' => '', 'content' => 'C', 'slug' => 'docs']);

    $repo = Mockery::mock(ClientFence\Mod\Custompages\Repository\CustomPageRepository::class);
    $repo->expects('findOneBySlug')->with('docs')->andReturn($page);

    $service = buildCustompagesService($repo);

    expect($service->getPage('docs', 'slug'))->toBe([
        'id' => 3,
        'title' => 'T',
        'description' => '',
        'keywords' => '',
        'content' => 'C',
        'slug' => 'docs',
        'status' => 'published',
        'visibility' => 'everyone',
        'show_in_menu' => false,
        'show_in_footer' => false,
        'sort_order' => 0,
        'created_at' => null,
        'updated_at' => null,
    ]);
});

test('get page returns null when not found', function (): void {
    $repo = Mockery::mock(ClientFence\Mod\Custompages\Repository\CustomPageRepository::class);
    $repo->expects('find')->with(99)->andReturn(null);

    $service = buildCustompagesService($repo);

    expect($service->getPage(99))->toBeNull();
});

test('get page rejects unknown column type', function (): void {
    $repo = Mockery::mock(ClientFence\Mod\Custompages\Repository\CustomPageRepository::class);

    $service = buildCustompagesService($repo);

    expect(fn (): ?array => $service->getPage(1, 'title'))->toThrow(ClientFence\Exception::class);
});

test('create page generates unique slug and persists entity', function (): void {
    $repo = Mockery::mock(ClientFence\Mod\Custompages\Repository\CustomPageRepository::class);
    $repo->expects('findOneBySlugExcludingId')->with('about-us', 0)->andReturn(null);

    $captured = null;
    $em = Mockery::mock(EntityManagerInterface::class);
    $em->allows('getRepository')->with(CustomPage::class)->andReturn($repo);
    $em->expects('persist')->once()->andReturnUsing(function (CustomPage $page) use (&$captured): void {
        $captured = $page;
    });
    $em->expects('flush')->once()->andReturnUsing(function () use (&$captured): void {
        Tests\Helpers\accessPrivate($captured, 'id', 42);
    });

    $logger = Mockery::mock();
    $logger->expects('info')->with('Created new custom page #%s', 42)->once();

    $di = new Pimple\Container();
    $di['em'] = $em;
    $di['logger'] = $logger;
    $di['tools'] = Mockery::mock(ClientFence\Tools::class);
    $di['tools'] = new ClientFence\Tools();

    $service = new Service();
    $service->setDi($di);

    $id = $service->createPage(['title' => 'About Us', 'content' => 'page body']);

    expect($id)->toBe(42);
    expect($captured)->toBeInstanceOf(CustomPage::class);
    expect($captured->getTitle())->toBe('About Us');
    expect($captured->getDescription())->toBe('');
    expect($captured->getKeywords())->toBe('');
    expect($captured->getContent())->toBe('page body');
    expect($captured->getSlug())->toBe('about-us');
    expect($captured->getStatus())->toBe('published');
    expect($captured->getVisibility())->toBe('everyone');
    expect($captured->isShownInMenu())->toBeFalse();
    expect($captured->isShownInFooter())->toBeFalse();
    expect($captured->getSortOrder())->toBe(0);
});

test('create page appends incrementing suffix until slug is unique', function (): void {
    $existing = Tests\Helpers\createEntity(CustomPage::class, ['id' => 1, 'slug' => 'page']);

    $repo = Mockery::mock(ClientFence\Mod\Custompages\Repository\CustomPageRepository::class);
    $repo->expects('findOneBySlugExcludingId')->with('page', 0)->andReturn($existing);
    $repo->expects('findOneBySlugExcludingId')->with('page-1', 0)->andReturn($existing);
    $repo->expects('findOneBySlugExcludingId')->with('page-2', 0)->andReturn(null);

    $captured = null;
    $em = Mockery::mock(EntityManagerInterface::class);
    $em->allows('getRepository')->with(CustomPage::class)->andReturn($repo);
    $em->expects('persist')->once()->andReturnUsing(function (CustomPage $page) use (&$captured): void {
        $captured = $page;
    });
    $em->allows('flush');

    $di = new Pimple\Container();
    $di['em'] = $em;
    $di['logger'] = Mockery::mock()->shouldIgnoreMissing();
    $di['tools'] = Mockery::mock(ClientFence\Tools::class);
    $di['tools'] = new ClientFence\Tools();

    $service = new Service();
    $service->setDi($di);

    $service->createPage(['title' => 'Page', 'content' => 'content']);

    expect($captured)->toBeInstanceOf(CustomPage::class);
    expect($captured->getSlug())->toBe('page-2');
});

test('update page throws when page not found', function (): void {
    $repo = Mockery::mock(ClientFence\Mod\Custompages\Repository\CustomPageRepository::class);
    $repo->expects('find')->with(5)->andReturn(null);

    $service = buildCustompagesService($repo);

    expect(fn (): int => $service->updatePage(5, ['title' => 't', 'content' => 'c', 'slug' => 'slug']))->toThrow(ClientFence\Exception::class, 'Custom page not found');
});

test('update page throws on duplicate slug with legacy code', function (): void {
    $page = Tests\Helpers\createEntity(CustomPage::class, ['id' => 5, 'title' => 'T', 'description' => '', 'keywords' => '', 'content' => 'C', 'slug' => 'old']);
    $existing = Tests\Helpers\createEntity(CustomPage::class, ['id' => 9, 'slug' => 'taken']);

    $repo = Mockery::mock(ClientFence\Mod\Custompages\Repository\CustomPageRepository::class);
    $repo->expects('find')->with(5)->andReturn($page);
    $repo->expects('findOneBySlugExcludingId')->with('taken', 5)->andReturn($existing);

    $service = buildCustompagesService($repo);

    expect(fn (): int => $service->updatePage(5, ['title' => 'T', 'content' => 'C', 'slug' => 'taken']))
        ->toThrow(fn (ClientFence\Exception $e): bool => $e->getCode() === 9999);
});

test('update page applies setters and returns id', function (): void {
    $page = Tests\Helpers\createEntity(CustomPage::class, ['id' => 5, 'title' => 'Old', 'description' => '', 'keywords' => '', 'content' => 'old', 'slug' => 'old']);

    $repo = Mockery::mock(ClientFence\Mod\Custompages\Repository\CustomPageRepository::class);
    $repo->expects('find')->with(5)->andReturn($page);
    $repo->expects('findOneBySlugExcludingId')->with('new', 5)->andReturn(null);

    $em = Mockery::mock(EntityManagerInterface::class);
    $em->allows('getRepository')->with(CustomPage::class)->andReturn($repo);
    $em->expects('persist')->once();
    $em->expects('flush')->once();

    $di = new Pimple\Container();
    $di['em'] = $em;
    $di['logger'] = Mockery::mock()->shouldIgnoreMissing();
    $di['tools'] = new ClientFence\Tools();

    $service = new Service();
    $service->setDi($di);

    $id = $service->updatePage(5, [
        'title' => 'New Title', 'description' => 'd', 'keywords' => 'k', 'content' => 'new content', 'slug' => 'New',
        'status' => 'draft', 'visibility' => 'active', 'show_in_menu' => '1', 'show_in_footer' => 'true', 'sort_order' => '3',
    ]);

    expect($id)->toBe(5);
    expect($page->getTitle())->toBe('New Title');
    expect($page->getDescription())->toBe('d');
    expect($page->getKeywords())->toBe('k');
    expect($page->getContent())->toBe('new content');
    expect($page->getSlug())->toBe('new');
    expect($page->getStatus())->toBe('draft');
    expect($page->getVisibility())->toBe('active');
    expect($page->isShownInMenu())->toBeTrue();
    expect($page->isShownInFooter())->toBeTrue();
    expect($page->getSortOrder())->toBe(3);
});

test('delete page by scalar removes the entity', function (): void {
    $page = Tests\Helpers\createEntity(CustomPage::class, ['id' => 3, 'title' => 'T', 'description' => '', 'keywords' => '', 'content' => 'C', 'slug' => 's']);

    $repo = Mockery::mock(ClientFence\Mod\Custompages\Repository\CustomPageRepository::class);
    $repo->expects('find')->with(3)->andReturn($page);

    $em = Mockery::mock(EntityManagerInterface::class);
    $em->allows('getRepository')->with(CustomPage::class)->andReturn($repo);
    $em->expects('remove')->with($page)->once();
    $em->expects('flush')->once();

    $di = new Pimple\Container();
    $di['em'] = $em;
    $di['logger'] = Mockery::mock()->shouldIgnoreMissing();
    $di['tools'] = Mockery::mock(ClientFence\Tools::class);

    $service = new Service();
    $service->setDi($di);

    $service->deletePage(3);
});

test('delete page by scalar is a no-op when not found', function (): void {
    $repo = Mockery::mock(ClientFence\Mod\Custompages\Repository\CustomPageRepository::class);
    $repo->expects('find')->with(404)->andReturn(null);

    $em = Mockery::mock(EntityManagerInterface::class);
    $em->allows('getRepository')->with(CustomPage::class)->andReturn($repo);
    $em->expects('remove')->never();
    $em->expects('flush')->never();

    $di = new Pimple\Container();
    $di['em'] = $em;
    $di['logger'] = Mockery::mock()->shouldIgnoreMissing();
    $di['tools'] = Mockery::mock(ClientFence\Tools::class);

    $service = new Service();
    $service->setDi($di);

    $service->deletePage(404);
});

test('delete page by array delegates to bulk delete', function (): void {
    $repo = Mockery::mock(ClientFence\Mod\Custompages\Repository\CustomPageRepository::class);
    $repo->expects('deleteByIds')->with([1, 2, 3])->andReturn(3);

    $service = buildCustompagesService($repo);

    $service->deletePage(['1', '2', '3']);
});

test('create page reports a concurrent slug conflict instead of retrying on a closed entity manager', function (): void {
    // Doctrine closes the entity manager when a flush fails, so the old retry loop failed with "EntityManager is closed".
    $repo = Mockery::mock(ClientFence\Mod\Custompages\Repository\CustomPageRepository::class);
    $repo->shouldReceive('findOneBySlugExcludingId')->andReturn(null);

    $em = Mockery::mock(EntityManagerInterface::class);
    $em->allows('getRepository')->with(CustomPage::class)->andReturn($repo);
    $em->expects('persist')->once();
    $em->expects('flush')->once()->andThrow(Mockery::mock(UniqueConstraintViolationException::class));

    $service = buildCustompagesService($repo, $em);

    expect(fn (): int => $service->createPage(['title' => 'Title', 'content' => 'content']))
        ->toThrow(fn (ClientFence\Exception $e): bool => $e->getCode() === 9999);
});

test('update page surfaces a concurrent constraint violation as the uniqueness error', function (): void {
    $page = Tests\Helpers\createEntity(CustomPage::class, ['id' => 5, 'title' => 'Old', 'description' => '', 'keywords' => '', 'content' => 'old', 'slug' => 'old']);

    $repo = Mockery::mock(ClientFence\Mod\Custompages\Repository\CustomPageRepository::class);
    $repo->expects('find')->with(5)->andReturn($page);
    // App-level check passes (no conflict visible yet), but the DB rejects the concurrent slug.
    $repo->expects('findOneBySlugExcludingId')->with('new', 5)->andReturn(null);

    $em = Mockery::mock(EntityManagerInterface::class);
    $em->allows('getRepository')->with(CustomPage::class)->andReturn($repo);
    $em->expects('persist')->once();
    $em->expects('flush')->once()->andThrow(Mockery::mock(UniqueConstraintViolationException::class));

    $service = buildCustompagesService($repo, $em);

    expect(fn (): int => $service->updatePage(5, ['title' => 'New', 'content' => 'content', 'slug' => 'new']))
        ->toThrow(fn (ClientFence\Exception $e): bool => $e->getCode() === 9999);
});

test('create page reserves room for the suffix when truncating a conflicting long slug', function (): void {
    // A 254-char slug that already exists; the suffixed candidate must truncate the base
    // to leave room for "-1", yielding a 255-char slug (253 a's + "-1").
    $base = str_repeat('a', 254);
    $existing = Tests\Helpers\createEntity(CustomPage::class, ['id' => 1, 'slug' => $base]);

    $repo = Mockery::mock(ClientFence\Mod\Custompages\Repository\CustomPageRepository::class);
    $repo->shouldReceive('findOneBySlugExcludingId')->andReturn($existing, null);

    $captured = null;
    $em = Mockery::mock(EntityManagerInterface::class);
    $em->allows('getRepository')->with(CustomPage::class)->andReturn($repo);
    $em->expects('persist')->once()->andReturnUsing(function (CustomPage $page) use (&$captured): void {
        $captured = $page;
    });
    $em->expects('flush')->once();

    $di = new Pimple\Container();
    $di['em'] = $em;
    $di['logger'] = Mockery::mock()->shouldIgnoreMissing();
    $di['tools'] = new ClientFence\Tools();

    $service = new Service();
    $service->setDi($di);

    $service->createPage(['title' => $base, 'content' => 'content']);

    expect($captured->getSlug())->toBe(str_repeat('a', 253) . '-1');
    expect(strlen($captured->getSlug()))->toBe(255);
});

test('a title without a-z letters or digits gets a slug instead of an empty one', function (): void {
    // tools->slug('صفحة') is '', which saved the page with no address.
    $repo = Mockery::mock(ClientFence\Mod\Custompages\Repository\CustomPageRepository::class);
    $repo->expects('findOneBySlugExcludingId')->with('page', 0)->andReturn(null);

    $captured = null;
    $em = Mockery::mock(EntityManagerInterface::class);
    $em->allows('getRepository')->with(CustomPage::class)->andReturn($repo);
    $em->expects('persist')->once()->andReturnUsing(function (CustomPage $page) use (&$captured): void {
        $captured = $page;
    });
    $em->expects('flush')->once();

    $service = buildCustompagesService($repo, $em, new Pimple\Container(['tools' => new ClientFence\Tools()]));

    $service->createPage(['title' => 'صفحة', 'content' => 'content']);

    expect($captured->getSlug())->toBe('page');
});

test('input the columns cannot hold is refused before the database fails on it', function (array $data): void {
    $repo = Mockery::mock(ClientFence\Mod\Custompages\Repository\CustomPageRepository::class);
    $repo->shouldReceive('findOneBySlugExcludingId')->andReturn(null);
    $service = buildCustompagesService($repo, extra: new Pimple\Container(['tools' => new ClientFence\Tools()]));

    expect(fn (): int => $service->createPage($data + ['title' => 'T', 'content' => 'C']))->toThrow(ClientFence\InformationException::class);
})->with([
    'title' => [['title' => str_repeat('a', 256)]],
    'slug' => [['slug' => str_repeat('a', 256)]],
    'description' => [['description' => str_repeat('a', 556)]],
    'status' => [['status' => 'hidden']],
    'visibility' => [['visibility' => 'staff']],
]);

test('the table is made in utf8mb4 with the page settings columns', function (): void {
    $sql = '';
    $db = Mockery::mock();
    $db->expects('exec')->andReturnUsing(function (string $query) use (&$sql): void {
        $sql = $query;
    });
    $service = buildCustompagesService(Mockery::mock(ClientFence\Mod\Custompages\Repository\CustomPageRepository::class), extra: new Pimple\Container(['db' => $db]));

    $service->install();

    // utf8 (3-byte) refused an emoji in a page.
    expect($sql)->toContain('CHARSET=utf8mb4')
        ->toContain("`status` varchar(16) NOT NULL DEFAULT 'published'")
        ->toContain("`visibility` varchar(16) NOT NULL DEFAULT 'everyone'")
        ->toContain('`show_in_menu` tinyint(1) NOT NULL DEFAULT 0')
        ->toContain('`show_in_footer` tinyint(1) NOT NULL DEFAULT 0')
        ->toContain('`sort_order` int(11) NOT NULL DEFAULT 0');
});

test('a visitor may open Everyone pages, a client also Signed-in pages, and a client with an active order every page', function (): void {
    $dbal = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $dbal->executeStatement('CREATE TABLE client_order (id INTEGER PRIMARY KEY, client_id INTEGER, status TEXT)');
    $dbal->executeStatement("INSERT INTO client_order (client_id, status) VALUES (1, 'active'), (2, 'suspended')");
    $service = buildCustompagesService(Mockery::mock(ClientFence\Mod\Custompages\Repository\CustomPageRepository::class), extra: new Pimple\Container(['dbal' => $dbal]));

    expect($service->visibilitiesFor(null))->toBe(['everyone'])
        ->and($service->visibilitiesFor(2))->toBe(['everyone', 'clients'])
        ->and($service->visibilitiesFor(1))->toBe(['everyone', 'clients', 'active']);
});
