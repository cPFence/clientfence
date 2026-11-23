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
