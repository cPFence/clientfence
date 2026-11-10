<?php

declare(strict_types=1);

/**
 * ClientFence's own features on top of FOSSBilling, built into the core (module 'clientfence').
 *
 * 1. Admin area style (widget): neutral avatars, no community/sponsor header buttons.
 *
 * 2. Suspension and termination as WHMCS did them: one rule set in the Orders service
 *    (Order\Service::batchSuspendExpired(), batchCancelSuspended() and
 *    isExemptFromAutoSuspension(); settings on System → Settings → Orders).
 *
 * 3. Domain renewal reminders for staff. After each cron run, every active domain order whose
 *    domain expires within 30 days, within 7 days, or has expired gets one email per stage to
 *    the company address, so free and manually registered domains are renewed in time too.
 *    Sent stages are recorded in client_order_meta (clientfence_domain_reminder = expiry:stage).
 *
 * 4. No updates from FOSSBilling: ClientFence is maintained as its own software, with its own
 *    security and bug fixes, because its features change core behaviour. The
 *    admin "Update" (download of a new release) and installs from the online extension
 *    directory are refused. The manual update (database patches for files copied in by hand)
 *    stays available. FOSSBilling never updates itself from cron.
 *
 * 5. Store gate (a store can stay closed, or open to testers only, while it is being set up):
 *    store_mode decides who may see and use the store (products, categories, cart, promo
 *    codes, checkout, domain search and pricing):
 *      - closed (the default): nobody; staff still create orders in the admin area;
 *      - testers: only signed-in clients in the client group store_testers_group;
 *      - public: everyone, as stock FOSSBilling.
 *    The API dispatcher asks storeGate() before every
 *    guest and client store call listed in STORE_CALLS, so the theme pages and the HTTP API
 *    are all covered. The cart and checkout listeners repeat the check.
 *    Staff change it on the Orders settings page (admin API clientfence_store_update).
 *
 * 6. Store page and preview link. The store's title and subtitle are settings (store_title,
 *    store_subtitle); the theme shows them above the products. A preview link
 *    (/order?preview=<store_preview_token>) opens the store, and sign-up from the checkout
 *    page, to that browser session only, whatever store_mode says, so new-customer orders can
 *    be tested while the store stays closed to everyone else. Nothing links to it; a new token
 *    or an empty one ends every preview session at once, and a session's preview lasts at most
 *    PREVIEW_HOURS. Clients who sign up in a preview session join the store testers group. The
 *    client guest API makes the sign-up check.
 *
 * 7. Payment method cards and payment receipts: in the Invoice module (Invoice/Receipts.php).
 *
 * 8. Order form answers. FOSSBilling 0.8.8 keeps only the cart keys a product type allows
 *    (license and API key products: period and quantity), so the answers to a product's order
 *    form (Form Builder) never reached the order. Product\Service::prepareCartProductConfig()
 *    also allows the keys
 *    orderFormKeys() returns: the product's own form fields, never one of the product's settings
 *    or a key FOSSBilling reads from an order (ORDER_KEYS), and only with text values. The cart
 *    listener checks required and read-only fields on the server for product types that do not
 *    (Servicecustom checks them itself).
 *
 * 9. Support dashboard (widget): staff who cannot see the statistics but work the help desk
 *    (Support Staff, Support Lead) get ticket counts and the open tickets on the admin
 *    dashboard instead of a blank page, in the dashboard's widget slot admin.index.dashboard.
 *
 * 10. Help desk greetings. Each help desk has a greeting next to its signature (Support → Help
 *    desks), and the staff reply box starts with it instead of FOSSBilling's fixed "Hello
 *    <first name>,". {name} is the client's first name; an empty greeting means none. The
 *    greetings are kept in table mod_clientfence_helpdesk. The Support module hands the greeting
 *    to the methods below and adds it to the help desk data staff pages get.
 *
 * 11. Hosting panels live in the Servicehosting module (Servicehosting/Panel/Panels.php), each
 *    an optional feature on the Hosting Plans and Servers page: Enhance is the first. ClientFence
 *    ships its own server manager for each panel (Enhance, library/Server/Manager/Enhance.php,
 *    logic in Servicehosting/Panel/Enhance/), which acts only while its panel is on; the client
 *    area's Hosting accounts pages (/hosting) show each account and its usage, the hosting
 *    service page the server its website really runs on, and staff make hosting plans from the
 *    panel's plans.
 *
 * 12. No licence: ClientFence is free. It is built in (a core module): always on, not an
 *    extension, never switched off.
 *
 * 13. Authorized users live in the Client module (Client/Users.php): logins beside the account
 *    owner's, each with its own password and WHMCS-style permissions on one or more client
 *    accounts, invited by the owner on the Users page (/users). Tables client_user,
 *    client_user_account, client_user_password_reset, client_user_invite,
 *    client_user_invite_log and support_ticket_author. Staff manage them on the Users tab of
 *    the admin client page; every change and sign-in is in the activity log, under the client.
 *
 * 14. Ticket attachments: in the Support module (Support/TicketFiles.php).
 *
 * 15. Two-factor sign-in and Google sign-in live in the Client module (Client/TwoFactor.php,
 *    Client/Google.php; tables client_twofactor, client_google, client_google_signup,
 *    client_google_password). Google sign-in's settings are on the Client settings page.
 *
 * 16. Security → Settings (mod_security_settings): sign-in security (SignInSecurity.php: an email
 *    PIN at sign-in for staff, clients or both, staff sign-in from chosen IP addresses and
 *    countries only, staff password reset; `php console.php clientfence:signin-security-off` for a
 *    lockout) and the config.php security options (SecuritySettings.php: trusted proxies, access
 *    lists, the same browser check).
 */

namespace ClientFence\Mod\Clientfence;

use ClientFence\InformationException;
use ClientFence\InjectionAwareInterface;
use ClientFence\Interfaces\WidgetProviderInterface;

class Service implements InjectionAwareInterface, WidgetProviderInterface
{
    public const DEFAULTS = [
        'store_mode' => 'closed',
        'store_testers_group' => '',
        'store_title' => '',
        'store_subtitle' => '',
        'store_preview_token' => '',
    ];

    /**
     * Where each setting is kept: the module whose settings page shows it (System → Settings), so
     * each page saves its own. A setting not listed is kept in this module's configuration. A page's main form keeps the listed keys it does not
     * send (Extension\Service::setConfig()).
     */
    public const SETTINGS = [
        'store_mode' => 'order',
        'store_testers_group' => 'order',
        'store_title' => 'order',
        'store_subtitle' => 'order',
        'store_preview_token' => 'order',
    ];

    public const STORE_TITLE_MAX = 80;
    public const STORE_SUBTITLE_MAX = 200;

    // Session key of an open preview, and how long a preview lasts in one session.
    public const PREVIEW_SESSION = 'clientfence_store_preview';
    public const PREVIEW_HOURS = 12;

    // Order config keys FOSSBilling's cart, orders and product types read or write. An order
    // form field with one of these names is not kept, so a client cannot set them.
    public const ORDER_KEYS = [
        'id', 'product_id', 'client_id', 'form_id', 'parent_id', 'promo_id', 'order_id', 'client_order_id',
        'period', 'quantity', 'multiple', 'addons', 'price', 'setup_price', 'total', 'discount',
        'discount_price', 'discount_setup', 'currency', 'title', 'type', 'unit', 'plugin', 'config',
        'notes', 'status', 'action', 'meta', 'invoice_option', 'skip_validation', 'group_id',
        'created_at', 'updated_at', 'activated_at', 'expires_at',
        'domain', 'domain_name', 'sld', 'tld', 'register_sld', 'register_tld', 'register_years',
        'transfer_sld', 'transfer_tld', 'transfer_code', 'owndomain_sld', 'owndomain_tld', 'domain_fields', 'transfer_fields',
        'subdomain_base_domain', 'subdomain_sld', 'free_domain', 'free_transfer', 'free_tlds',
        'server_id', 'hosting_plan_id', 'username', 'password', 'password_confirm', 'reseller',
        'ip', 'assigned_ips', 'import', 'license_key', 'validate_ip', 'validate_host',
        'validate_path', 'validate_version', 'api_key', 'filename', 'update_orders',
    ];

    public const STORE_MODES = ['closed', 'testers', 'public'];

    /**
     * Guest and client API calls that show or fill the store, by role, module and method, with
     * what a viewer the store is closed to gets instead: an empty page of results, an empty
     * list, "not found", or an error.
     */
    public const STORE_CALLS = [
        'guest' => [
            'product' => [
                'get_list' => 'page',
                'get_pairs' => 'list',
                'get' => 'not_found',
                // A product's configurable options (Product/Options.php) with their prices.
                'options' => 'list',
                'category_get_list' => 'page',
                // Not category_get_pairs: category names only, and the admin product page loads
                // its Category list through it (an empty list cleared the category on save).
            ],
            'cart' => ['add_item' => 'closed', 'apply_promo' => 'closed'],
            'servicedomain' => ['tlds' => 'list', 'pricing' => 'closed', 'check' => 'closed', 'can_be_transferred' => 'closed'],
            'servicehosting' => ['free_tlds' => 'list'],
        ],
        // Not order_upgradables: it only fills the "Request Upgrade" ticket of an existing order.
        'client' => [
            'cart' => ['checkout' => 'closed'],
        ],
    ];

    // Reminder stages: days before the domain expiry (0 = expired).
    public const DOMAIN_REMINDER_DAYS = [30, 7, 0];

    // Expired domains are reminded about once, and only for this long after the expiry.
    public const DOMAIN_REMINDER_EXPIRED_DAYS = 30;

    public const DOMAIN_REMINDER_META = 'clientfence_domain_reminder';

    // Tables this module owns: schema.json (outside the PHP code, so the pack installer reads it
    // when the module's PHP files are encoded).
    public const SCHEMA_FILE = 'schema.json';

    public const GREETING_MAX = 500;

    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    /**
     * Every staff member may call the module (FOSSBilling refuses a module's admin calls to staff
     * without access to it, and ClientFence shows no permissions to give): each admin call checks
     * its own permission, `manage_settings` (Super Administrators) or the client permissions for
     * the Users tab of the client page.
     */
    public function getModulePermissions(): array
    {
        return [
            'can_always_access' => true,
            'hide_permissions' => true,
            'manage_settings' => [],
        ];
    }

    public function getWidgets(): array
    {
        return [
            [
                'slot' => 'admin.theme.content.before',
                'template' => 'mod_clientfence_admin_style',
            ],
            [
                'slot' => 'admin.index.dashboard',
                'template' => 'mod_clientfence_dashboard_support',
            ],
        ];
    }

    /**
     * The module's tables that are missing; with $create they are made. A new install gets them
     * from install/sql/structure.sql; this repairs an install where one is missing.
     *
     * @return string[]
     */
    public static function ensureTables(\Pimple\Container $di, bool $create): array
    {
        $tables = self::tables();
        $db = $di['em']->getConnection();
        $existing = $db->createSchemaManager()->listTableNames();
        $missing = array_values(array_diff(array_keys($tables), $existing));
        if ($create) {
            foreach ($missing as $table) {
                $db->executeStatement($tables[$table]);
            }
        }

        return $missing;
    }

    /**
     * ClientFence tables kept under their home module's name rather than
     * mod_clientfence_*; schema.json may list only these and mod_clientfence_* tables.
     */
    public const HOME_TABLES = [
        'client_note', 'client_file',
        'support_ticket_file', 'support_ticket_author',
        // ClientFence core patch: ticket secret tables belong to Support.
        'support_ticket_secret', 'support_ticket_secret_event',
        'client_user', 'client_user_account', 'client_user_password_reset', 'client_user_invite', 'client_user_invite_log',
        'client_twofactor', 'client_google', 'client_google_signup', 'client_google_password',
        'client_order_action_failure', 'support_helpdesk_mail', 'support_mail_import',
        'email_queue_reply_to', 'support_ticket_merge', 'client_order_upgrade', 'client_order_upgrade_rule', 'support_ticket_pin',
        'support_ticket_assignment', 'support_ticket_message_rating', 'support_ticket_message_unverified', 'client_marketing_optout', 'email_queue_unsubscribe', 'support_ticket_feedback', 'support_ticket_feedback_request',
        'support_helpdesk_feedback', 'support_helpdesk_hidden', 'support_kb_category_hidden', 'support_escalation_rule', 'support_escalation_applied',
        'invoice_quote', 'invoice_quote_item', 'support_network_issue',
        'product_option_group', 'product_option_group_product', 'product_option', 'product_option_value', 'promo_option',
        'client_order_upgrade_option', 'client_contact', 'product_currency_price', 'email_template_translation', 'product_translation',
        'service_license_log', 'service_license_ban',
    ];

    /**
     * The module's tables: name => CREATE TABLE statement (schema.json).
     *
     * @return array<string, string>
     */
    public static function tables(): array
    {
        $schema = json_decode((string) file_get_contents(__DIR__ . '/' . self::SCHEMA_FILE), true);
        $tables = is_array($schema['tables'] ?? null) ? $schema['tables'] : [];
        foreach ($tables as $name => $sql) {
            $named = preg_match('/^mod_clientfence_[a-z0-9_]+$/', (string) $name) === 1 || in_array($name, self::HOME_TABLES, true);
            if (!$named || !is_string($sql) || !str_starts_with($sql, "CREATE TABLE IF NOT EXISTS `{$name}`")) {
                throw new \ClientFence\Exception('ClientFence: schema.json is damaged; install the pack again.');
            }
        }

        return $tables;
    }

    /**
     * Help desk data for staff with the greeting added (key `greeting`, '' for none). Called by
     * the core patches where the Support module builds help desk data; clients and guests, a
     * missing help desk or an inactive module get the data unchanged.
     */
    public static function withHelpdeskGreeting(\Pimple\Container $di, ?array $helpdesk, ?object $identity): ?array
    {
        if ($helpdesk === null || !isset($helpdesk['id']) || !$identity instanceof \Model_Admin) {
            return $helpdesk;
        }
        try {
            // Once per help desk and request: the staff ticket list asks for every ticket.
            $greeting = \ClientFence\RequestCache::remember($di, 'helpdesk.greeting.' . (int) $helpdesk['id'], static fn (): mixed => $di['em']->getConnection()->fetchOne('SELECT greeting FROM mod_clientfence_helpdesk WHERE helpdesk_id = ?', [(int) $helpdesk['id']]));
        } catch (\Throwable) {
            // Table not made yet (pack installer not run): no greeting rather than a broken page.
            return $helpdesk;
        }
        $helpdesk['greeting'] = $greeting === false ? '' : (string) $greeting;

        return $helpdesk;
    }

    /**
     * Stores a help desk's greeting from the help desk form (core patch in
     * Support\Service::helpdeskUpdate(), after FOSSBilling's manage_helpdesk check). Empty
     * means no greeting.
     */
    public static function saveHelpdeskGreeting(\Pimple\Container $di, int $helpdeskId, mixed $greeting): void
    {
        if ($greeting !== null && !is_string($greeting)) {
            throw new InformationException('The greeting must be text.');
        }
        $greeting = trim(str_replace(["\r\n", "\r"], "\n", (string) $greeting));
        if (mb_strlen($greeting) > self::GREETING_MAX) {
            throw new InformationException('The greeting can be at most :max characters.', [':max' => self::GREETING_MAX]);
        }
        self::ensureTables($di, true);
        \ClientFence\RequestCache::forget($di, 'helpdesk.greeting.');
        $db = $di['em']->getConnection();
        if ($greeting === '') {
            $db->delete('mod_clientfence_helpdesk', ['helpdesk_id' => $helpdeskId]);

            return;
        }
        $db->executeStatement(
            'INSERT INTO mod_clientfence_helpdesk (helpdesk_id, greeting, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE greeting = VALUES(greeting), updated_at = VALUES(updated_at)',
            [$helpdeskId, $greeting, date('Y-m-d H:i:s')]
        );
    }

    /**
     * Forgets a deleted help desk's greeting (core patch in Support\Service::helpdeskRm()).
     */
    public static function deleteHelpdeskGreeting(\Pimple\Container $di, int $helpdeskId): void
    {
        try {
            $di['em']->getConnection()->delete('mod_clientfence_helpdesk', ['helpdesk_id' => $helpdeskId]);
        } catch (\Throwable) {
            // No table yet: nothing to forget.
        }
    }

    public static function onBeforeAdminInstallExtension(\ClientFence\Event $event): void
    {
        throw new InformationException('Installing extensions from the FOSSBilling directory is disabled in ClientFence.');
    }

    public static function onBeforeProductAddedToCart(\ClientFence\Event $event): void
    {
        // Staff Add New Order (Cart\Service::staffOrder()) is not held by the store mode or the order form, as the staff order before it.
        if ($event->getParameters()['staff_order'] ?? false) {
            return;
        }
        $di = $event->getDi();
        if (!self::storeOpenTo($di, self::sessionClientId($di))) {
            throw new InformationException('The store is not available.');
        }
        self::checkOrderForm($di, $event->getParameters());
    }

    /**
     * Required and read-only order form fields, checked on the server for product types that
     * do not check them (Servicecustom has validateCustomForm()). $data is the cart item.
     */
    public static function checkOrderForm(\Pimple\Container $di, array $data): void
    {
        $productService = $di['mod_service']('product');
        $product = $productService->getProductRepository()->find((int) ($data['product_id'] ?? 0));
        if (!$product instanceof \ClientFence\Mod\Product\Entity\Product
            || method_exists($productService->getProductModuleService($product), 'validateCustomForm')) {
            return;
        }
        foreach (self::orderFormFields($di, $product) as $field) {
            $name = (string) ($field['name'] ?? '');
            $value = $data[$name] ?? null;
            $filled = is_array($value) ? $value !== [] : trim((string) $value) !== '';
            // The question as the client read it (Product/Translations.php).
            $field['label'] = \ClientFence\Mod\Product\Translations::text($di, 'field', (int) ($field['id'] ?? 0), 'title', $field['label'] ?? $name);
            // "Required" does not apply to checkboxes in FOSSBilling's form builder.
            if ((int) ($field['required'] ?? 0) === 1 && ($field['type'] ?? '') !== 'checkbox' && !$filled) {
                throw new InformationException('You must fill in all required fields. :field is missing', [':field' => $field['label'] ?? $name]);
            }
            if ((int) ($field['readonly'] ?? 0) === 1 && $filled && $value != ($field['default_value'] ?? '')) {
                throw new InformationException('Field :field is read only. You cannot change its value', [':field' => $field['label'] ?? $name]);
            }
        }
    }

    /**
     * Cart keys a client may set for this product besides the ones its product type allows: the
     * names of the product's order form fields (core patch in Product\Service::
     * prepareCartProductConfig(), which drops every other key). A name is kept only when it is
     * a plain name, not one of the product's settings and not in ORDER_KEYS, and its value in
     * $config is text, a number or a list of them (checkboxes).
     */
    public static function orderFormKeys(\Pimple\Container $di, \ClientFence\Mod\Product\Entity\Product $product, array $config): array
    {
        $productConfig = json_decode($product->getConfig() ?? '', true);
        $productConfig = is_array($productConfig) ? $productConfig : [];
        $keys = [];
        foreach (self::orderFormFields($di, $product) as $field) {
            $name = (string) ($field['name'] ?? '');
            if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $name) !== 1
                || in_array(strtolower($name), self::ORDER_KEYS, true)
                || array_key_exists($name, $productConfig)
                || !array_key_exists($name, $config)) {
                continue;
            }
            $value = $config[$name];
            $plain = static fn ($v): bool => is_string($v) || is_int($v) || is_float($v);
            if ($plain($value) || (is_array($value) && array_is_list($value) && count(array_filter($value, $plain)) === count($value))) {
                $keys[] = $name;
            }
        }

        return $keys;
    }

    /**
     * The fields of the product's order form (Form Builder), or [] when it has none.
     */
    private static function orderFormFields(\Pimple\Container $di, \ClientFence\Mod\Product\Entity\Product $product): array
    {
        $formId = (int) $product->getFormId();
        if ($formId <= 0) {
            return [];
        }
        try {
            $form = $di['mod_service']('formbuilder')->getForm($formId);
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_filter($form['fields'] ?? [], 'is_array'));
    }

    /**
     * A client who signs up in a preview session joins the store testers group, so the test
     * accounts are easy to find and keep seeing the store after the preview ends.
     */
    public static function onAfterClientSignUp(\ClientFence\Event $event): void
    {
        $di = $event->getDi();
        $clientId = (int) ($event->getParameters()['id'] ?? 0);
        self::notify($event, static fn () => Notifications::clientSignedUp($di, $clientId));
        $group = (string) self::config($di)['store_testers_group'];
        if ($clientId <= 0 || !self::previewActive($di) || !ctype_digit($group) || (int) $group <= 0) {
            return;
        }
        $di['em']->getConnection()->update('client', ['client_group_id' => (int) $group], ['id' => $clientId]);
        $di['logger']->info('ClientFence: client #%s signed up in a store preview and joined the clients who can order', $clientId);
    }

    public static function onBeforeClientCheckout(\ClientFence\Event $event): void
    {
        $di = $event->getDi();
        if (!self::storeOpenTo($di, (int) ($event->getParameters()['client_id'] ?? 0))) {
            throw new InformationException('The store is not available.');
        }
    }

    /**
     * Called by the API dispatcher (core patch) before every guest and client call. Returns null
     * when the call may run, or ['result' => ...] with what to return instead; throws for calls
     * that must fail.
     */
    public static function storeGate(\Pimple\Container $di, string $role, string $mod, string $method, object $identity, array $data): ?array
    {
        // PHP method names ignore case, so the dispatcher runs "Get_List" as get_list.
        $deny = self::STORE_CALLS[$role][strtolower($mod)][strtolower($method)] ?? null;
        if ($deny === null) {
            return null;
        }

        $clientId = match ($role) {
            'client' => (int) (method_exists($identity, 'getId') ? $identity->getId() : ($identity->id ?? 0)),
            default => self::sessionClientId($di),
        };
        if (self::storeOpenTo($di, $clientId)) {
            return null;
        }

        return match ($deny) {
            'page' => ['result' => ['pages' => 0, 'page' => 1, 'per_page' => max(1, (int) ($data['per_page'] ?? 100)), 'total' => 0, 'list' => []]],
            'list' => ['result' => []],
            // 404: a product page of a closed store is "not found", not a server error.
            'not_found' => throw new InformationException('Product not found', null, 404),
            default => throw new InformationException('The store is not available.'),
        };
    }

    /**
     * Whether the store is open to this client (null or 0: a visitor who is not signed in).
     */
    public static function storeOpenTo(\Pimple\Container $di, ?int $clientId): bool
    {
        if (self::previewActive($di)) {
            return true;
        }
        $config = self::config($di);
        $mode = (string) $config['store_mode'];
        if ($mode === 'public') {
            return true;
        }
        if ($mode !== 'testers' || !$clientId) {
            return false;
        }
        $group = (string) $config['store_testers_group'];
        if (!ctype_digit($group) || (int) $group <= 0) {
            return false;
        }
        $clientGroup = $di['em']->getConnection()->fetchOne(
            'SELECT client_group_id FROM client WHERE id = ? AND status = ?',
            [$clientId, 'active']
        );

        return $clientGroup !== false && $clientGroup !== null && (int) $clientGroup === (int) $group;
    }

    /**
     * Every ClientFence setting (DEFAULTS for the unset ones), each read from the module that keeps
     * it (SETTINGS).
     */
    public static function config(\Pimple\Container $di): array
    {
        $configs = ['clientfence' => $di['mod']('clientfence')->getConfig()];
        $values = array_diff_key($configs['clientfence'], self::SETTINGS);
        foreach (self::SETTINGS as $key => $owner) {
            $configs[$owner] ??= $di['mod']($owner)->getConfig();
            if (array_key_exists($key, $configs[$owner])) {
                $values[$key] = $configs[$owner][$key];
            }
        }

        return array_merge(self::DEFAULTS, array_filter($values, static fn ($v): bool => $v !== null && $v !== ''));
    }

    /**
     * Saves settings, each in the module that keeps it (SETTINGS); that module's other settings
     * stay. Staff need that module's "manage settings" permission (Extension\Service::setConfig()).
     *
     * @param array<string, string> $changes
     */
    public static function saveSettings(\Pimple\Container $di, array $changes): void
    {
        $byOwner = [];
        foreach ($changes as $key => $value) {
            $byOwner[self::SETTINGS[$key] ?? 'clientfence'][$key] = $value;
        }
        $extension = $di['mod_service']('extension');
        foreach ($byOwner as $owner => $values) {
            $ext = 'mod_' . $owner;
            $extension->setConfig(array_merge($extension->getConfig($ext), $values, ['ext' => $ext]));
        }
    }

    /**
     * Opens the store preview for this browser session when $token is the current preview token.
     */
    public static function enterPreview(\Pimple\Container $di, string $token): bool
    {
        $current = (string) self::config($di)['store_preview_token'];
        if ($current === '' || !hash_equals($current, $token)) {
            return false;
        }
        $di['session']->set(self::PREVIEW_SESSION, ['hash' => hash('sha256', $current), 'at' => time()]);

        return true;
    }

    /**
     * Whether this browser session opened the current preview link less than PREVIEW_HOURS ago.
     */
    public static function previewActive(\Pimple\Container $di): bool
    {
        $current = (string) self::config($di)['store_preview_token'];
        if ($current === '') {
            return false;
        }
        try {
            $preview = $di['session']->get(self::PREVIEW_SESSION);
        } catch (\Throwable) {
            return false;
        }

        return is_array($preview)
            && is_string($preview['hash'] ?? null)
            && hash_equals(hash('sha256', $current), $preview['hash'])
            && is_int($preview['at'] ?? null)
            && $preview['at'] > time() - self::PREVIEW_HOURS * 3600;
    }

    /**
     * Whether sign-up is open to this session although client sign-up is off (core patch in
     * modules/Client/Api/Guest.php create()).
     */
    public static function signupOpen(\Pimple\Container $di): bool
    {
        return self::previewActive($di);
    }

    /**
     * Set by FOSSBilling's sign-up once it checked the CAPTCHA (core patch "one CAPTCHA for
     * sign-up"): the sign-in it makes in the same request (auto login after sign-up) does not
     * ask for one again. Only PHP code sets it; nothing a request sends.
     */
    public static bool $signupCaptchaChecked = false;

    /**
     * Whether a visitor may sign up now: client sign-up on (Settings → Clients), or this session
     * opened the store preview link. The sign-up page, the checkout and Google sign-up follow it.
     */
    public static function registrationOpen(\Pimple\Container $di): bool
    {
        return empty($di['mod_config']('client')['disable_signup']) || self::signupOpen($di);
    }

    private static function sessionClientId(\Pimple\Container $di): int
    {
        return (int) ($di['session']->get('client_id') ?? 0);
    }

    public static function onAfterAdminCronRun(\ClientFence\Event $event): void
    {
        $di = $event->getDi();
        try {
            self::remindDomainRenewals($di);
        } catch (\Throwable $e) {
            // A reminder problem must never break the cron run.
            $di['logger']->error('ClientFence: domain renewal reminders failed: %s', $e->getMessage());
        }
        try {
            Health::runIfDue($di);
        } catch (\Throwable $e) {
            $di['logger']->error('ClientFence: system health checks failed: %s', $e->getMessage());
        }
        try {
            Backups::runIfDue($di);
        } catch (\Throwable $e) {
            $di['logger']->error('ClientFence: the database backup failed: %s', $e->getMessage());
        }
        try {
            \ClientFence\Mod\Servicehosting\Panel\StatusCheck::runIfDue($di);
        } catch (\Throwable $e) {
            $di['logger']->error('ClientFence: hosting status checks failed: %s', $e->getMessage());
        }
        try {
            Updates::runIfDue($di);
        } catch (\Throwable $e) {
            $di['logger']->error('ClientFence: the release check failed: %s', $e->getMessage());
        }
        try {
            \ClientFence\Mod\Order\Upgrades::sweep($di);
        } catch (\Throwable $e) {
            $di['logger']->error('ClientFence: paid upgrades could not be checked: %s', $e->getMessage());
        }
        try {
            // Once a day, when set (Order\CancelRequests).
            \ClientFence\Mod\Order\CancelRequests::run($di);
        } catch (\Throwable $e) {
            $di['logger']->error('ClientFence: cancellation requests failed: %s', $e->getMessage());
        }
        try {
            Notifications::daily($di);
        } catch (\Throwable $e) {
            $di['logger']->error('ClientFence: the daily notifications failed: %s', $e->getMessage());
        }
    }

    // ----- the bell (Notifications.php): what happened, for staff and clients ---------------------

    public static function onAfterClientOrderCreate(\ClientFence\Event $event): void
    {
        self::notify($event, static fn (\Pimple\Container $di, int $id) => Notifications::orderCreated($di, $id));
    }

    public static function onAfterAdminOrderActivate(\ClientFence\Event $event): void
    {
        self::notify($event, static fn (\Pimple\Container $di, int $id) => Notifications::orderChanged($di, $id, 'order_active'));
    }

    public static function onAfterAdminOrderSuspend(\ClientFence\Event $event): void
    {
        self::notify($event, static fn (\Pimple\Container $di, int $id) => Notifications::orderChanged($di, $id, 'order_suspended'));
    }

    public static function onAfterAdminOrderUnsuspend(\ClientFence\Event $event): void
    {
        self::notify($event, static fn (\Pimple\Container $di, int $id) => Notifications::orderChanged($di, $id, 'order_unsuspended'));
    }

    public static function onAfterAdminOrderCancel(\ClientFence\Event $event): void
    {
        self::notify($event, static fn (\Pimple\Container $di, int $id) => Notifications::orderChanged($di, $id, 'order_canceled'));
    }

    public static function onAfterAdminOrderDelete(\ClientFence\Event $event): void
    {
        self::notify($event, static fn (\Pimple\Container $di, int $id) => Notifications::orderDeleted($di, $id));
    }

    public static function onBeforeAdminInvoiceDelete(\ClientFence\Event $event): void
    {
        self::notify($event, static fn (\Pimple\Container $di, int $id) => Notifications::invoiceDeleted($di, $id));
    }

    public static function onAfterOrderUpgrade(\ClientFence\Event $event): void
    {
        self::notify($event, static fn (\Pimple\Container $di, int $id) => Notifications::orderUpgraded($di, $id));
    }

    public static function onAfterAdminInvoiceApprove(\ClientFence\Event $event): void
    {
        self::notify($event, static fn (\Pimple\Container $di, int $id) => Notifications::invoiceIssued($di, $id));
    }

    public static function onAfterAdminInvoiceReminderSent(\ClientFence\Event $event): void
    {
        self::notify($event, static fn (\Pimple\Container $di, int $id) => Notifications::invoiceReminded($di, $id));
    }

    public static function onAfterAdminInvoicePaymentReceived(\ClientFence\Event $event): void
    {
        self::notify($event, static fn (\Pimple\Container $di, int $id) => Notifications::invoicePaid($di, $id));
    }

    public static function onAfterClientOpenTicket(\ClientFence\Event $event): void
    {
        self::notify($event, static fn (\Pimple\Container $di, int $id) => Notifications::ticketFromClient($di, $id, true));
    }

    public static function onAfterClientReplyTicket(\ClientFence\Event $event): void
    {
        self::notify($event, static fn (\Pimple\Container $di, int $id) => Notifications::ticketFromClient($di, $id, false));
    }

    public static function onAfterAdminReplyTicket(\ClientFence\Event $event): void
    {
        self::notify($event, static fn (\Pimple\Container $di, int $id) => Notifications::ticketFromStaff($di, $id));
    }

    /** Runs a notification for the event's record (`id`); a failure is logged, never thrown. */
    private static function notify(\ClientFence\Event $event, callable $send): void
    {
        $di = $event->getDi();
        try {
            $send($di, (int) (((array) $event->getParameters())['id'] ?? 0));
        } catch (\Throwable $e) {
            $di['logger']->warning('ClientFence: notification for %s not sent: %s', $event->getName(), $e->getMessage());
        }
    }

    /**
     * The invoice line of an upgrade or downgrade was paid (an invoice "hook call" item, see
     * Order\Upgrades): the change is applied. Its failures go to Orders > Failed actions, so the
     * invoice line itself never fails.
     */
    public static function onAfterClientOrderUpgradePaid(\ClientFence\Event $event): void
    {
        // The invoice line's parameters arrive as an object (json_decode without assoc).
        $params = (array) $event->getParameters();
        $id = (int) ($params['upgrade_id'] ?? 0);
        if ($id > 0) {
            \ClientFence\Mod\Order\Upgrades::onPaid($event->getDi(), $id);
        }
    }

    /**
     * Emails staff about domains that expire soon or have expired, once per stage.
     *
     * @return int the number of domains in the email (0 when nothing was sent)
     */
    public static function remindDomainRenewals(\Pimple\Container $di, bool $send = true): int
    {
        $db = $di['em']->getConnection();
        $now = time();
        $rows = $db->fetchAllAssociative(
            "SELECT o.id, o.price, o.currency, o.invoice_option, o.period, d.sld, d.tld, d.expires_at, r.registrar,
                    CONCAT_WS(' ', c.first_name, c.last_name) AS client, c.company
               FROM client_order o
               JOIN service_domain d ON d.id = o.service_id
               JOIN client c ON c.id = o.client_id
               LEFT JOIN tld_registrar r ON r.id = d.tld_registrar_id
              WHERE o.service_type = 'domain' AND o.status = 'active' AND d.expires_at IS NOT NULL
                AND d.expires_at BETWEEN ? AND ?
              ORDER BY d.expires_at",
            [
                date('Y-m-d H:i:s', $now - self::DOMAIN_REMINDER_EXPIRED_DAYS * 86400),
                date('Y-m-d H:i:s', $now + max(self::DOMAIN_REMINDER_DAYS) * 86400),
            ]
        );

        $due = [];
        foreach ($rows as $row) {
            $daysLeft = (int) floor((strtotime((string) $row['expires_at']) - $now) / 86400);
            $stage = null;
            foreach (self::DOMAIN_REMINDER_DAYS as $days) {
                if ($daysLeft < $days || ($days === 0 && $daysLeft <= 0)) {
                    $stage = $days;
                }
            }
            if ($stage === null) {
                continue;
            }
            $key = substr((string) $row['expires_at'], 0, 10) . ':' . $stage;
            $sent = $db->fetchOne(
                'SELECT 1 FROM client_order_meta WHERE client_order_id = ? AND name = ? AND value = ?',
                [$row['id'], self::DOMAIN_REMINDER_META, $key]
            );
            if ($sent === false) {
                $due[] = $row + ['days_left' => $daysLeft, 'key' => $key];
            }
        }
        if ($due === [] || !$send) {
            return count($due);
        }

        $system = $di['mod_service']('system');
        $to = (string) $system->getParamValue('company_email');
        $items = '';
        foreach ($due as $row) {
            $domain = $row['sld'] . $row['tld'];
            $when = $row['days_left'] < 0
                ? 'expired ' . -$row['days_left'] . ' day(s) ago'
                : ($row['days_left'] === 0 ? 'expires today' : 'expires in ' . $row['days_left'] . ' day(s)');
            $paid = (float) $row['price'] > 0 && $row['invoice_option'] === 'issue-invoice' && !empty($row['period']);
            $auto = strcasecmp((string) $row['registrar'], 'Dynadot') === 0;
            $action = $paid
                ? 'The client gets a renewal invoice (' . $row['price'] . ' ' . $row['currency'] . ') 10 days before expiry. '
                    . ($auto ? 'Once it is paid, Dynadot renews the domain automatically; check that it did.' : 'Once it is paid, renew the domain at the registrar.')
                : 'No invoice is issued for this domain. Renew it at the registrar' . ($auto ? ' (Dynadot)' : '') . ' if it should be kept.';
            $items .= '<li><strong>' . htmlspecialchars($domain) . '</strong>, ' . htmlspecialchars($when)
                . ' (' . substr((string) $row['expires_at'], 0, 10) . ')<br>Client: ' . htmlspecialchars(trim($row['client'] . ($row['company'] ? ', ' . $row['company'] : '')))
                . '<br>Registrar: ' . htmlspecialchars((string) ($row['registrar'] ?: 'none')) . '<br>' . htmlspecialchars($action)
                . '<br><a href="' . htmlspecialchars($di['url']->adminLink('order/manage/' . $row['id'])) . '">Order #' . (int) $row['id'] . '</a></li>';
        }
        $subject = count($due) === 1
            ? 'Domain renewal: ' . $due[0]['sld'] . $due[0]['tld']
            : 'Domain renewals: ' . count($due) . ' domains';
        $content = '<p>These domains need attention:</p><ul>' . $items . '</ul><p>Sent by the ClientFence module of the billing portal.</p>';

        $company = (string) ($di['mod_service']('system')->getCompany()['name'] ?? '') ?: 'ClientFence';
        $di['mod_service']('email')->sendMail($to, $to, $subject, $content, $company, $company);

        $stamp = date('Y-m-d H:i:s', $now);
        foreach ($due as $row) {
            $db->insert('client_order_meta', [
                'client_order_id' => $row['id'],
                'name' => self::DOMAIN_REMINDER_META,
                'value' => $row['key'],
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
        }
        $di['logger']->info('ClientFence: domain renewal reminder sent for %s domain(s)', count($due));

        return count($due);
    }
}
