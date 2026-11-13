<?php

declare(strict_types=1);

/**
 * ClientFence: the Xero adapter of the accounting sync (Program.php, Sync.php). Written from the Xero Accounting API
 * documentation (https://developer.xero.com/documentation/api/accounting/overview) and its OAuth2 guide
 * (https://developer.xero.com/documentation/guides/oauth2/auth-flow); the field names were checked against the Xero
 * OpenAPI description (https://github.com/XeroAPI/Xero-OpenAPI, MIT). No code was copied.
 *
 * OAuth2 authorization code with each install's own Xero app. Access tokens last 30 minutes and are refreshed here,
 * refresh tokens rotate (each new set goes to $saveTokens). The organisation is the connection of this sign-in
 * (GET /connections, matched on the token's authentication_event_id), sent as the xero-tenant-id header. Apps made from
 * 2 March 2026 get only the granular scopes (SCOPES).
 *
 * Contacts are found by ContactNumber (the ClientFence client id); a name another contact already has gets " (#id)".
 * Invoices are ACCREC, AUTHORISED, numbered as ClientFence numbers them, with net lines and their tax amounts;
 * payments are Payments on the invoice; a refund is an ACCRECCREDIT credit note paid out (Payments on the credit note)
 * from the bank account of the payment method, or into the account credit account. Payments are found again by
 * their Reference on the invoice or credit note.
 */

namespace ClientFence\Mod\Invoice\Accounting;

use ClientFence\InformationException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Xero implements Program
{
    public const SCOPES = 'offline_access accounting.contacts accounting.invoices accounting.payments accounting.settings.read';
    private const AUTHORIZE = 'https://login.xero.com/identity/connect/authorize';
    private const TOKEN = 'https://identity.xero.com/connect/token';
    private const CONNECTIONS = 'https://api.xero.com/connections';
    private const API = 'https://api.xero.com/api.xro/2.0/';

    // Xero's test organisation (the Demo Company) is connected like any other: no separate environment.
    public function __construct(private readonly HttpClientInterface $http, private readonly string $clientId, private readonly string $clientSecret, private array $tokens, private readonly \Closure $saveTokens, bool $sandbox = false)
    {
    }

    public static function label(): string
    {
        return 'Xero';
    }

    public static function hasSandbox(): bool
    {
        return false;
    }

    public function authorizeUrl(string $state, string $redirect): string
    {
        return self::AUTHORIZE . '?' . http_build_query(['response_type' => 'code', 'client_id' => $this->clientId, 'redirect_uri' => $redirect, 'scope' => self::SCOPES, 'state' => $state], '', '&', PHP_QUERY_RFC3986);
    }

    public function connect(string $code, string $redirect, array $callback): array
    {
        $tokens = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirect]);
        $connections = $this->request('GET', self::CONNECTIONS, ['headers' => ['Authorization' => 'Bearer ' . $tokens['access_token'], 'Accept' => 'application/json']]);
        $claims = json_decode((string) base64_decode(strtr(explode('.', $tokens['access_token'])[1] ?? '', '-_', '+/')), true);
        $event = (string) (is_array($claims) ? ($claims['authentication_event_id'] ?? '') : '');
        $organisations = array_values(array_filter($connections, static fn (mixed $c): bool => is_array($c) && ($c['tenantType'] ?? '') === 'ORGANISATION'));
        $chosen = $organisations[0] ?? throw new InformationException('Xero connected no organisation: choose one when you connect.');
        foreach ($organisations as $organisation) {
            if ($event !== '' && ($organisation['authEventId'] ?? '') === $event) {
                $chosen = $organisation;
            }
        }

        return $tokens + ['tenant_id' => (string) $chosen['tenantId'], 'tenant_name' => (string) ($chosen['tenantName'] ?? '')];
    }

    public function accounts(): array
    {
        $out = [];
        foreach ($this->api('GET', 'Accounts')['Accounts'] ?? [] as $a) {
            if (($a['Status'] ?? '') !== 'ACTIVE') {
                continue;
            }
            $code = (string) ($a['Code'] ?? '');
            $out[] = [
                // Bank accounts may have no code; the AccountID then names them.
                'id' => $code !== '' ? $code : (string) $a['AccountID'],
                'name' => ($code !== '' ? $code . ' - ' : '') . (string) ($a['Name'] ?? ''),
                'kind' => ($a['Type'] ?? '') === 'BANK' ? 'bank' : (($a['Class'] ?? '') === 'REVENUE' ? 'sales' : 'other'),
                'payments' => ($a['Type'] ?? '') === 'BANK' || !empty($a['EnablePaymentsToAccount']),
            ];
        }

        return $out;
    }

    public function taxRates(): array
    {
        $out = [];
        foreach ($this->api('GET', 'TaxRates')['TaxRates'] ?? [] as $t) {
            if (($t['Status'] ?? '') === 'ACTIVE' && !empty($t['CanApplyToRevenue'])) {
                $out[] = ['id' => (string) $t['TaxType'], 'name' => (string) ($t['Name'] ?? $t['TaxType']), 'rate' => (float) ($t['EffectiveRate'] ?? 0)];
            }
        }

        return $out;
    }

    public function contact(array $client): string
    {
        $number = (string) $client['id'];
        $id = $client['remote_id'] ?? $this->find('Contacts', ['where' => 'ContactNumber==' . self::quote($number), 'includeArchived' => 'true'], 'ContactID');
        $name = (string) $client['name'];
        // Xero contact names are unique.
        $other = $this->find('Contacts', ['where' => 'Name==' . self::quote($name), 'includeArchived' => 'true'], 'ContactID');
        if ($other !== null && $other !== $id) {
            $name .= ' (#' . $number . ')';
        }
        $body = array_filter(['ContactID' => $id], static fn (?string $v): bool => $v !== null) + [
            'Name' => $name,
            'ContactNumber' => $number,
            'FirstName' => (string) $client['first_name'],
            'LastName' => (string) $client['last_name'],
            'EmailAddress' => (string) $client['email'],
            'TaxNumber' => (string) $client['vat'],
            'Addresses' => [['AddressType' => 'POBOX', 'AddressLine1' => (string) $client['address_1'], 'AddressLine2' => (string) $client['address_2'],
                'City' => (string) $client['city'], 'Region' => (string) $client['state'], 'PostalCode' => (string) $client['zip'], 'Country' => (string) $client['country']]],
            'Phones' => [['PhoneType' => 'DEFAULT', 'PhoneNumber' => (string) $client['phone'], 'PhoneCountryCode' => (string) $client['phone_cc']]],
        ];

        return self::id($this->api('POST', 'Contacts', [], ['Contacts' => [$body]]), 'Contacts', 'ContactID');
    }

    public function invoice(array $doc): string
    {
        $id = $doc['remote_id'] ?? null;
        if ($id === null) {
            foreach ($this->api('GET', 'Invoices', ['InvoiceNumbers' => $doc['number']])['Invoices'] ?? [] as $found) {
                if (($found['Type'] ?? '') === 'ACCREC' && !in_array($found['Status'] ?? '', ['DELETED', 'VOIDED'], true)) {
                    $id = (string) $found['InvoiceID'];

                    break;
                }
            }
        }
        $body = array_filter(['InvoiceID' => $id], static fn (?string $v): bool => $v !== null) + [
            'Type' => 'ACCREC',
            'Contact' => ['ContactID' => $doc['contact']],
            'InvoiceNumber' => $doc['number'],
            'Date' => $doc['date'],
            'DueDate' => $doc['due_date'],
            'CurrencyCode' => $doc['currency'],
            'Status' => 'AUTHORISED',
            'LineAmountTypes' => 'Exclusive',
            'LineItems' => self::lines($doc['lines']),
        ];

        return self::id($this->api('POST', 'Invoices', ['unitdp' => 4], ['Invoices' => [$body]]), 'Invoices', 'InvoiceID');
    }

    public function payment(array $payment): string
    {
        return $this->pay('Invoice', (string) $payment['invoice'], $payment);
    }

    public function creditNote(array $credit): string
    {
        $id = $credit['remote_id'] ?? $this->find('CreditNotes', ['where' => 'Type=="ACCRECCREDIT" AND CreditNoteNumber==' . self::quote((string) $credit['number'])], 'CreditNoteID');
        $id ??= self::id($this->api('PUT', 'CreditNotes', ['unitdp' => 4], ['CreditNotes' => [[
            'Type' => 'ACCRECCREDIT',
            'Contact' => ['ContactID' => $credit['contact']],
            'CreditNoteNumber' => $credit['number'],
            'Reference' => (string) ($credit['reference'] ?? ''),
            'Date' => $credit['date'],
            'CurrencyCode' => $credit['currency'],
            'Status' => 'AUTHORISED',
            'LineAmountTypes' => 'Exclusive',
            'LineItems' => self::lines($credit['lines']),
        ]]]), 'CreditNotes', 'CreditNoteID');
        foreach ($credit['refunds'] as $refund) {
            $this->pay('CreditNote', $id, $refund + ['currency' => $credit['currency']]);
        }

        return $id;
    }

    public function void(string $remoteInvoiceId): void
    {
        $this->api('POST', 'Invoices/' . rawurlencode($remoteInvoiceId), [], ['Invoices' => [['InvoiceID' => $remoteInvoiceId, 'Status' => 'VOIDED']]]);
    }

    public function voidCreditNote(string $remoteCreditNoteId): void
    {
        $path = 'CreditNotes/' . rawurlencode($remoteCreditNoteId);
        $note = $this->api('GET', $path)['CreditNotes'][0] ?? [];
        if (($note['Status'] ?? '') === 'VOIDED') {
            return;
        }
        // A credit note with payments cannot be voided: its payments are deleted first.
        foreach ($note['Payments'] ?? [] as $payment) {
            if (($payment['Status'] ?? 'AUTHORISED') !== 'DELETED') {
                $this->api('POST', 'Payments/' . rawurlencode((string) $payment['PaymentID']), [], ['Status' => 'DELETED']);
            }
        }
        $this->api('POST', $path, [], ['CreditNotes' => [['CreditNoteID' => $remoteCreditNoteId, 'Status' => 'VOIDED']]]);
    }

    /** A payment on an invoice or credit note ($type Invoice or CreditNote), unless one with its reference is there. */
    private function pay(string $type, string $documentId, array $payment): string
    {
        $documents = $this->api('GET', $type . 's/' . rawurlencode($documentId))[$type . 's'][0] ?? [];
        foreach ($documents['Payments'] ?? [] as $existing) {
            if (($existing['Reference'] ?? null) === $payment['reference'] && ($existing['Status'] ?? 'AUTHORISED') !== 'DELETED') {
                return (string) $existing['PaymentID'];
            }
        }

        return self::id($this->api('PUT', 'Payments', [], ['Payments' => [[
            $type => [$type . 'ID' => $documentId],
            'Account' => [self::isGuid($payment['account']) ? 'AccountID' : 'Code' => $payment['account']],
            'Date' => $payment['date'],
            'Amount' => $payment['amount'],
            'Reference' => $payment['reference'],
        ]]]), 'Payments', 'PaymentID');
    }

    /** The first record of $collection matching $query, or null. */
    private function find(string $collection, array $query, string $idField): ?string
    {
        $found = $this->api('GET', $collection, $query)[$collection][0][$idField] ?? null;

        return $found === null ? null : (string) $found;
    }

    private static function lines(array $lines): array
    {
        return array_map(static fn (array $line): array => [
            'Description' => $line['description'] !== '' ? $line['description'] : '-',
            'Quantity' => $line['quantity'],
            'UnitAmount' => $line['unit_price'],
            self::isGuid($line['account']) ? 'AccountID' : 'AccountCode' => $line['account'],
            'TaxType' => $line['tax'],
            'TaxAmount' => $line['tax_amount'],
        ], $lines);
    }

    private static function isGuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    /** A value in a Xero where filter: a double quote is doubled (Xero answers "Syntax error" to \"; a backslash is plain). */
    private static function quote(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }

    private static function id(array $reply, string $collection, string $field): string
    {
        $id = $reply[$collection][0][$field] ?? null;

        return is_string($id) && $id !== '' ? $id : throw new InformationException(':program sent an answer ClientFence cannot read.', [':program' => self::label()]);
    }

    private function api(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $options = ['headers' => ['Authorization' => 'Bearer ' . $this->accessToken(), 'xero-tenant-id' => (string) $this->tokens['tenant_id'], 'Accept' => 'application/json'], 'query' => $query];
        if ($body !== null) {
            $options['json'] = $body;
        }

        return $this->request($method, self::API . $path, $options);
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

        return ['access_token' => $reply['access_token'], 'refresh_token' => $reply['refresh_token'], 'expires_at' => time() + (int) ($reply['expires_in'] ?? 1800)];
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
        // Xero down, or the access refused for the whole organisation: no record is at fault.
        if ($status >= 500 || $status === 401 || $status === 403) {
            throw new RateLimited(300, self::label(), new InformationException(':program refused the request: :error', [':program' => self::label(), ':error' => self::error($reply, $status)]));
        }
        if ($status >= 400) {
            throw new InformationException(':program refused the request: :error', [':program' => self::label(), ':error' => self::error($reply, $status)]);
        }

        return is_array($reply) ? $reply : throw new InformationException(':program sent an answer ClientFence cannot read.', [':program' => self::label()]);
    }

    /** Xero's reason: the ValidationErrors of a ValidationException, else its Message, Detail or OAuth error. */
    private static function error(mixed $reply, int $status): string
    {
        if (!is_array($reply)) {
            return 'HTTP ' . $status;
        }
        $messages = [];
        foreach ($reply['Elements'] ?? [] as $element) {
            foreach ((array) ($element['ValidationErrors'] ?? []) as $error) {
                $messages[] = (string) ($error['Message'] ?? '');
            }
        }
        $messages = array_values(array_unique(array_filter($messages)));

        return $messages !== [] ? implode(' ', $messages) : (string) ($reply['Message'] ?? $reply['Detail'] ?? $reply['error'] ?? ('HTTP ' . $status));
    }
}
