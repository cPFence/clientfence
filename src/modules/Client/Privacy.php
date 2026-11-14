<?php

declare(strict_types=1);

namespace ClientFence\Mod\Client;

use ClientFence\InformationException;
use Doctrine\DBAL\Connection;

/**
 * ClientFence: privacy tools (release plan R10). Both switches are off by default and live on the
 * Privacy tab of System → Settings → Clients (mod_client settings):
 *
 * - privacy_tools: staff export a client's data as a JSON file (permission "export") and
 *   anonymize a client (permission "delete"); the client downloads the same file on the profile
 *   page (/my-data, the account owner only) and asks for the account to be deleted, which opens
 *   a ticket for staff.
 * - marketing_optout: a client turns marketing email (Mass Mailer) off on the profile page, or
 *   with the one-click link every mass email then carries (/unsubscribe/<id>/<token>, also as
 *   List-Unsubscribe headers, RFC 8058). Mass Mailer skips clients who opted out. Service email
 *   (invoices, tickets, sign-in) is not marketing and always goes.
 *
 * Anonymizing keeps the money records (orders, invoices, payments, balance, quotes, affiliate ledger) and erases
 * the person: names, email, address, phone, IP addresses and notes become placeholders or empty,
 * ticket messages, sent emails and sign-in history are removed, sign-in stops. The business
 * identifiers on invoices (company, VAT and company number, country) stay for tax records. It is
 * refused while the client has a live service, an unpaid invoice or an affiliate payout request waiting.
 */
final class Privacy
{
    public const OPTOUT_TABLE = 'client_marketing_optout';
    public const UNSUBSCRIBE_TABLE = 'email_queue_unsubscribe';
    public const ANONYMIZED_DOMAIN = 'anonymized.invalid';
    public const DELETION_SUBJECT = 'Account deletion request';

    /** Orders in these states are live services: anonymizing waits until they are canceled. */
    private const LIVE_ORDERS = ['active', 'pending_setup', 'failed_setup', 'failed_renew', 'suspended'];

    private const REMOVED = '[Removed: the client was anonymized.]';

    public static function toolsOn(\Pimple\Container $di): bool
    {
        return self::setting($di, 'privacy_tools');
    }

    public static function optOutOn(\Pimple\Container $di): bool
    {
        return self::setting($di, 'marketing_optout');
    }

    /**
     * Everything kept about the client, without secrets (password hash, API token, invoice and
     * ticket links). Private notes and documents are included only in staff exports.
     *
     * @return array<string, mixed>
     */
    public static function export(\Pimple\Container $di, int $clientId, bool $includeStaffData = false): array
    {
        $db = self::db($di);
        $client = $db->fetchAssociative('SELECT * FROM client WHERE id = ?', [$clientId]);
        if ($client === false) {
            throw new InformationException('Client not found', [], 404);
        }
        // Secrets, and the staff's own fields about the client (notes, group, import and referral marks).
        foreach (['pass', 'salt', 'api_token', 'notes', 'aid', 'referred_by', 'client_group_id', 'tax_exempt', 'role', 'auth_type'] as $column) {
            unset($client[$column]);
        }
        $invoiceIds = array_map(intval(...), $db->fetchFirstColumn('SELECT id FROM invoice WHERE client_id = ?', [$clientId]));
        $ticketIds = array_map(intval(...), $db->fetchFirstColumn('SELECT id FROM support_ticket WHERE client_id = ?', [$clientId]));
        $in = static fn (array $ids): string => $ids === [] ? '0' : implode(',', $ids);

        $tickets = self::rows($db, 'SELECT id, subject, status, priority, created_at, updated_at FROM support_ticket WHERE client_id = ? ORDER BY id', [$clientId]);
        $messages = self::rows($db, 'SELECT id, support_ticket_id, CASE WHEN admin_id IS NULL THEN \'you\' ELSE \'staff\' END AS author, content, created_at FROM support_ticket_message WHERE support_ticket_id IN (' . $in($ticketIds) . ') ORDER BY id');
        foreach ($tickets as $i => $ticket) {
            $tickets[$i]['messages'] = array_values(array_filter($messages, static fn (array $m): bool => (int) $m['support_ticket_id'] === (int) $ticket['id']));
        }

        $data = [
            'exported_at' => date('c'),
            'client' => $client,
            'marketing_email' => self::optedOut($db, $clientId) ? 'off' : 'on',
            'authorized_users' => self::rows($db, 'SELECT u.email, u.first_name, u.last_name, a.permissions, a.owner, a.created_at, a.last_login_at FROM client_user_account a JOIN client_user u ON u.id = a.user_id WHERE a.client_id = ?', [$clientId]),
            'contacts' => self::rows($db, 'SELECT first_name, last_name, company, email, phone_cc, phone, address_1, address_2, city, state, postcode, country, tax_id, emails, created_at FROM ' . Contacts::TABLE . ' WHERE client_id = ? ORDER BY id', [$clientId]),
            'orders' => self::rows($db, 'SELECT id, title, status, period, quantity, price, discount, currency, config, created_at, activated_at, expires_at, suspended_at, canceled_at FROM client_order WHERE client_id = ? ORDER BY id', [$clientId]),
            'domains' => self::rows($db, 'SELECT sld, tld, ns1, ns2, ns3, ns4, period, privacy, contact_email, contact_company, contact_first_name, contact_last_name, contact_address1, contact_address2, contact_city, contact_state, contact_postcode, contact_country, contact_phone_cc, contact_phone, registered_at, expires_at FROM service_domain WHERE client_id = ?', [$clientId]),
            'invoices' => self::rows($db, 'SELECT id, serie, nr, currency, status, buyer_first_name, buyer_last_name, buyer_company, buyer_company_vat, buyer_company_number, buyer_address, buyer_city, buyer_state, buyer_country, buyer_zip, buyer_phone, buyer_phone_cc, buyer_email, taxname, taxrate, due_at, paid_at, created_at FROM invoice WHERE client_id = ? ORDER BY id', [$clientId]),
            'invoice_items' => self::rows($db, 'SELECT invoice_id, title, period, quantity, unit, price, taxed, created_at FROM invoice_item WHERE invoice_id IN (' . $in($invoiceIds) . ') ORDER BY id'),
            'payments' => self::rows($db, 'SELECT invoice_id, txn_id, amount, currency, type, status, created_at FROM `transaction` WHERE invoice_id IN (' . $in($invoiceIds) . ') ORDER BY id'),
            'balance' => self::rows($db, 'SELECT type, amount, description, created_at FROM client_balance WHERE client_id = ? ORDER BY id', [$clientId]),
            'quotes' => self::rows($db, 'SELECT id, first_name, last_name, company, email, country, currency, subject, stage, valid_until, customer_notes, sent_at, accepted_at, declined_at, created_at FROM invoice_quote WHERE client_id = ? ORDER BY id', [$clientId]),
            'tickets' => $tickets,
            'emails_sent' => self::rows($db, 'SELECT sender, recipients, subject, content_text, created_at FROM activity_client_email WHERE client_id = ? ORDER BY id', [$clientId]),
            'sign_ins' => self::rows($db, 'SELECT ip, created_at FROM activity_client_history WHERE client_id = ? ORDER BY id', [$clientId]),
            // The client's own affiliate account (Affiliates/); referred clients are other people.
            'affiliate' => self::rows($db, 'SELECT code, status, commission_type, commission_amount, onetime, created_at FROM client_affiliate WHERE client_id = ?', [$clientId]),
            'affiliate_entries' => self::rows($db, 'SELECT e.type, e.status, e.amount, e.currency, e.note, e.clears_at, e.created_at FROM client_affiliate_entry e JOIN client_affiliate a ON a.id = e.affiliate_id WHERE a.client_id = ? ORDER BY e.id', [$clientId]),
            'affiliate_payouts' => self::rows($db, 'SELECT p.amount, p.currency, p.status, p.method, p.details, p.created_at, p.processed_at FROM client_affiliate_payout p JOIN client_affiliate a ON a.id = p.affiliate_id WHERE a.client_id = ? ORDER BY p.id', [$clientId]),
        ];
        if ($includeStaffData) {
            $data['notes'] = Notes::getList($di, $clientId);
            $data['files'] = Files::getList($di, $clientId);
        }

        return $data;
    }

    /**
     * Erases the person and keeps the money records (see the class comment). $adminId: who did it,
     * for the log.
     */
    public static function anonymize(\Pimple\Container $di, int $clientId, ?int $adminId): void
    {
        $db = self::db($di);
        $client = $db->fetchAssociative('SELECT id, email FROM client WHERE id = ?', [$clientId]);
        if ($client === false) {
            throw new InformationException('Client not found', [], 404);
        }
        if (str_ends_with((string) $client['email'], '@' . self::ANONYMIZED_DOMAIN)) {
            throw new InformationException('This client is anonymized already.');
        }
        $live = (int) $db->fetchOne('SELECT COUNT(*) FROM client_order WHERE client_id = ? AND status IN (?, ?, ?, ?, ?)', [$clientId, ...self::LIVE_ORDERS]);
        if ($live > 0) {
            throw new InformationException('This client has :count live services (active, pending or suspended). Cancel them first.', [':count' => $live]);
        }
        $unpaid = (int) $db->fetchOne('SELECT COUNT(*) FROM invoice WHERE client_id = ? AND status = ?', [$clientId, 'unpaid']);
        if ($unpaid > 0) {
            throw new InformationException('This client has :count unpaid invoices. Settle or cancel them first.', [':count' => $unpaid]);
        }
        if (self::rows($db, "SELECT 1 FROM client_affiliate_payout p JOIN client_affiliate a ON a.id = p.affiliate_id WHERE a.client_id = ? AND p.status = 'requested'", [$clientId]) !== []) {
            throw new InformationException('This client has an affiliate payout request waiting. Pay or decline it first.');
        }

        $oldEmail = strtolower((string) $client['email']);
        $email = 'anonymized-' . $clientId . '@' . self::ANONYMIZED_DOMAIN;
        $now = date('Y-m-d H:i:s');
        $ticketIds = array_map(intval(...), $db->fetchFirstColumn('SELECT id FROM support_ticket WHERE client_id = ?', [$clientId]));
        $tickets = $ticketIds === [] ? '0' : implode(',', $ticketIds);

        $db->transactional(static function (Connection $db) use ($di, $clientId, $email, $oldEmail, $now, $tickets): void {
            $db->delete('client_note', ['client_id' => $clientId]);
            Files::forgetClient($db, $clientId, $di['filesystem']);
            $erase = ['billing_email', 'salt', 'gender', 'birthday', 'phone_cc', 'phone', 'company', 'company_vat', 'company_number', 'address_1', 'address_2', 'city', 'state', 'postcode', 'notes', 'ip', 'api_token', 'referred_by', 'aid', 'auth_type'];
            for ($i = 1; $i <= 20; ++$i) {
                $erase[] = 'custom_' . $i;
            }
            $db->update('client', array_fill_keys($erase, null) + [
                'email' => $email,
                'first_name' => 'Anonymized',
                'last_name' => 'Client #' . $clientId,
                'status' => 'canceled',
                'email_approved' => 0,
                // Nobody can sign in: a hash of a password nobody knows.
                'pass' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                'updated_at' => $now,
            ], ['id' => $clientId]);
            $db->executeStatement(
                'UPDATE invoice SET buyer_first_name = ?, buyer_last_name = ?, buyer_address = NULL, buyer_city = NULL, buyer_state = NULL, buyer_zip = NULL, buyer_phone = NULL, buyer_phone_cc = NULL, buyer_email = ?, notes = NULL WHERE client_id = ?',
                ['Anonymized', 'Client #' . $clientId, $email, $clientId]
            );
            $db->executeStatement('UPDATE `transaction` SET ip = NULL, ipn = NULL, output = NULL WHERE invoice_id IN (SELECT id FROM invoice WHERE client_id = ?)', [$clientId]);
            $db->executeStatement('UPDATE invoice_quote SET first_name = ?, last_name = ?, email = ?, customer_notes = NULL WHERE client_id = ?', ['Anonymized', 'Client #' . $clientId, $email, $clientId]);
            $db->executeStatement('UPDATE client_order SET notes = NULL WHERE client_id = ?', [$clientId]);
            $db->executeStatement('UPDATE service_domain SET contact_email = NULL, contact_company = NULL, contact_first_name = NULL, contact_last_name = NULL, contact_address1 = NULL, contact_address2 = NULL, contact_city = NULL, contact_state = NULL, contact_postcode = NULL, contact_phone_cc = NULL, contact_phone = NULL, transfer_code = NULL WHERE client_id = ?', [$clientId]);
            $db->executeStatement('UPDATE support_ticket SET subject = ?, author_name = NULL, author_email = NULL WHERE id IN (' . $tickets . ')', ['Ticket (removed)']);
            $db->executeStatement('UPDATE support_ticket_message SET content = ?, ip = NULL WHERE support_ticket_id IN (' . $tickets . ')', [self::REMOVED]);
            $db->executeStatement('UPDATE support_ticket_note SET note = ? WHERE support_ticket_id IN (' . $tickets . ')', [self::REMOVED]);
            $db->executeStatement('DELETE FROM support_ticket_secret WHERE ticket_id IN (' . $tickets . ')');
            $db->executeStatement('DELETE FROM support_ticket_secret_event WHERE ticket_id IN (' . $tickets . ')');
            $db->executeStatement('UPDATE activity_system SET ip = NULL WHERE client_id = ?', [$clientId]);
            foreach (['activity_client_history', 'activity_client_email', 'client_password_reset', 'email_queue', 'extension_meta'] as $table) {
                $db->delete($table, ['client_id' => $clientId]);
            }
            // ClientFence's own records of the person (tables a fresh install has; see the schema).
            self::quietly($db, 'UPDATE support_ticket_feedback SET comments = \'\', ip = \'\' WHERE ticket_id IN (' . $tickets . ')');
            self::quietly($db, 'UPDATE support_ticket_message_rating SET ip = \'\' WHERE ticket_id IN (' . $tickets . ')');
            self::quietly($db, 'UPDATE support_ticket_message_unverified SET sender = \'\' WHERE ticket_id IN (' . $tickets . ')');
            self::quietly($db, 'DELETE FROM support_mail_import WHERE ticket_id IN (' . $tickets . ') OR LOWER(from_email) = ?', [$oldEmail]);
            self::quietly($db, 'DELETE FROM client_google_signup WHERE client_id = ?', [$clientId]);
            self::quietly($db, 'DELETE FROM client_google_password WHERE client_id = ?', [$clientId]);
            self::quietly($db, 'REPLACE INTO ' . self::OPTOUT_TABLE . ' (client_id, source, opted_out_at) VALUES (?, ?, ?)', [$clientId, 'anonymized', $now]);
            // The affiliate ledger and payouts stay as money records; their visits and payout details go.
            self::quietly($db, 'DELETE FROM client_affiliate_visit WHERE affiliate_id IN (SELECT id FROM client_affiliate WHERE client_id = ?)', [$clientId]);
            self::quietly($db, 'UPDATE client_affiliate_payout SET details = NULL WHERE affiliate_id IN (SELECT id FROM client_affiliate WHERE client_id = ?)', [$clientId]);
        });
        // Authorized-user links, two-factor and Google sign-in, ticket files (as a deleted client).
        Users::clientDeleted($di, $clientId);
        $di['logger']->setChannel('privacy')->info(sprintf('Client #%d anonymized by staff #%d', $clientId, (int) $adminId));
        Users::activity($di, [$clientId], 'The client was anonymized (privacy)', $adminId);
    }

    /**
     * The client asks for the account to be deleted: a ticket for staff, who anonymize the client
     * (or explain what must happen first). One open request at a time. Returns the ticket id.
     */
    public static function requestDeletion(\Pimple\Container $di, int $clientId): int
    {
        $db = self::db($di);
        $open = $db->fetchOne('SELECT id FROM support_ticket WHERE client_id = ? AND subject = ? AND status <> ? ORDER BY id DESC LIMIT 1', [$clientId, self::DELETION_SUBJECT, 'closed']);
        if ($open !== false) {
            return (int) $open;
        }
        $support = $di['mod_service']('support');
        $helpdesk = $support->getHelpdeskRepository()->findOneBy([], ['id' => 'ASC']);
        if ($helpdesk === null) {
            throw new InformationException('Your request could not be sent: there is no support desk. Please contact us.');
        }
        $client = $di['db']->load('Client', $clientId);

        return $support->ticketCreateForClient($client, $helpdesk, [
            'support_helpdesk_id' => $helpdesk->getId(),
            'subject' => self::DELETION_SUBJECT,
            'content' => \ClientFence\Tools::sanitizeMarkdownContent(__trans('Please delete my account and the personal data you keep about me. (Sent from the Privacy tab of my profile.)')),
        ]);
    }

    public static function optedOut(Connection $db, int $clientId): bool
    {
        try {
            return $db->fetchOne('SELECT 1 FROM ' . self::OPTOUT_TABLE . ' WHERE client_id = ?', [$clientId]) !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /** $source: profile, link (the unsubscribe link) or staff. */
    public static function setOptOut(\Pimple\Container $di, int $clientId, bool $optOut, string $source): void
    {
        $db = self::db($di);
        if ($optOut) {
            $db->executeStatement('REPLACE INTO ' . self::OPTOUT_TABLE . ' (client_id, source, opted_out_at) VALUES (?, ?, ?)', [$clientId, mb_substr($source, 0, 20), date('Y-m-d H:i:s')]);
        } else {
            $db->delete(self::OPTOUT_TABLE, ['client_id' => $clientId]);
        }
    }

    /** The unsubscribe link's token: an HMAC of the client id with this install's secret salt (config.php info.salt). */
    public static function token(\Pimple\Container $di, int $clientId): string
    {
        $salt = (string) \ClientFence\Config::getProperty('info.salt', '');
        if ($salt === '') {
            throw new \RuntimeException('The install has no salt in config.php.');
        }

        return substr(hash_hmac('sha256', 'unsubscribe|' . $clientId, $salt), 0, 32);
    }

    public static function tokenValid(\Pimple\Container $di, int $clientId, string $token): bool
    {
        return $clientId > 0 && hash_equals(self::token($di, $clientId), $token);
    }

    public static function unsubscribeUrl(\Pimple\Container $di, int $clientId): string
    {
        return $di['tools']->url('/unsubscribe/' . $clientId . '/' . self::token($di, $clientId));
    }

    /** The footer every mass email gets while the opt-out is on (HTML, the link escaped). */
    public static function footer(string $url): string
    {
        return '<p style="margin-top:24px;font-size:12px;color:#6c7a89">'
            . htmlspecialchars(__trans('You get this email because you are our client. Do not want these emails?'), ENT_QUOTES)
            . ' <a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . htmlspecialchars(__trans('Unsubscribe'), ENT_QUOTES) . '</a></p>';
    }

    /** Keeps the unsubscribe link of a queued email (its List-Unsubscribe headers). */
    public static function rememberUnsubscribe(\Pimple\Container $di, int $queueId, string $url): void
    {
        try {
            self::db($di)->executeStatement('REPLACE INTO ' . self::UNSUBSCRIBE_TABLE . ' (queue_id, url, created_at) VALUES (?, ?, ?)', [$queueId, mb_substr($url, 0, 500), date('Y-m-d H:i:s')]);
        } catch (\Throwable) {
            // The email still goes, with the link in its footer.
        }
    }

    public static function queuedUnsubscribe(\Pimple\Container $di, int $queueId): ?string
    {
        try {
            $url = self::db($di)->fetchOne('SELECT url FROM ' . self::UNSUBSCRIBE_TABLE . ' WHERE queue_id = ?', [$queueId]);
        } catch (\Throwable) {
            return null;
        }

        return is_string($url) && str_starts_with($url, 'http') ? $url : null;
    }

    /** Drops the row of a sent email, and rows of emails gone from the queue a day ago or more. */
    public static function forgetUnsubscribe(\Pimple\Container $di, int $queueId): void
    {
        try {
            self::db($di)->executeStatement(
                'DELETE FROM ' . self::UNSUBSCRIBE_TABLE . ' WHERE queue_id = ? OR (created_at < ? AND queue_id NOT IN (SELECT id FROM email_queue))',
                [$queueId, date('Y-m-d H:i:s', time() - 86400)]
            );
        } catch (\Throwable) {
            // Left for the next sent email.
        }
    }

    private static function setting(\Pimple\Container $di, string $key): bool
    {
        try {
            return (string) (($di['mod_config']('client'))[$key] ?? '0') === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    private static function db(\Pimple\Container $di): Connection
    {
        return $di['em']->getConnection();
    }

    /**
     * @param list<mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(Connection $db, string $sql, array $params = []): array
    {
        try {
            return $db->fetchAllAssociative($sql, $params);
        } catch (\Doctrine\DBAL\Exception\TableNotFoundException) {
            return [];
        }
    }

    /** @param list<mixed> $params */
    private static function quietly(Connection $db, string $sql, array $params = []): void
    {
        try {
            $db->executeStatement($sql, $params);
        } catch (\Doctrine\DBAL\Exception\TableNotFoundException) {
            // A table this install does not have yet.
        }
    }
}
