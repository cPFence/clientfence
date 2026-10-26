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

class Guest extends \ClientFence\Api\AbstractApi
{
    /**
     * Get paginated list of active news items.
     *
     * @param array $data Filtering and pagination parameters
     *
     * @return array Paginated list of news items
     */
    public function get_list(array $data): array
    {
        /** @var \ClientFence\Mod\News\Repository\PostRepository $repo */
        $repo = $this->getService()->getPostRepository();

        $qb = $repo->getVisibleQueryBuilder($data);

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
        $id = $data['id'] ?? null;
        $slug = $data['slug'] ?? null;

        if (!$id && !$slug) {
            throw new \ClientFence\InformationException('ID or slug is required.');
        }

        /** @var \ClientFence\Mod\News\Repository\PostRepository $repo */
        $repo = $this->getService()->getPostRepository();

        $post = $id ? $repo->findOneVisible('id', (int) $id) : $repo->findOneVisible('slug', (string) $slug);

        if (!$post instanceof Post) {
            // ClientFence core patch (ext news): a missing, scheduled or expired post is a 404, not a 500.
            throw new \ClientFence\InformationException('News item not found.', [], 404);
        }

        /**@todo Doctrine: Replace with actual Admin entity once it's migrated to Doctrine. */
        $admin = $post->getAdminId() === null ? [] : $repo->findAdminSummary($post->getAdminId()) ?? [];

        $post->setAdminData($admin);

        return $post->toApiArray();
    }
}
