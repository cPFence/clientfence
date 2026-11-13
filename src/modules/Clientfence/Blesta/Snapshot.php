<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Blesta;

use ClientFence\Config;
use ClientFence\InformationException;
use ClientFence\Mod\Clientfence\Whmcs\Snapshot as Base;
use ClientFence\Mod\Clientfence\Whmcs\Source;
use Doctrine\DBAL\Connection;

/**
 * ClientFence: the Blesta import. fetch() reads Blesta's database (read only, as the WHMCS import
 * reads WHMCS's: Whmcs\Source) into the import's snapshot (Whmcs/Snapshot.php), a page of rows at a
 * time, keeping Blesta's ids. Blesta's company 1 is read; ticket attachments are copied from
 * Blesta's support files folder into a private folder (files()) under the names the WHMCS step
 * reads.
 *
 * Passwords keep their hashes: Blesta hashes an HMAC of the password made with its system key, so
 * the client sign-in checks such a hash with the saved key once and then rehashes it
 * (Client\Service::authorizeClient, core patch). Left out with a warning: two-factor secrets, card
 * numbers (encrypted with Blesta's private key), custom client fields, package options, coupons,
 * recurring invoices, refunds, billing periods WHMCS has no name for (days, weeks, other terms).
 */
final class Snapshot extends Base
{
    public const NAME = 'Blesta';
    public const STATE = 'blesta_fetch';
    public const STEP = 'blesta';
    public const FILE = 'blesta-import.sqlite';
    /** Rows read per page. */
    public const PAGE = 200;

    public const STEPS = [
        'setup' => 'Currencies, taxes, groups and gateways',
        'packages' => 'Packages and domain prices',
        'clients' => 'Clients',
        'services' => 'Services and domains',
        'invoices' => 'Invoices',
        'payments' => 'Payments',
        'tickets' => 'Tickets',
    ];

    private const COMPANY = 1;

    private const SERVICE_STATUSES = ['active' => 'Active', 'suspended' => 'Suspended', 'canceled' => 'Cancelled', 'pending' => 'Pending', 'in_review' => 'Pending'];

    private const DOMAIN_STATUSES = ['active' => 'Active', 'suspended' => 'Active', 'canceled' => 'Cancelled', 'pending' => 'Pending', 'in_review' => 'Pending'];

    private const TICKET_STATUSES = ['open' => 'Open', 'awaiting_reply' => 'Answered', 'in_progress' => 'In Progress', 'on_hold' => 'On Hold', 'closed' => 'Closed', 'trash' => 'Closed'];

    private const URGENCY = ['emergency' => 'High', 'critical' => 'High', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];

    /**
     * Reads Blesta for up to $seconds (0: until done); $restart starts again from nothing. $settings
     * are the import settings: Blesta's database and its support files folder (attachments_dir);
     * tests hand in their own $source.
     *
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    public static function fetch(\Pimple\Container $di, array $settings, float $seconds = 0.0, bool $restart = false, ?Source $source = null): array
    {
        // A new read copies the attachments again.
        if ($restart || self::state($di) === null) {
            array_map('unlink', glob(self::files() . '/*') ?: []);
        }
        return self::read($di, static fn (Connection $db): self => new self($source ?? Source::mysql($di, $settings, 'Blesta'), (string) ($settings['attachments_dir'] ?? ''), $db), $seconds, $restart);
    }

    /** The private folder the attachments are copied to; the import reads them there. */
    public static function files(): string
    {
        return dirname(self::path()) . '/blesta-attachments';
    }

    /** Whether a database is Blesta's: its client, invoice and setting tables. */
    public static function isBlesta(Source $source): bool
    {
        return $source->hasTable('clients') && $source->hasTable('invoices') && $source->hasTable('company_settings') && $source->hasTable('pricings');
    }

    /** @var list<string>|null the languages a main text is taken in, first found first (texts()) */
    private ?array $languages = null;

    private function __construct(private readonly Source $blesta, private readonly string $supportFiles, Connection $db)
    {
        parent::__construct($db);
    }

    protected function close(): void
    {
        $this->blesta->close();
    }

    protected function page(string $step, int $offset): array
    {
        if ($step === 'setup') {
            return [null, $this->setup()];
        }
        $sql = [
            'packages' => 'SELECT * FROM packages WHERE company_id = ? AND id > ? ORDER BY id',
            'clients' => 'SELECT c.* FROM clients c JOIN client_groups g ON g.id = c.client_group_id WHERE g.company_id = ? AND c.id > ? ORDER BY c.id',
            'services' => 'SELECT s.*, pp.package_id, p.term, p.period, p.price, p.price_renews, p.currency FROM services s JOIN package_pricing pp ON pp.id = s.pricing_id JOIN pricings p ON p.id = pp.pricing_id WHERE p.company_id = ? AND s.id > ? ORDER BY s.id',
            'invoices' => 'SELECT i.* FROM invoices i JOIN clients c ON c.id = i.client_id JOIN client_groups g ON g.id = c.client_group_id WHERE g.company_id = ? AND i.id > ? ORDER BY i.id',
            'payments' => 'SELECT t.*, tt.name AS type_name, gw.class AS gateway FROM transactions t JOIN clients c ON c.id = t.client_id JOIN client_groups g ON g.id = c.client_group_id LEFT JOIN transaction_types tt ON tt.id = t.transaction_type_id LEFT JOIN gateways gw ON gw.id = t.gateway_id WHERE g.company_id = ? AND t.id > ? ORDER BY t.id',
            'tickets' => 'SELECT t.* FROM support_tickets t JOIN support_departments d ON d.id = t.department_id WHERE d.company_id = ? AND t.id > ? ORDER BY t.id',
        ][$step];
        if ($step === 'tickets' && !$this->blesta->hasTable('support_tickets')) {
            return [null, 0];
        }
        $rows = $this->blesta->rows($sql . ' LIMIT ' . self::PAGE, [self::COMPANY, $offset]);
        foreach ($rows as $row) {
            match ($step) {
                'packages' => $this->package($row),
                'clients' => $this->client($row),
                'services' => $this->service($row),
                'invoices' => $this->invoice($row),
                'payments' => $this->payment($row),
                default => $this->ticket($row),
            };
        }

        return [count($rows) < self::PAGE ? null : (int) end($rows)['id'], count($rows)];
    }

    /** A company setting, else the system one. */
    private function setting(string $key): string
    {
        $value = $this->blesta->value('SELECT value FROM company_settings WHERE company_id = ? AND `key` = ?', [self::COMPANY, $key]);
        if ($value === false) {
            $value = $this->blesta->value('SELECT value FROM settings WHERE `key` = ?', [$key]);
        }

        return $value === false ? '' : (string) $value;
    }

    private function setup(): int
    {
        if ((int) $this->blesta->value('SELECT COUNT(*) FROM companies') > 1) {
            $this->warn('Blesta has more than one company: only the first one was read.');
        }
        $this->db->insert('tblconfiguration', ['setting' => 'Version', 'value' => '8.0.0 (Blesta ' . ($this->setting('database_version') ?: '?') . ')']);
        $this->db->insert('tblconfiguration', ['setting' => 'SequentialInvoiceNumbering', 'value' => 'on']);
        // Blesta's {num} is WHMCS's {NUMBER}; another tag ({year}...) leaves the format to the invoices read.
        $format = $this->setting('inv_format');
        if (str_contains($format, '{num}') && preg_match('/\{(?!num\})/', $format) !== 1) {
            $this->db->insert('tblconfiguration', ['setting' => 'SequentialInvoiceNumberFormat', 'value' => str_replace('{num}', '{NUMBER}', $format)]);
        }
        $default = $this->setting('default_currency') ?: 'USD';
        foreach ($this->blesta->rows('SELECT * FROM currencies WHERE company_id = ? ORDER BY code', [self::COMPANY]) as $c) {
            $this->db->insert('tblcurrencies', [
                'code' => (string) $c['code'], 'prefix' => (string) $c['prefix'], 'suffix' => (string) $c['suffix'],
                'format' => (int) $c['precision'] === 0 ? 4 : 2, 'rate' => (float) $c['exchange_rate'] ?: 1, '"default"' => $c['code'] === $default ? 1 : 0,
            ]);
        }
        $inclusive = 0;
        foreach ($this->blesta->rows("SELECT * FROM taxes WHERE company_id = ? AND status = 'active' ORDER BY level, id", [self::COMPANY]) as $t) {
            $this->db->insert('tbltax', ['id' => (int) $t['id'], 'level' => (string) $t['level'], 'name' => (string) $t['name'], 'state' => (string) ($t['state'] ?? ''), 'country' => (string) ($t['country'] ?? ''), 'taxrate' => (float) $t['amount']]);
            // "Inclusive" in Blesta is inclusive_calculated; its "Inclusive (Additive)" adds the tax to the price.
            $inclusive += $t['type'] === 'inclusive_calculated' ? 1 : 0;
        }
        $taxes = (int) $this->db->fetchOne('SELECT COUNT(*) FROM tbltax');
        if ($inclusive > 0 && $inclusive < $taxes) {
            $this->warn('Blesta has taxes added to prices and taxes included in them: ClientFence does one or the other, so check Tax Settings under System > Tax Rules.');
        }
        if ($this->setting('enable_tax') === 'true') {
            $this->db->insert('tblconfiguration', ['setting' => 'TaxEnabled', 'value' => 'on']);
            $this->db->insert('tblconfiguration', ['setting' => 'TaxType', 'value' => $inclusive === $taxes && $taxes > 0 ? 'Inclusive' : 'Exclusive']);
            $untaxedTlds = $this->blesta->hasTable('domains_tlds') ? (int) $this->blesta->value('SELECT COUNT(*) FROM domains_tlds t JOIN packages p ON p.id = t.package_id WHERE t.company_id = ? AND p.taxable = 0', [self::COMPANY]) : 0;
            $this->db->insert('tblconfiguration', ['setting' => 'TaxDomains', 'value' => $untaxedTlds === 0 ? 'on' : '']);
        }
        foreach ($this->blesta->rows('SELECT * FROM client_groups WHERE company_id = ? ORDER BY id', [self::COMPANY]) as $g) {
            $this->db->insert('tblclientgroups', ['id' => (int) $g['id'], 'groupname' => (string) $g['name'], 'groupcolour' => '#' . ltrim((string) $g['color'], '#'), 'discountpercent' => 0, 'susptermexempt' => '', 'separateinvoices' => '']);
        }
        foreach ($this->blesta->rows('SELECT * FROM gateways WHERE company_id = ?', [self::COMPANY]) as $g) {
            $this->db->insert('tblpaymentgateways', ['gateway' => strtolower((string) $g['class']), 'setting' => 'name', 'value' => (string) $g['name']]);
        }
        // Product groups, but not the Domains plugin's own group of TLD packages.
        $tlds = $this->blesta->hasTable('domains_tlds') ? 'AND NOT EXISTS (SELECT 1 FROM package_group pg JOIN domains_tlds t ON t.package_id = pg.package_id WHERE pg.package_group_id = g.id)' : '';
        foreach ($this->blesta->rows("SELECT g.* FROM package_groups g WHERE g.company_id = ? AND g.type = 'standard' {$tlds} ORDER BY g.id", [self::COMPANY]) as $i => $g) {
            [$name, $names] = $this->texts('SELECT lang, name AS text FROM package_group_names WHERE package_group_id = ?', (int) $g['id']);
            $this->db->insert('tblproductgroups', ['id' => (int) $g['id'], 'name' => $name ?: 'Products', 'slug' => '', 'hidden' => (int) $g['hidden'], '"order"' => $i]);
            $this->translate('product_group', (int) $g['id'], 'name', $names);
        }
        if ($this->blesta->hasTable('support_departments')) {
            foreach ($this->blesta->rows('SELECT * FROM support_departments WHERE company_id = ?', [self::COMPANY]) as $d) {
                $this->db->insert('tblticketdepartments', ['id' => (int) $d['id'], 'name' => (string) $d['name'], 'description' => (string) ($d['description'] ?? ''), 'email' => (string) ($d['email'] ?? ''), 'hidden' => $d['status'] === 'hidden' ? 'on' : '']);
            }
        }
        // A template per language: ClientFence's own are translated (WHMCS's and Blesta's tags do not convert safely).
        $translated = $this->blesta->hasTable('emails') ? (int) $this->blesta->value('SELECT COUNT(*) - COUNT(DISTINCT email_group_id) FROM emails WHERE company_id = ?', [self::COMPANY]) : 0;
        if ($translated > 0) {
            $this->warn(sprintf('%d translated email %s in Blesta %s not imported; ClientFence sends its own translated templates.', $translated, $translated === 1 ? 'template' : 'templates', $translated === 1 ? 'was' : 'were'));
        }
        foreach (['coupons' => 'coupons', 'invoices_recur' => 'recurring invoices', 'package_options' => 'package options', 'client_fields' => 'custom client fields'] as $table => $what) {
            $count = $this->blesta->hasTable($table) ? $this->blesta->count($table) : 0;
            if ($count > 0) {
                $this->warn(sprintf('Blesta has %d %s: they were not imported.', $count, $what));
            }
        }

        return 1;
    }

    /** @param array<string, mixed> $p */
    private function package(array $p): void
    {
        $id = (int) $p['id'];
        [$name, $names] = $this->texts('SELECT lang, name AS text FROM package_names WHERE package_id = ?', $id);
        $pricing = $this->blesta->rows('SELECT p.* FROM package_pricing pp JOIN pricings p ON p.id = pp.pricing_id WHERE pp.package_id = ? ORDER BY p.currency, p.period, p.term', [$id]);
        $tld = $this->blesta->hasTable('domains_tlds') ? $this->blesta->value('SELECT tld FROM domains_tlds WHERE package_id = ?', [$id]) : false;
        if ($tld !== false) {
            // A domain extension: its prices by years.
            $this->db->insert('tbldomainpricing', ['id' => $id, 'extension' => '.' . ltrim(strtolower((string) $tld), '.')]);
            $rows = [];
            foreach ($pricing as $price) {
                $years = $price['period'] === 'year' ? (int) $price['term'] : 0;
                if (!isset(self::DOMAIN_COLUMNS[$years])) {
                    continue;
                }
                $column = self::DOMAIN_COLUMNS[$years];
                $currency = $this->currency((string) $price['currency']);
                $rows['domainregister'][$currency][$column] = (float) $price['price'];
                $rows['domainrenew'][$currency][$column] = (float) ($price['price_renews'] ?? $price['price']);
                if ($years === 1 && $price['price_transfer'] !== null) {
                    $rows['domaintransfer'][$currency][$column] = (float) $price['price_transfer'];
                }
            }
            foreach ($rows as $type => $byCurrency) {
                foreach ($byCurrency as $currency => $columns) {
                    $this->db->insert('tblpricing', ['type' => $type, 'currency' => $currency, 'relid' => $id] + $columns);
                }
            }

            return;
        }
        $group = $this->blesta->value('SELECT package_group_id FROM package_group WHERE package_id = ? ORDER BY `order` LIMIT 1', [$id]);
        [$description, $descriptions] = $this->texts('SELECT lang, text FROM package_descriptions WHERE package_id = ?', $id);
        $onetime = $pricing !== [] && array_filter($pricing, static fn (array $x): bool => $x['period'] !== 'onetime') === [];
        // A package in an add-on group is a WHMCS add-on of the packages in the group's parent groups.
        $parents = $this->blesta->hasTable('package_group_parents') ? $this->blesta->rows(
            "SELECT DISTINCT pg.package_id FROM package_group a JOIN package_groups g ON g.id = a.package_group_id AND g.type = 'addon' JOIN package_group_parents gp ON gp.group_id = a.package_group_id JOIN package_group pg ON pg.package_group_id = gp.parent_group_id WHERE a.package_id = ? ORDER BY pg.package_id",
            [$id],
        ) : [];
        $kind = $parents === [] ? 'product' : 'addon';
        if ($kind === 'addon') {
            $this->db->insert('tbladdons', [
                'id' => $id, 'packages' => implode(',', array_column($parents, 'package_id')), 'name' => $name !== '' ? $name : 'Package #' . $id,
                'description' => $description, 'billingcycle' => $onetime ? 'onetime' : 'recurring', 'allowqty' => 0, 'showorder' => 1,
                'hidden' => (int) $p['hidden'], 'retired' => $p['status'] === 'inactive' ? 1 : 0, 'autoactivate' => '', 'module' => '', 'weight' => 0, 'tax' => (int) $p['taxable'],
            ]);
        } else {
            $this->db->insert('tblproducts', [
                'id' => $id, 'type' => 'other', 'gid' => $group === false ? 0 : (int) $group, 'name' => $name !== '' ? $name : 'Package #' . $id,
                'description' => $description,
                'hidden' => (int) $p['hidden'], 'stockcontrol' => $p['qty'] !== null ? 1 : 0, 'qty' => (int) ($p['qty'] ?? 0), 'paytype' => $onetime ? 'onetime' : 'recurring',
                'retired' => $p['status'] === 'inactive' ? 1 : 0, 'tax' => (int) $p['taxable'],
            ]);
        }
        $this->translate('product' . ($kind === 'addon' ? '_addon' : ''), $id, 'name', $names);
        $this->translate('product' . ($kind === 'addon' ? '_addon' : ''), $id, 'description', $descriptions);
        $rows = [];
        $unbilled = [];
        foreach ($pricing as $price) {
            $months = self::months((string) $price['period'], (int) $price['term']);
            $currency = $this->currency((string) $price['currency']);
            $rows[$currency] ??= ['type' => $kind, 'currency' => $currency, 'relid' => $id, 'msetupfee' => 0, 'qsetupfee' => 0, 'ssetupfee' => 0, 'asetupfee' => 0, 'bsetupfee' => 0, 'tsetupfee' => 0];
            if ($months === 0 && !$onetime) {
                $unbilled['onetime'] = sprintf('Package "%s": its one-time price was not imported (the package also renews, and ClientFence bills a product either once or on renewal).', $name);

                continue;
            }
            if ($months === null) {
                // Once per term, not once per currency it is priced in.
                $unbilled[$price['term'] . '-' . $price['period']] = sprintf('Package "%s": its %d-%s price was not imported (ClientFence bills 1, 3, 6, 12, 24 or 36 months, or once).', $name, (int) $price['term'], (string) $price['period']);

                continue;
            }
            $column = $months === 0 ? 'monthly' : self::PRICE_COLUMNS[$months];
            $rows[$currency][$column] = (float) $price['price'];
            $rows[$currency][['monthly' => 'msetupfee', 'quarterly' => 'qsetupfee', 'semiannually' => 'ssetupfee', 'annually' => 'asetupfee', 'biennially' => 'bsetupfee', 'triennially' => 'tsetupfee'][$column]] = (float) $price['setup_fee'];
        }
        foreach ($rows as $row) {
            $this->db->insert('tblpricing', $row);
        }
        array_map($this->warn(...), array_values($unbilled));
    }

    /**
     * An item's text in its main language, and its texts in the others by language: the main one is
     * ClientFence's default language when Blesta has it, else Blesta's default language, else
     * en_us, else the first.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function texts(string $sql, int $id): array
    {
        $byLang = [];
        foreach ($this->blesta->rows($sql . ' ORDER BY lang', [$id]) as $row) {
            $byLang[strtolower((string) $row['lang'])] = (string) ($row['text'] ?? '');
        }
        $this->languages ??= array_values(array_unique(array_filter([strtolower((string) Config::getProperty('i18n.locale', 'en_US')), strtolower($this->setting('language')), 'en_us'])));
        $main = array_values(array_intersect($this->languages, array_keys($byLang)))[0] ?? array_key_first($byLang);
        if ($main === null) {
            return ['', []];
        }
        $text = $byLang[$main];
        unset($byLang[$main]);

        return [$text, $byLang];
    }

    /**
     * Texts of other languages as WHMCS's Dynamic Field Translation rows, which the catalogue step
     * imports (Whmcs/Catalogue.php).
     *
     * @param array<string, string> $byLang
     */
    private function translate(string $type, int $id, string $field, array $byLang): void
    {
        foreach ($byLang as $lang => $text) {
            if (trim($text) !== '') {
                $this->db->insert('tbldynamic_translations', ['related_type' => $type . '.{id}.' . $field, 'related_id' => $id, 'language' => $lang, 'translation' => $text]);
            }
        }
    }

    /** A Blesta term in months as WHMCS bills it: 0 for one time, null when WHMCS has no name for it. */
    private static function months(string $period, int $term): ?int
    {
        $months = match ($period) {
            'onetime' => 0,
            'month' => $term,
            'year' => $term * 12,
            default => -1,
        };

        return $months === 0 || isset(self::PRICE_COLUMNS[$months]) ? $months : null;
    }

    /** @param array<string, mixed> $c */
    private function client(array $c): void
    {
        $id = (int) $c['id'];
        $contact = $this->blesta->rows("SELECT * FROM contacts WHERE client_id = ? AND contact_type = 'primary' LIMIT 1", [$id])[0] ?? [];
        $user = $this->blesta->rows('SELECT * FROM users WHERE id = ?', [(int) $c['user_id']])[0] ?? [];
        $settings = [];
        foreach ($this->blesta->rows('SELECT `key`, value, encrypted FROM client_settings WHERE client_id = ?', [$id]) as $s) {
            $settings[(string) $s['key']] = (int) $s['encrypted'] === 1 ? '' : (string) $s['value'];
        }
        $email = trim((string) ($contact['email'] ?? ''));
        $currency = $settings['default_currency'] ?? '' ?: ($this->setting('default_currency') ?: 'USD');
        $created = (string) (($contact['date_added'] ?? null) ?: ($user['date_added'] ?? null) ?: date('Y-m-d H:i:s'));
        $this->db->insert('tblclients', [
            'id' => $id, 'firstname' => (string) ($contact['first_name'] ?? ''), 'lastname' => (string) ($contact['last_name'] ?? ''),
            'companyname' => (string) ($contact['company'] ?? ''), 'email' => $email, 'address1' => (string) ($contact['address1'] ?? ''),
            'address2' => (string) ($contact['address2'] ?? ''), 'city' => (string) ($contact['city'] ?? ''), 'state' => (string) ($contact['state'] ?? ''),
            'postcode' => (string) ($contact['zip'] ?? ''), 'country' => (string) ($contact['country'] ?? ''),
            'phonenumber' => (string) ($this->blesta->value("SELECT number FROM contact_numbers WHERE contact_id = ? ORDER BY type = 'phone' DESC, id LIMIT 1", [(int) ($contact['id'] ?? 0)]) ?: ''),
            'tax_id' => $settings['tax_id'] ?? '', 'password' => (string) ($user['password'] ?? ''), 'currency' => $this->currency($currency),
            'taxexempt' => ($settings['tax_exempt'] ?? '') === 'true' ? 1 : 0, 'datecreated' => substr($created, 0, 10), 'created_at' => $created,
            // Blesta's language code (bg_bg): the WHMCS import maps it to a locale this install ships (Accounts::locale).
            'language' => $settings['language'] ?? '',
            'groupid' => (int) $c['client_group_id'], 'status' => ['active' => 'Active', 'inactive' => 'Inactive', 'fraud' => 'Closed'][$c['status']] ?? 'Active',
        ]);
        if ($c['status'] === 'fraud') {
            $this->warn(sprintf('Client #%d is marked as fraud in Blesta: it was imported as Closed.', $id));
        }
        if ($user !== []) {
            // Blesta's hash of an HMAC of the password: the sign-in checks it with the saved system key, then rehashes it.
            $this->db->insert('tblusers', ['id' => $id, 'first_name' => (string) ($contact['first_name'] ?? ''), 'last_name' => (string) ($contact['last_name'] ?? ''), 'email' => $email, 'password' => (string) $user['password'], 'created_at' => $created]);
            $this->db->insert('tblusers_clients', ['auth_user_id' => $id, 'client_id' => $id, 'owner' => 1, 'created_at' => $created]);
            if (($user['two_factor_mode'] ?? 'none') !== 'none') {
                $this->warn(sprintf('Client %s used two-factor sign-in in Blesta, which could not be carried over: it signs in with its password until two-factor is set up again.', $email));
            }
        }
        foreach ($this->blesta->rows("SELECT * FROM contacts WHERE client_id = ? AND contact_type <> 'primary' ORDER BY id", [$id]) as $other) {
            $this->db->insert('tblcontacts', [
                'id' => (int) $other['id'], 'userid' => $id, 'firstname' => (string) $other['first_name'], 'lastname' => (string) $other['last_name'],
                'companyname' => (string) ($other['company'] ?? ''), 'email' => (string) $other['email'], 'address1' => (string) ($other['address1'] ?? ''),
                'address2' => (string) ($other['address2'] ?? ''), 'city' => (string) ($other['city'] ?? ''), 'state' => (string) ($other['state'] ?? ''),
                'postcode' => (string) ($other['zip'] ?? ''), 'country' => (string) ($other['country'] ?? ''), 'phonenumber' => '', 'tax_id' => '',
                'subaccount' => 0, 'password' => '', 'permissions' => '', 'generalemails' => 0, 'invoiceemails' => $other['contact_type'] === 'billing' ? 1 : 0,
                'productemails' => 0, 'domainemails' => 0, 'supportemails' => 0, 'affiliateemails' => 0, 'created_at' => (string) ($other['date_added'] ?? $created),
            ]);
            if (!empty($other['user_id'])) {
                $this->warn(sprintf('Contact %s of client #%d could sign in to Blesta: it was imported as a contact without a login.', (string) $other['email'], $id));
            }
        }
        foreach ($this->blesta->rows('SELECT * FROM client_notes WHERE client_id = ? ORDER BY id', [$id]) as $note) {
            if ((int) $note['encrypted'] === 1) {
                $this->warn(sprintf('A note of client #%d is encrypted in Blesta: it was not imported.', $id));

                continue;
            }
            $this->db->insert('tblnotes', [
                'userid' => $id, 'adminid' => 0, 'created' => (string) $note['date_added'], 'modified' => (string) ($note['date_updated'] ?? $note['date_added']),
                'note' => trim((string) $note['title'] . "\n\n" . (string) $note['description']), 'sticky' => (int) $note['stickied'],
            ]);
        }
    }

    /** @param array<string, mixed> $s a service with its pricing */
    private function service(array $s): void
    {
        $id = (int) $s['id'];
        $client = (int) $s['client_id'];
        $status = (string) $s['status'];
        $qty = max(1, (int) $s['qty']);
        $price = $s['override_price'] !== null ? (float) $s['override_price'] : (float) ($s['price_renews'] ?? $s['price']);
        $amount = round($price * $qty, 2);
        $currency = (string) (($s['override_currency'] ?? null) ?: $s['currency']);
        $this->billedIn($client, $currency, sprintf('service #%d', $id));
        $start = substr((string) $s['date_added'], 0, 10);
        $due = substr((string) ($s['date_renews'] ?? ''), 0, 10);
        $cancels = !empty($s['date_canceled']) ? substr((string) $s['date_canceled'], 0, 10) : '';
        $tld = $this->blesta->hasTable('domains_tlds') ? $this->blesta->value('SELECT tld FROM domains_tlds WHERE package_id = ?', [(int) $s['package_id']]) : false;
        if ($tld !== false) {
            $domain = $this->blesta->value("SELECT value FROM service_fields WHERE service_id = ? AND encrypted = 0 AND `key` IN ('domain', 'domain-name', 'domain_name') LIMIT 1", [$id]);
            $dates = $this->blesta->hasTable('domains_domains') ? ($this->blesta->rows('SELECT registration_date, expiration_date FROM domains_domains WHERE service_id = ?', [$id])[0] ?? []) : [];
            $this->db->insert('tbldomains', [
                'id' => $id, 'userid' => $client, 'registrationdate' => substr((string) (($dates['registration_date'] ?? null) ?: $start), 0, 10),
                'domain' => strtolower(trim((string) $domain)), 'firstpaymentamount' => $amount, 'recurringamount' => $amount,
                'registrationperiod' => $s['period'] === 'year' ? max(1, (int) $s['term']) : 1,
                'expirydate' => substr((string) (($dates['expiration_date'] ?? null) ?: ($due ?: $start)), 0, 10), 'nextduedate' => $due ?: $start,
                'status' => self::DOMAIN_STATUSES[$status] ?? 'Pending', 'donotrenew' => $cancels !== '' ? 1 : 0,
            ]);
            if ($domain === false || trim((string) $domain) === '') {
                $this->warn(sprintf('Domain service #%d of client #%d has no domain name in Blesta.', $id, $client));
            }

            return;
        }
        $months = self::months((string) $s['period'], (int) $s['term']);
        if ($months === null) {
            $this->warn(sprintf('Service #%d of client #%d was not imported: it renews every %d %s(s), which ClientFence does not bill.', $id, $client, (int) $s['term'], (string) $s['period']));

            return;
        }
        $cycle = $months === 0 ? 'One Time' : self::CYCLES[$months];
        $terminated = $status === 'canceled' && $cancels !== '' ? $cancels : null;
        if ((int) ($s['parent_service_id'] ?? 0) > 0 && $this->db->fetchOne('SELECT id FROM tbladdons WHERE id = ?', [(int) $s['package_id']]) !== false) {
            // An add-on service: the WHMCS step links it to its parent service.
            $this->db->insert('tblhostingaddons', [
                'id' => $id, 'orderid' => 0, 'hostingid' => (int) $s['parent_service_id'], 'addonid' => (int) $s['package_id'], 'userid' => $client,
                'name' => '', 'qty' => $qty, 'recurring' => $amount, 'billingcycle' => $cycle, 'status' => self::SERVICE_STATUSES[$status] ?? 'Pending',
                'regdate' => $start, 'nextduedate' => $due ?: $start, 'termination_date' => $terminated, 'notes' => '', 'subscriptionid' => '',
            ]);
            if ($status !== 'canceled' && $cancels !== '') {
                $this->warn(sprintf('Add-on service #%d of client #%d is set to cancel on %s in Blesta: cancel it again in ClientFence.', $id, $client, $cancels));
            }

            return;
        }
        $this->db->insert('tblhosting', [
            'id' => $id, 'userid' => $client, 'packageid' => (int) $s['package_id'], 'regdate' => $start,
            'domain' => (string) ($this->blesta->value("SELECT value FROM service_fields WHERE service_id = ? AND encrypted = 0 AND `key` LIKE '%domain%' LIMIT 1", [$id]) ?: ''),
            'qty' => $qty, 'firstpaymentamount' => $amount, 'amount' => $amount, 'billingcycle' => $cycle,
            'nextduedate' => $due ?: $start, 'termination_date' => $terminated,
            'domainstatus' => self::SERVICE_STATUSES[$status] ?? 'Pending', 'suspendreason' => (string) ($s['suspension_reason'] ?? ''),
        ]);
        // A cancellation set for a later date: the WHMCS step puts it in Cancellation Requests, so it does not renew.
        if ($status !== 'canceled' && $cancels !== '') {
            $this->db->insert('tblcancelrequests', ['date' => (string) $s['date_canceled'], 'relid' => $id, 'reason' => (string) ($s['cancellation_reason'] ?? '') ?: 'Cancellation scheduled in Blesta', 'type' => 'End of Billing Period']);
        }
    }

    /** @param array<string, mixed> $i */
    private function invoice(array $i): void
    {
        $id = (int) $i['id'];
        $client = (int) $i['client_id'];
        $this->billedIn($client, (string) $i['currency'], sprintf('invoice #%d', $id));
        $lines = $this->blesta->rows('SELECT l.*, (SELECT COUNT(*) FROM invoice_line_taxes t WHERE t.line_id = l.id) AS taxes FROM invoice_lines l WHERE l.invoice_id = ? ORDER BY l.`order`, l.id', [$id]);
        $subtotal = (float) $i['subtotal'];
        $total = (float) $i['total'];
        $tax = round(max(0, $total - $subtotal), 2);
        // Blesta's subtotal leaves out every tax, also one inside the prices: the rate is the rule's, or for two
        // rules the one that gives Blesta's tax on the net of the taxed lines.
        $rules = $this->blesta->rows('SELECT DISTINCT t.amount, t.type FROM invoice_line_taxes lt JOIN invoice_lines l ON l.id = lt.line_id JOIN taxes t ON t.id = lt.tax_id WHERE l.invoice_id = ?', [$id]);
        $taxed = array_sum(array_map(static fn (array $l): float => (int) $l['taxes'] > 0 ? (float) $l['qty'] * (float) $l['amount'] : 0.0, $lines));
        $taxedNet = in_array('inclusive_calculated', array_column($rules, 'type'), true) ? $taxed - $tax : $taxed;
        $rate = match (true) {
            $tax <= 0 => 0.0,
            count($rules) === 1 => (float) $rules[0]['amount'],
            default => $taxedNet > 0 ? round($tax / $taxedNet * 100, 4) : 0.0,
        };
        $paid = (float) $i['paid'] >= $total - 0.005;
        $status = match (true) {
            $i['status'] === 'void' => 'Cancelled',
            $i['status'] === 'draft' => 'Draft',
            $paid => 'Paid',
            default => 'Unpaid',
        };
        $this->db->insert('tblinvoices', [
            'id' => $id, 'userid' => $client, 'invoicenum' => str_replace('{num}', (string) $i['id_value'], (string) $i['id_format']),
            'date' => substr((string) $i['date_billed'], 0, 10), 'duedate' => substr((string) $i['date_due'], 0, 10),
            'datepaid' => $status === 'Paid' ? (string) ($i['date_closed'] ?? $i['date_billed']) : '0000-00-00 00:00:00',
            'subtotal' => $subtotal, 'tax' => $tax, 'total' => $total, 'taxrate' => $rate,
            'status' => $status, 'notes' => (string) ($i['note_private'] ?? ''),
        ]);
        $renews = [];
        foreach ($lines as $line) {
            $service = (int) ($line['service_id'] ?? 0);
            // Paying the item renews its service: only its first line on the invoice (setup fees, options and
            // coupons share the service's id).
            $type = match (true) {
                $service === 0 || isset($renews[$service]) => '',
                $this->db->fetchOne('SELECT 1 FROM tbldomains WHERE id = ?', [$service]) !== false => 'Domain',
                $this->db->fetchOne('SELECT 1 FROM tblhostingaddons WHERE id = ?', [$service]) !== false => 'Addon',
                default => 'Hosting',
            };
            $renews[$service] = true;
            $qty = (float) $line['qty'];
            $this->db->insert('tblinvoiceitems', [
                'invoiceid' => $id, 'userid' => $client, 'type' => $type, 'relid' => $type === '' ? 0 : $service,
                'description' => ($qty != 1.0 ? rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.') . ' x ' : '') . (string) $line['description'],
                'amount' => round($qty * (float) $line['amount'], 2), 'taxed' => (int) $line['taxes'] > 0 ? 1 : 0,
            ]);
        }
    }

    /** @param array<string, mixed> $t a transaction: what it paid on imported invoices; the rest is the client's credit */
    private function payment(array $t): void
    {
        if ($t['status'] !== 'approved') {
            if (in_array($t['status'], ['refunded', 'returned'], true)) {
                $this->warn(sprintf('Payment #%d of client #%d was %s in Blesta: it was not imported.', (int) $t['id'], (int) $t['client_id'], (string) $t['status']));
            }

            return;
        }
        $client = (int) $t['client_id'];
        $gateway = strtolower((string) (($t['gateway'] ?? null) ?: ($t['type_name'] ?? null) ?: $t['type']));
        $applied = 0.0;
        foreach ($this->blesta->rows('SELECT * FROM transaction_applied WHERE transaction_id = ? ORDER BY date', [(int) $t['id']]) as $a) {
            if ($this->db->fetchOne('SELECT 1 FROM tblinvoices WHERE id = ?', [(int) $a['invoice_id']]) === false) {
                continue;
            }
            $amount = round((float) $a['amount'], 2);
            $applied += $amount;
            $this->db->insert('tblaccounts', [
                'userid' => $client, 'gateway' => $gateway, 'date' => (string) (($a['date'] ?? null) ?: $t['date_added']), 'description' => 'Invoice Payment',
                'amountin' => $amount, 'transid' => (string) (($t['transaction_id'] ?? null) ?: 'blesta-' . $t['id']), 'invoiceid' => (int) $a['invoice_id'],
            ]);
        }
        // What was not applied stays with the client as credit (Blesta's credit balance).
        $left = round((float) $t['amount'] - $applied, 2);
        if ($left > 0) {
            $this->billedIn($client, (string) $t['currency'], sprintf('credit of payment #%d', (int) $t['id']));
            $this->db->executeStatement('UPDATE tblclients SET credit = credit + ? WHERE id = ?', [$left, $client]);
            $this->db->insert('tblcredit', ['clientid' => $client, 'date' => substr((string) $t['date_added'], 0, 10), 'description' => sprintf('Credit from Blesta payment #%d', (int) $t['id']), 'amount' => $left, 'relid' => 0]);
        }
    }

    /** @param array<string, mixed> $t */
    private function ticket(array $t): void
    {
        $id = (int) $t['id'];
        $client = (int) ($t['client_id'] ?? 0);
        $who = $client > 0 ? $this->db->fetchAssociative('SELECT firstname, lastname, email FROM tblclients WHERE id = ?', [$client]) : false;
        $name = $who ? trim($who['firstname'] . ' ' . $who['lastname']) : '';
        $email = $who ? (string) $who['email'] : (string) ($t['email'] ?? '');
        $first = null;
        foreach ($this->blesta->rows("SELECT r.*, s.first_name, s.last_name FROM support_replies r LEFT JOIN staff s ON s.id = r.staff_id WHERE r.ticket_id = ? AND r.type <> 'log' ORDER BY r.date_added, r.id", [$id]) as $r) {
            $staff = !empty($r['staff_id']) ? (trim($r['first_name'] . ' ' . $r['last_name']) ?: 'Staff') : '';
            $files = $this->attachments((int) $r['id']);
            if ($r['type'] === 'note') {
                $this->db->insert('tblticketnotes', ['ticketid' => $id, 'admin' => $staff ?: 'Staff', 'date' => (string) $r['date_added'], 'message' => (string) $r['details'], 'attachments' => $files]);
            } elseif ($first === null) {
                $first = ['message' => (string) $r['details'], 'admin' => $staff, 'attachment' => $files];
            } else {
                $this->db->insert('tblticketreplies', [
                    'tid' => $id, 'userid' => $staff !== '' ? 0 : $client, 'name' => $staff !== '' ? '' : $name, 'email' => $staff !== '' ? '' : $email,
                    'date' => (string) $r['date_added'], 'message' => (string) $r['details'], 'admin' => $staff, 'attachment' => $files,
                ]);
            }
        }
        $this->db->insert('tbltickets', [
            'id' => $id, 'status' => self::TICKET_STATUSES[$t['status']] ?? 'Open', 'tid' => (string) $t['code'], 'did' => (int) $t['department_id'],
            'userid' => $client, 'name' => $name, 'email' => $email, 'c' => bin2hex(random_bytes(4)), 'date' => (string) $t['date_added'],
            'title' => (string) $t['summary'], 'message' => (string) ($first['message'] ?? ''), 'urgency' => self::URGENCY[$t['priority']] ?? 'Medium',
            'admin' => (string) ($first['admin'] ?? ''), 'attachment' => (string) ($first['attachment'] ?? ''),
            'lastreply' => (string) (($t['date_updated'] ?? null) ?: $t['date_added']), 'service' => !empty($t['service_id']) ? 'S' . (int) $t['service_id'] : '',
        ]);
    }

    /**
     * A reply's attachments, copied into files() as "<id>_<name>" (the names the WHMCS step reads):
     * the names joined by "|", or '' when it has none.
     */
    private function attachments(int $reply): string
    {
        if (!$this->blesta->hasTable('support_attachments')) {
            return '';
        }
        $names = [];
        foreach ($this->blesta->rows('SELECT * FROM support_attachments WHERE reply_id = ? ORDER BY id', [$reply]) as $a) {
            $stored = (int) $a['id'] . '_' . str_replace(['/', '\\', '|'], '_', (string) $a['name']);
            $names[] = $stored;
            // Only from the support folder staff set: a path from the source database could name any file here.
            $from = $this->supportFiles !== '' ? rtrim($this->supportFiles, '/') . '/' . basename((string) $a['file_name']) : '';
            if ($from === '' || !is_file($from) || !is_readable($from)) {
                // The WHMCS step shows it as removed and names it in a warning.
                continue;
            }
            if (!is_dir(self::files()) && !@mkdir(self::files(), 0700, true) && !is_dir(self::files())) {
                throw new InformationException('Could not make the folder :dir.', [':dir' => self::files()]);
            }
            if (!@copy($from, self::files() . '/' . $stored)) {
                throw new InformationException('Could not copy the Blesta attachment :file.', [':file' => $from]);
            }
            chmod(self::files() . '/' . $stored, 0600);
        }

        return implode('|', $names);
    }

    /** After the last step: nothing more to read (credit was added payment by payment). */
    protected function finish(): void
    {
    }

    /**
     * A client's currency is its default one in Blesta; ClientFence bills a client in one currency,
     * so a service, invoice or credit in another is named.
     */
    private function billedIn(int $client, string $code, string $what): void
    {
        $currency = $this->db->fetchOne('SELECT cur.code FROM tblclients c JOIN tblcurrencies cur ON cur.id = c.currency WHERE c.id = ?', [$client]);
        if ($code !== '' && $currency !== false && $currency !== $code) {
            $this->warn(sprintf('Client #%d is billed in %s, but its %s is in %s in Blesta: it is imported in %s.', $client, (string) $currency, $what, $code, (string) $currency));
        }
    }

    /** The WHMCS currency id of a code (Blesta's currencies are read in setup). */
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
