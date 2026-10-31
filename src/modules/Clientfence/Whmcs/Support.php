<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Whmcs;

use ClientFence\Mod\Support\HiddenHelpdesks;
use ClientFence\Mod\Support\HiddenKbCategories;
use ClientFence\Sanitizer\BrowserHtmlSanitizer;
use ClientFence\Mod\Support\TicketFeedback;
use ClientFence\Mod\Support\TicketFiles;
use ClientFence\Mod\Support\TicketMerge;
use ClientFence\Mod\Support\TicketPriority;
use Doctrine\DBAL\Connection;

/**
 * ClientFence: the WHMCS import's support steps (Run.php runs them in batches, after the money
 * steps): staff, departments, predefined replies, tickets with their replies, attachments, notes and
 * feedback, the knowledge base, announcements, network issues and the email log. Rows are written
 * directly, as in Accounts.php; nothing is emailed.
 *
 * Staff (owner decision 2026-10-19: imported switched off): a WHMCS admin whose email is already
 * staff here is that staff member; the others become inactive staff with their WHMCS password hash
 * (Full Administrators in the Super Administrator group, every other role in Support Staff), to be
 * switched on at the cutover. A reply signed by a name no WHMCS admin has any more (a deleted admin)
 * gets an inactive staff member of that name, so every reply keeps its author.
 *
 * Departments become help desks with their WHMCS ids (a fresh install's default help desk of the
 * same id is overwritten): a hidden department is hidden from clients (HiddenHelpdesks), its
 * mailbox comes over switched off, its feedback request setting is kept.
 *
 * Tickets keep their WHMCS id. A merged ticket is not a ticket here (as after a ClientFence merge):
 * its id opens the ticket it was merged into (support_ticket_merge). Statuses: Closed and custom
 * statuses WHMCS does not count as active are closed, Answered and On Hold wait for the client
 * (on_hold, which the help desk's close-after closes, as WHMCS's inactive ticket closing), the rest
 * are open. Replies keep their author (client, authorized user, staff or guest), their date and
 * their star rating; a reply WHMCS took from an address that is not the client's is marked
 * unverified.
 *
 * Attachments are copied from WHMCS's attachments folder (Import settings, owner decision
 * 2026-10-19) into the ticket files folder (TicketFiles); a file WHMCS had removed, or that is not
 * in the folder, shows as removed on its message, and a type ClientFence never serves (TicketFiles::
 * TYPES) is left out; both are named in the warnings. The files keep their WHMCS dates, so
 * "Keep ticket files" applies to them as to any file (owner decision 2026-10-19).
 *
 * The knowledge base, announcements and predefined replies get new ids (a fresh install's samples
 * stay); a hidden category stays hidden from clients (HiddenKbCategories, owner decision 2026-10-19);
 * WHMCS translations are left out. The email log becomes each client's email history.
 */
final class Support
{
    /** The steps in order: name => [label, WHMCS table read]. */
    public const STEPS = [
        'staff' => ['Staff', 'tbladmins'],
        'departments' => ['Support departments', 'tblticketdepartments'],
        'predefined' => ['Predefined replies', 'tblticketpredefinedreplies'],
        'tickets' => ['Tickets', 'tbltickets'],
        'replies' => ['Ticket replies', 'tblticketreplies'],
        'ticketnotes' => ['Ticket notes', 'tblticketnotes'],
        'feedback' => ['Ticket feedback', 'tblticketfeedback'],
        'kbcategories' => ['Knowledge base categories', 'tblknowledgebasecats'],
        'kbarticles' => ['Knowledge base articles', 'tblknowledgebase'],
        'announcements' => ['Announcements', 'tblannouncements'],
        'networkissues' => ['Network issues', 'tblnetworkissues'],
        'emails' => ['Email log', 'tblemails'],
    ];

    /** WHMCS's inactive ticket closing off (0 hours): the longest close-after a help desk holds. */
    public const NEVER_CLOSE = 32767;

    /** WHMCS network issue values => ClientFence's (NetworkIssues). */
    private const ISSUE_STATUSES = ['reported' => 'reported', 'investigating' => 'investigating', 'in progress' => 'in_progress', 'outage' => 'outage', 'scheduled' => 'scheduled', 'resolved' => 'resolved'];

    /** @var list<string> */
    private array $warnings = [];

    /** @var list<string> files written by this batch (removed again when it rolls back) */
    private array $written = [];

    /** @var array<string, int>|null WHMCS admin full name (lower case) => staff id */
    private ?array $staffByName = null;

    /** @var array<string, array<string, mixed>>|null WHMCS custom ticket statuses by lower-case title */
    private ?array $statuses = null;

    /**
     * @param \Closure(string): string                    $encrypt      this install's encryption
     * @param \Closure(string, array<string, mixed>): void $config       merges extension settings
     * @param string                                      $attachments  WHMCS's attachments folder ('' when not given)
     * @param string                                      $files        this install's ticket files folder
     * @param int                                         $keepDays     its "Keep ticket files" days (0: kept)
     */
    public function __construct(
        private readonly Source $source,
        private readonly Connection $db,
        private readonly Map $map,
        private readonly ?Cipher $cipher,
        private readonly \Closure $encrypt,
        private readonly \Closure $config,
        private readonly string $attachments,
        private readonly string $files,
        private readonly int $keepDays,
        private readonly string $now,
    ) {
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** Removes the files this batch copied (Run calls it when the batch rolls back). */
    public function discard(): void
    {
        foreach ($this->written as $path) {
            @unlink($path);
        }
        $this->written = [];
    }

    public function total(string $step): int
    {
        $table = self::STEPS[$step][1];

        return $this->source->hasTable($table) ? $this->source->count($table) : 0;
    }

    /**
     * WHMCS's ticket attachments: how many files its tickets and replies name, leaving out those
     * WHMCS had removed (they come over as removed).
     */
    public function attachmentTotal(): int
    {
        $count = 0;
        foreach (['tbltickets', 'tblticketreplies'] as $table) {
            if (!$this->source->hasTable($table)) {
                continue;
            }
            $kept = in_array('attachments_removed', $this->source->columns($table), true) ? ' AND attachments_removed = 0' : '';
            foreach ($this->source->rows('SELECT attachment FROM ' . $table . " WHERE attachment <> ''" . $kept) as $row) {
                $count += count(self::fileNames((string) $row['attachment']));
            }
        }

        return $count;
    }

    /**
     * Imports up to $limit rows of a step after the WHMCS id $after: the last id read (null: done)
     * and how many rows were imported.
     *
     * @return array{0: ?int, 1: int}
     */
    public function batch(string $step, int $after, int $limit): array
    {
        $table = self::STEPS[$step][1] ?? throw new \InvalidArgumentException('Unknown step ' . $step);
        if (!$this->source->hasTable($table)) {
            return [null, 0];
        }
        $rows = $this->source->rows('SELECT * FROM ' . $table . ' WHERE id > ? ORDER BY id LIMIT ' . $limit, [$after]);
        if ($rows === []) {
            $this->finish($step);

            return [null, 0];
        }
        $done = 0;
        foreach ($rows as $row) {
            $done += match ($step) {
                'staff' => $this->staff($row),
                'departments' => $this->department($row),
                'predefined' => $this->predefined($row),
                'tickets' => $this->ticket($row),
                'replies' => $this->reply($row),
                'ticketnotes' => $this->note($row),
                'feedback' => $this->feedback($row),
                'kbcategories' => $this->kbCategory($row),
                'kbarticles' => $this->kbArticle($row),
                'announcements' => $this->announcement($row),
                'networkissues' => $this->networkIssue($row),
                'emails' => $this->email($row),
            };
        }

        return [(int) end($rows)['id'], $done];
    }

    /** What a step does once its last batch ran. */
    private function finish(string $step): void
    {
        if ($step === 'feedback' && !$this->map->has('notice', 'feedback') && $this->source->hasTable('tbltickets')) {
            $this->map->put('notice', 'feedback', 0);
            $orphans = $this->source->count('tblticketfeedback', 'ticketid NOT IN (SELECT id FROM tbltickets)');
            if ($orphans > 0) {
                $this->warn(sprintf('%d ticket feedback answers are for tickets WHMCS no longer has; they were not imported.', $orphans));
            }
        }
        if ($step === 'departments' && !$this->map->has('notice', 'departments')) {
            $this->map->put('notice', 'departments', 0);
            ($this->config)('mod_support', ['ticket_rating' => strtolower(trim((string) $this->setting('TicketRatingEnabled'))) === 'on' ? '1' : '0']);
            $kept = $this->db->fetchAllAssociative('SELECT id, name FROM support_helpdesk WHERE id NOT IN (' . implode(',', [0, ...array_values($this->map->all('helpdesk'))]) . ')');
            foreach ($kept as $helpdesk) {
                $this->warn(sprintf('Help desk "%s" of this install has no WHMCS department; delete or hide it if clients should not use it.', $helpdesk['name']));
            }
            foreach (['tblticketescalations' => 'escalation rules', 'tblticketspamfilters' => 'spam filters', 'tbldownloads' => 'downloads'] as $table => $what) {
                $count = $this->source->hasTable($table) ? $this->source->count($table) : 0;
                if ($count > 0) {
                    $this->warn(sprintf('WHMCS has %d %s; they were not imported (set them up again where needed).', $count, $what));
                }
            }
        }
        if ($step === 'tickets' && !$this->map->has('notice', 'tickets')) {
            $this->map->put('notice', 'tickets', 0);
            $this->ticketIds();
            $this->mergedMessages();
        }
        if ($step === 'replies' && !$this->map->has('notice', 'replies') && $this->attachments !== '') {
            $this->map->put('notice', 'replies', 0);
            $old = (int) $this->db->fetchOne('SELECT COUNT(*) FROM support_ticket_file WHERE uploader_kind IN (?, ?) AND removed_at IS NULL AND created_at < ?', ['whmcs', 'whmcsstaff', date('Y-m-d H:i:s', time() - $this->keepDays * 86400)]);
            if ($this->keepDays > 0 && $old > 0) {
                $this->warn(sprintf('%d imported ticket files are older than "Keep ticket files" (%d days): the next cron run removes them unless you raise the setting (Support settings).', $old, $this->keepDays));
            }
        }
    }

    /** @param array<string, mixed> $row */
    private function staff(array $row): int
    {
        $id = (int) $row['id'];
        if ($this->map->has('staff', $id)) {
            return 0;
        }
        $name = trim(trim((string) ($row['firstname'] ?? '')) . ' ' . trim((string) ($row['lastname'] ?? '')));
        $email = trim((string) ($row['email'] ?? ''));
        $existing = $email === '' ? false : $this->db->fetchOne('SELECT id FROM admin WHERE LOWER(email) = ?', [strtolower($email)]);
        if ($existing !== false) {
            $this->map->put('staff', $id, (int) $existing);
            $this->staffByName = null;

            return 1;
        }
        $superAdmin = (int) ($row['roleid'] ?? 0) === 1;
        $hash = (string) ($row['passwordhash'] ?? '');
        $staffId = $this->newStaff($name !== '' ? $name : ($email !== '' ? $email : 'WHMCS admin ' . $id), $email !== '' ? $email : self::placeholderEmail('admin-' . $id), password_get_info($hash)['algo'] !== null ? $hash : null, $superAdmin, self::cut($row['signature'] ?? '', 255) ?: null, Accounts::date($row['created_at'] ?? null));
        $this->map->put('staff', $id, $staffId);
        $this->staffByName = null;
        if (!$superAdmin && (int) ($row['roleid'] ?? 0) !== 3) {
            $this->warn(sprintf('Staff %s: the WHMCS role was not Support Operator; they are in Support Staff (change the group if needed).', $name));
        }
        if (password_get_info($hash)['algo'] === null) {
            $this->warn(sprintf('Staff %s: the WHMCS password could not be carried over; they set a new one with a password reset.', $name));
        }
        if (trim((string) ($row['authmodule'] ?? '')) !== '') {
            $this->warn(sprintf('Staff %s used two-factor sign-in in WHMCS; ClientFence staff sign in with the password only.', $name));
        }

        return 1;
    }

    /** An inactive staff member; returns the id. */
    private function newStaff(string $name, string $email, ?string $hash, bool $superAdmin, ?string $signature, ?string $created): int
    {
        $this->db->insert('admin', [
            'email' => self::cut($email, 255),
            'pass' => $hash ?? password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
            'name' => self::cut($name, 255),
            'signature' => $signature,
            'status' => 'inactive',
            'created_at' => $created ?? $this->now,
            'updated_at' => $this->now,
        ]);
        $staffId = (int) $this->db->lastInsertId();
        $group = $this->db->fetchOne('SELECT id FROM admin_group WHERE system_name = ?', [$superAdmin ? 'super_admin' : 'support_staff']);
        if ($group === false) {
            $group = $this->db->fetchOne($superAdmin ? 'SELECT MIN(id) FROM admin_group' : 'SELECT MAX(id) FROM admin_group');
        }
        if ($group !== false && $group !== null) {
            $this->db->insert('admin_group_member', ['admin_id' => $staffId, 'admin_group_id' => (int) $group, 'created_at' => $this->now]);
        }

        return $staffId;
    }

    /** The staff member of a WHMCS admin name (replies, notes and tickets keep the name only). */
    private function staffNamed(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        if ($this->staffByName === null) {
            $this->staffByName = [];
            foreach ($this->source->rows('SELECT id, firstname, lastname, username FROM tbladmins ORDER BY id') as $admin) {
                $staffId = $this->map->get('staff', (int) $admin['id']);
                if ($staffId === null) {
                    continue;
                }
                $this->staffByName[strtolower(trim(trim((string) $admin['firstname']) . ' ' . trim((string) $admin['lastname'])))] ??= $staffId;
                $this->staffByName[strtolower(trim((string) $admin['username']))] ??= $staffId;
            }
        }
        $key = strtolower($name);
        if (isset($this->staffByName[$key])) {
            return $this->staffByName[$key];
        }
        // A former WHMCS admin: one inactive staff member of that name.
        $staffId = $this->map->get('staffname', md5($key));
        if ($staffId === null) {
            $staffId = $this->newStaff($name, self::placeholderEmail('former-' . substr(md5($key), 0, 12)), null, false, null, null);
            $this->map->put('staffname', md5($key), $staffId);
            $this->warn(sprintf('Replies signed "%s" match no WHMCS admin (a deleted one); an inactive staff member of that name keeps them.', $name));
        }

        return $this->staffByName[$key] = $staffId;
    }

    /** @param array<string, mixed> $row */
    private function department(array $row): int
    {
        $id = (int) $row['id'];
        if ($this->map->has('helpdesk', $id)) {
            return 0;
        }
        $name = self::cut($row['name'] ?? '', 255) ?: 'Department ' . $id;
        $hours = (int) $this->setting('CloseInactiveTickets');
        $values = [
            'name' => $name,
            'email' => self::cut($row['email'] ?? '', 255) ?: null,
            'close_after' => $hours > 0 ? min($hours, self::NEVER_CLOSE) : self::NEVER_CLOSE,
            'can_reopen' => 1,
            'signature' => null,
            'updated_at' => $this->now,
        ];
        if ($this->db->fetchOne('SELECT id FROM support_helpdesk WHERE id = ?', [$id]) !== false) {
            // A fresh install's default help desk: WHMCS's department of the same id takes it over.
            $this->db->update('support_helpdesk', $values, ['id' => $id]);
        } else {
            $this->db->insert('support_helpdesk', ['id' => $id, 'created_at' => $this->now] + $values);
        }
        $this->map->put('helpdesk', $id, $id);
        $this->db->executeStatement('DELETE FROM ' . TicketFeedback::HELPDESKS . ' WHERE helpdesk_id = ?', [$id]);
        $this->db->insert(TicketFeedback::HELPDESKS, ['helpdesk_id' => $id, 'feedback_request' => (int) ($row['feedback_request'] ?? 1) === 0 ? 0 : 1, 'updated_at' => $this->now]);
        $this->db->executeStatement('DELETE FROM ' . HiddenHelpdesks::TABLE . ' WHERE helpdesk_id = ?', [$id]);
        if (self::on($row['hidden'] ?? '')) {
            $this->db->insert(HiddenHelpdesks::TABLE, ['helpdesk_id' => $id, 'hidden_at' => $this->now]);
        }
        $this->mailbox($id, $name, $row);

        return 1;
    }

    /**
     * A department's mailbox (WHMCS's POP3/IMAP import), switched off until the cutover.
     *
     * @param array<string, mixed> $row
     */
    private function mailbox(int $helpdeskId, string $name, array $row): void
    {
        $host = trim((string) ($row['host'] ?? ''));
        if ($host === '') {
            return;
        }
        $port = (int) ($row['port'] ?? 0) ?: 993;
        $password = '';
        if (trim((string) ($row['mail_auth_config'] ?? '')) !== '') {
            $this->warn(sprintf('Help desk "%s": WHMCS signed in to its mailbox with OAuth (Google or Microsoft); enter a password or app password on the help desk page before switching mail import on.', $name));
        } elseif (trim((string) ($row['password'] ?? '')) !== '') {
            $plain = $this->cipher?->decrypt((string) $row['password']);
            if ($plain === null) {
                $this->warn(sprintf('Help desk "%s": the mailbox password could not be read%s; enter it on the help desk page.', $name, $this->cipher === null ? ' (no WHMCS encryption key)' : ''));
            } else {
                $password = ($this->encrypt)($plain);
            }
        }
        if (in_array($port, [110, 995], true)) {
            $this->warn(sprintf('Help desk "%s": WHMCS read its mailbox over POP3 (port %d); ClientFence reads mail over IMAP, so check the server and port before switching mail import on.', $name, $port));
        }
        $this->db->executeStatement('DELETE FROM support_helpdesk_mail WHERE helpdesk_id = ?', [$helpdeskId]);
        $this->db->insert('support_helpdesk_mail', [
            'helpdesk_id' => $helpdeskId,
            'enabled' => 0,
            'host' => self::cut($host, 255),
            'port' => $port,
            'encryption' => in_array($port, [143, 110], true) ? 'tls' : 'ssl',
            'verify_tls' => 1,
            'username' => self::cut($row['login'] ?? '', 255),
            'password' => $password,
            'folder' => 'INBOX',
            'clients_only' => self::on($row['clientsonly'] ?? '') ? 1 : 0,
            'replies_only' => self::on($row['piperepliesonly'] ?? '') ? 1 : 0,
            'max_per_run' => 25,
            'updated_at' => $this->now,
        ]);
        $this->warn(sprintf('Help desk "%s": its mailbox came over switched off; switch mail import on at the cutover.', $name));
    }

    /** @param array<string, mixed> $row */
    private function predefined(array $row): int
    {
        $id = (int) $row['id'];
        if ($this->map->has('predefined', $id)) {
            return 0;
        }
        $this->db->insert('support_pr', [
            'support_pr_category_id' => $this->predefinedCategory((int) ($row['catid'] ?? 0)),
            'title' => self::cut($row['name'] ?? '', 100) ?: 'Reply ' . $id,
            'content' => Text::breaks((string) ($row['reply'] ?? '')),
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);
        $this->map->put('predefined', $id, (int) $this->db->lastInsertId());

        return 1;
    }

    /** The canned response category of a WHMCS category (nested ones as "Parent / Child"). */
    private function predefinedCategory(int $catId): int
    {
        $known = $this->map->get('prcategory', $catId);
        if ($known !== null) {
            return $known;
        }
        $names = [];
        $seen = [];
        for ($id = $catId; $id > 0 && !isset($seen[$id]); ) {
            $seen[$id] = true;
            $cat = $this->source->hasTable('tblticketpredefinedcats') ? $this->source->rows('SELECT id, parentid, name FROM tblticketpredefinedcats WHERE id = ?', [$id])[0] ?? null : null;
            if ($cat === null) {
                break;
            }
            array_unshift($names, trim((string) $cat['name']));
            $id = (int) $cat['parentid'];
        }
        $this->db->insert('support_pr_category', ['title' => self::cut(implode(' / ', array_filter($names)) ?: 'WHMCS', 100), 'created_at' => $this->now, 'updated_at' => $this->now]);
        $categoryId = (int) $this->db->lastInsertId();
        $this->map->put('prcategory', $catId, $categoryId);

        return $categoryId;
    }

    /** @param array<string, mixed> $row */
    private function ticket(array $row): int
    {
        $id = (int) $row['id'];
        if ($this->map->has('ticket', $id) || $this->map->has('skipped', 'ticket:' . $id)) {
            return 0;
        }
        if ((int) ($row['merged_ticket_id'] ?? 0) > 0) {
            return $this->merged($row);
        }
        $whmcsClient = (int) ($row['userid'] ?? 0);
        $clientId = $whmcsClient > 0 ? $this->map->get('client', $whmcsClient) : null;
        if ($whmcsClient > 0 && $clientId === null) {
            $this->map->put('skipped', 'ticket:' . $id, 0);
            $this->warn(sprintf('Ticket #%d was not imported: its client was not imported.', $id));

            return 0;
        }
        $helpdeskId = $this->map->get('helpdesk', (int) ($row['did'] ?? 0));
        if ($helpdeskId === null) {
            $helpdeskId = (int) $this->db->fetchOne('SELECT MIN(id) FROM support_helpdesk');
            $this->warn(sprintf('Ticket #%d: its WHMCS department is gone; it is in help desk #%d.', $id, $helpdeskId));
        }
        $client = $clientId === null ? false : $this->db->fetchAssociative('SELECT first_name, last_name, email FROM client WHERE id = ?', [$clientId]);
        $created = Accounts::date($row['date'] ?? null) ?? $this->now;
        [$relType, $relId] = $this->related($clientId, (string) ($row['service'] ?? ''));
        $this->db->insert('support_ticket', [
            'id' => $id,
            'support_helpdesk_id' => $helpdeskId,
            'client_id' => $clientId,
            'access_hash' => $clientId === null ? bin2hex(random_bytes(24)) : null,
            'author_name' => self::cut($client === false ? ($row['name'] ?? '') : trim($client['first_name'] . ' ' . $client['last_name']), 255),
            'author_email' => self::cut($client === false ? ($row['email'] ?? '') : $client['email'], 255),
            'priority' => TicketPriority::LEVELS[strtolower(trim((string) ($row['urgency'] ?? '')))] ?? TicketPriority::MEDIUM,
            'subject' => self::cut($row['title'] ?? '', 255) ?: '(no subject)',
            'status' => $this->status((string) ($row['status'] ?? '')),
            'rel_type' => $relType,
            'rel_id' => $relId,
            'rel_status' => $relType === null ? null : 'complete',
            'created_at' => $created,
            'updated_at' => Accounts::date($row['lastreply'] ?? null) ?? $created,
        ]);
        $this->map->put('ticket', $id, $id);
        $staffId = $this->staffNamed((string) ($row['admin'] ?? ''));
        $messageId = $this->message($id, $staffId === null ? $clientId : null, $staffId, (string) ($row['message'] ?? ''), $created);
        $this->author($messageId, $id, $clientId, $staffId === null ? (int) ($row['requestor_id'] ?? 0) : 0, $created);
        $this->files('t', $id, (string) ($row['attachment'] ?? ''), self::on($row['attachments_removed'] ?? ''), $id, $messageId, $clientId, $staffId, $created);
        $assigned = (int) ($row['flag'] ?? 0) > 0 ? $this->map->get('staff', (int) $row['flag']) : null;
        if ($assigned !== null) {
            $this->db->insert('support_ticket_assignment', ['ticket_id' => $id, 'admin_id' => $assigned, 'assigned_by' => null, 'assigned_at' => $created]);
        }
        if (trim((string) ($row['cc'] ?? '')) !== '') {
            $this->warn(sprintf('Ticket #%d had CC recipients in WHMCS; ClientFence tickets have none, so they no longer get its replies.', $id));
        }
        if ((string) ($row['tid'] ?? '') !== '' && (string) $row['tid'] !== (string) $id) {
            $this->warn(sprintf('Ticket #%d was numbered %s in WHMCS; it is #%d now.', $id, $row['tid'], $id));
        }

        return 1;
    }

    /**
     * A ticket WHMCS merged into another: its id opens that ticket. WHMCS moves the merged ticket's
     * messages; one it left behind is added to the ticket it was merged into.
     *
     * @param array<string, mixed> $row
     */
    private function merged(array $row): int
    {
        $id = (int) $row['id'];
        $target = (int) $row['merged_ticket_id'];
        $seen = [$id => true];
        while (!isset($seen[$target])) {
            $seen[$target] = true;
            $next = (int) ($this->source->value('SELECT merged_ticket_id FROM tbltickets WHERE id = ?', [$target]) ?? 0);
            if ($next <= 0) {
                break;
            }
            $target = $next;
        }
        $targetRow = $this->source->rows('SELECT id, userid, merged_ticket_id FROM tbltickets WHERE id = ?', [$target])[0] ?? null;
        if ($targetRow === null || (int) $targetRow['merged_ticket_id'] > 0 || ((int) $targetRow['userid'] > 0 && !$this->map->has('client', (int) $targetRow['userid']))) {
            $this->map->put('skipped', 'ticket:' . $id, 0);
            $this->warn(sprintf('Ticket #%d was merged into ticket #%d, which was not imported; it was left out.', $id, $target));

            return 0;
        }
        $this->db->executeStatement('DELETE FROM ' . TicketMerge::TABLE . ' WHERE ticket_id = ?', [$id]);
        $this->db->insert(TicketMerge::TABLE, [
            'ticket_id' => $id,
            'merged_into' => $target,
            'access_hash' => null,
            'subject' => self::cut($row['title'] ?? '', 255),
            'merged_by' => null,
            'merged_at' => Accounts::date($row['lastreply'] ?? null) ?? $this->now,
        ]);
        $this->map->put('ticket', $id, $target);
        if (trim((string) ($row['message'] ?? '')) !== '' || trim((string) ($row['attachment'] ?? '')) !== '') {
            // Imported with the target's replies: the target may come later (a higher id).
            $this->map->put('mergedmessage', $id, $target);
        }

        return 1;
    }

    /** @param array<string, mixed> $row */
    private function reply(array $row): int
    {
        $id = (int) $row['id'];
        if ($this->map->has('reply', $id) || $this->map->has('skipped', 'reply:' . $id)) {
            return 0;
        }
        $ticketId = $this->map->get('ticket', (int) ($row['tid'] ?? 0));
        $ticket = $ticketId === null ? false : $this->db->fetchAssociative('SELECT id, client_id, author_email FROM support_ticket WHERE id = ?', [$ticketId]);
        if ($ticket === false) {
            $this->map->put('skipped', 'reply:' . $id, 0);

            return 0;
        }
        $clientId = $ticket['client_id'] === null ? null : (int) $ticket['client_id'];
        $staffId = $this->staffNamed((string) ($row['admin'] ?? ''));
        $date = Accounts::date($row['date'] ?? null) ?? $this->now;
        $messageId = $this->message($ticketId, $staffId === null ? $clientId : null, $staffId, (string) ($row['message'] ?? ''), $date);
        $this->map->put('reply', $id, $messageId);
        if ($staffId === null) {
            $this->author($messageId, $ticketId, $clientId, (int) ($row['requestor_id'] ?? 0), $date);
            // A reply WHMCS took from an address that is not the client's (piped mail).
            if ($clientId !== null && (int) ($row['userid'] ?? 0) === 0 && (int) ($row['requestor_id'] ?? 0) === 0 && trim((string) ($row['email'] ?? '')) !== '') {
                $sender = trim(trim((string) ($row['name'] ?? '')) . ' <' . trim((string) $row['email']) . '>');
                $this->db->insert('support_ticket_message_unverified', ['message_id' => $messageId, 'ticket_id' => $ticketId, 'sender' => self::cut($sender, 255), 'reason' => 'Sent from an address that is not the client\'s (imported from WHMCS)', 'created_at' => $date]);
            }
        }
        $rating = (int) ($row['rating'] ?? 0);
        if ($staffId !== null && $rating >= 1 && $rating <= TicketFeedback::MAX_STARS) {
            $this->db->insert(TicketFeedback::RATINGS, [
                'message_id' => $messageId, 'ticket_id' => $ticketId, 'admin_id' => $staffId, 'rating' => $rating,
                'rated_by_kind' => $clientId === null ? 'guest' : 'client', 'rated_by_id' => $clientId ?? 0, 'ip' => '', 'rated_at' => $date,
            ]);
        }
        $this->files('r', $id, (string) ($row['attachment'] ?? ''), self::on($row['attachments_removed'] ?? ''), $ticketId, $messageId, $clientId, $staffId, $date);

        return 1;
    }

    /** The messages (and files) merged tickets left behind, added to the tickets they were merged into. */
    private function mergedMessages(): void
    {
        foreach ($this->map->all('mergedmessage') as $source => $target) {
            $row = $this->source->rows('SELECT * FROM tbltickets WHERE id = ?', [(int) $source])[0] ?? null;
            $ticket = $this->db->fetchAssociative('SELECT id, client_id FROM support_ticket WHERE id = ?', [$target]);
            if ($row === null || $ticket === false || $this->map->has('notice', 'merged:' . $source)) {
                continue;
            }
            $this->map->put('notice', 'merged:' . $source, 0);
            $clientId = $ticket['client_id'] === null ? null : (int) $ticket['client_id'];
            $staffId = $this->staffNamed((string) ($row['admin'] ?? ''));
            $date = Accounts::date($row['date'] ?? null) ?? $this->now;
            $messageId = $this->message($target, $staffId === null ? $clientId : null, $staffId, (string) ($row['message'] ?? ''), $date);
            $this->files('t', (int) $source, (string) ($row['attachment'] ?? ''), self::on($row['attachments_removed'] ?? ''), $target, $messageId, $clientId, $staffId, $date);
        }
    }

    private function message(int $ticketId, ?int $clientId, ?int $staffId, string $content, string $date): int
    {
        $this->db->insert('support_ticket_message', [
            'support_ticket_id' => $ticketId,
            'client_id' => $clientId,
            'admin_id' => $staffId,
            'content' => Text::breaks($content),
            'created_at' => $date,
            'updated_at' => $date,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** Who of the client's logins wrote a message, when it was an authorized user (support_ticket_author). */
    private function author(int $messageId, int $ticketId, ?int $clientId, int $requestorId, string $date): void
    {
        $userId = $clientId !== null && $requestorId > 0 ? $this->map->get('user', $requestorId) : null;
        if ($userId === null || $this->db->fetchOne('SELECT id FROM client_user_account WHERE user_id = ? AND client_id = ?', [$userId, $clientId]) === false) {
            return;
        }
        $user = $this->db->fetchAssociative('SELECT first_name, last_name, email FROM client_user WHERE id = ?', [$userId]);
        $name = $user === false ? '' : trim($user['first_name'] . ' ' . $user['last_name']);
        $this->db->insert('support_ticket_author', [
            'message_id' => $messageId, 'ticket_id' => $ticketId, 'client_id' => $clientId, 'user_id' => $userId,
            'name' => self::cut($name !== '' ? $name : ($user === false ? '' : $user['email']), 201), 'created_at' => $date,
        ]);
    }

    /**
     * A message's attachments: copied from WHMCS's attachments folder, or shown as removed.
     */
    private function files(string $kind, int $sourceId, string $attachment, bool $removed, int $ticketId, int $messageId, ?int $clientId, ?int $staffId, string $date): void
    {
        $names = self::fileNames($attachment);
        if ($names === []) {
            return;
        }
        $what = sprintf('Ticket #%d', $ticketId);
        if ($clientId === null) {
            $this->warn(sprintf('%s (a guest\'s) has %d attachment(s) in WHMCS; guest tickets keep no files here, so they were left out.', $what, count($names)));

            return;
        }
        foreach ($names as $index => $stored) {
            $key = $kind . ':' . $sourceId . ':' . $index;
            if ($this->map->has('file', $key)) {
                continue;
            }
            $name = TicketFiles::cleanName(preg_match('/^\d+_(.+)$/', $stored, $m) === 1 ? $m[1] : $stored);
            $path = $this->attachments === '' ? '' : $this->attachments . '/' . basename($stored);
            $content = !$removed && $path !== '' && is_file($path) && is_readable($path) ? file_get_contents($path) : false;
            $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
            $mime = $content === false ? '' : (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
            $token = bin2hex(random_bytes(16));
            $row = [
                'token' => $token, 'ticket_id' => $ticketId, 'message_id' => $messageId, 'client_id' => $clientId,
                'uploader_kind' => $staffId === null ? 'whmcs' : 'whmcsstaff', 'uploader_id' => $staffId ?? $clientId, 'session_hash' => '',
                'name' => self::cut($name, 191), 'mime' => self::cut($mime ?: 'application/octet-stream', 100), 'size' => $content === false ? 0 : strlen($content),
                'created_at' => $date, 'attached_at' => $date,
            ];
            if ($content !== false && $content !== '' && !TicketFiles::typeFits($extension, $mime)) {
                $this->map->put('file', $key, 0);
                $this->warn(sprintf('%s: attachment %s (%s) is a type ClientFence does not keep; it was left out.', $what, $name, $mime));

                continue;
            }
            if ($content === false || $content === '') {
                $row += ['removed_at' => $date, 'removed_reason' => 'expired'];
                if (!$removed) {
                    $this->warn(sprintf('%s: attachment %s was %s; the message shows it as removed.', $what, $name, $this->attachments === '' ? 'not copied (no WHMCS attachments folder set)' : 'not found in the WHMCS attachments folder'));
                }
            } else {
                $folder = $this->files . '/' . $clientId;
                if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
                    throw new \RuntimeException('The ticket files folder cannot be written.');
                }
                if (file_put_contents($folder . '/' . $token, $content) !== strlen($content)) {
                    @unlink($folder . '/' . $token);

                    throw new \RuntimeException('A ticket file could not be written.');
                }
                $this->written[] = $folder . '/' . $token;
                chmod($folder . '/' . $token, 0600);
            }
            $this->db->insert('support_ticket_file', $row);
            $this->map->put('file', $key, (int) $this->db->lastInsertId());
        }
    }

    /** @param array<string, mixed> $row */
    private function note(array $row): int
    {
        $id = (int) $row['id'];
        if ($this->map->has('ticketnote', $id)) {
            return 0;
        }
        $ticketId = $this->map->get('ticket', (int) ($row['ticketid'] ?? 0));
        if ($ticketId === null || $this->db->fetchOne('SELECT id FROM support_ticket WHERE id = ?', [$ticketId]) === false) {
            return 0;
        }
        $date = Accounts::date($row['date'] ?? null) ?? $this->now;
        $this->db->insert('support_ticket_note', [
            'support_ticket_id' => $ticketId,
            'admin_id' => $this->staffNamed((string) ($row['admin'] ?? '')),
            'note' => Text::breaks((string) ($row['message'] ?? '')),
            'created_at' => $date,
            'updated_at' => $date,
        ]);
        $this->map->put('ticketnote', $id, (int) $this->db->lastInsertId());
        if (trim((string) ($row['attachments'] ?? '')) !== '') {
            $this->warn(sprintf('Ticket #%d: a staff note had attachments in WHMCS; notes keep no files here.', $ticketId));
        }

        return 1;
    }

    /** @param array<string, mixed> $row */
    private function feedback(array $row): int
    {
        $id = (int) $row['id'];
        if ($this->map->has('feedback', $id)) {
            return 0;
        }
        $ticketId = $this->map->get('ticket', (int) ($row['ticketid'] ?? 0));
        if ($ticketId === null || $this->db->fetchOne('SELECT id FROM support_ticket WHERE id = ?', [$ticketId]) === false) {
            return 0;
        }
        $staffId = (int) ($row['adminid'] ?? 0) > 0 ? $this->map->get('staff', (int) $row['adminid']) : null;
        $rating = (int) ($row['rating'] ?? 0);
        $date = Accounts::date($row['datetime'] ?? null) ?? $this->now;
        $this->db->insert(TicketFeedback::FEEDBACK, [
            'ticket_id' => $ticketId,
            'admin_id' => $staffId,
            'rating' => $staffId !== null && $rating >= 1 && $rating <= TicketFeedback::MAX_SCORE ? $rating : null,
            'comments' => mb_substr(trim((string) ($row['comments'] ?? '')), 0, TicketFeedback::MAX_COMMENT),
            'ip' => self::cut($row['ip'] ?? '', 45),
            'created_at' => $date,
        ]);
        $this->map->put('feedback', $id, (int) $this->db->lastInsertId());
        if ($this->db->fetchOne('SELECT ticket_id FROM ' . TicketFeedback::REQUESTS . ' WHERE ticket_id = ?', [$ticketId]) === false) {
            // Answered: the ticket asks for feedback no more, as in WHMCS.
            $this->db->insert(TicketFeedback::REQUESTS, ['ticket_id' => $ticketId, 'token' => bin2hex(random_bytes(32)), 'sent_at' => $date, 'answered_at' => $date]);
        }

        return 1;
    }

    /** @param array<string, mixed> $row */
    private function kbCategory(array $row): int
    {
        $id = (int) $row['id'];
        if ($this->map->has('kbcategory', $id) || $this->map->has('skipped', 'kbcategory:' . $id)) {
            return 0;
        }
        if ((int) ($row['catid'] ?? 0) > 0 || trim((string) ($row['language'] ?? '')) !== '') {
            $this->map->put('skipped', 'kbcategory:' . $id, 0);
            $this->warn(sprintf('Knowledge base category "%s" is a WHMCS translation; translations were not imported.', $row['name'] ?? $id));

            return 0;
        }
        $names = [trim((string) ($row['name'] ?? ''))];
        $seen = [$id => true];
        for ($parent = (int) ($row['parentid'] ?? 0); $parent > 0 && !isset($seen[$parent]); ) {
            $seen[$parent] = true;
            $up = $this->source->rows('SELECT parentid, name FROM tblknowledgebasecats WHERE id = ?', [$parent])[0] ?? null;
            if ($up === null) {
                break;
            }
            array_unshift($names, trim((string) $up['name']));
            $parent = (int) $up['parentid'];
        }
        $title = self::cut(implode(' / ', array_filter($names)), 100) ?: 'Category ' . $id;
        $this->db->insert('support_kb_article_category', [
            'title' => $title,
            'description' => trim((string) ($row['description'] ?? '')) ?: null,
            'slug' => $this->slug('support_kb_article_category', $title, 'category'),
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);
        $categoryId = (int) $this->db->lastInsertId();
        $this->map->put('kbcategory', $id, $categoryId);
        if (self::on($row['hidden'] ?? '')) {
            $this->db->insert(HiddenKbCategories::TABLE, ['category_id' => $categoryId, 'hidden_at' => $this->now]);
        }

        return 1;
    }

    /** @param array<string, mixed> $row */
    private function kbArticle(array $row): int
    {
        $id = (int) $row['id'];
        if ($this->map->has('kbarticle', $id) || $this->map->has('skipped', 'kbarticle:' . $id)) {
            return 0;
        }
        $title = self::cut($row['title'] ?? '', 100) ?: 'Article ' . $id;
        if ((int) ($row['parentid'] ?? 0) > 0 || trim((string) ($row['language'] ?? '')) !== '') {
            $this->map->put('skipped', 'kbarticle:' . $id, 0);
            $this->warn(sprintf('Knowledge base article "%s" is a WHMCS translation; translations were not imported.', $title));

            return 0;
        }
        $categoryId = null;
        $links = $this->source->hasTable('tblknowledgebaselinks') ? $this->source->rows('SELECT categoryid FROM tblknowledgebaselinks WHERE articleid = ? ORDER BY id', [$id]) : [];
        foreach ($links as $link) {
            $mapped = $this->map->get('kbcategory', (int) $link['categoryid']);
            if ($mapped !== null) {
                $categoryId = $mapped;

                break;
            }
        }
        if (count($links) > 1) {
            $this->warn(sprintf('Knowledge base article "%s" was in %d WHMCS categories; it is in the first.', $title, count($links)));
        }
        if ($categoryId === null) {
            $categoryId = $this->map->get('kbcategory', 'none');
            if ($categoryId === null) {
                $this->db->insert('support_kb_article_category', ['title' => 'Other articles', 'description' => null, 'slug' => $this->slug('support_kb_article_category', 'Other articles', 'category'), 'created_at' => $this->now, 'updated_at' => $this->now]);
                $categoryId = (int) $this->db->lastInsertId();
                $this->map->put('kbcategory', 'none', $categoryId);
            }
        }
        // ClientFence has no articles for signed-in clients only: such an article waits as a draft.
        $draft = self::on($row['private'] ?? '');
        if ($draft) {
            $this->warn(sprintf('Knowledge base article "%s" was for signed-in clients only in WHMCS; it is a draft here (publish it if every visitor may read it).', $title));
        }
        $this->db->insert('support_kb_article', [
            'kb_article_category_id' => $categoryId,
            'views' => max(0, (int) ($row['views'] ?? 0)),
            'title' => $title,
            'content' => Text::markdown((string) ($row['article'] ?? '')),
            'slug' => $this->slug('support_kb_article', $title, 'article'),
            'status' => $draft ? 'draft' : 'active',
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);
        $this->map->put('kbarticle', $id, (int) $this->db->lastInsertId());

        return 1;
    }

    /** @param array<string, mixed> $row */
    private function announcement(array $row): int
    {
        $id = (int) $row['id'];
        if ($this->map->has('announcement', $id) || $this->map->has('skipped', 'announcement:' . $id)) {
            return 0;
        }
        $title = self::cut($row['title'] ?? '', 255) ?: 'Announcement ' . $id;
        if ((int) ($row['parentid'] ?? 0) > 0 || trim((string) ($row['language'] ?? '')) !== '') {
            $this->map->put('skipped', 'announcement:' . $id, 0);
            $this->warn(sprintf('Announcement "%s" is a WHMCS translation; translations were not imported.', $title));

            return 0;
        }
        $date = Accounts::date($row['date'] ?? null) ?? $this->now;
        $content = (string) ($row['announcement'] ?? '');
        $published = self::on($row['published'] ?? '');
        $summary = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $this->db->insert('post', [
            'admin_id' => null,
            'title' => $title,
            'description' => mb_strlen($summary) > 250 ? rtrim(mb_substr($summary, 0, 247)) . '...' : $summary,
            'content' => Text::markdown($content),
            'slug' => $this->slug('post', $title, 'announcement'),
            'status' => $published ? 'active' : 'draft',
            'publish_at' => $date,
            'published_at' => $published ? $date : null,
            'created_at' => Accounts::date($row['created_at'] ?? null) ?? $date,
            'updated_at' => $this->now,
        ]);
        $this->map->put('announcement', $id, (int) $this->db->lastInsertId());

        return 1;
    }

    /** @param array<string, mixed> $row */
    private function networkIssue(array $row): int
    {
        $id = (int) $row['id'];
        if ($this->map->has('networkissue', $id)) {
            return 0;
        }
        $type = strtolower(trim((string) ($row['type'] ?? '')));
        $priority = strtolower(trim((string) ($row['priority'] ?? '')));
        $start = Accounts::date($row['startdate'] ?? null) ?? $this->now;
        $this->db->insert('support_network_issue', [
            'title' => self::cut($row['title'] ?? '', 255) ?: 'Network issue ' . $id,
            'description' => (string) ($row['description'] ?? ''),
            'type' => in_array($type, ['server', 'system', 'other'], true) ? $type : 'other',
            'affecting' => self::cut($row['affecting'] ?? '', 100),
            'server_id' => (int) ($row['server'] ?? 0) > 0 ? $this->map->get('server', (int) $row['server']) : null,
            'priority' => in_array($priority, ['critical', 'high', 'medium', 'low'], true) ? $priority : 'medium',
            'status' => self::ISSUE_STATUSES[strtolower(trim((string) ($row['status'] ?? '')))] ?? 'reported',
            'start_at' => $start,
            'end_at' => Accounts::date($row['enddate'] ?? null),
            'emailed_count' => 0,
            'created_at' => Accounts::date($row['created_at'] ?? null) ?? $start,
            'updated_at' => Accounts::date($row['lastupdate'] ?? null) ?? $this->now,
        ]);
        $this->map->put('networkissue', $id, (int) $this->db->lastInsertId());

        return 1;
    }

    /** @param array<string, mixed> $row */
    private function email(array $row): int
    {
        $id = (int) $row['id'];
        if ($this->map->has('email', $id) || $this->map->has('skipped', 'email:' . $id)) {
            return 0;
        }
        $clientId = $this->map->get('client', (int) ($row['userid'] ?? 0));
        if ($clientId === null || (int) ($row['pending'] ?? 0) === 1 || (int) ($row['failed'] ?? 0) === 1) {
            $this->map->put('skipped', 'email:' . $id, 0);
            if ($clientId !== null && !$this->map->has('notice', 'unsent-emails')) {
                $this->map->put('notice', 'unsent-emails', 0);
                $this->warn('Emails WHMCS had not sent (pending or failed) were left out of the email history.');
            }

            return 0;
        }
        if (trim((string) ($row['attachments'] ?? '')) !== '' && trim((string) $row['attachments']) !== '[]' && !$this->map->has('notice', 'email-attachments')) {
            $this->map->put('notice', 'email-attachments', 0);
            $this->warn('WHMCS keeps only the names of email attachments (such as invoice PDFs); the email history shows the emails without them.');
        }
        // Shown as HTML on the staff email page: only safe markup comes over.
        $html = BrowserHtmlSanitizer::sanitizeAdapterHtml((string) ($row['message'] ?? ''));
        $recipients = array_filter(array_map('trim', [(string) ($row['to'] ?? ''), (string) ($row['cc'] ?? ''), (string) ($row['bcc'] ?? '')]));
        $date = Accounts::date($row['date'] ?? null) ?? $this->now;
        $this->db->insert('activity_client_email', [
            'client_id' => $clientId,
            'sender' => null,
            'recipients' => implode(', ', $recipients),
            'subject' => self::cut($row['subject'] ?? '', 255),
            'content_html' => self::fits($html),
            'content_text' => self::fits(trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'))),
            'created_at' => $date,
            'updated_at' => $date,
        ]);
        $this->map->put('email', $id, (int) $this->db->lastInsertId());

        return 1;
    }

    /**
     * New tickets after the import never take a WHMCS ticket's id, merged ones included (their id
     * opens the ticket they were merged into).
     */
    private function ticketIds(): void
    {
        $highest = (int) ($this->source->value('SELECT MAX(id) FROM tbltickets') ?? 0);
        if ($highest <= (int) $this->db->fetchOne('SELECT COALESCE(MAX(id), 0) FROM support_ticket')) {
            return;
        }
        // A row at the highest WHMCS id, removed at once, moves the next id past it. Unlike ALTER TABLE
        // it stays inside the batch's transaction (MySQL commits on DDL).
        $this->db->insert('support_ticket', ['id' => $highest, 'subject' => '', 'status' => 'closed', 'created_at' => $this->now, 'updated_at' => $this->now]);
        $this->db->delete('support_ticket', ['id' => $highest]);
    }

    /**
     * A ticket's related service: WHMCS's "S<id>" (service) or "D<id>" (domain).
     *
     * @return array{0: ?string, 1: ?int}
     */
    private function related(?int $clientId, string $service): array
    {
        if ($clientId === null || preg_match('/^([SD])(\d+)$/', trim($service), $m) !== 1) {
            return [null, null];
        }
        $orderId = $this->map->get($m[1] === 'S' ? 'service' : 'domain', (int) $m[2]);
        if ($orderId === null || $this->db->fetchOne('SELECT id FROM client_order WHERE id = ? AND client_id = ?', [$orderId, $clientId]) === false) {
            return [null, null];
        }

        return ['order', $orderId];
    }

    private function status(string $whmcs): string
    {
        $key = strtolower(trim($whmcs));
        if ($this->statuses === null) {
            $this->statuses = [];
            if ($this->source->hasTable('tblticketstatuses')) {
                foreach ($this->source->rows('SELECT title, showactive, showawaiting FROM tblticketstatuses') as $status) {
                    $this->statuses[strtolower(trim((string) $status['title']))] = $status;
                }
            }
        }

        return match (true) {
            $key === 'closed' => 'closed',
            $key === 'answered', $key === 'on hold' => 'on_hold',
            in_array($key, ['open', 'customer-reply', 'in progress'], true) => 'open',
            isset($this->statuses[$key]) && (int) $this->statuses[$key]['showactive'] === 0 => 'closed',
            isset($this->statuses[$key]) && (int) $this->statuses[$key]['showawaiting'] === 0 => 'on_hold',
            default => 'open',
        };
    }

    private function setting(string $name): ?string
    {
        $value = $this->source->value('SELECT value FROM tblconfiguration WHERE setting = ?', [$name]);

        return $value === null || $value === false ? null : (string) $value;
    }

    private function slug(string $table, string $text, string $fallback): string
    {
        $base = trim((string) preg_replace('/-+/', '-', (string) preg_replace('/[^a-z0-9-]/', '-', strtolower(trim($text)))), '-');
        $base = substr($base === '' ? $fallback : $base, 0, 240);
        $slug = $base;
        for ($n = 2; $this->db->fetchOne('SELECT id FROM ' . $table . ' WHERE slug = ?', [$slug]) !== false; ++$n) {
            $slug = $base . '-' . $n;
        }

        return $slug;
    }

    /**
     * The stored names of a WHMCS attachment field ("123456_name.png|654321_other.pdf").
     *
     * @return list<string>
     */
    public static function fileNames(string $attachment): array
    {
        return array_values(array_filter(array_map('trim', explode('|', $attachment)), static fn (string $n): bool => $n !== '' && $n !== '.' && $n !== '..'));
    }

    /** An address that receives nothing, for staff WHMCS kept without one. */
    private static function placeholderEmail(string $local): string
    {
        return 'whmcs-' . $local . '@staff.invalid';
    }

    private static function on(mixed $value): bool
    {
        $value = strtolower(trim((string) $value));

        return $value === 'on' || $value === '1' || $value === 'yes';
    }

    private function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    /**
     * Text for a TEXT column (65,535 bytes): converted text can grow past what WHMCS held (line breaks,
     * Markdown), and strict MySQL refuses longer values.
     */
    private static function fits(string $text): string
    {
        return mb_strcut($text, 0, 65535);
    }

    private static function cut(mixed $value, int $max): string
    {
        return mb_substr(trim(is_scalar($value) ? (string) $value : ''), 0, $max);
    }
}
