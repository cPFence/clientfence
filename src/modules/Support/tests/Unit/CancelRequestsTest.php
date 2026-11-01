<?php

declare(strict_types=1);

/**
 * ClientFence: cancellation requests, as WHMCS (Support\CancelRequests), with a stand-in for the Order service.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Support\CancelRequests;
use ClientFence\Mod\Support\Entity\SupportTicket;
use Doctrine\DBAL\DriverManager;

use function Tests\Helpers\createEntity;

function cancelRequestTicket(int $id, ?string $type, string $status = SupportTicket::REL_STATUS_PENDING): SupportTicket
{
    $ticket = createEntity(SupportTicket::class, ['id' => $id]);
    $ticket->setClientId(5)->setRelType(SupportTicket::REL_TYPE_ORDER)->setRelId(70)->setRelTask(SupportTicket::REL_TASK_CANCEL)->setRelNewValue($type)->setRelStatus($status);

    return $ticket;
}

function cancelRequestContainer(object $orderService, array $config = [], array $tickets = []): Pimple\Container
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE support_ticket (id INTEGER PRIMARY KEY, rel_type TEXT, rel_id INTEGER, rel_task TEXT, rel_new_value TEXT, rel_status TEXT)');
    foreach ($tickets as $ticket) {
        $db->insert('support_ticket', ['id' => $ticket->getId(), 'rel_type' => $ticket->getRelType(), 'rel_id' => $ticket->getRelId(), 'rel_task' => $ticket->getRelTask(), 'rel_new_value' => $ticket->getRelNewValue(), 'rel_status' => $ticket->getRelStatus()]);
    }
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $em->shouldReceive('flush');
    $em->shouldReceive('find')->andReturnUsing(static fn (string $class, int $id) => array_values(array_filter($tickets, static fn (SupportTicket $t): bool => $t->getId() === $id))[0] ?? null);
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

function cancelRequestOrder(string $status = 'active'): Model_ClientOrder
{
    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->id = 70;
    $order->status = $status;

    return $order;
}

test('accepting a request cancels the service as the client asked and completes the task', function (string $type, string $call): void {
    $order = cancelRequestOrder();
    $orders = Mockery::mock();
    $orders->shouldReceive('findByClientIdAndOrderId')->once()->with(5, 70)->andReturn($order);
    $orders->shouldReceive($call)->once()->with($order, 'Cancellation request (ticket #9)')->andReturn(true);
    $ticket = cancelRequestTicket(9, $type);

    CancelRequests::accept(cancelRequestContainer($orders), $ticket);

    expect($ticket->getRelStatus())->toBe(SupportTicket::REL_STATUS_COMPLETE);
})->with([
    'immediate' => [CancelRequests::IMMEDIATE, 'cancelFromOrder'],
    'end of billing period' => [CancelRequests::END_OF_PERIOD, 'scheduleCancellationFromOrder'],
]);

test('a request whose service staff already cancelled or deleted only completes the task', function (bool $deleted): void {
    $orders = Mockery::mock();
    $orders->shouldReceive('findByClientIdAndOrderId')->once()->andReturn($deleted ? null : cancelRequestOrder('canceled'));
    $orders->shouldNotReceive('cancelFromOrder');
    $ticket = cancelRequestTicket(9, CancelRequests::IMMEDIATE);

    CancelRequests::accept(cancelRequestContainer($orders), $ticket);

    expect($ticket->getRelStatus())->toBe(SupportTicket::REL_STATUS_COMPLETE);
})->with(['cancelled' => [false], 'deleted' => [true]]);

test('only a pending request with a type can be accepted', function (?string $type, string $status): void {
    $orders = Mockery::mock();
    $orders->shouldNotReceive('findByClientIdAndOrderId');

    expect(fn () => CancelRequests::accept(cancelRequestContainer($orders), cancelRequestTicket(9, $type, $status)))
        ->toThrow(InformationException::class, 'This ticket has no pending cancellation request.');
})->with([
    'made before the type existed' => [null, SupportTicket::REL_STATUS_PENDING],
    'already completed' => [CancelRequests::IMMEDIATE, SupportTicket::REL_STATUS_COMPLETE],
]);

test('the client cannot ask for the end of the period when the service cannot end there', function (): void {
    $order = cancelRequestOrder();
    $orders = Mockery::mock();
    $orders->shouldReceive('canCancelAtPeriodEnd')->once()->with($order)->andReturn(false);
    $di = cancelRequestContainer($orders);

    CancelRequests::assertType($di, $order, CancelRequests::IMMEDIATE);
    expect(fn () => CancelRequests::assertType($di, $order, CancelRequests::END_OF_PERIOD))->toThrow(InformationException::class)
        ->and(fn () => CancelRequests::assertType($di, $order, 'next_week'))->toThrow(ClientFence\Exception::class, 'Invalid cancellation type.');
});

test('the cron accepts the pending requests with a type only when the setting is on', function (bool $on): void {
    $typed = cancelRequestTicket(9, CancelRequests::IMMEDIATE);
    $old = cancelRequestTicket(10, null);
    $failing = cancelRequestTicket(11, CancelRequests::END_OF_PERIOD);
    $orders = Mockery::mock();
    $orders->shouldReceive('findByClientIdAndOrderId')->times($on ? 2 : 0)->andReturn(cancelRequestOrder());
    $orders->shouldReceive('cancelFromOrder')->times($on ? 1 : 0)->andReturn(true);
    $orders->shouldReceive('scheduleCancellationFromOrder')->times($on ? 1 : 0)->andThrow(new InformationException('No active gateway subscription that supports cancellation at period end is linked to this order.'));
    $di = cancelRequestContainer($orders, ['cancel_requests_auto' => $on ? '1' : '0'], [$typed, $old, $failing]);

    CancelRequests::run($di);

    expect($typed->getRelStatus())->toBe($on ? SupportTicket::REL_STATUS_COMPLETE : SupportTicket::REL_STATUS_PENDING)
        ->and($old->getRelStatus())->toBe(SupportTicket::REL_STATUS_PENDING)
        ->and($failing->getRelStatus())->toBe(SupportTicket::REL_STATUS_PENDING)
        ->and($di['logger']->errors)->toHaveCount($on ? 1 : 0);
})->with(['on' => [true], 'off' => [false]]);
