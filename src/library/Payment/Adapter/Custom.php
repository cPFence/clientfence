<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */
class Payment_Adapter_Custom
{
    protected ?Pimple\Container $di = null;
    private const string TRUSTED_SOURCE = 'admin';

    public function __construct(private $config)
    {
    }

    public function setDi(Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public static function getConfig(): array
    {
        return [
            'can_load_in_iframe' => true,
            'supports_one_time_payments' => true,
            'supports_subscriptions' => true,
            'description' => __trans('Custom payment gateway allows you to give instructions how can your client pay invoice. All system, client, order and invoice details can be printed. HTML code is supported.'),
            'logo' => [
                'logo' => 'custom.png',
                'height' => '50px',
                'width' => '50px',
            ],
            'form' => [
                'single' => [
                    'textarea', [
                        'label' => __trans('Enter Your Text for Single Payment Information'),
                    ],
                ],
                'recurrent' => [
                    'textarea', [
                        'label' => __trans('Enter Your Text for Subscription Information'),
                    ],
                ],
            ],
        ];
    }

    /**
     * Generate payment text.
     *
     * @return string - html form with auto submit javascript
     */
    public function getHtml(ClientFence\Api\Proxy $api_admin, int $invoice_id, bool $subscription): string
    {
        $invoiceModel = $this->di['db']->load('Invoice', $invoice_id);
        $invoiceService = $this->di['mod_service']('Invoice');
        $invoice = $invoiceService->toApiArray($invoiceModel, true);

        $tpl = $subscription ? ($this->config['recurrent'] ?? '"Custom" payment adapter is not fully configured.') : ($this->config['single'] ?? '"Custom" payment adapter is not fully configured.');
        $vars = [
            'invoice' => $invoice,
        ];
        $systemService = $this->di['mod_service']('System');

        return $systemService->renderAdapterTplString($tpl, $vars);
    }

    /**
     * Processes a transaction using a custom payment adapter.
     *
     * @param ClientFence\Api\Proxy $api_admin  the API admin object
     * @param int                   $id         the ID of the transaction to process
     * @param array                 $data       the data associated with the transaction
     * @param int                   $gateway_id the ID of the payment gateway to use
     *
     * @return bool returns true if the transaction was processed successfully, false otherwise
     */
    public function processTransaction(ClientFence\Api\Proxy $api_admin, int $id, array $data, int $gateway_id)
    {
        if (!$this->isIpnValid($data)) {
            throw new Payment_Exception('Custom payments must be approved by an administrator.');
        }

        // ClientFence core patch: the amount staff enter is credited as is (see below), so it must be in
        // the invoice currency: 3045 EGP on a USD invoice would credit 3045 USD.
        $currencyTx = $this->di['db']->getExistingModelById('Transaction', $id);
        $currencyInvoice = $this->di['db']->getExistingModelById('Invoice', $currencyTx->invoice_id);
        // ClientFence core patch: processing a processed transaction again credited the client again
        // (Process pressed twice, or a transaction id returned by the IPN-hash dedupe).
        if ($currencyTx->status === Model_Transaction::STATUS_PROCESSED) {
            throw new Payment_Exception(sprintf('Transaction %d is already processed.', $id));
        }
        // ClientFence core patch: without an amount the invoice total is credited, which is only right
        // while the invoice is unpaid; on a paid invoice it credited the total again.
        if ($currencyInvoice->status === Model_Invoice::STATUS_PAID && !(is_numeric($currencyTx->amount) && (float) $currencyTx->amount > 0)) {
            throw new Payment_Exception('The invoice is already paid. Enter the amount received to credit it to the client balance.');
        }
        if (trim((string) $currencyTx->currency) !== '' && strcasecmp(trim((string) $currencyTx->currency), (string) $currencyInvoice->currency) !== 0) {
            throw new Payment_Exception(sprintf('The transaction currency %s does not match the invoice currency %s. Enter the amount in %s.', $currencyTx->currency, $currencyInvoice->currency, $currencyInvoice->currency));
        }

        // ClientFence core patch: the amount goes to the client's credit, which is in the account's currency; an invoice
        // in another currency was credited 1:1 (EGP 100 became USD 100).
        $accountCurrency = (string) $this->di['db']->getCell('SELECT currency FROM client WHERE id = :id', ['id' => $currencyInvoice->client_id]);
        if ($accountCurrency !== '' && strcasecmp($accountCurrency, (string) $currencyInvoice->currency) !== 0) {
            throw new Payment_Exception(sprintf('The invoice is in %s but the client account is in %s. Record this payment by hand.', $currencyInvoice->currency, $accountCurrency));
        }

        try {
            // Get the transaction and invoice associated with the transaction
            $tx = $this->di['db']->getExistingModelById('Transaction', $id);
            $invoice = $this->di['db']->getExistingModelById('Invoice', $tx->invoice_id);

            // Load the payment gateway and client associated with the transaction
            $gateway = $this->di['db']->load('PayGateway', $tx->gateway_id);
            $clientService = $this->di['mod_service']('Client');
            $client = $clientService->get(['id' => $invoice->client_id]);

            // Calculate the total amount of the invoice
            $invoiceService = $this->di['mod_service']('Invoice');
            $invoiceTotal = $invoiceService->getTotalWithTax($invoice);

            // ClientFence core patch: credit the amount staff entered, not the invoice total (a $20
            // payment on a $60 invoice was recorded as $60 and paid it). The invoice is paid when the
            // amount covers it, or when the client's whole balance does (two partial payments); else
            // the amount waits on the balance. No amount means the invoice total, as before. The
            // gateway has no title column: its name is the label clients see in the balance history.
            $received = is_numeric($tx->amount) && (float) $tx->amount > 0 ? round((float) $tx->amount, 2) : $invoiceTotal;
            $tx_desc = $gateway->name . ' transaction No: ' . $tx->txn_id;
            $clientService->addFunds($client, $received, $tx_desc, []);
            // QA r7 X-01: an Add funds invoice is paid once its own transfers add up to it; credit never pays one, so two
            // partial transfers left it unpaid (and Mark as paid would credit it again). Only while it is unpaid: a refunded
            // one's transfers were paid back.
            $before = $invoiceService->isInvoiceTypeDeposit($invoice) && $invoice->status === Model_Invoice::STATUS_UNPAID ? (float) $this->di['db']->getCell(
                'SELECT COALESCE(SUM(amount), 0) FROM transaction WHERE invoice_id = :invoice AND status = :status AND id <> :id',
                ['invoice' => $invoice->id, 'status' => Model_Transaction::STATUS_PROCESSED, 'id' => $tx->id]
            ) : 0.0;
            // ClientFence core patch (QA r7 X-01-R2): transfers on settled invoices only add to the client's credit.
            if ($invoice->status === Model_Invoice::STATUS_UNPAID && (round($received, 2) >= round($invoiceTotal, 2) || round($before + $received, 2) >= round($invoiceTotal, 2))) {
                $invoiceService->markAsPaid($invoice, true, true);
            } elseif ($invoice->status === Model_Invoice::STATUS_UNPAID && !$invoiceService->isInvoiceTypeDeposit($invoice)) {
                $invoiceService->tryPayWithCredits($invoice);
            }

            // Update the transaction status and details
            $tx->status = Model_Transaction::STATUS_PROCESSED;
            $tx->amount = $received;
            $tx->note = $gateway->name . ' transaction No: ' . $tx->txn_id;
            $tx->currency = $invoice->currency;
            $tx->updated_at = date('Y-m-d H:i:s');

            // Store the updated transaction and use its return to indicate a success or failure.
            return $this->di['db']->store($tx);
        } catch (Exception) {
            return false;
        }
    }

    public function isIpnValid(array $data): bool
    {
        return ($data['source'] ?? null) === self::TRUSTED_SOURCE;
    }
}
