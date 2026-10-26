<?php

declare(strict_types=1);

namespace ClientFence\Mod\Support;

use ClientFence\Mod\Support\Entity\SupportTicket;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use ClientFence\InformationException;

/**
 * ClientFence: ticket merging, as WHMCS's "Merge Tickets" (ticket list bulk action, the ticket
 * page's Manage tab, API support/ticket_merge).
 *
 * The oldest ticket (lowest id) stays and gets every message, note, attachment, message author
 * and email import log entry of the others, which are deleted. Its subject gets " [MERGED]"
 * (or the subject staff typed), its status is that of the ticket with the latest message, and
 * a pending task (order cancel or upgrade) of another ticket moves to it when it has none.
 * Messages are shown oldest first by date (core patch in SupportTicketMessageRepository).
 * Cannot be undone.
 *
 * Unlike WHMCS, only tickets of the same owner can be merged: one client's tickets, or guest
 * tickets from the same email address, so no client sees another's messages or files.
 *
 * support_ticket_merge keeps each merged ticket's id and guest link: old ticket pages (staff,
 * client, guest link) open the ticket they were merged into, and emailed replies with an old
 * [Ticket #N] go to it (MailImport::decide()).
 */
final class TicketMerge
{
    public const TABLE = 'support_ticket_merge';
    /** Tickets merged in one go, at most. */
    public const MAX_TICKETS = 50;
    public const SUFFIX = ' [MERGED]';

    /**
     * Merges the tickets into the oldest; returns its id. The caller checks the staff
     * permission and makes sure the table exists (Clientfence\Service::ensureTables()).
     *
     * @param array<mixed> $ids
     *
     * @return array{id: int, merged: list<int>}
     */
    public static function merge(\Pimple\Container $di, array $ids, ?string $subject, int $adminId): array
    {
        $ids = self::ids($ids);
        if (count($ids) < 2) {
            throw new InformationException('Select at least two tickets to merge.');
        }
        if (count($ids) > self::MAX_TICKETS) {
            throw new InformationException('At most :max tickets can be merged at once.', [':max' => self::MAX_TICKETS]);
        }
        $subject = $subject === null ? '' : trim($subject);

        $db = self::db($di);
        $db->beginTransaction();
        try {
            $sql = 'SELECT id, client_id, author_email, subject, status, rel_type, rel_id, rel_task, rel_new_value, rel_status, access_hash, updated_at
                      FROM support_ticket WHERE id IN (?) ORDER BY id';
            if (self::isMysql($db)) {
                $sql .= ' FOR UPDATE';
            }
            $tickets = [];
            foreach ($db->fetchAllAssociative($sql, [$ids], [ArrayParameterType::INTEGER]) as $row) {
                $tickets[(int) $row['id']] = $row;
            }
            foreach ($ids as $id) {
                if (!isset($tickets[$id])) {
                    throw new InformationException('Ticket #:id was not found.', [':id' => $id]);
                }
            }
            self::checkOwner($tickets);

            $masterId = $ids[0];
            $master = $tickets[$masterId];
            $others = array_slice($ids, 1);
            $update = [
                'subject' => self::subject($subject, (string) $master['subject']),
                'status' => self::status($db, $tickets),
                'updated_at' => max(array_map(fn (array $t): string => (string) $t['updated_at'], $tickets)) ?: date('Y-m-d H:i:s'),
            ];
            $update += self::task($tickets, $masterId);

            $list = ['ids' => $others];
            $types = ['ids' => ArrayParameterType::INTEGER];
            $db->executeStatement('UPDATE support_ticket_note SET support_ticket_id = :master WHERE support_ticket_id IN (:ids)', ['master' => $masterId] + $list, $types);
            $db->executeStatement('UPDATE support_ticket_file SET ticket_id = :master WHERE ticket_id IN (:ids)', ['master' => $masterId] + $list, $types);
            $db->executeStatement('UPDATE support_ticket_author SET ticket_id = :master WHERE ticket_id IN (:ids)', ['master' => $masterId] + $list, $types);
            $db->executeStatement('UPDATE ' . MailImport::LOG_TABLE . ' SET ticket_id = :master WHERE ticket_id IN (:ids)', ['master' => $masterId] + $list, $types);
            // Tickets merged before into one of these now lead to the oldest.
            $db->executeStatement('UPDATE ' . self::TABLE . ' SET merged_into = :master WHERE merged_into IN (:ids)', ['master' => $masterId] + $list, $types);
            $now = date('Y-m-d H:i:s');
            foreach ($others as $id) {
                $db->insert(self::TABLE, [
                    'ticket_id' => $id,
                    'merged_into' => $masterId,
                    'access_hash' => $tickets[$id]['access_hash'] !== null && $tickets[$id]['access_hash'] !== '' ? (string) $tickets[$id]['access_hash'] : null,
                    'subject' => mb_substr((string) $tickets[$id]['subject'], 0, 255),
                    'merged_by' => $adminId,
                    'merged_at' => $now,
                ]);
            }
            $db->update('support_ticket', $update, ['id' => $masterId]);
            $db->executeStatement('DELETE FROM support_ticket WHERE id IN (:ids)', $list, $types);
            // Reply stars and closing feedback go with the replies (TicketFeedback).
            TicketFeedback::moveForMerge($db, $masterId, $others);
            // Last, so a reply saved while this ran still moves.
            $db->executeStatement('UPDATE support_ticket_message SET support_ticket_id = :master WHERE support_ticket_id IN (:ids)', ['master' => $masterId] + $list, $types);
            $db->commit();
        } catch (\Throwable $e) {
            // A failed commit leaves no transaction; its own error is the one to report.
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }

            throw $e;
        }

        return ['id' => $masterId, 'merged' => $others];
    }

    /**
     * Moves what was saved for a merged ticket after its merge (a reply that loaded the ticket
     * just before the merge and wrote just after it) to the ticket it went into. Run after each
     * cron run (Support\Service::onAfterAdminCronRun()); returns the rows moved. A missing
     * table moves nothing.
     */
    public static function sweep(\Pimple\Container $di): int
    {
        $db = self::db($di);
        $moved = 0;
        try {
            foreach (['support_ticket_message' => 'support_ticket_id', 'support_ticket_note' => 'support_ticket_id', 'support_ticket_file' => 'ticket_id', 'support_ticket_author' => 'ticket_id', MailImport::LOG_TABLE => 'ticket_id', TicketFeedback::RATINGS => 'ticket_id', TicketFeedback::FEEDBACK => 'ticket_id'] as $table => $column) {
                // An indexed join finds the few rows (usually none), so the cron never scans a table.
                $pairs = $db->fetchAllNumeric("SELECT DISTINCT m.ticket_id, m.merged_into FROM " . self::TABLE . " m JOIN {$table} x ON x.{$column} = m.ticket_id");
                foreach ($pairs as [$from, $to]) {
                    $moved += (int) $db->executeStatement("UPDATE {$table} SET {$column} = ? WHERE {$column} = ?", [(int) $to, (int) $from]);
                }
            }
        } catch (\Exception) {
            return $moved;
        }

        return $moved;
    }

    /**
     * The ticket a merged ticket now lives in, or null (not merged, or the table is missing).
     */
    public static function target(\Pimple\Container $di, int $ticketId): ?int
    {
        try {
            $target = self::db($di)->fetchOne('SELECT merged_into FROM ' . self::TABLE . ' WHERE ticket_id = ?', [$ticketId]);
        } catch (\Exception) {
            return null;
        }

        return $target === false || $target === null ? null : (int) $target;
    }

    /**
     * The ticket a merged ticket now lives in when it belongs to this client, else null.
     */
    public static function targetForClient(\Pimple\Container $di, int $ticketId, int $clientId): ?int
    {
        try {
            $target = self::db($di)->fetchOne(
                'SELECT t.id FROM ' . self::TABLE . ' m JOIN support_ticket t ON t.id = m.merged_into WHERE m.ticket_id = ? AND t.client_id = ?',
                [$ticketId, $clientId]
            );
        } catch (\Exception) {
            return null;
        }

        return $target === false || $target === null ? null : (int) $target;
    }

    /**
     * The ticket a merged guest ticket's link now opens: its id and guest link, or null when it
     * was not merged or the ticket it went to is not a guest ticket.
     *
     * @return array{id: int, hash: string}|null
     */
    public static function targetForHash(\Pimple\Container $di, string $hash): ?array
    {
        if ($hash === '') {
            return null;
        }
        try {
            $row = self::db($di)->fetchAssociative(
                'SELECT t.id, t.access_hash FROM ' . self::TABLE . ' m JOIN support_ticket t ON t.id = m.merged_into
                  WHERE m.access_hash = ? AND t.client_id IS NULL',
                [$hash]
            );
        } catch (\Exception) {
            return null;
        }
        if ($row === false || (string) $row['access_hash'] === '') {
            return null;
        }

        return ['id' => (int) $row['id'], 'hash' => (string) $row['access_hash']];
    }

    /**
     * The tickets merged into this one (for its page), oldest first.
     *
     * @return list<array{id: int, subject: string, merged_at: string}>
     */
    public static function mergedInto(\Pimple\Container $di, int $ticketId): array
    {
        try {
            $rows = self::db($di)->fetchAllAssociative('SELECT ticket_id, subject, merged_at FROM ' . self::TABLE . ' WHERE merged_into = ? ORDER BY ticket_id', [$ticketId]);
        } catch (\Exception) {
            return [];
        }

        return array_map(fn (array $r): array => ['id' => (int) $r['ticket_id'], 'subject' => (string) $r['subject'], 'merged_at' => (string) $r['merged_at']], $rows);
    }

    /**
     * Unique positive ticket ids, lowest first.
     *
     * @param array<mixed> $ids
     *
     * @return list<int>
     */
    public static function ids(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit(trim($id)))) {
                $id = (int) $id;
                if ($id > 0) {
                    $clean[$id] = $id;
                }
            }
        }
        sort($clean);

        return $clean;
    }

    /**
     * One client's tickets, or guest tickets of one email address.
     *
     * @param array<int, array<string, mixed>> $tickets
     */
    private static function checkOwner(array $tickets): void
    {
        $owners = [];
        foreach ($tickets as $ticket) {
            $owners[] = $ticket['client_id'] !== null && (int) $ticket['client_id'] > 0
                ? 'client:' . (int) $ticket['client_id']
                : 'guest:' . strtolower(trim((string) $ticket['author_email']));
        }
        if (count(array_unique($owners)) !== 1 || $owners[0] === 'guest:') {
            throw new InformationException('Only tickets of the same client, or guest tickets from the same email address, can be merged.');
        }
    }

    private static function subject(string $subject, string $current): string
    {
        if ($subject !== '') {
            return mb_substr($subject, 0, 255);
        }
        if (stripos($current, trim(self::SUFFIX)) !== false) {
            return $current;
        }

        return mb_substr($current, 0, 255 - mb_strlen(self::SUFFIX)) . self::SUFFIX;
    }

    /**
     * The status of the ticket with the latest message (WHMCS); the oldest ticket's when none
     * has messages.
     *
     * @param array<int, array<string, mixed>> $tickets
     */
    private static function status(Connection $db, array $tickets): string
    {
        $latest = $db->fetchOne(
            'SELECT support_ticket_id FROM support_ticket_message WHERE support_ticket_id IN (?) ORDER BY created_at DESC, id DESC LIMIT 1',
            [array_keys($tickets)],
            [ArrayParameterType::INTEGER]
        );
        $ticket = $latest !== false && isset($tickets[(int) $latest]) ? $tickets[(int) $latest] : reset($tickets);

        return (string) ($ticket['status'] ?? SupportTicket::STATUS_OPEN);
    }

    /**
     * The task fields for the oldest ticket: another ticket's task moves to it when it has none;
     * a pending task that cannot move stops the merge (it would be lost).
     *
     * @param array<int, array<string, mixed>> $tickets
     *
     * @return array<string, mixed>
     */
    private static function task(array $tickets, int $masterId): array
    {
        $hasTask = fn (array $t): bool => (string) ($t['rel_type'] ?? '') !== '';
        $move = [];
        $free = !$hasTask($tickets[$masterId]);
        foreach ($tickets as $id => $ticket) {
            if ($id === $masterId || !$hasTask($ticket)) {
                continue;
            }
            if ($free) {
                $move = [
                    'rel_type' => $ticket['rel_type'],
                    'rel_id' => $ticket['rel_id'],
                    'rel_task' => $ticket['rel_task'],
                    'rel_new_value' => $ticket['rel_new_value'],
                    'rel_status' => $ticket['rel_status'],
                ];
                $free = false;
            } elseif ($ticket['rel_status'] === SupportTicket::REL_STATUS_PENDING) {
                throw new InformationException('Ticket #:id has a pending task. Complete it before merging.', [':id' => $id]);
            }
        }

        return $move;
    }

    private static function isMysql(Connection $db): bool
    {
        return $db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
    }

    private static function db(\Pimple\Container $di): Connection
    {
        return $di['em']->getConnection();
    }
}
