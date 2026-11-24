<?php

declare(strict_types=1);

/**
 * ClientFence: an invoice made for a record is on that record's site (ClientFence\Site::on()): an order's invoice on the
 * order's, a credit note or refund invoice on the invoice it is for, an upgrade's invoice on the order's.
 */

use ClientFence\Mod\Invoice\Service;
use ClientFence\Site;

use function Tests\Helpers\container;

/** $di with the site module "shop" switched on, serving sites A and B ("shop/a", "shop/b"); `$services` answers every other module. */
function invoiceSites(Pimple\Container $di, Closure $services): void
{
    $sites = new class {
        public function siteByKey(string $key): ?Site
        {
            return in_array($key, ['a', 'b'], true) ? new Site("https://{$key}.example.test/", "{$key}.example.test", $key) : null;
        }
    };
    $extension = Mockery::mock();
    $extension->allows('getCoreAndActiveModules')->andReturn(['shop']);
    $di['mod_service'] = $di->protect(fn (string $module, string $sub = ''): object => match (strtolower($module)) {
        'extension' => $extension,
        'shop' => $sites,
        default => $services($module, $sub),
    });
    $mod = Mockery::mock(ClientFence\Module::class);
    $mod->allows('hasManifest')->andReturn(true);
    $mod->allows('getManifest')->andReturn(['sites' => true]);
    $mod->allows('cannotRun')->andReturn(null);
    $di['mod'] = $di->protect(fn (): object => $mod);
}

function siteInvoiceModel(string $class, array $data): object
{
    $model = new $class();
    $model->loadBean(new Tests\Helpers\DummyBean());
    foreach ($data as $key => $value) {
        $model->{$key} = $value;
    }

    return $model;
}

/** A Mockery dbal whose site of each record is in `$sites` ("table id" => key), as Site reads it. */
function siteInvoiceDbal(array $sites): Doctrine\DBAL\Connection
{
    $dbal = Mockery::mock(Doctrine\DBAL\Connection::class)->shouldIgnoreMissing();
    $dbal->allows('fetchOne')->andReturnUsing(static fn (string $sql, array $params = []): mixed => preg_match('/FROM `(\w+)` r LEFT JOIN client/', $sql, $m) === 1 ? ($sites[$m[1] . ' ' . $params[0]] ?? null) : null);
    $dbal->allows('transactional')->andReturnUsing(static fn (callable $work): mixed => $work());
    $dbal->allows('getDatabasePlatform')->andReturn(new Doctrine\DBAL\Platforms\SQLitePlatform());
    $dbal->shouldReceive('fetchFirstColumn', 'fetchAllAssociative')->andReturn([]);

    return $dbal;
}

test('an order\'s invoice is on the order\'s site: from cron, from a request on another site, and while its module is switched off', function (): void {
    $sql = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach (['client', 'client_order', 'invoice'] as $table) {
        $sql->executeStatement("CREATE TABLE {$table} (id INTEGER PRIMARY KEY, client_id INTEGER, site TEXT)");
    }
    // Client #7 is on A; order #6 on B, #8 has no site of its own (its client's), #9 on a site whose module is switched off.
    $sql->insert('client', ['id' => 7, 'site' => 'shop/a']);
    foreach ([6 => 'shop/b', 8 => null, 9 => 'gone/b'] as $id => $key) {
        $sql->insert('client_order', ['id' => $id, 'client_id' => 7, 'site' => $key]);
    }
    $di = container();
    $di['dbal'] = $sql;
    $items = Mockery::mock();
    $items->allows('generateFromOrder');
    invoiceSites($di, fn (): object => $items);
    // ClientFence\Database: a new invoice is a row of the invoice table, stamped as on a real install.
    $orm = new class($sql) {
        public function __construct(private readonly Doctrine\DBAL\Connection $db)
        {
        }

        public function store(RedBeanPHP\OODBBean $bean): int
        {
            $this->db->insert($bean->getMeta('type'), ['client_id' => $bean->client_id]);

            return $bean->id = (int) $this->db->lastInsertId();
        }
    };
    $database = new class extends ClientFence\Database {
        public function dispense($modelName)
        {
            $bean = new RedBeanPHP\OODBBean();
            $bean->initializeForDispense(strtolower($modelName));
            $model = new Model_Invoice();
            $model->loadBean($bean);

            return $model;
        }

        public function getExistingModelById($modelName, $id, $message = '')
        {
            return siteInvoiceModel(Model_Client::class, ['id' => $id]);
        }
    };
    $database->setDi($di);
    $database->setDataMapper($orm);
    $di['db'] = $database;
    $seen = [];
    $service = Mockery::mock(Service::class)->makePartial();
    // The seller and the links of the invoice are on its site.
    $service->allows('setInvoiceDefaults')->andReturnUsing(function () use ($di, &$seen): void {
        $seen[] = Site::current($di)->key;
    });
    $service->setDi($di);
    $renew = static fn (int $orderId, Site $on): mixed => Site::within($di, $on, fn () => $sql->fetchOne('SELECT site FROM invoice WHERE id = ?', [
        (fn () => $this->generateForOrderLocked(siteInvoiceModel(Model_ClientOrder::class, ['id' => $orderId, 'client_id' => 7, 'status' => Model_ClientOrder::STATUS_PENDING_SETUP, 'price' => 10, 'quantity' => 1, 'currency' => 'USD']), null, null, false))->call($service)->id,
    ]));
    $siteA = new Site('https://a.example.test/', 'a.example.test', 'shop/a');

    expect([$renew(6, Site::primary()), $renew(6, $siteA), $renew(8, new Site('https://b.example.test/', 'b.example.test', 'shop/b')), $renew(9, $siteA)])
        ->toBe(['shop/b', 'shop/b', 'shop/a', 'gone/b'])
        ->and($seen)->toBe(['shop/b', 'shop/b', 'shop/a', null]);
});

test('a credit note and a refund invoice are on the site of the invoice they are for, also when staff make them on the primary site', function (): void {
    $source = siteInvoiceModel(Model_Invoice::class, ['id' => 70, 'client_id' => 7, 'currency' => 'USD', 'status' => Model_Invoice::STATUS_PAID]);
    $new = siteInvoiceModel(Model_Invoice::class, ['id' => 90]);
    $line = siteInvoiceModel(Model_InvoiceItem::class, []);
    $di = container();
    $di['dbal'] = siteInvoiceDbal(['invoice 70' => 'shop/b']);
    $made = [];
    $di['db']->allows('getExistingModelById')->andReturn($source);
    $di['db']->allows('dispense')->andReturnUsing(fn (string $type): object => $type === 'Invoice' ? $new : $line);
    $di['db']->allows('find')->andReturn([]);
    // The site the new invoice is made on: its first save.
    $di['db']->allows('store')->andReturnUsing(function (object $model) use ($di, $new, &$made): void {
        if ($model === $new) {
            $made[] = Site::current($di)->key;
        }
    });
    $system = Mockery::mock(ClientFence\Mod\System\Service::class)->shouldIgnoreMissing();
    $system->allows('getParamValue')->andReturnUsing(fn (string $param, $default = null) => $param === 'invoice_refund_logic' ? 'credit_note' : $default);
    invoiceSites($di, fn (): object => $system);
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('addNote', 'countIncome');
    $service->shouldReceive('getTotal')->andReturn(10.0);
    $service->shouldReceive('getTax')->andReturn(0.0);
    $service->allows('getTotalWithTax')->andReturn(10.0);
    $service->allows('toApiArray')->andReturn([]);
    $service->setDi($di);

    $service->creditNote(70, 'Credit for the unused time of order #2 (downgrade)', 5.0, false, 'Account credit.', 2);
    $creditNote = $made;
    $made = [];
    $service->refundInvoice($source);

    expect($creditNote[0] ?? false)->toBe('shop/b')
        ->and($made[0] ?? false)->toBe('shop/b')
        ->and(Site::current($di)->key)->toBeNull();
});

test('an upgrade\'s invoice is on the order\'s site', function (): void {
    $di = container();
    $di['dbal'] = siteInvoiceDbal(['client_order 6' => 'shop/b']);
    $di['db']->allows('getExistingModelById')->andReturn(siteInvoiceModel(Model_Client::class, ['id' => 7]));
    $invoices = new class($di) {
        public ?string $site = 'none';

        public function __construct(private readonly Pimple\Container $di)
        {
        }

        public function prepareInvoice(Model_Client $client, array $data): Model_Invoice
        {
            $this->site = Site::current($this->di)->key;

            return siteInvoiceModel(Model_Invoice::class, ['id' => 40]);
        }

        public function isClientTaxable(Model_Client $client): bool
        {
            return false;
        }
    };
    invoiceSites($di, fn (): object => $invoices);
    $order = siteInvoiceModel(Model_ClientOrder::class, ['id' => 6, 'client_id' => 7, 'title' => 'Hosting', 'period' => '1M', 'currency' => 'USD']);
    $chosen = ['kind' => 'upgrade', 'title' => 'Hosting Plus', 'period' => '1M', 'amount' => 5.0, 'credit' => 0];

    (fn () => self::invoice($di, $order, $chosen, 3))->bindTo(null, ClientFence\Mod\Order\Upgrades::class)();

    expect($invoices->site)->toBe('shop/b');
});
