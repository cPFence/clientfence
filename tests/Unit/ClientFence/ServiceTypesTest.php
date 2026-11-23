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
        ->and(ServiceTypes::clientPermission($di, 'dnshosting', 'add'))->toBe('manageproducts')
        ->and(ServiceTypes::clientPermission($di, 'dnshosting', 'nosuchcall'))->toBeNull()
        ->and(ServiceTypes::clientPermission($di, 'custom', 'call'))->toBeNull()
        ->and(ServiceTypes::storeCall($di, 'domain', 'guest', 'check'))->toBe('closed')
        ->and(ServiceTypes::storeCall($di, 'domain', 'guest', 'tlds'))->toBe('list')
        ->and(ServiceTypes::storeCall($di, 'domain', 'client', 'tlds'))->toBeNull()
        ->and(ServiceTypes::storeCall($di, 'hosting', 'guest', 'free_tlds'))->toBe('list')
        ->and(ServiceTypes::storeCall($di, 'custom', 'guest', 'call'))->toBeNull()
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
    // A module's declaration as read from its manifest (the per-request cache holds it).
    $di['request_cache'] = new ArrayObject(['service_type.example' => ['store_calls' => ['guest' => ['offers' => null, 'quote' => 7]]]]);

    expect(ServiceTypes::storeCall($di, 'example', 'guest', 'offers'))->toBe('closed')
        ->and(ServiceTypes::storeCall($di, 'example', 'guest', 'quote'))->toBe('closed')
        ->and(ServiceTypes::storeCall($di, 'example', 'guest', 'other'))->toBeNull();
});

test('only a type that declares changeable as true joins upgrades and bulk prices', function (): void {
    $di = container();
    // Declarations of two modules on disk, as read from their manifests; no shipped module declares it.
    $di['request_cache'] = new ArrayObject(['service_type.custom' => ['changeable' => true], 'service_type.hosting' => ['changeable' => 'yes']]);

    expect(ServiceTypes::changeable($di))->toBe(['custom'])
        ->and(ServiceTypes::changeable(container()))->toBe([]);
});
