<?php

declare(strict_types=1);

use ClientFence\Crypt;
use ClientFence\SecretConfig;

test('only the listed secret fields are encrypted, and they decrypt back', function (): void {
    $crypt = new Crypt();

    $stored = SecretConfig::encryptSecretFields(['api_key' => 'example-key-secret', 'webhook_secret' => 'whsec_abc', 'pub_key' => 'pk_live_visible', 'fee' => ['percent' => 2]], ['api_key', 'webhook_secret', 'missing'], $crypt, 'test-passphrase');

    expect($stored['api_key'])->toStartWith(Crypt::CURRENT_FORMAT_PREFIX)->not->toContain('example-key-secret')
        ->and($stored['webhook_secret'])->toStartWith(Crypt::CURRENT_FORMAT_PREFIX)
        ->and($stored['pub_key'])->toBe('pk_live_visible')
        ->and($stored['fee'])->toBe(['percent' => 2])
        ->and($stored)->not->toHaveKey('missing')
        ->and(SecretConfig::decryptMarkedValues($stored, $crypt, 'test-passphrase'))->toBe(['api_key' => 'example-key-secret', 'webhook_secret' => 'whsec_abc', 'pub_key' => 'pk_live_visible', 'fee' => ['percent' => 2]]);
});

test('encrypting twice does not encrypt twice, and empty values stay empty', function (): void {
    $crypt = new Crypt();
    $once = SecretConfig::encryptSecretFields(['api_key' => 'example-key-secret', 'empty' => '', 'none' => null], ['api_key', 'empty', 'none'], $crypt, 'test-passphrase');

    expect(SecretConfig::encryptSecretFields($once, ['api_key', 'empty', 'none'], $crypt, 'test-passphrase'))->toBe($once)
        ->and($once['empty'])->toBe('')
        ->and($once['none'])->toBeNull();
});

test('a plaintext secret that starts like ciphertext is still encrypted', function (): void {
    $crypt = new Crypt();

    $stored = SecretConfig::encryptSecretFields(['api_key' => 'v2:not-ciphertext'], ['api_key'], $crypt, 'test-passphrase');

    expect($stored['api_key'])->not->toBe('v2:not-ciphertext')
        ->and($crypt->decrypt($stored['api_key'], 'test-passphrase'))->toBe('v2:not-ciphertext');
});

test('legacy plaintext and values that do not decrypt are read as they are', function (): void {
    $config = ['api_key' => 'example-key-legacy', 'label' => 'v2:not-ciphertext', 'count' => 42];

    expect(SecretConfig::decryptMarkedValues($config, new Crypt(), 'test-passphrase'))->toBe($config);
});
