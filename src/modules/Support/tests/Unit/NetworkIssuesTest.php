<?php

declare(strict_types=1);

/**
 * ClientFence: network issues (Support\NetworkIssues), on an in-memory SQLite copy of the tables.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Support\NetworkIssues;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

function networkDb(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
        'CREATE TABLE service_hosting_server (id INTEGER PRIMARY KEY, name TEXT)',
        'CREATE TABLE service_hosting (id INTEGER PRIMARY KEY, service_hosting_server_id INTEGER)',
        'CREATE TABLE client_order (id INTEGER PRIMARY KEY, client_id INTEGER, service_type TEXT, service_id INTEGER, status TEXT)',
        'CREATE TABLE support_network_issue (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, description TEXT NOT NULL, type TEXT NOT NULL DEFAULT \'server\', affecting TEXT NOT NULL DEFAULT \'\', server_id INTEGER, priority TEXT NOT NULL DEFAULT \'medium\', status TEXT NOT NULL DEFAULT \'reported\', start_at TEXT NOT NULL, end_at TEXT, emailed_at TEXT, emailed_count INTEGER NOT NULL DEFAULT 0, created_by INTEGER, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    $db->insert('service_hosting_server', ['id' => 1, 'name' => 'Cluster A']);
    $db->insert('service_hosting_server', ['id' => 2, 'name' => 'Cluster B']);
    // Client 1: hosting on server 1. Client 2: hosting on server 2 (suspended). Client 3: a domain only.
    // Client 4: hosting on server 1, cancelled. Client 5: nothing.
    $db->insert('service_hosting', ['id' => 10, 'service_hosting_server_id' => 1]);
    $db->insert('service_hosting', ['id' => 11, 'service_hosting_server_id' => 2]);
    $db->insert('service_hosting', ['id' => 12, 'service_hosting_server_id' => 1]);
    $db->insert('client_order', ['id' => 1, 'client_id' => 1, 'service_type' => 'hosting', 'service_id' => 10, 'status' => 'active']);
    $db->insert('client_order', ['id' => 2, 'client_id' => 2, 'service_type' => 'hosting', 'service_id' => 11, 'status' => 'suspended']);
    $db->insert('client_order', ['id' => 3, 'client_id' => 3, 'service_type' => 'domain', 'service_id' => 30, 'status' => 'active']);
    $db->insert('client_order', ['id' => 4, 'client_id' => 4, 'service_type' => 'hosting', 'service_id' => 12, 'status' => 'cancelled']);

    return $db;
}

/**
 * @return array{0: Pimple\Container, 1: ArrayObject<string, mixed>}
 */
function networkContainer(Connection $db, array $config = ['network_status' => '1']): array
{
    $log = new ArrayObject(['emails' => []]);
    $di = new Pimple\Container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;
    $di['logger'] = new class {
        public function warning(...$args): void
        {
        }
    };
    $di['url'] = new class {
        public function link(string $path): string
        {
            return 'https://billing.example.com/' . $path;
        }
    };
    $services = [
        'extension' => new class($config) {
            public function __construct(private array $config)
            {
            }

            public function getConfig(string $ext): array
            {
                return $this->config;
            }
        },
        'email' => new class($log) {
            public function __construct(private ArrayObject $log)
            {
            }

            public function sendTemplate(array $email): bool
            {
                if ($email['to_client'] === 2) {
                    throw new RuntimeException('mailbox full');
                }
                $this->log['emails'] = [...$this->log['emails'], $email];

                return true;
            }
        },
    ];
    $di['mod_service'] = $di->protect(fn (string $mod): object => $services[strtolower($mod)] ?? throw new RuntimeException('No service ' . $mod));

    return [$di, $log];
}

function networkForm(array $data = []): array
{
    return $data + [
        'title' => 'Cluster A maintenance',
        'description' => "Kernel updates.\nShort reboots.",
        'type' => 'server',
        'server_id' => '1',
        'priority' => 'medium',
        'status' => 'scheduled',
        'start_at' => '2099-01-10T02:00',
        'end_at' => '2099-01-10T04:00',
    ];
}

test('the switch is off by default; the page is public only while switched on and made public', function (): void {
    $db = networkDb();
    expect(NetworkIssues::enabled(networkContainer($db, [])[0]))->toBeFalse()
        ->and(NetworkIssues::isPublic(networkContainer($db, ['network_status_public' => '1'])[0]))->toBeFalse()
        ->and(NetworkIssues::enabled(networkContainer($db)[0]))->toBeTrue()
        ->and(NetworkIssues::isPublic(networkContainer($db)[0]))->toBeFalse()
        ->and(NetworkIssues::isPublic(networkContainer($db, ['network_status' => '1', 'network_status_public' => '1'])[0]))->toBeTrue();
});

test('an issue keeps what it is about, its times and its texts', function (): void {
    $db = networkDb();
    [$di] = networkContainer($db);
    $saved = NetworkIssues::save($di, networkForm(), 7);
    $issue = NetworkIssues::get($di, $saved['id']);

    expect($saved['emailed'])->toBe(0)
        ->and($issue)->toMatchArray([
            'title' => 'Cluster A maintenance', 'description' => "Kernel updates.\nShort reboots.", 'type' => 'server', 'server_id' => 1,
            'server_name' => 'Cluster A', 'about' => 'Cluster A', 'priority' => 'medium', 'status' => 'scheduled', 'open' => false,
            'start_at' => '2099-01-10 02:00:00', 'end_at' => '2099-01-10 04:00:00', 'created_by' => 7,
        ]);

    NetworkIssues::save($di, ['id' => $saved['id'], 'type' => 'other', 'affecting' => 'Email', 'server_id' => '1', 'end_at' => ''] + networkForm());
    expect(NetworkIssues::get($di, $saved['id']))->toMatchArray(['type' => 'other', 'server_id' => null, 'affecting' => 'Email', 'about' => 'Email', 'end_at' => null]);
});

test('times typed in the staff member\'s time zone are kept in server time', function (): void {
    [$di] = networkContainer(networkDb());
    $previous = date_default_timezone_get();
    date_default_timezone_set('UTC');
    try {
        $id = NetworkIssues::save($di, networkForm(['timezone' => 'Africa/Cairo', 'start_at' => '2099-07-01T15:00', 'end_at' => '2099-07-01T18:30']))['id'];
        $unknown = NetworkIssues::save($di, networkForm(['timezone' => 'Mars/Base', 'start_at' => '2099-07-01T15:00', 'end_at' => '']))['id'];

        expect(NetworkIssues::get($di, $id))->toMatchArray(['start_at' => '2099-07-01 12:00:00', 'end_at' => '2099-07-01 15:30:00'])
            ->and(NetworkIssues::get($di, $unknown)['start_at'])->toBe('2099-07-01 15:00:00');
    } finally {
        date_default_timezone_set($previous);
    }
});

test('the staff form is checked', function (array $change, string $message): void {
    [$di] = networkContainer(networkDb());
    expect(fn () => NetworkIssues::save($di, $change + networkForm()))->toThrow(InformationException::class, $message);
})->with([
    'no title' => [['title' => ' '], 'Enter a title'],
    'type' => [['type' => 'planet'], 'Choose what the issue is about'],
    'server' => [['server_id' => '9'], 'Choose the server.'],
    'other without text' => [['type' => 'other', 'affecting' => ''], 'Enter what the issue affects'],
    'priority' => [['priority' => 'urgent'], 'Choose the priority.'],
    'status' => [['status' => 'fixed'], 'Choose the status.'],
    'start' => [['start_at' => '2099-02-30T10:00'], 'Enter when the issue starts.'],
    'end before start' => [['end_at' => '2099-01-09T10:00'], 'The end must be a date and time after the start.'],
]);

test('a server issue affects the clients with services on it; others affect every client with a service', function (): void {
    [$di] = networkContainer(networkDb());

    expect(NetworkIssues::affectedClients($di, ['type' => 'server', 'server_id' => 1]))->toBe([1])
        ->and(NetworkIssues::affectedClients($di, ['type' => 'server', 'server_id' => 2]))->toBe([2])
        ->and(NetworkIssues::affectedClients($di, ['type' => 'system', 'server_id' => null]))->toBe([1, 2, 3])
        ->and(NetworkIssues::affectedClients($di, ['type' => 'other', 'server_id' => null]))->toBe([1, 2, 3]);
});

test('emailing reaches the affected clients, counts the sent ones and keeps going after a failure', function (): void {
    $db = networkDb();
    [$di, $log] = networkContainer($db);
    $saved = NetworkIssues::save($di, networkForm(['type' => 'system', 'email' => '1']));
    $issue = NetworkIssues::get($di, $saved['id']);

    expect($saved['emailed'])->toBe(2)
        ->and(array_column($log['emails'], 'to_client'))->toBe([1, 3])
        ->and($log['emails'][0]['code'])->toBe('mod_support_network_issue')
        ->and($log['emails'][0]['status_url'])->toBe('https://billing.example.com/network-status')
        ->and($log['emails'][0]['issue'])->not->toHaveKeys(['created_by', 'emailed_at', 'emailed_count'])
        ->and($issue['emailed_count'])->toBe(2)
        ->and($issue['emailed_at'])->not->toBeNull();
});

test('the banner shows a client the open issues and the scheduled work that affect it', function (): void {
    $db = networkDb();
    [$di] = networkContainer($db);
    $server1 = NetworkIssues::save($di, networkForm(['status' => 'outage', 'start_at' => '2020-01-01T00:00', 'end_at' => '']))['id'];
    $server2 = NetworkIssues::save($di, networkForm(['server_id' => '2', 'status' => 'investigating', 'start_at' => '2020-01-01T00:00', 'end_at' => '']))['id'];
    $planned = NetworkIssues::save($di, networkForm(['type' => 'system']))['id'];
    NetworkIssues::save($di, networkForm(['type' => 'system', 'status' => 'scheduled', 'start_at' => '2020-01-01T00:00', 'end_at' => '2020-01-01T02:00']));  // over
    NetworkIssues::save($di, networkForm(['type' => 'system', 'status' => 'resolved', 'start_at' => '2020-01-01T00:00', 'end_at' => '']));

    expect(array_column(NetworkIssues::alertsFor($di, 1), 'id'))->toBe([$server1, $planned])
        ->and(array_column(NetworkIssues::alertsFor($di, 2), 'id'))->toBe([$server2, $planned])
        ->and(array_column(NetworkIssues::alertsFor($di, 3), 'id'))->toBe([$planned])
        ->and(NetworkIssues::alertsFor($di, 4))->toBe([])
        ->and(NetworkIssues::alertsFor($di, 5))->toBe([])
        ->and(NetworkIssues::alertsFor($di, 1)[0])->not->toHaveKey('created_by');
});

test('the tabs: open newest first, scheduled soonest first, resolved; with their counts', function (): void {
    $db = networkDb();
    [$di] = networkContainer($db);
    $old = NetworkIssues::save($di, networkForm(['status' => 'outage', 'start_at' => '2020-01-01T00:00', 'end_at' => '']))['id'];
    $new = NetworkIssues::save($di, networkForm(['status' => 'reported', 'start_at' => '2021-01-01T00:00', 'end_at' => '']))['id'];
    $later = NetworkIssues::save($di, networkForm(['start_at' => '2099-03-01T00:00', 'end_at' => '']))['id'];
    $sooner = NetworkIssues::save($di, networkForm(['start_at' => '2099-02-01T00:00', 'end_at' => '']))['id'];
    NetworkIssues::save($di, networkForm(['status' => 'resolved', 'start_at' => '2020-01-01T00:00', 'end_at' => '']));

    expect(array_column(NetworkIssues::forPage($di, 'open')['list'], 'id'))->toBe([$new, $old])
        ->and(array_column(NetworkIssues::forPage($di, 'scheduled')['list'], 'id'))->toBe([$sooner, $later])
        ->and(NetworkIssues::forPage($di, 'nonsense')['list'])->toHaveCount(2)
        ->and(NetworkIssues::counts($di))->toBe(['open' => 2, 'scheduled' => 2, 'resolved' => 1])
        ->and(NetworkIssues::forPage($di, 'open')['list'][0])->not->toHaveKeys(['created_by', 'emailed_count']);

    NetworkIssues::delete($di, $old);
    expect(NetworkIssues::counts($di)['open'])->toBe(1)
        ->and(fn () => NetworkIssues::get($di, $old))->toThrow(InformationException::class, 'Network issue not found');
});

test('saving the main Support settings form keeps the network status switches', function (): void {
    // Extension\Service::setConfig() keeps only listed keys of settings saved by their own forms.
    $source = (string) file_get_contents(dirname(__DIR__, 3) . '/Extension/Service.php');
    $start = strpos($source, "if (\$ext === 'mod_support') {");
    $block = $start === false ? '' : substr($source, $start, (int) strpos($source, '}', $start) - $start);
    expect($block)->toContain('NetworkIssues::SETTINGS')
        ->and(NetworkIssues::SETTINGS)->toBe(['network_status', 'network_status_public']);
});

test('the test table has the columns of the real table', function (): void {
    $schema = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/Clientfence/schema.json'), true)['tables'];
    preg_match_all('/^\s*`([a-z_]+)` /m', str_replace([', `', ' (`'], [",\n`", " (\n`"], $schema['support_network_issue']), $m);
    expect(array_column(networkDb()->fetchAllAssociative('PRAGMA table_info(support_network_issue)'), 'name'))->toBe($m[1]);
});
