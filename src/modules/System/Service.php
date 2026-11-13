<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\System;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use ClientFence\Cache\CacheFactory;
use ClientFence\Environment;
use ClientFence\GeoIP\Reader;
use ClientFence\Sanitizer\BrowserHtmlSanitizer;
use ClientFence\Twig\SandboxedStringRenderer;
use Pimple\Container;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

class Service
{
    private const int MYSQL_DUPLICATE_ENTRY_ERROR = 23000;

    protected ?Container $di = null;

    private Filesystem $filesystem;

    public function __construct(?Filesystem $filesystem = null)
    {
        $this->filesystem = $filesystem ?? new Filesystem();
    }

    public function setDi(Container $di): void
    {
        $this->di = $di;
        if (isset($di['filesystem'])) {
            $this->filesystem = $di['filesystem'];
        }
    }

    public function getModulePermissions(): array
    {
        return [
            'view' => [
                'type' => 'bool',
                'display_name' => __trans('View System Information'),
                'description' => __trans('Allows the staff member to view system status, update availability, and other read-only system information.'),
            ],
            'manage_settings' => [
                'type' => 'bool',
                'display_name' => __trans('Manage System Settings'),
                'description' => __trans('Allows the staff member to view and manage general system settings.'),
            ],
            'manage_company_details' => [
                'type' => 'bool',
                'display_name' => __trans('Manage Company Details'),
                'description' => __trans('Allows the staff member to update company details as set under the system module.'),
            ],
            'manage_company_legal' => [
                'type' => 'bool',
                'display_name' => __trans('Manage Company Legal'),
                'description' => __trans('Allows the staff member to update company legal as set under the system module.'),
            ],
            'update_params' => [
                'type' => 'bool',
                'display_name' => __trans('Update System Parameters'),
                'description' => __trans('Allows the staff member to update system parameters through the system API endpoint.'),
            ],
            'invalidate_cache' => [
                'type' => 'bool',
                'display_name' => __trans('Invalidate Cache'),
                'description' => __trans('Allows the staff member to invalidate the ClientFence cache from within the system settings.'),
            ],
            'system_update' => [
                'type' => 'bool',
                'display_name' => __trans('Update ClientFence'),
                'description' => __trans('Allows the staff member to update ClientFence.'),
            ],
            'recheck_update' => [
                'type' => 'bool',
                'display_name' => __trans('Recheck for Updates'),
                'description' => __trans('Allows the staff member to clear cached update information and fetch the latest update metadata.'),
            ],
            'manage_network_interface' => [
                'type' => 'bool',
                'display_name' => __trans('Manage the Network Interface'),
                'description' => __trans('Allows the staff member to fetch a list of all local interface IP addresses and set the default network interface for ClientFence to use.'),
            ],
        ];
    }

    public function getParamValue(string $param, $default = null)
    {
        if (empty($param)) {
            throw new \ClientFence\Exception('Parameter key is missing.');
        }

        // ClientFence core patch: all settings are read once per request (ClientFence\RequestCache).
        if (isset($this->di['request_cache'])) {
            $all = $this->allParams();

            return array_key_exists($param, $all) ? $all[$param] : $default;
        }

        $query = $this->di['dbal']->createQueryBuilder();
        $query
            ->select('value')
            ->from('setting')
            ->where('param = :param')
            ->setParameter('param', $param);

        $result = $query->executeQuery()->fetchOne();
        if ($result === false) {
            return $default;
        }

        return $result;
    }

    /** @return array<string, mixed> every setting, read once per request */
    private function allParams(): array
    {
        return \ClientFence\RequestCache::remember($this->di, 'setting.all', fn (): array => $this->di['dbal']->fetchAllKeyValue('SELECT param, value FROM setting'));
    }

    public function setParamValue($param, $value, $createIfNotExists = true): bool
    {
        // Normalize the key so the permission check and the queries below
        // agree on it, then reject anything outside the canonical charset.
        $param = strtolower((string) $param);
        if (!preg_match('/^[a-z0-9_]+$/', $param)) {
            throw new \ClientFence\InformationException('Invalid parameter name, received: param_.', ['param_' => $param]);
        }

        // Skip this param if the user isn't permitted to update it.
        if (!$this->canUpdateParam($param)) {
            return true;
        }

        if ($this->paramExists($param)) {
            $query = $this->di['dbal']->createQueryBuilder();
            $query
                ->update('setting')
                ->set('value', ':value')
                ->where('param = :param')
                ->setParameter('param', $param)
                ->setParameter('value', $value)
                ->executeStatement();
        } elseif ($createIfNotExists) {
            try {
                $query = $this->di['dbal']->createQueryBuilder();
                $query
                    ->insert('setting')
                    ->values([
                        'param' => ':param',
                        'value' => ':value',
                        'created_at' => ':created_at',
                        'updated_at' => ':updated_at',
                    ])
                    ->setParameter('param', $param)
                    ->setParameter('value', $value)
                    ->setParameter('created_at', date('Y-m-d H:i:s'))
                    ->setParameter('updated_at', date('Y-m-d H:i:s'))
                    ->executeStatement();
            } catch (\Exception $e) {
                if ($e->getCode() != self::MYSQL_DUPLICATE_ENTRY_ERROR) {
                    throw $e;
                }
            }
        }
        \ClientFence\RequestCache::forget($this->di, 'setting.');

        return true;
    }

    public function paramExists($param): bool
    {
        $query = $this->di['dbal']->createQueryBuilder();
        $query
            ->select('id')
            ->from('setting')
            ->where('param = :param')
            ->setParameter('param', $param);

        $result = $query->executeQuery()->fetchOne();

        return (bool) $result;
    }

    /**
     * Fetch setting values for the provided setting keys.
     *
     * @param string[] $params
     *
     * @return mixed[]
     */
    private function getSettingsByParams(array $params): array
    {
        foreach ($params as $param) {
            if (!preg_match('/^[a-z0-9_]+$/', (string) $param)) {
                throw new \ClientFence\InformationException('Invalid parameter name, received: param_.', ['param_' => $param]);
            }
        }
        if (isset($this->di['request_cache'])) {
            return array_intersect_key($this->allParams(), array_flip($params));
        }
        $query = $this->di['dbal']->createQueryBuilder();
        $query
            ->select('param', 'value')
            ->from('setting')
            ->where('param IN (:params)')
            ->setParameter('params', $params, ArrayParameterType::STRING);

        $rows = $query->executeQuery()->fetchAllAssociative();
        $result = [];
        foreach ($rows as $row) {
            $result[$row['param']] = $row['value'];
        }

        return $result;
    }

    public function getCompany(): array
    {
        $c = [
            'company_name',
            'company_email',
            'company_tel',
            'company_signature',
            'company_logo',
            'company_logo_dark',
            'company_favicon',
            'company_address_1',
            'company_address_2',
            'company_address_3',
            'company_bank_name',
            'company_bic',
            'company_display_bank_info',
            'company_bank_info_pagebottom',
            'company_account_number',
            'company_number',
            'company_note',
            'company_privacy_policy',
            'company_tos',
            'company_vat_number',
            'invoice_title',
            'invoice_tax_label',
        ];
        $results = $this->getSettingsByParams($c);

        $logoUrl = $results['company_logo'] ?? null;
        if ($logoUrl !== null && !str_contains((string) $logoUrl, 'http')) {
            $logoUrl = SYSTEM_URL . $logoUrl;
        }

        $logoUrlDark = $results['company_logo_dark'] ?? null;
        if ($logoUrlDark !== null && !str_contains((string) $logoUrlDark, 'http')) {
            $logoUrlDark = SYSTEM_URL . $logoUrlDark;
        }
        $logoUrlDark ??= $logoUrl;

        $faviconUrl = $results['company_favicon'] ?? null;
        if ($faviconUrl !== null && !str_contains((string) $faviconUrl, 'http')) {
            $faviconUrl = SYSTEM_URL . $faviconUrl;
        }

        $email = \ClientFence\Mod\Clientfence\Branding::email($this->di);

        // Returned raw: output contexts escape on render, so escaping here
        // double-escapes in templates and corrupts stored snapshots.
        return [
            'www' => SYSTEM_URL,
            'name' => isset($results['company_name']) ? (string) $results['company_name'] : null,
            'email' => isset($results['company_email']) ? (string) $results['company_email'] : null,
            'tel' => isset($results['company_tel']) ? (string) $results['company_tel'] : null,
            'signature' => $results['company_signature'] ?? null,
            'logo_url' => $logoUrl,
            'logo_url_dark' => $logoUrlDark,
            'favicon_url' => $faviconUrl,
            'address_1' => isset($results['company_address_1']) ? (string) $results['company_address_1'] : null,
            'address_2' => isset($results['company_address_2']) ? (string) $results['company_address_2'] : null,
            'address_3' => isset($results['company_address_3']) ? (string) $results['company_address_3'] : null,
            'account_number' => $results['company_account_number'] ?? null,
            'bank_name' => isset($results['company_bank_name']) ? (string) $results['company_bank_name'] : null,
            'bic' => isset($results['company_bic']) ? (string) $results['company_bic'] : null,
            'display_bank_info' => $results['company_display_bank_info'] ?? null,
            'bank_info_pagebottom' => $results['company_bank_info_pagebottom'] ?? null,
            'number' => isset($results['company_number']) ? (string) $results['company_number'] : null,
            'note' => $results['company_note'] ?? null,
            'privacy_policy' => $results['company_privacy_policy'] ?? null,
            'tos' => $results['company_tos'] ?? null,
            'vat_number' => isset($results['company_vat_number']) ? (string) $results['company_vat_number'] : null,
            // ClientFence core patch: an invoice title and tax number label, such as Tax Invoice and ABN (GitHub #9).
            'invoice_title' => trim((string) ($results['invoice_title'] ?? '')),
            'tax_label' => trim((string) ($results['invoice_tax_label'] ?? '')),
            // ClientFence core patch: the brand color for the email templates (GitHub #10).
            'brand_color' => $email['color'],
            'brand_text' => $email['text'],
            'brand_link' => $email['link'],
        ];
    }

    /**
     * @return mixed[]
     */
    public function getParams($data): array
    {
        $query = 'SELECT param, value
                  FROM setting';
        $rows = $this->di['db']->getAll($query);
        $result = [];
        foreach ($rows as $row) {
            $result[$row['param']] = $row['value'];
        }

        return $result;
    }

    public function updateParams($data): bool
    {
        $this->di['events_manager']->fire(['event' => 'onBeforeAdminSettingsUpdate', 'params' => $data]);

        foreach ($data as $key => $val) {
            if (!$this->canUpdateParam($key)) {
                throw new \ClientFence\InformationException('You do not have permission to update the parameter :param', [':param' => $key]);
            }

            if (strtolower((string) $key) === 'invoice_starting_number'
                && ((!is_int($val) && !is_string($val))
                    || filter_var($val, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX - 1]]) === false)) {
                throw new \ClientFence\InformationException('Next invoice number must be a positive whole number no greater than :max.', [':max' => PHP_INT_MAX - 1]);
            }
        }

        foreach ($data as $key => $val) {
            $this->setParamValue($key, $val, true);
        }

        $this->di['events_manager']->fire(['event' => 'onAfterAdminSettingsUpdate']);

        $this->di['logger']->info('Updated system general settings');

        return true;
    }

    private function createAdminAlert(
        string $type,
        string $message,
        ?string $title = null,
        array $buttons = [],
        bool $dismissible = true,
    ): array {
        $defaultTitles = [
            'danger' => __trans('Danger!'),
            'warning' => __trans('Warning'),
            'info' => __trans('Information'),
            'success' => __trans('Success'),
        ];

        return [
            'type' => $type,
            'title' => $title ?? ($defaultTitles[$type] ?? __trans('Notice')),
            'message' => $message,
            'buttons' => $buttons,
            'dismissible' => $dismissible,
        ];
    }

    /**
     * @return mixed[][]
     */
    public function getMessages($type = null): array
    {
        $messages = [];

        // Check if there's an update available
        try {
            $updater = $this->di['updater'];
            if ($updater->isUpdateAvailable()) {
                $version = $updater->getLatestVersion();
                $updateUrl = $this->di['url']->adminLink('system/update');
                $messages[] = $this->createAdminAlert(
                    'info',
                    __trans('ClientFence :version is available for download.', [':version' => $version]),
                    __trans('Update Available'),
                    [[
                        'link' => $updateUrl,
                        'text' => __trans('Review Update'),
                        'type' => 'primary',
                    ]]
                );
            }
        } catch (\Exception $e) {
            error_log($e->getMessage());
        }

        // Check if FOSSBilling is behind on database patches
        try {
            $updater = $this->di['updater'];
            if ($updater->isBehindOnDBPatches()) {
                $messages[] = $this->createAdminAlert(
                    'warning',
                    __trans('Your ClientFence database is behind on database patches. Apply the pending patches to avoid issues.'),
                    __trans('Database Patches Pending'),
                    [[
                        'link' => $this->di['url']->adminLink('system/update'),
                        'text' => __trans('Apply Patches'),
                        'type' => 'warning',
                    ]]
                );
            }
        } catch (\Exception $e) {
            error_log($e->getMessage());
        }

        if (Environment::isProduction()) {
            $last_exec = $this->getParamValue('last_cron_exec');
            $cronUrl = $this->di['url']->adminLink('extension/settings/cron');

            if (!$last_exec) {
                $messages[] = $this->createAdminAlert(
                    'danger',
                    __trans('Cron was never executed, please ensure you have configured the cronjob or else scheduled tasks within ClientFence will not behave correctly.'),
                    buttons: [[
                        'link' => $cronUrl,
                        'text' => __trans('Open Cron Settings'),
                        'type' => 'danger',
                    ]]
                );
            } elseif ((time() - strtotime((string) $last_exec)) / 60 >= 15) {
                $messages[] = $this->createAdminAlert(
                    'danger',
                    __trans("ClientFence has detected that cron hasn't been run in an abnormal time period. Please ensure the cronjob is configured to be run every 5 minutes."),
                    buttons: [[
                        'link' => $cronUrl,
                        'text' => __trans('Open Cron Settings'),
                        'type' => 'danger',
                    ]]
                );
            }
        }

        $install = Path::join(PATH_ROOT, 'install');
        if ($this->filesystem->exists($install)) {
            $messages[] = $this->createAdminAlert(
                'danger',
                __trans('Installer (":path") still exists. Please remove it for security reasons.', [':path' => $install])
            );
        }

        if (!extension_loaded('openssl')) {
            $messages[] = $this->createAdminAlert(
                'warning',
                __trans('ClientFence requires :extension extension to be enabled on this server for security reasons.', [':extension' => 'php openssl'])
            );
        }

        try {
            $emailService = $this->di['mod_service']('email');
            $brokenTemplates = $emailService->getBrokenTemplateCount();
            if ($brokenTemplates > 0) {
                $emailSettingsUrl = $this->di['url']->adminLink('extension/settings/email');
                $messages[] = $this->createAdminAlert(
                    'warning',
                    __trans(':count email template(s) have syntax errors and cannot send emails. Please review and fix them.', [':count' => $brokenTemplates]),
                    __trans('Broken Email Templates'),
                    [[
                        'link' => $emailSettingsUrl,
                        'text' => __trans('View Email Templates'),
                        'type' => 'warning',
                    ]]
                );
            }
        } catch (\Exception $e) {
            error_log($e->getMessage());
        }

        // ClientFence core patch: failed order actions waiting for staff (ClientFence\Mod\Order\ActionFailures).
        $failedActions = \ClientFence\Mod\Order\ActionFailures::openCount($this->di);
        if ($failedActions > 0) {
            $messages[] = $this->createAdminAlert(
                'danger',
                __trans(':count order action(s) failed: a hosting panel, registrar or other service refused or could not finish them.', [':count' => $failedActions]),
                __trans('Failed Order Actions'),
                [[
                    'link' => $this->di['url']->adminLink('order/failed-actions'),
                    'text' => __trans('View Failed Actions'),
                    'type' => 'danger',
                ]]
            );
        }

        if ($type === null || $type === '') {
            return $messages;
        }

        return array_values(array_filter(
            $messages,
            static fn (array $message): bool => ($message['type'] ?? null) === $type
        ));
    }

    /**
     * Get the Central Alerts System messages sent to this installation.
     *
     * @return array - array of messages
     */
    public function getCasMessages(): array
    {
        try {
            return $this->di['central_alerts']->filterAlerts();
        } catch (\ClientFence\Exception $e) {
            return [
                $this->createAdminAlert('warning', $e->getMessage()),
            ];
        }
    }

    public function templateExists(string $file, ?\Model_Admin $identity = null): bool
    {
        $file = trim($file);
        if ($file === '' || str_contains($file, "\0") || Path::isAbsolute($file) || str_contains($file, '..') || str_contains($file, '\\')) {
            return false;
        }
        if (!preg_match('/\A[a-zA-Z0-9_\-\/]+\.html\.twig\z/', $file)) {
            return false;
        }

        if ($identity instanceof \Model_Admin) {
            $client = false;
        } else {
            $client = true;
        }
        $themeService = $this->di['mod_service']('theme');
        $theme = $themeService->getThemeConfig($client);
        foreach ($theme['paths'] as $path) {
            $candidate = Path::join($path, $file);
            $canonicalBase = Path::canonicalize($path);
            $canonicalCandidate = Path::canonicalize($candidate);
            if (!Path::isBasePath($canonicalBase, $canonicalCandidate)) {
                continue;
            }
            if ($this->filesystem->exists($canonicalCandidate)) {
                return true;
            }
        }

        return false;
    }

    public function renderAdapterTplString(string $tpl, array $vars): string
    {
        $twigFactory = $this->di['twig_factory'];
        $twig = $twigFactory->createAdapterEnvironment();

        $rendered = SandboxedStringRenderer::render(
            $twig,
            $tpl,
            $vars,
            'Payment adapter template',
            function (\Twig\Sandbox\SecurityError $e): void {
                $this->di['logger']->setChannel('security')->warning('Payment adapter template sandbox violation', [
                    'error' => $e->getMessage(),
                ]);
            }
        );

        return BrowserHtmlSanitizer::sanitizeAdapterHtml($rendered);
    }

    /**
     * Render a template string using the sandboxed email Twig environment.
     * Use this for database-stored templates (email templates, mass mailer).
     *
     * @param string      $tpl      The template string to render
     * @param array       $vars     Variables to pass to the template
     * @param string|null $timezone Optional IANA timezone for date formatting;
     *                              pass the recipient's so emails render in
     *                              their local time. Falls back to the active
     *                              user's timezone, then the config.
     * @param string|null $locale   Optional ICU locale for date formatting;
     *                              pass the recipient's language so emails
     *                              render in their language. Outranks browser
     *                              auto-detection. Falls back to the active
     *                              locale, then the config.
     *
     * @return string The rendered template
     *
     * @throws \ClientFence\InformationException If template violates sandbox policy or has syntax errors
     */
    public function renderEmailTplString(string $tpl, array $vars, ?string $timezone = null, ?string $locale = null): string
    {
        $twigFactory = $this->di['twig_factory'];
        $twig = $twigFactory->createEmailEnvironment($timezone, $locale);

        $render = fn (): string => SandboxedStringRenderer::render(
            $twig,
            $tpl,
            $vars,
            'Email template',
            function (\Twig\Sandbox\SecurityError $e): void {
                $this->di['logger']->setChannel('security')->warning('Email template sandbox violation', [
                    'error' => $e->getMessage(),
                ]);
            }
        );

        // ClientFence core patch: |trans follows the recipient's language, not the language of whoever triggered the email.
        return empty($locale) ? $render() : \ClientFence\Translate::with($locale, $render);
    }

    /**
     * Render an email subject line (plaintext header) through the
     * HTML-autoescaping email environment, decoding once to restore it as
     * typed. Never use this for the HTML body.
     */
    public function renderEmailSubjectString(string $tpl, array $vars, ?string $timezone = null, ?string $locale = null): string
    {
        $rendered = $this->renderEmailTplString($tpl, $vars, $timezone, $locale);

        return html_entity_decode($rendered, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function checkEmailTplSyntax(string $tpl): void
    {
        $twigFactory = $this->di['twig_factory'];
        $twig = $twigFactory->createEmailEnvironment();

        try {
            $stream = $twig->tokenize(new \Twig\Source($tpl, '__validation__'));
            $twig->parse($stream);
        } catch (\Twig\Error\SyntaxError $e) {
            throw new \ClientFence\InformationException('Email template syntax error: ' . $e->getMessage());
        } catch (\Twig\Sandbox\SecurityError $e) {
            throw new \ClientFence\InformationException('Email template contains disallowed Twig syntax: ' . $e->getMessage());
        }
    }

    public function clearCache(?string $cachePath = null): bool
    {
        $path = $cachePath ?? PATH_CACHE;
        $this->filesystem->remove($path);
        $this->filesystem->mkdir($path);

        // Also flush the configured application/rate-limiter/Doctrine cache pools, which may be
        // backed by Redis or Memcached rather than the filesystem path cleared above.
        CacheFactory::clearAll();

        return true;
    }

    public function getEnv(bool $fetchExternalIp = false)
    {
        if ($fetchExternalIp) {
            try {
                return $this->di['tools']->getExternalIP();
            } catch (\Exception) {
                return '';
            }
        }

        $r = new \ClientFence\Requirements();
        $data = $r->checkCompat();
        $data['last_patch'] = $this->getParamValue('last_patch');

        return $data;
    }

    public function getCurrentUrl(): string
    {
        $request = $this->di['request'];

        return $request->getSchemeAndHttpHost() . strtok($request->getRequestUri(), '?');
    }

    public function getPeriod($code)
    {
        if (!is_scalar($code)) {
            return '-';
        }

        $code = (string) $code;
        if ($code === '' || $code === '0') {
            return '-';
        }

        $p = \ClientFence\Period::getPredefined();
        if (isset($p[$code])) {
            return $p[$code];
        }

        $p = new \ClientFence\Period($code);

        return $p->getTitle();
    }

    public function getPublicParamValue($param)
    {
        $query = $this->di['dbal']->createQueryBuilder();
        $query
            ->select('value')
            ->from('setting')
            ->where('param = :param')
            ->andWhere('public = 1')
            ->setParameter('param', $param);

        $result = $query->executeQuery()->fetchOne();
        if ($result === false) {
            throw new \ClientFence\Exception('Parameter :param does not exist', [':param' => $param]);
        }

        return $result;
    }

    public function getNameservers()
    {
        $query = "SELECT param, value FROM setting WHERE param IN ('nameserver_1', 'nameserver_2', 'nameserver_3', 'nameserver_4')";

        return $this->di['db']->getAssoc($query);
    }

    public function getPendingMessages()
    {
        $messages = $this->di['session']->get('pending_messages');

        if (!is_array($messages)) {
            return [];
        }

        return $messages;
    }

    public function setPendingMessage($msg): bool
    {
        $messages = $this->getPendingMessages();
        $messages[] = $msg;
        $this->di['session']->set('pending_messages', $messages);

        return true;
    }

    public function clearPendingMessages(): bool
    {
        $this->di['session']->delete('pending_messages');

        return true;
    }

    public static function onBeforeAdminCronRun(\ClientFence\Event $event): void
    {
        $di = $event->getDi();
        /** @var Reader $geoipReader */
        $geoipReader = (new \ReflectionClass(Reader::class))->newInstanceWithoutConstructor();
        $geoipReader->setDi($di);
        $geoipReader->updateDefaultDatabases();

        try {
            // Prune the cache. Only filesystem-backed pools support this; Redis/Memcached
            // expire entries on their own and don't implement PruneableInterface.
            $cache = $di['cache'];
            if ($cache instanceof \Symfony\Component\Cache\PruneableInterface && $cache->prune()) {
                $di['logger']->setChannel('cron')->info('Pruned the filesystem cache');
            }
        } catch (\Exception $e) {
            error_log($e->getMessage());
        }
    }

    /**
     * Claims a numeric counter setting's current value and advances it, under a row lock so two
     * concurrent callers are never handed the same number. Returns null if it is missing or not
     * numeric and no $seed was given to repair it with.
     *
     * A $seed is only used if the setting is still missing or invalid once the lock is held, so
     * concurrent callers seeding the same value cannot both reserve it. If they collide on
     * creating the row, the loser reserves from the winner's row instead.
     *
     * Not subject to canUpdateParam(): this reserves an internal counter rather than applying a
     * user-driven settings change, and must work in client and cron contexts.
     */
    public function reserveNextNumericParamValue(string $param, ?int $seed = null): ?int
    {
        if (empty($param)) {
            throw new \ClientFence\Exception('Parameter key is missing.');
        }

        try {
            return $this->reserveNumericParamValue($param, $seed);
        } catch (UniqueConstraintViolationException|DeadlockException) {
            // Two callers seeding a counter that does not exist yet both pass the locking read:
            // InnoDB gap locks are shared, so neither blocks the other, and they collide on the
            // insert instead. The loser's transaction is rolled back, so reserve from the row the
            // winner committed rather than failing the caller.
            return $this->reserveNumericParamValue($param, $seed);
        }
    }

    private function reserveNumericParamValue(string $param, ?int $seed): ?int
    {
        \ClientFence\RequestCache::forget($this->di, 'setting.');

        return $this->di['dbal']->transactional(function (Connection $connection) use ($param, $seed): ?int {
            $now = date('Y-m-d H:i:s');
            $current = $connection->fetchOne(
                'SELECT value FROM setting WHERE param = :param FOR UPDATE',
                ['param' => $param]
            );

            // ClientFence core patch (0.8.9 port): reject an invalid invoice counter before reserving or incrementing it.
            if ($param === 'invoice_starting_number' && $current !== false && filter_var($current, FILTER_VALIDATE_INT) !== false
                && filter_var($current, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX - 1]]) === false) {
                throw new \ClientFence\InformationException('Next invoice number must be a positive whole number no greater than :max.', [':max' => PHP_INT_MAX - 1]);
            }

            $exists = $current !== false;
            if (!$exists || filter_var($current, FILTER_VALIDATE_INT) === false) {
                if ($seed === null) {
                    return null;
                }

                if ($exists) {
                    $connection->executeStatement(
                        'UPDATE setting SET value = :value, updated_at = :updated_at WHERE param = :param',
                        ['value' => (string) ($seed + 1), 'updated_at' => $now, 'param' => $param]
                    );
                } else {
                    $connection->executeStatement(
                        'INSERT INTO setting (param, value, created_at, updated_at) VALUES (:param, :value, :created_at, :updated_at)',
                        ['param' => $param, 'value' => (string) ($seed + 1), 'created_at' => $now, 'updated_at' => $now]
                    );
                }

                return $seed;
            }

            $connection->executeStatement(
                'UPDATE setting SET value = :value, updated_at = :updated_at WHERE param = :param',
                ['value' => (string) (intval($current) + 1), 'updated_at' => $now, 'param' => $param]
            );

            return intval($current);
        });
    }

    private function canUpdateParam(string $param): bool
    {
        // Compare case-insensitively so the check agrees with the lookup,
        // which resolves case-insensitively on some database drivers.
        $param = strtolower($param);

        $company = [
            'company_name',
            'company_email',
            'company_tel',
            'company_address_1',
            'company_address_2',
            'company_address_3',
            'company_logo',
            'company_logo_dark',
            'company_favicon',
            'company_number',
            'company_vat_number',
            'company_account_number',
            'company_bank_name',
            'company_bic',
            'company_display_bank_info',
            'company_bank_info_pagebottom',
            'hide_company_public',
            'company_signature',
        ];
        $company_legal = ['company_tos', 'company_privacy_policy', 'company_note'];

        $staff_service = $this->di['mod_service']('Staff');
        if (in_array($param, $company) && !$staff_service->hasPermission(null, 'system', 'manage_company_details')) {
            return false;
        }

        if (in_array($param, $company_legal) && !$staff_service->hasPermission(null, 'system', 'manage_company_legal')) {
            return false;
        }

        // ClientFence core patch: the accounting sync settings belong to the invoice settings permission.
        if (str_starts_with($param, 'accounting_') && !$staff_service->hasPermission(null, 'invoice', 'manage_settings')) {
            return false;
        }

        return true;
    }
}
