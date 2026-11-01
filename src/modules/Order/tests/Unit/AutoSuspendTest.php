<?php

declare(strict_types=1);

/**
 * ClientFence: one rule set for automatic suspension and cancellation in the Orders service
 * (batchSuspendExpired(), batchCancelSuspended(), isExemptFromAutoSuspension()), the WHMCS-style
 * "Override Auto-Suspend: do not suspend until [date]" kept in the order's meta, and what clients
 * see of an order (no staff notes, no staff-only meta).
 */

use ClientFence\Mod\Order\Entity\Order;
use ClientFence\Mod\Order\Entity\OrderMeta;
use ClientFence\Mod\Order\Repository\OrderMetaRepository;
use ClientFence\Mod\Order\Repository\OrderRepository;
use ClientFence\Mod\Order\Service;

use function Tests\Helpers\container;
use function Tests\Helpers\createEntity;

/**
 * @param array<int, array<string, string>> $meta order id => the order's meta
 */
function autoSuspendMetaRepository(array $meta): OrderMetaRepository
{
    $repository = Mockery::mock(OrderMetaRepository::class);
    $repository->shouldReceive('getPairsForOrder')->andReturnUsing(fn (int $id): array => $meta[$id] ?? []);

    return $repository;
}

/**
 * @param array<string, mixed>              $config the Orders settings
 * @param array<int, array<string, string>> $meta   order id => the order's meta
 */
function autoSuspendService(array $config, OrderRepository $repository, array $meta = []): Service
{
    $service = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();

    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getRepository')->with(Order::class)->andReturn($repository);
    $em->shouldReceive('getRepository')->with(OrderMeta::class)->andReturn(autoSuspendMetaRepository($meta));
    $em->shouldReceive('refresh');
    // batchCancelSuspended(): suspended orders past their due date only (not staff suspensions
    // of orders that are paid up).
    $em->shouldReceive('getConnection')->andReturn(Mockery::mock(Doctrine\DBAL\Connection::class)
        ->shouldReceive('fetchAllAssociative')
        ->with(Mockery::on(fn (string $sql): bool => str_contains($sql, 'expires_at <= NOW()')), Mockery::any())
        ->andReturn([['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4]])->getMock());

    $di = container();
    $di['em'] = $em;
    $di['events_manager'] = Mockery::mock('ClientFence\EventManager')->shouldIgnoreMissing();
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['mod'] = $di->protect(fn (string $name): object => new class($config) {
        public function __construct(private readonly array $config)
        {
        }

        public function getConfig(): array
        {
            return $this->config;
        }
    });
    $service->setDi($di);

    return $service;
}

/** @return array<string, string> the override ticked with that end date */
function autoSuspendOverride(string $until = ''): array
{
    return [Service::META_AUTO_SUSPEND_OVERRIDE => '1', Service::META_AUTO_SUSPEND_UNTIL => $until];
}

test('the auto-suspend override is on while ticked, with no date or up to and including the date', function (array $meta, bool $active): void {
    expect(Service::autoSuspendOverrideActive($meta))->toBe($active);
})->with([
    'no meta' => [[], false],
    'ticked, no date' => [[Service::META_AUTO_SUSPEND_OVERRIDE => '1'], true],
    'ticked, blank date' => [autoSuspendOverride(), true],
    'ticked, later date' => [autoSuspendOverride('2999-01-01'), true],
    'ticked, today (to the end of the day)' => [autoSuspendOverride(date('Y-m-d')), true],
    'ticked, yesterday' => [autoSuspendOverride(date('Y-m-d', strtotime('-1 day'))), false],
    'ticked, date passed' => [autoSuspendOverride('2000-01-01'), false],
    'ticked, not a date' => [autoSuspendOverride('soon'), false],
    'unticked, later date' => [[Service::META_AUTO_SUSPEND_OVERRIDE => '0', Service::META_AUTO_SUSPEND_UNTIL => '2999-01-01'], false],
]);

test('exempt from automatic suspension: domains, and orders whose override is on', function (?string $type, array $meta, bool $exempt): void {
    $order = createEntity(Order::class, ['id' => 1, 'serviceType' => $type]);
    $service = autoSuspendService([], Mockery::mock(OrderRepository::class), [1 => $meta]);

    expect($service->isExemptFromAutoSuspension($order))->toBe($exempt);
})->with([
    'hosting' => ['hosting', [], false],
    'domain' => ['domain', [], true],
    'override on' => ['custom', autoSuspendOverride(), true],
    'override ended' => ['hosting', autoSuspendOverride('2000-01-01'), false],
    'notes are not read' => ['hosting', ['notes' => '[clientfence:no-auto-suspend]'], false],
]);

test('batchSuspendExpired waits the configured days past the expiry and skips exempt orders', function (mixed $setting, int $days): void {
    $orders = [
        createEntity(Order::class, ['id' => 1, 'serviceType' => 'hosting', 'status' => Order::STATUS_ACTIVE, 'expiresAt' => new DateTime('-1 year')]),
        createEntity(Order::class, ['id' => 2, 'serviceType' => 'domain', 'status' => Order::STATUS_ACTIVE, 'expiresAt' => new DateTime('-1 year')]),
        createEntity(Order::class, ['id' => 3, 'serviceType' => 'hosting', 'status' => Order::STATUS_ACTIVE, 'expiresAt' => new DateTime('-1 year')]),
        createEntity(Order::class, ['id' => 4, 'serviceType' => 'hosting', 'status' => Order::STATUS_ACTIVE, 'expiresAt' => new DateTime('-1 year')]),
    ];

    $repository = Mockery::mock(OrderRepository::class);
    $repository->shouldReceive('getExpired')->once()
        ->with(Mockery::on(fn (DateTimeInterface $before): bool => abs($before->getTimestamp() - strtotime("-{$days} days")) < 60))
        ->andReturn($orders);
    $repository->shouldReceive('find')->andReturnUsing(fn (int $id): Order => $orders[$id - 1]);

    $config = ['batch_suspend_reason' => 'Overdue'];
    if ($setting !== null) {
        $config['batch_suspend_after_days'] = $setting;
    }
    $service = autoSuspendService($config, $repository, [3 => autoSuspendOverride(), 4 => autoSuspendOverride('2000-01-01')]);
    // Every call is recorded (the batch catches exceptions, so an unexpected call must not fail
    // silently inside it).
    $suspended = [];
    $service->shouldReceive('suspendFromOrder')->andReturnUsing(function (Order $order, ?string $reason) use (&$suspended): bool {
        $suspended[] = [$order->getId(), $reason];

        return true;
    });

    expect($service->batchSuspendExpired())->toBeTrue()
        ->and($suspended)->toBe([[1, 'Overdue'], [4, 'Overdue']]);
})->with([
    'default (WHMCS: 5)' => [null, Service::BATCH_SUSPEND_AFTER_DAYS],
    'blank means the default' => ['', Service::BATCH_SUSPEND_AFTER_DAYS],
    'set' => ['9', 9],
    'on the due date' => ['0', 0],
]);

test('batchSuspendExpired cancels an expired order whose cancellation staff scheduled, instead of suspending it', function (): void {
    $orders = [
        createEntity(Order::class, ['id' => 1, 'serviceType' => 'hosting', 'status' => Order::STATUS_ACTIVE, 'expiresAt' => new DateTime('-1 year')]),
        createEntity(Order::class, ['id' => 2, 'serviceType' => 'hosting', 'status' => Order::STATUS_ACTIVE, 'expiresAt' => new DateTime('-1 year')]),
    ];
    $repository = Mockery::mock(OrderRepository::class);
    $repository->shouldReceive('getExpired')->once()->andReturn($orders);
    $repository->shouldReceive('find')->andReturnUsing(fn (int $id): Order => $orders[$id - 1]);
    $service = autoSuspendService(['batch_suspend_reason' => 'Overdue'], $repository, [1 => [Service::META_CANCEL_AT_PERIOD_END => '1']]);
    $service->shouldReceive('cancelFromOrder')->once()->with($orders[0], 'Cancellation scheduled at the end of the period')->andReturn(true);
    $service->shouldReceive('suspendFromOrder')->once()->with($orders[1], 'Overdue')->andReturn(true);

    expect($service->batchSuspendExpired())->toBeTrue();
});

test('batchSuspendExpired rechecks renewed orders under the paid-task lock', function (string $status, string $expiry): void {
    $order = createEntity(Order::class, ['id' => 1, 'status' => Order::STATUS_ACTIVE, 'expiresAt' => new DateTime('-1 year')]);
    $repository = Mockery::mock(OrderRepository::class);
    $repository->shouldReceive('getExpired')->once()->andReturn([$order]);
    $repository->shouldReceive('find')->once()->with(1)->andReturn($order);

    $locked = $refreshedUnderLock = false;
    $connection = Mockery::mock(Doctrine\DBAL\Connection::class);
    $connection->shouldReceive('getDatabasePlatform')->andReturn(new Doctrine\DBAL\Platforms\MySQLPlatform());
    $connection->shouldReceive('fetchOne')->once()->with('SELECT GET_LOCK(?, ?)', ['fb:order_task:1', 10])
        ->andReturnUsing(function () use (&$locked): int {
            $locked = true;

            return 1;
        });
    $connection->shouldReceive('fetchOne')->once()->with('SELECT RELEASE_LOCK(?)', ['fb:order_task:1'])
        ->andReturnUsing(function () use (&$locked): int {
            $locked = false;

            return 1;
        });
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getRepository')->with(Order::class)->andReturn($repository);
    $em->shouldReceive('refresh')->once()->with($order)->andReturnUsing(function () use ($order, $status, $expiry, &$locked, &$refreshedUnderLock): void {
        $refreshedUnderLock = $locked;
        $order->setStatus($status);
        $order->setExpiresAt(new DateTime($expiry));
    });
    $di = container();
    $di['em'] = $em;
    $di['dbal'] = $connection;
    $service = Mockery::mock(Service::class)->makePartial();
    $suspended = [];
    $service->shouldReceive('suspendFromOrder')->andReturnUsing(function (Order $order) use (&$suspended): bool {
        $suspended[] = $order->getId();

        return true;
    });
    $service->setDi($di);

    expect($service->batchSuspendExpired())->toBeTrue()
        ->and($refreshedUnderLock)->toBeTrue()
        ->and($suspended)->toBe([])
        ->and($locked)->toBeFalse();
})->with([
    'no longer active' => [Order::STATUS_SUSPENDED, '-1 year'],
    'expiry extended' => [Order::STATUS_ACTIVE, '+1 month'],
    'inside the suspension grace period' => [Order::STATUS_ACTIVE, '-1 day'],
]);

test('automatic order actions leave paid lines awaiting execution alone', function (string $batch, string $action, string $status): void {
    $orders = [
        1 => createEntity(Order::class, ['id' => 1, 'status' => $status, 'expiresAt' => new DateTime('-1 year')]),
        2 => createEntity(Order::class, ['id' => 2, 'status' => $status, 'expiresAt' => new DateTime('-1 year')]),
    ];
    $repository = Mockery::mock(OrderRepository::class);
    $repository->shouldReceive('find')->andReturnUsing(fn (int $id): Order => $orders[$id]);
    $repository->shouldReceive('getExpired')->andReturn(array_values($orders));
    $connection = Mockery::mock(Doctrine\DBAL\Connection::class);
    $connection->shouldReceive('fetchAllAssociative')->andReturn([['id' => 1], ['id' => 2]]);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getRepository')->with(Order::class)->andReturn($repository);
    $em->shouldReceive('getRepository')->with(OrderMeta::class)->andReturn(autoSuspendMetaRepository([]));
    $em->shouldReceive('getConnection')->andReturn($connection);
    $em->shouldReceive('refresh');

    $dbal = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $dbal->executeStatement('CREATE TABLE invoice (id INTEGER PRIMARY KEY, status TEXT)');
    $dbal->executeStatement('CREATE TABLE invoice_item (invoice_id INTEGER, type TEXT, rel_id TEXT, task TEXT, status TEXT)');
    $dbal->insert('invoice', ['id' => 40, 'status' => 'paid']);
    $dbal->insert('invoice_item', ['invoice_id' => 40, 'type' => 'order', 'rel_id' => '1', 'task' => 'renew', 'status' => 'pending_setup']);
    // A failed first activation never runs again and must not protect the service.
    $dbal->insert('invoice_item', ['invoice_id' => 40, 'type' => 'order', 'rel_id' => '2', 'task' => 'activate', 'status' => 'failed']);
    $di = container();
    $di['em'] = $em;
    $di['dbal'] = $dbal;
    $di['mod_config'] = $di->protect(fn (): array => ['batch_cancel_suspended' => '1']);
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('isExemptFromAutoSuspension')->andReturn(false);
    $changed = [];
    $service->shouldReceive($action)->andReturnUsing(function (Order $order) use (&$changed): bool {
        $changed[] = $order->getId();

        return true;
    });
    $service->setDi($di);

    expect($service->{$batch}())->toBeTrue()
        ->and($changed)->toBe([2]);
})->with([
    'cancellation' => ['batchCancelSuspended', 'cancelFromOrder', Order::STATUS_SUSPENDED],
    'suspension' => ['batchSuspendExpired', 'suspendFromOrder', Order::STATUS_ACTIVE],
]);

test('batchCancelSuspended cancels overdue suspended orders only, skipping domains and overridden orders', function (): void {
    $orders = [
        1 => createEntity(Order::class, ['id' => 1, 'serviceType' => 'hosting', 'status' => Order::STATUS_SUSPENDED, 'expiresAt' => new DateTime('-1 day')]),
        2 => createEntity(Order::class, ['id' => 2, 'serviceType' => 'domain', 'status' => Order::STATUS_SUSPENDED, 'expiresAt' => new DateTime('-1 day')]),
        3 => createEntity(Order::class, ['id' => 3, 'serviceType' => 'hosting', 'status' => Order::STATUS_SUSPENDED, 'expiresAt' => new DateTime('-1 day')]),
        4 => createEntity(Order::class, ['id' => 4, 'serviceType' => 'hosting', 'status' => Order::STATUS_SUSPENDED, 'expiresAt' => new DateTime('-1 day')]),
    ];
    $repository = Mockery::mock(OrderRepository::class);
    $repository->shouldReceive('find')->andReturnUsing(fn (int $id): Order => $orders[$id]);

    $service = autoSuspendService(
        ['batch_cancel_suspended' => '1', 'batch_cancel_suspended_after_days' => '24', 'batch_cancel_suspended_reason' => 'Unpaid'],
        $repository,
        [3 => autoSuspendOverride('2999-01-01'), 4 => autoSuspendOverride('2000-01-01')],
    );
    $cancelled = [];
    $service->shouldReceive('cancelFromOrder')->andReturnUsing(function (Order $order, ?string $reason) use (&$cancelled): bool {
        $cancelled[] = [$order->getId(), $reason];

        return true;
    });

    expect($service->batchCancelSuspended())->toBeTrue()
        ->and($cancelled)->toBe([[1, 'Unpaid'], [4, 'Unpaid']]);
});

test('batchCancelSuspended skips orders renewed since selection under the paid-task lock', function (string $status, string $expiry): void {
    $order = createEntity(Order::class, ['id' => 1, 'serviceType' => 'hosting', 'status' => Order::STATUS_SUSPENDED, 'expiresAt' => new DateTime('-1 day')]);
    $repository = Mockery::mock(OrderRepository::class);
    $repository->shouldReceive('find')->once()->with(1)->andReturn($order);

    $locked = $refreshedUnderLock = false;
    $connection = Mockery::mock(Doctrine\DBAL\Connection::class);
    $connection->shouldReceive('getDatabasePlatform')->andReturn(new Doctrine\DBAL\Platforms\MySQLPlatform());
    $connection->shouldReceive('fetchAllAssociative')->once()->andReturn([['id' => 1]]);
    $connection->shouldReceive('fetchOne')->once()->with('SELECT GET_LOCK(?, ?)', ['fb:order_task:1', 10])
        ->andReturnUsing(function () use (&$locked): int {
            $locked = true;

            return 1;
        });
    $connection->shouldReceive('fetchOne')->once()->with('SELECT RELEASE_LOCK(?)', ['fb:order_task:1'])
        ->andReturnUsing(function () use (&$locked): int {
            $locked = false;

            return 1;
        });
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getRepository')->with(Order::class)->andReturn($repository);
    $em->shouldReceive('getConnection')->andReturn($connection);
    $em->shouldReceive('refresh')->once()->with($order)->andReturnUsing(function () use ($order, $status, $expiry, &$locked, &$refreshedUnderLock): void {
        $refreshedUnderLock = $locked;
        $order->setStatus($status);
        $order->setExpiresAt(new DateTime($expiry));
    });

    $di = container();
    $di['em'] = $em;
    $di['dbal'] = $connection;
    $di['mod_config'] = $di->protect(fn (string $name): array => ['batch_cancel_suspended' => '1']);
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('isExemptFromAutoSuspension')->andReturn(false);
    $cancelled = [];
    $service->shouldReceive('cancelFromOrder')->andReturnUsing(function (Order $order) use (&$cancelled): bool {
        $cancelled[] = $order->getId();

        return true;
    });
    $service->setDi($di);

    expect($service->batchCancelSuspended())->toBeTrue()
        ->and($refreshedUnderLock)->toBeTrue()
        ->and($cancelled)->toBe([])
        ->and($locked)->toBeFalse();
})->with([
    'unsuspended' => [Order::STATUS_ACTIVE, '-1 day'],
    'expiry extended' => [Order::STATUS_SUSPENDED, '+1 month'],
]);

test('the Edit Order tab saves the override in the order meta', function (array $posted, array $meta): void {
    $order = createEntity(Order::class, ['id' => 5, 'price' => 10]);
    $di = container();
    $di['events_manager'] = Mockery::mock('ClientFence\EventManager')->shouldIgnoreMissing();
    $di['em'] = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class)->shouldIgnoreMissing();
    $di['logger'] = new \ClientFence\Log();

    $service = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('updatePeriod');
    $saved = [];
    $service->shouldReceive('updateOrderMeta')->andReturnUsing(function (Order $o, $values) use (&$saved): int {
        if (is_array($values)) {
            $saved[] = $values;
        }

        return 2;
    });
    $service->setDi($di);

    expect($service->updateOrder($order, $posted + ['price' => 10]))->toBeTrue()
        ->and($saved)->toBe([$meta]);
})->with([
    'ticked with a date' => [
        ['auto_suspend_override' => '1', 'auto_suspend_until' => ' 2026-12-01 '],
        [Service::META_AUTO_SUSPEND_OVERRIDE => '1', Service::META_AUTO_SUSPEND_UNTIL => '2026-12-01'],
    ],
    'ticked, no date' => [
        ['auto_suspend_override' => '1', 'auto_suspend_until' => ''],
        [Service::META_AUTO_SUSPEND_OVERRIDE => '1', Service::META_AUTO_SUSPEND_UNTIL => ''],
    ],
    'unticked clears the date' => [
        ['auto_suspend_override' => '0', 'auto_suspend_until' => '2026-12-01'],
        [Service::META_AUTO_SUSPEND_OVERRIDE => '0', Service::META_AUTO_SUSPEND_UNTIL => ''],
    ],
]);

test('an update without the override fields leaves it as it is', function (): void {
    $order = createEntity(Order::class, ['id' => 5]);
    $di = container();
    $di['events_manager'] = Mockery::mock('ClientFence\EventManager')->shouldIgnoreMissing();
    $di['em'] = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class)->shouldIgnoreMissing();
    $di['logger'] = new \ClientFence\Log();

    $service = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('updatePeriod');
    $service->shouldReceive('updateOrderMeta')->once()->with($order, null)->andReturn(0);
    $service->setDi($di);

    expect($service->updateOrder($order, ['title' => 'Kept']))->toBeTrue();
});

test('a wrong "do not suspend until" date saves nothing', function (string $until): void {
    $order = createEntity(Order::class, ['id' => 5, 'title' => 'Before']);
    $di = container();
    $di['events_manager'] = Mockery::mock('ClientFence\EventManager')->shouldIgnoreMissing();
    $di['em'] = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $di['em']->shouldNotReceive('flush');

    $service = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldNotReceive('updatePeriod');
    $service->shouldNotReceive('updateOrderMeta');
    $service->setDi($di);

    expect(fn () => $service->updateOrder($order, ['title' => 'After', 'auto_suspend_override' => '1', 'auto_suspend_until' => $until]))
        ->toThrow(ClientFence\InformationException::class);
    expect($order->getTitle())->toBe('Before');
})->with(['2026-02-30', '01/12/2026', 'next week']);

/**
 * getBatchForApi() for one order with staff notes and meta, as the given identity sees it.
 */
function autoSuspendBatchOrder(object $identity): array
{
    $connection = Mockery::mock(Doctrine\DBAL\Connection::class);
    $connection->shouldReceive('fetchAllAssociative')
        ->with('SELECT * FROM client_order WHERE id IN (?)', [11])
        ->andReturn([['id' => 11, 'client_id' => 7, 'product_id' => 3, 'config' => '{}', 'price' => 10, 'quantity' => 1, 'title' => 'Hosting', 'notes' => 'Client pays late']]);
    $connection->shouldReceive('fetchAllAssociative')
        ->with(Mockery::on(fn (string $sql): bool => str_contains($sql, 'FROM client_order_meta')), [11])
        ->andReturn([
            ['client_order_id' => 11, 'name' => Service::META_AUTO_SUSPEND_OVERRIDE, 'value' => '1'],
            ['client_order_id' => 11, 'name' => Service::META_AUTO_SUSPEND_UNTIL, 'value' => '2999-01-01'],
            ['client_order_id' => 11, 'name' => Service::META_CANCEL_AT_PERIOD_END, 'value' => '1'],
        ]);
    $connection->shouldReceive('fetchAllAssociative')->andReturn([]);

    $clientRepository = Mockery::mock(ClientFence\Mod\Client\Repository\ClientRepository::class);
    $clientRepository->shouldReceive('findBy')->andReturn([createEntity(ClientFence\Mod\Client\Entity\Client::class, ['id' => 7])]);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($connection);
    $em->shouldReceive('getRepository')->andReturn($clientRepository);

    $clientService = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $clientService->shouldReceive('toApiArray')->andReturn(['id' => 7]);
    $productService = Mockery::mock(ClientFence\Mod\Product\Service::class);
    $productService->shouldReceive('getProductPluginMap')->andReturn([]);

    $di = container();
    $di['em'] = $em;
    $di['mod_service'] = $di->protect(fn (string $name) => $name === 'client' ? $clientService : $productService);
    $service = new Service();
    $service->setDi($di);

    return $service->getBatchForApi([11], $identity)[0];
}

test('clients get neither the staff notes nor the staff-only meta of their orders', function (): void {
    $order = autoSuspendBatchOrder(createEntity(ClientFence\Mod\Client\Entity\Client::class, ['id' => 7]));

    expect($order)->not->toHaveKey('notes')
        ->and($order)->not->toHaveKey('auto_suspend_off')
        ->and($order['meta'])->toBe([Service::META_CANCEL_AT_PERIOD_END => '1']);
});

test('staff get the notes, the meta and whether auto-suspend is off', function (): void {
    $order = autoSuspendBatchOrder(createEntity(ClientFence\Mod\Staff\Entity\Admin::class));

    expect($order['notes'])->toBe('Client pays late')
        ->and($order['auto_suspend_off'])->toBeTrue()
        ->and($order['meta'])->toHaveKey(Service::META_AUTO_SUSPEND_UNTIL);
});

test('the client order API reads as the client and cannot filter on the order meta', function (): void {
    $client = createEntity(ClientFence\Mod\Client\Entity\Client::class, ['id' => 7]);
    $order = createEntity(Order::class, ['id' => 11]);

    $service = Mockery::mock(Service::class);
    $service->shouldReceive('toApiArray')->once()->with($order, true, $client)->andReturn(['id' => 11]);
    $service->shouldReceive('getSearchQuery')->once()
        ->with(Mockery::on(fn (array $data): bool => !array_key_exists('meta', $data) && $data['client_id'] === 7))
        ->andReturn(['query', []]);
    $service->shouldReceive('getBatchForApi')->once()->with([], $client)->andReturn([]);

    $pager = Mockery::mock(ClientFence\Pagination::class);
    $pager->shouldReceive('getPaginatedResultSet')->andReturn(['list' => []]);
    $di = container();
    $di['pager'] = $pager;

    $api = Mockery::mock(ClientFence\Mod\Order\Api\Client::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $api->shouldReceive('_getOrder')->andReturn($order);
    $api->setDi($di);
    $api->setIdentity($client);
    $api->setService($service);

    expect($api->get(['id' => 11]))->toBe(['id' => 11])
        ->and($api->get_list(['meta' => ['AUTO_SUSPEND_OVERRIDE ' => '1']]))->toBe(['list' => []]);
});

test('the order search lists each order once and checks each meta filter on its own', function (): void {
    [$query, $bindings] = (new Service())->getSearchQuery(['meta' => ['a' => '1', 'b' => '2']]);

    expect($query)->not->toContain('JOIN client_order_meta')
        ->and($query)->toContain('EXISTS (SELECT 1 FROM client_order_meta meta1 WHERE meta1.client_order_id = co.id AND meta1.name = :meta_name1')
        ->and($query)->toContain('EXISTS (SELECT 1 FROM client_order_meta meta2 WHERE meta2.client_order_id = co.id AND meta2.name = :meta_name2')
        ->and($bindings)->toMatchArray(['meta_name1' => 'a', 'meta_value1' => '1%', 'meta_name2' => 'b', 'meta_value2' => '2%']);
});

test('the override cannot be set through meta (its date would skip the check)', function (string $name): void {
    $order = createEntity(Order::class, ['id' => 5]);
    $di = container();
    $di['events_manager'] = Mockery::mock('ClientFence\EventManager')->shouldIgnoreMissing();
    $di['em'] = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $di['em']->shouldNotReceive('flush');

    $service = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldNotReceive('updateOrderMeta');
    $service->setDi($di);

    expect(fn () => $service->updateOrder($order, ['meta' => ['other' => 'x', $name => '31/12/2026']]))
        ->toThrow(ClientFence\InformationException::class);
})->with([Service::META_AUTO_SUSPEND_UNTIL, Service::META_AUTO_SUSPEND_OVERRIDE, ' Auto_Suspend_Until ']);

test('an order is not created with the override in its meta', function (): void {
    $service = new Service();
    $service->setDi(container());

    expect(fn () => $service->createOrder(
        createEntity(ClientFence\Mod\Client\Entity\Client::class, ['id' => 7]),
        createEntity(ClientFence\Mod\Product\Entity\Product::class, ['id' => 3]),
        ['meta' => [Service::META_AUTO_SUSPEND_UNTIL => '2026-13-45']],
    ))->toThrow(ClientFence\InformationException::class, 'not in meta');
});
