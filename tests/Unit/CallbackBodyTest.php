<?php

declare(strict_types=1);

namespace Tests\CallbackBody;

use ClientFence\Mod\Invoice\ServiceTransaction;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CallbackBodyResponse extends \RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct();
    }
}

function emitResponse(Response $response): never
{
    throw new CallbackBodyResponse($response);
}

test('callback input is bounded before queuing and oversized bodies return 413', function (int $length): void {
    $source = file_get_contents(PATH_ROOT . '/ipn.php');
    $start = strpos($source, '$rawBody =');
    $end = strpos($source, '$ipn =', $start);
    $read = eval('namespace Tests\\CallbackBody; use ClientFence; use Symfony\\Component\\HttpFoundation\\JsonResponse; return static function ($request) {' . substr($source, $start, $end - $start) . 'return $rawBody;};');
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, str_repeat('x', $length));
    rewind($stream);
    $request = new Request(content: $stream);
    try {
        if ($length > ServiceTransaction::MAX_CALLBACK_BODY_SIZE) {
            try {
                $read($request);
                test()->fail('Oversized callback was accepted');
            } catch (CallbackBodyResponse $exception) {
                expect($exception->response->getStatusCode())->toBe(413)
                    ->and(ftell($stream))->toBe(ServiceTransaction::MAX_CALLBACK_BODY_SIZE + 1);
            }
        } else {
            expect(strlen($read($request)))->toBe($length);
        }
    } finally {
        fclose($stream);
    }
})->with([8, 1048576, 1048580]);
