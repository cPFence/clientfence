<?php

declare(strict_types=1);

test('an int passed as variables is treated as the exception code', function (): void {
    $exception = new Registrar_Exception('API failure', 500);

    expect($exception->getMessage())->toBe('API failure')
        ->and($exception->getCode())->toBe(500);
});

test('translation variables still work as before', function (): void {
    $exception = new Registrar_Exception('Hello :name', [':name' => 'world']);

    expect($exception->getMessage())->toBe('Hello world')
        ->and($exception->getCode())->toBe(0);
});

test('an explicit exception code is preserved when variables are null', function (): void {
    $exception = new Registrar_Exception('plain', null, 3001);

    expect($exception->getMessage())->toBe('plain')
        ->and($exception->getCode())->toBe(3001);
});

test('an int in variables takes precedence over the code argument', function (): void {
    $exception = new Registrar_Exception('API failure', 500, 999);

    expect($exception->getMessage())->toBe('API failure')
        ->and($exception->getCode())->toBe(500);
});

test('without the registrar name, the name variables read "the registrar"', function (string $message, array $variables, string $client): void {
    expect((new Registrar_Exception($message, $variables))->withoutRegistrarName()->getMessage())->toBe($client);
})->with([
    ':registrar' => ['Could not reach :registrar: :error', [':registrar' => 'Porkbun', ':error' => 'timeout'], 'Could not reach the registrar: timeout'],
    'at the start' => [':registrar returned an unreadable response for :command', [':registrar' => 'Nominet', ':command' => 'domain:info'], 'The registrar returned an unreadable response for domain:info'],
    ':type:' => ['Failed to :action: with :type:, check the error logs for further details', [':action:' => 'lock', ':type:' => 'Namecheap'], 'Failed to lock with the registrar, check the error logs for further details'],
    'no name' => ['At least two name servers are required.', [], 'At least two name servers are required.'],
]);
