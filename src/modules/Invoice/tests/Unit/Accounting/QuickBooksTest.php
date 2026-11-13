<?php

declare(strict_types=1);

/**
 * ClientFence: the QuickBooks Online adapter of the accounting sync (Invoice/Accounting/QuickBooks.php), against replies
 * shaped as the QuickBooks Online API and OAuth2 docs show them. $routes answers "METHOD path" (path after
 * /v3/company/{realmId}/, or the token path) with an array (JSON), a closure of the query, or a MockResponse; "GET query"
 * entries are keyed by the queried entity instead ("Customer", "Item"...), answered with rows or a closure of the
 * statement. Every call is in $calls.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Invoice\Accounting\QuickBooks;
use ClientFence\Mod\Invoice\Accounting\RateLimited;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

function acctQb(array $routes, ?array $tokens = null, array $rows = [], bool $sandbox = false): object
{
    $world = new stdClass();
    $world->calls = [];
    $world->saved = [];
    $http = new MockHttpClient(function (string $method, string $url, array $options) use ($routes, $rows, $world): MockResponse {
        $world->calls[] = $call = acctQbCall($method, $url, $options);
        if ($call['path'] === 'query' && preg_match('/^select \* from (\w+)/', $call['query']['query'] ?? '', $m) === 1) {
            $found = $rows[$m[1]] ?? [];
            $found = $found instanceof Closure ? $found($call['query']['query']) : $found;

            return new MockResponse((string) json_encode(['QueryResponse' => $found === [] ? new stdClass() : [$m[1] => $found, 'startPosition' => 1, 'maxResults' => count($found)], 'time' => '2026-11-13T10:00:00.000-08:00']));
        }
        $reply = $routes[$method . ' ' . $call['path']] ?? new MockResponse('{"Fault":{"Error":[{"Message":"Object Not Found","Detail":"Object Not Found : Something you\'re trying to use has been made inactive or is not found.","code":"610"}],"type":"ValidationFault"}}', ['http_code' => 400]);
        if ($reply instanceof Closure) {
            $reply = $reply($call);
        }

        return $reply instanceof MockResponse ? $reply : new MockResponse((string) json_encode($reply));
    });
    $tokens ??= ['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_at' => time() + 3600, 'tenant_id' => '9130350', 'tenant_name' => 'Sandbox Company_US_1'];
    $world->qb = new QuickBooks($http, 'client-id-1', 'client-secret-1', $tokens, function (array $tokens) use ($world): void {
        $world->saved[] = $tokens;
    }, $sandbox);

    return $world;
}

function acctQbCall(string $method, string $url, array $options): array
{
    $path = (string) parse_url($url, PHP_URL_PATH);
    $path = preg_match('#^/v3/company/[0-9]+/(.*)$#', $path, $m) === 1 ? $m[1] : $path;
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $body = (string) ($options['body'] ?? '');

    return ['method' => $method, 'host' => (string) parse_url($url, PHP_URL_HOST), 'path' => $path, 'query' => $query, 'headers' => implode("\n", $options['headers'] ?? []), 'body' => $body, 'json' => json_decode($body, true)];
}

/** The calls but queries, as "METHOD path". */
function acctQbWrites(object $world): array
{
    return array_values(array_map(static fn (array $c): string => $c['method'] . ' ' . $c['path'] . (isset($c['query']['operation']) ? '?' . $c['query']['operation'] : ''), array_filter($world->calls, static fn (array $c): bool => $c['path'] !== 'query')));
}

/** The query statements sent, in order. */
function acctQbQueries(object $world): array
{
    return array_values(array_map(static fn (array $c): string => $c['query']['query'], array_filter($world->calls, static fn (array $c): bool => $c['path'] === 'query')));
}

function acctQbBody(object $world, string $path): array
{
    foreach ($world->calls as $call) {
        if ($call['method'] === 'POST' && $call['path'] === $path) {
            return $call['json'];
        }
    }

    return [];
}

function acctQbItems(): array
{
    return [
        ['Id' => '5', 'Name' => 'Hosting', 'FullyQualifiedName' => 'Hosting', 'Type' => 'Service', 'Active' => true, 'IncomeAccountRef' => ['value' => '79', 'name' => 'Sales']],
        ['Id' => '6', 'Name' => 'Customer credit', 'FullyQualifiedName' => 'Customer credit', 'Type' => 'Service', 'Active' => true, 'IncomeAccountRef' => ['value' => '35', 'name' => 'Customer credit']],
        ['Id' => '7', 'Name' => 'Widgets', 'FullyQualifiedName' => 'Widgets', 'Type' => 'Inventory', 'Active' => true, 'IncomeAccountRef' => ['value' => '79']],
        ['Id' => '8', 'Name' => 'Services', 'FullyQualifiedName' => 'Services', 'Type' => 'Category', 'Active' => true],
    ];
}

function acctQbLines(): array
{
    return [
        ['description' => 'Hosting', 'quantity' => 3.0, 'unit_price' => 19.99, 'amount' => 59.97, 'account' => 'item:5', 'tax' => 'TAX', 'tax_amount' => 12.0],
        ['description' => 'Discount: Hosting', 'quantity' => 1.0, 'unit_price' => -5.0, 'amount' => -5.0, 'account' => 'item:5', 'tax' => 'TAX', 'tax_amount' => -1.0],
        ['description' => 'Add funds', 'quantity' => 1.0, 'unit_price' => 20.0, 'amount' => 20.0, 'account' => 'account:35', 'tax' => 'NON', 'tax_amount' => 0.0],
    ];
}

function acctQbPrefs(bool $automatedTax = true): array
{
    return ['Preferences' => ['Id' => '1', 'SyncToken' => '3', 'TaxPrefs' => ['UsingSalesTax' => true, 'PartnerTaxEnabled' => $automatedTax]]];
}

function acctQbDoc(array $overrides = []): array
{
    return $overrides + ['remote_id' => null, 'contact' => '58', 'number' => 'INV00012', 'date' => '2026-11-05', 'due_date' => '2026-11-12', 'currency' => 'USD', 'total' => 86.97, 'lines' => acctQbLines()];
}

test('the authorize URL asks for the accounting scope and carries the state', function (): void {
    $url = acctQb([])->qb->authorizeUrl('state-123', 'https://billing.example.com/admin/invoice/accounting/callback');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://appcenter.intuit.com/connect/oauth2?')
        ->and($query)->toBe(['client_id' => 'client-id-1', 'response_type' => 'code', 'scope' => 'com.intuit.quickbooks.accounting', 'redirect_uri' => 'https://billing.example.com/admin/invoice/accounting/callback', 'state' => 'state-123'])
        ->and(QuickBooks::label())->toBe('QuickBooks Online')
        ->and(QuickBooks::hasSandbox())->toBeTrue();
});

test('the code is exchanged with the app credentials and the realmId is the company', function (): void {
    $world = acctQb([
        'POST /oauth2/v1/tokens/bearer' => ['token_type' => 'bearer', 'access_token' => 'access-new', 'refresh_token' => 'refresh-new', 'expires_in' => 3600, 'x_refresh_token_expires_in' => 8726400],
        'GET companyinfo/9130350' => ['CompanyInfo' => ['Id' => '1', 'CompanyName' => 'Sandbox Company_US_1', 'Country' => 'US']],
    ], [], [], true);

    $tokens = $world->qb->connect('code-abc', 'https://billing.example.com/cb', ['code' => 'code-abc', 'state' => 's', 'realmId' => '9130350']);
    parse_str($world->calls[0]['body'], $form);

    expect($tokens)->toMatchArray(['access_token' => 'access-new', 'refresh_token' => 'refresh-new', 'tenant_id' => '9130350', 'tenant_name' => 'Sandbox Company_US_1'])
        ->and($tokens['expires_at'])->toBeGreaterThan(time() + 3500)
        ->and($form)->toBe(['grant_type' => 'authorization_code', 'code' => 'code-abc', 'redirect_uri' => 'https://billing.example.com/cb'])
        ->and($world->calls[0]['headers'])->toContain('Authorization: Basic ' . base64_encode('client-id-1:client-secret-1'))
        ->and($world->calls[1]['host'])->toBe('sandbox-quickbooks.api.intuit.com')
        ->and($world->calls[1]['query'])->toBe(['minorversion' => '75'])
        ->and($world->calls[1]['headers'])->toContain('Authorization: Bearer access-new');

    expect(fn () => acctQb([], [])->qb->connect('code-abc', 'https://billing.example.com/cb', ['code' => 'code-abc']))->toThrow(InformationException::class, 'This connection request has expired or was not started here.');
});

test('an expired access token is refreshed first; items are the sales choices, bank and current asset accounts take payments', function (): void {
    $world = acctQb([
        'POST /oauth2/v1/tokens/bearer' => ['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'expires_in' => 3600],
    ], ['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_at' => time() - 10, 'tenant_id' => '9130350'], [
        'Item' => acctQbItems(),
        'Account' => [
            ['Id' => '35', 'Name' => 'Customer credit', 'FullyQualifiedName' => 'Customer credit', 'AccountType' => 'Other Current Asset', 'Active' => true],
            ['Id' => '41', 'Name' => 'Checking', 'FullyQualifiedName' => 'Checking', 'AcctNum' => '1010', 'AccountType' => 'Bank', 'Active' => true],
            ['Id' => '79', 'Name' => 'Sales', 'FullyQualifiedName' => 'Sales', 'AccountType' => 'Income', 'Active' => true],
        ],
    ]);

    $accounts = $world->qb->accounts();
    parse_str($world->calls[0]['body'], $form);

    expect($form)->toBe(['grant_type' => 'refresh_token', 'refresh_token' => 'refresh-1'])
        ->and($world->saved)->toHaveCount(1)
        ->and($world->saved[0])->toMatchArray(['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'tenant_id' => '9130350'])
        ->and($world->calls[1]['host'])->toBe('quickbooks.api.intuit.com')
        ->and($world->calls[1]['headers'])->toContain('Authorization: Bearer access-2')
        ->and(acctQbQueries($world))->toBe(['select * from Item startposition 1 maxresults 1000', 'select * from Account maxresults 1000'])
        ->and($accounts)->toBe([
            ['id' => 'item:5', 'name' => 'Hosting', 'kind' => 'sales', 'payments' => false],
            ['id' => 'item:6', 'name' => 'Customer credit', 'kind' => 'sales', 'payments' => false],
            ['id' => 'account:35', 'name' => 'Customer credit', 'kind' => 'other', 'payments' => true],
            ['id' => 'account:41', 'name' => '1010 - Checking', 'kind' => 'bank', 'payments' => true],
        ]);
});

test('a refused refresh says to connect again', function (): void {
    $world = acctQb(['POST /oauth2/v1/tokens/bearer' => new MockResponse('{"error":"invalid_grant"}', ['http_code' => 400])], ['access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => 0, 'tenant_id' => '1']);

    expect(fn () => $world->qb->taxRates())->toThrow(InformationException::class, 'The connection to QuickBooks Online has ended (QuickBooks Online refused the request: invalid_grant): connect again.');
});

test('tax rates are TAX and NON with automated sales tax, else the active sales tax codes with their rates', function (): void {
    expect(acctQb(['GET preferences' => acctQbPrefs()])->qb->taxRates())->toBe([['id' => 'TAX', 'name' => 'TAX - Taxable', 'rate' => 0.0], ['id' => 'NON', 'name' => 'NON - Not taxable', 'rate' => 0.0]]);

    $world = acctQb(['GET preferences' => acctQbPrefs(false)], null, [
        'TaxRate' => [['Id' => '3', 'Name' => 'GST', 'RateValue' => 5], ['Id' => '4', 'Name' => 'PST', 'RateValue' => 7], ['Id' => '9', 'Name' => 'Zero', 'RateValue' => 0]],
        'TaxCode' => [
            ['Id' => '2', 'Name' => 'GST/PST BC', 'Active' => true, 'SalesTaxRateList' => ['TaxRateDetail' => [['TaxRateRef' => ['value' => '3']], ['TaxRateRef' => ['value' => '4']]]]],
            ['Id' => '6', 'Name' => 'Zero-rated', 'Active' => true, 'SalesTaxRateList' => ['TaxRateDetail' => [['TaxRateRef' => ['value' => '9']]]]],
            ['Id' => '7', 'Name' => 'Purchases only', 'Active' => true, 'SalesTaxRateList' => ['TaxRateDetail' => []], 'PurchaseTaxRateList' => ['TaxRateDetail' => [['TaxRateRef' => ['value' => '3']]]]],
        ],
    ]);

    expect($world->qb->taxRates())->toBe([['id' => '2', 'name' => 'GST/PST BC', 'rate' => 12.0], ['id' => '6', 'name' => 'Zero-rated', 'rate' => 0.0]]);
});

test('a new customer is looked up by DisplayName (quotes escaped) and created with the ClientFence mark', function (): void {
    $client = ['id' => 7, 'remote_id' => null, 'name' => "O'Brien \\ Co", 'first_name' => 'Ann', 'last_name' => 'Lee', 'company' => "O'Brien \\ Co", 'email' => 'ann@example.com', 'vat' => '',
        'phone' => '555 0100', 'phone_cc' => '1', 'address_1' => '1 Main St', 'address_2' => '', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701', 'country' => 'US'];
    $world = acctQb(['POST customer' => ['Customer' => ['Id' => '58', 'SyncToken' => '0']]]);

    expect($world->qb->contact($client))->toBe('58')
        ->and(acctQbQueries($world))->toBe([
            "select * from Customer where DisplayName = 'O\\'Brien \\\\ Co' and Active in (true, false) maxresults 1000",
            "select * from Customer where DisplayName = 'O\\'Brien \\\\ Co (#7)' and Active in (true, false) maxresults 1000",
            "select * from Vendor where DisplayName = 'O\\'Brien \\\\ Co' and Active in (true, false) maxresults 1000",
            "select * from Employee where DisplayName = 'O\\'Brien \\\\ Co' and Active in (true, false) maxresults 1000",
        ])
        ->and(acctQbBody($world, 'customer'))->toBe([
            'DisplayName' => "O'Brien \\ Co", 'GivenName' => 'Ann', 'FamilyName' => 'Lee', 'CompanyName' => "O'Brien \\ Co",
            'PrimaryEmailAddr' => ['Address' => 'ann@example.com'], 'PrimaryPhone' => ['FreeFormNumber' => '+1 555 0100'],
            'BillAddr' => ['Line1' => '1 Main St', 'Line2' => '', 'City' => 'Austin', 'CountrySubDivisionCode' => 'TX', 'PostalCode' => '78701', 'Country' => 'US'],
            'Notes' => 'ClientFence client #7',
        ]);
});

test('a customer with the name and our mark is ours; a name someone else has gets the client number', function (): void {
    $client = ['id' => 7, 'remote_id' => null, 'name' => 'Acme Ltd', 'first_name' => 'Ann', 'last_name' => 'Lee', 'company' => 'Acme Ltd', 'email' => '', 'vat' => '',
        'phone' => '', 'phone_cc' => '', 'address_1' => '', 'address_2' => '', 'city' => '', 'state' => '', 'zip' => '', 'country' => ''];
    $world = acctQb(['POST customer' => ['Customer' => ['Id' => '58']]], null, ['Customer' => [['Id' => '58', 'SyncToken' => '2', 'DisplayName' => 'Acme Ltd', 'Notes' => 'ClientFence client #7']]]);

    $world->qb->contact($client);
    expect(acctQbQueries($world))->toHaveCount(1)
        ->and(acctQbBody($world, 'customer'))->toMatchArray(['Id' => '58', 'SyncToken' => '2', 'sparse' => true, 'DisplayName' => 'Acme Ltd'])
        ->and(acctQbBody($world, 'customer'))->not->toHaveKey('Notes')->not->toHaveKey('PrimaryEmailAddr');

    // Client #77's mark is not client #7's; the known customer keeps its id and takes the unique name.
    $world = acctQb([
        'GET customer/60' => ['Customer' => ['Id' => '60', 'SyncToken' => '4', 'DisplayName' => 'Acme Ltd (#7)']],
        'POST customer' => ['Customer' => ['Id' => '60']],
    ], null, ['Customer' => fn (string $sql): array => str_contains($sql, "'Acme Ltd'") ? [['Id' => '58', 'SyncToken' => '0', 'DisplayName' => 'Acme Ltd', 'Notes' => 'ClientFence client #77']] : []]);

    expect($world->qb->contact(['remote_id' => '60'] + $client))->toBe('60')
        ->and(acctQbQueries($world))->toHaveCount(1)
        ->and(acctQbBody($world, 'customer'))->toMatchArray(['Id' => '60', 'SyncToken' => '4', 'sparse' => true, 'DisplayName' => 'Acme Ltd (#7)']);

    // The name is unique across suppliers and staff too: a supplier's name is taken.
    $world = acctQb(['POST customer' => ['Customer' => ['Id' => '61']]], null, ['Vendor' => [['Id' => '12', 'DisplayName' => 'Acme Ltd']]]);
    $world->qb->contact($client);
    expect(acctQbBody($world, 'customer'))->toMatchArray(['DisplayName' => 'Acme Ltd (#7)', 'Notes' => 'ClientFence client #7']);
});

test('an invoice with automated sales tax: items, TAX and NON lines, the total tax sent; added funds use the item of the credit account', function (): void {
    $world = acctQb(['GET preferences' => acctQbPrefs(), 'POST invoice' => ['Invoice' => ['Id' => '130', 'SyncToken' => '0']]], null, ['Item' => acctQbItems()]);

    expect($world->qb->invoice(acctQbDoc()))->toBe('130')
        ->and(acctQbQueries($world)[0])->toBe("select * from Invoice where DocNumber = 'INV00012' maxresults 1000")
        ->and(acctQbBody($world, 'invoice'))->toBe([
            'CustomerRef' => ['value' => '58'], 'DocNumber' => 'INV00012', 'TxnDate' => '2026-11-05', 'CurrencyRef' => ['value' => 'USD'], 'DueDate' => '2026-11-12',
            'Line' => [
                ['DetailType' => 'SalesItemLineDetail', 'Amount' => 59.97, 'Description' => 'Hosting', 'SalesItemLineDetail' => ['ItemRef' => ['value' => '5'], 'Qty' => 3.0, 'UnitPrice' => 19.99, 'TaxCodeRef' => ['value' => 'TAX']]],
                ['DetailType' => 'SalesItemLineDetail', 'Amount' => -5.0, 'Description' => 'Discount: Hosting', 'SalesItemLineDetail' => ['ItemRef' => ['value' => '5'], 'Qty' => 1.0, 'UnitPrice' => -5.0, 'TaxCodeRef' => ['value' => 'TAX']]],
                ['DetailType' => 'SalesItemLineDetail', 'Amount' => 20.0, 'Description' => 'Add funds', 'SalesItemLineDetail' => ['ItemRef' => ['value' => '6'], 'Qty' => 1.0, 'UnitPrice' => 20.0, 'TaxCodeRef' => ['value' => 'NON']]],
            ],
            'TxnTaxDetail' => ['TotalTax' => 11.0],
        ]);

    // Found by its number (or known by id): a sparse update with the current SyncToken.
    $world = acctQb(['GET preferences' => acctQbPrefs(), 'POST invoice' => ['Invoice' => ['Id' => '130']]], null, ['Item' => acctQbItems(), 'Invoice' => [['Id' => '130', 'SyncToken' => '3', 'DocNumber' => 'INV00012']]]);
    $world->qb->invoice(acctQbDoc());
    expect(acctQbBody($world, 'invoice'))->toMatchArray(['Id' => '130', 'SyncToken' => '3', 'sparse' => true, 'DocNumber' => 'INV00012']);

    $world = acctQb(['GET invoice/130' => ['Invoice' => ['Id' => '130', 'SyncToken' => '5']], 'GET preferences' => acctQbPrefs(), 'POST invoice' => ['Invoice' => ['Id' => '130']]], null, ['Item' => acctQbItems()]);
    $world->qb->invoice(acctQbDoc(['remote_id' => '130']));
    expect(acctQbQueries($world))->toBe(['select * from Item startposition 1 maxresults 1000'])
        ->and(acctQbBody($world, 'invoice'))->toMatchArray(['Id' => '130', 'SyncToken' => '5', 'sparse' => true]);
});

test('US manual sales tax: tax codes are offered with NON, lines are TAX or NON and the code is the invoice\'s, with the total tax sent', function (): void {
    $rows = [
        'Item' => acctQbItems(),
        'TaxRate' => [['Id' => '3', 'Name' => 'California', 'RateValue' => 8], ['Id' => '1', 'RateValue' => 7.1], ['Id' => '2', 'RateValue' => 2]],
        'TaxCode' => [
            ['Id' => 'TAX', 'Name' => 'TAX', 'Taxable' => true], ['Id' => 'NON', 'Name' => 'NON', 'Taxable' => false],
            ['Id' => '2', 'Name' => 'California', 'Active' => true, 'SalesTaxRateList' => ['TaxRateDetail' => [['TaxRateRef' => ['value' => '3']]]]],
            ['Id' => '3', 'Name' => 'Tucson', 'Active' => true, 'SalesTaxRateList' => ['TaxRateDetail' => [['TaxRateRef' => ['value' => '1']], ['TaxRateRef' => ['value' => '2']]]]],
        ],
    ];
    $world = acctQb(['GET preferences' => acctQbPrefs(false), 'POST invoice' => ['Invoice' => ['Id' => '145']]], null, $rows);
    expect($world->qb->taxRates())->toBe([['id' => '2', 'name' => 'California', 'rate' => 8.0], ['id' => '3', 'name' => 'Tucson', 'rate' => 9.1], ['id' => 'NON', 'name' => 'NON - Not taxable', 'rate' => 0.0]]);

    $lines = [
        ['description' => 'Hosting', 'quantity' => 3.0, 'unit_price' => 9.99, 'amount' => 29.97, 'account' => 'item:5', 'tax' => '2', 'tax_amount' => 2.41],
        ['description' => 'Add funds', 'quantity' => 1.0, 'unit_price' => 10.0, 'amount' => 10.0, 'account' => 'account:35', 'tax' => 'NON', 'tax_amount' => 0.0],
    ];
    $world->qb->invoice(acctQbDoc(['lines' => $lines]));
    $sent = acctQbBody($world, 'invoice');

    expect(array_column(array_column($sent['Line'], 'SalesItemLineDetail'), 'TaxCodeRef'))->toBe([['value' => 'TAX'], ['value' => 'NON']])
        ->and($sent['TxnTaxDetail'])->toBe(['TotalTax' => 2.41, 'TxnTaxCodeRef' => ['value' => '2']])
        ->and($sent)->not->toHaveKey('GlobalTaxCalculation');

    // Two tax codes on one invoice cannot be kept.
    $world = acctQb(['GET preferences' => acctQbPrefs(false)], null, $rows);
    expect(fn () => $world->qb->invoice(acctQbDoc(['lines' => [$lines[0], ['tax' => '3'] + $lines[0]]])))->toThrow(InformationException::class, 'QuickBooks Online takes one sales tax rate per invoice');
});

test('outside the US each rate of the codes used gets its TaxLine, so the tax is ClientFence\'s to the cent', function (): void {
    $world = acctQb(['GET preferences' => acctQbPrefs(false), 'POST invoice' => ['Invoice' => ['Id' => '131']]], null, [
        'Item' => acctQbItems(),
        'TaxRate' => [['Id' => '3', 'RateValue' => 5], ['Id' => '4', 'RateValue' => 7], ['Id' => '9', 'RateValue' => 0]],
        'TaxCode' => [
            ['Id' => '2', 'Name' => 'GST/PST BC', 'Active' => true, 'SalesTaxRateList' => ['TaxRateDetail' => [['TaxRateRef' => ['value' => '3']], ['TaxRateRef' => ['value' => '4']]]]],
            ['Id' => '6', 'Name' => 'Zero-rated', 'Active' => true, 'SalesTaxRateList' => ['TaxRateDetail' => [['TaxRateRef' => ['value' => '9']]]]],
        ],
    ]);
    $lines = [
        ['description' => 'Hosting', 'quantity' => 1.0, 'unit_price' => 100.0, 'amount' => 100.0, 'account' => 'item:5', 'tax' => '2', 'tax_amount' => 12.01],
        ['description' => 'Add funds', 'quantity' => 1.0, 'unit_price' => 20.0, 'amount' => 20.0, 'account' => 'account:35', 'tax' => '6', 'tax_amount' => 0.0],
    ];

    $world->qb->invoice(acctQbDoc(['currency' => 'CAD', 'lines' => $lines]));
    $sent = acctQbBody($world, 'invoice');

    expect($sent['GlobalTaxCalculation'])->toBe('TaxExcluded')
        ->and($sent['Line'][0]['SalesItemLineDetail']['TaxCodeRef'])->toBe(['value' => '2'])
        ->and($sent['TxnTaxDetail'])->toBe(['TotalTax' => 12.01, 'TaxLine' => [
            ['Amount' => 5.0, 'DetailType' => 'TaxLineDetail', 'TaxLineDetail' => ['TaxRateRef' => ['value' => '3'], 'PercentBased' => true, 'TaxPercent' => 5.0, 'NetAmountTaxable' => 100.0]],
            ['Amount' => 7.01, 'DetailType' => 'TaxLineDetail', 'TaxLineDetail' => ['TaxRateRef' => ['value' => '4'], 'PercentBased' => true, 'TaxPercent' => 7.0, 'NetAmountTaxable' => 100.0]],
            ['Amount' => 0.0, 'DetailType' => 'TaxLineDetail', 'TaxLineDetail' => ['TaxRateRef' => ['value' => '9'], 'PercentBased' => true, 'TaxPercent' => 0.0, 'NetAmountTaxable' => 20.0]],
        ]]);
});

test('the product list is read page by page, so an account-credit product past the first 1000 is found', function (): void {
    $items = fn (string $sql): array => str_contains($sql, 'startposition 1 ')
        ? array_map(static fn (int $i): array => ['Id' => (string) (100 + $i), 'Name' => 'P' . $i, 'Type' => 'Service', 'Active' => true, 'IncomeAccountRef' => ['value' => '79']], range(1, 1000))
        : [acctQbItems()[1]];
    $world = acctQb(['GET preferences' => acctQbPrefs(), 'POST invoice' => ['Invoice' => ['Id' => '130']]], null, ['Item' => $items]);

    $world->qb->invoice(acctQbDoc(['lines' => [acctQbLines()[2]]]));
    expect(acctQbBody($world, 'invoice')['Line'][0]['SalesItemLineDetail']['ItemRef'])->toBe(['value' => '6'])
        ->and(array_values(array_filter(acctQbQueries($world), static fn (string $q): bool => str_contains($q, 'from Item'))))
        ->toBe(['select * from Item startposition 1 maxresults 1000', 'select * from Item startposition 1001 maxresults 1000']);
});

test('a number QuickBooks cannot keep or an account without its item is a clear error', function (): void {
    $world = acctQb([]);
    expect(fn () => $world->qb->invoice(acctQbDoc(['number' => 'PRO-INVOICE-0000001234'])))->toThrow(InformationException::class, 'QuickBooks Online allows document numbers of up to 21 characters: PRO-INVOICE-0000001234 is longer.')
        ->and($world->calls)->toBe([]);

    $world = acctQb(['GET preferences' => acctQbPrefs()], null, ['Item' => [acctQbItems()[0]]]);
    expect(fn () => $world->qb->invoice(acctQbDoc()))->toThrow(InformationException::class, 'Create a QuickBooks Online product or service whose income account is the account credit account')
        ->and(acctQbWrites($world))->toBe(['GET preferences']);

    // A choice made for another program.
    $world = acctQb(['GET preferences' => acctQbPrefs()], null, ['Item' => acctQbItems()]);
    expect(fn () => $world->qb->invoice(acctQbDoc(['lines' => [['account' => '200'] + acctQbLines()[0]]])))->toThrow(InformationException::class, 'The account and tax choices could not be read.');
});

test('a payment is linked to the invoice once: a payment with its reference is found again', function (): void {
    $payment = ['invoice' => '130', 'currency' => 'USD', 'account' => 'account:41', 'amount' => 50.0, 'date' => '2026-11-06', 'reference' => 'Transaction 31'];
    $invoice = ['Invoice' => ['Id' => '130', 'SyncToken' => '1', 'CustomerRef' => ['value' => '58'], 'LinkedTxn' => [['TxnId' => '140', 'TxnType' => 'Payment'], ['TxnId' => '9', 'TxnType' => 'Estimate']]]];
    $world = acctQb(['GET invoice/130' => $invoice, 'POST payment' => ['Payment' => ['Id' => '141']]], null, ['Payment' => [['Id' => '140', 'PrivateNote' => 'Account credit']]]);

    expect($world->qb->payment($payment))->toBe('141')
        ->and(acctQbQueries($world))->toBe(["select * from Payment where Id in ('140') maxresults 1000"])
        ->and(acctQbBody($world, 'payment'))->toBe(['CustomerRef' => ['value' => '58'], 'TotalAmt' => 50.0, 'TxnDate' => '2026-11-06', 'DepositToAccountRef' => ['value' => '41'],
            'PaymentRefNum' => 'Transaction 31', 'PrivateNote' => 'Transaction 31', 'Line' => [['Amount' => 50.0, 'LinkedTxn' => [['TxnId' => '130', 'TxnType' => 'Invoice']]]]]);

    $world = acctQb(['GET invoice/130' => $invoice], null, ['Payment' => [['Id' => '140', 'PrivateNote' => 'Transaction 31']]]);
    expect($world->qb->payment($payment))->toBe('140')
        ->and(acctQbWrites($world))->toBe(['GET invoice/130']);
});

test('a refund is a RefundReceipt out of its first account, and a Transfer moves the bank part', function (): void {
    $credit = acctQbDoc(['number' => 'CN-00003', 'reference' => 'INV00012', 'date' => '2026-11-07', 'refunds' => [
        ['account' => 'account:35', 'amount' => 36.97, 'date' => '2026-11-07', 'reference' => 'Account credit'],
        ['account' => 'account:41', 'amount' => 50.0, 'date' => '2026-11-07', 'reference' => 'Refund CN-00003'],
    ]]);
    $world = acctQb(['GET preferences' => acctQbPrefs(), 'POST refundreceipt' => ['RefundReceipt' => ['Id' => '150']], 'POST transfer' => ['Transfer' => ['Id' => '151']]], null, [
        'Item' => acctQbItems(),
        'Transfer' => [['Id' => '99', 'PrivateNote' => 'Refund CN-00001', 'TxnDate' => '2026-11-07']],
    ]);

    expect($world->qb->creditNote($credit))->toBe('150 151')
        ->and(acctQbQueries($world))->toContain("select * from RefundReceipt where DocNumber = 'CN-00003' maxresults 1000", "select * from Transfer where TxnDate = '2026-11-07' maxresults 1000")
        ->and(acctQbBody($world, 'refundreceipt'))->toMatchArray(['CustomerRef' => ['value' => '58'], 'DocNumber' => 'CN-00003', 'DepositToAccountRef' => ['value' => '35'], 'PrivateNote' => 'INV00012', 'TxnTaxDetail' => ['TotalTax' => 11.0]])
        ->and(acctQbBody($world, 'refundreceipt'))->not->toHaveKey('DueDate')
        ->and(acctQbBody($world, 'transfer'))->toBe(['FromAccountRef' => ['value' => '41'], 'ToAccountRef' => ['value' => '35'], 'Amount' => 50.0, 'TxnDate' => '2026-11-07', 'PrivateNote' => 'Refund CN-00003']);

    // Sent again after a stop half way: both are found, nothing new is made.
    $world = acctQb(['GET preferences' => acctQbPrefs(), 'POST refundreceipt' => ['RefundReceipt' => ['Id' => '150']]], null, [
        'Item' => acctQbItems(),
        'RefundReceipt' => [['Id' => '150', 'SyncToken' => '0', 'DocNumber' => 'CN-00003']],
        'Transfer' => [['Id' => '151', 'PrivateNote' => 'Refund CN-00003']],
    ]);
    expect($world->qb->creditNote($credit))->toBe('150 151')
        ->and(acctQbWrites($world))->not->toContain('POST transfer');
});

test('a void voids the invoice with its SyncToken', function (): void {
    $world = acctQb(['GET invoice/130' => ['Invoice' => ['Id' => '130', 'SyncToken' => '2']], 'POST invoice' => ['Invoice' => ['Id' => '130', 'SyncToken' => '3']]]);
    $world->qb->void('130');

    expect(acctQbWrites($world))->toBe(['GET invoice/130', 'POST invoice?void'])
        ->and(acctQbBody($world, 'invoice'))->toBe(['Id' => '130', 'SyncToken' => '2']);
});

test('voiding a credit note deletes its RefundReceipt and Transfer; one already gone is skipped', function (): void {
    $world = acctQb(['POST transfer' => ['Transfer' => ['Id' => '151', 'status' => 'Deleted']]], null, [
        'RefundReceipt' => [],
        'Transfer' => fn (string $sql): array => str_contains($sql, "Id = '151'") ? [['Id' => '151', 'SyncToken' => '1']] : [],
    ]);

    $world->qb->voidCreditNote('150 151');

    expect(acctQbQueries($world))->toBe(["select * from RefundReceipt where Id = '150' maxresults 1000", "select * from Transfer where Id = '151' maxresults 1000"])
        ->and(acctQbWrites($world))->toBe(['POST transfer?delete'])
        ->and(acctQbBody($world, 'transfer'))->toBe(['Id' => '151', 'SyncToken' => '1']);
});

test('a 429 stops with the wait QuickBooks asks for', function (): void {
    $world = acctQb(['GET preferences' => new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 37']])]);

    try {
        $world->qb->taxRates();
        $this->fail('No rate limit');
    } catch (RateLimited $e) {
        expect($e->retryAfter)->toBe(37)->and($e->outage)->toBeNull();
    }
});

test('a validation fault becomes one clear message without a secret in it', function (): void {
    $world = acctQb(['POST customer' => new MockResponse((string) json_encode(['Fault' => ['Error' => [[
        'Message' => 'Duplicate Name Exists Error', 'Detail' => 'The name supplied already exists. : Another customer, vendor or employee is already using this name. Please use a different name.', 'code' => '6240',
    ]], 'type' => 'ValidationFault'], 'time' => '2026-11-13T10:00:00.000-08:00']), ['http_code' => 400])]);

    try {
        $world->qb->contact(['id' => 7, 'remote_id' => null, 'name' => 'Acme', 'first_name' => '', 'last_name' => '', 'company' => '', 'email' => '', 'vat' => '', 'phone' => '', 'phone_cc' => '', 'address_1' => '', 'address_2' => '', 'city' => '', 'state' => '', 'zip' => '', 'country' => '']);
        $this->fail('No error');
    } catch (InformationException $e) {
        expect($e)->not->toBeInstanceOf(RateLimited::class)
            ->and($e->getMessage())->toBe('QuickBooks Online refused the request: The name supplied already exists. : Another customer, vendor or employee is already using this name. Please use a different name.')
            ->not->toContain('client-secret-1')->not->toContain('access-1')->not->toContain('refresh-1');
    }
});

test('QuickBooks down, the access refused or not reachable stops the sync as an outage, not as a record failure', function (MockResponse $reply, int $wait, string $message): void {
    $world = acctQb(['GET preferences' => $reply, 'POST /oauth2/v1/tokens/bearer' => new MockResponse('{"error":"invalid_grant"}', ['http_code' => 400])], $wait === 3600 ? ['access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => 0, 'tenant_id' => '1'] : null);

    try {
        $world->qb->taxRates();
        $this->fail('No outage');
    } catch (RateLimited $e) {
        expect($e->retryAfter)->toBe($wait)
            ->and($e->outage)->toBeInstanceOf(InformationException::class)
            ->and($e->getMessage())->toContain($message)->not->toContain('AB11');
    }
})->with([
    'server error' => [new MockResponse('{"Fault":{"Error":[{"Message":"An application error has occurred.","Detail":"Service unavailable","code":"10000"}],"type":"SystemFault"}}', ['http_code' => 503]), 300, 'QuickBooks Online refused the request: Service unavailable'],
    'access refused' => [new MockResponse('{"fault":{"error":[{"message":"message=AuthenticationFailed; errorCode=003200; statusCode=401","detail":"Token expired: AB11xyz","code":"3200"}],"type":"AUTHENTICATION"}}', ['http_code' => 401]), 300, 'AuthenticationFailed'],
    'not reachable' => [new MockResponse('', ['error' => 'Connection timed out']), 300, 'Could not reach QuickBooks Online'],
    'connection ended' => [new MockResponse('{}'), 3600, 'The connection to QuickBooks Online has ended'],
]);
