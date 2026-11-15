<?php

declare(strict_types=1);

namespace ClientFence\Mod\Antispam;

use ClientFence\Config;
use ClientFence\GeoIP\IncompleteRecord;
use ClientFence\GeoIP\Reader;
use ClientFence\InformationException;
use ClientFence\Mod\Order\Holds;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * ClientFence: sign-ups and orders from hosting and data-centre networks (CF-119; System > Settings > Security >
 * Proxies, `security.hosting_networks` in config.php). Off (default), Hold for review (sign-up allowed, the
 * order waits in Order\Holds) or Block (sign-up and checkout refused). A network is the visitor's ASN from the
 * bundled GeoIP ASN database: hosting when it is on the bundled list (X4BNet lists_vpn) or the staff "always"
 * list, and not on the "never" list (pre-filled with Akamai, Fastly and Cloudflare, which carry iCloud Private
 * Relay). An address without an ASN record passes. Staff can let one client through (`allowed()`). Off costs
 * nothing; on, one local database lookup.
 */
final class Networks
{
    public const OFF = 'off';
    public const HOLD = 'hold';
    public const BLOCK = 'block';

    /** Cloudflare, Akamai (the Private Relay network) and Fastly. */
    public const NEVER = [13335, 36183, 54113];

    /** The per-client switch: an extension_meta row of the client. */
    private const META = 'hosting_network_allowed';

    /** @return array{mode: string, always: list<int>, never: list<int>} */
    public static function settings(): array
    {
        $saved = (array) Config::getProperty('security.hosting_networks', []);
        $mode = $saved['mode'] ?? null;

        return [
            'mode' => in_array($mode, [self::HOLD, self::BLOCK], true) ? $mode : self::OFF,
            'always' => array_map(intval(...), (array) ($saved['always'] ?? [])),
            'never' => array_map(intval(...), (array) ($saved['never'] ?? self::NEVER)),
        ];
    }

    /** Block: refuses a sign-up (no `$clientId`) or a checkout from a hosting network. */
    public static function refuse(\Pimple\Container $di, string $ip, ?int $clientId): void
    {
        $settings = self::settings();
        if ($settings['mode'] !== self::BLOCK) {
            return;
        }
        $network = self::match($settings, $ip);
        if ($network !== null && !($clientId !== null && self::allowed($di, $clientId))) {
            $di['logger']->setChannel('security')->info('Anti-Spam: refused a sign-up or order from hosting network %s', $network);

            throw new InformationException('Sign-ups and orders from hosting, VPN or proxy networks are not accepted. Please turn off your VPN or proxy and try again, or contact us.');
        }
    }

    /**
     * Hold for review: why a checkout's orders wait for staff (Order\Holds), or null.
     *
     * @return array{reason: string, note: string}|null
     */
    public static function hold(\Pimple\Container $di, string $ip, int $clientId): ?array
    {
        $settings = self::settings();
        if ($settings['mode'] !== self::HOLD) {
            return null;
        }
        $network = self::match($settings, $ip);

        return $network === null || self::allowed($di, $clientId) ? null : ['reason' => Holds::REASON_NETWORK, 'note' => $network];
    }

    /** "ORG (AS123)" when the address is on a hosting network, or null. */
    public static function match(array $settings, string $ip): ?string
    {
        try {
            $asn = (new Reader(Reader::getAsnDatabase()))->asn($ip);
        } catch (IncompleteRecord | \InvalidArgumentException) {
            // No ASN record, or no address: not known as hosting.
            return null;
        }
        $number = (int) $asn->asnNumber;
        if (in_array($number, $settings['never'], true) || !(in_array($number, $settings['always'], true) || isset(self::bundled()[$number]))) {
            return null;
        }

        return $asn->asnOrg . ' (AS' . $number . ')';
    }

    /**
     * The bundled list: one network per line, "AS24940 # HETZNER-AS, DE" (X4BNet's ASN.txt format) or a bare
     * number; `#` starts a comment. Read once per request.
     *
     * @return array<int, true>
     */
    public static function bundled(?string $file = null): array
    {
        static $lists = [];
        $file ??= Path::join(PATH_LIBRARY, 'ClientFence', 'GeoIP', 'Databases', 'hosting-asn.txt');
        if (!isset($lists[$file])) {
            $filesystem = new Filesystem();
            preg_match_all('/^\s*(?:AS)?(\d+)/im', $filesystem->exists($file) ? $filesystem->readFile($file) : '', $matches);
            $lists[$file] = array_fill_keys(array_map(intval(...), $matches[1]), true);
        }

        return $lists[$file];
    }

    /**
     * Network numbers typed by staff ("AS24940", "24940"), one per line or separated by spaces or commas.
     *
     * @return list<int>
     */
    public static function numbers(mixed $text): array
    {
        $numbers = [];
        foreach (preg_split('/[\s,]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            if (preg_match('/^(?:AS)?(\d{1,10})$/i', $entry, $m) !== 1) {
                throw new InformationException('Not a network number: :asn. Use numbers such as AS24940.', [':asn' => $entry]);
            }
            $numbers[] = (int) $m[1];
        }

        return array_values(array_unique($numbers));
    }

    public static function allowed(\Pimple\Container $di, int $clientId): bool
    {
        return $di['dbal']->fetchOne("SELECT 1 FROM extension_meta WHERE client_id = ? AND extension = 'mod_antispam' AND meta_key = ?", [$clientId, self::META]) !== false;
    }

    /** Staff let a client order from hosting networks, or stop it. */
    public static function allow(\Pimple\Container $di, int $clientId, bool $allowed): void
    {
        $db = $di['dbal'];
        $db->delete('extension_meta', ['client_id' => $clientId, 'extension' => 'mod_antispam', 'meta_key' => self::META]);
        if ($allowed) {
            $now = date('Y-m-d H:i:s');
            $db->insert('extension_meta', ['client_id' => $clientId, 'extension' => 'mod_antispam', 'meta_key' => self::META, 'meta_value' => '1', 'created_at' => $now, 'updated_at' => $now]);
        }
    }
}
