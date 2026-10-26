<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

/**
 * This file connects FOSSBilling client area interface and API
 * Class does not extend any other class.
 */

namespace ClientFence\Mod\Custompages\Controller;

use ClientFence\Mod\Custompages\Entity\CustomPage;
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

    /**
     * Methods maps client areas urls to corresponding methods
     * Always use your module prefix to avoid conflicts with other modules
     * in future.
     *
     * @param \ClientFence\App $app - returned by reference
     */
    public function register(\ClientFence\App &$app): void
    {
        $app->get('/custompages/:slug', 'get_page', ['slug' => '[a-z0-9-]+'], static::class);
    }

    public function get_page(\ClientFence\App $app, $slug): string|Response
    {
        $service = $this->di['mod_service']('custompages');
        $page = $service->getPageRepository()->findOneBySlug((string) $slug);
        // ClientFence core patch (ext custompages): staff open any page with ?preview=1; others only a published page their visibility allows.
        $preview = $page instanceof CustomPage && $app->getRequest()->query->has('preview') && $this->di['auth']->isAdminLoggedIn();
        if (!$preview) {
            if (!$page instanceof CustomPage || $page->getStatus() !== CustomPage::STATUS_PUBLISHED) {
                throw new \ClientFence\InformationException('Page not found', [], 404);
            }
            $clientId = $service->viewerClientId();
            if (!in_array($page->getVisibility(), $service->visibilitiesFor($clientId), true)) {
                if ($clientId === null) {
                    $this->di['is_client_logged'];
                }

                throw new \ClientFence\InformationException('Page not found', [], 404);
            }
        }

        return $app->render('mod_custompages_content', ['page' => $page->toApiArray(), 'preview' => $preview]);
    }
}
