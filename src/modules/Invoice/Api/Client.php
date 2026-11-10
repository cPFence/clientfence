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
use ClientFence\Mod\Invoice\SavedCards;
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
     * ClientFence core patch: apply part or all of the account credit to an unpaid invoice (WHMCS client area).
     *
     * @return bool
     */
    #[RequiredParams(['hash' => 'Invoice hash was not passed', 'amount' => 'Amount is missing'])]
    public function apply_credit($data)
    {
        $model = $this->getDi()['db']->findOne('Invoice', 'hash = :hash AND client_id = :client_id AND approved = 1', ['hash' => $data['hash'], 'client_id' => $this->getIdentity()->id]);
        if (!$model instanceof \Model_Invoice) {
            throw new \ClientFence\InformationException('Invoice was not found');
        }

        return $this->getService()->applyCredit($model, (float) $data['amount']);
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
        $service = $this->getService();
        // ClientFence core patch: the order's lock covers making and paying the invoice, and a click made
        // before another one renewed the order is refused: a double click renewed (and charged) twice.
        $seenExpiry = $model->expires_at;
        $invoice = \ClientFence\Tools::withLock($this->getDi(), 'fb:renewal:order:' . (int) $model->id, function () use ($service, $model, $seenExpiry) {
            // ClientFence core patch: an upgrade waiting for this lock may have changed the renewal price and cycle.
            $model = $this->getDi()['db']->findOne('ClientOrder', 'client_id = ? and id = ?', [$this->getIdentity()->id, $model->id]);
            if (!$model instanceof \Model_ClientOrder) {
                throw new \ClientFence\InformationException('Order not found');
            }
            // ClientFence core patch: only a running service renews; a cancelled one was charged and stayed cancelled.
            if (empty($model->period) || !in_array($model->status, [\Model_ClientOrder::STATUS_ACTIVE, \Model_ClientOrder::STATUS_SUSPENDED], true)) {
                throw new \ClientFence\InformationException('Only an active or suspended service can be renewed.');
            }
            // ClientFence core patch: a scheduled gateway cancellation still ends the service at its original expiry.
            if ($this->getDi()['dbal']->fetchOne('SELECT 1 FROM client_order_meta WHERE client_order_id = ? AND name = ? AND value = ?', [$model->id, \ClientFence\Mod\Order\Service::META_CANCEL_AT_PERIOD_END, '1'])) {
                throw new \ClientFence\InformationException('This service is set to be cancelled at the end of its period, so it cannot be renewed.');
            }
            if ($model->expires_at !== null && $model->expires_at !== $seenExpiry) {
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

    /**
     * ClientFence: the client's saved methods for the Payment methods page (SavedCards.php): {available, cards: [{id,
     * card, default}], paypal: {account, default} or null, autopay: {account, default, add} or null, paytabs: {account,
     * default, add} or null}. available is false without an enabled Stripe gateway, paypal null when clients cannot save PayPal
     * accounts, autopay null without PayPal Auto-Pay (add: the client may set it up here), paytabs null without PayTabs (add: the same);
     * default marks the method that pays the invoices.
     */
    public function card_list(): array
    {
        $di = $this->getDi();
        $clientId = (int) $this->getIdentity()->id;
        $stripe = SavedCards::stripe($di);
        $paypal = SavedCards::paypal($di);
        $paying = SavedCards::method($di, $clientId)['adapter'] ?? null;
        $cards = $stripe?->cards($clientId) ?? [];
        foreach ($cards as &$card) {
            $card['default'] = $card['default'] && $paying instanceof \Payment_Adapter_Stripe;
        }
        $account = $paypal?->savedCard($clientId);
        $autopay = SavedCards::autopay($di);
        $agreement = $autopay?->savedCard($clientId);
        $paytabs = SavedCards::paytabs($di);
        $paytabsCard = $paytabs?->savedCard($clientId);

        return [
            'available' => $stripe !== null,
            'bank' => $stripe?->bankDebit($clientId) ?? false,
            'cards' => $cards,
            'paypal' => $paypal === null ? null : ['account' => $account['card'] ?? null, 'default' => $account !== null && $paying instanceof \Payment_Adapter_PayPalEmail],
            'autopay' => $autopay === null ? null : ['account' => $agreement['card'] ?? null, 'default' => $agreement !== null && $paying instanceof \Payment_Adapter_PayPalAutoPay, 'add' => $autopay->option('save_only')],
            'paytabs' => $paytabs === null ? null : ['account' => $paytabsCard['card'] ?? null, 'default' => $paytabsCard !== null && $paying instanceof \Payment_Adapter_PayTabs, 'add' => $paytabs->option('save_only')],
        ];
    }

    /** ClientFence: whether saved cards or saved PayPal accounts can be used here, for the client menu. */
    public function card_available(): bool
    {
        return SavedCards::adapters($this->getDi()) !== [];
    }

    /** ClientFence: makes the saved PayPal account the method that pays the client's invoices. */
    public function paypal_default(): bool
    {
        $adapter = $this->paypalAdapter();
        if ($adapter->savedCard((int) $this->getIdentity()->id) === null) {
            throw new \ClientFence\InformationException('You have no saved PayPal account.');
        }
        SavedCards::choose($this->getDi(), $adapter->gatewayId(), $adapter->testMode(), (int) $this->getIdentity()->id);

        return true;
    }

    /** ClientFence: removes the saved PayPal account. */
    public function paypal_remove(): bool
    {
        $this->paypalAdapter()->vaultRemove((int) $this->getIdentity()->id);

        return true;
    }

    /** ClientFence: pays the client's own unpaid invoice (`hash`) at once with their saved PayPal account, as autopay_pay (GitHub #6). */
    #[RequiredParams(['hash' => 'Invoice hash was not passed'])]
    public function paypal_pay($data): array
    {
        $di = $this->getDi();
        $adapter = $this->paypalAdapter();
        $clientId = (int) $this->getIdentity()->id;
        $user = \ClientFence\Mod\Client\Users::context($di);
        if (($user !== null && !$user['owner']) || $adapter->savedCard($clientId) === null) {
            throw new \ClientFence\InformationException('You cannot pay this invoice with a saved PayPal account.');
        }
        $invoice = $di['db']->findOne('Invoice', 'hash = ? AND client_id = ? AND status = ?', [(string) $data['hash'], $clientId, \Model_Invoice::STATUS_UNPAID])
            ?? throw new \ClientFence\InformationException('This invoice is already paid or cancelled.');
        $result = SavedCards::charge($di, $invoice, $adapter, true, true);

        return ['paid' => $result['paid'], 'message' => (string) ($result['error'] ?? '')];
    }

    /** ClientFence: makes the PayPal billing agreement the method that pays the client's invoices. */
    public function autopay_default(): bool
    {
        $adapter = $this->autopayAdapter();
        if ($adapter->savedCard((int) $this->getIdentity()->id) === null) {
            throw new \ClientFence\InformationException('You have not set up automatic payments with PayPal.');
        }
        SavedCards::choose($this->getDi(), $adapter->gatewayId(), $adapter->testMode(), (int) $this->getIdentity()->id);

        return true;
    }

    /** ClientFence: stops automatic payments with PayPal (the billing agreement is cancelled at PayPal). */
    public function autopay_remove(): bool
    {
        $this->autopayAdapter()->agreementRemove((int) $this->getIdentity()->id);

        return true;
    }

    /**
     * ClientFence: pays the client's own unpaid invoice (`hash`) at once with their PayPal billing agreement, as WHMCS
     * offers on the invoice: {paid, message}. As a staff charge, it counts no automatic try and sends no email.
     */
    #[RequiredParams(['hash' => 'Invoice hash was not passed'])]
    public function autopay_pay($data): array
    {
        $di = $this->getDi();
        $adapter = $this->autopayAdapter();
        $clientId = (int) $this->getIdentity()->id;
        $user = \ClientFence\Mod\Client\Users::context($di);
        if (($user !== null && !$user['owner']) || !$adapter->option('pay_saved') || $adapter->savedCard($clientId) === null) {
            throw new \ClientFence\InformationException('You cannot pay this invoice with a saved PayPal account.');
        }
        $invoice = $di['db']->findOne('Invoice', 'hash = ? AND client_id = ? AND status = ?', [(string) $data['hash'], $clientId, \Model_Invoice::STATUS_UNPAID])
            ?? throw new \ClientFence\InformationException('This invoice is already paid or cancelled.');
        $result = SavedCards::charge($di, $invoice, $adapter, true, true);

        return ['paid' => $result['paid'], 'message' => (string) ($result['error'] ?? '')];
    }

    /** ClientFence: makes the card saved at PayTabs the method that pays the client's invoices. */
    public function paytabs_default(): bool
    {
        $adapter = $this->paytabsAdapter();
        if ($adapter->savedCard((int) $this->getIdentity()->id) === null) {
            throw new \ClientFence\InformationException('You have no card saved with PayTabs.');
        }
        SavedCards::choose($this->getDi(), $adapter->gatewayId(), $adapter->testMode(), (int) $this->getIdentity()->id);

        return true;
    }

    /** ClientFence: removes the card saved at PayTabs (deleted at PayTabs too). */
    public function paytabs_remove(): bool
    {
        $this->paytabsAdapter()->cardRemove((int) $this->getIdentity()->id);

        return true;
    }

    /** ClientFence: pays the client's own unpaid invoice (`hash`) at once with their card saved at PayTabs, as autopay_pay. */
    #[RequiredParams(['hash' => 'Invoice hash was not passed'])]
    public function paytabs_pay($data): array
    {
        $di = $this->getDi();
        $adapter = $this->paytabsAdapter();
        $clientId = (int) $this->getIdentity()->id;
        $user = \ClientFence\Mod\Client\Users::context($di);
        if (($user !== null && !$user['owner']) || !$adapter->option('pay_saved') || $adapter->savedCard($clientId) === null) {
            throw new \ClientFence\InformationException('You cannot pay this invoice with a saved card.');
        }
        $invoice = $di['db']->findOne('Invoice', 'hash = ? AND client_id = ? AND status = ?', [(string) $data['hash'], $clientId, \Model_Invoice::STATUS_UNPAID])
            ?? throw new \ClientFence\InformationException('This invoice is already paid or cancelled.');
        $result = SavedCards::charge($di, $invoice, $adapter, true, true);

        return ['paid' => $result['paid'], 'message' => (string) ($result['error'] ?? '')];
    }

    /** ClientFence: starts adding a card: {client_secret, pub_key} for Stripe's card form. */
    public function card_setup(): array
    {
        return $this->cardAdapter()->cardSetup((int) $this->getIdentity()->id);
    }

    /** ClientFence: keeps the card of a confirmed setup (`setup_intent`), the default one when there is none. */
    #[RequiredParams(['setup_intent' => 'Setup ID was not passed'])]
    public function card_added($data): bool
    {
        $this->cardAdapter()->cardAdded((int) $this->getIdentity()->id, (string) $data['setup_intent']);

        return true;
    }

    /** ClientFence: makes a saved card (`id`) the one invoices are charged to. */
    #[RequiredParams(['id' => 'Card ID was not passed'])]
    public function card_default($data): bool
    {
        $this->cardAdapter()->cardDefault((int) $this->getIdentity()->id, (string) $data['id']);

        return true;
    }

    /** ClientFence: removes a saved card (`id`). */
    #[RequiredParams(['id' => 'Card ID was not passed'])]
    public function card_remove($data): bool
    {
        $this->cardAdapter()->cardRemove((int) $this->getIdentity()->id, (string) $data['id']);

        return true;
    }

    private function paypalAdapter(): \Payment_Adapter_PayPalEmail
    {
        return SavedCards::paypal($this->getDi()) ?? throw new \ClientFence\InformationException('Saved PayPal accounts are not available.');
    }

    private function autopayAdapter(): \Payment_Adapter_PayPalAutoPay
    {
        return SavedCards::autopay($this->getDi()) ?? throw new \ClientFence\InformationException('Automatic payments with PayPal are not available.');
    }

    private function paytabsAdapter(): \Payment_Adapter_PayTabs
    {
        return SavedCards::paytabs($this->getDi()) ?? throw new \ClientFence\InformationException('PayTabs is not available.');
    }

    private function cardAdapter(): \Payment_Adapter_Stripe
    {
        return SavedCards::stripe($this->getDi()) ?? throw new \ClientFence\InformationException('Saved cards are not available.');
    }
}
