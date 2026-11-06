<?php

declare(strict_types=1);

/**
 * ClientFence: saved cards, charged as WHMCS charges a card on file (owner decision 2026-11-06;
 * Stripe only). A client saves a card with Stripe's "save" box when paying their invoice, or on the
 * client area's Payment methods page (/payment-methods). The card the client's invoices are charged
 * to is kept with their Stripe customer (pay_gateway_customer.default_payment_method; see the saved
 * cards part of Payment_Adapter_Stripe). The cron charges it for unpaid invoices without a payment
 * method or with Stripe's: the set number of days before the due date, then 7 and 14 days later when
 * retries are on (invoice_card_charge counts the tries). A failed charge emails the client
 * (mod_invoice_card_failed). Invoices of orders that a Stripe subscription pays are left to it.
 * Staff can charge the card from the invoice page.
 */

namespace ClientFence\Mod\Invoice;

use ClientFence\InformationException;

final class SavedCards
{
    /** Days after the first try (the due date less the days before) for each try. */
    public const RETRY_DAYS = [0, 7, 14];

    /** {days_before, retries} from the Invoices settings page. */
    public static function settings(\Pimple\Container $di): array
    {
        $config = $di['mod_service']('extension')->getConfig('mod_invoice');

        return [
            'days_before' => max(0, min(30, (int) ($config['card_days_before'] ?? 0))),
            'retries' => (string) ($config['card_retries'] ?? '1') !== '0',
        ];
    }

    public static function saveSettings(\Pimple\Container $di, array $data): void
    {
        $days = filter_var($data['card_days_before'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 30]]);
        if ($days === false) {
            throw new InformationException('Days before the due date must be a whole number from 0 to 30.');
        }
        $extension = $di['mod_service']('extension');
        $extension->setConfig(array_merge($extension->getConfig('mod_invoice'), [
            'card_days_before' => (string) $days,
            'card_retries' => empty($data['card_retries']) ? '0' : '1',
            'ext' => 'mod_invoice',
        ]));
        $di['logger']->info('Invoice: saved cards are charged %s days before the due date, %s', $days, empty($data['card_retries']) ? 'once' : 'with retries');
    }

    /** The enabled Stripe payment gateway's adapter, or null. */
    public static function stripe(\Pimple\Container $di): ?\Payment_Adapter_Stripe
    {
        $gateway = $di['db']->findOne('PayGateway', 'gateway = ? AND enabled = 1', ['Stripe']);
        if (!$gateway instanceof \Model_PayGateway) {
            return null;
        }
        $adapter = $di['mod_service']('Invoice', 'PayGateway')->getPaymentAdapter($gateway);

        return $adapter instanceof \Payment_Adapter_Stripe ? $adapter : null;
    }

    /** The client's card for automatic charges: {payment_method, card}, or null. */
    public static function card(\Pimple\Container $di, int $clientId): ?array
    {
        return self::stripe($di)?->savedCard($clientId);
    }

    /** From the cron: charges the saved card of every invoice whose next try is due today. */
    public static function runDue(\Pimple\Container $di): void
    {
        $adapter = self::stripe($di);
        if ($adapter === null) {
            return;
        }
        $settings = self::settings($di);
        $tries = $settings['retries'] ? count(self::RETRY_DAYS) : 1;
        $rows = $di['dbal']->fetchAllAssociative(
            "SELECT i.id, i.due_at, COALESCE(c.attempts, 0) AS attempts FROM invoice i
               JOIN pay_gateway_customer g ON g.client_id = i.client_id AND g.pay_gateway_id = ? AND g.test_mode = ? AND g.saved_cards = 1
                    AND g.default_payment_method IS NOT NULL AND g.default_payment_method <> ''
               LEFT JOIN invoice_card_charge c ON c.invoice_id = i.id
              WHERE i.status = 'unpaid' AND i.approved = 1 AND i.due_at IS NOT NULL
                AND (i.gateway_id IS NULL OR i.gateway_id = ?) AND COALESCE(c.attempts, 0) < ?
                AND (c.last_attempt_at IS NULL OR c.last_attempt_at < ?)
                AND NOT EXISTS (SELECT 1 FROM invoice_item ii
                      JOIN invoice_item si ON si.type = ii.type AND si.rel_id = ii.rel_id
                      JOIN subscription s ON s.rel_type = 'invoice' AND s.rel_id = si.invoice_id AND s.status = 'active'
                     WHERE ii.invoice_id = i.id AND ii.type = 'order')
              ORDER BY i.id",
            // Tries at least 7 days apart, also for an invoice long overdue when the card was saved.
            [$adapter->gatewayId(), (int) $adapter->testMode(), $adapter->gatewayId(), $tries, date('Y-m-d', strtotime('-6 days'))]
        );
        $today = date('Y-m-d');
        foreach ($rows as $row) {
            if (self::tryDate((string) $row['due_at'], (int) $row['attempts'], $settings['days_before']) > $today) {
                continue;
            }
            $invoice = $di['db']->load('Invoice', (int) $row['id']);
            if ($invoice instanceof \Model_Invoice) {
                self::charge($di, $invoice, $adapter, false);
            }
        }
    }

    /** The day of a try (0 = the first) for an invoice due on $dueAt. */
    public static function tryDate(string $dueAt, int $try, int $daysBefore): string
    {
        return date('Y-m-d', strtotime(substr($dueAt, 0, 10) . ' ' . (self::RETRY_DAYS[$try] - $daysBefore) . ' days'));
    }

    /**
     * Charges the saved card for the invoice's balance and records the payment: {paid, error}. A try
     * of the cron counts towards the retries and a failure emails the client; staff tries do neither.
     */
    public static function charge(\Pimple\Container $di, \Model_Invoice $invoice, \Payment_Adapter_Stripe $adapter, bool $byStaff): array
    {
        return \ClientFence\Tools::withLock($di, 'cf:card-charge:' . (int) $invoice->id, static function () use ($di, $invoice, $adapter, $byStaff): array {
            $db = $di['dbal'];
            $status = $db->fetchOne('SELECT status FROM invoice WHERE id = ?', [(int) $invoice->id]);
            if ($status !== \Model_Invoice::STATUS_UNPAID) {
                return ['paid' => false, 'error' => __trans('This invoice is already paid or cancelled.')];
            }
            $attempt = (int) $db->fetchOne('SELECT attempts FROM invoice_card_charge WHERE invoice_id = ?', [(int) $invoice->id]);
            if (!$byStaff && $db->executeStatement('UPDATE invoice_card_charge SET attempts = attempts + 1, last_attempt_at = ? WHERE invoice_id = ?', [date('Y-m-d H:i:s'), (int) $invoice->id]) === 0) {
                $db->insert('invoice_card_charge', ['invoice_id' => (int) $invoice->id, 'attempts' => 1, 'last_attempt_at' => date('Y-m-d H:i:s')]);
            }
            $card = (string) ($adapter->savedCard((int) $invoice->client_id)['card'] ?? '');

            try {
                $paymentIntent = $adapter->chargeSavedCard($invoice, $byStaff ? 'staff_' . time() : 'attempt_' . ($attempt + 1));
            } catch (\Throwable $e) {
                $error = $e instanceof InformationException ? $e->getMessage() : __trans('The payment service did not answer. Please try again later.');
                $db->executeStatement('UPDATE invoice_card_charge SET last_error = ? WHERE invoice_id = ?', [mb_substr($error, 0, 255), (int) $invoice->id]);
                $di['logger']->warning('Invoice #%s: charging the saved card %s failed: %s', $invoice->id, $card, $e->getMessage());
                if (!$byStaff && $e instanceof InformationException) {
                    self::emailFailure($di, $invoice, $card, $error, $attempt + 1);
                }

                return ['paid' => false, 'error' => $error];
            }
            $di['mod_service']('Invoice', 'Transaction')->createAndProcess([
                'invoice_id' => (int) $invoice->id,
                'gateway_id' => $adapter->gatewayId(),
                'source' => 'saved_card',
                'get' => ['payment_intent' => $paymentIntent, 'invoice_id' => (int) $invoice->id],
            ]);
            $di['logger']->info('Invoice #%s: charged the saved card %s (%s)', $invoice->id, $card, $paymentIntent);

            return ['paid' => true, 'error' => null];
        });
    }

    private static function emailFailure(\Pimple\Container $di, \Model_Invoice $invoice, string $card, string $reason, int $tries): void
    {
        $settings = self::settings($di);
        $retryAt = $settings['retries'] && $tries < count(self::RETRY_DAYS)
            ? max(self::tryDate((string) $invoice->due_at, $tries, $settings['days_before']), date('Y-m-d', strtotime('+7 days')))
            : '';
        try {
            $service = $di['mod_service']('Invoice');
            $data = $service->toApiArray($invoice, true, null, true);
            $email = $service->withBillingRecipient([
                'to_client' => (int) $invoice->client_id,
                'code' => 'mod_invoice_card_failed',
                'invoice' => $data,
                'card' => $card,
                'reason' => $reason,
                'retry_at' => $retryAt,
            ], $data);
            $di['mod_service']('email')->sendTemplate($email);
        } catch (\Throwable $e) {
            $di['logger']->error('Invoice #%s: the card failure email was not sent: %s', $invoice->id, $e->getMessage());
        }
    }
}
