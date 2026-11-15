<?php

declare(strict_types=1);

/**
 * ClientFence: cloud backups (Clientfence\CloudBackups): the S3 signature, the schedule, which copies the clean-up
 * removes, the encryption and the secrets kept from the page. The uploads need a storage service: they are checked on
 * the local test site against a MinIO container and real accounts.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Clientfence\CloudBackups;
use Doctrine\DBAL\DriverManager;

function cloudBackupsContainer(array $state = []): Pimple\Container
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE mod_clientfence_state (name TEXT PRIMARY KEY, value TEXT, updated_at TEXT)');
    foreach ($state as $name => $value) {
        $db->insert('mod_clientfence_state', ['name' => $name, 'value' => json_encode($value), 'updated_at' => date('Y-m-d H:i:s')]);
    }
    $di = new Pimple\Container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;
    $crypt = Mockery::mock();
    $crypt->shouldReceive('encrypt')->andReturnUsing(fn (string $p): string => 'enc:' . strrev($p));
    $di['crypt'] = $crypt;

    return $di;
}

test('S3 requests are signed as in the AWS Signature V4 examples', function (): void {
    $time = gmmktime(0, 0, 0, 5, 24, 2013);
    $hash = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
    [$key, $secret] = ['AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY'];
    $get = CloudBackups::sign('GET', '/test.txt', [], ['host' => 'examplebucket.s3.amazonaws.com', 'range' => 'bytes=0-9', 'x-amz-content-sha256' => $hash, 'x-amz-date' => '20130524T000000Z'], $hash, $key, $secret, 'us-east-1', $time);
    $list = CloudBackups::sign('GET', '/', ['prefix' => 'J', 'max-keys' => '2'], ['host' => 'examplebucket.s3.amazonaws.com', 'x-amz-content-sha256' => $hash, 'x-amz-date' => '20130524T000000Z'], $hash, $key, $secret, 'us-east-1', $time);

    expect($get)->toBe('AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41')
        ->and($list)->toEndWith('Signature=34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7');
});

test('a copy is due once its schedule has passed, never when off', function (string $every, int $ago, bool $due): void {
    $now = time();
    expect(CloudBackups::due(date('Y-m-d H:i:s', $now - $ago), $every, $now))->toBe($due);
})->with([
    'hourly, 59 minutes ago' => ['hourly', 3540, true],
    'hourly, 50 minutes ago' => ['hourly', 3000, false],
    'daily, 23 hours ago' => ['daily', 82800, false],
    'daily, a day ago' => ['daily', 86400, true],
    'weekly, 6 days ago' => ['weekly', 6 * 86400, false],
    'never' => ['never', 30 * 86400, false],
]);

test('the first copy is due at once', function (): void {
    expect(CloudBackups::due(null, 'weekly', time()))->toBeTrue()
        ->and(CloudBackups::due(null, 'never', time()))->toBeFalse();
});

test('the clean-up keeps the newest N copies and never touches other names', function (): void {
    $names = [
        'billing.example.com_db_backup_2026-11-13_030000_0123456789abcdef.sql.gz',
        'billing.example.com_db_backup_2026-11-15_030000_0123456789abcdef.sql.gz.enc',
        'billing.example.com_db_backup_2026-11-14_030000_0123456789abcdef.sql.gz',
        'billing.example.com_db_backup_2026-11-12_030000_0123456789abcdef.sql.gz',
        'staging.example.com_db_backup_2026-11-01_030000_0123456789abcdef.sql.gz',
        'billing.example.com_files_backup_2026-11-01_030000_0123456789abcdef.zip',
        'billing.example.com_db_backup_2026-11-01_notes.txt',
    ];

    expect(CloudBackups::expired($names, CloudBackups::pattern('db', 'billing.example.com_'), 2))->toBe([
        'billing.example.com_db_backup_2026-11-13_030000_0123456789abcdef.sql.gz',
        'billing.example.com_db_backup_2026-11-12_030000_0123456789abcdef.sql.gz',
    ])->and(CloudBackups::expired($names, CloudBackups::pattern('files', 'billing.example.com_'), 1))->toBe([]);
});

test('an encrypted copy is what openssl enc -aes-256-cbc -pbkdf2 -iter 100000 -md sha256 decrypts', function (): void {
    $plain = tempnam(sys_get_temp_dir(), 'cftest');
    $sealed = tempnam(sys_get_temp_dir(), 'cftest');
    // Over one CHUNK, so the chained pieces and the padded last one are both checked.
    file_put_contents($plain, random_bytes(10 * 1024 * 1024 + 5));

    try {
        CloudBackups::encrypt($plain, $sealed, 'correct horse');
        $data = (string) file_get_contents($sealed);
        $derived = hash_pbkdf2('sha256', 'correct horse', substr($data, 8, 8), 100000, 48, true);

        expect(substr($data, 0, 8))->toBe('Salted__')
            ->and(openssl_decrypt(substr($data, 16), 'aes-256-cbc', substr($derived, 0, 32), OPENSSL_RAW_DATA, substr($derived, 32)))->toBe(file_get_contents($plain));
    } finally {
        @unlink($plain);
        @unlink($sealed);
    }
});

test('saved secrets never reach the page and stay only with their service', function (): void {
    $saved = ['enabled' => true, 'provider' => 'onedrive', 'key' => 'app-id', 'secret' => 'enc:terces', 'tokens' => 'enc:snekot', 'passphrase' => 'enc:esarhp'];
    $di = cloudBackupsContainer(['backups_cloud' => $saved]);
    $view = CloudBackups::forStaff($di);

    expect($view)->not->toHaveKeys(['secret', 'tokens', 'passphrase'])
        ->and($view)->toMatchArray(['provider' => 'onedrive', 'key' => 'app-id', 'has_secret' => true, 'connected' => true, 'encrypted' => true]);

    $form = ['provider' => 'onedrive', 'key' => 'app-id', 'secret' => '', 'db_every' => 'daily', 'db_keep' => 7, 'files_every' => 'never', 'files_keep' => 4];
    $old = CloudBackups::settings($di);
    expect(CloudBackups::from($di, $form, $old))->toMatchArray(['secret' => 'enc:terces', 'tokens' => 'enc:snekot', 'passphrase' => ''])
        ->and(CloudBackups::from($di, ['key' => 'other-app'] + $form, $old)['tokens'])->toBe('')
        ->and(fn () => CloudBackups::from($di, ['provider' => 'gdrive'] + $form, $old))->toThrow(InformationException::class, 'client secret');
});

test('saving the same storage keeps the copies on, whatever order the saved keys are in', function (): void {
    $saved = ['tokens' => 'enc:snekot', 'enabled' => true, 'provider' => 'gdrive', 'key' => 'app-id', 'secret' => 'enc:terces'];
    $di = cloudBackupsContainer(['backups_cloud' => $saved]);
    $old = CloudBackups::settings($di);
    $form = ['provider' => 'gdrive', 'key' => 'app-id', 'secret' => '', 'db_every' => 'daily', 'db_keep' => 3, 'files_every' => 'never', 'files_keep' => 4];

    expect(CloudBackups::sameTarget(CloudBackups::from($di, $form, $old), $old))->toBeTrue()
        ->and(CloudBackups::sameTarget(CloudBackups::from($di, ['key' => 'other-app'] + $form, $old), $old))->toBeFalse();
});

test('a transport error keeps only the host of the URL (resumable upload URLs carry a credential)', function (): void {
    $di = cloudBackupsContainer();
    $di['http_client'] = new Symfony\Component\HttpClient\MockHttpClient(fn () => throw new Symfony\Component\HttpClient\Exception\TransportException('Idle timeout reached for "https://up.example.test/upload/session?tempauth=SECRET-TOKEN".'));
    $settings = ['provider' => 's3', 'endpoint' => 'https://s3.example.test', 'region' => 'us-east-1', 'bucket' => 'b', 'key' => 'k', 'secret' => 'enc:s', 'folder' => ''];
    $crypt = Mockery::mock();
    $crypt->shouldReceive('decrypt')->andReturn('s');
    $di['crypt'] = $crypt;

    try {
        CloudBackups::test($di, $settings);
        $message = '';
    } catch (ClientFence\InformationException $e) {
        $message = $e->getMessage();
    }

    expect($message)->toContain('up.example.test')->not->toContain('SECRET-TOKEN')->not->toContain('/upload/session');
});

