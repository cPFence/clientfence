<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use ClientFence\Mod\Order\Entity\Order;
use ClientFence\Mod\Order\Service as OrderService;
use ClientFence\Mod\Product\Entity\Product;
use ClientFence\Mod\Servicelicense\Entity\ServiceLicense;
use ClientFence\Mod\Servicelicense\Plugin\Simple;
use ClientFence\Mod\Servicelicense\Repository\ServiceLicenseRepository;
use ClientFence\Mod\Servicelicense\Server;
use ClientFence\Mod\Servicelicense\Service;
use Doctrine\ORM\EntityManagerInterface;

use function Tests\Helpers\container;
use function Tests\Helpers\createEntity;

function serviceLicenseCreateProductEntity(string $config): Product
{
    $product = new Product();
    $product->setConfig($config);

    return $product;
}

test('simple license keys retain 16 random characters and fit the column with their prefix', function (int $length, string $prefix): void {
    $key = (new Simple())->generate(new ServiceLicense(), new Model_ClientOrder(), ['length' => $length, 'prefix' => $prefix]);
    $savedPrefix = substr($prefix, 0, 236);
    $randomCharacters = str_replace('-', '', substr($key, strlen($savedPrefix)));

    expect($key)->toStartWith($savedPrefix)
        ->and(strlen($key))->toBeLessThanOrEqual(255)
        ->and(strlen($randomCharacters))->toBeGreaterThanOrEqual(16)
        ->and($randomCharacters)->toMatch('/^[A-Z1-9]+$/');
})->with([
    'one character' => [1, 'LICENSE-'],
    'zero characters' => [0, 'LICENSE-'],
    'oversized key' => [1000, 'LICENSE-'],
    'oversized prefix' => [1, str_repeat('P', 255)],
]);

test('attach order config empty product config', function (): void {
    $service = new Service();
    $productModel = serviceLicenseCreateProductEntity('{}');
    $data = [];

    $result = $service->attachOrderConfig($productModel, $data);
    expect($result)->toBeArray();
    expect($result)->toBe([]);
});

test('attach order config', function (): void {
    $service = new Service();
    $productModel = serviceLicenseCreateProductEntity('["hello", "world"]');
    $data = ['testing' => 'phase'];
    $expected = array_merge(json_decode($productModel->getConfig() ?? '', true) ?? [], $data);

    $result = $service->attachOrderConfig($productModel, $data);
    expect($result)->toBeArray();
    expect($result)->toEqual($expected);
});

test('get license plugins', function (): void {
    $service = new Service();
    $result = $service->getLicensePlugins();
    expect($result)->toBeArray();
});

test('action create', function (): void {
    $service = new Service();
    $clientOrderModel = new Model_ClientOrder();
    $clientOrderModel->loadBean(new Tests\Helpers\DummyBean());

    $orderServiceMock = Mockery::mock(OrderService::class);
    $orderServiceMock->shouldReceive('getConfig')->atLeast()->once()->andReturn([]);

    $em = Mockery::mock(EntityManagerInterface::class);
    $em->shouldReceive('persist')->atLeast()->once();
    $em->shouldReceive('flush')->atLeast()->once();

    $di = container();
    $di['em'] = $em;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $orderServiceMock);

    $service->setDi($di);

    $result = $service->action_create($clientOrderModel);
    expect($result)->toBeInstanceOf(ServiceLicense::class);
});

test('action activate', function (): void {
    $service = new Service();
    $clientOrderModel = new Model_ClientOrder();
    $clientOrderModel->loadBean(new Tests\Helpers\DummyBean());

    $serviceLicenseModel = new ServiceLicense();
    $serviceLicenseModel->setPlugin('Simple');

    $orderServiceMock = Mockery::mock(OrderService::class);
    $orderServiceMock->shouldReceive('getConfig')->atLeast()->once()->andReturn([]);
    $orderServiceMock->shouldReceive('getOrderService')->atLeast()->once()->andReturn($serviceLicenseModel);

    $repo = Mockery::mock(ServiceLicenseRepository::class);
    $repo->shouldReceive('findByLicenseKey')->atLeast()->once()->andReturn(null);

    $em = Mockery::mock(EntityManagerInterface::class);
    $em->shouldReceive('getRepository')->with(ServiceLicense::class)->andReturn($repo);
    $em->shouldReceive('flush')->atLeast()->once();

    $di = container();
    $di['em'] = $em;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $orderServiceMock);

    $service->setDi($di);

    $result = $service->action_activate($clientOrderModel);
    expect($result)->toBeTrue();
});

test('action activate license collision', function (): void {
    $service = new Service();
    $clientOrderModel = new Model_ClientOrder();
    $clientOrderModel->loadBean(new Tests\Helpers\DummyBean());

    $serviceLicenseModel = new ServiceLicense();
    $serviceLicenseModel->setPlugin('Simple');

    $orderServiceMock = Mockery::mock(OrderService::class);
    $orderServiceMock->shouldReceive('getConfig')->atLeast()->once()->andReturn(['iterations' => 3]);
    $orderServiceMock->shouldReceive('getOrderService')->atLeast()->once()->andReturn($serviceLicenseModel);

    $repo = Mockery::mock(ServiceLicenseRepository::class);
    $repo->shouldReceive('findByLicenseKey')
        ->times(3)
        ->andReturn($serviceLicenseModel, $serviceLicenseModel, null);

    $em = Mockery::mock(EntityManagerInterface::class);
    $em->shouldReceive('getRepository')->with(ServiceLicense::class)->andReturn($repo);
    $em->shouldReceive('flush')->atLeast()->once();

    $di = container();
    $di['em'] = $em;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $orderServiceMock);

    $service->setDi($di);

    $result = $service->action_activate($clientOrderModel);
    expect($result)->toBeTrue();
});

test('action activate license collision max iterations exception', function (): void {
    $service = new Service();
    $clientOrderModel = new Model_ClientOrder();
    $clientOrderModel->loadBean(new Tests\Helpers\DummyBean());

    $serviceLicenseModel = new ServiceLicense();
    $serviceLicenseModel->setPlugin('Simple');

    $orderServiceMock = Mockery::mock(OrderService::class);
    $orderServiceMock->shouldReceive('getConfig')->atLeast()->once()->andReturn([]);
    $orderServiceMock->shouldReceive('getOrderService')->atLeast()->once()->andReturn($serviceLicenseModel);

    $repo = Mockery::mock(ServiceLicenseRepository::class);
    $repo->shouldReceive('findByLicenseKey')->atLeast()->once()->andReturn($serviceLicenseModel);

    $em = Mockery::mock(EntityManagerInterface::class);
    $em->shouldReceive('getRepository')->with(ServiceLicense::class)->andReturn($repo);
    $em->shouldNotReceive('flush');

    $di = container();
    $di['em'] = $em;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $orderServiceMock);

    $service->setDi($di);

    expect(fn (): bool => $service->action_activate($clientOrderModel))
        ->toThrow(ClientFence\Exception::class);
});

test('action activate plugin not found', function (): void {
    $service = new Service();
    $clientOrderModel = new Model_ClientOrder();
    $clientOrderModel->loadBean(new Tests\Helpers\DummyBean());

    $serviceLicenseModel = new ServiceLicense();
    $serviceLicenseModel->setPlugin('TestPlugin');

    $orderServiceMock = Mockery::mock(OrderService::class);
    $orderServiceMock->shouldReceive('getConfig')->atLeast()->once()->andReturn([]);
    $orderServiceMock->shouldReceive('getOrderService')->atLeast()->once()->andReturn($serviceLicenseModel);

    $di = container();
    $di['logger'] = new \ClientFence\Log();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $orderServiceMock);

    $service->setDi($di);

    expect(fn (): bool => $service->action_activate($clientOrderModel))
        ->toThrow(ClientFence\Exception::class, 'License plugin TestPlugin was not found.');
});

test('action activate order activation exception', function (): void {
    $service = new Service();
    $clientOrderModel = new Model_ClientOrder();
    $clientOrderModel->loadBean(new Tests\Helpers\DummyBean());

    $orderServiceMock = Mockery::mock(OrderService::class);
    $orderServiceMock->shouldReceive('getConfig')->atLeast()->once()->andReturn([]);
    $orderServiceMock->shouldReceive('getOrderService')->atLeast()->once()->andReturn(null);

    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $orderServiceMock);

    $service->setDi($di);

    expect(fn (): bool => $service->action_activate($clientOrderModel))
        ->toThrow(ClientFence\Exception::class, 'Could not find associated service license');
});

test('action delete', function (): void {
    $service = new Service();
    $clientOrderModel = new Model_ClientOrder();
    $clientOrderModel->loadBean(new Tests\Helpers\DummyBean());

    $serviceLicenseModel = new ServiceLicense();

    $orderServiceMock = Mockery::mock(OrderService::class);
    $orderServiceMock->shouldReceive('getOrderService')->atLeast()->once()->andReturn($serviceLicenseModel);

    $em = Mockery::mock(EntityManagerInterface::class);
    $em->shouldReceive('remove')->atLeast()->once();
    $em->shouldReceive('flush')->atLeast()->once();

    $di = container();
    $di['em'] = $em;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $orderServiceMock);

    $service->setDi($di);
    $service->action_delete($clientOrderModel);
});

test('reset', function (): void {
    $service = new Service();
    $serviceLicenseModel = new ServiceLicense();

    $eventMock = Mockery::mock(\ClientFence\EventManager::class);
    $eventMock->shouldReceive('fire')->atLeast()->once();

    $em = Mockery::mock(EntityManagerInterface::class);
    $em->shouldReceive('flush')->atLeast()->once();

    $di = container();
    $di['em'] = $em;
    $di['logger'] = new \ClientFence\Log();
    $di['events_manager'] = $eventMock;

    $service->setDi($di);
    $result = $service->reset($serviceLicenseModel);
    expect($result)->toBeTrue();
});

test('is license active', function (): void {
    $service = new Service();

    $order = new Order();
    $order->setStatus(Order::STATUS_ACTIVE);

    $serviceLicenseModel = new ServiceLicense();

    $orderServiceMock = Mockery::mock(OrderService::class);
    $orderServiceMock->shouldReceive('getServiceOrder')->atLeast()->once()->andReturn($order);

    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $orderServiceMock);

    $service->setDi($di);
    $result = $service->isLicenseActive($serviceLicenseModel);
    expect($result)->toBeTrue();
});

test('is license not active', function (): void {
    $service = new Service();
    $serviceLicenseModel = new ServiceLicense();

    $orderServiceMock = Mockery::mock(OrderService::class);
    $orderServiceMock->shouldReceive('getServiceOrder')->atLeast()->once()->andReturn(null);

    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $orderServiceMock);

    $service->setDi($di);
    $result = $service->isLicenseActive($serviceLicenseModel);
    expect($result)->toBeFalse();
});

test('is license inactive when order has expired', function (): void {
    $service = new Service();

    $expiredOrder = new Order();
    $expiredOrder->setStatus(Order::STATUS_ACTIVE);
    $expiredOrder->setExpiresAt(new DateTime(date('Y-m-d H:i:s', time() - 3600)));

    $serviceLicenseModel = new ServiceLicense();

    $orderServiceMock = Mockery::mock(OrderService::class);
    $orderServiceMock->shouldReceive('getServiceOrder')
        ->atLeast()->once()
        ->andReturn($expiredOrder);

    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $orderServiceMock);

    $service->setDi($di);
    $result = $service->isLicenseActive($serviceLicenseModel);
    expect($result)->toBeFalse();
});

test('is valid ip', function (): void {
    $service = new Service();
    $serviceLicenseModel = new ServiceLicense();
    $serviceLicenseModel->setIps('{}');
    $value = '1.1.1.1';

    $em = Mockery::mock(EntityManagerInterface::class);
    $em->shouldReceive('flush')->atLeast()->once();

    $di = container();
    $di['em'] = $em;

    $service->setDi($di);

    $result = $service->isValidIp($serviceLicenseModel, $value);
    expect($result)->toBeTrue();
});

test('is valid ip when ip is not in allowed list and validation is not enforced returns true', function (): void {
    $service = new Service();
    $serviceLicenseModel = new ServiceLicense();
    $serviceLicenseModel->setIps('["2.2.2.2"]');
    $value = '1.1.1.1';

    $em = Mockery::mock(EntityManagerInterface::class);
    $em->shouldReceive('flush')->atLeast()->once();

    $di = container();
    $di['em'] = $em;

    $service->setDi($di);

    $result = $service->isValidIp($serviceLicenseModel, $value);
    expect($result)->toBeTrue();
});

test('is valid ip when validate_ip is set and ip does not match returns false', function (): void {
    $service = new Service();
    $serviceLicenseModel = new ServiceLicense();
    $serviceLicenseModel->setIps('["2.2.2.2"]');
    $serviceLicenseModel->setValidateIp(true);
    $value = '1.1.1.1';

    $result = $service->isValidIp($serviceLicenseModel, $value);
    expect($result)->toBeFalse();
});

test('is valid version', function (): void {
    $service = new Service();
    $serviceLicenseModel = new ServiceLicense();
    $serviceLicenseModel->setVersions('{}');
    $value = '1.0';

    $em = Mockery::mock(EntityManagerInterface::class);
    $em->shouldReceive('flush')->atLeast()->once();

    $di = container();
    $di['em'] = $em;

    $service->setDi($di);

    $result = $service->isValidVersion($serviceLicenseModel, $value);
    expect($result)->toBeTrue();
});

test('is valid version test2', function (): void {
    $service = new Service();
    $serviceLicenseModel = new ServiceLicense();
    $serviceLicenseModel->setVersions('["2.0"]');
    $value = '1.0';

    $em = Mockery::mock(EntityManagerInterface::class);
    $em->shouldReceive('flush')->atLeast()->once();

    $di = container();
    $di['em'] = $em;

    $service->setDi($di);

    $result = $service->isValidVersion($serviceLicenseModel, $value);
    expect($result)->toBeTrue();
});

test('is valid version test3', function (): void {
    $service = new Service();
    $serviceLicenseModel = new ServiceLicense();
    $serviceLicenseModel->setVersions('["2.0"]');
    $serviceLicenseModel->setValidateVersion(true);
    $value = '1.0';

    $result = $service->isValidVersion($serviceLicenseModel, $value);
    expect($result)->toBeFalse();
});

test('is valid path', function (): void {
    $service = new Service();
    $serviceLicenseModel = new ServiceLicense();
    $serviceLicenseModel->setPaths('{}');
    $value = '/var';

    $em = Mockery::mock(EntityManagerInterface::class);
    $em->shouldReceive('flush')->atLeast()->once();

    $di = container();
    $di['em'] = $em;

    $service->setDi($di);

    $result = $service->isValidPath($serviceLicenseModel, $value);
    expect($result)->toBeTrue();
});

test('is valid path test2', function (): void {
    $service = new Service();
    $serviceLicenseModel = new ServiceLicense();
    $serviceLicenseModel->setPaths('["/"]');
    $value = '/var';

    $em = Mockery::mock(EntityManagerInterface::class);
    $em->shouldReceive('flush')->atLeast()->once();

    $di = container();
    $di['em'] = $em;

    $service->setDi($di);

    $result = $service->isValidPath($serviceLicenseModel, $value);
    expect($result)->toBeTrue();
});

test('is valid path test3', function (): void {
    $service = new Service();
    $serviceLicenseModel = new ServiceLicense();
    $serviceLicenseModel->setPaths('["/"]');
    $serviceLicenseModel->setValidatePath(true);
    $value = '/var';

    $result = $service->isValidPath($serviceLicenseModel, $value);
    expect($result)->toBeFalse();
});

test('is valid host', function (): void {
    $service = new Service();
    $serviceLicenseModel = new ServiceLicense();
    $serviceLicenseModel->setHosts('{}');
    $value = 'site.com';

    $em = Mockery::mock(EntityManagerInterface::class);
    $em->shouldReceive('flush')->atLeast()->once();

    $di = container();
    $di['em'] = $em;

    $service->setDi($di);

    $result = $service->isValidHost($serviceLicenseModel, $value);
    expect($result)->toBeTrue();
});

test('is valid host test2', function (): void {
    $service = new Service();
    $serviceLicenseModel = new ServiceLicense();
    $serviceLicenseModel->setHosts('["clientfence.com"]');
    $value = 'site.com';

    $em = Mockery::mock(EntityManagerInterface::class);
    $em->shouldReceive('flush')->atLeast()->once();

    $di = container();
    $di['em'] = $em;

    $service->setDi($di);

    $result = $service->isValidHost($serviceLicenseModel, $value);
    expect($result)->toBeTrue();
});

test('is valid host test3', function (): void {
    $service = new Service();
    $serviceLicenseModel = new ServiceLicense();
    $serviceLicenseModel->setHosts('["clientfence.com"]');
    $serviceLicenseModel->setValidateHost(true);
    $value = 'site.com';

    $result = $service->isValidHost($serviceLicenseModel, $value);
    expect($result)->toBeFalse();
});

test('get additional params', function (): void {
    $service = new Service();
    $serviceLicenseModel = new ServiceLicense();
    $serviceLicenseModel->setPlugin('Simple');

    $result = $service->getAdditionalParams($serviceLicenseModel);
    expect($result)->toBeArray();
});

test('get owner name', function (): void {
    $service = new Service();

    $client = createEntity(ClientFence\Mod\Client\Entity\Client::class);
    $client->setFirstName('John');
    $client->setLastName('Smith');

    $serviceLicenseModel = createEntity(ServiceLicense::class);
    $serviceLicenseModel->setClientId(1);

    $expected = 'John Smith';

    $clientRepo = Mockery::mock(Doctrine\ORM\EntityRepository::class);
    $clientRepo->shouldReceive('find')->atLeast()->once()->with(1)->andReturn($client);

    $em = Mockery::mock(EntityManagerInterface::class);
    $em->shouldReceive('getRepository')->with(ClientFence\Mod\Client\Entity\Client::class)->andReturn($clientRepo);

    $di = container();
    $di['em'] = $em;

    $service->setDi($di);

    $result = $service->getOwnerName($serviceLicenseModel);
    expect($result)->toBeString();
    expect($result)->toEqual($expected);
});

test('get expiration date', function (): void {
    $service = new Service();
    $expected = '2004-02-12 15:19:21';

    $order = new Order();
    $order->setExpiresAt(new DateTime($expected));

    $serviceLicenseModel = new ServiceLicense();

    $orderServiceMock = Mockery::mock(OrderService::class);
    $orderServiceMock->shouldReceive('getServiceOrder')->atLeast()->once()->andReturn($order);

    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $orderServiceMock);

    $service->setDi($di);

    $result = $service->getExpirationDate($serviceLicenseModel);
    expect($result)->toBeString();
    expect($result)->toEqual($expected);
});

test('to api array', function (): void {
    $service = new Service();
    $serviceLicenseModel = new ServiceLicense();

    $result = $service->toApiArray($serviceLicenseModel, false, new Model_Admin());
    expect($result)->toBeArray();
    expect($result)->toHaveKey('license_key');
    expect($result)->toHaveKey('validate_ip');
    expect($result)->toHaveKey('validate_host');
    expect($result)->toHaveKey('validate_version');
    expect($result)->toHaveKey('validate_path');
    expect($result)->toHaveKey('ips');
    expect($result)->toHaveKey('hosts');
    expect($result)->toHaveKey('paths');
    expect($result)->toHaveKey('versions');
    expect($result)->toHaveKey('pinged_at');
    expect($result)->toHaveKey('plugin');
});

test('update', function (): void {
    $service = new Service();
    $data = [
        'license_key' => '123456Licence',
        'validate_ip' => '1.1.1.1',
        'validate_host' => 'clientfence.com',
        'validate_version' => '1.0',
        'validate_path' => '/usr',
        'ips' => "2.2.2.2\n",
        'pinged_at' => '',
        'plugin' => 'Simple',
    ];
    $serviceLicenseModel = new ServiceLicense();

    $em = Mockery::mock(EntityManagerInterface::class);
    $em->shouldReceive('flush')->atLeast()->once();

    $di = container();
    $di['em'] = $em;

    $service->setDi($di);
    $result = $service->update($serviceLicenseModel, $data);
    expect($result)->toBeBool();
    expect($result)->toBeTrue();
});

test('check license details format eq 2', function (): void {
    $service = new Service();
    $setChannelCalled = 0;
    $loggerMock = new class($setChannelCalled) extends \ClientFence\Log {
        public function __construct(public int &$setChannelCalled)
        {
        }

        public function setChannel(string $channel): static
        {
            ++$this->setChannelCalled;

            return $this;
        }
    };

    $data = [
        'format' => 2,
    ];

    $licenseServerMock = Mockery::mock(Server::class);
    $licenseServerMock->shouldReceive('process')->atLeast()->once()->andReturn([]);

    $di = container();
    $di['logger'] = $loggerMock;
    $di['license_server'] = $licenseServerMock;
    $service->setDi($di);

    $result = $service->checkLicenseDetails($data);

    expect($result)->toBeArray();
    expect($result)->toHaveKey('error');
    expect($result)->toHaveKey('error_code');
    expect($setChannelCalled)->toBeGreaterThanOrEqual(1);
});

test('check license details', function (): void {
    $service = new Service();
    $setChannelCalled = 0;
    $loggerMock = new class($setChannelCalled) extends \ClientFence\Log {
        public function __construct(public int &$setChannelCalled)
        {
        }

        public function setChannel(string $channel): static
        {
            ++$this->setChannelCalled;

            return $this;
        }
    };

    $data = [];

    $licenseServerMock = Mockery::mock(Server::class);
    $licenseServerMock->shouldReceive('process')->atLeast()->once()->andReturn([]);

    $di = container();
    $di['logger'] = $loggerMock;
    $di['license_server'] = $licenseServerMock;
    $service->setDi($di);

    $result = $service->checkLicenseDetails($data);

    expect($result)->toBeArray();
    expect($setChannelCalled)->toBeGreaterThanOrEqual(1);
});

test('server process rejects expired license', function (): void {
    $server = new Server();

    $serviceLicense = new ServiceLicense();
    $serviceLicense->setLicenseKey('KEY');

    $serviceMock = Mockery::mock(Service::class);
    $serviceMock->shouldReceive('isLicenseActive')
        ->once()
        ->andReturn(false);

    $repo = Mockery::mock(ServiceLicenseRepository::class);
    $repo->shouldReceive('findByLicenseKey')
        ->once()
        ->with('KEY')
        ->andReturn($serviceLicense);

    $em = Mockery::mock(EntityManagerInterface::class);
    $em->shouldReceive('getRepository')->with(ServiceLicense::class)->andReturn($repo);
    $em->shouldReceive('flush')->once();

    $requestMock = Mockery::mock(ClientFence\Request::class);
    $requestMock->shouldReceive('getClientIp')->once()->andReturn('127.0.0.1');

    $di = container();
    $di['em'] = $em;
    $di['request'] = $requestMock;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $serviceMock);
    $server->setDi($di);

    $data = [
        'license' => 'KEY',
        'host' => 'example.com',
        'version' => '1.0',
        'path' => '/var/www',
    ];

    expect(fn (): array => $server->process($data))
        ->toThrow(LogicException::class, 'License is not active');
});

test('clientSettableConfigKeys returns the license allowlist', function (): void {
    $service = new Service();
    $allowed = $service->clientSettableConfigKeys();

    expect($allowed)->toBeArray();
    expect($allowed)->toContain('period');
    expect($allowed)->toContain('quantity');
    // Admin-controlled keys must not appear - the central filter strips them
    // before attachOrderConfig's merge runs, preventing client override of
    // validation flags, plugin name, key length, prefix, and iterations.
    expect($allowed)->not->toContain('plugin');
    expect($allowed)->not->toContain('length');
    expect($allowed)->not->toContain('prefix');
    expect($allowed)->not->toContain('iterations');
    expect($allowed)->not->toContain('validate_ip');
    expect($allowed)->not->toContain('validate_host');
    expect($allowed)->not->toContain('validate_path');
    expect($allowed)->not->toContain('validate_version');
});

// ClientFence: the check's own errors reach the caller instead of an unexpected error.
test('check license details passes the check error on', function (): void {
    $service = new Service();
    $licenseServerMock = Mockery::mock(Server::class);
    $licenseServerMock->shouldReceive('process')->once()->andThrow(new LogicException('License key is not present in call', 1001));

    $di = container();
    $di['logger'] = new ClientFence\Log();
    $di['license_server'] = $licenseServerMock;
    $service->setDi($di);

    expect(fn () => $service->checkLicenseDetails([]))
        ->toThrow(fn (ClientFence\InformationException $e) => expect([$e->getMessage(), $e->getCode()])->toBe(['License key is not present in call', 1001]));
});
