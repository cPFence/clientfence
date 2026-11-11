<?php

declare(strict_types=1);

/**
 * ClientFence: an inline script finds its element by id or with closest(), never by position. Proxies such as
 * Cloudflare's email obfuscation insert their own <script> elements into the page, so the element next to a script
 * is not always the one the template put there (the language bar of product, category, add-on, help desk and order
 * form field pages did nothing behind Cloudflare).
 */
test('inline scripts do not find their element by its position next to the script', function (): void {
    $root = dirname(__DIR__, 3) . '/src';
    $found = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = $file->getPathname();
        if (!str_ends_with($path, '.twig') || str_contains($path, '/vendor/') || str_contains($path, '/node_modules/')) {
            continue;
        }
        if (preg_match('/currentScript\s*\.\s*(previous|next)ElementSibling/', (string) file_get_contents($path))) {
            $found[] = substr($path, strlen($root) + 1);
        }
    }

    expect($found)->toBe([]);
});
