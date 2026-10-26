<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Whmcs;

/**
 * ClientFence: the WHMCS import's last step, the add-on module archive (decision 2026-10-18): the
 * rows of every WHMCS add-on module without a mapper here (Inventory::archiveTables(), "Archived,
 * not imported" in the plan) are kept in one file, so nothing is lost and a module of its own can
 * import them later (PayPal billing agreements, for one: owner decision 2026-10-19).
 *
 * The file is gzipped JSON lines, {"table": ..., "row": {...}} each (a value that is not UTF-8
 * text is {"base64": ...}), in the backups folder (Backups::localDir(), outside the website,
 * owner-only), named after the run: whmcs-archive-<run start>.jsonl.gz. It can hold the secrets
 * a module keeps (tokens, keys): treat it as a backup.
 *
 * Each batch appends a gzip member and records the file's size in the id map with the batch, so a
 * batch that rolls back (or never committed) is cut off again before the next one is written.
 */
final class Archive
{
    public const STEPS = ['archive' => ['Add-on module archive', '']];

    /** Position = table index × TABLE_BASE + rows read in that table. */
    private const TABLE_BASE = 1_000_000_000_000;

    /** @var list<string> */
    private array $warnings = [];

    /** @var list<string>|null */
    private ?array $tables = null;

    private ?int $before = null;

    /**
     * @param string $path the archive file ('' when only counting)
     */
    public function __construct(
        private readonly Source $source,
        private readonly Map $map,
        private readonly string $path,
    ) {
    }

    /** The archive file of a run started at $startedAt, in $dir. */
    public static function file(string $dir, string $startedAt): string
    {
        $stamp = date('Ymd-His', strtotime($startedAt) ?: time());

        return rtrim($dir, '/') . '/whmcs-archive-' . $stamp . '.jsonl.gz';
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function total(string $step): int
    {
        $total = 0;
        foreach ($this->tables() as $table) {
            $total += $this->source->count($table);
        }

        return $total;
    }

    /**
     * Archives up to $limit rows from position $after: the next position (null: done) and how many
     * rows were written.
     *
     * @return array{0: ?int, 1: int}
     */
    public function batch(string $step, int $after, int $limit): array
    {
        $tables = $this->tables();
        $index = intdiv($after, self::TABLE_BASE);
        $offset = $after % self::TABLE_BASE;
        if (!isset($tables[$index])) {
            return [null, 0];
        }
        $table = $tables[$index];
        $rows = $this->source->rows('SELECT * FROM ' . $this->quote($table) . $this->order($table) . ' LIMIT ' . $limit . ' OFFSET ' . $offset);
        if ($rows === []) {
            // This table is done: the next one starts, with the file as it is.
            if (!isset($tables[$index + 1])) {
                return [null, 0];
            }
            $next = ($index + 1) * self::TABLE_BASE;
            $this->map->put('archive', (string) $next, $this->size($after));

            return [$next, 0];
        }
        $lines = '';
        foreach ($rows as $row) {
            $lines .= json_encode(['table' => $table, 'row' => array_map(self::value(...), $row)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        }
        $next = $index * self::TABLE_BASE + $offset + count($rows);
        $this->append($after, $next, $lines);

        return [$next, count($rows)];
    }

    /** Cuts off what this batch appended (Run calls it when the batch rolls back). */
    public function discard(): void
    {
        if ($this->before !== null && is_file($this->path)) {
            $this->cut($this->before);
        }
        $this->before = null;
    }

    /**
     * The tables archived, in a fixed order.
     *
     * @return list<string>
     */
    private function tables(): array
    {
        return $this->tables ??= Inventory::archiveTables($this->source);
    }

    /** Appends a batch after cutting the file back to the size the previous batch left. */
    private function append(int $after, int $next, string $lines): void
    {
        if ($this->path === '') {
            throw new \RuntimeException('No archive file was given.');
        }
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            \ClientFence\Mod\Clientfence\Backups::localFolder();
        }
        $expected = $this->size($after);
        clearstatcache(true, $this->path);
        if (!is_file($this->path)) {
            if (@touch($this->path) === false) {
                throw new \RuntimeException('The archive file could not be made in ' . $dir . '.');
            }
            @chmod($this->path, 0600);
        } elseif (filesize($this->path) !== $expected) {
            $this->cut($expected);
        }
        $this->before = $expected;
        $gz = @gzopen($this->path, 'ab9');
        if ($gz === false) {
            throw new \RuntimeException('The archive file ' . basename($this->path) . ' could not be written.');
        }
        $written = gzwrite($gz, $lines);
        gzclose($gz);
        if ($written === false || $written < strlen($lines)) {
            throw new \RuntimeException('The archive file ' . basename($this->path) . ' could not be written (is the disk full?).');
        }
        clearstatcache(true, $this->path);
        $this->map->put('archive', (string) $next, (int) filesize($this->path));
    }

    /** The file size the batches up to position $after left (recorded with each batch). */
    private function size(int $after): int
    {
        if ($after === 0) {
            return 0;
        }

        return $this->map->get('archive', (string) $after) ?? throw new \RuntimeException('The archive file\'s size at this point was not recorded; start the import again on a fresh install.');
    }

    private function cut(int $size): void
    {
        $handle = @fopen($this->path, 'r+b');
        if ($handle === false || !ftruncate($handle, $size)) {
            throw new \RuntimeException('The archive file ' . basename($this->path) . ' could not be cut back after a stop.');
        }
        fclose($handle);
    }

    /** A value as JSON can hold it: text as is, anything else that is not UTF-8 as base64. */
    private static function value(mixed $value): mixed
    {
        if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
            return ['base64' => base64_encode($value)];
        }

        return $value;
    }

    private function quote(string $table): string
    {
        return $this->source->connection()->quoteSingleIdentifier($table);
    }

    /** ORDER BY the table's primary key, so batches read each row once. */
    private function order(string $table): string
    {
        try {
            $keys = $this->source->rows('SHOW KEYS FROM ' . $this->quote($table) . " WHERE Key_name = 'PRIMARY'");
        } catch (\Throwable) {
            $keys = [];
        }
        usort($keys, static fn (array $a, array $b): int => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']);
        $columns = array_map(fn (array $key): string => $this->quote((string) $key['Column_name']), $keys);
        if ($columns === []) {
            // No primary key: every column, so the order is the same in each batch.
            $columns = array_map($this->quote(...), $this->source->columns($table));
        }

        return $columns === [] ? '' : ' ORDER BY ' . implode(', ', $columns);
    }
}
