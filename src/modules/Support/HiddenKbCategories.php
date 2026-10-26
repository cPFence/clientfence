<?php

declare(strict_types=1);

namespace ClientFence\Mod\Support;

use ClientFence\Tools;
use Doctrine\DBAL\Connection;

/**
 * ClientFence: knowledge base categories hidden from clients, as WHMCS's "Hidden" category setting.
 *
 * Clients and guests do not see a hidden category or its articles: the knowledge base lists, search
 * and ticket suggestions leave them out (guest kb_category_get_list and kb_article_get_list), and its
 * page and its articles' pages are not found (guest kb_category_get and kb_article_get). Staff see
 * and edit everything as before. For staff notes, or articles kept but no longer offered.
 *
 * Saved on the category's page ("Hidden from clients"); table support_kb_category_hidden holds the
 * hidden ones.
 */
final class HiddenKbCategories
{
    public const TABLE = 'support_kb_category_hidden';

    /**
     * The hidden categories' ids.
     *
     * @return list<int>
     */
    public static function ids(\Pimple\Container $di): array
    {
        try {
            return array_map(intval(...), self::db($di)->fetchFirstColumn('SELECT category_id FROM ' . self::TABLE . ' ORDER BY category_id'));
        } catch (\Throwable) {
            // Table not made yet (pack installer not run): nothing is hidden.
            return [];
        }
    }

    public static function isHidden(\Pimple\Container $di, ?int $categoryId): bool
    {
        return $categoryId !== null && in_array($categoryId, self::ids($di), true);
    }

    /** Saves a category's "Hidden from clients" from its page (core patch in Api\Admin::kb_category_update()). */
    public static function save(\Pimple\Container $di, int $categoryId, mixed $hidden): void
    {
        $db = self::db($di);
        $hidden = Tools::normalizeBoolean($hidden, false);
        self::withTable($di, static function () use ($db, $categoryId, $hidden): void {
            if (!$hidden) {
                $db->delete(self::TABLE, ['category_id' => $categoryId]);
            } elseif ($db->fetchOne('SELECT 1 FROM ' . self::TABLE . ' WHERE category_id = ?', [$categoryId]) === false) {
                $db->insert(self::TABLE, ['category_id' => $categoryId, 'hidden_at' => date('Y-m-d H:i:s')]);
            }
        });
    }

    /** A deleted category's setting (core patch in Api\Admin::kb_category_delete()). */
    public static function forget(\Pimple\Container $di, int $categoryId): void
    {
        try {
            self::db($di)->delete(self::TABLE, ['category_id' => $categoryId]);
        } catch (\Throwable) {
        }
    }

    /**
     * Category list rows (staff) with `hidden` added.
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
