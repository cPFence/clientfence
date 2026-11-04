<?php

declare(strict_types=1);

/**
 * ClientFence: encrypted ticket secrets and their audit trail on in-memory SQLite tables.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Client\Users;
use ClientFence\Mod\Support\Api\Admin as SupportAdminApi;
use ClientFence\Mod\Support\Api\Client as SupportClientApi;
use ClientFence\Mod\Support\Entity\SupportTicket;
use ClientFence\Mod\Support\Service as SupportService;
use ClientFence\Mod\Support\TicketSecrets;
use Doctrine\DBAL\DriverManager;

use function Tests\Helpers\container;
use function Tests\Helpers\setEntityId;

function ticketSecretsContainer(array $config = []): Pimple\Container
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
        'CREATE TABLE support_ticket (id INTEGER PRIMARY KEY, client_id INTEGER, status TEXT, subject TEXT, author_name TEXT, author_email TEXT, access_hash TEXT, support_helpdesk_id INTEGER)',
        'CREATE TABLE support_ticket_secret (id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INTEGER NOT NULL, kind TEXT NOT NULL, label TEXT NOT NULL, data TEXT NOT NULL, added_by_client_id INTEGER, created_at TEXT NOT NULL)',
        'CREATE TABLE support_ticket_secret_event (id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INTEGER NOT NULL, secret_label TEXT NOT NULL, action TEXT NOT NULL, admin_id INTEGER, client_id INTEGER, created_at TEXT NOT NULL)',
        'CREATE TABLE admin (id INTEGER PRIMARY KEY, name TEXT)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    foreach ([[10, 5, 'open'], [11, 6, 'open'], [12, 5, 'closed'], [13, null, 'open']] as [$id, $clientId, $status]) {
        $db->insert('support_ticket', ['id' => $id, 'client_id' => $clientId, 'status' => $status]);
    }
    $db->insert('admin', ['id' => 3, 'name' => 'Support Agent']);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $em->shouldReceive('getRepository')->andReturnUsing(static fn (string $entity): object => Mockery::mock(str_replace('\\Entity\\', '\\Repository\\', $entity) . 'Repository')->shouldIgnoreMissing());
    $em->shouldReceive('flush')->andReturnNull();
    $em->shouldReceive('remove')->andReturnNull();
    $mod = Mockery::mock(ClientFence\Module::class);
    $mod->shouldReceive('getConfig')->andReturn($config + ['secrets_server' => '1', 'secrets_other' => '1']);
    $di = container();
    $di['em'] = $em;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['crypt'] = new ClientFence\Crypt();
    $di['mod'] = $di->protect(static fn (string $name): ClientFence\Module => $mod);

    return $di;
}

function ticketSecretsClient(Pimple\Container $di, int $clientId = 5): SupportClientApi
{
    $identity = new Model_Client();
    $identity->loadBean(new Tests\Helpers\DummyBean());
    $identity->id = $clientId;
    $api = new SupportClientApi();
    $api->setDi($di);
    $api->setIdentity($identity);

    return $api;
}

function ticketSecretsServer(array $fields = []): array
{
    return $fields + ['ticket_id' => 10, 'kind' => 'server', 'host' => ' server.example.test ', 'type' => 'ssh', 'username' => ' root ', 'password' => 'example-password'];
}

test('ticket secret kinds expose settings without a ticket', function (array $config, array $expected): void {
    $di = container();
    $mod = Mockery::mock(ClientFence\Module::class);
    $mod->shouldReceive('getConfig')->once()->andReturn($config);
    $di['mod'] = $di->protect(static fn (string $name): ClientFence\Module => $mod);

    expect(ticketSecretsClient($di)->ticket_secret_kinds())->toBe($expected);
})->with([
    'defaults' => [[], ['server' => false, 'other' => false, 'days' => 7]],
    'server enabled' => [['secrets_server' => '1', 'secrets_other' => '0', 'secrets_days' => 30], ['server' => true, 'other' => false, 'days' => 30]],
    'other enabled' => [['secrets_server' => '0', 'secrets_other' => '1', 'secrets_days' => ''], ['server' => false, 'other' => true, 'days' => 7]],
    'minimum age' => [['secrets_days' => 0], ['server' => false, 'other' => false, 'days' => 1]],
    'maximum age' => [['secrets_days' => 91], ['server' => false, 'other' => false, 'days' => 90]],
]);

test('the owner adds encrypted server and other secrets without exposing values in the list', function (): void {
    $di = ticketSecretsContainer();
    $api = ticketSecretsClient($di);
    $id = $api->ticket_secret_add(ticketSecretsServer(['port' => '22', 'note' => "line one\nline two"]))['id'];
    $row = $di['em']->getConnection()->fetchAssociative('SELECT * FROM support_ticket_secret WHERE id = ?', [$id]);
    $fields = json_decode($di['crypt']->decrypt($row['data']), true, 512, JSON_THROW_ON_ERROR);

    expect($row['data'])->not->toContain('example-password', 'server.example.test')
        ->and($row['added_by_client_id'])->toBe(5)
        ->and($fields)->toBe(['host' => 'server.example.test', 'type' => 'ssh', 'username' => 'root', 'password' => 'example-password', 'ssh_key' => '', 'note' => "line one\nline two", 'port' => 22]);
    $other = $api->ticket_secret_add(['ticket_id' => 10, 'kind' => 'other', 'label' => ' Recovery code ', 'value' => " code\nsecond line "]);
    $list = $api->ticket_secret_list(['ticket_id' => 10]);
    expect($other)->toBe(['id' => $id + 1])
        ->and($list)->toBe([
            'secrets' => [
                ['id' => $id, 'kind' => 'server', 'label' => 'SSH server.example.test', 'created_at' => $row['created_at']],
                ['id' => $other['id'], 'kind' => 'other', 'label' => 'Recovery code', 'created_at' => $list['secrets'][1]['created_at']],
            ],
            'events' => [],
        ]);
});

test('secret additions reject another account, guest tickets, closed tickets and disabled kinds', function (int $ticketId, array $config, string $message): void {
    $api = ticketSecretsClient(ticketSecretsContainer($config));

    expect(fn () => $api->ticket_secret_add(ticketSecretsServer(['ticket_id' => $ticketId])))
        ->toThrow(InformationException::class, $message);
})->with([
    'another account' => [11, [], 'Ticket not found'],
    'guest ticket' => [13, [], 'Ticket not found'],
    'closed ticket' => [12, [], 'Secrets cannot be added to a closed ticket.'],
    'kind disabled' => [10, ['secrets_server' => '0'], 'This kind of secret is turned off.'],
]);

test('a ticket holds at most ten secrets', function (): void {
    $api = ticketSecretsClient(ticketSecretsContainer());
    for ($i = 0; $i < 10; ++$i) {
        $api->ticket_secret_add(ticketSecretsServer());
    }

    expect(fn () => $api->ticket_secret_add(ticketSecretsServer()))->toThrow(InformationException::class, 'At most 10 secrets');
});

test('secret fields reject missing credentials, invalid ports, long values and control characters', function (array $fields, string $message): void {
    $api = ticketSecretsClient(ticketSecretsContainer());

    expect(fn () => $api->ticket_secret_add(ticketSecretsServer($fields)))->toThrow(InformationException::class, $message);
})->with([
    'missing password and key' => [['password' => ''], 'Enter a password or an SSH key.'],
    'missing host' => [['host' => ' '], 'Enter the server host.'],
    'unknown type' => [['type' => 'ftp'], 'Choose a valid server access type.'],
    'zero port' => [['port' => 0], 'The port must be between 1 and 65535.'],
    'large port' => [['port' => 65536], 'The port must be between 1 and 65535.'],
    'fractional port' => [['port' => '22.5'], 'The port must be between 1 and 65535.'],
    'long password' => [['password' => str_repeat('p', 1001)], 'The password field must be at most 1000 characters.'],
    'host newline' => [['host' => "server\nexample"], 'The host field contains control characters.'],
    'note control' => [['note' => "hello\x00there"], 'The note field contains control characters.'],
    'key tab' => [['ssh_key' => "key\tvalue"], 'The ssh_key field contains control characters.'],
    'other empty value' => [['kind' => 'other', 'label' => 'Code', 'value' => ' '], 'Enter a label and a value for the secret.'],
    'other control' => [['kind' => 'other', 'label' => 'Code', 'value' => "a\x7Fb"], 'The value field contains control characters.'],
]);

test('staff opening needs its own permission and every successful opening is visible to the client', function (): void {
    $di = ticketSecretsContainer();
    $client = ticketSecretsClient($di);
    $id = $client->ticket_secret_add(ticketSecretsServer())['id'];
    $canOpen = false;
    $staff = Mockery::mock(ClientFence\Mod\Staff\Service::class)->makePartial();
    $staff->shouldReceive('hasPermission')->andReturnUsing(static function ($member, string $module, ?string $key = null) use (&$canOpen): bool {
        return $key !== 'open_secrets' || $canOpen;
    });
    $di['mod_service'] = $di->protect(static fn (string $name): object => $staff);
    $identity = new Model_Admin();
    $identity->loadBean(new Tests\Helpers\DummyBean());
    $identity->id = 3;
    $admin = new SupportAdminApi();
    $admin->setDi($di);
    $admin->setIdentity($identity);

    expect($admin->ticket_secret_list(['ticket_id' => 10])['can_open'])->toBeFalse()
        ->and(fn () => $admin->ticket_secret_open(['id' => $id]))->toThrow(InformationException::class, 'support.open_secrets')
        ->and($client->ticket_secret_list(['ticket_id' => 10])['events'])->toBe([]);
    $canOpen = true;
    expect($admin->ticket_secret_open(['id' => $id])['password'])->toBe('example-password')
        ->and($admin->ticket_secret_list(['ticket_id' => 10])['can_open'])->toBeTrue();
    $events = $client->ticket_secret_list(['ticket_id' => 10])['events'];
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBe(['action' => 'opened', 'label' => 'SSH server.example.test', 'staff_name' => 'Support Agent', 'created_at' => $events[0]['created_at']]);
    $canOpen = false;
    expect($admin->ticket_secret_delete(['id' => $id]))->toBeTrue()
        ->and($client->ticket_secret_list(['ticket_id' => 10])['events'][1])->toMatchArray(['action' => 'deleted', 'staff_name' => 'Support Agent'])
        ->and($di['logger']->calls)->toBe([]);
});

test('staff with only ticket viewing permission cannot delete secrets', function (): void {
    $di = ticketSecretsContainer();
    $id = ticketSecretsClient($di)->ticket_secret_add(ticketSecretsServer())['id'];
    $staff = Mockery::mock(ClientFence\Mod\Staff\Service::class)->makePartial();
    $staff->shouldReceive('hasPermission')->andReturnUsing(static fn ($member, string $module, ?string $key = null): bool => $key === null || $key === 'view');
    $di['mod_service'] = $di->protect(static fn (string $name): object => $staff);
    $identity = new Model_Admin();
    $identity->loadBean(new Tests\Helpers\DummyBean());
    $identity->id = 3;
    $admin = new SupportAdminApi();
    $admin->setDi($di);
    $admin->setIdentity($identity);

    expect($admin->ticket_secret_list(['ticket_id' => 10])['can_delete'])->toBeFalse()
        ->and(fn () => $admin->ticket_secret_delete(['id' => $id]))->toThrow(InformationException::class, 'support.manage_tickets');
});

test('another client cannot list or delete secrets and the owner deletion preserves its audit event', function (): void {
    $di = ticketSecretsContainer();
    $owner = ticketSecretsClient($di);
    $other = ticketSecretsClient($di, 6);
    $id = $owner->ticket_secret_add(ticketSecretsServer())['id'];
    expect(fn () => $other->ticket_secret_list(['ticket_id' => 10]))->toThrow(InformationException::class, 'Ticket not found')
        ->and(fn () => $other->ticket_secret_delete(['id' => $id]))->toThrow(InformationException::class, 'Ticket not found')
        ->and($owner->ticket_secret_delete(['id' => $id]))->toBeTrue()
        ->and($owner->ticket_secret_list(['ticket_id' => 10])['secrets'])->toBe([])
        ->and($di['em']->getConnection()->fetchAssociative('SELECT action, client_id, admin_id FROM support_ticket_secret_event'))->toBe(['action' => 'deleted', 'client_id' => 5, 'admin_id' => null]);
});

test('closing by staff, client, cron or status update deletes values without a deletion event', function (string $path): void {
    $di = ticketSecretsContainer();
    $id = ticketSecretsClient($di)->ticket_secret_add(ticketSecretsServer())['id'];
    TicketSecrets::openForStaff($di, $id, 3);
    $ticket = new SupportTicket();
    setEntityId($ticket, 10);
    $service = new SupportService();
    $service->setDi($di);
    match ($path) {
        'staff' => $service->closeTicket($ticket, new Model_Admin()),
        'client' => $service->closeTicket($ticket, new Model_Client()),
        'cron' => $service->autoClose($ticket),
        'status' => $service->ticketUpdate($ticket, ['status' => 'closed']),
    };

    expect($di['em']->getConnection()->fetchOne('SELECT COUNT(*) FROM support_ticket_secret'))->toBe(0)
        ->and($di['em']->getConnection()->fetchFirstColumn('SELECT action FROM support_ticket_secret_event'))->toBe(['opened']);
})->with(['staff', 'client', 'cron', 'status']);

test('deleting a ticket deletes its values and audit events', function (): void {
    $di = ticketSecretsContainer();
    $id = ticketSecretsClient($di)->ticket_secret_add(ticketSecretsServer())['id'];
    TicketSecrets::openForStaff($di, $id, 3);
    $ticket = new SupportTicket();
    setEntityId($ticket, 10);
    $service = new SupportService();
    $service->setDi($di);

    expect($service->rm($ticket))->toBeTrue()
        ->and($di['em']->getConnection()->fetchOne('SELECT COUNT(*) FROM support_ticket_secret'))->toBe(0)
        ->and($di['em']->getConnection()->fetchOne('SELECT COUNT(*) FROM support_ticket_secret_event'))->toBe(0);
});

test('pruning expires old values at the configured maximum age and keeps their audit events', function (array $config, int $days): void {
    $di = ticketSecretsContainer($config);
    $client = ticketSecretsClient($di);
    $old = $client->ticket_secret_add(ticketSecretsServer())['id'];
    $fresh = $client->ticket_secret_add(ticketSecretsServer())['id'];
    $db = $di['em']->getConnection();
    $db->update('support_ticket_secret', ['created_at' => date('Y-m-d H:i:s', time() - ($days + 1) * 86400)], ['id' => $old]);
    $db->update('support_ticket_secret', ['created_at' => date('Y-m-d H:i:s', time() - $days * 86400 + 3600)], ['id' => $fresh]);

    expect(TicketSecrets::prune($di))->toBe(1)
        ->and($db->fetchFirstColumn('SELECT id FROM support_ticket_secret'))->toBe([$fresh])
        ->and($client->ticket_secret_list(['ticket_id' => 10])['events'][0])->toMatchArray(['action' => 'expired', 'label' => 'SSH server.example.test', 'staff_name' => null]);
    TicketSecrets::forgetTicket($db, 10);
    expect($db->fetchOne('SELECT COUNT(*) FROM support_ticket_secret_event'))->toBe(1);
    TicketSecrets::forgetTicket($db, 10, true);
    expect($db->fetchOne('SELECT COUNT(*) FROM support_ticket_secret_event'))->toBe(0);
})->with([
    'default' => [[], 7],
    'configured' => [['secrets_days' => 3], 3],
    'minimum' => [['secrets_days' => 0], 1],
    'maximum' => [['secrets_days' => 100], 90],
]);

test('authorized users access ticket secret calls through the tickets permission', function (): void {
    foreach (['ticket_secret_kinds', 'ticket_secret_add', 'ticket_secret_list', 'ticket_secret_delete'] as $call) {
        expect(Users::CALLS['support'][$call])->toBe('tickets');
    }
    expect((new SupportService())->getModulePermissions()['open_secrets']['type'])->toBe('bool');
});
