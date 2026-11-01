<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */
class Payment_Adapter_ClientBalance implements ClientFence\InjectionAwareInterface
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

    public function __construct()
    {
    }

    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'logo' => [
                'logo' => 'clientbalance.png',
                'height' => '50px',
                'width' => '50px',
            ],
        ];
    }

    public function enoughInBalanceToCoverInvoice(Model_Invoice $invoice): bool
    {
        $clientModel = $this->di['db']->load('Client', $invoice->client_id);
        $clientBalanceService = $this->di['mod_service']('Client', 'Balance');
        $invoiceService = $this->di['mod_service']('Invoice');
        // ClientFence core patch: what other unpaid invoices have received is not spendable.
        $sumInBalance = $clientBalanceService->getClientBalance($clientModel) - $invoiceService->getReservedCredit((int) $invoice->client_id, (int) $invoice->id);

        $totalSumWithTaxes = $invoiceService->getTotalWithTax($invoice);
        if ($totalSumWithTaxes > $sumInBalance) {
            return false;
        }

        return true;
    }

    public function getHtml($api_admin, $invoice_id, $subscription): string
    {
        $invoiceModel = $this->di['db']->load('Invoice', $invoice_id);

        if (!$this->enoughInBalanceToCoverInvoice($invoiceModel)) {
            return __trans('Your account balance is insufficient to cover this invoice.');
        }

        $invoiceService = $this->di['mod_service']('Invoice');
        if ($invoiceService->isInvoiceTypeDeposit($invoiceModel)) {
            return __trans('You may not pay a deposit invoice with this payment gateway.');
        }

        $ipnUrl = $this->getServiceUrl($invoice_id);
        $invoiceUrl = $this->di['tools']->url('invoice/' . $invoiceModel->hash);

        return "<script nonce=\"" . \ClientFence\Csp::nonce() . "\">
                document.addEventListener('DOMContentLoaded', function() {
                    ClientFence.api.makeRequest('POST', '$ipnUrl', {}, function () {
                        window.location.href = '$invoiceUrl';
                    }, function (error) {
                        console.error('Payment callback failed:', error);
                        if (window.confirm('An error occurred while processing your request. Would you like to return to the invoice page?')) {
                            window.location.href = '$invoiceUrl';
                        }
                    }, false);
                });
                </script>";
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id): bool
    {
        if (!$this->isIpnValid($data)) {
            throw new Payment_Exception('IPN is invalid');
        }

        // ClientFence core patch: spending session credit requires a POST authenticated by the session CSRF token.
        if (!$this->di['request']->isMethod('POST')) {
            throw new ClientFence\InformationException('This action requires POST', null, 405);
        }
        $body = json_decode((string) ($data['http_raw_post_data'] ?? ''), true);
        $token = $body['CSRFToken'] ?? $data['post']['CSRFToken'] ??$this->di['request']->headers->get('X-CSRF-TOKEN');
        $sessionToken = $this->di['session']->get('csrf_token');
        if (!is_string($token) || !is_string($sessionToken) || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
            throw new ClientFence\InformationException('CSRF token invalid', null, 403);
        }

        $tx = $this->di['db']->load('Transaction', $id);

        if ($tx->invoice_id) {
            $invoice_id = $tx->invoice_id;
        } else {
            $invoice_id = $data['get']['invoice_id'] ?? 0;
        }

        $invoiceModel = $this->di['db']->load('Invoice', $invoice_id);
        if (!$invoiceModel instanceof Model_Invoice) {
            throw new Payment_Exception('Invoice not found');
        }

        if ((int) $invoiceModel->client_id !== (int) $this->di['loggedin_client']->id) {
            throw new Payment_Exception('You are not authorized to pay this invoice with client balance.');
        }

        // ClientFence core patch: the balance callback bypasses the client API's account-user permission gate.
        $context = ClientFence\Mod\Client\Users::context($this->di);
        if ($context !== null && !ClientFence\Mod\Client\Users::allows($context, 'invoices')) {
            throw new ClientFence\InformationException('You do not have permission to do this. Ask the account owner for access.', null, 403);
        }

        // ClientFence core patch: the invoice page can select balance without changing the saved gateway.
        $invoiceService = $this->di['mod_service']('Invoice');
        if ($invoiceService->isInvoiceTypeDeposit($invoiceModel)) {
            throw new Payment_Exception('You may not pay a deposit invoice with this payment gateway.', [], 303);
        }

        if ($invoice_id) {
            $invoiceService->payInvoiceWithCredits($invoiceModel);
        }
        $invoiceService->doBatchPayWithCredits(['client_id' => $invoiceModel->client_id]);

        $tx->error = '';
        $tx->error_code = null;
        $tx->status = 'processed';
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);

        return true;
    }

    public function isIpnValid($data): bool
    {
        return $this->di['auth']->isClientLoggedIn();
    }

    public function getServiceUrl($invoice_id = 0)
    {
        $gatewayModel = $this->di['db']->findOne('PayGateway', 'gateway = ? and enabled = 1', ['ClientBalance']);
        if (!$gatewayModel instanceof Model_PayGateway) {
            throw new Payment_Exception('ClientBalance gateway is not enabled', null, 301);
        }

        $invoiceModel = $this->di['db']->load('Invoice', $invoice_id);
        $invoiceService = $this->di['mod_service']('Invoice');
        if ($invoiceService->isInvoiceTypeDeposit($invoiceModel)) {
            throw new Payment_Exception('You may not pay a deposit invoice with this payment gateway.', null, 302);
        }

        $gatewayService = $this->di['mod_service']('Invoice', 'PayGateway');

        return $gatewayService->getCallbackUrl($gatewayModel, $invoiceModel);
    }
}
