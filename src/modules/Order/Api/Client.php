<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

/**
 * Client orders management.
 */

namespace ClientFence\Mod\Order\Api;

use ClientFence\Mod\Client\Entity\Client as ClientEntity;
use ClientFence\Mod\Order\Entity\Order;
use ClientFence\PaginationOptions;
use ClientFence\Validation\Api\RequiredParams;

class Client extends \ClientFence\Api\AbstractApi
{
    /**
     * Get list of orders.
     *
     * @return array
     */
    public function get_list($data)
    {
        $identity = $this->getIdentity();
        $clientId = $this->getClientId($identity);
        $data['client_id'] = $clientId;
        // ClientFence: no filtering on the order meta (staff-only values could be read back that way;
        // the client pages never filter on it).
        unset($data['meta']);

        if (isset($data['expiring'])) {
            [$query, $bindings] = $this->getService()->getSoonExpiringActiveOrdersQuery($data);
        } else {
            [$query, $bindings] = $this->getService()->getSearchQuery($data);
        }

        $pager = $this->getDi()['pager']->getPaginatedResultSet($query, $bindings, PaginationOptions::fromArray($data));

        $pager['list'] = $this->getService()->getBatchForApi(
            array_column($pager['list'], 'id'),
            $identity
        );

        return $pager;
    }

    /**
     * Get order details.
     *
     * @return array
     */
    public function get($data)
    {
        $model = $this->_getOrder($data);

        // ClientFence: as the client (no staff notes or staff-only meta).
        return $this->getService()->toApiArray($model, true, $this->getIdentity());
    }

    /**
     * Get order addons.
     */
    public function addons($data): array
    {
        $model = $this->_getOrder($data);
        $list = $this->getService()->getOrderAddonsList($model);
        $result = [];
        foreach ($list as $order) {
            $result[] = $this->getService()->toApiArray($order, true, $this->getIdentity());
        }

        return $result;
    }

    /**
     * Get order service. Order must be activated before service can be retrieved.
     *
     * @return array
     */
    public function service($data)
    {
        $order = $this->_getOrder($data);
        $status = $order instanceof Order ? $order->getStatus() : $order->status;

        if ($status !== Order::STATUS_ACTIVE) {
            throw new \ClientFence\InformationException('Order is not active');
        }

        return $this->getService()->getOrderServiceData($order, $this->getIdentity());
    }

    /**
     * List of product pairs offered as an upgrade.
     *
     * @return array
     */
    public function upgradables($data)
    {
        $model = $this->_getOrder($data);
        $productService = $this->di['mod_service']('product');
        $productId = $model instanceof Order ? $model->getProductId() : $model->product_id;

        return $productService->getUpgradablePairsByProductId((int) $productId);
    }

    /**
     * ClientFence core patch: what an active service can be upgraded or downgraded to, with the
     * prorated price (Order\Upgrades; WHMCS-style upgrades).
     *
     * @return array{available: bool, reason: string, current: array<string, mixed>, options: list<array<string, mixed>>, waiting: array<string, mixed>|null, options_change: bool, product_options: list<array<string, mixed>>}
     */
    public function upgrade_options($data): array
    {
        return \ClientFence\Mod\Order\Upgrades::options($this->getDi(), $this->upgradeOrder($data));
    }

    /**
     * ClientFence core patch: upgrades or downgrades a service. A positive amount makes an invoice
     * (the change applies when it is paid; invoice_hash leads to it), else the change applies now
     * and any credit goes to the account balance.
     *
     * @return array{id: int, status: string, amount: float, invoice_hash: ?string}
     */
    #[RequiredParams(['product_id' => 'Choose the new product', 'period' => 'Choose the billing cycle'])]
    public function upgrade($data): array
    {
        \ClientFence\Mod\Clientfence\Service::ensureTables($this->getDi(), true);

        // Configurable options (Product/Options.php): `options` changes them alone.
        $options = isset($data['options']) && is_array($data['options']) ? $data['options'] : null;

        return \ClientFence\Mod\Order\Upgrades::request($this->getDi(), $this->upgradeOrder($data), (int) $data['product_id'], (string) $data['period'], null, $options);
    }

    /**
     * ClientFence core patch: the price of changing a service's configurable options
     * (Product/Options.php; Order\Upgrades::optionsQuote()): `options` is the new choice.
     *
     * @return array<string, mixed>
     */
    public function upgrade_options_quote($data): array
    {
        $options = isset($data['options']) && is_array($data['options']) ? $data['options'] : [];

        return \ClientFence\Mod\Order\Upgrades::optionsQuote($this->getDi(), $this->upgradeOrder($data), $options);
    }

    /**
     * Can delete only pending setup and failed setup orders.
     */
    public function delete($data)
    {
        $model = $this->_getOrder($data);
        $status = $model instanceof Order ? $model->getStatus() : $model->status;
        if (!in_array($status, [Order::STATUS_PENDING_SETUP, Order::STATUS_FAILED_SETUP])) {
            throw new \ClientFence\InformationException('Only pending and failed setup orders can be deleted.');
        }
        // ClientFence core patch: an order that was paid for stays (its paid invoice would keep no order and the
        // failed action would be dismissed); staff can still delete it.
        $paid = $this->getDi()['dbal']->fetchOne(
            'SELECT 1 FROM invoice_item ii JOIN invoice i ON i.id = ii.invoice_id WHERE ii.type = ? AND ii.rel_id = ? AND i.status IN (?, ?) LIMIT 1',
            [\Model_InvoiceItem::TYPE_ORDER, (string) ($model instanceof Order ? $model->getId() : $model->id), \Model_Invoice::STATUS_PAID, \Model_Invoice::STATUS_REFUNDED]
        );
        if ($paid) {
            throw new \ClientFence\InformationException('A paid order cannot be deleted. Please contact support.');
        }

        return $this->getService()->deleteFromOrder($model);
    }

    protected function _getOrder($data)
    {
        $required = [
            'id' => 'Order id required',
        ];
        $this->getDi()['validator']->checkRequiredParamsForArray($required, $data);

        $order = $this->findOrderForIdentity($this->getIdentity(), (int) $data['id']);
        if (!$order instanceof Order && !$order instanceof \Model_ClientOrder) {
            // ClientFence core patch: a 404, so the client area shows its own "not found" page.
            throw new \ClientFence\InformationException('Order not found', [], 404);
        }

        return $order;
    }

    /** The client's order (ownership checked by _getOrder()) as the upgrade code needs it. */
    private function upgradeOrder($data): \Model_ClientOrder
    {
        $model = $this->_getOrder($data);
        $order = $this->getDi()['db']->load('ClientOrder', $model instanceof Order ? (int) $model->getId() : (int) $model->id);
        if (!$order instanceof \Model_ClientOrder) {
            throw new \ClientFence\InformationException('Order not found');
        }

        return $order;
    }

    private function getClientId(object $identity): int
    {
        if ($identity instanceof ClientEntity) {
            return (int) $identity->getId();
        }

        if (!$identity instanceof \Model_Client) {
            throw new \ClientFence\InformationException('Client identity not found');
        }

        return (int) $identity->id;
    }

    private function findOrderForIdentity(object $identity, int $id): Order|\Model_ClientOrder|null
    {
        if ($identity instanceof ClientEntity) {
            return $this->getService()->findEntityForClientById($identity, $id);
        }

        if (!$identity instanceof \Model_Client) {
            throw new \ClientFence\InformationException('Client identity not found');
        }

        return $this->getService()->findForClientById($identity, $id);
    }
}
