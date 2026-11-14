<?php

declare(strict_types=1);

/**
 * ClientFence: the Email registrar in ticket mode (Servicedomain\DomainTickets, GitHub #25). Domain #3 of client #5
 * belongs to order #7; the support service is a fake that keeps the one ticket, its messages and replies.
 */

use ClientFence\Mod\Servicedomain\DomainTickets;
use ClientFence\Mod\Servicedomain\Service;
use ClientFence\Mod\Support\Entity\Helpdesk;
use ClientFence\Mod\Support\Entity\SupportTicket;

use function Tests\Helpers\container;

afterEach(function (): void {
    Mockery::close();
});

/**
 * @return array{0: Pimple\Container, 1: Model_ServiceDomain, 2: Model_ClientOrder, 3: ArrayObject}
 */
function domainTicketsFixture(bool $ticketsOn = true, ?string $supportUrl = null): array
{
    $model = new Model_ServiceDomain();
    $model->loadBean(new Tests\Helpers\DummyBean());
    foreach (['id' => 3, 'client_id' => 5, 'sld' => 'example', 'tld' => '.com', 'period' => 2, 'ns1' => 'ns1.old.test', 'ns2' => 'ns2.old.test', 'ns3' => null, 'ns4' => null, 'locked' => 0, 'privacy' => 0, 'expires_at' => '2027-01-15 00:00:00', 'action' => null] as $k => $v) {
        $model->{$k} = $v;
    }
    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->id = 7;
    $order->client_id = 5;
    $order->service_id = 3;
    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->id = 5;

    $state = new ArrayObject(['ticket' => null, 'persisted' => 0, 'messages' => [], 'replies' => [], 'events' => [], 'stored' => 0]);

    $repository = Mockery::mock();
    $repository->shouldReceive('findOneBy')->andReturnUsing(fn () => $state['ticket']);
    $helpdesks = Mockery::mock();
    $helpdesks->shouldReceive('getDefault')->andReturn(new Helpdesk());
    $support = Mockery::mock();
    $support->shouldReceive('ticketsEnabled')->andReturn($ticketsOn);
    $support->shouldReceive('supportUrl')->andReturn($supportUrl);
    $support->shouldReceive('getSupportTicketRepository')->andReturn($repository);
    $support->shouldReceive('getHelpdeskRepository')->andReturn($helpdesks);
    $support->shouldReceive('messageCreateForTicket')->andReturnUsing(function (SupportTicket $t, $identity, string $content) use ($state): int {
        $state['messages'] = [...$state['messages'], $content];

        return 1;
    });
    $support->shouldReceive('ticketReply')->andReturnUsing(function (SupportTicket $t, $identity, string $content) use ($state): int {
        $state['replies'] = [...$state['replies'], [$identity::class, $content]];

        return 2;
    });

    $orders = Mockery::mock();
    $orders->shouldReceive('getServiceOrder')->andReturn($order);
    $orders->shouldReceive('getOrderService')->andReturn($model);
    $orders->shouldReceive('getConfig')->andReturn([]);

    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('wrapInTransaction')->andReturnUsing(fn (callable $fn) => $fn());
    $em->shouldReceive('persist')->andReturnUsing(function (SupportTicket $t) use ($state): void {
        $state['ticket'] = $t;
        ++$state['persisted'];
    });
    $em->shouldReceive('flush');
    $events = Mockery::mock();
    $events->shouldReceive('fire')->andReturnUsing(function (array $event) use ($state): void {
        $state['events'] = [...$state['events'], $event['event']];
    });
    $db = Mockery::mock();
    $db->shouldReceive('getExistingModelById')->andReturnUsing(fn (string $type) => $type === 'Client' ? $client : $order);
    $db->shouldReceive('store')->andReturnUsing(function () use ($state): int {
        ++$state['stored'];

        return 3;
    });

    $di = container();
    $di['db'] = $db;
    $di['em'] = $em;
    $di['events_manager'] = $events;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['mod_service'] = $di->protect(fn (string $name) => match (strtolower($name)) {
        'support' => $support,
        'order' => $orders,
    });

    return [$di, $model, $order, $state];
}

/** The domain service with the Email registrar: any adapter call (the old mail()) would throw. */
function domainTicketsService(Pimple\Container $di): Service
{
    $service = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('_getD')->andReturn([new Registrar_Domain(), new Registrar_Adapter_Email([])]);
    $service->setDi($di);

    return $service;
}

function domainTicketsPending(ArrayObject $state): array
{
    return json_decode((string) $state['ticket']->getRelNewValue(), true);
}

/** Runs one domain action through the domain service (dataset closures would be called by Pest itself). */
function domainTicketsAction(string $case, Service $service, Model_ServiceDomain $model, Model_ClientOrder $order): void
{
    $contacts = ['contact' => ['first_name' => 'Ann', 'last_name' => 'Lee', 'email' => 'ann@example.invalid', 'company' => '', 'address1' => '1 Road', 'address2' => '', 'country' => 'US', 'city' => 'Town', 'state' => 'CA', 'postcode' => '90001', 'phone_cc' => '1', 'phone' => '5550100']];
    switch ($case) {
        case 'register':
        case 'transfer':
            $model->action = $case;
            $service->action_activate($order);
            break;
        case 'renew':
            $service->action_renew($order);
            break;
        case 'delete':
            $service->action_cancel($order);
            break;
        case 'nameservers':
            $service->updateNameservers($model, ['ns1' => 'ns1.new.test', 'ns2' => 'ns2.new.test']);
            break;
        case 'contacts':
            $service->updateContacts($model, $contacts);
            break;
        case 'lock':
            $service->lock($model);
            break;
        case 'unlock':
            $service->unlock($model);
            break;
        case 'privacy on':
            $service->enablePrivacyProtection($model);
            break;
        case 'privacy off':
            $service->disablePrivacyProtection($model);
            break;
        case 'transfer code':
            $service->getTransferCode($model);
            break;
    }
}

test('every Email registrar action opens the domain\'s one ticket, then updates it', function (string $case, string $key): void {
    [$di, $model, $order, $state] = domainTicketsFixture();
    $service = domainTicketsService($di);

    domainTicketsAction($case, $service, $model, $order);
    domainTicketsAction($case, $service, $model, $order);

    $ticket = $state['ticket'];
    expect($state['persisted'])->toBe(1)
        ->and($state['messages'])->toHaveCount(1)
        ->and($state['replies'])->toHaveCount(1)
        ->and($state['replies'][0][0])->toBe(Model_Client::class)
        ->and($state['events'])->toBe(['onAfterClientOpenTicket'])
        ->and($ticket->getClientId())->toBe(5)
        ->and([$ticket->getRelType(), $ticket->getRelId(), $ticket->getRelTask(), $ticket->getRelStatus()])->toBe(['order', 7, DomainTickets::TASK, SupportTicket::REL_STATUS_PENDING])
        ->and(domainTicketsPending($state))->toHaveKey($key)
        ->and($state['messages'][0])->toContain('example.com');
})->with([
    'register' => ['register', 'register'],
    'transfer' => ['transfer', 'transfer'],
    'renew' => ['renew', 'renew'],
    'delete' => ['delete', 'delete'],
    'nameservers' => ['nameservers', 'nameservers'],
    'contacts' => ['contacts', 'contacts'],
    'lock' => ['lock', 'lock'],
    'unlock' => ['unlock', 'lock'],
    'privacy on' => ['privacy on', 'privacy'],
    'privacy off' => ['privacy off', 'privacy'],
    'transfer code' => ['transfer code', 'transfer_code'],
]);

test('a request waits on the ticket: the stored domain keeps its values and the domain pages show it', function (): void {
    [$di, $model, , $state] = domainTicketsFixture();
    $service = domainTicketsService($di);

    expect($service->updateNameservers($model, ['ns1' => 'ns1.new.test', 'ns2' => 'ns2.new.test']))->toBeTrue()
        ->and($service->lock($model))->toBeTrue()
        ->and($service->getTransferCode($model))->toBe('');

    expect($state['stored'])->toBe(0)
        ->and($model->ns1)->toBe('ns1.old.test')
        ->and(domainTicketsPending($state))->toBe([
            'nameservers' => ['ns1' => 'ns1.new.test', 'ns2' => 'ns2.new.test', 'ns3' => null, 'ns4' => null],
            'lock' => ['locked' => true],
            'transfer_code' => [],
        ]);
    $pending = DomainTickets::pending($di, $model);
    expect(array_keys($pending['actions']))->toBe(['nameservers', 'lock', 'transfer_code'])
        ->and($pending['actions']['nameservers'])->toContain('ns1.new.test')
        ->and($pending['actions']['lock'])->toBe('Lock example.com');
});

test('two renewals before staff act renew for both periods', function (): void {
    [$di, $model, $order, $state] = domainTicketsFixture();
    $service = domainTicketsService($di);

    $service->action_renew($order);
    $service->action_renew($order);

    expect(domainTicketsPending($state)['renew'])->toBe(['period' => 4]);
});

test('marking the requests done stores their values, completes the task and replies to the client', function (bool $fromTicket): void {
    [$di, $model, , $state] = domainTicketsFixture();
    $state['ticket'] = (new SupportTicket())->setClientId(5)->setRelType('order')->setRelId(7)->setRelTask(DomainTickets::TASK)
        ->setRelStatus(SupportTicket::REL_STATUS_PENDING)->setRelNewValue(json_encode([
            'nameservers' => ['ns1' => 'ns1.new.test', 'ns2' => 'ns2.new.test', 'ns3' => null, 'ns4' => null],
            'lock' => ['locked' => true],
            'privacy' => ['privacy' => true],
            'renew' => ['period' => 2],
            'transfer_code' => [],
        ]));
    $admin = new Model_Admin();
    $admin->loadBean(new Tests\Helpers\DummyBean());

    if ($fromTicket) {
        DomainTickets::doneFromTicket($di, $state['ticket'], $admin);
    } else {
        DomainTickets::done($di, $model, $admin, ' EPP-123 ');
    }

    expect([$model->ns1, $model->ns2, $model->locked, $model->privacy])->toBe(['ns1.new.test', 'ns2.new.test', '1', '1'])
        ->and($model->expires_at)->toBe('2029-01-15 00:00:00')
        ->and($model->transfer_code)->toBe($fromTicket ? null : 'EPP-123')
        ->and($state['stored'])->toBe(1)
        ->and($state['ticket']->getRelStatus())->toBe(SupportTicket::REL_STATUS_COMPLETE)
        ->and($state['replies'])->toHaveCount(1)
        ->and($state['replies'][0][0])->toBe(Model_Admin::class)
        ->and($state['replies'][0][1])->toContain('Your requests for example.com are done');
    if (!$fromTicket) {
        expect($state['replies'][0][1])->toContain('EPP-123');
    }
    expect(DomainTickets::pending($di, $model))->toBeNull();
})->with(['admin domain page' => [false], 'Set Task Complete on the ticket' => [true]]);

test('a registration done gets its dates; staff may set the expiry date themselves', function (?string $expiry, string $expected): void {
    [$di, $model, , $state] = domainTicketsFixture();
    $state['ticket'] = (new SupportTicket())->setRelStatus(SupportTicket::REL_STATUS_PENDING)->setRelNewValue('{"register":{"period":2}}');
    $admin = new Model_Admin();
    $admin->loadBean(new Tests\Helpers\DummyBean());

    DomainTickets::done($di, $model, $admin, null, $expiry);

    expect(substr((string) $model->expires_at, 0, 10))->toBe($expected)
        ->and(substr((string) $model->registered_at, 0, 10))->toBe(date('Y-m-d'));
})->with([
    'from the period' => [null, date('Y-m-d', strtotime('+2 years'))],
    'given by staff' => ['2030-03-01', '2030-03-01'],
]);

test('marking done without requests waiting is refused', function (): void {
    [$di, $model] = domainTicketsFixture();
    $admin = new Model_Admin();
    $admin->loadBean(new Tests\Helpers\DummyBean());

    DomainTickets::done($di, $model, $admin);
})->throws(ClientFence\InformationException::class, 'This domain has no requests waiting.');

test('with support tickets off a request is refused with the way to contact support', function (?string $url, string $message): void {
    [$di, $model, , $state] = domainTicketsFixture(false, $url);
    $service = domainTicketsService($di);

    expect(fn () => $service->lock($model))->toThrow(ClientFence\InformationException::class, $message);
    expect($state['persisted'])->toBe(0)
        ->and($state['replies'])->toBe([])
        ->and($state['stored'])->toBe(0);
})->with([
    'no outside help desk' => [null, 'Please contact us to make this change to example.com.'],
    'outside help desk' => ['https://help.example.test', 'Please contact us at https://help.example.test to make this change to example.com.'],
]);

test('with support tickets off, orders, renewals and cancels go through without a ticket', function (): void {
    [$di, $model, $order, $state] = domainTicketsFixture(false);
    $service = domainTicketsService($di);

    expect($service->action_renew($order))->toBeTrue()
        ->and($model->expires_at)->toBe('2029-01-15 00:00:00')
        ->and($service->action_cancel($order))->toBeTrue();
    $model->action = 'register';
    $service->action_activate($order);
    expect($model->action)->toBeNull()
        ->and(substr((string) $model->registered_at, 0, 10))->toBe(date('Y-m-d'))
        ->and($state['persisted'])->toBe(0)
        ->and($state['replies'])->toBe([]);
});
