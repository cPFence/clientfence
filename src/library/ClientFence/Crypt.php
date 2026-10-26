<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence;

use ClientFence\Config;

class Crypt implements \ClientFence\InjectionAwareInterface
{
    /** @var array<string, string> passphrase => derived key, for the length of a request */
    private static array $derivedKeys = [];

    protected ?\Pimple\Container $di = null;

    final public const string METHOD = 'aes-256-cbc';
    final public const string CURRENT_FORMAT_PREFIX = 'v2:';

    public function __construct()
    {
        if (!extension_loaded('openssl')) {
            throw new \ClientFence\Exception('The PHP OpenSSL extension must be enabled on your server');
        }
    }

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function encrypt(string $text, ?string $pass = null): string
    {
        return self::CURRENT_FORMAT_PREFIX . $this->encryptWithKey($text, $this->getCurrentKey($pass));
    }

    public function decrypt(?string $text, ?string $pass = null)
    {
        if (is_null($text)) {
            return false;
        }

        if (str_starts_with($text, self::CURRENT_FORMAT_PREFIX)) {
            return $this->decryptWithKey(substr($text, strlen(self::CURRENT_FORMAT_PREFIX)), $this->getCurrentKey($pass));
        }

        foreach ([$this->getCurrentKey($pass), $this->getLegacyKey($pass)] as $key) {
            $result = $this->decryptWithKey($text, $key);
            if ($result !== false) {
                return $result;
            }
        }

        return false;
    }

    private function encryptWithKey(string $text, string $key): string
    {
        $ivsize = openssl_cipher_iv_length(self::METHOD);
        $iv = openssl_random_pseudo_bytes($ivsize);

        $ciphertext = openssl_encrypt(
            $text,
            self::METHOD,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        return base64_encode($iv . $ciphertext);
    }

    private function decryptWithKey(string $text, string $key): string|false
    {
        $decoded = base64_decode($text, true);
        if ($decoded === false) {
            return false;
        }

        $ivsize = openssl_cipher_iv_length(self::METHOD);
        // ClientFence core patch (0.8.9 port): truncated values cannot contain both an IV and encrypted payload.
        if (strlen($decoded) <= $ivsize) {
            return false;
        }

        $iv = mb_substr($decoded, 0, $ivsize, '8bit');
        $ciphertext = mb_substr($decoded, $ivsize, null, '8bit');

        $result = openssl_decrypt(
            $ciphertext,
            self::METHOD,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($result === false) {
            return false;
        }

        $result = trim($result);

        if (!$this->isPlausiblePlaintext($result)) {
            return false;
        }

        return $result;
    }

    private function getCurrentKey(?string $pass = null): string
    {
        // ClientFence core patch: the key is derived once per passphrase and request (100,000 PBKDF2
        // rounds took 25-30 ms on every decrypt, most of a page's time), and kept in the app cache.
        $pass = $this->resolvePassphrase($pass);

        return self::$derivedKeys[$pass] ??= $this->cachedKey($pass);
    }

    /**
     * ClientFence: the derived key from the app cache (Redis or files), so pages stop paying for
     * PBKDF2. That cache already holds the decrypted module configurations
     * (Extension\Service::getConfig()), and config.php holds the passphrase itself, so it reveals
     * nothing new. Without a usable cache the key is derived here.
     */
    private function cachedKey(string $pass): string
    {
        $derive = static fn (): string => hash_pbkdf2('sha256', $pass, 'fossbilling_salt', 100000, 32, true);
        try {
            if ($this->di !== null && isset($this->di['cache'])) {
                $stored = $this->di['cache']->get('crypt_key_' . hash('sha256', 'clientfence-crypt-key:' . $pass), static fn (): string => base64_encode($derive()));
                $key = is_string($stored) ? base64_decode($stored, true) : false;
                if (is_string($key) && strlen($key) === 32) {
                    return $key;
                }
            }
        } catch (\Throwable) {
            // No cache (installer, broken cache folder): derive it.
        }

        return $derive();
    }

    private function getLegacyKey(?string $pass = null): string
    {
        return pack('H*', hash('md5', $this->resolvePassphrase($pass)));
    }

    private function resolvePassphrase(?string $pass = null): string
    {
        $pass ??= Config::getProperty('info.salt');

        return (string) $pass;
    }

    private function isPlausiblePlaintext(string $text): bool
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            return false;
        }

        return !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text);
    }
}
