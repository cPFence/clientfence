<?php

declare(strict_types=1);

/*
 * Copyright (c) 2023-2026 Taras Kondratyuk
 * Copyright (c) 2025-2026 Namingo contributors
 * Copyright (c) 2026 Terbora Ltd.
 *
 * Licensed under the Apache License, Version 2.0.
 * See the LICENSE file for the full license text.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace ClientFence\Mod\Servicednshosting\Api;

use ClientFence\Api\AbstractApi;
use ClientFence\Validation\Api\RequiredParams;

final class Admin extends AbstractApi
{
    #[RequiredParams(['order_id' => 'Order ID is required.'])]
    public function zone(array $data): array
    {
        $this->checkPermissions('order', 'view');
        $this->checkPermissions('servicednshosting', 'view');

        return $this->getService()->getZone($data, null);
    }
}
