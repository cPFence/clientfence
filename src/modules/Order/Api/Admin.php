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
 * Orders management.
 */

namespace ClientFence\Mod\Order\Api;

use ClientFence\Mod\Client\Entity\Client as ClientEntity;
use ClientFence\Mod\Order\Entity\Order;
use ClientFence\Mod\Order\Repository\OrderRepository;
use ClientFence\InformationException;
use ClientFence\PaginationOptions;
use ClientFence\Tools;
use ClientFence\Validation\Api\RequiredParams;
use Symfony\Component\HttpFoundation\Response;

class Admin extends \ClientFence\Api\AbstractApi
{
    private ?OrderRepository $orderRepository = null;

    protected function getOrderRepository(): OrderRepository
    {
        $this->orderRepository ??= $this->getDi()['em']->getRepository(Order::class);

        return $this->orderRepository;
    }

    /**
     * Get order details.
     *
     * @return array
     */
    public function get($data)
    {
        $this->checkPermissions('order', 'view');

        $deep = isset($data['deep']) ? (bool) $data['deep'] : true;
        $order = $this->_getOrder($data);

        return $this->getService()->toApiArray($order, $deep, $this->getIdentity());
    }

    /**
     * Return paginated list of orders.
     *
     * @optional string $date_from - show only order places after this date
     * @optional string $date_to - show only order places till this date
     *
     * @return array
     */
    public function get_list($data)
    {
        $this->checkPermissions('order', 'view');

        $orderConfig = $this->getDi()['mod']('order')->getConfig();
        $data['hide_addons'] = (isset($orderConfig['show_addons']) && $orderConfig['show_addons']) ? 0 : 1;

        [$sql, $params] = $this->getService()->getSearchQuery($data);
        $resultSet = $this->getDi()['pager']->getPaginatedResultSet($sql, $params, PaginationOptions::fromArray($data));

        $resultSet['list'] = $this->getService()->getBatchForApi(
            array_column($resultSet['list'], 'id'),
            $this->getIdentity()
        );

        return $resultSet;
    }

    /**
     * Place new order for client. Admin is able to order disabled products.
     *
     * @optional array $config - Depending on product type, you may need to pass product configuration options
     * @optional int $quantity - Quantity of products to order. Default 1
     * @optional float $price - Overridden unit price in default currency. Default is product price for selected period.
     * @optional string $group_id - Order group id. Assign order to be as an addon for other order
     * @optional string $currency - Order currency. If not passed, default is used
     * @optional string $title - Order title. If not passed, product title is used
     * @optional bool $activate - activate immediately
     * @optional string $invoice_option - Options: "no-invoice", "issue-invoice"; Default: no-invoice
     * @optional bool $mark_invoice_paid - Mark the generated invoice as paid after the order is created
     * @optional int $gateway_id - Payment gateway to associate with the invoice when mark_invoice_paid is used
     * @optional string $transactionId - Custom transaction ID to use when the selected gateway is Custom
     * @optional string $created_at - date when order was created. Default: now
     * @optional string $updated_at - date when order was updated. Default: now
     *
     * @return int
     */
    #[RequiredParams([
        'client_id' => 'Client ID was not passed',
        'product_id' => 'Product ID was not passed',
    ])]
    public function create($data)
    {
        $this->checkPermissions('order', 'manage');

        $markInvoicePaid = Tools::normalizeBoolean($data['mark_invoice_paid'] ?? false);
        $data['mark_invoice_paid'] = $markInvoicePaid;

        if ($markInvoicePaid) {
            $this->checkPermissions('invoice');

            if (($data['invoice_option'] ?? 'no-invoice') !== 'issue-invoice') {
                throw new InformationException('Marking an invoice as paid requires the order to issue an invoice.');
            }

            $this->getDi()['mod_service']('Invoice')->validateAdminMarkAsPaidRequest($data);
        }

        $client = $this->di['em']->getRepository(ClientEntity::class)->find($data['client_id'])
            ?? throw new InformationException('Client not found');
        $product = $this->di['mod_service']('product')->findProductById((int) $data['product_id']);

        return $this->getService()->createOrder($client, $product, $data);
    }

    /**
     * Update order settings.
     *
     * @optional string $period - Order billing period, ie: 1Y
     * @optional string $expires_at - Order expiration date, ie: 2022-12-29
     * @optional string $activated_at - Order activation date, ie: 2022-12-29
     * @optional string $title - new order title
     * @optional string $price - new order price, new invoice will be issued with this amount
     * @optional string $status - manual orders status change. Does not perform action on service
     * @optional string $reason - order status change reason
     * @optional string $notes - staff notes (clients do not see them)
     * @optional array  $meta - list of meta properties
     * @optional bool   $auto_suspend_override - Override Auto-Suspend: the cron neither suspends nor cancels the order (ClientFence)
     * @optional string $auto_suspend_until - with the override: do not suspend until this date (YYYY-MM-DD), blank for no end
     *
     * @return bool
     */
    public function update($data)
    {
        $this->checkPermissions('order', 'manage');

        $order = $this->_getOrder($data);

        return $this->getService()->updateOrder($order, $data);
    }

    /**
     * Activate order depending on current status.
     *
     * @optional bool $force - Skip order status checking. Force activate even active order
     *
     * @return bool
     */
    public function activate($data)
    {
        $this->checkPermissions('order', 'manage');

        $order = $this->_getOrder($data);
        // ClientFence core patch (QA r10 D-06): activating a service that has an expiry (Pending setup set by hand, or
        // force) adds a period from it that no invoice paid for. QA r11 PK-02: an active order without force adds nothing.
        if ($order->getExpiresAt() !== null && ($order->getStatus() !== Order::STATUS_ACTIVE || !empty($data['force']))) {
            \ClientFence\Mod\Order\Upgrades::markMoneyTouched($this->getDi(), (int) $order->getId());
        }

        return $this->getService()->activateOrder($order, $data);
    }

    /**
     * Activate order depending on current status.
     *
     * @return bool
     */
    public function renew($data)
    {
        $this->checkPermissions('order', 'manage');

        $order = $this->_getOrder($data);

        if ($order->getStatus() == Order::STATUS_PENDING_SETUP || $order->getStatus() == Order::STATUS_FAILED_SETUP) {
            return $this->activate($data);
        }

        // ClientFence core patch: a waiting paid renewal line is applied instead (Service::applyPaidRenewal).
        if ($this->getService()->applyPaidRenewal((int) $order->getId())) {
            return true;
        }
        // ClientFence core patch (QA r6 U-04): a renewal with no paid invoice adds time nobody paid for.
        \ClientFence\Mod\Order\Upgrades::markUnpaidTime($this->getDi(), (int) $order->getId());

        return $this->getService()->renewOrder($order, $data);
    }

    /**
     * Suspend order.
     *
     * @optional string $reason - Suspension reason message
     * @optional bool $skip_event - Skip calling event hooks
     *
     * @return bool
     */
    public function suspend($data)
    {
        $this->checkPermissions('order', 'manage');

        $order = $this->_getOrder($data);
        $skip_event = Tools::normalizeBoolean($data['skip_event'] ?? false);

        $reason = $data['reason'] ?? null;

        return $this->getService()->suspendFromOrder($order, $reason, $skip_event);
    }

    /**
     * Unsuspend suspended order.
     *
     * @return bool
     */
    public function unsuspend($data)
    {
        $this->checkPermissions('order', 'manage');

        $order = $this->_getOrder($data);
        if ($order->getStatus() != Order::STATUS_SUSPENDED) {
            throw new InformationException('Only suspended orders can be unsuspended');
        }

        // ClientFence core patch: a waiting paid renewal line is applied instead (Service::applyPaidRenewal).
        return $this->getService()->applyPaidRenewal((int) $order->getId()) || $this->getService()->unsuspendFromOrder($order);
    }

    /**
     * Cancel order.
     *
     * @optional bool $skip_event - Skip calling event hooks
     * @optional bool $cancel_at_period_end - Keep the order active until its gateway subscription ends
     *
     * @return bool
     */
    public function cancel($data)
    {
        $this->checkPermissions('order', 'manage');

        $order = $this->_getOrder($data);
        $skip_event = Tools::normalizeBoolean($data['skip_event'] ?? false);
        $cancelAtPeriodEnd = Tools::normalizeBoolean($data['cancel_at_period_end'] ?? false);

        $reason = $data['reason'] ?? null;

        if ($cancelAtPeriodEnd) {
            return $this->getService()->scheduleCancellationFromOrder($order, $reason);
        }

        return $this->getService()->cancelFromOrder($order, $reason, $skip_event);
    }

    /**
     * Check whether an order's active gateway subscription supports cancellation at period end.
     */
    public function can_cancel_at_period_end($data): bool
    {
        $this->checkPermissions('order', 'view');

        $order = $this->_getOrder($data);
        $subscriptionService = $this->getDi()['mod_service']('Invoice', 'Subscription');

        return $subscriptionService->canCancelAtPeriodEndForOrder($this->getService()->getLegacyOrder($order));
    }

    /**
     * Uncancel canceled order.
     *
     * @return bool
     */
    public function uncancel($data)
    {
        $this->checkPermissions('order', 'manage');

        $order = $this->_getOrder($data);
        if ($order->getStatus() != Order::STATUS_CANCELED) {
            throw new InformationException('Only canceled orders can be uncanceled');
        }
        // ClientFence core patch (QA r10 C-1): uncancel gives a new period from today that no invoice paid for.
        \ClientFence\Mod\Order\Upgrades::markMoneyTouched($this->getDi(), (int) $order->getId());

        return $this->getService()->uncancelFromOrder($order);
    }

    /**
     * Delete order.
     *
     * @optional bool $delete_addons - Remove addons also. Default false.
     *
     * @return bool
     */
    public function delete($data)
    {
        $this->checkPermissions('order', 'manage');

        $order = $this->_getOrder($data);
        $delete_addons = Tools::normalizeBoolean($data['delete_addons'] ?? false);
        $forceDelete = Tools::normalizeBoolean($data['force_delete'] ?? false);

        if ($delete_addons) {
            $list = $this->getService()->getOrderAddonsList($order);
            foreach ($list as $addon) {
                $this->getService()->deleteFromOrder($addon, $forceDelete);
            }
        }

        return $this->getService()->deleteFromOrder($order, $forceDelete);
    }

    /**
     * Suspend all expired orders.
     *
     * @return bool
     */
    public function batch_suspend_expired($data)
    {
        $this->checkPermissions('order', 'manage');

        return $this->getService()->batchSuspendExpired();
    }

    /**
     * Cancel all suspended orders.
     * Configure how many days suspended order should be kept before canceling.
     *
     * @return bool
     */
    public function batch_cancel_suspended($data)
    {
        $this->checkPermissions('order', 'manage');

        return $this->getService()->batchCancelSuspended();
    }

    /**
     * Remove pending-setup orders whose linked invoice has been unpaid past its due date for too long.
     * Configure how many days an unpaid order should be kept before it is removed.
     *
     * @return bool
     */
    public function batch_cancel_unpaid($data)
    {
        $this->checkPermissions('order', 'manage');

        return $this->getService()->batchCancelUnpaid();
    }

    /**
     * Update order config.
     *
     * @return bool
     */
    #[RequiredParams(['config' => 'Order config not passed'])]
    public function update_config($data)
    {
        $this->checkPermissions('order', 'manage');

        $order = $this->_getOrder($data);

        $config = $data['config'] ?? null;
        if (!is_array($config)) {
            throw new \ClientFence\Exception('Order config not passed');
        }

        return $this->getService()->updateOrderConfig($order, $config);
    }

    /**
     * Get order service data.
     *
     * @return array
     */
    public function service($data)
    {
        $this->checkPermissions('order', 'view');

        $order = $this->_getOrder($data);

        return $this->getService()->getOrderServiceData($order, $this->getIdentity());
    }

    /**
     * Get paginated order statuses history list.
     *
     * @return array
     */
    public function status_history_get_list($data)
    {
        $this->checkPermissions('order', 'view');

        $order = $this->_getOrder($data);

        $data['client_order_id'] = $order->getId();

        [$sql, $bindings] = $this->getService()->getOrderStatusSearchQuery($data);

        return $this->getDi()['pager']->getPaginatedResultSet($sql, $bindings, PaginationOptions::fromArray($data));
    }

    /**
     * Add order status history change.
     *
     * @return array
     */
    #[RequiredParams(['status' => 'Order status was not passed'])]
    public function status_history_add($data)
    {
        $this->checkPermissions('order', 'manage');

        $order = $this->_getOrder($data);

        $notes = $data['notes'] ?? null;

        return $this->getService()->orderStatusAdd($order, $data['status'], $notes);
    }

    /**
     * Remove order status history item.
     *
     * @return bool
     */
    #[RequiredParams(['id' => 'Order history line ID was not passed'])]
    public function status_history_delete($data)
    {
        $this->checkPermissions('order', 'manage');

        return $this->getService()->orderStatusRm($data['id']);
    }

    /**
     * Return order statuses codes with counter.
     *
     * @return array
     */
    public function get_statuses()
    {
        $this->checkPermissions('order', 'view');

        return $this->getService()->counter();
    }

    /**
     * Return available invoice options.
     */
    public function get_invoice_options($data): array
    {
        $this->checkPermissions('order', 'view');

        return [
            'issue-invoice' => __trans('Automatically Issue Renewal Invoices'),
            'no-invoice' => __trans('Issue Invoices Manually'),
        ];
    }

    /**
     * Return order statuses codes with titles.
     */
    public function get_status_pairs($data): array
    {
        $this->checkPermissions('order', 'view');

        return [
            Order::STATUS_PENDING_SETUP => 'Pending Setup',
            Order::STATUS_FAILED_SETUP => 'Setup Failed',
            Order::STATUS_ACTIVE => 'Active',
            Order::STATUS_SUSPENDED => 'Suspended',
            Order::STATUS_CANCELED => 'Canceled',
        ];
    }

    /**
     * Return order addons list.
     */
    public function addons($data): array
    {
        $this->checkPermissions('order', 'view');

        $model = $this->_getOrder($data);
        $list = $this->getService()->getOrderAddonsList($model);
        $result = [];
        foreach ($list as $order) {
            $result[] = $this->getService()->toApiArray($order);
        }

        return $result;
    }

    protected function _getOrder($data)
    {
        $required = [
            'id' => 'Order ID was not passed',
        ];
        $this->getDi()['validator']->checkRequiredParamsForArray($required, $data);

        $order = $this->getOrderRepository()->find((int) $data['id']);
        if (!$order instanceof Order) {
            throw new InformationException('Order Not Found');
        }

        return $order;
    }

    /**
     * Deletes orders with given IDs.
     *
     * @optional bool $delete_addons - Remove addons also. Default false.
     */
    #[RequiredParams(['ids' => 'Order IDs were not passed'])]
    public function batch_delete($data): bool
    {
        $this->checkPermissions('order', 'manage');

        $delete_addons = Tools::normalizeBoolean($data['delete_addons'] ?? false);

        foreach ($data['ids'] as $id) {
            $this->delete(['id' => $id, 'delete_addons' => $delete_addons]);
        }

        return true;
    }

    public function export_csv($data): Response
    {
        $this->checkPermissions('order', 'view');
        $this->checkPermissions('order', 'export');

        $data['headers'] ??= [];

        return $this->getService()->exportCSV($data['headers']);
    }

    /**
     * ClientFence: failed order actions (Orders > Failed actions; ActionFailures).
     *
     * @optional string $status - open (default), resolved, dismissed or all
     * @optional int $order_id - only this order's entries
     */
    public function failed_action_get_list($data): array
    {
        $this->checkPermissions('order', 'view');

        $status = (string) ($data['status'] ?? \ClientFence\Mod\Order\ActionFailures::STATUS_OPEN);
        if ($status === 'all') {
            $status = '';
        } elseif (!in_array($status, [\ClientFence\Mod\Order\ActionFailures::STATUS_OPEN, \ClientFence\Mod\Order\ActionFailures::STATUS_RESOLVED, \ClientFence\Mod\Order\ActionFailures::STATUS_DISMISSED], true)) {
            throw new InformationException('Unknown status.');
        }
        $orderId = isset($data['order_id']) && $data['order_id'] !== '' ? (int) $data['order_id'] : null;

        return \ClientFence\Mod\Order\ActionFailures::getList(
            $this->getDi()['em']->getConnection(),
            $status,
            (int) ($data['page'] ?? 1),
            (int) ($data['per_page'] ?? 25),
            $orderId,
        );
    }

    /**
     * ClientFence: runs a failed order action again.
     */
    #[RequiredParams(['id' => 'Failed action ID was not passed'])]
    public function failed_action_retry($data): bool
    {
        $this->checkPermissions('order', 'manage');

        return \ClientFence\Mod\Order\ActionFailures::retry($this->getDi(), (int) $data['id'], $this->adminId());
    }

    /**
     * ClientFence: closes a failed order action without running it again.
     */
    #[RequiredParams(['id' => 'Failed action ID was not passed'])]
    public function failed_action_dismiss($data): bool
    {
        $this->checkPermissions('order', 'manage');

        return \ClientFence\Mod\Order\ActionFailures::dismiss($this->getDi(), (int) $data['id'], $this->adminId());
    }

    /**
     * ClientFence: a product's or a service's upgrade rule (Order\Upgrades): upgrade and downgrade
     * each 'allow', 'block' or 'default'.
     *
     * @return array{upgrade: string, downgrade: string}
     */
    #[RequiredParams(['scope' => 'Scope was not passed', 'id' => 'ID was not passed'])]
    public function upgrade_rule_get($data): array
    {
        $scope = $this->upgradeScope($data, false);
        $rule = \ClientFence\Mod\Order\Upgrades::rule($this->getDi(), $scope, (int) $data['id']);
        $word = static fn (?bool $v): string => $v === null ? 'default' : ($v ? 'allow' : 'block');

        return ['upgrade' => $word($rule['upgrade']), 'downgrade' => $word($rule['downgrade'])];
    }

    /**
     * ClientFence: saves a product's (scope product) or a service's (scope order) upgrade rule.
     */
    #[RequiredParams(['scope' => 'Scope was not passed', 'id' => 'ID was not passed', 'upgrade' => 'Upgrade rule was not passed', 'downgrade' => 'Downgrade rule was not passed'])]
    public function upgrade_rule_save($data): bool
    {
        $scope = $this->upgradeScope($data, true);
        $id = (int) $data['id'];
        $exists = $scope === \ClientFence\Mod\Order\Upgrades::SCOPE_ORDER
            ? $this->getDi()['db']->load('ClientOrder', $id) instanceof \Model_ClientOrder
            : $this->getDi()['em']->getConnection()->fetchOne('SELECT 1 FROM product WHERE id = ?', [$id]) !== false;
        if (!$exists) {
            throw new InformationException('Unknown product or service.');
        }
        \ClientFence\Mod\Clientfence\Service::ensureTables($this->getDi(), true);
        \ClientFence\Mod\Order\Upgrades::saveRule($this->getDi(), $scope, $id, (string) $data['upgrade'], (string) $data['downgrade']);
        $this->getDi()['logger']->info('Saved the upgrade rule of %s #%d', $scope, $id);

        return true;
    }

    /**
     * ClientFence: a service's upgrades and downgrades, newest first.
     */
    #[RequiredParams(['id' => 'Order ID was not passed'])]
    public function upgrade_history($data): array
    {
        $this->checkPermissions('order', 'view');

        return \ClientFence\Mod\Order\Upgrades::history($this->getDi(), (int) $data['id']);
    }

    /**
     * ClientFence: a service's configurable options (Product/Options.php) for staff: the
     * product's options, hidden ones too, and the service's choice and lines.
     */
    #[RequiredParams(['id' => 'Order ID was not passed'])]
    public function options_get($data): array
    {
        $this->checkPermissions('order', 'view');

        return \ClientFence\Mod\Order\Upgrades::staffView($this->getDi(), (int) $data['id']);
    }

    /**
     * ClientFence: staff set a service's configurable options (`options`: option id => value id,
     * 0/1 or a quantity); with `reprice`, the recurring price moves by the difference. No invoice.
     */
    #[RequiredParams(['id' => 'Order ID was not passed'])]
    public function options_update($data): bool
    {
        $this->checkPermissions('order', 'manage');
        $choice = isset($data['options']) && is_array($data['options']) ? $data['options'] : [];
        \ClientFence\Mod\Order\Upgrades::staffSetOptions($this->getDi(), (int) $data['id'], $choice, !empty($data['reprice']));

        return true;
    }

    /**
     * ClientFence: what Orders > Bulk price update offers (Order\BulkPrices): products, billing
     * periods in use, statuses and currencies.
     */
    public function bulk_price_options($data): array
    {
        $this->checkPermissions('order', 'view');

        return \ClientFence\Mod\Order\BulkPrices::options($this->getDi());
    }

    /**
     * ClientFence: the orders a bulk price update matches, with their old and new prices.
     *
     * @optional array $product_ids - products (required)
     * @optional array $periods - billing periods (none: all)
     * @optional array $statuses - order statuses (required)
     * @optional string $currency - order currency (required)
     * @optional string $current_price - only orders at this price
     * @optional string $mode - price, increase or product
     * @optional string $amount - the new price (price) or the amount to add (increase)
     */
    public function bulk_price_preview($data): array
    {
        $this->checkPermissions('order', 'view');

        return \ClientFence\Mod\Order\BulkPrices::preview($this->getDi(), \ClientFence\Mod\Order\BulkPrices::filters($this->getDi(), $data));
    }

    /**
     * ClientFence: applies a previewed bulk price update; `fingerprint` is the preview's, and
     * nothing changes when the matches differ from it. `email` emails the clients.
     */
    #[RequiredParams(['fingerprint' => 'Preview the update first'])]
    public function bulk_price_apply($data): array
    {
        $this->checkPermissions('order', 'manage');

        return \ClientFence\Mod\Order\BulkPrices::apply(
            $this->getDi(),
            \ClientFence\Mod\Order\BulkPrices::filters($this->getDi(), $data),
            (string) $data['fingerprint'],
            !empty($data['email']),
            $this->adminId(),
        );
    }

    /** The rule's scope, with the permission it needs. */
    private function upgradeScope(array $data, bool $write): string
    {
        $scope = (string) $data['scope'];
        if ($scope === \ClientFence\Mod\Order\Upgrades::SCOPE_ORDER) {
            $this->checkPermissions('order', $write ? 'manage' : 'view');
        } elseif ($scope === \ClientFence\Mod\Order\Upgrades::SCOPE_PRODUCT) {
            $this->checkPermissions('product', $write ? 'manage_products' : 'view');
        } else {
            throw new InformationException('Unknown scope.');
        }

        return $scope;
    }

    private function adminId(): ?int
    {
        $identity = $this->getIdentity();

        return $identity instanceof \Model_Admin ? (int) $identity->id : null;
    }
}
