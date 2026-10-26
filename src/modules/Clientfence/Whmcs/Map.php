<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Whmcs;

use Doctrine\DBAL\Connection;

/**
 * ClientFence: the WHMCS import's id map (mod_clientfence_import_map): which ClientFence row each
 * imported WHMCS row became, by entity ("client", "user", "contact", ...). A row that is in the map
 * is never imported twice, so every step can run again after a stop; verify and sync read it too.
 */
final class Map
{
    public const TABLE = 'mod_clientfence_import_map';
    public const SOURCE = 'whmcs';

    /** @var array<string, array<string, int>> entity => source id => target id */
    private array $cache = [];

    public function __construct(private readonly Connection $db)
    {
    }

    public function get(string $entity, int|string $sourceId): ?int
    {
        $this->load($entity);

        return $this->cache[$entity][(string) $sourceId] ?? null;
    }

    public function has(string $entity, int|string $sourceId): bool
    {
        return $this->get($entity, $sourceId) !== null;
    }

    public function put(string $entity, int|string $sourceId, int $targetId): void
    {
        $this->load($entity);
        $this->db->insert(self::TABLE, [
            'source' => self::SOURCE,
            'entity' => $entity,
            'source_id' => (string) $sourceId,
            'target_id' => $targetId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->cache[$entity][(string) $sourceId] = $targetId;
    }

    public function count(string $entity): int
    {
        $this->load($entity);

        return count($this->cache[$entity]);
    }

    /** @return array<string, int> source id => target id */
    public function all(string $entity): array
    {
        $this->load($entity);

        return $this->cache[$entity];
    }

    /** Forgets what was read, after a batch was rolled back. */
    public function reset(): void
    {
        $this->cache = [];
    }

    private function load(string $entity): void
    {
        if (isset($this->cache[$entity])) {
            return;
        }
        $this->cache[$entity] = [];
        foreach ($this->db->fetchAllAssociative('SELECT source_id, target_id FROM ' . self::TABLE . ' WHERE source = ? AND entity = ?', [self::SOURCE, $entity]) as $row) {
            $this->cache[$entity][(string) $row['source_id']] = (int) $row['target_id'];
        }
    }
}
