<?php

declare(strict_types=1);

/**
 * ClientFence: saved cards (Invoice/SavedCards.php): the days of the tries, which invoices the cron
 * charges, and what a failed try does. In-memory SQLite and a fake Stripe adapter.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Invoice\SavedCards;

function savedCardsWorld(): object
{
    $world = new stdClass();
    $world->charged = [];
    $world->recorded = [];
    $world->emails = [];
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE invoice (id INTEGER PRIMARY KEY, client_id INTEGER, status TEXT, approved INTEGER, due_at TEXT, gateway_id INTEGER)');
    $db->executeStatement('CREATE TABLE invoice_item (id INTEGER PRIMARY KEY, invoice_id INTEGER, type TEXT, rel_id INTEGER)');
    $db->executeStatement('CREATE TABLE subscription (id INTEGER PRIMARY KEY, client_id INTEGER, pay_gateway_id INTEGER, sid TEXT, rel_type TEXT, rel_id INTEGER, status TEXT, updated_at TEXT)');
    $db->executeStatement('CREATE TABLE pay_gateway_customer (id INTEGER PRIMARY KEY, pay_gateway_id INTEGER, client_id INTEGER, test_mode INTEGER, external_customer_id TEXT, saved_cards INTEGER DEFAULT 0, default_payment_method TEXT, default_card TEXT, auto_pay_at TEXT, created_at TEXT, updated_at TEXT)');
    $db->executeStatement('CREATE TABLE invoice_card_charge (invoice_id INTEGER PRIMARY KEY, attempts INTEGER NOT NULL DEFAULT 0, last_attempt_at TEXT, last_error TEXT)');
    $db->executeStatement('CREATE TABLE `transaction` (id INTEGER PRIMARY KEY, invoice_id INTEGER, status TEXT, txn_status TEXT, amount TEXT, currency TEXT, created_at TEXT)');
    $db->insert('pay_gateway_customer', ['pay_gateway_id' => 1, 'client_id' => 7, 'test_mode' => 0, 'external_customer_id' => 'cus_7', 'saved_cards' => 1, 'default_payment_method' => 'pm_7', 'default_card' => 'Visa •••• 4242 (12/2030)']);
    $db->insert('pay_gateway_customer', ['pay_gateway_id' => 1, 'client_id' => 8, 'test_mode' => 0, 'external_customer_id' => 'cus_8', 'saved_cards' => 1]);
    // A subscription customer found by email: its card is not used.
    $db->insert('pay_gateway_customer', ['pay_gateway_id' => 1, 'client_id' => 11, 'test_mode' => 0, 'external_customer_id' => 'cus_11', 'default_payment_method' => 'pm_11']);
    $world->db = $db;

    $adapter = Mockery::mock(Payment_Adapter_Stripe::class);
    $adapter->allows('gatewayId')->andReturn(1);
    $adapter->allows('testMode')->andReturn(false);
    $adapter->allows('savedCard')->andReturn(['payment_method' => 'pm_7', 'card' => 'Visa •••• 4242 (12/2030)'])->byDefault();
    $adapter->allows('chargeSavedCard')->andReturnUsing(function (Model_Invoice $invoice, string $attempt) use ($world): string {
        $world->charged[] = [(int) $invoice->id, $attempt];

        return (int) $invoice->id === 7 ? throw new InformationException('The card was declined: insufficient funds') : 'pi_' . $invoice->id;
    })->byDefault();
    $gateway = new Model_PayGateway();
    $gateway->loadBean(new Tests\Helpers\DummyBean());

    $di = Tests\Helpers\container();
    $di['dbal'] = $db;
    $di['logger'] = new class {
        public function __call(string $name, array $arguments): mixed
        {
            return $this;
        }
    };
    $redbean = Mockery::mock();
    $redbean->allows('findOne')->andReturn($gateway)->byDefault();
    $redbean->allows('load')->andReturnUsing(function (string $type, int $id) use ($db): Model_Invoice {
        $invoice = new Model_Invoice();
        $invoice->loadBean(new Tests\Helpers\DummyBean());
        foreach ($db->fetchAssociative('SELECT * FROM invoice WHERE id = ?', [$id]) as $key => $value) {
            $invoice->{$key} = $value;
        }

        return $invoice;
    })->byDefault();
    $di['db'] = $redbean;
    $payGateway = Mockery::mock();
    // A new adapter per lookup, as ServicePayGateway::getPaymentAdapter() builds one.
    $payGateway->allows('getPaymentAdapter')->andReturnUsing(fn (): object => clone $adapter);
    $transactions = Mockery::mock();
    $transactions->allows('createAndProcess')->andReturnUsing(function (array $data) use ($world, $db): int {
        $world->recorded[] = $data;
        if (!str_starts_with((string) current($data['get']), 'pending')) {
            $db->update('invoice', ['status' => 'paid'], ['id' => $data['invoice_id']]);
        }

        return 1;
    });
    $invoices = Mockery::mock();
    $invoices->allows('toApiArray')->andReturn([]);
    $invoices->allows('withBillingRecipient')->andReturnUsing(fn (array $email): array => $email);
    $email = Mockery::mock();
    $email->allows('sendTemplate')->andReturnUsing(function (array $data) use ($world): bool {
        $world->emails[] = $data;

        return true;
    });
    $extension = Mockery::mock();
    $extension->allows('getConfig')->andReturn([]);
    $di['mod_service'] = $di->protect(fn (string $mod, string $sub = '') => match (strtolower($mod) . $sub) {
        'invoicePayGateway' => $payGateway,
        'invoiceTransaction' => $transactions,
        'invoice' => $invoices,
        'email' => $email,
        'extension' => $extension,
    });
    $world->di = $di;

    return $world;
}

test('a saved method is tried on the due date less the days before, then every set number of days', function (): void {
    $default = ['days_before' => 0, 'every' => 7, 'retries' => 2];
    expect(SavedCards::tryDate('2026-11-10 00:00:00', 0, $default))->toBe('2026-11-10')
        ->and(SavedCards::tryDate('2026-11-10 00:00:00', 1, $default))->toBe('2026-11-17')
        ->and(SavedCards::tryDate('2026-11-10 00:00:00', 2, $default))->toBe('2026-11-24')
        ->and(SavedCards::tryDate('2026-11-10 00:00:00', 0, ['days_before' => 3] + $default))->toBe('2026-11-07')
        ->and(SavedCards::tryDate('2026-11-10 00:00:00', 2, ['every' => 3] + $default))->toBe('2026-11-16');
});

test('the retry settings default to two retries a week apart, and the old on/off switch still counts', function (?array $config, array $settings): void {
    $extension = Mockery::mock();
    $extension->allows('getConfig')->andReturn($config ?? []);
    $di = Tests\Helpers\container();
    $di['mod_service'] = $di->protect(fn () => $extension);

    expect(SavedCards::settings($di))->toBe($settings);
})->with([
    'nothing set' => [null, ['days_before' => 0, 'every' => 7, 'retries' => 2]],
    'retries switched off before the count' => [['card_retries' => '0'], ['days_before' => 0, 'every' => 7, 'retries' => 0]],
    'the new fields' => [['card_days_before' => '2', 'card_retry_every' => '3', 'card_retry_count' => '4', 'card_retries' => '0'], ['days_before' => 2, 'every' => 3, 'retries' => 4]],
    'out of range' => [['card_retry_every' => '0', 'card_retry_count' => '9'], ['days_before' => 0, 'every' => 1, 'retries' => 5]],
]);

test('the retry settings refuse values out of range', function (array $data, string $error): void {
    $di = Tests\Helpers\container();
    expect(fn () => SavedCards::saveSettings($di, $data))->toThrow(InformationException::class, $error);
})->with([
    [['card_days_before' => 0, 'card_retry_every' => 0, 'card_retry_count' => 2], 'Days between retries'],
    [['card_days_before' => 0, 'card_retry_every' => 7, 'card_retry_count' => 6], 'Retries must be'],
]);

test('the cron charges due invoices without a payment method or with Stripe, never a subscription\'s, and retries a declined card', function (): void {
    $world = savedCardsWorld();
    $day = fn (int $days): string => date('Y-m-d 00:00:00', strtotime("{$days} days"));
    $invoice = fn (int $id, int $client, string $due, ?int $gateway = null, string $status = 'unpaid') => $world->db->insert('invoice', ['id' => $id, 'client_id' => $client, 'status' => $status, 'approved' => 1, 'due_at' => $due, 'gateway_id' => $gateway]);
    $invoice(1, 7, $day(0));          // due today, no payment method: charged
    $invoice(2, 7, $day(0), 2);       // the client chose another payment method
    $invoice(3, 8, $day(0));          // a Stripe customer without a saved card
    $invoice(4, 7, $day(1));          // not due yet
    $invoice(5, 7, $day(0), 1);       // paid by a Stripe subscription (its order's first invoice started it)
    $world->db->insert('invoice_item', ['invoice_id' => 5, 'type' => 'order', 'rel_id' => 50]);
    $world->db->insert('invoice_item', ['invoice_id' => 90, 'type' => 'order', 'rel_id' => 50]);
    $world->db->insert('subscription', ['rel_type' => 'invoice', 'rel_id' => 90, 'status' => 'active']);
    $invoice(6, 7, $day(-3));         // tried once; the next try is 7 days after the due date
    $world->db->insert('invoice_card_charge', ['invoice_id' => 6, 'attempts' => 1]);
    $invoice(7, 7, $day(-8));         // tried once 8 days ago: tried again, and declined
    $world->db->insert('invoice_card_charge', ['invoice_id' => 7, 'attempts' => 1]);
    $invoice(8, 7, $day(-20));        // long overdue, tried today: the next try waits 7 days, not one cron run
    $world->db->insert('invoice_card_charge', ['invoice_id' => 8, 'attempts' => 1, 'last_attempt_at' => date('Y-m-d H:i:s')]);
    $invoice(9, 7, $day(-20), null, 'paid');
    $invoice(11, 11, $day(0));

    SavedCards::runDue($world->di);

    expect($world->charged)->toBe([[1, 'attempt_1'], [7, 'attempt_2']])
        ->and($world->recorded)->toHaveCount(1)
        ->and($world->recorded[0])->toMatchArray(['invoice_id' => 1, 'gateway_id' => 1, 'get' => ['payment_intent' => 'pi_1', 'invoice_id' => 1]])
        // 99 on the paid invoice: once a payment is taken it is never tried again.
        ->and($world->db->fetchAllKeyValue('SELECT invoice_id, attempts FROM invoice_card_charge ORDER BY invoice_id'))->toBe([1 => 99, 6 => 1, 7 => 2, 8 => 1])
        ->and($world->emails)->toHaveCount(1)
        ->and($world->emails[0])->toMatchArray(['code' => 'mod_invoice_card_failed', 'to_client' => 7, 'card' => 'Visa •••• 4242 (12/2030)', 'retry_at' => substr($day(7), 0, 10)]);

    // A second cron run the same day tries nothing again.
    SavedCards::runDue($world->di);
    expect($world->charged)->toHaveCount(2);
});

test('a staff charge does not count as a try and emails nobody', function (): void {
    $world = savedCardsWorld();
    $world->db->insert('invoice', ['id' => 7, 'client_id' => 7, 'status' => 'unpaid', 'approved' => 1, 'due_at' => date('Y-m-d 00:00:00'), 'gateway_id' => null]);
    $invoice = $world->di['db']->load('Invoice', 7);

    $result = SavedCards::charge($world->di, $invoice, SavedCards::stripe($world->di), true);

    expect($result)->toBe(['paid' => false, 'error' => 'The card was declined: insufficient funds'])
        ->and($world->charged[0][1])->toStartWith('staff_')
        ->and($world->db->fetchOne('SELECT attempts FROM invoice_card_charge WHERE invoice_id = 7'))->toBeFalse()
        ->and($world->emails)->toBe([]);
});

test('a cron try skips an invoice set to another payment method after the cron read it, staff can still charge it', function (): void {
    $world = savedCardsWorld();
    $world->db->insert('invoice', ['id' => 1, 'client_id' => 7, 'status' => 'unpaid', 'approved' => 1, 'due_at' => date('Y-m-d 00:00:00'), 'gateway_id' => null]);
    $invoice = $world->di['db']->load('Invoice', 1);
    // The client sends a transfer receipt while the cron is on its way to this invoice.
    $world->db->executeStatement('UPDATE invoice SET gateway_id = 2 WHERE id = 1');

    expect(SavedCards::charge($world->di, $invoice, SavedCards::stripe($world->di), false))->toBe(['paid' => false, 'error' => null])
        ->and($world->charged)->toBe([])
        ->and($world->db->fetchOne('SELECT attempts FROM invoice_card_charge WHERE invoice_id = 1'))->toBeFalse()
        ->and(SavedCards::charge($world->di, $invoice, SavedCards::stripe($world->di), true)['paid'])->toBeTrue()
        ->and($world->charged)->toHaveCount(1);
});

test('the method a client saved or chose last pays: a saved PayPal account over an older card', function (): void {
    $world = savedCardsWorld();
    $world->db->update('pay_gateway_customer', ['auto_pay_at' => '2026-11-01 10:00:00'], ['client_id' => 7]);
    $world->db->insert('pay_gateway_customer', ['pay_gateway_id' => 2, 'client_id' => 7, 'test_mode' => 0, 'external_customer_id' => 'paypal-customer', 'saved_cards' => 1, 'default_payment_method' => 'vault-token', 'default_card' => 'PayPal buyer@example.com', 'auto_pay_at' => '2026-11-05 10:00:00']);
    $paypal = savedCardsPaypal($world);
    $stripe = SavedCards::stripe($world->di);
    $world->db->insert('invoice', ['id' => 1, 'client_id' => 7, 'status' => 'unpaid', 'approved' => 1, 'due_at' => date('Y-m-d 00:00:00'), 'gateway_id' => 1]);

    expect(SavedCards::method($world->di, 7))->toMatchArray(['payment_method' => 'vault-token', 'card' => 'PayPal buyer@example.com', 'adapter' => $paypal]);

    SavedCards::runDue($world->di);

    expect($world->charged)->toBe([['paypal', 1, 'attempt_1']])
        ->and($world->recorded[0])->toMatchArray(['gateway_id' => 2, 'get' => ['paypal_capture' => 'capture-1', 'invoice_id' => 1]]);

    // Choosing the card again makes it pay.
    SavedCards::choose($world->di, 1, false, 7);
    expect(SavedCards::method($world->di, 7)['adapter'])->toBe($stripe);
});

test('a payment taken but not completed, or maybe taken without an answer, is never charged again automatically', function (bool $byStaff, Closure $charge): void {
    $world = savedCardsWorld();
    $world->db->insert('invoice', ['id' => 12, 'client_id' => 7, 'status' => 'unpaid', 'approved' => 1, 'due_at' => date('Y-m-d 00:00:00'), 'gateway_id' => null]);
    $stripe = SavedCards::stripe($world->di);
    $stripe->allows('chargeSavedCard')->andReturnUsing($charge);

    expect(SavedCards::charge($world->di, $world->di['db']->load('Invoice', 12), $stripe, $byStaff))->toMatchArray(['paid' => false])
        ->and((int) $world->db->fetchOne('SELECT attempts FROM invoice_card_charge WHERE invoice_id = 12'))->toBe(99)
        ->and($world->emails)->toBe([]);

    // Days later the cron leaves it alone.
    $world->db->update('invoice_card_charge', ['last_attempt_at' => '2000-01-01 00:00:00'], ['invoice_id' => 12]);
    $world->charged = [];
    SavedCards::runDue($world->di);
    expect($world->charged)->toBe([]);
})->with([
    'held by the payment service, cron try' => [false, fn (): string => 'pending-12'],
    'held by the payment service, staff try' => [true, fn (): string => 'pending-12'],
    'no answer, cron try' => [false, fn (): string => throw new RuntimeException('Operation timed out')],
]);

test('a bank debit still clearing is neither charged again nor tried by the cron; a debit that starts says so', function (): void {
    $world = savedCardsWorld();
    $world->db->insert('invoice', ['id' => 1, 'client_id' => 7, 'status' => 'unpaid', 'approved' => 1, 'due_at' => date('Y-m-d 00:00:00'), 'gateway_id' => null]);
    $world->db->insert('invoice', ['id' => 2, 'client_id' => 7, 'status' => 'unpaid', 'approved' => 1, 'due_at' => date('Y-m-d 00:00:00'), 'gateway_id' => null]);
    $world->db->insert('`transaction`', ['invoice_id' => 1, 'status' => 'received', 'txn_status' => 'processing', 'amount' => '25.50', 'currency' => 'EUR', 'created_at' => date('Y-m-d H:i:s')]);
    // One Stripe never answered for stops counting after 21 days.
    $world->db->insert('`transaction`', ['invoice_id' => 2, 'status' => 'received', 'txn_status' => 'processing', 'amount' => '25.50', 'currency' => 'EUR', 'created_at' => date('Y-m-d H:i:s', strtotime('-22 days'))]);

    SavedCards::runDue($world->di);
    expect($world->charged)->toBe([[2, 'attempt_1']])
        ->and(SavedCards::charge($world->di, $world->di['db']->load('Invoice', 1), SavedCards::stripe($world->di), true))
        ->toBe(['paid' => false, 'error' => 'A payment for this invoice is still clearing.']);

    $world->db->insert('invoice', ['id' => 3, 'client_id' => 7, 'status' => 'unpaid', 'approved' => 1, 'due_at' => date('Y-m-d 00:00:00'), 'gateway_id' => null]);
    $stripe = SavedCards::stripe($world->di);
    $stripe->allows('chargeSavedCard')->andReturnUsing(function () use ($world): string {
        // Recording the processing PaymentIntent leaves a received, processing transaction.
        $world->db->insert('`transaction`', ['invoice_id' => 3, 'status' => 'received', 'txn_status' => 'processing', 'amount' => '25.50', 'currency' => 'EUR', 'created_at' => date('Y-m-d H:i:s')]);

        return 'pending-3';
    });
    expect(SavedCards::charge($world->di, $world->di['db']->load('Invoice', 3), $stripe, false))
        ->toBe(['paid' => false, 'error' => 'The bank debit has started. The invoice is marked paid when it clears.'])
        ->and($world->emails)->toBe([]);
});

test('a failed bank debit emails the client without a retry date', function (): void {
    $world = savedCardsWorld();
    $world->db->insert('invoice', ['id' => 1, 'client_id' => 7, 'status' => 'unpaid', 'approved' => 1, 'due_at' => date('Y-m-d 00:00:00'), 'gateway_id' => null]);

    SavedCards::emailDebitFailure($world->di, $world->di['db']->load('Invoice', 1), 'SEPA Direct Debit •••• 3000 (DE)', 'Stripe payment failed: insufficient funds');

    expect($world->emails)->toHaveCount(1)
        ->and($world->emails[0])->toMatchArray(['code' => 'mod_invoice_card_failed', 'to_client' => 7, 'card' => 'SEPA Direct Debit •••• 3000 (DE)', 'retry_at' => '']);
});

test('a cron try or the client\'s Pay now leaves an invoice held after runDue read it, staff can still charge it', function (): void {
    $world = savedCardsWorld();
    $world->db->insert('invoice', ['id' => 1, 'client_id' => 7, 'status' => 'unpaid', 'approved' => 1, 'due_at' => date('Y-m-d 00:00:00'), 'gateway_id' => null]);
    // A staff charge PayPal holds while the cron is on its way to this invoice.
    SavedCards::hold($world->di, 1, 'Payment held-1 is not completed yet.');
    $invoice = $world->di['db']->load('Invoice', 1);

    expect(SavedCards::charge($world->di, $invoice, SavedCards::stripe($world->di), false))->toBe(['paid' => false, 'error' => null])
        ->and(SavedCards::charge($world->di, $invoice, SavedCards::stripe($world->di), true, true))->toBe(['paid' => false, 'error' => 'A payment for this invoice is still clearing.'])
        ->and($world->charged)->toBe([])
        ->and(SavedCards::charge($world->di, $invoice, SavedCards::stripe($world->di), true)['paid'])->toBeTrue();
});

/** Adds an enabled PayPal gateway (2) with saved accounts on to the world: its adapter mock. */
function savedCardsPaypal(object $world): Mockery\MockInterface
{
    $paypal = Mockery::mock(Payment_Adapter_PayPalEmail::class);
    $paypal->allows('canVault')->andReturnTrue();
    $paypal->allows('gatewayId')->andReturn(2);
    $paypal->allows('testMode')->andReturnFalse();
    $paypal->allows('savedCard')->andReturnUsing(fn (int $clientId): ?array => SavedCards::saved($world->di, 2, false, $clientId));
    $paypal->allows('chargeSavedCard')->andReturnUsing(function (Model_Invoice $invoice, string $attempt) use ($world): string {
        $world->charged[] = ['paypal', (int) $invoice->id, $attempt];

        return 'capture-' . $invoice->id;
    });
    $stripe = SavedCards::stripe($world->di);
    $stripe->allows('savedCard')->andReturnUsing(fn (int $clientId): ?array => SavedCards::saved($world->di, 1, false, $clientId));
    $gateways = ['Stripe' => 1, 'PayPalEmail' => 2];
    $world->di['db']->allows('findOne')->andReturnUsing(function (string $type, string $where, array $values) use ($gateways): Model_PayGateway {
        $gateway = new Model_PayGateway();
        $gateway->loadBean(new Tests\Helpers\DummyBean());
        $gateway->id = $gateways[$values[0]];

        return $gateway;
    });
    $payGateway = Mockery::mock();
    $payGateway->allows('getPaymentAdapter')->andReturnUsing(fn (Model_PayGateway $gateway): object => (int) $gateway->id === 2 ? $paypal : $stripe);
    $services = $world->di['mod_service'];
    $world->di['mod_service'] = $world->di->protect(fn (string $mod, string $sub = '') => strtolower($mod) . $sub === 'invoicePayGateway' ? $payGateway : $services($mod, $sub));

    return $paypal;
}

test('a client who saved their PayPal account has their PayPal subscriptions cancelled, a refused one once a day', function (): void {
    $world = savedCardsWorld();
    savedCardsPaypal($world);
    $world->db->insert('pay_gateway_customer', ['pay_gateway_id' => 2, 'client_id' => 7, 'test_mode' => 0, 'external_customer_id' => 'paypal-customer', 'saved_cards' => 1, 'default_payment_method' => 'vault-token', 'default_card' => 'PayPal buyer@example.com']);
    $world->db->insert('subscription', ['id' => 1, 'client_id' => 7, 'pay_gateway_id' => 2, 'sid' => 'I-SAVED', 'status' => 'active']);
    $world->db->insert('subscription', ['id' => 2, 'client_id' => 8, 'pay_gateway_id' => 2, 'sid' => 'I-OTHER', 'status' => 'active']);
    $world->db->insert('subscription', ['id' => 3, 'client_id' => 7, 'pay_gateway_id' => 2, 'sid' => 'I-REFUSED', 'status' => 'active']);
    $world->di['db']->allows('load')->with('Subscription', Mockery::any())->andReturnUsing(function (string $type, int $id) use ($world): Model_Subscription {
        $subscription = new Model_Subscription();
        $subscription->loadBean(new Tests\Helpers\DummyBean());
        foreach ($world->db->fetchAssociative('SELECT * FROM subscription WHERE id = ?', [$id]) as $key => $value) {
            $subscription->{$key} = $value;
        }

        return $subscription;
    });
    $cancelled = [];
    $subscriptions = Mockery::mock();
    $subscriptions->allows('cancel')->andReturnUsing(function (Model_Subscription $subscription) use (&$cancelled, $world): void {
        $cancelled[] = $subscription->sid;
        if ($subscription->sid === 'I-REFUSED') {
            throw new InformationException('PayPal did not cancel subscription I-REFUSED');
        }
        $world->db->update('subscription', ['status' => 'canceled'], ['id' => $subscription->id]);
    });
    $services = $world->di['mod_service'];
    $world->di['mod_service'] = $world->di->protect(fn (string $mod, string $sub = '') => strtolower($mod) . $sub === 'invoiceSubscription' ? $subscriptions : $services($mod, $sub));

    SavedCards::runDue($world->di);
    SavedCards::runDue($world->di);

    expect($cancelled)->toBe(['I-SAVED', 'I-REFUSED']);
});
