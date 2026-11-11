<?php

declare(strict_types=1);

namespace ClientFence\Mod\Cookieconsent\Api;

/**
 * Cookie notice settings.
 */
class Admin extends \ClientFence\Api\AbstractApi
{
    /**
     * Get the banner settings.
     */
    public function get_settings(): array
    {
        $this->checkPermissions('cookieconsent', 'manage_settings');

        return $this->getService()->getSettings();
    }

    /**
     * ClientFence core patch (ext cookieconsent): the saved settings for the form, with the language versions and
     * the built-in texts in each turned-on language (`builtin`) and in the default one (`default_builtin`).
     */
    public function get_config(): array
    {
        $this->checkPermissions('cookieconsent', 'manage_settings');
        $service = $this->getService();
        $builtin = $service->builtinTexts();
        $default = (string) \ClientFence\Config::getProperty('i18n.locale', 'en_US');

        return $service->getConfig() + ['builtin' => $builtin, 'default_locale' => $default, 'default_builtin' => $builtin[$default] ?? reset($builtin)];
    }

    /**
     * Save the banner settings.
     *
     * @param array $data message and button (blank: the built-in text), privacy_url (http or https, empty: no link),
     *                    position (bar or card) and translations (lang => message, button)
     */
    public function save_settings($data): bool
    {
        $this->checkPermissions('cookieconsent', 'manage_settings');
        $this->getService()->saveSettings($data);

        return true;
    }
}
