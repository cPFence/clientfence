<?php

declare(strict_types=1);

namespace ClientFence\Mod\Support;

use ClientFence\InformationException;
use ClientFence\Tools;
use Doctrine\DBAL\Connection;

/**
 * ClientFence: WHMCS's two ticket rating features.
 *
 * Reply ratings ("Support Ticket Rating"): while Support settings > "Ticket ratings" is on, the
 * client (or the guest, through the ticket link) rates each staff reply once, 1 to 5 stars
 * (support_ticket_message_rating). Staff see the stars under the reply.
 *
 * Feedback requests ("Ticket Closure Feedback Request"): when a ticket closes (staff, client or
 * the inactivity auto-close; not an escalation rule's status change, as WHMCS) in a help desk
 * whose "Feedback request" is on (support_helpdesk_feedback; on when not set), the client gets
 * mod_support_ticket_feedback_request with a private link (support_ticket_feedback_request). It
 * opens a page without sign-in, as WHMCS: when the ticket was opened and last answered, the staff
 * involved and the duration, a 1 to 10 score and a comment for each staff member, and a general
 * comment (support_ticket_feedback). Each ticket takes one feedback; reopening and closing again
 * asks again while none was given.
 *
 * Support > Ratings & Feedback reports both by staff member, with the comments.
 */
final class TicketFeedback
{
    public const RATINGS = 'support_ticket_message_rating';
    public const FEEDBACK = 'support_ticket_feedback';
    public const REQUESTS = 'support_ticket_feedback_request';
    public const HELPDESKS = 'support_helpdesk_feedback';

    public const MAX_STARS = 5;
    public const MAX_SCORE = 10;
    // At or below these, staff get a bell notification (a 1-2 star reply, a feedback score of 4 or less).
    public const LOW_STARS = 2;
    public const LOW_SCORE = 4;
    public const MAX_COMMENT = 5000;
    /** Report periods: key => days back (0: all time). */
    public const PERIODS = ['30' => 30, '90' => 90, '365' => 365, 'all' => 0];

    /** Whether clients may rate staff replies (Support settings, on unless switched off). */
    public static function ratingsOn(\Pimple\Container $di): bool
    {
        try {
            $config = $di['mod_service']('extension')->getConfig('mod_support');
        } catch (\Throwable) {
            return false;
        }

        return Tools::normalizeBoolean($config['ticket_rating'] ?? true, true);
    }

    /** Whether a help desk asks for feedback when its tickets close (on unless switched off). */
    public static function helpdeskOn(\Pimple\Container $di, int $helpdeskId): bool
    {
        try {
            $on = self::db($di)->fetchOne('SELECT feedback_request FROM ' . self::HELPDESKS . ' WHERE helpdesk_id = ?', [$helpdeskId]);
        } catch (\Throwable) {
            return true;
        }

        return $on === false || (int) $on === 1;
    }

    /** Saves a help desk's "Feedback request" from its form (core patch in Service::helpdeskUpdate()). */
    public static function saveHelpdesk(\Pimple\Container $di, int $helpdeskId, mixed $on): void
    {
        $row = ['feedback_request' => Tools::normalizeBoolean($on, true) ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')];
        $db = self::db($di);
        self::withTables($di, static function () use ($db, $helpdeskId, $row): void {
            if ($db->update(self::HELPDESKS, $row, ['helpdesk_id' => $helpdeskId]) === 0
                && $db->fetchOne('SELECT 1 FROM ' . self::HELPDESKS . ' WHERE helpdesk_id = ?', [$helpdeskId]) === false) {
                $db->insert(self::HELPDESKS, ['helpdesk_id' => $helpdeskId] + $row);
            }
        });
    }

    /** A deleted help desk's setting (core patch in Service::helpdeskRm()). */
    public static function deleteHelpdesk(\Pimple\Container $di, int $helpdeskId): void
    {
        try {
            self::db($di)->delete(self::HELPDESKS, ['helpdesk_id' => $helpdeskId]);
        } catch (\Throwable) {
        }
    }

    /**
     * Rates a staff reply of the ticket, 1 to 5 stars, once. `$kind` is client or guest.
     */
    public static function rate(\Pimple\Container $di, int $ticketId, int $messageId, mixed $stars, string $kind, int $byId, ?string $ip): void
    {
        if (!self::ratingsOn($di)) {
            throw new InformationException('Reply ratings are switched off.');
        }
        $stars = filter_var($stars, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_STARS]]);
        if ($stars === false) {
            throw new InformationException('Choose from 1 to :max stars.', [':max' => self::MAX_STARS]);
        }
        $db = self::db($di);
        $adminId = $db->fetchOne('SELECT admin_id FROM support_ticket_message WHERE id = ? AND support_ticket_id = ?', [$messageId, $ticketId]);
        if ($adminId === false || $adminId === null) {
            throw new InformationException('Only staff replies of this ticket can be rated.');
        }
        try {
            self::withTables($di, static fn () => $db->insert(self::RATINGS, [
                'message_id' => $messageId,
                'ticket_id' => $ticketId,
                'admin_id' => (int) $adminId,
                'rating' => $stars,
                'rated_by_kind' => $kind === 'guest' ? 'guest' : 'client',
                'rated_by_id' => $byId,
                'ip' => mb_substr((string) $ip, 0, 45),
                'rated_at' => date('Y-m-d H:i:s'),
            ]));
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            throw new InformationException('This reply was already rated.');
        }
        $di['logger']->info('Reply #%s of ticket #%s rated %s stars', $messageId, $ticketId, $stars);
        if ($stars <= self::LOW_STARS) {
            \ClientFence\Mod\Clientfence\Notifications::staff($di, 'rating_low', ['id' => $ticketId, 'stars' => $stars], 'support/ticket/' . $ticketId, 'support', [(int) $adminId]);
        }
    }

    /**
     * The ticket's rated replies.
     *
     * @return array<int, int> message id => stars
     */
    public static function ratings(\Pimple\Container $di, int $ticketId): array
    {
        try {
            $rows = self::db($di)->fetchAllKeyValue('SELECT message_id, rating FROM ' . self::RATINGS . ' WHERE ticket_id = ?', [$ticketId]);
        } catch (\Throwable) {
            return [];
        }

        return array_map('intval', $rows);
    }

    /**
     * After a ticket closes: asks the client for feedback when its help desk does and none was
     * given (a new link the first time, the same link after a reopen). Failures are logged.
     */
    public static function afterClose(\Pimple\Container $di, int $ticketId): void
    {
        try {
            $db = self::db($di);
            $ticket = $db->fetchAssociative('SELECT id, subject, status, client_id, author_name, author_email, access_hash, support_helpdesk_id FROM support_ticket WHERE id = ?', [$ticketId]);
            if ($ticket === false || $ticket['status'] !== 'closed' || !self::helpdeskOn($di, (int) $ticket['support_helpdesk_id'])) {
                return;
            }
            if (self::withTables($di, static fn () => $db->fetchOne('SELECT 1 FROM ' . self::FEEDBACK . ' WHERE ticket_id = ?', [$ticketId])) !== false) {
                return;
            }
            $token = $db->fetchOne('SELECT token FROM ' . self::REQUESTS . ' WHERE ticket_id = ?', [$ticketId]);
            if ($token === false) {
                $token = bin2hex(random_bytes(32));
                $db->insert(self::REQUESTS, ['ticket_id' => $ticketId, 'token' => $token, 'sent_at' => date('Y-m-d H:i:s'), 'answered_at' => null]);
            } else {
                $db->update(self::REQUESTS, ['sent_at' => date('Y-m-d H:i:s')], ['ticket_id' => $ticketId]);
            }

            $email = [
                'code' => 'mod_support_ticket_feedback_request',
                'ticket' => ['id' => (int) $ticket['id'], 'subject' => (string) $ticket['subject']],
                'feedback_url' => $di['url']->link('support/feedback/' . $token),
            ];
            if ($ticket['client_id'] !== null) {
                $email['to_client'] = (int) $ticket['client_id'];
            } elseif ((string) $ticket['access_hash'] !== '' && (string) $ticket['author_email'] !== '') {
                $email['to'] = (string) $ticket['author_email'];
                $email['to_name'] = (string) $ticket['author_name'];
                $email['name'] = (string) $ticket['author_name'];
            } else {
                return;
            }
            $di['mod_service']('email')->sendTemplate($email);
        } catch (\Throwable $e) {
            self::log($di, 'could not ask for feedback on ticket #' . $ticketId . ': ' . $e->getMessage());
        }
    }

    /** The ticket's feedback link while one was sent and not answered (the client's ticket page shows it). */
    public static function openLink(\Pimple\Container $di, int $ticketId): ?string
    {
        try {
            $token = self::db($di)->fetchOne('SELECT token FROM ' . self::REQUESTS . ' WHERE ticket_id = ? AND answered_at IS NULL', [$ticketId]);
        } catch (\Throwable) {
            return null;
        }

        return $token === false ? null : $di['url']->link('support/feedback/' . $token);
    }

    /**
     * The feedback page for a link: the ticket's summary (as WHMCS: opened, last reply, staff
     * involved, duration) and whether feedback can be given.
     *
     * @return array{ticket: array<string, mixed>, staff: array<int, string>, state: string}
     */
    public static function page(\Pimple\Container $di, string $token): array
    {
        $ticket = self::ticketForToken($di, $token);
        $db = self::db($di);
        $times = $db->fetchAssociative('SELECT MIN(created_at) AS first_at, MAX(created_at) AS last_at FROM support_ticket_message WHERE support_ticket_id = ?', [(int) $ticket['id']]) ?: [];
        $opened = (string) ($ticket['created_at'] ?? $times['first_at'] ?? '');
        $last = (string) ($times['last_at'] ?? $opened);
        $seconds = ($opened !== '' && $last !== '') ? max(0, strtotime($last) - strtotime($opened)) : 0;

        $state = 'form';
        if ($ticket['status'] !== 'closed') {
            $state = 'open';
        } elseif ($db->fetchOne('SELECT 1 FROM ' . self::FEEDBACK . ' WHERE ticket_id = ?', [(int) $ticket['id']]) !== false) {
            $state = 'done';
        }

        return [
            'ticket' => [
                'id' => (int) $ticket['id'],
                'subject' => (string) $ticket['subject'],
                'opened_at' => $opened,
                'last_reply_at' => $last,
                'duration' => ['days' => intdiv($seconds, 86400), 'hours' => intdiv($seconds % 86400, 3600), 'minutes' => intdiv($seconds % 3600, 60)],
            ],
            'staff' => self::staffInvolved($di, (int) $ticket['id']),
            'state' => $state,
        ];
    }

    /**
     * Saves the feedback of a link: `$scores` staff id => 1..10 (one for each staff member
     * involved), `$comments` staff id or 'generic' => text.
     */
    public static function submit(\Pimple\Container $di, string $token, mixed $scores, mixed $comments, ?string $ip): void
    {
        $ticket = self::ticketForToken($di, $token);
        $ticketId = (int) $ticket['id'];
        if ($ticket['status'] !== 'closed') {
            throw new InformationException('Feedback can be given once the ticket is closed.');
        }
        $db = self::db($di);
        $scores = is_array($scores) ? $scores : [];
        $comments = is_array($comments) ? $comments : [];
        $staff = self::staffInvolved($di, $ticketId);

        $rows = [];
        foreach ($staff as $adminId => $name) {
            $score = filter_var($scores[$adminId] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_SCORE]]);
            if ($score === false) {
                throw new InformationException('Rate :name from 1 to :max.', [':name' => $name, ':max' => self::MAX_SCORE]);
            }
            $rows[] = ['admin_id' => $adminId, 'rating' => $score, 'comments' => self::comment($comments[$adminId] ?? '')];
        }
        $generic = self::comment($comments['generic'] ?? '');
        if ($generic !== '' || $rows === []) {
            if ($generic === '') {
                throw new InformationException('Tell us how we can improve.');
            }
            $rows[] = ['admin_id' => null, 'rating' => null, 'comments' => $generic];
        }

        $db->beginTransaction();
        try {
            // Claims the link: a second submit (another tab) finds it answered.
            if ($db->executeStatement('UPDATE ' . self::REQUESTS . ' SET answered_at = ? WHERE ticket_id = ? AND answered_at IS NULL', [date('Y-m-d H:i:s'), $ticketId]) !== 1
                || $db->fetchOne('SELECT 1 FROM ' . self::FEEDBACK . ' WHERE ticket_id = ?', [$ticketId]) !== false) {
                throw new InformationException('Feedback for this ticket was already given. Thank you.');
            }
            $now = date('Y-m-d H:i:s');
            foreach ($rows as $row) {
                $db->insert(self::FEEDBACK, ['ticket_id' => $ticketId] + $row + ['ip' => mb_substr((string) $ip, 0, 45), 'created_at' => $now]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();

            throw $e;
        }
        $di['logger']->info('Feedback given on ticket #%s', $ticketId);
        $scores = array_filter(array_column($rows, 'rating'), static fn ($r): bool => $r !== null);
        if ($scores !== [] && min($scores) <= self::LOW_SCORE) {
            \ClientFence\Mod\Clientfence\Notifications::staff($di, 'feedback_low', ['id' => $ticketId], 'support/ticket/' . $ticketId, 'support');
        }
    }

    /**
     * The ticket's feedback for staff, or null: each staff member's score and comment, and the
     * general comment.
     *
     * @return array{created_at: string, staff: list<array{admin_id: int, name: string, rating: int, comments: string}>, comments: string}|null
     */
    public static function forTicket(\Pimple\Container $di, int $ticketId): ?array
    {
        try {
            $rows = self::db($di)->fetchAllAssociative(
                'SELECT f.admin_id, f.rating, f.comments, f.created_at, a.name FROM ' . self::FEEDBACK . ' f LEFT JOIN admin a ON a.id = f.admin_id WHERE f.ticket_id = ? ORDER BY f.id',
                [$ticketId]
            );
        } catch (\Throwable) {
            return null;
        }
        if ($rows === []) {
            return null;
        }
        $feedback = ['created_at' => (string) $rows[0]['created_at'], 'staff' => [], 'comments' => ''];
        foreach ($rows as $row) {
            if ($row['admin_id'] === null) {
                $feedback['comments'] = (string) $row['comments'];
            } else {
                $feedback['staff'][] = ['admin_id' => (int) $row['admin_id'], 'name' => self::name($row['name'], (int) $row['admin_id']), 'rating' => (int) $row['rating'], 'comments' => (string) $row['comments']];
            }
        }

        return $feedback;
    }

    /**
     * Support > Ratings & Feedback: by staff member, reply stars (count, average, each star) and
     * feedback scores (count, average); the latest feedback comments.
     *
     * @return array{period: string, ratings: list<array<string, mixed>>, feedback: list<array<string, mixed>>, comments: list<array<string, mixed>>}
     */
    public static function report(\Pimple\Container $di, mixed $period): array
    {
        $period = is_string($period) && isset(self::PERIODS[$period]) ? $period : '90';
        $days = self::PERIODS[$period];
        $since = $days > 0 ? date('Y-m-d H:i:s', strtotime('-' . $days . ' days')) : '1970-01-01 00:00:00';
        $db = self::db($di);
        $report = ['period' => $period, 'ratings' => [], 'feedback' => [], 'comments' => []];
        try {
            foreach ($db->fetchAllAssociative(
                'SELECT r.admin_id, a.name, COUNT(*) AS total, AVG(r.rating) AS average,'
                . ' SUM(r.rating = 1) AS s1, SUM(r.rating = 2) AS s2, SUM(r.rating = 3) AS s3, SUM(r.rating = 4) AS s4, SUM(r.rating = 5) AS s5'
                . ' FROM ' . self::RATINGS . ' r LEFT JOIN admin a ON a.id = r.admin_id WHERE r.rated_at >= ? GROUP BY r.admin_id, a.name ORDER BY average DESC, total DESC',
                [$since]
            ) as $row) {
                $report['ratings'][] = [
                    'admin_id' => (int) $row['admin_id'], 'name' => self::name($row['name'], (int) $row['admin_id']),
                    'total' => (int) $row['total'], 'average' => round((float) $row['average'], 2),
                    'stars' => [1 => (int) $row['s1'], 2 => (int) $row['s2'], 3 => (int) $row['s3'], 4 => (int) $row['s4'], 5 => (int) $row['s5']],
                ];
            }
            foreach ($db->fetchAllAssociative(
                'SELECT f.admin_id, a.name, COUNT(*) AS total, AVG(f.rating) AS average FROM ' . self::FEEDBACK . ' f LEFT JOIN admin a ON a.id = f.admin_id'
                . ' WHERE f.admin_id IS NOT NULL AND f.created_at >= ? GROUP BY f.admin_id, a.name ORDER BY average DESC, total DESC',
                [$since]
            ) as $row) {
                $report['feedback'][] = ['admin_id' => (int) $row['admin_id'], 'name' => self::name($row['name'], (int) $row['admin_id']), 'total' => (int) $row['total'], 'average' => round((float) $row['average'], 2)];
            }
            foreach ($db->fetchAllAssociative(
                'SELECT f.ticket_id, f.admin_id, f.rating, f.comments, f.created_at, a.name, t.subject FROM ' . self::FEEDBACK . ' f'
                . ' LEFT JOIN admin a ON a.id = f.admin_id LEFT JOIN support_ticket t ON t.id = f.ticket_id'
                . " WHERE f.comments <> '' AND f.created_at >= ? ORDER BY f.created_at DESC, f.id DESC LIMIT 100",
                [$since]
            ) as $row) {
                $report['comments'][] = [
                    'ticket_id' => (int) $row['ticket_id'], 'subject' => (string) $row['subject'],
                    'name' => $row['admin_id'] === null ? '' : self::name($row['name'], (int) $row['admin_id']),
                    'rating' => $row['rating'] === null ? null : (int) $row['rating'], 'comments' => (string) $row['comments'], 'created_at' => (string) $row['created_at'],
                ];
            }
        } catch (\Doctrine\DBAL\Exception) {
            // Tables not made yet: an empty report.
        }

        return $report;
    }

    /**
     * A merge (TicketMerge::merge(), inside its transaction): the merged tickets' reply stars and
     * feedback go to the ticket that stays; their feedback link too, when that ticket has none and
     * no feedback (else the links end). A table not made yet is skipped.
     *
     * @param list<int> $merged
     */
    public static function moveForMerge(Connection $db, int $masterId, array $merged): void
    {
        if ($merged === []) {
            return;
        }
        $ids = ['ids' => $merged];
        $types = ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER];
        foreach ([self::RATINGS, self::FEEDBACK] as $table) {
            try {
                $db->executeStatement('UPDATE ' . $table . ' SET ticket_id = :master WHERE ticket_id IN (:ids)', ['master' => $masterId] + $ids, $types);
            } catch (\Doctrine\DBAL\Exception\TableNotFoundException) {
            }
        }
        try {
            $keep = $db->fetchOne('SELECT 1 FROM ' . self::REQUESTS . ' WHERE ticket_id = ?', [$masterId]) === false
                && $db->fetchOne('SELECT 1 FROM ' . self::FEEDBACK . ' WHERE ticket_id = ?', [$masterId]) === false
                ? $db->fetchOne('SELECT ticket_id FROM ' . self::REQUESTS . ' WHERE ticket_id IN (:ids) AND answered_at IS NULL ORDER BY sent_at DESC LIMIT 1', $ids, $types)
                : false;
            if ($keep !== false) {
                $db->update(self::REQUESTS, ['ticket_id' => $masterId], ['ticket_id' => (int) $keep]);
            }
            $db->executeStatement('DELETE FROM ' . self::REQUESTS . ' WHERE ticket_id IN (:ids)', $ids, $types);
        } catch (\Doctrine\DBAL\Exception\TableNotFoundException) {
        }
    }

    /** Ratings, feedback and links of deleted tickets (after a delete or a merge). */
    public static function sweep(\Pimple\Container $di): int
    {
        $swept = 0;
        foreach ([self::RATINGS, self::FEEDBACK, self::REQUESTS] as $table) {
            try {
                $swept += (int) self::db($di)->executeStatement('DELETE FROM ' . $table . ' WHERE NOT EXISTS (SELECT 1 FROM support_ticket t WHERE t.id = ' . $table . '.ticket_id)');
            } catch (\Doctrine\DBAL\Exception) {
            }
        }

        return $swept;
    }

    /**
     * Staff who replied on the ticket (not the system's cron account), by name.
     *
     * @return array<int, string> id => name
     */
    public static function staffInvolved(\Pimple\Container $di, int $ticketId): array
    {
        $rows = self::db($di)->fetchAllAssociative(
            'SELECT DISTINCT a.id, a.name FROM support_ticket_message m JOIN admin a ON a.id = m.admin_id'
            . ' WHERE m.support_ticket_id = ? AND a.system_name IS NULL ORDER BY a.name, a.id',
            [$ticketId]
        );
        $staff = [];
        foreach ($rows as $row) {
            $staff[(int) $row['id']] = self::name($row['name'], (int) $row['id']);
        }

        return $staff;
    }

    /** @return array<string, mixed> */
    private static function ticketForToken(\Pimple\Container $di, string $token): array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            throw new InformationException('This feedback link is not valid.');
        }
        try {
            $ticket = self::db($di)->fetchAssociative(
                'SELECT t.id, t.subject, t.status, t.created_at FROM ' . self::REQUESTS . ' r JOIN support_ticket t ON t.id = r.ticket_id WHERE r.token = ?',
                [$token]
            );
        } catch (\Doctrine\DBAL\Exception) {
            $ticket = false;
        }
        if ($ticket === false) {
            throw new InformationException('This feedback link is not valid.');
        }

        return $ticket;
    }

    /**
     * Runs the query; when one of the tables is missing (the pack installer not run since this
     * version), makes the ClientFence tables and runs it again.
     */
    private static function withTables(\Pimple\Container $di, callable $query): mixed
    {
        try {
            return $query();
        } catch (\Doctrine\DBAL\Exception\TableNotFoundException) {
            \ClientFence\Mod\Clientfence\Service::ensureTables($di, true);

            return $query();
        }
    }

    private static function comment(mixed $text): string
    {
        $text = is_string($text) ? trim(str_replace(["\r\n", "\r"], "\n", $text)) : '';
        if (mb_strlen($text) > self::MAX_COMMENT) {
            throw new InformationException('A comment can be at most :max characters.', [':max' => self::MAX_COMMENT]);
        }

        return $text;
    }

    private static function name(mixed $name, int $id): string
    {
        $name = trim((string) $name);

        return $name !== '' ? $name : '#' . $id;
    }

    private static function db(\Pimple\Container $di): Connection
    {
        return $di['em']->getConnection();
    }

    private static function log(\Pimple\Container $di, string $message): void
    {
        try {
            $di['logger']->warning('ClientFence: ' . $message);
        } catch (\Throwable) {
        }
    }
}
