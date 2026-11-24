<?php

declare(strict_types=1);

/**
 * ClientFence: Mollie (Payments API v2), with saved payment methods in the ways of PayTabs and eWAY. The client pays an
 * invoice on Mollie's page with the methods turned on in the Mollie dashboard; the account owner can save the card or
 * bank account there (a "first" payment that gives a Mollie mandate), or save a card or PayPal account without paying
 * (a first payment of 0.00); anyone can pay once when that is on. The saved method is then their saved method
 * (Invoice/SavedCards.php): the cron and staff charge it as a recurring payment, and the client can pay an invoice with
 * it. A SEPA Direct Debit charge stays pending for days: meanwhile the invoice shows "Payment pending"
 * (Invoice/PendingPayments.php). Mollie's webhook (ipn.php) and the return name a payment that is always read back
 * with the API key, so only this profile's payments made here (metadata clientfence) pay an invoice. With "Saved
 * payment methods" off, clients only pay once. A chargeback Mollie reports on a recorded payment is taken back on its
 * invoice (Invoice\Service::chargeback()), as Stripe's disputes. Pay-later methods that need a manual capture (status
 * authorized, such as Klarna) are not supported. Follows Mollie's API documentation; not tested against a live Mollie
 * account yet.
 */
class Payment_Adapter_Mollie implements ClientFence\InjectionAwareInterface
{
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
        if ($this->key() === '') {
            throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'Mollie', ':missing' => ($this->testMode() ? 'test' : 'live') . ' API key'], 4001);
        }
    }

    public static function getConfig(): array
    {
        $onOff = ['1' => __trans('On'), '0' => __trans('Off')];

        return [
            'title' => 'Mollie',
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => __trans('Payments on Mollie\'s payment page, with the payment methods turned on in your Mollie dashboard. Clients can save a card or bank account there and their invoices are then charged to it automatically. Pay-later methods that need a manual capture (such as Klarna) are not supported: do not turn them on for this portal.'),
            'form' => [
                'api_key' => ['password', ['label' => __trans('Live API key:'), 'secret' => true, 'description' => __trans('Mollie: Developers, API keys. The live key starts with live_, the test key with test_. [How to set up Mollie](https://clientfence.com/docs/clients-and-billing/mollie/)')]],
                'test_api_key' => ['password', ['label' => __trans('Test API key:'), 'secret' => true]],
                'saved_methods' => ['radio', ['label' => __trans('Saved payment methods:'), 'description' => __trans('Clients can save a card or bank account at Mollie and their invoices are then charged to it automatically. Turn it off if recurring payments are not enabled for your Mollie account; clients then pay each invoice on Mollie\'s page.'), 'multiOptions' => $onOff]],
                'pay_saved' => ['radio', ['label' => __trans('Pay with the saved payment method:'), 'description' => __trans('On the invoice page, a client with a saved payment method can pay at once without going to Mollie.'), 'multiOptions' => $onOff]],
                'pay_once' => ['radio', ['label' => __trans('Pay once without saving:'), 'description' => __trans('Clients can also pay an invoice without setting up automatic payments.'), 'multiOptions' => $onOff]],
                'save_only' => ['radio', ['label' => __trans('Set up on the Payment methods page:'), 'description' => __trans('Clients can save a card or PayPal account on their Payment methods page without paying an invoice (Mollie checks it without charging it).'), 'multiOptions' => $onOff]],
                'free_orders' => ['radio', ['label' => __trans('Free orders:'), 'description' => __trans('After a free order that renews (a free trial), a client without a saved payment method is sent to Mollie to save one.'), 'multiOptions' => $onOff]],
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

    /** Refunds (Invoice ServiceSubscription::gatewayCan()) always go to Mollie. */
    public function hasApiKeys(): bool
    {
        return true;
    }

    /** On saving the gateway: the key is one of this mode and Mollie accepts it (it lists the payment methods). */
    public function testConnection(): void
    {
        // A test key in the live field would mark invoices paid by test payments.
        if (!str_starts_with($this->key(), $this->testMode() ? 'test_' : 'live_')) {
            throw new ClientFence\InformationException('This Mollie API key does not start with :prefix (live keys start with live_, test keys with test_).', [':prefix' => $this->testMode() ? 'test_' : 'live_']);
        }
        $reply = $this->call('GET', '/methods');
        // A malformed key gets 400 "Invalid Authorization header", a wrong one 401.
        if (isset($reply['http'])) {
            throw new ClientFence\InformationException('Mollie refused the API key: :error', [':error' => $this->error($reply)]);
        }
    }

    /** The client's saved method ({payment_method: "customer mandate", card, auto_pay_at}), or null. */
    public function savedCard(int $clientId): ?array
    {
        return ClientFence\Mod\Invoice\SavedCards::saved($this->di, $this->gatewayId(), $this->testMode(), $clientId);
    }

    /**
     * The payment choices for an invoice, as eWAY. The account owner can pay with their saved method, or pay and save
     * it; anyone can pay once when that is on, or when saved methods are off (then the only way).
     */
    public function getHtml($api_admin, $invoice_id, $subscription): string
    {
        $invoice = $this->di['db']->getExistingModelById('Invoice', (int) $invoice_id, 'Invoice not found');
        $user = ClientFence\Mod\Client\Users::context($this->di);
        $saving = $this->option('saved_methods');
        $owner = $saving && (int) ($this->di['session']->get('client_id') ?? 0) === (int) $invoice->client_id && ($user === null || $user['owner']);
        $saved = $owner ? $this->savedCard((int) $invoice->client_id) : null;
        $link = fn (string $path): string => htmlspecialchars((string) $this->di['url']->link($path), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '';
        if ($saved !== null && $this->option('pay_saved')) {
            $html .= '<p><button type="button" class="btn btn-primary" data-cf-mollie-pay>' . $text(__trans('Pay now with :card', [':card' => $saved['card']])) . '</button></p>';
            $html .= '<script nonce="' . ClientFence\Csp::nonce() . '">document.querySelector("[data-cf-mollie-pay]").addEventListener("click", function (event) {'
                . ' var button = event.currentTarget; button.disabled = true;'
                . ' ClientFence.api.client.post("invoice/mollie_pay", ' . json_encode(['hash' => $invoice->hash], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ', function (result) {'
                . ' if (result.paid) { location.assign(' . json_encode((string) $this->di['url']->link('invoice/thank-you/' . $invoice->hash), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) . '); } else { ClientFence.message(result.message, "info"); }'
                . ' }, function (error) { button.disabled = false; ClientFence.message(error.message, "error"); });'
                . ' });</script>';
        } elseif ($owner) {
            $html .= '<p><a class="btn btn-primary" href="' . $link('invoice/mollie/' . $invoice->hash) . '">' . $text(__trans('Pay and set up automatic payments')) . '</a></p>'
                . '<p class="text-muted">' . $text(__trans('Your renewal invoices are then charged to this payment method. You can remove it on your Payment methods page.')) . '</p>';
        }
        if (!$saving || $this->option('pay_once')) {
            $html .= '<p><a class="btn ' . ($saving ? 'btn-outline-primary' : 'btn-primary') . '" href="' . $link('invoice/mollie/' . $invoice->hash . '?once=1') . '">' . $text($saving ? __trans('Pay once') : __trans('Pay now')) . '</a></p>';
        }

        return $html !== '' ? $html : '<p>' . $text(__trans('Sign in as the account owner to pay.')) . '</p>';
    }

    /**
     * Starts paying an invoice's balance on Mollie's page, saving the method when $save (the account owner): the page's
     * address. Mollie sends the client back to invoice/mollie-paid with our reference and its webhook to ipn.php;
     * either records the payment (processTransaction()).
     */
    public function checkout(Model_Invoice $invoice, bool $save): string
    {
        $reference = bin2hex(random_bytes(16));
        $fields = $this->payment($invoice, $save ? 'save' : 'pay') + ['redirectUrl' => (string) $this->di['url']->link('invoice/mollie-paid', ['ref' => $reference, 'hash' => $invoice->hash])];
        if ($save) {
            $fields += ['sequenceType' => 'first', 'customerId' => $this->customer((int) $invoice->client_id)];
        }

        return $this->startPage($fields, $reference);
    }

    /**
     * Starts saving a card or PayPal account without a payment (the Payment methods page, a free order): Mollie's page,
     * a first payment of 0.00 (Mollie allows it for those two). Mollie sends the client back to $returnUrl with our
     * reference (cardSaved()).
     */
    public function cardSetup(int $clientId, string $returnUrl): string
    {
        $reference = bin2hex(random_bytes(16));
        $currency = (string) $this->di['mod_service']('Currency')->getCurrencyByClientId($clientId)->getCode();

        return $this->startPage([
            'amount' => $this->amount(0.0, $currency),
            'description' => mb_substr(__trans('Automatic payments to :company', [':company' => (string) ($this->di['mod_service']('system')->getParamValue('company_name') ?: 'us')]), 0, 255),
            'redirectUrl' => $returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . http_build_query(['ref' => $reference]),
            'sequenceType' => 'first',
            'customerId' => $this->customer($clientId),
            'method' => ['creditcard', 'paypal'],
            'metadata' => ['clientfence' => 'setup', 'client_id' => $clientId],
        ], $reference);
    }

    /** Back from Mollie after cardSetup() with our reference: keeps the method for the client the setup names. Returns the client. */
    public function cardSaved(string $reference): int
    {
        $paymentId = $this->paymentOf($reference);
        $payment = $paymentId === '' ? [] : $this->call('GET', '/payments/' . $paymentId);
        $clientId = (int) ($payment['metadata']['client_id'] ?? 0);
        if (($payment['status'] ?? '') !== 'paid' || ($payment['metadata']['clientfence'] ?? '') !== 'setup' || empty($payment['mandateId'])
            || $clientId < 1 || (string) ($payment['customerId'] ?? '') !== $this->customerOf($clientId)) {
            throw new ClientFence\InformationException('Mollie did not save the payment method: :error', [':error' => $this->error($payment)]);
        }
        // Used once: a return sent again never brings back a method the client removed.
        $this->di['cache']->deleteItem($this->cacheKey($reference));
        $this->remember($clientId, $payment['customerId'] . ' ' . (string) $payment['mandateId'], $this->label($payment));

        return $clientId;
    }

    /**
     * Charges the client's saved method for an invoice's balance (the cron, staff, or the client on the invoice page), as
     * a recurring payment without the client. $attempt names the try. Returns Mollie's payment ID, also of a payment still
     * clearing; a refusal throws InformationException, no answer Payment_Exception.
     */
    public function chargeSavedCard(Model_Invoice $invoice, string $attempt): string
    {
        $saved = $this->savedCard((int) $invoice->client_id) ?? throw new ClientFence\InformationException('The client has no saved payment method.');
        [$customerId, $mandateId] = explode(' ', $saved['payment_method'] . ' ', 2);
        $reply = $this->call('POST', '/payments', $this->payment($invoice, 'charge') + ['sequenceType' => 'recurring', 'customerId' => $customerId, 'mandateId' => trim($mandateId)]);
        // Paid, or still clearing (a SEPA Direct Debit takes days; recorded as pending): either way taken, never tried again meanwhile.
        if (!empty($reply['id']) && in_array($reply['status'] ?? '', ['paid', 'pending', 'open'], true)) {
            return (string) $reply['id'];
        }
        if (($reply['http'] ?? 0) >= 500 || in_array($reply['http'] ?? 0, [401, 403], true)) {
            // Not a refusal of the method: the invoice is held (SavedCards::charge()), the client gets no email.
            throw new Payment_Exception('Mollie did not answer :path: :error', [':path' => '/payments', ':error' => $this->error($reply)]);
        }

        throw new ClientFence\InformationException('Mollie refused the payment: :error', [':error' => $this->error($reply)]);
    }

    /** Removes the client's saved method, here and at Mollie (the mandate is revoked). */
    public function cardRemove(int $clientId): void
    {
        $saved = $this->savedCard($clientId);
        if ($saved !== null) {
            $this->revoke($saved['payment_method']);
            $this->forget($saved['payment_method']);
        }
    }

    /** Sends money of a payment back (staff refunds): Mollie's refund ID. */
    public function refundPayment(string $transactionId, float $amount, string $currency): string
    {
        $reply = $this->call('POST', '/payments/' . rawurlencode($transactionId) . '/refunds', ['amount' => $this->amount($amount, $currency)]);
        if (empty($reply['id'])) {
            throw new ClientFence\InformationException('Mollie did not make the refund: :error', [':error' => $this->error($reply)]);
        }

        return (string) $reply['id'];
    }

    /** Back from Mollie after checkout(): the payment our reference names, '' when there is none (expired or unknown). */
    public function paymentOf(string $reference): string
    {
        $item = preg_match('/^[0-9a-f]{32}$/', $reference) === 1 ? $this->di['cache']->getItem($this->cacheKey($reference)) : null;

        return $item?->isHit() ? (string) $item->get() : '';
    }

    /**
     * A payment made here (source saved_card or mollie_return, get.mollie_payment) or Mollie's webhook to ipn.php
     * (post.id). A payment is always read back from Mollie: its metadata names the invoice.
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id): void
    {
        // Only ClientFence's own pages pass get.mollie_payment: on ipn.php only Mollie's post.id counts.
        $paymentId = (string) (($data['source'] ?? '') === 'ipn' ? ($data['post']['id'] ?? '') : ($data['get']['mollie_payment'] ?? ''));
        if (preg_match('/^tr_[A-Za-z0-9]{1,32}$/', $paymentId) !== 1) {
            throw new Payment_Exception('Mollie notice names no payment');
        }
        $this->recordPayment($api_admin, (int) $id, $paymentId);
    }

    /** A payment as Mollie reports it: recorded on transaction $id and applied to its invoice once. */
    private function recordPayment($api_admin, int $id, string $paymentId): void
    {
        $payment = $this->call('GET', '/payments/' . $paymentId);
        if (($payment['id'] ?? '') !== $paymentId) {
            throw new Payment_Exception('Mollie payment :id was not found: :error', [':id' => $paymentId, ':error' => $this->error($payment)]);
        }
        // Only payments made by this install name an invoice: one of another shop or portal on the same Mollie profile pays nothing.
        $purpose = (string) ($payment['metadata']['clientfence'] ?? '');
        if (!in_array($purpose, ['pay', 'save', 'charge'], true) || (int) ($payment['metadata']['invoice_id'] ?? 0) < 1 || ($payment['metadata']['site'] ?? '') !== INSTANCE_ID) {
            throw new Payment_Exception('Mollie payment :id is not for a ClientFence invoice', [':id' => $paymentId]);
        }
        $invoice = $this->di['db']->getExistingModelById('Invoice', (int) $payment['metadata']['invoice_id'], 'Invoice not found');
        if ((int) ($payment['metadata']['client_id'] ?? 0) !== (int) $invoice->client_id) {
            throw new Payment_Exception('Mollie payment :id is not for a ClientFence invoice', [':id' => $paymentId]);
        }
        $amount = (float) ($payment['amount']['value'] ?? 0);
        $currency = strtoupper((string) ($payment['amount']['currency'] ?? ''));
        $status = (string) ($payment['status'] ?? '');
        $db = $this->di['dbal'];
        $row = ['invoice_id' => $invoice->id, 'txn_id' => $paymentId, 'type' => 'mollie', 'amount' => $amount, 'currency' => $currency, 'updated_at' => date('Y-m-d H:i:s')];
        $clearing = $status === 'pending' || ($status === 'open' && $purpose === 'charge');
        // The return, Mollie's webhook and the charge may handle the same payment at once: one at a time, under its lock.
        ClientFence\Tools::withLock($this->di, 'cf:mollie-payment:' . $paymentId, function () use ($api_admin, $id, $paymentId, $invoice, $amount, $currency, $row, $payment, $purpose, $status, $clearing, $db): void {
            // A payment that was clearing stays on, and is settled on, the one row that waited for it (as PayTabs), so it counts once.
            $pending = $db->fetchOne("SELECT id FROM `transaction` WHERE txn_id = ? AND gateway_id = ? AND status = ? AND txn_status = 'processing' AND id <> ? ORDER BY id LIMIT 1", [$paymentId, $this->gatewayId(), Model_Transaction::STATUS_RECEIVED, $id]);
            if ($pending !== false) {
                $db->update('`transaction`', ['txn_id' => $paymentId, 'status' => Model_Transaction::STATUS_PROCESSED, 'note' => 'Payment ' . $paymentId . ': transaction #' . $pending . '.', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
                $id = (int) $pending;
            }
            if ($clearing) {
                // As WHMCS's "Payment Pending": no other charge or reminder until Mollie settles it (its webhook, or "Process again").
                // Only on a row still waiting: a stale answer never turns a recorded payment back into a pending one.
                $db->update('`transaction`', $row + ['txn_status' => 'processing', 'status' => Model_Transaction::STATUS_RECEIVED, 'error' => 'Mollie payment is ' . $status], ['id' => $id, 'status' => Model_Transaction::STATUS_RECEIVED]);

                return;
            }
            if ($status !== 'paid') {
                $db->update('`transaction`', $row + ['txn_status' => $status, 'status' => Model_Transaction::STATUS_ERROR, 'error' => 'Mollie payment not completed: ' . $this->error($payment)], ['id' => $id, 'status' => Model_Transaction::STATUS_RECEIVED]);
                // A charge of the saved method that was clearing failed: the client is told, with no automatic retry (as Stripe's bank debits).
                if ($pending !== false && $purpose === 'charge') {
                    ClientFence\Mod\Invoice\SavedCards::emailDebitFailure($this->di, $invoice, (string) ($this->savedCard((int) $invoice->client_id)['card'] ?? ''), $this->error($payment));
                }

                return;
            }
            if (!$this->applyPayment($api_admin, $id, $paymentId, $invoice, $amount, $currency, $row)) {
                // Recorded before: a later webhook of the payment may report a chargeback.
                if ((float) ($payment['amountChargedBack']['value'] ?? 0) > 0) {
                    $this->chargeback($id, $paymentId, $invoice, (float) $payment['amountChargedBack']['value']);
                }
            } elseif ($purpose === 'save' && !empty($payment['mandateId']) && (string) ($payment['customerId'] ?? '') === $this->customerOf((int) $invoice->client_id)) {
                // Kept when the payment is recorded, so a webhook sent again never brings back a method the client removed.
                $this->remember((int) $invoice->client_id, $payment['customerId'] . ' ' . (string) $payment['mandateId'], $this->label($payment));
            }
        });
    }

    /** A chargeback of a payment recorded here: taken back on its invoice once (as Stripe's disputes), noted on transaction $id. */
    private function chargeback(int $id, string $paymentId, Model_Invoice $invoice, float $charged): void
    {
        $db = $this->di['dbal'];
        $recorded = $db->fetchAssociative("SELECT id, amount FROM `transaction` WHERE txn_id = ? AND type = 'mollie' AND status = ? AND txn_status = 'Completed' ORDER BY id LIMIT 1", [$paymentId, Model_Transaction::STATUS_PROCESSED]);
        // Mollie calls the webhook again for later changes of the payment: the chargeback counts once ("Process again" of the payment's own row never takes it back).
        if ($recorded === false || (int) $recorded['id'] === $id
            || $db->fetchOne("SELECT 1 FROM `transaction` WHERE txn_id = ? AND type = 'mollie_chargeback' AND status = ?", [$paymentId, Model_Transaction::STATUS_PROCESSED]) !== false) {
            return;
        }
        // Recorded payments are in the invoice's currency (applyPayment()), and so is what Mollie charges back of them.
        $note = $this->di['mod_service']('Invoice')->chargeback($invoice, min($charged, (float) $recorded['amount']), sprintf('Mollie chargeback of payment %s.', $paymentId));
        $db->update('`transaction`', ['invoice_id' => $invoice->id, 'txn_id' => $paymentId, 'type' => 'mollie_chargeback', 'txn_status' => 'charged_back', 'status' => Model_Transaction::STATUS_PROCESSED, 'error' => null, 'note' => $note, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
    }

    /** Applies a paid payment to its invoice: false when it was recorded already. */
    private function applyPayment($api_admin, int $id, string $paymentId, Model_Invoice $invoice, float $amount, string $currency, array $row): bool
    {
        $db = $this->di['dbal'];
        // Applied already ("Process again", the return after the webhook, another Mollie gateway): never twice.
        $done = $db->fetchOne("SELECT id FROM `transaction` WHERE txn_id = ? AND type = 'mollie' AND status = ? AND txn_status = ? ORDER BY id LIMIT 1", [$paymentId, Model_Transaction::STATUS_PROCESSED, 'Completed']);
        if ($done !== false) {
            if ((int) $done !== $id) {
                $db->update('`transaction`', ['txn_id' => $paymentId, 'status' => Model_Transaction::STATUS_PROCESSED, 'note' => 'Payment ' . $paymentId . ' was already recorded on transaction #' . $done . '.', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
            }

            return false;
        }
        if ($currency !== strtoupper((string) $invoice->currency)) {
            throw new Payment_Exception('Mollie payment :id is in :currency, the invoice in :invoice', [':id' => $paymentId, ':currency' => $currency, ':invoice' => $invoice->currency]);
        }
        $service = $this->di['mod_service']('Invoice');
        $service->validatePaymentAmount($amount, $service->getBalanceDue($invoice), $invoice);
        $db->update('`transaction`', $row + ['txn_status' => 'Completed'], ['id' => $id]);
        // As PayPal: the payment goes to the client's credit, which then pays the invoice.
        // A retry after a try that failed once the payment was credited.
        if (!\ClientFence\Mod\Client\Service::hasGatewayCredit($this->di['dbal'], 'Mollie', $paymentId)) {
            $api_admin->client_balance_add_funds(['id' => $invoice->client_id, 'amount' => $amount, 'description' => 'Mollie payment ' . $paymentId, 'type' => 'Mollie', 'rel_id' => $paymentId]);
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

        return true;
    }

    /** "Visa •••• 1234", "PayPal name@example.com" or "SEPA NL…1234" from a payment's method and details. */
    private function label(array $payment): string
    {
        $details = $payment['details'] ?? [];

        return match ($payment['method'] ?? '') {
            'creditcard' => trim(($details['cardLabel'] ?? '') ?: __trans('Card')) . ' •••• ' . substr((string) ($details['cardNumber'] ?? ''), -4),
            'paypal' => trim('PayPal ' . ($details['consumerAccount'] ?? '')),
            default => 'SEPA ' . substr((string) ($details['consumerAccount'] ?? ''), 0, 2) . '…' . substr((string) ($details['consumerAccount'] ?? ''), -4),
        };
    }

    /** Mollie's page for a new payment: its address. Our reference is kept for a day to find the payment on the return. */
    private function startPage(array $fields, string $reference): string
    {
        $reply = $this->call('POST', '/payments', $fields);
        if (empty($reply['id']) || empty($reply['_links']['checkout']['href'])) {
            throw new ClientFence\InformationException('Mollie did not start the payment: :error', [':error' => $this->error($reply)]);
        }
        $item = $this->di['cache']->getItem($this->cacheKey($reference));
        $item->set((string) $reply['id']);
        $item->expiresAfter(86400);
        $this->di['cache']->save($item);

        return (string) $reply['_links']['checkout']['href'];
    }

    private function cacheKey(string $reference): string
    {
        return 'clientfence_mollie_' . $reference;
    }

    /** The client's Mollie customer, made the first time a method is saved and kept in pay_gateway_customer. */
    private function customer(int $clientId): string
    {
        $customerId = $this->customerOf($clientId);
        if ($customerId !== '') {
            return $customerId;
        }
        $client = $this->di['db']->getExistingModelById('Client', $clientId, 'Client not found');
        $reply = $this->call('POST', '/customers', ['name' => trim($client->first_name . ' ' . $client->last_name), 'email' => (string) $client->email, 'metadata' => ['client_id' => $clientId]]);
        if (empty($reply['id'])) {
            throw new ClientFence\InformationException('Mollie did not start the payment: :error', [':error' => $this->error($reply)]);
        }
        $now = date('Y-m-d H:i:s');
        $key = [$this->gatewayId(), $clientId, (int) $this->testMode()];
        if ($this->di['dbal']->executeStatement('UPDATE pay_gateway_customer SET external_customer_id = ?, updated_at = ? WHERE pay_gateway_id = ? AND client_id = ? AND test_mode = ?', [(string) $reply['id'], $now, ...$key]) === 0) {
            $this->di['dbal']->insert('pay_gateway_customer', ['pay_gateway_id' => $key[0], 'client_id' => $clientId, 'test_mode' => $key[2], 'external_customer_id' => (string) $reply['id'], 'saved_cards' => 0, 'created_at' => $now, 'updated_at' => $now]);
        }

        return (string) $reply['id'];
    }

    private function customerOf(int $clientId): string
    {
        return (string) $this->di['dbal']->fetchOne('SELECT external_customer_id FROM pay_gateway_customer WHERE pay_gateway_id = ? AND client_id = ? AND test_mode = ?', [$this->gatewayId(), $clientId, (int) $this->testMode()]);
    }

    /** Keeps a method ("customer mandate") as the client's saved method; the mandate saved before is revoked at Mollie. */
    private function remember(int $clientId, string $method, string $label): void
    {
        $old = $this->savedCard($clientId)['payment_method'] ?? null;
        ClientFence\Mod\Invoice\SavedCards::remember($this->di, $this->gatewayId(), $this->testMode(), $clientId, (string) strtok($method, ' '), $method, $label);
        if ($old !== null && $old !== $method) {
            $this->revoke($old);
        }
    }

    /** Removes a method wherever it is saved. */
    private function forget(string $method): void
    {
        $this->di['dbal']->executeStatement(
            'UPDATE pay_gateway_customer SET default_payment_method = NULL, default_card = NULL, updated_at = ? WHERE pay_gateway_id = ? AND test_mode = ? AND default_payment_method = ?',
            [date('Y-m-d H:i:s'), $this->gatewayId(), (int) $this->testMode(), $method]
        );
    }

    private function revoke(string $method): void
    {
        [$customerId, $mandateId] = explode(' ', $method . ' ', 2);
        try {
            $reply = $this->call('DELETE', '/customers/' . rawurlencode($customerId) . '/mandates/' . rawurlencode(trim($mandateId)));
            if (isset($reply['http'])) {
                $this->di['logger']->warning('Mollie: a saved mandate was not revoked at Mollie: %s', $this->error($reply));
            }
        } catch (Throwable $e) {
            $this->di['logger']->warning('Mollie: a saved mandate was not revoked at Mollie: %s', $e->getMessage());
        }
    }

    /** An invoice's balance as Mollie charges it: its metadata names the invoice and why ($purpose: pay, save or charge). */
    private function payment(Model_Invoice $invoice, string $purpose): array
    {
        $service = $this->di['mod_service']('Invoice');

        return [
            'amount' => $this->amount($service->getBalanceDue($invoice), (string) $invoice->currency),
            'description' => mb_substr(__trans('Invoice :number', [':number' => $service->toApiArray($invoice)['serie_nr']]), 0, 255),
            'metadata' => ['clientfence' => $purpose, 'invoice_id' => (int) $invoice->id, 'client_id' => (int) $invoice->client_id, 'site' => INSTANCE_ID],
        ] + (self::reachable($site = ClientFence\Site::current($this->di)->url) ? ['webhookUrl' => $site . 'ipn.php?' . http_build_query(['gateway_id' => $this->gatewayId(), 'invoice_id' => (int) $invoice->id])] : []);
    }

    /** Whether Mollie can call this address: it refuses a webhook on localhost, .localhost or a private IP (the return then records the payment). */
    public static function reachable(string $url): bool
    {
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');

        return $host !== 'localhost' && !str_ends_with($host, '.localhost')
            && (filter_var($host, FILTER_VALIDATE_IP) === false || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false);
    }

    /** Mollie's amount: the value a string with the currency's decimals and no thousands separator ("1234.50", JPY "1235"). */
    private function amount(float $amount, string $currency): array
    {
        $digits = Symfony\Component\Intl\Currencies::exists($currency) ? Symfony\Component\Intl\Currencies::getFractionDigits($currency) : 2;

        return ['currency' => strtoupper($currency), 'value' => number_format($amount, $digits, '.', '')];
    }

    /** Mollie's API with the key of the current mode: the reply (http: the status of a refused call). No answer throws Payment_Exception. */
    private function call(string $method, string $path, array $fields = []): array
    {
        try {
            $response = $this->di['http_client']->request($method, 'https://api.mollie.com/v2' . $path, [
                'timeout' => 60,
                'auth_bearer' => $this->key(),
            ] + ($fields === [] ? [] : ['json' => $fields]));
            $status = $response->getStatusCode();
            // A revoked mandate answers 204 without a body.
            $reply = (json_decode($response->getContent(false), true) ?: []) + ($status >= 400 ? ['http' => $status] : []);
        } catch (Throwable $e) {
            throw new Payment_Exception('Mollie did not answer :path: :error', [':path' => $path, ':error' => $e->getMessage()]);
        }

        return $reply;
    }

    /** Mollie's answer: an error's detail, else a payment's failure reason or status, with the HTTP status of a refused call. */
    private function error(array $reply): string
    {
        $text = $reply['detail'] ?? $reply['details']['failureReason'] ?? $reply['details']['bankReason'] ?? $reply['status'] ?? 'unknown error';

        return trim($text . (isset($reply['http']) ? ' (HTTP ' . $reply['http'] . ')' : ''));
    }

    private function key(): string
    {
        return trim((string) ($this->config[$this->testMode() ? 'test_api_key' : 'api_key'] ?? ''));
    }
}
