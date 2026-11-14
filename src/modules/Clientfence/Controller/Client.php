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
        // The client theme's custom CSS (CustomCss.php), linked with its version so browsers keep it until it changes.
        $app->get('/custom-css', 'get_custom_css', [], static::class);
    }

    public function get_custom_css(\ClientFence\App $app): \Symfony\Component\HttpFoundation\Response
    {
        $themes = $this->di['mod_service']('theme');
        $settings = $themes->getThemeSettings($themes->getTheme($themes->getCurrentClientAreaThemeCode()));
        $on = in_array($settings['custom_css_enabled'] ?? '0', ['1', 1, true, 'true', 'on'], true);
        $css = $on ? (string) ($settings['custom_css'] ?? '') : '';
        // The session's no-store headers would stop browsers keeping the stylesheet.
        header_remove('Cache-Control');
        header_remove('Pragma');
        header_remove('Expires');
        $current = $css !== '' && hash_equals((string) ($settings['custom_css_version'] ?? ''), (string) $this->di['request']->query->get('v', ''));

        return new \Symfony\Component\HttpFoundation\Response($css, 200, [
            'Content-Type' => 'text/css; charset=utf-8',
            'Cache-Control' => $current ? 'public, max-age=31536000, immutable' : 'no-cache',
        ]);
    }

    public function post_csp_report(\ClientFence\App $app): \Symfony\Component\HttpFoundation\Response
    {
        \ClientFence\Mod\Clientfence\CspReports::record((string) file_get_contents('php://input', false, null, 0, 16385));

        return new \Symfony\Component\HttpFoundation\Response('', 204);
    }
}
