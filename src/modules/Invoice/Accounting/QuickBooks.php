<?php

declare(strict_types=1);

/**
 * ClientFence: the QuickBooks Online adapter of the accounting sync (Program.php, Sync.php). Written from the QuickBooks
 * Online Accounting API documentation: OAuth 2.0 (https://developer.intuit.com/app/developer/qbo/docs/develop/authentication-and-authorization/oauth-2.0),
 * the API reference (https://developer.intuit.com/app/developer/qbo/docs/api/accounting/all-entities/invoice and the
 * Customer, Payment, RefundReceipt, Transfer, Item, Account, TaxCode, TaxRate, CompanyInfo and Preferences pages), the
 * query language (https://developer.intuit.com/app/developer/qbo/docs/learn/explore-the-quickbooks-online-api/data-queries),
 * sales tax (https://developer.intuit.com/app/developer/qbo/docs/workflows/manage-sales-tax-for-us-locales and
 * .../manage-sales-tax-for-non-us-locales) and minor versions
 * (https://developer.intuit.com/app/developer/qbo/docs/learn/explore-the-quickbooks-online-api/minor-versions). No code
 * was copied.
 *
 * OAuth2 authorization code with each install's own Intuit app. The company is the callback's realmId; access tokens
 * last one hour and are refreshed here, refresh tokens rotate (each new set goes to $saveTokens).
 *
 * Invoice lines need a product or service: the sales mapping offers Items ("item:<id>"), the payment mappings offer Bank
 * and Other Current Asset accounts ("account:<id>"). A line booked to an account (added funds, account credit) uses the
 * Item whose income account it is. Tax: in the US lines are TAX or NON and TxnTaxDetail.TotalTax sets the tax (with
 * manual sales tax the mapped tax code is the invoice's TxnTaxCodeRef); elsewhere lines carry TaxCode ids and TaxLines
 * per rate set the tax, so the total is ClientFence's.
 *
 * Customers are found by DisplayName (unique in QuickBooks) and marked "ClientFence client #id" in Notes; a name another
 * customer has gets " (#id)". Invoices are found by DocNumber, payments by their PrivateNote on the invoice. A refund is
 * a RefundReceipt paid out of the account of its first refund; a second refund account (bank and account credit) is a
 * Transfer between the two, so each account moves by its own part.
 */

namespace ClientFence\Mod\Invoice\Accounting;

use ClientFence\InformationException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class QuickBooks implements Program
{
    public const SCOPE = 'com.intuit.quickbooks.accounting';
    private const AUTHORIZE = 'https://appcenter.intuit.com/connect/oauth2';
    private const TOKEN = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
    private const API = 'https://quickbooks.api.intuit.com/v3/company/';
    private const SANDBOX_API = 'https://sandbox-quickbooks.api.intuit.com/v3/company/';
    // Versions below 75 are answered as 75 since August 2025.
    private const MINOR_VERSION = '75';
    private const DOC_NUMBER = 21;

    private ?array $preferences = null;
    private ?array $items = null;
    private ?array $taxCodes = null;
    private bool $us = false;

    public function __construct(private readonly HttpClientInterface $http, private readonly string $clientId, private readonly string $clientSecret, private array $tokens, private readonly \Closure $saveTokens, private readonly bool $sandbox = false)
    {
    }

    public static function label(): string
    {
        return 'QuickBooks Online';
    }

    public static function hasSandbox(): bool
    {
        return true;
    }

    public function authorizeUrl(string $state, string $redirect): string
    {
        return self::AUTHORIZE . '?' . http_build_query(['client_id' => $this->clientId, 'response_type' => 'code', 'scope' => self::SCOPE, 'redirect_uri' => $redirect, 'state' => $state], '', '&', PHP_QUERY_RFC3986);
    }

    public function connect(string $code, string $redirect, array $callback): array
    {
        $realm = is_string($callback['realmId'] ?? null) && preg_match('/^[0-9]{1,30}$/', $callback['realmId']) === 1
            ? $callback['realmId'] : throw new InformationException('This connection request has expired or was not started here. Click Connect again.');
        $this->tokens = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirect]) + ['tenant_id' => $realm];
        $company = $this->api('GET', 'companyinfo/' . $realm)['CompanyInfo'] ?? [];

        return $this->tokens + ['tenant_name' => (string) ($company['CompanyName'] ?? '')];
    }

    public function accounts(): array
    {
        $out = [];
        foreach ($this->items() as $item) {
            if (in_array($item['Type'] ?? '', ['Service', 'NonInventory'], true)) {
                $out[] = ['id' => 'item:' . $item['Id'], 'name' => (string) ($item['FullyQualifiedName'] ?? $item['Name'] ?? ''), 'kind' => 'sales', 'payments' => false];
            }
        }
        foreach ($this->query('Account') as $a) {
            // The accounts a payment may be deposited to (Undeposited Funds is an Other Current Asset).
            if (($a['Active'] ?? true) && in_array($a['AccountType'] ?? '', ['Bank', 'Other Current Asset'], true)) {
                $number = (string) ($a['AcctNum'] ?? '');
                $out[] = ['id' => 'account:' . $a['Id'], 'name' => ($number !== '' ? $number . ' - ' : '') . (string) ($a['FullyQualifiedName'] ?? $a['Name'] ?? ''),
                    'kind' => $a['AccountType'] === 'Bank' ? 'bank' : 'other', 'payments' => true];
            }
        }

        return $out;
    }

    public function taxRates(): array
    {
        if ($this->automatedTax()) {
            return [['id' => 'TAX', 'name' => 'TAX - Taxable', 'rate' => 0.0], ['id' => 'NON', 'name' => 'NON - Not taxable', 'rate' => 0.0]];
        }
        $out = [];
        foreach ($this->taxCodes() as $id => $code) {
            $out[] = ['id' => (string) $id, 'name' => $code['name'], 'rate' => (float) array_sum(array_column($code['rates'], 'rate'))];
        }

        return $this->us ? [...$out, ['id' => 'NON', 'name' => 'NON - Not taxable', 'rate' => 0.0]] : $out;
    }

    public function contact(array $client): string
    {
        $number = (string) $client['id'];
        $current = ($client['remote_id'] ?? null) !== null ? ($this->api('GET', 'customer/' . rawurlencode((string) $client['remote_id']))['Customer'] ?? null) : null;
        $name = trim((string) $client['name']);
        $suffixed = $name . ' (#' . $number . ')';
        // DisplayName is unique across customers, suppliers and staff: a customer of that name is ours only with our mark.
        $plain = $this->query('Customer', 'where DisplayName = ' . self::quote($name) . ' and Active in (true, false)')[0] ?? null;
        if ($current === null && $plain !== null && preg_match('/ClientFence client #' . preg_quote($number, '/') . '(?![0-9])/', (string) ($plain['Notes'] ?? '')) === 1) {
            $current = $plain;
        }
        $current ??= $this->query('Customer', 'where DisplayName = ' . self::quote($suffixed) . ' and Active in (true, false)')[0] ?? null;
        $taken = 'where DisplayName = ' . self::quote($name) . ' and Active in (true, false)';
        if ($plain !== null ? (string) $plain['Id'] !== (string) ($current['Id'] ?? '') : ($this->query('Vendor', $taken)[0] ?? $this->query('Employee', $taken)[0] ?? null) !== null) {
            $name = $suffixed;
        }
        $phone = trim(((string) $client['phone_cc'] !== '' ? '+' . $client['phone_cc'] . ' ' : '') . $client['phone']);
        $body = array_filter([
            'DisplayName' => $name,
            'GivenName' => (string) $client['first_name'],
            'FamilyName' => (string) $client['last_name'],
            'CompanyName' => (string) $client['company'],
            'PrimaryEmailAddr' => (string) $client['email'] !== '' ? ['Address' => (string) $client['email']] : null,
            'PrimaryPhone' => (string) $client['phone'] !== '' ? ['FreeFormNumber' => $phone] : null,
            'BillAddr' => ['Line1' => (string) $client['address_1'], 'Line2' => (string) $client['address_2'], 'City' => (string) $client['city'],
                'CountrySubDivisionCode' => (string) $client['state'], 'PostalCode' => (string) $client['zip'], 'Country' => (string) $client['country']],
        ], static fn (mixed $v): bool => $v !== null);
        $body = $current === null ? $body + ['Notes' => 'ClientFence client #' . $number] : ['Id' => (string) $current['Id'], 'SyncToken' => (string) $current['SyncToken'], 'sparse' => true] + $body;

        return self::id($this->api('POST', 'customer', [], $body), 'Customer');
    }

    public function invoice(array $doc): string
    {
        return $this->document('Invoice', $doc, ['DueDate' => $doc['due_date']]);
    }

    public function payment(array $payment): string
    {
        $invoiceId = (string) $payment['invoice'];
        $invoice = $this->api('GET', 'invoice/' . rawurlencode($invoiceId))['Invoice'] ?? [];
        $linked = [];
        foreach ($invoice['LinkedTxn'] ?? [] as $txn) {
            if (($txn['TxnType'] ?? '') === 'Payment') {
                $linked[] = self::quote((string) $txn['TxnId']);
            }
        }
        foreach ($linked === [] ? [] : $this->query('Payment', 'where Id in (' . implode(', ', $linked) . ')') as $existing) {
            if (($existing['PrivateNote'] ?? null) === $payment['reference']) {
                return (string) $existing['Id'];
            }
        }

        return self::id($this->api('POST', 'payment', [], [
            'CustomerRef' => ['value' => (string) ($invoice['CustomerRef']['value'] ?? '')],
            'TotalAmt' => $payment['amount'],
            'TxnDate' => $payment['date'],
            'DepositToAccountRef' => ['value' => self::ref((string) $payment['account'], 'account')],
            'PaymentRefNum' => mb_substr((string) $payment['reference'], 0, self::DOC_NUMBER),
            'PrivateNote' => $payment['reference'],
            'Line' => [['Amount' => $payment['amount'], 'LinkedTxn' => [['TxnId' => $invoiceId, 'TxnType' => 'Invoice']]]],
        ]), 'Payment');
    }

    public function creditNote(array $credit): string
    {
        if (($credit['remote_id'] ?? null) !== null) {
            return (string) $credit['remote_id'];
        }
        $refunds = $credit['refunds'];
        $first = array_shift($refunds);
        $ids = [$this->document('RefundReceipt', $credit, ['DepositToAccountRef' => ['value' => self::ref((string) $first['account'], 'account')], 'PrivateNote' => (string) ($credit['reference'] ?? '')])];
        // The receipt took it all from the first account: each other part moves from its own account to that one.
        foreach ($refunds as $refund) {
            $ids[] = $this->transfer($refund, (string) $first['account']);
        }

        return implode(' ', $ids);
    }

    public function void(string $remoteInvoiceId): void
    {
        $invoice = $this->api('GET', 'invoice/' . rawurlencode($remoteInvoiceId))['Invoice'] ?? [];
        $this->api('POST', 'invoice', ['operation' => 'void'], ['Id' => $remoteInvoiceId, 'SyncToken' => (string) ($invoice['SyncToken'] ?? '0')]);
    }

    public function voidCreditNote(string $remoteCreditNoteId): void
    {
        foreach (explode(' ', $remoteCreditNoteId) as $i => $id) {
            $entity = $i === 0 ? 'RefundReceipt' : 'Transfer';
            // Deleted already (a run that stopped half way) is done.
            $found = $this->query($entity, 'where Id = ' . self::quote($id))[0] ?? null;
            if ($found !== null) {
                $this->api('POST', strtolower($entity), ['operation' => 'delete'], ['Id' => $id, 'SyncToken' => (string) $found['SyncToken']]);
            }
        }
    }

    /** An Invoice or RefundReceipt: found by its number unless its id is known, then updated (sparse) or created. */
    private function document(string $entity, array $doc, array $extra): string
    {
        $number = (string) $doc['number'];
        if (mb_strlen($number) > self::DOC_NUMBER) {
            throw new InformationException(':program allows document numbers of up to :max characters: :number is longer.', [':program' => self::label(), ':max' => self::DOC_NUMBER, ':number' => $number]);
        }
        $current = ($doc['remote_id'] ?? null) !== null
            ? ($this->api('GET', strtolower($entity) . '/' . rawurlencode((string) $doc['remote_id']))[$entity] ?? null)
            : ($this->query($entity, 'where DocNumber = ' . self::quote($number))[0] ?? null);
        $body = ['CustomerRef' => ['value' => $doc['contact']], 'DocNumber' => $number, 'TxnDate' => $doc['date'], 'CurrencyRef' => ['value' => $doc['currency']]] + $extra + $this->lines($doc['lines']);
        if ($current !== null) {
            $body = ['Id' => (string) $current['Id'], 'SyncToken' => (string) $current['SyncToken'], 'sparse' => true] + $body;
        }

        return self::id($this->api('POST', strtolower($entity), [], $body), $entity);
    }

    /** Net lines with their product or service and tax code, and the tax ClientFence charged. */
    private function lines(array $lines): array
    {
        $out = $groups = [];
        $total = 0.0;
        $us = $this->automatedTax();
        if (!$us) {
            // Reading the tax codes tells a US company with manual sales tax.
            $this->taxCodes();
            $us = $this->us;
        }
        foreach ($lines as $line) {
            $out[] = ['DetailType' => 'SalesItemLineDetail', 'Amount' => $line['amount'], 'Description' => $line['description'], 'SalesItemLineDetail' => [
                'ItemRef' => ['value' => $this->item((string) $line['account'])],
                'Qty' => $line['quantity'],
                'UnitPrice' => $line['unit_price'],
                'TaxCodeRef' => ['value' => $us ? ($line['tax'] === 'NON' ? 'NON' : 'TAX') : $line['tax']],
            ]];
            $total += $line['tax_amount'];
            $groups[$line['tax']]['net'] = ($groups[$line['tax']]['net'] ?? 0.0) + $line['amount'];
            $groups[$line['tax']]['tax'] = ($groups[$line['tax']]['tax'] ?? 0.0) + $line['tax_amount'];
        }
        $detail = ['TotalTax' => round($total, 2)];
        if ($us) {
            // A sent TotalTax is kept. Manual sales tax takes one tax code (a rate or group) for the whole invoice.
            $codes = array_values(array_diff(array_unique(array_map('strval', array_keys($groups))), ['TAX', 'NON']));
            if (count($codes) > 1) {
                throw new InformationException(':program takes one sales tax rate per invoice: this one has lines with different rates.', [':program' => self::label()]);
            }

            return ['Line' => $out, 'TxnTaxDetail' => $detail + ($codes !== [] ? ['TxnTaxCodeRef' => ['value' => $codes[0]]] : [])];
        }
        // Elsewhere a TaxLine per rate of each code used replaces the calculated tax; a code's tax is shared by its rates.
        $codes = $this->taxCodes();
        $detail['TaxLine'] = [];
        foreach ($groups as $code => $group) {
            $rates = $codes[(string) $code]['rates'] ?? throw new InformationException('The account and tax choices could not be read. Reload the page and try again.');
            $sum = array_sum(array_column($rates, 'rate'));
            $left = round($group['tax'], 2);
            foreach ($rates as $i => $rate) {
                $amount = $i === array_key_last($rates) ? $left : ($sum > 0 ? round($group['tax'] * $rate['rate'] / $sum, 2) : 0.0);
                $left = round($left - $amount, 2);
                $detail['TaxLine'][] = ['Amount' => $amount, 'DetailType' => 'TaxLineDetail', 'TaxLineDetail' => [
                    'TaxRateRef' => ['value' => $rate['id']], 'PercentBased' => true, 'TaxPercent' => $rate['rate'], 'NetAmountTaxable' => round($group['net'], 2)]];
            }
        }

        return ['Line' => $out, 'TxnTaxDetail' => $detail, 'GlobalTaxCalculation' => 'TaxExcluded'];
    }

    /** The Item of a line: the mapped one, or for an account (added funds, account credit) the Item booked to it. */
    private function item(string $mapped): string
    {
        if (str_starts_with($mapped, 'item:')) {
            return self::ref($mapped, 'item');
        }
        $account = self::ref($mapped, 'account');
        foreach ($this->items() as $item) {
            if ((string) ($item['IncomeAccountRef']['value'] ?? '') === $account && in_array($item['Type'] ?? '', ['Service', 'NonInventory'], true)) {
                return (string) $item['Id'];
            }
        }

        throw new InformationException('Create a :program product or service whose income account is the account credit account: added funds and account credit are booked with it.', [':program' => self::label()]);
    }

    /** A refund part moved from its account to the one the RefundReceipt paid out of, unless one with its reference is there. */
    private function transfer(array $refund, string $to): string
    {
        foreach ($this->query('Transfer', 'where TxnDate = ' . self::quote((string) $refund['date'])) as $existing) {
            if (($existing['PrivateNote'] ?? null) === $refund['reference']) {
                return (string) $existing['Id'];
            }
        }

        return self::id($this->api('POST', 'transfer', [], [
            'FromAccountRef' => ['value' => self::ref((string) $refund['account'], 'account')],
            'ToAccountRef' => ['value' => self::ref($to, 'account')],
            'Amount' => $refund['amount'],
            'TxnDate' => $refund['date'],
            'PrivateNote' => $refund['reference'],
        ]), 'Transfer');
    }

    private function automatedTax(): bool
    {
        $this->preferences ??= $this->api('GET', 'preferences')['Preferences'] ?? [];

        return (bool) ($this->preferences['TaxPrefs']['PartnerTaxEnabled'] ?? false);
    }

    private function items(): array
    {
        if ($this->items === null) {
            $this->items = [];
            // A query answers 1000 rows at most: page until a short page.
            for ($start = 1; ; $start += 1000) {
                $page = $this->query('Item', 'startposition ' . $start);
                array_push($this->items, ...array_filter($page, static fn (array $i): bool => (bool) ($i['Active'] ?? true)));
                if (count($page) < 1000) {
                    break;
                }
            }
        }

        return $this->items;
    }

    /** @return array<string, array{name: string, rates: list<array{id: string, rate: float}>}> the active codes with sales rates */
    private function taxCodes(): array
    {
        if ($this->taxCodes !== null) {
            return $this->taxCodes;
        }
        $rates = [];
        foreach ($this->query('TaxRate') as $rate) {
            $rates[(string) $rate['Id']] = (float) ($rate['RateValue'] ?? 0);
        }
        $this->taxCodes = [];
        foreach ($this->query('TaxCode') as $code) {
            // US companies have the TAX and NON codes; their rates come from the invoice's tax code.
            $this->us = $this->us || $code['Id'] === 'TAX';
            $details = $code['SalesTaxRateList']['TaxRateDetail'] ?? [];
            if (($code['Active'] ?? true) && $details !== []) {
                $this->taxCodes[(string) $code['Id']] = ['name' => (string) ($code['Name'] ?? $code['Id']), 'rates' => array_map(static fn (array $d): array => [
                    'id' => (string) $d['TaxRateRef']['value'], 'rate' => $rates[(string) $d['TaxRateRef']['value']] ?? 0.0], $details)];
            }
        }

        return $this->taxCodes;
    }

    /** The rows of a query (at most 1000). */
    private function query(string $entity, string $where = ''): array
    {
        return $this->api('GET', 'query', ['query' => trim('select * from ' . $entity . ' ' . $where) . ' maxresults 1000'])['QueryResponse'][$entity] ?? [];
    }

    /** The id of a mapped "item:5" or "account:35"; a value of another program (or none) has to be chosen again. */
    private static function ref(string $value, string $kind): string
    {
        return preg_match('/^' . $kind . ':([0-9]+)$/', $value, $m) === 1 ? $m[1] : throw new InformationException('The account and tax choices could not be read. Reload the page and try again.');
    }

    /** A value in a query: a quote and a backslash are escaped with a backslash. */
    private static function quote(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    private static function id(array $reply, string $entity): string
    {
        $id = $reply[$entity]['Id'] ?? null;

        return (is_string($id) || is_int($id)) && (string) $id !== '' ? (string) $id : throw new InformationException(':program sent an answer ClientFence cannot read.', [':program' => self::label()]);
    }

    private function api(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $options = ['headers' => ['Authorization' => 'Bearer ' . $this->accessToken(), 'Accept' => 'application/json'], 'query' => $query + ['minorversion' => self::MINOR_VERSION]];
        if ($body !== null) {
            $options['json'] = $body;
        }

        return $this->request($method, ($this->sandbox ? self::SANDBOX_API : self::API) . rawurlencode((string) $this->tokens['tenant_id']) . '/' . $path, $options);
    }

    private function accessToken(): string
    {
        if (($this->tokens['refresh_token'] ?? '') === '' || ($this->tokens['tenant_id'] ?? '') === '') {
            throw new InformationException('Connect ClientFence to :program first.', [':program' => self::label()]);
        }
        if ((int) ($this->tokens['expires_at'] ?? 0) <= time() + 60) {
            try {
                $fresh = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => (string) $this->tokens['refresh_token']]);
            } catch (RateLimited $e) {
                throw $e;
            } catch (InformationException $e) {
                // Nothing can go until staff connect again: the sync waits instead of using up every record's tries.
                throw new RateLimited(3600, self::label(), new InformationException('The connection to :program has ended (:error): connect again.', [':program' => self::label(), ':error' => $e->getMessage()]));
            }
            $this->tokens = $fresh + $this->tokens;
            ($this->saveTokens)($this->tokens);
        }

        return (string) $this->tokens['access_token'];
    }

    /** @return array{access_token: string, refresh_token: string, expires_at: int} */
    private function token(array $form): array
    {
        $reply = $this->request('POST', self::TOKEN, [
            'headers' => ['Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret), 'Accept' => 'application/json'],
            'body' => $form,
        ]);
        if (!is_string($reply['access_token'] ?? null) || !is_string($reply['refresh_token'] ?? null)) {
            throw new InformationException(':program sent an answer ClientFence cannot read.', [':program' => self::label()]);
        }

        return ['access_token' => $reply['access_token'], 'refresh_token' => $reply['refresh_token'], 'expires_at' => time() + (int) ($reply['expires_in'] ?? 3600)];
    }

    private function request(string $method, string $url, array $options): array
    {
        try {
            $response = $this->http->request($method, $url, $options + ['timeout' => 60]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
            $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? '60';
        } catch (ExceptionInterface $e) {
            throw new RateLimited(300, self::label(), new InformationException('Could not reach :program: :error', [':program' => self::label(), ':error' => $e->getMessage()]));
        }
        if ($status === 429) {
            throw new RateLimited(max(1, (int) $retryAfter), self::label());
        }
        $reply = json_decode($content, true);
        // QuickBooks down, or the access refused for the whole company: no record is at fault.
        if ($status >= 500 || $status === 401 || $status === 403) {
            throw new RateLimited(300, self::label(), new InformationException(':program refused the request: :error', [':program' => self::label(), ':error' => self::error($reply, $status)]));
        }
        if ($status >= 400) {
            throw new InformationException(':program refused the request: :error', [':program' => self::label(), ':error' => self::error($reply, $status)]);
        }

        return is_array($reply) ? $reply : throw new InformationException(':program sent an answer ClientFence cannot read.', [':program' => self::label()]);
    }

    /** The Fault's errors (their Detail, or only the Message when the access was refused: a Detail can quote the token), else the OAuth error. */
    private static function error(mixed $reply, int $status): string
    {
        if (!is_array($reply)) {
            return 'HTTP ' . $status;
        }
        $messages = [];
        foreach ($reply['Fault']['Error'] ?? $reply['fault']['error'] ?? [] as $error) {
            $error = array_change_key_case(is_array($error) ? $error : [], CASE_LOWER);
            $messages[] = (string) ($status !== 401 && $status !== 403 && ($error['detail'] ?? '') !== '' ? $error['detail'] : ($error['message'] ?? ''));
        }
        $messages = array_values(array_unique(array_filter($messages)));

        return $messages !== [] ? implode(' ', $messages) : (string) ($reply['error'] ?? ('HTTP ' . $status));
    }
}
