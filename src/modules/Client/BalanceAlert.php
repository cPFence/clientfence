<?php

declare(strict_types=1);

namespace ClientFence\Mod\Client;

use ClientFence\InformationException;
use Doctrine\DBAL\Connection;

/**
 * ClientFence: the low balance alert (CF-128). While staff allow it (Settings > Invoice, Add Funds Settings:
 * funds_balance_alert, off by default), a client sets an amount on the Wallet page and gets one email (and a
 * bell notice) when the balance falls below it.
 *
 * The cron (run()) reads the opted-in active clients in one query: below the amount and not yet alerted ->
 * email, alerted_at set; back at or above it -> alerted_at cleared, so the next drop emails again. Saving the
 * alert re-arms it (already below: one email at the next run), and so does a change of the client's currency.
 * The balance is the one the Wallet shows (credit set aside for part-paid invoices left out). This only reads
 * credit: no balance is changed here.
 */
final class BalanceAlert
{
    public const TABLE = 'client_balance_alert';
    public const SETTING = 'funds_balance_alert';
    public const EMAIL = 'mod_client_low_balance';
    public const MAX = 1000000000;

    /** Whether staff let clients set the alert. */
    public static function on(\Pimple\Container $di): bool
    {
        return (bool) $di['mod_service']('system')->getParamValue(self::SETTING, false);
    }

    /** The client's alert amount, or null when the client has none. */
    public static function threshold(\Pimple\Container $di, int $clientId): ?float
    {
        $threshold = self::db($di)->fetchOne('SELECT threshold FROM ' . self::TABLE . ' WHERE client_id = ?', [$clientId]);

        return $threshold === false ? null : round((float) $threshold, 2);
    }

    /** The client turns the alert off, or on with an amount in the account's currency. */
    public static function save(\Pimple\Container $di, int $clientId, bool $enabled, mixed $amount): void
    {
        $db = self::db($di);
        if (!$enabled) {
            $db->delete(self::TABLE, ['client_id' => $clientId]);

            return;
        }
        $threshold = is_numeric($amount) ? round((float) $amount, 2) : 0.0;
        if ($threshold <= 0 || $threshold >= self::MAX) {
            throw new InformationException('Enter an amount above 0 and below :max.', [':max' => number_format(self::MAX)]);
        }
        $currency = $db->fetchOne('SELECT currency FROM client WHERE id = ?', [$clientId]);
        if ($currency === false) {
            throw new InformationException('Client not found');
        }
        // A fresh row on every save: alerted_at is cleared, so a balance already below gets one email.
        $db->transactional(static function (Connection $db) use ($clientId, $threshold, $currency): void {
            $db->delete(self::TABLE, ['client_id' => $clientId]);
            $db->insert(self::TABLE, [
                'client_id' => $clientId, 'threshold' => number_format($threshold, 2, '.', ''), 'currency' => $currency ?: null,
                'alerted_at' => null, 'updated_at' => date('Y-m-d H:i:s'),
            ]);
        });
    }

    /** The cron: emails the clients whose balance fell below their amount, and re-arms those back above it. */
    public static function run(\Pimple\Container $di): void
    {
        if (!self::on($di)) {
            return;
        }
        $db = self::db($di);
        $rows = $db->fetchAllAssociative(
            'SELECT a.client_id, a.threshold, a.currency AS alert_currency, a.alerted_at, c.currency,
                (SELECT COALESCE(SUM(b.amount), 0) FROM client_balance b WHERE b.client_id = a.client_id) AS balance,
                EXISTS (SELECT 1 FROM invoice i WHERE i.client_id = a.client_id AND i.status = ?) AS unpaid
             FROM ' . self::TABLE . ' a JOIN client c ON c.id = a.client_id WHERE c.status IN (?, ?)',
            [\Model_Invoice::STATUS_UNPAID, ...\Model_Client::OPEN],
        );
        foreach ($rows as $row) {
            $clientId = (int) $row['client_id'];
            $alerted = $row['alerted_at'] !== null;
            if ((string) $row['alert_currency'] !== (string) $row['currency']) {
                // The amount was set in another currency: the alert starts over in the new one.
                $db->update(self::TABLE, ['currency' => $row['currency'], 'alerted_at' => null], ['client_id' => $clientId]);
                $alerted = false;
            }
            // Only an unpaid invoice can hold credit aside; the others' balance is the plain sum.
            $balance = $row['unpaid'] ? $di['mod_service']('Invoice')->getFreeCredit($clientId) : round((float) $row['balance'], 2);
            $threshold = round((float) $row['threshold'], 2);
            if ($balance >= $threshold) {
                if ($alerted) {
                    $db->update(self::TABLE, ['alerted_at' => null], ['client_id' => $clientId]);
                }
            } elseif (!$alerted) {
                $db->update(self::TABLE, ['alerted_at' => date('Y-m-d H:i:s')], ['client_id' => $clientId]);
                self::send($di, $clientId, $balance, $threshold, (string) $row['currency']);
            }
        }
    }

    /** A deleted or anonymized client leaves no alert (Users::clientDeleted). */
    public static function forgetClient(Connection $db, int $clientId): void
    {
        try {
            $db->delete(self::TABLE, ['client_id' => $clientId]);
        } catch (\Throwable) {
            // No table yet: nothing to remove.
        }
    }

    private static function send(\Pimple\Container $di, int $clientId, float $balance, float $threshold, string $currency): void
    {
        try {
            $di['mod_service']('email')->sendTemplate(['to_client' => $clientId, 'code' => self::EMAIL, 'balance' => $balance, 'threshold' => $threshold, 'currency' => $currency]);
        } catch (\Throwable $e) {
            $di['logger']->warning('Client #%s: low balance email not sent: %s', $clientId, $e->getMessage());
        }
        $money = static fn (float $amount): string => trim(number_format($amount, 2) . ' ' . $currency);
        \ClientFence\Mod\Clientfence\Notifications::client($di, $clientId, 'balance_low', ['balance' => $money($balance), 'threshold' => $money($threshold)], 'client/balance');
    }

    private static function db(\Pimple\Container $di): Connection
    {
        return $di['em']->getConnection();
    }
}
