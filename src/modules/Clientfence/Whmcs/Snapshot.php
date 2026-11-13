<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Whmcs;

use ClientFence\InformationException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * ClientFence: an import from another billing system through a snapshot (Upmind/Snapshot.php,
 * Blesta/Snapshot.php). The system is read into a private SQLite file laid out as WHMCS's tables,
 * and the WHMCS import (Run.php) imports that file as it imports a WHMCS database
 * (Source::open()).
 *
 * read() runs a page at a time for as long as it is given, keeping its place in
 * mod_clientfence_state (static::STATE), so the admin tab can call it again and again and a stop
 * loses nothing. Its warnings go to the import's warnings (Run::WARNINGS, step static::STEP).
 */
abstract class Snapshot
{
    /** The system's name, as the texts show it. */
    public const NAME = '';
    /** mod_clientfence_state row of the read; also the warnings' step and the file's name. */
    public const STATE = '';
    public const STEP = '';
    public const FILE = '';
    /** The steps in order: name => label. */
    public const STEPS = [];

    /** The WHMCS tables the import reads, as WHMCS names their columns (untyped: SQLite keeps what it is given). */
    public const TABLES = [
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
        'tblproducts' => 'id INTEGER PRIMARY KEY, type TEXT, gid INTEGER, name TEXT, slug TEXT DEFAULT \'\', description TEXT DEFAULT \'\', hidden INTEGER DEFAULT 0, stockcontrol INTEGER DEFAULT 0, qty INTEGER DEFAULT 0, proratabilling INTEGER DEFAULT \'\', paytype TEXT, allowqty INTEGER DEFAULT 0, autosetup TEXT DEFAULT \'\', servertype TEXT DEFAULT \'\', servergroup INTEGER DEFAULT 0, configoption1 TEXT DEFAULT \'\', freedomain TEXT DEFAULT \'\', freedomainpaymentterms TEXT DEFAULT \'\', freedomaintlds TEXT DEFAULT \'\', recurringcycles INTEGER DEFAULT 0, autoterminatedays INTEGER DEFAULT 0, overagesenabled INTEGER DEFAULT \'\', "order" INTEGER DEFAULT 0, retired INTEGER DEFAULT 0, tax INTEGER DEFAULT 1',
        'tblpricing' => 'id INTEGER PRIMARY KEY, type TEXT, currency INTEGER, relid INTEGER, msetupfee REAL DEFAULT -1, qsetupfee REAL DEFAULT -1, ssetupfee REAL DEFAULT -1, asetupfee REAL DEFAULT -1, bsetupfee REAL DEFAULT -1, tsetupfee REAL DEFAULT 0, monthly REAL DEFAULT -1, quarterly REAL DEFAULT -1, semiannually REAL DEFAULT -1, annually REAL DEFAULT -1, biennially REAL DEFAULT -1, triennially REAL DEFAULT -1',
        'tbladdons' => 'id INTEGER PRIMARY KEY, packages TEXT, name TEXT, description TEXT, billingcycle TEXT, allowqty INTEGER, showorder INTEGER, hidden INTEGER, retired INTEGER, autoactivate TEXT, module TEXT, weight INTEGER, tax INTEGER DEFAULT 1',
        // Texts in other languages, as WHMCS's Dynamic Field Translation: related_type "product.{id}.name" with related_id.
        'tbldynamic_translations' => 'id INTEGER PRIMARY KEY, related_type TEXT, related_id INTEGER, language TEXT, translation TEXT',
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
        // The old system's ids that are not numbers, and lookups (dropped when the read finishes).
        'snapshot_id' => 'entity TEXT, uuid TEXT, id INTEGER, PRIMARY KEY (entity, uuid)',
        'snapshot_lookup' => 'kind TEXT, uuid TEXT, value TEXT, extra TEXT, PRIMARY KEY (kind, uuid)',
    ];

    /** Billing cycles in months => WHMCS cycle names. */
    protected const CYCLES = [1 => 'Monthly', 3 => 'Quarterly', 6 => 'Semi-Annually', 12 => 'Annually', 24 => 'Biennially', 36 => 'Triennially'];

    /** WHMCS tblpricing columns of those cycles. */
    protected const PRICE_COLUMNS = [1 => 'monthly', 3 => 'quarterly', 6 => 'semiannually', 12 => 'annually', 24 => 'biennially', 36 => 'triennially'];

    /** tblpricing columns of a domain price row by years (Catalogue::DOMAIN_YEARS). */
    protected const DOMAIN_COLUMNS = [1 => 'msetupfee', 2 => 'qsetupfee', 3 => 'ssetupfee', 4 => 'asetupfee', 5 => 'bsetupfee', 6 => 'monthly', 7 => 'quarterly', 8 => 'semiannually', 9 => 'annually', 10 => 'biennially'];

    /** @var list<string> */
    protected array $warnings = [];

    protected function __construct(protected readonly Connection $db)
    {
        if ($this->db->fetchOne("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'snapshot_id'") === 0) {
            foreach (self::TABLES as $table => $columns) {
                $this->db->executeStatement(sprintf('CREATE TABLE IF NOT EXISTS %s (%s)', $table, $columns));
            }
        }
    }

    /**
     * One page of a step: [the next offset or null when the step is done, rows read].
     *
     * @return array{0: ?int, 1: int}
     */
    abstract protected function page(string $step, int $offset): array;

    /** After the last step, before the id tables go. */
    abstract protected function finish(): void;

    /** After each read: lets go of what the snapshot reads from. */
    protected function close(): void
    {
    }

    /** Where the snapshot is kept: the private backups folder, outside the web root. */
    public static function path(): string
    {
        return \ClientFence\Mod\Clientfence\Backups::localDir() . '/' . static::FILE;
    }

    /** The snapshot, read only (Source::open()). */
    public static function open(): Connection
    {
        if (!is_file(static::path())) {
            throw new InformationException('Read :name first.', [':name' => static::NAME]);
        }
        $db = self::connect(static::path());
        $db->executeStatement('PRAGMA query_only = ON');

        return $db;
    }

    /** @return array<string, mixed>|null the last read's state */
    public static function state(\Pimple\Container $di): ?array
    {
        try {
            $row = $di['em']->getConnection()->fetchOne('SELECT value FROM mod_clientfence_state WHERE name = ?', [static::STATE]);
        } catch (\Throwable) {
            return null;
        }
        $value = is_string($row) ? json_decode($row, true) : null;

        return is_array($value) && is_file(static::path()) ? $value : null;
    }

    /**
     * Reads the system for up to $seconds (0: until done) with the snapshot $make builds on the
     * file; $restart starts again from nothing. Returns the state: [steps => [name => [label, done,
     * finished]], step, offset, finished_at].
     *
     * @param callable(Connection): static $make
     *
     * @return array<string, mixed>
     */
    protected static function read(\Pimple\Container $di, callable $make, float $seconds, bool $restart): array
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new InformationException('The :name import needs PHP\'s pdo_sqlite extension. Turn it on for this site\'s PHP version, then try again.', [':name' => static::NAME]);
        }
        $state = $restart ? null : static::state($di);
        if ($state === null) {
            @unlink(static::path());
            $dir = dirname(static::path());
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new InformationException('Could not make the folder :dir.', [':dir' => $dir]);
            }
            // Every client's data: readable by this site only.
            touch(static::path());
            chmod(static::path(), 0600);
            $state = ['started_at' => date('Y-m-d H:i:s'), 'finished_at' => null, 'step' => array_key_first(static::STEPS), 'offset' => 0, 'steps' => []];
            $di['em']->getConnection()->delete(Run::WARNINGS, ['source' => Map::SOURCE, 'step' => static::STEP]);
            foreach (static::STEPS as $step => $label) {
                $state['steps'][$step] = ['label' => $label, 'done' => 0, 'finished' => false];
            }
        } elseif ($state['finished_at'] !== null) {
            return $state;
        }
        $db = self::connect(static::path());
        $snapshot = null;
        $started = microtime(true);
        try {
            $snapshot = $make($db);
            foreach (array_keys(static::STEPS) as $step) {
                while (empty($state['steps'][$step]['finished'])) {
                    // The page is kept only once the state names the next one: a stop in between reads it again.
                    $db->beginTransaction();
                    try {
                        [$next, $done] = $snapshot->page($step, (int) $state['offset']);
                        $state['steps'][$step]['done'] += $done;
                        $state['steps'][$step]['finished'] = $next === null;
                        $state['step'] = $step;
                        $state['offset'] = $next ?? 0;
                        Run::addWarnings($di['em']->getConnection(), static::STEP, $snapshot->warnings);
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
            $snapshot->numbering();
            // The id tables go: the import would take them for an add-on module's.
            $db->executeStatement('DROP TABLE IF EXISTS snapshot_id');
            $db->executeStatement('DROP TABLE IF EXISTS snapshot_lookup');
            Run::addWarnings($di['em']->getConnection(), static::STEP, $snapshot->warnings);
            $state['finished_at'] = date('Y-m-d H:i:s');
            $state['step'] = null;
            self::save($di, $state);
        } finally {
            $snapshot?->close();
            $db->close();
        }

        return $state;
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
        if ($db->update('mod_clientfence_state', $row, ['name' => static::STATE]) === 0 && $db->fetchOne('SELECT 1 FROM mod_clientfence_state WHERE name = ?', [static::STATE]) === false) {
            $db->insert('mod_clientfence_state', $row + ['name' => static::STATE]);
        }
    }

    /** The invoice number format of the most used prefix ("Z-INV-{NUMBER}"), unless the system named one. */
    private function numbering(): void
    {
        if ($this->db->fetchOne("SELECT 1 FROM tblconfiguration WHERE setting = 'SequentialInvoiceNumberFormat'") !== false) {
            return;
        }
        $series = [];
        foreach ($this->db->fetchFirstColumn('SELECT invoicenum FROM tblinvoices') as $number) {
            $prefix = Money::number((string) $number)[0];
            $series[$prefix] = ($series[$prefix] ?? 0) + 1;
        }
        arsort($series);
        $this->db->insert('tblconfiguration', ['setting' => 'SequentialInvoiceNumberFormat', 'value' => (string) (array_key_first($series) ?? '') . '{NUMBER}']);
    }

    /** This snapshot's number for an id of the old system that is not a number, made on first use. */
    protected function id(string $entity, string $uuid): int
    {
        $id = $this->existing($entity, $uuid);
        if ($id !== null) {
            return $id;
        }
        $id = (int) $this->db->fetchOne('SELECT COALESCE(MAX(id), 0) + 1 FROM snapshot_id WHERE entity = ?', [$entity]);
        $this->db->insert('snapshot_id', ['entity' => $entity, 'uuid' => $uuid, 'id' => $id]);

        return $id;
    }

    protected function existing(string $entity, string $uuid): ?int
    {
        $id = $uuid === '' ? false : $this->db->fetchOne('SELECT id FROM snapshot_id WHERE entity = ? AND uuid = ?', [$entity, $uuid]);

        return $id === false ? null : (int) $id;
    }

    protected function lookup(string $kind, string $uuid, string $value, ?string $extra = null): void
    {
        $this->db->executeStatement('INSERT OR REPLACE INTO snapshot_lookup (kind, uuid, value, extra) VALUES (?, ?, ?, ?)', [$kind, $uuid, $value, $extra]);
    }

    protected function value(string $kind, string $uuid): ?string
    {
        $value = $uuid === '' ? false : $this->db->fetchOne('SELECT value FROM snapshot_lookup WHERE kind = ? AND uuid = ?', [$kind, $uuid]);

        return $value === false ? null : (string) $value;
    }

    protected function warn(string $message): void
    {
        $this->warnings[] = $message;
    }
}
