<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Upmind;

use ClientFence\InformationException;
use ClientFence\Mod\Clientfence\Whmcs\Map;
use ClientFence\Mod\Clientfence\Whmcs\Run;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * ClientFence: the Upmind import. Upmind is hosted, so there is no database to read: fetch() reads
 * Upmind's admin API (Api.php) into a private SQLite file laid out as WHMCS's tables, and the WHMCS
 * import (Whmcs/Run.php) imports that file as it imports a WHMCS database (Whmcs\Source::open()).
 *
 * fetch() runs a page at a time for as long as it is given, keeping its place in
 * mod_clientfence_state "upmind_fetch", so the admin tab can call it again and again and a stop
 * loses nothing. Upmind's UUIDs become numbers in the order they are read (table upmind_id).
 *
 * Not in Upmind's API, so not imported: passwords (clients use "Forgot password"), two-factor
 * secrets, saved card tokens, ticket attachments. Left out on purpose: invoices with a zero total
 * (Upmind makes one for each service it imported), payments that pay no imported invoice (the
 * client's credit balance carries them), domain transfer prices, billing cycles WHMCS has no name
 * for (a warning names each).
 */
final class Snapshot
{
    public const STATE = 'upmind_fetch';
    /** Rows read per page: fewer where each row needs more calls (a client four), so a page fits in a few seconds. */
    public const PAGES = ['products' => 50, 'clients' => 5, 'services' => 50, 'invoices' => 10, 'payments' => 50, 'tickets' => 10];

    /** The steps in order: name => label. */
    public const STEPS = [
        'setup' => 'Currencies, countries and gateways',
        'products' => 'Products and domain prices',
        'clients' => 'Clients',
        'services' => 'Services and domains',
        'invoices' => 'Invoices',
        'payments' => 'Payments',
        'tickets' => 'Tickets',
    ];

    /** The WHMCS tables the import reads, as WHMCS names their columns (untyped: SQLite keeps what it is given). */
    private const TABLES = [
        'tblconfiguration' => 'setting TEXT, value TEXT',
        'tblcurrencies' => 'id INTEGER PRIMARY KEY, code TEXT, prefix TEXT, suffix TEXT, format INTEGER, rate REAL, "default" INTEGER',
        'tblclientgroups' => 'id INTEGER PRIMARY KEY, groupname TEXT, groupcolour TEXT, discountpercent REAL, susptermexempt TEXT, separateinvoices TEXT',
        'tblcustomfields' => 'id INTEGER PRIMARY KEY, type TEXT, relid INTEGER, fieldname TEXT, fieldtype TEXT, adminonly TEXT, required TEXT, sortorder INTEGER, description TEXT, fieldoptions TEXT, showorder TEXT',
        'tblcustomfieldsvalues' => 'id INTEGER PRIMARY KEY, fieldid INTEGER, relid INTEGER, value TEXT',
        'tblclients' => 'id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, companyname TEXT, email TEXT, address1 TEXT, address2 TEXT, city TEXT, state TEXT, postcode TEXT, country TEXT, phonenumber TEXT, tax_id TEXT, password TEXT, authmodule TEXT DEFAULT \'\', authdata TEXT DEFAULT \'\', currency INTEGER, taxexempt INTEGER DEFAULT 0, datecreated TEXT, notes TEXT DEFAULT \'\', groupid INTEGER DEFAULT 0, ip TEXT DEFAULT \'\', status TEXT, language TEXT DEFAULT \'\', emailoptout INTEGER DEFAULT 0, marketing_emails_opt_in INTEGER DEFAULT 0, created_at TEXT, credit REAL DEFAULT 0',
        'tblusers' => 'id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, password TEXT, language TEXT DEFAULT \'\', second_factor TEXT DEFAULT \'\', second_factor_config TEXT DEFAULT \'\', last_ip TEXT DEFAULT \'\', last_login TEXT, created_at TEXT',
        'tblusers_clients' => 'id INTEGER PRIMARY KEY, auth_user_id INTEGER, client_id INTEGER, owner INTEGER, permissions TEXT DEFAULT \'\', last_login TEXT, created_at TEXT',
        'tblcontacts' => 'id INTEGER PRIMARY KEY, userid INTEGER, firstname TEXT, lastname TEXT, companyname TEXT, email TEXT, address1 TEXT, address2 TEXT, city TEXT, state TEXT, postcode TEXT, country TEXT, phonenumber TEXT, tax_id TEXT, subaccount INTEGER, password TEXT, permissions TEXT, generalemails INTEGER, invoiceemails INTEGER, productemails INTEGER, domainemails INTEGER, supportemails INTEGER, affiliateemails INTEGER, created_at TEXT',
        'tblnotes' => 'id INTEGER PRIMARY KEY, userid INTEGER, adminid INTEGER, created TEXT, modified TEXT, note TEXT, sticky INTEGER',
        'tblauthn_account_links' => 'id INTEGER PRIMARY KEY, provider TEXT, remote_user_id TEXT, user_id INTEGER, client_id INTEGER, contact_id INTEGER, metadata TEXT, created_at TEXT',
        'tblpaymethods' => 'id INTEGER PRIMARY KEY, userid TEXT, gateway_name TEXT, deleted_at TEXT',
        'tblpaymentgateways' => 'gateway TEXT, setting TEXT, value TEXT',
        'tblproductgroups' => 'id INTEGER PRIMARY KEY, name TEXT, slug TEXT, headline TEXT DEFAULT \'\', tagline TEXT DEFAULT \'\', hidden INTEGER DEFAULT 0, "order" INTEGER DEFAULT 0',
        'tblservers' => 'id INTEGER PRIMARY KEY, name TEXT, ipaddress TEXT, assignedips TEXT, hostname TEXT, statusaddress TEXT, nameserver1 TEXT, nameserver2 TEXT, nameserver3 TEXT, nameserver4 TEXT, maxaccounts INTEGER, type TEXT, username TEXT, password TEXT, accesshash TEXT, secure TEXT, port TEXT, active INTEGER, disabled INTEGER',
        'tblservergroups' => 'id INTEGER PRIMARY KEY, name TEXT, filltype INTEGER',
        'tblservergroupsrel' => 'id INTEGER PRIMARY KEY, groupid INTEGER, serverid INTEGER',
        'tblproducts' => 'id INTEGER PRIMARY KEY, type TEXT, gid INTEGER, name TEXT, slug TEXT DEFAULT \'\', description TEXT DEFAULT \'\', hidden INTEGER DEFAULT 0, stockcontrol INTEGER DEFAULT 0, qty INTEGER DEFAULT 0, proratabilling INTEGER DEFAULT \'\', paytype TEXT, allowqty INTEGER DEFAULT 0, autosetup TEXT DEFAULT \'\', servertype TEXT DEFAULT \'\', servergroup INTEGER DEFAULT 0, configoption1 TEXT DEFAULT \'\', freedomain TEXT DEFAULT \'\', freedomainpaymentterms TEXT DEFAULT \'\', freedomaintlds TEXT DEFAULT \'\', recurringcycles INTEGER DEFAULT 0, autoterminatedays INTEGER DEFAULT 0, overagesenabled INTEGER DEFAULT \'\', "order" INTEGER DEFAULT 0, retired INTEGER DEFAULT 0',
        'tblpricing' => 'id INTEGER PRIMARY KEY, type TEXT, currency INTEGER, relid INTEGER, msetupfee REAL DEFAULT -1, qsetupfee REAL DEFAULT -1, ssetupfee REAL DEFAULT -1, asetupfee REAL DEFAULT -1, bsetupfee REAL DEFAULT -1, tsetupfee REAL DEFAULT 0, monthly REAL DEFAULT -1, quarterly REAL DEFAULT -1, semiannually REAL DEFAULT -1, annually REAL DEFAULT -1, biennially REAL DEFAULT -1, triennially REAL DEFAULT -1',
        'tbladdons' => 'id INTEGER PRIMARY KEY, packages TEXT, name TEXT, description TEXT, billingcycle TEXT, allowqty INTEGER, showorder INTEGER, hidden INTEGER, retired INTEGER, autoactivate TEXT, module TEXT, weight INTEGER',
        'tblproduct_upgrade_products' => 'id INTEGER PRIMARY KEY, product_id INTEGER, upgrade_product_id INTEGER',
        'tblpromotions' => 'id INTEGER PRIMARY KEY, code TEXT, type TEXT, recurring INTEGER, value REAL, cycles TEXT, appliesto TEXT, requires TEXT, requiresexisting INTEGER, startdate TEXT, expirationdate TEXT, maxuses INTEGER, uses INTEGER, applyonce INTEGER, newsignups INTEGER, existingclient INTEGER, onceperclient INTEGER, recurfor INTEGER, upgrades INTEGER, notes TEXT',
        'tbldomainpricing' => 'id INTEGER PRIMARY KEY, extension TEXT, dnsmanagement INTEGER DEFAULT \'\', emailforwarding INTEGER DEFAULT \'\', idprotection INTEGER DEFAULT \'\', eppcode INTEGER DEFAULT \'on\', autoreg TEXT DEFAULT \'\', "order" INTEGER DEFAULT 0',
        'tblbundles' => 'id INTEGER PRIMARY KEY, name TEXT, itemdata TEXT',
        'tblhosting' => 'id INTEGER PRIMARY KEY, userid INTEGER, orderid INTEGER DEFAULT 0, packageid INTEGER, server INTEGER DEFAULT 0, regdate TEXT, domain TEXT DEFAULT \'\', paymentmethod TEXT DEFAULT \'\', qty INTEGER DEFAULT 1, firstpaymentamount REAL, amount REAL, billingcycle TEXT, nextduedate TEXT, termination_date TEXT, domainstatus TEXT, username TEXT DEFAULT \'\', password TEXT DEFAULT \'\', notes TEXT DEFAULT \'\', subscriptionid TEXT DEFAULT \'\', promoid INTEGER DEFAULT 0, suspendreason TEXT DEFAULT \'\', overideautosuspend TEXT DEFAULT 0, overidesuspenduntil TEXT, dedicatedip TEXT DEFAULT \'\'',
        'tblhostingaddons' => 'id INTEGER PRIMARY KEY, orderid INTEGER, hostingid INTEGER, addonid INTEGER, userid INTEGER, name TEXT, qty INTEGER, recurring REAL, billingcycle TEXT, status TEXT, regdate TEXT, nextduedate TEXT, termination_date TEXT, notes TEXT, subscriptionid TEXT',
        'tbldomains' => 'id INTEGER PRIMARY KEY, userid INTEGER, orderid INTEGER DEFAULT 0, type TEXT DEFAULT \'Register\', registrationdate TEXT, domain TEXT, firstpaymentamount REAL, recurringamount REAL, registrar TEXT DEFAULT \'\', registrationperiod INTEGER, expirydate TEXT, subscriptionid TEXT DEFAULT \'\', promoid INTEGER DEFAULT 0, status TEXT, nextduedate TEXT, additionalnotes TEXT DEFAULT \'\', dnsmanagement INTEGER DEFAULT \'\', emailforwarding INTEGER DEFAULT \'\', idprotection INTEGER DEFAULT \'\', donotrenew INTEGER DEFAULT 0',
        'tblcancelrequests' => 'id INTEGER PRIMARY KEY, date TEXT, relid INTEGER, reason TEXT, type TEXT',
        'tblproductconfiggroups' => 'id INTEGER PRIMARY KEY, name TEXT, description TEXT',
        'tblproductconfiglinks' => 'id INTEGER PRIMARY KEY, gid INTEGER, pid INTEGER',
        'tblproductconfigoptions' => 'id INTEGER PRIMARY KEY, gid INTEGER, optionname TEXT, optiontype INTEGER, qtyminimum INTEGER, qtymaximum INTEGER, "order" INTEGER, hidden INTEGER',
        'tblproductconfigoptionssub' => 'id INTEGER PRIMARY KEY, configid INTEGER, optionname TEXT, sortorder INTEGER, hidden INTEGER',
        'tblhostingconfigoptions' => 'id INTEGER PRIMARY KEY, relid INTEGER, configid INTEGER, optionid INTEGER, qty INTEGER',
        'tblinvoices' => 'id INTEGER PRIMARY KEY, userid INTEGER, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT, subtotal REAL, credit REAL DEFAULT 0, tax REAL DEFAULT 0, tax2 REAL DEFAULT 0, total REAL, taxrate REAL DEFAULT 0, status TEXT, paymentmethod TEXT DEFAULT \'\', notes TEXT DEFAULT \'\'',
        'tblinvoiceitems' => 'id INTEGER PRIMARY KEY, invoiceid INTEGER, userid INTEGER, type TEXT, relid INTEGER, description TEXT, amount REAL, taxed INTEGER',
        'tblaccounts' => 'id INTEGER PRIMARY KEY, userid INTEGER, currency INTEGER DEFAULT 0, gateway TEXT, date TEXT, description TEXT, amountin REAL DEFAULT 0, fees REAL DEFAULT 0, amountout REAL DEFAULT 0, transid TEXT, invoiceid INTEGER',
        'tblcredit' => 'id INTEGER PRIMARY KEY, clientid INTEGER, date TEXT, description TEXT, amount REAL, relid INTEGER',
        'tblbillingnotes' => 'id INTEGER PRIMARY KEY, note_type TEXT, custom_number TEXT, client_id INTEGER, date_issued TEXT, subtotal REAL, total REAL, taxrate REAL, status TEXT, notes TEXT',
        'tblbillingnoteitems' => 'id INTEGER PRIMARY KEY, billingnote_id INTEGER, description TEXT, amount REAL, taxed INTEGER',
        'tbltax' => 'id INTEGER PRIMARY KEY, level TEXT, name TEXT, state TEXT, country TEXT, taxrate REAL',
        'tbladdonmodules' => 'module TEXT, setting TEXT, value TEXT',
        'tbladmins' => 'id INTEGER PRIMARY KEY, roleid INTEGER, username TEXT, passwordhash TEXT, authmodule TEXT, firstname TEXT, lastname TEXT, email TEXT, signature TEXT, disabled INTEGER, created_at TEXT',
        'tblticketdepartments' => 'id INTEGER PRIMARY KEY, name TEXT, description TEXT DEFAULT \'\', email TEXT DEFAULT \'\', clientsonly TEXT DEFAULT \'\', piperepliesonly TEXT DEFAULT \'\', noautoresponder TEXT DEFAULT \'\', hidden TEXT DEFAULT \'\', "order" INTEGER DEFAULT 0, host TEXT DEFAULT \'\', port TEXT DEFAULT \'\', login TEXT DEFAULT \'\', password TEXT DEFAULT \'\', mail_auth_config TEXT DEFAULT \'\', feedback_request INTEGER DEFAULT 0',
        'tbltickets' => 'id INTEGER PRIMARY KEY, status TEXT, attachment TEXT DEFAULT \'\', tid TEXT, did INTEGER, userid INTEGER, contactid INTEGER DEFAULT 0, requestor_id INTEGER DEFAULT 0, name TEXT, email TEXT, cc TEXT DEFAULT \'\', c TEXT, date TEXT, title TEXT, message TEXT, urgency TEXT, admin TEXT DEFAULT \'\', attachments_removed INTEGER DEFAULT 0, lastreply TEXT, flag INTEGER DEFAULT 0, service TEXT DEFAULT \'\', merged_ticket_id INTEGER DEFAULT 0, editor TEXT DEFAULT \'plain\'',
        'tblticketpredefinedcats' => 'id INTEGER PRIMARY KEY, parentid INTEGER, name TEXT',
        'tblticketpredefinedreplies' => 'id INTEGER PRIMARY KEY, catid INTEGER, name TEXT, reply TEXT',
        'tblticketreplies' => 'id INTEGER PRIMARY KEY, tid INTEGER, userid INTEGER, contactid INTEGER DEFAULT 0, requestor_id INTEGER DEFAULT 0, name TEXT, email TEXT, date TEXT, message TEXT, admin TEXT DEFAULT \'\', attachment TEXT DEFAULT \'\', attachments_removed INTEGER DEFAULT 0, rating INTEGER DEFAULT 0, editor TEXT DEFAULT \'plain\'',
        'tblticketnotes' => 'id INTEGER PRIMARY KEY, ticketid INTEGER, admin TEXT, date TEXT, message TEXT, attachments TEXT DEFAULT \'\', attachments_removed INTEGER DEFAULT 0, editor TEXT DEFAULT \'plain\'',
        'tblticketfeedback' => 'id INTEGER PRIMARY KEY, ticketid INTEGER, adminid INTEGER, rating INTEGER, comments TEXT, datetime TEXT, ip TEXT',
        'tblticketstatuses' => 'id INTEGER PRIMARY KEY, title TEXT, color TEXT, sortorder INTEGER, showactive INTEGER, showawaiting INTEGER, autoclose INTEGER',
        'tblticketspamfilters' => 'id INTEGER PRIMARY KEY, type TEXT, content TEXT',
        'tblknowledgebasecats' => 'id INTEGER PRIMARY KEY, parentid INTEGER, name TEXT, description TEXT, hidden TEXT, catid INTEGER, language TEXT',
        'tblknowledgebase' => 'id INTEGER PRIMARY KEY, title TEXT, article TEXT, views INTEGER, useful INTEGER, votes INTEGER, private TEXT, "order" INTEGER, parentid INTEGER, language TEXT',
        'tblknowledgebaselinks' => 'id INTEGER PRIMARY KEY, categoryid INTEGER, articleid INTEGER',
        'tblannouncements' => 'id INTEGER PRIMARY KEY, date TEXT, title TEXT, announcement TEXT, published INTEGER, parentid INTEGER, language TEXT, created_at TEXT, updated_at TEXT',
        'tblnetworkissues' => 'id INTEGER PRIMARY KEY, title TEXT, description TEXT, type TEXT, affecting TEXT, server INTEGER, priority TEXT, startdate TEXT, enddate TEXT, status TEXT, lastupdate TEXT, created_at TEXT, updated_at TEXT',
        'tblemails' => 'id INTEGER PRIMARY KEY, userid INTEGER, subject TEXT, message TEXT, date TEXT, "to" TEXT, cc TEXT, bcc TEXT, attachments TEXT, pending INTEGER, failed INTEGER',
        // Upmind's UUIDs and lookups.
        'upmind_id' => 'entity TEXT, uuid TEXT, id INTEGER, PRIMARY KEY (entity, uuid)',
        'upmind_lookup' => 'kind TEXT, uuid TEXT, value TEXT, extra TEXT, PRIMARY KEY (kind, uuid)',
    ];

    /** Upmind billing cycles (months) => WHMCS cycle names. */
    private const CYCLES = [1 => 'Monthly', 3 => 'Quarterly', 6 => 'Semi-Annually', 12 => 'Annually', 24 => 'Biennially', 36 => 'Triennially'];

    /** WHMCS tblpricing columns of those cycles. */
    private const PRICE_COLUMNS = [1 => 'monthly', 3 => 'quarterly', 6 => 'semiannually', 12 => 'annually', 24 => 'biennially', 36 => 'triennially'];

    /** tblpricing columns of a domain price row by years (Whmcs\Catalogue::DOMAIN_YEARS). */
    private const DOMAIN_COLUMNS = [1 => 'msetupfee', 2 => 'qsetupfee', 3 => 'ssetupfee', 4 => 'asetupfee', 5 => 'bsetupfee', 6 => 'monthly', 7 => 'quarterly', 8 => 'semiannually', 9 => 'annually', 10 => 'biennially'];

    private const SERVICE_STATUSES = [
        'contract_active' => 'Active', 'contract_cancellation_request' => 'Active', 'contract_pending' => 'Pending', 'contract_awaiting_activation' => 'Pending',
        'contract_suspended' => 'Suspended', 'contract_cancelled' => 'Cancelled', 'contract_closed' => 'Terminated', 'contract_fraud' => 'Fraud',
    ];

    private const DOMAIN_STATUSES = [
        'contract_active' => 'Active', 'contract_cancellation_request' => 'Active', 'contract_suspended' => 'Active', 'contract_pending' => 'Pending',
        'contract_awaiting_activation' => 'Pending', 'contract_cancelled' => 'Cancelled', 'contract_closed' => 'Expired', 'contract_fraud' => 'Fraud',
    ];

    private const INVOICE_STATUSES = [
        'invoice_paid' => 'Paid', 'invoice_adjusted' => 'Paid', 'invoice_allocated' => 'Paid', 'invoice_cancelled' => 'Cancelled', 'invoice_replaced' => 'Cancelled',
        'invoice_refunded' => 'Refunded', 'invoice_credited' => 'Refunded',
    ];

    private const TICKET_STATUSES = [
        'ticket_in_progress' => 'In Progress', 'ticket_waiting_response' => 'Answered', 'ticket_client_replied' => 'Customer-Reply',
        'ticket_scheduled' => 'On Hold', 'ticket_closed' => 'Closed',
    ];

    /** Where the snapshot is kept: the private backups folder, outside the web root. */
    public static function path(): string
    {
        return \ClientFence\Mod\Clientfence\Backups::localDir() . '/upmind-import.sqlite';
    }

    /** The snapshot, read only (Whmcs\Source::open()). */
    public static function open(): Connection
    {
        if (!is_file(self::path())) {
            throw new InformationException('Read Upmind first.');
        }
        $db = self::connect(self::path());
        $db->executeStatement('PRAGMA query_only = ON');

        return $db;
    }

    /**
     * Reads Upmind for up to $seconds (0: until done); $restart starts again from nothing. Returns
     * the state: [steps => [name => [label, done, finished]], step, offset, finished_at]. Its
     * warnings go to the import's warnings (Whmcs\Run::WARNINGS, step "upmind"), as many as there are.
     *
     * @return array<string, mixed>
     */
    public static function fetch(\Pimple\Container $di, #[\SensitiveParameter] string $token, float $seconds = 0.0, bool $restart = false): array
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new InformationException('The Upmind import needs PHP\'s pdo_sqlite extension. Turn it on for this site\'s PHP version, then try again.');
        }
        $state = $restart ? null : self::state($di);
        if ($state === null) {
            @unlink(self::path());
            $dir = dirname(self::path());
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new InformationException('Could not make the folder :dir.', [':dir' => $dir]);
            }
            // Every client's data: readable by this site only.
            touch(self::path());
            chmod(self::path(), 0600);
            $state = ['started_at' => date('Y-m-d H:i:s'), 'finished_at' => null, 'step' => 'setup', 'offset' => 0, 'steps' => []];
            $di['em']->getConnection()->delete(Run::WARNINGS, ['source' => Map::SOURCE, 'step' => 'upmind']);
            foreach (self::STEPS as $step => $label) {
                $state['steps'][$step] = ['label' => $label, 'done' => 0, 'finished' => false];
            }
        } elseif ($state['finished_at'] !== null) {
            return $state;
        }
        $db = self::connect(self::path());
        $snapshot = new self(new Api($di, $token), $db);
        $started = microtime(true);
        try {
            foreach (array_keys(self::STEPS) as $step) {
                while (empty($state['steps'][$step]['finished'])) {
                    // The page is kept only once the state names the next one: a stop in between reads it again.
                    $db->beginTransaction();
                    try {
                        [$next, $done] = $snapshot->page($step, (int) $state['offset']);
                        $state['steps'][$step]['done'] += $done;
                        $state['steps'][$step]['finished'] = $next === null;
                        $state['step'] = $step;
                        $state['offset'] = $next ?? 0;
                        Run::addWarnings($di['em']->getConnection(), 'upmind', $snapshot->warnings);
                        $snapshot->warnings = [];
                        self::save($di, $state);
                        $db->commit();
                    } catch (\Throwable $e) {
                        $db->rollBack();

                        throw $e;
                    }
                    if ($seconds > 0 && microtime(true) - $started >= $seconds) {
                        return $state;
                    }
                }
            }
            $snapshot->finish();
            Run::addWarnings($di['em']->getConnection(), 'upmind', $snapshot->warnings);
            $state['finished_at'] = date('Y-m-d H:i:s');
            $state['step'] = null;
            self::save($di, $state);
        } finally {
            $db->close();
        }

        return $state;
    }

    /** @return array<string, mixed>|null the last read's state */
    public static function state(\Pimple\Container $di): ?array
    {
        try {
            $row = $di['em']->getConnection()->fetchOne('SELECT value FROM mod_clientfence_state WHERE name = ?', [self::STATE]);
        } catch (\Throwable) {
            return null;
        }
        $value = is_string($row) ? json_decode($row, true) : null;

        return is_array($value) && is_file(self::path()) ? $value : null;
    }

    /** @var list<string> */
    private array $warnings = [];

    private function __construct(private readonly Api $api, private readonly Connection $db)
    {
        if ($this->db->fetchOne("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'upmind_id'") === 0) {
            foreach (self::TABLES as $table => $columns) {
                $this->db->executeStatement(sprintf('CREATE TABLE IF NOT EXISTS %s (%s)', $table, $columns));
            }
        }
    }

    private static function connect(string $path): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
    }

    /** @param array<string, mixed> $state */
    private static function save(\Pimple\Container $di, array $state): void
    {
        $db = $di['em']->getConnection();
        $row = ['value' => json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 'updated_at' => date('Y-m-d H:i:s')];
        if ($db->update('mod_clientfence_state', $row, ['name' => self::STATE]) === 0 && $db->fetchOne('SELECT 1 FROM mod_clientfence_state WHERE name = ?', [self::STATE]) === false) {
            $db->insert('mod_clientfence_state', $row + ['name' => self::STATE]);
        }
    }

    /**
     * One page of a step: [the next offset or null when the step is done, rows read].
     *
     * @return array{0: ?int, 1: int}
     */
    private function page(string $step, int $offset): array
    {
        if ($step === 'setup') {
            return [null, $this->setup()];
        }
        $path = ['products' => 'products', 'clients' => 'clients', 'services' => 'contracts_products', 'invoices' => 'invoices', 'payments' => 'payments', 'tickets' => 'tickets'][$step];
        [$rows, $total] = $this->api->page($path, $offset, self::PAGES[$step], ['products' => ['with' => 'prices'], 'tickets' => ['with' => 'department']][$step] ?? []);
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
        $next = $offset + count($rows);

        return [$rows === [] || $next >= $total ? null : $next, count($rows)];
    }

    /** Lookups the other steps need, and the brand's currency as the default one. */
    private function setup(): int
    {
        $this->db->insert('tblconfiguration', ['setting' => 'Version', 'value' => '8.0.0 (Upmind)']);
        $this->db->insert('tblconfiguration', ['setting' => 'SequentialInvoiceNumbering', 'value' => 'on']);
        foreach ($this->api->all('currencies') as $c) {
            $this->lookup('currency', (string) $c['id'], (string) $c['code'], json_encode([$c['prefix'] ?? '', $c['suffix'] ?? '', (int) ($c['decimals'] ?? 2)]));
        }
        foreach ($this->api->all('countries') as $c) {
            $this->lookup('country', (string) $c['id'], strtoupper((string) $c['code']));
        }
        // Upmind lists its tax tags only inside its tax templates.
        $tags = [];
        foreach ($this->api->all('tax_templates', ['with' => 'tax_tags,tax_tags.locations']) as $template) {
            foreach ((array) ($template['tax_tags'] ?? []) as $tag) {
                $tags[(string) $tag['id']] = $tag;
            }
        }
        foreach ($tags as $tag) {
            $this->taxTag($tag);
        }
        foreach ($this->api->all('statuses') as $s) {
            $this->lookup('status', (string) $s['id'], (string) $s['code']);
        }
        foreach ($this->api->all('gateways') as $g) {
            $provider = strtolower((string) ($g['provider'] ?? ''));
            $module = match (true) {
                str_contains($provider, 'stripe') => 'stripe',
                str_contains($provider, 'paypal') => 'paypal',
                default => trim((string) preg_replace('/[^a-z0-9]+/', '', strtolower((string) $g['name']))),
            };
            $this->lookup('gateway', (string) $g['id'], $module);
            $this->db->insert('tblpaymentgateways', ['gateway' => $module, 'setting' => 'name', 'value' => (string) $g['name']]);
        }
        $brand = $this->api->all('brands')[0] ?? [];
        $default = $this->value('currency', (string) ($brand['currency_id'] ?? ''));
        if ($default === null) {
            throw new InformationException('Upmind named no currency for its brand.');
        }
        $this->currency($default, true);
        foreach ($this->api->all('products_categories') as $i => $c) {
            $this->db->insert('tblproductgroups', ['id' => $this->id('group', (string) $c['id']), 'name' => (string) $c['name'], 'slug' => '', 'hidden' => !empty($c['hidden']) ? 1 : 0, '"order"' => $i]);
            if (($c['module_sub_id'] ?? '') === 'domains') {
                $this->lookup('domaingroup', (string) $c['id'], '1');
            }
        }

        return 1;
    }

    /**
     * A tax tag's percentage becomes a tax rule for each country it applies to. ClientFence charges
     * one percentage per country or state, the same to companies and people.
     *
     * @param array<string, mixed> $tag
     */
    private function taxTag(array $tag): void
    {
        $name = trim((string) $tag['name']) ?: 'Tax';
        if ((int) $tag['type'] !== 1) {
            $this->warn(sprintf('Tax "%s" is a fixed amount: ClientFence charges tax as a percentage, so it was not imported.', $name));

            return;
        }
        $rate = (float) $tag['amount'];
        if ((int) ($tag['company_type'] ?? 1) !== 1 || (float) ($tag['company_amount'] ?? $rate) !== $rate) {
            $this->warn(sprintf('Tax "%s" charges companies another rate: ClientFence charges them %s%% too. To exempt businesses with a valid VAT number, see Tax Settings under System > Tax Rules.', $name, $rate));
        }
        $partial = false;
        foreach ((array) ($tag['locations'] ?? []) as $place) {
            $country = $this->value('country', (string) ($place['country_id'] ?? ''));
            // shortcut: a region, city, county or post code is left out (regions need another lookup), add it when a client needs state taxes.
            if ($country === null || array_filter([$place['region_id'] ?? null, $place['city'] ?? null, $place['county'] ?? null, $place['post_code'] ?? null]) !== []) {
                $partial = true;

                continue;
            }
            $this->db->insert('tbltax', ['level' => '1', 'name' => $name, 'state' => '', 'country' => $country, 'taxrate' => $rate]);
        }
        if ($partial || ($tag['locations'] ?? []) === []) {
            $this->warn(sprintf('Tax "%s" applies to a region, city or post code, or to no country: ClientFence taxes by country, so only its whole countries were imported.', $name));
        }
    }

    /** @param array<string, mixed> $p */
    private function product(array $p): void
    {
        $name = trim((string) $p['name']);
        $sub = (string) ($p['module_sub_id'] ?? '');
        if ($sub === 'domain_transfer') {
            return;
        }
        // A domain extension: its prices by years (Upmind prices each term in full).
        if (str_starts_with($sub, 'tld-') || $this->value('domaingroup', (string) ($p['category_id'] ?? '')) !== null) {
            $tld = str_starts_with($name, '.') ? strtolower($name) : '.' . str_replace('-', '.', substr($sub, 4));
            $id = $this->id('tld', (string) $p['id']);
            $this->db->insert('tbldomainpricing', ['id' => $id, 'extension' => $tld]);
            foreach ($this->pricesByCurrency($p) as $code => $prices) {
                $row = ['currency' => $this->currency($code), 'relid' => $id];
                foreach ($prices as $months => $price) {
                    if ($months % 12 === 0 && isset(self::DOMAIN_COLUMNS[$months / 12])) {
                        $row[self::DOMAIN_COLUMNS[$months / 12]] = $price;
                    }
                }
                $this->db->insert('tblpricing', ['type' => 'domainregister'] + $row);
                $this->db->insert('tblpricing', ['type' => 'domainrenew'] + $row);
            }

            return;
        }
        $id = $this->id('product', (string) $p['id']);
        $prices = $this->pricesByCurrency($p);
        $cycles = array_unique(array_merge([], ...array_map('array_keys', array_values($prices))));
        $onetime = $cycles === [0];
        $this->db->insert('tblproducts', [
            'id' => $id, 'type' => 'other', 'gid' => $this->existing('group', (string) ($p['category_id'] ?? '')) ?? 0, 'name' => $name,
            'description' => (string) ($p['description'] ?? ''), 'hidden' => !empty($p['hidden']) ? 1 : 0, 'paytype' => $onetime ? 'onetime' : 'recurring',
            '"order"' => (int) ($p['order'] ?? 0), 'retired' => !empty($p['deleted_at']) ? 1 : 0,
        ]);
        foreach ($prices as $code => $byMonths) {
            $row = ['type' => 'product', 'currency' => $this->currency($code), 'relid' => $id, 'msetupfee' => 0, 'qsetupfee' => 0, 'ssetupfee' => 0, 'asetupfee' => 0, 'bsetupfee' => 0];
            foreach ($byMonths as $months => $price) {
                if ($onetime) {
                    $row['monthly'] = $price;
                } elseif (isset(self::PRICE_COLUMNS[$months])) {
                    $row[self::PRICE_COLUMNS[$months]] = $price;
                } else {
                    $this->warn(sprintf('Product "%s": its %d-month price was not imported (ClientFence bills 1, 3, 6, 12, 24 or 36 months).', $name, $months));
                }
            }
            $this->db->insert('tblpricing', $row);
        }
    }

    /**
     * @param array<string, mixed> $p
     *
     * @return array<string, array<int, float>> currency code => months => price
     */
    private function pricesByCurrency(array $p): array
    {
        $out = [];
        foreach ((array) ($p['prices'] ?? []) as $price) {
            if (empty($price['deleted_at'])) {
                $out[(string) $price['currency_code']][(int) $price['billing_cycle_months']] = (float) $price['price'];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $c */
    private function client(array $c): void
    {
        $uuid = (string) $c['id'];
        $id = $this->id('client', $uuid);
        $address = $this->pick($this->api->all("clients/{$uuid}/addresses"));
        $phone = $this->pick($this->api->all("clients/{$uuid}/phones"));
        $company = $this->pick($this->api->all("clients/{$uuid}/companies"));
        // In Upmind's default currency; finish() asks again in the client's own once its billing shows it.
        $credit = $this->api->one("clients/{$uuid}/credit");
        $email = trim((string) $c['email']);
        // Upmind gives out no password: a hash nobody knows, so the client uses "Forgot password".
        $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT, ['cost' => 4]);
        $created = (string) ($c['created_at'] ?? date('Y-m-d H:i:s'));
        $this->db->insert('tblclients', [
            'id' => $id, 'firstname' => (string) ($c['firstname'] ?? ''), 'lastname' => (string) ($c['lastname'] ?? ''),
            'companyname' => (string) ($company['name'] ?? ''), 'email' => $email,
            'address1' => (string) ($address['address_1'] ?? ''), 'address2' => (string) ($address['address_2'] ?? ''),
            'city' => (string) ($address['city'] ?? ''), 'state' => (string) ($address['county'] ?? $address['state'] ?? ''),
            'postcode' => (string) ($address['postcode'] ?? ''), 'country' => $this->value('country', (string) ($address['country_id'] ?? '')) ?? (string) ($c['location_country_code'] ?? ''),
            'phonenumber' => self::phone($phone), 'tax_id' => (string) ($company['vat_number'] ?? ''), 'password' => $hash,
            'currency' => 1, 'taxexempt' => !empty($c['tax_exempt_number']) ? 1 : 0,
            'datecreated' => substr($created, 0, 10), 'created_at' => $created,
            'status' => $this->value('status', (string) ($c['status_id'] ?? '')) === 'client_inactive' ? 'Inactive' : 'Active',
            'credit' => round((float) ($credit['credit'] ?? 0), 2),
        ]);
        if (!empty($c['has_login'])) {
            $this->db->insert('tblusers', ['id' => $id, 'first_name' => (string) ($c['firstname'] ?? ''), 'last_name' => (string) ($c['lastname'] ?? ''), 'email' => $email, 'password' => $hash, 'last_login' => $c['last_login'] ?? null, 'created_at' => $created]);
            $this->db->insert('tblusers_clients', ['auth_user_id' => $id, 'client_id' => $id, 'owner' => 1, 'created_at' => $created]);
        }
        if (!empty($c['enabled_2fa'])) {
            $this->warn(sprintf('Client %s used two-factor sign-in in Upmind, which could not be carried over: it signs in with a new password until two-factor is set up again.', $email));
        }
    }

    /**
     * The default row of a list, else the first.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private function pick(array $rows): array
    {
        foreach ($rows as $row) {
            if (!empty($row['default'])) {
                return $row;
            }
        }

        return $rows[0] ?? [];
    }

    /** @param array<string, mixed> $phone "+44.2071234567", as WHMCS keeps phone numbers */
    private static function phone(array $phone): string
    {
        $code = ltrim((string) ($phone['phone_code'] ?? ''), '+');
        $full = ltrim((string) ($phone['international_phone'] ?? ''), '+');
        if ($code !== '' && str_starts_with($full, $code)) {
            return '+' . $code . '.' . substr($full, strlen($code));
        }

        return (string) ($phone['phone'] ?? '');
    }

    /** @param array<string, mixed> $s a contract product: a domain, or a service of a product */
    private function service(array $s): void
    {
        $status = (string) ($s['status']['code'] ?? $this->value('status', (string) ($s['status_id'] ?? '')) ?? '');
        $months = (int) ($s['billing_cycle_months'] ?? 0);
        $client = $this->id('client', (string) $s['client_id']);
        $start = substr((string) (($s['activation_date'] ?? null) ?: ($s['start_date'] ?? null) ?: ($s['created_at'] ?? '')), 0, 10);
        $due = substr((string) ($s['next_due_date'] ?? ''), 0, 10);
        // The renewal price: net_amount is what the first invoice charged (zero for a service Upmind imported).
        $amount = round((float) ($s['selling_price'] ?? 0) * max(1, (int) ($s['quantity'] ?? 1)), 2);
        $this->billedIn($client, (string) ($s['currency_code'] ?? ''));
        $tld = $this->db->fetchOne("SELECT id FROM upmind_id WHERE entity = 'tld' AND uuid = ?", [(string) $s['product_id']]);
        if ($tld !== false) {
            $this->db->insert('tbldomains', [
                'id' => $this->id('domain', (string) $s['id']), 'userid' => $client, 'registrationdate' => $start, 'domain' => strtolower(trim((string) $s['service_identifier'])),
                'firstpaymentamount' => $amount, 'recurringamount' => $amount, 'registrationperiod' => max(1, intdiv($months, 12)),
                'expirydate' => $due ?: $start, 'nextduedate' => $due ?: $start, 'status' => self::DOMAIN_STATUSES[$status] ?? 'Pending', 'donotrenew' => empty($s['renew']) || $status === 'contract_cancellation_request' ? 1 : 0,
            ]);

            return;
        }
        $cycle = $months === 0 ? 'One Time' : (self::CYCLES[$months] ?? null);
        if ($cycle === null) {
            $this->warn(sprintf('Service "%s" of client #%d was not imported: it renews every %d months, which ClientFence does not bill.', (string) $s['name'], $client, $months));

            return;
        }
        $this->db->insert('tblhosting', [
            'id' => $this->id('service', (string) $s['id']), 'userid' => $client, 'packageid' => $this->id('product', (string) $s['product_id']),
            'regdate' => $start, 'domain' => (string) ($s['service_identifier'] ?? ''), 'qty' => max(1, (int) ($s['quantity'] ?? 1)),
            'firstpaymentamount' => $amount, 'amount' => $amount, 'billingcycle' => $cycle, 'nextduedate' => $due ?: $start,
            'termination_date' => substr((string) ($s['closed_date'] ?? $s['cancelled_date'] ?? ''), 0, 10) ?: null,
            'domainstatus' => self::SERVICE_STATUSES[$status] ?? 'Pending',
        ]);
        // The WHMCS step puts it in Cancellation Requests, so it does not renew.
        if ($status === 'contract_cancellation_request') {
            $this->db->insert('tblcancelrequests', [
                'date' => (string) (($s['updated_at'] ?? null) ?: gmdate('Y-m-d H:i:s')), 'relid' => $this->id('service', (string) $s['id']),
                'reason' => 'Cancellation requested in Upmind', 'type' => 'End of Billing Period',
            ]);
        }
    }

    /** @param array<string, mixed> $i */
    private function invoice(array $i): void
    {
        if (round((float) $i['total_amount'], 2) == 0.0) {
            return;
        }
        $full = $this->api->one('invoices/' . rawurlencode((string) $i['number']));
        $id = $this->id('invoice', (string) $i['id']);
        $client = $this->id('client', (string) $i['client_id']);
        $status = $this->value('status', (string) $i['status_id']) ?? '';
        $currency = $this->value('currency', (string) $i['currency_id']);
        $net = (float) ($i['net_amount'] ?? 0);
        $tax = (float) ($i['tax_amount'] ?? 0);
        $content = $full['current_data']['content'] ?? [];
        $content = is_string($content) ? (array) json_decode($content, true) : (array) $content;
        $lines = (array) ($content['products'] ?? []);
        // The rate of the taxed lines only (WHMCS's taxrate applies to the lines marked taxed).
        $taxedNet = array_sum(array_map(static fn (array $l): float => (float) ($l['tax_amount'] ?? 0) > 0 ? (float) ($l['net_amount'] ?? 0) : 0.0, $lines));
        if ($currency !== null) {
            $this->billedIn($client, $currency);
        }
        $this->db->insert('tblinvoices', [
            'id' => $id, 'userid' => $client, 'invoicenum' => (string) $i['number'],
            'date' => substr((string) ($i['create_datetime'] ?? $i['created_at']), 0, 10), 'duedate' => substr((string) ($i['due_date'] ?? ''), 0, 10),
            'datepaid' => (string) ($i['paid_datetime'] ?? '') ?: '0000-00-00 00:00:00', 'subtotal' => $net, 'tax' => $tax, 'total' => (float) $i['total_amount'],
            'taxrate' => $taxedNet > 0 ? round($tax / $taxedNet * 100, 2) : 0, 'status' => self::INVOICE_STATUSES[$status] ?? 'Unpaid',
            'paymentmethod' => $this->value('gateway', (string) ($i['gateway_id'] ?? '')) ?? '',
        ]);
        foreach ($lines as $line) {
            $service = (string) ($line['contracts_product_id'] ?? '');
            [$type, $relid] = match (true) {
                $service !== '' && ($d = $this->existing('domain', $service)) !== null => ['Domain', $d],
                $service !== '' && ($h = $this->existing('service', $service)) !== null => ['Hosting', $h],
                default => ['', 0],
            };
            $period = !empty($line['from_date']) && !empty($line['to_date']) ? sprintf(' (%s - %s)', substr((string) $line['from_date'], 0, 10), substr((string) $line['to_date'], 0, 10)) : '';
            $this->db->insert('tblinvoiceitems', [
                'invoiceid' => $id, 'userid' => $client, 'type' => $type, 'relid' => $relid,
                'description' => trim((string) ($line['name'] ?? '') . (!empty($line['service_identifier']) ? ' - ' . $line['service_identifier'] : '') . $period),
                'amount' => (float) ($line['net_amount'] ?? 0), 'taxed' => (float) ($line['tax_amount'] ?? 0) > 0 ? 1 : 0,
            ]);
        }
    }

    /** @param array<string, mixed> $p */
    private function payment(array $p): void
    {
        $invoice = $this->existing('invoice', (string) ($p['invoice_id'] ?? ''));
        $amount = round((float) ($p['amount'] ?? 0), 2);
        if (strtoupper((string) ($p['status'] ?? '')) !== 'OK' || $amount == 0.0) {
            return;
        }
        if ($invoice === null) {
            // A credit top-up, or a payment of a zero invoice: the credit balance carries it.
            return;
        }
        $this->db->insert('tblaccounts', [
            'userid' => $this->id('client', (string) $p['client_id']), 'gateway' => $this->value('gateway', (string) ($p['gateway_id'] ?? '')) ?? '',
            'date' => (string) $p['created_at'], 'description' => 'Invoice Payment', 'amountin' => max(0, $amount), 'amountout' => max(0, -$amount),
            'transid' => (string) ($p['transaction_id'] ?? '') ?: (string) $p['id'], 'invoiceid' => $invoice,
        ]);
    }

    /** @param array<string, mixed> $t */
    private function ticket(array $t): void
    {
        $uuid = (string) $t['id'];
        $id = $this->id('ticket', $uuid);
        $client = !empty($t['client_id']) ? $this->id('client', (string) $t['client_id']) : 0;
        $who = $client > 0 ? $this->db->fetchAssociative('SELECT firstname, lastname, email FROM tblclients WHERE id = ?', [$client]) : false;
        $name = $who ? trim($who['firstname'] . ' ' . $who['lastname']) : '';
        $email = $who ? (string) $who['email'] : '';
        $department = (string) ($t['ticket_department_id'] ?? '');
        if ($department !== '' && $this->existing('department', $department) === null) {
            $this->db->insert('tblticketdepartments', ['id' => $this->id('department', $department), 'name' => (string) ($t['department']['name'] ?? 'Support')]);
        }
        $messages = $this->api->all("tickets/{$uuid}/messages");
        usort($messages, static fn (array $a, array $b): int => strcmp((string) $a['created_at'], (string) $b['created_at']));
        $first = '';
        foreach ($messages as $m) {
            if (!empty($m['is_log'])) {
                continue;
            }
            $body = (string) ($m['body'] ?? '');
            if (!empty($m['is_private'])) {
                $this->db->insert('tblticketnotes', ['ticketid' => $id, 'admin' => (string) ($m['actor_name'] ?? '') ?: 'Staff', 'date' => (string) $m['created_at'], 'message' => $body]);
            } elseif ($first === '' && !empty($m['client_id'])) {
                $first = $body;
            } else {
                $staff = empty($m['client_id']);
                $this->db->insert('tblticketreplies', [
                    'tid' => $id, 'userid' => $staff ? 0 : $client, 'name' => $staff ? '' : $name, 'email' => $staff ? '' : $email,
                    'date' => (string) $m['created_at'], 'message' => $body, 'admin' => $staff ? ((string) ($m['actor_name'] ?? '') ?: 'Staff') : '',
                ]);
            }
        }
        $status = $this->value('status', (string) ($t['status_id'] ?? '')) ?? '';
        $this->db->insert('tbltickets', [
            'id' => $id, 'status' => self::TICKET_STATUSES[$status] ?? 'Open', 'tid' => (string) ($t['reference'] ?? '') ?: (string) $id,
            'did' => $department !== '' ? $this->id('department', $department) : 0, 'userid' => $client, 'name' => $name, 'email' => $email,
            'c' => bin2hex(random_bytes(4)), 'date' => (string) $t['created_at'], 'title' => (string) ($t['subject'] ?? ''),
            'message' => $first, 'urgency' => 'Medium', 'lastreply' => (string) ($t['updated_at'] ?? $t['created_at']),
        ]);
    }

    /**
     * After the last step: credit balances in each client's own currency, the invoice number format
     * ("Z-INV-{NUMBER}"), a warning when Upmind charged tax (tax stays off), and
     * the UUID tables dropped (the import would take them for an add-on module's).
     */
    private function finish(): void
    {
        $default = (int) $this->db->fetchOne('SELECT id FROM tblcurrencies WHERE "default" = 1');
        foreach ($this->db->fetchAllAssociative("SELECT c.id, c.credit, u.uuid, cur.code FROM tblclients c JOIN upmind_id u ON u.entity = 'client' AND u.id = c.id JOIN tblcurrencies cur ON cur.id = c.currency WHERE c.currency <> ?", [$default]) as $c) {
            $credit = $this->api->one("clients/{$c['uuid']}/credit", ['currency_code' => (string) $c['code']]);
            $this->db->update('tblclients', ['credit' => round((float) ($credit['credit'] ?? 0), 2)], ['id' => (int) $c['id']]);
        }
        $this->db->executeStatement('DROP TABLE IF EXISTS upmind_id');
        $this->db->executeStatement('DROP TABLE IF EXISTS upmind_lookup');
        $series = [];
        foreach ($this->db->fetchFirstColumn('SELECT invoicenum FROM tblinvoices') as $number) {
            $prefix = \ClientFence\Mod\Clientfence\Whmcs\Money::number((string) $number)[0];
            $series[$prefix] = ($series[$prefix] ?? 0) + 1;
        }
        arsort($series);
        $this->db->insert('tblconfiguration', ['setting' => 'SequentialInvoiceNumberFormat', 'value' => (string) (array_key_first($series) ?? '') . '{NUMBER}']);
        if ((int) $this->db->fetchOne('SELECT (SELECT COUNT(*) FROM tblinvoices WHERE tax > 0) + (SELECT COUNT(*) FROM tbltax)') > 0) {
            $this->db->insert('tblconfiguration', ['setting' => 'TaxType', 'value' => 'Exclusive']);
            $this->warn('Upmind charges tax, but which products it taxes is not imported, so tax stays switched off: check the tax rules and turn on Enable Tax Support under System > Tax Rules before new invoices are made.');
        }
    }

    /**
     * A client's currency is the one Upmind bills it in (its first service or invoice): Upmind's
     * client records have none. ClientFence bills a client in one currency, so another is named.
     */
    private function billedIn(int $client, string $code): void
    {
        if ($code === '') {
            return;
        }
        $first = $this->value('billedin', (string) $client);
        if ($first === null) {
            $this->lookup('billedin', (string) $client, $code);
            $this->db->update('tblclients', ['currency' => $this->currency($code)], ['id' => $client]);
        } elseif ($first !== $code) {
            $this->warn(sprintf('Client #%d is billed in %s and %s in Upmind; ClientFence bills a client in one currency, so its %s services and invoices are imported in %s.', $client, $first, $code, $code, $first));
        }
    }

    /** This snapshot's number for an Upmind UUID, made on first use. */
    private function id(string $entity, string $uuid): int
    {
        $id = $this->existing($entity, $uuid);
        if ($id !== null) {
            return $id;
        }
        $id = (int) $this->db->fetchOne('SELECT COALESCE(MAX(id), 0) + 1 FROM upmind_id WHERE entity = ?', [$entity]);
        $this->db->insert('upmind_id', ['entity' => $entity, 'uuid' => $uuid, 'id' => $id]);

        return $id;
    }

    private function existing(string $entity, string $uuid): ?int
    {
        $id = $uuid === '' ? false : $this->db->fetchOne('SELECT id FROM upmind_id WHERE entity = ? AND uuid = ?', [$entity, $uuid]);

        return $id === false ? null : (int) $id;
    }

    private function lookup(string $kind, string $uuid, string $value, ?string $extra = null): void
    {
        $this->db->executeStatement('INSERT OR REPLACE INTO upmind_lookup (kind, uuid, value, extra) VALUES (?, ?, ?, ?)', [$kind, $uuid, $value, $extra]);
    }

    private function value(string $kind, string $uuid): ?string
    {
        $value = $uuid === '' ? false : $this->db->fetchOne('SELECT value FROM upmind_lookup WHERE kind = ? AND uuid = ?', [$kind, $uuid]);

        return $value === false ? null : (string) $value;
    }

    /** The WHMCS currency id of a code, added on first use with Upmind's symbols. */
    private function currency(string $code, bool $default = false): int
    {
        $id = $this->db->fetchOne('SELECT id FROM tblcurrencies WHERE code = ?', [$code]);
        if ($id !== false) {
            return (int) $id;
        }
        $extra = $this->db->fetchOne("SELECT extra FROM upmind_lookup WHERE kind = 'currency' AND value = ?", [$code]);
        [$prefix, $suffix, $decimals] = is_string($extra) ? (array) json_decode($extra, true) + ['', '', 2] : ['', '', 2];
        $this->db->insert('tblcurrencies', ['code' => $code, 'prefix' => $prefix, 'suffix' => $suffix, 'format' => (int) $decimals === 0 ? 4 : 2, 'rate' => 1, '"default"' => $default ? 1 : 0]);
        if (!$default) {
            $this->warn(sprintf('Currency %s: Upmind gives no exchange rate; set it under System > Currencies after the import.', $code));
        }

        return (int) $this->db->lastInsertId();
    }

    private function warn(string $message): void
    {
        $this->warnings[] = $message;
    }
}
