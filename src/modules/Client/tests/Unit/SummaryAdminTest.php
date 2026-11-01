<?php

declare(strict_types=1);

use ClientFence\InformationException;
use ClientFence\Mod\Client\Api\Admin;
use ClientFence\Mod\Client\Entity\Client;
use ClientFence\Mod\Client\Entity\ClientBalance;
use ClientFence\Mod\Client\Files;
use ClientFence\Mod\Client\Service;
use ClientFence\Mod\Client\ServiceBalance;
use ClientFence\Mod\Order\Entity\Order;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

use function Tests\Helpers\admin;
use function Tests\Helpers\container;
use function Tests\Helpers\createEntity;
use function Tests\Helpers\moduleService;

function clientSummaryDi(): Pimple\Container
{
    $di = container();
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
        'CREATE TABLE client (id INTEGER PRIMARY KEY, currency TEXT)',
        'CREATE TABLE admin (id INTEGER PRIMARY KEY, name TEXT)',
        'CREATE TABLE invoice (id INTEGER PRIMARY KEY, client_id INTEGER, currency TEXT, status TEXT, approved INTEGER, taxrate TEXT)',
        'CREATE TABLE invoice_item (invoice_id INTEGER, price REAL, quantity INTEGER, taxed INTEGER)',
        'CREATE TABLE client_order (id INTEGER PRIMARY KEY, client_id INTEGER, service_type TEXT, status TEXT, group_master INTEGER, group_id TEXT)',
        'CREATE TABLE client_balance (client_id INTEGER, amount REAL)',
        'CREATE TABLE invoice_quote (client_id INTEGER, stage TEXT)',
        'CREATE TABLE support_ticket (client_id INTEGER, status TEXT)',
        'CREATE TABLE client_note (id INTEGER PRIMARY KEY, client_id INTEGER, admin_id INTEGER, note TEXT, pinned INTEGER, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE client_file (id INTEGER PRIMARY KEY, client_id INTEGER, admin_id INTEGER, name TEXT, stored_name TEXT, size INTEGER, mime TEXT, created_at TEXT)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    $db->insert('client', ['id' => 7, 'currency' => 'USD']);
    $db->insert('admin', ['id' => 2, 'name' => 'Jane Staff']);
    $di['dbal'] = $db;

    return $di;
}

function clientSummaryApi(Pimple\Container $di): Admin
{
    $api = new Admin();
    $api->setDi($di);
    $api->setIdentity(admin(['id' => 2]));

    return $api;
}

test('summary groups invoices once with tax, draft approval, services and open work', function (): void {
    $di = clientSummaryDi();
    $db = $di['dbal'];
    foreach (['paid', 'unpaid', 'unpaid', 'canceled', 'refunded'] as $i => $status) {
        $db->insert('invoice', ['id' => $i + 1, 'client_id' => 7, 'currency' => 'USD', 'status' => $status, 'approved' => (int) ($i !== 2), 'taxrate' => '10']);
        $db->insert('invoice_item', ['invoice_id' => $i + 1, 'price' => 10, 'quantity' => 2, 'taxed' => 1]);
        $db->insert('invoice_item', ['invoice_id' => $i + 1, 'price' => 5, 'quantity' => 1, 'taxed' => 0]);
    }
    $db->insert('invoice', ['id' => 6, 'client_id' => 8, 'currency' => 'USD', 'status' => 'paid', 'approved' => 1]);
    foreach ([['hosting', 'active', 1], ['hosting', 'suspended', 1], ['domain', 'active', 1], ['custom', 'active', 0], ['custom', 'active', null]] as [$type, $status, $master]) {
        $db->insert('client_order', ['client_id' => 7, 'service_type' => $type, 'status' => $status, 'group_master' => $master, 'group_id' => 'group']);
    }
    $db->insert('client_balance', ['client_id' => 7, 'amount' => 9]);
    $db->insert('client_balance', ['client_id' => 7, 'amount' => -2]);
    $db->insert('invoice_quote', ['client_id' => 7, 'stage' => 'delivered']);
    $db->insert('invoice_quote', ['client_id' => 7, 'stage' => 'accepted']);
    $db->insert('support_ticket', ['client_id' => 7, 'status' => 'on_hold']);
    $db->insert('support_ticket', ['client_id' => 7, 'status' => 'closed']);
    // The credit shown is what the client can spend (the Invoice module sets aside part payments).
    $invoiceService = Mockery::mock(ClientFence\Mod\Invoice\Service::class);
    $invoiceService->shouldReceive('getFreeCredit')->once()->with(7)->andReturn(7.0);
    $di['mod_service'] = $di->protect(moduleService(['invoice' => $invoiceService]));
    $service = new Service();
    $service->setDi($di);
    $api = clientSummaryApi($di);
    $api->setService($service);

    $summary = $api->summary(['id' => 7]);

    expect($summary['currency'])->toBe('USD')
        ->and(array_column($summary['invoices'], 'count'))->toBe([1, 1, 1, 1, 1])
        ->and(array_column($summary['invoices'], 'total'))->toBe([27.0, 27.0, 27.0, 27.0, 27.0])
        ->and($summary['gross_revenue'])->toBe(27.0)
        ->and($summary['credit_balance'])->toBe(7.0)
        ->and($summary['services']['hosting'])->toMatchArray(['active' => 1, 'total' => 2])
        ->and($summary['services']['domain'])->toMatchArray(['active' => 1, 'total' => 1])
        ->and($summary['addon_count'])->toBe(2)
        ->and($summary['open_quotes'])->toBe(1)
        ->and($summary['open_tickets'])->toBe(1);
});

test('summary counts the refunded original invoice without its negative refund invoice', function (): void {
    $di = clientSummaryDi();
    $db = $di['dbal'];
    foreach ([1 => 100, 2 => -100] as $invoiceId => $price) {
        $db->insert('invoice', ['id' => $invoiceId, 'client_id' => 7, 'currency' => 'USD', 'status' => 'refunded', 'approved' => 1, 'taxrate' => '0']);
        $db->insert('invoice_item', ['invoice_id' => $invoiceId, 'price' => $price, 'quantity' => 1, 'taxed' => 0]);
    }
    $service = new Service();
    $service->setDi($di);
    $api = clientSummaryApi($di);
    $api->setService($service);

    $summary = $api->summary(['id' => 7]);

    expect($summary['invoices']['refunded'])->toBe(['count' => 1, 'total' => 100.0]);
});

test('balance deduction refuses amounts above the balance and creates a negative entry at the floor', function (float $amount, bool $allowed): void {
    $di = container();
    $client = createEntity(Client::class, ['id' => 7]);
    $di['em']->getRepository(Client::class)->shouldReceive('find')->with(7)->andReturn($client);
    $di['em']->getRepository(ClientBalance::class)->shouldReceive('getClientBalanceSum')->with(7)->andReturn(10.0);
    $di['em']->shouldReceive('persist')->times((int) $allowed)->with(Mockery::on(static fn (ClientBalance $entry): bool => $entry->getAmount() === '-10' && $entry->getDescription() === 'Correction'));
    $balance = new ServiceBalance();
    $balance->setDi($di);
    $di['mod_service'] = $di->protect(moduleService(['client:balance' => $balance]));
    $api = clientSummaryApi($di);

    if ($allowed) {
        expect($api->balance_deduct(['id' => 7, 'amount' => $amount, 'description' => 'Correction']))->toBeTrue();
    } else {
        expect(fn () => $api->balance_deduct(['id' => 7, 'amount' => $amount, 'description' => 'Correction']))->toThrow(InformationException::class);
    }
})->with([[11.0, false], [10.0, true], [-1.0, false]]);

test('close cancels active and suspended services including addons before disabling the account', function (): void {
    $di = container();
    $client = createEntity(Client::class, ['id' => 7, 'status' => 'active', 'api_token' => 'token']);
    $di['em']->getRepository(Client::class)->shouldReceive('find')->with(7)->andReturn($client);
    $master = createEntity(Order::class, ['id' => 1, 'status' => 'active']);
    $addon = createEntity(Order::class, ['id' => 2, 'status' => 'active']);
    $suspended = createEntity(Order::class, ['id' => 3, 'status' => 'suspended']);
    $repo = Mockery::mock(ClientFence\Mod\Order\Repository\OrderRepository::class);
    $repo->shouldReceive('findBy')->once()->with(['clientId' => 7, 'status' => ['active', 'suspended']], ['groupMaster' => 'DESC'])->andReturn([$master, $addon, $suspended]);
    $di['em']->shouldReceive('getRepository')->with(Order::class)->andReturn($repo);
    $orders = Mockery::mock(ClientFence\Mod\Order\Service::class);
    $orders->shouldReceive('cancelFromOrder')->once()->with($master)->andReturnUsing(function () use ($master, $addon): bool {
        $master->setStatus('canceled');
        $addon->setStatus('canceled');

        return true;
    });
    $orders->shouldReceive('cancelFromOrder')->once()->with($suspended)->andReturnUsing(function () use ($suspended): bool {
        $suspended->setStatus('canceled');

        return true;
    });
    $profile = Mockery::mock(ClientFence\Mod\Profile\Service::class);
    $profile->shouldReceive('invalidateSessions')->once()->with('client', 7);
    $di['mod_service'] = $di->protect(moduleService(['order' => $orders, 'profile' => $profile]));
    $service = new Service();
    $service->setDi($di);
    $api = clientSummaryApi($di);
    $api->setService($service);

    expect($api->close(['id' => 7]))->toBe(['cancelled' => 3])
        ->and($client->getStatus())->toBe('canceled')
        ->and($client->getApiToken())->toBeNull();
});

test('staff notes retain the author through editing and pinning and are scoped to the client', function (): void {
    $di = clientSummaryDi();
    $api = clientSummaryApi($di);
    $first = $api->note_create(['client_id' => 7, 'note' => 'First']);
    $second = $api->note_create(['client_id' => 7, 'note' => 'Second']);
    $di['dbal']->insert('client_note', ['client_id' => 8, 'note' => 'Other client']);
    expect($api->note_update(['id' => $first, 'note' => 'Edited', 'pinned' => 1]))->toBeTrue();

    $notes = $api->note_get_list(['client_id' => 7]);
    expect(array_column($notes, 'id'))->toBe([$first, $second])
        ->and($notes[0])->toMatchArray(['note' => 'Edited', 'author' => 'Jane Staff', 'admin_id' => 2, 'pinned' => 1])
        ->and($notes[0]['created_at'])->not->toBeEmpty()
        ->and($notes[0]['updated_at'])->not->toBeEmpty()
        ->and($api->note_delete(['id' => $first]))->toBeTrue()
        ->and($api->note_get_list(['client_id' => 7]))->toHaveCount(1);
});

test('file list contains only the client metadata and author without a storage path', function (): void {
    $di = clientSummaryDi();
    foreach ([7, 8] as $clientId) {
        $di['dbal']->insert('client_file', ['client_id' => $clientId, 'admin_id' => 2, 'name' => 'Contract.pdf', 'stored_name' => str_repeat('a', 32), 'size' => 10, 'mime' => 'application/pdf']);
    }
    $files = clientSummaryApi($di)->file_get_list(['client_id' => 7]);

    expect($files)->toHaveCount(1)
        ->and($files[0])->toMatchArray(['client_id' => 7, 'author' => 'Jane Staff', 'name' => 'Contract.pdf'])
        ->and($files[0])->not->toHaveKey('stored_name');
});

test('file upload refuses unsafe types and excessive size before storage', function (string $name, string $content, ?int $size, string $message): void {
    $di = clientSummaryDi();
    $path = tempnam(sys_get_temp_dir(), 'client-document-');
    file_put_contents($path, $content);
    try {
        $file = Mockery::mock(UploadedFile::class, [$path, $name, null, null, true])->makePartial();
        if ($size !== null) {
            $file->shouldReceive('getSize')->andReturn($size);
        }
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldNotReceive('mkdir');
        $di['filesystem'] = $filesystem;
        $di['request'] = new Request([], [], [], [], ['file' => $file]);

        expect(fn () => clientSummaryApi($di)->file_upload(['client_id' => 7]))->toThrow(InformationException::class, $message)
            ->and(str_starts_with(Files::dir(), PATH_ROOT . '/'))->toBeFalse()
            ->and(Files::dir())->not->toBe(ClientFence\Mod\Support\TicketFiles::dir());
    } finally {
        unlink($path);
    }
})->with([
    ['payload.php', '<?php echo 1;', null, 'type of file'],
    ['fake.pdf', '<html>bad</html>', null, 'type of file'],
    ['large.txt', 'text', ClientFence\Mod\Support\TicketFiles::MAX_BYTES + 1, 'smaller than'],
]);

test('file deletion removes its private object and database row', function (): void {
    $di = clientSummaryDi();
    $token = str_repeat('a', 32);
    $di['dbal']->insert('client_file', ['id' => 3, 'client_id' => 7, 'stored_name' => $token]);
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('remove')->once()->with(Files::dir() . '/7/' . $token);
    $di['filesystem'] = $filesystem;

    expect(clientSummaryApi($di)->file_delete(['id' => 3]))->toBeTrue()
        ->and((int) $di['dbal']->fetchOne('SELECT COUNT(*) FROM client_file'))->toBe(0);
});

test('accepted client files use random private names with restrictive permissions', function (): void {
    $di = clientSummaryDi();
    $path = tempnam(sys_get_temp_dir(), 'client-document-');
    file_put_contents($path, 'Contract text');
    try {
        $file = Mockery::mock(UploadedFile::class, [$path, 'contract.txt', null, null, true])->makePartial();
        $file->shouldReceive('move')->once()->with(Files::dir() . '/7', Mockery::pattern('/^[a-f0-9]{32}$/'))->andReturn(new Symfony\Component\HttpFoundation\File\File($path));
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('mkdir')->once()->with(Files::dir() . '/7', 0700);
        $filesystem->shouldReceive('chmod')->once()->with(Mockery::on(static fn (string $path): bool => preg_match('~^' . preg_quote(Files::dir(), '~') . '/7/[a-f0-9]{32}$~', $path) === 1), 0600);
        $di['filesystem'] = $filesystem;
        $di['request'] = new Request([], [], [], [], ['file' => $file]);

        $result = clientSummaryApi($di)->file_upload(['client_id' => 7]);
        $row = $di['dbal']->fetchAssociative('SELECT * FROM client_file WHERE id = ?', [$result['id']]);
        expect($row)->toMatchArray(['client_id' => 7, 'admin_id' => 2, 'name' => 'contract.txt', 'mime' => 'text/plain', 'size' => 13])
            ->and($row['stored_name'])->toMatch('/^[a-f0-9]{32}$/')
            ->and($result)->not->toHaveKey('stored_name');
    } finally {
        unlink($path);
    }
});

test('client document download checks staff permissions before loading a file', function (): void {
    $di = container();
    $di['is_admin_logged'] = true;
    $di['mod_service']('Staff')->shouldReceive('checkPermissionsAndThrowException')->once()
        ->with('client', 'view')->andThrow(new InformationException('Permission denied'));
    $di['dbal']->shouldNotReceive('fetchAssociative');
    $controller = new ClientFence\Mod\Client\Controller\Admin();
    $controller->setDi($di);

    expect(fn () => $controller->get_file(Mockery::mock(ClientFence\App::class), 3))->toThrow(InformationException::class, 'Permission denied');
});

test('balance deduction refuses staff without the balance permission', function (): void {
    $di = container();
    $di['mod_service']('Staff')->shouldReceive('checkPermissionsAndThrowException')->once()
        ->with('client', 'manage_balance', null, Mockery::type(Model_Admin::class))
        ->andThrow(new InformationException('Permission denied'));
    $di['em']->shouldNotReceive('persist');

    expect(fn () => clientSummaryApi($di)->balance_deduct(['id' => 7, 'amount' => 1, 'description' => 'Correction']))->toThrow(InformationException::class, 'Permission denied');
});
