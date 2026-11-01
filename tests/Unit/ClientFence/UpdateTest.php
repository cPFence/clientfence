<?php

declare(strict_types=1);

use ClientFence\InformationException;
use ClientFence\Update;
use ClientFence\UpdateFinalization;
use ClientFence\Version;
use PhpZip\ZipFile;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

function createUpdateTestArchive(string $content): string
{
    $archive = tempnam(sys_get_temp_dir(), 'clientfence-update-');
    if ($archive === false) {
        throw new RuntimeException('Unable to create a temporary update archive.');
    }

    file_put_contents($archive, $content);

    return $archive;
}

// ClientFence: the ClientFence release feed (Mod\Clientfence\Updates) instead of FOSSBilling's server.
function updateTestDi(string $feedVersion, array &$requests): Pimple\Container
{
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE mod_clientfence_state (name TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $system = new class {
        public array $params = [];

        public function getParamValue(string $param, $default = null)
        {
            return $this->params[$param] ?? $default;
        }
    };
    $di = new Pimple\Container();
    $di['em'] = $em;
    $di['mod_service'] = $di->protect(fn (string $name) => $system);
    $di['http_client'] = new MockHttpClient(function (string $method, string $url) use (&$requests, $feedVersion): MockResponse {
        $requests[] = $url;

        return new MockResponse(json_encode([
            'version' => $feedVersion,
            'download_url' => 'https://clientfence.com/releases/clientfence-' . $feedVersion . '.zip',
            'sha256' => str_repeat('ab', 32),
            'minimum_php_version' => '8.3',
            'notes' => 'Notes',
        ]));
    });

    return $di;
}

test('getLatestVersionInfo reads the ClientFence release feed', function (): void {
    $requests = [];
    $update = new Update();
    $update->setDi(updateTestDi('99.0.0', $requests));

    $info = $update->getLatestVersionInfo('release', true);

    expect($info['version'])->toBe('99.0.0')
        ->and($info['download_url'])->toBe('https://clientfence.com/releases/clientfence-99.0.0.zip')
        ->and($info['digest'])->toBe(str_repeat('ab', 32))
        ->and($info['minimum_php_version'])->toBe('8.3')
        ->and($info['check_enabled'])->toBeTrue()
        ->and($requests)->toBe([ClientFence\Mod\Clientfence\Updates::FEED_URL])
        // The saved check answers without another request.
        ->and($update->isUpdateAvailable())->toBeTrue()
        ->and($update->getLatestVersion())->toBe('99.0.0')
        ->and($requests)->toHaveCount(1);
});

test('an older release in the feed is no update', function (): void {
    $requests = [];
    $update = new Update();
    $update->setDi(updateTestDi('0.1.0', $requests));

    $info = $update->getLatestVersionInfo('release', true);

    expect($info['version'])->toBe(Version::CLIENTFENCE)
        ->and($info['download_url'])->toBeNull()
        ->and($update->isUpdateAvailable())->toBeFalse();
});

test('validates a downloaded archive against a SHA-256 digest', function (): void {
    $content = 'valid update archive';
    $archive = createUpdateTestArchive($content);

    try {
        (new ReflectionMethod(Update::class, 'validateDownloadedArchive'))->invoke(
            new Update(),
            $archive,
            ['digest' => 'sha256:' . hash('sha256', $content)],
        );

        expect((new Filesystem())->exists($archive))->toBeTrue();
    } finally {
        (new Filesystem())->remove($archive);
    }
});

test('rejects and removes a downloaded archive with the wrong digest', function (): void {
    $archive = createUpdateTestArchive('tampered update archive');

    expect(fn (): mixed => (new ReflectionMethod(Update::class, 'validateDownloadedArchive'))->invoke(
        new Update(),
        $archive,
        ['digest' => hash('sha256', 'different archive')],
    ))->toThrow(InformationException::class, 'integrity verification');

    expect((new Filesystem())->exists($archive))->toBeFalse();
});

test('rejects update metadata with an invalid digest', function (): void {
    $archive = createUpdateTestArchive('update archive');

    expect(fn (): mixed => (new ReflectionMethod(Update::class, 'validateDownloadedArchive'))->invoke(
        new Update(),
        $archive,
        ['digest' => 'not-a-sha256-digest'],
    ))->toThrow(InformationException::class, 'invalid SHA-256 digest');

    expect((new Filesystem())->exists($archive))->toBeFalse();
});

test('rejects update metadata without a feed digest', function (): void {
    $archive = createUpdateTestArchive('release archive');

    expect(fn (): mixed => (new ReflectionMethod(Update::class, 'validateDownloadedArchive'))->invoke(
        new Update(),
        $archive,
        [],
    ))->toThrow(InformationException::class, 'release feed did not provide a SHA-256 digest');

    expect((new Filesystem())->exists($archive))->toBeFalse();
});

test('isSafeArchiveEntry accepts a normal relative entry', function (): void {
    expect(Update::isSafeArchiveEntry('src/library/ClientFence/Update.php'))->toBeTrue();
});

test('isSafeArchiveEntry rejects a forward-slash traversal segment', function (): void {
    expect(Update::isSafeArchiveEntry('../../etc/passwd'))->toBeFalse();
});

test('isSafeArchiveEntry rejects a backslash traversal segment', function (): void {
    expect(Update::isSafeArchiveEntry('..\\..\\poc.php'))->toBeFalse();
});

test('isSafeArchiveEntry rejects a mixed-separator traversal segment', function (): void {
    expect(Update::isSafeArchiveEntry('src\\..\\..\\poc.php'))->toBeFalse();
});

test('isSafeArchiveEntry rejects a Unix absolute path', function (): void {
    expect(Update::isSafeArchiveEntry('/etc/passwd'))->toBeFalse();
});

test('isSafeArchiveEntry rejects a Windows drive-letter absolute path', function (): void {
    expect(Update::isSafeArchiveEntry('C:\\Windows\\System32\\evil.dll'))->toBeFalse();
});

test('isSafeArchiveEntry rejects a trailing-space-and-dot segment Windows normalizes to a parent traversal', function (): void {
    expect(Update::isSafeArchiveEntry('.. .\\outside.php'))->toBeFalse();
});

test('isSafeArchiveEntry rejects an all-dots segment', function (): void {
    expect(Update::isSafeArchiveEntry('.../outside.php'))->toBeFalse();
});

test('isSafeArchiveEntry accepts a filename that legitimately contains spaces and periods', function (): void {
    expect(Update::isSafeArchiveEntry('src/data/my file v1.2.3.txt'))->toBeTrue();
});

test('performUpdate refuses a concurrent update before checking finalization', function (): void {
    $requests = [];
    $di = updateTestDi('0.1.0', $requests);
    $finalization = Mockery::mock(UpdateFinalization::class);
    $finalization->shouldNotReceive('isRequired');
    $di['update_finalization'] = $finalization;
    $update = new Update();
    $update->setDi($di);
    $lock = fopen(PATH_ROOT . '/data/update.flock', 'c');
    try {
        expect(flock($lock, LOCK_EX | LOCK_NB))->toBeTrue();

        expect(fn () => $update->performUpdate())->toThrow(InformationException::class, 'Another update appears to already be in progress.');
    } finally {
        fclose($lock);
    }
});

// ClientFence: an install is updated by installing the new ClientFence release, never by a download.
test('performUpdate refuses when the feed has no newer release, before touching any file', function (): void {
    $requests = [];
    $di = updateTestDi('0.1.0', $requests);
    $finalization = Mockery::mock(UpdateFinalization::class);
    $finalization->shouldReceive('isRequired')->andReturnFalse();
    $finalization->shouldNotReceive('createPendingState');
    $readiness = new class {
        public function check(): array
        {
            return ['can_update' => true, 'issues' => []];
        }
    };
    $di['update_finalization'] = $finalization;
    $di['update_readiness'] = $readiness;
    $di['logger'] = new class {
        public function setChannel(string $channel): static
        {
            return $this;
        }

        public function __call(string $name, array $args): void
        {
        }
    };
    $update = new Update();
    $update->setDi($di);

    expect(fn () => $update->performUpdate())->toThrow(InformationException::class, 'You have the latest version of ClientFence.');
});

test('opcode cache reset is best-effort and never throws', function (): void {
    $method = new ReflectionMethod(Update::class, 'invalidateOpcodeCache');
    $method->invoke(new Update());

    expect(true)->toBeTrue();
});

test('a failed unpack leaves no error handler of the zip library behind', function (): void {
    $dir = sys_get_temp_dir() . '/cf-extract-' . bin2hex(random_bytes(4));
    mkdir($dir . '/taken', 0o755, true);
    $archive = $dir . '/release.zip';
    $zip = new ZipFile();
    // A file where a folder already is: PhpZip cannot open it for writing.
    $zip->addFromString('taken', 'x');
    $zip->saveAsFile($archive);
    $zip->close();

    $zip = new ZipFile();
    $zip->openFile($archive);
    try {
        expect(fn () => ClientFence\Mod\Clientfence\UpdateBackup::extract($zip, $dir))->toThrow(PhpZip\Exception\ZipException::class);
        // PhpZip's handler would turn this silenced warning into its exception.
        @trigger_error('a later warning', E_USER_WARNING);
        expect(true)->toBeTrue();
    } finally {
        $zip->close();
        (new Filesystem())->remove($dir);
    }
});
