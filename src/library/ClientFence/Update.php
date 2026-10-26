<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence;

use PhpZip\ZipFile;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Contracts\Cache\ItemInterface;

class Update implements InjectionAwareInterface
{
    /**
     * Name of the marker file that tells load.php to refuse all requests while
     * performUpdate() is writing files to PATH_ROOT.
     *
     * This filename is duplicated as a literal string at the top of load.php,
     * which has to check for the lock before the Composer autoloader (and
     * therefore this class) is available. Keep both in sync if you change it.
     */
    public const string LOCK_FILENAME = '.update-lock';

    /**
     * How long a lock file is honored before it's treated as abandoned - see the
     * comment above isCoreUpdateLockActive() in load.php, which applies the same
     * window to decide whether to keep refusing requests.
     */
    private const int LOCK_STALE_SECONDS = 600;

    protected ?\Pimple\Container $di = null;
    private Filesystem $filesystem;

    public function __construct()
    {
        $this->filesystem = new Filesystem();
    }

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
        if (isset($di['filesystem'])) {
            $this->filesystem = $di['filesystem'];
        }
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    /**
     * Get the branch configured to update from.
     *
     * @return string branch to update from
     */
    public function getUpdateBranch(): string
    {
        // ClientFence: releases only, no preview builds.
        return 'release';
    }

    /**
     * Get latest release notes for the configured update branch.
     *
     * @return string release notes for the latest version
     */
    public function getLatestReleaseNotes(): string
    {
        $updateBranch = $this->getUpdateBranch();

        return $this->getLatestVersionInfo($updateBranch)['release_notes'];
    }

    /**
     * Get latest version number for the configured update branch.
     *
     * @return string version number of the latest version
     */
    public function getLatestVersion(): string
    {
        // ClientFence: the newer release the last daily check found (no network call), else this one.
        return (string) (\ClientFence\Mod\Clientfence\Updates::available($this->di)['version'] ?? Version::CLIENTFENCE);
    }

    /**
     * Builds a complete changelog for all updates between the the newest FOSSBilling version and an ending version number.
     *
     * @param string $end (optional) What version number to end on. Defaults to the current version.
     */
    private function buildCompleteChangelog(string $end = Version::VERSION): string
    {
        if (Version::isPreviewVersion($end)) {
            return 'Changelogs are not available when updating from a preview release';
        }

        return $this->di['cache']->get("changelog_from_$end", function (ItemInterface $item) use ($end) {
            $item->expiresAfter(3600);

            $httpClient = $this->di['http_client'];
            $response = $httpClient->request('GET', "https://api.fossbilling.net/versions/v1/build_changelog/{$end}");
            $result = $response->toArray();

            return $result['result'];
        });
    }

    /**
     * Returns information about the latest version of the specified branch.
     *
     * @param string $branch  the branch to return the latest information for;
     *                        valid values are: 'preview' or 'release'
     * @param bool   $refetch Set to `true` to have FOSSBilling invalidate the update cache and fetch the latest info
     *
     * @throws Exception if there is an error downloading the latest
     *                   version information
     */
    public function getLatestVersionInfo(?string $branch = null, bool $refetch = false): array
    {
        // ClientFence core patch: the ClientFence release feed (Mod\Clientfence\Updates), checked once a
        // day (always with $refetch); there is no preview branch (beta releases are the feed's opt-in
        // channel).
        $last = \ClientFence\Mod\Clientfence\Updates::check($this->di, $refetch);
        $release = $last['release'];
        $newer = $release !== null && \ClientFence\Mod\Clientfence\Updates::isNewer((string) $release['version']);
        $checkedAt = $last['checked_at'];

        return [
            'version' => $newer ? (string) $release['version'] : Version::CLIENTFENCE,
            'download_url' => $newer ? $release['download_url'] : null,
            'release_date' => $newer ? $release['release_date'] : null,
            'release_notes' => $newer ? (string) $release['notes'] : '',
            'update_type' => $newer ? Version::getUpdateType((string) $release['version'], Version::CLIENTFENCE) : 0,
            'last_check' => $checkedAt,
            'next_check' => $checkedAt !== null ? date('Y-m-d H:i:s', (int) strtotime($checkedAt) + \ClientFence\Mod\Clientfence\Updates::CHECK_EVERY) : null,
            'branch' => 'release',
            'minimum_php_version' => $newer ? (string) $release['minimum_php_version'] : 'unknown',
            'digest' => $newer ? $release['sha256'] : null,
            'check_enabled' => \ClientFence\Mod\Clientfence\Updates::enabled($this->di),
            'channel' => \ClientFence\Mod\Clientfence\Updates::channel($this->di),
            'release_channel' => $newer ? (string) ($release['channel'] ?? 'stable') : null,
            'check_error' => $last['error'],
        ];
    }

    /**
     * Resolve the SHA-256 digest for the exact update archive being downloaded.
     */
    private function getArchiveDigest(array $releaseInfo): string
    {
        if (!isset($releaseInfo['digest'])) {
            throw new InformationException('The ClientFence release feed did not provide a SHA-256 digest. Update canceled for security reasons.');
        }

        return $this->normalizeSha256Digest($releaseInfo['digest']);
    }

    private function normalizeSha256Digest(mixed $digest): string
    {
        if (!is_string($digest)) {
            throw new InformationException('The ClientFence release feed provided an invalid SHA-256 digest. Update canceled for security reasons.');
        }

        $digest = trim($digest);
        if (str_starts_with(strtolower($digest), 'sha256:')) {
            $digest = substr($digest, 7);
        }

        if (preg_match('/\A[0-9a-fA-F]{64}\z/', $digest) !== 1) {
            throw new InformationException('The ClientFence release feed provided an invalid SHA-256 digest. Update canceled for security reasons.');
        }

        return strtolower($digest);
    }

    private function validateDownloadedArchive(string $archiveFile, array $releaseInfo): void
    {
        try {
            $expectedDigest = $this->getArchiveDigest($releaseInfo);
            $actualDigest = hash_file('sha256', $archiveFile);

            if ($actualDigest === false || !hash_equals($expectedDigest, $actualDigest)) {
                throw new InformationException('The downloaded update archive failed integrity verification. Update canceled for security reasons.');
            }
        } catch (Exception $e) {
            $this->removeDownloadedArchive($archiveFile);

            throw $e;
        }
    }

    private function removeDownloadedArchive(string $archiveFile): void
    {
        try {
            $this->filesystem->remove($archiveFile);
        } catch (IOException $e) {
            $this->di['logger']->setChannel('update')->error($e->getMessage());
        }
    }

    /**
     * Check if an update is available for the current FOSSBilling version.
     *
     * @return bool true if update is available, false if not
     */
    public function isUpdateAvailable(): bool
    {
        // ClientFence: the last daily check against the installed ClientFence release, without reaching
        // the network (the dashboard asks on every load).
        return \ClientFence\Mod\Clientfence\Updates::available($this->di) !== null;
    }

    public function isBehindOnDBPatches(): bool
    {
        $patcher = new UpdatePatcher();
        $patcher->setDi($this->di);

        return $patcher->availablePatches() > 0;
    }

    /**
     * Perform manual update - apply patches and update config.
     *
     * @throws Exception
     */
    public function performManualUpdate(): void
    {
        try {
            $this->filesystem->remove(PATH_CACHE);
            $this->filesystem->mkdir(PATH_CACHE, 0o755);
        } catch (IOException) {
            // Best effort: continue with patching even if the pre-clear fails.
        }

        // Apply system patches and migrate configuration file.
        $patcher = new UpdatePatcher();
        $patcher->setDi($this->di);
        $patcher->applyConfigPatches(force: true);
        $patcher->applyCorePatches(force: true);

        try {
            $this->filesystem->remove(PATH_CACHE);
            $this->filesystem->mkdir(PATH_CACHE, 0o755);
        } catch (IOException $e) {
            error_log($e->getMessage());

            throw new Exception('Unable to clear the cache after applying manual update patches. Further details are available in the error log.');
        }
    }

    /**
     * Perform system update.
     *
     * @throws InformationException if latest version already installed
     * @throws Exception            if unable to download the update archive
     * @throws Exception            if unable to extract the update archive
     */
    public function performUpdate(): void
    {
        $finalization = $this->di['update_finalization'];
        if ($finalization->isRequired()) {
            throw new InformationException('An update finalization is already pending. Complete finalization before starting another update.');
        }

        $readiness = $this->di['update_readiness']->check();
        if (!$readiness['can_update']) {
            throw new Exception('ClientFence does not have sufficient filesystem permissions to perform the update. Resolve the reported issues before trying again.', null, 820);
        }

        $updateBranch = $this->getUpdateBranch();
        if ($updateBranch !== 'preview' && !$this->isUpdateAvailable()) {
            throw new InformationException('You have the latest version of ClientFence. You do not need to update.');
        }

        $this->di['logger']->setChannel('update')->info('Started the ClientFence update');
        // ClientFence core patch: the release and its archive as the feed names them now.
        $releaseInfo = $this->getLatestVersionInfo($updateBranch, true);
        if ($releaseInfo['download_url'] === null) {
            throw new InformationException('You have the latest version of ClientFence. You do not need to update.');
        }
        $latestVersionNum = (string) $releaseInfo['version'];
        $archiveFile = Path::join(PATH_CACHE, 'clientfence-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $latestVersionNum) . '.zip');

        // Validate the required PHP version is met
        $requiredPHPVersion = $releaseInfo['minimum_php_version'];
        if ($requiredPHPVersion !== 'unknown' && version_compare(PHP_VERSION, $requiredPHPVersion, '<')) {
            throw new InformationException('ClientFence :version: requires at least PHP :min_php:, but you are running :current_php:.', [':version:' => $latestVersionNum, ':min_php:' => $requiredPHPVersion, ':current_php:' => PHP_VERSION]);
        }

        // Perform a sanity check that the download URL is a trusted one
        // (ClientFence core patch: the release feed's own folder).
        if ($updateBranch !== 'preview') {
            $allowed = is_string($releaseInfo['download_url']) && str_starts_with($releaseInfo['download_url'], \ClientFence\Mod\Clientfence\Updates::trustedPrefix());

            if (!$allowed) {
                throw new InformationException('The download URL for this release was not specified as a trusted one. Update canceled for security reasons.');
            }
        }

        // Download latest version archive for configured update branch.
        try {
            $httpClient = $this->di['http_client']->withOptions([
                'timeout' => 30,
                'max_duration' => 120,
            ]);
            $response = $httpClient->request('GET', $releaseInfo['download_url'], ['max_redirects' => 0]);

            $fileHandler = fopen($archiveFile, 'w');
            if ($fileHandler === false) {
                throw new \RuntimeException('Unable to create the update archive.');
            }

            try {
                foreach ($httpClient->stream($response) as $chunk) {
                    $content = (string) $chunk->getContent();
                    $written = fwrite($fileHandler, $content);
                    if ($written === false || $written !== strlen($content)) {
                        throw new \RuntimeException('Unable to write the update archive.');
                    }
                }
            } finally {
                fclose($fileHandler);
            }
        } catch (\Throwable $e) {
            $this->removeDownloadedArchive($archiveFile);
            $this->di['logger']->setChannel('update')->error($e->getMessage());

            throw new Exception('Failed to download the update archive. Further details are available in the error log.');
        }

        // The preview branch's release info isn't sourced from the versions API this
        // digest comes from, so it has nothing to verify against here.
        if ($updateBranch !== 'preview') {
            $this->validateDownloadedArchive($archiveFile, $releaseInfo);
        }

        // ClientFence core patch: the backup and unpacking of a full release can outlast the request's
        // time limit, and a request stopped while unpacking leaves the site half-updated.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        // ClientFence core patch: every file the release writes must be writable, or nothing changes.
        try {
            $blocked = \ClientFence\Mod\Clientfence\UpdateBackup::unwritable($archiveFile);
        } catch (\Throwable $e) {
            $this->removeDownloadedArchive($archiveFile);

            throw new Exception('The update archive could not be read. Further details are available in the error log.');
        }
        if ($blocked !== []) {
            $this->removeDownloadedArchive($archiveFile);
            $this->di['logger']->setChannel('update')->error('Files the update cannot write: ' . implode(', ', $blocked));

            throw new InformationException('The web server cannot write :file, which this release replaces or adds (:count such files in all). Fix their owner or permissions and try again; nothing was changed.', [':count' => count($blocked), ':file' => $blocked[0]]);
        }

        // ClientFence core patch: a backup of the database and of every file the release replaces
        // (Mod\Clientfence\UpdateBackup), before anything changes; no backup, no update.
        try {
            $backup = \ClientFence\Mod\Clientfence\UpdateBackup::make($this->di, $archiveFile, $latestVersionNum);
        } catch (\Throwable $e) {
            $this->removeDownloadedArchive($archiveFile);
            $this->di['logger']->setChannel('update')->error('The backup before the update failed: ' . $e->getMessage());

            throw new Exception('The backup before the update failed, so nothing was changed. Further details are available in the error log.');
        }
        $this->di['logger']->setChannel('update')->info('Backup before the update: ' . $backup['dir']);

        $finalization->createPendingState(Version::CLIENTFENCE, $latestVersionNum, [
            'branch' => $updateBranch,
            'update_type' => $releaseInfo['update_type'] ?? Version::getUpdateType($latestVersionNum),
            'source' => 'auto-update',
        ]);

        /*
         * From here until the lock is released below, files under PATH_ROOT are
         * being overwritten in place while the site may still be serving other
         * requests (this HTTP request is the only one that knows an update is
         * running). A request that lands mid-extraction can autoload a mix of
         * old and new class files and fatal out with an "incompatible
         * declaration" error - see https://github.com/FOSSBilling/FOSSBilling/issues/4159.
         *
         * load.php refuses to serve any request while this lock file exists (and
         * self-expires it in case this process dies before the `finally` below
         * runs), so create it before touching any files and make sure PHP keeps
         * running even if the client triggering the update disconnects mid-request.
         *
         * The create is exclusive (fails if the file already exists) so two
         * updates triggered at the same time can't both extract into PATH_ROOT
         * concurrently - the loser is turned away instead of corrupting the
         * extraction. A leftover lock from a run that never reached the
         * `finally` below (the process was killed outright) is treated as
         * abandoned once it's older than LOCK_STALE_SECONDS.
         */
        $lockFile = Path::join(PATH_ROOT, self::LOCK_FILENAME);
        if ($this->filesystem->exists($lockFile) && (time() - (int) @filemtime($lockFile)) > self::LOCK_STALE_SECONDS) {
            $this->filesystem->remove($lockFile);
        }

        // fopen(..., 'x') rather than Filesystem here: it's the only way to make the
        // create atomic (it fails if the file already exists), which is what makes
        // this a real mutex against two updates racing each other below.
        $lockHandle = @fopen($lockFile, 'x');
        if ($lockHandle === false) {
            throw new InformationException('Another update appears to already be in progress. Please wait for it to finish before trying again.');
        }
        fclose($lockHandle);
        ignore_user_abort(true);

        try {
            // Extract latest version archive on top of the current version.
            try {
                $zip = new ZipFile();
                $zip->openFile($archiveFile);
                foreach ($zip->getListFiles() as $entryName) {
                    if (!self::isSafeArchiveEntry($entryName)) {
                        throw new Exception('The update archive contains an unsafe file path and cannot be extracted.');
                    }
                }
                \ClientFence\Mod\Clientfence\UpdateBackup::extract($zip, PATH_ROOT);
                $zip->close();
            } catch (\Throwable $e) {
                $this->di['logger']->setChannel('update')->error($e->getMessage());
                // ClientFence core patch: put the old files back (UpdateBackup); the site then runs
                // the version it ran before, so the pending update is cancelled.
                try {
                    \ClientFence\Mod\Clientfence\UpdateBackup::restoreFiles($backup);
                    $this->invalidateOpcodeCache();
                    $finalization->cancelPendingState();
                } catch (\Throwable $restoreError) {
                    $this->di['logger']->setChannel('update')->error('Restoring the files failed: ' . $restoreError->getMessage());

                    throw new Exception('The release could not be unpacked, and putting the previous files back failed too: restore them from :file. Further details are available in the error log.', [':file' => $backup['files']]);
                }

                throw new Exception('The release could not be unpacked: check the free disk space and the file and folder permissions. The previous files were put back and the site runs ClientFence :version again. Further details are available in the error log.', [':version' => Version::CLIENTFENCE]);
            }

            // Extraction is the slow part; refresh the lock now so a legitimately
            // long-running update isn't mistaken for an abandoned one while the
            // patches below are still applying.
            $this->filesystem->touch($lockFile);

            // Files on disk just changed underneath the running workers - reset
            // opcache so finalization below runs against the new code rather
            // than stale pre-update classes.
            $this->invalidateOpcodeCache();

            /*
             * Apply pending config/database patches and remove the install folder
             * now, while the admin who triggered the update is still authenticated.
             *
             * This must happen before the session is destroyed below: the login
             * screen (and the Doctrine queries it runs to authenticate the admin)
             * is generated against the newly extracted code, which can expect
             * database columns that only the patches below create. Deferring this
             * work until after the forced logout would leave the admin unable to
             * log back in - and therefore unable to reach the finalization screen -
             * until the schema is patched. See the class docblock on
             * UpdateFinalization for the rest of the finalization flow.
             */
            try {
                $finalization->finalizeUpdate();
            } catch (\Throwable $e) {
                // ClientFence core patch: the files are new; Finalize update retries the database part.
                $this->di['logger']->setChannel('update')->error('The database update failed: ' . $e->getMessage());

                throw new Exception('The new files are in place but the database update failed. Sign in again and use Finalize update to retry; the backup made before the update is in :dir. Further details are available in the error log.', [':dir' => $backup['dir']]);
            }
        } finally {
            $this->filesystem->remove($lockFile);
        }

        // Log off the current user and destroy the session.
        $this->di['session']->destroy('admin');
    }

    /**
     * Drops PHP's opcode cache so workers compile the just-extracted files.
     *
     * Best effort: opcache may be absent (e.g. CLI) or restricted, in which
     * case there is nothing stale to clear and the update proceeds as before.
     */
    protected function invalidateOpcodeCache(): void
    {
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    /**
     * Whether a release archive entry name is safe to extract under PATH_ROOT
     * (Zip Slip / CWE-22 guard).
     *
     * nelexa/zip normalizes entry names by splitting on '/' only, so on a
     * Windows host an entry such as `..\..\poc.php` isn't recognized as
     * traversal and extractTo() can write outside PATH_ROOT (CVE-2026-16767).
     * Normalizing backslashes to forward slashes before checking for '..'
     * segments and absolute paths closes that gap on every platform.
     */
    public static function isSafeArchiveEntry(string $entryName): bool
    {
        $normalized = str_replace('\\', '/', $entryName);

        if (str_starts_with($normalized, '/') || preg_match('#^[a-zA-Z]:#', $normalized)) {
            return false;
        }

        foreach (explode('/', $normalized) as $segment) {
            // Windows strips trailing spaces and periods from path components, so
            // '.. .' or '...' resolve to '..' at extraction time even though they
            // don't match it literally here. Reject any segment that is nothing
            // but dots and/or spaces rather than just the exact '..' segment.
            if ($segment !== '' && trim($segment, ' .') === '') {
                return false;
            }
        }

        return true;
    }
}
