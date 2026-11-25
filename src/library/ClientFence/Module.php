<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence;

use ClientFence\Mod\Clientfence\Health;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

class Module implements InjectionAwareInterface
{
    private ?\Pimple\Container $di = null;
    private readonly string $module;
    private Filesystem $filesystem;

    public const MANIFEST_FILENAME = 'manifest.json';
    public const SERVICE_CLASS_PREFIX = 'ClientFence\\Mod\\';
    public const CONTROLLER_CLIENT_SUFFIX = '\\Controller\\Client';
    public const CONTROLLER_ADMIN_SUFFIX = '\\Controller\\Admin';

    // ClientFence core patch: only shipped modules are always active.
    public const CORE_MODULES = ['api', 'activity', 'antispam', 'cart', 'client',
        'cron', 'currency', 'email', 'extension',
        'hook', 'index', 'invoice', 'order',
        'page', 'product', 'profile', 'security',
        'servicecustom', 'servicedomain', 'servicedownloadable',
        'servicehosting', 'servicelicense', 'staff', 'stats',
        'support', 'system', 'theme', 'formbuilder',
        'widgets',
        // ClientFence core patch: DNS hosting is a built-in module.
        'servicednshosting',
        // ClientFence: built in, always on, never an extension.
        'clientfence'];

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
        if (isset($di['filesystem'])) {
            $this->filesystem = $di['filesystem'];
        }
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function __construct(string $mod)
    {
        if (!preg_match('#[a-zA-Z]#', $mod)) {
            throw new Exception('Invalid module name (:mod).', ['mod' => $mod]);
        }

        $this->module = strtolower($mod);
    }

    public function hasManifest(): bool
    {
        $path = Path::join($this->getModulePath(), self::MANIFEST_FILENAME);

        return $this->filesystem->exists($path);
    }

    public function getManifest(): array
    {
        if (!$this->hasManifest()) {
            throw new InformationException('Missing manifest file for the :mod module.', [':mod' => $this->module], 5897);
        }

        $contents = $this->filesystem->readFile(Path::join($this->getModulePath(), self::MANIFEST_FILENAME));

        try {
            $json = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InformationException('Invalid manifest file for the :mod module. Please check file syntax and permissions.', [':mod' => $this->module], 5897);
        }

        // ClientFence core patch (0.8.9 port): a module manifest must be an object, including an empty object.
        if (!is_array($json) || !str_starts_with(ltrim($contents), '{')) {
            throw new InformationException('Invalid manifest file for the :mod module. Please check file syntax and permissions.', [':mod' => $this->module], 5897);
        }

        $info = [
            'id' => $this->module,
            'type' => 'mod',
            'name' => $this->module,
            'description' => null,
            // ClientFence: modules without their own author are ClientFence's.
            'homepage_url' => 'https://clientfence.com/',
            'author' => 'ClientFence',
            'author_url' => 'https://clientfence.com/',
            'license' => 'N/A',
            'version' => '1.0',
            'icon_url' => null,
            'download_url' => null,
            'project_url' => 'https://clientfence.com/',
            'minimum_fossbilling_version' => null,
            'maximum_fossbilling_version' => null,
        ];

        $info = array_merge($info, $json);
        $info['id'] = $this->module;
        $info['type'] = 'mod';

        if (!empty($info['icon_url'])) {
            $info['icon_url'] = '/modules/' . ucfirst($this->module) . '/' . $info['icon_url'];
        }

        return $info;
    }

    public function hasService(string $sub = ''): bool
    {
        $path = Path::join($this->getModulePath(), 'Service' . ucfirst($sub) . '.php');

        return $this->filesystem->exists($path);
    }

    /**
     * ClientFence core patch: why this module's code cannot run on this PHP, as [message, params] in English (null: it
     * can; Health::msg() marks the texts for translation). An encoded file run without its loader ends the whole PHP
     * process, cron included, and the web and cron PHP can differ. Such a module stays switched on but is broken: its
     * files are never loaded (getService() and the controllers throw the reason, so what needs it fails), and the few
     * places that include its files directly skip it. Only manifest.json is read: "module_api" (absent: 1) must be a
     * level from Version::MODULE_API_MIN to MODULE_API, and "requires" may name "extensions" (extension_loaded() names,
     * for example "intl" or "sodium") and a minimum "php" version; either key present but malformed, an
     * unreadable manifest, or an unmet version minimum (unmetMinimum()) also means it cannot run. Core modules
     * and a module whose folder is gone are not checked here.
     *
     * @return array{0: string, 1: array<string, string>}|null
     */
    public function cannotRun(): ?array
    {
        // A module whose folder is gone is not this check's: it fails as before, with no service class.
        if ($this->isCore() || !$this->filesystem->exists($this->getModulePath())) {
            return null;
        }

        return RequestCache::remember($this->di, 'module.cannot_run.' . $this->module, function (): ?array {
            $params = [':mod' => $this->module];

            try {
                $manifest = $this->getManifest();
            } catch (\Throwable) {
                return [Health::msg('The manifest.json of the :mod module cannot be read.'), $params];
            }
            // A present but malformed key cannot say what the module needs, so it does not run (ServiceTypes::invalid()).
            $problem = ServiceTypes::invalid($this->module, array_intersect_key($manifest, ['module_api' => 0, 'requires' => 0]));
            if ($problem !== null) {
                return $problem;
            }
            $level = $manifest['module_api'] ?? 1;
            if ($level < Version::MODULE_API_MIN || $level > Version::MODULE_API) {
                return [Health::msg('The :mod module is written for module API level :level, which this ClientFence does not run (levels :min to :max).'), $params + [':level' => (string) $level, ':min' => (string) Version::MODULE_API_MIN, ':max' => (string) Version::MODULE_API]];
            }
            foreach ($manifest['requires']['extensions'] ?? [] as $extension) {
                if (!extension_loaded($extension)) {
                    return [Health::msg('The :mod module needs the PHP extension :extension, which this PHP (:sapi) does not load.'), $params + [':extension' => $extension, ':sapi' => PHP_SAPI]];
                }
            }
            $php = $manifest['requires']['php'] ?? null;
            if ($php !== null && version_compare(PHP_VERSION, $php, '<')) {
                return [Health::msg('The :mod module needs PHP :php or later; this PHP is :version.'), $params + [':php' => $php, ':version' => PHP_VERSION]];
            }
            // Files of a release for a newer core (its upgrade is refused until then) do not run either.
            if (($unmet = self::unmetMinimum($manifest)) !== null) {
                return [Health::msg('The :mod module needs :product :min or later; this site has :version.'), $params + array_combine([':product', ':min', ':version'], $unmet)];
            }

            return null;
        });
    }

    /**
     * ClientFence core patch: the first version minimum of `$manifest` this site does not meet, as [product, minimum, this
     * site's version], or null. The one check for activation, upgrade (Extension\Service) and cannotRun(): the FOSSBilling
     * base and the ClientFence release (Version::VERSION is a placeholder that refused every module with a minimum).
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    public static function unmetMinimum(array $manifest): ?array
    {
        foreach (['FOSSBilling' => ['minimum_fossbilling_version', Version::FOSSBILLING], 'ClientFence' => ['minimum_clientfence_version', Version::CLIENTFENCE]] as $product => [$key, $have]) {
            if (!empty($manifest[$key]) && version_compare($have, (string) $manifest[$key], '<')) {
                return [$product, (string) $manifest[$key], $have];
            }
        }

        return null;
    }

    public function getService(string $sub = ''): object
    {
        if (!$this->hasService($sub)) {
            throw new Exception('Module :mod does not have a service class. Make sure Service.php exists and is valid.', [':mod' => $this->module], 5898);
        }
        // ClientFence core patch: a module that cannot run here (cannotRun()) is never loaded: what needs it gets the reason.
        if (($reason = $this->cannotRun()) !== null) {
            throw new InformationException(...$reason);
        }

        $class = self::SERVICE_CLASS_PREFIX . ucfirst($this->module) . '\\Service' . ucfirst($sub);
        $service = new $class();

        if (method_exists($service, 'setDi')) {
            $service->setDi($this->di);
        }

        return $service;
    }

    public function hasClientController(): bool
    {
        $path = Path::join($this->getModulePath(), 'Controller/Client.php');

        return $this->filesystem->exists($path);
    }

    public function getClientController(): object
    {
        if (!$this->hasClientController()) {
            throw new Exception('Client controller class for the module :mod was not found.', [':mod' => $this->module]);
        }

        return $this->createControllerInstance(self::CONTROLLER_CLIENT_SUFFIX);
    }

    public function hasAdminController(): bool
    {
        $path = Path::join($this->getModulePath(), 'Controller/Admin.php');

        return $this->filesystem->exists($path);
    }

    public function getAdminController(): ?object
    {
        if (!$this->hasAdminController()) {
            return null;
        }

        return $this->createControllerInstance(self::CONTROLLER_ADMIN_SUFFIX);
    }

    public function hasSettingsPage(): bool
    {
        $path = Path::join($this->getModulePath(), "templates/admin/mod_{$this->module}_settings.html.twig");

        return $this->filesystem->exists($path);
    }

    public function install(): bool
    {
        if ($this->isCore()) {
            return true;
        }

        if ($this->hasService()) {
            $s = $this->getService();

            if (method_exists($s, 'install')) {
                $s->install();

                return true;
            }
        }

        return false;
    }

    public function uninstall(): bool
    {
        if ($this->isCore()) {
            return true;
        }

        if ($this->hasService()) {
            $s = $this->getService();

            if (method_exists($s, 'uninstall')) {
                $s->uninstall();

                return true;
            }
        }

        return false;
    }

    public function update(): bool
    {
        if ($this->isCore()) {
            throw new InformationException('Core modules cannot be updated separately. Please update to the latest ClientFence version to have the module updated.');
        }

        if ($this->hasService()) {
            $s = $this->getService();

            if (method_exists($s, 'update')) {
                $manifest = $this->getManifest();
                $s->update($manifest);

                return true;
            }
        }

        return false;
    }

    public function getCoreModules(): array
    {
        return self::CORE_MODULES;
    }

    public function isCore(): bool
    {
        return in_array($this->module, self::CORE_MODULES);
    }

    public function getConfig(): array
    {
        // ClientFence core patch: read and decrypted once per request (ClientFence\RequestCache); Extension\Service::setConfig() forgets it.
        return RequestCache::remember($this->di, 'mod.config.' . $this->module, fn (): array => $this->readConfig());
    }

    private function readConfig(): array
    {
        $modName = "mod_{$this->module}";

        $bean = $this->di['db']->findOne('extension_meta', 'extension = :ext AND meta_key = :key', [':ext' => $modName, ':key' => 'config']);
        if (!$bean || empty($bean->meta_value)) {
            return [];
        }

        $decrypted = $this->di['crypt']->decrypt($bean->meta_value, Config::getProperty('info.salt'));
        if (!is_string($decrypted) || !json_validate($decrypted)) {
            return [];
        }

        return json_decode($decrypted, true);
    }

    public function getName(): string
    {
        return $this->module;
    }

    public function registerClientRoutes(\ClientFence\App &$app): bool
    {
        if ($this->hasClientController()) {
            $cc = $this->getClientController();

            if (method_exists($cc, 'register')) {
                $cc->register($app);

                return true;
            }
        }

        return false;
    }

    private function getModulePath(): string
    {
        return Path::join(PATH_MODS, ucfirst($this->module)) . DIRECTORY_SEPARATOR;
    }

    private function createControllerInstance(string $suffix): object
    {
        // ClientFence core patch: as in getService(), a module that cannot run here is never loaded.
        if (($reason = $this->cannotRun()) !== null) {
            throw new InformationException(...$reason);
        }
        $class = self::SERVICE_CLASS_PREFIX . ucfirst($this->module) . $suffix;
        $instance = new $class();

        if (method_exists($instance, 'setDi')) {
            $instance->setDi($this->di);
        }

        return $instance;
    }
}
