<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence;

use ClientFence\Config;
use ClientFence\InformationException;

/**
 * The setup wizard (admin page /clientfence/setup): the basic settings of a new install in a few
 * steps (company and branding, email sending, payments, security and store, a first product).
 *
 * A new install starts with the setting clientfence_setup = pending (install/sql/content.sql): the
 * dashboard then opens the wizard for a Super Administrator until it is finished or skipped.
 * Updated sites have no such setting and never see it unasked; System → Settings opens it on any
 * site. Each step saves through the existing admin APIs (their permission checks apply), merging
 * into module settings instead of replacing them.
 */
final class Setup
{
    public const string STATE = 'clientfence_setup';
    public const array STEPS = ['company', 'email', 'payments', 'security', 'product'];

    /** Top-level addresses of the site besides the modules' client pages (files, API, folders). */
    private const array RESERVED_PATHS = ['api', 'public', 'themes', 'modules', 'library', 'install', 'data', 'vendor',
        'locale', 'uploads', 'cron', 'ipn', 'index', 'robots', 'console', 'load', 'di', 'config', 'sitemap'];

    public static function pending(\Pimple\Container $di): bool
    {
        try {
            return $di['em']->getConnection()->fetchOne('SELECT value FROM setting WHERE param = ?', [self::STATE]) === 'pending';
        } catch (\Throwable) {
            return false;
        }
    }

    /** Marks the wizard as finished (or skipped): it no longer opens by itself. */
    public static function finish(\Pimple\Container $di): void
    {
        $di['mod_service']('system')->setParamValue(self::STATE, 'done');
    }

    /** Whether the signed-in staff member may run the wizard: Super Administrators only. */
    public static function allowed(\Pimple\Container $di): bool
    {
        try {
            $admin = $di['loggedin_admin'];
            $id = is_object($admin) ? (int) ($admin->id ?? 0) : 0;

            return $id > 0 && $di['mod_service']('staff')->isSuperAdministrator($id);
        } catch (\Throwable) {
            return false;
        }
    }

    // ----- email ------------------------------------------------------------------------------

    /**
     * Saves how email is sent: PHP mail (sendmail) or an SMTP server, and the sender. "keep" leaves
     * a SendGrid or custom DSN setting as it is (made in System → Settings → Email). Only these
     * keys change; the email module's other settings are kept.
     */
    public static function saveEmail(\Pimple\Container $di, array $data): void
    {
        $mailer = (string) ($data['mailer'] ?? 'sendmail');
        if (!in_array($mailer, ['sendmail', 'smtp', 'keep'], true)) {
            throw new InformationException('Choose how email is sent.');
        }
        $fromEmail = trim((string) ($data['from_email'] ?? ''));
        if ($fromEmail === '' || filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new InformationException('Enter the address emails are sent from.');
        }
        $values = [
            'from_email' => $fromEmail,
            'from_name' => trim((string) ($data['from_name'] ?? '')),
        ];
        if ($mailer !== 'keep') {
            $values['mailer'] = $mailer;
        }
        if ($mailer === 'smtp') {
            $host = trim((string) ($data['smtp_host'] ?? ''));
            $port = (int) ($data['smtp_port'] ?? 0);
            if ($host === '' || preg_match('/\A[A-Za-z0-9.-]+\z/', $host) !== 1) {
                throw new InformationException('Enter the SMTP server name.');
            }
            if ($port < 1 || $port > 65535) {
                throw new InformationException('Enter the SMTP port (usually 587 or 465).');
            }
            $values += ['smtp_host' => $host, 'smtp_port' => (string) $port, 'smtp_username' => trim((string) ($data['smtp_username'] ?? ''))];
            // An empty password field keeps the saved one.
            $password = (string) ($data['smtp_password'] ?? '');
            if ($password !== '') {
                $values['smtp_password'] = $password;
            }
        }
        self::mergeConfig($di, 'mod_email', $values);
    }

    // ----- payments ---------------------------------------------------------------------------

    /**
     * Turns on the payment methods chosen: bank transfer or other manual payment (instructions),
     * PayPal (email address) and Stripe (live keys). A method left off is switched off. Then the
     * main currency, while nothing has been billed.
     */
    public static function savePayments(\Pimple\Container $di, array $data): void
    {
        $manual = !empty($data['manual_enabled']);
        $paypal = !empty($data['paypal_enabled']);
        $stripe = !empty($data['stripe_enabled']);

        // Everything is checked first, so a refused step changes nothing.
        $others = (int) $di['em']->getConnection()->fetchOne("SELECT COUNT(*) FROM pay_gateway WHERE enabled = 1 AND gateway NOT IN ('Custom', 'PayPalEmail', 'Stripe')");
        if (!$manual && !$paypal && !$stripe && $others === 0) {
            throw new InformationException('Turn on at least one payment method; you can change them later in System → Payment Gateways.');
        }
        $text = trim((string) ($data['manual_text'] ?? ''));
        if ($manual && $text === '') {
            throw new InformationException('Enter the payment instructions clients see (for example your bank details).');
        }
        $email = trim((string) ($data['paypal_email'] ?? ''));
        if ($paypal && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InformationException('Enter the PayPal email address that receives payments.');
        }
        $stripeConfig = $stripe ? self::stripeConfig($di, $data) : [];
        $currency = self::newCurrency($di, strtoupper(trim((string) ($data['currency'] ?? ''))));

        self::updateGateway($di, 'Custom', $manual, $manual ? self::manualConfig($di, $text) : []);
        self::updateGateway($di, 'PayPalEmail', $paypal, $paypal ? ['email' => $email] : []);
        self::updateGateway($di, 'Stripe', $stripe, $stripeConfig);
        if ($currency !== null) {
            self::setCurrency($di, $currency);
        }
    }

    /**
     * The manual gateway's instructions: the wizard shows the one-time text; the subscription text
     * follows it unless it was set to something else in System → Payment Gateways.
     *
     * @return array<string, string>
     */
    private static function manualConfig(\Pimple\Container $di, string $text): array
    {
        $row = self::gateway($di, 'Custom');
        $saved = $row !== null ? (json_decode((string) ($row['config'] ?? ''), true) ?: []) : [];
        $recurrent = (string) ($saved['recurrent'] ?? '');
        $config = ['single' => $text];
        if ($recurrent === '' || $recurrent === (string) ($saved['single'] ?? '')) {
            $config['recurrent'] = $text;
        }

        return $config;
    }

    /** @return array<string, string> the Stripe keys entered (an empty field keeps the saved key) */
    private static function stripeConfig(\Pimple\Container $di, array $data): array
    {
        $pub = trim((string) ($data['stripe_pub_key'] ?? ''));
        $secret = trim((string) ($data['stripe_api_key'] ?? ''));
        $webhook = trim((string) ($data['stripe_webhook_secret'] ?? ''));
        $config = [];
        if ($pub !== '') {
            if (!str_starts_with($pub, 'pk_')) {
                throw new InformationException('The Stripe publishable key starts with pk_.');
            }
            $config['pub_key'] = $pub;
        }
        if ($secret !== '') {
            if (!str_starts_with($secret, 'sk_') && !str_starts_with($secret, 'rk_')) {
                throw new InformationException('The Stripe secret key starts with sk_ (or rk_ for a restricted key).');
            }
            $config['api_key'] = $secret;
        }
        // Without the webhook's signing secret Stripe's payment and renewal events are refused.
        if ($webhook !== '') {
            if (!str_starts_with($webhook, 'whsec_')) {
                throw new InformationException('The Stripe webhook signing secret starts with whsec_.');
            }
            $config['webhook_secret'] = $webhook;
        }
        $existing = self::gateway($di, 'Stripe');
        $saved = $existing !== null ? (json_decode((string) ($existing['config'] ?? ''), true) ?: []) : [];
        if (($config['pub_key'] ?? $saved['pub_key'] ?? '') === '' || ($config['api_key'] ?? $saved['api_key'] ?? '') === '') {
            throw new InformationException('Enter both Stripe keys (Developers → API keys in your Stripe dashboard).');
        }
        if (($config['webhook_secret'] ?? $saved['webhook_secret'] ?? '') === '') {
            throw new InformationException('Enter the signing secret of the Stripe webhook (Developers → Webhooks in your Stripe dashboard).');
        }

        return $config;
    }

    /**
     * The address Stripe sends its events to (the gateway's IPN address). Stripe is installed,
     * switched off, when it is not there yet, so the address can be given before the keys.
     */
    public static function stripeWebhookUrl(\Pimple\Container $di): string
    {
        $row = self::gateway($di, 'Stripe');
        if ($row === null) {
            $di['api_admin']->invoice_gateway_install(['code' => 'Stripe']);
            $row = self::gateway($di, 'Stripe');
            $di['api_admin']->invoice_gateway_update(['id' => (int) $row['id'], 'enabled' => 0]);
        }

        return SYSTEM_URL . 'ipn.php?' . http_build_query(['gateway_id' => (int) $row['id']]);
    }

    /** Whether the main currency can still change here: nothing has been billed or bought yet. */
    public static function currencyChangeable(\Pimple\Container $di): bool
    {
        $db = $di['em']->getConnection();

        foreach (['invoice', 'invoice_quote', 'client_order'] as $table) {
            if ((int) $db->fetchOne('SELECT COUNT(*) FROM ' . $table) > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * The currency to make the main one, or null when it stays: only while nothing has been
     * billed, since amounts are not converted. Later changes belong to System → Currencies.
     */
    private static function newCurrency(\Pimple\Container $di, string $code): ?string
    {
        $current = (string) $di['em']->getConnection()->fetchOne('SELECT code FROM currency WHERE is_default = 1');
        if ($code === '' || $code === $current) {
            return null;
        }
        if (!\Symfony\Component\Intl\Currencies::exists($code)) {
            throw new InformationException('Choose your main currency.');
        }
        if (!self::currencyChangeable($di)) {
            throw new InformationException('Invoices, quotes or orders exist already: change the main currency in System → Currencies.');
        }

        return $code;
    }

    /**
     * Makes a currency the main one (rate 1). The old main currency (the install's USD) is
     * removed when no client uses it.
     */
    private static function setCurrency(\Pimple\Container $di, string $code): void
    {
        $db = $di['em']->getConnection();
        $api = $di['api_admin'];
        $current = (string) $db->fetchOne('SELECT code FROM currency WHERE is_default = 1');
        if ($db->fetchOne('SELECT id FROM currency WHERE code = ?', [$code]) === false) {
            $api->currency_create(['code' => $code, 'conversion_rate' => 1]);
        } else {
            $api->currency_update(['code' => $code, 'conversion_rate' => 1]);
        }
        $api->currency_set_default(['code' => $code]);
        if ($current !== '' && (int) $db->fetchOne('SELECT COUNT(*) FROM client WHERE currency = ?', [$current]) === 0) {
            $api->currency_delete(['code' => $current]);
        }
    }

    /** @return array<string, mixed>|null the gateway row of an adapter (the first one) */
    private static function gateway(\Pimple\Container $di, string $adapter): ?array
    {
        $row = $di['em']->getConnection()->fetchAssociative('SELECT * FROM pay_gateway WHERE gateway = ? ORDER BY id LIMIT 1', [$adapter]);

        return $row === false ? null : $row;
    }

    private static function updateGateway(\Pimple\Container $di, string $adapter, bool $enabled, array $config = []): void
    {
        $row = self::gateway($di, $adapter);
        if ($row === null) {
            if (!$enabled) {
                return;
            }
            $di['api_admin']->invoice_gateway_install(['code' => $adapter]);
            $row = self::gateway($di, $adapter);
        }
        $data = ['id' => (int) $row['id'], 'enabled' => $enabled ? 1 : 0];
        if ($config !== []) {
            $data['config'] = $config;
        }
        // The manual method is the wizard's bank transfer: clients saw its adapter name ("Pay by Custom").
        // A name the owner chose is kept.
        if ($adapter === 'Custom' && $enabled && in_array(trim((string) ($row['name'] ?? '')), ['', 'Custom'], true)) {
            $data['title'] = 'Bank transfer';
        }
        $di['api_admin']->invoice_gateway_update($data);
    }

    // ----- security and store -----------------------------------------------------------------

    /**
     * Saves the captcha (provider and keys, merged into the anti-spam settings), the staff sign-in
     * security (SignInSecurity: the email PIN for staff, sign-in from chosen countries) and the
     * admin address. Turning the staff PIN on first answers {confirm: true, email} and changes
     * nothing; the same save with `confirm_pin` then saves it all. $admin and $ip: the saving
     * admin, as SignInSecurity::save() takes them.
     *
     * @return array{admin_path: ?string, confirm?: bool, email?: string}
     */
    public static function saveSecurity(\Pimple\Container $di, array $data, array $admin, string $ip): array
    {
        // Everything is checked first, so a refused step changes nothing.
        $captcha = self::captchaValues($di, $data);

        $current = (string) Config::getProperty('admin_area_prefix', '/admin');
        $path = trim((string) ($data['admin_path'] ?? ''));
        $path = $path === '' || '/' . trim($path, '/ ') === $current ? null : self::validAdminPath($path);

        $signin = self::signinValues($di, $data);
        if ($signin !== null) {
            $result = SignInSecurity::save($di, $signin, $admin, $ip);
            if (!$result['saved']) {
                return ['admin_path' => null, 'confirm' => true, 'email' => (string) ($result['email'] ?? '')];
            }
        }

        if ($captcha !== []) {
            self::mergeConfig($di, 'mod_antispam', $captcha);
        }
        if ($path === null || $path === $current) {
            return ['admin_path' => null];
        }
        Config::setProperty('admin_area_prefix', $path);

        return ['admin_path' => $path];
    }

    /**
     * The sign-in security settings from the wizard, or null when it sent none. The wizard only
     * sets the staff side: the client PIN and, when the country fields are left out (no GeoIP
     * database), the country rule stay as they are.
     */
    private static function signinValues(\Pimple\Container $di, array $data): ?array
    {
        if (!array_key_exists('staff_pin', $data) && !array_key_exists('countries_on', $data)) {
            return null;
        }
        $old = SignInSecurity::settings($di);
        $clients = in_array($old['pin'], ['clients', 'both'], true);
        $staff = array_key_exists('staff_pin', $data) ? !empty($data['staff_pin']) : in_array($old['pin'], ['staff', 'both'], true);
        $values = [
            'pin' => $staff ? ($clients ? 'both' : 'staff') : ($clients ? 'clients' : 'off'),
            'countries_on' => $old['countries_on'],
            'countries' => $old['countries'],
            'confirm_pin' => (string) ($data['confirm_pin'] ?? ''),
        ];
        // Switched off, the rule keeps its country list (the wizard sends none then).
        if (array_key_exists('countries_on', $data)) {
            $values['countries_on'] = !empty($data['countries_on']);
            if ($values['countries_on']) {
                $values['countries'] = $data['countries'] ?? [];
            }
        }

        return $values;
    }

    /** Saves who sees the store: closed, one client group, or everyone. */
    public static function saveStore(\Pimple\Container $di, array $data): void
    {
        $mode = (string) ($data['store_mode'] ?? 'closed');
        $group = trim((string) ($data['store_testers_group'] ?? ''));
        if (!in_array($mode, Service::STORE_MODES, true)) {
            throw new InformationException('Choose who can see the store.');
        }
        if ($mode === 'testers' && $group === '') {
            throw new InformationException('Choose the client group that can order.');
        }
        // The store's title, subtitle and opening cycle are kept (the API saves every store setting).
        $store = Service::config($di);
        $di['api_admin']->clientfence_store_update([
            'store_mode' => $mode,
            'store_testers_group' => $group,
            'store_title' => (string) ($store['store_title'] ?? ''),
            'store_subtitle' => (string) ($store['store_subtitle'] ?? ''),
            'store_cycle' => (string) ($store['store_cycle'] ?? ''),
        ]);
    }

    /**
     * The anti-spam settings for the captcha chosen ("none": unchanged). An empty secret field
     * keeps the saved secret.
     *
     * @return array<string, string>
     */
    private static function captchaValues(\Pimple\Container $di, array $data): array
    {
        $provider = (string) ($data['captcha_provider'] ?? 'none');
        if ($provider === 'none') {
            return [];
        }
        $keys = [
            'recaptcha_v2' => ['captcha_recaptcha_publickey', 'captcha_recaptcha_privatekey'],
            'hcaptcha' => ['hcaptcha_site_key', 'hcaptcha_secret_key'],
            'turnstile' => ['turnstile_site_key', 'turnstile_secret_key'],
        ];
        if (!isset($keys[$provider])) {
            throw new InformationException('Choose a captcha service.');
        }
        [$siteField, $secretField] = $keys[$provider];
        $site = trim((string) ($data['captcha_site_key'] ?? ''));
        $secret = trim((string) ($data['captcha_secret_key'] ?? ''));
        $saved = $di['mod_service']('extension')->getConfig('mod_antispam');
        if ($site === '' || ($secret === '' && (string) ($saved[$secretField] ?? '') === '')) {
            throw new InformationException('Enter the captcha site key and secret key.');
        }
        $values = ['captcha_enabled' => '1', 'captcha_provider' => $provider, $siteField => $site];
        if ($secret !== '') {
            $values[$secretField] = $secret;
        }

        return $values;
    }

    /**
     * A new admin address: "/" and 4-40 lowercase letters, digits or dashes. The admin area takes
     * every address that starts with it (index.php compares the start only), so no client page,
     * file or folder of the site may start with it. `$taken` lists the site's top-level names
     * (default: takenPaths()).
     *
     * @param list<string>|null $taken
     */
    public static function validAdminPath(string $path, ?array $taken = null): string
    {
        $path = '/' . trim(strtolower($path), '/ ');
        if (preg_match('#\A/[a-z0-9][a-z0-9-]{3,39}\z#', $path) !== 1) {
            throw new InformationException('The admin address is 4 to 40 letters, digits or dashes, for example /team-7k2.');
        }
        $name = substr($path, 1);
        foreach ($taken ?? self::takenPaths() as $used) {
            if (str_starts_with(strtolower($used), $name)) {
                throw new InformationException('Choose an admin address of your own: that one is used by the site or is the first bots try.');
            }
        }
        if (Health::isDefaultAdminPath($path)) {
            throw new InformationException('Choose an admin address of your own: that one is used by the site or is the first bots try.');
        }

        return $path;
    }

    /**
     * The site's top-level names: the first part of every client page address the modules
     * register (Controller/Client.php), the pages served by name, the files and folders in the
     * site's root and the reserved names.
     *
     * @return list<string>
     */
    public static function takenPaths(): array
    {
        $names = self::RESERVED_PATHS;
        foreach (glob(PATH_MODS . '/*/Controller/Client.php') ?: [] as $file) {
            preg_match_all('#->(?:get|post|put|delete|map)\(\s*[\'"]/([^/\'":]+)#', (string) file_get_contents($file), $m);
            array_push($names, ...$m[1]);
        }
        // Pages served by name (/login, /signup...): mod_page_NAME templates of themes and modules.
        foreach ([...(glob(PATH_THEMES . '/*/html/mod_page_*') ?: []), ...(glob(PATH_MODS . '/*/templates/client/mod_page_*') ?: [])] as $file) {
            $names[] = explode('.', substr(basename($file), strlen('mod_page_')))[0];
        }
        foreach (scandir(PATH_ROOT) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $names[] = pathinfo($entry, PATHINFO_FILENAME) !== '' ? pathinfo($entry, PATHINFO_FILENAME) : $entry;
            }
        }

        return array_values(array_unique(array_filter(array_map('strtolower', $names), static fn (string $n): bool => $n !== '')));
    }

    // ----- first product ----------------------------------------------------------------------

    /**
     * Adds a first product or service any business can sell: a name, a description and a price,
     * paid once or on a schedule. Returns the product id.
     */
    public static function saveProduct(\Pimple\Container $di, array $data): int
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new InformationException('Give the product or service a name.');
        }
        $price = (float) str_replace(',', '.', (string) ($data['price'] ?? ''));
        if ($price < 0) {
            throw new InformationException('Enter a price of 0 or more.');
        }
        $billing = (string) ($data['billing'] ?? 'once');
        $periods = ['1M', '3M', '6M', '1Y', '2Y'];
        $period = (string) ($data['period'] ?? '1M');
        if ($billing !== 'once' && ($billing !== 'recurrent' || !in_array($period, $periods, true))) {
            throw new InformationException('Choose how often the client pays.');
        }

        $api = $di['api_admin'];
        $categories = $api->product_category_get_pairs([]) ?: [];
        $categoryId = array_key_first($categories) ?? $api->product_category_create(['title' => __trans('Products and services')]);
        $id = (int) $api->product_prepare(['title' => $title, 'type' => 'custom', 'product_category_id' => $categoryId]);
        // A new product has every period on at 0.00: only the chosen one stays on.
        $recurrent = [];
        foreach (['1W', '1M', '3M', '6M', '1Y', '2Y', '3Y'] as $key) {
            $recurrent[$key] = $key === $period ? ['price' => $price, 'setup' => 0, 'enabled' => 1] : ['price' => 0, 'setup' => 0, 'enabled' => 0];
        }
        $pricing = $billing === 'once'
            ? ['type' => 'once', 'once' => ['price' => $price, 'setup' => 0]]
            : ['type' => 'recurrent', 'recurrent' => $recurrent];
        $api->product_update([
            'id' => $id,
            'status' => 'enabled',
            'description' => trim((string) ($data['description'] ?? '')),
            'pricing' => $pricing,
        ]);

        return $id;
    }

    // ----- helpers ----------------------------------------------------------------------------

    /** Merges values into a module's settings (extension config_save replaces them). */
    private static function mergeConfig(\Pimple\Container $di, string $ext, array $values): void
    {
        $extension = $di['mod_service']('extension');
        $config = $extension->getConfig($ext);
        $extension->setConfig(array_merge($config, $values, ['ext' => $ext]));
    }
}
