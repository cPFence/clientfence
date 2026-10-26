<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Servicehosting\Api;

use ClientFence\Mod\Servicehosting\Panel\Panels;
use ClientFence\Mod\Servicehosting\Panel\Plans;
use ClientFence\PaginationOptions;
use ClientFence\Tools;
use ClientFence\Validation\Api\RequiredParams;

/**
 * Hosting service management.
 */
class Admin extends \ClientFence\Api\AbstractApi
{
    /**
     * Change hosting account plan.
     */
    #[RequiredParams(['plan_id' => 'plan_id is missing'])]
    public function change_plan($data): bool
    {
        $this->checkPermissions('servicehosting', 'manage_accounts');

        [$order, $s] = $this->_getService($data);
        $plan = $this->getDi()['db']->getExistingModelById('ServiceHostingHp', $data['plan_id'], 'Hosting plan not found');

        $service = $this->getService();

        return (bool) $service->changeAccountPlan($order, $s, $plan);
    }

    /**
     * Change hosting account username.
     */
    public function change_username($data): bool
    {
        $this->checkPermissions('servicehosting', 'manage_accounts');

        [$order, $s] = $this->_getService($data);
        $service = $this->getService();

        return (bool) $service->changeAccountUsername($order, $s, $data);
    }

    /**
     * Change hosting account ip.
     */
    public function change_ip($data): bool
    {
        $this->checkPermissions('servicehosting', 'manage_accounts');

        [$order, $s] = $this->_getService($data);
        $service = $this->getService();

        return (bool) $service->changeAccountIp($order, $s, $data);
    }

    /**
     * Change hosting account domain.
     */
    public function change_domain($data): bool
    {
        $this->checkPermissions('servicehosting', 'manage_accounts');

        [$order, $s] = $this->_getService($data);
        $service = $this->getService();

        return (bool) $service->changeAccountDomain($order, $s, $data);
    }

    /**
     * Change hosting account password.
     */
    public function change_password($data): bool
    {
        $this->checkPermissions('servicehosting', 'manage_accounts');

        [$order, $s] = $this->_getService($data);
        $service = $this->getService();

        return (bool) $service->changeAccountPassword($order, $s, $data);
    }

    /**
     * Synchronize account with server values.
     */
    public function sync($data): bool
    {
        $this->checkPermissions('servicehosting', 'manage_accounts');

        [$order, $s] = $this->_getService($data);
        $service = $this->getService();

        return (bool) $service->sync($order, $s);
    }

    /**
     * Update account information on FOSSBilling database.
     * This does not send actions to real account on hosting server.
     *
     * @optional string $username - Hosting account username
     * @optional string $ip - Hosting account ip
     */
    public function update($data): bool
    {
        $this->checkPermissions('servicehosting', 'manage_accounts');

        [, $s] = $this->_getService($data);
        $service = $this->getService();

        return (bool) $service->update($s, $data);
    }

    /**
     * Get list of available server managers on system.
     *
     * @return array
     */
    public function manager_get_pairs($data)
    {
        $this->checkPermissions('servicehosting', 'view_servers');

        return $this->getService()->getServerManagers();
    }

    /**
     * Get list of available hosting servers on system.
     *
     * @return array
     */
    public function server_get_pairs($data)
    {
        $this->checkPermissions('servicehosting', 'view_servers');

        return $this->getService()->getServerPairs();
    }

    /**
     * Get a paginated list of servers.
     *
     * @return array
     */
    public function server_get_list($data)
    {
        $this->checkPermissions('servicehosting', 'view_servers');
        [$sql, $params] = $this->getService()->getServersSearchQuery($data);
        $result = $this->getDi()['pager']->getPaginatedResultSet($sql, $params, PaginationOptions::fromArray($data));

        foreach ($result['list'] as $key => $server) {
            $bean = $this->getDi()['db']->dispense('ServiceHostingServer')->unbox();
            $bean->import($server);
            $model = $bean->box();

            $result['list'][$key] = $this->getService()->toHostingServerApiArray($model, false, $this->getIdentity());
        }

        return $result;
    }

    /**
     * Get a paginated list of hosting accounts, along with the "order" and "client" information.
     *
     * @param $data array Accepts the optional "server_id" property
     *
     * @return array
     */
    public function account_get_list($data)
    {
        $this->checkPermissions('servicehosting', 'view_servers');
        [$sql, $params] = $this->getService()->getAccountsSearchQuery($data);
        $result = $this->getDi()['pager']->getPaginatedResultSet($sql, $params, PaginationOptions::fromArray($data));
        $result['list'] = $this->getService()->getAccountsBatchForApi($result['list'], $this->getIdentity());

        return $result;
    }

    /**
     * Create new hosting server.
     *
     * @optional string $hostname - server hostname
     * @optional string $ns1 - default nameserver 1
     * @optional string $ns2 - default nameserver 2
     * @optional string $ns3 - default nameserver 3
     * @optional string $ns4 - default nameserver 4
     * @optional string $username - server API login username
     * @optional string $password - server API login password
     * @optional string $accesshash - server API login access hash
     * @optional string $port - server API port
     * @optional string $passwordLength - password length for generated accounts
     * @optional bool $secure - flag to define whether to use secure connection (https) to server or not (http)
     * @optional bool $tls_verify - flag to define whether to verify TLS certificates when calling server APIs
     * @optional bool $active - flag to enable/disable server
     *
     * @return int - server id
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams([
        'name' => 'Server name was not passed',
        'ip' => 'Server IP was not passed',
        'manager' => 'Server manager was not specified',
    ])]
    public function server_create($data): int
    {
        $this->checkPermissions('servicehosting', 'manage_servers');

        $service = $this->getService();

        $data['config'] = [
            'userprefix' => $data['userprefix'] ?? null,
            'tls_verify' => Tools::normalizeBoolean($data['tls_verify'] ?? true, true),
        ];

        return (int) $service->createServer($data['name'], $data['ip'], $data['manager'], $data);
    }

    /**
     * Get server details.
     *
     * @return array
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Server ID was not passed'])]
    public function server_get($data)
    {
        $this->checkPermissions('servicehosting', 'view_servers');

        $model = $this->getDi()['db']->getExistingModelById('ServiceHostingServer', $data['id'], 'Server not found');
        $service = $this->getService();

        return $service->toHostingServerApiArray($model, true, $this->getIdentity());
    }

    /**
     * Delete server.
     *
     * @optional bool $force - detach orphaned usages with no linked order and delete
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Server ID was not passed'])]
    public function server_delete($data): bool
    {
        $this->checkPermissions('servicehosting', 'manage_servers');

        $model = $this->getDi()['db']->getExistingModelById('ServiceHostingServer', $data['id'], 'Server not found');

        $stats = $this->getService()->getServerUsageStats($model);
        if ($stats['active'] > 0) {
            throw new \ClientFence\InformationException('Hosting server is used by :count: service hostings', [':count:' => $stats['active']], 704);
        }

        if ($stats['orphaned'] > 0) {
            $force = Tools::normalizeBoolean($data['force'] ?? false);
            if (!$force) {
                throw new \ClientFence\InformationException('Hosting server is used by :count: orphaned service hostings with no linked order. Re-run with force=true to detach them and delete the server.', [':count:' => $stats['orphaned']], 704);
            }

            $this->getService()->detachOrphanedServerUsages($model);
        }

        return (bool) $this->getService()->deleteServer($model);
    }

    /**
     * Update server configuration.
     *
     * @optional string $hostname - server hostname
     * @optional string $ns1 - default nameserver 1
     * @optional string $ns2 - default nameserver 2
     * @optional string $ns3 - default nameserver 3
     * @optional string $ns4 - default nameserver 4
     * @optional string $username - server API login username
     * @optional string $password - server API login password
     * @optional string $accesshash - server API login access hash
     * @optional string $userprefix - prefix for created user
     * @optional string $port - server API port
     * @optional string $passwordLength - password length for generated accounts
     * @optional bool $secure - flag to define whether to use secure connection (https) to server or not (http)
     * @optional bool $tls_verify - flag to define whether to verify TLS certificates when calling server APIs
     * @optional bool $active - flag to enable/disable server
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Server ID was not passed'])]
    public function server_update($data): bool
    {
        $this->checkPermissions('servicehosting', 'manage_servers');

        $model = $this->getDi()['db']->getExistingModelById('ServiceHostingServer', $data['id'], 'Server not found');
        $service = $this->getService();

        $existingConfig = json_decode($model->config ?? '', true) ?? [];

        $data['config'] = $existingConfig;
        $data['config']['userprefix'] = $data['userprefix'] ?? ($existingConfig['userprefix'] ?? null);
        $data['config']['tls_verify'] = Tools::normalizeBoolean($data['tls_verify'] ?? ($existingConfig['tls_verify'] ?? true), true);

        $updated = (bool) $service->updateServer($model, $data);

        if ($updated) {
            $this->validateServerConfig($model);
        }

        return $updated;
    }

    private function validateServerConfig(\Model_ServiceHostingServer $model): void
    {
        try {
            $this->getService()->getServerManager($model);
        } catch (\Server_Exception|\ClientFence\Exception $e) {
            throw new \ClientFence\InformationException($e->getMessage(), [], $e->getCode() ?: 719);
        }
    }

    /**
     * Test connection to server.
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Server ID was not passed'])]
    public function server_test_connection($data): bool
    {
        $this->checkPermissions('servicehosting', 'manage_servers');

        $model = $this->getDi()['db']->getExistingModelById('ServiceHostingServer', $data['id'], 'Server not found');

        return (bool) $this->getService()->testConnection($model);
    }

    /**
     * Get hosting plan pairs.
     *
     * @return array
     */
    public function hp_get_pairs($data)
    {
        $this->checkPermissions('servicehosting', 'manage_plans');

        return $this->getService()->getHpPairs();
    }

    /**
     * Get hosting plans paginated list.
     *
     * @return array
     */
    public function hp_get_list($data)
    {
        $this->checkPermissions('servicehosting', 'manage_plans');
        [$sql, $params] = $this->getService()->getHpSearchQuery($data);
        $pager = $this->getDi()['pager']->getPaginatedResultSet($sql, $params, PaginationOptions::fromArray($data));

        foreach ($pager['list'] as $key => $item) {
            $model = $this->getDi()['db']->getExistingModelById('ServiceHostingHp', $item['id'], 'Hosting plan not found');
            $pager['list'][$key] = $this->getService()->toHostingHpApiArray($model, false, $this->getIdentity());
        }

        return $pager;
    }

    /**
     * Delete hosting plan.
     *
     * @optional bool $force - detach orphaned usages with no linked order and delete
     *
     * @throws \ClientFence\InformationException
     */
    #[RequiredParams(['id' => 'Hosting plan ID was not passed'])]
    public function hp_delete($data): bool
    {
        $this->checkPermissions('servicehosting', 'manage_plans');

        $model = $this->getDi()['db']->getExistingModelById('ServiceHostingHp', $data['id'], 'Hosting plan not found');

        $stats = $this->getService()->getHpUsageStats($model);
        if ($stats['active'] > 0) {
            throw new \ClientFence\InformationException('Hosting plan is used by :count: service hostings', [':count:' => $stats['active']], 704);
        }

        if ($stats['orphaned'] > 0) {
            $force = Tools::normalizeBoolean($data['force'] ?? false);
            if (!$force) {
                throw new \ClientFence\InformationException('Hosting plan is used by :count: orphaned service hostings with no linked order. Re-run with force=true to detach them and delete the plan.', [':count:' => $stats['orphaned']], 704);
            }

            $this->getService()->detachOrphanedHpUsages($model);
        }

        return (bool) $this->getService()->deleteHp($model);
    }

    /**
     * Get hosting plan details.
     *
     * @return array
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Hosting plan ID was not passed'])]
    public function hp_get($data)
    {
        $this->checkPermissions('servicehosting', 'manage_plans');

        $model = $this->getDi()['db']->getExistingModelById('ServiceHostingHp', $data['id'], 'Hosting plan not found');

        return $this->getService()->toHostingHpApiArray($model, true, $this->getIdentity());
    }

    /**
     * Update hosting plan details.
     *
     * @optional string $name - hosting plan name. Used as identifier on server
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Hosting plan ID was not passed'])]
    public function hp_update($data): bool
    {
        $this->checkPermissions('servicehosting', 'manage_plans');

        $model = $this->getDi()['db']->getExistingModelById('ServiceHostingHp', $data['id'], 'Hosting plan not found');

        $service = $this->getService();

        return (bool) $service->updateHp($model, $data);
    }

    /**
     * Update hosting plan details.
     *
     * @return int - new hosting plan id
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['name' => 'Hosting plan name was not passed'])]
    public function hp_create($data): int
    {
        $this->checkPermissions('servicehosting', 'manage_plans');

        $service = $this->getService();

        return (int) $service->createHp($data['name'], $data);
    }

    public function _getService($data): array
    {
        $required = [
            'order_id' => 'Order ID name is missing',
        ];
        $this->getDi()['validator']->checkRequiredParamsForArray($required, $data);

        $order = $this->getDi()['db']->getExistingModelById('ClientOrder', $data['order_id'], 'Order not found');
        $orderService = $this->getDi()['mod_service']('order');
        $s = $orderService->getOrderService($order);
        if (!$s instanceof \Model_ServiceHosting) {
            throw new \ClientFence\Exception('Order is not activated');
        }

        return [$order, $s];
    }

    /**
     * ClientFence: switches the hosting panels on or off (`panel_<id>` = 1 or 0) and their options
     * (each 1 or 0, e.g. `enhance_global_webmail`), on the Hosting Plans and Servers page
     * (Panel/Panels.php). Kept in mod_servicehosting.
     */
    public function panels_update($data): bool
    {
        $di = $this->getDi();
        $extension = $di['mod_service']('extension');
        $extension->hasManagePermission('mod_servicehosting');

        $config = $di['mod_config']('servicehosting');
        $changes = [];
        foreach (Panels::PANELS as $panel) {
            foreach (array_merge([$panel['setting']], $panel['options']) as $setting) {
                $on = in_array((string) ($data[$setting] ?? '0'), ['1', 'on', 'true'], true) ? '1' : '0';
                $changes[$setting] = $on;
                if ((string) ($config[$setting] ?? '0') !== $on) {
                    $di['logger']->info('Servicehosting: %s %s switched %s', $panel['label'], $setting === $panel['setting'] ? 'hosting panel' : $setting, $on === '1' ? 'on' : 'off');
                }
            }
        }
        $extension->setConfig(array_merge($extension->getConfig('mod_servicehosting'), $changes, ['ext' => 'mod_servicehosting']));

        return true;
    }

    /**
     * ClientFence: the hosting panels with their state and servers, for the Hosting Plans and
     * Servers page.
     *
     * `webmail_check`: the page reads the panel's global webmail domain afterwards
     * (panel_webmail_domain), so the page never waits for the panel.
     *
     * @return list<array{id: string, label: string, setting: string, on: bool, servers: list<array>, options: array<string, bool>, webmail_check: bool}>
     */
    public function panels_get_list(): array
    {
        $this->checkPermissions('servicehosting');

        $di = $this->getDi();
        $config = $di['mod_config']('servicehosting');
        $list = [];
        foreach (Panels::PANELS as $id => $panel) {
            $servers = Panels::servers($di, $id);
            $options = [];
            foreach ($panel['options'] as $setting) {
                $options[$setting] = (string) ($config[$setting] ?? '0') === '1';
            }
            $on = Panels::on($di, $id);
            $list[] = ['id' => $id, 'label' => $panel['label'], 'setting' => $panel['setting'], 'on' => $on, 'servers' => $servers, 'options' => $options, 'webmail_check' => $on && $servers !== []];
        }

        return $list;
    }

    /**
     * ClientFence: the global webmail domain a panel reports (`panel`; its first server):
     * {domain, error}, or null when the panel is off, has no server or has no such setting.
     */
    public function panel_webmail_domain($data): ?array
    {
        $this->checkPermissions('servicehosting');

        $panel = (string) ($data['panel'] ?? '');
        if (!isset(Panels::PANELS[$panel])) {
            throw new \ClientFence\InformationException('Unknown hosting panel.');
        }
        $servers = Panels::servers($this->getDi(), $panel);
        if ($servers === [] || !Panels::on($this->getDi(), $panel)) {
            return null;
        }
        try {
            $manager = Panels::serverManager($this->getDi(), $panel, $servers[0]['id']);

            return method_exists($manager, 'clientfenceWebmailDomain') ? ['domain' => $manager->clientfenceWebmailDomain(), 'error' => false] : null;
        } catch (\Throwable) {
            return ['domain' => null, 'error' => true];
        }
    }

    /**
     * ClientFence: the plans of a panel server with the hosting plan each matches (Panel/Plans.php).
     */
    public function panel_plans($data): array
    {
        $this->checkPermissions('servicehosting');

        return Plans::list($this->getDi(), $this->panelOn($data), (int) ($data['server_id'] ?? 0));
    }

    /**
     * ClientFence: makes hosting plans from a panel server's plans (`plan_ids`: the chosen ones, else
     * all; `update`: also update the hosting plans that already match).
     */
    public function panel_plans_import($data): array
    {
        $this->checkPermissions('servicehosting', 'manage_plans');

        $ids = is_array($data['plan_ids'] ?? null) ? array_values(array_map('strval', array_filter($data['plan_ids'], 'is_scalar'))) : [];

        return Plans::import($this->getDi(), $this->panelOn($data), (int) ($data['server_id'] ?? 0), $ids, !empty($data['update']));
    }

    /** A hosting panel that is switched on (`panel`). */
    private function panelOn(array $data): string
    {
        $panel = (string) ($data['panel'] ?? '');
        if (!isset(Panels::PANELS[$panel]) || !Panels::on($this->getDi(), $panel)) {
            throw new \ClientFence\InformationException('Switch the hosting panel on first.');
        }

        return $panel;
    }
}
