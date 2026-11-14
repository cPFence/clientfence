<?php

declare(strict_types=1);

/**
 * ClientFence: the migration runner (Mod\Clientfence\Migrations) on an in-memory SQLite `setting`
 * table, and how the list fits a fresh install.
 */

use ClientFence\Mod\Clientfence\Migrations;
use Symfony\Component\Filesystem\Path;

function migrationsPdo(?string $level = null): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE setting (id INTEGER PRIMARY KEY, param TEXT, value TEXT, public INTEGER, category TEXT, hash TEXT, created_at TEXT, updated_at TEXT)');
    if ($level !== null) {
        $pdo->prepare("INSERT INTO setting (param, value, public, created_at, updated_at) VALUES (?, ?, 0, '2026-10-01', '2026-10-01')")->execute([Migrations::SETTING, $level]);
    }

    return $pdo;
}

test('pending migrations run in order and the level is recorded after each', function (): void {
    $pdo = migrationsPdo();
    $log = [];
    $steps = [3 => function () use (&$log): void { $log[] = 3; }, 1 => function () use (&$log): void { $log[] = 1; }, 2 => function () use (&$log): void { $log[] = 2; }];

    expect(Migrations::current($pdo))->toBe(0)
        ->and(Migrations::run($pdo, $steps))->toBe([1, 2, 3])
        ->and($log)->toBe([1, 2, 3])
        ->and(Migrations::current($pdo))->toBe(3)
        ->and((int) $pdo->query("SELECT COUNT(*) FROM setting WHERE param = 'clientfence_patch'")->fetchColumn())->toBe(1)
        // A second run has nothing left to do.
        ->and(Migrations::run($pdo, $steps))->toBe([])
        ->and($log)->toBe([1, 2, 3]);
});

test('only the migrations above the recorded level run', function (): void {
    $pdo = migrationsPdo('2');
    $log = [];
    $steps = [1 => function () use (&$log): void { $log[] = 1; }, 2 => function () use (&$log): void { $log[] = 2; }, 3 => function () use (&$log): void { $log[] = 3; }];

    expect(Migrations::run($pdo, $steps))->toBe([3])
        ->and($log)->toBe([3])
        ->and(Migrations::current($pdo))->toBe(3);
});

test('a failed migration stops the run at the last one that finished', function (): void {
    $pdo = migrationsPdo();
    $steps = [1 => function (): void {}, 2 => function (): void { throw new RuntimeException('disk full'); }, 3 => function (): void {}];

    expect(fn () => Migrations::run($pdo, $steps))->toThrow(RuntimeException::class, 'disk full')
        ->and(Migrations::current($pdo))->toBe(1);
});

test('the pending count compares the recorded level with the list', function (): void {
    expect(Migrations::pending(migrationsPdo()))->toBe(Migrations::latest())
        ->and(Migrations::pending(migrationsPdo((string) Migrations::latest())))->toBe(0);
});

test('migrations are numbered from 1 without gaps, and each one exists', function (): void {
    $list = (new ReflectionClassConstant(Migrations::class, 'LIST'))->getValue();

    expect(array_keys($list))->toBe(range(1, count($list)))
        ->and(Migrations::latest())->toBe(count($list));
    foreach ($list as $method) {
        expect(method_exists(Migrations::class, $method))->toBeTrue();
    }
});

test('fresh installs start at the latest ClientFence migration', function (): void {
    $content = (string) file_get_contents(Path::join(PATH_ROOT, 'install', 'sql', 'content.sql'));
    preg_match("/'clientfence_patch','(\\d+)'/", $content, $matches);

    expect((int) ($matches[1] ?? 0))->toBe(Migrations::latest());
});

test('every ClientFence table is also created by a fresh install', function (): void {
    $structure = (string) file_get_contents(Path::join(PATH_ROOT, 'install', 'sql', 'structure.sql'));
    $missing = array_filter(array_keys(ClientFence\Mod\Clientfence\Service::tables()), static fn (string $table): bool => !str_contains($structure, "CREATE TABLE `{$table}`") && !str_contains($structure, "CREATE TABLE IF NOT EXISTS `{$table}`"));

    expect(array_values($missing))->toBe([]);
});

test('migration 10 gives every client but the first a new PIN where PINs are shared, then makes PINs unique', function (): void {
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE setting (id INTEGER PRIMARY KEY, param TEXT, value TEXT, public INTEGER, category TEXT, hash TEXT, created_at TEXT, updated_at TEXT)');
    $db->insert('setting', ['param' => Migrations::SETTING, 'value' => '9', 'public' => 0, 'created_at' => '2026-10-01', 'updated_at' => '2026-10-01']);
    $db->executeStatement('CREATE TABLE client (id INTEGER PRIMARY KEY)');
    $db->executeStatement('CREATE TABLE client_support_pin (client_id INTEGER PRIMARY KEY, pin TEXT NOT NULL)');
    foreach ([3 => '11111111', 5 => '22222222', 8 => '11111111', 9 => '11111111', 12 => '22222222', 20 => '33333333'] as $clientId => $pin) {
        $db->insert('client', ['id' => $clientId]);
        $db->insert('client_support_pin', ['client_id' => $clientId, 'pin' => $pin]);
    }
    $di = Tests\Helpers\container();
    $di['dbal'] = $db;
    $di['pdo'] = $db->getNativeConnection();

    expect(Migrations::apply($di))->toBe([10]);
    $pins = $db->fetchAllKeyValue('SELECT client_id, pin FROM client_support_pin ORDER BY client_id');
    expect(array_keys($pins))->toBe([3, 5, 8, 9, 12, 20])
        // The oldest client keeps a shared PIN and a PIN nobody shared is kept.
        ->and([$pins[3], $pins[5], $pins[20]])->toBe(['11111111', '22222222', '33333333'])
        ->and(array_unique($pins))->toHaveCount(6)
        ->and(array_filter($pins, static fn ($pin): bool => preg_match('/^\d{8}$/', (string) $pin) !== 1))->toBe([])
        ->and(fn () => $db->insert('client_support_pin', ['client_id' => 30, 'pin' => '33333333']))->toThrow(Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);

    // Run again (an interrupted update repeats it): the unique key is there, nothing changes.
    (new ReflectionMethod(Migrations::class, 'm10SupportPinUnique'))->invoke(null, $di);
    expect($db->fetchAllKeyValue('SELECT client_id, pin FROM client_support_pin ORDER BY client_id'))->toBe($pins);
});
