<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Whmcs;

use ClientFence\InformationException;
use ClientFence\Mod\Clientfence\Service;

/**
 * ClientFence: the WHMCS import (System > Settings > WHMCS import, and the console command
 * clientfence:whmcs-import). It moves a whole WHMCS 8.x/9.x install (7.x on a best effort basis)
 * into a fresh ClientFence: clients and logins, the catalogue, services, invoices and payments,
 * tickets, the knowledge base and history, keeping ids, invoice numbers and licence keys.
 *
 * The steps, each re-runnable: connect (the WHMCS database, read only: Source.php), plan (what
 * WHMCS holds and what happens to it: Inventory.php), apply and verify (Run.php: clients, logins
 * and contacts so far, Accounts.php), then sync (a later build). Nothing is sent, provisioned or
 * charged during an import.
 *
 * The settings (mod_clientfence_state "whmcs_import") hold the database details; the password and
 * WHMCS's encryption key (cc_encryption_hash, to read what WHMCS encrypts) are encrypted with this
 * install's key and never shown again. Super Administrators only: the import reads every client's
 * data.
 */
final class Import
{
    public const SETTINGS = 'whmcs_import';
    public const PLAN = 'whmcs_import_plan';

    public const DEFAULTS = [
        'host' => 'localhost',
        'port' => 3306,
        'database' => '',
        'username' => '',
        'password' => '',
        'encryption_key' => '',
        'attachments_dir' => '',
    ];

    /** ClientFence tables that must be empty: the import keeps WHMCS's ids and numbers. */
    private const TARGET_TABLES = ['client' => 'clients', 'client_order' => 'orders', 'invoice' => 'invoices', 'support_ticket' => 'tickets'];

    /** @return array{host: string, port: int, database: string, username: string, password: string, encryption_key: string, attachments_dir: string} */
    public static function settings(\Pimple\Container $di): array
    {
        $saved = self::state($di, self::SETTINGS) ?? [];
        $out = self::DEFAULTS;
        foreach ($out as $key => $default) {
            if (array_key_exists($key, $saved) && is_scalar($saved[$key])) {
                $out[$key] = is_int($default) ? (int) $saved[$key] : (string) $saved[$key];
            }
        }

        return $out;
    }

    /**
     * The page: the settings without secrets, the last plan and whether this install can take an
     * import.
     *
     * @return array<string, mixed>
     */
    public static function forStaff(\Pimple\Container $di): array
    {
        $settings = self::settings($di);
        $settings['has_password'] = $settings['password'] !== '';
        $settings['has_encryption_key'] = $settings['encryption_key'] !== '';
        unset($settings['password'], $settings['encryption_key']);

        $run = Run::state($di);
        // The add-on module archive file (Archive.php), once the run wrote to it.
        $archive = null;
        $name = is_string($run['archive'] ?? null) ? basename($run['archive']) : '';
        $path = \ClientFence\Mod\Clientfence\Backups::localDir() . '/' . $name;
        if ($name !== '' && is_file($path)) {
            $archive = ['name' => $name, 'dir' => dirname($path), 'bytes' => (int) filesize($path)];
        }

        return ['settings' => $settings, 'plan' => self::state($di, self::PLAN), 'target' => self::target($di), 'run' => $run, 'archive' => $archive, 'warnings' => Run::warnings($di['em']->getConnection(), 500)];
    }

    /**
     * Saves the database details and tests them: answers WHMCS's version. An empty password or
     * encryption key keeps the saved one.
     *
     * @param array<string, mixed> $data
     *
     * @return array{version: string}
     */
    public static function save(\Pimple\Container $di, #[\SensitiveParameter] array $data): array
    {
        $old = self::settings($di);
        $port = (int) ($data['port'] ?? 3306);
        if ($port < 1 || $port > 65535) {
            throw new InformationException('Enter a port from 1 to 65535.');
        }
        $new = [
            'host' => self::text($data['host'] ?? '', 255, 'Host'),
            'port' => $port,
            'database' => self::text($data['database'] ?? '', 64, 'Database name'),
            'username' => self::text($data['username'] ?? '', 80, 'Username'),
            'password' => (string) ($data['password'] ?? '') !== '' ? (string) $di['crypt']->encrypt((string) $data['password']) : $old['password'],
            'encryption_key' => (string) ($data['encryption_key'] ?? '') !== '' ? (string) $di['crypt']->encrypt(trim((string) $data['encryption_key'])) : $old['encryption_key'],
            'attachments_dir' => self::folder((string) ($data['attachments_dir'] ?? '')),
        ];
        if ($new['host'] === '' || $new['database'] === '' || $new['username'] === '' || $new['password'] === '') {
            throw new InformationException('Enter the WHMCS database host, name, username and password.');
        }

        $source = Source::open($di, $new);
        try {
            if (!$source->isWhmcs()) {
                throw new InformationException('This database has no WHMCS tables. Check the database name.');
            }
            $version = $source->version();
        } finally {
            $source->close();
        }
        self::saveState($di, self::SETTINGS, $new);

        return ['version' => $version];
    }

    /**
     * Saves WHMCS's attachments folder alone (the console's --attachments; the tab saves it with the
     * database details). '' forgets it. Returns the saved path.
     */
    public static function saveAttachments(\Pimple\Container $di, string $path): string
    {
        $settings = self::state($di, self::SETTINGS) ?? [];
        $settings['attachments_dir'] = self::folder($path);
        self::saveState($di, self::SETTINGS, $settings);

        return $settings['attachments_dir'];
    }

    /**
     * Reads WHMCS (read only) and stores what an import would bring: Inventory::plan() plus when it
     * was made and whether this install can take it.
     *
     * @return array<string, mixed>
     */
    public static function plan(\Pimple\Container $di): array
    {
        $source = Source::open($di, self::settings($di));
        try {
            if (!$source->isWhmcs()) {
                throw new InformationException('This database has no WHMCS tables. Check the database name.');
            }
            $plan = Inventory::plan($source);
            // What the import needs from this server (Preflight.php): apply refuses to start without it.
            $plan['preflight'] = Preflight::checks($di, $source);
        } finally {
            $source->close();
        }
        $plan['made_at'] = date('Y-m-d H:i:s');
        self::saveState($di, self::PLAN, $plan);

        return $plan;
    }

    /**
     * Whether this install can take an import: it must be fresh (no clients, orders, invoices or
     * tickets), because WHMCS's ids and invoice numbers are kept.
     *
     * @return array{fresh: bool, counts: array<string, int>}
     */
    public static function target(\Pimple\Container $di): array
    {
        $db = $di['em']->getConnection();
        $counts = [];
        foreach (self::TARGET_TABLES as $table => $label) {
            try {
                $counts[$label] = (int) $db->fetchOne('SELECT COUNT(*) FROM ' . $table);
            } catch (\Throwable) {
                $counts[$label] = 0;
            }
        }

        return ['fresh' => array_sum($counts) === 0, 'counts' => $counts];
    }

    private static function text(mixed $value, int $max, string $what): string
    {
        $value = trim(is_scalar($value) ? (string) $value : '');
        if (mb_strlen($value) > $max || preg_match('/[\x00-\x1f]/', $value) === 1) {
            throw new InformationException(':what is not valid.', [':what' => $what]);
        }

        return $value;
    }

    /** WHMCS's attachments folder on this server (optional): absolute and readable. */
    private static function folder(string $path): string
    {
        $path = rtrim(trim($path), '/');
        if ($path === '') {
            return '';
        }
        if (!str_starts_with($path, '/') || str_contains($path, "\0") || !is_dir($path) || !is_readable($path)) {
            throw new InformationException('The attachments folder must be an absolute path to a folder this site can read.');
        }

        return $path;
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
        $di['em']->getConnection()->executeStatement(
            'INSERT INTO mod_clientfence_state (name, value, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
            [$name, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s')]
        );
    }
}
