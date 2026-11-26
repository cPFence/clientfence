<?php

declare(strict_types=1);

use ClientFence\InformationException;
use ClientFence\Mod\Product\Entity\Product;
use ClientFence\Mod\Servicecustom\Entity\ServiceCustom;
use ClientFence\Mod\Servicednshosting\Entity\ServiceDns;
use ClientFence\ServiceTypes;

use function Tests\Helpers\container;

test('a service module declares its product type in its manifest; DNS hosting uses it', function (): void {
    $di = container();
    $proxy = new class extends ServiceDns {};

    expect(ServiceTypes::builtIn($di, 'dnshosting'))->toBeTrue()
        ->and(ServiceTypes::entity($di, 'dnshosting'))->toBe(ServiceDns::class)
        ->and(ServiceTypes::singleUnit($di, 'dnshosting'))->toBeTrue()
        ->and(ServiceTypes::orderConfigEditable($di, 'dnshosting'))->toBeFalse()
        ->and(ServiceTypes::typeOfEntity($di, new ServiceDns()))->toBe('dnshosting')
        ->and(ServiceTypes::typeOfEntity($di, $proxy))->toBe('dnshosting')
        ->and(ServiceTypes::clientPermission($di, 'servicednshosting', 'add'))->toBe('manageproducts')
        ->and(ServiceTypes::clientPermission($di, 'servicednshosting', 'nosuchcall'))->toBeNull()
        ->and(ServiceTypes::clientPermission($di, 'servicecustom', 'call'))->toBeNull()
        ->and(ServiceTypes::storeCall($di, 'servicedomain', 'guest', 'check'))->toBe('closed')
        ->and(ServiceTypes::storeCall($di, 'servicedomain', 'guest', 'tlds'))->toBe('list')
        ->and(ServiceTypes::storeCall($di, 'servicedomain', 'client', 'tlds'))->toBeNull()
        ->and(ServiceTypes::storeCall($di, 'servicehosting', 'guest', 'free_tlds'))->toBe('list')
        ->and(ServiceTypes::storeCall($di, 'servicecustom', 'guest', 'call'))->toBeNull()
        ->and(ServiceTypes::pages($di))->toBe(['hosting' => ['module' => 'servicehosting', 'permission' => 'products'], 'licence' => ['module' => 'servicelicense', 'permission' => null]])
        // A module that declares nothing, an unknown type, a type that is not a plain name: nothing declared.
        ->and(ServiceTypes::declared($di, 'custom'))->toBe([])
        ->and(ServiceTypes::typeOfEntity($di, new ServiceCustom()))->toBeNull()
        ->and(ServiceTypes::orderConfigEditable($di, 'custom'))->toBeTrue()
        ->and(ServiceTypes::singleUnit($di, 'custom'))->toBeFalse()
        ->and(ServiceTypes::declared($di, 'nosuchtype'))->toBe([])
        ->and(ServiceTypes::declared($di, '../Order'))->toBe([])
        ->and(ServiceTypes::declared($di, null))->toBe([]);
});

test('staff cannot edit the raw order configuration of a type that declares it closed', function (): void {
    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->service_type = 'dnshosting';
    $service = new ClientFence\Mod\Order\Service();
    $service->setDi(container());

    expect(fn () => $service->updateOrderConfig($order, ['dns' => ['token' => 'test-private-token']]))
        ->toThrow(InformationException::class, 'This order configuration is set when the order is placed.');
});

test('the cart and checkout keep one unit of a type that declares one unit per order', function (): void {
    $service = Mockery::mock(ClientFence\Mod\Product\Service::class)->makePartial();
    $service->shouldReceive('getProductPrice')->andReturn(10.0);
    $service->shouldReceive('getProductSetupPrice')->andReturn(0.0);
    // No configurable options: the options tables answer with no rows.
    $db = Mockery::mock(Doctrine\DBAL\Connection::class);
    $db->shouldReceive('fetchOne')->andReturn(false);
    $db->shouldReceive('fetchFirstColumn')->andReturn([]);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di = container();
    $di['em'] = $em;
    $service->setDi($di);
    $product = (new Product())->setType('dnshosting');
    (new ReflectionProperty($product, 'id'))->setValue($product, 7);

    // A saved cart line asks for 3 (the line is worked out again for the cart view and at checkout).
    expect($service->getProductOrderLineConfig($product, ['quantity' => 3])['quantity'])->toBe(1)
        ->and($service->getProductOrderLineConfig($product->setType('custom'), ['quantity' => 3])['quantity'])->toBe(3);
});

test('a store call declared with no answer, or with one that is not a name, stays closed', function (): void {
    $di = container();
    // A module's manifest as read from disk (the per-request cache holds it).
    $di['request_cache'] = new ArrayObject(['module_manifests' => ['serviceexample' => ['store_calls' => ['guest' => ['offers' => null, 'quote' => 7]]]]]);
    $di['mod_service'] = $di->protect(fn (): object => moduleSwitch(['serviceexample']));

    expect(ServiceTypes::storeCall($di, 'serviceexample', 'guest', 'offers'))->toBe('closed')
        ->and(ServiceTypes::storeCall($di, 'serviceexample', 'guest', 'quote'))->toBe('closed')
        ->and(ServiceTypes::storeCall($di, 'serviceexample', 'guest', 'other'))->toBeNull();
});

test('only a type that declares changeable as true joins upgrades and bulk prices', function (): void {
    $di = container();
    // Manifests of two modules on disk; no shipped module declares it.
    $di['request_cache'] = new ArrayObject(['module_manifests' => ['servicecustom' => ['service' => ['changeable' => true]], 'servicehosting' => ['service' => ['changeable' => 'yes']]]]);

    expect(ServiceTypes::changeable($di))->toBe(['custom'])
        ->and(ServiceTypes::changeable(container()))->toBe([]);
});

test('a malformed import_fields list is left out of the declaration, so Add New Order never reads it', function (): void {
    $di = container();
    $fields = [['key' => 'account_id', 'label' => 'Account ID']];
    $di['request_cache'] = new ArrayObject(['module_manifests' => [
        'servicecustom' => ['service' => ['import' => true, 'import_fields' => $fields]],
        'servicelicense' => ['service' => ['import' => true, 'import_fields' => [['key' => 'Bad key', 'label' => 'ID']]]],
        'servicedownloadable' => ['service' => ['import_fields' => $fields]],
    ]]);

    expect(ServiceTypes::declared($di, 'custom')['import_fields'] ?? null)->toBe($fields)
        ->and(ServiceTypes::declared($di, 'license'))->toBe(['import' => true])
        ->and(ServiceTypes::declared($di, 'downloadable'))->toBe([]);
});

/** The extension service of `$on`: the extension modules that are switched on (core modules always are). */
function moduleSwitch(array $on): object
{
    return new class($on) {
        public function __construct(private readonly array $on)
        {
        }

        public function isExtensionActive(string $type, string $id): bool
        {
            return $type === 'mod' && in_array($id, $this->on, true);
        }
    };
}

/** A container whose modules on disk are `$manifests` (by module id), with the extension modules `$on` switched on. */
function moduleHooksContainer(array $manifests, array $on): Pimple\Container
{
    $di = container();
    $di['request_cache'] = new ArrayObject(['module_manifests' => $manifests]);
    $di['mod_service'] = $di->protect(fn (): object => moduleSwitch($on));

    return $di;
}

test('any switched-on module declares its hooks at the manifest root; a switched-off one does not count', function (): void {
    $policy = ['policy' => 'fixed_window', 'limit' => 5, 'interval' => '1 minute'];
    $hooks = static fn (string $id): array => [
        'client_calls' => ['report' => 'products', 'Export' => ['invoices', 'products']],
        'rate_limits' => [$id . '_report_ip' => $policy, 'domain_lookup_ip' => $policy],
        'client_pages' => ['meters' => 'products', 'Meter-Help' => null, 'invoice/meters' => null, '' => 'owner', 'old' => true],
        'store_calls' => ['guest' => ['Offers' => 'list']],
        'usage' => true,
    ];
    // Not service modules, so their hooks were not read before; "reports" is switched off.
    $di = moduleHooksContainer(['metering' => $hooks('metering'), 'reports' => $hooks('reports')], ['metering']);

    expect(ServiceTypes::clientPermission($di, 'metering', 'report'))->toBe('products')
        ->and(ServiceTypes::clientPermission($di, 'metering', 'export'))->toBe(['invoices', 'products'])
        ->and(ServiceTypes::clientPermission($di, 'reports', 'report'))->toBeNull()
        // Named after the module: it cannot declare another module's policy.
        ->and(ServiceTypes::rateLimits($di))->toBe(['metering_report_ip' => $policy])
        // Top-level pages only, each with a permission or null.
        ->and(ServiceTypes::pages($di))->toBe(['meters' => ['module' => 'metering', 'permission' => 'products'], 'meter-help' => ['module' => 'metering', 'permission' => null]])
        ->and(ServiceTypes::storeCall($di, 'metering', 'guest', 'offers'))->toBe('list')
        ->and(ServiceTypes::storeCall($di, 'reports', 'guest', 'offers'))->toBeNull()
        ->and(ServiceTypes::usage($di))->toBe(['metering']);

    // Both on: both bill usage; the page stays with the first by module name, with one warning.
    $di = moduleHooksContainer(['reports' => $hooks('reports'), 'metering' => $hooks('metering')], ['metering', 'reports']);
    ServiceTypes::pages($di);

    expect(ServiceTypes::usage($di))->toBe(['metering', 'reports'])
        ->and(array_column(ServiceTypes::pages($di), 'module'))->toBe(['metering', 'metering'])
        ->and(array_column($di['logger']->calls, 'method'))->toBe(['warning', 'warning'])
        ->and($di['logger']->calls[0]['params'][0])->toBe('Modules metering and reports both declare the client page /meters; metering keeps it.');
});

test('the core\'s pages stay the core\'s: a module that sorts first cannot take or gate them', function (): void {
    // "aaa" sorts before every core module; it claims a core module's page, a core-routed page, a gated core page and a core module's name.
    // Also the sign-in pages the Page module renders, and a switched-on module's own name ("reports"); a switched-off
    // module ("billing") registers no routes, so its name stays free.
    $di = moduleHooksContainer([
        'aaa' => ['client_pages' => ['hosting' => null, 'two-factor' => 'owner', 'invoice' => null, 'client' => 'owner', 'login' => null, 'signup' => null, 'reports' => null, 'billing' => 'invoices', 'meters' => 'products', 'aaa' => null]],
        'billing' => [],
        'reports' => ['client_pages' => ['reports' => 'invoices']],
        'servicehosting' => ['client_pages' => ['hosting' => 'products']],
    ], ['aaa', 'reports']);

    expect(ServiceTypes::pages($di))->toBe([
        'hosting' => ['module' => 'servicehosting', 'permission' => 'products'],
        'billing' => ['module' => 'aaa', 'permission' => 'invoices'],
        'meters' => ['module' => 'aaa', 'permission' => 'products'],
        'aaa' => ['module' => 'aaa', 'permission' => null],
        'reports' => ['module' => 'reports', 'permission' => 'invoices'],
    ]);
});

test('a policy name belongs to the module with the longest matching prefix', function (): void {
    $fixed = ['policy' => 'fixed_window', 'limit' => 5, 'interval' => '1 minute'];
    $open = ['policy' => 'no_limit', 'limit' => 1, 'interval' => '1 hour'];
    $di = moduleHooksContainer([
        'reports' => ['rate_limits' => ['reports_export_ip' => $open, 'reports_view_ip' => $fixed]],
        'reports_export' => ['rate_limits' => ['reports_export_ip' => $fixed]],
    ], ['reports', 'reports_export']);

    expect(ServiceTypes::rateLimits($di))->toBe(['reports_view_ip' => $fixed, 'reports_export_ip' => $fixed]);
});

test('a module named like a product type cannot take over that type\'s rate limits', function (): void {
    $own = ['policy' => 'fixed_window', 'limit' => 60, 'interval' => '1 hour'];
    $di = moduleHooksContainer([
        'domain' => ['rate_limits' => ['domain_lookup_ip' => ['policy' => 'no_limit', 'limit' => 1, 'interval' => '1 hour']]],
        'servicedomain' => ['rate_limits' => ['domain_lookup_ip' => $own]],
    ], ['domain']);

    expect(ServiceTypes::rateLimits($di))->toBe(['domain_lookup_ip' => $own]);
});

test('a switched-off type module no longer offers product changes, but its lifecycle still runs', function (bool $on): void {
    $di = moduleHooksContainer(['serviceexample' => ['service' => ['lifecycle' => 'built-in', 'single_unit' => true, 'changeable' => true]]], $on ? ['serviceexample'] : []);

    expect(ServiceTypes::builtIn($di, 'example'))->toBeTrue()
        ->and(ServiceTypes::singleUnit($di, 'example'))->toBeTrue()
        ->and(ServiceTypes::declared($di, 'example')['changeable'] ?? null)->toBe($on ? true : null)
        ->and(in_array('example', ClientFence\Mod\Order\Upgrades::kinds($di), true))->toBe($on);
})->with(['switched on' => [true], 'switched off' => [false]]);
