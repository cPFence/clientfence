<?php

declare(strict_types=1);

namespace ClientFence\Mod\Servicednshosting\Provider;

use ClientFence\InformationException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

abstract class Provider implements DnsHostingProviderInterface
{
    public function __construct(
        private readonly HttpClientInterface $http,
        protected readonly array $config,
    ) {
    }

    public function dnssec(string $zone): array
    {
        return ['supported' => false];
    }

    public function setDnssec(string $zone, bool $enabled): void
    {
        throw new InformationException('This DNS provider does not allow changing DNSSEC.');
    }

    protected function request(string $method, string $url, array $options, ?int $expected, bool $allowMissing = false, ?array &$responseHeaders = null): mixed
    {
        try {
            $response = $this->http->request($method, $url, $options + [
                'max_redirects' => 0,
                'timeout' => 15,
                'max_duration' => 20,
            ]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
            if ($responseHeaders !== null) {
                $responseHeaders = $response->getHeaders(false);
            }
        } catch (ExceptionInterface) {
            throw new InformationException('The DNS provider could not be reached.', null, 502, true);
        }

        if ($allowMissing && $status === 404 && is_array(json_decode($content, true))) {
            return null;
        }
        if ($expected === null ? $status < 200 || $status >= 300 : $status !== $expected) {
            throw new InformationException('The DNS provider refused the request (HTTP :status).', [':status' => $status], 502, true);
        }
        if ($status === 204) {
            return [];
        }

        $data = json_decode($content, true);
        $this->require(json_last_error() === JSON_ERROR_NONE);

        return $data;
    }

    protected function require(bool $condition): void
    {
        if (!$condition) {
            throw new InformationException('The DNS provider returned an unsuccessful or unreadable response.', null, 502, true);
        }
    }

    protected static function fqdn(string $name, string $domain): string
    {
        return ($name === '' ? '' : $name . '.') . $domain;
    }

    protected static function relative(string $name, string $domain): string
    {
        $name = rtrim($name, '.');

        return $name === $domain || $name === '@' ? '' : (str_ends_with($name, '.' . $domain) ? substr($name, 0, -strlen($domain) - 1) : $name);
    }

    protected static function record(string $id, string $name, string $type, string $value, int $ttl, ?int $priority = null): array
    {
        return compact('id', 'name', 'type', 'value', 'ttl', 'priority');
    }

    protected static function rrRecord(string $name, string $type, string $rdata, int $ttl): array
    {
        [$priority, $value] = $type === 'MX' ? explode(' ', $rdata, 2) : [null, $rdata];
        if (in_array($type, ['TXT', 'SPF'], true)) {
            $value = self::txtValue($value);
        }

        return self::record(hash('sha256', $name . "\0" . $type . "\0" . $rdata), $name, $type, $value, $ttl, $priority === null ? null : (int) $priority)
            + ['rdata' => $rdata];
    }

    /** A TXT value in DNS form ("a" "b") as one value: receivers join its strings. */
    public static function txtValue(string $value): string
    {
        if (!preg_match('/^\s*"(?:[^"\\\\]|\\\\.)*"(?:\s+"(?:[^"\\\\]|\\\\.)*")*\s*$/sD', $value)) {
            return $value;
        }
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/s', $value, $parts);

        return preg_replace('/\\\\(["\\\\])/', '$1', implode('', $parts[1]));
    }

    protected static function rdata(array $record): string
    {
        $value = $record['value'];

        return match ($record['type']) {
            // One DNS string holds at most 255 bytes, so a longer value (a DKIM key) goes as several.
            'TXT', 'SPF' => '"' . implode('" "', array_map(static fn (string $part): string => addcslashes($part, "\\\\\""), str_split($value, 255))) . '"',
            'MX' => $record['priority'] . ' ' . rtrim($value, '.') . '.',
            'CNAME' => rtrim($value, '.') . '.',
            default => $value,
        };
    }
}
