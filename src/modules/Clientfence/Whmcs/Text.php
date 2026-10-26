<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Whmcs;

use ClientFence\Sanitizer\BrowserHtmlSanitizer;

/**
 * ClientFence: WHMCS text as ClientFence shows it. ClientFence renders tickets, the knowledge base
 * and news as Markdown with raw HTML escaped (ClientFenceMarkdown), while WHMCS keeps knowledge base
 * articles and announcements as HTML (its editor) and shows a single line break in a ticket message
 * as a break. markdown() turns HTML into Markdown; breaks() keeps a message's line breaks.
 */
final class Text
{
    /** Block elements: their content stands on its own lines. */
    private const BLOCKS = ['p', 'div', 'section', 'article', 'header', 'footer', 'main', 'aside', 'figure', 'figcaption', 'address', 'center', 'dl', 'dt', 'dd'];

    /**
     * Markdown of a text that may be HTML; text without tags comes back as it is.
     */
    public static function markdown(string $html): string
    {
        if (preg_match('/<\s*[a-z][^>]*>/i', $html) !== 1) {
            return trim($html);
        }
        $clean = BrowserHtmlSanitizer::sanitizeAdapterHtml($html);
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"?><body>' . $clean . '</body>', LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $body = $dom->getElementsByTagName('body')->item(0);
        $out = $body === null ? '' : self::children($body, 0);
        $out = (string) preg_replace("/[ \t]+\n/", "\n", $out);
        $out = (string) preg_replace("/\n{3,}/", "\n\n", $out);

        return trim($out);
    }

    /**
     * A message whose single line breaks show as breaks (WHMCS shows them so; Markdown would join the
     * lines). Fenced code is left as it is.
     */
    public static function breaks(string $text): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
        $fenced = false;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
                $fenced = !$fenced;

                continue;
            }
            $next = $lines[$i + 1] ?? '';
            if (!$fenced && trim($line) !== '' && trim($next) !== '' && preg_match('/^\s*(```|~~~)/', $next) !== 1 && !str_ends_with($line, '  ')) {
                $lines[$i] = rtrim($line) . '  ';
            }
        }

        return implode("\n", $lines);
    }

    private static function children(\DOMNode $node, int $depth): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= self::node($child, $depth);
        }

        return $out;
    }

    private static function node(\DOMNode $node, int $depth): string
    {
        if ($node instanceof \DOMText) {
            return self::escape((string) preg_replace('/\s+/u', ' ', $node->textContent));
        }
        if (!$node instanceof \DOMElement) {
            return '';
        }
        $tag = strtolower($node->tagName);
        $inner = static fn (): string => self::children($node, $depth);

        return match (true) {
            in_array($tag, self::BLOCKS, true) => "\n\n" . trim($inner()) . "\n\n",
            $tag === 'br' => "\\\n",
            $tag === 'hr' => "\n\n---\n\n",
            (bool) preg_match('/^h([1-6])$/', $tag, $m) => "\n\n" . str_repeat('#', (int) $m[1]) . ' ' . trim((string) preg_replace('/\s+/u', ' ', $inner())) . "\n\n",
            in_array($tag, ['strong', 'b'], true) => self::wrap($inner(), '**'),
            in_array($tag, ['em', 'i'], true) => self::wrap($inner(), '*'),
            in_array($tag, ['del', 's', 'strike'], true) => self::wrap($inner(), '~~'),
            $tag === 'code' => '`' . str_replace('`', "'", $node->textContent) . '`',
            $tag === 'pre' => "\n\n```\n" . rtrim(str_replace('```', "'''", $node->textContent)) . "\n```\n\n",
            $tag === 'a' => self::link($node, $inner()),
            $tag === 'img' => self::image($node),
            $tag === 'ul', $tag === 'ol' => self::listOf($node, $tag === 'ol', $depth),
            $tag === 'blockquote' => "\n\n" . (string) preg_replace('/^/m', '> ', trim($inner())) . "\n\n",
            $tag === 'table' => self::table($node),
            default => $inner(),
        };
    }

    private static function wrap(string $text, string $mark): string
    {
        $trimmed = trim($text);

        return $trimmed === '' ? $text : $mark . $trimmed . $mark . (str_ends_with($text, ' ') ? ' ' : '');
    }

    private static function link(\DOMElement $node, string $text): string
    {
        $href = trim($node->getAttribute('href'));
        $text = trim($text);
        if ($href === '' || preg_match('#^(https?:|mailto:|/|\#)#i', $href) !== 1) {
            return $text;
        }

        return '[' . ($text === '' ? self::escape($href) : $text) . '](' . str_replace([' ', ')', '('], ['%20', '%29', '%28'], $href) . ')';
    }

    private static function image(\DOMElement $node): string
    {
        $src = trim($node->getAttribute('src'));
        if ($src === '' || preg_match('#^(https?:|/)#i', $src) !== 1) {
            return '';
        }

        return '![' . self::escape(trim($node->getAttribute('alt'))) . '](' . str_replace([' ', ')', '('], ['%20', '%29', '%28'], $src) . ')';
    }

    private static function listOf(\DOMElement $node, bool $ordered, int $depth): string
    {
        $out = '';
        $n = 0;
        foreach ($node->childNodes as $item) {
            if (!$item instanceof \DOMElement || strtolower($item->tagName) !== 'li') {
                continue;
            }
            $marker = $ordered ? (++$n) . '. ' : '- ';
            $text = trim((string) preg_replace("/\n{2,}/", "\n", self::children($item, $depth + 1)));
            $indent = str_repeat('  ', $depth);
            $out .= $indent . $marker . str_replace("\n", "\n" . $indent . str_repeat(' ', strlen($marker)), $text) . "\n";
        }

        return ($depth === 0 ? "\n\n" : "\n") . $out . ($depth === 0 ? "\n" : '');
    }

    private static function table(\DOMElement $node): string
    {
        $rows = [];
        foreach ($node->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof \DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                    $cells[] = str_replace('|', '\|', trim((string) preg_replace('/\s+/u', ' ', self::children($cell, 0))));
                }
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }
        if ($rows === []) {
            return '';
        }
        $width = max(array_map('count', $rows));
        $lines = [];
        foreach ($rows as $i => $cells) {
            $lines[] = '| ' . implode(' | ', array_pad($cells, $width, '')) . ' |';
            if ($i === 0) {
                $lines[] = '|' . str_repeat(' --- |', $width);
            }
        }

        return "\n\n" . implode("\n", $lines) . "\n\n";
    }

    private static function escape(string $text): string
    {
        return (string) preg_replace('/([\\\\`*_\[\]<>])/', '\\\\$1', $text);
    }
}
