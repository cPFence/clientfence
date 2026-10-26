<?php

declare(strict_types=1);

namespace ClientFence\Mod\Servicehosting;

use ClientFence\InformationException;

/**
 * ClientFence: hosting server credentials encrypted at rest. A server's password and API key (and
 * its username where the server manager marks it secret, as Plesk does) are stored encrypted with
 * this install's key, marked with PREFIX; they are decrypted only to talk to the server
 * (Service::getServerManager()). A value without the mark is an older plain one: it is read as it
 * is and encrypted the next time the server is saved. The value is base64-encoded before encryption:
 * the install's decryption trims what it returns, and a credential may begin or end with a space.
 */
final class ServerSecrets
{
    public const PREFIX = 'cfsecret:';
    /** The server columns that may hold a secret. */
    public const COLUMNS = ['username', 'password', 'accesshash'];

    /** The value to store: encrypted, or as it is when empty or already encrypted. */
    public static function seal(\Pimple\Container $di, mixed $value): mixed
    {
        if (!is_string($value) || $value === '' || str_starts_with($value, self::PREFIX)) {
            return $value;
        }

        return self::PREFIX . $di['crypt']->encrypt(base64_encode($value));
    }

    /** The plain value of a stored one (an older plain value as it is). */
    public static function open(\Pimple\Container $di, mixed $value): mixed
    {
        if (!is_string($value) || !str_starts_with($value, self::PREFIX)) {
            return $value;
        }
        $encoded = $di['crypt']->decrypt(substr($value, strlen(self::PREFIX)));
        $plain = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (!is_string($plain)) {
            throw new InformationException('A hosting server key could not be read with this install\'s encryption key. Enter the server\'s password or API key again.');
        }

        return $plain;
    }

    /** open(), or null when the stored value cannot be read (it is being replaced anyway). */
    public static function tryOpen(\Pimple\Container $di, mixed $value): mixed
    {
        try {
            return self::open($di, $value);
        } catch (InformationException) {
            return null;
        }
    }

    public static function isSealed(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }
}
