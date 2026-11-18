<?php

declare(strict_types=1);

/**
 * ClientFence: CCAvenue (Payment_Adapter_CCAvenue): the billing page form, the encrypted return and the Status API
 * read-back. CCAvenue's API replies come from a mock HTTP client, the tables from in-memory SQLite. The encryption is
 * checked against the integration kits' formula, computed here on its own, and a fixed vector.
 */

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function Tests\Helpers\container;

const CCAVENUE_KEY = 'ABCDEF0123456789';

/** The kits' formula: AES-128-CBC, key = MD5 of the working key (raw), IV bytes 00..0f, hex. */
function ccavenueEncrypt(string $plain, string $key = CCAVENUE_KEY): string
{
    return bin2hex(openssl_encrypt($plain, 'aes-128-cbc', md5($key, true), OPENSSL_RAW_DATA, "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f"));
}

function ccavenueDecrypt(string $hex): string
{
    return (string) openssl_decrypt((string) hex2bin($hex), 'aes-128-cbc', md5(CCAVENUE_KEY, true), OPENSSL_RAW_DATA, "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f");
}

function ccavenueOrderId(int $invoiceId = 5): string
{
    // The tag is HMAC-SHA256 of cf<invoice>-<time> under the working key and this install, first 8 hex digits.
    return 'cf' . $invoiceId . '-1700000000-' . substr(hash_hmac('sha256', 'cf' . $invoiceId . '-1700000000', CCAVENUE_KEY . '|' . INSTANCE_ID), 0, 8);
}

/** CCAvenue's encrypted return as posted to ipn.php. */
function ccavenueReturn(string $orderId, string $status = 'Success', string $trackingId = '204000163469'): array
{
    return ['source' => 'ipn', 'post' => ['encResp' => ccavenueEncrypt(http_build_query(['order_id' => $orderId, 'tracking_id' => $trackingId, 'order_status' => $status, 'amount' => '12.50', 'currency' => 'INR'])), 'orderNo' => $orderId]];
}

/** The Status API's order (the guide's JSON example fields). */
function ccavenueOrder(string $orderId, array $more = []): array
{
    return $more + ['reference_no' => '204000163469', 'order_no' => $orderId, 'order_currncy' => 'INR', 'order_amt' => 12.5, 'order_status' => 'Successful', 'order_bank_response' => 'Transaction Successful', 'error_desc' => '', 'status' => 0, 'error_code' => ''];
}

/**
 * An adapter (gateway 3, test mode) whose Status API calls get $order (encrypted, or a raw reply body when a string),
 * recorded in $world->calls as [host, form fields]. Invoice #5 of client #7 is in INR with 12.50 due.
 */
function ccavenueWorld(array|string $order = []): object
{
    $world = new stdClass();
    $world->calls = [];
    $http = new MockHttpClient(function (string $method, string $url, array $options) use ($order, $world): MockResponse {
        parse_str((string) $options['body'], $fields);
        $world->calls[] = [parse_url($url, PHP_URL_HOST) . parse_url($url, PHP_URL_PATH), $fields];

        return new MockResponse(is_string($order) ? $order : 'status=0&enc_response=' . strtoupper(ccavenueEncrypt((string) json_encode($order))) . '&enc_error_code=');
    });
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE `transaction` (id INTEGER PRIMARY KEY, invoice_id INTEGER, gateway_id INTEGER, txn_id TEXT, txn_status TEXT, amount TEXT, currency TEXT, type TEXT, status TEXT, error TEXT, error_code INTEGER, note TEXT, created_at TEXT, updated_at TEXT)');
    $db->executeStatement('CREATE TABLE client_balance (id INTEGER PRIMARY KEY, client_id INTEGER, type TEXT, rel_id TEXT, amount REAL)');
    $db->insert('`transaction`', ['id' => 40, 'gateway_id' => 3, 'status' => 'received']);
    $world->db = $db;

    $invoices = Mockery::mock();
    $invoices->allows('getBalanceDue')->andReturn(12.5);
    $invoices->allows('isInvoiceTypeDeposit')->andReturn(false);
    $invoices->allows('validatePaymentAmount')->andReturnUsing(function (float $received, float $expected): void {
        if ($received < $expected - 0.01) {
            throw new ClientFence\Exception('Payment amount does not match the expected invoice total.');
        }
    });
    $di = container();
    $di['dbal'] = $db;
    $di['http_client'] = $http;
    $di['mod_service'] = $di->protect(fn (): object => $invoices);
    $di['db'] = Mockery::mock();
    $di['db']->allows('getExistingModelById')->andReturnUsing(function (string $type, int $id): Model_Invoice {
        $invoice = new Model_Invoice();
        $invoice->loadBean(new Tests\Helpers\DummyBean());
        foreach (['id' => $id, 'client_id' => 7, 'currency' => 'INR', 'hash' => 'abc', 'approved' => 1] as $key => $value) {
            $invoice->{$key} = $value;
        }

        return $invoice;
    });
    $di['url'] = new class {
        public function link(string $path): string
        {
            return 'https://portal.example/' . $path;
        }
    };
    $world->adapter = new Payment_Adapter_CCAvenue(['gateway_id' => 3, 'test_mode' => true, 'merchant_id' => '2193', 'test_access_code' => 'AVTEST00AA11BB22', 'test_working_key' => CCAVENUE_KEY]);
    $world->adapter->setDi($di);

    return $world;
}

/** Processes transaction #40 with $data, crediting the client when $paid. */
function ccavenueProcess(object $world, array $data, bool $paid): void
{
    $api = Mockery::mock();
    $api->shouldReceive('client_balance_add_funds')->times($paid ? 1 : 0)->with(Mockery::subset(['id' => 7, 'amount' => 12.5, 'type' => 'CCAvenue', 'rel_id' => '204000163469']));
    $api->shouldReceive('invoice_pay_with_credits')->times($paid ? 1 : 0)->with(['id' => 5]);
    $world->adapter->processTransaction($api, 40, $data, 3);
}

test('needs the merchant ID and the access code and working key of the current mode', function (): void {
    expect(fn () => new Payment_Adapter_CCAvenue(['test_mode' => false, 'merchant_id' => '1', 'test_access_code' => 'a', 'test_working_key' => 'k']))->toThrow(Payment_Exception::class);
    expect(fn () => new Payment_Adapter_CCAvenue(['test_mode' => true, 'test_access_code' => 'a', 'test_working_key' => 'k']))->toThrow(Payment_Exception::class);
    expect(Payment_Adapter_CCAvenue::getConfig())->toMatchArray(['supports_one_time_payments' => true, 'supports_subscriptions' => false]);
});

test('encrypts like the integration kits: a fixed vector', function (): void {
    // Computed apart with the openssl command line: -aes-128-cbc -K md5(key) -iv 000102030405060708090a0b0c0d0e0f.
    expect(md5(CCAVENUE_KEY))->toBe('e3151df25557a98e7d9db2ed05af2cae')
        ->and(ccavenueEncrypt('order_id=cf5&amount=1.00'))->toBe('3afc77e61ce14aa187cf25d007bb352fbd13985eb7e2c75bc414770b9b14ff8e');
});

test('posts the invoice balance, encrypted with the working key, to the test billing page', function (): void {
    $html = ccavenueWorld()->adapter->getHtml(null, 5, false);
    expect($html)->toContain('action="https://test.ccavenue.com/transaction/transaction.do?command=initiateTransaction"')
        ->and($html)->toContain('name="access_code" value="AVTEST00AA11BB22"');
    preg_match('/name="encRequest" value="([0-9a-f]+)"/', $html, $match);
    $plain = ccavenueDecrypt($match[1]);
    // The kits' way: values unencoded, so the return address is short and holds no & or =.
    expect($plain)->toContain('&redirect_url=https://portal.example/invoice/ccavenue-paid/3&');
    parse_str($plain, $order);
    expect($order)->toMatchArray([
        'merchant_id' => '2193',
        'currency' => 'INR',
        'amount' => '12.50',
        'redirect_url' => 'https://portal.example/invoice/ccavenue-paid/3',
        'cancel_url' => 'https://portal.example/invoice/ccavenue-paid/3',
        'language' => 'EN',
    ])->and($order['order_id'])->toMatch('/^cf5-\d+-[0-9a-f]{8}$/')
        ->and(strlen($order['order_id']))->toBeLessThanOrEqual(30);
});

test('pays the invoice only as the Status API reports the order', function (array $order, array $return, ?string $error, string $rowStatus, bool $paid): void {
    $world = ccavenueWorld($order);
    $run = fn () => ccavenueProcess($world, $return, $paid);
    $error === null ? $run() : expect($run)->toThrow(Payment_Exception::class, $error);
    expect($world->db->fetchOne('SELECT status FROM `transaction` WHERE id = 40'))->toBe($rowStatus);
})->with([
    'paid' => [ccavenueOrder(ccavenueOrderId()), ccavenueReturn(ccavenueOrderId()), null, 'processed', true],
    'paid and confirmed (Shipped)' => [ccavenueOrder(ccavenueOrderId(), ['order_status' => 'Shipped']), ccavenueReturn(ccavenueOrderId()), null, 'processed', true],
    'paid, reply wrapped' => [['Order_Status_Result' => ccavenueOrder(ccavenueOrderId())], ccavenueReturn(ccavenueOrderId()), null, 'processed', true],
    'the return says Success, CCAvenue does not' => [ccavenueOrder(ccavenueOrderId(), ['order_status' => 'Unsuccessful']), ccavenueReturn(ccavenueOrderId()), null, 'error', false],
    'cancelled' => [ccavenueOrder(ccavenueOrderId(), ['order_status' => 'Aborted']), ccavenueReturn(ccavenueOrderId(), 'Aborted'), null, 'error', false],
    'awaited (read back by the cron)' => [ccavenueOrder(ccavenueOrderId(), ['order_status' => 'Awaited']), ccavenueReturn(ccavenueOrderId(), 'Awaited'), null, 'received', false],
    'another currency' => [ccavenueOrder(ccavenueOrderId(), ['order_currncy' => 'USD']), ccavenueReturn(ccavenueOrderId()), 'is in USD', 'received', false],
    'another order read back' => [ccavenueOrder(ccavenueOrderId(6)), ccavenueReturn(ccavenueOrderId()), 'is not for a ClientFence invoice', 'received', false],
    'another portal\'s or an altered order' => [ccavenueOrder('cf5-1700000000-00000000'), ccavenueReturn('cf5-1700000000-00000000'), 'names no payment', 'received', false],
    'an order ID cut to name another invoice' => [ccavenueOrder('cf6' . substr(ccavenueOrderId(), 3)), ccavenueReturn('cf6' . substr(ccavenueOrderId(), 3)), 'names no payment', 'received', false],
]);

test('the return names the invoice of this install\'s order only', function (): void {
    $adapter = ccavenueWorld()->adapter;
    expect($adapter->returnedOrder(ccavenueReturn(ccavenueOrderId())['post']['encResp']))->toBe([5, '204000163469'])
        ->and($adapter->returnedOrder(ccavenueReturn('cf5-1700000000-00000000')['post']['encResp']))->toBeNull()
        ->and($adapter->returnedOrder('zz'))->toBeNull();
});

test('a lower amount than due is refused', function (): void {
    $world = ccavenueWorld(ccavenueOrder(ccavenueOrderId(), ['order_amt' => 1.0]));
    expect(fn () => $world->adapter->processTransaction(Mockery::mock(), 40, ccavenueReturn(ccavenueOrderId()), 3))->toThrow(ClientFence\Exception::class, 'does not match');
    expect($world->db->fetchOne('SELECT status FROM `transaction` WHERE id = 40'))->toBe('received');
});

test('reads the order back with orderStatusTracker on the test API', function (): void {
    $world = ccavenueWorld(ccavenueOrder(ccavenueOrderId()));
    ccavenueProcess($world, ccavenueReturn(ccavenueOrderId()), true);
    [$endpoint, $fields] = $world->calls[0];
    expect($endpoint)->toBe('apitest.ccavenue.com/apis/servlet/DoWebTrans')
        ->and($fields)->toMatchArray(['access_code' => 'AVTEST00AA11BB22', 'command' => 'orderStatusTracker', 'request_type' => 'JSON', 'response_type' => 'JSON', 'version' => '1.2'])
        ->and(json_decode(ccavenueDecrypt($fields['enc_request']), true))->toBe(['reference_no' => '204000163469', 'order_no' => ccavenueOrderId()]);

    $refused = ccavenueWorld('status=1&enc_response=Access_code: Invalid Parameter&enc_error_code=51407');
    expect(fn () => $refused->adapter->processTransaction(Mockery::mock(), 40, ccavenueReturn(ccavenueOrderId()), 3))->toThrow(Payment_Exception::class, 'Access_code: Invalid Parameter 51407');
});

test('a tampered return or one encrypted with another key is refused without asking CCAvenue', function (string $encResp): void {
    $world = ccavenueWorld(ccavenueOrder(ccavenueOrderId()));
    expect(fn () => $world->adapter->processTransaction(Mockery::mock(), 40, ['source' => 'ipn', 'post' => ['encResp' => $encResp]], 3))->toThrow(Payment_Exception::class, 'names no payment')
        ->and($world->calls)->toBe([]);
})->with([
    'another working key' => [ccavenueEncrypt('order_id=' . ccavenueOrderId() . '&tracking_id=204000163469', 'FFFFFFFFFFFFFFFF')],
    'not hex' => ['order_id=cf5&tracking_id=1'],
    'none (cancelled before paying)' => [''],
]);

test('a payment recorded already is not credited again', function (): void {
    $world = ccavenueWorld(ccavenueOrder(ccavenueOrderId()));
    $world->db->insert('`transaction`', ['id' => 45, 'gateway_id' => 2, 'invoice_id' => 5, 'txn_id' => '204000163469', 'type' => 'ccavenue', 'status' => 'processed', 'txn_status' => 'Completed']);
    ccavenueProcess($world, ccavenueReturn(ccavenueOrderId()), false);
    expect($world->db->fetchOne('SELECT note FROM `transaction` WHERE id = 40'))->toBe('Payment 204000163469 was already recorded on transaction #45.')
        ->and($world->db->fetchOne('SELECT status FROM `transaction` WHERE id = 40'))->toBe('processed');
});

test('refunds a payment by its tracking ID with refundOrder', function (): void {
    $world = ccavenueWorld(['Refund_Order_Result' => ['refund_status' => 0]]);
    $reference = $world->adapter->refundPayment('204000163469', 5.25, 'INR');
    [$endpoint, $fields] = $world->calls[0];
    expect($reference)->toMatch('/^cf[0-9a-f]{16}$/')
        ->and($endpoint)->toBe('apitest.ccavenue.com/apis/servlet/DoWebTrans')
        ->and($fields)->toMatchArray(['command' => 'refundOrder', 'request_type' => 'JSON', 'response_type' => 'JSON', 'version' => '1.2'])
        ->and(json_decode(ccavenueDecrypt($fields['enc_request']), true))->toBe(['reference_no' => '204000163469', 'refund_amount' => '5.25', 'refund_ref_no' => $reference]);

    $declined = ccavenueWorld(['Refund_Order_Result' => ['refund_status' => 1, 'reason' => 'Refund amount is greater than the balance', 'error_code' => '51419']]);
    expect(fn () => $declined->adapter->refundPayment('204000163469', 500.0, 'INR'))->toThrow(ClientFence\InformationException::class, 'Refund amount is greater than the balance 51419');
    $refused = ccavenueWorld('status=1&enc_response=Access_code: Invalid Parameter&enc_error_code=51407');
    expect(fn () => $refused->adapter->refundPayment('204000163469', 5.25, 'INR'))->toThrow(ClientFence\InformationException::class, 'Access_code: Invalid Parameter 51407')
        ->and(fn () => $refused->adapter->refundPayment('cf5', 5.25, 'INR'))->toThrow(ClientFence\InformationException::class, 'its own payments');
});
