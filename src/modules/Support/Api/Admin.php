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
 * Support management module.
 */

namespace ClientFence\Mod\Support\Api;

use ClientFence\Mod\Support\Entity\CannedResponse;
use ClientFence\Mod\Support\Entity\CannedResponseCategory;
use ClientFence\Mod\Support\Entity\Helpdesk;
use ClientFence\Mod\Support\Entity\KbArticle;
use ClientFence\Mod\Support\Entity\KbArticleCategory;
use ClientFence\Mod\Support\Entity\SupportTicket;
use ClientFence\PaginationOptions;
use ClientFence\Validation\Api\RequiredParams;

class Admin extends \ClientFence\Api\AbstractApi
{
    /**
     * Get tickets list.
     *
     * @optional string status - filter tickets by status
     * @optional string date_from - show tickets created since this day. Can be any string parsable by strtotime()
     * @optional string date_to - show tickets created until this day. Can be any string parsable by strtotime()
     */
    public function ticket_get_list(array $data): array
    {
        $this->checkPermissions('support', 'view');

        $repo = $this->getService()->getSupportTicketRepository();
        // ClientFence core patch: pinned tickets first (TicketBulk), on the staff list only.
        $data['bf_pinned_first'] = \ClientFence\Mod\Support\TicketBulk::pinnedIds($this->getDi());
        // ClientFence core patch: `assigned`: me, none, any or a staff id (TicketAssign).
        $data['bf_assigned'] = \ClientFence\Mod\Support\TicketAssign::listFilter($this->getDi(), $data['assigned'] ?? '', $this->ticketFileAdminId());

        $pager = $this->getDi()['pager']->paginateMappedQuery($repo->getSearchQueryBuilder($data), PaginationOptions::fromArray($data), static fn (SupportTicket $ticket): SupportTicket => $ticket);
        // ClientFence core patch: the page's tickets in a few queries (Support\Service::listToApiArray()).
        $pager['list'] = $this->getService()->listToApiArray($pager['list'], $this->getIdentity());

        return $pager;
    }

    /**
     * Return ticket full details.
     */
    #[RequiredParams(['id' => 'Ticket ID is missing'])]
    public function ticket_get(array $data): array
    {
        $this->checkPermissions('support', 'view');

        $model = $this->getService()->getTicketById((int) $data['id']);

        return $this->getService()->toApiArray($model, true, $this->getIdentity());
    }

    /**
     * Update ticket details.
     *
     * @optional int $support_helpdesk_id - ticket helpdesk id
     * @optional string $status - ticket status
     * @optional string $subject - ticket subject
     * @optional string $priority - ticket priority: low, medium or high (ClientFence)
     * @optional int $assigned_to - staff member the ticket is assigned to, 0 for nobody (ClientFence)
     */
    #[RequiredParams(['id' => 'Ticket ID is missing'])]
    public function ticket_update(array $data): bool
    {
        $this->checkPermissions('support', 'manage_tickets');

        $model = $this->getService()->getTicketById((int) $data['id']);

        // ClientFence core patch: tags stripped but no HTML entities stored (Twig escapes on output; "R&D" stayed "R&amp;D").
        if (isset($data['subject'])) {
            $data['subject'] = \ClientFence\Tools::sanitizePlainText($data['subject']);
        }

        $wasClosed = $model->getStatus() === SupportTicket::STATUS_CLOSED;
        $updated = $this->getService()->ticketUpdate($model, $data);
        // ClientFence core patch: staff closing it from the status field ask for feedback too, as a
        // close does (TicketFeedback); an escalation rule's status change does not, as WHMCS.
        if (!$wasClosed && ($data['status'] ?? null) === SupportTicket::STATUS_CLOSED) {
            \ClientFence\Mod\Support\TicketFeedback::afterClose($this->getDi(), (int) $model->getId());
        }

        // ClientFence core patch: assign the ticket, as WHMCS's Flag (TicketAssign).
        if (array_key_exists('assigned_to', $data)) {
            \ClientFence\Mod\Clientfence\Service::ensureTables($this->getDi(), true);
            \ClientFence\Mod\Support\TicketAssign::assign($this->getDi(), (int) $model->getId(), (int) $data['assigned_to'], $this->ticketFileAdminId() ?: null);
        }

        return $updated;
    }

    /**
     * ClientFence: staff members a ticket can be assigned to (TicketAssign), id => name.
     *
     * @return array<int, string>
     */
    public function ticket_assignable_staff($data): array
    {
        $this->checkPermissions('support', 'view');

        return \ClientFence\Mod\Support\TicketAssign::staff($this->getDi());
    }

    /**
     * ClientFence: Support > Ratings & Feedback (TicketFeedback): reply stars and feedback scores
     * by staff member and the latest comments. `period`: 30, 90 (default), 365 or all (days).
     */
    public function ticket_feedback_report($data): array
    {
        $this->checkPermissions('support', 'view');

        return \ClientFence\Mod\Support\TicketFeedback::report($this->getDi(), $data['period'] ?? '90');
    }

    /**
     * ClientFence: network issues, as WHMCS (NetworkIssues.php). The list of a tab: `group` (open,
     * scheduled, resolved; empty: all), `page`, `per_page`; with the tabs' counts.
     */
    public function network_issue_get_list($data): array
    {
        $this->checkPermissions('support', 'view');
        \ClientFence\Mod\Support\NetworkIssues::ensure($this->getDi());

        return \ClientFence\Mod\Support\NetworkIssues::getList($this->getDi(), (string) ($data['group'] ?? ''), (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 25))
            + ['counts' => \ClientFence\Mod\Support\NetworkIssues::counts($this->getDi())];
    }

    /** ClientFence: one network issue. `id`. */
    public function network_issue_get($data): array
    {
        $this->checkPermissions('support', 'view');
        \ClientFence\Mod\Support\NetworkIssues::ensure($this->getDi());

        return \ClientFence\Mod\Support\NetworkIssues::get($this->getDi(), (int) ($data['id'] ?? 0));
    }

    /**
     * ClientFence: creates (no `id`) or updates a network issue (NetworkIssues::save() lists the
     * fields); `email` on emails the affected clients. Returns {id, emailed}.
     */
    public function network_issue_save($data): array
    {
        // ClientFence core patch: network issues use Support's declared ticket management permission.
        $this->checkPermissions('support', 'manage_tickets');
        \ClientFence\Mod\Support\NetworkIssues::ensure($this->getDi());

        return \ClientFence\Mod\Support\NetworkIssues::save($this->getDi(), is_array($data) ? $data : [], (int) $this->getIdentity()->id);
    }

    /** ClientFence: deletes a network issue. `id`. */
    public function network_issue_delete($data): bool
    {
        $this->checkPermissions('support', 'manage_tickets');
        \ClientFence\Mod\Support\NetworkIssues::ensure($this->getDi());
        \ClientFence\Mod\Support\NetworkIssues::delete($this->getDi(), (int) ($data['id'] ?? 0));

        return true;
    }

    /** ClientFence: the network issue form's choices: types, priorities, statuses and servers. */
    public function network_issue_options($data): array
    {
        $this->checkPermissions('support', 'view');

        return [
            'types' => \ClientFence\Mod\Support\NetworkIssues::types(),
            'priorities' => \ClientFence\Mod\Support\NetworkIssues::priorities(),
            'statuses' => \ClientFence\Mod\Support\NetworkIssues::statuses(),
            'servers' => \ClientFence\Mod\Support\NetworkIssues::servers($this->getDi()),
        ];
    }

    /**
     * ClientFence: Support settings > Network Status: `network_status` (the feature on or off) and
     * `network_status_public` (the client page without signing in).
     */
    public function network_status_update($data): bool
    {
        $di = $this->getDi();
        $di['mod_service']('extension')->hasManagePermission('mod_support');
        $on = \ClientFence\Tools::normalizeBoolean($data['network_status'] ?? false, false);
        $public = \ClientFence\Tools::normalizeBoolean($data['network_status_public'] ?? false, false);
        $extension = $di['mod_service']('extension');
        $extension->setConfig(array_merge($extension->getConfig('mod_support'), [
            'network_status' => $on ? '1' : '0',
            'network_status_public' => $public ? '1' : '0',
            'ext' => 'mod_support',
        ]));
        $di['logger']->info('Support: network status %s%s', $on ? 'on' : 'off', $on && $public ? ', public' : '');

        return true;
    }

    /**
     * ClientFence: the escalation rules, as WHMCS (TicketEscalation), by name.
     */
    public function escalation_rule_get_list($data): array
    {
        $this->checkPermissions('support', 'view');

        return \ClientFence\Mod\Support\TicketEscalation::rules($this->getDi());
    }

    /**
     * ClientFence: one escalation rule. `id`.
     */
    public function escalation_rule_get($data): array
    {
        $this->checkPermissions('support', 'view');
        \ClientFence\Mod\Clientfence\Service::ensureTables($this->getDi(), true);

        return \ClientFence\Mod\Support\TicketEscalation::get($this->getDi(), (int) ($data['id'] ?? 0));
    }

    /**
     * ClientFence: creates (no `id`) or updates an escalation rule (TicketEscalation::save lists the
     * fields), for staff who may change the Support settings. Returns its id.
     */
    public function escalation_rule_save($data): int
    {
        $this->getDi()['mod_service']('extension')->hasManagePermission('mod_support');
        \ClientFence\Mod\Clientfence\Service::ensureTables($this->getDi(), true);

        return \ClientFence\Mod\Support\TicketEscalation::save($this->getDi(), $data);
    }

    /**
     * ClientFence: deletes an escalation rule. `id`.
     */
    public function escalation_rule_delete($data): bool
    {
        $this->getDi()['mod_service']('extension')->hasManagePermission('mod_support');
        \ClientFence\Mod\Clientfence\Service::ensureTables($this->getDi(), true);
        \ClientFence\Mod\Support\TicketEscalation::delete($this->getDi(), (int) ($data['id'] ?? 0));

        return true;
    }

    /**
     * Update ticket message.
     */
    #[RequiredParams(['id' => 'Ticket message ID is missing', 'content' => 'Ticket message content is missing'])]
    public function ticket_message_update(array $data): bool
    {
        $this->checkPermissions('support', 'manage_tickets');

        $data['content'] = \ClientFence\Tools::sanitizeMarkdownContent($data['content']);

        $model = $this->getService()->getTicketMessageById((int) $data['id']);

        return $this->getService()->ticketMessageUpdate($model, $data['content'], $this->getIdentity());
    }

    /**
     * Return the edit history of a ticket message, most recent edit first.
     */
    #[RequiredParams(['id' => 'Ticket message ID is missing'])]
    public function ticket_message_history_get_list(array $data): array
    {
        $this->checkPermissions('support', 'view');

        $model = $this->getService()->getTicketMessageById((int) $data['id']);

        return $this->getService()->getMessageHistory($model);
    }

    /**
     * Delete ticket.
     */
    #[RequiredParams(['id' => 'Ticket ID is missing'])]
    public function ticket_delete(array $data): bool
    {
        $this->checkPermissions('support', 'manage_tickets');

        $model = $this->getService()->getTicketById((int) $data['id']);

        return $this->getService()->rm($model);
    }

    /**
     * Add new conversation message to to ticket.
     *
     * @return int - ticket message id
     */
    #[RequiredParams(['id' => 'Ticket ID is missing', 'content' => 'Ticket message content is missing'])]
    public function ticket_reply(array $data): int
    {
        $this->checkPermissions('support', 'manage_tickets');

        $data['content'] = \ClientFence\Tools::sanitizeMarkdownContent($data['content']);

        $ticket = $this->getService()->getTicketById((int) $data['id']);

        return $this->getService()->ticketReply($ticket, $this->getIdentity(), $data['content']);
    }

    /**
     * Close ticket.
     */
    #[RequiredParams(['id' => 'Ticket ID is missing'])]
    public function ticket_close(array $data): bool
    {
        $this->checkPermissions('support', 'manage_tickets');

        $ticket = $this->getService()->getTicketById((int) $data['id']);

        if ($ticket->getStatus() === SupportTicket::STATUS_CLOSED) {
            return true;
        }

        return $this->getService()->closeTicket($ticket, $this->getIdentity());
    }

    /**
     * Method to create open new ticket. Tickets can have tasks assigned to them
     * via optional parameters.
     *
     * @optional string $status - Ticket status. Default - on hold
     *
     * @return int $id - ticket id
     */
    #[RequiredParams(['client_id' => 'Client ID is missing', 'content' => 'Ticket content required', 'subject' => 'Ticket subject required', 'support_helpdesk_id' => 'Ticket support_helpdesk_id is required'])]
    public function ticket_create(array $data): int
    {
        $this->checkPermissions('support', 'manage_tickets');

        $data['content'] = \ClientFence\Tools::sanitizeMarkdownContent($data['content']);

        /** @var \ClientFence\Mod\Support\Repository\HelpdeskRepository $repo */
        $repo = $this->getService()->getHelpdeskRepository();

        $helpdesk = $repo->find((int) $data['support_helpdesk_id']);
        if (!$helpdesk instanceof Helpdesk) {
            throw new \ClientFence\InformationException('Helpdesk invalid');
        }

        return $this->getService()->ticketCreateForAdmin((int) $data['client_id'], $helpdesk, $data, $this->getIdentity());
    }

    /**
     * Action to close all tickets which have not received any replies for a
     * time defined in helpdesk.
     *
     * Run by cron job
     */
    public function batch_ticket_auto_close($data): bool
    {
        $this->checkPermissions('support', 'manage_tickets');

        // Auto close support tickets
        $expiredArr = $this->getService()->getExpired();

        foreach ($expiredArr as $ticketArr) {
            $ticketModel = $this->getService()->getTicketById((int) $ticketArr['id']);
            if (!$this->getService()->autoClose($ticketModel)) {
                $this->getDi()['logger']->info('Ticket %s was not closed', $ticketModel->getId());
            }
        }

        return true;
    }

    /**
     * Return tickets statuses with counter.
     */
    public function ticket_get_statuses(array $data): array
    {
        $this->checkPermissions('support', 'view');

        if (isset($data['titles'])) {
            return $this->getService()->getStatuses();
        }

        return $this->getService()->counter();
    }

    /**
     * Get helpdesk list.
     */
    public function helpdesk_get_list(array $data): array
    {
        $this->checkPermissions('support', 'view');

        /** @var \ClientFence\Mod\Support\Repository\HelpdeskRepository $repo */
        $repo = $this->getService()->getHelpdeskRepository();

        $qb = $repo->getSearchQueryBuilder($data);

        $result = $this->getDi()['pager']->paginateDoctrineQuery($qb, PaginationOptions::fromArray($data), $this->getIdentity());
        // ClientFence core patch: which help desks are hidden from clients (HiddenHelpdesks).
        $result['list'] = \ClientFence\Mod\Support\HiddenHelpdesks::withFlags($this->getDi(), $result['list'] ?? []);

        return $result;
    }

    /**
     * Get pairs of helpdesks.
     */
    public function helpdesk_get_pairs(array $data): array
    {
        $this->checkPermissions('support', 'view');

        return $this->getService()->getHelpdeskRepository()->getPairs();
    }

    /**
     * Get helpdesk details.
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Help desk ID is missing'])]
    public function helpdesk_get(array $data): array
    {
        $this->checkPermissions('support', 'view');

        /** @var \ClientFence\Mod\Support\Repository\HelpdeskRepository $repo */
        $repo = $this->getService()->getHelpdeskRepository();

        $model = $repo->find((int) $data['id']);
        if (!$model instanceof Helpdesk) {
            throw new \ClientFence\InformationException('Help desk not found');
        }

        // ClientFence core patch: help desk greeting (ClientFence, modules/Clientfence/Service.php).
        $helpdesk = $model->toApiArray($this->getIdentity());
        // ClientFence core patch: the help desk's feedback request (TicketFeedback).
        $helpdesk['feedback_request'] = \ClientFence\Mod\Support\TicketFeedback::helpdeskOn($this->getDi(), (int) $model->getId());
        // ClientFence core patch: whether clients see the help desk (HiddenHelpdesks).
        $helpdesk['hidden'] = \ClientFence\Mod\Support\HiddenHelpdesks::isHidden($this->getDi(), (int) $model->getId());

        return \ClientFence\Mod\Clientfence\Service::withHelpdeskGreeting($this->getDi(), $helpdesk, $this->getIdentity());
    }

    /**
     * Update helpdesk parameters.
     *
     * @optional string $name - helpdesk name
     * @optional string $email - helpdesk email
     * @optional string $can_reopen - flag to enable/disable ability to reopen closed tickets
     * @optional int $close_after - time to wait for reply before auto closing ticket
     * @optional string $signature - helpdesk signature
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Help desk ID is missing'])]
    public function helpdesk_update(array $data): bool
    {
        $this->checkPermissions('support', 'manage_helpdesk');

        /** @var \ClientFence\Mod\Support\Repository\HelpdeskRepository $repo */
        $repo = $this->getService()->getHelpdeskRepository();

        $model = $repo->find((int) $data['id']);
        if (!$model instanceof Helpdesk) {
            throw new \ClientFence\InformationException('Help desk not found');
        }

        $updated = $this->getService()->helpdeskUpdate($model, $data);
        // ClientFence core patch: the help desk's name per language (Product/Translations.php), saved with its form.
        if (is_array($data['translations'] ?? null)) {
            \ClientFence\Mod\Product\Translations::save($this->getDi(), 'helpdesk', (int) $model->getId(), $data['translations']);
        }

        return $updated;
    }

    /**
     * Create new helpdesk.
     *
     * @optional string $email - helpdesk email
     * @optional string $can_reopen - flag to enable/disable ability to reopen closed tickets
     * @optional int $close_after - time to wait for reply before auto closing ticket
     * @optional string $signature - helpdesk signature
     *
     * @return int - id
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['name' => 'Help desk title is missing'])]
    public function helpdesk_create(array $data): int
    {
        $this->checkPermissions('support', 'manage_helpdesk');

        return $this->getService()->helpdeskCreate($data);
    }

    /**
     * Delete helpdesk.
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Help desk ID is missing'])]
    public function helpdesk_delete(array $data): bool
    {
        $this->checkPermissions('support', 'manage_helpdesk');

        /** @var \ClientFence\Mod\Support\Repository\HelpdeskRepository $repo */
        $repo = $this->getService()->getHelpdeskRepository();

        $model = $repo->find((int) $data['id']);
        if (!$model instanceof Helpdesk) {
            throw new \ClientFence\InformationException('Help desk not found');
        }

        $id = (int) $model->getId();
        $removed = $this->getService()->helpdeskRm($model);
        // ClientFence core patch: its names in other languages go with it (Product/Translations.php).
        \ClientFence\Mod\Product\Translations::forget($this->getDi(), 'helpdesk', $id);

        return $removed;
    }

    /**
     * Get list of canned responses.
     */
    public function canned_get_list(array $data): array
    {
        $this->checkPermissions('support', 'view');

        /** @var \ClientFence\Mod\Support\Repository\CannedResponseRepository $repo */
        $repo = $this->getService()->getCannedResponseRepository();

        $qb = $repo->getSearchQueryBuilder($data);

        return $this->getDi()['pager']->paginateDoctrineQuery($qb, PaginationOptions::fromArray($data));
    }

    /**
     * Get list of canned responses grouped by category.
     */
    public function canned_pairs(): array
    {
        $this->checkPermissions('support', 'view');

        /** @var \ClientFence\Mod\Support\Repository\CannedResponseRepository $repo */
        $repo = $this->getService()->getCannedResponseRepository();

        return $repo->getGroupedPairs();
    }

    /**
     * Get canned response details.
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Canned reply ID is missing'])]
    public function canned_get(array $data): array
    {
        $this->checkPermissions('support', 'view');

        /** @var \ClientFence\Mod\Support\Repository\CannedResponseRepository $repo */
        $repo = $this->getService()->getCannedResponseRepository();

        $model = $repo->find((int) $data['id']);
        if (!$model instanceof CannedResponse) {
            throw new \ClientFence\InformationException('Canned reply not found');
        }

        return $model->toApiArray();
    }

    /**
     * Delete canned response.
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Canned reply ID is missing'])]
    public function canned_delete(array $data): bool
    {
        $this->checkPermissions('support', 'manage_canned');

        /** @var \ClientFence\Mod\Support\Repository\CannedResponseRepository $repo */
        $repo = $this->getService()->getCannedResponseRepository();

        $model = $repo->find((int) $data['id']);
        if (!$model instanceof CannedResponse) {
            throw new \ClientFence\InformationException('Canned reply not found');
        }

        return $this->getService()->cannedRm($model);
    }

    /**
     * Create new canned response.
     *
     * @optional string $content - canned response content
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['title' => 'Canned reply title is missing', 'category_id' => 'Canned reply category ID is missing'])]
    public function canned_create(array $data): int
    {
        $this->checkPermissions('support', 'manage_canned');

        $content = $data['content'] ?? null;

        return $this->getService()->cannedCreate($data['title'], (int) $data['category_id'], $content);
    }

    /**
     * Update canned response.
     *
     * @optional string $title - canned response title
     * @optional int $category_id - canned response category id
     * @optional string $content - canned response content
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Canned reply ID is missing'])]
    public function canned_update(array $data): bool
    {
        $this->checkPermissions('support', 'manage_canned');

        /** @var \ClientFence\Mod\Support\Repository\CannedResponseRepository $repo */
        $repo = $this->getService()->getCannedResponseRepository();

        $model = $repo->find((int) $data['id']);
        if (!$model instanceof CannedResponse) {
            throw new \ClientFence\InformationException('Canned reply not found');
        }

        return $this->getService()->cannedUpdate($model, $data);
    }

    /**
     * Get canned response pairs.
     */
    public function canned_category_pairs(array $data): array
    {
        $this->checkPermissions('support', 'view');

        /** @var \ClientFence\Mod\Support\Repository\CannedResponseCategoryRepository $repo */
        $repo = $this->getService()->getCannedResponseCategoryRepository();

        return $repo->getPairs();
    }

    /**
     * Get canned response category.
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Canned category ID is missing'])]
    public function canned_category_get(array $data): array
    {
        $this->checkPermissions('support', 'view');

        /** @var \ClientFence\Mod\Support\Repository\CannedResponseCategoryRepository $repo */
        $repo = $this->getService()->getCannedResponseCategoryRepository();

        $model = $repo->find((int) $data['id']);
        if (!$model instanceof CannedResponseCategory) {
            throw new \ClientFence\InformationException('Canned category not found');
        }

        return $model->toApiArray();
    }

    /**
     * Get canned response category.
     *
     * @optional string $title - new category title
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Canned category ID is missing'])]
    public function canned_category_update(array $data): bool
    {
        $this->checkPermissions('support', 'manage_canned');

        /** @var \ClientFence\Mod\Support\Repository\CannedResponseCategoryRepository $repo */
        $repo = $this->getService()->getCannedResponseCategoryRepository();

        $model = $repo->find((int) $data['id']);
        if (!$model instanceof CannedResponseCategory) {
            throw new \ClientFence\InformationException('Canned category not found');
        }

        $title = $data['title'] ?? $model->getTitle();

        return $this->getService()->cannedCategoryUpdate($model, $title);
    }

    /**
     * Delete canned response category.
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Canned category ID is missing'])]
    public function canned_category_delete(array $data): bool
    {
        $this->checkPermissions('support', 'manage_canned');

        /** @var \ClientFence\Mod\Support\Repository\CannedResponseCategoryRepository $repo */
        $repo = $this->getService()->getCannedResponseCategoryRepository();

        $model = $repo->find((int) $data['id']);
        if (!$model instanceof CannedResponseCategory) {
            throw new \ClientFence\InformationException('Canned category not found');
        }

        return $this->getService()->cannedCategoryRm($model);
    }

    /**
     * Create canned response category.
     *
     * @return int - new category id
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['title' => 'Canned category title is missing'])]
    public function canned_category_create(array $data): int
    {
        $this->checkPermissions('support', 'manage_canned');

        return $this->getService()->cannedCategoryCreate($data['title']);
    }

    /**
     * Add note to support ticket.
     *
     * @return int - new note id
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['ticket_id' => 'ticket_ID is missing', 'note' => 'Note is missing'])]
    public function note_create(array $data): int
    {
        $this->checkPermissions('support', 'manage_tickets');

        $ticket = $this->getService()->getTicketById((int) $data['ticket_id']);

        return $this->getService()->noteCreate($ticket, $this->getIdentity(), $data['note']);
    }

    /**
     * Delete note from support ticket.
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Note ID is missing'])]
    public function note_delete(array $data): bool
    {
        $this->checkPermissions('support', 'manage_tickets');

        $model = $this->getService()->getTicketNoteById((int) $data['id']);

        return $this->getService()->noteRm($model);
    }

    /**
     * Set support ticket related task to completed.
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Ticket ID is missing'])]
    public function task_complete(array $data): bool
    {
        $this->checkPermissions('support', 'manage_tickets');

        $model = $this->getService()->getTicketById((int) $data['id']);
        // ClientFence core patch: a domain request ticket also stores the domain's values (Servicedomain/DomainTickets.php).
        if ($model->getRelTask() === \ClientFence\Mod\Servicedomain\DomainTickets::TASK) {
            $this->checkPermissions('servicedomain', 'manage_domains');
        }

        return $this->getService()->ticketTaskComplete($model, $this->getIdentity());
    }

    /**
     * Deletes tickets with given IDs.
     */
    #[RequiredParams(['ids' => 'IDs were not passed'])]
    public function batch_delete($data): bool
    {
        $this->checkPermissions('support', 'manage_tickets');

        foreach ($data['ids'] as $id) {
            $this->ticket_delete(['id' => $id]);
        }

        return true;
    }

    /*
     * Support Knowledge Base.
     */
    /**
     * Get paginated list of knowledge base articles.
     */
    public function kb_article_get_list(array $data): array
    {
        $this->checkPermissions('support', 'view');

        $status = $data['status'] ?? null;
        $search = $data['search'] ?? null;
        $cat = $data['kb_article_category_id'] ?? $data['cat'] ?? null;

        /** @var \ClientFence\Mod\Support\Repository\KbArticleRepository $repo */
        $repo = $this->getService()->getKbArticleRepository();

        $qb = $repo->getSearchQueryBuilder($status, $search, $cat);

        return $this->getDi()['pager']->paginateDoctrineQuery($qb, PaginationOptions::fromArray($data), $this->getIdentity());
    }

    /**
     * Get knowledge base article.
     */
    #[RequiredParams(['id' => 'Article ID was not passed'])]
    public function kb_article_get(array $data): array
    {
        $this->checkPermissions('support', 'view');

        /** @var \ClientFence\Mod\Support\Repository\KbArticleRepository $repo */
        $repo = $this->getService()->getKbArticleRepository();

        $article = $repo->find((int) $data['id']);

        if (!$article instanceof KbArticle) {
            throw new \ClientFence\InformationException('Article not found');
        }

        return $article->toApiArray($this->getIdentity(), includeContent: true);
    }

    /**
     * Create new knowledge base article.
     *
     * @optional string $status - knowledge base article status
     * @optional string $content - knowledge base article content
     */
    #[RequiredParams(['kb_article_category_id' => 'Article category ID was not passed', 'title' => 'Article title not passed'])]
    public function kb_article_create(array $data): int
    {
        $this->checkPermissions('support', 'manage_kb');

        $articleCategoryId = (int) $data['kb_article_category_id'];
        $status = $data['status'] ?? KbArticle::DRAFT;

        $title = \ClientFence\Tools::sanitizePlainText($data['title']);
        $content = isset($data['content']) ? \ClientFence\Tools::sanitizeMarkdownContent($data['content']) : null;

        return $this->getService()->kbCreateArticle($articleCategoryId, $title, $status, $content);
    }

    /**
     * Update knowledge base article.
     *
     * @optional string $title - knowledge base article title
     * @optional int $kb_article_category_id - knowledge base category ID
     * @optional string $slug - knowledge base article slug
     * @optional string $status - knowledge base article status
     * @optional string $content - knowledge base article content
     * @optional int $views - knowledge base article views counter
     */
    #[RequiredParams(['id' => 'Article ID was not passed'])]
    public function kb_article_update(array $data): bool
    {
        $this->checkPermissions('support', 'manage_kb');

        $articleCategoryId = isset($data['kb_article_category_id']) ? (int) $data['kb_article_category_id'] : null;
        $title = isset($data['title']) ? \ClientFence\Tools::sanitizePlainText($data['title']) : null;
        $slug = $data['slug'] ?? null;
        $status = $data['status'] ?? null;
        $content = isset($data['content']) ? \ClientFence\Tools::sanitizeMarkdownContent($data['content']) : null;
        $views = isset($data['views']) ? (int) $data['views'] : null;

        return $this->getService()->kbUpdateArticle((int) $data['id'], $articleCategoryId, $title, $slug, $status, $content, $views);
    }

    /**
     * Delete knowledge base article.
     */
    #[RequiredParams(['id' => 'Article ID was not passed'])]
    public function kb_article_delete($data): bool
    {
        $this->checkPermissions('support', 'manage_kb');

        /** @var \ClientFence\Mod\Support\Repository\KbArticleRepository $repo */
        $repo = $this->getService()->getKbArticleRepository();

        $article = $repo->find((int) $data['id']);

        if (!$article instanceof KbArticle) {
            throw new \ClientFence\InformationException('Article not found');
        }

        $this->getService()->kbRm($article);

        return true;
    }

    /**
     * Get paginated list of knowledge base categories.
     */
    public function kb_category_get_list(array $data): array
    {
        $this->checkPermissions('support', 'view');

        /** @var \ClientFence\Mod\Support\Repository\KbArticleCategoryRepository $repo */
        $repo = $this->getService()->getKbArticleCategoryRepository();

        $qb = $repo->getSearchQueryBuilder($data);

        $result = $this->getDi()['pager']->paginateDoctrineQuery($qb, PaginationOptions::fromArray($data), $this->getIdentity(), $data['q'] ?? null);
        // ClientFence core patch: which categories are hidden from clients (HiddenKbCategories).
        $result['list'] = \ClientFence\Mod\Support\HiddenKbCategories::withFlags($this->getDi(), $result['list'] ?? []);

        return $result;
    }

    /**
     * Get knowledge base category.
     */
    #[RequiredParams(['id' => 'Category ID was not passed'])]
    public function kb_category_get(array $data): array
    {
        $this->checkPermissions('support', 'view');

        /** @var \ClientFence\Mod\Support\Repository\KbArticleCategoryRepository $repo */
        $repo = $this->getService()->getKbArticleCategoryRepository();

        $cat = $repo->find((int) $data['id']);

        if (!$cat instanceof KbArticleCategory) {
            throw new \ClientFence\InformationException('Article Category not found');
        }

        // ClientFence core patch: whether clients see the category (HiddenKbCategories).
        return $cat->toApiArray($this->getIdentity()) + ['hidden' => \ClientFence\Mod\Support\HiddenKbCategories::isHidden($this->getDi(), $cat->getId())];
    }

    /**
     * Create new knowledge base category.
     *
     * @optional string $description - knowledge base category description
     */
    #[RequiredParams(['title' => 'Category title not passed'])]
    public function kb_category_create(array $data): int
    {
        $this->checkPermissions('support', 'manage_kb');

        $title = $data['title'];
        $description = $data['description'] ?? null;

        return $this->getService()->kbCreateCategory($title, $description);
    }

    /**
     * Update knowledge base category.
     *
     * @optional string $title - knowledge base category title
     * @optional string $slug  - knowledge base category slug
     * @optional string $description - knowledge base category description
     */
    #[RequiredParams(['id' => 'Category ID was not passed'])]
    public function kb_category_update(array $data): bool
    {
        $this->checkPermissions('support', 'manage_kb');

        /** @var \ClientFence\Mod\Support\Repository\KbArticleCategoryRepository $repo */
        $repo = $this->getService()->getKbArticleCategoryRepository();

        $cat = $repo->find((int) $data['id']);

        if (!$cat instanceof KbArticleCategory) {
            throw new \ClientFence\InformationException('Article Category not found');
        }

        $title = $data['title'] ?? null;
        $slug = $data['slug'] ?? null;
        $description = $data['description'] ?? null;

        $updated = $this->getService()->kbUpdateCategory($cat, $title, $slug, $description);
        // ClientFence core patch: the category's "Hidden from clients", as WHMCS (HiddenKbCategories).
        if (array_key_exists('hidden', $data)) {
            \ClientFence\Mod\Support\HiddenKbCategories::save($this->getDi(), (int) $cat->getId(), $data['hidden']);
        }

        return $updated;
    }

    /**
     * Delete knowledge base category.
     */
    #[RequiredParams(['id' => 'Category ID was not passed'])]
    public function kb_category_delete(array $data): bool
    {
        $this->checkPermissions('support', 'manage_kb');

        /** @var \ClientFence\Mod\Support\Repository\KbArticleCategoryRepository $repo */
        $repo = $this->getService()->getKbArticleCategoryRepository();

        $cat = $repo->find((int) $data['id']);

        if (!$cat instanceof KbArticleCategory) {
            throw new \ClientFence\InformationException('Category not found');
        }

        $id = (int) $cat->getId();
        $removed = $this->getService()->kbCategoryRm($cat);
        // ClientFence core patch: and whether it was hidden from clients (HiddenKbCategories).
        \ClientFence\Mod\Support\HiddenKbCategories::forget($this->getDi(), $id);

        return $removed;
    }

    /**
     * Get knowledge base categories id, title pairs.
     */
    public function kb_category_get_pairs(array $data): array
    {
        $this->checkPermissions('support', 'view');

        return $this->getService()->getKbArticleCategoryRepository()->getPairs();
    }

    /**
     * ClientFence: attaches a file (multipart field "file") to the staff reply about to be sent on
     * ticket `ticket_id`. Returns {id, name, size, size_label} (TicketFiles.php).
     */
    public function ticket_file_upload($data): array
    {
        $this->checkPermissions('support', 'manage_tickets');
        $di = $this->getDi();

        return \ClientFence\Mod\Support\TicketFiles::uploadForStaff($di, $this->ticketFileAdminId(), (int) ($data['ticket_id'] ?? 0), $di['request']->files->get('file'));
    }

    /**
     * ClientFence: throws away a file uploaded for a reply and not sent (`id`).
     */
    public function ticket_file_discard($data): bool
    {
        $this->checkPermissions('support', 'manage_tickets');
        \ClientFence\Mod\Support\TicketFiles::discard($this->getDi(), (string) ($data['id'] ?? ''), 'admin', $this->ticketFileAdminId());

        return true;
    }

    /**
     * ClientFence: removes a sent ticket file (`id`): the file is deleted, the message shows it was
     * removed.
     */
    public function ticket_file_remove($data): bool
    {
        $this->checkPermissions('support', 'manage_tickets');
        $identity = $this->getIdentity();
        \ClientFence\Mod\Support\TicketFiles::remove($this->getDi(), (string) ($data['id'] ?? ''), $identity instanceof \Model_Admin ? (int) $identity->id : null);

        return true;
    }

    // ClientFence core patch: opening ticket secrets needs its own permission and an audit event.
    #[RequiredParams(['ticket_id' => 'Ticket ID was not passed'])]
    public function ticket_secret_list(array $data): array
    {
        $this->checkPermissions('support', 'view');
        $di = $this->getDi();
        $result = \ClientFence\Mod\Support\TicketSecrets::listForTicket($di, (int) $data['ticket_id']);
        $result['can_open'] = $di['mod_service']('Staff')->hasPermission($this->getIdentity(), 'support', 'open_secrets');
        $result['can_delete'] = $di['mod_service']('Staff')->hasPermission($this->getIdentity(), 'support', 'manage_tickets');

        return $result;
    }

    #[RequiredParams(['id' => 'Secret ID was not passed'])]
    public function ticket_secret_open(array $data): array
    {
        $this->checkPermissions('support', 'open_secrets');

        return \ClientFence\Mod\Support\TicketSecrets::openForStaff($this->getDi(), (int) $data['id'], (int) $this->getIdentity()->id);
    }

    #[RequiredParams(['id' => 'Secret ID was not passed'])]
    public function ticket_secret_delete(array $data): bool
    {
        $this->checkPermissions('support', 'manage_tickets');
        \ClientFence\Mod\Support\TicketSecrets::delete($this->getDi(), (int) $data['id'], (int) $this->getIdentity()->id, null);

        return true;
    }

    /**
     * ClientFence: saves how many days sent ticket files are kept (`ticket_files_days`, 0: kept),
     * on the Support settings page.
     */
    public function ticket_files_update($data): bool
    {
        $di = $this->getDi();
        $di['mod_service']('extension')->hasManagePermission('mod_support');

        $days = trim((string) ($data['ticket_files_days'] ?? ''));
        if (!ctype_digit($days) || (int) $days > 3650) {
            throw new \ClientFence\InformationException('Keep ticket files for 0 to 3650 days (0: keep them).');
        }
        $extension = $di['mod_service']('extension');
        $extension->setConfig(array_merge($extension->getConfig('mod_support'), ['ticket_files_days' => (string) (int) $days, 'ext' => 'mod_support']));
        $di['logger']->info('Support: ticket files are kept for %s days', (int) $days);

        return true;
    }

    /**
     * ClientFence: a help desk's mailbox for ticket email import (MailImport); the password only as
     * `has_password`.
     */
    #[RequiredParams(['helpdesk_id' => 'Help desk ID was not passed'])]
    public function mail_mailbox_get($data): array
    {
        $this->checkPermissions('support', 'manage_helpdesk');

        return \ClientFence\Mod\Support\MailImport::mailboxForStaff($this->getDi(), (int) $data['helpdesk_id']);
    }

    /**
     * ClientFence: saves a help desk's mailbox (`enabled`, `host`, `port`, `encryption` ssl|tls|none,
     * `verify_tls`, `username`, `password` (empty keeps the saved one), `folder`, `clients_only`,
     * `replies_only`, `max_per_run`).
     */
    #[RequiredParams(['helpdesk_id' => 'Help desk ID was not passed'])]
    public function mail_mailbox_update($data): bool
    {
        $this->checkPermissions('support', 'manage_helpdesk');
        \ClientFence\Mod\Support\MailImport::saveMailbox($this->getDi(), (int) $data['helpdesk_id'], $data);
        $this->getDi()['logger']->info('Support: updated the email import mailbox of help desk #%s', (int) $data['helpdesk_id']);

        return true;
    }

    /** ClientFence: connects with a help desk's saved mailbox; returns how many emails wait. */
    #[RequiredParams(['helpdesk_id' => 'Help desk ID was not passed'])]
    public function mail_mailbox_test($data): int
    {
        $this->checkPermissions('support', 'manage_helpdesk');

        return \ClientFence\Mod\Support\MailImport::test($this->getDi(), (int) $data['helpdesk_id']);
    }

    /** ClientFence: reads the enabled mailboxes now, as the cron does; returns emails handled. */
    public function mail_import_run(): int
    {
        $this->checkPermissions('support', 'manage_helpdesk');

        return array_sum(\ClientFence\Mod\Support\MailImport::run($this->getDi()));
    }

    /** ClientFence: saves the ticket email import settings (Support settings page). */
    public function mail_settings_update($data): bool
    {
        $di = $this->getDi();
        $di['mod_service']('extension')->hasManagePermission('mod_support');
        \ClientFence\Mod\Support\MailImport::saveSettings($di, $data);
        $di['logger']->info('Support: updated the email import settings');

        return true;
    }

    /**
     * ClientFence: the email import log (`filter`: all, imported or not_imported; `page`,
     * `per_page`).
     */
    public function mail_log_get_list($data): array
    {
        $this->checkPermissions('support', 'view');

        return \ClientFence\Mod\Support\MailImport::getLog($this->getDi(), (string) ($data['filter'] ?? 'all'), (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 25));
    }

    /** ClientFence: imports a logged email the checks refused (WHMCS's "Ignore Error and Import"). */
    #[RequiredParams(['id' => 'Log entry ID was not passed'])]
    public function mail_log_import($data): array
    {
        $this->checkPermissions('support', 'manage_tickets');

        return \ClientFence\Mod\Support\MailImport::importAnyway($this->getDi(), (int) $data['id'], $this->ticketFileAdminId());
    }

    /**
     * ClientFence: merges tickets into the oldest, as WHMCS (TicketMerge). `ids`: two or more
     * ticket ids (a list, or text such as "12, 15"), plus `id` when given (the ticket page's
     * "merge with"); optional `subject` for the merged ticket (default: its subject with
     * " [MERGED]"). Returns the merged ticket's id.
     */
    #[RequiredParams(['ids' => 'Select the tickets to merge'])]
    public function ticket_merge($data): int
    {
        $this->checkPermissions('support', 'manage_tickets');
        $di = $this->getDi();

        $ids = is_array($data['ids']) ? $data['ids'] : (preg_split('/[\s,#]+/', (string) $data['ids'], -1, PREG_SPLIT_NO_EMPTY) ?: []);
        if (isset($data['id']) && !is_array($data['id'])) {
            $ids[] = (string) $data['id'];
        }
        $subject = isset($data['subject']) && is_string($data['subject']) ? \ClientFence\Tools::sanitizePlainText($data['subject']) : null;
        \ClientFence\Mod\Clientfence\Service::ensureTables($di, true);
        $result = \ClientFence\Mod\Support\TicketMerge::merge($di, $ids, $subject, $this->ticketFileAdminId());

        $di['events_manager']->fire(['event' => 'onAfterAdminTicketMerge', 'params' => ['id' => $result['id'], 'merged_ids' => $result['merged']]]);
        $di['logger']->info('Merged tickets #%s into ticket #%s', implode(', #', $result['merged']), $result['id']);

        return $result['id'];
    }

    /**
     * ClientFence: pins tickets to the top of the staff ticket list, as WHMCS (TicketBulk). `ids`:
     * ticket ids. Returns how many are pinned.
     */
    #[RequiredParams(['ids' => 'Select the tickets'])]
    public function batch_ticket_pin($data): int
    {
        $this->checkPermissions('support', 'manage_tickets');
        $di = $this->getDi();
        \ClientFence\Mod\Clientfence\Service::ensureTables($di, true);
        $ids = \ClientFence\Mod\Support\TicketBulk::ids($data['ids']);

        return \ClientFence\Mod\Support\TicketBulk::pin($di, $ids, $this->ticketFileAdminId() ?: null);
    }

    /**
     * ClientFence: unpins tickets (TicketBulk). Returns how many were pinned.
     */
    #[RequiredParams(['ids' => 'Select the tickets'])]
    public function batch_ticket_unpin($data): int
    {
        $this->checkPermissions('support', 'manage_tickets');
        $di = $this->getDi();
        \ClientFence\Mod\Clientfence\Service::ensureTables($di, true);

        return \ClientFence\Mod\Support\TicketBulk::unpin($di, \ClientFence\Mod\Support\TicketBulk::ids($data['ids']));
    }

    /**
     * ClientFence: the pinned tickets' ids, newest first (the ticket list marks them).
     *
     * @return list<int>
     */
    public function ticket_pinned_ids($data): array
    {
        $this->checkPermissions('support', 'view');

        return \ClientFence\Mod\Support\TicketBulk::pinnedIds($this->getDi());
    }

    /**
     * ClientFence: closes tickets, as each ticket's Close (TicketBulk). Returns how many were closed.
     */
    #[RequiredParams(['ids' => 'Select the tickets'])]
    public function batch_ticket_close($data): int
    {
        $this->checkPermissions('support', 'manage_tickets');
        $closed = 0;
        foreach (\ClientFence\Mod\Support\TicketBulk::ids($data['ids']) as $id) {
            $ticket = $this->getService()->getTicketById($id);
            if ($ticket->getStatus() !== SupportTicket::STATUS_CLOSED) {
                $this->getService()->closeTicket($ticket, $this->getIdentity());
                ++$closed;
            }
        }

        return $closed;
    }

    /**
     * ClientFence: WHMCS "Block Sender & Delete": the tickets' senders go on the email import's
     * blocked senders list, then the tickets are deleted. Returns the addresses added.
     *
     * @return list<string>
     */
    #[RequiredParams(['ids' => 'Select the tickets'])]
    public function batch_block_delete($data): array
    {
        $this->checkPermissions('support', 'manage_tickets');
        $di = $this->getDi();
        // It changes the email import's blocked senders list, a Support setting.
        $di['mod_service']('extension')->hasManagePermission('mod_support');
        $ids = \ClientFence\Mod\Support\TicketBulk::ids($data['ids']);
        $tickets = array_map(fn (int $id): SupportTicket => $this->getService()->getTicketById($id), $ids);
        $added = \ClientFence\Mod\Support\MailImport::blockSenders($di, \ClientFence\Mod\Support\TicketBulk::senders($di, $ids));
        foreach ($tickets as $ticket) {
            $this->getService()->rm($ticket);
        }
        \ClientFence\Mod\Support\TicketBulk::sweep($di);
        \ClientFence\Mod\Support\TicketAssign::sweep($di);
        \ClientFence\Mod\Support\TicketFeedback::sweep($di);
        $di['logger']->info('Blocked %s and deleted tickets #%s', $added === [] ? 'no new sender' : implode(', ', $added), implode(', #', $ids));

        return array_values($added);
    }

    /** The signed-in staff member's id (0 when none) for ticket files. */
    private function ticketFileAdminId(): int
    {
        $identity = $this->getIdentity();

        return $identity instanceof \Model_Admin ? (int) $identity->id : 0;
    }
}
