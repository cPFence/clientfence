<?php

declare(strict_types=1);

use ClientFence\Mod\Client\StatusUpdate;

/** Clients 1-8 (see the comments) and the system setting the once-a-day run keeps. */
function statusUpdateWorld(string $mode): Pimple\Container
{
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE client (id INTEGER PRIMARY KEY, status TEXT, status_update INTEGER DEFAULT 1, created_at TEXT, updated_at TEXT)');
    $db->executeStatement('CREATE TABLE client_order (id INTEGER PRIMARY KEY, client_id INTEGER, status TEXT)');
    $db->executeStatement('CREATE TABLE activity_client_history (id INTEGER PRIMARY KEY, client_id INTEGER, created_at TEXT)');
    $old = date('Y-m-d H:i:s', strtotime('-1 year'));
    foreach ([
        1 => ['active', 1, $old],                                    // only canceled orders: Inactive
        2 => ['active', 1, $old],                                    // a suspended service keeps them Active
        3 => ['active', 1, date('Y-m-d H:i:s', strtotime('-1 day'))], // new: left alone
        4 => ['active', 0, $old],                                    // Status Update off on the profile
        5 => ['suspended', 1, $old],                                 // never touched
        6 => ['inactive', 1, $old],                                  // a new order: Active again
        7 => ['active', 1, $old],                                    // no services, signed in last week
        8 => ['inactive', 0, $old],                                  // set Inactive by staff, switch off
    ] as $id => [$status, $switch, $created]) {
        $db->insert('client', ['id' => $id, 'status' => $status, 'status_update' => $switch, 'created_at' => $created]);
    }
    foreach ([[1, 'canceled'], [2, 'suspended'], [6, 'pending_setup'], [8, 'active']] as [$client, $status]) {
        $db->insert('client_order', ['client_id' => $client, 'status' => $status]);
    }
    $db->insert('activity_client_history', ['client_id' => 7, 'created_at' => date('Y-m-d H:i:s', strtotime('-1 week'))]);

    $di = Tests\Helpers\container();
    $di['dbal'] = $db;
    $di['mod_config'] = $di->protect(fn (string $name): array => ['status_update' => $mode]);
    $params = [];
    $system = Mockery::mock();
    $system->allows('getParamValue')->andReturnUsing(function (string $key, $default) use (&$params) {
        return $params[$key] ?? $default;
    });
    $system->allows('setParamValue')->andReturnUsing(function (string $key, $value) use (&$params): bool {
        $params[$key] = $value;

        return true;
    });
    $di['mod_service'] = $di->protect(fn (): object => $system);
    $di['logger'] = new Tests\Helpers\TestLogger();

    return $di;
}

test('the status update sets clients without live services Inactive and back, once a day, as WHMCS', function (string $mode, array $expected): void {
    $di = statusUpdateWorld($mode);

    $first = StatusUpdate::run($di);
    $second = StatusUpdate::run($di);

    expect($di['dbal']->fetchAllKeyValue('SELECT id, status FROM client ORDER BY id'))->toBe($expected)
        ->and($second)->toBeNull()
        ->and($first)->toBe($mode === 'off' ? null : [array_count_values($expected)['inactive'] - 1, 1]);
})->with([
    'off' => ['off', [1 => 'active', 2 => 'active', 3 => 'active', 4 => 'active', 5 => 'suspended', 6 => 'inactive', 7 => 'active', 8 => 'inactive']],
    'by services' => ['services', [1 => 'inactive', 2 => 'active', 3 => 'active', 4 => 'active', 5 => 'suspended', 6 => 'active', 7 => 'inactive', 8 => 'inactive']],
    'by services and sign-in' => ['signin', [1 => 'inactive', 2 => 'active', 3 => 'active', 4 => 'active', 5 => 'suspended', 6 => 'active', 7 => 'active', 8 => 'inactive']],
]);

test('a new order sets an Inactive client Active at once, whatever the setting', function (): void {
    $di = statusUpdateWorld('off');

    StatusUpdate::ordered($di, 8);
    StatusUpdate::ordered($di, 5);

    expect($di['dbal']->fetchAllKeyValue('SELECT id, status FROM client WHERE id IN (5, 8) ORDER BY id'))->toBe([5 => 'suspended', 8 => 'active']);
});
