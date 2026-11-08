<?php

declare(strict_types=1);

/**
 * ClientFence: PayPal Auto-Pay (Payment_Adapter_PayPalAutoPay): billing agreements charged with PayPal's NVP API.
 * PayPal's replies come from a mock HTTP client (METHOD => reply fields), the tables from in-memory SQLite.
 */

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function Tests\Helpers\container;

/** An invoice model with $values. */
function autopayInvoice(array $values = []): Model_Invoice
{
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    foreach ($values + ['id' => 5, 'client_id' => 7, 'currency' => 'USD', 'hash' => 'abc', 'status' => 'unpaid', 'approved' => 1] as $key => $value) {
        $invoice->{$key} = $value;
    }

    return $invoice;
}

/**
 * An adapter (gateway 3, sandbox) whose NVP calls get $replies (METHOD => fields, or a callable of the request fields),
 * recorded in $world->calls. $world->db is the dbal, $world->invoices the Invoice service mock. Exchange rates: the
 * default currency is EUR, 1 EUR = 1.25 USD.
 */
function autopayWorld(array $replies, array $config = []): object
{
    $world = new stdClass();
    $world->calls = [];
    $http = new MockHttpClient(function (string $method, string $url, array $options) use ($replies, $world): MockResponse {
        parse_str((string) $options['body'], $fields);
        $world->calls[] = $fields;
        $reply = $replies[$fields['METHOD']] ?? ['ACK' => 'Failure', 'L_ERRORCODE0' => '10001', 'L_LONGMESSAGE0' => 'Unexpected'];

        return new MockResponse(http_build_query(is_callable($reply) ? $reply($fields) : $reply));
    });
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE pay_gateway_customer (id INTEGER PRIMARY KEY, pay_gateway_id INTEGER, client_id INTEGER, test_mode INTEGER, external_customer_id TEXT, saved_cards INTEGER DEFAULT 0, default_payment_method TEXT, default_card TEXT, auto_pay_at TEXT, created_at TEXT, updated_at TEXT)');
    $db->executeStatement('CREATE TABLE `transaction` (id INTEGER PRIMARY KEY, invoice_id INTEGER, gateway_id INTEGER, txn_id TEXT, txn_status TEXT, amount TEXT, currency TEXT, type TEXT, status TEXT, error TEXT, error_code INTEGER, note TEXT, created_at TEXT, updated_at TEXT)');
    $db->executeStatement('CREATE TABLE invoice_item (id INTEGER PRIMARY KEY, invoice_id INTEGER, type TEXT, rel_id TEXT, task TEXT, status TEXT, period TEXT)');
    $db->executeStatement('CREATE TABLE client_order (id INTEGER PRIMARY KEY, expires_at TEXT, period TEXT, updated_at TEXT)');
    $db->insert('pay_gateway_customer', ['pay_gateway_id' => 3, 'client_id' => 7, 'test_mode' => 1, 'external_customer_id' => '', 'saved_cards' => 1, 'default_payment_method' => 'B-OLD', 'default_card' => 'PayPal old@example.com', 'auto_pay_at' => '2026-11-01 00:00:00']);
    $world->db = $db;

    $invoices = Mockery::mock();
    $invoices->allows('getBalanceDue')->andReturn(12.5);
    $invoices->allows('toApiArray')->andReturn(['serie_nr' => 'INV-00005']);
    $invoices->allows('getTotalWithTax')->andReturn(12.5);
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
            public function getBaseCurrencyRate(string $code): float
            {
                return ['EUR' => 1.0, 'USD' => 0.8][$code] ?? throw new ClientFence\Exception('Currency not found.');
            }

            public function toBaseCurrency(string $code, float $amount): float
            {
                return $amount * $this->getBaseCurrencyRate($code);
            }
        },
        default => $invoices,
    });
    $redbean = Mockery::mock();
    $redbean->allows('getExistingModelById')->andReturnUsing(fn (string $type, int $id): Model_Invoice => autopayInvoice(['id' => $id]));
    $di['db'] = $redbean;
    $world->di = $di;
    $world->adapter = new Payment_Adapter_PayPalAutoPay(['gateway_id' => 3, 'test_mode' => true, 'test_api_username' => 'sb_api1', 'test_api_password' => 'pw', 'test_api_signature' => 'sig'] + $config);
    $world->adapter->setDi($di);

    return $world;
}

function autopaySaved(Connection $db, int $clientId = 7): ?string
{
    $value = $db->fetchOne('SELECT default_payment_method FROM pay_gateway_customer WHERE pay_gateway_id = 3 AND client_id = ?', [$clientId]);

    return $value === false ? null : $value;
}

test('needs the API details of the current mode', function (): void {
    expect(fn () => new Payment_Adapter_PayPalAutoPay(['test_mode' => false, 'test_api_username' => 'a', 'test_api_password' => 'b', 'test_api_signature' => 'c']))
        ->toThrow(Payment_Exception::class);
    expect(new Payment_Adapter_PayPalAutoPay(['test_mode' => false, 'api_username' => 'a', 'api_password' => 'b', 'api_signature' => 'c']))->toBeInstanceOf(Payment_Adapter_PayPalAutoPay::class);
});

test('charges the billing agreement with a reference transaction, and removes one PayPal says is cancelled', function (): void {
    $world = autopayWorld(['DoReferenceTransaction' => ['ACK' => 'Success', 'TRANSACTIONID' => '8AB', 'PAYMENTSTATUS' => 'Completed']]);

    expect($world->adapter->chargeSavedCard(autopayInvoice(), 'attempt_1'))->toBe('8AB');
    expect($world->calls[0])->toMatchArray([
        'METHOD' => 'DoReferenceTransaction', 'USER' => 'sb_api1', 'REFERENCEID' => 'B-OLD', 'PAYMENTACTION' => 'Sale', 'AMT' => '12.50',
        'CURRENCYCODE' => 'USD', 'CUSTOM' => 'cf-invoice-5', 'MSGSUBID' => 'cf-invoice-5-attempt_1',
    ]);

    $cancelled = autopayWorld(['DoReferenceTransaction' => ['ACK' => 'Failure', 'L_ERRORCODE0' => '10201', 'L_LONGMESSAGE0' => 'Billing agreement was cancelled']]);
    expect(fn () => $cancelled->adapter->chargeSavedCard(autopayInvoice(), 'attempt_2'))
        ->toThrow(ClientFence\InformationException::class, 'PayPal refused the payment: Billing agreement was cancelled (10201)');
    expect(autopaySaved($cancelled->db))->toBeNull();

    // A declined payment, or PayPal refusing reference transactions for the whole account, keeps the agreement.
    foreach (['10417', '11452'] as $code) {
        $declined = autopayWorld(['DoReferenceTransaction' => ['ACK' => 'Failure', 'L_ERRORCODE0' => $code, 'L_LONGMESSAGE0' => 'Refused']]);
        expect(fn () => $declined->adapter->chargeSavedCard(autopayInvoice(), 'attempt_1'))->toThrow(ClientFence\InformationException::class);
        expect(autopaySaved($declined->db))->toBe('B-OLD');
    }

    $silent = autopayWorld(['DoReferenceTransaction' => []]);
    expect(fn () => $silent->adapter->chargeSavedCard(autopayInvoice(), 'attempt_1'))->toThrow(Payment_Exception::class);
});

test('takes an approved PayPal checkout once and keeps its new billing agreement', function (): void {
    $details = ['ACK' => 'Success', 'PAYERID' => 'P1', 'EMAIL' => 'new@example.com', 'PAYMENTREQUEST_0_CUSTOM' => 'cf-invoice-5', 'PAYMENTREQUEST_0_AMT' => '12.50', 'PAYMENTREQUEST_0_CURRENCYCODE' => 'USD'];
    $world = autopayWorld([
        'GetExpressCheckoutDetails' => $details,
        'DoExpressCheckoutPayment' => ['ACK' => 'Success', 'PAYMENTINFO_0_TRANSACTIONID' => '9CD', 'BILLINGAGREEMENTID' => 'B-NEW'],
        'BillAgreementUpdate' => ['ACK' => 'Success', 'BILLINGAGREEMENTSTATUS' => 'Canceled'],
    ]);

    expect($world->adapter->checkoutPaid(autopayInvoice(), 'EC-1'))->toBe('9CD')
        ->and(autopaySaved($world->db))->toBe('B-NEW')
        ->and($world->db->fetchOne('SELECT default_card FROM pay_gateway_customer WHERE client_id = 7'))->toBe('PayPal new@example.com');
    expect(array_column($world->calls, 'METHOD'))->toBe(['GetExpressCheckoutDetails', 'DoExpressCheckoutPayment', 'BillAgreementUpdate'])
        ->and($world->calls[1])->toMatchArray(['TOKEN' => 'EC-1', 'PAYERID' => 'P1', 'PAYMENTREQUEST_0_AMT' => '12.50', 'MSGSUBID' => 'cf-EC-1'])
        ->and($world->calls[2])->toMatchArray(['REFERENCEID' => 'B-OLD', 'BILLINGAGREEMENTSTATUS' => 'Canceled']);

    $again = autopayWorld(['GetExpressCheckoutDetails' => $details + ['PAYMENTREQUEST_0_TRANSACTIONID' => '9CD']]);
    expect($again->adapter->checkoutPaid(autopayInvoice(), 'EC-1'))->toBe('9CD')
        ->and(array_column($again->calls, 'METHOD'))->toBe(['GetExpressCheckoutDetails']);

    $other = autopayWorld(['GetExpressCheckoutDetails' => ['PAYMENTREQUEST_0_CUSTOM' => 'cf-invoice-6'] + $details]);
    expect(fn () => $other->adapter->checkoutPaid(autopayInvoice(), 'EC-1'))->toThrow(ClientFence\InformationException::class);
});

test('records a payment PayPal holds as pending, and a completed one on its invoice once', function (string $status, string $custom, ?string $error, string $rowStatus, string $txnStatus, bool $applied): void {
    $world = autopayWorld(['GetTransactionDetails' => ['ACK' => 'Success', 'PAYMENTSTATUS' => $status, 'PENDINGREASON' => 'echeck', 'AMT' => '12.50', 'CURRENCYCODE' => 'USD', 'CUSTOM' => $custom]]);
    $world->db->insert('`transaction`', ['id' => 40, 'gateway_id' => 3, 'status' => 'received']);
    $world->invoices->allows('validatePaymentAmount');
    $world->invoices->allows('isInvoiceTypeDeposit')->andReturn(false);
    $api = Mockery::mock();
    $api->shouldReceive('client_balance_add_funds')->times($applied ? 1 : 0)->with(Mockery::subset(['id' => 7, 'amount' => 12.5, 'rel_id' => '8AB']));
    $api->shouldReceive('invoice_pay_with_credits')->times($applied ? 1 : 0)->with(['id' => 5]);

    $run = fn () => $world->adapter->processTransaction($api, 40, ['get' => ['paypal_txn' => '8AB']], 3);
    $error === null ? $run() : expect($run)->toThrow(Payment_Exception::class, $error);
    $row = $world->db->fetchAssociative('SELECT * FROM `transaction` WHERE id = 40');
    expect($row['status'])->toBe($rowStatus)->and((string) $row['txn_status'])->toBe($txnStatus);
})->with([
    'completed' => ['Completed', 'cf-invoice-5', null, 'processed', 'Completed', true],
    'held by PayPal' => ['Pending', 'cf-invoice-5', null, 'received', 'processing', false],
    'another shop\'s payment' => ['Completed', 'order-77', 'is not for a ClientFence invoice', 'received', '', false],
]);

test('a payment ID sent to ipn.php is never trusted, and a payment the other PayPal gateway recorded is not credited again', function (): void {
    $world = autopayWorld(['GetTransactionDetails' => ['ACK' => 'Success', 'PAYMENTSTATUS' => 'Completed', 'AMT' => '12.50', 'CURRENCYCODE' => 'USD', 'CUSTOM' => 'cf-invoice-5']]);
    $world->db->insert('`transaction`', ['id' => 44, 'gateway_id' => 3, 'status' => 'received']);
    $world->adapter->processTransaction(Mockery::mock(), 44, ['source' => 'ipn', 'get' => ['gateway_id' => '3', 'paypal_txn' => '8AB']], 3);
    expect($world->calls)->toBe([])
        ->and($world->db->fetchOne('SELECT note FROM `transaction` WHERE id = 44'))->toBe('No action needed.');

    $world->db->insert('`transaction`', ['id' => 45, 'gateway_id' => 2, 'invoice_id' => 5, 'txn_id' => '8AB', 'status' => 'processed', 'txn_status' => 'Completed']);
    $world->db->insert('`transaction`', ['id' => 46, 'gateway_id' => 3, 'status' => 'received']);
    $world->adapter->processTransaction(Mockery::mock(), 46, ['source' => 'saved_card', 'get' => ['paypal_txn' => '8AB']], 3);
    expect($world->db->fetchAssociative('SELECT status, amount, note FROM `transaction` WHERE id = 46'))->toBe(['status' => 'processed', 'amount' => null, 'note' => 'Payment 8AB was already recorded on transaction #45.']);
});

test('a PayPal notice removes a cancelled agreement, and completes a held payment on its own row once', function (): void {
    $paypal = ['ACK' => 'Success', 'PAYMENTSTATUS' => 'Pending', 'PENDINGREASON' => 'echeck', 'AMT' => '12.50', 'CURRENCYCODE' => 'USD', 'CUSTOM' => 'cf-invoice-5'];
    $world = autopayWorld(['GetTransactionDetails' => function () use (&$paypal): array {
        return $paypal;
    }]);
    $api = Mockery::mock();
    $world->db->insert('`transaction`', ['id' => 41, 'gateway_id' => 3, 'status' => 'received']);

    $world->adapter->processTransaction($api, 41, ['post' => ['txn_type' => 'mp_cancel', 'mp_id' => 'B-OLD']], 3);
    expect(autopaySaved($world->db))->toBeNull()
        ->and($world->db->fetchOne('SELECT status FROM `transaction` WHERE id = 41'))->toBe('processed');

    // The charge got no answer, so PayPal's Pending notice is the first row that knows the payment: it waits.
    $world->db->insert('`transaction`', ['id' => 42, 'gateway_id' => 3, 'status' => 'received']);
    $world->adapter->processTransaction($api, 42, ['post' => ['txn_type' => 'merch_pmt', 'payment_status' => 'Pending', 'txn_id' => '8AB']], 3);
    expect($world->db->fetchAssociative('SELECT status, txn_status, invoice_id FROM `transaction` WHERE id = 42'))->toBe(['status' => 'received', 'txn_status' => 'processing', 'invoice_id' => 5]);

    // Completed: recorded on that row (no longer pending when the invoice is paid), the notice's row holds no money.
    $paypal['PAYMENTSTATUS'] = 'Completed';
    $world->db->insert('`transaction`', ['id' => 43, 'gateway_id' => 3, 'status' => 'received']);
    $world->invoices->allows('validatePaymentAmount');
    $world->invoices->allows('isInvoiceTypeDeposit')->andReturn(false);
    $api->shouldReceive('client_balance_add_funds')->once();
    $atPay = null;
    $api->shouldReceive('invoice_pay_with_credits')->once()->andReturnUsing(function () use ($world, &$atPay): void {
        $atPay = $world->db->fetchOne('SELECT txn_status FROM `transaction` WHERE id = 42');
    });
    $world->adapter->processTransaction($api, 43, ['post' => ['txn_type' => 'merch_pmt', 'payment_status' => 'Completed', 'txn_id' => '8AB']], 3);
    expect($atPay)->toBe('Completed')
        ->and($world->db->fetchAssociative('SELECT status, txn_status, amount FROM `transaction` WHERE id = 42'))->toBe(['status' => 'processed', 'txn_status' => 'Completed', 'amount' => '12.5'])
        ->and($world->db->fetchAssociative('SELECT status, amount, note FROM `transaction` WHERE id = 43'))->toBe(['status' => 'processed', 'amount' => null, 'note' => 'Payment 8AB completed: transaction #42.']);

    // "Process again" on it, and the return page after the notice, credit nothing more.
    $world->adapter->processTransaction($api, 42, ['source' => 'saved_card', 'get' => ['paypal_txn' => '8AB']], 3);
    $world->db->insert('`transaction`', ['id' => 47, 'gateway_id' => 3, 'status' => 'received']);
    $world->adapter->processTransaction($api, 47, ['source' => 'paypal_autopay_return', 'get' => ['paypal_txn' => '8AB']], 3);
    expect($world->db->fetchOne('SELECT note FROM `transaction` WHERE id = 47'))->toBe('Payment 8AB was already recorded on transaction #42.');
});

test('a chargeback refunds the payment on its invoice and moves the renewed services back by their period (WHMCS)', function (): void {
    $world = autopayWorld([]);
    $world->db->insert('`transaction`', ['id' => 50, 'gateway_id' => 3, 'invoice_id' => 5, 'txn_id' => '8AB', 'status' => 'processed', 'txn_status' => 'Completed', 'amount' => '12.50']);
    $world->db->insert('`transaction`', ['id' => 51, 'gateway_id' => 3, 'status' => 'received']);
    $world->db->insert('invoice_item', ['invoice_id' => 5, 'type' => 'order', 'rel_id' => '9', 'task' => 'renew', 'status' => 'executed', 'period' => '1M']);
    $world->db->insert('invoice_item', ['invoice_id' => 5, 'type' => 'order', 'rel_id' => '10', 'task' => 'activate', 'status' => 'executed', 'period' => '1Y']);
    $world->db->insert('client_order', ['id' => 9, 'expires_at' => '2026-12-15 10:00:00', 'period' => '1M']);
    $world->db->insert('client_order', ['id' => 10, 'expires_at' => '2027-11-01 10:00:00', 'period' => '1Y']);
    $world->invoices->shouldReceive('refundInvoice')->once()->with(Mockery::type(Model_Invoice::class), 'PayPal chargeback 7RV of payment 8AB.', null);
    $world->invoices->shouldReceive('addNote')->once()->with(Mockery::type(Model_Invoice::class), Mockery::on(fn (string $note): bool => str_contains($note, 'order #9 due 2026-11-15')));

    $world->adapter->processTransaction(Mockery::mock(), 51, ['post' => ['payment_status' => 'Reversed', 'txn_id' => '7RV', 'parent_txn_id' => '8AB', 'mc_gross' => '-12.50']], 3);
    expect($world->db->fetchOne('SELECT expires_at FROM client_order WHERE id = 9'))->toBe('2026-11-15 10:00:00')
        ->and($world->db->fetchOne('SELECT expires_at FROM client_order WHERE id = 10'))->toBe('2027-11-01 10:00:00')
        ->and($world->db->fetchOne('SELECT note FROM `transaction` WHERE id = 51'))->toContain('PayPal chargeback 7RV of payment 8AB.');
});

test('reads the PayPal balance and refunds a payment through the NVP API', function (): void {
    $world = autopayWorld([
        'GetBalance' => ['ACK' => 'Success', 'L_AMT0' => '120.40', 'L_CURRENCYCODE0' => 'USD', 'L_AMT1' => '3.00', 'L_CURRENCYCODE1' => 'EUR'],
        'RefundTransaction' => ['ACK' => 'Success', 'REFUNDTRANSACTIONID' => '1RF'],
    ]);

    expect($world->adapter->balances())->toBe([['currency' => 'USD', 'amount' => 120.4], ['currency' => 'EUR', 'amount' => 3.0]])
        ->and($world->adapter->refundPayment('8AB', 5, 'USD'))->toBe('1RF')
        ->and(end($world->calls))->toMatchArray(['TRANSACTIONID' => '8AB', 'REFUNDTYPE' => 'Partial', 'AMT' => '5.00', 'CURRENCYCODE' => 'USD']);
});

test('converts invoices to the processing currency as WHMCS: charge, payment, refund and chargeback', function (): void {
    $paypal = ['ACK' => 'Success', 'PAYMENTSTATUS' => 'Completed', 'AMT' => '15.63', 'CURRENCYCODE' => 'USD'];
    $world = autopayWorld([
        'DoReferenceTransaction' => ['ACK' => 'Success', 'TRANSACTIONID' => '8AB'],
        'GetTransactionDetails' => function () use (&$paypal): array {
            return $paypal;
        },
        'RefundTransaction' => ['ACK' => 'Success', 'REFUNDTRANSACTIONID' => '1RF'],
    ], ['convert_to' => 'usd']);
    $eur = autopayInvoice(['currency' => 'EUR']);
    $world->adapter->chargeSavedCard($eur, 'attempt_1');
    expect($world->calls[0])->toMatchArray(['AMT' => '15.63', 'CURRENCYCODE' => 'USD'])
        ->and($world->calls[0]['CUSTOM'])->toMatch('/^cf-invoice-5-12\.50-[0-9a-f]{16}$/');

    // It pays the 12.50 EUR asked for (signed in its CUSTOM), whatever today's rate.
    $paypal['CUSTOM'] = $world->calls[0]['CUSTOM'];
    $world->di['db'] = Mockery::mock(['getExistingModelById' => $eur]);
    $world->db->insert('`transaction`', ['id' => 60, 'gateway_id' => 3, 'status' => 'received']);
    $world->invoices->shouldReceive('validatePaymentAmount')->once()->with(12.5, 12.5, Mockery::any());
    $world->invoices->shouldReceive('validatePaymentAmount')->once()->with(0.01, 12.5, Mockery::any());
    $world->invoices->allows('isInvoiceTypeDeposit')->andReturn(false);
    $api = Mockery::mock();
    $api->shouldReceive('client_balance_add_funds')->once()->with(Mockery::subset(['amount' => 12.5]));
    $api->allows('invoice_pay_with_credits');
    $world->adapter->processTransaction($api, 60, ['get' => ['paypal_txn' => '8AB']], 3);
    expect($world->db->fetchAssociative('SELECT amount, currency, note, status FROM `transaction` WHERE id = 60'))
        ->toBe(['amount' => '12.5', 'currency' => 'EUR', 'note' => 'PayPal: 15.63 USD.', 'status' => 'processed']);

    // A payment of the payer's own making that claims more in its CUSTOM counts only for what PayPal took.
    $paypal = ['ACK' => 'Success', 'PAYMENTSTATUS' => 'Completed', 'AMT' => '0.01', 'CURRENCYCODE' => 'USD', 'CUSTOM' => 'cf-invoice-5-99999.00-0123456789abcdef'];
    $world->db->insert('`transaction`', ['id' => 62, 'gateway_id' => 3, 'status' => 'received']);
    $api->shouldReceive('client_balance_add_funds')->once()->with(Mockery::subset(['amount' => 0.01]));
    $world->adapter->processTransaction($api, 62, ['get' => ['paypal_txn' => '9FG']], 3);
    $paypal = ['ACK' => 'Success', 'AMT' => '15.63', 'CURRENCYCODE' => 'USD'];

    // Refunding 5 of the 12.50 EUR sends back the same share of the 15.63 USD.
    $world->adapter->refundPayment('8AB', 5, 'EUR');
    expect(end($world->calls))->toMatchArray(['METHOD' => 'RefundTransaction', 'AMT' => '6.25', 'CURRENCYCODE' => 'USD']);

    // A full USD chargeback is the whole EUR payment.
    $world->db->insert('`transaction`', ['id' => 61, 'gateway_id' => 3, 'status' => 'received']);
    $world->invoices->shouldReceive('refundInvoice')->once()->with(Mockery::type(Model_Invoice::class), 'PayPal chargeback 7RV of payment 8AB.', null);
    $world->invoices->allows('addNote');
    $world->adapter->processTransaction(Mockery::mock(), 61, ['post' => ['payment_status' => 'Reversed', 'txn_id' => '7RV', 'parent_txn_id' => '8AB', 'mc_gross' => '-15.63', 'mc_currency' => 'USD']], 3);

    // Saving the gateway with a currency the portal does not have is refused.
    $wrong = autopayWorld([], ['convert_to' => 'GBP']);
    expect(fn () => $wrong->adapter->testConnection())->toThrow(ClientFence\InformationException::class, 'GBP is not one of the currencies');
});
