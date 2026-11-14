<?php

declare(strict_types=1);

namespace ClientFence\Mod\Client\Affiliates;

use Doctrine\DBAL\Connection;

/**
 * ClientFence: the affiliate emails (Affiliates.php), as WHMCS. Staff hear of a payout request
 * (mod_staff_affiliate_payout_request); the affiliate hears when it is paid or declined
 * (mod_client_affiliate_payout_paid, _declined) and gets the monthly referrals report
 * (mod_client_affiliate_report) on the first cron run of each month. Copies go to the account's contacts
 * with the "affiliate" kind (Contacts.php). A failed email never stops the money action.
 */
final class Emails
{
    private const STATE = 'affiliates_monthly';

    public static function payoutRequested(\Pimple\Container $di, int $payoutId): void
    {
        self::send($di, $payoutId, static function (array $payout) use ($di, $payoutId): array {
            return [
                'to_staff' => true,
                // Only the staff who can pay it: the email carries the affiliate's payout (bank) details.
                'to_staff_permission' => ['client', 'manage_affiliates'],
                'code' => 'mod_staff_affiliate_payout_request',
                'affiliate' => ['name' => trim($payout['first_name'] . ' ' . $payout['last_name']), 'company' => (string) $payout['company'], 'code' => $payout['code']],
                'payout' => ['id' => $payoutId, 'amount' => $payout['amount'], 'currency' => $payout['currency'], 'details' => (string) $payout['details']],
                'url' => $di['url']->adminLink('client/manage/' . $payout['client_id']) . '#tab-clientfence-affiliate',
            ];
        });
    }

    /** The affiliate's email for a payout staff paid or declined (with the staff note, if any). */
    public static function payoutHandled(\Pimple\Container $di, int $payoutId, string $note = ''): void
    {
        self::send($di, $payoutId, static fn (array $payout): array => [
            'to_client' => (int) $payout['client_id'],
            'code' => $payout['status'] === 'paid' ? 'mod_client_affiliate_payout_paid' : 'mod_client_affiliate_payout_declined',
            'payout' => ['id' => $payoutId, 'amount' => $payout['amount'], 'currency' => $payout['currency'], 'method' => (string) $payout['method'], 'note' => trim($note)],
        ]);
    }

    /**
     * Clientfence\Service::onAfterAdminCronRun(), daily: on the first run of a month, each active affiliate gets
     * last month's report. The first run after an install or update only notes the month, so nobody gets a
     * report for a month the program did not run in.
     */
    public static function monthly(\Pimple\Container $di): void
    {
        if (!Affiliates::settings($di)['enabled']) {
            return;
        }
        $db = $di['dbal'];
        $month = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
        $sent = $db->fetchOne('SELECT value FROM mod_clientfence_state WHERE name = ?', [self::STATE]);
        if ($sent !== false && $sent >= $month) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $sent === false
            ? $db->insert('mod_clientfence_state', ['name' => self::STATE, 'value' => $month, 'updated_at' => $now])
            : $db->update('mod_clientfence_state', ['value' => $month, 'updated_at' => $now], ['name' => self::STATE]);
        if ($sent === false) {
            return;
        }
        foreach ($db->fetchAllAssociative("SELECT id, client_id, code FROM client_affiliate WHERE status = 'active' ORDER BY id") as $affiliate) {
            try {
                $di['mod_service']('email')->sendTemplate([
                    'to_client' => (int) $affiliate['client_id'],
                    'code' => 'mod_client_affiliate_report',
                    'report' => self::report($db, (int) $affiliate['id'], $month) + Affiliates::balances($db, (int) $affiliate['id']) + [
                        'currency' => (string) $db->fetchOne('SELECT currency FROM client WHERE id = ?', [$affiliate['client_id']]),
                        'link' => $di['url']->link('/', ['aff' => $affiliate['code']]),
                    ],
                ]);
            } catch (\Throwable $e) {
                $di['logger']->error('Affiliates: the monthly report of affiliate #%s was not sent: %s', $affiliate['id'], $e->getMessage());
            }
        }
    }

    /**
     * An affiliate's month ('Y-m'): visitors, signups (referred orders) and the commissions it earned (not cancelled).
     *
     * @return array{month: string, visitors: int, signups: int, earned: float}
     */
    public static function report(Connection $db, int $affiliateId, string $month): array
    {
        $from = $month . '-01';
        $to = date('Y-m-d', strtotime($from . ' +1 month'));

        return [
            'month' => $from,
            'visitors' => (int) $db->fetchOne('SELECT COALESCE(SUM(hits), 0) FROM client_affiliate_visit WHERE affiliate_id = ? AND day >= ? AND day < ?', [$affiliateId, $from, $to]),
            'signups' => (int) $db->fetchOne('SELECT COUNT(*) FROM client_affiliate_referral WHERE affiliate_id = ? AND created_at >= ? AND created_at < ?', [$affiliateId, $from, $to]),
            'earned' => round((float) $db->fetchOne("SELECT COALESCE(SUM(amount), 0) FROM client_affiliate_entry WHERE affiliate_id = ? AND type = 'commission' AND status <> 'cancelled' AND created_at >= ? AND created_at < ?", [$affiliateId, $from, $to]), 2),
        ];
    }

    /** Sends the email $build makes from the payout (with its affiliate's client); logs a failure. */
    private static function send(\Pimple\Container $di, int $payoutId, callable $build): void
    {
        try {
            $payout = $di['dbal']->fetchAssociative(
                'SELECT p.*, a.client_id, a.code, c.first_name, c.last_name, c.company FROM client_affiliate_payout p
                 JOIN client_affiliate a ON a.id = p.affiliate_id JOIN client c ON c.id = a.client_id WHERE p.id = ?',
                [$payoutId],
            );
            if ($payout !== false) {
                $di['mod_service']('email')->sendTemplate($build($payout));
            }
        } catch (\Throwable $e) {
            $di['logger']->error('Affiliates: the email about payout #%s was not sent: %s', $payoutId, $e->getMessage());
        }
    }
}
