<?php

declare(strict_types=1);

use ClientFence\Mod\Massmailer\Entity\MassmailerMessage;
use ClientFence\Mod\Massmailer\Repository\MassmailerMessageRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use ClientFence\InformationException;

use function Tests\Helpers\moduleService;

function createMassmailerDi(?Connection $dbal = null): Pimple\Container
{
    $di = new Pimple\Container();
    $repo = Mockery::mock(MassmailerMessageRepository::class)->shouldIgnoreMissing();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getRepository')->with(MassmailerMessage::class)->andReturn($repo);
    $di['em'] = $em;
    $di['dbal'] = $dbal ?? DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

    return $di;
}

function createMassmailerDbal(): Connection
{
    return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
}

function seedReceiverTables(Connection $dbal): void
{
    $dbal->executeStatement('CREATE TABLE client_group (id INTEGER PRIMARY KEY)');
    $dbal->executeStatement('CREATE TABLE product (id INTEGER PRIMARY KEY)');
    $dbal->executeStatement('CREATE TABLE client (id INTEGER PRIMARY KEY, status TEXT, client_group_id INTEGER, first_name TEXT, last_name TEXT, email TEXT)');
    $dbal->executeStatement('CREATE TABLE client_order (id INTEGER PRIMARY KEY, client_id INTEGER, product_id INTEGER, status TEXT)');

    $dbal->executeStatement('INSERT INTO client_group (id) VALUES (1), (2)');
    $dbal->executeStatement('INSERT INTO product (id) VALUES (10), (11)');
    $dbal->executeStatement("INSERT INTO client (id, status, client_group_id, first_name, last_name, email) VALUES (1, 'active', 1, 'Ann', 'One', 'ann@example.com'), (2, 'canceled', 1, 'Bob', 'Two', 'bob@example.com'), (3, 'active', 2, 'Cy', 'Three', 'cy@example.com')");
    $dbal->executeStatement("INSERT INTO client_order (id, client_id, product_id, status) VALUES (1, 1, 10, 'active'), (2, 2, 10, 'suspended'), (3, 3, 11, 'active')");
}

test('sendMessage builds the mail payload from the client entity without a fatal error', function (): void {
    // Regression test: sendMessage() previously read $client->email/first_name/last_name
    // directly off the Client entity returned by ClientService::get(), which throws since
    // those properties are private (getEmail()/getFirstName()/getLastName() are the
    // accessors) - only reachable in production, since !Environment::isProduction() short-
    // circuits the actual send, but the fatal happens while building $data, before that check.
    $client = (new ClientFence\Mod\Client\Entity\Client())
        ->setEmail('client@example.com')
        ->setFirstName('Jane')
        ->setLastName('Doe');

    $clientService = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $clientService->shouldReceive('get')->with(['id' => 5])->twice()->andReturn($client);
    $clientService->shouldReceive('toApiArray')->with($client, true, null)->once()->andReturn([]);

    $systemService = Mockery::mock(ClientFence\Mod\System\Service::class);
    $systemService->shouldReceive('renderEmailSubjectString')->once()->andReturn('');
    $systemService->shouldReceive('renderEmailTplString')->once()->andReturn('');

    $extensionService = Mockery::mock(ClientFence\Mod\Extension\Service::class);
    $extensionService->shouldReceive('isExtensionActive')->with('mod', 'demo')->once()->andReturn(false);

    $model = (new MassmailerMessage())->setSubject('subject')->setContent('content');

    $service = new ClientFence\Mod\Massmailer\Service();
    $di = createMassmailerDi();
    $di['mod_service'] = $di->protect(moduleService([
        'client' => $clientService,
        'system' => $systemService,
        'extension' => $extensionService,
    ]));
    $service->setDi($di);

    expect($service->sendMessage($model, 5))->toBeNull();
});

test('normalize filter returns canonical enum values', function (): void {
    $service = new ClientFence\Mod\Massmailer\Service();

    $normalized = $service->normalizeFilter([
        'client_status' => ['canceled', 'active', 'active'],
        'has_order_with_status' => ['suspended', 'active', 'active'],
    ], true);

    expect($normalized)->toBe([
        'client_status' => ['active', 'canceled'],
        'has_order_with_status' => ['active', 'suspended'],
    ]);
});

test('normalize filter rejects unexpected keys in strict mode', function (): void {
    $service = new ClientFence\Mod\Massmailer\Service();
    $service->setDi(createMassmailerDi());

    expect(fn (): array => $service->normalizeFilter(['unexpected' => ['anything']], true))
        ->toThrow(InformationException::class, 'Mass mail filter contains invalid values for "unexpected"');
});

test('normalize filter rejects unknown IDs in strict mode', function (): void {
    $dbal = createMassmailerDbal();
    $dbal->executeStatement('CREATE TABLE client_group (id INTEGER PRIMARY KEY)');
    $dbal->executeStatement('INSERT INTO client_group (id) VALUES (1)');

    $service = new ClientFence\Mod\Massmailer\Service();
    $service->setDi(createMassmailerDi($dbal));

    expect(fn (): array => $service->normalizeFilter(['client_groups' => ['2', '1']], true))
        ->toThrow(InformationException::class, 'Mass mail filter contains invalid values for "client_groups"');
});

test('get message receivers builds parameterized query', function (): void {
    $dbal = createMassmailerDbal();
    seedReceiverTables($dbal);

    $model = (new MassmailerMessage())->setFilter(json_encode([
        'client_status' => ['active'],
        'client_groups' => [1],
        'has_order' => [10],
        'has_order_with_status' => ['active'],
    ], JSON_THROW_ON_ERROR));

    $service = new ClientFence\Mod\Massmailer\Service();
    $service->setDi(createMassmailerDi($dbal));

    expect($service->getMessageReceivers($model))->toBe([['id' => 1, 'name' => 'Ann One', 'email' => 'ann@example.com', 'opted_out' => false]]);
});

test('get message receivers rejects invalid stored filter', function (): void {
    $model = (new MassmailerMessage())->setFilter(json_encode([
        'client_status' => ['active', 'not-valid'],
    ], JSON_THROW_ON_ERROR));

    $service = new ClientFence\Mod\Massmailer\Service();
    $service->setDi(createMassmailerDi());

    expect(fn (): array => $service->getMessageReceivers($model))
        ->toThrow(InformationException::class, 'Mass mail filter contains invalid values for "client_status"');
});

test('get message receivers flags clients who opted out of marketing email', function (bool $optOutOn, array $expected): void {
    $dbal = createMassmailerDbal();
    seedReceiverTables($dbal);
    $dbal->executeStatement('CREATE TABLE ' . ClientFence\Mod\Client\Privacy::OPTOUT_TABLE . ' (client_id INTEGER PRIMARY KEY, source TEXT, opted_out_at TEXT)');
    $dbal->executeStatement('INSERT INTO ' . ClientFence\Mod\Client\Privacy::OPTOUT_TABLE . " (client_id, source, opted_out_at) VALUES (1, 'link', '2026-10-18')");
    $di = createMassmailerDi($dbal);
    $di['mod_config'] = $di->protect(fn (string $name): array => $name === 'client' ? ['marketing_optout' => $optOutOn ? '1' : '0'] : []);
    $model = (new MassmailerMessage())->setFilter(json_encode(['client_status' => ['active']], JSON_THROW_ON_ERROR));

    $service = new ClientFence\Mod\Massmailer\Service();
    $service->setDi($di);

    expect(array_column($service->getMessageReceivers($model), 'opted_out', 'id'))->toBe($expected);
})->with([
    'opt-out on' => [true, [3 => false, 1 => true]],
    'opt-out off: no one is flagged' => [false, [3 => false, 1 => false]],
]);

function createSendingService(Connection $dbal, array $messages = []): ClientFence\Mod\Massmailer\Service&Mockery\MockInterface
{
    $dbal->executeStatement('CREATE TABLE mod_massmailer (id INTEGER PRIMARY KEY, status TEXT, scheduled_at TEXT, sent_at TEXT, updated_at TEXT)');
    $dbal->executeStatement('CREATE TABLE mod_massmailer_recipient (message_id INTEGER, client_id INTEGER, status TEXT, queue_id INTEGER, PRIMARY KEY (message_id, client_id))');
    $dbal->executeStatement('CREATE TABLE email_queue (id INTEGER PRIMARY KEY, status TEXT)');

    $repo = Mockery::mock(MassmailerMessageRepository::class);
    $repo->shouldReceive('find')->andReturnUsing(fn (int $id): ?MassmailerMessage => $messages[$id] ?? null);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getRepository')->andReturn($repo);
    $di = Tests\Helpers\container();
    $di['em'] = $em;
    $di['dbal'] = $dbal;

    $service = Mockery::mock(ClientFence\Mod\Massmailer\Service::class)->makePartial();
    $service->setDi($di);

    return $service;
}

function recipientRows(Connection $dbal): array
{
    return $dbal->fetchAllNumeric('SELECT message_id, client_id, status, queue_id FROM mod_massmailer_recipient ORDER BY message_id, client_id');
}

test('a message is claimed for sending once, so a second click or cron run mails no one twice', function (): void {
    $dbal = createMassmailerDbal();
    seedReceiverTables($dbal);
    $service = createSendingService($dbal);
    $dbal->executeStatement("INSERT INTO mod_massmailer (id, status) VALUES (1, 'draft')");
    $model = (new MassmailerMessage(1))->setFilter('{"client_status":["active"]}');
    $service->shouldReceive('sendMessage')->twice()->andReturn(11, 12);

    expect($service->startSending($model))->toBeTrue()
        ->and($service->startSending($model))->toBeFalse();
    $service->deliver($model);
    $service->deliver($model);

    expect(recipientRows($dbal))->toBe([[1, 1, 'queued', 11], [1, 3, 'queued', 12]])
        ->and($dbal->fetchOne('SELECT status FROM mod_massmailer WHERE id = 1'))->toBe('sent');
});

test('sending skips opted-out clients and goes on after a client whose email could not be queued', function (): void {
    $dbal = createMassmailerDbal();
    seedReceiverTables($dbal);
    $service = createSendingService($dbal);
    $dbal->executeStatement("INSERT INTO mod_massmailer (id, status) VALUES (1, 'sending')");
    $dbal->executeStatement("INSERT INTO mod_massmailer_recipient (message_id, client_id, status) VALUES (1, 1, 'pending'), (1, 2, 'pending'), (1, 3, 'pending')");
    $model = new MassmailerMessage(1);
    $service->shouldReceive('sendMessage')->with($model, 1)->andThrow(new RuntimeException('Twig error'));
    $service->shouldReceive('sendMessage')->with($model, 2)->andReturnFalse();
    $service->shouldReceive('sendMessage')->with($model, 3)->andReturn(7);

    $service->deliver($model);

    expect(recipientRows($dbal))->toBe([[1, 1, 'failed', null], [1, 2, 'skipped', null], [1, 3, 'queued', 7]]);
});

test('the cron sends due scheduled messages, leaves later ones, and makes one with a broken filter a draft', function (): void {
    $dbal = createMassmailerDbal();
    seedReceiverTables($dbal);
    $due = (new MassmailerMessage(1))->setFilter('{"client_groups":[2]}');
    $later = new MassmailerMessage(2);
    $broken = (new MassmailerMessage(3))->setFilter('{"client_groups":[9]}');
    $service = createSendingService($dbal, [1 => $due, 2 => $later, 3 => $broken]);
    $dbal->executeStatement("INSERT INTO mod_massmailer (id, status, scheduled_at) VALUES (1, 'scheduled', '2026-01-01 10:00:00'), (2, 'scheduled', '2999-01-01 10:00:00'), (3, 'scheduled', '2026-01-01 10:00:00')");
    $service->shouldReceive('sendMessage')->once()->with($due, 3)->andReturn(5);

    $service->sendDue();

    expect($dbal->fetchAllKeyValue('SELECT id, status FROM mod_massmailer ORDER BY id'))->toBe([1 => 'sent', 2 => 'scheduled', 3 => 'draft'])
        ->and(recipientRows($dbal))->toBe([[1, 3, 'queued', 5]]);
});

test('the delivery report tells sent, queued, failed and skipped recipients apart', function (): void {
    $dbal = createMassmailerDbal();
    seedReceiverTables($dbal);
    $service = createSendingService($dbal);
    $dbal->executeStatement("INSERT INTO email_queue (id, status) VALUES (5, 'pending'), (6, 'failed')");
    $dbal->executeStatement("INSERT INTO mod_massmailer_recipient (message_id, client_id, status, queue_id) VALUES (1, 1, 'queued', 5), (1, 2, 'queued', 6), (1, 3, 'queued', 4), (1, 4, 'skipped', NULL), (1, 5, 'failed', NULL), (1, 6, 'pending', NULL)");

    $report = $service->getReport(new MassmailerMessage(1));

    expect($report['counts'])->toBe(['sent' => 1, 'queued' => 2, 'failed' => 2, 'skipped' => 1])
        ->and(array_column($report['recipients'], 'status', 'client_id'))->toBe([6 => 'queued', 5 => 'failed', 4 => 'skipped', 3 => 'sent', 2 => 'failed', 1 => 'queued'])
        ->and($report['recipients'][5])->toBe(['client_id' => 1, 'name' => 'Ann One', 'email' => 'ann@example.com', 'status' => 'queued']);
});

test('the content is rendered as Markdown with the email signature after it', function (): void {
    $client = new ClientFence\Mod\Client\Entity\Client();
    $clientService = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $clientService->shouldReceive('get')->andReturn($client);
    $clientService->shouldReceive('toApiArray')->andReturn(['first_name' => 'Ann']);
    $systemService = Mockery::mock(ClientFence\Mod\System\Service::class);
    $systemService->shouldReceive('renderEmailSubjectString')->with('Hi', ['c' => ['first_name' => 'Ann']], null, null)->andReturn('Hi');
    $systemService->shouldReceive('renderEmailTplString')
        ->with("{% apply markdown_to_html %}\n**Hi {{ c.first_name }}**\n{% endapply %}\n{{ guest.system_email.signature }}", ['c' => ['first_name' => 'Ann']], null, null)
        ->once()->andReturn('<p><strong>Hi Ann</strong></p>');

    $di = createMassmailerDi();
    $di['mod_service'] = $di->protect(moduleService(['client' => $clientService, 'system' => $systemService]));
    $service = new ClientFence\Mod\Massmailer\Service();
    $service->setDi($di);

    expect($service->getParsed((new MassmailerMessage())->setSubject('Hi')->setContent('**Hi {{ c.first_name }}**'), 1))->toBe(['Hi', '<p><strong>Hi Ann</strong></p>']);
});
