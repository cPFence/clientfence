<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\News;

use ClientFence\Mod\News\Entity\Post;
use ClientFence\Mod\News\Repository\PostRepository;

class Service
{
    protected ?\Pimple\Container $di = null;
    protected PostRepository $postRepository;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
        $this->postRepository = $this->di['em']->getRepository(Post::class);
    }

    public function getModulePermissions(): array
    {
        return [
            'view' => [
                'type' => 'bool',
                'display_name' => __trans('View news'),
                'description' => __trans('Allows the staff member to view news items.'),
            ],
            'manage' => [
                'type' => 'bool',
                'display_name' => __trans('Manage news'),
                'description' => __trans('Allows the staff member to create, update, and delete news items.'),
            ],
        ];
    }

    public function getPostRepository(): PostRepository
    {
        return $this->postRepository;
    }

    /**
     * Generate a placeholder meta description from given string.
     *
     * @param string $content - string to generate description from
     *
     * @return string Placeholder meta description
     */
    public function generateDescriptionFromContent(string $content): string
    {
        $desc = mb_convert_encoding($content, 'UTF-8');
        $desc = strip_tags($desc);
        $desc = str_replace(["\n", "\r", "\t"], ' ', $desc);

        // ClientFence core patch (ext news): cut by characters; a byte cut split non-Latin text and the database refused the post.
        return mb_substr($desc, 0, 125);
    }

    // ClientFence core patch (ext news): create and update share the checks; a field left out keeps the post's value.
    public function savePost(Post $post, array $data): void
    {
        $title = trim((string) ($data['title'] ?? $post->getTitle()));
        if ($title === '') {
            throw new \ClientFence\InformationException('Post title was not passed');
        }
        foreach ([$title, $data['slug'] ?? '', $data['image'] ?? '', $data['section'] ?? ''] as $value) {
            if (mb_strlen((string) $value) > 255) {
                throw new \ClientFence\InformationException('Keep the title, slug, image and section under 255 characters.');
            }
        }

        $slug = $this->di['tools']->slug($data['slug'] ?? $post->getSlug());
        $other = $slug === '' ? null : $this->postRepository->findOneBy(['slug' => $slug]);
        if ($other instanceof Post && $other !== $post) {
            throw new \ClientFence\Exception('You need to set unique slug.', null, 9999);
        }
        if ($slug === '') {
            $slug = $this->uniqueSlug($title, $post);
        }

        $content = $data['content'] ?? $post->getContent();
        $description = $data['description'] ?? $post->getDescription();

        $post->setTitle($title)
            ->setSlug($slug)
            ->setContent($content)
            ->setDescription($description ?: $this->generateDescriptionFromContent((string) $content))
            ->setImage($data['image'] ?? $post->getImage())
            ->setSection($data['section'] ?? $post->getSection())
            ->setStatus($data['status'] ?? $post->getStatus())
            ->setPinned(filter_var($data['pinned'] ?? $post->isPinned(), FILTER_VALIDATE_BOOLEAN))
            ->setPublishAt($this->date($data, 'publish_at', $post->getPublishAt()))
            ->setExpiresAt($this->date($data, 'expires_at', $post->getExpiresAt()));

        $this->di['em']->persist($post);
        $this->di['em']->flush();
    }

    // ClientFence core patch (ext news): a title without a-z letters or digits made an empty slug, and a repeated title a taken one.
    private function uniqueSlug(string $title, Post $post): string
    {
        $base = substr($this->di['tools']->slug($title) ?: 'post', 0, 240);
        $slug = $base;
        for ($i = 2; ($other = $this->postRepository->findOneBy(['slug' => $slug])) instanceof Post && $other !== $post; ++$i) {
            $slug = $base . '-' . $i;
        }

        return $slug;
    }

    private function date(array $data, string $key, ?\DateTime $current): ?\DateTime
    {
        if (!array_key_exists($key, $data)) {
            return $current;
        }
        if ($data[$key] === null || $data[$key] === '') {
            return null;
        }

        return date_create((string) $data[$key]) ?: throw new \ClientFence\InformationException('Enter a valid date.');
    }
}
