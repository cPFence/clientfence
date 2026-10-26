<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence;

use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class AppAdmin extends \ClientFence\App
{
    public function init(): void
    {
        $m = $this->di['mod']($this->mod);
        $controller = $m->getAdminController();
        // ClientFence: a switched-off extension has no admin pages (404) instead of pages that fail
        // on its disabled API. Its settings stay under Extensions, a core module.
        if (!is_null($controller) && method_exists($controller, 'register') && $this->di['mod_service']('extension')->isExtensionActive('mod', $this->mod)) {
            $controller->register($this);
        }
    }

    protected function checkPermission(): ?Response
    {
        /*
         * Disable permission checks when an update is pending finalization.
         *
         * This is to make sure update finalization still works when there are changes to the
         * permission system and patches need to be applied before everything starts working again.
         */
        if ($this->di['auth']->isAdminLoggedIn() && $this->di['update_finalization']->isRequired()) {
            if (!$this->di['update_finalization']->isAdminPathAllowed($this->uri)) {
                return $this->redirect('system/update/finalize');
            }

            return null;
        }

        $service = $this->di['mod_service']('Staff');

        // ClientFence core patch: every staff member opens their own profile (password, 2FA, signature);
        // the page only uses the always-allowed profile API.
        $ownProfile = $this->mod === 'staff' && preg_match('#^/?staff/profile/?$#', (string) parse_url((string) $this->uri, PHP_URL_PATH)) === 1;
        if ($this->mod !== 'extension' && !$ownProfile && $this->di['auth']->isAdminLoggedIn() && !$service->hasPermission(null, $this->mod)) {
            $e = new \ClientFence\InformationException('You do not have permission to access the :mod: module', [':mod:' => $this->mod], 403);

            return $this->errorResponse($e, 403);
        }

        return null;
    }

    #[\Override]
    public function render($fileName, $variableArray = []): string
    {
        try {
            try {
                $template = $this->getTwig()->load(Path::changeExtension($fileName, '.html.twig'));
            } catch (\Twig\Error\LoaderError $e) {
                // ClientFence: a page without a template is a 404, as in the client area, not an
                // error page that lists the server's template folders.
                $this->di['logger']->setChannel('routing')->info($e->getMessage());

                throw new \ClientFence\InformationException('Page not found', null, 404);
            }

            return $template->render($variableArray);
        } catch (\RuntimeException $e) {
            $this->convertCacheWriteFailure($e);
        }
    }

    #[\Override]
    public function redirect($path): RedirectResponse
    {
        return $this->responseFactory()->redirect($this->di['url']->adminLink($path));
    }

    /**
     * Get Twig environment for admin area.
     */
    protected function getTwig(): \Twig\Environment
    {
        $twigFactory = $this->di['twig_factory'];

        return $twigFactory->createAdminEnvironment($this->debugBar);
    }
}
