<?php

declare(strict_types=1);

/**
 * ClientFence: ticket merging (Support\TicketMerge) on an in-memory SQLite copy of the ticket tables.
 */

use ClientFence\Mod\Support\TicketMerge;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use ClientFence\InformationException;

function ticketMergeDb(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
        'CREATE TABLE support_ticket (id INTEGER PRIMARY KEY, client_id INTEGER, author_email TEXT, subject TEXT, status TEXT, rel_type TEXT, rel_id INTEGER, rel_task TEXT, rel_new_value TEXT, rel_status TEXT, access_hash TEXT, updated_at TEXT)',
        'CREATE TABLE support_ticket_message (id INTEGER PRIMARY KEY, support_ticket_id INTEGER, content TEXT, created_at TEXT)',
        'CREATE TABLE support_ticket_note (id INTEGER PRIMARY KEY, support_ticket_id INTEGER, note TEXT)',
        'CREATE TABLE support_ticket_file (id INTEGER PRIMARY KEY, ticket_id INTEGER, message_id INTEGER)',
        'CREATE TABLE support_ticket_author (message_id INTEGER PRIMARY KEY, ticket_id INTEGER)',
        'CREATE TABLE support_mail_import (id INTEGER PRIMARY KEY, ticket_id INTEGER)',
        'CREATE TABLE ' . TicketMerge::TABLE . ' (ticket_id INTEGER PRIMARY KEY, merged_into INTEGER NOT NULL, access_hash TEXT, subject TEXT NOT NULL DEFAULT \'\', merged_by INTEGER, merged_at TEXT NOT NULL)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    $ticket = fn (int $id, ?int $client, string $subject, string $status, string $updated, array $more = []) => $db->insert('support_ticket', $more + [
        'id' => $id, 'client_id' => $client, 'author_email' => null, 'subject' => $subject, 'status' => $status, 'updated_at' => $updated,
    ]);
    // Client 5: tickets 10, 12, 15 (15 answered last); client 6: ticket 11; guests: 20, 21 (same email), 22.
    $ticket(10, 5, 'Site down', 'closed', '2026-10-01 10:00:00');
    $ticket(11, 6, 'Other client', 'open', '2026-10-01 11:00:00');
    $ticket(12, 5, 'Still down', 'on_hold', '2026-10-02 10:00:00');
    $ticket(15, 5, 'Down again', 'open', '2026-10-05 10:00:00');
    $ticket(20, null, 'Guest one', 'open', '2026-10-03 10:00:00', ['author_email' => 'Guest@Mail.test', 'access_hash' => 'hash20']);
    $ticket(21, null, 'Guest two', 'closed', '2026-10-04 10:00:00', ['author_email' => 'guest@mail.test', 'access_hash' => 'hash21']);
    $ticket(22, null, 'Someone else', 'open', '2026-10-04 10:00:00', ['author_email' => 'else@mail.test', 'access_hash' => 'hash22']);
    foreach ([
        [100, 10, '2026-10-01 10:00:00'], [101, 12, '2026-10-02 10:00:00'], [102, 10, '2026-10-03 10:00:00'],
        [103, 15, '2026-10-05 10:00:00'], [104, 12, '2026-10-04 10:00:00'], [105, 11, '2026-10-06 10:00:00'],
        [106, 20, '2026-10-03 10:00:00'], [107, 21, '2026-10-04 10:00:00'],
    ] as [$id, $ticketId, $at]) {
        $db->insert('support_ticket_message', ['id' => $id, 'support_ticket_id' => $ticketId, 'content' => 'm' . $id, 'created_at' => $at]);
    }
    $db->insert('support_ticket_note', ['id' => 1, 'support_ticket_id' => 12, 'note' => 'n']);
    $db->insert('support_ticket_file', ['id' => 1, 'ticket_id' => 15, 'message_id' => 103]);
    $db->insert('support_ticket_author', ['message_id' => 104, 'ticket_id' => 12]);
    $db->insert('support_mail_import', ['id' => 1, 'ticket_id' => 15]);

    return $db;
}

function ticketMergeContainer(Connection $db): Pimple\Container
{
    $di = new Pimple\Container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;

    return $di;
}

test('tickets of one client merge into the oldest', function (): void {
    $db = ticketMergeDb();
    $di = ticketMergeContainer($db);

    expect(TicketMerge::merge($di, ['15', 12, '10', 10], null, 3))->toBe(['id' => 10, 'merged' => [12, 15]]);

    $master = $db->fetchAssociative('SELECT * FROM support_ticket WHERE id = 10');
    // Subject gets [MERGED]; the status is that of the ticket with the latest message (15).
    expect($master)->toMatchArray(['subject' => 'Site down [MERGED]', 'status' => 'open', 'updated_at' => '2026-10-05 10:00:00'])
        ->and($master['access_hash'])->toBeNull()
        ->and($db->fetchFirstColumn('SELECT id FROM support_ticket ORDER BY id'))->toBe([10, 11, 20, 21, 22])
        ->and($db->fetchFirstColumn('SELECT id FROM support_ticket_message WHERE support_ticket_id = 10 ORDER BY created_at'))->toBe([100, 101, 102, 104, 103])
        ->and($db->fetchOne('SELECT support_ticket_id FROM support_ticket_note WHERE id = 1'))->toBe(10)
        ->and($db->fetchOne('SELECT ticket_id FROM support_ticket_file WHERE id = 1'))->toBe(10)
        ->and($db->fetchOne('SELECT ticket_id FROM support_ticket_author WHERE message_id = 104'))->toBe(10)
        ->and($db->fetchOne('SELECT ticket_id FROM support_mail_import WHERE id = 1'))->toBe(10)
        ->and(TicketMerge::target($di, 12))->toBe(10)
        ->and(TicketMerge::target($di, 15))->toBe(10)
        ->and(TicketMerge::target($di, 10))->toBeNull()
        ->and(TicketMerge::targetForClient($di, 12, 5))->toBe(10)
        ->and(TicketMerge::targetForClient($di, 12, 6))->toBeNull()
        ->and(TicketMerge::mergedInto($di, 10))->toBe([
            ['id' => 12, 'subject' => 'Still down', 'merged_at' => (string) $db->fetchOne('SELECT merged_at FROM ' . TicketMerge::TABLE . ' WHERE ticket_id = 12')],
            ['id' => 15, 'subject' => 'Down again', 'merged_at' => (string) $db->fetchOne('SELECT merged_at FROM ' . TicketMerge::TABLE . ' WHERE ticket_id = 15')],
        ]);

    // Merged again into an older ticket: [MERGED] is not repeated and earlier merges follow.
    $db->insert('support_ticket', ['id' => 9, 'client_id' => 5, 'subject' => 'First [merged]', 'status' => 'closed', 'updated_at' => '2026-09-01 10:00:00']);
    TicketMerge::merge($di, [10, 9], null, 3);
    expect($db->fetchOne('SELECT subject FROM support_ticket WHERE id = 9'))->toBe('First [merged]')
        ->and(TicketMerge::target($di, 12))->toBe(9)
        ->and(TicketMerge::target($di, 10))->toBe(9);
});

test('the sweep moves a reply saved for a merged ticket after its merge', function (): void {
    $db = ticketMergeDb();
    $di = ticketMergeContainer($db);
    TicketMerge::merge($di, [10, 12], null, 1);
    // A reply that loaded ticket 12 just before the merge, written just after it.
    $db->insert('support_ticket_message', ['id' => 200, 'support_ticket_id' => 12, 'content' => 'late', 'created_at' => '2026-10-07 10:00:00']);
    $db->insert('support_ticket_file', ['id' => 2, 'ticket_id' => 12, 'message_id' => 200]);
    $db->insert('support_ticket_author', ['message_id' => 200, 'ticket_id' => 12]);

    expect(TicketMerge::sweep($di))->toBe(3)
        ->and($db->fetchOne('SELECT support_ticket_id FROM support_ticket_message WHERE id = 200'))->toBe(10)
        ->and($db->fetchOne('SELECT ticket_id FROM support_ticket_file WHERE id = 2'))->toBe(10)
        ->and($db->fetchOne('SELECT ticket_id FROM support_ticket_author WHERE message_id = 200'))->toBe(10)
        ->and($db->fetchOne('SELECT support_ticket_id FROM support_ticket_message WHERE id = 105'))->toBe(11)
        ->and(TicketMerge::sweep($di))->toBe(0)
        ->and(TicketMerge::sweep(ticketMergeContainer(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]))))->toBe(0);
});

test('a typed subject replaces the merged subject', function (): void {
    $db = ticketMergeDb();
    TicketMerge::merge(ticketMergeContainer($db), [10, 12], 'Website outage', 1);

    expect($db->fetchOne('SELECT subject FROM support_ticket WHERE id = 10'))->toBe('Website outage');
});

test('a merged guest ticket link is not forwarded', function (): void {
    $di = ticketMergeContainer(ticketMergeDb());
    TicketMerge::merge($di, [20, 21], null, 1);
    $api = Mockery::mock();
    $api->shouldReceive('support_ticket_get')->once()->with(['hash' => 'hash21'])
        ->andThrow(new ClientFence\Exception('Guest ticket not found'));
    $di['api_guest'] = $api;
    $app = Mockery::mock(ClientFence\App::class);
    $app->shouldNotReceive('redirect');
    $controller = new ClientFence\Mod\Support\Controller\Client();
    $controller->setDi($di);

    expect(fn () => $controller->get_ticket($app, 'hash21'))->toThrow(ClientFence\Exception::class, 'Guest ticket not found');
});

test('only tickets of the same owner merge', function (): void {
    $db = ticketMergeDb();
    $di = ticketMergeContainer($db);

    expect(fn () => TicketMerge::merge($di, [10, 11], null, 1))->toThrow(InformationException::class, 'Only tickets of the same client')
        ->and(fn () => TicketMerge::merge($di, [10, 20], null, 1))->toThrow(InformationException::class, 'Only tickets of the same client')
        ->and(fn () => TicketMerge::merge($di, [20, 22], null, 1))->toThrow(InformationException::class, 'Only tickets of the same client')
        ->and(fn () => TicketMerge::merge($di, [10], null, 1))->toThrow(InformationException::class, 'at least two')
        ->and(fn () => TicketMerge::merge($di, [10, 99], null, 1))->toThrow(InformationException::class, 'was not found')
        ->and($db->fetchOne('SELECT COUNT(*) FROM support_ticket'))->toBe(7)
        ->and($db->fetchOne('SELECT COUNT(*) FROM ' . TicketMerge::TABLE))->toBe(0);

    expect(TicketMerge::merge($di, [21, 20], null, 1)['id'])->toBe(20)
        ->and($db->fetchOne('SELECT status FROM support_ticket WHERE id = 20'))->toBe('closed')
        ->and($db->fetchOne('SELECT access_hash FROM support_ticket WHERE id = 20'))->not->toBe('hash20')->not->toBeEmpty();
});

test('a task moves to the merged ticket, and a pending one that cannot stops the merge', function (): void {
    $db = ticketMergeDb();
    $di = ticketMergeContainer($db);
    $task = ['rel_type' => 'order', 'rel_id' => 7, 'rel_task' => 'cancel', 'rel_status' => 'pending'];
    $db->update('support_ticket', $task, ['id' => 12]);
    $db->update('support_ticket', ['rel_id' => 8] + $task, ['id' => 15]);

    expect(fn () => TicketMerge::merge($di, [10, 12, 15], null, 1))->toThrow(InformationException::class, 'Ticket #15 has a pending task');

    $db->update('support_ticket', ['rel_status' => 'complete'], ['id' => 15]);
    TicketMerge::merge($di, [10, 12, 15], null, 1);
    expect($db->fetchAssociative('SELECT rel_type, rel_id, rel_task, rel_status FROM support_ticket WHERE id = 10'))
        ->toBe(['rel_type' => 'order', 'rel_id' => 7, 'rel_task' => 'cancel', 'rel_status' => 'pending']);
});

test('lookups survive a missing table and ids are cleaned', function (): void {
    $di = ticketMergeContainer(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));

    expect(TicketMerge::target($di, 5))->toBeNull()
        ->and(TicketMerge::targetForClient($di, 5, 1))->toBeNull()
        ->and(TicketMerge::mergedInto($di, 5))->toBe([])
        ->and(TicketMerge::ids(['12', 3, ' 7 ', 3, 0, -2, 'x', '1.5', null, [4]]))->toBe([3, 7, 12]);
});
