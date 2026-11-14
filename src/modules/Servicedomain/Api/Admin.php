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

use ClientFence\PaginationOptions;
use ClientFence\Validation\Api\RequiredParams;

/**
 * Domain order management.
 */
class Admin extends \ClientFence\Api\AbstractApi
{
    /**
     * Update domain service.
     * Does not send actions to domain registrar. Used to sync domain details
     * on FOSSBilling.
     *
     * @optional string $ns1 - 1 Nameserver hostname, ie: ns1.mydomain.com
     * @optional string $ns2 - 2 Nameserver hostname, ie: ns2.mydomain.com
     * @optional string $ns3 - 3 Nameserver hostname, ie: ns3.mydomain.com
     * @optional string $ns4 - 4 Nameserver hostname, ie: ns4.mydomain.com
     * @optional int $period - domain registration years
     * @optional bool $privacy - flag to define if domain privacy protection is enabled/disabled
     * @optional bool $locked - flag to define if domain is locked or not
     * @optional string $transfer_code - domain EPP code
     *
     * @return bool
     */
    #[RequiredParams(['order_id' => 'Order ID is missing'])]
    public function update($data)
    {
        $this->checkPermissions('servicedomain', 'manage_domains');

        $s = $this->_getService($data);

        return $this->getService()->updateDomain($s, $data);
    }

    /**
     * Update domain nameservers.
     *
     * @optional string $ns3 - 3 Nameserver hostname, ie: ns3.mydomain.com
     * @optional string $ns4 - 4 Nameserver hostname, ie: ns4.mydomain.com
     *
     * @return bool
     */
    #[RequiredParams(['order_id' => 'Order ID is missing'])]
    public function update_nameservers($data)
    {
        $this->checkPermissions('servicedomain', 'manage_domains');

        $s = $this->_getService($data);

        return $this->getService()->updateNameservers($s, $data);
    }

    /**
     * Update domain contact details.
     *
     * @return bool
     */
    #[RequiredParams(['order_id' => 'Order ID is missing'])]
    public function update_contacts($data)
    {
        $this->checkPermissions('servicedomain', 'manage_domains');

        $s = $this->_getService($data);

        return $this->getService()->updateContacts($s, $data);
    }

    /**
     * Enable domain privacy protection.
     *
     * @return bool
     */
    #[RequiredParams(['order_id' => 'Order ID is missing'])]
    public function enable_privacy_protection($data)
    {
        $this->checkPermissions('servicedomain', 'manage_domains');

        $s = $this->_getService($data);

        return $this->getService()->enablePrivacyProtection($s);
    }

    /**
     * Disable domain privacy protection.
     *
     * @return bool
     */
    #[RequiredParams(['order_id' => 'Order ID is missing'])]
    public function disable_privacy_protection($data)
    {
        $this->checkPermissions('servicedomain', 'manage_domains');

        $s = $this->_getService($data);

        return $this->getService()->disablePrivacyProtection($s);
    }

    /**
     * Synchronize domain registration details with the registrar.
     */
    #[RequiredParams(['order_id' => 'Order ID is missing'])]
    public function sync($data): bool
    {
        $this->checkPermissions('servicedomain', 'manage_domains');

        $s = $this->_getService($data);
        // ClientFence core patch: Sync on a tracked transfer asks the registrar where it stands (DomainSync.php).
        if ($s->action === \ClientFence\Mod\Servicedomain\DomainSync::PENDING) {
            \ClientFence\Mod\Servicedomain\DomainSync::checkOne($this->di, $s);

            return true;
        }
        $this->getService()->synchronizeDomain($s);

        return true;
    }

    /**
     * Get domain transfer code.
     *
     * @return bool
     */
    #[RequiredParams(['order_id' => 'Order ID is missing'])]
    public function get_transfer_code($data)
    {
        $this->checkPermissions('servicedomain', 'manage_domains');

        $s = $this->_getService($data);

        return $this->getService()->getTransferCode($s);
    }

    /**
     * Lock domain.
     *
     * @return bool
     */
    #[RequiredParams(['order_id' => 'Order ID is missing'])]
    public function lock($data)
    {
        $this->checkPermissions('servicedomain', 'manage_domains');

        $s = $this->_getService($data);

        return $this->getService()->lock($s);
    }

    /**
     * Unlock domain.
     *
     * @return bool
     */
    #[RequiredParams(['order_id' => 'Order ID is missing'])]
    public function unlock($data)
    {
        $this->checkPermissions('servicedomain', 'manage_domains');

        $s = $this->_getService($data);

        return $this->getService()->unlock($s);
    }

    /**
     * ClientFence core patch: marks the Email registrar's requests done (Servicedomain/DomainTickets.php): stores their
     * values and replies on the domain's ticket.
     *
     * @optional string $transfer_code - the transfer code (EPP code) for the client
     * @optional string $expires_at - the domain's expiry date at the registrar
     */
    #[RequiredParams(['order_id' => 'Order ID is missing'])]
    public function requests_done($data): bool
    {
        $this->checkPermissions('servicedomain', 'manage_domains');

        return $this->getService()->requestsDone($this->_getService($data), $this->getIdentity(), $data);
    }

    /**
     * Get paginated top level domains list.
     *
     * @return array
     */
    public function tld_get_list($data)
    {
        $this->checkPermissions('servicedomain', 'manage_tlds');
        [$sql, $params] = $this->getService()->tldGetSearchQuery($data);
        $pager = $this->getDi()['pager']->getPaginatedResultSet($sql, $params, PaginationOptions::fromArray($data));

        foreach ($pager['list'] as $key => $tldArr) {
            $tld = $this->getDi()['db']->getExistingModelById('Tld', $tldArr['id'], sprintf('Tld #%s not found', $tldArr['id']));
            $pager['list'][$key] = $this->getService()->tldToApiArray($tld, $this->identity);
        }

        return $pager;
    }

    /**
     * Get top level domain details.
     *
     * @return array
     *
     * @throws \ClientFence\InformationException
     */
    #[RequiredParams(['tld' => 'TLD is missing'])]
    public function tld_get($data)
    {
        $this->checkPermissions('servicedomain', 'manage_tlds');

        $model = $this->getService()->tldFindOneByTld($data['tld']);
        if (!$model instanceof \Model_Tld) {
            throw new \ClientFence\InformationException('TLD not found');
        }

        return $this->getService()->tldToApiArray($model, $this->identity);
    }

    /**
     * Get top level domain details by id.
     *
     * @return array
     *
     * @throws \ClientFence\InformationException
     */
    #[RequiredParams(['id' => 'ID is missing'])]
    public function tld_get_id($data)
    {
        $this->checkPermissions('servicedomain', 'manage_tlds');

        $model = $this->getService()->tldFindOneById($data['id']);
        if (!$model instanceof \Model_Tld) {
            throw new \ClientFence\InformationException('TLD not found');
        }

        return $this->getService()->tldToApiArray($model, $this->identity);
    }

    /**
     * Delete top level domain.
     *
     * @return bool
     *
     * @throws \ClientFence\InformationException
     */
    #[RequiredParams(['tld' => 'TLD is missing'])]
    public function tld_delete($data)
    {
        $this->checkPermissions('servicedomain', 'manage_tlds');

        $normalizedTld = $this->getService()->normalizeTld($data['tld']);
        $model = $this->getService()->tldFindOneByTld($normalizedTld);

        if (!$model instanceof \Model_Tld) {
            throw new \ClientFence\InformationException('TLD not found');
        }
        $service_domains = $this->getDi()['db']->find(
            'ServiceDomain',
            "LOWER(TRIM(TRAILING '.' FROM TRIM(tld))) IN (?, ?)",
            [$normalizedTld, ltrim($normalizedTld, '.')],
        );
        $count = \ClientFence\Tools::safeCount($service_domains);
        if ($count > 0) {
            throw new \ClientFence\InformationException('TLD is used by :count: domains', [':count:' => $count], 707);
        }

        return $this->getService()->tldRm($model);
    }

    /**
     * Add new top level domain.
     *
     * @return bool
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams([
        'tld' => 'TLD is missing',
        'tld_registrar_id' => 'TLD registrar ID is missing',
        'price_registration' => 'Registration price is missing',
        'price_renew' => 'Renewal price is missing',
        'price_transfer' => 'Transfer price is missing',
    ])]
    public function tld_create($data)
    {
        $this->checkPermissions('servicedomain', 'manage_tlds');

        if ($this->getService()->tldAlreadyRegistered($data['tld'])) {
            throw new \ClientFence\InformationException('TLD already registered');
        }

        return $this->getService()->tldCreate($data);
    }

    /**
     * Update top level domain.
     *
     * @optional int $tld_registrar_id - domain registrar id
     * @optional float $price_registration - registration price
     * @optional float $price_renew - renewal price
     * @optional float $price_transfer - transfer price
     *
     * @return bool
     *
     * @throws \ClientFence\InformationException
     */
    #[RequiredParams(['tld' => 'TLD is missing'])]
    public function tld_update($data)
    {
        $this->checkPermissions('servicedomain', 'manage_tlds');

        $model = $this->getService()->tldFindOneByTld($data['tld']);
        if (!$model instanceof \Model_Tld) {
            throw new \ClientFence\InformationException('TLD not found');
        }
        // ClientFence core patch: fixed currency prices (Product/CurrencyPrices.php), saved with the
        // TLD's prices: first, so a wrong price changes nothing.
        if (is_array($data['currency_prices'] ?? null)) {
            \ClientFence\Mod\Product\CurrencyPrices::ensure($this->getDi());
            \ClientFence\Mod\Product\CurrencyPrices::save($this->getDi()['em']->getConnection(), 'tld', (int) $model->id, $data['currency_prices']);
        }

        return $this->getService()->tldUpdate($model, $data);
    }

    /**
     * Get paginated registrars list.
     *
     * @return array
     */
    public function registrar_get_list($data)
    {
        $this->checkPermissions('servicedomain', 'manage_registrars');
        [$sql, $params] = $this->getService()->registrarGetSearchQuery($data);
        $pager = $this->getDi()['pager']->getPaginatedResultSet($sql, $params, PaginationOptions::fromArray($data));

        $registrars = $this->getDi()['db']->find('TldRegistrar', 'ORDER By name ASC');

        $registrarsArr = [];
        foreach ($registrars as $registrar) {
            $registrarsArr[] = $this->getService()->registrarToApiArray($registrar);
        }

        $pager['list'] = $registrarsArr;

        return $pager;
    }

    /**
     * Get registrars pairs.
     *
     * @return array
     */
    public function registrar_get_pairs($data)
    {
        $this->checkPermissions('servicedomain', 'manage_registrars');

        return $this->getService()->registrarGetPairs();
    }

    /**
     * Get available registrars for install.
     *
     * @return array
     */
    public function registrar_get_available($data)
    {
        $this->checkPermissions('servicedomain', 'manage_registrars');

        return $this->getService()->registrarGetAvailable();
    }

    /**
     * Install domain registrar.
     *
     * @return bool
     */
    #[RequiredParams(['code' => 'Registrar code is missing'])]
    public function registrar_install($data)
    {
        $this->checkPermissions('servicedomain', 'manage_registrars');

        $code = $data['code'];
        if (!in_array($code, $this->getService()->registrarGetAvailable())) {
            throw new \ClientFence\Exception('Registrar is not available for installation.');
        }

        return $this->getService()->registrarCreate($data['code']);
    }

    /**
     * Uninstall domain registrar.
     *
     * @return bool
     */
    #[RequiredParams(['id' => 'Registrar ID is missing'])]
    public function registrar_delete($data)
    {
        $this->checkPermissions('servicedomain', 'manage_registrars');

        $model = $this->getDi()['db']->getExistingModelById('TldRegistrar', $data['id'], 'Registrar not found');

        return $this->getService()->registrarRm($model);
    }

    /**
     * Copy domain registrar.
     *
     * @return bool
     */
    #[RequiredParams(['id' => 'Registrar ID is missing'])]
    public function registrar_copy($data)
    {
        $this->checkPermissions('servicedomain', 'manage_registrars');

        $model = $this->getDi()['db']->getExistingModelById('TldRegistrar', $data['id'], 'Registrar not found');

        return $this->getService()->registrarCopy($model);
    }

    /**
     * Get domain registrar details.
     *
     * @return array
     */
    #[RequiredParams(['id' => 'Registrar ID is missing'])]
    public function registrar_get($data)
    {
        $this->checkPermissions('servicedomain', 'manage_registrars');

        $registrar = $this->getDi()['db']->getExistingModelById('TldRegistrar', $data['id'], 'Registrar not found');

        return $this->getService()->registrarToApiArray($registrar);
    }

    /**
     * Sync domain expiration dates with registrars.
     * This action is run once a month.
     *
     * @return bool
     */
    public function batch_sync_expiration_dates($data)
    {
        $this->checkPermissions('servicedomain', 'manage_domains');

        return $this->getService()->batchSyncExpirationDates();
    }

    /**
     * Update domain registrar.
     *
     * @optional string $title - registrar title
     * @optional array $config - registrar configuration array
     *
     * @return bool
     */
    #[RequiredParams(['id' => 'Registrar ID is missing'])]
    public function registrar_update($data)
    {
        $this->checkPermissions('servicedomain', 'manage_registrars');

        $model = $this->getDi()['db']->getExistingModelById('TldRegistrar', $data['id'], 'Registrar not found');

        return $this->getService()->registrarUpdate($model, $data);
    }

    /**
     * ClientFence core patch: a registrar's TLD price settings and price list state (PriceSync.php).
     */
    #[RequiredParams(['id' => 'Registrar ID is missing'])]
    public function price_sync_get($data): array
    {
        $this->checkPermissions('servicedomain', 'manage_tlds');
        $registrar = $this->getDi()['db']->getExistingModelById('TldRegistrar', $data['id'], 'Registrar not found');
        $db = $this->getDi()['em']->getConnection();

        return [
            'id' => (int) $registrar->id,
            'name' => (string) $registrar->name,
            'supported' => \ClientFence\Mod\Servicedomain\PriceSync::supported($this->getDi(), $registrar),
            'settings' => \ClientFence\Mod\Servicedomain\PriceSync::settings($this->getDi(), (int) $registrar->id),
            'fetched_at' => $db->fetchOne('SELECT MAX(fetched_at) FROM tld_registrar_cost WHERE tld_registrar_id = ?', [$registrar->id]) ?: null,
            'currency' => $db->fetchOne('SELECT code FROM currency WHERE is_default = 1') ?: null,
        ];
    }

    /** ClientFence core patch: saves a registrar's markups, rounding and scheduled sync mode. */
    #[RequiredParams(['id' => 'Registrar ID is missing'])]
    public function price_sync_update($data): array
    {
        $this->checkPermissions('servicedomain', 'manage_tlds');

        return \ClientFence\Mod\Servicedomain\PriceSync::saveSettings($this->getDi(), (int) $data['id'], $data);
    }

    /** ClientFence core patch: gets the registrar's price list for up to 15 seconds; call again until `done`. */
    #[RequiredParams(['id' => 'Registrar ID is missing'])]
    public function price_sync_fetch($data): array
    {
        $this->checkPermissions('servicedomain', 'manage_tlds');

        return \ClientFence\Mod\Servicedomain\PriceSync::fetch($this->getDi(), (int) $data['id'], 15, !empty($data['refresh']));
    }

    /** ClientFence core patch: old and new prices of every TLD in the registrar's price list. */
    #[RequiredParams(['id' => 'Registrar ID is missing'])]
    public function price_sync_preview($data): array
    {
        $this->checkPermissions('servicedomain', 'manage_tlds');

        return \ClientFence\Mod\Servicedomain\PriceSync::preview($this->getDi(), (int) $data['id']);
    }

    /**
     * ClientFence core patch: applies the ticked TLDs (`tlds`; `move`: ticked TLDs of other
     * registrars to move here) of a preview whose `fingerprint` still matches.
     */
    #[RequiredParams(['id' => 'Registrar ID is missing', 'fingerprint' => 'Check the preview first'])]
    public function price_sync_apply($data): array
    {
        $this->checkPermissions('servicedomain', 'manage_tlds');
        $list = static fn (mixed $v): array => array_values(array_filter(array_map('strval', is_array($v) ? $v : explode(',', (string) $v)), static fn (string $t): bool => trim($t) !== ''));
        $identity = $this->getIdentity();

        return \ClientFence\Mod\Servicedomain\PriceSync::apply(
            $this->getDi(),
            (int) $data['id'],
            $list($data['tlds'] ?? []),
            $list($data['move'] ?? []),
            (string) $data['fingerprint'],
            $identity instanceof \Model_Admin ? (int) $identity->id : null,
        );
    }

    #[RequiredParams(['order_id' => 'Order ID is missing'])]
    protected function _getService($data)
    {
        $orderId = $data['order_id'];

        $order = $this->getDi()['db']->getExistingModelById('ClientOrder', $orderId, 'Order not found');

        $orderService = $this->getDi()['mod_service']('order');
        $s = $orderService->getOrderService($order);

        if (!$s instanceof \Model_ServiceDomain) {
            throw new \ClientFence\Exception('Domain order is not activated');
        }

        return $s;
    }
}
