<?php

declare(strict_types=1);

use ClientFence\InformationException;
use ClientFence\Mod\Order\Api\Admin;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

use function Tests\Helpers\admin;
use function Tests\Helpers\container;

/**
 * Client 7's orders: 1 an active add-on, 2 an active service, 3 a suspended service, 4 cancelled, 5 pending setup; 6 is
 * client 8's active service. The single-order calls are stubbed: they record the ids they get and change the status.
 *
 * @return array{0: Admin&Mockery\MockInterface, 1: Connection}
 */
function servicesActionApi(): array
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE client_order (id INTEGER PRIMARY KEY, client_id INTEGER, title TEXT, status TEXT, group_master INTEGER)');
    foreach ([[1, 7, 'active', 0], [2, 7, 'active', 1], [3, 7, 'suspended', 1], [4, 7, 'canceled', 1], [5, 7, 'pending_setup', 1], [6, 8, 'active', 1]] as [$id, $client, $status, $master]) {
        $db->insert('client_order', ['id' => $id, 'client_id' => $client, 'title' => 'Service ' . $id, 'status' => $status, 'group_master' => $master]);
    }
    $di = container();
    $di['dbal'] = $db;
    $api = Mockery::mock(Admin::class)->makePartial();
    $api->setDi($di);
    $api->setIdentity(admin());

    return [$api, $db];
}

test('client services action runs the single-order call on the orders in its state, services first', function (string $action, string $status, array $ids): void {
    [$api, $db] = servicesActionApi();
    $called = [];
    $api->shouldReceive($action)->andReturnUsing(function (array $data) use (&$called, $db, $status): bool {
        $called[] = $data;
        $db->update('client_order', ['status' => $status], ['id' => $data['id']]);

        return true;
    });

    $result = $api->client_services_action(['client_id' => 7, 'action' => $action, 'reason' => 'Unpaid']);

    expect(array_column($called, 'id'))->toBe($ids)
        ->and($result)->toBe(['done' => count($ids), 'failed' => []]);
    if ($action === 'suspend') {
        expect(array_column($called, 'reason'))->toBe(['Unpaid', 'Unpaid']);
    }
})->with([
    'suspend: active' => ['suspend', 'suspended', [2, 1]],
    'unsuspend: suspended' => ['unsuspend', 'active', [3]],
    'cancel: active and suspended' => ['cancel', 'canceled', [2, 3, 1]],
]);

test('client services action skips an add-on its service already took along', function (): void {
    [$api, $db] = servicesActionApi();
    $called = [];
    $api->shouldReceive('cancel')->andReturnUsing(function (array $data) use (&$called, $db): bool {
        $called[] = $data['id'];
        // Cancelling service 2 cancels its add-on 1, as Order\Service::cancelFromOrder() does.
        $db->executeStatement("UPDATE client_order SET status = 'canceled' WHERE id IN (?, ?)", [$data['id'], $data['id'] === 2 ? 1 : $data['id']]);

        return true;
    });

    expect($api->client_services_action(['client_id' => 7, 'action' => 'cancel']))->toBe(['done' => 2, 'failed' => []])
        ->and($called)->toBe([2, 3]);
});

test('client services action reports a failed order and goes on with the others', function (): void {
    [$api] = servicesActionApi();
    $called = [];
    $api->shouldReceive('cancel')->andReturnUsing(function (array $data) use (&$called): bool {
        $called[] = $data['id'];
        if ($data['id'] === 2) {
            throw new InformationException('The server did not answer');
        }

        return true;
    });

    expect($api->client_services_action(['client_id' => 7, 'action' => 'cancel']))->toBe([
        'done' => 2,
        'failed' => [['id' => 2, 'title' => 'Service 2', 'error' => 'The server did not answer']],
    ])->and($called)->toBe([2, 3, 1]);
});

test('client services action refuses an unknown action', function (): void {
    [$api] = servicesActionApi();

    expect(fn (): array => $api->client_services_action(['client_id' => 7, 'action' => 'delete']))->toThrow(InformationException::class, 'Unknown action');
});
