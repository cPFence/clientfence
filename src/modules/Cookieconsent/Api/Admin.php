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
     * Save the banner settings.
     *
     * @param array $data message, button, privacy_url (http or https, empty: no link) and position (bar or card)
     */
    public function save_settings($data): bool
    {
        $this->checkPermissions('cookieconsent', 'manage_settings');
        $this->getService()->saveSettings($data);

        return true;
    }
}
