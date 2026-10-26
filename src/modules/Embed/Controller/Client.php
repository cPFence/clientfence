<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Embed\Controller;

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

    /**
     * Methods maps client areas urls to corresponding methods
     * Always use your module prefix to avoid conflicts with other modules
     * in future.
     *
     * @param \ClientFence\App $app - returned by reference
     */
    public function register(\ClientFence\App &$app): void
    {
        $app->get('/embed/:what', 'get_object', ['what' => '[a-z0-9-]+'], static::class);
    }

    public function get_object(\ClientFence\App $app, $what): string
    {
        $tpl = 'mod_embed_' . $what;
        $origins = $this->di['mod_service']('embed')->getAllowedOrigins();
        if ($origins !== []) {
            // ClientFence core patch (ext embed): the allowed websites may frame these pages, which X-Frame-Options cannot list.
            header_remove('X-Frame-Options');
            foreach (clientfence_frame_headers($origins) as $line) {
                header($line);
            }
        }

        return $app->render($tpl);
    }
}
