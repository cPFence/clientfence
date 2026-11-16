<?php

declare(strict_types=1);

/**
 * ClientFence (CF-139): the bulk domain search (Servicedomain guest check_bulk): one name across up to 20 TLDs,
 * one counted lookup and one CAPTCHA per search, the rest in batches by search id; and the pinned TLDs.
 */

use ClientFence\Mod\Servicedomain\Api\Guest;
use ClientFence\Mod\Servicedomain\Service;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function Tests\Helpers\container;
use function Tests\Helpers\moduleService;

function bulkTld(string $name, bool $active = true, bool $register = true): Model_Tld
{
    $tld = new Model_Tld();
    $tld->loadBean(new Tests\Helpers\DummyBean());
    $tld->tld = $name;
    $tld->active = $active;
    $tld->allow_register = $register;

    return $tld;
}

/**
 * A guest API with TLDs .t1 to .t25 on sale (price = its number), .t2 taken, an array cache and a counting rate limiter.
 *
 * @return array{0: Guest, 1: object, 2: ArrayAdapter, 3: Mockery\MockInterface}
 */
function bulkGuest(array $tlds = [], ?Closure $available = null): array
{
    $tlds = $tlds ?: array_map(fn (int $i): Model_Tld => bulkTld(".t{$i}"), range(1, 25));
    $byName = [];
    foreach ($tlds as $tld) {
        $byName[$tld->tld] = $tld;
    }
    $service = Mockery::mock(Service::class);
    $service->shouldReceive('tldFindOneByTld')->andReturnUsing(fn (string $name): ?Model_Tld => $byName[$name] ?? null);
    $service->shouldReceive('isDomainAvailable')->andReturnUsing($available ?? fn (Model_Tld $tld, string $sld): bool => $tld->tld !== '.t2');
    $service->shouldReceive('tldToApiArray')->andReturnUsing(fn (Model_Tld $tld): array => ['tld' => $tld->tld, 'price_registration' => (float) substr($tld->tld, 2)]);

    $limiter = new class {
        public array $calls = [];

        public function consumeOrThrow(string $policy, string $subject, int $tokens = 1): ClientFence\Security\RateLimitResult
        {
            $this->calls[] = $policy;

            return new ClientFence\Security\RateLimitResult($policy, false, null, null);
        }
    };
    $cache = new ArrayAdapter();
    $di = container();
    $di['rate_limiter'] = $limiter;
    $di['cache'] = $cache;
    $api = new Guest();
    $api->setDi($di);
    $api->setService($service);

    return [$api, $limiter, $cache, $service];
}

/** @return list<string> */
function bulkNames(int $count): array
{
    return array_map(fn (int $i): string => ".t{$i}", range(1, $count));
}

test('a bulk search checks every TLD in batches with its price and counts one lookup', function (): void {
    [$api, $limiter] = bulkGuest();

    $answer = $api->check_bulk(['sld' => 'example', 'tlds' => bulkNames(12)]);
    $results = $answer['results'];
    expect($results)->toHaveCount(5)
        ->and($answer['search'])->toMatch('/^[a-f0-9]{32}$/');
    while ($answer['search']) {
        $answer = $api->check_bulk(['search' => $answer['search']]);
        $results = [...$results, ...$answer['results']];
    }

    expect(array_column($results, 'domain'))->toBe(array_map(fn (string $t): string => 'example' . $t, bulkNames(12)))
        ->and(array_column($results, 'available'))->toBe([true, false, true, true, true, true, true, true, true, true, true, true])
        ->and(array_column($results, 'price_registration'))->toBe(array_map('floatval', range(1, 12)))
        ->and(array_count_values($limiter->calls))->toBe(['domain_lookup_ip' => 1, 'domain_lookup_batch_ip' => 2]);
});

test('a bulk search takes at most 20 TLDs', function (): void {
    [$api, $limiter] = bulkGuest();

    expect(fn () => $api->check_bulk(['sld' => 'example', 'tlds' => bulkNames(21)]))
        ->toThrow(ClientFence\InformationException::class, 'Choose up to 20 extensions.');

    $answer = $api->check_bulk(['sld' => 'example', 'tlds' => bulkNames(20)]);
    $calls = 1;
    while ($answer['search']) {
        $answer = $api->check_bulk(['search' => $answer['search']]);
        ++$calls;
    }
    expect($calls)->toBe(4);
});

test('a bulk search refuses a bad name or TLD list', function (array $data, string $message): void {
    [$api] = bulkGuest([bulkTld('.com'), bulkTld('.off', false), bulkTld('.transfer', true, false)]);

    expect(fn () => $api->check_bulk($data))->toThrow(ClientFence\InformationException::class, $message);
})->with([
    'no TLDs' => [['sld' => 'example', 'tlds' => []], 'TLD is missing'],
    'TLDs as a string' => [['sld' => 'example', 'tlds' => '.com'], 'TLD is missing'],
    'a TLD that is not a string' => [['sld' => 'example', 'tlds' => ['.com', ['.net']]], 'TLD is missing'],
    'unknown TLD' => [['sld' => 'example', 'tlds' => ['.com', '.nope']], 'TLD is not configured'],
    'inactive TLD' => [['sld' => 'example', 'tlds' => ['.off']], 'TLD is not active'],
    'TLD not for registration' => [['sld' => 'example', 'tlds' => ['.transfer']], 'TLD is not active'],
    'invalid name' => [['sld' => 'ex ample', 'tlds' => ['.com']], 'Domain ex ample is invalid'],
    'name as a list' => [['sld' => ['example'], 'tlds' => ['.com']], 'is invalid'],
]);

test('repeated TLDs are checked once', function (): void {
    [$api] = bulkGuest();

    $answer = $api->check_bulk(['sld' => 'example', 'tlds' => ['.t1', '.t3', '.t1']]);

    expect(array_column($answer['results'], 'domain'))->toBe(['example.t1', 'example.t3'])
        ->and($answer['search'])->toBeNull();
});

test('a follow-up checks only the next TLDs of its own search, once', function (): void {
    [$api, , $cache] = bulkGuest();

    $first = $api->check_bulk(['sld' => 'example', 'tlds' => bulkNames(7)]);
    // Another name or other TLDs sent with the id change nothing: the search keeps its own.
    $next = $api->check_bulk(['search' => $first['search'], 'sld' => 'other', 'tlds' => ['.t20', '.t21']]);

    expect(array_column($next['results'], 'domain'))->toBe(['example.t6', 'example.t7'])
        ->and($next['search'])->toBeNull()
        ->and(fn () => $api->check_bulk(['search' => $first['search']]))->toThrow(ClientFence\InformationException::class, 'This search has expired. Search again.');

    $expired = $api->check_bulk(['sld' => 'example', 'tlds' => bulkNames(7)]);
    $cache->clear();
    expect(fn () => $api->check_bulk(['search' => $expired['search']]))->toThrow(ClientFence\InformationException::class, 'This search has expired. Search again.')
        ->and(fn () => $api->check_bulk(['search' => str_repeat('a', 32)]))->toThrow(ClientFence\InformationException::class, 'This search has expired. Search again.')
        ->and(fn () => $api->check_bulk(['search' => '../../x']))->toThrow(ClientFence\InformationException::class, 'This search has expired. Search again.');
});

test('a registrar error shows the TLD as unknown, not the whole search as failed', function (): void {
    [$api] = bulkGuest([], fn (Model_Tld $tld): bool => $tld->tld === '.t1' ? throw new Exception('timeout') : true);

    $answer = $api->check_bulk(['sld' => 'example', 'tlds' => ['.t1', '.t3']]);

    expect(array_column($answer['results'], 'available'))->toBe([null, true]);
});

test('a bulk search answers the guest CAPTCHA once, not on its follow-ups', function (): void {
    [$api] = bulkGuest();
    $di = $api->getDi();
    $extension = Mockery::mock();
    $extension->shouldReceive('isExtensionActive')->with('mod', 'antispam')->andReturn(true);
    $antispam = Mockery::mock(ClientFence\Mod\Antispam\Service::class);
    $antispam->shouldReceive('checkCaptcha')->once();
    $session = Mockery::mock(ClientFence\Session::class);
    $session->shouldReceive('get')->with('domain_lookup_captcha_until')->andReturn(0);
    $session->shouldReceive('set')->once();
    $di['mod_service'] = $di->protect(moduleService(['extension' => $extension, 'antispam' => $antispam]));
    $di['mod_config'] = $di->protect(fn (string $name): array => $name === 'Antispam' ? ['captcha_enabled' => '1'] : []);
    $di['session'] = $session;

    $answer = $api->check_bulk(['sld' => 'example', 'tlds' => bulkNames(12)]);
    while ($answer['search']) {
        $answer = $api->check_bulk(['search' => $answer['search']]);
    }
    expect($answer['results'])->toHaveCount(2);
});

test('staff pin up to 8 TLDs on sale, saved in their order without repeats', function (): void {
    $tlds = ['.com' => bulkTld('.com'), '.net' => bulkTld('.net'), '.off' => bulkTld('.off', false), '.transfer' => bulkTld('.transfer', true, false)];
    foreach (range(1, 8) as $i) {
        $tlds[".t{$i}"] = bulkTld(".t{$i}");
    }
    $db = Mockery::mock(ClientFence\Database::class);
    $db->shouldReceive('findOne')->andReturnUsing(fn (string $model, string $sql, array $params): ?Model_Tld => $tlds[$params[0]] ?? null);
    $system = Mockery::mock(ClientFence\Mod\System\Service::class);
    $saved = null;
    $system->shouldReceive('setParamValue')->andReturnUsing(function (string $param, string $value) use (&$saved): bool {
        $saved = [$param, $value];

        return true;
    });
    $di = container();
    $di['db'] = $db;
    $di['mod_service'] = $di->protect(moduleService(['system' => $system]));
    $service = new Service();
    $service->setDi($di);

    expect($service->pinnedTldsUpdate('net, com net'))->toBeTrue();
    expect($saved)->toBe(['servicedomain_pinned_tlds', '[".net",".com"]']);

    expect($service->pinnedTldsUpdate(['.com', '.t1', '.t2', '.t3', '.t4', '.t5', '.t6', '.t7']))->toBeTrue();
    expect($service->pinnedTldsUpdate([]))->toBeTrue();
    expect($saved[1])->toBe('[]');

    expect(fn () => $service->pinnedTldsUpdate(['.com', '.nope']))->toThrow(ClientFence\InformationException::class, 'TLD not found');
    expect(fn () => $service->pinnedTldsUpdate(['.off']))->toThrow(ClientFence\InformationException::class, 'Domain cannot be registered');
    expect(fn () => $service->pinnedTldsUpdate(['.transfer']))->toThrow(ClientFence\InformationException::class, 'Domain cannot be registered');
    expect(fn () => $service->pinnedTldsUpdate(['.com', '.t1', '.t2', '.t3', '.t4', '.t5', '.t6', '.t7', '.t8']))->toThrow(ClientFence\InformationException::class, 'Pin up to 8 TLDs.');
    expect($saved[1])->toBe('[]');
});

test('the pinned list drops a TLD taken off sale and never shows more than 8', function (): void {
    $system = Mockery::mock(ClientFence\Mod\System\Service::class);
    $system->shouldReceive('getParamValue')->with('servicedomain_pinned_tlds', '[]')->andReturn('[".net",".gone",".com",".t1",".t2",".t3",".t4",".t5",".t6",".t7"]');
    $db = Mockery::mock(ClientFence\Database::class);
    $db->shouldReceive('getAssoc')->andReturn([1 => '.com', 2 => '.net', 3 => '.t1', 4 => '.t2', 5 => '.t3', 6 => '.t4', 7 => '.t5', 8 => '.t6', 9 => '.t7']);
    $di = container();
    $di['db'] = $db;
    $di['mod_service'] = $di->protect(moduleService(['system' => $system]));
    $service = new Service();
    $service->setDi($di);

    expect($service->pinnedTlds())->toBe(['.net', '.com', '.t1', '.t2', '.t3', '.t4', '.t5', '.t6']);
});

test('the pinned TLDs are saved only with the TLD permission', function (): void {
    $staff = Mockery::mock(ClientFence\Mod\Staff\Service::class);
    $staff->shouldReceive('checkPermissionsAndThrowException')->with('servicedomain', 'manage_tlds', null, null)->once()->andThrow(new ClientFence\InformationException('You do not have permission to perform this action', [], 403));
    $service = Mockery::mock(Service::class);
    $service->shouldReceive('pinnedTldsUpdate')->never();
    $di = container();
    $di['mod_service'] = $di->protect(moduleService(['staff' => $staff]));
    $api = new ClientFence\Mod\Servicedomain\Api\Admin();
    $api->setDi($di);
    $api->setService($service);

    expect(fn () => $api->tld_pinned_update(['tlds' => '.com']))->toThrow(ClientFence\InformationException::class);
});
