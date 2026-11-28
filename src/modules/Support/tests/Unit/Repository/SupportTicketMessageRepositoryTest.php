<?php

declare(strict_types=1);

use ClientFence\Mod\Support\Entity\SupportTicketMessage;
use ClientFence\Mod\Support\Repository\SupportTicketMessageRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

test('last reply time is the newest message timestamp per ticket', function (): void {
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE support_ticket_message (id INTEGER PRIMARY KEY, support_ticket_id INTEGER, created_at TEXT)');
    foreach ([
        [10, '2026-11-28 12:00:00'],
        [10, '2026-11-28 09:00:00'],
        [11, '2026-11-28 10:00:00'],
        [11, '2026-11-28 11:00:00'],
    ] as [$ticketId, $createdAt]) {
        $db->insert('support_ticket_message', ['support_ticket_id' => $ticketId, 'created_at' => $createdAt]);
    }

    $em = Mockery::mock(EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->once()->andReturn($db);
    $repository = new SupportTicketMessageRepository($em, new ClassMetadata(SupportTicketMessage::class));

    expect($repository->lastMessageAtByTicketIds([10, 11, 12]))->toEqual([
        10 => '2026-11-28 09:00:00',
        11 => '2026-11-28 11:00:00',
    ]);
});
