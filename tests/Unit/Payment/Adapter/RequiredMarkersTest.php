<?php

declare(strict_types=1);

test('payment gateway required markers match the constructor in each mode', function (string $adapter, bool $testMode): void {
    $state = ['enabled' => true, 'test_mode' => $testMode];
    $required = [];
    foreach ($adapter::getConfig()['form'] as $field => [, $options]) {
        if (!isset($options['required_when'])) {
            continue;
        }

        foreach ($options['required_when'] as $key => $value) {
            if ($state[$key] !== $value) {
                continue 2;
            }
        }
        $required[$field] = 'x';
    }

    $config = ['test_mode' => $testMode] + $required;
    expect(new $adapter($config))->toBeInstanceOf($adapter);

    foreach (array_keys($required) as $field) {
        expect(fn () => new $adapter(array_diff_key($config, [$field => true])))->toThrow(Payment_Exception::class);
    }
})->with([
    Payment_Adapter_Eway::class,
    Payment_Adapter_Mollie::class,
    Payment_Adapter_GoCardless::class,
    Payment_Adapter_PayU::class,
    Payment_Adapter_CCAvenue::class,
    Payment_Adapter_PayTabs::class,
    Payment_Adapter_PayPalAutoPay::class,
])->with([false, true]);

test('Internetbs required markers match the constructor', function (): void {
    $config = [];
    foreach (Registrar_Adapter_Internetbs::getConfig()['form'] as $field => [, $options]) {
        if (($options['required'] ?? false) === true) {
            $config[$field] = 'x';
        }
    }

    expect(new Registrar_Adapter_Internetbs($config))->toBeInstanceOf(Registrar_Adapter_Internetbs::class);

    foreach (array_keys($config) as $field) {
        expect(fn () => new Registrar_Adapter_Internetbs(array_diff_key($config, [$field => true])))->toThrow(Registrar_Exception::class);
    }
});
