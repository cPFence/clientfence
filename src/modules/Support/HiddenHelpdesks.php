<?php

declare(strict_types=1);

namespace ClientFence\Mod\Support;

use ClientFence\Tools;
use Doctrine\DBAL\Connection;

/**
 * ClientFence: help desks hidden from clients, as WHMCS's "Hide from clients" department setting.
 *
 * A hidden help desk is left out of the help desks clients and guests choose from when they open
 * a ticket (client and guest helpdesk_get_pairs, so the ticket and order forms), and the client and
 * guest ticket_create refuse it. Everything else stays: staff open and move tickets into it, its
 * clients read and reply to their tickets, its mailbox imports mail (MailImport, as WHMCS's piping
 * to hidden departments) and the system opens tickets in it (receipts, privacy requests).
 *
 * Saved on the help desk's page ("Hidden from clients"); table support_helpdesk_hidden holds the
 * hidden ones.
 */
final class HiddenHelpdesks
{
    public const TABLE = 'support_helpdesk_hidden';

    /**
     * The hidden help desks' ids.
     *
     * @return list<int>
     */
    public static function ids(\Pimple\Container $di): array
    {
        try {
            return array_map(intval(...), self::db($di)->fetchFirstColumn('SELECT helpdesk_id FROM ' . self::TABLE . ' ORDER BY helpdesk_id'));
        } catch (\Throwable) {
            // Table not made yet (pack installer not run): nothing is hidden.
            return [];
        }
    }

    public static function isHidden(\Pimple\Container $di, int $helpdeskId): bool
    {
        return in_array($helpdeskId, self::ids($di), true);
    }

    /**
     * The help desks clients and guests may open tickets in: id => name.
     *
     * @param array<int, string> $pairs every help desk (HelpdeskRepository::getPairs())
     *
     * @return array<int, string>
     */
    public static function visiblePairs(\Pimple\Container $di, array $pairs): array
    {
        return array_diff_key($pairs, array_flip(self::ids($di)));
    }

    /** Saves a help desk's "Hidden from clients" from its form (core patch in Service::helpdeskUpdate()). */
    public static function save(\Pimple\Container $di, int $helpdeskId, mixed $hidden): void
    {
        $db = self::db($di);
        $hidden = Tools::normalizeBoolean($hidden, false);
        self::withTable($di, static function () use ($db, $helpdeskId, $hidden): void {
            if (!$hidden) {
                $db->delete(self::TABLE, ['helpdesk_id' => $helpdeskId]);
            } elseif ($db->fetchOne('SELECT 1 FROM ' . self::TABLE . ' WHERE helpdesk_id = ?', [$helpdeskId]) === false) {
                $db->insert(self::TABLE, ['helpdesk_id' => $helpdeskId, 'hidden_at' => date('Y-m-d H:i:s')]);
            }
        });
    }

    /** A deleted help desk's setting (core patch in Service::helpdeskRm()). */
    public static function forget(\Pimple\Container $di, int $helpdeskId): void
    {
        try {
            self::db($di)->delete(self::TABLE, ['helpdesk_id' => $helpdeskId]);
        } catch (\Throwable) {
        }
    }

    /**
     * Help desk list rows (staff) with `hidden` added.
     *
     * @param array<mixed> $rows
     *
     * @return array<mixed>
     */
    public static function withFlags(\Pimple\Container $di, array $rows): array
    {
        $hidden = self::ids($di);
        foreach ($rows as $key => $row) {
            if (is_array($row) && isset($row['id'])) {
                $rows[$key]['hidden'] = in_array((int) $row['id'], $hidden, true);
            }
        }

        return $rows;
    }

    private static function withTable(\Pimple\Container $di, callable $query): void
    {
        try {
            $query();
        } catch (\Doctrine\DBAL\Exception\TableNotFoundException) {
            \ClientFence\Mod\Clientfence\Service::ensureTables($di, true);
            $query();
        }
    }

    private static function db(\Pimple\Container $di): Connection
    {
        return $di['em']->getConnection();
    }
}
