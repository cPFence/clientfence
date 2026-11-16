<?php

declare(strict_types=1);

/**
 * ClientFence: the Database tab of System settings (CF-150, DatabaseMaintenance.php).
 */

use ClientFence\InformationException;
use ClientFence\Mod\Clientfence\DatabaseMaintenance;

use function Tests\Helpers\container;

function databaseMaintenanceDi(array $tables): array
{
    $di = container();
    $di['logger'] = new Tests\Helpers\TestLogger();
    $dbal = Mockery::mock(Doctrine\DBAL\Connection::class);
    $dbal->shouldReceive('fetchAllAssociative')->with(Mockery::pattern('/information_schema\.TABLES/'))->andReturn($tables);
    $dbal->shouldReceive('quoteSingleIdentifier')->andReturnUsing(fn (string $name): string => '`' . $name . '`');
    $di['dbal'] = $dbal;

    return [$di, $dbal];
}

test('optimize rebuilds only the tables with free space and keeps going after a failure', function (): void {
    [$di, $dbal] = databaseMaintenanceDi([
        ['name' => 'activity_system', 'row_count' => '900', 'size' => '5000', 'free' => '4096'],
        ['name' => 'client', 'row_count' => '10', 'size' => '300', 'free' => '0'],
        ['name' => 'email_log', 'row_count' => '50', 'size' => '200', 'free' => '1024'],
        ['name' => 'support_ticket', 'row_count' => '5', 'size' => '100', 'free' => '512'],
    ]);
    $dbal->shouldReceive('fetchAllAssociative')->with('OPTIMIZE TABLE `activity_system`')->once()
        ->andReturn([['Msg_type' => 'note', 'Msg_text' => 'Table does not support optimize, doing recreate + analyze instead'], ['Msg_type' => 'status', 'Msg_text' => 'OK']]);
    $dbal->shouldReceive('fetchAllAssociative')->with('OPTIMIZE TABLE `email_log`')->once()->andReturn([['Msg_type' => 'Error', 'Msg_text' => 'broken']]);
    $dbal->shouldReceive('fetchAllAssociative')->with('OPTIMIZE TABLE `support_ticket`')->once()->andThrow(new RuntimeException('lock wait'));
    $dbal->shouldNotReceive('fetchAllAssociative')->with('OPTIMIZE TABLE `client`');

    expect(fn () => DatabaseMaintenance::optimize($di))->toThrow(InformationException::class, 'email_log, support_ticket');
});

test('the table list keeps the numbers as integers, biggest first as the query orders them', function (): void {
    [$di] = databaseMaintenanceDi([['name' => 'invoice', 'row_count' => '12', 'size' => '65536', 'free' => null]]);

    expect(DatabaseMaintenance::tables($di))->toBe([['name' => 'invoice', 'rows' => 12, 'size' => 65536, 'free' => 0]]);
    expect(DatabaseMaintenance::optimize($di))->toBe(0);
});
