<?php

declare(strict_types=1);

/** ClientFence: the converting manual gateway shows the total in the currency set on it, EUR when none is set. */

use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

require_once PATH_LIBRARY . '/Payment/Adapter/CustomConvert.php';

/** An adapter with $config whose rate provider answers 1 USD = 50.5 EGP = 0.92 EUR = 0.000412345 XAU. */
function customConvertAdapter(array $config, Pimple\Container $di = new Pimple\Container()): Payment_Adapter_CustomConvert
{
    $di['cache'] = new ArrayAdapter();
    $di['http_client'] = new MockHttpClient(new MockResponse((string) json_encode([
        'result' => 'success',
        'time_last_update_unix' => 1_794_960_000,
        'time_next_update_utc' => 'Tue, 01 Jan 2030 00:00:00 +0000',
        'rates' => ['USD' => 1, 'EGP' => 50.5, 'EUR' => 0.92, 'XAU' => 0.000412345],
    ])));
    $adapter = new Payment_Adapter_CustomConvert($config);
    $adapter->setDi($di);

    return $adapter;
}

test('the total is shown in the gateway currency, rounded up to a whole unit', function (array $config, ?array $expected): void {
    $converted = customConvertAdapter($config)->convertedAmount(100.10, 'usd');

    expect($converted === null ? null : [$converted['currency'], $converted['total'], $converted['rate'], $converted['factor']])->toBe($expected);
})->with([
    'no setting shows EUR' => [[], ['EUR', '93', '0.9200', 0.92]],
    'EGP' => [['currency' => ' egp '], ['EGP', '5,056', '50.5000', 50.5]],
    'not a currency code falls back to EUR' => [['currency' => 'pound'], ['EUR', '93', '0.9200', 0.92]],
    'a currency the provider does not list' => [['currency' => 'XYZ'], null],
    'a strong currency keeps its rate digits' => [['currency' => 'XAU'], ['XAU', '1', '0.0004123', 0.000412345]],
    'the invoice currency itself' => [['currency' => 'USD'], ['USD', '101', '1.0000', 1.0]],
]);

test('the instructions convert the balance due of an unpaid invoice', function (string $status, string $expected): void {
    $di = new Pimple\Container();
    $di['db'] = Mockery::mock();
    $di['db']->allows('load')->andReturn(new Model_Invoice());
    $invoiceService = Mockery::mock();
    $invoiceService->allows('toApiArray')->andReturn(['status' => $status, 'total' => 10.0, 'balance_due' => $status === 'unpaid' ? 4.0 : 0.0, 'currency' => 'USD']);
    $systemService = Mockery::mock();
    $systemService->allows('renderAdapterTplString')->andReturnUsing(fn (string $tpl, array $vars): string => $vars['converted']['total']);
    $di['mod_service'] = $di->protect(fn (string $name) => $name === 'Invoice' ? $invoiceService : $systemService);
    $adapter = customConvertAdapter(['currency' => 'EGP', 'single' => '{{ converted.total }}'], $di);

    expect($adapter->getHtml(new ClientFence\Api\Proxy(new Model_Admin()), 1, false))->toBe($expected);
})->with([
    'unpaid, part paid' => ['unpaid', '202'],
    'paid' => ['paid', '505'],
]);
