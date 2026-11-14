<?php

declare(strict_types=1);

namespace ClientFence\Mod\Client;

use ClientFence\InformationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * The client's Support PIN: 8 random digits staff ask for in chat or on the phone to confirm who
 * they talk to. Made the first time it is shown, so older clients get one without a migration step.
 * Each PIN belongs to one client (unique key on `pin`), so staff can find the client by it.
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
        $pin = self::unused($di);
        try {
            $db->insert('client_support_pin', ['client_id' => $clientId, 'pin' => $pin]);
        } catch (UniqueConstraintViolationException) {
            // Two pages made the first PIN at once (keep the saved one), or another client took this PIN meanwhile (try again).
            return self::get($di, $clientId);
        }

        return $pin;
    }

    public static function renew(\Pimple\Container $di, int $clientId): string
    {
        self::get($di, $clientId);
        $pin = self::unused($di);
        try {
            $di['dbal']->update('client_support_pin', ['pin' => $pin], ['client_id' => $clientId]);
        } catch (UniqueConstraintViolationException) {
            // Another client took this PIN meanwhile.
            return self::renew($di, $clientId);
        }

        return $pin;
    }

    /**
     * The client an 8-digit staff search names by Support PIN, or null. A match is written to the
     * activity log (staff member and client, never the PIN).
     */
    public static function findClient(\Pimple\Container $di, string $search): ?int
    {
        if (preg_match('/^\d{8}$/', $search) !== 1) {
            return null;
        }
        $clientId = $di['dbal']->fetchOne('SELECT p.client_id FROM client_support_pin p JOIN client c ON c.id = p.client_id WHERE p.pin = ?', [$search]);
        if ($clientId === false) {
            return null;
        }
        $di['logger']->info('Found client #%s by Support PIN', $clientId);

        return (int) $clientId;
    }

    /** A random PIN no client has (the client's own old PIN included, so New PIN always changes it). */
    private static function unused(\Pimple\Container $di): string
    {
        do {
            $pin = sprintf('%08d', random_int(0, 99_999_999));
        } while ($di['dbal']->fetchOne('SELECT 1 FROM client_support_pin WHERE pin = ?', [$pin]) !== false);

        return $pin;
    }
}
