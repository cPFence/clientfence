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

test('gets dependency injection container', function (): void {
    $service = new ClientFence\Mod\Stats\Service();
    $di = container();
    $service->setDi($di);
    $getDi = $service->getDi();
    expect($getDi)->toBe($di);
});

test('gets order statuses', function (): void {
    $service = new ClientFence\Mod\Stats\Service();
    $orderServiceMock = Mockery::mock(ClientFence\Mod\Order\Service::class);
    /** @var Mockery\Expectation $expectation */
    $expectation = $orderServiceMock->shouldReceive('counter');
    $expectation->atLeast()->once();
    $expectation->andReturn([]);

    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $orderServiceMock);

    $service->setDi($di);

    $result = $service->getOrdersStatuses([]);
    expect($result)->toBeArray();
});

test('gets product summary', function (): void {
    $service = new ClientFence\Mod\Stats\Service();
    $data = [];

    $resultMock = Mockery::mock(Doctrine\DBAL\Result::class);
    /** @var Mockery\Expectation $expectation1 */
    $expectation1 = $resultMock->shouldReceive('fetchAllAssociative');
    $expectation1->atLeast()->once();
    $expectation1->andReturn([]);

    $dbalMock = Mockery::mock(Doctrine\DBAL\Connection::class);
    /** @var Mockery\Expectation $expectation2 */
    $expectation2 = $dbalMock->shouldReceive('executeQuery');
    $expectation2->atLeast()->once();
    $expectation2->andReturn($resultMock);

    $di = container();
    $di['dbal'] = $dbalMock;

    $service->setDi($di);
    $result = $service->getProductSummary($data);
    expect($result)->toBeArray();
});

test('gets summary', function (): void {
    $service = new ClientFence\Mod\Stats\Service();
    $resultMock = Mockery::mock(Doctrine\DBAL\Result::class);
    /** @var Mockery\Expectation $expectation1 */
    $expectation1 = $resultMock->shouldReceive('fetchOne');
    $expectation1->atLeast()->once();
    $expectation1->andReturn(null);

    $dbalMock = Mockery::mock(Doctrine\DBAL\Connection::class);
    /** @var Mockery\Expectation $expectation2 */
    $expectation2 = $dbalMock->shouldReceive('executeQuery');
    $expectation2->atLeast()->once();
    $expectation2->andReturn($resultMock);

    $di = container();
    $di['dbal'] = $dbalMock;
    $service->setDi($di);

    $expected = [
        'clients_total' => null,
        'clients_today' => null,
        'clients_yesterday' => null,
        'clients_this_month' => null,
        'clients_last_month' => null,

        'orders_total' => null,
        'orders_today' => null,
        'orders_yesterday' => null,
        'orders_this_month' => null,
        'orders_last_month' => null,

        'invoices_total' => null,
        'invoices_today' => null,
        'invoices_yesterday' => null,
        'invoices_this_month' => null,
        'invoices_last_month' => null,

        'tickets_total' => null,
        'tickets_today' => null,
        'tickets_yesterday' => null,
        'tickets_this_month' => null,
        'tickets_last_month' => null,
    ];

    $result = $service->getSummary();
    expect($result)->toBeArray()
        ->and($result)->toBe($expected);
});

test('gets summary income', function (): void {
    $service = new ClientFence\Mod\Stats\Service();
    $resultMock = Mockery::mock(Doctrine\DBAL\Result::class);
    /** @var Mockery\Expectation $expectation1 */
    $expectation1 = $resultMock->shouldReceive('fetchOne');
    $expectation1->atLeast()->once();
    $expectation1->andReturn(null);

    $dbalMock = Mockery::mock(Doctrine\DBAL\Connection::class);
    /** @var Mockery\Expectation $expectation2 */
    $expectation2 = $dbalMock->shouldReceive('executeQuery');
    $expectation2->atLeast()->once();
    $expectation2->andReturn($resultMock);

    $di = container();
    $di['dbal'] = $dbalMock;
    $service->setDi($di);

    $expected = [
        'total' => null,
        'today' => null,
        'yesterday' => null,
        'this_month' => null,
        'last_month' => null,
    ];

    $result = $service->getSummaryIncome();
    expect($result)->toBeArray()
        ->and($result)->toBe($expected);
});

test('gets product sales', function (): void {
    $service = new ClientFence\Mod\Stats\Service();
    $res = ['testProduct' => 1];
    $resultMock = Mockery::mock(Doctrine\DBAL\Result::class);
    /** @var Mockery\Expectation $expectation1 */
    $expectation1 = $resultMock->shouldReceive('fetchAllKeyValue');
    $expectation1->atLeast()->once();
    $expectation1->andReturn($res);

    $dbalMock = Mockery::mock(Doctrine\DBAL\Connection::class);
    /** @var Mockery\Expectation $expectation2 */
    $expectation2 = $dbalMock->shouldReceive('executeQuery');
    $expectation2->atLeast()->once();
    $expectation2->andReturn($resultMock);

    $di = container();
    $di['dbal'] = $dbalMock;
    $service->setDi($di);

    $data = [
        'date_from' => 'yesterday',
        'date_to' => 'now',
    ];
    $result = $service->getProductSales($data);
    expect($result)->toBeArray();
});

test('gets income and refund stats', function (): void {
    $service = new ClientFence\Mod\Stats\Service();
    $res = [
        [
            'refund' => 0,
            'income' => 0,
        ],
    ];
    $resultMock = Mockery::mock(Doctrine\DBAL\Result::class);
    /** @var Mockery\Expectation $expectation1 */
    $expectation1 = $resultMock->shouldReceive('fetchAllAssociative');
    $expectation1->atLeast()->once();
    $expectation1->andReturn($res);

    $dbalMock = Mockery::mock(Doctrine\DBAL\Connection::class);
    /** @var Mockery\Expectation $expectation2 */
    $expectation2 = $dbalMock->shouldReceive('executeQuery');
    $expectation2->atLeast()->once();
    $expectation2->andReturn($resultMock);

    $di = container();
    $di['dbal'] = $dbalMock;
    $service->setDi($di);

    $result = $service->incomeAndRefundStats([]);
    expect($result)->toBeArray()
        ->and($result)->toBe($res[0]);
});

test('gets refunds', function (): void {
    $service = new ClientFence\Mod\Stats\Service();
    $resultMock = Mockery::mock(Doctrine\DBAL\Result::class);
    /** @var Mockery\Expectation $expectation1 */
    $expectation1 = $resultMock->shouldReceive('fetchAllKeyValue');
    $expectation1->atLeast()->once();
    $expectation1->andReturn([]);

    $dbalMock = Mockery::mock(Doctrine\DBAL\Connection::class);
    /** @var Mockery\Expectation $expectation2 */
    $expectation2 = $dbalMock->shouldReceive('executeQuery');
    $expectation2->atLeast()->once();
    $expectation2->andReturn($resultMock);

    $di = container();
    $di['dbal'] = $dbalMock;

    $service->setDi($di);

    $data = [
        'date_from' => 'yesterday',
        'date_to' => 'now',
    ];
    $result = $service->getRefunds($data);
    expect($result)->toBeArray();
});

test('gets income', function (): void {
    $service = new ClientFence\Mod\Stats\Service();
    $resultMock = Mockery::mock(Doctrine\DBAL\Result::class);
    /** @var Mockery\Expectation $expectation1 */
    $expectation1 = $resultMock->shouldReceive('fetchAllKeyValue');
    $expectation1->atLeast()->once();
    $expectation1->andReturn([]);

    $dbalMock = Mockery::mock(Doctrine\DBAL\Connection::class);
    /** @var Mockery\Expectation $expectation2 */
    $expectation2 = $dbalMock->shouldReceive('executeQuery');
    $expectation2->atLeast()->once();
    $expectation2->andReturn($resultMock);

    $di = container();
    $di['dbal'] = $dbalMock;

    $service->setDi($di);

    $data = [
        'date_from' => 'yesterday',
        'date_to' => 'now',
    ];
    $result = $service->getIncome($data);
    expect($result)->toBeArray();
});

test('gets client countries', function (): void {
    $service = new ClientFence\Mod\Stats\Service();
    $resultMock = Mockery::mock(Doctrine\DBAL\Result::class);
    /** @var Mockery\Expectation $expectation1 */
    $expectation1 = $resultMock->shouldReceive('fetchAllKeyValue');
    $expectation1->atLeast()->once();
    $expectation1->andReturn([]);

    $dbalMock = Mockery::mock(Doctrine\DBAL\Connection::class);
    /** @var Mockery\Expectation $expectation2 */
    $expectation2 = $dbalMock->shouldReceive('executeQuery');
    $expectation2->atLeast()->once();
    $expectation2->andReturn($resultMock);

    $di = container();
    $di['dbal'] = $dbalMock;

    $service->setDi($di);

    $result = $service->getClientCountries([]);
    expect($result)->toBeArray();
});

test('gets sales by country', function (): void {
    $service = new ClientFence\Mod\Stats\Service();
    $resultMock = Mockery::mock(Doctrine\DBAL\Result::class);
    /** @var Mockery\Expectation $expectation1 */
    $expectation1 = $resultMock->shouldReceive('fetchAllKeyValue');
    $expectation1->atLeast()->once();
    $expectation1->andReturn([]);

    $dbalMock = Mockery::mock(Doctrine\DBAL\Connection::class);
    /** @var Mockery\Expectation $expectation2 */
    $expectation2 = $dbalMock->shouldReceive('executeQuery');
    $expectation2->atLeast()->once();
    $expectation2->andReturn($resultMock);

    $di = container();
    $di['dbal'] = $dbalMock;

    $service->setDi($di);

    $result = $service->getSalesByCountry([]);
    expect($result)->toBeArray();
});

test('gets table stats', function (): void {
    $service = new ClientFence\Mod\Stats\Service();
    $resultMock = Mockery::mock(Doctrine\DBAL\Result::class);
    /** @var Mockery\Expectation $expectation1 */
    $expectation1 = $resultMock->shouldReceive('fetchAllKeyValue');
    $expectation1->atLeast()->once();
    $expectation1->andReturn([]);

    $dbalMock = Mockery::mock(Doctrine\DBAL\Connection::class);
    /** @var Mockery\Expectation $expectation2 */
    $expectation2 = $dbalMock->shouldReceive('executeQuery');
    $expectation2->atLeast()->once();
    $expectation2->andReturn($resultMock);

    $di = container();
    $di['dbal'] = $dbalMock;

    $service->setDi($di);

    $data = [
        'date_from' => 'yesterday',
        'date_to' => 'now',
    ];
    $result = $service->getTableStats('client', $data);
    expect($result)->toBeArray();
});

test('a date that cannot be read is refused before any query', function (string $method, array $data): void {
    $service = new ClientFence\Mod\Stats\Service();
    $dbalMock = Mockery::mock(Doctrine\DBAL\Connection::class);
    $dbalMock->shouldNotReceive('executeQuery');
    $di = container();
    $di['dbal'] = $dbalMock;
    $service->setDi($di);

    expect(fn () => $service->{$method}($data))->toThrow(ClientFence\InformationException::class, 'Enter a valid date.');
})->with([
    'income' => ['getIncome', ['date_from' => 'nonsense']],
    'refunds' => ['getRefunds', ['date_to' => 'nonsense']],
    'product sales' => ['getProductSales', ['date_from' => ['2026-01-01']]],
]);

test('a chart shows every day of the range and counts the whole last day', function (): void {
    $result = Mockery::mock(Doctrine\DBAL\Result::class);
    $result->shouldReceive('fetchAllKeyValue')->andReturn(['2026-11-01' => '10.5', '2026-11-03' => '4']);
    $dbal = Mockery::mock(Doctrine\DBAL\Connection::class);
    $dbal->shouldReceive('executeQuery')->once()
        ->withArgs(fn (string $sql, array $params): bool => $params['date_from'] === '2026-11-01' && $params['date_to'] === '2026-11-03 23:59:59')
        ->andReturn($result);
    $di = container();
    $di['dbal'] = $dbal;
    $service = new ClientFence\Mod\Stats\Service();
    $service->setDi($di);

    $points = $service->getIncome(['date_from' => '2026-11-01', 'date_to' => '2026-11-03']);

    expect(array_map(fn (array $p): string => date('Y-m-d', intdiv($p[0], 1000)), $points))->toBe(['2026-11-01', '2026-11-02', '2026-11-03'])
        ->and(array_column($points, 1))->toBe([10.5, 0.0, 4.0]);
});

test('a chart range over a midnight clock change keeps its last day', function (): void {
    $zone = date_default_timezone_get();
    date_default_timezone_set('America/Santiago');
    try {
        $result = Mockery::mock(Doctrine\DBAL\Result::class);
        $result->shouldReceive('fetchAllKeyValue')->andReturn([]);
        $dbal = Mockery::mock(Doctrine\DBAL\Connection::class);
        $dbal->shouldReceive('executeQuery')->andReturn($result);
        $di = container();
        $di['dbal'] = $dbal;
        $service = new ClientFence\Mod\Stats\Service();
        $service->setDi($di);

        $points = $service->getIncome(['date_from' => '2026-08-15', 'date_to' => '2026-10-15']);

        expect($points)->toHaveCount(62)
            ->and(date('Y-m-d', intdiv(end($points)[0], 1000)))->toBe('2026-10-15');
    } finally {
        date_default_timezone_set($zone);
    }
});
