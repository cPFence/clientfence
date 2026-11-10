<?php

declare(strict_types=1);

/**
 * ClientFence: help desks hidden from clients (Support\HiddenHelpdesks), on an in-memory SQLite
 * copy of the table, and the client and guest APIs that leave them out.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Support\HiddenHelpdesks;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

function hiddenHelpdesksContainer(?Connection $db = null): Pimple\Container
{
    $db ??= DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE IF NOT EXISTS ' . HiddenHelpdesks::TABLE . ' (helpdesk_id INTEGER PRIMARY KEY, hidden_at TEXT NOT NULL)');
    $di = new Pimple\Container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;

    return $di;
}

/** A Support API object of the class, with the help desks 1 General, 2 Billing, 3 Internal. */
function hiddenHelpdesksApi(string $class, Pimple\Container $di, ?Closure $guestCreate = null): object
{
    $repo = Mockery::mock(ClientFence\Mod\Support\Repository\HelpdeskRepository::class);
    $repo->shouldReceive('getPairs')->andReturn([1 => 'General', 2 => 'Billing', 3 => 'Internal']);
    $repo->shouldReceive('find')->andReturnUsing(static function (int $id): ?ClientFence\Mod\Support\Entity\Helpdesk {
        if ($id < 1 || $id > 3) {
            return null;
        }
        $helpdesk = new ClientFence\Mod\Support\Entity\Helpdesk();
        $property = new ReflectionProperty($helpdesk, 'id');
        $property->setValue($helpdesk, $id);

        return $helpdesk;
    });
    $service = Mockery::mock(ClientFence\Mod\Support\Service::class);
    $service->shouldReceive('getHelpdeskRepository')->andReturn($repo);
    $service->shouldReceive('ticketCreateForClient')->andReturnUsing(static fn ($client, $helpdesk): int => 100 + (int) $helpdesk->getId());
    $service->shouldReceive('ticketCreateForGuest')->andReturnUsing($guestCreate ?? static fn (array $data): string => 'hash');
    $di['tools'] = new class {
        public function validateAndSanitizeEmail(string $email): string
        {
            return $email;
        }
    };
    $di['rate_limiter'] = new class {
        public function consumeOrThrow(): void
        {
        }
    };
    $di['request'] = Symfony\Component\HttpFoundation\Request::create('/');
    // No ticket limit (TicketLimit).
    $di['mod_config'] = $di->protect(static fn (): array => []);
    $api = new $class();
    $api->setDi($di);
    $api->setService($service);
    if ($api instanceof ClientFence\Mod\Support\Api\Client) {
        $client = new Model_Client();
        $client->loadBean(new Tests\Helpers\DummyBean());
        $client->id = 5;
        $api->setIdentity($client);
    }

    return $api;
}

test('a help desk is hidden and shown again from its form, and forgotten when deleted', function (): void {
    $di = hiddenHelpdesksContainer();
    HiddenHelpdesks::save($di, 3, '1');
    HiddenHelpdesks::save($di, 3, true);
    HiddenHelpdesks::save($di, 2, '0');
    expect(HiddenHelpdesks::ids($di))->toBe([3])
        ->and(HiddenHelpdesks::isHidden($di, 3))->toBeTrue()
        ->and(HiddenHelpdesks::isHidden($di, 2))->toBeFalse()
        ->and(HiddenHelpdesks::visiblePairs($di, [1 => 'General', 2 => 'Billing', 3 => 'Internal']))->toBe([1 => 'General', 2 => 'Billing'])
        ->and(HiddenHelpdesks::withFlags($di, [['id' => 1, 'name' => 'General'], ['id' => 3, 'name' => 'Internal']]))
        ->toBe([['id' => 1, 'name' => 'General', 'hidden' => false], ['id' => 3, 'name' => 'Internal', 'hidden' => true]]);

    HiddenHelpdesks::save($di, 3, '0');
    expect(HiddenHelpdesks::ids($di))->toBe([]);
    HiddenHelpdesks::save($di, 2, 1);
    HiddenHelpdesks::forget($di, 2);
    expect(HiddenHelpdesks::ids($di))->toBe([]);
});

test('without its table nothing is hidden', function (): void {
    $di = new Pimple\Container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
    $di['em'] = $em;

    expect(HiddenHelpdesks::ids($di))->toBe([])
        ->and(HiddenHelpdesks::visiblePairs($di, [1 => 'General']))->toBe([1 => 'General']);
});

test('clients do not see or open tickets in a hidden help desk', function (): void {
    $di = hiddenHelpdesksContainer();
    HiddenHelpdesks::save($di, 3, true);
    $api = hiddenHelpdesksApi(ClientFence\Mod\Support\Api\Client::class, $di);

    expect($api->helpdesk_get_pairs())->toBe([1 => 'General', 2 => 'Billing'])
        ->and($api->ticket_create(['support_helpdesk_id' => 2, 'subject' => 'Hello', 'content' => 'Hello there']))->toBe(102)
        ->and(fn () => $api->ticket_create(['support_helpdesk_id' => 3, 'subject' => 'Hello', 'content' => 'Hello there']))
        ->toThrow(InformationException::class, 'Helpdesk invalid');
});

test('guests do not see or open tickets in a hidden help desk, and get a visible one by default', function (): void {
    $di = hiddenHelpdesksContainer();
    HiddenHelpdesks::save($di, 1, true);
    $sent = [];
    $api = hiddenHelpdesksApi(ClientFence\Mod\Support\Api\Guest::class, $di, static function (array $data) use (&$sent): string {
        $sent[] = $data['support_helpdesk_id'] ?? null;

        return 'hash';
    });
    $ticket = ['name' => 'Guest', 'email' => 'guest@mail.test', 'subject' => 'Hello', 'content' => 'Hello there'];

    expect($api->helpdesk_get_pairs())->toBe([2 => 'Billing', 3 => 'Internal'])
        ->and($api->ticket_create($ticket))->toBe('hash')
        ->and($api->ticket_create($ticket + ['support_helpdesk_id' => 3]))->toBe('hash')
        ->and(fn () => $api->ticket_create($ticket + ['support_helpdesk_id' => 1]))
        ->toThrow(InformationException::class, 'Helpdesk invalid')
        ->and($sent)->toBe([2, 3]);

    HiddenHelpdesks::save($di, 2, true);
    HiddenHelpdesks::save($di, 3, true);
    expect(fn () => $api->ticket_create($ticket))->toThrow(InformationException::class, 'Helpdesk invalid');
});

test('nothing hidden leaves the guest form as it was', function (): void {
    $sent = [];
    $api = hiddenHelpdesksApi(ClientFence\Mod\Support\Api\Guest::class, hiddenHelpdesksContainer(), static function (array $data) use (&$sent): string {
        $sent[] = array_key_exists('support_helpdesk_id', $data) ? $data['support_helpdesk_id'] : 'none';

        return 'hash';
    });

    $api->ticket_create(['name' => 'Guest', 'email' => 'guest@mail.test', 'subject' => 'Hello', 'content' => 'Hello there']);
    expect($sent)->toBe(['none']);
});

test('clients and guests read a help desk\'s name in the page language, else the default one', function (): void {
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE product_translation (id INTEGER PRIMARY KEY, kind TEXT, ref_id INTEGER, lang TEXT, title TEXT, description TEXT, created_at TEXT, updated_at TEXT)');
    $db->insert('product_translation', ['kind' => 'helpdesk', 'ref_id' => 2, 'lang' => 'de_DE', 'title' => 'Buchhaltung', 'description' => null]);
    $di = hiddenHelpdesksContainer($db);

    expect(ClientFence\Translate::with('de_DE', fn (): array => HiddenHelpdesks::visiblePairs($di, [1 => 'General', 2 => 'Billing'])))->toBe([1 => 'General', 2 => 'Buchhaltung'])
        ->and(ClientFence\Translate::with('fr_FR', fn (): array => HiddenHelpdesks::visiblePairs($di, [1 => 'General', 2 => 'Billing'])))->toBe([1 => 'General', 2 => 'Billing']);
});
