<?php

declare(strict_types=1);

/**
 * ClientFence system health: the checks behind the dashboard's System health card and the
 * "System Health" tab of System > Settings > System (/clientfence/health opens it). FOSSBilling's
 * own security checks and admin alerts, then the pack's checks of security hardening, pack
 * integrity, services and the server. Each check is
 * passed ("ok"), a warning ("warn") or needs attention ("bad").
 *
 * The cron runs the checks once an hour (Service::onAfterAdminCronRun) and saves the result in
 * mod_clientfence_state, so the dashboard never waits for them; "Run checks now" on the page runs
 * them at once. When a check first needs attention, the Super Administrators get one email
 * (template mod_clientfence_health_alert); nothing more until it passes and breaks again.
 * Registrar and hosting server checks email after two failed runs in a row.
 *
 * Pack integrity compares the install with modules/Clientfence/pack-manifest.json, which
 * the release build writes (tools/build-pack-manifest.php): the hash of every pack file, the core patches and the
 * .htaccess blocks. No check reads or reports a secret.
 */

namespace ClientFence\Mod\Clientfence;

use ClientFence\Config;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

final class Health
{
    public const STATE = 'health';
    // The cron runs the checks when the last run is older than this (seconds; cron runs every 5 minutes).
    private const EVERY = 3300;
    private const HTTP_TIMEOUT = 8;
    // The web server's speed settings (speed()), as the last staff page saw them.
    private const WEB_SPEED = 'clientfence_web_speed';

    /**
     * The last saved run: ran_at (Y-m-d H:i:s), checks (list), counts, rating; null before the first.
     */
    public static function latest(\Pimple\Container $di): ?array
    {
        try {
            $row = $di['em']->getConnection()->fetchOne('SELECT value FROM mod_clientfence_state WHERE name = ?', [self::STATE]);
        } catch (\Throwable) {
            return null;
        }
        $report = is_string($row) ? json_decode($row, true) : null;

        return is_array($report) && is_array($report['checks'] ?? null) ? self::summarize($report) : null;
    }

    public static function runIfDue(\Pimple\Container $di): void
    {
        $last = self::latest($di);
        if ($last !== null && ($last['version'] ?? null) === \ClientFence\Version::CLIENTFENCE && time() - (int) strtotime((string) $last['ran_at']) < self::EVERY) {
            return;
        }
        self::run($di);
    }

    /**
     * Runs every check, emails the Super Administrators about checks that newly need attention,
     * and saves the result.
     */
    public static function run(\Pimple\Container $di): array
    {
        $previous = self::latest($di);
        $previousChecks = array_column($previous['checks'] ?? [], null, 'id');
        $checks = [];
        foreach (self::checkers() as $checker) {
            try {
                foreach ($checker($di) as $check) {
                    $prev = $previousChecks[$check['id']] ?? [];
                    $check['fails'] = $check['state'] === 'bad' ? ($prev['fails'] ?? (($prev['state'] ?? '') === 'bad' ? 2 : 0)) + 1 : 0;
                    $checks[] = $check;
                }
            } catch (\Throwable $e) {
                // One broken check must not hide the others.
                $di['logger']->error('ClientFence health: a check failed to run: %s', $e->getMessage());
            }
        }
        $report = self::summarize(['ran_at' => date('Y-m-d H:i:s'), 'checks' => $checks]);

        $newlyBad = array_values(array_filter($checks, static fn (array $c): bool => $c['fails'] === (str_starts_with($c['id'], 'svc_registrar_') || str_starts_with($c['id'], 'svc_server_') ? 2 : 1)));
        if ($newlyBad !== []) {
            try {
                self::notify($di, $newlyBad);
            } catch (\Throwable $e) {
                $di['logger']->error('ClientFence health: the alert email failed: %s', $e->getMessage());
            }
        }

        Service::ensureTables($di, true);
        $di['em']->getConnection()->executeStatement(
            'INSERT INTO mod_clientfence_state (name, value, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
            [self::STATE, json_encode(['ran_at' => $report['ran_at'], 'version' => \ClientFence\Version::CLIENTFENCE, 'checks' => $checks], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $report['ran_at']]
        );

        return $report;
    }

    /**
     * Adds the counts (ok, warn, bad, total), the share passed and the overall rating.
     */
    public static function summarize(array $report): array
    {
        $counts = ['ok' => 0, 'warn' => 0, 'bad' => 0];
        foreach ($report['checks'] as $check) {
            $state = $check['state'] ?? 'warn';
            $counts[isset($counts[$state]) ? $state : 'warn']++;
        }
        $report['checks'] = array_map(self::localize(...), $report['checks']);
        $total = array_sum($counts);
        $report['counts'] = $counts + ['total' => $total];
        $report['passed'] = $total > 0 ? (int) floor($counts['ok'] * 100 / $total) : 0;
        $report['rating'] = $counts['bad'] > 0 ? 'attention' : ($counts['warn'] > 0 ? 'good' : 'excellent');

        return $report;
    }

    /**
     * The speed settings of the PHP running this request: OPcache on or off, and the cache backend
     * in use (files also when a configured Redis or Memcached could not be reached) next to the
     * configured one. The setup wizard shows them; the health check reads them as below.
     *
     * @return array{opcache: bool, cache: string, cache_configured: string}
     */
    public static function speed(\Pimple\Container $di): array
    {
        $configured = (string) Config::getProperty('cache.driver', 'filesystem');

        return [
            'opcache' => extension_loaded('Zend OPcache') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN),
            'cache' => $di['cache'] instanceof FilesystemAdapter ? 'filesystem' : $configured,
            'cache_configured' => $configured,
        ];
    }

    /**
     * Remembers speed() of the web server's PHP (staff dashboard loads call this): the hourly cron
     * runs the checks in command-line PHP, which often has OPcache off or other settings.
     */
    public static function noteWebSpeed(\Pimple\Container $di): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        try {
            $item = $di['cache']->getItem(self::WEB_SPEED);
            $speed = self::speed($di);
            if (!$item->isHit() || $item->get() !== $speed) {
                $di['cache']->save($item->set($speed)->expiresAfter(7 * 86400));
            }
        } catch (\Throwable) {
            // No cache: the cron's check uses its own PHP settings.
        }
    }

    /** speed() of the web server's PHP: this request's, or what the last staff page saw. */
    private static function webSpeed(\Pimple\Container $di): array
    {
        if (PHP_SAPI !== 'cli') {
            self::noteWebSpeed($di);

            return self::speed($di);
        }
        try {
            $item = $di['cache']->getItem(self::WEB_SPEED);
            if ($item->isHit() && is_array($item->get())) {
                return $item->get() + self::speed($di);
            }
        } catch (\Throwable) {
        }
        // No snapshot: maybe only this PHP cannot use Redis, so it reads files while the site uses
        // Redis. Its own fallback says nothing about the site; a site that fell back to files too
        // leaves its snapshot in files, where this run reads it.
        $own = self::speed($di);

        return ['cache' => $own['cache_configured']] + $own;
    }

    /** Whether the admin area prefix is FOSSBilling's default (/admin, any case or slashes) or empty. */
    public static function isDefaultAdminPath(string $prefix): bool
    {
        $path = strtolower(trim($prefix, " /\t"));

        return $path === '' || $path === 'admin';
    }

    /**
     * Modules whose stored settings do not decrypt with this install's info.salt.
     *
     * @return list<string>
     */
    public static function unreadableSettings(\Pimple\Container $di): array
    {
        $unreadable = [];
        foreach ($di['em']->getConnection()->fetchAllAssociative("SELECT extension, meta_value FROM extension_meta WHERE meta_key = 'config' AND meta_value IS NOT NULL AND meta_value <> ''") as $row) {
            if (!is_string($di['crypt']->decrypt((string) $row['meta_value'], Config::getProperty('info.salt')))) {
                $unreadable[] = (string) $row['extension'];
            }
        }

        return $unreadable;
    }

    /** $title and $detail are saved in English with their values in $params, and translated when read (localize()). */
    private static function check(string $group, string $id, string $title, string $state, string $detail, string $url = '', array $params = []): array
    {
        return ['group' => $group, 'id' => $id, 'title' => $title, 'state' => $state, 'detail' => $detail, 'url' => $url, 'params' => $params];
    }

    /** The check groups (keys of a check's group), in English as the texts are. */
    public static function groups(): array
    {
        return ['core' => self::msg('Core checks'), 'security' => self::msg('Security hardening'), 'integrity' => self::msg('Pack integrity'), 'services' => self::msg('Services'), 'server' => self::msg('Server')];
    }

    /** Marks a check text for tools/locales.py extract; it is translated when read (localize()). */
    public static function msg(string $text): string
    {
        return $text;
    }

    /**
     * A saved check in the reader's language: the cron runs in the default one, staff read in their own.
     * A list value is translated item by item; :ago is seconds.
     */
    private static function localize(array $check): array
    {
        $params = [];
        foreach ($check['params'] ?? [] as $key => $value) {
            $params[$key] = match (true) {
                $key === ':ago' => self::ago((int) $value),
                is_array($value) => implode(', ', array_map(static fn ($item): string => __trans((string) $item), $value)),
                default => (string) $value,
            };
        }

        return ['title' => __trans((string) ($check['title'] ?? ''), $params), 'detail' => __trans((string) ($check['detail'] ?? ''), $params)] + $check;
    }

    /**
     * @return list<callable(\Pimple\Container): iterable<array>>
     */
    private static function checkers(): array
    {
        return [
            self::core(...),
            self::security(...),
            self::integrity(...),
            self::services(...),
            self::server(...),
        ];
    }

    // ---- The core checks (Security → Security Dashboard) and admin alerts.
    private static function core(\Pimple\Container $di): iterable
    {
        foreach ($di['mod_service']('security')->getAllChecks() as $id => $securityCheck) {
            try {
                $result = $securityCheck->performCheck();
                $state = match ($result->result->value) {
                    'passed' => 'ok',
                    'warning' => 'warn',
                    default => 'bad',
                };
                [$detail, $params] = [trim($result->message) !== '' ? trim($result->message) : self::msg('Passed'), []];
            } catch (\Throwable $e) {
                [$state, $detail, $params] = ['warn', self::msg('The check could not run: :error'), [':error' => $e->getMessage()]];
            }
            yield self::check('core', 'fb_' . $id, $securityCheck->getName(), $state, $detail, 'security', $params);
        }
        // The alert id must not change with the language (titles and texts are translated, and the cron
        // runs in the default locale while "Run checks now" runs in the staff member's): it comes from the
        // type and the button link, plus the position for alerts without a button.
        $unlinked = [];
        foreach ($di['mod_service']('system')->getMessages() as $message) {
            $type = (string) ($message['type'] ?? '');
            if (!in_array($type, ['danger', 'warning'], true)) {
                continue;
            }
            $title = trim((string) ($message['title'] ?? ''));
            $text = trim(strip_tags((string) ($message['message'] ?? '')));
            $link = (string) ($message['buttons'][0]['link'] ?? '');
            $key = $link !== '' ? $type . '|' . $link : $type . '|#' . ($unlinked[$type] = ($unlinked[$type] ?? 0) + 1);
            yield self::check('core', 'fb_alert_' . substr(hash('sha256', $key), 0, 12), $title !== '' ? $title : self::msg('Alert'), $type === 'danger' ? 'bad' : 'warn', $text, self::adminPath($di, $link));
        }
        // ClientFence has no update server: new versions come as ClientFence releases.
        $version = \ClientFence\Version::CLIENTFENCE;
        yield self::check('core', 'fb_update', self::msg('ClientFence version'), 'ok', self::msg('ClientFence :version. New versions come as ClientFence releases.'), params: [':version' => $version]);
    }

    // ---- Security hardening.
    private static function security(\Pimple\Container $di): iterable
    {
        $strict = Config::getProperty('security.mode', 'strict') === 'strict';
        yield self::check('security', 'sec_strict', self::msg('Strict security mode'), $strict ? 'ok' : 'bad', $strict ? self::msg('On: session cookies are SameSite=Strict.') : self::msg('Off: set security.mode to "strict" in config.php.'));

        // The report can be copied into a ticket or chat, so it never names the custom address.
        $defaultAdmin = self::isDefaultAdminPath(defined('ADMIN_PREFIX') ? (string) ADMIN_PREFIX : (string) Config::getProperty('admin_area_prefix', '/admin'));
        yield self::check('security', 'sec_admin_path', self::msg('Admin area address'), $defaultAdmin ? 'warn' : 'ok', $defaultAdmin
            ? self::msg('The admin area is at the default /admin, the first address bots try. Set admin_area_prefix in config.php to an address of your own (for example /team-7k2) and sign in there.')
            : self::msg('The admin area has its own address, not the default /admin.'));

        $https = (bool) Config::getProperty('security.force_https', false) && str_starts_with(strtolower((string) SYSTEM_URL), 'https://');
        yield self::check('security', 'sec_https', self::msg('HTTPS only'), $https ? 'ok' : 'bad', $https ? self::msg('The portal URL is https and plain HTTP is redirected.') : self::msg('Set the URL to https:// and security.force_https to true in config.php.'));

        $debug = (bool) Config::getProperty('debug_and_monitoring.debug', false);
        $display = filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN);
        yield self::check('security', 'sec_debug', self::msg('Debug and error display'), $debug ? 'bad' : ($display ? 'warn' : 'ok'), $debug ? self::msg('Debug mode is on: it can show server details to visitors.') : ($display ? self::msg('PHP display_errors is on.') : self::msg('Debug mode and PHP error display are off.')));

        $config = PATH_ROOT . '/config.php';
        $perms = is_file($config) ? fileperms($config) & 0o777 : null;
        yield self::check('security', 'sec_config_perms', self::msg('config.php permissions'), $perms === null ? 'bad' : (($perms & 0o007) !== 0 ? 'bad' : (($perms & 0o020) !== 0 ? 'warn' : 'ok')), match (true) {
            $perms === null => self::msg('config.php is missing.'),
            ($perms & 0o007) !== 0 => self::msg('Mode :mode: other users of the server can read it. Use 600.'),
            ($perms & 0o020) !== 0 => self::msg('Mode :mode: the group can write it. Use 600.'),
            default => self::msg('Mode :mode.'),
        }, params: [':mode' => sprintf('%o', $perms ?? 0)]);

        $htaccess = (string) @file_get_contents(PATH_ROOT . '/.htaccess');
        $manifest = self::manifest();
        $blocks = $manifest['htaccess'] ?? [];
        $missing = array_values(array_filter($blocks, static fn (string $b): bool => !str_contains($htaccess, "# BEGIN {$b}")));
        yield self::check('security', 'sec_htaccess', self::msg('Pack .htaccess rules'), $blocks === [] ? 'warn' : ($missing === [] ? 'ok' : 'bad'), $blocks === [] ? self::msg('No pack manifest: install the ClientFence release again.') : ($missing === [] ? self::msg(':count rule blocks in place.') : self::msg('Missing: :list. Install the ClientFence release again.')), params: [':count' => count($blocks), ':list' => implode(', ', $missing)]);

        foreach (['ticket' => [self::msg('Private folder: ticket attachments'), \ClientFence\Mod\Support\TicketFiles::dir()], 'payment' => [self::msg('Private folder: payment receipts'), \ClientFence\Mod\Invoice\Receipts::dir()]] as $what => [$title, $dir]) {
            if (!is_dir($dir)) {
                continue;
            }
            $inside = str_starts_with(realpath($dir) . '/', rtrim((string) realpath(PATH_ROOT), '/') . '/');
            $open = (fileperms($dir) & 0o007) !== 0;
            yield self::check('security', 'sec_dir_' . $what, $title, $inside ? 'bad' : ($open ? 'warn' : 'ok'), $inside ? self::msg('The folder is inside the web root.') : ($open ? self::msg('Outside the web root, but mode :mode lets other users in. Use 700.') : self::msg('Outside the web root, private to the site user.')), params: [':mode' => sprintf('%o', fileperms($dir) & 0o777)]);
        }

        // From the server itself: the response headers, and paths that must not be served.
        $base = rtrim((string) SYSTEM_URL, '/') . '/';
        $home = self::fetch($di, $base);
        if ($home === null) {
            yield self::check('security', 'sec_headers', self::msg('Security headers'), 'warn', self::msg('The portal could not be reached from the server.'));
        } else {
            $wanted = ['strict-transport-security' => 'HSTS', 'content-security-policy' => 'CSP', 'x-content-type-options' => 'X-Content-Type-Options'];
            $absent = array_values(array_filter($wanted, static fn (string $label, string $name): bool => !isset($home['headers'][$name]), ARRAY_FILTER_USE_BOTH));
            yield self::check('security', 'sec_headers', self::msg('Security headers'), $absent === [] ? 'ok' : 'bad', $absent === [] ? self::msg('HSTS, CSP and X-Content-Type-Options are sent.') : self::msg('Missing: :list (clientfence-security-headers.php).'), params: [':list' => implode(', ', $absent)]);
        }
        // Browser reports help spot scripts blocked by the enforced policy (CspReports).
        $blocked = CspReports::recent();
        yield self::check('security', 'sec_csp', self::msg('Script policy'), $blocked === [] ? 'ok' : 'warn', $blocked === []
            ? self::msg('Enforced. No script was blocked in the last 7 days.')
            : self::msg(":count kind(s) of script were blocked (last 7 days), for example :list. A browser extension or a bot can also cause these; check any that name the portal's own pages and scripts."),
            params: [':count' => count($blocked), ':list' => implode('; ', array_map(static fn (array $e): string => $e['blocked'] . ' (' . ($e['page'] !== '' ? $e['page'] : '/') . ($e['source'] !== '' ? ', ' . $e['source'] . ':' . $e['line'] : '') . ')', array_slice($blocked, 0, 3)))]);
        $served = [];
        $answered = 0;
        foreach (['locale/ar_SA/LC_MESSAGES/messages.mo', 'vendor/composer/installed.json', 'modules/Clientfence/manifest.json', 'api/client/servicehosting/change_domain'] as $path) {
            $response = self::fetch($di, $base . $path);
            $answered += $response !== null ? 1 : 0;
            if ($response !== null && $response['status'] === 200) {
                $served[] = '/' . $path;
            }
        }
        yield $answered === 0
            ? self::check('security', 'sec_private_paths', self::msg('Private paths blocked'), 'warn', self::msg('The portal could not be reached from the server.'))
            : self::check('security', 'sec_private_paths', self::msg('Private paths blocked'), $served === [] ? 'ok' : 'bad', $served === [] ? self::msg('Libraries, translations, manifests and the client domain-change call are refused.') : self::msg('Served to visitors: :list.'), params: [':list' => implode(', ', $served)]);
    }

    // ---- Pack integrity: the install against the pack manifest.
    private static function integrity(\Pimple\Container $di): iterable
    {
        try {
            $pending = Migrations::pending($di['pdo']);
            yield self::check('integrity', 'int_migrations', self::msg('Database updates'), $pending === 0 ? 'ok' : 'bad', $pending === 0
                ? self::msg('ClientFence database at update :level.')
                : self::msg(':count ClientFence database update(s) pending: open Finalize update in the admin, or run php console.php system:run-patcher.'), params: [':level' => Migrations::latest(), ':count' => $pending]);
        } catch (\Throwable) {
            yield self::check('integrity', 'int_migrations', self::msg('Database updates'), 'warn', self::msg('Could not read the ClientFence database update level.'));
        }

        // O-01: settings saved with another install's info.salt (a backup restored on a new install) read as empty.
        $unreadable = self::unreadableSettings($di);
        yield self::check('integrity', 'int_salt', self::msg('Encryption key'), $unreadable === [] ? 'ok' : 'bad', $unreadable === []
            ? self::msg('Module settings can be read with info.salt in config.php.')
            : self::msg(':count module setting(s) were saved with another encryption key and read as empty (:list). If this database was restored from a backup, put the original info.salt back in config.php before saving any settings.'),
            params: [':count' => count($unreadable), ':list' => implode(', ', array_slice($unreadable, 0, 3)) . (count($unreadable) > 3 ? ', …' : '')]);

        $manifest = self::manifest();
        if ($manifest === null) {
            yield self::check('integrity', 'int_manifest', self::msg('Pack manifest'), 'warn', self::msg('modules/Clientfence/pack-manifest.json is missing: install the ClientFence release again.'));

            return;
        }
        $patches = $manifest['patches'] ?? [];
        $undone = self::undonePatches($patches, PATH_ROOT);
        yield self::check('integrity', 'int_patches', self::msg('Core patches'), $undone === [] ? 'ok' : 'bad', $undone === [] ? self::msg(':count core patches applied.') : self::msg(':undone file(s) without their core patch: :list. Install the ClientFence release again.'),
            params: [':count' => count($patches), ':undone' => count($undone), ':list' => implode(', ', array_slice($undone, 0, 3)) . (count($undone) > 3 ? ', …' : '')]);

        $changed = [];
        foreach ($manifest['files'] ?? [] as $relative => $hash) {
            $path = PATH_ROOT . '/' . $relative;
            if (!is_file($path) || hash_file('sha256', $path) !== $hash) {
                $changed[] = $relative;
            }
        }
        $files = count($manifest['files'] ?? []);
        yield self::check('integrity', 'int_files', self::msg('Pack files unchanged'), $changed === [] ? 'ok' : 'bad', $changed === [] ? self::msg(':count files as installed.') : self::msg(':changed file(s) changed or missing: :list. Install the ClientFence release again.'),
            params: [':count' => $files, ':changed' => count($changed), ':list' => implode(', ', array_slice($changed, 0, 3)) . (count($changed) > 3 ? ', …' : '')]);
    }

    // ---- Services.
    private static function services(\Pimple\Container $di): iterable
    {
        $db = $di['em']->getConnection();
        $last = (string) $di['mod_service']('system')->getParamValue('last_cron_exec');
        $age = $last !== '' ? time() - (int) strtotime($last) : null;
        yield self::check('services', 'svc_cron', self::msg('Scheduled tasks (cron)'), $age === null ? 'bad' : ($age <= 900 ? 'ok' : ($age <= 7200 ? 'warn' : 'bad')), $age === null ? self::msg('Cron has never run.') : ($age > 900 ? self::msg('Last run :ago ago; it should run every 5 minutes.') : self::msg('Last run :ago ago.')), 'system', [':ago' => $age]);

        $stale = date('Y-m-d H:i:s', time() - \ClientFence\Mod\Email\Repository\QueuedEmailRepository::SENDING_STALE_MINUTES * 60);
        $queue = $db->fetchAssociative("SELECT SUM(status IN ('unsent', 'pending')) AS waiting, SUM(status = 'failed' OR (status = 'sending' AND updated_at < ?)) AS failed, MIN(CASE WHEN status IN ('unsent', 'pending') THEN created_at END) AS oldest FROM email_queue", [$stale]) ?: [];
        $waiting = (int) ($queue['waiting'] ?? 0);
        $failed = (int) ($queue['failed'] ?? 0);
        $oldest = $queue['oldest'] ? time() - (int) strtotime((string) $queue['oldest']) : 0;
        $queued = match (true) {
            $waiting === 0 => $failed > 0 ? self::msg('Nothing waiting, :failed failed (retried by cron).') : self::msg('Nothing waiting.'),
            $oldest > 3600 => $failed > 0 ? self::msg(':waiting waiting, the oldest for :ago, :failed failed (retried by cron).') : self::msg(':waiting waiting, the oldest for :ago.'),
            default => $failed > 0 ? self::msg(':waiting waiting, :failed failed (retried by cron).') : self::msg(':waiting waiting.'),
        };
        yield self::check('services', 'svc_queue', self::msg('Email queue'), $failed > 0 || $oldest > 3600 ? 'warn' : 'ok', $queued, 'extension/settings/email', [':waiting' => $waiting, ':failed' => $failed, ':ago' => $oldest]);

        $mail = $di['mod_service']('extension')->getConfig('mod_email');
        $mailer = (string) ($mail['mailer'] ?? '');
        $port = (int) ($mail['smtp_port'] ?? 0);
        [$state, $detail] = match (true) {
            $mailer === 'smtp' && $port === 465 => ['ok', self::msg('SMTP over TLS (port 465).')],
            $mailer === 'smtp' && $port === 587 => ['ok', self::msg('SMTP with STARTTLS (port 587).')],
            $mailer === 'smtp' => ['warn', self::msg('SMTP on port :port: use 465 (TLS) or 587 (STARTTLS).')],
            $mailer === 'sendgrid' => ['ok', self::msg('SendGrid API over HTTPS.')],
            $mailer === '' => ['bad', self::msg('No mail transport: emails are not sent.')],
            default => ['warn', self::msg('Mail goes through :mailer, not SMTP over TLS.')],
        };
        yield self::check('services', 'svc_mail', self::msg('Email delivery'), $state, $detail, 'extension/settings/email', [':port' => $port, ':mailer' => $mailer]);

        foreach ($db->fetchAllAssociative("SELECT id, name FROM tld_registrar WHERE registrar = 'Dynadot'") as $row) {
            $model = $di['db']->load('TldRegistrar', (int) $row['id']);
            try {
                $adapter = $di['mod_service']('servicedomain')->registrarGetRegistrarAdapter($model);
                $ok = method_exists($adapter, 'testConnection') && $adapter->testConnection();
                yield self::check('services', 'svc_registrar_' . $row['id'], self::msg('Registrar: :name'), $ok ? 'ok' : 'bad', $ok ? self::msg('The Dynadot API answers with this key.') : self::msg('The Dynadot API did not answer.'), 'servicedomain', [':name' => $row['name']]);
            } catch (\Throwable $e) {
                yield self::check('services', 'svc_registrar_' . $row['id'], self::msg('Registrar: :name'), 'bad', self::safe($e->getMessage()), 'servicedomain', [':name' => $row['name']]);
            }
        }

        foreach ($db->fetchAllAssociative('SELECT id, name FROM service_hosting_server WHERE active = 1') as $row) {
            $model = $di['db']->load('ServiceHostingServer', (int) $row['id']);
            try {
                $ok = (bool) $di['mod_service']('servicehosting')->testConnection($model);
                yield self::check('services', 'svc_server_' . $row['id'], self::msg('Hosting server: :name'), $ok ? 'ok' : 'bad', $ok ? self::msg('The control panel answers.') : self::msg('The control panel did not answer.'), 'servicehosting', [':name' => $row['name']]);
            } catch (\Throwable $e) {
                yield self::check('services', 'svc_server_' . $row['id'], self::msg('Hosting server: :name'), 'bad', self::safe($e->getMessage()), 'servicehosting', [':name' => $row['name']]);
            }
        }

        $config = \ClientFence\Mod\Client\Google::config($di);
        $googleOn = ($config['google_signin'] ?? '0') === '1';
        $googleKeys = (string) ($config['google_client_id'] ?? '') !== '' && (string) ($config['google_client_secret'] ?? '') !== '';
        yield self::check('services', 'svc_google', self::msg('Google sign-in'), $googleOn && !$googleKeys ? 'bad' : 'ok', $googleOn ? ($googleKeys ? self::msg('On, with a client ID and secret.') : self::msg('On, but the client ID or secret is missing.')) : self::msg('Off.'), 'extension/settings/client');

        $extensions = $di['mod_service']('extension');
        $antispam = $extensions->isExtensionActive('mod', 'antispam') ? $extensions->getConfig('mod_antispam') : [];
        $captcha = !empty($antispam['captcha_enabled']);
        $provider = (string) ($antispam['captcha_provider'] ?? 'recaptcha_v2');
        $keyed = $provider === 'turnstile' ? !empty($antispam['turnstile_site_key']) && !empty($antispam['turnstile_secret_key']) : !empty($antispam['captcha_recaptcha_privatekey']);
        yield self::check('services', 'svc_captcha', self::msg('Captcha on public forms'), !$captcha ? 'warn' : ($keyed ? 'ok' : 'bad'), !$captcha ? self::msg('Off: sign-up and sign-in forms have no captcha.') : ($keyed ? ($provider === 'turnstile' ? self::msg('Cloudflare Turnstile, keys set.') : self::msg('reCAPTCHA, keys set.')) : self::msg('On, but its keys are missing.')), 'extension/settings/antispam');
    }

    // ---- Server.
    private static function server(\Pimple\Container $di): iterable
    {
        $requirements = new \ClientFence\Requirements();
        $compat = $requirements->checkCompat();
        $phpOk = $requirements->isPhpVersionOk();
        yield self::check('server', 'srv_php', self::msg('PHP version'), $phpOk ? 'ok' : 'bad', $phpOk ? self::msg('PHP :version.') : self::msg('PHP :version: ClientFence needs a newer PHP.'), params: [':version' => PHP_VERSION]);

        $missing = array_keys(array_filter($compat['required_extensions'] ?? [], static fn ($loaded): bool => !$loaded));
        // OPcache has its own check below, from the web PHP (the cron's command-line PHP often lacks it).
        $suggested = array_values(array_diff(array_keys(array_filter($compat['suggested_extensions'] ?? [], static fn ($e): bool => !($e['loaded'] ?? false))), ['opcache']));
        yield self::check('server', 'srv_extensions', self::msg('PHP extensions'), $missing !== [] ? 'bad' : ($suggested !== [] ? 'warn' : 'ok'), $missing !== [] ? self::msg('Missing: :list.') : ($suggested !== [] ? self::msg('All required ones; recommended but missing: :suggested.') : self::msg('All required and recommended extensions are loaded.')), params: [':list' => implode(', ', $missing), ':suggested' => implode(', ', $suggested)]);

        $limit = (string) ini_get('memory_limit');
        $bytes = self::bytes($limit);
        yield self::check('server', 'srv_memory', self::msg('PHP memory limit'), $bytes < 0 || $bytes >= 256 << 20 ? 'ok' : ($bytes >= 128 << 20 ? 'warn' : 'bad'), $bytes < 0 ? self::msg('Unlimited.') : ($bytes < 256 << 20 ? self::msg(':limit; 256M or more is recommended.') : self::msg(':limit.')), params: [':limit' => $limit]);

        $dbVersion = (string) $di['em']->getConnection()->fetchOne('SELECT VERSION()');
        $maria = stripos($dbVersion, 'mariadb') !== false;
        preg_match('/^(\d+)\.(\d+)/', $dbVersion, $m);
        $numeric = isset($m[1]) ? ((int) $m[1]) * 100 + (int) $m[2] : 0;
        $dbOk = $maria ? $numeric >= 1006 : $numeric >= 800;
        yield self::check('server', 'srv_db', self::msg('Database version'), $dbOk ? 'ok' : 'warn', $dbOk ? self::msg(':database.') : ($maria ? self::msg(':database: 10.6 or newer is recommended.') : self::msg(':database: 8.0 or newer is recommended.')), params: [':database' => ($maria ? 'MariaDB ' : 'MySQL ') . preg_replace('/-.*$/', '', $dbVersion)]);

        $free = @disk_free_space(PATH_ROOT);
        $total = @disk_total_space(PATH_ROOT);
        if ($free !== false && $total) {
            $gb = $free / (1 << 30);
            yield self::check('server', 'srv_disk', self::msg('Free disk space'), $gb >= 5 ? 'ok' : ($gb >= 1 ? 'warn' : 'bad'), self::msg(':size GB free (:percent%).'), params: [':size' => sprintf('%.1f', $gb), ':percent' => (int) round($free * 100 / $total)]);
        }

        // Only the Linkers billing pack keeps this work folder; other installs have the daily database backup below.
        $backups = rtrim((string) (getenv('BILLING_SRC') ?: dirname(PATH_ROOT) . '/.billing-src'), '/') . '/backups';
        $newest = 0;
        foreach (is_dir($backups) && is_readable($backups) ? (glob($backups . '/*', GLOB_ONLYDIR) ?: []) : [] as $dir) {
            $newest = max($newest, (int) filemtime($dir));
        }
        if (is_dir($backups)) {
            yield self::check('server', 'srv_backup', self::msg('Newest billing backup'), $newest === 0 ? 'warn' : (time() - $newest <= 30 * 86400 ? 'ok' : 'warn'), $newest === 0 ? self::msg('No backup found in the work folder.') : (time() - $newest > 30 * 86400 ? self::msg('Made :ago ago; make a fresh one.') : self::msg('Made :ago ago.')), params: [':ago' => time() - $newest]);
        }

        [$state, $detail, $params] = Backups::health($di);
        yield self::check('server', 'srv_db_backup', self::msg('Daily database backup'), $state, $detail, 'clientfence/backups', $params);

        $speed = self::webSpeed($di);
        yield self::check('server', 'srv_opcache', self::msg('PHP OPcache'), $speed['opcache'] ? 'ok' : 'warn', $speed['opcache'] ? self::msg('On: PHP keeps the compiled code in memory.') : self::msg('Off: PHP compiles the code again on every request, so pages take about 4 times longer. Turn on OPcache (opcache.enable = 1, often "Opcode caching" in the hosting panel) in the PHP settings of this site.'));
        $names = ['filesystem' => 'Files', 'redis' => 'Redis', 'memcached' => 'Memcached'];
        $configured = $names[$speed['cache_configured']] ?? $speed['cache_configured'];
        yield self::check('server', 'srv_cache', self::msg('Cache backend'), $speed['cache'] !== $speed['cache_configured'] ? 'bad' : ($speed['cache'] === 'filesystem' ? 'warn' : 'ok'), match (true) {
            $speed['cache'] !== $speed['cache_configured'] => self::msg(':cache is chosen but could not be reached, so ClientFence uses files: check that the server runs and the settings are right.'),
            $speed['cache'] === 'filesystem' => self::msg('Files: fine for a small site. For a busy one, Redis is faster: install the Redis server and the PHP redis extension (many hosts offer both), then choose Redis in System → Settings → Cache.'),
            default => self::msg(':cache.'),
        }, 'extension/settings/system#tab-cache', [':cache' => $configured]);

        $geo = \ClientFence\GeoIP\Reader::getCountryDatabase();
        $geoAge = is_file($geo) ? time() - (int) filemtime($geo) : null;
        yield self::check('server', 'srv_geoip', self::msg('GeoIP database'), $geoAge === null ? 'bad' : ($geoAge <= 60 * 86400 ? 'ok' : 'warn'), $geoAge === null ? self::msg('Missing: countries of IP addresses are unknown.') : ($geoAge > 60 * 86400 ? self::msg("Updated :ago ago; ClientFence's cron should refresh it monthly.") : self::msg('Updated :ago ago.')), params: [':ago' => (int) $geoAge]);
    }

    // ---- Alert email to the Super Administrators.
    private static function notify(\Pimple\Container $di, array $checks): void
    {
        $staff = $di['mod_service']('staff');
        $ids = $di['em']->getConnection()->fetchFirstColumn("SELECT id FROM admin WHERE status = 'active' AND (system_name IS NULL OR system_name <> 'cron')");
        $list = array_map(static fn (array $c): array => ['title' => $c['title'], 'detail' => $c['detail'], 'group' => __trans(self::groups()[$c['group']] ?? $c['group'])], array_map(self::localize(...), $checks));
        $super = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $staff->isSuperAdministrator($id)));
        Notifications::staff($di, 'health_bad', [], 'clientfence/health', 'system', $super);
        foreach ($ids as $id) {
            if (!$staff->isSuperAdministrator((int) $id)) {
                continue;
            }
            $di['mod_service']('email')->sendTemplate([
                'code' => 'mod_clientfence_health_alert',
                'to_admin' => (int) $id,
                'checks' => $list,
                'count' => count($list),
            ]);
        }
        $di['logger']->info('ClientFence health: %s check(s) need attention, Super Administrators emailed', count($checks));
    }

    // ---- Helpers.

    /**
     * The pack manifest (tools/build-pack-manifest.php), or null.
     */
    /**
     * The files whose core patch is missing. The installer's files (install/) are removed after
     * setup and after each update, so their patches only count while that folder is there.
     *
     * @param array<int, array{file: string, done: string}> $patches
     *
     * @return list<string>
     */
    public static function undonePatches(array $patches, string $root): array
    {
        $installer = is_dir($root . '/install');
        $contents = [];
        $undone = [];
        foreach ($patches as $patch) {
            $file = (string) $patch['file'];
            if (!$installer && str_starts_with($file, 'install/')) {
                continue;
            }
            $contents[$file] ??= (string) @file_get_contents($root . '/' . $file);
            if (!str_contains($contents[$file], (string) $patch['done'])) {
                $undone[] = $file;
            }
        }

        return array_values(array_unique($undone));
    }

    public static function manifest(): ?array
    {
        $data = json_decode((string) @file_get_contents(__DIR__ . '/pack-manifest.json'), true);

        return is_array($data) ? $data : null;
    }

    /**
     * GET from the server itself: status and lower-case headers, or null when unreachable.
     */
    private static function fetch(\Pimple\Container $di, string $url): ?array
    {
        try {
            $response = $di['http_client']->request('GET', $url, ['timeout' => self::HTTP_TIMEOUT, 'max_redirects' => 0]);
            $status = $response->getStatusCode();
            $headers = array_change_key_case($response->getHeaders(false), CASE_LOWER);
            $response->cancel();

            return ['status' => $status, 'headers' => $headers];
        } catch (\Throwable) {
            return null;
        }
    }

    // An admin link as a path inside the admin area ("system/update"), so the page builds the URL.
    private static function adminPath(\Pimple\Container $di, string $link): string
    {
        $base = rtrim($di['url']->adminLink(''), '/') . '/';

        return $link !== '' && str_starts_with($link, $base) ? substr($link, strlen($base)) : '';
    }

    // An error message without anything that looks like a key, token or password.
    private static function safe(string $message): string
    {
        return mb_substr((string) preg_replace('/(key|token|pass(word)?|secret)=\S+/i', '$1=***', $message), 0, 200);
    }

    private static function ago(int $seconds): string
    {
        return match (true) {
            $seconds < 7200 => __pluralTrans(':count minute', ':count minutes', $n = max(1, intdiv($seconds, 60)), [':count' => $n]),
            $seconds < 172800 => __pluralTrans(':count hour', ':count hours', $n = intdiv($seconds, 3600), [':count' => $n]),
            default => __pluralTrans(':count day', ':count days', $n = intdiv($seconds, 86400), [':count' => $n]),
        };
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number << 30,
            'm' => $number << 20,
            'k' => $number << 10,
            default => $number,
        };
    }
}
