<?php

declare(strict_types=1);

/**
 * Custom CSS for the client area (CustomCss.php): what a save accepts and refuses, and its version and log.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Clientfence\CustomCss;

test('custom CSS accepts rules, relative links, data: URLs and links to this site', function (string $css): void {
    CustomCss::check($css);
    expect(true)->toBeTrue();
})->with([
    'rules' => ['.navbar { background: #123456; } /* comment */'],
    'relative link' => ['body { background: url("/uploads/brand/bg.png"); }'],
    'data: URL with an SVG namespace' => ["i { background: url(\"data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg'/>\"); }"],
    'this site' => [fn () => 'h1 { background: url(https://' . parse_url(SYSTEM_URL, PHP_URL_HOST) . '/a.png); }'],
]);

test('custom CSS refuses @import, links to other websites and oversized or binary text', function (string $css): void {
    expect(fn () => CustomCss::check($css))->toThrow(InformationException::class);
})->with([
    '@import' => ['@import "theme.css";'],
    'escaped @import' => ['@\69mport url(x.css);'],
    'other website' => ['body { background: url(https://tracker.example.net/p.gif); }'],
    'escaped other website' => ['body { background: url(ht\74ps://tracker.example.net/p.gif); }'],
    'protocol-relative' => ["body { background: url('//tracker.example.net/p.gif'); }"],
    'image-set' => ['body { background-image: image-set("https://tracker.example.net/a.png" 1x); }'],
    'font' => ['@font-face { font-family: X; src: url(https://fonts.example.net/x.woff2); }'],
    'too long' => [str_repeat('a', CustomCss::MAX_BYTES + 1)],
    'not UTF-8' => ["a { content: '\xff'; }"],
]);

test('saving custom CSS sets its version and logs only a change', function (): void {
    $logger = Mockery::mock(stdClass::class);
    $logger->shouldReceive('info')->once()->with('Changed the client area custom CSS (%s, %d bytes)', 'on', 12);
    $di = new Pimple\Container();
    $di['logger'] = $logger;

    $params = CustomCss::prepare($di, ['custom_css' => " a{color:red}\r\n", 'custom_css_enabled' => '1'], []);
    expect($params['custom_css'])->toBe('a{color:red}')
        ->and($params['custom_css_version'])->toBe(substr(hash('sha256', 'a{color:red}'), 0, 12));

    // The same CSS saved again with the switch unchanged writes no log line (once() above).
    CustomCss::prepare($di, ['custom_css' => 'a{color:red}', 'custom_css_enabled' => '1'], $params);
});
