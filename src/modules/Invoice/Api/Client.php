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
 *Invoice management.
 */

namespace ClientFence\Mod\Invoice\Api;

use ClientFence\Mod\Invoice\Receipts;
use ClientFence\PaginationOptions;
use ClientFence\Validation\Api\RequiredParams;

class Client extends \ClientFence\Api\AbstractApi
{
    /**
     * Get paginated list of invoices.
     *
     * @return array
     */
    public function get_list($data)
    {
        $data['client_id'] = $this->getIdentity()->id;
        $data['approved'] = true;

        // ClientFence core patch: the cheaper count query getSearchQuery() gives.
        [$sql, $params, $countSql] = $this->getService()->getSearchQuery($data);
        $pager = $this->getDi()['pager']->getPaginatedResultSet($sql, $params, PaginationOptions::fromArray($data), $countSql);

        foreach ($pager['list'] as $key => $item) {
            $invoice = $this->getDi()['db']->getExistingModelById('Invoice', $item['id'], 'Invoice not found');
            $pager['list'][$key] = $this->getService()->toApiArray($invoice);
        }

        return $pager;
    }

    /**
     * Get invoice details.
     *
     * @return array
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['hash' => 'Invoice hash was not passed'])]
    public function get($data)
    {
        $identity = $this->getIdentity();
        $model = $this->getDi()['db']->findOne('Invoice', 'hash = :hash AND client_id = :client_id', ['hash' => $data['hash'], 'client_id' => $identity->id]);
        if (!$model) {
            throw new \ClientFence\InformationException('Invoice was not found');
        }

        return $this->getService()->toApiArray($model, true, $identity);
    }

    /**
     * Generates new invoice for selected order. If unpaid invoice for selected order
     * already exists, new invoice will not be generated, and old invoice hash
     * is returned.
     *
     * @return string - invoice hash
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['order_id' => 'Order ID (order_id) was not passed'])]
    public function renewal_invoice($data)
    {
        $model = $this->getDi()['db']->findOne('ClientOrder', 'client_id = ? and id = ?', [$this->getIdentity()->id, $data['order_id']]);
        if (!$model instanceof \Model_ClientOrder) {
            throw new \ClientFence\InformationException('Order not found');
        }
        // ClientFence core patch: only a running service renews; a cancelled one was charged and stayed cancelled.
        if (empty($model->period) || !in_array($model->status, [\Model_ClientOrder::STATUS_ACTIVE, \Model_ClientOrder::STATUS_SUSPENDED], true)) {
            throw new \ClientFence\InformationException('Only an active or suspended service can be renewed.');
        }
        $service = $this->getService();
        // ClientFence core patch: the order's lock covers making and paying the invoice, and a click made
        // before another one renewed the order is refused: a double click renewed (and charged) twice.
        $seenExpiry = $model->expires_at;
        $invoice = \ClientFence\Tools::withLock($this->getDi(), 'fb:renewal:order:' . (int) $model->id, function () use ($service, $model, $seenExpiry) {
            $expiry = $this->getDi()['dbal']->fetchOne('SELECT expires_at FROM client_order WHERE id = ?', [$model->id]);
            if ($expiry !== false && $expiry !== null && $expiry !== $seenExpiry) {
                throw new \ClientFence\InformationException('This order was renewed a moment ago. Reload the page to see it.');
            }
            $invoice = $service->generateForOrder($model);
            $service->approveInvoice($invoice, ['id' => $invoice->id, 'use_credits' => true]);

            return $invoice;
        });
        $this->getDi()['logger']->info('Generated new renewal invoice #%s', $invoice->id);

        return $invoice->hash;
    }

    /**
     * Deposit money in advance. Generates new invoice for depositing money.
     * Clients currency must be defined.
     *
     * @return string - invoice hash
     */
    #[RequiredParams(['amount' => 'Amount is required'])]
    public function funds_invoice($data)
    {
        if (!is_numeric($data['amount'])) {
            throw new \ClientFence\InformationException('You need to enter numeric value');
        }

        $service = $this->getService();
        $invoice = $service->generateFundsInvoice($this->getIdentity(), $data['amount']);
        $service->approveInvoice($invoice, ['id' => $invoice->id]);
        $this->getDi()['logger']->info('Generated add funds invoice #%s', $invoice->id);

        return $invoice->hash;
    }

    /**
     * Get paginated list of transactions.
     *
     * @optional string $invoice_hash - filter transactions by invoice hash
     * @optional int $gateway_id - filter transactions by payment gateway id
     * @optional string $status - filter transactions by status
     * @optional string $currency - filter transactions by currency code
     * @optional string $date_from - filter transactions by date
     * @optional string $date_to - filter transactions by date
     *
     * @return array
     */
    public function transaction_get_list($data)
    {
        $data['client_id'] = $this->getIdentity()->id;
        $data['status'] = 'processed';
        $transactionService = $this->getDi()['mod_service']('Invoice', 'Transaction');
        [$sql, $params] = $transactionService->getSearchQuery($data);

        $pager = $this->getDi()['pager']->getPaginatedResultSet($sql, $params, PaginationOptions::fromArray($data));

        foreach ($pager['list'] as $key => $item) {
            $pager['list'][$key] = $transactionService->searchResultToApiArray($item);
        }

        return $pager;
    }

    public function get_tax_rate()
    {
        $service = $this->getDi()['mod_service']('Invoice', 'Tax');

        return $service->getTaxRateForClient($this->getIdentity());
    }

    /**
     * ClientFence: the client's account statement (Statement.php): invoices, payments and account
     * credit with the running balance. `from` and `to`: optional dates (year-month-day).
     */
    public function statement($data): array
    {
        return \ClientFence\Mod\Invoice\Statement::build($this->getDi(), (int) $this->getIdentity()->id, trim((string) ($data['from'] ?? '')), trim((string) ($data['to'] ?? '')));
    }

    /**
     * ClientFence: the account statement as a PDF in the client's language (StatementPdf.php).
     */
    public function statement_pdf($data): \Symfony\Component\HttpFoundation\Response
    {
        return \ClientFence\Mod\Invoice\StatementPdf::response($this->getDi(), $this->statement($data), \Locale::getDefault());
    }

    /**
     * ClientFence: the client's quotes (Quotes.php), not drafts, newest first.
     */
    public function quote_get_list($data): array
    {
        \ClientFence\Mod\Invoice\Quotes::ensure($this->getDi());

        return \ClientFence\Mod\Invoice\Quotes::forClient($this->getDi(), (int) $this->getIdentity()->id);
    }

    /**
     * ClientFence: the payment instructions of a manual payment method (Custom and adapters built on
     * it) for one of the client's unpaid invoices, as the method's settings word them. Unlike the
     * guest invoice_payment call this changes nothing and is not limited to 10 calls an hour, so
     * the invoice page can show every method's instructions. Empty for an invoice that is not the
     * client's or not unpaid.
     */
    public function payment_details($data): string
    {
        $di = $this->getDi();
        try {
            $invoice = Receipts::clientUnpaidInvoice($di, (int) $this->getIdentity()->id, (string) ($data['hash'] ?? ''));
            $gateway = Receipts::manualGateway($di, (int) ($data['gateway_id'] ?? 0));
        } catch (\ClientFence\InformationException) {
            // Not this client's unpaid invoice, or not a manual method: nothing to show.
            return '';
        }
        $adapter = $di['mod_service']('Invoice', 'PayGateway')->getPaymentAdapter($gateway);

        return (string) $adapter->getHtml($di['api_system'], (int) $invoice->id, false);
    }

    /**
     * ClientFence: uploads the receipt of a manual payment (multipart field "receipt") for one of
     * the client's unpaid invoices and opens a support ticket for staff (Receipts.php). Returns
     * the ticket id.
     */
    public function receipt_send($data): int
    {
        $di = $this->getDi();
        $client = $this->getIdentity();
        $invoice = Receipts::clientUnpaidInvoice($di, (int) $client->id, (string) ($data['hash'] ?? ''));
        $gateway = Receipts::manualGateway($di, (int) ($data['gateway_id'] ?? 0));

        return Receipts::send($di, $client, $invoice, $gateway, $di['request']->files->get('receipt'));
    }
}
