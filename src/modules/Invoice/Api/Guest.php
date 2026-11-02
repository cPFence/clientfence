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
 * Invoice processing.
 */

namespace ClientFence\Mod\Invoice\Api;

use ClientFence\Mod\Invoice\InvoiceOperation;
use ClientFence\Validation\Api\RequiredParams;

class Guest extends \ClientFence\Api\AbstractApi
{
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
        if (!preg_match('/^[a-f0-9]{30,60}$/', (string) $data['hash'])) {
            // ClientFence core patch: 404 (was 4001, a server error on the invoice page) for a malformed link.
            throw new \ClientFence\InformationException('Invalid invoice hash', null, 404);
        }

        $this->limitUnlessOwner('invoice_get', (string) $data['hash']);

        $model = $this->getDi()['db']->findOne('Invoice', 'hash = :hash', ['hash' => $data['hash']]);
        if (!$model) {
            throw new \ClientFence\InformationException('Invoice was not found', null, 404);
        }
        $service = $this->getService();
        $service->checkInvoiceAuth($model, InvoiceOperation::READ);

        return $service->toApiArray($model, true, $this->getIdentity());
    }

    /**
     * Get list of available payment gateways to pay for invoices.
     *
     * @optional string $format - if format is "pairs" then id=>name values are returned
     *
     * @return array
     */
    public function gateways($data)
    {
        $gatewayService = $this->getDi()['mod_service']('Invoice', 'PayGateway');

        return $gatewayService->getActive($data);
    }

    /**
     * Process invoice for selected gateway. Returned result can be processed
     * to redirect or to show required information. Returned result depends
     * on payment gateway.
     *
     * Tries to detect if invoice can be subscribed and if payment gateway supports subscriptions
     * uses subscription payment.
     *
     * @optional bool $auto_redirect - should payment adapter automatically redirect client or just print pay now button
     *
     * @return array
     *
     * @throws \ClientFence\Exception
     */
    public function payment($data)
    {
        if (empty($data['hash'])) {
            throw new \ClientFence\Exception('Invoice hash not passed. Missing param hash', null, 810);
        }

        if (!preg_match('/^[a-f0-9]{30,60}$/', (string) $data['hash'])) {
            throw new \ClientFence\Exception('Invalid invoice hash', null, 4001);
        }

        if (empty($data['gateway_id'])) {
            throw new \ClientFence\InformationException('Payment method not found. Missing param gateway_id', null, 811);
        }

        $this->limitUnlessOwner('invoice_payment', (string) $data['hash']);

        return $this->getService()->processInvoice($data);
    }

    /**
     * Get whether the client add funds feature is enabled, or not.
     */
    public function funds_enabled(): bool
    {
        return $this->getService()->isFundsEnabled();
    }

    /**
     * Generates PDF for given invoice.
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['hash' => 'Invoice hash was not passed'])]
    public function pdf($data)
    {
        if (!preg_match('/^[a-f0-9]{30,60}$/', (string) $data['hash'])) {
            throw new \ClientFence\Exception('Invalid invoice hash', null, 4001);
        }

        $this->limitUnlessOwner('invoice_pdf', (string) $data['hash']);

        return $this->getService()->generatePDF($data['hash'], $this->getIdentity());
    }

    /**
     * ClientFence: payment methods for an amount, as the theme's payment cards show them (see
     * Receipts::paymentMethods()).
     */
    public function payment_methods($data): array
    {
        $amount = filter_var($data['amount'] ?? 0, FILTER_VALIDATE_FLOAT);
        $currency = strtoupper((string) ($data['currency'] ?? ''));
        if ($amount === false || $amount < 0 || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new \ClientFence\InformationException('Amount or currency is not valid.');
        }

        return \ClientFence\Mod\Invoice\Receipts::paymentMethods($this->getDi(), (float) $amount, $currency, (bool) ($data['subscribable'] ?? false));
    }

    /**
     * ClientFence: a quote by its link (Quotes.php), as its page shows it: no admin notes. `mine`:
     * the signed-in client is the one it is for; `invoice_url` then opens the invoice it made.
     */
    #[RequiredParams(['hash' => 'Quote hash was not passed'])]
    public function quote_get($data): array
    {
        $quote = $this->quoteByHash($data);
        $mine = $quote['client_id'] !== null && $quote['client_id'] === $this->signedInClient();
        $invoiceHash = $mine && $quote['invoice_id'] !== null ? $this->getDi()['db']->getCell('SELECT hash FROM invoice WHERE id = :id', [':id' => $quote['invoice_id']]) : null;

        return \ClientFence\Mod\Invoice\Quotes::forViewer($quote) + [
            'mine' => $mine,
            'invoice_url' => $invoiceHash ? $this->getDi()['url']->link('invoice/' . $invoiceHash) : null,
        ];
    }

    /**
     * ClientFence: accepts a quote on its page (Quotes::answer()): a prospect gets an account and
     * the invoice is made and emailed.
     */
    #[RequiredParams(['hash' => 'Quote hash was not passed'])]
    public function quote_accept($data): bool
    {
        $this->quoteAnswer($data, true);

        return true;
    }

    /** ClientFence: declines a quote on its page (Quotes::answer()): it becomes lost. */
    #[RequiredParams(['hash' => 'Quote hash was not passed'])]
    public function quote_decline($data): bool
    {
        $this->quoteAnswer($data, false);

        return true;
    }

    /** ClientFence: a quote as a PDF in the reader's language (QuotePdf.php). */
    #[RequiredParams(['hash' => 'Quote hash was not passed'])]
    public function quote_pdf($data): \Symfony\Component\HttpFoundation\Response
    {
        $this->getDi()['rate_limiter']->consumeOrThrow('quote_ip', (string) $this->getIp());

        return \ClientFence\Mod\Invoice\QuotePdf::response($this->getDi(), \ClientFence\Mod\Invoice\Quotes::forViewer($this->quoteByHash($data)), \Locale::getDefault());
    }

    private function quoteAnswer(array $data, bool $accept): void
    {
        $this->getDi()['rate_limiter']->consumeOrThrow('quote_ip', (string) $this->getIp());
        $quote = $this->quoteByHash($data);
        // ClientFence core patch: signed-in account users need quote access to answer their account's quotes.
        $context = \ClientFence\Mod\Client\Users::context($this->getDi());
        if ($context !== null && $quote['client_id'] === $context['client_id'] && !\ClientFence\Mod\Client\Users::allows($context, 'quotes')) {
            throw new \ClientFence\InformationException('You do not have permission to do this. Ask the account owner for access.', null, 403);
        }
        \ClientFence\Mod\Invoice\Quotes::answer($this->getDi(), (string) $data['hash'], $accept, $this->signedInClient(), (string) $this->getIp());
    }

    private function quoteByHash(array $data): array
    {
        \ClientFence\Mod\Invoice\Quotes::ensure($this->getDi());
        $quote = \ClientFence\Mod\Invoice\Quotes::findByHash($this->getDi(), (string) $data['hash']);
        if ($quote === null) {
            throw new \ClientFence\InformationException('Quote not found', [], 404);
        }

        return $quote;
    }

    /** The client signed in to this session, if any (an authorized user acts for the account). */
    private function signedInClient(): ?int
    {
        $clientId = (int) ($this->getDi()['session']->get('client_id') ?? 0);

        return $clientId > 0 ? $clientId : null;
    }

    /**
     * ClientFence core patch: the limits slow the guessing of invoice links. The signed-in client's own invoices are
     * not limited (as in WHMCS): the client area opens them through these guest routes, and 10 views an hour per
     * address broke the invoice page for everyone behind that address.
     */
    private function limitUnlessOwner(string $limit, string $hash): void
    {
        $di = $this->getDi();
        if ($di['auth']->isClientLoggedIn() && (int) $di['dbal']->fetchOne('SELECT client_id FROM invoice WHERE hash = ?', [$hash]) === (int) $di['loggedin_client']->id) {
            return;
        }
        $di['rate_limiter']->consumeOrThrow($limit . '_ip', (string) $this->getIp());
        $di['rate_limiter']->consumeOrThrow($limit . '_hash', $hash);
    }
}
