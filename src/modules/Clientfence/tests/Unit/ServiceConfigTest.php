<?php

declare(strict_types=1);

use ClientFence\Mod\Clientfence\Service;
use Symfony\Component\HttpFoundation\Request;

use function Tests\Helpers\container;

// ClientFence settings live in the module whose settings page shows them (Service::SETTINGS).
test('config reads each setting from the module that keeps it', function (): void {
    $configs = [
        'clientfence' => ['own_setting' => '9', 'store_mode' => 'public', 'store_subtitle' => 'Old'],
        'order' => ['store_mode' => 'testers', 'store_title' => 'Shop'],
    ];
    $di = container();
    $di['mod_config'] = $di->protect(fn (string $name): array => $configs[$name] ?? []);

    $config = Service::config($di);

    expect($config['own_setting'])->toBe('9')
        ->and($config['store_mode'])->toBe('testers')
        ->and($config['store_title'])->toBe('Shop')
        // A listed key left in this module's own configuration is not read.
        ->and($config['store_subtitle'])->toBe('')
        ->and($config['store_testers_group'])->toBe(Service::DEFAULTS['store_testers_group']);
});

test('saveSettings writes each setting to the module that keeps it', function (): void {
    $saved = [];
    $extension = Mockery::mock(ClientFence\Mod\Extension\Service::class);
    $extension->shouldReceive('getConfig')->andReturnUsing(fn (string $ext): array => ['ext' => $ext, 'kept' => $ext]);
    $extension->shouldReceive('setConfig')->andReturnUsing(function (array $data) use (&$saved): bool {
        $saved[$data['ext']] = $data;

        return true;
    });
    $di = container();
    $di['mod_service'] = $di->protect(fn (): object => $extension);

    Service::saveSettings($di, ['store_mode' => 'closed', 'own_setting' => '7']);

    expect($saved)->toBe([
        'mod_order' => ['ext' => 'mod_order', 'kept' => 'mod_order', 'store_mode' => 'closed'],
        'mod_clientfence' => ['ext' => 'mod_clientfence', 'kept' => 'mod_clientfence', 'own_setting' => '7'],
    ]);
});

// ClientFence tables (schema.json): every shipped table passes the check and is in the installer's
// structure.sql, so ensureTables() never fails and new installs get them all.
test('the shipped schema is valid and new installs create every table', function (): void {
    $tables = Service::tables();
    $structure = (string) file_get_contents(dirname(__DIR__, 4) . '/install/sql/structure.sql');

    expect($tables)->toHaveKey('support_ticket_file')->toHaveKey('client_user')->toHaveKey('client_google')
        ->and(array_keys($tables))->toBe(array_values(array_filter(array_keys($tables), static fn (string $t): bool => str_starts_with($t, 'mod_clientfence_') || in_array($t, Service::HOME_TABLES, true))));
    foreach (array_keys($tables) as $table) {
        expect($structure)->toContain("CREATE TABLE `{$table}`");
    }
});

test('a closed store answers no store call, configurable options included', function (string $method, array $answer): void {
    $di = container();
    $di['mod_config'] = $di->protect(fn (string $name): array => $name === 'order' ? ['store_mode' => 'closed'] : []);
    $di['session'] = new class {
        public function get(string $key): mixed
        {
            return null;
        }
    };
    $di['request'] = Request::create('/order');

    expect(Service::storeGate($di, 'guest', 'product', $method, new stdClass(), []))->toBe($answer);
})->with([
    'options' => ['options', ['result' => []]],
    'list' => ['get_pairs', ['result' => []]],
]);

test('a closed store offers a client no add-ons for their service and refuses the order', function (): void {
    $di = container();
    $di['mod_config'] = $di->protect(fn (string $name): array => $name === 'order' ? ['store_mode' => 'closed'] : []);
    $di['session'] = new class {
        public function get(string $key): mixed
        {
            return null;
        }
    };
    $di['request'] = Request::create('/order/service/manage/5');
    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->id = 7;

    expect(Service::storeGate($di, 'client', 'order', 'addon_offers', $client, ['id' => 5]))->toBe(['result' => []])
        ->and(fn () => Service::storeGate($di, 'client', 'order', 'addon_order', $client, ['id' => 5, 'product_id' => 9]))
        ->toThrow(ClientFence\InformationException::class, 'The store is not available.');
});

test('a closed store refuses the bulk domain check and shows no pinned TLDs (CF-139)', function (): void {
    $di = container();
    $di['mod_config'] = $di->protect(fn (string $name): array => $name === 'order' ? ['store_mode' => 'closed'] : []);
    $di['session'] = new class {
        public function get(string $key): mixed
        {
            return null;
        }
    };
    $di['request'] = Request::create('/order/domain-checker');

    // Declared by the service modules in their manifests ("store_calls"), not in Service::STORE_CALLS.
    expect(fn () => Service::storeGate($di, 'guest', 'servicedomain', 'check_bulk', new stdClass(), ['sld' => 'example', 'tlds' => ['.com']]))
        ->toThrow(ClientFence\InformationException::class, 'The store is not available.')
        ->and(Service::storeGate($di, 'guest', 'servicedomain', 'pinned_tlds', new stdClass(), []))->toBe(['result' => []])
        ->and(Service::storeGate($di, 'guest', 'ServiceHosting', 'Free_Tlds', new stdClass(), []))->toBe(['result' => []])
        ->and(Service::storeGate($di, 'guest', 'servicedomain', 'nosuchcall', new stdClass(), []))->toBeNull();
});

test('a closed store gates the calls any switched-on module declares, whatever the case of their names', function (bool $on): void {
    $di = container();
    $di['mod_config'] = $di->protect(fn (string $name): array => $name === 'order' ? ['store_mode' => 'closed'] : []);
    $di['session'] = new class {
        public function get(string $key): mixed
        {
            return null;
        }
    };
    $di['request'] = Request::create('/order');
    // A module that is not a product type, as read from its manifest (keys with capitals).
    $di['request_cache'] = new ArrayObject(['module_manifests' => ['metering' => ['store_calls' => ['Guest' => ['Get_Offers' => 'list', 'Quote' => 'closed']]]]]);
    $di['mod_service'] = $di->protect(fn (): object => new class($on) {
        public function __construct(private readonly bool $on)
        {
        }

        public function isExtensionActive(string $type, string $id): bool
        {
            return $this->on;
        }
    });

    expect(Service::storeGate($di, 'guest', 'metering', 'get_offers', new stdClass(), []))->toBe($on ? ['result' => []] : null)
        ->and(Service::storeGate($di, 'guest', 'Metering', 'GET_OFFERS', new stdClass(), []))->toBe($on ? ['result' => []] : null);
    if ($on) {
        expect(fn () => Service::storeGate($di, 'guest', 'metering', 'quote', new stdClass(), []))
            ->toThrow(ClientFence\InformationException::class, 'The store is not available.');
    } else {
        expect(Service::storeGate($di, 'guest', 'metering', 'quote', new stdClass(), []))->toBeNull();
    }
})->with(['switched on' => [true], 'switched off' => [false]]);

test('a closed store lists its TLDs on admin pages to signed-in staff only', function (string $path, bool $staff, ?array $answer): void {
    $di = container();
    $di['mod_config'] = $di->protect(fn (string $name): array => $name === 'order' ? ['store_mode' => 'closed'] : []);
    $di['session'] = new class {
        public function get(string $key): mixed
        {
            return null;
        }
    };
    $di['request'] = Request::create($path);
    $di['auth'] = new class($staff) {
        public function __construct(private readonly bool $staff)
        {
        }

        public function isAdminLoggedIn(): bool
        {
            return $this->staff;
        }
    };

    expect(Service::storeGate($di, 'guest', 'servicedomain', 'tlds', new stdClass(), []))->toBe($answer);
})->with([
    'Add New Order, staff' => [ADMIN_PREFIX . '/order/new', true, null],
    'admin page, no staff' => [ADMIN_PREFIX . '/order/new', false, ['result' => []]],
    'public page, staff' => ['/order', true, ['result' => []]],
]);

test('a closed store answers a product page with 404', function (): void {
    $di = container();
    $di['mod_config'] = $di->protect(fn (string $name): array => $name === 'order' ? ['store_mode' => 'closed'] : []);
    $di['session'] = new class {
        public function get(string $key): mixed
        {
            return null;
        }
    };
    $di['request'] = Request::create('/order');

    expect(fn () => Service::storeGate($di, 'guest', 'product', 'get', new stdClass(), ['id' => 1]))
        ->toThrow(fn (ClientFence\InformationException $e) => expect([$e->getMessage(), $e->getCode()])->toBe(['Product not found', 404]));
});

// ClientFence: the store open to one client group (Client/Groups.php).
test('a store open to one client group lets in an active or inactive client in it, whichever of their groups it is', function (): void {
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE client (id INTEGER PRIMARY KEY, status TEXT)');
    $db->executeStatement('CREATE TABLE client_group_members (client_id INTEGER NOT NULL, client_group_id INTEGER NOT NULL, PRIMARY KEY (client_id, client_group_id))');
    $db->executeStatement("INSERT INTO client (id, status) VALUES (1, 'active'), (2, 'active'), (3, 'canceled'), (4, 'inactive')");
    $db->executeStatement('INSERT INTO client_group_members (client_id, client_group_id) VALUES (1, 2), (1, 5), (2, 2), (3, 5), (4, 5)');
    $di = container();
    $di['mod_config'] = $di->protect(fn (string $name): array => $name === 'order' ? ['store_mode' => 'testers', 'store_testers_group' => '5'] : []);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;

    expect(Service::storeOpenTo($di, 1))->toBeTrue()
        ->and(Service::storeOpenTo($di, 2))->toBeFalse()
        ->and(Service::storeOpenTo($di, 3))->toBeFalse()
        ->and(Service::storeOpenTo($di, 4))->toBeTrue()
        ->and(Service::storeOpenTo($di, null))->toBeFalse();
});

// Forum #181: the store title and subtitle have language versions (store_title_de_de), kept and read next to them.
test('the store title versions are saved and read with the title, and the store page shows the visitor\'s', function (): void {
    $saved = [];
    $extension = Mockery::mock(ClientFence\Mod\Extension\Service::class);
    $extension->shouldReceive('hasManagePermission')->with('mod_order');
    $extension->shouldReceive('getConfig')->andReturnUsing(fn (string $ext): array => ['ext' => $ext]);
    $extension->shouldReceive('setConfig')->andReturnUsing(function (array $data) use (&$saved): bool {
        $saved[$data['ext']] = $data;

        return true;
    });
    $di = container();
    $di['mod_service'] = $di->protect(fn (): object => $extension);
    $di['logger'] = new Tests\Helpers\TestLogger();
    $api = new ClientFence\Mod\Clientfence\Api\Admin();
    $api->setDi($di);

    $api->store_update(['store_mode' => 'public', 'store_title' => 'Store', 'store_title_de_de' => ' Shop ', 'store_subtitle_de_de' => 'Alles', 'store_title_ja_jp' => 'Off']);
    expect($saved)->toHaveKeys(['mod_order'])->not->toHaveKey('mod_clientfence')
        ->and($saved['mod_order'])->toMatchArray(['store_title' => 'Store', 'store_title_de_de' => 'Shop', 'store_subtitle_de_de' => 'Alles'])
        ->and($saved['mod_order'])->not->toHaveKey('store_title_ja_jp')
        ->and(fn () => $api->store_update(['store_mode' => 'public', 'store_title_de_de' => str_repeat('x', 81)]))->toThrow(ClientFence\InformationException::class, 'The store title can have');

    $guestDi = container();
    $guestDi['mod_config'] = $guestDi->protect(fn (string $name): array => $name === 'order' ? $saved['mod_order'] : []);
    $guestDi['session'] = new class {
        public function get(string $key): mixed
        {
            return null;
        }
    };
    $guest = new ClientFence\Mod\Clientfence\Api\Guest();
    $guest->setDi($guestDi);
    $info = fn (string $locale): array => ClientFence\Translate::with($locale, fn (): array => array_intersect_key($guest->store_info(), ['title' => 1, 'subtitle' => 1]));

    expect($info('de_DE'))->toBe(['title' => 'Shop', 'subtitle' => 'Alles'])
        ->and($info('fr_FR'))->toBe(['title' => 'Store', 'subtitle' => '']);
});
