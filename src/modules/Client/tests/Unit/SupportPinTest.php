<?php

declare(strict_types=1);

use ClientFence\InformationException;
use ClientFence\Mod\Client\Api\Admin;
use ClientFence\Mod\Client\Api\Client;
use ClientFence\Mod\Client\SupportPin;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Tests\Helpers\TestLogger;

use function Tests\Helpers\admin;
use function Tests\Helpers\client;
use function Tests\Helpers\container;

function supportPinDi(): Pimple\Container
{
    $di = container();
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE client (id INTEGER PRIMARY KEY)');
    $db->executeStatement('CREATE TABLE client_support_pin (client_id INTEGER PRIMARY KEY, pin TEXT NOT NULL UNIQUE)');
    $db->insert('client', ['id' => 7]);
    $di['dbal'] = $db;

    return $di;
}

function supportPinClientApi(Pimple\Container $di): Client
{
    $api = new Client();
    $api->setDi($di);
    $api->setIdentity(client(['id' => 7]));

    return $api;
}

function supportPinAdminApi(Pimple\Container $di): Admin
{
    $api = new Admin();
    $api->setDi($di);
    $api->setIdentity(admin(['id' => 2]));

    return $api;
}

test('a client gets one 8-digit Support PIN the first time it is shown and keeps it', function (): void {
    $di = supportPinDi();
    $pin = supportPinClientApi($di)->support_pin();

    expect($pin)->toMatch('/^\d{8}$/')
        ->and(supportPinClientApi($di)->support_pin())->toBe($pin)
        ->and(supportPinAdminApi($di)->support_pin(['id' => 7]))->toBe($pin)
        ->and((int) $di['dbal']->fetchOne('SELECT COUNT(*) FROM client_support_pin'))->toBe(1);
});

test('PINs keep their leading zeros', function (): void {
    $di = supportPinDi();
    $di['dbal']->insert('client_support_pin', ['client_id' => 7, 'pin' => '00012345']);

    expect(SupportPin::get($di, 7))->toBe('00012345');
});

test('New PIN replaces the PIN for the client and for staff', function (): void {
    $di = supportPinDi();
    $old = supportPinClientApi($di)->support_pin();
    $new = supportPinClientApi($di)->support_pin_new();

    expect($new)->toMatch('/^\d{8}$/')->not->toBe($old)
        ->and(supportPinClientApi($di)->support_pin())->toBe($new);

    $staffNew = supportPinAdminApi($di)->support_pin_new(['id' => 7]);
    expect($staffNew)->not->toBe($new)
        ->and(supportPinClientApi($di)->support_pin())->toBe($staffNew);
});

test('staff cannot make a PIN for a client that does not exist', function (): void {
    $di = supportPinDi();

    expect(fn () => supportPinAdminApi($di)->support_pin(['id' => 99]))->toThrow(InformationException::class, 'Client not found')
        ->and((int) $di['dbal']->fetchOne('SELECT COUNT(*) FROM client_support_pin'))->toBe(0);
});

test('staff need client view to read the PIN and edit profile to make a new one', function (string $method, string $permission): void {
    $di = supportPinDi();
    $di['mod_service']('Staff')->shouldReceive('checkPermissionsAndThrowException')->once()
        ->with('client', $permission, null, Mockery::type(Model_Admin::class))
        ->andThrow(new InformationException('Permission denied'));

    expect(fn () => supportPinAdminApi($di)->{$method}(['id' => 7]))->toThrow(InformationException::class, 'Permission denied')
        ->and((int) $di['dbal']->fetchOne('SELECT COUNT(*) FROM client_support_pin'))->toBe(0);
})->with([
    'read' => ['support_pin', 'view'],
    'new PIN' => ['support_pin_new', 'edit_profile'],
]);

/** A Connection mock for a client #7 without a PIN where the first $taken random PINs belong to other clients. */
function supportPinTakenDb(int $taken, array &$checked): Doctrine\DBAL\Connection
{
    $db = Mockery::mock(Doctrine\DBAL\Connection::class);
    $db->shouldReceive('fetchOne')->with('SELECT pin FROM client_support_pin WHERE client_id = ?', [7])->andReturn(false);
    $db->shouldReceive('fetchOne')->with('SELECT id FROM client WHERE id = ?', [7])->andReturn(7);
    $db->shouldReceive('fetchOne')->with('SELECT 1 FROM client_support_pin WHERE pin = ?', Mockery::type('array'))
        ->andReturnUsing(function (string $sql, array $params) use (&$checked, $taken): int|false {
            $checked[] = $params[0];

            return count($checked) <= $taken ? 1 : false;
        });

    return $db;
}

test('a new PIN skips PINs other clients already have', function (): void {
    $checked = [];
    $db = supportPinTakenDb(2, $checked);
    $saved = [];
    $db->shouldReceive('insert')->once()->andReturnUsing(function (string $table, array $row) use (&$saved): int {
        $saved = $row;

        return 1;
    });
    $di = container();
    $di['dbal'] = $db;

    $pin = SupportPin::get($di, 7);

    expect($checked)->toHaveCount(3)
        ->and($pin)->toBe($checked[2])->toMatch('/^\d{8}$/')
        ->and($saved)->toBe(['client_id' => 7, 'pin' => $checked[2]]);
});

test('a PIN another client saved at the same moment is replaced by a new one', function (): void {
    $checked = [];
    $db = supportPinTakenDb(0, $checked);
    $inserts = 0;
    $db->shouldReceive('insert')->twice()->andReturnUsing(function () use (&$inserts): int {
        if (++$inserts === 1) {
            throw new UniqueConstraintViolationException(new class('UNIQUE constraint failed: client_support_pin.pin') extends Exception implements Doctrine\DBAL\Driver\Exception {
                public function getSQLState(): ?string
                {
                    return '23000';
                }
            }, null);
        }

        return 1;
    });
    $di = container();
    $di['dbal'] = $db;

    expect(SupportPin::get($di, 7))->toBe($checked[1])
        ->and($checked)->toHaveCount(2);
});

test('an 8-digit staff search finds the client by Support PIN and logs it without the PIN', function (): void {
    $di = supportPinDi();
    $di['logger'] = $logger = new TestLogger();
    $pin = SupportPin::get($di, 7);

    expect(SupportPin::findClient($di, $pin))->toBe(7)
        ->and($logger->calls)->toHaveCount(1)
        ->and($logger->calls[0]['method'])->toBe('info')
        ->and(vsprintf($logger->calls[0]['params'][0], array_slice($logger->calls[0]['params'], 1)))->toBe('Found client #7 by Support PIN')
        ->and(json_encode($logger->calls))->not->toContain($pin);
});

test('a search that is not a known 8-digit PIN finds no client and logs nothing', function (string $search): void {
    $di = supportPinDi();
    $di['logger'] = $logger = new TestLogger();
    $di['dbal']->insert('client_support_pin', ['client_id' => 7, 'pin' => '12345678']);
    // A PIN left behind by a deleted client names nobody.
    $di['dbal']->insert('client_support_pin', ['client_id' => 99, 'pin' => '87654321']);

    expect(SupportPin::findClient($di, $search))->toBeNull()
        ->and($logger->calls)->toBe([]);
})->with(['unknown PIN' => '11112222', 'deleted client' => '87654321', 'too short' => '1234567', 'too long' => '123456789', 'not digits' => '1234567a', 'empty' => '']);

test('the staff client list passes the client found by Support PIN to the search', function (): void {
    $di = supportPinDi();
    $di['logger'] = $logger = new TestLogger();
    $di['dbal']->insert('client_support_pin', ['client_id' => 7, 'pin' => '12345678']);
    $queryBuilder = Mockery::mock(Doctrine\ORM\QueryBuilder::class);
    $repository = Mockery::mock(ClientFence\Mod\Client\Repository\ClientRepository::class);
    $repository->shouldReceive('getSearchQueryBuilder')->once()->with(['search' => '12345678'], 7)->andReturn($queryBuilder);
    $repository->shouldReceive('getListContext')->once()->with([])->andReturn([]);
    $service = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $service->shouldReceive('getClientRepository')->once()->andReturn($repository);
    $pager = Mockery::mock(ClientFence\Pagination::class);
    $pager->shouldReceive('paginateDoctrineQuery')->once()->andReturn(['list' => []]);
    $di['pager'] = $pager;
    $api = supportPinAdminApi($di);
    $api->setService($service);

    expect($api->get_list(['search' => '12345678']))->toBe(['list' => []])
        ->and($logger->calls)->toHaveCount(1);
});
