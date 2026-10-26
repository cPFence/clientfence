<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Extension\Api;

use ClientFence\Validation\Api\RequiredParams;

/**
 * Extensions.
 */
class Guest extends \ClientFence\Api\AbstractApi
{
    /**
     * Checks if extensions is available.
     *
     * @return bool
     */
    public function is_on($data)
    {
        $service = $this->getService();
        if (isset($data['mod']) && !empty($data['mod'])) {
            return $service->isExtensionActive('mod', $data['mod']);
        }

        if (isset($data['id']) && !empty($data['type'])) {
            return $service->isExtensionActive($data['type'], $data['id']);
        }

        return true;
    }

    /**
     * Retrieve extension public settings.
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['ext' => 'Parameter ext is missing'])]
    public function settings($data): array
    {
        $service = $this->getService();
        // ClientFence core patch (0.8.9 port): only available modules expose public settings.
        if (!is_string($data['ext'])) {
            return [];
        }
        $ext = strtolower($data['ext']);
        $module = str_starts_with($ext, 'mod_') ? substr($ext, 4) : $ext;
        if (!in_array($module, $service->getCoreAndActiveModules(), true)) {
            return [];
        }

        $config = $service->getConfig($ext);

        return (isset($config['public']) && is_array($config['public'])) ? $config['public'] : [];
    }

    /**
     * Retrieve list of available languages.
     *
     * @return array
     */
    public function languages($deep = false)
    {
        return \ClientFence\i18n::getLocales($deep);
    }
}
