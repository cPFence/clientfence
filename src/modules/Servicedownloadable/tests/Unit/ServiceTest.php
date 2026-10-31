<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use ClientFence\Mod\Order\Service as OrderService;
use ClientFence\Mod\Product\Entity\Product;
use ClientFence\Mod\Servicedownloadable\Service;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

use function Tests\Helpers\container;
use function Tests\Helpers\moduleService;

function serviceDownloadableCreateProductEntity(?int $id = null, ?string $config = null): Product
{
    $product = new Product();
    if ($id !== null) {
        $reflection = new ReflectionProperty($product, 'id');
        $reflection->setValue($product, $id);
    }
    if ($config !== null) {
        $product->setConfig($config);
    }

    return $product;
}

test('action delete', function (): void {
    $service = new Service();
    $clientOrderModel = new Model_ClientOrder();

    $orderServiceMock = Mockery::mock(OrderService::class);
    $orderServiceMock->shouldReceive('getOrderService')->atLeast()->once()->andReturn(new Model_ServiceDownloadable());

    $dbMock = Mockery::mock(\ClientFence\Database::class);
    $dbMock->shouldReceive('trash')->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $orderServiceMock);

    $service->setDi($di);
    $service->action_delete($clientOrderModel);
});

test('save product config', function (): void {
    $service = new Service();
    $data = [
        'update_orders' => true,
    ];

    $productModel = serviceDownloadableCreateProductEntity(config: '{"filename": "test.txt"}');
    $emMock = Mockery::mock(EntityManagerInterface::class);
    $emMock->shouldReceive('flush')->once();

    $di = new Pimple\Container();
    $di['em'] = $emMock;

    $service->setDi($di);
    $result = $service->saveProductConfig($productModel, $data);

    expect($result)->toBeBool();
    expect($result)->toBeTrue();

    $updatedConfig = json_decode($productModel->getConfig() ?? '', true);
    expect($updatedConfig)->toBeArray();
    expect($updatedConfig['filename'])->toEqual('test.txt');
    expect($updatedConfig['update_orders'])->toBeTrue();
    expect($productModel->getUpdatedAt())->not->toBeNull();
});

test('save product config with existing config', function (): void {
    $service = new Service();
    $data = [
        'update_orders' => false,
    ];

    $productModel = serviceDownloadableCreateProductEntity(config: '{"filename": "existing.txt", "update_orders": true}');
    $emMock = Mockery::mock(EntityManagerInterface::class);
    $emMock->shouldReceive('flush')->once();

    $di = new Pimple\Container();
    $di['em'] = $emMock;

    $service->setDi($di);
    $result = $service->saveProductConfig($productModel, $data);

    expect($result)->toBeBool();
    expect($result)->toBeTrue();

    $updatedConfig = json_decode($productModel->getConfig() ?? '', true);
    expect($updatedConfig)->toBeArray();
    expect($updatedConfig['filename'])->toEqual('existing.txt');
    expect($updatedConfig['update_orders'])->toBeFalse();
    expect($productModel->getUpdatedAt())->not->toBeNull();
});

test('save product config with no existing config', function (): void {
    $service = new Service();
    $data = [
        'update_orders' => true,
    ];

    $productModel = serviceDownloadableCreateProductEntity();
    $emMock = Mockery::mock(EntityManagerInterface::class);
    $emMock->shouldReceive('flush')->once();

    $di = new Pimple\Container();
    $di['em'] = $emMock;

    $service->setDi($di);
    $result = $service->saveProductConfig($productModel, $data);

    expect($result)->toBeBool();
    expect($result)->toBeTrue();

    $updatedConfig = json_decode($productModel->getConfig() ?? '', true);
    expect($updatedConfig)->toBeArray();
    expect($updatedConfig)->toHaveKey('update_orders');
    expect($updatedConfig['update_orders'])->toBeTrue();
    expect($productModel->getUpdatedAt())->not->toBeNull();
});

test('validate file upload allows known extension with octet stream mime', function (): void {
    $service = new Service();
    $service->setDi(container());

    $file = Mockery::mock(UploadedFile::class)->shouldIgnoreMissing();
    $file->shouldReceive('getClientOriginalExtension')->andReturn('exe');
    $file->shouldReceive('getClientOriginalName')->andReturn('installer.exe');
    $file->shouldReceive('getMimeType')->andReturn('application/octet-stream');

    $reflection = new ReflectionMethod(Service::class, 'validateFileUpload');
    $reflection->invoke($service, $file);

    expect(true)->toBeTrue();
});

test('validate file upload rejects unknown extension', function (): void {
    $service = new Service();
    $service->setDi(container());

    $file = Mockery::mock(UploadedFile::class)->shouldIgnoreMissing();
    $file->shouldReceive('getClientOriginalExtension')->andReturn('php');
    $file->shouldReceive('getClientOriginalName')->andReturn('shell.php');
    $file->shouldReceive('getMimeType')->andReturn('application/x-httpd-php');

    $reflection = new ReflectionMethod(Service::class, 'validateFileUpload');

    expect(fn (): mixed => $reflection->invoke($service, $file))
        ->toThrow(ClientFence\Exception::class);
});

test('clientSettableConfigKeys returns the downloadable allowlist', function (): void {
    $service = new Service();
    $allowed = $service->clientSettableConfigKeys();

    expect($allowed)->toBeArray();
    expect($allowed)->toContain('period');
    expect($allowed)->toContain('quantity');
    // `files` is admin-controlled and must not be client-settable.
    expect($allowed)->not->toContain('files');
});

test('attachOrderConfig merges admin config over client data (admin wins)', function (): void {
    $service = new Service();
    $service->setDi(container());

    // Admin product config carries the authoritative filename/stored_filename
    // plus an admin-controlled flag (`update_orders`). Client input also carries
    // a client-injected `update_orders` value - it must lose to admin.
    $storedFilename = str_repeat('b', 64);
    $productModel = serviceDownloadableCreateProductEntity(config: json_encode([
        'filename' => 'installer.zip',
        'stored_filename' => $storedFilename,
        'update_orders' => true,
    ], JSON_THROW_ON_ERROR));

    $clientData = [
        'period' => '1M',
        'quantity' => 1,
        'update_orders' => false, // injected - admin must win
    ];

    $result = $service->attachOrderConfig($productModel, $clientData);

    // Allowlisted client keys are preserved (the merge is admin-over-client,
    // not admin-replaces-entire-client).
    expect($result['period'])->toBe('1M');
    expect($result['quantity'])->toBe(1);

    // Admin-controlled keys take precedence over the client-supplied values.
    expect($result['update_orders'])->toBeTrue('client override of admin-controlled update_orders leaked through merge');
    expect($result['filename'])->toBe('installer.zip');
    expect($result['stored_filename'])->toBe($storedFilename);
});

test('attachOrderConfig throws when product has no filename configured', function (): void {
    $service = new Service();
    $service->setDi(container());
    $productModel = serviceDownloadableCreateProductEntity(config: '{}');

    expect(fn (): array => $service->attachOrderConfig($productModel, ['period' => '1M']))
        ->toThrow(Exception::class, 'Product is not configured completely.');
});

test('product file upload rejects a request without the expected file field', function (): void {
    $service = new Service();

    $request = new Symfony\Component\HttpFoundation\Request();
    $request->files = new Symfony\Component\HttpFoundation\FileBag([
        'other' => new UploadedFile(__FILE__, 'other.txt', null, UPLOAD_ERR_OK, true),
    ]);

    $di = container();
    $di['mod_service'] = $di->protect(moduleService());
    $di['request'] = $request;
    $service->setDi($di);

    $productModel = serviceDownloadableCreateProductEntity();

    expect(fn (): bool => $service->uploadProductFile($productModel))
        ->toThrow(ClientFence\Exception::class, 'File upload failed: no files in request.');
});

test('download headers preserve the original name and supply an ASCII fallback', function (string $method, string $fileName, string $fallback): void {
    $storedFilename = bin2hex(random_bytes(32));
    $config = ['filename' => $fileName, 'stored_filename' => $storedFilename];
    $di = container();
    $di['logger'] = new Tests\Helpers\TestLogger();
    $service = new Service();
    $service->setDi($di);
    if ($method === 'sendFile') {
        $model = new Model_ServiceDownloadable();
        $model->loadBean(new Tests\Helpers\DummyBean());
        $model->id = 1;
        $model->filename = $fileName;
        $model->stored_filename = $storedFilename;
        $model->downloads = 0;
        $di['db']->shouldReceive('store')->once()->with($model);
    } else {
        $model = serviceDownloadableCreateProductEntity(1, json_encode($config));
    }
    $path = Path::join(PATH_UPLOADS, $storedFilename);
    $di['filesystem']->dumpFile($path, 'download content');

    try {
        $response = $service->$method($model);
        $header = $response->headers->get('Content-Disposition');

        expect($header)->toStartWith('attachment;')
            ->and($header)->toContain('filename=' . $fallback)
            ->and($header)->toContain("filename*=utf-8''" . rawurlencode($fileName));
    } finally {
        $di['filesystem']->remove($path);
    }
})->with([
    'service Arabic name' => ['sendFile', 'دليل.pdf', '____.pdf'],
    'product Arabic name' => ['sendProductFile', 'دليل.pdf', '____.pdf'],
    'accented name' => ['sendFile', 'résumé.pdf', 'r_sum_.pdf'],
    'percent sign' => ['sendProductFile', '100%.pdf', '100_.pdf'],
]);

test('replacing a product file updates active and pending orders and saves the product', function (): void {
    $oldConfig = ['filename' => 'old.pdf', 'stored_filename' => str_repeat('a', 64)];
    $product = serviceDownloadableCreateProductEntity(1, json_encode($oldConfig + ['update_orders' => true]));
    $active = new Model_ClientOrder();
    $active->loadBean(new Tests\Helpers\DummyBean());
    $active->id = 1;
    $active->status = Model_ClientOrder::STATUS_ACTIVE;
    $active->config = json_encode($oldConfig);
    $pending = new Model_ClientOrder();
    $pending->loadBean(new Tests\Helpers\DummyBean());
    $pending->id = 2;
    $pending->status = Model_ClientOrder::STATUS_PENDING_SETUP;
    $pending->config = json_encode($oldConfig);
    $download = new Model_ServiceDownloadable();
    $download->loadBean(new Tests\Helpers\DummyBean());
    $download->filename = $oldConfig['filename'];
    $download->stored_filename = $oldConfig['stored_filename'];

    $file = Mockery::mock(UploadedFile::class);
    $file->shouldReceive('getClientOriginalName')->andReturn('new.pdf');
    $file->shouldReceive('getClientOriginalExtension')->andReturn('pdf');
    $file->shouldReceive('getError')->andReturn(UPLOAD_ERR_OK);
    $file->shouldReceive('getMimeType')->andReturn('application/pdf');
    $file->shouldReceive('move')->once()->with(PATH_UPLOADS, Mockery::pattern('/^[a-f0-9]{64}$/'))->andReturn(new File(__FILE__));

    $productService = Mockery::mock(ClientFence\Mod\Product\Service::class);
    $productService->shouldReceive('getOrdersForProduct')->once()->with($product)->andReturn([
        ['id' => 2, 'config' => $pending->config],
        ['id' => 1, 'config' => $active->config],
    ]);
    $orderService = Mockery::mock(OrderService::class);
    $orderService->shouldReceive('getOrderService')->once()->with($active)->andReturn($download);
    $orderService->shouldReceive('getOrderService')->once()->with($pending)->andReturnNull();
    $db = Mockery::mock(ClientFence\Database::class);
    $db->shouldReceive('getExistingModelById')->once()->with('ClientOrder', 1)->andReturn($active);
    $db->shouldReceive('getExistingModelById')->once()->with('ClientOrder', 2)->andReturn($pending);
    $db->shouldReceive('store')->once()->with($active);
    $db->shouldReceive('store')->once()->with($pending);
    $db->shouldReceive('store')->once()->with($download);
    $db->shouldReceive('getCell')->andReturn(1);
    $em = Mockery::mock(EntityManagerInterface::class);
    $em->shouldReceive('flush')->once();

    $di = container();
    $di['db'] = $db;
    $di['em'] = $em;
    $di['request'] = new Request(files: ['file_data' => $file]);
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['mod_service'] = $di->protect(fn (string $name) => $name === 'product' ? $productService : $orderService);
    $service = new Service();
    $service->setDi($di);

    expect($service->uploadProductFile($product))->toBeTrue();

    $newConfig = json_decode($product->getConfig(), true);
    $expected = ['filename' => 'new.pdf', 'stored_filename' => $newConfig['stored_filename']];
    expect($newConfig)->toMatchArray($expected)
        ->and($newConfig['stored_filename'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($newConfig['stored_filename'])->not->toBe($oldConfig['stored_filename'])
        ->and(json_decode($active->config, true))->toBe($expected)
        ->and(json_decode($pending->config, true))->toBe($expected)
        ->and($download->filename)->toBe('new.pdf')
        ->and($download->stored_filename)->toBe($newConfig['stored_filename']);
});
