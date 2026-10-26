<?php

declare(strict_types=1);

use ClientFence\Mod\Clientfence\Notifications;
use ClientFence\Mod\Extension\Entity\ExtensionMeta;
use ClientFence\Mod\Extension\Repository\ExtensionMetaRepository;
use ClientFence\Mod\Notification\Service;
use Doctrine\DBAL\DriverManager;

function announcementAdmin(int $id, string $name = ''): Model_Admin
{
    $admin = new Model_Admin();
    $admin->loadBean(new Tests\Helpers\DummyBean());
    $admin->id = $id;
    $admin->name = $name;

    return $admin;
}

/**
 * Staff 1 (the poster), 2 and 5 read announcements, 3 may open the module but not read them, 4 is
 * inactive. `$rows` are what the repository lists; `$saved` gets each persisted row.
 */
function announcementService(array $rows = [], ?ExtensionMeta &$saved = null): array
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE admin (id INTEGER PRIMARY KEY, name TEXT, status TEXT, system_name TEXT)');
    $db->executeStatement("INSERT INTO admin VALUES (1, 'Ann', 'active', NULL), (2, 'Bob', 'active', NULL), (3, 'Cy', 'active', NULL), (4, 'Di', 'inactive', NULL), (5, 'Ed', 'active', NULL)");
    $db->executeStatement('CREATE TABLE ' . Notifications::TABLE . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, audience TEXT NOT NULL, recipient_id INTEGER NOT NULL, kind TEXT NOT NULL, params TEXT NOT NULL, url TEXT NOT NULL, dedupe TEXT, created_at TEXT NOT NULL, read_at TEXT, UNIQUE (audience, recipient_id, dedupe))');

    $repository = Mockery::mock(ExtensionMetaRepository::class);
    $repository->shouldReceive('findByExtensionAndScope')->with('mod_notification', Service::KEY, null, null, ['id' => 'DESC'])->andReturn($rows);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $em->shouldReceive('getRepository')->andReturn($repository);
    $em->shouldReceive('persist')->andReturnUsing(function (ExtensionMeta $meta) use (&$saved): void {
        (new ReflectionProperty(ExtensionMeta::class, 'id'))->setValue($meta, 7);
        $saved = $meta;
    });
    $em->shouldReceive('flush');

    $di = new Pimple\Container();
    $di['em'] = $em;
    $di['logger'] = Mockery::mock()->shouldIgnoreMissing();
    $di['events_manager'] = Mockery::mock()->shouldIgnoreMissing();
    $di['db'] = new class {
        public function load(string $type, int $id): Model_Admin
        {
            return announcementAdmin($id, ['', 'Ann', 'Bob', 'Cy', 'Di', 'Ed'][$id]);
        }
    };
    $staff = new class {
        public function hasPermission(Model_Admin $admin, string $module, ?string $key = null): bool
        {
            return $admin->id !== 3 || $key === null;
        }
    };
    $di['mod_service'] = $di->protect(fn () => $staff);

    $service = new Service();
    $service->setDi($di);

    return [$service, $db];
}

function announcementRow(int $id, int $author, ?array $staff, ?string $pinnedUntil): ExtensionMeta
{
    $row = (new ExtensionMeta($id))->setExtension('mod_notification')->setRelType('staff')->setRelId($author)->setMetaKey(Service::KEY)
        ->setMetaValue((string) json_encode(['title' => 'T' . $id, 'message' => "Line 1\nLine 2", 'staff' => $staff, 'pinned_until' => $pinnedUntil]));
    $row->setCreatedAt(new DateTime('2026-10-01 10:00:00'));

    return $row;
}

test('posting records its author and reaches the bell of its audience but not the poster', function (array $data, ?array $staff, array $recipients): void {
    $saved = null;
    [$service, $db] = announcementService([], $saved);

    expect($service->create(announcementAdmin(1), ['title' => 'Office closed', 'message' => "Closed on Friday.\r\nBack Monday.", 'pinned_until' => ''] + $data))->toBe(7)
        ->and($saved->getRelId())->toBe('1')
        ->and(json_decode((string) $saved->getMetaValue(), true))->toBe(['title' => 'Office closed', 'message' => "Closed on Friday.\nBack Monday.", 'staff' => $staff, 'pinned_until' => null])
        ->and(array_map('intval', $db->fetchFirstColumn('SELECT recipient_id FROM ' . Notifications::TABLE . " WHERE kind = 'announcement' AND url = 'notification#announcement-7' ORDER BY recipient_id")))->toBe($recipients);
})->with([
    'all staff' => [['audience' => 'all'], null, [2, 5]],
    'chosen staff' => [['audience' => 'staff', 'staff' => ['1', '5']], [1, 5], [5]],
]);

test('an announcement needs a title, a message, readers as its chosen staff and a pin date from today on', function (array $data): void {
    [$service] = announcementService();

    expect(fn () => $service->create(announcementAdmin(1), $data + ['title' => 'Title', 'message' => 'Message', 'audience' => 'all']))->toThrow(ClientFence\InformationException::class);
})->with([
    'blank title' => [['title' => '  ']],
    'long title' => [['title' => str_repeat('a', 121)]],
    'blank message' => [['message' => " \n "]],
    'no staff chosen' => [['audience' => 'staff']],
    'staff who may not read them' => [['audience' => 'staff', 'staff' => [3]]],
    'past pin date' => [['pinned_until' => date('Y-m-d', strtotime('-1 day'))]],
    'impossible pin date' => [['pinned_until' => '2099-02-30']],
]);

test('staff see the announcements addressed to them, managers see all; the dashboard gets live pins', function (): void {
    $today = date('Y-m-d');
    [$service] = announcementService([
        announcementRow(4, 1, [2], $today),
        announcementRow(3, 1, [5], date('Y-m-d', strtotime('-1 day'))),
        announcementRow(2, 1, [2], null),
        announcementRow(1, 9, null, null),
    ]);

    $list = $service->getList(announcementAdmin(2), false);

    expect(array_column($list, 'id'))->toBe([4, 2, 1])
        ->and($list[0])->toMatchArray(['title' => 'T4', 'staff_names' => ['Bob'], 'pinned_until' => $today, 'pinned' => true, 'author' => 'Ann', 'created_at' => '2026-10-01 10:00:00'])
        ->and($list[2])->toMatchArray(['staff' => null, 'author' => ''])
        ->and(array_column($service->getList(announcementAdmin(2), true), 'pinned', 'id'))->toBe([4 => true, 3 => false, 2 => false, 1 => false])
        ->and(array_column($service->getPinned(announcementAdmin(2)), 'id'))->toBe([4])
        ->and($service->getPinned(announcementAdmin(5)))->toBe([]);
});

test('unpin takes an announcement off the dashboard and keeps the rest', function (): void {
    [$service] = announcementService();
    $row = announcementRow(4, 1, [2], date('Y-m-d'));
    $repository = Mockery::mock(ExtensionMetaRepository::class);
    $repository->shouldReceive('findOneByExtensionAndId')->with('mod_notification', 4)->andReturn($row);
    (new ReflectionProperty(Service::class, 'extensionMetaRepository'))->setValue($service, $repository);

    expect($service->unpin(4))->toBeTrue()
        ->and(json_decode((string) $row->getMetaValue(), true))->toBe(['title' => 'T4', 'message' => "Line 1\nLine 2", 'staff' => [2], 'pinned_until' => null]);
});
