<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence;

/**
 * ClientFence's own database migrations, numbered as FOSSBilling's core patches are
 * (\ClientFence\UpdatePatcher) but tracked apart in the setting `clientfence_patch`.
 *
 * They run after the core patches wherever those run (UpdatePatcher::applyCorePatches(): the
 * admin's "Finalize update" screen after new files are uploaded, and `console.php
 * system:run-patcher`). A fresh install starts at the latest number (install/sql/content.sql).
 *
 * A schema change to a ClientFence table, or any other one-time data change, goes here as the
 * next number: a migration is never edited or removed once released, and must be safe to run on
 * an install that already has its result (it runs once, but an interrupted run is repeated).
 * New tables also go in schema.json and install/sql/structure.sql; migration 1 creates every
 * missing one.
 */
final class Migrations
{
    public const SETTING = 'clientfence_patch';

    /** Number => method. */
    private const LIST = [
        1 => 'm1Tables',
        2 => 'm2UnverifiedSenders',
        3 => 'm3Privacy',
        4 => 'm4ClientRecords',
        5 => 'm5TicketSecrets',
        6 => 'm6SupportPin',
        7 => 'm7TldSync',
        8 => 'm8AccountingSync',
        9 => 'm9Affiliates',
    ];

    public static function latest(): int
    {
        return (int) array_key_last(self::LIST);
    }

    /** The database's level: 0 when no ClientFence migration ever ran. */
    public static function current(\PDO $pdo): int
    {
        $statement = $pdo->prepare('SELECT value FROM setting WHERE param = :param');
        $statement->execute(['param' => self::SETTING]);
        $value = $statement->fetchColumn();

        return $value === false ? 0 : (int) $value;
    }

    public static function pending(\PDO $pdo): int
    {
        $current = self::current($pdo);

        return count(array_filter(array_keys(self::LIST), static fn (int $n): bool => $n > $current));
    }

    /**
     * Runs the pending migrations in order, recording the level after each. Returns the numbers
     * that ran.
     *
     * @return list<int>
     */
    public static function apply(\Pimple\Container $di): array
    {
        $steps = [];
        foreach (self::LIST as $number => $method) {
            $steps[$number] = static fn () => call_user_func([self::class, $method], $di);
        }

        return self::run($di['pdo'], $steps);
    }

    /**
     * The runner behind apply(), with the steps given (tests pass their own).
     *
     * @param array<int, callable(): void> $steps
     *
     * @return list<int>
     */
    public static function run(\PDO $pdo, array $steps): array
    {
        ksort($steps);
        $current = self::current($pdo);
        $ran = [];
        foreach ($steps as $number => $step) {
            if ($number <= $current) {
                continue;
            }
            $step();
            self::setLevel($pdo, $number);
            $ran[] = $number;
        }

        return $ran;
    }

    private static function setLevel(\PDO $pdo, int $level): void
    {
        $now = date('Y-m-d H:i:s');
        $update = $pdo->prepare('UPDATE setting SET value = :value, updated_at = :now WHERE param = :param');
        $update->execute(['value' => (string) $level, 'now' => $now, 'param' => self::SETTING]);
        if ($update->rowCount() === 0 && self::current($pdo) !== $level) {
            $pdo->prepare('INSERT INTO setting (param, value, public, created_at, updated_at) VALUES (:param, :value, 0, :now, :now)')
                ->execute(['param' => self::SETTING, 'value' => (string) $level, 'now' => $now]);
        }
    }

    /** 1: every ClientFence table that is missing (installs made before migrations existed). */
    private static function m1Tables(\Pimple\Container $di): void
    {
        Service::ensureTables($di, true);
    }

    /** 2: support_ticket_message_unverified (emails whose sender DKIM could not verify). */
    private static function m2UnverifiedSenders(\Pimple\Container $di): void
    {
        Service::ensureTables($di, true);
    }

    /** 3: client_marketing_optout and email_queue_unsubscribe (Client/Privacy.php). */
    private static function m3Privacy(\Pimple\Container $di): void
    {
        Service::ensureTables($di, true);
    }

    private static function m4ClientRecords(\Pimple\Container $di): void
    {
        Service::ensureTables($di, true);
    }

    // ClientFence core patch: existing installs create the ticket secret tables before use.
    private static function m5TicketSecrets(\Pimple\Container $di): void
    {
        Service::ensureTables($di, true);
    }

    // ClientFence core patch: existing installs create the Support PIN table before use.
    private static function m6SupportPin(\Pimple\Container $di): void
    {
        Service::ensureTables($di, true);
    }

    /**
     * 7: tld_registrar_cost, tld.price_locked ("Keep my price") and tld_registrar.price_sync (Servicedomain/PriceSync.php);
     * service_domain.sync_error and transfer_since (Servicedomain/DomainSync.php).
     */
    private static function m7TldSync(\Pimple\Container $di): void
    {
        Service::ensureTables($di, true);
        $db = $di['em']->getConnection();
        $columns = static fn (string $table): array => array_map(static fn ($c): string => strtolower($c->getName()), $db->createSchemaManager()->listTableColumns($table));
        if (!in_array('price_locked', $columns('tld'), true)) {
            $db->executeStatement("ALTER TABLE `tld` ADD COLUMN `price_locked` tinyint(1) NOT NULL DEFAULT '0'");
        }
        if (!in_array('price_sync', $columns('tld_registrar'), true)) {
            $db->executeStatement('ALTER TABLE `tld_registrar` ADD COLUMN `price_sync` text');
        }
        if (!in_array('sync_error', $columns('service_domain'), true)) {
            $db->executeStatement('ALTER TABLE `service_domain` ADD COLUMN `sync_error` text, ADD COLUMN `transfer_since` datetime DEFAULT NULL');
        }
    }

    /** 8: invoice_accounting_sync (Invoice/Accounting/Sync.php). */
    private static function m8AccountingSync(\Pimple\Container $di): void
    {
        Service::ensureTables($di, true);
    }

    /** 9: the client_affiliate* tables (Client/Affiliates). */
    private static function m9Affiliates(\Pimple\Container $di): void
    {
        Service::ensureTables($di, true);
    }
}
