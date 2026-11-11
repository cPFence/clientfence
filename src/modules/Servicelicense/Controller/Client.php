<?php

declare(strict_types=1);

namespace ClientFence\Mod\Servicelicense\Controller;

use ClientFence\Mod\Servicelicense\Units;
use ClientFence\Mod\Servicelicense\WhmcsCheck;
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
        // The WHMCS Licensing Addon check address (/modules/servers/licensing/verify.php, see .htaccess).
        $app->post('/licence/verify', 'post_verify', [], static::class);
        // Usage reports of per-unit licences (Units.php).
        $app->post('/licence/report', 'post_report', [], static::class);
    }

    public function post_verify(\ClientFence\App $app): Response
    {
        $request = $this->di['request'];

        return new Response(WhmcsCheck::answer($this->di, $request->request->all(), (string) $request->getClientIp()), 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
    }

    public function post_report(\ClientFence\App $app): Response
    {
        $request = $this->di['request'];
        $data = json_decode((string) $request->getContent(), true);
        [$status, $text] = Units::report($this->di, is_array($data) ? $data : $request->request->all(), (string) $request->getClientIp());

        return new Response($text, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
