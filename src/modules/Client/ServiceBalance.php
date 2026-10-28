<?php

declare(strict_types=1);

namespace ClientFence\Mod\Client;

use ClientFence\Mod\Client\Entity\Client;
use ClientFence\Mod\Client\Entity\ClientBalance;
use ClientFence\Mod\Client\Repository\ClientBalanceRepository;
use ClientFence\Mod\Client\Repository\ClientRepository;
use Doctrine\ORM\QueryBuilder;
use ClientFence\InjectionAwareInterface;

class ServiceBalance implements InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;
    private ClientBalanceRepository $clientBalanceRepository;
    private ClientRepository $clientRepository;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
        $this->clientBalanceRepository = $di['em']->getRepository(ClientBalance::class);
        $this->clientRepository = $di['em']->getRepository(Client::class);
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function getClientBalance(Client|\Model_Client $c): float
    {
        return $this->clientTotal($c);
    }

    public function clientTotal(Client|\Model_Client $c): float
    {
        $clientId = $c instanceof Client ? $c->getId() : $c->id;

        return $this->clientBalanceRepository->getClientBalanceSum((int) $clientId);
    }

    public function rmByClient(Client|\Model_Client $client): void
    {
        $clientId = $client instanceof Client ? $client->getId() : $client->id;
        $balances = $this->clientBalanceRepository->findBy(['clientId' => (int) $clientId]);
        foreach ($balances as $balance) {
            $this->di['em']->remove($balance);
        }
        if (!empty($balances)) {
            $this->di['em']->flush();
        }
    }

    public function rm(ClientBalance $model): void
    {
        $this->di['em']->remove($model);
        $this->di['em']->flush();
    }

    public function toApiArray(ClientBalance $model, ?Client $client = null): array
    {
        $clientId = $model->getClientId();
        $client ??= $clientId !== null ? $this->clientRepository->find($clientId) : null;
        if (!$client instanceof Client) {
            throw new \ClientFence\InformationException('Client not found');
        }

        return [
            'id' => $model->getId(),
            'description' => $model->getDescription(),
            'amount' => $model->getAmount(),
            'currency' => $client->getCurrency(),
            'created_at' => $model->getCreatedAt()?->format('Y-m-d H:i:s'),
        ];
    }

    public function getSearchQueryBuilder(array $data = []): QueryBuilder
    {
        $queryBuilder = $this->clientBalanceRepository->createQueryBuilder('m');

        $id = $data['id'] ?? null;
        $clientId = $data['client_id'] ?? null;
        $dateFrom = $data['date_from'] ?? null;
        $dateTo = $data['date_to'] ?? null;

        if ($id !== null && $id !== '') {
            $queryBuilder->andWhere('m.id = :id')
                ->setParameter('id', $id);
        }

        if ($clientId !== null && $clientId !== '') {
            $queryBuilder->andWhere('m.clientId = :client_id')
                ->setParameter('client_id', $clientId);
        }

        if ($dateFrom !== null && $dateFrom !== '') {
            $queryBuilder->andWhere('m.createdAt >= :date_from')
                ->setParameter('date_from', new \DateTimeImmutable(date('Y-m-d H:i:s', strtotime((string) $dateFrom))));
        }

        if ($dateTo !== null && $dateTo !== '') {
            $queryBuilder->andWhere('m.createdAt <= :date_to')
                ->setParameter('date_to', new \DateTimeImmutable(date('Y-m-d H:i:s', strtotime((string) $dateTo))));
        }

        return $queryBuilder->orderBy('m.id', 'DESC');
    }

    public function getSearchQuery($data): array
    {
        $q = 'SELECT m.*, c.currency  as currency
              FROM client_balance as m
                LEFT JOIN client as c on c.id = m.client_id';

        $id = $data['id'] ?? null;
        $client_id = $data['client_id'] ?? null;
        $date_from = $data['date_from'] ?? null;
        $date_to = $data['date_to'] ?? null;

        $where = [];
        $params = [];

        if ($id !== null) {
            $where[] = 'm.id = :id';
            $params[':id'] = $id;
        }

        if ($client_id !== null) {
            $where[] = 'm.client_id = :client_id';
            $params[':client_id'] = $client_id;
        }

        if ($date_from !== null) {
            $where[] = 'm.created_at >= :date_from';
            $params[':date_from'] = strtotime($date_from);
        }

        if ($date_to !== null) {
            $where[] = 'm.created_at <= :date_to';
            $params[':date_to'] = strtotime($date_to);
        }

        if (!empty($where)) {
            $q .= ' WHERE ' . implode(' AND ', $where);
        }
        $q .= ' ORDER by m.id DESC';

        return [$q, $params];
    }

    // ClientFence core patch: staff deductions share the payment lock so the same credit cannot be spent twice.
    public function deductByAdmin(Client $client, mixed $amount, string $description): void
    {
        if (!is_numeric($amount) || !is_finite((float) $amount) || round((float) $amount, 2) <= 0) {
            throw new \ClientFence\InformationException('Funds amount is invalid');
        }
        $amount = round((float) $amount, 2);
        \ClientFence\Tools::withLock($this->di, 'fb:credit_payment:client:' . $client->getId(), function () use ($client, $amount, $description): void {
            if ($amount > $this->clientTotal($client)) {
                throw new \ClientFence\InformationException('Insufficient credit balance');
            }
            $this->deductFunds($client, $amount, $description);
        });
    }

    /**
     * @param float|string $amount
     *
     * @throws \ClientFence\InformationException
     */
    public function deductFunds(Client|\Model_Client $client, $amount, $description, ?array $data = null): ClientBalance
    {
        if (!is_numeric($amount)) {
            throw new \ClientFence\InformationException('Funds amount is invalid');
        }

        if (strlen(trim($description)) == 0) {
            throw new \ClientFence\InformationException('Funds description is invalid');
        }

        $credit = new ClientBalance();
        $clientId = $client instanceof Client ? $client->getId() : $client->id;
        $credit->setClientId((int) $clientId);
        $credit->setType($data['type'] ?? 'default');
        $credit->setRelId(isset($data['rel_id']) ? (string) $data['rel_id'] : null);
        $credit->setDescription($description);
        $credit->setAmount((string) (-(float) $amount));

        $this->di['em']->persist($credit);
        $this->di['em']->flush();

        return $credit;
    }
}
