<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\News\Api;

use ClientFence\Mod\News\Entity\Post;
use ClientFence\PaginationOptions;
use ClientFence\Validation\Api\RequiredParams;

class Admin extends \ClientFence\Api\AbstractApi
{
    /**
     * Get paginated list of news items (any status).
     *
     * @param array $data Filtering and pagination parameters
     *
     * @return array Paginated list of news items
     */
    public function get_list(array $data): array
    {
        $this->checkPermissions('news', 'view');

        /** @var \ClientFence\Mod\News\Repository\PostRepository $repo */
        $repo = $this->getService()->getPostRepository();

        // Repository method returns a QueryBuilder with filters applied
        $qb = $repo->getSearchQueryBuilder($data);

        return $this->getDi()['pager']->paginateDoctrineQuery($qb, PaginationOptions::fromArray($data));
    }

    /**
     * Get a single news item by ID or slug.
     *
     * @param array $data ['id' => int|null, 'slug' => string|null]
     *
     * @throws \ClientFence\InformationException if ID/slug is missing or news item not found
     */
    public function get(array $data): array
    {
        $this->checkPermissions('news', 'view');

        $id = $data['id'] ?? null;
        $slug = $data['slug'] ?? null;

        if (!$id && !$slug) {
            throw new \ClientFence\Exception('ID or slug is required.');
        }

        /** @var \ClientFence\Mod\News\Repository\PostRepository $repo */
        $repo = $this->getService()->getPostRepository();

        $post = null;
        if ($id) {
            $post = $repo->find($id);
        } elseif ($slug) {
            $post = $repo->findOneBy(['slug' => $slug]);
        }

        if (!$post instanceof Post) {
            throw new \ClientFence\InformationException('News item not found.');
        }

        /** @todo Doctrine: Replace with actual Admin entity once it's migrated to Doctrine. */
        $admin = $post->getAdminId() === null ? [] : $repo->findAdminSummary($post->getAdminId()) ?? [];

        $post->setAdminData($admin);

        return $post->toApiArray();
    }

    /**
     * Update news item.
     */
    #[RequiredParams(['id' => 'Post ID was not passed'])]
    public function update(array $data): bool
    {
        $this->checkPermissions('news', 'manage');

        /** @var \ClientFence\Mod\News\Repository\PostRepository $repo */
        $repo = $this->getService()->getPostRepository();

        $post = $repo->find($data['id']);

        if (!$post instanceof Post) {
            throw new \ClientFence\InformationException('News item not found');
        }

        if (!empty($data['created_at'])) {
            $post->setCreatedAt(new \DateTime($data['created_at']));
        }

        $post->setAdminId($this->getIdentity()->id);
        $this->getService()->savePost($post, $data);

        $this->getDi()['logger']->info('Updated news item #%s', $post->getId());

        return true;
    }

    /**
     * Create new news item.
     *
     * @return int New post ID
     */
    #[RequiredParams(['title' => 'Post title was not passed'])]
    public function create(array $data): int
    {
        $this->checkPermissions('news', 'manage');

        $post = new Post('', '');
        $post->setAdminId($this->getIdentity()->id);
        $this->getService()->savePost($post, $data);

        $this->getDi()['logger']->info('Created news item #%s', $post->getId());

        return $post->getId();
    }

    /**
     * Delete news item by ID.
     */
    #[RequiredParams(['id' => 'Post ID was not passed'])]
    public function delete(array $data): bool
    {
        $this->checkPermissions('news', 'manage');

        /** @var \ClientFence\Mod\News\Repository\PostRepository $repo */
        $repo = $this->getService()->getPostRepository();

        $post = $repo->find($data['id']);

        if (!$post instanceof Post) {
            throw new \ClientFence\InformationException('News item not found');
        }

        $this->getDi()['em']->remove($post);
        $this->getDi()['em']->flush();

        $this->getDi()['logger']->info('Removed news item #%s', $data['id']);

        return true;
    }

    /**
     * Batch delete news items by IDs.
     */
    #[RequiredParams(['ids' => 'IDs were not passed'])]
    public function batch_delete(array $data): bool
    {
        $this->checkPermissions('news', 'manage');

        /** @var \ClientFence\Mod\News\Repository\PostRepository $repo */
        $repo = $this->getService()->getPostRepository();

        $count = $repo->deleteByIds($data['ids']);

        $this->getDi()['logger']->info('Removed %s news items', $count);

        return true;
    }
}
