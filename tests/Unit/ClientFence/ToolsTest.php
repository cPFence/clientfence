<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

dataset('sanitizeContentProvider', fn (): array => [
    // [input, expected_output, allowSafeHtml]
    ['', '', false],
    ["Hello\0World", 'HelloWorld', false],
    ['<script>alert("xss")</script>', 'alert(&quot;xss&quot;)', false], // Text content HTML-encoded
    ['<p>Hello</p>', 'Hello', false],
    ['<p>Hello</p>', '<p>Hello</p>', true], // Safe HTML preserved
    ['Test <img src="x">', 'Test <img />', true], // img converted to self-closing
]);

test('sanitize content', function (string $input, string $expected, bool $allowSafeHtml): void {
    $result = ClientFence\Tools::sanitizeContent($input, $allowSafeHtml);
    expect($result)->toEqual($expected);
})->with('sanitizeContentProvider');

test('sanitize content removes null bytes', function (): void {
    $input = "Hello\0World";
    $result = ClientFence\Tools::sanitizeContent($input, false);
    expect($result)->not->toContain("\0");
    expect($result)->toBe('HelloWorld');
});

test('sanitize content removes script tags', function (): void {
    $input = '<p>Hello</p><script>alert("xss")</script>';
    $result = ClientFence\Tools::sanitizeContent($input, true);
    expect($result)->not->toContain('<script>');
    expect($result)->not->toContain('alert');
});

test('sanitize content removes java script urls', function (): void {
    $input = '<a href="javascript:alert(1)">Click</a>';
    $result = ClientFence\Tools::sanitizeContent($input, true);
    expect($result)->not->toContain('javascript:');
});

test('sanitize content removes event handlers', function (): void {
    $input = '<p onclick="alert(1)">Hello</p>';
    $result = ClientFence\Tools::sanitizeContent($input, true);
    expect($result)->not->toContain('onclick');
});

test('sanitize content removes multiple event handlers', function (): void {
    $input = '<img src="x" onerror="alert(1)" onload="evil()" onmouseover="bad()">';
    $result = ClientFence\Tools::sanitizeContent($input, true);
    expect($result)->not->toContain('onerror');
    expect($result)->not->toContain('onload');
    expect($result)->not->toContain('onmouseover');
});

test('sanitize content allows safe links', function (): void {
    $input = '<a href="https://example.com" title="Example">Link</a>';
    $result = ClientFence\Tools::sanitizeContent($input, true);
    expect($result)->toContain('href="https://example.com"');
});

test('sanitize content strips unsafe attributes', function (): void {
    $input = '<p style="color:red">Text</p>';
    $result = ClientFence\Tools::sanitizeContent($input, true);

    // style attribute is not allowed, so it's stripped
    expect($result)->not->toContain('style=');
    expect($result)->toContain('<p>');
    expect($result)->toContain('</p>');
});

test('sanitize content handles nested tags', function (): void {
    $input = '<div><span>nested<span>deep</span></span></div>';
    $result = ClientFence\Tools::sanitizeContent($input, true);
    expect($result)->toContain('<div>');
    expect($result)->toContain('</div>');
});

test('sanitize content strips php tags', function (): void {
    $input = '<?php echo "test"; ?>';
    $result = ClientFence\Tools::sanitizeContent($input, false);
    expect($result)->not->toContain('<?php');
    expect($result)->not->toContain('?>');
});

test('sanitize content strips iframe tags', function (): void {
    $input = '<iframe src="https://evil.com"></iframe>';
    $result = ClientFence\Tools::sanitizeContent($input, false);
    expect($result)->not->toContain('<iframe');
});

test('sanitize content strips object tags', function (): void {
    $input = '<object data="evil.swf"></object>';
    $result = ClientFence\Tools::sanitizeContent($input, false);
    expect($result)->not->toContain('<object');
});

test('sanitize content strips embed tags', function (): void {
    $input = '<embed src="evil.swf">';
    $result = ClientFence\Tools::sanitizeContent($input, false);
    expect($result)->not->toContain('<embed');
});

test('sanitize content preserves text content', function (): void {
    $input = 'Plain text with special chars: < > & " \' /';
    $result = ClientFence\Tools::sanitizeContent($input, false);
    expect($result)->not->toContain('<');
    expect($result)->not->toContain('>');
});

test('sanitize plain text preserves characters without storing HTML entities', function (): void {
    $input = " What's <strong>new</strong> & useful\0 ";
    $result = ClientFence\Tools::sanitizePlainText($input);

    expect($result)->toBe("What's new & useful");
});

test('sanitize markdown content preserves angle brackets as literal text', function (): void {
    $input = 'Hello <World> this text must not disappear';
    $result = ClientFence\Tools::sanitizeMarkdownContent($input);
    expect($result)->toBe('Hello <World> this text must not disappear');
});

test('sanitize markdown content preserves comparison operators', function (): void {
    $input = 'Value must be 1 < x < 10 and y > 0';
    $result = ClientFence\Tools::sanitizeMarkdownContent($input);
    expect($result)->toBe('Value must be 1 < x < 10 and y > 0');
});

test('sanitize markdown content removes null bytes', function (): void {
    $result = ClientFence\Tools::sanitizeMarkdownContent("Hello\0World");
    expect($result)->not->toContain("\0");
    expect($result)->toBe('HelloWorld');
});

test('sanitize markdown content returns empty string for empty input', function (): void {
    expect(ClientFence\Tools::sanitizeMarkdownContent(''))->toBe('');
});

test('sanitize markdown content trims surrounding whitespace', function (): void {
    $result = ClientFence\Tools::sanitizeMarkdownContent("  Hello World  \n");
    expect($result)->toBe('Hello World');
});

test('sanitize markdown content preserves markdown bold syntax', function (): void {
    $input = 'This is **bold** and _italic_ text';
    $result = ClientFence\Tools::sanitizeMarkdownContent($input);
    expect($result)->toBe($input);
});

test('sanitize markdown content preserves markdown inline code', function (): void {
    $input = 'Use `git clone` or `<tag>` syntax';
    $result = ClientFence\Tools::sanitizeMarkdownContent($input);
    expect($result)->toBe($input);
});

test('external IP lookup skips private responses and trims a public response', function (): void {
    $httpClient = new MockHttpClient([
        new MockResponse('192.168.1.10'),
        new MockResponse("8.8.8.8\n"),
    ]);
    $di = new Pimple\Container();
    $di['http_client'] = $httpClient;

    $tools = new ClientFence\Tools();
    $tools->setDi($di);

    expect($tools->getExternalIP())->toBe('8.8.8.8')
        ->and($httpClient->getRequestsCount())->toBe(2);
});

test('callback signature matches the HMAC of the gateway and invoice pair', function (): void {
    $previousSalt = ClientFence\Config::getProperty('info.salt');
    ClientFence\Config::setProperty('info.salt', 'test-salt', false);

    try {
        $expected = hash_hmac('sha256', '2|16', 'test-salt');

        expect(ClientFence\Tools::signCallbackParams(2, 16))->toBe($expected)
            ->and(ClientFence\Tools::signCallbackParams('2', '16'))->toBe($expected);
    } finally {
        ClientFence\Config::setProperty('info.salt', $previousSalt, false);
    }
});

test('callback signature verification accepts matching signatures only', function (): void {
    $previousSalt = ClientFence\Config::getProperty('info.salt');
    ClientFence\Config::setProperty('info.salt', 'test-salt', false);

    try {
        $sig = ClientFence\Tools::signCallbackParams(2, 16);

        expect(ClientFence\Tools::verifyCallbackSignature(2, 16, $sig))->toBeTrue()
            ->and(ClientFence\Tools::verifyCallbackSignature(2, 99, $sig))->toBeFalse()
            ->and(ClientFence\Tools::verifyCallbackSignature(7, 16, $sig))->toBeFalse()
            ->and(ClientFence\Tools::verifyCallbackSignature(2, 16, 'tampered'))->toBeFalse()
            ->and(ClientFence\Tools::verifyCallbackSignature(2, 16, ''))->toBeFalse()
            ->and(ClientFence\Tools::verifyCallbackSignature(2, 16, null))->toBeFalse()
            ->and(ClientFence\Tools::verifyCallbackSignature(2, 16, 12345))->toBeFalse();
    } finally {
        ClientFence\Config::setProperty('info.salt', $previousSalt, false);
    }
});

test('validate and sanitize email returns the address unescaped', function (): void {
    $tools = new ClientFence\Tools();

    expect($tools->validateAndSanitizeEmail('foo&bar@example.com', true, false))->toBe('foo&bar@example.com');
});

test('Arabic and Persian digits are read as digits in phone numbers', function (): void {
    expect(ClientFence\Tools::latinDigits('٠١٠ ۱۲۳'))->toBe('010 123')
        ->and(ClientFence\Tools::validatePhoneNumber('٠١٠١٢٣٤٥٦٧٨'))->toBe('01012345678');
});

test('withLock runs the work under a MySQL named lock and always releases it', function (): void {
    $db = Mockery::mock(Doctrine\DBAL\Connection::class);
    $db->shouldReceive('getDatabasePlatform')->andReturn(new Doctrine\DBAL\Platforms\MariaDBPlatform());
    $db->shouldReceive('fetchOne')->once()->ordered()->with('SELECT GET_LOCK(?, ?)', ['fb:test', 10])->andReturn(1);
    $db->shouldReceive('fetchOne')->once()->ordered()->with('SELECT RELEASE_LOCK(?)', ['fb:test'])->andReturn(1);
    $di = new Pimple\Container();
    $di['dbal'] = $db;

    expect(fn () => ClientFence\Tools::withLock($di, 'fb:test', function (): never {
        throw new RuntimeException('work failed');
    }))->toThrow(RuntimeException::class, 'work failed');
});

test('withLock refuses when the lock is busy and runs without MySQL', function (): void {
    $db = Mockery::mock(Doctrine\DBAL\Connection::class);
    $db->shouldReceive('getDatabasePlatform')->andReturn(new Doctrine\DBAL\Platforms\MariaDBPlatform());
    $db->shouldReceive('fetchOne')->twice()->with('SELECT GET_LOCK(?, ?)', ['fb:cron', 0])->andReturn(0);
    $di = new Pimple\Container();
    $di['dbal'] = $db;
    $ran = false;

    $work = function () use (&$ran): void {
        $ran = true;
    };

    expect(fn () => ClientFence\Tools::withLock($di, 'fb:cron', $work, 0))->toThrow(ClientFence\InformationException::class)
        ->and(ClientFence\Tools::withLock($di, 'fb:cron', $work, 0, fn (): string => 'skipped'))->toBe('skipped')
        ->and($ran)->toBeFalse()
        ->and(ClientFence\Tools::withLock(new Pimple\Container(), 'fb:x', fn (): int => 7))->toBe(7);
});
