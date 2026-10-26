<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence;

use ClientFence\InformationException;

/**
 * ClientFence: daily database backups, as WHMCS (System Settings → Database Backups): the cron
 * dumps the database once a day (DatabaseDump.php, a gzip SQL file) and sends it off the server
 * by each method that is on: an upload by SFTP or FTP, and/or an email to one address. As in
 * WHMCS a method is turned on only after its connection test passes ("Save & Activate"), the
 * file is not encrypted and old copies are not removed from the remote server. ClientFence adds a
 * third method (owner request): keep the newest N days on this server, in a folder outside the
 * web root (localDir(), ~/.clientfence-backups), listed on the page to download.
 *
 * The backup email goes straight through the Email settings' mailer, never through the email
 * queue or the email log, so the database is never stored in itself. Settings and the last run
 * are kept in mod_clientfence_state; the SFTP/FTP password is encrypted and never shown again.
 * Page: System → Settings → System → Database Backups (Controller/Admin.php); Health warns when no method is on,
 * the last run failed or the newest backup is old.
 */
final class Backups
{
    public const SETTINGS = 'backups';
    public const STATUS = 'backups_status';
    public const METHODS = ['ftp', 'email', 'local'];
    /** The cron makes one a day: when the last attempt is older than this (seconds). */
    private const EVERY = 86400 - 900;
    /** Larger backups are not emailed (most mail servers refuse them); WHMCS warns the same. */
    public const MAX_EMAIL_MB = 20;
    private const TIMEOUT = 20;
    private const LOCK = 'clientfence_backup';

    private const DEFAULTS = [
        'ftp' => ['enabled' => false, 'host' => '', 'port' => 22, 'username' => '', 'password' => '', 'path' => '', 'secure' => true, 'passive' => true, 'host_key' => ''],
        'email' => ['enabled' => false, 'address' => ''],
        'local' => ['enabled' => false, 'days' => 7],
    ];
    /** Names of backup files (make()); only these are listed, served or removed. */
    public const FILE_PATTERN = '/^db_backup_\d{4}-\d{2}-\d{2}_\d{6}_[0-9a-f]{16}\.sql\.gz$/';

    /** @return array{ftp: array<string, mixed>, email: array<string, mixed>, local: array<string, mixed>} */
    public static function settings(\Pimple\Container $di): array
    {
        $saved = self::state($di, self::SETTINGS) ?? [];
        $out = self::DEFAULTS;
        foreach (self::METHODS as $method) {
            if (is_array($saved[$method] ?? null)) {
                $out[$method] = array_intersect_key($saved[$method], $out[$method]) + $out[$method];
            }
        }

        return $out;
    }

    /** The settings without the password, the last run and the limits, for the page. */
    public static function forStaff(\Pimple\Container $di): array
    {
        $settings = self::settings($di);
        $settings['ftp']['has_password'] = (string) $settings['ftp']['password'] !== '';
        unset($settings['ftp']['password']);
        $settings['ftp']['ready'] = in_array('sftp', self::curlProtocols(), true);
        $settings['status'] = self::state($di, self::STATUS);
        $settings['max_email_mb'] = self::MAX_EMAIL_MB;
        $settings['local']['dir'] = self::localDir();
        $settings['local']['files'] = self::localFiles();

        return $settings;
    }

    /**
     * Saves the SFTP/FTP details; with $activate, tests them first and turns the method on (WHMCS
     * "Save & Activate"). An empty password keeps the saved one.
     */
    public static function saveFtp(\Pimple\Container $di, array $data, bool $activate): void
    {
        $old = self::settings($di)['ftp'];
        $ftp = self::ftpFrom($di, $data, $old);
        if ($activate) {
            // Save & Activate trusts the SFTP server's key as it is now; later transfers need the same key.
            $ftp['host_key'] = $ftp['secure'] ? self::hostKey($ftp) : '';
            self::testFtp($di, $ftp);
        }
        $ftp['enabled'] = $activate || ($old['enabled'] && self::sameServer($di, $old, $ftp));
        self::saveMethod($di, 'ftp', $ftp);
    }

    /** Saves the address; with $activate, turns emailed backups on. */
    public static function saveEmail(\Pimple\Container $di, array $data, bool $activate): void
    {
        $address = trim((string) ($data['address'] ?? ''));
        if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new InformationException('Enter a valid email address.');
        }
        if ($activate && $address === '') {
            throw new InformationException('Enter the email address the backups go to.');
        }
        $old = self::settings($di)['email'];
        self::saveMethod($di, 'email', ['enabled' => $activate || ($old['enabled'] && $address !== '' && strcasecmp($address, (string) $old['address']) === 0), 'address' => $address]);
    }

    /**
     * Saves how many days of backups this server keeps; with $activate, makes the folder (owner
     * only) and checks that it can be written, then turns the method on.
     */
    public static function saveLocal(\Pimple\Container $di, array $data, bool $activate): void
    {
        $days = (int) ($data['days'] ?? 0);
        if ($days < 1 || $days > 365) {
            throw new InformationException('Keep backups for 1 to 365 days.');
        }
        if ($activate) {
            self::localFolder();
        }
        $old = self::settings($di)['local'];
        self::saveMethod($di, 'local', ['enabled' => $activate || (bool) $old['enabled'], 'days' => $days]);
    }

    /** Where this server keeps backups: outside the web root; CLIENTFENCE_BACKUP_DIR overrides it. */
    public static function localDir(): string
    {
        return rtrim((string) (getenv('CLIENTFENCE_BACKUP_DIR') ?: dirname(PATH_ROOT) . '/.clientfence-backups'), '/');
    }

    /**
     * The backups kept on this server, newest first.
     *
     * @return list<array{name: string, bytes: int, made_at: string}>
     */
    public static function localFiles(): array
    {
        $files = [];
        foreach (glob(self::localDir() . '/db_backup_*.sql.gz') ?: [] as $path) {
            $name = basename($path);
            if (preg_match(self::FILE_PATTERN, $name) === 1 && is_file($path)) {
                $files[] = ['name' => $name, 'bytes' => (int) filesize($path), 'made_at' => date('Y-m-d H:i:s', (int) filemtime($path))];
            }
        }
        usort($files, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

        return $files;
    }

    /** The path of a kept backup by its name, or null (only names make() gives are accepted). */
    public static function localPath(string $name): ?string
    {
        if (preg_match(self::FILE_PATTERN, $name) !== 1) {
            return null;
        }
        $path = self::localDir() . '/' . $name;

        return is_file($path) ? $path : null;
    }

    public static function deactivate(\Pimple\Container $di, string $method): void
    {
        if (!in_array($method, self::METHODS, true)) {
            throw new InformationException('Unknown backup method.');
        }
        $settings = self::settings($di)[$method];
        $settings['enabled'] = false;
        self::saveMethod($di, $method, $settings);
    }

    /**
     * Connects with the given (or saved) details and opens the destination folder; throws with
     * the reason when it fails.
     */
    public static function testFtp(\Pimple\Container $di, ?array $ftp = null): void
    {
        $ftp ??= self::settings($di)['ftp'];
        if (trim((string) $ftp['host']) === '' || trim((string) $ftp['username']) === '') {
            throw new InformationException('Enter the host, username and password first.');
        }
        self::transfer($di, $ftp, null, '');
    }

    public static function runIfDue(\Pimple\Container $di): void
    {
        $settings = self::settings($di);
        if (!$settings['ftp']['enabled'] && !$settings['email']['enabled'] && !$settings['local']['enabled']) {
            return;
        }
        $last = self::state($di, self::STATUS);
        if ($last !== null && time() - (int) strtotime((string) ($last['ran_at'] ?? '')) < self::EVERY) {
            return;
        }
        self::run($di);
    }

    /**
     * Makes a backup and sends it by every method that is on; returns the run's status (also
     * saved for the page and Health). Methods that fail are listed with the reason.
     *
     * @return array{ran_at: string, ok: bool, file: string, bytes: int, tables: int, results: array<string, string>, last_ok_at: ?string}
     */
    public static function run(\Pimple\Container $di): array
    {
        $db = $di['em']->getConnection();
        if ((int) $db->fetchOne('SELECT GET_LOCK(?, 0)', [self::LOCK]) !== 1) {
            throw new InformationException('A backup is being made right now; try again in a minute.');
        }
        $settings = self::settings($di);
        $previous = self::state($di, self::STATUS);
        $status = ['ran_at' => date('Y-m-d H:i:s'), 'ok' => false, 'file' => '', 'bytes' => 0, 'tables' => 0, 'results' => [], 'last_ok_at' => $previous['last_ok_at'] ?? null];
        // Saved first: a run that dies (memory, a killed process) waits for the next day instead of
        // starting again every cron run, and Health shows it failed.
        self::saveState($di, self::STATUS, ['running' => true, 'results' => ['dump' => 'Started, but did not finish (the process stopped).']] + $status);
        $file = null;
        try {
            @set_time_limit(0);
            self::raiseMemoryLimit();
            [$file, $status['file'], $status['tables']] = self::make($di);
            register_shutdown_function(static function () use ($file): void {
                if (is_file($file)) {
                    @unlink($file);
                }
            });
            $status['bytes'] = (int) filesize($file);
            foreach (self::METHODS as $method) {
                if (!$settings[$method]['enabled']) {
                    continue;
                }
                try {
                    match ($method) {
                        'ftp' => self::transfer($di, self::pinned($di, $settings['ftp']), $file, $status['file']),
                        'email' => self::email($di, $settings['email']['address'], $file, $status['file']),
                        'local' => self::keep($file, $status['file'], (int) $settings['local']['days']),
                    };
                    $status['results'][$method] = 'ok';
                } catch (\Throwable $e) {
                    $status['results'][$method] = $e->getMessage();
                }
            }
            $status['ok'] = $status['results'] !== [] && array_unique(array_values($status['results'])) === ['ok'];
            if ($status['results'] === []) {
                $status['results']['none'] = 'No backup method is on.';
            }
        } catch (\Throwable $e) {
            $status['results']['dump'] = $e->getMessage();
        } finally {
            if ($file !== null && is_file($file)) {
                @unlink($file);
            }
            $db->fetchOne('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
        if ($status['ok']) {
            $status['last_ok_at'] = $status['ran_at'];
        }
        self::saveState($di, self::STATUS, $status);
        $di['logger']->info('ClientFence: database backup %s: %s', $status['ok'] ? 'sent' : 'failed', json_encode($status['results'], JSON_UNESCAPED_SLASHES));
        if (!$status['ok']) {
            // Backups are a Super Administrator setting.
            $staff = $di['mod_service']('staff');
            $super = array_values(array_filter(Notifications::staffWith($di, 'system'), static fn (int $id): bool => $staff->isSuperAdministrator($id)));
            Notifications::staff($di, 'backup_failed', [], 'extension/settings/system#tab-backups', 'system', $super, 'backup_failed:' . date('Y-m-d'));
        }

        return $status;
    }

    /**
     * Makes a backup file in the temporary folder: [path, file name, tables]. The caller removes it.
     *
     * @return array{0: string, 1: string, 2: int}
     */
    public static function make(\Pimple\Container $di): array
    {
        // Files a stopped process could not remove.
        foreach (glob(sys_get_temp_dir() . '/bfdb*') ?: [] as $stale) {
            if (is_file($stale) && is_writable($stale) && time() - (int) filemtime($stale) > 3600) {
                @unlink($stale);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'bfdb');
        if ($path === false) {
            throw new \RuntimeException('No temporary file could be made for the backup.');
        }
        @chmod($path, 0600);
        try {
            $tables = DatabaseDump::write($di['em']->getConnection(), $path);
        } catch (\Throwable $e) {
            @unlink($path);

            throw $e;
        }

        // The random part keeps a file name from being guessed wherever the file ends up.
        return [$path, 'db_backup_' . date('Y-m-d_His') . '_' . bin2hex(random_bytes(8)) . '.sql.gz', $tables];
    }

    /**
     * The Health check: [state, detail]. Warn when no method is on or the newest backup that was
     * sent is over two days old; needs attention when the last run failed.
     *
     * @return array{0: string, 1: string}
     */
    public static function health(\Pimple\Container $di): array
    {
        $settings = self::settings($di);
        $on = array_keys(array_filter(['SFTP/FTP' => $settings['ftp']['enabled'], 'email' => $settings['email']['enabled'], 'this server' => $settings['local']['enabled']]));
        if ($on === []) {
            return ['warn', 'No daily database backup is on: turn one on in System → Settings → System → Database Backups.'];
        }
        $status = self::state($di, self::STATUS);
        if ($status === null) {
            return ['ok', 'On (' . implode(', ', $on) . '); the first backup is made at the next cron run.'];
        }
        if (!empty($status['running']) && time() - (int) strtotime((string) $status['ran_at']) < 3600) {
            return ['ok', 'A backup is being made now (started ' . $status['ran_at'] . ').'];
        }
        if (empty($status['ok'])) {
            $why = [];
            foreach ((array) ($status['results'] ?? []) as $method => $result) {
                if ($result !== 'ok') {
                    $why[] = $method . ': ' . $result;
                }
            }

            return ['bad', 'The backup of ' . $status['ran_at'] . ' failed (' . implode('; ', $why) . ').'];
        }
        $age = time() - (int) strtotime((string) ($status['last_ok_at'] ?? $status['ran_at']));

        if ($age > 2 * 86400) {
            return ['warn', 'The newest backup sent is ' . (int) floor($age / 86400) . ' days old: check that the cron runs.'];
        }

        return $on === ['this server']
            ? ['warn', 'Made ' . $status['ran_at'] . ', but kept only on this server: add SFTP/FTP or email for a copy that survives losing it.']
            : ['ok', 'Sent ' . $status['ran_at'] . ' by ' . implode(', ', $on) . '.'];
    }

    // ---- Kept on this server.

    /**
     * The folder for kept backups, made owner-only when missing; throws when it cannot be written
     * or lies inside the website (an install in a subfolder of the web root), where the web server
     * could serve it.
     */
    public static function localFolder(): string
    {
        $dir = self::localDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new InformationException('The folder :dir could not be made.', [':dir' => $dir]);
        }
        @chmod($dir, 0700);
        if (!is_writable($dir)) {
            throw new InformationException('The folder :dir cannot be written by the web server user.', [':dir' => $dir]);
        }
        $real = realpath($dir);
        foreach (array_filter([$_SERVER['DOCUMENT_ROOT'] ?? '', PATH_ROOT]) as $root) {
            $root = realpath((string) $root);
            if ($real !== false && $root !== false && str_starts_with($real . '/', rtrim($root, '/') . '/')) {
                throw new InformationException('The folder :dir is inside the website, where it could be downloaded; set CLIENTFENCE_BACKUP_DIR to a folder outside the web root.', [':dir' => $dir]);
            }
        }
        // A web server that reaches the folder anyway gets nothing (Apache).
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        }
        if (!is_file($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }

        return $dir;
    }

    /**
     * Copies the backup into the folder (owner-only file), then removes kept backups older than
     * $days; the newest one always stays.
     */
    public static function keep(string $file, string $name, int $days): void
    {
        $dir = self::localFolder();
        foreach (glob($dir . '/db_backup_*.part') ?: [] as $stale) {
            if (time() - (int) filemtime($stale) > 3600) {
                @unlink($stale);
            }
        }
        $free = @disk_free_space($dir);
        if ($free !== false && $free < 3 * (int) filesize($file)) {
            throw new InformationException('Not enough free disk space to keep the backup on this server.');
        }
        $target = $dir . '/' . $name;
        $partial = $target . '.part';
        if (!@copy($file, $partial) || !@chmod($partial, 0600) || !@rename($partial, $target)) {
            @unlink($partial);

            throw new InformationException('The backup could not be written to :dir.', [':dir' => $dir]);
        }
        $cutoff = time() - max(1, $days) * 86400;
        foreach (array_slice(self::localFiles(), 1) as $kept) {
            $path = $dir . '/' . $kept['name'];
            if ((int) filemtime($path) < $cutoff) {
                @unlink($path);
            }
        }
    }

    // ---- SFTP and FTP through curl (libcurl with libssh: no PHP extension needed).

    /**
     * Uploads $file as $name into the destination folder, or with no file only lists the folder (a
     * connection test).
     *
     * @param array<string, mixed> $ftp
     */
    private static function transfer(\Pimple\Container $di, array $ftp, ?string $file, string $name): void
    {
        $secure = (bool) $ftp['secure'];
        if ($secure && !in_array('sftp', self::curlProtocols(), true)) {
            throw new InformationException('This server\'s curl cannot use SFTP; use FTP or ask the host to add libssh.');
        }
        $password = (string) $ftp['password'] !== '' ? (string) $di['crypt']->decrypt((string) $ftp['password']) : '';
        $folder = self::folder((string) $ftp['path']);
        $host = (string) $ftp['host'];
        $url = ($secure ? 'sftp://' : 'ftp://') . (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . (int) $ftp['port']
            . ($secure ? '/~/' : '/') . $folder . ($file === null ? '' : rawurlencode($name));

        $curl = curl_init();
        $in = null;
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_USERNAME => (string) $ftp['username'],
            CURLOPT_PASSWORD => $password,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_TIMEOUT => $file === null ? self::TIMEOUT * 2 : 3600,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROTOCOLS => $secure ? CURLPROTO_SFTP : CURLPROTO_FTP,
            CURLOPT_REDIR_PROTOCOLS => 0,
            CURLOPT_FTP_USE_EPSV => true,
        ];
        if ($secure) {
            $options[CURLOPT_SSH_HOST_PUBLIC_KEY_MD5] = (string) ($ftp['host_key'] ?? '') !== '' ? (string) $ftp['host_key'] : self::hostKey($ftp);
        }
        if (!$secure && !$ftp['passive']) {
            $options[CURLOPT_FTPPORT] = '-';
        }
        if ($file === null) {
            $options[CURLOPT_DIRLISTONLY] = true;
        } else {
            $in = fopen($file, 'rb');
            if ($in === false) {
                throw new \RuntimeException('The backup file could not be read.');
            }
            $options += [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $in, CURLOPT_INFILESIZE => (int) filesize($file)];
        }
        curl_setopt_array($curl, $options);
        $ok = curl_exec($curl) !== false;
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        if (is_resource($in)) {
            fclose($in);
        }
        if (!$ok) {
            throw new InformationException(self::curlReason($errno, $error, $host, (int) $ftp['port'], $folder));
        }
    }

    private static function curlReason(int $errno, string $error, string $host, int $port, string $folder): string
    {
        // libcurl codes PHP has no constant for: 67 login denied, 78 remote file not found,
        // 9 remote access denied, 25 upload failed, 60 the server's key does not match.
        return match ($errno) {
            CURLE_COULDNT_RESOLVE_HOST => 'The host ' . $host . ' was not found.',
            CURLE_COULDNT_CONNECT => 'Could not connect to ' . $host . ':' . $port . '.',
            CURLE_OPERATION_TIMEDOUT => 'The server ' . $host . ':' . $port . ' did not answer in time.',
            67 => 'Login failed: check the username and password.',
            78, CURLE_FTP_COULDNT_RETR_FILE => 'The folder "' . ($folder === '' ? '(login folder)' : rawurldecode(rtrim($folder, '/'))) . '" was not found; create it first.',
            9, 25 => 'The server refused the file: check that the user may write to the folder.',
            60 => str_contains($error, 'mismatch md5') ? 'The SFTP server\'s key is not the one trusted when the backups were set up: another server may be answering for ' . $host . '. If the server was moved or reinstalled, use Save & Activate to trust its new key.' : 'SFTP failed: ' . $error,
            CURLE_SSH => 'SFTP failed: ' . $error,
            default => 'The transfer failed: ' . ($error !== '' ? $error : 'error ' . $errno) . '.',
        };
    }

    /** The destination folder for the URL: relative to the login folder, "" or "a/b/". */
    private static function folder(string $path): string
    {
        $parts = array_filter(explode('/', trim(str_replace('\\', '/', $path), '/')), static fn (string $p): bool => $p !== '' && $p !== '.');

        return $parts === [] ? '' : implode('/', array_map('rawurlencode', $parts)) . '/';
    }

    /** @return string[] */
    private static function curlProtocols(): array
    {
        $version = function_exists('curl_version') ? curl_version() : false;

        return is_array($version) ? array_map('strtolower', (array) ($version['protocols'] ?? [])) : [];
    }

    // ---- Email: the Email settings' mailer, directly (no queue, no email log).

    private static function email(\Pimple\Container $di, string $address, string $file, string $name): void
    {
        $bytes = (int) filesize($file);
        if ($bytes > self::MAX_EMAIL_MB * 1024 * 1024) {
            throw new InformationException('The backup (' . round($bytes / 1048576, 1) . ' MB) is larger than ' . self::MAX_EMAIL_MB . ' MB, too large to email; use SFTP/FTP.');
        }
        $settings = $di['mod']('email')->getConfig();
        $system = $di['mod_service']('system');
        $from = (string) ($settings['from_email'] ?? '') ?: (string) $system->getParamValue('company_email');
        $company = (string) ($system->getCompany()['name'] ?? '') ?: 'ClientFence';
        if ($from === '') {
            throw new InformationException('Set the sender address in the Email settings first.');
        }
        $subject = $company . ' database backup ' . date('Y-m-d');
        $body = '<p>The ' . htmlspecialchars($company) . ' billing database backup of ' . date('Y-m-d H:i') . ' is attached ('
            . htmlspecialchars($name) . ', ' . round($bytes / 1048576, 2) . ' MB).</p><p>It holds all client data unencrypted: keep it safe. Restore with:<br><code>gunzip &lt; '
            . htmlspecialchars($name) . ' | mysql database_name</code></p><p>Sent by ClientFence (System → Settings → System → Database Backups).</p>';
        $mail = new \ClientFence\Mail(['email' => $from, 'name' => (string) ($settings['from_name'] ?? '') ?: $company], $address, $subject, $body, $settings['mailer'] ?? 'sendmail', $settings['custom_dsn'] ?? null);
        $mail->attach((string) file_get_contents($file), $name, 'application/gzip');
        if (!\ClientFence\Environment::isProduction()) {
            $di['logger']->setChannel('email')->info('Skip email sending. Application ENV: ' . \ClientFence\Environment::getCurrentEnvironment());

            return;
        }
        $mail->send($settings);
    }

    // ---- Storage (mod_clientfence_state).

    /**
     * @param array<string, mixed> $old
     *
     * @return array<string, mixed>
     */
    public static function ftpFrom(\Pimple\Container $di, array $data, array $old): array
    {
        $host = trim((string) ($data['host'] ?? ''), " \t[]");
        $port = (int) ($data['port'] ?? 0);
        $username = trim((string) ($data['username'] ?? ''));
        $path = trim((string) ($data['path'] ?? ''));
        foreach ([$host, $username, $path, (string) ($data['password'] ?? '')] as $value) {
            if (preg_match('/[\r\n\0]/', $value) === 1) {
                throw new InformationException('Line breaks are not allowed in the backup settings.');
            }
        }
        if ($host === '' || preg_match('/[\s\/@?#]/', $host) === 1) {
            throw new InformationException('Enter the server host name or IP address, without ftp:// or a path.');
        }
        if ($port < 1 || $port > 65535) {
            throw new InformationException('Enter the port (22 for SFTP, 21 for FTP).');
        }
        if ($username === '') {
            throw new InformationException('Enter the username.');
        }
        if (in_array('..', explode('/', str_replace('\\', '/', $path)), true)) {
            throw new InformationException('The folder may not contain "..".');
        }
        $password = (string) ($data['password'] ?? '');
        $stored = $password !== '' ? (string) $di['crypt']->encrypt($password) : (string) $old['password'];
        if ($stored === '') {
            throw new InformationException('Enter the password.');
        }

        return [
            'enabled' => false,
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $stored,
            'path' => $path,
            'secure' => self::flag($data['secure'] ?? false),
            'passive' => self::flag($data['passive'] ?? false),
            // The trusted key stays only for the same server.
            'host_key' => $host === (string) $old['host'] && $port === (int) $old['port'] ? (string) ($old['host_key'] ?? '') : '',
        ];
    }

    /**
     * ClientFence core patch: the SFTP server's key (MD5, the one fingerprint both libcurl SSH backends check). Asked
     * for with a key that cannot match: libcurl refuses before signing in and names the server's key. Without it the
     * password and the backups went to any server that answered for the host.
     */
    private static function hostKey(array $ftp): string
    {
        $host = (string) $ftp['host'];
        $curl = curl_init('sftp://' . (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . (int) $ftp['port'] . '/~/');
        curl_setopt_array($curl, [
            CURLOPT_SSH_HOST_PUBLIC_KEY_MD5 => str_repeat('0', 32),
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT * 2,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROTOCOLS => CURLPROTO_SFTP,
            CURLOPT_REDIR_PROTOCOLS => 0,
        ]);
        curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        if (preg_match('/Remote ([0-9a-f]{32}) is not equal/i', $error, $m) === 1) {
            return strtolower($m[1]);
        }

        throw new InformationException($errno === 60 || $errno === CURLE_SSH ? 'The SFTP server\'s key could not be read: ' . $error : self::curlReason($errno, $error, $host, (int) $ftp['port'], ''));
    }

    /** SFTP details saved before keys were kept trust the server's key at their next run, and keep it. */
    private static function pinned(\Pimple\Container $di, array $ftp): array
    {
        if ($ftp['secure'] && (string) $ftp['host_key'] === '') {
            $ftp['host_key'] = self::hostKey($ftp);
            self::saveMethod($di, 'ftp', $ftp);
        }

        return $ftp;
    }

    /**
     * Whether the details still point at the server that passed its test. Passwords are compared
     * decrypted: each encryption differs, so the same password typed again still matches.
     */
    private static function sameServer(\Pimple\Container $di, array $old, array $new): bool
    {
        foreach (['host', 'port', 'username', 'path', 'secure', 'passive'] as $key) {
            if ((string) $old[$key] !== (string) $new[$key]) {
                return false;
            }
        }
        if ((string) $old['password'] === (string) $new['password']) {
            return true;
        }
        try {
            return (string) $old['password'] !== '' && hash_equals((string) $di['crypt']->decrypt((string) $old['password']), (string) $di['crypt']->decrypt((string) $new['password']));
        } catch (\Throwable) {
            return false;
        }
    }

    /** The email (base64 of up to 20 MB) and the dump need room; raise a lower limit to 512 MB. */
    public static function raiseMemoryLimit(): void
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '-1') {
            return;
        }
        $bytes = (int) $limit * match (strtolower(substr($limit, -1))) {
            'g' => 1 << 30,
            'm' => 1 << 20,
            'k' => 1 << 10,
            default => 1,
        };
        if ($bytes < 512 << 20) {
            @ini_set('memory_limit', '512M');
        }
    }

    private static function saveMethod(\Pimple\Container $di, string $method, array $values): void
    {
        $settings = self::settings($di);
        $settings[$method] = $values;
        self::saveState($di, self::SETTINGS, $settings);
    }

    private static function state(\Pimple\Container $di, string $name): ?array
    {
        try {
            $row = $di['em']->getConnection()->fetchOne('SELECT value FROM mod_clientfence_state WHERE name = ?', [$name]);
        } catch (\Throwable) {
            return null;
        }
        $value = is_string($row) ? json_decode($row, true) : null;

        return is_array($value) ? $value : null;
    }

    private static function saveState(\Pimple\Container $di, string $name, array $value): void
    {
        Service::ensureTables($di, true);
        $now = date('Y-m-d H:i:s');
        $di['em']->getConnection()->executeStatement(
            'INSERT INTO mod_clientfence_state (name, value, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
            [$name, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $now]
        );
    }

    private static function flag(mixed $value): bool
    {
        return is_bool($value) ? $value : is_scalar($value) && in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }
}
