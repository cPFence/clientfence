<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

// ClientFence core patch (ext notification): staff announcements; "manage" posts, unpins, deletes and sees all, "view" reads those addressed to the staff member.

namespace ClientFence\Mod\Notification\Api;

use ClientFence\Validation\Api\RequiredParams;

class Admin extends \ClientFence\Api\AbstractApi
{
    public function get_list($data): array
    {
        $this->checkPermissions('notification', 'view');

        return $this->getService()->getList($this->getIdentity(), $this->canManage());
    }

    /**
     * Get notification message.
     *
     * @return array
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Notification ID was not passed'])]
    public function get($data): array
    {
        $this->checkPermissions('notification', 'view');

        foreach ($this->get_list([]) as $item) {
            if ($item['id'] === (int) $data['id']) {
                return $item;
            }
        }

        throw new \ClientFence\InformationException('Announcement not found');
    }

    public function get_pinned(): array
    {
        $this->checkPermissions('notification', 'view');

        return $this->getService()->getPinned($this->getIdentity());
    }

    public function get_staff(): array
    {
        $this->checkPermissions('notification', 'manage');

        $staff = [];
        foreach ($this->getService()->readers() as $id => $name) {
            $staff[] = ['id' => $id, 'name' => $name];
        }

        return $staff;
    }

    public function add($data): int
    {
        $this->checkPermissions('notification', 'manage');

        return $this->getService()->create($this->getIdentity(), $data);
    }

    #[RequiredParams(['id' => 'Notification ID was not passed'])]
    public function unpin($data): bool
    {
        $this->checkPermissions('notification', 'manage');

        return $this->getService()->unpin((int) $data['id']);
    }

    /**
     * Remove notification message.
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Notification ID was not passed'])]
    public function delete($data): bool
    {
        $this->checkPermissions('notification', 'manage');

        return $this->getService()->delete((int) $data['id']);
    }

    /**
     * Remove all notification messages.
     *
     * @throws \ClientFence\Exception
     */
    public function delete_all(): bool
    {
        $this->checkPermissions('notification', 'manage');

        return $this->getService()->deleteAll();
    }

    private function canManage(): bool
    {
        return $this->getDi()['mod_service']('Staff')->hasPermission($this->getIdentity(), 'notification', 'manage');
    }
}
