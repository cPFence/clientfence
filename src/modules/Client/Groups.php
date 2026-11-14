<?php

declare(strict_types=1);

namespace ClientFence\Mod\Client;

use ClientFence\InformationException;
use Doctrine\DBAL\ArrayParameterType;

/**
 * The client groups a client is in (client_group_members). A client can be in several; `client.client_group_id`
 * keeps the first of them, so the old single-group API (`group_id`) and its callers still answer. Every check that
 * gives a discount, an exemption or access uses all of them (ids()).
 */
final class Groups
{
    /** @return list<int> */
    public static function ids(\Pimple\Container $di, int $clientId): array
    {
        return array_map(intval(...), $di['dbal']->fetchFirstColumn('SELECT client_group_id FROM client_group_members WHERE client_id = ? ORDER BY client_group_id', [$clientId]));
    }

    /**
     * The group ids staff gave, checked: empty values are dropped (the form's empty hidden input clears the list).
     *
     * @return list<int>
     */
    public static function valid(\Pimple\Container $di, array $groupIds): array
    {
        $ids = [];
        foreach (array_filter($groupIds, static fn ($id): bool => !empty($id)) as $id) {
            $int = filter_var($id, FILTER_VALIDATE_INT);
            if ($int === false || $int <= 0) {
                throw new InformationException('Invalid client group ID: :id', [':id' => $id]);
            }
            $ids[$int] = $int;
        }
        $ids = array_values($ids);
        if ($ids !== [] && count($di['dbal']->fetchFirstColumn('SELECT id FROM client_group WHERE id IN (?)', [$ids], [ArrayParameterType::INTEGER])) !== count($ids)) {
            throw new InformationException('Client group not found');
        }

        return $ids;
    }

    /**
     * Puts the client in exactly these groups (checked with valid()), the first one also in `client.client_group_id`.
     * Returns that first group (null: none).
     */
    public static function set(\Pimple\Container $di, int $clientId, array $groupIds): ?int
    {
        $ids = self::valid($di, $groupIds);
        $di['dbal']->transactional(static function ($db) use ($clientId, $ids): void {
            $db->delete('client_group_members', ['client_id' => $clientId]);
            foreach ($ids as $id) {
                $db->insert('client_group_members', ['client_id' => $clientId, 'client_group_id' => $id]);
            }
            $db->update('client', ['client_group_id' => $ids[0] ?? null], ['id' => $clientId]);
        });

        return $ids[0] ?? null;
    }
}
