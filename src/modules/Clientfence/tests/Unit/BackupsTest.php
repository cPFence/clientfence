<?php

declare(strict_types=1);

/**
 * ClientFence: database backups (Clientfence\Backups, Clientfence\DatabaseDump): value escaping, the
 * checks on the SFTP/FTP and email settings, and the Health states, on an in-memory SQLite copy
 * of mod_clientfence_state. The dump itself and the transfers need MariaDB and a server: they are
 * checked on the local test site (dev/README.md).
 */

use ClientFence\Mod\Clientfence\Backups;
use ClientFence\Mod\Clientfence\DatabaseDump;
use Doctrine\DBAL\DriverManager;
use ClientFence\InformationException;

function backupsContainer(array $state = []): array
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE mod_clientfence_state (name TEXT PRIMARY KEY, value TEXT, updated_at TEXT)');
    foreach ($state as $name => $value) {
        $db->insert('mod_clientfence_state', ['name' => $name, 'value' => json_encode($value), 'updated_at' => date('Y-m-d H:i:s')]);
    }
    $di = new Pimple\Container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;
    $crypt = Mockery::mock();
    $crypt->shouldReceive('encrypt')->andReturnUsing(fn (string $p): string => 'enc:' . strrev($p));
    $di['crypt'] = $crypt;

    return [$di, $db];
}

test('values are written as MySQL literals that survive any quote or control byte', function (): void {
    expect(DatabaseDump::quote("O'Brien \\ said\n\"hi\"\r\0\x1a"))->toBe("'O\\'Brien \\\\ said\\n\"hi\"\\r\\0\\Z'")
        ->and(DatabaseDump::quote('مرحبا'))->toBe("'مرحبا'");
});

test('settings start off and the password never reaches the page', function (): void {
    [$di] = backupsContainer(['backups' => ['ftp' => ['enabled' => true, 'host' => 'b.example.com', 'password' => 'enc:x', 'unknown' => 1]]]);
    $view = Backups::forStaff($di);
    expect($view['ftp'])->toMatchArray(['enabled' => true, 'host' => 'b.example.com', 'port' => 22, 'secure' => true, 'has_password' => true])
        ->and($view['ftp'])->not->toHaveKey('password')
        ->and($view['ftp'])->not->toHaveKey('unknown')
        ->and($view['email'])->toBe(['enabled' => false, 'address' => ''])
        ->and($view['status'])->toBeNull();
});

test('SFTP/FTP details are checked before anything is saved or sent', function (): void {
    [$di] = backupsContainer();
    $good = ['host' => 'backup.example.com', 'port' => '22', 'username' => 'bk', 'password' => 'pw', 'path' => 'backups/', 'secure' => '1'];
    foreach ([
        [['host' => 'sftp://backup.example.com/x'] + $good, 'host name'],
        [['host' => ''] + $good, 'host name'],
        [['port' => '0'] + $good, 'port'],
        [['username' => ''] + $good, 'username'],
        [['password' => ''] + $good, 'password'],
        [['path' => '../etc'] + $good, '..'],
        [['username' => "bk\r\nDELE x"] + $good, 'Line breaks'],
    ] as [$data, $message]) {
        expect(fn () => Backups::saveFtp($di, $data, true))->toThrow(InformationException::class, $message);
    }
});

test('the trusted SFTP key stays only for the same host and port', function (): void {
    [$di] = backupsContainer();
    $old = ['host' => 'backup.example.com', 'port' => 22, 'password' => 'enc:x', 'host_key' => str_repeat('a', 32)];
    $data = ['host' => 'backup.example.com', 'port' => '22', 'username' => 'bk', 'password' => '', 'secure' => '1'];
    expect(Backups::ftpFrom($di, $data, $old)['host_key'])->toBe(str_repeat('a', 32))
        ->and(Backups::ftpFrom($di, ['host' => 'other.example.com'] + $data, $old)['host_key'])->toBe('')
        ->and(Backups::ftpFrom($di, ['port' => '2222'] + $data, $old)['host_key'])->toBe('');
});

test('emailed backups need a valid address', function (): void {
    [$di] = backupsContainer();
    expect(fn () => Backups::saveEmail($di, ['address' => 'not an address'], true))->toThrow(InformationException::class, 'valid email')
        ->and(fn () => Backups::saveEmail($di, ['address' => ''], true))->toThrow(InformationException::class, 'Enter the email address')
        ->and(fn () => Backups::deactivate($di, 'tape'))->toThrow(InformationException::class, 'Unknown');
});

test('Health warns with no method on, needs attention after a failure and warns when old', function (): void {
    [$di] = backupsContainer();
    expect(Backups::health($di)[0])->toBe('warn');

    $on = ['ftp' => ['enabled' => false], 'email' => ['enabled' => true, 'address' => 'b@example.com']];
    [$di] = backupsContainer(['backups' => $on]);
    expect(Backups::health($di))->toBe(['ok', 'On (email); the first backup is made at the next cron run.']);

    [$di] = backupsContainer(['backups' => $on, 'backups_status' => ['ran_at' => date('Y-m-d H:i:s'), 'ok' => false, 'results' => ['email' => 'Mail server refused']]]);
    expect(Backups::health($di)[0])->toBe('bad')
        ->and(Backups::health($di)[1])->toContain('email: Mail server refused');

    $old = date('Y-m-d H:i:s', time() - 3 * 86400);
    [$di] = backupsContainer(['backups' => $on, 'backups_status' => ['ran_at' => $old, 'ok' => true, 'last_ok_at' => $old, 'results' => ['email' => 'ok']]]);
    expect(Backups::health($di))->toBe(['warn', 'The newest backup sent is 3 days old: check that the cron runs.']);

    [$di] = backupsContainer(['backups' => $on, 'backups_status' => ['ran_at' => date('Y-m-d H:i:s'), 'ok' => true, 'results' => ['email' => 'ok']]]);
    expect(Backups::health($di)[0])->toBe('ok');

    // A run in progress is not a failure; one that stopped an hour ago is.
    $running = ['ran_at' => date('Y-m-d H:i:s'), 'ok' => false, 'running' => true, 'results' => ['dump' => 'Started, but did not finish (the process stopped).']];
    [$di] = backupsContainer(['backups' => $on, 'backups_status' => $running]);
    expect(Backups::health($di)[1])->toContain('being made now');
    [$di] = backupsContainer(['backups' => $on, 'backups_status' => ['ran_at' => date('Y-m-d H:i:s', time() - 7200)] + $running]);
    expect(Backups::health($di))->toBe(['bad', 'The backup of ' . date('Y-m-d H:i:s', time() - 7200) . ' failed (dump: Started, but did not finish (the process stopped).).']);
});

test('backups kept on this server: owner-only, old ones removed, the newest always stays', function (): void {
    $dir = sys_get_temp_dir() . '/bf-keep-' . bin2hex(random_bytes(4));
    putenv('CLIENTFENCE_BACKUP_DIR=' . $dir);
    try {
        $source = tempnam(sys_get_temp_dir(), 'bfsrc');
        file_put_contents($source, gzencode('-- dump'));

        Backups::keep($source, 'db_backup_2026-10-01_030000_0123456789abcdef.sql.gz', 7);
        touch($dir . '/db_backup_2026-10-01_030000_0123456789abcdef.sql.gz', time() - 20 * 86400);
        // Only the newest is left, however old it is.
        Backups::keep($source, 'db_backup_2026-10-01_030000_0123456789abcdef.sql.gz', 7);
        touch($dir . '/db_backup_2026-10-01_030000_0123456789abcdef.sql.gz', time() - 20 * 86400);
        expect(array_column(Backups::localFiles(), 'name'))->toBe(['db_backup_2026-10-01_030000_0123456789abcdef.sql.gz']);

        file_put_contents($dir . '/notes.txt', 'not a backup');
        Backups::keep($source, 'db_backup_2026-10-15_030000_0123456789abcdef.sql.gz', 7);
        Backups::keep($source, 'db_backup_2026-10-16_030000_0123456789abcdef.sql.gz', 7);
        expect(array_column(Backups::localFiles(), 'name'))->toBe(['db_backup_2026-10-16_030000_0123456789abcdef.sql.gz', 'db_backup_2026-10-15_030000_0123456789abcdef.sql.gz'])
            ->and(is_file($dir . '/notes.txt'))->toBeTrue()
            ->and(fileperms($dir) & 0777)->toBe(0700)
            ->and(fileperms($dir . '/db_backup_2026-10-16_030000_0123456789abcdef.sql.gz') & 0777)->toBe(0600);

        expect(Backups::localPath('db_backup_2026-10-16_030000_0123456789abcdef.sql.gz'))->toBe($dir . '/db_backup_2026-10-16_030000_0123456789abcdef.sql.gz')
            ->and(Backups::localPath('../db_backup_2026-10-16_030000_0123456789abcdef.sql.gz'))->toBeNull()
            ->and(Backups::localPath('notes.txt'))->toBeNull()
            ->and(Backups::localPath('db_backup_2026-10-16_030000.sql.gz'))->toBeNull()
            ->and(is_file($dir . '/.htaccess'))->toBeTrue()
            ->and(Backups::localPath('db_backup_2026-10-17_030000_0123456789abcdef.sql.gz'))->toBeNull();

        [$di] = backupsContainer();
        expect(fn () => Backups::saveLocal($di, ['days' => '0'], true))->toThrow(InformationException::class, '1 to 365');
    } finally {
        foreach (glob($dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($dir);
        putenv('CLIENTFENCE_BACKUP_DIR');
        @unlink($source ?? '');
    }
});

test('a backup write that the disk cuts short fails instead of passing as good', function (): void {
    if (!is_writable('/dev/full')) {
        $this->markTestSkipped('/dev/full is not available here.');
    }
    $gz = gzopen('/dev/full', 'wb6');
    $put = new ReflectionMethod(ClientFence\Mod\Clientfence\DatabaseDump::class, 'put');

    // Random text does not compress, so zlib has to write it out at once.
    expect(fn () => $put->invoke(null, $gz, random_bytes(1 << 20)))->toThrow(RuntimeException::class, 'disk full');
    @gzclose($gz);
});

test('a backup file counts as written only when it reads back to its last line', function (): void {
    $complete = new ReflectionMethod(DatabaseDump::class, 'complete');
    $dir = sys_get_temp_dir() . '/cf-dump-' . bin2hex(random_bytes(4));
    mkdir($dir);
    $body = str_repeat(bin2hex(random_bytes(512)) . "\n", 64);
    file_put_contents($dir . '/good.gz', gzencode($body . "-- Dump completed 2026-10-20 07:00:00 UTC\n"));
    file_put_contents($dir . '/no-end.gz', gzencode($body));
    $good = (string) file_get_contents($dir . '/good.gz');
    file_put_contents($dir . '/cut.gz', substr($good, 0, (int) (strlen($good) * 0.6)));

    expect($complete->invoke(null, $dir . '/good.gz'))->toBeTrue()
        ->and($complete->invoke(null, $dir . '/no-end.gz'))->toBeFalse()
        ->and($complete->invoke(null, $dir . '/cut.gz'))->toBeFalse();
    array_map('unlink', glob($dir . '/*') ?: []);
    rmdir($dir);
});
