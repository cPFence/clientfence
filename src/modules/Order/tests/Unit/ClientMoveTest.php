<?php

declare(strict_types=1);

/**
 * ClientFence (CF-183): staff move a service and its add-ons to another client (Order\ClientMove).
 */

use ClientFence\InformationException;
use ClientFence\Mod\Invoice\Service as InvoiceService;
use ClientFence\Mod\Invoice\ServiceInvoiceItem;
use ClientFence\Mod\Order\ClientMove;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

function clientMoveDb(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
        'CREATE TABLE client (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, currency TEXT, site TEXT, updated_at TEXT)',
        'CREATE TABLE admin (id INTEGER PRIMARY KEY, name TEXT)',
        'CREATE TABLE client_order (id INTEGER PRIMARY KEY, client_id INTEGER, title TEXT, status TEXT, currency TEXT, group_id TEXT, group_master INTEGER, service_id INTEGER, service_type TEXT, config TEXT, site TEXT, updated_at TEXT)',
        'CREATE TABLE service_hosting (id INTEGER PRIMARY KEY, client_id INTEGER, service_hosting_server_id INTEGER)',
        'CREATE TABLE service_hosting_server (id INTEGER PRIMARY KEY, manager TEXT)',
        'CREATE TABLE service_license (id INTEGER PRIMARY KEY, client_id INTEGER)',
        'CREATE TABLE invoice (id INTEGER PRIMARY KEY, client_id INTEGER, status TEXT, serie TEXT, nr TEXT)',
        'CREATE TABLE invoice_item (id INTEGER PRIMARY KEY, invoice_id INTEGER, type TEXT, rel_id TEXT)',
        'CREATE TABLE client_order_upgrade (id INTEGER PRIMARY KEY, order_id INTEGER, client_id INTEGER, invoice_id INTEGER, status TEXT)',
        'CREATE TABLE subscription (id INTEGER PRIMARY KEY, client_id INTEGER, sid TEXT, rel_type TEXT, rel_id INTEGER, status TEXT)',
        'CREATE TABLE client_order_hold (id INTEGER PRIMARY KEY, client_order_id INTEGER, client_id INTEGER, status TEXT)',
        'CREATE TABLE client_order_cancel_request (id INTEGER PRIMARY KEY, client_order_id INTEGER, client_id INTEGER, status TEXT)',
        'CREATE TABLE client_affiliate (id INTEGER PRIMARY KEY, client_id INTEGER)',
        'CREATE TABLE client_affiliate_referral (id INTEGER PRIMARY KEY, affiliate_id INTEGER, client_id INTEGER, client_order_id INTEGER)',
        'CREATE TABLE client_user_account (id INTEGER PRIMARY KEY, user_id INTEGER, client_id INTEGER)',
        'CREATE TABLE client_order_status (id INTEGER PRIMARY KEY AUTOINCREMENT, client_order_id INTEGER, status TEXT, notes TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE client_note (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, admin_id INTEGER, note TEXT, pinned INTEGER, created_at TEXT, updated_at TEXT)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    $db->insert('client', ['id' => 5, 'first_name' => 'Ann', 'last_name' => 'Old', 'currency' => 'EUR']);
    $db->insert('client', ['id' => 9, 'first_name' => 'Bob', 'last_name' => 'New', 'currency' => null]);
    $db->insert('client', ['id' => 11, 'first_name' => 'Cy', 'last_name' => 'Dollar', 'currency' => 'USD']);
    $db->insert('admin', ['id' => 3, 'name' => 'Staff One']);
    // A hosting service (70) with a licence add-on (71), and another service of the same client (72).
    $db->insert('client_order', ['id' => 70, 'client_id' => 5, 'title' => 'Hosting', 'status' => 'active', 'currency' => 'EUR', 'group_id' => 'g1', 'group_master' => 1, 'service_id' => 1, 'service_type' => 'hosting']);
    $db->insert('client_order', ['id' => 71, 'client_id' => 5, 'title' => 'Licence', 'status' => 'active', 'currency' => 'EUR', 'group_id' => 'g1', 'group_master' => 0, 'service_id' => 2, 'service_type' => 'license']);
    $db->insert('client_order', ['id' => 72, 'client_id' => 5, 'title' => 'Other', 'status' => 'active', 'currency' => 'EUR', 'group_id' => 'g2', 'group_master' => 1, 'service_id' => 3, 'service_type' => 'hosting']);
    $db->insert('service_hosting_server', ['id' => 1, 'manager' => 'Enhance']);
    $db->insert('service_hosting_server', ['id' => 2, 'manager' => 'Custom']);
    $db->insert('service_hosting', ['id' => 1, 'client_id' => 5, 'service_hosting_server_id' => 1]);
    $db->insert('service_license', ['id' => 2, 'client_id' => 5]);
    $db->insert('service_hosting', ['id' => 3, 'client_id' => 5, 'service_hosting_server_id' => 2]);
    // History stays with the old client: a paid invoice of the service.
    $db->insert('invoice', ['id' => 8, 'client_id' => 5, 'status' => 'paid']);
    $db->insert('invoice_item', ['invoice_id' => 8, 'type' => 'order', 'rel_id' => '70']);

    return $db;
}

function clientMoveContainer(Connection $db): Pimple\Container
{
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di = new Pimple\Container();
    $di['em'] = $em;
    $di['dbal'] = $db;
    $di['logger'] = new class {
        public array $lines = [];

        public function __call(string $name, array $args): void
        {
            $this->lines[] = vsprintf((string) array_shift($args), $args);
        }
    };

    return $di;
}

test('a move gives the service, its add-ons and their service records to the new client, with notes', function (): void {
    $db = clientMoveDb();
    $di = clientMoveContainer($db);

    ClientMove::move($di, 70, 9, 3);

    expect($db->fetchFirstColumn('SELECT client_id FROM client_order ORDER BY id'))->toEqual([9, 9, 5])
        ->and($db->fetchOne('SELECT client_id FROM service_hosting WHERE id = 1'))->toEqual(9)
        ->and($db->fetchOne('SELECT client_id FROM service_license WHERE id = 2'))->toEqual(9)
        ->and($db->fetchOne('SELECT client_id FROM service_hosting WHERE id = 3'))->toEqual(5)
        // The paid invoice stays with the old client; a client without a currency takes the service's.
        ->and($db->fetchOne('SELECT client_id FROM invoice WHERE id = 8'))->toEqual(5)
        ->and($db->fetchOne('SELECT currency FROM client WHERE id = 9'))->toBe('EUR')
        ->and($db->fetchFirstColumn('SELECT client_id FROM client_note ORDER BY client_id'))->toEqual([5, 9])
        ->and($db->fetchFirstColumn('SELECT client_order_id FROM client_order_status ORDER BY client_order_id'))->toEqual([70, 71]);
    $note = (string) $db->fetchOne('SELECT note FROM client_note WHERE client_id = 9');
    expect($note)->toContain('from client #5 Ann Old to client #9 Bob New by Staff One')
        ->toContain('add-ons #71')
        ->toContain('Enhance Link card')
        ->and($di['logger']->lines)->toHaveCount(1);
});

test('the moved services take the new client\'s site; the client\'s other service keeps its own (ClientFence\\Site)', function (): void {
    $db = clientMoveDb();
    $db->update('client', ['site' => 'shop/b'], ['id' => 5]);
    $db->update('client', ['site' => 'shop/c'], ['id' => 9]);
    $db->executeStatement("UPDATE client_order SET site = 'shop/b'");

    ClientMove::move(clientMoveContainer($db), 70, 9, 3);
    $onC = $db->fetchFirstColumn('SELECT site FROM client_order ORDER BY id');
    // Then to a client of the primary site.
    $db->insert('client', ['id' => 12, 'first_name' => 'Di', 'last_name' => 'Primary', 'currency' => 'EUR']);
    ClientMove::move(clientMoveContainer($db), 70, 12, 3);

    expect($onC)->toBe(['shop/c', 'shop/c', 'shop/b'])
        ->and($db->fetchFirstColumn('SELECT site FROM client_order ORDER BY id'))->toBe([null, null, 'shop/b']);
});

test('only a hosting account on an Enhance server is pointed to the Enhance Link card', function (): void {
    $db = clientMoveDb();
    ClientMove::move(clientMoveContainer($db), 72, 9, 3);

    expect((string) $db->fetchOne('SELECT note FROM client_note WHERE client_id = 9'))->toContain('move it on the panel by hand if needed.')
        ->not->toContain('Enhance Link card');
});

test('the affiliate keeps the moved service, unless the new client is the affiliate', function (): void {
    $db = clientMoveDb();
    $db->insert('client_affiliate', ['id' => 1, 'client_id' => 11]);
    $db->insert('client_affiliate_referral', ['id' => 1, 'affiliate_id' => 1, 'client_id' => 5, 'client_order_id' => 70]);

    ClientMove::move(clientMoveContainer($db), 70, 9, 3);
    expect($db->fetchOne('SELECT client_id FROM client_affiliate_referral WHERE id = 1'))->toEqual(9);

    $db->update('client_order', ['currency' => 'USD'], ['group_id' => 'g1']);
    ClientMove::move(clientMoveContainer($db), 70, 11, 3);
    expect($db->fetchOne('SELECT COUNT(*) FROM client_affiliate_referral'))->toEqual(0);
});

test('a move is refused, and nothing changes', function (int $orderId, int $clientId, ?Closure $setup, string $message): void {
    $db = clientMoveDb();
    if ($setup !== null) {
        $setup($db);
    }

    expect(fn () => ClientMove::move(clientMoveContainer($db), $orderId, $clientId, 3))->toThrow(InformationException::class, $message)
        ->and($db->fetchFirstColumn('SELECT DISTINCT client_id FROM client_order'))->toEqual([5])
        ->and($db->fetchOne('SELECT COUNT(*) FROM client_note'))->toEqual(0);
})->with([
    'same client' => [70, 5, null, 'already belongs to this client'],
    'unknown client' => [70, 404, null, 'Client not found'],
    'unpaid invoice of an add-on' => [70, 9, function (Connection $db): void {
        $db->insert('invoice', ['id' => 9, 'client_id' => 5, 'status' => 'unpaid', 'serie' => 'FOSS']);
        $db->insert('invoice_item', ['invoice_id' => 9, 'type' => 'order', 'rel_id' => '71']);
    }, 'unpaid invoice FOSS00009'],
    'unpaid upgrade invoice' => [70, 9, function (Connection $db): void {
        $db->insert('invoice', ['id' => 12, 'client_id' => 5, 'status' => 'unpaid', 'serie' => 'FOSS', 'nr' => '4']);
        $db->insert('client_order_upgrade', ['order_id' => 70, 'client_id' => 5, 'invoice_id' => 12, 'status' => 'pending']);
    }, 'unpaid invoice FOSS00004'],
    'other currency' => [70, 11, null, 'billed in EUR, but the new client pays in USD'],
    'gateway subscription' => [70, 9, function (Connection $db): void {
        $db->insert('subscription', ['client_id' => 5, 'sid' => 'sub_1', 'rel_type' => 'invoice', 'rel_id' => 8, 'status' => 'active']);
    }, 'gateway subscription'],
    'add-on alone' => [71, 9, null, 'This is an add-on'],
    'waiting for review' => [70, 9, function (Connection $db): void {
        $db->insert('client_order_hold', ['client_order_id' => 70, 'client_id' => 5, 'status' => 'held']);
    }, 'waits for review'],
    'cancellation request' => [70, 9, function (Connection $db): void {
        $db->insert('client_order_cancel_request', ['client_order_id' => 71, 'client_id' => 5, 'status' => 'pending']);
    }, 'waiting cancellation request'],
]);

test('after a move the next renewal invoice goes to the new client', function (): void {
    $db = clientMoveDb();
    ClientMove::move(clientMoveContainer($db), 70, 9, 3);

    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->id = 70;
    $order->client_id = (int) $db->fetchOne('SELECT client_id FROM client_order WHERE id = 70');
    $order->price = 10;
    $order->quantity = 1;
    $order->currency = 'EUR';

    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->id = 9;
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getExistingModelById')->with('Client', 9, 'Client not found')->once()->andReturn($client);
    $dbMock->shouldReceive('dispense')->andReturn($invoice);
    $dbMock->shouldReceive('store');
    $items = Mockery::mock(ServiceInvoiceItem::class);
    $items->shouldReceive('generateFromOrder')->once();

    $di = Tests\Helpers\container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $items);
    $service = Mockery::mock(InvoiceService::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('setInvoiceDefaults')->once();
    $service->setDi($di);

    $service->generateForOrder($order);

    expect($invoice->client_id)->toBe(9);
});

// A domain ordered with the hosting is a second master of its group: moving it leaves the hosting's add-ons with the hosting.
test('moving a domain bought with hosting leaves the hosting and its add-ons where they are', function (): void {
    $db = clientMoveDb();
    $db->executeStatement('CREATE TABLE service_domain (id INTEGER PRIMARY KEY, client_id INTEGER)');
    $db->insert('client_order', ['id' => 73, 'client_id' => 5, 'title' => 'example.com', 'status' => 'active', 'currency' => 'EUR', 'group_id' => 'g1', 'group_master' => 1, 'service_id' => 4, 'service_type' => 'domain']);
    $db->insert('service_domain', ['id' => 4, 'client_id' => 5]);
    // An add-on bought later for the domain itself moves with it.
    $db->insert('client_order', ['id' => 74, 'client_id' => 5, 'title' => 'Domain licence', 'status' => 'active', 'currency' => 'EUR', 'group_id' => 'g1', 'group_master' => 0, 'service_id' => 5, 'service_type' => 'license', 'config' => json_encode(['addon_for' => 73])]);
    $db->insert('service_license', ['id' => 5, 'client_id' => 5]);

    ClientMove::move(clientMoveContainer($db), 73, 9, 3);

    expect($db->fetchAllKeyValue('SELECT id, client_id FROM client_order WHERE id IN (70, 71, 73, 74) ORDER BY id'))->toEqual([70 => 5, 71 => 5, 73 => 9, 74 => 9])
        ->and((int) $db->fetchOne('SELECT client_id FROM service_domain WHERE id = 4'))->toBe(9)
        ->and((int) $db->fetchOne('SELECT client_id FROM service_license WHERE id = 2'))->toBe(5)
        ->and((int) $db->fetchOne('SELECT client_id FROM service_license WHERE id = 5'))->toBe(9);
});
