<?php

declare(strict_types=1);

/**
 * ClientFence: the bell in the admin header and in the client area. A notification is one row per
 * recipient (a staff member, or a client account) in mod_clientfence_notification: its kind, the
 * values its text needs and the page it links to. The text is made when it is shown (text()), in
 * the reader's language. Unread ones are counted on the bell; read or not, they are kept
 * KEEP_DAYS days (prune(), daily from the cron). A dismissed one stays until then too, hidden (kind
 * DISMISSED), so its dedupe key keeps the same notice from coming back.
 *
 * Staff get the kinds of their work (orders, payments, tickets, failures…), each only when their
 * permissions let them open the page it names; clients get their invoices, tickets and services.
 * Emails stay as they are: the bell adds to them. A failure to notify never stops the action that
 * caused it (it is logged).
 *
 * The table is made on the first notification on an install that predates it.
 */

namespace ClientFence\Mod\Clientfence;

use Doctrine\DBAL\Connection;

final class Notifications
{
    public const TABLE = 'mod_clientfence_notification';
    public const KEEP_DAYS = 90;
    public const LIST_SIZE = 20;
    public const STAFF = 'staff';
    public const CLIENT = 'client';
    public const DISMISSED = 'dismissed';
    // Days ahead that a renewal or a domain's expiry is announced.
    public const RENEW_DAYS = 7;
    public const DOMAIN_DAYS = 30;

    /**
     * Sends a notification to the active staff members who may open `$module` (and, when given,
     * only to `$only`: staff ids). `$url` is relative to the admin area. `$dedupe`: a key that
     * makes a second notification of the same thing to the same person a no-op.
     */
    public static function staff(\Pimple\Container $di, string $kind, array $params, string $url, string $module, ?array $only = null, ?string $dedupe = null): void
    {
        try {
            $ids = self::staffWith($di, $module, $only);
            foreach ($ids as $id) {
                self::add($di, self::STAFF, $id, $kind, $params, $url, $dedupe);
            }
        } catch (\Throwable $e) {
            $di['logger']->warning('Staff notification %s not saved: %s', $kind, $e->getMessage());
        }
    }

    /** Sends a notification to a client account. `$url` is relative to the client area. */
    public static function client(\Pimple\Container $di, int $clientId, string $kind, array $params, string $url, ?string $dedupe = null): void
    {
        if ($clientId <= 0) {
            return;
        }
        try {
            self::add($di, self::CLIENT, $clientId, $kind, $params, $url, $dedupe);
        } catch (\Throwable $e) {
            $di['logger']->warning('Client notification %s not saved: %s', $kind, $e->getMessage());
        }
    }

    /** How many unread notifications a recipient has (0 before the table exists). */
    public static function unread(\Pimple\Container $di, string $audience, int $recipientId): int
    {
        try {
            return (int) self::db($di)->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE audience = ? AND recipient_id = ? AND read_at IS NULL', [$audience, $recipientId]);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return list<array{id: int, url: string}> */
    public static function unreadRows(\Pimple\Container $di, string $audience, int $recipientId): array
    {
        try {
            $rows = self::db($di)->fetchAllAssociative('SELECT id, url FROM ' . self::TABLE . ' WHERE audience = ? AND recipient_id = ? AND read_at IS NULL AND kind <> ?', [$audience, $recipientId, self::DISMISSED]);
        } catch (\Throwable) {
            return [];
        }

        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'url' => (string) $row['url']], $rows);
    }

    /**
     * The newest notifications of a recipient, with their text: {id, kind, text, url, created_at,
     * age (seconds, so the browser needs no time zone), read}. `$base` turns the stored relative
     * address into a link.
     *
     * @return list<array{id: int, kind: string, text: string, url: string, created_at: string, age: int, read: bool}>
     */
    public static function latest(\Pimple\Container $di, string $audience, int $recipientId, callable $base, int $limit = self::LIST_SIZE): array
    {
        try {
            $rows = self::db($di)->fetchAllAssociative('SELECT id, kind, params, url, created_at, read_at FROM ' . self::TABLE . ' WHERE audience = ? AND recipient_id = ? AND kind <> ? ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)), [$audience, $recipientId, self::DISMISSED]);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $params = json_decode((string) $row['params'], true);
            $out[] = [
                'id' => (int) $row['id'],
                'kind' => (string) $row['kind'],
                'text' => self::text((string) $row['kind'], is_array($params) ? $params : []),
                'url' => $base((string) $row['url']),
                'created_at' => (string) $row['created_at'],
                'age' => max(0, time() - (int) strtotime((string) $row['created_at'])),
                'read' => $row['read_at'] !== null,
            ];
        }

        return $out;
    }

    /** Marks one notification of the recipient (`$id`) or all of them (null) as read. */
    public static function markRead(\Pimple\Container $di, string $audience, int $recipientId, ?int $id = null): int
    {
        $sql = 'UPDATE ' . self::TABLE . ' SET read_at = ? WHERE audience = ? AND recipient_id = ? AND read_at IS NULL';
        $args = [date('Y-m-d H:i:s'), $audience, $recipientId];
        if ($id !== null) {
            $sql .= ' AND id = ?';
            $args[] = $id;
        }
        try {
            return (int) self::db($di)->executeStatement($sql, $args);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** The stored (relative) address of one notification of the recipient, or null when it is not theirs. */
    public static function url(\Pimple\Container $di, string $audience, int $recipientId, int $id): ?string
    {
        try {
            $url = self::db($di)->fetchOne('SELECT url FROM ' . self::TABLE . ' WHERE audience = ? AND recipient_id = ? AND id = ?', [$audience, $recipientId, $id]);
        } catch (\Throwable) {
            return null;
        }

        return $url === false ? null : (string) $url;
    }

    /** Hides one notification of the recipient from the bell for good (it counts as read). */
    public static function dismiss(\Pimple\Container $di, string $audience, int $recipientId, int $id): int
    {
        try {
            return (int) self::db($di)->executeStatement('UPDATE ' . self::TABLE . ' SET kind = ?, read_at = COALESCE(read_at, ?) WHERE audience = ? AND recipient_id = ? AND id = ?', [self::DISMISSED, date('Y-m-d H:i:s'), $audience, $recipientId, $id]);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Deletes notifications older than KEEP_DAYS days. */
    public static function prune(\Pimple\Container $di): int
    {
        try {
            return (int) self::db($di)->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE created_at < ?', [date('Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400)]);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** The text of a notification in the current language; a kind no longer known reads generic. */
    public static function text(string $kind, array $p): string
    {
        $v = [];
        foreach ($p as $key => $value) {
            $v[':' . $key] = is_scalar($value) ? (string) $value : '';
        }

        return match ($kind) {
            // Staff: daily work.
            'order_new' => __trans('New order #:id: :title', $v),
            'payment_received' => __trans('Invoice :number paid: :amount', $v),
            'ticket_new' => __trans('New ticket #:id: :subject', $v),
            'ticket_reply' => __trans('Client replied to ticket #:id: :subject', $v),
            'ticket_assigned' => __trans('Ticket #:id was assigned to you: :subject', $v),
            'ticket_escalated' => __trans('Ticket #:id needs attention: :rule', $v),
            'client_signup' => __trans('New client: :name', $v),
            'quote_accepted' => __trans('Quote :number was accepted', $v),
            'rating_low' => __trans(':stars-star rating of your reply on ticket #:id', $v),
            'feedback_low' => __trans('Low feedback score on ticket #:id', $v),
            'announcement' => __trans('Announcement: :title', $v),
            // Staff: problems.
            'action_failed' => __trans('An action failed on order #:id: :title', $v),
            'payment_failed' => __trans('A payment could not be processed (transaction #:id)', $v),
            'email_failed' => __trans('Emails could not be sent: check the email settings', $v),
            'mail_import_failed' => __trans('Email import cannot read the mailbox of :helpdesk', $v),
            'backup_failed' => __trans('The database backup failed', $v),
            'health_bad' => __trans('System health: a check needs attention', $v),
            'domain_expiring' => __trans('Domain :domain expires on :date', $v),
            // Clients.
            'invoice_new' => __trans('New invoice :number: :amount', $v),
            'invoice_due' => __trans('Invoice :number is due on :date', $v),
            'invoice_overdue' => __trans('Invoice :number is overdue', $v),
            'invoice_paid' => __trans('Payment received for invoice :number', $v),
            'ticket_answered' => __trans('New reply on ticket #:id: :subject', $v),
            'order_active' => __trans(':title is active', $v),
            'order_suspended' => __trans(':title is suspended', $v),
            'order_unsuspended' => __trans(':title is active again', $v),
            'order_canceled' => __trans(':title is canceled', $v),
            'order_renews' => __trans(':title renews on :date', $v),
            'domain_expires' => __trans('Domain :domain expires on :date', $v),
            'quote_sent' => __trans('New quote :number', $v),
            'network_issue' => __trans('Network issue: :title', $v),
            'upgrade_done' => __trans(':title was changed', $v),
            'credit_added' => __trans(':amount was added to your account credit', $v),
            default => __trans('Notification'),
        };
    }

    // ----- events (Service::on*() hand them over) ----------------------------------------------

    /** A client placed an order. */
    public static function orderCreated(\Pimple\Container $di, int $orderId): void
    {
        $order = self::order($di, $orderId);
        if ($order !== null) {
            self::staff($di, 'order_new', ['id' => $orderId, 'title' => $order['title']], 'order/manage/' . $orderId, 'order');
        }
    }

    /** An order changed for its client: activated, suspended, unsuspended or canceled. */
    public static function orderChanged(\Pimple\Container $di, int $orderId, string $kind): void
    {
        $order = self::order($di, $orderId);
        if ($order !== null) {
            self::client($di, (int) $order['client_id'], $kind, ['title' => $order['title']], 'order/service/manage/' . $orderId);
        }
    }

    /** An order was upgraded or downgraded (product, period or options). */
    public static function orderUpgraded(\Pimple\Container $di, int $orderId): void
    {
        self::orderChanged($di, $orderId, 'upgrade_done');
    }

    /** An invoice became payable (approved): its client is told. */
    public static function invoiceIssued(\Pimple\Container $di, int $invoiceId): void
    {
        $invoice = self::invoice($di, $invoiceId);
        if ($invoice !== null && $invoice['total'] > 0) {
            self::client($di, $invoice['client_id'], 'invoice_new', ['number' => $invoice['number'], 'amount' => $invoice['amount']], 'invoice/' . $invoice['hash'], 'invoice_new:' . $invoiceId);
        }
    }

    /** A payment reminder went out: due soon, or overdue once the due date passed. */
    public static function invoiceReminded(\Pimple\Container $di, int $invoiceId): void
    {
        $invoice = self::invoice($di, $invoiceId);
        if ($invoice === null || $invoice['status'] !== 'unpaid') {
            return;
        }
        $overdue = $invoice['due_at'] !== '' && strtotime($invoice['due_at']) < time();
        $kind = $overdue ? 'invoice_overdue' : 'invoice_due';
        self::client($di, $invoice['client_id'], $kind, ['number' => $invoice['number'], 'date' => self::date($invoice['due_at'])], 'invoice/' . $invoice['hash'], $kind . ':' . $invoiceId);
    }

    /** An invoice was paid: its client and the staff who see invoices are told. */
    public static function invoicePaid(\Pimple\Container $di, int $invoiceId): void
    {
        $invoice = self::invoice($di, $invoiceId);
        if ($invoice === null || $invoice['total'] <= 0) {
            return;
        }
        self::client($di, $invoice['client_id'], 'invoice_paid', ['number' => $invoice['number']], 'invoice/' . $invoice['hash'], 'invoice_paid:' . $invoiceId);
        self::staff($di, 'payment_received', ['number' => $invoice['number'], 'amount' => $invoice['amount']], 'invoice/manage/' . $invoiceId, 'invoice', null, 'invoice_paid:' . $invoiceId);
    }

    /**
     * A client opened or answered a ticket: its assignee is told, or every staff member who sees
     * tickets when nobody has it.
     */
    public static function ticketFromClient(\Pimple\Container $di, int $ticketId, bool $new): void
    {
        $ticket = self::db($di)->fetchAssociative('SELECT id, subject FROM support_ticket WHERE id = ?', [$ticketId]);
        if ($ticket === false) {
            return;
        }
        $assignee = \ClientFence\Mod\Support\TicketAssign::assignee($di, $ticketId);
        self::staff($di, $new ? 'ticket_new' : 'ticket_reply', ['id' => $ticketId, 'subject' => (string) $ticket['subject']], 'support/ticket/' . $ticketId, 'support', $assignee === null ? null : [$assignee]);
    }

    /** Staff answered a ticket: its client is told. */
    public static function ticketFromStaff(\Pimple\Container $di, int $ticketId): void
    {
        $ticket = self::db($di)->fetchAssociative('SELECT id, subject, client_id FROM support_ticket WHERE id = ?', [$ticketId]);
        if ($ticket !== false && $ticket['client_id'] !== null) {
            self::client($di, (int) $ticket['client_id'], 'ticket_answered', ['id' => $ticketId, 'subject' => (string) $ticket['subject']], 'support/ticket/' . $ticketId);
        }
    }

    /** A ticket was given to a staff member (by someone else, or by an escalation rule). */
    public static function ticketAssigned(\Pimple\Container $di, int $ticketId, int $adminId): void
    {
        $subject = (string) self::db($di)->fetchOne('SELECT subject FROM support_ticket WHERE id = ?', [$ticketId]);
        self::staff($di, 'ticket_assigned', ['id' => $ticketId, 'subject' => $subject], 'support/ticket/' . $ticketId, 'support', [$adminId]);
    }

    /** A client signed up. */
    public static function clientSignedUp(\Pimple\Container $di, int $clientId): void
    {
        $row = self::db($di)->fetchAssociative('SELECT first_name, last_name, company FROM client WHERE id = ?', [$clientId]);
        if ($row !== false) {
            $name = trim($row['first_name'] . ' ' . $row['last_name']) ?: (string) $row['company'];
            self::staff($di, 'client_signup', ['name' => $name], 'client/manage/' . $clientId, 'client');
        }
    }

    // ----- daily (from the cron) ------------------------------------------------------------

    /**
     * Once a day: renewals and domain expiry coming up (services within RENEW_DAYS days, domains
     * within DOMAIN_DAYS), each once per expiry date; old notifications are deleted.
     */
    public static function daily(\Pimple\Container $di): void
    {
        $db = self::db($di);
        $today = date('Y-m-d');
        if ($db->fetchOne('SELECT value FROM mod_clientfence_state WHERE name = ?', ['notifications_daily']) === $today) {
            return;
        }
        $db->executeStatement('INSERT INTO mod_clientfence_state (name, value, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)', ['notifications_daily', $today, date('Y-m-d H:i:s')]);
        self::prune($di);

        $rows = $db->fetchAllAssociative(
            "SELECT id, client_id, title, expires_at FROM client_order
              WHERE status = 'active' AND service_type <> 'domain' AND expires_at IS NOT NULL AND expires_at BETWEEN ? AND ?",
            [date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() + self::RENEW_DAYS * 86400)]
        );
        foreach ($rows as $row) {
            self::client($di, (int) $row['client_id'], 'order_renews', ['title' => (string) $row['title'], 'date' => self::date((string) $row['expires_at'])], 'order/service/manage/' . $row['id'], 'renews:' . $row['id'] . ':' . substr((string) $row['expires_at'], 0, 10));
        }

        $domains = $db->fetchAllAssociative(
            "SELECT o.id, o.client_id, d.sld, d.tld, d.expires_at FROM client_order o JOIN service_domain d ON d.id = o.service_id
              WHERE o.service_type = 'domain' AND o.status = 'active' AND d.expires_at IS NOT NULL AND d.expires_at BETWEEN ? AND ?",
            [date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() + self::DOMAIN_DAYS * 86400)]
        );
        foreach ($domains as $row) {
            $params = ['domain' => $row['sld'] . $row['tld'], 'date' => self::date((string) $row['expires_at'])];
            $key = 'domain:' . $row['id'] . ':' . substr((string) $row['expires_at'], 0, 10);
            self::client($di, (int) $row['client_id'], 'domain_expires', $params, 'order/service/manage/' . $row['id'], $key);
            self::staff($di, 'domain_expiring', $params, 'order/manage/' . $row['id'], 'order', null, $key);
        }
    }

    /** @return array{client_id: int, title: string}|null */
    private static function order(\Pimple\Container $di, int $orderId): ?array
    {
        $row = self::db($di)->fetchAssociative('SELECT client_id, title FROM client_order WHERE id = ?', [$orderId]);

        return $row === false ? null : ['client_id' => (int) $row['client_id'], 'title' => (string) $row['title']];
    }

    /** @return array{client_id: int, number: string, hash: string, status: string, due_at: string, total: float, amount: string}|null */
    private static function invoice(\Pimple\Container $di, int $invoiceId): ?array
    {
        try {
            $model = $di['db']->getExistingModelById('Invoice', $invoiceId);
            $api = $di['mod_service']('invoice')->toApiArray($model, true, null, true);
        } catch (\Throwable) {
            return null;
        }
        $total = (float) ($api['total'] ?? 0);

        return [
            'client_id' => (int) ($api['client']['id'] ?? $model->client_id ?? 0),
            'number' => (string) ($api['serie_nr'] ?? ('#' . $invoiceId)),
            'hash' => (string) ($api['hash'] ?? ''),
            'status' => (string) ($api['status'] ?? ''),
            'due_at' => (string) ($api['due_at'] ?? ''),
            'total' => $total,
            'amount' => number_format($total, 2) . ' ' . ($api['currency'] ?? ''),
        ];
    }

    private static function date(string $value): string
    {
        $time = strtotime($value);

        return $time === false ? $value : date('Y-m-d', $time);
    }

    /**
     * Active staff (not the cron account) who may open the module: a Super Administrator always,
     * anyone else when the module is theirs to see.
     *
     * @return list<int>
     */
    public static function staffWith(\Pimple\Container $di, string $module, ?array $only = null): array
    {
        $ids = array_map('intval', self::db($di)->fetchFirstColumn("SELECT id FROM admin WHERE status = 'active' AND system_name IS NULL ORDER BY id"));
        if ($only !== null) {
            $ids = array_values(array_intersect($ids, array_map('intval', $only)));
        }
        $staff = $di['mod_service']('staff');
        $out = [];
        foreach ($ids as $id) {
            $admin = $di['db']->load('Admin', $id);
            if ($admin instanceof \Model_Admin && $staff->hasPermission($admin, $module)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    private static function add(\Pimple\Container $di, string $audience, int $recipientId, string $kind, array $params, string $url, ?string $dedupe): void
    {
        $row = [
            'audience' => $audience,
            'recipient_id' => $recipientId,
            'kind' => $kind,
            'params' => (string) json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'url' => mb_substr(ltrim($url, '/'), 0, 255),
            'dedupe' => $dedupe === null ? null : mb_substr($dedupe, 0, 120),
            'created_at' => date('Y-m-d H:i:s'),
            'read_at' => null,
        ];
        $db = self::db($di);
        try {
            $db->insert(self::TABLE, $row);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            // Already told about this.
        } catch (\Doctrine\DBAL\Exception\TableNotFoundException $e) {
            // Creating a table inside a transaction would commit it (MySQL): the next one makes it.
            if ($db->isTransactionActive()) {
                throw $e;
            }
            Service::ensureTables($di, true);
            $db->insert(self::TABLE, $row);
        }
    }

    private static function db(\Pimple\Container $di): Connection
    {
        return $di['em']->getConnection();
    }
}
