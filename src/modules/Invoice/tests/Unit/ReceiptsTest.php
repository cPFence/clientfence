<?php

declare(strict_types=1);

/**
 * ClientFence: payment method cards and payment receipts in the Invoice module (Receipts.php).
 */

use ClientFence\Mod\Client\Users;
use ClientFence\Mod\Invoice\Receipts;

use function Tests\Helpers\container;

test('a payment method card gets two letters from its name', function (string $name, string $mark): void {
    expect(Receipts::paymentMark($name))->toBe($mark);
})->with([
    'currency in brackets' => ['Bank transfer (USD)', 'US'],
    'one word with capitals' => ['InstaPay', 'IP'],
    'one plain word' => ['Stripe', 'ST'],
    'two words' => ['E-wallet', 'EW'],
]);

test('the receipt help desk setting is blank or an existing help desk', function (): void {
    $connection = Mockery::mock(Doctrine\DBAL\Connection::class);
    $connection->shouldReceive('fetchOne')->with('SELECT 1 FROM support_helpdesk WHERE id = ?', [2])->andReturn(1);
    $connection->shouldReceive('fetchOne')->with('SELECT 1 FROM support_helpdesk WHERE id = ?', [9])->andReturn(false);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($connection);
    $di = container();
    $di['em'] = $em;

    expect(Receipts::helpdeskSetting($di, ''))->toBe('')
        ->and(Receipts::helpdeskSetting($di, ' 2 '))->toBe('2')
        ->and(fn () => Receipts::helpdeskSetting($di, '9'))->toThrow(ClientFence\InformationException::class)
        ->and(fn () => Receipts::helpdeskSetting($di, '2; x'))->toThrow(ClientFence\InformationException::class);
});

test('a receipt id is 32 hex characters', function (): void {
    expect(Receipts::find('../../etc/passwd'))->toBeNull()
        ->and(Receipts::find(str_repeat('z', 32)))->toBeNull();
});

test('authorized users with invoices may see payment instructions and send receipts', function (): void {
    expect(Users::CALLS['invoice']['payment_details'])->toBe('invoices')
        ->and(Users::CALLS['invoice']['receipt_send'])->toBe('invoices')
        ->and(Users::CALLS['client'])->not->toHaveKey('payment_details')
        ->and(Users::CALLS['client'])->not->toHaveKey('receipt_send');
});
