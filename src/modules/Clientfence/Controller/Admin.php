<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Controller;

use ClientFence\Mod\Clientfence\Backups;
use ClientFence\Mod\Clientfence\Branding;
use ClientFence\Mod\Clientfence\Customized;
use ClientFence\Mod\Clientfence\Health;
use ClientFence\Mod\Clientfence\Setup;

/**
 * Admin area pages of the ClientFence module: System health (Health.php) and Database backups
 * (Backups.php), both tabs of System > Settings > System (core patch in mod_system_settings),
 * not in the menu; their old addresses open the tabs. Payment receipts are
 * Invoice module pages, ticket attachments Support module pages.
 */
class Admin implements \ClientFence\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function register(\ClientFence\App &$app): void
    {
        $app->get('/clientfence/health', 'get_health', [], static::class);
        $app->get('/clientfence/backups', 'get_backups', [], static::class);
        $app->get('/clientfence/backups/download', 'get_backup_download', [], static::class);
        $app->get('/clientfence/setup', 'get_setup', [], static::class);
        $app->get('/clientfence/update/changed-files', 'get_changed_files', [], static::class);
    }

    /** @return array<string, string> code => "Name (CODE)" of the currencies in use today, by name */
    private static function currencyNames(): array
    {
        $names = [];
        foreach (\Symfony\Component\Intl\Currencies::getNames() as $code => $name) {
            // Old and fund codes (with a year or "fund" in the name) are left out.
            if (preg_match('/\(\d{4}|fund|\bunit\b/i', $name) !== 1) {
                $names[$code] = $name . ' (' . $code . ')';
            }
        }
        asort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    /**
     * The setup wizard (Setup.php): opens by itself on a new install's dashboard, and from
     * System → Settings on any site. Super Administrators only. Secrets are never sent back: the
     * page only says whether one is saved.
     */
    public function get_setup(\ClientFence\App $app): string
    {
        $this->di['is_admin_logged'];
        if (!Setup::allowed($this->di)) {
            throw new \ClientFence\InformationException('Only Super Administrators can run the setup wizard.', null, 403);
        }
        $di = $this->di;
        $db = $di['em']->getConnection();
        $system = $di['mod_service']('system');
        $extension = $di['mod_service']('extension');
        $params = $system->getParams([]);
        $email = $extension->getConfig('mod_email');
        $antispam = $extension->getConfig('mod_antispam');
        $gateways = [];
        foreach ($db->fetchAllAssociative('SELECT id, gateway, enabled, config FROM pay_gateway ORDER BY id') as $row) {
            if (!isset($gateways[$row['gateway']])) {
                $config = json_decode((string) ($row['config'] ?? ''), true) ?: [];
                $gateways[$row['gateway']] = [
                    'enabled' => (bool) $row['enabled'],
                    'text' => (string) ($config['single'] ?? ''),
                    'email' => (string) ($config['email'] ?? ''),
                    'pub_key' => (string) ($config['pub_key'] ?? ''),
                    'secret_saved' => (string) ($config['api_key'] ?? '') !== '',
                    'webhook_saved' => (string) ($config['webhook_secret'] ?? '') !== '',
                    'webhook_url' => SYSTEM_URL . 'ipn.php?' . http_build_query(['gateway_id' => (int) $row['id']]),
                ];
            }
        }
        $provider = (string) ($antispam['captcha_provider'] ?? 'recaptcha_v2');
        $siteKeys = ['recaptcha_v2' => 'captcha_recaptcha_publickey', 'recaptcha_v3' => 'captcha_recaptcha_publickey', 'hcaptcha' => 'hcaptcha_site_key', 'turnstile' => 'turnstile_site_key'];
        $store = \ClientFence\Mod\Clientfence\Service::config($di);
        $company = $system->getCompany();
        $currency = (string) ($db->fetchOne('SELECT code FROM currency WHERE is_default = 1') ?: '');
        // The current currency is always offered, so the list never picks another one by itself.
        $currencies = self::currencyNames();
        if ($currency !== '' && !isset($currencies[$currency])) {
            $currencies = [$currency => $currency] + $currencies;
        }

        return $app->render('mod_clientfence_setup', [
            'setup' => [
                'pending' => Setup::pending($di),
                'speed' => Health::speed($di),
                'company' => $params,
                'logo_url' => $company['logo_url'] ?? null,
                'logo_dark_url' => (string) ($params['company_logo_dark'] ?? '') !== '' ? $company['logo_url_dark'] : null,
                'icon_url' => $company['favicon_url'] ?? null,
                'brand' => Branding::get($di),
                'brand_default' => Branding::DEFAULT_COLOR,
                'email' => [
                    'mailer' => (string) ($email['mailer'] ?? 'sendmail'),
                    'from_email' => (string) ($email['from_email'] ?? ''),
                    'from_name' => (string) ($email['from_name'] ?? ''),
                    'smtp_host' => (string) ($email['smtp_host'] ?? ''),
                    'smtp_port' => (string) ($email['smtp_port'] ?? ''),
                    'smtp_username' => (string) ($email['smtp_username'] ?? ''),
                    'password_saved' => (string) ($email['smtp_password'] ?? '') !== '',
                ],
                'gateways' => $gateways,
                'currency' => $currency,
                'currency_changeable' => Setup::currencyChangeable($di),
                'currencies' => $currencies,
                'captcha' => [
                    'enabled' => (string) ($antispam['captcha_enabled'] ?? '') === '1',
                    'provider' => $provider,
                    'site_key' => (string) ($antispam[$siteKeys[$provider] ?? 'captcha_recaptcha_publickey'] ?? ''),
                ],
                'store_mode' => (string) $store['store_mode'],
                'store_testers_group' => (string) $store['store_testers_group'],
                'groups' => $db->fetchAllKeyValue('SELECT id, title FROM client_group ORDER BY title'),
                'site_url' => rtrim(SYSTEM_URL, '/'),
                'admin_path' => (string) \ClientFence\Config::getProperty('admin_area_prefix', '/admin'),
                'admin_path_default' => Health::isDefaultAdminPath((string) \ClientFence\Config::getProperty('admin_area_prefix', '/admin')),
                'products' => (int) $db->fetchOne('SELECT COUNT(*) FROM product WHERE is_addon = 0'),
            ],
        ]);
    }

    /**
     * System health lives in the "System Health" tab of System > Settings > System; this address
     * (dashboard card, alert email) opens it. `show` (bad, warn, ok) jumps to that list.
     */
    public function get_health(\ClientFence\App $app): \Symfony\Component\HttpFoundation\Response
    {
        $this->di['is_admin_logged'];
        $this->di['mod_service']('staff')->checkPermissionsAndThrowException('system', 'manage_settings');
        $show = $this->di['request']->query->get('show');
        $query = in_array($show, ['bad', 'warn', 'ok'], true) ? ['health' => $show] : [];

        return $app->redirectUrl($this->di['url']->adminLink('extension/settings/system', $query) . '#tab-health');
    }

    /**
     * Database backups live in the "Database Backups" tab of System > Settings > System; this
     * address (health check, emails) opens it. Super Administrators only.
     */
    public function get_backups(\ClientFence\App $app): \Symfony\Component\HttpFoundation\Response
    {
        $this->requireSuperAdmin();

        return $app->redirectUrl($this->di['url']->adminLink('extension/settings/system') . '#tab-backups');
    }

    /**
     * Sends a backup kept on this server (?file=), or makes one and sends it (WHMCS: download from
     * Database Status), removing that file at once.
     */
    public function get_backup_download(\ClientFence\App $app): never
    {
        $this->requireSuperAdmin();
        // A link from another site must not make dumps: the page's link carries the CSRF token.
        $token = $this->di['request']->query->get('CSRFToken');
        $session = $this->di['session']->get('csrf_token');
        if (!is_string($token) || !is_string($session) || $session === '' || !hash_equals($session, $token)) {
            throw new \ClientFence\InformationException('CSRF token invalid', null, 403);
        }
        @set_time_limit(0);
        // A backup kept on this server (?file=), or a new one made for this download.
        $kept = $this->di['request']->query->get('file');
        if (is_string($kept) && $kept !== '') {
            $file = Backups::localPath($kept) ?? throw new \ClientFence\InformationException('Backup not found.', null, 404);
            $name = $kept;
            $temporary = false;
        } else {
            // The file is removed even when the browser goes away mid-download.
            ignore_user_abort(true);
            [$file, $name] = Backups::make($this->di);
            $temporary = true;
            register_shutdown_function(static function () use ($file): void {
                if (is_file($file)) {
                    @unlink($file);
                }
            });
        }
        $this->di['logger']->info('ClientFence: database backup %s downloaded by staff', $name);
        try {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: application/gzip');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . (string) filesize($file));
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            readfile($file);
        } finally {
            if ($temporary) {
                @unlink($file);
            }
        }
        exit;
    }

    /** System → Update: a zip of the release files changed on this site (Customized.php). */
    public function get_changed_files(\ClientFence\App $app): never
    {
        $this->di['is_admin_logged'];
        if (!$this->di['mod_service']('staff')->hasPermission($this->di['loggedin_admin'], 'system', 'system_update')) {
            throw new \ClientFence\InformationException('You do not have permission to perform this action', null, 403);
        }
        $token = $this->di['request']->query->get('CSRFToken');
        $session = $this->di['session']->get('csrf_token');
        if (!is_string($token) || !is_string($session) || $session === '' || !hash_equals($session, $token)) {
            throw new \ClientFence\InformationException('CSRF token invalid', null, 403);
        }
        @set_time_limit(0);
        ignore_user_abort(true);
        $file = Customized::zip();
        register_shutdown_function(static function () use ($file): void {
            if (is_file($file)) {
                @unlink($file);
            }
        });
        try {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="clientfence-' . preg_replace('/[^A-Za-z0-9._-]/', '_', \ClientFence\Version::CLIENTFENCE) . '-changed-files.zip"');
            header('Content-Length: ' . (string) filesize($file));
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            readfile($file);
        } finally {
            @unlink($file);
        }
        exit;
    }

    private function requireSuperAdmin(): void
    {
        $this->di['is_admin_logged'];
        if (!$this->isSuperAdmin()) {
            throw new \ClientFence\InformationException('Only Super Administrators can manage database backups.', null, 403);
        }
    }

    private function isSuperAdmin(): bool
    {
        try {
            $admin = $this->di['loggedin_admin'];
            $id = is_object($admin) ? (int) ($admin->id ?? 0) : 0;

            return $id > 0 && $this->di['mod_service']('staff')->isSuperAdministrator($id);
        } catch (\Throwable) {
            return false;
        }
    }
}
