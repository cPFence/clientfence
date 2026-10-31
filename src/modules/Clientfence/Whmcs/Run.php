<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Whmcs;

use ClientFence\Config;
use ClientFence\InformationException;
use Doctrine\DBAL\Connection;

/**
 * ClientFence: runs the WHMCS import (Import.php) in batches and checks the result.
 *
 * apply() works through the steps (Accounts::STEPS, Catalogue::STEPS, Services::STEPS, Money::STEPS,
 * Support::STEPS, Licensing::STEPS, then the add-on module archive, Archive::STEPS) for as long as
 * it is given: the admin tab calls it again and
 * again (a few seconds each, showing progress), the console in one go. Each batch is one
 * transaction on this install with its id map rows, its warnings (mod_clientfence_import_warning,
 * one row each) and the run state, so a stop (an error, a closed tab, a timeout) loses nothing: the
 * next call goes on from the last batch. The state (step, position, counts, the number of warnings,
 * the error that stopped it) is kept in mod_clientfence_state "whmcs_import_run".
 *
 * The first call needs a fresh install (Import::target()); later calls go on with the run that
 * started. One run at a time (a database lock).
 *
 * verify() compares what WHMCS holds with what was imported, step by step.
 */
final class Run
{
    public const STATE = 'whmcs_import_run';
    public const BATCH = 200;
    public const WARNINGS = 'mod_clientfence_import_warning';

    /** The id map entity of each step's rows. */
    public const ENTITIES = [
        'currencies' => 'currency', 'groups' => 'group', 'fields' => 'customfield',
        'clients' => 'client', 'users' => 'link', 'contacts' => 'contact',
        'categories' => 'category', 'servers' => 'server', 'products' => 'product', 'addons' => 'addon', 'options' => 'optiongroup',
        'upgrades' => 'upgrade', 'promotions' => 'promo', 'tlds' => 'tld', 'taxes' => 'tax', 'bundles' => 'bundle',
        'services' => 'service', 'serviceaddons' => 'serviceaddon', 'domains' => 'domain',
        'invoices' => 'invoice', 'transactions' => 'transaction', 'credits' => 'credit', 'balances' => 'balance', 'notes' => 'note',
        'staff' => 'staff', 'departments' => 'helpdesk', 'predefined' => 'predefined', 'tickets' => 'ticket', 'replies' => 'reply',
        'ticketnotes' => 'ticketnote', 'feedback' => 'feedback', 'kbcategories' => 'kbcategory', 'kbarticles' => 'kbarticle',
        'announcements' => 'announcement', 'networkissues' => 'networkissue', 'emails' => 'email',
        'licencebans' => 'licenceban',
    ];

    /** Steps of many small rows (no id map rows): batches this many times larger, counted in the run state. */
    public const BULK = ['licencelog' => 10, 'archive' => 10];

    /**
     * Every step in order: name => [label, WHMCS table].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function steps(): array
    {
        return Accounts::STEPS + Catalogue::STEPS + Services::STEPS + Money::STEPS + Support::STEPS + Licensing::STEPS + Archive::STEPS;
    }

    /**
     * Imports for up to $seconds (0: until done). Returns the run state.
     *
     * @return array<string, mixed>
     */
    public static function apply(\Pimple\Container $di, float $seconds = 0.0, int $batch = self::BATCH): array
    {
        $db = $di['em']->getConnection();
        \ClientFence\Mod\Clientfence\Service::ensureTables($di, true);
        if (!self::lock($db)) {
            throw new InformationException('The import is already running (another tab or the console). Wait for it to finish.');
        }
        $source = null;
        try {
            $state = self::state($di);
            if ($state === null) {
                if (!Import::target($di)['fresh']) {
                    throw new InformationException('This install already has clients, orders, invoices or tickets. The WHMCS import needs a fresh install.');
                }
            } elseif (isset($state['finished_at'])) {
                return $state;
            }
            $settings = Import::settings($di);
            $source = Source::open($di, $settings);
            if (!$source->isWhmcs()) {
                throw new InformationException('This database has no WHMCS tables. Check the database name.');
            }
            // Memory, folders, disk space and the like, before anything is written (Preflight.php).
            // Free space only before a run starts: a started run has used some for its files.
            Preflight::assert($di, $source, $state === null);
            $state ??= self::start($source);
            $state = self::work($di, $db, $source, $state, $seconds, $batch);
        } finally {
            $source?->close();
            self::unlock($db);
        }

        return $state;
    }

    /**
     * Runs batches on a source and target (apply(), and tests with their own databases).
     *
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    public static function work(\Pimple\Container $di, Connection $db, Source $source, array $state, float $seconds, int $batch): array
    {
        $started = microtime(true);
        $cipher = Cipher::fromSettings($di, Import::settings($di));
        $encrypt = static fn (string $text): string => (string) $di['crypt']->encrypt($text);
        $config = static fn (string $ext, array $changes) => self::mergeConfig($di, $ext, $changes);
        $seal = static fn (string $value): string => (string) \ClientFence\Mod\Servicehosting\ServerSecrets::seal($di, $value);
        $secretFields = static fn (string $manager): array => $di['mod_service']('servicehosting')->getServerManagerSecretFields($manager);
        $registrars = self::registrars();
        $attachments = Import::settings($di)['attachments_dir'];
        $keepDays = static function () use ($di): int {
            try {
                $setting = trim((string) ($di['mod_config']('support')['ticket_files_days'] ?? ''));
            } catch (\Throwable) {
                $setting = '';
            }

            return max(0, $setting === '' ? \ClientFence\Mod\Support\TicketFiles::KEEP_DAYS : (int) $setting);
        };
        $state['error'] = null;
        // A run started by an older build names its archive file here.
        $state['archive'] ??= basename(Archive::file('', (string) ($state['started_at'] ?? date('Y-m-d H:i:s'))));
        $archive = \ClientFence\Mod\Clientfence\Backups::localDir() . '/' . $state['archive'];
        foreach (self::steps() as $step => [$label]) {
            // A run started by an older build: its newer steps start here.
            $state['steps'][$step] ??= ['label' => $label, 'total' => null, 'done' => 0, 'after' => 0, 'finished' => false];
            while (empty($state['steps'][$step]['finished'])) {
                $map = new Map($db);
                $now = date('Y-m-d H:i:s');
                $catalogue = new Catalogue($source, $db, $map, $cipher, $seal, $secretFields, $registrars, $now);
                $runner = match (true) {
                    isset(Accounts::STEPS[$step]) => new Accounts($source, $db, $map, $cipher, $encrypt, $config, $now),
                    isset(Services::STEPS[$step]) => new Services($source, $db, $map, $catalogue, $now),
                    isset(Money::STEPS[$step]) => new Money($source, $db, $map, $now),
                    isset(Support::STEPS[$step]) => new Support($source, $db, $map, $cipher, $encrypt, $config, $attachments, \ClientFence\Mod\Support\TicketFiles::dir(), $keepDays(), $now),
                    isset(Licensing::STEPS[$step]) => new Licensing($source, $db, $map, $now),
                    isset(Archive::STEPS[$step]) => new Archive($source, $map, $archive),
                    default => $catalogue,
                };
                $state['steps'][$step]['total'] ??= $runner->total($step);
                $before = $state;
                $db->beginTransaction();
                try {
                    [$after, $done] = $runner->batch($step, (int) ($state['steps'][$step]['after'] ?? 0), $batch * (self::BULK[$step] ?? 1));
                    $warnings = $runner->warnings();
                    self::addWarnings($db, $step, $warnings);
                    $state['steps'][$step]['done'] = (int) ($state['steps'][$step]['done'] ?? 0) + $done;
                    $state['steps'][$step]['after'] = $after;
                    $state['steps'][$step]['finished'] = $after === null;
                    $state['warnings'] = (int) ($state['warnings'] ?? 0) + count($warnings);
                    $state['step'] = $step;
                    $state['updated_at'] = date('Y-m-d H:i:s');
                    self::save($di, $state);
                    $db->commit();
                    // The batch wrote settings, staff and configurations past the services.
                    \ClientFence\RequestCache::forget($di, '');
                } catch (\Throwable $e) {
                    $db->rollBack();
                    if ($runner instanceof Support || $runner instanceof Archive) {
                        // The batch's rows are gone: so are the ticket files it copied, or what it
                        // added to the archive file.
                        $runner->discard();
                    }
                    $state = $before;
                    $state['error'] = sprintf('%s: %s', $label, mb_substr($e->getMessage(), 0, 500));
                    self::save($di, $state);

                    throw new InformationException('The import stopped at ":step": :error', [':step' => $label, ':error' => mb_substr($e->getMessage(), 0, 500)]);
                }
                if ($seconds > 0 && microtime(true) - $started >= $seconds) {
                    return $state;
                }
            }
        }
        $state['finished_at'] = date('Y-m-d H:i:s');
        $state['step'] = null;
        self::save($di, $state);
        // New groups, currencies and settings: drop what was cached of them.
        try {
            $di['cache']->clear();
        } catch (\Throwable) {
            // A stale cache only shows old settings until it expires.
        }

        return $state;
    }

    /**
     * A new run: each step with the number of WHMCS rows it reads.
     *
     * @return array<string, mixed>
     */
    public static function start(Source $source): array
    {
        $steps = [];
        foreach (self::steps() as $step => [$label]) {
            $steps[$step] = ['label' => $label, 'total' => self::stepTotal($source, $step), 'done' => 0, 'after' => 0, 'finished' => false];
        }

        $now = date('Y-m-d H:i:s');

        return [
            'started_at' => $now,
            'updated_at' => $now,
            'finished_at' => null,
            'archive' => basename(Archive::file('', $now)),
            'version' => $source->version(),
            'step' => array_key_first($steps),
            'steps' => $steps,
            'warnings' => 0,
            'error' => null,
        ];
    }

    /**
     * What WHMCS holds against what was imported, per step: [{step, label, whmcs, imported}], plus
     * two-factor secrets and Google links carried over.
     *
     * @return array<string, mixed>
     */
    public static function verify(\Pimple\Container $di): array
    {
        $state = self::state($di);
        if ($state === null) {
            throw new InformationException('Nothing was imported yet.');
        }
        $source = Source::open($di, Import::settings($di));
        try {
            $out = self::compare($source, $di['em']->getConnection(), $state);
        } finally {
            $source->close();
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    public static function compare(Source $source, Connection $db, array $state): array
    {
        $map = new Map($db);
        $rows = [];
        foreach (self::steps() as $step => [$label, $table]) {
            $whmcs = match (true) {
                $step === 'users' => $source->hasTable('tblusers') ? $source->count('tblusers_clients', 'owner = 0') : $source->count('tblcontacts', 'subaccount = 1'),
                $step === 'fields' => $source->count('tblcustomfields', "type = 'client'"),
                $step === 'contacts' => $source->hasTable('tblusers') ? $source->count('tblcontacts') : $source->count('tblcontacts', 'subaccount = 0'),
                // Feedback of tickets WHMCS no longer has is not imported (a warning says so).
                $step === 'feedback' => $source->hasTable('tbltickets') ? $source->count('tblticketfeedback', 'ticketid IN (SELECT id FROM tbltickets)') : 0,
                isset(Catalogue::STEPS[$step]), isset(Services::STEPS[$step]), isset(Money::STEPS[$step]), isset(Support::STEPS[$step]), isset(Licensing::STEPS[$step]), isset(Archive::STEPS[$step]) => self::stepTotal($source, $step),
                default => $source->count($table),
            };
            // Bulk steps keep no id map rows: the run counted what it wrote.
            $imported = isset(self::BULK[$step]) ? (int) ($state['steps'][$step]['done'] ?? 0) : $map->count(self::ENTITIES[$step]);
            $rows[] = ['step' => $step, 'label' => $label, 'whmcs' => $whmcs, 'imported' => $imported];
        }
        $clientIds = array_values($map->all('client'));
        $missing = $clientIds === [] ? 0 : count($clientIds) - (int) $db->fetchOne('SELECT COUNT(*) FROM client WHERE id IN (' . implode(',', $clientIds) . ')');
        // Only logins linked to an account are imported (owner decision 2026-10-10): so are only
        // their two-factor secrets and Google links.
        $linked = $source->hasTable('tblusers_clients') ? 'IN (SELECT auth_user_id FROM tblusers_clients)' : null;
        $twofactor = $source->hasTable('tblusers')
            ? ($linked !== null ? $source->count('tblusers', "second_factor <> '' AND id " . $linked) : 0)
            : $source->count('tblclients', "authmodule <> ''");
        // One Google account per login (the first linked).
        $google = $source->hasTable('tblauthn_account_links') && $linked !== null ? (int) $source->value("SELECT COUNT(DISTINCT user_id) FROM tblauthn_account_links WHERE provider = 'google_signin' AND user_id " . $linked) : 0;
        $rows[] = ['step' => 'twofactor', 'label' => 'Two-factor sign-in carried over', 'whmcs' => $twofactor, 'imported' => $map->count('totp')];
        $rows[] = ['step' => 'google', 'label' => 'Google sign-in links', 'whmcs' => $google, 'imported' => $map->count('google')];
        // Money, once its steps ran: each imported client's balance against WHMCS's credit, and
        // the unpaid invoices.
        if (!empty($state['steps']['balances']['finished'])) {
            $matching = 0;
            $clientMap = $map->all('client');
            foreach ($source->rows('SELECT id, credit FROM tblclients') as $client) {
                $id = $clientMap[(string) $client['id']] ?? null;
                if ($id !== null && abs(round((float) $db->fetchOne('SELECT COALESCE(SUM(amount), 0) FROM client_balance WHERE client_id = ?', [$id]), 2) - round((float) $client['credit'], 2)) < 0.005) {
                    ++$matching;
                }
            }
            $rows[] = ['step' => 'credit', 'label' => 'Clients whose credit matches', 'whmcs' => count($clientMap), 'imported' => $matching];
        }
        if (!empty($state['steps']['invoices']['finished']) && $source->hasTable('tblinvoices')) {
            $invoiceIds = array_values($map->all('invoice'));
            $rows[] = [
                'step' => 'unpaid', 'label' => 'Unpaid invoices',
                'whmcs' => $source->count('tblinvoices', "status IN ('Unpaid', 'Collections', 'Payment Pending', 'Draft')"),
                'imported' => $invoiceIds === [] ? 0 : (int) $db->fetchOne("SELECT COUNT(*) FROM invoice WHERE status = 'unpaid' AND id IN (" . implode(',', $invoiceIds) . ')'),
            ];
        }

        // Support, once the replies ran: the attachments WHMCS still has (not removed), against the
        // files copied.
        if (!empty($state['steps']['replies']['finished'])) {
            $rows[] = [
                'step' => 'files', 'label' => 'Ticket attachments copied',
                'whmcs' => self::support($source)->attachmentTotal(),
                'imported' => (int) $db->fetchOne("SELECT COUNT(*) FROM support_ticket_file WHERE uploader_kind IN ('whmcs', 'whmcsstaff') AND removed_at IS NULL"),
            ];
        }

        return [
            'checked_at' => date('Y-m-d H:i:s'),
            'rows' => $rows,
            'missing_clients' => $missing,
            'warnings' => self::warningCount($db),
        ];
    }

    /** A step's WHMCS row count (nothing is written). */
    private static function stepTotal(Source $source, string $step): int
    {
        $map = new Map($source->connection());
        if (isset(Accounts::STEPS[$step])) {
            return (new Accounts($source, $source->connection(), $map, null, static fn (string $t): string => $t, static fn (string $e, array $c) => null, ''))->total($step);
        }

        $catalogue = new Catalogue($source, $source->connection(), $map, null, static fn (string $t): string => $t, static fn (string $m): array => [], [], '');

        return match (true) {
            isset(Services::STEPS[$step]) => (new Services($source, $source->connection(), $map, $catalogue, ''))->total($step),
            isset(Money::STEPS[$step]) => (new Money($source, $source->connection(), $map, ''))->total($step),
            isset(Support::STEPS[$step]) => self::support($source)->total($step),
            isset(Licensing::STEPS[$step]) => (new Licensing($source, $source->connection(), $map, ''))->total($step),
            isset(Archive::STEPS[$step]) => (new Archive($source, $map, ''))->total($step),
            default => $catalogue->total($step),
        };
    }

    /** A support runner that only counts (nothing is written). */
    private static function support(Source $source): Support
    {
        return new Support($source, $source->connection(), new Map($source->connection()), null, static fn (string $t): string => $t, static fn (string $e, array $c) => null, '', '', 0, '');
    }

    /**
     * This install's domain registrar adapters (library/Registrar/Adapter).
     *
     * @return list<string>
     */
    private static function registrars(): array
    {
        $files = glob(PATH_LIBRARY . '/Registrar/Adapter/*.php') ?: [];

        return array_map(static fn (string $f): string => basename($f, '.php'), $files);
    }

    /**
     * The import's warnings, oldest first ($limit 0: all).
     *
     * @return list<string>
     */
    public static function warnings(Connection $db, int $limit = 0): array
    {
        try {
            return array_map('strval', $db->fetchFirstColumn('SELECT message FROM ' . self::WARNINGS . ' WHERE source = ? ORDER BY id' . ($limit > 0 ? ' LIMIT ' . $limit : ''), [Map::SOURCE]));
        } catch (\Throwable) {
            return [];
        }
    }

    public static function warningCount(Connection $db): int
    {
        try {
            return (int) $db->fetchOne('SELECT COUNT(*) FROM ' . self::WARNINGS . ' WHERE source = ?', [Map::SOURCE]);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @param list<string> $warnings */
    public static function addWarnings(Connection $db, string $step, array $warnings): void
    {
        $now = date('Y-m-d H:i:s');
        foreach ($warnings as $message) {
            $db->insert(self::WARNINGS, ['source' => Map::SOURCE, 'step' => $step, 'message' => mb_substr($message, 0, 2000), 'created_at' => $now]);
        }
    }

    /** @return array<string, mixed>|null */
    public static function state(\Pimple\Container $di): ?array
    {
        try {
            $row = $di['em']->getConnection()->fetchOne('SELECT value FROM mod_clientfence_state WHERE name = ?', [self::STATE]);
        } catch (\Throwable) {
            return null;
        }
        $value = is_string($row) ? json_decode($row, true) : null;

        return is_array($value) ? $value : null;
    }

    /** @param array<string, mixed> $state */
    private static function save(\Pimple\Container $di, array $state): void
    {
        $db = $di['em']->getConnection();
        $value = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $now = date('Y-m-d H:i:s');
        if ($db->update('mod_clientfence_state', ['value' => $value, 'updated_at' => $now], ['name' => self::STATE]) === 0
            && $db->fetchOne('SELECT 1 FROM mod_clientfence_state WHERE name = ?', [self::STATE]) === false) {
            $db->insert('mod_clientfence_state', ['name' => self::STATE, 'value' => $value, 'updated_at' => $now]);
        }
    }

    /**
     * Merges $changes into a module's settings (the client custom fields), as the module's
     * settings page saves them but without a staff member (the console has none).
     *
     * @param array<string, mixed> $changes
     */
    private static function mergeConfig(\Pimple\Container $di, string $ext, array $changes): void
    {
        $service = $di['mod_service']('extension');
        $config = $service->getConfig($ext);
        foreach ($changes as $key => $value) {
            $config[$key] = is_array($value) && is_array($config[$key] ?? null) ? array_replace($config[$key], $value) : $value;
        }
        $meta = $service->getExtensionMetaRepository()->findOneByExtensionAndScope($ext, 'config');
        if ($meta === null) {
            $meta = new \ClientFence\Mod\Extension\Entity\ExtensionMeta();
            $meta->setExtension($ext);
            $meta->setMetaKey('config');
            $di['em']->persist($meta);
        }
        $meta->setMetaValue($di['crypt']->encrypt((string) json_encode($config), Config::getProperty('info.salt')));
        $di['em']->flush();
        $di['cache']->delete('config_' . $ext);
    }

    private static function lock(Connection $db): bool
    {
        if (!$db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
            return true;
        }

        return (int) $db->fetchOne("SELECT GET_LOCK('clientfence_whmcs_import', 0)") === 1;
    }

    private static function unlock(Connection $db): void
    {
        if ($db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
            $db->fetchOne("SELECT RELEASE_LOCK('clientfence_whmcs_import')");
        }
    }
}
