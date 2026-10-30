<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Support\Controller;

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

    public function register(\ClientFence\App &$app): void
    {
        $app->get('/support', 'get_tickets', [], static::class);
        // ClientFence: a ticket attachment (TicketFiles.php).
        $app->get('/ticket-file/:id', 'get_ticket_file', ['id' => '[a-f0-9]{32}'], static::class);
        $app->get('/support/ticket/:id', 'get_ticket', [], static::class);
        $app->get('/support/contact-us', 'get_contact_us', [], static::class);
        $app->get('/support/contact-us/conversation/:hash', 'get_ticket_redirect', ['hash' => '[a-z0-9]+'], static::class);
        // ClientFence: the feedback form of a feedback email's link, without sign-in, as WHMCS (TicketFeedback).
        $app->get('/support/feedback/:token', 'get_feedback', ['token' => '[a-f0-9]{64}'], static::class);
        // ClientFence: the Network Status page, as WHMCS (NetworkIssues.php), while it is on (off:
        // the theme's page not found, as the knowledge base).
        if (\ClientFence\Mod\Support\NetworkIssues::enabled($this->di)) {
            $app->get('/network-status', 'get_network_status', [], static::class);
        }

        if ($this->di['mod']('support')->getService()->kbEnabled()) {
            $app->get('/support/kb', 'get_kb_index', [], static::class);
            $app->get('/support/kb/:category', 'get_kb_category', ['category' => '[a-z0-9-]+'], static::class);
            $app->get('/support/kb/:category/:slug', 'get_kb_article', ['category' => '[a-z0-9-]+', 'slug' => '[a-z0-9-]+'], static::class);
        }
    }

    /**
     * ClientFence: the Network Status page (NetworkIssues.php): open issues, scheduled work and
     * resolved issues. Not found while network status is off; signing in first unless the Support
     * settings make it public.
     */
    public function get_network_status(\ClientFence\App $app): string
    {
        $settings = $this->di['api_guest']->support_network_status_settings();
        if (!$settings['enabled']) {
            throw new \ClientFence\InformationException('Page not found', [], 404);
        }
        if (!$settings['public']) {
            $this->di['is_client_logged'];
        }
        $query = $this->di['request']->query;
        $group = is_string($query->all()['group'] ?? null) ? (string) $query->all()['group'] : 'open';
        $issues = $this->di['api_guest']->support_network_issues(['group' => $group, 'page' => max(1, (int) $query->getInt('page', 1))]);

        return $app->render('mod_support_network_status', ['issues' => $issues, 'group' => in_array($group, \ClientFence\Mod\Support\NetworkIssues::GROUPS, true) ? $group : 'open']);
    }

    public function get_tickets(\ClientFence\App $app): string
    {
        $this->di['is_client_logged'];

        return $app->render('mod_support_tickets');
    }

    public function get_ticket(\ClientFence\App $app, $id): string|Response
    {
        $id = (string) $id;
        if (ctype_digit($id) && strlen($id) < 30) {
            $this->di['is_client_logged'];
            // ClientFence core patch: a merged ticket opens the ticket it went into (TicketMerge).
            $merged = \ClientFence\Mod\Support\TicketMerge::targetForClient($this->di, (int) $id, (int) $this->di['loggedin_client']->id);
            if ($merged !== null) {
                return $app->redirect('support/ticket/' . $merged);
            }
            $ticket = $this->di['api_client']->support_ticket_get(['id' => $id]);
        } else {
            $ticket = $this->di['api_guest']->support_ticket_get(['hash' => $id]);
        }

        return $app->render('mod_support_ticket', ['ticket' => $ticket]);
    }

    /**
     * ClientFence: the feedback page of a feedback email's link (TicketFeedback::page()); an unknown
     * link shows the page's "not valid" message.
     */
    public function get_feedback(\ClientFence\App $app, $token): string
    {
        try {
            $feedback = \ClientFence\Mod\Support\TicketFeedback::page($this->di, (string) $token);
        } catch (\ClientFence\InformationException) {
            $feedback = ['state' => 'invalid', 'ticket' => [], 'staff' => []];
        }

        return $app->render('mod_support_feedback', ['feedback' => $feedback, 'feedback_token' => (string) $token]);
    }

    public function get_contact_us(\ClientFence\App $app): string|Response
    {
        if ($this->di['auth']->isClientLoggedIn()) {
            return $app->redirect('support');
        }

        return $app->render('mod_support_contact_us');
    }

    /**
     * A redirect to keep old guest ticket links working.
     *
     * /support/contact-us/conversation/:hash -> /support/ticket/:hash
     */
    public function get_ticket_redirect(\ClientFence\App $app, $hash): Response
    {
        return $app->redirect('support/ticket/' . $hash);
    }

    /*
    * Support Knowledge Base.
    */
    public function get_kb_index(\ClientFence\App $app): string
    {
        return $app->render('mod_support_kb_index');
    }

    public function get_kb_category(\ClientFence\App $app, $category): string
    {
        $api = $this->di['api_guest'];
        $data = ['slug' => $category];
        $model = $api->support_kb_category_get($data);

        return $app->render('mod_support_kb_category', ['category' => $model]);
    }

    public function get_kb_article(\ClientFence\App $app, $category, $slug): string
    {
        $api = $this->di['api_guest'];
        $data = ['slug' => $slug];
        $article = $api->support_kb_article_get($data);

        return $app->render('mod_support_kb_article', ['article' => $article]);
    }

    /**
     * ClientFence: a ticket attachment (TicketFiles.php): the client's own tickets (authorized
     * users need `tickets`, the page gate), or staff with the support permission. Anyone else
     * gets "not found", and a visitor who is not signed in goes to the sign-in page.
     */
    public function get_ticket_file(\ClientFence\App $app, $id): Response
    {
        $auth = $this->di['auth'];
        $file = \ClientFence\Mod\Support\TicketFiles::find($this->di, (string) $id);
        if ($auth->isClientLoggedIn() && $file !== null && (int) $file['client_id'] === (int) $this->di['loggedin_client']->id) {
            return \ClientFence\Mod\Support\TicketFiles::response($file);
        }
        if ($auth->isAdminLoggedIn()) {
            $this->di['mod_service']('staff')->checkPermissionsAndThrowException('support');
            if ($file !== null) {
                return \ClientFence\Mod\Support\TicketFiles::response($file);
            }
        } elseif (!$auth->isClientLoggedIn()) {
            // Not signed in: the sign-in page, then back here.
            $this->di['is_client_logged'];
        }

        return new Response('File not found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
