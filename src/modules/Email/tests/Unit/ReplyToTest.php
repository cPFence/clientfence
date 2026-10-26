<?php

declare(strict_types=1);

/**
 * ClientFence: a Reply-To kept for one queued email (Email\ReplyTo), on an in-memory SQLite copy of
 * the queue and its side table.
 */

use ClientFence\Mod\Email\ReplyTo;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

function replyToContainer(?Connection $db): Pimple\Container
{
    $di = new Pimple\Container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $db === null ? $em->shouldReceive('getConnection')->andThrow(new RuntimeException('down')) : $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;

    return $di;
}

test('a queued email keeps its own Reply-To until it is sent', function (): void {
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE email_queue (id INTEGER PRIMARY KEY)');
    $db->executeStatement('CREATE TABLE ' . ReplyTo::TABLE . ' (queue_id INTEGER PRIMARY KEY, reply_to TEXT NOT NULL, created_at TEXT NOT NULL)');
    $db->insert('email_queue', ['id' => 7]);
    $db->insert('email_queue', ['id' => 8]);
    $di = replyToContainer($db);

    ReplyTo::remember($di, 7, ' help@clientfence.test ');
    ReplyTo::remember($di, 8, 'not an address');
    ReplyTo::remember($di, 0, 'help@clientfence.test');
    expect(ReplyTo::find($di, 7))->toBe('help@clientfence.test')
        ->and(ReplyTo::find($di, 8))->toBeNull()
        ->and((int) $db->fetchOne('SELECT COUNT(*) FROM ' . ReplyTo::TABLE))->toBe(1);

    // Kept again for the same email: the newest address wins.
    ReplyTo::remember($di, 7, 'sales@clientfence.test');
    expect(ReplyTo::find($di, 7))->toBe('sales@clientfence.test');

    // Sent: its row goes, and so do day-old rows of emails no longer queued.
    $db->insert(ReplyTo::TABLE, ['queue_id' => 99, 'reply_to' => 'old@clientfence.test', 'created_at' => date('Y-m-d H:i:s', time() - 2 * 86400)]);
    $db->insert(ReplyTo::TABLE, ['queue_id' => 8, 'reply_to' => 'waiting@clientfence.test', 'created_at' => date('Y-m-d H:i:s', time() - 2 * 86400)]);
    $db->delete('email_queue', ['id' => 7]);
    ReplyTo::forget($di, 7);
    expect($db->fetchFirstColumn('SELECT queue_id FROM ' . ReplyTo::TABLE))->toBe([8]);
});

test('a missing table or database never stops an email', function (): void {
    $empty = replyToContainer(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
    $down = replyToContainer(null);
    foreach ([$empty, $down] as $di) {
        ReplyTo::remember($di, 1, 'help@clientfence.test');
        ReplyTo::forget($di, 1);
        expect(ReplyTo::find($di, 1))->toBeNull();
    }
});
