<?php

declare(strict_types=1);

use ClientFence\Mod\Servicehosting\Panel\Enhance\Api;
use ClientFence\Mod\Servicehosting\Panel\Enhance\Panel;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A fake Enhance API: GET answers by path (lists as `items`), every request recorded.
 *
 * @param array<string, mixed> $answers
 * @param list<string>         $requests
 */
function enhancePanelFake(array $answers, array &$requests): Panel
{
    $http = new MockHttpClient(function (string $method, string $url) use ($answers, &$requests): MockResponse {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $requests[] = $method . ' ' . $path;
        if ($method !== 'GET') {
            return new MockResponse('{"id":"new"}');
        }
        $answer = $answers[$path] ?? [];

        return new MockResponse(json_encode(array_is_list($answer) ? ['items' => $answer, 'total' => count($answer)] : $answer));
    });

    return new Panel(new Api($http, 'https://panel.test', 'token'), 'M');
}

// ClientFence: a billing email changed to another customer's old address must not get that customer's panel login.
test('create refuses an existing panel login for an email the client has not proved', function (): void {
    $requests = [];
    $panel = enhancePanelFake(['/api/orgs/M/customers' => [['id' => 'B', 'status' => 'active', 'ownerEmail' => 'b@example.test']]], $requests);

    expect(fn () => $panel->create('b@example.test', 'Org', 'Name', 'Secret-pass-1', 'new.example', 1, 'Small'))
        ->toThrow(Server_Exception::class, 'has not confirmed this email address')
        ->and(array_filter($requests, fn (string $r): bool => !str_starts_with($r, 'GET ')))->toBe([]);
});

test('create uses an existing panel login of a proved email', function (): void {
    $requests = [];
    $panel = enhancePanelFake([
        '/api/orgs/M/customers' => [['id' => 'B', 'status' => 'active', 'ownerEmail' => 'b@example.test']],
        '/api/orgs/B/members' => [['id' => 'm1', 'email' => 'b@example.test', 'roles' => ['Owner'], 'loginId' => 'L1']],
    ], $requests);

    $panel->create('b@example.test', 'Org', 'Name', 'Secret-pass-1', 'new.example', 1, 'Small', true);

    expect($requests)->toContain('POST /api/orgs/M/customers/B/subscriptions')
        ->and($requests)->toContain('POST /api/orgs/B/websites')
        ->and($requests)->not->toContain('POST /api/orgs/M/customers');
});

// ClientFence: a website found by domain in another customer's organization is not this account's.
test('find refuses a website with the same domain in another customer\'s organization', function (): void {
    $requests = [];
    $panel = enhancePanelFake([
        '/api/orgs/M/customers' => [['id' => 'A', 'status' => 'active', 'ownerEmail' => 'a@example.test']],
        '/api/orgs/M/websites' => [['id' => 'W', 'orgId' => 'B', 'status' => 'active', 'domain' => ['domain' => 'site.example']]],
        '/api/orgs/B' => ['id' => 'B', 'ownerEmail' => 'b@example.test'],
    ], $requests);

    expect(fn () => $panel->cancel('a@example.test', 'site.example', 'a@example.test'))
        ->toThrow(Server_Exception::class, 'No website for site.example')
        ->and(array_filter($requests, fn (string $r): bool => str_starts_with($r, 'DELETE ')))->toBe([]);
});

test('find accepts a website by domain in the organization of the account\'s login (billing email changed)', function (): void {
    $requests = [];
    $panel = enhancePanelFake([
        '/api/orgs/M/websites' => [['id' => 'W', 'orgId' => 'O', 'status' => 'active', 'subscriptionId' => 7, 'domain' => ['domain' => 'site.example']]],
        '/api/orgs/O' => ['id' => 'O', 'ownerEmail' => 'old@example.test'],
    ], $requests);

    $panel->cancel('new@example.test', 'site.example', 'old@example.test');

    expect($requests)->toContain('DELETE /api/orgs/O/subscriptions/7');
});
