<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Custompages\Api;

use ClientFence\Validation\Api\RequiredParams;

class Admin extends \ClientFence\Api\AbstractApi
{
    /**
     * Get paginated list of custom pages.
     *
     * @return array
     */
    public function get_list($data)
    {
        $this->checkPermissions('custompages', 'view');

        $pager = $this->getService()->searchPages($data);

        foreach ($pager['list'] as $key => $item) {
            $pager['list'][$key] = $item;
        }

        return $pager;
    }

    /**
     * Delete custom page.
     */
    #[RequiredParams(['id' => 'Custom page ID was not passed'])]
    public function delete($data): bool
    {
        $this->checkPermissions('custompages', 'manage');

        $this->getService()->deletePage($data['id']);

        return true;
    }

    /**
     * Delete custom pages.
     */
    #[RequiredParams(['ids' => 'Custom page IDs were not passed'])]
    public function batch_delete($data): bool
    {
        $this->checkPermissions('custompages', 'manage');

        $this->getService()->deletePage($data['ids']);

        return true;
    }

    /**
     * Create new custom page.
     *
     * @optional string $description - custom page meta description
     * @optional string $keywords - custom page meta keywords
     *
     * @return array
     */
    #[RequiredParams(['title' => 'Title was not passed', 'content' => 'Content was not passed'])]
    public function create($data)
    {
        $this->checkPermissions('custompages', 'manage');

        return $this->getService()->createPage($data);
    }

    /**
     * Update custom page.
     *
     * @optional string $description - custom page meta description
     * @optional string $keywords - custom page meta keywords
     *
     * @return array
     */
    #[RequiredParams([
        'id' => 'Custom page ID was not passed',
        'title' => 'Custom page title was not passed',
        'slug' => 'Custom page slug was not passed',
        'content' => 'Custom page content was not passed',
    ])]
    public function update($data)
    {
        $this->checkPermissions('custompages', 'manage');

        return $this->getService()->updatePage((int) $data['id'], $data);
    }

    /**
     * Get custom page by id.
     *
     * @return array
     */
    public function get_page($data)
    {
        $this->checkPermissions('custompages', 'view');

        return $this->getService()->getPage($data['page_id']);
    }
}
