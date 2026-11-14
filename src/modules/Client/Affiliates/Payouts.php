<?php

declare(strict_types=1);

namespace ClientFence\Mod\Client\Affiliates;

use ClientFence\InformationException;
use ClientFence\PaginationOptions;
use ClientFence\Tools;
use Doctrine\DBAL\Connection;

/**
 * ClientFence: affiliate payouts (Affiliates.php). The affiliate asks for a fixed amount of its available
 * balance (one open request at a time, at least the minimum payout), with free-text payout details; the amount
 * is set aside until staff pay it as account credit, record it as paid outside ClientFence, or decline it.
 */
final class Payouts
{
    public const METHODS = ['credit', 'outside'];

    /** The account owner asks for a payout; returns its id. */
    public static function request(\Pimple\Container $di, int $clientId, mixed $amount, mixed $details): int
    {
        $db = $di['dbal'];
        $affiliate = Affiliates::forClient($db, $clientId);
        if ($affiliate === false || $affiliate['status'] !== 'active') {
            throw new InformationException('Your affiliate account is not active.');
        }
        if (!is_numeric($amount) || round((float) $amount, 2) <= 0) {
            throw new InformationException('Enter the amount to pay out.');
        }
        $details = trim(is_string($details) ? $details : '');
        if ($details === '') {
            throw new InformationException('Tell us how to pay you (payout details).');
        }
        if (mb_strlen($details) > 2000) {
            throw new InformationException('The payout details are too long.');
        }
        $amount = round((float) $amount, 2);
        $minimum = Affiliates::fromDefault($db, Affiliates::settings($di)['minimum'], (string) $affiliate['currency']);

        $id = Tools::withLock($di, 'fb:affiliate:' . $affiliate['id'], static function () use ($di, $db, $affiliate, $clientId, $amount, $details, $minimum): int {
            if ($db->fetchOne("SELECT 1 FROM client_affiliate_payout WHERE affiliate_id = ? AND status = 'requested'", [$affiliate['id']]) !== false) {
                throw new InformationException('Your payout request is already waiting.');
            }
            if ($amount < $minimum) {
                throw new InformationException('The minimum payout is :amount.', [':amount' => number_format($minimum, 2) . ' ' . $affiliate['currency']]);
            }
            $available = Affiliates::balances($db, (int) $affiliate['id'])['available'];
            if ($amount > $available) {
                throw new InformationException('You can ask for at most :amount.', [':amount' => number_format(max(0.0, $available), 2) . ' ' . $affiliate['currency']]);
            }
            $now = date('Y-m-d H:i:s');
            $db->insert('client_affiliate_payout', ['affiliate_id' => $affiliate['id'], 'amount' => $amount, 'currency' => $affiliate['currency'], 'status' => 'requested', 'details' => $details, 'created_at' => $now, 'updated_at' => $now]);
            $id = (int) $db->lastInsertId();
            Affiliates::log($di, $clientId, sprintf('Affiliate payout #%d requested: %s %s', $id, number_format($amount, 2), $affiliate['currency']));

            return $id;
        });
        Emails::payoutRequested($di, $id);

        return $id;
    }

    /**
     * Staff pay a request: `credit` adds it to the client's account credit (under the client's credit lock, the
     * credit and the payout row in one transaction), `outside` records a payment made elsewhere.
     */
    public static function pay(\Pimple\Container $di, int $payoutId, string $method, int $adminId): void
    {
        if (!in_array($method, self::METHODS, true)) {
            throw new InformationException('Choose how the payout was paid.');
        }
        $payout = self::find($di['dbal'], $payoutId);
        $work = static fn (): mixed => Tools::withLock($di, 'fb:affiliate:' . $payout['affiliate_id'], static function () use ($di, $payoutId, $method, $adminId, $payout): void {
            $db = $di['dbal'];
            if ($db->fetchOne('SELECT status FROM client_affiliate_payout WHERE id = ?', [$payoutId]) !== 'requested') {
                throw new InformationException('This payout request was already handled.');
            }
            // The request is set aside in the available balance: a reversal since then may have used it up.
            if (Affiliates::balances($db, (int) $payout['affiliate_id'])['available'] < 0) {
                throw new InformationException('The affiliate balance no longer covers this payout. Decline it or add a manual entry first.');
            }
            $db->transactional(static function (Connection $db) use ($payout, $payoutId, $method, $adminId): void {
                $now = date('Y-m-d H:i:s');
                if ($method === 'credit') {
                    $db->insert('client_balance', ['client_id' => $payout['client_id'], 'type' => 'affiliate', 'rel_id' => (string) $payoutId, 'description' => 'Affiliate payout #' . $payoutId, 'amount' => $payout['amount'], 'created_at' => $now, 'updated_at' => $now]);
                }
                $db->insert('client_affiliate_entry', [
                    'affiliate_id' => $payout['affiliate_id'], 'type' => 'payout', 'status' => 'cleared', 'amount' => -(float) $payout['amount'], 'currency' => $payout['currency'],
                    'payout_id' => $payoutId, 'admin_id' => $adminId, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $db->update('client_affiliate_payout', ['status' => 'paid', 'method' => $method, 'admin_id' => $adminId, 'processed_at' => $now, 'updated_at' => $now], ['id' => $payoutId]);
            });
            Affiliates::log($di, (int) $payout['client_id'], sprintf('Affiliate payout #%d paid (%s): %s %s', $payoutId, $method === 'credit' ? 'account credit' : 'outside ClientFence', number_format((float) $payout['amount'], 2), $payout['currency']), $adminId);
        });
        $method === 'credit' ? Tools::withLock($di, 'fb:credit_payment:client:' . $payout['client_id'], $work) : $work();
        Emails::payoutHandled($di, $payoutId);
    }

    /** Staff decline a request: its amount is available again. The `note` goes to the affiliate's email. */
    public static function decline(\Pimple\Container $di, int $payoutId, int $adminId, string $note = ''): void
    {
        $payout = self::find($di['dbal'], $payoutId);
        Tools::withLock($di, 'fb:affiliate:' . $payout['affiliate_id'], static function () use ($di, $payoutId, $adminId, $payout): void {
            $now = date('Y-m-d H:i:s');
            if ($di['dbal']->executeStatement("UPDATE client_affiliate_payout SET status = 'declined', admin_id = ?, processed_at = ?, updated_at = ? WHERE id = ? AND status = 'requested'", [$adminId, $now, $now, $payoutId]) === 0) {
                throw new InformationException('This payout request was already handled.');
            }
            Affiliates::log($di, (int) $payout['client_id'], sprintf('Affiliate payout #%d declined', $payoutId), $adminId);
        });
        Emails::payoutHandled($di, $payoutId, mb_substr($note, 0, 2000));
    }

    /** Staff: payout requests with the affiliate's client (`status`, `client_id` filter). */
    public static function adminList(\Pimple\Container $di, array $data): array
    {
        $where = [];
        $params = [];
        if (in_array($data['status'] ?? null, ['requested', 'paid', 'declined'], true)) {
            $where[] = 'p.status = ?';
            $params[] = $data['status'];
        }
        if (!empty($data['client_id'])) {
            $where[] = 'a.client_id = ?';
            $params[] = (int) $data['client_id'];
        }

        return $di['pager']->getPaginatedResultSet(
            'SELECT p.*, a.client_id, a.code, c.first_name, c.last_name, c.company, c.email FROM client_affiliate_payout p
             JOIN client_affiliate a ON a.id = p.affiliate_id JOIN client c ON c.id = a.client_id'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY p.id DESC',
            $params,
            PaginationOptions::fromArray($data),
        );
    }

    /** @return array<string, mixed> the payout with its affiliate's client_id */
    private static function find(Connection $db, int $payoutId): array
    {
        return $db->fetchAssociative('SELECT p.*, a.client_id FROM client_affiliate_payout p JOIN client_affiliate a ON a.id = p.affiliate_id WHERE p.id = ?', [$payoutId])
            ?: throw new InformationException('Payout request not found');
    }
}
