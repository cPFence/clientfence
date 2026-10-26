<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Redirect\Controller;

use ClientFence\Http\ResponseFactory;
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
        // ClientFence: the Client controller has no get_profile/get_balance (every visit was a 500);
        // these short addresses now lead to the profile and wallet pages.
        $app->get('/me', 'get_profile', [], static::class);
        $app->get('/balance', 'get_balance', [], static::class);
        $app->get('/reset-password-confirm/:hash', 'get_reset_password_confirm', ['hash' => '[a-z0-9]+'], '\\' . \ClientFence\Mod\Client\Controller\Client::class);
        $app->get('/emails', 'get_emails', [], '\\' . \ClientFence\Mod\Email\Controller\Client::class);
        $app->get('/banklink/:hash/:id', 'get_banklink', ['id' => '[0-9]+', 'hash' => '[a-z0-9]+'], '\\' . \ClientFence\Mod\Invoice\Controller\Client::class);
        // ClientFence: the News aliases only while the News extension is on; without it they would
        // fail on its disabled API (like /news, they then answer 404).
        if ($this->di['mod_service']('extension')->isExtensionActive('mod', 'news')) {
            $app->get('/blog', 'get_news', [], '\\' . \ClientFence\Mod\News\Controller\Client::class);
            $app->get('/blog/:slug', 'get_news_item', ['slug' => '[a-z0-9-]+'], '\\' . \ClientFence\Mod\News\Controller\Client::class);
        }
        $app->get('/service', 'get_orders', [], '\\' . \ClientFence\Mod\Order\Controller\Client::class);
        $app->get('/service/manage/:id', 'get_order', ['id' => '[0-9]+'], '\\' . \ClientFence\Mod\Order\Controller\Client::class);
        $app->get('/contact-us', 'get_contact_us', [], '\\' . \ClientFence\Mod\Support\Controller\Client::class);
        $app->get('/contact-us/conversation/:hash', 'get_ticket_redirect', ['hash' => '[a-z0-9]+'], '\\' . \ClientFence\Mod\Support\Controller\Client::class);

        $service = $this->di['mod_service']('redirect');
        $redirects = $service->getRedirects();
        foreach ($redirects as $redirect) {
            // ClientFence core patch (ext redirect): a switched-off redirect is not routed, so its path works as if it had none.
            if (!$redirect['enabled']) {
                continue;
            }
            $app->get('/' . $redirect['path'], 'do_redirect', [], static::class);
        }
    }

    public function get_profile(\ClientFence\App $app): Response
    {
        return $app->permanentRedirect('client/profile');
    }

    public function get_balance(\ClientFence\App $app): Response
    {
        return $app->permanentRedirect('client/balance');
    }

    public function do_redirect(\ClientFence\App $app): Response
    {
        $service = $this->di['mod_service']('redirect');
        $redirect = $service->getRedirectByPath($app->uri);

        if ($redirect === null || !$this->isTargetAllowed($redirect['target'])) {
            return (new ResponseFactory())->html('', 404);
        }

        // ClientFence core patch (ext redirect): a target without a scheme is a client-area path; sent as typed, the browser resolved it against the old path's folder.
        $target = is_string(parse_url($redirect['target'], PHP_URL_SCHEME)) ? $redirect['target'] : $this->di['url']->link($redirect['target']);

        return $app->redirectUrl($target, $redirect['type']);
    }

    private function isTargetAllowed(string $target): bool
    {
        if (trim($target) === '' || strpbrk($target, "\r\n") !== false) {
            return false;
        }

        $scheme = parse_url($target, PHP_URL_SCHEME);
        if (is_string($scheme) && !in_array(strtolower($scheme), ['http', 'https'], true)) {
            return false;
        }

        return true;
    }
}
