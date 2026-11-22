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

final class Client extends AbstractApi
{
    #[RequiredParams(['order_id' => 'Order ID is required.'])]
    public function zone(array $data): array
    {
        return $this->getService()->getZone($data, $this->clientId());
    }

    #[RequiredParams(['order_id' => 'Order ID is required.'])]
    public function add(array $data): bool
    {
        return $this->getService()->changeRecord('add', $data, $this->clientId());
    }

    #[RequiredParams(['order_id' => 'Order ID is required.', 'record_id' => 'Record ID is required.'])]
    public function update(array $data): bool
    {
        return $this->getService()->changeRecord('update', $data, $this->clientId());
    }

    #[RequiredParams(['order_id' => 'Order ID is required.', 'record_id' => 'Record ID is required.'])]
    public function del(array $data): bool
    {
        return $this->getService()->changeRecord('del', $data, $this->clientId());
    }

    #[RequiredParams(['order_id' => 'Order ID is required.'])]
    public function dnssec(array $data): array
    {
        return $this->getService()->getDnssec($data, $this->clientId());
    }

    #[RequiredParams(['order_id' => 'Order ID is required.'])]
    public function dnssec_enable(array $data): bool
    {
        return $this->getService()->setDnssec($data, $this->clientId(), true);
    }

    #[RequiredParams(['order_id' => 'Order ID is required.'])]
    public function dnssec_disable(array $data): bool
    {
        return $this->getService()->setDnssec($data, $this->clientId(), false);
    }

    private function clientId(): int
    {
        return (int) $this->getIdentity()->id;
    }
}
