<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\News\Controller;

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
        $app->get('/news', 'get_news', [], static::class);
        $app->get('/news/:slug', 'get_news_item', ['slug' => '[a-z0-9-]+'], static::class);
    }

    public function get_news(\ClientFence\App $app): string
    {
        return $app->render('mod_news_index');
    }

    public function get_news_item(\ClientFence\App $app, $slug): string
    {
        $post = $this->di['api_guest']->news_get(['slug' => $slug]);

        return $app->render('mod_news_post', ['post' => $post]);
    }
}
