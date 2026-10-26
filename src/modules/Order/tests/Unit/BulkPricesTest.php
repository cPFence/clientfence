<?php

declare(strict_types=1);

/**
 * ClientFence: Orders > Bulk price update (Order\BulkPrices) on an in-memory SQLite copy of the
 * tables it uses, with a fake email service.
 *
 * Catalog (USD prices; EUR = 0.9 USD):
 * - 1 Starter   custom   1M 5, 1Y 50
 * - 2 Pro       hosting  1Y 120
 * - 3 Domain    domain   (renews at the TLD price: never listed)
 * - 4 Extra     custom add-on, 1M 3
 *
 * Orders (client 5 Ann, client 6 Bob):
 * - 1 Starter 1M 5 active          - 2 Starter 1M 4 active (special price)
 * - 3 Starter 1Y 50 suspended      - 4 Starter 1M 5 canceled
 * - 5 Pro 1Y 100 active, unpaid invoice 50, gateway subscription (client 6)
 * - 6 Pro 1Y 110 EUR active (client 6)   - 7 Domain 1Y 10 active
 * - 8 Starter (no period) active    - 9 Starter 3M 14 pending setup (no 3M price)
 * - 10 Extra 1M 3 active x2 (client 6), recurring promo discount 7.50
 */

use ClientFence\InformationException;
use ClientFence\Mod\Order\BulkPrices;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

function bulkDb(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
        'CREATE TABLE product (id INTEGER PRIMARY KEY, title TEXT, type TEXT, status TEXT, is_addon INTEGER DEFAULT 0, config TEXT, upgrades TEXT, product_payment_id INTEGER)',
        'CREATE TABLE product_payment (id INTEGER PRIMARY KEY, type TEXT, w_price REAL DEFAULT 0, m_price REAL DEFAULT 0, q_price REAL DEFAULT 0, b_price REAL DEFAULT 0, a_price REAL DEFAULT 0, bia_price REAL DEFAULT 0, tria_price REAL DEFAULT 0,'
            . ' w_enabled INTEGER DEFAULT 0, m_enabled INTEGER DEFAULT 0, q_enabled INTEGER DEFAULT 0, b_enabled INTEGER DEFAULT 0, a_enabled INTEGER DEFAULT 0, bia_enabled INTEGER DEFAULT 0, tria_enabled INTEGER DEFAULT 0)',
        'CREATE TABLE currency (code TEXT PRIMARY KEY, conversion_rate REAL, is_default INTEGER DEFAULT 0)',
        'CREATE TABLE client (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, company TEXT)',
        'CREATE TABLE client_order (id INTEGER PRIMARY KEY, client_id INTEGER, product_id INTEGER, title TEXT, status TEXT, period TEXT, price REAL, quantity INTEGER DEFAULT 1,'
            . ' discount REAL, promo_recurring INTEGER, currency TEXT, expires_at TEXT, unpaid_invoice_id INTEGER, config TEXT, updated_at TEXT)',
        'CREATE TABLE client_order_status (id INTEGER PRIMARY KEY, client_order_id INTEGER, status TEXT, notes TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE invoice (id INTEGER PRIMARY KEY, status TEXT)',
        'CREATE TABLE invoice_item (id INTEGER PRIMARY KEY, invoice_id INTEGER, type TEXT, rel_id TEXT)',
        'CREATE TABLE subscription (id INTEGER PRIMARY KEY, rel_type TEXT, rel_id INTEGER, status TEXT)',
    ] as $sql) {
        $db->executeStatement($sql);
    }

    $product = function (int $id, string $title, string $type, array $prices, int $addon = 0) use ($db): void {
        $payment = ['id' => $id, 'type' => 'recurrent'];
        foreach ($prices as $key => $price) {
            $payment["{$key}_price"] = $price;
            $payment["{$key}_enabled"] = 1;
        }
        $db->insert('product_payment', $payment);
        $db->insert('product', ['id' => $id, 'title' => $title, 'type' => $type, 'status' => 'enabled', 'is_addon' => $addon, 'config' => '{}', 'product_payment_id' => $id]);
    };
    $product(1, 'Starter', 'custom', ['m' => 5, 'a' => 50]);
    $product(2, 'Pro', 'hosting', ['a' => 120]);
    $product(3, 'Domain', 'domain', ['a' => 10]);
    $product(4, 'Extra', 'custom', ['m' => 3], 1);

    $db->insert('currency', ['code' => 'USD', 'conversion_rate' => 1, 'is_default' => 1]);
    $db->insert('currency', ['code' => 'EUR', 'conversion_rate' => 0.9]);
    $db->insert('client', ['id' => 5, 'first_name' => 'Ann', 'last_name' => 'Lee', 'company' => '']);
    $db->insert('client', ['id' => 6, 'first_name' => 'Bob', 'last_name' => 'Ray', 'company' => '']);

    $order = fn (int $id, array $row) => $db->insert('client_order', $row + [
        'id' => $id, 'client_id' => 5, 'status' => 'active', 'quantity' => 1, 'currency' => 'USD',
        'expires_at' => '2027-01-01 00:00:00', 'unpaid_invoice_id' => null, 'promo_recurring' => 0, 'discount' => 0,
    ]);
    $order(1, ['product_id' => 1, 'title' => 'Starter one', 'period' => '1M', 'price' => 5]);
    $order(2, ['product_id' => 1, 'title' => 'Starter two', 'period' => '1M', 'price' => 4]);
    $order(3, ['product_id' => 1, 'title' => 'Starter yearly', 'period' => '1Y', 'price' => 50, 'status' => 'suspended']);
    $order(4, ['product_id' => 1, 'title' => 'Starter gone', 'period' => '1M', 'price' => 5, 'status' => 'canceled']);
    $order(5, ['client_id' => 6, 'product_id' => 2, 'title' => 'Pro example.com', 'period' => '1Y', 'price' => 100, 'unpaid_invoice_id' => 50]);
    $order(6, ['client_id' => 6, 'product_id' => 2, 'title' => 'Pro euro', 'period' => '1Y', 'price' => 110, 'currency' => 'EUR']);
    $order(7, ['product_id' => 3, 'title' => 'example.com', 'period' => '1Y', 'price' => 10]);
    $order(8, ['product_id' => 1, 'title' => 'Starter once', 'period' => null, 'price' => 0]);
    $order(9, ['product_id' => 1, 'title' => 'Starter quarterly', 'period' => '3M', 'price' => 14, 'status' => 'pending_setup', 'expires_at' => null]);
    $order(10, ['client_id' => 6, 'product_id' => 4, 'title' => 'Extra', 'period' => '1M', 'price' => 3, 'quantity' => 2, 'promo_recurring' => 1, 'discount' => 7.5]);

    $db->insert('invoice', ['id' => 50, 'status' => 'unpaid']);
    $db->insert('invoice', ['id' => 40, 'status' => 'paid']);
    $db->insert('invoice_item', ['id' => 1, 'invoice_id' => 40, 'type' => 'order', 'rel_id' => '5']);
    $db->insert('subscription', ['id' => 1, 'rel_type' => 'invoice', 'rel_id' => 40, 'status' => 'active']);

    return $db;
}

final class BulkPricesTestMail
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];
    public ?int $failFor = null;

    public function sendTemplate(array $data): bool
    {
        if ($data['to_client'] === $this->failFor) {
            throw new RuntimeException('mail server down');
        }
        $this->sent[] = $data;

        return true;
    }
}

function bulkContainer(Connection $db, ?BulkPricesTestMail $mail = null): Pimple\Container
{
    $di = new Pimple\Container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;
    $di['logger'] = new class {
        public array $lines = [];

        public function __call(string $name, array $args): void
        {
            $this->lines[] = vsprintf((string) array_shift($args), $args);
        }
    };
    $mail ??= new BulkPricesTestMail();
    $di['mod_service'] = $di->protect(fn (string $name) => strtolower($name) === 'email' ? $mail : throw new RuntimeException("no service {$name}"));

    return $di;
}

/** @param array<string, mixed> $data */
function bulkFilters(Pimple\Container $di, array $data): array
{
    return BulkPrices::filters($di, $data + ['statuses' => ['active', 'suspended'], 'currency' => 'USD']);
}

/** @return array<int, array<string, mixed>> rows by order id */
function bulkRows(array $preview): array
{
    return array_column($preview['rows'], null, 'id');
}

test('options list the products whose renewals use the order price, with their services', function (): void {
    $options = BulkPrices::options(bulkContainer(bulkDb()));

    expect(array_column($options['products'], 'title', 'id'))->toBe([2 => 'Pro', 1 => 'Starter', 4 => 'Extra'])
        ->and(array_column($options['products'], 'orders', 'id'))->toBe([2 => 2, 1 => 4, 4 => 1])
        ->and($options['products'][2]['is_addon'])->toBeTrue()
        ->and($options['periods'])->toBe(['1M', '3M', '1Y'])
        ->and($options['currencies'])->toBe(['USD', 'EUR'])
        ->and($options['default_currency'])->toBe('USD')
        ->and($options['default_statuses'])->toBe(['active', 'suspended']);
});

test('the filters refuse what cannot be updated', function (array $data, string $message): void {
    $di = bulkContainer(bulkDb());

    expect(fn () => BulkPrices::filters($di, $data + ['product_ids' => [1], 'statuses' => ['active'], 'currency' => 'USD', 'mode' => 'price', 'amount' => '6']))
        ->toThrow(InformationException::class, $message);
})->with([
    'no product' => [['product_ids' => []], 'Choose at least one product.'],
    'a domain product' => [['product_ids' => [1, 3]], 'Unknown product'],
    'an unknown product' => [['product_ids' => [99]], 'Unknown product'],
    'no status' => [['statuses' => ['canceled']], 'Choose at least one order status.'],
    'an unknown currency' => [['currency' => 'GBP'], 'Unknown currency.'],
    'a bad period' => [['periods' => ['1M; DROP']], 'Unknown billing period.'],
    'no mode' => [['mode' => 'half'], 'Choose how to set the new price.'],
    'a bad price' => [['amount' => '6,5'], 'Enter the new price as a number'],
    'a negative price' => [['amount' => '-1'], 'Enter the new price as a number'],
    'three decimals' => [['amount' => '6.555'], 'Enter the new price as a number'],
    'a bad current price' => [['current_price' => 'abc'], 'Enter the current price as a number'],
    'adding 0' => [['mode' => 'increase', 'amount' => '0'], 'other than 0'],
    'too large' => [['amount' => '2000000'], 'too large'],
]);

test('a new price matches the chosen products, periods, statuses and currency', function (): void {
    $di = bulkContainer(bulkDb());
    $preview = BulkPrices::preview($di, bulkFilters($di, ['product_ids' => ['1', '2'], 'mode' => 'price', 'amount' => '5']));
    $rows = bulkRows($preview);

    // Not the canceled (4), EUR (6), domain (7), no-period (8) or pending (9) orders.
    expect(array_keys($rows))->toBe([5, 1, 2, 3])
        ->and($rows[1]['state'])->toBe('unchanged')
        ->and($rows[2])->toMatchArray(['state' => 'change', 'old_price' => 4.0, 'new_price' => 5.0, 'client' => 'Ann Lee'])
        ->and($rows[3])->toMatchArray(['state' => 'change', 'old_price' => 50.0, 'new_price' => 5.0, 'status' => 'suspended'])
        ->and($rows[5])->toMatchArray(['state' => 'change', 'unpaid_invoice_id' => 50, 'subscription' => true])
        ->and($preview)->toMatchArray(['matched' => 4, 'change' => 3, 'unchanged' => 1, 'skipped' => 0, 'unpaid_invoices' => 1, 'subscriptions' => 1, 'clients' => 2]);
});

test('periods and the current price narrow the matches', function (): void {
    $di = bulkContainer(bulkDb());
    $preview = BulkPrices::preview($di, bulkFilters($di, ['product_ids' => [1], 'periods' => ['1M'], 'current_price' => '5.00', 'mode' => 'price', 'amount' => '6']));

    expect(array_keys(bulkRows($preview)))->toBe([1]);
});

test('an amount is added to each price, and a price that would go below 0 is skipped', function (): void {
    $di = bulkContainer(bulkDb());
    $rows = bulkRows(BulkPrices::preview($di, bulkFilters($di, ['product_ids' => [1], 'periods' => ['1M'], 'mode' => 'increase', 'amount' => '-4.5'])));

    expect($rows[1])->toMatchArray(['state' => 'change', 'new_price' => 0.5])
        ->and($rows[2])->toMatchArray(['state' => 'skipped', 'skip' => BulkPrices::SKIP_BELOW_ZERO, 'new_price' => null]);
});

test("the product's price is used for each service's period and currency", function (): void {
    $di = bulkContainer(bulkDb());
    $usd = bulkRows(BulkPrices::preview($di, bulkFilters($di, ['product_ids' => [1, 2], 'statuses' => ['active', 'suspended', 'pending_setup'], 'mode' => 'product'])));
    $eur = bulkRows(BulkPrices::preview($di, bulkFilters($di, ['product_ids' => [2], 'currency' => 'EUR', 'mode' => 'product'])));

    expect($usd[1]['state'])->toBe('unchanged')
        ->and($usd[2]['new_price'])->toBe(5.0)
        ->and($usd[3]['state'])->toBe('unchanged')
        ->and($usd[5]['new_price'])->toBe(120.0)
        ->and($usd[9])->toMatchArray(['state' => 'skipped', 'skip' => BulkPrices::SKIP_NO_PRODUCT_PRICE])
        ->and($eur[6])->toMatchArray(['state' => 'change', 'old_price' => 110.0, 'new_price' => 108.0]);
});

test("a service's configurable options stay on top of the product's price, as sold", function (): void {
    $db = bulkDb();
    $db->update('client_order', ['config' => json_encode(['option_lines' => [['title' => 'RAM', 'price' => 10], ['title' => 'Backups', 'price' => 5], 'junk']])], ['id' => 6]);
    $di = bulkContainer($db);
    $eur = bulkRows(BulkPrices::preview($di, bulkFilters($di, ['product_ids' => [2], 'currency' => 'EUR', 'mode' => 'product'])));

    // (120 + 10 + 5) USD × 0.9.
    expect($eur[6])->toMatchArray(['state' => 'change', 'old_price' => 110.0, 'new_price' => 121.5]);
});

test('applying changes the prices, notes each order and emails each client once', function (): void {
    $db = bulkDb();
    $mail = new BulkPricesTestMail();
    $di = bulkContainer($db, $mail);
    $filters = bulkFilters($di, ['product_ids' => [1, 2, 4], 'periods' => ['1M', '1Y'], 'mode' => 'increase', 'amount' => '10']);
    $preview = BulkPrices::preview($di, $filters);

    $result = BulkPrices::apply($di, $filters, $preview['fingerprint'], true, 7);

    expect($result)->toBe(['updated' => 5, 'emailed' => 2, 'unpaid_invoices' => 1, 'subscriptions' => 1])
        ->and(array_map('floatval', $db->fetchAllKeyValue('SELECT id, price FROM client_order ORDER BY id')))
        ->toBe([1 => 15.0, 2 => 14.0, 3 => 60.0, 4 => 5.0, 5 => 110.0, 6 => 110.0, 7 => 10.0, 8 => 0.0, 9 => 14.0, 10 => 13.0])
        ->and($db->fetchAllKeyValue('SELECT client_order_id, notes FROM client_order_status ORDER BY client_order_id'))->toBe([
            1 => 'Recurring price changed from 5.00 to 15.00 USD by a bulk price update (staff #7).',
            2 => 'Recurring price changed from 4.00 to 14.00 USD by a bulk price update (staff #7).',
            3 => 'Recurring price changed from 50.00 to 60.00 USD by a bulk price update (staff #7).',
            5 => 'Recurring price changed from 100.00 to 110.00 USD by a bulk price update (staff #7).',
            10 => 'Recurring price changed from 3.00 to 13.00 USD by a bulk price update (staff #7).',
        ])
        ->and($db->fetchOne('SELECT status FROM client_order_status WHERE client_order_id = 3'))->toBe('suspended')
        // In the preview's order (product title): Extra and Pro are Bob's, Starter is Ann's.
        ->and(array_column($mail->sent, 'to_client'))->toBe([6, 5])
        ->and(array_column($mail->sent[0]['services'], 'id'))->toBe([10, 5])
        // Order 10 has a recurring discount of 7.50: 2 x 3 = 6 was free, 2 x 13 = 26 less 7.50.
        ->and($mail->sent[0]['services'][0])->toMatchArray(['old_total' => 0.0, 'new_total' => 18.5, 'discount' => 7.5, 'next_invoice' => '2027-01-01 00:00:00'])
        ->and($mail->sent[0]['services'][1])->toMatchArray(['old_total' => 100.0, 'new_total' => 110.0, 'discount' => 0.0, 'unpaid_invoice_id' => 50, 'next_invoice' => null])
        ->and(array_column($mail->sent[1]['services'], 'id'))->toBe([1, 2, 3])
        ->and($mail->sent[0]['code'])->toBe('mod_order_price_changed');
});

test('applying without the email sends nothing, and a failed email is counted out', function (): void {
    $db = bulkDb();
    $mail = new BulkPricesTestMail();
    $di = bulkContainer($db, $mail);
    $filters = bulkFilters($di, ['product_ids' => [1, 2], 'mode' => 'price', 'amount' => '7']);

    expect(BulkPrices::apply($di, $filters, BulkPrices::preview($di, $filters)['fingerprint'], false, null)['emailed'])->toBe(0)
        ->and($mail->sent)->toBe([]);

    $mail->failFor = 6;
    $filters = bulkFilters($di, ['product_ids' => [1, 2], 'mode' => 'price', 'amount' => '8']);
    expect(BulkPrices::apply($di, $filters, BulkPrices::preview($di, $filters)['fingerprint'], true, null)['emailed'])->toBe(1);
});

test('nothing is applied when the services changed since the preview', function (): void {
    $db = bulkDb();
    $di = bulkContainer($db);
    $filters = bulkFilters($di, ['product_ids' => [1], 'mode' => 'price', 'amount' => '9']);
    $fingerprint = BulkPrices::preview($di, $filters)['fingerprint'];
    $db->update('client_order', ['price' => 6], ['id' => 2]);

    expect(fn () => BulkPrices::apply($di, $filters, $fingerprint, false, null))->toThrow(InformationException::class, 'changed since the preview')
        ->and(fn () => BulkPrices::apply($di, $filters, '', false, null))->toThrow(InformationException::class, 'changed since the preview')
        ->and((float) $db->fetchOne('SELECT price FROM client_order WHERE id = 1'))->toBe(5.0)
        ->and((int) $db->fetchOne('SELECT COUNT(*) FROM client_order_status'))->toBe(0);
});

test('nothing is applied when no service gets a new price', function (): void {
    $di = bulkContainer(bulkDb());
    $filters = bulkFilters($di, ['product_ids' => [1], 'periods' => ['1M'], 'current_price' => '5', 'mode' => 'price', 'amount' => '5']);

    expect(fn () => BulkPrices::apply($di, $filters, BulkPrices::preview($di, $filters)['fingerprint'], false, null))
        ->toThrow(InformationException::class, 'No service gets a new price.');
});
