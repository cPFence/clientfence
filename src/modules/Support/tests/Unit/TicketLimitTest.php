<?php

declare(strict_types=1);

/**
 * ClientFence: the ticket limit per client (Support\TicketLimit), on an in-memory SQLite copy of
 * support_ticket, and the APIs that apply it (client ticket_create) or never do (staff, replies).
 */

use ClientFence\InformationException;
use ClientFence\Mod\Support\Entity\Helpdesk;
use ClientFence\Mod\Support\TicketLimit;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

use function Tests\Helpers\container;

/** A container with the Support settings $config and the tickets table. */
function ticketLimitContainer(array $config, ?Connection $db = null): Pimple\Container
{
    $db ??= DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE IF NOT EXISTS support_ticket (id INTEGER PRIMARY KEY, client_id INTEGER, created_at TEXT)');
    $di = container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;
    $di['mod_config'] = $di->protect(static fn (): array => $config);

    return $di;
}

/** A ticket of the client (or another) created $days days ago, whoever opened it. */
function ticketLimitTicket(Pimple\Container $di, float $days, int $clientId = 5): string
{
    $createdAt = date('Y-m-d H:i:s', (int) (time() - $days * 86400));
    $di['em']->getConnection()->insert('support_ticket', ['client_id' => $clientId, 'created_at' => $createdAt]);

    return $createdAt;
}

function ticketLimitClient(?int $groupId = null): Model_Client
{
    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->id = 5;
    $client->client_group_id = $groupId;
    $client->timezone = 'UTC';

    return $client;
}

/** The client API of client 5, with a Support service that opens and replies to tickets. */
function ticketLimitClientApi(Pimple\Container $di): ClientFence\Mod\Support\Api\Client
{
    $repo = Mockery::mock(ClientFence\Mod\Support\Repository\HelpdeskRepository::class);
    $repo->shouldReceive('find')->andReturn(new Helpdesk());
    $tickets = Mockery::mock(ClientFence\Mod\Support\Repository\SupportTicketRepository::class);
    $tickets->shouldReceive('findOneByClient')->andReturn(new ClientFence\Mod\Support\Entity\SupportTicket());
    $service = Mockery::mock(ClientFence\Mod\Support\Service::class);
    $service->shouldReceive('getHelpdeskRepository')->andReturn($repo);
    $service->shouldReceive('getSupportTicketRepository')->andReturn($tickets);
    $service->shouldReceive('ticketCreateForClient')->andReturn(10);
    $service->shouldReceive('canBeReopened')->andReturn(true);
    $service->shouldReceive('ticketReply')->andReturn(1);
    $api = new ClientFence\Mod\Support\Api\Client();
    $api->setDi($di);
    $api->setService($service);
    $api->setIdentity(ticketLimitClient());

    return $api;
}

/** A new ticket from the client area's form. */
function ticketLimitForm(): array
{
    return ['support_helpdesk_id' => 1, 'subject' => 'Hello', 'content' => 'Hello there'];
}

test('there is no limit by default, nor with 0 or empty settings', function (): void {
    foreach ([[], ['ticket_limit_max' => '', 'ticket_limit_days' => ''], ['ticket_limit_max' => '1', 'ticket_limit_days' => '0'], ['ticket_limit_max' => '0', 'ticket_limit_days' => '7']] as $config) {
        $di = ticketLimitContainer($config);
        ticketLimitTicket($di, 1);
        ticketLimitTicket($di, 2);

        expect(TicketLimit::status($di, ticketLimitClient()))->toBeNull()
            ->and(ticketLimitClientApi($di)->ticket_create(ticketLimitForm()))->toBe(10)
            ->and(ticketLimitClientApi($di)->ticket_limit())->toBeNull();
    }
});

test('the client ticket over the limit in the period is refused, and one is allowed again once the oldest leaves it', function (): void {
    $di = ticketLimitContainer(['ticket_limit_max' => '2', 'ticket_limit_days' => '7']);
    ticketLimitTicket($di, 20);
    ticketLimitTicket($di, 1, 6);
    $oldest = ticketLimitTicket($di, 6);
    $api = ticketLimitClientApi($di);

    // One left: the warning's date is when the oldest leaves the period.
    expect($api->ticket_limit())->toMatchArray(['max' => 2, 'days' => 7, 'left' => 1, 'date' => date('Y-m-d H:i:s', strtotime($oldest) + 7 * 86400)])
        ->and($api->ticket_create(ticketLimitForm()))->toBe(10);

    $newest = ticketLimitTicket($di, 1);
    expect($api->ticket_limit())->toMatchArray(['left' => 0, 'date' => date('Y-m-d H:i:s', strtotime($oldest) + 7 * 86400)])
        ->and(fn () => $api->ticket_create(ticketLimitForm()))->toThrow(InformationException::class, 'You have reached your limit of 2 ticket');

    // The oldest leaves the period: one more again, until the next oldest leaves.
    $di['em']->getConnection()->update('support_ticket', ['created_at' => date('Y-m-d H:i:s', time() - 8 * 86400)], ['created_at' => $oldest]);
    expect($api->ticket_limit())->toMatchArray(['max' => 2, 'days' => 7, 'left' => 1, 'date' => date('Y-m-d H:i:s', strtotime($newest) + 7 * 86400)])
        ->and($api->ticket_create(ticketLimitForm()))->toBe(10);
});

test('tickets staff opened count, and the refusal waits until enough of them leave the period', function (): void {
    // Three tickets (staff opened some) for a limit of two: a place frees when the second one leaves.
    $di = ticketLimitContainer(['ticket_limit_max' => '2', 'ticket_limit_days' => '7']);
    ticketLimitTicket($di, 5);
    $second = ticketLimitTicket($di, 3);
    ticketLimitTicket($di, 1);

    expect(TicketLimit::status($di, ticketLimitClient()))->toMatchArray(['left' => 0, 'date' => date('Y-m-d H:i:s', strtotime($second) + 7 * 86400)]);
});

test('staff open tickets for a client who has none left', function (): void {
    $di = ticketLimitContainer(['ticket_limit_max' => '1', 'ticket_limit_days' => '7']);
    ticketLimitTicket($di, 1);
    $repo = Mockery::mock(ClientFence\Mod\Support\Repository\HelpdeskRepository::class);
    $repo->shouldReceive('find')->andReturn(new Helpdesk());
    $service = Mockery::mock(ClientFence\Mod\Support\Service::class);
    $service->shouldReceive('getHelpdeskRepository')->andReturn($repo);
    $service->shouldReceive('ticketCreateForAdmin')->once()->andReturn(11);
    $api = new ClientFence\Mod\Support\Api\Admin();
    $api->setDi($di);
    $api->setService($service);
    $api->setIdentity(new Model_Admin());

    expect(TicketLimit::status($di, ticketLimitClient())['left'])->toBe(0)
        ->and($api->ticket_create(['client_id' => 5, 'support_helpdesk_id' => 1, 'subject' => 'Hello', 'content' => 'Hello there']))->toBe(11);
});

test('a client in an exempt group has no limit', function (): void {
    $di = ticketLimitContainer(['ticket_limit_max' => '1', 'ticket_limit_days' => '7', 'ticket_limit_exempt_groups' => ['2', '3']]);
    ticketLimitTicket($di, 1);

    expect(TicketLimit::status($di, ticketLimitClient(3)))->toBeNull()
        ->and(TicketLimit::status($di, ticketLimitClient(4))['left'])->toBe(0)
        ->and(TicketLimit::status($di, ticketLimitClient())['left'])->toBe(0);
    TicketLimit::check($di, ticketLimitClient(3));
});

test('a client with no ticket left still replies', function (): void {
    $di = ticketLimitContainer(['ticket_limit_max' => '1', 'ticket_limit_days' => '7']);
    ticketLimitTicket($di, 1);

    expect(ticketLimitClientApi($di)->ticket_reply(['id' => 1, 'content' => 'More details']))->toBeTrue();
});
