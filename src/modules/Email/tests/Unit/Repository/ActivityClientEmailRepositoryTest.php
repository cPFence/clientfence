<?php

declare(strict_types=1);

use ClientFence\Mod\Email\Repository\ActivityClientEmailRepository;
use Doctrine\ORM\QueryBuilder;

test('get search query builder excludes the attachment blob from the select', function (): void {
    $selectCalls = [];

    $queryBuilder = Mockery::mock(QueryBuilder::class);
    $queryBuilder->shouldReceive('select')->once()->andReturnUsing(function (string $select) use (&$selectCalls, $queryBuilder) {
        $selectCalls[] = $select;

        return $queryBuilder;
    });
    $queryBuilder->shouldReceive('orderBy')->with('e.id', 'DESC')->once()->andReturn($queryBuilder);

    $repository = Mockery::mock(ActivityClientEmailRepository::class)->makePartial();
    $repository->shouldReceive('createQueryBuilder')->with('e')->once()->andReturn($queryBuilder);

    $result = $repository->getSearchQueryBuilder([]);

    expect($result)->toBe($queryBuilder);
    expect($selectCalls)->toHaveCount(1);
    expect($selectCalls[0])->toContain('attachmentName');
    expect($selectCalls[0])->toContain('attachmentMime');
    expect($selectCalls[0])->not->toContain('attachmentContent');
});

test('an email of another client or a missing one is a 404', function (): void {
    $repository = Mockery::mock(ActivityClientEmailRepository::class)->makePartial();
    $repository->shouldReceive('findOneForClientById')->with(1, 99)->once()->andReturn(null);

    expect(fn () => $repository->findOneForClientByIdOrFail(1, 99))
        ->toThrow(fn (ClientFence\InformationException $e) => expect($e->getCode())->toBe(404));
});

test('an unreadable date in the email history filter is refused with a clear message', function (string $field): void {
    $queryBuilder = Mockery::mock(QueryBuilder::class);
    $queryBuilder->shouldReceive('select', 'andWhere', 'orderBy')->andReturn($queryBuilder);

    $repository = Mockery::mock(ActivityClientEmailRepository::class)->makePartial();
    $repository->shouldReceive('createQueryBuilder')->andReturn($queryBuilder);

    expect(fn () => $repository->getSearchQueryBuilder([$field => 'nonsense']))
        ->toThrow(ClientFence\InformationException::class, 'Enter a valid date.');
})->with(['date_from', 'date_to']);
