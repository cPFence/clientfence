<?php

declare(strict_types=1);

/**
 * ClientFence: the FreeAgent adapter of the accounting sync (Program.php, Sync.php). Written from the FreeAgent API
 * documentation (https://dev.freeagent.com/docs): OAuth, Introduction (pagination, rate limits), Company, Contacts,
 * Invoices, Credit Notes, Categories, Bank Accounts, Bank Transaction Explanations and Sales Tax. No code was copied.
 *
 * OAuth2 authorization code with each install's own FreeAgent app, on the sandbox or the live API. Access tokens last
 * an hour and are refreshed here; each new set goes to $saveTokens. The company (GET /company) is the tenant.
 *
 * Records are named by their API URL. Sales map to income categories, payments to bank accounts. FreeAgent takes only
 * sales categories on invoice items, so money into account credit (an Add funds invoice, a surplus, their refunds) is no
 * invoice: it is a transfer between the payment's bank account and the account credit one ("transfer:<url> <number>" stands for
 * the invoice, "transfer:<explanation urls>" for the credit note). FreeAgent works the tax out from each
 * item's rate and has no manual tax amount on invoices, so a total that differs from ClientFence's by rounding gets a
 * "Rounding" item without tax. Invoices and credit notes keep ClientFence's number as their reference and are found by
 * it among the contact's documents, contacts by email and name. A payment is an invoice receipt explanation in the
 * bank account, a refund a credit note refund explanation, found again by paid document and description.
 */

namespace ClientFence\Mod\Invoice\Accounting;

use ClientFence\InformationException;
use Symfony\Component\Intl\Countries;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class FreeAgent implements Program
{
    private readonly string $api;
    private ?array $contacts = null;
    private ?array $banks = null;
    private ?array $company = null;

    public function __construct(private readonly HttpClientInterface $http, private readonly string $clientId, private readonly string $clientSecret, private array $tokens, private readonly \Closure $saveTokens, bool $sandbox = false)
    {
        $this->api = $sandbox ? 'https://api.sandbox.freeagent.com/v2/' : 'https://api.freeagent.com/v2/';
    }

    public static function label(): string
    {
        return 'FreeAgent';
    }

    public static function hasSandbox(): bool
    {
        return true;
    }

    public function authorizeUrl(string $state, string $redirect): string
    {
        return $this->api . 'approve_app?' . http_build_query(['client_id' => $this->clientId, 'response_type' => 'code', 'redirect_uri' => $redirect, 'state' => $state], '', '&', PHP_QUERY_RFC3986);
    }

    public function connect(string $code, string $redirect, array $callback): array
    {
        $tokens = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirect]);
        $company = $this->request('GET', $this->api . 'company', ['headers' => ['Authorization' => 'Bearer ' . $tokens['access_token'], 'Accept' => 'application/json']])['company'] ?? [];
        $id = (string) ($company['id'] ?? $company['subdomain'] ?? '');

        return $id !== '' ? $tokens + ['tenant_id' => $id, 'tenant_name' => (string) ($company['name'] ?? '')] : throw new InformationException(':program sent an answer ClientFence cannot read.', [':program' => self::label()]);
    }

    public function accounts(): array
    {
        $out = [];
        foreach ($this->api('GET', 'categories')['income_categories'] ?? [] as $c) {
            $out[] = ['id' => (string) $c['url'], 'name' => trim(($c['nominal_code'] ?? '') . ' - ' . ($c['description'] ?? ''), ' -'), 'kind' => 'sales', 'payments' => false];
        }
        foreach ($this->banks() as $b) {
            $out[] = ['id' => (string) $b['url'], 'name' => (string) ($b['name'] ?? '') . ' (' . ($b['currency'] ?? '') . ')', 'kind' => 'bank', 'payments' => true];
        }

        return $out;
    }

    public function taxRates(): array
    {
        $company = $this->api('GET', 'company')['company'] ?? [];
        $rates = ['0' => 0.0];
        foreach ((array) ($company['sales_tax_rates'] ?? []) as $r) {
            $rate = (float) (is_array($r) ? ($r['rate'] ?? $r['percentage'] ?? 0) : $r);
            $rates[self::rate($rate)] = $rate;
        }
        $out = [];
        foreach ($rates as $id => $rate) {
            $out[] = ['id' => (string) $id, 'name' => trim(($company['sales_tax_name'] ?? '') . ' ' . $id . '%'), 'rate' => $rate];
        }
        // Not rates: an EXEMPT item, and a 0% item on an invoice with ec_status "Reverse Charge" (items()).
        $out[] = ['id' => 'exempt', 'name' => 'Exempt', 'rate' => 0.0];
        $out[] = ['id' => 'reverse_charge', 'name' => 'Reverse Charge', 'rate' => 0.0];

        return $out;
    }

    public function contact(array $client): string
    {
        $person = trim($client['first_name'] . ' ' . $client['last_name']);
        $body = [
            // A contact needs an organisation name or both first and last names.
            'organisation_name' => trim((string) $client['company']) !== '' ? trim((string) $client['company']) : ($client['first_name'] === '' || $client['last_name'] === '' ? (string) $client['name'] : ''),
            'first_name' => (string) $client['first_name'],
            'last_name' => (string) $client['last_name'],
            'email' => (string) $client['email'],
            'phone_number' => trim(($client['phone_cc'] !== '' ? '+' . $client['phone_cc'] . ' ' : '') . $client['phone']),
            'address1' => (string) $client['address_1'],
            'address2' => (string) $client['address_2'],
            'town' => (string) $client['city'],
            'region' => (string) $client['state'],
            'postcode' => (string) $client['zip'],
            'country' => Countries::exists((string) $client['country']) ? Countries::getName((string) $client['country'], 'en') : (string) $client['country'],
            'sales_tax_registration_number' => (string) $client['vat'],
        ];
        $url = $client['remote_id'] ?? null;
        if ($url === null) {
            // No client number field: a contact with the same email and name that no other client has is this client. It
            // is taken as it is: a client cannot overwrite a contact by naming it.
            $this->contacts ??= $this->all('contacts', 'contacts', ['view' => 'all']);
            foreach ($this->contacts as $c) {
                if (strcasecmp((string) ($c['email'] ?? ''), $body['email']) === 0 && trim((string) ($c['organisation_name'] ?? '') ?: ($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) === ($body['organisation_name'] ?: $person)
                    && !(isset($client['taken']) && ($client['taken'])((string) $c['url']))) {
                    return (string) $c['url'];
                }
            }

            return self::url($this->api('POST', 'contacts', [], ['contact' => $body]), 'contact');
        }
        // FreeAgent may answer an update without a body.
        $this->api('PUT', $url, [], ['contact' => $body]);

        return $url;
    }

    public function invoice(array $doc): string
    {
        $credit = $this->creditOnly($doc);

        return $credit !== null ? 'transfer:' . $credit . ' ' . $doc['number'] : $this->save('invoices', $doc['remote_id'] ?? $this->find('invoices', $doc)['url'] ?? null, $doc, 1);
    }

    public function payment(array $payment): string
    {
        $invoice = (string) $payment['invoice'];
        if (str_starts_with($invoice, 'transfer:')) {
            [$credit, $number] = explode(' ', substr($invoice, 9), 2) + [1 => ''];

            // The number keeps an overpayment's surplus (same transaction reference) apart from the payment itself.
            return $payment['account'] === $credit ? $credit : $this->transfer($credit, ['reference' => $payment['reference'] . ', ' . $number] + $payment, 1);
        }

        return $this->explain($invoice, $payment, 1);
    }

    public function creditNote(array $credit): string
    {
        if (str_starts_with((string) ($credit['remote_id'] ?? ''), 'transfer:')) {
            return (string) $credit['remote_id'];
        }
        $account = $this->creditOnly($credit);
        if ($account !== null) {
            // What goes back into account credit cancels out there: only money paid out moves.
            $moved = [];
            foreach ($credit['refunds'] as $refund) {
                if ($refund['account'] !== $account) {
                    $moved[] = $this->transfer($account, $refund + ['currency' => $credit['currency'], 'rate' => $credit['rate'] ?? null], -1);
                }
            }

            return 'transfer:' . implode(' ', $moved);
        }
        $found = ($credit['remote_id'] ?? null) === null ? $this->find('credit_notes', $credit) : ['url' => $credit['remote_id']];
        // A refunded credit note is not changed again; one left a draft is finished.
        $url = $found === null || ($found['status'] ?? '') === 'Draft' ? $this->save('credit_notes', $found['url'] ?? null, $credit, -1) : (string) $found['url'];
        foreach ($credit['refunds'] as $refund) {
            $this->explain($url, $refund + ['currency' => $credit['currency']], -1);
        }

        return $url;
    }

    public function void(string $remoteInvoiceId): void
    {
        // An unpaid transfer invoice booked nothing.
        if (str_starts_with($remoteInvoiceId, 'transfer:')) {
            return;
        }
        // FreeAgent cancels only an overdue invoice, and deletes only a draft: the unpaid invoice goes back to draft first.
        $invoice = $this->api('GET', $remoteInvoiceId, [], null, true)['invoice'] ?? null;
        // Gone already: an earlier void whose reply was lost.
        if ($invoice !== null) {
            $this->delete($remoteInvoiceId, $invoice);
        }
    }

    public function voidCreditNote(string $remoteCreditNoteId): void
    {
        if (str_starts_with($remoteCreditNoteId, 'transfer:')) {
            // Deleting one side of a transfer deletes both.
            foreach (array_filter(explode(' ', substr($remoteCreditNoteId, 9))) as $url) {
                $this->api('DELETE', $url, [], null, true);
            }

            return;
        }
        $note = $this->api('GET', $remoteCreditNoteId, [], null, true)['credit_note'] ?? null;
        if ($note === null) {
            return;
        }
        // Its refunds are dated on its date (Sync::credit()); FreeAgent lists explanations per bank account.
        foreach ($this->banks() as $bank) {
            foreach ($this->all('bank_transaction_explanations', 'bank_transaction_explanations', ['bank_account' => $bank['url'], 'from_date' => $note['dated_on'] ?? '', 'to_date' => $note['dated_on'] ?? '']) as $e) {
                if (self::same($e['paid_invoice'] ?? '', $remoteCreditNoteId)) {
                    $this->api('DELETE', (string) $e['url']);
                }
            }
        }
        // Credit notes have no cancelled status: without its refunds it goes back to draft and is deleted.
        $this->delete($remoteCreditNoteId, $note);
    }

    private function delete(string $url, array $doc): void
    {
        if (($doc['status'] ?? 'Draft') !== 'Draft') {
            $this->api('PUT', $url . '/transitions/mark_as_draft');
        }
        $this->api('DELETE', $url);
    }

    /** Creates or updates an invoice ($sign 1, 'invoices') or credit note (-1, 'credit_notes') and leaves it sent. */
    private function save(string $kind, ?string $url, array $doc, int $sign): string
    {
        $root = rtrim($kind, 's');
        $items = $this->items($doc['lines'], $sign);
        $body = [
            'contact' => $doc['contact'],
            'reference' => $doc['number'],
            'dated_on' => $doc['date'],
            'due_on' => $doc['due_date'],
            'payment_terms_in_days' => max(0, (int) round((strtotime($doc['due_date']) - strtotime($doc['date'])) / 86400)),
            'currency' => $doc['currency'],
        ];
        if (in_array('reverse_charge', array_column($doc['lines'], 'tax'), true)) {
            $body['ec_status'] = 'Reverse Charge';
        }
        if ($kind === 'invoices') {
            // ClientFence emails the client; FreeAgent must not.
            $body += ['send_new_invoice_emails' => false, 'send_reminder_emails' => false, 'send_thank_you_emails' => false];
        }
        if ($url === null) {
            $reply = $this->api('POST', $kind, [], [$root => $body + [$root . '_items' => $items]])[$root] ?? [];
            $url = self::url([$root => $reply], $root);
        } else {
            $old = $this->api('GET', $url)[$root] ?? [];
            // Paid already with the right total (a retry after a lost payment reply): FreeAgent will not reopen it.
            if ((float) ($old['paid_value'] ?? 0) > 0 && round((float) ($old['total_value'] ?? 0) - $sign * $doc['total'], 2) == 0.0) {
                return $url;
            }
            // A sent document is locked: it goes back to draft (refused once it is paid) and is sent again below.
            if (($old['status'] ?? 'Draft') !== 'Draft') {
                $this->api('PUT', $url . '/transitions/mark_as_draft');
            }
            // Items without an id are added: the old ones are removed by id.
            foreach ($old[$root . '_items'] ?? [] as $item) {
                $items[] = ['id' => (int) basename((string) $item['url']), '_destroy' => 1];
            }
            $reply = $this->api('PUT', $url, [], [$root => $body + [$root . '_items' => $items]])[$root] ?? [];
        }
        $difference = round($sign * $doc['total'] - (float) ($reply['total_value'] ?? $sign * $doc['total']), 2);
        // More than a cent a line is not rounding: a tax rate FreeAgent did not apply, or a tax adjustment.
        if (abs($difference) > 0.01 * count($doc['lines']) + 0.001) {
            throw new InformationException('The :program total of :number is :total instead of :expected: check the tax rates in the accounting settings.', [':program' => self::label(), ':number' => $doc['number'], ':total' => $reply['total_value'], ':expected' => number_format($sign * $doc['total'], 2, '.', '')]);
        }
        if ($difference != 0.0) {
            $this->api('PUT', $url, [], [$root => [$root . '_items' => [['item_type' => 'Services', 'description' => 'Rounding', 'quantity' => 1, 'price' => $difference, 'sales_tax_rate' => 0, 'category' => $items[0]['category']]]]]);
        }
        if (($reply['status'] ?? 'Draft') === 'Draft') {
            $this->api('PUT', $url . '/transitions/mark_as_sent');
        }

        return $url;
    }

    /** The contact's invoice or credit note with this document's number, or null. */
    private function find(string $kind, array $doc): ?array
    {
        foreach ($this->all($kind, $kind, ['contact' => $doc['contact']]) as $found) {
            if (($found['reference'] ?? null) === $doc['number']) {
                return $found;
            }
        }

        return null;
    }

    /** An invoice receipt ($sign 1) or credit note refund (-1) in the bank account, unless one with its reference is there. */
    private function explain(string $documentUrl, array $payment, int $sign): string
    {
        $bank = (string) $payment['account'];
        $currency = (string) ($this->banks()[$bank]['currency'] ?? $payment['currency']);
        $amount = ['gross_value' => $sign * $payment['amount']];
        if ($currency !== $payment['currency']) {
            // gross_value is in the bank's currency: in the company's own, the payment's amount is converted at FreeAgent's
            // rate for the document (as FreeAgent shows it) and goes as foreign_currency_value.
            $this->company ??= $this->api('GET', 'company')['company'] ?? [];
            $document = $this->api('GET', $documentUrl);
            $rate = (float) (reset($document)['exchange_rate'] ?? 0);
            if ($currency !== ($this->company['currency'] ?? null) || $rate <= 0) {
                throw new InformationException('The :program bank account is in :bank_currency and the payment in :currency: choose an account in :currency for it.', [':program' => self::label(), ':bank_currency' => $currency, ':currency' => $payment['currency']]);
            }
            $amount = ['gross_value' => round($sign * $payment['amount'] * $rate, 2), 'foreign_currency_value' => $sign * $payment['amount']];
        }
        foreach ($this->all('bank_transaction_explanations', 'bank_transaction_explanations', ['bank_account' => $bank, 'from_date' => $payment['date'], 'to_date' => $payment['date']]) as $e) {
            // FreeAgent writes its own description; receipt_reference is kept.
            if (self::same($e['paid_invoice'] ?? '', $documentUrl) && ($e['receipt_reference'] ?? null) === $payment['reference']) {
                return (string) $e['url'];
            }
        }

        return self::url($this->api('POST', 'bank_transaction_explanations', [], ['bank_transaction_explanation' => [
            'bank_account' => $bank,
            'paid_invoice' => $documentUrl,
            'dated_on' => $payment['date'],
            ...$amount,
            'receipt_reference' => $payment['reference'],
        ]]), 'bank_transaction_explanation');
    }

    /** The account credit account when every line goes to it, else null; such lines among sales lines cannot be booked. */
    private function creditOnly(array $doc): ?string
    {
        $banks = array_filter(array_column($doc['lines'], 'account'), static fn (mixed $a): bool => str_contains((string) $a, '/bank_accounts/'));
        if ($banks === []) {
            return null;
        }

        return count($banks) === count($doc['lines']) ? (string) reset($banks) : throw new InformationException(':program cannot book account credit on an invoice with other lines: :number.', [':program' => self::label(), ':number' => $doc['number']]);
    }

    /**
     * Money between the payment's bank account and the account credit one ($sign 1 into the bank: added funds; -1 out of
     * it: their refund), unless a transfer with its reference is there. FreeAgent makes the other side.
     */
    private function transfer(string $credit, array $payment, int $sign): string
    {
        $bank = (string) $payment['account'];
        $currency = (string) ($this->banks()[$bank]['currency'] ?? $payment['currency']);
        $other = (string) ($this->banks()[$credit]['currency'] ?? $currency);
        // FreeAgent's API has no transfer between currencies: both accounts in one, a foreign payment converted at ClientFence's rate.
        $rate = $currency === $payment['currency'] ? 1.0 : (isset($payment['rate']) ? ($payment['rate'])($currency) : null);
        if ($rate === null || $other !== $currency) {
            throw new InformationException('The :program bank account is in :bank_currency and the payment in :currency: choose an account in :currency for it.', [':program' => self::label(), ':bank_currency' => $rate === null ? $currency : $other, ':currency' => $rate === null ? $payment['currency'] : $currency]);
        }
        foreach ($this->all('bank_transaction_explanations', 'bank_transaction_explanations', ['bank_account' => $bank, 'from_date' => $payment['date'], 'to_date' => $payment['date']]) as $e) {
            if (self::same($e['linked_transfer_account'] ?? '', $credit) && ($e['receipt_reference'] ?? null) === $payment['reference']) {
                return (string) $e['url'];
            }
        }

        return self::url($this->api('POST', 'bank_transaction_explanations', [], ['bank_transaction_explanation' => [
            'bank_account' => $bank,
            'transfer_bank_account' => $credit,
            'dated_on' => $payment['date'],
            'gross_value' => round($sign * $payment['amount'] * $rate, 2),
            'receipt_reference' => $payment['reference'],
        ]]), 'bank_transaction_explanation');
    }

    private function items(array $lines, int $sign): array
    {
        return array_map(fn (array $line): array => [
            'item_type' => 'Services',
            'description' => $line['description'] !== '' ? $line['description'] : '-',
            'quantity' => $line['quantity'],
            'price' => $sign * $line['unit_price'],
            'category' => (string) $line['account'],
        ] + match ($line['tax']) {
            'exempt' => ['sales_tax_status' => 'EXEMPT'],
            'reverse_charge' => ['sales_tax_rate' => 0],
            default => ['sales_tax_rate' => $line['tax']],
        }, $lines);
    }

    /** The active bank accounts by URL. */
    private function banks(): array
    {
        if ($this->banks === null) {
            $this->banks = [];
            foreach ($this->all('bank_accounts', 'bank_accounts') as $b) {
                if (($b['status'] ?? 'active') !== 'hidden') {
                    $this->banks[(string) $b['url']] = $b;
                }
            }
        }

        return $this->banks;
    }

    /** Every page of a list (100 a page). */
    private function all(string $path, string $key, array $query = []): array
    {
        $out = [];
        for ($page = 1; ; ++$page) {
            $rows = $this->api('GET', $path, $query + ['page' => $page, 'per_page' => 100])[$key] ?? [];
            array_push($out, ...array_values($rows));
            if (count($rows) < 100) {
                return $out;
            }
        }
    }

    /** FreeAgent names a credit note an invoice in paid_invoice (/invoices/7 for /credit_notes/7): the ids match. */
    private static function same(string $a, string $b): bool
    {
        return $a !== '' && basename($a) === basename($b);
    }

    private static function rate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.');
    }

    private static function url(array $reply, string $root): string
    {
        $url = $reply[$root]['url'] ?? null;

        return is_string($url) && $url !== '' ? $url : throw new InformationException(':program sent an answer ClientFence cannot read.', [':program' => self::label()]);
    }

    /** $missing: a 404 answers [] (a record deleted already). */
    private function api(string $method, string $path, array $query = [], ?array $body = null, bool $missing = false): array
    {
        $options = ['headers' => ['Authorization' => 'Bearer ' . $this->accessToken(), 'Accept' => 'application/json'], 'query' => $query];
        if ($body !== null) {
            $options['json'] = $body;
        }

        // Records are named by URL; only this API's own get the token.
        return $this->request($method, str_starts_with($path, $this->api) ? $path : $this->api . $path, $options, $missing);
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
        $reply = $this->request('POST', $this->api . 'token_endpoint', [
            'headers' => ['Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret), 'Accept' => 'application/json'],
            'body' => $form,
        ]);
        if (!is_string($reply['access_token'] ?? null) || !is_string($reply['refresh_token'] ?? null)) {
            throw new InformationException(':program sent an answer ClientFence cannot read.', [':program' => self::label()]);
        }

        return ['access_token' => $reply['access_token'], 'refresh_token' => $reply['refresh_token'], 'expires_at' => time() + (int) ($reply['expires_in'] ?? 3600)];
    }

    private function request(string $method, string $url, array $options, bool $missing = false): array
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
        // FreeAgent down, or the connection refused: no record is at fault. (FreeAgent answers 403 to a refused transition.)
        if ($status >= 500 || $status === 401) {
            throw new RateLimited(300, self::label(), new InformationException(':program refused the request: :error', [':program' => self::label(), ':error' => self::error($reply, $status)]));
        }
        if ($status === 404 && $missing) {
            return [];
        }
        if ($status >= 400) {
            throw new InformationException(':program refused the request: :error', [':program' => self::label(), ':error' => self::error($reply, $status)]);
        }

        // A transition or a delete may answer without a body.
        return is_array($reply) ? $reply : (trim($content) === '' ? [] : throw new InformationException(':program sent an answer ClientFence cannot read.', [':program' => self::label()]));
    }

    /** FreeAgent's reason: every message under errors, else the OAuth error. */
    private static function error(mixed $reply, int $status): string
    {
        if (!is_array($reply)) {
            return 'HTTP ' . $status;
        }
        $messages = [];
        if (is_array($reply['errors'] ?? null)) {
            array_walk_recursive($reply['errors'], static function (mixed $value, int|string $key) use (&$messages): void {
                if ($key === 'message') {
                    $messages[] = (string) $value;
                }
            });
        }
        $messages = array_values(array_unique(array_filter($messages)));

        return $messages !== [] ? implode(' ', $messages) : (string) ($reply['error_description'] ?? $reply['error'] ?? ('HTTP ' . $status));
    }
}
