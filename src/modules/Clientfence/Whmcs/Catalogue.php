<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Whmcs;

use ClientFence\Config;
use ClientFence\Mod\Product\CurrencyPrices;
use ClientFence\Mod\Product\Translations;
use Doctrine\DBAL\Connection;

/**
 * ClientFence: the WHMCS import's catalogue steps (Run.php runs them in batches, after the account
 * steps): product groups, hosting servers, products with their prices and custom fields, product
 * add-ons, upgrade paths, promotions and domain prices; bundles are named in the warnings only.
 * As in Accounts.php, rows are written directly, each imported row goes in the id map and a row
 * left out with a warning is noted there as "skipped".
 *
 * Prices: WHMCS keeps a price per currency (tblpricing); the default currency's become the
 * prices, the others fixed prices in that currency (Product/CurrencyPrices.php), so every WHMCS
 * price stays as it was.
 *
 * Products: a server module ClientFence has (cPanel, DirectAdmin, Plesk, CWP, Hestia, Enhance)
 * makes a hosting product on the first server of its WHMCS server group, with a hosting plan
 * named after the WHMCS package; WHMCS's Licensing makes a licence product; any other module a
 * custom product (a warning names the module). Product custom fields become the product's order
 * form (fields named whmcs_field_<id>, which the services step fills).
 *
 * Configurable options: each WHMCS option group becomes an option group on the same products, its
 * options (dropdown, radio, yes/no, quantity) and values with their prices in the default currency;
 * other currencies convert at the rate (Product/Options.php), and an option priced otherwise in
 * another currency is named in the warnings.
 *
 * Other languages: WHMCS's Dynamic Field Translation texts (tbldynamic_translations) of products,
 * add-ons and product groups become the item's texts in that language (Product/Translations.php).
 *
 * Hosting servers keep their password (read with WHMCS's encryption key, stored encrypted:
 * Servicehosting/ServerSecrets.php) and API key, and are imported switched off (owner decision
 * 2026-10-19): nothing is created or suspended on a live server until staff switch it on.
 */
final class Catalogue
{
    /** The steps in order: name => [label, WHMCS table read]. */
    public const STEPS = [
        'categories' => ['Product groups', 'tblproductgroups'],
        'servers' => ['Hosting servers (switched off)', 'tblservers'],
        'products' => ['Products and prices', 'tblproducts'],
        'addons' => ['Product add-ons', 'tbladdons'],
        'options' => ['Configurable option groups', 'tblproductconfiggroups'],
        'upgrades' => ['Upgrade paths', 'tblproduct_upgrade_products'],
        'promotions' => ['Promotions', 'tblpromotions'],
        'tlds' => ['Domain prices', 'tbldomainpricing'],
        'taxes' => ['Tax rules', 'tbltax'],
        'bundles' => ['Bundles (not imported)', 'tblbundles'],
    ];

    /** WHMCS server modules => ClientFence server managers. */
    public const SERVER_MANAGERS = [
        'cpanel' => 'Whm', 'directadmin' => 'Directadmin', 'plesk' => 'Plesk', 'cwp7' => 'CWP', 'cwp' => 'CWP',
        'hestiacp' => 'Hestia', 'hestia' => 'Hestia', 'enhance' => 'Enhance',
    ];

    /** WHMCS billing cycles (tblpricing columns) => [product_payment key, period, setup fee column]. */
    public const CYCLES = [
        'monthly' => ['m', '1M', 'msetupfee'],
        'quarterly' => ['q', '3M', 'qsetupfee'],
        'semiannually' => ['b', '6M', 'ssetupfee'],
        'annually' => ['a', '1Y', 'asetupfee'],
        'biennially' => ['bia', '2Y', 'bsetupfee'],
        'triennially' => ['tria', '3Y', 'tsetupfee'],
    ];

    /** WHMCS cycle names (promotions, add-ons) => periods. */
    public const CYCLE_NAMES = [
        'monthly' => '1M', 'quarterly' => '3M', 'semi-annually' => '6M', 'semiannually' => '6M', 'annually' => '1Y',
        'biennially' => '2Y', 'triennially' => '3Y',
    ];

    /** tblpricing columns of a domain price row => years. */
    public const DOMAIN_YEARS = [
        'msetupfee' => 1, 'qsetupfee' => 2, 'ssetupfee' => 3, 'asetupfee' => 4, 'bsetupfee' => 5,
        'monthly' => 6, 'quarterly' => 7, 'semiannually' => 8, 'annually' => 9, 'biennially' => 10,
    ];

    private const PAYMENT_KEYS = ['w', 'm', 'q', 'b', 'a', 'bia', 'tria'];

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<int, string>|null WHMCS currency id => code */
    private ?array $codes = null;

    private ?string $defaultCode = null;

    /**
     * @param \Closure(string): string             $seal         stores a server secret encrypted
     * @param \Closure(string): list<string>       $secretFields a server manager's secret fields
     * @param list<string>                         $registrars   this install's registrar adapters
     */
    public function __construct(
        private readonly Source $source,
        private readonly Connection $db,
        private readonly Map $map,
        private readonly ?Cipher $cipher,
        private readonly \Closure $seal,
        private readonly \Closure $secretFields,
        private readonly array $registrars,
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
        if ($step === 'upgrades' && !$this->source->hasTable('tblproduct_upgrade_products')) {
            return 0;
        }
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
        if ($step === 'categories' && $after === 0) {
            $this->languageWarnings();
        }
        if (!$this->source->hasTable($table)) {
            return [null, 0];
        }
        $rows = $this->source->rows('SELECT * FROM ' . $table . ' WHERE id > ? ORDER BY id LIMIT ' . $limit, [$after]);
        if ($rows === []) {
            return [null, 0];
        }
        $done = 0;
        if ($step === 'upgrades') {
            $done = $this->upgrades($rows);
        } else {
            foreach ($rows as $row) {
                $done += match ($step) {
                    'categories' => $this->category($row),
                    'servers' => $this->server($row),
                    'products' => $this->product($row),
                    'addons' => $this->addon($row),
                    'options' => $this->optionGroup($row),
                    'promotions' => $this->promotion($row),
                    'tlds' => $this->tld($row),
                    'taxes' => $this->taxRule($row),
                    'bundles' => $this->bundle($row),
                };
            }
        }

        return [(int) end($rows)['id'], $done];
    }

    /** @param array<string, mixed> $row */
    private function category(array $row): int
    {
        if ($this->map->has('category', (int) $row['id'])) {
            return 0;
        }
        $description = trim(implode("\n\n", array_filter([trim((string) ($row['headline'] ?? '')), trim((string) ($row['tagline'] ?? ''))])));
        $this->db->insert('product_category', [
            'title' => self::cut($row['name'] ?? '', 100) ?: 'Group ' . $row['id'],
            'description' => $description === '' ? null : $description,
            'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
        $categoryId = (int) $this->db->lastInsertId();
        $this->map->put('category', (int) $row['id'], $categoryId);
        self::translate($this->source, $this->db, $this->now, 'product_group', $row, 'category', $categoryId);

        return 1;
    }

    /** @param array<string, mixed> $row */
    private function server(array $row): int
    {
        $id = (int) $row['id'];
        if ($this->map->has('server', $id) || $this->map->has('skipped', 'server:' . $id)) {
            return 0;
        }
        $name = self::cut($row['name'] ?? '', 100) ?: 'Server ' . $id;
        $module = strtolower(trim((string) ($row['type'] ?? '')));
        $manager = self::SERVER_MANAGERS[$module] ?? null;
        if ($manager === null) {
            $this->map->put('skipped', 'server:' . $id, 0);
            $this->warn(sprintf('Hosting server "%s" uses the WHMCS module "%s", which ClientFence has no server manager for; it was not imported.', $name, $module));

            return 0;
        }
        $secret = ($this->secretFields)($manager);
        $password = null;
        if (trim((string) ($row['password'] ?? '')) !== '') {
            $password = $this->cipher?->decrypt((string) $row['password']);
            if ($password === null) {
                $this->warn(sprintf('Hosting server "%s": its password could not be read%s; enter it on the server page.', $name, $this->cipher === null ? ' (no WHMCS encryption key)' : ''));
            }
        }
        $username = trim((string) ($row['username'] ?? ''));
        $accesshash = trim((string) ($row['accesshash'] ?? ''));
        $ips = preg_split('/[\s,]+/', (string) ($row['assignedips'] ?? '')) ?: [];
        $ips = array_values(array_filter(array_map('trim', $ips), static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false));
        $port = trim((string) ($row['port'] ?? ''));
        $this->db->insert('service_hosting_server', [
            'name' => $name,
            'ip' => self::cut($row['ipaddress'] ?? '', 45),
            'hostname' => self::cut($row['hostname'] ?? '', 100) ?: null,
            'assigned_ips' => json_encode($ips),
            'status_url' => self::cut($row['statusaddress'] ?? '', 255) ?: null,
            // Owner decision: switched off until the cutover.
            'active' => 0,
            'max_accounts' => (int) ($row['maxaccounts'] ?? 0) ?: null,
            'ns1' => self::cut($row['nameserver1'] ?? '', 100) ?: null,
            'ns2' => self::cut($row['nameserver2'] ?? '', 100) ?: null,
            'ns3' => self::cut($row['nameserver3'] ?? '', 100) ?: null,
            'ns4' => self::cut($row['nameserver4'] ?? '', 100) ?: null,
            'manager' => $manager,
            'username' => $username === '' ? null : (in_array('username', $secret, true) ? ($this->seal)($username) : $username),
            'password' => $password === null || $password === '' ? null : ($this->seal)($password),
            'accesshash' => $accesshash === '' ? null : ($this->seal)($accesshash),
            'port' => ctype_digit($port) && (int) $port > 0 ? $port : null,
            'config' => json_encode(new \stdClass()),
            'secure' => in_array(strtolower(trim((string) ($row['secure'] ?? ''))), ['on', '1'], true) ? 1 : 0,
            'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
        $this->map->put('server', $id, (int) $this->db->lastInsertId());
        if (!$this->map->has('skipped', 'notice:servers')) {
            $this->map->put('skipped', 'notice:servers', 0);
            $this->warn('Hosting servers were imported switched off: nothing is created or suspended on them until you switch each on (Hosting > Servers) at the cutover. Test each connection first.');
        }

        return 1;
    }

    /** @param array<string, mixed> $row */
    private function product(array $row): int
    {
        $id = (int) $row['id'];
        if ($this->map->has('product', $id)) {
            return 0;
        }
        $title = self::cut($row['name'] ?? '', 255) ?: 'Product ' . $id;
        $module = strtolower(trim((string) ($row['servertype'] ?? '')));
        [$type, $config] = $this->productKind($row, $title, $module);
        // The product's affiliate commission (Client/Affiliates/Commissions::rule()); WHMCS's '' is the default.
        $payout = str_replace('fixedamount', 'fixed', strtolower(trim((string) ($row['affiliatepaytype'] ?? ''))));
        if (in_array($payout, ['percentage', 'fixed', 'none'], true)) {
            $config += [
                'affiliate_payout' => $payout,
                'affiliate_amount' => number_format(max(0.0, (float) ($row['affiliatepayamount'] ?? 0)), 2, '.', ''),
                'affiliate_onetime' => in_array(strtolower(trim((string) ($row['affiliateonetime'] ?? ''))), ['on', '1', 'yes'], true) ? '1' : '0',
            ];
        }
        $groupHidden = $this->groupHidden((int) ($row['gid'] ?? 0));
        $paymentId = $this->payment('product', $id, (string) ($row['paytype'] ?? 'recurring'), null, $title);
        $productId = $this->insertProduct([
            'product_category_id' => $this->map->get('category', (int) ($row['gid'] ?? 0)),
            'product_payment_id' => $paymentId,
            'title' => $title,
            'slug' => $this->slug((string) ($row['slug'] ?? '') ?: $title),
            'description' => trim((string) ($row['description'] ?? '')) ?: null,
            'status' => (int) ($row['retired'] ?? 0) === 1 ? 'disabled' : 'enabled',
            'hidden' => (int) ($row['hidden'] ?? 0) === 1 || $groupHidden ? 1 : 0,
            'is_addon' => 0,
            'setup' => self::setup((string) ($row['autosetup'] ?? '')),
            'allow_quantity_select' => (int) ($row['allowqty'] ?? 0) > 0 ? 1 : 0,
            'stock_control' => (int) ($row['stockcontrol'] ?? 0) === 1 ? 1 : 0,
            'quantity_in_stock' => max(0, (int) ($row['qty'] ?? 0)),
            'priority' => (int) ($row['order'] ?? 0),
            'type' => $type,
            'config' => json_encode($config === [] ? new \stdClass() : $config),
        ]);
        $this->currencyPrices('product', $id, $productId, $title);
        $this->map->put('product', $id, $productId);
        self::translate($this->source, $this->db, $this->now, 'product', $row, 'product', $productId);

        $formId = $this->form($id, $title);
        if ($formId !== null) {
            $this->db->update('product', ['form_id' => $formId], ['id' => $productId]);
        }
        $this->productWarnings($row, $title);

        return 1;
    }

    /**
     * The ClientFence setup of a WHMCS product's "autosetup" or an add-on's "autoactivate": "order"
     * sets up when the order is placed, "payment" on the first payment, "on" only when staff accept
     * the order and an empty value never automatically.
     */
    public static function setup(string $whmcs): string
    {
        return match (strtolower(trim($whmcs))) {
            'order' => 'after_order',
            'payment' => 'after_payment',
            default => 'manual',
        };
    }

    /**
     * The ClientFence product type and settings of a WHMCS product.
     *
     * @param array<string, mixed> $row
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function productKind(array $row, string $title, string $module): array
    {
        if ($module === 'licensing') {
            $this->warn(sprintf('Product "%s" is a WHMCS Licensing product: it is a licence product; check its licence settings (validation of IP, domain, directory).', $title));

            // Its software checks at WHMCS's address: those checks get WHMCS-style answers (Servicelicense/WhmcsCheck.php).
            return ['license', ['plugin' => 'Simple', 'validate_ip' => true, 'validate_host' => true, 'validate_path' => false, 'validate_version' => false, 'whmcs_check' => true]];
        }
        $manager = self::SERVER_MANAGERS[$module] ?? null;
        if ($manager === null) {
            if ($module !== '' && $module !== 'none' && $module !== 'autorelease') {
                $this->warn(sprintf('Product "%s" uses the WHMCS module "%s": it is a custom product; set up its provisioning by hand.', $title, $module));
            }

            return ['custom', []];
        }
        $serverId = $this->groupServer((int) ($row['servergroup'] ?? 0), $manager, $title);
        if ($serverId === null) {
            $this->warn(sprintf('Product "%s" (%s) has no imported server in its WHMCS server group: it is a custom product; make it a hosting product once its server is set up.', $title, $module));

            return ['custom', []];
        }
        $package = trim((string) ($row['configoption1'] ?? '')) ?: $title;
        $planKey = $manager . '|' . $package;
        $planId = $this->map->get('hostingplan', $planKey);
        if ($planId === null) {
            // WHM's manager names a plan's package "<server username>_<plan>" unless the plan names
            // the package exactly; WHMCS stores the exact name, so the plan uses it as it is.
            $planConfig = $manager === 'Whm' ? ['package' => $package] : new \stdClass();
            $this->db->insert('service_hosting_hp', ['name' => self::cut($package, 255), 'config' => json_encode($planConfig), 'created_at' => $this->now, 'updated_at' => $this->now]);
            $planId = (int) $this->db->lastInsertId();
            $this->map->put('hostingplan', $planKey, $planId);
        }
        $config = ['server_id' => $serverId, 'hosting_plan_id' => $planId, 'reseller' => strtolower((string) ($row['type'] ?? '')) === 'reselleraccount'];
        $free = strtolower(trim((string) ($row['freedomain'] ?? '')));
        if ($free !== '') {
            $tlds = array_values(array_filter(array_map(static fn (string $t): string => '.' . ltrim(strtolower(trim($t)), '.'), explode(',', (string) ($row['freedomaintlds'] ?? ''))), static fn (string $t): bool => $t !== '.'));
            $periods = [];
            foreach (explode(',', strtolower((string) ($row['freedomainpaymentterms'] ?? ''))) as $term) {
                $period = self::CYCLE_NAMES[trim($term)] ?? null;
                if ($period !== null) {
                    $periods[] = $period;
                }
            }
            $config += ['free_domain' => true, 'free_transfer' => true, 'free_tlds' => $tlds, 'free_domain_periods' => array_values(array_unique($periods)), 'free_domain_renewals' => $free === 'on'];
            if ($free === 'on') {
                $this->warn(sprintf('Product "%s": its new orders renew their free domain free while the hosting lasts, as in WHMCS. Its domains imported from WHMCS are not linked to their hosting, so they renew at the TLD price.', $title));
            }
        }

        return ['hosting', $config];
    }

    /** The first imported server of a WHMCS server group (with the manager), or null. */
    private function groupServer(int $groupId, string $manager, string $title): ?int
    {
        if ($groupId <= 0 || !$this->source->hasTable('tblservergroupsrel')) {
            return null;
        }
        $servers = [];
        foreach ($this->source->rows('SELECT serverid FROM tblservergroupsrel WHERE groupid = ? ORDER BY serverid', [$groupId]) as $rel) {
            $serverId = $this->map->get('server', (int) $rel['serverid']);
            if ($serverId !== null && $this->db->fetchOne('SELECT manager FROM service_hosting_server WHERE id = ?', [$serverId]) === $manager) {
                $servers[] = $serverId;
            }
        }
        if (count($servers) > 1) {
            $this->warn(sprintf('Product "%s": its WHMCS server group has %d servers; ClientFence places new accounts on the first one.', $title, count($servers)));
        }

        return $servers[0] ?? null;
    }

    private function groupHidden(int $groupId): bool
    {
        if ($groupId <= 0) {
            return false;
        }

        return (int) $this->source->value('SELECT hidden FROM tblproductgroups WHERE id = ?', [$groupId]) === 1;
    }

    /**
     * A product_payment row from a WHMCS product's or add-on's prices in the default currency.
     * $cycle limits an older add-on to its one billing cycle.
     */
    private function payment(string $kind, int $relid, string $paytype, ?string $cycle, string $title): int
    {
        $paytype = strtolower(trim($paytype));
        $row = $this->prices($kind, $relid)[$this->defaultCode()] ?? null;
        $values = ['type' => 'recurrent'];
        foreach (self::PAYMENT_KEYS as $key) {
            $values[$key . '_enabled'] = 0;
            $values[$key . '_price'] = 0;
            $values[$key . '_setup_price'] = 0;
        }
        if (in_array($paytype, ['free', 'free account'], true)) {
            $values['type'] = 'free';
        } elseif (in_array($paytype, ['onetime', 'one time'], true)) {
            $values['type'] = 'once';
            $values['once_price'] = max(0.0, (float) ($row['monthly'] ?? 0));
            $values['once_setup_price'] = max(0.0, (float) ($row['msetupfee'] ?? 0));
        } else {
            foreach (self::CYCLES as $column => [$key, $period, $setup]) {
                if ($row === null || (float) $row[$column] < 0 || ($cycle !== null && $cycle !== $period)) {
                    continue;
                }
                $values[$key . '_enabled'] = 1;
                $values[$key . '_price'] = (float) $row[$column];
                $values[$key . '_setup_price'] = max(0.0, (float) $row[$setup]);
            }
        }
        if ($row === null && $values['type'] !== 'free') {
            $this->warn(sprintf('"%s" has no price in the default currency %s: no billing period is enabled; set its prices.', $title, $this->defaultCode()));
        }
        $this->db->insert('product_payment', $values);

        return (int) $this->db->lastInsertId();
    }

    /**
     * The fixed prices of a WHMCS product or add-on in the other currencies.
     */
    private function currencyPrices(string $kind, int $relid, int $productId, string $title, ?string $cycle = null): void
    {
        $type = (string) $this->db->fetchOne('SELECT pp.type FROM product p JOIN product_payment pp ON pp.id = p.product_payment_id WHERE p.id = ?', [$productId]);
        $prices = [];
        foreach ($this->prices($kind, $relid) as $code => $row) {
            if ($code === $this->defaultCode() || $type === 'free' || !$this->targetCurrency($code)) {
                continue;
            }
            if ($type === 'once') {
                $prices[$code]['once'] = ['price' => max(0.0, (float) $row['monthly']), 'setup_price' => max(0.0, (float) $row['msetupfee'])];

                continue;
            }
            foreach (self::CYCLES as $column => [, $period, $setup]) {
                if ((float) $row[$column] >= 0 && ($cycle === null || $cycle === $period)) {
                    $prices[$code][$period] = ['price' => (float) $row[$column], 'setup_price' => max(0.0, (float) $row[$setup])];
                }
            }
        }
        if ($prices !== []) {
            CurrencyPrices::save($this->db, 'product', $productId, $prices);
        }
        unset($title);
    }

    /**
     * The order form of a product's WHMCS custom fields, or null when it has none.
     */
    private function form(int $whmcsProductId, string $title): ?int
    {
        $fields = $this->source->rows("SELECT * FROM tblcustomfields WHERE type = 'product' AND relid = ? ORDER BY sortorder, id", [$whmcsProductId]);
        if ($fields === []) {
            return null;
        }
        $this->db->insert('form', ['name' => self::cut($title, 255), 'style' => json_encode(['type' => 'horizontal', 'show_title' => '0'], JSON_FORCE_OBJECT), 'created_at' => $this->now, 'updated_at' => $this->now]);
        $formId = (int) $this->db->lastInsertId();
        foreach ($fields as $field) {
            [$type, $options] = self::fieldType($field);
            $this->db->insert('form_field', [
                'form_id' => $formId,
                'name' => 'whmcs_field_' . (int) $field['id'],
                'label' => self::cut($field['fieldname'] ?? '', 255) ?: 'Field ' . $field['id'],
                'hide_label' => 0,
                'description' => self::cut($field['description'] ?? '', 255) ?: null,
                'type' => $type,
                'required' => (string) ($field['required'] ?? '') === 'on' ? 1 : 0,
                'hidden' => (string) ($field['adminonly'] ?? '') === 'on' || (string) ($field['showorder'] ?? 'on') !== 'on' ? 1 : 0,
                'readonly' => 0,
                'is_unique' => 0,
                'options' => $options,
                'created_at' => $this->now, 'updated_at' => $this->now,
            ]);
            $fieldId = (int) $this->db->lastInsertId();
            $this->map->put('productfield', (int) $field['id'], $fieldId);
            self::translate($this->source, $this->db, $this->now, 'custom_field', $field, 'field', $fieldId);
        }

        return $formId;
    }

    /**
     * A WHMCS custom field's form field type and options.
     *
     * @param array<string, mixed> $field
     *
     * @return array{0: string, 1: ?string}
     */
    public static function fieldType(array $field): array
    {
        $kind = strtolower((string) ($field['fieldtype'] ?? 'text'));
        if ($kind === 'dropdown') {
            $options = [];
            foreach (explode(',', (string) ($field['fieldoptions'] ?? '')) as $option) {
                $option = trim($option);
                if ($option === '') {
                    continue;
                }
                [$value, $label] = str_contains($option, '|') ? array_map('trim', explode('|', $option, 2)) : [$option, $option];
                $options[$label] = $value;
            }

            return ['select', json_encode($options, JSON_FORCE_OBJECT | JSON_UNESCAPED_UNICODE) ?: null];
        }
        if ($kind === 'tickbox') {
            return ['checkbox', json_encode([(string) ($field['fieldname'] ?? 'Yes') => 'on'], JSON_FORCE_OBJECT | JSON_UNESCAPED_UNICODE) ?: null];
        }
        if ($kind === 'textarea') {
            return ['textarea', json_encode(['height' => '100', 'width' => '300'], JSON_FORCE_OBJECT)];
        }

        return ['text', null];
    }

    /** @param array<string, mixed> $row */
    private function productWarnings(array $row, string $title): void
    {
        $unused = [];
        if ((int) ($row['proratabilling'] ?? 0) === 1) {
            $unused[] = 'prorata billing';
        }
        if ((int) ($row['recurringcycles'] ?? 0) > 0) {
            $unused[] = sprintf('a limit of %d billing cycles', (int) $row['recurringcycles']);
        }
        if ((int) ($row['autoterminatedays'] ?? 0) > 0) {
            $unused[] = 'automatic termination';
        }
        if ((int) ($row['overagesenabled'] ?? 0) === 1) {
            $unused[] = 'overage billing';
        }
        if ($unused !== []) {
            $this->warn(sprintf('Product "%s": %s %s not imported.', $title, implode(', ', $unused), count($unused) === 1 ? 'is' : 'are'));
        }
    }

    /** @param array<string, mixed> $row */
    private function addon(array $row): int
    {
        $id = (int) $row['id'];
        if ($this->map->has('addon', $id)) {
            return 0;
        }
        $title = self::cut($row['name'] ?? '', 255) ?: 'Add-on ' . $id;
        $billing = strtolower(trim((string) ($row['billingcycle'] ?? 'recurring')));
        $cycle = self::CYCLE_NAMES[$billing] ?? null;
        $paytype = $cycle !== null ? 'recurring' : $billing;
        $paymentId = $this->payment('addon', $id, $paytype, $cycle, $title);
        $productId = $this->insertProduct([
            'product_category_id' => null,
            'product_payment_id' => $paymentId,
            'title' => $title,
            'slug' => $this->slug($title),
            'description' => trim((string) ($row['description'] ?? '')) ?: null,
            'status' => (int) ($row['retired'] ?? 0) === 1 ? 'disabled' : 'enabled',
            'hidden' => (int) ($row['hidden'] ?? 0) === 1 || (int) ($row['showorder'] ?? 1) !== 1 ? 1 : 0,
            'is_addon' => 1,
            'setup' => self::setup((string) ($row['autoactivate'] ?? '')),
            'allow_quantity_select' => (int) ($row['allowqty'] ?? 0) > 0 ? 1 : 0,
            'stock_control' => 0,
            'quantity_in_stock' => 0,
            'priority' => (int) ($row['weight'] ?? 0),
            'type' => 'custom',
            'config' => json_encode(new \stdClass()),
        ]);
        $this->currencyPrices('addon', $id, $productId, $title, $cycle);
        $this->map->put('addon', $id, $productId);
        self::translate($this->source, $this->db, $this->now, 'product_addon', $row, 'product', $productId);
        $module = trim((string) ($row['module'] ?? ''));
        if ($module !== '') {
            $this->warn(sprintf('Add-on "%s" uses the WHMCS module "%s": it is a custom add-on; set up its provisioning by hand.', $title, $module));
        }
        foreach (array_filter(array_map('intval', explode(',', (string) ($row['packages'] ?? '')))) as $whmcsProductId) {
            $parent = $this->map->get('product', $whmcsProductId);
            if ($parent !== null) {
                $this->appendList($parent, 'addons', $productId);
            }
        }

        return 1;
    }

    /** WHMCS option types => ClientFence option types. */
    public const OPTION_TYPES = [1 => 'select', 2 => 'radio', 3 => 'yesno', 4 => 'quantity'];

    /**
     * A configurable option group with its products, options and values.
     *
     * @param array<string, mixed> $row
     */
    private function optionGroup(array $row): int
    {
        $id = (int) $row['id'];
        if ($this->map->has('optiongroup', $id)) {
            return 0;
        }
        $title = self::cut($row['name'] ?? '', 255) ?: 'Options ' . $id;
        $this->db->insert('product_option_group', ['title' => $title, 'description' => trim((string) ($row['description'] ?? '')), 'created_at' => $this->now, 'updated_at' => $this->now]);
        $groupId = (int) $this->db->lastInsertId();
        if ($this->source->hasTable('tblproductconfiglinks')) {
            foreach ($this->source->rows('SELECT pid FROM tblproductconfiglinks WHERE gid = ? ORDER BY id', [$id]) as $link) {
                $productId = $this->map->get('product', (int) $link['pid']);
                if ($productId !== null && $this->db->fetchOne('SELECT 1 FROM product_option_group_product WHERE group_id = ? AND product_id = ?', [$groupId, $productId]) === false) {
                    $this->db->insert('product_option_group_product', ['group_id' => $groupId, 'product_id' => $productId]);
                }
            }
        }
        $options = $this->source->hasTable('tblproductconfigoptions')
            ? $this->source->rows('SELECT * FROM tblproductconfigoptions WHERE gid = ? ORDER BY `order`, id', [$id])
            : [];
        foreach ($options as $position => $option) {
            if ($position >= \ClientFence\Mod\Product\Options::MAX_OPTIONS) {
                $this->warn(sprintf('Option group "%s" has more than %d options: the others were not imported.', $title, \ClientFence\Mod\Product\Options::MAX_OPTIONS));

                break;
            }
            $this->option($option, $groupId, $position, $title);
        }
        $this->map->put('optiongroup', $id, $groupId);

        return 1;
    }

    /** @param array<string, mixed> $row */
    private function option(array $row, int $groupId, int $position, string $groupTitle): void
    {
        $id = (int) $row['id'];
        [$code, $title] = self::keyAndName((string) ($row['optionname'] ?? ''));
        $title = $title ?: 'Option ' . $id;
        $type = self::OPTION_TYPES[(int) ($row['optiontype'] ?? 0)] ?? null;
        if ($type === null) {
            $this->warn(sprintf('Option "%s" (%s) was not imported: WHMCS option type %s is not known.', $title, $groupTitle, (string) ($row['optiontype'] ?? '')));

            return;
        }
        $values = $this->source->rows('SELECT * FROM tblproductconfigoptionssub WHERE configid = ? ORDER BY sortorder, id', [$id]);
        if ($values === []) {
            $this->warn(sprintf('Option "%s" (%s) was not imported: it has no values.', $title, $groupTitle));

            return;
        }
        $limit = in_array($type, ['yesno', 'quantity'], true) ? 1 : \ClientFence\Mod\Product\Options::MAX_VALUES;
        if (count($values) > $limit) {
            $this->warn(sprintf('Option "%s" (%s) has %d values; ClientFence keeps the first %d.', $title, $groupTitle, count($values), $limit));
            $values = array_slice($values, 0, $limit);
        }
        $this->db->insert('product_option', [
            'group_id' => $groupId,
            'title' => $title,
            'code' => $code,
            'type' => $type,
            'qty_min' => $type === 'quantity' ? max(0, (int) ($row['qtyminimum'] ?? 0)) : 0,
            'qty_max' => $type === 'quantity' ? max(0, (int) ($row['qtymaximum'] ?? 0)) : 0,
            'unit' => '',
            'hidden' => (int) ($row['hidden'] ?? 0) === 1 ? 1 : 0,
            'position' => $position,
            'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
        $optionId = (int) $this->db->lastInsertId();
        $this->map->put('option', $id, $optionId);
        $otherPrices = false;
        foreach ($values as $valuePosition => $value) {
            [$valueCode, $valueTitle] = self::keyAndName((string) ($value['optionname'] ?? ''));
            $prices = $this->prices('configoptions', (int) $value['id']);
            $default = $prices[$this->defaultCode()] ?? [];
            $columns = self::optionPrices($default);
            $this->db->insert('product_option_value', $columns + [
                'option_id' => $optionId,
                'title' => $valueTitle ?: $title,
                'code' => $valueCode,
                'hidden' => (int) ($value['hidden'] ?? 0) === 1 ? 1 : 0,
                'position' => $valuePosition,
                'created_at' => $this->now, 'updated_at' => $this->now,
            ]);
            $this->map->put('optionvalue', (int) $value['id'], (int) $this->db->lastInsertId());
            foreach ($prices as $otherCode => $other) {
                if ($otherCode === $this->defaultCode()) {
                    continue;
                }
                $rate = (float) ($this->db->fetchOne('SELECT conversion_rate FROM currency WHERE code = ?', [$otherCode]) ?: 0);
                foreach (self::optionPrices($other) as $column => $price) {
                    if ($rate > 0 && abs(round((float) $columns[$column] * $rate, 2) - (float) $price) > 0.009) {
                        $otherPrices = true;
                    }
                }
            }
        }
        if ($otherPrices) {
            $this->warn(sprintf('Option "%s" (%s) had its own prices in other currencies in WHMCS: ClientFence converts option prices at the currency rate.', $title, $groupTitle));
        }
    }

    /**
     * The price columns of an option value from its WHMCS price row: the cycles and their setup
     * fees, and "once" (WHMCS's monthly column, which one-time and free products use).
     *
     * @param array<string, mixed> $row a tblpricing row ([] for none)
     *
     * @return array<string, string>
     */
    public static function optionPrices(array $row): array
    {
        $money = static fn (mixed $value): string => number_format(max(0.0, round((float) $value, 2)), 2, '.', '');
        $out = ['once_price' => $money($row['monthly'] ?? 0), 'once_setup_price' => $money($row['msetupfee'] ?? 0), 'w_price' => '0.00', 'w_setup_price' => '0.00'];
        foreach (self::CYCLES as $column => [$key, , $setup]) {
            $out[$key . '_price'] = $money($row[$column] ?? 0);
            $out[$key . '_setup_price'] = $money($row[$setup] ?? 0);
        }

        return $out;
    }

    /**
     * WHMCS's "key|Display name" (the key is what server modules read) as [code, title]; a name
     * without a key, or a key ClientFence cannot keep, is all title.
     *
     * @return array{0: string, 1: string}
     */
    public static function keyAndName(string $name): array
    {
        $name = trim($name);
        if (str_contains($name, '|')) {
            [$key, $title] = array_map('trim', explode('|', $name, 2));
            if (preg_match('/\A[A-Za-z0-9._-]{1,100}\z/', $key) === 1) {
                return [$key, self::cut($title !== '' ? $title : $key, 255)];
            }
        }

        return ['', self::cut($name, 255)];
    }

    /**
     * Upgrade paths (WHMCS 8: tblproduct_upgrade_products): the products a product may move to.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function upgrades(array $rows): int
    {
        $done = 0;
        foreach ($rows as $row) {
            if ($this->map->has('upgrade', (int) $row['id'])) {
                continue;
            }
            $from = $this->map->get('product', (int) $row['product_id']);
            $to = $this->map->get('product', (int) $row['upgrade_product_id']);
            if ($from === null || $to === null || $from === $to) {
                $this->map->put('upgrade', (int) $row['id'], 0);

                continue;
            }
            $this->appendList($from, 'upgrades', $to);
            $this->map->put('upgrade', (int) $row['id'], $to);
            ++$done;
        }

        return $done;
    }

    /** @param array<string, mixed> $row */
    private function promotion(array $row): int
    {
        $id = (int) $row['id'];
        if ($this->map->has('promo', $id) || $this->map->has('skipped', 'promo:' . $id)) {
            return 0;
        }
        $code = self::cut($row['code'] ?? '', 100);
        $kind = strtolower(trim((string) ($row['type'] ?? '')));
        $skip = function (string $why) use ($id, $code): int {
            $this->map->put('skipped', 'promo:' . $id, 0);
            $this->warn(sprintf('Promotion "%s" was not imported: %s.', $code, $why));

            return 0;
        };
        if ($code === '') {
            return $skip('it has no code');
        }
        if ($this->db->fetchOne('SELECT id FROM promo WHERE code = ?', [$code]) !== false) {
            return $skip('this install already has that code');
        }
        [$type, $value, $freeSetup] = match ($kind) {
            'percentage' => ['percentage', (float) $row['value'], 0],
            'fixed amount' => ['absolute', (float) $row['value'], 0],
            'free setup' => ['percentage', 0.0, 1],
            default => [null, 0.0, 0],
        };
        if ($type === null) {
            return $skip(sprintf('ClientFence has no "%s" promotions', (string) $row['type']));
        }
        $products = [];
        $notes = [];
        $appliesTo = array_filter(array_map('trim', explode(',', (string) ($row['appliesto'] ?? ''))));
        foreach ($appliesTo as $item) {
            if (ctype_digit($item)) {
                $target = $this->map->get('product', (int) $item);
            } elseif (preg_match('/^A(\d+)$/i', $item, $m) === 1) {
                $target = $this->map->get('addon', (int) $m[1]);
            } else {
                $notes[] = sprintf('it also applied to %s', $item);
                $target = null;
            }
            if ($target !== null) {
                $products[] = $target;
            }
        }
        $periods = [];
        foreach (array_filter(array_map('trim', explode(',', strtolower((string) ($row['cycles'] ?? ''))))) as $cycle) {
            if (isset(self::CYCLE_NAMES[$cycle])) {
                $periods[] = self::CYCLE_NAMES[$cycle];
            } elseif (preg_match('/^\d+years$/', $cycle) === 1) {
                $notes[] = 'its domain years';
            }
        }
        // Targets and domain years it loses only narrow the code; what follows widens it.
        $narrower = $notes;
        foreach (['requires' => 'a required product', 'requiresexisting' => 'existing services as the requirement', 'applyonce' => 'once per order', 'newsignups' => 'new clients only', 'existingclient' => 'existing clients only', 'upgrades' => 'upgrades'] as $column => $label) {
            $set = trim((string) ($row[$column] ?? ''));
            if ($set !== '' && $set !== '0') {
                $notes[] = $label;
            }
        }
        if ((int) ($row['recurring'] ?? 0) === 1 && (int) ($row['recurfor'] ?? 0) > 0) {
            $notes[] = sprintf('the limit of %d recurring cycles (it recurs without limit)', (int) $row['recurfor']);
        }
        if ($appliesTo !== [] && $products === []) {
            $notes[] = 'the products it applied to (none of them was imported)';
        }
        $wider = array_diff($notes, $narrower);
        $end = Accounts::date($row['expirationdate'] ?? null);
        $this->db->insert('promo', [
            'code' => $code,
            'description' => trim((string) ($row['notes'] ?? '')) ?: null,
            'type' => $type,
            'value' => $value,
            'maxuses' => max(0, (int) ($row['maxuses'] ?? 0)),
            'used' => max(0, (int) ($row['uses'] ?? 0)),
            'freesetup' => $freeSetup,
            'once_per_client' => (int) ($row['onceperclient'] ?? 0) === 1 ? 1 : 0,
            'recurring' => (int) ($row['recurring'] ?? 0) === 1 ? 1 : 0,
            // A code ClientFence cannot keep as narrow as WHMCS had it would discount more: it waits, switched off.
            'active' => $wider === [] ? 1 : 0,
            'products' => $products === [] ? null : json_encode(array_values(array_unique($products))),
            'periods' => $periods === [] ? null : json_encode(array_values(array_unique($periods))),
            'client_groups' => null,
            'start_at' => Accounts::date($row['startdate'] ?? null),
            'end_at' => $end === null ? null : substr($end, 0, 10) . ' 23:59:59',
            'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
        $this->map->put('promo', $id, (int) $this->db->lastInsertId());
        if ($wider !== []) {
            $this->warn(sprintf('Promotion "%s" was imported switched off: ClientFence does not keep %s, so it would apply more widely than in WHMCS. Check it before switching it on.', $code, implode(', ', array_values(array_unique($notes)))));
        } elseif ($notes !== []) {
            $this->warn(sprintf('Promotion "%s": ClientFence does not keep %s.', $code, implode(', ', array_values(array_unique($notes)))));
        }

        return 1;
    }

    /** @param array<string, mixed> $row */
    private function tld(array $row): int
    {
        $id = (int) $row['id'];
        if ($this->map->has('tld', $id) || $this->map->has('skipped', 'tld:' . $id)) {
            return 0;
        }
        $tld = '.' . ltrim(strtolower(trim((string) ($row['extension'] ?? ''))), '.');
        $byCurrency = [];
        foreach (['register' => 'domainregister', 'renew' => 'domainrenew', 'transfer' => 'domaintransfer'] as $action => $type) {
            foreach ($this->prices($type, $id) as $code => $prices) {
                $byCurrency[$code][$action] = $prices;
            }
        }
        $default = $byCurrency[$this->defaultCode()] ?? null;
        $terms = $default === null ? null : self::tldTerms($default);
        if ($tld === '.' || $terms === null || $terms['years'] === []) {
            $this->map->put('skipped', 'tld:' . $id, 0);
            $this->warn(sprintf('Domain %s was not imported: it has no registration price in the default currency %s.', $tld, $this->defaultCode()));

            return 0;
        }
        $values = [
            'tld_registrar_id' => $this->registrar(strtolower(trim((string) ($row['autoreg'] ?? '')))),
            'price_registration' => $terms['register'],
            'price_renew' => $terms['renew'],
            'price_transfer' => $terms['transfer'] ?? 0.0,
            'allow_register' => 1,
            'allow_transfer' => $terms['transfer'] !== null ? 1 : 0,
            'require_transfer_code' => in_array(strtolower(trim((string) ($row['eppcode'] ?? ''))), ['on', '1'], true) ? 1 : 0,
            'active' => 1,
            'min_years' => $terms['years'][0],
            'periods' => implode(',', $terms['years']),
            'updated_at' => $this->now,
        ];
        $existing = $this->db->fetchOne('SELECT id FROM tld WHERE tld = ?', [$tld]);
        if ($existing === false) {
            $this->db->insert('tld', $values + ['tld' => $tld, 'created_at' => $this->now]);
            $tldId = (int) $this->db->lastInsertId();
        } else {
            $tldId = (int) $existing;
            $this->db->update('tld', $values, ['id' => $tldId]);
        }
        if ($terms['mismatch'] !== []) {
            $this->warn(sprintf('Domain %s: WHMCS priced %s years differently; ClientFence charges the registration price plus the renewal price for each further year.', $tld, implode(', ', $terms['mismatch'])));
        }
        $fixed = [];
        foreach ($byCurrency as $code => $prices) {
            if ($code === $this->defaultCode() || !$this->targetCurrency($code)) {
                continue;
            }
            $other = self::tldTerms($prices);
            if ($other !== null && $other['years'] !== []) {
                $fixed[$code] = ['register' => ['price' => $other['register']], 'renew' => ['price' => $other['renew']]];
                if ($other['transfer'] !== null) {
                    $fixed[$code]['transfer'] = ['price' => $other['transfer']];
                }
            }
        }
        if ($fixed !== []) {
            CurrencyPrices::save($this->db, 'tld', $tldId, $fixed);
        }
        $this->map->put('tld', $id, $tldId);

        return 1;
    }

    /**
     * A TLD's terms from its WHMCS price rows in one currency: the years offered, the
     * registration price for the first of them (so that registration + renewals per further year
     * gives WHMCS's price for that term), the one-year renewal and transfer prices, and the terms
     * WHMCS priced differently.
     *
     * @param array<string, array<string, mixed>> $rows register/renew/transfer => tblpricing row
     *
     * @return array{years: list<int>, register: float, renew: float, transfer: ?float, mismatch: list<int>}|null
     */
    public static function tldTerms(array $rows): ?array
    {
        $register = $rows['register'] ?? null;
        if ($register === null) {
            return null;
        }
        $years = [];
        foreach (self::DOMAIN_YEARS as $column => $n) {
            if (isset($register[$column]) && (float) $register[$column] >= 0) {
                $years[$n] = (float) $register[$column];
            }
        }
        if ($years === []) {
            return ['years' => [], 'register' => 0.0, 'renew' => 0.0, 'transfer' => null, 'mismatch' => []];
        }
        $renewRow = $rows['renew'] ?? [];
        $renew = isset($renewRow['msetupfee']) && (float) $renewRow['msetupfee'] >= 0 ? (float) $renewRow['msetupfee'] : null;
        $first = (int) array_key_first($years);
        $renew ??= round($years[$first] / $first, 2);
        $registerPrice = round($years[$first] - ($first - 1) * $renew, 2);
        if ($registerPrice < 0) {
            $registerPrice = round($years[$first] / $first, 2);
        }
        $mismatch = [];
        foreach ($years as $n => $price) {
            if (abs($registerPrice + ($n - 1) * $renew - $price) > 0.009) {
                $mismatch[] = $n;
            }
        }
        $transferRow = $rows['transfer'] ?? [];
        $transfer = isset($transferRow['msetupfee']) && (float) $transferRow['msetupfee'] >= 0 ? (float) $transferRow['msetupfee'] : null;

        return ['years' => array_keys($years), 'register' => $registerPrice, 'renew' => $renew, 'transfer' => $transfer, 'mismatch' => $mismatch];
    }

    /**
     * The registrar of a WHMCS registrar module: this install's, or Custom (with a warning). Also
     * used by the domains step (Services.php).
     */
    public function registrar(string $module): int
    {
        $custom = (int) ($this->db->fetchOne("SELECT id FROM tld_registrar WHERE registrar = 'Custom' ORDER BY id") ?: 0);
        if ($module === '') {
            return $custom;
        }
        $known = $this->map->get('registrar', $module);
        if ($known !== null) {
            return $known;
        }
        $id = $this->db->fetchOne('SELECT id FROM tld_registrar WHERE LOWER(registrar) = ? ORDER BY id', [$module]);
        $adapter = null;
        foreach ($this->registrars as $name) {
            if (strtolower($name) === $module) {
                $adapter = $name;
            }
        }
        if ($id === false && $adapter !== null) {
            $this->db->insert('tld_registrar', ['name' => $adapter, 'registrar' => $adapter, 'test_mode' => 0, 'config' => null]);
            $id = (int) $this->db->lastInsertId();
        }
        if ($id === false) {
            $this->warn(sprintf('Domains registered with the WHMCS registrar "%s" use the Custom registrar: ClientFence has no "%s" registrar.', $module, $module));
            $id = $custom;
        } else {
            $this->warn(sprintf('Registrar "%s": enter its API settings (Domains > Registrars); they are not imported.', $module));
        }
        $this->map->put('registrar', $module, (int) $id);

        return (int) $id;
    }

    /**
     * A WHMCS tax rule (QA r4 W-08: without them renewals came out untaxed). ClientFence charges one rate, the first
     * level's; WHMCS's "Tax" switch turns ClientFence's on, except when WHMCS's prices included the tax: ClientFence adds
     * tax on top of prices, so it stays off until the prices are net.
     *
     * @param array<string, mixed> $row
     */
    private function taxRule(array $row): int
    {
        $id = (int) $row['id'];
        if ($this->map->has('tax', $id) || $this->map->has('skipped', 'tax:' . $id)) {
            return 0;
        }
        $name = self::cut($row['name'] ?? '', 255) ?: 'Tax';
        if ((int) ($row['level'] ?? 1) !== 1) {
            $this->map->put('skipped', 'tax:' . $id, 0);
            $this->warn(sprintf('Tax rule "%s" is a second-level tax: ClientFence charges one tax rate, so new invoices leave it out.', $name));

            return 0;
        }
        $this->db->insert('tax', [
            'level' => 1,
            'name' => $name,
            'country' => strtoupper(self::cut($row['country'] ?? '', 255)) ?: null,
            'state' => self::cut($row['state'] ?? '', 255) ?: null,
            'taxrate' => (string) (float) ($row['taxrate'] ?? 0),
            'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
        $this->map->put('tax', $id, (int) $this->db->lastInsertId());
        if (!$this->map->has('skipped', 'notice:tax')) {
            $this->map->put('skipped', 'notice:tax', 0);
            $setting = fn (string $name): string => strtolower(trim((string) $this->source->value('SELECT value FROM tblconfiguration WHERE setting = ?', [$name])));
            if (in_array($setting('TaxEnabled'), ['on', '1', 'yes'], true)) {
                // ClientFence taxes every item of a taxable client: on only if WHMCS taxed every product, add-on and domain.
                $untaxed = [];
                foreach (['tblproducts' => 'products', 'tbladdons' => 'add-ons'] as $table => $label) {
                    if ($this->source->hasTable($table) && in_array('tax', $this->source->columns($table), true) && $this->source->count($table, 'tax = 0') > 0) {
                        $untaxed[] = sprintf('%d %s', $this->source->count($table, 'tax = 0'), $label);
                    }
                }
                if ($this->source->hasTable('tbldomainpricing') && $this->source->count('tbldomainpricing') > 0 && !in_array($setting('TaxDomains'), ['on', '1', 'yes'], true)) {
                    $untaxed[] = 'domains';
                }
                if ($untaxed !== []) {
                    $this->warn(sprintf('WHMCS did not tax some items (%s), and ClientFence taxes every item of a taxable client: tax was left switched off, so renewals charge no tax. Decide before switching it on under Settings > Invoices.', implode(', ', $untaxed)));
                } else {
                    // WHMCS Inclusive Tax maps to "Prices include tax".
                    foreach (['tax_enabled' => '1', 'tax_inclusive' => $setting('TaxType') === 'inclusive' ? '1' : '0'] as $param => $value) {
                        if ($this->db->update('setting', ['value' => $value, 'updated_at' => $this->now], ['param' => $param]) === 0
                            && $this->db->fetchOne('SELECT 1 FROM setting WHERE param = ?', [$param]) === false) {
                            $this->db->insert('setting', ['param' => $param, 'value' => $value, 'public' => 0, 'created_at' => $this->now, 'updated_at' => $this->now]);
                        }
                    }
                }
            }
        }

        return 1;
    }

    /** @param array<string, mixed> $row */
    private function bundle(array $row): int
    {
        $id = (int) $row['id'];
        if (!$this->map->has('skipped', 'bundle:' . $id)) {
            $this->map->put('skipped', 'bundle:' . $id, 0);
            $this->warn(sprintf('Bundle "%s" was not imported: ClientFence has no bundles; make it a promotion if you still offer it.', trim((string) ($row['name'] ?? '')) ?: '#' . $id));
        }

        return 0;
    }

    /** @param array<string, mixed> $values */
    private function insertProduct(array $values): int
    {
        $this->db->insert('product', $values + [
            'form_id' => null, 'unit' => 'product', 'active' => 1, 'addons' => null, 'icon_url' => null, 'plugin' => null,
            'plugin_config' => null, 'upgrades' => null, 'created_at' => $this->now, 'updated_at' => $this->now,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** Adds an id to a product's JSON list column (addons, upgrades). */
    private function appendList(int $productId, string $column, int $id): void
    {
        $list = json_decode((string) $this->db->fetchOne('SELECT ' . $column . ' FROM product WHERE id = ?', [$productId]), true);
        // Entries are kept as saved: upgrades may hold Order\Upgrades::CATEGORY.
        $list = is_array($list) ? $list : [];
        if (!in_array($id, array_map('intval', $list), true)) {
            $list[] = $id;
            $this->db->update('product', [$column => json_encode($list)], ['id' => $productId]);
        }
    }

    private function slug(string $text): string
    {
        $base = trim((string) preg_replace('/-+/', '-', (string) preg_replace('/[^a-z0-9-]/', '-', strtolower(trim($text)))), '-');
        $base = substr($base === '' ? 'product' : $base, 0, 240);
        $slug = $base;
        for ($n = 2; $this->db->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]) !== false; ++$n) {
            $slug = $base . '-' . $n;
        }

        return $slug;
    }

    /**
     * A WHMCS item's price rows by currency code.
     *
     * @return array<string, array<string, mixed>>
     */
    private function prices(string $type, int $relid): array
    {
        $out = [];
        foreach ($this->source->rows('SELECT * FROM tblpricing WHERE type = ? AND relid = ? ORDER BY id', [$type, $relid]) as $row) {
            $code = $this->currencyCodes()[(int) $row['currency']] ?? null;
            if ($code !== null) {
                $out[$code] ??= $row;
            }
        }

        return $out;
    }

    /** Whether this install has the currency (the currencies step imported it). */
    private function targetCurrency(string $code): bool
    {
        return $this->db->fetchOne('SELECT id FROM currency WHERE code = ?', [$code]) !== false;
    }

    private function defaultCode(): string
    {
        if ($this->defaultCode === null) {
            $code = $this->source->value('SELECT code FROM tblcurrencies WHERE `default` = 1 ORDER BY id') ?: $this->source->value('SELECT code FROM tblcurrencies ORDER BY id');
            $this->defaultCode = strtoupper(trim((string) $code));
        }

        return $this->defaultCode;
    }

    /** @return array<int, string> */
    private function currencyCodes(): array
    {
        if ($this->codes === null) {
            $this->codes = [];
            foreach ($this->source->rows('SELECT id, code FROM tblcurrencies') as $row) {
                $this->codes[(int) $row['id']] = strtoupper(trim((string) $row['code']));
            }
        }

        return $this->codes;
    }

    /**
     * An item's texts in other languages (tbldynamic_translations rows "<type>.{id}.<field>" with
     * related_id), as product_translation rows: products, add-ons, groups, product custom fields
     * (name: the label) and departments (Support.php). A group's headline and tagline make its
     * description, as in category(); the default language keeps the item's own texts.
     *
     * @param array<string, mixed> $row the WHMCS item
     */
    public static function translate(Source $source, Connection $db, string $now, string $type, array $row, string $kind, int $refId): void
    {
        if (!$source->hasTable('tbldynamic_translations')) {
            return;
        }
        $texts = [];
        foreach ($source->rows('SELECT related_type, language, translation FROM tbldynamic_translations WHERE related_id = ? AND related_type LIKE ?', [(int) $row['id'], $type . '.{id}.%']) as $t) {
            $locale = Accounts::locale((string) $t['language']);
            $value = trim((string) $t['translation']);
            if ($locale !== null && $value !== '') {
                $texts[$locale][substr((string) $t['related_type'], strlen($type) + 6)] = $value;
            }
        }
        unset($texts[(string) Config::getProperty('i18n.locale', 'en_US')]);
        if ($texts !== []) {
            // A help desk WHMCS's department takes over (Support.php) may have texts of its own.
            $db->delete(Translations::TABLE, ['kind' => $kind, 'ref_id' => $refId]);
        }
        foreach ($texts as $locale => $t) {
            $description = $type === 'product_group'
                ? trim(implode("\n\n", array_filter([$t['headline'] ?? trim((string) ($row['headline'] ?? '')), $t['tagline'] ?? trim((string) ($row['tagline'] ?? ''))])))
                // A help desk has no description in ClientFence.
                : ($kind === 'helpdesk' ? '' : ($t['description'] ?? ''));
            $title = self::cut($t['name'] ?? '', $kind === 'category' ? 100 : 255);
            if (($title === '' && $description === '') || array_intersect_key($t, ['name' => 1, 'description' => 1, 'headline' => 1, 'tagline' => 1]) === []) {
                continue;
            }
            $db->insert(Translations::TABLE, [
                'kind' => $kind, 'ref_id' => $refId, 'lang' => $locale,
                'title' => $title === '' ? null : $title, 'description' => $description === '' ? null : $description,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /** Once per import: texts in languages this install does not ship, and translated email templates. */
    private function languageWarnings(): void
    {
        if ($this->source->hasTable('tbldynamic_translations')) {
            $languages = $this->source->rows("SELECT DISTINCT language FROM tbldynamic_translations WHERE related_type LIKE 'product%' OR related_type LIKE 'custom_field.%' OR related_type LIKE 'ticket_department.%'");
            $missing = array_values(array_filter(array_map(static fn (array $r): string => trim((string) $r['language']), $languages), static fn (string $l): bool => $l !== '' && Accounts::locale($l) === null));
            if ($missing !== []) {
                $this->warn(sprintf('Product texts in %d %s were not imported: %s not installed (%s).', count($missing), count($missing) === 1 ? 'language' : 'languages', count($missing) === 1 ? 'it is' : 'those languages are', implode(', ', $missing)));
            }
        }
        $templates = $this->source->count('tblemailtemplates', "language <> ''");
        if ($templates > 0) {
            $this->warn(sprintf('%d translated email %s in WHMCS %s not imported; ClientFence sends its own translated templates.', $templates, $templates === 1 ? 'template' : 'templates', $templates === 1 ? 'was' : 'were'));
        }
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
