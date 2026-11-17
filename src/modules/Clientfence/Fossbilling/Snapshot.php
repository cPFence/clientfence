<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Fossbilling;

use ClientFence\Mod\Clientfence\Whmcs\Snapshot as Base;
use ClientFence\Mod\Clientfence\Whmcs\Source;
use Doctrine\DBAL\Connection;

/**
 * ClientFence: the FOSSBilling import. fetch() reads FOSSBilling's database (read only, as the
 * WHMCS import reads WHMCS's: Whmcs\Source) into the import's snapshot (Whmcs/Snapshot.php), a
 * page of rows at a time, keeping FOSSBilling's ids.
 *
 * A client's order is its service: hosting, domain (with its service_domain row), an add-on of the
 * main order of its group, or any other product. FOSSBilling pays a gateway payment through the
 * client's credit balance, so only payments of paid invoices come over: an unpaid invoice's
 * payment is still in that balance, which comes over as the client's credit. Password hashes are
 * PHP's, so clients keep their passwords. Left out with a warning: promotions, servers and hosting
 * plans, gateway subscriptions, ticket attachments and weekly or 4-5 year billing.
 */
final class Snapshot extends Base
{
    public const NAME = 'FOSSBilling';
    public const STATE = 'fossbilling_fetch';
    public const STEP = 'fossbilling';
    public const FILE = 'fossbilling-import.sqlite';
    /** Rows read per page. */
    public const PAGE = 200;

    public const STEPS = [
        'setup' => 'Currencies, taxes, groups and departments',
        'products' => 'Products and domain prices',
        'clients' => 'Clients',
        'services' => 'Services and domains',
        'invoices' => 'Invoices',
        'payments' => 'Payments',
        'tickets' => 'Tickets',
    ];

    /** Order periods => months (0: one time); weekly and 4-5 years have no WHMCS cycle. */
    private const PERIODS = ['' => 0, '1M' => 1, '3M' => 3, '6M' => 6, '1Y' => 12, '2Y' => 24, '3Y' => 36];

    /** product_payment price columns => months. */
    private const PRICES = ['m' => 1, 'q' => 3, 'b' => 6, 'a' => 12, 'bia' => 24, 'tria' => 36];

    private const CLIENT_STATUSES = ['active' => 'Active', 'suspended' => 'Inactive', 'canceled' => 'Closed'];

    private const SERVICE_STATUSES = ['pending_setup' => 'Pending', 'failed_setup' => 'Pending', 'active' => 'Active', 'failed_renew' => 'Active', 'suspended' => 'Suspended', 'canceled' => 'Cancelled'];

    private const DOMAIN_STATUSES = ['pending_setup' => 'Pending', 'failed_setup' => 'Pending', 'active' => 'Active', 'failed_renew' => 'Active', 'suspended' => 'Active', 'canceled' => 'Cancelled'];

    private const INVOICE_STATUSES = ['paid' => 'Paid', 'unpaid' => 'Unpaid', 'refunded' => 'Refunded', 'canceled' => 'Cancelled'];

    /** on_hold waits on the client: staff answered. */
    private const TICKET_STATUSES = ['open' => 'Open', 'on_hold' => 'Answered', 'closed' => 'Closed'];

    /**
     * Reads FOSSBilling for up to $seconds (0: until done); $restart starts again from nothing.
     * $settings are the import settings (FOSSBilling's database); tests hand in their own $source.
     *
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    public static function fetch(\Pimple\Container $di, array $settings, float $seconds = 0.0, bool $restart = false, ?Source $source = null): array
    {
        return self::read($di, static fn (Connection $db): self => new self($source ?? Source::mysql($di, $settings, 'FOSSBilling'), $db), $seconds, $restart);
    }

    /** Whether a database is FOSSBilling's: its order, price, hosting plan and ticket tables. */
    public static function isFossbilling(Source $source): bool
    {
        return $source->hasTable('client_order') && $source->hasTable('product_payment') && $source->hasTable('service_hosting_hp') && $source->hasTable('support_ticket');
    }

    private function __construct(private readonly Source $fb, Connection $db)
    {
        parent::__construct($db);
    }

    protected function close(): void
    {
        $this->fb->close();
    }

    protected function page(string $step, int $offset): array
    {
        if ($step === 'setup') {
            return [null, $this->setup()];
        }
        $sql = [
            'products' => 'SELECT p.*, pp.type AS pay_type, pp.once_price, pp.once_setup_price, pp.w_price, pp.w_setup_price, pp.w_enabled, '
                . 'pp.m_price, pp.q_price, pp.b_price, pp.a_price, pp.bia_price, pp.tria_price, '
                . 'pp.m_setup_price, pp.q_setup_price, pp.b_setup_price, pp.a_setup_price, pp.bia_setup_price, pp.tria_setup_price, '
                . 'pp.m_enabled, pp.q_enabled, pp.b_enabled, pp.a_enabled, pp.bia_enabled, pp.tria_enabled '
                . 'FROM product p LEFT JOIN product_payment pp ON pp.id = p.product_payment_id WHERE p.id > ? ORDER BY p.id',
            'clients' => 'SELECT * FROM client WHERE id > ? ORDER BY id',
            'services' => 'SELECT o.*, p.is_addon FROM client_order o LEFT JOIN product p ON p.id = o.product_id WHERE o.id > ? ORDER BY o.id',
            'invoices' => 'SELECT * FROM invoice WHERE id > ? ORDER BY id',
            'payments' => 'SELECT t.*, i.client_id, g.gateway AS gateway_code FROM `transaction` t JOIN invoice i ON i.id = t.invoice_id LEFT JOIN pay_gateway g ON g.id = t.gateway_id WHERE t.id > ? ORDER BY t.id',
            'tickets' => 'SELECT * FROM support_ticket WHERE id > ? ORDER BY id',
        ][$step];
        $rows = $this->fb->rows($sql . ' LIMIT ' . self::PAGE, [$offset]);
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
        if ($step === 'products' && count($rows) < self::PAGE) {
            $this->tlds();
        }

        return [count($rows) < self::PAGE ? null : (int) end($rows)['id'], count($rows)];
    }

    private function setting(string $name): string
    {
        $value = $this->fb->value('SELECT value FROM setting WHERE param = ?', [$name]);

        return $value === false ? '' : (string) $value;
    }

    private function setup(): int
    {
        $this->db->insert('tblconfiguration', ['setting' => 'Version', 'value' => '8.0.0 (FOSSBilling)']);
        $this->db->insert('tblconfiguration', ['setting' => 'SequentialInvoiceNumbering', 'value' => 'on']);
        foreach ($this->fb->rows('SELECT * FROM currency ORDER BY id') as $c) {
            // format_pattern is the amount's text around "{amount}", such as "${amount}" or "{amount} €".
            [$prefix, $suffix] = str_contains((string) $c['format_pattern'], '{amount}') ? explode('{amount}', (string) $c['format_pattern'], 2) : ['', ' ' . $c['code']];
            $this->db->insert('tblcurrencies', [
                'id' => (int) $c['id'], 'code' => (string) $c['code'], 'prefix' => $prefix, 'suffix' => $suffix,
                'format' => (int) ($c['fraction_digits'] ?? 2) === 0 ? 4 : 2, 'rate' => (float) $c['conversion_rate'] ?: 1, '"default"' => (int) $c['is_default'],
            ]);
        }
        foreach ($this->fb->rows('SELECT * FROM tax ORDER BY id') as $t) {
            $this->db->insert('tbltax', [
                // FOSSBilling has one tax level: its rule for the client's state, else country, else everywhere.
                'id' => (int) $t['id'], 'level' => '1', 'name' => (string) $t['name'],
                'state' => (string) $t['state'], 'country' => (string) $t['country'], 'taxrate' => (float) $t['taxrate'],
            ]);
        }
        // FOSSBilling taxes every line of a taxable client; prices never include the tax.
        if ($this->setting('tax_enabled') === '1') {
            $this->db->insert('tblconfiguration', ['setting' => 'TaxEnabled', 'value' => 'on']);
            $this->db->insert('tblconfiguration', ['setting' => 'TaxType', 'value' => 'Exclusive']);
            $this->db->insert('tblconfiguration', ['setting' => 'TaxDomains', 'value' => 'on']);
        }
        foreach ($this->fb->rows('SELECT * FROM client_group ORDER BY id') as $g) {
            $this->db->insert('tblclientgroups', ['id' => (int) $g['id'], 'groupname' => (string) $g['title'], 'groupcolour' => '', 'discountpercent' => 0, 'susptermexempt' => '', 'separateinvoices' => '']);
        }
        foreach ($this->fb->rows('SELECT * FROM product_category ORDER BY id') as $i => $g) {
            $this->db->insert('tblproductgroups', ['id' => (int) $g['id'], 'name' => (string) $g['title'] ?: 'Products', 'slug' => '', 'hidden' => 0, '"order"' => $i]);
        }
        foreach ($this->fb->rows('SELECT * FROM support_helpdesk ORDER BY id') as $d) {
            $this->db->insert('tblticketdepartments', ['id' => (int) $d['id'], 'name' => (string) $d['name'], 'email' => (string) ($d['email'] ?? '')]);
        }
        $counts = [
            'promotions' => $this->fb->count('promo'),
            'hosting servers' => $this->fb->count('service_hosting_server'),
            'hosting plans' => $this->fb->count('service_hosting_hp'),
            'gateway subscriptions' => $this->fb->count('subscription', "status = 'active'"),
            'ticket attachments' => $this->fb->count('support_ticket_message', "attachment IS NOT NULL AND attachment <> ''"),
        ];
        foreach (array_filter($counts) as $what => $count) {
            $this->warn(sprintf('FOSSBilling has %d %s: they were not imported.', $count, $what));
        }

        return 1;
    }

    /** @param array<string, mixed> $p a product with its price row */
    private function product(array $p): void
    {
        $id = (int) $p['id'];
        $name = trim((string) $p['title']) ?: 'Product #' . $id;
        // Domains are sold by their extensions (tlds()).
        if ($p['type'] === 'domain') {
            return;
        }
        $paytype = match ((string) $p['pay_type']) {
            'free' => 'free',
            'once' => 'onetime',
            default => 'recurring',
        };
        $addon = (int) $p['is_addon'] === 1;
        $common = ['hidden' => (int) $p['hidden'], 'retired' => $p['status'] === 'enabled' ? 0 : 1, 'tax' => 1, 'description' => (string) ($p['description'] ?? '')];
        if ($addon) {
            // An add-on is offered with the products whose add-on list names it.
            $parents = array_filter(
                $this->fb->rows('SELECT id, addons FROM product WHERE addons IS NOT NULL'),
                static fn (array $r): bool => in_array($id, array_map('intval', (array) json_decode((string) $r['addons'], true)), true),
            );
            $this->db->insert('tbladdons', $common + [
                'id' => $id, 'packages' => implode(',', array_column($parents, 'id')), 'name' => $name,
                'billingcycle' => $paytype === 'recurring' ? 'recurring' : $paytype, 'allowqty' => 0, 'showorder' => 1, 'autoactivate' => '', 'module' => '', 'weight' => 0,
            ]);
        } else {
            $this->db->insert('tblproducts', $common + [
                'id' => $id, 'type' => $p['type'] === 'hosting' ? 'hostingaccount' : 'other', 'gid' => (int) $p['product_category_id'], 'name' => $name, 'slug' => (string) ($p['slug'] ?? ''),
                'stockcontrol' => (int) $p['stock_control'], 'qty' => (int) $p['quantity_in_stock'], 'allowqty' => (int) $p['allow_quantity_select'], 'paytype' => $paytype, '"order"' => (int) ($p['priority'] ?? 0),
            ]);
            foreach (array_map('intval', (array) json_decode((string) ($p['upgrades'] ?? ''), true)) as $upgrade) {
                $this->db->insert('tblproduct_upgrade_products', ['product_id' => $id, 'upgrade_product_id' => $upgrade]);
            }
            if (!in_array($p['type'], ['hosting', 'custom'], true)) {
                $this->warn(sprintf('Product "%s" is a %s product in FOSSBilling: it was imported as a plain product, without its %s settings.', $name, (string) $p['type'], (string) $p['type']));
            }
        }
        // FOSSBilling prices a product in the default currency; others follow its rate.
        $row = ['type' => $addon ? 'addon' : 'product', 'currency' => (int) $this->db->fetchOne('SELECT id FROM tblcurrencies ORDER BY "default" DESC, id LIMIT 1'), 'relid' => $id, 'msetupfee' => 0, 'qsetupfee' => 0, 'ssetupfee' => 0, 'asetupfee' => 0, 'bsetupfee' => 0, 'tsetupfee' => 0];
        if ($paytype === 'onetime') {
            $row['monthly'] = (float) $p['once_price'];
            $row['msetupfee'] = (float) $p['once_setup_price'];
        } elseif ($paytype === 'recurring') {
            // FOSSBilling leaves every period on at 0.00 until it is unticked: a period counts once it has a price.
            $offered = static fn (string $key): bool => (int) $p[$key . '_enabled'] === 1 && ((float) $p[$key . '_price'] > 0 || (float) $p[$key . '_setup_price'] > 0);
            foreach (self::PRICES as $key => $months) {
                if ($offered($key)) {
                    $row[self::PRICE_COLUMNS[$months]] = (float) $p[$key . '_price'];
                    $row[['monthly' => 'msetupfee', 'quarterly' => 'qsetupfee', 'semiannually' => 'ssetupfee', 'annually' => 'asetupfee', 'biennially' => 'bsetupfee', 'triennially' => 'tsetupfee'][self::PRICE_COLUMNS[$months]]] = (float) $p[$key . '_setup_price'];
                }
            }
            if ($offered('w')) {
                $this->warn(sprintf('Product "%s": its weekly price was not imported (ClientFence bills 1, 3, 6, 12, 24 or 36 months, or once).', $name));
            }
        }
        $this->db->insert('tblpricing', $row);
    }

    /** Domain prices by years, as FOSSBilling charges them: the first year's price, then the renewal price a year. */
    private function tlds(): void
    {
        $currency = (int) $this->db->fetchOne('SELECT id FROM tblcurrencies ORDER BY "default" DESC, id LIMIT 1');
        foreach ($this->fb->rows('SELECT * FROM tld WHERE active = 1 ORDER BY id') as $t) {
            $id = (int) $t['id'];
            $this->db->insert('tbldomainpricing', ['id' => $id, 'extension' => '.' . ltrim(strtolower((string) $t['tld']), '.'), 'eppcode' => (int) $t['require_transfer_code'] === 1 ? 'on' : '']);
            $rows = [];
            for ($years = max(1, (int) $t['min_years']); $years <= 10; ++$years) {
                $column = self::DOMAIN_COLUMNS[$years];
                $rows['domainrenew'][$column] = round($years * (float) $t['price_renew'], 2);
                if ((int) $t['allow_register'] === 1) {
                    $rows['domainregister'][$column] = round((float) $t['price_registration'] + ($years - 1) * (float) $t['price_renew'], 2);
                }
            }
            if ((int) $t['allow_transfer'] === 1) {
                $rows['domaintransfer'][self::DOMAIN_COLUMNS[1]] = (float) $t['price_transfer'];
            }
            foreach ($rows as $type => $columns) {
                $this->db->insert('tblpricing', ['type' => $type, 'currency' => $currency, 'relid' => $id] + $columns);
            }
        }
    }

    /** @param array<string, mixed> $c */
    private function client(array $c): void
    {
        $id = (int) $c['id'];
        $email = trim((string) $c['email']);
        $created = self::day($c['created_at'] ?? null) ?: date('Y-m-d');
        $phone = trim((string) ($c['phone'] ?? ''));
        $code = trim((string) ($c['currency'] ?? ''));
        $this->db->insert('tblclients', [
            'id' => $id, 'firstname' => (string) $c['first_name'], 'lastname' => (string) $c['last_name'], 'companyname' => (string) $c['company'], 'email' => $email,
            'address1' => (string) $c['address_1'], 'address2' => (string) $c['address_2'], 'city' => (string) $c['city'], 'state' => (string) $c['state'],
            'postcode' => (string) $c['postcode'], 'country' => strtoupper((string) $c['country']),
            // WHMCS's "+CC.number", which the WHMCS step splits again.
            'phonenumber' => $phone !== '' && trim((string) $c['phone_cc']) !== '' ? '+' . ltrim(trim((string) $c['phone_cc']), '+') . '.' . $phone : $phone,
            'tax_id' => (string) $c['company_vat'], 'password' => (string) $c['pass'], 'language' => (string) ($c['lang'] ?? ''),
            'currency' => $code !== '' ? $this->currency($code) : (int) $this->db->fetchOne('SELECT id FROM tblcurrencies ORDER BY "default" DESC, id LIMIT 1'),
            'taxexempt' => (int) $c['tax_exempt'], 'datecreated' => $created, 'created_at' => $created . ' 00:00:00',
            'groupid' => (int) ($c['client_group_id'] ?? 0), 'status' => self::CLIENT_STATUSES[(string) $c['status']] ?? 'Active',
            'credit' => max(0.0, round((float) $this->fb->value('SELECT COALESCE(SUM(amount), 0) FROM client_balance WHERE client_id = ?', [$id]), 2)),
        ]);
        $this->db->insert('tblusers', ['id' => $id, 'first_name' => (string) $c['first_name'], 'last_name' => (string) $c['last_name'], 'email' => $email, 'password' => (string) $c['pass'], 'created_at' => $created . ' 00:00:00']);
        $this->db->insert('tblusers_clients', ['auth_user_id' => $id, 'client_id' => $id, 'owner' => 1, 'created_at' => $created . ' 00:00:00']);
        // The credit history; the WHMCS step makes the balance end at tblclients.credit.
        foreach ($this->fb->rows('SELECT * FROM client_balance WHERE client_id = ? ORDER BY id', [$id]) as $b) {
            $this->db->insert('tblcredit', [
                'clientid' => $id, 'date' => (string) $b['created_at'], 'description' => (string) $b['description'], 'amount' => round((float) $b['amount'], 2),
                'relid' => $b['type'] === 'invoice' ? (int) $b['rel_id'] : 0,
            ]);
        }
        if (trim((string) ($c['notes'] ?? '')) !== '') {
            $this->db->insert('tblnotes', ['userid' => $id, 'adminid' => 0, 'created' => $created . ' 00:00:00', 'modified' => $created . ' 00:00:00', 'note' => trim((string) $c['notes']), 'sticky' => 0]);
        }
    }

    /** @param array<string, mixed> $o a client's order with its product's add-on flag */
    private function service(array $o): void
    {
        $id = (int) $o['id'];
        $client = (int) $o['client_id'];
        if ($this->db->fetchOne('SELECT 1 FROM tblclients WHERE id = ?', [$client]) === false) {
            return;
        }
        $period = strtoupper(trim((string) ($o['period'] ?? '')));
        $months = self::PERIODS[$period] ?? null;
        $title = trim((string) $o['title']) ?: 'Order #' . $id;
        $amount = round((float) $o['price'] * max(1, (int) $o['quantity']), 2);
        $status = (string) $o['status'];
        // FOSSBilling bills no renewal for a "no-invoice" order; WHMCS's do-not-renew keeps it so.
        $noRenew = (string) ($o['invoice_option'] ?? '') === 'no-invoice' ? 1 : 0;
        $start = self::day($o['activated_at'] ?? null) ?: (self::day($o['created_at'] ?? null) ?: date('Y-m-d'));
        // A domain's period is its years ("5Y" from the cart): the domain row keeps them, so it is no billing cycle.
        if ($o['service_type'] === 'domain') {
            $d = $this->fb->rows('SELECT d.*, r.registrar FROM service_domain d LEFT JOIN tld_registrar r ON r.id = d.tld_registrar_id WHERE d.id = ?', [(int) $o['service_id']])[0] ?? [];
            // A domain's order has no expiry in FOSSBilling: the domain renews when its registration ends.
            $due = self::day($o['expires_at'] ?? null) ?: (self::day($d['expires_at'] ?? null) ?: $start);
            $this->db->insert('tbldomains', [
                'id' => $id, 'userid' => $client, 'registrationdate' => self::day($d['registered_at'] ?? null) ?: $start,
                'domain' => strtolower(($d['sld'] ?? '') . ($d['tld'] ?? '')) ?: $title, 'firstpaymentamount' => $amount, 'recurringamount' => $amount,
                'registrar' => strtolower((string) ($d['registrar'] ?? '')), 'registrationperiod' => max(1, (int) ($d['period'] ?? 0) ?: intdiv($months ?: 12, 12)),
                'expirydate' => self::day($d['expires_at'] ?? null) ?: $due, 'nextduedate' => $due, 'status' => self::DOMAIN_STATUSES[$status] ?? 'Pending',
                'type' => ($d['action'] ?? '') === 'transfer' ? 'Transfer' : 'Register', 'donotrenew' => $noRenew,
            ]);

            return;
        }
        if ($months === null) {
            $this->warn(sprintf('Order #%d (%s) of client #%d was not imported: it renews every %s, which ClientFence does not bill.', $id, $title, $client, $period));

            return;
        }
        // A recurring promotion takes its discount off every renewal (FOSSBilling's getRenewalPromoAdjustment()) while it exists.
        if ((int) ($o['promo_recurring'] ?? 0) === 1 && (float) ($o['discount'] ?? 0) > 0 && $this->fb->value('SELECT 1 FROM promo WHERE id = ?', [(int) ($o['promo_id'] ?? 0)]) !== false) {
            $amount = max(0.0, round($amount - (float) $o['discount'], 2));
        }
        $due = self::day($o['expires_at'] ?? null) ?: $start;
        $cycle = $months === 0 ? 'One Time' : self::CYCLES[$months];
        $ended = $status === 'canceled' ? (self::day($o['canceled_at'] ?? null) ?: null) : null;
        // An add-on is an order of the same group as its main order.
        $main = (int) $o['is_addon'] === 1 && (string) ($o['group_id'] ?? '') !== ''
            ? $this->fb->value('SELECT id FROM client_order WHERE client_id = ? AND group_id = ? AND group_master = 1 AND id <> ? ORDER BY id LIMIT 1', [$client, (string) $o['group_id'], $id])
            : false;
        if ($main !== false) {
            $this->db->insert('tblhostingaddons', [
                'id' => $id, 'orderid' => 0, 'hostingid' => (int) $main, 'addonid' => (int) $o['product_id'], 'userid' => $client, 'name' => $title,
                'qty' => max(1, (int) $o['quantity']), 'recurring' => $amount, 'billingcycle' => $cycle, 'status' => self::SERVICE_STATUSES[$status] ?? 'Pending',
                'regdate' => $start, 'nextduedate' => $due, 'termination_date' => $ended, 'notes' => (string) ($o['notes'] ?? ''), 'subscriptionid' => '', 'donotrenew' => $noRenew,
            ]);

            return;
        }
        $hosting = $o['service_type'] === 'hosting' ? ($this->fb->rows('SELECT sld, tld, username FROM service_hosting WHERE id = ?', [(int) $o['service_id']])[0] ?? []) : [];
        $this->db->insert('tblhosting', [
            'id' => $id, 'userid' => $client, 'packageid' => (int) $o['product_id'], 'regdate' => $start,
            'domain' => strtolower(($hosting['sld'] ?? '') . ($hosting['tld'] ?? '')), 'username' => (string) ($hosting['username'] ?? ''),
            'qty' => max(1, (int) $o['quantity']), 'firstpaymentamount' => $amount, 'amount' => $amount, 'billingcycle' => $cycle,
            'nextduedate' => $due, 'termination_date' => $ended, 'domainstatus' => self::SERVICE_STATUSES[$status] ?? 'Pending',
            'notes' => (string) ($o['notes'] ?? ''), 'suspendreason' => $status === 'suspended' ? (string) ($o['reason'] ?? '') : '', 'donotrenew' => $noRenew,
        ]);
    }

    /** @param array<string, mixed> $i */
    private function invoice(array $i): void
    {
        $id = (int) $i['id'];
        $client = (int) $i['client_id'];
        if ($this->db->fetchOne('SELECT 1 FROM tblclients WHERE id = ?', [$client]) === false) {
            return;
        }
        $this->billedIn($client, (string) $i['currency'], $id);
        $lines = $this->fb->rows('SELECT * FROM invoice_item WHERE invoice_id = ? ORDER BY id', [$id]);
        // The total as FOSSBilling makes it: the lines, and the tax rounded once on the taxed lines.
        $subtotal = round(array_sum(array_map(static fn (array $l): float => round((float) $l['price'] * (float) $l['quantity'], 2), $lines)), 2);
        $taxed = array_sum(array_map(static fn (array $l): float => (int) $l['taxed'] === 1 ? round((float) $l['price'] * (float) $l['quantity'], 2) : 0.0, $lines));
        $rate = (float) ($i['taxrate'] ?? 0);
        $tax = round($taxed * $rate / 100, 2);
        $status = self::INVOICE_STATUSES[(string) $i['status']] ?? 'Unpaid';
        if ($status === 'Unpaid' && (int) ($i['approved'] ?? 1) === 0) {
            $status = 'Draft';
        }
        $gateway = $i['gateway_id'] ? $this->fb->value('SELECT gateway FROM pay_gateway WHERE id = ?', [(int) $i['gateway_id']]) : false;
        $this->db->insert('tblinvoices', [
            'id' => $id, 'userid' => $client, 'invoicenum' => (string) $i['nr'] !== '' ? $i['serie'] . $i['nr'] : (string) $id,
            'date' => self::day($i['created_at'] ?? null), 'duedate' => self::day($i['due_at'] ?? null) ?: self::day($i['created_at'] ?? null),
            'datepaid' => in_array($status, ['Paid', 'Refunded'], true) ? (self::day($i['paid_at'] ?? null) ?: self::day($i['created_at'] ?? null)) . ' 00:00:00' : '0000-00-00 00:00:00',
            'subtotal' => $subtotal, 'tax' => $tax, 'total' => round($subtotal + $tax, 2), 'taxrate' => $tax > 0 ? $rate : 0.0, 'status' => $status,
            'paymentmethod' => $gateway === false ? '' : strtolower((string) $gateway), 'notes' => (string) ($i['notes'] ?? ''),
        ]);
        $renews = [];
        foreach ($lines as $line) {
            $order = (int) ($line['rel_id'] ?? 0);
            // Paying an order's line renews (or sets up) it: only its first line on the invoice.
            $type = match (true) {
                $line['type'] === 'deposit' => 'AddFunds',
                $line['type'] !== 'order' || $order === 0 || isset($renews[$order]) => '',
                $this->db->fetchOne('SELECT 1 FROM tbldomains WHERE id = ?', [$order]) !== false => 'Domain',
                $this->db->fetchOne('SELECT 1 FROM tblhostingaddons WHERE id = ?', [$order]) !== false => 'Addon',
                $this->db->fetchOne('SELECT 1 FROM tblhosting WHERE id = ?', [$order]) !== false => 'Hosting',
                default => '',
            };
            if (in_array($type, ['Domain', 'Addon', 'Hosting'], true)) {
                $renews[$order] = true;
            }
            $qty = (float) $line['quantity'];
            $this->db->insert('tblinvoiceitems', [
                'invoiceid' => $id, 'userid' => $client, 'type' => $type, 'relid' => in_array($type, ['', 'AddFunds'], true) ? 0 : $order,
                'description' => ($qty != 1.0 ? rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.') . ' x ' : '') . (string) $line['title'],
                'amount' => round($qty * (float) $line['price'], 2), 'taxed' => (int) $line['taxed'],
            ]);
        }
        // A refund FOSSBilling recorded on the invoice goes out as a refund transaction.
        if ($status === 'Refunded' && (float) ($i['refund'] ?? 0) > 0) {
            $this->db->insert('tblaccounts', [
                'userid' => $client, 'gateway' => $gateway === false ? '' : strtolower((string) $gateway), 'date' => (string) ($i['updated_at'] ?? $i['created_at']),
                'description' => 'Refund', 'amountout' => round((float) $i['refund'], 2), 'transid' => 'fossbilling-refund-' . $id, 'invoiceid' => $id,
            ]);
        }
    }

    /** @param array<string, mixed> $t a transaction with its invoice's client and gateway */
    private function payment(array $t): void
    {
        $invoice = (int) $t['invoice_id'];
        // An unpaid invoice's payment is still in the client's credit balance (client()).
        $status = $this->db->fetchOne('SELECT status FROM tblinvoices WHERE id = ?', [$invoice]);
        if ($t['status'] !== 'processed' || !in_array($status, ['Paid', 'Refunded'], true)) {
            return;
        }
        $this->db->insert('tblaccounts', [
            'userid' => (int) $t['client_id'], 'gateway' => strtolower((string) ($t['gateway_code'] ?? '')),
            'date' => (string) $t['created_at'], 'description' => 'Invoice Payment', 'amountin' => round((float) $t['amount'], 2),
            'transid' => trim((string) $t['txn_id']) ?: 'fossbilling-' . $t['id'], 'invoiceid' => $invoice,
        ]);
    }

    /** @param array<string, mixed> $t */
    private function ticket(array $t): void
    {
        $id = (int) $t['id'];
        $client = (int) $t['client_id'];
        $who = $this->db->fetchAssociative('SELECT firstname, lastname, email FROM tblclients WHERE id = ?', [$client]);
        if ($who === false) {
            $client = 0;
        }
        $name = $who ? trim($who['firstname'] . ' ' . $who['lastname']) : trim((string) $t['author_name']);
        $email = $who ? (string) $who['email'] : (string) $t['author_email'];
        $first = null;
        $last = (string) $t['created_at'];
        foreach ($this->fb->rows('SELECT m.*, a.name AS staff FROM support_ticket_message m LEFT JOIN admin a ON a.id = m.admin_id WHERE m.support_ticket_id = ? ORDER BY m.id', [$id]) as $m) {
            $staff = (int) $m['admin_id'] > 0 ? (trim((string) $m['staff']) ?: 'Staff') : '';
            $last = (string) $m['created_at'];
            if ($first === null) {
                $first = ['message' => (string) $m['content'], 'admin' => $staff];

                continue;
            }
            $this->db->insert('tblticketreplies', [
                'tid' => $id, 'userid' => $staff !== '' ? 0 : $client, 'name' => $staff !== '' ? '' : $name, 'email' => $staff !== '' ? '' : $email,
                'date' => (string) $m['created_at'], 'message' => (string) $m['content'], 'admin' => $staff,
            ]);
        }
        foreach ($this->fb->rows('SELECT n.*, a.name AS staff FROM support_ticket_note n LEFT JOIN admin a ON a.id = n.admin_id WHERE n.support_ticket_id = ? ORDER BY n.id', [$id]) as $n) {
            $this->db->insert('tblticketnotes', ['ticketid' => $id, 'admin' => trim((string) $n['staff']) ?: 'Staff', 'date' => (string) $n['created_at'], 'message' => (string) $n['note']]);
        }
        $order = $t['rel_type'] === 'order' ? (int) $t['rel_id'] : 0;
        $priority = (int) $t['priority'];
        $this->db->insert('tbltickets', [
            'id' => $id, 'status' => self::TICKET_STATUSES[(string) $t['status']] ?? 'Open', 'tid' => (string) $id, 'did' => (int) $t['support_helpdesk_id'], 'userid' => $client,
            'name' => $name, 'email' => $email, 'c' => bin2hex(random_bytes(4)), 'date' => (string) $t['created_at'], 'title' => (string) $t['subject'],
            'message' => (string) ($first['message'] ?? ''), 'admin' => (string) ($first['admin'] ?? ''),
            // FOSSBilling's priority is a number, 100 by default; a lower one comes first.
            'urgency' => $priority < 100 ? 'High' : ($priority > 100 ? 'Low' : 'Medium'), 'lastreply' => $last,
            'service' => $order === 0 ? '' : ($this->db->fetchOne('SELECT 1 FROM tbldomains WHERE id = ?', [$order]) !== false ? 'D' : 'S') . $order,
        ]);
    }

    /** After the last step: nothing more to read. */
    protected function finish(): void
    {
    }

    /** A date (Y-m-d) of a DATE or DATETIME value; '' for none or MySQL's zero date. */
    private static function day(mixed $value): string
    {
        $day = substr(trim((string) $value), 0, 10);

        return $day === '' || str_starts_with($day, '0000') ? '' : $day;
    }

    /** ClientFence bills a client in one currency, so an invoice in another is named. */
    private function billedIn(int $client, string $code, int $invoice): void
    {
        $currency = $this->db->fetchOne('SELECT cur.code FROM tblclients c JOIN tblcurrencies cur ON cur.id = c.currency WHERE c.id = ?', [$client]);
        if ($code !== '' && $currency !== false && $currency !== $code) {
            $this->warn(sprintf('Client #%d is billed in %s, but its invoice #%d is in %s in FOSSBilling: it is imported in %s.', $client, (string) $currency, $invoice, $code, (string) $currency));
        }
    }

    /** The WHMCS currency id of a code (FOSSBilling's currencies are read in setup). */
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
