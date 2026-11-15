<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence;

use ClientFence\InformationException;
use PhpZip\ZipFile;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * ClientFence: copies of the database and the site's files to ONE cloud storage (owner decisions B1-B7, 2026-11-15),
 * next to the daily methods of Backups.php: any S3-compatible storage (AWS, Backblaze B2, Wasabi, Cloudflare R2,
 * MinIO...; AWS Signature V4 below, no SDK), Bunny Storage (its own HTTP API), Google Drive (scope drive.file: only
 * the files it made) or OneDrive (its app folder), the last two through the OAuth app the site owner registers.
 * The database is copied hourly, daily or weekly, the files (the web root and the private upload folders) daily,
 * weekly or never; the last N of each stay on the storage, older ones that this site made are removed.
 *
 * The cron only decides what is due (runIfDue(), one state read when off); the copies are made and uploaded after
 * the cron run has ended and released its lock, under their own lock, one streamed upload per file (5 GB at most).
 * With a passphrase each copy is encrypted first, in the format `openssl enc -aes-256-cbc -pbkdf2` writes; decrypt:
 *   openssl enc -d -aes-256-cbc -pbkdf2 -iter 100000 -md sha256 -in NAME.enc -out NAME
 * Keys, passwords, tokens and the passphrase are kept encrypted, never shown back and never logged.
 * Settings and the last runs are mod_clientfence_state rows; page: the Backups tab (mod_clientfence_backups_panel).
 */
final class CloudBackups
{
    public const SETTINGS = 'backups_cloud';
    public const STATUS = 'backups_cloud_status';
    public const PROVIDERS = ['s3' => 'S3', 'bunny' => 'Bunny Storage', 'gdrive' => 'Google Drive', 'onedrive' => 'OneDrive'];
    public const EVERY = ['hourly' => 3600, 'daily' => 86400, 'weekly' => 604800];
    public const CALLBACK = 'clientfence/backups/cloud-callback';
    /** A cron run starts a few seconds late or early; a copy due within this many seconds goes now. */
    private const SLACK = 120;
    private const MAX_BYTES = 5 * 1024 ** 3;
    /** Read and encrypted per step; a multiple of 16 (AES), 256 KiB (Google) and 320 KiB (OneDrive), under 60 MiB. */
    private const CHUNK = 10 * 1024 * 1024;
    private const ITERATIONS = 100000;
    private const TIMEOUT = 20;
    private const LOCK = 'clientfence_cloud_backup';
    private const SESSION = 'clientfence_cloud_backup_connect';
    private const DRIVE_FOLDER = 'ClientFence backups';
    private const EMPTY_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
    /** [authorize, token, scope, extra sign-in parameters]. */
    private const OAUTH = [
        'gdrive' => ['https://accounts.google.com/o/oauth2/v2/auth', 'https://oauth2.googleapis.com/token', 'https://www.googleapis.com/auth/drive.file', ['access_type' => 'offline', 'prompt' => 'consent']],
        'onedrive' => ['https://login.microsoftonline.com/common/oauth2/v2.0/authorize', 'https://login.microsoftonline.com/common/oauth2/v2.0/token', 'Files.ReadWrite.AppFolder offline_access', ['response_mode' => 'query']],
    ];
    private const DEFAULTS = [
        'enabled' => false, 'provider' => 's3', 'endpoint' => '', 'region' => '', 'bucket' => '', 'key' => '', 'secret' => '',
        'folder' => '', 'tokens' => '', 'passphrase' => '', 'db_every' => 'daily', 'db_keep' => 7, 'files_every' => 'never', 'files_keep' => 4,
    ];
    private const SECRETS = ['secret', 'tokens', 'passphrase'];

    /** @return array<string, mixed> */
    public static function settings(\Pimple\Container $di): array
    {
        return array_intersect_key(Backups::state($di, self::SETTINGS) ?? [], self::DEFAULTS) + self::DEFAULTS;
    }

    /** The settings without the secrets (only whether each is saved) and the last runs, for the page. */
    public static function forStaff(\Pimple\Container $di): array
    {
        $settings = self::settings($di);

        return array_diff_key($settings, array_flip(self::SECRETS)) + [
            'has_secret' => $settings['secret'] !== '',
            'connected' => $settings['tokens'] !== '',
            'encrypted' => $settings['passphrase'] !== '',
            'status' => Backups::state($di, self::STATUS) ?? [],
        ];
    }

    /**
     * `do` test: sends a test file with the typed details (nothing is saved); save: saves them, on only while the
     * storage is the same; activate: tests, saves and turns the copies on (as the SFTP card). Empty secrets keep the
     * saved ones.
     */
    public static function save(\Pimple\Container $di, array $data, string $do): void
    {
        $old = self::settings($di);
        $new = self::from($di, $data, $old);
        if ($do !== 'save') {
            self::test($di, $new);
        }
        if ($do === 'test') {
            return;
        }
        $new['enabled'] = $do === 'activate' || ($old['enabled'] && self::sameTarget($new, $old));
        if (!self::sameTarget($new, $old)) {
            // Another storage starts without the old one's copies: the first is due at the next cron run.
            Backups::saveState($di, self::STATUS, []);
        }
        Backups::saveState($di, self::SETTINGS, $new);
    }

    /** Whether two settings name the same storage; the saved ones may list their keys in another order (tokens first). */
    public static function sameTarget(array $a, array $b): bool
    {
        $target = array_fill_keys(['provider', 'endpoint', 'region', 'bucket', 'key', 'secret', 'folder', 'tokens'], null);
        $pick = static fn (array $s): array => array_replace($target, array_intersect_key($s, $target));

        return $pick($a) === $pick($b);
    }

    public static function deactivate(\Pimple\Container $di): void
    {
        Backups::saveState($di, self::SETTINGS, ['enabled' => false] + self::settings($di));
    }

    /**
     * The checked settings from the form, the secrets encrypted. A secret, key or token of another service or app
     * is not kept.
     *
     * @return array<string, mixed>
     */
    public static function from(\Pimple\Container $di, array $data, array $old): array
    {
        $provider = (string) ($data['provider'] ?? '');
        if (!isset(self::PROVIDERS[$provider])) {
            throw new InformationException('Choose a storage service.');
        }
        $text = [];
        foreach (['endpoint', 'region', 'bucket', 'key', 'secret', 'folder', 'passphrase'] as $name) {
            $text[$name] = trim((string) ($data[$name] ?? ''));
            if (preg_match('/[\r\n\0]/', $text[$name]) === 1) {
                throw new InformationException('Line breaks are not allowed in the backup settings.');
            }
        }
        $sameService = $provider === $old['provider'];
        $new = ['provider' => $provider, 'enabled' => false, 'region' => '', 'endpoint' => '', 'bucket' => '', 'folder' => '', 'tokens' => ''] + self::DEFAULTS;
        $new['key'] = $text['key'];
        $new['secret'] = $text['secret'] !== '' ? (string) $di['crypt']->encrypt($text['secret']) : ($sameService ? (string) $old['secret'] : '');
        if ($provider === 's3' || $provider === 'bunny') {
            $endpoint = preg_replace('#/+$#', '', $text['endpoint']);
            if ($provider === 's3') {
                $endpoint = preg_match('#^https?://#i', $endpoint) === 1 ? $endpoint : 'https://' . $endpoint;
                if (preg_match('#^https?://[a-z0-9.-]+(:\d{1,5})?$#i', $endpoint) !== 1) {
                    throw new InformationException('Enter the endpoint as https://host (for example https://s3.eu-central-1.amazonaws.com), without the bucket.');
                }
                if (preg_match('/^[a-z0-9][a-z0-9._-]{1,254}$/i', $text['bucket']) !== 1) {
                    throw new InformationException('Enter the bucket name.');
                }
                if ($new['key'] === '') {
                    throw new InformationException('Enter the access key.');
                }
                $new['region'] = $text['region'] !== '' ? $text['region'] : 'us-east-1';
            } else {
                // The password goes only to Bunny's own storage hosts.
                if (preg_match('/^([a-z]{2,4}\.)?storage\.bunnycdn\.com$/', $endpoint) !== 1) {
                    throw new InformationException('Enter the storage endpoint of the zone\'s region, for example storage.bunnycdn.com or ny.storage.bunnycdn.com.');
                }
                if (preg_match('/^[A-Za-z0-9-]{1,64}$/', $text['bucket']) !== 1) {
                    throw new InformationException('Enter the storage zone name.');
                }
                $new['key'] = '';
            }
            if (in_array('..', explode('/', str_replace('\\', '/', $text['folder'])), true)) {
                throw new InformationException('The folder may not contain "..".');
            }
            $new['endpoint'] = $endpoint;
            $new['bucket'] = $text['bucket'];
            $new['folder'] = trim((string) preg_replace('#/+#', '/', str_replace('\\', '/', $text['folder'])), '/');
        } else {
            if ($new['key'] === '') {
                throw new InformationException('Enter the client ID of your app.');
            }
            // Tokens belong to one app.
            $new['tokens'] = $sameService && $new['key'] === $old['key'] ? (string) $old['tokens'] : '';
        }
        if ($new['secret'] === '') {
            throw new InformationException($provider === 'bunny' ? 'Enter the storage zone password.' : ($provider === 's3' ? 'Enter the secret key.' : 'Enter the client secret of your app.'));
        }
        $new['db_every'] = (string) ($data['db_every'] ?? '');
        $new['files_every'] = (string) ($data['files_every'] ?? '');
        if (!isset(self::EVERY[$new['db_every']]) || ($new['files_every'] !== 'never' && (!isset(self::EVERY[$new['files_every']]) || $new['files_every'] === 'hourly'))) {
            throw new InformationException('Choose how often the copies are made.');
        }
        foreach (['db_keep', 'files_keep'] as $name) {
            $new[$name] = (int) ($data[$name] ?? 0);
            if ($new[$name] < 1 || $new[$name] > 1000) {
                throw new InformationException('Keep 1 to 1000 copies.');
            }
        }
        if (!empty($data['encrypt'])) {
            if ($text['passphrase'] !== '' && mb_strlen($text['passphrase']) < 8) {
                throw new InformationException('The passphrase needs at least 8 characters.');
            }
            $new['passphrase'] = $text['passphrase'] !== '' ? (string) $di['crypt']->encrypt($text['passphrase']) : (string) $old['passphrase'];
            if ($new['passphrase'] === '') {
                throw new InformationException('Enter the passphrase the copies are encrypted with.');
            }
        }

        return $new;
    }

    // ---- Google Drive and OneDrive sign-in (as Invoice\Accounting\Sync).

    /** The service's sign-in address for the saved app; the state waits in the staff session for 10 minutes. */
    public static function connectUrl(\Pimple\Container $di): string
    {
        $settings = self::settings($di);
        [$authorize, , $scope, $extra] = self::OAUTH[$settings['provider']] ?? throw new InformationException('Choose Google Drive or OneDrive and save first.');
        if ($settings['key'] === '' || $settings['secret'] === '') {
            throw new InformationException('Enter the client ID and client secret of your app and save first.');
        }
        $state = bin2hex(random_bytes(32));
        $di['session']->set(self::SESSION, ['state' => $state, 'provider' => $settings['provider'], 'key' => $settings['key'], 'expires' => time() + 600]);

        return $authorize . '?' . http_build_query(['client_id' => $settings['key'], 'redirect_uri' => self::redirectUri($di), 'response_type' => 'code', 'scope' => $scope, 'state' => $state] + $extra, '', '&', PHP_QUERY_RFC3986);
    }

    /** The service sent staff back (`state`, `code` or `error`): the code becomes the stored tokens. */
    public static function finishConnect(\Pimple\Container $di, array $query): void
    {
        $kept = $di['session']->get(self::SESSION);
        $di['session']->delete(self::SESSION);
        $settings = self::settings($di);
        $state = is_string($query['state'] ?? null) ? $query['state'] : '';
        if (!is_array($kept) || $state === '' || (int) ($kept['expires'] ?? 0) < time() || !hash_equals((string) ($kept['state'] ?? ''), $state)
            || ($kept['provider'] ?? '') !== $settings['provider'] || ($kept['key'] ?? '') !== $settings['key']) {
            throw new InformationException('This connection request has expired or was not started here. Click Connect again.');
        }
        if (isset($query['error'])) {
            throw new InformationException(':service did not connect: :error', [':service' => self::PROVIDERS[$settings['provider']], ':error' => mb_substr((string) $query['error'], 0, 200)]);
        }
        $code = is_string($query['code'] ?? null) ? $query['code'] : '';
        if ($code === '') {
            throw new InformationException('This connection request has expired or was not started here. Click Connect again.');
        }
        $tokens = self::token($di, $settings, ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::redirectUri($di)]);
        if ($tokens['refresh_token'] === '') {
            throw new InformationException(':service gave no lasting access: remove the app\'s access in your account and click Connect again.', [':service' => self::PROVIDERS[$settings['provider']]]);
        }
        Backups::saveState($di, self::SETTINGS, ['tokens' => (string) $di['crypt']->encrypt((string) json_encode($tokens))] + $settings);
        // It may be another account: it starts without the old one's copies (as save() for another storage).
        Backups::saveState($di, self::STATUS, []);
        $di['logger']->info('ClientFence: cloud backups connected to %s', self::PROVIDERS[$settings['provider']]);
    }

    private static function redirectUri(\Pimple\Container $di): string
    {
        return $di['url']->adminLink(self::CALLBACK);
    }

    /** @return array{access_token: string, refresh_token: string, expires_at: int} */
    private static function token(\Pimple\Container $di, array $settings, array $form): array
    {
        [, $url, $scope] = self::OAUTH[$settings['provider']];
        $form += ['client_id' => $settings['key'], 'client_secret' => self::secret($di, $settings['secret'])] + ($settings['provider'] === 'onedrive' ? ['scope' => $scope] : []);
        $reply = json_decode(self::call($di, 'POST', $url, ['body' => $form])[2], true);
        if (!is_string($reply['access_token'] ?? null)) {
            throw new InformationException(':service sent an answer ClientFence cannot read.', [':service' => self::PROVIDERS[$settings['provider']]]);
        }

        return ['access_token' => $reply['access_token'], 'refresh_token' => (string) ($reply['refresh_token'] ?? ''), 'expires_at' => time() + (int) ($reply['expires_in'] ?? 3600) - 60];
    }

    /** A valid access token; a refreshed one (Microsoft also turns the refresh token over) is saved at once. */
    private static function accessToken(\Pimple\Container $di, array &$settings): string
    {
        $tokens = $settings['tokens'] !== '' ? json_decode(self::secret($di, $settings['tokens']), true) : null;
        if (!is_array($tokens)) {
            throw new InformationException('Click Connect to let ClientFence into :service first.', [':service' => self::PROVIDERS[$settings['provider']]]);
        }
        if ((int) $tokens['expires_at'] > time()) {
            return (string) $tokens['access_token'];
        }
        $new = self::token($di, $settings, ['grant_type' => 'refresh_token', 'refresh_token' => (string) $tokens['refresh_token']]);
        $new['refresh_token'] = $new['refresh_token'] !== '' ? $new['refresh_token'] : (string) $tokens['refresh_token'];
        $settings['tokens'] = (string) $di['crypt']->encrypt((string) json_encode($new));
        Backups::saveState($di, self::SETTINGS, ['tokens' => $settings['tokens']] + self::settings($di));

        return $new['access_token'];
    }

    // ---- The cron.

    /** Inside the cron run: what is due waits until the run has ended and released the cron lock (B7). */
    public static function runIfDue(\Pimple\Container $di): void
    {
        $settings = self::settings($di);
        if (!$settings['enabled']) {
            return;
        }
        $status = Backups::state($di, self::STATUS) ?? [];
        $now = time();
        $kinds = array_values(array_filter(['db', 'files'], static fn (string $kind): bool => self::due($status[$kind]['ran_at'] ?? null, (string) $settings[$kind . '_every'], $now)));
        if ($kinds !== []) {
            self::runAfterResponse($di, $kinds, $now);
        }
    }

    /** Runs the kinds once the request has ended: a cron started over the web, or Back up now, answers first. */
    public static function runAfterResponse(\Pimple\Container $di, array $kinds, int $now): void
    {
        register_shutdown_function(static function () use ($di, $kinds, $now): void {
            // Saved now, or a page staff open during a long copy would lose its session changes when the copy ends.
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }

            try {
                self::run($di, $kinds, $now);
            } catch (\Throwable $e) {
                $di['logger']->error('ClientFence: the cloud backup failed: %s', $e->getMessage());
            }
        });
    }

    /** Whether a copy made every $every (EVERY, or never) is due when the last one started at $last. */
    public static function due(?string $last, string $every, int $now): bool
    {
        if (!isset(self::EVERY[$every])) {
            return false;
        }

        return $last === null || $now - (int) strtotime($last) >= self::EVERY[$every] - self::SLACK;
    }

    /** Makes and uploads each kind (db, files) under its own lock; a run still busy is not started twice. */
    public static function run(\Pimple\Container $di, array $kinds, int $now): void
    {
        $db = $di['em']->getConnection();
        if ((int) $db->fetchOne('SELECT GET_LOCK(?, 0)', [self::LOCK]) !== 1) {
            return;
        }
        try {
            @set_time_limit(0);
            Backups::raiseMemoryLimit();
            // Copies a stopped run left behind (only one run holds the lock).
            foreach (glob(sys_get_temp_dir() . '/cfcloud*') ?: [] as $stale) {
                if (is_file($stale) && is_writable($stale) && time() - (int) filemtime($stale) > 86400) {
                    @unlink($stale);
                }
            }
            $settings = self::settings($di);
            $status = Backups::state($di, self::STATUS) ?? [];
            foreach ($kinds as $kind) {
                // Saved first: a run that dies waits for its next turn, and Health shows it.
                $status[$kind] = ['ran_at' => date('Y-m-d H:i:s', $now), 'ok' => false, 'running' => true, 'file' => '', 'bytes' => 0, 'error' => 'Started, but did not finish (the process stopped).', 'last_ok_at' => $status[$kind]['last_ok_at'] ?? null];
            }
            Backups::saveState($di, self::STATUS, $status);
            foreach ($kinds as $kind) {
                $status[$kind] = self::copy($di, $settings, $kind, $status[$kind]);
                Backups::saveState($di, self::STATUS, $status);
            }
        } finally {
            $db->fetchOne('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
    }

    /** One copy: made, encrypted when set, uploaded, then the old ones removed. */
    private static function copy(\Pimple\Container $di, array &$settings, string $kind, array $result): array
    {
        $temporary = [];
        try {
            if ($kind === 'db') {
                [$file, $name] = Backups::make($di);
            } else {
                [$file, $name] = self::zipFiles();
            }
            $temporary[] = $file;
            if ($settings['passphrase'] !== '') {
                $temporary[] = $encrypted = self::tempFile();
                self::encrypt($file, $encrypted, self::secret($di, $settings['passphrase']));
                // The plain copy is not kept on the disk through a long upload.
                @unlink($file);
                [$file, $name] = [$encrypted, $name . '.enc'];
            }
            $name = self::site() . $name;
            $bytes = (int) filesize($file);
            self::upload($di, $settings, $file, $name);
            self::prune($di, $settings, $kind);
            $result = ['ok' => true, 'running' => false, 'file' => $name, 'bytes' => $bytes, 'error' => '', 'last_ok_at' => $result['ran_at']] + $result;
        } catch (\Throwable $e) {
            $result = ['running' => false, 'error' => $e->getMessage()] + $result;
        } finally {
            foreach ($temporary as $path) {
                @unlink($path);
            }
        }
        $di['logger']->info('ClientFence: cloud backup (%s) %s', $kind, $result['ok'] ? 'sent' : 'failed: ' . $result['error']);

        return $result;
    }

    /**
     * The folders of the files copy, by their name in the zip: the web root and every private upload folder outside it.
     *
     * @return array<string, string>
     */
    public static function fileRoots(): array
    {
        return ['site' => PATH_ROOT, 'receipts' => \ClientFence\Mod\Invoice\Receipts::dir(), 'client-files' => \ClientFence\Mod\Client\Files::dir(),
            'ticket-files' => \ClientFence\Mod\Support\TicketFiles::dir(), 'id-documents' => \ClientFence\Mod\Client\IdDocuments::dir()];
    }

    /**
     * A zip of the web root and the private upload folders outside it, without the cache, the logs and the backups.
     *
     * @return array{0: string, 1: string} path, name
     */
    private static function zipFiles(): array
    {
        $skip = array_filter(array_map('realpath', [PATH_CACHE, PATH_LOG, Backups::localDir(), sys_get_temp_dir()]));
        $zip = new ZipFile();
        $total = 0;
        foreach (self::fileRoots() as $folder => $root) {
            $root = realpath($root);
            if ($root === false || !is_dir($root)) {
                continue;
            }
            $finder = (new Finder())->files()->in($root)->ignoreDotFiles(false)->ignoreVCS(true)->ignoreUnreadableDirs()
                ->exclude(array_values(array_map(static fn (string $dir): string => Path::makeRelative($dir, $root), array_filter($skip, static fn (string $dir): bool => Path::isBasePath($root, $dir) && $dir !== $root))));
            foreach ($finder as $file) {
                $zip->addFile($file->getPathname(), $folder . '/' . str_replace('\\', '/', $file->getRelativePathname()));
                $total += (int) $file->getSize();
            }
        }
        // The zip and its encrypted copy must not fill the disk the live site runs on.
        $free = @disk_free_space(sys_get_temp_dir());
        if ($free !== false && $free < 2 * $total) {
            throw new InformationException('Not enough free disk space in :dir for the files copy (:size GB needed).', [':dir' => sys_get_temp_dir(), ':size' => round(2 * $total / 1024 ** 3, 1)]);
        }
        $out = self::tempFile();
        self::saveZip($zip, $out);

        return [$out, 'files_backup_' . date('Y-m-d_His') . '_' . bin2hex(random_bytes(8)) . '.zip'];
    }

    /** Saves and closes the zip owner-only: it holds config.php, and saveAsFile() writes a new file that it renames over $out. */
    public static function saveZip(ZipFile $zip, string $out): void
    {
        $umask = umask(0077);
        try {
            $zip->saveAsFile($out);
        } finally {
            umask($umask);
            $zip->close();
        }
    }

    private static function tempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cfcloud');
        if ($path === false) {
            throw new \RuntimeException('No temporary file could be made for the backup.');
        }
        @chmod($path, 0600);

        return $path;
    }

    /**
     * Encrypts $from into $to as `openssl enc -aes-256-cbc -pbkdf2 -iter 100000 -md sha256` does ("Salted__", the
     * salt, AES-256-CBC with PKCS#7 padding; key and IV from PBKDF2-HMAC-SHA256), CHUNK by CHUNK.
     */
    public static function encrypt(string $from, string $to, string $passphrase): void
    {
        $in = fopen($from, 'rb');
        $out = fopen($to, 'wb');
        if ($in === false || $out === false) {
            throw new \RuntimeException('The backup could not be encrypted.');
        }
        try {
            $salt = random_bytes(8);
            $derived = hash_pbkdf2('sha256', $passphrase, $salt, self::ITERATIONS, 48, true);
            [$key, $iv] = [substr($derived, 0, 32), substr($derived, 32)];
            $data = 'Salted__' . $salt;
            $chunk = (string) stream_get_contents($in, self::CHUNK);
            while (true) {
                if (fwrite($out, $data) !== strlen($data)) {
                    throw new \RuntimeException('The encrypted backup could not be written (is the disk full?).');
                }
                if ($chunk === null) {
                    break;
                }
                $next = (string) stream_get_contents($in, self::CHUNK);
                // Only the last piece is padded; each piece chains on from the last block of the one before.
                $data = (string) openssl_encrypt($chunk, 'aes-256-cbc', $key, $next === '' ? OPENSSL_RAW_DATA : OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
                $iv = substr($data, -16);
                $chunk = $next === '' ? null : $next;
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    // ---- The storage services.

    /** Writes, lists and removes a small file: what the copies and their clean-up need. */
    public static function test(\Pimple\Container $di, array &$settings): void
    {
        $file = self::tempFile();
        try {
            file_put_contents($file, "ClientFence test file; it is removed right away.\n");
            $name = self::site() . 'clientfence_test_' . bin2hex(random_bytes(8)) . '.txt';
            self::upload($di, $settings, $file, $name);
            $id = self::names($di, $settings, $name)[$name] ?? throw new InformationException('The test file was sent but is not listed: check that the key may list the folder.');
            self::remove($di, $settings, $id);
        } finally {
            @unlink($file);
        }
    }

    private static function upload(\Pimple\Container $di, array &$settings, string $file, string $name): void
    {
        $bytes = (int) filesize($file);
        if ($bytes > self::MAX_BYTES) {
            throw new InformationException('The copy is :size GB, over the 5 GB one upload may hold.', [':size' => round($bytes / 1024 ** 3, 1)]);
        }
        $hash = $settings['provider'] === 's3' ? hash_file('sha256', $file) : '';
        $in = fopen($file, 'rb');
        if ($in === false) {
            throw new \RuntimeException('The backup file could not be read.');
        }
        // At least 1 MB a second, and never less than 10 minutes.
        $limit = 600 + intdiv($bytes, 1 << 20);
        $headers = ['Content-Length' => (string) $bytes, 'Content-Type' => 'application/octet-stream'];
        try {
            match ($settings['provider']) {
                's3' => self::s3($di, $settings, 'PUT', self::folder($settings) . $name, [], ['body' => $in, 'headers' => $headers, 'max_duration' => $limit], $hash),
                'bunny' => self::call($di, 'PUT', self::bunnyUrl($settings, $name), ['body' => $in, 'headers' => $headers + ['AccessKey' => self::secret($di, $settings['secret'])], 'max_duration' => $limit]),
                default => self::chunks($di, self::session($di, $settings, $name, $bytes), $in, $bytes, $limit),
            };
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }
        }
    }

    /** Removes the copies of $kind beyond the last N (only names this site made). */
    private static function prune(\Pimple\Container $di, array &$settings, string $kind): void
    {
        $names = self::names($di, $settings, self::site() . ($kind === 'db' ? 'db_backup_' : 'files_backup_'));
        foreach (self::expired(array_keys($names), self::pattern($kind, self::site()), (int) $settings[$kind . '_keep']) as $name) {
            self::remove($di, $settings, $names[$name]);
        }
    }

    /** The copy names beyond the newest $keep that match $pattern (the date in the name orders them). */
    public static function expired(array $names, string $pattern, int $keep): array
    {
        $ours = array_values(array_filter($names, static fn (string $name): bool => preg_match($pattern, $name) === 1));
        rsort($ours, SORT_STRING);

        return array_slice($ours, max(1, $keep));
    }

    public static function pattern(string $kind, string $site): string
    {
        return '/^' . preg_quote($site, '/') . ($kind === 'db' ? 'db_backup_\d{4}-\d{2}-\d{2}_\d{6}_[0-9a-f]{16}\.sql\.gz' : 'files_backup_\d{4}-\d{2}-\d{2}_\d{6}_[0-9a-f]{16}\.zip') . '(\.enc)?$/';
    }

    /** This site's name before each copy's, so sites that share a folder never remove each other's copies. */
    private static function site(): string
    {
        return trim((string) preg_replace('/[^a-z0-9.-]+/', '-', strtolower((string) parse_url(SYSTEM_URL, PHP_URL_HOST))), '-') . '_';
    }

    /**
     * The stored files whose names start with $prefix: name => what remove() takes.
     * shortcut: one page of the listing (1000 names), enough while only the last N copies stay; page it if a folder holds more.
     *
     * @return array<string, string>
     */
    private static function names(\Pimple\Container $di, array &$settings, string $prefix): array
    {
        $out = [];
        switch ($settings['provider']) {
            case 's3':
                $xml = simplexml_load_string(self::s3($di, $settings, 'GET', '', ['list-type' => '2', 'prefix' => self::folder($settings) . $prefix]));
                foreach ($xml === false ? [] : $xml->Contents as $object) {
                    $out[basename((string) $object->Key)] = (string) $object->Key;
                }
                break;
            case 'bunny':
                foreach ((array) json_decode(self::call($di, 'GET', self::bunnyUrl($settings, ''), ['headers' => ['AccessKey' => self::secret($di, $settings['secret']), 'Accept' => 'application/json']])[2], true) as $object) {
                    if (is_array($object) && empty($object['IsDirectory']) && str_starts_with((string) ($object['ObjectName'] ?? ''), $prefix)) {
                        $out[(string) $object['ObjectName']] = (string) $object['ObjectName'];
                    }
                }
                break;
            case 'gdrive':
                $query = ['q' => "'" . self::driveFolder($di, $settings) . "' in parents and trashed = false", 'fields' => 'files(id,name)', 'pageSize' => 1000];
                foreach (json_decode(self::call($di, 'GET', 'https://www.googleapis.com/drive/v3/files', ['query' => $query, 'headers' => self::bearer($di, $settings)])[2], true)['files'] ?? [] as $object) {
                    $out[(string) $object['name']] = (string) $object['id'];
                }
                break;
            default:
                $query = ['$select' => 'id,name'];
                foreach (json_decode(self::call($di, 'GET', 'https://graph.microsoft.com/v1.0/me/drive/special/approot/children', ['query' => $query, 'headers' => self::bearer($di, $settings)])[2], true)['value'] ?? [] as $object) {
                    $out[(string) $object['name']] = (string) $object['id'];
                }
        }

        return array_filter($out, static fn (string $name): bool => str_starts_with($name, $prefix), ARRAY_FILTER_USE_KEY);
    }

    private static function remove(\Pimple\Container $di, array &$settings, string $id): void
    {
        match ($settings['provider']) {
            's3' => self::s3($di, $settings, 'DELETE', $id),
            'bunny' => self::call($di, 'DELETE', self::bunnyUrl($settings, $id), ['headers' => ['AccessKey' => self::secret($di, $settings['secret'])]]),
            'gdrive' => self::call($di, 'DELETE', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($id), ['headers' => self::bearer($di, $settings)]),
            default => self::call($di, 'DELETE', 'https://graph.microsoft.com/v1.0/me/drive/items/' . rawurlencode($id), ['headers' => self::bearer($di, $settings)]),
        };
    }

    /** "" or "a/b/". */
    private static function folder(array $settings): string
    {
        return $settings['folder'] === '' ? '' : $settings['folder'] . '/';
    }

    private static function bunnyUrl(array $settings, string $name): string
    {
        return 'https://' . $settings['endpoint'] . '/' . rawurlencode($settings['bucket']) . '/' . implode('', array_map(static fn (string $part): string => rawurlencode($part) . '/', array_filter(explode('/', $settings['folder']), static fn (string $part): bool => $part !== ''))) . ($name === '' ? '' : rawurlencode(basename($name)));
    }

    /** An S3 request, path-style (every S3-compatible service takes it), signed with AWS Signature V4; the answer's body. */
    private static function s3(\Pimple\Container $di, array $settings, string $method, string $key, array $query = [], array $options = [], string $hash = self::EMPTY_HASH): string
    {
        $parts = parse_url($settings['endpoint']);
        $path = '/' . implode('/', array_map('rawurlencode', explode('/', $settings['bucket'] . ($key === '' ? '' : '/' . $key))));
        $time = time();
        $headers = ['host' => $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''), 'x-amz-content-sha256' => $hash, 'x-amz-date' => gmdate('Ymd\THis\Z', $time)];
        $authorization = self::sign($method, $path, $query, $headers, $hash, $settings['key'], self::secret($di, $settings['secret']), $settings['region'], $time);
        // The client sends the same Host itself.
        unset($headers['host']);
        $options['headers'] = ['Authorization' => $authorization] + $headers + ($options['headers'] ?? []);

        return self::call($di, $method, $settings['endpoint'] . $path . ($query === [] ? '' : '?' . self::query($query)), $options)[2];
    }

    /**
     * AWS Signature V4 for S3 (https://docs.aws.amazon.com/AmazonS3/latest/API/sig-v4-header-based-auth.html): the
     * Authorization header of a request whose $headers (lowercase names, Host among them) are all signed.
     */
    public static function sign(string $method, string $path, array $query, array $headers, string $hash, string $key, string $secret, string $region, int $time): string
    {
        ksort($headers, SORT_STRING);
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . trim((string) $value) . "\n";
        }
        $signed = implode(';', array_keys($headers));
        $date = gmdate('Ymd', $time);
        $scope = $date . '/' . $region . '/s3/aws4_request';
        $canonical = implode("\n", [$method, $path, self::query($query), $canonicalHeaders, $signed, $hash]);
        $signing = 'AWS4' . $secret;
        foreach ([$date, $region, 's3', 'aws4_request'] as $part) {
            $signing = hash_hmac('sha256', $part, $signing, true);
        }
        $signature = hash_hmac('sha256', "AWS4-HMAC-SHA256\n" . gmdate('Ymd\THis\Z', $time) . "\n" . $scope . "\n" . hash('sha256', $canonical), $signing);

        return 'AWS4-HMAC-SHA256 Credential=' . $key . '/' . $scope . ', SignedHeaders=' . $signed . ', Signature=' . $signature;
    }

    private static function query(array $query): string
    {
        ksort($query, SORT_STRING);

        return implode('&', array_map(static fn (string $name, mixed $value): string => rawurlencode($name) . '=' . rawurlencode((string) $value), array_keys($query), $query));
    }

    /** @return array{Authorization: string} */
    private static function bearer(\Pimple\Container $di, array &$settings): array
    {
        return ['Authorization' => 'Bearer ' . self::accessToken($di, $settings)];
    }

    /** The Drive folder the copies go in, made on first use (drive.file sees only what this app made). */
    private static function driveFolder(\Pimple\Container $di, array &$settings): string
    {
        $query = ['q' => "mimeType = 'application/vnd.google-apps.folder' and name = '" . self::DRIVE_FOLDER . "' and trashed = false", 'fields' => 'files(id)'];
        $found = json_decode(self::call($di, 'GET', 'https://www.googleapis.com/drive/v3/files', ['query' => $query, 'headers' => self::bearer($di, $settings)])[2], true);

        return (string) ($found['files'][0]['id']
            ?? json_decode(self::call($di, 'POST', 'https://www.googleapis.com/drive/v3/files', ['json' => ['name' => self::DRIVE_FOLDER, 'mimeType' => 'application/vnd.google-apps.folder'], 'headers' => self::bearer($di, $settings)])[2], true)['id']
            ?? throw new InformationException('Google Drive did not make the backups folder.'));
    }

    /** A resumable upload's address: Google Drive in the backups folder, OneDrive in its app folder. */
    private static function session(\Pimple\Container $di, array &$settings, string $name, int $bytes): string
    {
        if ($settings['provider'] === 'gdrive') {
            $headers = self::bearer($di, $settings) + ['X-Upload-Content-Type' => 'application/octet-stream', 'X-Upload-Content-Length' => (string) $bytes];
            $reply = self::call($di, 'POST', 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable', ['json' => ['name' => $name, 'parents' => [self::driveFolder($di, $settings)]], 'headers' => $headers]);
            $url = (string) ($reply[1]['location'][0] ?? '');
        } else {
            $reply = self::call($di, 'POST', 'https://graph.microsoft.com/v1.0/me/drive/special/approot:/' . rawurlencode($name) . ':/createUploadSession', ['json' => ['item' => ['@microsoft.graph.conflictBehavior' => 'replace']], 'headers' => self::bearer($di, $settings)]);
            $url = (string) (json_decode($reply[2], true)['uploadUrl'] ?? '');
        }

        return str_starts_with($url, 'https://') ? $url : throw new InformationException(':service did not start the upload.', [':service' => self::PROVIDERS[$settings['provider']]]);
    }

    /** Sends the file in CHUNK pieces to a resumable upload (no Authorization: OneDrive refuses it there). */
    private static function chunks(\Pimple\Container $di, string $url, $in, int $bytes, int $limit): void
    {
        $ends = time() + $limit;
        $sent = 0;
        do {
            $chunk = (string) stream_get_contents($in, self::CHUNK);
            [$status] = self::call($di, 'PUT', $url, ['body' => $chunk, 'headers' => ['Content-Range' => 'bytes ' . $sent . '-' . ($sent + strlen($chunk) - 1) . '/' . $bytes], 'max_duration' => max(60, $ends - time())], [200, 201, 202, 308]);
            $sent += strlen($chunk);
        } while ($chunk !== '' && $sent < $bytes);
        if ($status !== 200 && $status !== 201) {
            throw new InformationException('The upload did not finish.');
        }
    }

    /**
     * One request; any other status than $ok throws the service's reason (which never holds a secret of ours).
     *
     * @return array{0: int, 1: array<string, list<string>>, 2: string} status, headers, body
     */
    private static function call(\Pimple\Container $di, string $method, string $url, array $options, array $ok = [200, 201, 204]): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        try {
            $response = $di['http_client']->request($method, $url, $options + ['timeout' => self::TIMEOUT, 'max_duration' => 120, 'max_redirects' => 0]);
            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            // The client names the URL in its errors; resumable upload URLs carry their own credential: keep hosts only.
            throw new InformationException('Could not reach :host: :error', [':host' => $host, ':error' => preg_replace('#https?://([^/\s"\']+)[^\s"\']*#', '$1', $e->getMessage())]);
        }
        if (!in_array($status, $ok, true)) {
            throw new InformationException(':host refused the request (HTTP :status): :error', [':host' => $host, ':status' => $status, ':error' => self::reason($body)]);
        }

        return [$status, $headers, $body];
    }

    /** The service's own reason, from its JSON or XML error. */
    private static function reason(string $body): string
    {
        $json = json_decode($body, true);
        if (is_array($json)) {
            $error = $json['error'] ?? null;

            return mb_substr((string) (is_array($error) ? ($error['message'] ?? '') : ($json['error_description'] ?? $error ?? $json['Message'] ?? '')), 0, 300);
        }
        $xml = str_starts_with(ltrim($body), '<') ? @simplexml_load_string($body) : false;
        if ($xml !== false && (string) $xml->Code === 'RequestTimeTooSkewed') {
            return 'This server\'s clock is more than 15 minutes off; set it right (NTP) and try again.';
        }

        return $xml !== false ? trim((string) $xml->Code . ': ' . (string) $xml->Message, ': ') : mb_substr(trim(strip_tags($body)), 0, 300);
    }

    private static function secret(\Pimple\Container $di, string $value): string
    {
        return (string) $di['crypt']->decrypt($value);
    }

    /**
     * The Health check, only while the copies are on: warn when the last copy failed or is older than twice its
     * schedule.
     *
     * @return array{0: string, 1: string, 2: array<string, mixed>}|null
     */
    public static function health(\Pimple\Container $di): ?array
    {
        $settings = self::settings($di);
        if (!$settings['enabled']) {
            return null;
        }
        $service = self::PROVIDERS[$settings['provider']];
        $status = Backups::state($di, self::STATUS) ?? [];
        $newest = null;
        foreach (['db', 'files'] as $kind) {
            $every = self::EVERY[$settings[$kind . '_every']] ?? null;
            $last = $status[$kind] ?? null;
            if ($every === null || $last === null || (!empty($last['running']) && time() - (int) strtotime((string) $last['ran_at']) < 6 * 3600)) {
                continue;
            }
            if (empty($last['ok'])) {
                return ['warn', Health::msg('The copy to :service of :time failed: :error'), [':service' => $service, ':time' => $last['ran_at'], ':error' => $last['error']]];
            }
            if (time() - (int) strtotime((string) $last['last_ok_at']) > 2 * $every) {
                return ['warn', Health::msg('The newest copy sent to :service is from :time: check that the cron runs.'), [':service' => $service, ':time' => $last['last_ok_at']]];
            }
            $newest = max($newest ?? '', (string) $last['last_ok_at']);
        }

        return $newest === null
            ? ['ok', Health::msg('On (:service); the first copy is sent after the next cron run.'), [':service' => $service]]
            : ['ok', Health::msg('Sent to :service :time.'), [':service' => $service, ':time' => $newest]];
    }
}
