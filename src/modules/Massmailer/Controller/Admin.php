<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Massmailer\Controller;

class Admin implements \ClientFence\InjectionAwareInterface
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

    public function fetchNavigation(): array
    {
        return [
            'subpages' => [
                // ClientFence core patch (ext massmailer): under Clients, where staff look for it.
                [
                    'location' => 'client',
                    'index' => 300,
                    'label' => __trans('Mass Mail'),
                    'uri' => $this->di['url']->adminLink('massmailer'),
                    'class' => '',
                ],
            ],
        ];
    }

    public function register(\ClientFence\App &$app): void
    {
        $app->get('/massmailer', 'get_index', [], static::class);
        $app->get('/massmailer/message/:id', 'get_edit', ['id' => '[0-9]+'], static::class);
        $app->get('/massmailer/new', 'get_new', [], static::class);
    }

    public function get_index(\ClientFence\App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_massmailer_index');
    }

    public function get_edit(\ClientFence\App $app, $id): string
    {
        $api = $this->di['api_admin'];
        $model = $api->massmailer_get(['id' => $id]);

        return $app->render('mod_massmailer_message', ['msg' => $model]);
    }

    // ClientFence core patch (ext massmailer): a new message opens on its first step and is created when staff move on.
    public function get_new(\ClientFence\App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_massmailer_message', ['msg' => null]);
    }
}
