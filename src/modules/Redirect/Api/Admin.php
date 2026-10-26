<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Redirect\Api;

use ClientFence\Validation\Api\RequiredParams;

/**
 * Redirects management.
 */
class Admin extends \ClientFence\Api\AbstractApi
{
    /**
     * Get list of redirects.
     *
     * @return array - list
     */
    public function get_list()
    {
        $this->checkPermissions('redirect', 'view');

        return $this->getService()->getRedirects();
    }

    /**
     * Get redirect by id.
     */
    #[RequiredParams(['id' => 'Redirect ID was not passed'])]
    public function get($data): array
    {
        $this->checkPermissions('redirect', 'view');

        return $this->getService()->toApiArray($this->getService()->get((int) $data['id']));
    }

    /**
     * Create new redirect.
     *
     * @optional int $type - 301 (default) or 302
     * @optional bool $enabled - default true
     *
     * @return int redirect id
     */
    #[RequiredParams(['path' => 'Redirect path was not passed', 'target' => 'Redirect target was not passed'])]
    public function create($data): int
    {
        $this->checkPermissions('redirect', 'create_and_edit');

        $id = $this->getService()->create(
            (string) $data['path'],
            (string) $data['target'],
            $data['type'] ?? 301,
            $data['enabled'] ?? true
        );

        $this->getDi()['logger']->info('Created new redirect #%s', $id);

        return $id;
    }

    /**
     * Update redirect.
     *
     * @optional string $path - redirect path
     * @optional string $target - redirect target
     * @optional int $type - 301 or 302
     * @optional bool $enabled - whether the redirect is applied
     *
     * @return true
     */
    #[RequiredParams(['id' => 'Redirect ID was not passed'])]
    public function update($data): bool
    {
        $this->checkPermissions('redirect', 'create_and_edit');

        $this->getService()->update($this->getService()->get((int) $data['id']), $data);

        $this->getDi()['logger']->info('Updated redirect #%s', $data['id']);

        return true;
    }

    /**
     * Delete redirect.
     *
     * @return true
     */
    #[RequiredParams(['id' => 'Redirect ID was not passed'])]
    public function delete($data): bool
    {
        $this->checkPermissions('redirect', 'delete');

        $this->getService()->delete($this->getService()->get((int) $data['id']));

        $this->getDi()['logger']->info('Removed redirect #%s', $data['id']);

        return true;
    }
}
