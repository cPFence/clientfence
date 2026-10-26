<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Cookieconsent;

use ClientFence\InjectionAwareInterface;

class Service implements InjectionAwareInterface
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

    public function getModulePermissions(): array
    {
        return [
            'manage_settings' => [],
        ];
    }

    public function getSettings(): array
    {
        $config = $this->di['mod_config']('cookieconsent');
        $message = trim((string) ($config['message'] ?? ''));
        $button = trim((string) ($config['button'] ?? ''));
        $url = (string) ($config['privacy_url'] ?? '');

        return [
            'message' => $message !== '' ? $message : __trans('This website uses cookies. By continuing to use this website, you consent to our use of these cookies.'),
            'button' => $button !== '' ? $button : __trans('Accept'),
            // ClientFence core patch (ext cookieconsent): extension/config_save can store any value, so only a web address becomes a link.
            'privacy_url' => $this->isWebAddress($url) ? $url : '',
            'position' => ($config['position'] ?? '') === 'card' ? 'card' : 'bar',
        ];
    }

    public function saveSettings(array $data): void
    {
        $message = trim((string) ($data['message'] ?? ''));
        if ($message === '' || mb_strlen($message) > 1000) {
            throw new \ClientFence\InformationException('Enter a message of up to 1000 characters.');
        }
        $button = trim((string) ($data['button'] ?? ''));
        if (mb_strlen($button) > 40) {
            throw new \ClientFence\InformationException('The button text can be up to 40 characters.');
        }
        $url = trim((string) ($data['privacy_url'] ?? ''));
        if ($url !== '' && !$this->isWebAddress($url)) {
            throw new \ClientFence\InformationException('The Privacy Policy address must start with https:// or http://.');
        }
        $position = (string) ($data['position'] ?? '');
        if (!in_array($position, ['bar', 'card'], true)) {
            throw new \ClientFence\InformationException('Choose where the banner shows.');
        }

        $this->di['mod_service']('extension')->setConfig([
            'ext' => 'mod_cookieconsent',
            'message' => $message,
            'button' => $button,
            'privacy_url' => $url,
            'position' => $position,
        ]);
    }

    private function isWebAddress(string $url): bool
    {
        return strlen($url) <= 300 && filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
