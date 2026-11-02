<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Order;

use ClientFence\Mod\Client\Entity\Client as ClientEntity;
use ClientFence\Mod\Currency\Entity\Currency;
use ClientFence\Mod\Order\Entity\Order;
use ClientFence\Mod\Order\Entity\OrderMeta;
use ClientFence\Mod\Order\Entity\OrderStatus;
use ClientFence\Mod\Order\Repository\OrderMetaRepository;
use ClientFence\Mod\Order\Repository\OrderRepository;
use ClientFence\Mod\Order\Repository\OrderStatusRepository;
use ClientFence\Mod\Product\Entity\Product;
use ClientFence\Mod\Staff\Entity\Admin;
use ClientFence\InformationException;
use ClientFence\InjectionAwareInterface;
use ClientFence\Tools;
use ClientFence\Validation\PriceValidator;
use Symfony\Component\HttpFoundation\Response;

class Service implements InjectionAwareInterface
{
    /**
     * Columns on the `client_order` table permitted in CSV exports.
     * The `config` column (service provisioning credentials) is excluded.
     */
    private const array EXPORTABLE_COLUMNS = [
        'id', 'client_id', 'product_id', 'form_id', 'promo_id', 'promo_recurring',
        'promo_used', 'group_id', 'group_master', 'invoice_option', 'title',
        'currency', 'unpaid_invoice_id', 'service_id', 'service_type', 'period',
        'quantity', 'unit', 'price', 'discount', 'status', 'reason', 'notes',
        'suspension_grace_days', 'referred_by', 'expires_at', 'activated_at',
        'suspended_at', 'unsuspended_at', 'canceled_at', 'created_at', 'updated_at',
    ];

    /** Subset of EXPORTABLE_COLUMNS used when the caller passes no headers. */
    private const array DEFAULT_EXPORT_COLUMNS = [
        'id', 'client_id', 'product_id', 'title', 'currency', 'service_type',
        'period', 'quantity', 'price', 'discount', 'status', 'reason', 'notes',
    ];

    public const META_CANCEL_AT_PERIOD_END = 'cancel_at_period_end';
    /** ClientFence: on an addon suspended with its service, that service's order id (unsuspended with it). */
    public const META_SUSPENDED_WITH = 'suspended_with_order';

    // ClientFence: automatic suspension (batchSuspendExpired(), isExemptFromAutoSuspension()).
    public const int BATCH_SUSPEND_AFTER_DAYS = 5;

    // ClientFence: "Override Auto-Suspend: do not suspend until [date]" on the order's Edit Order tab
    // (as in WHMCS), kept in the order's meta: '1' while on, and the date (YYYY-MM-DD, blank for no
    // end). Staff-only meta never reaches clients.
    public const string META_AUTO_SUSPEND_OVERRIDE = 'auto_suspend_override';
    public const string META_AUTO_SUSPEND_UNTIL = 'auto_suspend_until';
    public const array STAFF_ONLY_META = [self::META_AUTO_SUSPEND_OVERRIDE, self::META_AUTO_SUSPEND_UNTIL];

    private const array BUILT_IN_SERVICE_TYPES = [
        \ClientFence\Mod\Product\Service::CUSTOM,
        \ClientFence\Mod\Product\Service::LICENSE,
        \ClientFence\Mod\Product\Service::DOWNLOADABLE,
        \ClientFence\Mod\Product\Service::HOSTING,
        \ClientFence\Mod\Product\Service::DOMAIN,
        \ClientFence\Mod\Product\Service::APIKEY,
    ];

    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    private ?OrderRepository $orderRepository = null;
    private ?OrderMetaRepository $orderMetaRepository = null;
    private ?OrderStatusRepository $orderStatusRepository = null;

    public function getOrderRepository(): OrderRepository
    {
        $this->orderRepository ??= $this->di['em']->getRepository(Order::class);

        return $this->orderRepository;
    }

    public function getOrderMetaRepository(): OrderMetaRepository
    {
        $this->orderMetaRepository ??= $this->di['em']->getRepository(OrderMeta::class);

        return $this->orderMetaRepository;
    }

    public function getOrderStatusRepository(): OrderStatusRepository
    {
        $this->orderStatusRepository ??= $this->di['em']->getRepository(OrderStatus::class);

        return $this->orderStatusRepository;
    }

    private function orderId(Order|\Model_ClientOrder $order): int
    {
        return (int) ($order instanceof Order ? $order->getId() : $order->id);
    }

    private function orderClientId(Order|\Model_ClientOrder $order): ?int
    {
        return $order instanceof Order ? $order->getClientId() : (int) $order->client_id;
    }

    private function orderProductId(Order|\Model_ClientOrder $order): ?int
    {
        return $order instanceof Order ? $order->getProductId() : (int) $order->product_id;
    }

    private function orderFormId(Order|\Model_ClientOrder $order): ?int
    {
        return $order instanceof Order ? $order->getFormId() : (int) $order->form_id;
    }

    private function orderPromoId(Order|\Model_ClientOrder $order): ?int
    {
        return $order instanceof Order ? $order->getPromoId() : (int) $order->promo_id;
    }

    private function orderGroupId(Order|\Model_ClientOrder $order): ?string
    {
        return $order instanceof Order ? $order->getGroupId() : (string) $order->group_id;
    }

    private function orderIsGroupMaster(Order|\Model_ClientOrder $order): bool
    {
        return $order instanceof Order ? $order->isGroupMaster() : (bool) $order->group_master;
    }

    private function orderServiceId(Order|\Model_ClientOrder $order): ?int
    {
        return $order instanceof Order ? $order->getServiceId() : (int) $order->service_id;
    }

    private function orderServiceType(Order|\Model_ClientOrder $order): ?string
    {
        return $order instanceof Order ? $order->getServiceType() : $order->service_type;
    }

    private function orderPeriod(Order|\Model_ClientOrder $order): ?string
    {
        return $order instanceof Order ? $order->getPeriod() : $order->period;
    }

    private function orderQuantity(Order|\Model_ClientOrder $order): int
    {
        return (int) ($order instanceof Order ? $order->getQuantity() : $order->quantity);
    }

    private function orderPrice(Order|\Model_ClientOrder $order): ?float
    {
        return $order instanceof Order ? $order->getPrice() : (float) $order->price;
    }

    private function orderDiscount(Order|\Model_ClientOrder $order): ?float
    {
        return $order instanceof Order ? $order->getDiscount() : (float) $order->discount;
    }

    private function orderStatus(Order|\Model_ClientOrder $order): ?string
    {
        return $order instanceof Order ? $order->getStatus() : $order->status;
    }

    private function orderCurrency(Order|\Model_ClientOrder $order): ?string
    {
        return $order instanceof Order ? $order->getCurrency() : $order->currency;
    }

    private function orderTitle(Order|\Model_ClientOrder $order): ?string
    {
        return $order instanceof Order ? $order->getTitle() : $order->title;
    }

    private function orderInvoiceOption(Order|\Model_ClientOrder $order): ?string
    {
        return $order instanceof Order ? $order->getInvoiceOption() : $order->invoice_option;
    }

    private function orderConfig(Order|\Model_ClientOrder $order): ?string
    {
        return $order instanceof Order ? $order->getConfig() : $order->config;
    }

    private function orderNotes(Order|\Model_ClientOrder $order): ?string
    {
        return $order instanceof Order ? $order->getNotes() : $order->notes;
    }

    private function orderReason(Order|\Model_ClientOrder $order): ?string
    {
        return $order instanceof Order ? $order->getReason() : $order->reason;
    }

    private function persistOrder(Order|\Model_ClientOrder $order): void
    {
        if ($order instanceof Order) {
            $this->di['em']->persist($order);
            $this->di['em']->flush();

            return;
        }

        $this->di['db']->store($order);
    }

    public function getLegacyOrder(Order|\Model_ClientOrder $order): \Model_ClientOrder
    {
        if ($order instanceof \Model_ClientOrder) {
            return $order;
        }

        $legacyOrder = $this->di['db']->getExistingModelById('ClientOrder', $this->orderId($order));
        if (!$legacyOrder instanceof \Model_ClientOrder) {
            throw new \ClientFence\Exception('Order compatibility model not found');
        }

        return $legacyOrder;
    }

    public function getModulePermissions(): array
    {
        return [
            'view' => [
                'type' => 'bool',
                'display_name' => __trans('View orders'),
                'description' => __trans('Allows the staff member to view orders and order details.'),
            ],
            'manage' => [
                'type' => 'bool',
                'display_name' => __trans('Manage orders'),
                'description' => __trans('Allows the staff member to create, update, delete, and change order statuses.'),
            ],
            'export' => [
                'type' => 'bool',
                'display_name' => __trans('Export orders'),
                'description' => __trans('Allows the staff member to export order data as CSV.'),
            ],
            'manage_settings' => [],
        ];
    }

    public function counter(): array
    {
        $sql = '
        SELECT status, COUNT(id) as counter
        FROM client_order
        WHERE group_master = 1
        GROUP BY status
        ';

        $data = $this->di['em']->getConnection()->fetchAllKeyValue($sql);

        return [
            'total' => array_sum($data),
            Order::STATUS_PENDING_SETUP => $data[Order::STATUS_PENDING_SETUP] ?? 0,
            Order::STATUS_FAILED_SETUP => $data[Order::STATUS_FAILED_SETUP] ?? 0,
            Order::STATUS_FAILED_RENEW => $data[Order::STATUS_FAILED_RENEW] ?? 0,
            Order::STATUS_ACTIVE => $data[Order::STATUS_ACTIVE] ?? 0,
            Order::STATUS_SUSPENDED => $data[Order::STATUS_SUSPENDED] ?? 0,
            Order::STATUS_CANCELED => $data[Order::STATUS_CANCELED] ?? 0,
        ];
    }

    public static function onAfterAdminOrderActivate(\ClientFence\Event $event): void
    {
        $params = $event->getParameters();
        $order_id = $params['id'];
        $di = $event->getDi();
        $service = $di['mod_service']('order');

        try {
            $order = $di['em']->getRepository(Order::class)->find($order_id);
            if (!$order instanceof Order) {
                throw new \ClientFence\Exception('Order not found');
            }
            $s = $service->getOrderServiceData($order);
            $orderArr = $service->toApiArray($order, true);

            $email = $params;
            $email['to_client'] = $order->getClientId();
            $email['code'] = sprintf('mod_service%s_activated', $orderArr['service_type']);
            $email['service'] = $s;
            $email['order'] = $orderArr;

            $emailService = $di['mod_service']('email');
            $emailService->sendTemplate($email);
        } catch (\Exception $exc) {
            $di['logger']->setChannel('email')->error('Failed to send order activation email', ['exception' => $exc->getMessage(), 'order_id' => $order_id]);
        }
    }

    public static function onAfterAdminOrderRenew(\ClientFence\Event $event): void
    {
        $params = $event->getParameters();
        $order_id = $params['id'];
        $di = $event->getDi();
        $orderService = $di['mod_service']('order');

        try {
            $order = $di['em']->getRepository(Order::class)->find($order_id);
            if (!$order instanceof Order) {
                throw new \ClientFence\Exception('Order not found');
            }
            $identity = $di['loggedin_admin'] ?? null;
            $service = $orderService->getOrderServiceData($order, $identity);
            $orderArr = $orderService->toApiArray($order, true, $identity);

            $email = [];
            $email['to_client'] = $orderArr['client']['id'];
            $email['code'] = sprintf('mod_service%s_renewed', $orderArr['service_type']);
            $email['service'] = $service;
            $email['order'] = $orderArr;

            $emailService = $di['mod_service']('email');
            $emailService->sendTemplate($email);
        } catch (\Exception $exc) {
            $di['logger']->setChannel('email')->error('Failed to send order renewal email', ['exception' => $exc->getMessage()]);
        }
    }

    public static function onAfterAdminOrderSuspend(\ClientFence\Event $event): void
    {
        $params = $event->getParameters();
        $order_id = $params['id'];
        $di = $event->getDi();
        $service = $di['mod_service']('order');

        try {
            $order = $di['em']->getRepository(Order::class)->find($order_id);
            if (!$order instanceof Order) {
                throw new \ClientFence\Exception('Order not found');
            }
            $identity = $di['loggedin_admin'] ?? null;
            $s = $service->getOrderServiceData($order, $identity);
            $orderArr = $service->toApiArray($order, true, $identity);

            $email = [];
            $email['to_client'] = $orderArr['client']['id'];
            $email['code'] = sprintf('mod_service%s_suspended', $orderArr['service_type']);
            $email['service'] = $s;
            $email['order'] = $orderArr;

            $emailService = $di['mod_service']('email');
            $emailService->sendTemplate($email);
        } catch (\Exception $exc) {
            $di['logger']->setChannel('email')->error('Failed to send order suspension email', ['exception' => $exc->getMessage()]);
        }
    }

    public static function onAfterAdminOrderUnsuspend(\ClientFence\Event $event): void
    {
        $params = $event->getParameters();
        $order_id = $params['id'];
        $di = $event->getDi();
        $service = $di['mod_service']('order');

        try {
            $order = $di['em']->getRepository(Order::class)->find($order_id);
            if (!$order instanceof Order) {
                throw new \ClientFence\Exception('Order not found');
            }
            $identity = $di['loggedin_admin'] ?? null;
            $s = $service->getOrderServiceData($order, $identity);
            $orderArr = $service->toApiArray($order, true, $identity);

            $email = [];
            $email['to_client'] = $orderArr['client']['id'];
            $email['code'] = sprintf('mod_service%s_unsuspended', $orderArr['service_type']);
            $email['service'] = $s;
            $email['order'] = $orderArr;

            $emailService = $di['mod_service']('email');
            $emailService->sendTemplate($email);
        } catch (\Exception $exc) {
            $di['logger']->setChannel('email')->error('Failed to send order unsuspension email', ['exception' => $exc->getMessage()]);
        }
    }

    public static function onAfterAdminOrderCancel(\ClientFence\Event $event): void
    {
        $params = $event->getParameters();
        $order_id = $params['id'];
        $di = $event->getDi();
        $service = $di['mod_service']('order');

        try {
            $order = $di['em']->getRepository(Order::class)->find($order_id);
            if (!$order instanceof Order) {
                throw new \ClientFence\Exception('Order not found');
            }
            $identity = $di['loggedin_admin'] ?? null;
            $orderArr = $service->toApiArray($order, true, $identity);

            $email = [];
            $email['to_client'] = $orderArr['client']['id'];
            $email['code'] = sprintf('mod_service%s_canceled', $orderArr['service_type']);
            $email['order'] = $orderArr;

            $emailService = $di['mod_service']('email');
            $emailService->sendTemplate($email);
        } catch (\Exception $exc) {
            $di['logger']->setChannel('email')->error('Failed to send order cancellation email', ['exception' => $exc->getMessage()]);
        }
    }

    public static function onAfterAdminOrderUncancel(\ClientFence\Event $event): void
    {
        $params = $event->getParameters();
        $order_id = $params['id'];
        $di = $event->getDi();
        $service = $di['mod_service']('order');

        try {
            $order = $di['em']->getRepository(Order::class)->find($order_id);
            if (!$order instanceof Order) {
                throw new \ClientFence\Exception('Order not found');
            }
            $identity = $di['loggedin_admin'] ?? null;
            $s = $service->getOrderServiceData($order, $identity);
            $orderArr = $service->toApiArray($order, true, $identity);

            $email = [];
            $email['to_client'] = $orderArr['client']['id'];
            $email['code'] = sprintf('mod_service%s_renewed', $orderArr['service_type']);
            $email['order'] = $orderArr;
            $email['service'] = $s;

            $emailService = $di['mod_service']('email');
            $emailService->sendTemplate($email);
        } catch (\Exception $exc) {
            $di['logger']->setChannel('email')->error('Failed to send order uncancel email', ['exception' => $exc->getMessage()]);
        }
    }

    /**
     * Guards against interacting with a service on an expired order.
     *
     * Accepts both the Doctrine order entity and the legacy RedBeanPHP
     * model, since callers have not all been migrated yet.
     *
     * @throws InformationException if the order has an expiry date in the past
     */
    public function assertOrderUsable(Order|\Model_ClientOrder $order): void
    {
        $expiresAt = $order instanceof Order ? $order->getExpiresAt() : $order->expires_at;
        if ($expiresAt === null) {
            return;
        }

        $expiresAtTimestamp = $expiresAt instanceof \DateTimeInterface
            ? $expiresAt->getTimestamp()
            : strtotime((string) $expiresAt);

        if ($expiresAtTimestamp !== false && $expiresAtTimestamp <= time()) {
            throw new InformationException('Subscription expired');
        }
    }

    public function getOrderService(Order|\Model_ClientOrder $order)
    {
        $serviceId = $this->orderServiceId($order);
        $serviceType = $this->orderServiceType($order);

        if ($serviceId !== null) {
            if (in_array($serviceType, self::BUILT_IN_SERVICE_TYPES, true)) {
                $entityClass = $this->_getServiceEntityClass($order);
                if ($entityClass !== null) {
                    return $this->di['em']->getRepository($entityClass)->find($serviceId);
                }

                return $this->di['db']->load($this->_getServiceClassName($order), $serviceId);
            }

            return $this->di['db']->findOne(
                'service_' . $serviceType,
                'id = :id',
                [':id' => $serviceId]
            );
        }

        return null;
    }

    protected function _getServiceClassName(Order|\Model_ClientOrder $order): string
    {
        $serviceType = $this->orderServiceType($order);
        $s = $this->di['tools']->to_camel_case($serviceType, true);

        return 'Service' . ucfirst((string) $s);
    }

    protected function _getServiceEntityClass(Order|\Model_ClientOrder $order): ?string
    {
        $serviceType = $this->orderServiceType($order);

        return match ($serviceType) {
            \ClientFence\Mod\Product\Service::LICENSE => \ClientFence\Mod\Servicelicense\Entity\ServiceLicense::class,
            \ClientFence\Mod\Product\Service::CUSTOM => \ClientFence\Mod\Servicecustom\Entity\ServiceCustom::class,
            \ClientFence\Mod\Product\Service::APIKEY => \ClientFence\Mod\Serviceapikey\Entity\ServiceApiKey::class,
            default => null,
        };
    }

    private const array SERVICE_ENTITY_TYPE_MAP = [
        \ClientFence\Mod\Servicelicense\Entity\ServiceLicense::class => \ClientFence\Mod\Product\Service::LICENSE,
        \ClientFence\Mod\Servicecustom\Entity\ServiceCustom::class => \ClientFence\Mod\Product\Service::CUSTOM,
        \ClientFence\Mod\Serviceapikey\Entity\ServiceApiKey::class => \ClientFence\Mod\Product\Service::APIKEY,
    ];

    public function getServiceOrder($service)
    {
        foreach (self::SERVICE_ENTITY_TYPE_MAP as $entityClass => $serviceType) {
            if ($service instanceof $entityClass) {
                return $this->getOrderRepository()->findOneBy([
                    'serviceType' => $serviceType,
                    'serviceId' => $service->getId(),
                ]);
            }
        }

        $className = $service::class;
        $serviceTypeName = str_starts_with($className, 'Model_Service')
            ? substr($className, strlen('Model_Service'))
            : (new \ReflectionClass($service))->getShortName();
        $type = $this->di['tools']->from_camel_case($serviceTypeName);
        $serviceId = method_exists($service, 'getId') ? $service->getId() : $service->id;

        return $this->di['db']->findOne('ClientOrder', 'service_type = :service_type AND service_id = :service_id', [
            ':service_type' => $type,
            ':service_id' => $serviceId,
        ]);
    }

    public function getConfig(Order|\Model_ClientOrder $model): array
    {
        return json_decode($this->orderConfig($model) ?? '', true) ?? [];
    }

    public function productHasOrders(Product $product): bool
    {
        $order = $this->getOrderRepository()->findOneByProductId($product->getId());

        return $order instanceof Order;
    }

    public function getLogger(Order|\Model_ClientOrder $order)
    {
        $orderId = $this->orderId($order);
        $orderStatus = $this->orderStatus($order);

        $log = $this->di['logger'];
        $log->setEventItem('client_order_id', $orderId);
        $log->setEventItem('status', $orderStatus);

        return $log;
    }

    /**
     * @param string $notes
     */
    public function saveStatusChange(Order|\Model_ClientOrder $order, $notes = null): void
    {
        $orderId = $this->orderId($order);
        $orderStatus = $this->orderStatus($order);

        if ($order instanceof \Model_ClientOrder) {
            $os = $this->di['db']->dispense('ClientOrderStatus');
            $os->client_order_id = $orderId;
            $os->status = $orderStatus;
            $os->notes = $notes;
            $os->created_at = date('Y-m-d H:i:s');
            $os->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($os);

            return;
        }

        $os = new OrderStatus();
        $os->setClientOrderId($orderId);
        $os->setStatus($orderStatus);
        $os->setNotes($notes);
        $this->di['em']->persist($os);
        $this->di['em']->flush();
    }

    // ClientFence core patch: pass the existing client filter through to due-order selection.
    public function getSoonExpiringActiveOrders($data = [])
    {
        [$query, $bindings] = $this->getSoonExpiringActiveOrdersQuery($data);

        return $this->di['em']->getConnection()->fetchAllAssociative($query, $bindings);
    }

    public function getSoonExpiringActiveOrdersQuery($data = []): array
    {
        $systemService = $this->di['mod_service']('system');
        $days_until_expiration = $systemService->getParamValue('invoice_issue_days_before_expire', 14);

        $client_id = $data['client_id'] ?? null;

        $query = 'SELECT co.*
                FROM client_order co
                LEFT JOIN invoice i ON i.id = co.unpaid_invoice_id AND i.status = :unpaid_invoice_status
                WHERE co.status = :status
                AND co.invoice_option = :invoice_option
                AND co.period IS NOT NULL
                AND co.expires_at IS NOT NULL
                AND i.id IS NULL
                AND NOT EXISTS (
                    SELECT 1
                    FROM client_order_meta cancellation_meta
                    WHERE cancellation_meta.client_order_id = co.id
                    AND cancellation_meta.name = :cancellation_meta_name
                    AND cancellation_meta.value = :cancellation_meta_value
                )
                /* ClientFence core patch: as WHMCS, no renewal invoice while a cancellation request waits (Order\CancelRequests). */
                AND NOT EXISTS (
                    SELECT 1
                    FROM client_order_cancel_request cancel_request
                    WHERE cancel_request.client_order_id = co.id
                    AND cancel_request.status = :cancel_request_status
                )
                /* Pair non-executed renewal items with paid invoices to skip renewals already queued for activation. */
                AND NOT EXISTS (
                    SELECT 1
                    FROM invoice_item pending_item
                    INNER JOIN invoice pending_invoice ON pending_invoice.id = pending_item.invoice_id
                    WHERE pending_item.rel_id = co.id
                    AND pending_item.type = :pending_item_type
                    AND pending_item.task = :pending_item_task
                    AND pending_item.status != :pending_item_status
                    AND pending_invoice.status = :pending_invoice_status
                )';

        $where = [];
        $bindings = [];

        if ($client_id !== null) {
            $where[] = 'co.client_id = :client_id';
            $bindings['client_id'] = $client_id;
        }

        if (isset($data['id'])) {
            $where[] = 'co.id = :id';
            $bindings['id'] = $data['id'];
        }

        if (!empty($where)) {
            $query = $query . ' AND ' . implode(' AND ', $where);
        }

        // ClientFence core patch: for periods in days or weeks, at most half the period ahead (a weekly order was
        // renewed as soon as it started, with the 14 days lead of the longer periods).
        $query .= " HAVING DATEDIFF(co.expires_at, NOW()) <= CASE
                WHEN co.period LIKE '%W' AND (co.period + 0) * 7 / 2 < :days_until_expiration THEN FLOOR((co.period + 0) * 7 / 2)
                WHEN co.period LIKE '%D' AND (co.period + 0) / 2 < :days_short THEN FLOOR((co.period + 0) / 2)
                ELSE :days_long END
            ORDER BY co.client_id DESC";
        $bindings['status'] = Order::STATUS_ACTIVE;
        $bindings['invoice_option'] = 'issue-invoice';
        $bindings['unpaid_invoice_status'] = \Model_Invoice::STATUS_UNPAID;
        $bindings['cancellation_meta_name'] = self::META_CANCEL_AT_PERIOD_END;
        $bindings['cancellation_meta_value'] = '1';
        $bindings['cancel_request_status'] = CancelRequests::STATUS_PENDING;
        $bindings['pending_item_type'] = \Model_InvoiceItem::TYPE_ORDER;
        $bindings['pending_item_task'] = \Model_InvoiceItem::TASK_RENEW;
        $bindings['pending_item_status'] = \Model_InvoiceItem::STATUS_EXECUTED;
        $bindings['pending_invoice_status'] = \Model_Invoice::STATUS_PAID;
        $bindings['days_until_expiration'] = $days_until_expiration;
        $bindings['days_short'] = $days_until_expiration;
        $bindings['days_long'] = $days_until_expiration;

        return [$query, $bindings];
    }

    public function toApiArray(Order|\Model_ClientOrder $model, $deep = true, $identity = null): array
    {
        $clientService = $this->di['mod_service']('client');
        $supportService = $this->di['mod_service']('support');
        $modelId = $this->orderId($model);
        $modelClientId = $this->orderClientId($model);

        $data = [
            'id' => $modelId,
            'client_id' => $modelClientId,
            'product_id' => $this->orderProductId($model),
            'form_id' => $this->orderFormId($model),
            'promo_id' => $this->orderPromoId($model),
            'promo_recurring' => $model instanceof Order ? $model->isPromoRecurring() : (bool) $model->promo_recurring,
            'promo_used' => $model instanceof Order ? $model->getPromoUsed() : (int) $model->promo_used,
            'group_id' => $this->orderGroupId($model),
            'group_master' => $this->orderIsGroupMaster($model),
            'invoice_option' => $this->orderInvoiceOption($model),
            'title' => $this->orderTitle($model),
            'currency' => $this->orderCurrency($model),
            'unpaid_invoice_id' => $model instanceof Order ? $model->getUnpaidInvoiceId() : (int) $model->unpaid_invoice_id,
            'service_id' => $this->orderServiceId($model),
            'service_type' => $this->orderServiceType($model),
            'period' => $this->orderPeriod($model),
            'quantity' => $this->orderQuantity($model),
            'unit' => $model instanceof Order ? $model->getUnit() : $model->unit,
            'price' => $this->orderPrice($model),
            'discount' => $this->orderDiscount($model),
            'status' => $this->orderStatus($model),
            'reason' => $this->orderReason($model),
            'notes' => $this->orderNotes($model),
            'config' => $this->orderConfig($model),
            'referred_by' => $model instanceof Order ? $model->getReferredBy() : $model->referred_by,
            'expires_at' => $model instanceof Order ? $model->getExpiresAt()?->format('Y-m-d H:i:s') : $model->expires_at,
            'activated_at' => $model instanceof Order ? $model->getActivatedAt()?->format('Y-m-d H:i:s') : $model->activated_at,
            'suspended_at' => $model instanceof Order ? $model->getSuspendedAt()?->format('Y-m-d H:i:s') : $model->suspended_at,
            'unsuspended_at' => $model instanceof Order ? $model->getUnsuspendedAt()?->format('Y-m-d H:i:s') : $model->unsuspended_at,
            'canceled_at' => $model instanceof Order ? $model->getCanceledAt()?->format('Y-m-d H:i:s') : $model->canceled_at,
            'created_at' => $model instanceof Order ? $model->getCreatedAt()?->format('Y-m-d H:i:s') : $model->created_at,
            'updated_at' => $model instanceof Order ? $model->getUpdatedAt()?->format('Y-m-d H:i:s') : $model->updated_at,
        ];

        $data['config'] = json_decode($this->orderConfig($model) ?? '', true) ?? [];
        // ClientFence core patch: order responses must not expose provisioning passwords to clients.
        if (!$identity instanceof Admin && !$identity instanceof \Model_Admin) {
            unset($data['config']['password']);
        }
        $data['total'] = $this->getTotal($model);
        $data['discount'] ??= 0;
        if ($model instanceof Order) {
            $data['meta'] = $this->getOrderMetaRepository()->getPairsForOrder($modelId);
        } else {
            $data['meta'] = [];
            foreach ($this->di['db']->find('ClientOrderMeta', 'client_order_id = ?', [$modelId]) as $metaRow) {
                $data['meta'][$metaRow->name] = $metaRow->value;
            }
        }
        $data['active_tickets'] = $supportService->getSupportTicketRepository()->countActiveTicketsForOrder($modelId);
        $client = $model instanceof Order
            ? $this->di['em']->getRepository(ClientEntity::class)->find($modelClientId)
            : $this->di['db']->findOne('Client', 'id = ?', [$modelClientId]);
        if (!$client instanceof ClientEntity && !$client instanceof \Model_Client) {
            throw new InformationException('Client not found');
        }
        $data['client'] = $clientService->toApiArray($client, false);

        if ($identity instanceof Admin) {
            $data['config'] = $this->getConfig($model);
            $productService = $this->di['mod_service']('product');
            $data['plugin'] = $productService->getProductPluginById((int) $this->orderProductId($model));
        }

        return $this->withAudience($data, $identity);
    }

    /**
     * Get multiple orders in a batch for API response.
     *
     * @param array                                $ids      Array of order IDs to fetch
     * @param Admin|\Model_Admin|ClientEntity|null $identity The requesting identity
     *
     * @return array Array of order API arrays. Missing IDs are silently skipped.
     */
    public function getBatchForApi(array $ids, $identity = null): array
    {
        $ids = $this->normalizeIds($ids);
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $orders = $this->di['em']->getConnection()->fetchAllAssociative("SELECT * FROM client_order WHERE id IN ($placeholders)", $ids);
        if (empty($orders)) {
            return [];
        }
        $orders = $this->orderRowsByIds($orders, $ids);

        $orderIds = array_column($orders, 'id');
        $clientIds = $this->normalizeIds(array_column($orders, 'client_id'));
        $productIds = $this->normalizeIds(array_column($orders, 'product_id'));

        $clients = [];
        if (!empty($clientIds)) {
            $clientModels = $this->di['em']->getRepository(ClientEntity::class)->findBy(['id' => $clientIds]);
            $clientService = $this->di['mod_service']('client');
            foreach ($clientModels as $client) {
                $clients[$client->getId()] = $clientService->toApiArray($client, false);
            }
        }

        $meta = [];
        if (!empty($orderIds)) {
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $metaRows = $this->di['em']->getConnection()->fetchAllAssociative(
                "SELECT client_order_id, name, value FROM client_order_meta WHERE client_order_id IN ($placeholders)",
                $orderIds
            );
            foreach ($metaRows as $row) {
                $meta[$row['client_order_id']][$row['name']] = $row['value'];
            }
        }

        $activeTickets = [];
        if (!empty($orderIds)) {
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $rows = $this->di['em']->getConnection()->fetchAllAssociative(
                "SELECT rel_id, COUNT(id) as counter FROM support_ticket
                WHERE rel_type = 'order'
                AND rel_id IN ($placeholders)
                AND (status = 'open' OR status = 'on_hold')
                GROUP BY rel_id",
                $orderIds
            );
            foreach ($rows as $row) {
                $activeTickets[$row['rel_id']] = (int) $row['counter'];
            }
        }

        $plugins = [];
        if ($identity instanceof Admin && !empty($productIds)) {
            $productService = $this->di['mod_service']('product');
            $plugins = $productService->getProductPluginMap($productIds);
        }

        $result = [];
        foreach ($orders as $order) {
            $clientId = $order['client_id'];
            $data = $order;
            $data['config'] = json_decode($order['config'] ?? '', true) ?? [];
            if (!$identity instanceof Admin && !$identity instanceof \Model_Admin) {
                unset($data['config']['password']);
            }
            $data['total'] = $this->calculateTotal($order['price'], $order['quantity']);
            $data['title'] = $order['title'];
            $data['meta'] = $meta[$order['id']] ?? [];
            $data['active_tickets'] = $activeTickets[$order['id']] ?? 0;
            if (!isset($clients[$clientId])) {
                $this->di['logger']->error('Missing client for order ' . $order['id']);
                $data['client'] = [];
            } else {
                $data['client'] = $clients[$clientId];
            }

            if ($identity instanceof Admin) {
                $data['plugin'] = $plugins[$order['product_id']] ?? null;
            }

            $result[] = $this->withAudience($data, $identity);
        }

        return $result;
    }

    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_map(intval(...), array_filter($ids, is_numeric(...)))));
    }

    private function orderRowsByIds(array $rows, array $ids): array
    {
        $rowsById = [];
        foreach ($rows as $row) {
            $rowsById[(int) $row['id']] = $row;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($rowsById[$id])) {
                $ordered[] = $rowsById[$id];
            }
        }

        return $ordered;
    }

    public function getSearchQuery($data): array
    {
        // ClientFence: no join on the order meta (an order with several meta rows came back once per
        // row: inflated totals, short pages); each meta filter is its own EXISTS below.
        $query = 'SELECT co.* from client_order co
                LEFT JOIN client c ON c.id = co.client_id';

        $search = $data['search'] ?? false;
        $hide_addons = $data['hide_addons'] ?? null;
        $show_action_required = $data['show_action_required'] ?? null;
        $id = $data['id'] ?? null;
        $product_id = $data['product_id'] ?? null;
        $promo_id = $data['promo_id'] ?? null;
        $status = $data['status'] ?? null;
        $title = $data['title'] ?? null;
        $period = $data['period'] ?? null;
        $type = $data['type'] ?? null;
        $created_at = $data['created_at'] ?? null;
        $date_from = $data['date_from'] ?? null;
        $date_to = $data['date_to'] ?? null;
        $ids = (isset($data['ids']) && is_array($data['ids'])) ? $data['ids'] : null;
        $meta = (isset($data['meta']) && is_array($data['meta'])) ? $data['meta'] : null;

        $client_id = $data['client_id'] ?? null;
        $invoice_option = $data['invoice_option'] ?? null;

        $where = [];
        $bindings = [];

        if ($client_id) {
            $where[] = 'co.client_id = :client_id';
            $bindings['client_id'] = $client_id;
        }

        if ($invoice_option) {
            $where[] = 'co.invoice_option = :invoice_option';
            $bindings['invoice_option'] = $invoice_option;
        }

        if ($id) {
            $where[] = 'co.id = :id';
            $bindings['id'] = $id;
        }

        if ($show_action_required) {
            $where[] = '(co.status = \'pending_setup\' OR co.status = \'failed_setup\' OR co.status =\'failed_renew\')';
        }

        if ($status) {
            $where[] = 'co.status = :status';
            $bindings['status'] = $status;
        }

        if ($product_id) {
            $where[] = 'co.product_id = :product_id';
            $bindings['product_id'] = $product_id;
        }

        if ($promo_id) {
            $where[] = 'co.promo_id = :promo_id';
            $bindings['promo_id'] = $promo_id;
        }

        if ($type) {
            $where[] = 'co.service_type = :service_type';
            $bindings['service_type'] = $type;
        }

        if ($title) {
            $where[] = 'co.title LIKE :title';
            $bindings['title'] = '%' . $title . '%';
        }

        if ($period) {
            $where[] = 'co.period = :period';
            $bindings['period'] = $period;
        }

        if ($hide_addons) {
            $where[] = 'co.group_master = 1';
        }

        if ($created_at) {
            $where[] = "DATE_FORMAT(co.created_at, '%Y-%m-%d') = :created_at";
            $bindings['created_at'] = date('Y-m-d', strtotime((string) $created_at));
        }

        if ($date_from) {
            $where[] = 'UNIX_TIMESTAMP(co.created_at) >= :date_from';
            $bindings['date_from'] = strtotime((string) $date_from);
        }

        if ($date_to) {
            $where[] = 'UNIX_TIMESTAMP(co.created_at) <= :date_to';
            $bindings['date_to'] = strtotime((string) $date_to);
        }

        // smartSearch
        if ($search) {
            if (is_numeric($search)) {
                $where[] = 'co.id = :search';
                $bindings['search'] = $search;
            } else {
                $where[] = '(c.first_name LIKE :first_name OR c.last_name LIKE :last_name OR co.title LIKE :title)';
                $bindings['first_name'] = "%$search%";
                $bindings['last_name'] = "%$search%";
                $bindings['title'] = "%$search%";
            }
        }

        if ($ids) {
            $where[] = 'co.id IN (:ids)';
            $bindings['ids'] = implode(', ', $ids);
        }
        if ($meta) {
            $i = 1;
            foreach ($meta as $k => $v) {
                $where[] = "EXISTS (SELECT 1 FROM client_order_meta meta$i WHERE meta$i.client_order_id = co.id AND meta$i.name = :meta_name$i AND meta$i.value LIKE :meta_value$i)";
                $bindings['meta_name' . $i] = $k;
                $bindings['meta_value' . $i] = $v . '%';
                ++$i;
            }
        }

        if (!empty($where)) {
            $query = $query . ' WHERE ' . implode(' AND ', $where);
        }
        $query .= ' ORDER BY co.id DESC';

        return [$query, $bindings];
    }

    public function createOrder(ClientEntity|\Model_Client $client, Product $product, array $data)
    {
        $this->refuseOverrideInMeta($data);
        $quantity = PriceValidator::validateQuantity($data['quantity'] ?? 1);
        $price = isset($data['price']) ? PriceValidator::validateAmount($data['price']) : null;

        $currencyService = $this->di['mod_service']('currency');
        /** @var \ClientFence\Mod\Currency\Repository\CurrencyRepository $currencyRepository */
        $currencyRepository = $currencyService->getCurrencyRepository();

        $clientCurrency = $client instanceof ClientEntity ? $client->getCurrency() : $client->currency;
        // ClientFence core patch: an order bills in the client's currency. Its invoice took the order's, and a bank
        // payment then went to the client's credit as if in theirs (EGP 100 became USD 100).
        if (!empty($data['currency']) && $clientCurrency && strcasecmp((string) $data['currency'], (string) $clientCurrency) !== 0) {
            throw new InformationException('The order must be in the client\'s currency (:currency).', [':currency' => $clientCurrency]);
        }
        if (isset($data['currency']) && !empty($data['currency'])) {
            $currency = $currencyRepository->findOneByCode($data['currency']);
        } elseif ($clientCurrency) {
            $currency = $currencyRepository->findOneByCode($clientCurrency);
        } else {
            $currency = $currencyRepository->findDefault();
        }
        if (!$currency instanceof Currency) {
            throw new \ClientFence\Exception('Currency could not be determined for order');
        }

        $this->di['events_manager']->fire(['event' => 'onBeforeAdminOrderCreate', 'params' => $data, 'subject' => $this->getProductType($product)]);

        $period = (isset($data['period']) && !empty($data['period'])) ? $data['period'] : null;
        $config = (isset($data['config']) && is_array($data['config'])) ? $data['config'] : [];
        $group_id = $data['group_id'] ?? null;
        $activate = (bool) ($data['activate'] ?? false);
        $invoiceOption = $data['invoice_option'] ?? 'no-invoice';
        $skipValidation = (bool) ($data['skip_validation'] ?? false);

        $cartService = $this->di['mod_service']('cart');
        // check stock
        if (!$cartService->isStockAvailable($product, $quantity)) {
            throw new InformationException('Product :id is out of stock.', [':id' => $this->getProductId($product)], 831);
        }

        // Addons must have defined master order
        $parent_order = false;
        if ($this->isAddonProduct($product) && empty($group_id)) {
            throw new \ClientFence\Exception('Group ID parameter is missing for addon product order', null, 832);
        }

        if (!empty($group_id)) {
            $parent_order = $this->getMasterOrderForClient($client, $group_id);
            if (!$parent_order instanceof Order && !$parent_order instanceof \Model_ClientOrder) {
                throw new \ClientFence\Exception('Parent order :group_id was not found', [':group_id' => $group_id]);
            }
        }

        // ClientFence core patch: a period the product does not sell needs a price set by staff (it made a free
        // active order with no invoice).
        if ($price === null && $cartService->isRecurrentPricing($product)
            && ($period === null || !$cartService->isPeriodEnabledForProduct($product, $period))) {
            throw new InformationException('Choose a billing period this product sells, or set a price.');
        }

        // validate order config data
        if ($period) {
            $config['period'] = $period;
        }
        $se = $this->di['mod_service']('service' . $this->getProductType($product));
        if (method_exists($se, 'attachOrderConfig')) {
            $config = $se->attachOrderConfig($product, $config);
        }

        if (method_exists($se, 'validateOrderData')) {
            if (!$skipValidation) {
                $se->validateOrderData($config);
            }
        }

        // ClientFence core patch: staff domain orders take their billing period from the validated domain config.
        if ($period === null && $this->getProductType($product) === \ClientFence\Mod\Product\Service::DOMAIN) {
            $period = $config['period'] ?? null;
        }

        if (method_exists($se, 'generateOrderTitle')) {
            $generatedOrderTitle = $se->generateOrderTitle($config);
        } else {
            $generatedOrderTitle = null;
        }

        $invoice = null;
        $markInvoicePaid = \ClientFence\Tools::normalizeBoolean($data['mark_invoice_paid'] ?? false);

        $id = $this->di['em']->wrapInTransaction(function () use (
            $client,
            $config,
            $currency,
            $currencyRepository,
            $data,
            $generatedOrderTitle,
            $invoiceOption,
            $parent_order,
            $period,
            $price,
            $product,
            $quantity
        ) {
            $order = new Order();
            $order->setClientId($client instanceof ClientEntity ? $client->getId() : (int) $client->id);
            $order->setProductId($this->getProductId($product));
            $order->setFormId($this->getProductFormId($product));
            $parentGroupId = $parent_order ? $this->orderGroupId($parent_order) : null;
            $order->setGroupId($parentGroupId ?? uniqid());
            $order->setGroupMaster(!$parent_order);
            $order->setTitle($generatedOrderTitle ?? $data['title'] ?? $this->getProductTitle($product));
            $order->setCurrency($currency->getCode());
            $order->setServiceType($this->getProductType($product));
            $order->setUnit($this->getProductUnit($product));
            $order->setStatus(Order::STATUS_PENDING_SETUP);
            $order->setConfig(json_encode($config));
            $order->setInvoiceOption($invoiceOption);

            if ($period) {
                $bp = $this->di['period']($period);
                $order->setPeriod($bp->getCode());
            }

            $line = null;
            if (!isset($data['price']) || $this->getProductType($product) === \ClientFence\Mod\Product\Service::DOMAIN) {
                $productService = $this->di['mod_service']('Product');
                // ClientFence core patch: fixed currency prices (Product/CurrencyPrices.php).
                if ($productService instanceof \ClientFence\Mod\Product\Service) {
                    $productService = $productService->inCurrency($currency->getCode());
                }
                $line = $productService->getProductOrderLineConfig($product, array_merge($config, ['quantity' => $quantity]));
                $order->setQuantity($line['quantity']);
            } else {
                $order->setQuantity($quantity);
            }

            if ($price !== null) {
                $order->setPrice($price);
            } else {
                $rate = $currencyRepository->getRateByCode($currency->getCode());
                if ($rate === null) {
                    throw new \ClientFence\Exception("Currency rate for '{$currency->getCode()}' is not configured");
                }
                $order->setPrice($line['price'] * $rate);
            }

            $order->setNotes($data['notes'] ?? null);
            if (isset($data['created_at'])) {
                $order->setCreatedAt(new \DateTime(date('Y-m-d H:i:s', strtotime((string) $data['created_at']))));
            } else {
                $order->setCreatedAt(new \DateTime());
            }

            if (isset($data['updated_at'])) {
                $order->setUpdatedAt(new \DateTime(date('Y-m-d H:i:s', strtotime((string) $data['updated_at']))));
            } else {
                $order->setUpdatedAt(new \DateTime());
            }

            // ClientFence core patch: a client without a currency takes the order's, as at checkout (adding funds needs one).
            if ($client instanceof ClientEntity && !$client->getCurrency()) {
                $client->setCurrency($currency->getCode());
            } elseif ($client instanceof \Model_Client && !$client->currency) {
                $client->currency = $currency->getCode();
                $this->di['db']->store($client);
            }

            $this->di['em']->persist($order);
            $this->di['em']->flush();
            $orderId = $order->getId();

            if (isset($data['meta']) && is_array($data['meta'])) {
                foreach ($data['meta'] as $k => $v) {
                    $mm = $this->getOrderMetaRepository()->findOneByOrderIdAndName($orderId, $k);
                    if (!$mm instanceof OrderMeta) {
                        $mm = new OrderMeta();
                        $mm->setClientOrderId($orderId);
                        $mm->setName($k);
                        $mm->setCreatedAt(new \DateTime());
                    }
                    $mm->setValue($v);
                    $mm->setUpdatedAt(new \DateTime());
                    $this->di['em']->persist($mm);
                }
                $this->di['em']->flush();
            }

            return $orderId;
        });

        $order = $this->getOrderRepository()->find($id);
        if (!$order instanceof Order) {
            throw new \ClientFence\Exception('Order not found');
        }

        // Issue the invoice after the order-creation transaction commits.
        if ($invoiceOption == 'issue-invoice') {
            $invoiceService = $this->di['mod_service']('invoice');

            try {
                $invoice = $invoiceService->generateForOrder($this->getLegacyOrder($order));
            } catch (InformationException $e) {
                $this->di['logger']->warning($e->getMessage());
            }
        }

        if ($invoice instanceof \Model_Invoice) {
            $invoiceService = $this->di['mod_service']('invoice');

            try {
                $invoiceService->approveInvoice($invoice, ['id' => $invoice->id, 'use_credits' => true]);

                if ($markInvoicePaid) {
                    $invoiceService->markAsPaidByAdmin($invoice, $data);
                }
            } catch (\Exception $e) {
                $this->di['logger']->info($e->getMessage());

                try {
                    $invoiceService->addNote($invoice, 'Order was created, but invoice follow-up failed: ' . $e->getMessage());
                } catch (\Exception $noteException) {
                    $this->di['logger']->info($noteException->getMessage());
                }
            }
        }

        $this->di['events_manager']->fire(['event' => 'onAfterAdminOrderCreate', 'params' => ['id' => $order->getId()], 'subject' => $this->getProductType($product)]);

        $this->di['logger']->info('Created order #%s', $id);

        // activate immediately on creation
        if ($activate) {
            try {
                $this->activateOrder($order);
            } catch (\Exception $e) {
                $this->di['logger']->info($e->getMessage());
            }
        }

        return $id;
    }

    public function getMasterOrderForClient(ClientEntity|\Model_Client $client, $group_id): Order|\Model_ClientOrder|null
    {
        $clientId = $client instanceof ClientEntity ? $client->getId() : $client->id;
        if ($client instanceof \Model_Client) {
            $order = $this->di['db']->findOne('ClientOrder', 'group_id = :group_id AND group_master = 1 AND client_id = :client_id', [
                ':group_id' => $group_id,
                ':client_id' => $clientId,
            ]);

            return $order instanceof \Model_ClientOrder ? $order : null;
        }

        return $this->getOrderRepository()->findOneBy([
            'groupId' => $group_id,
            'groupMaster' => true,
            'clientId' => $clientId,
        ]);
    }

    /**
     * activate all addons on initial activation.
     *
     * @see https://github.com/boxbilling/boxbilling/issues/54
     */
    public function activateOrderAddons(Order|\Model_ClientOrder $order): bool
    {
        $isGroupMaster = $this->orderIsGroupMaster($order);
        if (!$isGroupMaster) {
            return false;
        }

        $list = $this->getOrderAddonsList($order);
        foreach ($list as $addon) {
            $addonId = $this->orderId($addon);

            try {
                // ClientFence core patch: family activation must respect each add-on's current state and setup policy.
                $status = $this->di['dbal']->fetchOne('SELECT status FROM client_order WHERE id = ?', [$addonId]);
                if (!in_array($status, [Order::STATUS_PENDING_SETUP, Order::STATUS_FAILED_SETUP], true)) {
                    continue;
                }
                $product = $this->di['mod_service']('product')->findProductById((int) $this->orderProductId($addon));
                if ($product->getSetup() !== \ClientFence\Mod\Product\Service::SETUP_AFTER_ORDER) {
                    continue;
                }
                $this->di['events_manager']->fire(['event' => 'onBeforeAdminOrderActivate', 'params' => ['id' => $addonId]]);
                $this->createFromOrder($addon);
                $this->di['events_manager']->fire(['event' => 'onAfterAdminOrderActivate', 'params' => ['id' => $addonId]]);
            } catch (\Exception $e) {
                $this->di['logger']->info($e->getMessage());
            }
        }

        return true;
    }

    public function activateOrder(Order|\Model_ClientOrder $order, $data = []): bool
    {
        $orderId = $this->orderId($order);
        if ($order instanceof Order) {
            $order = $this->getOrderRepository()->find($orderId);
            // ClientFence core patch: an invoice paid from credit may have just activated it through RedBean.
            if ($order instanceof Order) {
                $this->di['em']->refresh($order);
            }
        } else {
            $order = $this->di['db']->load('ClientOrder', $orderId);
        }
        if (!$order instanceof Order && !$order instanceof \Model_ClientOrder) {
            throw new \ClientFence\Exception('Order :id not found', [':id' => $orderId]);
        }
        $force = !empty($data['force']);

        $orderStatus = $this->orderStatus($order);
        if ($orderStatus === Order::STATUS_ACTIVE && !$force) {
            return true;
        }

        $statues = [
            Order::STATUS_PENDING_SETUP,
            Order::STATUS_FAILED_SETUP,
        ];
        if (!in_array($orderStatus, $statues) && !$force) {
            throw new \ClientFence\Exception('Only pending setup or failed orders can be activated');
        }

        $event_params = ['id' => $orderId];
        $this->di['events_manager']->fire(['event' => 'onBeforeAdminOrderActivate', 'params' => $event_params]);
        $result = $this->createFromOrder($order);
        if (is_array($result)) {
            $event_params = [...$event_params, ...$result];
        }
        $this->di['events_manager']->fire(['event' => 'onAfterAdminOrderActivate', 'params' => $event_params]);

        $this->activateOrderAddons($order);

        $this->di['logger']->info('Activated order #%s', $orderId);

        return true;
    }

    public function createFromOrder(Order|\Model_ClientOrder $order)
    {
        // ClientFence core patch: the stock is taken before provisioning and given back if it fails (it was taken
        // after, so a paid order of a sold-out product was provisioned anyway).
        $productId = (int) $this->orderProductId($order);
        $productService = $this->di['mod_service']('product');
        if ($productId) {
            $productService->reduceStock($productId, $this->orderQuantity($order));
        } else {
            $this->di['logger']->info("Order without product ID detected Order #{$this->orderId($order)}.");
        }

        try {
            $result = $this->provisionOrder($order);
        } catch (\Throwable $e) {
            if ($productId) {
                $productService->returnStock($productId, $this->orderQuantity($order));
            }

            throw $e;
        }

        $this->saveStatusChange($order, 'Order activated');

        return $result;
    }

    private function provisionOrder(Order|\Model_ClientOrder $order): mixed
    {
        $orderId = $this->orderId($order);
        $serviceType = $this->orderServiceType($order);

        $service = $this->getOrderService($order);
        if (!is_object($service)) {
            $mod = $this->di['mod']('service' . $serviceType);
            $s = $mod->getService();
            if (method_exists($s, 'create') || method_exists($s, 'action_create')) {
                $service = $this->_callOnService($order, Order::ACTION_CREATE);
                if (!is_object($service)) {
                    throw new \ClientFence\Exception('Error creating ' . $serviceType . ' service for order ' . $orderId);
                }

                $serviceId = method_exists($service, 'getId') ? $service->getId() : $service->id;
                if ($order instanceof Order) {
                    $order->setServiceId((int) $serviceId);
                    $order->setUpdatedAt(new \DateTime());
                } else {
                    $order->service_id = $serviceId;
                    $order->updated_at = date('Y-m-d H:i:s');
                }
                $this->persistOrder($order);
            }
        }

        // The provisioning call and the order status update that records its
        // outcome are treated as one unit: if anything here fails after the
        // service has already been provisioned (e.g. while computing the new
        // expiry date), the order must still end up in failed_setup instead
        // of being left in pending_setup. Otherwise a retry would call
        // _callOnService() again against a service that already exists on
        // the remote server.
        try {
            $result = $this->_callOnService($order, Order::ACTION_ACTIVATE);

            $period = $this->orderPeriod($order);
            $expiresAt = $order instanceof Order ? $order->getExpiresAt() : $order->expires_at;
            if (!empty($period)) {
                $from_time = ($expiresAt === null) ? time() : ($order instanceof Order ? ($expiresAt->getTimestamp() ?? time()) : strtotime((string) $expiresAt));

                $periodObj = $this->di['period']($period);
                $newExpires = date('Y-m-d H:i:s', $periodObj->getExpirationTime($from_time));
                if ($order instanceof Order) {
                    $order->setExpiresAt(new \DateTime($newExpires));
                } else {
                    $order->expires_at = $newExpires;
                }
            }

            if ($order instanceof Order) {
                $order->setStatus(Order::STATUS_ACTIVE);
                $order->setActivatedAt(new \DateTime());
                $order->setSuspendedAt(null);
                $order->setCanceledAt(null);
                $order->setUpdatedAt(new \DateTime());
            } else {
                $order->status = Order::STATUS_ACTIVE;
                $order->activated_at = date('Y-m-d H:i:s');
                $order->suspended_at = null;
                $order->canceled_at = null;
                $order->updated_at = date('Y-m-d H:i:s');
            }

            $this->persistOrder($order);
        } catch (\Throwable $e) {
            // Caught broadly (not just \Exception): an \Error or \TypeError
            // here means the service was already provisioned remotely, so
            // the order must still be recorded as failed_setup rather than
            // left in pending_setup for a retry to re-provision it.
            if ($order instanceof Order) {
                $order->setStatus(Order::STATUS_FAILED_SETUP);
            } else {
                $order->status = Order::STATUS_FAILED_SETUP;
            }
            $this->persistOrder($order);

            $this->saveStatusChange($order, $e->getMessage());

            throw $e;
        }

        return $result;
    }

    public function getOrderAddonsList(Order|\Model_ClientOrder $order): array
    {
        $groupId = $this->orderGroupId($order);
        $clientId = $this->orderClientId($order);

        if (!$order instanceof Order) {
            return $this->di['db']->find('ClientOrder', 'group_id = :group_id AND client_id = :client_id AND id != :id AND (group_master = 0 OR group_master IS NULL)', [
                ':group_id' => $groupId,
                ':client_id' => $clientId,
                ':id' => $this->orderId($order),
            ]);
        }

        $addons = $this->getOrderRepository()->findBy([
            'groupId' => $groupId,
            'clientId' => $clientId,
        ]);

        return array_values(array_filter($addons, fn (Order $addon): bool => $addon->getId() !== $order->getId() && !$addon->isGroupMaster()));
    }

    protected function _callOnService(Order|\Model_ClientOrder $order, $action)
    {
        // ClientFence core patch: failed order actions are recorded (Orders > Failed actions).
        $orderId = (int) $this->orderId($order);
        try {
            $result = $this->callOnServiceAction($order, $action);
        } catch (\Throwable $e) {
            ActionFailures::failed($this->di, $orderId, (string) $action, $e);

            throw $e;
        }
        ActionFailures::succeeded($this->di, $orderId, (string) $action);

        return $result;
    }

    private function callOnServiceAction(Order|\Model_ClientOrder $order, $action)
    {
        $serviceType = $this->orderServiceType($order);
        $serviceId = $this->orderServiceId($order);
        $orderId = $this->orderId($order);

        $repo = $this->di['mod_service']('service' . $serviceType);

        if (in_array($serviceType, self::BUILT_IN_SERVICE_TYPES, true)) {
            $m = 'action_' . $action;
            if (!method_exists($repo, $m) || !is_callable([$repo, $m])) {
                throw new \ClientFence\Exception('Service ' . $serviceType . ' do not support ' . $m);
            }

            return $repo->$m($order instanceof Order ? $this->getLegacyOrder($order) : $order);
        }

        $o = $this->getLegacyOrder($order);
        $service = null;
        $sdbname = 'service_' . $serviceType;
        if ($serviceId) {
            $service = $this->di['db']->load($sdbname, $serviceId);
        }
        if (method_exists($repo, $action) && is_callable([$repo, $action])) {
            return $repo->$action($o, $service);
        }

        $this->di['logger']->info("Service {$serviceType} does not support action {$action}.");

        return null;
    }

    public function stockSale(Product|int $product, $qty): bool
    {
        $productService = $this->di['mod_service']('product');

        return $productService->reduceStock($product, $qty);
    }

    public function updatePeriod(Order|\Model_ClientOrder $order, $period): int
    {
        if (!empty($period)) {
            $periodObj = $this->di['period']($period);
            if ($order instanceof Order) {
                $order->setPeriod($periodObj->getCode());
            } else {
                $order->period = $periodObj->getCode();
            }

            return 1;
        }

        if (!is_null($period)) {
            if ($order instanceof Order) {
                $order->setPeriod(null);
            } else {
                $order->period = null;
            }

            return 2;
        }

        return 0;
    }

    public function updateOrderMeta(Order|\Model_ClientOrder $order, $meta): int
    {
        if (!is_array($meta)) {
            return 0;
        }

        $orderId = $this->orderId($order);

        if (empty($meta)) {
            $this->di['dbal']->executeStatement('DELETE FROM client_order_meta WHERE client_order_id = ?', [$orderId]);

            return 1;
        }
        foreach ($meta as $k => $v) {
            $mm = $order instanceof Order
                ? $this->getOrderMetaRepository()->findOneByOrderIdAndName($orderId, $k)
                : $this->di['db']->findOne('ClientOrderMeta', 'client_order_id = :id AND name = :name', [':id' => $orderId, ':name' => $k]);
            if (!$mm instanceof OrderMeta) {
                if ($order instanceof Order) {
                    $mm = new OrderMeta();
                    $mm->setClientOrderId($orderId);
                    $mm->setName($k);
                    $mm->setCreatedAt(new \DateTime());
                } else {
                    $mm = $this->di['db']->dispense('ClientOrderMeta');
                    $mm->client_order_id = $orderId;
                    $mm->name = $k;
                    $mm->created_at = date('Y-m-d H:i:s');
                }
            }
            if ($mm instanceof OrderMeta) {
                $mm->setValue($v);
                $mm->setUpdatedAt(new \DateTime());
                $this->di['em']->persist($mm);
            } else {
                $mm->value = $v;
                $mm->updated_at = date('Y-m-d H:i:s');
                $this->di['db']->store($mm);
            }
        }
        if ($order instanceof Order) {
            $this->di['em']->flush();
        }

        return 2;
    }

    public function updateOrder(Order|\Model_ClientOrder $order, array $data): bool
    {
        $orderId = $this->orderId($order);
        $this->di['events_manager']->fire(['event' => 'onBeforeAdminOrderUpdate', 'params' => $data]);
        // ClientFence: checked before anything changes (a wrong date saves nothing).
        $this->refuseOverrideInMeta($data);
        $overrideMeta = array_key_exists(self::META_AUTO_SUSPEND_OVERRIDE, $data) ? $this->autoSuspendOverrideMeta($data) : null;
        $this->updatePeriod($order, $data['period'] ?? null);

        $created_at = $data['created_at'] ?? '';
        if (!empty($created_at)) {
            $createdAtDate = date('Y-m-d H:i:s', strtotime((string) $created_at));
            if ($order instanceof Order) {
                $order->setCreatedAt(new \DateTime($createdAtDate));
            } else {
                $order->created_at = $createdAtDate;
            }
        }

        $activated_at = $data['activated_at'] ?? null;
        if (!empty($activated_at)) {
            $activatedAtDate = date('Y-m-d H:i:s', strtotime((string) $activated_at));
            if ($order instanceof Order) {
                $order->setActivatedAt(new \DateTime($activatedAtDate));
            } else {
                $order->activated_at = $activatedAtDate;
            }
        }

        $expires_at = $data['expires_at'] ?? null;
        if (!empty($expires_at)) {
            $expiresAtDate = date('Y-m-d H:i:s', strtotime((string) $expires_at));
            if ($order instanceof Order) {
                $order->setExpiresAt(new \DateTime($expiresAtDate));
            } else {
                $order->expires_at = $expiresAtDate;
            }
        }
        if (empty($expires_at) && !is_null($expires_at)) {
            if ($order instanceof Order) {
                $order->setExpiresAt(null);
            } else {
                $order->expires_at = null;
            }
        }

        $invoiceOption = $data['invoice_option'] ?? $this->orderInvoiceOption($order);
        if ($order instanceof Order) {
            $order->setInvoiceOption($invoiceOption);
            $order->setTitle($data['title'] ?? $this->orderTitle($order));
        } else {
            $order->invoice_option = $invoiceOption;
            $order->title = $data['title'] ?? $this->orderTitle($order);
        }

        if (isset($data['price'])) {
            $price = PriceValidator::validateAmount($data['price']);
            if ($order instanceof Order) {
                $order->setPrice($price);
            } else {
                $order->price = $price;
            }
        }

        $currentStatus = $this->orderStatus($order);
        if (isset($data['status']) && $data['status'] !== $currentStatus) {
            if (!in_array($data['status'], Order::getValidStatuses(), true)) {
                throw new InformationException('Invalid order status: :status', [':status' => $data['status']]);
            }
            if ($order instanceof Order) {
                $order->setStatus($data['status']);
            } else {
                $order->status = $data['status'];
            }
        }

        $notes = $data['notes'] ?? $this->orderNotes($order);
        $reason = $data['reason'] ?? $this->orderReason($order);
        if ($order instanceof Order) {
            $order->setNotes($notes);
            $order->setReason($reason);
        } else {
            $order->notes = $notes;
            $order->reason = $reason;
        }

        $this->updateOrderMeta($order, $data['meta'] ?? null);
        if ($overrideMeta !== null) {
            $this->updateOrderMeta($order, $overrideMeta);
        }

        if ($order instanceof Order) {
            $order->setUpdatedAt(new \DateTime());
            $this->persistOrder($order);
        } else {
            $order->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($order);
        }

        $this->di['events_manager']->fire(['event' => 'onAfterAdminOrderUpdate', 'params' => ['id' => $orderId]]);

        $this->di['logger']->info('Update order #%s', $orderId);

        return true;
    }

    /**
     * ClientFence core patch: staff Renew, Unsuspend and the Retry of a failed renew or unsuspend run the order's
     * waiting paid renewal line when there is one: it unsuspends and renews once and is marked done. The bare
     * action renewed again on the next cron (the line stayed pending) or unsuspended without adding the paid
     * period (a failed line is never run again). Returns false when no paid line waits.
     */
    public function applyPaidRenewal(int $orderId): bool
    {
        $itemId = $this->di['dbal']->fetchOne(
            'SELECT ii.id FROM invoice_item ii JOIN invoice i ON i.id = ii.invoice_id WHERE ii.type = ? AND ii.rel_id = ? AND ii.task = ? AND ii.status IN (?, ?) AND i.status = ? ORDER BY ii.id LIMIT 1',
            [\Model_InvoiceItem::TYPE_ORDER, (string) $orderId, \Model_InvoiceItem::TASK_RENEW, \Model_InvoiceItem::STATUS_PENDING_SETUP, \Model_InvoiceItem::STATUS_FAILED, \Model_Invoice::STATUS_PAID],
        );
        if ($itemId === false || $itemId === null) {
            return false;
        }
        $item = $this->di['db']->getExistingModelById('InvoiceItem', (int) $itemId);
        $item->attempts = 0;
        $this->di['mod_service']('Invoice', 'InvoiceItem')->executeTask($item);
        if ($item->status !== \Model_InvoiceItem::STATUS_EXECUTED) {
            throw new InformationException('The paid renewal could not be applied. The order history has the error.');
        }

        return true;
    }

    public function renewOrder(Order|\Model_ClientOrder $order): bool
    {
        $orderId = $this->orderId($order);
        $this->di['events_manager']->fire(['event' => 'onBeforeAdminOrderRenew', 'params' => ['id' => $orderId]]);

        $this->renewFromOrder($order);

        $isGroupMaster = $this->orderIsGroupMaster($order);
        $orderStatus = $this->orderStatus($order);
        if ($isGroupMaster && $orderStatus == Order::STATUS_PENDING_SETUP) {
            $list = $this->getOrderAddonsList($order);
            foreach ($list as $addon) {
                try {
                    $this->renewFromOrder($addon);
                } catch (\Exception $e) {
                    $this->di['logger']->info($e->getMessage());
                }
            }
        }

        $this->di['events_manager']->fire(['event' => 'onAfterAdminOrderRenew', 'params' => ['id' => $orderId]]);
        $this->di['logger']->info('Renewed order #%s', $orderId);

        return true;
    }

    public function renewFromOrder(Order|\Model_ClientOrder $order): void
    {
        $orderId = $this->orderId($order);

        try {
            $result = $this->_callOnService($order, Order::ACTION_RENEW);
        } catch (\Exception $e) {
            if ($order instanceof Order) {
                $order->setStatus(Order::STATUS_FAILED_RENEW);
            } else {
                $order->status = Order::STATUS_FAILED_RENEW;
            }
            $this->persistOrder($order);

            $this->saveStatusChange($order, $e->getMessage());

            throw $e;
        }

        $period = $this->orderPeriod($order);
        $expiresAt = $order instanceof Order ? $order->getExpiresAt() : $order->expires_at;
        if (!empty($period)) {
            $from_time = ($expiresAt === null) ? time() : ($order instanceof Order ? $expiresAt->getTimestamp() : strtotime((string) $expiresAt));

            $config = $this->di['mod_config']('order');
            $logic = $config['order_renewal_logic'] ?? '';

            if ($logic == 'from_today') {
                $from_time = time();
            } elseif ($logic == 'from_greater') {
                $expiresTimestamp = $expiresAt === null ? time() : ($order instanceof Order ? $expiresAt->getTimestamp() : strtotime((string) $expiresAt));
                if ($expiresTimestamp > time()) {
                    $from_time = $expiresTimestamp;
                } else {
                    $from_time = time();
                }
            }
            $periodObj = $this->di['period']($period);
            $newExpires = date('Y-m-d H:i:s', $periodObj->getExpirationTime($from_time));
            if ($order instanceof Order) {
                $order->setExpiresAt(new \DateTime($newExpires));
            } else {
                $order->expires_at = $newExpires;
            }
        }

        if ($order instanceof Order) {
            $order->setStatus(Order::STATUS_ACTIVE);
            $order->setSuspendedAt(null);
            $order->setUnsuspendedAt(null);
            $order->setCanceledAt(null);
            $order->setUpdatedAt(new \DateTime());
        } else {
            $order->status = Order::STATUS_ACTIVE;
            $order->suspended_at = null;
            $order->unsuspended_at = null;
            $order->canceled_at = null;
            $order->updated_at = date('Y-m-d H:i:s');
        }
        $this->persistOrder($order);

        $this->saveStatusChange($order, 'Order renewed');
    }

    public function suspendFromOrder(Order|\Model_ClientOrder $order, $reason = null, $skipEvent = false): bool
    {
        $orderId = $this->orderId($order);
        $orderStatus = $this->orderStatus($order);

        if (!$skipEvent) {
            $this->di['events_manager']->fire(['event' => 'onBeforeAdminOrderSuspend', 'params' => ['id' => $orderId]]);
        }

        if ($orderStatus != Order::STATUS_ACTIVE) {
            throw new InformationException('Only active orders can be suspended');
        }

        $this->_callOnService($order, Order::ACTION_SUSPEND);

        if ($order instanceof Order) {
            $order->setStatus(Order::STATUS_SUSPENDED);
            $order->setReason($reason);
            $order->setSuspendedAt(new \DateTime());
            $order->setUpdatedAt(new \DateTime());
        } else {
            $order->status = Order::STATUS_SUSPENDED;
            $order->reason = $reason;
            $order->suspended_at = date('Y-m-d H:i:s');
            $order->updated_at = date('Y-m-d H:i:s');
        }
        $this->persistOrder($order);

        // ClientFence core patch: a blank reason (the auto suspend setting left empty) writes the plain note.
        $note = ($reason === null || trim($reason) === '') ? 'Order suspended' : 'Order suspended for ' . $reason;
        $this->saveStatusChange($order, $note);

        // ClientFence core patch: the active addons are suspended with their service (they stayed active and the
        // cron renewed them from credit); unsuspending the service brings back only these.
        if ($this->orderIsGroupMaster($order)) {
            foreach ($this->getOrderAddonsList($order) as $addon) {
                if ($this->orderStatus($addon) !== Order::STATUS_ACTIVE) {
                    continue;
                }
                try {
                    $this->suspendFromOrder($addon, $reason, $skipEvent);
                    $this->updateOrderMeta($addon, [self::META_SUSPENDED_WITH => (string) $orderId]);
                } catch (\Throwable $e) {
                    $this->di['logger']->warning('Could not suspend addon order #%s of #%s: %s', $this->orderId($addon), $orderId, $e->getMessage());
                }
            }
        }

        if (!$skipEvent) {
            $this->di['events_manager']->fire(['event' => 'onAfterAdminOrderSuspend', 'params' => ['id' => $orderId]]);
        }

        $this->di['logger']->info('Suspended order #%s', $orderId);

        return true;
    }

    public function unsuspendFromOrder(Order|\Model_ClientOrder $order): bool
    {
        $orderId = $this->orderId($order);
        $this->di['events_manager']->fire(['event' => 'onBeforeAdminOrderUnsuspend', 'params' => ['id' => $orderId]]);

        $this->_callOnService($order, Order::ACTION_UNSUSPEND);

        if ($order instanceof Order) {
            $order->setStatus(Order::STATUS_ACTIVE);
            $order->setReason(null);
            $order->setSuspendedAt(null);
            $order->setUnsuspendedAt(new \DateTime());
            $order->setUpdatedAt(new \DateTime());
        } else {
            $order->status = Order::STATUS_ACTIVE;
            $order->reason = null;
            $order->suspended_at = null;
            $order->unsuspended_at = date('Y-m-d H:i:s');
            $order->updated_at = date('Y-m-d H:i:s');
        }
        $this->persistOrder($order);

        $this->saveStatusChange($order, 'Order unsuspended');

        // ClientFence core patch: the addons suspended with this service come back with it (not those suspended for
        // their own unpaid invoice). An unsuspended order drops its mark, so a later suspension is never taken for it.
        $db = $this->di['dbal'];
        $db->executeStatement('DELETE FROM client_order_meta WHERE client_order_id = ? AND name = ?', [$orderId, self::META_SUSPENDED_WITH]);
        if ($this->orderIsGroupMaster($order)) {
            foreach ($this->getOrderAddonsList($order) as $addon) {
                $addonId = $this->orderId($addon);
                $with = $db->fetchOne('SELECT value FROM client_order_meta WHERE client_order_id = ? AND name = ?', [$addonId, self::META_SUSPENDED_WITH]);
                if ($this->orderStatus($addon) !== Order::STATUS_SUSPENDED || (string) $with !== (string) $orderId) {
                    continue;
                }
                try {
                    $this->unsuspendFromOrder($addon);
                } catch (\Throwable $e) {
                    $this->di['logger']->warning('Could not unsuspend addon order #%s of #%s: %s', $addonId, $orderId, $e->getMessage());
                }
            }
        }

        $this->di['events_manager']->fire(['event' => 'onAfterAdminOrderUnsuspend', 'params' => ['id' => $orderId]]);

        $this->di['logger']->info('Unsuspended order #%s', $orderId);

        return true;
    }

    public function cancelFromOrder(Order|\Model_ClientOrder $order, $reason = null, $skipEvent = false): bool
    {
        $orderId = $this->orderId($order);
        $this->assertOrderCanBeCanceled($order);

        // ClientFence core patch: a gateway failure must leave the hosting available for cancellation retries.
        $subscriptionService = $this->di['mod_service']('Invoice', 'Subscription');
        $subscriptionService->cancelForOrder($order instanceof Order ? $this->getLegacyOrder($order) : $order);

        $this->beginCancellation($order, $skipEvent);
        $this->completeCancellation($order, $reason, $skipEvent);

        $productService = $this->di['mod_service']('Product');
        $productService->releaseReservedPromoRedemptionsForOrder($order, 'order_canceled');

        $note = ($reason === null) ? 'Order canceled' : 'Canceled order for ' . $reason;
        $this->saveStatusChange($order, $note);

        if (!$skipEvent) {
            $this->di['events_manager']->fire(['event' => 'onAfterAdminOrderCancel', 'params' => ['id' => $orderId]]);
        }

        $this->cancelAddons($order, $reason, $skipEvent);

        $this->di['logger']->info('Canceled order #%s', $orderId);

        return true;
    }

    // ClientFence core patch: the addons go with their service. They stayed active, and the cron kept
    // invoicing them and paying them from the client's credit.
    private function cancelAddons(Order|\Model_ClientOrder $order, $reason, bool $skipEvent): void
    {
        if (!$this->orderIsGroupMaster($order)) {
            return;
        }
        foreach ($this->getOrderAddonsList($order) as $addon) {
            if (in_array($this->orderStatus($addon), [Order::STATUS_ACTIVE, Order::STATUS_SUSPENDED, Order::STATUS_FAILED_RENEW], true)) {
                try {
                    $this->cancelFromOrder($addon, $reason, $skipEvent);
                } catch (\Throwable $e) {
                    $this->di['logger']->warning('Could not cancel addon order #%s of #%s: %s', $this->orderId($addon), $this->orderId($order), $e->getMessage());
                }
            }
        }
    }

    public function scheduleCancellationFromOrder(Order|\Model_ClientOrder $order, $reason = null): bool
    {
        $orderId = $this->orderId($order);
        $this->assertOrderCanBeCanceled($order);

        $legacyOrder = $order instanceof Order ? $this->getLegacyOrder($order) : $order;
        if ((string) $legacyOrder->expires_at === '') {
            throw new InformationException('Only a service billed by period can be cancelled at the end of its period.');
        }
        // ClientFence core patch: a service without a gateway subscription ends at its period end too (WHMCS); the
        // expired-orders cron cancels it on its expiry date.
        $subscriptionService = $this->di['mod_service']('Invoice', 'Subscription');
        if ($subscriptionService->hasGatewaySubscriptionForOrder($legacyOrder)) {
            if (!$subscriptionService->canCancelAtPeriodEndForOrder($legacyOrder)) {
                throw new InformationException('No active gateway subscription that supports cancellation at period end is linked to this order.');
            }
            if ($subscriptionService->scheduleCancellationForOrder($legacyOrder) === 0) {
                throw new InformationException('No active gateway subscription is linked to this order.');
            }
        }
        $this->updateOrderMeta($order, [self::META_CANCEL_AT_PERIOD_END => '1']);
        // ClientFence core patch: a renewal invoice paid now would be lost when the service ends at the period end.
        $this->cancelOpenInvoices($orderId, 'Cancellation was scheduled at the end of the period.');
        // ClientFence core patch: its addons end with it, so the cron neither renews them nor takes credit for them.
        if ($this->orderIsGroupMaster($order)) {
            foreach ($this->getOrderAddonsList($order) as $addon) {
                if (in_array($this->orderStatus($addon), [Order::STATUS_ACTIVE, Order::STATUS_SUSPENDED, Order::STATUS_FAILED_RENEW], true)) {
                    $this->updateOrderMeta($addon, [self::META_CANCEL_AT_PERIOD_END => '1']);
                    $this->cancelOpenInvoices($this->orderId($addon), 'Cancellation was scheduled at the end of the period.');
                }
            }
        }

        if ($order instanceof Order) {
            $order->setReason($reason);
            $order->setUpdatedAt(new \DateTime());
        } else {
            $order->reason = $reason;
            $order->updated_at = date('Y-m-d H:i:s');
        }
        $this->persistOrder($order);
        $this->saveStatusChange($order, 'Cancellation scheduled at the end of the current billing period');
        $this->di['logger']->info('Scheduled cancellation for order #%s at the end of the current billing period', $orderId);

        return true;
    }

    /**
     * Whether the order can be cancelled at the end of its period: it has an expiry, and any gateway subscription
     * billing it supports cancellation at period end.
     */
    public function canCancelAtPeriodEnd(\Model_ClientOrder $order): bool
    {
        $subscriptionService = $this->di['mod_service']('Invoice', 'Subscription');

        return (string) $order->expires_at !== ''
            && (!$subscriptionService->hasGatewaySubscriptionForOrder($order) || $subscriptionService->canCancelAtPeriodEndForOrder($order));
    }

    public function finalizeCancellationFromGateway(Order|\Model_ClientOrder $order, $reason = null): bool
    {
        $this->assertOrderCanBeCanceled($order);
        $this->beginCancellation($order, false);

        $this->completeCancellation($order, $reason, false);
        $this->cancelAddons($order, $reason, false);

        return true;
    }

    private function assertOrderCanBeCanceled(Order|\Model_ClientOrder $order): void
    {
        $status = $this->orderStatus($order);
        if (!in_array($status, [Order::STATUS_CANCELED, Order::STATUS_PENDING_SETUP, Order::STATUS_FAILED_SETUP], true)) {
            return;
        }

        throw new \ClientFence\Exception('Cannot cancel ' . $status . ' order');
    }

    private function beginCancellation(Order|\Model_ClientOrder $order, bool $skipEvent): void
    {
        $orderId = $this->orderId($order);
        if (!$skipEvent) {
            $this->di['events_manager']->fire(['event' => 'onBeforeAdminOrderCancel', 'params' => ['id' => $orderId]]);
        }

        $this->_callOnService($order, Order::ACTION_CANCEL);
    }

    private function completeCancellation(Order|\Model_ClientOrder $order, $reason, bool $skipEvent): void
    {
        $orderId = $this->orderId($order);

        if ($order instanceof Order) {
            $order->setStatus(Order::STATUS_CANCELED);
            $order->setReason($reason);
            $order->setCanceledAt(new \DateTime());
            $order->setExpiresAt(null);
            $order->setSuspendedAt(null);
            $order->setUpdatedAt(new \DateTime());
        } else {
            $order->status = Order::STATUS_CANCELED;
            $order->reason = $reason;
            $order->canceled_at = date('Y-m-d H:i:s');
            $order->expires_at = null;
            $order->suspended_at = null;
            $order->updated_at = date('Y-m-d H:i:s');
        }
        $this->persistOrder($order);
        $this->di['dbal']->executeStatement(
            'DELETE FROM client_order_meta WHERE client_order_id = :order_id AND name = :name',
            ['order_id' => $orderId, 'name' => self::META_CANCEL_AT_PERIOD_END],
        );

        $this->cancelOpenInvoices($orderId, 'The order was cancelled.');
    }

    /**
     * ClientFence core patch: the order's open renewal invoice (one per order) and its waiting upgrade invoice
     * are cancelled when the order is cancelled or deleted. They stayed payable: the next credit paid them for
     * a service that was gone, or brought the cancelled order back for a new period.
     */
    private function cancelOpenInvoices(int $orderId, string $why): void
    {
        Upgrades::cancelWaiting($this->di, $orderId, $why);
        // ClientFence core patch: a renewal's recurring discount belongs to the same order.
        $this->di['dbal']->executeStatement(
            'UPDATE invoice i SET i.status = :canceled, i.updated_at = :now WHERE i.status = :unpaid
                AND EXISTS (SELECT 1 FROM invoice_item ii WHERE ii.invoice_id = i.id AND ii.type = :type AND ii.rel_id = :order_id AND ii.task = :renew)
                AND NOT EXISTS (SELECT 1 FROM invoice_item other WHERE other.invoice_id = i.id AND NOT (other.rel_id = :order_id AND (other.type = :type OR other.unit = :discount)))',
            ['canceled' => \Model_Invoice::STATUS_CANCELED, 'now' => date('Y-m-d H:i:s'), 'unpaid' => \Model_Invoice::STATUS_UNPAID, 'type' => \Model_InvoiceItem::TYPE_ORDER, 'order_id' => (string) $orderId, 'renew' => \Model_InvoiceItem::TASK_RENEW, 'discount' => 'discount'],
        );
    }

    public function uncancelFromOrder(Order|\Model_ClientOrder $order): bool
    {
        $orderId = $this->orderId($order);
        $this->di['events_manager']->fire(['event' => 'onBeforeAdminOrderUncancel', 'params' => ['id' => $orderId]]);

        $this->_callOnService($order, Order::ACTION_UNCANCEL);

        $expiresAt = null;
        $period = $this->orderPeriod($order);
        if ($period) {
            $periodObj = $this->di['period']($period);
            $expiresAt = $periodObj->getExpirationTime(time());
            $expiresAt = date('Y-m-d H:i:s', $expiresAt);
        }

        if ($order instanceof Order) {
            $order->setStatus(Order::STATUS_ACTIVE);
            $order->setReason(null);
            $order->setActivatedAt(new \DateTime());
            $order->setExpiresAt($expiresAt ? new \DateTime($expiresAt) : null);
            $order->setSuspendedAt(null);
            $order->setCanceledAt(null);
            $order->setUpdatedAt(new \DateTime());
        } else {
            $order->status = Order::STATUS_ACTIVE;
            $order->reason = null;
            $order->activated_at = date('Y-m-d H:i:s');
            $order->expires_at = $expiresAt;
            $order->suspended_at = null;
            $order->canceled_at = null;
            $order->updated_at = date('Y-m-d H:i:s');
        }
        $this->persistOrder($order);

        $this->saveStatusChange($order, 'Activated canceled order');

        $this->di['events_manager']->fire(['event' => 'onAfterAdminOrderUncancel', 'params' => ['id' => $orderId]]);

        $this->di['logger']->info('Uncanceled order #%s', $orderId);

        return true;
    }

    public function rmInvoiceItemByOrder(Order|\Model_ClientOrder $order): void
    {
        $params = [
            'rel_id' => (string) $this->orderId($order),
            'status' => \Model_InvoiceItem::STATUS_PENDING_PAYMENT,
        ];
        // ClientFence core patch: the tax-inclusive cents were for all the invoice's lines (Invoice\Service::applyInclusiveTax()).
        $this->di['dbal']->executeStatement('UPDATE invoice SET tax_adjust = 0 WHERE id IN (SELECT invoice_id FROM invoice_item WHERE rel_id = :rel_id AND status = :status)', $params);
        $this->di['dbal']->executeStatement('DELETE FROM invoice_item WHERE rel_id = :rel_id AND status = :status', $params);
    }

    public function rmClientOrderStatusByOrder(Order|\Model_ClientOrder $order): void
    {
        $orderId = $this->orderId($order);
        if ($order instanceof Order) {
            $this->getOrderStatusRepository()->rmByOrderId($orderId);

            return;
        }

        $this->di['dbal']->executeStatement('DELETE FROM client_order_status WHERE client_order_id = :id', ['id' => $orderId]);
    }

    public function rmOrder(Order|\Model_ClientOrder $model): void
    {
        $isGroupMaster = $this->orderIsGroupMaster($model);

        if ($isGroupMaster) {
            $list = $this->getOrderAddonsList($model);
            foreach ($list as $addon) {
                if ($addon instanceof Order) {
                    $addon->setGroupMaster(true);
                    $addon->setGroupId('0');
                    $this->di['em']->persist($addon);
                } else {
                    $addon->group_master = 1;
                    $addon->group_id = 0;
                    $this->di['db']->store($addon);
                }
            }
            if ($list !== [] && $list[0] instanceof Order) {
                $this->di['em']->flush();
            }
        }
        if ($model instanceof Order) {
            $this->di['em']->remove($model);
            $this->di['em']->flush();
        } else {
            $this->di['db']->trash($model);
        }
    }

    public function deleteFromOrder(Order|\Model_ClientOrder $order, bool $forceDelete = false): bool
    {
        $orderId = $this->orderId($order);
        $this->di['events_manager']->fire(['event' => 'onBeforeAdminOrderDelete', 'params' => ['id' => $orderId]]);

        $orderStatus = $this->orderStatus($order);
        if ($orderStatus == Order::STATUS_PENDING_SETUP) {
            $this->rmInvoiceItemByOrder($order);
        }

        try {
            // ClientFence core patch: deleting a service must stop its gateway subscription first.
            $subscriptionService = $this->di['mod_service']('Invoice', 'Subscription');
            $subscriptionService->cancelForOrder($order instanceof Order ? $this->getLegacyOrder($order) : $order);
            $this->_callOnService($order, Order::ACTION_DELETE);
        } catch (\Exception $e) {
            if (!$forceDelete) {
                throw $e;
            }
            $this->di['logger']->info("{$e->getMessage()} in {$e->getFile()} : {$e->getFile()}");
        }
        if ($orderStatus != Order::STATUS_PENDING_SETUP) {
            $this->cancelOpenInvoices($orderId, 'The order was deleted.');
        }

        $productService = $this->di['mod_service']('Product');
        $productService->releaseReservedPromoRedemptionsForOrder($order, 'order_deleted');
        $this->rmClientOrderStatusByOrder($order);
        $this->rmOrder($order);

        $this->di['events_manager']->fire(['event' => 'onAfterAdminOrderDelete', 'params' => ['id' => $orderId]]);
        $this->di['logger']->info('Deleted order #%s', $orderId);

        return true;
    }

    public function getExpiredOrders()
    {
        return $this->getOrderRepository()->getExpired();
    }

    /**
     * ClientFence: whether the cron leaves an order alone (neither suspended nor cancelled
     * automatically; staff actions are not affected). Domain orders always: a cancel asks the
     * registrar to delete the domain. Other orders while their "Override Auto-Suspend" is on
     * (autoSuspendOverrideActive()).
     */
    public function isExemptFromAutoSuspension(Order $order): bool
    {
        if ($order->getServiceType() === 'domain') {
            return true;
        }

        return self::autoSuspendOverrideActive($this->getOrderMetaRepository()->getPairsForOrder((int) $order->getId()));
    }

    /**
     * ClientFence: whether an order's "Override Auto-Suspend" is on: ticked, with no date or until
     * the end of that date, server time (as in WHMCS; the cron may suspend the order the next day).
     *
     * @param array<string, mixed> $meta the order's meta (name => value)
     */
    public static function autoSuspendOverrideActive(array $meta): bool
    {
        if ((string) ($meta[self::META_AUTO_SUSPEND_OVERRIDE] ?? '') !== '1') {
            return false;
        }
        $until = (string) ($meta[self::META_AUTO_SUSPEND_UNTIL] ?? '');
        if ($until === '') {
            return true;
        }
        $end = strtotime($until);

        return $end !== false && date('Y-m-d', $end) >= date('Y-m-d');
    }

    /**
     * ClientFence: the order meta for the Edit Order tab's "Override Auto-Suspend" (ticked) and "Do
     * not suspend until" (YYYY-MM-DD or blank). Unticked clears both.
     *
     * @return array<string, string>
     */
    private function autoSuspendOverrideMeta(array $data): array
    {
        if (!\ClientFence\Tools::normalizeBoolean($data[self::META_AUTO_SUSPEND_OVERRIDE] ?? false)) {
            return [self::META_AUTO_SUSPEND_OVERRIDE => '0', self::META_AUTO_SUSPEND_UNTIL => ''];
        }
        $until = trim((string) ($data[self::META_AUTO_SUSPEND_UNTIL] ?? ''));
        if ($until !== '') {
            $date = \DateTime::createFromFormat('!Y-m-d', $until);
            if (!$date instanceof \DateTime || $date->format('Y-m-d') !== $until) {
                throw new InformationException('Enter the "Do not suspend until" date as YYYY-MM-DD, or leave it blank.');
            }
        }

        return [self::META_AUTO_SUSPEND_OVERRIDE => '1', self::META_AUTO_SUSPEND_UNTIL => $until];
    }

    /**
     * ClientFence: the override is set only through its own fields (auto_suspend_override and
     * auto_suspend_until, which check the date), never through `meta`.
     */
    private function refuseOverrideInMeta(array $data): void
    {
        if (!is_array($data['meta'] ?? null)) {
            return;
        }
        $names = array_map(fn ($name): string => strtolower(trim((string) $name)), array_keys($data['meta']));
        if (array_intersect($names, self::STAFF_ONLY_META) !== []) {
            throw new InformationException('Set the auto-suspend override with auto_suspend_override and auto_suspend_until, not in meta.');
        }
    }

    /**
     * ClientFence: what a client may see of an order: no staff notes and no staff-only meta (the
     * client API used to return both). Staff get whether the auto-suspend override is on.
     *
     * @param array<string, mixed> $data an order as toApiArray() or getBatchForApi() build it
     */
    private function withAudience(array $data, mixed $identity): array
    {
        if ($identity instanceof ClientEntity || $identity instanceof \Model_Client) {
            unset($data['notes']);
            $data['meta'] = array_diff_key($data['meta'] ?? [], array_flip(self::STAFF_ONLY_META));

            return $data;
        }
        $data['auto_suspend_off'] = self::autoSuspendOverrideActive($data['meta'] ?? []);

        return $data;
    }

    /**
     * ClientFence: the cron suspends an active order once it is "Suspend After Due Date" days past
     * its expiry (batch_suspend_after_days, default BATCH_SUSPEND_AFTER_DAYS as in WHMCS; 0
     * suspends on the day), except exempt orders (isExemptFromAutoSuspension()).
     */
    public function batchSuspendExpired(): bool
    {
        $this->di['events_manager']->fire(['event' => 'onBeforeAdminBatchSuspendOrders']);

        $mod = $this->di['mod']('order');
        $c = $mod->getConfig();

        $reason = $c['batch_suspend_reason'] ?? null;
        $days = $this->resolveBatchAfterDays($c['batch_suspend_after_days'] ?? null, self::BATCH_SUSPEND_AFTER_DAYS);

        // ClientFence core patch: a cancellation scheduled at the period end ends the service on its expiry date, not
        // after the suspension delay, and also when auto-suspend is overridden (staff scheduled it).
        $scheduled = $this->di['dbal']->fetchFirstColumn(
            "SELECT co.id FROM client_order co JOIN client_order_meta m ON m.client_order_id = co.id AND m.name = ? AND m.value = '1'
             WHERE co.status IN (?, ?) AND co.expires_at IS NOT NULL AND co.expires_at <= ?",
            [self::META_CANCEL_AT_PERIOD_END, Order::STATUS_ACTIVE, Order::STATUS_SUSPENDED, date('Y-m-d H:i:s')],
        );
        foreach ($scheduled as $id) {
            try {
                Tools::withLock($this->di, 'fb:order_task:' . $id, function () use ($id): void {
                    $order = $this->getOrderRepository()->find((int) $id);
                    if (!$order instanceof Order) {
                        return;
                    }
                    $this->di['em']->refresh($order);
                    if (!in_array($order->getStatus(), [Order::STATUS_ACTIVE, Order::STATUS_SUSPENDED], true) || $order->getExpiresAt() === null || $order->getExpiresAt() > new \DateTime()
                        || $this->hasUnexecutedPaidOrderItem((int) $order->getId())) {
                        return;
                    }
                    $this->cancelFromOrder($order, 'Cancellation scheduled at the end of the period');
                });
            } catch (\Exception $e) {
                $this->di['logger']->info($e->getMessage());
            }
        }

        $before = new \DateTime("-{$days} days");
        $list = $this->getOrderRepository()->getExpired($before);

        foreach ($list as $order) {
            try {
                // ClientFence core patch: suspension must recheck renewal changes under the paid-task lock.
                Tools::withLock($this->di, 'fb:order_task:' . $order->getId(), function () use ($order, $reason, $before): void {
                    $order = $this->getOrderRepository()->find($order->getId());
                    if (!$order instanceof Order) {
                        throw new \ClientFence\Exception('Order not found');
                    }
                    $this->di['em']->refresh($order);
                    if ($order->getStatus() !== Order::STATUS_ACTIVE || $order->getExpiresAt() === null || $order->getExpiresAt() > $before) {
                        return;
                    }
                    if ($this->isExemptFromAutoSuspension($order) || $this->hasUnexecutedPaidOrderItem((int) $order->getId())) {
                        return;
                    }
                    $this->suspendFromOrder($order, $reason);
                });
            } catch (\Exception $e) {
                $this->di['logger']->info($e->getMessage());
            }
        }

        $this->di['events_manager']->fire(['event' => 'onAfterAdminBatchSuspendOrders']);

        $this->di['logger']->info('Executed action to suspend expired orders');

        return true;
    }

    public function batchCancelSuspended(): bool
    {
        $this->di['events_manager']->fire(['event' => 'onBeforeAdminBatchCancelSuspendedOrders']);

        $mod = $this->di['mod']('order');
        $config = $mod->getConfig();
        if (!isset($config['batch_cancel_suspended']) || !$config['batch_cancel_suspended']) {
            return false;
        }

        $reason = $config['batch_cancel_suspended_reason'] ?? null;
        $days = $this->resolveBatchAfterDays($config['batch_cancel_suspended_after_days'] ?? null);

        // ClientFence: only orders past their due date (unpaid). An order staff suspended for
        // another reason while it is paid up is never cancelled (and its service deleted) by cron.
        $sql = "
            SELECT id, suspended_at, DATEDIFF(NOW(), suspended_at) as days_passed_since_suspension
            FROM client_order
            WHERE status = 'suspended'
            AND DATEDIFF(NOW(), suspended_at) > :days
            AND expires_at IS NOT NULL
            AND expires_at <= NOW()
            ORDER BY id DESC
        ";

        $orders = $this->di['em']->getConnection()->fetchAllAssociative($sql, ['days' => $days]);

        foreach ($orders as $orderArr) {
            try {
                // ClientFence core patch: cancellation must recheck payment changes under the paid-task lock.
                Tools::withLock($this->di, 'fb:order_task:' . (int) $orderArr['id'], function () use ($orderArr, $reason): void {
                    $order = $this->getOrderRepository()->find((int) $orderArr['id']);
                    if (!$order instanceof Order) {
                        throw new \ClientFence\Exception('Order not found');
                    }
                    $this->di['em']->refresh($order);
                    if ($order->getStatus() !== Order::STATUS_SUSPENDED || $order->getExpiresAt() === null || $order->getExpiresAt() > new \DateTime()) {
                        return;
                    }
                    if ($this->isExemptFromAutoSuspension($order) || $this->hasUnexecutedPaidOrderItem((int) $order->getId())) {
                        return;
                    }
                    $this->cancelFromOrder($order, $reason);
                });
            } catch (\Exception $e) {
                $this->di['logger']->info($e->getMessage());
            }
        }

        $this->di['events_manager']->fire(['event' => 'onAfterAdminBatchCancelSuspendedOrders']);

        $this->di['logger']->info('Executed action to cancel suspended orders');

        return true;
    }

    // ClientFence core patch: a paid renewal waiting for its task protects the service (a failed or activation line does not).
    private function hasUnexecutedPaidOrderItem(int $orderId): bool
    {
        return (bool) $this->di['dbal']->fetchOne(
            'SELECT 1 FROM invoice_item ii JOIN invoice i ON i.id = ii.invoice_id WHERE ii.type = ? AND ii.rel_id = ? AND ii.task = ? AND ii.status = ? AND i.status = ? LIMIT 1',
            [\Model_InvoiceItem::TYPE_ORDER, (string) $orderId, \Model_InvoiceItem::TASK_RENEW, \Model_InvoiceItem::STATUS_PENDING_SETUP, \Model_Invoice::STATUS_PAID],
        );
    }

    public function batchCancelUnpaid(): bool
    {
        $this->di['events_manager']->fire(['event' => 'onBeforeAdminBatchCancelUnpaidOrders']);

        $mod = $this->di['mod']('order');
        $config = $mod->getConfig();
        if (!isset($config['batch_cancel_unpaid']) || !$config['batch_cancel_unpaid']) {
            return false;
        }

        $days = $this->resolveBatchAfterDays($config['batch_cancel_unpaid_after_days'] ?? null);

        $staleOrders = $this->getOrderRepository()->getStaleUnpaid($days);

        $invoiceService = $this->di['mod_service']('Invoice');

        // A single invoice can cover several orders from one cart checkout
        // (Cart\Service sets the same unpaid_invoice_id on all of them), so
        // they're batch-loaded once up front rather than once per order.
        $invoiceIds = array_values(array_unique(array_filter(
            array_map(static fn (Order $order): ?int => $order->getUnpaidInvoiceId(), $staleOrders),
            static fn (?int $id): bool => $id !== null
        )));
        $invoicesById = [];
        if ($invoiceIds !== []) {
            $placeholders = [];
            $params = [];
            foreach ($invoiceIds as $idx => $id) {
                $placeholder = ':invoice_id_' . $idx;
                $placeholders[] = $placeholder;
                $params['invoice_id_' . $idx] = $id;
            }
            $invoices = $this->di['db']->find('Invoice', 'id IN (' . implode(',', $placeholders) . ')', $params);
            foreach ($invoices as $invoice) {
                $invoicesById[(int) $invoice->id] = $invoice;
            }
        }

        // Whether each invoice's group may proceed to order deletion - set only
        // once the invoice is confirmed gone or removed, so a failed removal
        // leaves it unresolved for a sibling order to retry rather than
        // wrongly treating the group as already handled.
        $invoiceHandled = [];

        // Pending-setup orders were never provisioned, so cancelFromOrder() (which
        // tears down an active service) explicitly rejects them. deleteFromOrder()
        // is the same path an admin uses to manually remove one. The linked unpaid
        // invoice is removed first via deleteInvoiceByAdmin() so it doesn't linger
        // empty and still eligible for reminder emails after the order it belongs
        // to is gone.
        foreach ($staleOrders as $order) {
            try {
                // Re-check the order's current status before touching anything for
                // it: deleteFromOrder() has no status guard of its own, and this
                // order may have been activated or otherwise moved on while earlier
                // orders in this same run were being processed.
                $this->di['em']->refresh($order);
                if ($order->getStatus() !== Order::STATUS_PENDING_SETUP) {
                    continue;
                }

                $invoiceId = $order->getUnpaidInvoiceId();
                if ($invoiceId !== null) {
                    if (!array_key_exists($invoiceId, $invoiceHandled)) {
                        $invoice = $invoicesById[$invoiceId] ?? null;
                        $status = $invoice instanceof \Model_Invoice ? $invoice->status : null;

                        if ($status === \Model_Invoice::STATUS_PAID) {
                            // Paid since getStaleUnpaid() ran - leave every order tied
                            // to it alone instead of deleting one out from under that.
                            $invoiceHandled[$invoiceId] = false;
                        } else {
                            if ($status === \Model_Invoice::STATUS_UNPAID) {
                                $invoiceService->deleteInvoiceByAdmin($invoice);
                            }
                            // Already gone, or canceled/refunded/some other non-live
                            // status - either way it's no longer a live unpaid invoice,
                            // so the orders that reference it may proceed.
                            $invoiceHandled[$invoiceId] = true;
                        }
                    }

                    if (!$invoiceHandled[$invoiceId]) {
                        continue;
                    }
                }

                $this->deleteFromOrder($order);
            } catch (\Exception $e) {
                $this->di['logger']->info($e->getMessage());
            }
        }

        $this->di['events_manager']->fire(['event' => 'onAfterAdminBatchCancelUnpaidOrders']);

        $this->di['logger']->info('Executed action to remove stale unpaid orders');

        return true;
    }

    /**
     * Parses a "cancel/remove after N days" batch-job setting. A blank form field is
     * submitted as '', which isset() treats as present - so this is not just `(int) $value`,
     * which would silently turn a blank field into 0 instead of the intended default.
     */
    private function resolveBatchAfterDays(mixed $configValue, int $default = 7): int
    {
        $days = ($configValue === null || $configValue === '') ? $default : (int) $configValue;

        return $days < 0 ? $default : $days;
    }

    public function updateOrderConfig(Order|\Model_ClientOrder $order, array $config): bool
    {
        $formId = $this->orderFormId($order);
        $orderId = $this->orderId($order);

        if ($formId) {
            $formbuilderService = $this->di['mod_service']('formbuilder');
            $form = $formbuilderService->getForm((int) $formId);
            $this->validateConfigAgainstForm($config, $form);

            // ClientFence core patch (0.8.9 port): replace form answers without losing service settings or retaining unchecked choices.
            $existingConfig = $this->getConfig($order);
            foreach ($form['fields'] as $field) {
                unset($existingConfig[$field['name']]);
            }
            $config = array_replace($existingConfig, $config);
        }

        $oldConfig = $this->orderConfig($order);

        // ClientFence core patch: configurable options (Product/Options.php) change on the order's
        // Configurable options tab only (Order\Upgrades::staffSetOptions()): the raw config keeps them.
        unset($config['options'], $config['option_lines']);
        $previous = json_decode($oldConfig ?? '', true);
        foreach (['options', 'option_lines'] as $key) {
            if (is_array($previous) && array_key_exists($key, $previous)) {
                $config[$key] = $previous[$key];
            }
        }

        if ($order instanceof Order) {
            $order->setConfig(json_encode($config));
            $order->setUpdatedAt(new \DateTime());
            $this->di['em']->persist($order);
            $this->di['em']->flush();
        } else {
            $order->config = json_encode($config);
            $order->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($order);
        }

        $this->di['logger']->info(sprintf("Order #%s config changes:\n%s\n%s", $orderId, $oldConfig, $this->orderConfig($order)));

        return true;
    }

    private function validateConfigAgainstForm(array $config, array $form): void
    {
        foreach ($form['fields'] as $field) {
            $name = $field['name'];
            $value = $config[$name] ?? null;

            if (!empty($field['required']) && ($value === null || $value === '' || (is_array($value) && count($value) === 0))) {
                throw new \ClientFence\Exception('Field ":field" is required', [':field' => $field['label']], 4892);
            }

            $options = $field['options'] ?? [];
            if (!empty($options)) {
                if ($field['type'] === 'select' || $field['type'] === 'radio') {
                    if ($value === null || $value === '') {
                        continue;
                    }

                    if (!is_scalar($value)) {
                        throw new \ClientFence\Exception('Invalid value for field ":field"', [':field' => $field['label']], 4893);
                    }

                    if (!array_key_exists($value, $options) && !in_array($value, $options, true)) {
                        throw new \ClientFence\Exception('Invalid value for field ":field"', [':field' => $field['label']], 4893);
                    }
                } elseif ($field['type'] === 'checkbox') {
                    if (is_array($value)) {
                        foreach ($value as $v) {
                            if (!in_array($v, $options, true)) {
                                throw new \ClientFence\Exception('Invalid value for field ":field"', [':field' => $field['label']], 4894);
                            }
                        }
                    }
                }
            }
        }
    }

    public function getOrderStatusSearchQuery($data): array
    {
        $query = 'SELECT * FROM client_order_status';

        $oid = $data['client_order_id'] ?? null;

        $where = [];
        $bindings = [];

        if ($oid !== null) {
            $where[] = 'client_order_id = :client_order_id';

            $bindings['client_order_id'] = $oid;
        }

        if (!empty($where)) {
            $query = $query . ' WHERE ' . implode(' AND ', $where);
        }

        $query .= ' ORDER BY id DESC';

        return [$query, $bindings];
    }

    public function orderStatusAdd(Order|\Model_ClientOrder $order, $status, $notes = null): bool
    {
        if (!in_array($status, Order::getValidStatuses(), true)) {
            throw new InformationException('Invalid order status: :status', [':status' => $status]);
        }

        $orderId = $this->orderId($order);

        if ($order instanceof \Model_ClientOrder) {
            $bean = $this->di['db']->dispense('ClientOrderStatus');
            $bean->client_order_id = $orderId;
            $bean->status = $status;
            $bean->notes = $notes;
            $bean->created_at = date('Y-m-d H:i:s');
            $bean->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($bean);

            return true;
        }

        $bean = new OrderStatus();
        $bean->setClientOrderId($orderId);
        $bean->setStatus($status);
        $bean->setNotes($notes);
        $this->di['em']->persist($bean);
        $this->di['em']->flush();

        $this->di['logger']->info('Added order status history message to order #%s', $bean->getId());

        return true;
    }

    public function orderStatusRm($id): bool
    {
        $orderStatus = $this->getOrderStatusRepository()->find($id);
        if (!$orderStatus instanceof OrderStatus) {
            throw new \ClientFence\Exception('Order history line not found');
        }

        $this->di['em']->remove($orderStatus);
        $this->di['em']->flush();

        $this->di['logger']->info('Order history line removed');

        return true;
    }

    public function findForClientById(ClientEntity|\Model_Client $client, $id): Order|\Model_ClientOrder|null
    {
        $clientId = $client instanceof ClientEntity ? $client->getId() : $client->id;
        if ($client instanceof \Model_Client) {
            $order = $this->di['db']->findOne('ClientOrder', 'id = :id AND client_id = :client_id', [':id' => (int) $id, ':client_id' => $clientId]);

            return $order instanceof \Model_ClientOrder ? $order : null;
        }

        $order = $this->getOrderRepository()->findForClientById((int) $clientId, (int) $id);

        return $order instanceof Order ? $this->getLegacyOrder($order) : null;
    }

    public function findEntityForClientById(ClientEntity $client, int $id): ?Order
    {
        return $this->getOrderRepository()->findForClientById((int) $client->getId(), $id);
    }

    public function findByClientIdAndOrderId(int $clientId, int $orderId): Order|\Model_ClientOrder|null
    {
        $order = $this->di['db']->findOne('ClientOrder', 'id = :id AND client_id = :client_id', [':id' => $orderId, ':client_id' => $clientId]);

        return $order instanceof \Model_ClientOrder ? $order : null;
    }

    public function getOrderServiceData(Order|\Model_ClientOrder $order, $identity = null)
    {
        $orderId = $this->orderId($order);
        $serviceType = $this->orderServiceType($order);
        $service = $this->getOrderService($order);
        if (!is_object($service)) {
            $this->di['logger']->info("Order #{$orderId} has no active service.");

            return null;
        }
        $srepo = $this->di['mod_service']('service' . $serviceType);
        if (!method_exists($srepo, 'toApiArray')) {
            $this->di['logger']->info("Service #{$serviceType} method toApiArray is missing.");

            return null;
        }

        return $srepo->toApiArray($service, true, $identity);
    }

    public function getTotal(Order|\Model_ClientOrder $model): float
    {
        $quantity = $model instanceof Order ? $model->getQuantity() : $model->quantity;

        return $this->calculateTotal($this->orderPrice($model) ?? 0, $quantity ?? 1);
    }

    private function calculateTotal($price, $quantity): float
    {
        return (float) $price * (int) $quantity;
    }

    public function setUnpaidInvoice(Order|\Model_ClientOrder $order, \Model_Invoice $proforma): void
    {
        if ($order instanceof Order) {
            $order->setUnpaidInvoiceId((int) $proforma->id);
            $order->setUpdatedAt(new \DateTime());
        } else {
            $order->unpaid_invoice_id = (int) $proforma->id;
            $order->updated_at = date('Y-m-d H:i:s');
            // ClientFence core patch (QA r6 M-08): only these columns; storing the whole order wrote back the expiry
            // read before another request renewed it, undoing that renewal.
            $this->di['dbal']->executeStatement('UPDATE client_order SET unpaid_invoice_id = ?, updated_at = ? WHERE id = ?', [$order->unpaid_invoice_id, $order->updated_at, $order->id]);

            return;
        }
        $this->persistOrder($order);
    }

    public function unsetUnpaidInvoice(Order|\Model_ClientOrder $order): void
    {
        if ($order instanceof Order) {
            $order->setUnpaidInvoiceId(null);
            $order->setUpdatedAt(new \DateTime());
        } else {
            $order->unpaid_invoice_id = null;
            $order->updated_at = date('Y-m-d H:i:s');
            // ClientFence core patch (QA r6 M-08): only these columns; storing the whole order wrote back the expiry
            // read before another request renewed it, undoing that renewal.
            $this->di['dbal']->executeStatement('UPDATE client_order SET unpaid_invoice_id = ?, updated_at = ? WHERE id = ?', [$order->unpaid_invoice_id, $order->updated_at, $order->id]);

            return;
        }
        $this->persistOrder($order);
    }

    public function getRelatedOrderIdByType(Order|\Model_ClientOrder $order, $type)
    {
        $groupId = $this->orderGroupId($order);

        if (!$order instanceof Order) {
            $legacyOrder = $this->di['db']->findOne('ClientOrder', 'group_id = :group_id AND service_type = :service_type', [
                ':group_id' => $groupId,
                ':service_type' => $type,
            ]);

            return $legacyOrder instanceof \Model_ClientOrder ? $legacyOrder->id : null;
        }

        $o = $this->getOrderRepository()->findOneBy(['groupId' => $groupId, 'serviceType' => $type]);

        if ($o instanceof Order) {
            return $o->getId();
        }

        return null;
    }

    public function rmByClient(ClientEntity|\Model_Client $client): void
    {
        $productService = $this->di['mod_service']('Product');
        $clientId = $client instanceof ClientEntity ? $client->getId() : $client->id;
        $orders = $client instanceof ClientEntity
            ? $this->getOrderRepository()->findByClientId((int) $clientId)
            : $this->di['db']->find('ClientOrder', 'client_id = ?', [(int) $clientId]);
        foreach ($orders as $order) {
            $productService->releaseReservedPromoRedemptionsForOrder($order, 'client_deleted');
        }

        $query = $this->di['dbal']->createQueryBuilder();
        $query
            ->delete('client_order')
            ->where('client_id = :id')
            ->setParameter('id', $clientId)
            ->executeStatement();
    }

    public function exportCSV(array $headers): Response
    {
        if ($headers) {
            $headers = array_values(array_intersect(self::EXPORTABLE_COLUMNS, $headers));
        }

        if (!$headers) {
            $headers = self::DEFAULT_EXPORT_COLUMNS;
        }

        return $this->di['csv_response_factory']->create('client_order', 'orders.csv', $headers);
    }

    private function getProductId(Product $product): int
    {
        return (int) $product->getId();
    }

    private function getProductFormId(Product $product): ?int
    {
        return $product->getFormId();
    }

    private function getProductTitle(Product $product): string
    {
        return (string) $product->getTitle();
    }

    private function getProductType(Product $product): string
    {
        return (string) $product->getType();
    }

    private function getProductUnit(Product $product): string
    {
        return $product->getUnit();
    }

    private function isAddonProduct(Product $product): bool
    {
        return $product->isAddon();
    }
}
