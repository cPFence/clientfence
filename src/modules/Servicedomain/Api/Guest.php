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
    private const LOOKUP_CAPTCHA = 'domain_lookup_captcha_until';
    private const BULK_MAX = 20;
    private const BULK_BATCH = 5;
    private const BULK_SECONDS = 300;

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
        $this->checkLookupCaptcha($data);

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
     * ClientFence core patch (CF-139): one name across up to 20 TLDs (WHMCS Spotlight TLDs). A new search
     * (sld, tlds) counts one lookup, answers the CAPTCHA once, checks the first TLDs and returns a search id
     * while TLDs are left; a call with only that id checks the next TLDs of the same search, never others.
     *
     * @return array{search: ?string, results: list<array<string, mixed>>}
     */
    public function check_bulk($data): array
    {
        $di = $this->getDi();
        $search = is_string($data['search'] ?? null) ? $data['search'] : '';
        if ($search !== '') {
            $di['rate_limiter']->consumeOrThrow('domain_lookup_batch_ip', (string) $this->getIp());
            $item = $di['cache']->getItem('servicedomain_bulk_' . hash('sha256', $search));
            $state = $item->isHit() ? $item->get() : null;
            if (!is_array($state)) {
                throw new \ClientFence\InformationException('This search has expired. Search again.');
            }
        } else {
            $di['rate_limiter']->consumeOrThrow('domain_lookup_ip', (string) $this->getIp());
            $this->checkLookupCaptcha($data);
            $state = ['sld' => is_string($data['sld'] ?? null) ? $data['sld'] : '', 'tlds' => $this->bulkTlds($data['tlds'] ?? null)];
            if (!$di['validator']->isSldValid($state['sld'])) {
                throw new \ClientFence\InformationException('Domain :domain is invalid', [':domain' => $state['sld']]);
            }
            $search = bin2hex(random_bytes(16));
            $item = $di['cache']->getItem('servicedomain_bulk_' . hash('sha256', $search));
        }

        // The batch leaves the stored search before the registrar is asked (calls racing on one id still pay domain_lookup_batch_ip).
        $batch = array_splice($state['tlds'], 0, self::BULK_BATCH);
        if ($state['tlds'] === []) {
            $di['cache']->deleteItem($item->getKey());
        } else {
            $item->set($state);
            $item->expiresAfter(self::BULK_SECONDS);
            $di['cache']->save($item);
        }

        $results = [];
        foreach ($batch as $name) {
            $tld = $this->getService()->tldFindOneByTld($name);
            if (!$tld instanceof \Model_Tld) {
                continue;
            }
            try {
                $available = $tld->active ? (bool) $this->getService()->isDomainAvailable($tld, $state['sld']) : null;
            } catch (\Exception) {
                $available = null; // the registrar could not answer: shown as unknown
            }
            $results[] = ['domain' => $state['sld'] . $tld->tld, 'available' => $available] + $this->visitorPrices($this->getService()->tldToApiArray($tld));
        }

        return ['search' => $state['tlds'] === [] ? null : $search, 'results' => $results];
    }

    /**
     * ClientFence core patch (CF-139): the TLDs staff pinned for the domain search, in their order.
     *
     * @return list<string>
     */
    public function pinned_tlds($data = []): array
    {
        return $this->getService()->pinnedTlds();
    }

    /**
     * The TLDs of a new bulk search: 1 to 20 names of TLDs on sale, without repeats.
     *
     * @return list<string>
     */
    private function bulkTlds(mixed $tlds): array
    {
        if (!is_array($tlds) || $tlds === [] || array_filter($tlds, is_string(...)) !== $tlds) {
            throw new \ClientFence\InformationException('TLD is missing');
        }
        if (count($tlds) > self::BULK_MAX) {
            throw new \ClientFence\InformationException('Choose up to :count extensions.', [':count' => self::BULK_MAX]);
        }
        $names = [];
        foreach ($tlds as $name) {
            $tld = $this->getService()->tldFindOneByTld($name);
            if (!$tld instanceof \Model_Tld) {
                throw new \ClientFence\InformationException('Domain availability could not be determined. TLD is not configured.');
            }
            if (!$tld->active || !$tld->allow_register) {
                throw new \ClientFence\InformationException('Domain availability could not be determined. TLD is not active.');
            }
            $names[$tld->tld] = $tld->tld;
        }

        return array_values($names);
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
        $this->checkLookupCaptcha($data);

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

    /**
     * Whether the next domain search needs the CAPTCHA: guests answer it once, then search
     * without it for an hour (signed-in clients never see it).
     */
    public function lookup_captcha($data = []): bool
    {
        $di = $this->getDi();
        if ($di['auth']->isClientLoggedIn() || !$di['mod_service']('extension')->isExtensionActive('mod', 'antispam')) {
            return false;
        }
        if (empty($di['mod_config']('Antispam')['captcha_enabled'])) {
            return false;
        }

        return (int) ($di['session']->get(self::LOOKUP_CAPTCHA) ?? 0) < time();
    }

    // ClientFence core patch: domain searches ask guests for the CAPTCHA once an hour, on top of the lookup rate limit.
    private function checkLookupCaptcha(array $data): void
    {
        if (!$this->lookup_captcha()) {
            return;
        }
        $this->getDi()['mod_service']('Antispam')->checkCaptcha($data);
        $this->getDi()['session']->set(self::LOOKUP_CAPTCHA, time() + 3600);
    }
}
