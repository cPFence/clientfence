<?php

declare(strict_types=1);

/**
 * ClientFence: client upgrades and downgrades (Order\Upgrades) on an in-memory SQLite copy of the
 * tables they use, with fake invoice, client, order and hosting services. Every amount was worked
 * out apart from the code (by hand, with the same rules as ProrationTest), for monthly,
 * yearly and mixed cycles, leap years, month ends, prepaid periods, currencies and quantities.
 *
 * Catalog (USD prices; EUR = 0.9 USD):
 * - 1 Starter   1M 5,  1Y 48   upgrades: 2, 3, 6 (license), 7 (one time), 8, 4 (retired)
 * - 2 Pro       1M 45, 1Y 300  upgrades: 1, 3
 * - 3 Business  1M 85, 1Y 600  upgrades: 1, 2
 * - 4 Retired   1M 10 (disabled)
 * - 6 License   1M 50 (another type)
 * - 7 One time  (not recurring)
 * - 8 Starter+  1M 5 (same price as Starter)
 * - 20 Host S   1Y 100 hosting, server 1, plan 7      upgrades: 21, 22, 23
 * - 21 Host M   1Y 200 hosting, server 1, plan 8
 * - 22 Host X   1Y 400 hosting, server 2 (another server)
 * - 23 Host R   1Y 300 hosting, server 1, reseller
 */

use ClientFence\Mod\Order\ActionFailures;
use ClientFence\Mod\Order\Upgrades;
use ClientFence\Mod\Product\Options;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use ClientFence\InformationException;

const UPGRADES_NOW = '2026-10-16 09:00:00';

function upgradesDb(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
        'CREATE TABLE product (id INTEGER PRIMARY KEY, title TEXT, type TEXT, status TEXT, is_addon INTEGER DEFAULT 0, config TEXT, upgrades TEXT, product_payment_id INTEGER)',
        'CREATE TABLE product_payment (id INTEGER PRIMARY KEY, type TEXT, w_price REAL DEFAULT 0, m_price REAL DEFAULT 0, q_price REAL DEFAULT 0, b_price REAL DEFAULT 0, a_price REAL DEFAULT 0, bia_price REAL DEFAULT 0, tria_price REAL DEFAULT 0,'
            . ' w_enabled INTEGER DEFAULT 0, m_enabled INTEGER DEFAULT 0, q_enabled INTEGER DEFAULT 0, b_enabled INTEGER DEFAULT 0, a_enabled INTEGER DEFAULT 0, bia_enabled INTEGER DEFAULT 0, tria_enabled INTEGER DEFAULT 0)',
        'CREATE TABLE currency (code TEXT PRIMARY KEY, conversion_rate REAL)',
        'CREATE TABLE client_order_meta (id INTEGER PRIMARY KEY, client_order_id INTEGER, name TEXT, value TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE client (id INTEGER PRIMARY KEY, currency TEXT)',
        'CREATE TABLE client_order (id INTEGER PRIMARY KEY, client_id INTEGER, product_id INTEGER, service_id INTEGER, service_type TEXT, title TEXT, status TEXT, period TEXT, price REAL, quantity INTEGER DEFAULT 1,'
            . ' discount REAL, currency TEXT, expires_at TEXT, unpaid_invoice_id INTEGER, config TEXT, updated_at TEXT)',
        'CREATE TABLE invoice (id INTEGER PRIMARY KEY, client_id INTEGER, status TEXT, hash TEXT, serie TEXT, nr TEXT, updated_at TEXT, taxrate REAL, tax_adjust REAL DEFAULT 0, paid_at TEXT, created_at TEXT)',
        'CREATE TABLE invoice_item (id INTEGER PRIMARY KEY, invoice_id INTEGER, type TEXT, task TEXT, rel_id TEXT, title TEXT, price REAL, quantity INTEGER, unit TEXT, period TEXT, taxed INTEGER)',
        'CREATE TABLE subscription (id INTEGER PRIMARY KEY, rel_type TEXT, rel_id INTEGER, status TEXT, sid TEXT, pay_gateway_id INTEGER)',
        'CREATE TABLE client_balance (id INTEGER PRIMARY KEY, client_id INTEGER, amount REAL, description TEXT, type TEXT, rel_id TEXT)',
        'CREATE TABLE ' . ActionFailures::TABLE . ' (id INTEGER PRIMARY KEY, client_order_id INTEGER NOT NULL, action TEXT NOT NULL, message TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 1,'
            . ' status TEXT NOT NULL DEFAULT \'open\', first_failed_at TEXT NOT NULL, last_failed_at TEXT NOT NULL, resolved_at TEXT, resolved_by INTEGER)',
        'CREATE TABLE ' . Upgrades::TABLE . ' (id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL, client_id INTEGER NOT NULL, kind TEXT NOT NULL, from_product_id INTEGER NOT NULL, from_title TEXT NOT NULL DEFAULT \'\','
            . ' from_period TEXT NOT NULL, from_price REAL NOT NULL, from_expires_at TEXT NOT NULL, to_product_id INTEGER NOT NULL, to_title TEXT NOT NULL DEFAULT \'\', to_period TEXT NOT NULL, to_price REAL NOT NULL,'
            . ' to_expires_at TEXT NOT NULL, days_left INTEGER NOT NULL, days_total INTEGER NOT NULL, credit REAL NOT NULL, charge REAL NOT NULL, amount REAL NOT NULL, currency TEXT NOT NULL DEFAULT \'\','
            . ' invoice_id INTEGER, status TEXT NOT NULL DEFAULT \'pending\', error TEXT, created_at TEXT NOT NULL, claimed_at TEXT, applied_at TEXT)',
        'CREATE TABLE ' . Upgrades::RULES . ' (scope TEXT NOT NULL, ref_id INTEGER NOT NULL, upgrade INTEGER, downgrade INTEGER, updated_at TEXT NOT NULL, PRIMARY KEY (scope, ref_id))',
    ] as $sql) {
        $db->executeStatement($sql);
    }

    $product = function (int $id, string $title, array $prices, array $more = []) use ($db): void {
        $payment = ['id' => $id, 'type' => $more['payment'] ?? 'recurrent'];
        foreach ($prices as $key => $price) {
            $payment["{$key}_price"] = $price;
            $payment["{$key}_enabled"] = 1;
        }
        $db->insert('product_payment', $payment);
        $db->insert('product', [
            'id' => $id, 'title' => $title, 'type' => $more['type'] ?? 'custom', 'status' => $more['status'] ?? 'enabled',
            'is_addon' => $more['is_addon'] ?? 0, 'config' => json_encode($more['config'] ?? []),
            'upgrades' => isset($more['upgrades']) ? json_encode(array_map('strval', $more['upgrades'])) : null, 'product_payment_id' => $id,
        ]);
    };
    $product(1, 'Starter', ['m' => 5, 'a' => 48], ['upgrades' => [2, 3, 6, 7, 8, 4]]);
    $product(2, 'Pro', ['m' => 45, 'a' => 300], ['upgrades' => [1, 3]]);
    $product(3, 'Business', ['m' => 85, 'a' => 600], ['upgrades' => [1, 2]]);
    $product(4, 'Retired', ['m' => 10], ['status' => 'disabled']);
    $product(6, 'License', ['m' => 50], ['type' => 'license']);
    $product(7, 'One time', [], ['payment' => 'once']);
    $product(8, 'Starter+', ['m' => 5]);
    $product(20, 'Host S', ['a' => 100], ['type' => 'hosting', 'config' => ['server_id' => 1, 'hosting_plan_id' => 7], 'upgrades' => [21, 22, 23]]);
    $product(21, 'Host M', ['a' => 200], ['type' => 'hosting', 'config' => ['server_id' => 1, 'hosting_plan_id' => 8]]);
    $product(22, 'Host X', ['a' => 400], ['type' => 'hosting', 'config' => ['server_id' => 2, 'hosting_plan_id' => 9]]);
    $product(23, 'Host R', ['a' => 300], ['type' => 'hosting', 'config' => ['server_id' => 1, 'hosting_plan_id' => 10, 'reseller' => true]]);

    $db->insert('currency', ['code' => 'USD', 'conversion_rate' => 1]);
    $db->insert('currency', ['code' => 'EUR', 'conversion_rate' => 0.9]);
    $db->insert('client', ['id' => 5, 'currency' => 'USD']);
    $db->insert('client', ['id' => 6, 'currency' => 'EUR']);

    $order = fn (int $id, array $row) => $db->insert('client_order', $row + [
        'id' => $id, 'client_id' => 5, 'service_type' => 'custom', 'status' => 'active', 'quantity' => 1, 'discount' => null,
        'currency' => 'USD', 'unpaid_invoice_id' => null, 'config' => '{}', 'service_id' => 100 + $id,
    ]);
    $order(1, ['product_id' => 1, 'title' => 'Starter', 'period' => '1M', 'price' => 5, 'expires_at' => '2026-11-01 12:00:00']);
    $order(2, ['product_id' => 3, 'title' => 'Business', 'period' => '1Y', 'price' => 600, 'expires_at' => '2027-03-01 00:00:00']);
    $order(3, ['product_id' => 2, 'title' => 'Pro', 'period' => '1M', 'price' => 45, 'expires_at' => '2026-11-10 00:00:00']);
    $order(4, ['client_id' => 6, 'product_id' => 1, 'title' => 'Starter', 'period' => '1M', 'price' => 4.5, 'quantity' => 3, 'currency' => 'EUR', 'expires_at' => '2026-11-01 00:00:00']);
    $order(5, ['product_id' => 20, 'service_type' => 'hosting', 'title' => 'Host S example.com', 'period' => '1Y', 'price' => 100, 'expires_at' => '2027-01-01 00:00:00', 'config' => json_encode(['server_id' => 1, 'hosting_plan_id' => 7, 'domain' => 'example.com'])]);
    $order(6, ['product_id' => 4, 'title' => 'Retired', 'period' => '1M', 'price' => 10, 'expires_at' => '2026-11-01 00:00:00']);
    // Each service's paid invoice (a priced service with none is changed by staff): ids 9001+, paid long ago.
    foreach ($db->fetchAllAssociative('SELECT id, client_id, price, quantity FROM client_order') as $row) {
        $db->insert('invoice', ['id' => 9000 + (int) $row['id'], 'client_id' => $row['client_id'], 'status' => 'paid', 'serie' => 'PAID-', 'taxrate' => 0, 'paid_at' => '2025-01-01 00:00:00']);
        $db->insert('invoice_item', ['invoice_id' => 9000 + (int) $row['id'], 'type' => 'order', 'rel_id' => (string) $row['id'], 'title' => 'Paid', 'price' => $row['price'], 'quantity' => $row['quantity'], 'taxed' => 0]);
    }

    return $db;
}

/** A RedBean-like facade over the SQLite tables (load, store, getExistingModelById). */
final class UpgradesTestDb
{
    /** @var list<array{int, int}> hosting plan changes: [order id, plan id] */
    public array $planChanges = [];

    public function __construct(private readonly Connection $db)
    {
    }

    public function load(string $type, int $id): ?object
    {
        $table = ['ClientOrder' => 'client_order', 'Client' => 'client', 'Invoice' => 'invoice', 'InvoiceItem' => 'invoice_item'][$type];
        $row = $this->db->fetchAssociative("SELECT * FROM {$table} WHERE id = ?", [$id]);
        if ($row === false) {
            return null;
        }
        $class = 'Model_' . $type;
        $model = new $class();
        $model->loadBean(new Tests\Helpers\DummyBean());
        foreach ($row as $key => $value) {
            $model->{$key} = $value;
        }

        return $model;
    }

    public function find(string $type, string $sql, array $params): array
    {
        $params = array_combine(array_map(fn (int|string $key): string => ltrim((string) $key, ':'), array_keys($params)), array_values($params));

        return array_map(fn (mixed $id): ?object => $this->load($type, (int) $id), $this->db->fetchFirstColumn('SELECT id FROM invoice_item WHERE ' . $sql, $params));
    }

    public function getExistingModelById(string $type, int $id, string $message = ''): object
    {
        if ($type === 'ServiceHostingHp') {
            $hp = new Model_ServiceHostingHp();
            $hp->loadBean(new Tests\Helpers\DummyBean());
            $hp->id = $id;

            return $hp;
        }

        return $this->load($type, $id) ?? throw new InformationException($message);
    }

    public function store(object $model): int
    {
        $row = [];
        foreach (['product_id', 'title', 'price', 'period', 'expires_at', 'config', 'updated_at', 'status', 'unpaid_invoice_id'] as $key) {
            $row[$key] = $model->{$key};
        }
        $this->db->update('client_order', $row, ['id' => (int) $model->id]);

        return (int) $model->id;
    }
}

/**
 * @param array<string, string> $orderConfig Orders settings (upgrades_enabled, downgrades_enabled)
 * @param array{panel?: ?string, funds?: ?string, tax_rate?: ?float, inclusive?: bool} $fail errors the panel or the credit throw, the client's tax rate, prices that include tax
 */
function upgradesContainer(Connection $db, array $orderConfig = [], array $fail = []): Pimple\Container
{
    $di = new Pimple\Container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;
    $facade = new UpgradesTestDb($db);
    $di['db'] = $facade;
    $di['mod_config'] = $di->protect(fn (string $mod): array => $mod === 'order' ? $orderConfig : []);
    $di['logger'] = new class {
        public function __call(string $name, array $args): void
        {
        }
    };
    $di['events'] = new ArrayObject();
    $di['events_manager'] = fn ($di) => new class($di['events']) {
        public function __construct(private readonly ArrayObject $events)
        {
        }

        public function fire(array $event): void
        {
            $this->events->append($event);
        }
    };
    $di['status_notes'] = new ArrayObject();
    $productService = Mockery::mock(ClientFence\Mod\Product\Service::class);
    $productService->shouldReceive('isStockAvailable')->byDefault()->andReturn(true);
    $productService->shouldReceive('reduceStock')->byDefault()->andReturn(true);
    $productService->shouldReceive('returnStock')->byDefault()->andReturnNull();
    $services = [
        'product' => $productService,
        'invoice_subscription' => Mockery::mock(ClientFence\Mod\Invoice\ServiceSubscription::class),
        'invoice' => new class($db, $di, $fail['tax_rate'] ?? 14.0, $fail['inclusive'] ?? false) {
            public function __construct(private readonly Connection $db, private readonly Pimple\Container $di, private readonly float $taxRate, private readonly bool $inclusive)
            {
            }

            public function pricesIncludeTax(): bool
            {
                return $this->inclusive;
            }

            public function prepareInvoice(Model_Client $client, array $data): Model_Invoice
            {
                // Numbered below the fixture's paid invoices (9001+).
                $id = (int) $this->db->fetchOne('SELECT IFNULL(MAX(id), 0) + 1 FROM invoice WHERE id < 9000');
                $this->db->insert('invoice', ['id' => $id, 'client_id' => (int) $client->id, 'status' => 'unpaid', 'hash' => 'h' . bin2hex(random_bytes(4)), 'serie' => 'LNK-', 'taxrate' => $this->taxRate]);
                $this->db->update('invoice', ['nr' => (string) (420 + $id)], ['id' => $id]);
                foreach ($data['items'] as $item) {
                    $this->db->insert('invoice_item', ['invoice_id' => $id] + $item + ['taxed' => 0]);
                }
                $invoice = new Model_Invoice();
                $invoice->loadBean(new Tests\Helpers\DummyBean());
                $invoice->id = $id;
                $invoice->hash = $this->db->fetchOne('SELECT hash FROM invoice WHERE id = ?', [$id]);

                return $invoice;
            }

            public function creditNote(int $sourceId, string $title, float $net, bool $taxed, string $note, ?int $orderId = null): int
            {
                $id = (int) $this->db->fetchOne('SELECT IFNULL(MAX(id), 0) + 1 FROM invoice WHERE id < 9000');
                $this->db->insert('invoice', ['id' => $id, 'client_id' => 5, 'status' => 'refunded', 'serie' => 'CN-', 'nr' => 'from ' . $sourceId, 'taxrate' => $this->db->fetchOne('SELECT taxrate FROM invoice WHERE id = ?', [$sourceId])]);
                $this->db->insert('invoice_item', ['invoice_id' => $id, 'type' => 'custom', 'rel_id' => $orderId, 'title' => $title, 'price' => -$net, 'quantity' => 1, 'taxed' => (int) $taxed]);

                return $id;
            }

            public function getTotalWithTax(Model_Invoice $invoice): float
            {
                $service = new ClientFence\Mod\Invoice\Service();
                $service->setDi($this->di);

                return $service->getTotalWithTax($invoice);
            }
        },
        'invoice_invoiceitem' => new ClientFence\Mod\Invoice\ServiceInvoiceItem(),
        'client' => new class($db, $fail['funds'] ?? null, $fail['tax_rate'] ?? null) {
            public function __construct(private readonly Connection $db, private readonly ?string $fail, private readonly ?float $taxRate)
            {
            }

            public function isClientTaxable(Model_Client $client): bool
            {
                return $this->taxRate !== null;
            }

            public function addFunds(Model_Client $client, float $amount, string $description, array $data): bool
            {
                if ($this->fail !== null) {
                    throw new RuntimeException($this->fail);
                }
                $this->db->insert('client_balance', ['client_id' => (int) $client->id, 'amount' => $amount, 'description' => $description, 'type' => $data['type'], 'rel_id' => (string) $data['rel_id']]);

                return true;
            }
        },
        'invoice_tax' => new class($fail['tax_rate'] ?? null) {
            public function __construct(private readonly ?float $rate)
            {
            }

            public function getTaxRateForClient(Model_Client $client): float
            {
                return (float) $this->rate;
            }

            public function getLocationTaxRate(Model_Client $client): float
            {
                return 20.0;
            }
        },
        'order' => new class($di['status_notes']) {
            public function __construct(private readonly ArrayObject $notes)
            {
            }

            public function saveStatusChange(Model_ClientOrder $order, string $notes): void
            {
                $this->notes->append([(int) $order->id, $notes]);
            }

            public function getOrderService(Model_ClientOrder $order): Model_ServiceHosting
            {
                $service = new Model_ServiceHosting();
                $service->loadBean(new Tests\Helpers\DummyBean());
                $service->id = (int) $order->service_id;

                return $service;
            }
        },
        'servicehosting' => new class($facade, $fail['panel'] ?? null) {
            public function __construct(private readonly UpgradesTestDb $facade, public ?string $fail)
            {
            }

            public function changeAccountPlan(Model_ClientOrder $order, Model_ServiceHosting $service, Model_ServiceHostingHp $hp): bool
            {
                if ($this->fail !== null) {
                    throw new RuntimeException($this->fail);
                }
                $this->facade->planChanges[] = [(int) $order->id, (int) $hp->id];

                return true;
            }
        },
        'system' => new class {
            public function getParamValue(string $param): ?string
            {
                return $param === 'invoice_number_padding' ? '3' : null;
            }
        },
        'email' => new class {
            public function sendTemplate(array $data): bool
            {
                return true;
            }
        },
    ];
    $services['invoice_subscription']->shouldReceive('cancelForOrder')->byDefault()->andReturn(0);
    $di['services'] = $services;
    $di['mod_service'] = $di->protect(fn (string $name, string $sub = '') => $services[strtolower($sub === '' ? $name : $name . '_' . $sub)]);
    $di['url'] = new class {
        public function adminLink(string $path): string
        {
            return '/admin/' . $path;
        }
    };

    return $di;
}

function upgradesOrder(Pimple\Container $di, int $id): Model_ClientOrder
{
    return $di['db']->load('ClientOrder', $id);
}

/** The options of an order keyed "product/period" => [kind, credit, charge, amount, new expiry]. */
function upgradesOptionTable(Pimple\Container $di, int $orderId, string $now = UPGRADES_NOW): array
{
    $table = [];
    foreach (Upgrades::options($di, upgradesOrder($di, $orderId), $now)['options'] as $o) {
        $table[$o['product_id'] . '/' . $o['period']] = [$o['kind'], $o['credit'], $o['charge'], $o['amount'], $o['new_expires_at']];
    }

    return $table;
}

function upgradesPay(Connection $db, int $upgradeId): void
{
    $db->executeStatement('UPDATE invoice SET status = ? WHERE id = (SELECT invoice_id FROM ' . Upgrades::TABLE . ' WHERE id = ?)', ['paid', $upgradeId]);
}

// ---------------------------------------------------------------------------------------------
// Options and prices
// ---------------------------------------------------------------------------------------------

test('a monthly Starter lists its yearly cycle and the listed products, each priced', function (): void {
    $di = upgradesContainer(upgradesDb());
    // 1 Oct to 1 Nov: 31 days, 16 left on 16 Oct; credit 5 × 16 / 31 = 2.58.
    expect(upgradesOptionTable($di, 1))->toBe([
        '1/1Y' => ['upgrade', 2.58, 48.0, 45.42, '2027-10-16 09:00:00'],
        // Same cycle: the new price for the days left, and the date stays.
        '8/1M' => ['upgrade', 2.58, 2.58, 0.0, '2026-11-01 12:00:00'],
        '2/1Y' => ['upgrade', 2.58, 300.0, 297.42, '2027-10-16 09:00:00'],
        '2/1M' => ['upgrade', 2.58, 23.23, 20.65, '2026-11-01 12:00:00'],
        '3/1Y' => ['upgrade', 2.58, 600.0, 597.42, '2027-10-16 09:00:00'],
        '3/1M' => ['upgrade', 2.58, 43.87, 41.29, '2026-11-01 12:00:00'],
    ]);
    // Not offered: 4 is not on sale, 6 is another type, 7 does not recur.
    $options = Upgrades::options($di, upgradesOrder($di, 1), UPGRADES_NOW);
    expect($options['available'])->toBeTrue()
        ->and($options['current'])->toMatchArray(['product_id' => 1, 'period' => '1M', 'price' => 5.0])
        ->and($options['options'][0])->toMatchArray(['title' => 'Starter', 'same_product' => true, 'same_cycle' => false, 'days_left' => 16, 'days_total' => 31]);
});

test('a sold-out target is omitted and a product change to it is refused', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    $di['services']['product']->shouldReceive('isStockAvailable')->with(2, 3)->andReturn(false);
    $order = upgradesOrder($di, 4);

    expect(array_column(Upgrades::options($di, $order, UPGRADES_NOW)['options'], 'product_id'))->not->toContain(2);
    expect(fn () => Upgrades::request($di, $order, 2, '1M', UPGRADES_NOW))->toThrow(InformationException::class, 'This change is not available');
    expect($db->fetchOne('SELECT COUNT(*) FROM ' . Upgrades::TABLE))->toBe(0);
});

test('a downloadable product change replaces the service file with the target file', function (): void {
    $db = upgradesDb();
    $db->executeStatement('CREATE TABLE service_downloadable (id INTEGER PRIMARY KEY, filename TEXT, stored_filename TEXT)');
    $oldFile = ['filename' => 'starter.zip', 'stored_filename' => str_repeat('a', 64)];
    $newFile = ['filename' => 'starter-plus.zip', 'stored_filename' => str_repeat('b', 64)];
    $db->insert('service_downloadable', ['id' => 101] + $oldFile);
    $db->update('product', ['type' => 'downloadable', 'config' => json_encode($oldFile)], ['id' => 1]);
    $db->update('product', ['type' => 'downloadable', 'config' => json_encode($newFile)], ['id' => 8]);
    $db->update('client_order', ['service_type' => 'downloadable', 'config' => json_encode($oldFile)], ['id' => 1]);
    $di = upgradesContainer($db);

    expect(Upgrades::request($di, upgradesOrder($di, 1), 8, '1M', UPGRADES_NOW)['status'])->toBe(Upgrades::STATUS_APPLIED)
        ->and($db->fetchAssociative('SELECT filename, stored_filename FROM service_downloadable WHERE id = 101'))->toBe($newFile);
});

test('a license product change replaces validation settings in the service and order', function (array $targetConfig, array $expected): void {
    $db = upgradesDb();
    $db->executeStatement('CREATE TABLE service_license (id INTEGER PRIMARY KEY, validate_ip INTEGER, validate_host INTEGER, validate_path INTEGER, validate_version INTEGER, plugin TEXT)');
    $oldConfig = ['validate_ip' => true, 'validate_host' => true, 'validate_path' => true, 'validate_version' => true, 'plugin' => 'Old'];
    $db->insert('service_license', ['id' => 101, 'validate_ip' => 1, 'validate_host' => 1, 'validate_path' => 1, 'validate_version' => 1, 'plugin' => 'Old']);
    $db->update('product', ['type' => 'license', 'config' => json_encode($oldConfig)], ['id' => 1]);
    $db->update('product', ['type' => 'license', 'config' => json_encode($targetConfig)], ['id' => 8]);
    $db->update('client_order', ['service_type' => 'license', 'config' => json_encode($oldConfig)], ['id' => 1]);
    $di = upgradesContainer($db);

    expect(Upgrades::request($di, upgradesOrder($di, 1), 8, '1M', UPGRADES_NOW)['status'])->toBe(Upgrades::STATUS_APPLIED)
        ->and($db->fetchAssociative('SELECT validate_ip, validate_host, validate_path, validate_version, plugin FROM service_license WHERE id = 101'))
        ->toBe(array_map(fn (bool|string $value): int|string => is_bool($value) ? (int) $value : $value, $expected))
        ->and(json_decode($db->fetchOne('SELECT config FROM client_order WHERE id = 1'), true))->toMatchArray($expected);
})->with([
    'target settings' => [
        ['validate_ip' => false, 'validate_host' => true, 'validate_path' => false, 'validate_version' => false, 'plugin' => 'Other'],
        ['validate_ip' => false, 'validate_host' => true, 'validate_path' => false, 'validate_version' => false, 'plugin' => 'Other'],
    ],
    'target defaults' => [
        [],
        ['validate_ip' => false, 'validate_host' => false, 'validate_path' => false, 'validate_version' => false, 'plugin' => 'Simple'],
    ],
]);

test('a yearly Business mid-period: every choice is a downgrade with account credit', function (): void {
    $di = upgradesContainer(upgradesDb());
    // 1 Mar 2026 to 1 Mar 2027: 365 days, 136 left; credit 600 × 136 / 365 = 223.56.
    expect(upgradesOptionTable($di, 2))->toBe([
        '3/1M' => ['downgrade', 223.56, 85.0, -138.56, '2026-11-16 09:00:00'],
        '1/1Y' => ['downgrade', 223.56, 17.88, -205.68, '2027-03-01 00:00:00'],
        '1/1M' => ['downgrade', 223.56, 5.0, -218.56, '2026-11-16 09:00:00'],
        '2/1Y' => ['downgrade', 223.56, 111.78, -111.78, '2027-03-01 00:00:00'],
        '2/1M' => ['downgrade', 223.56, 45.0, -178.56, '2026-11-16 09:00:00'],
    ]);
});

test('kinds compare products on one cycle: a cheaper product on a dearer cycle is a downgrade that costs money', function (): void {
    $di = upgradesContainer(upgradesDb());
    // Pro monthly, 25 of 31 days left: credit 45 × 25 / 31 = 36.29.
    expect(upgradesOptionTable($di, 3))->toBe([
        // Pro yearly: same product, pays more now: an upgrade.
        '2/1Y' => ['upgrade', 36.29, 300.0, 263.71, '2027-10-16 09:00:00'],
        // Starter yearly costs 11.71 now, yet Starter is cheaper than Pro on every cycle.
        '1/1Y' => ['downgrade', 36.29, 48.0, 11.71, '2027-10-16 09:00:00'],
        '1/1M' => ['downgrade', 36.29, 4.03, -32.26, '2026-11-10 00:00:00'],
        '3/1Y' => ['upgrade', 36.29, 600.0, 563.71, '2027-10-16 09:00:00'],
        '3/1M' => ['upgrade', 36.29, 68.55, 32.26, '2026-11-10 00:00:00'],
    ]);
});

test('prices follow the order currency and quantity', function (): void {
    $di = upgradesContainer(upgradesDb());
    // EUR (0.9) × 3: Starter monthly 13.50, Pro monthly 121.50, Pro yearly 810.
    $table = upgradesOptionTable($di, 4);
    expect($table['2/1M'])->toBe(['upgrade', 6.97, 62.71, 55.74, '2026-11-01 00:00:00'])
        ->and($table['2/1Y'])->toBe(['upgrade', 6.97, 810.0, 803.03, '2027-10-16 09:00:00'])
        ->and(Upgrades::options($di, upgradesOrder($di, 4), UPGRADES_NOW)['current']['price'])->toBe(13.5);
});

test('a cycle left enabled at 0 is never offered', function (): void {
    $db = upgradesDb();
    // FOSSBilling's default: every cycle enabled, priced 0.
    $db->update('product_payment', ['w_enabled' => 1, 'q_enabled' => 1, 'q_price' => 0], ['id' => 2]);
    $db->update('product_payment', ['w_enabled' => 1], ['id' => 1]);
    $di = upgradesContainer($db);

    expect(array_keys(upgradesOptionTable($di, 1)))->toBe(['1/1Y', '8/1M', '2/1Y', '2/1M', '3/1Y', '3/1M']);
});

// ClientFence (QA r4 F-05): paid ahead, a downgrade credited days of several invoices that a refund cannot tell apart.
test('an order paid ahead can change, credited for every day left', function (): void {
    $db = upgradesDb();
    $db->update('client_order', ['expires_at' => '2027-01-16 00:00:00'], ['id' => 1]);
    $di = upgradesContainer($db);

    expect(Upgrades::blocker($di, upgradesOrder($di, 1), UPGRADES_NOW))->toBeNull()
        ->and(Upgrades::options($di, upgradesOrder($di, 1), UPGRADES_NOW))->toMatchArray(['available' => true]);
});

test('leap years, month ends and longer cycles', function (string $now, string $period, string $expires, float $price, string $to, array $want): void {
    $db = upgradesDb();
    $db->update('client_order', ['period' => $period, 'expires_at' => $expires, 'price' => $price], ['id' => 1]);
    $db->update('invoice_item', ['price' => $price], ['invoice_id' => 9001]);
    $db->update('product_payment', ['q_price' => 30, 'q_enabled' => 1, 'bia_price' => 1000, 'bia_enabled' => 1], ['id' => 1]);
    $di = upgradesContainer($db);

    expect(upgradesOptionTable($di, 1, $now)[$to])->toBe($want);
})->with([
    // 1 Mar 2027 to 1 Mar 2028 has 29 Feb: 366 days.
    'leap year, same cycle' => ['2028-02-28 10:00:00', '1Y', '2028-03-01 00:00:00', 48, '2/1Y', ['upgrade', 0.26, 1.64, 1.38, '2028-03-01 00:00:00']],
    'leap day, to monthly' => ['2028-02-29 10:00:00', '1Y', '2028-03-01 00:00:00', 48, '2/1M', ['upgrade', 0.13, 45.0, 44.87, '2028-03-29 10:00:00']],
    // A month ending 31 March started 28 February.
    'month end, same cycle' => ['2026-03-15 10:00:00', '1M', '2026-03-31 00:00:00', 5, '2/1M', ['upgrade', 2.58, 23.23, 20.65, '2026-03-31 00:00:00']],
    'month end, to yearly' => ['2026-03-15 10:00:00', '1M', '2026-03-31 00:00:00', 5, '2/1Y', ['upgrade', 2.58, 300.0, 297.42, '2027-03-15 10:00:00']],
    'quarterly to yearly' => [UPGRADES_NOW, '3M', '2026-12-31 00:00:00', 30, '2/1Y', ['upgrade', 24.78, 300.0, 275.22, '2027-10-16 09:00:00']],
    // Business is the dearer product on every cycle: an upgrade, though the old price was high
    // enough to leave a credit.
    'two years to monthly' => [UPGRADES_NOW, '2Y', '2027-06-30 00:00:00', 1000, '3/1M', ['upgrade', 352.05, 85.0, -267.05, '2026-11-16 09:00:00']],
]);

test('a retired product moves to its listed products but not to its own cycles', function (): void {
    $db = upgradesDb();
    $db->update('product', ['upgrades' => json_encode(['1'])], ['id' => 4]);
    $di = upgradesContainer($db);

    expect(array_keys(upgradesOptionTable($di, 6)))->toBe(['1/1Y', '1/1M']);
    $db->update('product', ['upgrades' => null], ['id' => 4]);
    expect(Upgrades::options($di, upgradesOrder($di, 6), UPGRADES_NOW))->toMatchArray(['available' => false, 'reason' => 'There is nothing to change this service to.']);
});

test('hosting moves only within its server and reseller kind', function (): void {
    $di = upgradesContainer(upgradesDb());
    // 365 days, 77 left: credit 100 × 77 / 365 = 21.10, Host M for the days left 42.19.
    expect(upgradesOptionTable($di, 5))->toBe(['21/1Y' => ['upgrade', 21.1, 42.19, 21.09, '2027-01-01 00:00:00']])
        ->and(Upgrades::compatible(['type' => 'hosting', 'is_addon' => false, 'config' => ['server_id' => 1]], ['type' => 'hosting', 'is_addon' => false, 'config' => ['server_id' => 1]]))->toBeFalse()
        ->and(Upgrades::compatible(['type' => 'custom', 'is_addon' => true, 'config' => []], ['type' => 'custom', 'is_addon' => false, 'config' => []]))->toBeFalse();
});

// ---------------------------------------------------------------------------------------------
// Who may
// ---------------------------------------------------------------------------------------------

test('the settings, then the service rule, then the product rule decide', function (array $settings, array $product, array $order, string $kind, bool $allowed): void {
    expect(Upgrades::allowedBy($settings, $product, $order, $kind))->toBe($allowed);
})->with([
    'all default' => [['upgrades' => true, 'downgrades' => true], ['upgrade' => null, 'downgrade' => null], ['upgrade' => null, 'downgrade' => null], 'upgrade', true],
    'upgrades off' => [['upgrades' => false, 'downgrades' => true], ['upgrade' => true, 'downgrade' => null], ['upgrade' => true, 'downgrade' => null], 'upgrade', false],
    'downgrades off, upgrade fine' => [['upgrades' => true, 'downgrades' => false], ['upgrade' => null, 'downgrade' => null], ['upgrade' => null, 'downgrade' => null], 'upgrade', true],
    'product blocks' => [['upgrades' => true, 'downgrades' => true], ['upgrade' => false, 'downgrade' => null], ['upgrade' => null, 'downgrade' => null], 'upgrade', false],
    'service allows over product' => [['upgrades' => true, 'downgrades' => true], ['upgrade' => false, 'downgrade' => null], ['upgrade' => true, 'downgrade' => null], 'upgrade', true],
    'service blocks over product' => [['upgrades' => true, 'downgrades' => true], ['upgrade' => null, 'downgrade' => true], ['upgrade' => null, 'downgrade' => false], 'downgrade', false],
    'product rule of the other kind' => [['upgrades' => true, 'downgrades' => true], ['upgrade' => false, 'downgrade' => null], ['upgrade' => null, 'downgrade' => null], 'downgrade', true],
]);

test('settings and rules filter the options', function (): void {
    $db = upgradesDb();
    // Downgrades off: Pro monthly keeps only its upgrades.
    expect(array_keys(upgradesOptionTable(upgradesContainer($db, ['downgrades_enabled' => '0']), 3)))->toBe(['2/1Y', '3/1Y', '3/1M'])
        ->and(array_keys(upgradesOptionTable(upgradesContainer($db, ['upgrades_enabled' => '0']), 3)))->toBe(['1/1Y', '1/1M'])
        ->and(Upgrades::options(upgradesContainer($db, ['upgrades_enabled' => '0', 'downgrades_enabled' => '0']), upgradesOrder(upgradesContainer($db), 3), UPGRADES_NOW)['reason'])
        ->toBe('Upgrades and downgrades are not available.');

    $di = upgradesContainer($db);
    Upgrades::saveRule($di, Upgrades::SCOPE_PRODUCT, 2, 'block', 'default');
    expect(array_keys(upgradesOptionTable($di, 3)))->toBe(['1/1Y', '1/1M']);
    // The service's own rule wins over its product's.
    Upgrades::saveRule($di, Upgrades::SCOPE_ORDER, 3, 'allow', 'block');
    expect(array_keys(upgradesOptionTable($di, 3)))->toBe(['2/1Y', '3/1Y', '3/1M'])
        ->and(Upgrades::rule($di, Upgrades::SCOPE_ORDER, 3))->toBe(['upgrade' => true, 'downgrade' => false])
        // Another order of the same product follows the product.
        ->and(Upgrades::rule($di, Upgrades::SCOPE_ORDER, 1))->toBe(['upgrade' => null, 'downgrade' => null]);
    // Back to default: the row goes.
    Upgrades::saveRule($di, Upgrades::SCOPE_ORDER, 3, 'default', 'default');
    expect($db->fetchOne('SELECT COUNT(*) FROM ' . Upgrades::RULES . ' WHERE scope = ?', ['order']))->toBe(0)
        ->and(fn () => Upgrades::saveRule($di, 'client', 3, 'allow', 'allow'))->toThrow(InformationException::class)
        ->and(fn () => Upgrades::saveRule($di, Upgrades::SCOPE_ORDER, 3, 'yes', 'allow'))->toThrow(InformationException::class);
});

test('services that cannot change say why', function (array $change, string $reason): void {
    $db = upgradesDb();
    $db->insert('invoice', ['id' => 50, 'client_id' => 5, 'status' => 'unpaid', 'hash' => 'x']);
    $db->insert('invoice', ['id' => 51, 'client_id' => 5, 'status' => 'paid', 'hash' => 'y']);
    foreach ($change as $table => $values) {
        $db->update($table, $values, ['id' => $table === 'client' ? 5 : 1]);
    }
    $di = upgradesContainer($db);

    expect(Upgrades::options($di, upgradesOrder($di, 1), UPGRADES_NOW))->toMatchArray(['available' => false, 'reason' => $reason, 'options' => []]);
})->with([
    'suspended' => [['client_order' => ['status' => 'suspended']], 'Only active services can be changed.'],
    'a domain' => [['client_order' => ['service_type' => 'domain']], 'This kind of service cannot be changed here.'],
    'no period' => [['client_order' => ['period' => '']], 'Only services billed by period can be changed.'],
    'an unpaid invoice' => [['client_order' => ['unpaid_invoice_id' => 50]], 'Pay the open invoice of this service first.'],
    'another currency' => [['client' => ['currency' => 'EUR']], 'This service is billed in another currency. Please contact us.'],
    'no currency' => [['client' => ['currency' => null]], 'This service is billed in another currency. Please contact us.'],
]);

// ClientFence (QA r4 F-01): a downgrade after a refund credited time already paid back.
test('a paid invoice left on the order does not block', function (): void {
    $db = upgradesDb();
    $db->insert('invoice', ['id' => 51, 'client_id' => 5, 'status' => 'paid', 'hash' => 'y']);
    $db->update('client_order', ['unpaid_invoice_id' => 51], ['id' => 1]);
    $di = upgradesContainer($db);

    expect(Upgrades::options($di, upgradesOrder($di, 1), UPGRADES_NOW)['available'])->toBeTrue();
});

// ---------------------------------------------------------------------------------------------
// Requests, payment and applying
// ---------------------------------------------------------------------------------------------

test('plan change quotes include the tax on the upgrade invoice and keep net prices', function (?float $rate, string $period): void {
    $db = upgradesDb();
    $di = upgradesContainer($db, [], ['tax_rate' => $rate]);
    $quotes = Upgrades::options($di, upgradesOrder($di, 1), UPGRADES_NOW)['options'];
    $quote = array_values(array_filter($quotes, fn (array $option): bool => $option['product_id'] === 2 && $option['period'] === $period))[0];
    $net = $period === '1M' ? 20.65 : 297.42;

    $result = Upgrades::request($di, upgradesOrder($di, 1), 2, $period, UPGRADES_NOW);
    $invoiceId = (int) $db->fetchOne('SELECT invoice_id FROM ' . Upgrades::TABLE . ' WHERE id = ?', [$result['id']]);
    $invoice = $di['db']->load('Invoice', $invoiceId);

    expect($quote['amount'])->toBe($net)
        ->and($quote['tax_rate'])->toBe($rate ?? 0.0)
        ->and($quote['total'])->toBe(round($di['mod_service']('invoice')->getTotalWithTax($invoice), 2))
        ->and($quote['total'])->toBe(round($net + round($net * ($rate ?? 0.0) / 100, 2), 2))
        ->and((float) $db->fetchOne('SELECT price FROM invoice_item WHERE invoice_id = ?', [$invoiceId]))->toBe($net)
        ->and((float) $db->fetchOne('SELECT amount FROM ' . Upgrades::TABLE . ' WHERE id = ?', [$result['id']]))->toBe($net);
})->with([null, 0.0, 19.0, 8.875])->with(['1M', '1Y']);

test('with prices that include tax the quote total is the proration; a client exempt from tax pays its net', function (?float $rate): void {
    $db = upgradesDb();
    $di = upgradesContainer($db, [], ['tax_rate' => $rate, 'inclusive' => true]);
    $gross = 20.65;
    $net = round($gross * 100 / 120, 2);

    $result = Upgrades::request($di, upgradesOrder($di, 1), 2, '1M', UPGRADES_NOW);
    $invoiceId = (int) $db->fetchOne('SELECT invoice_id FROM ' . Upgrades::TABLE . ' WHERE id = ?', [$result['id']]);

    expect((float) $db->fetchOne('SELECT amount FROM ' . Upgrades::TABLE . ' WHERE id = ?', [$result['id']]))->toBe($net)
        ->and((float) $db->fetchOne('SELECT price FROM invoice_item WHERE invoice_id = ?', [$invoiceId]))->toBe($net)
        ->and(round($di['mod_service']('invoice')->getTotalWithTax($di['db']->load('Invoice', $invoiceId)), 2))->toBe($rate === null ? $net : $gross);

    // Paid the next day: the recheck compares net with net, so the change is made.
    upgradesPay($db, $result['id']);
    Upgrades::onPaid($di, $result['id'], '2026-10-17 09:00:00');
    expect($db->fetchOne('SELECT status FROM ' . Upgrades::TABLE . ' WHERE id = ?', [$result['id']]))->toBe('applied');
})->with(['taxed at 20%' => [20.0], 'exempt, location rate 20%' => [null]]);

test('monthly to yearly upgrade: invoiced, then applied once when paid', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);

    $result = Upgrades::request($di, upgradesOrder($di, 1), 2, '1y', UPGRADES_NOW);
    expect($result)->toMatchArray(['status' => 'pending', 'amount' => 297.42])
        ->and($result['invoice_hash'])->toStartWith('h');
    $item = $db->fetchAssociative('SELECT * FROM invoice_item WHERE invoice_id < 9000');
    expect($item)->toMatchArray(['type' => 'hook_call', 'task' => Upgrades::EVENT, 'rel_id' => '{"upgrade_id":' . $result['id'] . '}', 'price' => 297.42, 'quantity' => 1])
        ->and($item['title'])->toBe('Upgrade of order #1: Starter (1M) to Pro (1Y), less 2.58 USD unused (16 of 31 days)');

    // Waiting for payment: no second change, and nothing is applied before payment.
    expect(Upgrades::options($di, upgradesOrder($di, 1), UPGRADES_NOW)['reason'])->toBe('A change of this service is waiting for payment. Pay its invoice, or ask us to cancel it.');
    Upgrades::onPaid($di, $result['id'], UPGRADES_NOW);
    expect($db->fetchOne('SELECT product_id FROM client_order WHERE id = 1'))->toBe(1);

    upgradesPay($db, $result['id']);
    Upgrades::onPaid($di, $result['id'], UPGRADES_NOW);
    $order = $db->fetchAssociative('SELECT product_id, title, price, period, expires_at, config FROM client_order WHERE id = 1');
    expect($order)->toMatchArray(['product_id' => 2, 'title' => 'Pro', 'price' => 300.0, 'period' => '1Y', 'expires_at' => '2027-10-16 09:00:00'])
        ->and(json_decode($order['config'], true))->toMatchArray(['product_id' => 2, 'period' => '1Y', 'title' => 'Pro'])
        ->and($db->fetchOne('SELECT status FROM ' . Upgrades::TABLE))->toBe('applied')
        ->and($db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(0)
        ->and($di['status_notes']->getArrayCopy())->toBe([[1, 'Upgrade from Starter (1M) to Pro (1Y)']])
        ->and($di['events'][0])->toBe(['event' => 'onAfterOrderUpgrade', 'params' => ['id' => 1, 'upgrade_id' => $result['id'], 'kind' => 'upgrade', 'from_product_id' => 1, 'to_product_id' => 2]]);

    // The paid event and the cron both arriving: applied once.
    Upgrades::onPaid($di, $result['id'], UPGRADES_NOW);
    expect(Upgrades::apply($di, $result['id']))->toBeFalse()
        ->and(Upgrades::sweep($di))->toBe(0)
        ->and(count($di['status_notes']))->toBe(1)
        // The new yearly Pro can move on: to Business yearly, for the days left of the new year.
        ->and(array_keys(upgradesOptionTable($di, 1, '2026-10-17 09:00:00')))->toContain('3/1Y');
});

test('a paid plan change cancels the order subscription', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    $di['services']['invoice_subscription']->shouldReceive('cancelForOrder')->once()
        ->with(Mockery::on(fn (Model_ClientOrder $order): bool => (int) $order->id === 1))
        ->andReturn(1);

    $result = Upgrades::request($di, upgradesOrder($di, 1), 2, '1M', UPGRADES_NOW);
    upgradesPay($db, $result['id']);
    Upgrades::onPaid($di, $result['id'], UPGRADES_NOW);

    expect($db->fetchOne('SELECT status FROM ' . Upgrades::TABLE . ' WHERE id = ?', [$result['id']]))->toBe('applied');
});

test('a plan change requires a PayPal subscription to be canceled in PayPal first', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    $order = upgradesOrder($di, 1);
    $gateway = new Model_PayGateway();
    $gateway->loadBean(new Tests\Helpers\DummyBean());
    $gatewayService = Mockery::mock(ClientFence\Mod\Invoice\ServicePayGateway::class);
    $gatewayService->shouldReceive('getPaymentAdapter')->once()->with($gateway)
        ->andReturn(new Payment_Adapter_PayPalEmail(['email' => 'merchant@example.com']));
    $models = Mockery::mock(ClientFence\Database::class);
    $models->shouldReceive('getExistingModelById')->once()->with('PayGateway', 2, 'Payment gateway not found')->andReturn($gateway);
    $di['db'] = $models;
    $di['mod_service'] = $di->protect(fn () => $gatewayService);

    expect(Upgrades::blocker($di, $order, UPGRADES_NOW))->toBeNull();

    $db->insert('subscription', ['id' => 7, 'rel_type' => 'invoice', 'rel_id' => 9001, 'status' => 'active', 'sid' => 'I-PAYPAL', 'pay_gateway_id' => 2]);
    expect(Upgrades::blocker($di, $order, UPGRADES_NOW))->toBe('This service is paid by a PayPal subscription: cancel it in PayPal first, then change the plan.');

    $db->update('subscription', ['sid' => ''], ['id' => 7]);
    expect(Upgrades::blocker($di, $order, UPGRADES_NOW))->toBeNull();
});

test('a service set to be cancelled at the end of its period cannot change plan', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    $db->insert('invoice', ['id' => 70, 'client_id' => 5, 'status' => 'paid', 'taxrate' => 0, 'paid_at' => '2026-10-01 09:00:00']);
    $db->insert('invoice_item', ['invoice_id' => 70, 'type' => 'order', 'rel_id' => '2', 'title' => 'Pro', 'price' => 600, 'quantity' => 1, 'period' => '1Y', 'taxed' => 0]);
    expect(Upgrades::blocker($di, upgradesOrder($di, 2), UPGRADES_NOW))->toBeNull();

    $db->insert('client_order_meta', ['client_order_id' => 2, 'name' => ClientFence\Mod\Order\Service::META_CANCEL_AT_PERIOD_END, 'value' => '1']);
    expect(Upgrades::blocker($di, upgradesOrder($di, 2), UPGRADES_NOW))->toBe('This service is set to be cancelled at the end of its period, so it cannot be changed.');
});

test('yearly to monthly downgrade: applied at once, the difference goes to the account', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);

    expect(Upgrades::request($di, upgradesOrder($di, 2), 1, '1M', UPGRADES_NOW))->toMatchArray(['status' => 'applied', 'amount' => -218.56, 'invoice_hash' => null])
        ->and($db->fetchAssociative('SELECT product_id, title, price, period, expires_at FROM client_order WHERE id = 2'))
        ->toBe(['product_id' => 1, 'title' => 'Starter', 'price' => 5.0, 'period' => '1M', 'expires_at' => '2026-11-16 09:00:00'])
        ->and($db->fetchAssociative('SELECT client_id, amount, type, rel_id FROM client_balance'))->toBe(['client_id' => 5, 'amount' => 218.56, 'type' => 'credit', 'rel_id' => '2'])
        // No invoice to pay; the credit note from the invoice that paid the year.
        ->and($db->fetchFirstColumn('SELECT nr FROM invoice WHERE id < 9000'))->toBe(['from 9002']);
});

test('an imported invoice paid from WHMCS credit is worth what was paid, not less', function (string $title, ?string $unit): void {
    // QA r6 R-01-R1: the import's credit applied, payments received and rounding lines are payments, not promotions.
    $db = upgradesDb();
    $di = upgradesContainer($db);
    $db->insert('invoice', ['id' => 70, 'client_id' => 5, 'status' => 'paid', 'taxrate' => 0, 'paid_at' => '2026-10-01 09:00:00']);
    $db->insert('invoice_item', ['invoice_id' => 70, 'type' => 'order', 'rel_id' => '2', 'title' => 'Pro', 'price' => 600, 'quantity' => 1, 'taxed' => 0]);
    $db->insert('invoice_item', ['invoice_id' => 70, 'type' => 'custom', 'title' => $title, 'unit' => $unit, 'price' => -600, 'quantity' => 1, 'taxed' => 0]);

    Upgrades::request($di, upgradesOrder($di, 2), 1, '1M', UPGRADES_NOW);
    expect($db->fetchOne('SELECT amount FROM client_balance'))->toBe(218.56);
})->with([
    'credit applied' => [\ClientFence\Mod\Clientfence\Whmcs\Money::CREDIT_TITLE, null],
    'payments received' => ['Payments received', \ClientFence\Mod\Clientfence\Whmcs\Money::RECEIVED_UNIT],
    'rounding' => [\ClientFence\Mod\Clientfence\Whmcs\Money::ROUNDING_TITLE, null],
]);

test('the unused time comes back with the tax of the invoice that paid it, not the profile\'s rate now', function (): void {
    foreach ([[24.0, 271.01], [0.0, 218.56], ['5', 229.49]] as [$paidRate, $credit]) {
        $db = upgradesDb();
        $db->executeStatement('DELETE FROM invoice WHERE id > 9000');     // only this test's invoices
        // The profile is taxed at 24% now; the time was paid at $paidRate (a client who moved before downgrading).
        $di = upgradesContainer($db, [], ['tax_rate' => 24.0]);
        $db->insert('invoice', ['id' => 70, 'client_id' => 5, 'status' => 'paid', 'taxrate' => $paidRate, 'paid_at' => '2026-10-01 09:00:00']);
        $db->insert('invoice_item', ['invoice_id' => 70, 'type' => 'order', 'rel_id' => '2', 'title' => 'Pro', 'price' => 600, 'quantity' => 1, 'taxed' => 1]);

        $quotes = Upgrades::options($di, upgradesOrder($di, 2), UPGRADES_NOW)['options'];
        $quote = array_values(array_filter($quotes, fn (array $option): bool => $option['product_id'] === 1 && $option['period'] === '1M'))[0];
        expect($quote)->toMatchArray(['amount' => -218.56, 'total' => -$credit, 'tax_rate' => (float) $paidRate]);

        Upgrades::request($di, upgradesOrder($di, 2), 1, '1M', UPGRADES_NOW);
        // 218.56 unused (+ 52.45 tax: 218.56 x 24%, rounded per line as InvoiceItem::getTax).
        expect($db->fetchOne('SELECT amount FROM client_balance'))->toBe($credit)
            // A credit note from the paying invoice gives the tax back in the sales tax report.
            ->and($db->fetchAssociative("SELECT i.nr, ii.price, ii.taxed FROM invoice i JOIN invoice_item ii ON ii.invoice_id = i.id WHERE i.status = 'refunded'"))
            ->toBe(['nr' => 'from 70', 'price' => -218.56, 'taxed' => (float) $paidRate > 0 ? 1 : 0]);
        $invoice = $di['db']->load('Invoice', (int) $db->fetchOne("SELECT id FROM invoice WHERE status = 'refunded'"));
        expect(round($di['mod_service']('invoice')->getTotalWithTax($invoice), 2))->toBe($quote['total']);
    }
    expect(Upgrades::creditWithTax(89.90, 24.0))->toBe(111.48)
        ->and(Upgrades::creditWithTax(89.90, 0.0))->toBe(89.9);
});

// ClientFence: an upgrade invoice (a hook line, not an order line) issued at 0% after the order's taxed invoice counts too.
// ClientFence (QA r4 review): on a renewed service the credit note takes the lowest rate and names the service, so a
// refund of any of its invoices (order, renewal or upgrade) takes the credit back.
test('a downgrade credit note names its service', function (): void {
    $db = upgradesDb();
    $db->executeStatement('DELETE FROM invoice WHERE id > 9000');     // only this test's invoices
    $di = upgradesContainer($db, [], ['tax_rate' => 24.0]);
    foreach ([70 => 5.0, 75 => 24.0] as $id => $rate) {
        $db->insert('invoice', ['id' => $id, 'client_id' => 5, 'status' => 'paid', 'taxrate' => $rate, 'paid_at' => '2026-10-01 09:00:00']);
        $db->insert('invoice_item', ['invoice_id' => $id, 'type' => 'order', 'rel_id' => '2', 'title' => 'Pro', 'price' => 600, 'quantity' => 1, 'taxed' => 1]);
    }

    Upgrades::request($di, upgradesOrder($di, 2), 1, '1M', UPGRADES_NOW);

    expect($db->fetchAssociative("SELECT i.nr, ii.rel_id FROM invoice i JOIN invoice_item ii ON ii.invoice_id = i.id WHERE i.status = 'refunded'"))
        ->toBe(['nr' => 'from 70', 'rel_id' => '2']);
});

test('a downgrade after an untaxed upgrade invoice does not give back the older invoice\'s tax', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db, [], ['tax_rate' => 24.0]);
    $db->insert('invoice', ['id' => 70, 'client_id' => 5, 'status' => 'paid', 'taxrate' => 24.0, 'paid_at' => '2026-10-01 09:00:00']);
    $db->insert('invoice_item', ['invoice_id' => 70, 'type' => 'order', 'rel_id' => '2', 'title' => 'Pro', 'price' => 600, 'quantity' => 1, 'taxed' => 1]);
    $db->insert('invoice', ['id' => 71, 'client_id' => 5, 'status' => 'paid', 'taxrate' => 0.0, 'paid_at' => '2026-10-02 09:00:00']);
    $db->insert('invoice_item', ['invoice_id' => 71, 'type' => 'hook_call', 'rel_id' => '{"upgrade_id":90}', 'title' => 'Upgrade', 'price' => 89.9, 'quantity' => 1, 'taxed' => 1]);
    // A taxed renewal paid after the untaxed upgrade invoice does not hide it.
    $db->insert('invoice', ['id' => 72, 'client_id' => 5, 'status' => 'paid', 'taxrate' => 24.0, 'paid_at' => '2026-10-03 09:00:00']);
    $db->insert('invoice_item', ['invoice_id' => 72, 'type' => 'order', 'rel_id' => '2', 'title' => 'Pro', 'price' => 600, 'quantity' => 1, 'taxed' => 1]);
    $db->insert(Upgrades::TABLE, ['id' => 90, 'order_id' => 2, 'client_id' => 5, 'kind' => 'upgrade', 'from_product_id' => 1, 'from_period' => '1M', 'from_price' => 5, 'from_expires_at' => '2026-11-01 09:00:00', 'to_product_id' => 2, 'to_period' => '1Y', 'to_price' => 240, 'to_expires_at' => '2027-10-01 09:00:00', 'days_left' => 1, 'days_total' => 1, 'credit' => 0, 'charge' => 0, 'amount' => 89.9, 'invoice_id' => 71, 'status' => 'applied', 'created_at' => '2026-10-02 09:00:00']);

    $quotes = Upgrades::options($di, upgradesOrder($di, 2), UPGRADES_NOW)['options'];
    $quote = array_values(array_filter($quotes, fn (array $option): bool => $option['product_id'] === 1 && $option['period'] === '1M'))[0];
    expect($quote)->toMatchArray(['amount' => -218.56, 'total' => -218.56, 'tax_rate' => 0.0]);

    Upgrades::request($di, upgradesOrder($di, 2), 1, '1M', UPGRADES_NOW);
    expect($db->fetchOne('SELECT amount FROM client_balance'))->toBe(218.56)
        ->and($db->fetchOne("SELECT nr FROM invoice WHERE status = 'refunded'"))->toBe('from 71');
});

test('yearly to yearly downgrade keeps the renewal date', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);

    Upgrades::request($di, upgradesOrder($di, 2), 2, '1Y', UPGRADES_NOW);
    expect($db->fetchAssociative('SELECT product_id, price, period, expires_at FROM client_order WHERE id = 2'))->toBe(['product_id' => 2, 'price' => 300.0, 'period' => '1Y', 'expires_at' => '2027-03-01 00:00:00'])
        ->and($db->fetchOne('SELECT amount FROM client_balance'))->toBe(111.78);
});

test('a change that costs nothing applies at once without an invoice or credit', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);

    $quotes = Upgrades::options($di, upgradesOrder($di, 1), UPGRADES_NOW)['options'];
    $quote = array_values(array_filter($quotes, fn (array $option): bool => $option['product_id'] === 8))[0];
    expect($quote)->toMatchArray(['amount' => 0.0, 'total' => 0.0, 'tax_rate' => 0.0]);

    expect(Upgrades::request($di, upgradesOrder($di, 1), 8, '1M', UPGRADES_NOW))->toMatchArray(['status' => 'applied', 'amount' => 0.0])
        ->and($db->fetchOne('SELECT product_id FROM client_order WHERE id = 1'))->toBe(8)
        ->and($db->fetchOne('SELECT COUNT(*) FROM invoice WHERE id < 9000') + $db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(0);
});

test('a quantity order keeps its unit price per item', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);

    $result = Upgrades::request($di, upgradesOrder($di, 4), 2, '1M', UPGRADES_NOW);
    upgradesPay($db, $result['id']);
    Upgrades::onPaid($di, $result['id'], UPGRADES_NOW);
    // 121.50 for 3 = 40.50 each (Pro 45 USD × 0.9).
    expect($db->fetchAssociative('SELECT price, period, expires_at FROM client_order WHERE id = 4'))->toBe(['price' => 40.5, 'period' => '1M', 'expires_at' => '2026-11-01 00:00:00']);
});

test('only offered changes are accepted', function (string|int $product, string $period, string $message): void {
    $db = upgradesDb();
    $di = upgradesContainer($db, ['downgrades_enabled' => '0']);

    expect(fn () => Upgrades::request($di, upgradesOrder($di, 3), (int) $product, $period, UPGRADES_NOW))->toThrow(InformationException::class, $message)
        ->and($db->fetchOne('SELECT COUNT(*) FROM ' . Upgrades::TABLE))->toBe(0);
})->with([
    'its own product and cycle' => [2, '1M', 'This change is not available'],
    'a product not listed' => [6, '1M', 'This change is not available'],
    'a cycle the product lacks' => [3, '3M', 'This change is not available'],
    'a downgrade while they are off' => [1, '1M', 'This change is not available'],
    'a period that is no period' => [3, 'monthly', 'This change is not available'],
]);

test('a renewal before the upgrade invoice is paid fails the upgrade for staff, without touching the order', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    $result = Upgrades::request($di, upgradesOrder($di, 1), 2, '1M', UPGRADES_NOW);
    $db->update('client_order', ['expires_at' => '2026-12-01 12:00:00'], ['id' => 1]);
    upgradesPay($db, $result['id']);

    Upgrades::onPaid($di, $result['id'], UPGRADES_NOW);
    expect($db->fetchAssociative('SELECT product_id, price FROM client_order WHERE id = 1'))->toBe(['product_id' => 1, 'price' => 5.0])
        ->and($db->fetchAssociative('SELECT status, error FROM ' . Upgrades::TABLE))
        ->toBe(['status' => 'failed', 'error' => 'The service changed after this upgrade was priced (renewed or its expiry date was changed). Change it by hand and settle the amount with the client.'])
        ->and($db->fetchAssociative('SELECT client_order_id, action, status FROM ' . ActionFailures::TABLE))->toBe(['client_order_id' => 1, 'action' => 'upgrade', 'status' => 'open'])
        ->and(fn () => Upgrades::retryForOrder($di, 1))->toThrow(InformationException::class, 'renewed')
        ->and($db->fetchOne('SELECT attempts FROM ' . ActionFailures::TABLE))->toBe(2);
});

test('a hosting upgrade paid at expiry fails for staff without changing the plan', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    $result = Upgrades::request($di, upgradesOrder($di, 5), 21, '1Y', UPGRADES_NOW);
    upgradesPay($db, $result['id']);

    Upgrades::onPaid($di, $result['id'], '2027-01-01 00:00:00');

    expect($db->fetchOne('SELECT product_id FROM client_order WHERE id = 5'))->toBe(20)
        ->and($di['db']->planChanges)->toBe([])
        ->and($db->fetchAssociative('SELECT status, error FROM ' . Upgrades::TABLE))->toBe(['status' => 'failed', 'error' => 'The service changed after this upgrade was priced (it has expired). Change it by hand and settle the amount with the client.'])
        ->and($db->fetchAssociative('SELECT client_order_id, action, status FROM ' . ActionFailures::TABLE))->toBe(['client_order_id' => 5, 'action' => 'upgrade', 'status' => 'open']);
});

test('a failed subscription cancellation leaves the hosting panel plan unchanged', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    $di['services']['invoice_subscription']->shouldReceive('cancelForOrder')->once()
        ->with(Mockery::on(fn (Model_ClientOrder $order): bool => (int) $order->id === 5))
        ->andThrow(new RuntimeException('Gateway unavailable'));
    $result = Upgrades::request($di, upgradesOrder($di, 5), 21, '1Y', UPGRADES_NOW);
    upgradesPay($db, $result['id']);

    Upgrades::onPaid($di, $result['id'], UPGRADES_NOW);

    expect($di['db']->planChanges)->toBe([])
        ->and($db->fetchAssociative('SELECT status, error FROM ' . Upgrades::TABLE . ' WHERE id = ?', [$result['id']]))
        ->toBe(['status' => 'failed', 'error' => 'Gateway unavailable']);
});

test('a hosting upgrade moves the panel plan; a panel failure is retried by staff', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db, [], ['panel' => 'Panel offline']);
    $result = Upgrades::request($di, upgradesOrder($di, 5), 21, '1Y', UPGRADES_NOW);
    expect($result['amount'])->toBe(21.09);
    upgradesPay($db, $result['id']);

    Upgrades::onPaid($di, $result['id'], UPGRADES_NOW);
    expect($db->fetchOne('SELECT product_id FROM client_order WHERE id = 5'))->toBe(20)
        ->and($db->fetchAssociative('SELECT status, error FROM ' . Upgrades::TABLE))->toBe(['status' => 'failed', 'error' => 'Panel offline'])
        ->and($db->fetchOne('SELECT action FROM ' . ActionFailures::TABLE . ' WHERE status = ?', ['open']))->toBe('upgrade');

    // Staff retry from Orders > Failed actions once the panel is back.
    $di['services']['servicehosting']->fail = null;
    $entry = (int) $db->fetchOne('SELECT id FROM ' . ActionFailures::TABLE);
    expect(ActionFailures::retry($di, $entry, 9))->toBeTrue()
        ->and($di['db']->planChanges)->toBe([[5, 8]])
        ->and($db->fetchAssociative('SELECT product_id, title, price FROM client_order WHERE id = 5'))->toBe(['product_id' => 21, 'title' => 'Host M example.com', 'price' => 200.0])
        ->and(json_decode((string) $db->fetchOne('SELECT config FROM client_order WHERE id = 5'), true))->toMatchArray(['server_id' => 1, 'hosting_plan_id' => 8, 'domain' => 'example.com'])
        ->and($db->fetchAssociative('SELECT status, resolved_by FROM ' . ActionFailures::TABLE))->toBe(['status' => 'resolved', 'resolved_by' => 9])
        ->and($db->fetchOne('SELECT status FROM ' . Upgrades::TABLE))->toBe('applied');
});

test('a failed credit undoes the whole change', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db, [], ['funds' => 'Balance locked']);

    expect(Upgrades::request($di, upgradesOrder($di, 2), 1, '1M', UPGRADES_NOW)['status'])->toBe('failed')
        ->and($db->fetchAssociative('SELECT product_id, price, period FROM client_order WHERE id = 2'))->toBe(['product_id' => 3, 'price' => 600.0, 'period' => '1Y'])
        ->and($db->fetchOne('SELECT error FROM ' . Upgrades::TABLE))->toBe('Balance locked');
});

test('the cron applies paid upgrades, closes dropped invoices and frees stuck claims', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    // The sweep and the listener apply with the real clock: order 1 must not have expired by then.
    $db->update('client_order', ['expires_at' => date('Y-m-d H:i:s', time() + 20 * 86400)], ['id' => 1]);
    $paid = Upgrades::request($di, upgradesOrder($di, 1), 2, '1M', date('Y-m-d H:i:s'))['id'];
    $dropped = Upgrades::request($di, upgradesOrder($di, 3), 3, '1M', UPGRADES_NOW)['id'];
    $removed = Upgrades::request($di, upgradesOrder($di, 4), 2, '1M', UPGRADES_NOW)['id'];
    upgradesPay($db, $paid);
    $db->executeStatement('UPDATE invoice SET status = ? WHERE id = (SELECT invoice_id FROM ' . Upgrades::TABLE . ' WHERE id = ?)', ['canceled', $dropped]);
    $db->executeStatement('DELETE FROM invoice WHERE id = (SELECT invoice_id FROM ' . Upgrades::TABLE . ' WHERE id = ?)', [$removed]);
    $db->insert(Upgrades::TABLE, $db->fetchAssociative('SELECT order_id, client_id, kind, from_product_id, from_title, from_period, from_price, from_expires_at, to_product_id, to_title, to_period, to_price, to_expires_at, days_left, days_total, credit, charge, amount, currency, created_at FROM ' . Upgrades::TABLE . ' WHERE id = ?', [$paid])
        + ['status' => 'applying', 'claimed_at' => '2026-01-01 00:00:00']);
    $stuck = (int) $db->lastInsertId();

    expect(Upgrades::sweep($di))->toBe(1)
        ->and($db->fetchAllKeyValue('SELECT id, status FROM ' . Upgrades::TABLE . ' ORDER BY id'))->toBe([$paid => 'applied', $dropped => 'cancelled', $removed => 'cancelled', $stuck => 'failed'])
        // A dropped invoice no longer blocks a new change.
        ->and(Upgrades::options($di, upgradesOrder($di, 3), UPGRADES_NOW)['available'])->toBeTrue();
});

test('the new title keeps what the order title adds to the product title', function (): void {
    expect(Upgrades::newTitle('Host S example.com', 'Host S example.com', 'Host M', 'Host S'))->toBe('Host M example.com')
        ->and(Upgrades::newTitle('My own name', 'My own name', 'Pro', 'Starter'))->toBe('Pro')
        ->and(Upgrades::newTitle('Starter', 'Starter', 'Pro', ''))->toBe('Pro');
});

test('the history lists newest first and a missing table lists nothing', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    Upgrades::request($di, upgradesOrder($di, 2), 2, '1Y', UPGRADES_NOW);
    Upgrades::request($di, upgradesOrder($di, 2), 1, '1Y', '2026-10-17 09:00:00');

    expect(array_column(Upgrades::history($di, 2), 'to_product_id'))->toBe([1, 2])
        ->and(Upgrades::history(upgradesContainer(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true])), 2))->toBe([]);
});

test('the test tables have the columns of the real tables', function (): void {
    $schema = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/Clientfence/schema.json'), true)['tables'];
    $db = upgradesDb();
    $di = upgradesContainer($db);
    upgradesWithOptions($di);
    foreach ([Upgrades::TABLE, Upgrades::RULES, Upgrades::OPTIONS_TABLE] as $table) {
        preg_match_all('/^\s*`([a-z_]+)` /m', str_replace([', `', ' (`'], [",\n`", " (\n`"], $schema[$table]), $m);
        expect(array_column($db->fetchAllAssociative("PRAGMA table_info({$table})"), 'name'))->toBe($m[1]);
    }
});

test('the paid invoice line applies the upgrade through the ClientFence listener', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    // The sweep and the listener apply with the real clock: order 1 must not have expired by then.
    $db->update('client_order', ['expires_at' => date('Y-m-d H:i:s', time() + 20 * 86400)], ['id' => 1]);
    $id = Upgrades::request($di, upgradesOrder($di, 1), 3, '1M', date('Y-m-d H:i:s'))['id'];
    upgradesPay($db, $id);
    // As Invoice\ServiceInvoiceItem::executeTask() fires it: the line's rel_id, json_decode()d.
    $event = new \ClientFence\Event(null, Upgrades::EVENT, json_decode((string) $db->fetchOne('SELECT rel_id FROM invoice_item WHERE invoice_id < 9000')));
    $event->setDi($di);

    ClientFence\Mod\Clientfence\Service::onAfterClientOrderUpgradePaid($event);
    expect($db->fetchOne('SELECT product_id FROM client_order WHERE id = 1'))->toBe(3);
});

test('a renewal invoice issued while the upgrade invoice waited is cancelled when the upgrade applies', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    $id = Upgrades::request($di, upgradesOrder($di, 1), 2, '1Y', UPGRADES_NOW)['id'];
    // The cron bills the next month at the old price before the client pays the upgrade.
    $db->insert('invoice', ['id' => 60, 'client_id' => 5, 'status' => 'unpaid', 'hash' => 'r']);
    $db->insert('invoice_item', ['invoice_id' => 60, 'type' => 'order', 'task' => 'renew', 'rel_id' => '1', 'title' => 'Starter renewal', 'price' => 5, 'quantity' => 1]);
    $db->update('client_order', ['unpaid_invoice_id' => 60], ['id' => 1]);
    upgradesPay($db, $id);

    Upgrades::onPaid($di, $id, UPGRADES_NOW);
    expect($db->fetchOne('SELECT status FROM invoice WHERE id = 60'))->toBe('canceled')
        ->and($db->fetchAssociative('SELECT product_id, period, unpaid_invoice_id FROM client_order WHERE id = 1'))->toBe(['product_id' => 2, 'period' => '1Y', 'unpaid_invoice_id' => null])
        ->and($di['status_notes'][0][1])->toBe('Upgrade from Starter (1M) to Pro (1Y) (unpaid renewal invoice #60 at the old price cancelled)');
});

test('a renewal invoice that bills other items too is left to staff', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    $id = Upgrades::request($di, upgradesOrder($di, 1), 2, '1M', UPGRADES_NOW)['id'];
    $db->insert('invoice', ['id' => 60, 'client_id' => 5, 'status' => 'unpaid', 'hash' => 'r']);
    $db->insert('invoice_item', ['invoice_id' => 60, 'type' => 'order', 'task' => 'renew', 'rel_id' => '1', 'title' => 'Starter renewal', 'price' => 5, 'quantity' => 1]);
    $db->insert('invoice_item', ['invoice_id' => 60, 'type' => 'order', 'task' => 'renew', 'rel_id' => '3', 'title' => 'Pro renewal', 'price' => 45, 'quantity' => 1]);
    $db->update('client_order', ['unpaid_invoice_id' => 60], ['id' => 1]);
    upgradesPay($db, $id);

    Upgrades::onPaid($di, $id, UPGRADES_NOW);
    expect($db->fetchOne('SELECT status FROM invoice WHERE id = 60'))->toBe('unpaid')
        ->and($db->fetchOne('SELECT product_id FROM client_order WHERE id = 1'))->toBe(1)
        ->and($db->fetchOne('SELECT error FROM ' . Upgrades::TABLE))->toBe('Unpaid invoice #60 bills this service at its old price together with other items. Cancel or edit it, then retry.');
});

test('two changes priced on the same service: the second one finds it changed and pays nothing', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    // Two downgrades priced at the same moment (two sign-ins), applied one after the other.
    $order = upgradesOrder($di, 2);
    $first = Upgrades::request($di, $order, 1, '1M', UPGRADES_NOW);
    $db->insert(Upgrades::TABLE, $db->fetchAssociative('SELECT order_id, client_id, kind, from_product_id, from_title, from_period, from_price, from_expires_at, to_product_id, to_title, to_period, to_price, to_expires_at, days_left, days_total, credit, charge, amount, currency, created_at FROM ' . Upgrades::TABLE . ' WHERE id = ?', [$first['id']]) + ['status' => 'pending']);
    $second = (int) $db->lastInsertId();

    expect($first['status'])->toBe('applied')
        ->and(Upgrades::apply($di, $second))->toBeFalse()
        ->and($db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(1)
        ->and($db->fetchOne('SELECT error FROM ' . Upgrades::TABLE . ' WHERE id = ?', [$second]))->toContain('another product');
});

test('a change being applied blocks a new one', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    $id = Upgrades::request($di, upgradesOrder($di, 1), 2, '1M', UPGRADES_NOW)['id'];
    $db->update(Upgrades::TABLE, ['status' => 'applying'], ['id' => $id]);

    expect(Upgrades::options($di, upgradesOrder($di, 1), UPGRADES_NOW)['reason'])->toBe('A change of this service is waiting for payment. Pay its invoice, or ask us to cancel it.');
});

test('a change waiting for payment is shown with its invoice, even with upgrades switched off', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    $result = Upgrades::request($di, upgradesOrder($di, 1), 2, '1Y', UPGRADES_NOW);
    $hash = (string) $db->fetchOne('SELECT hash FROM invoice WHERE id < 9000');
    $want = ['kind' => 'upgrade', 'title' => 'Pro', 'period' => '1Y', 'amount' => 297.42, 'currency' => 'USD', 'invoice_number' => 'LNK-421', 'invoice_hash' => $hash];

    expect(Upgrades::options($di, upgradesOrder($di, 1), UPGRADES_NOW))->toMatchArray(['available' => false, 'reason' => Upgrades::waitingMessage(), 'waiting' => $want])
        ->and(Upgrades::options(upgradesContainer($db, ['upgrades_enabled' => '0', 'downgrades_enabled' => '0']), upgradesOrder($di, 1), UPGRADES_NOW)['waiting'])->toBe($want)
        // Other services of the client are not affected.
        ->and(Upgrades::options($di, upgradesOrder($di, 3), UPGRADES_NOW)['waiting'])->toBeNull();

    // Paid (applied) or cancelled: no note.
    upgradesPay($db, $result['id']);
    Upgrades::onPaid($di, $result['id'], UPGRADES_NOW);
    expect(Upgrades::waiting($di, 1))->toBeNull()
        ->and(Upgrades::options($di, upgradesOrder($di, 1), '2026-10-17 09:00:00')['waiting'])->toBeNull();
    $second = Upgrades::request($di, upgradesOrder($di, 3), 3, '1M', UPGRADES_NOW);
    $db->executeStatement('UPDATE invoice SET status = ? WHERE id = (SELECT invoice_id FROM ' . Upgrades::TABLE . ' WHERE id = ?)', ['canceled', $second['id']]);
    expect(Upgrades::waiting($di, 3))->toBeNull()
        ->and(Upgrades::waiting(upgradesContainer(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true])), 3))->toBeNull();
});

test('an invoice without a number shows its id, padded as ClientFence prints it', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    Upgrades::request($di, upgradesOrder($di, 1), 2, '1M', UPGRADES_NOW);
    $db->executeStatement('UPDATE invoice SET nr = NULL, serie = ? WHERE id < 9000', ['INV']);

    expect(Upgrades::waiting($di, 1)['invoice_number'])->toBe('INV001');
});

test('the waiting note shows what the invoice asks for, tax included', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    Upgrades::request($di, upgradesOrder($di, 1), 2, '1M', UPGRADES_NOW);
    expect(Upgrades::waiting($di, 1)['amount'])->toBe(20.65);

    // A taxable client: 20.65 + 14% = 23.54.
    $db->executeStatement('UPDATE invoice_item SET taxed = 1');
    expect(Upgrades::waiting($di, 1)['amount'])->toBe(23.54);
});

// ---------------------------------------------------------------------------------------------
// Configurable options (Product/Options.php)
// ---------------------------------------------------------------------------------------------

/**
 * Options on Starter (1) and Pro (2): RAM (2 GB free, 4 GB 1M 5 / 1Y 50) and Backups (yes/no,
 * 1M 3 / 1Y 30); Starter only: Support (Basic free, Priority 1M 10; Secret hidden, 1M 1). Order 1
 * (Starter monthly, 16 of 31 days left) has RAM 4 GB, Backups and Basic support, sold at 5 + 8 = 13.
 *
 * @return array{ram: int, backups: int, support: int, '2gb': int, '4gb': int, basic: int, priority: int, secret: int}
 */
function upgradesWithOptions(Pimple\Container $di): array
{
    $db = $di['em']->getConnection();
    $prices = implode(', ', array_map(fn (string $k): string => "{$k}_price REAL NOT NULL DEFAULT 0, {$k}_setup_price REAL NOT NULL DEFAULT 0", Options::PRICE_KEYS));
    foreach ([
        'CREATE TABLE product_option_group (id INTEGER PRIMARY KEY, title TEXT NOT NULL, description TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)',
        'CREATE TABLE product_option_group_product (group_id INTEGER NOT NULL, product_id INTEGER NOT NULL, PRIMARY KEY (group_id, product_id))',
        'CREATE TABLE product_option (id INTEGER PRIMARY KEY, group_id INTEGER NOT NULL, title TEXT NOT NULL, code TEXT NOT NULL DEFAULT \'\', type TEXT NOT NULL, qty_min INTEGER NOT NULL DEFAULT 0,'
            . ' qty_max INTEGER NOT NULL DEFAULT 0, unit TEXT NOT NULL DEFAULT \'\', hidden INTEGER NOT NULL DEFAULT 0, position INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)',
        "CREATE TABLE product_option_value (id INTEGER PRIMARY KEY, option_id INTEGER NOT NULL, title TEXT NOT NULL, code TEXT NOT NULL DEFAULT '', hidden INTEGER NOT NULL DEFAULT 0, position INTEGER NOT NULL DEFAULT 0, {$prices}, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)",
        'CREATE TABLE ' . Upgrades::OPTIONS_TABLE . ' (upgrade_id INTEGER PRIMARY KEY, from_options TEXT NOT NULL, to_options TEXT NOT NULL, to_option_lines TEXT NOT NULL)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    $extras = Options::saveGroup($di, ['title' => 'Extras']);
    $starter = Options::saveGroup($di, ['title' => 'Starter only']);
    $ram = Options::saveOption($di, ['group_id' => $extras, 'title' => 'RAM', 'type' => 'select', 'values' => [['title' => '2 GB'], ['title' => '4 GB', 'm_price' => '5', 'a_price' => '50']]]);
    $backups = Options::saveOption($di, ['group_id' => $extras, 'title' => 'Backups', 'type' => 'yesno', 'position' => 1, 'values' => [['m_price' => '3', 'a_price' => '30']]]);
    $support = Options::saveOption($di, ['group_id' => $starter, 'title' => 'Support', 'type' => 'radio', 'values' => [['title' => 'Basic'], ['title' => 'Priority', 'm_price' => '10'], ['title' => 'Secret', 'm_price' => '1', 'hidden' => true]]]);
    Options::setProductGroups($di, 1, [$extras, $starter]);
    Options::setProductGroups($di, 2, [$extras]);
    $value = fn (int $option, string $title): int => (int) $db->fetchOne('SELECT id FROM product_option_value WHERE option_id = ? AND title = ?', [$option, $title]);
    $ids = ['ram' => $ram, 'backups' => $backups, 'support' => $support, '2gb' => $value($ram, '2 GB'), '4gb' => $value($ram, '4 GB'), 'basic' => $value($support, 'Basic'), 'priority' => $value($support, 'Priority'), 'secret' => $value($support, 'Secret')];
    $sold = Options::resolve($di, 1, [$ram => $ids['4gb'], $backups => 1, $support => $ids['basic']], '1M');
    $db->update('client_order', ['price' => 13, 'config' => json_encode(['options' => $sold['choice'], 'option_lines' => $sold['lines']])], ['id' => 1]);
    $db->update('invoice_item', ['price' => 13], ['invoice_id' => 9001]);

    return $ids;
}

/** @return array{options: array<string, int>, lines: list<string>} */
function upgradesOrderOptions(Connection $db, int $orderId): array
{
    $config = json_decode((string) $db->fetchOne('SELECT config FROM client_order WHERE id = ?', [$orderId]), true);

    return ['options' => $config['options'] ?? [], 'lines' => array_map(fn (array $l): string => $l['title'] . '=' . $l['price'], $config['option_lines'] ?? [])];
}

test('a service keeps the options its new product also has, priced for the new cycle', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    $o = upgradesWithOptions($di);

    $listed = [];
    foreach (Upgrades::options($di, upgradesOrder($di, 1), UPGRADES_NOW)['options'] as $entry) {
        $listed[$entry['product_id'] . '/' . $entry['period']] = [$entry['price'], $entry['options'], $entry['amount']];
    }
    // Pro monthly: 45 + RAM 5 + Backups 3; Support is Starter's only. 53 × 16/31 = 27.35, less 13 × 16/31 = 6.71.
    expect($listed['2/1M'])->toBe([53.0, [$o['ram'] => $o['4gb'], $o['backups'] => 1], 20.64])
        // Starter yearly: 48 + 50 + 30, Basic support kept; a new year less 6.71.
        ->and($listed['1/1Y'])->toBe([128.0, [$o['ram'] => $o['4gb'], $o['backups'] => 1, $o['support'] => $o['basic']], 121.29]);

    $result = Upgrades::request($di, upgradesOrder($di, 1), 2, '1M', UPGRADES_NOW);
    upgradesPay($db, $result['id']);
    Upgrades::onPaid($di, $result['id'], UPGRADES_NOW);
    expect($db->fetchAssociative('SELECT product_id, price FROM client_order WHERE id = 1'))->toBe(['product_id' => 2, 'price' => 53.0])
        ->and(upgradesOrderOptions($db, 1))->toBe(['options' => [$o['ram'] => $o['4gb'], $o['backups'] => 1], 'lines' => ['RAM=5', 'Backups=3']])
        ->and($di['status_notes'][0][1])->toBe('Upgrade from Starter (1M) to Pro (1M); options: RAM: 4 GB, Backups, Support: Basic to RAM: 4 GB, Backups')
        ->and($di['events'][0]['params']['options_changed'])->toBeTrue();
});

test('options change quotes include invoice tax rounded to cents', function (?float $rate, float $optionPrice, float $net, float $total): void {
    $db = upgradesDb();
    $di = upgradesContainer($db, [], ['tax_rate' => $rate]);
    $o = upgradesWithOptions($di);
    $db->update('product_option_value', ['m_price' => $optionPrice], ['id' => $o['priority']]);
    $choice = [$o['support'] => $o['priority']];

    $quote = Upgrades::optionsQuote($di, upgradesOrder($di, 1), $choice, UPGRADES_NOW);
    $result = Upgrades::request($di, upgradesOrder($di, 1), 1, '1M', UPGRADES_NOW, $choice);
    $invoiceId = (int) $db->fetchOne('SELECT invoice_id FROM ' . Upgrades::TABLE . ' WHERE id = ?', [$result['id']]);
    $invoice = $di['db']->load('Invoice', $invoiceId);

    expect($quote)->toMatchArray(['amount' => $net, 'total' => $total, 'tax_rate' => $rate ?? 0.0, 'price' => 13.0 + $optionPrice])
        ->and($quote['total'])->toBe(round($di['mod_service']('invoice')->getTotalWithTax($invoice), 2))
        ->and((float) $db->fetchOne('SELECT price FROM invoice_item WHERE invoice_id = ?', [$invoiceId]))->toBe($net);

    upgradesPay($db, $result['id']);
    Upgrades::onPaid($di, $result['id'], UPGRADES_NOW);
    expect((float) $db->fetchOne('SELECT price FROM client_order WHERE id = 1'))->toBe(13.0 + $optionPrice);
})->with([
    'untaxed' => [null, 10.0, 5.16, 5.16],
    'zero rate' => [0.0, 10.0, 5.16, 5.16],
    'DE 19%' => [19.0, 10.0, 5.16, 6.14],
    'half cent tax' => [19.0, 0.97, 0.50, 0.60],
]);

test('an options downgrade quote matches its credit note at the paid rate', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db, [], ['tax_rate' => 24.0]);
    $o = upgradesWithOptions($di);
    $db->update('invoice', ['taxrate' => 19], ['id' => 9001]);
    $db->update('invoice_item', ['taxed' => 1], ['invoice_id' => 9001]);
    $choice = [$o['backups'] => 0];

    $quote = Upgrades::optionsQuote($di, upgradesOrder($di, 1), $choice, UPGRADES_NOW);
    Upgrades::request($di, upgradesOrder($di, 1), 1, '1M', UPGRADES_NOW, $choice);
    $invoice = $di['db']->load('Invoice', (int) $db->fetchOne("SELECT id FROM invoice WHERE status = 'refunded'"));

    expect($quote)->toMatchArray(['amount' => -1.55, 'total' => -1.84, 'tax_rate' => 19.0])
        ->and($quote['total'])->toBe(round($di['mod_service']('invoice')->getTotalWithTax($invoice), 2))
        ->and((float) $db->fetchOne('SELECT amount FROM client_balance'))->toBe(-$quote['total']);
});

test('the client changes the options alone: the price moves by the difference for the days left', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    $o = upgradesWithOptions($di);

    expect(Upgrades::options($di, upgradesOrder($di, 1), UPGRADES_NOW)['options_change'])->toBeTrue();

    // Priority support: 13 + 10 = 23; 23 × 16/31 = 11.87, less 6.71.
    $quote = Upgrades::optionsQuote($di, upgradesOrder($di, 1), [$o['support'] => $o['priority']], UPGRADES_NOW);
    expect($quote)->toMatchArray(['kind' => 'upgrade', 'price' => 23.0, 'amount' => 5.16, 'same_cycle' => true, 'options_change' => true]);
    $result = Upgrades::request($di, upgradesOrder($di, 1), 1, '1M', UPGRADES_NOW, [$o['support'] => $o['priority']]);
    expect($result)->toMatchArray(['status' => 'pending', 'amount' => 5.16])
        ->and($db->fetchOne('SELECT title FROM invoice_item WHERE invoice_id < 9000'))->toBe('Upgrade of order #1: Starter, options changed to RAM: 4 GB, Backups, Support: Priority, less 6.71 USD unused (16 of 31 days)');
    upgradesPay($db, $result['id']);
    Upgrades::onPaid($di, $result['id'], UPGRADES_NOW);
    expect($db->fetchAssociative('SELECT product_id, title, price, period, expires_at FROM client_order WHERE id = 1'))->toBe(['product_id' => 1, 'title' => 'Starter', 'price' => 23.0, 'period' => '1M', 'expires_at' => '2026-11-01 12:00:00'])
        ->and(upgradesOrderOptions($db, 1)['lines'])->toBe(['RAM=5', 'Backups=3', 'Support=10'])
        ->and($di['status_notes'][0][1])->toBe('Upgrade of the options; options: RAM: 4 GB, Backups, Support: Basic to RAM: 4 GB, Backups, Support: Priority');

    // Back to 2 GB and no backups: a downgrade credited at once (15 × 16/31 = 7.74 against 23 × 16/31 = 11.87).
    $down = Upgrades::request($di, upgradesOrder($di, 1), 1, '1M', UPGRADES_NOW, [$o['ram'] => $o['2gb'], $o['backups'] => 0]);
    expect($down)->toMatchArray(['status' => 'applied', 'amount' => -4.13])
        ->and($db->fetchOne('SELECT price FROM client_order WHERE id = 1'))->toBe(15.0)
        ->and($db->fetchOne('SELECT amount FROM client_balance'))->toBe(4.13);
});

test('an options change after a price change charges only the options\' difference for the days left', function (float $priceNow): void {
    // QA r6 U-01: after a staff price edit or bulk update the days left were priced at the new price.
    $db = upgradesDb();
    $di = upgradesContainer($db);
    $o = upgradesWithOptions($di);
    $db->update('client_order', ['price' => $priceNow], ['id' => 1]);

    // Priority support adds 10: 10 × 16/31 = 5.16, whatever the price is now; the new price applies from the renewal.
    $quote = Upgrades::optionsQuote($di, upgradesOrder($di, 1), [$o['support'] => $o['priority']], UPGRADES_NOW);
    expect($quote)->toMatchArray(['kind' => 'upgrade', 'price' => $priceNow + 10, 'amount' => 5.16]);
})->with(['price raised' => [50.0], 'price cut' => [5.0]]);

test('client and staff options changes after a cycle edit price only the changed options', function (string $soldPeriod, string $period, float $price, float $amount): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    $o = upgradesWithOptions($di);
    $db->update('product_option_value', ['a_price' => 20], ['id' => $o['priority']]);
    $sold = Options::resolve($di, 1, Upgrades::orderOptions(upgradesOrder($di, 1))['choice'], $soldPeriod);
    $db->update('client_order', ['period' => $period, 'price' => $price, 'expires_at' => '2026-11-05 09:00:00', 'config' => json_encode(['options' => $sold['choice'], 'option_lines' => $sold['lines']])], ['id' => 1]);
    $db->update('invoice_item', ['period' => $soldPeriod, 'price' => $soldPeriod === '1M' ? 13 : 130], ['invoice_id' => 9001]);

    $quote = Upgrades::optionsQuote($di, upgradesOrder($di, 1), [$o['support'] => $o['priority']], UPGRADES_NOW);
    $newPrice = $price + ($period === '1M' ? 10 : 20);
    expect($quote)->toMatchArray(['kind' => 'upgrade', 'price' => $newPrice, 'amount' => $amount]);

    Upgrades::staffSetOptions($di, 1, [$o['support'] => $o['priority']], true);
    expect($db->fetchOne('SELECT price FROM client_order WHERE id = 1'))->toBe($newPrice);
})->with([
    'monthly options kept after a yearly cycle edit' => ['1M', '1Y', 130.0, 1.1],
    'yearly options kept after a monthly cycle edit' => ['1Y', '1M', 13.0, 6.45],
]);

test('options attached after purchase are charged only when chosen by the client or staff', function (bool $paid, float $price, float $amount): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    upgradesWithOptions($di);
    $group = Options::saveGroup($di, ['title' => 'Added later']);
    $location = Options::saveOption($di, ['group_id' => $group, 'title' => 'Location', 'type' => 'select', 'values' => [['title' => 'EU', 'm_price' => '5'], ['title' => 'US']]]);
    Options::setProductGroups($di, 1, [...Options::productGroups($di, 1), $group]);
    $value = (int) $db->fetchOne('SELECT id FROM product_option_value WHERE option_id = ? AND title = ?', [$location, $paid ? 'EU' : 'US']);
    $choice = [$location => $value];

    expect(Upgrades::optionsQuote($di, upgradesOrder($di, 1), $choice, UPGRADES_NOW))->toMatchArray(['price' => $price, 'amount' => $amount]);
    Upgrades::staffSetOptions($di, 1, $choice, true);
    expect($db->fetchOne('SELECT price FROM client_order WHERE id = 1'))->toBe($price)
        ->and(upgradesOrderOptions($db, 1)['options'][$location])->toBe($value);
})->with([
    'paid default is an addition' => [true, 18.0, 2.58],
    'free value gives no credit' => [false, 13.0, 0.0],
]);

test('client and staff options changes retain sold prices for unavailable choices', function (string $change, float $price, float $amount): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    $o = upgradesWithOptions($di);
    $choice = [$o['support'] => $o['priority']];
    if ($change === 'deleted option') {
        Options::deleteOption($di, $o['ram']);
    } elseif ($change === 'unlinked group') {
        $group = (int) $db->fetchOne('SELECT group_id FROM product_option WHERE id = ?', [$o['support']]);
        Options::setProductGroups($di, 1, [$group]);
    } elseif ($change === 'removed value') {
        $db->delete('product_option_value', ['id' => $o['4gb']]);
        $choice[$o['ram']] = $o['2gb'];
    } else {
        $db->delete('product_option_value', ['option_id' => $o['backups']]);
    }

    expect(Upgrades::optionsQuote($di, upgradesOrder($di, 1), $choice, UPGRADES_NOW))->toMatchArray(['price' => $price, 'amount' => $amount]);
    Upgrades::staffSetOptions($di, 1, $choice, true);
    expect($db->fetchOne('SELECT price FROM client_order WHERE id = 1'))->toBe($price);
})->with([
    'deleted option' => ['deleted option', 18.0, 2.58],
    'unlinked group' => ['unlinked group', 15.0, 1.03],
    'removed select value' => ['removed value', 18.0, 2.58],
    'no resolved line for the stored checkbox' => ['missing line', 20.0, 3.61],
]);

test('client and staff options changes retain the sold price of an out-of-range quantity', function (int $min, int $max, int $quantity, float $price, float $amount): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    upgradesWithOptions($di);
    $group = Options::saveGroup($di, ['title' => 'Seats']);
    $seats = Options::saveOption($di, ['group_id' => $group, 'title' => 'Seats', 'type' => 'quantity', 'qty_max' => 10, 'values' => [['m_price' => '2']]]);
    Options::setProductGroups($di, 1, [...Options::productGroups($di, 1), $group]);
    $sold = Options::resolve($di, 1, Upgrades::orderOptions(upgradesOrder($di, 1))['choice'] + [$seats => 3], '1M');
    $db->update('client_order', ['price' => 19, 'config' => json_encode(['options' => $sold['choice'], 'option_lines' => $sold['lines']])], ['id' => 1]);
    $db->update('invoice_item', ['price' => 19], ['invoice_id' => 9001]);
    $db->update('product_option', ['qty_min' => $min, 'qty_max' => $max], ['id' => $seats]);
    $choice = [$seats => $quantity];

    expect(Upgrades::optionsQuote($di, upgradesOrder($di, 1), $choice, UPGRADES_NOW))->toMatchArray(['price' => $price, 'amount' => $amount]);
    Upgrades::staffSetOptions($di, 1, $choice, true);
    expect($db->fetchOne('SELECT price FROM client_order WHERE id = 1'))->toBe($price);
})->with([
    'minimum raised above the stored quantity' => [4, 10, 4, 21.0, 1.03],
    'maximum lowered below the stored quantity' => [0, 2, 2, 17.0, -1.04],
]);

test('removing options worth more than the service\'s price now is left to staff', function (): void {
    // QA r6 U-01-R2: the new price was clamped at 0, so the difference stored was smaller than the one credited, and a
    // later change credited the options' share of the days again.
    $db = upgradesDb();
    $di = upgradesContainer($db);
    $o = upgradesWithOptions($di);
    $db->update('client_order', ['price' => 0], ['id' => 1]);

    expect(fn () => Upgrades::optionsQuote($di, upgradesOrder($di, 1), [$o['ram'] => $o['2gb']], UPGRADES_NOW))
        ->toThrow(InformationException::class, 'The price of this service is lower than its options');
});

test('an options change is refused when nothing changes, mixed with a move, or no longer offered', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    $o = upgradesWithOptions($di);

    expect(fn () => Upgrades::optionsQuote($di, upgradesOrder($di, 1), [$o['ram'] => $o['4gb']], UPGRADES_NOW))->toThrow(InformationException::class, 'Choose other options than the ones this service has.')
        ->and(fn () => Upgrades::request($di, upgradesOrder($di, 1), 2, '1M', UPGRADES_NOW, [$o['ram'] => $o['2gb']]))->toThrow(InformationException::class, 'one at a time')
        ->and(fn () => Upgrades::optionsQuote($di, upgradesOrder($di, 1), [$o['support'] => $o['secret']], UPGRADES_NOW))->toThrow(InformationException::class, 'Secret is no longer offered.')
        // A product without options has nothing to change.
        ->and(Upgrades::options($di, upgradesOrder($di, 2), UPGRADES_NOW)['options_change'])->toBeFalse()
        ->and(fn () => Upgrades::optionsQuote($di, upgradesOrder($di, 2), [], UPGRADES_NOW))->toThrow(InformationException::class, 'cannot be changed here');
});

test('a service of a product no longer on sale can still change its options', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    $o = upgradesWithOptions($di);
    $db->update('product', ['status' => 'disabled'], ['id' => 1]);

    $result = Upgrades::options($di, upgradesOrder($di, 1), UPGRADES_NOW);
    // The service page's form gets the offered options from here (not the store's guest call).
    expect($result['options_change'])->toBeTrue()
        ->and(array_column($result['product_options'], 'title'))->toBe(['RAM', 'Backups', 'Support'])
        ->and(Upgrades::optionsQuote($di, upgradesOrder($di, 1), [$o['backups'] => 0], UPGRADES_NOW)['price'])->toBe(10.0);
});

test('options changed by staff after pricing fail the upgrade for staff', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    $o = upgradesWithOptions($di);

    $result = Upgrades::request($di, upgradesOrder($di, 1), 1, '1M', UPGRADES_NOW, [$o['support'] => $o['priority']]);
    Upgrades::staffSetOptions($di, 1, [$o['backups'] => 0], false);
    upgradesPay($db, $result['id']);
    Upgrades::onPaid($di, $result['id'], UPGRADES_NOW);
    expect($db->fetchAssociative('SELECT status, error FROM ' . Upgrades::TABLE))->toMatchArray(['status' => 'failed'])
        ->and($db->fetchOne('SELECT error FROM ' . Upgrades::TABLE))->toContain('other options')
        ->and(upgradesOrderOptions($db, 1)['lines'])->toBe(['RAM=5', 'Support=0']);
});

test('staff set any option, hidden ones too, and may move the price by the difference', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    $o = upgradesWithOptions($di);

    Upgrades::staffSetOptions($di, 1, [$o['support'] => $o['secret']], true);
    expect($db->fetchOne('SELECT price FROM client_order WHERE id = 1'))->toBe(14.0)
        ->and(upgradesOrderOptions($db, 1)['options'])->toBe([$o['ram'] => $o['4gb'], $o['backups'] => 1, $o['support'] => $o['secret']])
        ->and($di['status_notes'][0][1])->toBe('Options changed by staff: RAM: 4 GB, Backups, Support: Basic to RAM: 4 GB, Backups, Support: Secret; price 13.00 to 14.00 USD');

    // The client keeps the hidden value when changing another option after renewal.
    $db->update('invoice', ['created_at' => date('Y-m-d H:i:s', time() + 30), 'paid_at' => date('Y-m-d H:i:s', time() + 60)], ['id' => 9001]);
    $quote = Upgrades::optionsQuote($di, upgradesOrder($di, 1), [$o['backups'] => 0], UPGRADES_NOW);
    expect($quote['options'][$o['support']])->toBe($o['secret'])
        ->and($quote['price'])->toBe(11.0);

    Upgrades::staffSetOptions($di, 1, [$o['ram'] => $o['2gb']], false);
    expect($db->fetchOne('SELECT price FROM client_order WHERE id = 1'))->toBe(14.0)
        ->and(fn () => Upgrades::staffSetOptions($di, 1, [$o['ram'] => $o['2gb']], true))->toThrow(InformationException::class, 'The options are unchanged.');
});

// ---------------------------------------------------------------------------------------------
// QA r10 design review: what was paid for the days left
// ---------------------------------------------------------------------------------------------

test('a period begun on the 31st is credited by its own days (D-01)', function (): void {
    $db = upgradesDb();
    $db->executeStatement('ALTER TABLE client_order ADD COLUMN activated_at TEXT');
    $di = upgradesContainer($db);
    // strtotime() renews 31 January to 3 March: 31 days, which a clamped month counts as 28.
    $db->update('client_order', ['expires_at' => '2027-03-03 12:00:00'], ['id' => 3]);
    $now = '2027-01-31 13:00:00';
    $db->update('client_order', ['activated_at' => '2027-01-31 12:00:00'], ['id' => 3]);
    expect(Upgrades::blocker($di, upgradesOrder($di, 3), $now))->toBeNull()
        // 45 x 31/31, less 5 x 31/31 for Starter: never more than was paid.
        ->and(Upgrades::request($di, upgradesOrder($di, 3), 1, '1M', $now))->toMatchArray(['status' => 'applied', 'amount' => -40.0]);
});

test('a recurring promotion credits the days left at the discounted recurring amount', function (): void {
    $db = upgradesDb();
    foreach (['activated_at TEXT', 'promo_id INTEGER', 'promo_recurring INTEGER'] as $column) {
        $db->executeStatement('ALTER TABLE client_order ADD COLUMN ' . $column);
    }
    $di = upgradesContainer($db);
    $db->update('client_order', ['expires_at' => '2027-03-03 12:00:00', 'activated_at' => '2027-01-31 12:00:00', 'promo_id' => 4, 'promo_recurring' => 1], ['id' => 3]);
    $di['mod_service']('product')->shouldReceive('getRenewalPromoAdjustment')->atLeast()->once()->andReturn(['discount_amount' => 9.0]);

    // 45 less the 9 off each renewal, 36 x 31/31, less 5 x 31/31 for Starter.
    expect(Upgrades::request($di, upgradesOrder($di, 3), 1, '1M', '2027-01-31 13:00:00'))->toMatchArray(['status' => 'applied', 'amount' => -31.0]);
});

test('after a change of cycle the periods are walked from that change, not from the activation (D-01)', function (): void {
    $db = upgradesDb();
    $db->executeStatement('ALTER TABLE client_order ADD COLUMN activated_at TEXT');
    $di = upgradesContainer($db);
    // Activated on the 12th; moved to monthly on 31 December (to 31 January), then renewed to 3 March: 31 days.
    $db->update('client_order', ['activated_at' => '2026-05-12 12:00:00', 'expires_at' => '2027-03-03 12:00:00'], ['id' => 3]);
    $db->insert(Upgrades::TABLE, ['order_id' => 3, 'client_id' => 5, 'kind' => 'downgrade', 'from_product_id' => 2, 'from_period' => '1Y', 'from_price' => 300, 'from_expires_at' => '2027-05-12 12:00:00',
        'to_product_id' => 2, 'to_period' => '1M', 'to_price' => 45, 'to_expires_at' => '2027-01-31 09:00:00', 'days_left' => 132, 'days_total' => 365, 'credit' => 108.49, 'charge' => 45, 'amount' => -63.49,
        'status' => 'applied', 'created_at' => '2026-12-31 09:00:00', 'applied_at' => '2026-12-31 09:00:00']);

    // 3 February, 28 of 31 days left: 45 x 28/31 less 5 x 28/31, not the whole 45 less 5.
    expect(Upgrades::request($di, upgradesOrder($di, 3), 1, '1M', '2027-02-03 13:00:00'))->toMatchArray(['status' => 'applied', 'amount' => -36.13]);
});

test('a change to another cycle paid on a later day goes to staff (D-05)', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    $result = Upgrades::request($di, upgradesOrder($di, 1), 2, '1Y', UPGRADES_NOW);
    upgradesPay($db, $result['id']);

    Upgrades::onPaid($di, $result['id'], '2026-10-21 09:00:00');
    expect($db->fetchAssociative('SELECT status, error FROM ' . Upgrades::TABLE))->toBe(['status' => 'failed', 'error' => 'The service changed after this upgrade was priced (priced for 16 days left, 11 now). Change it by hand and settle the amount with the client.'])
        ->and($db->fetchOne('SELECT product_id FROM client_order WHERE id = 1'))->toBe(1);
});

test('a failed downgrade retried on a later day goes to staff, on the same day it applies (D-07)', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db, [], ['funds' => 'Balance locked']);
    $result = Upgrades::request($di, upgradesOrder($di, 3), 1, '1M', UPGRADES_NOW);
    expect($result['status'])->toBe('failed');
    $di = upgradesContainer($db);

    expect(Upgrades::apply($di, $result['id'], '2026-10-21 09:00:00'))->toBeFalse()
        ->and($db->fetchOne('SELECT error FROM ' . Upgrades::TABLE))->toContain('priced for 25 days left, 20 now')
        ->and($db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(0)
        ->and(Upgrades::apply($di, $result['id'], UPGRADES_NOW))->toBeTrue();
});

test('a change takes the client money lock that refunds take (D-09)', function (int $got, string $status): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    $dbal = Mockery::mock(Connection::class);
    $dbal->shouldReceive('getDatabasePlatform')->andReturn(new Doctrine\DBAL\Platforms\MySQLPlatform());
    $dbal->shouldReceive('fetchOne')->once()->with('SELECT GET_LOCK(?, 10)', ['fb:credit_payment:client:5'])->andReturn($got);
    $dbal->shouldReceive('fetchOne')->times($got)->with('SELECT RELEASE_LOCK(?)', ['fb:credit_payment:client:5']);
    $di['dbal'] = $dbal;

    expect(Upgrades::request($di, upgradesOrder($di, 3), 1, '1M', UPGRADES_NOW)['status'])->toBe($status)
        ->and($db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe($got);
})->with(['free' => [1, 'applied'], 'busy' => [0, 'failed']]);

test('staff move a service to a compatible product, as WHMCS', function (bool $recalculate, bool $changePackage, float $price, array $planChanges): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    $di['services']['invoice_subscription']->shouldReceive('cancelForOrder')->times($recalculate ? 1 : 0)->andReturn(0);

    expect(Upgrades::moveTargets($di, upgradesOrder($di, 5)))->toBe([21 => 'Host M']);
    Upgrades::staffMove($di, 5, 21, $recalculate, $changePackage);

    $row = $db->fetchAssociative('SELECT product_id, title, price, period, expires_at, config FROM client_order WHERE id = 5');
    expect($row)->toMatchArray(['product_id' => 21, 'title' => 'Host M example.com', 'price' => $price, 'period' => '1Y', 'expires_at' => '2027-01-01 00:00:00'])
        ->and(json_decode((string) $row['config'], true))->toMatchArray(['product_id' => 21, 'hosting_plan_id' => 8, 'domain' => 'example.com'])
        ->and($di['db']->planChanges)->toBe($planChanges)
        ->and((int) $db->fetchOne('SELECT COUNT(*) FROM invoice WHERE id < 9000'))->toBe(0);
})->with([
    'recalculated, Change Package' => [true, true, 200.0, [[5, 8]]],
    'price kept, no Change Package' => [false, false, 100.0, []],
]);

test('staff cannot move a service to a product on another server or of another reseller kind', function (int $productId): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);

    expect(fn () => Upgrades::staffMove($di, 5, $productId, true, true))->toThrow(InformationException::class)
        ->and((int) $db->fetchOne('SELECT product_id FROM client_order WHERE id = 5'))->toBe(20)
        ->and($di['db']->planChanges)->toBe([]);
})->with(['other server' => [22], 'reseller' => [23], 'same product' => [20]]);

test('a product out of stock refuses the move before the panel changes', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db);
    $di['services']['product']->shouldReceive('reduceStock')->once()->with(21, 1)->andThrow(new InformationException('Product 21 is out of stock.'));

    expect(fn () => Upgrades::staffMove($di, 5, 21, true, true))->toThrow(InformationException::class, 'out of stock')
        ->and($di['db']->planChanges)->toBe([])
        ->and((int) $db->fetchOne('SELECT product_id FROM client_order WHERE id = 5'))->toBe(20);
});

test('a Change Package the panel refuses moves nothing and gives the stock back', function (): void {
    $db = upgradesDb();
    $di = upgradesContainer($db, [], ['panel' => 'Panel down']);
    $di['services']['product']->shouldReceive('reduceStock')->once()->with(21, 1)->andReturn(true);
    $di['services']['product']->shouldReceive('returnStock')->once()->with(21, 1);

    expect(fn () => Upgrades::staffMove($di, 5, 21, true, true))->toThrow(RuntimeException::class, 'Panel down')
        ->and($db->fetchAssociative('SELECT product_id, price FROM client_order WHERE id = 5'))->toBe(['product_id' => 20, 'price' => 100.0]);
});
