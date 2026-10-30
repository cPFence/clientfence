<?php

declare(strict_types=1);

namespace ClientFence\Mod\Servicehosting\Panel;

use ClientFence\InformationException;
use ClientFence\Mod\Order\ActionFailures;
use ClientFence\Mod\Servicehosting\Panel\Enhance\Panel;

final class StatusCheck
{
    public const ACTION = 'status_check';
    private const STATE = 'hosting_status_check';
    private const EVERY = 86400;

    public static function runIfDue(\Pimple\Container $di): void
    {
        if (!Panels::on($di, 'enhance')) {
            return;
        }
        $db = $di['em']->getConnection();
        $last = json_decode((string) $db->fetchOne('SELECT value FROM mod_clientfence_state WHERE name = ?', [self::STATE]), true);
        if (time() - (int) ($last['ran_at'] ?? 0) < self::EVERY) {
            return;
        }
        $db->executeStatement(
            'INSERT INTO mod_clientfence_state (name, value, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
            [self::STATE, json_encode(['ran_at' => time()]), date('Y-m-d H:i:s')],
        );
        $orders = $db->fetchFirstColumn(
            "SELECT o.id FROM client_order o JOIN service_hosting h ON h.id = o.service_id
             JOIN service_hosting_server s ON s.id = h.service_hosting_server_id
             WHERE o.service_type = 'hosting' AND o.status IN ('active', 'suspended') AND s.manager = 'Enhance'",
        );
        foreach ($orders as $orderId) {
            try {
                self::check($di, (int) $orderId);
            } catch (\Throwable $e) {
                $di['logger']->warning('Hosting status check for order #%s failed: %s', $orderId, $e->getMessage());
            }
        }
    }

    public static function check(\Pimple\Container $di, int $orderId): bool
    {
        $order = $di['em']->getConnection()->fetchAssociative(
            "SELECT o.status, o.service_id FROM client_order o JOIN service_hosting h ON h.id = o.service_id
             JOIN service_hosting_server s ON s.id = h.service_hosting_server_id
             WHERE o.id = ? AND o.service_type = 'hosting' AND o.status IN ('active', 'suspended') AND s.manager = 'Enhance'",
            [$orderId],
        );
        if ($order === false || !Panels::on($di, 'enhance')) {
            throw new InformationException('This hosting order cannot be checked.');
        }
        $model = $di['db']->load('ServiceHosting', (int) $order['service_id']);
        [$manager, $account, $linkError] = $di['mod_service']('servicehosting')->_getAM($model);
        if ($linkError !== null && $linkError->getCode() !== Panel::ACCOUNT_NOT_FOUND) {
            throw $linkError;
        }
        if ($account->getPanelSubscriptionId() === null || $account->getPanelOrgId() === null) {
            $message = 'This hosting service could not be linked to a panel subscription';
        } else {
            $state = $manager->clientfenceSubscriptionState($account);
            $message = match (true) {
                $state === 'gone' => 'The panel subscription no longer exists',
                $state === 'suspended' && $order['status'] === 'active' => 'Suspended on the panel but active in billing',
                $state === 'active' && $order['status'] === 'suspended' => 'Active on the panel but suspended in billing',
                default => null,
            };
        }
        if ($message !== null) {
            ActionFailures::failed($di, $orderId, self::ACTION, new InformationException($message));

            return false;
        }
        ActionFailures::succeeded($di, $orderId, self::ACTION);

        return true;
    }
}
