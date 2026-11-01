<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use function Tests\Helpers\container;
use function Tests\Helpers\createEntity;
use function Tests\Helpers\moduleService;

test('getDi returns dependency injection container', function (): void {
    $api = apiEndpoint(new ClientFence\Mod\Client\Api\Client());
    $di = container();
    $api->setDi($di);
    $getDi = $api->getDi();
    expect($getDi)->toEqual($di);
});

test('balanceGetList returns array', function (): void {
    $api = apiEndpoint(new ClientFence\Mod\Client\Api\Client());
    $data = [];

    $client = createEntity(ClientFence\Mod\Client\Entity\Client::class, ['id' => 1]);
    $balance = createEntity(ClientFence\Mod\Client\Entity\ClientBalance::class, [
        'id' => 1,
        'client_id' => $client->getId(),
    ]);
    $queryBuilder = Mockery::mock(Doctrine\ORM\QueryBuilder::class);

    $serviceMock = Mockery::mock(ClientFence\Mod\Client\ServiceBalance::class);
    $serviceMock
    ->shouldReceive('getSearchQueryBuilder')
    ->once()
    ->with(Mockery::on(static fn (array $query): bool => $query['client_id'] === $client->getId()))
    ->andReturn($queryBuilder);
    $serviceMock
    ->shouldReceive('toApiArray')
    ->once()
    ->with($balance, $client)
    ->andReturn([]);

    $pagerMock = Mockery::mock(ClientFence\Pagination::class)->makePartial();
    $pagerMock
    ->shouldReceive('paginateMappedQuery')
    ->once()
    ->andReturnUsing(static function (Doctrine\ORM\QueryBuilder $query, ClientFence\PaginationOptions $pagination, callable $mapper) use ($queryBuilder, $balance): array {
        expect($query)->toBe($queryBuilder)
            ->and($pagination)->toBeInstanceOf(ClientFence\PaginationOptions::class);

        return ['list' => [$mapper($balance)]];
    });

    $di = container();
    $di['mod_service'] = $di->protect(moduleService(['client:balance' => $serviceMock]));
    $di['pager'] = $pagerMock;

    $api->setDi($di);
    $api->setService($serviceMock);
    $api->setIdentity($client);

    $result = $api->balance_get_list($data);

    expect($result)->toBeArray();
});

test('balanceGetTotal returns float', function (): void {
    $api = apiEndpoint(new ClientFence\Mod\Client\Api\Client());
    $balanceAmount = 0.00;
    $model = createEntity(ClientFence\Mod\Client\Entity\Client::class);

    // The spendable credit: the Invoice module leaves out what is set aside for unpaid invoices.
    $serviceMock = Mockery::mock(ClientFence\Mod\Invoice\Service::class);
    $serviceMock
    ->shouldReceive('getFreeCredit')
    ->once()
    ->andReturn($balanceAmount);

    $di = container();
    $di['mod_service'] = $di->protect(moduleService(['invoice' => $serviceMock]));

    $api->setDi($di);
    $api->setIdentity($model);

    $result = $api->balance_get_total();

    expect($result)->toBeFloat();
    expect($result)->toEqual($balanceAmount);
});

test('isTaxable returns boolean', function (): void {
    $api = apiEndpoint(new ClientFence\Mod\Client\Api\Client());
    $clientIsTaxable = true;

    $serviceMock = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $serviceMock
    ->shouldReceive('isClientTaxable')
    ->atLeast()->once()
    ->andReturn($clientIsTaxable);

    $client = createEntity(ClientFence\Mod\Client\Entity\Client::class);

    $api->setService($serviceMock);
    $api->setIdentity($client);

    $result = $api->is_taxable();
    expect($result)->toBeBool();
    expect($result)->toEqual($clientIsTaxable);
});
