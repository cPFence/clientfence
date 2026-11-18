<?php

declare(strict_types=1);

/**
 * ClientFence: Mollie (Payment_Adapter_Mollie): payment pages, saved methods charged as recurring payments, payments
 * read back from Mollie, pending SEPA charges. Mollie's replies come from a mock HTTP client ("METHOD path" => reply),
 * the tables from in-memory SQLite.
 */

use ClientFence\Mod\Invoice\PendingPayments;
use ClientFence\Mod\Invoice\SavedCards;
use Doctrine\DBAL\Connection;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function Tests\Helpers\container;

function mollieModel(string $class, array $values): object
{
    $model = new $class();
    $model->loadBean(new Tests\Helpers\DummyBean());
    foreach ($values as $key => $value) {
        $model->{$key} = $value;
    }

    return $model;
}

function mollieInvoice(array $values = []): Model_Invoice
{
    return mollieModel(Model_Invoice::class, $values + ['id' => 5, 'client_id' => 7, 'currency' => 'EUR', 'hash' => 'abc', 'status' => 'unpaid', 'approved' => 1]);
}

/**
 * An adapter (gateway 3, test mode) whose API calls get $replies ("METHOD /v2/path" => reply, or a callable of the
 * request body; an error reply is [status, body]), recorded in $world->calls as [method path, body, authorization].
 * Client #7 has the Mollie customer cst_7 with the saved mandate mdt_1; invoice #5 is unpaid.
 */
function mollieWorld(array $replies, array $config = []): object
{
    $world = new stdClass();
    $world->calls = [];
    $world->emails = [];
    $http = new MockHttpClient(function (string $method, string $url, array $options) use ($replies, $world): MockResponse {
        $key = $method . ' ' . parse_url($url, PHP_URL_PATH);
        $body = json_decode((string) ($options['body'] ?? ''), true);
        $world->calls[] = [$key, $body, $options['normalized_headers']['authorization'][0] ?? ''];
        $reply = $replies[$key] ?? [404, ['status' => 404, 'title' => 'Not Found', 'detail' => 'Unexpected']];
        $reply = is_callable($reply) ? $reply($body) : $reply;
        [$status, $reply] = is_int($reply[0] ?? null) && array_key_exists(1, $reply) ? $reply : [200, $reply];

        return new MockResponse($reply === null ? '' : (string) json_encode($reply), ['http_code' => $status]);
    });
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE pay_gateway_customer (id INTEGER PRIMARY KEY, pay_gateway_id INTEGER, client_id INTEGER, test_mode INTEGER, external_customer_id TEXT, saved_cards INTEGER DEFAULT 0, default_payment_method TEXT, default_card TEXT, auto_pay_at TEXT, created_at TEXT, updated_at TEXT)');
    $db->executeStatement('CREATE TABLE `transaction` (id INTEGER PRIMARY KEY, invoice_id INTEGER, gateway_id INTEGER, txn_id TEXT, txn_status TEXT, amount TEXT, currency TEXT, type TEXT, status TEXT, error TEXT, error_code INTEGER, note TEXT, created_at TEXT, updated_at TEXT)');
    $db->executeStatement('CREATE TABLE client_balance (id INTEGER PRIMARY KEY, client_id INTEGER, type TEXT, rel_id TEXT, amount REAL)');
    $db->executeStatement('CREATE TABLE invoice (id INTEGER PRIMARY KEY, client_id INTEGER, status TEXT, approved INTEGER DEFAULT 1, gateway_id INTEGER)');
    $db->executeStatement('CREATE TABLE invoice_item (id INTEGER PRIMARY KEY, invoice_id INTEGER, type TEXT, task TEXT)');
    $db->insert('pay_gateway_customer', ['pay_gateway_id' => 3, 'client_id' => 7, 'test_mode' => 1, 'external_customer_id' => 'cst_7', 'saved_cards' => 1, 'default_payment_method' => 'cst_7 mdt_1', 'default_card' => 'Visa •••• 4242', 'auto_pay_at' => '2026-11-01 00:00:00']);
    $db->insert('invoice', ['id' => 5, 'client_id' => 7, 'status' => 'unpaid']);
    $world->db = $db;

    // One service mock for every module the adapter and SavedCards ask (Invoice, Currency, system, extension, email).
    $services = Mockery::mock();
    $services->allows('getBalanceDue')->andReturn(12.5)->byDefault();
    $services->allows('toApiArray')->andReturn(['serie_nr' => 'INV-00005']);
    $services->allows('getCurrencyByClientId')->andReturn(new class {
        public function getCode(): string
        {
            return 'EUR';
        }
    });
    $services->allows('getParamValue')->andReturn('Example Co');
    $services->allows('getConfig')->andReturn([]);
    $services->allows('withBillingRecipient')->andReturnUsing(fn (array $email): array => $email);
    $services->allows('sendTemplate')->andReturnUsing(function (array $email) use ($world): bool {
        $world->emails[] = $email;

        return true;
    });
    $world->services = $services;
    $di = container();
    $di['dbal'] = $db;
    $di['http_client'] = $http;
    $di['cache'] = new ArrayAdapter();
    $di['mod_service'] = $di->protect(fn (): object => $services);
    $di['db'] = Mockery::mock();
    $di['db']->allows('getExistingModelById')->andReturnUsing(fn (string $type, int $id): object => $type === 'Client'
        ? mollieModel(Model_Client::class, ['id' => $id, 'first_name' => 'Test', 'last_name' => 'Client', 'email' => 'test@example.com'])
        : mollieInvoice(['id' => $id]));
    $di['url'] = new class {
        public function link(string $path, array $params = []): string
        {
            return 'https://portal.example/' . $path . ($params === [] ? '' : '?' . http_build_query($params));
        }
    };
    $world->di = $di;
    $world->adapter = new Payment_Adapter_Mollie($config + ['gateway_id' => 3, 'test_mode' => true, 'test_api_key' => 'test_KEY']);
    $world->adapter->setDi($di);

    return $world;
}

function mollieSaved(Connection $db, int $clientId = 7): ?string
{
    $value = $db->fetchOne('SELECT default_payment_method FROM pay_gateway_customer WHERE pay_gateway_id = 3 AND client_id = ?', [$clientId]);

    return $value === false ? null : $value;
}

/** Mollie's payment tr_abc for invoice #5, paid by card with mandate mdt_2 of customer cst_7. */
function molliePayment(string $purpose = 'pay', string $status = 'paid', array $more = []): array
{
    return $more + [
        'id' => 'tr_abc', 'status' => $status, 'amount' => ['currency' => 'EUR', 'value' => '12.50'], 'sequenceType' => $purpose === 'charge' ? 'recurring' : 'oneoff',
        'metadata' => ['clientfence' => $purpose, 'invoice_id' => 5, 'client_id' => 7, 'site' => INSTANCE_ID], 'customerId' => 'cst_7', 'mandateId' => 'mdt_2',
        'method' => 'creditcard', 'details' => ['cardLabel' => 'Visa', 'cardNumber' => '1234'],
    ];
}

function mollieCreated(string $id = 'tr_new'): array
{
    return [201, ['id' => $id, 'status' => 'open', '_links' => ['checkout' => ['href' => 'https://www.mollie.com/checkout/' . $id]]]];
}

/** Our reference from the redirectUrl Mollie was given. */
function mollieReference(array $body): string
{
    parse_str((string) parse_url((string) $body['redirectUrl'], PHP_URL_QUERY), $query);

    return (string) $query['ref'];
}

test('needs the API key of the current mode, and checks it with Mollie', function (): void {
    expect(fn () => new Payment_Adapter_Mollie(['test_mode' => false, 'test_api_key' => 'test_KEY']))->toThrow(Payment_Exception::class);

    $ok = mollieWorld(['GET /v2/methods' => ['count' => 0]]);
    $ok->adapter->testConnection();
    expect($ok->calls[0][2])->toBe('Authorization: Bearer test_KEY');

    $wrong = mollieWorld(['GET /v2/methods' => [401, ['status' => 401, 'title' => 'Unauthorized Request', 'detail' => 'Missing authentication, or failed to authenticate']]]);
    expect(fn () => $wrong->adapter->testConnection())->toThrow(ClientFence\InformationException::class, 'HTTP 401');

    // What Mollie answers a malformed key (seen on the dev site).
    $malformed = mollieWorld(['GET /v2/methods' => [400, ['status' => 400, 'title' => 'Bad Request', 'detail' => 'Invalid Authorization header']]]);
    expect(fn () => $malformed->adapter->testConnection())->toThrow(ClientFence\InformationException::class, 'Invalid Authorization header');

    $live = mollieWorld([], ['test_api_key' => 'live_KEY']);
    expect(fn () => $live->adapter->testConnection())->toThrow(ClientFence\InformationException::class, 'test_')
        ->and($live->calls)->toBe([]);
});

test('opens a payment page that pays once, or one that saves the method with a new Mollie customer', function (): void {
    $world = mollieWorld(['POST /v2/payments' => mollieCreated(), 'POST /v2/customers' => [201, ['id' => 'cst_8']]]);
    $world->services->allows('getBalanceDue')->andReturn(1234.5);
    expect($world->adapter->checkout(mollieInvoice(), false))->toBe('https://www.mollie.com/checkout/tr_new');
    $once = $world->calls[0][1];
    expect($once)->toMatchArray([
        'amount' => ['currency' => 'EUR', 'value' => '1234.50'],
        'description' => 'Invoice INV-00005',
        'metadata' => ['clientfence' => 'pay', 'invoice_id' => 5, 'client_id' => 7, 'site' => INSTANCE_ID],
    ])->and($once)->not->toHaveKey('sequenceType')->not->toHaveKey('customerId')
        ->and($once['redirectUrl'])->toStartWith('https://portal.example/invoice/mollie-paid?ref=')
        ->and(array_key_exists('webhookUrl', $once))->toBe(Payment_Adapter_Mollie::reachable(SYSTEM_URL))
        ->and($world->adapter->paymentOf(mollieReference($once)))->toBe('tr_new')
        ->and($world->adapter->paymentOf('0123456789abcdef0123456789abcdef'))->toBe('');

    $world->adapter->checkout(mollieInvoice(['client_id' => 8, 'currency' => 'JPY']), true);
    expect($world->calls[1][0])->toBe('POST /v2/customers')
        ->and($world->calls[1][1])->toBe(['name' => 'Test Client', 'email' => 'test@example.com', 'metadata' => ['client_id' => 8]])
        ->and($world->calls[2][1])->toMatchArray(['amount' => ['currency' => 'JPY', 'value' => '1235'], 'sequenceType' => 'first', 'customerId' => 'cst_8'])
        ->and($world->calls[2][1]['metadata']['clientfence'])->toBe('save')
        ->and($world->db->fetchOne('SELECT external_customer_id FROM pay_gateway_customer WHERE client_id = 8'))->toBe('cst_8');

    // The client's customer is made once.
    $world->adapter->checkout(mollieInvoice(['client_id' => 8]), true);
    expect($world->calls[3][0])->toBe('POST /v2/payments');

    $refused = mollieWorld(['POST /v2/payments' => [422, ['status' => 422, 'title' => 'Unprocessable Entity', 'detail' => 'The amount is lower than the minimum']]]);
    expect(fn () => $refused->adapter->checkout(mollieInvoice(), false))->toThrow(ClientFence\InformationException::class, 'The amount is lower than the minimum');
});

test('leaves the webhook out where Mollie cannot reach it', function (string $url, bool $reachable): void {
    expect(Payment_Adapter_Mollie::reachable($url))->toBe($reachable);
})->with([
    ['http://localhost/', false],
    ['http://clientfence.localhost:8090/', false],
    ['http://192.168.1.10/billing/', false],
    ['http://127.0.0.1:8080/', false],
    ['https://billing.example.com/', true],
    ['https://8.8.8.8/', true],
]);

test('saves a card set up with a first payment of 0.00 for the client it names, once, revoking the mandate before', function (): void {
    $world = mollieWorld([
        'POST /v2/payments' => mollieCreated('tr_setup'),
        'GET /v2/payments/tr_setup' => molliePayment('setup', 'paid', ['id' => 'tr_setup', 'amount' => ['currency' => 'EUR', 'value' => '0.00'], 'metadata' => ['clientfence' => 'setup', 'client_id' => 7]]),
        'DELETE /v2/customers/cst_7/mandates/mdt_1' => [204, null],
        'DELETE /v2/customers/cst_7/mandates/mdt_2' => [204, null],
    ]);
    $world->adapter->cardSetup(7, 'https://portal.example/payment-methods/mollie/saved?next=payment-methods');
    $setup = $world->calls[0][1];
    expect($setup)->toMatchArray(['amount' => ['currency' => 'EUR', 'value' => '0.00'], 'sequenceType' => 'first', 'customerId' => 'cst_7', 'method' => ['creditcard', 'paypal']])
        ->and($setup['redirectUrl'])->toStartWith('https://portal.example/payment-methods/mollie/saved?next=payment-methods&ref=');

    expect($world->adapter->cardSaved(mollieReference($setup)))->toBe(7)
        ->and(mollieSaved($world->db))->toBe('cst_7 mdt_2')
        ->and($world->db->fetchOne('SELECT default_card FROM pay_gateway_customer WHERE client_id = 7'))->toBe('Visa •••• 1234')
        ->and(array_column($world->calls, 0))->toContain('DELETE /v2/customers/cst_7/mandates/mdt_1');

    // The same return again never brings back a method (here: a removed one).
    $world->adapter->cardRemove(7);
    expect(fn () => $world->adapter->cardSaved(mollieReference($setup)))->toThrow(ClientFence\InformationException::class, 'Mollie did not save the payment method')
        ->and(mollieSaved($world->db))->toBeNull();
});

test('removes a saved method here and revokes its mandate at Mollie', function (): void {
    $world = mollieWorld(['DELETE /v2/customers/cst_7/mandates/mdt_1' => [204, null]]);
    $world->adapter->cardRemove(7);
    expect($world->calls[0][0])->toBe('DELETE /v2/customers/cst_7/mandates/mdt_1')
        ->and(mollieSaved($world->db))->toBeNull();
});

test('records a paid payment read back from Mollie on its invoice once, keeping the method only from a page that saves it', function (array $payment, ?string $error, string $rowStatus, bool $applied, string $method): void {
    $world = mollieWorld(['GET /v2/payments/tr_abc' => $payment, 'DELETE /v2/customers/cst_7/mandates/mdt_1' => [204, null]]);
    $world->db->insert('`transaction`', ['id' => 40, 'gateway_id' => 3, 'status' => 'received']);
    $world->services->allows('validatePaymentAmount')->andReturnUsing(fn (float $received) => $received < 12.5 ? throw new ClientFence\Exception('Payment amount does not match') : null);
    $world->services->allows('isInvoiceTypeDeposit')->andReturn(false);
    $api = Mockery::mock();
    $api->shouldReceive('client_balance_add_funds')->times($applied ? 1 : 0)->with(Mockery::subset(['id' => 7, 'amount' => 12.5, 'rel_id' => 'tr_abc']));
    $api->shouldReceive('invoice_pay_with_credits')->times($applied ? 1 : 0)->with(['id' => 5]);

    $run = fn () => $world->adapter->processTransaction($api, 40, ['source' => 'mollie_return', 'get' => ['mollie_payment' => 'tr_abc']], 3);
    $error === null ? $run() : expect($run)->toThrow(Exception::class, $error);
    expect($world->db->fetchOne('SELECT status FROM `transaction` WHERE id = 40'))->toBe($rowStatus)
        ->and(mollieSaved($world->db))->toBe($method);
})->with([
    'paid once' => [molliePayment('pay'), null, 'processed', true, 'cst_7 mdt_1'],
    'paid and saved' => [molliePayment('save', 'paid', ['sequenceType' => 'first']), null, 'processed', true, 'cst_7 mdt_2'],
    'paid and saved, another customer' => [molliePayment('save', 'paid', ['customerId' => 'cst_9']), null, 'processed', true, 'cst_7 mdt_1'],
    'still open' => [molliePayment('pay', 'open'), null, 'error', false, 'cst_7 mdt_1'],
    'failed' => [molliePayment('pay', 'failed'), null, 'error', false, 'cst_7 mdt_1'],
    'another shop\'s payment' => [molliePayment('pay', 'paid', ['metadata' => ['order' => 5]]), 'is not for a ClientFence invoice', 'received', false, 'cst_7 mdt_1'],
    'a payment of another portal on the same profile' => [molliePayment('pay', 'paid', ['metadata' => ['clientfence' => 'pay', 'invoice_id' => 5, 'client_id' => 7, 'site' => 'other-install']]), 'is not for a ClientFence invoice', 'received', false, 'cst_7 mdt_1'],
    'a payment naming another client' => [molliePayment('pay', 'paid', ['metadata' => ['clientfence' => 'pay', 'invoice_id' => 5, 'client_id' => 8, 'site' => INSTANCE_ID]]), 'is not for a ClientFence invoice', 'received', false, 'cst_7 mdt_1'],
    'another currency' => [molliePayment('pay', 'paid', ['amount' => ['currency' => 'USD', 'value' => '12.50']]), 'is in USD', 'received', false, 'cst_7 mdt_1'],
    'less than the balance' => [molliePayment('pay', 'paid', ['amount' => ['currency' => 'EUR', 'value' => '5.00']]), 'Payment amount does not match', 'received', false, 'cst_7 mdt_1'],
]);

test('a payment credited by a try that failed after it is not credited again when its notice comes back', function (): void {
    $world = mollieWorld(['GET /v2/payments/tr_abc' => molliePayment('pay')]);
    // Transaction 40 credited tr_abc, then paying the invoice timed out on the client's credit lock.
    $world->db->insert('`transaction`', ['id' => 40, 'gateway_id' => 3, 'invoice_id' => 5, 'txn_id' => 'tr_abc', 'type' => 'mollie', 'status' => 'error', 'txn_status' => 'Completed']);
    $world->db->insert('client_balance', ['client_id' => 7, 'type' => 'Mollie', 'rel_id' => 'tr_abc', 'amount' => 12.5]);
    $world->db->insert('`transaction`', ['id' => 41, 'gateway_id' => 3, 'status' => 'received']);
    $world->services->allows('validatePaymentAmount');
    $world->services->allows('isInvoiceTypeDeposit')->andReturn(false);
    $api = Mockery::mock();
    $api->shouldNotReceive('client_balance_add_funds');
    $api->shouldReceive('invoice_pay_with_credits')->once()->with(['id' => 5]);

    $world->adapter->processTransaction($api, 41, ['source' => 'mollie_return', 'get' => ['mollie_payment' => 'tr_abc']], 3);

    expect($world->db->fetchOne('SELECT status FROM `transaction` WHERE id = 41'))->toBe('processed');
});

test('a payment recorded already is not credited again, and the webhook names the payment only by its posted id', function (): void {
    $world = mollieWorld(['GET /v2/payments/tr_abc' => molliePayment('pay')]);
    $world->db->insert('`transaction`', ['id' => 45, 'gateway_id' => 3, 'invoice_id' => 5, 'txn_id' => 'tr_abc', 'type' => 'mollie', 'status' => 'processed', 'txn_status' => 'Completed']);
    $world->db->insert('`transaction`', ['id' => 46, 'gateway_id' => 3, 'status' => 'received']);
    $world->adapter->processTransaction(Mockery::mock(), 46, ['source' => 'ipn', 'post' => ['id' => 'tr_abc']], 3);
    expect($world->calls[0][0])->toBe('GET /v2/payments/tr_abc')
        ->and($world->db->fetchOne('SELECT note FROM `transaction` WHERE id = 46'))->toBe('Payment tr_abc was already recorded on transaction #45.');

    expect(fn () => $world->adapter->processTransaction(Mockery::mock(), 46, ['source' => 'ipn', 'get' => ['mollie_payment' => 'tr_abc']], 3))->toThrow(Payment_Exception::class, 'names no payment');
});

test('a chargeback of a recorded payment is taken back on its invoice once', function (): void {
    $world = mollieWorld(['GET /v2/payments/tr_abc' => molliePayment('pay', 'paid', ['amountChargedBack' => ['currency' => 'EUR', 'value' => '12.50']])]);
    $world->db->insert('`transaction`', ['id' => 45, 'gateway_id' => 3, 'invoice_id' => 5, 'txn_id' => 'tr_abc', 'type' => 'mollie', 'amount' => '12.50', 'status' => 'processed', 'txn_status' => 'Completed']);
    $world->db->insert('`transaction`', ['id' => 46, 'gateway_id' => 3, 'status' => 'received']);
    $world->db->insert('`transaction`', ['id' => 47, 'gateway_id' => 3, 'status' => 'received']);
    $world->services->shouldReceive('chargeback')->once()->with(Mockery::type(Model_Invoice::class), 12.5, 'Mollie chargeback of payment tr_abc.')->andReturn('Taken back.');

    $world->adapter->processTransaction(Mockery::mock(), 46, ['source' => 'ipn', 'post' => ['id' => 'tr_abc']], 3);
    $world->adapter->processTransaction(Mockery::mock(), 47, ['source' => 'ipn', 'post' => ['id' => 'tr_abc']], 3);
    // "Process again" of the payment's own row takes nothing back either.
    $world->adapter->processTransaction(Mockery::mock(), 45, ['source' => 'ipn', 'post' => ['id' => 'tr_abc']], 3);

    expect($world->db->fetchAssociative('SELECT type, note FROM `transaction` WHERE id = 46'))->toBe(['type' => 'mollie_chargeback', 'note' => 'Taken back.'])
        ->and($world->db->fetchAssociative('SELECT type, note FROM `transaction` WHERE id = 47'))->toBe(['type' => null, 'note' => 'Payment tr_abc was already recorded on transaction #45.'])
        ->and($world->db->fetchOne('SELECT type FROM `transaction` WHERE id = 45'))->toBe('mollie');
});

test('charges the saved method as a recurring payment: paid or pending gives its ID, a refusal or no answer throws', function (): void {
    $world = mollieWorld(['POST /v2/payments' => [201, molliePayment('charge', 'paid', ['id' => 'tr_charge'])]]);
    expect($world->adapter->chargeSavedCard(mollieInvoice(), 'attempt_1'))->toBe('tr_charge')
        ->and($world->calls[0][1])->toMatchArray([
            'amount' => ['currency' => 'EUR', 'value' => '12.50'],
            'metadata' => ['clientfence' => 'charge', 'invoice_id' => 5, 'client_id' => 7, 'site' => INSTANCE_ID],
            'sequenceType' => 'recurring', 'customerId' => 'cst_7', 'mandateId' => 'mdt_1',
        ]);

    $pending = mollieWorld(['POST /v2/payments' => [201, molliePayment('charge', 'pending', ['id' => 'tr_sepa', 'method' => 'directdebit'])]]);
    expect($pending->adapter->chargeSavedCard(mollieInvoice(), 'attempt_1'))->toBe('tr_sepa');

    $declined = mollieWorld(['POST /v2/payments' => [201, molliePayment('charge', 'failed', ['details' => ['failureReason' => 'insufficient_funds']])]]);
    expect(fn () => $declined->adapter->chargeSavedCard(mollieInvoice(), 'attempt_1'))->toThrow(ClientFence\InformationException::class, 'Mollie refused the payment: insufficient_funds');

    $invalid = mollieWorld(['POST /v2/payments' => [422, ['status' => 422, 'title' => 'Unprocessable Entity', 'detail' => 'The mandate is invalid', 'field' => 'mandateId']]]);
    expect(fn () => $invalid->adapter->chargeSavedCard(mollieInvoice(), 'attempt_1'))->toThrow(ClientFence\InformationException::class, 'The mandate is invalid');

    $down = mollieWorld(['POST /v2/payments' => [503, ['status' => 503, 'title' => 'Service Unavailable', 'detail' => 'Maintenance']]]);
    expect(fn () => $down->adapter->chargeSavedCard(mollieInvoice(), 'attempt_1'))->toThrow(Payment_Exception::class, 'HTTP 503');

    $silent = mollieWorld(['POST /v2/payments' => fn () => throw new RuntimeException('timeout')]);
    expect(fn () => $silent->adapter->chargeSavedCard(mollieInvoice(), 'attempt_1'))->toThrow(Payment_Exception::class);
});

test('a pending SEPA charge leaves the invoice unpaid and blocks a second charge; paid later it counts once, failed it emails the client', function (string $later, bool $applied, string $rowStatus, int $emails): void {
    $status = 'pending';
    $world = mollieWorld(['GET /v2/payments/tr_sepa' => function () use (&$status): array {
        return molliePayment('charge', $status, ['id' => 'tr_sepa', 'method' => 'directdebit']);
    }]);
    $world->services->allows('validatePaymentAmount');
    $world->services->allows('isInvoiceTypeDeposit')->andReturn(false);
    $api = Mockery::mock();
    $api->shouldReceive('client_balance_add_funds')->times($applied ? 1 : 0);
    $api->shouldReceive('invoice_pay_with_credits')->times($applied ? 1 : 0);
    $world->db->insert('`transaction`', ['id' => 50, 'gateway_id' => 3, 'invoice_id' => 5, 'status' => 'received', 'created_at' => date('Y-m-d H:i:s')]);

    $world->adapter->processTransaction($api, 50, ['source' => 'saved_card', 'get' => ['mollie_payment' => 'tr_sepa']], 3);
    expect($world->db->fetchAssociative('SELECT status, txn_status FROM `transaction` WHERE id = 50'))->toBe(['status' => 'received', 'txn_status' => 'processing'])
        ->and(PendingPayments::forInvoice($world->di, 5))->not->toBeNull()
        ->and(SavedCards::charge($world->di, mollieInvoice(), $world->adapter, false))->toBe(['paid' => false, 'error' => 'A payment for this invoice is still clearing.']);

    // A webhook while it is still pending adds no second waiting row.
    $world->db->insert('`transaction`', ['id' => 52, 'gateway_id' => 3, 'invoice_id' => 5, 'status' => 'received', 'created_at' => date('Y-m-d H:i:s')]);
    $world->adapter->processTransaction($api, 52, ['source' => 'ipn', 'post' => ['id' => 'tr_sepa']], 3);
    expect($world->db->fetchOne("SELECT COUNT(*) FROM `transaction` WHERE txn_status = 'processing'"))->toBe(1)
        ->and($world->db->fetchOne('SELECT status FROM `transaction` WHERE id = 52'))->toBe('processed');

    // Mollie's webhook when the debit settles.
    $status = $later;
    $world->db->insert('`transaction`', ['id' => 51, 'gateway_id' => 3, 'invoice_id' => 5, 'status' => 'received', 'created_at' => date('Y-m-d H:i:s')]);
    $world->adapter->processTransaction($api, 51, ['source' => 'ipn', 'post' => ['id' => 'tr_sepa']], 3);
    expect($world->db->fetchOne('SELECT status FROM `transaction` WHERE id = 50'))->toBe($rowStatus)
        ->and($world->db->fetchOne('SELECT note FROM `transaction` WHERE id = 51'))->toBe('Payment tr_sepa: transaction #50.')
        ->and($world->emails)->toHaveCount($emails);
})->with([
    'paid' => ['paid', true, 'processed', 0],
    'failed' => ['failed', false, 'error', 1],
]);

test('refunds in the currency\'s decimals', function (): void {
    $world = mollieWorld(['POST /v2/payments/tr_abc/refunds' => [201, ['id' => 're_1', 'status' => 'pending']]]);
    expect($world->adapter->refundPayment('tr_abc', 5.25, 'EUR'))->toBe('re_1')
        ->and($world->calls[0][1])->toBe(['amount' => ['currency' => 'EUR', 'value' => '5.25']]);
    $world->adapter->refundPayment('tr_abc', 500.0, 'JPY');
    expect($world->calls[1][1]['amount']['value'])->toBe('500');

    $refused = mollieWorld(['POST /v2/payments/tr_abc/refunds' => [422, ['status' => 422, 'title' => 'Unprocessable Entity', 'detail' => 'The refund amount is higher than the remaining amount']]]);
    expect(fn () => $refused->adapter->refundPayment('tr_abc', 50.0, 'EUR'))->toThrow(ClientFence\InformationException::class, 'remaining amount');
});

test('with saved payment methods off, Mollie only takes one-time payments and never charges a saved method', function (): void {
    $world = mollieWorld(['POST /v2/payments' => mollieCreated()], ['saved_methods' => '0']);
    $gateway = mollieModel(Model_PayGateway::class, ['id' => 3, 'gateway' => 'Mollie']);
    $world->di['db']->allows('findOne')->andReturnUsing(fn (string $type, string $where, array $params): ?Model_PayGateway => $params[0] === 'Mollie' ? $gateway : null);
    $world->services->allows('getPaymentAdapter')->andReturn($world->adapter);

    expect(SavedCards::mollie($world->di))->toBeNull()
        ->and(SavedCards::adapters($world->di))->toBe([])
        ->and(SavedCards::mollie($world->di, false))->toBe($world->adapter);

    $world->adapter->checkout(mollieInvoice(), false);
    expect($world->calls[0][1]['metadata']['clientfence'])->toBe('pay')
        ->and($world->calls[0][1])->not->toHaveKey('sequenceType')->not->toHaveKey('customerId');
});

test('a stale pending answer never turns a recorded payment back into a pending one', function (): void {
    $world = mollieWorld(['GET /v2/payments/tr_sepa' => molliePayment('charge', 'pending', ['id' => 'tr_sepa', 'method' => 'directdebit'])]);
    $world->db->insert('`transaction`', ['id' => 50, 'gateway_id' => 3, 'invoice_id' => 5, 'txn_id' => 'tr_sepa', 'type' => 'mollie', 'status' => 'processed', 'txn_status' => 'Completed', 'created_at' => date('Y-m-d H:i:s')]);
    $world->adapter->processTransaction(Mockery::mock(), 50, ['source' => 'saved_card', 'get' => ['mollie_payment' => 'tr_sepa']], 3);

    expect($world->db->fetchAssociative('SELECT status, txn_status FROM `transaction` WHERE id = 50'))->toBe(['status' => 'processed', 'txn_status' => 'Completed']);
});
