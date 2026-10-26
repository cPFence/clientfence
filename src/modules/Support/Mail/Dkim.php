<?php

declare(strict_types=1);

namespace ClientFence\Mod\Support\Mail;

/**
 * ClientFence: checks that an email really comes from the domain in its From address, by its DKIM
 * signature (RFC 6376; rsa-sha256 and ed25519-sha256, RFC 8301 and 8463), against the key the
 * signing domain publishes in DNS.
 *
 * The receiving mail server's Authentication-Results header is not used: a sender can write one
 * too, and a mail server that adds none (or does not remove a sender's copy) cannot be told apart.
 *
 * A signature counts when its domain (d=) is the From domain or a parent of it, it signs the From
 * header, it covers the whole body (no l= shorter than the body), it has not expired and the key
 * verifies it. A message with more than one From header is never verified.
 */
final class Dkim
{
    private const MAX_SIGNATURES = 5;
    private const MIN_RSA_BITS = 1024;

    /** @var \Closure(string): ?string the TXT record of a name, joined ('' or null when there is none) */
    private \Closure $txt;

    /** @var array<string, ?string> */
    private array $keys = [];

    /**
     * @param (callable(string): ?string)|null $txt DNS TXT lookup (tests pass their own)
     */
    public function __construct(?callable $txt = null)
    {
        $this->txt = $txt !== null ? \Closure::fromCallable($txt) : self::dnsTxt(...);
    }

    /**
     * Whether the message is signed by its From domain: [true, ''] or [false, why not].
     *
     * @return array{0: bool, 1: string}
     */
    public function verify(string $raw, string $fromEmail): array
    {
        $from = strtolower((string) substr(strrchr($fromEmail, '@') ?: '', 1));
        if ($from === '') {
            return [false, 'the email has no sender domain'];
        }
        $raw = (string) preg_replace('/\r?\n/', "\r\n", $raw);
        $end = strpos($raw, "\r\n\r\n");
        $head = $end === false ? $raw : substr($raw, 0, $end);
        $body = $end === false ? '' : substr($raw, $end + 4);
        $fields = self::fields($head);

        $froms = count(array_filter($fields, static fn (array $f): bool => $f[0] === 'from'));
        if ($froms !== 1) {
            return [false, 'the email has ' . $froms . ' From headers'];
        }

        $signatures = array_values(array_filter($fields, static fn (array $f): bool => $f[0] === 'dkim-signature'));
        if ($signatures === []) {
            return [false, 'the email has no DKIM signature'];
        }
        $why = 'it has no DKIM signature from ' . $from;
        foreach (array_slice($signatures, 0, self::MAX_SIGNATURES) as $signature) {
            $tags = self::tags(substr($signature[1], (int) strpos($signature[1], ':') + 1));
            $domain = strtolower($tags['d'] ?? '');
            if (!self::aligned($domain, $from)) {
                continue;
            }
            $result = $this->check($signature[1], $tags, $domain, $fields, $body);
            if ($result === '') {
                return [true, ''];
            }
            $why = 'its DKIM signature from ' . $domain . ' ' . $result;
        }

        return [false, $why];
    }

    /** The signing domain is the From domain or a parent of it (never a top-level domain). */
    public static function aligned(string $domain, string $from): bool
    {
        return preg_match('/\A[a-z0-9-]+(\.[a-z0-9-]+)+\z/', $domain) === 1
            && ($domain === $from || str_ends_with($from, '.' . $domain));
    }

    /**
     * '' when the signature verifies, otherwise why not.
     *
     * @param array<string, string>       $tags
     * @param list<array{0: string, 1: string}> $fields
     */
    private function check(string $field, array $tags, string $domain, array $fields, string $body): string
    {
        foreach (['v', 'a', 'b', 'bh', 'h', 's'] as $required) {
            if (($tags[$required] ?? '') === '') {
                return 'is incomplete';
            }
        }
        if ($tags['v'] !== '1') {
            return 'has an unknown version';
        }
        $algorithm = strtolower($tags['a']);
        if (!in_array($algorithm, ['rsa-sha256', 'ed25519-sha256'], true)) {
            return 'uses an algorithm that is not accepted (' . $algorithm . ')';
        }
        $signed = array_map(static fn (string $h): string => strtolower(trim($h)), explode(':', $tags['h']));
        if (!in_array('from', $signed, true)) {
            return 'does not sign the From header';
        }
        if (isset($tags['i'])) {
            $agent = strtolower((string) substr(strrchr($tags['i'], '@') ?: '', 1));
            if ($agent !== $domain && !str_ends_with($agent, '.' . $domain)) {
                return 'has an identity outside its domain';
            }
        }
        if (isset($tags['x']) && ctype_digit($tags['x']) && (int) $tags['x'] < time()) {
            return 'has expired';
        }
        $selector = strtolower($tags['s']);
        if (preg_match('/\A[a-z0-9_-]+(\.[a-z0-9_-]+)*\z/', $selector) !== 1) {
            return 'has an invalid selector';
        }
        [$headerCanon, $bodyCanon] = array_pad(explode('/', strtolower($tags['c'] ?? 'simple/simple'), 2), 2, 'simple');
        if (!in_array($headerCanon, ['simple', 'relaxed'], true) || !in_array($bodyCanon, ['simple', 'relaxed'], true)) {
            return 'uses an unknown canonicalization';
        }

        $canonicalBody = self::canonicalBody($body, $bodyCanon);
        if (isset($tags['l']) && (!ctype_digit($tags['l']) || (int) $tags['l'] !== strlen($canonicalBody))) {
            return 'does not cover the whole message';
        }
        if (!hash_equals(base64_encode(hash('sha256', $canonicalBody, true)), $tags['bh'])) {
            return 'does not match the message body';
        }

        // Each signed header name takes the next instance from the bottom (RFC 6376 5.4.2).
        $data = '';
        $used = [];
        foreach ($signed as $name) {
            $used[$name] ??= 0;
            $instances = array_values(array_filter($fields, static fn (array $f): bool => $f[0] === $name));
            $index = count($instances) - 1 - $used[$name];
            if ($index < 0) {
                continue;
            }
            ++$used[$name];
            $data .= self::canonicalHeader($instances[$index][1], $headerCanon) . "\r\n";
        }
        $self = (string) preg_replace('/((?:^|[;:])\s*b\s*=)[^;]*/', '$1', $field, 1);
        $data .= self::canonicalHeader($self, $headerCanon);

        $record = $this->key($selector . '._domainkey.' . $domain);
        if ($record === null) {
            return 'has no published key';
        }
        $key = self::tags($record);
        if (isset($key['v']) && $key['v'] !== 'DKIM1') {
            return 'has an invalid key record';
        }
        if (($key['p'] ?? '') === '') {
            return 'has a revoked key';
        }
        if (isset($key['h']) && !in_array('sha256', array_map(trim(...), explode(':', strtolower($key['h']))), true)) {
            return 'is not allowed by its key';
        }
        $flags = array_map(trim(...), explode(':', strtolower($key['t'] ?? '')));
        if (in_array('y', $flags, true)) {
            return 'comes from a domain that is testing DKIM';
        }
        if (in_array('s', $flags, true) && isset($tags['i']) && strtolower((string) substr(strrchr($tags['i'], '@') ?: '', 1)) !== $domain) {
            return 'is not allowed by its key';
        }
        $keyType = strtolower($key['k'] ?? 'rsa');
        $signature = base64_decode($tags['b'], true);
        $public = base64_decode($key['p'], true);
        if ($signature === false || $public === false) {
            return 'is not valid base64';
        }

        if ($algorithm === 'rsa-sha256') {
            if ($keyType !== 'rsa') {
                return 'does not match its key type';
            }
            $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($public), 64, "\n") . "-----END PUBLIC KEY-----\n";
            $pkey = @openssl_pkey_get_public($pem);
            $details = $pkey === false ? false : openssl_pkey_get_details($pkey);
            if ($pkey === false || $details === false || ($details['bits'] ?? 0) < self::MIN_RSA_BITS) {
                return 'has an unusable key';
            }

            return openssl_verify($data, $signature, $pkey, OPENSSL_ALGO_SHA256) === 1 ? '' : 'does not verify';
        }
        if ($keyType !== 'ed25519' || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return 'has an unusable key';
        }

        return sodium_crypto_sign_verify_detached($signature, hash('sha256', $data, true), $public) ? '' : 'does not verify';
    }

    private function key(string $name): ?string
    {
        if (!array_key_exists($name, $this->keys)) {
            $record = ($this->txt)($name);
            $this->keys[$name] = $record === null || $record === '' ? null : $record;
        }

        return $this->keys[$name];
    }

    private static function dnsTxt(string $name): ?string
    {
        $records = @dns_get_record($name, DNS_TXT);
        if (!is_array($records)) {
            return null;
        }
        foreach ($records as $record) {
            $text = isset($record['entries']) && is_array($record['entries']) ? implode('', $record['entries']) : (string) ($record['txt'] ?? '');
            if (str_contains($text, 'p=')) {
                return $text;
            }
        }

        return null;
    }

    /**
     * The header fields as sent (folding kept), with their lower-case names, top to bottom.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function fields(string $head): array
    {
        $fields = [];
        foreach (explode("\r\n", $head) as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t") && $fields !== []) {
                $fields[count($fields) - 1][1] .= "\r\n" . $line;
            } elseif (str_contains($line, ':')) {
                $fields[] = [strtolower(rtrim(substr($line, 0, (int) strpos($line, ':')))), $line];
            }
        }

        return $fields;
    }

    /** @return array<string, string> tag => value, white space removed */
    private static function tags(string $list): array
    {
        $tags = [];
        foreach (explode(';', $list) as $pair) {
            $at = strpos($pair, '=');
            if ($at === false) {
                continue;
            }
            $name = trim(substr($pair, 0, $at));
            if ($name !== '' && !isset($tags[$name])) {
                $tags[$name] = (string) preg_replace('/\s+/', '', substr($pair, $at + 1));
            }
        }

        return $tags;
    }

    private static function canonicalHeader(string $field, string $canon): string
    {
        if ($canon === 'simple') {
            return $field;
        }
        $at = (int) strpos($field, ':');
        $value = (string) preg_replace('/[ \t]+/', ' ', str_replace("\r\n", '', substr($field, $at + 1)));

        return strtolower(rtrim(substr($field, 0, $at))) . ':' . trim($value, " \t");
    }

    private static function canonicalBody(string $body, string $canon): string
    {
        if ($canon === 'relaxed') {
            $lines = explode("\r\n", $body);
            foreach ($lines as $i => $line) {
                $lines[$i] = rtrim((string) preg_replace('/[ \t]+/', ' ', $line), " \t");
            }
            $body = implode("\r\n", $lines);
        }
        $body = (string) preg_replace('/(\r\n)+\z/', '', $body);
        if ($body === '') {
            return $canon === 'relaxed' ? '' : "\r\n";
        }

        return $body . "\r\n";
    }
}
