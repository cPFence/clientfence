<?php

declare(strict_types=1);

/**
 * ClientFence: the ticket list's bulk actions (Support\TicketBulk) and Block Sender & Delete's
 * blocked senders list (MailImport::blockSenders()), on an in-memory SQLite copy of the tables.
 */

use ClientFence\Mod\Support\MailImport;
use ClientFence\Mod\Support\TicketBulk;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use ClientFence\InformationException;

function ticketBulkDb(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
        'CREATE TABLE client (id INTEGER PRIMARY KEY, email TEXT)',
        'CREATE TABLE support_ticket (id INTEGER PRIMARY KEY, client_id INTEGER, author_email TEXT)',
        'CREATE TABLE support_helpdesk (id INTEGER PRIMARY KEY, email TEXT)',
        'CREATE TABLE admin (id INTEGER PRIMARY KEY, email TEXT)',
        'CREATE TABLE ' . MailImport::MAILBOX_TABLE . ' (helpdesk_id INTEGER PRIMARY KEY, username TEXT)',
        'CREATE TABLE ' . TicketBulk::PINS . ' (ticket_id INTEGER PRIMARY KEY, pinned_by INTEGER, pinned_at TEXT NOT NULL)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    $db->insert('client', ['id' => 5, 'email' => 'Alice@Client.test']);
    foreach ([[10, 5, null], [11, null, 'Spammer@Junk.test'], [12, null, 'spammer@junk.test'], [13, null, 'support@clientfence.test'], [14, 5, null]] as [$id, $client, $email]) {
        $db->insert('support_ticket', ['id' => $id, 'client_id' => $client, 'author_email' => $email]);
    }
    $db->insert('support_helpdesk', ['id' => 1, 'email' => 'support@clientfence.test']);
    $db->insert('admin', ['id' => 1, 'email' => 'staff@clientfence.test']);

    return $db;
}

function ticketBulkContainer(Connection $db, ArrayObject $config): Pimple\Container
{
    $di = new Pimple\Container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;
    $di['mod_config'] = $di->protect(fn (string $mod): array => $mod === 'support' ? $config->getArrayCopy() : []);
    $extension = new class($config) {
        public function __construct(private readonly ArrayObject $config)
        {
        }

        public function getConfig(string $ext): array
        {
            return $this->config->getArrayCopy();
        }

        public function setConfig(array $data): void
        {
            unset($data['ext']);
            $this->config->exchangeArray($data);
        }
    };
    $system = Mockery::mock();
    $system->shouldReceive('getParamValue')->andReturn('hello@clientfence.test');
    $di['mod_service'] = $di->protect(fn (string $mod): ?object => match ($mod) {
        'extension' => $extension,
        'system' => $system,
        default => null,
    });

    return $di;
}

test('selected ids are cleaned and bounded', function (): void {
    expect(TicketBulk::ids(['12', 10, '12', 'x', -3, 0]))->toBe([12, 10])
        ->and(TicketBulk::ids('5, 6,7'))->toBe([5, 6, 7])
        ->and(fn () => TicketBulk::ids([]))->toThrow(InformationException::class, 'at least one')
        ->and(fn () => TicketBulk::ids(range(1, TicketBulk::MAX + 1)))->toThrow(InformationException::class, 'At most');
});

test('pins keep pinned tickets first, newest first, and go with their tickets', function (): void {
    $db = ticketBulkDb();
    $di = ticketBulkContainer($db, new ArrayObject());

    expect(TicketBulk::pin($di, [10, 12, 99], 3))->toBe(2)
        ->and(TicketBulk::pin($di, [10], 3))->toBe(1)
        ->and(TicketBulk::pinnedIds($di))->toBe([12, 10])
        ->and($db->fetchOne('SELECT pinned_by FROM ' . TicketBulk::PINS . ' WHERE ticket_id = 10'))->toBe(3)
        ->and(TicketBulk::unpin($di, [12, 14]))->toBe(1)
        ->and(TicketBulk::pinnedIds($di))->toBe([10]);

    $db->delete('support_ticket', ['id' => 10]);
    expect(TicketBulk::pinnedIds($di))->toBe([])
        ->and(TicketBulk::sweep($di))->toBe(1)
        ->and(TicketBulk::pinnedIds(ticketBulkContainer(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), new ArrayObject())))->toBe([]);
});

test('senders are the guest address or the client email, once each', function (): void {
    $db = ticketBulkDb();
    $di = ticketBulkContainer($db, new ArrayObject());

    expect(TicketBulk::senders($di, [10, 11, 12, 14]))->toBe(['alice@client.test', 'spammer@junk.test']);
});

test('blocking adds new senders only, never our own addresses', function (): void {
    $db = ticketBulkDb();
    $config = new ArrayObject(['mail_block_senders' => "@junk.example\nold@spam.test", 'mail_reply_limit' => '7']);
    $di = ticketBulkContainer($db, $config);

    expect(MailImport::blockSenders($di, ['Spammer@Junk.test', 'spammer@junk.test', 'OLD@spam.test', 'support@clientfence.test', 'staff@clientfence.test', 'hello@clientfence.test', 'not an email']))
        ->toBe(['spammer@junk.test'])
        ->and($config['mail_block_senders'])->toBe("@junk.example\nold@spam.test\nspammer@junk.test")
        // Other support settings are kept.
        ->and($config['mail_reply_limit'])->toBe('7')
        ->and(MailImport::blockSenders($di, ['spammer@junk.test']))->toBe([]);

    $config['mail_block_senders'] = str_repeat('x', 19990);
    expect(fn () => MailImport::blockSenders($di, ['new@sender.test']))->toThrow(InformationException::class, 'full');
});

test('a client ticket an authorized user opened blocks that user, not the account owner', function (): void {
    $db = ticketBulkDb();
    foreach ([
        'CREATE TABLE client_user (id INTEGER PRIMARY KEY, email TEXT)',
        'CREATE TABLE support_ticket_message (id INTEGER PRIMARY KEY, support_ticket_id INTEGER)',
        'CREATE TABLE support_ticket_author (message_id INTEGER PRIMARY KEY, ticket_id INTEGER, client_id INTEGER, user_id INTEGER, name TEXT, created_at TEXT)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    $db->insert('client_user', ['id' => 7, 'email' => 'Dev@Agency.test']);
    // Ticket 10: opened by user 7. Ticket 14: opened by the owner, user 7 replied later.
    foreach ([[100, 10], [101, 10], [102, 14], [103, 14]] as [$message, $ticket]) {
        $db->insert('support_ticket_message', ['id' => $message, 'support_ticket_id' => $ticket]);
    }
    $db->insert('support_ticket_author', ['message_id' => 100, 'ticket_id' => 10, 'client_id' => 5, 'user_id' => 7, 'name' => 'Dev', 'created_at' => '2026-10-16 10:00:00']);
    $db->insert('support_ticket_author', ['message_id' => 103, 'ticket_id' => 14, 'client_id' => 5, 'user_id' => 7, 'name' => 'Dev', 'created_at' => '2026-10-16 11:00:00']);
    $di = ticketBulkContainer($db, new ArrayObject());

    expect(TicketBulk::senders($di, [10]))->toBe(['dev@agency.test'])
        ->and(TicketBulk::senders($di, [14]))->toBe(['alice@client.test'])
        ->and(TicketBulk::senders($di, [10, 11, 14]))->toBe(['dev@agency.test', 'spammer@junk.test', 'alice@client.test']);
});
