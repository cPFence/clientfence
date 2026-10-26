<?php

declare(strict_types=1);

/**
 * ClientFence: escalation rules, as WHMCS (Support\TicketEscalation), on an in-memory SQLite copy of
 * the tables, with a stand-in for the Support service that records what a rule does.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Support\TicketAssign;
use ClientFence\Mod\Support\TicketEscalation;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

function ticketEscalationDb(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
        'CREATE TABLE support_ticket (id INTEGER PRIMARY KEY, subject TEXT, status TEXT, priority INTEGER, support_helpdesk_id INTEGER, client_id INTEGER, author_name TEXT, author_email TEXT)',
        'CREATE TABLE support_ticket_message (id INTEGER PRIMARY KEY AUTOINCREMENT, support_ticket_id INTEGER, admin_id INTEGER, client_id INTEGER, content TEXT, created_at TEXT)',
        'CREATE TABLE support_helpdesk (id INTEGER PRIMARY KEY, name TEXT)',
        'CREATE TABLE client (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT)',
        'CREATE TABLE admin (id INTEGER PRIMARY KEY, name TEXT, system_name TEXT, status TEXT)',
        'CREATE TABLE ' . TicketAssign::TABLE . ' (ticket_id INTEGER PRIMARY KEY, admin_id INTEGER NOT NULL, assigned_by INTEGER, assigned_at TEXT NOT NULL)',
        'CREATE TABLE ' . TicketEscalation::RULES . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1, helpdesks TEXT NOT NULL, statuses TEXT NOT NULL, priorities TEXT NOT NULL, minutes INTEGER NOT NULL, new_helpdesk_id INTEGER, new_status TEXT NOT NULL DEFAULT \'\', new_priority TEXT NOT NULL DEFAULT \'\', assign_to INTEGER, notify TEXT NOT NULL, reply TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)',
        'CREATE TABLE ' . TicketEscalation::APPLIED . ' (rule_id INTEGER NOT NULL, ticket_id INTEGER NOT NULL, reply_at TEXT NOT NULL, applied_at TEXT NOT NULL, PRIMARY KEY (rule_id, ticket_id))',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    $db->insert('support_helpdesk', ['id' => 1, 'name' => 'Technical support']);
    $db->insert('support_helpdesk', ['id' => 2, 'name' => 'Billing']);
    $db->insert('client', ['id' => 5, 'first_name' => 'Alice', 'last_name' => 'Smith', 'email' => 'alice@example.com']);
    foreach ([[1, 'Test Admin', null, 'active'], [2, 'System Cron Job', 'cron', 'active'], [3, 'Support Agent', null, 'active'], [4, 'Former Staff', null, 'inactive']] as [$id, $name, $system, $status]) {
        $db->insert('admin', ['id' => $id, 'name' => $name, 'system_name' => $system, 'status' => $status]);
    }

    return $db;
}

/** A ticket whose last reply (by the client) was `$minutesAgo` minutes before `$at`. */
function ticketEscalationTicket(Connection $db, int $id, int $at, int $minutesAgo, array $ticket = []): void
{
    $db->insert('support_ticket', $ticket + ['id' => $id, 'subject' => 'Ticket ' . $id, 'status' => 'open', 'priority' => 100, 'support_helpdesk_id' => 1, 'client_id' => 5, 'author_name' => null, 'author_email' => null]);
    $db->insert('support_ticket_message', ['support_ticket_id' => $id, 'client_id' => 5, 'content' => 'Help', 'created_at' => date('Y-m-d H:i:s', $at - $minutesAgo * 60)]);
}

/**
 * @param list<array> $mails the emails sent
 * @param list<array> $calls what the rule asked the Support service to do
 */
function ticketEscalationContainer(Connection $db, array &$mails, array &$calls): Pimple\Container
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
        public function adminLink(string $path): string
        {
            return 'https://portal.test/admin/' . $path;
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
    $support = new class($db, $calls) {
        public function __construct(private Connection $db, private array &$calls)
        {
        }

        public function getTicketById(int $id): object
        {
            return (object) ['id' => $id];
        }

        public function ticketReply(object $ticket, string $admin, string $content): int
        {
            $this->calls[] = ['reply', $ticket->id, $admin, $content];
            // As the system's own reply, it is not a reply the time counts from.
            $this->db->insert('support_ticket_message', ['support_ticket_id' => $ticket->id, 'admin_id' => 2, 'content' => $content, 'created_at' => date('Y-m-d H:i:s')]);

            return 1;
        }

        public function ticketUpdate(object $ticket, array $data): bool
        {
            $this->calls[] = ['update', $ticket->id, $data];

            return true;
        }
    };
    $staff = new class {
        public function getCronAdmin(): string
        {
            return 'cron admin';
        }
    };
    $di['mod_service'] = $di->protect(fn (string $mod): ?object => match ($mod) {
        'email' => $email,
        'support' => $support,
        'staff' => $staff,
        default => null,
    });

    return $di;
}

function ticketEscalationRule(array $rule = []): array
{
    return $rule + ['name' => 'Waiting an hour', 'minutes' => 60, 'helpdesks' => [1], 'statuses' => ['open'], 'priorities' => [], 'reply' => 'Hello [FIRSTNAME] ([NAME], [EMAIL]), we are on it.'];
}

test('a rule is saved, read back, changed and deleted', function (): void {
    $mails = $calls = [];
    $di = ticketEscalationContainer(ticketEscalationDb(), $mails, $calls);

    $id = TicketEscalation::save($di, ticketEscalationRule(['helpdesks' => ['1', '2', '2'], 'statuses' => 'open,on_hold', 'priorities' => ['High'], 'new_priority' => 'high', 'assign_to' => 3, 'notify' => [1, 3], 'active' => '0']));
    $rule = TicketEscalation::get($di, $id);
    expect($rule)->toMatchArray([
        'name' => 'Waiting an hour', 'active' => false, 'helpdesks' => [1, 2], 'statuses' => ['open', 'on_hold'], 'priorities' => ['high'],
        'minutes' => 60, 'new_helpdesk_id' => null, 'new_status' => '', 'new_priority' => 'high', 'assign_to' => 3, 'notify' => [1, 3],
    ]);

    expect(TicketEscalation::save($di, ['id' => $id, 'name' => 'Renamed', 'minutes' => 30, 'new_status' => 'on_hold']))->toBe($id)
        ->and(TicketEscalation::rules($di))->toHaveCount(1)
        ->and(TicketEscalation::get($di, $id))->toMatchArray(['name' => 'Renamed', 'active' => true, 'helpdesks' => [], 'reply' => '', 'new_status' => 'on_hold']);

    TicketEscalation::delete($di, $id);
    expect(TicketEscalation::rules($di))->toBe([]);
    TicketEscalation::get($di, $id);
})->throws(InformationException::class, 'Escalation rule not found');

test('a rule needs a name, a time, at least one action and existing choices', function (array $rule, string $error): void {
    $mails = $calls = [];
    $di = ticketEscalationContainer(ticketEscalationDb(), $mails, $calls);

    expect(fn () => TicketEscalation::save($di, $rule))->toThrow(InformationException::class, $error);
})->with([
    'no name' => [['name' => ' ', 'minutes' => 60, 'reply' => 'x'], 'Name the rule'],
    'no time' => [['name' => 'A', 'minutes' => 0, 'reply' => 'x'], 'Set the time without a reply'],
    'no action' => [['name' => 'A', 'minutes' => 60], 'Choose at least one action.'],
    'unknown help desk' => [['name' => 'A', 'minutes' => 60, 'helpdesks' => [9], 'reply' => 'x'], 'Choose existing help desks.'],
    'unknown status' => [['name' => 'A', 'minutes' => 60, 'statuses' => ['answered'], 'reply' => 'x'], 'Choose ticket statuses.'],
    'unknown priority' => [['name' => 'A', 'minutes' => 60, 'new_priority' => 'urgent'], 'Choose a priority'],
    'inactive assignee' => [['name' => 'A', 'minutes' => 60, 'assign_to' => 4], 'Choose an active staff member.'],
    'system notified' => [['name' => 'A', 'minutes' => 60, 'notify' => [2]], 'Choose active staff members to email.'],
    'unknown move' => [['name' => 'A', 'minutes' => 60, 'new_helpdesk_id' => 9], 'Choose an existing help desk.'],
]);

test('a rule acts once per reply on the tickets it matches whose time ran out after it was made', function (): void {
    $mails = $calls = [];
    $db = ticketEscalationDb();
    $di = ticketEscalationContainer($db, $mails, $calls);
    $id = TicketEscalation::save($di, ticketEscalationRule(['priorities' => ['high', 'medium'], 'new_helpdesk_id' => 2, 'new_priority' => 'high', 'new_status' => 'on_hold', 'assign_to' => 3, 'notify' => [1]]));
    $made = strtotime((string) TicketEscalation::get($di, $id)['created_at']);

    ticketEscalationTicket($db, 10, $made, 30);                                     // due 30 minutes after the rule was made
    ticketEscalationTicket($db, 11, $made, 120);                                    // its time ran out before the rule was made
    ticketEscalationTicket($db, 12, $made, 30, ['support_helpdesk_id' => 2]);       // other help desk
    ticketEscalationTicket($db, 13, $made, 30, ['status' => 'closed']);             // other status
    ticketEscalationTicket($db, 14, $made, 30, ['priority' => 50]);                 // low priority
    ticketEscalationTicket($db, 15, $made, 30, ['client_id' => null, 'author_name' => 'Bob Guest', 'author_email' => 'bob@example.com']);

    expect(TicketEscalation::run($di, $made + 29 * 60))->toBe(0);
    expect(TicketEscalation::run($di, $made + 31 * 60))->toBe(2)
        ->and($calls)->toBe([
            ['reply', 10, 'cron admin', 'Hello Alice (Alice Smith, alice@example.com), we are on it.'],
            ['update', 10, ['support_helpdesk_id' => 2, 'priority' => 'high', 'status' => 'on_hold']],
            ['reply', 15, 'cron admin', 'Hello Bob (Bob Guest, bob@example.com), we are on it.'],
            ['update', 15, ['support_helpdesk_id' => 2, 'priority' => 'high', 'status' => 'on_hold']],
        ])
        ->and(TicketAssign::assignee($di, 10))->toBe(3)
        ->and(array_column($mails, 'code'))->toBe(['mod_staff_ticket_assigned', 'mod_staff_ticket_escalated', 'mod_staff_ticket_assigned', 'mod_staff_ticket_escalated'])
        ->and($mails[1])->toMatchArray(['to_admin' => 1, 'rule' => ['name' => 'Waiting an hour', 'minutes' => 60]])
        ->and($mails[1]['ticket'])->toMatchArray(['id' => 10, 'subject' => 'Ticket 10', 'helpdesk' => 'Technical support', 'url' => 'https://portal.test/admin/support/ticket/10']);

    // Its own reply does not restart the time: no second action for the same reply.
    $calls = [];
    expect(TicketEscalation::run($di, $made + 200 * 60))->toBe(0)->and($calls)->toBe([]);

    // A new reply from the client: it acts again once that one's time runs out.
    $db->insert('support_ticket_message', ['support_ticket_id' => 10, 'client_id' => 5, 'content' => 'Still waiting', 'created_at' => date('Y-m-d H:i:s', $made + 210 * 60)]);
    expect(TicketEscalation::run($di, $made + 250 * 60))->toBe(0)
        ->and(TicketEscalation::run($di, $made + 271 * 60))->toBe(1)
        ->and(array_column($calls, 1))->toBe([10, 10]);
});

test('a paused rule does nothing, and a failing ticket does not stop the others', function (): void {
    $mails = $calls = [];
    $db = ticketEscalationDb();
    $di = ticketEscalationContainer($db, $mails, $calls);
    $paused = TicketEscalation::save($di, ticketEscalationRule(['active' => false]));
    $made = strtotime((string) TicketEscalation::get($di, $paused)['created_at']);
    ticketEscalationTicket($db, 10, $made, 30);
    ticketEscalationTicket($db, 11, $made, 30);
    expect(TicketEscalation::run($di, $made + 31 * 60))->toBe(0);

    // A notified staff member who has since left is skipped; ticket 10's assignee is gone too.
    $active = TicketEscalation::save($di, ticketEscalationRule(['name' => 'Assign', 'reply' => '', 'assign_to' => 3, 'notify' => [1]]));
    $db->update('admin', ['status' => 'inactive'], ['id' => 3]);
    expect(TicketEscalation::run($di, $made + 31 * 60))->toBe(0)
        ->and($di['logger']->warnings)->toHaveCount(2)
        ->and($di['logger']->warnings[0])->toContain('escalation rule "Assign" failed on ticket #10');
    // Each ticket is tried once for that reply, not again on every run.
    expect(TicketEscalation::run($di, $made + 40 * 60))->toBe(0)
        ->and($di['logger']->warnings)->toHaveCount(2);
    TicketEscalation::delete($di, $active);
});

test('marks of deleted tickets and rules are swept', function (): void {
    $mails = $calls = [];
    $db = ticketEscalationDb();
    $di = ticketEscalationContainer($db, $mails, $calls);
    $id = TicketEscalation::save($di, ticketEscalationRule());
    $made = strtotime((string) TicketEscalation::get($di, $id)['created_at']);
    ticketEscalationTicket($db, 10, $made, 30);
    ticketEscalationTicket($db, 11, $made, 30);
    TicketEscalation::run($di, $made + 31 * 60);
    $db->insert(TicketEscalation::APPLIED, ['rule_id' => 99, 'ticket_id' => 11, 'reply_at' => '2026-01-01 00:00:00', 'applied_at' => '2026-01-01 00:00:00']);
    $db->delete('support_ticket', ['id' => 10]);

    expect(TicketEscalation::sweep($di))->toBe(2)
        ->and($db->fetchAllNumeric('SELECT rule_id, ticket_id FROM ' . TicketEscalation::APPLIED))->toBe([[$id, 11]]);

    // Without the tables (not made yet), nothing breaks.
    $bare = ticketEscalationContainer(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $mails, $calls);
    expect(TicketEscalation::rules($bare))->toBe([])
        ->and(TicketEscalation::run($bare))->toBe(0)
        ->and(TicketEscalation::sweep($bare))->toBe(0);
});
