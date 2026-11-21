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

        return $this->getLocationTaxRate($model, $title);
    }

    /**
     * ClientFence: the rate of the client's location, also for a client exempt from tax (prices that include tax are
     * reduced by it for them, Invoice\Service::applyInclusiveTax()).
     */
    public function getLocationTaxRate(\Model_Client $model, &$title = null)
    {
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

    /**
     * ClientFence core patch (forum #172): an invoice's tax name as its reader sees it, as WHMCS dynamic translations:
     * the own name in $locale (the page language when null, Product/Translations.php kind `tax`) of the rule with the
     * saved name and rate for the buyer's place (its country and state, its country, or every country, as
     * getLocationTaxRate() picks); else the name saved on the invoice. The invoice keeps the rule's saved name (the accounting sync and reports key on it).
     */
    public function displayName(\Model_Invoice $invoice, ?string $locale = null): string
    {
        $name = (string) $invoice->taxname;
        $rules = $name === '' ? [] : array_filter($this->di['db']->find('Tax'), static fn (\Model_Tax $tax): bool => trim((string) $tax->name) === trim($name) && abs((float) $tax->taxrate - (float) $invoice->taxrate) < 0.00005);
        $fit = static fn (\Model_Tax $tax): int => match (true) {
            $tax->country && $tax->country === $invoice->buyer_country && $tax->state && $tax->state === $invoice->buyer_state => 3,
            $tax->country && $tax->country === $invoice->buyer_country && !$tax->state => 2,
            !$tax->country => 1,
            default => 0,
        };
        // Another country's rule never names this invoice (its own rule was deleted): the saved name then.
        $rules = array_values(array_filter($rules, static fn (\Model_Tax $tax): bool => $fit($tax) > 0));
        usort($rules, static fn (\Model_Tax $a, \Model_Tax $b): int => $fit($b) <=> $fit($a));

        return $rules ? \ClientFence\Mod\Product\Translations::text($this->di, 'tax', (int) $rules[0]->id, 'title', $name, $locale) ?? $name : $name;
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
