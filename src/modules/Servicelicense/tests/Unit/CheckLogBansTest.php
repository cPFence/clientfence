<?php

declare(strict_types=1);

/**
 * ClientFence: the licence check log (Servicelicense\CheckLog) and licence bans (Servicelicense\Bans),
 * on an in-memory SQLite install with Doctrine, and the licence check (Server::process()) that
 * writes the one and honours the other.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Servicelicense\Bans;
use ClientFence\Mod\Servicelicense\CheckLog;
use ClientFence\Mod\Servicelicense\Entity\ServiceLicense;
use ClientFence\Mod\Servicelicense\Server;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Symfony\Component\Filesystem\Path;

/**
 * An install with one licence (key KEY-1, IP and host validated, nothing bound yet) and the licence
 * check served from $ip.
 *
 * @return array{0: EntityManager, 1: Pimple\Container, 2: Server}
 */
function licenceCheckFixture(string $ip = '203.0.113.5'): array
{
    $config = ORMSetup::createAttributeMetadataConfig([Path::join(__DIR__, '..', '..', 'Entity')], true);
    $config->setProxyDir(sys_get_temp_dir());
    $config->setProxyNamespace('ClientFence\\Tests\\DoctrineProxies');
    $em = new EntityManager(Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
    (new Doctrine\ORM\Tools\SchemaTool($em))->createSchema([$em->getClassMetadata(ServiceLicense::class)]);
    $db = $em->getConnection();
    $db->executeStatement('CREATE TABLE ' . CheckLog::TABLE . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, service_license_id INTEGER NOT NULL, host TEXT NOT NULL, ip TEXT NOT NULL, path TEXT NOT NULL, version TEXT NOT NULL, result TEXT NOT NULL, created_at TEXT NOT NULL)');
    $db->executeStatement('CREATE TABLE ' . Bans::TABLE . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT NOT NULL UNIQUE, notes TEXT NOT NULL, created_at TEXT NOT NULL)');
    $db->executeStatement('CREATE TABLE setting (id INTEGER PRIMARY KEY AUTOINCREMENT, param TEXT UNIQUE, value TEXT, public INTEGER, category TEXT, hash TEXT, created_at TEXT, updated_at TEXT)');

    $licence = (new ServiceLicense())->setLicenseKey('KEY-1')->setValidateIp(true)->setValidateHost(true)->setIps('[]')->setHosts('[]')->setPaths('[]')->setVersions('[]');
    $em->persist($licence);
    $em->flush();

    $di = new Pimple\Container();
    $di['em'] = $em;
    $di['logger'] = new class {
        public function setChannel(string $channel): self
        {
            return $this;
        }

        public function __call(string $name, array $arguments): void
        {
        }
    };
    $di['request'] = new class($ip) {
        public function __construct(private readonly string $ip)
        {
        }

        public function getClientIp(): string
        {
            return $this->ip;
        }
    };
    $service = Mockery::mock(ClientFence\Mod\Servicelicense\Service::class)->makePartial();
    $service->setDi($di);
    $service->shouldReceive('isLicenseActive')->andReturn(true);
    $service->shouldReceive('getOwnerName')->andReturn('Client');
    $service->shouldReceive('getExpirationDate')->andReturn(null);
    $di['mod_service'] = $di->protect(static fn (string $name): object => $service);
    $server = new Server();
    $server->setDi($di);

    return [$em, $di, $server];
}

test('bans are domains or IP addresses, written plainly, and match the host or the address checked', function (): void {
    [, $di] = licenceCheckFixture();
    expect(Bans::normalize(' https://WWW.Example.com:8080/path '))->toBe('example.com')
        ->and(Bans::normalize('2001:DB8::1'))->toBe('2001:db8::1')
        ->and(Bans::kind('198.51.100.7'))->toBe('ip')
        ->and(Bans::kind('example.com'))->toBe('domain');

    $id = Bans::add($di, 'www.example.com', 'Chargeback');
    Bans::add($di, '198.51.100.7');
    expect(Bans::all($di))->toHaveCount(2)
        ->and(Bans::all($di)[0])->toMatchArray(['id' => $id, 'value' => 'example.com', 'kind' => 'domain', 'notes' => 'Chargeback'])
        ->and(Bans::match($di, 'www.example.com', '203.0.113.5'))->toBe('example.com')
        ->and(Bans::match($di, 'EXAMPLE.com', '203.0.113.5'))->toBe('example.com')
        ->and(Bans::match($di, 'shop.example.com', '203.0.113.5'))->toBeNull()
        ->and(Bans::match($di, 'other.test', '198.51.100.7'))->toBe('198.51.100.7')
        ->and(Bans::match($di, '', '203.0.113.5'))->toBeNull()
        ->and(fn () => Bans::add($di, 'example.com'))->toThrow(InformationException::class, 'already banned')
        ->and(fn () => Bans::add($di, 'two words'))->toThrow(InformationException::class)
        ->and(fn () => Bans::add($di, 'bad_host!'))->toThrow(InformationException::class);

    Bans::delete($di, $id);
    expect(array_column(Bans::all($di), 'value'))->toBe(['198.51.100.7']);
});

test('the log keeps what a licence was checked with, newest first, for the days set', function (): void {
    [, $di] = licenceCheckFixture();
    expect(CheckLog::days($di))->toBe(CheckLog::KEEP_DAYS);

    CheckLog::record($di, 1, 'example.com', '203.0.113.5', '/home/site', '2.0', 'Valid');
    $di['em']->getConnection()->insert(CheckLog::TABLE, ['service_license_id' => 1, 'host' => 'old.test', 'ip' => '', 'path' => '', 'version' => '', 'result' => 'License Expired', 'created_at' => date('Y-m-d H:i:s', time() - 40 * 86400)]);
    CheckLog::record($di, 2, 'other.test', '203.0.113.9', '/', '1.0', 'License is not active');
    expect(CheckLog::recent($di, 1))->toHaveCount(2)
        ->and(CheckLog::recent($di, 1)[0])->toMatchArray(['host' => 'example.com', 'ip' => '203.0.113.5', 'path' => '/home/site', 'version' => '2.0', 'result' => 'Valid'])
        ->and(CheckLog::recent($di, 1)[1]['host'])->toBe('old.test');

    // Kept for 30 days: the 40-day-old line goes on the next prune; 0 keeps every line.
    CheckLog::saveDays($di, '30');
    expect(CheckLog::days($di))->toBe(30)
        ->and(CheckLog::prune($di))->toBe(1)
        ->and(CheckLog::recent($di, 1))->toHaveCount(1);
    CheckLog::saveDays($di, 0);
    expect(CheckLog::days($di))->toBe(0)
        ->and(CheckLog::prune($di))->toBe(0)
        ->and(fn () => CheckLog::saveDays($di, '-1'))->toThrow(InformationException::class)
        ->and(fn () => CheckLog::saveDays($di, '2.5'))->toThrow(InformationException::class)
        ->and(fn () => CheckLog::saveDays($di, CheckLog::MAX_DAYS + 1))->toThrow(InformationException::class);

    // A deleted licence's lines go with it.
    CheckLog::forget($di, 1);
    expect(CheckLog::recent($di, 1))->toBe([])
        ->and(CheckLog::recent($di, 2))->toHaveCount(1);
});

test('every check of a known key is logged with its answer; unknown keys are not', function (): void {
    [$em, $di, $server] = licenceCheckFixture();

    $result = $server->process(['license' => 'KEY-1', 'host' => 'example.com', 'version' => '2.0', 'path' => '/home/site']);
    expect($result['valid'])->toBeTrue();
    expect(fn () => $server->process(['license' => 'KEY-1', 'host' => 'example.com', 'version' => '2.0']))->toThrow(LogicException::class, 'Path key is not present in call');
    expect(fn () => $server->process(['license' => 'KEY-1', 'host' => 'elsewhere.test', 'version' => '2.0', 'path' => '/home/site']))->toThrow(LogicException::class, 'Host "elsewhere.test" is not allowed for this license');
    expect(fn () => $server->process(['license' => 'NO-SUCH-KEY', 'host' => 'example.com', 'version' => '2.0', 'path' => '/']))->toThrow(LogicException::class, 'Your license key is invalid.');

    $licence = $em->getRepository(ServiceLicense::class)->findByLicenseKey('KEY-1');
    expect(array_column(CheckLog::recent($di, (int) $licence->getId()), 'result'))->toBe(['Host "elsewhere.test" is not allowed for this license', 'Path key is not present in call', 'Valid'])
        ->and((int) $di['em']->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . CheckLog::TABLE))->toBe(3)
        ->and(CheckLog::recent($di, (int) $licence->getId())[2])->toMatchArray(['host' => 'example.com', 'ip' => '203.0.113.5', 'path' => '/home/site', 'version' => '2.0']);
});

test('licence checks reject boolean bindings and accept matching strings', function (string $field, string $method, string $message, int $code): void {
    [$em, $di, $server] = licenceCheckFixture();
    $licence = $em->getRepository(ServiceLicense::class)->findByLicenseKey('KEY-1');
    $licence->setHosts('["example.com"]')->setPaths('["/home/site"]')->setVersions('["2.0"]')->setValidatePath(true)->setValidateVersion(true);
    $data = ['license' => 'KEY-1', 'host' => 'example.com', 'version' => '2.0', 'path' => '/home/site'];

    expect(fn () => $server->process(json_encode(array_replace($data, [$field => true]), JSON_THROW_ON_ERROR)))
        ->toThrow(new LogicException($message, $code));
    expect($di['mod_service']('servicelicense')->$method($licence, true))->toBeFalse()
        ->and($server->process(json_encode($data, JSON_THROW_ON_ERROR))['valid'])->toBeTrue();
})->with([
    'host' => ['host', 'isValidHost', 'Host key is not present in call', 1002],
    'version' => ['version', 'isValidVersion', 'Version key is not present in call', 1003],
    'path' => ['path', 'isValidPath', 'Path key is not present in call', 1004],
]);

test('a banned domain or IP address never passes and binds nothing to the licence', function (): void {
    [$em, $di, $server] = licenceCheckFixture('198.51.100.7');
    Bans::add($di, 'banned.test');

    expect(fn () => $server->process(['license' => 'KEY-1', 'host' => 'www.banned.test', 'version' => '2.0', 'path' => '/']))->toThrow(LogicException::class, '"banned.test" is banned from licence checks');
    $em->clear();
    $licence = $em->getRepository(ServiceLicense::class)->findByLicenseKey('KEY-1');
    expect($licence->getAllowedHosts())->toBe([])
        ->and($licence->getAllowedIps())->toBe([])
        ->and(CheckLog::recent($di, (int) $licence->getId())[0]['result'])->toBe('"banned.test" is banned from licence checks');

    Bans::add($di, '198.51.100.7');
    try {
        $server->process(['license' => 'KEY-1', 'host' => 'fine.test', 'version' => '2.0', 'path' => '/']);
        $code = 0;
    } catch (LogicException $e) {
        $code = $e->getCode();
    }
    expect($code)->toBe(1011);

    // Lifted: the next check passes and binds as before.
    foreach (Bans::all($di) as $ban) {
        Bans::delete($di, $ban['id']);
    }
    expect($server->process(['license' => 'KEY-1', 'host' => 'fine.test', 'version' => '2.0', 'path' => '/'])['valid'])->toBeTrue();
    $em->clear();
    expect($em->getRepository(ServiceLicense::class)->findByLicenseKey('KEY-1')->getAllowedHosts())->toBe(['fine.test']);
});
