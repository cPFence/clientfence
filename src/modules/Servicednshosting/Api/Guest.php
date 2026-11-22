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
use ClientFence\InformationException;
use ClientFence\Mod\Product\Service as ProductService;
use ClientFence\Validation\Api\RequiredParams;

final class Guest extends AbstractApi
{
    #[RequiredParams(['product_id' => 'Product ID is required.'])]
    public function nameservers(array $data): array
    {
        $product = $this->di['mod_service']('product')->findOneActiveById((int) $data['product_id']);
        if ($product?->getType() !== ProductService::DNS) {
            throw new InformationException('DNS product not found.', null, 404);
        }

        return $this->getService()->getNameservers($product);
    }
}
