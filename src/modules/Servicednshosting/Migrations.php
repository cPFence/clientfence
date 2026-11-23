<?php

declare(strict_types=1);

namespace ClientFence\Mod\Servicednshosting;

/**
 * DNS hosting's database steps (Clientfence\ModuleMigrations, named in manifest.json). A new install creates the
 * table from install/sql/structure.sql and starts at the last step (install/sql/content.sql).
 */
final class Migrations
{
    public const TABLE = 'CREATE TABLE IF NOT EXISTS `service_dnshosting` (`id` bigint(20) NOT NULL AUTO_INCREMENT, `order_id` bigint(20) NOT NULL, `domain_name` varchar(253) NOT NULL, `config` text NOT NULL, `zone_id` varchar(253) DEFAULT NULL, `nameservers` json NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `dns_order` (`order_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    /** @return array<int, callable(\Pimple\Container): void> */
    public static function steps(): array
    {
        return [1 => self::table(...)];
    }

    private static function table(\Pimple\Container $di): void
    {
        $di['dbal']->executeStatement(self::TABLE);
    }
}
