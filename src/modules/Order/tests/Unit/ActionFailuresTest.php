<?php

declare(strict_types=1);

/**
 * ClientFence: failed order actions (ClientFence\Mod\Order\ActionFailures), on an in-memory SQLite copy of
 * the table and the order and client columns its list reads.
 */

use ClientFence\Mod\Order\ActionFailures;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use ClientFence\InformationException;

function actionFailuresDb(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE client (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT)');
    $db->executeStatement('CREATE TABLE client_order (id INTEGER PRIMARY KEY, client_id INTEGER, title TEXT, status TEXT)');
    $db->executeStatement('CREATE TABLE ' . ActionFailures::TABLE . " (id INTEGER PRIMARY KEY AUTOINCREMENT, client_order_id INTEGER NOT NULL, action TEXT NOT NULL, message TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 1, status TEXT NOT NULL DEFAULT 'open', first_failed_at TEXT NOT NULL, last_failed_at TEXT NOT NULL, resolved_at TEXT, resolved_by INTEGER)");
    $db->insert('client', ['id' => 5, 'first_name' => 'Alice', 'last_name' => 'Test']);
    $db->insert('client_order', ['id' => 7, 'client_id' => 5, 'title' => 'Hosting plan', 'status' => 'active']);

    return $db;
}

function actionFailuresContainer(Connection $db, ?object $email = null): Pimple\Container
{
    $di = new Pimple\Container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $url = Mockery::mock(\ClientFence\Url::class);
    $url->shouldReceive('adminLink')->andReturnUsing(fn (string $path): string => 'https://billing.test/admin/' . $path);
    $di['url'] = $url;
    $di['mod_service'] = $di->protect(fn (string $mod): ?object => $mod === 'email' ? $email : null);

    return $di;
}

test('a failure opens one entry per order and action and counts later attempts', function (): void {
    $db = actionFailuresDb();

    $id = ActionFailures::record($db, 7, 'suspend', 'Panel refused', '2026-10-15 10:00:00');
    expect($id)->toBeInt()
        ->and(ActionFailures::record($db, 7, 'suspend', 'Panel timed out', '2026-10-15 11:00:00'))->toBeNull()
        ->and(ActionFailures::record($db, 7, 'renew', 'Registrar down', '2026-10-15 11:05:00'))->toBeInt()
        // Not an order service action: ignored.
        ->and(ActionFailures::record($db, 7, 'reboot', 'x', '2026-10-15 11:05:00'))->toBeNull();

    $row = $db->fetchAssociative('SELECT * FROM ' . ActionFailures::TABLE . ' WHERE id = ?', [$id]);
    expect((int) $row['attempts'])->toBe(2)
        ->and($row['message'])->toBe('Panel timed out')
        ->and($row['first_failed_at'])->toBe('2026-10-15 10:00:00')
        ->and($row['last_failed_at'])->toBe('2026-10-15 11:00:00')
        ->and((int) $db->fetchOne('SELECT COUNT(*) FROM ' . ActionFailures::TABLE))->toBe(2);
});

test('a long or empty error is kept short and readable', function (): void {
    $db = actionFailuresDb();
    ActionFailures::record($db, 7, 'create', str_repeat('é', ActionFailures::MAX_MESSAGE + 50), '2026-10-15 10:00:00');
    ActionFailures::record($db, 7, 'delete', '   ', '2026-10-15 10:00:00');

    $messages = $db->fetchFirstColumn('SELECT message FROM ' . ActionFailures::TABLE . ' ORDER BY id');
    expect(mb_strlen($messages[0]))->toBe(ActionFailures::MAX_MESSAGE)
        ->and($messages[1])->toBe('No error message was given.');
});

test('a success closes what it fixes and a new failure opens a new entry', function (): void {
    $db = actionFailuresDb();
    ActionFailures::record($db, 7, 'create', 'No IP left', '2026-10-15 10:00:00');
    ActionFailures::record($db, 7, 'suspend', 'Panel refused', '2026-10-15 10:00:00');

    expect(ActionFailures::fixedBy('activate'))->toBe(['create', 'activate'])
        ->and(ActionFailures::fixedBy('suspend'))->toBe(['suspend', 'unsuspend', 'uncancel'])
        ->and(ActionFailures::resolve($db, 7, ActionFailures::fixedBy('activate'), '2026-10-15 12:00:00'))->toBe(1)
        ->and(ActionFailures::resolve($db, 8, ['suspend'], '2026-10-15 12:00:00'))->toBe(0);

    $statuses = $db->fetchAllKeyValue('SELECT action, status FROM ' . ActionFailures::TABLE);
    expect($statuses)->toBe(['create' => 'resolved', 'suspend' => 'open']);

    // The closed entry stays closed; the next failure of the same action starts again.
    expect(ActionFailures::record($db, 7, 'create', 'Again', '2026-10-15 13:00:00'))->toBeInt();
});

test('the list joins the order and client, filters and pages', function (): void {
    $db = actionFailuresDb();
    ActionFailures::record($db, 7, 'suspend', 'one', '2026-10-15 10:00:00');
    ActionFailures::record($db, 7, 'renew', 'two', '2026-10-15 11:00:00');
    ActionFailures::record($db, 7, 'uncancel', 'three', '2026-10-15 11:30:00');
    ActionFailures::record($db, 99, 'delete', 'four', '2026-10-15 12:00:00');
    ActionFailures::resolve($db, 7, ['suspend'], '2026-10-15 13:00:00', 1);

    $open = ActionFailures::getList($db, ActionFailures::STATUS_OPEN, 1, 1);
    expect($open['total'])->toBe(2)
        ->and($open['pages'])->toBe(2)
        ->and($open['list'])->toHaveCount(1)
        // Newest first.
        ->and($open['list'][0])->toMatchArray(['order_id' => 7, 'action' => 'uncancel']);

    $second = ActionFailures::getList($db, ActionFailures::STATUS_OPEN, 2, 1)['list'][0];
    expect($second)->toMatchArray(['order_id' => 7, 'order_title' => 'Hosting plan', 'order_status' => 'active', 'client_id' => 5, 'client_name' => 'Alice Test', 'action' => 'renew', 'attempts' => 1]);

    $resolved = ActionFailures::getList($db, ActionFailures::STATUS_RESOLVED, 1, 25);
    // The order of #99 is gone: its entry was closed, without a title or client.
    $dismissed = ActionFailures::getList($db, ActionFailures::STATUS_DISMISSED, 1, 25);
    expect($resolved['list'][0])->toMatchArray(['action' => 'suspend', 'status' => 'resolved', 'resolved_by' => 1, 'resolved_at' => '2026-10-15 13:00:00'])
        ->and($dismissed['list'][0])->toMatchArray(['order_id' => 99, 'order_title' => null, 'client_id' => null, 'client_name' => null, 'action' => 'delete', 'resolved_by' => null])
        ->and(ActionFailures::getList($db, '', 1, 25)['total'])->toBe(4)
        ->and(ActionFailures::getList($db, '', 1, 25, 7)['total'])->toBe(3);
});

test('failed() emails staff once per new entry and succeeded() closes it', function (): void {
    $db = actionFailuresDb();
    $email = Mockery::mock();
    $email->shouldReceive('sendTemplate')->once()->withArgs(function (array $data): bool {
        return $data['to_staff'] === true
            && $data['code'] === 'mod_staff_order_action_failed'
            && $data['failure']['order_id'] === 7
            && $data['failure']['client_name'] === 'Alice Test'
            && $data['failure']['order_url'] === 'https://billing.test/admin/order/manage/7'
            && $data['failure']['list_url'] === 'https://billing.test/admin/order/failed-actions';
    });
    $di = actionFailuresContainer($db, $email);

    ActionFailures::failed($di, 7, 'suspend', new RuntimeException('Panel refused'));
    ActionFailures::failed($di, 7, 'suspend', new RuntimeException('Panel refused again'));
    expect(ActionFailures::openCount($di))->toBe(1);

    ActionFailures::succeeded($di, 7, 'suspend');
    expect(ActionFailures::openCount($di))->toBe(0);
});

test('recording never breaks the order action', function (): void {
    // No table: the failure is only logged and the dashboard shows nothing.
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $di = actionFailuresContainer($db);

    ActionFailures::failed($di, 7, 'suspend', new RuntimeException('Panel refused'));
    ActionFailures::succeeded($di, 7, 'suspend');
    expect(ActionFailures::openCount($di))->toBe(0)
        ->and(ActionFailures::openCount(new Pimple\Container()))->toBe(0);
});

test('only open entries can be dismissed or retried', function (): void {
    $db = actionFailuresDb();
    $di = actionFailuresContainer($db);
    $id = ActionFailures::record($db, 7, 'suspend', 'Panel refused', '2026-10-15 10:00:00');

    expect(ActionFailures::dismiss($di, $id, 3))->toBeTrue();
    $row = $db->fetchAssociative('SELECT status, resolved_by FROM ' . ActionFailures::TABLE . ' WHERE id = ?', [$id]);
    expect($row)->toBe(['status' => 'dismissed', 'resolved_by' => 3]);

    expect(fn () => ActionFailures::dismiss($di, $id, 3))->toThrow(InformationException::class, 'already closed')
        ->and(fn () => ActionFailures::retry($di, $id, 3))->toThrow(InformationException::class, 'already closed')
        ->and(fn () => ActionFailures::retry($di, 12345, 3))->toThrow(InformationException::class, 'not found');
});

test('a success closes the failures it makes stale, so no old action can be retried', function (): void {
    $db = actionFailuresDb();
    // Cron could not suspend or cancel; then the client paid and the renewal went through.
    ActionFailures::record($db, 7, 'suspend', 'Panel down', '2026-10-15 10:00:00');
    ActionFailures::record($db, 7, 'cancel', 'Panel down', '2026-10-15 10:00:00');
    ActionFailures::record($db, 7, 'create', 'No IP left', '2026-10-15 10:00:00');

    ActionFailures::record($db, 7, 'renew', 'Panel down', '2026-10-15 10:00:00');
    ActionFailures::closeAfterSuccess($db, 7, 'renew', '2026-10-15 12:00:00', 4);
    expect($db->fetchAllKeyValue('SELECT action, status FROM ' . ActionFailures::TABLE))
        ->toBe(['suspend' => 'dismissed', 'cancel' => 'dismissed', 'create' => 'open', 'renew' => 'resolved'])
        ->and($db->fetchAllKeyValue('SELECT action, resolved_by FROM ' . ActionFailures::TABLE . ' WHERE status <> ?', ['open']))
        ->toBe(['suspend' => null, 'cancel' => null, 'renew' => 4])
        ->and(ActionFailures::fixedBy('delete'))->toBe(ActionFailures::ACTIONS);

    foreach (ActionFailures::ACTIONS as $action) {
        expect(ActionFailures::fixedBy($action))->toContain($action);
    }
});

test('a retry runs only while the order status still fits the action', function (): void {
    $db = actionFailuresDb();
    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->status = 'active';
    $dbFacade = Mockery::mock();
    $dbFacade->shouldReceive('load')->with('ClientOrder', 7)->andReturn($order);
    $di = actionFailuresContainer($db);
    $di['db'] = $dbFacade;

    $id = ActionFailures::record($db, 7, 'unsuspend', 'Panel down', '2026-10-15 10:00:00');
    expect(fn () => ActionFailures::retry($di, $id, 1))->toThrow(InformationException::class, 'no longer fits')
        ->and(ActionFailures::retryStatuses('suspend'))->toBe(['active'])
        ->and(ActionFailures::retryStatuses('uncancel'))->toBe(['canceled'])
        ->and(ActionFailures::retryStatuses('delete'))->toBeNull();
});

test('entries of removed orders close, and a missing table lists nothing', function (): void {
    $db = actionFailuresDb();
    ActionFailures::record($db, 7, 'suspend', 'Panel down', '2026-10-15 10:00:00');
    ActionFailures::record($db, 99, 'delete', 'Panel down', '2026-10-15 10:00:00');

    expect(ActionFailures::openCount(actionFailuresContainer($db)))->toBe(1)
        ->and(ActionFailures::getList($db, 'dismissed', 1, 25)['list'][0]['order_id'])->toBe(99);

    $empty = ActionFailures::getList(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), 'open', 1, 25);
    expect($empty)->toBe(['list' => [], 'total' => 0, 'page' => 1, 'per_page' => 25, 'pages' => 1]);
});

test('a retry of renew or unsuspend applies the paid renewal line first', function (): void {
    $db = actionFailuresDb();
    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->status = 'suspended';
    $dbFacade = Mockery::mock();
    $dbFacade->shouldReceive('load')->with('ClientOrder', 7)->andReturn($order);
    $orderService = Mockery::mock();
    $orderService->shouldReceive('applyPaidRenewal')->once()->with(7)->andReturn(true);
    $orderService->shouldNotReceive('unsuspendFromOrder');
    $di = actionFailuresContainer($db);
    $di['db'] = $dbFacade;
    $di['mod_service'] = $di->protect(fn (): object => $orderService);

    $id = ActionFailures::record($db, 7, 'unsuspend', 'Panel down', '2026-10-15 10:00:00');
    expect(ActionFailures::retry($di, $id, 1))->toBeTrue()
        ->and($db->fetchOne('SELECT status FROM ' . ActionFailures::TABLE . ' WHERE id = ?', [$id]))->toBe('resolved');
});
