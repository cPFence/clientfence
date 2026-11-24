<?php

declare(strict_types=1);

/**
 * ClientFence: PayU India (PayU Hosted Checkout). The client pays an invoice's balance in Indian rupees on PayU's page:
 * the invoice page posts a form signed with the merchant salt (SHA-512 request hash) to PayU's _payment page. PayU
 * posts the result back to ipn.php (surl and furl), which then shows the invoice; PayU's payment webhooks (set in the
 * PayU dashboard) post the same fields. Each post must carry PayU's reverse hash, and the payment is then always read
 * back with the verify_payment API, so only this site's payments made here (txnid cf<invoice>-..., udf1 the instance)
 * pay an invoice. Staff refunds use cancel_refund_transaction. Follows PayU's documentation; not tested against a live
 * PayU account yet.
 */
class Payment_Adapter_PayU implements ClientFence\InjectionAwareInterface
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
        [$key, $salt] = $this->keys();
        if ($key === '' || $salt === '') {
            throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'PayU', ':missing' => ($this->testMode() ? 'test' : 'live') . ' merchant key and salt'], 4001);
        }
    }

    public static function getConfig(): array
    {
        return [
            'title' => 'PayU',
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => __trans('Payments in Indian rupees (INR) on PayU India\'s payment page: cards, UPI, net banking and wallets. [How to set up PayU](https://clientfence.com/docs/clients-and-billing/payu/)'),
            'form' => [
                'merchant_key' => ['text', ['label' => __trans('Live merchant key:'), 'description' => __trans('PayU dashboard: Developers, Key and Salt. Test mode uses the test key and salt.')]],
                'merchant_salt' => ['password', ['label' => __trans('Live merchant salt:'), 'secret' => true]],
                'test_merchant_key' => ['text', ['label' => __trans('Test merchant key:')]],
                'test_merchant_salt' => ['password', ['label' => __trans('Test merchant salt:'), 'secret' => true]],
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

    /** Refunds (Invoice ServiceSubscription::gatewayCan()) always go to PayU. */
    public function hasApiKeys(): bool
    {
        return true;
    }

    /** The form that sends the client to PayU's page to pay the invoice's balance. */
    public function getHtml($api_admin, $invoice_id, $subscription): string
    {
        $invoice = $this->di['db']->getExistingModelById('Invoice', (int) $invoice_id, 'Invoice not found');
        $text = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // PayU India's hosted page takes no currency: it charges rupees.
        if (strtoupper((string) $invoice->currency) !== 'INR') {
            return '<p>' . $text(__trans('PayU takes payments in Indian rupees (INR) only.')) . '</p>';
        }
        $html = '<form name="payu_form" action="' . ($this->testMode() ? 'https://test.payu.in/_payment' : 'https://secure.payu.in/_payment') . '" method="post">';
        foreach ($this->paymentFields($invoice) as $name => $value) {
            $html .= '<input type="hidden" name="' . $text($name) . '" value="' . $text($value) . '">';
        }
        $html .= '<button type="submit" class="btn btn-primary">' . $text(__trans('Pay with PayU')) . '</button></form>';
        if (!empty($this->config['auto_redirect'])) {
            $html .= '<script nonce="' . ClientFence\Csp::nonce() . '">document.addEventListener("DOMContentLoaded", function () { document.forms.payu_form.submit(); });</script>';
        }

        return $html;
    }

    /** The posted fields of a payment of the invoice's balance, signed with the request hash. */
    public function paymentFields(Model_Invoice $invoice): array
    {
        [$key, $salt] = $this->keys();
        $service = $this->di['mod_service']('Invoice');
        $client = $this->di['db']->getExistingModelById('Client', (int) $invoice->client_id, 'Client not found');
        // Back to ipn.php, which records the result and then shows the invoice.
        $back = ClientFence\Site::current($this->di)->url . 'ipn.php?' . http_build_query(['gateway_id' => $this->gatewayId(), 'invoice_id' => (int) $invoice->id, 'invoice_hash' => $invoice->hash, 'redirect' => 1]);
        $fields = [
            'key' => $key,
            // Unique per try (PayU refuses a txnid paid before), at most 25 characters; it names the invoice.
            'txnid' => 'cf' . (int) $invoice->id . '-' . bin2hex(random_bytes(4)),
            'amount' => number_format($service->getBalanceDue($invoice), 2, '.', ''),
            'productinfo' => mb_substr(__trans('Invoice :number', [':number' => (string) $service->toApiArray($invoice)['serie_nr']]), 0, 100),
            'firstname' => mb_substr(trim((string) $client->first_name) ?: (string) $client->last_name, 0, 20),
            'email' => (string) $client->email,
            // Tells this site's payments from another's on the same merchant key.
            'udf1' => INSTANCE_ID,
        ];
        $fields['hash'] = hash('sha512', implode('|', [$key, $fields['txnid'], $fields['amount'], $fields['productinfo'], $fields['firstname'], $fields['email'], $fields['udf1'], '', '', '', '', '', '', '', '', '', $salt]));

        return $fields + [
            'lastname' => mb_substr((string) $client->last_name, 0, 20),
            'phone' => mb_substr((string) preg_replace('/\D/', '', (string) $client->phone), 0, 50),
            'surl' => $back,
            'furl' => $back,
        ];
    }

    /** Sends money of a payment (PayU's mihpayid) back (staff refunds): PayU's refund request ID. */
    public function refundPayment(string $transactionId, float $amount, string $currency): string
    {
        if (strtoupper($currency) !== 'INR' || preg_match('/^\d{1,20}$/', $transactionId) !== 1) {
            throw new ClientFence\InformationException('PayU refunds only its own payments in Indian rupees (INR).');
        }
        // var2 is our token for this refund: new for each request, at most 23 characters.
        $reply = $this->call('cancel_refund_transaction', $transactionId, ['var2' => 'cf' . bin2hex(random_bytes(10)), 'var3' => number_format($amount, 2, '.', '')]);
        if ((int) ($reply['status'] ?? 0) !== 1 || empty($reply['request_id'])) {
            throw new ClientFence\InformationException('PayU did not make the refund: :error', [':error' => (string) ($reply['msg'] ?? 'unknown error')]);
        }

        return (string) $reply['request_id'];
    }

    /**
     * PayU's post to ipn.php: the browser's return (surl, furl) or a payment webhook. Its reverse hash must match; the
     * payment it names is then read back from PayU and recorded on its invoice.
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id): void
    {
        $post = array_map(static fn ($value): string => is_string($value) ? $value : '', (array) ($data['post'] ?? []));
        [$key, $salt] = $this->keys();
        $fields = [$salt, $post['status'] ?? '', '', '', '', '', '', $post['udf5'] ?? '', $post['udf4'] ?? '', $post['udf3'] ?? '', $post['udf2'] ?? '', $post['udf1'] ?? '', $post['email'] ?? '', $post['firstname'] ?? '', $post['productinfo'] ?? '', $post['amount'] ?? '', $post['txnid'] ?? '', $key];
        // PayU puts the convenience fee it charged first, when there is one.
        if ((float) ($post['additionalCharges'] ?? 0) > 0) {
            array_unshift($fields, $post['additionalCharges']);
        }
        if (($post['hash'] ?? '') === '' || !hash_equals(hash('sha512', implode('|', $fields)), strtolower($post['hash']))) {
            throw new Payment_Exception('PayU notice is not signed with this merchant key and salt');
        }
        if (preg_match('/^cf(\d+)-[0-9a-f]{8}$/', $post['txnid'] ?? '', $match) !== 1) {
            throw new Payment_Exception('PayU payment :id is not for a ClientFence invoice', [':id' => $post['txnid'] ?? '']);
        }
        $this->recordPayment($api_admin, (int) $id, $post['txnid'], (int) $match[1]);
    }

    /** A payment as PayU's verify_payment reports it: recorded on transaction $id and applied to its invoice once. */
    private function recordPayment($api_admin, int $id, string $txnid, int $invoiceId): void
    {
        $payment = $this->call('verify_payment', $txnid)['transaction_details'][$txnid] ?? [];
        $mihpayid = (string) ($payment['mihpayid'] ?? '');
        if (preg_match('/^\d{1,20}$/', $mihpayid) !== 1) {
            throw new Payment_Exception('PayU payment :id was not found', [':id' => $txnid]);
        }
        if (($payment['udf1'] ?? '') !== INSTANCE_ID) {
            throw new Payment_Exception('PayU payment :id is not for a ClientFence invoice', [':id' => $txnid]);
        }
        $invoice = $this->di['db']->getExistingModelById('Invoice', $invoiceId, 'Invoice not found');
        // amt is the amount we asked for, in rupees; a convenience fee PayU added is not ours.
        $amount = round((float) ($payment['amt'] ?? 0), 2);
        $row = ['invoice_id' => $invoice->id, 'txn_id' => $mihpayid, 'type' => 'payu', 'amount' => $amount, 'currency' => 'INR', 'updated_at' => date('Y-m-d H:i:s')];
        if (($payment['status'] ?? '') === 'pending') {
            // As WHMCS's "Payment Pending" (net banking, UPI): the cron reads it back until PayU settles it.
            $this->di['dbal']->update('`transaction`', $row + ['txn_status' => 'processing', 'status' => Model_Transaction::STATUS_RECEIVED, 'error' => 'PayU is still processing the payment'], ['id' => $id]);

            return;
        }
        if (($payment['status'] ?? '') !== 'success') {
            $this->di['dbal']->update('`transaction`', $row + ['txn_status' => (string) ($payment['status'] ?? 'unknown'), 'status' => Model_Transaction::STATUS_ERROR, 'error' => 'PayU payment not completed: ' . ($payment['error_Message'] ?? $payment['status'] ?? 'unknown')], ['id' => $id]);

            return;
        }
        // The browser's return and the webhook may come at once.
        ClientFence\Tools::withLock($this->di, 'cf:payu-payment:' . $mihpayid, fn () => $this->applyPayment($api_admin, $id, $mihpayid, $invoice, $amount, $row));
    }

    /** Applies a completed payment to its invoice, never twice. */
    private function applyPayment($api_admin, int $id, string $mihpayid, Model_Invoice $invoice, float $amount, array $row): void
    {
        $db = $this->di['dbal'];
        // Applied already (the return and the webhook, "Process again", another PayU gateway): never twice.
        $done = $db->fetchOne("SELECT id FROM `transaction` WHERE txn_id = ? AND type = 'payu' AND status = ? AND txn_status = ? ORDER BY id LIMIT 1", [$mihpayid, Model_Transaction::STATUS_PROCESSED, 'Completed']);
        if ($done !== false) {
            if ((int) $done !== $id) {
                // amount null: a row that waited for this payment must not count it in the paid totals again.
                $db->update('`transaction`', ['txn_id' => $mihpayid, 'amount' => null, 'status' => Model_Transaction::STATUS_PROCESSED, 'note' => 'Payment ' . $mihpayid . ' was already recorded on transaction #' . $done . '.', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
            }

            return;
        }
        if (strtoupper((string) $invoice->currency) !== 'INR') {
            throw new Payment_Exception('PayU payment :id is in INR, the invoice in :invoice', [':id' => $mihpayid, ':invoice' => $invoice->currency]);
        }
        $service = $this->di['mod_service']('Invoice');
        $service->validatePaymentAmount($amount, $service->getBalanceDue($invoice), $invoice);
        $db->update('`transaction`', $row + ['txn_status' => 'Completed'], ['id' => $id]);
        // As PayPal: the payment goes to the client's credit, which then pays the invoice.
        // A retry after a try that failed once the payment was credited.
        if (!\ClientFence\Mod\Client\Service::hasGatewayCredit($db, 'PayU', $mihpayid)) {
            $api_admin->client_balance_add_funds(['id' => $invoice->client_id, 'amount' => $amount, 'description' => 'PayU payment ' . $mihpayid, 'type' => 'PayU', 'rel_id' => $mihpayid]);
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

    /** A command of PayU's postservice API, signed sha512(key|command|var1|salt): its JSON reply. No answer throws Payment_Exception. */
    private function call(string $command, string $var1, array $more = []): array
    {
        [$key, $salt] = $this->keys();
        try {
            return $this->di['http_client']->request('POST', ($this->testMode() ? 'https://test.payu.in' : 'https://info.payu.in') . '/merchant/postservice.php?form=2', [
                'timeout' => 60,
                'body' => ['key' => $key, 'command' => $command, 'var1' => $var1] + $more + ['hash' => hash('sha512', $key . '|' . $command . '|' . $var1 . '|' . $salt)],
            ])->toArray(false);
        } catch (Throwable $e) {
            throw new Payment_Exception('PayU did not answer :command: :error', [':command' => $command, ':error' => $e->getMessage()]);
        }
    }

    /** The merchant key and salt of the current mode. */
    private function keys(): array
    {
        $prefix = $this->testMode() ? 'test_' : '';

        return [trim((string) ($this->config[$prefix . 'merchant_key'] ?? '')), trim((string) ($this->config[$prefix . 'merchant_salt'] ?? ''))];
    }
}
