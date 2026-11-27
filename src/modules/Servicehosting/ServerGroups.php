<?php

declare(strict_types=1);

namespace ClientFence\Mod\Servicehosting;

use ClientFence\InformationException;

final class ServerGroups
{
    public static function list(\Pimple\Container $di): array
    {
        $groups = $di['dbal']->fetchAllAssociative('SELECT * FROM service_hosting_server_group ORDER BY name');
        foreach ($groups as &$group) {
            $group['servers'] = json_decode($group['servers'], true);
        }

        return $groups;
    }

    public static function save(\Pimple\Container $di, array $data): int
    {
        $db = $di['dbal'];
        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
        if ($name === '') {
            throw new InformationException('Server group name is required.');
        }
        $fillType = ($data + ['fill_type' => 'least'])['fill_type'];
        if (!in_array($fillType, ['least', 'fill'], true)) {
            throw new InformationException('Invalid server group fill type.');
        }
        $servers = $data['servers'] ?? [];
        if (!is_array($servers) || $servers === []) {
            throw new InformationException('Choose at least one server for the group.');
        }
        $positions = [];
        foreach ($servers as $serverId => $position) {
            // The form posts an empty position for a server that is not in the group.
            if ($position === '' || $position === null) {
                continue;
            }
            $id = filter_var($serverId, FILTER_VALIDATE_INT);
            $server = $id === false ? false : $db->fetchAssociative('SELECT name, max_accounts FROM service_hosting_server WHERE id = ?', [$id]);
            if ($server === false) {
                throw new InformationException('Server not found');
            }
            if ((int) $server['max_accounts'] <= 0) {
                throw new InformationException('Server :name has no maximum number of accounts. Set one to add it to a group.', [':name' => $server['name']]);
            }
            $position = filter_var($position, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($position === false) {
                throw new InformationException('Enter a positive position for each server.');
            }
            $positions[$id] = $position;
        }
        if ($positions === []) {
            throw new InformationException('Choose at least one server for the group.');
        }
        ksort($positions, SORT_NUMERIC);
        asort($positions, SORT_NUMERIC);
        $row = ['name' => $name, 'fill_type' => $fillType, 'servers' => json_encode(array_keys($positions)), 'updated_at' => date('Y-m-d H:i:s')];
        $id = (int) ($data['id'] ?? 0);
        if ($id > 0) {
            if ($db->fetchOne('SELECT id FROM service_hosting_server_group WHERE id = ?', [$id]) === false) {
                throw new InformationException('Server group not found');
            }
            $db->update('service_hosting_server_group', $row, ['id' => $id]);

            return $id;
        }
        $db->insert('service_hosting_server_group', $row + ['created_at' => $row['updated_at']]);

        return (int) $db->lastInsertId();
    }

    public static function delete(\Pimple\Container $di, int $id): void
    {
        $db = $di['dbal'];
        foreach ($db->iterateAssociative('SELECT title, config FROM product WHERE config IS NOT NULL') as $product) {
            $groupId = json_decode($product['config'], true)['server_group_id'] ?? null;
            if ($groupId !== null && (int) $groupId === $id) {
                throw new InformationException('Server group is used by product :product.', [':product' => $product['title']]);
            }
        }
        $db->delete('service_hosting_server_group', ['id' => $id]);
    }

    public static function pick(\Pimple\Container $di, array $group, int $planId): \Model_ServiceHostingServer
    {
        $plan = $di['db']->getExistingModelById('ServiceHostingHp', $planId, 'Hosting plan from order configuration was not found');
        $best = null;
        $bestLoad = null;
        foreach ($group['servers'] as $id) {
            if (!Service::planFitsServer($plan->config, $id)) {
                continue;
            }
            $server = $di['db']->load('ServiceHostingServer', $id);
            if ($server === null || (string) $server->active === '0') {
                continue;
            }
            $count = Service::countServerAccounts($di, $id);
            $maxAccounts = (int) $server->max_accounts;
            if ($count >= $maxAccounts) {
                continue;
            }
            if ($group['fill_type'] === 'fill') {
                return $server;
            }
            $load = $count / $maxAccounts;
            if ($bestLoad === null || $load < $bestLoad) {
                $best = $server;
                $bestLoad = $load;
            }
        }

        return $best ?? throw new InformationException('This hosting plan cannot be ordered right now. Please contact us.');
    }
}
