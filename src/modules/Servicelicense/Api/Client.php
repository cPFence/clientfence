<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Servicelicense\Api;

use ClientFence\IpSeats;
use ClientFence\Mod\Servicelicense\Entity\ServiceLicense;

/**
 *License Service management.
 */
class Client extends \ClientFence\Api\AbstractApi
{
    /**
     * Reset license validation rules.
     *
     * ClientFence core patch: counts as one IP change and may need staff approval (IpSeats, CF-56).
     *
     * @return bool
     */
    public function reset($data)
    {
        $s = $this->_getService($data);

        return $this->getService()->ipAction($s, IpSeats::RESET);
    }

    /**
     * ClientFence: adds an IP address (order_id, ip) into a free place of a product with "Servers (IP
     * addresses)"; true when done or waiting for staff approval (order_service seats.pending).
     */
    public function ip_add($data): bool
    {
        return $this->getService()->ipAction($this->_getService($data), IpSeats::ADD, (string) ($data['ip'] ?? ''));
    }

    /** ClientFence: removes an IP address (order_id, ip): one IP change, free in its first 24 hours while no check has used it. */
    public function ip_remove($data): bool
    {
        return $this->getService()->ipAction($this->_getService($data), IpSeats::REMOVE, (string) ($data['ip'] ?? ''));
    }

    /** ClientFence: takes back the IP change request waiting for staff approval (order_id). */
    public function ip_request_withdraw($data): bool
    {
        return $this->getService()->ipAction($this->_getService($data), 'withdraw');
    }

    public function _getService(array $data): ServiceLicense
    {
        $required = ['order_id' => 'Order ID is required'];
        $this->getDi()['validator']->checkRequiredParamsForArray($required, $data);

        $client = $this->getIdentity();

        $order = $this->getDi()['em']->getRepository(\ClientFence\Mod\Order\Entity\Order::class)->findOneBy([
            'id' => $data['order_id'],
            'clientId' => $client->id,
        ]);

        if (!$order instanceof \ClientFence\Mod\Order\Entity\Order) {
            throw new \ClientFence\InformationException('Order not found');
        }

        $orderService = $this->getDi()['mod_service']('order');
        $orderService->assertOrderUsable($order);

        if ($order->getStatus() !== \ClientFence\Mod\Order\Entity\Order::STATUS_ACTIVE) {
            throw new \ClientFence\InformationException('Order is not activated');
        }

        $s = $orderService->getOrderService($order);
        if (!$s instanceof ServiceLicense) {
            throw new \ClientFence\Exception('Order is not activated');
        }

        return $s;
    }
}
