<?php

declare(strict_types=1);

/**
 * ClientFence: eWAY (Payment_Adapter_Eway): payment pages, saved cards charged as recurring payments, payments read
 * back from eWAY. eWAY's replies come from a mock HTTP client ("METHOD path" => reply), the tables from in-memory SQLite.
 */

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function Tests\Helpers\container;

function ewayModel(string $class, array $values): object
{
    $model = new $class();
    $model->loadBean(new Tests\Helpers\DummyBean());
    foreach ($values as $key => $value) {
        $model->{$key} = $value;
    }

    return $model;
}

function ewayInvoice(array $values = []): Model_Invoice
{
    return ewayModel(Model_Invoice::class, $values + ['id' => 5, 'client_id' => 7, 'currency' => 'AUD', 'hash' => 'abc', 'status' => 'unpaid', 'approved' => 1]);
}

/**
 * An adapter (gateway 3, sandbox) whose API calls get $replies ("METHOD path" => reply, or a callable of the request
 * body; an error reply is [status, body]), recorded in $world->calls as [method path, body, authorization]. The client
 * (#7) has the saved card "9100000001".
 */
function ewayWorld(array $replies): object
{
    $world = new stdClass();
    $world->calls = [];
    $http = new MockHttpClient(function (string $method, string $url, array $options) use ($replies, $world): MockResponse {
        $key = $method . ' ' . parse_url($url, PHP_URL_PATH);
        $body = json_decode((string) ($options['body'] ?? ''), true);
        $world->calls[] = [$key, $body, $options['normalized_headers']['authorization'][0] ?? '', parse_url($url, PHP_URL_HOST)];
        $reply = $replies[$key] ?? [404, ['Errors' => 'Unexpected']];
        $reply = is_callable($reply) ? $reply($body) : $reply;
        [$status, $reply] = isset($reply[0], $reply[1]) && is_int($reply[0]) ? $reply : [200, $reply];

        return new MockResponse((string) json_encode($reply), ['http_code' => $status]);
    });
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE pay_gateway_customer (id INTEGER PRIMARY KEY, pay_gateway_id INTEGER, client_id INTEGER, test_mode INTEGER, external_customer_id TEXT, saved_cards INTEGER DEFAULT 0, default_payment_method TEXT, default_card TEXT, auto_pay_at TEXT, created_at TEXT, updated_at TEXT)');
    $db->executeStatement('CREATE TABLE `transaction` (id INTEGER PRIMARY KEY, invoice_id INTEGER, gateway_id INTEGER, txn_id TEXT, txn_status TEXT, amount TEXT, currency TEXT, type TEXT, status TEXT, error TEXT, error_code INTEGER, note TEXT, created_at TEXT, updated_at TEXT)');
    $db->insert('pay_gateway_customer', ['pay_gateway_id' => 3, 'client_id' => 7, 'test_mode' => 1, 'external_customer_id' => '', 'saved_cards' => 1, 'default_payment_method' => '9100000001', 'default_card' => 'Card •••• 1111 (12/28)', 'auto_pay_at' => '2026-11-01 00:00:00']);
    $world->db = $db;

    $invoices = Mockery::mock();
    $invoices->allows('getBalanceDue')->andReturn(12.5);
    $invoices->allows('toApiArray')->andReturn(['serie_nr' => 'INV-00005']);
    $world->invoices = $invoices;
    $di = container();
    $di['dbal'] = $db;
    $di['http_client'] = $http;
    $di['mod_service'] = $di->protect(fn (): object => $invoices);
    $di['db'] = Mockery::mock();
    $di['db']->allows('getExistingModelById')->andReturnUsing(fn (string $type, int $id): object => $type === 'Client'
        ? ewayModel(Model_Client::class, ['id' => $id, 'first_name' => 'Test', 'last_name' => 'Client', 'email' => 'test@example.com', 'country' => 'AU', 'city' => 'Sydney'])
        : ewayInvoice(['id' => $id]));
    $di['url'] = new class {
        public function link(string $path, array $params = []): string
        {
            return 'https://portal.example/' . $path . ($params === [] ? '' : '?' . http_build_query($params));
        }
    };
    $world->adapter = new Payment_Adapter_Eway(['gateway_id' => 3, 'test_mode' => true, 'test_api_key' => 'KEY', 'test_api_password' => 'PASS']);
    $world->adapter->setDi($di);

    return $world;
}

function ewaySaved(Connection $db): ?string
{
    $value = $db->fetchOne('SELECT default_payment_method FROM pay_gateway_customer WHERE pay_gateway_id = 3 AND client_id = 7');

    return $value === false ? null : $value;
}

/** eWAY's answer to a transaction query. */
function ewayTransaction(string $reference, bool $approved = true, array $more = []): array
{
    return ['Transactions' => [$more + ['TransactionID' => 11223344, 'TransactionStatus' => $approved, 'ResponseMessage' => $approved ? 'A2000' : 'D4405', 'InvoiceReference' => $reference, 'TotalAmount' => 1250, 'CurrencyCode' => 'AUD']]];
}

/** eWAY's token customer with the Reference set here. */
function ewayCustomer(string $reference): array
{
    return ['Customers' => [['TokenCustomerID' => 9100000002, 'Reference' => $reference, 'CardDetails' => ['Number' => '444433XXXXXX1111', 'ExpiryMonth' => '3', 'ExpiryYear' => '29']]]];
}

test('needs the API key and password of the current mode, and checks them with eWAY', function (): void {
    expect(fn () => new Payment_Adapter_Eway(['test_mode' => false, 'test_api_key' => 'k', 'test_api_password' => 'p']))->toThrow(Payment_Exception::class);

    $ok = ewayWorld(['GET /Transaction/0' => ['Transactions' => []]]);
    $ok->adapter->testConnection();
    expect($ok->calls[0][2])->toBe('Authorization: Basic ' . base64_encode('KEY:PASS'))
        ->and($ok->calls[0][3])->toBe('api.sandbox.ewaypayments.com');

    $wrong = ewayWorld(['GET /Transaction/0' => [401, []]]);
    expect(fn () => $wrong->adapter->testConnection())->toThrow(ClientFence\InformationException::class, 'HTTP 401');
});

test('charges the saved card as a recurring payment, and removes a card eWAY no longer knows', function (): void {
    $world = ewayWorld(['POST /Transaction' => ['TransactionID' => 11223345, 'TransactionStatus' => true, 'ResponseMessage' => 'A2000']]);
    expect($world->adapter->chargeSavedCard(ewayInvoice(), 'attempt_1'))->toBe('11223345')
        ->and($world->calls[0][1])->toBe([
            'Customer' => ['TokenCustomerID' => '9100000001'],
            'Payment' => ['TotalAmount' => 1250, 'InvoiceNumber' => 'INV-00005', 'InvoiceDescription' => 'Invoice INV-00005', 'InvoiceReference' => 'cf-invoice-5-attempt_1', 'CurrencyCode' => 'AUD'],
            'Method' => 'TokenPayment',
            'TransactionType' => 'Recurring',
        ]);

    $declined = ewayWorld(['POST /Transaction' => ['TransactionID' => 11223346, 'TransactionStatus' => false, 'ResponseMessage' => 'D4405']]);
    expect(fn () => $declined->adapter->chargeSavedCard(ewayInvoice(), 'attempt_1'))->toThrow(ClientFence\InformationException::class, 'eWAY refused the payment: D4405 Do Not Honour');
    expect(ewaySaved($declined->db))->toBe('9100000001');

    $gone = ewayWorld(['POST /Transaction' => ['TransactionStatus' => false, 'Errors' => 'V6040']]);
    expect(fn () => $gone->adapter->chargeSavedCard(ewayInvoice(), 'attempt_1'))->toThrow(ClientFence\InformationException::class);
    expect(ewaySaved($gone->db))->toBeNull();

    $down = ewayWorld(['POST /Transaction' => [503, []]]);
    expect(fn () => $down->adapter->chargeSavedCard(ewayInvoice(), 'attempt_1'))->toThrow(Payment_Exception::class, 'HTTP 503');

    $silent = ewayWorld(['POST /Transaction' => fn () => throw new RuntimeException('timeout')]);
    expect(fn () => $silent->adapter->chargeSavedCard(ewayInvoice(), 'attempt_1'))->toThrow(Payment_Exception::class);
});

test('opens a payment page that saves the card for the owner and one that only pays', function (): void {
    $world = ewayWorld(['POST /AccessCodesShared' => ['AccessCode' => 'AC1', 'SharedPaymentUrl' => 'https://secure-au.sandbox.ewaypayments.com/sharedpage/sharedpayment?AccessCode=AC1']]);
    expect($world->adapter->checkout(ewayInvoice(), true))->toEndWith('AccessCode=AC1');
    $save = $world->calls[0][1];
    expect($save)->toMatchArray(['Method' => 'TokenPayment', 'TransactionType' => 'Purchase', 'RedirectUrl' => 'https://portal.example/invoice/eway-paid?hash=abc'])
        ->and($save['Payment']['InvoiceReference'])->toStartWith('cf-invoice-5-save')
        ->and($save['Customer'])->toMatchArray(['Reference' => 'cf-client-7', 'FirstName' => 'Test', 'LastName' => 'Client', 'Country' => 'au']);

    $world->adapter->checkout(ewayInvoice(), false);
    expect($world->calls[1][1]['Method'])->toBe('ProcessPayment')
        ->and($world->calls[1][1]['Payment']['InvoiceReference'])->toStartWith('cf-invoice-5-pay');

    $refused = ewayWorld(['POST /AccessCodesShared' => ['Errors' => 'V6021']]);
    expect(fn () => $refused->adapter->checkout(ewayInvoice(), true))->toThrow(ClientFence\InformationException::class, 'V6021');
});

test('saves a card set up without a charge for the client its reference names, within the hour', function (): void {
    $fresh = 'cf-client-7-save' . time() . '-0123456789abcdef';
    $world = ewayWorld([
        'POST /AccessCodesShared' => ['AccessCode' => 'AC2', 'SharedPaymentUrl' => 'https://secure.example/AC2'],
        'GET /AccessCode/AC2AC2AC2AC2' => ['TokenCustomerID' => 9100000002, 'ResponseMessage' => 'A2000'],
        'GET /Customer/9100000002' => ewayCustomer($fresh),
    ]);
    $world->adapter->cardSetup(7, 'https://portal.example/payment-methods/eway/saved');
    expect($world->calls[0][1])->toMatchArray(['Method' => 'CreateTokenCustomer', 'Payment' => ['TotalAmount' => 0]])
        ->and($world->calls[0][1]['Customer']['Reference'])->toMatch('/^cf-client-7-save\d+-[0-9a-f]{16}$/');

    expect($world->adapter->cardSaved('AC2AC2AC2AC2'))->toBe(7)
        ->and(ewaySaved($world->db))->toBe('9100000002')
        ->and($world->db->fetchOne('SELECT default_card FROM pay_gateway_customer WHERE client_id = 7'))->toBe('Card •••• 1111 (03/29)');

    // A setup from more than an hour ago sent again never brings back a card.
    $old = ewayWorld([
        'GET /AccessCode/AC2AC2AC2AC2' => ['TokenCustomerID' => 9100000002],
        'GET /Customer/9100000002' => ewayCustomer('cf-client-7-save' . (time() - 7200) . '-0123456789abcdef'),
    ]);
    expect(fn () => $old->adapter->cardSaved('AC2AC2AC2AC2'))->toThrow(ClientFence\InformationException::class, 'eWAY did not save the card');
    expect(ewaySaved($old->db))->toBe('9100000001');

    // Cancelled at eWAY: no AccessCode.
    expect(fn () => $old->adapter->cardSaved(''))->toThrow(ClientFence\InformationException::class, 'eWAY did not save the card');
});

test('records a payment read back from eWAY on its invoice once, keeping the card only from a page that saves it', function (string $reference, bool $approved, array $more, string $owner, ?string $error, string $rowStatus, bool $applied, string $card): void {
    $world = ewayWorld([
        'GET /AccessCode/AC3AC3AC3AC3' => ['TransactionID' => 11223344],
        'GET /Transaction/11223344' => ewayTransaction($reference, $approved, $more + ['TokenCustomerID' => 9100000002]),
        'GET /Customer/9100000002' => ewayCustomer($owner),
    ]);
    $world->db->insert('`transaction`', ['id' => 40, 'gateway_id' => 3, 'status' => 'received']);
    $world->invoices->allows('validatePaymentAmount');
    $world->invoices->allows('isInvoiceTypeDeposit')->andReturn(false);
    $api = Mockery::mock();
    $api->shouldReceive('client_balance_add_funds')->times($applied ? 1 : 0)->with(Mockery::subset(['id' => 7, 'amount' => 12.5, 'rel_id' => '11223344']));
    $api->shouldReceive('invoice_pay_with_credits')->times($applied ? 1 : 0)->with(['id' => 5]);

    $run = fn () => $world->adapter->processTransaction($api, 40, ['source' => 'eway_return', 'get' => ['AccessCode' => 'AC3AC3AC3AC3']], 3);
    $error === null ? $run() : expect($run)->toThrow(Payment_Exception::class, $error);
    expect($world->db->fetchOne('SELECT status FROM `transaction` WHERE id = 40'))->toBe($rowStatus)
        ->and(ewaySaved($world->db))->toBe($card);
})->with([
    'paid and saved' => ['cf-invoice-5-save1700000000', true, [], 'cf-client-7', null, 'processed', true, '9100000002'],
    'paid and saved, another client\'s card' => ['cf-invoice-5-save1700000000', true, [], 'cf-client-8', null, 'processed', true, '9100000001'],
    'paid once' => ['cf-invoice-5-pay1700000000', true, [], 'cf-client-7', null, 'processed', true, '9100000001'],
    'declined' => ['cf-invoice-5-pay1700000000', false, [], 'cf-client-7', null, 'error', false, '9100000001'],
    'another shop\'s payment' => ['77', true, [], 'cf-client-7', 'is not for a ClientFence invoice', 'received', false, '9100000001'],
    'a refund' => ['cf-invoice-5-pay1700000000', true, ['OriginalTransactionId' => 11223300], 'cf-client-7', 'is not for a ClientFence invoice', 'received', false, '9100000001'],
    'another currency' => ['cf-invoice-5-pay1700000000', true, ['CurrencyCode' => 'NZD'], 'cf-client-7', 'is in NZD', 'received', false, '9100000001'],
    'paid, currency by number' => ['cf-invoice-5-pay1700000000', true, ['CurrencyCode' => '036'], 'cf-client-7', null, 'processed', true, '9100000001'],
    'another currency by number' => ['cf-invoice-5-pay1700000000', true, ['CurrencyCode' => '554'], 'cf-client-7', 'is in 554', 'received', false, '9100000001'],
]);

test('a payment recorded already is not credited again, and a saved card charge is read back by its ID', function (): void {
    $world = ewayWorld(['GET /Transaction/11223344' => ewayTransaction('cf-invoice-5-attempt_1')]);
    $world->db->insert('`transaction`', ['id' => 45, 'gateway_id' => 2, 'invoice_id' => 5, 'txn_id' => '11223344', 'type' => 'eway', 'status' => 'processed', 'txn_status' => 'Completed']);
    $world->db->insert('`transaction`', ['id' => 46, 'gateway_id' => 3, 'status' => 'received']);
    $world->adapter->processTransaction(Mockery::mock(), 46, ['source' => 'saved_card', 'get' => ['eway_tran' => '11223344']], 3);
    expect($world->calls[0][0])->toBe('GET /Transaction/11223344')
        ->and($world->db->fetchOne('SELECT note FROM `transaction` WHERE id = 46'))->toBe('Payment 11223344 was already recorded on transaction #45.');

    expect(fn () => $world->adapter->processTransaction(Mockery::mock(), 46, ['source' => 'eway_return', 'get' => []], 3))->toThrow(Payment_Exception::class, 'names no payment');
});

test('refunds in the currency\'s smallest unit', function (): void {
    $world = ewayWorld(['POST /Transaction/11223344/Refund' => ['TransactionID' => 11223399, 'TransactionStatus' => true]]);
    expect($world->adapter->refundPayment('11223344', 5.25, 'AUD'))->toBe('11223399')
        ->and($world->calls[0][1])->toBe(['Refund' => ['TransactionID' => '11223344', 'TotalAmount' => 525, 'CurrencyCode' => 'AUD']]);
    $world->adapter->refundPayment('11223344', 500.0, 'JPY');
    expect($world->calls[1][1]['Refund']['TotalAmount'])->toBe(500);

    $refused = ewayWorld(['POST /Transaction/11223344/Refund' => ['TransactionStatus' => false, 'Errors' => 'V6151']]);
    expect(fn () => $refused->adapter->refundPayment('11223344', 5.0, 'AUD'))->toThrow(ClientFence\InformationException::class, 'V6151');
});
