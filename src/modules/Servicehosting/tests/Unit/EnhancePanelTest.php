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
        $answer = $answers[$method . ' ' . $path] ?? $answers[$path] ?? [];
        if ($answer instanceof MockResponse) {
            return $answer;
        }
        if ($method !== 'GET') {
            return new MockResponse('{"id":7}');
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        if (isset($query['subscriptionId'])) {
            $answer = array_values(array_filter($answer, static fn (array $website): bool => Panel::subscriptionOf($website) === (int) $query['subscriptionId']));
        }

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

test('create returns the organization and created or reused subscription id', function (array $websites): void {
    $requests = [];
    $panel = enhancePanelFake([
        '/api/orgs/M/customers' => [['id' => 'B', 'status' => 'active', 'ownerEmail' => 'b@example.test']],
        '/api/orgs/B/members' => [['id' => 'm1', 'email' => 'b@example.test', 'roles' => ['Owner'], 'loginId' => 'L1']],
        '/api/orgs/B/websites' => $websites,
        '/api/orgs/B/subscriptions/7' => ['planId' => 1],
    ], $requests);

    expect($panel->create('b@example.test', 'Org', 'Name', 'Secret-pass-1', 'new.example', 1, 'Small', true))->toBe(['org' => 'B', 'subscription' => 7]);
})->with([
    'created' => [[]],
    'reused' => [[['id' => 'W', 'status' => 'active', 'subscriptionId' => 7, 'domain' => ['domain' => 'new.example']]]],
]);

test('create refuses an existing website on a shared subscription or another plan', function (int $planId, array $others): void {
    $requests = [];
    $panel = enhancePanelFake([
        '/api/orgs/M/customers' => [['id' => 'B', 'status' => 'active', 'ownerEmail' => 'b@example.test']],
        '/api/orgs/B/members' => [['id' => 'm1', 'email' => 'b@example.test', 'roles' => ['Owner'], 'loginId' => 'L1']],
        '/api/orgs/B/websites' => array_merge([['id' => 'W', 'status' => 'active', 'subscriptionId' => 7, 'domain' => ['domain' => 'site.example']]], $others),
        '/api/orgs/B/subscriptions/7' => ['planId' => $planId],
    ], $requests);

    expect(fn () => $panel->create('b@example.test', 'Org', 'Name', 'Secret-pass-1', 'site.example', 1, 'Small', true))
        ->toThrow(Server_Exception::class, 'under another subscription and must be moved or removed first')
        ->and(array_filter($requests, fn (string $r): bool => !str_starts_with($r, 'GET ')))->toBe([]);
})->with([
    'shared subscription' => [1, [['id' => 'W2', 'status' => 'active', 'subscriptionId' => 7, 'domain' => ['domain' => 'other.example']]]],
    'another plan' => [2, []],
]);

test('website actions use the stored subscription and refresh migrated server data', function (string $otherPrimary, string $clientEmail): void {
    $requests = [];
    $panel = enhancePanelFake([
        '/api/orgs/B/subscriptions/7' => ['id' => 7, 'subscriberId' => 'B', 'vendorId' => 'M', 'status' => 'active', 'planId' => 1],
        '/api/orgs/B/websites' => [
            ['id' => 'Other', 'status' => 'active', 'subscriptionId' => 8, 'domain' => ['domain' => $otherPrimary], 'aliases' => [['domain' => 'site.example', 'kind' => 'alias']]],
            ['id' => 'W', 'status' => 'active', 'subscriptionId' => 7, 'domain' => ['domain' => 'new.example']],
        ],
        '/api/orgs/B/websites/W' => ['serverIps' => [['ip' => '192.0.2.20', 'isPrimary' => true]], 'appServerId' => 'S2', 'appServerName' => 'migrated.example'],
        '/api/servers/S2' => ['ips' => [['ip' => '192.0.2.20', 'isPrimary' => true]]],
    ], $requests);

    $panel = $panel->forAccount('B', 7);
    expect($panel->sync($clientEmail, 'site.example', 'b@example.test')['ip'])->toBe('192.0.2.20')
        ->and($panel->server($clientEmail, 'site.example', 'b@example.test'))->toBe(['hostname' => 'migrated.example', 'ip' => '192.0.2.20'])
        ->and($requests)->not->toContain('GET /api/orgs/M/customers');
})->with([
    'another primary' => ['site.example', 'b@example.test'],
    'another alias' => ['other.example', 'b@example.test'],
    'billing email changed' => ['site.example', 'new@example.test'],
]);

test('find fails when the stored subscription has no non-deleted website', function (): void {
    $requests = [];
    $other = ['id' => 'Other', 'orgId' => 'B', 'status' => 'active', 'subscriptionId' => 8, 'domain' => ['domain' => 'site.example']];
    $panel = enhancePanelFake([
        '/api/orgs/B/subscriptions/7' => ['id' => 7, 'subscriberId' => 'B', 'vendorId' => 'M', 'status' => 'active', 'planId' => 1],
        '/api/orgs/B/websites' => [$other, ['id' => 'Deleted', 'status' => 'deleted', 'subscriptionId' => 7, 'domain' => ['domain' => 'site.example']]],
        '/api/orgs/M/websites' => [$other],
    ], $requests);

    expect(fn () => $panel->forAccount('B', 7)->find('b@example.test', 'site.example'))
        ->toThrow(Server_Exception::class, 'The panel subscription 7 of site.example was not found.')
        ->and($requests)->not->toContain('GET /api/orgs/M/websites');
});

test('stored subscription resolution refuses another owner or a missing subscription', function (array|MockResponse $subscription, string $reason): void {
    $requests = [];
    $panel = enhancePanelFake(['/api/orgs/B/subscriptions/7' => $subscription], $requests)->forAccount('B', 7);

    expect(fn () => $panel->changePlan('changed@example.test', 'changed.example', 2))
        ->toThrow(Server_Exception::class, $reason)
        ->and(array_filter($requests, fn (string $r): bool => !str_starts_with($r, 'GET ')))->toBe([]);
})->with([
    'another organization' => [['status' => 'active', 'subscriberId' => 'Other', 'vendorId' => 'M'], 'belongs to another organization or vendor'],
    'another vendor' => [['status' => 'active', 'subscriberId' => 'B', 'vendorId' => 'Other'], 'belongs to another organization or vendor'],
    'deleted' => [['status' => 'deleted'], 'The panel subscription 7 no longer exists.'],
    'not found' => [new MockResponse('', ['http_code' => 404]), 'The panel subscription 7 no longer exists.'],
]);

test('subscription lifecycle actions work without websites and skip completed changes', function (string $method, array $state, bool|int $value, bool $patch): void {
    $requests = [];
    $panel = enhancePanelFake([
        '/api/orgs/B/subscriptions/7' => $state + ['id' => 7, 'subscriberId' => 'B', 'vendorId' => 'M', 'status' => 'active', 'planId' => 1],
    ], $requests)->forAccount('B', 7);

    $panel->{$method}('changed@example.test', 'changed.example', $value);

    expect(array_values(array_filter($requests, fn (string $r): bool => !str_starts_with($r, 'GET '))))
        ->toBe($patch ? ['PATCH /api/orgs/B/subscriptions/7'] : [])
        ->and($requests)->not->toContain('GET /api/orgs/M/customers');
})->with([
    'suspend' => ['setSuspended', [], true, true],
    'already suspended' => ['setSuspended', ['suspendedBy' => 'M'], true, false],
    'unsuspend' => ['setSuspended', ['suspendedBy' => 'M'], false, true],
    'already active' => ['setSuspended', [], false, false],
    'change plan' => ['changePlan', [], 2, true],
    'same plan' => ['changePlan', [], 1, false],
]);

test('cancel succeeds when the subscription is already gone', function (array|MockResponse $subscription, ?MockResponse $delete, bool $expectDelete): void {
    $requests = [];
    $panel = enhancePanelFake([
        '/api/orgs/B/subscriptions/7' => $subscription,
        'DELETE /api/orgs/B/subscriptions/7' => $delete,
    ], $requests)->forAccount('B', 7);

    $panel->cancel('changed@example.test', 'changed.example');

    expect(array_values(array_filter($requests, fn (string $r): bool => str_starts_with($r, 'DELETE '))))
        ->toBe($expectDelete ? ['DELETE /api/orgs/B/subscriptions/7'] : []);
})->with([
    'deleted status' => [['status' => 'deleted'], null, false],
    'GET not found' => [new MockResponse('', ['http_code' => 404]), null, false],
    'DELETE not found' => [['status' => 'active', 'subscriberId' => 'B', 'vendorId' => 'M'], new MockResponse('', ['http_code' => 404]), true],
]);

test('cancel refuses an unlinked account without looking up or deleting a website', function (): void {
    $requests = [];
    $panel = enhancePanelFake([], $requests);

    expect(fn () => $panel->cancel('b@example.test', 'site.example'))
        ->toThrow(Server_Exception::class, 'Run Sync on the service first.')
        ->and($requests)->toBe([]);
});

// ClientFence: a website found by domain in another customer's organization is not this account's.
test('find refuses a website with the same domain in another customer\'s organization', function (): void {
    $requests = [];
    $panel = enhancePanelFake([
        '/api/orgs/M/customers' => [['id' => 'A', 'status' => 'active', 'ownerEmail' => 'a@example.test']],
        '/api/orgs/M/websites' => [['id' => 'W', 'orgId' => 'B', 'status' => 'active', 'domain' => ['domain' => 'site.example']]],
        '/api/orgs/B' => ['id' => 'B', 'ownerEmail' => 'b@example.test'],
    ], $requests);

    expect(fn () => $panel->find('a@example.test', 'site.example', 'a@example.test'))
        ->toThrow(Server_Exception::class, 'No website for site.example')
        ->and(array_filter($requests, fn (string $r): bool => str_starts_with($r, 'DELETE ')))->toBe([]);
});

test('find accepts a website by domain in the organization of the account\'s login (billing email changed)', function (): void {
    $requests = [];
    $panel = enhancePanelFake([
        '/api/orgs/M/websites' => [['id' => 'W', 'orgId' => 'O', 'status' => 'active', 'subscriptionId' => 7, 'domain' => ['domain' => 'site.example']]],
        '/api/orgs/O' => ['id' => 'O', 'ownerEmail' => 'old@example.test'],
    ], $requests);

    $found = $panel->find('new@example.test', 'site.example', 'old@example.test');

    expect($found['org']['id'])->toBe('O')
        ->and(Panel::subscriptionOf($found['website']))->toBe(7);
});
