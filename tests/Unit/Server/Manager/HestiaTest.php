<?php

declare(strict_types=1);

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

test('Hestia rejects missing hostnames during initialization', function (array $config): void {
    expect(fn (): Server_Manager_Hestia => new Server_Manager_Hestia($config))->toThrow(
        Server_Exception::class,
        'The HestiaCP server hostname is missing. Please configure it in the server settings.',
    );
})->with([
    'missing' => [[]],
    'null' => [['host' => null]],
    'empty' => [['host' => '']],
    'whitespace' => [['host' => " \t\n"]],
    'IP without hostname' => [['ip' => '192.0.2.1']],
]);
