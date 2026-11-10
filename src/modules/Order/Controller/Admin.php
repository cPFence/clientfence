<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Order\Controller;

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
                'index' => 300,
                'location' => 'order',
                'label' => __trans('Orders'),
                'uri' => $this->di['url']->adminLink('order'),
                'class' => 'package',
            ],
            'subpages' => [
                [
                    'location' => 'order',
                    'index' => 100,
                    'label' => __trans('Overview'),
                    'uri' => $this->di['url']->adminLink('order'),
                    'class' => '',
                ],
                // ClientFence core patch: failed order actions (ActionFailures).
                [
                    'location' => 'order',
                    'index' => 200,
                    'label' => __trans('Failed actions'),
                    'uri' => $this->di['url']->adminLink('order/failed-actions'),
                    'class' => '',
                ],
                // ClientFence core patch: bulk price update, as WHMCS (BulkPrices).
                [
                    'location' => 'order',
                    'index' => 300,
                    'label' => __trans('Bulk price update'),
                    'uri' => $this->di['url']->adminLink('order/bulk-prices'),
                    'class' => '',
                ],
            ],
        ];
    }

    public function register(\ClientFence\App &$app): void
    {
        $app->get('/order', 'get_index', [], static::class);
        $app->get('/order/', 'get_index', [], static::class);
        $app->get('/order/index', 'get_index', [], static::class);
        $app->get('/order/manage/:id', 'get_order', ['id' => '[0-9]+'], static::class);
        $app->get('/order/failed-actions', 'get_failed_actions', [], static::class);
        // ClientFence: bulk price update, with its preview (BulkPrices.php).
        $app->get('/order/bulk-prices', 'get_bulk_prices', [], static::class);
        // ClientFence core patch: Add New Order is one page for a client's products and domains (WHMCS).
        $app->get('/order/new', 'get_new', [], static::class);
    }

    /**
     * The bulk price update form; with `preview` in the query, also the orders it matches (or
     * why the filters are refused).
     */
    public function get_bulk_prices(\ClientFence\App $app): string
    {
        $this->di['is_admin_logged'];
        $query = $this->di['request']->query->all();
        $set = ['filters' => $query, 'preview' => null, 'error' => null];
        if (!empty($query['preview'])) {
            try {
                $set['preview'] = $this->di['api_admin']->order_bulk_price_preview($query);
            } catch (\ClientFence\InformationException $e) {
                $set['error'] = $e->getMessage();
            }
        }

        return $app->render('mod_order_bulk_prices', $set);
    }

    public function get_index(\ClientFence\App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_order_index');
    }

    public function get_failed_actions(\ClientFence\App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_order_failed_actions');
    }

    public function get_new(\ClientFence\App $app): string
    {
        $this->di['is_admin_logged'];
        $clientId = (int) $app->getRequest()->query->get('client_id');

        return $app->render('mod_order_new', ['client' => $clientId > 0 ? $this->di['api_admin']->client_get(['id' => $clientId]) : null]);
    }

    public function get_order(\ClientFence\App $app, $id): string
    {
        $api = $this->di['api_admin'];
        $data = [
            'id' => $id,
        ];
        $order = $api->order_get($data);
        $set = ['order' => $order];

        if (isset($order['plugin']) && !empty($order['plugin'])) {
            $set['plugin'] = 'plugin_' . $order['plugin'] . '_manage.html.twig';
        }

        return $app->render('mod_order_manage', $set);
    }
}
