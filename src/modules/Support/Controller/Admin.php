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
        $nav = [
            'group' => [
                'location' => 'support',
                'index' => 500,
                'label' => __trans('Support'),
                'class' => 'headset',
            ],
            'subpages' => [
                [
                    'location' => 'support',
                    'label' => __trans('Support Tickets'),
                    'uri' => $this->di['url']->adminLink('support', ['status' => 'open']),
                    'index' => 100,
                    'class' => '',
                ],
                [
                    'location' => 'support',
                    'label' => __trans('Canned Responses'),
                    'uri' => $this->di['url']->adminLink('support/canned-responses'),
                    'index' => 400,
                    'class' => '',
                ],
                // ClientFence core patch: the ticket email import log (MailImport).
                [
                    'location' => 'support',
                    'label' => __trans('Email import log'),
                    'uri' => $this->di['url']->adminLink('support/email-import'),
                    'index' => 450,
                    'class' => '',
                ],
                // ClientFence core patch: reply stars and feedback by staff member (TicketFeedback).
                [
                    'location' => 'support',
                    'label' => __trans('Ratings & Feedback'),
                    'uri' => $this->di['url']->adminLink('support/ratings'),
                    'index' => 460,
                    'class' => '',
                ],
            ],
        ];

        // ClientFence core patch: network issues, as WHMCS (NetworkIssues), while network status is on.
        if (\ClientFence\Mod\Support\NetworkIssues::enabled($this->di)) {
            $nav['subpages'][] = [
                'location' => 'support',
                'label' => __trans('Network Issues'),
                'uri' => $this->di['url']->adminLink('support/network-issues'),
                'index' => 470,
                'class' => '',
            ];
        }

        // ClientFence core patch: staff who manage help desks without the Support settings (where the
        // list is) get the help desks page; others keep the settings tab.
        $staff = $this->di['mod_service']('Staff');
        if ($staff->hasPermission(null, 'support', 'manage_helpdesk') && !$staff->hasPermission(null, 'support', 'manage_settings')) {
            $nav['subpages'][] = [
                'location' => 'support',
                'index' => 350,
                'label' => __trans('Helpdesks'),
                'uri' => $this->di['url']->adminLink('support/helpdesks'),
                'class' => '',
            ];
        }

        if ($this->di['mod']('support')->getService()->kbEnabled()) {
            $nav['subpages'][] =
                [
                    'location' => 'support',
                    'index' => 500,
                    'label' => __trans('Knowledge Base'),
                    'uri' => $this->di['url']->adminLink('support/kb'),
                    'class' => '',
                ];
        }

        // ClientFence core patch: with tickets off (Support settings) only the knowledge base and network
        // issues stay (other modules' entries, such as Announcements, too; an empty group is dropped).
        if (!$this->di['mod']('support')->getService()->ticketsEnabled()) {
            $nav['subpages'] = array_values(array_filter($nav['subpages'], fn (array $page): bool => str_contains($page['uri'], 'support/kb') || str_contains($page['uri'], 'support/network-issues')));
        }

        return $nav;
    }

    public function register(\ClientFence\App &$app): void
    {
        $app->get('/support', 'get_index', [], static::class);
        $app->get('/support/', 'get_index', [], static::class);
        $app->get('/support/index', 'get_index', [], static::class);
        $app->get('/support/ticket/:id', 'get_ticket', ['id' => '[0-9]+'], static::class);
        // ClientFence: a ticket attachment for staff with the support permission (TicketFiles.php).
        $app->get('/support/ticket-file/:id', 'get_ticket_file', ['id' => '[a-f0-9]{32}'], static::class);
        $app->get('/support/ticket/:id/message/:messageid', 'get_ticket', ['id' => '[0-9]+', 'messageid' => '[0-9]+'], static::class);
        $app->get('/support/helpdesks', 'get_helpdesks', [], static::class);
        $app->get('/support/helpdesk/:id', 'get_helpdesk', ['id' => '[0-9]+'], static::class);
        $app->get('/support/canned-responses', 'get_canned_list', [], static::class);
        $app->get('/support/email-import', 'get_email_import', [], static::class);
        $app->get('/support/ratings', 'get_ratings', [], static::class);
        // ClientFence: network issues and one issue (0: a new one) (NetworkIssues.php).
        $app->get('/support/network-issues', 'get_network_issues', [], static::class);
        $app->get('/support/network-issue/:id', 'get_network_issue', ['id' => '[0-9]+'], static::class);
        $app->get('/support/canned/:id', 'get_canned', ['id' => '[0-9]+'], static::class);
        $app->get('/support/canned-category/:id', 'get_canned_cat', ['id' => '[0-9]+'], static::class);

        if ($this->di['mod']('support')->getService()->kbEnabled()) {
            $app->get('/support/kb', 'get_kb_index', [], static::class);
            $app->get('/support/kb/article/:id', 'get_kb_article', ['id' => '[0-9]+'], static::class);
            $app->get('/support/kb/category/:id', 'get_kb_category', ['id' => '[0-9]+'], static::class);
        }
    }

    /** ClientFence: the ticket email import log (MailImport). */
    public function get_email_import(\ClientFence\App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_support_mail_log');
    }

    /** ClientFence: Support > Network Issues (NetworkIssues.php). */
    public function get_network_issues(\ClientFence\App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_support_network_issues');
    }

    /** ClientFence: a network issue to edit, or a new one (id 0). */
    public function get_network_issue(\ClientFence\App $app, $id): string
    {
        $this->di['is_admin_logged'];
        $issue = (int) $id > 0 ? $this->di['api_admin']->support_network_issue_get(['id' => (int) $id]) : null;

        return $app->render('mod_support_network_issue', ['issue' => $issue]);
    }

    /** ClientFence: Support > Ratings & Feedback (TicketFeedback::report()). */
    public function get_ratings(\ClientFence\App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_support_ratings');
    }

    public function get_index(\ClientFence\App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_support_tickets');
    }

    public function get_ticket(\ClientFence\App $app, $id, $messageid = ''): string|\Symfony\Component\HttpFoundation\Response
    {
        // ClientFence core patch: a merged ticket's page opens the ticket it went into (TicketMerge).
        $this->di['is_admin_logged'];
        $merged = \ClientFence\Mod\Support\TicketMerge::target($this->di, (int) $id);
        if ($merged !== null) {
            return $app->redirectUrl($this->di['url']->adminLink('support/ticket/' . $merged, ['merged' => (int) $id]));
        }

        $api = $this->di['api_admin'];
        $ticket = $api->support_ticket_get(['id' => $id]);

        $cdm = '';
        $mod = $this->di['mod']('support');
        $config = $mod->getConfig();

        try {
            if (isset($config['delay_enable']) && $config['delay_enable'] && isset($config['delay_hours']) && $config['delay_hours'] >= 0) {
                $last_message = end($ticket['messages']);
                reset($ticket);

                $hours_passed = (round((time() - strtotime((string) $last_message['created_at'])) / 3600) > $config['delay_hours']);
                if ($hours_passed) {
                    $delay_canned = $api->support_canned_get(['id' => $config['delay_message_id']]);
                    $cdm = $delay_canned['content'];
                }
            }
        } catch (\Exception $e) {
            $this->di['logger']->error($e->getMessage());
        }

        return $app->render('mod_support_ticket', ['ticket' => $ticket, 'canned_delay_message' => $cdm, 'request_message' => $messageid]);
    }

    public function get_helpdesk(\ClientFence\App $app, $id): string
    {
        $api = $this->di['api_admin'];
        $helpdesk = $api->support_helpdesk_get(['id' => $id]);

        return $app->render('mod_support_helpdesk', ['helpdesk' => $helpdesk]);
    }

    public function get_helpdesks(\ClientFence\App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_support_helpdesks');
    }

    public function get_canned_list(\ClientFence\App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_support_canned_responses');
    }

    public function get_canned(\ClientFence\App $app, $id): string
    {
        $api = $this->di['api_admin'];
        $c = $api->support_canned_get(['id' => $id]);

        return $app->render('mod_support_canned_response', ['response' => $c]);
    }

    public function get_canned_cat(\ClientFence\App $app, $id): string
    {
        $api = $this->di['api_admin'];
        $c = $api->support_canned_category_get(['id' => $id]);

        return $app->render('mod_support_canned_category', ['category' => $c]);
    }

    /*
    * Support Knowledge Base.
    */
    public function get_kb_index(\ClientFence\App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_support_kb_index');
    }

    public function get_kb_article(\ClientFence\App $app, $id): string
    {
        $api = $this->di['api_admin'];
        $post = $api->support_kb_article_get(['id' => $id]);

        return $app->render('mod_support_kb_article', ['post' => $post]);
    }

    public function get_kb_category(\ClientFence\App $app, $id): string
    {
        $api = $this->di['api_admin'];
        $cat = $api->support_kb_category_get(['id' => $id]);

        return $app->render('mod_support_kb_category', ['category' => $cat]);
    }

    /**
     * ClientFence: a ticket attachment for staff with the support permission (TicketFiles.php).
     */
    public function get_ticket_file(\ClientFence\App $app, $id): \Symfony\Component\HttpFoundation\Response
    {
        $this->di['is_admin_logged'];
        // ClientFence core patch: admin ticket downloads require permission to view tickets.
        $this->di['mod_service']('staff')->checkPermissionsAndThrowException('support', 'view');

        $file = \ClientFence\Mod\Support\TicketFiles::find($this->di, (string) $id);
        if ($file === null) {
            return new \Symfony\Component\HttpFoundation\Response('File not found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return \ClientFence\Mod\Support\TicketFiles::response($file);
    }
}
