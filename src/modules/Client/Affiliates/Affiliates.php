<?php

declare(strict_types=1);

namespace ClientFence\Mod\Client\Affiliates;

use ClientFence\InformationException;
use ClientFence\PaginationOptions;
use ClientFence\Tools;
use ClientFence\Mod\Client\Users;
use Doctrine\DBAL\Connection;

/**
 * ClientFence: the affiliate system, as WHMCS. A client becomes an affiliate (staff, or the client when the
 * setting allows it), shares a link with a random code (Link.php), and earns a commission on each paid invoice
 * of the orders it referred (Commissions.php), cleared after the clearing days and paid out on request
 * (Payouts.php). Money is a ledger (client_affiliate_entry), never a stored balance: balances are sums. Amounts
 * are in the affiliate's (client's) currency, which is locked once the affiliate has ledger rows
 * (Client\Service::canChangeCurrency()). Everything that changes an affiliate's money runs under the lock
 * "fb:affiliate:<id>" and reads its state again inside it.
 */
final class Affiliates
{
    public const STATUSES = ['active', 'inactive'];
    public const TYPES = ['percentage', 'fixed'];

    /**
     * The settings (system params affiliate_*; the amounts in the default currency).
     *
     * @return array{enabled: bool, type: string, amount: float, onetime: bool, bonus: float, minimum: float, clearing_days: int, cookie_days: int, signup_text: string, join: bool}
     */
    public static function settings(\Pimple\Container $di): array
    {
        $system = $di['mod_service']('system');
        $get = static fn (string $key, mixed $default): mixed => $system->getParamValue('affiliate_' . $key, $default);

        return [
            'enabled' => Tools::normalizeBoolean($get('enabled', false)),
            'type' => $get('type', 'percentage') === 'fixed' ? 'fixed' : 'percentage',
            'amount' => max(0.0, (float) $get('amount', 0)),
            'onetime' => Tools::normalizeBoolean($get('onetime', false)),
            'bonus' => max(0.0, (float) $get('bonus', 0)),
            'minimum' => max(0.0, (float) $get('min_payout', 0)),
            'clearing_days' => max(0, (int) $get('clearing_days', 30)),
            'cookie_days' => max(1, (int) $get('cookie_days', 90)),
            'signup_text' => (string) $get('signup_text', ''),
            'join' => Tools::normalizeBoolean($get('join', true), true),
        ];
    }

    /** @return array<string, mixed>|false */
    public static function forClient(Connection $db, int $clientId): array|false
    {
        return $db->fetchAssociative('SELECT a.*, c.currency FROM client_affiliate a JOIN client c ON c.id = a.client_id WHERE a.client_id = ?', [$clientId]);
    }

    /** What $to units one $from unit is worth, at today's rates (Currency settings). */
    public static function rate(Connection $db, string $from, string $to): float
    {
        if ($from === $to) {
            return 1.0;
        }
        $rates = $db->fetchAllKeyValue('SELECT code, conversion_rate FROM currency WHERE code IN (?, ?)', [$from, $to]);
        if ((float) ($rates[$from] ?? 0) <= 0 || (float) ($rates[$to] ?? 0) <= 0) {
            throw new \ClientFence\Exception('Currency rate for :code is not configured.', [':code' => (float) ($rates[$from] ?? 0) <= 0 ? $from : $to]);
        }

        return (float) $rates[$to] / (float) $rates[$from];
    }

    /** A setting amount (default currency) in $currency. */
    public static function fromDefault(Connection $db, float $amount, string $currency): float
    {
        return round($amount * self::rate($db, (string) $db->fetchOne('SELECT code FROM currency WHERE is_default = 1'), $currency), 2);
    }

    /**
     * Makes the client an affiliate, or activates it again (staff only: a client staff turned off cannot turn
     * itself on). A new affiliate gets a random code, the default currency when the client has none, and the
     * bonus deposit as its first ledger row.
     */
    public static function activate(\Pimple\Container $di, int $clientId, ?int $adminId): void
    {
        $db = $di['dbal'];
        $existing = self::forClient($db, $clientId);
        if ($existing !== false) {
            if ($existing['status'] !== 'active') {
                if ($adminId === null) {
                    throw new InformationException('Your affiliate account is not active. Please contact us.');
                }
                $db->update('client_affiliate', ['status' => 'active', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $existing['id']]);
                self::log($di, $clientId, 'Affiliate account activated again', $adminId);
            }

            return;
        }
        $client = $db->fetchAssociative('SELECT id, currency FROM client WHERE id = ?', [$clientId]);
        if ($client === false) {
            throw new InformationException('Client not found');
        }
        $default = (string) $db->fetchOne('SELECT code FROM currency WHERE is_default = 1');
        $currency = (string) $client['currency'] ?: $default;
        $settings = self::settings($di);
        $now = date('Y-m-d H:i:s');
        $db->transactional(static function (Connection $db) use ($client, $clientId, $currency, $default, $settings, $now, $adminId): void {
            if (!$client['currency']) {
                $db->update('client', ['currency' => $currency, 'updated_at' => $now], ['id' => $clientId]);
            }
            $db->insert('client_affiliate', ['client_id' => $clientId, 'code' => bin2hex(random_bytes(6)), 'status' => 'active', 'onetime' => 0, 'created_at' => $now, 'updated_at' => $now]);
            $id = (int) $db->lastInsertId();
            $rate = self::rate($db, $default, $currency);
            $bonus = round($settings['bonus'] * $rate, 2);
            if ($bonus > 0) {
                $db->insert('client_affiliate_entry', [
                    'affiliate_id' => $id, 'type' => 'bonus', 'status' => 'cleared', 'amount' => $bonus, 'currency' => $currency,
                    'source_amount' => $settings['bonus'], 'source_currency' => $default, 'rate' => $rate, 'admin_id' => $adminId,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        });
        self::log($di, $clientId, 'Affiliate account activated', $adminId);
    }

    /**
     * Staff changes: `status` (active, inactive) and the affiliate's own commission (`commission_type` percentage
     * or fixed, '' for the defaults; `commission_amount`; `onetime`).
     */
    public static function update(\Pimple\Container $di, int $clientId, array $data, int $adminId): void
    {
        $db = $di['dbal'];
        $affiliate = self::forClient($db, $clientId) ?: throw new InformationException('This client is not an affiliate.');
        $set = [];
        if (array_key_exists('status', $data)) {
            $set['status'] = in_array($data['status'], self::STATUSES, true) ? $data['status'] : throw new InformationException('Unknown affiliate status.');
        }
        if (array_key_exists('commission_type', $data)) {
            $type = (string) $data['commission_type'];
            $set['commission_type'] = $type === '' ? null : (in_array($type, self::TYPES, true) ? $type : throw new InformationException('Unknown commission type.'));
        }
        if (array_key_exists('commission_amount', $data)) {
            if (!is_numeric($data['commission_amount']) || (float) $data['commission_amount'] < 0) {
                throw new InformationException('The commission amount must be a number of zero or more.');
            }
            $set['commission_amount'] = round((float) $data['commission_amount'], 2);
        }
        if (array_key_exists('onetime', $data)) {
            $set['onetime'] = (int) Tools::normalizeBoolean($data['onetime']);
        }
        if ($set === []) {
            return;
        }
        $db->update('client_affiliate', $set + ['updated_at' => date('Y-m-d H:i:s')], ['id' => $affiliate['id']]);
        self::log($di, $clientId, 'Affiliate settings changed', $adminId);
    }

    /**
     * The balances, from the ledger: pending, available (cleared rows less the open payout request; the bonus
     * counts once the affiliate has a cleared commission), held (that bonus until then), requested, paid out.
     *
     * @return array{pending: float, available: float, held: float, requested: float, paid_out: float}
     */
    public static function balances(Connection $db, int $affiliateId): array
    {
        $sum = static fn (string $where): float => round((float) $db->fetchOne('SELECT COALESCE(SUM(amount), 0) FROM client_affiliate_entry WHERE affiliate_id = ? AND ' . $where, [$affiliateId]), 2);
        $bonusFree = $db->fetchOne("SELECT 1 FROM client_affiliate_entry WHERE affiliate_id = ? AND type = 'commission' AND status = 'cleared'", [$affiliateId]) !== false;
        $held = $bonusFree ? 0.0 : $sum("type = 'bonus' AND status = 'cleared'");
        $requested = round((float) $db->fetchOne("SELECT COALESCE(SUM(amount), 0) FROM client_affiliate_payout WHERE affiliate_id = ? AND status = 'requested'", [$affiliateId]), 2);

        return [
            'pending' => $sum("status = 'pending'"),
            'available' => round($sum("status = 'cleared'") - $held - $requested, 2),
            'held' => $held,
            'requested' => $requested,
            'paid_out' => 0.0 - $sum("type = 'payout'"), // not -0.0, which shows as "-$0.00"
        ];
    }

    /** The affiliate account of a client with its link, counts and balances, or null. */
    public static function get(\Pimple\Container $di, int $clientId): ?array
    {
        $db = $di['dbal'];
        $affiliate = self::forClient($db, $clientId);
        if ($affiliate === false) {
            return null;
        }
        $id = (int) $affiliate['id'];
        $request = $db->fetchAssociative("SELECT id, amount, currency, created_at FROM client_affiliate_payout WHERE affiliate_id = ? AND status = 'requested'", [$id]);
        $balances = self::balances($db, $id);
        $minimum = self::fromDefault($db, self::settings($di)['minimum'], (string) $affiliate['currency']);

        return [
            'id' => $id,
            'code' => $affiliate['code'],
            'link' => $di['url']->link('/', ['aff' => $affiliate['code']]),
            'status' => $affiliate['status'],
            'currency' => $affiliate['currency'],
            'commission_type' => $affiliate['commission_type'],
            'commission_amount' => $affiliate['commission_amount'],
            'onetime' => (bool) $affiliate['onetime'],
            'visitors' => (int) $db->fetchOne('SELECT COALESCE(SUM(hits), 0) FROM client_affiliate_visit WHERE affiliate_id = ?', [$id]),
            'signups' => (int) $db->fetchOne('SELECT COUNT(*) FROM client_affiliate_referral WHERE affiliate_id = ?', [$id]),
            'minimum' => $minimum,
            'request' => $request ?: null,
            'can_request' => $affiliate['status'] === 'active' && $request === false && $balances['available'] > 0 && $balances['available'] >= $minimum,
            'created_at' => $affiliate['created_at'],
        ] + $balances;
    }

    /** Staff: affiliates with visitors, signups and balances (`status` filters). */
    public static function adminList(\Pimple\Container $di, array $data): array
    {
        $sum = static fn (string $where): string => "(SELECT COALESCE(SUM(e.amount), 0) FROM client_affiliate_entry e WHERE e.affiliate_id = a.id AND {$where})";
        $sql = 'SELECT a.id, a.client_id, a.code, a.status, a.created_at, c.first_name, c.last_name, c.company, c.email, c.currency,
                (SELECT COALESCE(SUM(v.hits), 0) FROM client_affiliate_visit v WHERE v.affiliate_id = a.id) AS visitors,
                (SELECT COUNT(*) FROM client_affiliate_referral r WHERE r.affiliate_id = a.id) AS signups, '
            . $sum("e.status = 'pending'") . ' AS pending, ' . $sum("e.status = 'cleared'") . ' AS balance, -' . $sum("e.type = 'payout'") . " AS paid_out,
                " . $sum("e.status = 'cleared'") . " - (CASE WHEN EXISTS (SELECT 1 FROM client_affiliate_entry x WHERE x.affiliate_id = a.id AND x.type = 'commission' AND x.status = 'cleared') THEN 0 ELSE "
            . $sum("e.type = 'bonus' AND e.status = 'cleared'") . " END) - (SELECT COALESCE(SUM(p.amount), 0) FROM client_affiliate_payout p WHERE p.affiliate_id = a.id AND p.status = 'requested') AS available,
                (SELECT COUNT(*) FROM client_affiliate_payout p WHERE p.affiliate_id = a.id AND p.status = 'requested') AS requests
                FROM client_affiliate a JOIN client c ON c.id = a.client_id";
        $params = [];
        if (in_array($data['status'] ?? null, self::STATUSES, true)) {
            $sql .= ' WHERE a.status = ?';
            $params[] = $data['status'];
        }

        return $di['pager']->getPaginatedResultSet($sql . ' ORDER BY a.id DESC', $params, PaginationOptions::fromArray($data));
    }

    /** Staff: active affiliates matching `search` (name, company, email, code or client id) as id => label, for a picker. */
    public static function pairs(Connection $db, array $data): array
    {
        $search = trim(is_string($data['search'] ?? null) ? $data['search'] : '');
        $like = '%' . addcslashes($search, '%_\\') . '%';
        $limit = min(30, max(1, (int) ($data['per_page'] ?? 10)));
        $rows = $db->fetchAllAssociative(
            "SELECT a.id, a.code, c.first_name, c.last_name, c.company FROM client_affiliate a JOIN client c ON c.id = a.client_id
             WHERE a.status = 'active' AND (c.first_name LIKE ? OR c.last_name LIKE ? OR c.company LIKE ? OR c.email LIKE ? OR a.code LIKE ? OR a.client_id = ?)
             ORDER BY c.first_name, c.last_name LIMIT {$limit}",
            [$like, $like, $like, $like, $like, ctype_digit($search) ? (int) $search : 0],
        );
        $pairs = [];
        foreach ($rows as $row) {
            $pairs[(int) $row['id']] = trim($row['first_name'] . ' ' . $row['last_name']) . ($row['company'] ? ' (' . $row['company'] . ')' : '') . ' · ' . $row['code'];
        }

        return $pairs;
    }

    /** Staff: the affiliate that referred an order, with its client's name, or false. */
    public static function forOrder(Connection $db, int $orderId): array|false
    {
        return $db->fetchAssociative(
            'SELECT r.affiliate_id, r.source, r.created_at, a.client_id, a.code, a.status, c.first_name, c.last_name, c.company FROM client_affiliate_referral r
             JOIN client_affiliate a ON a.id = r.affiliate_id JOIN client c ON c.id = a.client_id WHERE r.client_order_id = ?',
            [$orderId],
        );
    }

    /** The orders an affiliate referred. Staff see the client and the order; the affiliate sees the product only. */
    public static function referrals(\Pimple\Container $di, int $affiliateId, array $data, bool $staff): array
    {
        $earned = "(SELECT COALESCE(SUM(e.amount), 0) FROM client_affiliate_entry e WHERE e.affiliate_id = r.affiliate_id AND e.client_order_id = r.client_order_id AND e.type IN ('commission', 'reversal') AND e.status <> 'cancelled') AS earned";
        $columns = $staff
            ? 'r.id, r.client_id, r.client_order_id, r.source, r.created_at, o.title, o.status, c.first_name, c.last_name, c.company, ' . $earned
            : 'r.created_at, p.title AS product, o.status, ' . $earned;

        return $di['pager']->getPaginatedResultSet(
            "SELECT {$columns} FROM client_affiliate_referral r LEFT JOIN client_order o ON o.id = r.client_order_id LEFT JOIN product p ON p.id = o.product_id
             LEFT JOIN client c ON c.id = r.client_id WHERE r.affiliate_id = ? ORDER BY r.id DESC",
            [$affiliateId],
            PaginationOptions::fromArray($data),
        );
    }

    /** The ledger. Staff also see the referred client, order, invoice and who made a manual row. */
    public static function entries(\Pimple\Container $di, int $affiliateId, array $data, bool $staff): array
    {
        $columns = 'e.id, e.type, e.status, e.amount, e.currency, e.note, e.clears_at, e.created_at'
            . ($staff ? ', e.source_amount, e.source_currency, e.rate, e.client_id, e.client_order_id, e.invoice_id, e.reverses_id, e.payout_id, e.admin_id, e.hold_until' : '');

        return $di['pager']->getPaginatedResultSet(
            "SELECT {$columns} FROM client_affiliate_entry e WHERE e.affiliate_id = ? ORDER BY e.id DESC",
            [$affiliateId],
            PaginationOptions::fromArray($data),
        );
    }

    /** Staff: a manual ledger row (positive or negative) with a note. */
    public static function manual(\Pimple\Container $di, int $clientId, mixed $amount, mixed $note, int $adminId): int
    {
        $db = $di['dbal'];
        $affiliate = self::forClient($db, $clientId) ?: throw new InformationException('This client is not an affiliate.');
        if (!is_numeric($amount) || round((float) $amount, 2) == 0) {
            throw new InformationException('Enter an amount other than zero.');
        }
        $note = trim(is_string($note) ? $note : '');
        if ($note === '') {
            throw new InformationException('A note is required.');
        }
        $amount = round((float) $amount, 2);

        return Tools::withLock($di, 'fb:affiliate:' . $affiliate['id'], static function () use ($di, $db, $affiliate, $clientId, $amount, $note, $adminId): int {
            $now = date('Y-m-d H:i:s');
            $db->insert('client_affiliate_entry', [
                'affiliate_id' => $affiliate['id'], 'type' => 'manual', 'status' => 'cleared', 'amount' => $amount, 'currency' => $affiliate['currency'],
                'admin_id' => $adminId, 'note' => mb_substr($note, 0, 255), 'created_at' => $now, 'updated_at' => $now,
            ]);
            $id = (int) $db->lastInsertId();
            self::log($di, $clientId, sprintf('Affiliate manual entry #%d: %s %s', $id, number_format($amount, 2), $affiliate['currency']), $adminId);

            return $id;
        });
    }

    /** Staff: cancels a commission that has not cleared yet. */
    public static function cancelPending(\Pimple\Container $di, int $entryId, int $adminId): void
    {
        $db = $di['dbal'];
        $row = $db->fetchAssociative("SELECT e.affiliate_id, a.client_id FROM client_affiliate_entry e JOIN client_affiliate a ON a.id = e.affiliate_id WHERE e.id = ? AND e.type = 'commission'", [$entryId]);
        if ($row === false) {
            throw new InformationException('Commission not found');
        }
        Tools::withLock($di, 'fb:affiliate:' . $row['affiliate_id'], static function () use ($di, $db, $row, $entryId, $adminId): void {
            if ($db->executeStatement("UPDATE client_affiliate_entry SET status = 'cancelled', admin_id = ?, updated_at = ? WHERE id = ? AND status = 'pending'", [$adminId, date('Y-m-d H:i:s'), $entryId]) === 0) {
                throw new InformationException('Only a pending commission can be cancelled.');
            }
            self::log($di, (int) $row['client_id'], sprintf('Affiliate commission #%d cancelled', $entryId), $adminId);
        });
    }

    /**
     * Client\Service::remove(), inside its transaction: a referred client's pending commissions are cancelled (cleared
     * ones stay, with their own amounts); an affiliate client's affiliate account goes with all its rows.
     */
    public static function clientRemoved(Connection $db, int $clientId): void
    {
        $db->executeStatement("UPDATE client_affiliate_entry SET status = 'cancelled', updated_at = ? WHERE client_id = ? AND type = 'commission' AND status = 'pending'", [date('Y-m-d H:i:s'), $clientId]);
        $id = $db->fetchOne('SELECT id FROM client_affiliate WHERE client_id = ?', [$clientId]);
        if ($id === false) {
            return;
        }
        foreach (['client_affiliate_entry', 'client_affiliate_payout', 'client_affiliate_visit', 'client_affiliate_referral'] as $table) {
            $db->delete($table, ['affiliate_id' => $id]);
        }
        $db->delete('client_affiliate', ['id' => $id]);
    }

    /** A line in the affiliate client's activity log. */
    public static function log(\Pimple\Container $di, int $clientId, string $message, ?int $adminId = null): void
    {
        Users::activity($di, [$clientId], $message, $adminId);
    }
}
