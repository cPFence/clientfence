<?php

declare(strict_types=1);

/**
 * ClientFence: FOSSBilling hosting plans made from a panel's own plans (Hosting Plans and Servers page,
 * Hosting panels). A panel plan matches a hosting plan by its custom values (`plan_id`), else by
 * name; matching plans are left alone unless staff choose to update them. Hosting plans made from a server keep its id
 * (`server_id`): several servers (Enhance clusters) number and name their plans alike, and never match each other's.
 */

namespace ClientFence\Mod\Servicehosting\Panel;

final class Plans
{
    public const LIMITS = ['quota', 'bandwidth', 'max_addon', 'max_sub', 'max_park', 'max_ftp', 'max_sql', 'max_pop'];

    /**
     * The panel plans of a server, each with the hosting plan it matches (if any).
     *
     * @return list<array<string, mixed>>
     */
    public static function list(\Pimple\Container $di, string $panel, int $serverId): array
    {
        $hostingPlans = $di['em']->getConnection()->fetchAllAssociative('SELECT id, name, config FROM service_hosting_hp ORDER BY id');
        $plans = [];
        foreach (Panels::serverManager($di, $panel, $serverId)->listPackages() as $plan) {
            if (!empty($plan['config'])) {
                $plan['config']['server_id'] = (string) $serverId;
            }
            [$match, $by] = self::match($hostingPlans, $plan);
            $plan['hosting_plan_id'] = $match === null ? null : (int) $match['id'];
            $plan['hosting_plan_name'] = $match === null ? null : (string) $match['name'];
            $plan['matched_by'] = $by;
            $plans[] = $plan;
        }

        return $plans;
    }

    /**
     * Makes hosting plans for the chosen panel plans (all when none are chosen) and, when asked,
     * updates the hosting plans that already match one.
     *
     * @param list<string> $planIds
     *
     * @return array{created: int, updated: int, skipped: int}
     */
    public static function import(\Pimple\Container $di, string $panel, int $serverId, array $planIds, bool $update): array
    {
        $hosting = $di['mod_service']('servicehosting');
        $done = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        foreach (self::list($di, $panel, $serverId) as $plan) {
            if ($planIds !== [] && !in_array((string) $plan['id'], $planIds, true)) {
                continue;
            }
            $data = ['config' => $plan['config'] ?? []];
            foreach (self::LIMITS as $limit) {
                if (array_key_exists($limit, $plan)) {
                    $data[$limit] = $plan[$limit] === null ? 'unlimited' : (string) $plan[$limit];
                }
            }

            if ($plan['hosting_plan_id'] !== null) {
                if (!$update) {
                    ++$done['skipped'];

                    continue;
                }
                $hosting->updateHp($di['db']->load('ServiceHostingHp', $plan['hosting_plan_id']), $data + ['name' => self::name($di, $plan, $serverId)]);
                ++$done['updated'];

                continue;
            }

            $id = $hosting->createHp(self::name($di, $plan, $serverId), $data);
            if ($id !== null && $data['config'] !== []) {
                // createHp leaves the custom values out.
                $hosting->updateHp($di['db']->load('ServiceHostingHp', $id), ['config' => $data['config']]);
            }
            ++$done['created'];
        }
        $di['logger']->info('ClientFence: hosting plans from %s server #%s: %d created, %d updated, %d left as they were', Panels::PANELS[$panel]['label'], $serverId, $done['created'], $done['updated'], $done['skipped']);

        return $done;
    }

    /** The plan's name, followed by its server's when another hosting plan already has that name. */
    private static function name(\Pimple\Container $di, array $plan, int $serverId): string
    {
        $db = $di['em']->getConnection();
        $taken = $db->fetchOne('SELECT COUNT(*) FROM service_hosting_hp WHERE name = ? AND id <> ?', [(string) $plan['name'], (int) ($plan['hosting_plan_id'] ?? 0)]);

        return (int) $taken === 0 ? (string) $plan['name'] : $plan['name'] . ' (' . $db->fetchOne('SELECT name FROM service_hosting_server WHERE id = ?', [$serverId]) . ')';
    }

    /**
     * @return array{0: ?array, 1: ?string}
     */
    private static function match(array $hostingPlans, array $plan): array
    {
        $wanted = $plan['config'] ?? [];
        if ($wanted !== []) {
            foreach ($hostingPlans as $hp) {
                $config = json_decode((string) $hp['config'], true);
                $config = is_array($config) ? $config : [];
                foreach ($wanted as $key => $value) {
                    if ((string) ($config[$key] ?? '') !== (string) $value) {
                        continue 2;
                    }
                }

                return [$hp, 'config'];
            }
        }
        foreach ($hostingPlans as $hp) {
            $config = json_decode((string) $hp['config'], true);
            // Never a plan made from another server, whatever its name.
            if (isset($wanted['server_id'], $config['server_id']) && (string) $config['server_id'] !== $wanted['server_id']) {
                continue;
            }
            if (strcasecmp((string) $hp['name'], (string) $plan['name']) === 0) {
                return [$hp, 'name'];
            }
        }

        return [null, null];
    }
}
