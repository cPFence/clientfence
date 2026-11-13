<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Clientexec;

use ClientFence\Mod\Clientfence\Whmcs\Snapshot as Base;
use ClientFence\Mod\Clientfence\Whmcs\Source;
use Doctrine\DBAL\Connection;

/**
 * ClientFence: the ClientExec import. fetch() reads ClientExec's database (read only, as the
 * WHMCS import reads WHMCS's: Whmcs\Source) into the import's snapshot (Whmcs/Snapshot.php), a
 * page of rows at a time, keeping ClientExec's ids.
 *
 * ClientExec keeps a client's packages, hosting and domains alike, in its "domains" table, their
 * fields in object_customField and what renews in recurringfee. Passwords keep their hashes: the
 * WHMCS step keeps a hash PHP can check and names the others. Left out with a warning: two-factor
 * secrets, coupons, package add-ons, recurring charges that are not a package's, ticket
 * attachments, refunds and billing periods WHMCS has no name for (weeks, 4 or more years).
 */
final class Snapshot extends Base
{
    public const NAME = 'ClientExec';
    public const STATE = 'clientexec_fetch';
    public const STEP = 'clientexec';
    public const FILE = 'clientexec-import.sqlite';
    /** Rows read per page. */
    public const PAGE = 200;

    public const STEPS = [
        'setup' => 'Currencies, taxes, groups and departments',
        'packages' => 'Packages and domain prices',
        'clients' => 'Clients',
        'services' => 'Services and domains',
        'invoices' => 'Invoices',
        'payments' => 'Payments',
        'tickets' => 'Tickets',
    ];

    /** ClientExec's client statuses (USER_STATUS_*): pending clients can pay, so they are active. */
    private const CLIENT_STATUSES = [0 => 'Active', 1 => 'Active', -1 => 'Inactive', -2 => 'Closed', -3 => 'Closed'];

    /** Package statuses (PACKAGE_STATUS_*); 4 is a pending cancellation, which waits in Cancellation Requests. */
    private const SERVICE_STATUSES = [0 => 'Pending', 1 => 'Active', 2 => 'Suspended', 3 => 'Cancelled', 4 => 'Active', 5 => 'Terminated'];

    private const DOMAIN_STATUSES = [0 => 'Pending', 1 => 'Active', 2 => 'Active', 3 => 'Cancelled', 4 => 'Active', 5 => 'Expired'];

    /** Invoice statuses seen in ClientExec 7; 5 is partly paid. */
    private const INVOICE_STATUSES = [-1 => 'Draft', 0 => 'Unpaid', 1 => 'Paid', 2 => 'Cancelled', 5 => 'Unpaid'];

    /** TICKET_STATUS_*: unassigned, open, waiting on staff, waiting on the client, closed. */
    private const TICKET_STATUSES = [0 => 'Open', 1 => 'Open', 2 => 'In Progress', 3 => 'Answered', -1 => 'Closed'];

    private const URGENCY = [1 => 'High', 2 => 'Medium', 3 => 'Low'];

    /** customuserfields types of the client's details. */
    private const FIELDS = ['address1' => 2, 'city' => 3, 'state' => 4, 'postcode' => 5, 'country' => 6, 'phonenumber' => 7, 'language' => 8, 'tax_id' => 47];

    /** @var array<int, ?int>|null billing_cycle ids => months (null: weeks or days) */
    private ?array $cycles = null;

    /**
     * Reads ClientExec for up to $seconds (0: until done); $restart starts again from nothing.
     * $settings are the import settings (ClientExec's database); tests hand in their own $source.
     *
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    public static function fetch(\Pimple\Container $di, array $settings, float $seconds = 0.0, bool $restart = false, ?Source $source = null): array
    {
        return self::read($di, static fn (Connection $db): self => new self($source ?? Source::mysql($di, $settings, 'ClientExec'), $db), $seconds, $restart);
    }

    /** Whether a database is ClientExec's: its client, package, invoice and setting tables. */
    public static function isClientexec(Source $source): bool
    {
        return $source->hasTable('users') && $source->hasTable('recurringfee') && $source->hasTable('invoiceentry') && $source->hasTable('setting');
    }

    private function __construct(private readonly Source $cexec, Connection $db)
    {
        parent::__construct($db);
    }

    protected function close(): void
    {
        $this->cexec->close();
    }

    protected function page(string $step, int $offset): array
    {
        if ($step === 'setup') {
            return [null, $this->setup()];
        }
        // Clients are the users of a group that is not staff's.
        $sql = [
            'packages' => 'SELECT p.*, g.type AS group_type FROM package p LEFT JOIN promotion g ON g.id = p.planid WHERE p.id > ? ORDER BY p.id',
            'clients' => 'SELECT u.* FROM users u JOIN `groups` g ON g.id = u.groupid WHERE g.isadmin = 0 AND u.id > ? ORDER BY u.id',
            'services' => 'SELECT * FROM domains WHERE id > ? ORDER BY id',
            'invoices' => 'SELECT * FROM invoice WHERE id > ? ORDER BY id',
            'payments' => 'SELECT t.*, i.customerid, i.pluginused, i.checknum FROM invoicetransaction t JOIN invoice i ON i.id = t.invoiceid WHERE t.id > ? ORDER BY t.id',
            'tickets' => 'SELECT * FROM troubleticket WHERE id > ? ORDER BY id',
        ][$step];
        $rows = $this->cexec->rows($sql . ' LIMIT ' . self::PAGE, [$offset]);
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

    private function setting(string $name): string
    {
        $value = $this->cexec->value('SELECT value FROM setting WHERE name = ?', [$name]);

        return $value === false ? '' : (string) $value;
    }

    private function setup(): int
    {
        $this->db->insert('tblconfiguration', ['setting' => 'Version', 'value' => '8.0.0 (ClientExec ' . ($this->setting('ClientExec Version') ?: '?') . ')']);
        $this->db->insert('tblconfiguration', ['setting' => 'SequentialInvoiceNumbering', 'value' => 'on']);
        $default = $this->setting('Default Currency') ?: 'USD';
        foreach ($this->cexec->rows('SELECT * FROM currency WHERE enabled = 1 OR abrv = ? ORDER BY abrv', [$default]) as $c) {
            $left = $c['alignment'] !== 'right';
            $this->db->insert('tblcurrencies', [
                'code' => (string) $c['abrv'], 'prefix' => $left ? (string) $c['symbol'] : '', 'suffix' => $left ? '' : (string) $c['symbol'],
                'format' => (int) $c['precision'] === 0 ? 4 : 2, 'rate' => (float) $c['rate'] ?: 1, '"default"' => $c['abrv'] === $default ? 1 : 0,
            ]);
        }
        foreach ($this->cexec->rows('SELECT * FROM taxrule ORDER BY level, id') as $t) {
            $this->db->insert('tbltax', [
                'id' => (int) $t['id'], 'level' => (string) $t['level'], 'name' => (string) $t['name'],
                'state' => $t['state'] === '_ALL' ? '' : (string) $t['state'], 'country' => $t['countryiso'] === '_ALL' ? '' : (string) $t['countryiso'], 'taxrate' => (float) $t['tax'],
            ]);
        }
        // ClientExec taxes a taxable client on its tax rules; prices never include the tax.
        if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM tbltax') > 0) {
            $this->db->insert('tblconfiguration', ['setting' => 'TaxEnabled', 'value' => 'on']);
            $this->db->insert('tblconfiguration', ['setting' => 'TaxType', 'value' => 'Exclusive']);
            $this->db->insert('tblconfiguration', ['setting' => 'TaxDomains', 'value' => $this->setting('Domains Taxable') === '1' ? 'on' : '']);
        }
        // Client groups, but not the one every client is in.
        foreach ($this->cexec->rows('SELECT * FROM `groups` WHERE isadmin = 0 AND iscustomersmaingroup = 0 ORDER BY id') as $g) {
            $this->db->insert('tblclientgroups', ['id' => (int) $g['id'], 'groupname' => (string) $g['name'], 'groupcolour' => (string) ($g['groupcolor'] ?? ''), 'discountpercent' => 0, 'susptermexempt' => '', 'separateinvoices' => '']);
        }
        // Product groups; the domain groups' packages are domain extensions.
        foreach ($this->cexec->rows('SELECT * FROM promotion WHERE type <> 3 ORDER BY groupOrder, id') as $i => $g) {
            $this->db->insert('tblproductgroups', ['id' => (int) $g['id'], 'name' => (string) $g['name'] ?: 'Products', 'slug' => '', 'hidden' => 0, '"order"' => $i]);
        }
        foreach ($this->cexec->rows('SELECT * FROM departments ORDER BY id') as $d) {
            $this->db->insert('tblticketdepartments', ['id' => (int) $d['id'], 'name' => (string) $d['name']]);
        }
        $counts = [
            'coupons' => $this->cexec->count('coupons', 'coupons_archive = 0'),
            'recurring charges that are not a package\'s or an add-on\'s (discounts and others)' => $this->cexec->count('recurringfee', 'billingtypeid <> -1 AND recurring = 1 AND packageaddon_prices_id = 0'),
            'ticket attachments' => $this->cexec->count('troubleticket_files'),
        ];
        foreach (array_filter($counts) as $what => $count) {
            $this->warn(sprintf('ClientExec has %d %s: they were not imported.', $count, $what));
        }
        $addons = $this->cexec->count('packageaddon');
        if ($addons > 0) {
            $this->warn(sprintf('ClientExec has %d package add-ons: the add-ons your clients have were imported with their packages, but new orders do not offer them; add them as add-on products.', $addons));
        }

        return 1;
    }

    /** @param array<string, mixed> $p a package with its group's type (3: domains) */
    private function package(array $p): void
    {
        $id = (int) $p['id'];
        $name = (string) $p['planname'];
        $prices = [];
        foreach ($this->cexec->rows('SELECT * FROM prices WHERE type = 1 AND itemid = ? ORDER BY currency_abrv, billing_cycle, field', [$id]) as $row) {
            $prices[(string) $row['currency_abrv']][(string) $row['billing_cycle']][(string) $row['field']] = (string) ($row['value'] ?? '');
        }
        if ((int) ($p['group_type'] ?? 0) === 3) {
            // A domain extension: its prices by years.
            $extension = '.' . ltrim(strtolower(trim($name)), '.');
            $this->db->insert('tbldomainpricing', ['id' => $id, 'extension' => $extension]);
            $unbilled = [];
            foreach ($prices as $code => $cycles) {
                $rows = [];
                foreach ($cycles as $cycle => $fields) {
                    if (($fields['price'] ?? '') === '') {
                        continue;
                    }
                    $years = $this->years((int) $cycle);
                    if ($years === null) {
                        $unbilled[$cycle] = sprintf('Domain extension %s: its %s price was not imported (ClientFence sells domains for 1 to 10 years).', $extension, $this->cycleName((int) $cycle));

                        continue;
                    }
                    $column = self::DOMAIN_COLUMNS[$years];
                    $rows['domainregister'][$column] = (float) $fields['price'];
                    $rows['domainrenew'][$column] = (float) (($fields['renew'] ?? '') !== '' ? $fields['renew'] : $fields['price']);
                    if (($fields['transfer'] ?? '') !== '') {
                        $rows['domaintransfer'][$column] = (float) $fields['transfer'];
                    }
                }
                foreach ($rows as $type => $columns) {
                    $this->db->insert('tblpricing', ['type' => $type, 'currency' => $this->currency($code), 'relid' => $id] + $columns);
                }
            }
            array_map($this->warn(...), array_values($unbilled));

            return;
        }
        // The cycles a package is sold on: included, with a price.
        $offered = [];
        foreach ($prices as $code => $cycles) {
            foreach ($cycles as $cycle => $fields) {
                if ($cycle !== 'all' && ($fields['included'] ?? '0') === '1' && ($fields['price'] ?? '') !== '') {
                    $offered[$code][(int) $cycle] = $fields;
                }
            }
        }
        $onetime = $offered !== [] && array_filter($offered, static fn (array $c): bool => array_keys($c) !== [0]) === [];
        $taxable = array_filter($prices, static fn (array $c): bool => ($c['all']['taxable'] ?? '0') === '1') !== [];
        $this->db->insert('tblproducts', [
            'id' => $id, 'type' => 'other', 'gid' => (int) $p['planid'], 'name' => $name !== '' ? $name : 'Package #' . $id,
            'description' => (string) $p['description'], 'hidden' => (int) $p['showpackage'] === 1 ? 0 : 1,
            'paytype' => $onetime ? 'onetime' : 'recurring', 'tax' => $taxable ? 1 : 0,
        ]);
        $unbilled = [];
        foreach ($offered as $code => $cycles) {
            $row = ['type' => 'product', 'currency' => $this->currency($code), 'relid' => $id, 'msetupfee' => 0, 'qsetupfee' => 0, 'ssetupfee' => 0, 'asetupfee' => 0, 'bsetupfee' => 0, 'tsetupfee' => 0];
            foreach ($cycles as $cycle => $fields) {
                $months = $cycle === 0 ? 0 : $this->months($cycle);
                if ($months === 0 && !$onetime) {
                    $unbilled['onetime'] = sprintf('Package "%s": its one-time price was not imported (the package also renews, and ClientFence bills a product either once or on renewal).', $name);

                    continue;
                }
                if ($months === null) {
                    // Once per cycle, not once per currency it is priced in.
                    $unbilled[$cycle] = sprintf('Package "%s": its %s price was not imported (ClientFence bills 1, 3, 6, 12, 24 or 36 months, or once).', $name, $this->cycleName($cycle));

                    continue;
                }
                $column = $months === 0 ? 'monthly' : self::PRICE_COLUMNS[$months];
                $row[$column] = (float) $fields['price'];
                $row[['monthly' => 'msetupfee', 'quarterly' => 'qsetupfee', 'semiannually' => 'ssetupfee', 'annually' => 'asetupfee', 'biennially' => 'bsetupfee', 'triennially' => 'tsetupfee'][$column]] = (float) ($fields['setup'] ?? 0);
            }
            $this->db->insert('tblpricing', $row);
        }
        array_map($this->warn(...), array_values($unbilled));
    }

    /** A billing cycle in months as WHMCS bills it: 0 for one time, null when WHMCS has no name for it. */
    private function months(int $cycle): ?int
    {
        $months = $this->cycle($cycle);

        return $months === 0 || isset(self::PRICE_COLUMNS[(int) $months]) ? $months : null;
    }

    /** A domain term in years as ClientFence sells it (1 to 10), else null. */
    private function years(int $cycle): ?int
    {
        $months = (int) $this->cycle($cycle);

        return $months > 0 && $months % 12 === 0 && isset(self::DOMAIN_COLUMNS[intdiv($months, 12)]) ? intdiv($months, 12) : null;
    }

    /** A billing cycle in months (0: one time; null: weeks or days). */
    private function cycle(int $cycle): ?int
    {
        if ($this->cycles === null) {
            $this->cycles = [];
            foreach ($this->cexec->rows('SELECT * FROM billing_cycle') as $c) {
                $this->cycles[(int) $c['id']] = match ((string) $c['time_unit']) {
                    'm' => (int) $c['amount_of_units'],
                    'y' => (int) $c['amount_of_units'] * 12,
                    default => null,
                };
            }
        }
        return $this->cycles[$cycle] ?? null;
    }

    /**
     * ClientExec's own number of a status: a custom status (statusalias; type 1 packages, 2 tickets,
     * 3 clients) stands for one of them.
     *
     * @param array<int, string> $known
     */
    private function systemStatus(int $type, int $status, array $known): int
    {
        if (isset($known[$status])) {
            return $status;
        }
        $alias = $this->cexec->value('SELECT aliasto FROM statusalias WHERE type = ? AND statusid = ? AND aliasto IS NOT NULL', [$type, $status]);

        return $alias === false ? $status : (int) $alias;
    }

    private function cycleName(int $cycle): string
    {
        return (string) ($this->cexec->value('SELECT name FROM billing_cycle WHERE id = ?', [$cycle]) ?: $cycle . '-cycle');
    }

    /** @param array<string, mixed> $u */
    private function client(array $u): void
    {
        $id = (int) $u['id'];
        $fields = [];
        $twoFactor = false;
        foreach ($this->cexec->rows('SELECT f.type, f.name, v.value FROM user_customuserfields v JOIN customuserfields f ON f.id = v.customid WHERE v.userid = ?', [$id]) as $f) {
            $fields[(int) $f['type']] = trim((string) ($f['value'] ?? ''));
            $twoFactor = $twoFactor || ($f['name'] === 'TwoFactor Enabled' && (string) $f['value'] === '1');
        }
        $email = trim((string) $u['email']);
        $code = trim((string) $u['currency']);
        $created = self::day($u['dateActivated'] ?? null) ?: (self::day($u['created_at'] ?? null) ?: date('Y-m-d'));
        $group = $this->cexec->value('SELECT ug.group_id FROM user_groups ug JOIN `groups` g ON g.id = ug.group_id WHERE ug.user_id = ? AND g.isadmin = 0 AND g.iscustomersmaingroup = 0 ORDER BY ug.group_id LIMIT 1', [$id]);
        $status = $this->systemStatus(3, (int) $u['status'], self::CLIENT_STATUSES);
        $password = (string) ($u['password'] ?? '');
        $this->db->insert('tblclients', [
            'id' => $id, 'firstname' => (string) $u['firstname'], 'lastname' => (string) $u['lastname'], 'companyname' => (string) $u['organization'], 'email' => $email,
            'address1' => $fields[self::FIELDS['address1']] ?? '', 'address2' => '', 'city' => $fields[self::FIELDS['city']] ?? '', 'state' => $fields[self::FIELDS['state']] ?? '',
            'postcode' => $fields[self::FIELDS['postcode']] ?? '', 'country' => strtoupper($fields[self::FIELDS['country']] ?? ''), 'phonenumber' => $fields[self::FIELDS['phonenumber']] ?? '',
            'tax_id' => $fields[self::FIELDS['tax_id']] ?? '', 'password' => $password,
            // ClientExec's "Language" field ("English"): the WHMCS import maps it to a locale this install ships (Accounts::locale).
            'language' => $fields[self::FIELDS['language']] ?? '',
            'currency' => $this->currency($code !== '' && $code !== '0' ? $code : ($this->setting('Default Currency') ?: 'USD')),
            'taxexempt' => (int) $u['taxable'] === 1 ? 0 : 1, 'datecreated' => $created, 'created_at' => $created . ' 00:00:00',
            'groupid' => $group === false ? 0 : (int) $group, 'status' => self::CLIENT_STATUSES[$status] ?? 'Active',
            // ClientExec's credit balance; the WHMCS step makes it the opening line of the credit history.
            'credit' => max(0.0, round((float) $u['balance'], 2)),
        ]);
        if ($status === -3) {
            $this->warn(sprintf('Client #%d is marked as fraud in ClientExec: it was imported as Closed.', $id));
        }
        // The hash as ClientExec keeps it: the WHMCS step keeps one PHP can check and names the others.
        $this->db->insert('tblusers', ['id' => $id, 'first_name' => (string) $u['firstname'], 'last_name' => (string) $u['lastname'], 'email' => $email, 'password' => $password, 'created_at' => $created . ' 00:00:00']);
        $this->db->insert('tblusers_clients', ['auth_user_id' => $id, 'client_id' => $id, 'owner' => 1, 'created_at' => $created . ' 00:00:00']);
        if ($twoFactor) {
            $this->warn(sprintf('Client %s used two-factor sign-in in ClientExec, which could not be carried over: it signs in with its password until two-factor is set up again.', $email));
        }
        if (trim((string) ($u['usernotes'] ?? '')) !== '') {
            $this->db->insert('tblnotes', ['userid' => $id, 'adminid' => 0, 'created' => $created . ' 00:00:00', 'modified' => $created . ' 00:00:00', 'note' => trim((string) $u['usernotes']), 'sticky' => 0]);
        }
        foreach ($this->cexec->rows('SELECT * FROM clients_notes WHERE target_id = ? AND is_target_group = 0 ORDER BY id', [$id]) as $note) {
            $this->db->insert('tblnotes', ['userid' => $id, 'adminid' => 0, 'created' => (string) $note['date'], 'modified' => (string) $note['date'], 'note' => trim((string) $note['subject'] . "\n\n" . (string) $note['note']), 'sticky' => 0]);
        }
    }

    /** @param array<string, mixed> $d a client's package (ClientExec's "domains" row) */
    private function service(array $d): void
    {
        $id = (int) $d['id'];
        $client = (int) $d['CustomerID'];
        if ($this->db->fetchOne('SELECT 1 FROM tblclients WHERE id = ?', [$client]) === false) {
            return;
        }
        $fields = [];
        foreach ($this->cexec->rows('SELECT c.subGroupId, c.name, o.value FROM object_customField o JOIN customField c ON c.id = o.customFieldId WHERE c.groupId = 2 AND o.objectid = ?', [$id]) as $f) {
            $fields[$f['subGroupId'] . ':' . $f['name']] = trim((string) ($f['value'] ?? ''));
        }
        $fee = $this->cexec->rows('SELECT * FROM recurringfee WHERE appliestoid = ? AND billingtypeid = -1 AND packageaddon_prices_id = 0 ORDER BY id LIMIT 1', [$id])[0] ?? null;
        $amount = $fee !== null ? round((float) $fee['amount'] * (float) $fee['quantity'], 2) : ((int) $d['use_custom_price'] === 1 ? (float) $d['custom_price'] : 0.0);
        $once = $fee === null || (int) $fee['recurring'] === 0;
        $months = $once ? 0 : $this->months((int) $fee['paymentterm']);
        $status = $this->systemStatus(1, (int) $d['status'], self::SERVICE_STATUSES);
        $start = self::day($d['dateActivated'] ?? null) ?: date('Y-m-d');
        $due = self::day($fee['nextbilldate'] ?? null) ?: $start;
        $cancel = $status === 4 ? $this->cancellation($id, $fields) : null;
        $package = $this->cexec->rows('SELECT p.planname, g.type FROM package p LEFT JOIN promotion g ON g.id = p.planid WHERE p.id = ?', [(int) $d['Plan']])[0] ?? null;
        if ($package !== null && (int) $package['type'] === 3) {
            $domain = strtolower($fields['3:Domain Name'] ?? '');
            $years = $once ? 1 : $this->years((int) $fee['paymentterm']);
            if ($years === null) {
                $this->warn(sprintf('Domain package #%d (%s) of client #%d was not imported: it renews every %s, which ClientFence does not bill.', $id, $domain, $client, $this->cycleName((int) $fee['paymentterm'])));

                return;
            }
            $this->db->insert('tbldomains', [
                'id' => $id, 'userid' => $client, 'registrationdate' => $start, 'domain' => $domain, 'firstpaymentamount' => $amount, 'recurringamount' => $amount,
                'registrationperiod' => $years,
                'expirydate' => self::day($fields['3:Expiration Date'] ?? null) ?: $due, 'nextduedate' => $due,
                'status' => self::DOMAIN_STATUSES[$status] ?? 'Pending', 'donotrenew' => $cancel !== null ? 1 : 0,
            ]);
            if ($domain === '') {
                $this->warn(sprintf('Domain package #%d of client #%d has no domain name in ClientExec.', $id, $client));
            }
            if ($this->cexec->count('recurringfee', 'appliestoid = ? AND packageaddon_prices_id > 0', [$id]) > 0) {
                $this->warn(sprintf('Domain package #%d (%s) of client #%d has add-ons in ClientExec: they were not imported (ClientFence links add-ons to services only).', $id, $domain, $client));
            }

            return;
        }
        if ($months === null) {
            $this->warn(sprintf('Package #%d of client #%d was not imported: it renews every %s, which ClientFence does not bill.', $id, $client, $this->cycleName((int) $fee['paymentterm'])));

            return;
        }
        $this->db->insert('tblhosting', [
            'id' => $id, 'userid' => $client, 'packageid' => (int) $d['Plan'], 'regdate' => $start,
            'domain' => $fields['1:Domain Name'] ?? '', 'username' => $fields['1:User Name'] ?? '',
            'firstpaymentamount' => $amount, 'amount' => $amount, 'billingcycle' => $months === 0 ? 'One Time' : self::CYCLES[$months],
            'nextduedate' => $due, 'domainstatus' => self::SERVICE_STATUSES[$status] ?? 'Pending',
        ]);
        // A pending cancellation: the WHMCS step puts it in Cancellation Requests, so it does not renew.
        if ($cancel !== null) {
            $this->db->insert('tblcancelrequests', ['date' => $cancel['date'], 'relid' => $id, 'reason' => $cancel['reason'], 'type' => 'End of Billing Period']);
        }
        $this->addons($id, $client, self::SERVICE_STATUSES[$status] ?? 'Pending', $start);
    }

    /**
     * A package's add-on charges (a chosen value of a ClientExec add-on) as WHMCS service add-ons: the WHMCS step
     * links each to the package's service, with its own price, cycle and due date.
     */
    private function addons(int $service, int $client, string $status, string $start): void
    {
        $fees = $this->cexec->rows(
            'SELECT f.*, a.name, v.detail FROM recurringfee f LEFT JOIN packageaddon_prices v ON v.id = f.packageaddon_prices_id LEFT JOIN packageaddon a ON a.id = v.packageaddon_id WHERE f.appliestoid = ? AND f.packageaddon_prices_id > 0 ORDER BY f.id',
            [$service],
        );
        foreach ($fees as $fee) {
            $title = implode(': ', array_filter([trim((string) ($fee['name'] ?? '')), trim((string) ($fee['detail'] ?? ''))])) ?: trim((string) ($fee['description'] ?? '')) ?: 'Add-on';
            $months = (int) $fee['recurring'] === 0 ? 0 : $this->months((int) $fee['paymentterm']);
            if ($months === null) {
                $this->warn(sprintf('Add-on "%s" of package #%d was not imported: it renews every %s, which ClientFence does not bill.', $title, $service, $this->cycleName((int) $fee['paymentterm'])));

                continue;
            }
            $this->db->insert('tblhostingaddons', [
                'id' => (int) $fee['id'], 'orderid' => 0, 'hostingid' => $service, 'addonid' => 0, 'userid' => $client, 'name' => $title,
                'qty' => max(1, (int) $fee['quantity']), 'recurring' => round((float) $fee['amount'] * max(1.0, (float) $fee['quantity']), 2),
                'billingcycle' => $months === 0 ? 'One Time' : self::CYCLES[$months], 'status' => $status,
                'regdate' => $start, 'nextduedate' => self::day($fee['nextbilldate'] ?? null) ?: $start, 'termination_date' => null, 'notes' => '', 'subscriptionid' => '',
            ]);
        }
    }

    /**
     * A pending cancellation's date and reason: its request, else the package's cancellation fields.
     *
     * @param array<string, string> $fields
     *
     * @return array{date: string, reason: string}
     */
    private function cancellation(int $service, array $fields): array
    {
        $request = $this->cexec->hasTable('cancellations') ? ($this->cexec->rows('SELECT * FROM cancellations WHERE userpackage_id = ? ORDER BY id DESC LIMIT 1', [$service])[0] ?? []) : [];

        return [
            'date' => (string) (($request['requested_at'] ?? null) ?: ((self::day($fields['0:Cancellation Request Date'] ?? null) ?: date('Y-m-d')) . ' 00:00:00')),
            'reason' => trim((string) ($request['reason'] ?? '')) ?: (($fields['0:Cancellation Reason'] ?? '') ?: 'Cancellation requested in ClientExec'),
        ];
    }

    /** @param array<string, mixed> $i */
    private function invoice(array $i): void
    {
        $id = (int) $i['id'];
        $client = (int) $i['customerid'];
        if ($this->db->fetchOne('SELECT 1 FROM tblclients WHERE id = ?', [$client]) === false) {
            return;
        }
        $this->billedIn($client, (string) $i['currency'], sprintf('invoice #%d', $id));
        $lines = $this->cexec->rows('SELECT * FROM invoiceentry WHERE invoiceid = ? ORDER BY id', [$id]);
        $subtotal = round((float) $i['subtotal'], 2);
        $total = round((float) $i['amount'], 2);
        $tax = round(max(0, $total - $subtotal), 2);
        // ClientExec keeps the rate on the invoice; with a second tax, the rate that gives its tax on the taxed lines.
        $taxed = array_sum(array_map(static fn (array $l): float => (int) $l['taxable'] === 1 ? (float) $l['price'] * (float) $l['quantity'] : 0.0, $lines));
        $rate = match (true) {
            $tax <= 0 => 0.0,
            (float) $i['tax2'] <= 0 => (float) $i['tax'],
            default => $taxed > 0 ? round($tax / $taxed * 100, 4) : 0.0,
        };
        $status = self::INVOICE_STATUSES[(int) $i['status']] ?? null;
        if ($status === null) {
            $this->warn(sprintf('Invoice #%d has a status ClientExec numbers %d, which the import does not know: it was imported as Cancelled, check it.', $id, (int) $i['status']));
            $status = 'Cancelled';
        }
        $this->db->insert('tblinvoices', [
            'id' => $id, 'userid' => $client, 'invoicenum' => (string) $id,
            'date' => self::day($i['datecreated'] ?? null) ?: self::day($i['billdate']), 'duedate' => self::day($i['billdate']) ?: self::day($i['datecreated'] ?? null),
            'datepaid' => $status === 'Paid' ? (self::day($i['datepaid'] ?? null) ?: self::day($i['billdate'])) . ' 00:00:00' : '0000-00-00 00:00:00',
            'subtotal' => $subtotal, 'tax' => $tax, 'total' => $total, 'taxrate' => $rate, 'status' => $status, 'notes' => (string) $i['note'],
        ]);
        $renews = [];
        foreach ($lines as $line) {
            $service = (int) $line['appliestoid'];
            // An add-on charge's line renews that add-on (its fee is the imported add-on's id).
            $fee = (int) ($line['recurringappliesto'] ?? 0);
            $addon = $fee > 0 && $this->db->fetchOne('SELECT 1 FROM tblhostingaddons WHERE id = ?', [$fee]) !== false;
            if ($addon) {
                $service = $fee;
            }
            // Paying a package's line renews it: only its first package line on the invoice.
            $type = match (true) {
                $addon => isset($renews['addon:' . $fee]) ? '' : 'Addon',
                (int) $line['billingtypeid'] !== -1 || $service === 0 || isset($renews[$service]) => '',
                $this->db->fetchOne('SELECT 1 FROM tbldomains WHERE id = ?', [$service]) !== false => 'Domain',
                $this->db->fetchOne('SELECT 1 FROM tblhosting WHERE id = ?', [$service]) !== false => 'Hosting',
                default => '',
            };
            $renews[$type === 'Addon' ? 'addon:' . $fee : $service] = true;
            $qty = (float) $line['quantity'];
            $detail = trim((string) $line['detail']);
            $this->db->insert('tblinvoiceitems', [
                'invoiceid' => $id, 'userid' => $client, 'type' => $type, 'relid' => $type === '' ? 0 : $service,
                'description' => ($qty != 1.0 ? rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.') . ' x ' : '') . (string) $line['description'] . ($detail !== '' ? "\n" . $detail : ''),
                'amount' => round($qty * (float) $line['price'], 2), 'taxed' => (int) $line['taxable'] === 1 ? 1 : 0,
            ]);
        }
    }

    /** @param array<string, mixed> $t a transaction with its invoice's client, gateway and reference */
    private function payment(array $t): void
    {
        $invoice = (int) $t['invoiceid'];
        if ((int) $t['accepted'] !== 1 || $this->db->fetchOne('SELECT 1 FROM tblinvoices WHERE id = ?', [$invoice]) === false) {
            return;
        }
        if ($t['action'] === 'refund') {
            $this->warn(sprintf('Payment #%d of invoice #%d is a refund in ClientExec: it was not imported.', (int) $t['id'], $invoice));

            return;
        }
        $reference = (string) $t['transactionid'] !== '' && $t['transactionid'] !== 'NA' ? (string) $t['transactionid'] : ((string) $t['checknum'] ?: 'clientexec-' . $t['id']);
        $this->db->insert('tblaccounts', [
            'userid' => (int) $t['customerid'], 'gateway' => $t['pluginused'] === 'none' ? '' : strtolower((string) $t['pluginused']),
            'date' => (string) $t['transactiondate'], 'description' => 'Invoice Payment', 'amountin' => round((float) $t['amount'], 2), 'transid' => $reference, 'invoiceid' => $invoice,
        ]);
    }

    /** @param array<string, mixed> $t */
    private function ticket(array $t): void
    {
        $id = (int) $t['id'];
        $client = (int) $t['userid'];
        $who = $client > 0 ? $this->db->fetchAssociative('SELECT firstname, lastname, email FROM tblclients WHERE id = ?', [$client]) : false;
        if ($who === false) {
            $client = 0;
        }
        $first = null;
        $email = $who ? (string) $who['email'] : '';
        // Messages only (log type 0): ClientExec's other log rows are events (status, attachments).
        foreach ($this->cexec->rows('SELECT l.*, u.firstname, u.lastname, g.isadmin FROM troubleticket_log l LEFT JOIN users u ON u.id = l.userid LEFT JOIN `groups` g ON g.id = u.groupid WHERE l.troubleticketid = ? AND l.logtype = 0 ORDER BY l.mydatetime, l.id', [$id]) as $r) {
            $staff = (int) ($r['isadmin'] ?? 0) === 1 ? (trim($r['firstname'] . ' ' . $r['lastname']) ?: 'Staff') : '';
            $email = $email ?: (string) $r['email'];
            if ((int) $r['private'] === 1) {
                $this->db->insert('tblticketnotes', ['ticketid' => $id, 'admin' => $staff ?: 'Staff', 'date' => (string) $r['mydatetime'], 'message' => (string) $r['message']]);
            } elseif ($first === null) {
                $first = ['message' => (string) $r['message'], 'admin' => $staff];
            } else {
                $this->db->insert('tblticketreplies', [
                    'tid' => $id, 'userid' => $staff !== '' ? 0 : $client, 'name' => $staff !== '' ? '' : ($who ? trim($who['firstname'] . ' ' . $who['lastname']) : ''),
                    'email' => $staff !== '' ? '' : ($who ? (string) $who['email'] : (string) $r['email']), 'date' => (string) $r['mydatetime'], 'message' => (string) $r['message'], 'admin' => $staff,
                ]);
            }
        }
        $status = self::TICKET_STATUSES[$this->systemStatus(2, (int) $t['status'], self::TICKET_STATUSES)] ?? 'Open';
        $department = (int) $t['assignedtodeptid'] ?: (int) $this->cexec->value('SELECT id FROM departments ORDER BY is_generaldep DESC, id LIMIT 1');
        $service = (int) ($t['domainid'] ?? 0);
        $this->db->insert('tbltickets', [
            'id' => $id, 'status' => $status, 'tid' => (string) $id, 'did' => $department, 'userid' => $client,
            'name' => $who ? trim($who['firstname'] . ' ' . $who['lastname']) : '', 'email' => $email, 'c' => bin2hex(random_bytes(4)),
            'date' => (string) $t['datesubmitted'], 'title' => (string) $t['subject'], 'message' => (string) ($first['message'] ?? ''),
            'urgency' => self::URGENCY[(int) $t['priority']] ?? 'Medium', 'admin' => (string) ($first['admin'] ?? ''),
            'lastreply' => self::day($t['lastlog_datetime']) !== '' ? (string) $t['lastlog_datetime'] : (string) $t['datesubmitted'],
            'service' => $service === 0 ? '' : ($this->db->fetchOne('SELECT 1 FROM tbldomains WHERE id = ?', [$service]) !== false ? 'D' : 'S') . $service,
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
    private function billedIn(int $client, string $code, string $what): void
    {
        $currency = $this->db->fetchOne('SELECT cur.code FROM tblclients c JOIN tblcurrencies cur ON cur.id = c.currency WHERE c.id = ?', [$client]);
        if ($code !== '' && $code !== '0' && $currency !== false && $currency !== $code) {
            $this->warn(sprintf('Client #%d is billed in %s, but its %s is in %s in ClientExec: it is imported in %s.', $client, (string) $currency, $what, $code, (string) $currency));
        }
    }

    /** The WHMCS currency id of a code (ClientExec's enabled currencies are read in setup). */
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
