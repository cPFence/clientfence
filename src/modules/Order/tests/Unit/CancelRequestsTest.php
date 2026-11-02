<?php

declare(strict_types=1);

/**
 * ClientFence: cancellation requests in their own list, as WHMCS (Order\CancelRequests), with a stand-in for the
 * Order service.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Order\CancelRequests;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

function cancelRequestDb(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE client_order_cancel_request (id INTEGER PRIMARY KEY AUTOINCREMENT, client_order_id INTEGER, client_id INTEGER, type TEXT, reason TEXT, status TEXT, created_at TEXT, updated_at TEXT)');
    $db->executeStatement('CREATE TABLE mod_clientfence_state (name TEXT PRIMARY KEY, value TEXT, updated_at TEXT)');

    return $db;
}

function cancelRequestContainer(Connection $db, object $orderService, array $config = []): Pimple\Container
{
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di = new Pimple\Container();
    $di['em'] = $em;
    $di['logger'] = new class {
        public array $errors = [];

        public function error(string $message, mixed ...$args): void
        {
            $this->errors[] = vsprintf($message, $args);
        }

        public function __call(string $name, array $args): void
        {
        }
    };
    $di['mod_service'] = $di->protect(static fn (): object => $orderService);
    $di['mod'] = $di->protect(static fn (): object => new class($config) {
        public function __construct(private array $config)
        {
        }

        public function getConfig(): array
        {
            return $this->config;
        }
    });

    return $di;
}

function cancelRequestOrder(string $status = 'active', int $id = 70): Model_ClientOrder
{
    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->id = $id;
    $order->client_id = 5;
    $order->status = $status;
    $order->title = 'Hosting';

    return $order;
}

test('a client request waits in the list with its type and reason, and the client gets a confirmation', function (): void {
    $db = cancelRequestDb();
    $orders = Mockery::mock();
    $orders->shouldReceive('canCancelAtPeriodEnd')->andReturn(true);
    $sent = [];
    $orders->shouldReceive('sendTemplate')->andReturnUsing(function (array $email) use (&$sent): bool {
        $sent[] = $email;

        return true;
    });
    $id = CancelRequests::create(cancelRequestContainer($db, $orders), cancelRequestOrder(), CancelRequests::END_OF_PERIOD, '  Too expensive ');

    expect($db->fetchAssociative('SELECT client_order_id, client_id, type, reason, status FROM client_order_cancel_request WHERE id = ?', [$id]))
        ->toBe(['client_order_id' => 70, 'client_id' => 5, 'type' => 'end_of_period', 'reason' => 'Too expensive', 'status' => 'pending']);
    expect($sent[0]['to_client'])->toBe(5)
        ->and($sent[0]['code'])->toBe('mod_order_cancel_request')
        ->and($sent[0]['request']['type'])->toBe('end_of_period')
        ->and($sent[0]['order']['title'])->toBe('Hosting');
});

test('a request is refused without a reason, with a bad type, for an inactive service, or twice', function (string $type, string $reason, string $status, bool $periodEnd, bool $twice, string $message): void {
    $db = cancelRequestDb();
    $orders = Mockery::mock();
    $orders->shouldReceive('canCancelAtPeriodEnd')->andReturn($periodEnd);
    $di = cancelRequestContainer($db, $orders);
    if ($twice) {
        CancelRequests::insert($db, 70, 5, CancelRequests::IMMEDIATE, 'First', '2026-11-01 10:00:00');
    }

    expect(fn () => CancelRequests::create($di, cancelRequestOrder($status), $type, $reason))->toThrow(InformationException::class, $message);
})->with([
    'no reason' => [CancelRequests::IMMEDIATE, ' ', 'active', true, false, 'Please tell us why'],
    'bad type' => ['later', 'Moving', 'active', true, false, 'Invalid cancellation type'],
    'suspended' => [CancelRequests::IMMEDIATE, 'Moving', 'suspended', true, false, 'Only an active service'],
    'no period end' => [CancelRequests::END_OF_PERIOD, 'Moving', 'active', false, false, 'cannot be cancelled at the end of its period'],
    'already waiting' => [CancelRequests::IMMEDIATE, 'Moving', 'active', true, true, 'already received'],
]);

test('the client withdraws only their own waiting request; staff stop one', function (): void {
    $db = cancelRequestDb();
    $di = cancelRequestContainer($db, Mockery::mock());
    $first = CancelRequests::insert($db, 70, 5, CancelRequests::IMMEDIATE, 'Moving', '2026-11-01 10:00:00');

    expect(fn () => CancelRequests::withdraw($di, 6, 70))->toThrow(InformationException::class, 'no cancellation request waiting');
    CancelRequests::withdraw($di, 5, 70);
    $second = CancelRequests::insert($db, 70, 5, CancelRequests::IMMEDIATE, 'Moving again', '2026-11-02 10:00:00');
    CancelRequests::stop($di, $second);

    expect($db->fetchAllKeyValue('SELECT id, status FROM client_order_cancel_request ORDER BY id'))->toBe([$first => 'withdrawn', $second => 'stopped'])
        ->and(fn () => CancelRequests::stop($di, $second))->toThrow(InformationException::class, 'no longer waiting')
        ->and(CancelRequests::pending($di, 70))->toBeNull();
});

test('accepting cancels the service now or at the end of its period, as the client asked', function (string $type, string $call): void {
    $db = cancelRequestDb();
    $order = cancelRequestOrder();
    $orders = Mockery::mock();
    $orders->shouldReceive('findByClientIdAndOrderId')->once()->with(5, 70)->andReturn($order);
    $id = CancelRequests::insert($db, 70, 5, $type, 'Moving', '2026-11-01 10:00:00');
    $orders->shouldReceive($call)->once()->with($order, 'Cancellation request #' . $id)->andReturn(true);

    CancelRequests::accept(cancelRequestContainer($db, $orders), $id);

    expect($db->fetchOne('SELECT status FROM client_order_cancel_request WHERE id = ?', [$id]))->toBe('accepted');
})->with([
    'immediate' => [CancelRequests::IMMEDIATE, 'cancelFromOrder'],
    'end of period' => [CancelRequests::END_OF_PERIOD, 'scheduleCancellationFromOrder'],
]);

test('accepting the request of a service staff cancelled already only closes it', function (): void {
    $db = cancelRequestDb();
    $orders = Mockery::mock();
    $orders->shouldReceive('findByClientIdAndOrderId')->andReturn(cancelRequestOrder('canceled'));
    $orders->shouldNotReceive('cancelFromOrder');
    $id = CancelRequests::insert($db, 70, 5, CancelRequests::IMMEDIATE, 'Moving', '2026-11-01 10:00:00');

    CancelRequests::accept(cancelRequestContainer($db, $orders), $id);

    expect($db->fetchOne('SELECT status FROM client_order_cancel_request WHERE id = ?', [$id]))->toBe('accepted');
});

test('the cron accepts the waiting requests once a day, only when the setting is on', function (): void {
    $db = cancelRequestDb();
    $ok = CancelRequests::insert($db, 70, 5, CancelRequests::IMMEDIATE, 'Moving', '2026-11-01 10:00:00');
    $failing = CancelRequests::insert($db, 71, 5, CancelRequests::IMMEDIATE, 'Moving', '2026-11-01 10:00:00');
    $orders = Mockery::mock();
    $orders->shouldReceive('findByClientIdAndOrderId')->with(5, 70)->andReturn(cancelRequestOrder());
    $orders->shouldReceive('findByClientIdAndOrderId')->with(5, 71)->andReturn(cancelRequestOrder('active', 71));
    $orders->shouldReceive('cancelFromOrder')->once()->withArgs(fn (Model_ClientOrder $o): bool => (int) $o->id === 70)->andReturn(true);
    $orders->shouldReceive('cancelFromOrder')->once()->withArgs(fn (Model_ClientOrder $o): bool => (int) $o->id === 71)->andThrow(new RuntimeException('panel down'));

    CancelRequests::run(cancelRequestContainer($db, $orders, ['cancel_requests_auto' => '0']));
    expect($db->fetchAllKeyValue('SELECT id, status FROM client_order_cancel_request ORDER BY id'))->toBe([$ok => 'pending', $failing => 'pending']);

    $di = cancelRequestContainer($db, $orders, ['cancel_requests_auto' => '1']);
    CancelRequests::run($di);
    CancelRequests::run($di);

    expect($db->fetchAllKeyValue('SELECT id, status FROM client_order_cancel_request ORDER BY id'))->toBe([$ok => 'accepted', $failing => 'pending'])
        ->and($di['logger']->errors)->toBe(['Order: cancellation request #' . $failing . ' was not processed: panel down'])
        ->and($db->fetchOne('SELECT value FROM mod_clientfence_state WHERE name = ?', ['cancel_requests_day']))->toBe(date('Y-m-d'));
});
