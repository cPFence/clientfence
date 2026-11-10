<?php

declare(strict_types=1);
/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

use ClientFence\Environment;
use Pimple\Container;

class Payment_Adapter_PayPalEmail extends Payment_AdapterAbstract implements ClientFence\InjectionAwareInterface
{
    protected ?Container $di = null;
    // ClientFence core patch: the REST API access token of this request (see restToken()).
    private ?string $restToken = null;

    public function setDi(Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Container
    {
        return $this->di;
    }

    public function __construct(private $config)
    {
        if (!isset($this->config['email'])) {
            throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'PayPal', ':missing' => 'PayPal Email address'], 4001);
        }
    }

    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions' => true,
            'description' => __trans('Enter your PayPal email to start accepting payments by PayPal.'),
            'logo' => [
                'logo' => 'paypal.png',
                'height' => '25px',
                'width' => '85px',
            ],
            'form' => [
                'email' => [
                    'text',
                    [
                        'label' => __trans('PayPal email address for payments'),
                        'validators' => ['EmailAddress'],
                    ],
                ],
                // ClientFence core patch: optional REST API keys (a PayPal REST app): staff refunds go to PayPal and
                // subscriptions are cancelled from ClientFence, as WHMCS does. Without them PayPal works as before.
                'api_client_id' => [
                    'text', [
                        'label' => __trans('Live REST API client ID (optional):'),
                        'description' => __trans('The client ID and secret of a PayPal REST app (developer.paypal.com, Apps & Credentials). With them, refunds are sent to PayPal and PayPal subscriptions are cancelled from ClientFence.'),
                    ],
                ],
                'api_secret' => [
                    'password', [
                        'label' => __trans('Live REST API secret:'),
                        'secret' => true,
                    ],
                ],
                'test_api_client_id' => [
                    'text', [
                        'label' => __trans('Sandbox REST API client ID (optional):'),
                    ],
                ],
                'test_api_secret' => [
                    'password', [
                        'label' => __trans('Sandbox REST API secret:'),
                        'secret' => true,
                    ],
                ],
                // ClientFence core patch: saved PayPal accounts pay invoices automatically, as saved cards do (Invoice/SavedCards.php).
                'vault' => [
                    'radio', [
                        'label' => __trans('Saved PayPal accounts:'),
                        'description' => __trans('Clients can save their PayPal account when they pay or on their Payment methods page, and their invoices are then paid automatically, as with saved cards. Needs the REST API keys and the Vault ("Save payment methods") on your PayPal REST app, which PayPal offers in some countries only.'),
                        'multiOptions' => ['1' => __trans('On'), '0' => __trans('Off')],
                        'default' => '0',
                    ],
                ],
                // ClientFence core patch: the PayPal balance on the admin dashboard (Invoice Api\Admin::paypal_balances()).
                'balance' => [
                    'radio', [
                        'label' => __trans('PayPal balance on the dashboard:'),
                        'description' => __trans('Needs the REST API keys, with "Transaction search" turned on for the PayPal REST app.'),
                        'multiOptions' => ['1' => __trans('On'), '0' => __trans('Off')],
                    ],
                ],
            ],
        ];
    }

    public function getHtml($api_admin, $invoice_id, $subscription): string
    {
        $invoiceModel = $this->di['db']->load('Invoice', $invoice_id);
        $invoiceService = $this->di['mod_service']('Invoice');
        $invoice = $invoiceService->toApiArray($invoiceModel, true);

        $data = [];
        if ($subscription) {
            $data = $this->getSubscriptionFields($invoice);
        } else {
            $data = $this->getOneTimePaymentFields($invoice);
        }

        $url = $this->serviceUrl();
        // ClientFence core patch: on their own invoice, a signed-in account owner may pay and save their PayPal account,
        // so the page waits for their choice instead of going to PayPal at once.
        $user = ClientFence\Mod\Client\Users::context($this->di);
        $save = !$subscription && $this->canVault() && (int) ($this->di['session']->get('client_id') ?? 0) === (int) $invoiceModel->client_id && ($user === null || $user['owner']);
        if ($save) {
            $this->config['auto_redirect'] = false;
        }
        $form = $this->_generateForm($url, $data);
        $saved = $save ? $this->savedCard((int) $invoiceModel->client_id) : null;
        if ($saved !== null) {
            // ClientFence core patch: the saved PayPal account pays at once, as PayPal Auto-Pay and PayTabs offer (GitHub #6).
            $json = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
            $form .= '<p class="mt-3"><button type="button" class="btn btn-primary" data-cf-paypal-pay>' . htmlspecialchars(__trans('Pay now with :account', [':account' => $saved['card']]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</button></p>'
                . '<script nonce="' . ClientFence\Csp::nonce() . '">document.querySelector("[data-cf-paypal-pay]").addEventListener("click", function (event) {'
                . ' var button = event.currentTarget; button.disabled = true;'
                . ' ClientFence.api.client.post("invoice/paypal_pay", ' . json_encode(['hash' => $invoiceModel->hash], $json) . ', function (result) {'
                . ' if (result.paid) { location.assign(' . json_encode((string) $this->di['url']->link('invoice/thank-you/' . $invoiceModel->hash), $json | JSON_UNESCAPED_SLASHES) . '); } else { ClientFence.message(result.message, "info"); }'
                . ' }, function (error) { button.disabled = false; ClientFence.message(error.message, "error"); });'
                . ' });</script>';
        } elseif ($save) {
            $form .= sprintf(
                '<p class="mt-3"><a class="btn btn-outline-primary" href="%s">%s</a></p>',
                htmlspecialchars((string) $this->di['url']->link('invoice/paypal-save/' . $invoiceModel->hash), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars(__trans('Pay and save my PayPal account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            );
        }

        return $form;
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id): void
    {
        // ClientFence core patch: a payment through the REST API (a saved PayPal account, "Pay and save"), read back from PayPal.
        if (isset($data['get']['paypal_capture'])) {
            $this->processRestCapture($api_admin, (int) $id, (string) $data['get']['paypal_capture']);

            return;
        }

        if (!Environment::isTesting() && !$this->_isIpnValid($data)) {
            throw new Payment_Exception('IPN is invalid');
        }

        $ipn = $data['post'];

        if (!isset($ipn['mc_currency']) && isset($ipn['amount_currency'])) {
            $ipn['mc_currency'] = $ipn['amount_currency'];
        }

        $tx = $api_admin->invoice_transaction_get(['id' => $id]);

        // The invoice binding arrives through a buyer-editable callback URL,
        // so authenticate it before anything below trusts it. Runs outside
        // the test-mode bypass above: the binding must hold in every environment.
        $verifiedInvoiceId = $this->verifyCallbackBinding($data, (int) $gateway_id, $tx['invoice_id'] ?? null, (int) $id);

        // Set the invoice ID if it's not set. The value was authenticated above.
        if (!$tx['invoice_id']) {
            if ($verifiedInvoiceId === null) {
                throw new Payment_Exception('PayPal transaction is not associated with an invoice');
            }
            $tx['invoice_id'] = $verifiedInvoiceId;
            $api_admin->invoice_transaction_update(['id' => $id, 'invoice_id' => $verifiedInvoiceId]);
        }

        if (!$tx['type'] && isset($ipn['txn_type'])) {
            $api_admin->invoice_transaction_update(['id' => $id, 'type' => $ipn['txn_type']]);
        }

        if (!$tx['txn_id'] && isset($ipn['txn_id'])) {
            $api_admin->invoice_transaction_update(['id' => $id, 'txn_id' => $ipn['txn_id']]);
        }

        if (!$tx['txn_status'] && isset($ipn['payment_status'])) {
            $api_admin->invoice_transaction_update(['id' => $id, 'txn_status' => $ipn['payment_status']]);
        }

        if (!$tx['amount'] && isset($ipn['mc_gross'])) {
            $api_admin->invoice_transaction_update(['id' => $id, 'amount' => $ipn['mc_gross']]);
        }

        if (!$tx['currency'] && isset($ipn['mc_currency'])) {
            $api_admin->invoice_transaction_update(['id' => $id, 'currency' => $ipn['mc_currency']]);
        }

        $invoice = $api_admin->invoice_get(['id' => $tx['invoice_id']]);
        $client_id = $invoice['client']['id'];

        $txnType = $ipn['txn_type'] ?? '';
        switch ($txnType) {
            case 'web_accept':
            case 'subscr_payment':
                if ($ipn['payment_status'] == 'Completed') {
                    // Skip only if we've already processed a Completed payment for this transaction
                    if (isset($tx['status'], $tx['txn_status']) && $tx['status'] === Model_Transaction::STATUS_PROCESSED && $tx['txn_status'] === 'Completed') {
                        $d = [
                            'id' => $id,
                            'error' => '',
                            'error_code' => null,
                            'status' => Model_Transaction::STATUS_PROCESSED,
                            'updated_at' => date('Y-m-d H:i:s'),
                        ];
                        $api_admin->invoice_transaction_update($d);

                        return;
                    }

                    $this->validateCurrency($ipn['mc_currency'] ?? null, $invoice['currency'] ?? null);
                } else {
                    $api_admin->invoice_transaction_update([
                        'id' => $id,
                        'txn_status' => (string) ($ipn['payment_status'] ?? ''),
                        'status' => Model_Transaction::STATUS_RECEIVED,
                        'error' => sprintf('PayPal payment not completed: %s', (string) ($ipn['payment_status'] ?? 'unknown')),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);

                    // ClientFence core patch: pending payments must remain retryable by a later Completed IPN (a refund goes on to the refund below).
                    if (($ipn['payment_status'] ?? '') !== 'Refunded') {
                        return;
                    }

                    break;
                }

                // Reload transaction to get updated status
                $tx = $api_admin->invoice_transaction_get(['id' => $id]);

                // Update to Completed so transaction record reflects final PayPal status
                if (!isset($tx['txn_status']) || $tx['txn_status'] !== 'Completed') {
                    $api_admin->invoice_transaction_update(['id' => $id, 'txn_status' => 'Completed']);
                    $tx['txn_status'] = 'Completed';
                }

                if ($this->isIpnDuplicate($ipn)) {
                    throw new Payment_Exception('Cannot process duplicate IPN');
                }

                $invoiceService = $this->di['mod_service']('Invoice');
                $invoiceDbModel = null;
                if (!empty($tx['invoice_id'])) {
                    $invoiceDbModel = $this->di['db']->load('Invoice', $tx['invoice_id']);
                }

                // For subscription renewals, generate a renewal invoice so
                // we validate against the correct amount. Skip this for the
                // initial payment (original invoice still unpaid) — that
                // payment should go to the original invoice.
                // Echoed references describe the original invoice, so check
                // them before the renewal reassignment below changes it.
                if ($invoiceDbModel instanceof Model_Invoice) {
                    $this->verifyIpnEcho($ipn, $invoiceDbModel);
                }

                if ($ipn['txn_type'] === 'subscr_payment' && isset($ipn['subscr_id'])) {
                    $originalAlreadyPaid = $invoiceDbModel instanceof Model_Invoice
                        && $invoiceDbModel->status === Model_Invoice::STATUS_PAID;

                    if ($originalAlreadyPaid) {
                        $renewalInvoice = $invoiceService->generateRenewalInvoiceForSubscriptionPayment($ipn['subscr_id'], $client_id);
                        if ($renewalInvoice instanceof Model_Invoice) {
                            $api_admin->invoice_transaction_update(['id' => $id, 'invoice_id' => $renewalInvoice->id]);
                            $tx['invoice_id'] = $renewalInvoice->id;
                            $invoiceDbModel = $renewalInvoice;
                        }
                    }
                }

                if ($invoiceDbModel instanceof Model_Invoice) {
                    $expected = $invoiceService->getBalanceDue($invoiceDbModel);
                    $invoiceService->validatePaymentAmount((float) $ipn['mc_gross'], $expected, $invoiceDbModel);
                }

                $this->applyPayment($api_admin, (int) $client_id, $ipn['mc_gross'], (string) $ipn['txn_id'], empty($tx['invoice_id']) ? null : $invoiceDbModel);

                break;

            case 'subscr_signup':
                $this->validateCurrency($ipn['mc_currency'] ?? null, $invoice['currency'] ?? null);

                $existingSubscription = $this->di['db']->findOne('Subscription', 'sid = :sid', [':sid' => $ipn['subscr_id']]);

                if (!$existingSubscription instanceof Model_Subscription) {
                    $sd = [
                        'client_id' => $client_id,
                        'gateway_id' => $gateway_id,
                        'currency' => $ipn['mc_currency'] ?? '',
                        'sid' => $ipn['subscr_id'],
                        'status' => 'active',
                        'period' => str_replace(' ', '', $ipn['period3']),
                        'amount' => $ipn['amount3'],
                        'rel_type' => 'invoice',
                        'rel_id' => $invoice['id'],
                    ];
                    $api_admin->invoice_subscription_create($sd);
                }

                $t = [
                    'id' => $id,
                    's_id' => $ipn['subscr_id'],
                    's_period' => str_replace(' ', '', $ipn['period3']),
                ];
                $api_admin->invoice_transaction_update($t);

                break;

            case 'subscr_failed':
                // ClientFence core patch: PayPal retries failed renewals before cancelling the subscription. As WHMCS, the
                // failure is logged, never counted as money received.
                $api_admin->invoice_transaction_update([
                    'id' => $id,
                    'status' => Model_Transaction::STATUS_ERROR,
                    'error' => 'PayPal subscription payment failed; PayPal retries it.',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

                return;

            case 'recurring_payment_suspended_due_to_max_failed_payment':
            case 'subscr_eot':
            case 'subscr_cancel':
                $s = $api_admin->invoice_subscription_get(['sid' => $ipn['subscr_id']]);
                $this->di['mod_service']('Invoice', 'Subscription')->updateStatusFromGateway((int) $s['id'], 'canceled');

                break;

            default:
                error_log('Unknown paypal transaction ' . $id);

                break;
        }

        $refundError = '';
        if (
            isset($ipn['payment_status'], $ipn['txn_type'])
            && $ipn['payment_status'] == 'Refunded'
            && in_array($ipn['txn_type'], ['web_accept', 'subscr_payment'], true)
            && !$this->refundedFromClientFence((string) ($ipn['txn_id'] ?? ''))
        ) {
            // ClientFence core patch: only a full refund of the parent payment refunds its own invoice.
            $payment = empty($ipn['parent_txn_id']) ? null : $this->di['db']->findOne(
                'Transaction',
                // ClientFence core patch: pending PayPal rows can still point to the subscription's original invoice.
                'txn_id = :txn AND gateway_id = :gateway AND status = :status AND txn_status = :txn_status',
                [':txn' => $ipn['parent_txn_id'], ':gateway' => $gateway_id, ':status' => Model_Transaction::STATUS_PROCESSED, ':txn_status' => 'Completed']
            );
            $amount = abs((float) $ipn['mc_gross']);
            if (!$payment instanceof Model_Transaction) {
                $refundError = sprintf('PayPal refund of %s %s must be settled by hand: original payment not found.', number_format($amount, 2, '.', ''), $ipn['mc_currency']);
            } elseif ($amount < (float) $payment->amount - 0.01 || $amount > (float) $payment->amount + 0.01) {
                $refundError = sprintf('Partial PayPal refund of %s %s must be settled by hand.', number_format($amount, 2, '.', ''), $ipn['mc_currency']);
            } else {
                // ClientFence core patch: account credit can settle an invoice before its PayPal payment arrives.
                $paymentInvoice = $this->di['db']->getExistingModelById('Invoice', $payment->invoice_id);
                if (($paymentInvoice->paid_at && $paymentInvoice->paid_at < $payment->created_at) || $this->di['db']->findOne(
                    'Transaction',
                    'invoice_id = :invoice AND id != :payment AND status = :status AND (gateway_id != :gateway OR txn_status = :txn_status)',
                    [':invoice' => $payment->invoice_id, ':payment' => $payment->id, ':status' => Model_Transaction::STATUS_PROCESSED, ':gateway' => $gateway_id, ':txn_status' => 'Completed']
                ) instanceof Model_Transaction) {
                    // ClientFence core patch: refunding duplicate payment credit must not refund the settled invoice.
                    $refundError = sprintf('PayPal refund of %s %s must be settled by hand: invoice has multiple payments.', number_format($amount, 2, '.', ''), $ipn['mc_currency']);
                } elseif ((float) $payment->amount > $this->di['mod_service']('Invoice')->getTotalWithTax($paymentInvoice) + 0.01) {
                    // ClientFence core patch: an overpayment refund must also reverse its excess balance credit.
                    $refundError = sprintf('PayPal refund of %s %s must be settled by hand: the payment was larger than the invoice.', number_format($amount, 2, '.', ''), $ipn['mc_currency']);
                } elseif ((float) $payment->amount < $this->di['mod_service']('Invoice')->getTotalWithTax($paymentInvoice) - 0.01) {
                    // ClientFence core patch: account credit paid the rest of the invoice, so a full refund would refund more than PayPal returned.
                    $refundError = sprintf('PayPal refund of %s %s must be settled by hand: credit paid the rest of the invoice.', number_format($amount, 2, '.', ''), $ipn['mc_currency']);
                } else {
                    $api_admin->invoice_refund([
                        'id' => $payment->invoice_id,
                        'note' => 'PayPal refund ' . $ipn['parent_txn_id'],
                    ]);
                }
            }
        }

        $d = [
            'id' => $id,
            'error' => $refundError,
            'error_code' => null,
            'status' => Model_Transaction::STATUS_PROCESSED,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $api_admin->invoice_transaction_update($d);
    }

    /** ClientFence core patch: a received PayPal payment goes to the client's credit, then pays the invoice (or their open invoices). */
    private function applyPayment($api_admin, int $clientId, $amount, string $txnId, ?Model_Invoice $invoice): void
    {
        $api_admin->client_balance_add_funds([
            'id' => $clientId,
            'amount' => $amount,
            'description' => 'PayPal transaction ' . $txnId,
            'type' => 'PayPal',
            'rel_id' => $txnId,
        ]);
        $invoiceService = $this->di['mod_service']('Invoice');
        if ($invoice instanceof Model_Invoice && !$invoiceService->isInvoiceTypeDeposit($invoice)) {
            if (!$invoice->approved) {
                $invoiceService->approveInvoice($invoice, ['use_credits' => false]);
            }
            $api_admin->invoice_pay_with_credits(['id' => $invoice->id]);
        } elseif ($invoice instanceof Model_Invoice) {
            $invoiceService->markAsPaid($invoice);
        } else {
            $api_admin->invoice_batch_pay_with_credits(['client_id' => $clientId]);
        }
    }

    /**
     * Resolve the invoice id from callback data, authenticating the binding.
     * Used by flows that read the invoice from the IPN before processing it.
     */
    public function getInvoiceId($data): ?int
    {
        $invoiceId = $data['invoice_id'] ?? $data['get']['invoice_id'] ?? null;
        if (empty($invoiceId)) {
            return null;
        }

        $gatewayId = $this->config['gateway_id'] ?? null;
        if ($gatewayId === null || $gatewayId === '') {
            return (int) $invoiceId;
        }

        return $this->verifyCallbackBinding($data, (int) $gatewayId, $invoiceId);
    }

    /**
     * Authenticate the gateway/invoice binding carried by the callback URL.
     * Returns the verified invoice id, or null when no invoice was supplied.
     *
     * Unsigned callbacks predate signing and are honored only for a stored
     * subscription on the same invoice, or for a notification continuing a
     * previously recorded payment for the same invoice (delayed completions
     * and refunds for pre-upgrade payments), so existing activity keeps
     * working: a fresh payment has no earlier transaction row to match, and
     * a tampered invoice id never matches the stored row.
     */
    private function verifyCallbackBinding(array $data, int $gatewayId, mixed $boundInvoiceId, ?int $currentTxId = null): ?int
    {
        $get = $data['get'] ?? [];
        $invoiceId = $boundInvoiceId ?: ($get['invoice_id'] ?? null);
        if (empty($invoiceId)) {
            return null;
        }
        $invoiceId = (int) $invoiceId;

        if (ClientFence\Tools::verifyCallbackSignature($gatewayId, $invoiceId, $get['sig'] ?? null)) {
            return $invoiceId;
        }

        $post = $data['post'] ?? [];
        $subscrId = (string) ($post['subscr_id'] ?? $post['recurring_payment_id'] ?? '');
        if ($subscrId !== '') {
            $storedSubscription = $this->di['db']->findOne('Subscription', 'sid = :sid', [':sid' => $subscrId]);
            if ($storedSubscription && ($storedSubscription->rel_type ?? null) === 'invoice' && (int) ($storedSubscription->rel_id ?? 0) === $invoiceId) {
                $this->di['logger']->info('Accepted unsigned PayPal callback for stored subscription ' . $subscrId . ' on invoice ' . $invoiceId);

                return $invoiceId;
            }
        }

        // A verified IPN names PayPal-grounded transaction ids, so an
        // earlier transaction row for the same gateway payment on the same
        // invoice proves this notification continues pre-upgrade activity
        // rather than starting a fresh (unsigned) payment.
        if ($currentTxId !== null) {
            foreach ([$post['txn_id'] ?? null, $post['parent_txn_id'] ?? null] as $candidateTxnId) {
                if (!is_string($candidateTxnId) || $candidateTxnId === '') {
                    continue;
                }
                $earlier = $this->di['db']->findOne(
                    'Transaction',
                    'txn_id = :txn AND gateway_id = :gateway AND id != :id AND status IN (:received, :processing, :processed)',
                    [
                        ':txn' => $candidateTxnId,
                        ':gateway' => $gatewayId,
                        ':id' => $currentTxId,
                        ':received' => Model_Transaction::STATUS_RECEIVED,
                        ':processing' => Model_Transaction::STATUS_PROCESSING,
                        ':processed' => Model_Transaction::STATUS_PROCESSED,
                    ]
                );
                if ($earlier && (int) ($earlier->invoice_id ?? 0) === $invoiceId) {
                    $this->di['logger']->info('Accepted unsigned PayPal callback continuing transaction ' . $candidateTxnId . ' on invoice ' . $invoiceId);

                    return $invoiceId;
                }
            }
        }

        throw new Payment_Exception('PayPal callback signature is invalid');
    }

    /**
     * Cross-check PayPal's echoed invoice references against the invoice
     * about to be credited (defense in depth behind the callback signature).
     * Only enforced when PayPal echoes the field; several notification
     * types omit these references, and that must never fail a payment.
     */
    private function verifyIpnEcho(array $ipn, Model_Invoice $invoice): void
    {
        $echoedNr = trim((string) ($ipn['item_number'] ?? ''));
        if ($echoedNr !== '') {
            $expectedNr = trim((string) ($invoice->nr ?? ''));
            if ($expectedNr !== '' && $echoedNr !== $expectedNr) {
                // ClientFence core patch (0.8.9 port): PayPal can echo the number from before the invoice was issued.
                $this->di['logger']->warning('PayPal item_number differs from the current number for invoice ' . $invoice->id);
            }
        }

        $echoedInvoice = trim((string) ($ipn['invoice'] ?? ''));
        if ($echoedInvoice !== '' && (int) $echoedInvoice !== (int) $invoice->id) {
            throw new Payment_Exception('PayPal invoice reference does not match invoice ' . $invoice->id);
        }
    }

    /**
     * Append a binding signature to a callback URL. Skipped when the adapter
     * was built without a gateway id (never the case in production).
     */
    private function signNotifyUrl(string $url, string|int $invoiceId): string
    {
        $gatewayId = $this->config['gateway_id'] ?? null;
        if ($gatewayId === null || $gatewayId === '') {
            return $url;
        }

        $sig = ClientFence\Tools::signCallbackParams((int) $gatewayId, (int) $invoiceId);
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . 'sig=' . urlencode($sig);
    }

    private function validateCurrency($received, $expected): void
    {
        $received = trim((string) $received);
        $expected = trim((string) $expected);
        if ($received === '' || $expected === '') {
            throw new Payment_Exception('PayPal payment is missing currency details');
        }
        if (strcasecmp($received, $expected) !== 0) {
            throw new Payment_Exception(sprintf('PayPal payment currency %s does not match invoice currency %s', $received, $expected));
        }
    }

    private function serviceUrl(): string
    {
        if ($this->config['test_mode']) {
            return 'https://www.sandbox.paypal.com/cgi-bin/webscr';
        }

        return 'https://www.paypal.com/cgi-bin/webscr';
    }

    private function _isIpnValid($data): bool
    {
        // use http_raw_post_data instead of post due to encoding
        parse_str((string) $data['http_raw_post_data'], $post);
        $req = 'cmd=_notify-validate';
        foreach ($post as $key => $value) {
            // ClientFence core patch (0.8.9 port): IPN fields must be scalar before rebuilding the verification body.
            if (is_array($value)) {
                return false;
            }
            // No stripslashes() here: that undid PHP's long-removed magic_quotes_gpc
            // escaping. Applied unconditionally it corrupts any value containing a
            // backslash, so the re-posted request no longer matches what PayPal sent.
            $value = urlencode((string) $value);
            $req .= "&$key=$value";
        }

        if ($this->download($this->serviceUrl(), $req) !== 'VERIFIED') {
            return false;
        }

        // VERIFIED only proves PayPal originated the notification; verify the
        // payee so a payment to a different account cannot be replayed here.
        $configured = strtolower(trim((string) $this->config['email']));
        $payees = [
            strtolower(trim((string) ($post['receiver_email'] ?? ''))),
            strtolower(trim((string) ($post['business'] ?? ''))),
        ];

        foreach ($payees as $payee) {
            if ($payee !== '' && hash_equals($configured, $payee)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $url
     */
    private function download($url, array|string|null $post_vars = null): string
    {
        $post_contents = '';
        if ($post_vars !== null) {
            if (is_array($post_vars)) {
                foreach ($post_vars as $key => $val) {
                    $post_contents .= ($post_contents ? '&' : '') . urlencode((string) $key) . '=' . urlencode((string) $val);
                }
            } else {
                $post_contents = $post_vars;
            }
        }

        $httpClient = $this->di['http_client']->withOptions([
            'timeout' => 600,
        ]);
        $response = $httpClient->request('POST', $url, [
            'body' => $post_contents,
        ]);

        return $response->getContent();
    }

    /**
     * @param string $url
     */
    private function _generateForm($url, $data, $method = 'post'): string
    {
        $form = '';
        $safeUrl = htmlspecialchars((string) $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeMethod = htmlspecialchars((string) $method, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $form .= '<form name="payment_form" action="' . $safeUrl . '" method="' . $safeMethod . '">' . PHP_EOL;
        foreach ($data as $key => $value) {
            $safeKey = htmlspecialchars((string) $key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeValue = htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $form .= sprintf('<input type="hidden" name="%s" value="%s" />', $safeKey, $safeValue) . PHP_EOL;
        }
        $form .= '<input class="btn btn-primary" type="submit" value="Pay with PayPal" id="payment_button"/>' . PHP_EOL;
        $form .= '</form>' . PHP_EOL . PHP_EOL;

        if (isset($this->config['auto_redirect']) && $this->config['auto_redirect']) {
            $form .= sprintf('<h2>%s</h2>', __trans('Redirecting to PayPal.com'));
            $form .= "<script nonce=\"" . \ClientFence\Csp::nonce() . "\">
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('payment_button').style.display = 'none';
    document.forms['payment_form'].submit();
});
</script>";
        }

        return $form;
    }

    public function isIpnDuplicate(array $ipn): bool
    {
        $sql = 'SELECT id
                FROM transaction
                WHERE txn_id = :transaction_id
                  AND txn_status = :transaction_status
                  AND type = :transaction_type
                  AND amount = :transaction_amount
                LIMIT 2';

        $bindings = [
            ':transaction_id' => $ipn['txn_id'] ?? null,
            ':transaction_status' => $ipn['payment_status'] ?? null,
            ':transaction_type' => $ipn['txn_type'] ?? null,
            ':transaction_amount' => $ipn['mc_gross'] ?? null,
        ];

        $rows = $this->di['db']->getAll($sql, $bindings);
        if (ClientFence\Tools::safeCount($rows) > 1) {
            return true;
        }

        return false;
    }

    public function getInvoiceTitle(array $invoice): string
    {
        $p = [
            ':id' => sprintf('%05d', (int) $invoice['nr']),
            ':serie' => $invoice['serie'],
            ':title' => $invoice['lines'][0]['title'],
        ];

        return __trans('Payment for invoice :serie:id [:title]', $p);
    }

    public function getSubscriptionFields(array $invoice): array
    {
        $data = [];
        $subs = $invoice['subscription'];

        $data['item_name'] = $this->getInvoiceTitle($invoice);
        $data['item_number'] = $invoice['nr'];
        $data['no_shipping'] = '1';
        $data['no_note'] = '1'; // Do not prompt payers to include a note with their payments. Allowable values for Subscribe buttons:
        $data['currency_code'] = $invoice['currency'];
        $data['return'] = $this->config['thankyou_url'];
        $data['cancel_return'] = $this->config['cancel_url'];
        $data['notify_url'] = $this->signNotifyUrl($this->config['notify_url'], $invoice['id']);
        $data['business'] = $this->config['email'];

        $data['cmd'] = '_xclick-subscriptions';
        $data['rm'] = '1';

        $data['invoice'] = $invoice['id'];

        // Recurrence info
        $data['a3'] = $this->moneyFormat($invoice['total'], $invoice['currency']); // Regular subscription price.
        $data['p3'] = $subs['cycle']; // Subscription duration. Specify an integer value in the allowable range for the units of duration that you specify with t3.

        /*
         * t3: Regular subscription units of duration. Allowable values:
         *  D – for days; allowable range for p3 is 1 to 90
         *  W – for weeks; allowable range for p3 is 1 to 52
         *  M – for months; allowable range for p3 is 1 to 24
         *  Y – for years; allowable range for p3 is 1 to 5
         */
        $data['t3'] = $subs['unit'];

        $data['src'] = 1; // Recurring payments. Subscription payments recur unless subscribers cancel their subscriptions before the end of the current billing cycle or you limit the number of times that payments recur with the value that you specify for srt.
        $data['sra'] = 1; // Reattempt on failure. If a recurring payment fails, PayPal attempts to collect the payment two more times before canceling the subscription.
        $data['charset'] = 'UTF-8'; // Sets the character encoding for the billing information/log-in page, for the information you send to PayPal in your HTML button code, and for the information that PayPal returns to you as a result of checkout processes initiated by the payment button. The default is based on the character encoding settings in your account profile.

        // client data
        $buyer = $invoice['buyer'];
        $data['address1'] = $buyer['address'];
        $data['city'] = $buyer['city'];
        $data['email'] = $buyer['email'];
        $data['first_name'] = $buyer['first_name'];
        $data['last_name'] = $buyer['last_name'];
        $data['zip'] = $buyer['zip'];
        $data['state'] = $buyer['state'];
        $data['bn'] = 'ClientFence_SP';

        return $data;
    }

    public function getOneTimePaymentFields(array $invoice): array
    {
        $data = [];
        $data['item_name'] = $this->getInvoiceTitle($invoice);
        $data['item_number'] = $invoice['nr'];
        $data['no_shipping'] = '1';
        $data['no_note'] = '1';
        $data['currency_code'] = $invoice['currency'];
        $data['rm'] = '1';
        $data['return'] = $this->config['thankyou_url'];
        $data['cancel_return'] = $this->config['cancel_url'];
        $data['notify_url'] = $this->signNotifyUrl($this->config['notify_url'], $invoice['id']);
        $data['business'] = $this->config['email'];
        $data['cmd'] = '_xclick';
        $data['amount'] = $this->moneyFormat($invoice['subtotal'], $invoice['currency']);
        $data['tax'] = $this->moneyFormat($invoice['tax'], $invoice['currency']);
        // ClientFence core patch: a part-paid invoice asks for its balance due.
        if (($invoice['amount_paid'] ?? 0) > 0 && $invoice['status'] === Model_Invoice::STATUS_UNPAID) {
            $data['amount'] = $this->moneyFormat($invoice['balance_due'], $invoice['currency']);
            $data['tax'] = $this->moneyFormat(0, $invoice['currency']);
        }
        $data['bn'] = 'ClientFence_SP';
        $data['charset'] = 'utf-8';

        return $data;
    }

    // ClientFence core patch: the REST API part of PayPal Email (optional keys of a PayPal REST app). It refunds
    // payments and cancels subscriptions, also those made through PayPal Standard (their transaction and profile IDs
    // are REST capture and subscription IDs).

    /** Whether the REST API keys of the current mode (live or sandbox) are set: refunds and subscription cancels need them. */
    public function hasApiKeys(): bool
    {
        return $this->restKeys() !== null;
    }

    /** Checks the REST API keys when they are set (on saving the gateway). */
    public function testConnection(): void
    {
        if ($this->restKeys() !== null) {
            $this->restToken();
        }
    }

    public function cancelSubscription(string $subscriptionId): void
    {
        if ($this->restKeys() === null) {
            throw new ClientFence\InformationException('Cancel subscription :id in PayPal: ClientFence marks it canceled when PayPal reports it.', [':id' => $subscriptionId]);
        }
        [$status, $body] = $this->rest('POST', '/v1/billing/subscriptions/' . rawurlencode($subscriptionId) . '/cancel', ['reason' => 'Cancelled in ClientFence']);
        // Already cancelled or ended at PayPal (by the client, or by an earlier try) is what was asked.
        if ($status === 422 && in_array($this->rest('GET', '/v1/billing/subscriptions/' . rawurlencode($subscriptionId))[1]['status'] ?? '', ['CANCELLED', 'EXPIRED'], true)) {
            return;
        }
        if ($status !== 204) {
            throw new ClientFence\InformationException('PayPal did not cancel subscription :id: :error', [':id' => $subscriptionId, ':error' => $this->restError($body, $status)]);
        }
    }

    /** Whether PayPal finds the subscription and it can still be cancelled (a check before a plan change relies on it). */
    public function canCancelSubscription(string $subscriptionId): bool
    {
        try {
            [$status, $body] = $this->rest('GET', '/v1/billing/subscriptions/' . rawurlencode($subscriptionId));
        } catch (\Throwable) {
            return false;
        }

        return $status === 200 && in_array($body['status'] ?? '', ['APPROVAL_PENDING', 'APPROVED', 'ACTIVE', 'SUSPENDED'], true);
    }

    /** PayPal charges a subscription again only when its period ends, so cancelling it now ends it there. */
    public function cancelSubscriptionAtPeriodEnd(string $subscriptionId): void
    {
        $this->cancelSubscription($subscriptionId);
    }

    /** Refunds $amount of a PayPal payment (its transaction ID) and returns PayPal's refund ID. */
    public function refundPayment(string $transactionId, float $amount, string $currency): string
    {
        [$status, $body] = $this->rest('POST', '/v2/payments/captures/' . rawurlencode($transactionId) . '/refund', [
            'amount' => ['value' => $this->moneyFormat($amount, $currency), 'currency_code' => $currency],
        ]);
        if ($status !== 201 || empty($body['id'])) {
            throw new ClientFence\InformationException('PayPal did not refund payment :id: :error', [':id' => $transactionId, ':error' => $this->restError($body, $status)]);
        }

        return (string) $body['id'];
    }

    /** ClientFence core patch: a refund staff sent from ClientFence (refundPayment()) has its credit note already. */
    private function refundedFromClientFence(string $refundId): bool
    {
        return preg_match('/^[A-Z0-9]{8,32}$/i', $refundId) === 1
            && (bool) $this->di['db']->getCell('SELECT 1 FROM invoice WHERE notes LIKE ? LIMIT 1', ['%PayPal refund ' . $refundId . ' %']);
    }

    /** [client ID, secret] of the current mode, or null. */
    private function restKeys(): ?array
    {
        $prefix = empty($this->config['test_mode']) ? '' : 'test_';
        $id = trim((string) ($this->config[$prefix . 'api_client_id'] ?? ''));
        $secret = trim((string) ($this->config[$prefix . 'api_secret'] ?? ''));

        return $id !== '' && $secret !== '' ? [$id, $secret] : null;
    }

    private function restUrl(string $path): string
    {
        return (empty($this->config['test_mode']) ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com') . $path;
    }

    private function restToken(): string
    {
        if ($this->restToken === null) {
            [$id, $secret] = $this->restKeys() ?? throw new ClientFence\InformationException('Enter the PayPal REST API client ID and secret first.');
            $response = $this->di['http_client']->request('POST', $this->restUrl('/v1/oauth2/token'), [
                'auth_basic' => [$id, $secret],
                'body' => ['grant_type' => 'client_credentials'],
                'timeout' => 30,
            ]);
            $body = $response->toArray(false);
            if ($response->getStatusCode() !== 200 || empty($body['access_token'])) {
                throw new ClientFence\InformationException('PayPal did not accept the REST API keys: :error', [':error' => (string) ($body['error_description'] ?? $response->getStatusCode())]);
            }
            $this->restToken = (string) $body['access_token'];
        }

        return $this->restToken;
    }

    /** [HTTP status, decoded body] of a REST call; network errors throw. */
    private function rest(string $method, string $path, ?array $json = null, array $headers = []): array
    {
        // PayPal refuses a POST without a JSON content type, also one without a body (an order capture).
        $options = ['auth_bearer' => $this->restToken(), 'timeout' => 60, 'headers' => $headers + ['Content-Type' => 'application/json']];
        if ($json !== null) {
            $options['json'] = $json;
        }
        $response = $this->di['http_client']->request($method, $this->restUrl($path), $options);
        $status = $response->getStatusCode();

        return [$status, $status === 204 ? [] : $response->toArray(false)];
    }

    private function restError(array $body, int $status): string
    {
        $detail = $body['details'][0]['description'] ?? $body['details'][0]['issue'] ?? null;

        return trim(($body['message'] ?? $body['name'] ?? 'HTTP ' . $status) . ($detail ? ' (' . $detail . ')' : ''));
    }

    // ClientFence core patch: saved PayPal accounts (Invoice/SavedCards.php) through the Vault of the REST API. A client
    // saves their account on the Payment methods page (a setup token they approve at PayPal) or with "Pay and save my
    // PayPal account" (an order stored in the vault when it is paid); the cron then charges it as a saved card.

    /** Whether clients can save their PayPal account here: the setting is on and the REST API keys are set. */
    /** ClientFence core patch: the PayPal account's balances through the REST API: [{currency, amount}]. */
    public function balances(): array
    {
        if (!$this->hasApiKeys()) {
            throw new ClientFence\InformationException('Add the REST API keys to PayPal to see its balance.');
        }
        [$status, $body] = $this->rest('GET', '/v1/reporting/balances');
        if ($status !== 200) {
            throw new ClientFence\InformationException('PayPal did not give the balance: :error', [':error' => $this->restError($body, $status)]);
        }

        return array_map(static fn (array $balance): array => [
            'currency' => (string) ($balance['currency'] ?? ''),
            'amount' => (float) ($balance['total_balance']['value'] ?? 0),
        ], $body['balances'] ?? []);
    }

    public function canVault(): bool
    {
        return (string) ($this->config['vault'] ?? '') === '1' && $this->hasApiKeys();
    }

    public function gatewayId(): int
    {
        return (int) ($this->config['gateway_id'] ?? 0);
    }

    public function testMode(): bool
    {
        return !empty($this->config['test_mode']);
    }

    /** The client's saved PayPal account ({payment_method, card, auto_pay_at}), or null. */
    public function savedCard(int $clientId): ?array
    {
        return ClientFence\Mod\Invoice\SavedCards::saved($this->di, $this->gatewayId(), $this->testMode(), $clientId);
    }

    /** Starts saving a PayPal account on the Payment methods page: {id, url}, the setup token and its approval page. */
    public function vaultSetup(string $returnUrl, string $cancelUrl): array
    {
        [$status, $body] = $this->rest('POST', '/v3/vault/setup-tokens', ['payment_source' => ['paypal' => [
            'usage_type' => 'MERCHANT',
            'experience_context' => ['return_url' => $returnUrl, 'cancel_url' => $cancelUrl, 'shipping_preference' => 'NO_SHIPPING'],
        ]]]);
        $url = $this->restLink($body, 'approve');
        if (!in_array($status, [200, 201], true) || empty($body['id']) || $url === null) {
            throw new ClientFence\InformationException('PayPal did not start saving your account: :error', [':error' => $this->restError($body, $status)]);
        }

        return ['id' => (string) $body['id'], 'url' => $url];
    }

    /** After the client approved a setup token at PayPal: keeps that account as the one that pays their invoices. */
    public function vaultSaved(int $clientId, string $setupTokenId): void
    {
        [$status, $body] = $this->rest('POST', '/v3/vault/payment-tokens', ['payment_source' => ['token' => ['id' => $setupTokenId, 'type' => 'SETUP_TOKEN']]]);
        if (!in_array($status, [200, 201], true) || empty($body['id'])) {
            throw new ClientFence\InformationException('PayPal did not save your account: :error', [':error' => $this->restError($body, $status)]);
        }
        $this->rememberAccount($clientId, (string) $body['id'], (string) ($body['customer']['id'] ?? ''), (string) ($body['payment_source']['paypal']['email_address'] ?? ''));
    }

    /** Removes the client's saved PayPal account, here and at PayPal. */
    public function vaultRemove(int $clientId): void
    {
        $saved = $this->savedCard($clientId);
        if ($saved !== null) {
            $this->deleteToken($saved['payment_method']);
            $this->di['dbal']->executeStatement(
                'UPDATE pay_gateway_customer SET default_payment_method = NULL, default_card = NULL WHERE pay_gateway_id = ? AND client_id = ? AND test_mode = ?',
                [$this->gatewayId(), $clientId, (int) $this->testMode()]
            );
        }
    }

    /** "Pay and save my PayPal account": an order for the invoice's balance, stored in the vault when paid: {id, url}. */
    public function vaultOrder(Model_Invoice $invoice, string $returnUrl, string $cancelUrl): array
    {
        [$status, $body] = $this->rest('POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [$this->purchaseUnit($invoice)],
            'payment_source' => ['paypal' => [
                'attributes' => ['vault' => ['store_in_vault' => 'ON_SUCCESS', 'usage_type' => 'MERCHANT']],
                'experience_context' => ['return_url' => $returnUrl, 'cancel_url' => $cancelUrl, 'shipping_preference' => 'NO_SHIPPING', 'user_action' => 'PAY_NOW'],
            ]],
        ]);
        $url = $this->restLink($body, 'payer-action');
        if (!in_array($status, [200, 201], true) || empty($body['id']) || $url === null) {
            throw new ClientFence\InformationException('PayPal did not start the payment: :error', [':error' => $this->restError($body, $status)]);
        }

        return ['id' => (string) $body['id'], 'url' => $url];
    }

    /** Takes the payment of an approved "Pay and save" order and keeps its PayPal account: the capture ID. */
    public function vaultCapture(Model_Invoice $invoice, string $orderId): string
    {
        $path = '/v2/checkout/orders/' . rawurlencode($orderId);
        // The full reply: the short one leaves out the purchase unit's custom_id.
        [$status, $body] = $this->rest('POST', $path . '/capture', null, ['PayPal-Request-Id' => 'cf-capture-' . $orderId, 'Prefer' => 'return=representation']);
        // Captured already (the client came back twice): the order says how.
        if ($status === 422) {
            [$status, $body] = $this->rest('GET', $path);
        }
        $unit = $body['purchase_units'][0] ?? [];
        $capture = $unit['payments']['captures'][0] ?? [];
        if (!in_array($status, [200, 201], true) || empty($capture['id']) || (string) ($unit['custom_id'] ?? '') !== 'cf-invoice-' . $invoice->id) {
            throw new ClientFence\InformationException('PayPal did not take the payment: :error', [':error' => $this->restError($body, $status)]);
        }
        $vault = $body['payment_source']['paypal']['attributes']['vault'] ?? [];
        if (!empty($vault['id'])) {
            $this->rememberAccount((int) $invoice->client_id, (string) $vault['id'], (string) ($vault['customer']['id'] ?? ''), (string) ($body['payment_source']['paypal']['email_address'] ?? ''));
        }

        return (string) $capture['id'];
    }

    /**
     * Charges the client's saved PayPal account for an invoice's balance (the cron or staff). $attempt names the try,
     * so each one is its own idempotent payment. Returns the capture ID; PayPal's refusal throws InformationException.
     */
    public function chargeSavedCard(Model_Invoice $invoice, string $attempt): string
    {
        $saved = $this->savedCard((int) $invoice->client_id) ?? throw new ClientFence\InformationException('The client has no saved PayPal account.');
        [$status, $body] = $this->rest('POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [$this->purchaseUnit($invoice)],
            'payment_source' => ['paypal' => ['vault_id' => $saved['payment_method']]],
        ], ['PayPal-Request-Id' => 'cf-invoice-' . $invoice->id . '-' . $attempt]);
        $capture = $body['purchase_units'][0]['payments']['captures'][0] ?? [];
        if (in_array($status, [200, 201], true) && in_array($capture['status'] ?? '', ['DECLINED', 'FAILED'], true)) {
            throw new ClientFence\InformationException('PayPal refused the payment: :error', [':error' => $capture['status']]);
        }
        if (in_array($status, [200, 201], true) && !empty($capture['id'])) {
            return (string) $capture['id'];
        }
        if ($status >= 400 && $status < 500) {
            throw new ClientFence\InformationException('PayPal refused the payment: :error', [':error' => $this->restError($body, $status)]);
        }

        throw new Payment_Exception('PayPal answered HTTP :status to the payment of invoice #:id', [':status' => $status, ':id' => $invoice->id]);
    }

    /** A REST payment (capture) as PayPal reports it: recorded on its transaction and applied to its invoice once. */
    private function processRestCapture($api_admin, int $id, string $captureId): void
    {
        [$status, $capture] = $this->rest('GET', '/v2/payments/captures/' . rawurlencode($captureId));
        if ($status !== 200 || empty($capture['id'])) {
            throw new Payment_Exception('PayPal payment :id was not found', [':id' => $captureId]);
        }
        // Only orders made here name an invoice: a payment of another shop on the same PayPal account pays nothing.
        if (preg_match('/^cf-invoice-(\d+)$/', (string) ($capture['custom_id'] ?? ''), $match) !== 1) {
            throw new Payment_Exception('PayPal payment :id is not for a ClientFence invoice', [':id' => $captureId]);
        }
        $invoice = $this->di['db']->getExistingModelById('Invoice', (int) $match[1], 'Invoice not found');
        $amount = (float) ($capture['amount']['value'] ?? 0);
        $currency = (string) ($capture['amount']['currency_code'] ?? '');
        $completed = ($capture['status'] ?? '') === 'COMPLETED';
        $api_admin->invoice_transaction_update([
            'id' => $id, 'invoice_id' => $invoice->id, 'txn_id' => (string) $capture['id'], 'type' => 'paypal_rest',
            'txn_status' => $completed ? 'Completed' : ucfirst(strtolower((string) ($capture['status'] ?? ''))), 'amount' => $amount, 'currency' => $currency,
        ]);
        if (!$completed) {
            $api_admin->invoice_transaction_update(['id' => $id, 'status' => Model_Transaction::STATUS_RECEIVED, 'error' => sprintf('PayPal payment not completed: %s', (string) ($capture['status'] ?? 'unknown')), 'updated_at' => date('Y-m-d H:i:s')]);

            return;
        }
        // This transaction too: "Process Again" on a payment already applied must not credit it again.
        $done = $this->di['db']->findOne('Transaction', 'txn_id = :txn AND gateway_id = :gateway AND status = :status', [':txn' => $capture['id'], ':gateway' => $this->gatewayId(), ':status' => Model_Transaction::STATUS_PROCESSED]);
        if ($done instanceof Model_Transaction) {
            throw new Payment_Exception('Cannot process duplicate PayPal payment');
        }
        $this->validateCurrency($currency, $invoice->currency);
        $invoiceService = $this->di['mod_service']('Invoice');
        $invoiceService->validatePaymentAmount($amount, $invoiceService->getBalanceDue($invoice), $invoice);
        $this->applyPayment($api_admin, (int) $invoice->client_id, $amount, (string) $capture['id'], $invoice);
        $api_admin->invoice_transaction_update(['id' => $id, 'error' => '', 'error_code' => null, 'status' => Model_Transaction::STATUS_PROCESSED, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /** The invoice's balance as an order's purchase unit; custom_id names the invoice (processRestCapture()). */
    private function purchaseUnit(Model_Invoice $invoice): array
    {
        $invoiceService = $this->di['mod_service']('Invoice');

        return [
            'custom_id' => 'cf-invoice-' . $invoice->id,
            'description' => mb_substr($this->getInvoiceTitle($invoiceService->toApiArray($invoice)), 0, 127),
            'amount' => ['currency_code' => (string) $invoice->currency, 'value' => $this->moneyFormat($invoiceService->getBalanceDue($invoice), (string) $invoice->currency)],
        ];
    }

    /** Keeps a vaulted PayPal account as the client's (the newest replaces the one saved before). */
    private function rememberAccount(int $clientId, string $tokenId, string $customerId, string $email): void
    {
        $old = $this->savedCard($clientId)['payment_method'] ?? null;
        ClientFence\Mod\Invoice\SavedCards::remember($this->di, $this->gatewayId(), $this->testMode(), $clientId, $customerId, $tokenId, trim('PayPal ' . $email));
        if ($old !== null && $old !== $tokenId) {
            $this->deleteToken($old);
        }
    }

    private function deleteToken(string $tokenId): void
    {
        try {
            $this->rest('DELETE', '/v3/vault/payment-tokens/' . rawurlencode($tokenId));
        } catch (Throwable $e) {
            $this->di['logger']->warning('PayPal: the saved account %s was not removed at PayPal: %s', $tokenId, $e->getMessage());
        }
    }

    private function restLink(array $body, string $rel): ?string
    {
        foreach ($body['links'] ?? [] as $link) {
            if (($link['rel'] ?? '') === $rel && is_string($link['href'] ?? null)) {
                return $link['href'];
            }
        }

        return null;
    }
}
