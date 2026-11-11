<?php

declare(strict_types=1);

/**
 * ClientFence: the hosting control panels the Servicehosting module works with, each an optional
 * feature in its settings (Hosting Plans and Servers page). A panel is a FOSSBilling server manager that ClientFence ships (in
 * library/Server/Manager/) and declares here with the features its client pages offer. The
 * client and admin pages ask this class, never a panel directly: with a panel switched off (or
 * the module off) its features are gone and its server manager refuses to act.
 */

namespace ClientFence\Mod\Servicehosting\Panel;

use ClientFence\InformationException;

final class Panels
{
    /**
     * id => label, server manager (file name in library/Server/Manager/), its class, the module
     * setting that switches it on, the features it offers and its options (module settings, off
     * by default, that only count while the panel is on).
     */
    public const PANELS = [
        'enhance' => [
            'label' => 'Enhance',
            'manager' => 'Enhance',
            'class' => 'Server_Manager_Enhance',
            'setting' => 'panel_enhance',
            'features' => ['usage', 'server', 'email', 'dns', 'backups'],
            'options' => ['enhance_global_webmail'],
        ],
    ];

    public const HOSTING_STATUSES = ['active', 'suspended'];

    /**
     * The panels' setting keys (each panel's switch and its options): Extension\Service::setConfig()
     * keeps them when another form saves mod_servicehosting.
     *
     * @return list<string>
     */
    public static function settingKeys(): array
    {
        $keys = [];
        foreach (self::PANELS as $panel) {
            $keys = [...$keys, $panel['setting'], ...$panel['options']];
        }

        return $keys;
    }

    /**
     * The panels' settings (Hosting Plans and Servers page), kept in mod_servicehosting.
     *
     * @return array<string, mixed>
     */
    private static function settings(\Pimple\Container $di): array
    {
        return $di['mod_config']('servicehosting');
    }

    public static function on(\Pimple\Container $di, string $panel): bool
    {
        $setting = self::PANELS[$panel]['setting'] ?? null;

        return $setting !== null && (string) (self::settings($di)[$setting] ?? '0') === '1';
    }

    /**
     * Whether one of a panel's options is on (and the panel with it).
     */
    public static function option(\Pimple\Container $di, string $panel, string $setting): bool
    {
        return in_array($setting, self::PANELS[$panel]['options'] ?? [], true) && self::on($di, $panel) && (string) (self::settings($di)[$setting] ?? '0') === '1';
    }

    public static function anyOn(\Pimple\Container $di): bool
    {
        foreach (array_keys(self::PANELS) as $panel) {
            if (self::on($di, $panel)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The panel a server manager belongs to, or null when it is not one of ours.
     */
    public static function panelOf(object $manager): ?string
    {
        foreach (self::PANELS as $id => $panel) {
            if ($manager instanceof PanelManager && $manager instanceof $panel['class']) {
                return $id;
            }
        }

        return null;
    }

    public static function supports(string $panel, string $feature): bool
    {
        return in_array($feature, self::PANELS[$panel]['features'] ?? [], true);
    }

    /**
     * One of the client's hosting orders with its server manager and account, for a panel feature.
     * The order ID comes from the browser, so it is looked up for that client only; the panel must
     * be on and offer the feature.
     *
     * @return array{order: array, panel: string, manager: PanelManager, account: \Server_Account}
     */
    public static function forClientOrder(\Pimple\Container $di, int $orderId, int $clientId, string $feature): array
    {
        $order = $di['em']->getConnection()->fetchAssociative(
            'SELECT id, client_id, product_id, status, service_id, service_type, title FROM client_order WHERE id = ? AND client_id = ? AND service_type = ?',
            [$orderId, $clientId, 'hosting']
        );
        if ($order === false || !in_array($order['status'], self::HOSTING_STATUSES, true) || empty($order['service_id'])) {
            throw new InformationException('Hosting account not found');
        }

        return ['order' => $order] + self::forService($di, (int) $order['service_id'], $feature);
    }

    /**
     * The server manager and account of a hosting service, for a panel feature.
     *
     * @return array{panel: string, manager: PanelManager, account: \Server_Account}
     */
    public static function forService(\Pimple\Container $di, int $serviceId, string $feature): array
    {
        $model = $di['db']->load('ServiceHosting', $serviceId);
        if (!$model instanceof \Model_ServiceHosting || empty($model->id)) {
            throw new InformationException('Hosting account not found');
        }
        [$manager, $account] = $di['mod_service']('servicehosting')->_getAM($model);
        $panel = self::panelOf($manager);
        if ($panel === null || !$manager instanceof PanelManager || !self::on($di, $panel) || !self::supports($panel, $feature)) {
            throw new InformationException('This hosting account does not offer this feature.');
        }

        return ['panel' => $panel, 'manager' => $manager, 'account' => $account];
    }

    /**
     * The client's active and suspended hosting orders (no panel calls): the order title, the
     * domain, the hosting plan's name, when the order renews (null: never) and whether the plan allows
     * email accounts (its email accounts limit is not 0).
     *
     * @return list<array{order_id: int, title: string, domain: string, plan: string, status: string, expires_at: ?string, email: bool}>
     */
    public static function clientAccounts(\Pimple\Container $di, int $clientId): array
    {
        $rows = $di['em']->getConnection()->fetchAllAssociative(
            "SELECT o.id, o.title, o.status, o.expires_at, CONCAT(COALESCE(h.sld, ''), COALESCE(h.tld, '')) AS domain, COALESCE(hp.name, '') AS plan, hp.max_pop
             FROM client_order o LEFT JOIN service_hosting h ON h.id = o.service_id
             LEFT JOIN service_hosting_hp hp ON hp.id = h.service_hosting_hp_id
             WHERE o.client_id = ? AND o.service_type = 'hosting' AND o.status IN ('active', 'suspended')
             ORDER BY o.id",
            [$clientId]
        );

        return array_map(static fn (array $r): array => ['order_id' => (int) $r['id'], 'title' => (string) $r['title'], 'domain' => (string) $r['domain'],
            'plan' => (string) $r['plan'], 'status' => (string) $r['status'], 'expires_at' => $r['expires_at'] !== null ? (string) $r['expires_at'] : null,
            'email' => (string) $r['max_pop'] !== '0'], $rows);
    }

    /**
     * The servers of a panel (id, name, hostname), for the admin pages.
     *
     * @return list<array{id: int, name: string, hostname: string}>
     */
    public static function servers(\Pimple\Container $di, string $panel): array
    {
        $manager = self::PANELS[$panel]['manager'] ?? null;
        if ($manager === null) {
            return [];
        }
        $rows = $di['em']->getConnection()->fetchAllAssociative('SELECT id, name, hostname FROM service_hosting_server WHERE manager = ? ORDER BY name', [$manager]);

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'hostname' => (string) $r['hostname']], $rows);
    }

    /**
     * The server manager of one of a panel's servers.
     */
    public static function serverManager(\Pimple\Container $di, string $panel, int $serverId): PanelManager
    {
        $server = $di['db']->load('ServiceHostingServer', $serverId);
        if (!$server instanceof \Model_ServiceHostingServer || empty($server->id) || $server->manager !== (self::PANELS[$panel]['manager'] ?? '')) {
            throw new InformationException('Server not found');
        }
        $manager = $di['mod_service']('servicehosting')->getServerManager($server);
        if (!$manager instanceof PanelManager) {
            throw new InformationException('Server not found');
        }

        return $manager;
    }
}
