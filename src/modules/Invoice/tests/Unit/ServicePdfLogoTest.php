<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 */

declare(strict_types=1);

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

use function Tests\Helpers\container;

function pdfLogoService(MockHttpClient $client): ClientFence\Mod\Invoice\Service
{
    $di = container();
    $di['http_client'] = $client;
    $di['request'] = Request::create('/');
    $di['request']->server->set('DOCUMENT_ROOT', PATH_ROOT);
    $service = new class extends ClientFence\Mod\Invoice\Service {
        public function logo(string $url): array
        {
            return $this->getPdfLogoSource($url);
        }
    };
    $service->setDi($di);

    return $service;
}

function pdfLogoPng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=');
}


test('PDF logo rejects unsafe response content', function (string $body, string $mime, int $status, string $url = 'https://8.8.8.8/logo.png'): void {
    $client = new MockHttpClient(new MockResponse($body, ['http_code' => $status, 'response_headers' => ['content-type: ' . $mime]]));
    expect(pdfLogoService($client)->logo($url))->toBe(['', false]);
})->with([
    'loopback' => [pdfLogoPng(), 'image/png', 200, 'http://127.0.0.1/logo.png'],
    'metadata' => [pdfLogoPng(), 'image/png', 200, 'http://169.254.169.254/logo.png'],
    'IPv6 loopback' => [pdfLogoPng(), 'image/png', 200, 'http://[::1]/logo.png'],
    'HTML' => ['<html>secret</html>', 'text/html', 200],
    'invalid image' => ['secret', 'image/png', 200],
    'remote SVG' => ['<svg xmlns="http://www.w3.org/2000/svg"/>', 'image/svg+xml', 200],
    'too large' => [str_repeat('x', 2 * 1024 * 1024 + 1), 'image/png', 200],
    'error response' => ['error', 'image/png', 500],
]);

test('PDF logo embeds a remote raster image', function (): void {
    $client = new MockHttpClient(new MockResponse(pdfLogoPng(), ['response_headers' => ['content-type: image/png']]));
    expect(pdfLogoService($client)->logo('https://8.8.8.8/logo.png'))->toBe(['data:image/png;base64,' . base64_encode(pdfLogoPng()), false]);
});
