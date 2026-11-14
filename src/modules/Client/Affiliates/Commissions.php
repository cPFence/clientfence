<?php

declare(strict_types=1);

namespace ClientFence\Mod\Client\Affiliates;

use ClientFence\Tools;

/**
 * ClientFence: affiliate commissions (Affiliates.php), with WHMCS's scope and rules.
 *
 * - Earned when an invoice turns paid (Invoice\Service::markAsPaidLocked()), per referred order on it: the net of
 *   the invoice lines with the order's id (order, setup fee, options, less its discount; no tax, no gateway fee,
 *   no upgrade or deposit lines). Add-on and domain orders are never referred (Link.php).
 * - The rule, most specific first: the product's "No commission" always wins, then the affiliate's own, then the
 *   product's (product config affiliate_payout percentage|fixed|none, affiliate_amount, affiliate_onetime), then
 *   the default. One-time pays only the order's first commission row. A percentage is converted to the
 *   affiliate's currency at payment time; a fixed amount pays the same number in any currency.
 * - Pending until the clearing days pass (0: cleared at once); the daily job then clears it while the service is
 *   active, cancels it once the service is canceled, suspended or gone, and holds it while it waits for setup,
 *   cancelling it when the service is still not active after another clearing period.
 * - A refund reaching the invoice total, a partial refund staff choose to reverse, and every chargeback reverse
 *   all the invoice's commissions (Invoice\Service::refundInvoice(), chargeback()): pending ones are cancelled,
 *   cleared ones get one negative reversal row (the balance may go below zero; later commissions pay it off).
 */
final class Commissions
{
    private const STATE = 'affiliates_daily';
    /** How far back the daily job looks for paid invoices whose commission was not written. */
    private const LOOKBACK_DAYS = 7;
    private const CLEARS = ['active', 'failed_renew'];
    private const HOLDS = ['pending_setup', 'failed_setup'];

    /** The commissions a paid invoice earns; writing one twice does nothing. */
    public static function onPaid(\Pimple\Container $di, int $invoiceId): void
    {
        $settings = Affiliates::settings($di);
        if (!$settings['enabled']) {
            return;
        }
        $db = $di['dbal'];
        $invoice = $db->fetchAssociative("SELECT id, currency FROM invoice WHERE id = ? AND status = 'paid'", [$invoiceId]);
        if ($invoice === false) {
            return;
        }
        $referrals = $db->fetchAllAssociative(
            "SELECT r.affiliate_id, r.client_id, r.client_order_id FROM client_affiliate_referral r JOIN client_affiliate a ON a.id = r.affiliate_id
             WHERE a.status = 'active' AND r.client_order_id IN (SELECT rel_id FROM invoice_item WHERE invoice_id = ? AND type = 'order')",
            [$invoiceId],
        );
        foreach ($referrals as $referral) {
            Tools::withLock($di, 'fb:affiliate:' . $referral['affiliate_id'], static fn () => self::earn($di, $settings, $invoice, $referral));
        }
    }

    /**
     * The rule for an order: [type, amount, one-time], or null for no commission.
     *
     * @return array{0: string, 1: float, 2: bool}|null
     */
    public static function rule(array $settings, array $affiliate, array $product): ?array
    {
        $payout = (string) ($product['affiliate_payout'] ?? '');
        if ($payout === 'none') {
            return null;
        }
        if ($affiliate['commission_type'] !== null) {
            return [(string) $affiliate['commission_type'], (float) $affiliate['commission_amount'], (bool) $affiliate['onetime']];
        }
        if (in_array($payout, Affiliates::TYPES, true)) {
            return [$payout, (float) ($product['affiliate_amount'] ?? 0), Tools::normalizeBoolean($product['affiliate_onetime'] ?? false)];
        }

        return [$settings['type'], $settings['amount'], $settings['onetime']];
    }

    /** Takes back every commission of the invoice (see the class comment); a second call changes nothing. */
    public static function reverse(\Pimple\Container $di, int $invoiceId): void
    {
        $db = $di['dbal'];
        $affiliates = $db->fetchFirstColumn("SELECT DISTINCT affiliate_id FROM client_affiliate_entry WHERE invoice_id = ? AND type = 'commission' AND status <> 'cancelled'", [$invoiceId]);
        foreach ($affiliates as $affiliateId) {
            Tools::withLock($di, 'fb:affiliate:' . $affiliateId, static function () use ($di, $db, $invoiceId, $affiliateId): void {
                $rows = $db->fetchAllAssociative(
                    "SELECT e.*, a.client_id AS affiliate_client_id FROM client_affiliate_entry e JOIN client_affiliate a ON a.id = e.affiliate_id
                     WHERE e.invoice_id = ? AND e.affiliate_id = ? AND e.type = 'commission' AND e.status <> 'cancelled'",
                    [$invoiceId, $affiliateId],
                );
                foreach ($rows as $row) {
                    $now = date('Y-m-d H:i:s');
                    if ($row['status'] === 'pending') {
                        $db->update('client_affiliate_entry', ['status' => 'cancelled', 'updated_at' => $now], ['id' => $row['id'], 'status' => 'pending']);
                    } elseif (($done = $db->fetchAssociative('SELECT id, amount FROM client_affiliate_entry WHERE reverses_id = ?', [$row['id']])) === false) {
                        $db->insert('client_affiliate_entry', [
                            'affiliate_id' => $affiliateId, 'type' => 'reversal', 'status' => 'cleared', 'amount' => -(float) $row['amount'], 'currency' => $row['currency'],
                            'client_id' => $row['client_id'], 'client_order_id' => $row['client_order_id'], 'reverses_id' => $row['id'], 'created_at' => $now, 'updated_at' => $now,
                        ]);
                    } elseif (round((float) $done['amount'] + (float) $row['amount'], 2) > 0) {
                        // A downgrade credit took back a share (credited()): the refund takes the rest.
                        $db->update('client_affiliate_entry', ['amount' => -(float) $row['amount'], 'updated_at' => $now], ['id' => $done['id']]);
                    } else {
                        continue;
                    }
                    Affiliates::log($di, (int) $row['affiliate_client_id'], sprintf('Affiliate commission #%d reversed: invoice #%d was refunded', $row['id'], $invoiceId));
                }
            });
        }
    }

    /**
     * A downgrade gave $credit of the service's unused time back as account credit (Order/Upgrades.php): the commission of
     * the invoice that paid the current period gives back the same share, so money returned never earns twice.
     */
    public static function credited(\Pimple\Container $di, int $orderId, float $credit): void
    {
        $db = $di['dbal'];
        $invoiceId = $db->fetchOne(
            "SELECT MAX(ii.invoice_id) FROM invoice_item ii JOIN invoice i ON i.id = ii.invoice_id WHERE ii.type = 'order' AND ii.rel_id = ? AND i.status = 'paid'",
            [(string) $orderId],
        );
        $affiliateId = $invoiceId ? $db->fetchOne("SELECT affiliate_id FROM client_affiliate_entry WHERE invoice_id = ? AND client_order_id = ? AND type = 'commission' AND status <> 'cancelled'", [$invoiceId, $orderId]) : false;
        if ($affiliateId === false || $credit <= 0) {
            return;
        }
        Tools::withLock($di, 'fb:affiliate:' . $affiliateId, static function () use ($di, $db, $invoiceId, $orderId, $credit): void {
            $row = $db->fetchAssociative(
                "SELECT e.*, a.client_id AS affiliate_client_id FROM client_affiliate_entry e JOIN client_affiliate a ON a.id = e.affiliate_id
                 WHERE e.invoice_id = ? AND e.client_order_id = ? AND e.type = 'commission' AND e.status <> 'cancelled'",
                [$invoiceId, $orderId],
            );
            if ($row === false) {
                return;
            }
            $amount = (float) $row['amount'];
            // Imported commissions have no stored base: the invoice's lines for the service, as earn() counts it.
            $base = (float) ($row['source_amount'] ?? $db->fetchOne("SELECT COALESCE(SUM(price * quantity), 0) FROM invoice_item WHERE invoice_id = ? AND rel_id = ? AND type IN ('order', 'custom')", [$invoiceId, (string) $orderId]));
            $done = $row['status'] === 'cleared' ? $db->fetchAssociative('SELECT id, amount FROM client_affiliate_entry WHERE reverses_id = ?', [$row['id']]) : false;
            // The commission per unit of the base it was earned on; a pending row's base shrinks with it, so later credits stay in proportion.
            $take = min(round($amount + ($done === false ? 0.0 : (float) $done['amount']), 2), $base > 0 ? round($amount * $credit / $base, 2) : $amount);
            if ($take <= 0) {
                return;
            }
            $now = date('Y-m-d H:i:s');
            if ($row['status'] === 'pending') {
                $left = round($amount - $take, 2);
                $db->update('client_affiliate_entry', ['amount' => $left, 'source_amount' => max(0.0, round($base - $credit, 2)), 'updated_at' => $now] + ($left <= 0 ? ['status' => 'cancelled'] : []), ['id' => $row['id'], 'status' => 'pending']);
            } elseif ($done === false) {
                $db->insert('client_affiliate_entry', [
                    'affiliate_id' => $row['affiliate_id'], 'type' => 'reversal', 'status' => 'cleared', 'amount' => -$take, 'currency' => $row['currency'],
                    'client_id' => $row['client_id'], 'client_order_id' => $orderId, 'reverses_id' => $row['id'], 'created_at' => $now, 'updated_at' => $now,
                ]);
            } else {
                $db->update('client_affiliate_entry', ['amount' => round((float) $done['amount'] - $take, 2), 'updated_at' => $now], ['id' => $done['id']]);
            }
            Affiliates::log($di, (int) $row['affiliate_client_id'], sprintf('Affiliate commission #%d reduced by %s: order #%d was downgraded with credit back', $row['id'], number_format($take, 2), $orderId));
        });
    }

    /** Clientfence\Service::onAfterAdminCronRun(), once a day: missing commissions, then clearing. */
    public static function daily(\Pimple\Container $di): void
    {
        $settings = Affiliates::settings($di);
        if (!$settings['enabled']) {
            return;
        }
        $db = $di['dbal'];
        $today = date('Y-m-d');
        $day = $db->fetchOne('SELECT value FROM mod_clientfence_state WHERE name = ?', [self::STATE]);
        if ($day === $today) {
            return;
        }
        $day === false
            ? $db->insert('mod_clientfence_state', ['name' => self::STATE, 'value' => $today, 'updated_at' => date('Y-m-d H:i:s')])
            : $db->update('mod_clientfence_state', ['value' => $today, 'updated_at' => date('Y-m-d H:i:s')], ['name' => self::STATE]);

        // A staff assignment earns on later payments only, so it is not filled in for earlier ones here; nor is a payment made
        // before the affiliate or the program switch last changed (it may have been paid while earning was off).
        $missing = $db->fetchAllAssociative(
            "SELECT DISTINCT i.id, i.currency, r.affiliate_id, r.client_id, r.client_order_id FROM invoice i
             JOIN invoice_item ii ON ii.invoice_id = i.id AND ii.type = 'order'
             JOIN client_affiliate_referral r ON r.client_order_id = ii.rel_id AND r.source <> 'staff'
             JOIN client_affiliate a ON a.id = r.affiliate_id AND a.status = 'active'
             LEFT JOIN client_affiliate_entry e ON e.invoice_id = i.id AND e.client_order_id = r.client_order_id
             WHERE i.status = 'paid' AND i.paid_at >= ? AND i.paid_at >= a.updated_at AND e.id IS NULL
               AND i.paid_at >= (SELECT COALESCE(MAX(s.updated_at), '1970-01-01') FROM setting s WHERE s.param = 'affiliate_enabled')",
            [date('Y-m-d H:i:s', time() - self::LOOKBACK_DAYS * 86400)],
        );
        foreach ($missing as $row) {
            try {
                Tools::withLock($di, 'fb:affiliate:' . $row['affiliate_id'], static fn () => self::earn($di, $settings, ['id' => $row['id'], 'currency' => $row['currency']], $row));
            } catch (\Throwable $e) {
                $di['logger']->error('Affiliates: the commission of invoice #%s was not written: %s', $row['id'], $e->getMessage());
            }
        }

        $due = $db->fetchFirstColumn("SELECT DISTINCT affiliate_id FROM client_affiliate_entry WHERE type = 'commission' AND status = 'pending' AND clears_at <= ?", [date('Y-m-d H:i:s')]);
        foreach ($due as $affiliateId) {
            try {
                Tools::withLock($di, 'fb:affiliate:' . $affiliateId, static fn () => self::clear($di, (int) $affiliateId, $settings['clearing_days']));
            } catch (\Throwable $e) {
                $di['logger']->error('Affiliates: commissions of affiliate #%s were not cleared: %s', $affiliateId, $e->getMessage());
            }
        }
    }

    /** Under the affiliate's lock: one commission row for the order on the paid invoice. */
    private static function earn(\Pimple\Container $di, array $settings, array $invoice, array $referral): void
    {
        $db = $di['dbal'];
        $orderId = (int) $referral['client_order_id'];
        if ($db->fetchOne('SELECT 1 FROM client_affiliate_entry WHERE invoice_id = ? AND client_order_id = ?', [$invoice['id'], $orderId]) !== false) {
            return;
        }
        $affiliate = $db->fetchAssociative('SELECT a.*, c.currency FROM client_affiliate a JOIN client c ON c.id = a.client_id WHERE a.id = ?', [$referral['affiliate_id']]);
        $product = $db->fetchOne('SELECT p.config FROM client_order o JOIN product p ON p.id = o.product_id WHERE o.id = ?', [$orderId]);
        $rule = self::rule($settings, $affiliate, json_decode((string) $product, true) ?: []);
        if ($rule === null || ($rule[2] && $db->fetchOne("SELECT 1 FROM client_affiliate_entry WHERE client_order_id = ? AND type = 'commission'", [$orderId]) !== false)) {
            return;
        }
        $base = round((float) $db->fetchOne("SELECT COALESCE(SUM(price * quantity), 0) FROM invoice_item WHERE invoice_id = ? AND rel_id = ? AND type IN ('order', 'custom')", [$invoice['id'], (string) $orderId]), 2);
        // A fixed commission ignores promotions (WHMCS): a fully discounted service still earns it, but only on an invoice
        // that took money (a 0.00 renewal of a free plan or a 100% recurring promo pays nothing).
        if ($base < 0 || round((float) $db->fetchOne('SELECT COALESCE(SUM(price * quantity), 0) FROM invoice_item WHERE invoice_id = ?', [$invoice['id']]), 2) <= 0) {
            return;
        }
        [$type, $amount] = $rule;
        $rate = $type === 'percentage' ? Affiliates::rate($db, (string) $invoice['currency'], (string) $affiliate['currency']) : 1.0;
        $earned = round($type === 'percentage' ? $base * $amount / 100 * $rate : $amount, 2);
        if ($earned <= 0) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $db->insert('client_affiliate_entry', [
            'affiliate_id' => $affiliate['id'], 'type' => 'commission', 'status' => $settings['clearing_days'] === 0 ? 'cleared' : 'pending',
            'amount' => $earned, 'currency' => $affiliate['currency'], 'source_amount' => $base, 'source_currency' => $invoice['currency'], 'rate' => $rate,
            'client_id' => $referral['client_id'], 'client_order_id' => $orderId, 'invoice_id' => $invoice['id'],
            'clears_at' => date('Y-m-d H:i:s', time() + $settings['clearing_days'] * 86400), 'created_at' => $now, 'updated_at' => $now,
        ]);
        Affiliates::log($di, (int) $affiliate['client_id'], sprintf('Affiliate commission %s %s on invoice #%d', number_format($earned, 2), $affiliate['currency'], $invoice['id']));
    }

    /** Under the affiliate's lock: its pending commissions whose clearing days have passed. */
    private static function clear(\Pimple\Container $di, int $affiliateId, int $days): void
    {
        $db = $di['dbal'];
        $now = date('Y-m-d H:i:s');
        $rows = $db->fetchAllAssociative(
            "SELECT e.id, e.hold_until, o.status, a.client_id FROM client_affiliate_entry e JOIN client_affiliate a ON a.id = e.affiliate_id
             LEFT JOIN client_order o ON o.id = e.client_order_id
             WHERE e.affiliate_id = ? AND e.type = 'commission' AND e.status = 'pending' AND e.clears_at <= ?",
            [$affiliateId, $now],
        );
        foreach ($rows as $row) {
            $holds = in_array($row['status'], self::HOLDS, true);
            if (in_array($row['status'], self::CLEARS, true)) {
                $set = ['status' => 'cleared'];
            } elseif ($holds && $row['hold_until'] === null) {
                $set = ['hold_until' => date('Y-m-d H:i:s', time() + $days * 86400)];
            } elseif ($holds && $row['hold_until'] > $now) {
                continue;
            } else {
                $set = ['status' => 'cancelled'];
            }
            // A client deletion may have cancelled it meanwhile (Affiliates::clientRemoved(), outside this lock).
            $db->update('client_affiliate_entry', $set + ['updated_at' => $now], ['id' => $row['id'], 'status' => 'pending']);
            if (isset($set['status'])) {
                Affiliates::log($di, (int) $row['client_id'], sprintf('Affiliate commission #%d %s', $row['id'], $set['status']));
            }
        }
    }
}
