<?php

declare(strict_types=1);

/**
 * ClientFence: eWAY (Rapid API), with saved cards in the ways of PayTabs (owner decision 2026-11-12). The account owner
 * pays an invoice on eWAY's Responsive Shared Page and saves the card there (an eWAY token customer), or saves a card
 * without paying; anyone can pay once when that is on. The saved card is then their saved method
 * (Invoice/SavedCards.php): the cron and staff charge it as a recurring payment, and the client can pay an invoice
 * with it. eWAY sends no notices: the return's AccessCode and every payment are read back from eWAY with the API key,
 * so only this account's payments made here (invoice reference cf-invoice-...) pay an invoice.
 */
class Payment_Adapter_Eway implements ClientFence\InjectionAwareInterface
{
    /** The bank answers a cardholder can act on (eWAY's response codes). */
    private const DECLINES = [
        'D4405' => 'Do Not Honour', 'D4451' => 'Insufficient Funds', 'D4454' => 'Expired Card', 'D4457' => 'Function Not Permitted',
        'D4482' => 'CVV Validation Error', 'D4401' => 'Refer to Issuer', 'D4404' => 'Pick Up Card', 'D4441' => 'Lost Card', 'D4443' => 'Stolen Card',
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
        foreach (['api_key', 'api_password'] as $key) {
            if (trim((string) ($this->config[$prefix . $key] ?? '')) === '') {
                throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'eWAY', ':missing' => ($prefix === '' ? 'live' : 'sandbox') . ' API key and password'], 4001);
            }
        }
    }

    public static function getConfig(): array
    {
        $onOff = ['1' => __trans('On'), '0' => __trans('Off')];

        return [
            'title' => 'eWAY',
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => __trans('Card payments on eWAY\'s payment page. Clients can save their card there and their invoices are then charged to it automatically.'),
            'form' => [
                'api_key' => ['text', ['label' => __trans('Live API key:'), 'description' => __trans('MYeWAY: My Account, API Key. Test mode uses the API key and password of your eWAY sandbox account. [How to set up eWAY](https://clientfence.com/docs/clients-and-billing/eway/)')]],
                'api_password' => ['password', ['label' => __trans('Live API password:'), 'secret' => true]],
                'test_api_key' => ['text', ['label' => __trans('Sandbox API key:')]],
                'test_api_password' => ['password', ['label' => __trans('Sandbox API password:'), 'secret' => true]],
                'pay_saved' => ['radio', ['label' => __trans('Pay with the saved card:'), 'description' => __trans('On the invoice page, a client with a saved card can pay at once without going to eWAY.'), 'multiOptions' => $onOff]],
                'pay_once' => ['radio', ['label' => __trans('Pay once without saving the card:'), 'description' => __trans('Clients can also pay an invoice with a card without setting up automatic payments.'), 'multiOptions' => $onOff]],
                'save_only' => ['radio', ['label' => __trans('Set up on the Payment methods page:'), 'description' => __trans('Clients can save a card on their Payment methods page without paying an invoice.'), 'multiOptions' => $onOff]],
                'free_orders' => ['radio', ['label' => __trans('Free orders:'), 'description' => __trans('After a free order that renews (a free trial), a client without a saved payment method is sent to eWAY to save a card.'), 'multiOptions' => $onOff]],
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

    /** Refunds (Invoice ServiceSubscription::gatewayCan()) always go to eWAY. */
    public function hasApiKeys(): bool
    {
        return true;
    }

    /** On saving the gateway: eWAY accepts the API key and password (it answers a lookup of no payment). */
    public function testConnection(): void
    {
        $reply = $this->call('GET', '/Transaction/0');
        if (in_array($reply['http'] ?? 200, [401, 403], true)) {
            throw new ClientFence\InformationException('eWAY refused the API key or password (live and sandbox keys differ): :error', [':error' => $this->error($reply)]);
        }
    }

    /** The client's saved card ({payment_method, card, auto_pay_at}), or null. */
    public function savedCard(int $clientId): ?array
    {
        return ClientFence\Mod\Invoice\SavedCards::saved($this->di, $this->gatewayId(), $this->testMode(), $clientId);
    }

    /**
     * The payment choices for an invoice, as PayTabs. The account owner can pay with their saved card, or pay and save
     * the card; anyone can pay once when that is on.
     */
    public function getHtml($api_admin, $invoice_id, $subscription): string
    {
        $invoice = $this->di['db']->getExistingModelById('Invoice', (int) $invoice_id, 'Invoice not found');
        $user = ClientFence\Mod\Client\Users::context($this->di);
        $owner = (int) ($this->di['session']->get('client_id') ?? 0) === (int) $invoice->client_id && ($user === null || $user['owner']);
        $saved = $owner ? $this->savedCard((int) $invoice->client_id) : null;
        $link = fn (string $path): string => htmlspecialchars((string) $this->di['url']->link($path), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '';
        if ($saved !== null && $this->option('pay_saved')) {
            $html .= '<p><button type="button" class="btn btn-primary" data-cf-eway-pay>' . $text(__trans('Pay now with :card', [':card' => $saved['card']])) . '</button></p>';
            $html .= '<script nonce="' . ClientFence\Csp::nonce() . '">document.querySelector("[data-cf-eway-pay]").addEventListener("click", function (event) {'
                . ' var button = event.currentTarget; button.disabled = true;'
                . ' ClientFence.api.client.post("invoice/eway_pay", ' . json_encode(['hash' => $invoice->hash], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ', function (result) {'
                . ' if (result.paid) { location.assign(' . json_encode((string) $this->di['url']->link('invoice/thank-you/' . $invoice->hash), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) . '); } else { ClientFence.message(result.message, "info"); }'
                . ' }, function (error) { button.disabled = false; ClientFence.message(error.message, "error"); });'
                . ' });</script>';
        } elseif ($owner) {
            $html .= '<p><a class="btn btn-primary" href="' . $link('invoice/eway/' . $invoice->hash) . '">' . $text(__trans('Pay with a card and set up automatic payments')) . '</a></p>'
                . '<p class="text-muted">' . $text(__trans('Your renewal invoices are then charged to this card. You can remove it on your Payment methods page.')) . '</p>';
        }
        if ($this->option('pay_once')) {
            $html .= '<p><a class="btn btn-outline-primary" href="' . $link('invoice/eway/' . $invoice->hash . '?once=1') . '">' . $text(__trans('Pay once with a card')) . '</a></p>';
        }

        return $html !== '' ? $html : '<p>' . $text(__trans('Sign in as the account owner to pay with a card.')) . '</p>';
    }

    /**
     * Starts paying an invoice's balance on eWAY's page, saving the card when $save (the account owner): the page's
     * address. eWAY sends the client back to invoice/eway-paid with the AccessCode, which records the payment
     * (processTransaction()).
     */
    public function checkout(Model_Invoice $invoice, bool $save): string
    {
        $back = (string) $this->di['url']->link('invoice/eway-paid', ['hash' => $invoice->hash]);

        return $this->startPage([
            'Method' => $save ? 'TokenPayment' : 'ProcessPayment',
            'Payment' => $this->payment($invoice, ($save ? 'save' : 'pay') . time()),
            'RedirectUrl' => $back,
            'CancelUrl' => $back,
        ], (int) $invoice->client_id, 'cf-client-' . (int) $invoice->client_id);
    }

    /**
     * Starts saving a card without a payment (the Payment methods page, a free order): eWAY's page. eWAY sends the
     * client back to $returnUrl with the AccessCode (cardSaved()).
     */
    public function cardSetup(int $clientId, string $returnUrl): string
    {
        // Random, so nobody can guess it, and dated: an old return sent again never brings back a card the client removed.
        $reference = 'cf-client-' . $clientId . '-save' . time() . '-' . bin2hex(random_bytes(8));

        return $this->startPage([
            'Method' => 'CreateTokenCustomer',
            'Payment' => ['TotalAmount' => 0],
            'RedirectUrl' => $returnUrl,
            'CancelUrl' => $returnUrl,
        ], $clientId, $reference);
    }

    /** Back from eWAY after cardSetup() with its AccessCode: keeps the card for the client its reference names. Returns the client. */
    public function cardSaved(string $accessCode): int
    {
        $result = $this->accessCode($accessCode);
        $customer = $this->customer((string) ($result['TokenCustomerID'] ?? ''));
        // A setup counts within an hour of its start.
        if (empty($result['TokenCustomerID']) || preg_match('/^cf-client-(\d+)-save(\d+)-[0-9a-f]{16}$/', (string) ($customer['Reference'] ?? ''), $match) !== 1 || time() - (int) $match[2] > 3600) {
            throw new ClientFence\InformationException('eWAY did not save the card: :error', [':error' => $this->error($result)]);
        }
        $this->remember((int) $match[1], (string) $result['TokenCustomerID'], $this->label($customer));

        return (int) $match[1];
    }

    /**
     * Charges the client's saved card for an invoice's balance (the cron, staff, or the client on the invoice page), as a
     * recurring payment without the cardholder. $attempt names the try. Returns eWAY's transaction ID; a refusal throws
     * InformationException, no answer Payment_Exception.
     */
    public function chargeSavedCard(Model_Invoice $invoice, string $attempt): string
    {
        $saved = $this->savedCard((int) $invoice->client_id) ?? throw new ClientFence\InformationException('The client has no saved card.');
        $reply = $this->call('POST', '/Transaction', [
            'Customer' => ['TokenCustomerID' => $saved['payment_method']],
            'Payment' => $this->payment($invoice, $attempt),
            'Method' => 'TokenPayment',
            'TransactionType' => 'Recurring',
        ]);
        if (($reply['TransactionStatus'] ?? false) === true && !empty($reply['TransactionID'])) {
            return (string) $reply['TransactionID'];
        }
        if (str_contains((string) ($reply['Errors'] ?? ''), 'V6040')) {
            // As WHMCS: a card eWAY no longer knows (invalid token customer) is removed, so it is not tried again.
            $this->forget($saved['payment_method']);
        } elseif (($reply['http'] ?? 0) >= 500 || in_array($reply['http'] ?? 0, [401, 403], true)) {
            // Not a refusal of the card: the invoice is held (SavedCards::charge()), the client gets no email.
            throw new Payment_Exception('eWAY did not answer :path: :error', [':path' => '/Transaction', ':error' => $this->error($reply)]);
        }

        throw new ClientFence\InformationException('eWAY refused the payment: :error', [':error' => $this->error($reply)]);
    }

    /** Removes the client's saved card here (eWAY's API has no way to delete a token customer). */
    public function cardRemove(int $clientId): void
    {
        $saved = $this->savedCard($clientId);
        if ($saved !== null) {
            $this->forget($saved['payment_method']);
        }
    }

    /** Sends money of a payment back (staff refunds): eWAY's refund transaction ID. */
    public function refundPayment(string $transactionId, float $amount, string $currency): string
    {
        $reply = $this->call('POST', '/Transaction/' . rawurlencode($transactionId) . '/Refund', [
            'Refund' => ['TransactionID' => $transactionId, 'TotalAmount' => $this->minorUnits($amount, $currency), 'CurrencyCode' => strtoupper($currency)],
        ]);
        if (($reply['TransactionStatus'] ?? false) !== true || empty($reply['TransactionID'])) {
            throw new ClientFence\InformationException('eWAY did not make the refund: :error', [':error' => $this->error($reply)]);
        }

        return (string) $reply['TransactionID'];
    }

    /**
     * A payment made here (source saved_card, get.eway_tran) or eWAY's return (source eway_return, get.AccessCode). A
     * payment is always read back from eWAY: its invoice reference names the invoice.
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id): void
    {
        $transactionId = (string) ($data['get']['eway_tran'] ?? '');
        if (($data['source'] ?? '') !== 'saved_card') {
            $transactionId = (string) ($this->accessCode((string) ($data['get']['AccessCode'] ?? ''))['TransactionID'] ?? '');
        }
        if (preg_match('/^\d{1,20}$/', $transactionId) !== 1) {
            throw new Payment_Exception('eWAY return names no payment');
        }
        $this->recordPayment($api_admin, (int) $id, $transactionId);
    }

    /** A payment as eWAY reports it: recorded on transaction $id and applied to its invoice once. */
    private function recordPayment($api_admin, int $id, string $transactionId): void
    {
        $payment = $this->call('GET', '/Transaction/' . $transactionId)['Transactions'][0] ?? [];
        if ((string) ($payment['TransactionID'] ?? '') !== $transactionId) {
            throw new Payment_Exception('eWAY payment :id was not found', [':id' => $transactionId]);
        }
        // Only payments made here name an invoice: one of another shop on the same account pays nothing.
        if (preg_match('/^cf-invoice-(\d+)-(save)?/', (string) ($payment['InvoiceReference'] ?? ''), $match) !== 1 || !empty($payment['OriginalTransactionId'])) {
            throw new Payment_Exception('eWAY payment :id is not for a ClientFence invoice', [':id' => $transactionId]);
        }
        $invoice = $this->di['db']->getExistingModelById('Invoice', (int) $match[1], 'Invoice not found');
        // eWAY charges in the currency it was asked for, the invoice's (payment()); a query may name it by its ISO 4217 number ("036").
        $currency = strtoupper((string) ($payment['CurrencyCode'] ?? $invoice->currency));
        if (ctype_digit($currency) && Symfony\Component\Intl\Currencies::exists((string) $invoice->currency) && Symfony\Component\Intl\Currencies::getNumericCode((string) $invoice->currency) === (int) $currency) {
            $currency = strtoupper((string) $invoice->currency);
        }
        $amount = $this->fromMinorUnits((int) ($payment['TotalAmount'] ?? 0), $currency);
        $row = ['invoice_id' => $invoice->id, 'txn_id' => $transactionId, 'type' => 'eway', 'amount' => $amount, 'currency' => $currency, 'updated_at' => date('Y-m-d H:i:s')];
        if (($payment['TransactionStatus'] ?? false) !== true) {
            $this->di['dbal']->update('`transaction`', $row + ['txn_status' => (string) ($payment['ResponseMessage'] ?? 'declined'), 'status' => Model_Transaction::STATUS_ERROR, 'error' => 'eWAY payment not completed: ' . $this->error($payment)], ['id' => $id]);

            return;
        }
        // The return may be sent twice at once (a double click, a reload).
        ClientFence\Tools::withLock($this->di, 'cf:eway-payment:' . $transactionId, function () use ($api_admin, $id, $transactionId, $invoice, $amount, $currency, $row, $payment, $match): void {
            if ($this->applyPayment($api_admin, $id, $transactionId, $invoice, $amount, $currency, $row) && !empty($match[2]) && !empty($payment['TokenCustomerID'])) {
                $customer = $this->customer((string) $payment['TokenCustomerID']);
                // Kept when the payment is recorded, so a return sent again never brings back a card the client removed.
                if ((string) ($customer['Reference'] ?? '') === 'cf-client-' . (int) $invoice->client_id) {
                    $this->remember((int) $invoice->client_id, (string) $payment['TokenCustomerID'], $this->label($customer));
                }
            }
        });
    }

    /** Applies a completed payment to its invoice: false when it was recorded already. */
    private function applyPayment($api_admin, int $id, string $transactionId, Model_Invoice $invoice, float $amount, string $currency, array $row): bool
    {
        $db = $this->di['dbal'];
        // Applied already ("Process again", the return sent again, another eWAY gateway): never twice.
        $done = $db->fetchOne("SELECT id FROM `transaction` WHERE txn_id = ? AND type = 'eway' AND status = ? AND txn_status = ? ORDER BY id LIMIT 1", [$transactionId, Model_Transaction::STATUS_PROCESSED, 'Completed']);
        if ($done !== false) {
            if ((int) $done !== $id) {
                $db->update('`transaction`', ['txn_id' => $transactionId, 'status' => Model_Transaction::STATUS_PROCESSED, 'note' => 'Payment ' . $transactionId . ' was already recorded on transaction #' . $done . '.', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
            }

            return false;
        }
        if ($currency !== strtoupper((string) $invoice->currency)) {
            throw new Payment_Exception('eWAY payment :id is in :currency, the invoice in :invoice', [':id' => $transactionId, ':currency' => $currency, ':invoice' => $invoice->currency]);
        }
        $service = $this->di['mod_service']('Invoice');
        $service->validatePaymentAmount($amount, $service->getBalanceDue($invoice), $invoice);
        $db->update('`transaction`', $row + ['txn_status' => 'Completed'], ['id' => $id]);
        // As PayPal: the payment goes to the client's credit, which then pays the invoice.
        $api_admin->client_balance_add_funds(['id' => $invoice->client_id, 'amount' => $amount, 'description' => 'eWAY transaction ' . $transactionId, 'type' => 'eWAY', 'rel_id' => $transactionId]);
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

    /** The result of a payment page by its AccessCode (eWAY keeps it a week), [] without one (the client cancelled). */
    private function accessCode(string $accessCode): array
    {
        return preg_match('/^[A-Za-z0-9_=-]{10,512}$/', $accessCode) === 1 ? $this->call('GET', '/AccessCode/' . rawurlencode($accessCode)) : [];
    }

    /** A token customer as eWAY keeps it ({Reference, CardDetails}), [] when there is none. */
    private function customer(string $tokenCustomerId): array
    {
        return preg_match('/^\d{1,16}$/', $tokenCustomerId) === 1 ? ($this->call('GET', '/Customer/' . $tokenCustomerId)['Customers'][0] ?? []) : [];
    }

    /** "Card •••• 1111 (12/28)" from eWAY's CardDetails (masked number, expiry). */
    private function label(array $customer): string
    {
        $card = $customer['CardDetails'] ?? [];
        $expiry = empty($card['ExpiryMonth']) ? '' : sprintf(' (%02d/%s)', (int) $card['ExpiryMonth'], $card['ExpiryYear'] ?? '');

        return __trans('Card') . ' •••• ' . substr((string) ($card['Number'] ?? ''), -4) . $expiry;
    }

    /** eWAY's payment page for a request, filled in with the client's details ($reference names them): its address. */
    private function startPage(array $fields, int $clientId, string $reference): string
    {
        $client = $this->di['db']->getExistingModelById('Client', $clientId, 'Client not found');
        $reply = $this->call('POST', '/AccessCodesShared', $fields + [
            'TransactionType' => 'Purchase',
            'Customer' => [
                'Reference' => $reference,
                'FirstName' => mb_substr((string) $client->first_name, 0, 50),
                'LastName' => mb_substr((string) $client->last_name, 0, 50),
                'CompanyName' => mb_substr((string) $client->company, 0, 50),
                'Email' => (string) $client->email,
                'Phone' => mb_substr(trim($client->phone_cc . $client->phone), 0, 32),
                'Street1' => mb_substr((string) $client->address_1, 0, 50),
                'Street2' => mb_substr((string) $client->address_2, 0, 50),
                'City' => mb_substr((string) $client->city, 0, 50),
                'State' => mb_substr((string) $client->state, 0, 50),
                'PostalCode' => mb_substr((string) $client->postcode, 0, 30),
                'Country' => strtolower((string) $client->country),
            ],
        ]);
        if (empty($reply['SharedPaymentUrl'])) {
            throw new ClientFence\InformationException('eWAY did not start the payment: :error', [':error' => $this->error($reply)]);
        }

        return (string) $reply['SharedPaymentUrl'];
    }

    /** Keeps a card (its token customer ID) as the client's saved method. */
    private function remember(int $clientId, string $method, string $label): void
    {
        ClientFence\Mod\Invoice\SavedCards::remember($this->di, $this->gatewayId(), $this->testMode(), $clientId, '', $method, $label);
    }

    /** Removes a card wherever it is saved. */
    private function forget(string $method): void
    {
        $this->di['dbal']->executeStatement(
            'UPDATE pay_gateway_customer SET default_payment_method = NULL, default_card = NULL, updated_at = ? WHERE pay_gateway_id = ? AND test_mode = ? AND default_payment_method = ?',
            [date('Y-m-d H:i:s'), $this->gatewayId(), (int) $this->testMode(), $method]
        );
    }

    /** An invoice's balance as eWAY charges it: the reference names the invoice and the try ($suffix: "save..." saves the card). */
    private function payment(Model_Invoice $invoice, string $suffix): array
    {
        $service = $this->di['mod_service']('Invoice');
        $number = (string) $service->toApiArray($invoice)['serie_nr'];

        return [
            'TotalAmount' => $this->minorUnits($service->getBalanceDue($invoice), (string) $invoice->currency),
            'InvoiceNumber' => mb_substr($number, -12),
            'InvoiceDescription' => mb_substr(__trans('Invoice :number', [':number' => $number]), 0, 64),
            'InvoiceReference' => mb_substr('cf-invoice-' . $invoice->id . '-' . $suffix, 0, 50),
            'CurrencyCode' => strtoupper((string) $invoice->currency),
        ];
    }

    /** eWAY's API with the key and password: the reply (http: the status of a refused call). No answer throws Payment_Exception. */
    private function call(string $method, string $path, array $fields = []): array
    {
        $prefix = $this->testMode() ? 'test_' : '';
        try {
            $response = $this->di['http_client']->request($method, ($this->testMode() ? 'https://api.sandbox.ewaypayments.com' : 'https://api.ewaypayments.com') . $path, [
                'timeout' => 60,
                'auth_basic' => [trim((string) $this->config[$prefix . 'api_key']), trim((string) $this->config[$prefix . 'api_password'])],
                'headers' => ['X-EWAY-APIVERSION' => '40'],
            ] + ($fields === [] ? [] : ['json' => $fields]));
            $status = $response->getStatusCode();
            $reply = $status >= 400 ? (json_decode($response->getContent(false), true) ?: []) + ['http' => $status] : $response->toArray(false);
        } catch (Throwable $e) {
            throw new Payment_Exception('eWAY did not answer :path: :error', [':path' => $path, ':error' => $e->getMessage()]);
        }

        return $reply;
    }

    /** eWAY's codes of a reply, e.g. "D4405 Do Not Honour" (declined) or "V6040" (invalid token customer). */
    private function error(array $reply): string
    {
        $codes = array_filter(array_map('trim', explode(',', ($reply['ResponseMessage'] ?? '') . ',' . ($reply['Errors'] ?? ''))));
        $codes = array_map(static fn (string $code): string => trim($code . ' ' . (self::DECLINES[$code] ?? '')), $codes);
        if (isset($reply['http'])) {
            $codes[] = 'HTTP ' . $reply['http'];
        }

        return implode(', ', $codes) ?: 'unknown error';
    }

    /** eWAY amounts are in the currency's smallest unit (AUD 27.00 = 2700, JPY 27 = 27). */
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
