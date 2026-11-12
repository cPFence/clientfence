<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

/**
 * System management methods.
 */

namespace ClientFence\Mod\System\Api;

use ClientFence\Cache\CacheFactory;
use ClientFence\Config;
use ClientFence\Tools;
use ClientFence\Validation\Api\RequiredParams;

class Admin extends \ClientFence\Api\AbstractApi
{
    /**
     * Get all defined system params.
     *
     * @return array
     */
    public function get_params($data)
    {
        $this->checkPermissions('system', 'manage_settings');

        return $this->getService()->getParams($data);
    }

    /**
     * Returns localization settings stored in the FOSSBilling config file.
     */
    public function localization_settings(): array
    {
        $this->checkPermissions('system', 'manage_settings');

        return [
            'locale' => (string) Config::getProperty('i18n.locale', 'en_US'),
            'auto_detect_locale' => Tools::normalizeBoolean(Config::getProperty('i18n.auto_detect_locale', true), true),
        ];
    }

    /**
     * Updated parameters array with new values. Creates new setting if it was
     * not defined earlier. You can create new parameters using this method.
     * This method accepts any number of parameters you pass.
     *
     * @return bool
     */
    public function update_params($data)
    {
        $this->checkPermissions('system', 'update_params');

        return $this->getService()->updateParams($data);
    }

    /**
     * Updates localization settings stored in the FOSSBilling config file.
     *
     * @throws \ClientFence\Exception
     */
    public function update_localization_settings($data): bool
    {
        $this->checkPermissions('system', 'update_params');

        if (isset($data['locale']) && $data['locale'] !== '') {
            Config::setProperty('i18n.locale', $data['locale']);
        }

        Config::setProperty('i18n.auto_detect_locale', Tools::normalizeBoolean($data['auto_detect_locale'] ?? true, true));

        return true;
    }

    /**
     * Returns the cache backend settings stored in the FOSSBilling config file.
     * The Redis password, if set, is never returned.
     */
    public function cache_settings(): array
    {
        $this->checkPermissions('system', 'manage_settings');

        return [
            'driver' => (string) Config::getProperty('cache.driver', 'filesystem'),
            'redis_host' => (string) Config::getProperty('cache.redis.host', '127.0.0.1'),
            'redis_port' => (int) Config::getProperty('cache.redis.port', 6379),
            'redis_password_set' => Config::getProperty('cache.redis.password') !== null,
            'redis_database' => (int) Config::getProperty('cache.redis.database', 0),
            'redis_tls_enabled' => Tools::normalizeBoolean(Config::getProperty('cache.redis.tls.enabled', false), false),
            'redis_tls_verify_peer' => Tools::normalizeBoolean(Config::getProperty('cache.redis.tls.verify_peer', true), true),
            'redis_tls_verify_peer_name' => Tools::normalizeBoolean(Config::getProperty('cache.redis.tls.verify_peer_name', true), true),
            'redis_tls_allow_self_signed' => Tools::normalizeBoolean(Config::getProperty('cache.redis.tls.allow_self_signed', false), false),
            'redis_tls_cafile' => (string) Config::getProperty('cache.redis.tls.cafile', ''),
            'memcached_host' => (string) Config::getProperty('cache.memcached.host', '127.0.0.1'),
            'memcached_port' => (int) Config::getProperty('cache.memcached.port', 11211),
        ];
    }

    /**
     * Updates the cache backend settings stored in the FOSSBilling config file.
     *
     * The new settings are validated by attempting to connect to the configured backend before
     * anything is saved, so a mistyped host/port/password is rejected with a clear reason rather
     * than being written and silently falling back to the filesystem cache later. That same
     * validation also rejects a Redis password on a non-loopback host with TLS disabled, since
     * that combination would send the password in plain text (see CacheFactory).
     *
     * @throws \ClientFence\Exception
     */
    public function update_cache_settings($data): bool
    {
        $this->checkPermissions('system', 'update_params');

        // ClientFence core patch: remote caches receive decrypted extension settings.
        if (!$this->getDi()['mod_service']('Staff')->isSuperAdministrator()) {
            throw new \ClientFence\InformationException('Only Super Administrators can change the cache settings.', null, 403);
        }

        $driver = $data['driver'] ?? 'filesystem';
        if (!in_array($driver, CacheFactory::SUPPORTED_DRIVERS, true)) {
            throw new \ClientFence\Exception('Unsupported cache driver: :driver', [':driver' => $driver], 5001);
        }

        $redisHost = $data['redis_host'] ?? '127.0.0.1';
        $redisPort = Tools::normalizePort($data['redis_port'] ?? null, 6379);
        $redisPassword = $data['redis_password'] ?? '';
        // ClientFence core patch: a saved Redis password belongs only to its configured host and port.
        if (
            $redisPassword === '' && !Tools::normalizeBoolean($data['redis_password_clear'] ?? false, false)
            && $redisHost === Config::getProperty('cache.redis.host', '127.0.0.1')
            && $redisPort === Tools::normalizePort(Config::getProperty('cache.redis.port'), 6379)
        ) {
            $redisPassword = Config::getProperty('cache.redis.password');
        }

        $newCacheConfig = [
            'driver' => $driver,
            'redis' => [
                'host' => $redisHost,
                'port' => $redisPort,
                'password' => $redisPassword !== '' ? $redisPassword : null,
                'database' => (int) ($data['redis_database'] ?? 0),
                'tls' => [
                    'enabled' => Tools::normalizeBoolean($data['redis_tls_enabled'] ?? false, false),
                    'verify_peer' => Tools::normalizeBoolean($data['redis_tls_verify_peer'] ?? true, true),
                    'verify_peer_name' => Tools::normalizeBoolean($data['redis_tls_verify_peer_name'] ?? true, true),
                    'allow_self_signed' => Tools::normalizeBoolean($data['redis_tls_allow_self_signed'] ?? false, false),
                    'cafile' => ($data['redis_tls_cafile'] ?? '') !== '' ? $data['redis_tls_cafile'] : null,
                ],
            ],
            'memcached' => [
                'host' => $data['memcached_host'] ?? '127.0.0.1',
                'port' => Tools::normalizePort($data['memcached_port'] ?? null, 11211),
            ],
        ];

        // Filesystem can't fail to connect, so only redis/memcached need the eager check.
        if ($driver !== 'filesystem') {
            CacheFactory::createFromConfig($newCacheConfig, CacheFactory::NAMESPACE_CONNECTION_TEST, 60, fallbackOnFailure: false);
        }

        // Config::setConfig() below already clears the pools for the new driver; also clear
        // the previously configured one, so entries left behind there (e.g. a Redis database
        // the admin is switching away from) don't reappear if this setting is ever reverted.
        $oldCacheConfig = Config::getProperty('cache', []);

        $config = Config::getConfig();
        $config['cache'] = $newCacheConfig;
        Config::setConfig($config);

        if (is_array($oldCacheConfig)) {
            CacheFactory::clearAll($oldCacheConfig);
        }

        return true;
    }

    /**
     * ClientFence core patch: maintenance mode as WHMCS (System > Settings > Maintenance), kept in config.php
     * maintenance_mode, which the updater also turns on and restores.
     */
    public function maintenance_get(): array
    {
        $this->checkPermissions('system', 'manage_settings');
        $mode = Config::getProperty('maintenance_mode', []);

        return [
            'enabled' => Tools::normalizeBoolean($mode['enabled'] ?? false, false),
            'message' => (string) ($mode['message'] ?? ''),
            'redirect_url' => (string) ($mode['redirect_url'] ?? ''),
            'allowed_ips' => implode("\n", array_map('strval', (array) ($mode['allowed_ips'] ?? []))),
            'my_ip' => (string) $this->getDi()['request']->getClientIp(),
        ];
    }

    public function maintenance_update($data): bool
    {
        $this->checkPermissions('system', 'update_params');

        $ips = array_values(array_unique(array_filter(preg_split('/[\s,]+/', (string) ($data['allowed_ips'] ?? '')) ?: [])));
        foreach ($ips as $ip) {
            [$address, $bits] = array_pad(explode('/', $ip, 2), 2, null);
            $max = str_contains((string) $address, ':') ? 128 : 32;
            if (filter_var($address, FILTER_VALIDATE_IP) === false || ($bits !== null && (!ctype_digit($bits) || (int) $bits > $max))) {
                throw new \ClientFence\InformationException('":ip" is not an IP address or range.', [':ip' => $ip]);
            }
        }
        $url = trim((string) ($data['redirect_url'] ?? ''));
        if ($url !== '') {
            if (filter_var($url, FILTER_VALIDATE_URL) === false || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                throw new \ClientFence\InformationException('The redirect address must be a full address starting with https:// or http://.');
            }
            // The portal's own pages are closed too: sending visitors there would loop.
            if (strcasecmp((string) parse_url($url, PHP_URL_HOST), (string) parse_url(SYSTEM_URL, PHP_URL_HOST)) === 0) {
                throw new \ClientFence\InformationException('The redirect address must be on another site: this portal is closed during maintenance.');
            }
        }
        $message = trim((string) ($data['message'] ?? ''));
        if (mb_strlen($message) > 2000) {
            throw new \ClientFence\InformationException('The message can be at most 2000 characters.');
        }
        $enabled = Tools::normalizeBoolean($data['enabled'] ?? false, false);

        $config = Config::getConfig();
        $config['maintenance_mode'] = array_merge((array) ($config['maintenance_mode'] ?? []), [
            'enabled' => $enabled,
            'allowed_ips' => $ips,
            'message' => $message,
            'redirect_url' => $url,
        ]);
        Config::setConfig($config, false);
        $this->getDi()['logger']->info($enabled ? 'Turned maintenance mode on' : 'Saved maintenance mode (off)');

        return true;
    }

    /**
     * System messages about working environment.
     *
     * @return array
     */
    public function messages($data)
    {
        try {
            $this->checkPermissions('system', 'manage_settings');
        } catch (\Throwable) {
            return [];
        }

        $type = $data['type'] ?? null;

        return $this->getService()->getMessages($type);
    }

    /**
     * Get Central Alerts System messages sent for this installation.
     *
     * @return array - array of messages
     */
    public function cas_messages()
    {
        try {
            $this->checkPermissions('system', 'manage_settings');
        } catch (\Throwable) {
            return [];
        }

        return $this->getService()->getCasMessages();
    }

    /**
     * Check if passed file name template exists for admin area.
     */
    public function template_exists($data): bool
    {
        // ClientFence core patch: any staff member (Dispatcher open call); billing staff could not open orders.
        if (!isset($data['file']) || !is_string($data['file'])) {
            return false;
        }

        $file = trim($data['file']);
        if ($file === '' || str_contains($file, "\0")) {
            return false;
        }

        return $this->getService()->templateExists($file, $this->getIdentity());
    }

    /**
     * Returns system environment information.
     *
     * @return array
     */
    public function env($data)
    {
        $this->checkPermissions('system', 'manage_settings');

        $fetchExternalIp = Tools::normalizeBoolean($data['ip'] ?? false);

        return $this->getService()->getEnv($fetchExternalIp);
    }

    /**
     * Method to check if staff member has permission to access module.
     *
     * @optional string $f - module method name
     *
     * @return bool
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['mod' => '"mod" key is missing'])]
    public function is_allowed($data)
    {
        $f = $data['f'] ?? null;
        $service = $this->getDi()['mod_service']('Staff');

        return $service->hasPermission($this->getIdentity(), $data['mod'], $f);
    }

    /**
     * Clear system cache.
     *
     * @return bool
     */
    public function clear_cache()
    {
        $this->checkPermissions('system', 'invalidate_cache');

        return $this->getService()->clearCache();
    }

    /**
     * Used to check if there's an update available.
     */
    public function update_available(): bool
    {
        $this->checkPermissions('system', 'view');

        $updater = $this->getDi()['updater'];

        return $updater->isUpdateAvailable();
    }

    /**
     * Returns an array containing the update info.
     */
    public function update_info(): array
    {
        $this->checkPermissions('system', 'view');

        $updater = $this->getDi()['updater'];

        $info = $updater->getLatestVersionInfo();
        $requiredPhpVersion = $info['minimum_php_version'] ?? 'unknown';
        if (!is_string($requiredPhpVersion) || $requiredPhpVersion === '') {
            $requiredPhpVersion = 'unknown';
        }

        $info['minimum_php_version'] = $requiredPhpVersion;
        $info['current_php_version'] = PHP_VERSION;
        $info['php_version_supported'] = $requiredPhpVersion === 'unknown' || version_compare(PHP_VERSION, $requiredPhpVersion, '>=');
        $info['readiness'] = $this->getDi()['update_readiness']->check();

        return $info;
    }

    /**
     * Forces the system to clear out the update cache and re-fetch the latest info.
     */
    public function recheck_update(): bool
    {
        $this->checkPermissions('system', 'recheck_update');

        $updater = $this->getDi()['updater'];
        $updater->getLatestVersionInfo(null, true);

        return true;
    }

    /**
     * Update FOSSBilling core.
     *
     * @throws \ClientFence\Exception
     */
    public function update_core($data): bool
    {
        $updater = $this->getDi()['updater'];
        if ($updater->getUpdateBranch() !== 'preview' && !$updater->isUpdateAvailable()) {
            throw new \ClientFence\InformationException('You have the latest version of ClientFence. You do not need to update.');
        }

        $this->checkPermissions('system', 'system_update');

        if (function_exists('set_time_limit')) {
            set_time_limit(300);
        }

        $new_version = $updater->getLatestVersion();
        $this->getDi()['events_manager']->fire(['event' => 'onBeforeAdminUpdateCore']);
        $updater->performUpdate();

        // ClientFence core patch: ClientFence versions in the update log.
        $this->getDi()['logger']->info('Installed ClientFence update files from %s to %s. Update finalization is pending.', \ClientFence\Version::CLIENTFENCE, $new_version);

        return true;
    }

    public function update_finalization_status(): array
    {
        $this->checkUpdateFinalizationPermissions();

        return $this->getDi()['update_finalization']->getStatus();
    }

    public function finalize_update(): bool
    {
        $this->checkUpdateFinalizationPermissions();

        if (function_exists('set_time_limit')) {
            set_time_limit(180);
        }

        $this->getDi()['update_finalization']->finalizeUpdate();
        $this->getDi()['events_manager']->fire(['event' => 'onAfterAdminUpdateCore']);
        $this->getDi()['logger']->info('Finalized ClientFence update to %s.', \ClientFence\Version::CLIENTFENCE);

        return true;
    }

    public function complete_update_finalization(): bool
    {
        $this->checkUpdateFinalizationPermissions();

        $this->getDi()['update_finalization']->completeFinalization();
        $this->getDi()['logger']->info('Completed ClientFence update finalization for %s.', \ClientFence\Version::CLIENTFENCE);

        return true;
    }

    private function checkUpdateFinalizationPermissions(): void
    {
        if (!$this->getDi()['update_finalization']->isRequired()) {
            $this->checkPermissions('system', 'system_update');

            return;
        }

        if ($this->identity instanceof \Model_Admin) {
            try {
                if ($this->getDi()['mod_service']('Staff')->isSuperAdministrator($this->identity->id)) {
                    return;
                }
            } catch (\Doctrine\DBAL\Exception) {
                // If the installation hasn't migrated to the new group structure yet,
                // Manually look for the old "admin" role
                if (
                    $this->getDi()['db']->getCell("SHOW COLUMNS FROM `admin` LIKE 'role'")
                    && $this->getDi()['db']->getCell('SELECT role FROM admin WHERE id = :id', ['id' => (int) $this->identity->id]) === 'admin'
                ) {
                    return;
                }
            }
        }

        throw new \ClientFence\InformationException('You need to be a Super Administrator to finalize this update.', [], 403);
    }

    /**
     * Update FOSSBilling config.
     *
     * @throws \ClientFence\Exception
     */
    public function manual_update(): bool
    {
        $this->checkPermissions('system', 'system_update');

        if (function_exists('set_time_limit')) {
            set_time_limit(180);
        }

        $updater = $this->getDi()['updater'];
        $this->getDi()['events_manager']->fire(['event' => 'onBeforeAdminManualUpdate']);
        $updater->performManualUpdate();
        $this->getDi()['events_manager']->fire(['event' => 'onAfterAdminManualUpdate']);
        $this->getDi()['logger']->info('Updated FOSSBilling - applied patches and updated configuration file.');

        return true;
    }

    /**
     * Checks if the database is behind on patches.
     */
    public function is_behind_on_patches(): bool
    {
        $this->checkPermissions('system', 'view');

        $updater = $this->getDi()['updater'];

        return $updater->isBehindOnDBPatches();
    }

    /**
     * Returns the unique instance ID for this FOSSBilling installation.
     */
    public function instance_id(): string
    {
        $this->checkPermissions('system', 'view');

        return INSTANCE_ID;
    }

    public function get_interface_ips(): array
    {
        $this->checkPermissions('system', 'manage_network_interface');

        return $this->di['tools']->listHttpInterfaces();
    }

    public function set_interface_ip($data): bool
    {
        $this->checkPermissions('system', 'manage_network_interface');
        $config = Config::getConfig();

        if (isset($data['interface'])) {
            $interface = $data['interface'];
            if ($interface !== '0' && !filter_var($interface, FILTER_VALIDATE_IP)) {
                throw new \ClientFence\Exception('Invalid interface IP address');
            }
            $config['interface_ip'] = $interface;
        }

        if (isset($data['custom_interface'])) {
            $custom = $data['custom_interface'];
            if ($custom !== '' && !Tools::isValidHttpInterface($custom)) {
                throw new \ClientFence\Exception('Invalid custom interface. Must be a valid IP address or hostname.');
            }
            $config['custom_interface_ip'] = $custom;
        }

        Config::setConfig($config);

        return true;
    }
}
