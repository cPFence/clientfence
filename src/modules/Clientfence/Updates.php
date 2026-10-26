<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence;

use ClientFence\InformationException;
use ClientFence\Version;

/**
 * ClientFence release notices: the release feed on clientfence.com, checked once a day by the cron
 * (on by default; System → Update turns it off), a dashboard banner and one email per new release
 * to the staff who may update the system. System → Update installs the release with
 * \ClientFence\Update::performUpdate(), which downloads the archive the feed names and checks its SHA-256.
 *
 * The feed is a JSON object:
 *   {"version": "1.1.0", "release_date": "2026-11-01", "minimum_php_version": "8.3",
 *    "download_url": "https://clientfence.com/releases/clientfence-1.1.0.zip",
 *    "sha256": "<64 hex digits>", "notes": "Markdown release notes"}
 * The archive must sit in the feed's own folder. CLIENTFENCE_UPDATE_FEED overrides the feed
 * address (tests and staging).
 *
 * Beta releases (opt in on System → Update, off by default, not for production): beta.json in the
 * same folder, in the same format. A site on the beta channel takes the newer of the two feeds, so it
 * moves on to a stable release once that is newer than its beta. A beta becomes stable unchanged:
 * its feed entry is copied to latest.json. Without a beta.json (404) only the stable feed counts.
 *
 * Only the cron and System → Update reach the network; the banner reads the last saved result.
 */
final class Updates
{
    public const FEED_URL = 'https://clientfence.com/releases/latest.json';
    /** The `setting` row that switches the daily check off ('0'). */
    public const SETTING = 'clientfence_update_check';
    /** The `setting` row with the release channel: 'stable' (default) or 'beta'. */
    public const CHANNEL_SETTING = 'clientfence_update_channel';
    public const CHANNELS = ['stable', 'beta'];
    public const CHECK_EVERY = 86400;
    private const STATE = 'update_feed';
    private const NOTIFIED = 'update_notified';
    private const MAX_FEED_BYTES = 1048576;
    // The state row is a TEXT column (64 KB): Arabic notes take two bytes a character.
    private const MAX_NOTES = 20000;

    public static function feedUrl(): string
    {
        return (string) (getenv('CLIENTFENCE_UPDATE_FEED') ?: self::FEED_URL);
    }

    /** The beta feed: beta.json next to the stable feed. */
    public static function betaFeedUrl(): string
    {
        return self::trustedPrefix() . 'beta.json';
    }

    /** Release archives must come from the feed's own folder. */
    public static function trustedPrefix(?string $feedUrl = null): string
    {
        $feedUrl ??= self::feedUrl();

        return substr($feedUrl, 0, (int) strrpos($feedUrl, '/') + 1);
    }

    public static function enabled(\Pimple\Container $di): bool
    {
        return (string) $di['mod_service']('system')->getParamValue(self::SETTING, '1') !== '0';
    }

    public static function setEnabled(\Pimple\Container $di, bool $on): void
    {
        $di['mod_service']('system')->setParamValue(self::SETTING, $on ? '1' : '0');
    }

    public static function channel(\Pimple\Container $di): string
    {
        return (string) $di['mod_service']('system')->getParamValue(self::CHANNEL_SETTING, 'stable') === 'beta' ? 'beta' : 'stable';
    }

    /**
     * Switches the release channel and forgets the release the last check found: it may be a beta
     * the stable channel must not offer. The next check reads the feeds again.
     */
    public static function setChannel(\Pimple\Container $di, string $channel): void
    {
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new InformationException('Unknown release channel.');
        }
        $di['mod_service']('system')->setParamValue(self::CHANNEL_SETTING, $channel);
        self::saveState($di, self::STATE, ['checked_at' => null, 'release' => null, 'error' => null]);
    }

    /** Is $version newer than the installed ClientFence? */
    public static function isNewer(?string $version): bool
    {
        return $version !== null && $version !== '' && version_compare(strtolower($version), strtolower(Version::CLIENTFENCE), '>');
    }

    /**
     * Reads and checks a feed.
     *
     * @return array{version: string, release_date: ?string, minimum_php_version: string, download_url: string, sha256: string, notes: string}
     */
    public static function parse(string $json, string $feedUrl): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new InformationException('The ClientFence release feed is not valid JSON.');
        }
        $version = $data['version'] ?? null;
        // Version::semverRegex has no start anchor (its delimiter is ^): the plain characters check adds it.
        if (!is_string($version) || preg_match('/\A[0-9A-Za-z.+-]{5,64}\z/', $version) !== 1 || preg_match(Version::semverRegex, $version) !== 1) {
            throw new InformationException('The ClientFence release feed has no valid version.');
        }
        $url = $data['download_url'] ?? null;
        $prefix = self::trustedPrefix($feedUrl);
        $file = is_string($url) && str_starts_with($url, $prefix) ? substr($url, strlen($prefix)) : null;
        if ($file === null || preg_match('#\A[A-Za-z0-9_-][A-Za-z0-9._-]*\.zip\z#', $file) !== 1 || str_contains($file, '..')) {
            throw new InformationException('The ClientFence release feed names an archive outside its own folder.');
        }
        $sha = is_string($data['sha256'] ?? null) ? strtolower(trim((string) $data['sha256'])) : '';
        $sha = str_starts_with($sha, 'sha256:') ? substr($sha, 7) : $sha;
        if (preg_match('/\A[0-9a-f]{64}\z/', $sha) !== 1) {
            throw new InformationException('The ClientFence release feed has no valid SHA-256 for the archive.');
        }
        $php = $data['minimum_php_version'] ?? null;
        $date = $data['release_date'] ?? null;
        $notes = $data['notes'] ?? '';

        return [
            'version' => $version,
            'release_date' => is_string($date) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) === 1 ? $date : null,
            'minimum_php_version' => is_string($php) && preg_match('/\A\d+\.\d+(\.\d+)?\z/', $php) === 1 ? $php : 'unknown',
            'download_url' => (string) $url,
            'sha256' => $sha,
            'notes' => is_string($notes) ? mb_substr($notes, 0, self::MAX_NOTES) : '',
        ];
    }

    /**
     * The newest release for this site's channel, checked now: the stable feed, and on the beta
     * channel the beta feed too, whichever names the later version. `channel` says which one.
     *
     * @return array{version: string, release_date: ?string, minimum_php_version: string, download_url: string, sha256: string, notes: string, channel: string}
     */
    public static function fetch(\Pimple\Container $di): array
    {
        $release = self::fetchFeed($di, self::feedUrl()) + ['channel' => 'stable'];
        if (self::channel($di) === 'beta') {
            $beta = self::fetchFeed($di, self::betaFeedUrl(), true);
            if ($beta !== null && version_compare(strtolower($beta['version']), strtolower($release['version']), '>')) {
                $release = $beta + ['channel' => 'beta'];
            }
        }

        return $release;
    }

    /**
     * One feed, checked now; null for a missing feed (404) when $optional.
     *
     * @return array{version: string, release_date: ?string, minimum_php_version: string, download_url: string, sha256: string, notes: string}|null
     */
    private static function fetchFeed(\Pimple\Container $di, string $feedUrl, bool $optional = false): ?array
    {
        try {
            $response = $di['http_client']->request('GET', $feedUrl, [
                'timeout' => 15,
                'max_duration' => 30,
                'max_redirects' => 0,
                'headers' => ['Accept' => 'application/json'],
            ]);
            if ($optional && $response->getStatusCode() === 404) {
                return null;
            }
            if ($response->getStatusCode() !== 200) {
                throw new InformationException('The ClientFence release feed answered HTTP :code.', [':code' => $response->getStatusCode()]);
            }
            $body = $response->getContent();
        } catch (InformationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new InformationException('The ClientFence release feed could not be reached: :error', [':error' => $e->getMessage()]);
        }
        if (strlen($body) > self::MAX_FEED_BYTES) {
            throw new InformationException('The ClientFence release feed is too large.');
        }

        return self::parse($body, $feedUrl);
    }

    /**
     * The last check: when, the release it found (kept when a later check fails) and its error.
     *
     * @return array{checked_at: ?string, release: ?array<string, mixed>, error: ?string}
     */
    public static function last(\Pimple\Container $di): array
    {
        $state = self::state($di, self::STATE) ?? [];

        return [
            'checked_at' => is_string($state['checked_at'] ?? null) ? $state['checked_at'] : null,
            'release' => is_array($state['release'] ?? null) ? $state['release'] : null,
            'error' => is_string($state['error'] ?? null) ? $state['error'] : null,
        ];
    }

    /**
     * Checks the feed when the last check is a day old, or always with $force (staff asked: that
     * works with the daily check off too), and saves the result. Returns the last check.
     *
     * @return array{checked_at: ?string, release: ?array<string, mixed>, error: ?string}
     */
    public static function check(\Pimple\Container $di, bool $force = false): array
    {
        $last = self::last($di);
        if (!$force && !self::enabled($di)) {
            return $last;
        }
        $checkedAt = $last['checked_at'] !== null ? strtotime($last['checked_at']) : false;
        if (!$force && $checkedAt !== false && time() - $checkedAt < self::CHECK_EVERY) {
            return $last;
        }
        $result = ['checked_at' => date('Y-m-d H:i:s'), 'release' => $last['release'], 'error' => null];
        try {
            $result['release'] = self::fetch($di);
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }
        self::saveState($di, self::STATE, $result);

        return $result;
    }

    /**
     * The newer release the last check found, without reaching the network (the dashboard banner
     * and System → Update), or null.
     *
     * @return array<string, mixed>|null
     */
    public static function available(\Pimple\Container $di): ?array
    {
        $release = self::last($di)['release'];

        return $release !== null && self::isNewer((string) ($release['version'] ?? '')) ? $release : null;
    }

    /** Cron: checks once a day and emails staff once about each new release. */
    public static function runIfDue(\Pimple\Container $di): void
    {
        if (!self::enabled($di)) {
            return;
        }
        $last = self::check($di);
        $release = $last['release'];
        if ($release === null || !self::isNewer((string) $release['version'])) {
            return;
        }
        $notified = self::state($di, self::NOTIFIED)['version'] ?? null;
        if ($notified === $release['version']) {
            return;
        }
        // Recorded first: a failing mail server must not send the same notice every day.
        self::saveState($di, self::NOTIFIED, ['version' => $release['version'], 'at' => date('Y-m-d H:i:s')]);
        $staff = $di['mod_service']('staff');
        $ids = $di['em']->getConnection()->fetchFirstColumn("SELECT id FROM admin WHERE status = 'active' AND (system_name IS NULL OR system_name <> 'cron')");
        foreach ($ids as $id) {
            // The staff who may install it (System → Update); Super Administrators always.
            if (!$staff->hasPermission($di['db']->getExistingModelById('Admin', (int) $id), 'system', 'system_update')) {
                continue;
            }
            try {
                $di['mod_service']('email')->sendTemplate([
                    'code' => 'mod_staff_clientfence_update',
                    'to_admin' => (int) $id,
                    'release' => ['version' => $release['version'], 'release_date' => $release['release_date'], 'notes' => $release['notes']],
                    'current_version' => Version::CLIENTFENCE,
                    'update_url' => (string) $di['url']->adminLink('system/update'),
                    // Not left for the next cron run: staff may install the release before then.
                    'send_now' => true,
                ]);
            } catch (\Throwable $e) {
                $di['logger']->error('ClientFence: the release notice to staff #%s failed: %s', $id, $e->getMessage());
            }
        }
    }

    /** @return array<string, mixed>|null */
    private static function state(\Pimple\Container $di, string $name): ?array
    {
        try {
            $value = $di['em']->getConnection()->fetchOne('SELECT value FROM mod_clientfence_state WHERE name = ?', [$name]);
        } catch (\Throwable) {
            return null;
        }
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $value */
    private static function saveState(\Pimple\Container $di, string $name, array $value): void
    {
        $db = $di['em']->getConnection();
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $now = date('Y-m-d H:i:s');
        if ($db->executeStatement('UPDATE mod_clientfence_state SET value = ?, updated_at = ? WHERE name = ?', [$json, $now, $name]) === 0
            && $db->fetchOne('SELECT 1 FROM mod_clientfence_state WHERE name = ?', [$name]) === false) {
            $db->insert('mod_clientfence_state', ['name' => $name, 'value' => $json, 'updated_at' => $now]);
        }
    }
}
