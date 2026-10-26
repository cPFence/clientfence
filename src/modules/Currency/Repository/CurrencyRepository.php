<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Currency\Repository;

use ClientFence\Mod\Currency\Entity\Currency;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Intl\Currencies;

class CurrencyRepository extends EntityRepository
{
    /** @var array<string, Currency>|null ClientFence: byCode() */
    private ?array $byCode = null;

    public function getClientCurrencyCode(int $clientId): ?string
    {
        $currencyCode = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT currency FROM client WHERE id = :client_id',
            ['client_id' => $clientId],
        );

        return is_string($currencyCode) && $currencyCode !== '' ? $currencyCode : null;
    }

    /**
     * Build a QueryBuilder for searching currencies.
     *
     * @param array $data Array of filters
     */
    public function getSearchQueryBuilder(array $data = []): QueryBuilder
    {
        $qb = $this->createQueryBuilder('c');

        if (!empty($data['search'])) {
            $qb->andWhere('c.code LIKE :search')
               ->setParameter('search', '%' . $data['search'] . '%');
        }

        $qb->orderBy('c.code', 'ASC');

        return $qb;
    }

    /**
     * Find a currency by its code.
     */
    public function findOneByCode(string $code): ?Currency
    {
        // ClientFence core patch: from the currencies read once per request (byCode()).
        $all = $this->byCode();

        return $all[$code] ?? $all[strtoupper($code)] ?? null;
    }

    /**
     * Get the default currency.
     *
     * Returns null if no currency is marked as default. Callers should handle
     * this case appropriately (e.g., by throwing an exception or using a fallback).
     *
     * Note: Doctrine's identity map provides automatic caching within a request.
     * If the default currency is changed via `Service::setAsDefault()`, that method
     * clears the identity map to ensure subsequent calls return fresh data.
     */
    public function findDefault(): ?Currency
    {
        foreach ($this->byCode() as $currency) {
            if ($currency->isDefault()) {
                return $currency;
            }
        }

        return null;
    }

    /**
     * ClientFence core patch: every currency by code, read once per request (repositories live as
     * long as the entity manager). A page asked for the same currencies 30-60 times, one query
     * each; the table holds a handful of rows. Service methods that change currencies call
     * forgetCached().
     *
     * @return array<string, Currency>
     */
    private function byCode(): array
    {
        if ($this->byCode === null) {
            $this->byCode = [];
            foreach ($this->findAll() as $currency) {
                $this->byCode[$currency->getCode()] = $currency;
            }
        }

        return $this->byCode;
    }

    public function forgetCached(): void
    {
        $this->byCode = null;
    }

    public function getPairs(): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('c.code')
            ->orderBy('c.code', 'ASC');

        $results = $qb->getQuery()->getResult();

        $pairs = [];
        foreach ($results as $result) {
            $code = $result['code'];
            $pairs[$code] = Currencies::getName($code);
        }

        return $pairs;
    }

    /**
     * Get conversion rate by currency code.
     * Returns the rate as a float for calculations, or null if currency not found.
     *
     * @param string $code Currency code
     *
     * @return float|null The conversion rate as a float, or null if not found
     */
    public function getRateByCode(string $code): ?float
    {
        return $this->findOneByCode($code)?->getConversionRate();
    }

    /**
     * Set all currencies to non-default.
     *
     * @return int Number of affected rows
     */
    public function clearDefaultFlags(): int
    {
        return $this->createQueryBuilder('c')
            ->update()
            ->set('c.isDefault', ':isDefault')
            ->setParameter('isDefault', false)
            ->getQuery()
            ->execute();
    }
}
