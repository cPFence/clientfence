<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Whmcs;

use ClientFence\Mod\Servicelicense\Bans;
use ClientFence\Mod\Servicelicense\CheckLog;
use Doctrine\DBAL\Connection;

/**
 * ClientFence: the WHMCS import's licence steps (Run.php runs them after the support steps), the
 * mapper of WHMCS's Licensing add-on: its licence keys and bindings came over with their services
 * (Services::licence()); here its bans become licence bans (Servicelicense\Bans) and its log
 * becomes the licence check log (Servicelicense\CheckLog, owner decision 2026-10-19), with WHMCS's
 * "Log Prune" days as "Keep the licence check log".
 *
 * A log line belongs to a licence through mod_licensing (licenseid => serviceid => the imported
 * order's licence). Lines of licence keys WHMCS no longer has (checks with an unknown key) or of
 * services that were not imported are left out and counted in a warning.
 */
final class Licensing
{
    /** The steps in order: name => [label, WHMCS table read]. */
    public const STEPS = [
        'licencebans' => ['Licence bans', 'mod_licensingbans'],
        'licencelog' => ['Licence check log', 'mod_licensinglog'],
    ];

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        private readonly Source $source,
        private readonly Connection $db,
        private readonly Map $map,
        private readonly string $now,
    ) {
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function total(string $step): int
    {
        $table = self::STEPS[$step][1];

        return $this->source->hasTable($table) ? $this->source->count($table) : 0;
    }

    /**
     * Imports up to $limit rows of a step after the WHMCS id $after: the last id read (null: done)
     * and how many rows were imported.
     *
     * @return array{0: ?int, 1: int}
     */
    public function batch(string $step, int $after, int $limit): array
    {
        $table = self::STEPS[$step][1] ?? throw new \InvalidArgumentException('Unknown step ' . $step);
        if (!$this->source->hasTable($table)) {
            $this->finish($step);

            return [null, 0];
        }
        $rows = $this->source->rows('SELECT * FROM ' . $table . ' WHERE id > ? ORDER BY id LIMIT ' . $limit, [$after]);
        if ($rows === []) {
            $this->finish($step);

            return [null, 0];
        }
        $done = $step === 'licencebans' ? array_sum(array_map($this->ban(...), $rows)) : $this->logLines($rows);

        return [(int) end($rows)['id'], $done];
    }

    /** What a step does once its last batch ran. */
    private function finish(string $step): void
    {
        if ($step !== 'licencelog' || $this->map->has('notice', 'licencelog')) {
            return;
        }
        $this->map->put('notice', 'licencelog', 0);
        if (!$this->source->hasTable('mod_licensinglog')) {
            return;
        }
        // WHMCS's "Log Prune" (days; empty or 0 keeps every line) becomes "Keep the licence check log".
        $prune = $this->source->hasTable('tbladdonmodules')
            ? trim((string) $this->source->value("SELECT value FROM tbladdonmodules WHERE module = 'licensing' AND setting = 'logprune'"))
            : '';
        if ($prune !== '' && is_numeric($prune)) {
            CheckLog::storeDays($this->db, (int) $prune);
        } elseif ($prune === '') {
            CheckLog::storeDays($this->db, 0);
        }
        $left = 0;
        $licences = $this->licences(array_map(intval(...), $this->source->connection()->fetchFirstColumn('SELECT DISTINCT licenseid FROM mod_licensinglog')));
        foreach ($this->source->rows('SELECT licenseid, COUNT(*) AS n FROM mod_licensinglog GROUP BY licenseid') as $row) {
            if (!isset($licences[(int) $row['licenseid']])) {
                $left += (int) $row['n'];
            }
        }
        if ($left > 0) {
            $this->warn(sprintf('%d licence check log lines are for licence keys WHMCS no longer has (checks with an unknown key) or for services that were not imported; they were not imported (they stay in the WHMCS database).', $left));
        }
    }

    /** @param array<string, mixed> $row */
    private function ban(array $row): int
    {
        if ($this->map->has('licenceban', $row['id'])) {
            return 0;
        }
        $value = Bans::normalize((string) ($row['value'] ?? ''));
        $valid = $value !== '' && mb_strlen($value) <= 255
            && (Bans::kind($value) === 'ip' || preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $value) === 1);
        if (!$valid) {
            $this->warn(sprintf('Licence ban "%s" is not a domain or an IP address; it was not imported.', mb_substr((string) ($row['value'] ?? ''), 0, 100)));

            return 0;
        }
        $existing = $this->db->fetchOne('SELECT id FROM ' . Bans::TABLE . ' WHERE value = ?', [$value]);
        if ($existing === false) {
            $this->db->insert(Bans::TABLE, ['value' => $value, 'notes' => mb_substr(trim((string) ($row['notes'] ?? '')), 0, 1000), 'created_at' => $this->now]);
            $existing = $this->db->lastInsertId();
        }
        $this->map->put('licenceban', $row['id'], (int) $existing);

        return 1;
    }

    /**
     * The log lines of a batch, written in one statement; returns how many were written.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function logLines(array $rows): int
    {
        $licences = $this->licences(array_values(array_unique(array_map(static fn (array $row): int => (int) $row['licenseid'], $rows))));
        $values = [];
        $params = [];
        foreach ($rows as $row) {
            $licence = $licences[(int) $row['licenseid']] ?? null;
            if ($licence === null) {
                continue;
            }
            $values[] = '(?, ?, ?, ?, ?, ?, ?)';
            array_push(
                $params,
                $licence,
                self::cut($row['domain'] ?? ''),
                self::cut($row['ip'] ?? ''),
                self::cut($row['path'] ?? ''),
                '',
                self::cut($row['message'] ?? ''),
                Accounts::date($row['datetime'] ?? null) ?? $this->now,
            );
        }
        if ($values !== []) {
            $this->db->executeStatement('INSERT INTO ' . CheckLog::TABLE . ' (service_license_id, host, ip, path, version, result, created_at) VALUES ' . implode(', ', $values), $params);
        }

        return count($values);
    }

    /**
     * WHMCS licence ids => this install's licence (service_license) ids, for the licences whose
     * services were imported.
     *
     * @param list<int> $ids
     *
     * @return array<int, int>
     */
    private function licences(array $ids): array
    {
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        if ($ids === [] || !$this->source->hasTable('mod_licensing')) {
            return [];
        }
        $orders = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($this->source->rows('SELECT id, serviceid FROM mod_licensing WHERE id IN (' . implode(',', $chunk) . ')') as $row) {
                $order = $this->map->get('service', (int) $row['serviceid']);
                if ($order !== null) {
                    $orders[(int) $row['id']] = $order;
                }
            }
        }
        if ($orders === []) {
            return [];
        }
        $licenceOf = [];
        foreach (array_chunk(array_values(array_unique($orders)), 500) as $chunk) {
            foreach ($this->db->fetchAllAssociative("SELECT id, service_id FROM client_order WHERE service_type = 'license' AND service_id IS NOT NULL AND id IN (" . implode(',', $chunk) . ')') as $order) {
                $licenceOf[(int) $order['id']] = (int) $order['service_id'];
            }
        }
        $out = [];
        foreach ($orders as $licenceId => $order) {
            if (isset($licenceOf[$order])) {
                $out[$licenceId] = $licenceOf[$order];
            }
        }

        return $out;
    }

    private static function cut(mixed $value, int $length = 255): string
    {
        return mb_substr(trim(is_scalar($value) ? (string) $value : ''), 0, $length);
    }

    private function warn(string $message): void
    {
        $this->warnings[] = $message;
    }
}
