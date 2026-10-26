<?php

declare(strict_types=1);

use ClientFence\Mod\News\Entity\Post;
use ClientFence\Mod\News\Repository\PostRepository;
use ClientFence\Mod\News\Service;

function newsEntityManager(): Doctrine\ORM\EntityManager
{
    $config = Doctrine\ORM\ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2) . '/Entity'], true);
    $config->setProxyDir(sys_get_temp_dir());
    $config->setProxyNamespace('ClientFence\\Tests\\DoctrineProxies');
    $config->setNamingStrategy(new Doctrine\ORM\Mapping\UnderscoreNamingStrategy(CASE_LOWER));
    $em = new Doctrine\ORM\EntityManager(Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
    (new Doctrine\ORM\Tools\SchemaTool($em))->createSchema([$em->getClassMetadata(Post::class)]);

    return $em;
}

function newsService(): Service
{
    $di = new Pimple\Container();
    $di['em'] = newsEntityManager();
    $di['tools'] = new ClientFence\Tools();
    $service = new Service();
    $service->setDi($di);

    return $service;
}

test('a new post gets a free slug, also from a title without a-z letters or digits', function (): void {
    $service = newsService();
    $slugs = [];
    foreach (['Downtime', 'Downtime', 'تحديث', 'تحديث'] as $title) {
        $post = new Post('', '');
        $service->savePost($post, ['title' => $title]);
        $slugs[] = $post->getSlug();
    }

    expect($slugs)->toBe(['downtime', 'downtime-2', 'post', 'post-2']);
});

test('a slug staff type is made fit for the address, and one in use is refused', function (): void {
    $service = newsService();
    $first = new Post('', '');
    $service->savePost($first, ['title' => 'First', 'slug' => 'My News!']);
    $second = new Post('', '');
    $service->savePost($second, ['title' => 'Second']);

    expect($first->getSlug())->toBe('my-news')
        ->and(fn () => $service->savePost($second, ['slug' => 'My-News']))->toThrow(ClientFence\Exception::class, 'You need to set unique slug.');
});

test('the description made from non-Latin content is cut by characters, not bytes', function (): void {
    $service = newsService();
    $post = new Post('', '');
    $service->savePost($post, ['title' => 'Update', 'content' => str_repeat('ب', 200)]);

    expect($post->getDescription())->toBe(str_repeat('ب', 125))
        ->and(mb_check_encoding((string) $post->getDescription(), 'UTF-8'))->toBeTrue();
});

test('pinning a post or setting its dates keeps the rest of it', function (): void {
    $service = newsService();
    $post = new Post('', '');
    $service->savePost($post, ['title' => 'Maintenance', 'content' => 'Body', 'section' => 'Network', 'publish_at' => '2026-11-01T09:30']);
    $service->savePost($post, ['pinned' => 1, 'expires_at' => '2026-11-08T00:00']);

    expect($post->isPinned())->toBeTrue()
        ->and($post->getTitle())->toBe('Maintenance')
        ->and($post->getSection())->toBe('Network')
        ->and($post->getPublishAt()?->format('Y-m-d H:i'))->toBe('2026-11-01 09:30')
        ->and($post->getExpiresAt()?->format('Y-m-d H:i'))->toBe('2026-11-08 00:00')
        ->and(fn () => $service->savePost($post, ['publish_at' => 'soon']))->toThrow(ClientFence\InformationException::class);
});

test('clients see active posts past their publish date and not expired, pinned first, then newest', function (): void {
    $em = newsEntityManager();
    $add = function (string $slug, array $set) use ($em): void {
        $post = (new Post($slug, $slug))->setStatus($set['status'] ?? Post::STATUS_ACTIVE)->setPinned($set['pinned'] ?? false)
            ->setPublishAt(isset($set['publish']) ? new DateTime($set['publish']) : null)
            ->setExpiresAt(isset($set['expires']) ? new DateTime($set['expires']) : null);
        $post->setCreatedAt(new DateTime($set['created'] ?? '-1 day'));
        $em->persist($post);
    };
    $add('old', ['created' => '-10 days']);
    $add('recent', ['publish' => '-1 hour', 'created' => '-20 days']);
    $add('old-pinned', ['pinned' => true, 'created' => '-30 days']);
    $add('new-pinned', ['pinned' => true, 'created' => '-5 days']);
    $add('draft', ['status' => Post::STATUS_DRAFT]);
    $add('scheduled', ['publish' => '+1 day']);
    $add('expired', ['expires' => '-1 hour']);
    $add('expiring', ['expires' => '+1 day', 'created' => '-15 days']);
    $em->flush();

    /** @var PostRepository $repo */
    $repo = $em->getRepository(Post::class);
    $page = (new Doctrine\ORM\Tools\Pagination\OffsetPaginator(true))->paginate($repo->getVisibleQueryBuilder(['status' => Post::STATUS_DRAFT]), Doctrine\ORM\Tools\Pagination\Window::fromPageNumberAndSize(1, 10));

    expect(array_map(static fn (Post $post): string => $post->getSlug(), iterator_to_array($page->getItems(), false)))
        ->toBe(['new-pinned', 'old-pinned', 'recent', 'old', 'expiring'])
        ->and($repo->findOneVisible('slug', 'recent')?->getSlug())->toBe('recent')
        ->and($repo->findOneVisible('slug', 'scheduled'))->toBeNull()
        ->and($repo->findOneVisible('slug', 'expired'))->toBeNull()
        ->and($repo->findOneVisible('slug', 'draft'))->toBeNull();
});
