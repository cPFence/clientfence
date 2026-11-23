<?php

declare(strict_types=1);

use ClientFence\Mod\Clientfence\ModuleMigrations;
use ClientFence\Mod\Servicednshosting\Migrations as DnsMigrations;

use function Tests\Helpers\container;

/** A container whose module `$module` has the manifest given, and an in-memory `setting` table. */
function moduleMigrationsDi(string $module, array $manifest): Pimple\Container
{
    $di = container();
    $mod = Mockery::mock(ClientFence\Module::class);
    $mod->shouldReceive('getManifest')->andReturn($manifest);
    $di['mod'] = $di->protect(fn (string $name): object => $name === $module ? $mod : throw new LogicException($name));
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE setting (id INTEGER PRIMARY KEY, param TEXT, value TEXT, public INTEGER, category TEXT, hash TEXT, created_at TEXT, updated_at TEXT)');
    $di['pdo'] = $pdo;

    return $di;
}

test('a module runs its pending database steps once and records its level; DNS hosting makes its table', function (): void {
    $manifest = json_decode((string) file_get_contents(PATH_MODS . '/Servicednshosting/manifest.json'), true);
    $di = moduleMigrationsDi('servicednshosting', $manifest);
    $db = Mockery::mock(Doctrine\DBAL\Connection::class);
    $db->shouldReceive('executeStatement')->once()->with(DnsMigrations::TABLE)->andReturn(0);
    $di['dbal'] = $db;

    expect(ModuleMigrations::pending($di, 'servicednshosting'))->toBe(1)
        ->and(ModuleMigrations::apply($di, 'servicednshosting'))->toBe([1])
        ->and(ModuleMigrations::apply($di, 'servicednshosting'))->toBe([])
        ->and(ModuleMigrations::pending($di, 'servicednshosting'))->toBe(0)
        ->and($di['pdo']->query("SELECT value FROM setting WHERE param = 'migrations_servicednshosting'")->fetchColumn())->toBe('1');
});

test('an uninstalled module runs its steps again when installed again', function (): void {
    $manifest = json_decode((string) file_get_contents(PATH_MODS . '/Servicednshosting/manifest.json'), true);
    $di = moduleMigrationsDi('servicednshosting', $manifest);
    $db = Mockery::mock(Doctrine\DBAL\Connection::class);
    $db->shouldReceive('executeStatement')->twice()->with(DnsMigrations::TABLE)->andReturn(0);
    $db->shouldReceive('delete')->once()->with('setting', ['param' => 'migrations_servicednshosting'])
        ->andReturnUsing(fn (): int => (int) $di['pdo']->exec("DELETE FROM setting WHERE param = 'migrations_servicednshosting'"));
    $di['dbal'] = $db;

    ModuleMigrations::apply($di, 'servicednshosting');
    ModuleMigrations::forget($di, 'servicednshosting');

    expect(ModuleMigrations::apply($di, 'servicednshosting'))->toBe([1]);
});

test('a module without steps runs nothing, and one naming another module\'s class is refused', function (): void {
    expect(ModuleMigrations::apply(moduleMigrationsDi('servicecustom', ['id' => 'servicecustom']), 'servicecustom'))->toBe([])
        ->and(fn () => ModuleMigrations::apply(moduleMigrationsDi('servicecustom', ['migrations' => DnsMigrations::class]), 'servicecustom'))
        ->toThrow(ClientFence\Exception::class, 'The module servicecustom names migrations it does not ship.');
});

test('a new install has the DNS hosting table and starts at its last step', function (): void {
    $sql = PATH_ROOT . '/install/sql';
    $steps = DnsMigrations::steps();

    expect((string) file_get_contents($sql . '/structure.sql'))->toContain(str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TABLE', DnsMigrations::TABLE) . ';')
        ->and((string) file_get_contents($sql . '/content.sql'))->toContain("'migrations_servicednshosting','" . array_key_last($steps) . "'");
});

test('fork migration 23 runs DNS hosting\'s steps, for an update from a release without it in the core list', function (): void {
    $manifest = json_decode((string) file_get_contents(PATH_MODS . '/Servicednshosting/manifest.json'), true);
    $di = moduleMigrationsDi('servicednshosting', $manifest);
    $db = Mockery::mock(Doctrine\DBAL\Connection::class);
    $db->shouldReceive('executeStatement')->once()->with(DnsMigrations::TABLE)->andReturn(0);
    $di['dbal'] = $db;

    (new ReflectionMethod(ClientFence\Mod\Clientfence\Migrations::class, 'm23DnsSteps'))->invoke(null, $di);

    expect(ModuleMigrations::pending($di, 'servicednshosting'))->toBe(0);
});
