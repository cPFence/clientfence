<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use Payment_Adapter_PayPalEmail;

use function Tests\Helpers\container;

function buildPayPalEmailAdapter(string $configuredEmail, string $postbackResponse, ?array &$capturedRequestBody = null, ?string &$capturedRawRequestBody = null): Payment_Adapter_PayPalEmail
{
    $response = Mockery::mock();
    $response->shouldReceive('getContent')->andReturn($postbackResponse);

    $httpClient = Mockery::mock();
    $httpClient->shouldReceive('withOptions')->andReturnSelf();
    $httpClient->shouldReceive('request')
        ->with('POST', Mockery::any(), Mockery::on(function (array $options) use (&$capturedRequestBody, &$capturedRawRequestBody): bool {
            $capturedRawRequestBody = (string) ($options['body'] ?? '');
            parse_str($capturedRawRequestBody, $capturedRequestBody);

            return true;
        }))
        ->andReturn($response);

    $adapter = new Payment_Adapter_PayPalEmail(['email' => $configuredEmail, 'test_mode' => false]);
    $di = container();
    $di['http_client'] = $httpClient;
    $adapter->setDi($di);

    return $adapter;
}

function callIsIpnValid(Payment_Adapter_PayPalEmail $adapter, string $rawPostBody): bool
{
    $reflection = new ReflectionClass($adapter);

    return (bool) $reflection->getMethod('_isIpnValid')->invokeArgs($adapter, [['http_raw_post_data' => $rawPostBody]]);
}

function callValidateCurrency(Payment_Adapter_PayPalEmail $adapter, mixed $received, mixed $expected): void
{
    $reflection = new ReflectionClass($adapter);
    $reflection->getMethod('validateCurrency')->invokeArgs($adapter, [$received, $expected]);
}

describe('validateCurrency', function (): void {
    test('accepts a currency matching the invoice case-insensitively', function (): void {
        $adapter = buildPayPalEmailAdapter('merchant@example.com', 'VERIFIED');

        expect(fn () => callValidateCurrency($adapter, 'usd', 'USD'))->not->toThrow(Payment_Exception::class);
    });

    test('rejects a currency that does not match the invoice', function (): void {
        $adapter = buildPayPalEmailAdapter('merchant@example.com', 'VERIFIED');

        expect(fn () => callValidateCurrency($adapter, 'MXN', 'USD'))
            ->toThrow(Payment_Exception::class, 'PayPal payment currency MXN does not match invoice currency USD');
    });

    test('rejects a payment with no currency', function (): void {
        $adapter = buildPayPalEmailAdapter('merchant@example.com', 'VERIFIED');

        expect(fn () => callValidateCurrency($adapter, null, 'USD'))
            ->toThrow(Payment_Exception::class, 'PayPal payment is missing currency details');
    });

    test('rejects a payment when the invoice currency is unknown', function (): void {
        $adapter = buildPayPalEmailAdapter('merchant@example.com', 'VERIFIED');

        expect(fn () => callValidateCurrency($adapter, 'USD', null))
            ->toThrow(Payment_Exception::class, 'PayPal payment is missing currency details');
    });
});

describe('_isIpnValid receiver verification', function (): void {
    test('accepts a payment made to the configured merchant account', function (): void {
        $adapter = buildPayPalEmailAdapter('merchant@example.com', 'VERIFIED');

        $result = callIsIpnValid($adapter, http_build_query([
            'receiver_email' => 'merchant@example.com',
            'mc_gross' => '10.00',
        ]));

        expect($result)->toBeTrue();
    });

    test('accepts the business field when receiver_email is absent', function (): void {
        $adapter = buildPayPalEmailAdapter('merchant@example.com', 'VERIFIED');

        $result = callIsIpnValid($adapter, http_build_query([
            'business' => 'merchant@example.com',
            'mc_gross' => '10.00',
        ]));

        expect($result)->toBeTrue();
    });

    test('accepts the business field when receiver_email is a different confirmed account email', function (): void {
        $adapter = buildPayPalEmailAdapter('merchant@example.com', 'VERIFIED');

        $result = callIsIpnValid($adapter, http_build_query([
            'receiver_email' => 'primary@example.com',
            'business' => 'merchant@example.com',
            'mc_gross' => '10.00',
        ]));

        expect($result)->toBeTrue();
    });

    test('rejects a payment made to a different account', function (): void {
        $adapter = buildPayPalEmailAdapter('merchant@example.com', 'VERIFIED');

        $result = callIsIpnValid($adapter, http_build_query([
            'receiver_email' => 'attacker@evil.example',
            'mc_gross' => '10.00',
        ]));

        expect($result)->toBeFalse();
    });

    test('matches the configured address case-insensitively', function (): void {
        $adapter = buildPayPalEmailAdapter('Merchant@Example.COM', 'VERIFIED');

        $result = callIsIpnValid($adapter, http_build_query([
            'receiver_email' => 'merchant@example.com',
            'mc_gross' => '10.00',
        ]));

        expect($result)->toBeTrue();
    });

    test('matches the configured address after trimming surrounding whitespace', function (): void {
        $adapter = buildPayPalEmailAdapter('merchant@example.com', 'VERIFIED');

        $result = callIsIpnValid($adapter, http_build_query([
            'receiver_email' => '  merchant@example.com  ',
            'mc_gross' => '10.00',
        ]));

        expect($result)->toBeTrue();
    });

    test('rejects a notification with no payee field', function (): void {
        $adapter = buildPayPalEmailAdapter('merchant@example.com', 'VERIFIED');

        $result = callIsIpnValid($adapter, http_build_query([
            'mc_gross' => '10.00',
        ]));

        expect($result)->toBeFalse();
    });

    test('rejects a notification whose postback is not VERIFIED', function (): void {
        $adapter = buildPayPalEmailAdapter('merchant@example.com', 'INVALID');

        $result = callIsIpnValid($adapter, http_build_query([
            'receiver_email' => 'merchant@example.com',
            'mc_gross' => '10.00',
        ]));

        expect($result)->toBeFalse();
    });

    test('sends field values back to PayPal unmodified, backslashes included', function (): void {
        $captured = [];
        $capturedRaw = null;
        $adapter = buildPayPalEmailAdapter('merchant@example.com', 'VERIFIED', $captured, $capturedRaw);

        $addressStreet = 'C:\\Users\\Test 1\\2 Foo St';
        $result = callIsIpnValid($adapter, http_build_query([
            'receiver_email' => 'merchant@example.com',
            'mc_gross' => '10.00',
            'address_street' => $addressStreet,
        ]));

        expect($result)->toBeTrue();
        expect($captured['address_street'])->toBe($addressStreet);
        // Assert on the raw wire body too, not just its parse_str()-decoded form,
        // so a bug in the percent-encoding itself (not just the decoded value)
        // would still be caught.
        expect($capturedRaw)->toContain('address_street=' . urlencode($addressStreet));
    });
});

function paypalProcessAdapter(object $di): Payment_Adapter_PayPalEmail
{
    $adapter = new Payment_Adapter_PayPalEmail(['email' => 'merchant@example.com', 'test_mode' => false]);
    $adapter->setDi($di);

    return $adapter;
}

function paypalDbMocks(?callable $findOne = null): Mockery\MockInterface
{
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('findOne')->byDefault()->andReturnUsing(
        $findOne ?? static fn (): mixed => null
    );
    $dbMock->shouldReceive('getAll')->byDefault()->andReturn([]);

    return $dbMock;
}

function paypalBean(string $model, array $properties): object
{
    $bean = new $model();
    $bean->loadBean(new Tests\Helpers\DummyBean());
    foreach ($properties as $name => $value) {
        $bean->$name = $value;
    }

    return $bean;
}

/**
 * Build the query-string portion of a callback URL as issued post-fix:
 * invoice id plus its binding signature for the given gateway.
 */
function signedPayPalGet(int $invoiceId, int $gatewayId = 1, array $extra = []): array
{
    return array_merge(
        ['invoice_id' => $invoiceId, 'sig' => ClientFence\Tools::signCallbackParams($gatewayId, $invoiceId)],
        $extra
    );
}

test('leaves a pending PayPal payment received with its error', function (): void {
    $tx = [
        'invoice_id' => 16, 'type' => 'web_accept', 'txn_id' => 'TXN-PENDING',
        'txn_status' => 'Pending', 'amount' => '120.00', 'currency' => 'USD',
    ];
    $apiAdmin = Mockery::mock();
    $apiAdmin->shouldReceive('invoice_transaction_get')->once()->with(['id' => 42])->andReturn($tx);
    $apiAdmin->shouldReceive('invoice_get')->once()->with(['id' => 16])->andReturn([
        'id' => 16, 'currency' => 'USD', 'client' => ['id' => 9],
    ]);
    $apiAdmin->shouldReceive('invoice_transaction_update')->once()->with(Mockery::on(function (array $update) use (&$tx): bool {
        $tx = array_replace($tx, $update);

        return true;
    }));

    paypalProcessAdapter(container())->processTransaction($apiAdmin, 42, [
        'post' => ['txn_type' => 'web_accept', 'payment_status' => 'Pending'],
        'get' => signedPayPalGet(16, 2),
    ], 2);

    expect($tx['status'])->toBe(Model_Transaction::STATUS_RECEIVED)
        ->and($tx['error'])->toBe('PayPal payment not completed: Pending');
});

test('settles only full PayPal refunds against the parent payment invoice', function (?float $paymentAmount, float $refundAmount, ?int $refundedInvoice, bool $otherPayment, bool $paidBeforePayment = false): void {
    $payment = $paymentAmount === null ? null : paypalBean(Model_Transaction::class, ['id' => 41, 'invoice_id' => 26, 'amount' => $paymentAmount, 'created_at' => '2026-11-01 10:05:00']);
    $tx = [
        'invoice_id' => 16, 'type' => 'subscr_payment', 'txn_id' => 'REFUND-RENEWAL',
        'txn_status' => 'Refunded', 'amount' => -$refundAmount, 'currency' => 'USD',
    ];
    $api = Mockery::mock();
    $api->shouldReceive('invoice_transaction_get')->once()->with(['id' => 42])->andReturn($tx);
    $api->shouldReceive('invoice_get')->once()->with(['id' => 16])->andReturn(['id' => 16, 'client' => ['id' => 9]]);
    $api->shouldReceive('invoice_transaction_update')->twice()->andReturnUsing(function (array $update) use (&$tx): void {
        $tx = array_replace($tx, $update);
    });
    if ($refundedInvoice === null) {
        $api->shouldNotReceive('invoice_refund');
    } else {
        $api->shouldReceive('invoice_refund')->once()->with(['id' => $refundedInvoice, 'note' => 'PayPal refund TXN-RENEWAL']);
    }
    $db = paypalDbMocks();
    $db->shouldReceive('findOne')->once()->with('Transaction', 'txn_id = :txn AND gateway_id = :gateway AND status = :status AND txn_status = :txn_status', [':txn' => 'TXN-RENEWAL', ':gateway' => 2, ':status' => Model_Transaction::STATUS_PROCESSED, ':txn_status' => 'Completed'])->andReturn($payment);
    if ($refundedInvoice !== null || $otherPayment) {
        $db->shouldReceive('findOne')->once()->with(
            'Transaction',
            'invoice_id = :invoice AND id != :payment AND status = :status AND (gateway_id != :gateway OR txn_status = :txn_status)',
            [':invoice' => 26, ':payment' => 41, ':status' => Model_Transaction::STATUS_PROCESSED, ':gateway' => 2, ':txn_status' => 'Completed']
        )->andReturn($otherPayment ? paypalBean(Model_Transaction::class, ['txn_id' => 'TXN-FIRST', 'invoice_id' => 26, 'amount' => 120]) : null);
    }
    $di = container();
    $di['db'] = $db;
    $db->shouldReceive('getExistingModelById')->with('Invoice', 26)->andReturn(paypalBean(Model_Invoice::class, ['id' => 26, 'paid_at' => $paidBeforePayment ? '2026-11-01 10:00:00' : null]));
    $invoiceService = Mockery::mock();
    $invoiceService->shouldReceive('getTotalWithTax')->andReturn(120.0);
    $invoiceService->shouldReceive('getBalanceDue')->andReturn(120.0);
    $di['mod_service'] = $di->protect(fn () => $invoiceService);

    paypalProcessAdapter($di)->processTransaction($api, 42, [
        'get' => signedPayPalGet(16, 2),
        'post' => [
            'txn_type' => 'subscr_payment', 'payment_status' => 'Refunded', 'txn_id' => 'REFUND-RENEWAL',
            'parent_txn_id' => 'TXN-RENEWAL', 'mc_gross' => -$refundAmount, 'mc_currency' => 'USD',
        ],
    ], 2);

    expect($tx['status'])->toBe(Model_Transaction::STATUS_PROCESSED);
    if ($otherPayment || $paidBeforePayment) {
        expect($tx['error'])->toBe('PayPal refund of 120.00 USD must be settled by hand: invoice has multiple payments.');
    } elseif ($refundedInvoice === null) {
        expect($tx['error'])->toBe($paymentAmount === null
            ? 'PayPal refund of 120.00 USD must be settled by hand: original payment not found.'
            : 'Partial PayPal refund of 20.00 USD must be settled by hand.');
    } else {
        expect($tx['error'])->toBe('');
    }
})->with([
    'partial refund' => [120.0, 20.0, null, false],
    'unknown original payment' => [null, 120.0, null, false],
    'full renewal refund' => [120.0, 120.0, 26, false],
    'full refund within amount tolerance' => [120.0, 119.99, 26, false],
    'full duplicate payment refund' => [120.0, 120.0, null, true],
    'invoice settled from account credit before payment' => [120.0, 120.0, null, false, true],
]);

test('keeps a PayPal subscription active while a failed renewal is retried, and never counts it as paid (WHMCS)', function (): void {
    $tx = [
        'invoice_id' => 16, 'type' => 'subscr_failed', 'txn_id' => null,
        'txn_status' => null, 'amount' => null, 'currency' => null,
    ];
    $api = Mockery::mock();
    $api->shouldReceive('invoice_transaction_get')->once()->with(['id' => 42])->andReturn($tx);
    $api->shouldReceive('invoice_get')->once()->with(['id' => 16])->andReturn(['id' => 16, 'client' => ['id' => 9]]);
    $api->shouldReceive('invoice_transaction_update')->twice()->andReturnUsing(function (array $update) use (&$tx): void {
        $tx = array_replace($tx, $update);
    });
    $api->shouldNotReceive('invoice_subscription_get');
    $subscriptionService = Mockery::mock();
    $subscriptionService->shouldNotReceive('updateStatusFromGateway');
    $di = container();
    $di['mod_service'] = $di->protect(fn () => $subscriptionService);

    paypalProcessAdapter($di)->processTransaction($api, 42, [
        'get' => signedPayPalGet(16, 2),
        'post' => ['txn_type' => 'subscr_failed', 'subscr_id' => 'I-ACTIVE', 'mc_gross' => '10.00'],
    ], 2);

    // The failed amount is kept for staff, but an errored transaction is not money received (Invoice PAID_SQL, reports).
    expect($tx['status'])->toBe(Model_Transaction::STATUS_ERROR)
        ->and($tx['amount'])->toBe('10.00')
        ->and($tx['error'])->toBe('PayPal subscription payment failed; PayPal retries it.');
});

test('leaves PayPal duplicate, overpayment and part-of-the-invoice refunds for manual settlement', function (bool $stripePayment, float $invoiceTotal, string $reason): void {
    $payments = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $payments->executeStatement('CREATE TABLE payment (id INTEGER PRIMARY KEY, txn_id TEXT, gateway_id INTEGER, status TEXT, txn_status TEXT, invoice_id INTEGER, amount REAL)');
    $payments->insert('payment', ['id' => 41, 'txn_id' => 'TXN-RENEWAL', 'gateway_id' => 2, 'status' => 'processed', 'txn_status' => 'Completed', 'invoice_id' => 26, 'amount' => 120]);
    if ($stripePayment) {
        $payments->insert('payment', ['id' => 40, 'txn_id' => 'pi_first', 'gateway_id' => 3, 'status' => 'processed', 'txn_status' => 'succeeded', 'invoice_id' => 26, 'amount' => 120]);
    }
    $db = paypalDbMocks(function (string $table, string $where, array $bindings) use ($payments): ?Model_Transaction {
        $row = $payments->fetchAssociative('SELECT * FROM payment WHERE ' . $where, array_combine(array_map(static fn (string $key): string => ltrim($key, ':'), array_keys($bindings)), $bindings));

        return $row === false ? null : paypalBean(Model_Transaction::class, $row);
    });
    $invoice = paypalBean(Model_Invoice::class, ['id' => 26]);
    $db->shouldReceive('getExistingModelById')->with('Invoice', 26)->andReturn($invoice);
    $invoiceService = Mockery::mock();
    $invoiceService->shouldReceive('getTotalWithTax')->with($invoice)->andReturn($invoiceTotal);
    $invoiceService->shouldReceive('getBalanceDue')->andReturn($invoiceTotal);
    $di = container();
    $di['db'] = $db;
    $di['mod_service'] = $di->protect(fn () => $invoiceService);
    $tx = [
        'invoice_id' => 16, 'type' => 'subscr_payment', 'txn_id' => 'REFUND-RENEWAL',
        'txn_status' => 'Refunded', 'amount' => -120, 'currency' => 'USD',
    ];
    $api = Mockery::mock();
    $api->shouldReceive('invoice_transaction_get')->once()->with(['id' => 42])->andReturn($tx);
    $api->shouldReceive('invoice_get')->once()->with(['id' => 16])->andReturn(['id' => 16, 'client' => ['id' => 9]]);
    $api->shouldReceive('invoice_transaction_update')->twice()->andReturnUsing(function (array $update) use (&$tx): void {
        $tx = array_replace($tx, $update);
    });
    $api->shouldNotReceive('invoice_refund');

    paypalProcessAdapter($di)->processTransaction($api, 42, [
        'get' => signedPayPalGet(16, 2),
        'post' => [
            'txn_type' => 'subscr_payment', 'payment_status' => 'Refunded', 'txn_id' => 'REFUND-RENEWAL',
            'parent_txn_id' => 'TXN-RENEWAL', 'mc_gross' => -120, 'mc_currency' => 'USD',
        ],
    ], 2);

    expect($tx['error'])->toBe('PayPal refund of 120.00 USD must be settled by hand: ' . $reason . '.')
        ->and($tx['status'])->toBe(Model_Transaction::STATUS_PROCESSED);
})->with([
    'Stripe duplicate' => [true, 120.0, 'invoice has multiple payments'],
    'overpayment' => [false, 60.0, 'the payment was larger than the invoice'],
    'credit paid the rest (a part payment)' => [false, 144.0, 'credit paid the rest of the invoice'],
]);

test('refunds the completed renewal payment rather than its earlier pending notification', function (): void {
    $payments = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $payments->executeStatement('CREATE TABLE payment (id INTEGER PRIMARY KEY, txn_id TEXT, gateway_id INTEGER, status TEXT, txn_status TEXT, invoice_id INTEGER, amount REAL)');
    $payments->insert('payment', ['id' => 40, 'txn_id' => 'TXN-RENEWAL', 'gateway_id' => 2, 'status' => 'received', 'txn_status' => 'Pending', 'invoice_id' => 16, 'amount' => 120]);
    $payments->insert('payment', ['id' => 41, 'txn_id' => 'TXN-RENEWAL', 'gateway_id' => 2, 'status' => 'processed', 'txn_status' => 'Completed', 'invoice_id' => 26, 'amount' => 120]);
    $db = paypalDbMocks(function (string $table, string $where, array $bindings) use ($payments): ?Model_Transaction {
        // RedBean takes ':name' keys, DBAL takes 'name'.
        $row = $payments->fetchAssociative('SELECT * FROM payment WHERE ' . $where . ' ORDER BY id ASC', array_combine(array_map(static fn (string $k): string => ltrim($k, ':'), array_keys($bindings)), $bindings));

        return $row === false ? null : paypalBean(Model_Transaction::class, $row);
    });
    $api = Mockery::mock();
    $api->shouldReceive('invoice_transaction_get')->once()->with(['id' => 42])->andReturn([
        'invoice_id' => 16, 'type' => 'subscr_payment', 'txn_id' => 'REFUND-RENEWAL',
        'txn_status' => 'Refunded', 'amount' => -120, 'currency' => 'USD',
    ]);
    $api->shouldReceive('invoice_get')->once()->with(['id' => 16])->andReturn(['id' => 16, 'client' => ['id' => 9]]);
    $api->shouldReceive('invoice_transaction_update')->twice();
    $api->shouldReceive('invoice_refund')->once()->with(['id' => 26, 'note' => 'PayPal refund TXN-RENEWAL']);
    $di = container();
    $di['db'] = $db;
    $db->shouldReceive('getExistingModelById')->with('Invoice', 26)->andReturn(paypalBean(Model_Invoice::class, ['id' => 26]));
    $invoiceService = Mockery::mock();
    $invoiceService->shouldReceive('getTotalWithTax')->andReturn(120.0);
    $invoiceService->shouldReceive('getBalanceDue')->andReturn(120.0);
    $di['mod_service'] = $di->protect(fn () => $invoiceService);

    paypalProcessAdapter($di)->processTransaction($api, 42, [
        'get' => signedPayPalGet(16, 2),
        'post' => [
            'txn_type' => 'subscr_payment', 'payment_status' => 'Refunded', 'txn_id' => 'REFUND-RENEWAL',
            'parent_txn_id' => 'TXN-RENEWAL', 'mc_gross' => -120, 'mc_currency' => 'USD',
        ],
    ], 2);
});

describe('PayPal callback invoice binding', function (): void {
    test('rejects a callback whose invoice id was swapped after signing', function (): void {
        $apiAdmin = Mockery::mock();
        $apiAdmin->shouldReceive('invoice_transaction_get')->once()->with(['id' => 42])->andReturn([
            'invoice_id' => null, 'type' => null, 'txn_id' => null,
            'txn_status' => null, 'amount' => null, 'currency' => null,
        ]);
        $apiAdmin->shouldNotReceive('invoice_transaction_update', 'invoice_get');

        $di = container();
        $di['db'] = paypalDbMocks();
        $di['logger'] = new Tests\Helpers\TestLogger();

        // Signature was issued for invoice 16; the callback URL now names 99.
        $sig = ClientFence\Tools::signCallbackParams(2, 16);

        paypalProcessAdapter($di)->processTransaction($apiAdmin, 42, [
            'post' => ['txn_type' => 'web_accept', 'payment_status' => 'Completed'],
            'get' => ['invoice_id' => 99, 'sig' => $sig],
        ], 2);
    })->throws(Payment_Exception::class, 'PayPal callback signature is invalid');

    test('rejects a callback with no signature', function (): void {
        $apiAdmin = Mockery::mock();
        $apiAdmin->shouldReceive('invoice_transaction_get')->once()->with(['id' => 42])->andReturn([
            'invoice_id' => null, 'type' => null, 'txn_id' => null,
            'txn_status' => null, 'amount' => null, 'currency' => null,
        ]);
        $apiAdmin->shouldNotReceive('invoice_transaction_update', 'invoice_get');

        $di = container();
        $di['db'] = paypalDbMocks();
        $di['logger'] = new Tests\Helpers\TestLogger();

        paypalProcessAdapter($di)->processTransaction($apiAdmin, 42, [
            'post' => ['txn_type' => 'web_accept', 'payment_status' => 'Completed'],
            'get' => ['invoice_id' => 16],
        ], 2);
    })->throws(Payment_Exception::class, 'PayPal callback signature is invalid');

    test('rejects a signature issued for a different gateway', function (): void {
        $apiAdmin = Mockery::mock();
        $apiAdmin->shouldReceive('invoice_transaction_get')->once()->with(['id' => 42])->andReturn([
            'invoice_id' => null, 'type' => null, 'txn_id' => null,
            'txn_status' => null, 'amount' => null, 'currency' => null,
        ]);
        $apiAdmin->shouldNotReceive('invoice_transaction_update', 'invoice_get');

        $di = container();
        $di['db'] = paypalDbMocks();
        $di['logger'] = new Tests\Helpers\TestLogger();

        paypalProcessAdapter($di)->processTransaction($apiAdmin, 42, [
            'post' => ['txn_type' => 'web_accept', 'payment_status' => 'Completed'],
            'get' => ['invoice_id' => 16, 'sig' => ClientFence\Tools::signCallbackParams(7, 16)],
        ], 2);
    })->throws(Payment_Exception::class, 'PayPal callback signature is invalid');

    test('binds an unbound transaction to the verified invoice', function (): void {
        $updates = [];
        $apiAdmin = Mockery::mock();
        $apiAdmin->shouldReceive('invoice_transaction_get')->once()->with(['id' => 42])->andReturn([
            'invoice_id' => null, 'type' => null, 'txn_id' => null,
            'txn_status' => null, 'amount' => null, 'currency' => null,
        ]);
        $apiAdmin->shouldReceive('invoice_get')->once()->with(['id' => 16])->andReturn([
            'id' => 16, 'currency' => 'USD', 'client' => ['id' => 9],
        ]);
        $apiAdmin->shouldReceive('invoice_transaction_update')->byDefault()->withArgs(function (array $data) use (&$updates): bool {
            $updates[] = $data;

            return true;
        });

        $di = container();
        $di['db'] = paypalDbMocks();
        $di['logger'] = new Tests\Helpers\TestLogger();

        paypalProcessAdapter($di)->processTransaction($apiAdmin, 42, [
            'post' => ['txn_type' => 'unknown_event', 'payment_status' => 'Unknown'],
            'get' => signedPayPalGet(16, 2),
        ], 2);

        $bound = array_values(array_filter($updates, fn (array $u): bool => isset($u['invoice_id'])));
        expect($bound)->toHaveCount(1)
            ->and((int) $bound[0]['invoice_id'])->toBe(16);
    });

    test('accepts an unsigned callback for a stored subscription on the same invoice', function (): void {
        $updates = [];
        $stored = paypalBean(Model_Subscription::class, ['rel_type' => 'invoice', 'rel_id' => 16]);

        $apiAdmin = Mockery::mock();
        $apiAdmin->shouldReceive('invoice_transaction_get')->once()->with(['id' => 42])->andReturn([
            'invoice_id' => 16, 'type' => null, 'txn_id' => null,
            'txn_status' => null, 'amount' => null, 'currency' => null,
        ]);
        $apiAdmin->shouldReceive('invoice_get')->once()->with(['id' => 16])->andReturn([
            'id' => 16, 'currency' => 'USD', 'client' => ['id' => 9],
        ]);
        $apiAdmin->shouldReceive('invoice_transaction_update')->byDefault()->withArgs(function (array $data) use (&$updates): bool {
            $updates[] = $data;

            return true;
        });

        $di = container();
        $di['db'] = paypalDbMocks(static fn (string $table): mixed => $table === 'Subscription' ? $stored : null);
        $di['logger'] = new Tests\Helpers\TestLogger();

        // Legacy recurring profile: callback URL predates signing, so no sig.
        // The subscr_id is only used for the binding fallback here; the
        // unknown txn type keeps the flow from touching subscriptions.
        paypalProcessAdapter($di)->processTransaction($apiAdmin, 42, [
            'post' => ['txn_type' => 'unknown_event', 'subscr_id' => 'I-LEGACY'],
            'get' => ['invoice_id' => 16],
        ], 2);

        $processed = array_values(array_filter($updates, fn (array $u): bool => ($u['status'] ?? null) === 'processed'));
        expect($processed)->toHaveCount(1);
    });

    test('rejects an unsigned callback naming a different invoice for a stored subscription', function (): void {
        $stored = paypalBean(Model_Subscription::class, ['rel_type' => 'invoice', 'rel_id' => 16]);

        $apiAdmin = Mockery::mock();
        $apiAdmin->shouldReceive('invoice_transaction_get')->once()->with(['id' => 42])->andReturn([
            'invoice_id' => 99, 'type' => null, 'txn_id' => null,
            'txn_status' => null, 'amount' => null, 'currency' => null,
        ]);
        $apiAdmin->shouldNotReceive('invoice_get');

        $di = container();
        $di['db'] = paypalDbMocks(static fn (string $table): mixed => $table === 'Subscription' ? $stored : null);
        $di['logger'] = new Tests\Helpers\TestLogger();

        paypalProcessAdapter($di)->processTransaction($apiAdmin, 42, [
            'post' => ['txn_type' => 'unknown_event', 'subscr_id' => 'I-LEGACY'],
            'get' => ['invoice_id' => 99],
        ], 2);
    })->throws(Payment_Exception::class, 'PayPal callback signature is invalid');

    test('rejects an unsigned callback for an unknown subscription', function (): void {
        $apiAdmin = Mockery::mock();
        $apiAdmin->shouldReceive('invoice_transaction_get')->once()->with(['id' => 42])->andReturn([
            'invoice_id' => 16, 'type' => null, 'txn_id' => null,
            'txn_status' => null, 'amount' => null, 'currency' => null,
        ]);
        $apiAdmin->shouldNotReceive('invoice_get');

        $di = container();
        $di['db'] = paypalDbMocks();
        $di['logger'] = new Tests\Helpers\TestLogger();

        paypalProcessAdapter($di)->processTransaction($apiAdmin, 42, [
            'post' => ['txn_type' => 'subscr_cancel', 'subscr_id' => 'I-UNKNOWN'],
            'get' => ['invoice_id' => 16],
        ], 2);
    })->throws(Payment_Exception::class, 'PayPal callback signature is invalid');

    test('still requires an invoice when none is supplied', function (): void {
        $apiAdmin = Mockery::mock();
        $apiAdmin->shouldReceive('invoice_transaction_get')->once()->with(['id' => 42])->andReturn([
            'invoice_id' => null, 'type' => null, 'txn_id' => null,
            'txn_status' => null, 'amount' => null, 'currency' => null,
        ]);
        $apiAdmin->shouldNotReceive('invoice_get');

        $di = container();
        $di['db'] = paypalDbMocks();
        $di['logger'] = new Tests\Helpers\TestLogger();

        paypalProcessAdapter($di)->processTransaction($apiAdmin, 42, [
            'post' => ['txn_type' => 'web_accept', 'payment_status' => 'Completed'],
            'get' => [],
        ], 2);
    })->throws(Payment_Exception::class, 'PayPal transaction is not associated with an invoice');

    test('accepts an unsigned refund continuing a pre-upgrade payment', function (): void {
        $refunded = [];
        $earlier = paypalBean(Model_Transaction::class, ['invoice_id' => 16, 'amount' => 120]);

        $apiAdmin = Mockery::mock();
        $apiAdmin->shouldReceive('invoice_transaction_get')->once()->with(['id' => 42])->andReturn([
            'invoice_id' => 16, 'type' => null, 'txn_id' => null,
            'txn_status' => null, 'amount' => null, 'currency' => null,
        ]);
        $apiAdmin->shouldReceive('invoice_get')->once()->with(['id' => 16])->andReturn([
            'id' => 16, 'currency' => 'USD', 'client' => ['id' => 9],
        ]);
        $apiAdmin->shouldReceive('invoice_transaction_update')->byDefault()->andReturnTrue();
        $apiAdmin->shouldReceive('invoice_refund')->once()->withArgs(function (array $data) use (&$refunded): bool {
            $refunded[] = $data;

            return true;
        });

        $di = container();
        $di['db'] = paypalDbMocks(static fn (string $table, string $where, array $bindings): mixed => $table === 'Transaction' && ($bindings[':txn'] ?? null) === 'TXN-ORIG' ? $earlier : null);
        $di['db']->shouldReceive('getExistingModelById')->with('Invoice', 16)->andReturn(paypalBean(Model_Invoice::class, ['id' => 16]));
        $invoiceService = Mockery::mock();
        $invoiceService->shouldReceive('getTotalWithTax')->andReturn(120.0);
        $invoiceService->shouldReceive('getBalanceDue')->andReturn(120.0);
        $di['mod_service'] = $di->protect(fn () => $invoiceService);
        $di['logger'] = new Tests\Helpers\TestLogger();

        // Refund IPN for a pre-upgrade payment: new txn id, unsigned URL,
        // but parent_txn_id names the original recorded payment.
        paypalProcessAdapter($di)->processTransaction($apiAdmin, 42, [
            'post' => [
                'txn_type' => 'web_accept',
                'payment_status' => 'Refunded',
                'txn_id' => 'REFUND-9',
                'parent_txn_id' => 'TXN-ORIG',
                'mc_gross' => '-120.00',
                'mc_currency' => 'USD',
            ],
            'get' => ['invoice_id' => 16],
        ], 2);

        expect($refunded)->toHaveCount(1)
            ->and($refunded[0]['id'])->toBe(16);
    });

    test('accepts an unsigned delayed completion with a recorded pending payment', function (): void {
        $funds = [];
        $earlier = paypalBean(Model_Transaction::class, ['invoice_id' => 16]);

        $apiAdmin = Mockery::mock();
        $apiAdmin->shouldReceive('invoice_transaction_get')->twice()->with(['id' => 42])->andReturn(
            ['invoice_id' => 16, 'type' => null, 'txn_id' => null, 'txn_status' => null, 'amount' => null, 'currency' => null, 'status' => 'received'],
            ['invoice_id' => 16, 'type' => 'web_accept', 'txn_id' => 'TXN-E', 'txn_status' => 'Completed', 'amount' => '120.00', 'currency' => 'USD', 'status' => 'processing']
        );
        $apiAdmin->shouldNotReceive('invoice_transaction_claim_for_processing');
        $apiAdmin->shouldReceive('invoice_get')->once()->with(['id' => 16])->andReturn([
            'id' => 16, 'currency' => 'USD', 'client' => ['id' => 9],
        ]);
        $apiAdmin->shouldReceive('invoice_transaction_update')->byDefault()->andReturnTrue();
        $apiAdmin->shouldReceive('client_balance_add_funds')->once()->withArgs(function (array $data) use (&$funds): bool {
            $funds[] = $data;

            return true;
        });
        $apiAdmin->shouldReceive('invoice_pay_with_credits')->once()->with(['id' => 16]);

        $invoiceBean = paypalBean(Model_Invoice::class, [
            'id' => 16,
            'nr' => '00042',
            'status' => Model_Invoice::STATUS_UNPAID,
            'approved' => true,
        ]);

        $invoiceService = Mockery::mock();
        $invoiceService->shouldReceive('getTotalWithTax')->andReturn(120.00);
        $invoiceService->shouldReceive('getBalanceDue')->once()->andReturn(120.00);
        $invoiceService->shouldReceive('validatePaymentAmount')->once()->with(120.00, 120.00, Mockery::type(Model_Invoice::class))->andReturnNull();
        $invoiceService->shouldReceive('isInvoiceTypeDeposit')->once()->andReturn(false);

        $dbMock = paypalDbMocks(static fn (string $table): mixed => $table === 'Transaction' ? $earlier : null);
        $dbMock->shouldReceive('load')->with('Invoice', 16)->andReturn($invoiceBean);
        $di = container();
        $di['db'] = $dbMock;
        $di['logger'] = new Tests\Helpers\TestLogger();
        $di['mod_service'] = $di->protect(static fn (): object => $invoiceService);

        // eCheck-style delayed completion: unsigned pre-upgrade URL, but the
        // same PayPal transaction was already recorded for this invoice.
        paypalProcessAdapter($di)->processTransaction($apiAdmin, 42, [
            'post' => [
                'txn_type' => 'web_accept',
                'payment_status' => 'Completed',
                'txn_id' => 'TXN-E',
                'mc_gross' => '120.00',
                'mc_currency' => 'USD',
            ],
            'get' => ['invoice_id' => 16],
        ], 2);

        expect($funds)->toHaveCount(1);
    });

    test('rejects an unsigned callback whose earlier payment belongs to another invoice', function (): void {
        $earlier = paypalBean(Model_Transaction::class, ['invoice_id' => 16]);

        $apiAdmin = Mockery::mock();
        $apiAdmin->shouldReceive('invoice_transaction_get')->once()->with(['id' => 42])->andReturn([
            'invoice_id' => 99, 'type' => null, 'txn_id' => null,
            'txn_status' => null, 'amount' => null, 'currency' => null,
        ]);
        $apiAdmin->shouldNotReceive('invoice_get');

        $di = container();
        $di['db'] = paypalDbMocks(static fn (string $table): mixed => $table === 'Transaction' ? $earlier : null);
        $di['logger'] = new Tests\Helpers\TestLogger();

        paypalProcessAdapter($di)->processTransaction($apiAdmin, 42, [
            'post' => ['txn_type' => 'web_accept', 'payment_status' => 'Completed', 'txn_id' => 'TXN-X'],
            'get' => ['invoice_id' => 99],
        ], 2);
    })->throws(Payment_Exception::class, 'PayPal callback signature is invalid');

    test('warns about a stale display number while accepting the stable invoice reference', function (): void {
        $adapter = new Payment_Adapter_PayPalEmail(['email' => 'merchant@example.com', 'test_mode' => false]);
        $di = container();
        $logger = Mockery::mock(Psr\Log\LoggerInterface::class);
        $logger->shouldReceive('warning')->once()->with(Mockery::pattern('/invoice 16/'));
        $di['logger'] = $logger;
        $adapter->setDi($di);
        invokePrivateMethod($adapter, 'verifyIpnEcho', [
            ['item_number' => 'old', 'invoice' => '16'],
            paypalBean(Model_Invoice::class, ['id' => 16, 'nr' => 'new']),
        ]);
    });

    test('accepts a completed payment whose echoed references match the invoice', function (): void {
        $funds = [];
        $apiAdmin = Mockery::mock();
        $apiAdmin->shouldReceive('invoice_transaction_get')->twice()->with(['id' => 42])->andReturn(
            ['invoice_id' => 16, 'type' => null, 'txn_id' => null, 'txn_status' => null, 'amount' => null, 'currency' => null, 'status' => 'received'],
            ['invoice_id' => 16, 'type' => 'web_accept', 'txn_id' => 'TXN-9', 'txn_status' => 'Completed', 'amount' => '120.00', 'currency' => 'USD', 'status' => 'processing']
        );
        $apiAdmin->shouldNotReceive('invoice_transaction_claim_for_processing');
        $apiAdmin->shouldReceive('invoice_get')->once()->with(['id' => 16])->andReturn([
            'id' => 16, 'currency' => 'USD', 'client' => ['id' => 9],
        ]);
        $apiAdmin->shouldReceive('invoice_transaction_update')->byDefault()->andReturnTrue();
        $apiAdmin->shouldReceive('client_balance_add_funds')->once()->withArgs(function (array $data) use (&$funds): bool {
            $funds[] = $data;

            return true;
        });
        $apiAdmin->shouldReceive('invoice_pay_with_credits')->once()->with(['id' => 16]);

        $invoiceBean = paypalBean(Model_Invoice::class, [
            'id' => 16,
            'nr' => '00042',
            'status' => Model_Invoice::STATUS_UNPAID,
            'approved' => true,
        ]);

        $invoiceService = Mockery::mock();
        $invoiceService->shouldReceive('getTotalWithTax')->andReturn(120.00);
        $invoiceService->shouldReceive('getBalanceDue')->once()->andReturn(120.00);
        $invoiceService->shouldReceive('validatePaymentAmount')->once()->with(120.00, 120.00, Mockery::type(Model_Invoice::class))->andReturnNull();
        $invoiceService->shouldReceive('isInvoiceTypeDeposit')->once()->andReturn(false);

        $dbMock = paypalDbMocks();
        $dbMock->shouldReceive('load')->with('Invoice', 16)->andReturn($invoiceBean);
        $di = container();
        $di['db'] = $dbMock;
        $di['logger'] = new Tests\Helpers\TestLogger();
        $di['mod_service'] = $di->protect(static fn (): object => $invoiceService);

        paypalProcessAdapter($di)->processTransaction($apiAdmin, 42, [
            'post' => [
                'txn_type' => 'web_accept',
                'payment_status' => 'Completed',
                'txn_id' => 'TXN-9',
                'mc_gross' => '120.00',
                'mc_currency' => 'USD',
                'item_number' => '00042',
            ],
            'get' => signedPayPalGet(16, 2),
        ], 2);

        expect($funds)->toHaveCount(1);
    });

    test('processes a completed payment without re-claiming the row', function (): void {
        $updates = [];
        $apiAdmin = Mockery::mock();
        $apiAdmin->shouldReceive('invoice_transaction_get')->twice()->with(['id' => 42])->andReturn(
            ['invoice_id' => 16, 'type' => null, 'txn_id' => null, 'txn_status' => null, 'amount' => null, 'currency' => null, 'status' => 'received'],
            ['invoice_id' => 16, 'type' => 'web_accept', 'txn_id' => 'TXN-1', 'txn_status' => 'Completed', 'amount' => '120.00', 'currency' => 'USD', 'status' => 'processing']
        );
        $apiAdmin->shouldReceive('invoice_get')->once()->with(['id' => 16])->andReturn([
            'id' => 16, 'currency' => 'USD', 'client' => ['id' => 9],
        ]);
        $apiAdmin->shouldReceive('invoice_transaction_update')->byDefault()->withArgs(function (array $data) use (&$updates): bool {
            $updates[] = $data;

            return true;
        });
        $apiAdmin->shouldNotReceive('invoice_transaction_claim_for_processing');
        $apiAdmin->shouldReceive('client_balance_add_funds')->once();
        $apiAdmin->shouldReceive('invoice_pay_with_credits')->once()->with(['id' => 16]);

        $invoiceBean = paypalBean(Model_Invoice::class, [
            'id' => 16,
            'nr' => '00042',
            'status' => Model_Invoice::STATUS_UNPAID,
            'approved' => true,
        ]);

        $invoiceService = Mockery::mock();
        $invoiceService->shouldReceive('getTotalWithTax')->andReturn(120.00);
        $invoiceService->shouldReceive('getBalanceDue')->once()->andReturn(120.00);
        $invoiceService->shouldReceive('validatePaymentAmount')->once()->andReturnNull();
        $invoiceService->shouldReceive('isInvoiceTypeDeposit')->once()->andReturn(false);

        $dbMock = paypalDbMocks();
        $dbMock->shouldReceive('load')->with('Invoice', 16)->andReturn($invoiceBean);
        $di = container();
        $di['db'] = $dbMock;
        $di['logger'] = new Tests\Helpers\TestLogger();
        $di['mod_service'] = $di->protect(static fn (): object => $invoiceService);

        paypalProcessAdapter($di)->processTransaction($apiAdmin, 42, [
            'post' => [
                'txn_type' => 'web_accept',
                'payment_status' => 'Completed',
                'txn_id' => 'TXN-1',
                'mc_gross' => '120.00',
                'mc_currency' => 'USD',
                'item_number' => '00042',
            ],
            'get' => signedPayPalGet(16, 2),
        ], 2);

        $processed = array_values(array_filter($updates, fn (array $u): bool => ($u['status'] ?? null) === 'processed'));
        expect($processed)->toHaveCount(1);
    });

    test('getInvoiceId resolves a signed callback and rejects a tampered one', function (): void {
        $di = container();
        $di['db'] = paypalDbMocks();
        $di['logger'] = new Tests\Helpers\TestLogger();

        $adapter = new Payment_Adapter_PayPalEmail(['email' => 'merchant@example.com', 'test_mode' => false, 'gateway_id' => 2]);
        $adapter->setDi($di);

        expect($adapter->getInvoiceId(['get' => signedPayPalGet(16, 2)]))->toBe(16);
        expect($adapter->getInvoiceId(['get' => []]))->toBeNull();
        expect(fn (): mixed => $adapter->getInvoiceId(['get' => ['invoice_id' => 99, 'sig' => ClientFence\Tools::signCallbackParams(2, 16)]]))
            ->toThrow(Payment_Exception::class, 'PayPal callback signature is invalid');
    });

    test('payment forms sign the callback URL for the paying invoice', function (): void {
        $di = container();
        $di['db'] = paypalDbMocks();
        $di['logger'] = new Tests\Helpers\TestLogger();

        $adapter = new Payment_Adapter_PayPalEmail([
            'email' => 'merchant@example.com',
            'test_mode' => false,
            'gateway_id' => 2,
            'thankyou_url' => 'https://example.com/thank-you',
            'cancel_url' => 'https://example.com/cancel',
            'notify_url' => 'https://example.com/ipn.php?gateway_id=2&invoice_id=16',
        ]);
        $adapter->setDi($di);

        $invoice = [
            'id' => 16,
            'nr' => '00042',
            'serie' => 'FB',
            'currency' => 'USD',
            'subtotal' => '100.00',
            'tax' => '20.00',
            'lines' => [['title' => 'Test product']],
        ];

        $fields = $adapter->getOneTimePaymentFields($invoice);
        parse_str((string) parse_url($fields['notify_url'], PHP_URL_QUERY), $query);

        expect((int) ($query['invoice_id'] ?? 0))->toBe(16)
            ->and(ClientFence\Tools::verifyCallbackSignature(2, 16, $query['sig'] ?? null))->toBeTrue();
    });
});

test('rejects array-valued IPN fields before verification', function (): void {
    $adapter = buildPayPalEmailAdapter('merchant@example.com', 'VERIFIED');
    expect(callIsIpnValid($adapter, 'receiver_email=merchant%40example.com&item_number[]=1'))->toBeFalse();
});

/** A PayPal Email adapter with sandbox REST keys whose HTTP calls go to $responses (path => [status, body]), recorded in $calls. */
function paypalRestAdapter(array $responses, ?array &$calls = [], ?object $db = null): Payment_Adapter_PayPalEmail
{
    $calls = [];
    $http = new Symfony\Component\HttpClient\MockHttpClient(function (string $method, string $url, array $options) use ($responses, &$calls): Symfony\Component\HttpClient\Response\MockResponse {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $calls[] = [$method, $url, json_decode((string) ($options['body'] ?? ''), true)];
        [$status, $body] = $responses[$method . ' ' . $path] ?? $responses[$path] ?? [500, []];

        return new Symfony\Component\HttpClient\Response\MockResponse($status === 204 ? '' : (string) json_encode($body), ['http_code' => $status]);
    });
    $adapter = new Payment_Adapter_PayPalEmail(['email' => 'merchant@example.com', 'test_mode' => true, 'test_api_client_id' => 'sandbox-id', 'test_api_secret' => 'sandbox-secret']);
    $di = container();
    $di['http_client'] = $http;
    if ($db !== null) {
        $di['db'] = $db;
    }
    $adapter->setDi($di);

    return $adapter;
}

const PAYPAL_TOKEN = ['/v1/oauth2/token' => [200, ['access_token' => 'token']]];

test('uses the REST API keys of the current mode only', function (): void {
    $keys = ['email' => 'merchant@example.com', 'api_client_id' => 'live-id', 'api_secret' => 'live-secret'];

    expect((new Payment_Adapter_PayPalEmail($keys + ['test_mode' => false]))->hasApiKeys())->toBeTrue()
        ->and((new Payment_Adapter_PayPalEmail($keys + ['test_mode' => true]))->hasApiKeys())->toBeFalse()
        ->and((new Payment_Adapter_PayPalEmail(['email' => 'merchant@example.com', 'api_client_id' => 'live-id', 'test_mode' => false]))->hasApiKeys())->toBeFalse();
    expect(fn () => (new Payment_Adapter_PayPalEmail(['email' => 'merchant@example.com', 'test_mode' => false]))->cancelSubscription('I-ABC'))
        ->toThrow(ClientFence\InformationException::class, 'Cancel subscription I-ABC in PayPal');
});

test('cancels a PayPal subscription through the REST API', function (int $status, ?string $after, ?string $error): void {
    $adapter = paypalRestAdapter(PAYPAL_TOKEN + [
        'POST /v1/billing/subscriptions/I-ABC/cancel' => [$status, $status === 204 ? [] : ['name' => 'UNPROCESSABLE_ENTITY', 'message' => 'The requested action could not be performed.', 'details' => [['issue' => 'SUBSCRIPTION_STATUS_INVALID']]]],
        'GET /v1/billing/subscriptions/I-ABC' => [200, ['status' => $after]],
    ], $calls);

    $error === null ? $adapter->cancelSubscriptionAtPeriodEnd('I-ABC') : expect(fn () => $adapter->cancelSubscription('I-ABC'))->toThrow(ClientFence\InformationException::class, $error);
    expect($calls[0][1])->toBe('https://api-m.sandbox.paypal.com/v1/oauth2/token')
        ->and($calls[1][2])->toBe(['reason' => 'Cancelled in ClientFence']);
})->with([
    'cancelled' => [204, null, null],
    'already cancelled at PayPal' => [422, 'CANCELLED', null],
    'refused' => [422, 'ACTIVE', 'PayPal did not cancel subscription I-ABC: The requested action could not be performed. (SUBSCRIPTION_STATUS_INVALID)'],
]);

test('refunds a PayPal payment through the REST API', function (): void {
    $adapter = paypalRestAdapter(PAYPAL_TOKEN + ['POST /v2/payments/captures/97452580WT6535333/refund' => [201, ['id' => '1JU08902781691411', 'status' => 'COMPLETED']]], $calls);

    expect($adapter->refundPayment('97452580WT6535333', 0.4, 'USD'))->toBe('1JU08902781691411')
        ->and($calls[1][2])->toBe(['amount' => ['value' => '0.40', 'currency_code' => 'USD']]);

    $refused = paypalRestAdapter(PAYPAL_TOKEN + ['POST /v2/payments/captures/97452580WT6535333/refund' => [422, ['message' => 'The requested action could not be performed.', 'details' => [['issue' => 'CAPTURE_FULLY_REFUNDED', 'description' => 'The capture has already been fully refunded']]]]]);
    expect(fn () => $refused->refundPayment('97452580WT6535333', 1.0, 'USD'))
        ->toThrow(ClientFence\InformationException::class, 'PayPal did not refund payment 97452580WT6535333: The requested action could not be performed. (The capture has already been fully refunded)');

    $badKeys = paypalRestAdapter(['/v1/oauth2/token' => [401, ['error' => 'invalid_client', 'error_description' => 'Client Authentication failed']]]);
    expect(fn () => $badKeys->testConnection())->toThrow(ClientFence\InformationException::class, 'PayPal did not accept the REST API keys: Client Authentication failed');
});

test('records nothing more for the IPN of a refund staff sent from ClientFence', function (): void {
    $tx = ['invoice_id' => 16, 'type' => 'web_accept', 'txn_id' => '1JU08902781691411', 'txn_status' => 'Refunded', 'amount' => -1, 'currency' => 'USD'];
    $api = Mockery::mock();
    $api->shouldReceive('invoice_transaction_get')->once()->with(['id' => 42])->andReturn($tx);
    $api->shouldReceive('invoice_get')->once()->with(['id' => 16])->andReturn(['id' => 16, 'client' => ['id' => 9]]);
    $api->shouldReceive('invoice_transaction_update')->andReturnUsing(function (array $update) use (&$tx): void {
        $tx = array_replace($tx, $update);
    });
    $api->shouldNotReceive('invoice_refund');
    $db = paypalDbMocks();
    $db->shouldReceive('getCell')->once()->with('SELECT 1 FROM invoice WHERE notes LIKE ? LIMIT 1', ['%PayPal refund 1JU08902781691411 %'])->andReturn('1');
    $db->shouldNotReceive('findOne');
    $di = container();
    $di['db'] = $db;

    paypalProcessAdapter($di)->processTransaction($api, 42, [
        'get' => signedPayPalGet(16, 2),
        'post' => ['txn_type' => 'web_accept', 'payment_status' => 'Refunded', 'txn_id' => '1JU08902781691411', 'parent_txn_id' => '97452580WT6535333', 'mc_gross' => -1, 'mc_currency' => 'USD'],
    ], 2);

    expect($tx['status'])->toBe(Model_Transaction::STATUS_PROCESSED)
        ->and($tx['error'])->toBe('');
});

test('finds a PayPal subscription it can still cancel', function (int $status, string $state, bool $can): void {
    $adapter = paypalRestAdapter(PAYPAL_TOKEN + ['GET /v1/billing/subscriptions/I-ABC' => [$status, $status === 200 ? ['status' => $state] : ['name' => 'RESOURCE_NOT_FOUND']]]);

    expect($adapter->canCancelSubscription('I-ABC'))->toBe($can);
})->with([
    'active' => [200, 'ACTIVE', true],
    'suspended' => [200, 'SUSPENDED', true],
    'already cancelled' => [200, 'CANCELLED', false],
    'unknown to the REST API (old S- profile, another account)' => [404, '', false],
]);

/** An in-memory pay_gateway_customer table for saved PayPal accounts. */
function paypalVaultDb(): Doctrine\DBAL\Connection
{
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE pay_gateway_customer (id INTEGER PRIMARY KEY, pay_gateway_id INTEGER, client_id INTEGER, test_mode INTEGER, external_customer_id TEXT, saved_cards INTEGER DEFAULT 0, default_payment_method TEXT, default_card TEXT, auto_pay_at TEXT, created_at TEXT, updated_at TEXT)');

    return $db;
}

/** A sandbox adapter with saved PayPal accounts on (gateway 2) over $db and the given REST answers. */
function paypalVaultAdapter(array $responses, ?array &$calls, Doctrine\DBAL\Connection $db, ?object $invoices = null, ?object $redbean = null): Payment_Adapter_PayPalEmail
{
    $calls = [];
    $http = new Symfony\Component\HttpClient\MockHttpClient(function (string $method, string $url, array $options) use ($responses, &$calls): Symfony\Component\HttpClient\Response\MockResponse {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $headers = implode("\n", $options['headers'] ?? []);
        $calls[] = [$method, $path, json_decode((string) ($options['body'] ?? ''), true), $headers];
        [$status, $body] = $responses[$method . ' ' . $path] ?? $responses[$path] ?? [500, []];

        return new Symfony\Component\HttpClient\Response\MockResponse($status === 204 ? '' : (string) json_encode($body), ['http_code' => $status]);
    });
    $adapter = new Payment_Adapter_PayPalEmail(['email' => 'merchant@example.com', 'test_mode' => true, 'gateway_id' => 2, 'vault' => '1', 'test_api_client_id' => 'sandbox-id', 'test_api_secret' => 'sandbox-secret']);
    $di = container();
    $di['http_client'] = $http;
    $di['dbal'] = $db;
    $di['logger'] = new Tests\Helpers\TestLogger();
    if ($invoices !== null) {
        $di['mod_service'] = $di->protect(fn () => $invoices);
    }
    if ($redbean !== null) {
        $di['db'] = $redbean;
    }
    $adapter->setDi($di);

    return $adapter;
}

function paypalVaultInvoice(): Model_Invoice
{
    return paypalBean(Model_Invoice::class, ['id' => 16, 'client_id' => 9, 'currency' => 'USD', 'nr' => 42, 'serie' => 'CF', 'approved' => 1]);
}

function paypalVaultInvoices(): Mockery\MockInterface
{
    $invoices = Mockery::mock();
    $invoices->allows('getBalanceDue')->andReturn(12.5);
    $invoices->allows('toApiArray')->andReturn(['nr' => 42, 'serie' => 'CF', 'lines' => [['title' => 'Hosting']]]);

    return $invoices;
}

test('saved PayPal accounts need the setting and the REST API keys', function (): void {
    $keys = ['email' => 'merchant@example.com', 'test_mode' => false, 'api_client_id' => 'id', 'api_secret' => 'secret'];

    expect((new Payment_Adapter_PayPalEmail($keys + ['vault' => '1']))->canVault())->toBeTrue()
        ->and((new Payment_Adapter_PayPalEmail($keys + ['vault' => '0']))->canVault())->toBeFalse()
        ->and((new Payment_Adapter_PayPalEmail(['email' => 'merchant@example.com', 'vault' => '1']))->canVault())->toBeFalse();
});

test('a saved PayPal account is kept from an approved setup, and a newer one replaces it at PayPal too', function (): void {
    $db = paypalVaultDb();
    $token = fn (string $id): array => [200, ['id' => $id, 'customer' => ['id' => 'CUST-9'], 'payment_source' => ['paypal' => ['email_address' => 'buyer@example.com']]]];
    $adapter = paypalVaultAdapter(PAYPAL_TOKEN + ['POST /v3/vault/payment-tokens' => $token('TOKEN-1'), 'DELETE /v3/vault/payment-tokens/TOKEN-1' => [204, []]], $calls, $db);

    $adapter->vaultSaved(9, 'SETUP-1');
    expect($calls[1][2])->toBe(['payment_source' => ['token' => ['id' => 'SETUP-1', 'type' => 'SETUP_TOKEN']]])
        ->and($adapter->savedCard(9))->toMatchArray(['payment_method' => 'TOKEN-1', 'card' => 'PayPal buyer@example.com']);

    $adapter = paypalVaultAdapter(PAYPAL_TOKEN + ['POST /v3/vault/payment-tokens' => $token('TOKEN-2'), 'DELETE /v3/vault/payment-tokens/TOKEN-1' => [204, []]], $calls, $db);
    $adapter->vaultSaved(9, 'SETUP-2');
    expect($adapter->savedCard(9)['payment_method'])->toBe('TOKEN-2')
        ->and(array_column($calls, 1))->toContain('/v3/vault/payment-tokens/TOKEN-1')
        ->and($db->fetchOne('SELECT COUNT(*) FROM pay_gateway_customer'))->toBe(1);

    $adapter->vaultRemove(9);
    expect($adapter->savedCard(9))->toBeNull();
});

test('the account owner\'s invoice offers to pay now with the saved PayPal account, else to pay and save one (GitHub #6)', function (bool $saved): void {
    $db = paypalVaultDb();
    if ($saved) {
        $db->insert('pay_gateway_customer', ['pay_gateway_id' => 2, 'client_id' => 9, 'test_mode' => 1, 'external_customer_id' => 'CUST-9', 'saved_cards' => 1, 'default_payment_method' => 'TOKEN-1', 'default_card' => 'PayPal buyer@example.com']);
    }
    $invoice = paypalBean(Model_Invoice::class, ['id' => 16, 'client_id' => 9, 'currency' => 'USD', 'nr' => 42, 'serie' => 'CF', 'hash' => 'hash16', 'status' => 'unpaid']);
    $redbean = Mockery::mock(ClientFence\Database::class);
    $redbean->allows('load')->with('Invoice', 16)->andReturn($invoice);
    $invoices = Mockery::mock();
    $invoices->allows('toApiArray')->andReturn(['id' => 16, 'nr' => 42, 'serie' => 'CF', 'currency' => 'USD', 'subtotal' => 10, 'tax' => 0, 'status' => 'unpaid', 'lines' => [['title' => 'Hosting']]]);
    $adapter = paypalVaultAdapter(PAYPAL_TOKEN, $calls, $db, $invoices, $redbean);
    setPrivateProperty($adapter, 'config', ['email' => 'merchant@example.com', 'test_mode' => true, 'gateway_id' => 2, 'vault' => '1', 'test_api_client_id' => 'sandbox-id', 'test_api_secret' => 'sandbox-secret', 'thankyou_url' => 'https://billing.example/thanks', 'cancel_url' => 'https://billing.example/cancel', 'notify_url' => 'https://billing.example/ipn.php']);
    $adapter->getDi()['session']->shouldReceive('get')->with('client_id')->andReturn(9);
    $adapter->getDi()['url'] = new class {
        public function link(string $path): string
        {
            return 'https://billing.example/' . $path;
        }
    };

    $html = $adapter->getHtml(null, 16, false);
    expect(str_contains($html, 'invoice/paypal_pay') && str_contains($html, 'PayPal buyer@example.com'))->toBe($saved)
        ->and(str_contains($html, 'invoice/paypal-save/hash16'))->toBe(!$saved);
})->with(['saved' => [true], 'none saved' => [false]]);

test('a saved PayPal account pays the invoice balance with its own request ID per try, and PayPal\'s refusal is the client\'s reason', function (): void {
    $db = paypalVaultDb();
    $db->insert('pay_gateway_customer', ['pay_gateway_id' => 2, 'client_id' => 9, 'test_mode' => 1, 'external_customer_id' => 'CUST-9', 'saved_cards' => 1, 'default_payment_method' => 'TOKEN-1', 'default_card' => 'PayPal buyer@example.com']);
    $paid = [201, ['id' => 'ORDER-1', 'status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [['id' => 'CAPTURE-1', 'status' => 'COMPLETED']]]]]]];
    $adapter = paypalVaultAdapter(PAYPAL_TOKEN + ['POST /v2/checkout/orders' => $paid], $calls, $db, paypalVaultInvoices());

    expect($adapter->chargeSavedCard(paypalVaultInvoice(), 'attempt_2'))->toBe('CAPTURE-1')
        ->and($calls[1][2]['payment_source'])->toBe(['paypal' => ['vault_id' => 'TOKEN-1']])
        ->and($calls[1][2]['purchase_units'][0])->toMatchArray(['custom_id' => 'cf-invoice-16', 'amount' => ['currency_code' => 'USD', 'value' => '12.50']])
        ->and($calls[1][3])->toContain('PayPal-Request-Id: cf-invoice-16-attempt_2');

    $refused = paypalVaultAdapter(PAYPAL_TOKEN + ['POST /v2/checkout/orders' => [422, ['message' => 'The requested action could not be performed.', 'details' => [['issue' => 'INSTRUMENT_DECLINED', 'description' => 'The instrument presented was declined.']]]]], $calls, $db, paypalVaultInvoices());
    expect(fn () => $refused->chargeSavedCard(paypalVaultInvoice(), 'attempt_1'))
        ->toThrow(ClientFence\InformationException::class, 'PayPal refused the payment: The requested action could not be performed. (The instrument presented was declined.)');

    // An order made but its capture declined is a refusal too, not a payment taken.
    $declined = paypalVaultAdapter(PAYPAL_TOKEN + ['POST /v2/checkout/orders' => [201, ['id' => 'ORDER-2', 'status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [['id' => 'CAPTURE-2', 'status' => 'DECLINED']]]]]]]], $calls, $db, paypalVaultInvoices());
    expect(fn () => $declined->chargeSavedCard(paypalVaultInvoice(), 'attempt_1'))->toThrow(ClientFence\InformationException::class, 'PayPal refused the payment: DECLINED');
});

test('a REST payment is read back from PayPal and pays only an invoice made here', function (string $customId, string $status, ?string $error, bool $processed = false): void {
    $capture = ['id' => 'CAPTURE-1', 'status' => $status, 'custom_id' => $customId, 'amount' => ['value' => '12.50', 'currency_code' => 'USD']];
    $invoices = paypalVaultInvoices();
    $invoices->allows('isInvoiceTypeDeposit')->andReturnFalse();
    $invoices->allows('validatePaymentAmount');
    // The duplicate check finds this very transaction once it was applied ("Process Again").
    $redbean = paypalDbMocks(fn (string $model, string $sql, array $bindings): ?Model_Transaction => $processed && $model === 'Transaction' && !isset($bindings[':id']) ? new Model_Transaction() : null);
    $redbean->allows('getExistingModelById')->with('Invoice', 16, 'Invoice not found')->andReturn(paypalVaultInvoice());
    $adapter = paypalVaultAdapter(PAYPAL_TOKEN + ['GET /v2/payments/captures/CAPTURE-1' => [200, $capture]], $calls, paypalVaultDb(), $invoices, $redbean);
    $tx = [];
    $api = Mockery::mock();
    $api->allows('invoice_transaction_update')->andReturnUsing(function (array $update) use (&$tx): void {
        $tx = array_replace($tx, $update);
    });
    $api->shouldReceive('client_balance_add_funds')->times($error === null && $status === 'COMPLETED' ? 1 : 0)->with(['id' => 9, 'amount' => 12.5, 'description' => 'PayPal transaction CAPTURE-1', 'type' => 'PayPal', 'rel_id' => 'CAPTURE-1']);
    $api->shouldReceive('invoice_pay_with_credits')->times($error === null && $status === 'COMPLETED' ? 1 : 0)->with(['id' => 16]);

    $run = fn () => $adapter->processTransaction($api, 42, ['get' => ['paypal_capture' => 'CAPTURE-1', 'invoice_id' => 16]], 2);
    if ($error !== null) {
        expect($run)->toThrow(Payment_Exception::class, $error);

        return;
    }
    $run();
    expect($tx)->toMatchArray($status === 'COMPLETED'
        ? ['invoice_id' => 16, 'txn_id' => 'CAPTURE-1', 'txn_status' => 'Completed', 'status' => Model_Transaction::STATUS_PROCESSED]
        : ['txn_status' => 'Pending', 'status' => Model_Transaction::STATUS_RECEIVED, 'error' => 'PayPal payment not completed: PENDING']);
})->with([
    'completed' => ['cf-invoice-16', 'COMPLETED', null],
    'held by PayPal' => ['cf-invoice-16', 'PENDING', null],
    'another shop on the same PayPal account' => ['16', 'COMPLETED', 'is not for a ClientFence invoice'],
    'processed again after it was applied' => ['cf-invoice-16', 'COMPLETED', 'Cannot process duplicate PayPal payment', true],
]);

test('"Pay and save" takes the approved order\'s payment for its own invoice and keeps the account', function (): void {
    $db = paypalVaultDb();
    $order = fn (string $customId): array => [201, [
        'id' => 'ORDER-1', 'status' => 'COMPLETED',
        'payment_source' => ['paypal' => ['email_address' => 'buyer@example.com', 'attributes' => ['vault' => ['id' => 'TOKEN-1', 'status' => 'VAULTED', 'customer' => ['id' => 'CUST-9']]]]],
        'purchase_units' => [['custom_id' => $customId, 'payments' => ['captures' => [['id' => 'CAPTURE-1', 'status' => 'COMPLETED']]]]],
    ]];
    $adapter = paypalVaultAdapter(PAYPAL_TOKEN + ['POST /v2/checkout/orders/ORDER-1/capture' => $order('cf-invoice-16')], $calls, $db);

    expect($adapter->vaultCapture(paypalVaultInvoice(), 'ORDER-1'))->toBe('CAPTURE-1')
        ->and($adapter->savedCard(9))->toMatchArray(['payment_method' => 'TOKEN-1', 'card' => 'PayPal buyer@example.com']);
    // PayPal refuses a capture without the JSON content type (415) and leaves custom_id out of its short reply.
    $capture = array_values(array_filter($calls, fn (array $call): bool => $call[1] === '/v2/checkout/orders/ORDER-1/capture'))[0];
    expect($capture[3])->toContain('Content-Type: application/json')->toContain('Prefer: return=representation');

    $other = paypalVaultAdapter(PAYPAL_TOKEN + ['POST /v2/checkout/orders/ORDER-1/capture' => $order('cf-invoice-99')], $calls, paypalVaultDb());
    expect(fn () => $other->vaultCapture(paypalVaultInvoice(), 'ORDER-1'))->toThrow(ClientFence\InformationException::class, 'PayPal did not take the payment');
});
