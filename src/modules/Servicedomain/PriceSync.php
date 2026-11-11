<?php

declare(strict_types=1);

namespace ClientFence\Mod\Servicedomain;

use ClientFence\InformationException;
use Doctrine\DBAL\Connection;

/**
 * ClientFence: TLD prices from a registrar's price list (Domain Registration > a registrar >
 * TLDs and prices).
 *
 * Staff get the registrar's cost of one year per action (Registrar_TldPrices), see old and new
 * prices for every TLD and apply only what they tick, as in BulkPrices: applying recomputes the
 * preview and refuses when anything changed since. A price is the cost converted to the default
 * currency with the store's own rate, plus the action's markup, rounded up: never below cost.
 *
 * Applied prices change what existing domains of that TLD pay at their next renewal (renewals use
 * the TLD's current price); unpaid invoices keep their amount. Fixed prices in other currencies
 * (Product/CurrencyPrices.php) are never touched. New TLDs are created inactive. TLDs marked
 * "Keep my price" (tld.price_locked) are left alone.
 *
 * Costs are kept per registrar and TLD in tld_registrar_cost: the list is reused for a day, and a
 * registrar that prices TLDs in slices continues where it stopped (state in mod_clientfence_state).
 * applied_* is the cost the store's price was last set from, applied_rate the exchange rate then.
 */
final class PriceSync
{
    /** Registrar action => tld price column. */
    public const ACTIONS = ['register' => 'price_registration', 'renew' => 'price_renew', 'transfer' => 'price_transfer'];
    public const MODES = ['off', 'notify', 'apply'];
    public const ROUNDINGS = ['', '.00', '.50', '.95', '.99'];
    public const MARKUPS = ['percent', 'fixed'];

    /** A price list younger than this is reused. */
    public const CACHE_SECONDS = 86400;
    public const MAX_PERCENT = 1000;
    public const MAX_AMOUNT = 100000;
    /** The scheduled sync leaves a price change above this share (0.5 = 50%) to staff. */
    public const MAX_JUMP = 0.5;

    public const CHANGE = 'change';
    public const SAME = 'same';
    public const NEW = 'new';
    public const LOCKED = 'locked';
    public const OTHER = 'other';
    public const NO_PRICE = 'no_price';

    /**
     * The registrar's price settings (tld_registrar.price_sync), with defaults.
     *
     * @return array{mode: string, markup: array<string, array{type: string, value: float}>, rounding: string}
     */
    public static function settings(\Pimple\Container $di, int $registrarId): array
    {
        $saved = json_decode((string) self::db($di)->fetchOne('SELECT price_sync FROM tld_registrar WHERE id = ?', [$registrarId]), true);

        return self::clean(is_array($saved) ? $saved : [], false);
    }

    /**
     * @param array<string, mixed> $data mode, markup[register|renew|transfer][type|value], rounding
     *
     * @return array{mode: string, markup: array<string, array{type: string, value: float}>, rounding: string}
     */
    public static function saveSettings(\Pimple\Container $di, int $registrarId, array $data): array
    {
        self::registrar($di, $registrarId);
        $settings = self::clean($data, true);
        self::db($di)->update('tld_registrar', ['price_sync' => json_encode($settings)], ['id' => $registrarId]);

        return $settings;
    }

    /**
     * A store price from a registrar cost: converted with $rate (registrar currency units per one
     * default unit, currency.conversion_rate), plus the markup, up to the cent and then up to the
     * rounding ending. Null when there is no usable cost.
     *
     * @param array{type: string, value: float} $markup
     */
    public static function price(?float $cost, float $rate, array $markup, string $rounding): ?float
    {
        if ($cost === null || !is_finite($cost) || $cost <= 0 || $rate <= 0) {
            return null;
        }
        $cents = $cost / $rate * 100;
        $cents = $markup['type'] === 'fixed' ? $cents + $markup['value'] * 100 : $cents * (1 + $markup['value'] / 100);
        // Round away float noise first, so 10.00 stays 1000 cents and is not pushed to 1001.
        $cents = (int) ceil(round($cents, 6));
        if ($rounding !== '') {
            $cents += ((int) substr($rounding, 1) - $cents % 100 + 100) % 100;
        }

        return (float) ($cents / 100);
    }

    /**
     * Gets the registrar's price list, for at most $seconds; call again until done. A list
     * younger than a day is reused unless $refresh.
     *
     * @return array{done: bool, priced: int, remaining: int, fetched_at: ?string}
     */
    public static function fetch(\Pimple\Container $di, int $registrarId, int $seconds, bool $refresh = false): array
    {
        return self::pull($di, $registrarId, 'tld_prices_' . $registrarId, [], $seconds, $refresh);
    }

    /**
     * fetch() for the page ($name tld_prices_ID, every TLD) and for the scheduled sync (tld_sync_ID,
     * only $only), each with its own progress.
     *
     * @param list<string> $only
     *
     * @return array{done: bool, priced: int, remaining: int, fetched_at: ?string}
     */
    private static function pull(\Pimple\Container $di, int $registrarId, string $name, array $only, int $seconds, bool $refresh): array
    {
        $db = self::db($di);
        $adapter = self::adapter($di, self::registrar($di, $registrarId));
        $state = self::state($db, $name);
        $fresh = $state['done_at'] !== null && time() - (int) strtotime($state['done_at']) < self::CACHE_SECONDS;
        if ($refresh || ($state['remaining'] === null && !$fresh)) {
            $state = ['since' => date('Y-m-d H:i:s'), 'remaining' => [], 'first' => true, 'done_at' => $state['done_at']];
        }

        $deadline = microtime(true) + $seconds;
        while ($state['remaining'] !== null) {
            $result = $adapter->getTldPrices($state['first'] ? $only : $state['remaining']);
            $prices = is_array($result['prices'] ?? null) ? $result['prices'] : [];
            $remaining = array_values(array_map('strval', is_array($result['remaining'] ?? null) ? $result['remaining'] : []));
            // No progress, or a finished run that priced nothing (TLDs with no price may fill a whole slice).
            if ($prices === [] && ($remaining === $state['remaining'] || ($remaining === [] && self::priced($db, $registrarId, $state['since']) === 0))) {
                throw new InformationException('The registrar returned no prices. Nothing was changed; try again later.');
            }
            $currency = strtoupper(trim((string) ($result['currency'] ?? '')));
            if (self::rate($db, $currency) === null) {
                throw new InformationException('The registrar charges in :code, which is not one of your currencies. Add :code under Currencies first: its rate converts the costs.', [':code' => $currency !== '' ? $currency : '?']);
            }
            // A sync that sees a new registrar currency makes the page's list stale: it is fetched again.
            if (self::store($di, $registrarId, $currency, $prices) && $name !== 'tld_prices_' . $registrarId) {
                $db->delete('mod_clientfence_state', ['name' => 'tld_prices_' . $registrarId]);
            }
            $state['first'] = false;
            $state['remaining'] = $remaining === [] ? null : $remaining;
            if ($state['remaining'] === null) {
                $state['done_at'] = date('Y-m-d H:i:s');
            }
            self::saveState($db, $name, $state);
            if (microtime(true) >= $deadline) {
                break;
            }
        }

        return [
            'done' => $state['remaining'] === null,
            'priced' => self::priced($db, $registrarId, $state['since']),
            'remaining' => count($state['remaining'] ?? []),
            'fetched_at' => $state['done_at'],
        ];
    }

    /**
     * Every TLD of the last complete price list with its status, costs, current and new prices.
     *
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>, currency: string, rate: float, fetched_at: string, fingerprint: string}
     */
    public static function preview(\Pimple\Container $di, int $registrarId): array
    {
        $db = self::db($di);
        self::registrar($di, $registrarId);
        $state = self::state($db, 'tld_prices_' . $registrarId);
        // An unfinished refresh holds only part of the list: it is finished with "Get the price list".
        if ($state['done_at'] === null || $state['since'] === null || $state['remaining'] !== null) {
            throw new InformationException('Get the price list first.');
        }
        $costs = $db->fetchAllAssociative('SELECT * FROM tld_registrar_cost WHERE tld_registrar_id = ? AND fetched_at >= ? ORDER BY tld', [$registrarId, $state['since']]);
        $currency = (string) ($costs[0]['currency'] ?? '');
        $rate = self::rate($db, $currency);
        if ($rate === null) {
            throw new InformationException('The registrar charges in :code, which is not one of your currencies. Add :code under Currencies first: its rate converts the costs.', [':code' => $currency !== '' ? $currency : '?']);
        }
        $settings = self::settings($di, $registrarId);
        $service = $di['mod_service']('servicedomain');
        $store = [];
        foreach ($db->fetchAllAssociative('SELECT id, tld, tld_registrar_id, price_registration, price_renew, price_transfer, price_locked FROM tld') as $tld) {
            try {
                $store[$service->normalizeTld((string) $tld['tld'])] = $tld;
            } catch (InformationException) {
                // A malformed legacy row cannot match a registrar's TLD.
            }
        }
        $names = $db->fetchAllKeyValue('SELECT id, name FROM tld_registrar');
        $fixed = [];
        if ($db->createSchemaManager()->tablesExist(['product_currency_price'])) {
            foreach ($db->fetchAllAssociative("SELECT DISTINCT ref_id, currency FROM product_currency_price WHERE kind = 'tld' AND price IS NOT NULL ORDER BY currency") as $p) {
                $fixed[(int) $p['ref_id']][] = (string) $p['currency'];
            }
        }

        $rows = [];
        $counts = array_fill_keys([self::CHANGE, self::SAME, self::NEW, self::LOCKED, self::OTHER, self::NO_PRICE], 0);
        foreach ($costs as $cost) {
            $tld = (string) $cost['tld'];
            $current = $store[$tld] ?? null;
            $costOf = $new = $applied = [];
            foreach (array_keys(self::ACTIONS) as $action) {
                $costOf[$action] = $cost['cost_' . $action] === null ? null : (float) $cost['cost_' . $action];
                $applied[$action] = $cost['applied_' . $action] === null ? null : (float) $cost['applied_' . $action];
                $new[$action] = self::price($costOf[$action], $rate, $settings['markup'][$action], $settings['rounding']);
            }
            $old = $current === null ? null : array_map(static fn (string $c): float => round((float) $current[$c], 2), self::ACTIONS);
            if ($new['transfer'] === null) {
                // No transfer cost: an existing TLD keeps its transfer price; a new one gets 0 with transfers off.
                $new['transfer'] = $old['transfer'] ?? 0.0;
            }
            $appliedRate = $cost['applied_rate'] === null ? null : (float) $cost['applied_rate'];
            $status = match (true) {
                $new['register'] === null || $new['renew'] === null => self::NO_PRICE,
                $current === null => self::NEW,
                (int) $current['price_locked'] === 1 => self::LOCKED,
                (int) $current['tld_registrar_id'] !== $registrarId => self::OTHER,
                $new === $old => self::SAME,
                default => self::CHANGE,
            };
            ++$counts[$status];
            $rows[] = [
                'tld' => $tld,
                'tld_id' => $current !== null ? (int) $current['id'] : null,
                'status' => $status,
                'cost' => $costOf,
                'current' => $old,
                'new' => $new,
                'registrar' => $status === self::OTHER ? (string) ($names[$current['tld_registrar_id']] ?? '') : null,
                // Same cost, and the settings give the current price at the rate of then: only the exchange rate moved.
                'rate_only' => $status === self::CHANGE && $appliedRate !== null && $applied === $costOf && $old === array_map(
                    fn (string $action): float => self::price($costOf[$action], $appliedRate, $settings['markup'][$action], $settings['rounding']) ?? $new[$action],
                    array_combine(array_keys(self::ACTIONS), array_keys(self::ACTIONS))
                ),
                'fixed_currencies' => $current !== null ? ($fixed[(int) $current['id']] ?? []) : [],
                'offers_transfer' => $costOf['transfer'] !== null,
            ];
        }

        return [
            'rows' => $rows,
            'counts' => $counts,
            'currency' => $currency,
            'rate' => $rate,
            'fetched_at' => $state['done_at'],
            'fingerprint' => hash('sha256', json_encode([$settings, $rate, array_map(static fn (array $r): array => [$r['tld'], $r['status'], $r['current'], $r['new'], $r['registrar']], $rows)])),
        ];
    }

    /**
     * Applies the ticked rows of a preview: creates new TLDs (inactive), updates changed ones and
     * moves the ticked ones of other registrars here. Nothing is applied when the preview changed
     * or a ticked row cannot be applied.
     *
     * @param list<string> $tlds TLDs to apply
     * @param list<string> $move ticked TLDs of other registrars to move to this one
     *
     * @return array{created: int, updated: int, moved: int}
     */
    public static function apply(\Pimple\Container $di, int $registrarId, array $tlds, array $move, string $fingerprint, ?int $adminId): array
    {
        $db = self::db($di);
        $service = $di['mod_service']('servicedomain');
        $tlds = array_values(array_unique(array_map(static fn ($t): string => strtolower(trim((string) $t)), $tlds)));
        $move = array_map(static fn ($t): string => strtolower(trim((string) $t)), $move);
        if ($tlds === []) {
            throw new InformationException('Tick at least one TLD.');
        }
        $done = ['created' => 0, 'updated' => 0, 'moved' => 0];
        $changes = [];
        $db->beginTransaction();
        try {
            // One apply per registrar at a time; then price again under the lock.
            $db->fetchOne('SELECT id FROM tld_registrar WHERE id = ?' . (self::isMysql($db) ? ' FOR UPDATE' : ''), [$registrarId]);
            $preview = self::preview($di, $registrarId);
            if ($fingerprint === '' || !hash_equals($preview['fingerprint'], $fingerprint)) {
                throw new InformationException('Prices changed since the preview. Check the preview again before applying.');
            }
            $rows = array_column($preview['rows'], null, 'tld');
            foreach ($tlds as $tld) {
                $row = $rows[$tld] ?? null;
                $moving = $row !== null && $row['status'] === self::OTHER && in_array($tld, $move, true);
                if ($row === null || !(in_array($row['status'], [self::CHANGE, self::NEW], true) || $moving)) {
                    throw new InformationException(':tld cannot be applied. Nothing was changed.', [':tld' => $tld]);
                }
                $prices = array_combine(array_values(self::ACTIONS), array_values($row['new']));
                if ($row['status'] === self::NEW) {
                    $service->tldCreate($prices + [
                        'tld' => $tld,
                        'tld_registrar_id' => $registrarId,
                        'active' => 0,
                        'allow_register' => 1,
                        'allow_transfer' => $row['offers_transfer'] ? 1 : 0,
                        'require_transfer_code' => 1,
                        'min_years' => 1,
                    ]);
                    ++$done['created'];
                } else {
                    $model = $service->tldFindOneById($row['tld_id']);
                    $service->tldUpdate($model, $prices + ($moving ? ['tld_registrar_id' => $registrarId] : []));
                    ++$done[$moving ? 'moved' : 'updated'];
                }
                $db->executeStatement(
                    'UPDATE tld_registrar_cost SET applied_register = cost_register, applied_renew = cost_renew, applied_transfer = cost_transfer, applied_rate = ? WHERE tld_registrar_id = ? AND tld = ?',
                    [$preview['rate'], $registrarId, $tld]
                );
                $changes[] = sprintf('%s %s %s→%s', $tld, $row['status'] === self::NEW ? 'new' : ($moving ? 'moved' : 'changed'), json_encode($row['current']), json_encode($row['new']));
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }

            throw $e;
        }
        $di['logger']->info('TLD prices of registrar #%s applied%s: %s', $registrarId, $adminId !== null ? sprintf(' by staff #%d', $adminId) : '', implode('; ', $changes));

        return $done;
    }

    /**
     * The scheduled sync (Service::onAfterAdminCronRun()): once a day for each registrar whose mode is
     * not off, for the TLDs applied once through the import (applied_rate set) and not locked. A TLD
     * counts when the registrar changed a cost since it was applied, or its price is below cost at
     * today's rate; an exchange rate move alone is ignored (the import re-bases on demand). Notify only
     * emails staff; Apply writes the new prices, except a change of more than MAX_JUMP, which waits
     * for staff. One email per new set of changes. At most $seconds of registrar calls per cron run.
     */
    public static function runIfDue(\Pimple\Container $di, int $seconds = 30): void
    {
        $db = self::db($di);
        $deadline = microtime(true) + $seconds;
        foreach ($db->fetchAllAssociative('SELECT id, name, price_sync FROM tld_registrar WHERE price_sync IS NOT NULL ORDER BY id') as $registrar) {
            $id = (int) $registrar['id'];
            $settings = self::clean(json_decode((string) $registrar['price_sync'], true) ?: [], false);
            $state = self::state($db, 'tld_sync_' . $id);
            if ($settings['mode'] === 'off' || ($state['remaining'] === null && str_starts_with((string) $state['done_at'], date('Y-m-d')))) {
                continue;
            }
            $tlds = array_keys(self::synced($di, $id));
            try {
                if ($tlds === []) {
                    self::saveState($db, 'tld_sync_' . $id, ['since' => null, 'remaining' => null, 'first' => false, 'done_at' => date('Y-m-d H:i:s')]);

                    continue;
                }
                // At least one slice per registrar; then the time left.
                $pulled = self::pull($di, $id, 'tld_sync_' . $id, $tlds, max(0, (int) ($deadline - microtime(true))), $state['remaining'] === null);
                if ($pulled['done']) {
                    self::compare($di, $id, (string) $registrar['name'], $settings, $tlds);
                }
                if (microtime(true) >= $deadline) {
                    return;
                }
            } catch (\Throwable $e) {
                // Tried again tomorrow.
                self::saveState($db, 'tld_sync_' . $id, ['since' => null, 'remaining' => null, 'first' => false, 'done_at' => date('Y-m-d H:i:s')]);
                $di['logger']->error('TLD price sync of registrar #%s failed: %s', $id, $e->getMessage());
            }
        }
    }

    /**
     * The store's TLDs on the registrar that the sync follows, by TLD, with their cost row.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function synced(\Pimple\Container $di, int $registrarId): array
    {
        $db = self::db($di);
        $service = $di['mod_service']('servicedomain');
        $costs = [];
        foreach ($db->fetchAllAssociative('SELECT * FROM tld_registrar_cost WHERE tld_registrar_id = ? AND applied_rate IS NOT NULL', [$registrarId]) as $cost) {
            $costs[(string) $cost['tld']] = $cost;
        }
        $synced = [];
        foreach ($db->fetchAllAssociative('SELECT id, tld, price_registration, price_renew, price_transfer FROM tld WHERE tld_registrar_id = ? AND price_locked = 0', [$registrarId]) as $tld) {
            try {
                $name = $service->normalizeTld((string) $tld['tld']);
            } catch (InformationException) {
                continue;
            }
            if (isset($costs[$name])) {
                $synced[$name] = $tld + ['cost' => $costs[$name]];
            }
        }
        ksort($synced);

        return $synced;
    }

    /**
     * After a complete sync fetch: finds the changes, applies them in Apply mode and emails staff.
     *
     * @param array{mode: string, markup: array<string, array{type: string, value: float}>, rounding: string} $settings
     * @param list<string>                                                                                       $asked
     */
    private static function compare(\Pimple\Container $di, int $registrarId, string $name, array $settings, array $asked): void
    {
        $db = self::db($di);
        $synced = self::synced($di, $registrarId);
        // A changed registrar currency cleared these baselines (store()): only the import sets new ones.
        $rebase = count(array_diff($asked, array_keys($synced)));
        $rows = [];
        foreach ($synced as $tld => $item) {
            $rate = self::rate($db, (string) $item['cost']['currency']);
            if ($rate === null) {
                throw new InformationException('The registrar charges in :code, which is not one of your currencies. Add :code under Currencies first: its rate converts the costs.', [':code' => (string) $item['cost']['currency']]);
            }
            $row = ['tld' => $tld, 'tld_id' => (int) $item['id'], 'rate' => $rate, 'changed' => false, 'below' => false, 'held' => false];
            foreach (self::ACTIONS as $action => $column) {
                $cost = $item['cost']['cost_' . $action] === null ? null : (float) $item['cost']['cost_' . $action];
                $applied = $item['cost']['applied_' . $action] === null ? null : (float) $item['cost']['applied_' . $action];
                $now = round((float) $item[$column], 2);
                $changed = $cost !== $applied;
                $below = $cost !== null && $now < self::price($cost, $rate, ['type' => 'fixed', 'value' => 0.0], '');
                // Only the actions the registrar changed, or that sell below cost, get a new price.
                $new = $changed || $below ? self::price($cost, $rate, $settings['markup'][$action], $settings['rounding']) ?? $now : $now;
                $row['changed'] = $row['changed'] || $changed;
                $row['below'] = $row['below'] || $below;
                // A big jump, or a registrar that stopped pricing register or renew, waits for staff.
                $row['held'] = $row['held'] || ($now > 0 && abs($new - $now) / $now > self::MAX_JUMP) || ($cost === null && $action !== 'transfer');
                $row['actions'][$action] = ['cost_old' => $applied, 'cost_new' => $cost, 'price_now' => $now, 'price_new' => $new];
            }
            if ($row['changed'] || $row['below']) {
                $row['what'] = $settings['mode'] === 'notify' ? 'suggested' : ($row['held'] ? 'review' : 'applied');
                $rows[] = $row;
            }
        }

        $applied = array_values(array_filter($rows, static fn (array $r): bool => $r['what'] === 'applied'));
        if ($applied !== []) {
            $service = $di['mod_service']('servicedomain');
            $db->beginTransaction();
            try {
                $db->fetchOne('SELECT id FROM tld_registrar WHERE id = ?' . (self::isMysql($db) ? ' FOR UPDATE' : ''), [$registrarId]);
                // Staff may have left Apply while the registrar answered.
                if (self::settings($di, $registrarId)['mode'] !== 'apply') {
                    $db->rollBack();

                    return;
                }
                foreach ($applied as $row) {
                    $service->tldUpdate($service->tldFindOneById($row['tld_id']), array_combine(array_values(self::ACTIONS), array_column($row['actions'], 'price_new')));
                    $db->executeStatement(
                        'UPDATE tld_registrar_cost SET applied_register = cost_register, applied_renew = cost_renew, applied_transfer = cost_transfer, applied_rate = ? WHERE tld_registrar_id = ? AND tld = ?',
                        [$row['rate'], $registrarId, $row['tld']]
                    );
                }
                $db->commit();
            } catch (\Throwable $e) {
                if ($db->isTransactionActive()) {
                    $db->rollBack();
                }

                throw $e;
            }
            $di['logger']->info('TLD prices of registrar #%s applied by the scheduled sync: %s', $registrarId, implode('; ', array_map(static fn (array $r): string => $r['tld'] . ' ' . json_encode(array_column($r['actions'], 'price_new')), $applied)));
        }

        // Staff hear of every apply, and of the changes left to them once per new set.
        $waiting = array_values(array_filter($rows, static fn (array $r): bool => $r['what'] !== 'applied'));
        // Costs and status make a set; the suggested price moves with the daily exchange rate update.
        $set = $waiting === [] && $rebase === 0 ? '' : hash('sha256', json_encode([$rebase, array_map(static fn (array $r): array => [$r['tld'], $r['what'], $r['below'], array_map(static fn (array $a): array => [$a['cost_old'], $a['cost_new']], $r['actions'])], $waiting)]));
        $sent = json_decode((string) $db->fetchOne('SELECT value FROM mod_clientfence_state WHERE name = ?', ['tld_sync_mail_' . $registrarId]), true)['sent'] ?? '';
        if ($applied === [] && $set === $sent) {
            return;
        }
        if ($rows !== [] || $rebase > 0) {
            $fixed = $db->createSchemaManager()->tablesExist(['product_currency_price'])
                ? $db->fetchFirstColumn("SELECT DISTINCT ref_id FROM product_currency_price WHERE kind = 'tld' AND price IS NOT NULL")
                : [];
            $di['mod_service']('email')->sendTemplate([
                'to_staff' => true,
                'code' => 'mod_staff_tld_price_changes',
                'sync' => [
                    'registrar' => $name,
                    'mode' => $settings['mode'],
                    'rows' => $rows,
                    'rebase' => $rebase,
                    'fixed' => count(array_intersect(array_column($rows, 'tld_id'), array_map('intval', $fixed))),
                    'url' => $di['url']->adminLink('servicedomain/registrar/' . $registrarId . '/prices'),
                ],
            ]);
        }
        self::saveState($db, 'tld_sync_mail_' . $registrarId, ['sent' => $set]);
    }

    /** Whether the registrar's adapter has a price list. */
    public static function supported(\Pimple\Container $di, \Model_TldRegistrar $registrar): bool
    {
        try {
            return $di['mod_service']('servicedomain')->registrarGetRegistrarAdapter($registrar) instanceof \Registrar_TldPrices;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{mode: string, markup: array<string, array{type: string, value: float}>, rounding: string}
     */
    private static function clean(array $data, bool $strict): array
    {
        $settings = ['mode' => 'off', 'markup' => [], 'rounding' => ''];
        $mode = (string) ($data['mode'] ?? 'off');
        $rounding = (string) ($data['rounding'] ?? '');
        if ($strict && (!in_array($mode, self::MODES, true) || !in_array($rounding, self::ROUNDINGS, true))) {
            throw new InformationException('Unknown sync mode or rounding.');
        }
        $settings['mode'] = in_array($mode, self::MODES, true) ? $mode : 'off';
        $settings['rounding'] = in_array($rounding, self::ROUNDINGS, true) ? $rounding : '';
        foreach (array_keys(self::ACTIONS) as $action) {
            $markup = is_array($data['markup'][$action] ?? null) ? $data['markup'][$action] : [];
            $type = in_array($markup['type'] ?? null, self::MARKUPS, true) ? $markup['type'] : 'percent';
            $value = str_replace(' ', '', trim((string) ($markup['value'] ?? '0')));
            $value = $value === '' ? '0' : $value;
            $valid = preg_match('/^[0-9]+(\.[0-9]{1,2})?$/', $value) === 1 && (float) $value <= ($type === 'fixed' ? self::MAX_AMOUNT : self::MAX_PERCENT);
            if ($strict && !$valid) {
                throw new InformationException('Enter each markup as a number from 0 with up to 2 decimals (at most :max).', [':max' => $type === 'fixed' ? self::MAX_AMOUNT : self::MAX_PERCENT]);
            }
            $settings['markup'][$action] = ['type' => $type, 'value' => $valid ? round((float) $value, 2) : 0.0];
        }

        return $settings;
    }

    /**
     * Saves the costs; true when a TLD's costs came in another currency than before.
     *
     * @param array<string, mixed> $prices
     */
    private static function store(\Pimple\Container $di, int $registrarId, string $currency, array $prices): bool
    {
        $changed = false;
        $db = self::db($di);
        $service = $di['mod_service']('servicedomain');
        $now = date('Y-m-d H:i:s');
        $cost = static function (mixed $value): ?string {
            return is_numeric($value) && is_finite((float) $value) && (float) $value > 0 ? number_format((float) $value, 4, '.', '') : null;
        };
        foreach ($prices as $tld => $price) {
            try {
                $tld = $service->normalizeTld((string) $tld);
            } catch (InformationException) {
                continue;
            }
            $values = ['currency' => $currency, 'fetched_at' => $now];
            foreach (array_keys(self::ACTIONS) as $action) {
                $values['cost_' . $action] = $cost(is_array($price) ? ($price[$action] ?? null) : null);
            }
            $old = $db->fetchAssociative('SELECT id, currency FROM tld_registrar_cost WHERE tld_registrar_id = ? AND tld = ?', [$registrarId, $tld]);
            if ($old === false) {
                $db->insert('tld_registrar_cost', $values + ['tld_registrar_id' => $registrarId, 'tld' => $tld]);
            } else {
                if ($old['currency'] !== $currency) {
                    // Costs in another currency: the old baseline no longer compares.
                    $values += ['applied_register' => null, 'applied_renew' => null, 'applied_transfer' => null, 'applied_rate' => null];
                    $changed = true;
                }
                $db->update('tld_registrar_cost', $values, ['id' => $old['id']]);
            }
        }

        return $changed;
    }

    /** @return array{since: ?string, remaining: ?list<string>, first: bool, done_at: ?string} */
    private static function state(Connection $db, string $name): array
    {
        $state = json_decode((string) $db->fetchOne('SELECT value FROM mod_clientfence_state WHERE name = ?', [$name]), true);
        $state = is_array($state) ? $state : [];

        return [
            'since' => isset($state['since']) ? (string) $state['since'] : null,
            'remaining' => is_array($state['remaining'] ?? null) ? array_values(array_map('strval', $state['remaining'])) : null,
            'first' => (bool) ($state['first'] ?? false),
            'done_at' => isset($state['done_at']) ? (string) $state['done_at'] : null,
        ];
    }

    /** @param array<string, mixed> $state */
    private static function saveState(Connection $db, string $name, array $state): void
    {
        $now = date('Y-m-d H:i:s');
        if ($db->executeStatement('UPDATE mod_clientfence_state SET value = ?, updated_at = ? WHERE name = ?', [json_encode($state), $now, $name]) === 0
            && $db->fetchOne('SELECT 1 FROM mod_clientfence_state WHERE name = ?', [$name]) === false) {
            $db->insert('mod_clientfence_state', ['name' => $name, 'value' => json_encode($state), 'updated_at' => $now]);
        }
    }

    /** Removes a registrar's costs and fetch state (Service::registrarRm()). */
    public static function forget(\Pimple\Container $di, int $registrarId): void
    {
        $db = self::db($di);
        $db->delete('tld_registrar_cost', ['tld_registrar_id' => $registrarId]);
        foreach (['tld_prices_', 'tld_sync_', 'tld_sync_mail_'] as $name) {
            $db->delete('mod_clientfence_state', ['name' => $name . $registrarId]);
        }
    }

    private static function registrar(\Pimple\Container $di, int $registrarId): \Model_TldRegistrar
    {
        return $di['db']->getExistingModelById('TldRegistrar', $registrarId, 'Registrar not found');
    }

    private static function adapter(\Pimple\Container $di, \Model_TldRegistrar $registrar): \Registrar_TldPrices
    {
        $adapter = $di['mod_service']('servicedomain')->registrarGetRegistrarAdapter($registrar);
        if (!$adapter instanceof \Registrar_TldPrices) {
            throw new InformationException('Not supported by this registrar.');
        }

        return $adapter;
    }

    /** Registrar currency units per one default-currency unit, or null for a currency the store lacks. */
    private static function rate(Connection $db, string $currency): ?float
    {
        $rate = $currency === '' ? false : $db->fetchOne('SELECT conversion_rate FROM currency WHERE code = ?', [$currency]);

        return $rate === false || (float) $rate <= 0 ? null : (float) $rate;
    }

    private static function isMysql(Connection $db): bool
    {
        return $db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
    }

    private static function priced(Connection $db, int $registrarId, string $since): int
    {
        return (int) $db->fetchOne('SELECT COUNT(*) FROM tld_registrar_cost WHERE tld_registrar_id = ? AND fetched_at >= ?', [$registrarId, $since]);
    }

    private static function db(\Pimple\Container $di): Connection
    {
        return $di['em']->getConnection();
    }
}
