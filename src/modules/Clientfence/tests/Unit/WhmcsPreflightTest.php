<?php

declare(strict_types=1);

/**
 * ClientFence: what the WHMCS import checks before it writes anything (Whmcs\Preflight): the PHP
 * memory limit, the folders it writes and their free space, the attachments folder.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Clientfence\Whmcs\Preflight;
use ClientFence\Mod\Clientfence\Whmcs\Source;
use Doctrine\DBAL\DriverManager;

/** A container whose import settings name $attachments, with the folders it writes under $root. */
function whmcsPreflightDi(string $root, string $attachments = ''): Pimple\Container
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE mod_clientfence_state (name TEXT PRIMARY KEY, value TEXT, updated_at TEXT)');
    $db->insert('mod_clientfence_state', ['name' => ClientFence\Mod\Clientfence\Whmcs\Import::SETTINGS, 'value' => json_encode(['attachments_dir' => $attachments]), 'updated_at' => '2026-10-19 00:00:00']);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di = new Pimple\Container();
    $di['em'] = $em;
    putenv('CLIENTFENCE_TICKET_FILES_DIR=' . $root . '/ticket-files');
    putenv('CLIENTFENCE_BACKUP_DIR=' . $root . '/backups');

    return $di;
}

function whmcsPreflightRoot(): string
{
    $dir = sys_get_temp_dir() . '/cf-whmcs-preflight-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    register_shutdown_function(static function () use ($dir): void {
        exec('chmod -R u+w ' . escapeshellarg($dir) . '; rm -rf ' . escapeshellarg($dir));
    });

    return $dir;
}

afterEach(function (): void {
    putenv('CLIENTFENCE_TICKET_FILES_DIR');
    putenv('CLIENTFENCE_BACKUP_DIR');
});

test('php.ini sizes are read as bytes', function (): void {
    expect(Preflight::bytes('128M'))->toBe(134217728)
        ->and(Preflight::bytes('1G'))->toBe(1073741824)
        ->and(Preflight::bytes('512k'))->toBe(524288)
        ->and(Preflight::bytes('1048576'))->toBe(1048576)
        ->and(Preflight::bytes('-1'))->toBeNull();
});

test('a low memory limit fails its check, naming the fix for the console or the website', function (): void {
    $console = Preflight::memory('128M', true);
    $web = Preflight::memory('128M', false);
    expect($console['ok'])->toBeFalse()
        ->and($console['detail'])->toContain('128 MB')->toContain('the console\'s PHP')
        ->and($console['fix'])->toContain('php -d memory_limit=512M')
        ->and($web['fix'])->toContain('this site\'s PHP settings')
        ->and(Preflight::memory('256M', true)['ok'])->toBeTrue()
        ->and(Preflight::memory('-1', false)['ok'])->toBeTrue();
});

test('a failed check stops the import before it starts; passing ones let it start', function (): void {
    $root = whmcsPreflightRoot();
    mkdir($root . '/attachments');
    $di = whmcsPreflightDi($root, $root . '/no-such-folder');
    expect(fn () => Preflight::assert($di))->toThrow(InformationException::class, 'The import did not start');

    // (this process's memory limit is the test runner's: only the folder checks are judged here)
    $checks = array_filter(Preflight::checks(whmcsPreflightDi($root, $root . '/attachments')), static fn (array $c): bool => $c['check'] !== 'PHP memory limit');
    expect(array_column($checks, 'ok'))->each->toBeTrue();
});

test('folders it cannot write, or an attachments folder it cannot read, stop it', function (): void {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('root writes every folder');
    }
    $root = whmcsPreflightRoot();
    mkdir($root . '/backups', 0500);
    $di = whmcsPreflightDi($root, $root . '/no-such-folder');
    $checks = array_column(Preflight::checks($di), null, 'check');
    expect($checks['Ticket files folder']['ok'])->toBeTrue()
        ->and($checks['Backups folder (add-on module archive)']['ok'])->toBeFalse()
        ->and($checks['Backups folder (add-on module archive)']['detail'])->toContain('cannot be written')
        ->and($checks['WHMCS attachments folder']['ok'])->toBeFalse();
});

test('WHMCS tickets with attachments need the attachments folder, or an empty one on purpose', function (): void {
    $root = whmcsPreflightRoot();
    $whmcs = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $whmcs->executeStatement("CREATE TABLE tbltickets (id INTEGER PRIMARY KEY, attachment TEXT DEFAULT '')");
    $whmcs->insert('tbltickets', ['id' => 1, 'attachment' => '123456_file.png']);

    $checks = array_column(Preflight::checks(whmcsPreflightDi($root), new Source($whmcs)), null, 'check');
    expect($checks['WHMCS attachments folder']['ok'])->toBeFalse()
        ->and($checks['WHMCS attachments folder']['fix'])->toContain('An empty folder imports the messages without their files');

    mkdir($root . '/attachments');
    file_put_contents($root . '/attachments/123456_file.png', str_repeat('x', 2048));
    $checks = array_column(Preflight::checks(whmcsPreflightDi($root, $root . '/attachments'), new Source($whmcs)), null, 'check');
    expect($checks['WHMCS attachments folder']['ok'])->toBeTrue()
        ->and($checks['Ticket files folder']['ok'])->toBeTrue();
});

test('free disk space is judged before a run starts, not while it goes on', function (): void {
    $root = whmcsPreflightRoot();
    mkdir($root . '/attachments');
    // More bytes to copy than the disk has: refused before a run starts, not judged while it goes on.
    $di = whmcsPreflightDi($root, $root . '/attachments');
    $free = (int) disk_free_space($root);
    $reflection = new ReflectionMethod(Preflight::class, 'folder');
    $starting = $reflection->invoke(null, 'Ticket files folder', $root . '/ticket-files', $free + 1);
    $going = $reflection->invoke(null, 'Ticket files folder', $root . '/ticket-files', null);
    expect($starting['ok'])->toBeFalse()
        ->and($starting['detail'])->toContain('needed')
        ->and($going['ok'])->toBeTrue()
        ->and(array_column(array_filter(Preflight::checks($di, null, false), static fn (array $c): bool => $c['check'] !== 'PHP memory limit'), 'ok'))->each->toBeTrue();
});

// ClientFence (QA r4 W-04): a currency without a three-letter code stops the import (its invoices' pages failed).
test('a WHMCS currency without a three-letter code stops it', function (): void {
    $root = whmcsPreflightRoot();
    mkdir($root . '/attachments');
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
    $db->insert('tblcurrencies', ['id' => 1, 'code' => 'usd']);
    $checks = array_column(Preflight::checks(whmcsPreflightDi($root, $root . '/attachments'), new Source($db)), null, 'check');
    expect($checks['WHMCS currency codes']['ok'])->toBeTrue();

    $db->insert('tblcurrencies', ['id' => 2, 'code' => 'CA$']);
    $checks = array_column(Preflight::checks(whmcsPreflightDi($root, $root . '/attachments'), new Source($db)), null, 'check');
    expect($checks['WHMCS currency codes'])->toMatchArray(['ok' => false, 'detail' => 'Not a three-letter code: #2 "CA$".'])
        ->and($checks)->not->toHaveKey('WHMCS table encoding')->not->toHaveKey('WHMCS text encoding');
});
