<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Upmind;

use ClientFence\InformationException;
use ClientFence\Mod\Clientfence\Whmcs\Map;
use ClientFence\Mod\Clientfence\Whmcs\Run;
use ClientFence\Mod\Clientfence\Whmcs\Snapshot as Base;
use Doctrine\DBAL\Connection;

/**
 * ClientFence: the Upmind import. Upmind is hosted, so there is no database to read: fetch() reads
 * Upmind's admin API (Api.php) into the import's snapshot (Whmcs/Snapshot.php), a page at a time.
 * Upmind's UUIDs become numbers in the order they are read.
 *
 * Not in Upmind's API, so not imported: passwords (clients use "Forgot password"), two-factor
 * secrets, saved card tokens, ticket attachments. Left out on purpose: invoices with a zero total
 * (Upmind makes one for each service it imported), payments that pay no imported invoice (the
 * client's credit balance carries them), domain transfer prices, billing cycles WHMCS has no name
 * for (a warning names each).
 */
final class Snapshot extends Base
{
    public const NAME = 'Upmind';
    public const STATE = 'upmind_fetch';
    public const STEP = 'upmind';
    public const FILE = 'upmind-import.sqlite';
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

    /**
     * Reads Upmind for up to $seconds (0: until done); $restart starts again from nothing (Base::read()).
     *
     * @return array<string, mixed>
     */
    public static function fetch(\Pimple\Container $di, #[\SensitiveParameter] string $token, float $seconds = 0.0, bool $restart = false): array
    {
        return self::read($di, static fn (Connection $db): self => new self(new Api($di, $token), $db), $seconds, $restart);
    }

    private function __construct(private readonly Api $api, Connection $db)
    {
        parent::__construct($db);
    }

    /**
     * One page of a step: [the next offset or null when the step is done, rows read].
     *
     * @return array{0: ?int, 1: int}
     */
    protected function page(string $step, int $offset): array
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
            // Upmind's language code (sr): the WHMCS import maps it to a locale this install ships (Accounts::locale).
            'language' => (string) ($c['interface_language_code'] ?? ''),
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
        $tld = $this->db->fetchOne("SELECT id FROM snapshot_id WHERE entity = 'tld' AND uuid = ?", [(string) $s['product_id']]);
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

    /** After the last step: credit balances in each client's own currency, and a warning when Upmind charged tax (tax stays off). */
    protected function finish(): void
    {
        $default = (int) $this->db->fetchOne('SELECT id FROM tblcurrencies WHERE "default" = 1');
        foreach ($this->db->fetchAllAssociative("SELECT c.id, c.credit, u.uuid, cur.code FROM tblclients c JOIN snapshot_id u ON u.entity = 'client' AND u.id = c.id JOIN tblcurrencies cur ON cur.id = c.currency WHERE c.currency <> ?", [$default]) as $c) {
            $credit = $this->api->one("clients/{$c['uuid']}/credit", ['currency_code' => (string) $c['code']]);
            $this->db->update('tblclients', ['credit' => round((float) ($credit['credit'] ?? 0), 2)], ['id' => (int) $c['id']]);
        }
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

    /** The WHMCS currency id of a code, added on first use with Upmind's symbols. */
    private function currency(string $code, bool $default = false): int
    {
        $id = $this->db->fetchOne('SELECT id FROM tblcurrencies WHERE code = ?', [$code]);
        if ($id !== false) {
            return (int) $id;
        }
        $extra = $this->db->fetchOne("SELECT extra FROM snapshot_lookup WHERE kind = 'currency' AND value = ?", [$code]);
        [$prefix, $suffix, $decimals] = is_string($extra) ? (array) json_decode($extra, true) + ['', '', 2] : ['', '', 2];
        $this->db->insert('tblcurrencies', ['code' => $code, 'prefix' => $prefix, 'suffix' => $suffix, 'format' => (int) $decimals === 0 ? 4 : 2, 'rate' => 1, '"default"' => $default ? 1 : 0]);
        if (!$default) {
            $this->warn(sprintf('Currency %s: Upmind gives no exchange rate; set it under System > Currencies after the import.', $code));
        }

        return (int) $this->db->lastInsertId();
    }
}
