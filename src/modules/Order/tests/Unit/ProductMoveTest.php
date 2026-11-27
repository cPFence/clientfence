<?php

declare(strict_types=1);

/**
 * ClientFence: Orders > Move services (Order\ProductMove) on the real services over an in-memory SQLite database. A
 * stand-in Enhance manager finds accounts by domain and records any create call.
 *
 * Product 1 "Imported" (custom), product 2 "Web Pro" (hosting, server 1, plan 1). Client 5 Ann. Custom orders of
 * product 1, each with service_custom row = order id and the import's history note:
 * - 1 site.example (on the panel)      - 2 and 3 dup.example (two services, one domain)
 * - 4 missing.example (not on the panel) - 5 broken.example (on the panel; its activation fails)
 * Product 3 "Imported hosting" (hosting, server 1, plan 1): order 6 hosted.example without a login (an import from a
 * system that keeps none), order 7 linked.example with one.
 */

use ClientFence\Mod\Order\Entity\Order;
use ClientFence\Mod\Order\Entity\OrderMeta;
use ClientFence\Mod\Order\Entity\OrderStatus;
use ClientFence\Mod\Order\ProductMove;
use ClientFence\Mod\Product\Entity\Product;
use ClientFence\Mod\Servicehosting\Panel\Enhance\Panel;
use ClientFence\Doctrine\DriverManagerFactory;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Tools\SchemaTool;
use RedBeanPHP\Facade;

beforeEach(function (): void {
    $this->previousToolboxes = Facade::$toolboxes;
    $this->previousDatabase = Facade::$currentDB;
    Facade::$toolboxes = [];
    $this->sharedProperty = new ReflectionProperty(DriverManagerFactory::class, 'sharedConnection');
    $this->previousConnection = $this->sharedProperty->getValue();
    $db = $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $this->sharedProperty->setValue(null, $db);
    $di = $this->di = require PATH_ROOT . '/di.php';
    $di['pdo'] = $db->getNativeConnection();
    $di['logger'] = $this->log = new class {
        public array $lines = [];

        public function __call(string $name, array $args): void
        {
            $this->lines[] = vsprintf((string) array_shift($args), $args);
        }
    };
    $di['events_manager'] = Mockery::mock(ClientFence\EventManager::class)->shouldIgnoreMissing();
    $di['db'];
    Facade::freeze(true);
    $em = $di['em'];
    $schema = (new SchemaTool($em))->getSchemaFromMetadata(array_map(fn (string $class) => $em->getClassMetadata($class), [Order::class, OrderMeta::class, OrderStatus::class, Product::class]));
    foreach ($schema->getTables() as $table) {
        foreach ($table->getIndexes() as $index) {
            if (!$index->isPrimary()) {
                $table->renameIndex($index->getName(), $table->getName() . '_' . $index->getName());
            }
        }
    }
    foreach ([
        ...$schema->toSql($db->getDatabasePlatform()),
        'CREATE TABLE client (id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT, company TEXT, address_1 TEXT, postcode TEXT, city TEXT, state TEXT, country TEXT, phone TEXT, email_approved INTEGER, lang TEXT)',
        'CREATE TABLE service_custom (id INTEGER PRIMARY KEY, client_id INTEGER, plugin TEXT, plugin_config TEXT, config TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE service_hosting (id INTEGER PRIMARY KEY, client_id INTEGER, service_hosting_server_id INTEGER, service_hosting_hp_id INTEGER, sld TEXT, tld TEXT, ip TEXT, username TEXT, pass TEXT, reseller INTEGER, panel_org_id TEXT, panel_subscription_id INTEGER, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE service_hosting_server (id INTEGER PRIMARY KEY, name TEXT, ip TEXT, hostname TEXT, manager TEXT, active INTEGER, max_accounts INTEGER, ns1 TEXT, ns2 TEXT, ns3 TEXT, ns4 TEXT, config TEXT)',
        'CREATE TABLE service_hosting_hp (id INTEGER PRIMARY KEY, name TEXT, config TEXT)',
        'CREATE TABLE product_payment (id INTEGER PRIMARY KEY, type TEXT, w_price REAL DEFAULT 0, m_price REAL DEFAULT 0, q_price REAL DEFAULT 0, b_price REAL DEFAULT 0, a_price REAL DEFAULT 0, bia_price REAL DEFAULT 0, tria_price REAL DEFAULT 0,'
            . ' w_enabled INTEGER DEFAULT 0, m_enabled INTEGER DEFAULT 0, q_enabled INTEGER DEFAULT 0, b_enabled INTEGER DEFAULT 0, a_enabled INTEGER DEFAULT 0, bia_enabled INTEGER DEFAULT 0, tria_enabled INTEGER DEFAULT 0)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    $db->insert('product_payment', ['id' => 1, 'type' => 'recurrent', 'm_price' => 9.99, 'm_enabled' => 1]);
    $db->insert('product', ['id' => 1, 'title' => 'Imported', 'type' => 'custom', 'status' => 'enabled', 'is_addon' => 0, 'config' => '{}', 'product_payment_id' => 1]);
    // A custom product to move to, out of stock.
    $db->insert('product', ['id' => 4, 'title' => 'Other', 'type' => 'custom', 'status' => 'enabled', 'is_addon' => 0, 'config' => '{}', 'product_payment_id' => 1, 'stock_control' => 1, 'quantity_in_stock' => 0]);
    $db->insert('product', ['id' => 2, 'title' => 'Web Pro', 'type' => 'hosting', 'status' => 'enabled', 'is_addon' => 0, 'config' => '{"server_id":1,"hosting_plan_id":1,"reseller":false}']);
    $db->insert('client', ['id' => 5, 'email' => 'ann@example.test', 'first_name' => 'Ann', 'last_name' => 'Lee', 'email_approved' => 1]);
    $db->insert('service_hosting_server', ['id' => 1, 'name' => 'Web 1', 'ip' => '192.0.2.1', 'manager' => 'Enhance', 'active' => 1, 'max_accounts' => 0]);
    $db->insert('service_hosting_hp', ['id' => 1, 'name' => 'Pro']);
    foreach ([1 => 'site.example', 2 => 'dup.example', 3 => 'dup.example', 4 => 'missing.example', 5 => 'broken.example'] as $id => $domain) {
        $config = '{"product_id":1,"type":"custom","period":"1M","quantity":1,"whmcs_service_id":' . (10 + $id) . '}';
        $db->insert('service_custom', ['id' => $id, 'client_id' => 5, 'config' => $config]);
        $db->insert('client_order', [
            'id' => $id, 'client_id' => 5, 'product_id' => 1, 'title' => 'Imported', 'status' => 'active', 'service_type' => 'custom', 'service_id' => $id,
            'period' => '1M', 'price' => 9.99, 'quantity' => 1, 'currency' => 'USD', 'config' => $config,
            'expires_at' => '2027-01-15 00:00:00', 'activated_at' => '2025-01-15 00:00:00', 'notes' => 'Imported staff note',
        ]);
        $db->insert('client_order_status', ['client_order_id' => $id, 'status' => 'active', 'notes' => sprintf('Imported from WHMCS (Service #%d (%s), status Active)', 10 + $id, $domain)]);
    }

    $db->insert('product', ['id' => 3, 'title' => 'Imported hosting', 'type' => 'hosting', 'status' => 'enabled', 'is_addon' => 0, 'config' => '{"server_id":1,"hosting_plan_id":1}']);
    // Order 6's row lacks its server and plan, as some imports leave it.
    foreach ([6 => ['hosted', null, null], 7 => ['linked', 'ann@example.test', 1]] as $id => [$sld, $username, $server]) {
        $db->insert('service_hosting', ['id' => $id, 'client_id' => 5, 'service_hosting_server_id' => $server, 'service_hosting_hp_id' => $server, 'sld' => $sld, 'tld' => '.example', 'username' => $username]);
        $db->insert('client_order', ['id' => $id, 'client_id' => 5, 'product_id' => 3, 'title' => "Imported hosting for {$sld}.example", 'status' => 'active', 'service_type' => 'hosting', 'service_id' => $id, 'period' => '1M', 'price' => 5, 'currency' => 'USD', 'config' => '{}']);
    }

    // Mockery cannot mock the manager (its `never` methods), so a stand-in answers the lookup.
    $this->adapter = new class extends Server_Manager_Enhance {
        public array $created = [];
        /** @var array<int, list<string>> the websites of each subscription (default: the account's own) */
        public array $websites = [];
        public bool $offline = false;
        /** Runs after the check, before the move's transaction (a change made meanwhile). */
        public ?Closure $onSync = null;

        public function __construct()
        {
        }

        public function linkAccount(Server_Account $account): array
        {
            if ($this->offline) {
                throw new RuntimeException('Server offline');
            }

            return match ($account->getDomain()) {
                'site.example' => ['org' => 'O1', 'subscription' => 71],
                'broken.example' => ['org' => 'O5', 'subscription' => 75],
                'hosted.example' => ['org' => 'O6', 'subscription' => 76],
                'xn--bcher-kva.example' => ['org' => 'O4', 'subscription' => 74],
                default => strlen((string) $account->getDomain()) > 200 ? ['org' => 'OL', 'subscription' => 80] : throw new Server_Exception('No website for :domain:', [':domain:' => $account->getDomain()], Panel::ACCOUNT_NOT_FOUND),
            };
        }

        public function synchronizeAccount(Server_Account $account): Server_Account
        {
            $this->onSync?->__invoke();

            return (clone $account)->setUsername('ann@example.test');
        }

        public function clientfenceWebsites(Server_Account $account): array
        {
            return $this->websites[$account->getPanelSubscriptionId()] ?? [(string) $account->getDomain()];
        }

        public function createAccount(Server_Account $account): bool
        {
            $this->created[] = $account->getDomain();

            return true;
        }
    };
    $hosting = Mockery::mock(ClientFence\Mod\Servicehosting\Service::class)->makePartial();
    $hosting->shouldReceive('getServerManager')->andReturn($this->adapter);
    $hosting->shouldReceive('getServerPackage')->andReturn(new Server_Package());
    // The activation of order 5 fails after its hosting row and order were stored: the whole move of it rolls back.
    $hosting->shouldReceive('action_activate')->with(Mockery::on(fn ($order): bool => (int) $order->id === 5))->andThrow(new RuntimeException('Activation failed'));
    $hosting->shouldReceive('action_activate')->passthru();
    $hosting->setDi($di);
    $base = $di['mod_service'];
    // Automatic renewal promotions by product id (discount per renewal); everything else is the real product service.
    $this->promos = new ArrayObject();
    $products = new class($base('product'), $this->promos) {
        public function __construct(private readonly object $real, private readonly ArrayObject $promos)
        {
        }

        public function getAutoRenewalPromoAdjustment(Model_ClientOrder $order, Model_Client $client, float $price, float $quantity, bool $preview = false): ?array
        {
            return isset($this->promos[(int) $order->product_id]) ? ['discount_amount' => $this->promos[(int) $order->product_id]] : null;
        }

        public function __call(string $name, array $args): mixed
        {
            return $this->real->{$name}(...$args);
        }
    };
    $di['mod_service'] = $di->protect(fn (string $mod, string $sub = '') => match ($sub === '' ? strtolower($mod) : '') {
        'servicehosting' => $hosting,
        'product' => $products,
        default => $base($mod, $sub),
    });
});

afterEach(function (): void {
    Facade::close();
    $this->connection->close();
    $this->sharedProperty->setValue(null, $this->previousConnection);
    Facade::$toolboxes = $this->previousToolboxes;
    Facade::$currentDB = '';
    if ($this->previousDatabase !== '') {
        Facade::selectDatabase($this->previousDatabase);
    }
});

test('the preview links by domain and says why a miss or a double match cannot move, changing nothing', function (): void {
    $preview = ProductMove::preview($this->di, ['from_product_id' => 1, 'to_product_id' => 2, 'statuses' => ['active']]);
    $rows = array_column($preview['rows'], null, 'id');

    expect($preview['total'])->toBe(5)
        ->and($preview['movable'])->toBe(2)
        ->and($rows[1]['domain'])->toBe('site.example')
        ->and($rows[1]['link'])->toBe(['org' => 'O1', 'subscription' => 71])
        ->and($rows[1]['reason'])->toBeNull()
        ->and($rows[2]['reason'])->toBe('Several services of this product have this domain.')
        ->and($rows[3]['reason'])->toBe('Several services of this product have this domain.')
        ->and($rows[4]['reason'])->toBe('No account of this client with this domain was found on the server.')
        ->and($rows[5]['reason'])->toBeNull()
        ->and($this->connection->fetchOne('SELECT COUNT(*) FROM service_hosting'))->toBe(2)
        ->and($this->connection->fetchFirstColumn('SELECT DISTINCT service_type FROM client_order WHERE product_id = 1'))->toBe(['custom']);
});

test('the move links a custom order to its existing account, keeps its terms and leaves a failed one untouched', function (): void {
    $before = $this->connection->fetchAssociative('SELECT * FROM client_order WHERE id = 5');

    $result = ProductMove::move($this->di, ['from_product_id' => 1, 'to_product_id' => 2, 'order_ids' => ['5', '1', '4']]);

    expect($result['moved'])->toBe(1)
        ->and($result['results'])->toBe([
            ['id' => 5, 'moved' => false, 'note' => 'Activation failed'],
            ['id' => 1, 'moved' => true, 'note' => null],
            ['id' => 4, 'moved' => false, 'note' => 'No account of this client with this domain was found on the server.'],
        ])
        ->and($this->adapter->created)->toBe([]);

    $order = $this->connection->fetchAssociative('SELECT * FROM client_order WHERE id = 1');
    $service = $this->connection->fetchAssociative('SELECT * FROM service_hosting WHERE id = ?', [$order['service_id']]);
    $config = json_decode($order['config'], true);
    expect($order)->toMatchArray(['product_id' => 2, 'service_type' => 'hosting', 'title' => 'Web Pro for site.example', 'status' => 'active', 'client_id' => 5, 'period' => '1M', 'notes' => 'Imported staff note'])
        ->and((float) $order['price'])->toBe(9.99)
        ->and($order['expires_at'])->toBe('2027-01-15 00:00:00')
        ->and($config)->toMatchArray(['product_id' => 2, 'server_id' => 1, 'hosting_plan_id' => 1, 'import' => true, 'whmcs_service_id' => 11])
        ->and($service)->toMatchArray(['client_id' => 5, 'service_hosting_server_id' => 1, 'service_hosting_hp_id' => 1, 'sld' => 'site', 'tld' => '.example', 'username' => 'ann@example.test', 'panel_org_id' => 'O1', 'panel_subscription_id' => 71])
        ->and($this->connection->fetchOne('SELECT COUNT(*) FROM service_custom WHERE id = 1'))->toBe(0)
        ->and($this->connection->fetchOne('SELECT notes FROM client_order_status WHERE client_order_id = 1 ORDER BY id DESC'))->toBe('Moved by staff from Imported to Web Pro, linked to the existing account site.example on the server')
        ->and($this->log->lines)->toContain('Order #1: Moved by staff from Imported to Web Pro, linked to the existing account site.example on the server');

    // Order 5: as it was, its custom service kept, no hosting row left behind.
    expect($this->connection->fetchAssociative('SELECT * FROM client_order WHERE id = 5'))->toBe($before)
        ->and($this->connection->fetchOne('SELECT COUNT(*) FROM service_custom WHERE id = 5'))->toBe(1)
        ->and($this->connection->fetchOne("SELECT COUNT(*) FROM service_hosting WHERE sld = 'broken'"))->toBe(0);
});

test('a hosting service without a login is linked in place, so its renewals and panel actions work', function (): void {
    $preview = ProductMove::preview($this->di, ['from_product_id' => 3, 'to_product_id' => 3, 'statuses' => ['active']]);
    expect(array_column($preview['rows'], 'id'))->toBe([6])
        ->and($preview['rows'][0]['link'])->toBe(['org' => 'O6', 'subscription' => 76]);

    $result = ProductMove::move($this->di, ['from_product_id' => 3, 'to_product_id' => 3, 'order_ids' => [6, 7]]);

    expect($result['results'])->toBe([
        ['id' => 6, 'moved' => true, 'note' => null],
        ['id' => 7, 'moved' => false, 'note' => 'Already linked to its account.'],
    ])
        ->and($this->connection->fetchAssociative('SELECT username, panel_org_id, panel_subscription_id, service_hosting_server_id, service_hosting_hp_id FROM service_hosting WHERE id = 6'))
        ->toBe(['username' => 'ann@example.test', 'panel_org_id' => 'O6', 'panel_subscription_id' => 76, 'service_hosting_server_id' => 1, 'service_hosting_hp_id' => 1])
        ->and($this->connection->fetchOne('SELECT product_id FROM client_order WHERE id = 6'))->toBe(3)
        ->and($this->adapter->created)->toBe([])
        ->and(fn () => ProductMove::preview($this->di, ['from_product_id' => 3, 'to_product_id' => 2, 'statuses' => ['active']]))->toThrow(ClientFence\InformationException::class);
});

test('an explicit offline move leaves the account unlinked until it can be verified', function (): void {
    $this->adapter->offline = true;
    $filters = ['from_product_id' => 1, 'to_product_id' => 2, 'statuses' => ['active']];
    expect(array_column(ProductMove::preview($this->di, $filters)['rows'], null, 'id')[1]['reason'])->toBe('Server offline');

    $rows = array_column(ProductMove::preview($this->di, $filters + ['move_unlinked' => '1'])['rows'], null, 'id');
    expect($rows[1]['reason'])->toBeNull()
        ->and($rows[1]['link'])->toBeNull()
        ->and($rows[2]['reason'])->toBe('Several services of this product have this domain.');

    $result = ProductMove::move($this->di, ['from_product_id' => 1, 'to_product_id' => 2, 'order_ids' => [1, 2], 'move_unlinked' => '1']);
    $order = $this->di['db']->getExistingModelById('ClientOrder', 1);
    $service = $this->di['mod_service']('order')->getOrderService($order);
    expect($result['moved'])->toBe(1)
        ->and($result['results'][1]['moved'])->toBeFalse()
        ->and($order->product_id)->toBe(2)
        ->and($order->service_type)->toBe('hosting')
        ->and($service->username)->toBeNull()
        ->and($service->panel_subscription_id)->toBeNull()
        ->and($this->adapter->created)->toBe([])
        ->and(fn () => $this->di['mod_service']('servicehosting')->action_activate($order))->toThrow(ClientFence\InformationException::class, 'Link it before using hosting actions.')
        ->and(fn () => $this->di['mod_service']('servicehosting')->action_renew($order))->toThrow(ClientFence\InformationException::class, 'Link it before using hosting actions.')
        ->and(fn () => $this->di['mod_service']('servicehosting')->action_suspend($order))->toThrow(ClientFence\InformationException::class, 'Link it before using hosting actions.')
        ->and(fn () => $this->di['mod_service']('servicehosting')->action_cancel($order))->toThrow(ClientFence\InformationException::class, 'Link it before using hosting actions.');

    $this->adapter->offline = false;
    expect(ProductMove::move($this->di, ['from_product_id' => 2, 'to_product_id' => 2, 'order_ids' => [1]])['moved'])->toBe(1);
    $order = $this->di['db']->getExistingModelById('ClientOrder', 1);
    $service = $this->di['mod_service']('order')->getOrderService($order);
    expect($service->username)->toBe('ann@example.test')
        ->and((int) $service->panel_subscription_id)->toBe(71)
        ->and($this->di['mod_service']('order')->getConfig($order))->not->toHaveKey('clientfence_move_unlinked');
});

test('offline override must be explicit and cannot pretend to link in place', function (): void {
    $this->adapter->offline = true;
    $data = ['from_product_id' => 1, 'to_product_id' => 2, 'order_ids' => [1]];
    expect(ProductMove::move($this->di, $data + ['move_unlinked' => 'false'])['results'][0]['note'])->toBe('Server offline')
        ->and(fn () => ProductMove::move($this->di, ['from_product_id' => 3, 'to_product_id' => 3, 'order_ids' => [6], 'move_unlinked' => '1']))->toThrow(ClientFence\InformationException::class, 'The unlinked move option is only for moves');
});

test('a client whose email is not confirmed is never linked to the panel account found by that email', function (): void {
    // Neither trust: not confirmed, and no service of the client holds the email as its login (order 7 did).
    $this->connection->update('client', ['email_approved' => 0], ['id' => 5]);
    $this->connection->update('service_hosting', ['username' => 'other@example.test'], ['id' => 7]);

    $rows = array_column(ProductMove::preview($this->di, ['from_product_id' => 1, 'to_product_id' => 2, 'statuses' => ['active']])['rows'], null, 'id');
    $result = ProductMove::move($this->di, ['from_product_id' => 1, 'to_product_id' => 2, 'order_ids' => [1]]);

    expect($rows[1]['reason'])->toBe("The client's email address is not confirmed.")
        ->and($rows[1]['link'])->toBeNull()
        ->and($result['results'])->toBe([['id' => 1, 'moved' => false, 'note' => "The client's email address is not confirmed."]])
        ->and($this->connection->fetchOne('SELECT service_type FROM client_order WHERE id = 1'))->toBe('custom');
});

test('the move keeps the order\'s own proration day, so its next renewal date does not change', function (): void {
    $config = json_decode((string) $this->connection->fetchOne('SELECT config FROM client_order WHERE id = 1'), true);
    $this->connection->update('client_order', ['config' => json_encode(['prorata_day' => 28] + $config), 'expires_at' => '2027-02-28 00:00:00'], ['id' => 1]);
    $this->connection->update('product', ['config' => '{"server_id":1,"hosting_plan_id":1,"prorata_day":31}'], ['id' => 2]);
    $orders = $this->di['mod_service']('order');
    $next = fn (): string => date('Y-m-d', $orders->periodEnd('1M', strtotime('2027-02-28'), '2025-01-15 00:00:00', 1, $orders->prorataDay($this->di['db']->load('ClientOrder', 1))));
    $before = $next();

    expect(ProductMove::move($this->di, ['from_product_id' => 1, 'to_product_id' => 2, 'order_ids' => [1]])['moved'])->toBe(1)
        ->and($this->connection->fetchOne('SELECT service_type FROM client_order WHERE id = 1'))->toBe('hosting')
        ->and($next())->toBe($before)
        ->and($before)->toBe('2027-03-28');
});

test('a service whose client changed after the check is not moved onto the account found for the old client', function (): void {
    $this->connection->insert('client', ['id' => 6, 'email' => 'bob@example.test', 'first_name' => 'Bob', 'email_approved' => 1]);
    $this->adapter->onSync = fn () => $this->connection->update('client_order', ['client_id' => 6], ['id' => 1]);

    $result = ProductMove::move($this->di, ['from_product_id' => 1, 'to_product_id' => 2, 'order_ids' => [1]]);

    expect($result['results'])->toBe([['id' => 1, 'moved' => false, 'note' => 'This service changed while it was being moved. Preview it again.']])
        ->and($this->connection->fetchOne('SELECT service_type FROM client_order WHERE id = 1'))->toBe('custom')
        ->and($this->connection->fetchOne('SELECT COUNT(*) FROM service_hosting WHERE panel_subscription_id = 71'))->toBe(0);
});

test('an account another service took after the check is not linked twice', function (): void {
    $this->adapter->onSync = fn () => $this->connection->insert('service_hosting', ['id' => 9, 'client_id' => 5, 'sld' => 'other', 'tld' => '.example', 'panel_org_id' => 'O1', 'panel_subscription_id' => 71]);

    $result = ProductMove::move($this->di, ['from_product_id' => 1, 'to_product_id' => 2, 'order_ids' => [1]]);

    expect($result['results'])->toBe([['id' => 1, 'moved' => false, 'note' => 'This account is already linked to hosting service #9.']])
        ->and($this->connection->fetchOne('SELECT service_type FROM client_order WHERE id = 1'))->toBe('custom');
});

test('a service is not linked to an account whose other website is a service not linked yet, also of another client', function (): void {
    $this->adapter->websites[71] = ['site.example', 'shop.example'];
    // Order 9 was transferred to client 6: it keeps the panel account of client 5.
    $this->connection->insert('client', ['id' => 6, 'email' => 'bob@example.test', 'first_name' => 'Bob', 'email_approved' => 1]);
    $this->connection->insert('service_hosting', ['id' => 9, 'client_id' => 6, 'service_hosting_server_id' => 1, 'service_hosting_hp_id' => 1, 'sld' => 'shop', 'tld' => '.example', 'username' => 'ann@example.test']);
    $this->connection->insert('client_order', ['id' => 9, 'client_id' => 6, 'product_id' => 3, 'title' => 'Shop', 'status' => 'active', 'service_type' => 'hosting', 'service_id' => 9, 'currency' => 'USD', 'config' => '{}']);

    $rows = array_column(ProductMove::preview($this->di, ['from_product_id' => 1, 'to_product_id' => 2, 'statuses' => ['active']])['rows'], null, 'id');

    expect($rows[1]['reason'])->toBe('Order #9 (shop.example) is a website of this account too: two services cannot share one account.');
});

test('a move to another custom product is previewed with the checks the move makes (stock)', function (): void {
    $rows = array_column(ProductMove::preview($this->di, ['from_product_id' => 1, 'to_product_id' => 4, 'statuses' => ['active']])['rows'], null, 'id');
    $result = ProductMove::move($this->di, ['from_product_id' => 1, 'to_product_id' => 4, 'order_ids' => [1]]);

    expect($rows[1]['reason'])->toBe('Product 4 is out of stock.')
        ->and($result['results'][0]['moved'])->toBeFalse()
        ->and($this->connection->fetchOne('SELECT product_id FROM client_order WHERE id = 1'))->toBe(1);
});

test('a domain the import recorded in its own script is looked up in its ASCII form', function (): void {
    $this->connection->update('client_order_status', ['notes' => 'Imported from WHMCS (Service #14 (bücher.example), status Active)'], ['client_order_id' => 4]);

    $rows = array_column(ProductMove::preview($this->di, ['from_product_id' => 1, 'to_product_id' => 2, 'statuses' => ['active']])['rows'], null, 'id');

    expect($rows[4]['domain'])->toBe('xn--bcher-kva.example')
        ->and($rows[4]['link'])->toBe(['org' => 'O4', 'subscription' => 74]);
});

test('link in place keeps a link staff set by hand and only fills in the login', function (): void {
    $this->connection->update('service_hosting', ['panel_org_id' => 'O9', 'panel_subscription_id' => 99], ['id' => 6]);

    $result = ProductMove::move($this->di, ['from_product_id' => 3, 'to_product_id' => 3, 'order_ids' => [6]]);

    expect($result['moved'])->toBe(1)
        ->and($this->connection->fetchAssociative('SELECT username, panel_org_id, panel_subscription_id FROM service_hosting WHERE id = 6'))
        ->toBe(['username' => 'ann@example.test', 'panel_org_id' => 'O9', 'panel_subscription_id' => 99]);
});

test('a hosting row that spells the domain in its own script counts as that domain', function (): void {
    $this->connection->update('client_order_status', ['notes' => 'Imported from WHMCS (Service #14 (bücher.example), status Active)'], ['client_order_id' => 4]);
    $this->connection->insert('service_hosting', ['id' => 9, 'client_id' => 5, 'service_hosting_server_id' => 1, 'service_hosting_hp_id' => 1, 'sld' => 'bücher', 'tld' => '.example']);
    $this->connection->insert('client_order', ['id' => 9, 'client_id' => 5, 'product_id' => 3, 'title' => 'Books', 'status' => 'active', 'service_type' => 'hosting', 'service_id' => 9, 'currency' => 'USD', 'config' => '{}']);

    $rows = array_column(ProductMove::preview($this->di, ['from_product_id' => 1, 'to_product_id' => 2, 'statuses' => ['active']])['rows'], null, 'id');

    expect($rows[4]['reason'])->toBe('Order #9 is already a hosting service for this domain.');
});

test('link in place stores the domain in the ASCII form it was found by', function (): void {
    $this->connection->update('service_hosting', ['sld' => 'bücher'], ['id' => 6]);

    expect(ProductMove::move($this->di, ['from_product_id' => 3, 'to_product_id' => 3, 'order_ids' => [6]])['moved'])->toBe(1)
        ->and($this->connection->fetchAssociative('SELECT sld, tld, panel_subscription_id FROM service_hosting WHERE id = 6'))->toBe(['sld' => 'xn--bcher-kva', 'tld' => '.example', 'panel_subscription_id' => 74]);
});

test('on MySQL one move or link of an account runs at a time', function (int $got): void {
    // The calls are recorded and checked here, so a failure stays an assertion of this test.
    $calls = [];
    $db = Mockery::mock(Doctrine\DBAL\Connection::class);
    $db->shouldReceive('getDatabasePlatform')->andReturn(new Doctrine\DBAL\Platforms\MySQLPlatform());
    $db->shouldReceive('fetchOne')->andReturnUsing(function (string $sql, array $params) use (&$calls, $got): int {
        $calls[] = [$sql, $params];

        return str_contains($sql, 'GET_LOCK') ? $got : 1;
    });
    $name = 'cf:panel:' . sha1('O1/71');
    $lock = Closure::bind(fn (): Closure => ProductMove::lock($db, ['org' => 'O1', 'subscription' => 71]), null, ProductMove::class);

    if ($got === 1) {
        $lock()();
        expect($calls)->toBe([['SELECT GET_LOCK(?, 10)', [$name]], ['SELECT RELEASE_LOCK(?)', [$name]]]);
    } else {
        expect($lock)->toThrow(ClientFence\InformationException::class, 'Another move or link of this account is running.')
            ->and($calls)->toBe([['SELECT GET_LOCK(?, 10)', [$name]]]);
    }
})->with(['free' => 1, 'held' => 0]);

test('a product whose automatic promotion would discount the renewal is not offered as the target', function (): void {
    $this->promos[2] = 2.0;

    $rows = array_column(ProductMove::preview($this->di, ['from_product_id' => 1, 'to_product_id' => 2, 'statuses' => ['active']])['rows'], null, 'id');

    expect($rows[1]['reason'])->toBe('An automatic promotion of the new product would change the renewal price of this service.');
});

test('the new order title is cut to the column length', function (): void {
    $domain = implode('.', array_fill(0, 4, str_repeat('a', 60))) . '.example';
    $this->connection->update('client_order_status', ['notes' => "Imported from WHMCS (Service #11 ({$domain}), status Active)"], ['client_order_id' => 1]);

    expect(ProductMove::move($this->di, ['from_product_id' => 1, 'to_product_id' => 2, 'order_ids' => [1]])['moved'])->toBe(1)
        ->and(mb_strlen((string) $this->connection->fetchOne('SELECT title FROM client_order WHERE id = 1')))->toBe(255);
});
