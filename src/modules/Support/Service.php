<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Support;

use ClientFence\Mod\Support\Entity\CannedResponse;
use ClientFence\Mod\Support\Entity\CannedResponseCategory;
use ClientFence\Mod\Support\Entity\Helpdesk;
use ClientFence\Mod\Support\Entity\KbArticle;
use ClientFence\Mod\Support\Entity\KbArticleCategory;
use ClientFence\Mod\Support\Entity\SupportTicket;
use ClientFence\Mod\Support\Entity\SupportTicketMessage;
use ClientFence\Mod\Support\Entity\SupportTicketMessageHistory;
use ClientFence\Mod\Support\Entity\SupportTicketNote;
use ClientFence\Mod\Support\Repository\CannedResponseCategoryRepository;
use ClientFence\Mod\Support\Repository\CannedResponseRepository;
use ClientFence\Mod\Support\Repository\HelpdeskRepository;
use ClientFence\Mod\Support\Repository\KbArticleCategoryRepository;
use ClientFence\Mod\Support\Repository\KbArticleRepository;
use ClientFence\Mod\Support\Repository\SupportTicketMessageHistoryRepository;
use ClientFence\Mod\Support\Repository\SupportTicketMessageRepository;
use ClientFence\Mod\Support\Repository\SupportTicketNoteRepository;
use ClientFence\Mod\Support\Repository\SupportTicketRepository;
use ClientFence\InformationException;
use ClientFence\Tools;
use ClientFence\Twig\Markdown\ClientFenceMarkdown;

class Service implements \ClientFence\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;
    protected KbArticleRepository $kbArticleRepository;
    protected KbArticleCategoryRepository $kbArticleCategoryRepository;
    protected CannedResponseRepository $cannedResponseRepository;
    protected CannedResponseCategoryRepository $cannedResponseCategoryRepository;
    protected HelpdeskRepository $helpdeskRepository;
    protected SupportTicketRepository $supportTicketRepository;
    protected SupportTicketMessageRepository $supportTicketMessageRepository;
    protected SupportTicketNoteRepository $supportTicketNoteRepository;
    protected SupportTicketMessageHistoryRepository $supportTicketMessageHistoryRepository;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
        $em = $di['em'];
        $this->kbArticleRepository = $em->getRepository(KbArticle::class);
        $this->kbArticleCategoryRepository = $em->getRepository(KbArticleCategory::class);
        $this->cannedResponseRepository = $em->getRepository(CannedResponse::class);
        $this->cannedResponseCategoryRepository = $em->getRepository(CannedResponseCategory::class);
        $this->helpdeskRepository = $em->getRepository(Helpdesk::class);
        $this->supportTicketRepository = $em->getRepository(SupportTicket::class);
        $this->supportTicketMessageRepository = $em->getRepository(SupportTicketMessage::class);
        $this->supportTicketNoteRepository = $em->getRepository(SupportTicketNote::class);
        $this->supportTicketMessageHistoryRepository = $em->getRepository(SupportTicketMessageHistory::class);
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function getKbArticleRepository(): KbArticleRepository
    {
        return $this->kbArticleRepository;
    }

    public function getKbArticleCategoryRepository(): KbArticleCategoryRepository
    {
        return $this->kbArticleCategoryRepository;
    }

    public function getCannedResponseRepository(): CannedResponseRepository
    {
        return $this->cannedResponseRepository;
    }

    public function getCannedResponseCategoryRepository(): CannedResponseCategoryRepository
    {
        return $this->cannedResponseCategoryRepository;
    }

    public function getSupportTicketRepository(): SupportTicketRepository
    {
        return $this->supportTicketRepository;
    }

    public function getSupportTicketMessageRepository(): SupportTicketMessageRepository
    {
        return $this->supportTicketMessageRepository;
    }

    public function getSupportTicketNoteRepository(): SupportTicketNoteRepository
    {
        return $this->supportTicketNoteRepository;
    }

    public function getSupportTicketMessageHistoryRepository(): SupportTicketMessageHistoryRepository
    {
        return $this->supportTicketMessageHistoryRepository;
    }

    public function getHelpdeskRepository(): HelpdeskRepository
    {
        return $this->helpdeskRepository;
    }

    public function getModulePermissions(): array
    {
        return [
            'view' => [
                'type' => 'bool',
                'display_name' => __trans('View support tickets'),
                'description' => __trans('Allows the staff member to view tickets, inquiries, helpdesks, canned responses, and knowledge base articles.'),
            ],
            'manage_tickets' => [
                'type' => 'bool',
                'display_name' => __trans('Manage tickets'),
                'description' => __trans('Allows the staff member to create, update, reply to, close, and delete tickets and inquiries.'),
            ],
            'manage_helpdesk' => [
                'type' => 'bool',
                'display_name' => __trans('Manage helpdesks'),
                'description' => __trans('Allows the staff member to create, update, and delete helpdesks.'),
            ],
            'manage_canned' => [
                'type' => 'bool',
                'display_name' => __trans('Manage canned responses'),
                'description' => __trans('Allows the staff member to create, update, and delete canned responses and categories.'),
            ],
            'manage_kb' => [
                'type' => 'bool',
                'display_name' => __trans('Manage knowledge base'),
                'description' => __trans('Allows the staff member to create, update, and delete knowledge base articles and categories.'),
            ],
            'manage_settings' => [],
        ];
    }

    /**
     * ClientFence: a client's reply gets the files its form sent (TicketFiles.php) and, written by
     * an authorized user, records the user (Client/Users.php).
     */
    public static function onAfterClientReplyTicket(\ClientFence\Event $event): void
    {
        $ticketId = (int) ($event->getParameters()['id'] ?? 0);
        TicketFiles::attachFromRequest($event->getDi(), $ticketId, false);
        if ($ticketId > 0) {
            \ClientFence\Mod\Client\Users::recordTicketAuthor($event->getDi(), $ticketId);
        }
    }

    /**
     * ClientFence: after each cron run, ticket files older than the "keep ticket files" setting go
     * (TicketFiles::prune()), the help desk mailboxes are read (MailImport::run()) and anything
     * saved for a merged ticket moves to the ticket it went into (TicketMerge::sweep()) and the
     * escalation rules run (TicketEscalation::run()); a problem never breaks the cron run.
     * When set, cancellation requests are accepted too (CancelRequests::run()).
     */
    public static function onAfterAdminCronRun(\ClientFence\Event $event): void
    {
        $di = $event->getDi();
        try {
            TicketMerge::sweep($di);
        } catch (\Throwable $e) {
            $di['logger']->error('Support: moving replies of merged tickets failed: %s', $e->getMessage());
        }
        // Pins and assignments of deleted tickets (TicketBulk, TicketAssign).
        TicketBulk::sweep($di);
        TicketAssign::sweep($di);
        TicketFeedback::sweep($di);
        // Escalation rules, as WHMCS (TicketEscalation).
        // Cancellation requests processed automatically, when set (CancelRequests).
        try {
            CancelRequests::run($di);
        } catch (\Throwable $e) {
            $di['logger']->error('Support: cancellation requests failed: %s', $e->getMessage());
        }
        try {
            TicketEscalation::run($di);
        } catch (\Throwable $e) {
            $di['logger']->error('Support: escalation rules failed: %s', $e->getMessage());
        }
        TicketEscalation::sweep($di);
        try {
            TicketFiles::prune($di);
        } catch (\Throwable $e) {
            $di['logger']->error('Support: removing old ticket files failed: %s', $e->getMessage());
        }
        try {
            MailImport::run($di);
        } catch (\Throwable $e) {
            $di['logger']->error('Support: email import failed: %s', $e->getMessage());
        }
    }

    public static function onAfterClientOpenTicket(\ClientFence\Event $event): void
    {
        $di = $event->getDi();
        // ClientFence: the files the ticket form sent go to the new message (TicketFiles.php); a
        // ticket written by an authorized user records the user (Client/Users.php).
        TicketFiles::attachFromRequest($di, (int) ($event->getParameters()['id'] ?? 0), false);
        if ((int) ($event->getParameters()['id'] ?? 0) > 0) {
            \ClientFence\Mod\Client\Users::recordTicketAuthor($di, (int) $event->getParameters()['id']);
        }
        $params = $event->getParameters();
        $supportService = $di['mod_service']('support');
        $emailService = $di['mod_service']('email');

        try {
            $ticketObj = $supportService->getTicketById((int) $params['id']);
            $isGuestTicket = $ticketObj->isGuestTicket();
            // ClientFence core patch: the ticket's own client, not whoever is signed in (a ticket
            // opened by email, MailImport, has no signed-in client or a different one).
            $identity = $isGuestTicket ? null : $di['db']->getExistingModelById('Client', (int) $ticketObj->getClientId(), 'Client not found');
            $ticketArr = $supportService->toApiArray($ticketObj, true, $identity);

            $email = [];
            if ($isGuestTicket) {
                $email['to'] = $ticketObj->getAuthorEmail();
                $email['to_name'] = $ticketObj->getAuthorName();
            } else {
                $email['to_client'] = $ticketObj->getClientId();
            }
            $email['code'] = 'mod_support_ticket_open';
            $email['ticket'] = $ticketArr;
            // ClientFence core patch: replies go to the help desk's imported mailbox (MailImport::replyAddress()).
            $email['reply_to'] = MailImport::replyAddress($di, (int) $ticketObj->getSupportHelpdeskId());
            $emailService->sendTemplate($email);
        } catch (\Exception $exc) {
            $di['logger']->setChannel('email')->error('Failed to send ticket open email', ['exception' => $exc->getMessage()]);
        }
    }

    public static function onAfterAdminOpenTicket(\ClientFence\Event $event): void
    {
        $di = $event->getDi();
        // ClientFence: the files the ticket form sent go to the new message (TicketFiles.php).
        TicketFiles::attachFromRequest($di, (int) ($event->getParameters()['id'] ?? 0), true);
        $supportService = $di['mod_service']('support');
        $emailService = $di['mod_service']('email');
        $params = $event->getParameters();

        try {
            $ticketObj = $supportService->getTicketById((int) $params['id']);
            $identity = $di['loggedin_admin'];
            $ticketArr = $supportService->toApiArray($ticketObj, true, $identity);

            $email = [];
            if ($ticketObj->isGuestTicket()) {
                $email['to'] = $ticketObj->getAuthorEmail();
                $email['to_name'] = $ticketObj->getAuthorName();
            } else {
                $email['to_client'] = $ticketObj->getClientId();
            }
            $email['code'] = 'mod_support_ticket_staff_open';
            $email['ticket'] = $ticketArr;
            $email['reply_to'] = MailImport::replyAddress($di, (int) $ticketObj->getSupportHelpdeskId());
            $emailService->sendTemplate($email);
        } catch (\Exception $exc) {
            $di['logger']->setChannel('email')->error('Failed to send admin ticket open email', ['exception' => $exc->getMessage()]);
        }
    }

    public static function onAfterAdminCloseTicket(\ClientFence\Event $event): void
    {
        $di = $event->getDi();
        $supportService = $di['mod_service']('support');
        $emailService = $di['mod_service']('email');
        $params = $event->getParameters();

        try {
            $identity = $di['loggedin_admin'];
            $ticketObj = $supportService->getTicketById((int) $params['id']);
            $ticketArr = $supportService->toApiArray($ticketObj, true, $identity);

            $email = [];
            if ($ticketObj->isGuestTicket()) {
                $email['to'] = $ticketObj->getAuthorEmail();
                $email['to_name'] = $ticketObj->getAuthorName();
            } else {
                $email['to_client'] = $ticketObj->getClientId();
            }
            $email['code'] = 'mod_support_ticket_staff_close';
            $email['ticket'] = $ticketArr;
            $email['reply_to'] = MailImport::replyAddress($di, (int) $ticketObj->getSupportHelpdeskId());
            $emailService->sendTemplate($email);
        } catch (\Exception $exc) {
            $di['logger']->setChannel('email')->error('Failed to send ticket close email', ['exception' => $exc->getMessage()]);
        }
    }

    public static function onAfterAdminReplyTicket(\ClientFence\Event $event): void
    {
        $di = $event->getDi();
        // ClientFence: the files the ticket form sent go to the new message (TicketFiles.php).
        TicketFiles::attachFromRequest($di, (int) ($event->getParameters()['id'] ?? 0), true);
        $supportService = $di['mod_service']('support');
        $emailService = $di['mod_service']('email');
        $params = $event->getParameters();

        try {
            $ticketObj = $supportService->getTicketById((int) $params['id']);
            $identity = $di['loggedin_admin'];
            $ticketArr = $supportService->toApiArray($ticketObj, true, $identity);

            $email = [];
            if ($ticketObj->isGuestTicket()) {
                $email['to'] = $ticketObj->getAuthorEmail();
                $email['to_name'] = $ticketObj->getAuthorName();
            } else {
                $email['to_client'] = $ticketObj->getClientId();
            }
            $email['code'] = 'mod_support_ticket_staff_reply';
            $email['ticket'] = $ticketArr;
            $email['reply_to'] = MailImport::replyAddress($di, (int) $ticketObj->getSupportHelpdeskId());
            $emailService->sendTemplate($email);
        } catch (\Exception $exc) {
            $di['logger']->setChannel('email')->error('Failed to send ticket reply email', ['exception' => $exc->getMessage()]);
        }
    }

    public function getTicketById(int $id): SupportTicket
    {
        return $this->getSupportTicketRepository()->findOneByIdOrFail($id);
    }

    public function getTicketMessageById(int $id): SupportTicketMessage
    {
        return $this->getSupportTicketMessageRepository()->findOneByIdOrFail($id);
    }

    public function getTicketNoteById(int $id): SupportTicketNote
    {
        return $this->getSupportTicketNoteRepository()->findOneByIdOrFail($id);
    }

    /**
     * Return array of ticket statuses.
     */
    public function getStatuses(): array
    {
        return [
            SupportTicket::STATUS_OPEN => 'Open',
            SupportTicket::STATUS_ONHOLD => 'On Hold',
            SupportTicket::STATUS_CLOSED => 'Closed',
        ];
    }

    /**
     * Find ticket for client.
     */
    public function findOneByClient(\Model_Client $c, int $id): SupportTicket
    {
        return $this->getSupportTicketRepository()->findOneByClientOrFail((int) $c->id, $id);
    }

    public function counter(): array
    {
        $data = $this->getSupportTicketRepository()->countGroupedByStatus();

        return [
            'total' => array_sum($data),
            SupportTicket::STATUS_OPEN => $data[SupportTicket::STATUS_OPEN] ?? 0,
            SupportTicket::STATUS_CLOSED => $data[SupportTicket::STATUS_CLOSED] ?? 0,
            SupportTicket::STATUS_ONHOLD => $data[SupportTicket::STATUS_ONHOLD] ?? 0,
        ];
    }

    public function getLatest(): array
    {
        return $this->getSupportTicketRepository()->findLatest();
    }

    public function getExpired(): array
    {
        return $this->getSupportTicketRepository()->findExpiredOnHold(new \DateTime());
    }

    public function countByStatus(string $status): int
    {
        return $this->getSupportTicketRepository()->countByStatus($status);
    }

    public function checkIfTaskAlreadyExists(\Model_Client $client, int $rel_id, string $rel_type, string $rel_task): bool
    {
        return $this->getSupportTicketRepository()->hasPendingTaskForClient((int) $client->id, $rel_id, $rel_type, $rel_task);
    }

    public function closeTicket(SupportTicket $ticket, \Model_Admin|\Model_Client|\ClientFence\Identity\Guest $identity): bool
    {
        // ClientFence core patch: repeat closes must not resend feedback requests.
        if ($ticket->getStatus() === SupportTicket::STATUS_CLOSED) {
            return true;
        }

        $ticket->close();
        $this->di['em']->flush();

        if ($identity instanceof \Model_Admin) {
            $this->di['events_manager']->fire(['event' => 'onAfterAdminCloseTicket', 'params' => ['id' => $ticket->getId()]]);
        } else {
            $this->di['events_manager']->fire(['event' => 'onAfterClientCloseTicket', 'params' => ['id' => $ticket->getId()]]);
        }
        // ClientFence core patch: ask the client for feedback, as WHMCS (TicketFeedback).
        TicketFeedback::afterClose($this->di, (int) $ticket->getId());

        $this->di['logger']->info('Closed ticket "%s"', $ticket->getId());

        return true;
    }

    public function autoClose(SupportTicket $model): bool
    {
        $model->close();
        $this->di['em']->flush();
        $this->di['logger']->info('Ticket %s was closed', $model->getId());
        // ClientFence core patch: ask the client for feedback after an inactivity close, as WHMCS (TicketFeedback).
        TicketFeedback::afterClose($this->di, (int) $model->getId());

        return true;
    }

    public function canBeReopened(SupportTicket $model): bool
    {
        return $model->canBeReopen();
    }

    /**
     * @return mixed[]
     */
    private function _getRelDetails(SupportTicket $model): array
    {
        $result = [
            'id' => $model->getRelId() ?: null,
            'type' => $model->getRelType() ?: null,
            'task' => $model->getRelTask() ?: null,
            'new_value' => $model->getRelNewValue() ?: null,
            'status' => $model->getRelStatus() ?: null,
        ];

        if (!$model->getRelType() || !$model->getRelId()) {
            return $result;
        }

        if ($model->getRelType() === SupportTicket::REL_TYPE_ORDER) {
            $clientId = $model->getClientId();
            if ($clientId !== null && $this->fetchClientSummary($clientId) !== null) {
                $order = $this->di['mod_service']('order')->findByClientIdAndOrderId($clientId, (int) $model->getRelId());
                if ($order instanceof \Model_ClientOrder) {
                    $result['order'] = $this->di['mod_service']('order')->toApiArray($order, false);
                }
            }
        }

        return $result;
    }

    public function rmByClient(\Model_Client $client): void
    {
        $em = $this->di['em'];
        foreach ($this->getSupportTicketRepository()->findByClientId((int) $client->id) as $ticket) {
            $em->remove($ticket);
        }
        $em->flush();
    }

    public function rm(SupportTicket $model): bool
    {
        $em = $this->di['em'];
        $id = $model->getId();

        foreach ($this->getSupportTicketNoteRepository()->findByTicketId($id ?? 0) as $note) {
            $em->remove($note);
        }
        foreach ($this->getSupportTicketMessageRepository()->findByTicketId($id ?? 0) as $message) {
            foreach ($this->getSupportTicketMessageHistoryRepository()->findByMessageId((int) $message->getId()) as $history) {
                $em->remove($history);
            }
            $em->remove($message);
        }

        $em->remove($model);
        $em->flush();

        $this->di['logger']->info('Removed ticket "%s"', $id);

        return true;
    }

    public function toApiArray(SupportTicket $model, bool $deep = true, \Model_Admin|\Model_Client|null $identity = null, array $known = []): array
    {
        // ClientFence core patch: `$known` holds what listToApiArray() loaded for the whole page.
        $firstSupportTicketMessage = array_key_exists('first', $known) ? $known['first'] : $this->getSupportTicketMessageRepository()->findFirstByTicketId($model->getId() ?? 0);
        $helpdeskId = $model->getSupportHelpdeskId();
        $helpdesk = $helpdeskId !== null ? $this->getHelpdeskRepository()->find($helpdeskId) : null;

        $data = $model->toApiArray($identity);
        // ClientFence core patch: the priority as WHMCS's Low, Medium or High, for staff and clients (TicketPriority).
        $data['priority_level'] = TicketPriority::level($model->getPriority());
        $data['replies'] = $known['replies'] ?? $this->messageGetRepliesCount($model);
        $data['first'] = $firstSupportTicketMessage instanceof SupportTicketMessage ? $this->messageToApiArray($firstSupportTicketMessage, true, $identity) : null;
        $data['helpdesk'] = $helpdesk instanceof Helpdesk ? $helpdesk->toApiArray($identity) : null;
        // ClientFence core patch: help desk greeting for the staff reply box (ClientFence).
        $data['helpdesk'] = \ClientFence\Mod\Clientfence\Service::withHelpdeskGreeting($this->di, $data['helpdesk'], $identity);
        $data['author'] = $this->getTicketAuthor($model, $identity);

        $data['client'] = $known['client'] ?? $this->getClientApiArrayForTicket($model, $identity);

        if ($deep) {
            $messages = $this->getSupportTicketMessageRepository()->findByTicketId($model->getId() ?? 0);
            // ClientFence core patch: a ticket without messages (left by a failed save) still opens.
            $data['messages'] = [];
            foreach ($messages as $msg) {
                $data['messages'][] = $this->messageToApiArray($msg, true, $identity);
            }
            // ClientFence core patch: reply stars and the feedback link, as WHMCS (TicketFeedback).
            $data['ratings_on'] = TicketFeedback::ratingsOn($this->di);
            $data['message_ratings'] = TicketFeedback::ratings($this->di, (int) $model->getId());
            // ClientFence core patch: staff see which emailed messages came from a sender DKIM could not verify (MailImport).
            if ($identity instanceof \Model_Admin) {
                $data['unverified_messages'] = MailImport::unverifiedMessages($this->di, (int) $model->getId());
            }
            if (!$identity instanceof \Model_Admin && $model->getStatus() === SupportTicket::STATUS_CLOSED) {
                $data['feedback_url'] = TicketFeedback::openLink($this->di, (int) $model->getId());
            }
        }

        if ($identity instanceof \Model_Admin) {
            $data['rel'] = $this->_getRelDetails($model);
            $data['priority'] = $model->getPriority();
            // ClientFence core patch: the staff member the ticket is assigned to, or null (TicketAssign).
            $data['assigned_to'] = (array_key_exists('assigned_to', $known) ? $known['assigned_to'] : TicketAssign::assignees($this->di, [(int) $model->getId()])[(int) $model->getId()] ?? null);
            $data['notes'] = [];
            $supportTicketNotes = $known['notes'] ?? $this->getSupportTicketNoteRepository()->findByTicketId($model->getId() ?? 0);

            foreach ($supportTicketNotes as $note) {
                $data['notes'][] = $this->noteToApiArray($note);
            }
            // ClientFence core patch: the tickets merged into this one (TicketMerge), for its page.
            if ($deep) {
                $data['merged_tickets'] = TicketMerge::mergedInto($this->di, (int) $model->getId());
                // ClientFence core patch: the client's feedback (TicketFeedback).
                $data['feedback'] = TicketFeedback::forTicket($this->di, (int) $model->getId());
            }
        }

        return $data;
    }

    /**
     * ClientFence core patch: the staff ticket list, each ticket as toApiArray($ticket, false,
     * $identity) builds it, with reply counts, first messages, notes, assignees and clients loaded
     * once for the page instead of about ten queries per ticket.
     *
     * @param list<SupportTicket> $tickets
     */
    public function listToApiArray(array $tickets, \Model_Admin|\Model_Client|null $identity): array
    {
        $ids = array_map(static fn (SupportTicket $ticket): int => (int) $ticket->getId(), $tickets);
        $replies = $this->getSupportTicketMessageRepository()->countRepliesByTicketIds($ids);
        $first = $this->getSupportTicketMessageRepository()->findFirstByTicketIds($ids);
        $assignees = TicketAssign::assignees($this->di, $ids);
        $notes = [];
        foreach ($ids === [] ? [] : $this->getSupportTicketNoteRepository()->findBy(['ticket' => $ids], ['id' => 'ASC']) as $note) {
            $notes[(int) $note->getTicket()?->getId()][] = $note;
        }
        $clients = [];
        $list = [];
        foreach ($tickets as $ticket) {
            $id = (int) $ticket->getId();
            $known = ['replies' => $replies[$id] ?? 0, 'first' => $first[$id] ?? null, 'assigned_to' => $assignees[$id] ?? null, 'notes' => $notes[$id] ?? []];
            if (!$ticket->isGuestTicket() && $ticket->getClientId() !== null) {
                $known['client'] = $clients[$ticket->getClientId()] ??= $this->getClientApiArrayForTicket($ticket, $identity);
            }
            $list[] = $this->toApiArray($ticket, false, $identity, $known);
        }

        return $list;
    }

    /**
     * Apply identity-based field stripping to a raw ticket row.
     *
     * Used by the batch fetcher ({@see getBatchForApi()}), which operates on
     * associative arrays rather than hydrated entities and therefore cannot
     * use {@see SupportTicket::toApiArray()}.
     */
    private function ticketToApiArray(array $data, \Model_Admin|\Model_Client|null $identity = null): array
    {
        if (!empty($data['access_hash'])) {
            $data['hash'] = $data['access_hash'];
        }
        // ClientFence core patch: the priority as Low, Medium or High (TicketPriority), kept for clients.
        $data['priority_level'] = TicketPriority::level($data['priority'] ?? null);

        if ($identity instanceof \Model_Admin) {
            return $data;
        }

        unset(
            $data['support_helpdesk_id'],
            $data['client_id'],
            $data['priority'],
            $data['access_hash'],
            $data['rel_type'],
            $data['rel_id'],
            $data['rel_task'],
            $data['rel_new_value'],
            $data['rel_status'],
        );

        return $data;
    }

    /**
     * Get multiple tickets in a batch for API response.
     *
     * @param array                           $ids      Array of ticket IDs to fetch
     * @param bool                            $deep     Whether to include full message history
     * @param \Model_Admin|\Model_Client|null $identity The requesting identity
     *
     * @return array Array of ticket API arrays. Missing IDs are silently skipped.
     */
    public function getBatchForApi(array $ids, bool $deep = false, $identity = null): array
    {
        $ids = $this->normalizeIds($ids);
        if (empty($ids)) {
            return [];
        }

        if ($deep || $identity instanceof \Model_Admin) {
            return $this->getBatchForApiWithModels($ids, $deep, $identity);
        }

        $tickets = $this->getSupportTicketRepository()->findBatchRowsByIds($ids);
        if (empty($tickets)) {
            return [];
        }

        $tickets = $this->orderRowsByIds($tickets, $ids);
        $ticketIds = array_column($tickets, 'id');
        $helpdeskIds = $this->normalizeIds(array_column($tickets, 'support_helpdesk_id'));
        $clientIds = $this->normalizeIds(array_column($tickets, 'client_id'));

        $replyCounts = $this->getSupportTicketMessageRepository()->countRepliesByTicketIds($ticketIds);

        $firstMessages = [];
        if (!empty($ticketIds)) {
            $firstMessageIdsByTicket = $this->getSupportTicketMessageRepository()->findFirstIdsByTicketIds($ticketIds);
            $messageIds = array_values($firstMessageIdsByTicket);
            if (!empty($messageIds)) {
                $messages = $this->getSupportTicketMessageRepository()->findByIds($messageIds);
                foreach ($messages as $message) {
                    $ticket = $message->getSupportTicket();
                    $firstMessages[$ticket instanceof SupportTicket ? ($ticket->getId() ?? 0) : 0] = $message;
                }
            }
        }

        $helpdesks = [];
        if (!empty($helpdeskIds)) {
            $helpdeskModels = $this->getHelpdeskRepository()->findByIds($helpdeskIds);
            foreach ($helpdeskModels as $helpdesk) {
                $helpdesks[$helpdesk->getId()] = $helpdesk;
            }
        }

        $clients = [];
        $clientAuthors = [];
        if (!empty($clientIds)) {
            /** @todo Doctrine: use Client entity once Client is migrated */
            $clientRows = $this->di['dbal']->fetchAllAssociative(
                'SELECT id, first_name, last_name, email FROM client WHERE id IN (?)',
                [$clientIds],
                [\Doctrine\DBAL\ArrayParameterType::INTEGER]
            );
            foreach ($clientRows as $row) {
                $id = (int) $row['id'];
                $first = (string) $row['first_name'];
                $last = (string) $row['last_name'];
                $clients[$id] = [
                    'id' => $id,
                    'first_name' => $first,
                    'last_name' => $last,
                ];
                $clientAuthors[$id] = [
                    'id' => $id,
                    'name' => trim($first . ' ' . $last),
                    'first_name' => $first,
                    'last_name' => $last,
                    'email' => (string) $row['email'],
                    'role' => 'client',
                ];
            }
        }

        $result = [];
        foreach ($tickets as $ticket) {
            $data = $this->ticketToApiArray($ticket, $identity);
            $data['replies'] = $replyCounts[$ticket['id']] ?? 0;
            $data['first'] = isset($firstMessages[$ticket['id']]) ? $this->messageToApiArray($firstMessages[$ticket['id']], true, $identity) : null;

            $helpdesk = $helpdesks[$ticket['support_helpdesk_id']] ?? null;
            $data['helpdesk'] = $helpdesk instanceof Helpdesk ? $helpdesk->toApiArray($identity) : null;

            if (empty($ticket['client_id']) && !empty($ticket['access_hash'])) {
                $data['author'] = [
                    'name' => $ticket['author_name'],
                    'email' => $ticket['author_email'],
                    'role' => 'guest',
                ];
                $data['client'] = [];
            } elseif (!isset($clients[$ticket['client_id']])) {
                $this->di['logger']->error('Missing client for ticket ' . $ticket['id']);
                // The client row is gone: fall back to the stored contact
                // details so templates rendering author.name/email/role keep working.
                $data['author'] = [
                    'name' => $ticket['author_name'] ?? '',
                    'email' => $ticket['author_email'] ?? '',
                    'role' => 'guest',
                ];
                $data['client'] = [];
            } else {
                $data['author'] = $clientAuthors[$ticket['client_id']];
                $data['client'] = $clients[$ticket['client_id']];
            }

            $result[] = $data;
        }

        return $result;
    }

    private function getBatchForApiWithModels(array $ids, bool $deep, $identity): array
    {
        $tickets = $this->getSupportTicketRepository()->findByIds($ids);
        if (empty($tickets)) {
            return [];
        }

        $ticketsById = [];
        foreach ($tickets as $ticket) {
            $id = $ticket->getId();
            if ($id !== null) {
                $ticketsById[$id] = $ticket;
            }
        }

        $result = [];
        foreach ($ids as $id) {
            if (isset($ticketsById[$id])) {
                $result[] = $this->toApiArray($ticketsById[$id], $deep, $identity);
            }
        }

        return $result;
    }

    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_map(intval(...), array_filter($ids, is_numeric(...)))));
    }

    private function orderRowsByIds(array $rows, array $ids): array
    {
        $rowsById = [];
        foreach ($rows as $row) {
            $rowsById[(int) $row['id']] = $row;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($rowsById[$id])) {
                $ordered[] = $rowsById[$id];
            }
        }

        return $ordered;
    }

    private function getClientApiArrayForTicket(SupportTicket $ticket, \Model_Admin|\Model_Client|null $identity = null): array
    {
        if ($ticket->isGuestTicket()) {
            return [];
        }

        $clientId = $ticket->getClientId();
        if ($clientId === null) {
            return [];
        }

        if ($identity instanceof \Model_Admin) {
            // Requires Model_Client until Client::toApiArray() is migrated to Doctrine.
            $client = $this->di['db']->load('Client', $clientId);

            return $client instanceof \Model_Client
                ? $this->clientToTicketApiArray($client, $identity)
                : [];
        }

        $summary = $this->fetchClientSummary($clientId);
        if ($summary === null) {
            $this->di['logger']->error('Missing client for ticket ' . $ticket->getId());

            return [];
        }

        return [
            'id' => $summary['id'],
            'first_name' => $summary['first_name'],
            'last_name' => $summary['last_name'],
        ];
    }

    private function getTicketAuthor(SupportTicket $ticket, \Model_Admin|\Model_Client|null $identity = null): array
    {
        if ($ticket->isGuestTicket()) {
            $author = [
                'name' => $ticket->getAuthorName(),
                'role' => 'guest',
            ];

            if ($identity instanceof \Model_Admin || $identity === null) {
                $author['email'] = $ticket->getAuthorEmail();
            }

            return $author;
        }

        $clientId = $ticket->getClientId();
        $client = $clientId !== null ? $this->fetchClientSummary($clientId) : null;

        if ($client !== null) {
            return [
                'id' => $client['id'],
                'name' => $client['name'],
                'first_name' => $client['first_name'],
                'last_name' => $client['last_name'],
                'email' => $client['email'],
                'role' => 'client',
            ];
        }
        $this->di['logger']->error('Missing client for ticket ' . $ticket->getId());

        // The client row is gone: fall back to the stored contact details
        // so templates rendering author.name/email/role keep working.
        return [
            'name' => $ticket->getAuthorName() ?? '',
            'email' => $ticket->getAuthorEmail() ?? '',
            'role' => 'guest',
        ];
    }

    private function clientToTicketApiArray(\Model_Client $client, \Model_Admin|\Model_Client|null $identity = null): array
    {
        if ($identity instanceof \Model_Admin) {
            $clientService = $this->di['mod_service']('client');

            return $clientService->toApiArray($client, false, $identity);
        }

        return [
            'id' => $client->id,
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
        ];
    }

    /**
     * Fetch a minimal `id / name / email` summary for an admin row via DBAL.
     *
     * @return array{id: int, name: string, email: string}|null
     *
     * @todo Doctrine: replace with Admin entity once Staff is migrated
     */
    private function fetchAdminSummary(int $adminId): ?array
    {
        $row = $this->di['dbal']->fetchAssociative(
            'SELECT id, name, email FROM admin WHERE id = :id',
            ['id' => $adminId]
        );

        return $row === false ? null : [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
        ];
    }

    /**
     * Fetch a minimal `id / first_name / last_name / email / name` summary
     * for a client row via DBAL.
     *
     * The synthesized `name` field concatenates first and last name to mimic
     * the legacy {@see \Model_Client::getFullName()} behaviour.
     *
     * Name and email fields are nullable, so only a missing row means a
     * missing client.
     *
     * @return array{id: int, first_name: string, last_name: string, email: string, name: string}|null
     *
     * @todo Doctrine: replace with Client entity once Client is migrated
     */
    private function fetchClientSummary(int $clientId): ?array
    {
        $row = $this->di['dbal']->fetchAssociative(
            'SELECT id, first_name, last_name, email FROM client WHERE id = :id',
            ['id' => $clientId]
        );

        if ($row === false) {
            return null;
        }

        $first = (string) $row['first_name'];
        $last = (string) $row['last_name'];
        $fullName = trim($first . ' ' . $last);

        return [
            'id' => (int) $row['id'],
            'first_name' => $first,
            'last_name' => $last,
            'email' => (string) $row['email'],
            'name' => $fullName,
        ];
    }

    public function noteGetAuthorDetails(SupportTicketNote $model): array
    {
        $adminId = $model->getAdminId();
        $admin = $adminId !== null ? $this->fetchAdminSummary($adminId) : null;

        return [
            'name' => $admin['name'] ?? null,
            'email' => $admin['email'] ?? null,
        ];
    }

    public function noteRm(SupportTicketNote $model): bool
    {
        $id = $model->getId();
        $this->di['em']->remove($model);
        $this->di['em']->flush();

        $this->di['logger']->info('Removed note #%s', $id);

        return true;
    }

    public function noteToApiArray(SupportTicketNote $model, bool $deep = false, \Model_Admin|\Model_Client|null $identity = null): array
    {
        $data = [
            'id' => $model->getId(),
            'support_ticket_id' => $model->getSupportTicket()?->getId(),
            'admin_id' => $model->getAdminId(),
            'note' => $model->getNote(),
            'created_at' => $model->getCreatedAt()?->format('Y-m-d H:i:s'),
            'updated_at' => $model->getUpdatedAt()?->format('Y-m-d H:i:s'),
        ];
        $data['author'] = $this->noteGetAuthorDetails($model);

        return $data;
    }

    public function helpdeskRm(Helpdesk $model): bool
    {
        $id = $model->getId();

        if ($id !== null && $this->getHelpdeskRepository()->countTickets($id) > 0) {
            throw new InformationException('Cannot remove helpdesk which has tickets');
        }

        $this->di['em']->remove($model);
        $this->di['em']->flush();
        $this->di['logger']->info('Deleted helpdesk #%s', $id);
        // ClientFence core patch: help desk greeting removed with the help desk (ClientFence).
        if ($id !== null) {
            \ClientFence\Mod\Clientfence\Service::deleteHelpdeskGreeting($this->di, (int) $id);
            // ClientFence core patch: and its feedback request setting (TicketFeedback).
            TicketFeedback::deleteHelpdesk($this->di, (int) $id);
            // ClientFence core patch: and whether it was hidden from clients (HiddenHelpdesks).
            HiddenHelpdesks::forget($this->di, (int) $id);
            // ClientFence core patch: and its mailbox (MailImport), password included; it was still read.
            try {
                $this->di['dbal']->executeStatement('DELETE FROM ' . MailImport::MAILBOX_TABLE . ' WHERE helpdesk_id = ?', [(int) $id]);
            } catch (\Throwable $e) {
                $this->di['logger']->warning('Mailbox of deleted helpdesk #%s not removed: %s', $id, $e->getMessage());
            }
        }

        return true;
    }

    public function messageGetRepliesCount(SupportTicket $model): int
    {
        return $this->getSupportTicketMessageRepository()->countByTicketId($model->getId() ?? 0);
    }

    public function messageGetAuthorDetails(SupportTicketMessage $model, \Model_Admin|\Model_Client|null $identity = null): array
    {
        $adminId = $model->getAdminId();
        $clientId = $model->getClientId();
        $ticket = $model->getSupportTicket();

        if ($adminId) {
            /** @todo Doctrine: use Admin entity once Staff is migrated */
            $author = $this->fetchAdminSummary($adminId);
            $role = 'admin';
        } elseif ($clientId) {
            /** @todo Doctrine: use Client entity once Client is migrated */
            $author = $this->fetchClientSummary($clientId);
            $role = 'client';
        } else {
            if ($ticket instanceof SupportTicket && $ticket->isGuestTicket()) {
                return [
                    'name' => $ticket->getAuthorName(),
                    'email' => $ticket->getAuthorEmail(),
                    'role' => 'guest',
                ];
            }

            return [];
        }

        if ($author === null) {
            return [];
        }

        $result = [
            'name' => $author['name'],
            'role' => $role,
        ];

        if ($identity instanceof \Model_Admin) {
            $result['email'] = $author['email'];
        }

        return $result;
    }

    public function messageToApiArray(SupportTicketMessage $model, bool $deep = true, \Model_Admin|\Model_Client|null $identity = null): array
    {
        $data = $model->toApiArray($identity);
        $data['author'] = $this->messageGetAuthorDetails($model, $identity);

        return $data;
    }

    public function ticketUpdate(SupportTicket $model, array $data): bool
    {
        if (isset($data['support_helpdesk_id'])) {
            $helpdesk = $this->getHelpdeskRepository()->find((int) $data['support_helpdesk_id']);
            if ($helpdesk instanceof Helpdesk) {
                $model->setSupportHelpdesk($helpdesk);
            }
        }
        if (isset($data['status'])) {
            $model->setStatus($data['status']);
        }
        if (isset($data['subject'])) {
            $model->setSubject($data['subject']);
        }
        if (isset($data['priority'])) {
            // ClientFence core patch: Low, Medium or High only (TicketPriority).
            $model->setPriority(TicketPriority::fromInput($data['priority']));
        }
        $model->setUpdatedAt(new \DateTime());

        $this->di['em']->flush();

        $this->di['logger']->info('Updated ticket #%s', $model->getId());

        return true;
    }

    public function ticketMessageUpdate(SupportTicketMessage $model, string $content, \Model_Admin $identity): bool
    {
        if ($model->getAdminId() === null) {
            throw new InformationException('Only admin replies can be edited');
        }

        $previousContent = (string) $model->getContent();
        if ($previousContent === $content) {
            return true;
        }

        $history = new SupportTicketMessageHistory();
        $history->setMessage($model);
        $history->setAdminId((int) $identity->id);
        $history->setContent($previousContent);
        $this->di['em']->persist($history);

        $model->setContent($content);
        $this->di['em']->flush();

        $this->di['logger']->info('Edited ticket message #%s', $model->getId());

        return true;
    }

    /**
     * @return array[]
     */
    public function getMessageHistory(SupportTicketMessage $message): array
    {
        // Always render with the admin theme's Markdown defaults: this is admin-only functionality,
        // and ADMIN_AREA isn't set to true for api/admin/... requests the way it is for admin page loads.
        $markdown = new ClientFenceMarkdown($this->di, isAdmin: true);

        $result = [];
        foreach ($this->getSupportTicketMessageHistoryRepository()->findByMessageId((int) $message->getId()) as $history) {
            $data = $history->toApiArray();
            $data['content_html'] = $markdown->convert((string) $data['content']);
            $result[] = $data;
        }

        return $result;
    }

    public function ticketReply(SupportTicket $ticket, \Model_Admin|\Model_Client|\ClientFence\Identity\Guest $identity, string $content): int
    {
        SupportTicketValidator::validateContent($content);
        $em = $this->di['em'];
        $msg = new SupportTicketMessage();
        $msg->setSupportTicket($ticket);
        if ($identity instanceof \Model_Admin) {
            $msg->setAdminId((int) $identity->id);
        } elseif ($identity instanceof \Model_Client) {
            $msg->setClientId((int) $identity->id);
        }
        $msg->setContent($content);
        $msg->setIp($this->di['request']->getClientIp());
        $em->persist($msg);
        $em->flush();

        if ($identity instanceof \Model_Admin) {
            $ticket->setStatus(SupportTicket::STATUS_ONHOLD);
        } else {
            $ticket->setStatus(SupportTicket::STATUS_OPEN);
        }
        $ticket->setUpdatedAt(new \DateTime());
        $em->flush();

        if ($identity instanceof \Model_Admin) {
            $this->di['events_manager']->fire(['event' => 'onAfterAdminReplyTicket', 'params' => ['id' => $ticket->getId()]]);
        } else {
            $this->di['events_manager']->fire(['event' => 'onAfterClientReplyTicket', 'params' => ['id' => $ticket->getId()]]);
        }

        $this->di['logger']->info('Replied to ticket "%s"', $ticket->getId());

        return (int) $msg->getId();
    }

    public function ticketCreateForAdmin(int $clientId, Helpdesk $helpdesk, array $data, \Model_Admin $identity): int
    {
        $status = $data['status'] ?? SupportTicket::STATUS_ONHOLD;

        SupportTicketValidator::validateContent($data['content'] ?? null);
        $this->di['events_manager']->fire(['event' => 'onBeforeAdminOpenTicket', 'params' => $data]);

        $em = $this->di['em'];
        $ticket = new SupportTicket();
        $ticket->setClientId($clientId);
        $ticket->setStatus($status);
        $ticket->setSubject($data['subject']);
        $ticket->setSupportHelpdesk($helpdesk);
        // ClientFence core patch: the ticket and its first message are saved together, so a failed message leaves no empty ticket.
        $em->wrapInTransaction(function () use ($em, $ticket, $identity, $data): void {
            $em->persist($ticket);
            $em->flush();

            $msg = new SupportTicketMessage();
            $msg->setAdminId((int) $identity->id);
            $msg->setSupportTicket($ticket);
            $msg->setContent($data['content']);
            $msg->setIp($this->di['request']->getClientIp());
            $em->persist($msg);
            $em->flush();
        });

        $this->di['events_manager']->fire(['event' => 'onAfterAdminOpenTicket', 'params' => ['id' => $ticket->getId()]]);

        $this->di['logger']->info('Admin opened new ticket "%s"', $ticket->getId());

        return (int) $ticket->getId();
    }

    public function ticketCreateForGuest(array $data): string
    {
        if (!$this->guestTicketsEnabled()) {
            throw new InformationException("We currently aren't accepting support tickets from unregistered users. Please use another contact method.");
        }

        $data['email'] = $this->di['tools']->validateAndSanitizeEmail($data['email']);
        $data['content'] ??= $data['message'] ?? null;

        SupportTicketValidator::validateTicketCreation($data);

        $event_params = $data;
        $event_params['author_role'] = 'guest';
        $event_params['ip'] = $this->di['request']->getClientIp();
        $altered = $this->di['events_manager']->fire(['event' => 'onBeforeClientOpenTicket', 'params' => $event_params]);

        $status = 'open';
        $subject = $data['subject'] ?? null;
        $message = $data['content'] ?? null;

        if (is_array($altered)) {
            $status = $altered['status'] ?? null;
            $subject = $altered['subject'] ?? null;
            $message = $altered['content'] ?? $altered['message'] ?? null;
        }
        SupportTicketValidator::validateContent($message);

        $helpdesk = isset($data['support_helpdesk_id'])
            ? $this->getHelpdeskRepository()->find((int) $data['support_helpdesk_id'])
            : $this->getHelpdeskRepository()->getDefault();

        if (!$helpdesk instanceof Helpdesk) {
            throw new \ClientFence\Exception('Helpdesk invalid');
        }

        $em = $this->di['em'];
        $ticket = new SupportTicket();
        $ticket->setAccessHash(bin2hex(random_bytes(random_int(15, 30))));
        $ticket->setSupportHelpdesk($helpdesk);
        $ticket->setAuthorName($data['name']);
        $ticket->setAuthorEmail($data['email']);
        $ticket->setSubject($subject);
        $ticket->setStatus($status);
        // ClientFence core patch: the urgency the guest chose, Medium if none (TicketPriority).
        $ticket->setPriority(TicketPriority::fromInput($data['priority'] ?? null));
        // ClientFence core patch: the ticket and its first message are saved together, so a failed message leaves no empty ticket.
        $em->wrapInTransaction(function () use ($em, $ticket, $message): void {
            $em->persist($ticket);
            $em->flush();

            $msg = new SupportTicketMessage();
            $msg->setSupportTicket($ticket);
            $msg->setContent($message);
            $msg->setIp($this->di['request']->getClientIp());
            $em->persist($msg);
            $em->flush();
        });

        $this->di['events_manager']->fire(['event' => 'onAfterClientOpenTicket', 'params' => ['id' => $ticket->getId()]]);

        $this->di['logger']->info('"%s" opened guest ticket "%s"', $ticket->getAuthorEmail(), $ticket->getId());

        return $ticket->getAccessHash();
    }

    public function guestTicketsEnabled(): bool
    {
        $extensionService = $this->di['mod_service']('extension');
        $config = $extensionService->getConfig('mod_support');

        $disableGuestTickets = $config['disable_guest_tickets'] ?? $config['disable_public_tickets'] ?? false;

        return !$disableGuestTickets;
    }

    public function canClientSubmitNewTicket(\Model_Client $client, array $config): bool
    {
        $hours = $config['wait_hours'];

        $lastTicket = $this->getSupportTicketRepository()->findOneBy(
            ['clientId' => (int) $client->id],
            ['createdAt' => 'DESC']
        );
        if (!$lastTicket instanceof SupportTicket) {
            return true;
        }

        $createdAt = $lastTicket->getCreatedAt();
        $createdAtStr = $createdAt?->format('Y-m-d H:i:s');
        $timeSinceLast = $createdAtStr !== null ? round(abs(strtotime($createdAtStr) - strtotime(date('Y-m-d H:i:s'))) / 3600, 0) : 0;

        if ($timeSinceLast < $hours) {
            throw new InformationException(sprintf('You can submit one ticket per %s hours. %s hours left', $hours, $hours - $timeSinceLast));
        }

        return true;
    }

    public function ticketCreateForClient(\Model_Client $client, Helpdesk $helpdesk, array $data): int
    {
        SupportTicketValidator::validateTicketCreation($data);
        SupportTicketValidator::validateContent($data['content'] ?? null);

        if (isset($data['rel_id'])) {
            if (filter_var($data['rel_id'], FILTER_VALIDATE_INT) === false) {
                throw new \ClientFence\Exception('rel_id must be a valid integer, received: :value', [':value' => $data['rel_id']]);
            }
            $rel_id = (int) $data['rel_id'];
        } else {
            $rel_id = null;
        }

        $rel_type = $data['rel_type'] ?? null;

        $rel_task = $data['rel_task'] ?? null;
        $rel_new_value = $data['rel_new_value'] ?? null;
        $rel_status = isset($data['rel_task']) ? SupportTicket::REL_STATUS_PENDING : SupportTicket::REL_STATUS_COMPLETE;

        $order = null;
        if ($rel_id !== null && $rel_type === SupportTicket::REL_TYPE_ORDER) {
            $orderService = $this->di['mod_service']('order');
            $order = $orderService->findForClientById($client, $rel_id);
            if (!$order instanceof \Model_ClientOrder) {
                throw new \ClientFence\Exception('You do not have permission to reference this order.');
            }
        }

        if ($rel_task === SupportTicket::REL_TASK_UPGRADE) {
            if (!$order instanceof \Model_ClientOrder) {
                throw new \ClientFence\Exception('You must provide both an order ID and a new product ID in order to request an upgrade.');
            }

            if (filter_var($rel_new_value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new \ClientFence\Exception('rel_new_value must be a valid positive integer product ID, received: :value', [':value' => $rel_new_value]);
            }

            $productService = $this->di['mod_service']('product');
            $productService->assertUpgradeAllowedByIds((int) $order->product_id, (int) $rel_new_value);
        }
        // ClientFence core patch: a cancellation request says when the service ends (CancelRequests).
        if ($rel_task === SupportTicket::REL_TASK_CANCEL && $rel_new_value !== null && $rel_new_value !== '') {
            CancelRequests::assertType($this->di, $order, (string) $rel_new_value);
        }

        if ($rel_id && $rel_type && $rel_task && $this->checkIfTaskAlreadyExists($client, $rel_id, $rel_type, $rel_task)) {
            throw new InformationException('We have already received this request.');
        }

        $mod = $this->di['mod']('support');
        $config = $mod->getConfig();

        if (isset($config['wait_hours']) && is_numeric($config['wait_hours'])) {
            $this->canClientSubmitNewTicket($client, $config);
        }

        $event_params = $data;
        $event_params['author_role'] = 'client';
        $event_params['client_id'] = $client->id;
        $this->di['events_manager']->fire(['event' => 'onBeforeClientOpenTicket', 'params' => $event_params]);

        $ticket = new SupportTicket();
        $ticket->setClientId((int) $client->id);
        $ticket->setSubject($data['subject']);
        $ticket->setSupportHelpdesk($helpdesk);
        // ClientFence core patch: the urgency the client chose, Medium if none (TicketPriority).
        $ticket->setPriority(TicketPriority::fromInput($data['priority'] ?? null));

        // related task with ticket
        $ticket->setRelId($rel_id);
        $ticket->setRelType($rel_type);
        $ticket->setRelTask($rel_task);
        $ticket->setRelNewValue($rel_new_value);
        $ticket->setRelStatus($rel_status);

        // ClientFence core patch: the ticket and its first message are saved together, so a failed message leaves no empty ticket.
        $this->di['em']->wrapInTransaction(function () use ($ticket, $client, $data): void {
            $this->di['em']->persist($ticket);
            $this->di['em']->flush();

            $this->messageCreateForTicket($ticket, $client, $data['content']);
        });

        $this->di['events_manager']->fire(['event' => 'onAfterClientOpenTicket', 'params' => ['id' => $ticket->getId()]]);

        if (
            isset($config['autorespond_enable'])
            && $config['autorespond_enable']
            && isset($config['autorespond_message_id'])
            && !empty($config['autorespond_message_id'])
        ) {
            $this->sendAutoresponderCannedReply($ticket, $config['autorespond_message_id']);
        }

        $this->di['logger']->info('Submitted new ticket "%s"', $ticket->getId());

        return (int) $ticket->getId();
    }

    private function sendAutoresponderCannedReply(SupportTicket $ticket, $cannedId): void
    {
        try {
            $cannedResponse = $this->getCannedResponseRepository()->find((int) $cannedId);

            if (!$cannedResponse instanceof CannedResponse) {
                $this->di['logger']->warning('Autoresponder: canned response #%s not found, skipping reply for ticket #%s', $cannedId, $ticket->getId());

                return;
            }

            $canned = $cannedResponse->toApiArray();

            $staffService = $this->di['mod_service']('staff');
            $admin = $staffService->getCronAdmin();

            if (isset($canned['content']) && $admin instanceof \Model_Admin) {
                $this->ticketReply($ticket, $admin, $canned['content']);
            }
        } catch (\Exception $e) {
            $this->di['logger']->error('Autoresponder canned reply failed: %s', $e->getMessage());
        }
    }

    /**
     * @param \Model_Client $identity
     */
    public function messageCreateForTicket(SupportTicket $ticket, \Model_Admin|\Model_Client $identity, string $content): int
    {
        SupportTicketValidator::validateContent($content);
        $em = $this->di['em'];
        $msg = new SupportTicketMessage();
        $msg->setSupportTicket($ticket);
        if ($identity instanceof \Model_Admin) {
            $msg->setAdminId((int) $identity->id);
        } elseif ($identity instanceof \Model_Client) {
            $msg->setClientId((int) $identity->id);
        } else {
            throw new \ClientFence\Exception('Identity is invalid');
        }
        $msg->setContent($content);
        $msg->setIp($this->di['request']->getClientIp());
        $em->persist($msg);
        $em->flush();

        return (int) $msg->getId();
    }

    public function findOneByHash(string $hash): SupportTicket
    {
        $guestTicket = $this->getSupportTicketRepository()->findOneByAccessHash($hash);
        if (!$guestTicket instanceof SupportTicket) {
            throw new \ClientFence\Exception('Guest ticket not found');
        }

        return $guestTicket;
    }

    public function helpdeskUpdate(Helpdesk $model, array $data): bool
    {
        if (array_key_exists('name', $data)) {
            $model->setName($data['name']);
        }
        if (array_key_exists('email', $data)) {
            $model->setEmail($data['email']);
        }
        if (array_key_exists('can_reopen', $data)) {
            $model->setCanReopen($data['can_reopen']);
        }
        if (array_key_exists('close_after', $data)) {
            $model->setCloseAfter($data['close_after']);
        }
        if (array_key_exists('signature', $data)) {
            $model->setSignature($data['signature']);
        }
        // ClientFence core patch: help desk greeting saved with the help desk (ClientFence).
        if (array_key_exists('greeting', $data)) {
            \ClientFence\Mod\Clientfence\Service::saveHelpdeskGreeting($this->di, (int) $model->getId(), $data['greeting']);
        }
        // ClientFence core patch: the help desk's feedback request, as WHMCS (TicketFeedback).
        if (array_key_exists('feedback_request', $data)) {
            TicketFeedback::saveHelpdesk($this->di, (int) $model->getId(), $data['feedback_request']);
        }
        // ClientFence core patch: the help desk's "Hidden from clients", as WHMCS (HiddenHelpdesks).
        if (array_key_exists('hidden', $data)) {
            HiddenHelpdesks::save($this->di, (int) $model->getId(), $data['hidden']);
        }

        $this->di['em']->flush();

        $this->di['logger']->info('Updated helpdesk #%s', $model->getId());

        return true;
    }

    public function helpdeskCreate(array $data): int
    {
        $model = (new Helpdesk())
            ->setName($data['name'])
            ->setEmail($data['email'] ?? null)
            ->setCanReopen($data['can_reopen'] ?? null)
            ->setCloseAfter($data['close_after'] ?? null)
            ->setSignature($data['signature'] ?? null);

        $this->di['em']->persist($model);
        $this->di['em']->flush();

        $id = (int) $model->getId();

        $this->di['logger']->info('Created helpdesk #%s', $id);

        return $id;
    }

    public function cannedRm(CannedResponse $model): bool
    {
        $id = $model->getId();

        $this->di['em']->remove($model);
        $this->di['em']->flush();

        $this->di['logger']->info('Deleted canned response #%s', $id);

        return true;
    }

    public function cannedCategoryRm(CannedResponseCategory $model): bool
    {
        $id = $model->getId();
        $responsesCount = $id !== null ? $this->getCannedResponseRepository()->countByCategoryId($id) : 0;

        if ($responsesCount > 0) {
            throw new InformationException('Cannot remove category which has canned responses');
        }

        $this->di['em']->remove($model);
        $this->di['em']->flush();
        $this->di['logger']->info('Deleted canned response category #%s', $id);

        return true;
    }

    public function cannedCreate(string $title, int $categoryId, ?string $content = null): int
    {
        $category = $this->getCannedResponseCategoryRepository()->find($categoryId);
        if (!$category instanceof CannedResponseCategory) {
            throw new \ClientFence\Exception('Canned category not found');
        }

        $model = (new CannedResponse())
            ->setCategory($category)
            ->setTitle($title)
            ->setContent($content);

        $this->di['em']->persist($model);
        $this->di['em']->flush();

        $id = (int) $model->getId();

        $this->di['logger']->info('Created new canned response #%s', $id);

        return $id;
    }

    public function cannedUpdate(CannedResponse $model, array $data): bool
    {
        if (isset($data['category_id'])) {
            $category = $this->getCannedResponseCategoryRepository()->find((int) $data['category_id']);
            if (!$category instanceof CannedResponseCategory) {
                throw new \ClientFence\Exception('Canned category not found');
            }

            $model->setCategory($category);
        }

        if (isset($data['title'])) {
            $model->setTitle($data['title']);
        }

        if (array_key_exists('content', $data)) {
            $model->setContent($data['content']);
        }

        $this->di['em']->flush();

        $this->di['logger']->info('Updated canned response #%s', $model->getId());

        return true;
    }

    public function cannedCategoryCreate(string $title): int
    {
        $model = (new CannedResponseCategory())
            ->setTitle($title);

        $this->di['em']->persist($model);
        $this->di['em']->flush();

        $id = (int) $model->getId();

        $this->di['logger']->info('Created new canned response category #%s', $id);

        return $id;
    }

    public function cannedCategoryUpdate(CannedResponseCategory $model, ?string $title = null): bool
    {
        if (isset($title)) {
            $model->setTitle($title);
        }

        $this->di['em']->flush();

        $this->di['logger']->info('Updated canned response category #%s', $model->getId());

        return true;
    }

    public function noteCreate(SupportTicket $ticket, \Model_Admin $identity, string $note): int
    {
        $em = $this->di['em'];
        $model = new SupportTicketNote();
        $model->setSupportTicket($ticket);
        $model->setAdminId((int) $identity->id);
        $model->setNote($note);
        $em->persist($model);
        $em->flush();

        $id = (int) $model->getId();
        $this->di['logger']->info('Added note to ticket #%s', $id);

        return $id;
    }

    public function ticketTaskComplete(SupportTicket $model): bool
    {
        $model->markTaskComplete();
        $this->di['em']->flush();

        $this->di['logger']->info('Marked ticket #%s task as complete', $model->getId());

        return true;
    }

    /*
     * Knowledge Base Functions.
     */

    public function kbEnabled(): bool
    {
        $extensionService = $this->di['mod_service']('extension');
        $config = $extensionService->getConfig('mod_support');

        return Tools::normalizeBoolean($config['kb_enable'] ?? true, true);
    }

    public function kbArticleViewsEnabled(): bool
    {
        $extensionService = $this->di['mod_service']('extension');
        $config = $extensionService->getConfig('mod_support');

        return Tools::normalizeBoolean($config['kb_article_views_enable'] ?? true, true);
    }

    public function kbSuggestionsEnabled(string $area): bool
    {
        if (!$this->kbEnabled()) {
            return false;
        }

        $key = match ($area) {
            'contact' => 'kb_suggestions_contact',
            'ticket' => 'kb_suggestions_ticket',
            default => null,
        };

        if ($key === null) {
            return false;
        }

        $extensionService = $this->di['mod_service']('extension');
        $config = $extensionService->getConfig('mod_support');

        return Tools::normalizeBoolean($config[$key] ?? false);
    }

    public function kbRm(KbArticle $model): void
    {
        $id = $model->getId();
        $this->di['em']->remove($model);
        $this->di['em']->flush();
        $this->di['logger']->info('Deleted Knowledge Base article #%s', $id);
    }

    public function kbCreateArticle(int $articleCategoryId, string $title, ?string $status = null, ?string $content = null): int
    {
        $status = $this->normalizeKbArticleStatus($status ?? KbArticle::DRAFT);
        $category = $this->getKbArticleCategoryRepository()->find($articleCategoryId);
        if (!$category instanceof KbArticleCategory) {
            throw new \ClientFence\Exception('Knowledge Base category not found');
        }

        $model = (new KbArticle())
            ->setCategory($category)
            ->setTitle($title)
            ->setSlug($this->di['tools']->slug($title))
            ->setStatus($status)
            ->setContent($content);

        $this->di['em']->persist($model);
        $this->di['em']->flush();

        $id = (int) $model->getId();
        $this->di['logger']->info('Created new knowledge base article #%s', $id);

        return $id;
    }

    public function kbUpdateArticle(int $id, ?int $articleCategoryId = null, ?string $title = null, ?string $slug = null, ?string $status = null, ?string $content = null, ?int $views = null): bool
    {
        $status = $status !== null ? $this->normalizeKbArticleStatus($status) : null;
        $model = $this->getKbArticleRepository()->find($id);

        if (!$model instanceof KbArticle) {
            throw new \ClientFence\Exception('Article not found');
        }

        if (isset($articleCategoryId)) {
            $category = $this->getKbArticleCategoryRepository()->find($articleCategoryId);
            if (!$category instanceof KbArticleCategory) {
                throw new \ClientFence\Exception('Knowledge Base category not found');
            }

            $model->setCategory($category);
        }

        if (isset($title)) {
            $model->setTitle($title);
        }

        if (isset($slug)) {
            $model->setSlug($slug);
        }

        if (isset($status)) {
            $model->setStatus($status);
        }

        if (isset($content)) {
            $model->setContent($content);
        }

        if (isset($views)) {
            $model->setViews($views);
        }

        $this->di['em']->flush();

        $this->di['logger']->info('Updated knowledge base article #%s', $id);

        return true;
    }

    private function normalizeKbArticleStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if (!in_array($status, [KbArticle::ACTIVE, KbArticle::DRAFT], true)) {
            throw new \ClientFence\Exception('Invalid knowledge base article status: :status', [':status' => $status]);
        }

        return $status;
    }

    public function kbCategoryRm(KbArticleCategory $model): bool
    {
        $id = $model->getId();
        $articlesCount = $id !== null ? $this->getKbArticleRepository()->countByCategoryId($id) : 0;

        if ($articlesCount > 0) {
            throw new InformationException('Cannot remove category which has articles');
        }

        $this->di['em']->remove($model);
        $this->di['em']->flush();

        $this->di['logger']->info('Deleted knowledge base category #%s', $id);

        return true;
    }

    public function kbCreateCategory(string $title, ?string $description = null): int
    {
        $model = (new KbArticleCategory())
            ->setTitle($title)
            ->setDescription($description)
            ->setSlug($this->di['tools']->slug($title));

        $this->di['em']->persist($model);
        $this->di['em']->flush();

        $id = (int) $model->getId();
        $this->di['logger']->info('Created new knowledge base category #%s', $id);

        return $id;
    }

    public function kbUpdateCategory(KbArticleCategory $model, ?string $title = null, ?string $slug = null, ?string $description = null): bool
    {
        if (isset($title)) {
            $model->setTitle($title);
        }

        if (isset($slug)) {
            $model->setSlug($slug);
        }

        if (isset($description)) {
            $model->setDescription($description);
        }

        $this->di['em']->flush();

        $this->di['logger']->info('Updated Knowledge Base category #%s', $model->getId());

        return true;
    }
}
