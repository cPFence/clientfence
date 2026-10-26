<?php

declare(strict_types=1);

/**
 * ClientFence: tickets assigned to staff, as WHMCS's Flag (Support\TicketAssign), on an in-memory
 * SQLite copy of the tables.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Support\TicketAssign;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

function ticketAssignDb(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
        'CREATE TABLE support_ticket (id INTEGER PRIMARY KEY, subject TEXT, status TEXT, priority INTEGER, support_helpdesk_id INTEGER)',
        'CREATE TABLE support_helpdesk (id INTEGER PRIMARY KEY, name TEXT)',
        'CREATE TABLE admin (id INTEGER PRIMARY KEY, name TEXT, system_name TEXT, status TEXT)',
        'CREATE TABLE ' . TicketAssign::TABLE . ' (ticket_id INTEGER PRIMARY KEY, admin_id INTEGER NOT NULL, assigned_by INTEGER, assigned_at TEXT NOT NULL)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    $db->insert('support_helpdesk', ['id' => 1, 'name' => 'Technical support']);
    foreach ([[1, 'Test Admin', null, 'active'], [2, 'System Cron Job', 'cron', 'active'], [3, 'Support Agent', null, 'active'], [4, 'Former Staff', null, 'inactive']] as [$id, $name, $system, $status]) {
        $db->insert('admin', ['id' => $id, 'name' => $name, 'system_name' => $system, 'status' => $status]);
    }
    foreach ([10, 11, 12] as $id) {
        $db->insert('support_ticket', ['id' => $id, 'subject' => 'Ticket ' . $id, 'status' => 'open', 'priority' => 150, 'support_helpdesk_id' => 1]);
    }

    return $db;
}

/** @param list<array> $mails the emails sent */
function ticketAssignContainer(Connection $db, array &$mails): Pimple\Container
{
    $di = new Pimple\Container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;
    $di['logger'] = new class {
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
    $di['mod_service'] = $di->protect(fn (string $mod): ?object => $mod === 'email' ? $email : null);

    return $di;
}

test('staff a ticket can be assigned to are the active people, by name', function (): void {
    $mails = [];
    $di = ticketAssignContainer(ticketAssignDb(), $mails);

    expect(TicketAssign::staff($di))->toBe([3 => 'Support Agent', 1 => 'Test Admin']);
});

test('assigning emails the assignee unless they assigned themselves', function (): void {
    $mails = [];
    $db = ticketAssignDb();
    $di = ticketAssignContainer($db, $mails);

    expect(TicketAssign::assign($di, 10, 3, 1))->toBeTrue()
        ->and(TicketAssign::assignee($di, 10))->toBe(3)
        ->and($mails)->toHaveCount(1)
        ->and($mails[0]['to_admin'])->toBe(3)
        ->and($mails[0]['code'])->toBe('mod_staff_ticket_assigned')
        ->and($mails[0]['assigned_by'])->toBe('Test Admin')
        ->and($mails[0]['ticket'])->toMatchArray(['id' => 10, 'priority' => 'high', 'priority_title' => 'High', 'status_title' => 'Open', 'helpdesk' => 'Technical support', 'url' => 'https://portal.test/admin/support/ticket/10']);

    // The same assignee again changes nothing and sends nothing.
    expect(TicketAssign::assign($di, 10, 3, 1))->toBeFalse()->and($mails)->toHaveCount(1);

    // Taking it yourself: no email. A rule (no staff member) always emails.
    expect(TicketAssign::assign($di, 10, 1, 1))->toBeTrue()->and($mails)->toHaveCount(1)
        ->and(TicketAssign::assign($di, 11, 1, null))->toBeTrue()->and($mails)->toHaveCount(2)
        ->and($mails[1]['assigned_by'])->toBe('')
        ->and($db->fetchOne('SELECT COUNT(*) FROM ' . TicketAssign::TABLE))->toBe(2);

    // Nobody removes the row.
    expect(TicketAssign::assign($di, 10, 0, 1))->toBeTrue()
        ->and(TicketAssign::assignee($di, 10))->toBeNull()
        ->and(TicketAssign::assign($di, 10, null, 1))->toBeFalse();
});

test('only active staff, and only existing tickets', function (int $ticket, int $admin): void {
    $mails = [];
    $di = ticketAssignContainer(ticketAssignDb(), $mails);

    expect(fn () => TicketAssign::assign($di, $ticket, $admin, 1))->toThrow(InformationException::class);
    expect($mails)->toBe([]);
})->with([
    'cron account' => [10, 2],
    'inactive' => [10, 4],
    'unknown staff' => [10, 99],
    'unknown ticket' => [99, 3],
]);

test('a deactivated assignee leaves the ticket unassigned', function (): void {
    $mails = [];
    $db = ticketAssignDb();
    $di = ticketAssignContainer($db, $mails);
    TicketAssign::assign($di, 10, 3, 1);
    TicketAssign::assign($di, 11, 1, 1);
    $db->update('admin', ['status' => 'inactive'], ['id' => 3]);

    expect(TicketAssign::assignee($di, 10))->toBeNull()
        ->and(TicketAssign::assignees($di, [10, 11, 12]))->toBe([11 => ['id' => 1, 'name' => 'Test Admin']]);
});

test('list filters', function (): void {
    $mails = [];
    $db = ticketAssignDb();
    $di = ticketAssignContainer($db, $mails);
    TicketAssign::assign($di, 10, 3, 1);
    TicketAssign::assign($di, 11, 1, 1);

    expect(TicketAssign::listFilter($di, '', 1))->toBeNull()
        ->and(TicketAssign::listFilter($di, 'me', 1))->toBe(['in', [11]])
        ->and(TicketAssign::listFilter($di, 'ME', 3))->toBe(['in', [10]])
        ->and(TicketAssign::listFilter($di, '3', 1))->toBe(['in', [10]])
        ->and(TicketAssign::listFilter($di, 'any', 1))->toBe(['in', [11, 10]])
        ->and(TicketAssign::listFilter($di, 'none', 1))->toBe(['not_in', [11, 10]])
        ->and(TicketAssign::listFilter($di, '4', 1))->toBe(['in', []])
        ->and(fn () => TicketAssign::listFilter($di, 'someone', 1))->toThrow(InformationException::class);
});

test('assignments of deleted tickets are swept', function (): void {
    $mails = [];
    $db = ticketAssignDb();
    $di = ticketAssignContainer($db, $mails);
    TicketAssign::assign($di, 10, 3, 1);
    TicketAssign::assign($di, 11, 3, 1);
    $db->delete('support_ticket', ['id' => 11]);

    expect(TicketAssign::sweep($di))->toBe(1)
        ->and($db->fetchFirstColumn('SELECT ticket_id FROM ' . TicketAssign::TABLE))->toBe([10]);
});
