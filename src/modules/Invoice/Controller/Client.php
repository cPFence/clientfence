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
        // ClientFence: saving a PayPal account (Invoice/SavedCards.php): on the Payment methods page, or while paying.
        $app->get('/payment-methods/paypal', 'get_paypal_save', [], static::class);
        $app->get('/payment-methods/paypal/saved', 'get_paypal_saved', [], static::class);
        $app->get('/invoice/paypal-save/:hash', 'get_invoice_paypal_save', ['hash' => '[a-z0-9]+'], static::class);
        $app->get('/invoice/paypal-paid', 'get_invoice_paypal_paid', [], static::class);
    }

    /** ClientFence: sends the client to PayPal to save their account. */
    public function get_paypal_save(\ClientFence\App $app): Response
    {
        $adapter = $this->paypalForClient();
        $setup = $adapter->vaultSetup((string) $this->di['url']->link('payment-methods/paypal/saved'), (string) $this->di['url']->link('payment-methods'));
        $this->paypalBind($setup['id'], ['client' => (int) $this->di['session']->get('client_id')]);

        return $app->redirectUrl($setup['url']);
    }

    /** ClientFence: back from PayPal: keeps the approved account for the client who started saving it, in this browser. */
    public function get_paypal_saved(\ClientFence\App $app): Response
    {
        $this->keepSessionCookie();
        $setupId = (string) $app->getRequest()->query->get('approval_token_id');
        $flow = $this->paypalFlow($setupId);
        $adapter = \ClientFence\Mod\Invoice\SavedCards::paypal($this->di);
        if ($flow === null || $adapter === null) {
            throw new InformationException('Your PayPal account was not saved. Please try again.');
        }
        $adapter->vaultSaved((int) $flow['client'], $setupId);
        $this->di['cache']->deleteItem(self::paypalKey($setupId));

        return $this->movesOn('payment-methods');
    }

    /** ClientFence: "Pay and save my PayPal account" on the client's own unpaid invoice. */
    public function get_invoice_paypal_save(\ClientFence\App $app, $hash): Response
    {
        $adapter = $this->paypalForClient();
        $invoice = $this->di['db']->findOne('Invoice', 'hash = ? AND client_id = ? AND status = ?', [(string) $hash, (int) $this->di['session']->get('client_id'), \Model_Invoice::STATUS_UNPAID]);
        if (!$invoice instanceof \Model_Invoice) {
            return $app->redirect('invoice');
        }
        $order = $adapter->vaultOrder($invoice, (string) $this->di['url']->link('invoice/paypal-paid'), (string) $this->di['url']->link('invoice/' . $invoice->hash));
        $this->paypalBind($order['id'], ['invoice' => (int) $invoice->id]);

        return $app->redirectUrl($order['url']);
    }

    /** ClientFence: back from PayPal: takes the payment of the approved order, records it and keeps the account. */
    public function get_invoice_paypal_paid(\ClientFence\App $app): Response
    {
        $this->keepSessionCookie();
        $orderId = (string) $app->getRequest()->query->get('token');
        $flow = $this->paypalFlow($orderId);
        $adapter = \ClientFence\Mod\Invoice\SavedCards::paypal($this->di);
        if ($flow === null || $adapter === null) {
            return $this->movesOn('invoice');
        }
        $invoice = $this->di['db']->getExistingModelById('Invoice', (int) $flow['invoice'], 'Invoice not found');
        // Paid another way meanwhile: the approved order is left to expire, never taken as well.
        if ($invoice->status !== \Model_Invoice::STATUS_UNPAID) {
            $this->di['cache']->deleteItem(self::paypalKey($orderId));

            return $this->movesOn('invoice/' . $invoice->hash);
        }
        $captureId = $adapter->vaultCapture($invoice, $orderId);
        // Kept until PayPal took the payment, so that the client can come back again after an error.
        $this->di['cache']->deleteItem(self::paypalKey($orderId));
        // Taken: the cron never charges the saved account for it, also while PayPal holds the payment.
        \ClientFence\Mod\Invoice\SavedCards::hold($this->di, (int) $invoice->id, 'Payment ' . $captureId . ' is not completed yet.');
        $this->di['mod_service']('Invoice', 'Transaction')->createAndProcess([
            'invoice_id' => (int) $invoice->id,
            'gateway_id' => $adapter->gatewayId(),
            'source' => 'paypal_return',
            'get' => ['paypal_capture' => $captureId, 'invoice_id' => (int) $invoice->id],
        ]);

        return $this->movesOn('invoice/thank-you/' . $invoice->hash);
    }

    /*
     * The way back from PayPal comes without the SameSite=Strict session cookie (security mode "strict"), as with
     * Google sign-in (Client/Google.php). So PayPal's token is kept in the cache with the client or invoice and the
     * hash of a SameSite=Lax cookie that the browser does send then: only the browser that went to PayPal can finish.
     */
    private const PAYPAL_COOKIE = 'paypal_return';
    private const PAYPAL_SECONDS = 10800;

    private static function paypalKey(string $token): string
    {
        return 'clientfence_paypal_' . hash('sha256', $token);
    }

    private function paypalBind(string $token, array $flow): void
    {
        $bind = bin2hex(random_bytes(32));
        $item = $this->di['cache']->getItem(self::paypalKey($token));
        $item->set($flow + ['bind' => hash('sha256', $bind)]);
        $item->expiresAfter(self::PAYPAL_SECONDS);
        $this->di['cache']->save($item);
        $secure = (bool) \ClientFence\Config::getProperty('security.force_https', true) || $this->di['request']->isSecure();
        $this->di['cookie_queue']->queue(self::PAYPAL_COOKIE, $bind, time() + self::PAYPAL_SECONDS, '/', null, $secure, true, 'Lax');
    }

    /** The flow PayPal's token belongs to when this browser started it, or null. */
    private function paypalFlow(string $token): ?array
    {
        $cookie = (string) $this->di['request']->cookies->get(self::PAYPAL_COOKIE, '');
        $item = $this->di['cache']->getItem(self::paypalKey($token));
        $flow = $item->isHit() ? $item->get() : null;

        return $token !== '' && $cookie !== '' && is_array($flow) && hash_equals((string) $flow['bind'], hash('sha256', $cookie)) ? $flow : null;
    }

    /** Without the session cookie this request has a new, empty session: its cookie must not replace the client's. */
    private function keepSessionCookie(): void
    {
        if (!$this->di['request']->cookies->has(session_name())) {
            header_remove('Set-Cookie');
        }
    }

    /** A page that moves on, not a redirect: a redirect would still come from PayPal and again miss the session cookie. */
    private function movesOn(string $path): Response
    {
        $next = (string) $this->di['url']->link($path);
        $html = htmlspecialchars($next, ENT_QUOTES, 'UTF-8');
        $js = json_encode($next, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        $continue = htmlspecialchars(__trans('Continue'), ENT_QUOTES, 'UTF-8');

        return new Response(
            "<!doctype html><html><head><meta charset=\"utf-8\"><meta name=\"referrer\" content=\"no-referrer\"><meta http-equiv=\"refresh\" content=\"0;url={$html}\"><title>{$continue}</title></head>"
            . "<body><p><a href=\"{$html}\">{$continue}</a></p><script nonce=\"" . \ClientFence\Csp::nonce() . "\">location.replace({$js});</script></body></html>",
            200,
            ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex']
        );
    }

    /** The PayPal adapter for a signed-in account owner when clients can save PayPal accounts. */
    private function paypalForClient(): \Payment_Adapter_PayPalEmail
    {
        $this->di['is_client_logged'];
        $user = \ClientFence\Mod\Client\Users::context($this->di);
        if ($user !== null && !$user['owner']) {
            throw new InformationException('Only the account owner can save a payment method.');
        }

        return \ClientFence\Mod\Invoice\SavedCards::paypal($this->di) ?? throw new InformationException('Saved PayPal accounts are not available.');
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
