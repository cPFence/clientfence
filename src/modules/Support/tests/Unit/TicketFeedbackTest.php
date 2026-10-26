<?php

declare(strict_types=1);

/**
 * ClientFence: WHMCS's reply ratings and closing feedback (Support\TicketFeedback), on an in-memory
 * SQLite copy of the tables.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Support\TicketFeedback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

function ticketFeedbackDb(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
        'CREATE TABLE support_ticket (id INTEGER PRIMARY KEY, subject TEXT, status TEXT, client_id INTEGER, author_name TEXT, author_email TEXT, access_hash TEXT, support_helpdesk_id INTEGER, created_at TEXT)',
        'CREATE TABLE support_ticket_message (id INTEGER PRIMARY KEY, support_ticket_id INTEGER, admin_id INTEGER, client_id INTEGER, created_at TEXT)',
        'CREATE TABLE admin (id INTEGER PRIMARY KEY, name TEXT, system_name TEXT, status TEXT)',
        'CREATE TABLE ' . TicketFeedback::RATINGS . ' (message_id INTEGER PRIMARY KEY, ticket_id INTEGER NOT NULL, admin_id INTEGER NOT NULL, rating INTEGER NOT NULL, rated_by_kind TEXT NOT NULL, rated_by_id INTEGER NOT NULL DEFAULT 0, ip TEXT NOT NULL DEFAULT \'\', rated_at TEXT NOT NULL)',
        'CREATE TABLE ' . TicketFeedback::FEEDBACK . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INTEGER NOT NULL, admin_id INTEGER, rating INTEGER, comments TEXT NOT NULL, ip TEXT NOT NULL DEFAULT \'\', created_at TEXT NOT NULL)',
        'CREATE TABLE ' . TicketFeedback::REQUESTS . ' (ticket_id INTEGER PRIMARY KEY, token TEXT NOT NULL UNIQUE, sent_at TEXT NOT NULL, answered_at TEXT)',
        'CREATE TABLE ' . TicketFeedback::HELPDESKS . ' (helpdesk_id INTEGER PRIMARY KEY, feedback_request INTEGER NOT NULL DEFAULT 1, updated_at TEXT NOT NULL)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    foreach ([[1, 'Test Admin', null], [2, 'System Cron Job', 'cron'], [3, 'Support Agent', null]] as [$id, $name, $system]) {
        $db->insert('admin', ['id' => $id, 'name' => $name, 'system_name' => $system, 'status' => 'active']);
    }
    // Ticket 10 (client 5, help desk 1): the client, a cron autoresponse, Support Agent, the client, Test Admin.
    $db->insert('support_ticket', ['id' => 10, 'subject' => 'Site down', 'status' => 'closed', 'client_id' => 5, 'support_helpdesk_id' => 1, 'created_at' => '2026-10-01 10:00:00']);
    foreach ([[100, null, 5, '2026-10-01 10:00:00'], [101, 2, null, '2026-10-01 10:00:05'], [102, 3, null, '2026-10-01 12:00:00'], [103, null, 5, '2026-10-02 09:00:00'], [104, 1, null, '2026-10-03 13:30:00']] as [$id, $admin, $client, $at]) {
        $db->insert('support_ticket_message', ['id' => $id, 'support_ticket_id' => 10, 'admin_id' => $admin, 'client_id' => $client, 'created_at' => $at]);
    }
    // Ticket 11: a guest's, help desk 2; ticket 12: open; ticket 13: no staff reply.
    $db->insert('support_ticket', ['id' => 11, 'subject' => 'Guest', 'status' => 'closed', 'client_id' => null, 'author_name' => 'Guest Person', 'author_email' => 'guest@mail.test', 'access_hash' => 'hash11', 'support_helpdesk_id' => 2, 'created_at' => '2026-10-01 10:00:00']);
    $db->insert('support_ticket_message', ['id' => 110, 'support_ticket_id' => 11, 'admin_id' => 3, 'client_id' => null, 'created_at' => '2026-10-01 11:00:00']);
    $db->insert('support_ticket', ['id' => 12, 'subject' => 'Open', 'status' => 'open', 'client_id' => 5, 'support_helpdesk_id' => 1, 'created_at' => '2026-10-01 10:00:00']);
    $db->insert('support_ticket', ['id' => 13, 'subject' => 'Quiet', 'status' => 'closed', 'client_id' => 5, 'support_helpdesk_id' => 1, 'created_at' => '2026-10-01 10:00:00']);
    $db->insert('support_ticket_message', ['id' => 130, 'support_ticket_id' => 13, 'admin_id' => null, 'client_id' => 5, 'created_at' => '2026-10-01 10:00:00']);

    return $db;
}

/**
 * @param list<array> $mails  the emails sent
 * @param array       $config the Support settings
 */
function ticketFeedbackContainer(Connection $db, array &$mails, array $config = []): Pimple\Container
{
    $di = new Pimple\Container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;
    $di['logger'] = new class {
        public array $warnings = [];

        public function warning(string $message): void
        {
            $this->warnings[] = $message;
        }

        public function __call(string $name, array $args): void
        {
        }
    };
    $di['url'] = new class {
        public function link(string $path): string
        {
            return 'https://portal.test/' . $path;
        }
    };
    $email = new class($mails) {
        public function __construct(private array &$mails)
        {
        }

        public function sendTemplate(array $data): bool
        {
            $this->mails[] = $data;

            return true;
        }
    };
    $extension = new class($config) {
        public function __construct(private array $config)
        {
        }

        public function getConfig(string $ext): array
        {
            return $this->config;
        }
    };
    $di['mod_service'] = $di->protect(fn (string $mod): ?object => match ($mod) {
        'email' => $email,
        'extension' => $extension,
        default => null,
    });

    return $di;
}

test('a staff reply is rated once, 1 to 5 stars', function (): void {
    $mails = [];
    $db = ticketFeedbackDb();
    $di = ticketFeedbackContainer($db, $mails);

    TicketFeedback::rate($di, 10, 102, '4', 'client', 5, '203.0.113.9');

    expect(TicketFeedback::ratings($di, 10))->toBe([102 => 4])
        ->and($db->fetchAssociative('SELECT admin_id, rated_by_kind, rated_by_id, ip FROM ' . TicketFeedback::RATINGS))->toBe(['admin_id' => 3, 'rated_by_kind' => 'client', 'rated_by_id' => 5, 'ip' => '203.0.113.9'])
        ->and(fn () => TicketFeedback::rate($di, 10, 102, 5, 'client', 5, null))->toThrow(InformationException::class, 'already rated');
});

test('only staff replies of the ticket, and only 1 to 5', function (int $ticket, int $message, mixed $stars): void {
    $mails = [];
    $di = ticketFeedbackContainer(ticketFeedbackDb(), $mails);

    expect(fn () => TicketFeedback::rate($di, $ticket, $message, $stars, 'client', 5, null))->toThrow(InformationException::class);
})->with([
    'client message' => [10, 100, 3],
    'reply of another ticket' => [10, 110, 3],
    'unknown message' => [10, 999, 3],
    'zero stars' => [10, 102, 0],
    'six stars' => [10, 102, 6],
    'not a number' => [10, 102, 'five'],
]);

test('ratings switched off in Support settings', function (): void {
    $mails = [];
    $di = ticketFeedbackContainer(ticketFeedbackDb(), $mails, ['ticket_rating' => 'false']);

    expect(TicketFeedback::ratingsOn($di))->toBeFalse()
        ->and(fn () => TicketFeedback::rate($di, 10, 102, 4, 'client', 5, null))->toThrow(InformationException::class, 'switched off')
        ->and(TicketFeedback::ratingsOn(ticketFeedbackContainer(ticketFeedbackDb(), $mails)))->toBeTrue();
});

test('closing asks the client for feedback with a private link', function (): void {
    $mails = [];
    $db = ticketFeedbackDb();
    $di = ticketFeedbackContainer($db, $mails);

    TicketFeedback::afterClose($di, 10);
    $token = (string) $db->fetchOne('SELECT token FROM ' . TicketFeedback::REQUESTS . ' WHERE ticket_id = 10');

    expect($token)->toMatch('/^[a-f0-9]{64}$/')
        ->and($mails)->toHaveCount(1)
        ->and($mails[0])->toMatchArray(['code' => 'mod_support_ticket_feedback_request', 'to_client' => 5, 'feedback_url' => 'https://portal.test/support/feedback/' . $token])
        ->and(TicketFeedback::openLink($di, 10))->toBe('https://portal.test/support/feedback/' . $token);

    // Closed again without feedback: the same link again.
    TicketFeedback::afterClose($di, 10);
    expect($mails)->toHaveCount(2)->and($mails[1]['feedback_url'])->toBe($mails[0]['feedback_url']);

    // A guest gets it at the ticket's address.
    TicketFeedback::afterClose($di, 11);
    expect($mails[2])->toMatchArray(['to' => 'guest@mail.test', 'name' => 'Guest Person']);
});

test('no feedback email while open, with the help desk switched off, or after feedback', function (): void {
    $mails = [];
    $db = ticketFeedbackDb();
    $di = ticketFeedbackContainer($db, $mails);

    TicketFeedback::afterClose($di, 12);
    TicketFeedback::saveHelpdesk($di, 2, '0');
    TicketFeedback::afterClose($di, 11);
    expect($mails)->toBe([])
        ->and(TicketFeedback::helpdeskOn($di, 2))->toBeFalse()
        ->and(TicketFeedback::helpdeskOn($di, 1))->toBeTrue();

    TicketFeedback::saveHelpdesk($di, 2, '1');
    expect(TicketFeedback::helpdeskOn($di, 2))->toBeTrue();

    $db->insert(TicketFeedback::FEEDBACK, ['ticket_id' => 10, 'admin_id' => null, 'rating' => null, 'comments' => 'Fine', 'created_at' => '2026-10-04 10:00:00']);
    TicketFeedback::afterClose($di, 10);
    expect($mails)->toBe([]);
});

test('the feedback page shows the ticket as WHMCS and takes one answer', function (): void {
    $mails = [];
    $db = ticketFeedbackDb();
    $di = ticketFeedbackContainer($db, $mails);
    TicketFeedback::afterClose($di, 10);
    $token = (string) $db->fetchOne('SELECT token FROM ' . TicketFeedback::REQUESTS . ' WHERE ticket_id = 10');

    $page = TicketFeedback::page($di, $token);
    // The cron account's autoresponse is not "staff involved".
    expect($page['state'])->toBe('form')
        ->and($page['staff'])->toBe([3 => 'Support Agent', 1 => 'Test Admin'])
        ->and($page['ticket'])->toMatchArray(['id' => 10, 'opened_at' => '2026-10-01 10:00:00', 'last_reply_at' => '2026-10-03 13:30:00', 'duration' => ['days' => 2, 'hours' => 3, 'minutes' => 30]]);

    // Every staff member involved needs a score from 1 to 10.
    expect(fn () => TicketFeedback::submit($di, $token, [3 => 9], [], null))->toThrow(InformationException::class, 'Test Admin')
        ->and(fn () => TicketFeedback::submit($di, $token, [3 => 9, 1 => 11], [], null))->toThrow(InformationException::class);

    TicketFeedback::submit($di, $token, ['3' => '9', '1' => 6], ['3' => " Quick and clear \r\n", 'generic' => 'Faster first reply'], '198.51.100.7');

    expect(TicketFeedback::forTicket($di, 10))->toMatchArray([
        'staff' => [
            ['admin_id' => 3, 'name' => 'Support Agent', 'rating' => 9, 'comments' => 'Quick and clear'],
            ['admin_id' => 1, 'name' => 'Test Admin', 'rating' => 6, 'comments' => ''],
        ],
        'comments' => 'Faster first reply',
    ])
        ->and(TicketFeedback::page($di, $token)['state'])->toBe('done')
        ->and(TicketFeedback::openLink($di, 10))->toBeNull()
        ->and(fn () => TicketFeedback::submit($di, $token, ['3' => 1, '1' => 1], [], null))->toThrow(InformationException::class, 'already given')
        ->and((int) $db->fetchOne('SELECT COUNT(*) FROM ' . TicketFeedback::FEEDBACK))->toBe(3);
});

test('feedback waits while the ticket is open again; unknown links are refused', function (): void {
    $mails = [];
    $db = ticketFeedbackDb();
    $di = ticketFeedbackContainer($db, $mails);
    TicketFeedback::afterClose($di, 13);
    $token = (string) $db->fetchOne('SELECT token FROM ' . TicketFeedback::REQUESTS . ' WHERE ticket_id = 13');

    // No staff replied: only the general comment, which is then required.
    expect(TicketFeedback::page($di, $token)['staff'])->toBe([])
        ->and(fn () => TicketFeedback::submit($di, $token, [], ['generic' => '  '], null))->toThrow(InformationException::class);

    $db->update('support_ticket', ['status' => 'open'], ['id' => 13]);
    expect(TicketFeedback::page($di, $token)['state'])->toBe('open')
        ->and(fn () => TicketFeedback::submit($di, $token, [], ['generic' => 'ok'], null))->toThrow(InformationException::class, 'closed')
        ->and(fn () => TicketFeedback::page($di, str_repeat('a', 64)))->toThrow(InformationException::class)
        ->and(fn () => TicketFeedback::page($di, 'not-a-token'))->toThrow(InformationException::class);
});

test('the report by staff member and the comments', function (): void {
    $mails = [];
    $db = ticketFeedbackDb();
    $di = ticketFeedbackContainer($db, $mails);
    TicketFeedback::rate($di, 10, 102, 5, 'client', 5, null);
    TicketFeedback::rate($di, 10, 104, 2, 'client', 5, null);
    TicketFeedback::rate($di, 11, 110, 4, 'guest', 0, null);
    TicketFeedback::afterClose($di, 10);
    TicketFeedback::submit($di, (string) $db->fetchOne('SELECT token FROM ' . TicketFeedback::REQUESTS), [3 => 10, 1 => 7], [1 => 'Polite', 'generic' => 'Great'], null);

    $report = TicketFeedback::report($di, 'all');

    expect($report['period'])->toBe('all')
        ->and($report['ratings'])->toBe([
            ['admin_id' => 3, 'name' => 'Support Agent', 'total' => 2, 'average' => 4.5, 'stars' => [1 => 0, 2 => 0, 3 => 0, 4 => 1, 5 => 1]],
            ['admin_id' => 1, 'name' => 'Test Admin', 'total' => 1, 'average' => 2.0, 'stars' => [1 => 0, 2 => 1, 3 => 0, 4 => 0, 5 => 0]],
        ])
        ->and($report['feedback'])->toBe([
            ['admin_id' => 3, 'name' => 'Support Agent', 'total' => 1, 'average' => 10.0],
            ['admin_id' => 1, 'name' => 'Test Admin', 'total' => 1, 'average' => 7.0],
        ])
        ->and(array_column($report['comments'], 'comments'))->toBe(['Great', 'Polite'])
        ->and(TicketFeedback::report($di, 'bogus')['period'])->toBe('90');
});

test('ratings, feedback and links of deleted tickets are swept', function (): void {
    $mails = [];
    $db = ticketFeedbackDb();
    $di = ticketFeedbackContainer($db, $mails);
    TicketFeedback::rate($di, 11, 110, 4, 'guest', 0, null);
    TicketFeedback::afterClose($di, 11);
    TicketFeedback::afterClose($di, 10);
    $db->delete('support_ticket', ['id' => 11]);

    expect(TicketFeedback::sweep($di))->toBe(2)
        ->and($db->fetchFirstColumn('SELECT ticket_id FROM ' . TicketFeedback::REQUESTS))->toBe([10]);
});

test('a merge takes the stars, the feedback and an open link to the ticket that stays', function (): void {
    $mails = [];
    $db = ticketFeedbackDb();
    $di = ticketFeedbackContainer($db, $mails);
    // Ticket 13 (no feedback, no link) stays; 10 and 12 go into it.
    TicketFeedback::rate($di, 10, 102, 5, 'client', 5, null);
    TicketFeedback::afterClose($di, 10);
    $db->insert(TicketFeedback::REQUESTS, ['ticket_id' => 12, 'token' => str_repeat('b', 64), 'sent_at' => '2026-10-01 10:00:00', 'answered_at' => null]);
    $token10 = (string) $db->fetchOne('SELECT token FROM ' . TicketFeedback::REQUESTS . ' WHERE ticket_id = 10');

    TicketFeedback::moveForMerge($db, 13, [10, 12]);

    // The newest open link (ticket 10's) now leads to 13; the other ends.
    expect($db->fetchAllKeyValue('SELECT ticket_id, token FROM ' . TicketFeedback::REQUESTS))->toBe([13 => $token10])
        ->and($db->fetchFirstColumn('SELECT ticket_id FROM ' . TicketFeedback::RATINGS))->toBe([13]);

    // With the replies moved and the merged tickets deleted, the sweep keeps them.
    $db->executeStatement('UPDATE support_ticket_message SET support_ticket_id = 13 WHERE support_ticket_id IN (10, 12)');
    $db->executeStatement('DELETE FROM support_ticket WHERE id IN (10, 12)');
    expect(TicketFeedback::sweep($di))->toBe(0)
        ->and(TicketFeedback::ratings($di, 13))->toBe([102 => 5]);

    // Missing tables (not made yet) are skipped.
    $bare = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    TicketFeedback::moveForMerge($bare, 1, [2]);
});

test('staff closing a ticket from its status field ask for feedback; a rule does not', function (): void {
    $mails = [];
    $db = ticketFeedbackDb();
    $di = ticketFeedbackContainer($db, $mails);
    $db->update('support_ticket', ['status' => 'open'], ['id' => 10]);

    $ticket = new ClientFence\Mod\Support\Entity\SupportTicket();
    Tests\Helpers\setEntityId($ticket, 10);
    $service = Mockery::mock(ClientFence\Mod\Support\Service::class)->makePartial();
    $service->shouldReceive('getTicketById')->andReturn($ticket);
    $service->shouldReceive('ticketUpdate')->andReturnUsing(function ($model, array $data) use ($db): bool {
        $model->setStatus($data['status']);
        $db->update('support_ticket', ['status' => $data['status']], ['id' => 10]);

        return true;
    });
    $modules = $di['mod_service'];
    $staff = Mockery::mock(ClientFence\Mod\Staff\Service::class);
    $staff->shouldReceive('checkPermissionsAndThrowException');
    $di['mod_service'] = $di->protect(fn (string $mod): ?object => $mod === 'Staff' ? $staff : $modules($mod));
    $api = new ClientFence\Mod\Support\Api\Admin();
    $api->setDi($di);
    $api->setService($service);

    expect($api->ticket_update(['id' => 10, 'status' => 'closed']))->toBeTrue()
        ->and($mails)->toHaveCount(1)
        ->and($mails[0]['code'])->toBe('mod_support_ticket_feedback_request');

    // Saving it again while closed asks nothing more.
    $api->ticket_update(['id' => 10, 'status' => 'closed']);
    expect($mails)->toHaveCount(1);
});

test('authorized users with ticket access may rate replies', function (): void {
    expect(ClientFence\Mod\Client\Users::CALLS['support']['ticket_message_rate'])->toBe('tickets');
});
