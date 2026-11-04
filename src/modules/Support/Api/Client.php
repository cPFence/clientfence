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
 * Client support tickets management.
 */

namespace ClientFence\Mod\Support\Api;

use ClientFence\Mod\Support\Entity\Helpdesk;
use ClientFence\Mod\Support\Entity\SupportTicket;
use ClientFence\PaginationOptions;
use ClientFence\Validation\Api\RequiredParams;

class Client extends \ClientFence\Api\AbstractApi
{
    /**
     * Get the list of tickets for the logged in client.
     *
     * @optional string status - filter tickets by status
     * @optional string date_from - show tickets created since this day. Can be any string parsable by strtotime()
     * @optional string date_to - show tickets created until this day. Can be any string parsable by strtotime()
     */
    public function ticket_get_list(array $data): array
    {
        $identity = $this->getIdentity();
        $data['client_id'] = $identity->id;
        // ClientFence core patch: pins and assignees are for the staff list only (TicketBulk, TicketAssign).
        unset($data['bf_pinned_first'], $data['bf_assigned']);

        $repo = $this->getService()->getSupportTicketRepository();

        return $this->getDi()['pager']->paginateMappedQuery(
            $repo->getSearchQueryBuilder($data),
            PaginationOptions::fromArray($data),
            fn (SupportTicket $ticket): array => $this->getService()->toApiArray($ticket, true, $this->getIdentity()),
        );
    }

    /**
     * Return ticket full details.
     */
    #[RequiredParams(['id' => 'Ticket ID was not passed'])]
    public function ticket_get(array $data): array
    {
        $identity = $this->getIdentity();
        $ticket = $this->getService()->findOneByClient($identity, (int) $data['id']);

        return $this->getService()->toApiArray($ticket, true, $identity);
    }

    /**
     * Return pairs for support helpdesk. Can be used to populate select box.
     */
    public function helpdesk_get_pairs(): array
    {
        // ClientFence core patch: without the help desks hidden from clients (HiddenHelpdesks).
        return \ClientFence\Mod\Support\HiddenHelpdesks::visiblePairs($this->getDi(), $this->getService()->getHelpdeskRepository()->getPairs());
    }

    /**
     * Method to create open new ticket. Tickets can have tasks assigned to them
     * via optional parameters.
     *
     * @optional int $rel_type - Ticket relation type
     * @optional int $rel_id - Ticket relation id
     * @optional int $rel_task - Ticket task codename
     * @optional int $rel_new_value - Task can have new value assigned.
     *
     * @return int $id - ticket id
     */
    #[RequiredParams([
        'content' => 'Ticket content required',
        'subject' => 'Ticket subject required',
        'support_helpdesk_id' => 'Ticket support_helpdesk_id required',
    ])]
    public function ticket_create(array $data): int
    {
        $data['content'] = \ClientFence\Tools::sanitizeMarkdownContent($data['content']);

        /** @var \ClientFence\Mod\Support\Repository\HelpdeskRepository $repo */
        $repo = $this->getService()->getHelpdeskRepository();

        $helpdesk = $repo->find((int) $data['support_helpdesk_id']);
        // ClientFence core patch: not in a help desk hidden from clients (HiddenHelpdesks).
        if (!$helpdesk instanceof Helpdesk || \ClientFence\Mod\Support\HiddenHelpdesks::isHidden($this->getDi(), (int) $helpdesk->getId())) {
            throw new \ClientFence\InformationException('Helpdesk invalid');
        }

        $client = $this->getIdentity();

        return $this->getService()->ticketCreateForClient($client, $helpdesk, $data);
    }

    /**
     * Add new conversation message to ticket. Ticket will be reopened if closed.
     */
    #[RequiredParams(['id' => 'Ticket ID was not passed', 'content' => 'Ticket content required'])]
    public function ticket_reply(array $data): bool
    {
        $data['content'] = \ClientFence\Tools::sanitizeMarkdownContent($data['content']);

        $client = $this->getIdentity();
        $ticket = $this->getService()->getSupportTicketRepository()->findOneByClient((int) $client->id, (int) $data['id']);

        if (!$ticket instanceof SupportTicket) {
            throw new \ClientFence\InformationException('Ticket not found', [], 404);
        }

        if (!$this->getService()->canBeReopened($ticket)) {
            throw new \ClientFence\InformationException('Ticket cannot be reopened.');
        }

        $result = $this->getService()->ticketReply($ticket, $client, $data['content']);

        return $result > 0;
    }

    /**
     * Close ticket.
     */
    #[RequiredParams(['id' => 'Ticket ID was not passed'])]
    public function ticket_close(array $data): bool
    {
        $client = $this->getIdentity();

        $ticket = $this->getService()->findOneByClient($client, (int) $data['id']);

        return $this->getService()->closeTicket($ticket, $this->getIdentity());
    }

    /**
     * ClientFence: rates a staff reply of the client's ticket, 1 to 5 stars, once, as WHMCS
     * (TicketFeedback). `id`: ticket, `message_id`: the reply, `rating`: 1 to 5.
     */
    #[RequiredParams(['id' => 'Ticket ID was not passed', 'message_id' => 'Choose a reply', 'rating' => 'Choose a rating'])]
    public function ticket_message_rate(array $data): bool
    {
        $client = $this->getIdentity();
        $ticket = $this->getService()->findOneByClient($client, (int) $data['id']);
        \ClientFence\Mod\Support\TicketFeedback::rate($this->getDi(), (int) $ticket->getId(), (int) $data['message_id'], $data['rating'], 'client', (int) $client->id, $this->getIp());

        return true;
    }

    /**
     * ClientFence: attaches a file (multipart field "file") to the next ticket message of this
     * browser session; `ticket_id` for a reply. Returns {id, name, size, size_label}; the ticket
     * form sends the id as ticket_files[] (TicketFiles.php).
     */
    public function ticket_file_upload($data): array
    {
        $di = $this->getDi();
        $ticketId = isset($data['ticket_id']) && (string) $data['ticket_id'] !== '' ? (int) $data['ticket_id'] : null;

        return \ClientFence\Mod\Support\TicketFiles::uploadForClient($di, (int) $this->getIdentity()->id, $ticketId, $di['request']->files->get('file'));
    }

    /**
     * ClientFence: throws away a file uploaded in this browser session and not sent (`id`).
     */
    public function ticket_file_discard($data): bool
    {
        $di = $this->getDi();
        [$kind, $uploaderId] = \ClientFence\Mod\Support\TicketFiles::clientUploader($di, (int) $this->getIdentity()->id);
        \ClientFence\Mod\Support\TicketFiles::discard($di, (string) ($data['id'] ?? ''), $kind, $uploaderId);

        return true;
    }

    // ClientFence core patch: new ticket forms read secret availability without a ticket.
    public function ticket_secret_kinds(): array
    {
        return \ClientFence\Mod\Support\TicketSecrets::kinds($this->getDi());
    }

    // ClientFence core patch: ticket secrets stay separate from ticket messages and email payloads.
    #[RequiredParams(['ticket_id' => 'Ticket ID was not passed', 'kind' => 'Choose a kind of secret'])]
    public function ticket_secret_add(array $data): array
    {
        return \ClientFence\Mod\Support\TicketSecrets::addForClient($this->getDi(), (int) $this->getIdentity()->id, (int) $data['ticket_id'], (string) $data['kind'], $data);
    }

    #[RequiredParams(['ticket_id' => 'Ticket ID was not passed'])]
    public function ticket_secret_list(array $data): array
    {
        return \ClientFence\Mod\Support\TicketSecrets::listForTicket($this->getDi(), (int) $data['ticket_id'], (int) $this->getIdentity()->id);
    }

    #[RequiredParams(['id' => 'Secret ID was not passed'])]
    public function ticket_secret_delete(array $data): bool
    {
        \ClientFence\Mod\Support\TicketSecrets::delete($this->getDi(), (int) $data['id'], null, (int) $this->getIdentity()->id);

        return true;
    }

    /**
     * ClientFence: the open issues and the scheduled work that affect the client's services, for the
     * client area's banner (NetworkIssues.php); none while network status is off.
     */
    public function network_issue_alerts($data): array
    {
        $di = $this->getDi();
        if (!\ClientFence\Mod\Support\NetworkIssues::enabled($di)) {
            return [];
        }
        \ClientFence\Mod\Support\NetworkIssues::ensure($di);

        return \ClientFence\Mod\Support\NetworkIssues::alertsFor($di, (int) $this->getIdentity()->id);
    }
}
