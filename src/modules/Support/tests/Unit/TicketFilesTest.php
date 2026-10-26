<?php

declare(strict_types=1);

/**
 * ClientFence: ticket attachments in the Support module (TicketFiles.php).
 */

use ClientFence\Mod\Client\Users;
use ClientFence\Mod\Support\TicketFiles;

use function Tests\Helpers\container;

/**
 * prune() with this "keep ticket files" setting: the cut-off date of the expiry query, or null
 * when files are kept.
 */
function ticketFilesPruneCutoff(?string $setting): ?string
{
    $cutoff = null;
    $connection = Mockery::mock(Doctrine\DBAL\Connection::class);
    $connection->shouldReceive('fetchAllAssociative')
        ->with(Mockery::on(fn (string $sql): bool => str_contains($sql, 'removed_at IS NULL AND created_at < ?')), Mockery::any())
        ->andReturnUsing(function (string $sql, array $params) use (&$cutoff): array {
            $cutoff = $params[0];

            return [];
        });
    $connection->shouldReceive('fetchAllAssociative')->andReturn([]);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($connection);
    $di = container();
    $di['em'] = $em;
    $di['mod_config'] = $di->protect(fn (string $name): array => $setting === null ? [] : ['ticket_files_days' => $setting]);

    TicketFiles::prune($di);

    return $cutoff;
}

test('sent ticket files are kept for the setting\'s days, 180 when it is blank, for good at 0', function (?string $setting, ?int $days): void {
    $cutoff = ticketFilesPruneCutoff($setting);

    if ($days === null) {
        expect($cutoff)->toBeNull();

        return;
    }
    expect(abs(strtotime((string) $cutoff) - (time() - $days * 86400)))->toBeLessThan(60);
})->with([
    'not set' => [null, TicketFiles::KEEP_DAYS],
    'blank' => ['', TicketFiles::KEEP_DAYS],
    'set' => ['30', 30],
    'kept' => ['0', null],
]);

test('a ticket file must have a type its extension allows', function (string $extension, string $mime, bool $fits): void {
    expect(TicketFiles::typeFits($extension, $mime))->toBe($fits);
})->with([
    'image' => ['png', 'image/png', true],
    'renamed html' => ['png', 'text/html', false],
    'log as text' => ['log', 'text/plain', true],
    'svg never' => ['svg', 'image/svg+xml', false],
]);

test('no files are attached to a ticket id of 0', function (): void {
    expect(TicketFiles::attachFromRequest(container(), 0, false))->toBe(0);
});

test('authorized users with tickets may attach files', function (): void {
    expect(Users::CALLS['support']['ticket_file_upload'])->toBe('tickets')
        ->and(Users::CALLS['support']['ticket_file_discard'])->toBe('tickets')
        ->and(Users::CALLS['client'])->not->toHaveKey('ticket_file_upload');
});
