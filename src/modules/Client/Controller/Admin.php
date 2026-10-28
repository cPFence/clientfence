<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Client\Controller;

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
                'index' => 200,
                'location' => 'client',
                'label' => __trans('Clients'),
                'uri' => $this->di['url']->adminLink('client'),
                'class' => 'address-book',
            ],
            'subpages' => [
                [
                    'location' => 'client',
                    'label' => __trans('Overview'),
                    'uri' => $this->di['url']->adminLink('client'),
                    'index' => 100,
                    'class' => '',
                ],
                [
                    'location' => 'activity',
                    'index' => 900,
                    'label' => __trans('Client Login History'),
                    'uri' => $this->di['url']->adminLink('client/logins'),
                    'class' => '',
                ],
            ],
        ];
    }

    public function register(\ClientFence\App &$app): void
    {
        $app->get('/client', 'get_index', [], static::class);
        $app->get('/client/', 'get_index', [], static::class);
        $app->get('/client/index', 'get_index', [], static::class);
        $app->get('/client/login/:id', 'get_login', ['id' => '[0-9]+'], static::class);
        $app->get('/client/manage/:id', 'get_manage', ['id' => '[0-9]+'], static::class);
        $app->get('/client/group/:id', 'get_group', ['id' => '[0-9]+'], static::class);
        $app->get('/client/create', 'get_create', [], static::class);
        $app->get('/client/logins', 'get_history', [], static::class);
        // ClientFence core patch: a client's data as a JSON file (Privacy.php).
        $app->get('/client/privacy-export/:id', 'get_privacy_export', ['id' => '[0-9]+'], static::class);
        // ClientFence core patch: private client documents are streamed only after staff authorization.
        $app->get('/client/file/:id', 'get_file', ['id' => '[0-9]+'], static::class);
    }

    public function get_index(\ClientFence\App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_client_index');
    }

    public function get_manage(\ClientFence\App $app, $id): string
    {
        $api = $this->di['api_admin'];
        $client = $api->client_get(['id' => $id]);

        // ClientFence core patch: the privacy tools' buttons (Privacy.php).
        return $app->render('mod_client_manage', ['client' => $client, 'privacy_tools' => \ClientFence\Mod\Client\Privacy::toolsOn($this->di)]);
    }

    public function get_group(\ClientFence\App $app, $id): string
    {
        $api = $this->di['api_admin'];
        $model = $api->client_group_get(['id' => $id]);

        return $app->render('mod_client_group', ['group' => $model]);
    }

    public function get_history(\ClientFence\App $app): string
    {
        $api = $this->di['api_admin'];

        return $app->render('mod_client_login_history');
    }

    public function get_login(\ClientFence\App $app, $id): Response
    {
        $api = $this->di['api_admin'];
        $api->client_login(['id' => $id]);

        $redirect_to = '/';

        $query = $app->getRequest()->query->get('r');
        if ($query) {
            $r = $query;
            $redirect_to = '/' . trim((string) $r, '/');
        }

        return $app->redirectUrl($this->di['tools']->url($redirect_to), 301);
    }

    /**
     * ClientFence: a client's data as a JSON file (Privacy::export): the privacy tools on, the
     * "export" permission, and the page's CSRF token in the link (no download from another site).
     */
    public function get_privacy_export(\ClientFence\App $app, $id): Response
    {
        $this->di['is_admin_logged'];
        $this->di['mod_service']('staff')->checkPermissionsAndThrowException('client', 'export');
        if (!\ClientFence\Mod\Client\Privacy::toolsOn($this->di)) {
            throw new \ClientFence\InformationException('The privacy tools are off (System → Settings → Clients → Privacy).');
        }
        $token = $this->di['request']->query->get('CSRFToken');
        $session = $this->di['session']->get('csrf_token');
        if (!is_string($token) || !is_string($session) || $session === '' || !hash_equals($session, $token)) {
            throw new \ClientFence\InformationException('CSRF token invalid', null, 403);
        }
        $json = json_encode(\ClientFence\Mod\Client\Privacy::export($this->di, (int) $id, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $this->di['logger']->setChannel('privacy')->info(sprintf('Data of client #%d exported by staff', (int) $id));

        return new Response((string) $json, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="client-' . (int) $id . '-data-' . date('Y-m-d') . '.json"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function get_file(\ClientFence\App $app, $id): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $this->di['is_admin_logged'];
        $this->di['mod_service']('staff')->checkPermissionsAndThrowException('client', 'view');

        return \ClientFence\Mod\Client\Files::download($this->di, (int) $id);
    }
}
