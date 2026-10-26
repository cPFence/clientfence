<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Whmcs;

/**
 * ClientFence: reads what WHMCS encrypted with its encryption key (cc_encryption_hash in WHMCS's
 * configuration.php): two-factor secrets, password custom fields. WHMCS's own cipher, without
 * WHMCS code: a SHA-1 key stream over a random IV, base64 encoded; and WHMCS 9's model encryption
 * (decryptModel). Only decrypts; the import never writes to WHMCS.
 *
 * The key comes from the import settings (encrypted with this install's key) and is decrypted
 * inside fromSettings(), so it is never an argument of anything that may throw.
 */
final class Cipher
{
    private function __construct(#[\SensitiveParameter] private readonly string $key)
    {
    }

    /** The cipher of the saved WHMCS encryption key, or null when none was entered. */
    public static function fromSettings(\Pimple\Container $di, array $settings): ?self
    {
        $stored = (string) ($settings['encryption_key'] ?? '');
        if ($stored === '') {
            return null;
        }
        $key = (string) $di['crypt']->decrypt($stored);

        return $key === '' ? null : new self($key);
    }

    /** For tests: a cipher of a known key. */
    public static function withKey(#[\SensitiveParameter] string $key): self
    {
        return new self($key);
    }

    /** The plain text of a WHMCS-encrypted value, or null when it does not decrypt to text. */
    public function decrypt(string $value): ?string
    {
        $raw = base64_decode(trim($value), true);
        $hashKey = self::hash(md5(md5($this->key)) . md5($this->key));
        $length = strlen($hashKey);
        // Exactly one block is WHMCS's encryption of an empty value (a cPanel server's unused password).
        if ($raw === false || strlen($raw) < $length) {
            return null;
        }
        $iv = '';
        for ($i = 0; $i < $length; ++$i) {
            $iv .= chr(ord($raw[$i]) ^ ord($hashKey[$i]));
        }
        $data = substr($raw, $length);
        $key = $iv;
        $out = '';
        for ($i = 0, $n = strlen($data); $i < $n; ++$i) {
            if ($i !== 0 && $i % $length === 0) {
                $key = self::hash($key . substr($out, $i - $length, $length));
            }
            $out .= chr(ord($key[$i % $length]) ^ ord($data[$i]));
        }

        return self::text($out);
    }

    /**
     * The plain text of a value WHMCS 9 encrypted in a model (two-factor settings, since its "2FA
     * storage migration"), or null: hex of AES-256-CBC with a zero IV, keyed by the first 32
     * characters of sha256(cc_encryption_hash), as WHMCS's own code produces it.
     */
    public function decryptModel(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) % 32 !== 0 || !ctype_xdigit($value)) {
            return null;
        }
        $out = openssl_decrypt((string) hex2bin($value), 'aes-256-cbc', substr(hash('sha256', $this->key), 0, 32), OPENSSL_RAW_DATA, str_repeat("\0", 16));

        return $out === false ? null : self::text($out);
    }

    private static function text(string $out): ?string
    {
        return mb_check_encoding($out, 'UTF-8') && preg_match('/[\x00-\x08\x0e-\x1f]/', $out) !== 1 ? $out : null;
    }

    /** WHMCS's encryption, for tests that need values as WHMCS stores them. */
    public function encrypt(string $text, string $iv): string
    {
        $hashKey = self::hash(md5(md5($this->key)) . md5($this->key));
        $length = strlen($hashKey);
        $iv = substr(str_pad($iv, $length, "\0"), 0, $length);
        $out = '';
        for ($i = 0; $i < $length; ++$i) {
            $out .= chr(ord($iv[$i]) ^ ord($hashKey[$i]));
        }
        $key = $iv;
        for ($i = 0, $n = strlen($text); $i < $n; ++$i) {
            if ($i !== 0 && $i % $length === 0) {
                $key = self::hash($key . substr($text, $i - $length, $length));
            }
            $out .= chr(ord($key[$i % $length]) ^ ord($text[$i]));
        }

        return base64_encode($out);
    }

    private static function hash(string $text): string
    {
        return sha1($text, true);
    }
}
