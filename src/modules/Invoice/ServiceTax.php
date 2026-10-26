<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Invoice;

use ClientFence\InjectionAwareInterface;

class ServiceTax implements InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function getTaxRateForClient(\Model_Client $model, &$title = null)
    {
        $clientService = $this->di['mod_service']('client');
        if (!$clientService->isClientTaxable($model)) {
            return 0;
        }

        $tax = $this->di['db']->findOne('Tax', 'state = ? and country = ?', [$model->state, $model->country]);
        // find rate which matches clients country and state

        if ($tax instanceof \Model_Tax) {
            $title = $tax->name;

            return $tax->taxrate;
        }

        // find rate which matches clients country
        // ClientFence core patch: only a rule for the whole country (no state); a state's rule is not charged in other states.
        $tax = $this->di['db']->findOne('Tax', 'country = ? and (state is NULL or state = "")', [$model->country]);
        if ($tax instanceof \Model_Tax) {
            $title = $tax->name;

            return $tax->taxrate;
        }

        // find global rate
        $tax = $this->di['db']->findOne('Tax', '(state is NULL or state = "") and (country is null or country = "")');
        if ($tax instanceof \Model_Tax) {
            $title = $tax->name;

            return $tax->taxrate;
        }

        return 0;
    }

    public function getTax(\Model_Invoice $invoice)
    {
        if ($invoice->taxrate <= 0) {
            return 0;
        }

        $tax = 0;
        $invoiceItems = $this->di['db']->find('InvoiceItem', 'invoice_id = ?', [$invoice->id]);
        $invoiceItemService = $this->di['mod_service']('Invoice', 'InvoiceItem');
        foreach ($invoiceItems as $item) {
            $tax += $invoiceItemService->getTax($item) * $item->quantity;
        }

        return $tax;
    }

    public function delete(\Model_Tax $model): bool
    {
        $name = $model->name;
        $this->di['db']->trash($model);
        $this->di['logger']->info('Deleted tax rule %s', $name);

        return true;
    }

    /** ClientFence: a tax rate from 0 to 100, a decimal comma read as a point ("14,5" was shown as 14,5% and charged 14%). */
    public static function validRate(mixed $rate): string
    {
        $rate = str_replace(',', '.', trim((string) $rate));
        if (!is_numeric($rate) || (float) $rate < 0 || (float) $rate > 100) {
            throw new \ClientFence\InformationException('The tax rate must be a number from 0 to 100.');
        }

        return rtrim(rtrim(number_format((float) $rate, 4, '.', ''), '0'), '.');
    }

    public function create(array $data)
    {
        $model = $this->di['db']->dispense('Tax');
        $model->name = $data['name'];
        $model->country = (!isset($data['country']) || empty($data['country'])) ? null : $data['country'];
        $model->state = (!isset($data['state']) || empty($data['state'])) ? null : $data['state'];
        $model->taxrate = self::validRate($data['taxrate'] ?? null);
        $model->created_at = date('Y-m-d H:i:s');
        $model->updated_at = date('Y-m-d H:i:s');
        $newId = $this->di['db']->store($model);

        $this->di['logger']->info('Created new tax rule %s', $model->name);

        return $newId;
    }

    public function update(\Model_Tax $model, array $data): bool
    {
        $model->name = $data['name'];
        $model->country = (!isset($data['country']) || empty($data['country'])) ? null : $data['country'];
        $model->state = (!isset($data['state']) || empty($data['state'])) ? null : $data['state'];
        $model->taxrate = self::validRate($data['taxrate'] ?? null);
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);

        $this->di['logger']->info('Updated tax rule %s', $model->name);

        return true;
    }

    public function getSearchQuery($data): array
    {
        $sql = 'SELECT *
            FROM tax
            ORDER BY id desc';

        return [$sql, []];
    }

    public function toApiArray(\Model_Tax $model, $deep = false, $identity = null)
    {
        return $this->di['db']->toArray($model);
    }
}
