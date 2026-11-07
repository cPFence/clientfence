<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use Tests\Helpers\ApiClient;

if (!getenv('APP_URL') || !getenv('TEST_API_KEY')) {
    return;
}

const SYSTEM_MAX_RETRY_ATTEMPTS = 10;
const SYSTEM_RETRY_DELAY_MICROSECONDS = 200000;
const SYSTEM_DEFAULT_INTERFACE = '0';

function systemRetryUntil(callable $operation, int $maxAttempts = SYSTEM_MAX_RETRY_ATTEMPTS, int $delayMicroseconds = SYSTEM_RETRY_DELAY_MICROSECONDS): bool
{
    for ($attempt = 0; $attempt < $maxAttempts; ++$attempt) {
        if ($operation()) {
            return true;
        }

        if ($attempt < $maxAttempts - 1) {
            usleep($delayMicroseconds);
        }
    }

    return false;
}

function systemIsIpLookupAvailable(): bool
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 2,
        ],
    ]);

    $lookupErrorMessage = null;
    $previousErrorHandler = set_error_handler(
        static function (
            int $severity,
            string $message,
            string $file = '',
            int $line = 0,
        ) use (&$lookupErrorMessage, &$previousErrorHandler): bool {
            if ($severity === E_WARNING) {
                $lookupErrorMessage = $message;

                return true;
            }

            if (is_callable($previousErrorHandler)) {
                return (bool) $previousErrorHandler($severity, $message, $file, $line);
            }

            return false;
        }
    );

    try {
        $response = file_get_contents('https://api.ipify.org', false, $context);
    } finally {
        restore_error_handler();
    }

    if ($response === false && $lookupErrorMessage !== null) {
        $singleLineLookupErrorMessage = str_replace(["\r", "\n"], ' ', $lookupErrorMessage);
        error_log('IP lookup availability check failed: ' . $singleLineLookupErrorMessage);
    }

    return $response !== false;
}

function systemResetInterfaceConfiguration(): void
{
    $resetResult = ApiClient::request(
        'admin/system/set_interface_ip',
        ['custom_interface' => '', 'interface' => SYSTEM_DEFAULT_INTERFACE]
    );
    expect($resetResult->wasSuccessful())->toBeTrue();
}

test('clears cache', function (): void {
    $result = ApiClient::request('admin/system/clear_cache');
    expect($result->wasSuccessful())->toBeTrue()
        ->and($result->getResult())->toBeBool();
});

test('gets and sets network interfaces', function (): void {
    $result = ApiClient::request('admin/system/get_interface_ips');
    expect($result->wasSuccessful())->toBeTrue()
        ->and($result->getResult())->toBeArray();

    foreach ($result->getResult() as $ip) {
        expect((bool) filter_var($ip, FILTER_VALIDATE_IP))->toBeTrue();
    }

    if (!systemIsIpLookupAvailable()) {
        $this->markTestSkipped('Integration test requires ipify.org to be available.');
    }

    foreach ($result->getResult() as $ip) {
        $testResult = ApiClient::request('admin/system/set_interface_ip', ['interface' => $ip]);
        expect($testResult->wasSuccessful())->toBeTrue();

        $isReady = systemRetryUntil(
            static function (): bool {
                $envResult = ApiClient::request('admin/system/env', ['ip' => true]);

                return $envResult->wasSuccessful() && (bool) filter_var($envResult->getResult(), FILTER_VALIDATE_IP);
            }
        );

        expect($isReady)->toBeTrue();
    }

    $cleanupResult = ApiClient::request('admin/system/set_interface_ip', ['interface' => SYSTEM_DEFAULT_INTERFACE]);
    expect($cleanupResult->wasSuccessful())->toBeTrue();
});

test('rejects invalid interface', function (): void {
    $result = ApiClient::request('admin/system/set_interface_ip', ['interface' => '12345']);
    expect($result->wasSuccessful())->toBeFalse();
});

test('rejects invalid custom interface', function (): void {
    $result = ApiClient::request('admin/system/set_interface_ip', ['custom_interface' => '!@#$%']);
    expect($result->wasSuccessful())->toBeFalse();
});

test('rejects malicious interface values', function (string $payload): void {
    $result = ApiClient::request('admin/system/set_interface_ip', ['interface' => $payload]);
    expect($result->wasSuccessful())->toBeFalse();
})->with([
    'quote-escape' => ["x\"; echo 'pwned'; //"],
    'pipe-command' => ['eth0|id'],
    'backticks' => ['eth0`id`'],
    'sub-shell' => ['eth0$(id)'],
    'and-command' => ['eth0 && whoami'],
    'redirect' => ['eth0 > /tmp/pwned'],
    'path-traversal-unix' => ['../etc/passwd'],
    'path-traversal-windows' => ['..\\..\\windows\\system32\\drivers\\etc\\hosts'],
    'null-byte' => ["eth0\0evil"],
    'long-string' => [str_repeat('a', 1024)],
    'newline-injection' => ["eth0\nwhoami"],
    'leading-trailing-space' => [' eth0 '],
]);

test('rejects malicious custom interface', function (): void {
    $result = ApiClient::request('admin/system/set_interface_ip', ['custom_interface' => "x\"; passthru('id'); //"]);
    expect($result->wasSuccessful())->toBeFalse();
});

test('accepts valid custom interface hostname', function (): void {
    try {
        $result = ApiClient::request('admin/system/set_interface_ip', ['custom_interface' => 'eth0']);
        expect($result->wasSuccessful())->toBeTrue();
    } finally {
        systemResetInterfaceConfiguration();
    }
});
