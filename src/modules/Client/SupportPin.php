<?php

declare(strict_types=1);

namespace ClientFence\Mod\Client;

use ClientFence\InformationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * The client's Support PIN: 8 random digits staff ask for in chat or on the phone to confirm who
 * they talk to. Made the first time it is shown, so older clients get one without a migration step.
 */
final class SupportPin
{
    public static function get(\Pimple\Container $di, int $clientId): string
    {
        $db = $di['dbal'];
        $pin = $db->fetchOne('SELECT pin FROM client_support_pin WHERE client_id = ?', [$clientId]);
        if ($pin !== false) {
            return (string) $pin;
        }
        if ($db->fetchOne('SELECT id FROM client WHERE id = ?', [$clientId]) === false) {
            throw new InformationException('Client not found');
        }
        $pin = self::random();
        try {
            $db->insert('client_support_pin', ['client_id' => $clientId, 'pin' => $pin]);
        } catch (UniqueConstraintViolationException) {
            // Two pages made the first PIN at once: keep the one that was saved.
            return (string) $db->fetchOne('SELECT pin FROM client_support_pin WHERE client_id = ?', [$clientId]);
        }

        return $pin;
    }

    public static function renew(\Pimple\Container $di, int $clientId): string
    {
        $old = self::get($di, $clientId);
        do {
            $pin = self::random();
        } while ($pin === $old);
        $di['dbal']->update('client_support_pin', ['pin' => $pin], ['client_id' => $clientId]);

        return $pin;
    }

    private static function random(): string
    {
        return sprintf('%08d', random_int(0, 99_999_999));
    }
}
