<?php

declare(strict_types=1);

/**
 * ClientFence: System health reports a core file without its patch marker as not patched
 * (tools/core-patch-markers.json, copied into the release's pack manifest), so every marker
 * must be in the code it names.
 */
test('every core patch marker is in the file it names', function (): void {
    $root = dirname(__DIR__, 3);
    $markers = json_decode((string) file_get_contents($root . '/tools/core-patch-markers.json'), true, 512, JSON_THROW_ON_ERROR);

    $missing = [];
    foreach ($markers as $marker) {
        $file = $root . '/src/' . $marker['file'];
        if (!is_file($file) || !str_contains((string) file_get_contents($file), $marker['done'])) {
            $missing[] = $marker['file'] . ': ' . strtok($marker['done'], "\n");
        }
    }

    expect($markers)->not->toBeEmpty()
        ->and($missing)->toBe([]);
});
