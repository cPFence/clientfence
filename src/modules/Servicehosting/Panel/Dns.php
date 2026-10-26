<?php

declare(strict_types=1);

/**
 * ClientFence: whether a hosting account's domains point to its server (panel feature "dns").
 * The panel names the domains and the addresses they should use (manager clientfenceDomains());
 * this class looks the domains up in public DNS from the portal, so the answer is the same for
 * every panel:
 *
 * - website: `ok` (its A/AAAA records are the server's), `cloudflare` (every address is in
 *   Cloudflare's proxy ranges, cloudflare-ranges.json: the server behind it cannot be seen),
 *   `elsewhere` (other addresses) or `none` (no A or AAAA record);
 * - mail: `ok` (an MX host resolves to the mail server), `elsewhere` (MX records point to
 *   another mail service) or `none` (no MX record).
 *
 * Enhance's own DNS status is not used: it calls every Cloudflare-proxied domain "ForeignServer".
 */

namespace ClientFence\Mod\Servicehosting\Panel;

final class Dns
{
    private const RANGES_FILE = __DIR__ . '/cloudflare-ranges.json';

    /** @var ?callable(string $name, int $type): list<array> tests replace the DNS lookups */
    public static $resolver;

    private static ?array $ranges = null;

    /**
     * @param array{domains: list<array{domain: string, kind: string}>, web_ips: list<string>, mail_ips: list<string>, mail_host: string} $site
     *
     * @return list<array{domain: string, kind: string, web: array{status: string, addresses: list<string>}, mail: array{status: string, hosts: list<string>}}>
     */
    public static function check(array $site): array
    {
        $results = [];
        foreach ($site['domains'] as $domain) {
            $results[] = [
                'domain' => $domain['domain'],
                'kind' => $domain['kind'],
                'web' => self::web($domain['domain'], $site['web_ips']),
                'mail' => self::mail($domain['domain'], $site['mail_ips']),
            ];
        }

        return $results;
    }

    /**
     * @param list<string> $serverIps
     *
     * @return array{status: string, addresses: list<string>}
     */
    private static function web(string $domain, array $serverIps): array
    {
        $addresses = self::addresses($domain);
        if ($addresses === []) {
            return ['status' => 'none', 'addresses' => []];
        }
        $server = array_map([self::class, 'normal'], $serverIps);
        $ours = array_filter($addresses, static fn (string $ip): bool => in_array(self::normal($ip), $server, true));
        if (count($ours) === count($addresses)) {
            $status = 'ok';
        } elseif (array_filter($addresses, static fn (string $ip): bool => !self::cloudflare($ip)) === []) {
            $status = 'cloudflare';
        } else {
            $status = 'elsewhere';
        }

        return ['status' => $status, 'addresses' => $addresses];
    }

    /**
     * @param list<string> $mailIps
     *
     * @return array{status: string, hosts: list<string>}
     */
    private static function mail(string $domain, array $mailIps): array
    {
        $records = self::lookup($domain, DNS_MX);
        usort($records, static fn (array $a, array $b): int => (int) ($a['pri'] ?? 0) <=> (int) ($b['pri'] ?? 0));
        $hosts = array_values(array_unique(array_filter(array_map(static fn (array $r): string => strtolower(rtrim((string) ($r['target'] ?? ''), '.')), $records))));
        if ($hosts === []) {
            return ['status' => 'none', 'hosts' => []];
        }
        $mail = array_map([self::class, 'normal'], $mailIps);
        foreach ($hosts as $host) {
            foreach (self::addresses($host) as $ip) {
                if (in_array(self::normal($ip), $mail, true)) {
                    return ['status' => 'ok', 'hosts' => $hosts];
                }
            }
        }

        return ['status' => 'elsewhere', 'hosts' => $hosts];
    }

    /**
     * @return list<string> the A and AAAA addresses of a name
     */
    private static function addresses(string $name): array
    {
        $addresses = [];
        foreach (self::lookup($name, DNS_A) as $record) {
            if (!empty($record['ip'])) {
                $addresses[] = (string) $record['ip'];
            }
        }
        foreach (self::lookup($name, DNS_AAAA) as $record) {
            if (!empty($record['ipv6'])) {
                $addresses[] = (string) $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }

    private static function lookup(string $name, int $type): array
    {
        if (self::$resolver !== null) {
            return (self::$resolver)($name, $type);
        }
        // A name that does not resolve gives a warning and false.
        $records = @dns_get_record($name, $type);

        return is_array($records) ? $records : [];
    }

    private static function cloudflare(string $ip): bool
    {
        self::$ranges ??= json_decode((string) file_get_contents(self::RANGES_FILE), true) ?: [];
        $binary = @inet_pton($ip);
        if ($binary === false) {
            return false;
        }
        foreach (strlen($binary) === 4 ? self::$ranges['ipv4'] ?? [] : self::$ranges['ipv6'] ?? [] as $range) {
            [$network, $bits] = explode('/', (string) $range) + [1 => ''];
            $net = @inet_pton($network);
            if ($net === false || strlen($net) !== strlen($binary)) {
                continue;
            }
            $bits = (int) $bits;
            $bytes = intdiv($bits, 8);
            if (strncmp($binary, $net, $bytes) !== 0) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0 || ((ord($binary[$bytes]) ^ ord($net[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function normal(string $ip): string
    {
        $binary = @inet_pton($ip);

        return $binary === false ? strtolower($ip) : (string) inet_ntop($binary);
    }
}
