<?php

declare(strict_types=1);

namespace ClientFence\Mod\Support;

use ClientFence\InformationException;
use Doctrine\DBAL\Connection;

/**
 * ClientFence: encrypted secrets for a client's open support ticket, including authorized users
 * with the tickets permission. Only staff with open_secrets may decrypt them; each opening is
 * recorded separately from ticket messages. Closing or merging removes the values, cron expires
 * them, and deleting the ticket also removes its audit events.
 */
final class TicketSecrets
{
    public const TABLE = 'support_ticket_secret';
    public const EVENTS = 'support_ticket_secret_event';
    public const MAX_SECRETS = 10;

    public static function kinds(\Pimple\Container $di): array
    {
        $config = $di['mod']('support')->getConfig();
        $setting = (string) ($config['secrets_days'] ?? '');

        return [
            'server' => (string) ($config['secrets_server'] ?? '0') === '1',
            'other' => (string) ($config['secrets_other'] ?? '0') === '1',
            'days' => $setting === '' ? 7 : max(1, min(90, (int) $setting)),
        ];
    }

    public static function addForClient(\Pimple\Container $di, int $clientId, int $ticketId, string $kind, array $data): array
    {
        $ticket = self::ticket($di, $ticketId, $clientId);
        if ($ticket['status'] === 'closed') {
            throw new InformationException('Secrets cannot be added to a closed ticket.');
        }
        if (!in_array($kind, ['server', 'other'], true)) {
            throw new InformationException('Choose a valid kind of secret.');
        }
        if (!self::kinds($di)[$kind]) {
            throw new InformationException('This kind of secret is turned off.');
        }
        $db = $di['em']->getConnection();
        if ((int) $db->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE ticket_id = ?', [$ticketId]) >= self::MAX_SECRETS) {
            throw new InformationException('At most :count secrets can be added to a ticket.', [':count' => self::MAX_SECRETS]);
        }
        if ($kind === 'server') {
            $fields = [
                'host' => self::text($data, 'host', 255),
                'type' => self::text($data, 'type', 10),
                'username' => self::text($data, 'username', 255),
                'password' => self::text($data, 'password', 1000),
                'ssh_key' => self::text($data, 'ssh_key', 20000, true),
                'note' => self::text($data, 'note', 2000, true),
            ];
            if ($fields['host'] === '') {
                throw new InformationException('Enter the server host.');
            }
            $types = ['ssh' => 'SSH', 'panel' => 'Panel', 'other' => 'Other'];
            if (!isset($types[$fields['type']])) {
                throw new InformationException('Choose a valid server access type.');
            }
            if ($fields['password'] === '' && $fields['ssh_key'] === '') {
                throw new InformationException('Enter a password or an SSH key.');
            }
            $port = trim((string) ($data['port'] ?? ''));
            if ($port !== '' && (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535)) {
                throw new InformationException('The port must be between 1 and 65535.');
            }
            $fields['port'] = $port === '' ? null : (int) $port;
            $label = mb_substr($types[$fields['type']] . ' ' . $fields['host'], 0, 100);
        } else {
            $fields = ['label' => self::text($data, 'label', 100), 'value' => self::text($data, 'value', 5000, true)];
            if ($fields['label'] === '' || $fields['value'] === '') {
                throw new InformationException('Enter a label and a value for the secret.');
            }
            $label = $fields['label'];
        }
        $encrypted = $di['crypt']->encrypt(json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if (strlen($encrypted) > 65535) {
            throw new InformationException('This secret is too large.');
        }
        $db->insert(self::TABLE, [
            'ticket_id' => $ticketId,
            'kind' => $kind,
            'label' => $label,
            'data' => $encrypted,
            'added_by_client_id' => $clientId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return ['id' => (int) $db->lastInsertId()];
    }

    private static function text(array $data, string $key, int $max, bool $multiline = false): string
    {
        $value = $data[$key] ?? '';
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new InformationException('The :field field must be text.', [':field' => $key]);
        }
        $controls = $multiline ? '/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F-\x{9F}]/u' : '/\p{Cc}/u';
        if (preg_match($controls, $value)) {
            throw new InformationException('The :field field contains control characters.', [':field' => $key]);
        }
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            throw new InformationException('The :field field must be at most :max characters.', [':field' => $key, ':max' => $max]);
        }

        return $value;
    }

    private static function ticket(\Pimple\Container $di, int $ticketId, ?int $clientId): array
    {
        $sql = 'SELECT status FROM support_ticket WHERE id = ? AND client_id > 0';
        $params = [$ticketId];
        if ($clientId !== null) {
            $sql .= ' AND client_id = ?';
            $params[] = $clientId;
        }
        $ticket = $di['em']->getConnection()->fetchAssociative($sql, $params);
        if ($ticket === false) {
            throw new InformationException('Ticket not found');
        }

        return $ticket;
    }

    public static function listForTicket(\Pimple\Container $di, int $ticketId, ?int $clientId = null): array
    {
        self::ticket($di, $ticketId, $clientId);
        $db = $di['em']->getConnection();
        $secrets = $db->fetchAllAssociative('SELECT id, kind, label, created_at FROM ' . self::TABLE . ' WHERE ticket_id = ? ORDER BY id', [$ticketId]);
        foreach ($secrets as &$secret) {
            $secret['id'] = (int) $secret['id'];
        }

        return [
            'secrets' => $secrets,
            'events' => $db->fetchAllAssociative('SELECT e.action, e.secret_label AS label, a.name AS staff_name, e.created_at FROM ' . self::EVENTS . ' e LEFT JOIN admin a ON a.id = e.admin_id WHERE e.ticket_id = ? ORDER BY e.id', [$ticketId]),
        ];
    }

    private static function secret(\Pimple\Container $di, int $id, ?int $clientId): array
    {
        $sql = 'SELECT s.* FROM ' . self::TABLE . ' s JOIN support_ticket t ON t.id = s.ticket_id WHERE s.id = ? AND t.client_id > 0';
        $params = [$id];
        if ($clientId !== null) {
            $sql .= ' AND t.client_id = ?';
            $params[] = $clientId;
        }
        $row = $di['em']->getConnection()->fetchAssociative($sql, $params);
        if ($row === false) {
            throw new InformationException('Ticket not found');
        }

        return $row;
    }

    public static function openForStaff(\Pimple\Container $di, int $id, int $adminId): array
    {
        $row = self::secret($di, $id, null);
        $fields = json_decode($di['crypt']->decrypt($row['data']), true, 512, JSON_THROW_ON_ERROR);
        self::event($di['em']->getConnection(), $row, 'opened', $adminId, null);

        return $fields;
    }

    public static function delete(\Pimple\Container $di, int $id, ?int $adminId, ?int $clientId): void
    {
        $row = self::secret($di, $id, $clientId);
        $di['em']->getConnection()->transactional(static function (Connection $db) use ($row, $id, $adminId, $clientId): void {
            self::event($db, $row, 'deleted', $adminId, $clientId);
            $db->delete(self::TABLE, ['id' => $id]);
        });
    }

    private static function event(Connection $db, array $row, string $action, ?int $adminId, ?int $clientId): void
    {
        $db->insert(self::EVENTS, [
            'ticket_id' => (int) $row['ticket_id'],
            'secret_label' => $row['label'],
            'action' => $action,
            'admin_id' => $adminId,
            'client_id' => $clientId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function forgetTicket(Connection $db, int $ticketId, bool $withEvents = false): void
    {
        $db->delete(self::TABLE, ['ticket_id' => $ticketId]);
        if ($withEvents) {
            $db->delete(self::EVENTS, ['ticket_id' => $ticketId]);
        }
    }

    public static function prune(\Pimple\Container $di): int
    {
        $days = self::kinds($di)['days'];
        $db = $di['em']->getConnection();
        $rows = $db->fetchAllAssociative('SELECT id, ticket_id, label FROM ' . self::TABLE . ' WHERE created_at < ? LIMIT 500', [date('Y-m-d H:i:s', time() - $days * 86400)]);
        $db->transactional(static function (Connection $db) use ($rows): void {
            foreach ($rows as $row) {
                self::event($db, $row, 'expired', null, null);
                $db->delete(self::TABLE, ['id' => (int) $row['id']]);
            }
        });

        return count($rows);
    }
}
