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
    /**
     * Staff's product choices (saveProducts()): {source: sourceKey() of the source they were made on, choices:
     * source product id => {name (its check), server, plan}}; a run applies them (Catalogue::productKind()) only
     * on the source its start recorded (choicesFor()).
     */
    public const PRODUCTS = 'whmcs_import_products';

    public const DEFAULTS = [
        'host' => 'localhost',
        'port' => 3306,
        'database' => '',
        'username' => '',
        'password' => '',
        'encryption_key' => '',
        'attachments_dir' => '',
        // PayPal Auto-Pay billing agreements of WHMCS's lcwsoftpaypalbillingagreements gateway (Money.php): 1 brings
        // them; off unless chosen (owner decision 2026-11-08).
        'paypal_agreements' => 0,
        // 1: import from Upmind instead (Upmind/Snapshot.php, read with this API token, encrypted).
        'upmind' => 0,
        'upmind_token' => '',
        // 1: import from a Blesta database (the database fields above; Blesta/Snapshot.php) with Blesta's system
        // key (encrypted), which checks the passwords kept from Blesta (blestaPassword()).
        'blesta' => 0,
        'blesta_key' => '',
        // 1: import from a ClientExec database (the database fields above; Clientexec/Snapshot.php).
        'clientexec' => 0,
        // 1: import from a FOSSBilling database (the database fields above; Fossbilling/Snapshot.php).
        'fossbilling' => 0,
        // 1: import from a Paymenter database (the database fields above; Paymenter/Snapshot.php).
        'paymenter' => 0,
    ];

    /** ClientFence tables that must be empty: the import keeps WHMCS's ids and numbers. */
    private const TARGET_TABLES = ['client' => 'clients', 'client_order' => 'orders', 'invoice' => 'invoices', 'support_ticket' => 'tickets'];

    /** @return array{host: string, port: int, database: string, username: string, password: string, encryption_key: string, attachments_dir: string, paypal_agreements: int, upmind: int, upmind_token: string, blesta: int, blesta_key: string, clientexec: int, fossbilling: int, paymenter: int} */
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
        $settings['has_upmind_token'] = $settings['upmind_token'] !== '';
        $settings['has_blesta_key'] = $settings['blesta_key'] !== '';
        unset($settings['password'], $settings['encryption_key'], $settings['upmind_token'], $settings['blesta_key']);

        $run = Run::state($di);
        // The add-on module archive file (Archive.php), once the run wrote to it.
        $archive = null;
        $name = is_string($run['archive'] ?? null) ? basename($run['archive']) : '';
        $path = \ClientFence\Mod\Clientfence\Backups::localDir() . '/' . $name;
        if ($name !== '' && is_file($path)) {
            $archive = ['name' => $name, 'dir' => dirname($path), 'bytes' => (int) filesize($path)];
        }

        return ['settings' => $settings, 'plan' => self::state($di, self::PLAN), 'target' => self::target($di), 'run' => $run, 'archive' => $archive, 'warnings' => Run::warnings($di['em']->getConnection(), 500), 'upmind' => \ClientFence\Mod\Clientfence\Upmind\Snapshot::state($di), 'blesta' => \ClientFence\Mod\Clientfence\Blesta\Snapshot::state($di), 'clientexec' => \ClientFence\Mod\Clientfence\Clientexec\Snapshot::state($di), 'fossbilling' => \ClientFence\Mod\Clientfence\Fossbilling\Snapshot::state($di), 'paymenter' => \ClientFence\Mod\Clientfence\Paymenter\Snapshot::state($di)];
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
        $new = self::database($di, $data, $old, 'WHMCS') + [
            'encryption_key' => (string) ($data['encryption_key'] ?? '') !== '' ? (string) $di['crypt']->encrypt(trim((string) $data['encryption_key'])) : $old['encryption_key'],
            'paypal_agreements' => (string) ($data['paypal_agreements'] ?? '0') === '1' ? 1 : 0,
            'upmind' => 0,
            'blesta' => 0,
            'clientexec' => 0,
            'fossbilling' => 0,
            'paymenter' => 0,
        ] + $old;

        $source = Source::open($di, $new);
        try {
            if (!$source->isWhmcs()) {
                throw new InformationException('This database has no WHMCS tables. Check the database name.');
            }
            $version = $source->version();
        } finally {
            $source->close();
        }
        self::saveSettings($di, $old, $new);

        return ['version' => $version];
    }

    /**
     * Imports from Upmind instead of a WHMCS database: saves the API token (empty keeps the saved
     * one) once Upmind accepts it. Answers {clients}: how many clients Upmind holds.
     *
     * @param array<string, mixed> $data
     *
     * @return array{clients: int}
     */
    public static function saveUpmind(\Pimple\Container $di, #[\SensitiveParameter] array $data): array
    {
        $old = self::settings($di);
        $token = trim((string) ($data['upmind_token'] ?? ''));
        if ($token === '' && $old['upmind_token'] !== '') {
            $token = (string) $di['crypt']->decrypt($old['upmind_token']);
        }
        if ($token === '' || preg_match('/[\x00-\x20]/', $token) === 1) {
            throw new InformationException('Enter the Upmind API token.');
        }
        [, $clients] = (new \ClientFence\Mod\Clientfence\Upmind\Api($di, $token))->page('clients', 0, 1);
        self::saveSettings($di, $old, ['upmind' => 1, 'blesta' => 0, 'clientexec' => 0, 'fossbilling' => 0, 'paymenter' => 0, 'upmind_token' => (string) $di['crypt']->encrypt($token)] + $old);

        return ['clients' => $clients];
    }

    /**
     * Reads Upmind into the import's snapshot for up to $seconds (Upmind/Snapshot.php); $restart
     * reads it again from the start.
     *
     * @return array<string, mixed>
     */
    public static function fetchUpmind(\Pimple\Container $di, float $seconds, bool $restart = false): array
    {
        $settings = self::settings($di);
        if ($settings['upmind'] !== 1 || $settings['upmind_token'] === '') {
            throw new InformationException('Save the Upmind API token first.');
        }
        if (Run::state($di) !== null) {
            throw new InformationException('The import has started: :name can no longer be read again.', [':name' => 'Upmind']);
        }

        return \ClientFence\Mod\Clientfence\Upmind\Snapshot::fetch($di, (string) $di['crypt']->decrypt($settings['upmind_token']), $seconds, $restart);
    }

    /**
     * The system the import reads: WHMCS, or the one read into its snapshot (Snapshot.php).
     *
     * @param array<string, int|string> $settings Import::settings()
     */
    public static function system(array $settings): string
    {
        return match (1) {
            $settings['upmind'] => 'Upmind',
            $settings['blesta'] => 'Blesta',
            $settings['clientexec'] => 'ClientExec',
            $settings['fossbilling'] => 'FOSSBilling',
            $settings['paymenter'] => 'Paymenter',
            default => 'WHMCS',
        };
    }

    /**
     * Imports from a Blesta database instead (Blesta/Snapshot.php): saves its details, the folder of
     * its ticket attachments and its system key (empty keeps the saved one) once the database
     * answers as Blesta's. Answers {version, clients}.
     *
     * @param array<string, mixed> $data
     *
     * @return array{version: string, clients: int}
     */
    public static function saveBlesta(\Pimple\Container $di, #[\SensitiveParameter] array $data): array
    {
        $old = self::settings($di);
        $key = trim((string) ($data['blesta_key'] ?? ''));
        if (preg_match('/[\x00-\x20]/', $key) === 1) {
            throw new InformationException('Enter Blesta\'s system key as config/blesta.php shows it.');
        }
        $new = self::database($di, $data, $old, 'Blesta') + [
            'upmind' => 0,
            'blesta' => 1,
            'clientexec' => 0,
            'fossbilling' => 0,
            'paymenter' => 0,
            'blesta_key' => $key !== '' ? (string) $di['crypt']->encrypt($key) : $old['blesta_key'],
        ] + $old;

        $source = Source::mysql($di, $new, 'Blesta');
        try {
            if (!\ClientFence\Mod\Clientfence\Blesta\Snapshot::isBlesta($source)) {
                throw new InformationException('This database has no Blesta tables. Check the database name.');
            }
            $version = (string) $source->value("SELECT value FROM settings WHERE `key` = 'database_version'");
            $clients = $source->count('clients');
        } finally {
            $source->close();
        }
        self::saveSettings($di, $old, $new);

        return ['version' => $version, 'clients' => $clients];
    }

    /**
     * Reads Blesta into the import's snapshot for up to $seconds (Blesta/Snapshot.php); $restart
     * reads it again from the start.
     *
     * @return array<string, mixed>
     */
    public static function fetchBlesta(\Pimple\Container $di, float $seconds, bool $restart = false): array
    {
        $settings = self::settings($di);
        if ($settings['blesta'] !== 1) {
            throw new InformationException('Save the Blesta database details first.');
        }
        if (Run::state($di) !== null) {
            throw new InformationException('The import has started: :name can no longer be read again.', [':name' => 'Blesta']);
        }

        return \ClientFence\Mod\Clientfence\Blesta\Snapshot::fetch($di, $settings, $seconds, $restart);
    }

    /**
     * Imports from a ClientExec database instead (Clientexec/Snapshot.php): saves its details once
     * the database answers as ClientExec's. Answers {version, clients}.
     *
     * @param array<string, mixed> $data
     *
     * @return array{version: string, clients: int}
     */
    public static function saveClientexec(\Pimple\Container $di, #[\SensitiveParameter] array $data): array
    {
        $old = self::settings($di);
        $new = self::database($di, $data, $old, 'ClientExec') + ['upmind' => 0, 'blesta' => 0, 'clientexec' => 1, 'fossbilling' => 0, 'paymenter' => 0] + $old;

        $source = Source::mysql($di, $new, 'ClientExec');
        try {
            if (!\ClientFence\Mod\Clientfence\Clientexec\Snapshot::isClientexec($source)) {
                throw new InformationException('This database has no ClientExec tables. Check the database name.');
            }
            $version = (string) $source->value("SELECT value FROM setting WHERE name = 'ClientExec Version'");
            $clients = $source->count('users', 'groupid IN (SELECT id FROM `groups` WHERE isadmin = 0)');
        } finally {
            $source->close();
        }
        self::saveSettings($di, $old, $new);

        return ['version' => $version, 'clients' => $clients];
    }

    /**
     * Reads ClientExec into the import's snapshot for up to $seconds (Clientexec/Snapshot.php);
     * $restart reads it again from the start.
     *
     * @return array<string, mixed>
     */
    public static function fetchClientexec(\Pimple\Container $di, float $seconds, bool $restart = false): array
    {
        $settings = self::settings($di);
        if ($settings['clientexec'] !== 1) {
            throw new InformationException('Save the ClientExec database details first.');
        }
        if (Run::state($di) !== null) {
            throw new InformationException('The import has started: :name can no longer be read again.', [':name' => 'ClientExec']);
        }

        return \ClientFence\Mod\Clientfence\Clientexec\Snapshot::fetch($di, $settings, $seconds, $restart);
    }

    /**
     * Imports from a FOSSBilling database instead (Fossbilling/Snapshot.php): saves its details once
     * the database answers as FOSSBilling's. Answers {version, clients}: its database patch level.
     *
     * @param array<string, mixed> $data
     *
     * @return array{version: string, clients: int}
     */
    public static function saveFossbilling(\Pimple\Container $di, #[\SensitiveParameter] array $data): array
    {
        $old = self::settings($di);
        $new = self::database($di, $data, $old, 'FOSSBilling') + ['upmind' => 0, 'blesta' => 0, 'clientexec' => 0, 'fossbilling' => 1, 'paymenter' => 0] + $old;

        $source = Source::mysql($di, $new, 'FOSSBilling');
        try {
            if (!\ClientFence\Mod\Clientfence\Fossbilling\Snapshot::isFossbilling($source)) {
                throw new InformationException('This database has no FOSSBilling tables. Check the database name.');
            }
            $version = (string) $source->value("SELECT value FROM setting WHERE param = 'last_patch'");
            $clients = $source->count('client');
        } finally {
            $source->close();
        }
        self::saveSettings($di, $old, $new);

        return ['version' => $version, 'clients' => $clients];
    }

    /**
     * Reads FOSSBilling into the import's snapshot for up to $seconds (Fossbilling/Snapshot.php);
     * $restart reads it again from the start.
     *
     * @return array<string, mixed>
     */
    public static function fetchFossbilling(\Pimple\Container $di, float $seconds, bool $restart = false): array
    {
        $settings = self::settings($di);
        if ($settings['fossbilling'] !== 1) {
            throw new InformationException('Save the FOSSBilling database details first.');
        }
        if (Run::state($di) !== null) {
            throw new InformationException('The import has started: :name can no longer be read again.', [':name' => 'FOSSBilling']);
        }

        return \ClientFence\Mod\Clientfence\Fossbilling\Snapshot::fetch($di, $settings, $seconds, $restart);
    }

    /**
     * Imports from a Paymenter database instead (Paymenter/Snapshot.php): saves its details once
     * the database answers as Paymenter 1.4's or newer. Answers {version, clients}: the version is
     * its newest database migration, as Paymenter keeps no version in its database.
     *
     * @param array<string, mixed> $data
     *
     * @return array{version: string, clients: int}
     */
    public static function savePaymenter(\Pimple\Container $di, #[\SensitiveParameter] array $data): array
    {
        $old = self::settings($di);
        $new = self::database($di, $data, $old, 'Paymenter') + ['upmind' => 0, 'blesta' => 0, 'clientexec' => 0, 'fossbilling' => 0, 'paymenter' => 1] + $old;

        $source = Source::mysql($di, $new, 'Paymenter');
        try {
            if (!\ClientFence\Mod\Clientfence\Paymenter\Snapshot::isPaymenter($source)) {
                throw new InformationException('This database has no Paymenter tables. Check the database name.');
            }
            // Before 1.4 a payment from credit looks like any other payment: it would count twice.
            if (!in_array('is_credit_transaction', $source->columns('invoice_transactions'), true)) {
                throw new InformationException('The import reads Paymenter 1.4 or newer. Update Paymenter first, then try again.');
            }
            $version = (string) $source->value('SELECT MAX(migration) FROM migrations');
            $clients = $source->count('users', 'role_id IS NULL');
        } finally {
            $source->close();
        }
        self::saveSettings($di, $old, $new);

        return ['version' => $version, 'clients' => $clients];
    }

    /**
     * Reads Paymenter into the import's snapshot for up to $seconds (Paymenter/Snapshot.php);
     * $restart reads it again from the start.
     *
     * @return array<string, mixed>
     */
    public static function fetchPaymenter(\Pimple\Container $di, float $seconds, bool $restart = false): array
    {
        $settings = self::settings($di);
        if ($settings['paymenter'] !== 1) {
            throw new InformationException('Save the Paymenter database details first.');
        }
        if (Run::state($di) !== null) {
            throw new InformationException('The import has started: :name can no longer be read again.', [':name' => 'Paymenter']);
        }

        return \ClientFence\Mod\Clientfence\Paymenter\Snapshot::fetch($di, $settings, $seconds, $restart);
    }

    /**
     * The client sign-in, after a wrong password: whether it is the client's password kept from
     * Blesta, which hashes an HMAC of it made with its system key. A match is rehashed, so each
     * client's first sign-in is the only one that takes this path.
     */
    public static function blestaPassword(\Pimple\Container $di, object $client, #[\SensitiveParameter] string $password): bool
    {
        $hash = (string) ($client->pass ?? '');
        $key = str_starts_with($hash, '$2y$') ? self::settings($di)['blesta_key'] : '';
        if ($key === '' || !password_verify(hash_hmac('sha256', $password, (string) $di['crypt']->decrypt($key)), $hash)) {
            return false;
        }
        $client->pass = $di['password']->hashIt($password);
        $client->updated_at = date('Y-m-d H:i:s');
        $di['db']->store($client);

        return true;
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
     * The plan's Products list, read from the source each time (Catalogue::preview(); kept out of the stored
     * plan, which a few hundred products would overflow): each product with staff's choice (server ''
     * is automatic), the servers it may go to (those the import brings, those already in ClientFence) and
     * this install's hosting plan names. Once the import has started the list is not read (locked): the
     * choices stay as they were. A source that cannot be read gives its error instead.
     *
     * @return array<string, mixed>
     */
    public static function products(\Pimple\Container $di): array
    {
        if (Run::state($di) !== null) {
            return ['locked' => true, 'error' => null, 'products' => [], 'servers' => [], 'clientfence_servers' => [], 'plans' => []];
        }
        $settings = self::settings($di);
        try {
            $preview = self::preview($di, $settings);
        } catch (\Throwable $e) {
            return ['locked' => false, 'error' => $e->getMessage(), 'products' => [], 'servers' => [], 'clientfence_servers' => [], 'plans' => []];
        }

        return self::productList($di, $preview, self::sourceKey($settings));
    }

    /**
     * The saved choices if they were made on the source $key names (sourceKey()), else none: choices made on
     * another source name other products.
     *
     * @return array<array-key, array<string, string>>
     */
    public static function choicesFor(\Pimple\Container $di, ?string $key): array
    {
        $row = self::state($di, self::PRODUCTS) ?? [];

        return $key !== null && ($row['source'] ?? null) === $key && is_array($row['choices'] ?? null) ? $row['choices'] : [];
    }

    /**
     * What names the source of the settings: the system and its database (sameSource()). A snapshot read
     * again drops the choices made on the old one instead (Snapshot::read()).
     *
     * @param array<string, int|string> $settings
     */
    public static function sourceKey(array $settings): string
    {
        return hash('sha256', (string) json_encode(self::sourceFields($settings)));
    }

    /**
     * products() for a Catalogue::preview().
     *
     * @param array{products: list<array<string, mixed>>, servers: list<array{id: int, name: string, manager: string}>} $preview
     * @param string $source the source's sourceKey()
     *
     * @return array<string, mixed>
     */
    public static function productList(\Pimple\Container $di, array $preview, string $source): array
    {
        $choices = self::choicesFor($di, $source);
        $db = $di['em']->getConnection();
        $products = [];
        foreach ($preview['products'] as $product) {
            $choice = $choices[(string) $product['id']] ?? null;
            $match = is_array($choice) && ($choice['name'] ?? null) === Catalogue::nameCheck((string) $product['name']);
            $products[] = $product + ['check' => Catalogue::nameCheck((string) $product['name']), 'choice' => ['server' => $match ? (string) ($choice['server'] ?? '') : '', 'plan' => $match ? (string) ($choice['plan'] ?? '') : '']];
        }

        return [
            'locked' => false,
            'error' => null,
            'products' => $products,
            'servers' => $preview['servers'],
            'clientfence_servers' => $db->fetchAllAssociative('SELECT id, name, manager FROM service_hosting_server ORDER BY name, id'),
            'plans' => $db->fetchFirstColumn('SELECT DISTINCT name FROM service_hosting_hp ORDER BY name'),
        ];
    }

    /**
     * Saves staff's product choices from the Products list: products[<id>][server] is '' (automatic: what the
     * list shows), "custom", "s<id>" (a server the import brings) or "c<id>" (one already in ClientFence), and
     * products[<id>][plan] the hosting plan (an existing plan's name, else a new plan of that package; empty:
     * the product's package), with an automatic server too. Licence products stay as they are. Only before
     * the import starts.
     *
     * @param array<string, mixed> $data
     *
     * @return int the products with a choice
     */
    public static function saveProducts(\Pimple\Container $di, array $data): int
    {
        // The run's lock: a save cannot land between a run's start and its products step.
        $db = $di['em']->getConnection();
        if (!Run::lock($db)) {
            throw new InformationException('The import is already running (another tab or the console). Wait for it to finish.');
        }
        try {
            if (Run::state($di) !== null) {
                throw new InformationException('The import has started: what the products become can no longer change.');
            }
            // The settings read once: the choices name the source they were checked against.
            $settings = self::settings($di);
            $choices = self::choose($di, self::preview($di, $settings), $data);
            self::saveState($di, self::PRODUCTS, ['source' => self::sourceKey($settings), 'choices' => $choices]);
        } finally {
            Run::unlock($db);
        }

        return count($choices);
    }

    /**
     * The choices saveProducts() stores for a Catalogue::preview(): only products not left as they are,
     * each with its name's check (Catalogue::nameCheck()), and small enough for the state row.
     *
     * @param array{products: list<array<string, mixed>>, servers: list<array{id: int, name: string, manager: string}>} $preview
     * @param array<string, mixed>                                                                                     $data
     *
     * @return array<string, array{name: string, server: string, plan: string}>
     */
    public static function choose(\Pimple\Container $di, array $preview, array $data): array
    {
        $servers = ['s' => array_map('intval', array_column($preview['servers'], 'id')), 'c' => array_map('intval', $di['em']->getConnection()->fetchFirstColumn('SELECT id FROM service_hosting_server'))];
        $posted = is_array($data['products'] ?? null) ? $data['products'] : [];
        $choices = [];
        foreach ($preview['products'] as $product) {
            $row = $posted[$product['id']] ?? null;
            if (!is_array($row) || $product['kind'] === 'license') {
                continue;
            }
            $server = is_string($row['server'] ?? null) ? trim($row['server']) : '';
            // As typed (or picked from the list): plan names may start or end with spaces.
            $plan = $server === 'custom' || !is_string($row['plan'] ?? null) || trim($row['plan']) === '' ? '' : $row['plan'];
            if (mb_strlen($plan) > 255 || preg_match('/[\x00-\x1f]/', $plan) === 1) {
                throw new InformationException(':what is not valid.', [':what' => 'Hosting plan']);
            }
            if ($server === '' && $plan === '') {
                continue;
            }
            // The form names the product it showed: a source read again since then may have given the id to another.
            if (($row['check'] ?? null) !== Catalogue::nameCheck((string) $product['name'])) {
                throw new InformationException('The products changed since the list was shown: show the products again.');
            }
            if ($server !== '' && $server !== 'custom' && (preg_match('/^([sc])(\d+)$/', $server, $m) !== 1 || !in_array((int) $m[2], $servers[$m[1]], true))) {
                throw new InformationException('Choose a server from the list for :product.', [':product' => $product['name']]);
            }
            $choices[(string) $product['id']] = ['name' => Catalogue::nameCheck((string) $product['name']), 'server' => $server, 'plan' => $plan];
        }
        // The state row is a TEXT column (64 KB).
        if (strlen((string) json_encode($choices, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) > 65535) {
            throw new InformationException('These choices are too long to save: use shorter plan names, or leave more products on Automatic.');
        }

        return $choices;
    }

    /**
     * The source's products and servers (Catalogue::preview()), read only.
     *
     * @param array<string, int|string> $settings
     * @return array{products: list<array<string, mixed>>, servers: list<array{id: int, name: string, manager: string}>}
     */
    private static function preview(\Pimple\Container $di, array $settings): array
    {
        $source = Source::open($di, $settings);
        try {
            return Catalogue::preview($source);
        } finally {
            $source->close();
        }
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

    /**
     * The database fields of a save: WHMCS's or Blesta's. An empty password keeps the saved one.
     *
     * @param array<string, mixed>      $data
     * @param array<string, int|string> $old
     *
     * @return array<string, int|string>
     */
    private static function database(\Pimple\Container $di, #[\SensitiveParameter] array $data, array $old, string $system): array
    {
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
            'attachments_dir' => self::folder((string) ($data['attachments_dir'] ?? '')),
        ];
        if ($new['host'] === '' || $new['database'] === '' || $new['username'] === '' || $new['password'] === '') {
            throw new InformationException('Enter the :name database host, name, username and password.', [':name' => $system]);
        }

        return $new;
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

    /** A saved import setting or plan (SETTINGS, PLAN), or null. */
    public static function state(\Pimple\Container $di, string $name): ?array
    {
        try {
            $row = $di['em']->getConnection()->fetchOne('SELECT value FROM mod_clientfence_state WHERE name = ?', [$name]);
        } catch (\Throwable) {
            return null;
        }
        $value = is_string($row) ? json_decode($row, true) : null;

        return is_array($value) ? $value : null;
    }

    /**
     * @param array<string, int|string> $old
     * @param array<string, int|string> $new
     */
    private static function saveSettings(\Pimple\Container $di, array $old, array $new): void
    {
        self::saveState($di, self::SETTINGS, $new);
        // A plan describes the system it was made from: moving to the other one needs a new plan.
        if (self::system($old) !== self::system($new)) {
            $di['em']->getConnection()->executeStatement('DELETE FROM mod_clientfence_state WHERE name = ?', [self::PLAN]);
        }
        self::forgetChoices($di, $old, $new);
    }

    /**
     * Drops the product choices when the settings move to another source before the import starts: they name
     * that source's products (a snapshot read again drops them too: Snapshot::read()). A started run keeps
     * them: its connection may be fixed and the run go on, and the choices can no longer be made again.
     *
     * @param array<string, int|string> $old
     * @param array<string, int|string> $new
     */
    public static function forgetChoices(\Pimple\Container $di, array $old, array $new): void
    {
        if (!self::sameSource($old, $new) && Run::state($di) === null) {
            $di['em']->getConnection()->executeStatement('DELETE FROM mod_clientfence_state WHERE name = ?', [self::PRODUCTS]);
        }
    }

    /**
     * Whether two import settings read the same source: the system and its database.
     *
     * @param array<string, int|string> $old
     * @param array<string, int|string> $new
     */
    public static function sameSource(array $old, array $new): bool
    {
        return self::sourceFields($old) === self::sourceFields($new);
    }

    /**
     * @param array<string, int|string> $settings
     *
     * @return list<int|string>
     */
    private static function sourceFields(array $settings): array
    {
        return [self::system($settings), strtolower(trim((string) $settings['host'])), (int) $settings['port'], (string) $settings['database']];
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
