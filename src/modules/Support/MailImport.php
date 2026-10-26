<?php

declare(strict_types=1);

namespace ClientFence\Mod\Support;

use ClientFence\Mod\Support\Entity\Helpdesk;
use ClientFence\Mod\Support\Entity\SupportTicket;
use ClientFence\Mod\Support\Mail\ImapClient;
use ClientFence\Mod\Support\Mail\MailException;
use ClientFence\Mod\Support\Mail\MimeMessage;
use Doctrine\DBAL\Connection;
use ClientFence\InformationException;

/**
 * ClientFence: tickets from email, like WHMCS's email importing.
 *
 * Each help desk can have a mailbox (support_helpdesk_mail; the password is kept encrypted and
 * never shown again). After every cron run (Support\Service::onAfterAdminCronRun) run() reads
 * each enabled mailbox over IMAP (Mail\ImapClient, Mail\MimeMessage), turns every message into a
 * new ticket or a reply, and deletes it from the mailbox, as WHMCS does. Every message is logged
 * in support_mail_import (Support > Email import log); one that was not imported keeps a
 * compressed copy, so staff can import it anyway (importAnyway(), WHMCS's "Ignore Error and
 * Import").
 *
 * A message is checked in this order (decide()):
 *  1. sent by an auto-responder (Auto-Submitted, Precedence: bulk/junk/list, X-Autoreply): blocked;
 *  2. from one of our own addresses (help desks, the company and sender addresses, staff): blocked
 *     (an email loop);
 *  3. the sender's address, subject or text is on the spam lists: blocked;
 *  4. the sender already sent `mail_reply_limit` messages (default 10) in 15 minutes: blocked
 *     (WHMCS's "Ticket Reply Email Limit");
 *  5. a subject with [Ticket #123] (or WHMCS's [Ticket ID: 123]) of an existing ticket (or of one
 *     merged into it, TicketMerge) is a reply:
 *     only from the ticket's client, an authorized user of it with ticket access, or the guest who
 *     opened it, unless "Allow insecure imports" is on; a closed ticket is reopened unless "Prevent
 *     email reopening" is on or its help desk does not let clients reopen tickets (the sender is
 *     told by email);
 *  6. otherwise a new ticket: refused (sender told) when the help desk takes replies only; a client
 *     or authorized user gets a ticket on their account; anyone else a guest ticket, unless the help
 *     desk takes clients only or guest tickets are off (sender told).
 * Quoted earlier messages are cut at the break lines (WHMCS's list, editable) and at "On ...
 * wrote:" lines. Attachments become ticket files (TicketFiles rules); others are named in the
 * message. Nothing a sender writes can break the import of the next message.
 */
final class MailImport
{
    public const MAILBOX_TABLE = 'support_helpdesk_mail';
    public const LOG_TABLE = 'support_mail_import';
    public const UNVERIFIED_TABLE = 'support_ticket_message_unverified';

    public const IMPORTED = 'imported';
    public const REPLIED = 'replied';
    public const BLOCKED_AUTO = 'blocked_auto';
    public const BLOCKED_OWN = 'blocked_own';
    public const BLOCKED_SPAM = 'blocked_spam';
    public const BLOCKED_LOOP = 'blocked_loop';
    public const BLOCKED_SENDER = 'blocked_sender';
    public const REJECTED_CLOSED = 'rejected_closed';
    public const REJECTED_REPLIES_ONLY = 'rejected_replies_only';
    public const REJECTED_CLIENTS_ONLY = 'rejected_clients_only';
    public const REJECTED_TOO_LARGE = 'rejected_too_large';
    public const REJECTED_REFUSED = 'rejected_refused';
    public const DUPLICATE = 'duplicate';
    public const FAILED = 'failed';
    /** A log entry staff are importing right now (importAnyway()). */
    private const IMPORTING = 'importing';

    /** Statuses whose sender gets the "not imported" email (mod_support_mail_rejected). */
    private const TELL_SENDER = [self::REJECTED_CLOSED, self::REJECTED_REPLIES_ONLY, self::REJECTED_CLIENTS_ONLY, self::REJECTED_TOO_LARGE, self::REJECTED_REFUSED];
    /** At most this many "not imported" emails go out an hour in all (forged senders, floods). */
    private const TELL_PER_HOUR = 50;
    /** Statuses whose email is not worth a copy (our own notifications, auto-replies, repeats). */
    private const NO_COPY = [self::DUPLICATE, self::BLOCKED_OWN, self::BLOCKED_AUTO];
    /** The largest compressed copy kept of an email (well under MariaDB's 16 MB packet). */
    private const MAX_COPY = 8 * 1024 * 1024;
    /** Memory the import may use: a 10 MB email is parsed and decoded in memory. */
    private const MEMORY = 256 * 1024 * 1024;

    /** WHMCS's default break lines; text from the first one on is an earlier, quoted message. */
    public const BREAK_LINES = [
        '====== Please reply above this line ======',
        '> -----Original Message-----',
        '----- Original Message -----',
        '-----Original Message-----',
        '<!-- Break Line -->',
        '________________________________',
    ];

    public const DEFAULTS = [
        'mail_reply_limit' => '10',
        'mail_insecure_imports' => '0',
        'mail_prevent_reopen' => '0',
        'mail_max_mb' => '10',
        'mail_log_days' => '30',
        'mail_break_lines' => '',
        'mail_block_senders' => '',
        'mail_block_subjects' => '',
        'mail_block_phrases' => '',
    ];

    public const ENCRYPTIONS = ['ssl' => 'SSL/TLS', 'tls' => 'STARTTLS', 'none' => 'None'];
    public const MAX_PER_RUN = 25;
    /** Lost connections on one email before it is set aside. */
    private const MAX_TRIES = 3;
    /** processing_tries while an email is being set aside: if that fails too, it is removed. */
    private const SETTING_ASIDE = 99;

    /**
     * Reads every enabled mailbox once. Returns messages handled per help desk id. A mailbox that
     * fails is recorded on it (last_ok, last_result) and the others still run.
     *
     * @return array<int, int>
     */
    public static function run(\Pimple\Container $di): array
    {
        $db = self::db($di);
        try {
            $mailboxes = $db->fetchAllAssociative('SELECT * FROM ' . self::MAILBOX_TABLE . ' WHERE enabled = 1 ORDER BY helpdesk_id');
        } catch (\Doctrine\DBAL\Exception\TableNotFoundException) {
            return [];
        }
        if ($mailboxes === [] || !self::lock($db)) {
            return [];
        }
        self::raiseMemoryLimit();
        // "Read now" runs in a web request: a time limit or a closed tab must not cut an email.
        @set_time_limit(0);
        ignore_user_abort(true);
        $done = [];
        try {
            foreach ($mailboxes as $mailbox) {
                $helpdeskId = (int) $mailbox['helpdesk_id'];
                try {
                    $done[$helpdeskId] = self::readMailbox($di, $mailbox);
                    self::recordRun($db, $helpdeskId, true, $done[$helpdeskId] === 1 ? '1 email handled' : $done[$helpdeskId] . ' emails handled');
                } catch (\Throwable $e) {
                    self::recordRun($db, $helpdeskId, false, $e->getMessage());
                    $di['logger']->warning('Support: email import for help desk #%s failed: %s', $helpdeskId, $e->getMessage());
                    $name = (string) $db->fetchOne('SELECT name FROM support_helpdesk WHERE id = ?', [$helpdeskId]);
                    \ClientFence\Mod\Clientfence\Notifications::staff($di, 'mail_import_failed', ['helpdesk' => $name], 'support/email-import', 'support', null, 'mail_import_failed:' . $helpdeskId . ':' . date('Y-m-d'));
                }
            }
            self::prune($di);
        } finally {
            self::unlock($db);
        }

        return $done;
    }

    /** Connects with a mailbox's settings and reports how many messages wait (Test connection). */
    public static function test(\Pimple\Container $di, int $helpdeskId): int
    {
        $mailbox = self::mailbox($di, $helpdeskId);
        if ($mailbox === null || (string) $mailbox['host'] === '') {
            throw new InformationException('Save the mailbox settings first.');
        }
        $imap = self::open($di, $mailbox);
        try {
            return $imap->select((string) $mailbox['folder']);
        } finally {
            $imap->logout();
        }
    }

    /**
     * Handles one message (a whole email as sent). $helpdeskId: the mailbox's help desk.
     * $anyway: staff import a message the checks refused (no checks, no limits).
     *
     * @return array{status: string, ticket_id: ?int, note: string}
     */
    public static function process(\Pimple\Container $di, string $raw, int $helpdeskId, bool $anyway = false): array
    {
        return self::processMessage($di, MimeMessage::parse($raw), $helpdeskId, $anyway);
    }

    /**
     * @return array{status: string, ticket_id: ?int, note: string}
     */
    public static function processMessage(\Pimple\Container $di, MimeMessage $message, int $helpdeskId, bool $anyway = false): array
    {
        $decision = self::decide($di, $message, $helpdeskId, $anyway);
        if (in_array($decision['status'], [self::IMPORTED, self::REPLIED], true)) {
            $decision = self::import($di, $message, $decision);
        }

        return ['status' => $decision['status'], 'ticket_id' => $decision['ticket_id'] ?? null, 'note' => $decision['note'] ?? ''];
    }

    /**
     * What to do with a message (no change is made). Keys: status, note, and for an import:
     * ticket_id (reply), client_id, user (an authorized user's row), helpdesk_id.
     *
     * @return array<string, mixed>
     */
    public static function decide(\Pimple\Container $di, MimeMessage $message, int $helpdeskId, bool $anyway = false): array
    {
        $db = self::db($di);
        $settings = self::settings($di);
        $sender = $message->from['email'];
        if ($sender === '') {
            return ['status' => self::FAILED, 'note' => 'The email has no valid sender address.'];
        }
        if (!$anyway) {
            if (self::isAutoReply($message)) {
                return ['status' => self::BLOCKED_AUTO, 'note' => 'Sent by an auto-responder.'];
            }
            if (in_array($sender, self::ownAddresses($di), true)) {
                return ['status' => self::BLOCKED_OWN, 'note' => 'Sent from one of our own addresses (an email loop).'];
            }
            $spam = self::spamMatch($settings, $message);
            if ($spam !== null) {
                return ['status' => self::BLOCKED_SPAM, 'note' => $spam];
            }
            $messageId = self::messageId($message);
            if ($messageId !== '' && $db->fetchOne('SELECT id FROM ' . self::LOG_TABLE . ' WHERE message_id = ? AND status IN (?, ?)', [$messageId, self::IMPORTED, self::REPLIED]) !== false) {
                return ['status' => self::DUPLICATE, 'note' => 'This email was imported already.'];
            }
            $limit = max(1, (int) $settings['mail_reply_limit']);
            $recent = (int) $db->fetchOne('SELECT COUNT(*) FROM ' . self::LOG_TABLE . ' WHERE from_email = ? AND created_at > ?', [$sender, date('Y-m-d H:i:s', time() - 900)]);
            if ($recent >= $limit) {
                return ['status' => self::BLOCKED_LOOP, 'note' => sprintf('More than %d emails from this address in 15 minutes (a possible email loop).', $limit)];
            }
        }
        $mailbox = self::mailbox($di, $helpdeskId) ?? [];
        // ClientFence: anyone can write any From. A sender whose email is not DKIM-signed by its
        // From domain is still matched, but the message is flagged and staff see a red warning.
        [$verified, $why] = self::verifySender($di, $message);
        $unverified = $verified ? null : $why;
        $client = self::clientByEmail($db, $sender);
        $users = self::usersByEmail($db, $sender);

        $ticketId = self::ticketIdFromSubject($message->subject);
        // A merged ticket's number leads to the ticket it went into (TicketMerge).
        $ticketId = $ticketId !== null ? (TicketMerge::target($di, $ticketId) ?? $ticketId) : null;
        $ticket = $ticketId !== null ? $db->fetchAssociative('SELECT t.*, h.can_reopen FROM support_ticket t LEFT JOIN support_helpdesk h ON h.id = t.support_helpdesk_id WHERE t.id = ?', [$ticketId]) : false;
        if ($ticket !== false) {
            $base = ['status' => self::REPLIED, 'ticket_id' => (int) $ticket['id'], 'helpdesk_id' => (int) $ticket['support_helpdesk_id'], 'unverified' => $unverified];
            $ticketClient = $ticket['client_id'] !== null ? (int) $ticket['client_id'] : 0;
            if ($ticketClient > 0) {
                $base['client_id'] = $ticketClient;
                $user = $users[$ticketClient] ?? null;
                $allowed = ($client !== null && (int) $client['id'] === $ticketClient) || $user !== null;
                if ($user !== null && ($client === null || (int) $client['id'] !== $ticketClient)) {
                    $base['user'] = $user;
                }
            } else {
                $allowed = strtolower((string) $ticket['author_email']) === $sender;
            }
            if (!$allowed && !$anyway && $settings['mail_insecure_imports'] !== '1') {
                return ['status' => self::BLOCKED_SENDER, 'note' => 'The sender is not the client, an authorized user or the guest of ticket #' . $ticket['id'] . '.'];
            }
            if (!$allowed) {
                $base['stranger'] = true;
            }
            if ($ticket['status'] === SupportTicket::STATUS_CLOSED && !$anyway && ($settings['mail_prevent_reopen'] === '1' || (string) $ticket['can_reopen'] === '0')) {
                return ['status' => self::REJECTED_CLOSED, 'note' => 'Ticket #' . $ticket['id'] . ' is closed and cannot be reopened by email.', 'ticket_id' => (int) $ticket['id']];
            }

            return $base;
        }

        if (!$anyway && (string) ($mailbox['replies_only'] ?? '0') === '1') {
            return ['status' => self::REJECTED_REPLIES_ONLY, 'note' => 'This help desk takes replies to tickets only.'];
        }
        $base = ['status' => self::IMPORTED, 'ticket_id' => null, 'helpdesk_id' => $helpdeskId, 'unverified' => $unverified];
        if ($client !== null) {
            return $base + ['client_id' => (int) $client['id']];
        }
        if ($users !== []) {
            $user = reset($users);

            return $base + ['client_id' => (int) $user['client_id'], 'user' => $user];
        }
        $guestsAllowed = $di['mod_service']('support')->guestTicketsEnabled() && (string) ($mailbox['clients_only'] ?? '0') !== '1';
        if (!$anyway && !$guestsAllowed) {
            return ['status' => self::REJECTED_CLIENTS_ONLY, 'note' => 'This help desk takes emails from registered clients only.'];
        }

        return $base + ['client_id' => 0];
    }

    /**
     * Imports a message as decide() said: a new ticket (client or guest) or a reply. A rule of the
     * ticket system that refuses it (one ticket per N hours, a message too long) makes it
     * REJECTED_REFUSED; once the ticket or reply exists, a later problem (files, the user's name)
     * only adds a note, so the email is never imported twice.
     *
     * @param array<string, mixed> $decision
     *
     * @return array<string, mixed>
     */
    private static function import(\Pimple\Container $di, MimeMessage $message, array $decision): array
    {
        /** @var Service $support */
        $support = $di['mod_service']('support');
        $content = self::content($di, $message);
        if (!empty($decision['stranger'])) {
            $content = sprintf("[Sent by %s, who is not on this ticket.]\n\n", $message->from['email']) . $content;
        }
        $clientId = (int) ($decision['client_id'] ?? 0);

        try {
            [$ticketId, $messageId] = self::create($di, $support, $message, $decision, $content, $clientId);
        } catch (InformationException $e) {
            return ['status' => self::REJECTED_REFUSED, 'note' => $e->getMessage(), 'ticket_id' => $decision['ticket_id'] ?? null];
        }
        $decision['ticket_id'] = $ticketId;
        if (($decision['unverified'] ?? null) !== null) {
            self::flagUnverified($di, $ticketId, $messageId, $message->from['email'], (string) $decision['unverified']);
        }
        try {
            self::finish($di, $message, $decision, $clientId, $ticketId, $messageId);
        } catch (\Throwable $e) {
            $decision['note'] = 'Imported, but: ' . $e->getMessage();
        }

        return $decision;
    }

    /**
     * Adds the reply or opens the ticket.
     *
     * @param array<string, mixed> $decision
     *
     * @return array{0: int, 1: int} ticket id, message id
     */
    private static function create(\Pimple\Container $di, Service $support, MimeMessage $message, array $decision, string $content, int $clientId): array
    {
        if ($decision['status'] === self::REPLIED) {
            $ticket = $support->getTicketById((int) $decision['ticket_id']);
            $identity = $clientId > 0 ? $di['db']->getExistingModelById('Client', $clientId, 'Client not found') : new \ClientFence\Identity\Guest();
            $messageId = $support->ticketReply($ticket, $identity, $content);
            $ticketId = (int) $ticket->getId();
        } else {
            $subject = mb_substr(trim($message->subject) !== '' ? trim($message->subject) : 'No subject', 0, 255);
            $helpdesk = $support->getHelpdeskRepository()->find((int) $decision['helpdesk_id']);
            if (!$helpdesk instanceof Helpdesk) {
                throw new InformationException('The help desk of this mailbox no longer exists.');
            }
            if ($clientId > 0) {
                $client = $di['db']->getExistingModelById('Client', $clientId, 'Client not found');
                $ticketId = $support->ticketCreateForClient($client, $helpdesk, ['subject' => $subject, 'content' => $content]);
            } else {
                $hash = $support->ticketCreateForGuest([
                    'name' => mb_substr($message->from['name'] !== '' ? $message->from['name'] : explode('@', $message->from['email'])[0], 0, 255),
                    'email' => $message->from['email'],
                    'subject' => $subject,
                    'content' => $content,
                    'support_helpdesk_id' => (int) $helpdesk->getId(),
                ]);
                $ticketId = (int) self::db($di)->fetchOne('SELECT id FROM support_ticket WHERE access_hash = ?', [$hash]);
            }
            $messageId = (int) self::db($di)->fetchOne('SELECT MIN(id) FROM support_ticket_message WHERE support_ticket_id = ?', [$ticketId]);
        }

        return [$ticketId, $messageId];
    }

    /**
     * The authorized user's name on the message and the attachments.
     *
     * @param array<string, mixed> $decision
     */
    private static function finish(\Pimple\Container $di, MimeMessage $message, array $decision, int $clientId, int $ticketId, int $messageId): void
    {
        if (isset($decision['user']) && $clientId > 0) {
            $user = $decision['user'];
            self::db($di)->executeStatement(
                'INSERT INTO support_ticket_author (message_id, ticket_id, client_id, user_id, name, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$messageId, $ticketId, $clientId, (int) $user['user_id'], mb_substr(trim($user['first_name'] . ' ' . $user['last_name']) ?: (string) $user['email'], 0, 201), date('Y-m-d H:i:s')]
            );
        }
        $skipped = TicketFiles::attachFromMail($di, $clientId, $ticketId, $messageId, $message->attachments);
        if ($skipped !== []) {
            $db = self::db($di);
            $text = (string) $db->fetchOne('SELECT content FROM support_ticket_message WHERE id = ?', [$messageId]);
            $db->update('support_ticket_message', ['content' => $text . "\n\n[Not attached (type or size not allowed): " . implode(', ', $skipped) . ']'], ['id' => $messageId]);
        }
    }

    /** The message text for a ticket: quoted earlier messages cut, never empty. */
    public static function content(\Pimple\Container $di, MimeMessage $message): string
    {
        $text = self::shorten(self::stripQuoted(str_replace("\r\n", "\n", $message->text), self::breakLines(self::settings($di))));

        return $text !== '' ? $text : '(The email had no text.)';
    }

    /** Cuts a text longer than a ticket message may be, leaving room for the notes added later. */
    public static function shorten(string $text): string
    {
        $max = SupportTicketValidator::MAX_CONTENT - 2000;

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max)) . "\n\n[The email was longer and has been shortened.]" : $text;
    }

    /**
     * Cuts the text at the first break line, "On ... wrote:" line or Outlook reply header, and
     * drops a trailing block of ">" quoted lines.
     *
     * @param string[] $breakLines
     */
    public static function stripQuoted(string $text, array $breakLines): string
    {
        $cut = strlen($text);
        foreach ($breakLines as $line) {
            $at = $line === '' ? false : strpos($text, $line);
            if ($at !== false && $at < $cut) {
                $cut = $at;
            }
        }
        $patterns = [
            '/^[ \t]*On [^\n]{4,300}(\n[^\n]{0,200})?wrote:[ \t]*$/m',
            '/^[ \t]*(From|De|Von): [^\n]+\n[ \t]*(Sent|Date|Envoyé|Gesendet): /m',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE) === 1 && $m[0][1] < $cut) {
                $cut = $m[0][1];
            }
        }
        $text = rtrim(substr($text, 0, $cut));
        $lines = explode("\n", $text);
        while ($lines !== [] && (str_starts_with(ltrim((string) end($lines)), '>') || trim((string) end($lines)) === '')) {
            array_pop($lines);
        }

        return trim(implode("\n", $lines));
    }

    /** The ticket id in a subject ([Ticket #123] or WHMCS's [Ticket ID: 123]), else null. */
    public static function ticketIdFromSubject(string $subject): ?int
    {
        return preg_match('/\[Ticket (?:#|ID: ?#?)(\d{1,18})\]/i', $subject, $m) === 1 ? (int) $m[1] : null;
    }

    public static function isAutoReply(MimeMessage $message): bool
    {
        $auto = strtolower(trim($message->header('auto-submitted')));
        if ($auto !== '' && $auto !== 'no') {
            return true;
        }
        if (in_array(strtolower(trim($message->header('precedence'))), ['bulk', 'junk', 'list', 'auto_reply'], true)) {
            return true;
        }

        return $message->header('x-autoreply') !== '' || $message->header('x-autorespond') !== ''
            || strtolower($message->header('x-auto-response-suppress')) === 'all'
            || strtolower(trim($message->header('content-type'))) === 'multipart/report'
            || str_starts_with(strtolower(trim($message->header('content-type'))), 'multipart/report;');
    }

    /** Why the spam lists block a message, or null. */
    public static function spamMatch(array $settings, MimeMessage $message): ?string
    {
        $sender = $message->from['email'];
        foreach (self::lines($settings['mail_block_senders']) as $entry) {
            $entry = strtolower($entry);
            $domain = ltrim($entry, '@');
            if ($sender === $entry || (!str_contains($entry, '@') || str_starts_with($entry, '@')) && str_ends_with($sender, '@' . $domain)) {
                return 'The sender is on the blocked senders list.';
            }
        }
        foreach (self::lines($settings['mail_block_subjects']) as $entry) {
            if (mb_stripos($message->subject, $entry) !== false) {
                return 'The subject is on the blocked subjects list.';
            }
        }
        foreach (self::lines($settings['mail_block_phrases']) as $entry) {
            if (mb_stripos($message->text, $entry) !== false) {
                return 'The text has a blocked phrase.';
            }
        }

        return null;
    }

    /**
     * Staff import a logged message that was not imported (its copy is kept).
     *
     * @return array{status: string, ticket_id: ?int, note: string}
     */
    public static function importAnyway(\Pimple\Container $di, int $logId, int $adminId): array
    {
        $db = self::db($di);
        $row = $db->fetchAssociative('SELECT * FROM ' . self::LOG_TABLE . ' WHERE id = ?', [$logId]);
        if ($row === false) {
            throw new InformationException('Log entry not found.');
        }
        if (in_array($row['status'], [self::IMPORTED, self::REPLIED], true) || $row['raw'] === null) {
            throw new InformationException('This email was imported already or its copy is gone.');
        }
        $raw = @gzuncompress((string) $row['raw']);
        if ($raw === false) {
            throw new InformationException('The copy of this email could not be read.');
        }
        $imported = $row['message_id'] !== '' ? $db->fetchOne('SELECT ticket_id FROM ' . self::LOG_TABLE . ' WHERE message_id = ? AND id <> ? AND status IN (?, ?)', [$row['message_id'], $logId, self::IMPORTED, self::REPLIED]) : false;
        if ($imported !== false) {
            throw new InformationException('This email was imported already (ticket #:id).', [':id' => (int) $imported]);
        }
        // Claimed first, so a second click (or another staff member) cannot import it twice.
        if ($db->executeStatement('UPDATE ' . self::LOG_TABLE . ' SET status = ? WHERE id = ? AND status = ?', [self::IMPORTING, $logId, $row['status']]) !== 1) {
            throw new InformationException('This email is being imported already.');
        }
        try {
            $result = self::process($di, $raw, (int) $row['helpdesk_id'], true);
        } catch (\Throwable $e) {
            $db->update(self::LOG_TABLE, ['status' => $row['status']], ['id' => $logId]);

            throw $e;
        }
        if (!in_array($result['status'], [self::IMPORTED, self::REPLIED], true)) {
            $db->update(self::LOG_TABLE, ['status' => $row['status']], ['id' => $logId]);

            throw new InformationException('The email could not be imported: :note', [':note' => $result['note']]);
        }
        $db->executeStatement(
            'UPDATE ' . self::LOG_TABLE . ' SET status = ?, ticket_id = ?, note = ?, raw = NULL, imported_by = ?, imported_at = ? WHERE id = ?',
            [$result['status'], $result['ticket_id'], mb_substr('Imported by staff. Was: ' . $row['note'] . ($result['note'] !== '' ? ' ' . $result['note'] : ''), 0, 500), $adminId, date('Y-m-d H:i:s'), $logId]
        );

        return $result;
    }

    /**
     * The log, newest first; `raw` is left out (has_copy says whether one is kept).
     *
     * @return array{list: array<int, array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public static function getLog(\Pimple\Container $di, string $filter, int $page, int $perPage): array
    {
        $db = self::db($di);
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $where = match ($filter) {
            'imported' => " WHERE status IN ('" . self::IMPORTED . "', '" . self::REPLIED . "')",
            'not_imported' => " WHERE status NOT IN ('" . self::IMPORTED . "', '" . self::REPLIED . "')",
            default => '',
        };
        try {
            $total = (int) $db->fetchOne('SELECT COUNT(*) FROM ' . self::LOG_TABLE . $where);
        } catch (\Doctrine\DBAL\Exception\TableNotFoundException) {
            return ['list' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'pages' => 1];
        }
        $rows = $db->fetchAllAssociative(
            'SELECT l.id, l.helpdesk_id, h.name AS helpdesk_name, l.created_at, l.from_email, l.from_name, l.to_email, l.subject, l.status, l.note, l.ticket_id, l.imported_by, l.imported_at, (l.raw IS NOT NULL) AS has_copy'
            . ' FROM ' . self::LOG_TABLE . ' l LEFT JOIN support_helpdesk h ON h.id = l.helpdesk_id' . str_replace('status', 'l.status', $where)
            . ' ORDER BY l.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
        );
        foreach ($rows as &$row) {
            $row['has_copy'] = (bool) $row['has_copy'];
            $row['imported'] = in_array($row['status'], [self::IMPORTED, self::REPLIED], true);
        }

        return ['list' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => (int) max(1, ceil($total / $perPage))];
    }

    /**
     * A help desk's mailbox settings for staff (the password only as has_password).
     *
     * @return array<string, mixed>
     */
    public static function mailboxForStaff(\Pimple\Container $di, int $helpdeskId): array
    {
        $row = self::mailbox($di, $helpdeskId);

        return [
            'helpdesk_id' => $helpdeskId,
            'enabled' => (bool) ($row['enabled'] ?? false),
            'host' => (string) ($row['host'] ?? ''),
            'port' => (int) ($row['port'] ?? 993),
            'encryption' => (string) ($row['encryption'] ?? 'ssl'),
            'verify_tls' => (bool) ($row['verify_tls'] ?? true),
            'username' => (string) ($row['username'] ?? ''),
            'has_password' => (string) ($row['password'] ?? '') !== '',
            'folder' => (string) ($row['folder'] ?? 'INBOX'),
            'clients_only' => (bool) ($row['clients_only'] ?? false),
            'replies_only' => (bool) ($row['replies_only'] ?? false),
            'max_per_run' => (int) ($row['max_per_run'] ?? self::MAX_PER_RUN),
            'last_run_at' => $row['last_run_at'] ?? null,
            'last_ok' => isset($row['last_ok']) ? (bool) $row['last_ok'] : null,
            'last_result' => $row['last_result'] ?? null,
            'reply_to' => self::replyCandidate($di, $helpdeskId, (string) ($row['username'] ?? '')),
            'desk_email' => (string) self::db($di)->fetchOne('SELECT email FROM support_helpdesk WHERE id = ?', [$helpdeskId]),
        ];
    }

    /**
     * Where this help desk's ticket emails ask clients to reply while its mailbox is imported: the
     * mailbox login (the address being read), else the help desk's email when the login is not an
     * address. WHMCS sends ticket mail from the department address. Null: the Email settings'
     * Reply-To stays (Email\ReplyTo).
     */
    public static function replyAddress(\Pimple\Container $di, int $helpdeskId): ?string
    {
        try {
            $mailbox = self::mailbox($di, $helpdeskId);
            if ($mailbox === null || !self::flag($mailbox['enabled'] ?? 0) || trim((string) ($mailbox['host'] ?? '')) === '') {
                return null;
            }

            return self::replyCandidate($di, $helpdeskId, (string) ($mailbox['username'] ?? ''));
        } catch (\Exception) {
            // Never in the way of the ticket email: it keeps the Email settings' Reply-To.
            return null;
        }
    }

    /**
     * Whether mail to $address lands in a mailbox that is imported: there, our own notices are
     * blocked and deleted (blocked_own), so a new-ticket alert for a help desk whose email is
     * such an address goes to staff instead (Staff\Service::onAfterClientOpenTicket()).
     */
    public static function isImportedAddress(\Pimple\Container $di, string $address): bool
    {
        $address = strtolower(trim($address));
        if ($address === '') {
            return false;
        }
        try {
            foreach (self::db($di)->fetchAllAssociative('SELECT helpdesk_id, username, host FROM ' . self::MAILBOX_TABLE . ' WHERE enabled = 1') as $row) {
                if (trim((string) $row['host']) !== '' && strtolower((string) self::replyCandidate($di, (int) $row['helpdesk_id'], (string) $row['username'])) === $address) {
                    return true;
                }
            }
        } catch (\Exception) {
            return false;
        }

        return false;
    }

    private static function replyCandidate(\Pimple\Container $di, int $helpdeskId, string $username): ?string
    {
        $deskEmail = self::db($di)->fetchOne('SELECT email FROM support_helpdesk WHERE id = ?', [$helpdeskId]);
        foreach ([$username, is_string($deskEmail) ? $deskEmail : ''] as $address) {
            $address = trim($address);
            if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
                return $address;
            }
        }

        return null;
    }

    /** Saves a help desk's mailbox; an empty password keeps the saved one for the same server and user. */
    public static function saveMailbox(\Pimple\Container $di, int $helpdeskId, array $data): void
    {
        $db = self::db($di);
        if ($db->fetchOne('SELECT id FROM support_helpdesk WHERE id = ?', [$helpdeskId]) === false) {
            throw new InformationException('Help desk not found.');
        }
        $old = self::mailbox($di, $helpdeskId);
        $enabled = self::flag($data['enabled'] ?? null);
        $host = trim(trim((string) ($data['host'] ?? '')), '[]');
        $port = (int) ($data['port'] ?? 0);
        $encryption = (string) ($data['encryption'] ?? 'ssl');
        $username = trim((string) ($data['username'] ?? ''));
        $folder = trim((string) ($data['folder'] ?? '')) ?: 'INBOX';
        $password = (string) ($data['password'] ?? '');
        $maxPerRun = (int) ($data['max_per_run'] ?? self::MAX_PER_RUN);
        if (!array_key_exists($encryption, self::ENCRYPTIONS)) {
            throw new InformationException('Choose the mail server encryption.');
        }
        if ($host !== '' && (preg_match('/^[A-Za-z0-9.:\-]{1,255}$/', $host) !== 1)) {
            throw new InformationException('The mail server host is not valid.');
        }
        if ($port < 1 || $port > 65535) {
            throw new InformationException('The mail server port must be 1 to 65535.');
        }
        if ($maxPerRun < 1 || $maxPerRun > 200) {
            throw new InformationException('Emails per cron run must be 1 to 200.');
        }
        foreach ([$username, $folder, $password] as $value) {
            if (preg_match('/[\r\n\0]/', $value) === 1 || strlen($value) > 255) {
                throw new InformationException('Mail server settings cannot hold line breaks or more than 255 characters.');
            }
        }
        // ClientFence core patch: the saved password is kept only for the same server and user; staff who could
        // point the mailbox elsewhere and keep it had it sent to their own server on the next connection.
        $sameServer = $old !== null && [(string) $old['host'], (int) $old['port'], (string) $old['encryption'], (string) $old['username']] === [$host, $port, $encryption, $username];
        $storedPassword = $password !== '' ? (string) $di['crypt']->encrypt($password) : ($sameServer ? (string) $old['password'] : '');
        if ($password === '' && !$sameServer && (string) ($old['password'] ?? '') !== '') {
            throw new InformationException('Enter the password again when the host, port, encryption or username changes.');
        }
        if ($enabled && ($host === '' || $username === '' || $storedPassword === '')) {
            throw new InformationException('Enter the host, username and password before turning email import on.');
        }
        $row = [
            'enabled' => $enabled ? 1 : 0,
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'verify_tls' => self::flag($data['verify_tls'] ?? null) ? 1 : 0,
            'username' => $username,
            'password' => $storedPassword,
            'folder' => $folder,
            'clients_only' => self::flag($data['clients_only'] ?? null) ? 1 : 0,
            'replies_only' => self::flag($data['replies_only'] ?? null) ? 1 : 0,
            'max_per_run' => $maxPerRun,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($old === null) {
            $db->insert(self::MAILBOX_TABLE, ['helpdesk_id' => $helpdeskId] + $row);
        } else {
            $db->update(self::MAILBOX_TABLE, $row, ['helpdesk_id' => $helpdeskId]);
        }
    }

    /** The import settings (Support settings), defaults filled in. */
    public static function settings(\Pimple\Container $di): array
    {
        try {
            $config = $di['mod_config']('support');
        } catch (\Throwable) {
            $config = [];
        }
        $settings = [];
        foreach (self::DEFAULTS as $key => $default) {
            $settings[$key] = isset($config[$key]) && is_scalar($config[$key]) ? (string) $config[$key] : $default;
        }

        return $settings;
    }

    /** Checks and saves the import settings (Support settings page). */
    public static function saveSettings(\Pimple\Container $di, array $data): void
    {
        $values = [];
        foreach (['mail_reply_limit' => [1, 1000], 'mail_max_mb' => [1, 25], 'mail_log_days' => [1, 3650]] as $key => [$min, $max]) {
            $value = trim((string) ($data[$key] ?? self::DEFAULTS[$key]));
            if (!ctype_digit($value) || (int) $value < $min || (int) $value > $max) {
                throw new InformationException('Check the email import numbers: limits are :min to :max.', [':min' => $min, ':max' => $max]);
            }
            $values[$key] = (string) (int) $value;
        }
        foreach (['mail_insecure_imports', 'mail_prevent_reopen'] as $key) {
            $values[$key] = self::flag($data[$key] ?? null) ? '1' : '0';
        }
        foreach (['mail_break_lines', 'mail_block_senders', 'mail_block_subjects', 'mail_block_phrases'] as $key) {
            $text = str_replace("\r\n", "\n", (string) ($data[$key] ?? ''));
            if (mb_strlen($text) > 20000) {
                throw new InformationException('Each email import list can hold up to 20,000 characters.');
            }
            $values[$key] = implode("\n", self::lines($text));
        }
        $extension = $di['mod_service']('extension');
        $extension->setConfig(array_merge($extension->getConfig('mod_support'), $values, ['ext' => 'mod_support']));
    }

    /**
     * Adds addresses to the blocked senders list (WHMCS "Block Sender & Delete"); our own addresses
     * and ones already listed are skipped. Returns the addresses added.
     *
     * @param string[] $emails
     *
     * @return string[]
     */
    public static function blockSenders(\Pimple\Container $di, array $emails): array
    {
        $listed = array_map('strtolower', self::lines(self::settings($di)['mail_block_senders']));
        $own = self::ownAddresses($di);
        $added = [];
        foreach ($emails as $email) {
            $email = strtolower(trim((string) $email));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || in_array($email, $listed, true) || in_array($email, $own, true) || in_array($email, $added, true)) {
                continue;
            }
            $added[] = $email;
        }
        if ($added !== []) {
            $text = implode("\n", array_merge(self::lines(self::settings($di)['mail_block_senders']), $added));
            if (mb_strlen($text) > 20000) {
                throw new InformationException('The blocked senders list is full (20,000 characters). Remove old entries in the email import settings first.');
            }
            $extension = $di['mod_service']('extension');
            $extension->setConfig(array_merge($extension->getConfig('mod_support'), ['mail_block_senders' => $text, 'ext' => 'mod_support']));
        }

        return $added;
    }

    /**
     * The break lines in use: the saved list, or WHMCS's defaults when none is saved.
     *
     * @return string[]
     */
    public static function breakLines(array $settings): array
    {
        $lines = self::lines($settings['mail_break_lines']);

        return $lines !== [] ? $lines : self::BREAK_LINES;
    }

    /**
     * Reads one mailbox: up to max_per_run messages, each handled, logged and deleted.
     *
     * @param array<string, mixed> $mailbox
     */
    private static function readMailbox(\Pimple\Container $di, array $mailbox): int
    {
        $helpdeskId = (int) $mailbox['helpdesk_id'];
        $address = (string) $mailbox['username'];
        $maxBytes = max(1, (int) self::settings($di)['mail_max_mb']) * 1024 * 1024;
        $db = self::db($di);
        $imap = self::open($di, $mailbox);
        $imap->setMaxLiteral($maxBytes + 1024 * 1024);
        $handled = 0;
        // The email a run was on when it stopped: processing_tries 0 means the run died (out of
        // memory, killed), more means the connection was lost that many times on it. A run that
        // died, or a third lost connection, moves it aside: logged with a copy, not parsed.
        $stuck = $mailbox['processing_uid'] !== null ? (int) $mailbox['processing_uid'] : null;
        $tries = (int) ($mailbox['processing_tries'] ?? 0);
        try {
            $imap->select((string) $mailbox['folder']);
            foreach (array_slice($imap->uids(), 0, max(1, (int) $mailbox['max_per_run'])) as $uid) {
                $retry = $uid === $stuck ? $tries : 0;
                if ($uid === $stuck && $tries >= self::SETTING_ASIDE) {
                    self::logSafely($di, $helpdeskId, null, $address, ['status' => self::FAILED, 'ticket_id' => null, 'note' => 'Runs stopped on this email even while setting it aside; it was removed without a copy.'], null);
                } elseif ($uid === $stuck && ($tries === 0 || $tries >= self::MAX_TRIES)) {
                    $db->update(self::MAILBOX_TABLE, ['processing_tries' => self::SETTING_ASIDE], ['helpdesk_id' => $helpdeskId]);
                    try {
                        self::setAside($di, $imap, $helpdeskId, $uid, $address, $maxBytes, $tries === 0);
                    } catch (MailException $e) {
                        // A lost connection while setting it aside: the next run tries that again.
                        $db->update(self::MAILBOX_TABLE, ['processing_tries' => $tries], ['helpdesk_id' => $helpdeskId]);

                        throw $e;
                    }
                } else {
                    $db->update(self::MAILBOX_TABLE, ['processing_uid' => $uid, 'processing_tries' => $retry], ['helpdesk_id' => $helpdeskId]);
                    try {
                        $size = $imap->size($uid);
                        if ($size !== null && $size > $maxBytes) {
                            self::handleTooLarge($di, $helpdeskId, $imap->fetchHeader($uid), $size);
                        } else {
                            self::handle($di, $helpdeskId, $imap->fetch($uid), $address);
                        }
                    } catch (\Throwable $e) {
                        if (!$imap->isConnected()) {
                            // A lost connection: the email stays and the next run tries it again.
                            $db->update(self::MAILBOX_TABLE, ['processing_tries' => $retry + 1], ['helpdesk_id' => $helpdeskId]);

                            throw $e;
                        }
                        self::logSafely($di, $helpdeskId, null, $address, ['status' => self::FAILED, 'ticket_id' => null, 'note' => 'The email could not be read: ' . $e->getMessage()], null);
                    }
                }
                $imap->delete($uid);
                ++$handled;
            }
            $db->update(self::MAILBOX_TABLE, ['processing_uid' => null, 'processing_tries' => 0], ['helpdesk_id' => $helpdeskId]);
        } finally {
            if ($handled > 0 && $imap->isConnected()) {
                try {
                    $imap->expunge();
                } catch (MailException) {
                    // Marked deleted already: the next run's search leaves them out.
                }
            }
            $imap->logout();
        }

        return $handled;
    }

    /**
     * An email that stopped a run: logged FAILED with its copy (so staff can import it anyway),
     * without parsing more than its headers.
     */
    private static function setAside(\Pimple\Container $di, ImapClient $imap, int $helpdeskId, int $uid, string $address, int $maxBytes, bool $died): void
    {
        $headers = null;
        $raw = null;
        try {
            $headers = MimeMessage::parse($imap->fetchHeader($uid) . "\r\n\r\n");
            $size = $imap->size($uid);
            if ($size === null || $size <= $maxBytes) {
                $raw = $imap->fetch($uid);
            }
        } catch (MailException $e) {
            if (!$imap->isConnected()) {
                throw $e;
            }
        }
        $note = $died
            ? 'An earlier run stopped on this email (too large for the memory limit, or too slow); it was set aside.'
            : sprintf('The connection to the mail server was lost %d times on this email; it was set aside.', self::MAX_TRIES);
        self::logSafely($di, $helpdeskId, $headers, $address, ['status' => self::FAILED, 'ticket_id' => null, 'note' => $note], $raw);
    }

    /** Handles and logs one fetched message; never throws for a bad message. */
    private static function handle(\Pimple\Container $di, int $helpdeskId, string $raw, string $mailboxAddress): void
    {
        $message = null;
        try {
            $message = MimeMessage::parse($raw);
            $result = self::processMessage($di, $message, $helpdeskId);
        } catch (\Throwable $e) {
            $result = ['status' => self::FAILED, 'ticket_id' => null, 'note' => 'Import failed: ' . $e->getMessage()];
        }
        $imported = in_array($result['status'], [self::IMPORTED, self::REPLIED], true);
        $keep = !$imported && !in_array($result['status'], self::NO_COPY, true);
        self::logSafely($di, $helpdeskId, $message, $mailboxAddress, $result, $keep ? $raw : null);
        if ($message !== null && in_array($result['status'], self::TELL_SENDER, true)) {
            self::tellSender($di, $message, $helpdeskId, $result);
        }
    }

    private static function handleTooLarge(\Pimple\Container $di, int $helpdeskId, string $headers, int $size): void
    {
        $message = MimeMessage::parse($headers . "\r\n\r\n");
        $limit = (int) self::settings($di)['mail_max_mb'];
        $result = ['status' => self::REJECTED_TOO_LARGE, 'ticket_id' => null, 'note' => sprintf('The email is %.1f MB; the limit is %d MB.', $size / 1048576, $limit)];
        self::logSafely($di, $helpdeskId, $message, '', $result, null);
        if ($message->from['email'] !== '' && !self::isAutoReply($message) && !in_array($message->from['email'], self::ownAddresses($di), true)) {
            self::tellSender($di, $message, $helpdeskId, $result);
        }
    }

    /**
     * Logs a handled email with its compressed copy when one is kept and small enough; if that
     * fails, without the copy. Never throws.
     *
     * @param array{status: string, ticket_id: ?int, note: string} $result
     */
    private static function logSafely(\Pimple\Container $di, int $helpdeskId, ?MimeMessage $message, string $mailboxAddress, array $result, ?string $raw): void
    {
        $copy = $raw !== null ? gzcompress($raw, 6) : null;
        if ($copy !== null && strlen($copy) > self::MAX_COPY) {
            $copy = null;
            $result['note'] = trim($result['note'] . ' No copy was kept (too large).');
        }
        try {
            self::log($di, $helpdeskId, $message, $mailboxAddress, $result, $copy);

            return;
        } catch (\Throwable $e) {
            if ($copy === null) {
                $di['logger']->error('Support: could not log an imported email: %s', $e->getMessage());

                return;
            }
        }
        try {
            $result['note'] = trim($result['note'] . ' No copy was kept (it could not be saved).');
            self::log($di, $helpdeskId, $message, $mailboxAddress, $result, null);
        } catch (\Throwable $e) {
            $di['logger']->error('Support: could not log an imported email: %s', $e->getMessage());
        }
    }

    /** @param array{status: string, ticket_id: ?int, note: string} $result */
    private static function log(\Pimple\Container $di, int $helpdeskId, ?MimeMessage $parsed, string $mailboxAddress, array $result, ?string $copy): void
    {
        $message = $parsed ?? new MimeMessage();
        $to = $message->to[0]['email'] ?? '';
        self::db($di)->insert(self::LOG_TABLE, [
            'helpdesk_id' => $helpdeskId,
            'created_at' => date('Y-m-d H:i:s'),
            'from_email' => mb_substr($message->from['email'], 0, 255),
            'from_name' => mb_substr($message->from['name'], 0, 255),
            'to_email' => mb_substr($to !== '' ? $to : $mailboxAddress, 0, 255),
            'subject' => mb_substr($message->subject, 0, 255),
            'message_id' => mb_substr(self::messageId($message), 0, 255),
            'status' => $result['status'],
            'note' => mb_substr($result['note'], 0, 500),
            'ticket_id' => $result['ticket_id'],
            'raw' => $copy,
        ], [
            'raw' => \Doctrine\DBAL\ParameterType::LARGE_OBJECT,
        ]);
    }

    /**
     * Tells a sender why their email did not reach a ticket (template mod_support_mail_rejected),
     * unless they wrote more than the 15-minute limit or TELL_PER_HOUR such emails went out.
     */
    private static function tellSender(\Pimple\Container $di, MimeMessage $message, int $helpdeskId, array $result): void
    {
        try {
            $db = self::db($di);
            $since = date('Y-m-d H:i:s', time() - 900);
            if ((int) $db->fetchOne('SELECT COUNT(*) FROM ' . self::LOG_TABLE . ' WHERE from_email = ? AND created_at > ?', [$message->from['email'], $since]) > max(1, (int) self::settings($di)['mail_reply_limit'])) {
                return;
            }
            $marks = implode(', ', array_fill(0, count(self::TELL_SENDER), '?'));
            if ((int) $db->fetchOne('SELECT COUNT(*) FROM ' . self::LOG_TABLE . " WHERE status IN ({$marks}) AND created_at > ?", [...self::TELL_SENDER, date('Y-m-d H:i:s', time() - 3600)]) > self::TELL_PER_HOUR) {
                $di['logger']->warning('Support: more than %s "not imported" emails in an hour; %s is not told.', self::TELL_PER_HOUR, $message->from['email']);

                return;
            }
            $helpdesk = self::db($di)->fetchAssociative('SELECT name, email FROM support_helpdesk WHERE id = ?', [$helpdeskId]) ?: ['name' => '', 'email' => ''];
            $di['mod_service']('email')->sendTemplate([
                'to' => $message->from['email'],
                'to_name' => $message->from['name'],
                'code' => 'mod_support_mail_rejected',
                'rejected' => [
                    'reason' => $result['status'],
                    'note' => $result['note'],
                    'subject' => $message->subject,
                    'ticket_id' => $result['ticket_id'],
                    'helpdesk' => (string) $helpdesk['name'],
                ],
            ]);
        } catch (\Throwable $e) {
            $di['logger']->warning('Support: could not tell %s that their email was not imported: %s', $message->from['email'], $e->getMessage());
        }
    }

    /** Removes log entries older than the "keep the log" setting. */
    private static function prune(\Pimple\Container $di): void
    {
        $days = max(1, (int) self::settings($di)['mail_log_days']);
        self::db($di)->executeStatement('DELETE FROM ' . self::LOG_TABLE . ' WHERE created_at < ?', [date('Y-m-d H:i:s', time() - $days * 86400)]);
    }

    /** @param array<string, mixed> $mailbox */
    private static function open(\Pimple\Container $di, array $mailbox): ImapClient
    {
        $password = (string) $mailbox['password'] !== '' ? (string) $di['crypt']->decrypt((string) $mailbox['password']) : '';
        if ($password === '') {
            throw new MailException('The mailbox password could not be read; enter it again.');
        }
        $imap = new ImapClient();
        $imap->connect((string) $mailbox['host'], (int) $mailbox['port'], (string) $mailbox['encryption'], (bool) $mailbox['verify_tls']);
        try {
            $imap->login((string) $mailbox['username'], $password);
        } catch (\Throwable $e) {
            $imap->logout();

            throw $e;
        }

        return $imap;
    }

    /** @return array<string, mixed>|null */
    private static function mailbox(\Pimple\Container $di, int $helpdeskId): ?array
    {
        try {
            $row = self::db($di)->fetchAssociative('SELECT * FROM ' . self::MAILBOX_TABLE . ' WHERE helpdesk_id = ?', [$helpdeskId]);
        } catch (\Doctrine\DBAL\Exception\TableNotFoundException) {
            return null;
        }

        return $row === false ? null : $row;
    }

    private static function recordRun(Connection $db, int $helpdeskId, bool $ok, string $result): void
    {
        $db->update(self::MAILBOX_TABLE, ['last_run_at' => date('Y-m-d H:i:s'), 'last_ok' => $ok ? 1 : 0, 'last_result' => mb_substr($result, 0, 500)], ['helpdesk_id' => $helpdeskId]);
    }

    /**
     * ClientFence: whether the email is DKIM-signed by its From domain (Mail\Dkim): [true, ''] or
     * [false, why not]. $di['dkim'] replaces the verifier (tests).
     *
     * @return array{0: bool, 1: string}
     */
    public static function verifySender(\Pimple\Container $di, MimeMessage $message): array
    {
        $dkim = isset($di['dkim']) ? $di['dkim'] : new Mail\Dkim();

        try {
            return $dkim->verify($message->raw, $message->from['email']);
        } catch (\Throwable $e) {
            return [false, 'its DKIM signature could not be checked (' . $e->getMessage() . ')'];
        }
    }

    /**
     * ClientFence: marks an imported message whose sender could not be verified; staff see a red
     * warning on it. If the mark cannot be saved, the warning goes into the message text instead.
     */
    private static function flagUnverified(\Pimple\Container $di, int $ticketId, int $messageId, string $sender, string $why): void
    {
        $db = self::db($di);
        try {
            $db->insert(self::UNVERIFIED_TABLE, ['message_id' => $messageId, 'ticket_id' => $ticketId, 'sender' => mb_substr($sender, 0, 255), 'reason' => mb_substr($why, 0, 255), 'created_at' => date('Y-m-d H:i:s')]);
        } catch (\Throwable) {
            $text = (string) $db->fetchOne('SELECT content FROM support_ticket_message WHERE id = ?', [$messageId]);
            $db->update('support_ticket_message', ['content' => sprintf("[Unverified sender: this email may not come from %s (%s).]\n\n", $sender, $why) . $text], ['id' => $messageId]);
        }
    }

    /**
     * ClientFence: the messages of a ticket that came by email from a sender who could not be
     * verified, by message id (staff only).
     *
     * @return array<int, array{sender: string, reason: string}>
     */
    public static function unverifiedMessages(\Pimple\Container $di, int $ticketId): array
    {
        try {
            // By the message's ticket: a merge moves messages to another ticket.
            $rows = self::db($di)->fetchAllAssociative('SELECT u.message_id, u.sender, u.reason FROM ' . self::UNVERIFIED_TABLE . ' u JOIN support_ticket_message m ON m.id = u.message_id WHERE m.support_ticket_id = ?', [$ticketId]);
        } catch (\Throwable) {
            return [];
        }
        $flags = [];
        foreach ($rows as $row) {
            $flags[(int) $row['message_id']] = ['sender' => (string) $row['sender'], 'reason' => (string) $row['reason']];
        }

        return $flags;
    }

    /**
     * The client with this email, whatever its status (suspended and canceled clients can still
     * write to support).
     *
     * @return array<string, mixed>|null
     */
    private static function clientByEmail(Connection $db, string $email): ?array
    {
        $row = $db->fetchAssociative('SELECT id, status FROM client WHERE LOWER(email) = ? ORDER BY id LIMIT 1', [$email]);

        return $row === false ? null : $row;
    }

    /**
     * Active authorized users with this email who may use tickets, by client id (owners of an
     * account first).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function usersByEmail(Connection $db, string $email): array
    {
        try {
            $rows = $db->fetchAllAssociative(
                'SELECT u.id AS user_id, u.email, u.first_name, u.last_name, a.client_id, a.owner, a.permissions'
                . ' FROM client_user u JOIN client_user_account a ON a.user_id = u.id JOIN client c ON c.id = a.client_id'
                . " WHERE LOWER(u.email) = ? AND u.status = 'active' AND c.status = 'active' ORDER BY a.owner DESC, a.client_id",
                [$email]
            );
        } catch (\Doctrine\DBAL\Exception\TableNotFoundException) {
            return [];
        }
        $users = [];
        foreach ($rows as $row) {
            if ((int) $row['owner'] === 1 || in_array('tickets', explode(',', (string) $row['permissions']), true)) {
                $users[(int) $row['client_id']] = $row;
            }
        }

        return $users;
    }

    /**
     * Addresses mail must never come from: the help desks, the company and sender addresses, the
     * mailboxes' logins and staff.
     *
     * @return string[]
     */
    private static function ownAddresses(\Pimple\Container $di): array
    {
        $db = self::db($di);
        $addresses = array_merge(
            $db->fetchFirstColumn('SELECT email FROM support_helpdesk WHERE email IS NOT NULL'),
            $db->fetchFirstColumn('SELECT username FROM ' . self::MAILBOX_TABLE),
            $db->fetchFirstColumn('SELECT email FROM admin'),
        );
        try {
            $addresses[] = (string) $di['mod_service']('system')->getParamValue('company_email');
            $addresses[] = (string) ($di['mod_config']('email')['from_email'] ?? '');
        } catch (\Throwable) {
            // Settings not readable: the stored addresses still count.
        }

        return array_values(array_unique(array_filter(array_map(static fn ($a): string => strtolower(trim((string) $a)), $addresses))));
    }

    private static function messageId(MimeMessage $message): string
    {
        return trim($message->header('message-id'), " <>\t");
    }

    /** A checkbox or switch value: on for 1, true, on or yes. */
    private static function flag(mixed $value): bool
    {
        return is_scalar($value) && in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }

    /** @return string[] non-empty trimmed lines */
    private static function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", str_replace("\r\n", "\n", $text))), static fn (string $l): bool => $l !== ''));
    }

    /** At least MEMORY for the import, never lowering a higher or unlimited limit. */
    private static function raiseMemoryLimit(): void
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return;
        }
        $bytes = (int) $limit * match (strtolower(substr($limit, -1))) {
            'g' => 1024 * 1024 * 1024,
            'm' => 1024 * 1024,
            'k' => 1024,
            default => 1,
        };
        if ($bytes < self::MEMORY) {
            @ini_set('memory_limit', (string) self::MEMORY);
        }
    }

    /** One import at a time (cron runs can overlap); not needed on other databases. */
    private static function lock(Connection $db): bool
    {
        if (!$db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
            return true;
        }

        return (int) $db->fetchOne("SELECT GET_LOCK('clientfence_mail_import', 0)") === 1;
    }

    private static function unlock(Connection $db): void
    {
        if ($db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
            $db->fetchOne("SELECT RELEASE_LOCK('clientfence_mail_import')");
        }
    }

    private static function db(\Pimple\Container $di): Connection
    {
        return $di['em']->getConnection();
    }
}
