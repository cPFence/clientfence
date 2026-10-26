<?php

declare(strict_types=1);

/**
 * ClientFence: the script policy (clientfence-security-headers.php) lets only scripts with this request's
 * nonce run, and no inline event handlers. Every template keeps to it.
 */
function scriptPolicyTemplates(): iterable
{
    $src = dirname(__DIR__, 3) . '/src';
    foreach (['modules', 'themes'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src . '/' . $dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), '.twig') && !str_contains($file->getPathname(), '/tests/')) {
                yield substr($file->getPathname(), strlen($src) + 1) => (string) file_get_contents($file->getPathname());
            }
        }
    }
}

test('the script policy is enforced in one header with frame ancestors', function (): void {
    // The headers file skips header() under CLI, so inspect its source.
    $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/clientfence-security-headers.php');

    expect($source)->toContain('foreach (clientfence_frame_headers() as $line)')
        ->not->toContain('Content-Security-Policy-Report-Only:')
        ->and(substr_count($source, 'Content-Security-Policy:'))->toBe(1);
});

test('a page is locked to this site and an embed page also allows the listed websites', function (): void {
    $script = "; script-src 'nonce-" . CLIENTFENCE_CSP_NONCE . "' 'strict-dynamic'";

    [$frameOptions, $locked] = clientfence_frame_headers();
    expect($frameOptions)->toBe('X-Frame-Options: SAMEORIGIN')
        ->and($locked)->toStartWith("Content-Security-Policy: frame-ancestors 'self'" . $script)
        ->and(clientfence_frame_headers(['https://example.com', 'https://b.example.org']))->toHaveCount(1)
        ->and(clientfence_frame_headers(['https://example.com', 'https://b.example.org'])[0])
        ->toStartWith("Content-Security-Policy: frame-ancestors 'self' https://example.com https://b.example.org" . $script);
});

test('every script tag in a template carries the nonce', function (): void {
    $missing = [];
    foreach (scriptPolicyTemplates() as $path => $twig) {
        // Embed code shown to staff ('\<script src=...') is text, not a tag of this page.
        // Inside {% autoescape 'js' %} the nonce is printed |raw (base64url, made by the server), or a "-" in it becomes \u002D.
        if (preg_match_all('/(?<![\\\\\'"])<script\b(?![^>]*\bnonce="\{\{ csp_nonce(\|raw)? \}\}")/i', $twig) > 0) {
            $missing[] = $path;
        }
    }

    expect($missing)->toBe([]);
});

test('a nonce inside a js autoescape block is printed raw', function (): void {
    $wrong = [];
    foreach (scriptPolicyTemplates() as $path => $twig) {
        foreach (preg_split('/\{%-?\s*endautoescape\s*-?%\}/', $twig) as $part) {
            $js = preg_match('/\{%-?\s*autoescape\s+[\'"]js[\'"]\s*-?%\}(.*)$/s', $part, $m) === 1 ? $m[1] : '';
            if (str_contains($js, 'nonce="{{ csp_nonce }}"')) {
                $wrong[] = $path;
            }
        }
    }

    expect($wrong)->toBe([]);
});

test('no template uses an inline event handler', function (): void {
    $found = [];
    foreach (scriptPolicyTemplates() as $path => $twig) {
        if (preg_match('/<[a-z][^>]*\son[a-z]+\s*=\s*["\']/i', $twig) === 1) {
            $found[] = $path;
        }
    }

    expect($found)->toBe([]);
});

test('withNonce adds the nonce to trusted script tags once', function (): void {
    $nonce = ClientFence\Csp::nonce();

    expect(ClientFence\Csp::withNonce('<p>x</p><SCRIPT src="a.js"></SCRIPT><script nonce="n">b()</script>'))
        ->toBe('<p>x</p><SCRIPT nonce="' . $nonce . '" src="a.js"></SCRIPT><script nonce="n">b()</script>')
        // A loader that writes a script tag from inside a script keeps its code unchanged.
        ->and(ClientFence\Csp::withNonce('<script>document.write("<script src=\'x.js\'><\\/script>");</script><script src="b.js"></script>'))
        ->toBe('<script nonce="' . $nonce . '">document.write("<script src=\'x.js\'><\\/script>");</script><script nonce="' . $nonce . '" src="b.js"></script>')
        ->and($nonce)->toMatch('/^[A-Za-z0-9_-]{24}$/')
        ->and(ClientFence\Csp::nonce())->toBe($nonce);
});
