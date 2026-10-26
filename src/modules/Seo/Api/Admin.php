<?php

declare(strict_types=1);
/**
 * FOSSBilling.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license   Apache-2.0
 *
 * Copyright FOSSBilling 2022
 *
 * This source file is subject to the Apache-2.0 License that is bundled
 * with this source code in the file LICENSE
 */

namespace ClientFence\Mod\Seo\Api;

class Admin extends \ClientFence\Api\AbstractApi
{
    /**
     * Returns the IndexNow key, the last submit and the verification codes.
     */
    public function info($data): array
    {
        $this->checkPermissions('seo', 'view');

        return $this->getService()->getInfo();
    }

    /**
     * Submits the sitemap's addresses to IndexNow now.
     */
    public function submit(): array
    {
        $this->checkPermissions('seo', 'manage');

        return $this->getService()->submit(true) ?? throw new \ClientFence\InformationException('Save the settings first to create the IndexNow key.');
    }

    /**
     * Saves the verification codes.
     *
     * @param array $data google_verification, bing_verification: the content value only, or empty
     */
    public function save_settings($data): bool
    {
        $this->checkPermissions('seo', 'manage_settings');
        $this->getService()->saveVerification($data);

        return true;
    }
}
