<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Theme;

use ClientFence\Mod\Extension\Entity\ExtensionMeta;
use ClientFence\Mod\Extension\Repository\ExtensionMetaRepository;
use ClientFence\InjectionAwareInterface;
use ClientFence\Sanitizer\BrowserHtmlSanitizer;
use ClientFence\Twig\SandboxedStringRenderer;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;

class Service implements InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;
    private ?ExtensionMetaRepository $extensionMetaRepository = null;
    private Filesystem $filesystem;

    /**
     * In-request cache for the current admin theme name.
     * This cache is used to store theme information during a single request.
     * It is cleared whenever theme settings are changed by calling clearThemeCache().
     */
    private static ?string $adminThemeCache = null;
    /**
     * In-request cache for the current client theme name.
     * This cache is used to store theme information during a single request.
     * It is cleared whenever theme settings are changed by calling clearThemeCache().
     */
    private static ?string $clientThemeCache = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
        if (isset($di['filesystem'])) {
            $this->filesystem = $di['filesystem'];
        }
        $this->extensionMetaRepository = isset($this->di['em'])
            ? $this->di['em']->getRepository(ExtensionMeta::class)
            : null;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function getModulePermissions(): array
    {
        return [
            'view' => [
                'type' => 'bool',
                'display_name' => __trans('View themes'),
                'description' => __trans('Allows the staff member to view available themes and their configuration.'),
            ],
            'manage' => [
                'type' => 'bool',
                'display_name' => __trans('Manage themes'),
                'description' => __trans('Allows the staff member to select themes and manage presets.'),
            ],
            'manage_settings' => [],
        ];
    }

    public function __construct()
    {
        $this->filesystem = new Filesystem();
    }

    /**
     * Clear the theme cache. Call this method when theme settings are updated.
     */
    public static function clearThemeCache(): void
    {
        self::$adminThemeCache = null;
        self::$clientThemeCache = null;
    }

    public function getExtensionMetaRepository(): ExtensionMetaRepository
    {
        if ($this->extensionMetaRepository === null) {
            if ($this->di === null) {
                throw new \ClientFence\Exception('The dependency injection container has not been set.');
            }

            $this->extensionMetaRepository = $this->di['em']->getRepository(ExtensionMeta::class);
        }

        return $this->extensionMetaRepository;
    }

    public function getTheme($name): Model\Theme
    {
        return new Model\Theme($name);
    }

    public function getCurrentThemePreset(Model\Theme $theme)
    {
        $current = $this->getExtensionMetaRepository()
            ->findOneByExtensionAndScope('mod_theme', $theme->getName(), 'preset', 'current')
            ?->getMetaValue();
        if (empty($current)) {
            $current = $theme->getCurrentPreset();
            $this->setCurrentThemePreset($theme, $current);
        }

        return $current;
    }

    public function setCurrentThemePreset(Model\Theme $theme, $preset): bool
    {
        $meta = $this->getExtensionMetaRepository()->findOneByExtensionAndScope('mod_theme', $theme->getName(), 'preset', 'current');

        if (!$meta instanceof ExtensionMeta) {
            $meta = (new ExtensionMeta())
                ->setExtension('mod_theme')
                ->setRelType('preset')
                ->setRelId('current')
                ->setMetaKey($theme->getName());

            $this->di['em']->persist($meta);
        }

        $meta->setMetaValue((string) $preset);
        $this->di['em']->flush();

        return true;
    }

    public function deletePreset(Model\Theme $theme, $preset): bool
    {
        $preset = (string) $preset;
        $currentPreset = (string) $this->getCurrentThemePreset($theme);

        $this->getExtensionMetaRepository()->deleteByExtensionAndScope('mod_theme', $preset, 'settings', $theme->getName());

        if ($preset === $currentPreset) {
            $this->getExtensionMetaRepository()->deleteByExtensionAndScope('mod_theme', $theme->getName(), 'preset', 'current');
        }

        return true;
    }

    /**
     * @return mixed[]
     */
    public function getThemePresets(Model\Theme $theme): array
    {
        $presets = [];
        $metaRows = $this->getExtensionMetaRepository()->findByExtensionAndScope('mod_theme', null, 'settings', $theme->getName(), ['metaKey' => 'ASC']);
        foreach ($metaRows as $meta) {
            $presets[$meta->getMetaKey()] = $meta->getMetaKey();
        }

        // insert default presets to database
        if (empty($presets)) {
            $core_presets = $theme->getPresetsFromSettingsDataFile();
            $presets = [];
            foreach ($core_presets as $preset => $params) {
                $presets[$preset] = $preset;
                $this->persistSettings($theme, $preset, $params);
            }
        }

        // if theme does not have settings data file
        if (empty($presets)) {
            $presets = ['Default' => 'Default'];
        }

        return $presets;
    }

    public function getThemeSettings(Model\Theme $theme, $preset = null)
    {
        $preset ??= $this->getCurrentThemePreset($theme);

        $defaults = $theme->getPresetFromSettingsDataFile($preset);

        $meta = $this->getExtensionMetaRepository()->findOneByExtensionAndScope('mod_theme', (string) $preset, 'settings', $theme->getName());
        if (!$meta instanceof ExtensionMeta) {
            return $defaults;
        }

        // Saved presets predate settings added in later releases: fall back
        // to the shipped defaults for keys the saved preset does not define,
        // so templates never hit missing keys after an update adds settings.
        $saved = json_decode($meta->getMetaValue() ?? '', true) ?? [];

        return array_merge($defaults, $saved);
    }

    public function updateSettings(Model\Theme $theme, $preset, array $params): bool
    {
        $this->di['mod_service']('Staff')->checkPermissionsAndThrowException('theme', 'manage_settings');

        // ClientFence core patch: theme settings permission must not grant script execution in client sessions.
        $old = $this->getThemeSettings($theme, $preset);
        if (!$this->di['mod_service']('Staff')->isSuperAdministrator()) {
            $params['inject_javascript'] = $old['inject_javascript'] ?? '';
            // ClientFence core patch: custom CSS can restyle or hide anything clients see, so super administrators only (Clientfence/CustomCss.php).
            foreach (\ClientFence\Mod\Clientfence\CustomCss::KEYS as $key) {
                unset($params[$key]);
                if (array_key_exists($key, $old)) {
                    $params[$key] = $old[$key];
                }
            }
        } elseif (array_key_exists('custom_css', $params)) {
            $params = \ClientFence\Mod\Clientfence\CustomCss::prepare($this->di, $params, $old);
        }

        return $this->persistSettings($theme, $preset, $params);
    }

    // ClientFence core patch (0.8.9 port): trusted preset initialization must remain separate from permission-checked writes.
    private function persistSettings(Model\Theme $theme, $preset, array $params): bool
    {
        $meta = $this->getExtensionMetaRepository()->findOneByExtensionAndScope('mod_theme', (string) $preset, 'settings', $theme->getName());

        if (!$meta instanceof ExtensionMeta) {
            $meta = (new ExtensionMeta())
                ->setExtension('mod_theme')
                ->setRelType('settings')
                ->setRelId($theme->getName())
                ->setMetaKey((string) $preset);

            $this->di['em']->persist($meta);
        }

        $meta->setMetaValue(json_encode($params));
        $this->di['em']->flush();

        return true;
    }

    /**
     * ClientFence: a form post leaves out every unticked box, and getThemeSettings() keeps a key's
     * old value when the saved settings lack it, so a ticked option could never be turned off.
     * Returns the posted settings with "0" for each box of the theme's settings page left out.
     */
    public function withUncheckedBoxesOff(Model\Theme $theme, $preset, array $params): array
    {
        return self::addUncheckedBoxes($this->renderThemeSettingsPageHtml($theme, $this->getThemeSettings($theme, $preset)), $params);
    }

    /**
     * @param array<string, mixed> $params the posted settings
     *
     * @return array<string, mixed> $params plus "0" for each named checkbox of $settingsHtml they lack
     */
    public static function addUncheckedBoxes(string $settingsHtml, array $params): array
    {
        if (trim($settingsHtml) === '') {
            return $params;
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?><html><body>' . $settingsHtml . '</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $boxes = (new \DOMXPath($document))->query('//input[@type="checkbox"][@name]');
        foreach ($boxes === false ? [] : $boxes as $box) {
            $name = $box instanceof \DOMElement ? $box->getAttribute('name') : '';
            // Array names (name[]) post a list; leaving one out is how a list drops an item.
            if ($name !== '' && !str_contains($name, '[') && !array_key_exists($name, $params)) {
                $params[$name] = '0';
            }
        }

        return $params;
    }

    public function regenerateThemeSettingsDataFile(Model\Theme $theme): bool
    {
        $settings = [];
        $presets = $this->getThemePresets($theme);
        foreach ($presets as $preset) {
            $settings['presets'][$preset] = $this->getThemeSettings($theme, $preset);
        }
        $settings['current'] = $this->getCurrentThemePreset($theme);
        $data_file = $theme->getPathSettingsDataFile();

        $this->filesystem->dumpFile($data_file, json_encode($settings));

        return true;
    }

    public function regenerateThemeCssAndJsFiles(Model\Theme $theme, $preset): bool
    {
        $assets = $theme->getPathAssets();

        $finder = new Finder();
        $finder->files()->in($assets)->name(['*.css.html.twig', '*.js.html.twig']);

        if (!count($finder)) {
            return true;
        }

        $twigFactory = $this->di['twig_factory'];
        $settings = $this->getThemeSettings($theme, $preset);

        foreach ($finder as $file) {
            $templateFilename = $file->getFilename();
            $realFilename = preg_replace('/\.(css|js)\.html\.twig$/', '.$1', $templateFilename);
            $realFile = Path::join($file->getPath(), $realFilename);

            $twig = $twigFactory->createBaseEnvironment();
            $template = $twig->createTemplate($file->getContents());
            $data = $template->render(['settings' => $settings]);

            $this->filesystem->dumpFile($realFile, $data);
        }

        return true;
    }

    public function renderThemeSettingsPageHtml(Model\Theme $theme, array $settings): string
    {
        $twigFactory = $this->di['twig_factory'];
        $twig = $twigFactory->createThemeSettingsEnvironment();

        $rendered = SandboxedStringRenderer::render(
            $twig,
            $theme->getSettingsPageHtml(),
            ['settings' => $settings],
            'Theme settings template',
            function (\Twig\Sandbox\SecurityError $e) use ($theme): void {
                $this->di['logger']->setChannel('security')->warning('Theme settings template sandbox violation', [
                    'theme' => $theme->getName(),
                    'error' => $e->getMessage(),
                ]);
            }
        );

        return BrowserHtmlSanitizer::sanitizeThemeSettingsHtml($rendered);
    }

    public function getCurrentAdminAreaTheme(): array
    {
        $default = 'admin_default';

        if (self::$adminThemeCache !== null) {
            // Apply default logic when returning from cache
            $theme = !empty(self::$adminThemeCache) && $this->filesystem->exists(Path::join(PATH_THEMES, self::$adminThemeCache))
                ? self::$adminThemeCache
                : $default;
            $url = SYSTEM_URL . "themes/{$theme}/";

            return ['code' => $theme, 'url' => $url];
        }

        $query = 'SELECT value
                FROM setting
                WHERE param = :param
               ';
        $theme = $this->di['db']->getCell($query, ['param' => 'admin_theme']);
        // Cache the raw database value (use empty string instead of null to mark as cached)
        self::$adminThemeCache = $theme ?? '';

        // Apply default logic for the return value
        if ($theme == null || !$this->filesystem->exists(Path::join(PATH_THEMES, $theme))) {
            $theme = $default;
        }
        $url = SYSTEM_URL . "themes/{$theme}/";

        return ['code' => $theme, 'url' => $url];
    }

    public function getCurrentClientAreaTheme()
    {
        $code = $this->getCurrentClientAreaThemeCode();

        return $this->getTheme($code);
    }

    public function getCurrentClientAreaThemeCode()
    {
        if (self::$clientThemeCache !== null) {
            // Apply default logic when returning from cache
            return !empty(self::$clientThemeCache) ? self::$clientThemeCache : 'palisade';
        }

        $theme = $this->di['db']->getCell("SELECT value FROM setting WHERE param = 'theme' ");
        // Cache the raw database value (use empty string instead of null to mark as cached)
        self::$clientThemeCache = $theme ?? '';

        return !empty($theme) ? $theme : 'palisade';
    }

    /**
     * @return mixed[]
     */
    public function getThemes($client = true): array
    {
        $list = [];
        $path = $this->getThemesPath();

        $finder = new Finder();
        $finder->directories()->in($path)->depth('== 0')->ignoreDotFiles(true);
        foreach ($finder as $file) {
            try {
                if (!$client && str_contains($file->getFilename(), 'admin')) {
                    $list[] = $this->buildThemeConfig($file->getFilename());
                }

                if ($client && !str_contains($file->getFilename(), 'admin')) {
                    $list[] = $this->buildThemeConfig($file->getFilename());
                }
            } catch (\Exception $e) {
                error_log($e->getMessage());
            }
        }

        return $list;
    }

    public function getThemeConfig($client = true, $mod = null)
    {
        if ($client) {
            $default = 'palisade';
            $theme = $this->getCurrentClientAreaThemeCode();
        } else {
            $default = 'admin_default';
            $systemService = $this->di['mod_service']('system');
            $theme = $systemService->getParamValue('admin_theme', $default);
        }

        $path = $this->getThemesPath();
        if (!$this->filesystem->exists(Path::join($path, $theme))) {
            $theme = $default;
        }

        return $this->buildThemeConfig($theme, $client, $mod);
    }

    public function loadTheme($code, $client = true, $mod = null)
    {
        return $this->buildThemeConfig($code, $client, $mod);
    }

    public function getThemesPath()
    {
        return PATH_THEMES;
    }

    private function buildThemeConfig($theme, $client = true, $mod = null): array
    {
        $theme_path = Path::join($this->getThemesPath(), $theme);

        if (!$this->filesystem->exists($theme_path)) {
            throw new \ClientFence\Exception('Theme was not found in path :path', [':path' => $theme_path]);
        }
        $manifest = Path::join($theme_path, 'manifest.json');

        if ($this->filesystem->exists($manifest)) {
            $config = json_decode($this->filesystem->readFile($manifest), true);
        } else {
            $config = [
                'name' => $theme,
                'version' => '1.0',
                'description' => 'Theme',
                'author' => 'ClientFence', // ClientFence: themes without a manifest
                'author_url' => 'https://clientfence.com/',
            ];
        }

        if (!is_array($config)) {
            throw new \ClientFence\Exception('Unable to decode theme manifest file :file', [':file' => $manifest]);
        }

        $paths = [Path::join($theme_path, 'html')];

        if (isset($config['extends'])) {
            $ext = trim((string) $config['extends'], '/');
            $ext = str_replace('.', '', $ext);

            $config['url'] = SYSTEM_URL . "themes/{$ext}/";
            $paths[] = Path::join($this->getThemesPath(), $ext, 'html');
        } else {
            $config['url'] = SYSTEM_URL . "themes/{$theme}/";
        }

        // add installed modules paths
        $table = $this->di['mod_service']('extension');
        $list = $table->getCoreAndActiveModules();
        // add module folder to look for template
        if (!is_null($mod)) {
            $list[] = $mod;
        }
        $list = array_unique($list);
        foreach ($list as $mod) {
            $p = Path::join(PATH_MODS, ucfirst((string) $mod), $client ? 'templates/client' : 'templates/admin');
            if ($this->filesystem->exists($p)) {
                $paths[] = $p;
            }
        }

        $config['code'] = $theme;
        $config['paths'] = $paths;
        $config['hasSettings'] = false;

        if ($this->filesystem->exists(Path::join($theme_path, 'config'))) {
            $config['hasSettings'] = true;
        }

        return $config;
    }

    public function getCurrentRouteTheme(): string
    {
        // Runtime check for admin area - uses index.php defined constant.
        // @phpstan-ignore if.alwaysTrue, booleanAnd.rightAlwaysTrue
        if (\defined('ADMIN_AREA') && ADMIN_AREA) {
            return $this->getCurrentAdminAreaTheme()['code'];
        }

        return $this->getCurrentClientAreaTheme()->getName();
    }

    public function getDefaultMarkdownAttributes(?bool $isAdmin = null): array
    {
        // Runtime check for admin area - uses index.php defined constant.
        // API requests (e.g. api/admin/...) don't set ADMIN_AREA the way admin panel
        // page loads do, so callers outside of an admin page render must pass $isAdmin explicitly.
        // ClientFence core patch: the cron and the CLI define no ADMIN_AREA (emails rendered there
        // use the client theme's defaults).
        // @phpstan-ignore booleanAnd.rightAlwaysTrue
        $isAdmin ??= \defined('ADMIN_AREA') && ADMIN_AREA;
        if ($isAdmin) {
            $config = $this->getThemeConfig(false);
        } else {
            $config = $this->getThemeConfig(true);
        }

        if (isset($config['markdown_attributes']) && is_array($config['markdown_attributes'])) {
            $attributes = $config['markdown_attributes'];
            foreach ($attributes as $class => $defaults) {
                if (!class_exists($class)) {
                    unset($attributes[$class]);
                }
            }

            return $attributes;
        }

        return [];
    }
}
