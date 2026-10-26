<?php

declare(strict_types=1);

namespace ClientFence\Mod\Product;

use ClientFence\InformationException;
use Doctrine\DBAL\Connection;

/**
 * ClientFence: configurable options, as WHMCS's. Staff make option groups (Products > Configurable
 * options) and attach them to products (the product's Options tab). A group holds options of four
 * kinds:
 * - select and radio: the client picks one value ("RAM: 2 GB / 4 GB / 8 GB");
 * - yesno: one value, on or off ("Daily backups");
 * - quantity: one value priced per unit, between a minimum and a maximum (0: no maximum).
 * Every value has a setup and a recurring price per billing period, in the default currency as the
 * product prices (FOSSBilling converts them for other currencies); a one-time or free product uses
 * the "once" prices. A hidden option or value is not offered on new orders but stays on the
 * services that have it.
 *
 * A choice (cart item config `options`) maps an option id to a value id (select, radio), 0 or 1
 * (yesno) or a number (quantity). resolve() checks it and prices it; the product's price is its own
 * plus the options' (Product\Service::getProductOrderLineConfig()), and an order keeps the chosen
 * lines with their keys (`option_lines`), which service modules read.
 *
 * Tables product_option_group, product_option_group_product, product_option and
 * product_option_value (Clientfence/schema.json); promo_option keeps the promotions whose discount
 * also covers the options (off by default).
 */
final class Options
{
    public const TYPES = ['select', 'radio', 'yesno', 'quantity'];
    /** Price columns of product_option_value: the product_payment periods plus "once". */
    public const PRICE_KEYS = ['once', 'w', 'm', 'q', 'b', 'a', 'bia', 'tria'];
    public const PERIOD_KEYS = ['1W' => 'w', '1M' => 'm', '3M' => 'q', '6M' => 'b', '1Y' => 'a', '12M' => 'a', '2Y' => 'bia', '3Y' => 'tria'];
    public const MAX_OPTIONS = 100;
    public const MAX_VALUES = 100;
    public const MAX_QUANTITY = 100000;
    public const TITLE_MAX = 255;
    public const CODE_MAX = 100;

    // ----- groups ------------------------------------------------------------------------------

    /**
     * The groups with their number of options and the products they are attached to.
     *
     * @return list<array{id: int, title: string, description: string, options: int, products: list<int>}>
     */
    public static function groups(\Pimple\Container $di): array
    {
        $db = self::db($di);
        $links = [];
        foreach ($db->fetchAllAssociative('SELECT group_id, product_id FROM product_option_group_product ORDER BY product_id') as $row) {
            $links[(int) $row['group_id']][] = (int) $row['product_id'];
        }
        $groups = [];
        foreach ($db->fetchAllAssociative('SELECT g.id, g.title, g.description, (SELECT COUNT(*) FROM product_option o WHERE o.group_id = g.id) AS options FROM product_option_group g ORDER BY g.title, g.id') as $row) {
            $groups[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'description' => (string) $row['description'],
                'options' => (int) $row['options'],
                'products' => $links[(int) $row['id']] ?? [],
            ];
        }

        return $groups;
    }

    /**
     * A group with its options (and their values) and products.
     *
     * @return array{id: int, title: string, description: string, products: list<int>, options: list<array<string, mixed>>}
     */
    public static function group(\Pimple\Container $di, int $id): array
    {
        $db = self::db($di);
        $row = $db->fetchAssociative('SELECT id, title, description FROM product_option_group WHERE id = ?', [$id]);
        if ($row === false) {
            throw new InformationException('Option group not found', [], 404);
        }

        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'description' => (string) $row['description'],
            'products' => array_map('intval', $db->fetchFirstColumn('SELECT product_id FROM product_option_group_product WHERE group_id = ? ORDER BY product_id', [$id])),
            'options' => self::optionsOf($db, [$id], true),
        ];
    }

    /** Creates (no id) or updates a group: `title`, `description`. Returns its id. */
    public static function saveGroup(\Pimple\Container $di, array $data): int
    {
        $db = self::db($di);
        $id = (int) ($data['id'] ?? 0);
        $row = [
            'title' => self::text($data['title'] ?? '', 'Group name', true),
            'description' => self::text($data['description'] ?? '', 'Description', false, 2000),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($id > 0) {
            self::group($di, $id);
            $db->update('product_option_group', $row, ['id' => $id]);

            return $id;
        }
        $row['created_at'] = $row['updated_at'];
        $db->insert('product_option_group', $row);

        return (int) $db->lastInsertId();
    }

    /** Deletes a group with its options and values. Orders keep the option lines they have. */
    public static function deleteGroup(\Pimple\Container $di, int $id): void
    {
        self::group($di, $id);
        $db = self::db($di);
        $db->transactional(function (Connection $db) use ($id): void {
            $options = array_map('intval', $db->fetchFirstColumn('SELECT id FROM product_option WHERE group_id = ?', [$id]));
            if ($options !== []) {
                $db->executeStatement('DELETE FROM product_option_value WHERE option_id IN (' . implode(',', $options) . ')');
            }
            $db->delete('product_option', ['group_id' => $id]);
            $db->delete('product_option_group_product', ['group_id' => $id]);
            $db->delete('product_option_group', ['id' => $id]);
        });
    }

    /**
     * Sets the groups attached to a product (the product's Options tab).
     *
     * @param list<int|string> $groupIds
     */
    public static function setProductGroups(\Pimple\Container $di, int $productId, array $groupIds): void
    {
        $db = self::db($di);
        $ids = array_values(array_unique(array_filter(array_map('intval', $groupIds), fn (int $id): bool => $id > 0)));
        if ($ids !== []) {
            $known = array_map('intval', $db->fetchFirstColumn('SELECT id FROM product_option_group WHERE id IN (' . implode(',', $ids) . ')'));
            if (count($known) !== count($ids)) {
                throw new InformationException('Option group not found', [], 404);
            }
        }
        $db->transactional(function (Connection $db) use ($productId, $ids): void {
            $db->delete('product_option_group_product', ['product_id' => $productId]);
            foreach ($ids as $groupId) {
                $db->insert('product_option_group_product', ['group_id' => $groupId, 'product_id' => $productId]);
            }
        });
    }

    /** @return list<int> the groups attached to a product */
    public static function productGroups(\Pimple\Container $di, int $productId): array
    {
        if (!self::ready($di)) {
            return [];
        }

        return array_map('intval', self::db($di)->fetchFirstColumn('SELECT group_id FROM product_option_group_product WHERE product_id = ? ORDER BY group_id', [$productId]));
    }

    // ----- options -----------------------------------------------------------------------------

    /**
     * Creates (no id) or updates an option with its values: `group_id`, `title`, `code`, `type`,
     * `qty_min`, `qty_max`, `unit`, `hidden`, `position` and `values` (a list of `id` (none for a new
     * one), `title`, `code`, `hidden` and the prices `<key>_price` and `<key>_setup_price` for
     * PRICE_KEYS). A value left out of the list is deleted. Returns the option id.
     */
    public static function saveOption(\Pimple\Container $di, array $data): int
    {
        $db = self::db($di);
        $id = (int) ($data['id'] ?? 0);
        $current = null;
        if ($id > 0) {
            $current = $db->fetchAssociative('SELECT * FROM product_option WHERE id = ?', [$id]);
            if ($current === false) {
                throw new InformationException('Option not found', [], 404);
            }
        }
        $groupId = $current !== null ? (int) $current['group_id'] : (int) ($data['group_id'] ?? 0);
        self::group($di, $groupId);
        if ($current === null && (int) $db->fetchOne('SELECT COUNT(*) FROM product_option WHERE group_id = ?', [$groupId]) >= self::MAX_OPTIONS) {
            throw new InformationException('A group can have at most :max options.', [':max' => self::MAX_OPTIONS]);
        }

        $type = (string) ($data['type'] ?? ($current['type'] ?? ''));
        if (!in_array($type, self::TYPES, true)) {
            throw new InformationException('Choose the kind of option.');
        }
        if ($current !== null && $current['type'] !== $type) {
            throw new InformationException('The kind of an option cannot change. Make a new option instead.');
        }
        $qtyMin = $type === 'quantity' ? self::int($data['qty_min'] ?? 0, 'Minimum') : 0;
        $qtyMax = $type === 'quantity' ? self::int($data['qty_max'] ?? 0, 'Maximum') : 0;
        if ($qtyMax > 0 && $qtyMax < $qtyMin) {
            throw new InformationException('The maximum cannot be below the minimum.');
        }

        $values = is_array($data['values'] ?? null) ? array_values($data['values']) : [];
        if ($values === []) {
            throw new InformationException('An option needs at least one value.');
        }
        if (count($values) > self::MAX_VALUES) {
            throw new InformationException('An option can have at most :max values.', [':max' => self::MAX_VALUES]);
        }
        if (in_array($type, ['yesno', 'quantity'], true) && count($values) !== 1) {
            throw new InformationException('A yes/no or quantity option has exactly one value: its price.');
        }

        $now = date('Y-m-d H:i:s');
        $row = [
            'group_id' => $groupId,
            'title' => self::text($data['title'] ?? '', 'Option name', true),
            'code' => self::code($data['code'] ?? ''),
            'type' => $type,
            'qty_min' => $qtyMin,
            'qty_max' => $qtyMax,
            'unit' => self::text($data['unit'] ?? '', 'Unit', false, 50),
            'hidden' => self::bool($data['hidden'] ?? false) ? 1 : 0,
            'position' => self::int($data['position'] ?? ($current['position'] ?? 0), 'Position'),
            'updated_at' => $now,
        ];

        $existingValues = $id > 0 ? array_map('intval', $db->fetchFirstColumn('SELECT id FROM product_option_value WHERE option_id = ?', [$id])) : [];
        $rows = [];
        foreach ($values as $position => $value) {
            if (!is_array($value)) {
                throw new InformationException('Invalid value');
            }
            $valueId = (int) ($value['id'] ?? 0);
            if ($valueId > 0 && !in_array($valueId, $existingValues, true)) {
                throw new InformationException('Value not found', [], 404);
            }
            $valueRow = [
                'id' => $valueId,
                'title' => in_array($type, ['yesno', 'quantity'], true) && trim((string) ($value['title'] ?? '')) === ''
                    ? $row['title']
                    : self::text($value['title'] ?? '', 'Value name', true),
                'code' => self::code($value['code'] ?? ''),
                'hidden' => self::bool($value['hidden'] ?? false) ? 1 : 0,
                'position' => $position,
                'updated_at' => $now,
            ];
            foreach (self::PRICE_KEYS as $key) {
                $valueRow[$key . '_price'] = self::money($value[$key . '_price'] ?? 0);
                $valueRow[$key . '_setup_price'] = self::money($value[$key . '_setup_price'] ?? 0);
            }
            $rows[] = $valueRow;
        }

        return $db->transactional(function (Connection $db) use ($id, $row, $rows, $existingValues, $now): int {
            if ($id > 0) {
                $db->update('product_option', $row, ['id' => $id]);
            } else {
                $row['created_at'] = $now;
                $db->insert('product_option', $row);
                $id = (int) $db->lastInsertId();
            }
            $kept = [];
            foreach ($rows as $valueRow) {
                $valueId = $valueRow['id'];
                unset($valueRow['id']);
                if ($valueId > 0) {
                    $db->update('product_option_value', $valueRow, ['id' => $valueId]);
                    $kept[] = $valueId;
                } else {
                    $valueRow['option_id'] = $id;
                    $valueRow['created_at'] = $now;
                    $db->insert('product_option_value', $valueRow);
                }
            }
            $gone = array_diff($existingValues, $kept);
            if ($gone !== []) {
                $db->executeStatement('DELETE FROM product_option_value WHERE id IN (' . implode(',', array_map('intval', $gone)) . ')');
            }

            return $id;
        });
    }

    /** Deletes an option with its values. Orders keep the option lines they have. */
    public static function deleteOption(\Pimple\Container $di, int $id): void
    {
        $db = self::db($di);
        if ($db->fetchOne('SELECT id FROM product_option WHERE id = ?', [$id]) === false) {
            throw new InformationException('Option not found', [], 404);
        }
        $db->transactional(function (Connection $db) use ($id): void {
            $db->delete('product_option_value', ['option_id' => $id]);
            $db->delete('product_option', ['id' => $id]);
        });
    }

    // ----- a product's options and prices ------------------------------------------------------

    /**
     * The options of a product, in group and position order, each with its values. Without
     * $withHidden, hidden options and values are left out (the order form).
     *
     * @return list<array<string, mixed>>
     */
    public static function forProduct(\Pimple\Container $di, int $productId, bool $withHidden = false): array
    {
        if (!self::ready($di)) {
            return [];
        }
        $db = self::db($di);
        $groups = array_map('intval', $db->fetchFirstColumn('SELECT group_id FROM product_option_group_product WHERE product_id = ? ORDER BY group_id', [$productId]));

        return $groups === [] ? [] : self::optionsOf($db, $groups, $withHidden);
    }

    /**
     * Checks a choice for a product and prices it for a billing period (a period code such as
     * "1M", or "once" for a one-time or free product). Options left out take their default: the
     * first offered value, "no", or the minimum quantity. Hidden options and values are refused
     * unless $keep holds the same choice (a service that already has it) or $staff is set.
     *
     * @param array<int|string, mixed> $choice option id => value id, 0/1 or a quantity
     * @param array<int|string, mixed> $keep   the choice the service has now
     *
     * @return array{choice: array<int, int>, lines: list<array<string, mixed>>, price: float, setup_price: float}
     */
    public static function resolve(\Pimple\Container $di, int $productId, array $choice, string $period, array $keep = [], bool $staff = false): array
    {
        $key = $period === 'once' ? 'once' : (self::PERIOD_KEYS[$period] ?? null);
        if ($key === null) {
            throw new InformationException('Unknown billing period :period', [':period' => $period]);
        }
        $result = ['choice' => [], 'lines' => [], 'price' => 0.0, 'setup_price' => 0.0];
        $options = self::forProduct($di, $productId, true);
        $known = array_column($options, null, 'id');
        foreach (array_keys($choice) as $optionId) {
            if (!isset($known[(int) $optionId])) {
                throw new InformationException('This option is not available for this product.');
            }
        }

        foreach ($options as $option) {
            $optionId = (int) $option['id'];
            $given = array_key_exists($optionId, $choice) ? $choice[$optionId] : (array_key_exists((string) $optionId, $choice) ? $choice[(string) $optionId] : null);
            $kept = $keep[$optionId] ?? ($keep[(string) $optionId] ?? null);
            // A hidden choice stays only as the service has it (staff may set any); a yes/no or
            // quantity option can always be switched off.
            $allowedHidden = fn (mixed $value): bool => $staff || ($kept !== null && (int) $kept === (int) $value)
                || (in_array($option['type'], ['yesno', 'quantity'], true) && (int) $value === 0 && (int) $option['qty_min'] === 0);
            if ($option['hidden'] && $given === null && $kept === null) {
                continue;
            }
            if ($option['hidden'] && !$allowedHidden($given ?? $kept)) {
                if ($given !== null) {
                    throw new InformationException(':option is no longer offered.', [':option' => $option['title']]);
                }

                continue;
            }
            $given ??= $kept;
            $values = array_column($option['values'], null, 'id');

            if ($option['type'] === 'select' || $option['type'] === 'radio') {
                if ($given === null || $given === '') {
                    $first = array_values(array_filter($option['values'], fn (array $v): bool => !$v['hidden']))[0] ?? null;
                    if ($first === null) {
                        continue;
                    }
                    $given = $first['id'];
                }
                $value = $values[(int) $given] ?? null;
                if ($value === null || (!is_int($given) && !ctype_digit((string) $given))) {
                    throw new InformationException('Choose a value for :option.', [':option' => $option['title']]);
                }
                if ($value['hidden'] && !$allowedHidden($given)) {
                    throw new InformationException(':value is no longer offered.', [':value' => $value['title']]);
                }
                $qty = 1;
                $chosen = (int) $value['id'];
            } else {
                $value = array_values($values)[0] ?? null;
                if ($value === null) {
                    continue;
                }
                if ($option['type'] === 'yesno') {
                    $qty = self::bool($given ?? false) ? 1 : 0;
                } else {
                    $qty = $given === null || $given === '' ? (int) $option['qty_min'] : $given;
                    if (!is_int($qty) && !(is_string($qty) && ctype_digit($qty))) {
                        throw new InformationException('Enter a whole number for :option.', [':option' => $option['title']]);
                    }
                    $qty = (int) $qty;
                    $max = (int) $option['qty_max'] ?: self::MAX_QUANTITY;
                    if ($qty < (int) $option['qty_min'] || $qty > $max) {
                        throw new InformationException(':option must be between :min and :max.', [':option' => $option['title'], ':min' => (int) $option['qty_min'], ':max' => $max]);
                    }
                }
                $chosen = $qty;
            }

            $result['choice'][$optionId] = $chosen;
            if ($qty === 0) {
                continue;
            }
            $price = round((float) $value['prices'][$key]['price'] * $qty, 2);
            $setup = round((float) $value['prices'][$key]['setup_price'] * $qty, 2);
            $result['lines'][] = [
                'option_id' => $optionId,
                'code' => $option['code'],
                'title' => $option['title'],
                'type' => $option['type'],
                'value_id' => (int) $value['id'],
                'value_code' => $value['code'],
                'value' => $value['title'],
                'quantity' => $qty,
                'unit' => $option['unit'],
                'price' => $price,
                'setup_price' => $setup,
            ];
            $result['price'] += $price;
            $result['setup_price'] += $setup;
        }
        $result['price'] = round($result['price'], 2);
        $result['setup_price'] = round($result['setup_price'], 2);

        return $result;
    }

    /**
     * The chosen options in a few words for an invoice line or a title: "RAM: 4 GB, Extra IPs: 2,
     * Daily backups".
     *
     * @param array<mixed> $lines resolve()'s lines (an order's `option_lines`, from its stored config)
     */
    public static function summary(array $lines): string
    {
        $parts = [];
        foreach ($lines as $line) {
            if (!is_array($line) || !isset($line['title'])) {
                continue;
            }
            $parts[] = match ($line['type'] ?? '') {
                'yesno' => (string) $line['title'],
                'quantity' => $line['title'] . ': ' . (int) ($line['quantity'] ?? 0) . (($line['unit'] ?? '') !== '' ? ' ' . $line['unit'] : ''),
                default => $line['title'] . ': ' . ($line['value'] ?? ''),
            };
        }

        return implode(', ', $parts);
    }

    /**
     * A title with the options' summary, cut to $max characters: "VPS (RAM: 4 GB, Extra IPs: 2)".
     *
     * @param array<mixed> $lines
     */
    public static function titleWith(string $title, array $lines, int $max = 255): string
    {
        $summary = self::summary($lines);
        if ($summary === '') {
            return mb_substr($title, 0, $max);
        }
        $full = $title . ' (' . $summary . ')';

        return mb_strlen($full) <= $max ? $full : rtrim(mb_substr($full, 0, $max - 2)) . '…)';
    }

    // ----- promotions --------------------------------------------------------------------------

    /** Whether a promotion's discount also covers the options' price (off by default). */
    public static function promoCoversOptions(\Pimple\Container $di, int $promoId): bool
    {
        if (!self::ready($di)) {
            return false;
        }

        return (int) self::db($di)->fetchOne('SELECT include_options FROM promo_option WHERE promo_id = ?', [$promoId]) === 1;
    }

    public static function setPromoCoversOptions(\Pimple\Container $di, int $promoId, bool $on): void
    {
        $db = self::db($di);
        $db->delete('promo_option', ['promo_id' => $promoId]);
        if ($on) {
            $db->insert('promo_option', ['promo_id' => $promoId, 'include_options' => 1, 'updated_at' => date('Y-m-d H:i:s')]);
        }
    }

    // ----- helpers -----------------------------------------------------------------------------

    /**
     * @param list<int> $groupIds
     *
     * @return list<array<string, mixed>>
     */
    private static function optionsOf(Connection $db, array $groupIds, bool $withHidden): array
    {
        $groupIds = array_map('intval', $groupIds);
        $order = array_flip($groupIds);
        $options = $db->fetchAllAssociative('SELECT * FROM product_option WHERE group_id IN (' . implode(',', $groupIds) . ')' . ($withHidden ? '' : ' AND hidden = 0'));
        usort($options, fn (array $a, array $b): int => [$order[(int) $a['group_id']], (int) $a['position'], (int) $a['id']] <=> [$order[(int) $b['group_id']], (int) $b['position'], (int) $b['id']]);
        if ($options === []) {
            return [];
        }
        $values = [];
        foreach ($db->fetchAllAssociative('SELECT * FROM product_option_value WHERE option_id IN (' . implode(',', array_map(fn (array $o): int => (int) $o['id'], $options)) . ')' . ($withHidden ? '' : ' AND hidden = 0') . ' ORDER BY position, id') as $row) {
            $prices = [];
            foreach (self::PRICE_KEYS as $key) {
                $prices[$key] = ['price' => (float) $row[$key . '_price'], 'setup_price' => (float) $row[$key . '_setup_price']];
            }
            $values[(int) $row['option_id']][] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'code' => (string) $row['code'],
                'hidden' => (bool) $row['hidden'],
                'prices' => $prices,
            ];
        }
        $result = [];
        foreach ($options as $row) {
            $optionValues = $values[(int) $row['id']] ?? [];
            if (!$withHidden && $optionValues === []) {
                continue;
            }
            $result[] = [
                'id' => (int) $row['id'],
                'group_id' => (int) $row['group_id'],
                'title' => (string) $row['title'],
                'code' => (string) $row['code'],
                'type' => (string) $row['type'],
                'qty_min' => (int) $row['qty_min'],
                'qty_max' => (int) $row['qty_max'],
                'unit' => (string) $row['unit'],
                'hidden' => (bool) $row['hidden'],
                'position' => (int) $row['position'],
                'values' => $optionValues,
            ];
        }

        return $result;
    }

    private static function text(mixed $value, string $what, bool $required, int $max = self::TITLE_MAX): string
    {
        if ($value !== null && !is_scalar($value)) {
            throw new InformationException(':what must be text.', [':what' => __trans($what)]);
        }
        $text = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
        if ($required && $text === '') {
            throw new InformationException(':what is required.', [':what' => __trans($what)]);
        }
        if (mb_strlen($text) > $max) {
            throw new InformationException(':what can be at most :max characters.', [':what' => __trans($what), ':max' => $max]);
        }

        return $text;
    }

    /** A key for service modules: letters, digits, dot, dash and underscore ("" for none). */
    private static function code(mixed $value): string
    {
        $code = trim(is_scalar($value) ? (string) $value : '');
        if ($code !== '' && preg_match('/\A[A-Za-z0-9._-]{1,' . self::CODE_MAX . '}\z/', $code) !== 1) {
            throw new InformationException('A key may only have letters, digits, dots, dashes and underscores (at most :max).', [':max' => self::CODE_MAX]);
        }

        return $code;
    }

    private static function int(mixed $value, string $what): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (!is_int($value) && !(is_string($value) && ctype_digit(trim($value)))) {
            throw new InformationException(':what must be a whole number, 0 or more.', [':what' => __trans($what)]);
        }
        $int = (int) $value;
        if ($int > self::MAX_QUANTITY) {
            throw new InformationException(':what can be at most :max.', [':what' => __trans($what), ':max' => self::MAX_QUANTITY]);
        }

        return $int;
    }

    private static function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }
        if (!is_numeric($value) || (float) $value < 0 || (float) $value > 99999999) {
            throw new InformationException('Prices must be amounts of 0 or more.');
        }

        return number_format(round((float) $value, 2), 2, '.', '');
    }

    private static function bool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'on', 'yes', 'true'], true);
    }

    /**
     * Whether the tables exist: an install updated to this version has them only once staff open
     * the options (ensure()); until then no product has options, never a broken page.
     */
    private static function ready(\Pimple\Container $di): bool
    {
        /** @var \WeakMap<\Pimple\Container, true>|null $known */
        static $known = null;
        $known ??= new \WeakMap();
        if (!isset($known[$di])) {
            try {
                self::db($di)->fetchOne('SELECT 1 FROM product_option_group_product WHERE 1 = 0');
            } catch (\Throwable) {
                // No table yet (or no database to ask): no options rather than a broken page.
                return false;
            }
            $known[$di] = true;
        }

        return true;
    }

    /**
     * The tables, made on first use (Clientfence\Service::ensureTables()), once a request. The API
     * calls it before staff work on options.
     */
    public static function ensure(\Pimple\Container $di): void
    {
        /** @var \WeakMap<\Pimple\Container, true>|null $ready */
        static $ready = null;
        $ready ??= new \WeakMap();
        if (!isset($ready[$di])) {
            \ClientFence\Mod\Clientfence\Service::ensureTables($di, true);
            $ready[$di] = true;
        }
    }

    private static function db(\Pimple\Container $di): Connection
    {
        return $di['em']->getConnection();
    }
}
