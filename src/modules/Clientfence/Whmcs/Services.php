<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Whmcs;

use Doctrine\DBAL\Connection;

/**
 * ClientFence: the WHMCS import's service steps (Run.php runs them in batches, after the catalogue
 * steps): services (tblhosting), service add-ons (tblhostingaddons) and domains (tbldomains). Each
 * becomes an order of the imported product with its service row (hosting account, licence, custom
 * service or domain), as checkout and activation would have made it, but nothing is created,
 * registered or charged anywhere. As in Accounts.php, rows are written directly, each imported row
 * goes in the id map (WHMCS id => order id) and a row left out with a warning is noted as "skipped".
 *
 * Prices: an order keeps the amount WHMCS bills for it (in the client's currency, promotions
 * already applied; per unit when WHMCS scaled a quantity), so it renews at that amount; a domain renews at its domain price, as ClientFence
 * prices domains (a domain WHMCS billed differently is named in the warnings).
 *
 * Statuses: Active and Completed are active (Completed, and every one-time or free service, never
 * renews), Suspended suspended, Pending pending setup; every ended service (Terminated, Cancelled,
 * Fraud, an expired or transferred domain) is a canceled order, kept for its history (owner
 * decision 2026-10-19). A cancellation request of an active or suspended service waits in Orders >
 * Cancellation Requests with its type, reason and date (Order\CancelRequests).
 *
 * Configurable options chosen on a service fill its order config (`options`, `option_lines`) as
 * checkout would; the order's price stays what WHMCS billed.
 *
 * Hosting accounts keep their username, but not their password: ClientFence does not store hosting
 * passwords. Licence services take their key and bindings from WHMCS's Licensing module.
 */
final class Services
{
    /** The steps in order: name => [label, WHMCS table read]. */
    public const STEPS = [
        'services' => ['Services', 'tblhosting'],
        'serviceaddons' => ['Service add-ons', 'tblhostingaddons'],
        'domains' => ['Domains', 'tbldomains'],
    ];

    /** WHMCS service statuses => order statuses. */
    public const STATUSES = [
        'pending' => 'pending_setup', 'active' => 'active', 'completed' => 'active', 'suspended' => 'suspended',
        'terminated' => 'canceled', 'cancelled' => 'canceled', 'fraud' => 'canceled',
    ];

    /** WHMCS domain statuses => order statuses. */
    public const DOMAIN_STATUSES = [
        'pending' => 'pending_setup', 'pending registration' => 'pending_setup', 'pending transfer' => 'pending_setup',
        'active' => 'active', 'grace' => 'active', 'redemption' => 'active',
        'expired' => 'canceled', 'transferred away' => 'canceled', 'cancelled' => 'canceled', 'fraud' => 'canceled',
    ];

    /** What ClientFence keeps of a hosting password (Servicehosting\Service::PASSWORD_PLACEHOLDER). */
    private const PASSWORD_PLACEHOLDER = '********';

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<string, string> domain => its TLD (cache of lookups) */
    private array $tlds = [];

    /**
     * @param Catalogue $catalogue for the registrars of domains (Catalogue::registrar())
     */
    public function __construct(
        private readonly Source $source,
        private readonly Connection $db,
        private readonly Map $map,
        private readonly Catalogue $catalogue,
        private readonly string $now,
    ) {
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return [...$this->warnings, ...$this->catalogue->warnings()];
    }

    public function total(string $step): int
    {
        $table = self::STEPS[$step][1];

        return $this->source->hasTable($table) ? $this->source->count($table) : 0;
    }

    /**
     * Imports up to $limit rows of a step after the WHMCS id $after: the last id read (null: done)
     * and how many rows were imported.
     *
     * @return array{0: ?int, 1: int}
     */
    public function batch(string $step, int $after, int $limit): array
    {
        $table = self::STEPS[$step][1] ?? throw new \InvalidArgumentException('Unknown step ' . $step);
        if (!$this->source->hasTable($table)) {
            return [null, 0];
        }
        $rows = $this->source->rows('SELECT * FROM ' . $table . ' WHERE id > ? ORDER BY id LIMIT ' . $limit, [$after]);
        if ($rows === []) {
            return [null, 0];
        }
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $answers = $step === 'services' ? $this->answers($ids) : [];
        $choices = $step === 'services' ? $this->optionChoices($ids) : [];
        $done = 0;
        foreach ($rows as $row) {
            $done += match ($step) {
                'services' => $this->service($row, $answers[(int) $row['id']] ?? [], $choices[(int) $row['id']] ?? []),
                'serviceaddons' => $this->serviceAddon($row),
                'domains' => $this->domain($row),
            };
        }

        return [(int) end($rows)['id'], $done];
    }

    /**
     * @param array<string, mixed>               $row
     * @param array<string, string|list<string>> $answers the service's order form answers
     * @param list<array<string, mixed>>         $choices its configurable options (tblhostingconfigoptions rows)
     */
    private function service(array $row, array $answers, array $choices): int
    {
        $id = (int) $row['id'];
        if ($this->map->has('service', $id) || $this->map->has('skipped', 'service:' . $id)) {
            return 0;
        }
        $client = $this->client((int) ($row['userid'] ?? 0));
        $productId = $this->map->get('product', (int) ($row['packageid'] ?? 0));
        $product = $productId === null ? false : $this->db->fetchAssociative('SELECT id, title, type, form_id, config FROM product WHERE id = ?', [$productId]);
        $domain = strtolower(trim((string) ($row['domain'] ?? '')));
        $name = sprintf('Service #%d%s', $id, $domain === '' ? '' : ' (' . $domain . ')');
        if ($client === null || $product === false) {
            $this->map->put('skipped', 'service:' . $id, 0);
            $this->warn(sprintf('%s was not imported: its %s was not imported.', $name, $client === null ? 'client' : 'product'));

            return 0;
        }
        $whmcsStatus = strtolower(trim((string) ($row['domainstatus'] ?? '')));
        $status = self::STATUSES[$whmcsStatus] ?? 'canceled';
        $cycle = strtolower(trim((string) ($row['billingcycle'] ?? '')));
        $period = Catalogue::CYCLE_NAMES[$cycle] ?? null;
        $renews = $period !== null && $whmcsStatus !== 'completed';
        $productConfig = json_decode((string) ($product['config'] ?? ''), true);
        $productConfig = is_array($productConfig) ? $productConfig : [];
        [$sld, $tld] = $this->split($domain);
        $type = (string) $product['type'];
        [$quantity, $price] = $this->units((int) ($row['qty'] ?? 1), (float) ($row['amount'] ?? 0), $name);
        $config = ['product_id' => (int) $product['id'], 'type' => $type, 'period' => $period, 'quantity' => $quantity]
            + $answers + $productConfig + ['whmcs_service_id' => $id];
        if ($choices !== []) {
            [$config['options'], $config['option_lines']] = $this->options((int) $product['id'], $choices, $period);
        }
        if ($type === 'hosting' && $sld !== '') {
            $config['domain'] = ['action' => 'owndomain', 'owndomain_sld' => $sld, 'owndomain_tld' => $tld];
        }
        $title = $type === 'hosting' && $sld !== '' ? sprintf('%s for %s', $product['title'], $sld . $tld) : (string) $product['title'];
        if (!in_array($whmcsStatus, array_keys(self::STATUSES), true)) {
            $this->warn(sprintf('%s: WHMCS status "%s" is not known; it was imported as canceled.', $name, (string) $row['domainstatus']));
        }

        $orderId = $this->order([
            'client_id' => $client['id'],
            'product_id' => (int) $product['id'],
            'form_id' => $product['form_id'] === null ? null : (int) $product['form_id'],
            'promo_id' => $this->map->get('promo', (int) ($row['promoid'] ?? 0)),
            'group_id' => 'whmcs_s' . $id,
            'group_master' => 1,
            'title' => $title,
            'currency' => $client['currency'],
            'service_type' => $type,
            'period' => $period,
            'quantity' => $quantity,
            'price' => $price,
            'notes' => trim((string) ($row['notes'] ?? '')) ?: null,
            'config' => $config,
        ], $row, $whmcsStatus, $status, $renews, (string) ($row['regdate'] ?? ''), $name, $id);

        $serviceId = match ($type) {
            'hosting' => $this->hosting($row, $client['id'], $productConfig, $sld, $tld, $status, $name),
            'license' => $this->licence($id, (int) ($row['packageid'] ?? 0), (string) ($row['billingcycle'] ?? ''), $client['id'], $productConfig, $name),
            default => $this->custom($client['id'], $config),
        };
        $this->db->update('client_order', ['service_id' => $serviceId], ['id' => $orderId]);
        $this->map->put('service', $id, $orderId);

        return 1;
    }

    /**
     * A service's order form answers (product custom fields): service id => field name => value.
     *
     * @param list<int> $serviceIds
     *
     * @return array<int, array<string, string|list<string>>>
     */
    private function answers(array $serviceIds): array
    {
        if ($serviceIds === [] || !$this->source->hasTable('tblcustomfieldsvalues')) {
            return [];
        }
        $in = implode(',', array_map('intval', $serviceIds));
        $out = [];
        $rows = $this->source->rows(
            "SELECT v.fieldid, v.relid, v.value, f.fieldtype FROM tblcustomfieldsvalues v JOIN tblcustomfields f ON f.id = v.fieldid JOIN tblhosting h ON h.id = v.relid AND h.packageid = f.relid WHERE f.type = 'product' AND v.relid IN (" . $in . ') ORDER BY v.id'
        );
        foreach ($rows as $row) {
            if (!$this->map->has('productfield', (int) $row['fieldid'])) {
                continue;
            }
            $value = (string) $row['value'];
            $name = 'whmcs_field_' . (int) $row['fieldid'];
            if (strtolower((string) $row['fieldtype']) === 'tickbox') {
                if (in_array(strtolower(trim($value)), ['on', '1', 'yes'], true)) {
                    $out[(int) $row['relid']][$name] = ['on'];
                }
            } elseif (trim($value) !== '') {
                $out[(int) $row['relid']][$name] = $value;
            }
        }

        return $out;
    }

    /**
     * The configurable options chosen on services: service id => its tblhostingconfigoptions rows.
     *
     * @param list<int> $serviceIds
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function optionChoices(array $serviceIds): array
    {
        if ($serviceIds === [] || !$this->source->hasTable('tblhostingconfigoptions')) {
            return [];
        }
        $out = [];
        foreach ($this->source->rows('SELECT relid, configid, optionid, qty FROM tblhostingconfigoptions WHERE relid IN (' . implode(',', array_map('intval', $serviceIds)) . ') ORDER BY id') as $row) {
            $out[(int) $row['relid']][] = $row;
        }

        return $out;
    }

    /**
     * A service's option choice and lines, as checkout keeps them in the order config (`options`,
     * `option_lines`: Product/Options.php resolve()). The order's price is still what WHMCS billed;
     * the lines name the options for invoices and server modules. Options of a group the product no
     * longer has are left out.
     *
     * @param list<array<string, mixed>> $choices
     *
     * @return array{0: array<int, int>, 1: list<array<string, mixed>>}
     */
    private function options(int $productId, array $choices, ?string $period): array
    {
        $key = $period === null ? 'once' : (\ClientFence\Mod\Product\Options::PERIOD_KEYS[$period] ?? 'once');
        $choice = [];
        $lines = [];
        foreach ($choices as $row) {
            $optionId = $this->map->get('option', (int) $row['configid']);
            $option = $optionId === null ? false : $this->db->fetchAssociative(
                'SELECT o.* FROM product_option o JOIN product_option_group_product gp ON gp.group_id = o.group_id AND gp.product_id = ? WHERE o.id = ?',
                [$productId, $optionId]
            );
            if ($option === false || isset($choice[(int) $option['id']])) {
                continue;
            }
            if (in_array($option['type'], ['select', 'radio'], true)) {
                $valueId = $this->map->get('optionvalue', (int) $row['optionid']);
                $value = $valueId === null ? false : $this->db->fetchAssociative('SELECT * FROM product_option_value WHERE id = ? AND option_id = ?', [$valueId, $option['id']]);
                if ($value === false) {
                    continue;
                }
                $qty = 1;
                $choice[(int) $option['id']] = (int) $value['id'];
            } else {
                $value = $this->db->fetchAssociative('SELECT * FROM product_option_value WHERE option_id = ? ORDER BY position, id', [$option['id']]);
                if ($value === false) {
                    continue;
                }
                $qty = $option['type'] === 'yesno' ? ((int) $row['qty'] > 0 ? 1 : 0) : max(0, (int) $row['qty']);
                $choice[(int) $option['id']] = $qty;
            }
            if ($qty === 0) {
                continue;
            }
            $lines[] = [
                'option_id' => (int) $option['id'],
                'code' => (string) $option['code'],
                'title' => (string) $option['title'],
                'type' => (string) $option['type'],
                'value_id' => (int) $value['id'],
                'value_code' => (string) $value['code'],
                'value' => (string) $value['title'],
                'quantity' => $qty,
                'unit' => (string) $option['unit'],
                'price' => round((float) $value[$key . '_price'] * $qty, 2),
                'setup_price' => round((float) $value[$key . '_setup_price'] * $qty, 2),
            ];
        }

        return [$choice, $lines];
    }

    /**
     * The hosting account of a service: the server and plan of its product (its own server when
     * WHMCS placed it on another imported one), its domain and username; no password.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $productConfig
     */
    private function hosting(array $row, int $clientId, array $productConfig, string $sld, string $tld, string $status, string $name): int
    {
        $serverId = $this->map->get('server', (int) ($row['server'] ?? 0)) ?? (isset($productConfig['server_id']) ? (int) $productConfig['server_id'] : null);
        $ip = trim((string) ($row['dedicatedip'] ?? ''));
        if ($ip === '' && $serverId !== null) {
            $ip = (string) $this->db->fetchOne('SELECT ip FROM service_hosting_server WHERE id = ?', [$serverId]);
        }
        if ($sld === '' && $status !== 'canceled') {
            $this->warn(sprintf('%s is a hosting account without a domain: enter its domain on the order before its server is switched on.', $name));
        }
        if (!$this->map->has('skipped', 'notice:hostingpasswords')) {
            $this->map->put('skipped', 'notice:hostingpasswords', 0);
            $this->warn('Hosting account passwords were not imported: ClientFence does not store them. Clients and staff set a new one from the hosting account page when needed.');
        }
        $this->db->insert('service_hosting', [
            'client_id' => $clientId,
            'service_hosting_server_id' => $serverId,
            'service_hosting_hp_id' => isset($productConfig['hosting_plan_id']) ? (int) $productConfig['hosting_plan_id'] : null,
            'sld' => $sld === '' ? null : $sld,
            'tld' => $tld === '' ? null : $tld,
            'ip' => self::cut($ip, 45) ?: null,
            'username' => self::cut($row['username'] ?? '', 255) ?: null,
            'pass' => self::PASSWORD_PLACEHOLDER,
            'reseller' => !empty($productConfig['reseller']) ? 1 : 0,
            'created_at' => $this->now, 'updated_at' => $this->now,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * The licence of a service: its key and bindings from WHMCS's Licensing module (mod_licensing).
     *
     * @param array<string, mixed> $productConfig
     */
    private function licence(int $serviceId, int $whmcsProductId, string $whmcsCycle, int $clientId, array $productConfig, string $name): int
    {
        $licence = $this->source->hasTable('mod_licensing')
            ? $this->source->rows('SELECT * FROM mod_licensing WHERE serviceid = ? ORDER BY id DESC LIMIT 1', [$serviceId])[0] ?? null
            : null;
        $key = $licence === null ? '' : self::cut($licence['licensekey'] ?? '', 255);
        if ($key !== '' && $this->db->fetchOne('SELECT id FROM service_license WHERE license_key = ?', [$key]) !== false) {
            $this->warn(sprintf('%s: its licence key is already used by another imported licence; it was left without a key.', $name));
            $key = '';
        }
        if ($licence === null) {
            $this->warn(sprintf('%s is a licence without a WHMCS Licensing record: it has no licence key yet.', $name));
        }
        // A reissued WHMCS licence binds again on its next check: it keeps no bindings.
        $reissued = $licence !== null && strtolower(trim((string) ($licence['status'] ?? ''))) === 'reissued';
        $list = static fn (mixed $value): string => (string) json_encode($reissued ? [] : array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) $value) ?: []), static fn (string $v): bool => $v !== ''))));
        $this->db->insert('service_license', [
            'client_id' => $clientId,
            'license_key' => $key === '' ? null : $key,
            'validate_ip' => !empty($productConfig['validate_ip']) ? 1 : 0,
            'validate_host' => !empty($productConfig['validate_host']) ? 1 : 0,
            'validate_path' => !empty($productConfig['validate_path']) ? 1 : 0,
            'validate_version' => !empty($productConfig['validate_version']) ? 1 : 0,
            'ips' => $list($licence['validip'] ?? ''),
            'hosts' => $list($licence['validdomain'] ?? ''),
            'paths' => $list($licence['validdirectory'] ?? ''),
            'versions' => '[]',
            // The WHMCS ids its software already knows, for WHMCS-style check answers (Servicelicense/WhmcsCheck.php).
            'config' => json_encode(['whmcs' => ['service_id' => $serviceId, 'product_id' => $whmcsProductId, 'billingcycle' => $whmcsCycle]]),
            'plugin' => (string) ($productConfig['plugin'] ?? 'Simple'),
            'checked_at' => Accounts::date($licence['lastaccess'] ?? null),
            'pinged_at' => null,
            'created_at' => $this->now, 'updated_at' => $this->now,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @param array<string, mixed> $config */
    private function custom(int $clientId, array $config): int
    {
        $this->db->insert('service_custom', [
            'client_id' => $clientId,
            'plugin' => null,
            'plugin_config' => null,
            'config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $this->now, 'updated_at' => $this->now,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @param array<string, mixed> $row */
    private function serviceAddon(array $row): int
    {
        $id = (int) $row['id'];
        if ($this->map->has('serviceaddon', $id) || $this->map->has('skipped', 'serviceaddon:' . $id)) {
            return 0;
        }
        $parentWhmcs = (int) ($row['hostingid'] ?? 0);
        $parentOrder = $this->map->get('service', $parentWhmcs);
        $clientId = (int) ($row['userid'] ?? 0);
        if ($clientId <= 0 && $parentOrder !== null) {
            $clientId = (int) $this->db->fetchOne('SELECT client_id FROM client_order WHERE id = ?', [$parentOrder]);
            $client = $this->clientById($clientId);
        } else {
            $client = $this->client($clientId);
        }
        $title = trim((string) ($row['name'] ?? ''));
        $productId = (int) ($row['addonid'] ?? 0) > 0 ? $this->map->get('addon', (int) $row['addonid']) : $this->customAddon($title);
        $name = sprintf('Service add-on #%d%s', $id, $title === '' ? '' : ' (' . $title . ')');
        if ($client === null || $productId === null) {
            $this->map->put('skipped', 'serviceaddon:' . $id, 0);
            $this->warn(sprintf('%s was not imported: its %s was not imported.', $name, $client === null ? 'client' : 'add-on'));

            return 0;
        }
        $title = $title !== '' ? $title : (string) $this->db->fetchOne('SELECT title FROM product WHERE id = ?', [$productId]);
        $whmcsStatus = strtolower(trim((string) ($row['status'] ?? '')));
        $status = self::STATUSES[$whmcsStatus] ?? 'canceled';
        $cycle = strtolower(trim((string) ($row['billingcycle'] ?? '')));
        $period = Catalogue::CYCLE_NAMES[$cycle] ?? null;
        [$quantity, $price] = $this->units((int) ($row['qty'] ?? 1), (float) ($row['recurring'] ?? 0), $name);
        $config = ['product_id' => $productId, 'type' => 'custom', 'period' => $period, 'quantity' => $quantity, 'whmcs_service_addon_id' => $id];
        if ($parentOrder === null) {
            $this->warn(sprintf('%s: its service #%d was not imported; it is listed on its own.', $name, $parentWhmcs));
        }
        $orderId = $this->order([
            'client_id' => $client['id'],
            'product_id' => $productId,
            'form_id' => null,
            'promo_id' => null,
            'group_id' => $parentOrder === null ? 'whmcs_a' . $id : 'whmcs_s' . $parentWhmcs,
            'group_master' => $parentOrder === null ? 1 : 0,
            'title' => self::cut($title, 255),
            'currency' => $client['currency'],
            'service_type' => 'custom',
            'period' => $period,
            'quantity' => $quantity,
            'price' => $price,
            'notes' => trim((string) ($row['notes'] ?? '')) ?: null,
            'config' => $config,
        ], $row, $whmcsStatus, $status, $period !== null && $whmcsStatus !== 'completed', (string) ($row['regdate'] ?? ''), $name);
        $this->db->update('client_order', ['service_id' => $this->custom($client['id'], $config)], ['id' => $orderId]);
        $this->map->put('serviceaddon', $id, $orderId);

        return 1;
    }

    /**
     * The product of a WHMCS add-on typed in on the service (no catalogue add-on): one hidden,
     * disabled custom add-on per name, so the order has a product.
     */
    private function customAddon(string $title): int
    {
        $title = self::cut($title, 255) ?: 'Add-on';
        $key = mb_strtolower($title);
        $known = $this->map->get('customaddon', $key);
        if ($known !== null) {
            return $known;
        }
        $this->db->insert('product_payment', ['type' => 'free']);
        $paymentId = (int) $this->db->lastInsertId();
        $slug = 'whmcs-addon-' . substr(sha1($key), 0, 12);
        $this->db->insert('product', [
            'product_category_id' => null, 'product_payment_id' => $paymentId, 'form_id' => null, 'title' => $title,
            'slug' => $slug, 'description' => null, 'unit' => 'product', 'active' => 1, 'status' => 'disabled',
            'hidden' => 1, 'is_addon' => 1, 'setup' => 'manual', 'addons' => null, 'icon_url' => null,
            'allow_quantity_select' => 0, 'stock_control' => 0, 'quantity_in_stock' => 0, 'plugin' => null,
            'plugin_config' => null, 'upgrades' => null, 'priority' => 0, 'config' => '{}', 'type' => 'custom',
            'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
        $productId = (int) $this->db->lastInsertId();
        $this->map->put('customaddon', $key, $productId);
        $this->warn(sprintf('Add-on "%s" was typed in on services in WHMCS: it is a hidden, disabled add-on product so its services keep their history.', $title));

        return $productId;
    }

    /** @param array<string, mixed> $row */
    private function domain(array $row): int
    {
        $id = (int) $row['id'];
        if ($this->map->has('domain', $id) || $this->map->has('skipped', 'domain:' . $id)) {
            return 0;
        }
        $domain = strtolower(trim((string) ($row['domain'] ?? '')));
        $name = sprintf('Domain %s', $domain === '' ? '#' . $id : $domain);
        $client = $this->client((int) ($row['userid'] ?? 0));
        $productId = $this->db->fetchOne("SELECT id FROM product WHERE type = 'domain' ORDER BY id");
        [$sld, $tld] = $this->split($domain);
        if ($client === null || $productId === false || $sld === '') {
            $this->map->put('skipped', 'domain:' . $id, 0);
            $why = $client === null ? 'its client was not imported' : ($productId === false ? 'this install has no domain product' : 'it has no domain name');
            $this->warn(sprintf('%s was not imported: %s.', $name, $why));

            return 0;
        }
        $whmcsStatus = strtolower(trim((string) ($row['status'] ?? '')));
        $status = self::DOMAIN_STATUSES[$whmcsStatus] ?? 'canceled';
        $action = strtolower(trim((string) ($row['type'] ?? ''))) === 'transfer' ? 'transfer' : 'register';
        $years = max(1, min(10, (int) ($row['registrationperiod'] ?? 1)));
        $period = $years . 'Y';
        $config = [
            'product_id' => (int) $productId, 'type' => 'domain', 'action' => $action, 'period' => $period,
            $action . '_sld' => $sld, $action . '_tld' => $tld, 'register_years' => $years, 'whmcs_domain_id' => $id,
        ];
        $price = max(0.0, (float) ($row['recurringamount'] ?? 0));
        if (!in_array($whmcsStatus, array_keys(self::DOMAIN_STATUSES), true)) {
            $this->warn(sprintf('%s: WHMCS status "%s" is not known; it was imported as canceled.', $name, (string) $row['status']));
        }
        if (in_array($whmcsStatus, ['grace', 'redemption'], true)) {
            $this->warn(sprintf('%s is in its %s period at the registrar: renew it there or let it go.', $name, $whmcsStatus));
        }
        $noRenew = (int) ($row['donotrenew'] ?? 0) === 1;
        if ($status === 'active' && !$noRenew) {
            $this->renewalCheck($name, $tld, $client['currency'], $price, $years);
        }

        $orderId = $this->order([
            'client_id' => $client['id'],
            'product_id' => (int) $productId,
            'form_id' => null,
            'promo_id' => $this->map->get('promo', (int) ($row['promoid'] ?? 0)),
            'group_id' => 'whmcs_d' . $id,
            'group_master' => 1,
            'title' => sprintf('Domain %s %s', $sld . $tld, $action === 'transfer' ? 'transfer' : 'registration'),
            'currency' => $client['currency'],
            'service_type' => 'domain',
            'period' => $period,
            'quantity' => 1,
            'price' => $price,
            'notes' => trim((string) ($row['additionalnotes'] ?? '')) ?: null,
            'config' => $config,
            'invoice_option' => $noRenew ? 'no-invoice' : 'issue-invoice',
        ], $row, $whmcsStatus, $status, true, (string) ($row['registrationdate'] ?? ''), $name);

        $registrarModule = strtolower(trim((string) ($row['registrar'] ?? '')));
        $contact = $this->db->fetchAssociative('SELECT * FROM client WHERE id = ?', [$client['id']]) ?: [];
        $this->db->insert('service_domain', [
            'client_id' => $client['id'],
            'tld_registrar_id' => $this->catalogue->registrar($registrarModule),
            'sld' => $sld, 'tld' => $tld,
            'period' => $years,
            'privacy' => (int) ($row['idprotection'] ?? 0) === 1 ? 1 : 0,
            'locked' => 1,
            'action' => $action,
            'contact_email' => $contact['email'] ?? null, 'contact_company' => $contact['company'] ?? null,
            'contact_first_name' => $contact['first_name'] ?? null, 'contact_last_name' => $contact['last_name'] ?? null,
            'contact_address1' => $contact['address_1'] ?? null, 'contact_address2' => $contact['address_2'] ?? null,
            'contact_city' => $contact['city'] ?? null, 'contact_state' => $contact['state'] ?? null,
            'contact_postcode' => $contact['postcode'] ?? null, 'contact_country' => $contact['country'] ?? null,
            'contact_phone_cc' => $contact['phone_cc'] ?? null, 'contact_phone' => $contact['phone'] ?? null,
            'registered_at' => Accounts::date($row['registrationdate'] ?? null),
            'expires_at' => Accounts::date($row['expirydate'] ?? null) ?? Accounts::date($row['nextduedate'] ?? null),
            'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
        $this->db->update('client_order', ['service_id' => (int) $this->db->lastInsertId()], ['id' => $orderId]);
        $this->map->put('domain', $id, $orderId);
        $extras = array_filter(['DNS management' => (int) ($row['dnsmanagement'] ?? 0), 'email forwarding' => (int) ($row['emailforwarding'] ?? 0)]);
        if ($extras !== [] && $status !== 'canceled') {
            $this->warn(sprintf('%s had %s in WHMCS: ClientFence does not sell it; it stays at the registrar as it is.', $name, implode(' and ', array_keys($extras))));
        }

        return 1;
    }

    /**
     * Warns when a domain's WHMCS renewal amount differs from what ClientFence will charge: the
     * TLD's renewal price (a fixed price in the currency, else converted) for the term.
     */
    private function renewalCheck(string $name, string $tld, string $currency, float $price, int $years): void
    {
        $tldRow = $this->db->fetchAssociative('SELECT id, price_renew FROM tld WHERE tld = ?', [$tld]);
        if ($tldRow === false) {
            $this->warn(sprintf('%s: this install has no %s domain price, so it cannot renew; add %s under Domains.', $name, $tld, $tld));

            return;
        }
        $fixed = \ClientFence\Mod\Product\CurrencyPrices::forItem($this->db, 'tld', (int) $tldRow['id'], $currency)['renew']['price'] ?? null;
        if ($fixed !== null) {
            $renew = (float) $fixed;
        } else {
            $rate = (float) ($this->db->fetchOne('SELECT conversion_rate FROM currency WHERE code = ?', [$currency]) ?: 1);
            $renew = round((float) $tldRow['price_renew'] * $rate, 2);
        }
        if (abs($renew * $years - $price) > 0.009) {
            $this->warn(sprintf('%s: WHMCS renewed it for %s %s; ClientFence renews it at the %s price, %s %s.', $name, number_format($price, 2, '.', ''), $currency, $tld, number_format($renew * $years, 2, '.', ''), $currency));
        }
    }

    /**
     * The quantity and unit price of a WHMCS amount, which is the total for all units (WHMCS's
     * scaled quantities) while an order's price is per unit (ClientFence bills price × quantity).
     * When the total does not divide into whole cents, the order keeps one unit at the total, so
     * it renews at exactly what WHMCS billed, and the quantity is named in a warning.
     *
     * @return array{0: int, 1: float}
     */
    private function units(int $quantity, float $total, string $name): array
    {
        $quantity = max(1, $quantity);
        $total = max(0.0, $total);
        if ($quantity === 1) {
            return [1, $total];
        }
        $unit = round($total / $quantity, 2);
        if (abs($unit * $quantity - $total) < 0.005) {
            return [$quantity, $unit];
        }
        $this->warn(sprintf('%s: WHMCS billed %d units for %s in all, which is not a whole amount per unit; it is one unit at that total.', $name, $quantity, number_format($total, 2, '.', '')));

        return [1, $total];
    }

    /**
     * Writes an order with its dates, status history and cancellation request.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $row      the WHMCS row (dates, reasons, subscription)
     * @param int                  $cancelId the WHMCS service whose cancellation requests apply (0: none)
     */
    private function order(array $values, array $row, string $whmcsStatus, string $status, bool $renews, string $start, string $name, int $cancelId = 0): int
    {
        $created = Accounts::date($row['created_at'] ?? null) ?? Accounts::date($start) ?? $this->now;
        $next = Accounts::date($row['nextduedate'] ?? null);
        $ended = Accounts::date($row['termination_date'] ?? null);
        $request = $this->cancelRequest($cancelId);
        $reason = match ($status) {
            'suspended' => trim((string) ($row['suspendreason'] ?? '')) ?: null,
            'canceled' => trim(sprintf('%s in WHMCS%s', ucwords($whmcsStatus) ?: 'Ended', $request === null || $request['reason'] === '' ? '' : ': ' . $request['reason'])),
            default => null,
        };
        $values['config'] = json_encode($values['config'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $values['title'] = self::cut($values['title'], 255); // "Product for a-very-long-domain" stopped the whole import
        $this->db->insert('client_order', $values + [
            'promo_recurring' => 0,
            'promo_used' => null,
            'invoice_option' => 'issue-invoice',
            'unpaid_invoice_id' => null,
            'unit' => 'product',
            'discount' => 0,
            'status' => $status,
            'reason' => $reason === null ? null : self::cut($reason, 255),
            'referred_by' => null,
            // A one-time, free or completed service never renews; an ended one keeps its last date.
            'expires_at' => $renews || $status === 'canceled' ? $next : null,
            'activated_at' => in_array($status, ['active', 'suspended'], true) || $ended !== null ? Accounts::date($start) ?? $created : null,
            'suspended_at' => $status === 'suspended' ? $this->now : null,
            'unsuspended_at' => null,
            'canceled_at' => $status === 'canceled' ? ($ended ?? Accounts::date($row['updated_at'] ?? null) ?? $this->now) : null,
            'created_at' => $created,
            'updated_at' => $this->now,
        ]);
        $orderId = (int) $this->db->lastInsertId();
        $this->db->insert('client_order_status', [
            'client_order_id' => $orderId, 'status' => $status,
            'notes' => sprintf('Imported from WHMCS (%s, status %s)', $name, (string) ($row['domainstatus'] ?? $row['status'] ?? '')),
            'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
        $subscription = trim((string) ($row['subscriptionid'] ?? ''));
        if ($subscription !== '') {
            $this->meta($orderId, 'whmcs_subscription_id', $subscription);
            if ($status !== 'canceled' && !$this->map->has('skipped', 'notice:subscriptions')) {
                $this->map->put('skipped', 'notice:subscriptions', 0);
                $this->warn('Some services pay through a gateway subscription in WHMCS (their order keeps its id as "whmcs_subscription_id"): ClientFence does not charge these subscriptions; their clients pay renewal invoices instead.');
            }
        }
        if ($request !== null && in_array($status, ['active', 'suspended'], true)) {
            \ClientFence\Mod\Order\CancelRequests::insert(
                $this->db, $orderId, (int) $values['client_id'],
                strcasecmp($request['type'], 'Immediate') === 0 ? \ClientFence\Mod\Order\CancelRequests::IMMEDIATE : \ClientFence\Mod\Order\CancelRequests::END_OF_PERIOD,
                $request['reason'] === '' ? 'Cancellation requested in WHMCS' : $request['reason'], $request['date'] ?? $this->now,
            );
            $this->warn(sprintf('%s has a cancellation request (%s): it waits in Orders > Cancellation Requests.', $name, $request['type'] ?: 'no type'));
        }
        if ($status !== 'canceled' && ((string) ($row['overideautosuspend'] ?? '') === 'on' || (int) ($row['overideautosuspend'] ?? 0) === 1)) {
            $until = Accounts::date($row['overidesuspenduntil'] ?? null);
            $this->meta($orderId, \ClientFence\Mod\Order\Service::META_AUTO_SUSPEND_OVERRIDE, '1');
            $this->meta($orderId, \ClientFence\Mod\Order\Service::META_AUTO_SUSPEND_UNTIL, $until === null ? '' : substr($until, 0, 10));
        }
        if ($renews && $status === 'active' && $next !== null && $next < $this->now) {
            $this->warn(sprintf('%s was due on %s: it is overdue.', $name, substr($next, 0, 10)));
        }

        return $orderId;
    }

    /**
     * A service's newest cancellation request (tblcancelrequests holds services only): [type, reason, date].
     *
     * @return array{type: string, reason: string, date: ?string}|null
     */
    private function cancelRequest(int $serviceId): ?array
    {
        if ($serviceId <= 0 || !$this->source->hasTable('tblcancelrequests')) {
            return null;
        }
        $row = $this->source->rows('SELECT type, reason, date FROM tblcancelrequests WHERE relid = ? ORDER BY id DESC LIMIT 1', [$serviceId])[0] ?? null;

        return $row === null ? null : ['type' => trim((string) $row['type']), 'reason' => trim((string) $row['reason']), 'date' => Accounts::date($row['date'] ?? null)];
    }

    private function meta(int $orderId, string $name, string $value): void
    {
        $this->db->insert('client_order_meta', ['client_order_id' => $orderId, 'name' => $name, 'value' => $value, 'created_at' => $this->now, 'updated_at' => $this->now]);
    }

    /**
     * The imported client of a WHMCS client id: [id, currency].
     *
     * @return array{id: int, currency: string}|null
     */
    private function client(int $whmcsId): ?array
    {
        $id = $this->map->get('client', $whmcsId);

        return $id === null ? null : $this->clientById($id);
    }

    /** @return array{id: int, currency: string}|null */
    private function clientById(int $id): ?array
    {
        $currency = $this->db->fetchOne('SELECT currency FROM client WHERE id = ?', [$id]);
        if ($currency === false) {
            return null;
        }
        $currency = (string) $currency;
        if ($currency === '') {
            $currency = (string) $this->db->fetchOne('SELECT code FROM currency WHERE is_default = 1 ORDER BY id');
        }

        return ['id' => $id, 'currency' => $currency];
    }

    /**
     * A domain name as [sld, tld]: the longest TLD this install prices (".co.uk" before ".uk"),
     * else the last label.
     *
     * @return array{0: string, 1: string}
     */
    private function split(string $domain): array
    {
        $domain = trim(strtolower($domain), " .\t\n\r");
        if ($domain === '' || !str_contains($domain, '.')) {
            return [$domain, ''];
        }
        if (!array_key_exists($domain, $this->tlds)) {
            $labels = explode('.', $domain);
            $found = null;
            for ($i = 1; $i < count($labels); ++$i) {
                $tld = '.' . implode('.', array_slice($labels, $i));
                if ($this->db->fetchOne('SELECT id FROM tld WHERE tld = ?', [$tld]) !== false) {
                    $found = $tld;

                    break;
                }
            }
            $this->tlds[$domain] = $found ?? '.' . end($labels);
        }
        $tld = (string) $this->tlds[$domain];

        return [substr($domain, 0, -strlen($tld)), $tld];
    }

    private function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    private static function cut(mixed $value, int $max): string
    {
        return mb_substr(trim(is_scalar($value) ? (string) $value : ''), 0, $max);
    }
}
