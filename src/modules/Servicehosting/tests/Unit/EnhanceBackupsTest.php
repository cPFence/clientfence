<?php

declare(strict_types=1);

use ClientFence\Mod\Client\Users;
use ClientFence\Mod\Servicehosting\Api\Client;
use ClientFence\Mod\Servicehosting\Panel\Enhance\Api;
use ClientFence\Mod\Servicehosting\Panel\Enhance\Panel;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function Tests\Helpers\container;

afterEach(fn () => Users::forget());

function enhanceBackupsFixture(string $case = 'success'): array
{
    Users::forget();
    $calls = new class {
        public array $requests = [];
        public array $activity = [];
        public array $info = [];

        public function logEvent(array $entry): void
        {
            $this->activity[] = $entry;
        }

        public function info(string $message, mixed ...$args): void
        {
            $this->info[] = vsprintf($message, $args);
        }
    };
    $backups = [];
    for ($id = 1; $id <= 12; ++$id) {
        $backups[] = [
            'id' => $id, 'startedAt' => sprintf('2026-10-%02dT00:00:00Z', $id), 'finishedAt' => sprintf('2026-10-%02dT00:05:00Z', $id),
            'size' => 10000, 'filesSize' => 700183584, 'mysqlDbsSize' => 1048576, 'emailsSize' => 9942349,
            'homeDirStatus' => 'successful', 'mysqlDbsStatus' => 'successful', 'emailsStatus' => 'failed',
        ];
    }
    $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($case, $calls, $backups): MockResponse {
        $calls->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($method === 'PUT') {
            return new MockResponse('', ['http_code' => 200]);
        }
        if ($path === '/api/orgs/B/subscriptions/7') {
            return new MockResponse('{"id":7,"subscriberId":"B","vendorId":"M","status":"active"}');
        }
        if ($path === '/api/orgs/B/websites') {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            expect($query['subscriptionId'])->toBe('7');

            return new MockResponse(json_encode(['total' => 2, 'items' => [
                ['id' => 'W', 'subscriptionId' => 7, 'domain' => ['domain' => 'site.example']],
                ['id' => 'W2', 'subscriptionId' => 7, 'domain' => ['domain' => 'second.example']],
            ]]));
        }
        if ($path === '/api/orgs/B/websites/W') {
            return new MockResponse(json_encode(['id' => 'W', 'subscriptionId' => $case === 'another subscription' ? 8 : 7, 'domain' => ['domain' => 'site.example']]));
        }
        if ($path === '/api/orgs/B/websites/W/backups') {
            return new MockResponse(json_encode(['items' => $backups]));
        }
        if ($path === '/api/orgs/B/websites/W2/backups') {
            return new MockResponse('{"items":[{"id":20,"startedAt":"2026-10-15T00:00:00Z","homeDirStatus":"successful","emailsStatus":"successful"}]}');
        }
        if ($path === '/api/orgs/B/websites/W/backups/12') {
            return $case === 'another website'
                ? new MockResponse('{}', ['http_code' => 404])
                : new MockResponse(json_encode(['homeDirStatus' => $case === 'failed backup' ? 'failed' : 'successful'] + $backups[11]));
        }

        throw new RuntimeException('Unexpected panel request: ' . $url);
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
    $account = (new Server_Account())->setPanelOrgId('B')->setPanelSubscriptionId(7);
    $hosting = Mockery::mock(ClientFence\Mod\Servicehosting\Service::class);
    $hosting->shouldReceive('_getAM')->andReturn([$manager, $account, null]);
    $db = Mockery::mock(Doctrine\DBAL\Connection::class);
    $db->shouldReceive('fetchAssociative')->with(Mockery::pattern('/FROM client_order/'), [7, 5, 'hosting'])->andReturn($case === 'unavailable' ? false : [
        'id' => 7, 'client_id' => 5, 'product_id' => 3, 'service_id' => 9, 'status' => $case === 'suspended' ? 'suspended' : 'active',
    ]);
    $db->shouldReceive('fetchOne')->with('SELECT config FROM product WHERE id = ?', [3])
        ->andReturn(json_encode(['client_backup_restore' => $case === 'disabled product' ? '0' : '1']));
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di = container();
    $di['em'] = $em;
    $di['logger'] = $calls;
    $model = new Model_ServiceHosting();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->id = 9;
    $di['db']->shouldReceive('load')->with('ServiceHosting', 9)->andReturn($model);
    $di['mod_config'] = $di->protect(fn (): array => ['panel_enhance' => '1']);
    $di['mod_service'] = $di->protect(fn (string $mod): object => $mod === 'activity' ? $calls : $hosting);
    if ($case === 'sub-user') {
        $di['session'] = new class {
            public function get(string $key): mixed
            {
                return $key === Users::SESSION ? ['user_id' => 4, 'client_id' => 5, 'version' => 1] : 5;
            }
        };
        $db->shouldReceive('fetchAssociative')->with(Mockery::pattern('/FROM client_user u/'), [5, Model_Client::ACTIVE, 4])->andReturn([
            'email' => 'user@example.test', 'first_name' => 'Test', 'last_name' => 'User', 'status' => 'active',
            'session_version' => 1, 'owner' => 1, 'permissions' => '[]',
        ]);
    }
    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->id = 5;
    $api = apiEndpoint(new Client());
    $api->setDi($di);
    $api->setIdentity($client);

    return [$api, $calls];
}

test('backups list the latest ten per subscription website and the current restore permission', function (string $case, bool $allowed): void {
    [$api] = enhanceBackupsFixture($case);
    $list = $api->backups(['order_id' => 7]);
    if ($case === 'unavailable') {
        expect($list)->toBe(['error' => true]);

        return;
    }

    expect($list['can_restore'])->toBe($allowed)
        ->and(array_column($list['websites'], 'domain'))->toBe(['site.example', 'second.example'])
        ->and(array_column($list['websites'][0]['backups'], 'id'))->toBe(range(12, 3))
        ->and($list['websites'][0]['backups'][0])->toBe([
            'id' => 12, 'startedAt' => '2026-10-12T00:00:00Z', 'finishedAt' => '2026-10-12T00:05:00Z',
            'size' => 711174509, 'files' => true, 'databases' => true, 'mailboxes' => false,
        ])
        ->and($list['websites'][1]['backups'][0])->toMatchArray(['finishedAt' => null, 'size' => null, 'databases' => false, 'mailboxes' => true]);
})->with(['owner' => ['success', true], 'current product disabled' => ['disabled product', false], 'sub-user' => ['sub-user', false], 'suspended' => ['suspended', false], 'unavailable' => ['unavailable', false]]);

test('restore refuses disallowed requests before sending a restore', function (string $case, string $message): void {
    [$api, $calls] = enhanceBackupsFixture($case);

    expect(fn () => $api->restore(['order_id' => 7, 'website_id' => 'W', 'backup_id' => 12, 'include_emails' => 1, 'confirm' => $case !== 'unconfirmed']))
        ->toThrow(ClientFence\InformationException::class, $message)
        ->and(array_filter($calls->requests, fn (array $request): bool => $request['method'] === 'PUT'))->toBe([])
        ->and($calls->activity)->toBe([]);
})->with([
    'product disabled' => ['disabled product', 'not enabled for this product'],
    'sub-user with full access' => ['sub-user', 'Only the account owner'],
    'suspended' => ['suspended', 'account is suspended'],
    'no confirmation' => ['unconfirmed', 'Confirm that you want'],
    'files backup failed' => ['failed backup', 'successful files backup'],
    'backup of another website' => ['another website', 'successful files backup'],
    'website of another subscription' => ['another subscription', 'not found on this hosting account'],
]);

test('restore sends the selected mail option and logs the order domain and backup date', function (bool $emails): void {
    [$api, $calls] = enhanceBackupsFixture();

    expect($api->restore(['order_id' => 7, 'website_id' => 'W', 'backup_id' => 12, 'include_emails' => $emails, 'confirm' => 1]))->toBeTrue();
    $puts = array_values(array_filter($calls->requests, fn (array $request): bool => $request['method'] === 'PUT'));
    expect($puts)->toHaveCount(1)
        ->and($puts[0]['url'])->toBe('https://panel.test/api/orgs/B/websites/W/backups/12')
        ->and(json_decode($puts[0]['options']['body'], true))->toBe(['restoreFiles' => true, 'restoreAllEmails' => $emails])
        ->and((float) $puts[0]['options']['timeout'])->toBe(20.0)
        ->and((float) $puts[0]['options']['max_duration'])->toBe(60.0)
        ->and($calls->activity)->toBe([[
            'client_id' => 5, 'admin_id' => null, 'priority' => 6,
            'message' => 'Started restoring the backup from 2026-10-12T00:00:00Z for site.example on hosting order #7',
        ]])
        ->and($calls->info)->toBe(['Client #5: Started restoring the backup from 2026-10-12T00:00:00Z for site.example on hosting order #7']);
})->with([false, true]);
