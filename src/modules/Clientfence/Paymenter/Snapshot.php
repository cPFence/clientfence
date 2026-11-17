<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Paymenter;

use ClientFence\Mod\Clientfence\Whmcs\Snapshot as Base;
use ClientFence\Mod\Clientfence\Whmcs\Source;
use Doctrine\DBAL\Connection;
use Symfony\Component\Intl\Countries;

/**
 * ClientFence: the Paymenter import (Paymenter 1.4 or newer). fetch() reads Paymenter's database
 * (read only, as the WHMCS import reads WHMCS's: Whmcs\Source) into the import's snapshot
 * (Whmcs/Snapshot.php), a page of rows at a time, keeping Paymenter's ids.
 *
 * Paymenter keeps a client's details as properties, a product's prices in plans (one per billing
 * period) and a configurable option's values as its children. Service prices and invoice lines
 * include the tax (with exclusive tax Paymenter adds it when the order is placed), and an invoice's
 * total is the sum of its lines: its tax is the part of that total its rate gives. A payment from
 * the client's credit is a transaction marked as credit: the invoice's credit applied, not money
 * received, as the credit balance has already spent it. Passwords keep their hashes. Left out with a
 * warning: two-factor secrets, coupons, servers, saved payment methods, gateway subscriptions, ticket
 * attachments, payments still processing and billing periods WHMCS has no name for (hours, days,
 * weeks, 4 or more years).
 */
final class Snapshot extends Base
{
    public const NAME = 'Paymenter';
    public const STATE = 'paymenter_fetch';
    public const STEP = 'paymenter';
    public const FILE = 'paymenter-import.sqlite';
    /** Rows read per page. */
    public const PAGE = 200;

    public const STEPS = [
        'setup' => 'Currencies, taxes, options and departments',
        'products' => 'Products',
        'clients' => 'Clients',
        'services' => 'Services',
        'invoices' => 'Invoices',
        'payments' => 'Payments',
        'tickets' => 'Tickets',
    ];

    private const SERVICE_STATUSES = ['pending' => 'Pending', 'active' => 'Active', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled'];

    /** Paymenter's own WHMCS import writes "unpaid". */
    private const INVOICE_STATUSES = ['pending' => 'Unpaid', 'unpaid' => 'Unpaid', 'paid' => 'Paid', 'cancelled' => 'Cancelled'];

    private const TICKET_STATUSES = ['open' => 'Open', 'replied' => 'Answered', 'closed' => 'Closed'];

    private const URGENCY = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'];

    /** Option types => WHMCS's (1 dropdown, 2 radio, 3 yes/no); text and number options are answers kept on the service. */
    private const OPTION_TYPES = ['select' => 1, 'slider' => 1, 'radio' => 2, 'checkbox' => 3];

    /** Paymenter's country names (its config app.countries) that are not Symfony's English names, lower case => code. */
    private const COUNTRY_NAMES = [
        'aland islands' => 'AX', 'antigua and barbuda' => 'AG', 'bosnia and herzegovina' => 'BA',
        'brunei darussalam' => 'BN', 'congo' => 'CG', 'congo, democratic republic' => 'CD', 'cote d\'ivoire' => 'CI',
        'curacao' => 'CW', 'czech republic' => 'CZ', 'falkland islands (malvinas)' => 'FK',
        'heard island & mcdonald islands' => 'HM', 'holy see (vatican city state)' => 'VA', 'hong kong' => 'HK',
        'iran, islamic republic of' => 'IR', 'korea' => 'KR', 'lao people\'s democratic republic' => 'LA',
        'libyan arab jamahiriya' => 'LY', 'macao' => 'MO', 'macedonia' => 'MK',
        'micronesia, federated states of' => 'FM', 'myanmar' => 'MM', 'palestine, state of' => 'PS',
        'pitcairn' => 'PN', 'reunion' => 'RE', 'russian federation' => 'RU', 'saint barthelemy' => 'BL',
        'saint helena' => 'SH', 'saint kitts and nevis' => 'KN', 'saint lucia' => 'LC', 'saint martin' => 'MF',
        'saint pierre and miquelon' => 'PM', 'saint vincent and grenadines' => 'VC', 'sao tome and principe' => 'ST',
        'south georgia and sandwich isl.' => 'GS', 'svalbard and jan mayen' => 'SJ', 'swaziland' => 'SZ',
        'syrian arab republic' => 'SY', 'trinidad and tobago' => 'TT', 'turkey' => 'TR',
        'turks and caicos islands' => 'TC', 'united states outlying islands' => 'UM', 'viet nam' => 'VN',
        'virgin islands, british' => 'VG', 'virgin islands, u.s.' => 'VI', 'wallis and futuna' => 'WF',
    ];

    /** The client details kept as user properties: property key => tblclients column. */
    private const FIELDS = ['company_name' => 'companyname', 'address' => 'address1', 'address2' => 'address2', 'city' => 'city', 'state' => 'state', 'zip' => 'postcode', 'phone' => 'phonenumber'];

    /** '' with tax off, else Paymenter's tax type (inclusive, exclusive). */
    private ?string $taxType = null;

    /**
     * Reads Paymenter for up to $seconds (0: until done); $restart starts again from nothing.
     * $settings are the import settings (Paymenter's database); tests hand in their own $source.
     *
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    public static function fetch(\Pimple\Container $di, array $settings, float $seconds = 0.0, bool $restart = false, ?Source $source = null): array
    {
        return self::read($di, static fn (Connection $db): self => new self($source ?? Source::mysql($di, $settings, 'Paymenter'), $db), $seconds, $restart);
    }

    /** Whether a database is Paymenter 1.x's: its service, plan, option and transaction tables. */
    public static function isPaymenter(Source $source): bool
    {
        return $source->hasTable('services') && $source->hasTable('plans') && $source->hasTable('config_option_products') && $source->hasTable('invoice_transactions');
    }

    private function __construct(private readonly Source $pay, Connection $db)
    {
        parent::__construct($db);
    }

    protected function close(): void
    {
        $this->pay->close();
    }

    protected function page(string $step, int $offset): array
    {
        if ($step === 'setup') {
            return [null, $this->setup()];
        }
        // Clients are the users without a staff role.
        $sql = [
            'products' => 'SELECT * FROM products WHERE id > ? ORDER BY id',
            'clients' => 'SELECT * FROM users WHERE role_id IS NULL AND id > ? ORDER BY id',
            'services' => 'SELECT * FROM services WHERE id > ? ORDER BY id',
            'invoices' => 'SELECT * FROM invoices WHERE id > ? ORDER BY id',
            'payments' => 'SELECT t.*, i.user_id, e.extension FROM invoice_transactions t JOIN invoices i ON i.id = t.invoice_id LEFT JOIN extensions e ON e.id = t.gateway_id WHERE t.id > ? ORDER BY t.id',
            'tickets' => 'SELECT * FROM tickets WHERE id > ? ORDER BY id',
        ][$step];
        $rows = $this->pay->rows($sql . ' LIMIT ' . self::PAGE, [$offset]);
        foreach ($rows as $row) {
            match ($step) {
                'products' => $this->product($row),
                'clients' => $this->client($row),
                'services' => $this->service($row),
                'invoices' => $this->invoice($row),
                'payments' => $this->payment($row),
                default => $this->ticket($row),
            };
        }

        return [count($rows) < self::PAGE ? null : (int) end($rows)['id'], count($rows)];
    }

    private function setting(string $key): string
    {
        $value = $this->pay->value('SELECT value FROM settings WHERE `key` = ? AND settingable_type IS NULL', [$key]);

        return $value === false ? '' : (string) $value;
    }

    private function taxType(): string
    {
        return $this->taxType ??= in_array($this->setting('tax_enabled'), ['1', 'true'], true) ? ($this->setting('tax_type') ?: 'inclusive') : '';
    }

    private function setup(): int
    {
        $this->db->insert('tblconfiguration', ['setting' => 'Version', 'value' => '8.0.0 (Paymenter)']);
        $this->db->insert('tblconfiguration', ['setting' => 'SequentialInvoiceNumbering', 'value' => 'on']);
        $default = $this->setting('default_currency') ?: 'USD';
        foreach ($this->pay->rows('SELECT * FROM currencies ORDER BY code') as $c) {
            $this->db->insert('tblcurrencies', ['code' => (string) $c['code'], 'prefix' => (string) $c['prefix'], 'suffix' => (string) $c['suffix'], 'format' => 2, 'rate' => 1, '"default"' => $c['code'] === $default ? 1 : 0]);
        }
        foreach ($this->pay->rows('SELECT * FROM tax_rates ORDER BY id') as $t) {
            $this->db->insert('tbltax', ['id' => (int) $t['id'], 'level' => '1', 'name' => (string) $t['name'], 'state' => '', 'country' => $t['country'] === 'all' ? '' : (string) $t['country'], 'taxrate' => (float) $t['rate']]);
        }
        // Paymenter taxes every line; "inclusive" prices hold the tax, "exclusive" ones get it added.
        if ($this->taxType() !== '' && (int) $this->db->fetchOne('SELECT COUNT(*) FROM tbltax') > 0) {
            $this->db->insert('tblconfiguration', ['setting' => 'TaxEnabled', 'value' => 'on']);
            $this->db->insert('tblconfiguration', ['setting' => 'TaxType', 'value' => $this->taxType() === 'exclusive' ? 'Exclusive' : 'Inclusive']);
            $this->db->insert('tblconfiguration', ['setting' => 'TaxDomains', 'value' => 'on']);
        }
        foreach ($this->pay->rows('SELECT * FROM categories ORDER BY sort, id') as $i => $c) {
            $this->db->insert('tblproductgroups', ['id' => (int) $c['id'], 'name' => (string) $c['name'], 'slug' => (string) $c['slug'], 'hidden' => 0, '"order"' => $i]);
        }
        $this->options();
        // Departments are names: those of the setting, and any a ticket still has.
        $names = json_decode($this->setting('ticket_departments'), true);
        $names = array_merge(is_array($names) ? $names : [], array_column($this->pay->rows('SELECT DISTINCT department FROM tickets WHERE department IS NOT NULL'), 'department'));
        foreach (array_values(array_unique(array_filter(array_map(static fn (mixed $n): string => trim((string) $n), $names)))) as $i => $name) {
            $this->db->insert('tblticketdepartments', ['id' => $i + 1, 'name' => $name, '"order"' => $i]);
            $this->lookup('department', $name, (string) ($i + 1));
        }
        $counts = [
            'coupons' => $this->pay->count('coupons'),
            'servers' => $this->pay->count('extensions', "type = 'server' AND deleted_at IS NULL"),
            'saved payment methods' => $this->pay->count('billing_agreements', 'deleted_at IS NULL'),
            'ticket attachments' => $this->pay->count('ticket_message_attachments'),
        ];
        foreach (array_filter($counts) as $what => $count) {
            $this->warn(sprintf('Paymenter has %d %s: they were not imported.', $count, $what));
        }
        $subscribed = $this->pay->count('services', "subscription_id IS NOT NULL AND subscription_id <> ''");
        if ($subscribed > 0) {
            $this->warn(sprintf('Paymenter has %d services renewed by a gateway subscription: the subscriptions were not imported, so these services get renewal invoices instead.', $subscribed));
        }
        $answers = $this->pay->count('config_options', "parent_id IS NULL AND type IN ('text', 'number')");
        if ($answers > 0) {
            $this->warn(sprintf('Paymenter has %d text and number options: they were not imported as options; the answers your clients gave are in their services\' notes.', $answers));
        }

        return 1;
    }

    /** Each priced configurable option as a WHMCS option group of its own, linked to its products, with its values and their prices. */
    private function options(): void
    {
        foreach ($this->pay->rows('SELECT * FROM config_options WHERE parent_id IS NULL ORDER BY sort, id') as $o) {
            $type = self::OPTION_TYPES[(string) $o['type']] ?? null;
            if ($type === null) {
                continue;
            }
            $id = (int) $o['id'];
            $this->db->insert('tblproductconfiggroups', ['id' => $id, 'name' => (string) $o['name'], 'description' => (string) ($o['description'] ?? '')]);
            foreach ($this->pay->rows('SELECT product_id FROM config_option_products WHERE config_option_id = ? ORDER BY id', [$id]) as $link) {
                $this->db->insert('tblproductconfiglinks', ['gid' => $id, 'pid' => (int) $link['product_id']]);
            }
            $this->db->insert('tblproductconfigoptions', ['id' => $id, 'gid' => $id, 'optionname' => self::keyed($o), 'optiontype' => $type, 'qtyminimum' => 0, 'qtymaximum' => 0, '"order"' => 0, 'hidden' => (int) $o['hidden']]);
            foreach ($this->pay->rows('SELECT * FROM config_options WHERE parent_id = ? ORDER BY sort, id', [$id]) as $i => $v) {
                $this->db->insert('tblproductconfigoptionssub', ['id' => (int) $v['id'], 'configid' => $id, 'optionname' => self::keyed($v), 'sortorder' => $i, 'hidden' => (int) $v['hidden']]);
                $this->prices('configoptions', (int) $v['id'], 'App\Models\ConfigOption', sprintf('Option "%s: %s"', $o['name'], $v['name']));
            }
        }
    }

    /**
     * WHMCS's "key|Display name" of an option or value: the key is what server modules read.
     *
     * @param array<string, mixed> $option
     */
    private static function keyed(array $option): string
    {
        $key = trim((string) ($option['env_variable'] ?? ''));

        return ($key !== '' ? $key . '|' : '') . (string) $option['name'];
    }

    /** @param array<string, mixed> $p */
    private function product(array $p): void
    {
        $id = (int) $p['id'];
        $this->db->insert('tblproducts', [
            'id' => $id, 'type' => 'other', 'gid' => (int) $p['category_id'], 'name' => (string) $p['name'], 'slug' => (string) ($p['slug'] ?? ''),
            'description' => (string) ($p['description'] ?? ''), 'hidden' => (int) ($p['hidden'] ?? 0), 'stockcontrol' => $p['stock'] === null ? 0 : 1, 'qty' => (int) ($p['stock'] ?? 0),
            'paytype' => $this->prices('product', $id, 'App\Models\Product', sprintf('Product "%s"', $p['name'])),
            'allowqty' => $p['allow_quantity'] === 'disabled' ? 0 : 1, '"order"' => (int) ($p['sort'] ?? 0),
        ]);
        foreach ($this->pay->rows('SELECT upgrade_id FROM product_upgrades WHERE product_id = ? ORDER BY id', [$id]) as $u) {
            $this->db->insert('tblproduct_upgrade_products', ['product_id' => $id, 'upgrade_product_id' => (int) $u['upgrade_id']]);
        }
    }

    /**
     * The prices of a product or option value (its plans, one per billing period, priced per currency) as tblpricing
     * rows; answers WHMCS's pay type. WHMCS bills free, once or on renewal at one price per cycle: other plans are named.
     */
    private function prices(string $type, int $relid, string $model, string $name): string
    {
        $plans = $this->pay->rows('SELECT * FROM plans WHERE priceable_type = ? AND priceable_id = ? ORDER BY sort, id', [$model, $relid]);
        $kinds = array_column($plans, 'type');
        $paytype = in_array('recurring', $kinds, true) ? 'recurring' : (in_array('one-time', $kinds, true) ? 'onetime' : 'free');
        $rows = $taken = [];
        foreach ($plans as $plan) {
            if ($paytype === 'free') {
                break;
            }
            $months = self::months($plan);
            $column = match (true) {
                $paytype === 'onetime' => $plan['type'] === 'one-time' ? 'monthly' : null,
                default => $plan['type'] === 'recurring' && $months !== null ? self::PRICE_COLUMNS[$months] : null,
            };
            if ($column === null || isset($taken[$column])) {
                $this->warn(sprintf('%s: its %s price was not imported (ClientFence bills free, once, or every 1, 3, 6, 12, 24 or 36 months at one price each).', $name, self::period($plan)));

                continue;
            }
            $taken[$column] = true;
            foreach ($this->pay->rows('SELECT * FROM prices WHERE plan_id = ? ORDER BY id', [(int) $plan['id']]) as $price) {
                $code = (string) $price['currency_code'];
                $rows[$code] ??= ['type' => $type, 'currency' => $this->currency($code), 'relid' => $relid, 'msetupfee' => 0, 'qsetupfee' => 0, 'ssetupfee' => 0, 'asetupfee' => 0, 'bsetupfee' => 0, 'tsetupfee' => 0];
                $rows[$code][$column] = round((float) $price['price'], 2);
                $rows[$code][['monthly' => 'msetupfee', 'quarterly' => 'qsetupfee', 'semiannually' => 'ssetupfee', 'annually' => 'asetupfee', 'biennially' => 'bsetupfee', 'triennially' => 'tsetupfee'][$column]] = round((float) ($price['setup_fee'] ?? 0), 2);
            }
        }
        foreach ($rows as $row) {
            $this->db->insert('tblpricing', $row);
        }

        return $paytype;
    }

    /**
     * A plan's billing period in months as WHMCS bills it: 0 once (one-time or free), null when WHMCS has no name for it.
     *
     * @param array<string, mixed> $plan
     */
    private static function months(array $plan): ?int
    {
        if ($plan['type'] !== 'recurring') {
            return 0;
        }
        $months = match ((string) $plan['billing_unit']) {
            'month' => (int) $plan['billing_period'],
            'year' => (int) $plan['billing_period'] * 12,
            default => -1,
        };

        return isset(self::PRICE_COLUMNS[$months]) ? $months : null;
    }

    /** @param array<string, mixed> $plan */
    private static function period(array $plan): string
    {
        return $plan['type'] === 'recurring' ? sprintf('%d %s%s', (int) $plan['billing_period'], $plan['billing_unit'], (int) $plan['billing_period'] === 1 ? '' : 's') : (string) $plan['type'];
    }

    /**
     * A user's or service's properties (the answers of its fields): key => [name, value].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function properties(string $model, int $id): array
    {
        $out = [];
        foreach ($this->pay->rows('SELECT p.`key`, COALESCE(p.name, c.name, p.`key`) AS name, p.value FROM properties p LEFT JOIN custom_properties c ON c.id = p.custom_property_id WHERE p.model_type = ? AND p.model_id = ? ORDER BY p.id', [$model, $id]) as $p) {
            $out[(string) $p['key']] = [(string) $p['name'], trim((string) $p['value'])];
        }

        return $out;
    }

    /** @param array<string, mixed> $u */
    private function client(array $u): void
    {
        $id = (int) $u['id'];
        $fields = $this->properties('App\Models\User', $id);
        $email = trim((string) $u['email']);
        $created = self::day($u['created_at'] ?? null) ?: date('Y-m-d');
        // A Paymenter client has no currency of its own: the one it is billed in most.
        $code = (string) ($this->pay->value('SELECT currency_code FROM (SELECT currency_code FROM services WHERE user_id = ? UNION ALL SELECT currency_code FROM invoices WHERE user_id = ?) x GROUP BY currency_code ORDER BY COUNT(*) DESC, currency_code LIMIT 1', [$id, $id]) ?: ($this->setting('default_currency') ?: 'USD'));
        // Paymenter keeps the country's English name, as its country list gives it.
        $country = $fields['country'][1] ?? '';
        $iso = self::COUNTRY_NAMES[mb_strtolower($country)] ?? (array_search(mb_strtolower($country), array_map(mb_strtolower(...), Countries::getNames('en')), true) ?: (Countries::exists(strtoupper($country)) ? strtoupper($country) : ''));
        if ($country !== '' && $iso === '') {
            $this->warn(sprintf('Client #%d: its country "%s" is not known, so it was left empty.', $id, $country));
        }
        $credit = 0.0;
        foreach ($this->pay->rows('SELECT currency_code, amount FROM credits WHERE user_id = ? ORDER BY id', [$id]) as $c) {
            if ($c['currency_code'] === $code) {
                $credit += (float) $c['amount'];
            } elseif ((float) $c['amount'] > 0) {
                $this->warn(sprintf('Client #%d has %s %s of credit in Paymenter, but is billed in %s: that credit was not imported.', $id, number_format((float) $c['amount'], 2, '.', ''), $c['currency_code'], $code));
            }
        }
        $row = ['id' => $id, 'firstname' => (string) $u['first_name'], 'lastname' => (string) $u['last_name'], 'email' => $email, 'country' => $iso, 'tax_id' => '', 'password' => (string) $u['password']];
        foreach (self::FIELDS as $key => $column) {
            $row[$column] = $fields[$key][1] ?? '';
        }
        $this->db->insert('tblclients', $row + [
            'currency' => $this->currency($code), 'taxexempt' => 0, 'datecreated' => $created, 'created_at' => $created . ' 00:00:00', 'status' => 'Active',
            // The credit balance; the WHMCS step makes it the opening line of the credit history.
            'credit' => max(0.0, round($credit, 2)),
        ]);
        // The hash as Paymenter keeps it: the WHMCS step keeps one PHP can check and names the others.
        $this->db->insert('tblusers', ['id' => $id, 'first_name' => (string) $u['first_name'], 'last_name' => (string) $u['last_name'], 'email' => $email, 'password' => (string) $u['password'], 'created_at' => $created . ' 00:00:00']);
        $this->db->insert('tblusers_clients', ['auth_user_id' => $id, 'client_id' => $id, 'owner' => 1, 'created_at' => $created . ' 00:00:00']);
        if (trim((string) ($u['tfa_secret'] ?? '')) !== '') {
            $this->warn(sprintf('Client %s used two-factor sign-in in Paymenter, which could not be carried over: it signs in with its password until two-factor is set up again.', $email));
        }
        // Fields of its own (custom client fields) are kept as a note.
        $other = array_diff_key($fields, self::FIELDS, ['country' => true]);
        if (array_filter(array_column($other, 1)) !== []) {
            $note = implode("\n", array_map(static fn (array $f): string => $f[0] . ': ' . $f[1], array_filter($other, static fn (array $f): bool => $f[1] !== '')));
            $this->db->insert('tblnotes', ['userid' => $id, 'adminid' => 0, 'created' => $created . ' 00:00:00', 'modified' => $created . ' 00:00:00', 'note' => $note, 'sticky' => 0]);
        }
    }

    /** The tax rate Paymenter charges a client now: its country's, else the one for all countries (0 with tax off). */
    private function rate(int $client): float
    {
        if ($this->taxType() === '') {
            return 0.0;
        }
        $country = (string) $this->db->fetchOne('SELECT country FROM tblclients WHERE id = ?', [$client]);

        return (float) $this->pay->value("SELECT rate FROM tax_rates WHERE country IN (?, 'all') ORDER BY country = 'all' LIMIT 1", [$country]);
    }

    /** @param array<string, mixed> $s */
    private function service(array $s): void
    {
        $id = (int) $s['id'];
        $client = (int) $s['user_id'];
        if ($this->db->fetchOne('SELECT 1 FROM tblclients WHERE id = ?', [$client]) === false) {
            return;
        }
        $plan = $this->pay->rows('SELECT * FROM plans WHERE id = ?', [(int) $s['plan_id']])[0] ?? null;
        if ($plan === null) {
            $this->warn(sprintf('Service #%d of client #%d was not imported: its plan no longer exists in Paymenter.', $id, $client));

            return;
        }
        $months = self::months($plan);
        if ($months === null) {
            $this->warn(sprintf('Service #%d of client #%d was not imported: it renews every %s, which ClientFence does not bill.', $id, $client, self::period($plan)));

            return;
        }
        $qty = max(1, (int) $s['quantity']);
        $amount = round((float) $s['price'] * $qty, 2);
        $rate = $this->taxType() === 'exclusive' ? $this->rate($client) : 0.0;
        // Paymenter's price holds the tax; with tax added to prices, WHMCS's amount leaves it out.
        if ($rate > 0) {
            $amount = round($amount * 100 / (100 + $rate), 2);
        }
        // A coupon for some invoices: Paymenter's price stays discounted until it puts the full price back.
        $coupon = (int) ($s['coupon_id'] ?? 0) > 0 ? (int) $this->pay->value('SELECT recurring FROM coupons WHERE id = ?', [(int) $s['coupon_id']]) : 0;
        if ($coupon > 1) {
            $this->warn(sprintf('Service #%d of client #%d has a Paymenter coupon for its first %d invoices: it was imported at its discounted price, so set its full price when that coupon ends.', $id, $client, $coupon));
        }
        $fields = $this->properties('App\Models\Service', $id);
        $start = self::day($s['created_at'] ?? null) ?: date('Y-m-d');
        $notes = array_merge(trim((string) ($s['label'] ?? '')) !== '' ? [(string) $s['label']] : [], array_map(static fn (array $f): string => $f[0] . ': ' . $f[1], array_values($fields)));
        $this->db->insert('tblhosting', [
            'id' => $id, 'userid' => $client, 'packageid' => (int) $s['product_id'], 'regdate' => $start, 'domain' => $fields['domain'][1] ?? '', 'qty' => $qty,
            'firstpaymentamount' => $amount, 'amount' => $amount, 'billingcycle' => match ($plan['type']) {
                'free' => 'Free Account',
                'one-time' => 'One Time',
                default => self::CYCLES[$months],
            },
            'nextduedate' => self::day($s['expires_at'] ?? null) ?: $start, 'domainstatus' => self::SERVICE_STATUSES[(string) $s['status']] ?? 'Pending', 'notes' => implode("\n", $notes),
        ]);
        // A cancellation at the end of the period: the WHMCS step puts it in Cancellation Requests, so it does not renew.
        $cancel = $s['status'] === 'cancelled' ? null : ($this->pay->rows('SELECT * FROM service_cancellations WHERE service_id = ? ORDER BY id DESC LIMIT 1', [$id])[0] ?? null);
        if ($cancel !== null) {
            $this->db->insert('tblcancelrequests', ['date' => (string) $cancel['created_at'], 'relid' => $id, 'reason' => (string) ($cancel['reason'] ?? '') ?: 'Cancellation requested in Paymenter', 'type' => 'End of Billing Period']);
        }
        foreach ($this->pay->rows('SELECT config_option_id, config_value_id FROM service_configs WHERE configurable_type = ? AND configurable_id = ? ORDER BY id', ['App\Models\Service', $id]) as $c) {
            if ($this->db->fetchOne('SELECT 1 FROM tblproductconfigoptionssub WHERE id = ? AND configid = ?', [(int) $c['config_value_id'], (int) $c['config_option_id']]) !== false) {
                // A ticked checkbox is a WHMCS yes/no option that is on: quantity 1.
                $yesNo = (int) $this->db->fetchOne('SELECT optiontype FROM tblproductconfigoptions WHERE id = ?', [(int) $c['config_option_id']]) === 3;
                $this->db->insert('tblhostingconfigoptions', ['relid' => $id, 'configid' => (int) $c['config_option_id'], 'optionid' => (int) $c['config_value_id'], 'qty' => $yesNo ? 1 : 0]);
            }
        }
    }

    /** @param array<string, mixed> $i */
    private function invoice(array $i): void
    {
        $id = (int) $i['id'];
        $client = (int) $i['user_id'];
        if ($this->db->fetchOne('SELECT 1 FROM tblclients WHERE id = ?', [$client]) === false) {
            return;
        }
        $this->billedIn($client, (string) $i['currency_code'], sprintf('invoice #%d', $id));
        $lines = $this->pay->rows('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id', [$id]);
        $gross = round(array_sum(array_map(static fn (array $l): float => (float) $l['price'] * (int) $l['quantity'], $lines)), 2);
        // A credit deposit is not a sale: untaxed, as WHMCS's AddFunds; the tax is on what the credit pays.
        $deposits = round(array_sum(array_map(static fn (array $l): float => $l['reference_type'] === 'App\\Models\\Credit' ? (float) $l['price'] * (int) $l['quantity'] : 0.0, $lines)), 2);
        // The rate the invoice was paid at (its snapshot), else the client's now; Paymenter shows no tax with tax off.
        $snapshot = $this->taxType() === '' ? false : $this->pay->value('SELECT tax_rate FROM invoice_snapshots WHERE invoice_id = ? AND tax_name IS NOT NULL ORDER BY id DESC LIMIT 1', [$id]);
        $rate = $this->taxType() === '' ? 0.0 : (float) ($snapshot !== false ? $snapshot : $this->rate($client));
        $tax = $rate > 0 ? round(($gross - $deposits) - ($gross - $deposits) / (1 + $rate / 100), 2) : 0.0;
        $money = $this->pay->rows("SELECT SUM(CASE WHEN is_credit_transaction = 1 THEN amount ELSE 0 END) AS credit, MAX(created_at) AS paid FROM invoice_transactions WHERE invoice_id = ? AND status = 'succeeded'", [$id])[0] ?? [];
        // Paid from the client's credit: WHMCS's credit applied, which its total leaves out.
        $credit = round((float) ($money['credit'] ?? 0), 2);
        $status = self::INVOICE_STATUSES[(string) $i['status']] ?? null;
        if ($status === null) {
            $this->warn(sprintf('Invoice #%d has the status "%s" in Paymenter, which the import does not know: it was imported as Cancelled, check it.', $id, (string) $i['status']));
            $status = 'Cancelled';
        }
        $this->db->insert('tblinvoices', [
            'id' => $id, 'userid' => $client, 'invoicenum' => (string) ($i['number'] ?? ''),
            'date' => self::day($i['created_at']), 'duedate' => self::day($i['due_at'] ?? null) ?: self::day($i['created_at']),
            'datepaid' => $status === 'Paid' ? (string) (($money['paid'] ?? null) ?: $i['updated_at']) : '0000-00-00 00:00:00',
            'subtotal' => round($gross - $tax, 2), 'credit' => $credit, 'tax' => $tax, 'total' => round($gross - $credit, 2), 'taxrate' => $rate, 'status' => $status,
        ]);
        // Lines hold the tax; with tax added to prices, WHMCS's lines leave it out.
        $net = $rate > 0 && $this->taxType() === 'exclusive' ? 100 / (100 + $rate) : 1.0;
        $renews = [];
        foreach ($lines as $line) {
            $service = (int) $line['reference_id'];
            // Paying a service's line renews it: only its first line on the invoice.
            $type = match ((string) $line['reference_type']) {
                'App\Models\Service' => isset($renews[$service]) || $this->db->fetchOne('SELECT 1 FROM tblhosting WHERE id = ?', [$service]) === false ? '' : 'Hosting',
                'App\Models\Credit' => 'AddFunds',
                default => '',
            };
            $renews[$service] = true;
            if ($status === 'Unpaid' && $line['reference_type'] === 'App\Models\ServiceUpgrade') {
                $this->warn(sprintf('Invoice #%d bills an upgrade in Paymenter: paying it in ClientFence does not change the service, so make the upgrade by hand.', $id));
            }
            $qty = (int) $line['quantity'];
            $taxed = $rate > 0 && $type !== 'AddFunds';
            $this->db->insert('tblinvoiceitems', [
                'invoiceid' => $id, 'userid' => $client, 'type' => $type, 'relid' => $type === 'Hosting' ? $service : 0,
                'description' => ($qty !== 1 ? $qty . ' x ' : '') . (string) ($line['description'] ?? ''),
                'amount' => round((float) $line['price'] * $qty * ($taxed ? $net : 1.0), 2), 'taxed' => $taxed ? 1 : 0,
            ]);
        }
    }

    /** @param array<string, mixed> $t a transaction with its invoice's client and gateway */
    private function payment(array $t): void
    {
        $invoice = (int) $t['invoice_id'];
        // A payment from credit is the invoice's credit applied (invoice()), not money received.
        if ((int) $t['is_credit_transaction'] === 1 || $t['status'] === 'failed' || $this->db->fetchOne('SELECT 1 FROM tblinvoices WHERE id = ?', [$invoice]) === false) {
            return;
        }
        if ($t['status'] !== 'succeeded') {
            $this->warn(sprintf('Payment #%d of invoice #%d was still processing in Paymenter: it was not imported; check it at the gateway.', (int) $t['id'], $invoice));

            return;
        }
        $this->db->insert('tblaccounts', [
            'userid' => (int) $t['user_id'], 'gateway' => strtolower((string) ($t['extension'] ?? '')), 'date' => (string) $t['created_at'], 'description' => 'Invoice Payment',
            'amountin' => round((float) $t['amount'], 2), 'fees' => round((float) ($t['fee'] ?? 0), 2), 'transid' => (string) ($t['transaction_id'] ?? '') ?: 'paymenter-' . $t['id'], 'invoiceid' => $invoice,
        ]);
    }

    /** @param array<string, mixed> $t */
    private function ticket(array $t): void
    {
        $id = (int) $t['id'];
        $owner = (int) $t['user_id'];
        $who = $this->db->fetchAssociative('SELECT firstname, lastname, email FROM tblclients WHERE id = ?', [$owner]);
        $client = $who === false ? 0 : $owner;
        $first = null;
        $last = (string) $t['created_at'];
        foreach ($this->pay->rows('SELECT m.*, u.first_name, u.last_name, u.email, u.role_id FROM ticket_messages m LEFT JOIN users u ON u.id = m.user_id WHERE m.ticket_id = ? ORDER BY m.created_at, m.id', [$id]) as $m) {
            // Staff: someone else than the ticket's owner with a staff role.
            $staff = (int) $m['user_id'] !== $owner && $m['role_id'] !== null ? (trim($m['first_name'] . ' ' . $m['last_name']) ?: 'Staff') : '';
            $last = (string) $m['created_at'];
            if ($first === null) {
                $first = ['message' => (string) $m['message'], 'admin' => $staff];
            } else {
                $this->db->insert('tblticketreplies', [
                    'tid' => $id, 'userid' => $staff !== '' ? 0 : $client, 'name' => $staff !== '' ? '' : trim($m['first_name'] . ' ' . $m['last_name']),
                    'email' => $staff !== '' ? '' : (string) $m['email'], 'date' => (string) $m['created_at'], 'message' => (string) $m['message'], 'admin' => $staff,
                ]);
            }
        }
        $service = (int) ($t['service_id'] ?? 0);
        $this->db->insert('tbltickets', [
            'id' => $id, 'status' => self::TICKET_STATUSES[(string) $t['status']] ?? 'Open', 'tid' => (string) $id,
            'did' => (int) ($this->value('department', trim((string) $t['department'])) ?? 1), 'userid' => $client,
            'name' => $who ? trim($who['firstname'] . ' ' . $who['lastname']) : '', 'email' => $who ? (string) $who['email'] : '', 'c' => bin2hex(random_bytes(4)),
            'date' => (string) $t['created_at'], 'title' => (string) $t['subject'], 'message' => (string) ($first['message'] ?? ''),
            'urgency' => self::URGENCY[(string) $t['priority']] ?? 'Medium', 'admin' => (string) ($first['admin'] ?? ''), 'lastreply' => $last,
            'service' => $service > 0 && $this->db->fetchOne('SELECT 1 FROM tblhosting WHERE id = ?', [$service]) !== false ? 'S' . $service : '',
        ]);
    }

    /** After the last step: nothing more to read. */
    protected function finish(): void
    {
    }

    /** A date (Y-m-d) of a DATE or DATETIME value; '' for none. */
    private static function day(mixed $value): string
    {
        $day = substr(trim((string) $value), 0, 10);

        return $day === '' || str_starts_with($day, '0000') ? '' : $day;
    }

    /** ClientFence bills a client in one currency, so an invoice in another is named. */
    private function billedIn(int $client, string $code, string $what): void
    {
        $currency = $this->db->fetchOne('SELECT cur.code FROM tblclients c JOIN tblcurrencies cur ON cur.id = c.currency WHERE c.id = ?', [$client]);
        if ($currency !== false && $currency !== $code) {
            $this->warn(sprintf('Client #%d is billed in %s, but its %s is in %s in Paymenter: it is imported in %s.', $client, (string) $currency, $what, $code, (string) $currency));
        }
    }

    /** The WHMCS currency id of a code (Paymenter's currencies are read in setup). */
    private function currency(string $code): int
    {
        $id = $this->db->fetchOne('SELECT id FROM tblcurrencies WHERE code = ?', [$code]);
        if ($id === false) {
            $this->db->insert('tblcurrencies', ['code' => $code, 'prefix' => '', 'suffix' => ' ' . $code, 'format' => 2, 'rate' => 1, '"default"' => 0]);

            return (int) $this->db->lastInsertId();
        }

        return (int) $id;
    }
}
