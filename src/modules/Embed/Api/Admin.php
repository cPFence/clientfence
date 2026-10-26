<?php

declare(strict_types=1);

namespace ClientFence\Mod\Embed\Api;

/**
 * Embed Parts settings.
 */
class Admin extends \ClientFence\Api\AbstractApi
{
    /**
     * Get the websites allowed to embed the parts.
     */
    public function get_settings(): array
    {
        $this->checkPermissions('embed', 'manage_settings');

        return ['allowed_origins' => $this->getService()->getAllowedOrigins()];
    }

    /**
     * Save the websites allowed to embed the parts.
     *
     * @param array $data allowed_origins: one origin per line (https://example.com), up to 20; empty: this site only
     */
    public function save_settings($data): bool
    {
        $this->checkPermissions('embed', 'manage_settings');
        if (!is_string($data['allowed_origins'] ?? '')) {
            throw new \ClientFence\InformationException('Enter one website per line.');
        }
        $this->getService()->saveAllowedOrigins($data['allowed_origins'] ?? '');

        return true;
    }
}
