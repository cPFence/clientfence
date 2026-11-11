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
use Symfony\Contracts\Cache\ItemInterface;

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

    /** The banner in the page's language: its own text, else the default one, else the built-in text translated. */
    public function getSettings(): array
    {
        $config = $this->getConfig();
        $own = $config['translations'][\Locale::getDefault()] ?? [];
        $builtin = self::builtin();
        $url = (string) ($config['privacy_url'] ?? '');

        return [
            'message' => ($own['message'] ?? '') ?: ($config['message'] ?: $builtin['message']),
            'button' => ($own['button'] ?? '') ?: ($config['button'] ?: $builtin['button']),
            // ClientFence core patch (ext cookieconsent): extension/config_save can store any value, so only a web address becomes a link.
            'privacy_url' => $this->isWebAddress($url) ? $url : '',
            'position' => ($config['position'] ?? '') === 'card' ? 'card' : 'bar',
        ];
    }

    /**
     * The saved settings for the admin form: message and button blank for the built-in text, and `translations`
     * (lang => message, button) for the languages with their own.
     */
    public function getConfig(): array
    {
        $config = $this->di['mod_config']('cookieconsent');
        $message = trim((string) ($config['message'] ?? ''));
        $button = trim((string) ($config['button'] ?? ''));
        // ClientFence core patch (ext cookieconsent): before 1.0.45 the form saved the built-in texts in the admin's
        // language, which then showed in every language: they count as blank. Cached, as every client page reads this.
        [$builtinMessage, $builtinButton] = $this->di['cache']->get('cookieconsent_builtin_' . hash('sha256', $message . "\0" . $button), function (ItemInterface $item) use ($message, $button): array {
            $item->expiresAfter(86400);
            $texts = $this->builtinTexts();

            return [in_array($message, array_column($texts, 'message'), true), in_array($button, array_column($texts, 'button'), true)];
        });

        return [
            'message' => $builtinMessage ? '' : $message,
            'button' => $builtinButton ? '' : $button,
            'privacy_url' => (string) ($config['privacy_url'] ?? ''),
            'position' => (string) ($config['position'] ?? ''),
            'translations' => is_array($config['translations'] ?? null) ? $config['translations'] : [],
        ];
    }

    /** The built-in message and button in each turned-on language, for the admin form's language versions. */
    public function builtinTexts(): array
    {
        $texts = [];
        foreach (\ClientFence\i18n::getLocales() as $lang) {
            $texts[$lang] = \ClientFence\Translate::with($lang, self::builtin(...));
        }

        return $texts;
    }

    /** The built-in texts in the current language (literals, so the translation catalogue finds them). */
    private static function builtin(): array
    {
        return [
            'message' => __trans('This website uses cookies. By continuing to use this website, you consent to our use of these cookies.'),
            'button' => __trans('Accept'),
        ];
    }

    public function saveSettings(array $data): void
    {
        [$message, $button] = $this->texts($data);
        $translations = [];
        $installed = array_merge(\ClientFence\i18n::getLocales(), \ClientFence\i18n::getLocales(false, true));
        foreach (is_array($data['translations'] ?? null) ? $data['translations'] : [] as $lang => $texts) {
            if (!in_array((string) $lang, $installed, true)) {
                throw new \ClientFence\InformationException('Invalid locale code: :code', [':code' => (string) $lang]);
            }
            [$langMessage, $langButton] = $this->texts(is_array($texts) ? $texts : []);
            if ($langMessage !== '' || $langButton !== '') {
                $translations[(string) $lang] = ['message' => $langMessage, 'button' => $langButton];
            }
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
            'translations' => $translations,
        ]);
    }

    /** A message and button text checked for length; blank for the built-in text. */
    private function texts(array $data): array
    {
        $message = trim((string) ($data['message'] ?? ''));
        if (mb_strlen($message) > 1000) {
            throw new \ClientFence\InformationException('Enter a message of up to 1000 characters.');
        }
        $button = trim((string) ($data['button'] ?? ''));
        if (mb_strlen($button) > 40) {
            throw new \ClientFence\InformationException('The button text can be up to 40 characters.');
        }

        return [$message, $button];
    }

    private function isWebAddress(string $url): bool
    {
        return strlen($url) <= 300 && filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
