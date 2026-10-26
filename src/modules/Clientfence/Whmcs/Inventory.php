<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Whmcs;

/**
 * ClientFence: what a WHMCS install holds, for the import plan (Import.php): counts per area (with
 * the status breakdown WHMCS keeps), money still open, the add-on modules found and what happens to
 * them, the payment gateways and server modules in use, and what stands in the way.
 *
 * Add-on modules: WHMCS add-ons keep their data in their own tables (anything not named tbl…). A
 * module with a mapper here (MAPPERS) is imported into its ClientFence counterpart; every other
 * module is listed as "found, not imported" with its tables and row counts; the import keeps its
 * rows in an archive file, so nothing is lost and a mapper can be added later.
 */
final class Inventory
{
    /**
     * The areas the import covers: key => [group, label, table, status column or '']. Labels are
     * translated by the page.
     */
    public const AREAS = [
        'currencies' => ['clients', 'Currencies', 'tblcurrencies', ''],
        'client_groups' => ['clients', 'Client groups', 'tblclientgroups', ''],
        'clients' => ['clients', 'Clients', 'tblclients', 'status'],
        'users' => ['clients', 'Logins (users)', 'tblusers', ''],
        'user_links' => ['clients', 'Logins linked to clients', 'tblusers_clients', ''],
        'contacts' => ['clients', 'Contacts', 'tblcontacts', ''],
        'custom_fields' => ['clients', 'Custom fields', 'tblcustomfields', 'type'],
        'custom_values' => ['clients', 'Custom field answers', 'tblcustomfieldsvalues', ''],
        'social_logins' => ['clients', 'Social sign-in links', 'tblauthn_account_links', ''],
        'admins' => ['clients', 'Staff', 'tbladmins', ''],
        'product_groups' => ['catalogue', 'Product groups', 'tblproductgroups', ''],
        'products' => ['catalogue', 'Products', 'tblproducts', 'type'],
        'config_groups' => ['catalogue', 'Configurable option groups', 'tblproductconfiggroups', ''],
        'config_options' => ['catalogue', 'Configurable options', 'tblproductconfigoptions', 'optiontype'],
        'product_addons' => ['catalogue', 'Product add-ons', 'tbladdons', ''],
        'upgrade_paths' => ['catalogue', 'Upgrade paths', 'tblproduct_upgrade_products', ''],
        'promotions' => ['catalogue', 'Promotions', 'tblpromotions', 'type'],
        'tld_pricing' => ['catalogue', 'Domain extensions', 'tbldomainpricing', ''],
        'servers' => ['catalogue', 'Servers', 'tblservers', 'type'],
        'services' => ['services', 'Services', 'tblhosting', 'domainstatus'],
        'service_addons' => ['services', 'Service add-ons', 'tblhostingaddons', 'status'],
        'domains' => ['services', 'Domains', 'tbldomains', 'status'],
        'orders' => ['services', 'Orders', 'tblorders', 'status'],
        'cancel_requests' => ['services', 'Cancellation requests', 'tblcancelrequests', 'type'],
        'invoices' => ['billing', 'Invoices', 'tblinvoices', 'status'],
        'invoice_items' => ['billing', 'Invoice lines', 'tblinvoiceitems', ''],
        'transactions' => ['billing', 'Transactions', 'tblaccounts', ''],
        'credit_log' => ['billing', 'Credit log', 'tblcredit', ''],
        'billing_notes' => ['billing', 'Credit and debit notes', 'tblbillingnotes', ''],
        'quotes' => ['billing', 'Quotes', 'tblquotes', 'stage'],
        'departments' => ['support', 'Support departments', 'tblticketdepartments', ''],
        'tickets' => ['support', 'Tickets', 'tbltickets', 'status'],
        'ticket_replies' => ['support', 'Ticket replies', 'tblticketreplies', ''],
        'ticket_notes' => ['support', 'Ticket notes', 'tblticketnotes', ''],
        'predefined_replies' => ['support', 'Predefined replies', 'tblticketpredefinedreplies', ''],
        'kb_categories' => ['content', 'Knowledge base categories', 'tblknowledgebasecats', ''],
        'kb_articles' => ['content', 'Knowledge base articles', 'tblknowledgebase', ''],
        'announcements' => ['content', 'Announcements', 'tblannouncements', ''],
        'emails' => ['content', 'Sent emails', 'tblemails', ''],
    ];

    /** Groups in page order. */
    public const GROUPS = ['clients' => 'Clients and logins', 'catalogue' => 'Catalogue', 'services' => 'Services', 'billing' => 'Billing', 'support' => 'Support', 'content' => 'Content and history'];

    /**
     * Add-on modules with a mapper: name => [label, table patterns]. Their rows are imported into
     * ClientFence (licences into licence services with their check log and bans, Licensing.php).
     * Google sign-in links are WHMCS's own (tblauthn_account_links, Accounts.php). Every other
     * module's rows go to the archive file (Archive.php); PayPal billing agreements among them
     * (owner decision 2026-10-19: a module of its own later).
     */
    public const MAPPERS = [
        'licensing' => ['WHMCS Licensing (licence keys, check log and bans)', ['/^mod_licensing/']],
    ];

    /** Tables never archived: WHMCS's own (tbl…), caches and SQLite's own (tests). */
    private const SKIP = ['/^tbl/', '/cache/i', '/^sessions?$/i', '/^sqlite_/'];

    /**
     * @return array{version: string, major: int, supported: bool, areas: array<string, array{group: string, label: string, table: string, rows: int, by_status: array<string, int>}>, money: list<array{currency: string, unpaid_invoices: float, client_credit: float, paid_invoices: float}>, modules: list<array{module: string, label: string, handling: string, active: bool, tables: list<array{table: string, rows: int}>, rows: int}>, gateways: list<array{gateway: string, label: string, services: int, invoices: int}>, server_modules: list<array{module: string, products: int, services: int}>, warnings: list<string>}
     */
    public static function plan(Source $source): array
    {
        $areas = [];
        foreach (self::AREAS as $key => [$group, $label, $table, $status]) {
            $areas[$key] = [
                'group' => $group,
                'label' => $label,
                'table' => $table,
                'rows' => $source->count($table),
                'by_status' => $status !== '' ? $source->countBy($table, $status) : [],
            ];
        }

        $warnings = [];
        $major = $source->major();
        $supported = $major >= Source::SUPPORTED_MAJOR;
        if ($major === 0) {
            $warnings[] = 'The WHMCS version is unknown.';
        } elseif ($major < Source::OLDEST_MAJOR) {
            $warnings[] = 'WHMCS ' . $source->version() . ' is too old to import: update it to 8 or later first.';
        } elseif (!$supported) {
            $warnings[] = 'WHMCS ' . $source->version() . ' imports on a best effort basis: check the result carefully, or update WHMCS to 8 or later first.';
        }

        return [
            'version' => $source->version(),
            'major' => $major,
            'supported' => $supported,
            'areas' => $areas,
            'money' => self::money($source),
            'modules' => self::modules($source),
            'gateways' => self::gateways($source),
            'server_modules' => self::serverModules($source),
            'warnings' => $warnings,
        ];
    }

    /**
     * Unpaid invoices, client credit and paid invoices per currency (in that currency).
     *
     * @return list<array{currency: string, unpaid_invoices: float, client_credit: float, paid_invoices: float}>
     */
    private static function money(Source $source): array
    {
        if (!$source->hasTable('tblclients') || !$source->hasTable('tblcurrencies') || !$source->hasTable('tblinvoices')) {
            return [];
        }
        $out = [];
        foreach ($source->rows('SELECT id, code FROM tblcurrencies ORDER BY id') as $currency) {
            $id = (int) $currency['id'];
            $invoices = static fn (string $status): float => round((float) $source->value(
                'SELECT COALESCE(SUM(i.total), 0) FROM tblinvoices i JOIN tblclients c ON c.id = i.userid WHERE c.currency = ? AND i.status = ?',
                [$id, $status]
            ), 2);
            $out[] = [
                'currency' => (string) $currency['code'],
                'unpaid_invoices' => $invoices('Unpaid'),
                'client_credit' => round((float) $source->value('SELECT COALESCE(SUM(credit), 0) FROM tblclients WHERE currency = ?', [$id]), 2),
                'paid_invoices' => $invoices('Paid'),
            ];
        }

        return $out;
    }

    /**
     * The add-on modules: those WHMCS has switched on (tbladdonmodules) and the tables of any module
     * (not tbl…), grouped by module, with what the import does with them.
     *
     * @return list<array{module: string, label: string, handling: string, active: bool, tables: list<array{table: string, rows: int}>, rows: int}>
     */
    private static function modules(Source $source): array
    {
        $active = [];
        if ($source->hasTable('tbladdonmodules')) {
            foreach ($source->rows("SELECT DISTINCT module FROM tbladdonmodules WHERE setting = 'version'") as $row) {
                $active[strtolower((string) $row['module'])] = (string) $row['module'];
            }
        }

        $modules = [];
        foreach (array_keys($source->tables()) as $table) {
            if (self::matches($table, self::SKIP)) {
                continue;
            }
            $mapper = null;
            foreach (self::MAPPERS as $key => [, $patterns]) {
                if (self::matches($table, $patterns)) {
                    $mapper = $key;

                    break;
                }
            }
            $module = $mapper ?? self::moduleOf($table, array_keys($active));
            $modules[$module] ??= ['module' => $module, 'label' => $mapper !== null ? self::MAPPERS[$mapper][0] : ($active[$module] ?? $module), 'handling' => $mapper !== null ? 'import' : 'archive', 'active' => false, 'tables' => [], 'rows' => 0];
            $rows = $source->count($table);
            $modules[$module]['tables'][] = ['table' => $table, 'rows' => $rows];
            $modules[$module]['rows'] += $rows;
        }
        // Switched on without tables of its own (it keeps its settings in tbladdonmodules).
        foreach ($active as $key => $name) {
            $modules[$key] ??= ['module' => $key, 'label' => $name, 'handling' => 'settings', 'active' => true, 'tables' => [], 'rows' => 0];
            $modules[$key]['active'] = true;
        }
        foreach (self::MAPPERS as $key => $mapper) {
            if (isset($modules[$key])) {
                $modules[$key]['active'] = $modules[$key]['active'] || $modules[$key]['rows'] > 0;
            }
        }
        usort($modules, static fn (array $a, array $b): int => [$a['handling'] === 'import' ? 0 : 1, -$a['rows'], $a['module']] <=> [$b['handling'] === 'import' ? 0 : 1, -$b['rows'], $b['module']]);

        return $modules;
    }

    /**
     * The tables the import keeps in the archive file (Archive.php): those of every module that is
     * "Archived, not imported", by name.
     *
     * @return list<string>
     */
    public static function archiveTables(Source $source): array
    {
        $tables = [];
        foreach (array_keys($source->tables()) as $table) {
            $table = (string) $table;
            if (self::matches($table, self::SKIP)) {
                continue;
            }
            foreach (self::MAPPERS as [, $patterns]) {
                if (self::matches($table, $patterns)) {
                    continue 2;
                }
            }
            $tables[] = $table;
        }
        sort($tables);

        return $tables;
    }

    /**
     * The module a table belongs to: a switched-on module whose name starts the table, else the
     * table's prefix (up to its first underscore, without "mod_").
     *
     * @param list<string> $active lower-case module names
     */
    private static function moduleOf(string $table, array $active): string
    {
        $lower = strtolower($table);
        $bare = preg_replace('/^(mod_|mg_)/', '', $lower) ?? $lower;
        usort($active, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($active as $module) {
            if (str_starts_with($lower, $module) || str_starts_with($bare, $module)) {
                return $module;
            }
        }

        return explode('_', $bare)[0] ?: $lower;
    }

    /**
     * Payment gateways that are set up, with the services and invoices that use them.
     *
     * @return list<array{gateway: string, label: string, services: int, invoices: int}>
     */
    private static function gateways(Source $source): array
    {
        if (!$source->hasTable('tblpaymentgateways')) {
            return [];
        }
        $out = [];
        foreach ($source->rows("SELECT gateway, MAX(CASE WHEN setting = 'name' THEN value END) AS label FROM tblpaymentgateways GROUP BY gateway ORDER BY gateway") as $row) {
            $gateway = (string) $row['gateway'];
            $out[] = [
                'gateway' => $gateway,
                'label' => (string) ($row['label'] ?? '') !== '' ? (string) $row['label'] : $gateway,
                'services' => $source->count('tblhosting', 'paymentmethod = ?', [$gateway]),
                'invoices' => $source->count('tblinvoices', 'paymentmethod = ?', [$gateway]),
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$b['services'], $b['invoices']] <=> [$a['services'], $a['invoices']]);

        return $out;
    }

    /**
     * The server (provisioning) modules products use, with their services.
     *
     * @return list<array{module: string, products: int, services: int}>
     */
    private static function serverModules(Source $source): array
    {
        if (!$source->hasTable('tblproducts') || !in_array('servertype', $source->columns('tblproducts'), true)) {
            return [];
        }
        $out = [];
        foreach ($source->rows("SELECT p.servertype AS module, COUNT(DISTINCT p.id) AS products, COUNT(h.id) AS services FROM tblproducts p LEFT JOIN tblhosting h ON h.packageid = p.id WHERE p.servertype <> '' GROUP BY p.servertype ORDER BY services DESC, module") as $row) {
            $out[] = ['module' => (string) $row['module'], 'products' => (int) $row['products'], 'services' => (int) $row['services']];
        }

        return $out;
    }

    /** @param list<string> $patterns */
    private static function matches(string $table, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $table) === 1) {
                return true;
            }
        }

        return false;
    }
}
