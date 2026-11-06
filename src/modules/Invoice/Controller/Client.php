<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Invoice\Controller;

use ClientFence\InformationException;
use Symfony\Component\HttpFoundation\Response;

class Client implements \ClientFence\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function register(\ClientFence\App &$app): void
    {
        $app->get('/invoice', 'get_invoices', [], static::class);
        $app->post('/invoice', 'get_invoices', [], static::class);
        // ClientFence: the account statement and its PDF (Statement.php), before /invoice/:hash.
        $app->get('/invoice/statement', 'get_statement', [], static::class);
        $app->get('/invoice/statement/pdf', 'get_statement_pdf', [], static::class);
        // ClientFence: the client's quotes, and a quote by its link (Quotes.php).
        $app->get('/invoice/quotes', 'get_quotes', [], static::class);
        $app->get('/quote/:hash', 'get_quote', ['hash' => '[a-f0-9]{40}'], static::class);
        $app->get('/quote/:hash/pdf', 'get_quote_pdf', ['hash' => '[a-f0-9]{40}'], static::class);
        $app->get('/invoice/:hash', 'get_invoice', ['hash' => '[a-z0-9]+'], static::class);
        $app->post('/invoice/:hash', 'get_invoice', ['hash' => '[a-z0-9]+'], static::class);
        $app->get('/invoice/print/:hash', 'get_invoice_print', ['hash' => '[a-z0-9]+'], static::class);
        $app->post('/invoice/print/:hash', 'get_invoice_print', ['hash' => '[a-z0-9]+'], static::class);
        $app->get('/invoice/banklink/:hash/:id', 'get_banklink', ['id' => '[0-9]+', 'hash' => '[a-z0-9]+'], static::class);
        $app->get('/invoice/thank-you/:hash', 'get_thankyoupage', ['hash' => '[a-z0-9]+'], static::class);
        $app->post('/invoice/thank-you/:hash', 'get_thankyoupage', ['hash' => '[a-z0-9]+'], static::class);
        $app->get('/invoice/pdf/:hash', 'get_pdf', ['hash' => '[a-z0-9]+'], static::class);
        // ClientFence: a payment receipt (Receipts.php), linked from its support ticket.
        $app->get('/receipt/:id', 'get_receipt', ['id' => '[a-f0-9]{32}'], static::class);
        // ClientFence: the client's saved cards (SavedCards.php).
        $app->get('/payment-methods', 'get_payment_methods', [], static::class);
    }

    public function get_payment_methods(\ClientFence\App $app): string
    {
        $this->di['is_client_logged'];

        return $app->render('mod_invoice_payment_methods', ['cards' => $this->di['api_client']->invoice_card_list()]);
    }

    public function get_invoices(\ClientFence\App $app): string
    {
        $this->di['is_client_logged'];

        return $app->render('mod_invoice_index');
    }

    public function get_invoice(\ClientFence\App $app, $hash): string|Response
    {
        $data = [
            'hash' => $hash,
        ];
        $invoice = $this->getInvoiceOrRedirect($app, $data);
        if ($invoice instanceof Response) {
            return $invoice;
        }

        return $app->render('mod_invoice_invoice', ['invoice' => $invoice]);
    }

    public function get_invoice_print(\ClientFence\App $app, $hash): string|Response
    {
        $data = [
            'hash' => $hash,
        ];
        $invoice = $this->getInvoiceOrRedirect($app, $data);
        if ($invoice instanceof Response) {
            return $invoice;
        }

        return $app->render('mod_invoice_print', ['invoice' => $invoice]);
    }

    public function get_thankyoupage(\ClientFence\App $app, $hash): string|Response
    {
        $data = [
            'hash' => $hash,
        ];
        $invoice = $this->getInvoiceOrRedirect($app, $data);
        if ($invoice instanceof Response) {
            return $invoice;
        }

        return $app->render('mod_invoice_thankyou', ['invoice' => $invoice]);
    }

    public function get_banklink(\ClientFence\App $app, $hash, $id): string|Response
    {
        $data = [
            'allow_subscription' => $app->getRequest()->query->getBoolean('allow_subscription', true),
            'hash' => $hash,
            'gateway_id' => $id,
            'auto_redirect' => true,
        ];

        $api = $this->di['api_guest'];
        $invoice = $this->getInvoiceOrRedirect($app, $data);
        if ($invoice instanceof Response) {
            return $invoice;
        }

        try {
            $payment = $api->invoice_payment($data);
        } catch (InformationException $e) {
            if ($this->isInvoiceAccessDenied($e)) {
                return $app->redirect('invoice');
            }

            throw $e;
        }

        return $app->render('mod_invoice_banklink', ['payment' => $payment, 'invoice' => $invoice]);
    }

    public function get_pdf(\ClientFence\App $app, $hash): Response
    {
        $api = $this->di['api_guest'];
        $data = [
            'hash' => $hash,
        ];

        try {
            $response = $api->invoice_pdf($data);
        } catch (InformationException $e) {
            if ($this->isInvoiceAccessDenied($e)) {
                return $app->redirect('invoice');
            }

            throw $e;
        }

        if (!$response instanceof Response) {
            throw new \ClientFence\Exception('Invoice PDF response could not be generated');
        }

        return $response;
    }

    private function getInvoiceOrRedirect(\ClientFence\App $app, array $data): array|Response
    {
        $api = $this->di['api_guest'];

        try {
            return $api->invoice_get($data);
        } catch (InformationException $e) {
            if ($this->isInvoiceAccessDenied($e)) {
                return $app->redirect('invoice');
            }

            throw $e;
        }
    }

    private function isInvoiceAccessDenied(InformationException $e): bool
    {
        return (int) $e->getCode() === 403;
    }

    /**
     * ClientFence: a payment receipt (Receipts.php). It opens for the client who sent it and for
     * staff with support permission (admin and client sign-ins share one session), so the ticket
     * never shows the admin area's address. Anyone else gets "not found", and a visitor who is not
     * signed in goes to the sign-in page.
     */
    public function get_receipt(\ClientFence\App $app, $id): Response
    {
        $auth = $this->di['auth'];
        $receipt = \ClientFence\Mod\Invoice\Receipts::find((string) $id);
        if ($auth->isClientLoggedIn() && $receipt !== null
            && (int) ($receipt['meta']['client_id'] ?? 0) === (int) $this->di['loggedin_client']->id) {
            return \ClientFence\Mod\Invoice\Receipts::response($receipt);
        }
        if ($auth->isAdminLoggedIn()) {
            // ClientFence core patch: staff receipt downloads require permission to view their support tickets.
            $this->di['mod_service']('staff')->checkPermissionsAndThrowException('support', 'view');
            if ($receipt !== null) {
                return \ClientFence\Mod\Invoice\Receipts::response($receipt);
            }
        } else {
            // Not signed in: the sign-in page, then back here.
            $this->di['is_client_logged'];
        }

        return new Response('Receipt not found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * ClientFence: the account statement page; dates that are not valid show their message and
     * the whole account is shown.
     */
    public function get_statement(\ClientFence\App $app): string
    {
        $this->di['is_client_logged'];
        $api = $this->di['api_client'];
        $dates = $this->statementDates();
        $error = null;
        try {
            $statement = $api->invoice_statement($dates);
        } catch (\ClientFence\InformationException $e) {
            $error = $e->getMessage();
            $dates = [];
            $statement = $api->invoice_statement([]);
        }

        return $app->render('mod_invoice_statement', ['statement' => $statement, 'error' => $error, 'dates' => $dates]);
    }

    /**
     * ClientFence: the client's quotes.
     */
    public function get_quotes(\ClientFence\App $app): string
    {
        $this->di['is_client_logged'];

        return $app->render('mod_invoice_quotes', ['quotes' => $this->di['api_client']->invoice_quote_get_list([])]);
    }

    /**
     * ClientFence: a quote by its link, for the client or prospect it is for (signed in or not):
     * view, accept or decline it, download the PDF.
     */
    public function get_quote(\ClientFence\App $app, $hash): string
    {
        return $app->render('mod_invoice_quote', ['quote' => $this->di['api_guest']->invoice_quote_get(['hash' => $hash])]);
    }

    public function get_quote_pdf(\ClientFence\App $app, $hash): Response
    {
        return $this->di['api_guest']->invoice_quote_pdf(['hash' => $hash]);
    }

    /**
     * ClientFence: the account statement as a PDF in the client's language.
     */
    public function get_statement_pdf(\ClientFence\App $app): \Symfony\Component\HttpFoundation\Response
    {
        $this->di['is_client_logged'];

        return $this->di['api_client']->invoice_statement_pdf($this->statementDates());
    }

    /** `from` and `to` in the query string (text values only). */
    private function statementDates(): array
    {
        $dates = [];
        $query = $this->di['request']->query->all();
        foreach (['from', 'to'] as $name) {
            if (isset($query[$name]) && is_string($query[$name]) && $query[$name] !== '') {
                $dates[$name] = mb_substr($query[$name], 0, 20);
            }
        }

        return $dates;
    }
}
