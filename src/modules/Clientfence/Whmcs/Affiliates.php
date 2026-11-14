<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Whmcs;

use Doctrine\DBAL\Connection;

/**
 * ClientFence: the WHMCS import's affiliate step (Run.php runs it after the money steps), into the affiliate
 * system (Client/Affiliates). Each WHMCS affiliate comes over with everything it holds in one go, so a batch
 * never leaves half an affiliate: its referred services (as referrals), pending commissions, commission history
 * and manual entries, withdrawals (as paid payouts) and visits per day.
 *
 * Its code is its WHMCS id, so published /aff.php?aff=<id> links keep working (a snapshot of another system
 * brings that system's code). WHMCS keeps the balance as a total staff can edit (and an old bonus deposit only
 * there): one "opening" ledger row per affiliate makes the available balance equal WHMCS's. Amounts are in the
 * affiliate's (client's) currency, as in WHMCS. WHMCS's affiliate settings come over when this install never
 * saved its own.
 */
final class Affiliates
{
    /** The steps in order: name => [label, WHMCS table read]. */
    public const STEPS = [
        'affiliates' => ['Affiliates', 'tblaffiliates'],
    ];

    /** WHMCS settings => affiliate settings (Client/Affiliates/Affiliates::settings()). */
    private const SETTINGS = [
        'AffiliateEnabled' => 'affiliate_enabled', 'AffiliateEarningPercent' => 'affiliate_amount', 'AffiliateBonusDeposit' => 'affiliate_bonus',
        'AffiliatePayout' => 'affiliate_min_payout', 'AffiliatesDelayCommission' => 'affiliate_clearing_days',
    ];

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        private readonly Source $source,
        private readonly Connection $db,
        private readonly Map $map,
        private readonly string $now,
    ) {
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function total(string $step): int
    {
        return $this->source->count(self::STEPS[$step][1] ?? throw new \InvalidArgumentException('Unknown step ' . $step));
    }

    /**
     * Imports up to $limit affiliates after the WHMCS id $after: the last id read (null: done) and how many were
     * imported.
     *
     * @return array{0: ?int, 1: int}
     */
    public function batch(string $step, int $after, int $limit): array
    {
        $table = self::STEPS[$step][1] ?? throw new \InvalidArgumentException('Unknown step ' . $step);
        if (!$this->source->hasTable($table)) {
            return [null, 0];
        }
        if (!$this->map->has('notice', 'affiliatesettings')) {
            $this->settings();
        }
        $rows = $this->source->rows('SELECT * FROM ' . $table . ' WHERE id > ? ORDER BY id LIMIT ' . $limit, [$after]);
        if ($rows === []) {
            return [null, 0];
        }

        return [(int) end($rows)['id'], array_sum(array_map($this->affiliate(...), $rows))];
    }

    /** WHMCS's affiliate settings, once, unless this install saved its own. */
    private function settings(): void
    {
        $this->map->put('notice', 'affiliatesettings', 0);
        if (!$this->source->hasTable('tblconfiguration') || $this->db->fetchOne("SELECT 1 FROM setting WHERE SUBSTR(param, 1, 10) = 'affiliate_'") !== false) {
            return;
        }
        $whmcs = [];
        foreach ($this->source->rows('SELECT setting, value FROM tblconfiguration WHERE setting IN (?, ?, ?, ?, ?)', array_keys(self::SETTINGS)) as $row) {
            $whmcs[(string) $row['setting']] = trim((string) $row['value']);
        }
        foreach ($whmcs as $name => $value) {
            $value = match ($name) {
                'AffiliateEnabled' => in_array(strtolower($value), ['on', '1', 'yes'], true) ? '1' : '0',
                'AffiliatesDelayCommission' => (string) max(0, (int) $value),
                default => number_format(max(0.0, (float) $value), 2, '.', ''),
            };
            $this->writeSetting(self::SETTINGS[$name], $value);
        }
        // WHMCS's default commission is a percentage.
        if (isset($whmcs['AffiliateEarningPercent'])) {
            $this->writeSetting('affiliate_type', 'percentage');
        }
    }

    /** @param array<string, mixed> $row a tblaffiliates row */
    private function affiliate(array $row): int
    {
        $id = (int) $row['id'];
        if ($this->map->has('affiliate', $id) || $this->map->has('skipped', 'affiliate:' . $id)) {
            return 0;
        }
        $clientId = $this->map->get('client', (int) ($row['clientid'] ?? 0));
        $client = $clientId === null ? false : $this->db->fetchAssociative('SELECT id, currency FROM client WHERE id = ?', [$clientId]);
        if ($client === false || $this->db->fetchOne('SELECT 1 FROM client_affiliate WHERE client_id = ?', [$clientId]) !== false) {
            $this->map->put('skipped', 'affiliate:' . $id, 0);
            $this->warn(sprintf($client === false ? 'Affiliate #%d was not imported: its client #%d was not imported.' : 'Affiliate #%d was not imported: client #%d already has an affiliate account.', $id, (int) ($row['clientid'] ?? 0)));

            return 0;
        }
        $currency = (string) $client['currency'];
        if ($currency === '') {
            $currency = (string) $this->db->fetchOne('SELECT code FROM currency WHERE is_default = 1 ORDER BY id');
            $this->db->update('client', ['currency' => $currency], ['id' => $clientId]);
        }
        $code = trim((string) ($row['code'] ?? '')) ?: (string) $id;
        if (preg_match('/^[A-Za-z0-9_-]{1,62}={0,2}$/D', $code) !== 1 || $this->db->fetchOne('SELECT 1 FROM client_affiliate WHERE code = ?', [$code]) !== false) {
            $this->warn(sprintf('Affiliate #%d: its code "%s" is not valid here or is already taken; it got a new code, so its old links no longer work.', $id, mb_substr($code, 0, 64)));
            $code = bin2hex(random_bytes(6));
        }
        // WHMCS's forms store a fixed commission as "fixedamount".
        $type = str_replace('fixedamount', 'fixed', strtolower(trim((string) ($row['paytype'] ?? ''))));
        $type = in_array($type, ['percentage', 'fixed'], true) ? $type : null;
        $created = Accounts::date($row['date'] ?? null) ?? $this->now;
        // updated_at is now: the daily job only earns on invoices paid after it (Commissions::daily()), never on imported ones.
        $this->db->insert('client_affiliate', [
            'client_id' => $clientId, 'code' => $code, 'status' => ($row['status'] ?? '') === 'inactive' ? 'inactive' : 'active',
            'commission_type' => $type, 'commission_amount' => $type === null ? null : number_format(max(0.0, (float) ($row['payamount'] ?? 0)), 2, '.', ''),
            'onetime' => $type !== null && (int) ($row['onetime'] ?? 0) === 1 ? 1 : 0, 'created_at' => $created, 'updated_at' => $this->now,
        ]);
        $affiliateId = (int) $this->db->lastInsertId();
        $entry = function (array $values) use ($affiliateId, $currency): void {
            $this->db->insert('client_affiliate_entry', $values + ['affiliate_id' => $affiliateId, 'status' => 'cleared', 'currency' => $currency, 'updated_at' => $this->now]);
        };

        // Referred services: WHMCS account id => [the imported order, its client].
        $orders = [];
        $missing = 0;
        foreach ($this->source->hasTable('tblaffiliatesaccounts') ? $this->source->rows('SELECT * FROM tblaffiliatesaccounts WHERE affiliateid = ? ORDER BY id', [$id]) : [] as $account) {
            $orderId = $this->map->get('service', (int) $account['relid']);
            $order = $orderId === null ? false : $this->db->fetchAssociative('SELECT id, client_id FROM client_order WHERE id = ?', [$orderId]);
            if ($order === false) {
                ++$missing;

                continue;
            }
            $orders[(int) $account['id']] = [(int) $order['id'], (int) $order['client_id']];
            if ($this->db->fetchOne('SELECT 1 FROM client_affiliate_referral WHERE client_order_id = ?', [$orderId]) !== false) {
                $this->warn(sprintf('Service #%d was referred by more than one affiliate in WHMCS: it stays with the first; affiliate #%d keeps the commissions it earned on it.', (int) $account['relid'], $id));

                continue;
            }
            $this->db->insert('client_affiliate_referral', [
                'affiliate_id' => $affiliateId, 'client_id' => $order['client_id'], 'client_order_id' => $order['id'], 'source' => 'import',
                'created_at' => Accounts::date($account['created_at'] ?? null) ?? $created,
            ]);
        }

        if ($this->source->hasTable('tblaffiliatespending') && $this->source->hasTable('tblaffiliatesaccounts')) {
            foreach ($this->source->rows('SELECT p.* FROM tblaffiliatespending p JOIN tblaffiliatesaccounts a ON a.id = p.affaccid WHERE a.affiliateid = ? ORDER BY p.id', [$id]) as $pending) {
                // Clearing needs the service (Commissions::clear()): without it the commission is left out.
                if (!isset($orders[(int) $pending['affaccid']])) {
                    ++$missing;

                    continue;
                }
                [$orderId, $referredId] = $orders[(int) $pending['affaccid']];
                $entry([
                    'type' => 'commission', 'status' => 'pending', 'amount' => self::money($pending['amount'] ?? 0), 'client_id' => $referredId, 'client_order_id' => $orderId,
                    'invoice_id' => $this->invoice((int) ($pending['invoice_id'] ?? 0), $orderId), 'clears_at' => Accounts::date($pending['clearingdate'] ?? null) ?? $this->now,
                    'created_at' => Accounts::date($pending['created_at'] ?? null) ?? $this->now,
                ]);
            }
        }
        // A snapshot's own: Blesta's pending commissions on domain-only orders clear on the domain (no referral).
        if ($this->source->hasTable('tblaffiliatespending') && in_array('domainid', $this->source->columns('tblaffiliatespending'), true)) {
            foreach ($this->source->rows('SELECT * FROM tblaffiliatespending WHERE affaccid = 0 AND affiliateid = ? AND domainid > 0 ORDER BY id', [$id]) as $pending) {
                $orderId = $this->map->get('domain', (int) $pending['domainid']);
                $order = $orderId === null ? false : $this->db->fetchAssociative('SELECT id, client_id FROM client_order WHERE id = ?', [$orderId]);
                if ($order === false) {
                    ++$missing;

                    continue;
                }
                $entry([
                    'type' => 'commission', 'status' => 'pending', 'amount' => self::money($pending['amount'] ?? 0), 'client_id' => (int) $order['client_id'], 'client_order_id' => (int) $order['id'],
                    'invoice_id' => $this->invoice((int) ($pending['invoice_id'] ?? 0), (int) $order['id']), 'clears_at' => Accounts::date($pending['clearingdate'] ?? null) ?? $this->now,
                    'created_at' => Accounts::date($pending['created_at'] ?? null) ?? $this->now,
                ]);
            }
        }

        foreach ($this->source->hasTable('tblaffiliateshistory') ? $this->source->rows('SELECT * FROM tblaffiliateshistory WHERE affiliateid = ? ORDER BY id', [$id]) : [] as $history) {
            [$orderId, $referredId] = $orders[(int) ($history['affaccid'] ?? 0)] ?? [null, null];
            $amount = self::money($history['amount'] ?? 0);
            // A commission names its referred service; WHMCS's manual entries and reversals do not.
            $entry([
                'type' => (int) ($history['affaccid'] ?? 0) > 0 && (float) $amount > 0 ? 'commission' : 'manual', 'amount' => $amount, 'client_id' => $referredId, 'client_order_id' => $orderId,
                'invoice_id' => $this->invoice((int) ($history['invoice_id'] ?? 0), $orderId), 'note' => mb_substr(trim((string) ($history['description'] ?? '')), 0, 255) ?: null,
                'created_at' => Accounts::date($history['date'] ?? null) ?? $this->now,
            ]);
        }

        foreach ($this->source->hasTable('tblaffiliateswithdrawals') ? $this->source->rows('SELECT * FROM tblaffiliateswithdrawals WHERE affiliateid = ? ORDER BY id', [$id]) : [] as $withdrawal) {
            $amount = self::money($withdrawal['amount'] ?? 0);
            $date = Accounts::date($withdrawal['date'] ?? null) ?? $this->now;
            $this->db->insert('client_affiliate_payout', [
                'affiliate_id' => $affiliateId, 'amount' => $amount, 'currency' => $currency, 'status' => 'paid', 'method' => 'outside', 'details' => 'Imported',
                'created_at' => $date, 'processed_at' => $date, 'updated_at' => $this->now,
            ]);
            $entry(['type' => 'payout', 'amount' => self::money(-(float) $amount), 'payout_id' => (int) $this->db->lastInsertId(), 'created_at' => $date]);
        }

        // WHMCS's balance is what the affiliate can withdraw: the opening row makes the ledger agree (an old bonus deposit, staff edits).
        $cleared = round((float) $this->db->fetchOne("SELECT COALESCE(SUM(amount), 0) FROM client_affiliate_entry WHERE affiliate_id = ? AND status = 'cleared'", [$affiliateId]), 2);
        $opening = round(round((float) ($row['balance'] ?? 0), 2) - $cleared, 2);
        if (abs($opening) >= 0.005) {
            $entry(['type' => 'opening', 'amount' => self::money($opening), 'created_at' => $created]);
        }

        $this->visits($affiliateId, $id, (int) ($row['visitors'] ?? 0), substr($created, 0, 10));
        if ($missing > 0) {
            $this->warn(sprintf('Affiliate #%d: %d referred services or pending commissions were not imported, because their services were not imported.', $id, $missing));
        }
        $this->map->put('affiliate', $id, $affiliateId);

        return 1;
    }

    /**
     * Visits per day (no referrer, as Link::countVisit()); visitors WHMCS counted before it logged each hit go on
     * the affiliate's first day, so the total stays WHMCS's.
     */
    private function visits(int $affiliateId, int $whmcsId, int $visitors, string $start): void
    {
        $days = [];
        if ($this->source->hasTable('tblaffiliates_hits')) {
            $count = in_array('hits', $this->source->columns('tblaffiliates_hits'), true) ? 'SUM(hits)' : 'COUNT(*)';
            foreach ($this->source->rows("SELECT SUBSTR(created_at, 1, 10) AS day, {$count} AS n FROM tblaffiliates_hits WHERE affiliate_id = ? GROUP BY SUBSTR(created_at, 1, 10)", [$whmcsId]) as $row) {
                $day = Accounts::date($row['day'] ?? null) === null ? $start : (string) $row['day'];
                $days[$day] = ($days[$day] ?? 0) + (int) $row['n'];
            }
        }
        $rest = $visitors - array_sum($days);
        if ($rest > 0) {
            $days[$start] = ($days[$start] ?? 0) + $rest;
        }
        foreach ($days as $day => $hits) {
            if ($hits > 0) {
                $this->db->insert('client_affiliate_visit', ['affiliate_id' => $affiliateId, 'day' => $day, 'hits' => $hits]);
            }
        }
    }

    /** The imported invoice of a commission, unless it has none or another row of that order holds it (one commission per invoice and order). */
    private function invoice(int $whmcsId, ?int $orderId): ?int
    {
        $invoiceId = $whmcsId > 0 ? $this->map->get('invoice', $whmcsId) : null;
        if ($invoiceId !== null && $orderId !== null && $this->db->fetchOne('SELECT 1 FROM client_affiliate_entry WHERE invoice_id = ? AND client_order_id = ?', [$invoiceId, $orderId]) !== false) {
            return null;
        }

        return $invoiceId;
    }

    private static function money(mixed $amount): string
    {
        return number_format(round((float) $amount, 2), 2, '.', '');
    }

    private function writeSetting(string $param, string $value): void
    {
        $this->db->insert('setting', ['param' => $param, 'value' => $value, 'public' => 0, 'created_at' => $this->now, 'updated_at' => $this->now]);
    }

    private function warn(string $message): void
    {
        $this->warnings[] = $message;
    }
}
