<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence;

use PhpZip\ZipFile;

/**
 * The backup System → Update makes before it unpacks a release (\ClientFence\Update::performUpdate()):
 * in a folder of its own under the server backups folder (Backups::localDir(), outside the web
 * root), a database dump (database.sql.gz), every file the release replaces (files.zip) and the
 * files it adds (added.json).
 *
 * restoreFiles() puts the files back when unpacking fails. When the database update fails, the
 * database is loaded back from the dump (DatabaseDump::restore()) and the files are put back too,
 * so the site runs the version it ran before, as it was.
 *
 * Each update keeps only its own backup: once it is made, prune() removes the older update
 * backups, so updates do not fill the server's disk.
 */
final class UpdateBackup
{
    /** Folder names make() gives: update-<from>-to-<to>-<Ymd-His>. Only these are pruned. */
    private const DIR_PATTERN = '/\Aupdate-[A-Za-z0-9._-]+-to-[A-Za-z0-9._-]+-\d{8}-\d{6}\z/';

    /**
     * @return array{dir: string, database: string, files: string, replaced: int, added: list<string>}
     */
    public static function make(\Pimple\Container $di, string $archiveFile, string $toVersion): array
    {
        $safe = static fn (string $v): string => (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $v);
        $dir = Backups::localFolder() . '/update-' . $safe(\ClientFence\Version::CLIENTFENCE) . '-to-' . $safe($toVersion) . '-' . date('Ymd-His');
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true)) {
            throw new \RuntimeException('The backup folder could not be made: ' . $dir);
        }

        Backups::raiseMemoryLimit();
        [$tmp] = Backups::make($di);
        $database = $dir . '/database.sql.gz';
        $copied = @copy($tmp, $database);
        @unlink($tmp);
        if (!$copied) {
            throw new \RuntimeException('The database backup could not be saved in ' . $dir);
        }
        @chmod($database, 0o600);

        $release = new ZipFile();
        $release->openFile($archiveFile);
        $entries = $release->getListFiles();
        $release->close();

        $backup = new ZipFile();
        $added = [];
        $replaced = 0;
        foreach ($entries as $entry) {
            if (!\ClientFence\Update::isSafeArchiveEntry($entry)) {
                throw new \RuntimeException('The update archive contains an unsafe file path: ' . $entry);
            }
            if (str_ends_with($entry, '/')) {
                continue;
            }
            $path = PATH_ROOT . '/' . $entry;
            if (is_file($path)) {
                $backup->addFile($path, $entry);
                ++$replaced;
            } elseif (!file_exists($path)) {
                $added[] = $entry;
            }
        }
        $files = $dir . '/files.zip';
        $backup->saveAsFile($files);
        $backup->close();
        @chmod($files, 0o600);
        if (file_put_contents($dir . '/added.json', json_encode($added, JSON_UNESCAPED_SLASHES)) === false) {
            throw new \RuntimeException('The backup list could not be saved in ' . $dir);
        }
        @chmod($dir . '/added.json', 0o600);

        // A backup that could not be pruned must not stop the update.
        try {
            $removed = self::prune($dir);
            if ($removed !== []) {
                $di['logger']->setChannel('update')->info('Removed older update backups: ' . implode(', ', $removed));
            }
        } catch (\Throwable $e) {
            $di['logger']->setChannel('update')->error('Older update backups could not be removed: ' . $e->getMessage());
        }

        return ['dir' => $dir, 'database' => $database, 'files' => $files, 'replaced' => $replaced, 'added' => $added];
    }

    /**
     * Removes every update backup in the folder of $keep except $keep itself. Only folders named as
     * make() names them are touched, never a symbolic link or anything outside them; the daily
     * database backups next to them stay.
     *
     * @return list<string> the names of the removed folders
     */
    public static function prune(string $keep): array
    {
        $parent = dirname($keep);
        $removed = [];
        foreach (scandir($parent) ?: [] as $name) {
            $path = $parent . '/' . $name;
            if ($name === basename($keep) || preg_match(self::DIR_PATTERN, $name) !== 1 || is_link($path) || !is_dir($path)) {
                continue;
            }
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) {
                $ok = $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
                if (!$ok) {
                    throw new \RuntimeException('Could not remove ' . $item->getPathname());
                }
            }
            if (!@rmdir($path)) {
                throw new \RuntimeException('Could not remove ' . $path);
            }
            $removed[] = $name;
        }

        return $removed;
    }

    /**
     * The files the release would write that the web server user cannot: existing files it may not
     * overwrite, and new files whose nearest existing folder it may not write to. Checked before
     * anything changes, so a file the update cannot write never leaves the site half-updated.
     *
     * @return list<string>
     */
    public static function unwritable(string $archiveFile, string $root = PATH_ROOT): array
    {
        $release = new ZipFile();
        $release->openFile($archiveFile);
        $entries = $release->getListFiles();
        $release->close();

        $blocked = [];
        foreach ($entries as $entry) {
            if (str_ends_with($entry, '/') || !\ClientFence\Update::isSafeArchiveEntry($entry)) {
                continue;
            }
            $path = $root . '/' . $entry;
            if (file_exists($path)) {
                $ok = is_file($path) && is_writable($path);
            } else {
                $dir = dirname($path);
                while (!file_exists($dir) && $dir !== dirname($dir)) {
                    $dir = dirname($dir);
                }
                $ok = is_dir($dir) && is_writable($dir);
            }
            if (!$ok) {
                $blocked[] = $entry;
            }
        }

        return $blocked;
    }

    /**
     * Puts the replaced files back and removes the ones the release added, file by file, so one
     * file that cannot be written does not stop the others. Throws at the end, naming the files
     * that could not be put back or removed.
     *
     * @param array{dir: string, database: string, files: string, replaced: int, added: list<string>} $backup
     */
    public static function restoreFiles(array $backup, string $root = PATH_ROOT): void
    {
        $failed = [];
        // Free space first when unpacking has filled the disk.
        clearstatcache();
        foreach ($backup['added'] as $entry) {
            // Unpacking may have stopped before it wrote the file.
            $path = $root . '/' . $entry;
            if (\ClientFence\Update::isSafeArchiveEntry($entry) && is_file($path) && !@unlink($path)) {
                $failed[] = $entry;
            }
        }
        $zip = new ZipFile();
        $zip->openFile($backup['files']);
        foreach ($zip->getListFiles() as $entry) {
            $path = $root . '/' . $entry;
            if (str_ends_with($entry, '/') || !\ClientFence\Update::isSafeArchiveEntry($entry)) {
                continue;
            }
            $contents = $zip->getEntryContents($entry);
            if (is_file($path) && @file_get_contents($path) === $contents) {
                continue;
            }
            if (@file_put_contents($path, $contents) !== strlen($contents)) {
                $failed[] = $entry;
            }
        }
        $zip->close();
        if ($failed !== []) {
            throw new \RuntimeException('These files could not be put back: ' . implode(', ', array_slice($failed, 0, 20)));
        }
    }

    /**
     * ZipFile::extractTo(), keeping PHP's error handlers as they were: when a file cannot be
     * opened, PhpZip throws from inside its own error handler and leaves that handler set, which
     * turns every later warning of the request (even a silenced one) into its exception.
     *
     * The files are dated now: a zip stores local times without a time zone, so on a server in
     * another zone PhpZip dated them hours ahead, and until then OPcache would not cache them and
     * Twig recompiled the changed templates on every request.
     *
     * ClientFence core patch: only files whose content differs are written. Rewriting an unchanged
     * .htaccess made LiteSpeed restart during every update, and a page loading then stayed blank.
     */
    public static function extract(ZipFile $zip, string $dir): void
    {
        $changed = array_values(array_filter($zip->getListFiles(), static function (string $name) use ($zip, $dir): bool {
            $path = $dir . '/' . $name;

            return !is_file($path) || filesize($path) !== $zip->getEntry($name)->getUncompressedSize()
                || @file_get_contents($path) !== $zip->getEntryContents($name);
        }));
        if ($changed === []) {
            return;
        }

        $guard = static fn (): bool => false;
        set_error_handler($guard);
        $extracted = [];
        try {
            $zip->extractTo($dir, $changed, [], $extracted);
            // ClientFence core patch: a short write (a full disk or quota) is only a notice to PhpZip; a cut-off
            // PHP file then took the site down while the update said it succeeded. The caller puts the old files back.
            clearstatcache();
            $short = array_filter($changed, static fn (string $name): bool => !str_ends_with($name, '/')
                && @filesize($dir . '/' . $name) !== $zip->getEntry($name)->getUncompressedSize());
            if ($short !== []) {
                throw new \RuntimeException('Files were not written in full (is the disk or quota full?): ' . implode(', ', array_slice($short, 0, 5)));
            }
            $now = time();
            foreach (array_keys($extracted) as $file) {
                @touch($file, $now);
            }
        } finally {
            for ($i = 0; $i < 32; ++$i) {
                $current = set_error_handler(static fn (): bool => false);
                restore_error_handler();
                restore_error_handler();
                if ($current === $guard) {
                    break;
                }
            }
        }
    }
}
