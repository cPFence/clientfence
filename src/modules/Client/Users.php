<?php

declare(strict_types=1);

namespace ClientFence\Mod\Client;

use ClientFence\Mod\Support\TicketFiles;
use ClientFence\InformationException;
use ClientFence\Security\AuthenticationRequiredException;

/**
 * Authorized users (like the WHMCS "Users" of a client account).
 *
 * The client account keeps its own login: that is the owner. An authorized user is a separate
 * login (table client_user) linked to one or more client accounts (client_user_account)
 * with a set of permissions, named as in WHMCS so a WHMCS import keeps them. A link marked owner
 * may do everything the owner does.
 *
 * Sign-in: the client login page (core patch "authorized users: sign-in") tries the client
 * login first, then authorize() here. The session then holds FOSSBilling's `client_id` (the
 * account) and SESSION: {user_id, client_id, version}. Everything FOSSBilling does for the
 * account works as before; the gates below narrow it:
 *   - every client API call (core patch on the API dispatcher) passes checkCall(): CALLS names
 *     the permission each call needs, '*' any signed-in user, and a call that is not listed
 *     is for the owner only (a new FOSSBilling call stays closed until it is listed here), and
 *     no user changes the owner's sign-in (OWNER_LOGIN_CALLS, OWNER_LOGIN_FIELDS);
 *   - every client page (core patch on \ClientFence\AppClient) passes pageDenied() (PAGES);
 *   - filterResult() trims the profile and dashboard to what the permissions show.
 * context() checks the user on every request: a user who was removed, switched off, unlinked
 * or whose password was reset (session_version) ends the
 * session. Staff "Login as client" and a later client login drop the user context.
 *
 * Password reset: the client "Forgot password" page (core patch "authorized users: password
 * reset") sends a user a link to /password-reset/<token> (valid RESET_MINUTES; only
 * the token's sha256 is stored).
 *
 * The owner (and an owner link) manages the account's users on /users: invitations by
 * email (client_user_invite, single use, INVITE_DAYS, sha256 only), permissions, removal.
 * The invitation page /invite/<token> lets a new user choose a name and password, or an
 * existing user accept with the user's password. A user keeps a name and password on
 * /profile. Tickets and replies a user writes record the user
 * (support_ticket_author), so the owner and staff see who wrote them.
 */
final class Users
{
    public const SESSION = 'clientfence_user';
    public const RESET_MINUTES = 60;
    public const STATUSES = ['active', 'disabled'];
    public const INVITE_DAYS = 7;
    public const INVITES_PER_HOUR = 20;

    /** WHMCS permission names, in WHMCS order. quotes and affiliates have no FOSSBilling feature yet. */
    public const PERMISSIONS = [
        'profile', 'contacts', 'products', 'manageproducts', 'productsso', 'domains', 'managedomains',
        'invoices', 'quotes', 'tickets', 'affiliates', 'emails', 'orders',
    ];

    /** What each permission lets a user do, as the Users page words it (translated in the theme). */
    public const PERMISSION_LABELS = [
        'profile' => 'Change the account details',
        'contacts' => 'View and manage contacts',
        'products' => 'View services',
        'manageproducts' => 'Manage services (passwords, email accounts)',
        'productsso' => 'Sign in to control panels and webmail',
        'domains' => 'View domains',
        'managedomains' => 'Manage domain settings',
        'invoices' => 'View and pay invoices',
        'quotes' => 'View and accept quotes',
        'tickets' => 'View and open support tickets',
        'affiliates' => 'View the affiliate account',
        'emails' => 'View emails sent to the account',
        'orders' => 'Place new orders',
    ];

    /** The permissions the Users page offers (the others unlock nothing here yet; an import keeps them). */
    public const PERMISSIONS_SHOWN = ['profile', 'contacts', 'products', 'manageproducts', 'productsso', 'domains', 'managedomains', 'invoices', 'tickets', 'emails', 'orders'];

    /**
     * Client API calls an authorized user may make: module => method => permission ('*': any user;
     * a list: any of them). Calls a page makes while it renders need the page's permission or '*',
     * or the page fails (PAGES).
     */
    public const CALLS = [
        // The bell (Clientfence/Notifications.php): every user, each item only if its page is theirs.
        // The tax rate: the order page shows it in the total (mod_order_product).
        'clientfence' => ['notifications_get' => '*', 'notifications_read' => '*', 'notifications_dismiss' => '*', 'tax' => '*'],
        'cart' => ['checkout' => 'orders'],
        'client' => [
            'balance_get_list' => 'invoices', 'balance_get_total' => ['invoices', 'orders'], 'is_taxable' => '*',
            'user_session' => '*', 'user_switch' => '*', 'user_profile_update' => '*', 'user_password_change' => '*',
            // A user's own two-factor sign-in (TwoFactor.php), not the account's.
            'twofactor_status' => '*', 'twofactor_setup' => '*', 'twofactor_enable' => '*', 'twofactor_disable' => '*',
            'twofactor_backup' => '*',
            // A user's own Google sign-in link (Google.php).
            'google_status' => '*', 'google_link' => '*', 'google_unlink' => '*',
            // The account's contacts (Contacts.php).
            'contacts_get_list' => 'contacts', 'contacts_save' => 'contacts', 'contacts_delete' => 'contacts',
            // users_* (the Users page) are not listed: the owner and owner links only.
        ],
        'email' => ['get_list' => 'emails', 'get' => 'emails', 'resend' => 'emails', 'delete' => 'emails'],
        'index' => ['get_dashboard' => '*'],
        'invoice' => [
            'get_list' => 'invoices', 'get' => 'invoices', 'renewal_invoice' => 'invoices', 'funds_invoice' => 'invoices', 'apply_credit' => 'invoices',
            'transaction_get_list' => 'invoices', 'get_tax_rate' => '*',
            'payment_details' => 'invoices', 'receipt_send' => 'invoices', 'statement' => 'invoices', 'statement_pdf' => 'invoices',
            'quote_get_list' => 'quotes',
        ],
        'order' => ['get_list' => 'products', 'get' => 'products', 'addons' => 'products', 'service' => 'products', 'upgradables' => 'products', 'upgrade_options' => 'products', 'upgrade_options_quote' => 'products', 'upgrade' => 'orders', 'delete' => 'orders'],
        'profile' => ['get' => '*', 'logout' => '*', 'update' => 'profile'],
        'serviceapikey' => ['reset' => 'manageproducts', 'update_ips' => 'manageproducts'],
        'servicecustom' => ['call' => 'manageproducts'],
        'servicedomain' => [
            'sync' => 'domains', 'update_nameservers' => 'managedomains', 'update_contacts' => 'managedomains',
            'enable_privacy_protection' => 'managedomains', 'disable_privacy_protection' => 'managedomains',
            'get_transfer_code' => 'managedomains', 'lock' => 'managedomains', 'unlock' => 'managedomains',
        ],
        'servicedownloadable' => ['send_file' => 'products'],
        'servicehosting' => [
            'accounts' => 'products', 'usage' => 'products', 'server' => 'products', 'backups' => 'products',
            'domains' => 'products', 'emails' => 'products', 'email_get' => 'products', 'email_settings' => 'products',
            'email_create' => 'manageproducts', 'email_update' => 'manageproducts',
            'email_password' => 'manageproducts', 'email_delete' => 'manageproducts',
            'email_autoresponder' => 'manageproducts',
            'email_webmail' => 'productsso',
            'change_password' => 'manageproducts', 'hp_get_pairs' => 'products', 'get_login_url' => 'productsso',
        ],
        'servicelicense' => ['reset' => 'manageproducts'],
        'support' => [
            // Help desk names: the service page's request forms list them while it renders.
            'ticket_get_list' => 'tickets', 'ticket_get' => 'tickets', 'helpdesk_get_pairs' => '*',
            'ticket_create' => 'tickets', 'ticket_reply' => 'tickets', 'ticket_close' => 'tickets',
            // Ticket attachments (Support/TicketFiles.php).
            'ticket_file_upload' => 'tickets', 'ticket_file_discard' => 'tickets',
            // Reply stars (Support/TicketFeedback.php).
            'ticket_message_rate' => 'tickets',
            // The network issues banner (Support/NetworkIssues.php): every user of the account.
            'network_issue_alerts' => '*',
        ],
    ];

    /** Calls even an owner link never makes: they act on the owner's own login. */
    public const OWNER_LOGIN_CALLS = [
        'profile' => ['change_password', 'destroy_sessions', 'api_key_get', 'api_key_reset'],
        // The owner's first password and profile after a Google sign-up (Google.php).
        'client' => ['google_password', 'google_profile_update'],
    ];

    /**
     * Fields of a call no user may change, owner links included: the account email is the
     * owner's sign-in name (a new one plus "Forgot password" would hand over the owner login).
     */
    public const OWNER_LOGIN_FIELDS = ['profile' => ['update' => ['email']]];

    /**
     * Client pages by path (the longest matching prefix wins) => permission; null: any user;
     * 'owner': owner links only. A page not listed is open to any signed-in user (its API calls
     * are still checked).
     */
    public const PAGES = [
        'support/kb' => null,
        'network-status' => null,
        'support/contact-us' => null,
        'support' => 'tickets',
        'order/service' => 'products',
        'order' => 'orders',
        'cart' => 'orders',
        'invoice' => 'invoices',
        'invoice/quotes' => 'quotes',
        'quote' => 'quotes',
        'client/balance' => 'invoices',
        'receipt' => 'invoices',
        'ticket-file' => 'tickets',
        'client/profile' => 'profile',
        'email' => 'emails',
        'hosting' => 'products',
        'servicedownloadable' => 'products',
        'users' => 'owner',
        'contacts' => 'contacts',
    ];

    /** Profile fields every user sees (the account's name); the rest needs `profile`, the balance `invoices`. */
    private const PROFILE_SHARED = ['id', 'first_name', 'last_name', 'company', 'currency', 'lang', 'timezone', 'status', 'created_at'];

    /** @var array<string, ?array> context() result per session state, for the length of a request */
    private static array $cache = [];

    /**
     * The signed-in authorized user, or null for an owner session (or nobody signed in):
     * {user_id, client_id, owner, permissions, email, first_name, last_name}. A user who may
     * no longer use the account ends the session (AuthenticationRequiredException).
     */
    public static function context(\Pimple\Container $di): ?array
    {
        $session = $di['session'];
        $state = $session->get(self::SESSION);
        if (!is_array($state)) {
            return null;
        }
        $clientId = (int) $session->get('client_id');
        if ($clientId <= 0 || (int) ($state['client_id'] ?? 0) !== $clientId) {
            // The account changed without us (a sign-up, staff "Login as client"): not this user's.
            $session->delete(self::SESSION);

            return null;
        }
        $key = sprintf('%d:%d:%d', (int) ($state['user_id'] ?? 0), $clientId, (int) ($state['version'] ?? 0));
        if (!array_key_exists($key, self::$cache)) {
            self::$cache[$key] = self::load($di, (int) ($state['user_id'] ?? 0), $clientId, (int) ($state['version'] ?? 0));
        }
        if (self::$cache[$key] === null) {
            unset(self::$cache[$key]);
            $session->delete(self::SESSION);
            $session->destroy('client');

            throw new AuthenticationRequiredException('client');
        }

        return self::$cache[$key];
    }

    /**
     * Forgets the per-request cache (after a change to users or links).
     */
    public static function forget(): void
    {
        self::$cache = [];
    }

    private static function load(\Pimple\Container $di, int $userId, int $clientId, int $version): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        try {
            $row = $di['em']->getConnection()->fetchAssociative(
                'SELECT u.email, u.first_name, u.last_name, u.status, u.session_version, l.owner, l.permissions
                 FROM client_user u
                 JOIN client_user_account l ON l.user_id = u.id AND l.client_id = ?
                 JOIN client c ON c.id = l.client_id AND c.status = ?
                 WHERE u.id = ?',
                [$clientId, \Model_Client::ACTIVE, $userId]
            );
        } catch (\Throwable) {
            return null;
        }
        if ($row === false || $row['status'] !== 'active' || (int) $row['session_version'] !== $version) {
            return null;
        }

        return [
            'user_id' => $userId,
            'client_id' => $clientId,
            'owner' => (bool) $row['owner'],
            'permissions' => self::parsePermissions((string) $row['permissions']),
            'email' => (string) $row['email'],
            'first_name' => (string) $row['first_name'],
            'last_name' => (string) $row['last_name'],
        ];
    }

    /**
     * Checks an authorized user's email and password (the client login page, after the client
     * login failed). Returns {user_id, client_id, version} for the account the user used last,
     * or null. Takes the same time whether or not the user exists.
     */
    public static function authorize(\Pimple\Container $di, string $email, string $password): ?array
    {
        try {
            $user = $di['em']->getConnection()->fetchAssociative(
                'SELECT id, pass, status, session_version FROM client_user WHERE email = ?',
                [$email]
            );
        } catch (\Throwable) {
            $user = false;
        }
        if ($user === false || $user['status'] !== 'active') {
            $di['password']->dummyVerify($password);

            return null;
        }
        if (!$di['password']->verify($password, (string) $user['pass'])) {
            return null;
        }
        $db = $di['em']->getConnection();
        if ($di['password']->needsRehash((string) $user['pass'])) {
            $db->update('client_user', ['pass' => $di['password']->hashIt($password), 'updated_at' => date('Y-m-d H:i:s')], ['id' => (int) $user['id']]);
        }

        return self::loginFor($di, (int) $user['id']);
    }

    /**
     * An active user's sign-in (after the password, or Google sign-in): {user_id, client_id,
     * version} for the active account the user used last, or null.
     */
    public static function loginFor(\Pimple\Container $di, int $userId): ?array
    {
        $db = $di['em']->getConnection();
        $version = $db->fetchOne('SELECT session_version FROM client_user WHERE id = ? AND status = ?', [$userId, 'active']);
        $clientId = $version === false ? false : $db->fetchOne(
            'SELECT l.client_id FROM client_user_account l JOIN client c ON c.id = l.client_id
             WHERE l.user_id = ? AND c.status = ?
             ORDER BY l.last_login_at IS NULL, l.last_login_at DESC, l.id LIMIT 1',
            [$userId, \Model_Client::ACTIVE]
        );
        if ($clientId === false) {
            return null;
        }

        return ['user_id' => $userId, 'client_id' => (int) $clientId, 'version' => (int) $version];
    }

    /**
     * Marks the session as the authorized user's (after FOSSBilling set `client_id`), or as the
     * owner's when $user is null.
     */
    public static function startSession(\Pimple\Container $di, ?array $user, string $event = 'signed in'): void
    {
        $session = $di['session'];
        if ($user === null) {
            $session->delete(self::SESSION);

            return;
        }
        $session->set(self::SESSION, ['user_id' => $user['user_id'], 'client_id' => $user['client_id'], 'version' => $user['version']]);
        $now = date('Y-m-d H:i:s');
        $db = $di['em']->getConnection();
        $db->update('client_user', ['last_login_at' => $now, 'last_ip' => substr((string) $di['request']->getClientIp(), 0, 45)], ['id' => $user['user_id']]);
        $db->update('client_user_account', ['last_login_at' => $now], ['user_id' => $user['user_id'], 'client_id' => $user['client_id']]);
        $di['logger']->setChannel('security')->info('Authorized user #%s signed in to client #%s', $user['user_id'], $user['client_id']);
        self::activity($di, [$user['client_id']], sprintf('Authorized user %s %s', self::userEmail($db, $user['user_id']), $event));
    }

    /**
     * Moves the signed-in user to another account the user is linked to.
     */
    public static function switchTo(\Pimple\Container $di, int $clientId): void
    {
        $context = self::context($di);
        if ($context === null) {
            throw new InformationException('Only authorized users can switch accounts.', null, 403);
        }
        $version = $di['em']->getConnection()->fetchOne(
            'SELECT u.session_version FROM client_user u
             JOIN client_user_account l ON l.user_id = u.id AND l.client_id = ?
             JOIN client c ON c.id = l.client_id AND c.status = ?
             WHERE u.id = ? AND u.status = ?',
            [$clientId, \Model_Client::ACTIVE, $context['user_id'], 'active']
        );
        if ($version === false) {
            throw new InformationException('Account not found.', null, 404);
        }
        $session = $di['session'];
        $session->regenerateId();
        $session->set('client_id', $clientId);
        self::startSession($di, ['user_id' => $context['user_id'], 'client_id' => $clientId, 'version' => (int) $version], 'switched to this account');
    }

    /**
     * What the client area shows about the signed-in user: null for the owner, else the user,
     * the account, the permissions and every account the user can switch to.
     */
    public static function sessionView(\Pimple\Container $di): ?array
    {
        $context = self::context($di);
        if ($context === null) {
            return null;
        }
        $accounts = $di['em']->getConnection()->fetchAllAssociative(
            'SELECT c.id, c.first_name, c.last_name, c.company FROM client_user_account l
             JOIN client c ON c.id = l.client_id AND c.status = ?
             WHERE l.user_id = ? ORDER BY c.company, c.first_name, c.last_name, c.id',
            [\Model_Client::ACTIVE, $context['user_id']]
        );
        $name = static fn (array $c): string => trim((string) $c['company']) !== '' ? trim((string) $c['company']) : trim($c['first_name'] . ' ' . $c['last_name']);
        $list = array_map(static fn (array $c): array => ['id' => (int) $c['id'], 'name' => $name($c), 'current' => (int) $c['id'] === $context['client_id']], $accounts);
        $current = array_values(array_filter($list, static fn (array $a): bool => $a['current']))[0] ?? ['id' => $context['client_id'], 'name' => ''];

        return [
            'user' => ['id' => $context['user_id'], 'email' => $context['email'], 'first_name' => $context['first_name'], 'last_name' => $context['last_name']],
            'account' => ['id' => $current['id'], 'name' => $current['name']],
            'owner' => $context['owner'],
            'permissions' => $context['owner'] ? self::PERMISSIONS : $context['permissions'],
            'accounts' => $list,
        ];
    }

    /**
     * Whether the user may use a permission (an owner link may use them all; 'owner' is only
     * theirs).
     */
    public static function allows(array $context, string $permission): bool
    {
        return $context['owner'] || in_array($permission, $context['permissions'], true);
    }

    /**
     * API dispatcher gate (core patch): refuses a client API call the signed-in authorized user
     * may not make. Owner sessions and calls for another identity pass unchanged.
     */
    public static function checkCall(\Pimple\Container $di, object $identity, string $mod, string $method, array $data = []): void
    {
        $context = self::context($di);
        if ($context === null || (int) ($identity->id ?? 0) !== $context['client_id']) {
            return;
        }
        $mod = strtolower($mod);
        $method = strtolower($method);
        if (in_array($method, self::OWNER_LOGIN_CALLS[$mod] ?? [], true)) {
            $allowed = false;
        } elseif ($context['owner']) {
            $allowed = true;
        } else {
            $permission = self::CALLS[$mod][$method] ?? null;
            $allowed = $permission === '*' || array_filter((array) $permission, static fn (string $p): bool => self::allows($context, $p)) !== [];
        }
        if (!$allowed) {
            throw new InformationException('You do not have permission to do this. Ask the account owner for access.', null, 403);
        }
        foreach (self::OWNER_LOGIN_FIELDS[$mod][$method] ?? [] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== (string) ($identity->{$field} ?? '')) {
                throw new InformationException('Only the account owner can change the account email.', null, 403);
            }
        }
    }

    /**
     * API dispatcher (core patch): trims what a client call returns to the signed-in user's
     * permissions (profile, orders and dashboard), and names the users who wrote ticket messages (for
     * the owner too). Anything else is returned unchanged.
     */
    public static function filterResult(\Pimple\Container $di, object $identity, string $mod, string $method, mixed $result): mixed
    {
        $mod = strtolower($mod);
        $method = strtolower($method);
        if ($mod === 'support' && $method === 'ticket_get' && is_array($result)) {
            return TicketFiles::withFiles($di, self::withTicketAuthors($di, $result, false));
        }
        if (!is_array($result) || !(($mod === 'profile' && $method === 'get') || ($mod === 'index' && $method === 'get_dashboard') || ($mod === 'order' && in_array($method, ['get', 'get_list', 'addons'], true)))) {
            return $result;
        }
        $context = self::context($di);
        if ($context === null || (int) ($identity->id ?? 0) !== $context['client_id']) {
            return $result;
        }
        if ($mod === 'profile') {
            return self::trimProfile($context, $result);
        }
        if ($mod === 'order') {
            if ($method === 'get') {
                $result['client'] = self::trimProfile($context, $result['client']);
            } elseif ($method === 'addons') {
                foreach ($result as $key => $order) {
                    $result[$key]['client'] = self::trimProfile($context, $order['client']);
                }
            } else {
                foreach ($result['list'] as $key => $order) {
                    $result['list'][$key]['client'] = self::trimProfile($context, $order['client']);
                }
            }

            return $result;
        }
        if (is_array($result['profile'] ?? null)) {
            $result['profile'] = self::trimProfile($context, $result['profile']);
        }
        if (!self::allows($context, 'tickets')) {
            $result['tickets'] = null;
            $result['recent_tickets'] = [];
        }
        if (!self::allows($context, 'invoices')) {
            $result['invoices'] = null;
        }
        if (!self::allows($context, 'products')) {
            $result['orders'] = null;
            $result['recent_orders'] = [];
        }
        foreach ($result['recent_orders'] as $key => $order) {
            $result['recent_orders'][$key]['client'] = self::trimProfile($context, $order['client']);
        }

        return $result;
    }

    private static function trimProfile(array $context, array $profile): array
    {
        if (self::allows($context, 'profile')) {
            return $profile;
        }
        $keep = self::PROFILE_SHARED;
        if (self::allows($context, 'invoices')) {
            $keep[] = 'balance';
        }

        return array_intersect_key($profile, array_flip($keep));
    }

    /**
     * Client page gate (core patch on \ClientFence\AppClient): the permission the signed-in user lacks
     * for the page at $url, or null when the page opens.
     */
    public static function pageDenied(\Pimple\Container $di, string $url): ?string
    {
        $context = self::context($di);
        if ($context === null) {
            return null;
        }
        $path = strtolower(trim($url, '/'));
        $best = null;
        foreach (self::PAGES as $prefix => $permission) {
            if (($path === $prefix || str_starts_with($path, $prefix . '/')) && ($best === null || strlen($prefix) > strlen($best))) {
                $best = $prefix;
            }
        }
        $permission = $best === null ? null : self::PAGES[$best];

        return $permission === null || self::allows($context, $permission) ? null : $permission;
    }

    /**
     * "Forgot password" for an authorized user (core patch: the client reset page, when no
     * client has the email; rate limits and CAPTCHA already passed). Sends the reset link;
     * false when there is no active user with that email.
     */
    public static function requestReset(\Pimple\Container $di, string $email): bool
    {
        try {
            $db = $di['em']->getConnection();
            $user = $db->fetchAssociative('SELECT id, email, first_name, last_name FROM client_user WHERE email = ? AND status = ?', [$email, 'active']);
        } catch (\Throwable) {
            return false;
        }
        if ($user === false) {
            return false;
        }
        self::sendReset($di, $user);
        self::activity($di, self::linkedClients($db, (int) $user['id']), sprintf('Authorized user %s asked for a password reset link', $user['email']));

        return true;
    }

    /**
     * Staff send a user of the account a password reset link (Users tab of the client page).
     */
    public static function staffSendReset(\Pimple\Container $di, int $userId, int $clientId, int $adminId): void
    {
        $db = $di['em']->getConnection();
        $user = $db->fetchAssociative(
            'SELECT u.id, u.email, u.first_name, u.last_name, u.status FROM client_user u
             JOIN client_user_account l ON l.user_id = u.id AND l.client_id = ? WHERE u.id = ?',
            [$clientId, $userId]
        );
        if ($user === false) {
            throw new InformationException('User not found.', null, 404);
        }
        if ($user['status'] !== 'active') {
            throw new InformationException('This user is switched off: switch the user on first.');
        }
        self::sendReset($di, $user);
        self::activity($di, [$clientId], sprintf('Password reset link sent to authorized user %s by staff', $user['email']), $adminId);
    }

    /**
     * A new reset link for the user (older ones stop working), sent by email.
     */
    private static function sendReset(\Pimple\Container $di, array $user): void
    {
        $db = $di['em']->getConnection();
        $token = bin2hex(random_bytes(32));
        $now = time();
        $db->transactional(function () use ($db, $user, $token, $now): void {
            $db->executeStatement('UPDATE client_user SET id = id WHERE id = ?', [(int) $user['id']]);
            $db->executeStatement('DELETE FROM client_user_password_reset WHERE user_id = ? OR expires_at < ?', [(int) $user['id'], date('Y-m-d H:i:s', $now)]);
            $db->insert('client_user_password_reset', [
                'token_hash' => hash('sha256', $token),
                'user_id' => (int) $user['id'],
                'created_at' => date('Y-m-d H:i:s', $now),
                'expires_at' => date('Y-m-d H:i:s', $now + self::RESET_MINUTES * 60),
            ]);
        });
        $di['mod_service']('email')->sendTemplate([
            'code' => 'mod_client_user_password_reset',
            'to' => (string) $user['email'],
            'to_name' => trim($user['first_name'] . ' ' . $user['last_name']),
            'first_name' => (string) $user['first_name'],
            'token' => $token,
            'minutes' => self::RESET_MINUTES,
            // Sent at once, like FOSSBilling's client reset email (the queue retries a failure).
            'send_now' => true,
        ]);
        $di['logger']->setChannel('security')->info('Authorized user #%s password reset email sent', $user['id']);
    }

    /**
     * Whether a reset link is still valid (the reset page asks before showing its form).
     */
    public static function resetValid(\Pimple\Container $di, string $token): bool
    {
        return self::resetUser($di, $token) !== null;
    }

    /**
     * Sets a new password from a reset link. Signs the user out everywhere.
     */
    public static function resetPassword(\Pimple\Container $di, string $token, mixed $password, mixed $confirm, string $ip): void
    {
        $di['rate_limiter']->consumeOrThrow('client_password_reset_confirm_post_ip', $ip);
        $di['validator']->passwordsMatch(['password' => $password, 'password_confirm' => $confirm]);
        $di['validator']->isPasswordStrong($password);
        // ClientFence core patch (0.8.9 port): a reset cannot race another reset or password change.
        $db = $di['em']->getConnection();
        $userId = self::resetUser($di, $token);
        if ($userId === null) {
            throw new InformationException('The link has expired or you have already reset your password.');
        }
        $db->transactional(function () use ($di, $db, $token, $password, $userId): void {
            $db->executeStatement('UPDATE client_user SET id = id WHERE id = ?', [$userId]);
            $current = $db->fetchOne(
                'SELECT r.user_id FROM client_user_password_reset r JOIN client_user u ON u.id = r.user_id AND u.status = ? WHERE r.token_hash = ? AND r.expires_at >= ?' . \ClientFence\Doctrine\RowLock::suffix($db),
                ['active', hash('sha256', $token), date('Y-m-d H:i:s')]
            );
            if ($current === false) {
                throw new InformationException('The link has expired or you have already reset your password.');
            }
            $db->executeStatement(
                'UPDATE client_user SET pass = ?, session_version = session_version + 1, updated_at = ? WHERE id = ?',
                [$di['password']->hashIt((string) $password), date('Y-m-d H:i:s'), $userId]
            );
            $db->delete('client_user_password_reset', ['user_id' => $userId]);
        });
        self::forget();
        $di['logger']->setChannel('security')->info('Authorized user #%s password reset completed from IP %s', $userId, $ip);
        self::activity($di, self::linkedClients($db, $userId), sprintf('Authorized user %s set a new password from a reset link', self::userEmail($db, $userId)));
    }

    private static function resetUser(\Pimple\Container $di, string $token): ?int
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $userId = $di['em']->getConnection()->fetchOne(
            'SELECT r.user_id FROM client_user_password_reset r JOIN client_user u ON u.id = r.user_id AND u.status = ?
             WHERE r.token_hash = ? AND r.expires_at >= ?',
            ['active', hash('sha256', $token), date('Y-m-d H:i:s')]
        );

        return $userId === false ? null : (int) $userId;
    }

    /**
     * API dispatcher (core patch, staff calls): names the users who wrote ticket messages.
     */
    public static function filterAdminResult(\Pimple\Container $di, string $mod, string $method, mixed $result): mixed
    {
        if (strtolower($mod) === 'support' && strtolower($method) === 'ticket_get' && is_array($result)) {
            return TicketFiles::withFiles($di, self::withTicketAuthors($di, $result, true));
        }

        return $result;
    }

    /**
     * Records the signed-in user as the author of the newest client message of a ticket
     * (Service::onAfterClientOpenTicket and onAfterClientReplyTicket). Owner sessions record
     * nothing.
     */
    public static function recordTicketAuthor(\Pimple\Container $di, int $ticketId): void
    {
        try {
            $context = self::context($di);
        } catch (\Throwable) {
            return;
        }
        if ($context === null) {
            return;
        }
        $db = $di['em']->getConnection();
        $messageId = $db->fetchOne(
            'SELECT id FROM support_ticket_message WHERE support_ticket_id = ? AND client_id = ? ORDER BY id DESC LIMIT 1',
            [$ticketId, $context['client_id']]
        );
        if ($messageId === false) {
            return;
        }
        $db->executeStatement(
            'INSERT IGNORE INTO support_ticket_author (message_id, ticket_id, client_id, user_id, name, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [(int) $messageId, $ticketId, $context['client_id'], $context['user_id'], mb_substr(trim($context['first_name'] . ' ' . $context['last_name']) ?: $context['email'], 0, 201), date('Y-m-d H:i:s')]
        );
    }

    /**
     * A ticket (support_ticket_get) with each message a user wrote named after the user:
     * author.name is the user's name and author.clientfence_user is true; staff also see that it
     * was an authorized user.
     */
    private static function withTicketAuthors(\Pimple\Container $di, array $ticket, bool $staff): array
    {
        $ids = array_values(array_filter(array_map(static fn ($m): int => is_array($m) ? (int) ($m['id'] ?? 0) : 0, $ticket['messages'] ?? [])));
        if ($ids === []) {
            return $ticket;
        }
        try {
            $names = $di['em']->getConnection()->fetchAllKeyValue(
                'SELECT message_id, name FROM support_ticket_author WHERE ticket_id = ? AND message_id IN (' . implode(',', $ids) . ')',
                [(int) ($ticket['id'] ?? 0)]
            );
        } catch (\Throwable) {
            return $ticket;
        }
        foreach ($ticket['messages'] as $i => $message) {
            $name = $names[(int) ($message['id'] ?? 0)] ?? null;
            if ($name === null || ($message['author']['role'] ?? '') !== 'client') {
                continue;
            }
            $ticket['messages'][$i]['author']['name'] = $staff ? $name . ' (' . __trans('authorized user') . ')' : $name;
            $ticket['messages'][$i]['author']['clientfence_user'] = true;
        }

        return $ticket;
    }

    /**
     * Invites an email to the account with permissions (the Users page). A pending invitation
     * to the same email is replaced. Sends the invitation email.
     */
    public static function invite(\Pimple\Container $di, int $clientId, mixed $email, mixed $permissions, ?int $adminId = null): void
    {
        $email = $di['tools']->validateAndSanitizeEmail(is_string($email) ? trim($email) : '', true, false);
        $permissions = self::validPermissions($permissions);
        $db = $di['em']->getConnection();
        $client = $db->fetchAssociative('SELECT id, email, first_name, last_name, company FROM client WHERE id = ?', [$clientId]);
        if ($client === false) {
            throw new InformationException('Client not found.', null, 404);
        }
        if (strcasecmp($email, (string) $client['email']) === 0) {
            throw new InformationException('This is the account\'s own email.');
        }
        if ($db->fetchOne('SELECT l.id FROM client_user_account l JOIN client_user u ON u.id = l.user_id WHERE l.client_id = ? AND u.email = ?', [$clientId, $email]) !== false) {
            throw new InformationException('This user already has access to the account.');
        }
        self::checkInviteRate($db, $clientId);
        $db->delete('client_user_invite', ['client_id' => $clientId, 'email' => $email]);
        $token = bin2hex(random_bytes(32));
        $now = time();
        $db->insert('client_user_invite', [
            'client_id' => $clientId,
            'email' => $email,
            'permissions' => implode(',', $permissions),
            'token_hash' => hash('sha256', $token),
            'created_at' => date('Y-m-d H:i:s', $now),
            'expires_at' => date('Y-m-d H:i:s', $now + self::INVITE_DAYS * 86400),
        ]);
        self::sendInvite($di, $client, $email, $token);
        $di['logger']->info('Authorized user invitation sent for client #%s', $clientId);
        self::activity($di, [$clientId], sprintf('Authorized user invitation sent to %s by %s (%s)', $email, self::by($di, $adminId), self::describe(false, $permissions)), $adminId);
    }

    /**
     * Sends a pending invitation again, with a new link (the old one stops working).
     */
    public static function resendInvite(\Pimple\Container $di, int $clientId, int $inviteId, ?int $adminId = null): void
    {
        $db = $di['em']->getConnection();
        $invite = $db->fetchAssociative('SELECT id, email FROM client_user_invite WHERE id = ? AND client_id = ?', [$inviteId, $clientId]);
        $client = $db->fetchAssociative('SELECT id, email, first_name, last_name, company FROM client WHERE id = ?', [$clientId]);
        if ($invite === false || $client === false) {
            throw new InformationException('Invitation not found.', null, 404);
        }
        self::checkInviteRate($db, $clientId);
        $token = bin2hex(random_bytes(32));
        $now = time();
        $db->update('client_user_invite', [
            'token_hash' => hash('sha256', $token),
            'created_at' => date('Y-m-d H:i:s', $now),
            'expires_at' => date('Y-m-d H:i:s', $now + self::INVITE_DAYS * 86400),
        ], ['id' => $inviteId]);
        self::sendInvite($di, $client, (string) $invite['email'], $token);
        self::activity($di, [$clientId], sprintf('Authorized user invitation to %s sent again by %s', $invite['email'], self::by($di, $adminId)), $adminId);
    }

    public static function cancelInvite(\Pimple\Container $di, int $clientId, int $inviteId, ?int $adminId = null): void
    {
        $db = $di['em']->getConnection();
        $email = $db->fetchOne('SELECT email FROM client_user_invite WHERE id = ? AND client_id = ?', [$inviteId, $clientId]);
        if ($email === false || $db->delete('client_user_invite', ['id' => $inviteId, 'client_id' => $clientId]) === 0) {
            throw new InformationException('Invitation not found.', null, 404);
        }
        self::activity($di, [$clientId], sprintf('Authorized user invitation to %s cancelled by %s', $email, self::by($di, $adminId)), $adminId);
    }

    /**
     * The account's pending invitations (expired ones are removed).
     */
    public static function invitesFor(\Pimple\Container $di, int $clientId): array
    {
        $db = $di['em']->getConnection();
        $db->executeStatement('DELETE FROM client_user_invite WHERE expires_at < ?', [date('Y-m-d H:i:s')]);
        $rows = $db->fetchAllAssociative('SELECT id, email, permissions, created_at, expires_at FROM client_user_invite WHERE client_id = ? ORDER BY created_at DESC', [$clientId]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'email' => (string) $r['email'],
            'permissions' => self::parsePermissions((string) $r['permissions']),
            'created_at' => $r['created_at'],
            'expires_at' => $r['expires_at'],
        ], $rows);
    }

    /**
     * What the Users page shows: the users, the pending invitations and the permissions offered.
     * `self` is the signed-in user (an owner link), who cannot change or remove itself.
     */
    public static function usersPage(\Pimple\Container $di, int $clientId): array
    {
        $context = self::context($di);

        return [
            'users' => self::listFor($di, $clientId),
            'invites' => self::invitesFor($di, $clientId),
            'permissions' => array_map(static fn (string $p): array => ['name' => $p, 'label' => self::PERMISSION_LABELS[$p]], self::PERMISSIONS_SHOWN),
            'self' => $context['user_id'] ?? null,
        ];
    }

    /**
     * What the Users tab of the admin client page shows: the account's users (with the user's
     * sign-in status, which holds for every account), the pending invitations, the permissions
     * offered and the labels of all of them.
     */
    public static function staffPage(\Pimple\Container $di, int $clientId): array
    {
        if ($di['em']->getConnection()->fetchOne('SELECT id FROM client WHERE id = ?', [$clientId]) === false) {
            throw new InformationException('Client not found.', null, 404);
        }

        return [
            'owner_twofactor' => TwoFactor::isOn($di, 'client', $clientId),
            'owner_google' => Google::linkOf($di, 'client', $clientId),
            'owner_own_password' => Google::ownPassword($di, $clientId),
            'users' => self::listFor($di, $clientId),
            'invites' => self::invitesFor($di, $clientId),
            'permissions' => array_map(static fn (string $p): array => ['name' => $p, 'label' => self::PERMISSION_LABELS[$p]], self::PERMISSIONS_SHOWN),
            'labels' => self::PERMISSION_LABELS,
        ];
    }

    /**
     * The owner changes a user's permissions on the account (never the owner flag or status).
     */
    public static function ownerUpdate(\Pimple\Container $di, int $clientId, int $userId, mixed $permissions): void
    {
        self::refuseSelf($di, $userId);
        self::update($di, $userId, $clientId, ['permissions' => $permissions ?? []]);
    }

    public static function ownerRemove(\Pimple\Container $di, int $clientId, int $userId): void
    {
        self::refuseSelf($di, $userId);
        self::remove($di, $userId, $clientId);
    }

    private static function refuseSelf(\Pimple\Container $di, int $userId): void
    {
        if ((self::context($di)['user_id'] ?? null) === $userId) {
            throw new InformationException('You cannot change your own access.');
        }
    }

    /**
     * At most INVITES_PER_HOUR invitation emails per account and hour (sends and resends, logged in
     * client_user_invite_log whatever became of the invitation). Counts this one.
     */
    private static function checkInviteRate(\Doctrine\DBAL\Connection $db, int $clientId): void
    {
        $now = time();
        $db->executeStatement('DELETE FROM client_user_invite_log WHERE sent_at < ?', [date('Y-m-d H:i:s', $now - 86400)]);
        $recent = (int) $db->fetchOne('SELECT COUNT(*) FROM client_user_invite_log WHERE client_id = ? AND sent_at > ?', [$clientId, date('Y-m-d H:i:s', $now - 3600)]);
        if ($recent >= self::INVITES_PER_HOUR) {
            throw new InformationException('Too many invitations. Please try again later.', null, 429);
        }
        $db->insert('client_user_invite_log', ['client_id' => $clientId, 'sent_at' => date('Y-m-d H:i:s', $now)]);
    }

    private static function sendInvite(\Pimple\Container $di, array $client, string $email, string $token): void
    {
        $di['mod_service']('email')->sendTemplate([
            'code' => 'mod_client_user_invite',
            'to' => $email,
            'account' => self::accountName($client),
            'token' => $token,
            'days' => self::INVITE_DAYS,
            // Sent at once, as WHMCS does (the queue retries a failure).
            'send_now' => true,
        ]);
    }

    private static function accountName(array $client): string
    {
        $company = trim((string) ($client['company'] ?? ''));

        return $company !== '' ? $company : trim(($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? ''));
    }

    private static function inviteRow(\Pimple\Container $di, string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $row = $di['em']->getConnection()->fetchAssociative(
            'SELECT i.id, i.client_id, i.email, i.permissions, c.email AS client_email, c.first_name, c.last_name, c.company
             FROM client_user_invite i JOIN client c ON c.id = i.client_id AND c.status = ?
             WHERE i.token_hash = ? AND i.expires_at >= ?',
            [\Model_Client::ACTIVE, hash('sha256', $token), date('Y-m-d H:i:s')]
        );

        return $row === false ? null : $row;
    }

    /**
     * What the invitation page shows, or null for a link that is not valid (any more):
     * {email, account, existing: the email already has a user, taken: a client signs in with it}.
     */
    public static function inviteView(\Pimple\Container $di, string $token): ?array
    {
        $invite = self::inviteRow($di, $token);
        if ($invite === null) {
            return null;
        }
        $db = $di['em']->getConnection();

        return [
            'email' => (string) $invite['email'],
            'account' => self::accountName($invite),
            'existing' => $db->fetchOne('SELECT id FROM client_user WHERE email = ?', [$invite['email']]) !== false,
            'taken' => $db->fetchOne('SELECT id FROM client WHERE email = ?', [$invite['email']]) !== false,
        ];
    }

    /**
     * Accepts an invitation: an existing user confirms with the user's password; a new email
     * gets a user with `first_name`, `last_name` and `password` (`password_confirm`). Returns
     * the session to start ({user_id, client_id, version}).
     */
    public static function acceptInvite(\Pimple\Container $di, string $token, array $data, string $ip): array
    {
        $di['rate_limiter']->consumeOrThrow('client_password_reset_confirm_post_ip', $ip);
        $invite = self::inviteRow($di, $token);
        if ($invite === null) {
            throw new InformationException('This invitation has expired or was already used.');
        }
        $db = $di['em']->getConnection();
        $user = $db->fetchAssociative('SELECT id, pass, status FROM client_user WHERE email = ?', [$invite['email']]);
        $now = date('Y-m-d H:i:s');
        if ($user !== false) {
            $di['rate_limiter']->consumeOrThrow('api_login', $ip);
            if ($user['status'] !== 'active' || !is_string($data['password'] ?? null) || !$di['password']->verify($data['password'], (string) $user['pass'])) {
                throw new InformationException('Please check your login details.', [], 401);
            }
            $userId = (int) $user['id'];
        } else {
            if ($db->fetchOne('SELECT id FROM client WHERE email = ?', [$invite['email']]) !== false) {
                throw new InformationException('This email already signs in to a client account. Ask the account owner to invite another address.');
            }
            $first = self::name($data['first_name'] ?? '');
            if ($first === '') {
                throw new InformationException('Enter your first name.');
            }
            $di['validator']->passwordsMatch(['password' => $data['password'] ?? null, 'password_confirm' => $data['password_confirm'] ?? null]);
            $di['validator']->isPasswordStrong($data['password'] ?? null);
            $db->insert('client_user', [
                'email' => (string) $invite['email'],
                'first_name' => $first,
                'last_name' => self::name($data['last_name'] ?? ''),
                'pass' => $di['password']->hashIt((string) $data['password']),
                'status' => 'active',
                'session_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $userId = (int) $db->lastInsertId();
        }
        if ($db->fetchOne('SELECT id FROM client_user_account WHERE user_id = ? AND client_id = ?', [$userId, (int) $invite['client_id']]) === false) {
            $db->insert('client_user_account', [
                'user_id' => $userId,
                'client_id' => (int) $invite['client_id'],
                'owner' => 0,
                'permissions' => implode(',', self::parsePermissions((string) $invite['permissions'])),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $db->delete('client_user_invite', ['id' => (int) $invite['id']]);
        self::forget();
        $di['logger']->info('Authorized user #%s accepted an invitation to client #%s', $userId, $invite['client_id']);
        self::activity($di, [(int) $invite['client_id']], sprintf('%s accepted the invitation and is now an authorized user (%s)', $invite['email'], $user !== false ? 'existing user' : 'new user'));

        return ['user_id' => $userId, 'client_id' => (int) $invite['client_id'], 'version' => (int) $db->fetchOne('SELECT session_version FROM client_user WHERE id = ?', [$userId])];
    }

    /**
     * The signed-in user's own name (/profile).
     */
    public static function updateOwnProfile(\Pimple\Container $di, array $data): void
    {
        $context = self::requireUser($di);
        $first = self::name($data['first_name'] ?? '');
        if ($first === '') {
            throw new InformationException('Enter your first name.');
        }
        $di['em']->getConnection()->update('client_user', ['first_name' => $first, 'last_name' => self::name($data['last_name'] ?? ''), 'updated_at' => date('Y-m-d H:i:s')], ['id' => $context['user_id']]);
        self::forget();
    }

    /**
     * The signed-in user's own password: the current one, then the new one twice. Other
     * sessions of the user end; this one stays.
     */
    public static function changeOwnPassword(\Pimple\Container $di, array $data, string $ip): void
    {
        $context = self::requireUser($di);
        $di['rate_limiter']->consumeOrThrow('profile_password_change_ip', $ip);
        $di['rate_limiter']->consumeOrThrow('profile_password_change_account', 'clientfence-user-' . $context['user_id']);
        $db = $di['em']->getConnection();
        $db->transactional(function () use ($di, $db, $context, $data): void {
            $db->executeStatement('UPDATE client_user SET id = id WHERE id = ?', [$context['user_id']]);
            $pass = (string) $db->fetchOne('SELECT pass FROM client_user WHERE id = ?', [$context['user_id']]);
            if (!is_string($data['current_password'] ?? null) || !$di['password']->verify($data['current_password'], $pass)) {
                throw new InformationException('The current password is not correct.');
            }
            $di['validator']->passwordsMatch(['password' => $data['new_password'] ?? null, 'password_confirm' => $data['confirm_password'] ?? null]);
            $di['validator']->isPasswordStrong($data['new_password'] ?? null);
            $db->executeStatement(
                'UPDATE client_user SET pass = ?, session_version = session_version + 1, updated_at = ? WHERE id = ?',
                [$di['password']->hashIt((string) $data['new_password']), date('Y-m-d H:i:s'), $context['user_id']]
            );
            $db->delete('client_user_password_reset', ['user_id' => $context['user_id']]);
        });
        $state = $di['session']->get(self::SESSION);
        $state['version'] = (int) $db->fetchOne('SELECT session_version FROM client_user WHERE id = ?', [$context['user_id']]);
        $di['session']->set(self::SESSION, $state);
        self::forget();
        $di['logger']->setChannel('security')->info('Authorized user #%s changed the password from IP %s', $context['user_id'], $ip);
        self::activity($di, self::linkedClients($db, $context['user_id']), sprintf('Authorized user %s changed the password', $context['email']));
    }

    private static function requireUser(\Pimple\Container $di): array
    {
        $context = self::context($di);
        if ($context === null) {
            throw new InformationException('Only authorized users have this profile.', null, 403);
        }

        return $context;
    }

    /**
     * The users of a client account, for staff and the owner's Users page.
     */
    public static function listFor(\Pimple\Container $di, int $clientId): array
    {
        $rows = $di['em']->getConnection()->fetchAllAssociative(
            'SELECT u.id, u.email, u.first_name, u.last_name, u.status, u.last_login_at, l.owner, l.permissions, l.last_login_at AS account_login_at, l.created_at
             FROM client_user_account l JOIN client_user u ON u.id = l.user_id
             WHERE l.client_id = ? ORDER BY u.email',
            [$clientId]
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'email' => (string) $r['email'],
            'first_name' => (string) $r['first_name'],
            'last_name' => (string) $r['last_name'],
            'status' => (string) $r['status'],
            'owner' => (bool) $r['owner'],
            'permissions' => self::parsePermissions((string) $r['permissions']),
            'last_login_at' => $r['account_login_at'],
            'created_at' => $r['created_at'],
            'twofactor' => TwoFactor::isOn($di, 'user', (int) $r['id']),
            'google' => Google::linkOf($di, 'user', (int) $r['id']) !== null,
        ], $rows);
    }

    /**
     * Gives a user access to a client account. A new email makes a new user (password required);
     * an existing user's own details and password stay as they are. Returns the user id.
     */
    public static function add(\Pimple\Container $di, int $clientId, array $data): int
    {
        $email = $di['tools']->validateAndSanitizeEmail((string) ($data['email'] ?? ''), true, false);
        $db = $di['em']->getConnection();
        if ($db->fetchOne('SELECT id FROM client WHERE id = ?', [$clientId]) === false) {
            throw new InformationException('Client not found.', null, 404);
        }
        $permissions = self::validPermissions($data['permissions'] ?? []);
        $owner = !empty($data['owner']) ? 1 : 0;
        $now = date('Y-m-d H:i:s');
        $userId = $db->fetchOne('SELECT id FROM client_user WHERE email = ?', [$email]);
        if ($userId === false) {
            if ($db->fetchOne('SELECT id FROM client WHERE email = ?', [$email]) !== false) {
                throw new InformationException('A client account already signs in with this email.');
            }
            $di['validator']->isPasswordStrong($data['password'] ?? '');
            $db->insert('client_user', [
                'email' => $email,
                'first_name' => self::name($data['first_name'] ?? ''),
                'last_name' => self::name($data['last_name'] ?? ''),
                'pass' => $di['password']->hashIt((string) $data['password']),
                'status' => 'active',
                'session_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $userId = (int) $db->lastInsertId();
        } elseif ($db->fetchOne('SELECT id FROM client_user_account WHERE user_id = ? AND client_id = ?', [(int) $userId, $clientId]) !== false) {
            throw new InformationException('This user already has access to the account.');
        }
        $db->insert('client_user_account', [
            'user_id' => (int) $userId,
            'client_id' => $clientId,
            'owner' => $owner,
            'permissions' => implode(',', $permissions),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::forget();
        $di['logger']->info('Authorized user #%s added to client #%s', $userId, $clientId);

        return (int) $userId;
    }

    /**
     * Changes a user's access to one account (`permissions`, `owner`) and the user's `status`.
     */
    public static function update(\Pimple\Container $di, int $userId, int $clientId, array $data, ?int $adminId = null): void
    {
        $db = $di['em']->getConnection();
        $before = $db->fetchAssociative(
            'SELECT u.email, u.status, l.owner, l.permissions FROM client_user_account l JOIN client_user u ON u.id = l.user_id
             WHERE l.user_id = ? AND l.client_id = ?',
            [$userId, $clientId]
        );
        if ($before === false) {
            throw new InformationException('User not found.', null, 404);
        }
        if (array_key_exists('status', $data) && !in_array($data['status'], self::STATUSES, true)) {
            throw new InformationException('Unknown status.');
        }
        $now = date('Y-m-d H:i:s');
        $link = ['updated_at' => $now];
        if (array_key_exists('permissions', $data)) {
            $link['permissions'] = implode(',', self::validPermissions($data['permissions']));
        }
        if (array_key_exists('owner', $data)) {
            $link['owner'] = !empty($data['owner']) ? 1 : 0;
        }
        $db->update('client_user_account', $link, ['user_id' => $userId, 'client_id' => $clientId]);
        if (array_key_exists('status', $data)) {
            $db->update('client_user', ['status' => $data['status'], 'updated_at' => $now], ['id' => $userId]);
        }
        self::forget();
        $di['logger']->info('Authorized user #%s access to client #%s changed', $userId, $clientId);

        $owner = (bool) ($link['owner'] ?? $before['owner']);
        $permissions = self::parsePermissions((string) ($link['permissions'] ?? $before['permissions']));
        if ($owner !== (bool) $before['owner'] || $permissions !== self::parsePermissions((string) $before['permissions'])) {
            self::activity($di, [$clientId], sprintf('Authorized user %s: access changed by %s to %s', $before['email'], self::by($di, $adminId), self::describe($owner, $permissions)), $adminId);
        }
        if (array_key_exists('status', $data) && $data['status'] !== $before['status']) {
            $what = $data['status'] === 'active' ? 'switched on' : 'switched off';
            self::activity($di, self::linkedClients($db, $userId), sprintf('Authorized user %s %s by %s (sign-in on every account)', $before['email'], $what, self::by($di, $adminId)), $adminId);
        }
    }

    /**
     * Removes a user's access to an account; a user left without accounts is deleted. The
     * user's sessions end.
     */
    public static function remove(\Pimple\Container $di, int $userId, int $clientId, ?int $adminId = null): void
    {
        $db = $di['em']->getConnection();
        $email = self::userEmail($db, $userId);
        if ($db->delete('client_user_account', ['user_id' => $userId, 'client_id' => $clientId]) === 0) {
            throw new InformationException('User not found.', null, 404);
        }
        self::activity($di, [$clientId], sprintf('Authorized user %s removed from the account by %s', $email, self::by($di, $adminId)), $adminId);
        self::dropOrphan($db, $userId);
        self::forget();
        $di['logger']->info('Authorized user #%s removed from client #%s', $userId, $clientId);
    }

    /**
     * A deleted client takes its links with it (Service::onAfterAdminClientDelete).
     */
    public static function clientDeleted(\Pimple\Container $di, int $clientId): void
    {
        try {
            $db = $di['em']->getConnection();
            $users = $db->fetchFirstColumn('SELECT user_id FROM client_user_account WHERE client_id = ?', [$clientId]);
            $db->delete('client_user_account', ['client_id' => $clientId]);
            $db->delete('client_user_invite', ['client_id' => $clientId]);
            $db->delete('client_user_invite_log', ['client_id' => $clientId]);
            $db->delete('support_ticket_author', ['client_id' => $clientId]);
            TwoFactor::forgetLogin($db, 'client', $clientId);
            Google::forgetLogin($db, 'client', $clientId);
            TicketFiles::forgetClient($db, $clientId);
            Contacts::forgetClient($db, $clientId);
        } catch (\Throwable) {
            // No tables yet: nothing to remove.
            return;
        }
        foreach ($users as $userId) {
            self::dropOrphan($db, (int) $userId);
        }
        self::forget();
    }

    private static function dropOrphan(\Doctrine\DBAL\Connection $db, int $userId): void
    {
        if ($db->fetchOne('SELECT COUNT(*) FROM client_user_account WHERE user_id = ?', [$userId]) == 0) {
            $db->delete('client_user_password_reset', ['user_id' => $userId]);
            $db->delete('client_user', ['id' => $userId]);
            TwoFactor::forgetLogin($db, 'user', $userId);
            Google::forgetLogin($db, 'user', $userId);
        } else {
            $db->executeStatement('UPDATE client_user SET session_version = session_version + 1 WHERE id = ?', [$userId]);
        }
    }

    /**
     * An entry in FOSSBilling's activity log (Activity → Event log; filter by client) for each
     * account, with the staff member when staff did it. A failed entry never stops the action.
     *
     * @param list<int> $clientIds
     */
    public static function activity(\Pimple\Container $di, array $clientIds, string $message, ?int $adminId = null): void
    {
        try {
            $activity = $di['mod_service']('activity');
            foreach (array_unique($clientIds) as $clientId) {
                $activity->logEvent(['client_id' => (int) $clientId, 'admin_id' => $adminId, 'priority' => 6, 'message' => $message]);
            }
        } catch (\Throwable) {
            // The log is a record, not part of the action.
        }
    }

    /**
     * Who made a change, for the activity log: staff (the entry names the staff member), the
     * signed-in authorized user (an owner link) or the account owner.
     */
    private static function by(\Pimple\Container $di, ?int $adminId): string
    {
        if ($adminId !== null) {
            return 'staff';
        }
        try {
            $context = self::context($di);
        } catch (\Throwable) {
            $context = null;
        }

        return $context === null ? 'the account owner' : 'authorized user ' . $context['email'];
    }

    /**
     * Access in words for the activity log: "full access" or the permissions' labels.
     */
    private static function describe(bool $owner, mixed $permissions): string
    {
        if ($owner) {
            return 'full access';
        }
        $labels = array_map(static fn (string $p): string => self::PERMISSION_LABELS[$p], self::validPermissions($permissions));

        return $labels === [] ? 'no permissions' : implode(', ', $labels);
    }

    /** @return list<int> the accounts a user is linked to */
    public static function linkedClients(\Doctrine\DBAL\Connection $db, int $userId): array
    {
        return array_map('intval', $db->fetchFirstColumn('SELECT client_id FROM client_user_account WHERE user_id = ?', [$userId]));
    }

    public static function userEmail(\Doctrine\DBAL\Connection $db, int $userId): string
    {
        return (string) $db->fetchOne('SELECT email FROM client_user WHERE id = ?', [$userId]);
    }

    /**
     * @return list<string> known permissions, in PERMISSIONS order
     */
    public static function validPermissions(mixed $permissions): array
    {
        if (is_string($permissions)) {
            $permissions = $permissions === '' ? [] : explode(',', $permissions);
        }
        if (!is_array($permissions)) {
            throw new InformationException('Permissions must be a list.');
        }
        // An empty value is "none" (a form sends permissions[] = '' so that no box ticked still counts).
        $permissions = array_values(array_filter(array_map(static fn ($p): ?string => is_string($p) ? strtolower(trim($p)) : null, $permissions), static fn (?string $p): bool => $p !== ''));
        $unknown = array_diff($permissions, self::PERMISSIONS);
        if ($unknown !== []) {
            throw new InformationException('Unknown permission: :name', [':name' => implode(', ', $unknown)]);
        }

        return array_values(array_intersect(self::PERMISSIONS, $permissions));
    }

    private static function parsePermissions(string $list): array
    {
        return array_values(array_intersect(self::PERMISSIONS, explode(',', $list)));
    }

    private static function name(mixed $value): string
    {
        return mb_substr(trim(is_string($value) ? $value : ''), 0, 100);
    }
}
