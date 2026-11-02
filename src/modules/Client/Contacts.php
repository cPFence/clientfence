<?php

declare(strict_types=1);

namespace ClientFence\Mod\Client;

use ClientFence\InformationException;
use Doctrine\DBAL\Connection;

/**
 * Contacts of a client account (like the WHMCS "Contacts" tab): people with a name, address and
 * email who do not sign in, and who get a copy of the account's emails of the kinds chosen for
 * them (KINDS). Authorized users (Users.php) are the people who sign in.
 *
 * The owner, owner links and users with the `contacts` permission manage them on /contacts;
 * staff on the client page's Contacts tab (the client "edit profile" permission to change them).
 * Every change is in the activity log.
 *
 * Copies: Email\Service::sendTemplate (core patch "contact copies") sends each client email to
 * the client and then calls copies() for the contacts whose kinds include the email's kind
 * (kindOf()). Emails with a sign-in link, a code or a password reset are never copied.
 * Unlike WHMCS, support contacts get copies of the account's ticket emails.
 *
 * A deleted or anonymized client takes its contacts with it (Users::clientDeleted). The WHMCS
 * import writes the rows directly (tblcontacts, with the email choices).
 */
final class Contacts
{
    public const TABLE = 'client_contact';
    public const MAX_PER_CLIENT = 50;

    /** Email kinds, in WHMCS's names (generalemails, invoiceemails, ...), and how the pages word them. */
    public const KINDS = [
        'general' => 'General account emails',
        'invoice' => 'Invoices, payments and quotes',
        'product' => 'Services (including welcome emails with sign-in details)',
        'domain' => 'Domains',
        'support' => 'Support tickets',
    ];

    /**
     * The permission an authorized user needs to give a contact copies of each kind (the owner and
     * owner links need none): a copy shows what the permission guards. Product copies include
     * welcome emails with sign-in details, so they need `manageproducts`.
     */
    public const KIND_PERMISSIONS = [
        'general' => 'profile', 'invoice' => 'invoices', 'product' => 'manageproducts',
        'domain' => 'domains', 'support' => 'tickets',
    ];

    /** Contact fields and their longest length. */
    public const FIELDS = [
        'first_name' => 100, 'last_name' => 100, 'company' => 100, 'email' => 255,
        'phone_cc' => 10, 'phone' => 100, 'address_1' => 100, 'address_2' => 100, 'city' => 100,
        'state' => 100, 'postcode' => 100, 'country' => 100, 'tax_id' => 100,
    ];

    /** Client emails that never go to a contact: sign-in links, codes, passwords, mail to someone else. */
    private const NEVER_COPIED = [
        'mod_client_password_reset_request', 'mod_client_signup_admin', 'mod_client_signup', 'mod_client_confirm',
        'mod_client_user_password_reset', 'mod_client_clientfence_signin_pin', 'mod_client_user_invite',
        'mod_client_password_reset_information', 'mod_email_test', 'mod_support_mail_rejected',
        'mod_support_helpdesk_ticket_open',
    ];

    /**
     * The kind of a client email by its template code, or null when it is never copied.
     */
    public static function kindOf(string $code): ?string
    {
        if (in_array($code, self::NEVER_COPIED, true) || str_starts_with($code, 'mod_staff_')
            || preg_match('/password|sign_?in|_pin\b|confirm|invite|verif|twofactor|two_factor|reset|unsubscribe/', $code) === 1) {
            return null;
        }

        return match (true) {
            str_starts_with($code, 'mod_invoice_'), $code === 'mod_order_price_changed' => 'invoice',
            str_starts_with($code, 'mod_servicedomain_') => 'domain',
            preg_match('/^mod_service[a-z]+_/', $code) === 1, $code === 'mod_order_cancel_request' => 'product',
            $code === 'mod_support_network_issue' => 'general',
            str_starts_with($code, 'mod_support_') => 'support',
            default => 'general',
        };
    }

    /**
     * Who gets a copy of a client email (`code`): [{email, name}] of the account's contacts with
     * that kind, without the address the email went to.
     *
     * @return list<array{email: string, name: string}>
     */
    public static function copies(\Pimple\Container $di, int $clientId, string $code, string $sentTo = ''): array
    {
        $kind = self::kindOf($code);
        if ($kind === null || $clientId <= 0) {
            return [];
        }
        try {
            $rows = self::db($di)->fetchAllAssociative('SELECT first_name, last_name, email, emails FROM ' . self::TABLE . ' WHERE client_id = ? ORDER BY id', [$clientId]);
        } catch (\Throwable) {
            // No table yet (the pack is not installed): nobody to copy.
            return [];
        }
        $out = [];
        $seen = [strtolower(trim($sentTo)) => true];
        foreach ($rows as $row) {
            $email = trim((string) $row['email']);
            if ($email === '' || isset($seen[strtolower($email)]) || !in_array($kind, self::kindList((string) $row['emails']), true)) {
                continue;
            }
            $seen[strtolower($email)] = true;
            $out[] = ['email' => $email, 'name' => trim($row['first_name'] . ' ' . $row['last_name'])];
        }

        return $out;
    }

    /**
     * The account's contacts, oldest first, each with `emails` as a list of kinds.
     *
     * @return list<array<string, mixed>>
     */
    public static function listFor(\Pimple\Container $di, int $clientId): array
    {
        $rows = self::db($di)->fetchAllAssociative('SELECT * FROM ' . self::TABLE . ' WHERE client_id = ? ORDER BY id', [$clientId]);

        return array_map(self::view(...), $rows);
    }

    /**
     * The Contacts page and tab: {contacts, kinds: [{name, label, allowed}], max}; `allowed`: the
     * signed-in authorized user may set that kind (always for the owner and staff).
     */
    public static function page(\Pimple\Container $di, int $clientId): array
    {
        self::client($di, $clientId);
        $allowed = self::allowedKinds(self::restrictedUser($di, null));

        return [
            'contacts' => self::listFor($di, $clientId),
            'kinds' => array_map(static fn (string $name, string $label): array => ['name' => $name, 'label' => $label, 'allowed' => in_array($name, $allowed, true)], array_keys(self::KINDS), self::kindLabels()),
            'max' => self::MAX_PER_CLIENT,
        ];
    }

    /**
     * Adds a contact (no `id`) or changes one (`id`) of the account. Returns the contact id.
     * $adminId: the staff member who did it (null: the client), for the activity log.
     */
    public static function save(\Pimple\Container $di, int $clientId, array $data, ?int $adminId = null): int
    {
        self::client($di, $clientId);
        $db = self::db($di);
        $id = (int) ($data['id'] ?? 0);
        $old = null;
        if ($id > 0) {
            $old = $db->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE id = ? AND client_id = ?', [$id, $clientId]);
            if ($old === false) {
                throw new InformationException('Contact not found', [], 404);
            }
        } elseif ((int) $db->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE client_id = ?', [$clientId]) >= self::MAX_PER_CLIENT) {
            throw new InformationException('An account can have up to :max contacts.', [':max' => self::MAX_PER_CLIENT]);
        }

        $row = self::clean($di, $data);
        $row['emails'] = implode(',', self::kindsFor(self::kindList($row['emails']), self::restrictedUser($di, $adminId), $old === null ? null : ['email' => (string) $old['email'], 'emails' => self::kindList((string) $old['emails'])], $row['email']));
        $taken = $db->fetchOne('SELECT id FROM ' . self::TABLE . ' WHERE client_id = ? AND LOWER(email) = ? AND id <> ?', [$clientId, strtolower($row['email']), $id]);
        if ($taken !== false) {
            throw new InformationException('This account already has a contact with this email.');
        }
        $now = date('Y-m-d H:i:s');
        if ($old === null) {
            $db->insert(self::TABLE, $row + ['client_id' => $clientId, 'created_at' => $now, 'updated_at' => $now]);
            $id = (int) $db->lastInsertId();
            Users::activity($di, [$clientId], sprintf('Contact %s added by %s', $row['email'], self::by($di, $adminId)), $adminId);
        } else {
            $db->update(self::TABLE, $row + ['updated_at' => $now], ['id' => $id]);
            Users::activity($di, [$clientId], sprintf('Contact %s changed by %s', $row['email'], self::by($di, $adminId)), $adminId);
        }

        return $id;
    }

    /**
     * Removes a contact (`id`) of the account.
     */
    public static function delete(\Pimple\Container $di, int $clientId, int $id, ?int $adminId = null): void
    {
        $db = self::db($di);
        $email = $db->fetchOne('SELECT email FROM ' . self::TABLE . ' WHERE id = ? AND client_id = ?', [$id, $clientId]);
        if ($email === false) {
            throw new InformationException('Contact not found', [], 404);
        }
        $db->delete(self::TABLE, ['id' => $id]);
        Users::activity($di, [$clientId], sprintf('Contact %s removed by %s', $email, self::by($di, $adminId)), $adminId);
    }

    /**
     * The kinds a contact keeps after a save. An authorized user who is not an owner link
     * ($permissions: the user's permissions; null: the owner, an owner link or staff) sets only
     * the kinds the permissions cover (KIND_PERMISSIONS); the other kinds the contact had stay
     * as they were, unless the email changes: then they go, so nobody redirects copies the
     * permissions do not show.
     *
     * @param list<string>                                    $requested
     * @param list<string>|null                               $permissions
     * @param array{email: string, emails: list<string>}|null $old
     *
     * @return list<string>
     */
    public static function kindsFor(array $requested, ?array $permissions, ?array $old, string $email): array
    {
        $allowed = self::allowedKinds($permissions);
        $kinds = array_intersect($requested, $allowed);
        if ($old !== null && strcasecmp(trim($old['email']), trim($email)) === 0) {
            $kinds = array_merge($kinds, array_diff($old['emails'], $allowed));
        }

        return array_values(array_intersect(array_keys(self::KINDS), $kinds));
    }

    /** @return list<string> the kinds a login with these permissions may set (null: all) */
    private static function allowedKinds(?array $permissions): array
    {
        if ($permissions === null) {
            return array_keys(self::KINDS);
        }

        return array_keys(array_filter(self::KIND_PERMISSIONS, static fn (string $permission): bool => in_array($permission, $permissions, true)));
    }

    /**
     * The permissions of the signed-in authorized user when the kinds are limited for them, or
     * null (staff, the owner, an owner link).
     *
     * @return list<string>|null
     */
    private static function restrictedUser(\Pimple\Container $di, ?int $adminId): ?array
    {
        if ($adminId !== null) {
            return null;
        }
        $user = Users::context($di);
        if ($user === null || !empty($user['owner'])) {
            return null;
        }

        return array_values(array_map('strval', (array) ($user['permissions'] ?? [])));
    }

    /** A deleted or anonymized client leaves no contacts (Users::clientDeleted). */
    public static function forgetClient(Connection $db, int $clientId): void
    {
        try {
            $db->delete(self::TABLE, ['client_id' => $clientId]);
        } catch (\Throwable) {
            // No table yet: nothing to remove.
        }
    }

    /** KINDS' labels in the reader's language (literal here so tools/locales.py finds them). */
    public static function kindLabels(): array
    {
        return [
            __trans('General account emails'),
            __trans('Invoices, payments and quotes'),
            __trans('Services (including welcome emails with sign-in details)'),
            __trans('Domains'),
            __trans('Support tickets'),
        ];
    }

    /** The kinds stored for a contact ("invoice,support") as a list of known kinds. */
    public static function kindList(string $stored): array
    {
        return array_values(array_intersect(array_keys(self::KINDS), array_map('trim', explode(',', $stored))));
    }

    /**
     * Checked and trimmed fields of a contact form.
     *
     * @return array<string, string>
     */
    private static function clean(\Pimple\Container $di, array $data): array
    {
        $row = [];
        foreach (self::FIELDS as $field => $max) {
            $value = $data[$field] ?? '';
            $row[$field] = is_scalar($value) ? trim((string) $value) : '';
            if (mb_strlen($row[$field]) > $max) {
                throw new InformationException('The :field is too long (:max characters at most).', [':field' => str_replace('_', ' ', $field), ':max' => $max]);
            }
        }
        if ($row['first_name'] === '') {
            throw new InformationException('Enter the contact\'s first name.');
        }
        if ($row['email'] === '') {
            throw new InformationException('Enter the contact\'s email.');
        }
        $di['tools']->validateAndSanitizeEmail($row['email']);
        // Arabic and Persian digits are digits (as the profile's phone, Tools::validatePhoneNumber).
        $row['phone_cc'] = \ClientFence\Tools::latinDigits($row['phone_cc']);
        if ($row['phone'] !== '') {
            $row['phone'] = \ClientFence\Tools::validatePhoneNumber($row['phone']);
        }
        if ($row['phone_cc'] !== '' && preg_match('/^\+?[0-9]{1,4}$/', $row['phone_cc']) !== 1) {
            throw new InformationException('The phone country code is not valid.');
        }
        $row['phone_cc'] = ltrim($row['phone_cc'], '+');
        $kinds = $data['emails'] ?? [];
        $kinds = is_array($kinds) ? $kinds : explode(',', (string) $kinds);
        $row['emails'] = implode(',', array_values(array_intersect(array_keys(self::KINDS), array_map(static fn ($k): string => is_scalar($k) ? trim((string) $k) : '', $kinds))));

        return $row;
    }

    private static function view(array $row): array
    {
        $out = ['id' => (int) $row['id']];
        foreach (array_keys(self::FIELDS) as $field) {
            $out[$field] = (string) ($row[$field] ?? '');
        }

        return $out + [
            'emails' => self::kindList((string) $row['emails']),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private static function client(\Pimple\Container $di, int $clientId): void
    {
        if ($clientId <= 0 || self::db($di)->fetchOne('SELECT id FROM client WHERE id = ?', [$clientId]) === false) {
            throw new InformationException('Client not found', [], 404);
        }
    }

    /** Who made a change, for the activity log. */
    private static function by(\Pimple\Container $di, ?int $adminId): string
    {
        if ($adminId !== null) {
            return 'staff';
        }
        $user = Users::context($di);

        return $user === null ? 'the account owner' : 'authorized user ' . $user['email'];
    }

    /**
     * The table, made on first use on an install that predates it (Clientfence\Service::ensureTables()),
     * once a request. The API calls it before using the contacts.
     */
    public static function ensure(\Pimple\Container $di): void
    {
        /** @var \WeakMap<\Pimple\Container, true>|null $ready */
        static $ready = null;
        $ready ??= new \WeakMap();
        if (!isset($ready[$di])) {
            \ClientFence\Mod\Clientfence\Service::ensureTables($di, true);
            $ready[$di] = true;
        }
    }

    private static function db(\Pimple\Container $di): Connection
    {
        return $di['em']->getConnection();
    }
}
