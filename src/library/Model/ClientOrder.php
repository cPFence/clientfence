<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */
class Model_ClientOrder extends RedBeanPHP\SimpleModel
{
    final public const string STATUS_PENDING_SETUP = ClientFence\Mod\Order\Entity\Order::STATUS_PENDING_SETUP;
    final public const string STATUS_FAILED_SETUP = ClientFence\Mod\Order\Entity\Order::STATUS_FAILED_SETUP;
    final public const string STATUS_FAILED_RENEW = ClientFence\Mod\Order\Entity\Order::STATUS_FAILED_RENEW;
    final public const string STATUS_ACTIVE = ClientFence\Mod\Order\Entity\Order::STATUS_ACTIVE;
    final public const string STATUS_CANCELED = ClientFence\Mod\Order\Entity\Order::STATUS_CANCELED;
    final public const string STATUS_SUSPENDED = ClientFence\Mod\Order\Entity\Order::STATUS_SUSPENDED;

    final public const string ACTION_CREATE = ClientFence\Mod\Order\Entity\Order::ACTION_CREATE;
    final public const string ACTION_ACTIVATE = ClientFence\Mod\Order\Entity\Order::ACTION_ACTIVATE;
    final public const string ACTION_RENEW = ClientFence\Mod\Order\Entity\Order::ACTION_RENEW;
    final public const string ACTION_SUSPEND = ClientFence\Mod\Order\Entity\Order::ACTION_SUSPEND;
    final public const string ACTION_UNSUSPEND = ClientFence\Mod\Order\Entity\Order::ACTION_UNSUSPEND;
    final public const string ACTION_CANCEL = ClientFence\Mod\Order\Entity\Order::ACTION_CANCEL;
    final public const string ACTION_UNCANCEL = ClientFence\Mod\Order\Entity\Order::ACTION_UNCANCEL;
    final public const string ACTION_DELETE = ClientFence\Mod\Order\Entity\Order::ACTION_DELETE;

    public static function getValidStatuses(): array
    {
        return ClientFence\Mod\Order\Entity\Order::getValidStatuses();
    }
}
