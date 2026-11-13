<?php

declare(strict_types=1);

/**
 * ClientFence: the Xero adapter of the accounting sync (Invoice/Accounting/Xero.php), against replies shaped as the Xero
 * Accounting API and OAuth2 docs show them. $routes answers "METHOD path" (path after /api.xro/2.0/, or the identity
 * and connections paths) with an array (JSON), a closure of the query, or a MockResponse; every call is in $calls.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Invoice\Accounting\RateLimited;
use ClientFence\Mod\Invoice\Accounting\Xero;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

function acctXero(array $routes, ?array $tokens = null): object
{
    $world = new stdClass();
    $world->calls = [];
    $world->saved = [];
    $http = new MockHttpClient(function (string $method, string $url, array $options) use ($routes, $world): MockResponse {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = str_starts_with($path, '/api.xro/2.0/') ? substr($path, 13) : $path;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $body = (string) ($options['body'] ?? '');
        $world->calls[] = ['method' => $method, 'path' => $path, 'query' => $query, 'headers' => implode("\n", $options['headers'] ?? []), 'body' => $body, 'json' => json_decode($body, true)];
        $reply = $routes[$method . ' ' . $path] ?? new MockResponse('{"Message":"not found"}', ['http_code' => 404]);
        if ($reply instanceof Closure) {
            $reply = $reply($query);
        }

        return $reply instanceof MockResponse ? $reply : new MockResponse((string) json_encode($reply));
    });
    $tokens ??= ['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_at' => time() + 1800, 'tenant_id' => 'tenant-1', 'tenant_name' => 'Demo Company'];
    $world->xero = new Xero($http, 'client-id-1', 'client-secret-1', $tokens, function (array $tokens) use ($world): void {
        $world->saved[] = $tokens;
    });

    return $world;
}

/** A JWT-shaped access token (only the payload is read). */
function acctXeroJwt(array $claims): string
{
    return 'header.' . rtrim(strtr(base64_encode((string) json_encode($claims)), '+/', '-_'), '=') . '.signature';
}

function acctXeroLines(): array
{
    return [
        ['description' => 'Hosting', 'quantity' => 3.0, 'unit_price' => 19.99, 'amount' => 59.97, 'account' => '200', 'tax' => 'OUTPUT2', 'tax_amount' => 12.0],
        ['description' => 'Discount: Hosting', 'quantity' => 1.0, 'unit_price' => -5.0, 'amount' => -5.0, 'account' => '200', 'tax' => 'OUTPUT2', 'tax_amount' => -1.0],
    ];
}

test('the authorize URL asks for the granular scopes with offline access and carries the state', function (): void {
    $url = acctXero([])->xero->authorizeUrl('state-123', 'https://billing.example.com/admin/invoice/accounting/callback');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://login.xero.com/identity/connect/authorize?')
        ->and($query)->toMatchArray(['response_type' => 'code', 'client_id' => 'client-id-1', 'state' => 'state-123', 'redirect_uri' => 'https://billing.example.com/admin/invoice/accounting/callback'])
        ->and(explode(' ', $query['scope']))->toBe(['offline_access', 'accounting.contacts', 'accounting.invoices', 'accounting.payments', 'accounting.settings.read']);
});

test('the code is exchanged with the app credentials and the organisation of this sign-in is chosen', function (): void {
    $access = acctXeroJwt(['authentication_event_id' => 'event-2']);
    $world = acctXero([
        'POST /connect/token' => ['access_token' => $access, 'refresh_token' => 'refresh-new', 'expires_in' => 1800, 'token_type' => 'Bearer'],
        'GET /connections' => [
            ['id' => 'c1', 'tenantId' => 'tenant-old', 'tenantType' => 'ORGANISATION', 'tenantName' => 'Old Co', 'authEventId' => 'event-1'],
            ['id' => 'c2', 'tenantId' => 'tenant-new', 'tenantType' => 'ORGANISATION', 'tenantName' => 'New Co', 'authEventId' => 'event-2'],
        ],
    ], []);

    $tokens = $world->xero->connect('code-abc', 'https://billing.example.com/cb', ['code' => 'code-abc']);
    parse_str($world->calls[0]['body'], $form);

    expect($tokens)->toMatchArray(['access_token' => $access, 'refresh_token' => 'refresh-new', 'tenant_id' => 'tenant-new', 'tenant_name' => 'New Co'])
        ->and($tokens['expires_at'])->toBeGreaterThan(time() + 1700)
        ->and($form)->toBe(['grant_type' => 'authorization_code', 'code' => 'code-abc', 'redirect_uri' => 'https://billing.example.com/cb'])
        ->and($world->calls[0]['headers'])->toContain('Authorization: Basic ' . base64_encode('client-id-1:client-secret-1'))
        ->and($world->calls[1]['headers'])->toContain('Authorization: Bearer ' . $access);
});

test('an expired access token is refreshed first and the new set is stored', function (): void {
    $world = acctXero([
        'POST /connect/token' => ['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'expires_in' => 1800],
        'GET Accounts' => ['Accounts' => [
            ['AccountID' => '11111111-2222-3333-4444-555555555555', 'Code' => '', 'Name' => 'Business Bank', 'Type' => 'BANK', 'Class' => 'ASSET', 'Status' => 'ACTIVE'],
            ['AccountID' => 'a2', 'Code' => '200', 'Name' => 'Sales', 'Type' => 'REVENUE', 'Class' => 'REVENUE', 'Status' => 'ACTIVE'],
            ['AccountID' => 'a3', 'Code' => '850', 'Name' => 'Customer credit', 'Type' => 'CURRLIAB', 'Class' => 'LIABILITY', 'Status' => 'ACTIVE', 'EnablePaymentsToAccount' => true],
            ['AccountID' => 'a4', 'Code' => '999', 'Name' => 'Old', 'Type' => 'REVENUE', 'Class' => 'REVENUE', 'Status' => 'ARCHIVED'],
        ]],
    ], ['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_at' => time() - 10, 'tenant_id' => 'tenant-1']);

    $accounts = $world->xero->accounts();
    parse_str($world->calls[0]['body'], $form);

    expect($form)->toBe(['grant_type' => 'refresh_token', 'refresh_token' => 'refresh-1'])
        ->and($world->saved)->toHaveCount(1)
        ->and($world->saved[0])->toMatchArray(['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'tenant_id' => 'tenant-1'])
        ->and($world->calls[1]['headers'])->toContain('Authorization: Bearer access-2')->toContain('xero-tenant-id: tenant-1')
        ->and($accounts)->toBe([
            ['id' => '11111111-2222-3333-4444-555555555555', 'name' => 'Business Bank', 'kind' => 'bank', 'payments' => true],
            ['id' => '200', 'name' => '200 - Sales', 'kind' => 'sales', 'payments' => false],
            ['id' => '850', 'name' => '850 - Customer credit', 'kind' => 'other', 'payments' => true],
        ]);
});

test('a refused refresh says to connect again', function (): void {
    $world = acctXero(['POST /connect/token' => new MockResponse('{"error":"invalid_grant"}', ['http_code' => 400])], ['access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => 0, 'tenant_id' => 't']);

    expect(fn () => $world->xero->taxRates())->toThrow(InformationException::class, 'The connection to Xero has ended (Xero refused the request: invalid_grant): connect again.');
});

test('a new contact is created with its ContactNumber; a known one is updated, its taken name made unique', function (): void {
    $client = ['id' => 7, 'remote_id' => null, 'name' => 'Acme Ltd', 'first_name' => 'Ann', 'last_name' => 'Lee', 'company' => 'Acme Ltd', 'email' => 'ann@example.com', 'vat' => 'GB123',
        'phone' => '7946 0000', 'phone_cc' => '44', 'address_1' => '1 Main St', 'address_2' => '', 'city' => 'London', 'state' => '', 'zip' => 'N1 1AA', 'country' => 'GB'];
    $world = acctXero([
        'GET Contacts' => ['Contacts' => []],
        'POST Contacts' => ['Contacts' => [['ContactID' => 'contact-7']]],
    ]);

    expect($world->xero->contact($client))->toBe('contact-7')
        ->and($world->calls[0]['query'])->toBe(['where' => 'ContactNumber=="7"', 'includeArchived' => 'true'])
        ->and($world->calls[1]['query']['where'])->toBe('Name=="Acme Ltd"')
        ->and($world->calls[2]['json']['Contacts'][0])->not->toHaveKey('ContactID')
        ->and($world->calls[2]['json']['Contacts'][0])->toMatchArray(['Name' => 'Acme Ltd', 'ContactNumber' => '7', 'EmailAddress' => 'ann@example.com', 'TaxNumber' => 'GB123'])
        ->and($world->calls[2]['json']['Contacts'][0]['Addresses'][0])->toMatchArray(['AddressType' => 'POBOX', 'City' => 'London', 'PostalCode' => 'N1 1AA', 'Country' => 'GB']);

    $world = acctXero([
        'GET Contacts' => fn (array $query): array => ['Contacts' => [['ContactID' => 'someone-else']]],
        'POST Contacts' => ['Contacts' => [['ContactID' => 'contact-7']]],
    ]);
    $world->xero->contact(['remote_id' => 'contact-7'] + $client);

    expect(array_column($world->calls, 'method'))->toBe(['GET', 'POST'])
        ->and($world->calls[1]['json']['Contacts'][0])->toMatchArray(['ContactID' => 'contact-7', 'Name' => 'Acme Ltd (#7)', 'ContactNumber' => '7']);
});

test('an invoice goes as AUTHORISED ACCREC with its number, net lines, accounts and tax amounts', function (): void {
    $world = acctXero([
        'GET Invoices' => ['Invoices' => [['InvoiceID' => 'bill-1', 'Type' => 'ACCPAY', 'Status' => 'AUTHORISED']]],
        'POST Invoices' => ['Invoices' => [['InvoiceID' => 'inv-1']]],
    ]);
    $id = $world->xero->invoice(['remote_id' => null, 'contact' => 'contact-7', 'number' => 'INV00012', 'date' => '2026-11-05', 'due_date' => '2026-11-12', 'currency' => 'GBP', 'total' => 65.97, 'lines' => acctXeroLines()]);
    $sent = $world->calls[1]['json']['Invoices'][0];

    expect($id)->toBe('inv-1')
        ->and($world->calls[0]['query'])->toBe(['InvoiceNumbers' => 'INV00012'])
        // A supplier bill with the same number is not ours.
        ->and($sent)->not->toHaveKey('InvoiceID')
        ->and($world->calls[1]['query'])->toBe(['unitdp' => '4'])
        ->and($sent)->toMatchArray(['Type' => 'ACCREC', 'Status' => 'AUTHORISED', 'InvoiceNumber' => 'INV00012', 'Contact' => ['ContactID' => 'contact-7'],
            'Date' => '2026-11-05', 'DueDate' => '2026-11-12', 'CurrencyCode' => 'GBP', 'LineAmountTypes' => 'Exclusive'])
        ->and($sent['LineItems'])->toBe([
            ['Description' => 'Hosting', 'Quantity' => 3.0, 'UnitAmount' => 19.99, 'AccountCode' => '200', 'TaxType' => 'OUTPUT2', 'TaxAmount' => 12.0],
            ['Description' => 'Discount: Hosting', 'Quantity' => 1.0, 'UnitAmount' => -5.0, 'AccountCode' => '200', 'TaxType' => 'OUTPUT2', 'TaxAmount' => -1.0],
        ]);

    // With the stored id it updates without a look-up.
    $world = acctXero(['POST Invoices' => ['Invoices' => [['InvoiceID' => 'inv-1']]]]);
    $world->xero->invoice(['remote_id' => 'inv-1', 'contact' => 'contact-7', 'number' => 'INV00012', 'date' => '2026-11-05', 'due_date' => '2026-11-12', 'currency' => 'GBP', 'total' => 65.97, 'lines' => acctXeroLines()]);
    expect(array_column($world->calls, 'path'))->toBe(['Invoices'])
        ->and($world->calls[0]['json']['Invoices'][0]['InvoiceID'])->toBe('inv-1');
});

test('a payment is added to the invoice once: a payment with its reference is found again', function (): void {
    $payment = ['invoice' => 'inv-1', 'currency' => 'GBP', 'account' => '11111111-2222-3333-4444-555555555555', 'amount' => 50.0, 'date' => '2026-11-06', 'reference' => 'Transaction 31'];
    $world = acctXero([
        'GET Invoices/inv-1' => ['Invoices' => [['InvoiceID' => 'inv-1', 'Payments' => [['PaymentID' => 'pay-0', 'Reference' => 'Account credit']]]]],
        'PUT Payments' => ['Payments' => [['PaymentID' => 'pay-1']]],
    ]);

    expect($world->xero->payment($payment))->toBe('pay-1')
        ->and($world->calls[1]['json']['Payments'][0])->toBe(['Invoice' => ['InvoiceID' => 'inv-1'], 'Account' => ['AccountID' => '11111111-2222-3333-4444-555555555555'], 'Date' => '2026-11-06', 'Amount' => 50.0, 'Reference' => 'Transaction 31']);

    $world = acctXero(['GET Invoices/inv-1' => ['Invoices' => [['InvoiceID' => 'inv-1', 'Payments' => [['PaymentID' => 'pay-1', 'Reference' => 'Transaction 31']]]]]]);
    expect($world->xero->payment($payment))->toBe('pay-1')
        ->and($world->calls)->toHaveCount(1);
});

test('a credit note is created and its refund paid out of the bank account and into account credit', function (): void {
    $world = acctXero([
        'GET CreditNotes' => ['CreditNotes' => []],
        'PUT CreditNotes' => ['CreditNotes' => [['CreditNoteID' => 'cn-1']]],
        'GET CreditNotes/cn-1' => ['CreditNotes' => [['CreditNoteID' => 'cn-1', 'Payments' => []]]],
        'PUT Payments' => ['Payments' => [['PaymentID' => 'refund-1']]],
    ]);
    $id = $world->xero->creditNote(['remote_id' => null, 'contact' => 'contact-7', 'number' => 'CN-00003', 'reference' => 'INV00012', 'date' => '2026-11-07', 'due_date' => '2026-11-07', 'currency' => 'GBP', 'total' => 65.97,
        'lines' => acctXeroLines(), 'refunds' => [['account' => '850', 'amount' => 15.97, 'date' => '2026-11-07', 'reference' => 'Account credit'], ['account' => '090', 'amount' => 50.0, 'date' => '2026-11-07', 'reference' => 'Refund CN-00003']]]);
    $puts = array_values(array_filter($world->calls, static fn (array $c): bool => $c['method'] === 'PUT'));

    expect($id)->toBe('cn-1')
        ->and($world->calls[0]['query'])->toBe(['where' => 'Type=="ACCRECCREDIT" AND CreditNoteNumber=="CN-00003"'])
        ->and($puts[0]['json']['CreditNotes'][0])->toMatchArray(['Type' => 'ACCRECCREDIT', 'Status' => 'AUTHORISED', 'CreditNoteNumber' => 'CN-00003', 'Reference' => 'INV00012', 'LineAmountTypes' => 'Exclusive'])
        ->and($puts[1]['json']['Payments'][0])->toBe(['CreditNote' => ['CreditNoteID' => 'cn-1'], 'Account' => ['Code' => '850'], 'Date' => '2026-11-07', 'Amount' => 15.97, 'Reference' => 'Account credit'])
        ->and($puts[2]['json']['Payments'][0])->toMatchArray(['CreditNote' => ['CreditNoteID' => 'cn-1'], 'Account' => ['Code' => '090'], 'Amount' => 50.0]);
});

test('a void sets the invoice VOIDED', function (): void {
    $world = acctXero(['POST Invoices/inv-1' => ['Invoices' => [['InvoiceID' => 'inv-1', 'Status' => 'VOIDED']]]]);
    $world->xero->void('inv-1');

    expect($world->calls[0]['json'])->toBe(['Invoices' => [['InvoiceID' => 'inv-1', 'Status' => 'VOIDED']]]);
});

test('a 429 stops with the wait Xero asks for', function (): void {
    $world = acctXero(['GET TaxRates' => new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 37', 'X-Rate-Limit-Problem: minute']])]);

    try {
        $world->xero->taxRates();
        $this->fail('No rate limit');
    } catch (RateLimited $e) {
        expect($e->retryAfter)->toBe(37);
    }
});

test('ValidationErrors become one clear message without a secret in it', function (): void {
    $world = acctXero(['GET Invoices' => ['Invoices' => []], 'POST Invoices' => new MockResponse((string) json_encode([
        'ErrorNumber' => 10, 'Type' => 'ValidationException', 'Message' => 'A validation exception occurred',
        'Elements' => [['ValidationErrors' => [['Message' => 'Account code \'201\' is not a valid code for this document.'], ['Message' => 'The TaxType code \'X\' does not exist or cannot be used for this type of transaction.']]]],
    ]), ['http_code' => 400])]);

    try {
        $world->xero->invoice(['remote_id' => null, 'contact' => 'c', 'number' => 'INV1', 'date' => '2026-11-05', 'due_date' => '2026-11-05', 'currency' => 'GBP', 'total' => 1.0, 'lines' => acctXeroLines()]);
        $this->fail('No error');
    } catch (InformationException $e) {
        expect($e->getMessage())->toBe('Xero refused the request: Account code \'201\' is not a valid code for this document. The TaxType code \'X\' does not exist or cannot be used for this type of transaction.')
            ->not->toContain('client-secret-1')->not->toContain('access-1')->not->toContain('refresh-1');
    }
});

test('Xero down, the access refused or not reachable stops the sync as an outage, not as a record failure', function (MockResponse $reply, int $wait, string $message): void {
    $world = acctXero(['GET TaxRates' => $reply, 'POST /connect/token' => new MockResponse('{"error":"invalid_grant"}', ['http_code' => 400])], $wait === 3600 ? ['access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => 0, 'tenant_id' => 't'] : null);

    try {
        $world->xero->taxRates();
        $this->fail('No outage');
    } catch (RateLimited $e) {
        expect($e->retryAfter)->toBe($wait)
            ->and($e->outage)->toBeInstanceOf(InformationException::class)
            ->and($e->getMessage())->toContain($message);
    }
})->with([
    'server error' => [new MockResponse('{"Message":"Service unavailable"}', ['http_code' => 503]), 300, 'Xero refused the request: Service unavailable'],
    'access refused' => [new MockResponse('{"Detail":"AuthenticationUnsuccessful"}', ['http_code' => 401]), 300, 'AuthenticationUnsuccessful'],
    'not reachable' => [new MockResponse('', ['error' => 'Connection timed out']), 300, 'Could not reach Xero'],
    'connection ended' => [new MockResponse('{}'), 3600, 'The connection to Xero has ended'],
]);

test('voiding a credit note deletes its refund payments first', function (): void {
    $world = acctXero([
        'GET CreditNotes/cn-1' => ['CreditNotes' => [['CreditNoteID' => 'cn-1', 'Status' => 'PAID', 'Payments' => [['PaymentID' => 'p-1', 'Status' => 'AUTHORISED'], ['PaymentID' => 'p-0', 'Status' => 'DELETED']]]]],
        'POST Payments/p-1' => ['Payments' => [['PaymentID' => 'p-1', 'Status' => 'DELETED']]],
        'POST CreditNotes/cn-1' => ['CreditNotes' => [['CreditNoteID' => 'cn-1', 'Status' => 'VOIDED']]],
    ]);

    $world->xero->voidCreditNote('cn-1');

    expect(array_map(static fn (array $c): string => $c['method'] . ' ' . $c['path'], $world->calls))->toBe(['GET CreditNotes/cn-1', 'POST Payments/p-1', 'POST CreditNotes/cn-1'])
        ->and($world->calls[1]['json'])->toBe(['Status' => 'DELETED'])
        ->and($world->calls[2]['json'])->toBe(['CreditNotes' => [['CreditNoteID' => 'cn-1', 'Status' => 'VOIDED']]]);
});
