<?php

declare(strict_types=1);
/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Email\Repository;

use ClientFence\Mod\Email\Entity\QueuedEmail;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;

class QueuedEmailRepository extends EntityRepository
{
    // ClientFence: minutes after which an email still 'sending' counts as cut off (Health counts it too).
    public const SENDING_STALE_MINUTES = 30;

    /**
     * Build a QueryBuilder for the email queue listing (admin UI).
     */
    public function getSearchQueryBuilder(array $data = []): QueryBuilder
    {
        $qb = $this->createQueryBuilder('q')
            ->orderBy('q.priority', 'DESC')
            ->addOrderBy('q.id', 'ASC');

        if (!empty($data['status'])) {
            $qb->andWhere('q.status = :status')
                ->setParameter('status', $data['status']);
        }

        if (!empty($data['client_id'])) {
            $qb->andWhere('q.clientId = :client_id')
                ->setParameter('client_id', (int) $data['client_id']);
        }

        return $qb;
    }

    /**
     * Return the batch of pending queue items the cron should attempt to send.
     *
     * Items are ordered by priority (highest first) and by ID (FIFO within a
     * priority bucket). Includes legacy `unsent`, `pending`, and `failed`
     * status so that transient failures and pre-migration rows get attempted.
     *
     * @return QueuedEmail[]
     */
    public function findDueBatch(int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('q')
            // ClientFence core patch: an email left 'sending' by a process that died is retried (a rare duplicate beats a lost email).
            ->andWhere("q.status IN (:statuses) OR (q.status = 'sending' AND q.updatedAt < :stale)")
            ->setParameter('statuses', [QueuedEmail::STATUS_UNSENT, QueuedEmail::STATUS_PENDING, QueuedEmail::STATUS_FAILED])
            ->setParameter('stale', new \DateTime('-' . self::SENDING_STALE_MINUTES . ' minutes'))
            ->orderBy('q.priority', 'DESC')
            ->addOrderBy('q.id', 'ASC');

        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }
}
