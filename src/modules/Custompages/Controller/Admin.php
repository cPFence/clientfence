<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Custompages\Controller;

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
                [
                    'location' => 'extensions',
                    'label' => __trans('Custom Pages'),
                    'index' => 2000,
                    'uri' => $this->di['url']->adminLink('custompages'),
                    'class' => '',
                ],
            ],
        ];
    }

    public function register(\ClientFence\App &$app): void
    {
        $app->get('/custompages', 'get_index', [], static::class);
        $app->get('/custompages/new', 'get_new', [], static::class);
        $app->get('/custompages/:id', 'get_page', ['id' => '[0-9]+'], static::class);
    }

    public function get_index(\ClientFence\App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_custompages_index');
    }

    public function get_new(\ClientFence\App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_custompages_page', ['page_id' => 0]);
    }

    public function get_page(\ClientFence\App $app, $id): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_custompages_page', ['page_id' => $id]);
    }
}
