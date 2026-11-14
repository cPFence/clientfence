<?php

declare(strict_types=1);

/**
 * ClientFence: the daily domain sync and transfer tracking (Servicedomain\DomainSync), on an
 * in-memory SQLite copy of the tables they read. Staff #1 gets the notifications; the registrar
 * is a fake whose answers each test sets.
 */

use ClientFence\Mod\Clientfence\Notifications;
use ClientFence\Mod\Order\ActionFailures;
use ClientFence\Mod\Servicedomain\DomainSync;
use ClientFence\Mod\Servicedomain\Service;
use Doctrine\DBAL\Connection;

use function Tests\Helpers\container;

final class DomainSyncFakeRegistrar
{
    /** @var array<int, string> domain id => the registrar's expiry date; a missing id fails */
    public array $expiry = [];

    /** @var array{status: string, message: string}|null */
    public ?array $transfer = ['status' => Registrar_TransferStatus::PENDING, 'message' => ''];

    /** @var list<int> */
    public array $synced = [];

    public int $transfers = 0;

    public function syncExpirationDate(Model_ServiceDomain $model): void
    {
        $this->synced[] = (int) $model->id;
        $model->expires_at = $this->expiry[(int) $model->id] ?? throw new Registrar_Exception('Domain not found at the registrar');
    }

    public function synchronizeDomain(Model_ServiceDomain $model): void
    {
        $this->syncExpirationDate($model);
    }

    public ?string $fail = null;

    public function transferStatus(Model_ServiceDomain $model): ?array
    {
        return $this->fail === null ? $this->transfer : throw new Registrar_Exception($this->fail);
    }

    public function transferAgain(Model_ServiceDomain $model): void
    {
        ++$this->transfers;
    }

    public function toApiArray(Model_ServiceDomain $model): array
    {
        return ['domain' => $model->sld . $model->tld];
    }
}

/**
 * Domain #N belongs to order #N (client 5). Rows: [id, action, order status, order expires_at, synced_at, transfer_since].
 *
 * @return array{0: Pimple\Container, 1: Connection, 2: DomainSyncFakeRegistrar, 3: ArrayObject}
 */
function domainSyncFixture(array $rows, array $params = []): array
{
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE service_domain (id INTEGER PRIMARY KEY, sld TEXT, tld TEXT, action TEXT, expires_at TEXT, synced_at TEXT, sync_error TEXT, transfer_since TEXT)');
    $db->executeStatement('CREATE TABLE client_order (id INTEGER PRIMARY KEY, client_id INTEGER, title TEXT, status TEXT, service_id INTEGER, service_type TEXT, expires_at TEXT)');
    $db->executeStatement('CREATE TABLE mod_clientfence_state (name TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $db->executeStatement('CREATE TABLE ' . ActionFailures::TABLE . " (id INTEGER PRIMARY KEY AUTOINCREMENT, client_order_id INTEGER NOT NULL, action TEXT NOT NULL, message TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 1, status TEXT NOT NULL DEFAULT 'open', first_failed_at TEXT NOT NULL, last_failed_at TEXT NOT NULL, resolved_at TEXT, resolved_by INTEGER)");
    $db->executeStatement('CREATE TABLE client (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT)');
    $db->executeStatement("CREATE TABLE admin (id INTEGER PRIMARY KEY, status TEXT, system_name TEXT)");
    $db->executeStatement("INSERT INTO admin VALUES (1, 'active', NULL)");
    $db->executeStatement('CREATE TABLE ' . Notifications::TABLE . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, audience TEXT NOT NULL, recipient_id INTEGER NOT NULL, kind TEXT NOT NULL, params TEXT NOT NULL, url TEXT NOT NULL, dedupe TEXT, created_at TEXT NOT NULL, read_at TEXT, UNIQUE (audience, recipient_id, dedupe))');
    foreach ($rows as [$id, $action, $status, $due, $synced, $since]) {
        $db->insert('service_domain', ['id' => $id, 'sld' => 'site' . $id, 'tld' => '.test', 'action' => $action, 'synced_at' => $synced, 'transfer_since' => $since]);
        $db->insert('client_order', ['id' => $id, 'client_id' => 5, 'title' => 'site' . $id . '.test', 'status' => $status, 'service_id' => $id, 'service_type' => 'domain', 'expires_at' => $due]);
    }

    $registrar = new DomainSyncFakeRegistrar();
    $log = new ArrayObject(['notes' => [], 'emails' => []]);
    $domain = static function (int $id) use ($db): Model_ServiceDomain {
        $model = new Model_ServiceDomain();
        $model->loadBean(new Tests\Helpers\DummyBean());
        foreach ($db->fetchAssociative('SELECT * FROM service_domain WHERE id = ?', [$id]) as $k => $v) {
            $model->{$k} = $v;
        }

        return $model;
    };
    $order = static function (int $id) use ($db): Model_ClientOrder {
        $model = new Model_ClientOrder();
        $model->loadBean(new Tests\Helpers\DummyBean());
        foreach ($db->fetchAssociative('SELECT * FROM client_order WHERE id = ?', [$id]) as $k => $v) {
            $model->{$k} = $v;
        }

        return $model;
    };
    $orders = Mockery::mock();
    $orders->shouldReceive('getServiceOrder')->andReturnUsing(static fn (Model_ServiceDomain $m) => $order((int) $m->id));
    $orders->shouldReceive('getOrderService')->andReturnUsing(static fn (Model_ClientOrder $o) => $domain((int) $o->service_id));
    $orders->shouldReceive('saveStatusChange')->andReturnUsing(static function (Model_ClientOrder $o, string $note) use ($log): void {
        $log['notes'] = [...$log['notes'], $note];
    });
    $orders->shouldReceive('toApiArray')->andReturnUsing(static fn (Model_ClientOrder $o) => ['id' => (int) $o->id]);
    $email = Mockery::mock();
    $email->shouldReceive('sendTemplate')->andReturnUsing(static function (array $data) use ($log): bool {
        $log['emails'] = [...$log['emails'], $data];

        return true;
    });
    $system = Mockery::mock();
    $system->shouldReceive('getParamValue')->andReturnUsing(static fn (string $key, $default = null) => $params[$key] ?? $default);
    $staff = Mockery::mock();
    $staff->shouldReceive('hasPermission')->andReturn(true);

    $di = new Pimple\Container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;
    $rb = Mockery::mock();
    $rb->shouldReceive('load')->andReturnUsing(static fn (string $type, int $id) => match ($type) {
        'ServiceDomain' => $domain($id),
        'ClientOrder' => $order($id),
        'Admin' => new Model_Admin(),
    });
    $rb->shouldReceive('getExistingModelById')->andReturnUsing(static fn (string $type, int $id) => $order($id));
    $rb->shouldReceive('store')->andReturnUsing(static function (Model_ClientOrder $o) use ($db): int {
        $db->update('client_order', ['expires_at' => $o->expires_at], ['id' => (int) $o->id]);

        return (int) $o->id;
    });
    $di['db'] = $rb;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $url = Mockery::mock(ClientFence\Url::class);
    $url->shouldReceive('adminLink')->andReturnUsing(fn (string $path): string => 'https://billing.test/admin/' . $path);
    $di['url'] = $url;
    $di['mod_service'] = $di->protect(static fn (string $name) => match ($name) {
        'servicedomain' => $registrar,
        'order' => $orders,
        'email' => $email,
        'system' => $system,
        'staff' => $staff,
    });

    return [$di, $db, $registrar, $log];
}

function domainSyncNotices(Connection $db, string $kind): array
{
    return $db->fetchFirstColumn('SELECT params FROM ' . Notifications::TABLE . ' WHERE kind = ? ORDER BY id', [$kind]);
}

function domainSyncFailures(Connection $db): array
{
    return $db->fetchAllAssociative('SELECT action, status, message FROM ' . ActionFailures::TABLE . ' ORDER BY id');
}

function domainSyncLoad(Pimple\Container $di, int $id): Model_ServiceDomain
{
    return $di['db']->load('ServiceDomain', $id);
}

test('a completed transfer leaves pending once: history, client email, earlier due date, failure closed', function (): void {
    [$di, $db, $registrar, $log] = domainSyncFixture([[1, DomainSync::PENDING, 'active', '2027-09-01 10:00:00', null, '2026-11-01 10:00:00']]);
    ActionFailures::record($db, 1, DomainSync::ACTION, 'stuck', '2026-11-05 10:00:00');
    $registrar->transfer = ['status' => Registrar_TransferStatus::COMPLETED, 'message' => ''];
    $registrar->expiry[1] = '2027-06-30 00:00:00';

    expect(DomainSync::checkOne($di, domainSyncLoad($di, 1)))->toBe(Registrar_TransferStatus::COMPLETED)
        ->and($db->fetchAssociative('SELECT action, transfer_since FROM service_domain WHERE id = 1'))->toBe(['action' => null, 'transfer_since' => null])
        ->and($db->fetchOne('SELECT expires_at FROM client_order WHERE id = 1'))->toBe('2027-06-30 10:00:00')
        ->and($log['notes'])->toHaveCount(1)
        ->and($log['notes'][0])->toContain('2027-06-30')->toContain('moved from 2027-09-01')
        ->and(array_column($log['emails'], 'code'))->toContain('mod_servicedomain_transfer_completed')
        ->and(domainSyncFailures($db)[0]['status'])->toBe(ActionFailures::STATUS_RESOLVED)
        ->and(domainSyncNotices($db, 'transfer_completed'))->toHaveCount(1);

    // A second check of the same model (another cron) does nothing more.
    $stale = domainSyncLoad($di, 1);
    $stale->action = DomainSync::PENDING;
    DomainSync::checkOne($di, $stale);
    expect($log['notes'])->toHaveCount(1)
        ->and(array_column($log['emails'], 'code'))->toHaveCount(1);
});

test('a completed transfer never moves the next due date later', function (): void {
    [$di, $db, $registrar] = domainSyncFixture([[1, DomainSync::PENDING, 'active', '2027-03-01 10:00:00', null, '2026-11-01 10:00:00']]);
    $registrar->transfer = ['status' => Registrar_TransferStatus::COMPLETED, 'message' => ''];
    $registrar->expiry[1] = '2028-03-01 00:00:00';

    DomainSync::checkOne($di, domainSyncLoad($di, 1));
    expect($db->fetchOne('SELECT expires_at FROM client_order WHERE id = 1'))->toBe('2027-03-01 10:00:00');
});

test('a reported completion whose sync fails stays pending', function (): void {
    [$di, $db, $registrar, $log] = domainSyncFixture([[1, DomainSync::PENDING, 'active', '2027-03-01 10:00:00', null, '2026-11-01 10:00:00']]);
    $registrar->transfer = ['status' => Registrar_TransferStatus::COMPLETED, 'message' => ''];

    expect(DomainSync::checkOne($di, domainSyncLoad($di, 1)))->toBe(Registrar_TransferStatus::PENDING)
        ->and($db->fetchOne('SELECT action FROM service_domain WHERE id = 1'))->toBe(DomainSync::PENDING)
        ->and($log['emails'])->toBe([]);
});

test('a failed transfer opens one Failed actions entry with the registrar words', function (): void {
    [$di, $db, $registrar] = domainSyncFixture([[1, DomainSync::PENDING, 'active', '2027-03-01 10:00:00', null, '2026-11-01 10:00:00']]);
    $registrar->transfer = ['status' => Registrar_TransferStatus::FAILED, 'message' => 'Wrong auth code'];

    expect(DomainSync::checkOne($di, domainSyncLoad($di, 1)))->toBe(Registrar_TransferStatus::FAILED)
        ->and($db->fetchOne('SELECT action FROM service_domain WHERE id = 1'))->toBe(DomainSync::FAILED)
        ->and(domainSyncFailures($db))->toBe([['action' => DomainSync::ACTION, 'status' => 'open', 'message' => 'Wrong auth code']])
        // No longer pending: not checked again.
        ->and(DomainSync::checkOne($di, domainSyncLoad($di, 1)))->toBeNull();
});

test('a transfer pending over 14 days opens one entry (a suspended order too), and none again after a dismiss', function (): void {
    $since = date('Y-m-d H:i:s', time() - 15 * 86400);
    [$di, $db] = domainSyncFixture([[1, DomainSync::PENDING, 'suspended', '2027-03-01 10:00:00', null, $since], [2, DomainSync::PENDING, 'active', '2027-03-01 10:00:00', null, date('Y-m-d H:i:s', time() - 3 * 86400)]]);

    DomainSync::checkTransfers($di, 30);
    DomainSync::checkTransfers($di, 30);
    $failures = domainSyncFailures($db);
    expect($failures)->toHaveCount(1)
        ->and($failures[0]['message'])->toContain('14 days');

    ActionFailures::dismiss($di, (int) $db->fetchOne('SELECT id FROM ' . ActionFailures::TABLE), 1);
    DomainSync::checkTransfers($di, 30);
    expect(domainSyncFailures($db))->toHaveCount(1);
});

test('a transfer the registrar keeps refusing to report on still reaches staff after 14 days', function (): void {
    [$di, $db, $registrar] = domainSyncFixture([[1, DomainSync::PENDING, 'active', '2027-03-01 10:00:00', null, date('Y-m-d H:i:s', time() - 15 * 86400)]]);
    $registrar->fail = 'Domain not found in the account';

    DomainSync::checkTransfers($di, 30);
    DomainSync::checkTransfers($di, 30);
    $failures = domainSyncFailures($db);
    expect($failures)->toHaveCount(1)
        ->and($failures[0]['message'])->toContain('14 days')->toContain('not found in the account')
        ->and($db->fetchOne('SELECT action FROM service_domain WHERE id = 1'))->toBe(DomainSync::PENDING);
});

test('a working expiry sync clears the daily sync error', function (): void {
    $model = new Model_ServiceDomain();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->sync_error = 'Domain not found at the registrar';
    $domain = new Registrar_Domain();
    $domain->setExpirationTime(strtotime('2030-01-01'));
    $adapter = Mockery::mock(Registrar_Adapter_Custom::class);
    $adapter->shouldReceive('getDomainDetails')->andReturn($domain);
    $service = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('_getD')->andReturn([$domain, $adapter]);
    $di = container();
    $di['db']->shouldReceive('store')->once()->andReturn(1);
    $service->setDi($di);

    $service->syncExpirationDate($model);
    expect($model->sync_error)->toBeNull()
        ->and(substr((string) $model->expires_at, 0, 10))->toBe('2030-01-01');
});

test('Failed actions retry sends the transfer again and tracks it from now', function (): void {
    [$di, $db, $registrar, $log] = domainSyncFixture([[1, DomainSync::FAILED, 'active', '2027-03-01 10:00:00', null, '2026-10-01 10:00:00']]);
    $id = ActionFailures::record($db, 1, DomainSync::ACTION, 'Wrong auth code', '2026-10-20 10:00:00');

    expect(ActionFailures::retry($di, (int) $id, 1))->toBeTrue()
        ->and($registrar->transfers)->toBe(1)
        ->and($db->fetchOne('SELECT action FROM service_domain WHERE id = 1'))->toBe(DomainSync::PENDING)
        ->and(strtotime((string) $db->fetchOne('SELECT transfer_since FROM service_domain WHERE id = 1')))->toBeGreaterThan(time() - 60)
        ->and(domainSyncFailures($db)[0]['status'])->toBe(ActionFailures::STATUS_RESOLVED)
        ->and($log['notes'])->toHaveCount(1);

    $db->update('service_domain', ['action' => null], ['id' => 1]);
    expect(fn () => DomainSync::retryTransfer($di, 1))->toThrow(ClientFence\InformationException::class);
});

test('the daily batch syncs a share, oldest first, only active and suspended domains that are not tracked transfers', function (): void {
    $rows = [];
    for ($i = 1; $i <= 25; ++$i) {
        $rows[] = [$i, null, 'active', '2027-03-01 10:00:00', sprintf('2026-10-%02d 10:00:00', $i), null];
    }
    $rows[] = [26, null, 'active', '2027-03-01 10:00:00', null, null];
    $rows[] = [27, null, 'suspended', '2027-03-01 10:00:00', '2026-09-01 10:00:00', null];
    $rows[] = [28, null, 'canceled', '2027-03-01 10:00:00', null, null];
    $rows[] = [29, DomainSync::PENDING, 'active', '2027-03-01 10:00:00', null, '2026-11-01 10:00:00'];
    [$di, $db, $registrar] = domainSyncFixture($rows);
    $registrar->expiry = array_fill(1, 29, '2027-03-01 00:00:00');
    unset($registrar->expiry[3]);

    // 27 domains to sync: the minimum of 20 a day.
    expect(DomainSync::batch($di, 30))->toBe(20)
        ->and(array_slice($registrar->synced, 0, 3))->toBe([26, 27, 1])
        ->and($registrar->synced)->not->toContain(28)->not->toContain(29)
        ->and(DomainSync::batch($di, 30))->toBe(0)
        ->and($db->fetchOne('SELECT sync_error FROM service_domain WHERE id = 3'))->toContain('not found')
        ->and($db->fetchOne('SELECT synced_at FROM service_domain WHERE id = 3'))->toStartWith(date('Y-m-d'))
        ->and($db->fetchOne('SELECT COUNT(*) FROM service_domain WHERE sync_error IS NOT NULL'))->toBe(1)
        ->and($db->fetchOne("SELECT COUNT(DISTINCT expires_at) FROM client_order"))->toBe(1);
});

test('a registrar expiry before the next due date tells staff once per domain and date', function (): void {
    [$di, $db, $registrar] = domainSyncFixture([[1, null, 'active', '2027-03-01 10:00:00', null, null], [2, null, 'active', '2027-03-01 10:00:00', null, null]]);
    $registrar->expiry = [1 => '2027-01-15 00:00:00', 2 => '2027-03-01 00:00:00'];

    DomainSync::batch($di, 30);
    $db->executeStatement('DELETE FROM mod_clientfence_state');
    DomainSync::batch($di, 30);
    $notices = domainSyncNotices($db, 'domain_expires_early');
    expect($notices)->toHaveCount(1)
        ->and(json_decode($notices[0], true))->toBe(['domain' => 'site1.test', 'date' => '2027-01-15', 'due' => '2027-03-01'])
        ->and($db->fetchOne('SELECT expires_at FROM client_order WHERE id = 1'))->toBe('2027-03-01 10:00:00');
});

test('the cron runs the daily batch only with its switch on', function (bool $on): void {
    [$di, , $registrar] = domainSyncFixture([[1, null, 'active', '2027-03-01 10:00:00', null, null]], [DomainSync::DAILY => $on ? '1' : '0']);
    $registrar->expiry[1] = '2027-03-01 00:00:00';

    DomainSync::runIfDue($di);
    expect($registrar->synced)->toBe($on ? [1] : []);
})->with([true, false]);

function domainSyncActivation(bool $track, ?string $action = 'transfer', bool $canTell = true): array
{
    $model = new Model_ServiceDomain();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->action = $action;
    $orders = Mockery::mock(ClientFence\Mod\Order\Service::class);
    $orders->shouldReceive('getOrderService')->andReturn($model);
    $orders->shouldReceive('getConfig')->andReturn([]);
    $system = Mockery::mock();
    $system->shouldReceive('getParamValue')->andReturn($track ? '1' : '0');
    $adapter = $canTell ? Mockery::mock('Registrar_Adapter_Custom, Registrar_TransferStatus') : Mockery::mock(Registrar_Adapter_Custom::class);
    $service = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('_getD')->andReturn([new Registrar_Domain(), $adapter]);
    $di = container();
    $di['mod_service'] = $di->protect(fn (string $name) => $name === 'system' ? $system : $orders);
    $di['db']->shouldReceive('store')->andReturn(1);
    $service->setDi($di);
    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());

    return [$service, $adapter, $model, $order];
}

test('with tracking on, an accepted transfer waits as pending without the full sync', function (): void {
    [$service, $adapter, $model, $order] = domainSyncActivation(true);
    $adapter->shouldReceive('transferDomain')->once()->andReturn(true);
    $service->shouldNotReceive('syncWhois');

    $service->action_activate($order);
    expect($model->action)->toBe(DomainSync::PENDING)
        ->and($model->transfer_since)->not->toBeEmpty();
});

test('with tracking off or a registrar that cannot tell, a transfer completes on acceptance as before', function (bool $track, bool $canTell): void {
    [$service, $adapter, $model, $order] = domainSyncActivation($track, 'transfer', $canTell);
    $adapter->shouldReceive('transferDomain')->once()->andReturn(true);
    $service->shouldReceive('syncWhois')->once();

    $service->action_activate($order);
    expect($model->action)->toBeNull();
})->with([[false, true], [true, false]]);

test('a second activation of a tracked transfer does not send it again', function (): void {
    foreach ([DomainSync::PENDING, DomainSync::FAILED] as $action) {
        [$service, $adapter, $model, $order] = domainSyncActivation(true, $action);
        $adapter->shouldNotReceive('transferDomain');
        $service->shouldNotReceive('syncWhois');

        expect($service->action_activate($order))->toBe($model)
            ->and($model->action)->toBe($action);
    }
});
