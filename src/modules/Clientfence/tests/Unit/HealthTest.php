<?php

declare(strict_types=1);

/**
 * ClientFence system health (Health.php): the admin area address check, the core patch check and
 * the speed settings (OPcache, cache backend).
 */

use ClientFence\Mod\Clientfence\Health;

test('the default admin address is spotted', function (): void {
    expect(Health::isDefaultAdminPath('/admin'))->toBeTrue()
        ->and(Health::isDefaultAdminPath('/Admin/'))->toBeTrue()
        ->and(Health::isDefaultAdminPath('admin'))->toBeTrue()
        ->and(Health::isDefaultAdminPath(''))->toBeTrue()
        ->and(Health::isDefaultAdminPath('/'))->toBeTrue()
        ->and(Health::isDefaultAdminPath('/team-7k2'))->toBeFalse()
        ->and(Health::isDefaultAdminPath('/administrator'))->toBeFalse()
        ->and(Health::isDefaultAdminPath('/staff/admin'))->toBeFalse();
});

test('core patches in the installer count only while install/ is there', function (): void {
    $root = sys_get_temp_dir() . '/cf-health-' . bin2hex(random_bytes(4));
    mkdir($root . '/library', 0o777, true);
    file_put_contents($root . '/library/App.php', '<?php // ClientFence core patch: app');
    $patches = [
        ['file' => 'library/App.php', 'done' => 'ClientFence core patch: app'],
        ['file' => 'install/result.html', 'done' => 'ClientFence core patch: setup'],
    ];

    try {
        // After setup or an update the installer is removed: nothing is missing.
        expect(Health::undonePatches($patches, $root))->toBe([]);

        // While the installer is there, its patch must be in place.
        mkdir($root . '/install');
        file_put_contents($root . '/install/result.html', 'stock');
        expect(Health::undonePatches($patches, $root))->toBe(['install/result.html']);

        // A core file without its patch is always reported.
        file_put_contents($root . '/library/App.php', '<?php // stock');
        expect(Health::undonePatches($patches, $root))->toBe(['library/App.php', 'install/result.html']);
    } finally {
        @unlink($root . '/install/result.html');
        @rmdir($root . '/install');
        @unlink($root . '/library/App.php');
        @rmdir($root . '/library');
        @rmdir($root);
    }
});

test('speed reports OPcache and the cache in use, and the cron reads what the web server saw', function (): void {
    $di = new Pimple\Container();
    $di['cache'] = new Symfony\Component\Cache\Adapter\FilesystemAdapter('health-test', 0, sys_get_temp_dir() . '/cf-health-' . bin2hex(random_bytes(4)));
    $speed = Health::speed($di);
    expect($speed['cache'])->toBe('filesystem')
        ->and($speed['opcache'])->toBe(extension_loaded('Zend OPcache') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN));

    // A Redis pool that fell back to files: in use is files, chosen is Redis.
    $di['cache']->save($di['cache']->getItem('clientfence_web_speed')->set(['opcache' => false, 'cache' => 'filesystem', 'cache_configured' => 'redis']));
    $web = (new ReflectionMethod(Health::class, 'webSpeed'))->invoke(null, $di);
    expect($web)->toBe(['opcache' => false, 'cache' => 'filesystem', 'cache_configured' => 'redis']);
    $di['cache']->clear();
});

test('without a web snapshot the cron does not report its own cache fallback', function (): void {
    $di = new Pimple\Container();
    $di['cache'] = new Symfony\Component\Cache\Adapter\FilesystemAdapter('health-test', 0, sys_get_temp_dir() . '/cf-health-' . bin2hex(random_bytes(4)));
    $web = (new ReflectionMethod(Health::class, 'webSpeed'))->invoke(null, $di);
    expect($web['cache'])->toBe($web['cache_configured']);
    $di['cache']->clear();
});

// ClientFence (QA r3 O-01): settings saved with another install's info.salt are reported, not read as empty in silence.
test('settings encrypted with another salt are reported', function (): void {
    $crypt = new ClientFence\Crypt();
    $rows = [
        ['extension' => 'mod_email', 'meta_value' => $crypt->encrypt('{"mailer":"smtp"}', ClientFence\Config::getProperty('info.salt'))],
        ['extension' => 'mod_order', 'meta_value' => $crypt->encrypt('{"store":"open"}', 'another-install-salt')],
    ];
    $connection = Mockery::mock(Doctrine\DBAL\Connection::class);
    $connection->shouldReceive('fetchAllAssociative')->andReturn($rows);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($connection);
    $di = new Pimple\Container();
    $di['em'] = $em;
    $di['crypt'] = $crypt;

    expect(Health::unreadableSettings($di))->toBe(['mod_order']);
});

test('a recent health run from another version is refreshed', function (): void {
    $saved = ['ran_at' => date('Y-m-d H:i:s', time() - 60), 'version' => '0.0.0', 'checks' => []];
    $connection = Mockery::mock(Doctrine\DBAL\Connection::class);
    $connection->shouldReceive('fetchOne')->with('SELECT value FROM mod_clientfence_state WHERE name = ?', [Health::STATE])->andReturn(json_encode($saved));
    $connection->shouldReceive('executeStatement')->andReturnUsing(function (string $sql, array $params) use (&$saved): int {
        $saved = json_decode($params[1], true);

        return 1;
    });
    $schema = Mockery::mock(Doctrine\DBAL\Schema\AbstractSchemaManager::class);
    $schema->shouldReceive('listTableNames')->andReturn(array_keys(ClientFence\Mod\Clientfence\Service::tables()));
    $connection->shouldReceive('createSchemaManager')->andReturn($schema);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($connection);
    $service = Mockery::mock();
    $service->shouldReceive('getAllChecks', 'getMessages')->andReturn([]);
    $di = new Pimple\Container();
    $di['em'] = $em;
    $di['logger'] = Mockery::mock()->shouldIgnoreMissing();
    $di['mod_service'] = $di->protect(fn () => $service);

    Health::runIfDue($di);

    expect($saved['version'])->toBe(ClientFence\Version::CLIENTFENCE)
        ->and(array_column($saved['checks'], 'detail', 'id')['fb_update'])->toBe('ClientFence :version. New versions come as ClientFence releases.')
        ->and(array_column($saved['checks'], 'params', 'id')['fb_update'])->toBe([':version' => ClientFence\Version::CLIENTFENCE]);
});

test('a saved check is read with its values filled in', function (): void {
    $report = Health::summarize(['checks' => [
        ['group' => 'services', 'id' => 'svc_cron', 'title' => 'Scheduled tasks (cron)', 'state' => 'ok', 'detail' => 'Last run :ago ago.', 'params' => [':ago' => 300]],
        ['group' => 'server', 'id' => 'srv_db_backup', 'title' => 'Registrar: :name', 'state' => 'ok', 'detail' => 'Sent :time by :methods.', 'params' => [':name' => 'Main', ':time' => '2026-11-03 04:00:00', ':methods' => ['SFTP/FTP', 'email']]],
        // Saved before the texts had values.
        ['group' => 'server', 'id' => 'srv_php', 'title' => 'PHP version', 'state' => 'ok', 'detail' => 'PHP 8.4.1.'],
    ]]);

    // The plural form comes from the loaded catalog, so only the number is checked.
    expect($report['checks'][0]['detail'])->toMatch('/^Last run 5 minutes? ago\.$/')
        ->and(array_column(array_slice($report['checks'], 1), 'detail'))->toBe(['Sent 2026-11-03 04:00:00 by SFTP/FTP, email.', 'PHP 8.4.1.'])
        ->and($report['checks'][1]['title'])->toBe('Registrar: Main');
});
