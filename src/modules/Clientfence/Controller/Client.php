<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Controller;

/**
 * Client area pages of the ClientFence module: the script policy's violation reports. Client URLs
 * never name a module: hosting accounts are pages of the Servicehosting module (/hosting), payment
 * receipts of the Invoice module (/receipt/<id>).
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
        // The script policy's violation reports (clientfence-security-headers.php).
        $app->post('/csp-report', 'post_csp_report', [], static::class);
    }

    public function post_csp_report(\ClientFence\App $app): \Symfony\Component\HttpFoundation\Response
    {
        \ClientFence\Mod\Clientfence\CspReports::record((string) file_get_contents('php://input', false, null, 0, 16385));

        return new \Symfony\Component\HttpFoundation\Response('', 204);
    }
}
