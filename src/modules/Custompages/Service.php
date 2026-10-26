<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Custompages;

use ClientFence\Mod\Custompages\Entity\CustomPage;
use ClientFence\Mod\Custompages\Repository\CustomPageRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use ClientFence\PaginationOptions;

class Service
{
    protected ?\Pimple\Container $di = null;
    protected CustomPageRepository $pageRepository;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
        $this->pageRepository = $di['em']->getRepository(CustomPage::class);
    }

    public function getPageRepository(): CustomPageRepository
    {
        return $this->pageRepository;
    }

    public function getModulePermissions(): array
    {
        return [
            'view' => [
                'type' => 'bool',
                'display_name' => __trans('View custom pages'),
                'description' => __trans('Allows the staff member to view custom pages.'),
            ],
            'manage' => [
                'type' => 'bool',
                'display_name' => __trans('Manage custom pages'),
                'description' => __trans('Allows the staff member to create, update, and delete custom pages.'),
            ],
        ];
    }

    public function install(): bool
    {
        $sql = '
            CREATE TABLE IF NOT EXISTS `custom_pages` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `title` varchar(255) NOT NULL,
                `description` varchar(555) NOT NULL,
                `keywords` varchar(555) NOT NULL,
                `content` text NOT NULL,
                `slug` varchar(255) NOT NULL,
                `status` varchar(16) NOT NULL DEFAULT \'published\',
                `visibility` varchar(16) NOT NULL DEFAULT \'everyone\',
                `show_in_menu` tinyint(1) NOT NULL DEFAULT 0,
                `show_in_footer` tinyint(1) NOT NULL DEFAULT 0,
                `sort_order` int(11) NOT NULL DEFAULT 0,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_custom_pages_slug` (`slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $this->di['db']->exec($sql);

        return true;
    }

    public function searchPages(array $data = []): array
    {
        $qb = $this->pageRepository->getSearchQueryBuilder($data);

        return $this->di['pager']->paginateDoctrineQuery($qb, PaginationOptions::fromArray($data));
    }

    public function deletePage($id): void
    {
        if (is_array($id)) {
            $ids = array_map(static fn ($x): int => (int) $x, $id);
            $this->pageRepository->deleteByIds($ids);

            return;
        }

        $page = $this->pageRepository->find((int) $id);
        if ($page instanceof CustomPage) {
            $this->di['em']->remove($page);
            $this->di['em']->flush();
        }
    }

    public function getPage($id, $type = 'id'): ?array
    {
        $allowedColumns = ['id', 'slug'];
        if (!in_array($type, $allowedColumns, true)) {
            throw new \ClientFence\Exception('Invalid column type: :type', [':type' => $type]);
        }

        $page = $type === 'slug'
            ? $this->pageRepository->findOneBySlug((string) $id)
            : $this->pageRepository->find((int) $id);

        return $page instanceof CustomPage ? $page->toApiArray() : null;
    }

    public function createPage(array $data): int
    {
        $page = new CustomPage();
        $this->fill($page, $data);
        $this->save($page);
        $this->di['logger']->info('Created new custom page #%s', $page->getId());

        return (int) $page->getId();
    }

    public function updatePage(int $id, array $data): int
    {
        $page = $this->pageRepository->find($id);
        if (!$page instanceof CustomPage) {
            throw new \ClientFence\Exception('Custom page not found');
        }

        $this->fill($page, $data);
        $this->save($page);
        $this->di['logger']->info('Updated custom page #%s', $id);

        return $id;
    }

    // ClientFence core patch (ext custompages): the visibilities a viewer may open; a visitor (null) only Everyone pages.
    public function visibilitiesFor(?int $clientId): array
    {
        if ($clientId === null) {
            return ['everyone'];
        }
        $active = $this->di['dbal']->fetchOne("SELECT 1 FROM client_order WHERE client_id = ? AND status = 'active' LIMIT 1", [$clientId]);

        return $active ? CustomPage::VISIBILITIES : ['everyone', 'clients'];
    }

    public function viewerClientId(): ?int
    {
        return $this->di['auth']->isClientLoggedIn() ? (int) $this->di['session']->get('client_id') : null;
    }

    public function getLinks(): array
    {
        return array_map(static fn (CustomPage $page): array => [
            'title' => $page->getTitle(),
            'slug' => $page->getSlug(),
            'menu' => $page->isShownInMenu(),
            'footer' => $page->isShownInFooter(),
        ], $this->pageRepository->findLinks($this->visibilitiesFor($this->viewerClientId())));
    }

    private function fill(CustomPage $page, array $data): void
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 255) {
            throw new \ClientFence\InformationException('Enter a title of up to 255 characters.');
        }
        foreach (['description', 'keywords'] as $field) {
            if (mb_strlen((string) ($data[$field] ?? '')) > 555) {
                throw new \ClientFence\InformationException('Keep the description and keywords under 555 characters.');
            }
        }
        $status = $data['status'] ?? $page->getStatus();
        $visibility = $data['visibility'] ?? $page->getVisibility();
        if (!in_array($status, CustomPage::STATUSES, true) || !in_array($visibility, CustomPage::VISIBILITIES, true)) {
            throw new \ClientFence\InformationException('Unknown status or visibility.');
        }

        $slug = $this->di['tools']->slug($data['slug'] ?? '');
        if ($slug === '') {
            $slug = $this->generateUniqueSlug($title, $page->getId() ?? 0);
        } elseif (strlen($slug) > 255) {
            throw new \ClientFence\InformationException('Keep the slug under 255 characters.');
        } elseif ($this->pageRepository->findOneBySlugExcludingId($slug, $page->getId() ?? 0) instanceof CustomPage) {
            throw new \ClientFence\Exception('You need to set unique slug.', null, 9999);
        }

        $page->setTitle($title)
            ->setDescription((string) ($data['description'] ?? ''))
            ->setKeywords((string) ($data['keywords'] ?? ''))
            ->setContent((string) $data['content'])
            ->setSlug($slug)
            ->setStatus($status)
            ->setVisibility($visibility)
            ->setShowInMenu(filter_var($data['show_in_menu'] ?? $page->isShownInMenu(), FILTER_VALIDATE_BOOLEAN))
            ->setShowInFooter(filter_var($data['show_in_footer'] ?? $page->isShownInFooter(), FILTER_VALIDATE_BOOLEAN))
            ->setSortOrder((int) ($data['sort_order'] ?? $page->getSortOrder()));
    }

    private function save(CustomPage $page): void
    {
        try {
            $this->di['em']->persist($page);
            $this->di['em']->flush();
        } catch (UniqueConstraintViolationException) {
            // ClientFence core patch (ext custompages): a concurrent save took the slug; Doctrine closed the entity manager, so no retry.
            throw new \ClientFence\Exception('You need to set unique slug.', null, 9999);
        }
    }

    /**
     * Generate a unique slug for a page title, appending an incrementing
     * suffix until no existing page uses it.
     *
     * (`<slug>-1`, `<slug>-2`, ...). Candidates are truncated so they never
     * exceed the custom_pages.slug VARCHAR(255) column, reserving room for the
     * hyphen and suffix before appending it.
     */
    private function generateUniqueSlug(string $title, int $id): string
    {
        // ClientFence core patch (ext custompages): a title without a-z letters or digits (Arabic, Cyrillic) made an empty slug, a page with no address.
        $base = $this->di['tools']->slug($title) ?: 'page';
        $slug = $this->fitSlug($base, null);
        $i = 0;
        while ($this->pageRepository->findOneBySlugExcludingId($slug, $id) instanceof CustomPage) {
            $slug = $this->fitSlug($base, ++$i);
        }

        return $slug;
    }

    /**
     * Truncate a slug (optionally suffixed) to fit the VARCHAR(255) column,
     * reserving room for "-$suffix" when a suffix is requested.
     */
    private function fitSlug(string $base, ?int $suffix): string
    {
        if ($suffix === null) {
            return strlen($base) <= 255 ? $base : substr($base, 0, 255);
        }

        $suffixStr = '-' . $suffix;
        if (strlen($base) + strlen($suffixStr) <= 255) {
            return $base . $suffixStr;
        }

        return substr($base, 0, 255 - strlen($suffixStr)) . $suffixStr;
    }
}
