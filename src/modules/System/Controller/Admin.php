<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\System\Controller;

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
            'group' => [
                'index' => 600,
                'location' => 'system',
                'label' => __trans('System'),
                'class' => 'settings',
            ],
            'subpages' => [
                [
                    'location' => 'system',
                    'label' => __trans('Settings'),
                    'index' => 100,
                    'uri' => $this->di['url']->adminLink('system'),
                    'class' => '',
                ],
                [
                    'location' => 'system',
                    'label' => __trans('Update ClientFence'),
                    'index' => 100,
                    'uri' => $this->di['url']->adminLink('system/update'),
                    'class' => '',
                ],
            ],
        ];
    }

    public function register(\ClientFence\App &$app): void
    {
        $app->get('/system', 'get_index', [], static::class);
        $app->get('/system/', 'get_index', [], static::class);
        $app->get('/system/index', 'get_index', [], static::class);
        // ClientFence: no /system/activity route; FOSSBilling 0.8.8 ships no template for it (it
        // answered with an error). Activity logs are under Activity in the admin menu.
        $app->get('/system/update', 'get_update', [], static::class);
        $app->get('/system/update/finalize', 'get_update_finalize', [], static::class);
    }

    public function get_index(\ClientFence\App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_system_index');
    }

    public function get_update(\ClientFence\App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_system_update');
    }

    public function get_update_finalize(\ClientFence\App $app): string|\Symfony\Component\HttpFoundation\Response
    {
        $this->di['is_admin_logged'];
        // ClientFence core patch: an update that completed on its own (UpdateFinalization) leaves nothing to finalize.
        if (!$this->di['update_finalization']->isRequired()) {
            return $app->redirect('/');
        }

        return $app->render('mod_system_update_finalize');
    }
}
