<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Servicedomain\Api;

use ClientFence\Validation\Api\RequiredParams;

/**
 * Domain service management.
 */
class Guest extends \ClientFence\Api\AbstractApi
{
    /**
     * Get configured TLDs which can be ordered. Shows only enabled TLDs.
     *
     * @optional bool $allow_register - shows only these TLDs which can be registered
     * @optional bool $allow_transfer - shows only these TLDs which can be transferred
     *
     * @return array - list of TLDs
     */
    public function tlds($data = []): array
    {
        $allow_register = $data['allow_register'] ?? null;
        $allow_transfer = $data['allow_transfer'] ?? null;

        $where = [];
        $where[] = 'active = 1';

        if ($allow_register !== null) {
            $where[] = 'allow_register = 1';
        }

        if ($allow_transfer !== null) {
            $where[] = 'allow_transfer = 1';
        }

        $query = implode(' AND ', $where);

        $tlds = $this->getDi()['db']->find('Tld', $query, []);
        $result = [];
        foreach ($tlds as $model) {
            $result[] = $this->visitorPrices($this->getService()->tldToApiArray($model));
        }

        return $result;
    }

    /**
     * Get TLD pricing information.
     *
     * @return array
     */
    #[RequiredParams(['tld' => 'TLD is missing'])]
    public function pricing($data)
    {
        $model = $this->getService()->tldFindOneByTld($data['tld']);
        if (!$model instanceof \Model_Tld) {
            throw new \ClientFence\InformationException('TLD not found');
        }

        return $this->visitorPrices($this->getService()->tldToApiArray($model));
    }

    /**
     * ClientFence core patch: fixed currency prices (Product/CurrencyPrices.php). A TLD's prices in
     * the visitor's currency (the cart's), as default-currency equivalents the pages convert.
     *
     * @param array<string, mixed> $tld
     *
     * @return array<string, mixed>
     */
    private function visitorPrices(array $tld): array
    {
        try {
            $cart = $this->getDi()['mod_service']('cart');
            if (!$cart instanceof \ClientFence\Mod\Cart\Service) {
                return $tld;
            }
            $db = $this->getDi()['em']->getConnection();
        } catch (\Throwable) {
            return $tld;
        }

        return \ClientFence\Mod\Product\CurrencyPrices::tld($db, $cart->sessionCurrencyCode(), $tld);
    }

    /**
     * Check if domain is available for registration. Domain registrar must be
     * configured in order to get correct results.
     *
     * @return true
     */
    #[RequiredParams([
        'tld' => 'TLD is missing',
        'sld' => 'SLD is missing',
    ])]
    public function check($data): bool
    {
        $this->getDi()['rate_limiter']->consumeOrThrow('domain_lookup_ip', (string) $this->getIp());

        $sld = (string) $data['sld'];
        $validator = $this->getDi()['validator'];
        if (!$validator->isSldValid($sld)) {
            throw new \ClientFence\InformationException('Domain :domain is invalid', [':domain' => $sld]);
        }

        $tld = $this->getService()->tldFindOneByTld($data['tld']);
        if (!$tld instanceof \Model_Tld) {
            throw new \ClientFence\InformationException('Domain availability could not be determined. TLD is not configured.');
        }
        if (!$tld->active) {
            throw new \ClientFence\InformationException('Domain availability could not be determined. TLD is not active.');
        }

        if (!$this->getService()->isDomainAvailable($tld, $sld)) {
            throw new \ClientFence\InformationException('Domain is not available.');
        }

        return true;
    }

    /**
     * Check if domain can be transferred. Domain registrar must be
     * configured in order to get correct results.
     *
     * @return true
     */
    #[RequiredParams([
        'tld' => 'TLD is missing',
        'sld' => 'SLD is missing',
    ])]
    public function can_be_transferred($data): bool
    {
        $this->getDi()['rate_limiter']->consumeOrThrow('domain_lookup_ip', (string) $this->getIp());

        $tld = $this->getService()->tldFindOneByTld($data['tld']);
        if (!$tld instanceof \Model_Tld) {
            throw new \ClientFence\InformationException('TLD is not configured.');
        }
        if (!$tld->active) {
            throw new \ClientFence\InformationException('TLD is not active.');
        }
        if (!$this->getService()->canBeTransferred($tld, $data['sld'])) {
            throw new \ClientFence\InformationException('Domain cannot be transferred.');
        }

        return true;
    }
}
