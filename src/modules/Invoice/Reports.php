<?php

declare(strict_types=1);

namespace ClientFence\Mod\Invoice;

use ClientFence\InformationException;
use Doctrine\DBAL\Connection;
use League\Csv\Bom;
use League\Csv\EscapeFormula;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Intl\Countries;

/**
 * ClientFence: staff reports, as WHMCS's (Invoices > Reports). Each report is a table: run() returns
 * its title, the filters it was run with, the columns and the rows; the admin page shows it, prints
 * it and downloads it as CSV (csv()). Money is in one currency at a time (the `currency` filter,
 * the default currency first), as WHMCS.
 *
 * Column types: text, int, money, date, month, country (ISO code; the page shows the name in the
 * staff member's language), link ([label, admin path]).
 *
 * Money follows FOSSBilling's own rules: an invoice's total is its lines plus their tax (as
 * Invoice\Service::getTotalWithTax()), a payment is a processed transaction (refunds are stored
 * negative), and account credit is the client's balance entries, without the "transaction" ones (a
 * gateway payment passing through the balance to its invoice, ServiceTransaction::debitTransaction()).
 * A positive "invoice" entry is a paid deposit (add funds) invoice, so it is credit.
 */
final class Reports
{
    /** Report => group, in the order the Reports page lists them. */
    public const REPORTS = [
        'statement' => 'billing',
        'aging_invoices' => 'billing',
        'monthly_transactions' => 'billing',
        'income_forecast' => 'billing',
        'sales_tax' => 'billing',
        'top_clients' => 'billing',
        'credits' => 'billing',
        'clients_by_country' => 'clients',
        'suspensions' => 'clients',
        'ticket_replies' => 'support',
    ];

    /** Most rows a list report shows (the rest are left out, and the page says so). */
    public const MAX_ROWS = 5000;

    /**
     * The Reports page: group => [report => [title, description]].
     *
     * @return array<string, array{title: string, reports: array<string, array{title: string, description: string}>}>
     */
    public static function catalog(): array
    {
        $groups = [
            'billing' => __trans('Billing'),
            'clients' => __trans('Clients'),
            'support' => __trans('Support'),
        ];
        $catalog = [];
        foreach (self::REPORTS as $key => $group) {
            $catalog[$group]['title'] = $groups[$group];
            $catalog[$group]['reports'][$key] = self::about($key);
        }

        return $catalog;
    }

    /**
     * Runs a report with the given filters (missing ones take their defaults).
     *
     * @return array<string, mixed>
     */
    public static function run(\Pimple\Container $di, string $key, array $params = [], ?int $now = null): array
    {
        if (!isset(self::REPORTS[$key])) {
            throw new InformationException('Report not found', [], 404);
        }
        $now ??= time();
        $db = self::db($di);
        $report = self::about($key) + [
            'key' => $key,
            'group' => self::REPORTS[$key],
            'fields' => [],
            'filters' => [],
            'currency' => null,
            'currencies' => [],
            'columns' => [],
            'rows' => [],
            'total' => null,
            'truncated' => false,
            'notice' => null,
            'client' => null,
            'pdf' => false,
        ];

        return match ($key) {
            'statement' => self::statement($di, $report, $params),
            'aging_invoices' => self::agingInvoices($db, $report, $params, $now),
            'monthly_transactions' => self::monthlyTransactions($db, $report, $params, $now),
            'income_forecast' => self::incomeForecast($di, $db, $report, $params, $now),
            'sales_tax' => self::salesTax($db, $report, $params, $now),
            'top_clients' => self::topClients($db, $report, $params),
            'credits' => self::credits($db, $report, $params, $now),
            'clients_by_country' => self::clientsByCountry($db, $report),
            'suspensions' => self::suspensions($db, $report),
            'ticket_replies' => self::ticketReplies($db, $report, $params, $now),
        };
    }

    /**
     * The report as a CSV download (UTF-8 with a byte order mark, so Excel reads Arabic): the
     * column titles, the rows and the total, with plain numbers (two decimals for money), ISO
     * dates and country names in `$locale`.
     */
    public static function csv(array $report, string $locale): Response
    {
        $file = new \SplTempFileObject();
        $csv = Writer::from($file);
        $csv->setOutputBOM(Bom::Utf8);
        // Formula escaping, except for numbers: it would quote a negative amount.
        $escape = new EscapeFormula();
        $csv->addFormatter(static function (array $record) use ($escape): array {
            $escaped = $escape->escapeRecord($record);
            foreach ($record as $i => $value) {
                if (is_string($value) && is_numeric($value)) {
                    $escaped[$i] = $value;
                }
            }

            return $escaped;
        });
        $csv->insertOne(array_column($report['columns'], 'label'));
        $rows = $report['rows'];
        if ($report['total'] !== null) {
            $rows[] = $report['total'];
        }
        foreach ($rows as $row) {
            $line = [];
            foreach ($report['columns'] as $i => $column) {
                $line[] = self::plain($row[$i] ?? null, $column['type'], $locale);
            }
            $csv->insertOne($line);
        }

        $parts = array_filter(array_map('strval', $report['filters']), static fn (string $v): bool => $v !== '');
        $name = $report['key'] . ($parts === [] ? '' : '-' . implode('-', $parts)) . '.csv';
        $response = new Response($csv->toString());
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, preg_replace('/[^A-Za-z0-9_.-]/', '', $name) ?: 'report.csv'));
        $response->headers->set('Cache-Control', 'no-cache, must-revalidate');

        return $response;
    }

    // ---- Reports -------------------------------------------------------------------------

    /**
     * WHMCS "Client Account Register Balance": one client's statement (Statement.php), with the
     * opening balance when the period has a start, and a PDF (StatementPdf).
     */
    private static function statement(\Pimple\Container $di, array $report, array $params): array
    {
        [$from, $to] = self::range($params, '', '');
        $report = self::withRange($report, $from, $to, true);
        $clientId = self::optionalNumber($params['client_id'] ?? null, 'Enter a client ID.', true);
        $report['fields'] = array_merge([['name' => 'client_id', 'type' => 'number', 'label' => __trans('Client ID')]], $report['fields']);
        $report['filters'] = ['client_id' => $clientId ?? ''] + $report['filters'];
        $report['columns'] = [
            ['label' => __trans('Date'), 'type' => 'date'],
            ['label' => __trans('Description'), 'type' => 'link'],
            ['label' => __trans('Debit'), 'type' => 'money'],
            ['label' => __trans('Credit'), 'type' => 'money'],
            ['label' => __trans('Balance'), 'type' => 'money'],
        ];
        if ($clientId === null) {
            $report['notice'] = __trans('Enter a client ID to see the client\'s statement.');

            return $report;
        }

        $statement = Statement::build($di, (int) $clientId, $from, $to);
        $report['currency'] = $statement['currency'];
        $report['client'] = $statement['client'];
        $report['pdf'] = true;
        if ($from !== '') {
            $report['rows'][] = [$from, __trans('Opening balance'), null, null, $statement['opening']];
        }
        foreach ($statement['lines'] as $line) {
            $report['rows'][] = [
                $line['date'],
                $line['invoice_id'] ? [$line['description'], 'invoice/manage/' . $line['invoice_id']] : $line['description'],
                $line['debit'] ?: null,
                $line['credit'] ?: null,
                $line['balance'],
            ];
        }
        $report['total'] = [null, __trans('Closing balance'), $statement['debit'], $statement['credit'], $statement['closing']];

        return $report;
    }

    /** WHMCS "Aging Invoices": unpaid invoices by how long they are overdue. */
    private static function agingInvoices(Connection $db, array $report, array $params, int $now): array
    {
        $report = self::withCurrency($db, $report, $params);
        $buckets = [
            'due' => __trans('Not due yet'),
            '0' => __trans(':from to :to days', [':from' => 0, ':to' => 30]),
            '31' => __trans(':from to :to days', [':from' => 31, ':to' => 60]),
            '61' => __trans(':from to :to days', [':from' => 61, ':to' => 90]),
            '91' => __trans(':from to :to days', [':from' => 91, ':to' => 120]),
            '121' => __trans('Over :days days', [':days' => 120]),
        ];
        $sums = array_fill_keys(array_keys($buckets), [0, 0.0]);
        $today = strtotime(date('Y-m-d', $now));
        $invoices = $db->fetchAllAssociative(
            'SELECT i.id, COALESCE(i.due_at, i.created_at) AS due, ' . self::invoiceTotal() . ' AS total FROM invoice i WHERE i.status = ? AND i.currency = ?',
            ['unpaid', $report['currency']]
        );
        foreach ($invoices as $invoice) {
            $days = (int) floor(($today - strtotime(substr((string) $invoice['due'], 0, 10))) / 86400);
            $bucket = match (true) {
                $days < 0 => 'due',
                $days <= 30 => '0',
                $days <= 60 => '31',
                $days <= 90 => '61',
                $days <= 120 => '91',
                default => '121',
            };
            ++$sums[$bucket][0];
            $sums[$bucket][1] += (float) $invoice['total'];
        }

        $report['columns'] = [
            ['label' => __trans('Overdue'), 'type' => 'text'],
            ['label' => __trans('Invoices'), 'type' => 'int'],
            ['label' => __trans('Amount'), 'type' => 'money'],
        ];
        foreach ($buckets as $bucket => $label) {
            $report['rows'][] = [$label, $sums[$bucket][0], round($sums[$bucket][1], 2)];
        }
        $report['total'] = [__trans('Total'), array_sum(array_column($sums, 0)), round(array_sum(array_column($sums, 1)), 2)];

        return $report;
    }

    /** WHMCS "Monthly Transactions": money in and out for each day of a month. */
    private static function monthlyTransactions(Connection $db, array $report, array $params, int $now): array
    {
        $report = self::withCurrency($db, $report, $params);
        $month = self::month($params['month'] ?? null, $now);
        $report['fields'] = array_merge([['name' => 'month', 'type' => 'month', 'label' => __trans('Month')]], $report['fields']);
        $report['filters'] = ['month' => $month] + $report['filters'];

        $days = (int) date('t', (int) strtotime($month . '-01'));
        $in = $out = array_fill(1, $days, 0.0);
        foreach (self::transactions($db, $report['currency'], $month . '-01', $month . '-' . $days) as $tx) {
            $day = (int) substr((string) $tx['created_at'], 8, 2);
            if ($tx['out']) {
                $out[$day] += $tx['amount'];
            } else {
                $in[$day] += $tx['amount'];
            }
        }

        $report['columns'] = [
            ['label' => __trans('Date'), 'type' => 'date'],
            ['label' => __trans('Amount in'), 'type' => 'money'],
            ['label' => __trans('Amount out'), 'type' => 'money'],
            ['label' => __trans('Net'), 'type' => 'money'],
        ];
        for ($day = 1; $day <= $days; ++$day) {
            $report['rows'][] = [sprintf('%s-%02d', $month, $day), round($in[$day], 2), round($out[$day], 2), round($in[$day] - $out[$day], 2)];
        }
        $report['total'] = [__trans('Total'), round(array_sum($in), 2), round(array_sum($out), 2), round(array_sum($in) - array_sum($out), 2)];

        return $report;
    }

    /**
     * WHMCS "Income Forecast": what the active services bring in each of the next 12 months if they
     * all renew, at the price their renewal invoice would have (domains at the TLD's renewal price).
     * A renewal already overdue counts in this month.
     */
    private static function incomeForecast(\Pimple\Container $di, Connection $db, array $report, array $params, int $now): array
    {
        $report = self::withCurrency($db, $report, $params);
        $start = new \DateTimeImmutable(date('Y-m-01', $now));
        $end = $start->modify('+12 months');
        $months = [];
        for ($m = $start; $m < $end; $m = $m->modify('+1 month')) {
            $months[$m->format('Y-m')] = [0, 0.0];
        }

        $orders = $db->fetchAllAssociative(
            "SELECT o.id, o.product_id, o.currency, o.period, o.price, o.quantity, o.config, o.expires_at, p.type AS product_type
             FROM client_order o LEFT JOIN product p ON p.id = o.product_id
             WHERE o.status = 'active' AND o.currency = ? AND o.expires_at IS NOT NULL AND o.period IS NOT NULL AND o.period <> ''",
            [$report['currency']]
        );
        foreach ($orders as $order) {
            if (preg_match('/^([1-9][0-9]?)([DWMY])$/', (string) $order['period'], $period) !== 1) {
                continue;
            }
            $step = new \DateInterval('P' . $period[1] . $period[2]);
            $amount = self::renewalAmount($di, $db, $order);
            $due = new \DateTimeImmutable(substr((string) $order['expires_at'], 0, 10));
            if ($due < $start) {
                // Overdue: it would be paid now; the later renewals follow its own schedule.
                ++$months[$start->format('Y-m')][0];
                $months[$start->format('Y-m')][1] += $amount;
                while ($due < $start) {
                    $due = $due->add($step);
                }
                if ($due->format('Y-m') === $start->format('Y-m')) {
                    $due = $due->add($step);
                }
            }
            for (; $due < $end; $due = $due->add($step)) {
                ++$months[$due->format('Y-m')][0];
                $months[$due->format('Y-m')][1] += $amount;
            }
        }

        $report['columns'] = [
            ['label' => __trans('Month'), 'type' => 'month'],
            ['label' => __trans('Renewals'), 'type' => 'int'],
            ['label' => __trans('Amount'), 'type' => 'money'],
        ];
        foreach ($months as $month => [$count, $sum]) {
            $report['rows'][] = [$month, $count, round($sum, 2)];
        }
        $report['total'] = [__trans('Total'), array_sum(array_column($months, 0)), round(array_sum(array_column($months, 1)), 2)];

        return $report;
    }

    /** WHMCS "Sales Tax Liability": tax on the invoices paid in a period. */
    private static function salesTax(Connection $db, array $report, array $params, int $now): array
    {
        $report = self::withCurrency($db, $report, $params);
        [$from, $to] = self::range($params, date('Y-01-01', $now), date('Y-m-d', $now));
        $report = self::withRange($report, $from, $to);

        $rows = $db->fetchAllAssociative(
            'SELECT i.id, i.serie, i.nr, i.client_id, i.buyer_first_name, i.buyer_last_name, i.buyer_company, i.paid_at, i.taxname, i.taxrate, '
            . self::invoiceSubtotal() . ' AS subtotal, ' . self::invoiceTax() . ' AS tax
             FROM invoice i WHERE (i.status = ? OR (i.status = ? AND EXISTS (SELECT 1 FROM invoice_item ci WHERE ci.invoice_id = i.id AND ci.task = ?)))
             AND i.currency = ? AND i.paid_at >= ? AND i.paid_at < ? ORDER BY i.paid_at, i.id',
            // ClientFence: with the credit notes of account credit (a downgrade's unused time), which give the tax back;
            // one whose credit a refund took back has another task and stays out with the refunded invoice.
            ['paid', 'refunded', Service::CREDIT_NOTE_TASK, $report['currency'], $from . ' 00:00:00', self::dayAfter($to)]
        );
        $report['columns'] = [
            ['label' => __trans('Invoice'), 'type' => 'link'],
            ['label' => __trans('Client'), 'type' => 'link'],
            ['label' => __trans('Date paid'), 'type' => 'date'],
            ['label' => __trans('Tax'), 'type' => 'text'],
            ['label' => __trans('Subtotal'), 'type' => 'money'],
            ['label' => __trans('Tax amount'), 'type' => 'money'],
            ['label' => __trans('Total'), 'type' => 'money'],
        ];
        $subtotal = $tax = 0.0;
        foreach (self::limit($report, $rows) as $row) {
            $name = self::name($row['buyer_first_name'], $row['buyer_last_name'], $row['buyer_company']);
            $rate = (float) $row['taxrate'] > 0 ? trim($row['taxname'] . ' ' . rtrim(rtrim((string) $row['taxrate'], '0'), '.') . '%') : '';
            $report['rows'][] = [
                [Statement::number($db, $row), 'invoice/manage/' . $row['id']],
                [$name, 'client/manage/' . $row['client_id']],
                substr((string) $row['paid_at'], 0, 10),
                $rate,
                round((float) $row['subtotal'], 2),
                round((float) $row['tax'], 2),
                round((float) $row['subtotal'] + (float) $row['tax'], 2),
            ];
            $subtotal += (float) $row['subtotal'];
            $tax += (float) $row['tax'];
        }
        $report['total'] = [__trans('Total'), null, null, null, round($subtotal, 2), round($tax, 2), round($subtotal + $tax, 2)];

        return $report;
    }

    /**
     * WHMCS "Top 10 Clients by Income": the clients who paid most, net of refunds, in a period
     * (all time when none is set). Payments without an invoice have no client and are left out.
     */
    private static function topClients(Connection $db, array $report, array $params): array
    {
        $report = self::withCurrency($db, $report, $params);
        [$from, $to] = self::range($params, '', '');
        $report = self::withRange($report, $from, $to, true);

        $clients = [];
        foreach (self::transactions($db, $report['currency'], $from, $to, true) as $tx) {
            $id = (int) $tx['client_id'];
            $clients[$id] ??= ['name' => self::name($tx['first_name'], $tx['last_name'], $tx['company']), 'in' => 0.0, 'out' => 0.0];
            $clients[$id][$tx['out'] ? 'out' : 'in'] += $tx['amount'];
        }
        uasort($clients, static fn (array $a, array $b): int => ($b['in'] - $b['out']) <=> ($a['in'] - $a['out']));

        $report['columns'] = [
            ['label' => __trans('Client'), 'type' => 'link'],
            ['label' => __trans('Amount in'), 'type' => 'money'],
            ['label' => __trans('Amount out'), 'type' => 'money'],
            ['label' => __trans('Net'), 'type' => 'money'],
        ];
        foreach (array_slice($clients, 0, 10, true) as $id => $client) {
            $report['rows'][] = [[$client['name'], 'client/manage/' . $id], round($client['in'], 2), round($client['out'], 2), round($client['in'] - $client['out'], 2)];
        }

        return $report;
    }

    /** WHMCS "Credits Reviewer": account credit given to clients in a period. */
    private static function credits(Connection $db, array $report, array $params, int $now): array
    {
        $report = self::withCurrency($db, $report, $params);
        [$from, $to] = self::range($params, date('Y-01-01', $now), date('Y-m-d', $now));
        $report = self::withRange($report, $from, $to);
        $clientId = self::optionalNumber($params['client_id'] ?? null, 'Enter a client ID.', true);
        $min = self::optionalNumber($params['min'] ?? null, 'Enter the smallest amount as a number.');
        $max = self::optionalNumber($params['max'] ?? null, 'Enter the largest amount as a number.');
        $report['fields'][] = ['name' => 'client_id', 'type' => 'number', 'label' => __trans('Client ID')];
        $report['fields'][] = ['name' => 'min', 'type' => 'amount', 'label' => __trans('Smallest amount')];
        $report['fields'][] = ['name' => 'max', 'type' => 'amount', 'label' => __trans('Largest amount')];
        $report['filters'] += ['client_id' => $clientId ?? '', 'min' => $min ?? '', 'max' => $max ?? ''];

        $default = self::defaultCurrency($db);
        $sql = "SELECT b.id, b.client_id, b.amount, b.description, b.created_at, c.first_name, c.last_name, c.company
                FROM client_balance b JOIN client c ON c.id = b.client_id
                WHERE b.amount > 0 AND (b.type IS NULL OR b.type <> 'transaction') AND COALESCE(NULLIF(c.currency, ''), ?) = ?
                  AND b.created_at >= ? AND b.created_at < ?";
        $args = [$default, $report['currency'], $from . ' 00:00:00', self::dayAfter($to)];
        foreach ([[$clientId, ' AND b.client_id = ?'], [$min, ' AND b.amount >= ?'], [$max, ' AND b.amount <= ?']] as [$value, $where]) {
            if ($value !== null) {
                $sql .= $where;
                $args[] = $value;
            }
        }
        $rows = $db->fetchAllAssociative($sql . ' ORDER BY b.created_at, b.id', $args);

        $report['columns'] = [
            ['label' => __trans('ID'), 'type' => 'int'],
            ['label' => __trans('Client'), 'type' => 'link'],
            ['label' => __trans('Date'), 'type' => 'date'],
            ['label' => __trans('Description'), 'type' => 'text'],
            ['label' => __trans('Amount'), 'type' => 'money'],
        ];
        $sum = 0.0;
        foreach (self::limit($report, $rows) as $row) {
            $report['rows'][] = [
                (int) $row['id'],
                [self::name($row['first_name'], $row['last_name'], $row['company']), 'client/manage/' . $row['client_id']],
                substr((string) $row['created_at'], 0, 10),
                (string) $row['description'],
                round((float) $row['amount'], 2),
            ];
            $sum += (float) $row['amount'];
        }
        $report['total'] = [null, __trans('Total'), null, null, round($sum, 2)];

        return $report;
    }

    /** WHMCS "Clients by Country": active services and active clients per country. */
    private static function clientsByCountry(Connection $db, array $report): array
    {
        $countries = [];
        foreach ($db->fetchAllAssociative("SELECT COALESCE(country, '') AS country, COUNT(*) AS n FROM client WHERE status = 'active' GROUP BY COALESCE(country, '')") as $row) {
            $countries[strtoupper((string) $row['country'])] = [0, (int) $row['n']];
        }
        $services = $db->fetchAllAssociative(
            "SELECT COALESCE(c.country, '') AS country, COUNT(*) AS n FROM client_order o JOIN client c ON c.id = o.client_id
             WHERE o.status = 'active' AND (o.group_master = 1 OR o.group_master IS NULL) GROUP BY COALESCE(c.country, '')"
        );
        foreach ($services as $row) {
            $code = strtoupper((string) $row['country']);
            $countries[$code] ??= [0, 0];
            $countries[$code][0] = (int) $row['n'];
        }
        uksort($countries, static fn (string $a, string $b): int => [$countries[$b][0], $countries[$b][1], $a] <=> [$countries[$a][0], $countries[$a][1], $b]);

        $report['columns'] = [
            ['label' => __trans('Country'), 'type' => 'country'],
            ['label' => __trans('Active services'), 'type' => 'int'],
            ['label' => __trans('Active clients'), 'type' => 'int'],
        ];
        foreach ($countries as $code => [$services, $clients]) {
            $report['rows'][] = [$code, $services, $clients];
        }
        $report['total'] = [__trans('Total'), array_sum(array_column($countries, 0)), array_sum(array_column($countries, 1))];

        return $report;
    }

    /** WHMCS "Product Suspensions": suspended services and why. */
    private static function suspensions(Connection $db, array $report): array
    {
        $rows = $db->fetchAllAssociative(
            "SELECT o.id, o.title, o.client_id, o.expires_at, o.suspended_at, o.reason, c.first_name, c.last_name, c.company
             FROM client_order o JOIN client c ON c.id = o.client_id WHERE o.status = 'suspended' ORDER BY o.suspended_at, o.id"
        );
        $report['columns'] = [
            ['label' => __trans('Service'), 'type' => 'link'],
            ['label' => __trans('Client'), 'type' => 'link'],
            ['label' => __trans('Suspended on'), 'type' => 'date'],
            ['label' => __trans('Expires'), 'type' => 'date'],
            ['label' => __trans('Reason'), 'type' => 'text'],
        ];
        foreach (self::limit($report, $rows) as $row) {
            $report['rows'][] = [
                ['#' . $row['id'] . ' ' . $row['title'], 'order/manage/' . $row['id']],
                [self::name($row['first_name'], $row['last_name'], $row['company']), 'client/manage/' . $row['client_id']],
                $row['suspended_at'] === null ? null : substr((string) $row['suspended_at'], 0, 10),
                $row['expires_at'] === null ? null : substr((string) $row['expires_at'], 0, 10),
                (string) $row['reason'],
            ];
        }

        return $report;
    }

    /**
     * WHMCS "Support Ticket Replies": each staff member's ticket replies for each day of a month.
     * The system's own replies (autoresponder, escalation rules) are left out.
     */
    private static function ticketReplies(Connection $db, array $report, array $params, int $now): array
    {
        $month = self::month($params['month'] ?? null, $now);
        $report['fields'] = [['name' => 'month', 'type' => 'month', 'label' => __trans('Month')]];
        $report['filters'] = ['month' => $month];
        $days = (int) date('t', (int) strtotime($month . '-01'));

        $rows = $db->fetchAllAssociative(
            'SELECT a.id, a.name, SUBSTR(m.created_at, 9, 2) AS day, COUNT(*) AS n
             FROM support_ticket_message m JOIN admin a ON a.id = m.admin_id
             WHERE a.system_name IS NULL AND m.created_at >= ? AND m.created_at < ?
             GROUP BY a.id, a.name, SUBSTR(m.created_at, 9, 2)',
            [$month . '-01 00:00:00', self::dayAfter($month . '-' . $days)]
        );
        $staff = [];
        foreach ($rows as $row) {
            $staff[(int) $row['id']] ??= ['name' => (string) $row['name'], 'days' => array_fill(1, $days, 0)];
            $staff[(int) $row['id']]['days'][(int) $row['day']] = (int) $row['n'];
        }
        uasort($staff, static fn (array $a, array $b): int => [array_sum($b['days']), $a['name']] <=> [array_sum($a['days']), $b['name']]);

        $report['columns'] = [['label' => __trans('Team member'), 'type' => 'text']];
        for ($day = 1; $day <= $days; ++$day) {
            $report['columns'][] = ['label' => (string) $day, 'type' => 'int'];
        }
        $report['columns'][] = ['label' => __trans('Total'), 'type' => 'int'];
        $totals = array_fill(1, $days, 0);
        foreach ($staff as $member) {
            $report['rows'][] = array_merge([$member['name']], array_values($member['days']), [array_sum($member['days'])]);
            foreach ($member['days'] as $day => $n) {
                $totals[$day] += $n;
            }
        }
        $report['total'] = array_merge([__trans('Total')], array_values($totals), [array_sum($totals)]);

        return $report;
    }

    // ---- Helpers -------------------------------------------------------------------------

    /** @return array{title: string, description: string} */
    private static function about(string $key): array
    {
        return match ($key) {
            'statement' => ['title' => __trans('Client Statement'), 'description' => __trans('A client\'s invoices, payments and account credit with the running balance.')],
            'aging_invoices' => ['title' => __trans('Aging Invoices'), 'description' => __trans('Unpaid invoices by how long they are overdue.')],
            'monthly_transactions' => ['title' => __trans('Monthly Transactions'), 'description' => __trans('Payments received and refunds sent on each day of a month.')],
            'income_forecast' => ['title' => __trans('Income Forecast'), 'description' => __trans('What the active services bring in each of the next 12 months if they all renew.')],
            'sales_tax' => ['title' => __trans('Sales Tax Liability'), 'description' => __trans('The tax on the invoices paid in a period.')],
            'top_clients' => ['title' => __trans('Top 10 Clients by Income'), 'description' => __trans('The clients who paid most, net of refunds.')],
            'credits' => ['title' => __trans('Credits Reviewer'), 'description' => __trans('Account credit given to clients in a period.')],
            'clients_by_country' => ['title' => __trans('Clients by Country'), 'description' => __trans('Active services and active clients in each country.')],
            'suspensions' => ['title' => __trans('Product Suspensions'), 'description' => __trans('Suspended services and the reason for each.')],
            'ticket_replies' => ['title' => __trans('Support Ticket Replies'), 'description' => __trans('Ticket replies by each staff member on each day of a month.')],
            default => ['title' => $key, 'description' => ''],
        };
    }

    /** Adds the currency filter (only shown when there is more than one currency). */
    private static function withCurrency(Connection $db, array $report, array $params): array
    {
        $currencies = $db->fetchFirstColumn('SELECT code FROM currency ORDER BY is_default DESC, code');
        if ($currencies === []) {
            $currencies = ['USD'];
        }
        $currency = strtoupper(trim((string) ($params['currency'] ?? '')));
        if ($currency === '') {
            $currency = (string) $currencies[0];
        } elseif (!in_array($currency, $currencies, true)) {
            throw new InformationException('Choose one of the currencies.');
        }
        $report['currency'] = $currency;
        $report['currencies'] = $currencies;
        if (count($currencies) > 1) {
            $report['fields'][] = ['name' => 'currency', 'type' => 'currency', 'label' => __trans('Currency')];
        }
        $report['filters']['currency'] = $currency;

        return $report;
    }

    private static function withRange(array $report, string $from, string $to, bool $optional = false): array
    {
        $report['fields'] = array_merge([
            ['name' => 'from', 'type' => 'date', 'label' => __trans('From'), 'optional' => $optional],
            ['name' => 'to', 'type' => 'date', 'label' => __trans('To'), 'optional' => $optional],
        ], $report['fields']);
        $report['filters'] = ['from' => $from, 'to' => $to] + $report['filters'];

        return $report;
    }

    /**
     * Processed payments and refunds in a currency and period, with `out` true for refunds and
     * the amount positive. With `$withClient`, only those of an invoice, with its client.
     *
     * @return list<array<string, mixed>>
     */
    private static function transactions(Connection $db, string $currency, string $from, string $to, bool $withClient = false): array
    {
        $default = self::defaultCurrency($db);
        $sql = 'SELECT t.amount, t.type, t.created_at' . ($withClient ? ', i.client_id, c.first_name, c.last_name, c.company' : '')
            . ' FROM ' . $db->quoteSingleIdentifier('transaction') . ' t LEFT JOIN invoice i ON i.id = t.invoice_id'
            . ($withClient ? ' JOIN client c ON c.id = i.client_id' : '')
            . " WHERE t.status = 'processed' AND COALESCE(NULLIF(t.currency, ''), i.currency, ?) = ?";
        $args = [$default, $currency];
        if ($from !== '') {
            $sql .= ' AND t.created_at >= ?';
            $args[] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $sql .= ' AND t.created_at < ?';
            $args[] = self::dayAfter($to);
        }
        $result = [];
        foreach ($db->fetchAllAssociative($sql, $args) as $row) {
            if (!is_numeric($row['amount'])) {
                continue;
            }
            $amount = (float) $row['amount'];
            $row['out'] = $row['type'] === 'refund' || $amount < 0;
            $row['amount'] = abs($amount);
            $result[] = $row;
        }

        return $result;
    }

    /**
     * An active order's next renewal amount, as Invoice\Service::generateForOrder() bills it: the
     * order's price, or for a domain the TLD's renewal price (in the default currency) at the
     * order currency's rate.
     */
    private static function renewalAmount(\Pimple\Container $di, Connection $db, array $order): float
    {
        $amount = (float) $order['price'] * max(1, (int) $order['quantity']);
        if ($order['product_type'] !== 'domain') {
            return $amount;
        }
        try {
            $rate = $db->fetchOne('SELECT conversion_rate FROM currency WHERE code = ?', [$order['currency']]);
            if ($rate === false || !is_numeric($rate)) {
                return $amount;
            }
            $productService = $di['mod_service']('Product');
            $product = $productService->findProductById((int) $order['product_id']);
            // Fixed currency prices (Product/CurrencyPrices.php) of the order's currency.
            if ($productService instanceof \ClientFence\Mod\Product\Service) {
                $productService = $productService->inCurrency((string) $order['currency']);
            }
            $line = $productService->getProductRenewalLineConfig($product, json_decode((string) $order['config'], true) ?: []);

            return (float) $line['price'] * (float) $rate * max(1, (int) ($line['quantity'] ?? 1));
        } catch (\Throwable) {
            return $amount;
        }
    }

    /** SQL: an invoice's lines before tax (alias `i`). */
    private static function invoiceSubtotal(): string
    {
        return '(SELECT COALESCE(SUM(ii.price * ii.quantity), 0) FROM invoice_item ii WHERE ii.invoice_id = i.id)';
    }

    /** SQL: an invoice's tax, as Invoice\Service::getTax() (once on the taxed subtotal, rounded to cents). */
    private static function invoiceTax(): string
    {
        return '(SELECT CASE WHEN (i.taxrate + 0) > 0 THEN ROUND(COALESCE(SUM(CASE WHEN ii.taxed <> 0 THEN ii.price * ii.quantity ELSE 0 END), 0) * (i.taxrate + 0) / 100, 2) ELSE 0 END FROM invoice_item ii WHERE ii.invoice_id = i.id)';
    }

    private static function invoiceTotal(): string
    {
        return '(' . self::invoiceSubtotal() . ' + ' . self::invoiceTax() . ')';
    }

    private static function name(mixed $first, mixed $last, mixed $company): string
    {
        $name = trim((string) $first . ' ' . (string) $last);

        return $name !== '' ? $name : trim((string) $company);
    }

    private static function month(mixed $value, int $now): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return date('Y-m', $now);
        }
        if (preg_match('/^(19|20)[0-9]{2}-(0[1-9]|1[0-2])$/', $value) !== 1) {
            throw new InformationException('Choose a month.');
        }

        return $value;
    }

    /** @return array{0: string, 1: string} from and to (Y-m-d, or '' when optional and not set) */
    private static function range(array $params, string $from, string $to): array
    {
        $dates = ['from' => $from, 'to' => $to];
        foreach ($dates as $name => $default) {
            $value = trim((string) ($params[$name] ?? ''));
            if ($value === '') {
                continue;
            }
            if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $value, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                throw new InformationException('Enter the dates as year-month-day.');
            }
            $dates[$name] = $value;
        }
        ['from' => $from, 'to' => $to] = $dates;
        if ($from !== '' && $to !== '' && $from > $to) {
            throw new InformationException('The start date is after the end date.');
        }

        return [$from, $to];
    }

    private static function optionalNumber(mixed $value, string $error, bool $integer = false): int|float|null
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $number = filter_var($value, $integer ? FILTER_VALIDATE_INT : FILTER_VALIDATE_FLOAT, $integer ? ['options' => ['min_range' => 1]] : []);
        if ($number === false) {
            throw new InformationException($error);
        }

        return $number;
    }

    private static function dayAfter(string $date): string
    {
        return date('Y-m-d 00:00:00', (int) strtotime($date . ' +1 day'));
    }

    private static function defaultCurrency(Connection $db): string
    {
        $code = $db->fetchOne('SELECT code FROM currency ORDER BY is_default DESC, code');

        return $code === false ? 'USD' : (string) $code;
    }

    /** At most MAX_ROWS rows; marks the report when some were left out. */
    private static function limit(array &$report, array $rows): array
    {
        if (count($rows) > self::MAX_ROWS) {
            $report['truncated'] = true;

            return array_slice($rows, 0, self::MAX_ROWS);
        }

        return $rows;
    }

    private static function plain(mixed $value, string $type, string $locale): string
    {
        if ($value === null) {
            return '';
        }

        return match ($type) {
            'money' => number_format((float) $value, 2, '.', ''),
            'link' => is_array($value) ? (string) ($value[0] ?? '') : (string) $value,
            'country' => $value === '' ? '' : (Countries::exists((string) $value) ? Countries::getName((string) $value, $locale) : (string) $value),
            default => (string) $value,
        };
    }

    private static function db(\Pimple\Container $di): Connection
    {
        return $di['em']->getConnection();
    }
}
