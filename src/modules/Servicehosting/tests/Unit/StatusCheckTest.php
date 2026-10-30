<?php

declare(strict_types=1);

use ClientFence\Mod\Order\ActionFailures;
use ClientFence\Mod\Servicehosting\Panel\Enhance\Api;
use ClientFence\Mod\Servicehosting\Panel\Enhance\Panel;
use ClientFence\Mod\Servicehosting\Panel\StatusCheck;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function Tests\Helpers\container;

test('hosting status mismatches are reported and a matching retry closes the entry', function (string $billing, string $state, ?string $message): void {
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE client_order (id INTEGER PRIMARY KEY, title TEXT, status TEXT, service_id INTEGER, service_type TEXT, client_id INTEGER)');
    $db->executeStatement('CREATE TABLE service_hosting (id INTEGER PRIMARY KEY, service_hosting_server_id INTEGER)');
    $db->executeStatement('CREATE TABLE service_hosting_server (id INTEGER PRIMARY KEY, manager TEXT)');
    $db->executeStatement('CREATE TABLE client_order_action_failure (id INTEGER PRIMARY KEY AUTOINCREMENT, client_order_id INTEGER, action TEXT, message TEXT, attempts INTEGER, status TEXT, first_failed_at TEXT, last_failed_at TEXT, resolved_at TEXT, resolved_by INTEGER)');
    $db->insert('client_order', ['id' => 7, 'title' => 'Hosting', 'status' => $billing, 'service_id' => 9, 'service_type' => 'hosting', 'client_id' => 5]);
    $db->insert('service_hosting', ['id' => 9, 'service_hosting_server_id' => 2]);
    $db->insert('service_hosting_server', ['id' => 2, 'manager' => 'Enhance']);
    $http = new MockHttpClient(static function (string $method, string $url) use ($state): MockResponse {
        expect($method)->toBe('GET')
            ->and(parse_url($url, PHP_URL_PATH))->toBe('/api/orgs/B/subscriptions/7');
        if ($state === 'gone 404') {
            return new MockResponse('{}', ['http_code' => 404]);
        }

        return new MockResponse(json_encode([
            'subscriberId' => 'B', 'vendorId' => 'M', 'status' => $state === 'gone' ? 'deleted' : 'active',
            'suspendedBy' => $state === 'suspended' ? 'M' : null,
        ]));
    });
    $manager = new class(new Panel(new Api($http, 'https://panel.test', 'token'), 'M')) extends Server_Manager_Enhance {
        public function __construct(private readonly Panel $testPanel)
        {
        }

        public function panel(): Panel
        {
            return $this->testPanel;
        }
    };
    $account = new Server_Account();
    $linkError = null;
    if (in_array($state, ['unlinked', 'link timeout'], true)) {
        $linkError = new Server_Exception('Lookup failed', null, $state === 'unlinked' ? Panel::ACCOUNT_NOT_FOUND : 0);
    } else {
        $account->setPanelOrgId('B')->setPanelSubscriptionId(7);
    }
    $hosting = Mockery::mock(ClientFence\Mod\Servicehosting\Service::class);
    $hosting->shouldReceive('_getAM')->once()->andReturn([$manager, $account, $linkError]);
    $model = new Model_ServiceHosting();
    $legacy = Mockery::mock(ClientFence\Database::class);
    $legacy->shouldReceive('load')->with('ServiceHosting', 9)->andReturn($model);
    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->status = $billing;
    $legacy->shouldReceive('load')->with('ClientOrder', 7)->andReturn($order);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di = container();
    $di['em'] = $em;
    $di['db'] = $legacy;
    $di['mod_config'] = $di->protect(fn (): array => ['panel_enhance' => '1']);
    $di['mod_service'] = $di->protect(fn (): object => $hosting);

    if ($state === 'active') {
        $id = ActionFailures::record($db, 7, StatusCheck::ACTION, 'Previous mismatch', '2026-10-01 00:00:00');
        expect(ActionFailures::retry($di, $id, 3))->toBeTrue()
            ->and($db->fetchAssociative('SELECT status, resolved_by FROM client_order_action_failure'))->toMatchArray(['status' => 'resolved', 'resolved_by' => 3]);
    } elseif ($state === 'link timeout') {
        expect(fn () => StatusCheck::check($di, 7))->toThrow(Server_Exception::class, 'Lookup failed')
            ->and((int) $db->fetchOne('SELECT COUNT(*) FROM client_order_action_failure'))->toBe(0);
    } else {
        expect(StatusCheck::check($di, 7))->toBeFalse()
            ->and($db->fetchAssociative('SELECT action, status, message FROM client_order_action_failure'))->toBe([
                'action' => StatusCheck::ACTION, 'status' => 'open', 'message' => $message,
            ]);
    }
})->with([
    'panel suspended' => ['active', 'suspended', 'Suspended on the panel but active in billing'],
    'panel active' => ['suspended', 'active mismatch', 'Active on the panel but suspended in billing'],
    'panel gone' => ['active', 'gone', 'The panel subscription no longer exists'],
    'panel returns 404' => ['active', 'gone 404', 'The panel subscription no longer exists'],
    'cannot link' => ['active', 'unlinked', 'This hosting service could not be linked to a panel subscription'],
    'matches again' => ['active', 'active', null],
    'link timeout is not a mismatch' => ['active', 'link timeout', null],
]);

test('the hosting status cron checks once a day and continues after a panel error', function (): void {
    $last = null;
    $db = Mockery::mock(Doctrine\DBAL\Connection::class);
    $db->shouldReceive('fetchOne')->with('SELECT value FROM mod_clientfence_state WHERE name = ?', ['hosting_status_check'])
        ->times(3)->andReturnUsing(function () use (&$last): string|false { return $last ?? false; });
    $db->shouldReceive('executeStatement')->twice()->with(Mockery::pattern('/INSERT INTO mod_clientfence_state/'), Mockery::any())
        ->andReturnUsing(function (string $sql, array $params) use (&$last): int { $last = $params[1]; return 1; });
    $db->shouldReceive('fetchFirstColumn')->twice()->with(Mockery::on(fn (string $sql): bool => str_contains($sql, "s.manager = 'Enhance'") && str_contains($sql, "('active', 'suspended')")))->andReturn([7, 8]);
    $db->shouldReceive('fetchAssociative')->with(Mockery::pattern('/SELECT o.status/'), [7])->twice()->andReturn(['status' => 'active', 'service_id' => 9]);
    $db->shouldReceive('fetchAssociative')->with(Mockery::pattern('/SELECT o.status/'), [8])->twice()->andReturn(['status' => 'active', 'service_id' => 10]);
    $db->shouldReceive('executeStatement')->with(Mockery::pattern('/UPDATE client_order_action_failure/'), Mockery::any())->twice()->andReturn(0);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $manager = new class extends Server_Manager_Enhance {
        public function __construct()
        {
        }

        public function clientfenceSubscriptionState(Server_Account $account): string
        {
            return 'active';
        }
    };
    $account = (new Server_Account())->setPanelOrgId('B')->setPanelSubscriptionId(7);
    $hosting = Mockery::mock(ClientFence\Mod\Servicehosting\Service::class);
    $hosting->shouldReceive('_getAM')->times(4)->andReturn(
        [$manager, new Server_Account(), new Server_Exception('Panel timeout')], [$manager, $account, null],
        [$manager, new Server_Account(), new Server_Exception('Panel timeout')], [$manager, $account, null],
    );
    $di = container();
    $di['em'] = $em;
    $di['db']->shouldReceive('load')->andReturn(new Model_ServiceHosting());
    $di['mod_config'] = $di->protect(fn (): array => ['panel_enhance' => '1']);
    $di['mod_service'] = $di->protect(fn (): object => $hosting);
    $di['logger'] = new class {
        public array $warnings = [];

        public function warning(string $message, mixed ...$args): void
        {
            $this->warnings[] = vsprintf($message, $args);
        }
    };

    StatusCheck::runIfDue($di);
    StatusCheck::runIfDue($di);
    $last = json_encode(['ran_at' => time() - 86401]);
    StatusCheck::runIfDue($di);

    expect($di['logger']->warnings)->toBe(array_fill(0, 2, 'Hosting status check for order #7 failed: Panel timeout'));
});
