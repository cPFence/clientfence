<?php

declare(strict_types=1);

/**
 * ClientFence: database backups (Clientfence\Backups, Clientfence\DatabaseDump): value escaping, the
 * checks on the SFTP/FTP and email settings, the methods "Back up now" uses and the Health states, on an in-memory SQLite copy
 * of mod_clientfence_state. The dump itself and the transfers need MariaDB and a server: they are
 * checked on the local test site (dev/README.md).
 */

use ClientFence\Mod\Clientfence\Backups;
use ClientFence\Mod\Clientfence\DatabaseDump;
use ClientFence\Mod\Clientfence\UpdateBackup;
use Doctrine\DBAL\DriverManager;
use ClientFence\InformationException;
use Symfony\Component\Filesystem\Filesystem;

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

test('a run uses the methods that are on, with a choice only the chosen ones, and makes no backup when none is left', function (): void {
    $settings = ['ftp' => ['enabled' => true], 'email' => ['enabled' => true], 'local' => ['enabled' => false]];
    expect(Backups::methods($settings))->toBe(['ftp', 'email'])
        ->and(Backups::methods($settings, ['local', 'email', 'cloud_db']))->toBe(['email'])
        ->and(Backups::methods($settings, ['local']))->toBe([]);

    // No method left: nothing is locked, dumped or saved (the in-memory database has no GET_LOCK).
    [$di] = backupsContainer(['backups' => $settings]);
    expect(Backups::run($di, ['local', 'cloud_files']))->toBeNull()
        ->and(Backups::state($di, Backups::STATUS))->toBeNull();
});

test('a Back up now run changes only its methods: ran_at, the cron\'s timer, and the other results stay', function (): void {
    $cron = ['ran_at' => '2026-11-15 03:00:00', 'ok' => false, 'file' => 'a.sql.gz', 'bytes' => 10, 'tables' => 5, 'results' => ['ftp' => 'ok', 'email' => 'Mail refused'], 'last_ok_at' => '2026-11-14 03:00:00'];
    $full = Backups::merged(null, $cron, ['ftp', 'email'], false, ['ftp', 'email']);
    expect($full['results_at'])->toBe(['ftp' => '2026-11-15 03:00:00', 'email' => '2026-11-15 03:00:00']);

    $now = ['ran_at' => '2026-11-15 20:00:00', 'ok' => true, 'file' => 'b.sql.gz', 'bytes' => 20, 'tables' => 5, 'results' => ['email' => 'ok'], 'last_ok_at' => '2026-11-15 20:00:00'];
    $merged = Backups::merged($full, $now, ['email'], true, ['ftp', 'email']);
    expect($merged)->toMatchArray(['ran_at' => '2026-11-15 03:00:00', 'ok' => true, 'file' => 'b.sql.gz', 'bytes' => 20, 'last_ok_at' => '2026-11-15 20:00:00'])
        ->and($merged['results'])->toBe(['ftp' => 'ok', 'email' => 'ok'])
        ->and($merged['results_at'])->toBe(['ftp' => '2026-11-15 03:00:00', 'email' => '2026-11-15 20:00:00']);

    // A run that dies leaves the other methods' results; a failed dump is the result of the chosen ones.
    $running = Backups::merged($full, ['running' => true, 'results' => ['dump' => 'Started, but did not finish (the process stopped).']] + $now, ['email'], true, ['ftp', 'email']);
    expect($running['results'])->toBe(['ftp' => 'ok', 'email' => 'Started, but did not finish (the process stopped).'])
        ->and($running)->toMatchArray(['running' => true, 'ok' => false, 'ran_at' => '2026-11-15 03:00:00']);

    // A full run replaces everything, as before.
    expect(Backups::merged($merged, $cron, ['ftp', 'email'], false, ['ftp', 'email'])['results'])->toBe(['ftp' => 'ok', 'email' => 'Mail refused']);

    // An old failed dump and a method turned off since are dropped, so a good run clears the failure.
    $died = ['ran_at' => '2026-11-15 03:00:00', 'ok' => false, 'running' => true, 'results' => ['dump' => 'Started, but did not finish (the process stopped).', 'ftp' => 'Login failed'], 'results_at' => ['ftp' => '2026-11-15 03:00:00']];
    $cleared = Backups::merged($died, ['results' => ['email' => 'ok']] + $now, ['email'], true, ['email']);
    expect($cleared['results'])->toBe(['email' => 'ok'])
        ->and($cleared['ok'])->toBeTrue()
        ->and($cleared['results_at'])->toBe(['email' => '2026-11-15 20:00:00']);

    // A first run that is partial leaves the timer unset: the cron makes the daily backup at its next run.
    expect(Backups::merged(null, $now, ['email'], true, ['ftp', 'email'])['ran_at'])->toBe('');
});

/** The admin API as staff member $adminId (1 is the only Super Administrator). */
function backupsAdminApi(Pimple\Container $di, int $adminId): ClientFence\Mod\Clientfence\Api\Admin
{
    $di['logger'] = Mockery::mock()->shouldIgnoreMissing();
    $staff = Mockery::mock();
    $staff->shouldReceive('isSuperAdministrator')->andReturnUsing(fn (int $id): bool => $id === 1);
    $di['mod_service'] = $di->protect(fn () => $staff);
    $admin = new Model_Admin();
    $admin->loadBean(new Tests\Helpers\DummyBean());
    $admin->id = $adminId;
    $api = new ClientFence\Mod\Clientfence\Api\Admin();
    $api->setDi($di);
    $api->setIdentity($admin);

    return $api;
}

test('Back up now checks its choice: nothing ticked, unknown or cloud copies while they are off are refused', function (): void {
    [$di] = backupsContainer(['backups' => ['email' => ['enabled' => true, 'address' => 'b@example.com']]]);
    $api = backupsAdminApi($di, 1);
    expect(fn () => $api->backup_run(['targets' => ['']]))->toThrow(InformationException::class, 'Choose where')
        ->and(fn () => $api->backup_run(['targets' => ['', 'tape']]))->toThrow(InformationException::class, 'Unknown')
        ->and(fn () => $api->backup_run(['targets' => ['', 'email', 'cloud_files']]))->toThrow(InformationException::class, 'Cloud copies are off')
        // Only methods that are off: no backup, no cloud copy.
        ->and($api->backup_run(['targets' => ['', 'local', 'ftp']]))->toBe(['backup' => null, 'cloud' => []])
        ->and(fn () => backupsAdminApi($di, 2)->backup_run(['targets' => ['email']]))->toThrow(InformationException::class, 'Only Super Administrators');
});

test('Health warns with no method on, needs attention after a failure and warns when old', function (): void {
    [$di] = backupsContainer();
    expect(Backups::health($di)[0])->toBe('warn');

    $on = ['ftp' => ['enabled' => false], 'email' => ['enabled' => true, 'address' => 'b@example.com']];
    [$di] = backupsContainer(['backups' => $on]);
    expect(Backups::health($di))->toBe(['ok', 'On (:methods); the first backup is made at the next cron run.', [':methods' => ['email']]]);

    [$di] = backupsContainer(['backups' => $on, 'backups_status' => ['ran_at' => date('Y-m-d H:i:s'), 'ok' => false, 'results' => ['email' => 'Mail server refused']]]);
    expect(Backups::health($di)[0])->toBe('bad')
        ->and(Backups::health($di)[2][':why'])->toBe('email: Mail server refused');

    $old = date('Y-m-d H:i:s', time() - 3 * 86400);
    [$di] = backupsContainer(['backups' => $on, 'backups_status' => ['ran_at' => $old, 'ok' => true, 'last_ok_at' => $old, 'results' => ['email' => 'ok']]]);
    expect(Backups::health($di))->toBe(['warn', 'The newest backup sent is :days days old: check that the cron runs.', [':days' => 3]]);

    [$di] = backupsContainer(['backups' => $on, 'backups_status' => ['ran_at' => date('Y-m-d H:i:s'), 'ok' => true, 'results' => ['email' => 'ok']]]);
    expect(Backups::health($di)[0])->toBe('ok');

    // A run in progress is not a failure; one that stopped an hour ago is.
    $running = ['ran_at' => date('Y-m-d H:i:s'), 'ok' => false, 'running' => true, 'results' => ['dump' => 'Started, but did not finish (the process stopped).']];
    [$di] = backupsContainer(['backups' => $on, 'backups_status' => $running]);
    expect(Backups::health($di)[1])->toContain('being made now');
    [$di] = backupsContainer(['backups' => $on, 'backups_status' => ['ran_at' => date('Y-m-d H:i:s', time() - 7200)] + $running]);
    expect(Backups::health($di))->toBe(['bad', 'The backup of :time failed (:why).', [':time' => date('Y-m-d H:i:s', time() - 7200), ':why' => 'dump: Started, but did not finish (the process stopped).']]);
    // A Back up now run keeps the cron's ran_at: it is running by its own time.
    [$di] = backupsContainer(['backups' => $on, 'backups_status' => ['ran_at' => date('Y-m-d H:i:s', time() - 7200), 'results_at' => ['email' => date('Y-m-d H:i:s')]] + $running]);
    expect(Backups::health($di)[1])->toContain('being made now');
});

test('Health counts cloud copies of the database as a copy off this server', function (): void {
    $local = ['local' => ['enabled' => true], 'email' => ['enabled' => false], 'ftp' => ['enabled' => false]];
    $done = ['ran_at' => date('Y-m-d H:i:s'), 'ok' => true, 'results' => ['local' => 'ok']];
    [$di] = backupsContainer(['backups' => $local, 'backups_status' => $done]);
    expect(Backups::health($di)[0])->toBe('warn')->and(Backups::health($di)[1])->toContain('cloud storage');

    [$di] = backupsContainer(['backups' => $local, 'backups_status' => $done, 'backups_cloud' => ['enabled' => true, 'provider' => 'gdrive', 'db_every' => 'daily']]);
    expect(Backups::health($di))->toBe(['ok', 'Made :time; cloud storage keeps a copy off this server.', [':time' => $done['ran_at']]]);

    // Cloud copies of the files only leave the database on this server.
    [$di] = backupsContainer(['backups' => $local, 'backups_status' => $done, 'backups_cloud' => ['enabled' => true, 'provider' => 'gdrive', 'db_every' => 'never', 'files_every' => 'daily']]);
    expect(Backups::health($di)[0])->toBe('warn');
});

test('update backups refuse a folder inside the site root', function (): void {
    $previous = getenv('CLIENTFENCE_BACKUP_DIR');
    $dir = PATH_ROOT . '/data/cache/cf-backup-' . bin2hex(random_bytes(4));
    putenv('CLIENTFENCE_BACKUP_DIR=' . $dir);
    try {
        expect(fn () => UpdateBackup::make(new Pimple\Container(), '', '99.0.0'))->toThrow(InformationException::class, 'inside the website');
    } finally {
        (new Filesystem())->remove($dir);
        putenv($previous === false ? 'CLIENTFENCE_BACKUP_DIR' : 'CLIENTFENCE_BACKUP_DIR=' . $previous);
    }
});

test('the default backups folder belongs to this install', function (): void {
    $previous = getenv('CLIENTFENCE_BACKUP_DIR');
    putenv('CLIENTFENCE_BACKUP_DIR');
    try {
        expect(Backups::localDir())->toBe(dirname(PATH_ROOT) . '/.clientfence-backups/' . basename(PATH_ROOT));
    } finally {
        putenv($previous === false ? 'CLIENTFENCE_BACKUP_DIR' : 'CLIENTFENCE_BACKUP_DIR=' . $previous);
    }
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

test('a dump is loaded back statement by statement and tables made since are dropped', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'dump');
    $gz = gzopen($file, 'wb6');
    gzwrite($gz, "-- ClientFence database backup\n\n/*!40014 SET FOREIGN_KEY_CHECKS=0 */;\n\n-- Table client\nDROP TABLE IF EXISTS `client`;\nCREATE TABLE `client` (\n  `id` int NOT NULL,\n  `note` text\n) ENGINE=InnoDB;\n"
        . 'INSERT INTO `client` (`id`, `note`) VALUES (' . "'1'," . DatabaseDump::quote("one;\ntwo;") . ");\n\n-- View v_client\nDROP VIEW IF EXISTS `v_client`;\nCREATE VIEW `v_client` AS select 1;\n\n-- Dump completed 2026-11-03 00:00:00 UTC\n");
    gzclose($gz);
    $run = [];
    $db = Mockery::mock(Doctrine\DBAL\Connection::class);
    $db->shouldReceive('fetchNumeric')->andReturn(['STRICT_TRANS_TABLES', 'SYSTEM']);
    $db->shouldReceive('executeStatement')->andReturnUsing(function (string $sql) use (&$run): int {
        $run[] = $sql;

        return 0;
    });
    $db->shouldReceive('fetchAllNumeric')->with('SHOW FULL TABLES')->andReturn([['client', 'BASE TABLE'], ['qa8_partial', 'BASE TABLE'], ['v_client', 'VIEW'], ['v_new', 'VIEW']]);

    DatabaseDump::restore($db, $file);

    expect($run)->toBe([
        '/*!40014 SET FOREIGN_KEY_CHECKS=0 */',
        'DROP TABLE IF EXISTS `client`',
        "CREATE TABLE `client` (\n  `id` int NOT NULL,\n  `note` text\n) ENGINE=InnoDB",
        "INSERT INTO `client` (`id`, `note`) VALUES ('1','one;\\ntwo;')",
        'DROP VIEW IF EXISTS `v_client`',
        'CREATE VIEW `v_client` AS select 1',
        'SET FOREIGN_KEY_CHECKS = 0',
        'DROP TABLE `qa8_partial`',
        'DROP VIEW `v_new`',
        'SET FOREIGN_KEY_CHECKS = 1, UNIQUE_CHECKS = 1',
        'SET SESSION sql_mode = ?, time_zone = ?',
    ]);

    // A cut-off dump is refused before anything is run.
    file_put_contents($file, gzencode("-- Table client\nDROP TABLE `client`;\n"));
    expect(fn () => DatabaseDump::restore($db, $file))->toThrow(RuntimeException::class, 'incomplete');
    unlink($file);
});
