<?php

declare(strict_types=1);

namespace ClientFence\Mod\Client;

use ClientFence\InformationException;

final class Notes
{
    public static function getList(\Pimple\Container $di, int $clientId): array
    {
        return $di['dbal']->fetchAllAssociative(
            'SELECT n.*, a.name AS author FROM client_note n LEFT JOIN admin a ON a.id = n.admin_id WHERE n.client_id = ? ORDER BY n.pinned DESC, n.created_at DESC, n.id DESC',
            [$clientId]
        );
    }

    public static function create(\Pimple\Container $di, int $clientId, int $adminId, mixed $note): int
    {
        $db = $di['dbal'];
        if ($db->fetchOne('SELECT id FROM client WHERE id = ?', [$clientId]) === false) {
            throw new InformationException('Client not found');
        }
        $now = date('Y-m-d H:i:s');
        $db->insert('client_note', [
            'client_id' => $clientId,
            'admin_id' => $adminId,
            'note' => self::text($note),
            'pinned' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $db->lastInsertId();
    }

    public static function update(\Pimple\Container $di, int $id, mixed $note, bool $pinned): void
    {
        $db = $di['dbal'];
        if ($db->fetchOne('SELECT id FROM client_note WHERE id = ?', [$id]) === false) {
            throw new InformationException('Note not found');
        }
        $db->update('client_note', ['note' => self::text($note), 'pinned' => (int) $pinned, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
    }

    public static function delete(\Pimple\Container $di, int $id): void
    {
        if ($di['dbal']->delete('client_note', ['id' => $id]) === 0) {
            throw new InformationException('Note not found');
        }
    }

    private static function text(mixed $note): string
    {
        if (!is_string($note) || trim($note) === '') {
            throw new InformationException('Note is required');
        }
        if (strlen($note) > 65535) {
            throw new InformationException('Note is too long');
        }

        return trim($note);
    }
}
