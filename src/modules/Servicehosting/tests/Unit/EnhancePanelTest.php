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

// A failed create cannot remove the login it made: it reports that login, not one it found.
test('create reports the login it made, also when a later step fails', function (bool $exists): void {
    $requests = [];
    $panel = enhancePanelFake([
        'POST /api/logins' => $exists ? new MockResponse('{}', ['http_code' => 409]) : new MockResponse('{"id":"L1"}'),
        '/api/v2/orgs/M/customers/logins' => $exists ? [['id' => 'L1', 'email' => 'n@example.test']] : [],
        'POST /api/orgs/M/customers/7/subscriptions' => new MockResponse('{"message":"plan limit"}', ['http_code' => 400]),
    ], $requests);

    expect(fn () => $panel->create('n@example.test', 'Org', 'Name', 'Secret-pass-1', 'new.example', 1, 'Small', $exists))->toThrow(Server_Exception::class)
        ->and($panel->createdLogin())->toBe($exists ? null : 'n@example.test')
        ->and($requests)->toContain('DELETE /api/orgs/7');
})->with(['new login' => [false], 'existing login' => [true]]);

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

// ClientFence: Enhance leaves the subscription's transfer usage at 0; the month's bandwidth comes from its own call (GitHub #44).
test('usage reads this month\'s bandwidth from the subscription bandwidth call', function (MockResponse $bandwidth, int $used): void {
    $requests = [];
    $usage = enhancePanelFake([
        '/api/orgs/M/websites' => [['id' => 'W', 'orgId' => 'O', 'status' => 'active', 'subscriptionId' => 7, 'domain' => ['domain' => 'site.example']]],
        '/api/orgs/O' => ['id' => 'O', 'ownerEmail' => 'a@example.test'],
        '/api/orgs/O/subscriptions/7' => ['id' => 7, 'resources' => [['name' => 'transfer', 'usage' => 5_000_000, 'total' => 40_000_000_000]]],
        '/api/orgs/O/subscriptions/7/bandwidth' => $bandwidth,
    ], $requests)->usage('a@example.test', 'site.example');

    expect($usage['bandwidth_used_mb'])->toBe($used)
        ->and($usage['bandwidth_limit_mb'])->toBe(40_000);
})->with([
    'from the bandwidth call' => [new MockResponse('242610000'), 243],
    'older panel without the call' => [new MockResponse('', ['http_code' => 404]), 5],
]);

// ClientFence (GitHub #47): a link set by hand is this panel's subscription of that organization, its login a member who is not staff.
test('checkLink accepts the organization\'s own subscription and refuses another owner or a login that is not a client member', function (array|MockResponse $subscription, string $login, ?string $reason): void {
    $requests = [];
    $panel = enhancePanelFake([
        '/api/orgs/B/subscriptions/7' => $subscription,
        '/api/orgs/B/members' => [
            ['id' => 'm1', 'email' => 'owner@example.test', 'roles' => ['Owner'], 'loginId' => 'L1'],
            ['id' => 'm2', 'email' => 'staff@example.test', 'roles' => ['SuperAdmin'], 'loginId' => 'L2'],
        ],
        '/api/orgs/M/members' => [['id' => 's1', 'email' => 'staff@example.test', 'loginId' => 'L2']],
    ], $requests);
    $check = fn () => $panel->forAccount('B', 7)->checkLink($login);

    if ($reason === null) {
        $check();
        expect($requests)->toContain('GET /api/orgs/B/subscriptions/7');
    } else {
        expect($check)->toThrow(Server_Exception::class, $reason);
    }
    expect(array_filter($requests, fn (string $r): bool => !str_starts_with($r, 'GET ')))->toBe([]);
})->with([
    'own subscription, no login' => [['status' => 'active', 'subscriberId' => 'B', 'vendorId' => 'M'], '', null],
    'own subscription and member login' => [['status' => 'active', 'subscriberId' => 'B', 'vendorId' => 'M'], 'owner@example.test', null],
    'another organization' => [['status' => 'active', 'subscriberId' => 'Other', 'vendorId' => 'M'], '', 'belongs to another organization or vendor'],
    'another panel vendor' => [['status' => 'active', 'subscriberId' => 'B', 'vendorId' => 'Other'], '', 'belongs to another organization or vendor'],
    'no such subscription in this organization' => [new MockResponse('', ['http_code' => 404]), '', 'The Enhance organization B has no subscription 7. Check both IDs in the panel.'],
    'login not a member' => [['status' => 'active', 'subscriberId' => 'B', 'vendorId' => 'M'], 'else@example.test', 'No Enhance login for else@example.test was found.'],
    'staff login' => [['status' => 'active', 'subscriberId' => 'B', 'vendorId' => 'M'], 'staff@example.test', 'No Enhance login for staff@example.test was found.'],
]);

// CF-181: a panel that cannot be reached says why and what to check (the request URL stays in the log).
test('an unreachable panel names the reason and what to check', function (string $error, string $expected): void {
    $api = new Api(new MockHttpClient(fn (): MockResponse => new MockResponse('', ['error' => $error . ' for "https://ssl.panel.test/api/orgs/M"'])), 'https://panel.test', 'token');

    expect(fn () => $api->get('/orgs/M'))->toThrow(Server_Exception::class, $expected);
})->with([
    'host name' => ['Could not resolve host: panel.test', 'its host name was not found'],
    'wrong port' => ['OpenSSL/3.0.13: error:0A00010B:SSL routines::wrong version number', 'nothing answers HTTPS on this port'],
    'refused' => ['Failed to connect to panel.test port 2087 after 3 ms: Couldn\'t connect to server', 'the connection was refused'],
    'closed port, curl 8' => ['Failed to connect to 127.0.0.1 port 8444 after 0 ms: Could not connect to server', 'the connection was refused'],
    'timeout' => ['Idle timeout reached', 'the connection timed out'],
    'certificate' => ["SSL: no alternative certificate subject name matches target host name 'panel.test'", 'its TLS certificate could not be verified'],
    'self-signed' => ['SSL certificate problem: self-signed certificate', 'its TLS certificate could not be verified'],
    'reset inside TLS' => ['OpenSSL SSL_read: Connection reset by peer, errno 104', 'Please try again later.'],
    'end of stream inside TLS' => ['OpenSSL SSL_read: SSL_ERROR_SYSCALL, errno 0', 'Please try again later.'],
    'other, host name with ssl' => ['Recv failure: Connection reset by peer', 'Please try again later.'],
]);

// Forum #159: a plan with several websites is one subscription; the Email and DNS tabs pick among its websites only.
test('sites lists the subscription\'s websites and site picks only one of them', function (bool $stored): void {
    $requests = [];
    $panel = enhancePanelFake([
        '/api/orgs/M/customers' => [['id' => 'B', 'status' => 'active', 'ownerEmail' => 'b@example.test']],
        '/api/orgs/B/subscriptions/7' => ['id' => 7, 'subscriberId' => 'B', 'vendorId' => 'M', 'status' => 'active', 'planId' => 1],
        '/api/orgs/B/websites' => [
            ['id' => 'Other', 'status' => 'active', 'subscriptionId' => 8, 'domain' => ['domain' => 'other.example']],
            ['id' => 'Second', 'status' => 'active', 'subscriptionId' => 7, 'domain' => ['domain' => 'second.example']],
            ['id' => 'W', 'status' => 'active', 'subscriptionId' => 7, 'domain' => ['domain' => 'site.example']],
            ['id' => 'Gone', 'status' => 'deleted', 'subscriptionId' => 7, 'domain' => ['domain' => 'gone.example']],
        ],
    ], $requests);
    $panel = $stored ? $panel->forAccount('B', 7) : $panel;
    $site = fn (string $website): string => $panel->site('b@example.test', 'site.example', '', $website)['website']['id'];

    expect(array_map(fn (array $s): string => $s['website']['id'], $panel->sites('b@example.test', 'site.example')))->toBe(['W', 'Second'])
        ->and($site(''))->toBe('W')
        ->and($site('SECOND.example'))->toBe('Second')
        ->and(fn () => $site('other.example'))->toThrow(Server_Exception::class, 'is not part of this hosting account')
        ->and(fn () => $site('gone.example'))->toThrow(Server_Exception::class, 'is not part of this hosting account');
})->with(['stored subscription' => [true], 'found by the client email' => [false]]);

// Forum #165: a resource the plan does not list is not sold (0); one without a total is unlimited (null).
test('plans read a resource the plan leaves out as 0 and one without a total as unlimited', function (): void {
    $requests = [];
    $panel = enhancePanelFake(['/api/orgs/M/plans' => [['id' => 3, 'name' => 'Small', 'resources' => [
        ['name' => 'subdomains'],
        ['name' => 'mailboxes', 'total' => 5],
        ['name' => 'domainAliases', 'total' => 0],
    ]]]], $requests);

    expect($panel->plans()[0])->toMatchArray(['max_addon' => 0, 'max_sub' => null, 'max_pop' => 5, 'max_park' => 0, 'max_ftp' => 0, 'max_sql' => 0]);
});
