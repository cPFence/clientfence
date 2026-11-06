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
use Symfony\Component\HttpFoundation\Response;

class AppClient extends \ClientFence\App
{
    protected function init(): void
    {
        // Paths without letters can resolve to custom pages, but cannot identify modules.
        // ClientFence: a switched-off extension has no client pages; its URLs answer 404 like any
        // unknown page instead of failing on its disabled API (core modules are always on).
        if (preg_match('/[a-zA-Z]/', $this->mod) === 1) {
            $m = $this->di['mod']($this->mod);
            if ($m->hasClientController() && $this->di['mod_service']('extension')->isExtensionActive('mod', $this->mod)) {
                $m->registerClientRoutes($this);
            }
        }
        // ClientFence core patch: top-level client pages, each registered by the module that owns it
        // (client URLs never name the module).
        $pageModule = [
            'hosting' => 'servicehosting', 'users' => 'client', 'contacts' => 'client', 'profile' => 'client', 'invite' => 'client',
            'password-reset' => 'client', 'two-factor' => 'client', 'ticket-file' => 'support',
            'google-sign-in' => 'client', 'receipt' => 'invoice',
            // ClientFence: quote links (Invoice/Quotes.php).
            'quote' => 'invoice',
            // ClientFence: the client's saved cards (Invoice/SavedCards.php).
            'payment-methods' => 'invoice',
            // ClientFence: the Network Status page (Support/NetworkIssues.php).
            'network-status' => 'support',
            // ClientFence core patch: privacy (Client/Privacy.php): the data download and unsubscribe links.
            'my-data' => 'client', 'unsubscribe' => 'client',
            // ClientFence: the script policy's violation reports (Clientfence/CspReports.php).
            'csp-report' => 'clientfence',
        ][$this->mod] ?? null;
        if ($pageModule !== null) {
            $this->di['mod']($pageModule)->registerClientRoutes($this);
        }

        if ($this->mod == 'api') {
            define('API_MODE', true);

            // Prevent errors from being displayed in API mode as it can cause invalid JSON to be returned.
            ini_set('display_errors', '0');
            ini_set('display_startup_errors', '0');
        } else {
            $extensionService = $this->di['mod_service']('extension');
            if ($extensionService->isExtensionActive('mod', 'redirect')) {
                $m = $this->di['mod']('redirect');
                $m->registerClientRoutes($this);
            }
            // ClientFence core patch (ext seo): the IndexNow key file sits at the site root (/<key>.txt).
            if (str_ends_with($this->mod, '.txt') && $extensionService->isExtensionActive('mod', 'seo')) {
                $this->di['mod']('seo')->registerClientRoutes($this);
            }

            // init index module manually
            $this->get('', 'get_index');
            $this->get('/', 'get_index');

            // init custom methods for undefined pages
            $this->get('/:page', 'get_custom_page', ['page' => '[a-z0-9-/.//]+']);
            $this->post('/:page', 'get_custom_page', ['page' => '[a-z0-9-/.//]+']);
        }
    }

    // ClientFence core patch: authorized users. A signed-in authorized user opens only the client
    // pages the user's permissions allow (modules/Client/Users.php PAGES).
    #[\Override]
    protected function checkPermission(): ?Response
    {
        if ($this->mod !== 'api') {
            // ClientFence core patch: Google sign-up. An account made by Google sign-up fills the
            // profile fields the portal requires before the client area opens (Google.php).
            $profile = \ClientFence\Mod\Client\Google::profileGate($this->di, $this->url);
            if ($profile !== null) {
                return $this->redirect($profile);
            }
            $denied = \ClientFence\Mod\Client\Users::pageDenied($this->di, $this->url);
            if ($denied !== null) {
                return $this->renderResponse('mod_client_no_access', ['permission' => $denied], 403);
            }
        }

        return null;
    }

    public function get_index(): string
    {
        return $this->render('mod_index_dashboard');
    }

    public function get_custom_page($page): Response
    {
        $ext = $this->ext;
        if (str_contains((string) $page, '.')) {
            $ext = substr((string) $page, strpos((string) $page, '.') + 1);
            $page = substr((string) $page, 0, strpos((string) $page, '.'));
        }

        if ($page === 'login' && $this->di['auth']->isClientLoggedIn()) {
            return $this->redirect('/');
        }

        $page = str_replace('/', '_', $page);
        $tpl = 'mod_page_' . $page;

        try {
            $content = $this->render($tpl, ['post' => $this->getRequest()->request->all()], $ext);

            if ("{$tpl}.{$ext}" === 'mod_page_sitemap.xml') {
                return $this->responseFactory()->html($content, 200, ['Content-Type' => 'application/xml']);
            }

            return $this->responseFactory()->html($content);
        } catch (\ClientFence\InformationException $e) {
            // @phpstan-ignore if.alwaysFalse (DEBUG is a runtime constant that may be true during debugging)
            if (DEBUG) {
                error_log($e->getMessage());
            }
        } catch (\Twig\Error\LoaderError|\Twig\Error\RuntimeError|\Twig\Error\SyntaxError $e) {
            // A real template bug, not a missing page. Surface as a 500 so the
            // next regression of this shape (issue #3818) cannot hide behind a
            // generic 404.
            $this->di['logger']->setChannel('routing')->error(sprintf(
                'Template rendering failed for "%s" (page "%s"): %s',
                $tpl,
                (string) $page,
                $e->getMessage(),
            ), ['exception' => $e]);

            $internal = new \ClientFence\InformationException('The requested page could not be rendered.', [], 500);

            return $this->errorResponse($internal);
        }
        $e = new \ClientFence\InformationException('Page :url not found', [':url' => $this->url], 404);

        $this->di['logger']->setChannel('routing')->info($e->getMessage());

        return $this->errorResponse($e, 404);
    }

    /**
     * @param string $fileName
     */
    #[\Override]
    public function render($fileName, $variableArray = [], $ext = 'html.twig'): string
    {
        try {
            $template = $this->getTwig()->load(Path::changeExtension($fileName, $ext));

            return $template->render($variableArray);
        } catch (\Twig\Error\LoaderError $e) {
            $this->di['logger']->setChannel('routing')->info($e->getMessage());

            throw new \ClientFence\InformationException('Page not found', null, 404);
        } catch (\RuntimeException $e) {
            $this->convertCacheWriteFailure($e);
        }
    }

    /**
     * Get Twig environment for client area.
     */
    protected function getTwig(): \Twig\Environment
    {
        $twigFactory = $this->di['twig_factory'];

        return $twigFactory->createClientEnvironment($this->debugBar);
    }
}
