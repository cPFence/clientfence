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
