<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence;

use ClientFence\Config;
use ClientFence\InformationException;

/**
 * The config.php parts of Security → Settings (mod_security_settings; Super Administrators only):
 *
 * - Proxies (security.trusted_proxies): requests from these addresses are taken from a proxy such as
 *   Cloudflare, and the visitor's address is read from its header. Without it every visitor
 *   behind the proxy shows as the proxy, for sign-in rules, rate limits and logs.
 * - Access lists: addresses never rate limited (rate_limiter.whitelist_ips) and the addresses API
 *   keys may be used from (api.allowed_ips; the admin and client pages are not affected).
 * - Sessions: the "same browser" check of every signed-in request (security.perform_session_fingerprinting).
 */
final class SecuritySettings
{
    /** Cloudflare's published ranges (cloudflare.com/ips), built in: ClientFence calls no outside service. */
    public const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /** RequestFactory header modes offered: aws_elb trusts X-Forwarded-For/Proto/Port, not the Host header. */
    public const HEADERS = ['aws_elb', 'x_forwarded', 'forwarded'];

    public static function forPage(\Pimple\Container $di): array
    {
        $proxies = Config::getProperty('security.trusted_proxies', []);
        $list = self::stored($proxies['proxies'] ?? []);
        $request = $di['request'];

        return [
            'proxies_on' => !empty($proxies['enabled']),
            'proxies' => $list,
            'headers' => in_array($proxies['headers'] ?? null, self::HEADERS, true) ? $proxies['headers'] : 'aws_elb',
            'cloudflare' => self::CLOUDFLARE,
            'cloudflare_listed' => count(array_intersect(self::CLOUDFLARE, $list)),
            'remote_addr' => (string) $request->server->get('REMOTE_ADDR', ''),
            'client_ip' => (string) $request->getClientIp(),
            'whitelist' => self::stored(Config::getProperty('rate_limiter.whitelist_ips', [])),
            'api_ips' => self::stored(Config::getProperty('api.allowed_ips', [])),
            'fingerprint' => (bool) Config::getProperty('security.perform_session_fingerprinting', true),
        ];
    }

    /** Saves one section (proxies, access or sessions) into config.php. */
    public static function save(\Pimple\Container $di, array $data, int $adminId): void
    {
        $config = Config::getConfig();
        $section = (string) ($data['section'] ?? '');
        switch ($section) {
            case 'proxies':
                $on = !empty($data['proxies_on']);
                $list = self::ranges($data['proxies'] ?? '');
                if ($on && $list === []) {
                    throw new InformationException('Add at least one proxy address.');
                }
                $headers = (string) ($data['headers'] ?? 'aws_elb');
                if (!in_array($headers, self::HEADERS, true)) {
                    throw new InformationException('Choose the header the proxy sends.');
                }
                $config['security']['trusted_proxies'] = ['enabled' => $on, 'proxies' => $list, 'headers' => $headers];

                break;
            case 'access':
                $config['rate_limiter']['whitelist_ips'] = self::ranges($data['whitelist'] ?? '');
                $config['api']['allowed_ips'] = self::ranges($data['api_ips'] ?? '');

                break;
            case 'sessions':
                $config['security']['perform_session_fingerprinting'] = !empty($data['fingerprint']);

                break;
            default:
                throw new InformationException('Unknown settings section.');
        }
        // Read from config.php on every request: no cache to clear.
        Config::setConfig($config, false);
        $di['logger']->info('Security settings (%s) saved by staff #%s', $section, $adminId);
    }

    /**
     * The addresses and ranges of a textarea (one per line, or separated by spaces or commas).
     * Ranges wider than /8 (IPv4) or /16 (IPv6) are refused: trusting or exempting that much is a mistake.
     *
     * @return list<string>
     */
    public static function ranges(mixed $text): array
    {
        $entries = is_array($text) ? $text : preg_split('/[\s,]+/', (string) $text);
        $entries = array_values(array_unique(array_filter(array_map(static fn ($e): string => trim((string) $e), $entries), static fn (string $e): bool => $e !== '')));
        foreach ($entries as $entry) {
            [$ip, $bits] = array_pad(explode('/', $entry, 2), 2, null);
            $v6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            $valid = ($v6 || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)
                && ($bits === null || (ctype_digit($bits) && (int) $bits >= ($v6 ? 16 : 8) && (int) $bits <= ($v6 ? 128 : 32)));
            if (!$valid) {
                throw new InformationException('Not an IP address or range: :ip. Ranges wider than /8 (IPv4) or /16 (IPv6) are not allowed.', [':ip' => $entry]);
            }
        }

        return $entries;
    }

    /** @return list<string> */
    private static function stored(mixed $value): array
    {
        $value = is_string($value) ? explode(',', $value) : (is_array($value) ? $value : []);

        return array_values(array_filter(array_map(static fn ($e): string => trim((string) $e), $value), static fn (string $e): bool => $e !== ''));
    }
}
