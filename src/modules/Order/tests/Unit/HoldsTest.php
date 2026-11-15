<?php

declare(strict_types=1);

/**
 * ClientFence: orders held for review (Order\Holds) on an in-memory database, with stand-ins for the Invoice,
 * Order, Product and Email services.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Order\Holds;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

use function Tests\Helpers\container;

/** One checkout held: orders 1 (set up after the order) and 2 (after payment) on draft invoice 5. */
function holdsDb(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE client_order_hold (id INTEGER PRIMARY KEY AUTOINCREMENT, client_order_id INTEGER, client_id INTEGER, invoice_id INTEGER, reason TEXT, note TEXT, status TEXT, admin_id INTEGER, created_at TEXT, updated_at TEXT)');
    $db->executeStatement('CREATE TABLE client (id INTEGER PRIMARY KEY, email TEXT)');
    $db->executeStatement('CREATE TABLE client_order (id INTEGER PRIMARY KEY, client_id INTEGER, product_id INTEGER, title TEXT, status TEXT, reason TEXT, canceled_at TEXT, unpaid_invoice_id INTEGER, updated_at TEXT)');
    $db->executeStatement('CREATE TABLE product (id INTEGER PRIMARY KEY, setup TEXT)');
    $db->executeStatement('CREATE TABLE invoice (id INTEGER PRIMARY KEY, status TEXT, approved INTEGER, due_at TEXT, created_at TEXT, updated_at TEXT)');
    $db->insert('client', ['id' => 9, 'email' => 'buyer@example.com']);
    $db->insert('product', ['id' => 1, 'setup' => 'after_order']);
    $db->insert('product', ['id' => 2, 'setup' => 'after_payment']);
    foreach ([1, 2] as $id) {
        $db->insert('client_order', ['id' => $id, 'client_id' => 9, 'product_id' => $id, 'title' => 'Order ' . $id, 'status' => 'pending_setup']);
    }
    $db->insert('invoice', ['id' => 5, 'status' => 'unpaid', 'approved' => 0, 'due_at' => '2026-11-02 10:00:00', 'created_at' => '2026-11-01 10:00:00']);
    Holds::add($db, [1, 2], 9, 5, Holds::REASON_NETWORK, 'HETZNER-AS (AS24940)');

    return $db;
}

/** @param array<string, list<mixed>> $calls what the stand-in services were asked */
function holdsContainer(Connection $db, array &$calls): Pimple\Container
{
    $di = container();
    $di['logger'] = new Tests\Helpers\TestLogger();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;
    $di['dbal'] = $db;
    $legacy = Mockery::mock(ClientFence\Database::class);
    $legacy->shouldReceive('load')->andReturnUsing(function (string $type, int $id) use ($db): object {
        $model = $type === 'Invoice' ? new Model_Invoice() : new Model_ClientOrder();
        $model->loadBean(new Tests\Helpers\DummyBean());
        foreach ($db->fetchAssociative('SELECT * FROM ' . ($type === 'Invoice' ? 'invoice' : 'client_order') . ' WHERE id = ?', [$id]) as $key => $value) {
            $model->{$key} = $value;
        }

        return $model;
    });
    $di['db'] = $legacy;
    $services = new class($db, $calls) {
        public function __construct(private Connection $db, private array &$calls)
        {
        }

        public function approveInvoice(Model_Invoice $invoice, array $data): bool
        {
            $this->calls['approveInvoice'][] = [(int) $invoice->id, $data['use_credits'], $invoice->due_at];
            $this->db->executeStatement('UPDATE invoice SET approved = 1 WHERE id = ?', [$invoice->id]);

            return true;
        }

        public function activateOrder(Model_ClientOrder $order): bool
        {
            $this->calls['activateOrder'][] = (int) $order->id;

            return true;
        }

        public function orderStatusAdd(Model_ClientOrder $order, string $status, ?string $notes = null): bool
        {
            $this->calls['orderStatusAdd'][] = [(int) $order->id, $status];

            return true;
        }

        public function releaseReservedPromoRedemptionsForOrder(Model_ClientOrder $order, string $reason): void
        {
            $this->calls['release'][] = (int) $order->id;
        }

        public function sendTemplate(array $email): bool
        {
            $this->calls['email'][] = [$email['code'], $email['to_client'], array_column($email['orders'], 'id')];

            return true;
        }

        public function setConfig(array $config): bool
        {
            $this->calls['config'][] = $config;

            return true;
        }
    };
    $di['mod_service'] = $di->protect(static fn (): object => $services);
    $di['mod_config'] = $di->protect(static fn (): array => []);

    return $di;
}

test('approving issues the draft invoice, sets the checkout up and decides its orders together', function (): void {
    $db = holdsDb();
    $calls = [];
    $di = holdsContainer($db, $calls);
    $holdOfOrder2 = (int) $db->fetchOne('SELECT id FROM client_order_hold WHERE client_order_id = 2');

    Holds::approve($di, $holdOfOrder2, 3);

    expect($calls['approveInvoice'])->toHaveCount(1)
        ->and($calls['approveInvoice'][0][0])->toBe(5)
        ->and($calls['approveInvoice'][0][1])->toBeTrue()
        // The day of payment term starts now, not at the checkout.
        ->and(strtotime((string) $calls['approveInvoice'][0][2]))->toBeGreaterThan(time() + 86000)
        // Set up after the order: now; after payment: waits for the invoice, which is still unpaid.
        ->and($calls['activateOrder'])->toBe([1])
        ->and($db->fetchFirstColumn('SELECT unpaid_invoice_id FROM client_order ORDER BY id'))->toBe([5, 5])
        ->and($db->fetchFirstColumn('SELECT status FROM client_order_hold ORDER BY id'))->toBe(['approved', 'approved'])
        ->and($db->fetchFirstColumn('SELECT admin_id FROM client_order_hold ORDER BY id'))->toBe([3, 3])
        ->and($calls['email'])->toBe([['mod_order_hold_approved', 9, [1, 2]]])
        ->and(fn () => Holds::approve($di, $holdOfOrder2, 3))->toThrow(InformationException::class, 'no longer waiting');
});

test('refusing cancels the orders and the draft invoice, and may ban the email', function (): void {
    $db = holdsDb();
    $calls = [];
    $di = holdsContainer($db, $calls);

    Holds::refuse($di, (int) $db->fetchOne('SELECT MIN(id) FROM client_order_hold'), 3, true);

    expect($db->fetchFirstColumn('SELECT status FROM client_order ORDER BY id'))->toBe(['canceled', 'canceled'])
        ->and($db->fetchOne('SELECT status FROM invoice WHERE id = 5'))->toBe('canceled')
        ->and($calls['release'])->toBe([1, 2])
        ->and($calls)->not->toHaveKey('approveInvoice')
        ->and($calls['config'][0]['banned_emails'])->toBe('buyer@example.com')
        ->and($calls['email'])->toBe([['mod_order_hold_refused', 9, [1, 2]]]);
});

test('the orders of a free checkout (no invoice) are decided together, not with another checkout', function (): void {
    $db = holdsDb();
    $calls = [];
    $di = holdsContainer($db, $calls);
    foreach ([3, 4, 5] as $id) {
        $db->insert('client_order', ['id' => $id, 'client_id' => 9, 'product_id' => 1, 'title' => 'Order ' . $id, 'status' => 'pending_setup']);
    }
    Holds::add($db, [3, 4], 9, null, Holds::REASON_NETWORK, '');
    $db->insert('client_order_hold', ['client_order_id' => 5, 'client_id' => 9, 'invoice_id' => null, 'reason' => 'network', 'status' => 'held', 'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00']);

    Holds::refuse($di, (int) $db->fetchOne('SELECT id FROM client_order_hold WHERE client_order_id = 4'), 3, false);

    expect($db->fetchAllKeyValue('SELECT client_order_id, status FROM client_order_hold WHERE invoice_id IS NULL ORDER BY client_order_id'))->toBe([3 => 'refused', 4 => 'refused', 5 => 'held'])
        ->and($calls['email'])->toBe([['mod_order_hold_refused', 9, [3, 4]]]);
});

test('approving a free checkout counts its reserved promo uses as used', function (): void {
    $db = holdsDb();
    $calls = [];
    $di = holdsContainer($db, $calls);
    $db->executeStatement('CREATE TABLE promo_redemption (id INTEGER PRIMARY KEY, client_order_id INTEGER, invoice_id INTEGER, status TEXT, committed_at TEXT, updated_at TEXT)');
    $db->insert('client_order', ['id' => 3, 'client_id' => 9, 'product_id' => 1, 'title' => 'Order 3', 'status' => 'pending_setup']);
    Holds::add($db, [3], 9, null, Holds::REASON_NETWORK, '');
    $db->insert('promo_redemption', ['id' => 1, 'client_order_id' => 3, 'invoice_id' => null, 'status' => 'reserved']);
    $db->insert('promo_redemption', ['id' => 2, 'client_order_id' => 1, 'invoice_id' => 5, 'status' => 'reserved']);

    Holds::approve($di, (int) $db->fetchOne('SELECT id FROM client_order_hold WHERE client_order_id = 3'), 3);

    expect($db->fetchAllKeyValue('SELECT id, status FROM promo_redemption ORDER BY id'))->toBe([1 => 'committed', 2 => 'reserved'])
        ->and($db->fetchOne('SELECT committed_at FROM promo_redemption WHERE id = 1'))->not->toBeNull()
        ->and($calls['activateOrder'])->toBe([3]);
});

test('one checkout held by several checks is one hold: the first reason, the others in the note', function (): void {
    expect(Holds::combine(null, null, null))->toBeNull()
        ->and(Holds::combine(null, ['reason' => 'fraud', 'note' => 'MaxMind 45 / 20'], ['reason' => 'id_document', 'note' => '']))->toBe(['reason' => 'fraud', 'note' => 'MaxMind 45 / 20 [id_document]'])
        ->and(Holds::combine(['reason' => 'network', 'note' => 'HETZNER-AS (AS24940)'], ['reason' => 'fraud', 'note' => 'MaxMind 45 / 20'], null))->toBe(['reason' => 'network', 'note' => 'HETZNER-AS (AS24940) [fraud] MaxMind 45 / 20']);
});

test('an approved ID document releases only the checkouts that wait for nothing else', function (): void {
    // ClientFence: Client/IdDocuments.php; order 3 waits only for the ID, order 4 also for its network.
    $db = holdsDb();
    $calls = [];
    $di = holdsContainer($db, $calls);
    $db->executeStatement('CREATE TABLE promo_redemption (id INTEGER PRIMARY KEY, client_order_id INTEGER, invoice_id INTEGER, status TEXT, committed_at TEXT, updated_at TEXT)');
    foreach ([3, 4] as $id) {
        $db->insert('client_order', ['id' => $id, 'client_id' => 9, 'product_id' => 1, 'title' => 'Order ' . $id, 'status' => 'pending_setup']);
    }
    Holds::add($db, [3], 9, null, Holds::REASON_ID_DOCUMENT, '');
    $db->insert('client_order_hold', ['client_order_id' => 4, 'client_id' => 9, 'invoice_id' => null, 'reason' => 'network', 'note' => 'HETZNER-AS (AS24940) [id_document]', 'status' => 'held', 'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00']);

    ClientFence\Mod\Client\IdDocuments::releaseHolds($di, 9, 3);

    expect($db->fetchAllKeyValue('SELECT client_order_id, status FROM client_order_hold ORDER BY client_order_id'))->toBe([1 => 'held', 2 => 'held', 3 => 'approved', 4 => 'held'])
        ->and($calls['activateOrder'])->toBe([3]);
});
