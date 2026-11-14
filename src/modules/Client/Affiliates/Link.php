<?php

declare(strict_types=1);

namespace ClientFence\Mod\Client\Affiliates;

use ClientFence\Http\RequestFactory;
use ClientFence\InformationException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * ClientFence: affiliate links and referrals (Affiliates.php). `?aff=CODE` on any client page, or the WHMCS
 * address /aff.php?aff=CODE, sets a first-party cookie (last click wins) and sends the visitor back to the same
 * page without the code; valid and invalid codes get the same answer. Orders a client places in a client session
 * are referred to the cookie's affiliate (Cart\Service::createFromCart()); staff can assign or remove one.
 */
final class Link
{
    public const COOKIE = 'clientfence_aff';

    /** index.php, before any session: the link's redirect, or null when the request has no code. */
    public static function hop(Request $request, \Pimple\Container $di): ?RedirectResponse
    {
        $code = $request->query->all()['aff'] ?? null;
        $path = RequestFactory::getRoutePath($request);
        // ClientFence: Blesta's affiliate links (/order/forms/a/CODE) keep working after an import.
        if ($code === null && preg_match('~^/order/forms/a/([A-Za-z0-9_-]{1,62}={0,2})/?$~D', $path, $blesta) === 1) {
            [$code, $path] = [$blesta[1], '/aff.php'];
        }
        if (!is_string($code) || !$request->isMethod('GET') || str_starts_with($path, '/api/') || $path === ADMIN_PREFIX || str_starts_with($path, ADMIN_PREFIX . '/')) {
            return null;
        }
        $query = $request->query->all();
        unset($query['aff'], $query['_url'], $query['_errcode']);
        // Only the page asked for, on the configured address: the link names no other target.
        $response = new RedirectResponse($di['url']->link($path === '/aff.php' ? '/' : $path, $query), 302, ['Cache-Control' => 'no-store']);
        $settings = Affiliates::settings($di);
        if ($settings['enabled'] && preg_match('/^[A-Za-z0-9_-]{1,62}={0,2}$/D', $code) === 1) {
            if ($request->cookies->get(self::COOKIE) !== $code) {
                self::countVisit($di['dbal'], $code);
            }
            $response->headers->setCookie(Cookie::create(self::COOKIE, $code, time() + $settings['cookie_days'] * 86400, '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX));
        }

        return $response;
    }

    /**
     * Cart\Service::createFromCart() for a client's own checkout: each new service order that can earn (not an add-on,
     * not a domain) is referred to the cookie's active affiliate, unless staff are signed in as the client or the
     * affiliate is the client itself (or shares an authorized user with it).
     *
     * @param array<int|null> $orderIds
     */
    public static function attribute(\Pimple\Container $di, int $clientId, array $orderIds): void
    {
        $code = $di['request']->cookies->get(self::COOKIE);
        if (!is_string($code) || $code === '' || !empty($di['session']->get('admin')) || !Affiliates::settings($di)['enabled']) {
            return;
        }
        $db = $di['dbal'];
        $affiliate = $db->fetchAssociative("SELECT id, client_id FROM client_affiliate WHERE code = ? AND status = 'active'", [$code]);
        if ($affiliate === false || self::related($db, (int) $affiliate['client_id'], $clientId)) {
            return;
        }
        $ids = implode(',', array_map(intval(...), $orderIds)) ?: '0';
        foreach ($db->fetchFirstColumn("SELECT id FROM client_order WHERE id IN ({$ids}) AND group_master = 1 AND service_type <> 'domain'") as $orderId) {
            $db->insert('client_affiliate_referral', ['affiliate_id' => $affiliate['id'], 'client_id' => $clientId, 'client_order_id' => $orderId, 'source' => 'cookie', 'created_at' => date('Y-m-d H:i:s')]);
        }
    }

    /** Whether the client is the affiliate's own account, or an authorized user is linked to both. */
    public static function related(Connection $db, int $affiliateClientId, int $clientId): bool
    {
        return $affiliateClientId === $clientId
            || $db->fetchOne('SELECT 1 FROM client_user_account a JOIN client_user_account b ON b.user_id = a.user_id WHERE a.client_id = ? AND b.client_id = ?', [$affiliateClientId, $clientId]) !== false;
    }

    /** Staff: refers an order to an affiliate (replacing any other); it earns on invoices paid from now on. */
    public static function assign(\Pimple\Container $di, int $orderId, int $affiliateId, int $adminId): void
    {
        $db = $di['dbal'];
        $order = $db->fetchAssociative('SELECT id, client_id, group_master, service_type FROM client_order WHERE id = ?', [$orderId]);
        if ($order === false) {
            throw new InformationException('Order not found');
        }
        if (!(int) $order['group_master'] || $order['service_type'] === 'domain') {
            throw new InformationException('Add-on and domain orders do not earn affiliate commissions.');
        }
        $affiliate = $db->fetchAssociative('SELECT id, client_id FROM client_affiliate WHERE id = ?', [$affiliateId]);
        if ($affiliate === false) {
            throw new InformationException('Affiliate not found');
        }
        if (self::related($db, (int) $affiliate['client_id'], (int) $order['client_id'])) {
            throw new InformationException('An affiliate cannot be referred to its own orders.');
        }
        $db->transactional(static function (Connection $db) use ($order, $affiliateId): void {
            $db->delete('client_affiliate_referral', ['client_order_id' => $order['id']]);
            $db->insert('client_affiliate_referral', ['affiliate_id' => $affiliateId, 'client_id' => $order['client_id'], 'client_order_id' => $order['id'], 'source' => 'staff', 'created_at' => date('Y-m-d H:i:s')]);
        });
        Affiliates::log($di, (int) $affiliate['client_id'], sprintf('Order #%d assigned to the affiliate account', $orderId), $adminId);
    }

    /** Staff: the order is no longer referred (commissions it earned stay). */
    public static function unassign(\Pimple\Container $di, int $orderId, int $adminId): void
    {
        $db = $di['dbal'];
        $affiliateClient = $db->fetchOne('SELECT a.client_id FROM client_affiliate_referral r JOIN client_affiliate a ON a.id = r.affiliate_id WHERE r.client_order_id = ?', [$orderId]);
        if ($affiliateClient === false) {
            throw new InformationException('This order has no affiliate.');
        }
        $db->delete('client_affiliate_referral', ['client_order_id' => $orderId]);
        Affiliates::log($di, (int) $affiliateClient, sprintf('Order #%d removed from the affiliate account', $orderId), $adminId);
    }

    /** Visits are counted per affiliate and day (no referrer, no IP), only for an active code. */
    private static function countVisit(Connection $db, string $code): void
    {
        $id = $db->fetchOne("SELECT id FROM client_affiliate WHERE code = ? AND status = 'active'", [$code]);
        if ($id === false) {
            return;
        }
        $day = date('Y-m-d');
        $hit = static fn (): int => (int) $db->executeStatement('UPDATE client_affiliate_visit SET hits = hits + 1 WHERE affiliate_id = ? AND day = ?', [$id, $day]);
        if ($hit() === 0) {
            try {
                $db->insert('client_affiliate_visit', ['affiliate_id' => $id, 'day' => $day, 'hits' => 1]);
            } catch (UniqueConstraintViolationException) {
                // The day's first visit arrived twice at once.
                $hit();
            }
        }
    }
}
