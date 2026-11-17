<?php

declare(strict_types=1);

namespace ClientFence;

/**
 * Cron started from a root crontab runs as the user that owns the install: files it writes as root (cache, logs,
 * uploads) break the site and later updates.
 */
final class SiteUser
{
    /** Required by the entry scripts before load.php, so it uses no other class. */
    public static function switchFromRoot(string $root): void
    {
        $owner = fileowner(is_dir($root . '/data') ? $root . '/data' : $root);
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0 || $owner === false || $owner === 0) {
            return;
        }
        $user = posix_getpwuid($owner);
        if ($user === false || !posix_initgroups($user['name'], $user['gid']) || !posix_setgid($user['gid']) || !posix_setuid($owner)) {
            fwrite(STDERR, "Could not switch from root to the site's user. Run it as the site's user: sudo -u <site user> php " . basename($_SERVER['argv'][0] ?? 'cron.php') . "\n");
            exit(1);
        }
        putenv('HOME=' . $user['dir']);
    }
}
