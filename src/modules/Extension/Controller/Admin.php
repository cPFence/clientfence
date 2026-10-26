<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Extension\Controller;

use ClientFence\InformationException;
use Symfony\Component\HttpFoundation\Response;

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
                'location' => 'extensions',
                'index' => 1000,
                'label' => __trans('Extensions'),
                'class' => 'iPlugin',
            ],
            'subpages' => [
                [
                    'location' => 'extensions',
                    'label' => __trans('Overview'),
                    'uri' => $this->di['url']->adminLink('extension'),
                    'index' => 100,
                    'class' => '',
                ],
                [
                    'location' => 'extensions',
                    'label' => __trans('Languages'),
                    'index' => 200,
                    'uri' => $this->di['url']->adminLink('extension/languages'),
                    'class' => '',
                ],
            ],
        ];
    }

    public function register(\ClientFence\App &$app): void
    {
        $app->get('/extension', 'get_index', [], static::class);
        $app->get('/extension/settings/:mod', 'get_settings', ['mod' => '[a-z0-9-]+'], static::class);
        $app->get('/extension/languages', 'get_langs', [], static::class);
    }

    public function get_index(\ClientFence\App $app): string|Response
    {
        $this->di['is_admin_logged'];
        // ClientFence core patch: a clean 403 for staff without the extension module (the page crashed).
        if (!$this->di['mod_service']('Staff')->hasPermission(null, 'extension')) {
            return $app->errorResponse(new InformationException('You do not have permission to access the :mod: module', [':mod:' => 'extension'], 403), 403);
        }

        return $app->render('mod_extension_index');
    }

    public function get_langs(\ClientFence\App $app): string|Response
    {
        $this->di['is_admin_logged'];
        // ClientFence core patch: a clean 403 for staff without the extension module (the page crashed).
        if (!$this->di['mod_service']('Staff')->hasPermission(null, 'extension')) {
            return $app->errorResponse(new InformationException('You do not have permission to access the :mod: module', [':mod:' => 'extension'], 403), 403);
        }

        return $app->render('mod_extension_languages');
    }

    public function get_settings(\ClientFence\App $app, $mod): string|Response
    {
        $this->di['is_admin_logged'];
        $extensionService = $this->di['mod_service']('Extension');

        try {
            $extensionService->hasManagePermission($mod);
        } catch (InformationException $e) {
            return $app->errorResponse($e, 403);
        }

        $file = 'mod_' . $mod . '_settings';

        return $app->render($file);
    }
}
