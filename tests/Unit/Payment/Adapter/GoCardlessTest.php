<?php

declare(strict_types=1);

/**
 * ClientFence: GoCardless (Payment_Adapter_GoCardless): Direct Debit set-up on GoCardless' page, payments collected from
 * the mandate that wait until GoCardless confirms them, signed webhooks, chargebacks and refunds. GoCardless's replies
 * come from a mock HTTP client ("METHOD path" => reply), the tables from in-memory SQLite.
 */

use ClientFence\Mod\Invoice\PendingPayments;
use ClientFence\Mod\Invoice\SavedCards;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function Tests\Helpers\container;

function gcInvoice(array $values = []): Model_Invoice
{
    $model = new Model_Invoice();
    $model->loadBean(new Tests\Helpers\DummyBean());
    foreach ($values + ['id' => 5, 'client_id' => 7, 'currency' => 'GBP', 'hash' => 'abc', 'status' => 'unpaid', 'approved' => 1, 'due_at' => '2026-11-20 00:00:00'] as $key => $value) {
        $model->{$key} = $value;
    }

    return $model;
}

/**
 * An adapter (gateway 3, sandbox) whose API calls get $replies ("METHOD path" => reply, or a callable of the request
 * body; an error reply is [status, body]), recorded in $world->calls as [method path, body, authorization, host,
 * idempotency key]. The client (#7) has the Direct Debit "MD1 GBP"; invoice #5 is unpaid.
 */
function gcWorld(array $replies): object
{
    $world = new stdClass();
    $world->calls = [];
    $world->balance = 12.5;
    $http = new MockHttpClient(function (string $method, string $url, array $options) use ($replies, $world): MockResponse {
        $key = $method . ' ' . parse_url($url, PHP_URL_PATH);
        $body = json_decode((string) ($options['body'] ?? ''), true);
        $world->calls[] = [$key, $body, $options['normalized_headers']['authorization'][0] ?? '', parse_url($url, PHP_URL_HOST), $options['normalized_headers']['idempotency-key'][0] ?? ''];
        $reply = $replies[$key] ?? [404, ['error' => ['message' => 'Unexpected', 'errors' => [['reason' => 'resource_not_found']]]]];
        $reply = is_callable($reply) ? $reply($body) : $reply;
        [$status, $reply] = isset($reply[0], $reply[1]) && is_int($reply[0]) ? $reply : [200, $reply];

        return new MockResponse((string) json_encode($reply), ['http_code' => $status]);
    });
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE pay_gateway_customer (id INTEGER PRIMARY KEY, pay_gateway_id INTEGER, client_id INTEGER, test_mode INTEGER, external_customer_id TEXT, saved_cards INTEGER DEFAULT 0, default_payment_method TEXT, default_card TEXT, auto_pay_at TEXT, created_at TEXT, updated_at TEXT)');
    $db->executeStatement('CREATE TABLE `transaction` (id INTEGER PRIMARY KEY, invoice_id INTEGER, gateway_id INTEGER, txn_id TEXT, txn_status TEXT, amount TEXT, currency TEXT, type TEXT, status TEXT, error TEXT, error_code INTEGER, note TEXT, created_at TEXT, updated_at TEXT)');
    $db->executeStatement('CREATE TABLE invoice (id INTEGER PRIMARY KEY, client_id INTEGER, status TEXT, approved INTEGER DEFAULT 1, gateway_id INTEGER)');
    $db->executeStatement('CREATE TABLE invoice_item (id INTEGER PRIMARY KEY, invoice_id INTEGER, type TEXT, task TEXT)');
    $db->executeStatement('CREATE TABLE invoice_card_charge (invoice_id INTEGER PRIMARY KEY, attempts INTEGER, last_attempt_at TEXT, last_error TEXT)');
    $db->insert('invoice', ['id' => 5, 'client_id' => 7, 'status' => 'unpaid']);
    $db->insert('pay_gateway_customer', ['pay_gateway_id' => 3, 'client_id' => 7, 'test_mode' => 1, 'external_customer_id' => '', 'saved_cards' => 1, 'default_payment_method' => 'MD1 GBP', 'default_card' => 'Bacs Direct Debit ••12', 'auto_pay_at' => '2026-11-01 00:00:00']);
    $world->db = $db;

    // One mock stands for every module service the adapter uses (Invoice, Currency, extension, email).
    $services = Mockery::mock();
    $services->allows('getBalanceDue')->andReturnUsing(fn (): float => $world->balance);
    $services->allows('toApiArray')->andReturn(['serie_nr' => 'INV-00005']);
    $services->allows('getCurrencyByClientId')->andReturn(new class {
        public function getCode(): string
        {
            return 'EUR';
        }
    });
    $world->services = $services;
    $di = container();
    $di['dbal'] = $db;
    $di['http_client'] = $http;
    $di['mod_service'] = $di->protect(fn (): object => $services);
    $di['db'] = Mockery::mock();
    $di['db']->allows('getExistingModelById')->andReturnUsing(function (string $type, int $id): object {
        if ($type !== 'Client') {
            return gcInvoice(['id' => $id]);
        }
        $client = new Model_Client();
        $client->loadBean(new Tests\Helpers\DummyBean());
        foreach (['id' => $id, 'first_name' => 'Test', 'last_name' => 'Client', 'email' => 'test@example.com', 'country' => 'gb', 'city' => 'London'] as $key => $value) {
            $client->{$key} = $value;
        }

        return $client;
    });
    $di['url'] = new class {
        public function link(string $path, array $params = []): string
        {
            return 'https://portal.example/' . $path . ($params === [] ? '' : '?' . http_build_query($params));
        }
    };
    $world->di = $di;
    $world->adapter = new Payment_Adapter_GoCardless(['gateway_id' => 3, 'test_mode' => true, 'test_access_token' => 'TOKEN', 'webhook_secret' => 'WHSECRET']);
    $world->adapter->setDi($di);

    return $world;
}

function gcSaved(Connection $db): ?string
{
    $value = $db->fetchOne('SELECT default_payment_method FROM pay_gateway_customer WHERE pay_gateway_id = 3 AND client_id = 7');

    return $value === false ? null : $value;
}

/** GoCardless's answer to a payment lookup. */
function gcPayment(string $status, array $more = []): array
{
    return ['payments' => $more + ['id' => 'PM123', 'amount' => 1250, 'currency' => 'GBP', 'status' => $status, 'amount_refunded' => 0, 'description' => 'Invoice INV-00005', 'metadata' => ['clientfence' => 'cf-invoice-5-attempt_1'], 'links' => ['mandate' => 'MD1']]];
}

/** A webhook of $events as ipn.php keeps it, signed with the webhook secret unless $signature is given. */
function gcWebhook(array $events, ?string $signature = null): array
{
    $body = (string) json_encode(['events' => $events]);

    return ['source' => 'ipn', 'http_raw_post_data' => $body, 'server' => ['HTTP_WEBHOOK_SIGNATURE' => $signature ?? hash_hmac('sha256', $body, 'WHSECRET')]];
}

/** The pending row of payment PM123 (an automatic try), as SavedCards::charge() leaves it. */
function gcPending(Connection $db, int $id = 40): void
{
    $db->insert('`transaction`', ['id' => $id, 'invoice_id' => 5, 'gateway_id' => 3, 'txn_id' => 'PM123', 'type' => 'gocardless', 'status' => 'received', 'txn_status' => 'processing', 'amount' => '12.5', 'currency' => 'GBP', 'created_at' => date('Y-m-d H:i:s')]);
}

test('needs the access token of the current mode and the webhook secret, and checks the token with GoCardless', function (): void {
    expect(fn () => new Payment_Adapter_GoCardless(['test_mode' => false, 'test_access_token' => 't', 'webhook_secret' => 's']))->toThrow(Payment_Exception::class);
    expect(fn () => new Payment_Adapter_GoCardless(['test_mode' => true, 'test_access_token' => 't']))->toThrow(Payment_Exception::class);

    $ok = gcWorld(['GET /creditors' => ['creditors' => []]]);
    $ok->adapter->testConnection();
    expect($ok->calls[0][2])->toBe('Authorization: Bearer TOKEN')
        ->and($ok->calls[0][3])->toBe('api-sandbox.gocardless.com');

    $wrong = gcWorld(['GET /creditors' => [401, ['error' => ['message' => 'Credentials not recognised', 'errors' => [['reason' => 'unauthorized']]]]]]);
    expect(fn () => $wrong->adapter->testConnection())->toThrow(ClientFence\InformationException::class, 'unauthorized, HTTP 401');
});

test('sets up a Direct Debit on GoCardless\' page and keeps its mandate for the client, within the hour', function (): void {
    $request = fn (string $status, int $age, string $reference = 'cf-client-7', string $site = INSTANCE_ID): array => ['billing_requests' => [
        'id' => 'BRQ1', 'status' => $status, 'created_at' => gmdate('Y-m-d\TH:i:s.000\Z', time() - $age), 'metadata' => ['clientfence' => $reference, 'site' => $site],
        'mandate_request' => ['currency' => 'EUR'], 'links' => ['mandate_request_mandate' => 'MD2'],
    ]];
    $world = gcWorld([
        'POST /billing_requests' => ['billing_requests' => ['id' => 'BRQ1']],
        'POST /billing_request_flows' => ['billing_request_flows' => ['id' => 'BRF1', 'authorisation_url' => 'https://pay-sandbox.gocardless.com/billing/static/flow?id=BRF1']],
        'GET /billing_requests/BRQ1' => $request('fulfilled', 60),
        'GET /mandates/MD2' => ['mandates' => ['id' => 'MD2', 'scheme' => 'sepa_core', 'status' => 'pending_submission', 'links' => ['customer_bank_account' => 'BA2']]],
        'GET /customer_bank_accounts/BA2' => ['customer_bank_accounts' => ['id' => 'BA2', 'account_number_ending' => '34', 'currency' => 'EUR']],
        'POST /mandates/MD1/actions/cancel' => ['mandates' => ['id' => 'MD1', 'status' => 'cancelled']],
    ]);
    expect($world->adapter->cardSetup(7, 'https://portal.example/payment-methods/gocardless/saved?next=payment-methods'))->toEndWith('id=BRF1')
        ->and($world->calls[0][1])->toBe(['billing_requests' => ['mandate_request' => ['currency' => 'EUR'], 'metadata' => ['clientfence' => 'cf-client-7', 'site' => INSTANCE_ID]]])
        ->and($world->calls[1][1]['billing_request_flows'])->toMatchArray([
            'redirect_uri' => 'https://portal.example/payment-methods/gocardless/saved?next=payment-methods&br=BRQ1',
            'exit_uri' => 'https://portal.example/payment-methods',
            'links' => ['billing_request' => 'BRQ1'],
        ])
        ->and($world->calls[1][1]['billing_request_flows']['prefilled_customer'])->toMatchArray(['given_name' => 'Test', 'email' => 'test@example.com', 'country_code' => 'GB']);

    // Back from GoCardless: the new mandate is kept with its label, and the one saved before is cancelled at GoCardless.
    expect($world->adapter->mandateSaved('BRQ1'))->toBe([7, 0])
        ->and(gcSaved($world->db))->toBe('MD2 EUR')
        ->and($world->db->fetchOne('SELECT default_card FROM pay_gateway_customer WHERE client_id = 7'))->toBe('SEPA Direct Debit ••34')
        ->and(array_column($world->calls, 0))->toContain('POST /mandates/MD1/actions/cancel');

    // Paying an invoice: the set-up names it and comes back to it.
    $world->adapter->checkout(gcInvoice());
    expect($world->calls[count($world->calls) - 2][1]['billing_requests'])->toBe(['mandate_request' => ['currency' => 'GBP'], 'metadata' => ['clientfence' => 'cf-client-7-invoice-5', 'site' => INSTANCE_ID]])
        ->and($world->calls[count($world->calls) - 1][1]['billing_request_flows']['redirect_uri'])->toBe('https://portal.example/invoice/gocardless-paid?hash=abc&br=BRQ1');

    foreach (['an old set-up sent again' => $request('fulfilled', 7200), 'not completed' => $request('pending', 60), 'another shop\'s' => $request('fulfilled', 60, 'shop-7'), 'another portal\'s on the same account' => $request('fulfilled', 60, 'cf-client-7', 'other-install')] as $reply) {
        $refused = gcWorld(['GET /billing_requests/BRQ1' => $reply]);
        expect(fn () => $refused->adapter->mandateSaved('BRQ1'))->toThrow(ClientFence\InformationException::class, 'GoCardless did not set up the Direct Debit');
        expect(gcSaved($refused->db))->toBe('MD1 GBP');
    }
});

test('collects an invoice from the mandate in the currency\'s smallest unit, once per try, in its scheme\'s currency only', function (): void {
    $world = gcWorld(['POST /payments' => ['payments' => ['id' => 'PM123', 'status' => 'pending_submission']]]);
    $world->balance = 1234.5;
    expect($world->adapter->chargeSavedCard(gcInvoice(), 'attempt_1'))->toBe('PM123')
        ->and($world->calls[0][1])->toBe(['payments' => [
            'amount' => 123450, 'currency' => 'GBP', 'description' => 'Invoice INV-00005', 'metadata' => ['clientfence' => 'cf-invoice-5-attempt_1'], 'links' => ['mandate' => 'MD1'],
        ]])
        ->and($world->calls[0][4])->toBe('Idempotency-Key: cf-invoice-5-attempt_1-' . substr(hash('sha256', 'abc'), 0, 12));

    // A Bacs (GBP) mandate cannot collect a euro invoice: refused before asking GoCardless.
    $euro = gcWorld([]);
    expect(fn () => $euro->adapter->chargeSavedCard(gcInvoice(['currency' => 'EUR']), 'attempt_1'))->toThrow(ClientFence\InformationException::class, 'The Direct Debit collects GBP, the invoice is in EUR');
    expect($euro->calls)->toBe([]);

    $gone = gcWorld(['POST /payments' => [422, ['error' => ['message' => 'Mandate is not active', 'errors' => [['reason' => 'mandate_is_inactive']]]]]]);
    expect(fn () => $gone->adapter->chargeSavedCard(gcInvoice(), 'attempt_1'))->toThrow(ClientFence\InformationException::class, 'mandate_is_inactive');
    expect(gcSaved($gone->db))->toBeNull();

    $down = gcWorld(['POST /payments' => [503, []]]);
    expect(fn () => $down->adapter->chargeSavedCard(gcInvoice(), 'attempt_1'))->toThrow(Payment_Exception::class, 'HTTP 503');
    expect(gcSaved($down->db))->toBe('MD1 GBP');
});

test('a payment still being collected leaves the invoice unpaid and refuses a second payment', function (): void {
    $world = gcWorld(['GET /payments/PM123' => gcPayment('pending_submission')]);
    $world->db->insert('`transaction`', ['id' => 40, 'gateway_id' => 3, 'status' => 'received', 'created_at' => date('Y-m-d H:i:s')]);
    $world->adapter->processTransaction(Mockery::mock(), 40, ['source' => 'saved_card', 'get' => ['gocardless_payment' => 'PM123']], 3);

    expect($world->db->fetchAssociative('SELECT status, txn_status, txn_id FROM `transaction` WHERE id = 40'))->toBe(['status' => 'received', 'txn_status' => 'processing', 'txn_id' => 'PM123'])
        ->and(PendingPayments::forInvoice($world->di, 5))->not->toBeNull()
        ->and(SavedCards::charge($world->di, gcInvoice(), $world->adapter, false))->toBe(['paid' => false, 'error' => 'A payment for this invoice is still clearing.'])
        ->and(array_column($world->calls, 0))->not->toContain('POST /payments');
});

test('a payment GoCardless does not answer for when read back waits as pending, and its webhook settles it', function (): void {
    $world = gcWorld(['GET /payments/PM123' => [503, ['error' => ['message' => 'Service unavailable']]]]);
    $world->db->insert('`transaction`', ['id' => 40, 'invoice_id' => 5, 'gateway_id' => 3, 'status' => 'received', 'created_at' => date('Y-m-d H:i:s')]);
    $world->adapter->processTransaction(Mockery::mock(), 40, ['source' => 'saved_card', 'get' => ['gocardless_payment' => 'PM123']], 3);

    expect($world->db->fetchAssociative('SELECT status, txn_status, txn_id FROM `transaction` WHERE id = 40'))->toBe(['status' => 'received', 'txn_status' => 'processing', 'txn_id' => 'PM123'])
        ->and(SavedCards::charge($world->di, gcInvoice(), $world->adapter, true))->toBe(['paid' => false, 'error' => 'A payment for this invoice is still clearing.']);

    // A row already paid stays paid when GoCardless does not answer.
    $paid = gcWorld(['GET /payments/PM123' => [503, ['error' => ['message' => 'Service unavailable']]]]);
    $paid->db->insert('`transaction`', ['id' => 40, 'invoice_id' => 5, 'gateway_id' => 3, 'txn_id' => 'PM123', 'txn_status' => 'Completed', 'status' => 'processed', 'created_at' => date('Y-m-d H:i:s')]);
    $paid->adapter->processTransaction(Mockery::mock(), 40, ['source' => 'saved_card', 'get' => ['gocardless_payment' => 'PM123']], 3);
    expect($paid->db->fetchAssociative('SELECT status, txn_status FROM `transaction` WHERE id = 40'))->toBe(['status' => 'processed', 'txn_status' => 'Completed']);

    // A payment GoCardless says does not exist is not kept waiting.
    $gone = gcWorld([]);
    $gone->db->insert('`transaction`', ['id' => 40, 'invoice_id' => 5, 'gateway_id' => 3, 'status' => 'received', 'created_at' => date('Y-m-d H:i:s')]);
    expect(fn () => $gone->adapter->processTransaction(Mockery::mock(), 40, ['source' => 'saved_card', 'get' => ['gocardless_payment' => 'PM123']], 3))->toThrow(Payment_Exception::class, 'was not found');
});

test('a webhook GoCardless did not sign records nothing', function (): void {
    $world = gcWorld(['GET /payments/PM123' => gcPayment('confirmed')]);
    gcPending($world->db);
    $webhook = gcWebhook([['id' => 'EV1', 'resource_type' => 'payments', 'action' => 'confirmed', 'links' => ['payment' => 'PM123']]], 'forged');

    expect($world->adapter->webhookSigned($webhook['http_raw_post_data'], 'forged'))->toBeFalse()
        ->and($world->adapter->webhookSigned($webhook['http_raw_post_data'], hash_hmac('sha256', $webhook['http_raw_post_data'], 'WHSECRET')))->toBeTrue()
        ->and(fn () => $world->adapter->processTransaction(Mockery::mock(), 41, $webhook, 3))->toThrow(Payment_Exception::class, 'signature');
    expect($world->calls)->toBe([])
        ->and($world->db->fetchOne('SELECT status FROM `transaction` WHERE id = 40'))->toBe('received');
});

test('a webhook stored without the server environment is still signed when processed again', function (): void {
    $world = gcWorld([]);
    $world->db->insert('`transaction`', ['id' => 41, 'gateway_id' => 3, 'status' => 'received']);
    $webhook = gcWebhook([]);
    // As ServiceTransaction::create() stores it (B11): headers and basic request fields only, as JSON.
    $webhook['server'] = ClientFence\Mod\Activity\ApiLog::noticeServer($webhook['server'] + ['DB_PASSWORD' => 'x', 'HTTP_COOKIE' => 'y']);
    $stored = json_decode((string) json_encode($webhook), true);

    $world->adapter->processTransaction(Mockery::mock(), 41, $stored, 3);
    expect($stored['server'])->toBe(['HTTP_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $webhook['http_raw_post_data'], 'WHSECRET'), 'HTTP_COOKIE' => '********'])
        ->and($world->db->fetchAssociative('SELECT status, note FROM `transaction` WHERE id = 41'))->toBe(['status' => 'processed', 'note' => 'No action needed.']);
});

test('a confirmed payment read back from GoCardless pays its invoice once', function (): void {
    $world = gcWorld(['GET /payments/PM123' => gcPayment('confirmed')]);
    gcPending($world->db);
    $world->db->insert('`transaction`', ['id' => 41, 'gateway_id' => 3, 'status' => 'received']);
    $world->db->insert('`transaction`', ['id' => 42, 'gateway_id' => 3, 'status' => 'received']);
    $world->services->allows('validatePaymentAmount');
    $world->services->allows('isInvoiceTypeDeposit')->andReturn(false);
    $api = Mockery::mock();
    $api->shouldReceive('client_balance_add_funds')->once()->with(Mockery::subset(['id' => 7, 'amount' => 12.5, 'rel_id' => 'PM123']));
    $api->shouldReceive('invoice_pay_with_credits')->once()->with(['id' => 5]);
    $event = ['id' => 'EV1', 'resource_type' => 'payments', 'action' => 'confirmed', 'links' => ['payment' => 'PM123']];

    $world->adapter->processTransaction($api, 41, gcWebhook([$event]), 3);
    expect($world->db->fetchAssociative('SELECT status, txn_status FROM `transaction` WHERE id = 40'))->toBe(['status' => 'processed', 'txn_status' => 'Completed'])
        ->and($world->db->fetchAssociative('SELECT status, type, note FROM `transaction` WHERE id = 41'))->toBe(['status' => 'processed', 'type' => 'gocardless_webhook', 'note' => 'Payment PM123: transaction #40.']);

    // The same event again (GoCardless resends until answered): nothing more is credited.
    $world->adapter->processTransaction($api, 42, gcWebhook([$event + ['id' => 'EV2']]), 3);
    expect($world->db->fetchOne('SELECT note FROM `transaction` WHERE id = 42'))->toBe('No action needed.');
});

test('a failed automatic payment ends the wait and emails the client, as a failed try', function (): void {
    $world = gcWorld(['GET /payments/PM123' => gcPayment('failed')]);
    gcPending($world->db);
    $world->db->insert('invoice_card_charge', ['invoice_id' => 5, 'attempts' => 99]);
    $world->db->insert('`transaction`', ['id' => 41, 'gateway_id' => 3, 'status' => 'received']);
    $world->services->allows('getConfig')->andReturn([]);
    $world->services->allows('withBillingRecipient')->andReturnUsing(fn (array $email): array => $email);
    $world->services->shouldReceive('sendTemplate')->once()->with(Mockery::subset(['code' => 'mod_invoice_card_failed', 'to_client' => 7, 'card' => 'Bacs Direct Debit ••12']));

    $world->adapter->processTransaction(Mockery::mock(), 41, gcWebhook([['id' => 'EV1', 'resource_type' => 'payments', 'action' => 'failed', 'links' => ['payment' => 'PM123']]]), 3);
    expect($world->db->fetchAssociative('SELECT status, txn_status FROM `transaction` WHERE id = 40'))->toBe(['status' => 'error', 'txn_status' => 'failed'])
        ->and(PendingPayments::forInvoice($world->di, 5))->toBeNull()
        // The hold of the collection becomes its one failed try (attempt_1): retried as set, and the client can pay again.
        ->and($world->db->fetchOne('SELECT attempts FROM invoice_card_charge WHERE invoice_id = 5'))->toBe(1);
});

test('a paid payment charged back is taken back as a chargeback, once', function (): void {
    $world = gcWorld(['GET /payments/PM123' => gcPayment('charged_back')]);
    $world->db->insert('`transaction`', ['id' => 40, 'invoice_id' => 5, 'gateway_id' => 3, 'txn_id' => 'PM123', 'type' => 'gocardless', 'status' => 'processed', 'txn_status' => 'Completed', 'amount' => '12.5', 'currency' => 'GBP']);
    $world->db->insert('`transaction`', ['id' => 41, 'gateway_id' => 3, 'status' => 'received']);
    $world->db->insert('`transaction`', ['id' => 42, 'gateway_id' => 3, 'status' => 'received']);
    $world->services->shouldReceive('chargeback')->once()->with(Mockery::type(Model_Invoice::class), 12.5, 'GoCardless chargeback of payment PM123.')->andReturn('GoCardless chargeback of payment PM123. Due dates moved back: order #9 due 2026-10-20.');
    $event = ['id' => 'EV1', 'resource_type' => 'payments', 'action' => 'charged_back', 'links' => ['payment' => 'PM123']];

    $world->adapter->processTransaction(Mockery::mock(), 41, gcWebhook([$event]), 3);
    expect($world->db->fetchOne('SELECT note FROM `transaction` WHERE id = 41'))->toStartWith('GoCardless chargeback of payment PM123.');

    $world->adapter->processTransaction(Mockery::mock(), 42, gcWebhook([$event + ['id' => 'EV2']]), 3);
    expect($world->db->fetchOne('SELECT note FROM `transaction` WHERE id = 42'))->toBe('No action needed.');

    // GoCardless does not answer the read-back: the webhook fails (to process again), nothing is taken back yet.
    $busy = gcWorld(['GET /payments/PM123' => [503, ['error' => ['message' => 'Service unavailable']]]]);
    $busy->db->insert('`transaction`', ['id' => 40, 'invoice_id' => 5, 'gateway_id' => 3, 'txn_id' => 'PM123', 'type' => 'gocardless', 'status' => 'processed', 'txn_status' => 'Completed', 'amount' => '12.5', 'currency' => 'GBP']);
    $busy->db->insert('`transaction`', ['id' => 41, 'gateway_id' => 3, 'status' => 'received']);
    $busy->services->shouldNotReceive('chargeback');
    expect(fn () => $busy->adapter->processTransaction(Mockery::mock(), 41, gcWebhook([$event]), 3))->toThrow(Payment_Exception::class, 'was not found');
});

test('a stale pending answer never turns a recorded payment back into a pending one', function (): void {
    $world = gcWorld(['GET /payments/PM123' => gcPayment('submitted')]);
    $world->db->insert('`transaction`', ['id' => 40, 'invoice_id' => 5, 'gateway_id' => 3, 'txn_id' => 'PM123', 'type' => 'gocardless', 'status' => 'processed', 'txn_status' => 'Completed', 'created_at' => date('Y-m-d H:i:s')]);
    $world->adapter->processTransaction(Mockery::mock(), 40, ['source' => 'saved_card', 'get' => ['gocardless_payment' => 'PM123']], 3);

    expect($world->db->fetchAssociative('SELECT status, txn_status FROM `transaction` WHERE id = 40'))->toBe(['status' => 'processed', 'txn_status' => 'Completed']);
});

test('a mandate cancelled at GoCardless is removed, and Remove cancels it at GoCardless', function (): void {
    $world = gcWorld(['GET /mandates/MD1' => ['mandates' => ['id' => 'MD1', 'status' => 'cancelled']]]);
    $world->db->insert('`transaction`', ['id' => 41, 'gateway_id' => 3, 'status' => 'received']);
    $world->adapter->processTransaction(Mockery::mock(), 41, gcWebhook([['id' => 'EV1', 'resource_type' => 'mandates', 'action' => 'cancelled', 'links' => ['mandate' => 'MD1']]]), 3);
    expect(gcSaved($world->db))->toBeNull()
        ->and($world->db->fetchOne('SELECT note FROM `transaction` WHERE id = 41'))->toBe('Direct Debit MD1 cancelled at GoCardless: removed.');

    $removed = gcWorld(['POST /mandates/MD1/actions/cancel' => ['mandates' => ['id' => 'MD1', 'status' => 'cancelled']]]);
    $removed->adapter->cardRemove(7);
    expect($removed->calls[0][0])->toBe('POST /mandates/MD1/actions/cancel')
        ->and(gcSaved($removed->db))->toBeNull();
});

test('the Direct Debit cannot be removed or replaced while one of its payments is being collected', function (): void {
    $world = gcWorld(['POST /mandates/MD1/actions/cancel' => ['mandates' => ['id' => 'MD1', 'status' => 'cancelled']]]);
    gcPending($world->db);

    expect(fn () => $world->adapter->cardRemove(7))->toThrow(ClientFence\InformationException::class, 'A Direct Debit payment is still being collected.');
    expect(fn () => $world->adapter->cardSetup(7, 'https://portal.example/payment-methods/gocardless/saved'))->toThrow(ClientFence\InformationException::class, 'A Direct Debit payment is still being collected.');
    expect($world->calls)->toBe([])
        ->and(gcSaved($world->db))->toBe('MD1 GBP');

    // A payment clearing at another gateway (a Stripe SEPA debit) does not stop the client removing the mandate.
    $other = gcWorld(['POST /mandates/MD1/actions/cancel' => ['mandates' => ['id' => 'MD1', 'status' => 'cancelled']]]);
    $other->db->insert('`transaction`', ['id' => 40, 'invoice_id' => 5, 'gateway_id' => 9, 'txn_id' => 'pi_1', 'type' => 'stripe', 'status' => 'received', 'txn_status' => 'processing', 'amount' => '12.5', 'currency' => 'GBP', 'created_at' => date('Y-m-d H:i:s')]);
    $other->adapter->cardRemove(7);
    expect(gcSaved($other->db))->toBeNull();
});

test('refunds in the currency\'s smallest unit, confirming the total refunded, and explains refunds that are off', function (): void {
    $world = gcWorld([
        'GET /payments/PM123' => gcPayment('confirmed', ['amount_refunded' => 200]),
        'POST /refunds' => ['refunds' => ['id' => 'RF1', 'status' => 'created']],
    ]);
    expect($world->adapter->refundPayment('PM123', 5.25, 'GBP'))->toBe('RF1')
        ->and($world->calls[1][1])->toBe(['refunds' => ['amount' => 525, 'total_amount_confirmation' => 725, 'links' => ['payment' => 'PM123']]])
        ->and($world->calls[1][4])->toBe('Idempotency-Key: cf-refund-PM123-725');
    $world->adapter->refundPayment('PM123', 500.0, 'JPY');
    expect($world->calls[3][1]['refunds']['amount'])->toBe(500);

    $off = gcWorld([
        'GET /payments/PM123' => gcPayment('confirmed'),
        'POST /refunds' => [403, ['error' => ['message' => 'Forbidden', 'errors' => [['reason' => 'forbidden']]]]],
    ]);
    expect(fn () => $off->adapter->refundPayment('PM123', 5.0, 'GBP'))->toThrow(ClientFence\InformationException::class, 'ask GoCardless to enable them');
});
