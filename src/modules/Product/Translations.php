<?php

declare(strict_types=1);

namespace ClientFence\Mod\Product;

use ClientFence\Config;
use ClientFence\i18n;
use ClientFence\InformationException;
use ClientFence\Mod\Product\Entity\Product;
use ClientFence\RequestCache;

/**
 * ClientFence: names and descriptions of products, add-ons (`product`) and categories (`category`),
 * order form questions (`field`: label as title, description) and help desk names (`helpdesk`)
 * per language, as WHMCS's Dynamic Field Translation. The store, cart and client area show the
 * page language's text (Product\Service), the order title is written in it at checkout, and a
 * blank text or a language that is turned off shows the default text. Staff pages show the
 * default texts. Saved with the item's form (`translations[lang][title|description]`) and dropped
 * with the item. Reads never fail: before the table exists every text is the default one.
 *
 * Rows: product_translation (kind, ref_id, lang, title, description); null for a blank field.
 */
final class Translations
{
    public const TABLE = 'product_translation';
    public const KINDS = ['product', 'category', 'field', 'helpdesk'];

    /** The language a client's texts are written in: theirs when it is turned on, else the default one. */
    public static function locale(?string $lang): string
    {
        return in_array($lang, i18n::getLocales(), true) ? $lang : (string) Config::getProperty('i18n.locale', 'en_US');
    }

    /**
     * $field (title or description) of an item in $locale (the page language when null) when that
     * language is turned on and the text is not blank, else $default.
     */
    public static function text(\Pimple\Container $di, string $kind, int $refId, string $field, ?string $default, ?string $locale = null): ?string
    {
        $locale ??= \Locale::getDefault();
        if (!in_array($locale, i18n::getLocales(), true)) {
            return $default;
        }

        return self::forLocale($di, $locale)[$kind][$refId][$field] ?? $default;
    }

    /** A product's name in $locale (the page language when null). */
    public static function title(\Pimple\Container $di, Product $product, ?string $locale = null): string
    {
        return (string) self::text($di, 'product', (int) $product->getId(), 'title', $product->getTitle(), $locale);
    }

    /**
     * An API array (with `id`, `title`, `description`) with the page language's texts.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function overlay(\Pimple\Container $di, string $kind, array $row): array
    {
        foreach (['title', 'description'] as $field) {
            $row[$field] = self::text($di, $kind, (int) $row['id'], $field, $row[$field]);
        }

        return $row;
    }

    /**
     * Staff view of an item's texts by language.
     *
     * @return array<string, array{title: ?string, description: ?string}>
     */
    public static function forItem(\Pimple\Container $di, string $kind, int $refId): array
    {
        try {
            $rows = $di['em']->getConnection()->fetchAllAssociative('SELECT lang, title, description FROM ' . self::TABLE . ' WHERE kind = ? AND ref_id = ? ORDER BY lang', [$kind, $refId]);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['lang']] = ['title' => $row['title'], 'description' => $row['description']];
        }

        return $out;
    }

    /**
     * Replaces an item's texts with $translations[lang] = {title, description}; a blank field uses
     * the default text and a language left out (or left blank) loses its texts.
     *
     * @param array<mixed> $translations
     */
    public static function save(\Pimple\Container $di, string $kind, int $refId, array $translations): void
    {
        $installed = array_merge(i18n::getLocales(), i18n::getLocales(false, true));
        $rows = [];
        foreach ($translations as $lang => $texts) {
            $lang = (string) $lang;
            if (!in_array($lang, $installed, true)) {
                throw new InformationException('Invalid locale code: :code', [':code' => $lang]);
            }
            $row = [];
            foreach (['title', 'description'] as $field) {
                $value = is_array($texts) && is_string($texts[$field] ?? null) ? trim($texts[$field]) : '';
                $row[$field] = $value === '' ? null : $value;
            }
            if ($row['title'] !== null || $row['description'] !== null) {
                $rows[$lang] = $row;
            }
        }

        $now = date('Y-m-d H:i:s');
        $di['em']->getConnection()->transactional(function ($db) use ($kind, $refId, $rows, $now): void {
            $db->delete(self::TABLE, ['kind' => $kind, 'ref_id' => $refId]);
            foreach ($rows as $lang => $row) {
                $db->insert(self::TABLE, ['kind' => $kind, 'ref_id' => $refId, 'lang' => $lang] + $row + ['created_at' => $now, 'updated_at' => $now]);
            }
        });
        RequestCache::forget($di, 'product_translation.');
    }

    /** Drops an item's texts (the item was deleted). */
    public static function forget(\Pimple\Container $di, string $kind, int $refId): void
    {
        try {
            $di['em']->getConnection()->delete(self::TABLE, ['kind' => $kind, 'ref_id' => $refId]);
        } catch (\Throwable) {
            // No table yet: nothing to drop.
        }
    }

    /**
     * Every item's texts in one language, read once per request.
     *
     * @return array<string, array<int, array{title: ?string, description: ?string}>>
     */
    private static function forLocale(\Pimple\Container $di, string $locale): array
    {
        return RequestCache::remember($di, 'product_translation.' . $locale, static function () use ($di, $locale): array {
            try {
                $rows = $di['em']->getConnection()->fetchAllAssociative('SELECT kind, ref_id, title, description FROM ' . self::TABLE . ' WHERE lang = ?', [$locale]);
            } catch (\Throwable) {
                return [];
            }
            $out = [];
            foreach ($rows as $row) {
                $out[(string) $row['kind']][(int) $row['ref_id']] = ['title' => $row['title'], 'description' => $row['description']];
            }

            return $out;
        });
    }
}
