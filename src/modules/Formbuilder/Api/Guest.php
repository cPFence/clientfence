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
 * Custom forms.
 */

namespace ClientFence\Mod\Formbuilder\Api;

use ClientFence\Validation\Api\RequiredParams;

class Guest extends \ClientFence\Api\AbstractApi
{
    /**
     * Get custom order form details for product.
     *
     * @return array
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['id' => 'Form ID was not passed'])]
    public function get($data)
    {
        $service = $this->getService();
        $form = $service->getForm((int) $data['id']);
        // ClientFence core patch: clients and guests read each question in the page language (Product/Translations.php); staff pages ask for the default texts.
        if (empty($data['default_texts'])) {
            foreach ($form['fields'] as &$field) {
                $field['label'] = \ClientFence\Mod\Product\Translations::text($this->getDi(), 'field', (int) $field['id'], 'title', $field['label']);
                $field['description'] = \ClientFence\Mod\Product\Translations::text($this->getDi(), 'field', (int) $field['id'], 'description', $field['description']);
            }
            unset($field);
        }

        return $form;
    }
}
