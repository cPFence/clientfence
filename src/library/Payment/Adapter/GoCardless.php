<?php

declare(strict_types=1);

/**
 * ClientFence: GoCardless Direct Debit (Bacs, SEPA, BECS, ACH, PAD and GoCardless's other schemes), in the ways of eWAY
 * and as WHMCS and Blesta collect from a mandate. The account owner sets up a Direct Debit (a GoCardless mandate) on
 * GoCardless's page, while paying an invoice or on the Payment methods page. It is then their saved method
 * (Invoice/SavedCards.php): the cron and staff collect invoices from it, and the client can pay an invoice with it.
 * A payment takes days: until GoCardless confirms it, it is "Payment pending" (Invoice/PendingPayments.php). Its signed
 * webhooks to ipn.php settle it; every payment and mandate is read back from GoCardless with the access token, so only
 * this account's payments made here (metadata cf-invoice-...) pay an invoice. A confirmed payment that later fails or
 * is charged back is handled as a chargeback. Follows GoCardless's API documentation (version 2015-07-06); not tested
 * against a live GoCardless account yet.
 */
class Payment_Adapter_GoCardless implements ClientFence\InjectionAwareInterface
{
    /** Payments GoCardless is still collecting, and the ones it has collected (confirmed is when WHMCS marks an invoice paid). */
    private const PENDING = ['pending_customer_approval', 'pending_submission', 'submitted'];
    private const PAID = ['confirmed', 'paid_out'];

    private const SCHEMES = [
        'bacs' => 'Bacs Direct Debit', 'sepa_core' => 'SEPA Direct Debit', 'becs' => 'BECS Direct Debit', 'becs_nz' => 'BECS NZ Direct Debit',
        'ach' => 'ACH Debit', 'pad' => 'PAD Debit', 'autogiro' => 'Autogiro', 'betalingsservice' => 'Betalingsservice', 'pay_to' => 'PayTo',
    ];

    protected ?Pimple\Container $di = null;

    public function setDi(Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Pimple\Container
    {
        return $this->di;
    }

    public function __construct(private array $config)
    {
        $prefix = empty($this->config['test_mode']) ? '' : 'test_';
        foreach ([$prefix . 'access_token', 'webhook_secret'] as $key) {
            if (trim((string) ($this->config[$key] ?? '')) === '') {
                throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'GoCardless', ':missing' => ($prefix === '' ? 'live' : 'sandbox') . ' access token and webhook endpoint secret'], 4001);
            }
        }
    }

    public static function getConfig(): array
    {
        $onOff = ['1' => __trans('On'), '0' => __trans('Off')];

        return [
            'title' => 'GoCardless',
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => __trans('Direct Debit with GoCardless. Clients set up a Direct Debit on GoCardless\' page and their invoices are then collected from their bank account automatically. A payment takes a few days: the invoice is marked paid when GoCardless confirms it.'),
            'form' => [
                'access_token' => ['password', ['label' => __trans('Live access token:'), 'secret' => true, 'description' => __trans('GoCardless: Developers, Create, Access token (read-write). Test mode uses the access token of your GoCardless sandbox account. [How to set up GoCardless](https://clientfence.com/docs/clients-and-billing/gocardless/)')]],
                'test_access_token' => ['password', ['label' => __trans('Sandbox access token:'), 'secret' => true]],
                'webhook_secret' => ['password', ['label' => __trans('Webhook endpoint secret:'), 'secret' => true, 'description' => __trans('GoCardless: Developers, Create, Webhook endpoint, with this gateway\'s IPN Callback URL. The sandbox has its own endpoint and secret.')]],
                'pay_saved' => ['radio', ['label' => __trans('Pay with the saved Direct Debit:'), 'description' => __trans('On the invoice page, a client with a Direct Debit can start the payment at once without going to GoCardless.'), 'multiOptions' => $onOff]],
                'save_only' => ['radio', ['label' => __trans('Set up on the Payment methods page:'), 'description' => __trans('Clients can set up a Direct Debit on their Payment methods page without paying an invoice.'), 'multiOptions' => $onOff]],
                'free_orders' => ['radio', ['label' => __trans('Free orders:'), 'description' => __trans('After a free order that renews (a free trial), a client without a saved payment method is sent to GoCardless to set up a Direct Debit.'), 'multiOptions' => $onOff]],
            ],
        ];
    }

    public function gatewayId(): int
    {
        return (int) ($this->config['gateway_id'] ?? 0);
    }

    public function testMode(): bool
    {
        return !empty($this->config['test_mode']);
    }

    /** Whether a setting with an On/Off choice is on ($default when it was never saved). */
    public function option(string $key, bool $default = true): bool
    {
        return (string) ($this->config[$key] ?? ($default ? '1' : '0')) === '1';
    }

    /** Refunds (Invoice ServiceSubscription::gatewayCan()) always go to GoCardless. */
    public function hasApiKeys(): bool
    {
        return true;
    }

    /** On saving the gateway: GoCardless accepts the access token (it lists the account's creditors). */
    public function testConnection(): void
    {
        $reply = $this->call('GET', '/creditors');
        if (in_array($reply['http'] ?? 200, [401, 403], true)) {
            throw new ClientFence\InformationException('GoCardless refused the access token (live and sandbox tokens differ): :error', [':error' => $this->error($reply)]);
        }
    }

    /** The client's saved Direct Debit ({payment_method: "mandate currency", card, auto_pay_at}), or null. */
    public function savedCard(int $clientId): ?array
    {
        return ClientFence\Mod\Invoice\SavedCards::saved($this->di, $this->gatewayId(), $this->testMode(), $clientId);
    }

    /** Whether GoCardless signed a webhook body ($signature: its Webhook-Signature header). */
    public function webhookSigned(string $body, string $signature): bool
    {
        return hash_equals(hash_hmac('sha256', $body, trim((string) $this->config['webhook_secret'])), $signature);
    }

    /** The payment choices for an invoice, as eWAY: the account owner pays with their Direct Debit, or sets one up and pays. */
    public function getHtml($api_admin, $invoice_id, $subscription): string
    {
        $invoice = $this->di['db']->getExistingModelById('Invoice', (int) $invoice_id, 'Invoice not found');
        $user = ClientFence\Mod\Client\Users::context($this->di);
        $owner = (int) ($this->di['session']->get('client_id') ?? 0) === (int) $invoice->client_id && ($user === null || $user['owner']);
        $saved = $owner ? $this->savedCard((int) $invoice->client_id) : null;
        $text = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($saved !== null && $this->option('pay_saved')) {
            return '<p><button type="button" class="btn btn-primary" data-cf-gocardless-pay>' . $text(__trans('Pay now with :card', [':card' => $saved['card']])) . '</button></p>'
                . '<script nonce="' . ClientFence\Csp::nonce() . '">document.querySelector("[data-cf-gocardless-pay]").addEventListener("click", function (event) {'
                . ' var button = event.currentTarget; button.disabled = true;'
                . ' ClientFence.api.client.post("invoice/gocardless_pay", ' . json_encode(['hash' => $invoice->hash], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ', function (result) {'
                . ' if (result.paid) { location.assign(' . json_encode((string) $this->di['url']->link('invoice/thank-you/' . $invoice->hash), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) . '); } else { ClientFence.message(result.message, "info"); }'
                . ' }, function (error) { button.disabled = false; ClientFence.message(error.message, "error"); });'
                . ' });</script>';
        }
        if ($owner) {
            return '<p><a class="btn btn-primary" href="' . $text((string) $this->di['url']->link('invoice/gocardless/' . $invoice->hash)) . '">' . $text(__trans('Pay by Direct Debit and set up automatic payments')) . '</a></p>'
                . '<p class="text-muted">' . $text(__trans('Your renewal invoices are then collected by this Direct Debit. You can cancel it on your Payment methods page.')) . '</p>';
        }

        return '<p>' . $text(__trans('Sign in as the account owner to pay by Direct Debit.')) . '</p>';
    }

    /**
     * Starts setting up a Direct Debit in the invoice's currency to pay it (the account owner): GoCardless's page.
     * GoCardless sends the client back to invoice/gocardless-paid, which keeps the mandate and collects the invoice.
     */
    public function checkout(Model_Invoice $invoice): string
    {
        return $this->startFlow((int) $invoice->client_id, (string) $invoice->currency, 'cf-client-' . (int) $invoice->client_id . '-invoice-' . (int) $invoice->id,
            (string) $this->di['url']->link('invoice/gocardless-paid', ['hash' => $invoice->hash]), (string) $this->di['url']->link('invoice/' . $invoice->hash));
    }

    /**
     * Starts setting up a Direct Debit in the client's currency without a payment (the Payment methods page, a free
     * order): GoCardless's page. GoCardless sends the client back to $returnUrl (mandateSaved()).
     */
    public function cardSetup(int $clientId, string $returnUrl): string
    {
        $currency = (string) $this->di['mod_service']('Currency')->getCurrencyByClientId($clientId)->getCode();

        return $this->startFlow($clientId, $currency, 'cf-client-' . $clientId, $returnUrl, (string) $this->di['url']->link('payment-methods'));
    }

    /**
     * Back from GoCardless with the billing request ($requestId) the setup made: keeps its mandate for the client it
     * names. Returns [client, invoice to pay (0: none)].
     */
    public function mandateSaved(string $requestId): array
    {
        $request = preg_match('/^BRQ[0-9A-Z]{1,30}$/', $requestId) === 1 ? ($this->call('GET', '/billing_requests/' . $requestId)['billing_requests'] ?? []) : [];
        $mandateId = (string) ($request['links']['mandate_request_mandate'] ?? '');
        // A setup counts within an hour of its start: an old return sent again never brings back a Direct Debit the client removed.
        if (($request['status'] ?? '') !== 'fulfilled' || $mandateId === '' || time() - (int) strtotime((string) ($request['created_at'] ?? '')) > 3600
            // Only a set-up this install started: another portal on the same GoCardless account names its own clients.
            || ($request['metadata']['site'] ?? '') !== INSTANCE_ID
            || preg_match('/^cf-client-(\d+)(?:-invoice-(\d+))?$/', (string) ($request['metadata']['clientfence'] ?? ''), $match) !== 1) {
            throw new ClientFence\InformationException('GoCardless did not set up the Direct Debit: :error', [':error' => (string) ($request['status'] ?? 'not found')]);
        }
        $mandate = $this->call('GET', '/mandates/' . rawurlencode($mandateId))['mandates'] ?? [];
        $account = $this->call('GET', '/customer_bank_accounts/' . rawurlencode((string) ($mandate['links']['customer_bank_account'] ?? '')))['customer_bank_accounts'] ?? [];
        $label = (self::SCHEMES[$mandate['scheme'] ?? ''] ?? __trans('Direct Debit')) . ' ••' . ($account['account_number_ending'] ?? '');
        $this->remember((int) $match[1], $mandateId . ' ' . strtoupper((string) ($account['currency'] ?? $request['mandate_request']['currency'] ?? '')), $label);

        return [(int) $match[1], (int) ($match[2] ?? 0)];
    }

    /**
     * Collects an invoice's balance from the client's Direct Debit (the cron, staff, or the client on the invoice page).
     * $attempt names the try. Returns GoCardless's payment ID; the payment then waits until GoCardless collects it. A
     * refusal throws InformationException, no answer Payment_Exception.
     */
    public function chargeSavedCard(Model_Invoice $invoice, string $attempt): string
    {
        $saved = $this->savedCard((int) $invoice->client_id) ?? throw new ClientFence\InformationException('The client has no Direct Debit set up.');
        [$mandateId, $currency] = explode(' ', $saved['payment_method'] . ' ', 2);
        $currency = trim($currency);
        // Each scheme collects one currency (GBP for Bacs, EUR for SEPA...): GoCardless would refuse the payment.
        if ($currency !== strtoupper((string) $invoice->currency)) {
            throw new ClientFence\InformationException('The Direct Debit collects :mandate, the invoice is in :invoice: pay this invoice another way.', [':mandate' => $currency, ':invoice' => $invoice->currency]);
        }
        $service = $this->di['mod_service']('Invoice');
        $reference = 'cf-invoice-' . $invoice->id . '-' . $attempt;
        $reply = $this->call('POST', '/payments', ['payments' => [
            'amount' => $this->minorUnits($service->getBalanceDue($invoice), $currency),
            'currency' => $currency,
            'description' => __trans('Invoice :number', [':number' => $service->toApiArray($invoice)['serie_nr']]),
            'metadata' => ['clientfence' => $reference],
            'links' => ['mandate' => $mandateId],
        ]], $reference . '-' . substr(hash('sha256', (string) $invoice->hash), 0, 12)); // The hash part keeps two installs on one account apart.
        if (!empty($reply['payments']['id'])) {
            return (string) $reply['payments']['id'];
        }
        if ($this->reason($reply) === 'mandate_is_inactive') {
            // As WHMCS: a Direct Debit that was cancelled, failed or expired is removed, so it is not tried again.
            $this->forget($mandateId);
        } elseif (($reply['http'] ?? 0) >= 500 || in_array($reply['http'] ?? 0, [401, 403], true)) {
            // Not a refusal of the payment: the invoice is held (SavedCards::charge()), the client gets no email.
            throw new Payment_Exception('GoCardless did not answer :path: :error', [':path' => '/payments', ':error' => $this->error($reply)]);
        }

        throw new ClientFence\InformationException('GoCardless refused the payment: :error', [':error' => $this->error($reply)]);
    }

    /** Removes the client's Direct Debit, here and at GoCardless (the mandate is cancelled). */
    public function cardRemove(int $clientId): void
    {
        $saved = $this->savedCard($clientId);
        if ($saved !== null) {
            $this->refuseWhileCollecting($clientId);
            $this->cancelMandate((string) strtok($saved['payment_method'], ' '));
            $this->forget((string) strtok($saved['payment_method'], ' '));
        }
    }

    /** Sends money of a payment back (staff refunds): GoCardless's refund ID. */
    public function refundPayment(string $transactionId, float $amount, string $currency): string
    {
        $payment = $this->call('GET', '/payments/' . rawurlencode($transactionId))['payments'] ?? [];
        $minor = $this->minorUnits($amount, $currency);
        // GoCardless checks the total refunded so far, so two refunds made at once cannot both go through.
        $total = (int) ($payment['amount_refunded'] ?? 0) + $minor;
        $reply = $this->call('POST', '/refunds', ['refunds' => ['amount' => $minor, 'total_amount_confirmation' => $total, 'links' => ['payment' => $transactionId]]], 'cf-refund-' . $transactionId . '-' . $total);
        if (!empty($reply['refunds']['id'])) {
            return (string) $reply['refunds']['id'];
        }
        if (($reply['http'] ?? 0) === 403) {
            throw new ClientFence\InformationException('GoCardless did not make the refund: refunds are off for your GoCardless account (ask GoCardless to enable them). :error', [':error' => $this->error($reply)]);
        }

        throw new ClientFence\InformationException('GoCardless did not make the refund: :error', [':error' => $this->error($reply)]);
    }

    /**
     * A payment made here (source saved_card, get.gocardless_payment) or GoCardless's signed webhook to ipn.php. Every
     * payment and mandate a webhook names is read back from GoCardless.
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id): void
    {
        $id = (int) $id;
        // Only ClientFence's own pages pass a payment ID: a request to ipn.php naming one is never trusted.
        if (isset($data['get']['gocardless_payment']) && ($data['source'] ?? '') !== 'ipn') {
            $this->recordPayment($api_admin, $id, (string) $data['get']['gocardless_payment']);

            return;
        }
        $body = (string) ($data['http_raw_post_data'] ?? '');
        if (!$this->webhookSigned($body, (string) ($data['server']['HTTP_WEBHOOK_SIGNATURE'] ?? ''))) {
            throw new Payment_Exception('GoCardless webhook signature does not match');
        }
        $notes = [];
        foreach ((json_decode($body, true)['events'] ?? []) as $event) {
            $notes[] = match ($event['resource_type'] ?? '') {
                'payments' => $this->paymentEvent($api_admin, (string) ($event['links']['payment'] ?? '')),
                'mandates' => $this->mandateEvent((string) ($event['links']['mandate'] ?? '')),
                default => null,
            };
        }
        $this->di['dbal']->update('`transaction`', ['type' => 'gocardless_webhook', 'status' => Model_Transaction::STATUS_PROCESSED, 'error' => null, 'note' => implode(' ', array_filter($notes)) ?: 'No action needed.', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
    }

    /** A webhook event of a payment: settles the payment waiting here, or takes back a recorded one that failed late or was charged back. */
    private function paymentEvent($api_admin, string $paymentId): ?string
    {
        if (preg_match('/^PM[0-9A-Z]{1,30}$/', $paymentId) !== 1) {
            return null;
        }
        $db = $this->di['dbal'];
        $pending = $db->fetchOne("SELECT id FROM `transaction` WHERE txn_id = ? AND gateway_id = ? AND status = ? AND txn_status = 'processing' ORDER BY id LIMIT 1", [$paymentId, $this->gatewayId(), Model_Transaction::STATUS_RECEIVED]);
        if ($pending !== false) {
            $this->recordPayment($api_admin, (int) $pending, $paymentId);

            return 'Payment ' . $paymentId . ': transaction #' . $pending . '.';
        }
        $paid = $db->fetchAssociative('SELECT invoice_id, amount FROM `transaction` WHERE txn_id = ? AND gateway_id = ? AND status = ? AND txn_status = ? ORDER BY id LIMIT 1', [$paymentId, $this->gatewayId(), Model_Transaction::STATUS_PROCESSED, 'Completed']);
        if ($paid === false) {
            return null;
        }
        $payment = $this->call('GET', '/payments/' . $paymentId)['payments'] ?? [];
        // No answer: the webhook fails (kept with the error, to process again) rather than losing a chargeback.
        if (($payment['id'] ?? '') !== $paymentId) {
            throw new Payment_Exception('GoCardless payment :id was not found', [':id' => $paymentId]);
        }
        $status = (string) ($payment['status'] ?? '');
        if (!in_array($status, ['failed', 'charged_back'], true)) {
            return null;
        }

        return ClientFence\Tools::withLock($this->di, 'cf:gocardless-payment:' . $paymentId, function () use ($db, $paymentId, $paid, $payment, $status): ?string {
            // GoCardless sends an event again until it is answered: a payment is taken back once.
            if ($db->fetchOne("SELECT 1 FROM `transaction` WHERE txn_id = ? AND gateway_id = ? AND status = ? AND txn_status IN ('failed', 'charged_back')", [$paymentId, $this->gatewayId(), Model_Transaction::STATUS_PROCESSED]) !== false) {
                return null;
            }
            $invoice = $this->di['db']->getExistingModelById('Invoice', (int) $paid['invoice_id'], 'Invoice not found');
            // As a Stripe dispute: refunded on its invoice, and the services it renewed go back by their period.
            $note = $this->di['mod_service']('Invoice')->chargeback($invoice, min($this->fromMinorUnits((int) ($payment['amount'] ?? 0), (string) $invoice->currency), (float) $paid['amount']),
                sprintf('GoCardless %s of payment %s.', $status === 'failed' ? 'late failure' : 'chargeback', $paymentId));
            $now = date('Y-m-d H:i:s');
            $db->insert('`transaction`', ['invoice_id' => (int) $invoice->id, 'gateway_id' => $this->gatewayId(), 'txn_id' => $paymentId, 'txn_status' => $status, 'type' => 'gocardless', 'status' => Model_Transaction::STATUS_PROCESSED, 'note' => $note, 'created_at' => $now, 'updated_at' => $now]);

            return $note;
        });
    }

    /** A webhook event of a mandate: one that can no longer collect is removed. */
    private function mandateEvent(string $mandateId): ?string
    {
        if (preg_match('/^MD[0-9A-Z]{1,30}$/', $mandateId) !== 1) {
            return null;
        }
        $status = (string) ($this->call('GET', '/mandates/' . $mandateId)['mandates']['status'] ?? '');
        if (!in_array($status, ['cancelled', 'failed', 'expired', 'consumed', 'blocked'], true)) {
            return null;
        }
        $this->forget($mandateId);

        return 'Direct Debit ' . $mandateId . ' ' . $status . ' at GoCardless: removed.';
    }

    /** A payment as GoCardless reports it: waiting, failed, or recorded on transaction $id and applied to its invoice once. */
    private function recordPayment($api_admin, int $id, string $paymentId): void
    {
        $reply = [];
        if (preg_match('/^PM[0-9A-Z]{1,30}$/', $paymentId) === 1) {
            try {
                $reply = $this->call('GET', '/payments/' . $paymentId);
            } catch (Payment_Exception) {
                $reply = ['http' => 0];
            }
        }
        $payment = $reply['payments'] ?? [];
        if (($payment['id'] ?? '') !== $paymentId && isset($reply['http']) && ($reply['http'] === 0 || $reply['http'] === 429 || $reply['http'] >= 500)) {
            // GoCardless did not answer: the payment waits as pending (no second payment starts) until its webhook settles it.
            // Only a row still waiting: a paid or failed one keeps its result, so a later webhook cannot act on it twice.
            ClientFence\Tools::withLock($this->di, 'cf:gocardless-payment:' . $paymentId, fn () => $this->di['dbal']->executeStatement("UPDATE `transaction` SET txn_id = ?, type = 'gocardless', txn_status = 'processing', status = ?, error = ?, updated_at = ? WHERE id = ? AND status = ?", [$paymentId, Model_Transaction::STATUS_RECEIVED, 'GoCardless did not answer when payment ' . $paymentId . ' was read back: its webhook settles it.', date('Y-m-d H:i:s'), $id, Model_Transaction::STATUS_RECEIVED]));

            return;
        }
        if (($payment['id'] ?? '') !== $paymentId) {
            throw new Payment_Exception('GoCardless payment :id was not found', [':id' => $paymentId]);
        }
        // Only payments made here name an invoice: one of another shop on the same account pays nothing.
        if (preg_match('/^cf-invoice-(\d+)-(\w+)/', (string) ($payment['metadata']['clientfence'] ?? ''), $match) !== 1) {
            throw new Payment_Exception('GoCardless payment :id is not for a ClientFence invoice', [':id' => $paymentId]);
        }
        $invoice = $this->di['db']->getExistingModelById('Invoice', (int) $match[1], 'Invoice not found');
        $currency = strtoupper((string) ($payment['currency'] ?? ''));
        $amount = $this->fromMinorUnits((int) ($payment['amount'] ?? 0), $currency);
        $status = (string) ($payment['status'] ?? '');
        $db = $this->di['dbal'];
        $row = ['invoice_id' => $invoice->id, 'txn_id' => $paymentId, 'type' => 'gocardless', 'amount' => $amount, 'currency' => $currency, 'updated_at' => date('Y-m-d H:i:s')];
        if (in_array($status, self::PENDING, true)) {
            // As WHMCS's "Payment Pending": no other charge or reminder until GoCardless collects it (its webhook).
            // Under the payment's lock and only on a row still waiting: a stale answer never turns a payment being recorded back into a pending one.
            ClientFence\Tools::withLock($this->di, 'cf:gocardless-payment:' . $paymentId, fn () => $db->update('`transaction`', $row + ['txn_status' => 'processing', 'status' => Model_Transaction::STATUS_RECEIVED, 'error' => 'GoCardless is collecting the payment: ' . $status], ['id' => $id, 'status' => Model_Transaction::STATUS_RECEIVED]));

            return;
        }
        if (!in_array($status, self::PAID, true)) {
            $reason = 'GoCardless payment ' . $status . ': ' . ($payment['description'] ?? '');
            $waited = ClientFence\Tools::withLock($this->di, 'cf:gocardless-payment:' . $paymentId, static function () use ($db, $id, $row, $status, $reason): bool {
                $waited = $db->fetchOne("SELECT 1 FROM `transaction` WHERE id = ? AND status = ? AND txn_status = 'processing'", [$id, Model_Transaction::STATUS_RECEIVED]) !== false;
                $db->update('`transaction`', $row + ['txn_status' => $status, 'status' => Model_Transaction::STATUS_ERROR, 'error' => $reason], ['id' => $id, 'status' => Model_Transaction::STATUS_RECEIVED]);

                return $waited;
            });
            // A collection that failed counts as one failed try: retried as set, and the client can pay again.
            if ($waited) {
                ClientFence\Mod\Invoice\SavedCards::debitFailedTry($this->di, $invoice, (string) ($this->savedCard((int) $invoice->client_id)['card'] ?? ''), 'GoCardless payment ' . $status . '.', $match[2]);
            }

            return;
        }
        // The webhook and "Process again" may record the same payment at once.
        ClientFence\Tools::withLock($this->di, 'cf:gocardless-payment:' . $paymentId, fn () => $this->applyPayment($api_admin, $id, $paymentId, $invoice, $amount, $currency, $row));
    }

    /** Applies a collected payment to its invoice once. */
    private function applyPayment($api_admin, int $id, string $paymentId, Model_Invoice $invoice, float $amount, string $currency, array $row): void
    {
        $db = $this->di['dbal'];
        // Applied already ("Process again", another GoCardless gateway): never twice.
        $done = $db->fetchOne("SELECT id FROM `transaction` WHERE txn_id = ? AND type = 'gocardless' AND status = ? AND txn_status = ? ORDER BY id LIMIT 1", [$paymentId, Model_Transaction::STATUS_PROCESSED, 'Completed']);
        if ($done !== false) {
            if ((int) $done !== $id) {
                $db->update('`transaction`', ['txn_id' => $paymentId, 'status' => Model_Transaction::STATUS_PROCESSED, 'note' => 'Payment ' . $paymentId . ' was already recorded on transaction #' . $done . '.', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
            }

            return;
        }
        if ($currency !== strtoupper((string) $invoice->currency)) {
            throw new Payment_Exception('GoCardless payment :id is in :currency, the invoice in :invoice', [':id' => $paymentId, ':currency' => $currency, ':invoice' => $invoice->currency]);
        }
        $service = $this->di['mod_service']('Invoice');
        $service->validatePaymentAmount($amount, $service->getBalanceDue($invoice), $invoice);
        $db->update('`transaction`', $row + ['txn_status' => 'Completed'], ['id' => $id]);
        // As PayPal: the payment goes to the client's credit, which then pays the invoice.
        // A retry after a try that failed once the payment was credited.
        if (!\ClientFence\Mod\Client\Service::hasGatewayCredit($this->di['dbal'], 'GoCardless', $paymentId)) {
            $api_admin->client_balance_add_funds(['id' => $invoice->client_id, 'amount' => $amount, 'description' => 'GoCardless payment ' . $paymentId, 'type' => 'GoCardless', 'rel_id' => $paymentId]);
        }
        if ($service->isInvoiceTypeDeposit($invoice)) {
            $service->markAsPaid($invoice);
        } else {
            if (!$invoice->approved) {
                $service->approveInvoice($invoice, ['use_credits' => false]);
            }
            $api_admin->invoice_pay_with_credits(['id' => $invoice->id]);
        }
        $db->update('`transaction`', ['status' => Model_Transaction::STATUS_PROCESSED, 'error' => null, 'error_code' => null, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
    }

    /** GoCardless's page to set up a mandate in $currency, filled in with the client's details ($reference names them): its address. */
    private function startFlow(int $clientId, string $currency, string $reference, string $returnUrl, string $exitUrl): string
    {
        // The new mandate replaces the saved one, which is then cancelled (remember()).
        if ($this->savedCard($clientId) !== null) {
            $this->refuseWhileCollecting($clientId);
        }
        $request = $this->call('POST', '/billing_requests', ['billing_requests' => [
            'mandate_request' => ['currency' => strtoupper($currency)],
            'metadata' => ['clientfence' => $reference, 'site' => INSTANCE_ID],
        ]]);
        $requestId = (string) ($request['billing_requests']['id'] ?? '');
        $client = $this->di['db']->getExistingModelById('Client', $clientId, 'Client not found');
        // GoCardless adds nothing to the return address: it carries the billing request, read back from GoCardless there.
        $flow = $requestId === '' ? $request : $this->call('POST', '/billing_request_flows', ['billing_request_flows' => [
            'redirect_uri' => $returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . http_build_query(['br' => $requestId]),
            'exit_uri' => $exitUrl,
            'links' => ['billing_request' => $requestId],
            'prefilled_customer' => array_filter([
                'given_name' => (string) $client->first_name,
                'family_name' => (string) $client->last_name,
                'company_name' => (string) $client->company,
                'email' => (string) $client->email,
                'address_line1' => (string) $client->address_1,
                'address_line2' => (string) $client->address_2,
                'city' => (string) $client->city,
                'region' => (string) $client->state,
                'postal_code' => (string) $client->postcode,
                'country_code' => strtoupper((string) $client->country),
            ]),
        ]]);
        if (empty($flow['billing_request_flows']['authorisation_url'])) {
            throw new ClientFence\InformationException('GoCardless did not start the Direct Debit set-up: :error', [':error' => $this->error($flow)]);
        }

        return (string) $flow['billing_request_flows']['authorisation_url'];
    }

    /** Keeps a mandate ("mandate currency") as the client's saved method; the one saved before is cancelled at GoCardless. */
    private function remember(int $clientId, string $method, string $label): void
    {
        $old = $this->savedCard($clientId)['payment_method'] ?? null;
        ClientFence\Mod\Invoice\SavedCards::remember($this->di, $this->gatewayId(), $this->testMode(), $clientId, '', $method, $label);
        if ($old !== null && strtok($old, ' ') !== strtok($method, ' ')) {
            $this->cancelMandate((string) strtok($old, ' '));
        }
    }

    /** Removes a mandate wherever it is saved. */
    private function forget(string $mandateId): void
    {
        $this->di['dbal']->executeStatement(
            'UPDATE pay_gateway_customer SET default_payment_method = NULL, default_card = NULL, updated_at = ? WHERE pay_gateway_id = ? AND test_mode = ? AND default_payment_method LIKE ?',
            [date('Y-m-d H:i:s'), $this->gatewayId(), (int) $this->testMode(), $mandateId . ' %']
        );
    }

    /** As WHMCS keeps a mandate: cancelling it at GoCardless would also cancel a payment of the client still being collected. */
    private function refuseWhileCollecting(int $clientId): void
    {
        // Only this gateway's payments: one clearing elsewhere (a Stripe SEPA debit) does not hang on the mandate.
        if ($this->di['dbal']->fetchOne("SELECT 1 FROM `transaction` t JOIN invoice i ON i.id = t.invoice_id WHERE i.client_id = ? AND t.gateway_id = ? AND t.status = 'received' AND t.txn_status = 'processing' AND t.created_at > ? LIMIT 1", [$clientId, $this->gatewayId(), ClientFence\Mod\Invoice\PendingPayments::since()]) !== false) {
            throw new ClientFence\InformationException('A Direct Debit payment is still being collected. You can remove the Direct Debit once it has finished.');
        }
    }

    private function cancelMandate(string $mandateId): void
    {
        try {
            $reply = $this->call('POST', '/mandates/' . rawurlencode($mandateId) . '/actions/cancel');
            if (isset($reply['error'])) {
                $this->di['logger']->warning('GoCardless: Direct Debit %s was not cancelled at GoCardless: %s', $mandateId, $this->error($reply));
            }
        } catch (Throwable $e) {
            $this->di['logger']->warning('GoCardless: Direct Debit %s was not cancelled at GoCardless: %s', $mandateId, $e->getMessage());
        }
    }

    /** GoCardless's API with the access token: the reply (http: the status of a refused call). No answer throws Payment_Exception. */
    private function call(string $method, string $path, array $fields = [], string $idempotencyKey = ''): array
    {
        try {
            $response = $this->di['http_client']->request($method, ($this->testMode() ? 'https://api-sandbox.gocardless.com' : 'https://api.gocardless.com') . $path, [
                'timeout' => 60,
                'auth_bearer' => trim((string) $this->config[($this->testMode() ? 'test_' : '') . 'access_token']),
                // A create sent again with the same key never makes a second payment or refund.
                'headers' => ['GoCardless-Version' => '2015-07-06', 'Accept' => 'application/json'] + ($idempotencyKey === '' ? [] : ['Idempotency-Key' => $idempotencyKey]),
            ] + ($fields === [] ? [] : ['json' => $fields]));
            $status = $response->getStatusCode();
            $reply = $status >= 400 ? (json_decode($response->getContent(false), true) ?: []) + ['http' => $status] : $response->toArray(false);
        } catch (Throwable $e) {
            throw new Payment_Exception('GoCardless did not answer :path: :error', [':path' => $path, ':error' => $e->getMessage()]);
        }

        return $reply;
    }

    /** The first reason of an error reply (e.g. mandate_is_inactive). */
    private function reason(array $reply): string
    {
        return (string) ($reply['error']['errors'][0]['reason'] ?? '');
    }

    /** GoCardless's error message and reasons, e.g. "Mandate is not active (mandate_is_inactive, HTTP 422)". */
    private function error(array $reply): string
    {
        $reasons = array_map(static fn ($error): string => (string) ($error['reason'] ?? $error['field'] ?? ''), (array) ($reply['error']['errors'] ?? []));
        $codes = array_filter([...$reasons, isset($reply['http']) ? 'HTTP ' . $reply['http'] : '']);

        return trim(($reply['error']['message'] ?? 'unknown error') . ($codes === [] ? '' : ' (' . implode(', ', $codes) . ')'));
    }

    /** GoCardless amounts are in the currency's smallest unit (GBP 12.50 = 1250). */
    private function minorUnits(float $amount, string $currency): int
    {
        return (int) round($amount * 10 ** $this->digits($currency));
    }

    private function fromMinorUnits(int $amount, string $currency): float
    {
        return round($amount / 10 ** $this->digits($currency), $this->digits($currency));
    }

    private function digits(string $currency): int
    {
        return Symfony\Component\Intl\Currencies::exists($currency) ? Symfony\Component\Intl\Currencies::getFractionDigits($currency) : 2;
    }
}
