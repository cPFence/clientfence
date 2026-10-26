<?php

declare(strict_types=1);

namespace ClientFence\Mod\Support\Mail;

/**
 * ClientFence: reads an email as an IMAP server returns it (RFC 5322 and MIME) into what a ticket
 * needs: sender, recipients, subject, the text (the plain part, else the HTML part as text) and the
 * attachments. Built on PHP's own functions (no mailparse extension, no library).
 *
 * Header words in other character sets (RFC 2047) and file names (RFC 2231) are decoded; every
 * text comes back as valid UTF-8. Nesting and the number of parts are limited, so a crafted
 * message cannot make the import loop or use much memory.
 */
final class MimeMessage
{
    private const MAX_DEPTH = 10;
    private const MAX_PARTS = 200;

    /** @var array<string, string[]> lower-case name => values as sent (unfolded) */
    public array $headers = [];
    /** ClientFence: the whole email as received (for its DKIM check). */
    public string $raw = '';
    /** @var array{email: string, name: string} */
    public array $from = ['email' => '', 'name' => ''];
    /** @var array<int, array{email: string, name: string}> */
    public array $to = [];
    /** @var array<int, array{email: string, name: string}> */
    public array $cc = [];
    public string $subject = '';
    public string $text = '';
    public string $html = '';
    /** @var array<int, array{name: string, mime: string, content: string}> */
    public array $attachments = [];

    private int $parts = 0;

    public static function parse(string $raw): self
    {
        $message = new self();
        $message->raw = $raw;
        [$headers, $body] = self::split($raw);
        $message->headers = $headers;
        $message->subject = self::decodeHeader($message->header('subject'));
        $from = self::addresses($message->header('from'));
        $message->from = $from[0] ?? ['email' => '', 'name' => ''];
        $message->to = self::addresses(implode(', ', $headers['to'] ?? []));
        $message->cc = self::addresses(implode(', ', $headers['cc'] ?? []));
        $message->walk($headers, $body, 0);
        if ($message->text === '' && $message->html !== '') {
            $message->text = self::htmlToText($message->html);
        }

        return $message;
    }

    /** The first value of a header as sent ('' when missing). */
    public function header(string $name): string
    {
        return $this->headers[strtolower($name)][0] ?? '';
    }

    /**
     * Splits a message or part into its headers (unfolded, lower-case names) and its body.
     *
     * @return array{0: array<string, string[]>, 1: string}
     */
    public static function split(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $end = strpos($raw, "\n\n");
        $head = $end === false ? $raw : substr($raw, 0, $end);
        $body = $end === false ? '' : substr($raw, $end + 2);
        $headers = [];
        $current = null;
        foreach (explode("\n", $head) as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
                $last = count($headers[$current]) - 1;
                $headers[$current][$last] .= ' ' . trim($line);

                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) {
                $current = null;

                continue;
            }
            $current = strtolower(trim(substr($line, 0, $colon)));
            $headers[$current][] = trim(substr($line, $colon + 1));
        }

        return [$headers, $body];
    }

    /** Decodes encoded words (=?charset?B|Q?...?=) into UTF-8. */
    public static function decodeHeader(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (str_contains($value, '=?')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if ($decoded === false) {
                $decoded = mb_decode_mimeheader($value);
            }
            $value = $decoded;
        }

        return trim(self::utf8($value, 'UTF-8'));
    }

    /**
     * A header's main value and its parameters (lower-case names; RFC 2231 continuations and
     * charsets joined and decoded).
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function params(string $value): array
    {
        $pieces = [];
        $buffer = '';
        $quoted = false;
        $length = strlen($value);
        for ($i = 0; $i < $length; ++$i) {
            $char = $value[$i];
            if ($char === '\\' && $quoted && $i + 1 < $length) {
                $buffer .= $value[++$i];

                continue;
            }
            if ($char === '"') {
                $quoted = !$quoted;

                continue;
            }
            if ($char === ';' && !$quoted) {
                $pieces[] = $buffer;
                $buffer = '';

                continue;
            }
            $buffer .= $char;
        }
        $pieces[] = $buffer;
        $main = strtolower(trim((string) array_shift($pieces)));
        $params = [];
        $extended = [];
        foreach ($pieces as $piece) {
            $equals = strpos($piece, '=');
            if ($equals === false) {
                continue;
            }
            $name = strtolower(trim(substr($piece, 0, $equals)));
            $val = trim(substr($piece, $equals + 1));
            if (preg_match('/^([a-z0-9_.-]+)\*(\d+)?(\*)?$/', $name, $m) === 1) {
                $extended[$m[1]][(int) ($m[2] ?? 0)] = [$val, isset($m[3]) || ($m[2] ?? '') === ''];

                continue;
            }
            $params[$name] = $val;
        }
        foreach ($extended as $name => $chunks) {
            ksort($chunks);
            $charset = 'UTF-8';
            $joined = '';
            foreach ($chunks as $index => [$chunk, $encoded]) {
                if ($encoded) {
                    if ($index === 0 && preg_match("/^([^']*)'[^']*'(.*)$/", $chunk, $m) === 1) {
                        $charset = $m[1] !== '' ? $m[1] : 'UTF-8';
                        $chunk = $m[2];
                    }
                    $chunk = rawurldecode($chunk);
                }
                $joined .= $chunk;
            }
            $params[$name] = self::utf8($joined, $charset);
        }
        foreach ($params as $name => $val) {
            $params[$name] = self::decodeHeader($val);
        }

        return [$main, $params];
    }

    /**
     * Addresses of a From, To or Cc header.
     *
     * @return array<int, array{email: string, name: string}>
     */
    public static function addresses(string $value): array
    {
        $list = [];
        $buffer = '';
        $quoted = false;
        $angle = false;
        $length = strlen($value);
        for ($i = 0; $i < $length; ++$i) {
            $char = $value[$i];
            if ($char === '"' && ($i === 0 || $value[$i - 1] !== '\\')) {
                $quoted = !$quoted;
            } elseif ($char === '<' && !$quoted) {
                $angle = true;
            } elseif ($char === '>' && !$quoted) {
                $angle = false;
            } elseif (($char === ',' || $char === ';') && !$quoted && !$angle) {
                $list[] = $buffer;
                $buffer = '';

                continue;
            }
            $buffer .= $char;
        }
        $list[] = $buffer;
        $addresses = [];
        foreach ($list as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            if (preg_match('/^(.*)<([^<>]*)>\s*$/s', $item, $m) === 1) {
                $email = trim($m[2]);
                $name = trim(trim($m[1]), '"');
            } else {
                $email = trim(preg_replace('/\(.*?\)/', '', $item) ?? $item);
                $name = '';
            }
            $email = strtolower($email);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            $addresses[] = ['email' => $email, 'name' => self::decodeHeader(stripslashes($name))];
        }

        return $addresses;
    }

    /** Plain text from an HTML body: paragraphs and line breaks kept, scripts and styles dropped. */
    public static function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|li|tr|h[1-6]|blockquote|pre|table)\s*>#i', "\n", $html) ?? $html;
        $html = preg_replace('#<li\b[^>]*>#i', '- ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /** Converts text in $charset to valid UTF-8 (unknown character sets are read as UTF-8). */
    public static function utf8(string $text, string $charset): string
    {
        $charset = strtoupper(trim($charset)) ?: 'UTF-8';
        if (in_array($charset, ['US-ASCII', 'ASCII', 'UTF8'], true)) {
            $charset = 'UTF-8';
        }
        if ($charset !== 'UTF-8') {
            $converted = in_array($charset, array_map('strtoupper', mb_list_encodings()), true)
                ? @mb_convert_encoding($text, 'UTF-8', $charset)
                : @iconv($charset, 'UTF-8//IGNORE', $text);
            if (is_string($converted)) {
                $text = $converted;
            }
        }

        return mb_scrub($text, 'UTF-8');
    }

    /**
     * @param array<string, string[]> $headers
     */
    private function walk(array $headers, string $body, int $depth): void
    {
        if (++$this->parts > self::MAX_PARTS || $depth > self::MAX_DEPTH) {
            return;
        }
        [$type, $typeParams] = self::params($headers['content-type'][0] ?? 'text/plain');
        [$disposition, $dispositionParams] = self::params($headers['content-disposition'][0] ?? '');
        if (str_starts_with($type, 'multipart/') && ($typeParams['boundary'] ?? '') !== '') {
            foreach (self::multipart($body, $typeParams['boundary']) as $part) {
                [$partHeaders, $partBody] = self::split($part);
                $this->walk($partHeaders, $partBody, $depth + 1);
            }

            return;
        }
        $content = self::decodeBody($body, strtolower(trim($headers['content-transfer-encoding'][0] ?? '')));
        $name = $dispositionParams['filename'] ?? $typeParams['name'] ?? '';
        $isAttachment = $disposition === 'attachment' || $name !== '' || $type === 'message/rfc822';
        if (!$isAttachment && $type === 'text/plain' && $this->text === '') {
            $this->text = trim(self::utf8($content, $typeParams['charset'] ?? 'UTF-8'));

            return;
        }
        if (!$isAttachment && $type === 'text/html' && $this->html === '') {
            $this->html = self::utf8($content, $typeParams['charset'] ?? 'UTF-8');

            return;
        }
        if (!$isAttachment && str_starts_with($type, 'text/')) {
            return;
        }
        if ($name === '') {
            $name = $type === 'message/rfc822' ? 'message.eml' : 'attachment';
        }
        $this->attachments[] = ['name' => $name, 'mime' => $type, 'content' => $content];
    }

    /** @return string[] */
    private static function multipart(string $body, string $boundary): array
    {
        $parts = [];
        $current = null;
        foreach (explode("\n", $body) as $line) {
            $trimmed = rtrim($line, "\r");
            if ($trimmed === '--' . $boundary . '--') {
                break;
            }
            if ($trimmed === '--' . $boundary) {
                if ($current !== null) {
                    $parts[] = $current;
                }
                $current = '';

                continue;
            }
            if ($current !== null) {
                $current .= $line . "\n";
            }
        }
        if ($current !== null) {
            $parts[] = $current;
        }

        // Each part ends with the line break that belongs to the next boundary.
        return array_map(static fn (string $part): string => str_ends_with($part, "\n") ? substr($part, 0, -1) : $part, $parts);
    }

    private static function decodeBody(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64' => (string) base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $body) ?? '', false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }
}
