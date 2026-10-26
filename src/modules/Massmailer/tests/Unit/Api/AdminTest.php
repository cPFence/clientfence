<?php

declare(strict_types=1);

use ClientFence\Mod\Massmailer\Entity\MassmailerMessage;
use ClientFence\Mod\Massmailer\Repository\MassmailerMessageRepository;
use ClientFence\InformationException;

use function Tests\Helpers\container;
use function Tests\Helpers\moduleService;

function createMassmailerAdminDi(MassmailerMessage $message, bool $expectFlush = true): Pimple\Container
{
    $di = new Pimple\Container();

    $repo = Mockery::mock(MassmailerMessageRepository::class);
    $repo->shouldReceive('find')->with(1)->once()->andReturn($message);

    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getRepository')->with(MassmailerMessage::class)->andReturn($repo);

    if ($expectFlush) {
        $em->shouldReceive('flush')->once();
    } else {
        $em->shouldNotReceive('flush');
    }

    $di['em'] = $em;
    $di['mod_service'] = $di->protect(moduleService());

    return $di;
}

test('update stores normalized filter', function (): void {
    $model = (new MassmailerMessage())
        ->setContent('content')
        ->setSubject('subject')
        ->setStatus(MassmailerMessage::STATUS_DRAFT);

    $service = new ClientFence\Mod\Massmailer\Service();
    $di = createMassmailerAdminDi($model);
    $di['logger'] = new \ClientFence\Log();
    $service->setDi($di);

    $api = apiEndpoint(new ClientFence\Mod\Massmailer\Api\Admin());
    $api->setDi($di);
    $api->setService($service);

    $result = $api->update([
        'id' => 1,
        'filter' => [
            'client_status' => ['canceled', 'active', 'active'],
            'has_order_with_status' => ['suspended', 'active', 'active'],
        ],
    ]);

    expect($result)->toBeTrue();
    expect($model->getFilter())->toBe('{"client_status":["active","canceled"],"has_order_with_status":["active","suspended"]}');
});

test('get_test_client returns the configured test client email', function (): void {
    // Regression test: get_test_client() previously read $client->email directly off the
    // Client entity returned by ClientService::get(), which throws since that property is
    // private (Client::getEmail() is the accessor) - see the sibling bug in
    // Massmailer\Service::sendMessage(), covered in ServiceTest.php.
    $client = (new ClientFence\Mod\Client\Entity\Client())->setEmail('test-client@example.com');

    $clientService = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $clientService->shouldReceive('get')->with(['id' => 5])->once()->andReturn($client);

    $modMock = Mockery::mock();
    $modMock->shouldReceive('getConfig')->andReturn(['test_client_id' => 5]);

    $di = container();
    $di['mod_service'] = $di->protect(moduleService(['client' => $clientService]));
    $di['mod'] = $di->protect(fn (): Mockery\MockInterface => $modMock);

    $api = apiEndpoint(new ClientFence\Mod\Massmailer\Api\Admin());
    $api->setDi($di);

    expect($api->get_test_client())->toBe('test-client@example.com');
});

test('update rejects invalid filter', function (): void {
    $model = (new MassmailerMessage())
        ->setContent('content')
        ->setSubject('subject')
        ->setStatus(MassmailerMessage::STATUS_DRAFT);

    $service = new ClientFence\Mod\Massmailer\Service();
    $di = createMassmailerAdminDi($model, false);
    $di['logger'] = new \ClientFence\Log();
    $service->setDi($di);

    $api = apiEndpoint(new ClientFence\Mod\Massmailer\Api\Admin());
    $api->setDi($di);
    $api->setService($service);

    expect(fn (): bool => $api->update([
        'id' => 1,
        'filter' => ['client_status' => ['active', 'not-valid']],
    ]))->toThrow(InformationException::class, 'Mass mail filter contains invalid values for "client_status"');
});

test('a message being sent or sent can no longer be changed', function (string $status): void {
    $model = (new MassmailerMessage())->setSubject('subject')->setStatus($status);
    $service = new ClientFence\Mod\Massmailer\Service();
    $di = createMassmailerAdminDi($model, false);
    $service->setDi($di);

    $api = apiEndpoint(new ClientFence\Mod\Massmailer\Api\Admin());
    $api->setDi($di);
    $api->setService($service);

    expect(fn (): bool => $api->update(['id' => 1, 'subject' => 'changed', 'status' => 'draft']))
        ->toThrow(InformationException::class, 'This message was already sent.');
    expect($model->getSubject())->toBe('subject');
})->with(['sending', 'sent']);

test('a subject longer than its column is refused', function (): void {
    $model = (new MassmailerMessage())->setSubject('subject')->setStatus(MassmailerMessage::STATUS_DRAFT);
    $service = new ClientFence\Mod\Massmailer\Service();
    $di = createMassmailerAdminDi($model, false);
    $service->setDi($di);

    $api = apiEndpoint(new ClientFence\Mod\Massmailer\Api\Admin());
    $api->setDi($di);
    $api->setService($service);

    expect(fn (): bool => $api->update(['id' => 1, 'subject' => str_repeat('é', 256)]))
        ->toThrow(InformationException::class, 'Use at most 255 characters.');
});

test('a test email to an opted-out test client is not reported as sent', function (): void {
    $model = (new MassmailerMessage())->setContent('content');
    $repo = Mockery::mock(MassmailerMessageRepository::class);
    $repo->shouldReceive('find')->with(1)->andReturn($model);
    $service = Mockery::mock(ClientFence\Mod\Massmailer\Service::class);
    $service->shouldReceive('getMessageRepository')->andReturn($repo);
    $service->shouldReceive('sendMessage')->with($model, 5, true)->once()->andReturnFalse();
    $modMock = Mockery::mock();
    $modMock->shouldReceive('getConfig')->andReturn(['test_client_id' => 5]);

    $api = apiEndpoint(new ClientFence\Mod\Massmailer\Api\Admin());
    $di = container();
    $di['mod_service'] = $di->protect(moduleService());
    $di['mod'] = $di->protect(fn (): Mockery\MockInterface => $modMock);
    $api->setDi($di);
    $api->setService($service);

    expect(fn (): bool => $api->send_test(['id' => 1]))->toThrow(InformationException::class, 'The test client opted out of marketing email.');
});

test('staff schedule a message for a later time and cancel it back to a draft until sending starts', function (): void {
    $model = (new MassmailerMessage(1))->setContent('content');
    $dbal = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $dbal->executeStatement('CREATE TABLE mod_massmailer (id INTEGER PRIMARY KEY, status TEXT, scheduled_at TEXT, updated_at TEXT)');
    $dbal->executeStatement("INSERT INTO mod_massmailer (id, status) VALUES (1, 'draft')");
    $repo = Mockery::mock(MassmailerMessageRepository::class);
    $repo->shouldReceive('find')->with(1)->andReturn($model);
    $service = Mockery::mock(ClientFence\Mod\Massmailer\Service::class);
    $service->shouldReceive('getMessageRepository')->andReturn($repo);

    $api = apiEndpoint(new ClientFence\Mod\Massmailer\Api\Admin());
    $di = container();
    $di['mod_service'] = $di->protect(moduleService());
    $di['dbal'] = $dbal;
    $di['logger'] = new ClientFence\Log();
    $api->setDi($di);
    $api->setService($service);
    $status = fn (): array => $dbal->fetchAssociative('SELECT status, scheduled_at FROM mod_massmailer WHERE id = 1');

    expect(fn (): bool => $api->schedule(['id' => 1, 'scheduled_at' => '2020-01-01T10:00']))->toThrow(InformationException::class, 'Pick a date and time in the future.');
    expect($api->schedule(['id' => 1, 'scheduled_at' => '2999-05-01T10:30']))->toBeTrue()
        ->and($status())->toBe(['status' => 'scheduled', 'scheduled_at' => '2999-05-01 10:30:00']);
    expect($api->cancel_schedule(['id' => 1]))->toBeTrue()
        ->and($status())->toBe(['status' => 'draft', 'scheduled_at' => null]);

    $dbal->executeStatement("UPDATE mod_massmailer SET status = 'sending' WHERE id = 1");
    expect(fn (): bool => $api->schedule(['id' => 1, 'scheduled_at' => '2999-05-01T10:30']))->toThrow(InformationException::class, 'This message was already sent.')
        ->and(fn (): bool => $api->cancel_schedule(['id' => 1]))->toThrow(InformationException::class, 'This message was already sent.');
});
