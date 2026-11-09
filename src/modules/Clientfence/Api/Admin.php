<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Api;

use ClientFence\Mod\Clientfence\Backups;
use ClientFence\Mod\Clientfence\Branding;
use ClientFence\Mod\Clientfence\CspReports;
use ClientFence\Mod\Clientfence\Customized;
use ClientFence\Mod\Clientfence\Health;
use ClientFence\Mod\Clientfence\Notifications;
use ClientFence\Mod\Clientfence\SecuritySettings;
use ClientFence\Mod\Clientfence\Service;
use ClientFence\Mod\Clientfence\SettingsIndex;
use ClientFence\Mod\Clientfence\Setup;
use ClientFence\Mod\Clientfence\SignInSecurity;
use ClientFence\Mod\Clientfence\Updates;
use ClientFence\Mod\Clientfence\Whmcs\Import as WhmcsImport;
use ClientFence\Mod\Clientfence\Whmcs\Run as WhmcsRun;
use ClientFence\InformationException;

class Admin extends \ClientFence\Api\AbstractApi
{
    /**
     * The admin dashboard's figures that FOSSBilling's own calls do not give (ClientFence admin
     * frame, themes/admin_default/html_custom): domains whose active order expires within 30 days
     * (with the Orders view permission), unpaid invoices past their due date (with the Invoices view
     * permission), the last cron run and the email queue (with the System settings permission). A
     * part the staff member may not see is null.
     *
     * @return array{domains_expiring: ?int, invoices_overdue: ?int, cron_last: ?string, email_queue: ?int}
     */
    public function dashboard_get(): array
    {
        $di = $this->getDi();
        // The hourly health check (cron, command-line PHP) reads the web server's speed settings.
        Health::noteWebSpeed($di);
        $db = $di['em']->getConnection();
        $can = function (string $mod, ?string $key = null): bool {
            try {
                $this->checkPermissions($mod, $key);

                return true;
            } catch (\Throwable) {
                return false;
            }
        };
        $out = ['domains_expiring' => null, 'invoices_overdue' => null, 'cron_last' => null, 'email_queue' => null];
        if ($can('order', 'view')) {
            $out['domains_expiring'] = (int) $db->fetchOne(
                "SELECT COUNT(*) FROM service_domain d JOIN client_order o ON o.service_type = 'domain' AND o.service_id = d.id
                 WHERE o.status = 'active' AND d.expires_at BETWEEN NOW() AND NOW() + INTERVAL 30 DAY"
            );
        }
        if ($can('invoice', 'view')) {
            $out['invoices_overdue'] = (int) $db->fetchOne("SELECT COUNT(*) FROM invoice WHERE status = 'unpaid' AND due_at < NOW()");
        }
        if ($can('system', 'manage_settings')) {
            $last = $di['mod_service']('system')->getParamValue('last_cron_exec');
            $out['cron_last'] = is_string($last) && $last !== '' ? $last : null;
            $out['email_queue'] = (int) $db->fetchOne('SELECT COUNT(*) FROM email_queue');
        }

        return $out;
    }

    /**
     * Today hour by hour for the dashboard chart (FOSSBilling's stats series are per day): one
     * point per hour from 00:00 to the current hour, as [timestamp in ms, value] like
     * stats_get_income. `metric`: income (default), clients, orders, invoices or tickets, counted
     * as FOSSBilling's stats_get_summary_income and stats_get_summary count "today" (income:
     * approved, paid or refunded, refunds taken off; the others: created today), so the hours add
     * up to its figure. Needs the Statistics view permission, as FOSSBilling's own stats calls,
     * and for a count the view permission of its module, as the dashboard offers it.
     *
     * @return array<int, array{0: int, 1: float|int}>
     */
    public function dashboard_today($data): array
    {
        $this->checkPermissions('stats', 'view');
        $metric = $data['metric'] ?? 'income';
        // metric => [table, module whose view permission it needs]
        $counts = ['clients' => ['client', 'client'], 'orders' => ['client_order', 'order'], 'invoices' => ['invoice', 'invoice'], 'tickets' => ['support_ticket', 'support']];
        if ($metric !== 'income' && !isset($counts[$metric])) {
            throw new InformationException('Unknown metric.');
        }
        $db = $this->getDi()['em']->getConnection();
        if ($metric === 'income') {
            $rows = $db->fetchAllKeyValue(
                "SELECT HOUR(paid_at) AS h, COALESCE(SUM(base_income), 0) - COALESCE(SUM(base_refund), 0)
                   FROM invoice
                  WHERE approved = 1 AND (status = 'paid' OR status = 'refunded') AND DATE(paid_at) = CURDATE()
                  GROUP BY h"
            );
        } else {
            [$table, $module] = $counts[$metric];
            $this->checkPermissions($module, 'view');
            $rows = $db->fetchAllKeyValue("SELECT HOUR(created_at) AS h, COUNT(1) FROM `{$table}` WHERE DATE(created_at) = CURDATE() GROUP BY h");
        }
        $midnight = (int) strtotime('today');
        $last = max(1, (int) date('G'));
        $points = [];
        for ($hour = 0; $hour <= $last; ++$hour) {
            $points[] = [($midnight + $hour * 3600) * 1000, $metric === 'income' ? round((float) ($rows[$hour] ?? 0), 2) : (int) ($rows[$hour] ?? 0)];
        }

        return $points;
    }

    /**
     * Income for the dashboard's Billing card: today, this month, this year and all time, counted as
     * FOSSBilling's stats_get_summary_income counts it (approved invoices, paid or refunded, refunds
     * taken off, by paid date, in the default currency), so today, this month and all time match its
     * figures. Needs the Statistics and Invoices view permissions.
     *
     * @return array{today: float, this_month: float, this_year: float, total: float}
     */
    public function dashboard_billing(): array
    {
        $this->checkPermissions('stats', 'view');
        $this->checkPermissions('invoice', 'view');
        $sum = static fn (string $when): string => "COALESCE(SUM(CASE WHEN {$when} THEN base_income END), 0) - COALESCE(SUM(CASE WHEN {$when} THEN base_refund END), 0)";
        $row = $this->getDi()['em']->getConnection()->fetchAssociative(
            'SELECT ' . $sum('DATE(paid_at) = CURDATE()') . ' AS today, '
                . $sum("DATE_FORMAT(paid_at, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')") . ' AS this_month, '
                . $sum('YEAR(paid_at) = YEAR(CURDATE())') . ' AS this_year, '
                . $sum('TRUE') . " AS total
               FROM invoice
              WHERE approved = 1 AND (status = 'paid' OR status = 'refunded')"
        ) ?: [];

        return array_map(static fn ($v): float => round((float) $v, 2), [
            'today' => $row['today'] ?? 0,
            'this_month' => $row['this_month'] ?? 0,
            'this_year' => $row['this_year'] ?? 0,
            'total' => $row['total'] ?? 0,
        ]);
    }

    /**
     * The all-time footer of the dashboard's four key figures (the totals themselves come from
     * stats_get_summary and stats_get_summary_income). Needs the Statistics view permission, as
     * the key figures.
     * - `active_clients`: clients with the Active status (client_get_statuses);
     * - `pending_orders`: orders pending setup (order_get_statuses);
     * - `unpaid`: what the unpaid invoices ask for, tax included, in the default currency;
     * - `mrr`: monthly recurring revenue in the default currency: every order FOSSBilling will
     *   renew with an invoice (active, "issue invoice", a billing period and an expiry date, not
     *   set to cancel at the end of the period), at its renewal amount as FOSSBilling bills it
     *   (domains at the TLD's renewal price, a recurring promo taken off, before tax), divided by
     *   the months of its period (yearly ÷ 12, quarterly ÷ 3…); orders that renew for nothing
     *   count 0. `mrr_orders`: the orders that count.
     *
     * @return array{active_clients: int, pending_orders: int, unpaid: float, mrr: float, mrr_orders: int}
     */
    public function dashboard_totals(): array
    {
        $this->checkPermissions('stats', 'view');
        $di = $this->getDi();
        $db = $di['em']->getConnection();
        $currencyService = $di['mod_service']('currency');

        $unpaid = 0.0;
        // Per invoice as Invoice\Service::getTotalWithTax: items price × quantity, plus the
        // invoice's tax rate on the taxed items, rounded to cents.
        $owed = $db->fetchAllKeyValue(
            "SELECT t.currency, SUM(t.total + t.tax)
               FROM (SELECT i.currency,
                            SUM(ii.price * ii.quantity) AS total,
                            CASE WHEN CAST(i.taxrate AS DECIMAL(20, 10)) > 0
                                 THEN ROUND(CAST(SUM(CASE WHEN ii.taxed THEN ii.price * ii.quantity ELSE 0 END) AS DECIMAL(20, 2)) * CAST(i.taxrate AS DECIMAL(20, 10)) / 100, 2) + CAST(i.tax_adjust AS DECIMAL(20, 2))
                                 ELSE 0 END AS tax
                       FROM invoice i JOIN invoice_item ii ON ii.invoice_id = i.id
                      WHERE i.status = 'unpaid'
                      GROUP BY i.id, i.currency, i.taxrate, i.tax_adjust) t
              GROUP BY t.currency"
        );
        foreach ($owed as $code => $amount) {
            $unpaid += $currencyService->toBaseCurrency((string) $code, (float) $amount);
        }

        $productService = $di['mod_service']('product');
        $mrr = 0.0;
        $counted = 0;
        $orders = $di['db']->find(
            'ClientOrder',
            "status = 'active' AND invoice_option = 'issue-invoice' AND period IS NOT NULL AND period <> '' AND expires_at IS NOT NULL
             AND NOT EXISTS (SELECT 1 FROM client_order_meta m WHERE m.client_order_id = client_order.id AND m.name = ? AND m.value = '1')",
            [\ClientFence\Mod\Order\Service::META_CANCEL_AT_PERIOD_END]
        );
        foreach ($orders as $order) {
            try {
                $price = (float) $order->price;
                $quantity = (float) $order->quantity;
                $product = $productService->findProductById((int) $order->product_id);
                if ($product->getType() === \ClientFence\Mod\Product\Service::DOMAIN) {
                    // As Invoice\Service::generateForOrder: the TLD's renewal price in the order's currency
                    // (with its fixed prices, Product/CurrencyPrices.php).
                    $line = $productService->inCurrency((string) $order->currency)->getProductRenewalLineConfig($product, json_decode($order->config ?? '', true) ?? []);
                    $price = $productService->hasFreeDomainRenewal((int) $order->id) ? 0.0 : (float) $line['price'] * (float) $currencyService->getCurrencyRepository()->getRateByCode($order->currency);
                    $quantity = (float) $line['quantity'];
                }
                $amount = $price * $quantity - (float) ($productService->getRenewalPromoAdjustment($order, $price, $quantity)['discount_amount'] ?? 0);
                $months = (float) (new \ClientFence\Period((string) $order->period))->getMonths();
                if ($amount <= 0 || $months <= 0) {
                    continue;
                }
                $mrr += $currencyService->toBaseCurrency((string) $order->currency, $amount) / $months;
                ++$counted;
            } catch (\Throwable) {
                // An order FOSSBilling could not renew either (product or TLD gone, bad period);
                // not logged, as the dashboard asks on every view.
            }
        }

        return [
            'active_clients' => (int) $db->fetchOne("SELECT COUNT(*) FROM client WHERE status = 'active'"),
            'pending_orders' => (int) $db->fetchOne("SELECT COUNT(*) FROM client_order WHERE status = 'pending_setup'"),
            'unpaid' => round($unpaid, 2),
            'mrr' => round($mrr, 2),
            'mrr_orders' => $counted,
        ];
    }

    /**
     * The dashboard's Latest orders: the 5 newest orders by order date, the same rows as
     * order_get_list (which sorts by id, so records imported later with their old dates would
     * come first), addons hidden when the order settings hide them. Needs the Orders view
     * permission, as order_get_list.
     *
     * @return list<array<string, mixed>>
     */
    public function dashboard_latest_orders(): array
    {
        $this->checkPermissions('order', 'view');
        $di = $this->getDi();
        $config = $di['mod']('order')->getConfig();
        $hideAddons = !(isset($config['show_addons']) && $config['show_addons']);
        $ids = $di['em']->getConnection()->fetchFirstColumn(
            'SELECT id FROM client_order' . ($hideAddons ? ' WHERE group_master = 1' : '') . ' ORDER BY created_at DESC, id DESC LIMIT 5'
        );

        return $di['mod_service']('order')->getBatchForApi(array_map('intval', $ids), $this->getIdentity());
    }

    /**
     * The dashboard's Latest invoices: the 5 newest invoices by invoice date, the same rows as
     * invoice_get_list (which sorts by id). Needs the Invoices view permission, as
     * invoice_get_list.
     *
     * @return list<array<string, mixed>>
     */
    public function dashboard_latest_invoices(): array
    {
        $this->checkPermissions('invoice', 'view');
        $di = $this->getDi();
        $service = $di['mod_service']('invoice');
        $out = [];
        foreach ($di['em']->getConnection()->fetchFirstColumn('SELECT id FROM invoice ORDER BY created_at DESC, id DESC LIMIT 5') as $id) {
            $out[] = $service->toApiArray($di['db']->getExistingModelById('Invoice', (int) $id, 'Invoice not found'), true, $this->getIdentity());
        }

        return $out;
    }

    /**
     * The dashboard's Client activity card. `active`: clients with the Active status (as
     * client_get_statuses). FOSSBilling keeps no online state, so a client is "seen" when they
     * signed in (login history) or did something logged as themselves (client activity log, not
     * staff acting on the client), each source only with its permission (client "view login
     * history", activity view). `active_hour`: clients seen in the last hour; `recent`: the 6
     * latest clients seen in the last 30 days, one row per client with the IP of that moment.
     * Both are null/empty when neither source is allowed. Needs the Clients view permission.
     *
     * @return array{active: int, active_hour: ?int, recent: list<array{id: int, name: string, company: string, ip: string, at: string}>}
     */
    public function dashboard_clients(): array
    {
        $this->checkPermissions('client', 'view');
        $di = $this->getDi();
        $db = $di['em']->getConnection();
        $can = function (string $mod, string $key): bool {
            try {
                $this->checkPermissions($mod, $key);

                return true;
            } catch (\Throwable) {
                return false;
            }
        };
        $out = ['active' => (int) ($di['mod_service']('client')->counter()['active'] ?? 0), 'active_hour' => null, 'recent' => []];
        $sources = [];
        if ($can('client', 'view_login_history')) {
            $sources[] = 'SELECT client_id, ip, created_at AS at FROM activity_client_history WHERE created_at >= ?';
        }
        if ($can('activity', 'view')) {
            $sources[] = 'SELECT client_id, ip, created_at AS at FROM activity_system WHERE client_id IS NOT NULL AND admin_id IS NULL AND created_at >= ?';
        }
        if ($sources === []) {
            return $out;
        }
        $seen = implode(' UNION ALL ', $sources);
        $since = static fn (int $seconds): array => array_fill(0, count($sources), date('Y-m-d H:i:s', time() - $seconds));
        $out['active_hour'] = (int) $db->fetchOne("SELECT COUNT(DISTINCT s.client_id) FROM ({$seen}) s JOIN client c ON c.id = s.client_id", $since(3600));
        $rows = $db->fetchAllAssociative(
            "SELECT t.client_id, t.ip, t.at, c.first_name, c.last_name, c.company
               FROM (SELECT s.client_id, s.ip, s.at, ROW_NUMBER() OVER (PARTITION BY s.client_id ORDER BY s.at DESC) AS n FROM ({$seen}) s) t
               JOIN client c ON c.id = t.client_id
              WHERE t.n = 1
              ORDER BY t.at DESC, t.client_id DESC
              LIMIT 6",
            $since(30 * 86400)
        );
        foreach ($rows as $row) {
            $out['recent'][] = [
                'id' => (int) $row['client_id'],
                'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                'company' => (string) $row['company'],
                'ip' => (string) $row['ip'],
                'at' => (string) $row['at'],
            ];
        }

        return $out;
    }

    /**
     * The last system health run (Health.php: checks, counts, rating, ran_at), or null before the
     * first. Needs the System settings permission, as the dashboard's System health card.
     */
    public function health_get(): ?array
    {
        $this->checkPermissions('system', 'manage_settings');

        return Health::latest($this->getDi());
    }

    /**
     * The daily release check: on or off, and the last check.
     *
     * @return array{enabled: bool, channel: string, checked_at: ?string, error: ?string, feed: string}
     */
    public function update_check_get(): array
    {
        $this->checkPermissions('system', 'view');
        $last = Updates::last($this->getDi());

        return ['enabled' => Updates::enabled($this->getDi()), 'channel' => Updates::channel($this->getDi()), 'checked_at' => $last['checked_at'], 'error' => $last['error'], 'feed' => Updates::feedUrl()];
    }

    /**
     * The release files changed on this site, which an update overwrites (Customized.php):
     * `checked` false when the installed version has no file list.
     *
     * @return array{checked: bool, files: list<string>}
     */
    public function update_changes(): array
    {
        $this->checkPermissions('system', 'system_update');
        $files = Customized::files();

        return ['checked' => $files !== null, 'files' => $files ?? []];
    }

    /**
     * Switches the release channel (`channel` stable or beta; beta is opt in, not for production)
     * and checks the feeds again, so System → Update shows the release of the new channel.
     */
    public function update_channel_save($data): bool
    {
        $this->checkPermissions('system', 'system_update');
        $channel = (string) ($data['channel'] ?? '');
        Updates::setChannel($this->getDi(), $channel);
        $this->getDi()['logger']->info('ClientFence: the release channel was set to %s', $channel);
        Updates::check($this->getDi(), true);

        return true;
    }

    /**
     * Turns the daily release check on or off (`enabled` 1 or 0).
     */
    public function update_check_save($data): bool
    {
        $this->checkPermissions('system', 'system_update');
        $on = !empty($data['enabled']);
        Updates::setEnabled($this->getDi(), $on);
        $this->getDi()['logger']->info('ClientFence: the daily release check was turned %s', $on ? 'on' : 'off');

        return true;
    }

    /**
     * The "System Health" tab of System settings: the last run (or null) and the check groups.
     */
    public function health_page(): array
    {
        $this->checkPermissions('system', 'manage_settings');

        return ['report' => Health::latest($this->getDi()), 'groups' => Health::groups()];
    }

    /**
     * Runs the system health checks now and saves the result (the cron runs them hourly). First clears
     * the script policy reports the last run already showed, so only scripts blocked since then warn.
     */
    public function health_run(): array
    {
        $this->checkPermissions('system', 'manage_settings');
        $this->getDi()['logger']->info('ClientFence: system health checks run by staff');
        // Script policy warnings come from stored browser reports: a staff run clears those the last run showed.
        $started = Health::latest($this->getDi())['started_at'] ?? null;
        if ($started !== null) {
            // Only what came in before that run read the reports; a second's margin keeps a report of that same second.
            CspReports::clear((int) strtotime((string) $started) - 1);
        }

        return Health::run($this->getDi());
    }

    /**
     * The dashboard cards below the key figures in their default columns (as WHMCS: each
     * card right under the one above it). The template names the same ids.
     */
    private const DASHBOARD_CARDS = [['income', 'billing', 'paypal', 'orders', 'activity'], ['products', 'health', 'invoices', 'clients']];

    /**
     * The signed-in staff member's dashboard card order: `cols`, two columns of card ids; the order
     * a Super Administrator saved (dashboard_layout_save) or the default. A card the saved order
     * lacks (added by a later pack) joins its default column after the card it follows there; an unknown id is left out.
     * `can_arrange`: a Super Administrator with ClientFence on; `saved`: an order of their own.
     *
     * @return array{cols: list<list<string>>, can_arrange: bool, saved: bool}
     */
    public function dashboard_layout_get(): array
    {
        $canArrange = $this->canArrangeDashboard();
        $saved = null;
        if ($canArrange) {
            Service::ensureTables($this->getDi(), true);
            $value = $this->getDi()['em']->getConnection()->fetchOne('SELECT value FROM mod_clientfence_state WHERE name = ?', [$this->dashboardLayoutKey()]);
            $saved = is_string($value) ? json_decode($value, true) : null;
        }

        return ['cols' => self::dashboardLayout(is_array($saved) ? $saved : null), 'can_arrange' => $canArrange, 'saved' => is_array($saved)];
    }

    /**
     * Saves the signed-in Super Administrator's dashboard card order in their account. `layout`:
     * JSON of two columns of card ids (dashboard_layout_get's `cols`).
     */
    public function dashboard_layout_save($data): bool
    {
        if (!$this->canArrangeDashboard()) {
            throw new InformationException('Only Super Administrators can arrange the dashboard.');
        }
        $cols = json_decode((string) ($data['layout'] ?? ''), true);
        if (!is_array($cols) || !array_is_list($cols) || count($cols) !== 2 || !is_array($cols[0]) || !is_array($cols[1])) {
            throw new InformationException('The card order could not be read.');
        }
        $di = $this->getDi();
        Service::ensureTables($di, true);
        $di['em']->getConnection()->executeStatement(
            'INSERT INTO mod_clientfence_state (name, value, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
            [$this->dashboardLayoutKey(), json_encode(self::dashboardLayout($cols)), date('Y-m-d H:i:s')]
        );

        return true;
    }

    /**
     * Puts the signed-in Super Administrator's dashboard back in the default card order.
     */
    public function dashboard_layout_reset(): bool
    {
        if (!$this->canArrangeDashboard()) {
            throw new InformationException('Only Super Administrators can arrange the dashboard.');
        }
        Service::ensureTables($this->getDi(), true);
        $this->getDi()['em']->getConnection()->executeStatement('DELETE FROM mod_clientfence_state WHERE name = ?', [$this->dashboardLayoutKey()]);

        return true;
    }

    /** Whether the signed-in staff member may manage database backups (a Super Administrator). */
    public function backup_access(): bool
    {
        return $this->canArrangeDashboard();
    }

    /**
     * The "Sign-in security" tab of System settings (SignInSecurity.php): the settings, every
     * country, and the address and country of this request. Super Administrators only.
     */
    public function signin_security_get(): array
    {
        $this->requireSuperAdmin();

        return SignInSecurity::forPage($this->getDi(), (string) $this->getIp());
    }

    /**
     * Saves sign-in security: `pin` (off, staff, clients, both), `countries_on` and `countries`
     * (ISO codes) for staff sign-in. Turning the staff PIN on answers {confirm: true, email} and
     * sends a PIN to this admin; the same save with `confirm_pin` finishes it.
     */
    public function signin_security_save($data): array
    {
        $this->requireSuperAdmin();

        $admin = $this->getIdentity();

        return SignInSecurity::save($this->getDi(), (array) $data, ['id' => (int) $admin->id, 'email' => (string) $admin->email, 'name' => (string) $admin->name, 'timezone' => (string) ($admin->timezone ?? '')], (string) $this->getIp());
    }

    /**
     * Security → Settings, the config.php parts (SecuritySettings.php): proxies, access lists and
     * sessions, with this request's connecting and visitor addresses. Super Administrators only.
     */
    public function security_settings_get(): array
    {
        $this->requireSuperAdmin('Only Super Administrators can change security settings.');

        return SecuritySettings::forPage($this->getDi());
    }

    /** Saves one `section` of Security → Settings: proxies, access or sessions. */
    public function security_settings_save($data): bool
    {
        $this->requireSuperAdmin('Only Super Administrators can change security settings.');
        SecuritySettings::save($this->getDi(), (array) $data, (int) $this->adminId());

        return true;
    }

    /**
     * The WHMCS import (Whmcs/Import.php): the database details without secrets, the last plan
     * and whether this install can take an import. Super Administrators only: the import reads
     * every client's data.
     */
    public function whmcs_import_get(): array
    {
        $this->requireSuperAdmin('Only Super Administrators can import from WHMCS.');

        return WhmcsImport::forStaff($this->getDi());
    }

    /**
     * Saves and tests the WHMCS database details: host, port, database, username, password and
     * encryption_key (each empty one keeps the saved one), attachments_dir, paypal_agreements (1 imports WHMCS PayPal billing agreements). Answers {version}.
     */
    public function whmcs_import_save($data): array
    {
        $this->requireSuperAdmin('Only Super Administrators can import from WHMCS.');
        $result = WhmcsImport::save($this->getDi(), (array) $data);
        $this->getDi()['logger']->info('ClientFence: WHMCS import database details saved (WHMCS %s)', $result['version']);

        return $result;
    }

    /**
     * Imports from Upmind instead of WHMCS: saves and tests the API token, upmind_token (empty keeps
     * the saved one). Answers {clients}.
     */
    public function whmcs_import_upmind_save($data): array
    {
        $this->requireSuperAdmin('Only Super Administrators can import from WHMCS.');
        $result = WhmcsImport::saveUpmind($this->getDi(), (array) $data);
        $this->getDi()['logger']->info('ClientFence: Upmind import token saved (%d clients)', $result['clients']);

        return $result;
    }

    /**
     * Reads Upmind for a few seconds (Upmind/Snapshot.php) and answers how far it got; restart = 1
     * reads it again from the start. The tab calls it until it finishes.
     */
    public function whmcs_import_upmind_fetch($data): array
    {
        $this->requireSuperAdmin('Only Super Administrators can import from WHMCS.');

        return WhmcsImport::fetchUpmind($this->getDi(), 8.0, (string) ($data['restart'] ?? '0') === '1');
    }

    /**
     * Imports from a Blesta database instead of WHMCS: saves and tests its details and system key,
     * blesta_key (empty password or key keeps the saved one). Answers {version, clients}.
     */
    public function whmcs_import_blesta_save($data): array
    {
        $this->requireSuperAdmin('Only Super Administrators can import from WHMCS.');
        $result = WhmcsImport::saveBlesta($this->getDi(), (array) $data);
        $this->getDi()['logger']->info('ClientFence: Blesta import database details saved (%d clients)', $result['clients']);

        return $result;
    }

    /**
     * Reads Blesta for a few seconds (Blesta/Snapshot.php) and answers how far it got; restart = 1
     * reads it again from the start. The tab calls it until it finishes.
     */
    public function whmcs_import_blesta_fetch($data): array
    {
        $this->requireSuperAdmin('Only Super Administrators can import from WHMCS.');

        return WhmcsImport::fetchBlesta($this->getDi(), 8.0, (string) ($data['restart'] ?? '0') === '1');
    }

    /** Imports from a ClientExec database instead of WHMCS: saves and tests its details. Answers {version, clients}. */
    public function whmcs_import_clientexec_save($data): array
    {
        $this->requireSuperAdmin('Only Super Administrators can import from WHMCS.');
        $result = WhmcsImport::saveClientexec($this->getDi(), (array) $data);
        $this->getDi()['logger']->info('ClientFence: ClientExec import database details saved (%d clients)', $result['clients']);

        return $result;
    }

    /**
     * Reads ClientExec for a few seconds (Clientexec/Snapshot.php) and answers how far it got;
     * restart = 1 reads it again from the start. The tab calls it until it finishes.
     */
    public function whmcs_import_clientexec_fetch($data): array
    {
        $this->requireSuperAdmin('Only Super Administrators can import from WHMCS.');

        return WhmcsImport::fetchClientexec($this->getDi(), 8.0, (string) ($data['restart'] ?? '0') === '1');
    }

    /** Reads WHMCS (read only) and stores the import plan: what WHMCS holds and what happens to it. */
    public function whmcs_import_plan(): array
    {
        $this->requireSuperAdmin('Only Super Administrators can import from WHMCS.');

        return WhmcsImport::plan($this->getDi());
    }

    /**
     * Imports from WHMCS for a few seconds (Whmcs/Run.php) and answers the run: the steps with
     * their counts, the warnings and whether it finished. The tab calls it until it finishes.
     */
    public function whmcs_import_apply(): array
    {
        $this->requireSuperAdmin('Only Super Administrators can import from WHMCS.');
        $state = WhmcsRun::apply($this->getDi(), 8.0);
        if (!empty($state['finished_at'])) {
            $this->getDi()['logger']->info('ClientFence: WHMCS import finished');
        }

        return $state;
    }

    /** Compares what WHMCS holds with what was imported (Whmcs/Run.php). */
    public function whmcs_import_verify(): array
    {
        $this->requireSuperAdmin('Only Super Administrators can import from WHMCS.');

        return WhmcsRun::verify($this->getDi());
    }

    /**
     * Database backups (Backups.php): the settings without the password, and the last run.
     * Backups hold the whole database, so every backup call is for Super Administrators only.
     */
    public function backup_get(): array
    {
        $this->requireSuperAdmin();

        return Backups::forStaff($this->getDi());
    }

    /**
     * The SFTP/FTP details: host, port, username, password (empty keeps the saved one), path
     * (relative to the login folder), secure (SFTP), passive. `do`: "test" only tests the posted
     * details (the saved ones and their switch stay as they are), "activate" tests them, saves them
     * and turns uploads on (WHMCS "Save & Activate").
     */
    public function backup_ftp_save($data): array|bool
    {
        $this->requireSuperAdmin();
        $di = $this->getDi();
        if (($data['do'] ?? '') !== 'activate') {
            Backups::testFtp($di, Backups::ftpFrom($di, (array) $data, Backups::settings($di)['ftp']));

            // The form stays as typed (no reload), ready for "Save & Activate".
            return ['tested' => true];
        }
        Backups::saveFtp($di, (array) $data, true);
        $di['logger']->info('ClientFence: database backup upload settings saved');

        return true;
    }

    /** Saves the address backups are emailed to; `do` "activate" turns emailed backups on. */
    public function backup_email_save($data): bool
    {
        $this->requireSuperAdmin();
        Backups::saveEmail($this->getDi(), (array) $data, ($data['do'] ?? '') === 'activate');
        $this->getDi()['logger']->info('ClientFence: database backup email settings saved');

        return true;
    }

    /**
     * Saves how many `days` of backups this server keeps; `do` "activate" makes the folder and
     * turns keeping them on.
     */
    public function backup_local_save($data): bool
    {
        $this->requireSuperAdmin();
        Backups::saveLocal($this->getDi(), (array) $data, ($data['do'] ?? '') === 'activate');
        $this->getDi()['logger']->info('ClientFence: database backup local settings saved');

        return true;
    }

    /** Turns a backup method off: `method` ftp, email or local (kept files stay). */
    public function backup_deactivate($data): bool
    {
        $this->requireSuperAdmin();
        Backups::deactivate($this->getDi(), (string) ($data['method'] ?? ''));

        return true;
    }

    /** Makes a backup now and sends it by every method that is on (the cron does it daily). */
    public function backup_run(): array
    {
        $this->requireSuperAdmin();
        $this->getDi()['logger']->info('ClientFence: database backup run by staff');

        return Backups::run($this->getDi());
    }

    private function requireSuperAdmin(string $message = 'Only Super Administrators can manage database backups.'): void
    {
        $adminId = $this->adminId();
        if ($adminId === null || !$this->getDi()['mod_service']('staff')->isSuperAdministrator($adminId)) {
            throw new InformationException($message);
        }
    }

    /** A Super Administrator: arranges the dashboard and manages database backups. */
    private function canArrangeDashboard(): bool
    {
        $adminId = $this->adminId();

        return $adminId !== null && $this->getDi()['mod_service']('staff')->isSuperAdministrator($adminId);
    }

    private function dashboardLayoutKey(): string
    {
        return 'dashboard_layout:' . $this->adminId();
    }

    /**
     * Two columns of known card ids, each once: the given order, with every card it lacks put in its
     * default column after the card it follows by default (first when it comes first).
     *
     * @return list<list<string>>
     */
    private static function dashboardLayout(?array $cols): array
    {
        $known = array_merge(...self::DASHBOARD_CARDS);
        $out = [[], []];
        $placed = [];
        foreach ([0, 1] as $col) {
            foreach (is_array($cols[$col] ?? null) ? $cols[$col] : [] as $card) {
                if (is_string($card) && in_array($card, $known, true) && !isset($placed[$card])) {
                    $out[$col][] = $card;
                    $placed[$card] = true;
                }
            }
        }
        foreach (self::DASHBOARD_CARDS as $col => $cards) {
            foreach ($cards as $i => $card) {
                if (!isset($placed[$card])) {
                    $after = $i > 0 ? array_search($cards[$i - 1], $out[$col], true) : -1;
                    array_splice($out[$col], $after === false ? count($out[$col]) : $after + 1, 0, [$card]);
                    $placed[$card] = true;
                }
            }
        }

        return $out;
    }

    /**
     * Saves who may see and use the store (see "Store gate" in the module's Service.php), on
     * the Orders settings page.
     */
    public function store_update($data): bool
    {
        $this->manageSettings('order');

        $mode = (string) ($data['store_mode'] ?? '');
        if (!in_array($mode, Service::STORE_MODES, true)) {
            throw new InformationException('Choose who can see the store.');
        }

        $group = trim((string) ($data['store_testers_group'] ?? ''));
        if ($group !== '') {
            if (!ctype_digit($group) || $this->getDi()['em']->getConnection()->fetchOne('SELECT 1 FROM client_group WHERE id = ?', [(int) $group]) === false) {
                throw new InformationException('The client group that can order was not found.');
            }
            $group = (string) (int) $group;
        }
        if ($mode === 'testers' && $group === '') {
            throw new InformationException('Choose the client group that can order.');
        }

        $title = trim((string) ($data['store_title'] ?? ''));
        $subtitle = trim((string) ($data['store_subtitle'] ?? ''));
        if (mb_strlen($title) > Service::STORE_TITLE_MAX || mb_strlen($subtitle) > Service::STORE_SUBTITLE_MAX) {
            throw new InformationException('The store title can have :title characters and the subtitle :subtitle.', [':title' => Service::STORE_TITLE_MAX, ':subtitle' => Service::STORE_SUBTITLE_MAX]);
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $title . $subtitle) === 1) {
            throw new InformationException('The store title and subtitle are one line of plain text each.');
        }

        Service::saveSettings($this->getDi(), [
            'store_mode' => $mode,
            'store_testers_group' => $group,
            'store_title' => $title,
            'store_subtitle' => $subtitle,
        ]);
        $this->getDi()['logger']->info('ClientFence: store set to %s (client group that can order: %s)', $mode, $group === '' ? 'none' : $group);

        return true;
    }

    /**
     * Makes a new store preview link (action "new") or turns the preview off (action "off").
     * Either way every open preview session ends. Returns the new token, or an empty string.
     */
    public function store_preview_update($data): string
    {
        $this->manageSettings('order');

        $action = (string) ($data['action'] ?? '');
        if (!in_array($action, ['new', 'off'], true)) {
            throw new InformationException('Choose whether to make a new preview link or turn it off.');
        }
        $token = $action === 'new' ? bin2hex(random_bytes(16)) : '';

        Service::saveSettings($this->getDi(), ['store_preview_token' => $token]);
        $this->getDi()['logger']->info($token === '' ? 'ClientFence: store preview link turned off' : 'ClientFence: new store preview link');

        return $token;
    }

    // ----- setup wizard (Setup.php, Branding.php; Super Administrators only) ----------------

    /** Whether the signed-in staff member may open the setup wizard (System → Settings shows its link). */
    public function setup_allowed(): bool
    {
        return Setup::allowed($this->getDi());
    }

    /** Company details: name, email, phone and address (System → Settings → Company Details). */
    public function setup_company($data): bool
    {
        $this->setupAllowed();
        $name = trim((string) ($data['company_name'] ?? ''));
        if ($name === '') {
            throw new InformationException('Enter your business name.');
        }
        $email = trim((string) ($data['company_email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InformationException('Enter a valid business email address.');
        }
        $params = ['company_name' => $name, 'company_email' => $email];
        foreach (['company_tel', 'company_address_1', 'company_address_2', 'company_address_3'] as $key) {
            $params[$key] = trim((string) ($data[$key] ?? ''));
        }

        return (bool) $this->getDi()['api_admin']->system_update_params($params);
    }

    /** Uploads the logo, the logo for dark backgrounds or the square icon (`kind`, multipart "file"). */
    public function setup_brand_asset($data): array
    {
        $this->setupAllowed();
        $di = $this->getDi();
        $kind = (string) ($data['kind'] ?? '');

        return ['kind' => $kind, 'url' => Branding::saveAsset($di, $kind, $di['request']->files->get('file'))];
    }

    /** Brand color and sign-in page style. */
    public function setup_brand($data): array
    {
        $this->setupAllowed();

        return Branding::save($this->getDi(), (string) ($data['color'] ?? ''), (string) ($data['login_style'] ?? 'dark'));
    }

    /** How email is sent (PHP mail or SMTP) and the sender. */
    public function setup_email($data): bool
    {
        $this->setupAllowed();
        Setup::saveEmail($this->getDi(), $data);

        return true;
    }

    /** Sends a test email to the signed-in staff member. */
    public function setup_test_email(): bool
    {
        $this->setupAllowed();

        return (bool) $this->getDi()['api_admin']->email_send_test([]);
    }

    /** Payment methods: manual (bank transfer), PayPal, Stripe. */
    public function setup_payments($data): bool
    {
        $this->setupAllowed();
        Setup::savePayments($this->getDi(), $data);

        return true;
    }

    /** The address to give Stripe for its webhook (installs Stripe, switched off, if needed). */
    public function setup_stripe_webhook(): array
    {
        $this->setupAllowed();

        return ['url' => Setup::stripeWebhookUrl($this->getDi())];
    }

    /**
     * Captcha, staff sign-in security and the admin address. Turning the staff email PIN on first
     * answers {confirm: true, email} (a PIN went to this admin); the same save with `confirm_pin`
     * finishes it. When the admin address moved, returns its new URL for the browser to go to.
     */
    public function setup_security($data): array
    {
        $this->setupAllowed();
        $admin = $this->getIdentity();
        $result = Setup::saveSecurity($this->getDi(), (array) $data, ['id' => (int) $admin->id, 'email' => (string) $admin->email, 'name' => (string) $admin->name, 'timezone' => (string) ($admin->timezone ?? '')], (string) $this->getIp());
        if (!empty($result['confirm'])) {
            return ['confirm' => true, 'email' => $result['email'] ?? ''];
        }
        $path = $result['admin_path'];

        return ['admin_url' => $path === null ? null : rtrim(SYSTEM_URL, '/') . $path . '/clientfence/setup#store'];
    }

    /** The bell in the admin header: this staff member's newest notifications and the unread count. */
    public function notifications_get(): array
    {
        $di = $this->getDi();
        $id = (int) $this->adminId();

        return [
            'unread' => Notifications::unread($di, Notifications::STAFF, $id),
            'items' => Notifications::latest($di, Notifications::STAFF, $id, static fn (string $path): string => $di['url']->adminLink($path)),
        ];
    }

    /** Marks one of this staff member's notifications (`id`) or all of them as read. */
    public function notifications_read($data): int
    {
        $id = (int) ($data['id'] ?? 0);

        return Notifications::markRead($this->getDi(), Notifications::STAFF, (int) $this->adminId(), $id > 0 ? $id : null);
    }

    /** Hides one of this staff member's notifications (`id`) from the bell. */
    public function notifications_dismiss($data): int
    {
        $id = (int) ($data['id'] ?? 0);

        return $id > 0 ? Notifications::dismiss($this->getDi(), Notifications::STAFF, (int) $this->adminId(), $id) : 0;
    }

    /**
     * The settings the Ctrl+K search finds (SettingsIndex.php): the settings pages this staff
     * member may open, with their tabs, sections and options, in their language.
     */
    public function settings_index(): array
    {
        return SettingsIndex::forAdmin($this->getDi());
    }

    /** Who sees the store: closed, one client group, or everyone. */
    public function setup_store($data): bool
    {
        $this->setupAllowed();
        Setup::saveStore($this->getDi(), (array) $data);

        return true;
    }

    /** A first product or service. Returns its id. */
    public function setup_product($data): int
    {
        $this->setupAllowed();

        return Setup::saveProduct($this->getDi(), $data);
    }

    /** Finishes (or skips) the wizard: it no longer opens by itself. */
    public function setup_finish(): bool
    {
        $this->setupAllowed();
        Setup::finish($this->getDi());

        return true;
    }

    private function setupAllowed(): void
    {
        if (!Setup::allowed($this->getDi())) {
            throw new InformationException('Only Super Administrators can run the setup wizard.', null, 403);
        }
    }

    /**
     * The settings permission of the module whose settings page shows the setting.
     */
    private function manageSettings(string $module): void
    {
        $this->getDi()['mod_service']('extension')->hasManagePermission('mod_' . $module);
    }

    private function adminId(): ?int
    {
        $identity = $this->getIdentity();

        return $identity instanceof \Model_Admin ? (int) $identity->id : null;
    }
}
