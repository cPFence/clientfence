<?php

declare(strict_types=1);

namespace ClientFence\Mod\Seo\Controller;

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
        $key = $this->di['mod_config']('seo')['indexnow_key'] ?? null;
        if ($key !== null) {
            $app->get('/' . $key . '.txt', 'get_key', [], static::class);
        }
    }

    /**
     * The IndexNow key file: search engines read it to confirm the key belongs to this site.
     */
    public function get_key(\ClientFence\App $app): Response
    {
        return new Response($this->di['mod_config']('seo')['indexnow_key'], 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
