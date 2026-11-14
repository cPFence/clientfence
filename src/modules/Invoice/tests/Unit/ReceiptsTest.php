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

test('a manual method takes receipts unless its setting is off', function (?string $config, bool $takes): void {
    $gateway = new Model_PayGateway();
    $gateway->loadBean(new Tests\Helpers\DummyBean());
    $gateway->config = $config;

    expect(Receipts::receiptUpload($gateway))->toBe($takes);
})->with([
    'saved before the setting (no config)' => [null, true],
    'saved before the setting (other values)' => ['{"single":"Pay to IBAN ..."}', true],
    'on' => ['{"receipt_upload":"1"}', true],
    'off' => ['{"receipt_upload":"0"}', false],
]);

test('a receipt for a method that takes none is refused before anything is stored', function (): void {
    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->id = 7;
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    $invoice->id = 30;
    $gateway = new Model_PayGateway();
    $gateway->loadBean(new Tests\Helpers\DummyBean());
    $gateway->id = 4;
    $gateway->config = '{"receipt_upload":"0"}';
    $png = sys_get_temp_dir() . '/cf-receipt-' . bin2hex(random_bytes(4)) . '.png';
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
    // No database, ticket or file service: the refusal comes first.
    $di = container();

    try {
        expect(fn () => Receipts::send($di, $client, $invoice, $gateway, new Symfony\Component\HttpFoundation\File\UploadedFile($png, 'receipt.png', 'image/png', null, true)))
            ->toThrow(ClientFence\InformationException::class, 'This payment method does not take receipt uploads.')
            ->and(is_file($png))->toBeTrue();
    } finally {
        @unlink($png);
    }
});

test('a sent receipt sets the invoice to the transfer method, so the saved-card cron skips it', function (): void {
    $dir = sys_get_temp_dir() . '/cf-receipts-' . bin2hex(random_bytes(4));
    putenv('CLIENTFENCE_RECEIPT_DIR=' . $dir);
    $png = sys_get_temp_dir() . '/cf-receipt-' . bin2hex(random_bytes(4)) . '.png';
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->id = 7;
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    $invoice->id = 30;
    $invoice->currency = 'USD';
    $gateway = new Model_PayGateway();
    $gateway->loadBean(new Tests\Helpers\DummyBean());
    $gateway->id = 4;
    $gateway->name = 'Bank transfer';

    $di = container();
    $updates = [];
    $dbal = Mockery::mock(Doctrine\DBAL\Connection::class);
    $dbal->allows('executeStatement')->andReturnUsing(function (string $sql, array $params) use (&$updates): int {
        $updates[] = [$sql, $params[0], $params[2], $params[3]];

        return 1;
    });
    // The gateway fee (GatewayFees::apply()): this method has none.
    $dbal->allows('getDatabasePlatform')->andReturn(new Doctrine\DBAL\Platforms\SQLitePlatform());
    $dbal->allows('fetchAssociative')->andReturn(['status' => 'unpaid', 'gateway_id' => 4]);
    $di['db'] = Mockery::mock();
    $di['db']->allows('load')->andReturn($gateway);
    $di['db']->allows('findOne')->andReturnNull();
    $di['dbal'] = $dbal;
    $di['logger'] = new class {
        public function __call(string $name, array $arguments): mixed
        {
            return $this;
        }
    };
    $di['url'] = new class {
        public function link(string $path): string
        {
            return 'https://example.com/' . $path;
        }
    };
    $di['mod_config'] = $di->protect(fn (string $mod): array => []);
    $helpdesk = Mockery::mock();
    $helpdesk->allows('getId')->andReturn(1);
    $helpdesks = Mockery::mock();
    $helpdesks->allows('findOneBy')->andReturn($helpdesk);
    $support = Mockery::mock();
    $support->allows('getHelpdeskRepository')->andReturn($helpdesks);
    $support->allows('ticketCreateForClient')->andReturn(55);
    $invoices = Mockery::mock();
    $invoices->allows('toApiArray')->andReturn(['serie_nr' => 'INV-30', 'total' => 100]);
    $invoices->allows('isInvoiceTypeDeposit')->andReturn(false);
    $di['mod_service'] = $di->protect(fn (string $mod) => strtolower($mod) === 'support' ? $support : $invoices);

    try {
        $ticket = Receipts::send($di, $client, $invoice, $gateway, new Symfony\Component\HttpFoundation\File\UploadedFile($png, 'receipt.png', 'image/png', null, true));
    } finally {
        putenv('CLIENTFENCE_RECEIPT_DIR');
        array_map('unlink', glob($dir . '/7/*') ?: []);
        @rmdir($dir . '/7');
        @rmdir($dir);
        @unlink($png);
    }

    // Only gateway_id and updated_at of an invoice still unpaid: a full store could undo a payment made meanwhile.
    expect($ticket)->toBe(55)
        ->and($updates)->toBe([['UPDATE invoice SET gateway_id = ?, updated_at = ? WHERE id = ? AND status = ?', 4, 30, 'unpaid']]);
});
