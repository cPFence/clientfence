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
    $db->executeStatement('CREATE TABLE subscription (id INTEGER PRIMARY KEY, rel_type TEXT, rel_id INTEGER, status TEXT)');
    $db->executeStatement('CREATE TABLE pay_gateway_customer (id INTEGER PRIMARY KEY, pay_gateway_id INTEGER, client_id INTEGER, test_mode INTEGER, external_customer_id TEXT, saved_cards INTEGER DEFAULT 0, default_payment_method TEXT, default_card TEXT)');
    $db->executeStatement('CREATE TABLE invoice_card_charge (invoice_id INTEGER PRIMARY KEY, attempts INTEGER NOT NULL DEFAULT 0, last_attempt_at TEXT, last_error TEXT)');
    $db->insert('pay_gateway_customer', ['pay_gateway_id' => 1, 'client_id' => 7, 'test_mode' => 0, 'external_customer_id' => 'cus_7', 'saved_cards' => 1, 'default_payment_method' => 'pm_7', 'default_card' => 'Visa •••• 4242 (12/2030)']);
    $db->insert('pay_gateway_customer', ['pay_gateway_id' => 1, 'client_id' => 8, 'test_mode' => 0, 'external_customer_id' => 'cus_8', 'saved_cards' => 1]);
    // A subscription customer found by email: its card is not used.
    $db->insert('pay_gateway_customer', ['pay_gateway_id' => 1, 'client_id' => 11, 'test_mode' => 0, 'external_customer_id' => 'cus_11', 'default_payment_method' => 'pm_11']);
    $world->db = $db;

    $adapter = Mockery::mock(Payment_Adapter_Stripe::class);
    $adapter->allows('gatewayId')->andReturn(1);
    $adapter->allows('testMode')->andReturn(false);
    $adapter->allows('savedCard')->andReturn(['payment_method' => 'pm_7', 'card' => 'Visa •••• 4242 (12/2030)']);
    $adapter->allows('chargeSavedCard')->andReturnUsing(function (Model_Invoice $invoice, string $attempt) use ($world): string {
        $world->charged[] = [(int) $invoice->id, $attempt];

        return (int) $invoice->id === 7 ? throw new InformationException('The card was declined: insufficient funds') : 'pi_' . $invoice->id;
    });
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
    $redbean->allows('findOne')->andReturn($gateway);
    $redbean->allows('load')->andReturnUsing(function (string $type, int $id) use ($db): Model_Invoice {
        $invoice = new Model_Invoice();
        $invoice->loadBean(new Tests\Helpers\DummyBean());
        foreach ($db->fetchAssociative('SELECT * FROM invoice WHERE id = ?', [$id]) as $key => $value) {
            $invoice->{$key} = $value;
        }

        return $invoice;
    });
    $di['db'] = $redbean;
    $payGateway = Mockery::mock();
    $payGateway->allows('getPaymentAdapter')->andReturn($adapter);
    $transactions = Mockery::mock();
    $transactions->allows('createAndProcess')->andReturnUsing(function (array $data) use ($world): int {
        $world->recorded[] = $data;

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

test('a saved card is tried on the due date less the days before, then 7 and 14 days later', function (): void {
    expect(SavedCards::tryDate('2026-11-10 00:00:00', 0, 0))->toBe('2026-11-10')
        ->and(SavedCards::tryDate('2026-11-10 00:00:00', 1, 0))->toBe('2026-11-17')
        ->and(SavedCards::tryDate('2026-11-10 00:00:00', 2, 0))->toBe('2026-11-24')
        ->and(SavedCards::tryDate('2026-11-10 00:00:00', 0, 3))->toBe('2026-11-07');
});

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
        ->and($world->db->fetchAllKeyValue('SELECT invoice_id, attempts FROM invoice_card_charge ORDER BY invoice_id'))->toBe([1 => 1, 6 => 1, 7 => 2, 8 => 1])
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
