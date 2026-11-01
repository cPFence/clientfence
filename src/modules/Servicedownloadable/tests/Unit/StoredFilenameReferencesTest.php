<?php

declare(strict_types=1);

use ClientFence\Mod\Servicedownloadable\Service;
use Symfony\Component\Filesystem\Path;

use function Tests\Helpers\container;

test('a stored download referenced only by a cart item is not removed', function (): void {
    $storedFilename = bin2hex(random_bytes(32));
    $db = Mockery::mock(ClientFence\Database::class);
    $db->shouldReceive('getCell')->once()
        ->with('SELECT COUNT(*) FROM service_downloadable WHERE stored_filename = :stored_filename', [':stored_filename' => $storedFilename])
        ->andReturn(0);
    foreach (['product', 'client_order', 'cart_product'] as $table) {
        $db->shouldReceive('getCell')->once()
            ->with('SELECT COUNT(*) FROM ' . $table . ' WHERE config LIKE :pattern', [':pattern' => '%' . $storedFilename . '%'])
            ->andReturn($table === 'cart_product' ? 1 : 0);
    }

    $di = container();
    $di['db'] = $db;
    $service = new Service();
    $service->setDi($di);
    $path = Path::join(PATH_UPLOADS, $storedFilename);
    $di['filesystem']->dumpFile($path, 'download content');

    try {
        (new ReflectionMethod(Service::class, 'removeStoredFileIfOrphaned'))->invoke($service, $storedFilename);

        expect($di['filesystem']->exists($path))->toBeTrue();
    } finally {
        $di['filesystem']->remove($path);
    }
});
