<?php

declare(strict_types=1);
/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;

// ClientFence core patch (0.8.9 port): SQLite omits the row-lock suffix used inside credential transactions.
final class RowLock
{
    public static function suffix(Connection $connection): string
    {
        return $connection->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';
    }
}
