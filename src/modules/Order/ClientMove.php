<?php

declare(strict_types=1);

namespace ClientFence\Mod\Order;

use ClientFence\InformationException;
use ClientFence\Mod\Client\Affiliates\Link;
use ClientFence\Mod\Client\Notes;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * ClientFence (CF-183): staff move a service, with its add-ons, to another client, as Blesta.
 *
 * Only the owner changes: the orders, their service records (service_* client_id) and the order's affiliate referral.
 * Invoices, payments, credit and gateway subscriptions stay with the old client as history, so a service with an
 * unpaid invoice (its own line or a waiting upgrade's) or an active gateway subscription is refused until staff settle
 * it: an unpaid invoice of one client must not suspend the service of another. A saved card is the client's, not the
 * service's: renewals invoice the new owner, so the old client's card is never charged for them.
 * Nothing is called on a server or registrar: a hosting account keeps its panel account, and the note says so.
 */
final class ClientMove
{
    public static function move(\Pimple\Container $di, int $orderId, int $clientId, int $adminId): void
    {
        $di['em']->getConnection()->transactional(static function (Connection $db) use ($di, $orderId, $clientId, $adminId): void {
            $order = $db->fetchAssociative('SELECT id, client_id, title, status, currency, group_id, group_master, service_type FROM client_order WHERE id = ?', [$orderId]);
            if ($order === false) {
                throw new InformationException('Order not found');
            }
            $fromId = (int) $order['client_id'];
            if ($fromId === $clientId) {
                throw new InformationException('The service already belongs to this client.');
            }
            $to = $db->fetchAssociative('SELECT id, currency, first_name, last_name FROM client WHERE id = ?', [$clientId]);
            if ($to === false) {
                throw new InformationException('Client not found');
            }
            $group = (string) ($order['group_id'] ?? '');
            if ($group !== '' && !(int) $order['group_master']
                && $db->fetchOne('SELECT id FROM client_order WHERE group_id = ? AND client_id = ? AND group_master = 1', [$group, $fromId]) !== false) {
                throw new InformationException('This is an add-on: move its main service, and its add-ons move with it.');
            }
            // The add-ons as Order\Service::getOrderAddonsList() finds them: one bought for this service, or in its checkout
            // (a domain ordered with hosting is a second master of the hosting's group: those stay with the hosting).
            $ids = [$orderId];
            if ($group !== '' && (int) $order['group_master']) {
                $domainBeside = (string) $order['service_type'] === 'domain'
                    && $db->fetchOne("SELECT id FROM client_order WHERE group_id = ? AND client_id = ? AND id <> ? AND group_master = 1 AND service_type <> 'domain'", [$group, $fromId, $orderId]) !== false;
                foreach ($db->fetchAllAssociative('SELECT id, config FROM client_order WHERE group_id = ? AND client_id = ? AND id <> ? AND (group_master = 0 OR group_master IS NULL) ORDER BY id', [$group, $fromId, $orderId]) as $addon) {
                    $for = (int) (json_decode((string) $addon['config'], true)['addon_for'] ?? 0);
                    if ($for > 0 ? $for === $orderId : !$domainBeside) {
                        $ids[] = (int) $addon['id'];
                    }
                }
            }
            self::refuse($db, $ids, $to);

            $now = date('Y-m-d H:i:s');
            $in = [$ids];
            $types = [ArrayParameterType::INTEGER];
            if ((string) $to['currency'] === '' && (string) $order['currency'] !== '') {
                // As checkout, a client without a currency takes its first order's.
                $db->update('client', ['currency' => $order['currency'], 'updated_at' => $now], ['id' => $clientId]);
            }
            $db->executeStatement('UPDATE client_order SET client_id = ?, updated_at = ? WHERE id IN (?)', [$clientId, $now, $ids], [ParameterType::INTEGER, ParameterType::STRING, ArrayParameterType::INTEGER]);
            $hosting = false;
            $enhance = false;
            foreach ($db->fetchAllAssociative('SELECT service_id, service_type FROM client_order WHERE id IN (?) AND service_id IS NOT NULL', $in, $types) as $service) {
                $type = (string) $service['service_type'];
                $enhance = $enhance || ($type === 'hosting' && $db->fetchOne("SELECT 1 FROM service_hosting h JOIN service_hosting_server s ON s.id = h.service_hosting_server_id WHERE h.id = ? AND s.manager = 'Enhance'", [(int) $service['service_id']]) !== false);
                $hosting = $hosting || $type === 'hosting';
                if (preg_match('/^[a-z0-9_]+$/', $type) && self::hasClientColumn($db, 'service_' . $type)) {
                    $db->update('service_' . $type, ['client_id' => $clientId], ['id' => (int) $service['service_id']]);
                }
            }
            // The affiliate keeps the referred service, unless the new owner is the affiliate (Link::assign() refuses that).
            $affiliateNote = '';
            foreach ($db->fetchAllAssociative('SELECT r.id, a.client_id FROM client_affiliate_referral r JOIN client_affiliate a ON a.id = r.affiliate_id WHERE r.client_order_id IN (?)', $in, $types) as $referral) {
                if (Link::related($db, (int) $referral['client_id'], $clientId)) {
                    $db->delete('client_affiliate_referral', ['id' => (int) $referral['id']]);
                    $affiliateNote = ' The affiliate referral was removed: the new client is the affiliate.';
                } else {
                    $db->update('client_affiliate_referral', ['client_id' => $clientId], ['id' => (int) $referral['id']]);
                }
            }

            $admin = (string) $db->fetchOne('SELECT name FROM admin WHERE id = ?', [$adminId]);
            $from = $db->fetchAssociative('SELECT first_name, last_name FROM client WHERE id = ?', [$fromId]) ?: ['first_name' => '', 'last_name' => ''];
            $what = sprintf('Service #%d "%s"', $orderId, $order['title']) . (count($ids) > 1 ? ' with its add-ons #' . implode(', #', array_slice($ids, 1)) : '');
            $who = sprintf('from client #%d %s to client #%d %s by %s', $fromId, trim($from['first_name'] . ' ' . $from['last_name']), $clientId, trim($to['first_name'] . ' ' . $to['last_name']), $admin !== '' ? $admin : 'staff #' . $adminId);
            $note = sprintf('%s moved %s. Invoices, payments and credit stay with the old client. Nothing was changed on servers or registrars', $what, $who)
                . ($hosting ? ': the hosting account keeps its panel account; move it on the panel by hand if needed' . ($enhance ? ' and check the Enhance Link card.' : '.') : '.') . $affiliateNote;
            foreach ($db->fetchAllAssociative('SELECT id, status FROM client_order WHERE id IN (?)', $in, $types) as $row) {
                $db->insert('client_order_status', ['client_order_id' => (int) $row['id'], 'status' => $row['status'], 'notes' => $note, 'created_at' => $now, 'updated_at' => $now]);
            }
            Notes::create($di, $fromId, $adminId, $note);
            Notes::create($di, $clientId, $adminId, $note);
            $di['logger']->info('Order move: %s', $note);
        });
    }

    /** Each reason the service cannot change owner now. */
    private static function refuse(Connection $db, array $ids, array $to): void
    {
        $in = [$ids];
        $types = [ArrayParameterType::INTEGER];
        $invoice = $db->fetchAssociative(
            "SELECT i.id, i.serie, i.nr FROM invoice i JOIN invoice_item ii ON ii.invoice_id = i.id WHERE i.status = 'unpaid' AND ii.type = 'order' AND ii.rel_id IN (?)
             UNION SELECT i.id, i.serie, i.nr FROM invoice i JOIN client_order_upgrade u ON u.invoice_id = i.id WHERE i.status = 'unpaid' AND u.order_id IN (?) LIMIT 1",
            [array_map('strval', $ids), $ids],
            [ArrayParameterType::STRING, ArrayParameterType::INTEGER],
        );
        if ($invoice !== false) {
            throw new InformationException('This service has an unpaid invoice :number. Mark it paid or cancel it before you move the service.', [':number' => \ClientFence\Mod\Invoice\Statement::number($db, $invoice)]);
        }
        if ((string) $to['currency'] !== '') {
            foreach ($db->fetchFirstColumn('SELECT DISTINCT currency FROM client_order WHERE id IN (?)', $in, $types) as $currency) {
                if ((string) $currency !== '' && $currency !== $to['currency']) {
                    throw new InformationException('The service is billed in :currency, but the new client pays in :client_currency.', [':currency' => $currency, ':client_currency' => $to['currency']]);
                }
            }
        }
        // As Invoice\SavedCards and ServiceSubscription::getSubscriptionsForOrder(): a subscription belongs to the invoice that started it.
        if ($db->fetchOne("SELECT s.id FROM subscription s JOIN invoice_item ii ON ii.invoice_id = s.rel_id WHERE s.rel_type = 'invoice' AND s.status = 'active' AND ii.type = 'order' AND ii.rel_id IN (?)", [array_map('strval', $ids)], [ArrayParameterType::STRING]) !== false) {
            throw new InformationException('This service is paid by a gateway subscription of its client. Cancel the subscription first, so the old client does not keep paying for the new owner.');
        }
        if ($db->fetchOne('SELECT id FROM ' . Holds::TABLE . ' WHERE client_order_id IN (?) AND status = ?', [$ids, Holds::STATUS_HELD], [ArrayParameterType::INTEGER, ParameterType::STRING]) !== false) {
            throw new InformationException('This service waits for review. Approve or refuse it first.');
        }
        if ($db->fetchOne('SELECT id FROM ' . CancelRequests::TABLE . ' WHERE client_order_id IN (?) AND status = ?', [$ids, CancelRequests::STATUS_PENDING], [ArrayParameterType::INTEGER, ParameterType::STRING]) !== false) {
            throw new InformationException('This service has a waiting cancellation request. Accept or stop it first.');
        }
    }

    private static function hasClientColumn(Connection $db, string $table): bool
    {
        return $db->createSchemaManager()->introspectTableByUnquotedName($table)->hasColumn('client_id');
    }
}
