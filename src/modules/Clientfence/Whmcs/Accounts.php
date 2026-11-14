<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Whmcs;

use ClientFence\Mod\Client\Contacts;
use ClientFence\Mod\Client\TwoFactor;
use ClientFence\Mod\Client\Users;
use Doctrine\DBAL\Connection;

/**
 * ClientFence: the WHMCS import's account steps (Run.php runs them in batches): currencies, client
 * groups, client custom fields, clients with their owner logins, the other logins (authorized
 * users) and contacts. Rows are written directly, so nothing is emailed and no event runs; each
 * imported row goes in the id map (Map.php) and is never imported twice; a row left out with a
 * warning is noted there too ("skipped"), so a second run does not warn again.
 *
 * How WHMCS's logins become ClientFence's (WHMCS 8 and later keep logins in tblusers, linked to
 * accounts by tblusers_clients; ClientFence keeps the owner's login on the client and other logins
 * as authorized users, and an email signs in to one of the two only):
 *   - the owner login of an account signs in to it: its email, password hash, two-factor secret
 *     and Google link go to the client; the WHMCS account email, when it differs, becomes the
 *     billing email (invoices keep going there);
 *   - a login that owns several accounts owns the first; the others keep their account email and
 *     get a password reset (a warning names them);
 *   - other links become authorized users with their WHMCS permissions; a login that is also an
 *     owner cannot be one (a warning names it);
 *   - logins without an account are not imported.
 * WHMCS 7 (best effort): the client's own email and password, and sub-accounts (contacts with a
 * password) as authorized users.
 *
 * Two-factor secrets are read with WHMCS's encryption key (Cipher.php) and stored encrypted with
 * this install's key; without the key, or when a secret cannot be read, the login is imported
 * without two-factor and a warning names it. Password hashes are kept when PHP can check them
 * (bcrypt, argon2); other logins get a password nobody knows and must use "Forgot password".
 *
 * Choices kept from WHMCS: Active and Inactive clients are active (WHMCS lets both sign in),
 * Closed ones canceled; clients who did not opt in to marketing email are opted out; the
 * client's language when this install has it.
 */
final class Accounts
{
    /** The steps in order: name => [label, WHMCS table read, its id column]. */
    public const STEPS = [
        'currencies' => ['Currencies', 'tblcurrencies'],
        'groups' => ['Client groups', 'tblclientgroups'],
        'fields' => ['Client custom fields', 'tblcustomfields'],
        'clients' => ['Clients and their owner logins', 'tblclients'],
        'users' => ['Authorized users', 'tblusers_clients'],
        'contacts' => ['Contacts', 'tblcontacts'],
    ];

    /** WHMCS language names => this install's locales (only the ones it ships). */
    public const LANGUAGES = [
        'arabic' => 'ar_SA', 'bulgarian' => 'bg_BG', 'chinese' => 'zh_CN', 'czech' => 'cs_CZ', 'dutch' => 'nl_NL',
        'english' => 'en_US', 'farsi' => 'fa_IR', 'finnish' => 'fi_FI', 'french' => 'fr_FR', 'german' => 'de_DE',
        'greek' => 'el_GR', 'hebrew' => 'he_IL', 'hungarian' => 'hu_HU', 'indonesian' => 'id_ID', 'italian' => 'it_IT', 'persian' => 'fa_IR',
        'polish' => 'pl_PL', 'portuguese-br' => 'pt_BR', 'portuguese-pt' => 'pt_PT', 'romanian' => 'ro_RO',
        'russian' => 'ru_RU', 'serbian' => 'sr_RS', 'spanish' => 'es_ES', 'thai' => 'th_TH', 'turkish' => 'tr_TR',
        'ukranian' => 'uk_UA', 'ukrainian' => 'uk_UA', 'uzbek' => 'uz_UZ', 'vietnamese' => 'vi_VN',
    ];

    /** WHMCS contact email flags => contact email kinds (Contacts::KINDS). */
    private const CONTACT_KINDS = [
        'generalemails' => 'general', 'invoiceemails' => 'invoice', 'productemails' => 'product',
        'domainemails' => 'domain', 'supportemails' => 'support', 'affiliateemails' => 'affiliate',
    ];

    private const CUSTOM_SLOTS = 20;

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<int, list<int>>|null owner login (tblusers id) => the accounts it owns, oldest first */
    private ?array $owned = null;

    /** @var array<string, int>|null lower-case email => client id signing in with it */
    private ?array $logins = null;

    /**
     * @param \Closure(string): string                 $encrypt   this install's encryption (two-factor secrets)
     * @param \Closure(string, array<string, mixed>): void $config merges settings into a module's settings
     */
    public function __construct(
        private readonly Source $source,
        private readonly Connection $db,
        private readonly Map $map,
        private readonly ?Cipher $cipher,
        private readonly \Closure $encrypt,
        private readonly \Closure $config,
        private readonly string $now,
    ) {
    }

    /** Warnings of the batches run so far (names and ids, never secrets). */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** How many WHMCS rows a step reads (the progress total). */
    public function total(string $step): int
    {
        return match ($step) {
            'users' => $this->source->hasTable('tblusers')
                ? $this->source->count('tblusers_clients', 'owner = 0')
                : $this->source->count('tblcontacts', 'subaccount = 1'),
            'fields' => $this->source->count('tblcustomfields', "type = 'client'"),
            default => $this->source->count(self::STEPS[$step][1]),
        };
    }

    /**
     * Imports up to $limit rows of a step after the WHMCS id $after. Returns the last id read
     * (null: the step is done) and how many rows were imported.
     *
     * @return array{0: ?int, 1: int}
     */
    public function batch(string $step, int $after, int $limit): array
    {
        return match ($step) {
            'currencies' => $this->currencies(),
            'groups' => $this->groups(),
            'fields' => $this->fields(),
            'clients' => $this->clients($after, $limit),
            'users' => $this->source->hasTable('tblusers') ? $this->users($after, $limit) : $this->subAccounts($after, $limit),
            'contacts' => $this->contacts($after, $limit),
            default => throw new \InvalidArgumentException('Unknown step ' . $step),
        };
    }

    /**
     * Currencies by code: the WHMCS default becomes the default, rates and prefix/suffix kept.
     *
     * @return array{0: null, 1: int}
     */
    private function currencies(): array
    {
        $done = 0;
        $rows = $this->source->rows('SELECT * FROM tblcurrencies ORDER BY id');
        $hasDefault = array_filter($rows, static fn (array $r): bool => (int) ($r['default'] ?? 0) === 1) !== [];
        foreach ($rows as $row) {
            if ($this->map->has('currency', (int) $row['id'])) {
                continue;
            }
            $code = strtoupper(trim((string) $row['code']));
            if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
                $this->warn(sprintf('Currency #%d "%s" is not a three-letter code and was not imported.', $row['id'], $code));

                continue;
            }
            $prefix = (string) ($row['prefix'] ?? '');
            $suffix = (string) ($row['suffix'] ?? '');
            $isDefault = (int) ($row['default'] ?? 0) === 1;
            $values = [
                'conversion_rate' => (float) $row['rate'] > 0 ? (float) $row['rate'] : 1.0,
                'is_rate_manual' => 1,
                'format_pattern' => $prefix === '' && $suffix === '' ? null : $prefix . '{amount}' . $suffix,
                'fraction_digits' => (int) ($row['format'] ?? 1) === 4 ? 0 : 2,
                'updated_at' => $this->now,
            ];
            if ($hasDefault) {
                $values['is_default'] = $isDefault ? 1 : 0;
            }
            if ($isDefault) {
                $this->db->executeStatement('UPDATE currency SET is_default = 0 WHERE code <> ?', [$code]);
            }
            $id = $this->db->fetchOne('SELECT id FROM currency WHERE code = ?', [$code]);
            if ($id === false) {
                $this->db->insert('currency', $values + ['code' => $code, 'is_default' => $isDefault ? 1 : 0, 'created_at' => $this->now]);
                $id = (int) $this->db->lastInsertId();
            } else {
                $this->db->update('currency', $values, ['id' => (int) $id]);
            }
            $this->map->put('currency', (int) $row['id'], (int) $id);
            ++$done;
        }

        return [null, $done];
    }

    /**
     * Client groups by name (discounts and exemptions are not carried: a warning says so).
     *
     * @return array{0: null, 1: int}
     */
    private function groups(): array
    {
        $done = 0;
        foreach ($this->source->rows('SELECT * FROM tblclientgroups ORDER BY id') as $row) {
            if ($this->map->has('group', (int) $row['id'])) {
                continue;
            }
            $title = trim((string) $row['groupname']) ?: 'Group ' . $row['id'];
            $id = $this->db->fetchOne('SELECT id FROM client_group WHERE title = ?', [$title]);
            if ($id === false) {
                $this->db->insert('client_group', ['title' => $title, 'created_at' => $this->now, 'updated_at' => $this->now]);
                $id = (int) $this->db->lastInsertId();
            }
            $this->map->put('group', (int) $row['id'], (int) $id);
            if ((float) ($row['discountpercent'] ?? 0) > 0 || trim((string) ($row['susptermexempt'] ?? '')) !== '' || trim((string) ($row['separateinvoices'] ?? '')) !== '') {
                $this->warn(sprintf('Client group "%s": its discount and exemptions are not imported; set them again where ClientFence has them.', $title));
            }
            ++$done;
        }

        return [null, $done];
    }

    /**
     * Client custom fields become the client custom fields 1 to 20 (title, shown to the client
     * unless admin only, required); more than 20 go into the client notes.
     *
     * @return array{0: null, 1: int}
     */
    private function fields(): array
    {
        $done = 0;
        $settings = [];
        $slot = count($this->map->all('customfield'));
        foreach ($this->source->rows("SELECT * FROM tblcustomfields WHERE type = 'client' ORDER BY sortorder, id") as $row) {
            if ($this->map->has('customfield', (int) $row['id'])) {
                continue;
            }
            ++$slot;
            $title = trim((string) $row['fieldname']);
            if ($slot > self::CUSTOM_SLOTS) {
                $this->map->put('customfield', (int) $row['id'], 0);
                $this->warn(sprintf('Client custom field "%s": ClientFence has %d client custom fields; its answers are in the client notes.', $title, self::CUSTOM_SLOTS));

                continue;
            }
            $settings['custom_' . $slot] = [
                'active' => (string) ($row['adminonly'] ?? '') === 'on' ? 0 : 1,
                'required' => (string) ($row['required'] ?? '') === 'on' ? 1 : 0,
                'title' => $title,
            ];
            $this->map->put('customfield', (int) $row['id'], $slot);
            ++$done;
        }
        if ($settings !== []) {
            ($this->config)('mod_client', ['custom_fields' => $settings]);
        }

        return [null, $done];
    }

    /**
     * Clients with their WHMCS ids, and the owner login of each.
     *
     * @return array{0: ?int, 1: int}
     */
    private function clients(int $after, int $limit): array
    {
        $rows = $this->source->rows('SELECT * FROM tblclients WHERE id > ? ORDER BY id LIMIT ' . $limit, [$after]);
        if ($rows === []) {
            return [null, 0];
        }
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $owners = $this->ownerLogins($ids);
        $values = $this->customValues($ids);
        $notes = $this->staffNotes($ids);
        $currencies = $this->currencyCodes();
        $done = 0;
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if ($this->map->has('client', $id)) {
                continue;
            }
            $owner = $owners[$id] ?? null;
            $accountEmail = trim((string) $row['email']);
            // The owner login signs in to the first account it owns.
            $signsIn = $owner !== null && ($this->owned()[(int) $owner['id']][0] ?? 0) === $id;
            $loginEmail = $signsIn ? trim((string) $owner['email']) : $accountEmail;
            if ($this->source->hasTable('tblusers') === false) {
                $hash = (string) ($row['password'] ?? '');
            } else {
                $hash = $signsIn ? (string) $owner['password'] : '';
            }
            $email = $this->freeEmail($loginEmail, $accountEmail, $id);
            if ($owner !== null && !$signsIn) {
                $this->warn(sprintf('Client #%d: its owner login %s owns an earlier account and signs in there; this account signs in with %s after "Forgot password".', $id, $owner['email'], $email));
            } elseif ($owner === null && $this->source->hasTable('tblusers')) {
                $this->warn(sprintf('Client #%d has no owner login in WHMCS; it signs in with %s after "Forgot password".', $id, $email));
            }

            [$cc, $phone] = self::phone((string) ($row['phonenumber'] ?? ''));
            $client = [
                'id' => $id,
                'role' => 'client',
                'email' => $email,
                'billing_email' => strcasecmp($accountEmail, $email) !== 0 && $accountEmail !== '' ? $accountEmail : null,
                // An account whose owner signs in elsewhere (or has none) was named above already.
                'pass' => $this->hash($hash, sprintf('Client #%d', $id), $signsIn || !$this->source->hasTable('tblusers')),
                'status' => match (strtolower((string) $row['status'])) {
                    'closed' => 'canceled',
                    default => 'active',
                },
                'email_approved' => 1,
                'tax_exempt' => (int) ($row['taxexempt'] ?? 0) === 1 ? 1 : 0,
                'type' => trim((string) $row['companyname']) !== '' ? 'company' : 'individual',
                'first_name' => self::cut($row['firstname'] ?? '', 100),
                'last_name' => self::cut($row['lastname'] ?? '', 100),
                'phone_cc' => $cc,
                'phone' => self::cut($phone, 100),
                'company' => self::cut($row['companyname'] ?? '', 100),
                'company_vat' => self::cut($row['tax_id'] ?? '', 100),
                'address_1' => self::cut($row['address1'] ?? '', 100),
                'address_2' => self::cut($row['address2'] ?? '', 100),
                'city' => self::cut($row['city'] ?? '', 100),
                'state' => self::cut($row['state'] ?? '', 100),
                'postcode' => self::cut($row['postcode'] ?? '', 100),
                'country' => strtoupper(self::cut($row['country'] ?? '', 100)),
                'currency' => $currencies[(int) ($row['currency'] ?? 0)] ?? null,
                'lang' => self::locale((string) (($signsIn ? $owner['language'] : null) ?: ($row['language'] ?? ''))),
                'ip' => self::cut($row['ip'] ?? '', 45) ?: null,
                'client_group_id' => (int) ($row['groupid'] ?? 0) > 0 ? $this->map->get('group', (int) $row['groupid']) : null,
                'notes' => null,
                'created_at' => self::date($row['datecreated'] ?? null) ?? self::date($row['created_at'] ?? null) ?? $this->now,
                'updated_at' => $this->now,
            ];
            $extra = [];
            foreach ($values[$id] ?? [] as $fieldId => $value) {
                $slot = $this->map->get('customfield', $fieldId);
                if ($slot !== null && $slot > 0) {
                    $client['custom_' . $slot] = $value;
                } elseif ($slot === 0 && $value !== '') {
                    $extra[] = $value;
                }
            }
            $client['notes'] = self::notes((string) ($row['notes'] ?? ''), $notes[$id] ?? [], $extra);
            $this->db->insert('client', $client);
            $this->map->put('client', $id, $id);
            $this->logins[strtolower($email)] = $id;

            if ((int) ($row['marketing_emails_opt_in'] ?? 1) === 0 || (int) ($row['emailoptout'] ?? 0) === 1) {
                $this->db->insert('client_marketing_optout', ['client_id' => $id, 'source' => 'whmcs', 'opted_out_at' => $this->now]);
            }
            if ($signsIn) {
                $this->map->put('owner', (int) $owner['id'], $id);
                $this->secondFactor('client', $id, (string) ($owner['second_factor'] ?? ''), (string) ($owner['second_factor_config'] ?? ''), sprintf('Client #%d', $id));
                $this->google('client', $id, (int) $owner['id'], $email);
            } elseif (!$this->source->hasTable('tblusers')) {
                $this->secondFactor('client', $id, (string) ($row['authmodule'] ?? ''), (string) ($row['authdata'] ?? ''), sprintf('Client #%d', $id));
            }
            ++$done;
        }

        return [(int) end($rows)['id'], $done];
    }

    /**
     * WHMCS 8+: logins linked to an account they do not own become authorized users.
     *
     * @return array{0: ?int, 1: int}
     */
    private function users(int $after, int $limit): array
    {
        $links = $this->source->rows('SELECT * FROM tblusers_clients WHERE owner = 0 AND id > ? ORDER BY id LIMIT ' . $limit, [$after]);
        if ($links === []) {
            return [null, 0];
        }
        $userIds = array_values(array_unique(array_map(static fn (array $l): int => (int) $l['auth_user_id'], $links)));
        $users = [];
        foreach ($this->source->rows('SELECT * FROM tblusers WHERE id IN (' . implode(',', $userIds) . ')') as $user) {
            $users[(int) $user['id']] = $user;
        }
        $done = 0;
        foreach ($links as $link) {
            if ($this->map->has('link', (int) $link['id']) || $this->map->has('skipped', 'link:' . $link['id'])) {
                continue;
            }
            $user = $users[(int) $link['auth_user_id']] ?? null;
            $clientId = $this->map->get('client', (int) $link['client_id']);
            if ($user === null || $clientId === null) {
                continue;
            }
            $userId = $this->user($user, sprintf('client #%d', $clientId));
            if ($userId === null) {
                $this->map->put('skipped', 'link:' . $link['id'], 0);

                continue;
            }
            $this->link($userId, $clientId, (string) ($link['permissions'] ?? ''), self::date($link['last_login'] ?? null), self::date($link['created_at'] ?? null));
            $this->map->put('link', (int) $link['id'], $userId);
            ++$done;
        }

        return [(int) end($links)['id'], $done];
    }

    /**
     * WHMCS 7: sub-accounts (contacts with a password) become authorized users.
     *
     * @return array{0: ?int, 1: int}
     */
    private function subAccounts(int $after, int $limit): array
    {
        $rows = $this->source->rows('SELECT * FROM tblcontacts WHERE subaccount = 1 AND id > ? ORDER BY id LIMIT ' . $limit, [$after]);
        if ($rows === []) {
            return [null, 0];
        }
        $done = 0;
        foreach ($rows as $row) {
            $clientId = $this->map->get('client', (int) $row['userid']);
            if ($clientId === null || $this->map->has('link', 'c' . $row['id']) || $this->map->has('skipped', 'link:c' . $row['id'])) {
                continue;
            }
            $userId = $this->user([
                'id' => 'c' . $row['id'], 'email' => $row['email'], 'first_name' => $row['firstname'], 'last_name' => $row['lastname'],
                'password' => $row['password'] ?? '', 'language' => '', 'created_at' => $row['created_at'] ?? null,
            ], sprintf('client #%d', $clientId));
            if ($userId === null) {
                $this->map->put('skipped', 'link:c' . $row['id'], 0);

                continue;
            }
            $this->link($userId, $clientId, (string) ($row['permissions'] ?? ''), null, self::date($row['created_at'] ?? null));
            $this->map->put('link', 'c' . $row['id'], $userId);
            ++$done;
        }

        return [(int) end($rows)['id'], $done];
    }

    /**
     * Contacts (WHMCS 7 sub-accounts are users, not contacts) with their email choices.
     *
     * @return array{0: ?int, 1: int}
     */
    private function contacts(int $after, int $limit): array
    {
        $rows = $this->source->rows('SELECT * FROM tblcontacts WHERE id > ? ORDER BY id LIMIT ' . $limit, [$after]);
        if ($rows === []) {
            return [null, 0];
        }
        $done = 0;
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if ($this->map->has('contact', $id) || $this->map->has('skipped', 'contact:' . $id) || (!$this->source->hasTable('tblusers') && (int) ($row['subaccount'] ?? 0) === 1)) {
                continue;
            }
            $clientId = $this->map->get('client', (int) $row['userid']);
            $email = trim((string) $row['email']);
            if ($clientId === null) {
                continue;
            }
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $this->warn(sprintf('Contact #%d of client #%d has no valid email and was not imported.', $id, $clientId));
                $this->map->put('skipped', 'contact:' . $id, 0);

                continue;
            }
            if ($this->db->fetchOne('SELECT id FROM ' . Contacts::TABLE . ' WHERE client_id = ? AND LOWER(email) = ?', [$clientId, strtolower($email)]) !== false) {
                $this->warn(sprintf('Contact #%d of client #%d: the account already has a contact with %s.', $id, $clientId, $email));
                $this->map->put('skipped', 'contact:' . $id, 0);

                continue;
            }
            $kinds = [];
            foreach (self::CONTACT_KINDS as $flag => $kind) {
                if ((int) ($row[$flag] ?? 0) === 1) {
                    $kinds[] = $kind;
                }
            }
            [$cc, $phone] = self::phone((string) ($row['phonenumber'] ?? ''));
            $this->db->insert(Contacts::TABLE, [
                'client_id' => $clientId,
                'first_name' => self::cut($row['firstname'] ?? '', 100),
                'last_name' => self::cut($row['lastname'] ?? '', 100),
                'company' => self::cut($row['companyname'] ?? '', 100),
                'email' => self::cut($email, 255),
                'phone_cc' => $cc,
                'phone' => self::cut($phone, 100),
                'address_1' => self::cut($row['address1'] ?? '', 100),
                'address_2' => self::cut($row['address2'] ?? '', 100),
                'city' => self::cut($row['city'] ?? '', 100),
                'state' => self::cut($row['state'] ?? '', 100),
                'postcode' => self::cut($row['postcode'] ?? '', 100),
                'country' => strtoupper(self::cut($row['country'] ?? '', 100)),
                'tax_id' => self::cut($row['tax_id'] ?? '', 100),
                'emails' => implode(',', $kinds),
                'created_at' => self::date($row['created_at'] ?? null) ?? $this->now,
                'updated_at' => $this->now,
            ]);
            $this->map->put('contact', $id, (int) $this->db->lastInsertId());
            ++$done;
        }

        return [(int) end($rows)['id'], $done];
    }

    /**
     * The authorized user of a WHMCS login, made once (several links share it). Null when its
     * email signs in to a client account (ClientFence keeps the two apart).
     */
    private function user(array $user, string $where): ?int
    {
        $existing = $this->map->get('user', (string) $user['id']);
        if ($existing !== null) {
            return $existing;
        }
        $email = trim((string) $user['email']);
        if ($email === '') {
            $this->warn(sprintf('A login without an email was not imported (access to %s).', $where));

            return null;
        }
        if (isset($this->logins()[strtolower($email)])) {
            $this->warn(sprintf('Login %s: it signs in to client #%d, so its access to %s was not imported; that account\'s owner can invite another address.', $email, $this->logins()[strtolower($email)], $where));

            return null;
        }
        $taken = $this->db->fetchOne('SELECT id FROM client_user WHERE email = ?', [$email]);
        if ($taken !== false) {
            $this->map->put('user', (string) $user['id'], (int) $taken);

            return (int) $taken;
        }
        $this->db->insert('client_user', [
            'email' => self::cut($email, 255),
            'first_name' => self::cut($user['first_name'] ?? '', 100),
            'last_name' => self::cut($user['last_name'] ?? '', 100),
            'pass' => $this->hash((string) ($user['password'] ?? ''), 'Login ' . $email),
            'lang' => self::locale((string) ($user['language'] ?? '')),
            'status' => 'active',
            'session_version' => 1,
            'last_login_at' => self::date($user['last_login'] ?? null),
            'last_ip' => self::cut($user['last_ip'] ?? '', 45) ?: null,
            'created_at' => self::date($user['created_at'] ?? null) ?? $this->now,
            'updated_at' => $this->now,
        ]);
        $userId = (int) $this->db->lastInsertId();
        $this->map->put('user', (string) $user['id'], $userId);
        if (is_numeric($user['id'])) {
            $this->secondFactor('user', $userId, (string) ($user['second_factor'] ?? ''), (string) ($user['second_factor_config'] ?? ''), 'Login ' . $email);
            $this->google('user', $userId, (int) $user['id'], $email);
        }

        return $userId;
    }

    private function link(int $userId, int $clientId, string $permissions, ?string $lastLogin, ?string $created): void
    {
        if ($this->db->fetchOne('SELECT id FROM client_user_account WHERE user_id = ? AND client_id = ?', [$userId, $clientId]) !== false) {
            return;
        }
        $kept = array_values(array_intersect(Users::PERMISSIONS, array_map('trim', explode(',', $permissions))));
        $this->db->insert('client_user_account', [
            'user_id' => $userId,
            'client_id' => $clientId,
            'owner' => 0,
            'permissions' => implode(',', $kept),
            'last_login_at' => $lastLogin,
            'created_at' => $created ?? $this->now,
            'updated_at' => $this->now,
        ]);
    }

    /** Carries a WHMCS TOTP secret over, or warns that the login comes without two-factor. */
    private function secondFactor(string $kind, int $subjectId, string $module, string $config, string $who): void
    {
        if (strtolower(trim($module)) === '') {
            return;
        }
        $secret = null;
        if (strtolower(trim($module)) === 'totp') {
            // WHMCS 9 after its 2FA storage migration, older WHMCS's cipher, or not encrypted (before the migration).
            $secret = self::totpSecret($this->cipher?->decryptModel($config) ?? $this->cipher?->decrypt($config)
                ?? (preg_match('/^\s*(a:|\{)/', $config) === 1 ? $config : null));
        }
        if ($secret === null) {
            $this->warn(sprintf('%s used two-factor sign-in (%s) in WHMCS, which could not be carried over%s: it signs in with its password until two-factor is set up again.', $who, $module, $this->cipher === null ? ' (no WHMCS encryption key was entered)' : ''));

            return;
        }
        $this->db->insert('client_twofactor', [
            'kind' => $kind,
            'subject_id' => $subjectId,
            'secret' => ($this->encrypt)($secret),
            'backup_hash' => null,
            'last_step' => 0,
            'enabled_at' => $this->now,
            'updated_at' => $this->now,
        ]);
        $this->map->put('totp', $kind . ':' . $subjectId, $subjectId);
    }

    /**
     * The base32 secret in a decrypted WHMCS TOTP setting (JSON or PHP serialized, key "secret"),
     * or null.
     */
    public static function totpSecret(?string $plain): ?string
    {
        if ($plain === null || trim($plain) === '') {
            return null;
        }
        $data = json_decode($plain, true);
        if (!is_array($data)) {
            $data = @unserialize($plain, ['allowed_classes' => false]);
        }
        $candidates = is_array($data) ? self::stringsByKey($data) : ['' => trim($plain)];
        foreach ($candidates as $key => $value) {
            $value = strtoupper(str_replace(' ', '', $value));
            if (($key === '' || str_contains(strtolower((string) $key), 'secret')) && preg_match('/^[A-Z2-7]{16,128}=*$/', $value) === 1
                && TwoFactor::base32Decode($value) !== '') {
                return rtrim($value, '=');
            }
        }

        return null;
    }

    /** @return array<string, string> every string value of a nested array, by its key */
    private static function stringsByKey(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $out[(string) $key] = $value;
            } elseif (is_array($value)) {
                $out += self::stringsByKey($value);
            }
        }

        return $out;
    }

    /** The Google sign-in link of a WHMCS login (tblauthn_account_links, provider google_signin). */
    private function google(string $kind, int $subjectId, int $whmcsUserId, string $email): void
    {
        if (!$this->source->hasTable('tblauthn_account_links')) {
            return;
        }
        $links = $this->source->rows("SELECT * FROM tblauthn_account_links WHERE provider = 'google_signin' AND user_id = ? ORDER BY id", [$whmcsUserId]);
        $link = $links[0] ?? null;
        $sub = trim((string) ($link['remote_user_id'] ?? ''));
        if ($link === null || $sub === '') {
            return;
        }
        if (count($links) > 1) {
            $this->warn(sprintf('Login %s has %d Google accounts linked in WHMCS; ClientFence keeps one per login, the first linked. The others sign in with the password, or link again.', $email, count($links)));
        }
        if ($this->db->fetchOne('SELECT id FROM client_google WHERE google_sub = ? OR (kind = ? AND subject_id = ?)', [$sub, $kind, $subjectId]) !== false) {
            return;
        }
        $meta = json_decode((string) ($link['metadata'] ?? ''), true);
        $this->db->insert('client_google', [
            'kind' => $kind,
            'subject_id' => $subjectId,
            'google_sub' => self::cut($sub, 255),
            'email' => self::cut(is_array($meta) && is_string($meta['email'] ?? null) ? $meta['email'] : $email, 191),
            'created_at' => self::date($link['created_at'] ?? null) ?? $this->now,
            'last_used_at' => null,
        ]);
        $this->map->put('google', (int) $link['id'], $subjectId);
    }

    /** A password hash PHP can check, else one nobody knows (and a warning, unless $warn is false). */
    private function hash(string $hash, string $who, bool $warn = true): string
    {
        $info = password_get_info($hash);
        if ($hash !== '' && $info['algo'] !== null) {
            return $hash;
        }
        if (!$warn) {
            return password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
        }
        $this->warn(sprintf('%s: the WHMCS password could not be kept; it signs in after "Forgot password".', $who));

        return password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
    }

    /**
     * The email a client signs in with: the login email, else the account email, else a
     * placeholder (the email is in use already; a warning names it).
     */
    private function freeEmail(string $login, string $account, int $clientId): string
    {
        foreach (array_unique([$login, $account]) as $email) {
            if ($email !== '' && !isset($this->logins()[strtolower($email)])
                && $this->db->fetchOne('SELECT id FROM client_user WHERE email = ?', [$email]) === false) {
                return $email;
            }
        }
        $placeholder = 'whmcs-client-' . $clientId . '@import.invalid';
        $this->warn(sprintf('Client #%d: %s already signs in to another account; the client got %s, change it on the client page.', $clientId, $login ?: $account, $placeholder));

        return $placeholder;
    }

    /**
     * WHMCS 8+: the owner login of each client of a batch (the oldest owner link).
     *
     * @param list<int> $clientIds
     *
     * @return array<int, array<string, mixed>>
     */
    private function ownerLogins(array $clientIds): array
    {
        if (!$this->source->hasTable('tblusers')) {
            return [];
        }
        $out = [];
        foreach ($this->source->rows('SELECT uc.client_id, u.* FROM tblusers_clients uc JOIN tblusers u ON u.id = uc.auth_user_id WHERE uc.owner = 1 AND uc.client_id IN (' . implode(',', $clientIds) . ') ORDER BY uc.id') as $row) {
            $out[(int) $row['client_id']] ??= $row;
        }

        return $out;
    }

    /** @return array<int, list<int>> owner login => the accounts it owns, oldest first */
    private function owned(): array
    {
        if ($this->owned === null) {
            $this->owned = [];
            if ($this->source->hasTable('tblusers')) {
                foreach ($this->source->rows('SELECT auth_user_id, client_id FROM tblusers_clients WHERE owner = 1 ORDER BY client_id') as $row) {
                    $this->owned[(int) $row['auth_user_id']][] = (int) $row['client_id'];
                }
            }
        }

        return $this->owned;
    }

    /** @return array<string, int> lower-case email => client id, for the emails clients sign in with */
    private function logins(): array
    {
        if ($this->logins === null) {
            $this->logins = [];
            foreach ($this->db->fetchAllAssociative('SELECT id, email FROM client WHERE email IS NOT NULL') as $row) {
                $this->logins[strtolower((string) $row['email'])] = (int) $row['id'];
            }
        }

        return $this->logins;
    }

    /**
     * @param list<int> $clientIds
     *
     * @return array<int, array<int, string>> client => field => value
     */
    private function customValues(array $clientIds): array
    {
        if (!$this->source->hasTable('tblcustomfieldsvalues')) {
            return [];
        }
        $out = [];
        $sql = "SELECT v.relid, v.fieldid, v.value, f.fieldtype, f.fieldname FROM tblcustomfieldsvalues v JOIN tblcustomfields f ON f.id = v.fieldid
                WHERE f.type = 'client' AND v.relid IN (" . implode(',', $clientIds) . ') ORDER BY v.id';
        foreach ($this->source->rows($sql) as $row) {
            $value = (string) $row['value'];
            if ($row['fieldtype'] === 'password' && $value !== '') {
                $value = $this->cipher?->decrypt($value) ?? '';
            }
            if ($this->map->get('customfield', (int) $row['fieldid']) === 0 && $value !== '') {
                $value = $row['fieldname'] . ': ' . $value;
            }
            $out[(int) $row['relid']][(int) $row['fieldid']] = $value;
        }

        return $out;
    }

    /**
     * WHMCS's staff notes (tblnotes), sticky ones first.
     *
     * @param list<int> $clientIds
     *
     * @return array<int, list<string>>
     */
    private function staffNotes(array $clientIds): array
    {
        if (!$this->source->hasTable('tblnotes')) {
            return [];
        }
        $out = [];
        foreach ($this->source->rows('SELECT userid, note, created, sticky FROM tblnotes WHERE userid IN (' . implode(',', $clientIds) . ') ORDER BY sticky DESC, created, id') as $row) {
            $note = trim((string) $row['note']);
            if ($note !== '') {
                $out[(int) $row['userid']][] = '[' . (self::date($row['created'] ?? null) ?? '') . '] ' . $note;
            }
        }

        return $out;
    }

    /** @return array<int, string> WHMCS currency id => code */
    private function currencyCodes(): array
    {
        $out = [];
        foreach ($this->source->rows('SELECT id, code FROM tblcurrencies') as $row) {
            $out[(int) $row['id']] = strtoupper(trim((string) $row['code']));
        }

        return $out;
    }

    private function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    /** @return array{0: string, 1: string} WHMCS's "+44.2071234567" as country code and number */
    public static function phone(string $value): array
    {
        $value = trim($value);
        if (preg_match('/^\+(\d{1,4})\.\s*(.+)$/', $value, $m) === 1) {
            return [$m[1], trim($m[2])];
        }

        return ['', $value];
    }

    /**
     * The locale this install ships for a WHMCS language name ("german") or a locale code ("bg_bg",
     * "sr-RS", "sr", as the Blesta and Upmind reads leave it); null when there is none, or when a bare
     * code fits two locales ("pt").
     */
    public static function locale(string $language): ?string
    {
        $language = strtolower(trim($language));
        if (isset(self::LANGUAGES[$language])) {
            return self::LANGUAGES[$language];
        }
        $code = str_replace('-', '_', $language);
        $found = array_values(array_filter(array_unique(self::LANGUAGES), static fn (string $locale): bool => strtolower($locale) === $code || str_starts_with(strtolower($locale), $code . '_')));

        return count($found) === 1 ? $found[0] : null;
    }

    /** A WHMCS date or time, or null for empty and zero dates. */
    public static function date(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || str_starts_with($value, '0000-00-00') || strtotime($value) === false) {
            return null;
        }

        return date('Y-m-d H:i:s', (int) strtotime($value));
    }

    /**
     * The client notes: WHMCS's notes field, its staff notes and answers of custom fields
     * beyond the 20.
     *
     * @param list<string> $staffNotes
     * @param list<string> $extra
     */
    private static function notes(string $notes, array $staffNotes, array $extra): ?string
    {
        $parts = array_filter([trim($notes), ...$staffNotes, ...$extra], static fn (string $p): bool => $p !== '');

        return $parts === [] ? null : implode("\n\n", $parts);
    }

    private static function cut(mixed $value, int $max): string
    {
        return mb_substr(trim(is_scalar($value) ? (string) $value : ''), 0, $max);
    }
}
