<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

test('rejects ciphertext without a complete IV and payload without warnings', function (int $length, string $prefix): void {
    set_error_handler(static function (int $severity, string $message): never {
        throw new ErrorException($message, 0, $severity);
    });

    try {
        $crypt = new ClientFence\Crypt();
        expect($crypt->decrypt($prefix . base64_encode(str_repeat('x', $length)), 'password'))->toBeFalse();
    } finally {
        restore_error_handler();
    }
})->with([0, 6, 15, 16])->with(['', ClientFence\Crypt::CURRENT_FORMAT_PREFIX]);
