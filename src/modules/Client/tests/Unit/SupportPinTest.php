<?php

declare(strict_types=1);

use ClientFence\InformationException;
use ClientFence\Mod\Client\Api\Admin;
use ClientFence\Mod\Client\Api\Client;
use ClientFence\Mod\Client\SupportPin;
use Doctrine\DBAL\DriverManager;

use function Tests\Helpers\admin;
use function Tests\Helpers\client;
use function Tests\Helpers\container;

function supportPinDi(): Pimple\Container
{
    $di = container();
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE client (id INTEGER PRIMARY KEY)');
    $db->executeStatement('CREATE TABLE client_support_pin (client_id INTEGER PRIMARY KEY, pin TEXT NOT NULL)');
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
