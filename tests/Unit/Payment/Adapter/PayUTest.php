<?php

declare(strict_types=1);

/**
 * ClientFence: PayU India (Payment_Adapter_PayU): the signed payment form, the reverse hash of PayU's posts, payments
 * read back with verify_payment, refunds. The hashes are pinned with PayU's documented formulas (and fixed vectors);
 * PayU's replies come from a mock HTTP client (command => reply), the tables from in-memory SQLite.
 */

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function Tests\Helpers\container;

function payuModel(string $class, array $values): object
{
    $model = new $class();
    $model->loadBean(new Tests\Helpers\DummyBean());
    foreach ($values as $key => $value) {
        $model->{$key} = $value;
    }

    return $model;
}

/**
 * An adapter (gateway 3, test mode, key KEY, salt SALT) whose postservice calls get $replies (command => reply, or a
 * callable of the posted fields), recorded in $world->calls as [posted fields, URL]. Invoice #5 of client #7 is in
 * $currency with 12.50 due.
 */
function payuWorld(array $replies, string $currency = 'INR'): object
{
    $world = new stdClass();
    $world->calls = [];
    $http = new MockHttpClient(function (string $method, string $url, array $options) use ($replies, $world): MockResponse {
        parse_str((string) ($options['body'] ?? ''), $fields);
        $world->calls[] = [$fields, $method . ' ' . $url];
        $reply = $replies[$fields['command'] ?? ''] ?? ['status' => 0, 'msg' => 'Unexpected'];
        $reply = is_callable($reply) ? $reply($fields) : $reply;

        return new MockResponse((string) json_encode($reply));
    });
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE `transaction` (id INTEGER PRIMARY KEY, invoice_id INTEGER, gateway_id INTEGER, txn_id TEXT, txn_status TEXT, amount TEXT, currency TEXT, type TEXT, status TEXT, error TEXT, error_code INTEGER, note TEXT, created_at TEXT, updated_at TEXT)');
    $db->executeStatement('CREATE TABLE client_balance (id INTEGER PRIMARY KEY, client_id INTEGER, type TEXT, rel_id TEXT, amount REAL)');
    $world->db = $db;

    $invoices = Mockery::mock();
    $invoices->allows('getBalanceDue')->andReturn(12.5);
    $invoices->allows('toApiArray')->andReturn(['serie_nr' => 'INV-00005']);
    $invoices->allows('validatePaymentAmount')->andReturnUsing(fn (float $received) => $received < 12.5 ? throw new ClientFence\Exception('Payment amount does not match') : null);
    $invoices->allows('isInvoiceTypeDeposit')->andReturn(false);
    $world->invoice = payuModel(Model_Invoice::class, ['id' => 5, 'client_id' => 7, 'currency' => $currency, 'hash' => 'abc', 'status' => 'unpaid', 'approved' => 1]);
    $di = container();
    $di['dbal'] = $db;
    $di['http_client'] = $http;
    $di['mod_service'] = $di->protect(fn (): object => $invoices);
    $di['db'] = Mockery::mock();
    $di['db']->allows('getExistingModelById')->andReturnUsing(fn (string $type, int $id): object => $type === 'Client'
        ? payuModel(Model_Client::class, ['id' => $id, 'first_name' => 'Test', 'last_name' => 'Client', 'email' => 'test@example.com', 'phone' => '98431 76540'])
        : $world->invoice);
    $world->adapter = new Payment_Adapter_PayU(['gateway_id' => 3, 'test_mode' => true, 'test_merchant_key' => 'KEY', 'test_merchant_salt' => 'SALT']);
    $world->adapter->setDi($di);

    return $world;
}

/** PayU's post back for invoice #5, signed with PayU's documented reverse hash (udf1 this site's instance). */
function payuPost(array $more = []): array
{
    $post = $more + ['key' => 'KEY', 'txnid' => 'cf5-0a1b2c3d', 'amount' => '12.50', 'productinfo' => 'Invoice INV-00005', 'firstname' => 'Test', 'email' => 'test@example.com', 'udf1' => INSTANCE_ID, 'udf2' => '', 'udf3' => '', 'udf4' => '', 'udf5' => '', 'status' => 'success', 'mihpayid' => '403993715521937565'];
    $post['hash'] ??= hash('sha512', 'SALT|' . $post['status'] . '||||||' . $post['udf5'] . '|' . $post['udf4'] . '|' . $post['udf3'] . '|' . $post['udf2'] . '|' . $post['udf1'] . '|' . $post['email'] . '|' . $post['firstname'] . '|' . $post['productinfo'] . '|' . $post['amount'] . '|' . $post['txnid'] . '|' . $post['key']);

    return $post;
}

/** verify_payment's answer for txnid cf5-0a1b2c3d. */
function payuVerified(string $status = 'success', array $more = []): array
{
    return ['status' => 1, 'msg' => '1 out of 1 Transactions Fetched Successfully', 'transaction_details' => ['cf5-0a1b2c3d' => $more + ['mihpayid' => '403993715521937565', 'txnid' => 'cf5-0a1b2c3d', 'status' => $status, 'amt' => '12.50', 'udf1' => INSTANCE_ID, 'error_Message' => $status === 'success' ? 'NO ERROR' : 'Bank was unable to authenticate.']]];
}

test('needs the merchant key and salt of the current mode', function (): void {
    expect(fn () => new Payment_Adapter_PayU(['test_mode' => true, 'merchant_key' => 'k', 'merchant_salt' => 's']))->toThrow(Payment_Exception::class)
        ->and(fn () => new Payment_Adapter_PayU(['test_mode' => false, 'merchant_key' => 'k']))->toThrow(Payment_Exception::class);
    expect(Payment_Adapter_PayU::getConfig())->toMatchArray(['supports_one_time_payments' => true, 'supports_subscriptions' => false]);
});

test('the payment form posts the balance to PayU signed with the documented request hash', function (): void {
    $world = payuWorld([]);
    $fields = $world->adapter->paymentFields($world->invoice);
    expect($fields['txnid'])->toMatch('/^cf5-[0-9a-f]{8}$/')
        ->and(strlen($fields['txnid']))->toBeLessThanOrEqual(25)
        ->and($fields)->toMatchArray(['key' => 'KEY', 'amount' => '12.50', 'productinfo' => 'Invoice INV-00005', 'firstname' => 'Test', 'lastname' => 'Client', 'email' => 'test@example.com', 'phone' => '9843176540', 'udf1' => INSTANCE_ID])
        ->and($fields['surl'])->toBe(SYSTEM_URL . 'ipn.php?gateway_id=3&invoice_id=5&invoice_hash=abc&redirect=1')
        ->and($fields['furl'])->toBe($fields['surl'])
        // sha512(key|txnid|amount|productinfo|firstname|email|udf1|udf2|udf3|udf4|udf5||||||SALT)
        ->and($fields['hash'])->toBe(hash('sha512', 'KEY|' . $fields['txnid'] . '|12.50|Invoice INV-00005|Test|test@example.com|' . INSTANCE_ID . '||||||||||SALT'));
    // 17 fields: key, txnid, amount, productinfo, firstname, email, udf1 to udf10, salt.
    expect(substr_count('KEY|' . $fields['txnid'] . '|12.50|Invoice INV-00005|Test|test@example.com|' . INSTANCE_ID . '||||||||||SALT', '|'))->toBe(16);

    $html = $world->adapter->getHtml(null, 5, false);
    expect($html)->toContain('action="https://test.payu.in/_payment"')
        ->and($html)->toContain('name="hash" value="')
        ->and($html)->toContain('name="surl" value="' . htmlspecialchars($fields['surl'], ENT_QUOTES));

    $usd = payuWorld([], 'USD');
    expect($usd->adapter->getHtml(null, 5, false))->toContain('Indian rupees (INR) only')->not->toContain('<form');
});

test('checks the reverse hash against fixed vectors, with and without PayU\'s additional charges', function (): void {
    $world = payuWorld(['verify_payment' => payuVerified('success', ['udf1' => 'site-1'])]);
    $post = payuPost(['udf1' => 'site-1', 'hash' => '899dae5dd40ecec8501eaf17a62dda20ca9664300c05b9a912c043b162e4a100594ad3204638de3ddc11cbf11e1bf8c1969555c1d1d34f08a12ad5ca3de51b3e']);
    $world->db->insert('`transaction`', ['id' => 40, 'gateway_id' => 3, 'status' => 'received']);

    // The hash is accepted, so the payment is read back, then refused as another site's (udf1).
    expect(fn () => $world->adapter->processTransaction(Mockery::mock(), 40, ['source' => 'ipn', 'post' => $post], 3))->toThrow(Payment_Exception::class, 'is not for a ClientFence invoice');
    expect($world->calls[0][1])->toBe('POST https://test.payu.in/merchant/postservice.php?form=2')
        ->and($world->calls[0][0])->toBe(['key' => 'KEY', 'command' => 'verify_payment', 'var1' => 'cf5-0a1b2c3d', 'hash' => hash('sha512', 'KEY|verify_payment|cf5-0a1b2c3d|SALT')]);

    // sha512(additionalCharges|SALT|status||||||udf5|...|key)
    $charged = $post + ['additionalCharges' => '20.00'];
    $charged['hash'] = 'cbf60ca6c111fa3b40aeb72b9b8afccbab0d0307dcfa7589fba7c280ad6e384d0620b0e8cb74890b9d3d3dfe8f35f5167785ffe04d342a97aa54b04835109245';
    expect(fn () => $world->adapter->processTransaction(Mockery::mock(), 40, ['source' => 'ipn', 'post' => $charged], 3))->toThrow(Payment_Exception::class, 'is not for a ClientFence invoice');
    expect($world->calls)->toHaveCount(2);
});

test('a tampered or unsigned post is refused before anything is read back', function (array $post): void {
    $world = payuWorld(['verify_payment' => payuVerified()]);
    expect(fn () => $world->adapter->processTransaction(Mockery::mock(), 40, ['source' => 'ipn', 'post' => $post], 3))->toThrow(Payment_Exception::class, 'is not signed')
        ->and($world->calls)->toBe([]);
})->with([
    'amount changed' => [array_merge(payuPost(), ['amount' => '1.00'])],
    'status changed' => [array_merge(payuPost(['status' => 'failure']), ['status' => 'success'])],
    'another merchant key' => [payuPost(['key' => 'OTHER'])],
    'no hash' => [array_merge(payuPost(), ['hash' => ''])],
    'nothing posted' => [[]],
]);

test('records a payment read back from PayU on its invoice once; the browser\'s word alone never pays', function (array $post, array $verified, string $currency, ?string $error, string $rowStatus, bool $applied): void {
    $world = payuWorld(['verify_payment' => $verified], $currency);
    $world->db->insert('`transaction`', ['id' => 40, 'gateway_id' => 3, 'status' => 'received']);
    $api = Mockery::mock();
    $api->shouldReceive('client_balance_add_funds')->times($applied ? 1 : 0)->with(Mockery::subset(['id' => 7, 'amount' => 12.5, 'type' => 'PayU', 'rel_id' => '403993715521937565']));
    $api->shouldReceive('invoice_pay_with_credits')->times($applied ? 1 : 0)->with(['id' => 5]);

    $run = fn () => $world->adapter->processTransaction($api, 40, ['source' => 'ipn', 'post' => $post], 3);
    $error === null ? $run() : expect($run)->toThrow(Exception::class, $error);
    expect($world->db->fetchOne('SELECT status FROM `transaction` WHERE id = 40'))->toBe($rowStatus);
    if ($applied) {
        expect($world->db->fetchAssociative('SELECT txn_id, txn_status, amount, currency, type FROM `transaction` WHERE id = 40'))->toBe(['txn_id' => '403993715521937565', 'txn_status' => 'Completed', 'amount' => '12.5', 'currency' => 'INR', 'type' => 'payu']);
    }
})->with([
    'paid' => [payuPost(), payuVerified(), 'INR', null, 'processed', true],
    'the return says success, PayU says failure' => [payuPost(), payuVerified('failure'), 'INR', null, 'error', false],
    'still pending' => [payuPost(['status' => 'pending']), payuVerified('pending'), 'INR', null, 'received', false],
    'not found at PayU' => [payuPost(), ['status' => 0, 'msg' => '0 out of 1 Transactions Fetched Successfully', 'transaction_details' => ['cf5-0a1b2c3d' => ['mihpayid' => 'Not Found', 'status' => 'Not Found']]], 'INR', 'was not found', 'received', false],
    'another site on the same key' => [payuPost(), payuVerified('success', ['udf1' => 'other-install']), 'INR', 'is not for a ClientFence invoice', 'received', false],
    'another shop\'s txnid' => [payuPost(['txnid' => 'ORDER-1001']), payuVerified(), 'INR', 'is not for a ClientFence invoice', 'received', false],
    'an invoice not in rupees' => [payuPost(), payuVerified(), 'USD', 'is in INR, the invoice in USD', 'received', false],
    'less than the balance' => [payuPost(), payuVerified('success', ['amt' => '5.00']), 'INR', 'Payment amount does not match', 'received', false],
]);

test('the return and the webhook of one payment credit it once', function (): void {
    $world = payuWorld(['verify_payment' => payuVerified()]);
    $world->db->insert('`transaction`', ['id' => 40, 'gateway_id' => 3, 'status' => 'received']);
    $world->db->insert('`transaction`', ['id' => 41, 'gateway_id' => 3, 'status' => 'received']);
    $api = Mockery::mock();
    $api->shouldReceive('client_balance_add_funds')->once();
    $api->shouldReceive('invoice_pay_with_credits')->once();

    $world->adapter->processTransaction($api, 40, ['source' => 'ipn', 'post' => payuPost()], 3);
    $world->adapter->processTransaction($api, 41, ['source' => 'ipn', 'post' => payuPost() + ['mode' => 'UPI']], 3);
    expect($world->db->fetchOne('SELECT note FROM `transaction` WHERE id = 41'))->toBe('Payment 403993715521937565 was already recorded on transaction #40.');
});

test('a pending row settled by the webhook counts the payment once', function (): void {
    $pending = payuWorld(['verify_payment' => payuVerified('pending')]);
    $pending->db->insert('`transaction`', ['id' => 40, 'gateway_id' => 3, 'status' => 'received']);
    $pending->adapter->processTransaction(Mockery::mock(), 40, ['source' => 'ipn', 'post' => payuPost(['status' => 'pending'])], 3);
    expect($pending->db->fetchAssociative('SELECT status, txn_status, amount FROM `transaction` WHERE id = 40'))->toBe(['status' => 'received', 'txn_status' => 'processing', 'amount' => '12.5']);

    // The webhook's row records the payment; the cron then reads the waiting row back.
    $world = payuWorld(['verify_payment' => payuVerified()]);
    $world->db->insert('`transaction`', ['id' => 40, 'gateway_id' => 3, 'invoice_id' => 5, 'type' => 'payu', 'amount' => '12.5', 'txn_status' => 'processing', 'status' => 'received']);
    $world->db->insert('`transaction`', ['id' => 41, 'gateway_id' => 3, 'status' => 'received']);
    $api = Mockery::mock();
    $api->shouldReceive('client_balance_add_funds')->once();
    $api->shouldReceive('invoice_pay_with_credits')->once();
    $world->adapter->processTransaction($api, 41, ['source' => 'ipn', 'post' => payuPost()], 3);
    $world->adapter->processTransaction($api, 40, ['source' => 'ipn', 'post' => payuPost()], 3);
    expect($world->db->fetchOne("SELECT SUM(amount) FROM `transaction` WHERE status = 'processed'"))->toEqual(12.5)
        ->and($world->db->fetchOne('SELECT note FROM `transaction` WHERE id = 40'))->toBe('Payment 403993715521937565 was already recorded on transaction #41.');
});

test('refunds a payment by its mihpayid with a new token each time', function (): void {
    $world = payuWorld(['cancel_refund_transaction' => ['status' => 1, 'msg' => 'Refund Request Queued', 'request_id' => '130247421', 'bank_ref_num' => null, 'mihpayid' => 403993715521937565, 'error_code' => 102]]);
    expect($world->adapter->refundPayment('403993715521937565', 5.25, 'INR'))->toBe('130247421');
    $fields = $world->calls[0][0];
    expect($fields)->toMatchArray(['key' => 'KEY', 'command' => 'cancel_refund_transaction', 'var1' => '403993715521937565', 'var3' => '5.25', 'hash' => hash('sha512', 'KEY|cancel_refund_transaction|403993715521937565|SALT')])
        ->and(strlen($fields['var2']))->toBeLessThanOrEqual(23);
    $world->adapter->refundPayment('403993715521937565', 1.0, 'INR');
    expect($world->calls[1][0]['var2'])->not->toBe($fields['var2']);

    $refused = payuWorld(['cancel_refund_transaction' => ['status' => 0, 'msg' => 'token already used or request pending']]);
    expect(fn () => $refused->adapter->refundPayment('403993715521937565', 5.0, 'INR'))->toThrow(ClientFence\InformationException::class, 'token already used');
    expect(fn () => $refused->adapter->refundPayment('403993715521937565', 5.0, 'USD'))->toThrow(ClientFence\InformationException::class)
        ->and($refused->calls)->toHaveCount(1);

    $silent = payuWorld(['cancel_refund_transaction' => fn () => throw new RuntimeException('timeout')]);
    expect(fn () => $silent->adapter->refundPayment('403993715521937565', 5.0, 'INR'))->toThrow(Payment_Exception::class);
});
