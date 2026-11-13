<?php

declare(strict_types=1);

/**
 * ClientFence: an accounting program the books are sent to, one way (Sync.php). Xero now; FreeAgent and QuickBooks Online
 * fit the same calls. Sync hands over plain arrays and stores what comes back; every call that writes returns the
 * program's id and is safe to repeat: with the stored id it updates that record, without one it first looks the
 * record up (ContactNumber, invoice or credit note number, payment reference) and only then creates it.
 *
 * Amounts are in the document's currency, rounded to cents; lines are net of tax with their tax amount given, so the
 * program's total is ClientFence's. Account and tax values are the `id`s accounts() and taxRates() return. A 429
 * throws RateLimited; any other refusal throws \ClientFence\InformationException with the program's message, which
 * never carries a secret.
 */

namespace ClientFence\Mod\Invoice\Accounting;

use Symfony\Contracts\HttpClient\HttpClientInterface;

interface Program
{
    /**
     * $tokens as connect() returned them (empty before connecting); $saveTokens receives each refreshed set; $sandbox
     * uses the program's test environment (hasSandbox()).
     */
    public function __construct(HttpClientInterface $http, string $clientId, string $clientSecret, array $tokens, \Closure $saveTokens, bool $sandbox = false);

    /** The program's name, as staff read it. */
    public static function label(): string;

    /** Whether the program has a separate test environment (a sandbox company) to connect to. */
    public static function hasSandbox(): bool;

    /** The address staff are sent to, to let ClientFence in (OAuth2 authorization code). */
    public function authorizeUrl(string $state, string $redirect): string;

    /**
     * Exchanges the code the program sent back ($callback: the whole callback query, for values such as QuickBooks'
     * realmId) for the token set: {access_token, refresh_token, expires_at, tenant_id, tenant_name}.
     */
    public function connect(string $code, string $redirect, array $callback): array;

    /** @return list<array{id: string, name: string, kind: string, payments: bool}> kind: sales, bank or other */
    public function accounts(): array;

    /** @return list<array{id: string, name: string, rate: float}> */
    public function taxRates(): array;

    /**
     * {id, remote_id, name, first_name, last_name, company, email, vat, phone, phone_cc, address_1, address_2, city,
     * state, zip, country, taken}. taken(string $remoteId): bool says another client's record is that contact.
     */
    public function contact(array $client): string;

    /**
     * {remote_id, contact, number, date, due_date, currency, total, lines: [{description, quantity, unit_price,
     * amount, account, tax, tax_amount}]}.
     */
    public function invoice(array $doc): string;

    /**
     * One payment of an invoice: {invoice (remote id), currency, account, amount, date, reference, rate}.
     * rate(string $to): ?float gives units of $to for one of the payment's currency at ClientFence's rates (null: unknown).
     */
    public function payment(array $payment): string;

    /**
     * A credit note, with the invoice's fields (amounts positive) and how the money went back, `refunds`: [{account,
     * amount, date, reference}] (to the bank account of the payment method, or the account credit one), and `rate` as for payment().
     */
    public function creditNote(array $credit): string;

    /** Voids an invoice that has no payment at the program. */
    public function void(string $remoteInvoiceId): void;

    /** Voids a credit note and the refunds paid out of it (a downgrade credit taken back by a later refund). */
    public function voidCreditNote(string $remoteCreditNoteId): void;
}
