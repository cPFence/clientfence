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
        10 => 'm10SupportPinUnique',
        11 => 'm11GroupsAutoPromos',
        12 => 'm12SignInHistory',
        13 => 'm13OrderHolds',
        14 => 'm14Passkeys',
        15 => 'm15IdDocuments',
        16 => 'm16ApiLog',
        17 => 'm17BalanceAlert',
        18 => 'm18GatewayOrderPoNumber',
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

    /**
     * 10: ClientFence core patch: one client per Support PIN, so staff can find a client by it. Every client
     * but the first that shares a PIN gets a new unused one, then `pin` gets its unique key.
     */
    private static function m10SupportPinUnique(\Pimple\Container $di): void
    {
        $db = $di['dbal'];
        if (array_key_exists('pin', $db->createSchemaManager()->listTableIndexes('client_support_pin'))) {
            return;
        }
        $shared = $db->fetchFirstColumn('SELECT pin FROM client_support_pin GROUP BY pin HAVING COUNT(*) > 1');
        foreach ($shared as $pin) {
            $clients = $db->fetchFirstColumn('SELECT client_id FROM client_support_pin WHERE pin = ? ORDER BY client_id', [$pin]);
            foreach (array_slice($clients, 1) as $clientId) {
                \ClientFence\Mod\Client\SupportPin::renew($di, (int) $clientId);
            }
        }
        $db->executeStatement('CREATE UNIQUE INDEX `pin` ON `client_support_pin` (`pin`)');
    }

    /**
     * 11: ClientFence core patch: clients in several groups (Client/Groups.php): client_group_members, where each client's
     * group (client.client_group_id, kept as the first one) becomes their group list; promo.auto_apply (promotions
     * applied without a code).
     */
    private static function m11GroupsAutoPromos(\Pimple\Container $di): void
    {
        $db = $di['dbal'];
        $schema = $db->createSchemaManager();
        if (!$schema->tablesExist(['client_group_members'])) {
            $db->executeStatement(Service::tables()['client_group_members']);
        }
        // A group that was deleted is left out.
        $db->executeStatement('INSERT INTO client_group_members (client_id, client_group_id) SELECT c.id, c.client_group_id FROM client c JOIN client_group g ON g.id = c.client_group_id
            WHERE NOT EXISTS (SELECT 1 FROM client_group_members m WHERE m.client_id = c.id AND m.client_group_id = c.client_group_id)');
        if (!in_array('auto_apply', array_map(static fn ($c): string => strtolower($c->getName()), $schema->listTableColumns('promo')), true)) {
            $db->executeStatement("ALTER TABLE `promo` ADD COLUMN `auto_apply` tinyint(1) NOT NULL DEFAULT '0'");
        }
    }

    /**
     * 12: ClientFence core patch: sign-in details on FOSSBilling's login history (Clientfence/SignInHistory.php): country,
     * asn, network, browser and session_id on both tables, and the authorized user (user_id, with its index) on the
     * client one. One column at a time, so an interrupted run adds only what is missing.
     */
    private static function m12SignInHistory(\Pimple\Container $di): void
    {
        $db = $di['dbal'];
        $schema = $db->createSchemaManager();
        $details = ['country' => 'varchar(2)', 'asn' => 'bigint(20)', 'network' => 'varchar(128)', 'browser' => 'varchar(64)', 'session_id' => 'varchar(32)'];
        foreach (['activity_client_history' => ['user_id' => 'bigint(20)'] + $details, 'activity_admin_history' => $details] as $table => $columns) {
            $have = array_map(static fn ($c): string => strtolower($c->getName()), $schema->listTableColumns($table));
            foreach (array_diff_key($columns, array_flip($have)) as $name => $type) {
                $db->executeStatement("ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$type} DEFAULT NULL");
            }
        }
        foreach (['activity_client_history' => ['user_id_idx' => 'user_id', 'client_signin_session_idx' => 'session_id'], 'activity_admin_history' => ['admin_signin_session_idx' => 'session_id']] as $table => $indexes) {
            $have = $schema->listTableIndexes($table);
            foreach (array_diff_key($indexes, $have) as $index => $column) {
                $db->executeStatement("CREATE INDEX `{$index}` ON `{$table}` (`{$column}`)");
            }
        }
    }

    /** 13: ClientFence core patch: client_order_hold, orders held for review (Order/Holds.php). */
    private static function m13OrderHolds(\Pimple\Container $di): void
    {
        if (!$di['dbal']->createSchemaManager()->tablesExist(['client_order_hold'])) {
            $di['dbal']->executeStatement(Service::tables()['client_order_hold']);
        }
    }

    /** 14: client_passkey, the passkeys of client, user and staff logins (Client/Passkeys.php). */
    private static function m14Passkeys(\Pimple\Container $di): void
    {
        if (!$di['dbal']->createSchemaManager()->tablesExist(['client_passkey'])) {
            $di['dbal']->executeStatement(Service::tables()['client_passkey']);
        }
    }

    /** 15: ClientFence core patch: client_id_document, the ID documents of clients (Client/IdDocuments.php). */
    private static function m15IdDocuments(\Pimple\Container $di): void
    {
        if (!$di['dbal']->createSchemaManager()->tablesExist(['client_id_document'])) {
            $di['dbal']->executeStatement(Service::tables()['client_id_document']);
        }
    }

    /** 16: ClientFence core patch: activity_api, the API request log (Activity/ApiLog.php). */
    private static function m16ApiLog(\Pimple\Container $di): void
    {
        if (!$di['dbal']->createSchemaManager()->tablesExist(['activity_api'])) {
            $di['dbal']->executeStatement(Service::tables()['activity_api']);
        }
    }

    /** 17: ClientFence core patch: client_balance_alert, the clients' low balance alerts (Client/BalanceAlert.php). */
    private static function m17BalanceAlert(\Pimple\Container $di): void
    {
        if (!$di['dbal']->createSchemaManager()->tablesExist(['client_balance_alert'])) {
            $di['dbal']->executeStatement(Service::tables()['client_balance_alert']);
        }
    }

    /**
     * 18: ClientFence core patch: pay_gateway.position, the order of the payment methods (GitHub #43); client.po_number and
     * invoice.po_number, the purchase order number (GitHub #31). Safe to run again: each column is added only once.
     */
    private static function m18GatewayOrderPoNumber(\Pimple\Container $di): void
    {
        $db = $di['dbal'];
        $columns = static fn (string $table): array => array_map(static fn ($c): string => strtolower($c->getName()), $db->createSchemaManager()->listTableColumns($table));
        if (!in_array('position', $columns('pay_gateway'), true)) {
            $db->executeStatement("ALTER TABLE `pay_gateway` ADD COLUMN `position` int(11) NOT NULL DEFAULT '0'");
            // Existing gateways keep the order clients saw before: online methods first, then manual ones, each by id.
            $gateways = $di['mod_service']('Invoice', 'PayGateway');
            $order = [];
            foreach ($db->fetchAllKeyValue('SELECT id, gateway FROM pay_gateway ORDER BY id') as $id => $code) {
                $model = $di['db']->dispense('PayGateway');
                $model->gateway = (string) $code;
                $order[] = [is_a($gateways->getAdapterClassName($model), \Payment_Adapter_Custom::class, true), (int) $id];
            }
            sort($order);
            foreach ($order as $i => [, $id]) {
                $db->update('pay_gateway', ['position' => $i + 1], ['id' => $id]);
            }
        }
        foreach (['client', 'invoice'] as $table) {
            if (!in_array('po_number', $columns($table), true)) {
                $db->executeStatement("ALTER TABLE `{$table}` ADD COLUMN `po_number` varchar(64) DEFAULT NULL");
            }
        }
    }
}
