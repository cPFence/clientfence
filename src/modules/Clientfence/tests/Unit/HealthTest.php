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
    // The cron keeps the last web result of the proxy check untranslated, so staff read it in their language.
    $proxy = ['group' => 'security', 'id' => 'sec_proxy', 'title' => 'Visitor addresses', 'state' => 'ok', 'detail' => 'Read from the trusted proxy at :ip.', 'params' => [':ip' => '203.0.113.9']];
    $saved = ['ran_at' => date('Y-m-d H:i:s', time() - 60), 'version' => '0.0.0', 'checks' => [$proxy]];
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
        ->and(array_column($saved['checks'], 'params', 'id')['fb_update'])->toBe([':version' => ClientFence\Version::CLIENTFENCE])
        ->and(array_column($saved['checks'], 'detail', 'id')['sec_proxy'])->toBe($proxy['detail'])
        ->and(array_column($saved['checks'], 'params', 'id')['sec_proxy'])->toBe($proxy['params']);
});

test('a registrar failure emails only after two consecutive failed runs', function (): void {
    $saved = null;
    $emails = [];
    $connection = Mockery::mock(Doctrine\DBAL\Connection::class);
    $connection->shouldReceive('fetchOne')->with('SELECT value FROM mod_clientfence_state WHERE name = ?', [Health::STATE])->andReturnUsing(function () use (&$saved): string {
        return json_encode($saved);
    });
    $connection->shouldReceive('executeStatement')->andReturnUsing(function (string $sql, array $params) use (&$saved): int {
        $saved = json_decode($params[1], true);

        return 1;
    });
    $connection->shouldReceive('fetchAssociative')->andReturn(['waiting' => 0, 'failed' => 0, 'oldest' => null]);
    $connection->shouldReceive('fetchAllAssociative')->andReturnUsing(static fn (string $sql): array => str_contains($sql, 'tld_registrar') ? [['id' => 1, 'name' => 'Main']] : []);
    $connection->shouldReceive('fetchFirstColumn')->andReturn([1]);
    $schema = Mockery::mock(Doctrine\DBAL\Schema\AbstractSchemaManager::class);
    $schema->shouldReceive('listTableNames')->andReturn(array_keys(ClientFence\Mod\Clientfence\Service::tables()));
    $connection->shouldReceive('createSchemaManager')->andReturn($schema);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($connection);
    // A real method: Health checks method_exists(), which a plain mock does not pass.
    $adapter = new class () {
        public array $answers = [true, false, false, false];

        public function testConnection(): bool
        {
            return array_shift($this->answers);
        }
    };
    $service = Mockery::mock();
    $service->shouldReceive('getAllChecks', 'getMessages', 'getConfig')->andReturn([]);
    $service->shouldReceive('getParamValue')->andReturn(date('Y-m-d H:i:s'));
    $service->shouldReceive('registrarGetRegistrarAdapter')->andReturn($adapter);
    $service->shouldReceive('isSuperAdministrator')->andReturnTrue();
    $service->shouldReceive('sendTemplate')->andReturnUsing(function (array $data) use (&$emails): bool {
        $emails[] = $data;

        return true;
    });
    $di = new Pimple\Container();
    $di['em'] = $em;
    $di['db'] = Mockery::mock();
    $di['db']->shouldReceive('load')->andReturn(new stdClass());
    $di['logger'] = Mockery::mock()->shouldIgnoreMissing();
    $di['mod_service'] = $di->protect(fn () => $service);

    // Establish the other checks' states before the registrar connection fails.
    Health::run($di);
    expect($emails)->toHaveCount(1)
        ->and(array_column($emails[0]['checks'], 'title'))->toContain(__trans('Email delivery'));
    $emails = [];

    Health::run($di);
    expect($emails)->toBe([])
        ->and(array_column($saved['checks'], 'fails', 'id')['svc_registrar_1'])->toBe(1);

    Health::run($di);
    expect($emails)->toHaveCount(1)
        ->and($emails[0]['count'])->toBe(1)
        ->and(array_column($saved['checks'], 'fails', 'id')['svc_registrar_1'])->toBe(2);

    Health::run($di);
    expect($emails)->toHaveCount(1);
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

test('the proxy check warns only when the forwarded address is not the connecting one', function (string $remote, array $headers, string $state, string $detail): void {
    Symfony\Component\HttpFoundation\Request::setTrustedProxies([], -1);
    $request = Symfony\Component\HttpFoundation\Request::create('/', server: ['REMOTE_ADDR' => $remote]);
    $request->headers->add($headers);

    $check = Health::proxyState($request);

    expect($check['state'])->toBe($state)->and($check['detail'])->toStartWith($detail);
})->with([
    'no proxy' => ['203.0.113.9', [], 'ok', 'Visitors connect directly'],
    'web server already resolved it' => ['203.0.113.9', ['X-Forwarded-For' => '203.0.113.9', 'CF-Connecting-IP' => '203.0.113.9'], 'ok', 'The web server already reads'],
    'untrusted proxy' => ['10.0.0.5', ['X-Forwarded-For' => '203.0.113.9'], 'warn', 'Requests come through a proxy'],
]);
