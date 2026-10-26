<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence;

use PhpZip\ZipFile;

/**
 * The files of a release that were changed on this site. Each release ships LIST (written by
 * tools/build-release.sh): the SHA-256 of every file in it. Before an update, System → Update
 * compares the install with the list of the installed version, lists the changed files and offers
 * them as a zip, since the update overwrites them. Files added on the site are not in any list and
 * stay as they are; files that are gone (the install folder) are not changes.
 */
final class Customized
{
    public const string LIST = 'library/ClientFence/release-files.json';

    /**
     * The changed files, or null when the installed version has no list (installed from source,
     * or from a release before the list existed).
     *
     * @return list<string>|null
     */
    public static function files(string $root = PATH_ROOT): ?array
    {
        $list = json_decode((string) @file_get_contents($root . '/' . self::LIST), true);
        if (!is_array($list) || ($list['version'] ?? null) !== \ClientFence\Version::CLIENTFENCE || !is_array($list['files'] ?? null)) {
            return null;
        }
        $changed = [];
        foreach ($list['files'] as $path => $hash) {
            if (!is_string($path) || !\ClientFence\Update::isSafeArchiveEntry($path)) {
                continue;
            }
            $file = $root . '/' . $path;
            if (is_file($file) && hash_file('sha256', $file) !== $hash) {
                $changed[] = $path;
            }
        }
        sort($changed);

        return $changed;
    }

    /** A zip of the changed files in a temporary file; the caller removes it. */
    public static function zip(string $root = PATH_ROOT): string
    {
        $files = self::files($root) ?? [];
        $zip = new ZipFile();
        foreach ($files as $path) {
            $zip->addFile($root . '/' . $path, $path);
        }
        $out = tempnam(sys_get_temp_dir(), 'cf-changed-');
        if ($out === false) {
            throw new \RuntimeException('No temporary file for the zip.');
        }
        $zip->saveAsFile($out);
        $zip->close();

        return $out;
    }
}
