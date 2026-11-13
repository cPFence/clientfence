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
     * @return array{available: bool, reason: string, notice: bool, current: array<string, mixed>, options: list<array<string, mixed>>, waiting: array<string, mixed>|null, options_change: bool, product_options: list<array<string, mixed>>}
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
     * ClientFence: the add-ons this service can take now (Order/AddonOrders.php), with the price to its due date.
     *
     * @return list<array<string, mixed>>
     */
    public function addon_offers($data): array
    {
        return \ClientFence\Mod\Order\AddonOrders::offers($this->getDi(), $this->upgradeOrder($data));
    }

    /**
     * ClientFence: puts an add-on for this service in the cart (Order/AddonOrders.php): `product_id`. Checkout charges it
     * to the service's due date; it then renews with the service.
     */
    #[RequiredParams(['product_id' => 'Choose the add-on'])]
    public function addon_order($data): bool
    {
        $order = $this->upgradeOrder($data);
        $addon = \ClientFence\Mod\Order\AddonOrders::offered($this->getDi(), $order, (int) $data['product_id']);
        $cart = $this->getDi()['mod_service']('cart');

        return $cart->addItem($cart->getSessionCart(), $addon, ['quantity' => 1], $order);
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
        // ClientFence core patch: only discounted bundled domains depend on the hosting order.
        $orders = [$model];
        $groupId = $model instanceof Order ? $model->getGroupId() : $model->group_id;
        if ($groupId && ($model instanceof Order ? $model->isGroupMaster() : (bool) $model->group_master)) {
            $family = $this->getService()->getOrderRepository()->findBy([
                'clientId' => $model instanceof Order ? $model->getClientId() : (int) $model->client_id,
                'groupId' => $groupId,
                'status' => [Order::STATUS_PENDING_SETUP, Order::STATUS_FAILED_SETUP],
                'unpaidInvoiceId' => $model instanceof Order ? $model->getUnpaidInvoiceId() : $model->unpaid_invoice_id,
            ]);
            foreach ($family as $sibling) {
                $config = json_decode($sibling->getConfig() ?? '', true);
                if ($sibling->getId() !== (int) ($model instanceof Order ? $model->getId() : $model->id)
                    && $sibling->getServiceType() === 'domain'
                    && $sibling->getDiscount() > 0
                    && (!empty($config['free_domain']) || !empty($config['free_transfer']))) {
                    $orders[] = $sibling;
                }
            }
        }
        // ClientFence core patch: an order that was paid for stays (its paid invoice would keep no order and the
        // failed action would be dismissed); staff can still delete it.
        foreach ($orders as $order) {
            $paid = $this->getDi()['dbal']->fetchOne(
                'SELECT 1 FROM invoice_item ii JOIN invoice i ON i.id = ii.invoice_id WHERE ii.type = ? AND ii.rel_id = ? AND i.status IN (?, ?) LIMIT 1',
                [\Model_InvoiceItem::TYPE_ORDER, (string) ($order instanceof Order ? $order->getId() : $order->id), \Model_Invoice::STATUS_PAID, \Model_Invoice::STATUS_REFUNDED]
            );
            if ($paid) {
                throw new \ClientFence\InformationException('A paid order cannot be deleted. Please contact support.');
            }
        }
        foreach ($orders as $order) {
            $this->getService()->deleteFromOrder($order);
        }

        return true;
    }

    /**
     * ClientFence: asks to cancel the service, as WHMCS (Order\CancelRequests): `type` immediate or
     * end_of_period, and a `reason`.
     */
    #[RequiredParams(['type' => 'Choose when the service ends', 'reason' => 'Please tell us why you want to cancel.'])]
    public function cancel_request($data): bool
    {
        \ClientFence\Mod\Order\CancelRequests::create($this->getDi(), $this->upgradeOrder($data), (string) $data['type'], (string) $data['reason']);

        return true;
    }

    /** ClientFence: the waiting cancellation request of the service, or null. */
    public function cancel_request_get($data): ?array
    {
        $request = \ClientFence\Mod\Order\CancelRequests::pending($this->getDi(), (int) $this->upgradeOrder($data)->id);

        return $request === null ? null : ['type' => $request['type'], 'reason' => $request['reason'], 'created_at' => $request['created_at']];
    }

    /** ClientFence: takes back the waiting cancellation request of the service. */
    public function cancel_request_withdraw($data): bool
    {
        $order = $this->upgradeOrder($data);
        \ClientFence\Mod\Order\CancelRequests::withdraw($this->getDi(), (int) $order->client_id, (int) $order->id);

        return true;
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
