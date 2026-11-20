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

/**
 * ClientFence: the secret fields of a stored payment gateway or registrar configuration
 * (pay_gateway.config, tld_registrar.config) are encrypted one by one with the install's key
 * (Crypt, info.salt); the rest of the JSON stays readable. Plaintext from before stays readable.
 */
final class SecretConfig
{
    /**
     * Encrypts the listed fields. A value counts as encrypted only when it really decrypts, so
     * running twice never encrypts twice and a plaintext that starts with "v2:" is still encrypted.
     *
     * @param array<string, mixed> $config
     * @param string[]             $secretFields
     *
     * @return array<string, mixed>
     */
    public static function encryptSecretFields(array $config, array $secretFields, Crypt $crypt, ?string $passphrase = null): array
    {
        foreach ($secretFields as $field) {
            $value = $config[$field] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }
            if (str_starts_with($value, Crypt::CURRENT_FORMAT_PREFIX) && is_string($crypt->decrypt($value, $passphrase))) {
                continue;
            }
            $config[$field] = $crypt->encrypt($value, $passphrase);
        }

        return $config;
    }

    /**
     * Decrypts every encrypted value; plaintext, and a value that does not decrypt, pass through.
     * Needs no field list, so it also works when the adapter is gone.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public static function decryptMarkedValues(array $config, Crypt $crypt, ?string $passphrase = null): array
    {
        foreach ($config as $field => $value) {
            if (!is_string($value) || !str_starts_with($value, Crypt::CURRENT_FORMAT_PREFIX)) {
                continue;
            }
            $decrypted = $crypt->decrypt($value, $passphrase);
            if (is_string($decrypted)) {
                $config[$field] = $decrypted;
            }
        }

        return $config;
    }
}
