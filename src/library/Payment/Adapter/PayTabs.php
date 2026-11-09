<?php

declare(strict_types=1);

/**
 * ClientFence: PayTabs, with saved cards as WHMCS charges a card on file, in the ways of PayPal Auto-Pay (owner decisions
 * 2026-11-09). The account owner pays an invoice on PayTabs' payment page and saves the card (a PayTabs token), or saves a
 * card without paying (a card check, "register"); anyone can pay once when that is on. The saved card is then
 * their saved method (Invoice/SavedCards.php): the cron and staff charge it as a recurring payment, and the client can
 * pay an invoice with it. PayTabs sends the client back and its callback to ipn.php; every payment is read back from
 * PayTabs with the server key, so only this profile's payments made here (cart ID cf-invoice-...) pay an invoice.
 * Recurring payments need PayTabs to turn on "Recurring mode" for the profile.
 */
class Payment_Adapter_PayTabs implements ClientFence\InjectionAwareInterface
{
    private const REGIONS = [
        'ARE' => 'https://secure.paytabs.com', 'SAU' => 'https://secure.paytabs.sa', 'OMN' => 'https://secure-oman.paytabs.com',
        'JOR' => 'https://secure-jordan.paytabs.com', 'EGY' => 'https://secure-egypt.paytabs.com', 'IRQ' => 'https://secure-iraq.paytabs.com',
        'KWT' => 'https://secure-kuwait.paytabs.com', 'QAT' => 'https://secure-doha.paytabs.com', 'MAR' => 'https://secure-morocco.paytabs.com',
        'GLOBAL' => 'https://secure-global.paytabs.com',
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
        foreach (['profile_id', 'server_key'] as $key) {
            if (trim((string) ($this->config[$prefix . $key] ?? '')) === '') {
                throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'PayTabs', ':missing' => ($prefix === '' ? 'live' : 'test') . ' profile ID and server key'], 4001);
            }
        }
    }

    public static function getConfig(): array
    {
        $onOff = ['1' => __trans('On'), '0' => __trans('Off')];

        return [
            'title' => 'PayTabs',
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => __trans('Card payments on PayTabs\' payment page. Clients can save their card there and their invoices are then charged to it automatically (ask PayTabs to turn on Recurring mode for the profile).'),
            'form' => [
                'region' => ['select', ['label' => __trans('Region:'), 'description' => __trans('The region of your PayTabs account (the address of your PayTabs dashboard).'), 'multiOptions' => [
                    'ARE' => 'United Arab Emirates', 'SAU' => 'Saudi Arabia', 'OMN' => 'Oman', 'JOR' => 'Jordan', 'EGY' => 'Egypt', 'IRQ' => 'Iraq', 'KWT' => 'Kuwait', 'QAT' => 'Qatar', 'MAR' => 'Morocco', 'GLOBAL' => 'Global',
                ]]],
                'profile_id' => ['text', ['label' => __trans('Live profile ID:'), 'description' => __trans('PayTabs: Profile. The server key: Developers, Key management (an API key).')]],
                'server_key' => ['password', ['label' => __trans('Live server key:'), 'secret' => true]],
                'test_profile_id' => ['text', ['label' => __trans('Test profile ID:')]],
                'test_server_key' => ['password', ['label' => __trans('Test server key:'), 'secret' => true]],
                'pay_saved' => ['radio', ['label' => __trans('Pay with the saved card:'), 'description' => __trans('On the invoice page, a client with a saved card can pay at once without going to PayTabs.'), 'multiOptions' => $onOff]],
                'pay_once' => ['radio', ['label' => __trans('Pay once without saving the card:'), 'description' => __trans('Clients can also pay an invoice with a card without setting up automatic payments.'), 'multiOptions' => $onOff]],
                'save_only' => ['radio', ['label' => __trans('Set up on the Payment methods page:'), 'description' => __trans('Clients can save a card on their Payment methods page without paying an invoice (PayTabs checks the card without charging it).'), 'multiOptions' => $onOff]],
                'free_orders' => ['radio', ['label' => __trans('Free orders:'), 'description' => __trans('After a free order that renews (a free trial), a client without a saved payment method is sent to PayTabs to save a card.'), 'multiOptions' => $onOff]],
                'convert_to' => ['text', ['label' => __trans('Convert to for processing (optional):'), 'placeholder' => 'EGP', 'description' => __trans('A currency code of this portal that your PayTabs profile accepts. Invoices in other currencies are charged in it at the portal\'s exchange rates, as WHMCS\'s "Convert To For Processing". Empty: each invoice is charged in its own currency. [How to set up PayTabs](https://clientfence.com/docs/clients-and-billing/paytabs/)')]],
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

    /** Refunds (Invoice ServiceSubscription::gatewayCan()) always go to PayTabs. */
    public function hasApiKeys(): bool
    {
        return true;
    }

    /** On saving the gateway: the region, profile and server key work (PayTabs answers a lookup of no payment). */
    public function testConnection(): void
    {
        if ($this->convertTo() !== '') {
            try {
                $this->di['mod_service']('Currency')->getBaseCurrencyRate($this->convertTo());
            } catch (ClientFence\Exception) {
                throw new ClientFence\InformationException('Convert to for processing: :code is not one of the currencies of this portal.', [':code' => $this->convertTo()]);
            }
        }
        $reply = $this->call('/payment/query', ['tran_ref' => 'CF0000000000000']);
        // 113: no such payment, so the key and profile were accepted.
        if ((int) ($reply['code'] ?? 0) !== 113) {
            throw new ClientFence\InformationException('PayTabs refused the profile ID or server key (check the region too): :error', [':error' => $this->error($reply)]);
        }
    }

    /** The client's saved card ({payment_method, card, auto_pay_at}), or null. */
    public function savedCard(int $clientId): ?array
    {
        return ClientFence\Mod\Invoice\SavedCards::saved($this->di, $this->gatewayId(), $this->testMode(), $clientId);
    }

    /**
     * The payment choices for an invoice, as PayPal Auto-Pay. The account owner can pay with their saved card, or pay and
     * save the card; anyone can pay once when that is on.
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
            $html .= '<p><button type="button" class="btn btn-primary" data-cf-paytabs-pay>' . $text(__trans('Pay now with :card', [':card' => $saved['card']])) . '</button></p>';
            $html .= '<script nonce="' . ClientFence\Csp::nonce() . '">document.querySelector("[data-cf-paytabs-pay]").addEventListener("click", function (event) {'
                . ' var button = event.currentTarget; button.disabled = true;'
                . ' ClientFence.api.client.post("invoice/paytabs_pay", ' . json_encode(['hash' => $invoice->hash], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ', function (result) {'
                . ' if (result.paid) { location.assign(' . json_encode((string) $this->di['url']->link('invoice/thank-you/' . $invoice->hash), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) . '); } else { ClientFence.message(result.message, "info"); }'
                . ' }, function (error) { button.disabled = false; ClientFence.message(error.message, "error"); });'
                . ' });</script>';
        } elseif ($owner) {
            $html .= '<p><a class="btn btn-primary" href="' . $link('invoice/paytabs/' . $invoice->hash) . '">' . $text(__trans('Pay with a card and set up automatic payments')) . '</a></p>'
                . '<p class="text-muted">' . $text(__trans('Your renewal invoices are then charged to this card. You can remove it on your Payment methods page.')) . '</p>';
        }
        if ($this->option('pay_once')) {
            $html .= '<p><a class="btn btn-outline-primary" href="' . $link('invoice/paytabs/' . $invoice->hash . '?once=1') . '">' . $text(__trans('Pay once with a card')) . '</a></p>';
        }

        return $html !== '' ? $html : '<p>' . $text(__trans('Sign in as the account owner to pay with a card.')) . '</p>';
    }

    /**
     * Starts paying an invoice's balance on PayTabs' page, saving the card when $save (the account owner): the page's
     * address. PayTabs sends the client back to invoice/paytabs-paid and its notice to ipn.php; either records the
     * payment (processTransaction()).
     */
    public function checkout(Model_Invoice $invoice, bool $save): string
    {
        $back = SYSTEM_URL . 'ipn.php?' . http_build_query(['gateway_id' => $this->gatewayId(), 'invoice_id' => (int) $invoice->id]);
        $cart = $this->cart($invoice, ($save ? 'save' : 'pay') . time());

        return $this->startPage($cart + [
            'tran_type' => 'sale',
            'return' => (string) $this->di['url']->link('invoice/paytabs-paid', ['cart' => $cart['cart_id'], 'hash' => $invoice->hash]),
        ] + (str_starts_with($back, 'https://') ? ['callback' => $back] : []) // PayTabs refuses http callbacks; the return records the payment.
          + ($save ? ['tokenise' => 2] : []), (int) $invoice->client_id);
    }

    /**
     * Starts saving a card without a payment (the Payment methods page, a free order): PayTabs' page, where the card is
     * checked without a charge. PayTabs sends the client back to $returnUrl with the check (cardSaved()).
     */
    public function cardSetup(int $clientId, string $returnUrl): string
    {
        // Random, so nobody can guess the return address and save an old check again.
        $cartId = 'cf-client-' . $clientId . '-save' . time() . '-' . bin2hex(random_bytes(8));

        return $this->startPage([
            'tran_type' => 'register',
            'cart_id' => $cartId,
            'cart_description' => mb_substr(__trans('Card for automatic payments to :company', [':company' => (string) ($this->di['mod_service']('system')->getParamValue('company_name') ?: 'us')]), 0, 128),
            'cart_currency' => $this->convertTo() ?: (string) $this->di['mod_service']('Currency')->getCurrencyByClientId($clientId)->getCode(),
            'cart_amount' => 0,
            'tokenise' => 2,
            'return' => $returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . http_build_query(['cart' => $cartId]),
        ], $clientId);
    }

    /**
     * Back from PayTabs after cardSetup() with the check ($tranRef) or, when PayTabs sends none, its cart: keeps the checked
     * card for the client its cart names. Returns the client.
     */
    public function cardSaved(string $tranRef, string $cartId = ''): int
    {
        $tranRef = $tranRef ?: $this->tranRefOfCart($cartId);
        $check = $this->call('/payment/query', ['tran_ref' => $tranRef]);
        // A check counts within an hour of its start: an old one sent again never brings back a card the client removed.
        if (($check['tran_ref'] ?? '') !== $tranRef || preg_match('/^cf-client-(\d+)-save(\d+)-[0-9a-f]{16}$/', (string) ($check['cart_id'] ?? ''), $match) !== 1 || time() - (int) $match[2] > 3600
            || ($check['payment_result']['response_status'] ?? '') !== 'A' || empty($check['token'])) {
            throw new ClientFence\InformationException('PayTabs did not save the card: :error', [':error' => $this->error($check)]);
        }
        $this->remember((int) $match[1], $check['token'] . ' ' . $tranRef, $this->label($check['payment_info'] ?? []));

        return (int) $match[1];
    }

    /**
     * Charges the client's saved card for an invoice's balance (the cron, staff, or the client on the invoice page), as a
     * recurring payment without the cardholder. $attempt names the try. Returns PayTabs' transaction reference; a refusal
     * throws InformationException, no answer Payment_Exception.
     */
    public function chargeSavedCard(Model_Invoice $invoice, string $attempt): string
    {
        $saved = $this->savedCard((int) $invoice->client_id) ?? throw new ClientFence\InformationException('The client has no saved card.');
        [$token, $tranRef] = explode(' ', $saved['payment_method'] . ' ', 2);
        $reply = $this->call('/payment/request', $this->cart($invoice, $attempt) + ['tran_type' => 'sale', 'tran_class' => 'recurring', 'token' => $token, 'tran_ref' => trim($tranRef)]);
        $status = (string) ($reply['payment_result']['response_status'] ?? '');
        // Approved, or held for PayTabs' review (recorded as pending): either way taken, never tried again meanwhile.
        if (!empty($reply['tran_ref']) && in_array($status, ['A', 'H', 'P'], true)) {
            return (string) $reply['tran_ref'];
        }
        if (!empty($reply['redirect_url'])) {
            throw new ClientFence\InformationException('The card issuer asks the cardholder to confirm this payment: the client has to pay the invoice on the payment page.');
        }
        if ((int) ($reply['code'] ?? 0) === 209) {
            // As WHMCS: a card PayTabs no longer knows is removed, so it is not tried again.
            $this->forget($saved['payment_method']);
        }

        throw new ClientFence\InformationException('PayTabs refused the payment: :error', [':error' => $this->error($reply)]);
    }

    /** Removes the client's saved card, here and at PayTabs. */
    public function cardRemove(int $clientId): void
    {
        $saved = $this->savedCard($clientId);
        if ($saved !== null) {
            $this->deleteToken($saved['payment_method']);
            $this->forget($saved['payment_method']);
        }
    }

    /** Sends money of a payment back (staff refunds): PayTabs' refund reference. */
    public function refundPayment(string $transactionId, float $amount, string $currency): string
    {
        $payment = $this->call('/payment/query', ['tran_ref' => $transactionId]);
        if (!empty($payment['tran_currency']) && strtoupper((string) $payment['tran_currency']) !== strtoupper($currency)) {
            // Converted for processing: the same share of what PayTabs took, so a later rate never refunds more.
            $recorded = (float) $this->di['dbal']->fetchOne('SELECT amount FROM `transaction` WHERE txn_id = ? AND gateway_id = ? AND status = ? AND txn_status = ? ORDER BY id LIMIT 1', [$transactionId, $this->gatewayId(), Model_Transaction::STATUS_PROCESSED, 'Completed']);
            $amount = min((float) $payment['tran_total'], (float) $payment['tran_total'] * $amount / $recorded);
            $currency = (string) $payment['tran_currency'];
        }
        $reply = $this->call('/payment/request', [
            'tran_type' => 'refund',
            'tran_class' => 'ecom',
            'cart_id' => (string) ($payment['cart_id'] ?? $transactionId),
            'cart_currency' => $currency,
            'cart_amount' => (float) $this->moneyFormat($amount, $currency),
            'cart_description' => 'Refund',
            'tran_ref' => $transactionId,
        ]);
        if (($reply['payment_result']['response_status'] ?? '') !== 'A' || empty($reply['tran_ref'])) {
            throw new ClientFence\InformationException('PayTabs did not make the refund: :error', [':error' => $this->error($reply)]);
        }

        return (string) $reply['tran_ref'];
    }

    /**
     * A payment made here (source saved_card, get.paytabs_tran), PayTabs' return (source paytabs_return: post.tranRef, or
     * get.cart when PayTabs sends no reference) or its callback to ipn.php (tran_ref). A payment is always read back from
     * PayTabs: its cart ID names the invoice.
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id): void
    {
        $id = (int) $id;
        if (isset($data['get']['paytabs_tran']) && ($data['source'] ?? '') !== 'ipn') {
            $this->recordPayment($api_admin, $id, (string) $data['get']['paytabs_tran']);

            return;
        }
        $body = json_decode((string) ($data['http_raw_post_data'] ?? ''), true);
        // The return names its cart (the payment is looked up by it when PayTabs sends no reference), the callback the payment.
        $tranRef = (string) ($data['post']['tranRef'] ?? (is_array($body) ? ($body['tran_ref'] ?? '') : '')) ?: $this->tranRefOfCart((string) ($data['get']['cart'] ?? ''));
        if (preg_match('/^[A-Z0-9]{1,32}$/', $tranRef) !== 1) {
            throw new Payment_Exception('PayTabs notice names no payment');
        }
        $this->recordPayment($api_admin, $id, $tranRef);
    }

    /** A payment as PayTabs reports it: recorded on transaction $id and applied to its invoice once. */
    private function recordPayment($api_admin, int $id, string $tranRef): void
    {
        $payment = $this->call('/payment/query', ['tran_ref' => $tranRef]);
        if (($payment['tran_ref'] ?? '') !== $tranRef) {
            throw new Payment_Exception('PayTabs payment :id was not found: :error', [':id' => $tranRef, ':error' => $this->error($payment)]);
        }
        // Only payments made here name an invoice: one of another shop on the same profile pays nothing.
        if (preg_match('/^cf-invoice-(\d+)-(save)?/', (string) ($payment['cart_id'] ?? ''), $match) !== 1 || strcasecmp((string) ($payment['tran_type'] ?? ''), 'sale') !== 0) {
            throw new Payment_Exception('PayTabs payment :id is not for a ClientFence invoice', [':id' => $tranRef]);
        }
        $invoice = $this->di['db']->getExistingModelById('Invoice', (int) $match[1], 'Invoice not found');
        $amount = (float) ($payment['tran_total'] ?? 0);
        $currency = (string) ($payment['tran_currency'] ?? '');
        $status = (string) ($payment['payment_result']['response_status'] ?? '');
        $db = $this->di['dbal'];
        $row = ['invoice_id' => $invoice->id, 'txn_id' => $tranRef, 'type' => 'paytabs', 'amount' => $amount, 'currency' => $currency, 'updated_at' => date('Y-m-d H:i:s')];
        // A payment PayTabs held is settled on the row that waited for it (as PayPal Auto-Pay), so it counts once.
        $pending = $db->fetchOne("SELECT id FROM `transaction` WHERE txn_id = ? AND gateway_id = ? AND status = ? AND txn_status = 'processing' AND id <> ? ORDER BY id LIMIT 1", [$tranRef, $this->gatewayId(), Model_Transaction::STATUS_RECEIVED, $id]);
        if ($pending !== false && !in_array($status, ['H', 'P'], true)) {
            $db->update('`transaction`', ['txn_id' => $tranRef, 'status' => Model_Transaction::STATUS_PROCESSED, 'note' => 'Payment ' . $tranRef . ' settled on transaction #' . $pending . '.', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
            $id = (int) $pending;
        }
        if (in_array($status, ['H', 'P'], true)) {
            // As WHMCS's "Payment Pending": no other charge or reminder until PayTabs settles it ("Process again" reads it back).
            $db->update('`transaction`', $row + ['txn_status' => 'processing', 'status' => Model_Transaction::STATUS_RECEIVED, 'error' => 'PayTabs is holding the payment: ' . ($payment['payment_result']['response_message'] ?? $status)], ['id' => $id]);

            return;
        }
        if ($status !== 'A') {
            $db->update('`transaction`', $row + ['txn_status' => $status, 'status' => Model_Transaction::STATUS_ERROR, 'error' => 'PayTabs payment not completed: ' . ($payment['payment_result']['response_message'] ?? $status)], ['id' => $id]);

            return;
        }
        // The return and PayTabs' callback may record the same payment at once.
        ClientFence\Tools::withLock($this->di, 'cf:paytabs-payment:' . $tranRef, function () use ($api_admin, $id, $tranRef, $invoice, $amount, $currency, $row, $payment, $match): void {
            if ($this->applyPayment($api_admin, $id, $tranRef, $invoice, $amount, $currency, $row, (string) ($payment['user_defined']['udf1'] ?? '')) && !empty($match[2]) && !empty($payment['token'])) {
                // Kept when the payment is recorded, so a notice sent again never brings back a card the client removed.
                $this->remember((int) $invoice->client_id, $payment['token'] . ' ' . $tranRef, $this->label($payment['payment_info'] ?? []));
            }
        });
    }

    /** Applies a completed payment to its invoice: false when it was recorded already. */
    private function applyPayment($api_admin, int $id, string $tranRef, Model_Invoice $invoice, float $amount, string $currency, array $row, string $asked): bool
    {
        $db = $this->di['dbal'];
        // Applied already ("Process again", the return after the callback, another PayTabs gateway): never twice.
        $done = $db->fetchOne('SELECT id FROM `transaction` WHERE txn_id = ? AND status = ? AND txn_status = ? ORDER BY id LIMIT 1', [$tranRef, Model_Transaction::STATUS_PROCESSED, 'Completed']);
        if ($done !== false) {
            if ((int) $done !== $id) {
                $db->update('`transaction`', ['txn_id' => $tranRef, 'status' => Model_Transaction::STATUS_PROCESSED, 'note' => 'Payment ' . $tranRef . ' was already recorded on transaction #' . $done . '.', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
            }

            return false;
        }
        $service = $this->di['mod_service']('Invoice');
        $due = $service->getBalanceDue($invoice);
        if (strtoupper($currency) !== strtoupper((string) $invoice->currency)) {
            if (strtoupper($currency) !== $this->convertTo()) {
                throw new Payment_Exception('PayTabs payment :id is in :currency, the invoice in :invoice', [':id' => $tranRef, ':currency' => $currency, ':invoice' => $invoice->currency]);
            }
            // Converted for processing: worth the invoice amount it was asked for (udf1, set here), also after the rate changed.
            $paid = $this->moneyFormat($amount, $currency);
            $amount = is_numeric($asked) ? (float) $asked : round($this->convert($amount, $currency, (string) $invoice->currency), 2);
            $row = ['amount' => $amount, 'currency' => (string) $invoice->currency, 'note' => 'PayTabs: ' . $paid . ' ' . $currency . '.'] + $row;
        }
        $service->validatePaymentAmount($amount, $due, $invoice);
        $db->update('`transaction`', $row + ['txn_status' => 'Completed'], ['id' => $id]);
        // As PayPal: the payment goes to the client's credit, which then pays the invoice.
        $api_admin->client_balance_add_funds(['id' => $invoice->client_id, 'amount' => $amount, 'description' => 'PayTabs transaction ' . $tranRef, 'type' => 'PayTabs', 'rel_id' => $tranRef]);
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

    /** "Visa •••• 1111 (12/2028)" from PayTabs' payment_info. */
    private function label(array $info): string
    {
        $last4 = substr(preg_replace('/\D/', '', (string) ($info['payment_description'] ?? '')), -4);
        $expiry = empty($info['expiryMonth']) ? '' : sprintf(' (%02d/%d)', $info['expiryMonth'], $info['expiryYear'] ?? 0);

        return trim(($info['card_scheme'] ?? $info['payment_method'] ?? 'Card') . ' •••• ' . $last4) . $expiry;
    }

    /** The payment of a cart made here: the approved one, else the latest; '' when there is none. */
    private function tranRefOfCart(string $cartId): string
    {
        if (preg_match('/^cf-(invoice|client)-\d+-[a-z]+\d+(-[0-9a-f]{16})?$/', $cartId) !== 1) {
            return '';
        }
        $payments = $this->call('/payment/query', ['cart_id' => $cartId]);
        $payments = isset($payments['tran_ref']) ? [$payments] : array_filter($payments, 'is_array');
        foreach ($payments as $payment) {
            if (($payment['payment_result']['response_status'] ?? '') === 'A') {
                return (string) $payment['tran_ref'];
            }
        }

        return (string) (end($payments)['tran_ref'] ?? '');
    }

    /** PayTabs' payment page for a request (filled in with the client's details): its address. */
    private function startPage(array $fields, int $clientId): string
    {
        $client = $this->di['db']->getExistingModelById('Client', $clientId, 'Client not found');
        $reply = $this->call('/payment/request', $fields + [
            'tran_class' => 'ecom',
            'hide_shipping' => true,
            'customer_details' => [
                'name' => trim($client->first_name . ' ' . $client->last_name),
                'email' => (string) $client->email,
                'phone' => trim($client->phone_cc . $client->phone),
                'street1' => trim($client->address_1 . ' ' . $client->address_2),
                'city' => (string) $client->city,
                'state' => (string) $client->state,
                'country' => (string) $client->country,
                'zip' => (string) $client->postcode,
            ],
        ]);
        if (empty($reply['redirect_url'])) {
            throw new ClientFence\InformationException('PayTabs did not start the payment: :error', [':error' => $this->error($reply)]);
        }

        return (string) $reply['redirect_url'];
    }

    /** Keeps a card ("token tran_ref") as the client's saved method; the one saved before is deleted at PayTabs. */
    private function remember(int $clientId, string $method, string $label): void
    {
        $old = $this->savedCard($clientId)['payment_method'] ?? null;
        ClientFence\Mod\Invoice\SavedCards::remember($this->di, $this->gatewayId(), $this->testMode(), $clientId, '', $method, $label);
        if ($old !== null && strtok($old, ' ') !== strtok($method, ' ')) {
            $this->deleteToken($old);
        }
    }

    /** Removes a card wherever it is saved. */
    private function forget(string $method): void
    {
        $this->di['dbal']->executeStatement(
            'UPDATE pay_gateway_customer SET default_payment_method = NULL, default_card = NULL, updated_at = ? WHERE pay_gateway_id = ? AND test_mode = ? AND default_payment_method = ?',
            [date('Y-m-d H:i:s'), $this->gatewayId(), (int) $this->testMode(), $method]
        );
    }

    private function deleteToken(string $method): void
    {
        try {
            $reply = $this->call('/payment/token/delete', ['token' => strtok($method, ' ')]);
            if (isset($reply['code'])) {
                $this->di['logger']->warning('PayTabs: a saved card was not deleted at PayTabs: %s', $this->error($reply));
            }
        } catch (Throwable $e) {
            $this->di['logger']->warning('PayTabs: a saved card was not deleted at PayTabs: %s', $e->getMessage());
        }
    }

    /**
     * The cart of an invoice's balance as PayTabs charges it. The cart ID names the invoice and the try ($suffix: a
     * payment page that saves the card starts with "save"); a converted cart keeps the invoice amount asked for in udf1.
     */
    private function cart(Model_Invoice $invoice, string $suffix): array
    {
        $service = $this->di['mod_service']('Invoice');
        $amount = $service->getBalanceDue($invoice);
        $cart = [
            'cart_id' => 'cf-invoice-' . $invoice->id . '-' . $suffix,
            'cart_description' => mb_substr(__trans('Invoice :number', [':number' => $service->toApiArray($invoice)['serie_nr']]), 0, 128),
            'cart_currency' => (string) $invoice->currency,
            'cart_amount' => (float) $this->moneyFormat($amount, (string) $invoice->currency),
        ];
        $to = $this->convertTo();
        if ($to !== '' && $to !== strtoupper((string) $invoice->currency)) {
            $cart['cart_currency'] = $to;
            $cart['cart_amount'] = (float) $this->moneyFormat($this->convert($amount, (string) $invoice->currency, $to), $to);
            $cart['user_defined'] = ['udf1' => number_format($amount, 2, '.', '')];
        }

        return $cart;
    }

    /** PayTabs' API: the reply (an error reply has code and message). No answer throws Payment_Exception. */
    private function call(string $path, array $fields): array
    {
        $prefix = $this->testMode() ? 'test_' : '';
        try {
            $response = $this->di['http_client']->request('POST', (self::REGIONS[$this->config['region'] ?? ''] ?? self::REGIONS['GLOBAL']) . $path, [
                'timeout' => 60,
                'headers' => ['authorization' => trim((string) $this->config[$prefix . 'server_key'])],
                'json' => ['profile_id' => (int) $this->config[$prefix . 'profile_id']] + $fields,
            ]);
            $reply = $response->toArray(false);
        } catch (Throwable $e) {
            throw new Payment_Exception('PayTabs did not answer :path: :error', [':path' => $path, ':error' => $e->getMessage()]);
        }

        return $reply;
    }

    private function error(array $reply): string
    {
        return trim(($reply['message'] ?? $reply['payment_result']['response_message'] ?? 'unknown error') . ' (' . ($reply['code'] ?? $reply['payment_result']['response_code'] ?? '-') . ')');
    }

    /** WHMCS's "Convert To For Processing": the currency PayTabs charges in, empty for each invoice's own. */
    private function convertTo(): string
    {
        return strtoupper(trim((string) ($this->config['convert_to'] ?? '')));
    }

    /** At the portal's exchange rates, which are to the default currency. */
    private function convert(float $amount, string $from, string $to): float
    {
        $currency = $this->di['mod_service']('Currency');

        return $currency->toBaseCurrency($from, $amount) / $currency->getBaseCurrencyRate($to);
    }

    private function moneyFormat(float $amount, string $currency): string
    {
        $digits = Symfony\Component\Intl\Currencies::exists($currency) ? Symfony\Component\Intl\Currencies::getFractionDigits($currency) : 2;

        return number_format($amount, $digits, '.', '');
    }
}
