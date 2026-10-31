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

use Symfony\Component\HttpFoundation\Response;

class Admin implements \ClientFence\InjectionAwareInterface
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

    public function fetchNavigation(): array
    {
        return [
            'group' => [
                'index' => 400,
                'location' => 'invoice',
                'label' => __trans('Invoices'),
                'uri' => $this->di['url']->adminLink('invoice'),
                'class' => 'receipt',
            ],
            'subpages' => [
                [
                    'location' => 'invoice',
                    'label' => __trans('Overview'),
                    'uri' => $this->di['url']->adminLink('invoice'),
                    'index' => 100,
                    'class' => '',
                ],
                [
                    'location' => 'invoice',
                    'label' => __trans('Subscriptions'),
                    'uri' => $this->di['url']->adminLink('invoice/subscriptions'),
                    'index' => 300,
                    'class' => '',
                ],
                [
                    'location' => 'invoice',
                    'label' => __trans('Transactions'),
                    'uri' => $this->di['url']->adminLink('invoice/transactions'),
                    'index' => 400,
                    'class' => '',
                ],
                [
                    'location' => 'invoice',
                    'label' => __trans('Failed Items'),
                    'uri' => $this->di['url']->adminLink('invoice/failed-items'),
                    'index' => 500,
                    'class' => '',
                ],
                // ClientFence core patch: quotes, as WHMCS's (Invoice/Quotes.php).
                [
                    'location' => 'invoice',
                    'label' => __trans('Quotes'),
                    'uri' => $this->di['url']->adminLink('invoice/quotes'),
                    'index' => 550,
                    'class' => '',
                ],
                // ClientFence core patch: the Reports page, as WHMCS's (Invoice/Reports.php).
                [
                    'location' => 'invoice',
                    'label' => __trans('Reports'),
                    'uri' => $this->di['url']->adminLink('invoice/reports'),
                    'index' => 600,
                    'class' => '',
                ],
                [
                    'location' => 'system',
                    'label' => __trans('Tax Rules'),
                    'uri' => $this->di['url']->adminLink('invoice/tax'),
                    'index' => 180,
                    'class' => '',
                ],
                [
                    'location' => 'system',
                    'label' => __trans('Payment Gateways'),
                    'uri' => $this->di['url']->adminLink('invoice/gateways'),
                    'index' => 160,
                    'class' => '',
                ],
            ],
        ];
    }

    public function register(\ClientFence\App &$app): void
    {
        $app->get('/invoice', 'get_index', [], static::class);
        $app->get('/invoice/subscriptions', 'get_subscriptions', [], static::class);
        $app->get('/invoice/transactions', 'get_transactions', [], static::class);
        $app->get('/invoice/failed-items', 'get_failed_items', [], static::class);
        // ClientFence: the quotes, a quote (0: a new one) and its PDF (Quotes.php).
        $app->get('/invoice/quotes', 'get_quotes', [], static::class);
        $app->get('/invoice/quote/:id', 'get_quote', ['id' => '[0-9]+'], static::class);
        $app->get('/invoice/quote/:id/pdf', 'get_quote_pdf', ['id' => '[0-9]+'], static::class);
        // ClientFence: the Reports page, a report, and a report as CSV (Reports.php).
        $app->get('/invoice/reports', 'get_reports', [], static::class);
        $app->get('/invoice/reports/:report', 'get_report', ['report' => '[a-z_]+'], static::class);
        $app->get('/invoice/reports/:report/csv', 'get_report_csv', ['report' => '[a-z_]+'], static::class);
        // ClientFence: the Client Statement report as a PDF (StatementPdf.php).
        $app->get('/invoice/reports/statement/pdf', 'get_statement_pdf', [], static::class);
        $app->get('/invoice/gateways', 'get_gateways', [], static::class);
        $app->get('/invoice/gateway/:id', 'get_gateway', ['id' => '[0-9]+'], static::class);
        $app->get('/invoice/manage/:id', 'get_invoice', ['id' => '[0-9]+'], static::class);
        $app->get('/invoice/transaction/:id', 'get_transaction', ['id' => '[0-9]+'], static::class);
        $app->get('/invoice/subscription/:id', 'get_subscription', ['id' => '[0-9]+'], static::class);
        $app->get('/invoice/tax', 'get_taxes', [], static::class);
        $app->get('/invoice/tax/:id', 'get_tax', [], static::class);
        $app->get('/invoice/pdf/:hash', 'get_pdf', ['hash' => '[a-z0-9]+'], static::class);
        // ClientFence: a payment receipt for staff with support permission (Receipts.php).
        $app->get('/invoice/receipt/:id', 'get_receipt', ['id' => '[a-f0-9]{32}'], static::class);
    }

    public function get_taxes(\ClientFence\App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_invoice_tax');
    }

    public function get_tax(\ClientFence\App $app, $id): string
    {
        $api = $this->di['api_admin'];
        $tax = $api->invoice_tax_get(['id' => $id]);

        return $app->render('mod_invoice_taxupdate', ['tax' => $tax]);
    }

    public function get_index(\ClientFence\App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_invoice_index');
    }

    public function get_invoice(\ClientFence\App $app, $id): string
    {
        $api = $this->di['api_admin'];
        $invoice = $api->invoice_get(['id' => $id]);

        return $app->render('mod_invoice_invoice', ['invoice' => $invoice]);
    }

    public function get_transaction(\ClientFence\App $app, $id): string
    {
        $api = $this->di['api_admin'];
        $tx = $api->invoice_transaction_get(['id' => $id]);

        return $app->render('mod_invoice_transaction', ['transaction' => $tx]);
    }

    public function get_transactions(\ClientFence\App $app): string|Response
    {
        $this->di['is_admin_logged'];
        // ClientFence core patch: a clean 403 for staff without "manage_transactions" (the page crashed).
        if (!$this->di['mod_service']('Staff')->hasPermission(null, 'invoice', 'manage_transactions')) {
            return $app->errorResponse(new \ClientFence\InformationException('You do not have permission to perform this action', [], 403), 403);
        }

        return $app->render('mod_invoice_transactions');
    }

    public function get_failed_items(\ClientFence\App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_invoice_failed_items');
    }

    public function get_subscriptions(\ClientFence\App $app): string|Response
    {
        $this->di['is_admin_logged'];
        // ClientFence core patch: a clean 403 for staff without "manage_subscriptions" (the page crashed).
        if (!$this->di['mod_service']('Staff')->hasPermission(null, 'invoice', 'manage_subscriptions')) {
            return $app->errorResponse(new \ClientFence\InformationException('You do not have permission to perform this action', [], 403), 403);
        }

        return $app->render('mod_invoice_subscriptions');
    }

    public function get_subscription(\ClientFence\App $app, $id): string
    {
        $api = $this->di['api_admin'];
        $tx = $api->invoice_subscription_get(['id' => $id]);

        return $app->render('mod_invoice_subscription', ['subscription' => $tx]);
    }

    public function get_gateways(\ClientFence\App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_invoice_gateways');
    }

    public function get_gateway(\ClientFence\App $app, $id): string
    {
        $api = $this->di['api_admin'];
        $gateway = $api->invoice_gateway_get(['id' => $id]);

        return $app->render('mod_invoice_gateway', ['gateway' => $gateway]);
    }

    public function get_pdf(\ClientFence\App $app, $hash): Response
    {
        $this->di['is_admin_logged'];
        // ClientFence core patch: invoice PDF downloads require the same access as invoice details.
        $this->di['mod_service']('staff')->checkPermissionsAndThrowException('invoice', 'view');
        // ClientFence: a query value must name an installed, enabled language before reaching the translator.
        $locale = $app->getRequest()->query->get('lang');
        if ($locale !== null && !in_array($locale, \ClientFence\i18n::getLocales(), true)) {
            throw new \ClientFence\InformationException('Language not found', [], 400);
        }

        return $this->di['mod_service']('invoice')->generatePDF($hash, $this->di['loggedin_admin'], $locale);
    }

    /**
     * ClientFence: a payment receipt a client uploaded (Receipts.php), for staff with support
     * permission (and, like every Invoice admin page, access to invoices). The receipt's support
     * ticket links to the client area page, which needs the support permission only.
     */
    public function get_receipt(\ClientFence\App $app, $id): Response
    {
        $this->di['is_admin_logged'];
        $this->di['mod_service']('staff')->checkPermissionsAndThrowException('support');

        $receipt = \ClientFence\Mod\Invoice\Receipts::find((string) $id);
        if ($receipt === null) {
            return new Response('Receipt not found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return \ClientFence\Mod\Invoice\Receipts::response($receipt);
    }

    /**
     * ClientFence: the quotes (Quotes.php).
     */
    public function get_quotes(\ClientFence\App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_invoice_quotes');
    }

    /**
     * ClientFence: a quote to edit, or a new one (id 0; ?client_id= picks the client).
     */
    public function get_quote(\ClientFence\App $app, $id): string
    {
        $this->di['is_admin_logged'];
        $quote = (int) $id > 0 ? $this->di['api_admin']->invoice_quote_get(['id' => (int) $id]) : null;

        return $app->render('mod_invoice_quote', ['quote' => $quote]);
    }

    /**
     * ClientFence: a quote as a PDF (QuotePdf.php).
     */
    public function get_quote_pdf(\ClientFence\App $app, $id): Response
    {
        $this->di['is_admin_logged'];

        return $this->di['api_admin']->invoice_quote_pdf(['id' => (int) $id]);
    }

    /**
     * ClientFence: the Reports page (Reports.php).
     */
    public function get_reports(\ClientFence\App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_invoice_reports', ['catalog' => $this->di['api_admin']->invoice_report_catalog([])]);
    }

    /**
     * ClientFence: one report, run with the filters in the query string; a filter that is not
     * valid shows its message above the form and the report runs with its defaults.
     */
    public function get_report(\ClientFence\App $app, $report): string
    {
        $this->di['is_admin_logged'];
        $api = $this->di['api_admin'];
        $params = $this->reportParams();
        $error = null;
        try {
            $result = $api->invoice_report(['report' => $report] + $params);
        } catch (\ClientFence\InformationException $e) {
            if ($e->getCode() === 404 || $params === []) {
                throw $e;
            }
            $error = $e->getMessage();
            $result = $api->invoice_report(['report' => $report]);
        }

        return $app->render('mod_invoice_report', ['report' => $result, 'error' => $error]);
    }

    /**
     * ClientFence: a report as CSV (Reports::csv()).
     */
    public function get_report_csv(\ClientFence\App $app, $report): Response
    {
        $this->di['is_admin_logged'];

        return $this->di['api_admin']->invoice_report_csv(['report' => $report] + $this->reportParams());
    }

    /**
     * ClientFence: the Client Statement report as a PDF (StatementPdf.php).
     */
    public function get_statement_pdf(\ClientFence\App $app): Response
    {
        $this->di['is_admin_logged'];

        return $this->di['api_admin']->invoice_statement_pdf($this->reportParams());
    }

    /** The report filters in the query string (text values only). */
    private function reportParams(): array
    {
        $params = [];
        $query = $this->di['request']->query->all();
        foreach (['month', 'from', 'to', 'currency', 'client_id', 'min', 'max'] as $name) {
            $value = $query[$name] ?? null;
            if (is_string($value) && $value !== '') {
                $params[$name] = mb_substr($value, 0, 40);
            }
        }

        return $params;
    }
}
