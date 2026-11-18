<?php

declare(strict_types=1);

/**
 * ClientFence: CCAvenue (non-seamless billing page), one-time payments. The invoice page posts the order, encrypted with
 * the working key (AES-128-CBC, key = MD5 of the working key, IV 00..0f, hex), to CCAvenue's billing page. CCAvenue
 * posts the encrypted result (encResp) back to invoice/ccavenue-paid/<gateway>, which records it and sends the client to
 * the invoice. The return only
 * names the order: its status, amount and currency are always read back with CCAvenue's Status API (orderStatusTracker),
 * so only this install's orders (order ID cf<invoice>-<site>-<time>) that CCAvenue reports paid pay an invoice. Staff
 * refunds use refundOrder. Follows
 * CCAvenue's integration and API guides; not tested against a live CCAvenue account yet.
 */
class Payment_Adapter_CCAvenue implements ClientFence\InjectionAwareInterface
{
    /** The Status API's order statuses of a payment taken ("Shipped": confirmed by the merchant). */
    private const PAID = ['Successful', 'Shipped'];

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
        $prefix = $this->testMode() ? 'test_' : '';
        foreach (['merchant_id', $prefix . 'access_code', $prefix . 'working_key'] as $key) {
            if (trim((string) ($this->config[$key] ?? '')) === '') {
                throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'CCAvenue', ':missing' => 'merchant ID, ' . ($prefix === '' ? 'live' : 'test') . ' access code and working key'], 4001);
            }
        }
    }

    public static function getConfig(): array
    {
        return [
            'title' => 'CCAvenue',
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => __trans('Payments on CCAvenue\'s billing page. CCAvenue charges in the invoice\'s currency: turn on the currencies of your invoices in your CCAvenue account. Payments are checked with CCAvenue\'s API, so register this server\'s IP address with CCAvenue for API access. [How to set up CCAvenue](https://clientfence.com/docs/clients-and-billing/ccavenue/)'),
            'form' => [
                'merchant_id' => ['text', ['label' => __trans('Merchant ID:'), 'description' => __trans('CCAvenue M.A.R.S: Settings, API Keys.')]],
                'access_code' => ['text', ['label' => __trans('Live access code:')]],
                'working_key' => ['password', ['label' => __trans('Live working key:'), 'secret' => true]],
                'test_access_code' => ['text', ['label' => __trans('Test access code:')]],
                'test_working_key' => ['password', ['label' => __trans('Test working key:'), 'secret' => true]],
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

    /** The form that sends the client to CCAvenue's billing page with the invoice's balance. */
    public function getHtml($api_admin, $invoice_id, $subscription): string
    {
        $invoice = $this->di['db']->getExistingModelById('Invoice', (int) $invoice_id, 'Invoice not found');
        // A short address with no query string: CCAvenue's kits send the order's values unencoded, so no value may hold & or =.
        $back = (string) $this->di['url']->link('invoice/ccavenue-paid/' . $this->gatewayId());
        $order = [
            'merchant_id' => trim((string) $this->config['merchant_id']),
            'order_id' => 'cf' . (int) $invoice->id . '-' . $this->site() . '-' . time(),
            'currency' => strtoupper((string) $invoice->currency),
            'amount' => number_format($this->di['mod_service']('Invoice')->getBalanceDue($invoice), 2, '.', ''),
            'redirect_url' => $back,
            'cancel_url' => $back,
            'language' => 'EN',
        ];
        $text = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<form name="payment_form" action="' . $text($this->host('secure') . '/transaction/transaction.do?command=initiateTransaction') . '" method="post">'
            . '<input type="hidden" name="encRequest" value="' . $this->encrypt(implode('&', array_map(static fn (string $key, string $value): string => $key . '=' . $value, array_keys($order), $order))) . '">'
            . '<input type="hidden" name="access_code" value="' . $text($this->credential('access_code')) . '">'
            . '<input class="btn btn-primary" type="submit" id="payment_button" value="' . $text(__trans('Pay with CCAvenue')) . '">'
            . '</form>';
        if (!empty($this->config['auto_redirect'])) {
            $html .= '<script nonce="' . ClientFence\Csp::nonce() . '">document.addEventListener("DOMContentLoaded", function () { document.getElementById("payment_button").style.display = "none"; document.forms.payment_form.submit(); });</script>';
        }

        return $html;
    }

    /**
     * [invoice ID, tracking_id] of this install's order that CCAvenue's encrypted return names, or null. Unproven: the
     * encryption has no integrity check, so only the Status API's read-back (a ccavenue row) proves the order.
     */
    public function returnedOrder(string $encResp): ?array
    {
        parse_str($this->decrypt($encResp) ?? '', $return);

        return preg_match('/^cf(\d+)-' . $this->site() . '-\d+$/', (string) ($return['order_id'] ?? ''), $match) === 1 ? [(int) $match[1], (string) ($return['tracking_id'] ?? '')] : null;
    }

    /** CCAvenue's return (post.encResp): the order it names is read back from CCAvenue and pays its invoice once. */
    public function processTransaction($api_admin, $id, $data, $gateway_id): void
    {
        parse_str($this->decrypt((string) ($data['post']['encResp'] ?? '')) ?? '', $return);
        $orderId = (string) ($return['order_id'] ?? '');
        $trackingId = (string) ($return['tracking_id'] ?? '');
        if (preg_match('/^cf\d+-[0-9a-f]{6}-\d+$/', $orderId) !== 1 || preg_match('/^\d{1,25}$/', $trackingId) !== 1) {
            throw new Payment_Exception('CCAvenue return names no payment');
        }
        $this->recordPayment($api_admin, (int) $id, $orderId, $trackingId);
    }

    /** An order as CCAvenue's Status API reports it: recorded on transaction $id and applied to its invoice once. */
    private function recordPayment($api_admin, int $id, string $orderId, string $trackingId): void
    {
        $order = $this->status($orderId, $trackingId);
        // Only this install's orders name an invoice: one of another shop or portal on the same account pays nothing.
        if ((string) ($order['order_no'] ?? '') !== $orderId || (string) ($order['reference_no'] ?? '') !== $trackingId
            || preg_match('/^cf(\d+)-' . $this->site() . '-\d+$/', $orderId, $match) !== 1) {
            throw new Payment_Exception('CCAvenue payment :id is not for a ClientFence invoice', [':id' => $trackingId]);
        }
        $invoice = $this->di['db']->getExistingModelById('Invoice', (int) $match[1], 'Invoice not found');
        $amount = (float) ($order['order_amt'] ?? 0);
        // The guide's field table says order_curr, its examples order_currncy.
        $currency = strtoupper((string) ($order['order_currncy'] ?? $order['order_curr'] ?? ''));
        $status = (string) ($order['order_status'] ?? '');
        $row = ['invoice_id' => $invoice->id, 'txn_id' => $trackingId, 'type' => 'ccavenue', 'amount' => $amount, 'currency' => $currency, 'updated_at' => date('Y-m-d H:i:s')];
        if (in_array($status, ['Awaited', 'Pending'], true)) {
            // As WHMCS's "Payment Pending": the cron reads it back until CCAvenue settles it.
            $this->di['dbal']->update('`transaction`', $row + ['txn_status' => 'processing', 'status' => Model_Transaction::STATUS_RECEIVED, 'error' => 'CCAvenue is still processing the payment: ' . $status], ['id' => $id]);

            return;
        }
        if (!in_array($status, self::PAID, true)) {
            $this->di['dbal']->update('`transaction`', $row + ['txn_status' => $status, 'status' => Model_Transaction::STATUS_ERROR, 'error' => 'CCAvenue payment not completed: ' . trim($status . ' ' . ($order['order_bank_response'] ?? ''))], ['id' => $id]);

            return;
        }
        // The return may be posted twice at once (a double click, a reload).
        ClientFence\Tools::withLock($this->di, 'cf:ccavenue-payment:' . $trackingId, fn () => $this->applyPayment($api_admin, $id, $trackingId, $invoice, $amount, $currency, $row));
    }

    /** Applies a paid order to its invoice: false when it was recorded already. */
    private function applyPayment($api_admin, int $id, string $trackingId, Model_Invoice $invoice, float $amount, string $currency, array $row): bool
    {
        $db = $this->di['dbal'];
        // Applied already ("Process again", the return posted again, another CCAvenue gateway): never twice.
        $done = $db->fetchOne("SELECT id FROM `transaction` WHERE txn_id = ? AND type = 'ccavenue' AND status = ? AND txn_status = ? ORDER BY id LIMIT 1", [$trackingId, Model_Transaction::STATUS_PROCESSED, 'Completed']);
        if ($done !== false) {
            if ((int) $done !== $id) {
                // amount null: a row that waited for this payment must not count it in the paid totals again.
                $db->update('`transaction`', ['txn_id' => $trackingId, 'amount' => null, 'status' => Model_Transaction::STATUS_PROCESSED, 'note' => 'Payment ' . $trackingId . ' was already recorded on transaction #' . $done . '.', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
            }

            return false;
        }
        if ($currency !== strtoupper((string) $invoice->currency)) {
            throw new Payment_Exception('CCAvenue payment :id is in :currency, the invoice in :invoice', [':id' => $trackingId, ':currency' => $currency, ':invoice' => $invoice->currency]);
        }
        $service = $this->di['mod_service']('Invoice');
        $service->validatePaymentAmount($amount, $service->getBalanceDue($invoice), $invoice);
        $db->update('`transaction`', $row + ['txn_status' => 'Completed'], ['id' => $id]);
        // As PayPal: the payment goes to the client's credit, which then pays the invoice.
        // A retry after a try that failed once the payment was credited.
        if (!\ClientFence\Mod\Client\Service::hasGatewayCredit($db, 'CCAvenue', $trackingId)) {
            $api_admin->client_balance_add_funds(['id' => $invoice->client_id, 'amount' => $amount, 'description' => 'CCAvenue payment ' . $trackingId, 'type' => 'CCAvenue', 'rel_id' => $trackingId]);
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

    /** Sends money of a payment (CCAvenue's tracking_id) back with refundOrder (staff refunds): our reference of the refund. */
    public function refundPayment(string $transactionId, float $amount, string $currency): string
    {
        if (preg_match('/^\d{1,25}$/', $transactionId) !== 1) {
            throw new ClientFence\InformationException('CCAvenue refunds only its own payments.');
        }
        // Our reference of this refund: new for each request.
        $reference = 'cf' . bin2hex(random_bytes(8));
        [$refund, $refused] = $this->api('refundOrder', ['reference_no' => $transactionId, 'refund_amount' => number_format($amount, 2, '.', ''), 'refund_ref_no' => $reference], 'Refund_Order_Result');
        // refund_status 0: the refund is made; a refused one names its reason.
        if ((string) ($refund['refund_status'] ?? '1') !== '0') {
            throw new ClientFence\InformationException('CCAvenue did not make the refund: :error', [':error' => isset($refund['refund_status']) ? trim(($refund['reason'] ?? 'unknown error') . ' ' . ($refund['error_code'] ?? '')) : $refused]);
        }

        return $reference;
    }

    /** Refunds (Invoice ServiceSubscription::gatewayCan()) always go to CCAvenue. */
    public function hasApiKeys(): bool
    {
        return true;
    }

    /** The order from CCAvenue's Status API (orderStatusTracker). No answer or a refused call throws Payment_Exception. */
    private function status(string $orderId, string $trackingId): array
    {
        [$order, $refused] = $this->api('orderStatusTracker', ['reference_no' => $trackingId, 'order_no' => $orderId], 'Order_Status_Result');
        if ((string) ($order['status'] ?? '1') !== '0') {
            throw new Payment_Exception('CCAvenue did not report payment :id: :error', [':id' => $trackingId, ':error' => isset($order['status']) ? trim(($order['error_desc'] ?? 'unknown error') . ' ' . ($order['error_code'] ?? '')) : $refused]);
        }

        return $order;
    }

    /**
     * A command of CCAvenue's API (DoWebTrans, JSON, version 1.2): [its decrypted reply, the error text when CCAvenue
     * refused the call]. No answer throws Payment_Exception.
     */
    private function api(string $command, array $request, string $result): array
    {
        try {
            $response = $this->di['http_client']->request('POST', $this->host('api') . '/apis/servlet/DoWebTrans', [
                'timeout' => 60,
                'body' => [
                    'enc_request' => $this->encrypt((string) json_encode($request)),
                    'access_code' => $this->credential('access_code'),
                    'command' => $command,
                    'request_type' => 'JSON',
                    'response_type' => 'JSON',
                    'version' => '1.2',
                ],
            ]);
            parse_str(trim($response->getContent()), $reply);
        } catch (Throwable $e) {
            throw new Payment_Exception('CCAvenue did not answer :path: :error', [':path' => $command, ':error' => $e->getMessage()]);
        }
        // status 1: enc_response is a plain error message.
        $data = ($reply['status'] ?? '') === '0' ? json_decode($this->decrypt((string) ($reply['enc_response'] ?? '')) ?? '', true) : null;
        // shortcut: the guide's JSON examples are flat; open integrations read a wrapper (Order_Status_Result, Refund_Order_Result), so both are read.
        $data = is_array($data) ? ($data[$result] ?? $data) : [];

        return [$data, trim((string) ($reply['enc_response'] ?? 'unknown error') . ' ' . ($reply['enc_error_code'] ?? ''))];
    }

    /** CCAvenue's encryption (its integration kits): AES-128-CBC, key = MD5 of the working key, IV bytes 00..0f, hex. */
    private function encrypt(string $plain): string
    {
        return bin2hex((string) openssl_encrypt($plain, 'aes-128-cbc', md5($this->credential('working_key'), true), OPENSSL_RAW_DATA, self::iv()));
    }

    /** The plain text of CCAvenue's encrypted hex, null when it is not one (tampered, another working key). */
    private function decrypt(string $hex): ?string
    {
        $plain = preg_match('/^(?:[0-9a-fA-F]{32})+$/', $hex) === 1 ? openssl_decrypt((string) hex2bin($hex), 'aes-128-cbc', md5($this->credential('working_key'), true), OPENSSL_RAW_DATA, self::iv()) : false;

        return $plain === false ? null : $plain;
    }

    private static function iv(): string
    {
        return implode('', array_map('chr', range(0, 15)));
    }

    /** "https://secure.ccavenue.com" / "https://api.ccavenue.com", or their test hosts (test., apitest.). */
    private function host(string $live): string
    {
        return 'https://' . ($this->testMode() ? ($live === 'api' ? 'apitest' : 'test') : $live) . '.ccavenue.com';
    }

    private function credential(string $key): string
    {
        return trim((string) $this->config[($this->testMode() ? 'test_' : '') . $key]);
    }

    /** This install in the order ID, so a CCAvenue account shared by several portals pays only its own invoices. */
    private function site(): string
    {
        return substr(md5((string) INSTANCE_ID), 0, 6);
    }
}
