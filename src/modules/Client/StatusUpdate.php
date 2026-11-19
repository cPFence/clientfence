<?php

declare(strict_types=1);

namespace ClientFence\Mod\Client;

use ClientFence\Mod\Client\Entity\Client;

/**
 * ClientFence: WHMCS's automatic client status update (Client settings > General, mod_client "status_update").
 * Once a day the cron sets an Active client Inactive when they have no live service, add-on or domain (and, with
 * "signin", no sign-in for 3 months either), and an Inactive client Active again once they have one (or signed in).
 * Only clients two days old or more with the profile switch Status Update on (client.status_update) are touched;
 * Suspended and Canceled clients never are. A new order sets an Inactive client Active at once, whatever the setting
 * (ordered()). Inactive clients sign in and use everything as Active ones (Entity\Client::OPEN).
 */
final class StatusUpdate
{
    public const MODES = ['off', 'services', 'signin'];

    /** Orders in these states keep a client Active (as Privacy's live services). */
    private const LIVE = "('active', 'pending_setup', 'failed_setup', 'failed_renew', 'suspended')";

    private const LAST_RUN = 'client_status_update_at';

    public static function mode(\Pimple\Container $di): string
    {
        $mode = (string) ($di['mod_config']('client')['status_update'] ?? 'off');

        return in_array($mode, self::MODES, true) ? $mode : 'off';
    }

    /** From the cron: once a day; returns [set Inactive, set Active], or null when it did not run. */
    public static function run(\Pimple\Container $di): ?array
    {
        $mode = self::mode($di);
        $system = $di['mod_service']('system');
        $last = (string) $system->getParamValue(self::LAST_RUN, '');
        if ($mode === 'off' || ($last !== '' && substr($last, 0, 10) === date('Y-m-d'))) {
            return null;
        }
        $db = $di['dbal'];
        $now = date('Y-m-d H:i:s');
        $live = 'EXISTS (SELECT 1 FROM client_order o WHERE o.client_id = client.id AND o.status IN ' . self::LIVE . ')';
        $signedIn = 'EXISTS (SELECT 1 FROM activity_client_history h WHERE h.client_id = client.id AND h.created_at >= ?)';
        $since = date('Y-m-d H:i:s', strtotime('-3 months'));
        $keeps = $mode === 'signin' ? "({$live} OR {$signedIn})" : $live;
        $params = $mode === 'signin' ? [$since] : [];
        $where = 'status_update = 1 AND created_at <= ?';
        $old = date('Y-m-d H:i:s', strtotime('-2 days'));

        $inactive = $db->executeStatement(
            "UPDATE client SET status = ?, updated_at = ? WHERE status = ? AND {$where} AND NOT {$keeps}",
            [Client::INACTIVE, $now, Client::ACTIVE, $old, ...$params]
        );
        $active = $db->executeStatement(
            "UPDATE client SET status = ?, updated_at = ? WHERE status = ? AND {$where} AND {$keeps}",
            [Client::ACTIVE, $now, Client::INACTIVE, $old, ...$params]
        );
        $system->setParamValue(self::LAST_RUN, $now);
        if ($inactive + $active > 0) {
            $di['logger']->info('Automatic client status update: %s clients set Inactive, %s set Active again', $inactive, $active);
        }

        return [$inactive, $active];
    }

    /** A new order: an Inactive client is Active again (WHMCS: a purchase resets the status). */
    public static function ordered(\Pimple\Container $di, int $clientId): void
    {
        $di['dbal']->executeStatement('UPDATE client SET status = ?, updated_at = ? WHERE id = ? AND status = ?', [Client::ACTIVE, date('Y-m-d H:i:s'), $clientId, Client::INACTIVE]);
    }
}
