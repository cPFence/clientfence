<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Servicedownloadable\Api;

use ClientFence\Validation\Api\RequiredParams;
use Symfony\Component\HttpFoundation\Response;

/**
 * Downloadable service management.
 */
class Client extends \ClientFence\Api\AbstractApi
{
    /**
     * Use GET to call this method. Sends file attached to order.
     * Sends file as attachment.
     */
    #[RequiredParams(['order_id' => 'Order ID is required'])]
    public function send_file($data): Response
    {
        if (empty($data['order_id'])) {
            throw new \ClientFence\Exception('Order ID is required');
        }

        $identity = $this->getIdentity();
        $order = $this->getDi()['db']->findOne('ClientOrder', 'id = :id AND client_id = :client_id', [':id' => $data['order_id'], ':client_id' => $identity->id]);
        if (!$order instanceof \Model_ClientOrder) {
            // ClientFence core patch: 404, the theme's not-found page (another client's link showed the developer error page).
            throw new \ClientFence\InformationException('Order not found', null, 404);
        }

        $orderService = $this->getDi()['mod_service']('order');
        $orderService->assertOrderUsable($order);
        $s = $orderService->getOrderService($order);
        if (!$s instanceof \Model_ServiceDownloadable || $order->status !== 'active') {
            throw new \ClientFence\Exception('Order is not activated');
        }

        $service = $this->getService();

        return $service->sendFile($s);
    }
}
