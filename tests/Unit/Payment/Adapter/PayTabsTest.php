<?php

declare(strict_types=1);

/**
 * ClientFence: PayTabs (Payment_Adapter_PayTabs): payment pages, saved cards charged as recurring payments, payments read
 * back from PayTabs. PayTabs' replies come from a mock HTTP client (path => reply), the tables from in-memory SQLite.
 */

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function Tests\Helpers\container;

/** A model of $class with $values. */
function paytabsModel(string $class, array $values): object
{
    $model = new $class();
    $model->loadBean(new Tests\Helpers\DummyBean());
    foreach ($values as $key => $value) {
        $model->{$key} = $value;
    }

    return $model;
}

function paytabsInvoice(array $values = []): Model_Invoice
{
    return paytabsModel(Model_Invoice::class, $values + ['id' => 5, 'client_id' => 7, 'currency' => 'USD', 'hash' => 'abc', 'status' => 'unpaid', 'approved' => 1]);
}

/**
 * An adapter (gateway 3, test mode, Egypt) whose API calls get $replies (path => reply, or a callable of the request body;
 * an error reply is [status, body]), recorded in $world->calls as [path, body]. The client (#7) has the saved card
 * "TOK1 TST1". Exchange rates: the default currency is USD, 1 USD = 50 EGP.
 */
function paytabsWorld(array $replies, array $config = []): object
{
    $world = new stdClass();
    $world->calls = [];
    $http = new MockHttpClient(function (string $method, string $url, array $options) use ($replies, $world): MockResponse {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $body = json_decode((string) $options['body'], true);
        $world->calls[] = [$path, $body];
        $reply = $replies[$path] ?? [400, ['code' => 999, 'message' => 'Unexpected']];
        $reply = is_callable($reply) ? $reply($body) : $reply;
        [$status, $reply] = isset($reply[0], $reply[1]) && is_int($reply[0]) ? $reply : [200, $reply];

        return new MockResponse((string) json_encode($reply), ['http_code' => $status]);
    });
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE pay_gateway_customer (id INTEGER PRIMARY KEY, pay_gateway_id INTEGER, client_id INTEGER, test_mode INTEGER, external_customer_id TEXT, saved_cards INTEGER DEFAULT 0, default_payment_method TEXT, default_card TEXT, auto_pay_at TEXT, created_at TEXT, updated_at TEXT)');
    $db->executeStatement('CREATE TABLE `transaction` (id INTEGER PRIMARY KEY, invoice_id INTEGER, gateway_id INTEGER, txn_id TEXT, txn_status TEXT, amount TEXT, currency TEXT, type TEXT, status TEXT, error TEXT, error_code INTEGER, note TEXT, created_at TEXT, updated_at TEXT)');
    $db->insert('pay_gateway_customer', ['pay_gateway_id' => 3, 'client_id' => 7, 'test_mode' => 1, 'external_customer_id' => '', 'saved_cards' => 1, 'default_payment_method' => 'TOK1 TST1', 'default_card' => 'Visa •••• 1111 (12/2028)', 'auto_pay_at' => '2026-11-01 00:00:00']);
    $world->db = $db;

    $invoices = Mockery::mock();
    $invoices->allows('getBalanceDue')->andReturn(12.5);
    $invoices->allows('toApiArray')->andReturn(['serie_nr' => 'INV-00005']);
    $world->invoices = $invoices;
    $di = container();
    $di['dbal'] = $db;
    $di['http_client'] = $http;
    $di['logger'] = new class {
        public function __call(string $name, array $arguments): mixed
        {
            return $this;
        }
    };
    $di['mod_service'] = $di->protect(fn (string $module, string $sub = ''): object => match ($module) {
        'system' => new class {
            public function getParamValue(string $key, mixed $default = null): string
            {
                return 'Example Hosting';
            }
        },
        'Currency' => new class {
            public function getCurrencyByClientId(int $clientId): object
            {
                return new class {
                    public function getCode(): string
                    {
                        return 'USD';
                    }
                };
            }

            public function getBaseCurrencyRate(string $code): float
            {
                return ['USD' => 1.0, 'EGP' => 0.02][$code] ?? throw new ClientFence\Exception('Currency not found.');
            }

            public function toBaseCurrency(string $code, float $amount): float
            {
                return $amount * $this->getBaseCurrencyRate($code);
            }
        },
        default => $invoices,
    });
    $di['db'] = Mockery::mock();
    $di['db']->allows('getExistingModelById')->andReturnUsing(fn (string $type, int $id): object => $type === 'Client'
        ? paytabsModel(Model_Client::class, ['id' => $id, 'first_name' => 'Test', 'last_name' => 'Client', 'email' => 'test@example.com', 'country' => 'EG', 'city' => 'Cairo'])
        : paytabsInvoice(['id' => $id]));
    $di['url'] = new class {
        public function link(string $path, array $params = []): string
        {
            return 'https://portal.example/' . $path . ($params === [] ? '' : '?' . http_build_query($params));
        }
    };
    $world->di = $di;
    $world->adapter = new Payment_Adapter_PayTabs(['gateway_id' => 3, 'test_mode' => true, 'region' => 'EGY', 'test_profile_id' => '142064', 'test_server_key' => 'SKEY'] + $config);
    $world->adapter->setDi($di);

    return $world;
}

function paytabsSaved(Connection $db, int $clientId = 7): ?string
{
    $value = $db->fetchOne('SELECT default_payment_method FROM pay_gateway_customer WHERE pay_gateway_id = 3 AND client_id = ?', [$clientId]);

    return $value === false ? null : $value;
}

/** PayTabs' answer to a payment query. */
function paytabsPayment(string $cartId, string $status = 'A', array $more = []): array
{
    return $more + ['tran_ref' => 'TST9', 'tran_type' => 'Sale', 'cart_id' => $cartId, 'tran_total' => '12.50', 'tran_currency' => 'USD',
        'payment_result' => ['response_status' => $status, 'response_message' => $status === 'A' ? 'Authorised' : 'Held'],
        'payment_info' => ['card_scheme' => 'Visa', 'payment_description' => '4111 11## #### 4242', 'expiryMonth' => 3, 'expiryYear' => 2029]];
}

test('needs the profile ID and server key of the current mode, and checks them with PayTabs', function (): void {
    expect(fn () => new Payment_Adapter_PayTabs(['test_mode' => false, 'test_profile_id' => '1', 'test_server_key' => 'k']))->toThrow(Payment_Exception::class);

    $ok = paytabsWorld(['/payment/query' => [400, ['code' => 113, 'message' => 'Transaction not found']]]);
    $ok->adapter->testConnection();
    expect($ok->calls[0][1])->toMatchArray(['profile_id' => 142064]);

    $wrong = paytabsWorld(['/payment/query' => [401, ['code' => 1, 'message' => 'Key/Profile not found']]]);
    expect(fn () => $wrong->adapter->testConnection())->toThrow(ClientFence\InformationException::class, 'Key/Profile not found (1)');
});

test('charges the saved card as a recurring payment, and removes a card PayTabs no longer knows', function (): void {
    $world = paytabsWorld(['/payment/request' => ['tran_ref' => 'TST2', 'payment_result' => ['response_status' => 'A']]]);
    expect($world->adapter->chargeSavedCard(paytabsInvoice(), 'attempt_1'))->toBe('TST2')
        ->and($world->calls[0])->toBe(['/payment/request', [
            'profile_id' => 142064, 'cart_id' => 'cf-invoice-5-attempt_1', 'cart_description' => 'Invoice INV-00005', 'cart_currency' => 'USD', 'cart_amount' => 12.5,
            'tran_type' => 'sale', 'tran_class' => 'recurring', 'token' => 'TOK1', 'tran_ref' => 'TST1',
        ]]);

    $declined = paytabsWorld(['/payment/request' => ['tran_ref' => 'TST3', 'payment_result' => ['response_status' => 'D', 'response_code' => '4', 'response_message' => 'Declined']]]);
    expect(fn () => $declined->adapter->chargeSavedCard(paytabsInvoice(), 'attempt_1'))->toThrow(ClientFence\InformationException::class, 'PayTabs refused the payment: Declined (4)');
    expect(paytabsSaved($declined->db))->toBe('TOK1 TST1');

    $confirm = paytabsWorld(['/payment/request' => ['tran_ref' => 'TST4', 'redirect_url' => 'https://secure-egypt.paytabs.com/payment/page/X']]);
    expect(fn () => $confirm->adapter->chargeSavedCard(paytabsInvoice(), 'attempt_1'))->toThrow(ClientFence\InformationException::class, 'asks the cardholder to confirm');

    $gone = paytabsWorld(['/payment/request' => [400, ['code' => 209, 'message' => 'Invalid token']]]);
    expect(fn () => $gone->adapter->chargeSavedCard(paytabsInvoice(), 'attempt_1'))->toThrow(ClientFence\InformationException::class);
    expect(paytabsSaved($gone->db))->toBeNull();

    $silent = paytabsWorld(['/payment/request' => fn () => throw new RuntimeException('timeout')]);
    expect(fn () => $silent->adapter->chargeSavedCard(paytabsInvoice(), 'attempt_1'))->toThrow(Payment_Exception::class);
});

test('opens a payment page that saves the card for the owner and one that only pays', function (): void {
    $world = paytabsWorld(['/payment/request' => ['tran_ref' => 'TST5', 'redirect_url' => 'https://secure-egypt.paytabs.com/payment/page/P']]);
    expect($world->adapter->checkout(paytabsInvoice(), true))->toBe('https://secure-egypt.paytabs.com/payment/page/P');
    $save = $world->calls[0][1];
    expect($save)->toMatchArray(['tran_type' => 'sale', 'tran_class' => 'ecom', 'tokenise' => 2, 'cart_amount' => 12.5])
        ->and($save['cart_id'])->toStartWith('cf-invoice-5-save')
        ->and($save['customer_details'])->toMatchArray(['name' => 'Test Client', 'email' => 'test@example.com', 'country' => 'EG'])
        ->and($save['return'])->toBe('https://portal.example/invoice/paytabs-paid?cart=' . $save['cart_id'] . '&hash=abc')
        ->and($save['callback'])->toEndWith('ipn.php?gateway_id=3&invoice_id=5');

    $world->adapter->checkout(paytabsInvoice(), false);
    expect($world->calls[1][1])->not->toHaveKey('tokenise')
        ->and($world->calls[1][1]['cart_id'])->toStartWith('cf-invoice-5-pay');
});

test('saves a card checked without a charge for the client its cart names, and deletes the card it replaces', function (): void {
    $world = paytabsWorld([
        '/payment/request' => ['tran_ref' => 'TST6', 'redirect_url' => 'https://secure-egypt.paytabs.com/payment/page/R'],
        '/payment/query' => paytabsPayment('cf-client-7-save' . time() . '-0123456789abcdef', 'A', ['tran_ref' => 'TST6', 'tran_type' => 'Register', 'token' => 'TOK2']),
        '/payment/token/delete' => ['status' => 'success'],
    ]);
    $world->adapter->cardSetup(7, 'https://portal.example/payment-methods/paytabs/saved');
    expect($world->calls[0][1])->toMatchArray(['tran_type' => 'register', 'cart_amount' => 0, 'tokenise' => 2])
        ->and($world->calls[0][1]['cart_id'])->toMatch('/^cf-client-7-save\d+-[0-9a-f]{16}$/')
        ->and($world->calls[0][1]['return'])->toBe('https://portal.example/payment-methods/paytabs/saved?cart=' . $world->calls[0][1]['cart_id']);

    expect($world->adapter->cardSaved('TST6'))->toBe(7)
        ->and(paytabsSaved($world->db))->toBe('TOK2 TST6')
        ->and($world->db->fetchOne('SELECT default_card FROM pay_gateway_customer WHERE client_id = 7'))->toBe('Visa •••• 4242 (03/2029)')
        ->and(end($world->calls))->toBe(['/payment/token/delete', ['profile_id' => 142064, 'token' => 'TOK1']]);

    $declined = paytabsWorld(['/payment/query' => paytabsPayment('cf-client-7-save' . time() . '-0123456789abcdef', 'D', ['tran_ref' => 'TST6'])]);
    expect(fn () => $declined->adapter->cardSaved('TST6'))->toThrow(ClientFence\InformationException::class, 'PayTabs did not save the card');

    // A check from more than an hour ago sent again never brings back a card.
    $old = paytabsWorld(['/payment/query' => paytabsPayment('cf-client-7-save' . (time() - 7200) . '-0123456789abcdef', 'A', ['tran_ref' => 'TST6', 'token' => 'TOK2'])]);
    expect(fn () => $old->adapter->cardSaved('TST6'))->toThrow(ClientFence\InformationException::class);
    expect(paytabsSaved($old->db))->toBe('TOK1 TST1');
});

test('records a payment read back from PayTabs on its invoice once, keeping the card only from a page that saves it', function (string $cartId, string $status, array $more, ?string $error, string $rowStatus, bool $applied, string $card): void {
    $world = paytabsWorld(['/payment/query' => paytabsPayment($cartId, $status, $more)]);
    $world->db->insert('`transaction`', ['id' => 40, 'gateway_id' => 3, 'status' => 'received']);
    $world->invoices->allows('validatePaymentAmount');
    $world->invoices->allows('isInvoiceTypeDeposit')->andReturn(false);
    $api = Mockery::mock();
    $api->shouldReceive('client_balance_add_funds')->times($applied ? 1 : 0)->with(Mockery::subset(['id' => 7, 'amount' => 12.5, 'rel_id' => 'TST9']));
    $api->shouldReceive('invoice_pay_with_credits')->times($applied ? 1 : 0)->with(['id' => 5]);

    $run = fn () => $world->adapter->processTransaction($api, 40, ['source' => 'ipn', 'post' => ['tranRef' => 'TST9']], 3);
    $error === null ? $run() : expect($run)->toThrow(Payment_Exception::class, $error);
    expect($world->db->fetchOne('SELECT status FROM `transaction` WHERE id = 40'))->toBe($rowStatus)
        ->and(paytabsSaved($world->db))->toBe($card);
})->with([
    'paid and saved' => ['cf-invoice-5-save1700000000', 'A', ['token' => 'TOK3'], null, 'processed', true, 'TOK3 TST9'],
    'paid once' => ['cf-invoice-5-pay1700000000', 'A', ['token' => 'TOK3'], null, 'processed', true, 'TOK1 TST1'],
    'held by PayTabs' => ['cf-invoice-5-attempt_1', 'H', [], null, 'received', false, 'TOK1 TST1'],
    'declined' => ['cf-invoice-5-pay1700000000', 'D', [], null, 'error', false, 'TOK1 TST1'],
    'another shop\'s payment' => ['77', 'A', [], 'is not for a ClientFence invoice', 'received', false, 'TOK1 TST1'],
]);

test('PayTabs\' callback names the payment in JSON, and a payment recorded already is not credited again', function (): void {
    $world = paytabsWorld(['/payment/query' => paytabsPayment('cf-invoice-5-attempt_1')]);
    // Recorded by another PayTabs gateway on the same profile: still not credited twice.
    $world->db->insert('`transaction`', ['id' => 45, 'gateway_id' => 2, 'invoice_id' => 5, 'txn_id' => 'TST9', 'status' => 'processed', 'txn_status' => 'Completed']);
    $world->db->insert('`transaction`', ['id' => 46, 'gateway_id' => 3, 'status' => 'received']);
    $world->adapter->processTransaction(Mockery::mock(), 46, ['source' => 'ipn', 'http_raw_post_data' => json_encode(['tran_ref' => 'TST9'])], 3);
    expect($world->calls[0])->toBe(['/payment/query', ['profile_id' => 142064, 'tran_ref' => 'TST9']])
        ->and($world->db->fetchOne('SELECT note FROM `transaction` WHERE id = 46'))->toBe('Payment TST9 was already recorded on transaction #45.');

    expect(fn () => $world->adapter->processTransaction(Mockery::mock(), 46, ['source' => 'ipn', 'post' => []], 3))->toThrow(Payment_Exception::class, 'names no payment');

    // A "save" payment's notice sent again after the client removed the card does not save it again.
    $saved = paytabsWorld(['/payment/query' => paytabsPayment('cf-invoice-5-save1700000000', 'A', ['token' => 'TOK3'])]);
    $saved->db->executeStatement('UPDATE pay_gateway_customer SET default_payment_method = NULL');
    $saved->db->insert('`transaction`', ['id' => 45, 'gateway_id' => 3, 'invoice_id' => 5, 'txn_id' => 'TST9', 'status' => 'processed', 'txn_status' => 'Completed']);
    $saved->db->insert('`transaction`', ['id' => 47, 'gateway_id' => 3, 'status' => 'received']);
    $saved->adapter->processTransaction(Mockery::mock(), 47, ['source' => 'ipn', 'post' => ['tranRef' => 'TST9']], 3);
    expect(paytabsSaved($saved->db))->toBeNull();
});

test('a return without a payment reference is looked up by its cart, and a held payment is settled on the row that waited', function (): void {
    $world = paytabsWorld(['/payment/query' => fn (array $body): array => isset($body['cart_id'])
        ? [paytabsPayment('cf-invoice-5-pay1700000000', 'D', ['tran_ref' => 'TST8']), paytabsPayment('cf-invoice-5-pay1700000000')]
        : paytabsPayment('cf-invoice-5-pay1700000000')]);
    $world->db->insert('`transaction`', ['id' => 50, 'gateway_id' => 3, 'invoice_id' => 5, 'txn_id' => 'TST9', 'status' => 'received', 'txn_status' => 'processing']);
    $world->db->insert('`transaction`', ['id' => 51, 'gateway_id' => 3, 'status' => 'received']);
    $world->invoices->allows('validatePaymentAmount');
    $world->invoices->allows('isInvoiceTypeDeposit')->andReturn(false);
    $api = Mockery::mock();
    $api->shouldReceive('client_balance_add_funds')->once();
    $atPay = null;
    $api->shouldReceive('invoice_pay_with_credits')->once()->andReturnUsing(function () use ($world, &$atPay): void {
        $atPay = $world->db->fetchOne('SELECT txn_status FROM `transaction` WHERE id = 50');
    });
    $world->adapter->processTransaction($api, 51, ['source' => 'paytabs_return', 'get' => ['cart' => 'cf-invoice-5-pay1700000000']], 3);
    expect($world->calls[0][1])->toMatchArray(['cart_id' => 'cf-invoice-5-pay1700000000'])
        ->and($atPay)->toBe('Completed')
        ->and($world->db->fetchAssociative('SELECT status, txn_status, amount FROM `transaction` WHERE id = 50'))->toBe(['status' => 'processed', 'txn_status' => 'Completed', 'amount' => '12.5'])
        ->and($world->db->fetchAssociative('SELECT status, amount, note FROM `transaction` WHERE id = 51'))->toBe(['status' => 'processed', 'amount' => null, 'note' => 'Payment TST9 settled on transaction #50.']);

    // A held payment PayTabs then declines ends the wait of its row.
    $declined = paytabsWorld(['/payment/query' => paytabsPayment('cf-invoice-5-attempt_1', 'D')]);
    $declined->db->insert('`transaction`', ['id' => 50, 'gateway_id' => 3, 'invoice_id' => 5, 'txn_id' => 'TST9', 'status' => 'received', 'txn_status' => 'processing']);
    $declined->db->insert('`transaction`', ['id' => 51, 'gateway_id' => 3, 'status' => 'received']);
    $declined->adapter->processTransaction(Mockery::mock(), 51, ['source' => 'ipn', 'post' => ['tranRef' => 'TST9']], 3);
    expect($declined->db->fetchAssociative('SELECT status, txn_status FROM `transaction` WHERE id = 50'))->toBe(['status' => 'error', 'txn_status' => 'D']);
});

test('converts invoices to the processing currency as WHMCS: charge, payment and refund', function (): void {
    $payment = paytabsPayment('cf-invoice-5-attempt_1', 'A', ['tran_total' => '625.00', 'tran_currency' => 'EGP', 'user_defined' => ['udf1' => '12.50']]);
    $world = paytabsWorld([
        '/payment/request' => fn (array $body): array => ($body['tran_type'] ?? '') === 'refund'
            ? ['tran_ref' => 'TSTR', 'payment_result' => ['response_status' => 'A']]
            : ['tran_ref' => 'TST9', 'payment_result' => ['response_status' => 'A']],
        '/payment/query' => $payment,
    ], ['convert_to' => 'egp']);
    $world->adapter->chargeSavedCard(paytabsInvoice(), 'attempt_1');
    expect($world->calls[0][1])->toMatchArray(['cart_currency' => 'EGP', 'cart_amount' => 625.0, 'user_defined' => ['udf1' => '12.50']]);

    // It pays the 12.50 USD asked for, whatever today's rate.
    $world->db->insert('`transaction`', ['id' => 60, 'gateway_id' => 3, 'status' => 'received']);
    $world->invoices->shouldReceive('validatePaymentAmount')->once()->with(12.5, 12.5, Mockery::any());
    $world->invoices->allows('isInvoiceTypeDeposit')->andReturn(false);
    $api = Mockery::mock();
    $api->shouldReceive('client_balance_add_funds')->once()->with(Mockery::subset(['amount' => 12.5]));
    $api->allows('invoice_pay_with_credits');
    $world->adapter->processTransaction($api, 60, ['source' => 'saved_card', 'get' => ['paytabs_tran' => 'TST9']], 3);
    expect($world->db->fetchAssociative('SELECT amount, currency, note, status FROM `transaction` WHERE id = 60'))
        ->toBe(['amount' => '12.5', 'currency' => 'USD', 'note' => 'PayTabs: 625.00 EGP.', 'status' => 'processed']);

    // Refunding 5 of the 12.50 USD sends back the same share of the 625 EGP.
    expect($world->adapter->refundPayment('TST9', 5, 'USD'))->toBe('TSTR')
        ->and(end($world->calls)[1])->toMatchArray(['tran_type' => 'refund', 'tran_ref' => 'TST9', 'cart_currency' => 'EGP', 'cart_amount' => 250.0, 'cart_id' => 'cf-invoice-5-attempt_1']);
});
