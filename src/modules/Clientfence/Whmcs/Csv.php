<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Whmcs;

use Doctrine\DBAL\Connection;
use League\Csv\EscapeFormula;
use League\Csv\Writer;

/**
 * ClientFence (CF-213): the import's CSV downloads, so a move can be audited (Controller/Admin.php
 * sends them; the Migration tab links them).
 *
 * plan(): before the import, from the saved plan (Import::plan()): one row per area and add-on
 * module with what the source holds and what the import does with it (areas: NOT_IMPORTED, PARTLY), then the server checks
 * (Preflight.php). audit(): after it, the check against the source (Run::verify()), every warning
 * and one row per id map row (Map.php: target 0 is a row that was not imported).
 *
 * One file holds several blocks: each starts with its own header row, every row starts with its
 * section, and a blank line comes between them. Headers and the names the page translates are
 * translated; the data is as the import keeps it. Nothing from the import settings (database
 * details, password, keys, token) is written. Large tables are read in chunks of CHUNK rows
 * (by key, not OFFSET), so the file streams at any size.
 */
final class Csv
{
    public const CHUNK = 1000;

    /**
     * The plan's areas (Inventory::AREAS keys) the import does not read: WHMCS's order records (each service and
     * domain becomes a ClientFence order of its own) and quotes. Every other area is imported.
     */
    public const NOT_IMPORTED = ['orders', 'quotes'];

    /** Areas the import reads only part of (the row's Detail says which part): no count of what it takes is made. */
    public const PARTLY = ['users', 'social_logins', 'custom_fields', 'custom_values', 'cancel_requests', 'kb_categories', 'kb_articles', 'announcements', 'emails'];

    /** "clientfence-import-plan-whmcs-2026-11-19.csv"; $kind is "plan" or "audit". */
    public static function name(string $kind, string $system): string
    {
        return 'clientfence-import-' . $kind . '-' . strtolower($system) . '-' . date('Y-m-d') . '.csv';
    }

    /**
     * @param array<string, mixed> $plan Import::state(PLAN)
     * @param resource             $out
     */
    public static function plan(array $plan, string $system, $out): void
    {
        $csv = self::writer($out);
        $csv->insertOne([__trans('Section'), __trans('Source system'), __trans('Area'), __trans('Handling'), __trans('Found in the source'), __trans('Will be imported'), __trans('Archived only'), __trans('Settings only'), __trans('Detail')]);
        foreach ($plan['areas'] ?? [] as $key => $area) {
            $rows = (int) $area['rows'];
            [$handling, $will] = match (true) {
                in_array($key, self::NOT_IMPORTED, true) => [__trans('Not imported'), 0],
                in_array($key, self::PARTLY, true) => [__trans('Partly imported'), ''],
                // Single rows the import passes over in an imported area are named in the warnings after it.
                default => [__trans('Imported'), $rows],
            };
            $detail = [];
            foreach ($area['by_status'] ?? [] as $status => $count) {
                $detail[] = $status . ' ' . $count;
            }
            $note = match ($key) {
                'orders' => __trans('Its services and domains become ClientFence orders.'),
                'users' => __trans('Only logins linked to a client.'),
                'social_logins' => __trans('Only Google sign-in links, the first of each login.'),
                'custom_fields' => __trans('Only client and product custom fields.'),
                'custom_values' => __trans('Only the answers to client and product custom fields.'),
                'cancel_requests' => __trans('Only the newest request of each service.'),
                'kb_categories', 'kb_articles', 'announcements' => __trans('Not their translations.'),
                'emails' => __trans('Only emails that were sent.'),
                default => '',
            };
            $csv->insertOne([__trans('Area'), $system, __trans((string) $area['label']), $handling, $rows, $will, 0, 0, trim($note . ' ' . implode(', ', $detail))]);
        }
        $modules = ['import' => __trans('Imported'), 'archive' => __trans('Archived, not imported'), 'settings' => __trans('Settings only')];
        foreach ($plan['modules'] ?? [] as $module) {
            $rows = (int) $module['rows'];
            $tables = array_map(static fn (array $t): string => $t['table'] . ' (' . $t['rows'] . ')', $module['tables'] ?? []);
            $csv->insertOne([
                __trans('Add-on module'), $system, (string) $module['label'], $modules[$module['handling']] ?? (string) $module['handling'], $rows,
                $module['handling'] === 'import' ? $rows : 0,
                $module['handling'] === 'archive' ? $rows : 0,
                $module['handling'] === 'settings' ? $rows : 0,
                implode(', ', $tables),
            ]);
        }

        $csv->insertOne(['']);
        $csv->insertOne([__trans('Section'), __trans('Check'), __trans('Result'), __trans('Detail'), __trans('Fix')]);
        foreach ($plan['preflight'] ?? [] as $check) {
            $csv->insertOne([__trans('Server check'), (string) $check['check'], $check['ok'] ? __trans('Pass') : __trans('Fail'), (string) $check['detail'], (string) $check['fix']]);
        }
    }

    /**
     * @param array<string, mixed>|null $verify Run::verify(), null when the source could not be read
     * @param resource                  $out
     */
    public static function audit(Connection $db, string $system, ?array $verify, $out, int $chunk = self::CHUNK): void
    {
        $csv = self::writer($out);
        $steps = array_map(static fn (array $s): string => $s[0], Run::steps());

        $csv->insertOne([__trans('Section'), __trans('Area'), __trans('In the source'), __trans('Imported'), __trans('Difference')]);
        if ($verify === null) {
            $csv->insertOne([__trans('Check against the source'), __trans('The source could not be read, so this check is left out.')]);
        }
        foreach ($verify['rows'] ?? [] as $row) {
            $csv->insertOne([__trans('Check against the source'), __trans((string) $row['label']), (int) $row['whmcs'], (int) $row['imported'], (int) $row['imported'] - (int) $row['whmcs']]);
        }

        $csv->insertOne(['']);
        $csv->insertOne([__trans('Section'), __trans('Step'), __trans('Warning')]);
        $after = 0;
        do {
            $rows = $db->fetchAllAssociative('SELECT id, step, message FROM ' . Run::WARNINGS . ' WHERE source = ? AND id > ? ORDER BY id LIMIT ' . $chunk, [Map::SOURCE, $after]);
            foreach ($rows as $row) {
                $step = (string) $row['step'];
                // Earlier imports wrote a client's full PayPal agreement ID (it charges them) into a warning: only its end.
                $message = (string) preg_replace_callback('/\bB-[A-Z0-9]{8,}\b/', static fn (array $m): string => '…' . substr($m[0], -4), (string) $row['message']);
                $csv->insertOne([__trans('Warning'), isset($steps[$step]) ? __trans($steps[$step]) : $step, $message]);
                $after = (int) $row['id'];
            }
        } while (count($rows) === $chunk);

        $csv->insertOne(['']);
        $csv->insertOne([__trans('Section'), __trans('Source system'), __trans('Entity'), __trans('Source ID'), __trans('ClientFence ID'), __trans('Result')]);
        if ($system === 'Upmind') {
            // Upmind/Snapshot.php numbers Upmind's UUIDs and drops its lookup after the snapshot.
            $csv->insertOne([__trans('Record'), $system, '', __trans('Source IDs are the numbers the import gave Upmind\'s records, not Upmind IDs.')]);
        }
        [$entity, $id] = ['', ''];
        do {
            $rows = $db->fetchAllAssociative(
                // archive (file sizes) and notice (warnings already given) are the import's own bookkeeping, not records.
                'SELECT entity, source_id, target_id FROM ' . Map::TABLE . " WHERE source = ? AND entity NOT IN ('archive', 'notice') AND (entity > ? OR (entity = ? AND source_id > ?)) ORDER BY entity, source_id LIMIT " . $chunk,
                [Map::SOURCE, $entity, $entity, $id]
            );
            foreach ($rows as $row) {
                [$entity, $id, $target] = [(string) $row['entity'], (string) $row['source_id'], (int) $row['target_id']];
                $csv->insertOne([__trans('Record'), $system, $entity, $id, $target, $target > 0 ? __trans('Imported') : __trans('Not imported')]);
            }
        } while (count($rows) === $chunk);
    }

    /** @param resource $out */
    private static function writer($out): Writer
    {
        $csv = Writer::from($out);
        // RFC 4180 quoting only: a backslash before a quote would otherwise leave that quote undoubled.
        $csv->setEscape('');
        // Spreadsheet formulas (= + - @, tab, CR) start with an apostrophe; numbers stay numbers (a negative difference).
        $escape = new EscapeFormula();
        $csv->addFormatter(static function (array $record) use ($escape): array {
            $escaped = $escape->escapeRecord($record);
            foreach ($record as $i => $value) {
                if (is_string($value) && is_numeric($value)) {
                    $escaped[$i] = $value;
                }
            }

            return $escaped;
        });

        return $csv;
    }
}
