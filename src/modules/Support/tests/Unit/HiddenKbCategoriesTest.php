<?php

declare(strict_types=1);

/**
 * ClientFence: knowledge base categories hidden from clients (Support\HiddenKbCategories), on an
 * in-memory SQLite knowledge base with Doctrine, and the guest API that leaves them out.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Support\Entity\KbArticle;
use ClientFence\Mod\Support\Entity\KbArticleCategory;
use ClientFence\Mod\Support\HiddenKbCategories;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Symfony\Component\Filesystem\Path;

/**
 * A knowledge base: category Public (articles Open and Draft) and category Staff (article Secret),
 * with Staff hidden; returns [entity manager, container].
 *
 * @return array{0: EntityManager, 1: Pimple\Container}
 */
function hiddenKbFixture(): array
{
    $config = ORMSetup::createAttributeMetadataConfig([Path::join(__DIR__, '..', '..', 'Entity')], true);
    $config->setProxyDir(sys_get_temp_dir());
    $config->setProxyNamespace('ClientFence\\Tests\\DoctrineProxies');
    $em = new EntityManager(Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
    (new Doctrine\ORM\Tools\SchemaTool($em))->createSchema(array_map($em->getClassMetadata(...), [KbArticleCategory::class, KbArticle::class]));
    $em->getConnection()->executeStatement('CREATE TABLE ' . HiddenKbCategories::TABLE . ' (category_id INTEGER PRIMARY KEY, hidden_at TEXT NOT NULL)');

    $public = (new KbArticleCategory())->setTitle('Public')->setSlug('public');
    $staff = (new KbArticleCategory())->setTitle('Staff')->setSlug('staff');
    $em->persist($public);
    $em->persist($staff);
    foreach ([[$public, 'Open', 'open', KbArticle::ACTIVE], [$public, 'Draft', 'draft', KbArticle::DRAFT], [$staff, 'Secret', 'secret', KbArticle::ACTIVE]] as [$category, $title, $slug, $status]) {
        $em->persist((new KbArticle())->setCategory($category)->setTitle($title)->setSlug($slug)->setStatus($status)->setContent($title . ' text'));
    }
    $em->flush();

    $di = new Pimple\Container();
    $di['em'] = $em;
    HiddenKbCategories::save($di, (int) $staff->getId(), '1');

    return [$em, $di];
}

/** The guest API on the fixture's knowledge base. */
function hiddenKbGuestApi(EntityManager $em, Pimple\Container $di): ClientFence\Mod\Support\Api\Guest
{
    $service = Mockery::mock(ClientFence\Mod\Support\Service::class)->makePartial();
    $service->shouldReceive('kbEnabled')->andReturn(true);
    $service->shouldReceive('kbArticleViewsEnabled')->andReturn(false);
    $service->shouldReceive('getKbArticleRepository')->andReturn($em->getRepository(KbArticle::class));
    $service->shouldReceive('getKbArticleCategoryRepository')->andReturn($em->getRepository(KbArticleCategory::class));
    $di['pager'] = new class {
        public function paginateDoctrineQuery(Doctrine\ORM\QueryBuilder $qb): array
        {
            return ['list' => $qb->getQuery()->getResult()];
        }
    };
    $api = new ClientFence\Mod\Support\Api\Guest();
    $api->setDi($di);
    $api->setService($service);
    $api->setIdentity(new ClientFence\Identity\Guest());

    return $api;
}

test('a category is hidden and shown again from its page, and forgotten when deleted', function (): void {
    [, $di] = hiddenKbFixture();
    expect(HiddenKbCategories::ids($di))->toBe([2])
        ->and(HiddenKbCategories::isHidden($di, 2))->toBeTrue()
        ->and(HiddenKbCategories::isHidden($di, 1))->toBeFalse()
        ->and(HiddenKbCategories::isHidden($di, null))->toBeFalse()
        ->and(HiddenKbCategories::withFlags($di, [['id' => 1], ['id' => 2]]))->toBe([['id' => 1, 'hidden' => false], ['id' => 2, 'hidden' => true]]);

    HiddenKbCategories::save($di, 2, true);
    expect(HiddenKbCategories::ids($di))->toBe([2]);
    HiddenKbCategories::save($di, 2, '0');
    expect(HiddenKbCategories::ids($di))->toBe([]);
    HiddenKbCategories::save($di, 1, 1);
    HiddenKbCategories::forget($di, 1);
    expect(HiddenKbCategories::ids($di))->toBe([]);
});

test('clients and guests do not see a hidden category or its articles', function (): void {
    [$em, $di] = hiddenKbFixture();
    $api = hiddenKbGuestApi($em, $di);

    $titles = static fn (array $list): array => array_map(static fn (object $item): string => $item->getTitle(), $list);
    expect($titles($api->kb_category_get_list([])['list']))->toBe(['Public'])
        ->and($titles($api->kb_category_get_list(['exclude_ids' => []])['list']))->toBe(['Public'])
        ->and($titles($api->kb_article_get_list([])['list']))->toBe(['Open'])
        ->and($titles($api->kb_article_get_list(['search' => 'secret'])['list']))->toBe([])
        ->and($titles($api->kb_article_get_list(['kb_article_category_id' => 2])['list']))->toBe([])
        ->and($api->kb_article_get(['slug' => 'open'])['title'])->toBe('Open')
        ->and($api->kb_category_get(['slug' => 'public'])['title'])->toBe('Public')
        ->and(fn () => $api->kb_article_get(['slug' => 'secret']))->toThrow(InformationException::class, 'Article item not found')
        ->and(fn () => $api->kb_article_get(['id' => 3]))->toThrow(InformationException::class, 'Article item not found')
        ->and(fn () => $api->kb_category_get(['slug' => 'staff']))->toThrow(InformationException::class, 'Knowledge Base category not found')
        ->and(fn () => $api->kb_category_get(['id' => 2]))->toThrow(InformationException::class, 'Knowledge Base category not found');

    // Shown again: everything is back.
    HiddenKbCategories::save($di, 2, false);
    $em->clear();
    expect($titles($api->kb_category_get_list([])['list']))->toBe(['Public', 'Staff'])
        ->and($titles($api->kb_article_get_list([])['list']))->toBe(['Open', 'Secret'])
        ->and($api->kb_article_get(['slug' => 'secret'])['title'])->toBe('Secret');
});

test('staff still see and manage a hidden category', function (): void {
    [$em, $di] = hiddenKbFixture();
    $di['pager'] = new class {
        public function paginateDoctrineQuery(Doctrine\ORM\QueryBuilder $qb): array
        {
            return ['list' => array_map(static fn (KbArticleCategory $c): array => ['id' => $c->getId(), 'title' => $c->getTitle()], $qb->getQuery()->getResult())];
        }
    };
    $service = Mockery::mock(ClientFence\Mod\Support\Service::class)->makePartial();
    $service->shouldReceive('getKbArticleCategoryRepository')->andReturn($em->getRepository(KbArticleCategory::class));
    $service->shouldReceive('kbUpdateCategory')->andReturn(true);
    $service->shouldReceive('kbCategoryRm')->andReturn(true);
    $api = new ClientFence\Mod\Support\Api\Admin();
    $api->setDi($di);
    $api->setService($service);
    $admin = new Model_Admin();
    $admin->loadBean(new Tests\Helpers\DummyBean());
    $api->setIdentity($admin);
    $staff = new class {
        public function checkPermissionsAndThrowException(): void
        {
        }
    };
    $di['mod_service'] = $di->protect(static fn (string $name): object => $staff);

    expect($api->kb_category_get_list([])['list'])->toBe([['id' => 1, 'title' => 'Public', 'hidden' => false], ['id' => 2, 'title' => 'Staff', 'hidden' => true]])
        ->and($api->kb_category_get(['id' => 2])['hidden'])->toBeTrue();
    $api->kb_category_update(['id' => 2, 'hidden' => '0']);
    expect(HiddenKbCategories::ids($di))->toBe([]);
    $api->kb_category_update(['id' => 1, 'hidden' => '1']);
    $api->kb_category_update(['id' => 1, 'title' => 'Public']);
    expect(HiddenKbCategories::ids($di))->toBe([1]);
    $api->kb_category_delete(['id' => 1]);
    expect(HiddenKbCategories::ids($di))->toBe([]);
});
