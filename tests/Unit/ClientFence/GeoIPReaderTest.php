<?php

declare(strict_types=1);

// ClientFence: a downloaded GeoIP database replaces the old one only when it is usable (the staff
// country rule depends on it); otherwise the old one stays and is tried again a day later.

use ClientFence\GeoIP\Reader;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

function geoipTestReader(MockResponse $response): Reader
{
    $di = new Pimple\Container();
    $di['http_client'] = new MockHttpClient($response);
    $reader = (new ReflectionClass(Reader::class))->newInstanceWithoutConstructor();
    $reader->setDi($di);

    return $reader;
}

function geoipTestOldFile(): string
{
    $file = tempnam(sys_get_temp_dir(), 'clientfence-geoip-');
    copy(Reader::getCountryDatabase(), $file);
    touch($file, time() - 8 * 86400);

    return $file;
}

test('the bundled databases pass the check, each only as its own kind', function (): void {
    Reader::checkDatabase(Reader::getCountryDatabase(), 'country');
    Reader::checkDatabase(Reader::getAsnDatabase(), 'asn');

    expect(fn () => Reader::checkDatabase(Reader::getCountryDatabase(), 'asn'))->toThrow(RuntimeException::class, 'no asn')
        ->and(fn () => Reader::checkDatabase(Reader::getAsnDatabase(), 'country'))->toThrow(RuntimeException::class, 'no country');
});

test('a small or broken file is not a database', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'clientfence-geoip-');
    file_put_contents($file, '<html>Not Found</html>');
    expect(fn () => Reader::checkDatabase($file, 'country'))->toThrow(RuntimeException::class, 'too small');

    file_put_contents($file, str_repeat('x', 2_000_000));
    expect(fn () => Reader::checkDatabase($file, 'country'))->toThrow(RuntimeException::class, 'cannot be read');
    unlink($file);
});

test('a broken download keeps the old database and tries again in a day', function (): void {
    $file = geoipTestOldFile();
    $before = hash_file('sha256', $file);

    expect(fn () => geoipTestReader(new MockResponse(str_repeat('x', 2_000_000)))->downloadDb($file, 'https://example.test/db.mmdb', 'country'))
        ->toThrow(Exception::class, 'was not used');

    clearstatcache();
    expect(hash_file('sha256', $file))->toBe($before)
        ->and(file_exists($file . '.download'))->toBeFalse()
        // Due again in about a day (the age limit is 7 days).
        ->and(time() - filemtime($file))->toBeGreaterThan(6 * 86400 - 60)->toBeLessThan(6 * 86400 + 60);
    unlink($file);
});

test('a failed request keeps the old database', function (): void {
    $file = geoipTestOldFile();
    $before = hash_file('sha256', $file);

    expect(fn () => geoipTestReader(new MockResponse('', ['http_code' => 503]))->downloadDb($file, 'https://example.test/db.mmdb', 'country'))
        ->toThrow(Exception::class, '503');
    expect(hash_file('sha256', $file))->toBe($before);
    unlink($file);
});

test('a usable download replaces the old database', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'clientfence-geoip-');
    file_put_contents($file, 'old');
    $fresh = file_get_contents(Reader::getAsnDatabase());

    geoipTestReader(new MockResponse($fresh))->downloadDb($file, 'https://example.test/db.mmdb', 'asn');

    expect(hash_file('sha256', $file))->toBe(hash('sha256', $fresh))
        ->and(file_exists($file . '.download'))->toBeFalse();
    unlink($file);
});
