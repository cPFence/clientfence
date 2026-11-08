<?php

declare(strict_types=1);

/**
 * ClientFence: automatic payments with saved cards and saved PayPal accounts, as WHMCS charges a payment method on
 * file (owner decisions 2026-11-06 and 2026-11-07). A client saves a Stripe card or their PayPal account (PayPal
 * Email with its REST API keys and "Saved PayPal accounts" on) when paying an invoice, or on the client area's Payment
 * methods page (/payment-methods). Each gateway keeps the saved method in pay_gateway_customer
 * (default_payment_method); the one the client saved or chose last (auto_pay_at) pays. The cron charges it for unpaid
 * invoices without a payment method or with one of these gateways: the set number of days before the due date, then
 * every set number of days up to the set number of retries (invoice_card_charge counts the tries). A failed charge
 * emails the client (mod_invoice_card_failed). Invoices of orders that a gateway subscription pays are left to it.
 * Staff can charge the saved method from the invoice page.
 */

namespace ClientFence\Mod\Invoice;

use ClientFence\InformationException;

final class SavedCards
{
    /** invoice_card_charge.attempts of an invoice that is never charged automatically again (hold()). */
    private const HELD = 99;

    /** {days_before, every, retries} from the Invoices settings page. */
    public static function settings(\Pimple\Container $di): array
    {
        $config = $di['mod_service']('extension')->getConfig('mod_invoice');
        // Before the retry count, card_retries switched the two retries on or off.
        $retries = $config['card_retry_count'] ?? ((string) ($config['card_retries'] ?? '1') === '0' ? 0 : 2);

        return [
            'days_before' => max(0, min(30, (int) ($config['card_days_before'] ?? 0))),
            'every' => max(1, min(30, (int) ($config['card_retry_every'] ?? 7))),
            'retries' => max(0, min(5, (int) $retries)),
        ];
    }

    public static function saveSettings(\Pimple\Container $di, array $data): void
    {
        $days = filter_var($data['card_days_before'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 30]]);
        if ($days === false) {
            throw new InformationException('Days before the due date must be a whole number from 0 to 30.');
        }
        $every = filter_var($data['card_retry_every'] ?? 7, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 30]]);
        if ($every === false) {
            throw new InformationException('Days between retries must be a whole number from 1 to 30.');
        }
        $retries = filter_var($data['card_retry_count'] ?? 2, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 5]]);
        if ($retries === false) {
            throw new InformationException('Retries must be a whole number from 0 to 5.');
        }
        $extension = $di['mod_service']('extension');
        $extension->setConfig(array_merge($extension->getConfig('mod_invoice'), [
            'card_days_before' => (string) $days,
            'card_retry_every' => (string) $every,
            'card_retry_count' => (string) $retries,
            'ext' => 'mod_invoice',
        ]));
        $di['logger']->info('Invoice: saved payment methods are charged %s days before the due date, retried %s times every %s days', $days, $retries, $every);
    }

    /** The enabled Stripe payment gateway's adapter, or null. */
    public static function stripe(\Pimple\Container $di): ?\Payment_Adapter_Stripe
    {
        $adapter = self::adapter($di, 'Stripe');

        return $adapter instanceof \Payment_Adapter_Stripe ? $adapter : null;
    }

    /** The enabled PayPal Email gateway's adapter when clients can save their PayPal account, or null. */
    public static function paypal(\Pimple\Container $di): ?\Payment_Adapter_PayPalEmail
    {
        $adapter = self::adapter($di, 'PayPalEmail');

        return $adapter instanceof \Payment_Adapter_PayPalEmail && $adapter->canVault() ? $adapter : null;
    }

    private static function adapter(\Pimple\Container $di, string $code): ?object
    {
        $gateway = $di['db']->findOne('PayGateway', 'gateway = ? AND enabled = 1', [$code]);

        return $gateway instanceof \Model_PayGateway ? $di['mod_service']('Invoice', 'PayGateway')->getPaymentAdapter($gateway) : null;
    }

    /** @return list<\Payment_Adapter_Stripe|\Payment_Adapter_PayPalEmail> the gateways that charge saved methods */
    public static function adapters(\Pimple\Container $di): array
    {
        return array_values(array_filter([self::stripe($di), self::paypal($di)]));
    }

    /**
     * The method that pays the client's invoices: the saved one the client saved or chose last.
     * {adapter, payment_method, card (its label)}, or null.
     */
    public static function method(\Pimple\Container $di, int $clientId): ?array
    {
        $method = null;
        foreach (self::adapters($di) as $adapter) {
            $saved = $adapter->savedCard($clientId);
            if ($saved !== null && ($method === null || (string) ($saved['auto_pay_at'] ?? '') > (string) ($method['auto_pay_at'] ?? ''))) {
                $method = $saved + ['adapter' => $adapter];
            }
        }

        return $method;
    }

    /** The client's method for automatic charges: {adapter, payment_method, card}, or null. */
    public static function card(\Pimple\Container $di, int $clientId): ?array
    {
        return self::method($di, $clientId);
    }

    /** The saved method of a gateway's client ({payment_method, card, auto_pay_at}), or null (the adapters' savedCard()). */
    public static function saved(\Pimple\Container $di, int $gatewayId, bool $testMode, int $clientId): ?array
    {
        $row = $di['dbal']->fetchAssociative(
            'SELECT default_payment_method, default_card, auto_pay_at FROM pay_gateway_customer WHERE pay_gateway_id = ? AND client_id = ? AND test_mode = ? AND saved_cards = 1',
            [$gatewayId, $clientId, (int) $testMode]
        );

        return is_array($row) && (string) $row['default_payment_method'] !== ''
            ? ['payment_method' => (string) $row['default_payment_method'], 'card' => (string) $row['default_card'], 'auto_pay_at' => (string) $row['auto_pay_at']]
            : null;
    }

    /** Keeps a saved PayPal account (one per client and gateway) as the method that pays the client's invoices. */
    public static function remember(\Pimple\Container $di, int $gatewayId, bool $testMode, int $clientId, string $customerId, string $paymentMethod, string $label): void
    {
        $key = [$gatewayId, $clientId, (int) $testMode];
        $now = date('Y-m-d H:i:s');
        if ($di['dbal']->executeStatement(
            'UPDATE pay_gateway_customer SET external_customer_id = ?, saved_cards = 1, default_payment_method = ?, default_card = ?, auto_pay_at = ?, updated_at = ? WHERE pay_gateway_id = ? AND client_id = ? AND test_mode = ?',
            [$customerId, $paymentMethod, mb_substr($label, 0, 100), $now, $now, ...$key]
        ) === 0) {
            $di['dbal']->insert('pay_gateway_customer', [
                'pay_gateway_id' => $gatewayId, 'client_id' => $clientId, 'test_mode' => (int) $testMode, 'external_customer_id' => $customerId, 'saved_cards' => 1,
                'default_payment_method' => $paymentMethod, 'default_card' => mb_substr($label, 0, 100), 'auto_pay_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /** Makes a gateway's saved method the one that pays the client's invoices. */
    public static function choose(\Pimple\Container $di, int $gatewayId, bool $testMode, int $clientId): void
    {
        $di['dbal']->executeStatement('UPDATE pay_gateway_customer SET auto_pay_at = ? WHERE pay_gateway_id = ? AND client_id = ? AND test_mode = ?', [date('Y-m-d H:i:s'), $gatewayId, $clientId, (int) $testMode]);
    }

    /** From the cron: charges the saved method of every invoice whose next try is due today. */
    public static function runDue(\Pimple\Container $di): void
    {
        $adapters = self::adapters($di);
        if ($adapters === []) {
            return;
        }
        self::endPaypalSubscriptions($di);
        $gatewayIds = array_map(static fn (object $adapter): int => $adapter->gatewayId(), $adapters);
        $settings = self::settings($di);
        foreach ($adapters as $adapter) {
            $rows = $di['dbal']->fetchAllAssociative(
                "SELECT i.id, i.client_id, i.due_at, COALESCE(c.attempts, 0) AS attempts FROM invoice i
                   JOIN pay_gateway_customer g ON g.client_id = i.client_id AND g.pay_gateway_id = ? AND g.test_mode = ? AND g.saved_cards = 1
                        AND g.default_payment_method IS NOT NULL AND g.default_payment_method <> ''
                   LEFT JOIN invoice_card_charge c ON c.invoice_id = i.id
                  WHERE i.status = 'unpaid' AND i.approved = 1 AND i.due_at IS NOT NULL
                    AND (i.gateway_id IS NULL OR i.gateway_id IN (" . implode(',', $gatewayIds) . ')) AND COALESCE(c.attempts, 0) < ?
                    AND (c.last_attempt_at IS NULL OR c.last_attempt_at < ?)
                    AND NOT ' . PendingPayments::sql('i.id') . '
                    AND NOT EXISTS (SELECT 1 FROM invoice_item ii
                          JOIN invoice_item si ON si.type = ii.type AND si.rel_id = ii.rel_id
                          JOIN subscription s ON s.rel_type = \'invoice\' AND s.rel_id = si.invoice_id AND s.status = \'active\'
                         WHERE ii.invoice_id = i.id AND ii.type = \'order\')
                  ORDER BY i.id',
                // Tries at least the retry gap apart, also for an invoice long overdue when the method was saved.
                [$adapter->gatewayId(), (int) $adapter->testMode(), 1 + $settings['retries'], date('Y-m-d', strtotime('-' . ($settings['every'] - 1) . ' days'))]
            );
            $today = date('Y-m-d');
            foreach ($rows as $row) {
                if (self::tryDate((string) $row['due_at'], (int) $row['attempts'], $settings) > $today
                    || !(self::method($di, (int) $row['client_id'])['adapter'] ?? null) instanceof $adapter) {
                    continue;
                }
                $invoice = $di['db']->load('Invoice', (int) $row['id']);
                if ($invoice instanceof \Model_Invoice) {
                    self::charge($di, $invoice, $adapter, false);
                }
            }
        }
    }

    /**
     * As WHMCS, a client who saved their PayPal account no longer needs their PayPal subscriptions: they are cancelled
     * (PayPal emails the client) and the saved account pays the renewal invoices.
     */
    private static function endPaypalSubscriptions(\Pimple\Container $di): void
    {
        $paypal = self::paypal($di);
        if ($paypal === null) {
            return;
        }
        $ids = $di['dbal']->fetchFirstColumn(
            "SELECT s.id FROM subscription s JOIN pay_gateway_customer g ON g.client_id = s.client_id AND g.pay_gateway_id = s.pay_gateway_id AND g.test_mode = ?
              WHERE s.pay_gateway_id = ? AND s.status = 'active' AND s.sid IS NOT NULL AND s.sid <> '' AND g.saved_cards = 1
                AND g.default_payment_method IS NOT NULL AND g.default_payment_method <> ''
                AND (s.updated_at IS NULL OR s.updated_at < ?)",
            // A subscription PayPal refused to cancel is tried again a day later, not every cron run.
            [(int) $paypal->testMode(), $paypal->gatewayId(), date('Y-m-d H:i:s', strtotime('-1 day'))]
        );
        foreach ($ids as $id) {
            $subscription = $di['db']->load('Subscription', (int) $id);
            try {
                $di['mod_service']('Invoice', 'Subscription')->cancel($subscription);
                $di['logger']->info('Subscription #%s (%s): cancelled at PayPal, the saved PayPal account pays from now on', $id, $subscription->sid);
            } catch (\Throwable $e) {
                $di['dbal']->executeStatement('UPDATE subscription SET updated_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), (int) $id]);
                $di['logger']->warning('Subscription #%s (%s): not cancelled at PayPal: %s', $id, $subscription->sid, $e->getMessage());
            }
        }
    }

    /** The day of a try (0 = the first) for an invoice due on $dueAt. */
    public static function tryDate(string $dueAt, int $try, array $settings): string
    {
        return date('Y-m-d', strtotime(substr($dueAt, 0, 10) . ' ' . ($try * $settings['every'] - $settings['days_before']) . ' days'));
    }

    /**
     * Charges the saved method for the invoice's balance and records the payment: {paid, error}. A try of the cron
     * counts towards the retries and a failure emails the client; staff tries do neither.
     */
    public static function charge(\Pimple\Container $di, \Model_Invoice $invoice, \Payment_Adapter_Stripe|\Payment_Adapter_PayPalEmail $adapter, bool $byStaff): array
    {
        return \ClientFence\Tools::withLock($di, 'cf:card-charge:' . (int) $invoice->id, static function () use ($di, $invoice, $adapter, $byStaff): array {
            $db = $di['dbal'];
            $row = $db->fetchAssociative('SELECT status, gateway_id FROM invoice WHERE id = ?', [(int) $invoice->id]) ?: [];
            if (($row['status'] ?? null) !== \Model_Invoice::STATUS_UNPAID) {
                return ['paid' => false, 'error' => __trans('This invoice is already paid or cancelled.')];
            }
            if (PendingPayments::forInvoice($di, (int) $invoice->id) !== null) {
                return ['paid' => false, 'error' => __trans('A payment for this invoice is still clearing.')];
            }
            // A cron try leaves an invoice the client chose to pay another way after runDue read it (a receipt sent meanwhile).
            if (!$byStaff && $row['gateway_id'] !== null && !in_array((int) $row['gateway_id'], array_map(static fn (object $a): int => $a->gatewayId(), self::adapters($di)), true)) {
                return ['paid' => false, 'error' => null];
            }
            $attempt = (int) $db->fetchOne('SELECT attempts FROM invoice_card_charge WHERE invoice_id = ?', [(int) $invoice->id]);
            // Held after runDue read it (a staff charge PayPal holds): a cron try would charge twice.
            if (!$byStaff && $attempt >= self::HELD) {
                return ['paid' => false, 'error' => null];
            }
            if (!$byStaff && $db->executeStatement('UPDATE invoice_card_charge SET attempts = attempts + 1, last_attempt_at = ? WHERE invoice_id = ?', [date('Y-m-d H:i:s'), (int) $invoice->id]) === 0) {
                $db->insert('invoice_card_charge', ['invoice_id' => (int) $invoice->id, 'attempts' => 1, 'last_attempt_at' => date('Y-m-d H:i:s')]);
            }
            $card = (string) ($adapter->savedCard((int) $invoice->client_id)['card'] ?? '');

            try {
                $paymentId = $adapter->chargeSavedCard($invoice, $byStaff ? 'staff_' . time() : 'attempt_' . ($attempt + 1));
            } catch (\Throwable $e) {
                $error = $e instanceof InformationException ? $e->getMessage() : __trans('The payment service did not answer. Please try again later.');
                $db->executeStatement('UPDATE invoice_card_charge SET last_error = ? WHERE invoice_id = ?', [mb_substr($error, 0, 255), (int) $invoice->id]);
                if (!$e instanceof InformationException) {
                    // Maybe taken without an answer (a timeout): a new try could charge twice (owner decision 2026-11-07).
                    self::hold($di, (int) $invoice->id, 'No answer: ' . $e->getMessage());
                }
                $di['logger']->warning('Invoice #%s: charging the saved method %s failed: %s', $invoice->id, $card, $e->getMessage());
                if (!$byStaff && $e instanceof InformationException) {
                    self::emailFailure($di, $invoice, $card, $error, $attempt + 1);
                }

                return ['paid' => false, 'error' => $error];
            }
            // Taken: until it is recorded as paid (PayPal may hold it, or reading it back may fail), never charged again.
            self::hold($di, (int) $invoice->id, 'Payment ' . $paymentId . ' is not completed yet.');
            $di['mod_service']('Invoice', 'Transaction')->createAndProcess([
                'invoice_id' => (int) $invoice->id,
                'gateway_id' => $adapter->gatewayId(),
                'source' => 'saved_card',
                'get' => [$adapter instanceof \Payment_Adapter_Stripe ? 'payment_intent' : 'paypal_capture' => $paymentId, 'invoice_id' => (int) $invoice->id],
            ]);
            $di['logger']->info('Invoice #%s: charged the saved method %s (%s)', $invoice->id, $card, $paymentId);
            if ($db->fetchOne('SELECT status FROM invoice WHERE id = ?', [(int) $invoice->id]) !== \Model_Invoice::STATUS_PAID) {
                return ['paid' => false, 'error' => PendingPayments::forInvoice($di, (int) $invoice->id) !== null
                    ? __trans('The bank debit has started. The invoice is marked paid when it clears.')
                    : __trans('The payment was taken but is not completed yet. Check it with the payment service.')];
            }

            return ['paid' => true, 'error' => null];
        });
    }

    /** Stops automatic tries for an invoice whose payment may have been taken already (staff can still charge it). */
    public static function hold(\Pimple\Container $di, int $invoiceId, string $note): void
    {
        $db = $di['dbal'];
        $values = ['attempts' => self::HELD, 'last_attempt_at' => date('Y-m-d H:i:s'), 'last_error' => mb_substr($note, 0, 255)];
        $db->fetchOne('SELECT 1 FROM invoice_card_charge WHERE invoice_id = ?', [$invoiceId]) === false
            ? $db->insert('invoice_card_charge', ['invoice_id' => $invoiceId] + $values)
            : $db->update('invoice_card_charge', $values, ['invoice_id' => $invoiceId]);
    }

    /** A saved bank debit that was clearing failed (Payment_Adapter_Stripe): the client is told, with no automatic retry. */
    public static function emailDebitFailure(\Pimple\Container $di, \Model_Invoice $invoice, string $method, string $reason): void
    {
        self::emailFailure($di, $invoice, $method, $reason, self::HELD);
    }

    private static function emailFailure(\Pimple\Container $di, \Model_Invoice $invoice, string $card, string $reason, int $tries): void
    {
        $settings = self::settings($di);
        $retryAt = $tries <= $settings['retries']
            ? max(self::tryDate((string) $invoice->due_at, $tries, $settings), date('Y-m-d', strtotime('+' . $settings['every'] . ' days')))
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
            $di['logger']->error('Invoice #%s: the failed payment email was not sent: %s', $invoice->id, $e->getMessage());
        }
    }
}
