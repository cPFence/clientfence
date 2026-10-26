<?php

declare(strict_types=1);

/**
 * ClientFence: release notices (Mod\Clientfence\Updates): the feed format, the daily check and the
 * one email per release, on an in-memory SQLite state table with fake services.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Clientfence\Updates;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

const UPDATES_FEED = 'https://clientfence.com/releases/latest.json';
const UPDATES_BETA_FEED = 'https://clientfence.com/releases/beta.json';

/** @param array<string, mixed> $override */
function updatesFeed(array $override = []): string
{
    return (string) json_encode($override + [
        'version' => '99.1.0',
        'release_date' => '2026-11-01',
        'minimum_php_version' => '8.3',
        'download_url' => 'https://clientfence.com/releases/clientfence-99.1.0.zip',
        'sha256' => 'sha256:' . str_repeat('AB', 32),
        'notes' => '## Fixed',
    ]);
}

final class UpdatesTestWorld
{
    public array $requests = [];
    public array $mails = [];
    public array $params = [];
    public string|Closure $feed;
    /** The beta feed; null answers 404. */
    public string|Closure|null $beta = null;
    public Pimple\Container $di;

    public function __construct()
    {
        $this->feed = updatesFeed();
        $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE mod_clientfence_state (name TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at TEXT NOT NULL)');
        // #1 may update the system, #2 may not, #3 is inactive, #4 is the cron's own account.
        $db->executeStatement('CREATE TABLE admin (id INTEGER PRIMARY KEY, status TEXT, system_name TEXT)');
        $db->executeStatement("INSERT INTO admin VALUES (1, 'active', NULL), (2, 'active', NULL), (3, 'inactive', NULL), (4, 'active', 'cron')");
        $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
        $em->shouldReceive('getConnection')->andReturn($db);
        $world = $this;
        $system = new class($world) {
            public function __construct(private readonly UpdatesTestWorld $world)
            {
            }

            public function getParamValue(string $param, $default = null)
            {
                return $this->world->params[$param] ?? $default;
            }

            public function setParamValue($param, $value): bool
            {
                $this->world->params[$param] = $value;

                return true;
            }
        };
        $email = new class($world) {
            public function __construct(private readonly UpdatesTestWorld $world)
            {
            }

            public function sendTemplate(array $data): bool
            {
                $this->world->mails[] = $data;

                return true;
            }
        };
        $staff = new class {
            public function hasPermission(object $member, string $module, string $key): bool
            {
                return $member->id === 1 && $module === 'system' && $key === 'system_update';
            }
        };
        $this->di = new Pimple\Container();
        $this->di['em'] = $em;
        $this->di['db'] = new class {
            public function getExistingModelById(string $type, int $id): object
            {
                return (object) ['id' => $id];
            }
        };
        $this->di['mod_service'] = $this->di->protect(fn (string $name) => match (strtolower($name)) {
            'email' => $email,
            'staff' => $staff,
            default => $system,
        });
        $this->di['url'] = new class {
            public function adminLink(string $path): string
            {
                return 'https://example.test/admin/' . $path;
            }
        };
        $this->di['http_client'] = new MockHttpClient(function (string $method, string $url) use ($world): MockResponse {
            $world->requests[] = $url;
            $feed = str_ends_with($url, '/beta.json') ? ($world->beta ?? new MockResponse('', ['http_code' => 404])) : $world->feed;
            if ($feed instanceof MockResponse) {
                return $feed;
            }

            return $feed instanceof Closure ? $feed() : new MockResponse($feed);
        });
    }
}

test('a valid feed is read and normalized', function (): void {
    $release = Updates::parse(updatesFeed(['minimum_php_version' => 'soon', 'release_date' => 'tomorrow']), UPDATES_FEED);

    expect($release)->toBe([
        'version' => '99.1.0',
        'release_date' => null,
        'minimum_php_version' => 'unknown',
        'download_url' => 'https://clientfence.com/releases/clientfence-99.1.0.zip',
        'sha256' => str_repeat('ab', 32),
        'notes' => '## Fixed',
    ]);
});

test('a feed that is not trustworthy is refused', function (string $json, string $message): void {
    expect(fn () => Updates::parse($json, UPDATES_FEED))->toThrow(InformationException::class, $message);
})->with([
    'not JSON' => ['<html>', 'not valid JSON'],
    'no version' => [updatesFeed(['version' => 'latest']), 'no valid version'],
    'a version after other text' => [updatesFeed(['version' => '../1.0.0']), 'no valid version'],
    'another host' => [updatesFeed(['download_url' => 'https://evil.test/releases/clientfence.zip']), 'outside its own folder'],
    'plain http' => [updatesFeed(['download_url' => 'http://clientfence.com/releases/clientfence.zip']), 'outside its own folder'],
    'a subfolder' => [updatesFeed(['download_url' => 'https://clientfence.com/releases/../clientfence.zip']), 'outside its own folder'],
    'a lookalike host' => [updatesFeed(['download_url' => 'https://clientfence.com.evil.test/releases/x.zip']), 'outside its own folder'],
    'not a zip' => [updatesFeed(['download_url' => 'https://clientfence.com/releases/clientfence.php']), 'outside its own folder'],
    'no checksum' => [updatesFeed(['sha256' => '']), 'no valid SHA-256'],
    'a short checksum' => [updatesFeed(['sha256' => 'abc']), 'no valid SHA-256'],
]);

test('only a later version counts as new', function (): void {
    expect(Updates::isNewer('99.0.0'))->toBeTrue()
        ->and(Updates::isNewer(ClientFence\Version::CLIENTFENCE))->toBeFalse()
        ->and(Updates::isNewer('0.1.0'))->toBeFalse()
        ->and(Updates::isNewer(''))->toBeFalse()
        ->and(Updates::isNewer(null))->toBeFalse()
        // A release after a development build of the same version.
        ->and(version_compare('1.0.0', '1.0.0-dev', '>'))->toBeTrue();
});

test('the feed is checked once a day, or at once when staff ask', function (): void {
    $world = new UpdatesTestWorld();

    $first = Updates::check($world->di);
    $again = Updates::check($world->di);
    $forced = Updates::check($world->di, true);

    expect($world->requests)->toBe([UPDATES_FEED, UPDATES_FEED])
        ->and($first['release']['version'])->toBe('99.1.0')
        ->and($again['checked_at'])->toBe($first['checked_at'])
        ->and($forced['error'])->toBeNull();
});

test('with the daily check off nothing is fetched unless staff ask', function (): void {
    $world = new UpdatesTestWorld();
    Updates::setEnabled($world->di, false);

    Updates::check($world->di);
    Updates::runIfDue($world->di);
    expect($world->requests)->toBe([])
        ->and(Updates::enabled($world->di))->toBeFalse();

    Updates::check($world->di, true);
    expect($world->requests)->toHaveCount(1)
        ->and(Updates::available($world->di)['version'])->toBe('99.1.0');
});

test('a failed check keeps the release found before and records the error', function (): void {
    $world = new UpdatesTestWorld();
    Updates::check($world->di);
    $world->feed = fn () => new MockResponse('down', ['http_code' => 503]);

    $last = Updates::check($world->di, true);

    expect($last['release']['version'])->toBe('99.1.0')
        ->and($last['error'])->toContain('HTTP 503');
});

test('the staff who may update are emailed once about each new release', function (): void {
    $world = new UpdatesTestWorld();

    Updates::runIfDue($world->di);
    Updates::check($world->di, true);
    Updates::runIfDue($world->di);

    expect($world->mails)->toHaveCount(1)
        ->and($world->mails[0])->toMatchArray(['code' => 'mod_staff_clientfence_update', 'to_admin' => 1, 'update_url' => 'https://example.test/admin/system/update', 'send_now' => true])
        ->and($world->mails[0]['release']['version'])->toBe('99.1.0');

    $world->feed = updatesFeed(['version' => '99.2.0', 'download_url' => 'https://clientfence.com/releases/clientfence-99.2.0.zip']);
    Updates::check($world->di, true);
    Updates::runIfDue($world->di);

    expect($world->mails)->toHaveCount(2)
        ->and($world->mails[1]['release']['version'])->toBe('99.2.0');
});

test('an older release is no notice', function (): void {
    $world = new UpdatesTestWorld();
    $world->feed = updatesFeed(['version' => '0.1.0']);

    Updates::runIfDue($world->di);

    expect($world->mails)->toBe([])
        ->and(Updates::available($world->di))->toBeNull()
        ->and(Updates::last($world->di)['release']['version'])->toBe('0.1.0');
});

test('archives are trusted only from the feed folder', function (): void {
    expect(Updates::trustedPrefix(UPDATES_FEED))->toBe('https://clientfence.com/releases/')
        ->and(Updates::trustedPrefix())->toBe('https://clientfence.com/releases/');
});

/** A feed entry for $version, its archive next to the feeds. */
function updatesRelease(string $version): string
{
    return updatesFeed(['version' => $version, 'download_url' => "https://clientfence.com/releases/clientfence-$version.zip"]);
}

test('the stable channel never reads the beta feed', function (): void {
    $world = new UpdatesTestWorld();
    $world->beta = updatesRelease('99.2.0');

    $last = Updates::check($world->di, true);

    expect(Updates::channel($world->di))->toBe('stable')
        ->and($world->requests)->toBe([UPDATES_FEED])
        ->and($last['release'])->toMatchArray(['version' => '99.1.0', 'channel' => 'stable']);
});

test('the beta channel takes the newer of the beta and stable feeds', function (string $beta, string $version, string $channel): void {
    $world = new UpdatesTestWorld();
    $world->beta = updatesRelease($beta);
    Updates::setChannel($world->di, 'beta');

    $last = Updates::check($world->di);

    expect($world->requests)->toBe([UPDATES_FEED, UPDATES_BETA_FEED])
        ->and($last['error'])->toBeNull()
        ->and($last['release'])->toMatchArray(['version' => $version, 'channel' => $channel]);
})->with([
    'a newer beta' => ['99.2.0', '99.2.0', 'beta'],
    // The beta was promoted, or a stable release came out after it.
    'the same release' => ['99.1.0', '99.1.0', 'stable'],
    'an older beta' => ['99.0.0', '99.1.0', 'stable'],
]);

test('without a beta feed the beta channel gets stable releases', function (): void {
    $world = new UpdatesTestWorld();
    Updates::setChannel($world->di, 'beta');

    $last = Updates::check($world->di);

    expect($last['error'])->toBeNull()
        ->and($last['release'])->toMatchArray(['version' => '99.1.0', 'channel' => 'stable']);
});

test('a beta feed that fails otherwise is an error, and the archive must sit next to the feeds', function (): void {
    $world = new UpdatesTestWorld();
    Updates::setChannel($world->di, 'beta');
    $world->beta = fn () => new MockResponse('down', ['http_code' => 503]);

    expect(Updates::check($world->di)['error'])->toContain('HTTP 503');

    $world->beta = updatesFeed(['version' => '99.2.0', 'download_url' => 'https://clientfence.com/beta/clientfence-99.2.0.zip']);
    expect(Updates::check($world->di, true)['error'])->toContain('outside its own folder');
});

test('switching channels forgets the release found on the other one', function (): void {
    $world = new UpdatesTestWorld();
    $world->beta = updatesRelease('99.2.0');
    Updates::setChannel($world->di, 'beta');
    Updates::check($world->di);
    expect(Updates::available($world->di)['version'])->toBe('99.2.0');

    Updates::setChannel($world->di, 'stable');
    expect(Updates::available($world->di))->toBeNull()
        ->and(Updates::last($world->di)['checked_at'])->toBeNull()
        ->and(Updates::check($world->di)['release']['version'])->toBe('99.1.0')
        ->and(fn () => Updates::setChannel($world->di, 'nightly'))->toThrow(InformationException::class);
});
