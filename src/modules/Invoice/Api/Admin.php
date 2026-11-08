<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

/**
 * Invoice management API.
 */

namespace ClientFence\Mod\Invoice\Api;

use ClientFence\Mod\Invoice\Receipts;
use ClientFence\Mod\Invoice\SavedCards;
use ClientFence\InformationException;
use ClientFence\PaginationOptions;
use ClientFence\Validation\Api\RequiredParams;
use Symfony\Component\HttpFoundation\Response;

class Admin extends \ClientFence\Api\AbstractApi
{
    /**
     * Returns paginated list of invoices.
     *
     * @optional bool $summary - return only fields needed by invoice list views, without expanding related records
     *
     * @return array
     */
    public function get_list($data)
    {
        $this->checkPermissions('invoice', 'view');

        $service = $this->getService();
        // ClientFence core patch: the cheaper count query getSearchQuery() gives.
        [$sql, $params, $countSql] = $service->getSearchQuery($data);
        $pager = $this->getDi()['pager']->getPaginatedResultSet($sql, $params, PaginationOptions::fromArray($data), $countSql);
        if (!empty($data['summary'])) {
            foreach ($pager['list'] as $key => $item) {
                $pager['list'][$key] = $service->toApiSummaryArray($item);
            }

            return $pager;
        }

        foreach ($pager['list'] as $key => $item) {
            $invoice = $this->getDi()['db']->getExistingModelById('Invoice', $item['id'], 'Invoice not found');
            $pager['list'][$key] = $service->toApiArray($invoice, true, $this->getIdentity());
        }

        return $pager;
    }

    /**
     * Get invoice details.
     *
     * @return array
     */
    #[RequiredParams(['id' => 'Invoice ID is missing'])]
    public function get($data)
    {
        $this->checkPermissions('invoice', 'view');

        $model = $this->_getInvoice($data);
        $invoice = $this->getService()->toApiArray($model, true, $this->getIdentity());
        // ClientFence core patch: the client's free credit, for Apply Credit on the invoice page.
        $invoice['client_credit'] = $model->client_id ? $this->getService()->getFreeCredit((int) $model->client_id) : 0.0;

        return $invoice;
    }

    /**
     * Sets invoice status to paid. This method differs from invoice update method
     * in a way that it sends notification to Events system, so emails are sent.
     * The gateway must be enabled; when the invoice has no gateway, gateway_id
     * is required and is saved on the invoice.
     *
     * @optional int $gateway_id - Payment gateway to associate with the invoice. Required when the invoice has no gateway.
     * @optional string $transactionId - Custom transaction ID to use when the selected gateway is Custom
     *
     * @return bool
     */
    #[RequiredParams(['id' => 'Invoice ID is missing'])]
    public function mark_as_paid($data)
    {
        $this->checkPermissions('invoice', 'manage_invoices');

        $invoice = $this->_getInvoice($data);

        // Marking a deposit invoice as paid credits the client balance, so it
        // requires the same permission as crediting the balance directly.
        if ($this->getService()->isInvoiceTypeDeposit($invoice)) {
            $this->checkPermissions('client', 'manage_balance');
        }

        return $this->getService()->markAsPaidByAdmin($invoice, $data);
    }

    /**
     * Prepare invoice for editing and updating.
     * Uses clients details, such as currency assigned to client.
     * If client currency is not defined, sets default currency for client.
     *
     * @optional bool $approve - set true to approve invoice after preparation. Defaults to false
     * @optional int $gateway_id - Selected payment gateway id
     * @optional array $items - list of invoice lines. One line is array of line parameters
     * @optional string $text_1 - text to be displayed before invoice items table
     * @optional string $text_2 - text to be displayed after invoice items table
     *
     * @return int $id - newly generated invoice ID
     */
    #[RequiredParams(['client_id' => 'Client ID is missing'])]
    public function prepare($data)
    {
        $this->checkPermissions('invoice', 'manage_invoices');

        $client = $this->getDi()['db']->getExistingModelById('Client', $data['client_id'], 'Client not found');

        $invoice = $this->getService()->prepareInvoice($client, $data);

        return $invoice->id;
    }

    /**
     * Approve invoice.
     *
     * @return bool
     */
    #[RequiredParams(['id' => 'Invoice ID is missing'])]
    public function approve($data)
    {
        $this->checkPermissions('invoice', 'manage_invoices');

        $model = $this->_getInvoice($data);

        return $this->getService()->approveInvoice($model, $data);
    }

    /**
     * Add refunds.
     *
     * @optional string $note - note for refund
     * @optional string $refund_to - gateway (send it back through the gateway that took the payment), credit (to the
     *                               client's credit) or manual (record it only); to_credit=1 is the same as credit
     *
     * @return bool
     */
    #[RequiredParams(['id' => 'Invoice ID is missing'])]
    public function refund($data)
    {
        $this->checkPermissions('invoice', 'manage_invoices');

        $model = $this->_getInvoice($data);
        $note = $data['note'] ?? null;
        $amount = isset($data['amount']) && $data['amount'] !== '' ? (float) $data['amount'] : null;
        $to = (string) ($data['refund_to'] ?? '');

        return $this->getService()->refundInvoice($model, $note, $amount, !empty($data['to_credit']) || $to === 'credit', $to === 'gateway');
    }

    /** ClientFence core patch: the gateway a refund of this invoice can be sent back through (PayPal with its REST API keys) and the most one payment has left to refund: {gateway, left}, or null. */
    #[RequiredParams(['id' => 'Invoice ID was not passed'])]
    public function refund_gateway($data): ?array
    {
        $this->checkPermissions('invoice', 'view');
        $payment = $this->getService()->gatewayRefundPayment($this->_getInvoice($data));

        return $payment === null ? null : ['gateway' => $payment['gateway'], 'left' => $payment['left']];
    }

    /**
     * Update invoice details.
     *
     * @optional string $paid_at - Invoice payment date (Y-m-d) or empty to remove
     * @optional string $due_at - Invoice due date (Y-m-d)or empty to remove
     * @optional string $created_at - Invoice issue date (Y-m-d) or empty to remove
     * @optional string $serie - Invoice serie
     * @optional string $nr - Invoice number
     * @optional string $status - Invoice status: paid|unpaid
     * @optional string $taxrate - Invoice tax rate
     * @optional string $taxname - Invoice tax name
     * @optional bool $approved - flag to set invoice as approved. Approved invoices are visible to clients
     * @optional string $notes - notes
     * @optional int $gateway_id - selected payment method - gateway id
     * @optional array $new_item - [title] [price]
     * @optional string $text_1 - Custom invoice text 1
     * @optional string $text_2 - Custom invoice text 2
     * @optional string $seller_company - Seller company name
     * @optional string $seller_company_vat - Seller company VAT number
     * @optional string $seller_company_number - Seller company number
     * @optional string $seller_address - Seller address
     * @optional string $seller_phone - Seller phone
     * @optional string $seller_email - Seller email
     * @optional string $buyer_first_name - Buyer first name
     * @optional string $buyer_last_name - Buyer last name
     * @optional string $buyer_company - Buyer company name
     * @optional string $buyer_company_vat - Buyer company VAT number
     * @optional string $buyer_company_number - Buyer company number
     * @optional string $buyer_address - Buyer address
     * @optional string $buyer_city - Buyer city
     * @optional string $buyer_state - Buyer state
     * @optional string $buyer_country - Buyer country
     * @optional string $buyer_zip - Buyer zip
     * @optional string $buyer_phone - Buyer phone
     * @optional string $buyer_email - Buyer email
     * @optional bool $approve - approve the invoice after saving the supplied changes
     *
     * @return bool
     */
    #[RequiredParams(['id' => 'Invoice ID is missing'])]
    public function update($data)
    {
        $this->checkPermissions('invoice', 'manage_invoices');

        $model = $this->_getInvoice($data);
        $result = $this->getService()->updateInvoice($model, $data);

        if ($result && !empty($data['approve'])) {
            return $this->getService()->approveInvoice($model, $data);
        }

        return $result;
    }

    /**
     * Remove one line from invoice.
     *
     * @return bool
     */
    #[RequiredParams(['id' => 'Invoice item ID was not passed'])]
    public function item_delete($data)
    {
        $this->checkPermissions('invoice', 'manage_invoices');

        $model = $this->getDi()['db']->getExistingModelById('InvoiceItem', $data['id'], 'Invoice item was not found');
        // ClientFence core patch: a paid invoice keeps its lines. Checked under the client's credit lock with the status as it
        // is now: a line deleted while a credit payment ran left the invoice paid for less than was taken.
        $invoice = $this->getDi()['db']->load('Invoice', $model->invoice_id);
        $invoiceItemService = $this->getDi()['mod_service']('Invoice', 'InvoiceItem');
        if (!$invoice instanceof \Model_Invoice) {
            return $invoiceItemService->remove($model);
        }

        return \ClientFence\Tools::withLock($this->getDi(), 'fb:credit_payment:client:' . (int) $invoice->client_id, function () use ($invoice, $model, $invoiceItemService) {
            $this->getService()->refreshStatus($invoice);
            if ($this->getService()->isBooked($invoice)) {
                throw new \ClientFence\InformationException('The lines and tax rate of a paid invoice cannot be changed. Refund it and make a new invoice.');
            }
            return $invoiceItemService->remove($model);
        });
    }

    /**
     * Returns invoice items whose task execution permanently failed.
     *
     * @return array
     */
    public function get_failed_items($data)
    {
        $this->checkPermissions('invoice', 'view');

        $invoiceItemService = $this->getDi()['mod_service']('Invoice', 'InvoiceItem');

        return $invoiceItemService->getFailedItems();
    }

    /**
     * Re-queue a failed invoice item for execution by resetting its status and attempt counter.
     */
    #[RequiredParams(['id' => 'Invoice item ID was not passed'])]
    public function item_requeue($data): bool
    {
        $this->checkPermissions('invoice', 'manage_invoices');

        $invoiceItemService = $this->getDi()['mod_service']('Invoice', 'InvoiceItem');
        $invoiceItemService->requeueItem((int) $data['id']);

        return true;
    }

    /**
     * Delete invoice.
     *
     * @return bool
     */
    #[RequiredParams(['id' => 'Invoice ID is missing'])]
    public function delete($data)
    {
        $this->checkPermissions('invoice', 'manage_invoices');

        $model = $this->_getInvoice($data);

        return $this->getService()->deleteInvoiceByAdmin($model);
    }

    /**
     * Generates new invoice for order. If unpaid invoice for selected order
     * already exists, new invoice will not be generated, and existing invoice id
     * is returned.
     *
     * @optional int $due_days - Days number until invoice is due
     *
     * @return string - invoice id
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Order ID was not passed'])]
    public function renewal_invoice($data)
    {
        $this->checkPermissions('invoice', 'manage_invoices');

        $model = $this->getDi()['db']->getExistingModelById('ClientOrder', $data['id'], 'Order not found');

        return $this->getService()->renewInvoice($model, $data);
    }

    /**
     * Use credits to pay for invoices
     * if credits are available in clients balance.
     *
     * @optional int $client_id - cover only one client invoices
     *
     * @return bool
     */
    public function batch_pay_with_credits($data)
    {
        $this->checkPermissions('invoice', 'manage_invoices');

        return $this->getService()->doBatchPayWithCredits($data);
    }

    /**
     * Cover one invoice with credits.
     *
     * @return bool
     */
    #[RequiredParams(['id' => 'Invoice ID is missing'])]
    public function pay_with_credits($data)
    {
        $this->checkPermissions('invoice', 'manage_invoices');

        $invoice = $this->_getInvoice($data);

        // ClientFence core patch (QA r7 M-02): report the refused deposit payment instead of a false success.
        if ($this->getService()->isInvoiceTypeDeposit($invoice)) {
            throw new \ClientFence\InformationException(__trans('Client credit') . ': ' . __trans('You may not pay a deposit invoice with this payment gateway.'));
        }

        return $this->getService()->payInvoiceWithCredits($invoice);
    }

    /**
     * ClientFence core patch: apply part or all of the client's credit to an unpaid invoice (WHMCS "Apply Credit").
     *
     * @return bool
     */
    #[RequiredParams(['id' => 'Invoice ID is missing', 'amount' => 'Amount is missing'])]
    public function apply_credit($data)
    {
        $this->checkPermissions('invoice', 'manage_invoices');

        return $this->getService()->applyCredit($this->_getInvoice($data), (float) $data['amount']);
    }

    /**
     * Take the credit applied to an unpaid invoice back to the client's credit.
     *
     * @return bool
     */
    #[RequiredParams(['id' => 'Invoice ID is missing'])]
    public function remove_credit($data)
    {
        $this->checkPermissions('invoice', 'manage_invoices');

        return $this->getService()->removeCredit($this->_getInvoice($data));
    }

    /**
     * Generate invoices for expiring orders.
     *
     * @return bool
     */
    // ClientFence core patch: the due-invoice action can be limited to the client being managed.
    public function batch_generate($data = [])
    {
        $this->checkPermissions('invoice', 'manage_invoices');

        return $this->getService()->generateInvoicesForExpiringOrders(isset($data['client_id']) ? (int) $data['client_id'] : null);
    }

    #[RequiredParams(['ids' => 'Order IDs are required'])]
    public function batch_renewal_invoice($data): array
    {
        $this->checkPermissions('invoice', 'manage_invoices');

        return $this->getService()->batchRenewalInvoice($data['ids']);
    }

    #[RequiredParams(['client_id' => 'Client ID is missing', 'amount' => 'Amount is required'])]
    public function funds_invoice($data): int
    {
        $this->checkPermissions('invoice', 'manage_invoices');
        if (!is_numeric($data['amount']) || !is_finite((float) $data['amount']) || (float) $data['amount'] <= 0) {
            throw new InformationException('You need to enter a positive numeric value');
        }
        $client = $this->getDi()['db']->getExistingModelById('Client', $data['client_id'], 'Client not found');
        $invoice = $this->getService()->generateFundsInvoice($client, $data['amount']);
        $this->getService()->approveInvoice($invoice, ['id' => $invoice->id]);
        $this->getDi()['logger']->info('Generated add funds invoice #%s', $invoice->id);

        return (int) $invoice->id;
    }

    /**
     * Action to activate paid invoices lines.
     *
     * @return bool
     */
    public function batch_activate_paid()
    {
        $this->checkPermissions('invoice', 'manage_invoices');

        return $this->getService()->doBatchPaidInvoiceActivation();
    }

    /**
     * Attempts the invoice due event batch (see batch_invoke_due_event()), limited to once per
     * rolling 24-hour period. If that batch ran within the previous 24 hours, it falls back to
     * dispatching the same due-date events directly so newly-eligible invoices are not missed
     * for up to 24 hours. Either way, the built-in reminder handlers atomically claim each
     * invoice via reminded_at before sending anything, so the same invoice is never reminded
     * twice even across overlapping cron runs or repeated dispatch of the underlying events.
     *
     * @return bool
     */
    public function batch_send_reminders($data)
    {
        $this->checkPermissions('invoice', 'manage_invoices');

        return $this->getService()->doBatchRemindersSend();
    }

    /**
     * Calls due events on unpaid and approved invoices.
     * Attach custom event hooks events:.
     *
     * onEventBeforeInvoiceIsDue - event receives params: id and days_left
     * onEventAfterInvoiceIsDue - event receives params: id and days_passed
     *
     * @optional bool $once_per_day - default true. Pass false if you want to execute this action more than once per day
     *
     * @return bool - true if executed, false - if it was already executed
     */
    public function batch_invoke_due_event($data)
    {
        $this->checkPermissions('invoice', 'manage_invoices');

        return $this->getService()->doBatchInvokeDueEvent($data);
    }

    /**
     * Send payment reminder notification for client.
     * Calls event hook, so you can attach your custom notification code.
     *
     * @return bool
     */
    #[RequiredParams(['id' => 'Invoice ID is missing'])]
    public function send_reminder($data)
    {
        $this->checkPermissions('invoice', 'manage_invoices');

        $invoice = $this->_getInvoice($data);

        return $this->getService()->sendInvoiceReminder($invoice);
    }

    /**
     * Return invoice statuses with counter.
     *
     * @return array
     */
    public function get_statuses($data)
    {
        $this->checkPermissions('invoice', 'view');

        return $this->getService()->counter();
    }

    /**
     * Process all received transactions.
     *
     * @return bool
     */
    public function transaction_process_all($data)
    {
        $this->checkPermissions('invoice', 'manage_transactions');

        $transactionService = $this->getDi()['mod_service']('Invoice', 'Transaction');

        return $transactionService->processReceivedATransactions();
    }

    /**
     * Process selected transaction.
     */
    #[RequiredParams(['id' => 'Transaction ID is missing'])]
    public function transaction_process($data): bool
    {
        $this->checkPermissions('invoice', 'manage_transactions');

        $model = $this->getDi()['db']->getExistingModelById('Transaction', $data['id'], 'Transaction not found');

        $output = null;
        $this->getDi()['events_manager']->fire(['event' => 'onBeforeAdminTransactionProcess', 'params' => ['id' => $model->id]]);

        $transactionService = $this->getDi()['mod_service']('Invoice', 'Transaction');

        return $transactionService->preProcessTransaction($model);
    }

    /**
     * Update transaction details.
     *
     * @optional int $invoice_id - new invoice id
     * @optional string $txn_id - transaction id on payment gateway
     * @optional string $txn_status - transaction status on payment gateway
     * @optional int $gateway_id - Payment gateway ID on FOSSBilling
     * @optional float $amount - Transaction amount
     * @optional string $currency - Currency code. Must be available on FOSSBilling
     * @optional string $type - Currency code. Must be available on FOSSBilling
     * @optional string $status - Transaction status on FOSSBilling
     * @optional bool $validate_ipn - Flag to enable and disable IPN validation for this transaction
     * @optional string $note - Custom note
     *
     * @return bool
     */
    #[RequiredParams(['id' => 'Transaction ID is missing'])]
    public function transaction_update($data)
    {
        $this->checkPermissions('invoice', 'manage_transactions');

        $model = $this->getDi()['db']->getExistingModelById('Transaction', $data['id'], 'Transaction not found');

        $transactionService = $this->getDi()['mod_service']('Invoice', 'Transaction');

        return $transactionService->update($model, $data);
    }

    /**
     * Create custom transaction.
     *
     * @optional array $get - $_GET data
     * @optional array $post - $_POST data
     * @optional array $server - $_SERVER data
     * @optional array $http_raw_post_data - php://input
     * @optional string $txn_id - transaction id on payment gateway
     * @optional bool $skip_validation - makes params invoice_id and gateway_id optional
     *
     * @return int $id - new transaction id
     */
    public function transaction_create($data)
    {
        $this->checkPermissions('invoice', 'manage_transactions');

        $transactionService = $this->getDi()['mod_service']('Invoice', 'Transaction');
        $data['source'] ??= 'admin';

        $id = $transactionService->create($data);
        // ClientFence core patch: settle staff payments now, recording and notifying failures as cron does.
        $transactionService->processAndCatchErrors((int) $id);

        return $id;
    }

    /**
     * Remove transaction.
     *
     * @return bool
     */
    #[RequiredParams(['id' => 'Transaction ID is missing'])]
    public function transaction_delete($data)
    {
        $this->checkPermissions('invoice', 'manage_transactions');

        $model = $this->getDi()['db']->getExistingModelById('Transaction', $data['id'], 'Transaction not found');

        $transactionService = $this->getDi()['mod_service']('Invoice', 'Transaction');

        return $transactionService->delete($model);
    }

    /**
     * Get transaction details.
     *
     * @return array
     */
    #[RequiredParams(['id' => 'Transaction ID is missing'])]
    public function transaction_get($data)
    {
        $this->checkPermissions('invoice', 'manage_transactions');

        $model = $this->getDi()['db']->getExistingModelById('Transaction', $data['id'], 'Transaction not found');

        $transactionService = $this->getDi()['mod_service']('Invoice', 'Transaction');

        return $transactionService->toApiArray($model, true);
    }

    /**
     * Get paginated list of transactions.
     *
     * @optional string $txn_id - search for transactions by transaction id on payment gateway
     *
     * @return array
     */
    public function transaction_get_list($data)
    {
        $this->checkPermissions('invoice', 'manage_transactions');

        $transactionService = $this->getDi()['mod_service']('Invoice', 'Transaction');
        [$sql, $params] = $transactionService->getSearchQuery($data);

        $pager = $this->getDi()['pager']->getPaginatedResultSet($sql, $params, PaginationOptions::fromArray($data));

        foreach ($pager['list'] as $key => $item) {
            $pager['list'][$key] = $transactionService->searchResultToApiArray($item);
        }

        return $pager;
    }

    /**
     * Total in, total out and balance of a client's processed transactions, one row per currency.
     *
     * @return array list of ['currency', 'in', 'out', 'balance']
     */
    #[RequiredParams(['client_id' => 'Client ID is missing'])]
    public function transaction_client_totals($data)
    {
        // ClientFence core patch: staff client page transaction totals
        $this->checkPermissions('invoice', 'manage_transactions');

        return $this->getDi()['mod_service']('Invoice', 'Transaction')->getClientTotals((int) $data['client_id']);
    }

    /**
     * Return transactions statuses with counter.
     *
     * @return array
     */
    public function transaction_get_statuses($data)
    {
        $this->checkPermissions('invoice', 'view');

        $transactionService = $this->getDi()['mod_service']('Invoice', 'Transaction');

        return $transactionService->counter();
    }

    /**
     * Get available transaction statuses.
     *
     * @return array
     */
    public function transaction_get_statuses_pairs($data)
    {
        $this->checkPermissions('invoice', 'view');

        $transactionService = $this->getDi()['mod_service']('Invoice', 'Transaction');

        return $transactionService->getStatusPairs();
    }

    /**
     * Get available transaction statuses.
     *
     * @return array
     */
    public function transaction_statuses($data)
    {
        $this->checkPermissions('invoice', 'view');

        $transactionService = $this->getDi()['mod_service']('Invoice', 'Transaction');

        return $transactionService->getStatuses();
    }

    /**
     * Get available transaction statuses on gateways.
     *
     * @return array
     */
    public function transaction_gateway_statuses($data)
    {
        $this->checkPermissions('invoice', 'view');

        $transactionService = $this->getDi()['mod_service']('Invoice', 'Transaction');

        return $transactionService->getGatewayStatuses();
    }

    /**
     * Get available transaction types.
     *
     * @return array
     */
    public function transaction_types($data)
    {
        $this->checkPermissions('invoice', 'view');

        $transactionService = $this->getDi()['mod_service']('Invoice', 'Transaction');

        return $transactionService->getTypes();
    }

    /**
     * Atomically claim a transaction for processing.
     * Uses conditional UPDATE to prevent race conditions when multiple
     * workers attempt to process the same transaction simultaneously.
     *
     * @param array $data Contains 'id' - the transaction ID to claim
     *
     * @return bool True if the transaction was successfully claimed, false if already claimed/processed
     *
     * @throws \ClientFence\Exception if transaction ID is missing
     */
    public function transaction_claim_for_processing($data)
    {
        $this->checkPermissions('invoice', 'manage_transactions');

        $required = [
            'id' => 'Transaction ID is required',
        ];
        $this->getDi()['validator']->checkRequiredParamsForArray($required, $data);

        $transactionService = $this->getDi()['mod_service']('Invoice', 'Transaction');

        return $transactionService->claimForProcessing((int) $data['id']);
    }

    /**
     * Get available gateways.
     *
     * @return array
     */
    public function gateway_get_list($data)
    {
        $this->checkPermissions('invoice', 'manage_gateways');

        $gatewayService = $this->getDi()['mod_service']('Invoice', 'PayGateway');
        [$sql, $params] = $gatewayService->getSearchQuery($data);

        $pager = $this->getDi()['pager']->getPaginatedResultSet($sql, $params, PaginationOptions::fromArray($data));

        foreach ($pager['list'] as $key => $item) {
            $gateway = $this->getDi()['db']->getExistingModelById('PayGateway', $item['id'], 'Gateway not found');
            $pager['list'][$key] = $gatewayService->toApiArray($gateway, false, $this->getIdentity());
        }

        return $pager;
    }

    /**
     * Get available gateways pairs.
     *
     * @return array
     */
    public function gateway_get_pairs($data)
    {
        // ClientFence core patch: only the gateways' names, for filters and lists; any invoice staff may read them
        // (the transactions and subscriptions pages crashed without "manage gateways").
        $this->checkPermissions('invoice');

        $gatewayService = $this->getDi()['mod_service']('Invoice', 'PayGateway');

        return $gatewayService->getPairs();
    }

    /**
     * Return existing module but not activated.
     *
     * @return array
     */
    public function gateway_get_available(array $data)
    {
        $this->checkPermissions('invoice', 'manage_gateways');

        $gatewayService = $this->getDi()['mod_service']('Invoice', 'PayGateway');

        return $gatewayService->getAvailable();
    }

    /**
     * Install available payment gateway.
     *
     * @return true
     */
    #[RequiredParams(['code' => 'Payment gateway code is missing'])]
    public function gateway_install(array $data)
    {
        $this->checkPermissions('invoice', 'manage_gateways');

        $code = $data['code'];
        $gatewayService = $this->getDi()['mod_service']('Invoice', 'PayGateway');

        return $gatewayService->install($code);
    }

    /**
     * Get gateway details.
     *
     * @return array
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Gateway ID was not passed'])]
    public function gateway_get($data)
    {
        $this->checkPermissions('invoice', 'manage_gateways');

        $model = $this->getDi()['db']->getExistingModelById('PayGateway', $data['id'], 'Gateway not found');

        $gatewayService = $this->getDi()['mod_service']('Invoice', 'PayGateway');

        return $gatewayService->toApiArray($model, true, $this->getIdentity());
    }

    /**
     * Copy gateway from existing one.
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Gateway ID was not passed'])]
    public function gateway_copy($data)
    {
        $this->checkPermissions('invoice', 'manage_gateways');

        $model = $this->getDi()['db']->getExistingModelById('PayGateway', $data['id'], 'Gateway not found');
        $gatewayService = $this->getDi()['mod_service']('Invoice', 'PayGateway');

        return $gatewayService->copy($model);
    }

    /**
     * Change gateway settings.
     *
     * @optional string $title - gateway title
     * @optional array $config - gateway config array
     * @optional array $accepted_currencies - list of currencies this gateway supports
     * @optional bool $enabled - flag to enable or disable gateway
     * @optional bool $allow_single - flag to enable or disable single payment option
     * @optional bool $allow_recurrent - flag to enable or disable recurrent payment option
     * @optional bool $test_mode - flag to enable or disable test mode for gateway
     *
     * @return bool
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Gateway ID was not passed'])]
    public function gateway_update($data)
    {
        $this->checkPermissions('invoice', 'manage_gateways');

        $model = $this->getDi()['db']->getExistingModelById('PayGateway', $data['id'], 'Gateway not found');
        $gatewayService = $this->getDi()['mod_service']('Invoice', 'PayGateway');

        return $gatewayService->update($model, $data);
    }

    /**
     * Remove payment gateway from system.
     *
     * @return bool
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Gateway ID was not passed'])]
    public function gateway_delete($data)
    {
        $this->checkPermissions('invoice', 'manage_gateways');

        $model = $this->getDi()['db']->getExistingModelById('PayGateway', $data['id'], 'Gateway not found');
        $gatewayService = $this->getDi()['mod_service']('Invoice', 'PayGateway');

        return $gatewayService->delete($model);
    }

    /**
     * Get list of subscriptions.
     *
     * @return array
     */
    public function subscription_get_list($data)
    {
        $this->checkPermissions('invoice', 'manage_subscriptions');

        $subscriptionService = $this->getDi()['mod_service']('Invoice', 'Subscription');

        [$sql, $params] = $subscriptionService->getSearchQuery($data);
        $pager = $this->getDi()['pager']->getPaginatedResultSet($sql, $params, PaginationOptions::fromArray($data));

        if (isset($pager['list']) && is_array($pager['list'])) {
            foreach ($pager['list'] as $key => $item) {
                $subscription = $this->getDi()['db']->getExistingModelById('Subscription', $item['id'], 'Subscription not found');
                $pager['list'][$key] = $subscriptionService->toApiArray($subscription);
            }
        }

        return $pager;
    }

    /**
     * Add new subscription.
     *
     * @optional string $sid - subscription id on payment gateway
     * @optional string $status - status: active|canceled
     * @optional string $period - example: 1W - every week, 2M - every 2 months
     * @optional string $amount - billed amount
     * @optional string $rel_type - related item type
     * @optional string $rel_id - related item id
     *
     * @return int - id
     *
     * @throws InformationException
     */
    #[RequiredParams([
        'client_id' => 'Client ID was not passed',
        'gateway_id' => 'Payment gateway ID was not passed',
        'currency' => 'Subscription currency was not passed',
    ])]
    public function subscription_create($data)
    {
        $this->checkPermissions('invoice', 'manage_subscriptions');

        $client = $this->getDi()['db']->getExistingModelById('Client', $data['client_id'], 'Client not found');
        $payGateway = $this->getDi()['db']->getExistingModelById('PayGateway', $data['gateway_id'], 'Payment gateway not found');

        if (strtoupper((string) $client->currency) !== strtoupper((string) $data['currency'])) {
            throw new InformationException('Client currency must match subscription currency. Check if clients currency is defined.');
        }
        $subscriptionService = $this->getDi()['mod_service']('Invoice', 'Subscription');

        return $subscriptionService->create($client, $payGateway, $data);
    }

    /**
     * Update subscription options.
     *
     * @optional int $status - subscription status
     * @optional string $sid - subscription id on payment gateway
     * @optional string $period - subscription period code
     * @optional string $amount - subscription amount
     * @optional string $currency - subscription currency
     *
     * @return bool
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Subscription ID was not passed'])]
    public function subscription_update($data)
    {
        $this->checkPermissions('invoice', 'manage_subscriptions');

        $model = $this->getDi()['db']->getExistingModelById('Subscription', $data['id'], 'Subscription not found');
        $subscriptionService = $this->getDi()['mod_service']('Invoice', 'Subscription');

        return $subscriptionService->update($model, $data);
    }

    /**
     * Get subscription details.
     *
     * @return array
     *
     * @throws \ClientFence\Exception
     */
    public function subscription_get($data)
    {
        $this->checkPermissions('invoice', 'manage_subscriptions');

        if (!isset($data['id']) && !isset($data['sid'])) {
            $required = [
                'id' => 'Subscription ID was not passed',
                'sid' => 'Subscription sID was not passed',
            ];
            $this->getDi()['validator']->checkRequiredParamsForArray($required, $data);
        }
        $model = null;
        if (isset($data['id'])) {
            $model = $this->getDi()['db']->load('Subscription', $data['id']);
        }

        if (!$model && isset($data['sid'])) {
            $model = $this->getDi()['db']->findOne('Subscription', 'sid = ?', [$data['sid']]);
        }

        if (!$model instanceof \Model_Subscription) {
            throw new \ClientFence\Exception('Subscription not found');
        }

        $subscriptionService = $this->getDi()['mod_service']('Invoice', 'Subscription');

        return $subscriptionService->toApiArray($model, true, $this->getIdentity());
    }

    /**
     * Remove subscription.
     *
     * @return bool
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Subscription ID was not passed'])]
    public function subscription_delete($data)
    {
        $this->checkPermissions('invoice', 'manage_subscriptions');

        $model = $this->getDi()['db']->getExistingModelById('Subscription', $data['id'], 'Subscription not found');
        $subscriptionService = $this->getDi()['mod_service']('Invoice', 'Subscription');

        return $subscriptionService->delete($model);
    }

    /**
     * Remove tax rule.
     *
     * @return bool
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Tax ID was not passed'])]
    public function tax_delete($data)
    {
        $this->checkPermissions('invoice', 'manage_tax');

        $model = $this->getDi()['db']->getExistingModelById('Tax', $data['id'], 'Tax rule not found');
        $taxService = $this->getDi()['mod_service']('Invoice', 'Tax');

        return $taxService->delete($model);
    }

    /**
     * Create new tax rule.
     *
     * @return int - new tax id
     */
    #[RequiredParams([
        'name' => 'Tax name is missing',
        'taxrate' => 'Tax rate is missing or is invalid',
    ])]
    public function tax_create($data)
    {
        $this->checkPermissions('invoice', 'manage_tax');

        $taxService = $this->getDi()['mod_service']('Invoice', 'Tax');

        return $taxService->create($data);
    }

    /**
     * Update tax rule.
     *
     * @return array
     */
    #[RequiredParams(['id' => 'Tax ID was not passed'])]
    public function tax_get($data)
    {
        $this->checkPermissions('invoice', 'manage_tax');

        $tax = $this->getDi()['db']->getExistingModelById('Tax', $data['id'], 'Tax rule not found');

        $taxService = $this->getDi()['mod_service']('Invoice', 'Tax');

        return $taxService->toApiArray($tax);
    }

    /**
     * Update tax rule.
     *
     * @return bool
     */
    #[RequiredParams([
        'id' => 'Tax ID is missing',
        'taxrate' => 'Tax rate is missing',
        'name' => 'Tax name is missing',
    ])]
    public function tax_update($data)
    {
        $this->checkPermissions('invoice', 'manage_tax');

        $tax = $this->getDi()['db']->getExistingModelById('Tax', $data['id'], 'Tax rule not found');

        $taxService = $this->getDi()['mod_service']('Invoice', 'Tax');

        return $taxService->update($tax, $data);
    }

    /**
     * Get list of taxes.
     *
     * @return array
     */
    public function tax_get_list($data)
    {
        $this->checkPermissions('invoice', 'manage_tax');

        $taxService = $this->getDi()['mod_service']('Invoice', 'Tax');
        [$sql, $params] = $taxService->getSearchQuery($data);

        return $this->getDi()['pager']->getPaginatedResultSet($sql, $params, PaginationOptions::fromArray($data));
    }

    /**
     * Automatically setup the EU VAT tax rules for you for all EU Member States.
     * This action will delete any existing tax rules and configure the VAT rates
     * for all EU countries.
     *
     * @return bool
     */
    public function tax_setup_eu($data)
    {
        $this->checkPermissions('invoice', 'manage_tax');

        $taxService = $this->getDi()['mod_service']('Invoice', 'Tax');

        return $taxService->setupEUTaxes($data);
    }

    #[RequiredParams(['id' => 'Invoice ID was not passed'])]
    private function _getInvoice($data)
    {
        return $this->getDi()['db']->getExistingModelById('Invoice', $data['id'], 'Invoice was not found');
    }

    /**
     * Deletes invoices with given IDs.
     */
    #[RequiredParams(['ids' => 'IDs were not passed'])]
    public function batch_delete($data): bool
    {
        $this->checkPermissions('invoice', 'manage_invoices');

        foreach ($data['ids'] as $id) {
            $this->delete(['id' => $id]);
        }

        return true;
    }

    /**
     * Deletes subscriptions with given IDs.
     */
    #[RequiredParams(['ids' => 'IDs were not passed'])]
    public function batch_delete_subscription($data): bool
    {
        $this->checkPermissions('invoice', 'manage_subscriptions');

        foreach ($data['ids'] as $id) {
            $this->subscription_delete(['id' => $id]);
        }

        return true;
    }

    /**
     * Deletes transactions with given IDs.
     */
    #[RequiredParams(['ids' => 'IDs were not passed'])]
    public function batch_delete_transaction($data): bool
    {
        $this->checkPermissions('invoice', 'manage_transactions');

        foreach ($data['ids'] as $id) {
            $this->transaction_delete(['id' => $id]);
        }

        return true;
    }

    /**
     * Deletes taxes with given IDs.
     */
    #[RequiredParams(['ids' => 'IDs were not passed'])]
    public function batch_delete_tax($data): bool
    {
        $this->checkPermissions('invoice', 'manage_tax');

        foreach ($data['ids'] as $id) {
            $this->tax_delete(['id' => $id]);
        }

        return true;
    }

    /**
     * ClientFence: the Reports page, as WHMCS's (Reports.php): group => title and reports.
     */
    public function report_catalog($data): array
    {
        $this->checkPermissions('invoice', 'view');

        // ClientFence core patch: reports also expose data governed by their group's module permission.
        $catalog = \ClientFence\Mod\Invoice\Reports::catalog();
        foreach (\ClientFence\Mod\Invoice\Reports::GROUP_MODULES as $group => $module) {
            if ($module !== 'invoice' && !$this->getDi()['mod_service']('staff')->hasPermission($this->getIdentity(), $module, 'view')) {
                unset($catalog[$group]);
            }
        }

        return $catalog;
    }

    /**
     * ClientFence: runs a report (Reports.php). `report`: its key; the rest are its filters
     * (month, from, to, currency, client_id, min, max as the report lists them in `fields`).
     */
    public function report($data): array
    {
        $this->checkPermissions('invoice', 'view');
        $key = (string) ($data['report'] ?? '');
        $group = \ClientFence\Mod\Invoice\Reports::REPORTS[$key] ?? null;
        if ($group !== null && \ClientFence\Mod\Invoice\Reports::GROUP_MODULES[$group] !== 'invoice') {
            $this->checkPermissions(\ClientFence\Mod\Invoice\Reports::GROUP_MODULES[$group], 'view');
        }
        unset($data['report']);

        return \ClientFence\Mod\Invoice\Reports::run($this->getDi(), $key, $data);
    }

    /**
     * ClientFence: a report as a CSV download (Reports::csv()), for staff who may export.
     */
    public function report_csv($data): Response
    {
        $this->checkPermissions('invoice', 'export');

        return \ClientFence\Mod\Invoice\Reports::csv($this->report($data), \Locale::getDefault());
    }

    /**
     * ClientFence: quotes, as WHMCS's (Quotes.php). The list: `stage`, `client_id`, `search`,
     * `page`, `per_page`.
     */
    public function quote_get_list($data): array
    {
        $this->checkPermissions('invoice', 'view');
        \ClientFence\Mod\Invoice\Quotes::ensure($this->getDi());

        return \ClientFence\Mod\Invoice\Quotes::getList($this->getDi(), is_array($data) ? $data : [], (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 30));
    }

    /**
     * ClientFence: a quote with its lines and totals (Quotes.php). `id`.
     */
    public function quote_get($data): array
    {
        $this->checkPermissions('invoice', 'view');
        \ClientFence\Mod\Invoice\Quotes::ensure($this->getDi());

        return \ClientFence\Mod\Invoice\Quotes::get($this->getDi(), (int) ($data['id'] ?? 0));
    }

    /**
     * ClientFence: creates or updates a quote (Quotes::save()). Returns its id.
     */
    public function quote_save($data): int
    {
        $this->checkPermissions('invoice', 'manage_invoices');
        \ClientFence\Mod\Invoice\Quotes::ensure($this->getDi());

        return \ClientFence\Mod\Invoice\Quotes::save($this->getDi(), is_array($data) ? $data : [], (int) $this->getIdentity()->id);
    }

    /**
     * ClientFence: deletes a quote (its invoice, if any, stays). `id`.
     */
    public function quote_delete($data): bool
    {
        $this->checkPermissions('invoice', 'manage_invoices');
        \ClientFence\Mod\Invoice\Quotes::ensure($this->getDi());
        \ClientFence\Mod\Invoice\Quotes::delete($this->getDi(), (int) ($data['id'] ?? 0));

        return true;
    }

    /**
     * ClientFence: emails a quote with its PDF and link (Quotes::send()). `id`.
     */
    public function quote_send($data): bool
    {
        $this->checkPermissions('invoice', 'manage_invoices');
        \ClientFence\Mod\Invoice\Quotes::ensure($this->getDi());
        \ClientFence\Mod\Invoice\Quotes::send($this->getDi(), (int) ($data['id'] ?? 0));

        return true;
    }

    /**
     * ClientFence: accepts a quote for the client (accepted by phone or email): the account of a
     * prospect and the invoice are made (Quotes::accept()). `id`. Returns the invoice id.
     */
    public function quote_accept($data): int
    {
        $this->checkPermissions('invoice', 'manage_invoices');
        \ClientFence\Mod\Invoice\Quotes::ensure($this->getDi());

        return \ClientFence\Mod\Invoice\Quotes::accept($this->getDi(), (int) ($data['id'] ?? 0), 'staff');
    }

    /**
     * ClientFence: a quote as a PDF in the recipient's language (QuotePdf.php). `id`.
     */
    public function quote_pdf($data): Response
    {
        $this->checkPermissions('invoice', 'view');
        \ClientFence\Mod\Invoice\Quotes::ensure($this->getDi());
        $quote = \ClientFence\Mod\Invoice\Quotes::get($this->getDi(), (int) ($data['id'] ?? 0));

        return \ClientFence\Mod\Invoice\QuotePdf::response($this->getDi(), $quote, \ClientFence\Mod\Invoice\Quotes::recipientLocale($this->getDi(), $quote));
    }

    /**
     * ClientFence: a client's account statement as a PDF in the staff member's language
     * (Statement.php, StatementPdf.php). `client_id`; `from` and `to`: optional dates.
     */
    public function statement_pdf($data): Response
    {
        $this->checkPermissions('invoice', 'view');
        $statement = \ClientFence\Mod\Invoice\Statement::build($this->getDi(), (int) ($data['client_id'] ?? 0), trim((string) ($data['from'] ?? '')), trim((string) ($data['to'] ?? '')));

        return \ClientFence\Mod\Invoice\StatementPdf::response($this->getDi(), $statement, \Locale::getDefault());
    }

    public function export_csv($data): Response
    {
        $this->checkPermissions('invoice', 'view');
        $this->checkPermissions('invoice', 'export');

        $data['headers'] ??= [];

        return $this->getService()->exportCSV($data['headers']);
    }

    /**
     * ClientFence: saves the help desk that payment receipts open a ticket in (`receipt_helpdesk`,
     * empty: the first one), on the Invoices settings page (Receipts.php).
     */
    public function receipts_update($data): bool
    {
        $di = $this->getDi();
        $di['mod_service']('extension')->hasManagePermission('mod_invoice');

        $helpdesk = Receipts::helpdeskSetting($di, is_array($data) ? ($data['receipt_helpdesk'] ?? '') : '');
        $extension = $di['mod_service']('extension');
        $extension->setConfig(array_merge($extension->getConfig('mod_invoice'), ['receipt_helpdesk' => $helpdesk, 'ext' => 'mod_invoice']));
        $di['logger']->info('Invoice: payment receipts go to help desk %s', $helpdesk === '' ? 'the first' : $helpdesk);

        return true;
    }

    /**
     * ClientFence: saves when saved cards and PayPal accounts are charged (SavedCards.php): `card_days_before` (0-30
     * days before the due date), `card_retry_every` (1-30 days between tries) and `card_retry_count` (0-5 retries).
     */
    public function card_settings_update($data): bool
    {
        $di = $this->getDi();
        $di['mod_service']('extension')->hasManagePermission('mod_invoice');
        SavedCards::saveSettings($di, is_array($data) ? $data : []);

        return true;
    }

    /**
     * ClientFence: finds saved cards at Stripe for clients without one (a move from WHMCS), 25 clients
     * after `after_id` per call: {checked, found, next} (SavedCards.php).
     */
    public function card_find($data): array
    {
        $di = $this->getDi();
        $di['mod_service']('extension')->hasManagePermission('mod_invoice');
        $adapter = SavedCards::stripe($di) ?? throw new InformationException('Saved cards are not available.');
        $result = $adapter->findCards((int) ($data['after_id'] ?? 0), 25);
        $di['logger']->info('Invoice: looked for saved cards at Stripe for %s clients, found %s', $result['checked'], $result['found']);

        return $result;
    }

    /** ClientFence: the saved method an invoice's client is charged to ("Visa •••• 4242 (12/2028)", "PayPal name@example.com"), or null. */
    #[RequiredParams(['id' => 'Invoice ID was not passed'])]
    public function card_get($data): ?string
    {
        $this->checkPermissions('invoice', 'view');
        $invoice = $this->getDi()['db']->getExistingModelById('Invoice', $data['id'], 'Invoice not found');

        return SavedCards::card($this->getDi(), (int) $invoice->client_id)['card'] ?? null;
    }

    /**
     * ClientFence: the balance of each enabled PayPal gateway (PayPal Email with its REST API keys, PayPal Auto-Pay) whose
     * "PayPal balance on the dashboard" is on, as WHMCS's gateway balance widget: [{gateway, test_mode, balances:
     * [{currency, amount}], error}]. PayPal is asked at most every 10 minutes.
     */
    public function paypal_balances(): array
    {
        $this->checkPermissions('invoice', 'view');
        $di = $this->getDi();
        $result = [];
        foreach ($di['db']->find('PayGateway', "enabled = 1 AND gateway IN ('PayPalEmail', 'PayPalAutoPay') ORDER BY id") as $gateway) {
            if ((string) ((json_decode((string) $gateway->config, true) ?? [])['balance'] ?? '1') === '0') {
                continue;
            }
            $key = 'clientfence_paypal_balance_' . $gateway->id . '_' . (int) $gateway->test_mode;
            $result[] = ['gateway' => (string) $gateway->name, 'test_mode' => (bool) $gateway->test_mode] + $di['cache']->get($key, function (\Symfony\Contracts\Cache\ItemInterface $item) use ($di, $gateway): array {
                $item->expiresAfter(600);
                try {
                    return ['balances' => $di['mod_service']('Invoice', 'PayGateway')->getPaymentAdapter($gateway)->balances(), 'error' => null];
                } catch (\Throwable $e) {
                    return ['balances' => [], 'error' => $e instanceof \ClientFence\InformationException ? $e->getMessage() : __trans('PayPal did not answer. Please try again later.')];
                }
            });
        }

        return $result;
    }

    /** ClientFence: charges the client's saved card or PayPal account for an unpaid invoice (`id`) now; a failed charge is an error. */
    #[RequiredParams(['id' => 'Invoice ID was not passed'])]
    public function card_charge($data): bool
    {
        $this->checkPermissions('invoice', 'manage_transactions');
        $di = $this->getDi();
        $invoice = $di['db']->getExistingModelById('Invoice', $data['id'], 'Invoice not found');
        $method = SavedCards::method($di, (int) $invoice->client_id) ?? throw new \ClientFence\InformationException('The client has no saved payment method.');

        $result = SavedCards::charge($di, $invoice, $method['adapter'], true);
        if (!$result['paid']) {
            throw new InformationException((string) $result['error']);
        }

        return true;
    }
}
