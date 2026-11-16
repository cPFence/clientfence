<?php

declare(strict_types=1);

namespace ClientFence\Mod\Client;

use ClientFence\InformationException;
use ClientFence\Mod\Order\Holds;
use Doctrine\DBAL\Connection;

/**
 * ClientFence: the trust score on the admin client page (owner's formula, 2026-11-16), out of 100. Staff see it;
 * nothing acts on it. Pays on time 40 (paid invoices due in the last 12 months, the share paid by the due date; none:
 * 20), client since 20 (a point a full month), active services 15 (5 each), total paid 15 (default currency: 15 at
 * 2,000, 11 at 1,000, 7 at 500, 3 at 100), verified 10 (email 5, approved ID document 5); minus 20 when the fraud check
 * ever held an order, 5 for each refunded invoice and reversed payment; never below 0. The page words the parts.
 */
final class TrustScore
{
    /**
     * @return array{total: int, band: string, parts: array<string, int>, deductions: array<string, int>, counts: array<string, int|float|bool>}
     */
    public static function get(Connection $db, int $clientId): array
    {
        $client = $db->fetchAssociative(
            "SELECT c.created_at, c.email_approved,
                    (SELECT COUNT(*) FROM client_order o WHERE o.client_id = c.id AND o.status = 'active' AND (o.group_master = 1 OR o.group_id IS NULL)) AS active,
                    (SELECT COUNT(*) FROM client_id_document d WHERE d.client_id = c.id AND d.status = ?) AS id_approved,
                    (SELECT COUNT(*) FROM client_order_hold h WHERE h.client_id = c.id AND h.reason = ?) AS fraud,
                    (SELECT COUNT(*) FROM `transaction` t JOIN invoice ti ON ti.id = t.invoice_id WHERE ti.client_id = c.id AND t.status = 'reversed') AS reversed
               FROM client c WHERE c.id = ?",
            [IdDocuments::APPROVED, Holds::REASON_FRAUD, $clientId]
        );
        if ($client === false) {
            throw new InformationException('Client not found');
        }
        $now = new \DateTimeImmutable();
        $from = $now->modify('-12 months')->format('Y-m-d H:i:s');
        $to = $now->format('Y-m-d H:i:s');
        // A refund invoice is "refunded" too, with a negative income: only the refunded invoice itself counts.
        $invoices = $db->fetchAssociative(
            "SELECT COALESCE(SUM(CASE WHEN status = 'paid' AND due_at >= ? AND due_at <= ? THEN 1 ELSE 0 END), 0) AS due,
                    COALESCE(SUM(CASE WHEN status = 'paid' AND due_at >= ? AND due_at <= ? AND DATE(paid_at) <= DATE(due_at) THEN 1 ELSE 0 END), 0) AS on_time,
                    COALESCE(SUM(CASE WHEN status = 'paid' THEN base_income ELSE 0 END), 0) AS paid,
                    COALESCE(SUM(CASE WHEN status = 'refunded' AND base_income > 0 THEN 1 ELSE 0 END), 0) AS refunded
               FROM invoice WHERE client_id = ?",
            [$from, $to, $from, $to, $clientId]
        );

        $due = (int) $invoices['due'];
        $since = $client['created_at'] ? (new \DateTimeImmutable($client['created_at']))->diff($now) : null;
        $months = $since ? $since->y * 12 + $since->m : 0;
        $paid = (float) $invoices['paid'];
        $parts = [
            'on_time' => $due > 0 ? (int) round((int) $invoices['on_time'] / $due * 40) : 20,
            'since' => min(20, $months),
            'services' => min(15, 5 * (int) $client['active']),
            'paid' => match (true) {
                $paid >= 2000 => 15,
                $paid >= 1000 => 11,
                $paid >= 500 => 7,
                $paid >= 100 => 3,
                default => 0,
            },
            'verified' => ((int) $client['email_approved'] === 1 ? 5 : 0) + ((int) $client['id_approved'] > 0 ? 5 : 0),
        ];
        $deductions = [
            'fraud' => (int) $client['fraud'] > 0 ? 20 : 0,
            'refunds' => 5 * ((int) $invoices['refunded'] + (int) $client['reversed']),
        ];
        $total = max(0, array_sum($parts) - array_sum($deductions));

        return [
            'total' => $total,
            'band' => match (true) {
                $total >= 80 => 'very_good',
                $total >= 60 => 'good',
                $total >= 40 => 'fair',
                default => 'watch',
            },
            'parts' => $parts,
            'deductions' => $deductions,
            'counts' => [
                'due' => $due,
                'on_time' => (int) $invoices['on_time'],
                'months' => $months,
                'active' => (int) $client['active'],
                'paid' => round($paid, 2),
                'email' => (int) $client['email_approved'] === 1,
                'id_document' => (int) $client['id_approved'] > 0,
                'refunded' => (int) $invoices['refunded'],
                'reversed' => (int) $client['reversed'],
            ],
        ];
    }
}
