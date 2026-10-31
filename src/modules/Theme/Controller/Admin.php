<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Theme\Controller;

use Symfony\Component\HttpFoundation\Response;

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
        $app->get('/theme/:theme', 'get_theme', ['theme' => '[a-z0-9-_]+'], static::class);
        $app->post('/theme/:theme', 'save_theme_settings', ['theme' => '[a-z0-9-_]+'], static::class);
    }

    /**
     * Save theme settings.
     */
    public function save_theme_settings(\ClientFence\App $app, $theme): Response
    {
        // ClientFence core patch (security S-2): viewing a theme does not grant permission to change its settings.
        $this->di['mod_service']('Staff')->checkPermissionsAndThrowException('theme', 'manage_settings');

        $body = $app->getRequest()->request->all();
        // ClientFence core patch (0.8.9 port): the direct theme form must validate its session nonce before hooks or saves.
        $token = $body['CSRFToken'] ?? null;
        $sessionToken = $this->di['session']->get('csrf_token');
        if (!is_string($token) || !is_string($sessionToken) || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
            throw new \ClientFence\InformationException('CSRF token invalid', null, 403);
        }
        unset($body['CSRFToken']);
        $this->di['events_manager']->fire(['event' => 'onBeforeThemeSettingsSave', 'params' => $body]);

        $api = $this->di['api_admin'];

        $mod = $this->di['mod']('theme');
        $service = $mod->getService();
        $t = $service->getTheme($theme);

        $isNewPreset = isset($body['save-current-setting']) && (bool) $body['save-current-setting'];
        $preset = $service->getCurrentThemePreset($t);
        if ($isNewPreset && isset($body['save-current-setting-preset']) && !empty($body['save-current-setting-preset'])) {
            $this->di['mod_service']('Staff')->checkPermissionsAndThrowException('theme', 'manage');
            $preset = $body['save-current-setting-preset'];
            $preset = str_replace(' ', '', $preset);
            $service->setCurrentThemePreset($t, $preset);
        }

        unset($body['save-current-setting-preset'], $body['save-current-setting']);

        $error = null;

        try {
            if (!$t->isAssetsPathWritable()) {
                throw new \ClientFence\Exception('Theme ":name" assets folder is not writable. Files cannot be uploaded and settings cannot be saved. Set folder permissions to 755', [':name' => $t->getName()]);
            }
            $body = $service->withUncheckedBoxesOff($t, $preset, $body);
            $service->updateSettings($t, $preset, $body);
            $service->regenerateThemeCssAndJsFiles($t, $preset, $api);
        } catch (\Exception $e) {
            $error = $e->getMessage();
            error_log($e->getMessage());
        }

        // optional data file
        try {
            $service->regenerateThemeSettingsDataFile($t);
        } catch (\Exception $e) {
            error_log($e->getMessage());
        }

        $red_url = '/theme/' . $theme;
        if ($error) {
            $red_url .= '?error=' . $error;
        }

        return $app->redirect($red_url);
    }

    public function get_theme(\ClientFence\App $app, $theme): string
    {
        $this->di['is_admin_logged'];
        $staffService = $this->di['mod_service']('Staff');
        $staffService->checkPermissionsAndThrowException('theme', 'view');

        $mod = $this->di['mod']('theme');
        $service = $mod->getService();
        $t = $service->getTheme($theme);
        $preset = $service->getCurrentThemePreset($t);
        $settings = $service->getThemeSettings($t, $preset);
        $error = $app->getRequest()->query->get('error');

        try {
            $html = $service->renderThemeSettingsPageHtml($t, $settings);
        } catch (\ClientFence\InformationException $e) {
            $html = '';
            $error ??= $e->getMessage();
        }

        $info = null;
        if (!$t->isAssetsPathWritable()) {
            $info = __trans('Theme ":name" assets folder is not writable. Set folder :folder permissions to 777', [':name' => $t->getName(), ':folder' => $t->getPathAssets()]);
        }

        if (empty($html) && empty($error)) {
            $info = __trans('Theme ":name" is not configurable', [':name' => $t->getName()]);
        }

        $data = [
            'info' => $info,
            'error' => $error,
            'theme_code' => $t->getName(),
            'settings_html' => new \Twig\Markup($html, 'UTF-8'),
            // ClientFence core patch: the page tells other staff that only Super Administrators save the footer JavaScript.
            'is_super_admin' => $staffService->isSuperAdministrator(),
            // ClientFence core patch: optional theme choices must respect the staff member's page and product permissions.
            'page_pairs' => $staffService->hasPermission(null, 'page') ? $this->di['api_admin']->page_get_pairs() : [],
            'product_pairs' => $staffService->hasPermission(null, 'product', 'view') ? $this->di['api_admin']->product_get_pairs() : [],
            'uploaded' => $t->getUploadedAssets(),
            'settings' => $settings,
            'current_preset' => $preset,
            'presets' => $service->getThemePresets($t),
            'snippets' => [
                'client-login-form' => 'Client Area Login Form',
                'domain-checker-form' => 'Domain Checker Form',
                'contact-us-form' => 'Contact Us Form',
            ],
        ];

        return $app->render('mod_theme_preset', $data);
    }
}
