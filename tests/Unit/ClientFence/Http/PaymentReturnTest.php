<?php

declare(strict_types=1);

use ClientFence\Http\PaymentReturn;
use Symfony\Component\HttpFoundation\Request;

test('payment returns use fixed destinations and the shared script nonce without session credentials', function (string $status, string $path): void {
    $url = Mockery::mock(ClientFence\Url::class);
    $url->shouldReceive('link')->once()->with($path, $status === 'thankyou' ? [] : ['status' => $status])->andReturn('https://billing.example' . $path);
    $response = PaymentReturn::createResponse(Request::create('/invoice/payment-return', 'GET', ['status' => $status, 'hash' => 'abc123', 'restore_token' => 'ignored']), $url);
    expect($response->getContent())->toContain('https://billing.example' . $path)
        ->toContain('nonce="' . ClientFence\Csp::nonce() . '"')
        ->not->toContain('restore_token');
    expect($response->headers->get('Content-Security-Policy'))->toContain(ClientFence\Csp::nonce());
})->with([
    ['ok', '/invoice/abc123'], ['cancel', '/invoice/abc123'], ['thankyou', '/invoice/thank-you/abc123'],
]);
