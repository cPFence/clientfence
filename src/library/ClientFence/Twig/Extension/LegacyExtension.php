<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling.
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Twig\Extension;

use Twig\Attribute\AsTwigFilter;

class LegacyExtension
{
    public function __construct(private ?\Pimple\Container $di)
    {
    }

    #[AsTwigFilter('ip_country_name')]
    public function ipCountryName(?string $ip): string
    {
        if ($ip === null) {
            return '';
        }

        try {
            $record = $this->di['geoip']->country($ip);

            return $record->name;
        } catch (\Exception) {
            return '';
        }
    }

    #[AsTwigFilter('ip_country_code')]
    public function ipCountryCode(?string $ip): string
    {
        if ($ip === null) {
            return '';
        }

        try {
            $record = $this->di['geoip']->country($ip);

            return strtolower($record->isoCode ?? '');
        } catch (\Exception) {
            return '';
        }
    }

    #[AsTwigFilter('mod_asset_url')]
    public function modAssetUrl(?string $asset, ?string $module): string
    {
        if ($asset === null || $module === null) {
            return '';
        }

        // ClientFence core patch: the ?v= changes with each ClientFence or module update, so browsers and caches fetch the new file.
        try {
            $version = (string) ($this->di['mod']($module)->getManifest()['version'] ?? '');
        } catch (\Exception) {
            $version = '';
        }

        return SYSTEM_URL . 'modules/' . ucfirst($module) . "/assets/{$asset}?v=" . substr(hash('sha256', \ClientFence\Version::CLIENTFENCE . '/' . $version), 0, 10);
    }

    #[AsTwigFilter('period_title', isSafe: ['html'])]
    public function periodTitle(?string $period): string
    {
        if ($period === null) {
            return '';
        }

        return $this->di['api_guest']->system_period_title(['code' => $period]);
    }
}
