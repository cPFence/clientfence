<?php

declare(strict_types=1);

use ClientFence\Csp;
use ClientFence\Twig\Extension\DebugBarExtension;

test('the debug bar tags carry the script policy nonce in development', function (): void {
    [$head, $body] = withAppEnv('dev', function (): array {
        $extension = new DebugBarExtension(new DebugBar\StandardDebugBar());

        return [$extension->renderHead(), $extension->render()];
    });
    $nonce = 'nonce="' . Csp::nonce() . '"';

    expect($head)->toContain('<style ' . $nonce . '>')
        ->and($head)->toContain('<script type="text/javascript" ' . $nonce . '>')
        ->and($body)->toStartWith('<script type="text/javascript" ' . $nonce . '>');
});
