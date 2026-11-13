<?php

declare(strict_types=1);

/**
 * ClientFence: the FreeAgent adapter of the accounting sync (Invoice/Accounting/FreeAgent.php), against replies shaped as
 * the FreeAgent API docs show them. $routes answers "METHOD path" (path after /v2/) with an array (JSON), a closure of
 * the query, or a MockResponse; every call is in $calls.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Invoice\Accounting\FreeAgent;
use ClientFence\Mod\Invoice\Accounting\RateLimited;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

const ACCT_FA = 'https://api.freeagent.com/v2/';

function acctFreeAgent(array $routes, ?array $tokens = null, bool $sandbox = false): object
{
    $world = new stdClass();
    $world->calls = [];
    $world->saved = [];
    $http = new MockHttpClient(function (string $method, string $url, array $options) use ($routes, $world): MockResponse {
        $path = substr((string) parse_url($url, PHP_URL_PATH), 4);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $body = (string) ($options['body'] ?? '');
        $world->calls[] = ['method' => $method, 'url' => $url, 'path' => $path, 'query' => $query, 'headers' => implode("\n", $options['headers'] ?? []), 'body' => $body, 'json' => json_decode($body, true)];
        $reply = $routes[$method . ' ' . $path] ?? new MockResponse('{"errors":{"error":{"message":"Resource not found"}}}', ['http_code' => 404]);
        if ($reply instanceof Closure) {
            $reply = $reply($query);
        }

        return $reply instanceof MockResponse ? $reply : new MockResponse((string) json_encode($reply));
    });
    $tokens ??= ['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_at' => time() + 3600, 'tenant_id' => '12345', 'tenant_name' => 'Demo Ltd'];
    $world->fa = new FreeAgent($http, 'client-id-1', 'client-secret-1', $tokens, function (array $tokens) use ($world): void {
        $world->saved[] = $tokens;
    }, $sandbox);

    return $world;
}

function acctFreeAgentCalls(object $world): array
{
    return array_map(static fn (array $c): string => $c['method'] . ' ' . $c['path'], $world->calls);
}

function acctFreeAgentLines(): array
{
    return [
        ['description' => 'Hosting', 'quantity' => 3.0, 'unit_price' => 19.99, 'amount' => 59.97, 'account' => ACCT_FA . 'categories/001', 'tax' => '20', 'tax_amount' => 12.0],
        ['description' => 'Discount: Hosting', 'quantity' => 1.0, 'unit_price' => -5.0, 'amount' => -5.0, 'account' => ACCT_FA . 'categories/001', 'tax' => '20', 'tax_amount' => -1.01],
    ];
}

function acctFreeAgentDoc(array $extra = []): array
{
    return $extra + ['remote_id' => null, 'contact' => ACCT_FA . 'contacts/7', 'number' => 'INV00012', 'date' => '2026-11-05', 'due_date' => '2026-11-12', 'currency' => 'GBP', 'total' => 65.96, 'lines' => acctFreeAgentLines()];
}

function acctFreeAgentBanks(): array
{
    return ['bank_accounts' => [
        ['url' => ACCT_FA . 'bank_accounts/1', 'name' => 'Business Current', 'currency' => 'GBP', 'status' => 'active'],
        ['url' => ACCT_FA . 'bank_accounts/2', 'name' => 'Client credit', 'currency' => 'GBP', 'status' => 'active'],
        ['url' => ACCT_FA . 'bank_accounts/3', 'name' => 'Old', 'currency' => 'GBP', 'status' => 'hidden'],
    ]];
}

test('the authorize URL goes to approve_app on the live or sandbox host and carries the state', function (): void {
    $url = acctFreeAgent([])->fa->authorizeUrl('state-123', 'https://billing.example.com/admin/invoice/accounting/callback');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://api.freeagent.com/v2/approve_app?')
        ->and($query)->toBe(['client_id' => 'client-id-1', 'response_type' => 'code', 'redirect_uri' => 'https://billing.example.com/admin/invoice/accounting/callback', 'state' => 'state-123'])
        ->and(acctFreeAgent([], null, true)->fa->authorizeUrl('s', 'https://b.example.com/cb'))->toStartWith('https://api.sandbox.freeagent.com/v2/approve_app?')
        ->and(FreeAgent::hasSandbox())->toBeTrue();
});

test('the code is exchanged with the app credentials and the company is the tenant', function (): void {
    $world = acctFreeAgent([
        'POST token_endpoint' => ['access_token' => 'access-new', 'token_type' => 'bearer', 'expires_in' => 3600, 'refresh_token' => 'refresh-new', 'refresh_token_expires_in' => 631151957],
        'GET company' => ['company' => ['url' => ACCT_FA . 'company', 'id' => '12345', 'name' => 'My Company', 'subdomain' => 'mycompany', 'type' => 'UkLimitedCompany', 'currency' => 'GBP']],
    ], []);

    $tokens = $world->fa->connect('code-abc', 'https://billing.example.com/cb', ['code' => 'code-abc']);
    parse_str($world->calls[0]['body'], $form);

    expect($tokens)->toMatchArray(['access_token' => 'access-new', 'refresh_token' => 'refresh-new', 'tenant_id' => '12345', 'tenant_name' => 'My Company'])
        ->and($tokens['expires_at'])->toBeGreaterThan(time() + 3500)
        ->and($form)->toBe(['grant_type' => 'authorization_code', 'code' => 'code-abc', 'redirect_uri' => 'https://billing.example.com/cb'])
        ->and($world->calls[0]['headers'])->toContain('Authorization: Basic ' . base64_encode('client-id-1:client-secret-1'))
        ->and($world->calls[1]['headers'])->toContain('Authorization: Bearer access-new');
});

test('an expired access token is refreshed first; income categories and active bank accounts are the accounts', function (): void {
    $world = acctFreeAgent([
        'POST token_endpoint' => ['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'expires_in' => 3600],
        'GET categories' => [
            'admin_expenses_categories' => [['url' => ACCT_FA . 'categories/285', 'description' => 'Accommodation and Meals', 'nominal_code' => '285']],
            'income_categories' => [['url' => ACCT_FA . 'categories/001', 'description' => 'Sales', 'nominal_code' => '001', 'auto_sales_tax_rate' => 'Standard rate']],
            'general_categories' => [['url' => ACCT_FA . 'categories/051', 'description' => 'Interest Received', 'nominal_code' => '051']],
        ],
        'GET bank_accounts' => acctFreeAgentBanks(),
    ], ['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_at' => time() - 10, 'tenant_id' => '12345']);

    $accounts = $world->fa->accounts();
    parse_str($world->calls[0]['body'], $form);

    expect($form)->toBe(['grant_type' => 'refresh_token', 'refresh_token' => 'refresh-1'])
        ->and($world->saved)->toHaveCount(1)
        ->and($world->saved[0])->toMatchArray(['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'tenant_id' => '12345'])
        ->and($world->calls[1]['headers'])->toContain('Authorization: Bearer access-2')
        ->and($accounts)->toBe([
            ['id' => ACCT_FA . 'categories/001', 'name' => '001 - Sales', 'kind' => 'sales', 'payments' => false],
            ['id' => ACCT_FA . 'bank_accounts/1', 'name' => 'Business Current (GBP)', 'kind' => 'bank', 'payments' => true],
            ['id' => ACCT_FA . 'bank_accounts/2', 'name' => 'Client credit (GBP)', 'kind' => 'bank', 'payments' => true],
        ]);
});

test('a refused refresh says to connect again', function (): void {
    $world = acctFreeAgent(['POST token_endpoint' => new MockResponse('{"error":"invalid_grant"}', ['http_code' => 400])], ['access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => 0, 'tenant_id' => 't']);

    expect(fn () => $world->fa->taxRates())->toThrow(InformationException::class, 'The connection to FreeAgent has ended (FreeAgent refused the request: invalid_grant): connect again.');
});

test('the tax rates are the company rates, no tax, exempt and reverse charge', function (): void {
    $world = acctFreeAgent(['GET company' => ['company' => ['id' => '12345', 'sales_tax_name' => 'VAT', 'sales_tax_registration_status' => 'Registered', 'sales_tax_rates' => ['20.0', '5.0', '0.0']]]]);

    expect($world->fa->taxRates())->toBe([
        ['id' => '0', 'name' => 'VAT 0%', 'rate' => 0.0],
        ['id' => '20', 'name' => 'VAT 20%', 'rate' => 20.0],
        ['id' => '5', 'name' => 'VAT 5%', 'rate' => 5.0],
        ['id' => 'exempt', 'name' => 'Exempt', 'rate' => 0.0],
        ['id' => 'reverse_charge', 'name' => 'Reverse Charge', 'rate' => 0.0],
    ]);
});

test('a new contact is created; one with the same email and name is taken as it is unless another client has it; a stored one is updated', function (): void {
    $client = ['id' => 7, 'remote_id' => null, 'name' => 'Acme Ltd', 'first_name' => 'Ann', 'last_name' => 'Lee', 'company' => 'Acme Ltd', 'email' => 'ann@example.com', 'vat' => 'GB123456789',
        'phone' => '7946 0000', 'phone_cc' => '44', 'address_1' => '1 Main St', 'address_2' => '', 'city' => 'London', 'state' => '', 'zip' => 'N1 1AA', 'country' => 'GB'];
    $world = acctFreeAgent([
        'GET contacts' => ['contacts' => [['url' => ACCT_FA . 'contacts/1', 'organisation_name' => 'Acme Ltd', 'email' => 'other@example.com']]],
        'POST contacts' => ['contact' => ['url' => ACCT_FA . 'contacts/70']],
    ]);

    expect($world->fa->contact($client))->toBe(ACCT_FA . 'contacts/70')
        ->and($world->calls[0]['query'])->toBe(['view' => 'all', 'page' => '1', 'per_page' => '100'])
        ->and($world->calls[1]['json']['contact'])->toMatchArray(['organisation_name' => 'Acme Ltd', 'first_name' => 'Ann', 'last_name' => 'Lee', 'email' => 'ann@example.com',
            'phone_number' => '+44 7946 0000', 'address1' => '1 Main St', 'town' => 'London', 'postcode' => 'N1 1AA', 'country' => 'United Kingdom', 'sales_tax_registration_number' => 'GB123456789']);

    $world = acctFreeAgent([
        'GET contacts' => ['contacts' => [['url' => ACCT_FA . 'contacts/9', 'first_name' => 'Bo', 'last_name' => 'Ray', 'email' => 'BO@example.com']]],
        'POST contacts' => ['contact' => ['url' => ACCT_FA . 'contacts/71']],
    ]);
    $person = ['name' => 'Bo Ray', 'first_name' => 'Bo', 'last_name' => 'Ray', 'company' => '', 'email' => 'bo@example.com'] + $client;

    // Not overwritten: a client could name someone else's contact.
    expect($world->fa->contact($person))->toBe(ACCT_FA . 'contacts/9')
        ->and(acctFreeAgentCalls($world))->toBe(['GET contacts']);

    // Another client's contact is not shared: a new one is made.
    expect($world->fa->contact(['taken' => fn (string $url): bool => $url === ACCT_FA . 'contacts/9'] + $person))->toBe(ACCT_FA . 'contacts/71')
        ->and(end($world->calls)['json']['contact']['organisation_name'])->toBe('');

    // An update may answer without a body: the stored contact is kept.
    $world = acctFreeAgent(['PUT contacts/70' => new MockResponse('')]);
    expect($world->fa->contact(['remote_id' => ACCT_FA . 'contacts/70'] + $client))->toBe(ACCT_FA . 'contacts/70')
        ->and(acctFreeAgentCalls($world))->toBe(['PUT contacts/70']);
});

test('an invoice is created with its number, net items, categories and rates, then marked as sent', function (): void {
    $world = acctFreeAgent([
        'GET invoices' => ['invoices' => [['url' => ACCT_FA . 'invoices/4', 'reference' => 'INV00011', 'status' => 'Open']]],
        'POST invoices' => ['invoice' => ['url' => ACCT_FA . 'invoices/5', 'status' => 'Draft', 'total_value' => '65.96']],
        'PUT invoices/5/transitions/mark_as_sent' => new MockResponse(''),
    ]);

    expect($world->fa->invoice(acctFreeAgentDoc()))->toBe(ACCT_FA . 'invoices/5')
        ->and(acctFreeAgentCalls($world))->toBe(['GET invoices', 'POST invoices', 'PUT invoices/5/transitions/mark_as_sent'])
        ->and($world->calls[0]['query'])->toMatchArray(['contact' => ACCT_FA . 'contacts/7']);
    $sent = $world->calls[1]['json']['invoice'];
    expect($sent)->toMatchArray(['contact' => ACCT_FA . 'contacts/7', 'reference' => 'INV00012', 'dated_on' => '2026-11-05', 'due_on' => '2026-11-12', 'payment_terms_in_days' => 7, 'currency' => 'GBP',
        'send_new_invoice_emails' => false, 'send_reminder_emails' => false, 'send_thank_you_emails' => false])
        ->and($sent)->not->toHaveKey('ec_status')
        ->and($sent['invoice_items'])->toBe([
            ['item_type' => 'Services', 'description' => 'Hosting', 'quantity' => 3.0, 'price' => 19.99, 'category' => ACCT_FA . 'categories/001', 'sales_tax_rate' => '20'],
            ['item_type' => 'Services', 'description' => 'Discount: Hosting', 'quantity' => 1.0, 'price' => -5.0, 'category' => ACCT_FA . 'categories/001', 'sales_tax_rate' => '20'],
        ]);
});

test('reverse charge and exempt items', function (): void {
    $world = acctFreeAgent([
        'GET invoices' => ['invoices' => []],
        'POST invoices' => ['invoice' => ['url' => ACCT_FA . 'invoices/6', 'status' => 'Draft', 'total_value' => '150.0']],
        'PUT invoices/6/transitions/mark_as_sent' => new MockResponse(''),
    ]);
    $world->fa->invoice(acctFreeAgentDoc(['total' => 150.0, 'lines' => [
        ['description' => 'Consulting', 'quantity' => 1.0, 'unit_price' => 100.0, 'amount' => 100.0, 'account' => ACCT_FA . 'categories/001', 'tax' => 'reverse_charge', 'tax_amount' => 0.0],
        ['description' => 'Training', 'quantity' => 1.0, 'unit_price' => 50.0, 'amount' => 50.0, 'account' => ACCT_FA . 'categories/001', 'tax' => 'exempt', 'tax_amount' => 0.0],
    ]]));
    $sent = $world->calls[1]['json']['invoice'];

    expect($sent['ec_status'])->toBe('Reverse Charge')
        ->and($sent['invoice_items'][0])->toMatchArray(['category' => ACCT_FA . 'categories/001', 'sales_tax_rate' => 0])
        ->and($sent['invoice_items'][1])->toMatchArray(['category' => ACCT_FA . 'categories/001', 'sales_tax_status' => 'EXEMPT'])
        ->and($sent['invoice_items'][1])->not->toHaveKey('sales_tax_rate');
});

test('added funds are no invoice: their payment is a transfer from account credit into the bank account, once', function (): void {
    $funds = acctFreeAgentDoc(['number' => 'CREDIT-77', 'total' => 100.0, 'lines' => [
        ['description' => 'Account credit', 'quantity' => 1.0, 'unit_price' => 100.0, 'amount' => 100.0, 'account' => ACCT_FA . 'bank_accounts/2', 'tax' => '0', 'tax_amount' => 0.0],
    ]]);
    $world = acctFreeAgent([
        'GET bank_accounts' => acctFreeAgentBanks(),
        'GET bank_transaction_explanations' => ['bank_transaction_explanations' => []],
        'POST bank_transaction_explanations' => ['bank_transaction_explanation' => ['url' => ACCT_FA . 'bank_transaction_explanations/50']],
    ]);
    $payment = ['account' => ACCT_FA . 'bank_accounts/1', 'amount' => 100.0, 'date' => '2026-11-13', 'reference' => 'Transaction 77', 'currency' => 'GBP'];

    expect($id = $world->fa->invoice($funds))->toBe('transfer:' . ACCT_FA . 'bank_accounts/2 CREDIT-77')
        ->and($world->calls)->toBe([])
        ->and($world->fa->payment(['invoice' => $id] + $payment))->toBe(ACCT_FA . 'bank_transaction_explanations/50')
        ->and(end($world->calls)['json']['bank_transaction_explanation'])->toBe(['bank_account' => ACCT_FA . 'bank_accounts/1', 'transfer_bank_account' => ACCT_FA . 'bank_accounts/2',
            'dated_on' => '2026-11-13', 'gross_value' => 100.0, 'receipt_reference' => 'Transaction 77, CREDIT-77']);

    // Found again by its other account and reference; paid from account credit itself, nothing moves; a void books nothing.
    $world = acctFreeAgent([
        'GET bank_accounts' => acctFreeAgentBanks(),
        'GET bank_transaction_explanations' => ['bank_transaction_explanations' => [['url' => ACCT_FA . 'bank_transaction_explanations/50', 'linked_transfer_account' => ACCT_FA . 'bank_accounts/2', 'receipt_reference' => 'Transaction 77, CREDIT-77']]],
        'POST bank_transaction_explanations' => ['bank_transaction_explanation' => ['url' => ACCT_FA . 'bank_transaction_explanations/51']],
    ]);
    expect($world->fa->payment(['invoice' => $id] + $payment))->toBe(ACCT_FA . 'bank_transaction_explanations/50')
        ->and($world->fa->payment(['invoice' => $id, 'account' => ACCT_FA . 'bank_accounts/2'] + $payment))->toBe(ACCT_FA . 'bank_accounts/2');
    $world->fa->void($id);
    expect(acctFreeAgentCalls($world))->toBe(['GET bank_accounts', 'GET bank_transaction_explanations']);

    // The surplus of an overpaid Add funds invoice has the same transaction reference: its own number keeps it a transfer of its own.
    expect($world->fa->payment(['invoice' => 'transfer:' . ACCT_FA . 'bank_accounts/2 CREDIT-78', 'amount' => 20.0] + $payment))->toBe(ACCT_FA . 'bank_transaction_explanations/51')
        ->and(end($world->calls)['json']['bank_transaction_explanation'])->toMatchArray(['gross_value' => 20.0, 'receipt_reference' => 'Transaction 77, CREDIT-78']);

    // Paid in another currency: converted at ClientFence's rate into the accounts' currency; without a rate, refused.
    $world = acctFreeAgent([
        'GET bank_accounts' => acctFreeAgentBanks(),
        'GET bank_transaction_explanations' => ['bank_transaction_explanations' => []],
        'POST bank_transaction_explanations' => ['bank_transaction_explanation' => ['url' => ACCT_FA . 'bank_transaction_explanations/53']],
    ]);
    $usd = ['invoice' => $id, 'currency' => 'USD', 'amount' => 125.0, 'rate' => fn (string $to): ?float => $to === 'GBP' ? 0.79 : null] + $payment;
    expect($world->fa->payment($usd))->toBe(ACCT_FA . 'bank_transaction_explanations/53')
        ->and(end($world->calls)['json']['bank_transaction_explanation']['gross_value'])->toBe(98.75)
        ->and(fn () => $world->fa->payment(['rate' => fn (string $to): ?float => null] + $usd))
        ->toThrow(InformationException::class, 'The FreeAgent bank account is in GBP and the payment in USD: choose an account in USD for it.');

    // Account credit among sales lines cannot be booked.
    expect(fn () => acctFreeAgent([])->fa->invoice(acctFreeAgentDoc(['lines' => [$funds['lines'][0], acctFreeAgentLines()[0]]])))
        ->toThrow(InformationException::class, 'FreeAgent cannot book account credit on an invoice with other lines: INV00012.');
});

test('a refund of added funds moves only the money paid out back into account credit; its void deletes that transfer', function (): void {
    $world = acctFreeAgent([
        'GET bank_accounts' => acctFreeAgentBanks(),
        'GET bank_transaction_explanations' => ['bank_transaction_explanations' => []],
        'POST bank_transaction_explanations' => ['bank_transaction_explanation' => ['url' => ACCT_FA . 'bank_transaction_explanations/52']],
        'DELETE bank_transaction_explanations/52' => new MockResponse(''),
    ]);
    $id = $world->fa->creditNote(acctFreeAgentDoc(['number' => 'CN-00009', 'total' => 80.0, 'lines' => [
        ['description' => 'Add funds', 'quantity' => 1.0, 'unit_price' => 80.0, 'amount' => 80.0, 'account' => ACCT_FA . 'bank_accounts/2', 'tax' => '0', 'tax_amount' => 0.0],
    ], 'refunds' => [
        ['account' => ACCT_FA . 'bank_accounts/2', 'amount' => 30.0, 'date' => '2026-11-14', 'reference' => 'Account credit'],
        ['account' => ACCT_FA . 'bank_accounts/1', 'amount' => 50.0, 'date' => '2026-11-14', 'reference' => 'Refund CN-00009'],
    ]]));

    expect($id)->toBe('transfer:' . ACCT_FA . 'bank_transaction_explanations/52')
        ->and(array_values(array_filter(acctFreeAgentCalls($world), static fn (string $c): bool => str_starts_with($c, 'POST'))))->toHaveCount(1)
        ->and(end($world->calls)['json']['bank_transaction_explanation'])->toMatchArray(['bank_account' => ACCT_FA . 'bank_accounts/1', 'transfer_bank_account' => ACCT_FA . 'bank_accounts/2', 'gross_value' => -50.0]);

    $world->fa->voidCreditNote($id);
    expect(end($world->calls)['method'] . ' ' . end($world->calls)['path'])->toBe('DELETE bank_transaction_explanations/52');

    // Refunded in another currency: converted at ClientFence's rate, as the payment was.
    $world = acctFreeAgent([
        'GET bank_accounts' => acctFreeAgentBanks(),
        'GET bank_transaction_explanations' => ['bank_transaction_explanations' => []],
        'POST bank_transaction_explanations' => ['bank_transaction_explanation' => ['url' => ACCT_FA . 'bank_transaction_explanations/54']],
    ]);
    $world->fa->creditNote(acctFreeAgentDoc(['number' => 'CN-00010', 'currency' => 'USD', 'total' => 50.0, 'rate' => fn (string $to): ?float => $to === 'GBP' ? 0.79 : null, 'lines' => [
        ['description' => 'Add funds', 'quantity' => 1.0, 'unit_price' => 50.0, 'amount' => 50.0, 'account' => ACCT_FA . 'bank_accounts/2', 'tax' => '0', 'tax_amount' => 0.0],
    ], 'refunds' => [['account' => ACCT_FA . 'bank_accounts/1', 'amount' => 50.0, 'date' => '2026-11-14', 'reference' => 'Refund CN-00010']]]));
    expect(end($world->calls)['json']['bank_transaction_explanation']['gross_value'])->toBe(-39.5);
});

test('a stored sent invoice goes back to draft, is updated with its old items removed and a rounding difference made up, and is sent again', function (): void {
    $world = acctFreeAgent([
        'GET invoices/5' => ['invoice' => ['url' => ACCT_FA . 'invoices/5', 'status' => 'Open', 'invoice_items' => [['url' => ACCT_FA . 'invoice_items/31'], ['url' => ACCT_FA . 'invoice_items/32']]]],
        'PUT invoices/5/transitions/mark_as_draft' => new MockResponse(''),
        'PUT invoices/5' => fn (array $query): array => ['invoice' => ['url' => ACCT_FA . 'invoices/5', 'status' => 'Draft', 'total_value' => '65.95']],
        'PUT invoices/5/transitions/mark_as_sent' => new MockResponse(''),
    ]);

    expect($world->fa->invoice(acctFreeAgentDoc(['remote_id' => ACCT_FA . 'invoices/5'])))->toBe(ACCT_FA . 'invoices/5')
        ->and(acctFreeAgentCalls($world))->toBe(['GET invoices/5', 'PUT invoices/5/transitions/mark_as_draft', 'PUT invoices/5', 'PUT invoices/5', 'PUT invoices/5/transitions/mark_as_sent'])
        ->and(array_slice($world->calls[2]['json']['invoice']['invoice_items'], 2))->toBe([['id' => 31, '_destroy' => 1], ['id' => 32, '_destroy' => 1]])
        ->and($world->calls[2]['json']['invoice']['invoice_items'][0]['description'])->toBe('Hosting')
        ->and($world->calls[3]['json'])->toBe(['invoice' => ['invoice_items' => [['item_type' => 'Services', 'description' => 'Rounding', 'quantity' => 1, 'price' => 0.01, 'sales_tax_rate' => 0, 'category' => ACCT_FA . 'categories/001']]]]);
});

test('a paid invoice with the right total is left as it is: FreeAgent will not reopen it', function (): void {
    $world = acctFreeAgent(['GET invoices/5' => ['invoice' => ['url' => ACCT_FA . 'invoices/5', 'status' => 'Paid', 'paid_value' => '65.96', 'total_value' => '65.96']]]);

    expect($world->fa->invoice(acctFreeAgentDoc(['remote_id' => ACCT_FA . 'invoices/5'])))->toBe(ACCT_FA . 'invoices/5')
        ->and(acctFreeAgentCalls($world))->toBe(['GET invoices/5']);
});

test('a total off by more than rounding is not made up: the tax rate was not applied', function (): void {
    $world = acctFreeAgent([
        'GET invoices' => ['invoices' => []],
        'POST invoices' => ['invoice' => ['url' => ACCT_FA . 'invoices/5', 'status' => 'Draft', 'total_value' => '54.97']],
    ]);

    expect(fn () => $world->fa->invoice(acctFreeAgentDoc()))->toThrow(InformationException::class, 'The FreeAgent total of INV00012 is 54.97 instead of 65.96: check the tax rates in the accounting settings.');
    expect(acctFreeAgentCalls($world))->toBe(['GET invoices', 'POST invoices']);
});

test('a payment is an invoice receipt in the bank account, once: one with its reference is found again', function (): void {
    $payment = ['invoice' => ACCT_FA . 'invoices/5', 'currency' => 'GBP', 'account' => ACCT_FA . 'bank_accounts/1', 'amount' => 50.0, 'date' => '2026-11-06', 'reference' => 'Transaction 31'];
    $explanations = static fn (array $existing): Closure => fn (array $query): array => ['bank_transaction_explanations' => $query['bank_account'] === ACCT_FA . 'bank_accounts/1' && $query['from_date'] === '2026-11-06' && $query['to_date'] === '2026-11-06' ? $existing : []];
    $world = acctFreeAgent([
        'GET bank_accounts' => acctFreeAgentBanks(),
        'GET bank_transaction_explanations' => $explanations([['url' => ACCT_FA . 'bank_transaction_explanations/1', 'paid_invoice' => ACCT_FA . 'invoices/4', 'receipt_reference' => 'Transaction 31']]),
        'POST bank_transaction_explanations' => ['bank_transaction_explanation' => ['url' => ACCT_FA . 'bank_transaction_explanations/2']],
    ]);

    expect($world->fa->payment($payment))->toBe(ACCT_FA . 'bank_transaction_explanations/2')
        ->and($world->calls[2]['json'])->toBe(['bank_transaction_explanation' => ['bank_account' => ACCT_FA . 'bank_accounts/1', 'paid_invoice' => ACCT_FA . 'invoices/5', 'dated_on' => '2026-11-06', 'gross_value' => 50.0, 'receipt_reference' => 'Transaction 31']]);

    $world = acctFreeAgent([
        'GET bank_accounts' => acctFreeAgentBanks(),
        'GET bank_transaction_explanations' => $explanations([['url' => ACCT_FA . 'bank_transaction_explanations/2', 'paid_invoice' => ACCT_FA . 'invoices/5', 'description' => 'Invoice receipt against INV00012', 'receipt_reference' => 'Transaction 31']]),
    ]);
    expect($world->fa->payment($payment))->toBe(ACCT_FA . 'bank_transaction_explanations/2')
        ->and(acctFreeAgentCalls($world))->toBe(['GET bank_accounts', 'GET bank_transaction_explanations']);

    // gross_value is in the bank account's currency: a USD payment into the company's GBP account is converted at
    // FreeAgent's rate for the invoice; a bank in a third currency cannot be converted.
    $routes = [
        'GET bank_accounts' => acctFreeAgentBanks(),
        'GET company' => ['company' => ['currency' => 'GBP']],
        'GET invoices/5' => ['invoice' => ['url' => ACCT_FA . 'invoices/5', 'currency' => 'USD', 'exchange_rate' => '0.8']],
        'GET bank_transaction_explanations' => $explanations([]),
        'POST bank_transaction_explanations' => ['bank_transaction_explanation' => ['url' => ACCT_FA . 'bank_transaction_explanations/3']],
    ];
    $world = acctFreeAgent($routes);
    expect($world->fa->payment(['currency' => 'USD'] + $payment))->toBe(ACCT_FA . 'bank_transaction_explanations/3')
        ->and(end($world->calls)['json']['bank_transaction_explanation'])->toMatchArray(['gross_value' => 40.0, 'foreign_currency_value' => 50.0]);

    $world = acctFreeAgent(['GET company' => ['company' => ['currency' => 'EUR']]] + $routes);
    expect(fn () => $world->fa->payment(['currency' => 'USD'] + $payment))->toThrow(InformationException::class, 'The FreeAgent bank account is in GBP and the payment in USD: choose an account in USD for it.');
});

test('a credit note is created with negative items and refunded out of the bank account and into account credit', function (): void {
    $world = acctFreeAgent([
        'GET credit_notes' => ['credit_notes' => []],
        'POST credit_notes' => ['credit_note' => ['url' => ACCT_FA . 'credit_notes/3', 'status' => 'Draft', 'total_value' => '-65.96']],
        'PUT credit_notes/3/transitions/mark_as_sent' => new MockResponse(''),
        'GET bank_accounts' => acctFreeAgentBanks(),
        'GET bank_transaction_explanations' => ['bank_transaction_explanations' => []],
        'POST bank_transaction_explanations' => ['bank_transaction_explanation' => ['url' => ACCT_FA . 'bank_transaction_explanations/9']],
    ]);
    $id = $world->fa->creditNote(acctFreeAgentDoc(['number' => 'CN-00003', 'reference' => 'INV00012', 'due_date' => '2026-11-05', 'refunds' => [
        ['account' => ACCT_FA . 'bank_accounts/2', 'amount' => 15.96, 'date' => '2026-11-07', 'reference' => 'Account credit'],
        ['account' => ACCT_FA . 'bank_accounts/1', 'amount' => 50.0, 'date' => '2026-11-07', 'reference' => 'Refund CN-00003'],
    ]]));
    $posts = array_values(array_filter($world->calls, static fn (array $c): bool => $c['method'] === 'POST'));

    expect($id)->toBe(ACCT_FA . 'credit_notes/3')
        ->and($world->calls[0]['query'])->toMatchArray(['contact' => ACCT_FA . 'contacts/7'])
        ->and($posts[0]['json']['credit_note'])->toMatchArray(['reference' => 'CN-00003', 'payment_terms_in_days' => 0])
        ->and($posts[0]['json']['credit_note'])->not->toHaveKey('send_new_invoice_emails')
        ->and(array_column($posts[0]['json']['credit_note']['credit_note_items'], 'price'))->toBe([-19.99, 5.0])
        ->and(acctFreeAgentCalls($world))->toContain('PUT credit_notes/3/transitions/mark_as_sent')
        ->and($posts[1]['json']['bank_transaction_explanation'])->toBe(['bank_account' => ACCT_FA . 'bank_accounts/2', 'paid_invoice' => ACCT_FA . 'credit_notes/3', 'dated_on' => '2026-11-07', 'gross_value' => -15.96, 'receipt_reference' => 'Account credit'])
        ->and($posts[2]['json']['bank_transaction_explanation'])->toMatchArray(['bank_account' => ACCT_FA . 'bank_accounts/1', 'gross_value' => -50.0, 'receipt_reference' => 'Refund CN-00003']);

    // Found open by its number: not changed, only its refunds made sure of (FreeAgent names the credit note an invoice in paid_invoice).
    $world = acctFreeAgent([
        'GET credit_notes' => ['credit_notes' => [['url' => ACCT_FA . 'credit_notes/3', 'reference' => 'CN-00003', 'status' => 'Open']]],
        'GET bank_accounts' => acctFreeAgentBanks(),
        'GET bank_transaction_explanations' => ['bank_transaction_explanations' => [['url' => ACCT_FA . 'bank_transaction_explanations/9', 'paid_invoice' => ACCT_FA . 'invoices/3', 'receipt_reference' => 'Account credit']]],
    ]);
    $world->fa->creditNote(acctFreeAgentDoc(['number' => 'CN-00003', 'refunds' => [['account' => ACCT_FA . 'bank_accounts/2', 'amount' => 15.96, 'date' => '2026-11-07', 'reference' => 'Account credit']]]));
    expect(acctFreeAgentCalls($world))->toBe(['GET credit_notes', 'GET bank_accounts', 'GET bank_transaction_explanations']);
});

test('a void takes a sent invoice back to draft and deletes it; a refused transition fails the record, not the sync', function (): void {
    $world = acctFreeAgent([
        'GET invoices/5' => ['invoice' => ['url' => ACCT_FA . 'invoices/5', 'status' => 'Open']],
        'PUT invoices/5/transitions/mark_as_draft' => new MockResponse(''),
        'DELETE invoices/5' => new MockResponse(''),
    ]);
    $world->fa->void(ACCT_FA . 'invoices/5');
    expect(acctFreeAgentCalls($world))->toBe(['GET invoices/5', 'PUT invoices/5/transitions/mark_as_draft', 'DELETE invoices/5']);

    $world = acctFreeAgent(['GET invoices/5' => ['invoice' => ['url' => ACCT_FA . 'invoices/5', 'status' => 'Draft']], 'DELETE invoices/5' => new MockResponse('')]);
    $world->fa->void(ACCT_FA . 'invoices/5');
    expect(acctFreeAgentCalls($world))->toBe(['GET invoices/5', 'DELETE invoices/5']);

    // Deleted already (a void whose reply was lost): done, for a credit note too.
    $world = acctFreeAgent([]);
    $world->fa->void(ACCT_FA . 'invoices/5');
    $world->fa->voidCreditNote(ACCT_FA . 'credit_notes/3');
    expect(acctFreeAgentCalls($world))->toBe(['GET invoices/5', 'GET credit_notes/3']);

    // FreeAgent answers 403 to a transition it refuses (a paid invoice).
    $world = acctFreeAgent([
        'GET invoices/5' => ['invoice' => ['url' => ACCT_FA . 'invoices/5', 'status' => 'Open']],
        'PUT invoices/5/transitions/mark_as_draft' => new MockResponse('{"errors":{"error":{"message":"This Invoice cannot be marked as draft"}}}', ['http_code' => 403]),
    ]);
    try {
        $world->fa->void(ACCT_FA . 'invoices/5');
        $this->fail('No error');
    } catch (InformationException $e) {
        expect($e)->not->toBeInstanceOf(RateLimited::class)
            ->and($e->getMessage())->toBe('FreeAgent refused the request: This Invoice cannot be marked as draft');
    }
});

test('voiding a credit note deletes its refund explanations first, then takes it back to draft and deletes it', function (): void {
    $world = acctFreeAgent([
        'GET credit_notes/3' => ['credit_note' => ['url' => ACCT_FA . 'credit_notes/3', 'dated_on' => '2026-11-07', 'status' => 'Refunded']],
        'GET bank_accounts' => acctFreeAgentBanks(),
        'GET bank_transaction_explanations' => fn (array $query): array => ['bank_transaction_explanations' => $query['bank_account'] === ACCT_FA . 'bank_accounts/2' && $query['from_date'] === '2026-11-07' ? [
            ['url' => ACCT_FA . 'bank_transaction_explanations/9', 'paid_invoice' => ACCT_FA . 'invoices/3'],
            ['url' => ACCT_FA . 'bank_transaction_explanations/8', 'paid_invoice' => ACCT_FA . 'invoices/5'],
        ] : []],
        'DELETE bank_transaction_explanations/9' => new MockResponse(''),
        'PUT credit_notes/3/transitions/mark_as_draft' => new MockResponse(''),
        'DELETE credit_notes/3' => new MockResponse(''),
    ]);

    $world->fa->voidCreditNote(ACCT_FA . 'credit_notes/3');

    expect(acctFreeAgentCalls($world))->toBe(['GET credit_notes/3', 'GET bank_accounts', 'GET bank_transaction_explanations', 'GET bank_transaction_explanations', 'DELETE bank_transaction_explanations/9', 'PUT credit_notes/3/transitions/mark_as_draft', 'DELETE credit_notes/3']);
});

test('a long list is read page by page', function (): void {
    $page = fn (array $query): array => ['contacts' => $query['page'] === '1' ? array_map(static fn (int $i): array => ['url' => ACCT_FA . 'contacts/' . $i, 'email' => 'x' . $i . '@example.com'], range(1, 100))
        : [['url' => ACCT_FA . 'contacts/101', 'organisation_name' => 'Acme Ltd', 'email' => 'ann@example.com']]];
    $world = acctFreeAgent(['GET contacts' => $page]);

    expect($world->fa->contact(['id' => 7, 'remote_id' => null, 'name' => 'Acme Ltd', 'first_name' => 'Ann', 'last_name' => 'Lee', 'company' => 'Acme Ltd', 'email' => 'ann@example.com', 'vat' => '',
        'phone' => '', 'phone_cc' => '', 'address_1' => '', 'address_2' => '', 'city' => '', 'state' => '', 'zip' => '', 'country' => 'GB']))->toBe(ACCT_FA . 'contacts/101')
        ->and(array_column(array_column($world->calls, 'query'), 'page'))->toBe(['1', '2']);
});

test('a URL that is not this API\'s gets no token', function (): void {
    $world = acctFreeAgent([]);

    expect(fn () => $world->fa->invoice(acctFreeAgentDoc(['remote_id' => 'https://elsewhere.example.com/v2/invoices/5'])))->toThrow(InformationException::class);
    expect(array_column($world->calls, 'url'))->each->toStartWith(ACCT_FA);
});

test('a 429 stops with the wait FreeAgent asks for', function (): void {
    $world = acctFreeAgent(['GET company' => new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 37']])]);

    try {
        $world->fa->taxRates();
        $this->fail('No rate limit');
    } catch (RateLimited $e) {
        expect($e->retryAfter)->toBe(37);
    }
});

test('the error messages become one clear message without a secret in it', function (string $reply, string $message): void {
    $world = acctFreeAgent(['GET invoices' => ['invoices' => []], 'POST invoices' => new MockResponse($reply, ['http_code' => 422])]);

    try {
        $world->fa->invoice(acctFreeAgentDoc());
        $this->fail('No error');
    } catch (InformationException $e) {
        expect($e)->not->toBeInstanceOf(RateLimited::class)
            ->and($e->getMessage())->toBe('FreeAgent refused the request: ' . $message)
            ->not->toContain('client-secret-1')->not->toContain('access-1')->not->toContain('refresh-1');
    }
})->with([
    'list' => ['{"errors":[{"message":"Reference has already been taken"},{"message":"Category is invalid"}]}', 'Reference has already been taken Category is invalid'],
    'one' => ['{"errors":{"error":{"message":"Contact can\'t be blank"}}}', 'Contact can\'t be blank'],
]);

test('FreeAgent down, the access refused or not reachable stops the sync as an outage, not as a record failure', function (MockResponse $reply, int $wait, string $message): void {
    $world = acctFreeAgent(['GET company' => $reply, 'POST token_endpoint' => new MockResponse('{"error":"invalid_grant"}', ['http_code' => 400])], $wait === 3600 ? ['access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => 0, 'tenant_id' => 't'] : null);

    try {
        $world->fa->taxRates();
        $this->fail('No outage');
    } catch (RateLimited $e) {
        expect($e->retryAfter)->toBe($wait)
            ->and($e->outage)->toBeInstanceOf(InformationException::class)
            ->and($e->getMessage())->toContain($message);
    }
})->with([
    'server error' => [new MockResponse('{"errors":{"error":{"message":"Service unavailable"}}}', ['http_code' => 503]), 300, 'FreeAgent refused the request: Service unavailable'],
    'access refused' => [new MockResponse('{"errors":{"error":{"message":"Access token not recognised"}}}', ['http_code' => 401]), 300, 'Access token not recognised'],
    'not reachable' => [new MockResponse('', ['error' => 'Connection timed out']), 300, 'Could not reach FreeAgent'],
    'connection ended' => [new MockResponse('{}'), 3600, 'The connection to FreeAgent has ended'],
]);
