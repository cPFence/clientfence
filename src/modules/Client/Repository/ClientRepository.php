<?php

declare(strict_types=1);
/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Client\Repository;

use ClientFence\Mod\Client\Entity\Client;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;

class ClientRepository extends EntityRepository
{
    public function findOneByEmail(string $email): ?Client
    {
        $client = $this->findOneBy(['email' => $email]);

        return $client instanceof Client ? $client : null;
    }

    public function findOneByEmailAndActive(string $email): ?Client
    {
        $client = $this->findOneBy(['email' => $email, 'status' => Client::ACTIVE]);

        return $client instanceof Client ? $client : null;
    }

    public function findOneByApiToken(?string $apiToken): ?Client
    {
        if ($apiToken === null || $apiToken === '') {
            return null;
        }

        $client = $this->findOneBy(['apiToken' => $apiToken]);

        return $client instanceof Client ? $client : null;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<int, string>
     */
    public function getIdNamePairs(array $data = [], int $limit = 30): array
    {
        $clients = $this->getSearchQueryBuilder($data)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $pairs = [];
        foreach ($clients as $client) {
            if (!$client instanceof Client) {
                continue;
            }

            $name = trim(($client->getFirstName() ?? '') . ' ' . ($client->getLastName() ?? ''));
            if ($client->getCompany()) {
                $name .= ' (' . $client->getCompany() . ')';
            }
            $pairs[(int) $client->getId()] = $name;
        }

        return $pairs;
    }

    /** ClientFence core patch: $pinClientId is the client a numeric search names by Support PIN (Client/SupportPin.php). */
    public function getSearchQueryBuilder(array $data = [], ?int $pinClientId = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('c');

        $id = $data['id'] ?? null;
        $name = $data['name'] ?? null;
        $email = $data['email'] ?? null;
        $company = $data['company'] ?? null;
        $status = $data['status'] ?? null;
        $groupId = $data['group_id'] ?? null;
        $createdAt = $data['created_at'] ?? null;
        $dateFrom = $data['date_from'] ?? null;
        $dateTo = $data['date_to'] ?? null;
        $search = $data['search'] ?? null;

        if ($id !== null && $id !== '') {
            $qb->andWhere('(c.id = :id OR c.aid = :id)')
                ->setParameter('id', $id);
        }

        if ($name) {
            $qb->andWhere('(c.firstName LIKE :name OR c.lastName LIKE :name)')
                ->setParameter('name', '%' . $name . '%');
        }

        if ($email) {
            $qb->andWhere('c.email LIKE :email')
                ->setParameter('email', '%' . $email . '%');
        }

        if ($company) {
            $qb->andWhere('c.company LIKE :company')
                ->setParameter('company', '%' . $company . '%');
        }

        if ($status) {
            $qb->andWhere('c.status = :status')
                ->setParameter('status', $status);
        }

        if ($groupId) {
            // ClientFence core patch: every client in the group (Client/Groups.php).
            $members = $this->getEntityManager()->getConnection()->fetchFirstColumn('SELECT client_id FROM client_group_members WHERE client_group_id = ?', [(int) $groupId]);
            $qb->andWhere('c.id IN (:group_members)')
                ->setParameter('group_members', $members ?: [0], ArrayParameterType::INTEGER);
        }

        if ($createdAt) {
            // ClientFence core patch: an unreadable date is refused with a message (with strict types date() got false and threw a TypeError).
            $date = date('Y-m-d', (strtotime((string) $createdAt) ?: throw new \ClientFence\InformationException('Enter a valid date.')));
            $start = new \DateTimeImmutable($date);
            $qb->andWhere('c.createdAt >= :created_from AND c.createdAt < :created_to')
                ->setParameter('created_from', $start)
                ->setParameter('created_to', $start->modify('+1 day'));
        }

        if ($dateFrom) {
            $qb->andWhere('c.createdAt >= :date_from')
                ->setParameter('date_from', new \DateTimeImmutable(date('Y-m-d H:i:s', (strtotime((string) $dateFrom) ?: throw new \ClientFence\InformationException('Enter a valid date.')))));
        }

        if ($dateTo) {
            $qb->andWhere('c.createdAt <= :date_to')
                ->setParameter('date_to', new \DateTimeImmutable(date('Y-m-d H:i:s', (strtotime((string) $dateTo) ?: throw new \ClientFence\InformationException('Enter a valid date.')))));
        }

        if ($search) {
            if (is_numeric($search) && $pinClientId !== null) {
                $qb->andWhere('(c.id = :search_id OR c.aid = :search_id OR c.id = :pin_client_id)')
                    ->setParameter('search_id', $search)
                    ->setParameter('pin_client_id', $pinClientId);
            } elseif (is_numeric($search)) {
                $qb->andWhere('(c.id = :search_id OR c.aid = :search_id)')
                    ->setParameter('search_id', $search);
            } else {
                $search = '%' . $search . '%';
                $qb->andWhere("(c.company LIKE :search OR c.firstName LIKE :search OR c.lastName LIKE :search OR c.email LIKE :search OR CONCAT(CONCAT(c.firstName, ' '), c.lastName) LIKE :search)")
                    ->setParameter('search', $search);
            }
        }

        return $qb->orderBy('c.createdAt', 'DESC');
    }

    /**
     * @param list<int> $clientIds
     *
     * @return array<int, array{balance: float, group: ?string}>
     */
    public function getListContext(array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT c.id, COALESCE(SUM(cb.amount), 0) AS balance
             FROM client c
             LEFT JOIN client_balance cb ON cb.client_id = c.id
             WHERE c.id IN (:ids)
             GROUP BY c.id',
            ['ids' => $clientIds],
            ['ids' => ArrayParameterType::INTEGER],
        );
        // ClientFence core patch: all the client's groups (Client/Groups.php).
        $titles = [];
        foreach ($this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT m.client_id, g.title FROM client_group_members m JOIN client_group g ON g.id = m.client_group_id WHERE m.client_id IN (:ids) ORDER BY g.title',
            ['ids' => $clientIds],
            ['ids' => ArrayParameterType::INTEGER],
        ) as $group) {
            $titles[(int) $group['client_id']][] = (string) $group['title'];
        }

        $context = [];
        foreach ($rows as $row) {
            $context[(int) $row['id']] = [
                'balance' => (float) $row['balance'],
                'group' => isset($titles[(int) $row['id']]) ? implode(', ', $titles[(int) $row['id']]) : null,
            ];
        }

        return $context;
    }

    /**
     * @return array<string, int>
     */
    public function getStatusCounts(): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT status, COUNT(id) AS count FROM client GROUP BY status'
        );

        $counts = ['active' => 0, 'suspended' => 0, 'canceled' => 0];
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['count'];
        }

        return $counts;
    }
}
