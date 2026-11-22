<?php

declare(strict_types=1);

use ClientFence\InformationException;
use ClientFence\Mod\Servicednshosting\Provider\ClouDNS;
use ClientFence\Mod\Servicednshosting\Provider\Cloudflare;
use ClientFence\Mod\Servicednshosting\Provider\Desec;
use ClientFence\Mod\Servicednshosting\Provider\DigitalOcean;
use ClientFence\Mod\Servicednshosting\Provider\Hetzner;
use ClientFence\Mod\Servicednshosting\Provider\PowerDNS;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

dataset('DNS providers', [
    'Cloudflare' => [Cloudflare::class, ['token' => 'test-token', 'account_id' => 'account-id'], [
        ['POST', 'https://api.cloudflare.com/client/v4/zones', ['name' => 'example.com', 'jump_start' => false, 'account' => ['id' => 'account-id']], 200, ['success' => true, 'result' => ['id' => 'zone-a', 'name_servers' => ['ns.example.org']]]],
        ['POST', 'https://api.cloudflare.com/client/v4/zones/zone-a/dns_records', ['type' => 'MX', 'name' => 'example.com', 'ttl' => 3600, 'content' => 'mx.example.com', 'priority' => 10, 'proxied' => false], 200, ['success' => true, 'result' => ['id' => 'record-a']]],
        ['DELETE', 'https://api.cloudflare.com/client/v4/zones/zone-a', null, 404, ['success' => false]],
        ['POST', 'https://api.cloudflare.com/client/v4/zones', ['name' => 'example.com', 'jump_start' => false, 'account' => ['id' => 'account-id']], 200, ['success' => false, 'errors' => [['message' => 'test-token']]]],
    ], 'zone-a'],
    'PowerDNS' => [PowerDNS::class, ['token' => 'test-token', 'api_url' => 'https://dns.example.org', 'soa_email' => '', 'nameservers' => ['ns.example.org']], [
        ['POST', 'https://dns.example.org/api/v1/servers/localhost/zones', ['name' => 'example.com.', 'kind' => 'Master', 'masters' => [], 'nameservers' => ['ns.example.org.'], 'dnssec' => false], 201, ['id' => 'example.com.']],
        ['GET', 'https://dns.example.org/api/v1/servers/localhost/zones/example.com.', null, 200, ['rrsets' => []]],
        ['PATCH', 'https://dns.example.org/api/v1/servers/localhost/zones/example.com.', ['rrsets' => [['name' => 'example.com.', 'type' => 'MX', 'ttl' => 3600, 'changetype' => 'REPLACE', 'records' => [['content' => '10 mx.example.com.', 'disabled' => false]]]]], 204, null],
        ['DELETE', 'https://dns.example.org/api/v1/servers/localhost/zones/example.com.', null, 404, ['error' => 'Not found']],
        ['POST', 'https://dns.example.org/api/v1/servers/localhost/zones', ['name' => 'example.com.', 'kind' => 'Master', 'masters' => [], 'nameservers' => ['ns.example.org.'], 'dnssec' => false], 422, ['error' => 'test-token']],
    ], 'example.com.'],
    'Hetzner DNS' => [Hetzner::class, ['token' => 'test-token'], [
        ['POST', 'https://api.hetzner.cloud/v1/zones', ['name' => 'example.com', 'mode' => 'primary'], 201, ['zone' => ['id' => 5], 'action' => ['id' => 7, 'status' => 'running']]],
        ['GET', 'https://api.hetzner.cloud/v1/zones/actions/7', null, 200, ['action' => ['status' => 'success']]],
        ['POST', 'https://api.hetzner.cloud/v1/zones/example.com/rrsets/%40/MX/actions/add_records', ['ttl' => 3600, 'records' => [['value' => '10 mx.example.com.']]], 201, ['action' => ['status' => 'success']]],
        ['DELETE', 'https://api.hetzner.cloud/v1/zones/example.com', null, 404, ['error' => ['code' => 'not_found']]],
        ['POST', 'https://api.hetzner.cloud/v1/zones', ['name' => 'example.com', 'mode' => 'primary'], 201, ['zone' => ['id' => 6], 'action' => ['status' => 'error', 'error' => ['message' => 'test-token']]]],
    ], 'example.com'],
    'ClouDNS' => [ClouDNS::class, ['auth_id' => '12', 'password' => 'test-token'], [
        ['POST', 'https://api.cloudns.net/dns/register.json', ['zone-type' => 'master', 'domain-name' => 'example.com', 'auth-id' => '12', 'auth-password' => 'test-token'], 200, ['status' => 'Success']],
        ['POST', 'https://api.cloudns.net/dns/add-record.json', ['host' => '', 'record-type' => 'MX', 'record' => 'mx.example.com', 'ttl' => '3600', 'priority' => '10', 'domain-name' => 'example.com', 'auth-id' => '12', 'auth-password' => 'test-token'], 200, ['status' => 'Success', 'data' => ['id' => '99']]],
        ['POST', 'https://api.cloudns.net/dns/delete.json', ['domain-name' => 'example.com', 'auth-id' => '12', 'auth-password' => 'test-token'], 200, ['status' => 'Failed', 'statusDescription' => 'Missing domain-name']],
        ['POST', 'https://api.cloudns.net/dns/register.json', ['zone-type' => 'master', 'domain-name' => 'example.com', 'auth-id' => '12', 'auth-password' => 'test-token'], 200, ['status' => 'Failed', 'statusDescription' => 'test-token']],
    ], 'example.com'],
    'DigitalOcean' => [DigitalOcean::class, ['token' => 'test-token'], [
        ['POST', 'https://api.digitalocean.com/v2/domains', ['name' => 'example.com'], 201, ['domain' => ['name' => 'example.com']]],
        ['POST', 'https://api.digitalocean.com/v2/domains/example.com/records', ['type' => 'MX', 'name' => '@', 'data' => 'mx.example.com', 'ttl' => 3600, 'priority' => 10], 201, ['domain_record' => ['id' => 23]]],
        ['DELETE', 'https://api.digitalocean.com/v2/domains/example.com', null, 404, ['message' => 'Not found']],
        ['POST', 'https://api.digitalocean.com/v2/domains', ['name' => 'example.com'], 201, ['message' => 'test-token']],
    ], 'example.com'],
    'deSEC' => [Desec::class, ['token' => 'test-token'], [
        ['POST', 'https://desec.io/api/v1/domains/', ['name' => 'example.com'], 201, ['name' => 'example.com']],
        ['GET', 'https://desec.io/api/v1/domains/example.com/rrsets/@/MX/', null, 404, ['detail' => 'Not found']],
        ['POST', 'https://desec.io/api/v1/domains/example.com/rrsets/', ['subname' => '', 'type' => 'MX', 'ttl' => 3600, 'records' => ['10 mx.example.com.']], 201, ['type' => 'MX']],
        ['DELETE', 'https://desec.io/api/v1/domains/example.com/', null, 404, ['detail' => 'Not found']],
        ['POST', 'https://desec.io/api/v1/domains/', ['name' => 'example.com'], 403, ['detail' => 'test-token']],
    ], 'example.com'],
]);

test('Servicednshosting provider sends the expected zone and record requests and maps failure', function (string $class, array $config, array $requests, string $zone): void {
    $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, $class, $config): MockResponse {
        expect($requests)->not->toBeEmpty();
        [$verb, $endpoint, $payload, $status, $body] = array_shift($requests);
        expect($method)->toBe($verb)
            ->and(rawurldecode($url))->toBe(rawurldecode($endpoint));
        if ($payload !== null) {
            if ($class === ClouDNS::class) {
                parse_str($options['body'], $sent);
            } else {
                $sent = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
            }
            expect($sent)->toEqual($payload);
        }
        if (isset($config['token'])) {
            expect(implode("\n", $options['headers']))->toContain('test-token');
        }

        return new MockResponse($body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR), ['http_code' => $status]);
    });
    $provider = new $class($http, $config);
    expect($provider->createZone('example.com')['id'])->toBe($zone);
    $provider->addRecord($zone, 'example.com', ['name' => '', 'type' => 'MX', 'value' => 'mx.example.com', 'priority' => 10, 'ttl' => 3600]);
    $provider->deleteZone($zone);
    try {
        $provider->createZone('example.com');
        $this->fail('The provider response should have failed.');
    } catch (InformationException $e) {
        expect($e->getCode())->toBe(502)->and($e->getMessage())->not->toContain('test-token');
    }
    expect($requests)->toBe([]);
})->with('DNS providers');

test('Servicednshosting sends a long TXT value as 255-byte strings and reads several strings as one value', function (): void {
    $sent = null;
    $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
        if ($method === 'PATCH') {
            $sent = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR)['rrsets'][0]['records'][0]['content'];

            return new MockResponse('', ['http_code' => 204]);
        }

        return new MockResponse(json_encode(['rrsets' => [['name' => 'example.com.', 'type' => 'TXT', 'ttl' => 3600, 'records' => [['content' => '"v=DKIM1; " "p=AB\\"C"', 'disabled' => false]]]]], JSON_THROW_ON_ERROR));
    });
    $provider = new PowerDNS($http, ['token' => 'test-token', 'api_url' => 'https://dns.example.org', 'soa_email' => '', 'nameservers' => ['ns.example.org']]);
    $provider->addRecord('example.com.', 'example.com', ['name' => 'k._domainkey', 'type' => 'TXT', 'value' => str_repeat('a', 300), 'ttl' => 3600]);

    expect($sent)->toBe('"' . str_repeat('a', 255) . '" "' . str_repeat('a', 45) . '"')
        ->and($provider->records('example.com.', 'example.com')[0]['value'])->toBe('v=DKIM1; p=AB"C');
});

test('Servicednshosting Cloudflare edits a record with PATCH, keeping the fields the editor does not send', function (): void {
    $sent = [];
    $provider = new Cloudflare(new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
        $sent = [$method, rawurldecode($url), json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR)];

        return new MockResponse(json_encode(['success' => true, 'result' => []], JSON_THROW_ON_ERROR));
    }), ['token' => 'test-token', 'account_id' => 'account-id']);
    $provider->updateRecord('zone-a', 'example.com', ['id' => 'rec-1'], ['name' => 'www', 'type' => 'A', 'value' => '192.0.2.1', 'ttl' => 300]);

    expect($sent)->toBe(['PATCH', 'https://api.cloudflare.com/client/v4/zones/zone-a/dns_records/rec-1', ['type' => 'A', 'name' => 'www.example.com', 'ttl' => 300, 'content' => '192.0.2.1']]);
});

test('Servicednshosting DigitalOcean keeps a record named 0 off the zone apex', function (): void {
    $name = null;
    $provider = new DigitalOcean(new MockHttpClient(static function (string $method, string $url, array $options) use (&$name): MockResponse {
        $name = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR)['name'];

        return new MockResponse(json_encode(['domain_record' => ['id' => 1]], JSON_THROW_ON_ERROR), ['http_code' => 201]);
    }), ['token' => 'test-token']);
    $provider->addRecord('example.com', 'example.com', ['name' => '0', 'type' => 'A', 'value' => '192.0.2.1', 'ttl' => 3600]);

    expect($name)->toBe('0');
});

test('Servicednshosting PowerDNS refuses a zone under a zone already on the server', function (): void {
    $urls = [];
    $http = new MockHttpClient(static function (string $method, string $url) use (&$urls): MockResponse {
        $urls[] = $method . ' ' . rawurldecode($url);

        return new MockResponse(json_encode(str_ends_with(rawurldecode($url), '=example.com.') ? [['name' => 'example.com.']] : [], JSON_THROW_ON_ERROR));
    });
    $provider = new PowerDNS($http, ['token' => 'test-token', 'api_url' => 'https://dns.example.org', 'soa_email' => '', 'nameservers' => ['ns.example.org']]);

    expect(fn () => $provider->createZone('a.shop.example.com'))->toThrow(InformationException::class, 'A zone above this one is already on the DNS server.')
        ->and($urls)->toBe([
            'GET https://dns.example.org/api/v1/servers/localhost/zones?zone=shop.example.com.',
            'GET https://dns.example.org/api/v1/servers/localhost/zones?zone=example.com.',
        ]);
});

test('Servicednshosting DNSSEC presents public DS records without returning key details', function (): void {
    $provider = new Desec(new MockHttpClient(new MockResponse(json_encode([
        'keys' => [['ds' => ['10123 13 2 ABCD'], 'privatekey' => 'unused']],
    ], JSON_THROW_ON_ERROR))), ['token' => 'test-token']);

    expect($provider->dnssec('example.com'))->toBe([
        'supported' => true, 'enabled' => true, 'can_toggle' => false, 'ds' => ['10123 13 2 ABCD'],
    ]);
});
