<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence;

use ClientFence\InformationException;
use ClientFence\Mod\Activity\Service as ActivityService;
use ClientFence\Mod\Support\MailImport;
use ClientFence\Mod\Support\TicketFiles;

/**
 * The "Database" tab of System > Settings (CF-150, as WHMCS's Database Status and System Cleanup): the
 * tables by size, OPTIMIZE TABLE for the tables with free space, and the cron clean-ups run at once with the
 * retention settings as they are. Super Administrators only (Api\Admin db_*).
 */
final class DatabaseMaintenance
{
    /** @return list<array{name: string, rows: int, size: int, free: int}> this database's tables, biggest first */
    public static function tables(\Pimple\Container $di): array
    {
        $rows = $di['dbal']->fetchAllAssociative(
            "SELECT TABLE_NAME AS name, TABLE_ROWS AS row_count, DATA_LENGTH + INDEX_LENGTH AS size, DATA_FREE AS free
               FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
              ORDER BY size DESC, name"
        );

        return array_map(fn (array $r): array => ['name' => (string) $r['name'], 'rows' => (int) $r['row_count'], 'size' => (int) $r['size'], 'free' => (int) $r['free']], $rows);
    }

    /**
     * OPTIMIZE TABLE, one table at a time, for each table with free space (InnoDB rebuilds the table and gives
     * the space of deleted rows back). A table that fails does not stop the others. Returns how many were done.
     */
    public static function optimize(\Pimple\Container $di): int
    {
        // A big table can outlast the browser or a proxy (Cloudflare stops at 100 s): finish anyway.
        ignore_user_abort(true);
        @set_time_limit(0);
        $db = $di['dbal'];
        $done = 0;
        $failed = [];
        foreach (self::tables($di) as $table) {
            if ($table['free'] <= 0) {
                continue;
            }

            try {
                $result = $db->fetchAllAssociative('OPTIMIZE TABLE ' . $db->quoteSingleIdentifier($table['name']));
            } catch (\Throwable) {
                $result = [['Msg_type' => 'error']];
            }
            // InnoDB answers "doing recreate + analyze instead" as a note; only an error row is a failure.
            if (array_filter($result, fn (array $row): bool => strtolower((string) ($row['Msg_type'] ?? '')) === 'error')) {
                $failed[] = $table['name'];
            } else {
                ++$done;
            }
        }
        $di['logger']->info('ClientFence: database tables optimized by staff (%d done, %d failed)', $done, count($failed));
        if ($failed) {
            throw new InformationException('These tables could not be optimized: :tables. The other tables were optimized.', [':tables' => implode(', ', $failed)]);
        }

        return $done;
    }

    /**
     * Runs the cron clean-ups now: the activity, sign-in and email logs and the API request log
     * (Activity\Service::onBeforeAdminCronRun()), old ticket files (TicketFiles::prune(), up to 500 per run as
     * in cron) and the email import log (MailImport::prune()). Returns how many ticket files went.
     */
    public static function cleanUp(\Pimple\Container $di): int
    {
        ignore_user_abort(true);
        @set_time_limit(0);
        $event = new \ClientFence\Event(null, 'onBeforeAdminCronRun');
        $event->setDi($di);
        ActivityService::onBeforeAdminCronRun($event);
        $files = TicketFiles::prune($di);
        MailImport::prune($di);
        $di['logger']->info('ClientFence: database clean-up run by staff (%d ticket files removed)', $files);

        return $files;
    }
}
