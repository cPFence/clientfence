<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Controller;

/**
 * Client area pages of the ClientFence module: only the older addresses of pages that moved to
 * their home modules, each answering 301. Client URLs never name a module.
 *
 * Hosting accounts are pages of the Servicehosting module (/hosting): /billfence/hosting.
 * Payment receipts are pages of the Invoice module (/receipt/<id>): /billfence/receipt/<id>,
 * which tickets written before carry. These keep the product's earlier name (BillFence until
 * 2026-10-16): \ClientFence\AppClient's page map sends /billfence/* here.
 *
 * Authorized users, two-factor sign-in and Google sign-in are pages of the Client module
 * (Client/Controller/Client.php); ticket attachments (/ticket-file/<id>) of the Support module.
 */
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
        $app->get('/billfence/receipt/:id', 'get_receipt_moved', ['id' => '[a-f0-9]{32}'], static::class);
        $app->get('/billfence/hosting', 'get_hosting_moved', [], static::class);
        // The script policy's violation reports (clientfence-security-headers.php).
        $app->post('/csp-report', 'post_csp_report', [], static::class);
    }

    public function post_csp_report(\ClientFence\App $app): \Symfony\Component\HttpFoundation\Response
    {
        \ClientFence\Mod\Clientfence\CspReports::record((string) file_get_contents('php://input', false, null, 0, 16385));

        return new \Symfony\Component\HttpFoundation\Response('', 204);
    }

    public function get_receipt_moved(\ClientFence\App $app, $id): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return $app->permanentRedirect('/receipt/' . $id);
    }

    public function get_hosting_moved(\ClientFence\App $app): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return $app->permanentRedirect('/hosting');
    }
}
