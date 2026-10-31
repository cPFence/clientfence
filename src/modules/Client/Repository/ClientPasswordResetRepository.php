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
use ClientFence\Mod\Client\Entity\ClientPasswordReset;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityRepository;
use ClientFence\Doctrine\RowLock;
use ClientFence\InformationException;

class ClientPasswordResetRepository extends EntityRepository
{
    public function changePassword(Client $client, string $passwordHash, ?string $resetHash = null): void
    {
        $em = $this->getEntityManager();
        $em->wrapInTransaction(function () use ($em, $client, $passwordHash, $resetHash): void {
            $connection = $em->getConnection();
            $clientId = (int) $client->getId();
            $status = $this->lockClient($clientId);

            if ($resetHash !== null) {
                // ClientFence core patch (0.8.9 port): a previously loaded token may have been revoked while waiting for the identity lock.
                $reset = $connection->fetchAssociative(
                    'SELECT created_at FROM client_password_reset WHERE client_id = :id AND hash = :hash' . RowLock::suffix($connection),
                    ['id' => $clientId, 'hash' => $resetHash],
                );
                if ($status !== Client::ACTIVE || $reset === false || strtotime((string) $reset['created_at']) + 900 < time()) {
                    throw new InformationException('The link has expired or you have already reset your password.');
                }
            }

            $this->deleteResetsForClient($clientId);
            $client->setPass($passwordHash);
            $em->persist($client);
        });
    }

    public function createRequest(Client $client, string $hash, ?string $ip): void
    {
        $em = $this->getEntityManager();
        $em->wrapInTransaction(function () use ($em, $client, $hash, $ip): void {
            $clientId = (int) $client->getId();
            $this->lockClient($clientId);
            $this->deleteResetsForClient($clientId);

            $reset = (new ClientPasswordReset())
                ->setClientId($clientId)
                ->setHash($hash)
                ->setIp($ip);
            $em->persist($reset);
        });
    }

    // ClientFence core patch: email changes revoke the same reset records as password changes.
    public function deleteResetsForClient(int $clientId): int
    {
        return (int) $this->getEntityManager()->getConnection()->delete('client_password_reset', ['client_id' => $clientId]);
    }

    private function lockClient(int $clientId): mixed
    {
        $connection = $this->getEntityManager()->getConnection();
        if ($connection->getDatabasePlatform() instanceof SQLitePlatform) {
            // ClientFence core patch (0.8.9 port): A deferred SQLite transaction needs a write before it can serialize readers.
            $connection->executeStatement('UPDATE client SET updated_at = updated_at WHERE id = :id', ['id' => $clientId]);
        }

        return $connection->fetchOne('SELECT status FROM client WHERE id = :id' . RowLock::suffix($connection), ['id' => $clientId]);
    }

    /**
     * @return list<ClientPasswordReset>
     */
    public function findExpiredBefore(\DateTimeInterface $cutoff): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
    }

    public function findOneByHash(string $hash): ?ClientPasswordReset
    {
        $reset = $this->findOneBy(['hash' => $hash]);

        return $reset instanceof ClientPasswordReset ? $reset : null;
    }
}
