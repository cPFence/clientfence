<?php

declare(strict_types=1);

/**
 * ClientFence: the WHMCS import's licence steps (Whmcs\Licensing: WHMCS Licensing's bans and check
 * log) and the add-on module archive (Whmcs\Archive), from a small invented WHMCS database into a
 * ClientFence database, both SQLite: WHMCS's column names only, no WHMCS code or data.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Clientfence\Whmcs\Archive;
use ClientFence\Mod\Clientfence\Whmcs\Inventory;
use ClientFence\Mod\Clientfence\Whmcs\Licensing;
use ClientFence\Mod\Clientfence\Whmcs\Map;
use ClientFence\Mod\Clientfence\Whmcs\Source;
use ClientFence\Mod\Servicelicense\Bans;
use ClientFence\Mod\Servicelicense\CheckLog;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->archiveBackupDir = getenv('CLIENTFENCE_BACKUP_DIR');
});

afterEach(function (): void {
    putenv($this->archiveBackupDir === false ? 'CLIENTFENCE_BACKUP_DIR' : 'CLIENTFENCE_BACKUP_DIR=' . $this->archiveBackupDir);
});

function whmcsLicensingSource(string $logPrune = '30'): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
        'CREATE TABLE tblconfiguration (setting TEXT, value TEXT)',
        'CREATE TABLE tbladdonmodules (module TEXT, setting TEXT, value TEXT)',
        "CREATE TABLE mod_licensing (id INTEGER PRIMARY KEY, serviceid INTEGER, addon_id INTEGER DEFAULT 0, licensekey TEXT, validdomain TEXT DEFAULT '', validip TEXT DEFAULT '', validdirectory TEXT DEFAULT '', reissues INTEGER DEFAULT 0, status TEXT, lastaccess TEXT)",
        'CREATE TABLE mod_licensinglog (id INTEGER PRIMARY KEY, licenseid INTEGER, domain TEXT, ip TEXT, path TEXT, message TEXT, datetime TEXT)',
        'CREATE TABLE mod_licensingbans (id INTEGER PRIMARY KEY, type TEXT, value TEXT, notes TEXT)',
        // Modules without a mapper: archived.
        'CREATE TABLE mod_lcwsoftpaypalbillingagreements (userid INTEGER, agreementID TEXT)',
        'CREATE TABLE paymobonline_orders (id INTEGER PRIMARY KEY, invoice_id INTEGER, payload BLOB)',
        'CREATE TABLE ProductTrialManager_Logger (id INTEGER PRIMARY KEY, message TEXT)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    $db->executeStatement("INSERT INTO tblconfiguration VALUES ('Version', '9.0.4-release.1')");
    $db->executeStatement("INSERT INTO tbladdonmodules VALUES ('licensing', 'version', '3.1'), ('licensing', 'logprune', " . $db->quote($logPrune) . ')');
    // Licence 1 and 3 belong to service 7 (imported), licence 2 to service 8 (not imported).
    $db->executeStatement("INSERT INTO mod_licensing (id, serviceid, licensekey, status, lastaccess) VALUES (1, 7, 'KEY-A', 'Active', '2026-10-01 10:00:00'), (2, 8, 'KEY-B', 'Active', '2026-10-01 10:00:00'), (3, 7, 'KEY-OLD', 'Reissued', '2026-01-01 10:00:00')");
    $db->executeStatement("INSERT INTO mod_licensinglog VALUES
        (1, 1, 'example.com', '203.0.113.5', '/home/a', 'License Reissued', '2026-10-01 10:00:00'),
        (2, 0, '', '198.51.100.9', '', 'Invalid Key - KEY-GONE', '2026-10-01 10:05:00'),
        (3, 1, '', '203.0.113.5, 203.0.113.6', '', 'IP Address Invalid', '2026-10-02 10:00:00'),
        (4, 2, '', '203.0.113.7', '', 'License Suspended', '2026-10-02 11:00:00'),
        (5, 3, '', '203.0.113.5', '', 'License Expired', '0000-00-00 00:00:00'),
        (6, 0, '', '198.51.100.9', '', 'Invalid Key - KEY-GONE', '2026-10-03 10:05:00'),
        (7, 1, 'example.com', '203.0.113.5', '/home/a', 'License Reissued', '2026-10-04 10:00:00')");
    $db->executeStatement("INSERT INTO mod_licensingbans VALUES (1, '', 'www.Bad.test', 'Chargeback'), (2, '', '198.51.100.7', ''), (3, '', 'not a domain!', 'typo'), (4, '', 'bad.test', 'again')");
    $db->executeStatement("INSERT INTO mod_lcwsoftpaypalbillingagreements VALUES (1, 'B-1AA'), (1, 'B-2BB'), (2, 'B-3CC')");
    $db->insert('paymobonline_orders', ['id' => 1, 'invoice_id' => 10, 'payload' => "\xff\x00\xfe binary"]);
    $db->insert('paymobonline_orders', ['id' => 2, 'invoice_id' => 11, 'payload' => 'plain']);

    return $db;
}

function whmcsLicensingTarget(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
        'CREATE TABLE ' . Map::TABLE . ' (source TEXT, entity TEXT, source_id TEXT, target_id INTEGER, created_at TEXT, PRIMARY KEY (source, entity, source_id))',
        'CREATE TABLE client_order (id INTEGER PRIMARY KEY, service_id INTEGER, service_type TEXT)',
        'CREATE TABLE ' . CheckLog::TABLE . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, service_license_id INTEGER NOT NULL, host TEXT NOT NULL, ip TEXT NOT NULL, path TEXT NOT NULL, version TEXT NOT NULL, result TEXT NOT NULL, created_at TEXT NOT NULL)',
        'CREATE TABLE ' . Bans::TABLE . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT NOT NULL UNIQUE, notes TEXT NOT NULL, created_at TEXT NOT NULL)',
        'CREATE TABLE setting (id INTEGER PRIMARY KEY AUTOINCREMENT, param TEXT UNIQUE, value TEXT, public INTEGER, category TEXT, hash TEXT, created_at TEXT, updated_at TEXT)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    // Service 7 was imported as order 70, whose licence is 700.
    $db->insert('client_order', ['id' => 70, 'service_id' => 700, 'service_type' => 'license']);
    (new Map($db))->put('service', 7, 70);

    return $db;
}

/**
 * Runs the licence steps to the end in batches of $batch; returns the warnings.
 *
 * @return list<string>
 */
function whmcsLicensingAll(Connection $source, Connection $target, int $batch = 2): array
{
    $warnings = [];
    foreach (array_keys(Licensing::STEPS) as $step) {
        $after = 0;
        do {
            $runner = new Licensing(new Source($source), $target, new Map($target), '2026-10-19 12:00:00');
            [$after] = $runner->batch($step, (int) $after, $batch);
            array_push($warnings, ...$runner->warnings());
        } while ($after !== null);
    }

    return $warnings;
}

function whmcsArchiveFile(): string
{
    $dir = sys_get_temp_dir() . '/cf-whmcs-archive-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    putenv('CLIENTFENCE_BACKUP_DIR=' . $dir);
    register_shutdown_function(static function () use ($dir): void {
        exec('rm -rf ' . escapeshellarg($dir));
    });

    return Archive::file($dir, '2026-10-19 12:00:00');
}

/**
 * The archive file's lines.
 *
 * @return list<array{table: string, row: array<string, mixed>}>
 */
function whmcsArchiveLines(string $path): array
{
    return array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), array_values(array_filter(gzfile($path) ?: [], static fn (string $l): bool => trim($l) !== '')));
}

test('bans come over as licence bans; what is not a domain or IP address is named', function (): void {
    $target = whmcsLicensingTarget();
    $warnings = whmcsLicensingAll(whmcsLicensingSource(), $target);

    expect($target->fetchAllAssociative('SELECT value, notes FROM ' . Bans::TABLE . ' ORDER BY id'))->toBe([
        ['value' => 'bad.test', 'notes' => 'Chargeback'],
        ['value' => '198.51.100.7', 'notes' => ''],
    ])
        ->and((new Map($target))->count('licenceban'))->toBe(3)
        ->and(implode("\n", $warnings))->toContain('Licence ban "not a domain!" is not a domain or an IP address');
});

test('the check log comes over to the licences of imported services, with WHMCS\'s log prune days', function (): void {
    $target = whmcsLicensingTarget();
    $warnings = whmcsLicensingAll(whmcsLicensingSource(), $target);

    $lines = $target->fetchAllAssociative('SELECT service_license_id, host, ip, path, version, result, created_at FROM ' . CheckLog::TABLE . ' ORDER BY id');
    expect($lines)->toHaveCount(4)
        ->and(array_unique(array_column($lines, 'service_license_id')))->toBe([700])
        ->and($lines[0])->toMatchArray(['host' => 'example.com', 'ip' => '203.0.113.5', 'path' => '/home/a', 'version' => '', 'result' => 'License Reissued', 'created_at' => '2026-10-01 10:00:00'])
        ->and($lines[1])->toMatchArray(['ip' => '203.0.113.5, 203.0.113.6', 'result' => 'IP Address Invalid'])
        // A line without a date takes the import's.
        ->and($lines[2])->toMatchArray(['result' => 'License Expired', 'created_at' => '2026-10-19 12:00:00'])
        ->and($target->fetchOne('SELECT value FROM setting WHERE param = ?', [CheckLog::PARAM]))->toBe('30')
        // Licence 2's line (service not imported) and the two unknown-key lines.
        ->and(implode("\n", $warnings))->toContain('3 licence check log lines are for licence keys WHMCS no longer has');

    // WHMCS without "Log Prune" keeps every line: so does ClientFence.
    $target = whmcsLicensingTarget();
    whmcsLicensingAll(whmcsLicensingSource(''), $target);
    expect($target->fetchOne('SELECT value FROM setting WHERE param = ?', [CheckLog::PARAM]))->toBe('0');
});

test('the archive takes every module without a mapper, PayPal billing agreements included', function (): void {
    $source = new Source(whmcsLicensingSource());
    expect(Inventory::archiveTables($source))->toBe(['ProductTrialManager_Logger', 'mod_lcwsoftpaypalbillingagreements', 'paymobonline_orders'])
        ->and(array_column(array_filter(Inventory::plan($source)['modules'], static fn (array $m): bool => $m['handling'] === 'import'), 'module'))->toBe(['licensing']);

    $target = whmcsLicensingTarget();
    $path = whmcsArchiveFile();
    $after = 0;
    $written = 0;
    $total = (new Archive($source, new Map($target), $path))->total('archive');
    do {
        [$after, $done] = (new Archive($source, new Map($target), $path))->batch('archive', (int) $after, 2);
        $written += $done;
    } while ($after !== null);

    $lines = whmcsArchiveLines($path);
    expect($total)->toBe(5)
        ->and($written)->toBe(5)
        ->and(substr(sprintf('%o', fileperms($path)), -4))->toBe('0600')
        ->and(array_map(static fn (array $l): string => $l['table'], $lines))->toBe(['mod_lcwsoftpaypalbillingagreements', 'mod_lcwsoftpaypalbillingagreements', 'mod_lcwsoftpaypalbillingagreements', 'paymobonline_orders', 'paymobonline_orders'])
        ->and(array_column(array_column($lines, 'row'), 'agreementID'))->toBe(['B-1AA', 'B-2BB', 'B-3CC'])
        ->and($lines[3]['row']['payload'])->toBe(['base64' => base64_encode("\xff\x00\xfe binary")])
        ->and($lines[4]['row'])->toBe(['id' => 2, 'invoice_id' => 11, 'payload' => 'plain']);
});

test('an existing archive folder inside the website is refused before a batch writes', function (): void {
    $dir = PATH_ROOT . '/data/cache/cf-whmcs-archive-' . bin2hex(random_bytes(4));
    $filesystem = new Filesystem();
    try {
        $filesystem->mkdir($dir, 0700);
        putenv('CLIENTFENCE_BACKUP_DIR=' . $dir);
        $source = whmcsLicensingSource();
        $source->insert('ProductTrialManager_Logger', ['id' => 1, 'message' => 'Archived settings']);
        $path = Archive::file($dir, '2026-10-19 12:00:00');
        $archive = new Archive(new Source($source), new Map(whmcsLicensingTarget()), $path);

        expect(fn () => $archive->batch('archive', 0, 2))->toThrow(InformationException::class, 'inside the website');
        expect($filesystem->exists($path))->toBeFalse();
    } finally {
        $filesystem->remove($dir);
    }
});

test('a batch that rolls back, or never committed, leaves nothing in the archive file', function (): void {
    $source = new Source(whmcsLicensingSource());
    $target = whmcsLicensingTarget();
    $path = whmcsArchiveFile();

    // The first table is empty; the second batch writes two agreements.
    [$first] = (new Archive($source, new Map($target), $path))->batch('archive', 0, 2);
    [$first] = (new Archive($source, new Map($target), $path))->batch('archive', (int) $first, 2);
    $size = filesize($path);
    expect(whmcsArchiveLines($path))->toHaveCount(2);

    // Rolled back: the batch's map row goes, and discard() cuts the file back.
    $target->beginTransaction();
    $archive = new Archive($source, new Map($target), $path);
    $archive->batch('archive', (int) $first, 2);
    $target->rollBack();
    $archive->discard();
    clearstatcache();
    expect(filesize($path))->toBe($size);

    // Never committed (a crash after the write): the next batch cuts it off before writing.
    $target->beginTransaction();
    (new Archive($source, new Map($target), $path))->batch('archive', (int) $first, 2);
    $target->rollBack();
    clearstatcache();
    expect(filesize($path))->toBeGreaterThan($size);

    $after = $first;
    do {
        [$after] = (new Archive($source, new Map($target), $path))->batch('archive', (int) $after, 2);
    } while ($after !== null);
    expect(array_column(array_column(whmcsArchiveLines($path), 'row'), 'agreementID'))->toBe(['B-1AA', 'B-2BB', 'B-3CC'])
        ->and(whmcsArchiveLines($path))->toHaveCount(5);
});
