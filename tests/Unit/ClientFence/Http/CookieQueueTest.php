<?php

declare(strict_types=1);

use ClientFence\Http\CookieQueue;
use Symfony\Component\HttpFoundation\Response;

function queuedCookies(Response $response): array
{
    $queued = [];
    foreach ($response->headers->getCookies() as $cookie) {
        $queued[$cookie->getName()] = $cookie;
    }

    return $queued;
}


test('queued cookies default to SameSite Lax', function (): void {
    $cookies = new CookieQueue();
    $cookies->queue('fb_locale', 'en_US', strtotime('+1 month'), '/');

    $response = new Response();
    $cookies->applyToResponse($response);

    expect(queuedCookies($response)['fb_locale']->getSameSite())->toBe('lax');
});
