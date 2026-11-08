<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Support\Api;

use ClientFence\Mod\Support\Entity\KbArticle;
use ClientFence\Mod\Support\Entity\KbArticleCategory;
use ClientFence\PaginationOptions;
use ClientFence\Validation\Api\RequiredParams;

class Guest extends \ClientFence\Api\AbstractApi
{
    /**
     * Submit new ticket.
     *
     * @return string - ticket hash
     */
    #[RequiredParams([
        'name' => 'Please enter your name',
        'email' => 'Please enter your email address',
        'subject' => 'Please enter the subject',
    ])]
    public function ticket_create(array $data): string
    {
        // Deprecated 0.9.0 The 'message' parameter will be dropped. Update your themes to use 'content' instead.
        $content = $data['content'] ?? $data['message'] ?? null;
        if (!is_string($content) || strlen($content) < 4) {
            throw new \ClientFence\InformationException('Please enter your message');
        }

        $this->getDi()['rate_limiter']->consumeOrThrow('guest_ticket_create', (string) $this->getIp());
        $data['email'] = $this->getDi()['tools']->validateAndSanitizeEmail($data['email']);

        $data['content'] = \ClientFence\Tools::sanitizeMarkdownContent($content);

        // ClientFence core patch: not in a help desk hidden from clients; without a help desk, the
        // first one clients see rather than a hidden one (HiddenHelpdesks).
        $hidden = \ClientFence\Mod\Support\HiddenHelpdesks::ids($this->getDi());
        if ($hidden !== []) {
            if (isset($data['support_helpdesk_id'])) {
                if (in_array((int) $data['support_helpdesk_id'], $hidden, true)) {
                    throw new \ClientFence\InformationException('Helpdesk invalid');
                }
            } else {
                $visible = \ClientFence\Mod\Support\HiddenHelpdesks::visiblePairs($this->getDi(), $this->getService()->getHelpdeskRepository()->getPairs());
                if ($visible === []) {
                    throw new \ClientFence\InformationException('Helpdesk invalid');
                }
                $data['support_helpdesk_id'] = array_key_first($visible);
            }
        }

        return $this->getService()->ticketCreateForGuest($data);
    }

    /**
     * Get ticket.
     *
     * @return array - ticket details
     */
    #[RequiredParams(['hash' => 'Ticket hash required'])]
    public function ticket_get(array $data): array
    {
        $guestTicket = $this->getService()->findOneByHash($data['hash']);

        return $this->getService()->toApiArray($guestTicket);
    }

    /**
     * Close ticket.
     */
    #[RequiredParams(['hash' => 'Ticket hash required'])]
    public function ticket_close(array $data): bool
    {
        $guestTicket = $this->getService()->findOneByHash($data['hash']);

        return $this->getService()->closeTicket($guestTicket, $this->getIdentity());
    }

    /**
     * ClientFence: rates a staff reply of a guest ticket, 1 to 5 stars, once, as WHMCS
     * (TicketFeedback). `hash`: the ticket link, `message_id`: the reply, `rating`: 1 to 5.
     */
    #[RequiredParams(['hash' => 'Ticket hash required', 'message_id' => 'Choose a reply', 'rating' => 'Choose a rating'])]
    public function ticket_message_rate(array $data): bool
    {
        $guestTicket = $this->getService()->findOneByHash($data['hash']);
        \ClientFence\Mod\Support\TicketFeedback::rate($this->getDi(), (int) $guestTicket->getId(), (int) $data['message_id'], $data['rating'], 'guest', 0, $this->getIp());

        return true;
    }

    /**
     * ClientFence: the feedback form of a feedback email's link (no sign-in, as WHMCS;
     * TicketFeedback). `token`: the link's code, `rate`: staff id => 1 to 10, `comments`: staff id
     * or "generic" => text.
     */
    #[RequiredParams(['token' => 'This feedback link is not valid.'])]
    public function ticket_feedback_submit(array $data): bool
    {
        \ClientFence\Mod\Support\TicketFeedback::submit($this->getDi(), (string) $data['token'], $data['rate'] ?? [], $data['comments'] ?? [], $this->getIp());

        return true;
    }

    /**
     * Reply to ticket.
     */
    #[RequiredParams(['hash' => 'Ticket hash required'])]
    public function ticket_reply(array $data): int
    {
        $guestTicket = $this->getService()->findOneByHash($data['hash']);

        // ClientFence core patch: the help desk's reopen rule also applies to guest replies.
        if (!$this->getService()->canBeReopened($guestTicket)) {
            throw new \ClientFence\InformationException('Ticket cannot be reopened.');
        }

        // Deprecated 0.9.0 The 'message' parameter will be dropped. Update your themes to use 'content' instead.
        $message = $data['content'] ?? $data['message'] ?? null;

        if (!is_string($message)) {
            throw new \ClientFence\InformationException('Message cannot be empty');
        }

        $message = \ClientFence\Tools::sanitizeMarkdownContent($message);

        return $this->getService()->ticketReply($guestTicket, new \ClientFence\Identity\Guest(), $message);
    }

    /**
     * Get whether guest tickets are enabled.
     */
    public function guest_tickets_enabled(): bool
    {
        return $this->getService()->guestTicketsEnabled();
    }

    /** ClientFence: whether clients and guests can open tickets (Support settings "Tickets"). */
    public function tickets_enabled(): bool
    {
        return $this->getService()->ticketsEnabled();
    }

    /** ClientFence: the outside help desk address while tickets are off, or null. */
    public function support_url(): ?string
    {
        return $this->getService()->ticketsEnabled() ? null : $this->getService()->supportUrl();
    }

    /**
     * @deprecated use guest_tickets_enabled() instead
     */
    public function public_tickets_enabled(): bool
    {
        return $this->guest_tickets_enabled();
    }

    /**
     * ClientFence: ticket priorities for the new ticket form's Urgency, as WHMCS (TicketPriority),
     * level => name, highest first.
     *
     * @return array<string, string>
     */
    public function ticket_priorities(): array
    {
        return \ClientFence\Mod\Support\TicketPriority::titles();
    }

    /**
     * Return pairs for support helpdesks. Can be used to populate the select box.
     */
    public function helpdesk_get_pairs(): array
    {
        // ClientFence core patch: without the help desks hidden from clients (HiddenHelpdesks).
        return \ClientFence\Mod\Support\HiddenHelpdesks::visiblePairs($this->getDi(), $this->getService()->getHelpdeskRepository()->getPairs());
    }

    /*
     * Support Knowledge Base Functions.
     */
    /**
     * Get whether the knowledge base is enabled, or not.
     */
    public function kb_enabled(): bool
    {
        return $this->getService()->kbEnabled();
    }

    /**
     * Get whether the knowledge base suggestions are enabled in the specified area.
     */
    public function kb_suggestions_enabled(array $data): bool
    {
        return $this->getService()->kbSuggestionsEnabled($data['area'] ?? '');
    }

    /**
     * Get whether public knowledge base article view counts are visible.
     */
    public function kb_article_views_enabled(): bool
    {
        return $this->getService()->kbArticleViewsEnabled();
    }

    /**
     * Get paginated list of knowledge base articles.
     * Returns only active articles.
     */
    public function kb_article_get_list(array $data): array
    {
        $this->assertKbEnabled();

        $search = $data['search'] ?? null;
        $cat = $data['kb_article_category_id'] ?? null;

        /** @var \ClientFence\Mod\Support\Repository\KbArticleRepository $repo */
        $repo = $this->getService()->getKbArticleRepository();

        // ClientFence core patch: not the articles of categories hidden from clients (HiddenKbCategories).
        $qb = $repo->getSearchQueryBuilder(KbArticle::ACTIVE, $search, $cat, \ClientFence\Mod\Support\HiddenKbCategories::ids($this->getDi()));

        return $this->getDi()['pager']->paginateDoctrineQuery($qb, PaginationOptions::fromArray($data), $this->getIdentity(), false, $this->getService()->kbArticleViewsEnabled());
    }

    /**
     * Get active knowledge base article.
     */
    public function kb_article_get(array $data): array
    {
        $this->assertKbEnabled();

        if (!isset($data['id']) && !isset($data['slug'])) {
            throw new \ClientFence\InformationException('ID or slug is missing');
        }

        $id = $data['id'] ?? null;
        $slug = $data['slug'] ?? null;

        /** @var \ClientFence\Mod\Support\Repository\KbArticleRepository $repo */
        $repo = $this->getService()->getKbArticleRepository();

        $article = $id
            ? $repo->findOneActiveById((int) $id)
            : $repo->findOneActiveBySlug($slug);

        // ClientFence core patch: nor one in a category hidden from clients (HiddenKbCategories).
        if (!$article instanceof KbArticle || \ClientFence\Mod\Support\HiddenKbCategories::isHidden($this->getDi(), $article->getCategory()?->getId())) {
            throw new \ClientFence\InformationException('Article item not found', [], 404); // ClientFence core patch: a 404 page, not a 500
        }

        $repo->incrementViews($article);

        return $article->toApiArray($this->getIdentity(), includeContent: true, includeViews: $this->getService()->kbArticleViewsEnabled());
    }

    /**
     * Get paginated list of knowledge base categories.
     */
    public function kb_category_get_list(array $data): array
    {
        $this->assertKbEnabled();

        $data['article_status'] = KbArticle::ACTIVE;
        // ClientFence core patch: without the categories hidden from clients (HiddenKbCategories).
        $data['exclude_ids'] = \ClientFence\Mod\Support\HiddenKbCategories::ids($this->getDi());
        $q = $data['q'] ?? null;

        /** @var \ClientFence\Mod\Support\Repository\KbArticleCategoryRepository $repo */
        $repo = $this->getService()->getKbArticleCategoryRepository();

        $qb = $repo->getSearchQueryBuilder($data);

        return $this->getDi()['pager']->paginateDoctrineQuery($qb, PaginationOptions::fromArray($data), $this->getIdentity(), $q, $this->getService()->kbArticleViewsEnabled());
    }

    /**
     * Get knowledge base category by ID or SLUG.
     */
    public function kb_category_get(array $data): array
    {
        $this->assertKbEnabled();

        if (!isset($data['id']) && !isset($data['slug'])) {
            throw new \ClientFence\InformationException('Category ID or slug is missing');
        }

        $id = $data['id'] ?? null;
        $slug = $data['slug'] ?? null;

        /** @var \ClientFence\Mod\Support\Repository\KbArticleCategoryRepository $repo */
        $repo = $this->getService()->getKbArticleCategoryRepository();

        $cat = $id
            ? $repo->find((int) $id)
            : $repo->findOneBySlug($slug);

        // ClientFence core patch: nor a category hidden from clients (HiddenKbCategories).
        if (!$cat instanceof KbArticleCategory || \ClientFence\Mod\Support\HiddenKbCategories::isHidden($this->getDi(), $cat->getId())) {
            throw new \ClientFence\InformationException('Knowledge Base category not found', [], 404); // ClientFence core patch: a 404 page, not a 500
        }

        return $cat->toApiArray($this->getIdentity(), includeArticleViews: $this->getService()->kbArticleViewsEnabled());
    }

    private function assertKbEnabled(): void
    {
        if (!$this->getService()->kbEnabled()) {
            throw new \ClientFence\InformationException('Knowledge Base is disabled');
        }
    }

    /**
     * ClientFence: what ticket attachments allow (the upload fields): extensions, number and size of
     * files (TicketFiles.php).
     */
    public function ticket_file_rules(): array
    {
        return \ClientFence\Mod\Support\TicketFiles::rules();
    }

    /**
     * ClientFence: whether the Network Status page is on, and whether it needs signing in
     * (NetworkIssues.php).
     *
     * @return array{enabled: bool, public: bool}
     */
    public function network_status_settings(): array
    {
        return [
            'enabled' => \ClientFence\Mod\Support\NetworkIssues::enabled($this->getDi()),
            'public' => \ClientFence\Mod\Support\NetworkIssues::isPublic($this->getDi()),
        ];
    }

    /**
     * ClientFence: a tab of the Network Status page (`group`: open, scheduled or resolved; `page`),
     * with the tabs' counts, for signed-in clients (anyone when the page is public).
     */
    public function network_issues($data): array
    {
        $di = $this->getDi();
        if (!\ClientFence\Mod\Support\NetworkIssues::enabled($di)) {
            throw new \ClientFence\InformationException('Network status is not available.', [], 404);
        }
        if (!\ClientFence\Mod\Support\NetworkIssues::isPublic($di) && (int) ($di['session']->get('client_id') ?? 0) <= 0) {
            throw new \ClientFence\InformationException('Sign in to see the network status.', [], 403);
        }
        \ClientFence\Mod\Support\NetworkIssues::ensure($di);

        return \ClientFence\Mod\Support\NetworkIssues::forPage($di, (string) ($data['group'] ?? 'open'), (int) ($data['page'] ?? 1))
            + ['counts' => \ClientFence\Mod\Support\NetworkIssues::counts($di)];
    }
}
