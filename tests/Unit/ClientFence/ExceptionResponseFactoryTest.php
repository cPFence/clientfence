<?php

declare(strict_types=1);

use ClientFence\ErrorPage;
use ClientFence\Http\ExceptionResponseFactory;
use Symfony\Component\HttpFoundation\Response;

test('exception response factory creates plain text responses while testing', function (): void {
    putenv('APP_ENV=test');
    $exception = new RuntimeException('Broken loader state', 500);

    $response = (new ExceptionResponseFactory())->create($exception);

    expect($response->getStatusCode())->toBe(Response::HTTP_INTERNAL_SERVER_ERROR)
        ->and($response->headers->get('Content-Type'))->toContain('text/plain')
        ->and($response->getContent())->toContain('RuntimeException: Broken loader state');
});

test('exception response factory creates ClientFence error pages outside testing', function (): void {
    $previousEnv = getenv('APP_ENV');
    putenv('APP_ENV=prod');

    try {
        $response = (new ExceptionResponseFactory())->create(new RuntimeException('Config missing', 3));
    } finally {
        putenv($previousEnv === false ? 'APP_ENV' : 'APP_ENV=' . $previousEnv);
    }

    expect($response->getStatusCode())->toBe(Response::HTTP_INTERNAL_SERVER_ERROR)
        ->and($response->getContent())->toContain('Powered by ClientFence')
        ->and($response->getContent())->toContain('Your Configuration is Empty');
});

test('exception response factory falls back to 500 for non-integer exception codes', function (): void {
    // PDOException's $code property is untyped, so PDO can set it to a string SQLSTATE.
    $exception = new PDOException('Unknown column');
    $codeProperty = new ReflectionProperty($exception, 'code');
    $codeProperty->setValue($exception, '42S22');

    $response = (new ExceptionResponseFactory())->create($exception);

    expect($response->getStatusCode())->toBe(Response::HTTP_INTERNAL_SERVER_ERROR);
});

test('error page can render without sending output', function (): void {
    $page = (new ErrorPage())->renderPage(404, 'Missing route');

    expect($page)->toContain('<title>An error has occurred. · ClientFence</title>')
        ->and($page)->toContain('Error code #404')
        ->and($page)->toContain('Missing route');
});

test('exception error pages disclose database details only to signed-in staff', function (array $session, bool $showDetails): void {
    $previousSession = $_SESSION ?? [];
    $_SESSION = $session;
    $message = "SQLSTATE[42S22]: SELECT denied to user 'billing'@'localhost' for table `private_db`.`invoice` <query>";
    $exception = new PDOException($message);
    (new ReflectionProperty($exception, 'code'))->setValue($exception, '42S22');

    try {
        $response = withAppEnv('prod', fn (): Response => (new ExceptionResponseFactory())->create($exception));
    } finally {
        $_SESSION = $previousSession;
    }

    expect($response->getStatusCode())->toBe(Response::HTTP_INTERNAL_SERVER_ERROR)
        ->and($response->getContent())->toContain('Error code #0', 'Instance ' . INSTANCE_ID)
        ->and($response->getContent())->not->toContain($message);

    if ($showDetails) {
        expect($response->getContent())->toContain(htmlspecialchars($message));
    } else {
        expect($response->getContent())->toContain('An unexpected error occurred. Please contact support.')
            ->and($response->getContent())->not->toContain('SQLSTATE', 'billing', 'localhost', 'private_db', '&lt;query&gt;');
    }
})->with([
    'guest' => [[], false],
    'client' => [['client_id' => 12], false],
    'staff' => [['admin' => ['id' => 3]], true],
]);

test('application error messages stay visible to guests', function (): void {
    $previousSession = $_SESSION ?? [];
    $_SESSION = [];

    try {
        $response = withAppEnv('prod', fn (): Response => (new ExceptionResponseFactory())->create(new ClientFence\Exception('Invoice not found & <gone>')));
    } finally {
        $_SESSION = $previousSession;
    }

    expect($response->getContent())->toContain('Invoice not found &amp; &lt;gone&gt;');
});

test('application messages wrapped by template rendering stay visible to guests', function (): void {
    $previousSession = $_SESSION ?? [];
    $_SESSION = [];
    $wrapped = new Twig\Error\RuntimeError('An exception has been thrown during the rendering of a template ("Product not found").', -1, null, new ClientFence\InformationException('Product not found'));

    try {
        $response = withAppEnv('prod', fn (): Response => (new ExceptionResponseFactory())->create($wrapped));
    } finally {
        $_SESSION = $previousSession;
    }

    expect($response->getContent())->toContain('Product not found')
        ->and($response->getContent())->not->toContain('rendering of a template');
});
