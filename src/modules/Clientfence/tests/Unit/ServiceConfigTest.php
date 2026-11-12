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
