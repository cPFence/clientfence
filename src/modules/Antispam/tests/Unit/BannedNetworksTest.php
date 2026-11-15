<?php

declare(strict_types=1);

/**
 * ClientFence: banned emails and phone numbers (CF-117, Banned.php) and hosting networks (CF-119, Networks.php).
 */

use ClientFence\InformationException;
use ClientFence\Mod\Antispam\Banned;
use ClientFence\Mod\Antispam\Networks;

use function Tests\Helpers\container;

test('the email list bans addresses, domains and subdomains', function (string $email, bool $banned): void {
    $entries = Banned::entries("# comment\nBad@Example.com\nspam.test\n*.junk.test\n");

    expect(Banned::emailBanned($entries, $email))->toBe($banned);
})->with([
    ['bad@example.com', true],
    ['BAD@example.com', true],
    ['good@example.com', false],
    ['anyone@spam.test', true],
    ['anyone@sub.spam.test', false],
    ['anyone@mail.junk.test', true],
    ['anyone@junk.test', false],
    ['anyone@notjunk.test', false],
]);

test('the phone list compares digits with the country code', function (string $countryCode, string $phone, bool $banned): void {
    expect(Banned::phoneBanned(Banned::entries("+1 555-010-0199\n"), $countryCode, $phone))->toBe($banned);
})->with([
    ['1', '555 010 0199', true],
    ['1', '(555) 010-0199', true],
    ['', '15550100199', true],
    ['44', '555 010 0199', false],
    ['1', '555 010 0198', false],
]);

test('a profile change is checked only for what changes, checkout for the account', function (): void {
    $di = container();
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['mod_config'] = $di->protect(fn (): array => ['banned_emails' => 'banned.test', 'banned_phones' => '']);
    $dbal = Mockery::mock(Doctrine\DBAL\Connection::class);
    $dbal->shouldReceive('fetchAssociative')->with('SELECT email, phone_cc, phone FROM client WHERE id = ?', [7])->andReturn(['email' => 'old@banned.test', 'phone_cc' => '1', 'phone' => '5550100']);
    $di['dbal'] = $dbal;

    // The client keeps an address banned after they signed up: their other changes still save.
    Banned::refuse($di, ['email' => 'old@banned.test', 'first_name' => 'New'], 7);
    expect(fn () => Banned::refuse($di, ['email' => 'new@banned.test'], 7))->toThrow(InformationException::class, Banned::MESSAGE)
        ->and(fn () => Banned::refuse($di, null, 7))->toThrow(InformationException::class)
        ->and(fn () => Banned::refuse($di, ['email' => 'someone@banned.test']))->toThrow(InformationException::class);
});

test('empty lists read nothing from the database', function (): void {
    $di = container();
    $di['mod_config'] = $di->protect(fn (): array => []);
    $di['dbal'] = Mockery::mock(Doctrine\DBAL\Connection::class)->shouldNotReceive('fetchAssociative')->getMock();

    Banned::refuse($di, null, 7);
    expect(true)->toBeTrue();
});

test('the bundled network list reads X4BNet lines and bare numbers', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'asn');
    file_put_contents($file, "# Source: example\nAS24940 # HETZNER-AS, DE\n  14061\nas16276 # OVH\n# AS1 commented\n");

    expect(array_keys(Networks::bundled($file)))->toBe([24940, 14061, 16276]);
    unlink($file);
});

test('staff network numbers take AS prefixes and refuse anything else', function (): void {
    expect(Networks::numbers("AS24940, 14061\nas24940"))->toBe([24940, 14061])
        ->and(fn () => Networks::numbers('HETZNER'))->toThrow(InformationException::class);
});

test('an address is a hosting network by its ASN, unless the never list has it', function (): void {
    // 78.46.0.1 is in AS24940 (Hetzner) in the bundled GeoIP ASN database.
    expect(Networks::match(['mode' => 'hold', 'always' => [24940], 'never' => []], '78.46.0.1'))->toBe('HETZNER-AS (AS24940)')
        ->and(Networks::match(['mode' => 'hold', 'always' => [24940], 'never' => [24940]], '78.46.0.1'))->toBeNull()
        // A private address has no ASN record: not known as hosting.
        ->and(Networks::match(['mode' => 'hold', 'always' => [24940], 'never' => []], '10.0.0.1'))->toBeNull();
});
