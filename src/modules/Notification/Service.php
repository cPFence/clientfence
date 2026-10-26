<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Notification;

use ClientFence\InformationException;
use ClientFence\InjectionAwareInterface;
use ClientFence\Mod\Clientfence\Notifications;
use ClientFence\Mod\Extension\Entity\ExtensionMeta;
use ClientFence\Mod\Extension\Repository\ExtensionMetaRepository;

class Service implements InjectionAwareInterface
{
    // ClientFence core patch (ext notification): an announcement is one extension_meta row, rel_id its author, meta_value {"title", "message", "staff" (null: all staff), "pinned_until"}.
    public const KEY = 'announcement';

    protected ?\Pimple\Container $di = null;
    private ?ExtensionMetaRepository $extensionMetaRepository = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
        $this->extensionMetaRepository = isset($this->di['em'])
            ? $this->di['em']->getRepository(ExtensionMeta::class)
            : null;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function getModulePermissions(): array
    {
        return [
            'view' => [
                'type' => 'bool',
                'display_name' => __trans('Read announcements'),
                'description' => __trans('Allows the staff member to read the announcements sent to them.'),
            ],
            'manage' => [
                'type' => 'bool',
                'display_name' => __trans('Manage announcements'),
                'description' => __trans('Allows the staff member to post, unpin and delete announcements and to see all of them.'),
            ],
        ];
    }

    public function getExtensionMetaRepository(): ExtensionMetaRepository
    {
        if ($this->extensionMetaRepository === null) {
            if ($this->di === null) {
                throw new \ClientFence\Exception('The dependency injection container has not been set.');
            }

            $this->extensionMetaRepository = $this->di['em']->getRepository(ExtensionMeta::class);
        }

        return $this->extensionMetaRepository;
    }

    public function getList(\Model_Admin $admin, bool $all): array
    {
        $rows = $this->getExtensionMetaRepository()->findByExtensionAndScope('mod_notification', self::KEY, null, null, ['id' => 'DESC']);
        $names = $this->di['em']->getConnection()->fetchAllKeyValue('SELECT id, name FROM admin');

        $list = [];
        foreach ($rows as $row) {
            $item = $this->toApiArray($row, $names);
            if ($all || $item['staff'] === null || in_array((int) $admin->id, $item['staff'], true)) {
                $list[] = $item;
            }
        }

        return $list;
    }

    public function getPinned(\Model_Admin $admin): array
    {
        return array_values(array_filter($this->getList($admin, false), static fn (array $item): bool => $item['pinned']));
    }

    public function readers(): array
    {
        $staff = $this->di['mod_service']('staff');
        $readers = [];
        foreach (Notifications::staffWith($this->di, 'notification') as $id) {
            $admin = $this->di['db']->load('Admin', $id);
            if ($staff->hasPermission($admin, 'notification', 'view')) {
                $readers[$id] = (string) $admin->name;
            }
        }

        return $readers;
    }

    public function toApiArray(ExtensionMeta $row, array $names): array
    {
        $value = json_decode((string) $row->getMetaValue(), true);
        $value = is_array($value) ? $value : [];
        $staff = is_array($value['staff'] ?? null) ? array_map('intval', $value['staff']) : null;
        $until = $value['pinned_until'] ?? null;
        $authorId = (int) $row->getRelId();

        return [
            'id' => $row->getId(),
            'title' => (string) ($value['title'] ?? ''),
            'message' => (string) ($value['message'] ?? ''),
            'staff' => $staff,
            'staff_names' => $staff === null ? null : array_map(static fn (int $id): string => (string) ($names[$id] ?? '#' . $id), $staff),
            'pinned_until' => $until,
            'pinned' => is_string($until) && $until >= date('Y-m-d'),
            'author_id' => $authorId,
            'author' => (string) ($names[$authorId] ?? ''),
            'created_at' => $row->getCreatedAt()?->format('Y-m-d H:i:s'),
        ];
    }

    public function get(int $id): ExtensionMeta
    {
        $meta = $this->getExtensionMetaRepository()->findOneByExtensionAndId('mod_notification', $id);
        if (!$meta instanceof ExtensionMeta || $meta->getMetaKey() !== self::KEY) {
            throw new InformationException('Announcement not found');
        }

        return $meta;
    }

    public function create(\Model_Admin $author, array $data): int
    {
        $title = trim((string) ($data['title'] ?? ''));
        $message = trim(str_replace("\r\n", "\n", (string) ($data['message'] ?? '')));
        if ($title === '' || mb_strlen($title) > 120) {
            throw new InformationException('Enter a title of up to 120 characters.');
        }
        if ($message === '' || mb_strlen($message) > 2000) {
            throw new InformationException('Enter a message of up to 2000 characters.');
        }

        $readers = $this->readers();
        $staff = null;
        if (($data['audience'] ?? 'all') !== 'all') {
            $staff = array_values(array_unique(array_map('intval', (array) ($data['staff'] ?? []))));
            if ($staff === [] || array_diff($staff, array_keys($readers)) !== []) {
                throw new InformationException('Choose the staff members who get the announcement.');
            }
        }

        $until = trim((string) ($data['pinned_until'] ?? ''));
        if ($until !== '') {
            $date = \DateTime::createFromFormat('!Y-m-d', $until);
            if ($date === false || $date->format('Y-m-d') !== $until || $until < date('Y-m-d')) {
                throw new InformationException('Choose a pin date from today on.');
            }
        }

        $meta = (new ExtensionMeta())
            ->setExtension('mod_notification')
            ->setRelType('staff')
            ->setRelId((int) $author->id)
            ->setMetaKey(self::KEY)
            ->setMetaValue((string) json_encode([
                'title' => $title,
                'message' => $message,
                'staff' => $staff,
                'pinned_until' => $until === '' ? null : $until,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->di['em']->persist($meta);
        $this->di['em']->flush();

        $id = $meta->getId();
        if ($id === null) {
            throw new \ClientFence\Exception('Failed to create notification message: missing ID after persistence.');
        }
        $this->di['events_manager']->fire(['event' => 'onAfterAdminNotificationAdd', 'params' => ['id' => $id]]);

        $recipients = array_diff($staff ?? array_keys($readers), [(int) $author->id]);
        Notifications::staff($this->di, 'announcement', ['title' => $title], 'notification#announcement-' . $id, 'notification', array_values($recipients));

        return $id;
    }

    public function unpin(int $id): bool
    {
        $meta = $this->get($id);
        $value = (array) json_decode((string) $meta->getMetaValue(), true);
        $value['pinned_until'] = null;
        $meta->setMetaValue((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->di['em']->flush();

        return true;
    }

    public function delete(int $id): bool
    {
        $meta = $this->get($id);
        $this->di['em']->remove($meta);
        $this->di['em']->flush();

        return true;
    }

    public function deleteAll(): bool
    {
        $this->getExtensionMetaRepository()->deleteByExtensionAndScope('mod_notification', self::KEY);

        return true;
    }
}
