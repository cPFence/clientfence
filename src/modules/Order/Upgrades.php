<?php

declare(strict_types=1);

namespace ClientFence\Mod\Order;

use Doctrine\DBAL\Connection;
use ClientFence\InformationException;
use ClientFence\Mod\Clientfence\Whmcs\Money;
use ClientFence\Mod\Product\Options;

/**
 * ClientFence: upgrades and downgrades of a service by the client, as WHMCS (Proration.php has the
 * price rules).
 *
 * The client area lists, for an active service, every product its product may move to (the
 * product's "Upgrades" list, plus its own other billing cycles) that is on sale, of the same kind
 * and allowed here, with the prorated price (a cycle priced 0 is left out: FOSSBilling enables
 * every cycle at 0 by default). Choosing one:
 * - amount > 0: an invoice with one line (an invoice "hook call", Upgrades::EVENT); when it is
 *   paid, Clientfence\Service::onAfterClientOrderUpgradePaid() applies the change (and the cron
 *   applies any paid one the event missed);
 * - amount <= 0: the change applies at once, and a negative amount goes to the client's account
 *   credit (WHMCS "credit on downgrade").
 * Applying moves the order to the new product, price, billing cycle and expiry date; a hosting
 * account also moves to the new product's plan on its panel (same server only). A failure goes to
 * Orders > Failed actions (action "upgrade") for staff to retry.
 *
 * Who may: Orders settings turn upgrades and downgrades on or off for everyone; a product, and a
 * single service, can allow or block each of them (client_order_upgrade_rule; a service's rule
 * wins over its product's, the default allows). A service needs no unpaid invoice, no discount,
 * the client's currency and no other upgrade waiting for payment.
 *
 * Configurable options (Product/Options.php): a service keeps the options its new product also has
 * (priced for the new cycle; the others take their defaults), and the client can change the
 * options alone (optionsQuote()): as WHMCS, the recurring price then moves by the options'
 * difference and the client pays, or is credited, it for the days left. The new choice waits in
 * client_order_upgrade_option until the change applies.
 */
final class Upgrades
{
    public const TABLE = 'client_order_upgrade';
    /** The configurable options an upgrade sets (one row per upgrade of a service with options). */
    public const OPTIONS_TABLE = 'client_order_upgrade_option';
    public const RULES = 'client_order_upgrade_rule';
    /** The invoice line's event, fired when the upgrade invoice is paid. */
    public const EVENT = 'onAfterClientOrderUpgradePaid';
    /** The failed actions entry (ActionFailures) of an upgrade that could not be applied. */
    public const ACTION = 'upgrade';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPLYING = 'applying';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const SCOPE_PRODUCT = 'product';
    public const SCOPE_ORDER = 'order';

    /** FOSSBilling's price columns (product_payment) by billing period. */
    public const PERIODS = ['w' => '1W', 'm' => '1M', 'q' => '3M', 'b' => '6M', 'a' => '1Y', 'bia' => '2Y', 'tria' => '3Y'];
    /** Service kinds that can move between products (domains renew by registrar rules). */
    private const KINDS = ['hosting', 'license', 'custom', 'membership', 'downloadable'];

    /** Why a service with a change waiting for payment (or being applied) cannot change again. */
    public static function waitingMessage(): string
    {
        return __trans('A change of this service is waiting for payment. Pay its invoice, or ask us to cancel it.');
    }

    /**
     * Orders settings: are upgrades and downgrades on at all (default on).
     *
     * @return array{upgrades: bool, downgrades: bool}
     */
    public static function settings(\Pimple\Container $di): array
    {
        try {
            $config = (array) $di['mod_config']('order');
        } catch (\Throwable) {
            $config = [];
        }

        return [
            'upgrades' => (string) ($config['upgrades_enabled'] ?? '1') !== '0',
            'downgrades' => (string) ($config['downgrades_enabled'] ?? '1') !== '0',
        ];
    }

    /**
     * Whether a change of this kind may happen: the setting first, then the service's own rule,
     * then its product's (null means "no rule": allowed).
     *
     * @param array{upgrades: bool, downgrades: bool}            $settings
     * @param array{upgrade: ?bool, downgrade: ?bool}           $productRule
     * @param array{upgrade: ?bool, downgrade: ?bool}           $orderRule
     */
    public static function allowedBy(array $settings, array $productRule, array $orderRule, string $kind): bool
    {
        $key = $kind === Proration::DOWNGRADE ? 'downgrade' : 'upgrade';
        if (!$settings[$key . 's']) {
            return false;
        }

        return $orderRule[$key] ?? $productRule[$key] ?? true;
    }

    /**
     * A product's or a service's rule: upgrade and downgrade each true (allow), false (block) or
     * null (follow the product, or for a product: allowed).
     *
     * @return array{upgrade: ?bool, downgrade: ?bool}
     */
    public static function rule(\Pimple\Container $di, string $scope, int $id): array
    {
        try {
            $row = self::db($di)->fetchAssociative('SELECT upgrade, downgrade FROM ' . self::RULES . ' WHERE scope = ? AND ref_id = ?', [$scope, $id]);
        } catch (\Exception) {
            $row = false;
        }
        $value = static fn (mixed $v): ?bool => $v === null ? null : (bool) (int) $v;

        return ['upgrade' => $row === false ? null : $value($row['upgrade']), 'downgrade' => $row === false ? null : $value($row['downgrade'])];
    }

    /**
     * Saves a rule ('allow', 'block' or 'default' for each; anything else is refused).
     */
    public static function saveRule(\Pimple\Container $di, string $scope, int $id, string $upgrade, string $downgrade): void
    {
        if (!in_array($scope, [self::SCOPE_PRODUCT, self::SCOPE_ORDER], true) || $id < 1) {
            throw new InformationException('Unknown product or service.');
        }
        $map = ['default' => null, 'allow' => 1, 'block' => 0];
        if (!array_key_exists($upgrade, $map) || !array_key_exists($downgrade, $map)) {
            throw new InformationException('Choose allow, block or default.');
        }
        $db = self::db($di);
        $db->delete(self::RULES, ['scope' => $scope, 'ref_id' => $id]);
        if ($map[$upgrade] !== null || $map[$downgrade] !== null) {
            $db->insert(self::RULES, ['scope' => $scope, 'ref_id' => $id, 'upgrade' => $map[$upgrade], 'downgrade' => $map[$downgrade], 'updated_at' => date('Y-m-d H:i:s')]);
        }
    }

    /**
     * What a service can change to now. `available` false comes with a `reason` for the client.
     *
     * @return array{available: bool, reason: string, current: array<string, mixed>, options: list<array<string, mixed>>, waiting: array<string, mixed>|null, options_change: bool, product_options: list<array<string, mixed>>}
     */
    public static function options(\Pimple\Container $di, \Model_ClientOrder $order, ?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');
        $db = self::db($di);
        $own = self::orderOptions($order);
        $current = ['product_id' => (int) $order->product_id, 'title' => (string) $order->title, 'period' => (string) $order->period, 'price' => self::orderTotal($order), 'expires_at' => (string) $order->expires_at, 'currency' => (string) $order->currency, 'option_lines' => $own['lines']];
        $settings = self::settings($di);
        $none = static fn (string $reason, ?array $waiting = null, array $offered = []): array => ['available' => false, 'reason' => $reason, 'current' => $current, 'options' => [], 'waiting' => $waiting, 'options_change' => $offered !== [], 'product_options' => $offered];
        // A change waiting for payment is shown to the client with its invoice, whatever the settings.
        $waiting = self::waiting($di, (int) $order->id);
        if ($waiting !== null) {
            return $none(self::waitingMessage(), $waiting);
        }
        if (!$settings['upgrades'] && !$settings['downgrades']) {
            return $none('Upgrades and downgrades are not available.');
        }
        $reason = self::blocker($di, $order, $now);
        if ($reason !== null) {
            return $none($reason);
        }
        // A retired product can still move to the products it lists, but not to its own cycles.
        // ClientFence core patch: fixed currency prices (Product/CurrencyPrices.php) of the order's currency.
        $from = self::catalog($db, (int) $order->product_id, false, (string) $order->currency);
        $rate = self::rate($db, (string) $order->currency);
        if ($from === null || $rate === null) {
            return $none('This service cannot be changed here. Please contact us.');
        }
        // The client may change the configurable options alone (optionsQuote()): the options offered
        // now, for the service page's form (also for a product no longer on sale).
        $offered = Options::forProduct($di, (int) $order->product_id);
        $paid = self::recurringTotal($di, $order);
        $productRule = self::rule($di, self::SCOPE_PRODUCT, (int) $order->product_id);
        $orderRule = self::rule($di, self::SCOPE_ORDER, (int) $order->id);
        $anchor = self::periodAnchor($db, $order);
        $quantity = max(1, (int) ($order->quantity ?? 1));

        $options = [];
        foreach (array_unique(array_merge([(int) $order->product_id], $from['upgrades'])) as $productId) {
            $to = $productId === (int) $order->product_id ? ($from['on_sale'] ? $from : null) : self::catalog($db, $productId, true, (string) $order->currency);
            if ($to === null || !self::compatible($from, $to)) {
                continue;
            }
            if ($productId !== (int) $order->product_id && !$di['mod_service']('product')->isStockAvailable($productId, $quantity)) {
                continue;
            }
            foreach ($to['prices'] as $period => $price) {
                if ($productId === (int) $order->product_id && $period === (string) $order->period) {
                    continue;
                }
                // The options the new product also has are kept, priced for the new cycle.
                $target = self::optionsFor($di, $productId, $own['choice'], $period);
                $here = $productId === (int) $order->product_id ? $target : self::optionsFor($di, (int) $order->product_id, $own['choice'], $period);
                if ($target === null) {
                    continue;
                }
                $newTotal = round(($price + $target['price']) * $rate * $quantity, 2);
                $sameCycle = isset($from['prices'][$period]) && $here !== null ? round(($from['prices'][$period] + $here['price']) * $rate * $quantity, 2) : null;
                $quote = Proration::quote($paid, (string) $order->period, (string) $order->expires_at, $newTotal, $period, $now, $sameCycle, $anchor);
                if (!self::allowedBy($settings, $productRule, $orderRule, $quote['kind'])) {
                    continue;
                }
                $quote = self::quoteWithTax($di, $order, $quote);
                $options[] = [
                    'product_id' => $productId,
                    'title' => $to['title'],
                    'period' => $period,
                    'price' => $newTotal,
                    'same_product' => $productId === (int) $order->product_id,
                    'options' => $target['choice'],
                    'option_lines' => $target['lines'],
                ] + $quote;
            }
        }
        usort($options, static fn (array $a, array $b): int => [$a['same_product'] ? 0 : 1, $a['price'] / self::periodDays($a['period']), $a['title']] <=> [$b['same_product'] ? 0 : 1, $b['price'] / self::periodDays($b['period']), $b['title']]);

        return $options === []
            ? $none('There is nothing to change this service to.', null, $offered)
            : ['available' => true, 'reason' => '', 'current' => $current, 'options' => $options, 'waiting' => null, 'options_change' => $offered !== [], 'product_options' => $offered];
    }

    /**
     * The client's choice: prices it again, then makes the invoice or applies it at once. With
     * $options, the service's configurable options change (same product and cycle; optionsQuote()).
     *
     * @param array<int|string, mixed>|null $options option id => value id, 0/1 or a quantity
     *
     * @return array{id: int, status: string, amount: float, invoice_hash: ?string}
     */
    public static function request(\Pimple\Container $di, \Model_ClientOrder $order, int $productId, string $period, ?string $now = null, ?array $options = null): array
    {
        // One request per service at a time (two sign-ins or two users of the account), each priced
        // on the service as the one before left it.
        $db = self::db($di);
        $lock = 'clientfence_upgrade_' . (int) $order->id;
        if (self::isMysql($db) && (int) $db->fetchOne('SELECT GET_LOCK(?, 15)', [$lock]) !== 1) {
            throw new InformationException('This service is being changed. Please try again in a moment.');
        }
        try {
            $fresh = $di['db']->load('ClientOrder', (int) $order->id);

            return self::requestNow($di, $fresh instanceof \Model_ClientOrder ? $fresh : $order, $productId, $period, $now ?? date('Y-m-d H:i:s'), $options);
        } finally {
            if (self::isMysql($db)) {
                $db->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
            }
        }
    }

    /**
     * @param array<int|string, mixed>|null $choice
     *
     * @return array{id: int, status: string, amount: float, invoice_hash: ?string}
     */
    private static function requestNow(\Pimple\Container $di, \Model_ClientOrder $order, int $productId, string $period, string $now, ?array $choice): array
    {
        if ($choice !== null) {
            if ($productId !== (int) $order->product_id || strtoupper(trim($period)) !== (string) $order->period) {
                throw new InformationException('Change the options and the product or billing cycle one at a time.');
            }
            $chosen = self::optionsQuote($di, $order, $choice, $now);
        } else {
            $options = self::options($di, $order, $now);
            if (!$options['available']) {
                throw new InformationException($options['reason']);
            }
            $period = strtoupper(trim($period));
            $chosen = null;
            foreach ($options['options'] as $option) {
                if ($option['product_id'] === $productId && $option['period'] === $period) {
                    $chosen = $option;
                }
            }
            if ($chosen === null) {
                throw new InformationException('This change is not available for this service.');
            }
        }

        $db = self::db($di);
        $db->insert(self::TABLE, [
            'order_id' => (int) $order->id,
            'client_id' => (int) $order->client_id,
            'kind' => $chosen['kind'],
            'from_product_id' => (int) $order->product_id,
            'from_title' => mb_substr((string) $order->title, 0, 255),
            'from_period' => (string) $order->period,
            'from_price' => self::orderTotal($order),
            'from_expires_at' => (string) $order->expires_at,
            'to_product_id' => $productId,
            'to_title' => mb_substr((string) $chosen['title'], 0, 255),
            'to_period' => $chosen['period'],
            'to_price' => $chosen['price'],
            'to_expires_at' => $chosen['new_expires_at'],
            'days_left' => $chosen['days_left'],
            'days_total' => $chosen['days_total'],
            'credit' => $chosen['credit'],
            'charge' => $chosen['charge'],
            'amount' => $chosen['amount'],
            'currency' => (string) $order->currency,
            'status' => self::STATUS_PENDING,
            'created_at' => $now,
        ]);
        $id = (int) $db->lastInsertId();
        $own = self::orderOptions($order);
        if ($own['choice'] !== [] || $chosen['options'] !== []) {
            try {
                $db->insert(self::OPTIONS_TABLE, [
                    'upgrade_id' => $id,
                    'from_options' => json_encode($own['choice'], JSON_FORCE_OBJECT),
                    'to_options' => json_encode($chosen['options'], JSON_FORCE_OBJECT),
                    'to_option_lines' => json_encode($chosen['option_lines']),
                ]);
            } catch (\Throwable $e) {
                $db->update(self::TABLE, ['status' => self::STATUS_CANCELLED, 'error' => mb_substr($e->getMessage(), 0, 2000)], ['id' => $id]);

                throw $e;
            }
        }

        if ($chosen['amount'] > 0) {
            try {
                $invoice = self::invoice($di, $order, $chosen, $id);
            } catch (\Throwable $e) {
                $db->update(self::TABLE, ['status' => self::STATUS_CANCELLED, 'error' => mb_substr($e->getMessage(), 0, 2000)], ['id' => $id]);

                throw $e;
            }
            $db->update(self::TABLE, ['invoice_id' => (int) $invoice->id], ['id' => $id]);
            self::log($di, sprintf('Order #%d: %s to %s (%s) requested, invoice #%d', (int) $order->id, $chosen['kind'], $chosen['title'], $chosen['period'], (int) $invoice->id));

            return ['id' => $id, 'status' => self::STATUS_PENDING, 'amount' => $chosen['amount'], 'invoice_hash' => (string) $invoice->hash];
        }

        self::apply($di, $id, $now);
        $status = (string) $db->fetchOne('SELECT status FROM ' . self::TABLE . ' WHERE id = ?', [$id]);

        return ['id' => $id, 'status' => $status, 'amount' => $chosen['amount'], 'invoice_hash' => null];
    }

    /**
     * The upgrade's invoice: one line that fires Upgrades::EVENT when paid.
     *
     * @param array<string, mixed> $chosen
     */
    private static function invoice(\Pimple\Container $di, \Model_ClientOrder $order, array $chosen, int $id): \Model_Invoice
    {
        $client = $di['db']->getExistingModelById('Client', (int) $order->client_id, 'Client not found');

        return $di['mod_service']('invoice')->prepareInvoice($client, [
            'items' => [[
                'type' => \Model_InvoiceItem::TYPE_HOOK_CALL,
                'task' => self::EVENT,
                'rel_id' => json_encode(['upgrade_id' => $id]),
                'title' => self::invoiceTitle($order, $chosen),
                'price' => $chosen['amount'],
                'quantity' => 1,
                'taxed' => (bool) $di['mod_service']('client')->isClientTaxable($client),
            ]],
            'approve' => true,
        ]);
    }

    /** A downgrade's credit with the tax an invoice line of that amount carries (rounded per line, as InvoiceItem). */
    public static function creditWithTax(float $credit, float $rate): float
    {
        return round($credit + ($rate > 0 ? round($credit * $rate / 100, 2) : 0.0), 2);
    }

    /**
     * @param array<string, mixed> $quote
     *
     * @return array<string, mixed>
     */
    private static function quoteWithTax(\Pimple\Container $di, \Model_ClientOrder $order, array $quote): array
    {
        // ClientFence core patch (QA r8 C-01): show the invoice or credit note total, keeping the stored proration net.
        $rate = 0.0;
        if ($quote['amount'] < 0) {
            $source = self::creditSource(self::db($di), (int) $order->id);
            $rate = $source === null ? 0.0 : (float) $source['rate'];
        } elseif ($quote['amount'] > 0) {
            $client = $di['db']->getExistingModelById('Client', (int) $order->client_id, 'Client not found');
            $rate = (float) $di['mod_service']('Invoice', 'Tax')->getTaxRateForClient($client);
        }

        return $quote + ['total' => self::creditWithTax($quote['amount'], $rate), 'tax_rate' => max(0.0, $rate)];
    }

    /** @return array<string, mixed>|null */
    private static function creditSource(Connection $db, int $orderId): ?array
    {
        // ClientFence core patch: the unused time comes back with tax (a taxable client lost it on every
        // downgrade), at the lowest rate any paid invoice of this service charged (order and upgrade invoices),
        // never the profile's: a client who moved their profile between invoices never gets back tax they did
        // not pay for every part of the service.
        // The rates are compared as numbers here: invoice.taxrate is text, and SQL's MIN put '14' before '5'.
        $paid = $db->fetchAllAssociative(
            'SELECT r.id, r.rate FROM (
                SELECT i.id, CASE WHEN ii.taxed <> 0 THEN i.taxrate ELSE 0 END AS rate FROM invoice i JOIN invoice_item ii ON ii.invoice_id = i.id
                    WHERE ii.type = ? AND ii.rel_id = ? AND i.status = ?
                UNION ALL
                SELECT i.id, CASE WHEN ii.taxed <> 0 THEN i.taxrate ELSE 0 END AS rate FROM ' . self::TABLE . ' u JOIN invoice i ON i.id = u.invoice_id JOIN invoice_item ii ON ii.invoice_id = i.id
                    WHERE u.order_id = ? AND u.status = ? AND i.status = ?
            ) r ORDER BY r.id',
            [\Model_InvoiceItem::TYPE_ORDER, (string) $orderId, \Model_Invoice::STATUS_PAID, $orderId, self::STATUS_APPLIED, \Model_Invoice::STATUS_PAID],
        );
        $source = null;
        foreach ($paid as $row) {
            if ($source === null || (float) $row['rate'] < (float) $source['rate']) {
                $source = $row;
            }
        }

        return $source;
    }

    /** The upgrade invoice was paid (the invoice line's event, or the cron's check). */
    public static function onPaid(\Pimple\Container $di, int $id, ?string $now = null): void
    {
        $row = self::db($di)->fetchAssociative('SELECT u.status, i.status AS invoice_status FROM ' . self::TABLE . ' u LEFT JOIN invoice i ON i.id = u.invoice_id WHERE u.id = ?', [$id]);
        if ($row === false || $row['status'] !== self::STATUS_PENDING || $row['invoice_status'] !== \Model_Invoice::STATUS_PAID) {
            return;
        }
        self::apply($di, $id, $now);
    }

    /**
     * Applies an upgrade (pending, or failed when staff retry). Never throws for a failure of the
     * change itself: the upgrade is marked failed and Orders > Failed actions gets an entry.
     */
    public static function apply(\Pimple\Container $di, int $id, ?string $now = null): bool
    {
        $db = self::db($di);
        // One run at a time: the paid event and the cron may both try.
        $claimed = $db->executeStatement('UPDATE ' . self::TABLE . ' SET status = ?, claimed_at = ? WHERE id = ? AND status IN (?, ?)', [self::STATUS_APPLYING, date('Y-m-d H:i:s'), $id, self::STATUS_PENDING, self::STATUS_FAILED]);
        if ($claimed !== 1) {
            return false;
        }
        $up = $db->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE id = ?', [$id]);
        $orderId = (int) $up['order_id'];
        $renewalNote = '';
        $optionsNote = '';
        $opt = null;
        $locked = false;
        $renewalLock = 'fb:renewal:order:' . $orderId;
        $renewalLocked = false;
        try {
            // A paid invoice may already hold the money lock; waiting for renewal here would reverse the lock order.
            if (self::isMysql($db) && (int) $db->fetchOne('SELECT IS_USED_LOCK(?) = CONNECTION_ID()', ['fb:credit_payment:client:' . (int) $up['client_id']]) !== 1) {
                if ((int) $db->fetchOne('SELECT GET_LOCK(?, 10)', [$renewalLock]) !== 1) {
                    throw new InformationException('Another request is being processed. Please try again.');
                }
                $renewalLocked = true;
            }
            // ClientFence (QA r10 D-09): under the client's money lock too, as refunds and credit payments, so a refund
            // never reads the service's credit notes between the refund check below and this change's credit.
            $locked = self::moneyLock($di, (int) $up['client_id']);
            // Everything below happens under a lock of the order's row, so two changes of one
            // service (a paid upgrade and a downgrade, or two downgrades) never both pass the check.
            $db->beginTransaction();
            try {
                $row = $db->fetchAssociative('SELECT status, product_id, period, expires_at, unpaid_invoice_id, config FROM client_order WHERE id = ?' . (self::isMysql($db) ? ' FOR UPDATE' : ''), [$orderId]);
                if ($row === false) {
                    throw new InformationException('The service no longer exists.');
                }
                $opt = self::upgradeOptions($db, $id);
                $changed = self::changedSince($row, $up);
                if ($changed === null && $opt !== null && !self::sameChoice(self::optionsOf($row['config'])['choice'], $opt['from'])) {
                    $changed = 'other options';
                }
                // Only the options change: same product and cycle.
                $optionsOnly = $opt !== null && (int) $up['to_product_id'] === (int) $up['from_product_id'] && (string) $up['to_period'] === (string) $up['from_period'];
                $now ??= date('Y-m-d H:i:s');
                if ($changed === null && (string) $row['expires_at'] !== '' && strtotime((string) $row['expires_at']) <= strtotime($now)) {
                    $changed = 'it has expired';
                }
                // ClientFence (QA r10 D-05, D-07): priced for the days left on the day it was asked; on a later day
                // (an invoice paid late, a failed change retried) it must not ask less or credit more than is due now.
                if ($changed === null) {
                    $changed = self::repriced($up, $now);
                }
                if ($changed !== null) {
                    throw new InformationException('The service changed after this ' . $up['kind'] . ' was priced (' . $changed . '). Change it by hand and settle the amount with the client.');
                }
                $order = $di['db']->load('ClientOrder', $orderId);
                $to = self::catalog($db, (int) $up['to_product_id'], false);
                if ($to === null) {
                    throw new InformationException('The new product no longer exists.');
                }
                $quantity = max(1, (int) ($order->quantity ?? 1));
                $productChanged = (int) $up['to_product_id'] !== (int) $up['from_product_id'];
                if ($productChanged) {
                    $productService = $di['mod_service']('product');
                    $productService->reduceStock((int) $up['to_product_id'], $quantity);
                    $productService->returnStock((int) $up['from_product_id'], $quantity);
                }
                // A renewal invoice issued at the old price and cycle must not renew the new one.
                $renewalNote = self::cancelRenewalInvoice($db, $order);

                $newPrice = round((float) $up['to_price'] / $quantity, 2);
                // ClientFence core patch: a changed price or cycle must not keep charging the old gateway subscription.
                if ((float) $order->price !== $newPrice || (string) $order->period !== (string) $up['to_period']) {
                    $di['mod_service']('Invoice', 'Subscription')->cancelForOrder($order);
                }

                if ($order->service_type === 'hosting' && !$optionsOnly) {
                    self::moveHosting($di, $order, $to);
                }

                $config = json_decode((string) $order->config, true);
                $config = is_array($config) ? $config : [];
                if ($productChanged) {
                    $config = self::productConfig($db, $order, $to, $config);
                }
                if ($opt !== null) {
                    $before = Options::summary(self::optionsOf($row['config'])['lines']);
                    $after = Options::summary($opt['lines']);
                    if ($before !== $after) {
                        $optionsNote = sprintf('; options: %s to %s', $before !== '' ? $before : 'none', $after !== '' ? $after : 'none');
                    }
                    if ($opt['to'] === []) {
                        unset($config['options'], $config['option_lines']);
                    } else {
                        $config['options'] = $opt['to'];
                        $config['option_lines'] = $opt['lines'];
                    }
                }
                $config['product_id'] = (int) $up['to_product_id'];
                $config['period'] = (string) $up['to_period'];
                $config['title'] = (string) $up['to_title'];
                $config = self::hostingConfig($order, $to, $config);
                $fromProduct = self::catalog($db, (int) $up['from_product_id'], false);
                $order->product_id = (int) $up['to_product_id'];
                if (!$optionsOnly) {
                    $order->title = self::newTitle((string) $order->title, (string) $up['from_title'], (string) $up['to_title'], $fromProduct['title'] ?? '');
                }
                $order->price = $newPrice;
                $order->period = (string) $up['to_period'];
                $order->expires_at = (string) $up['to_expires_at'];
                $order->config = json_encode($config);
                $order->updated_at = date('Y-m-d H:i:s');
                $di['db']->store($order);
                if ((float) $up['amount'] < 0) {
                    $client = $di['db']->getExistingModelById('Client', (int) $order->client_id, 'Client not found');
                    $source = self::creditSource($db, $orderId);
                    $rate = $source === null ? 0.0 : (float) $source['rate'];
                    $net = abs((float) $up['amount']);
                    $description = sprintf('Credit for the unused time of order #%d (%s)', $orderId, $up['kind']);
                    $di['mod_service']('client')->addFunds($client, self::creditWithTax($net, $rate), $description, ['type' => 'credit', 'rel_id' => $orderId]);
                    // A credit note from the invoice whose rate was used, so the sales tax report shows the tax given back.
                    // Named after the service: the first refund of any of its invoices takes the credit back.
                    if ($source !== null) {
                        $di['mod_service']('invoice')->creditNote((int) $source['id'], $description, $net, $rate > 0, sprintf('Account credit for the %s of order #%d.', $up['kind'], $orderId), $orderId);
                    }
                }
                $db->update(self::TABLE, ['status' => self::STATUS_APPLIED, 'error' => null, 'applied_at' => date('Y-m-d H:i:s')], ['id' => $id]);
                $db->commit();
            } catch (\Throwable $e) {
                if ($db->isTransactionActive()) {
                    $db->rollBack();
                }

                throw $e;
            }
        } catch (\Throwable $e) {
            $db->update(self::TABLE, ['status' => self::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 2000)], ['id' => $id]);
            ActionFailures::failed($di, $orderId, self::ACTION, $e);
            self::log($di, sprintf('Order #%d: %s #%d failed: %s', $orderId, $up['kind'], $id, $e->getMessage()));

            return false;
        } finally {
            if ($locked) {
                $di['dbal']->fetchOne('SELECT RELEASE_LOCK(?)', ['fb:credit_payment:client:' . (int) $up['client_id']]);
            }
            if ($renewalLocked) {
                $db->fetchOne('SELECT RELEASE_LOCK(?)', [$renewalLock]);
            }
        }

        // Done: what follows only reports it, and never undoes it.
        ActionFailures::succeeded($di, $orderId, self::ACTION);
        try {
            $what = $optionsOnly
                ? sprintf('%s of the options%s', ucfirst((string) $up['kind']), $optionsNote)
                : sprintf('%s from %s (%s) to %s (%s)', ucfirst((string) $up['kind']), $up['from_title'], $up['from_period'], $up['to_title'], $up['to_period']) . $optionsNote;
            $di['mod_service']('order')->saveStatusChange($order, $what . $renewalNote);
            $di['events_manager']->fire(['event' => 'onAfterOrderUpgrade', 'params' => ['id' => $orderId, 'upgrade_id' => $id, 'kind' => $up['kind'], 'from_product_id' => (int) $up['from_product_id'], 'to_product_id' => (int) $up['to_product_id']] + ($opt !== null ? ['options_changed' => $optionsNote !== ''] : [])]);
        } catch (\Throwable $e) {
            self::log($di, sprintf('Order #%d: after %s #%d: %s', $orderId, $up['kind'], $id, $e->getMessage()));
        }
        self::log($di, sprintf('Order #%d: %s to %s (%s) applied%s', $orderId, $up['kind'], $up['to_title'], $up['to_period'], $optionsNote));

        return true;
    }

    /** Staff retry from Orders > Failed actions: the order's last failed upgrade again. */
    public static function retryForOrder(\Pimple\Container $di, int $orderId): void
    {
        $id = self::db($di)->fetchOne('SELECT id FROM ' . self::TABLE . ' WHERE order_id = ? AND status = ? ORDER BY id DESC LIMIT 1', [$orderId, self::STATUS_FAILED]);
        if ($id === false) {
            throw new InformationException('This service has no failed upgrade to retry.');
        }
        if (!self::apply($di, (int) $id)) {
            $error = (string) self::db($di)->fetchOne('SELECT error FROM ' . self::TABLE . ' WHERE id = ?', [(int) $id]);

            throw new InformationException($error !== '' ? $error : 'The upgrade failed again.');
        }
    }

    /**
     * After each cron run: paid upgrades the invoice event missed are applied; waiting ones whose
     * invoice was removed or cancelled are closed. Returns how many were applied.
     */
    public static function sweep(\Pimple\Container $di): int
    {
        $db = self::db($di);
        $applied = 0;
        try {
            $rows = $db->fetchAllAssociative('SELECT u.id, i.status AS invoice_status FROM ' . self::TABLE . ' u LEFT JOIN invoice i ON i.id = u.invoice_id WHERE u.status = ? AND u.invoice_id IS NOT NULL', [self::STATUS_PENDING]);
        } catch (\Exception) {
            return 0;
        }
        foreach ($rows as $row) {
            if ($row['invoice_status'] === \Model_Invoice::STATUS_PAID) {
                $applied += self::apply($di, (int) $row['id']) ? 1 : 0;
            } elseif ($row['invoice_status'] === null || in_array($row['invoice_status'], [\Model_Invoice::STATUS_CANCELED, \Model_Invoice::STATUS_REFUNDED], true)) {
                $db->update(self::TABLE, ['status' => self::STATUS_CANCELLED], ['id' => (int) $row['id'], 'status' => self::STATUS_PENDING]);
            }
        }
        // A claim left by a run that died midway goes back to failed for staff to look at.
        $db->executeStatement('UPDATE ' . self::TABLE . ' SET status = ?, error = ? WHERE status = ? AND claimed_at < ?', [self::STATUS_FAILED, 'Stopped while being applied: check the service, then retry.', self::STATUS_APPLYING, date('Y-m-d H:i:s', time() - 3600)]);

        return $applied;
    }

    /**
     * A service's upgrades, newest first (staff: the order page).
     *
     * @return list<array<string, mixed>>
     */
    public static function history(\Pimple\Container $di, int $orderId): array
    {
        try {
            return self::db($di)->fetchAllAssociative('SELECT * FROM ' . self::TABLE . ' WHERE order_id = ? ORDER BY id DESC LIMIT 50', [$orderId]);
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * The service's change waiting for payment, for the client's service page: the new product and
     * cycle, the amount and the invoice (number as FOSSBilling prints it, and its hash for the
     * link), or null.
     *
     * @return array{kind: string, title: string, period: string, amount: float, currency: string, invoice_number: string, invoice_hash: string}|null
     */
    public static function waiting(\Pimple\Container $di, int $orderId): ?array
    {
        try {
            $row = self::db($di)->fetchAssociative(
                'SELECT u.kind, u.to_title, u.to_period, u.amount, u.currency, i.id AS invoice_id, i.serie, i.nr, i.hash FROM ' . self::TABLE . ' u'
                . ' JOIN invoice i ON i.id = u.invoice_id WHERE u.order_id = ? AND u.status = ? AND i.status = ? ORDER BY u.id DESC LIMIT 1',
                [$orderId, self::STATUS_PENDING, \Model_Invoice::STATUS_UNPAID]
            );
        } catch (\Exception) {
            return null;
        }
        if ($row === false) {
            return null;
        }
        try {
            $padding = $di['mod_service']('system')->getParamValue('invoice_number_padding');
        } catch (\Throwable) {
            $padding = null;
        }
        // As Invoice\Service prints serie_nr.
        $nr = is_numeric($row['nr']) ? (int) $row['nr'] : (int) $row['invoice_id'];
        // What the invoice asks for: tax included for a taxable client.
        $amount = (float) $row['amount'];
        try {
            $invoice = $di['db']->load('Invoice', (int) $row['invoice_id']);
            if ($invoice instanceof \Model_Invoice) {
                $amount = (float) $di['mod_service']('invoice')->getTotalWithTax($invoice);
            }
        } catch (\Throwable) {
            // The upgrade's own amount (before tax) then.
        }

        return [
            'kind' => (string) $row['kind'],
            'title' => (string) $row['to_title'],
            'period' => (string) $row['to_period'],
            'amount' => round($amount, 2),
            'currency' => (string) $row['currency'],
            'invoice_number' => $row['serie'] . sprintf('%0' . ($padding !== null && $padding !== '' ? (int) $padding : 5) . 's', $nr),
            'invoice_hash' => (string) $row['hash'],
        ];
    }

    /**
     * ClientFence core patch: a renewal invoice replaces a plan change still waiting for payment. Its
     * price covered the rest of the old period, and once the renewal was paid it could never apply
     * (the client paid for a change they did not get). Its unpaid invoice is cancelled with it.
     */
    public static function cancelWaiting(\Pimple\Container $di, int $orderId, string $why = 'Replaced by the renewal invoice.'): void
    {
        $db = $di['dbal'];
        $rows = $db->fetchAllAssociative('SELECT u.id, u.invoice_id FROM ' . self::TABLE . ' u JOIN invoice i ON i.id = u.invoice_id WHERE u.order_id = ? AND u.status = ? AND i.status = ?', [$orderId, self::STATUS_PENDING, \Model_Invoice::STATUS_UNPAID]);
        foreach ($rows as $row) {
            $db->update('invoice', ['status' => \Model_Invoice::STATUS_CANCELED, 'updated_at' => date('Y-m-d H:i:s')], ['id' => (int) $row['invoice_id'], 'status' => \Model_Invoice::STATUS_UNPAID]);
            $db->update(self::TABLE, ['status' => self::STATUS_CANCELLED, 'error' => $why], ['id' => (int) $row['id'], 'status' => self::STATUS_PENDING]);
        }
    }

    /** Why a service cannot change now, or null. */
    public static function blocker(\Pimple\Container $di, \Model_ClientOrder $order, ?string $now = null): ?string
    {
        if ($order->status !== \Model_ClientOrder::STATUS_ACTIVE) {
            return __trans('Only active services can be changed.');
        }
        if (!in_array((string) $order->service_type, self::KINDS, true)) {
            return __trans('This kind of service cannot be changed here.');
        }
        if ((string) $order->period === '' || (string) $order->expires_at === '') {
            return __trans('Only services billed by period can be changed.');
        }
        // ClientFence core patch: a change cancels the gateway subscription that ends the service at the period end.
        if (self::db($di)->fetchOne('SELECT value FROM client_order_meta WHERE client_order_id = ? AND name = ?', [(int) $order->id, Service::META_CANCEL_AT_PERIOD_END]) === '1') {
            return __trans('This service is set to be cancelled at the end of its period, so it cannot be changed.');
        }
        $db = self::db($di);
        if ($order->unpaid_invoice_id !== null && $db->fetchOne('SELECT status FROM invoice WHERE id = ?', [(int) $order->unpaid_invoice_id]) === \Model_Invoice::STATUS_UNPAID) {
            return __trans('Pay the open invoice of this service first.');
        }
        // ClientFence core patch: a plan change cannot replace a subscription the gateway cannot cancel.
        $subscriptions = $db->fetchAllAssociative(
            'SELECT DISTINCT s.pay_gateway_id, s.sid FROM subscription s JOIN invoice_item ii ON ii.invoice_id = s.rel_id
             WHERE s.rel_type = ? AND s.status = ? AND ii.type = ? AND ii.rel_id = ?',
            ['invoice', 'active', \Model_InvoiceItem::TYPE_ORDER, (int) $order->id],
        );
        foreach ($subscriptions as $subscription) {
            if (trim((string) $subscription['sid']) === '') {
                continue;
            }
            $gateway = $di['db']->getExistingModelById('PayGateway', (int) $subscription['pay_gateway_id'], 'Payment gateway not found');
            $adapter = $di['mod_service']('Invoice', 'PayGateway')->getPaymentAdapter($gateway);
            if (!method_exists($adapter, 'cancelSubscription')) {
                return __trans('This service is paid by a PayPal subscription: cancel it in PayPal first, then change the plan.');
            }
        }
        $client = $db->fetchOne('SELECT currency FROM client WHERE id = ?', [(int) $order->client_id]);
        if ($client === false || $client === null || $client === '' || strtoupper((string) $client) !== strtoupper((string) $order->currency)) {
            return __trans('This service is billed in another currency. Please contact us.');
        }
        try {
            $waiting = $db->fetchOne('SELECT u.id FROM ' . self::TABLE . ' u LEFT JOIN invoice i ON i.id = u.invoice_id WHERE u.order_id = ? AND (u.status = ? OR (u.status = ? AND i.status = ?)) LIMIT 1', [(int) $order->id, self::STATUS_APPLYING, self::STATUS_PENDING, \Model_Invoice::STATUS_UNPAID]);
        } catch (\Exception) {
            $waiting = false;
        }
        if ($waiting !== false) {
            return self::waitingMessage();
        }

        return null;
    }

    /**
     * A product as the upgrade code needs it, or null when it is not recurring (or, with $onSale,
     * not on sale). With $currency, its prices are the default-currency equivalents of the fixed
     * prices it has in that currency (Product/CurrencyPrices.php).
     *
     * @return array{id: int, title: string, type: string, is_addon: bool, on_sale: bool, config: array<string, mixed>, upgrades: list<int>, prices: array<string, float>}|null
     */
    public static function catalog(Connection $db, int $productId, bool $onSale = true, ?string $currency = null): ?array
    {
        $columns = [];
        foreach (array_keys(self::PERIODS) as $key) {
            $columns[] = "pp.{$key}_price, pp.{$key}_enabled";
        }
        $row = $db->fetchAssociative(
            'SELECT p.id, p.title, p.type, p.status, p.is_addon, p.config, p.upgrades, pp.type AS payment_type, ' . implode(', ', $columns)
            . ' FROM product p JOIN product_payment pp ON pp.id = p.product_payment_id WHERE p.id = ?',
            [$productId]
        );
        if ($row === false || ($onSale && $row['status'] !== 'enabled') || $row['payment_type'] !== 'recurrent' || !in_array((string) $row['type'], self::KINDS, true)) {
            return null;
        }
        $prices = [];
        foreach (self::PERIODS as $key => $period) {
            // FOSSBilling enables every cycle at 0 by default: a free cycle is never offered.
            if ((int) ($row[$key . '_enabled'] ?? 0) === 1 && (float) $row[$key . '_price'] > 0) {
                $prices[$period] = \ClientFence\Mod\Product\CurrencyPrices::base($db, 'product', $productId, $currency, $period, 'price', (float) $row[$key . '_price']);
            }
        }
        $upgrades = json_decode((string) $row['upgrades'], true);
        $config = json_decode((string) $row['config'], true);

        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'type' => (string) $row['type'],
            'is_addon' => (bool) (int) $row['is_addon'],
            'on_sale' => $row['status'] === 'enabled',
            'config' => is_array($config) ? $config : [],
            'upgrades' => is_array($upgrades) ? array_values(array_filter(array_map('intval', $upgrades), static fn (int $id): bool => $id > 0)) : [],
            'prices' => $prices,
        ];
    }

    /**
     * Same kind of product (and both add-ons or neither); hosting stays on its server and keeps
     * reseller or not.
     *
     * @param array<string, mixed> $from
     * @param array<string, mixed> $to
     */
    public static function compatible(array $from, array $to): bool
    {
        if ($from['type'] !== $to['type'] || $from['is_addon'] !== $to['is_addon']) {
            return false;
        }
        if ($from['type'] === 'hosting') {
            return (int) ($from['config']['server_id'] ?? 0) === (int) ($to['config']['server_id'] ?? 0)
                && (int) ($to['config']['hosting_plan_id'] ?? 0) > 0
                && (bool) ($from['config']['reseller'] ?? false) === (bool) ($to['config']['reseller'] ?? false);
        }

        return true;
    }

    /**
     * A change of the service's configurable options alone (same product and cycle), priced as
     * WHMCS does: the recurring price moves by the options' difference (at today's rate) and the
     * client pays, or is credited, that difference for the days left. Values the service has stay
     * allowed when they are hidden. The same checks as options() apply.
     *
     * @param array<int|string, mixed> $choice option id => value id, 0/1 or a quantity
     *
     * @return array<string, mixed> an options() entry with `options_change` set
     */
    public static function optionsQuote(\Pimple\Container $di, \Model_ClientOrder $order, array $choice, ?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');
        if (self::waiting($di, (int) $order->id) !== null) {
            throw new InformationException(self::waitingMessage());
        }
        $settings = self::settings($di);
        if (!$settings['upgrades'] && !$settings['downgrades']) {
            throw new InformationException('Upgrades and downgrades are not available.');
        }
        $reason = self::blocker($di, $order, $now);
        if ($reason !== null) {
            throw new InformationException($reason);
        }
        $db = self::db($di);
        $product = self::catalog($db, (int) $order->product_id, false);
        $rate = self::rate($db, (string) $order->currency);
        if ($product === null || $rate === null || Options::forProduct($di, (int) $order->product_id) === []) {
            throw new InformationException('The options of this service cannot be changed here. Please contact us.');
        }
        $own = self::orderOptions($order);
        $new = Options::resolve($di, (int) $order->product_id, $choice, (string) $order->period, $own['choice']);
        if (self::sameChoice($new['choice'], $own['choice'])) {
            throw new InformationException('Choose other options than the ones this service has.');
        }
        $quantity = max(1, (int) ($order->quantity ?? 1));
        // ClientFence (QA r8 P-01): price both choices for today's cycle so unchanged options do not add a cycle difference.
        $current = self::currentOptionsPrice($di, (int) $order->product_id, $own, (string) $order->period);
        $delta = ($new['price'] - $current) * $rate * $quantity;
        $newTotal = round(self::orderTotal($order) + $delta, 2);
        // Only the options' difference is charged or credited for the days left.
        $paid = self::recurringTotal($di, $order);
        if ($newTotal < 0 || round($paid + $delta, 2) < 0) {
            throw new InformationException('The price of this service is lower than its options, so it is changed by our staff. Please contact us.');
        }
        $quote = Proration::quote($paid, (string) $order->period, (string) $order->expires_at, round($paid + $delta, 2), (string) $order->period, $now, $paid, self::periodAnchor(self::db($di), $order));
        if (!self::allowedBy($settings, self::rule($di, self::SCOPE_PRODUCT, (int) $order->product_id), self::rule($di, self::SCOPE_ORDER, (int) $order->id), $quote['kind'])) {
            throw new InformationException('This change is not available for this service.');
        }

        return [
            'product_id' => (int) $order->product_id,
            'title' => $product['title'],
            'period' => (string) $order->period,
            'price' => $newTotal,
            'same_product' => true,
            'options' => $new['choice'],
            'option_lines' => $new['lines'],
            'options_change' => true,
        ] + self::quoteWithTax($di, $order, $quote);
    }

    /**
     * The service's configurable options for staff (the order's Configurable options tab): every
     * option of its product, hidden ones too, with the service's choice and lines.
     *
     * @return array{options: list<array<string, mixed>>, choice: array<int, int>, lines: list<array<string, mixed>>}
     */
    public static function staffView(\Pimple\Container $di, int $orderId): array
    {
        $order = $di['db']->getExistingModelById('ClientOrder', $orderId, 'Order not found');
        $own = self::orderOptions($order);

        return ['options' => Options::forProduct($di, (int) $order->product_id, true), 'choice' => $own['choice'], 'lines' => $own['lines']];
    }

    /**
     * Staff set a service's configurable options: any value, hidden ones too (an empty value leaves
     * a hidden option unset). With $reprice the recurring price moves by the options' difference
     * (at today's rate); no invoice is made. The history gets the change.
     *
     * @param array<int|string, mixed> $choice option id => value id, 0/1 or a quantity
     */
    public static function staffSetOptions(\Pimple\Container $di, int $orderId, array $choice, bool $reprice): void
    {
        $order = $di['db']->getExistingModelById('ClientOrder', $orderId, 'Order not found');
        $choice = array_filter($choice, static fn (mixed $value): bool => $value !== '' && $value !== null);
        $own = self::orderOptions($order);
        $period = (string) $order->period !== '' ? (string) $order->period : 'once';
        $new = Options::resolve($di, (int) $order->product_id, $choice, $period, $own['choice'], true);
        if (self::sameChoice($new['choice'], $own['choice'])) {
            throw new InformationException('The options are unchanged.');
        }

        $config = json_decode((string) $order->config, true);
        $config = is_array($config) ? $config : [];
        if ($new['choice'] === []) {
            unset($config['options'], $config['option_lines']);
        } else {
            $config['options'] = $new['choice'];
            $config['option_lines'] = $new['lines'];
        }
        $note = sprintf('Options changed by staff: %s to %s', Options::summary($own['lines']) ?: 'none', Options::summary($new['lines']) ?: 'none');
        if ($reprice) {
            $rate = self::rate(self::db($di), (string) $order->currency);
            if ($rate === null) {
                throw new InformationException('The currency of this service is unknown, so its price cannot change with the options.');
            }
            $old = round((float) $order->price, 2);
            // ClientFence (QA r8 P-01): staff repricing uses today's prices and cycle for both choices too.
            $current = self::currentOptionsPrice($di, (int) $order->product_id, $own, $period, true);
            $order->price = max(0.0, round($old + ($new['price'] - $current) * $rate, 2));
            $note .= sprintf('; price %s to %s %s', number_format($old, 2, '.', ''), number_format((float) $order->price, 2, '.', ''), $order->currency);
        }
        $order->config = json_encode($config);
        $order->updated_at = date('Y-m-d H:i:s');
        $di['db']->store($order);
        $di['mod_service']('order')->saveStatusChange($order, $note);
        self::log($di, sprintf('Order #%d: %s', $orderId, $note));
    }

    /**
     * The configurable options a service has: its choice and the priced lines it was sold with
     * (in the default currency), from its config.
     *
     * @return array{choice: array<int, int>, lines: list<array<string, mixed>>, price: float}
     */
    public static function orderOptions(\Model_ClientOrder $order): array
    {
        return self::optionsOf($order->config);
    }

    /**
     * @return array{choice: array<int, int>, lines: list<array<string, mixed>>, price: float}
     */
    private static function optionsOf(mixed $config): array
    {
        $config = json_decode((string) $config, true);
        $choice = self::choiceOf($config['options'] ?? null);
        $lines = is_array($config['option_lines'] ?? null) ? array_values(array_filter($config['option_lines'], 'is_array')) : [];
        $price = 0.0;
        foreach ($lines as $line) {
            $price += (float) ($line['price'] ?? 0);
        }

        return ['choice' => $choice, 'lines' => $lines, 'price' => round($price, 2)];
    }

    /** @param array{choice: array<int, int>, lines: list<array<string, mixed>>, price: float} $own */
    private static function currentOptionsPrice(\Pimple\Container $di, int $productId, array $own, string $period, bool $staff = false): float
    {
        // ClientFence (QA r8 P-01 R1/R2): only stored lines were paid for; unavailable choices keep their sold price.
        $total = 0.0;
        foreach ($own['lines'] as $line) {
            $price = (float) ($line['price'] ?? 0);
            $optionId = (int) ($line['option_id'] ?? 0);
            if (isset($own['choice'][$optionId])) {
                $choice = [$optionId => $own['choice'][$optionId]];
                try {
                    $resolved = Options::resolve($di, $productId, $choice, $period, $choice, $staff);
                    foreach ($resolved['lines'] as $current) {
                        if ((int) $current['option_id'] === $optionId) {
                            $price = (float) $current['price'];

                            break;
                        }
                    }
                } catch (InformationException) {
                }
            }
            $total += $price;
        }

        return round($total, 2);
    }

    /**
     * The options a service keeps on a product and cycle: its choice for the options that product
     * also has (the others take their defaults), priced for the cycle; null when the choice does
     * not fit that product (a quantity out of its range, a value it no longer offers).
     *
     * @param array<int, int> $choice
     *
     * @return array{choice: array<int, int>, lines: list<array<string, mixed>>, price: float}|null
     */
    private static function optionsFor(\Pimple\Container $di, int $productId, array $choice, string $period): ?array
    {
        $offered = array_map('intval', array_column(Options::forProduct($di, $productId, true), 'id'));
        if ($offered === []) {
            return ['choice' => [], 'lines' => [], 'price' => 0.0];
        }
        $kept = array_intersect_key($choice, array_flip($offered));
        try {
            $resolved = Options::resolve($di, $productId, $kept, $period, $kept);
        } catch (InformationException) {
            return null;
        }

        return ['choice' => $resolved['choice'], 'lines' => $resolved['lines'], 'price' => $resolved['price']];
    }

    /**
     * The options an upgrade sets, or null for an upgrade without them.
     *
     * @return array{from: array<int, int>, to: array<int, int>, lines: list<array<string, mixed>>}|null
     */
    private static function upgradeOptions(Connection $db, int $id): ?array
    {
        try {
            $row = $db->fetchAssociative('SELECT from_options, to_options, to_option_lines FROM ' . self::OPTIONS_TABLE . ' WHERE upgrade_id = ?', [$id]);
        } catch (\Exception) {
            return null;
        }
        if ($row === false) {
            return null;
        }
        $lines = json_decode((string) $row['to_option_lines'], true);

        return ['from' => self::choiceOf(json_decode((string) $row['from_options'], true)), 'to' => self::choiceOf(json_decode((string) $row['to_options'], true)), 'lines' => is_array($lines) ? array_values(array_filter($lines, 'is_array')) : []];
    }

    /**
     * @param array<int, int> $a
     * @param array<int, int> $b
     */
    private static function sameChoice(array $a, array $b): bool
    {
        ksort($a);
        ksort($b);

        return $a === $b;
    }

    /**
     * A stored choice: option id => value id, 0/1 or a quantity.
     *
     * @return array<int, int>
     */
    private static function choiceOf(mixed $choice): array
    {
        $out = [];
        foreach (is_array($choice) ? $choice : [] as $optionId => $value) {
            if (is_numeric($optionId) && is_numeric($value)) {
                $out[(int) $optionId] = (int) $value;
            }
        }

        return $out;
    }

    /**
     * The order's new title: the old product title in it is swapped for the new one (a hosting
     * order's title can carry its domain), else the new product title.
     */
    public static function newTitle(string $orderTitle, string $fromTitle, string $toTitle, string $fromProductTitle): string
    {
        foreach ([$fromProductTitle, $fromTitle] as $old) {
            if ($old !== '' && str_contains($orderTitle, $old)) {
                return mb_substr(str_replace($old, $toTitle, $orderTitle), 0, 255);
            }
        }

        return mb_substr($toTitle, 0, 255);
    }

    /**
     * What changed on the order (its locked row) since it was priced, or null.
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed> $up
     */
    private static function changedSince(array $order, array $up): ?string
    {
        if ($order['status'] !== \Model_ClientOrder::STATUS_ACTIVE) {
            return 'it is ' . str_replace('_', ' ', (string) $order['status']);
        }
        if ((int) $order['product_id'] !== (int) $up['from_product_id']) {
            return 'another product';
        }
        if ((string) $order['period'] !== (string) $up['from_period']) {
            return 'another billing cycle';
        }
        if (substr((string) $order['expires_at'], 0, 10) !== substr((string) $up['from_expires_at'], 0, 10)) {
            return 'renewed or its expiry date was changed';
        }

        return null;
    }

    /**
     * QA r10 D-05, D-07: the change's amount for the days left now, from what it was priced with (the credit and,
     * on the same cycle, the charge scale with the days left; a new cycle's charge does not). Why it must go to
     * staff when that asks more or credits less than the change holds; null when it does not.
     */
    private static function repriced(array $up, string $now): ?string
    {
        $then = (int) $up['days_left'];
        $left = Proration::daysLeft((string) $up['from_expires_at'], $now);
        if ($then <= 0 || $left >= $then) {
            return null;
        }
        $credit = round((float) $up['credit'] * $left / $then, 2);
        $charge = (string) $up['to_period'] === (string) $up['from_period'] ? round((float) $up['charge'] * $left / $then, 2) : (float) $up['charge'];

        return round($charge - $credit, 2) > round((float) $up['amount'], 2) + 0.01 ? sprintf('priced for %d days left, %d now', $then, $left) : null;
    }

    /**
     * QA r10 D-01: a day a period of the service began, for Proration to walk its periods from: the day its last change
     * of cycle applied (that period ends on the change's expiry), the change's expiry (renewals go on from it), or else
     * its activation.
     */
    private static function periodAnchor(Connection $db, \Model_ClientOrder $order): ?string
    {
        $change = $db->fetchAssociative('SELECT applied_at, to_expires_at FROM ' . self::TABLE . " WHERE order_id = ? AND status = ? AND to_period <> from_period AND applied_at IS NOT NULL AND applied_at <> '' ORDER BY applied_at DESC, id DESC LIMIT 1", [(int) $order->id, self::STATUS_APPLIED]);
        if ($change !== false) {
            return substr((string) $change['to_expires_at'], 0, 10) === substr((string) $order->expires_at, 0, 10) ? (string) $change['applied_at'] : (string) $change['to_expires_at'];
        }

        return $order->activated_at !== null && $order->activated_at !== '' ? (string) $order->activated_at : null;
    }

    /** QA r10 D-09: takes the client's money lock (MySQL only, as Tools::withLock()); false when there is none to take. */
    private static function moneyLock(\Pimple\Container $di, int $clientId): bool
    {
        $dbal = isset($di['dbal']) ? $di['dbal'] : null;
        if (!$dbal instanceof Connection || !self::isMysql($dbal)) {
            return false;
        }
        if ((int) $dbal->fetchOne('SELECT GET_LOCK(?, 10)', ['fb:credit_payment:client:' . $clientId]) !== 1) {
            throw new InformationException('Another request is being processed. Please try again.');
        }

        return true;
    }

    /**
     * Cancels the order's unpaid renewal invoice (issued at the old price and cycle; the cron
     * issues a new one when the new period is due) and returns a note for the order history. An
     * invoice that also bills something else is left to staff: the change fails.
     */
    private static function cancelRenewalInvoice(Connection $db, \Model_ClientOrder $order): string
    {
        if ($order->unpaid_invoice_id === null || (int) $order->unpaid_invoice_id === 0) {
            return '';
        }
        $invoiceId = (int) $order->unpaid_invoice_id;
        if ($db->fetchOne('SELECT status FROM invoice WHERE id = ?', [$invoiceId]) !== \Model_Invoice::STATUS_UNPAID) {
            return '';
        }
        $other = (int) $db->fetchOne(
            'SELECT COUNT(*) FROM invoice_item WHERE invoice_id = ? AND NOT (type = ? AND rel_id = ?)',
            [$invoiceId, \Model_InvoiceItem::TYPE_ORDER, (string) (int) $order->id]
        );
        if ($other > 0) {
            throw new InformationException(sprintf('Unpaid invoice #%d bills this service at its old price together with other items. Cancel or edit it, then retry.', $invoiceId));
        }
        $db->update('invoice', ['status' => \Model_Invoice::STATUS_CANCELED, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $invoiceId]);
        $order->unpaid_invoice_id = null;

        return sprintf(' (unpaid renewal invoice #%d at the old price cancelled)', $invoiceId);
    }

    private static function isMysql(Connection $db): bool
    {
        return $db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
    }

    /** The hosting account moves to the new product's plan on its panel. */
    /**
     * The service config and records a new product sets: a download's file, a license's validation.
     *
     * @param array<string, mixed> $to     a catalog() entry
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function productConfig(Connection $db, \Model_ClientOrder $order, array $to, array $config): array
    {
        if ($order->service_type === 'downloadable') {
            $config['filename'] = $to['config']['filename'];
            $config['stored_filename'] = $to['config']['stored_filename'];
            $db->update('service_downloadable', [
                'filename' => $config['filename'],
                'stored_filename' => $config['stored_filename'],
            ], ['id' => (int) $order->service_id]);
        }
        if ($order->service_type === 'license') {
            // ClientFence core patch: license validation follows the target plan, including settings it disables.
            $licenseConfig = [];
            foreach (['validate_ip', 'validate_host', 'validate_path', 'validate_version'] as $key) {
                $config[$key] = (bool) ($to['config'][$key] ?? false);
                $licenseConfig[$key] = (int) $config[$key];
            }
            $licenseConfig['plugin'] = $config['plugin'] = $to['config']['plugin'] ?? 'Simple';
            $db->update('service_license', $licenseConfig, ['id' => (int) $order->service_id]);
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $to     a catalog() entry
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function hostingConfig(\Model_ClientOrder $order, array $to, array $config): array
    {
        if ($order->service_type === 'hosting') {
            foreach (['server_id', 'hosting_plan_id', 'reseller'] as $key) {
                if (array_key_exists($key, $to['config'])) {
                    $config[$key] = $to['config'][$key];
                }
            }
        }

        return $config;
    }

    /**
     * The products staff can move a service to (staffMove()): the other recurring products it is compatible with.
     *
     * @return array<int, string> id => title
     */
    public static function moveTargets(\Pimple\Container $di, \Model_ClientOrder $order): array
    {
        $db = self::db($di);
        $from = self::catalog($db, (int) $order->product_id, false);
        if ($from === null) {
            return [];
        }
        $targets = [];
        foreach ($db->fetchFirstColumn('SELECT id FROM product WHERE type = ? AND id <> ? ORDER BY title', [$from['type'], (int) $order->product_id]) as $id) {
            $to = self::catalog($db, (int) $id, false);
            if ($to !== null && self::compatible($from, $to)) {
                $targets[(int) $id] = $to['title'];
            }
        }

        return $targets;
    }

    /**
     * ClientFence: staff move a service to another product, as WHMCS (Products/Services tab: a new Product/Service,
     * "Auto Recalculate on Save", then the Change Package module command). No invoice is made. Its options stay where
     * the new product has them. With $reprice the recurring price is the new product's for the service's cycle (with
     * its options) at today's rate; with $changePackage a hosting account moves to the new product's plan on the
     * panel first, so a panel failure changes nothing. The history gets the change.
     */
    public static function staffMove(\Pimple\Container $di, int $orderId, int $productId, bool $reprice, bool $changePackage): void
    {
        $db = self::db($di);
        $order = $di['db']->getExistingModelById('ClientOrder', $orderId, 'Order not found');
        if (!isset(self::moveTargets($di, $order)[$productId])) {
            throw new InformationException('The service can move only to another recurring product of the same type (hosting: on the same server, and reseller or not as now).');
        }
        $from = self::catalog($db, (int) $order->product_id, false, (string) $order->currency);
        $to = self::catalog($db, $productId, false, (string) $order->currency);
        $period = (string) $order->period;
        $options = self::optionsFor($di, $productId, self::orderOptions($order)['choice'], $period !== '' ? $period : 'once');
        if ($options === null) {
            throw new InformationException('The options of this service do not fit the new product. Change them first.');
        }
        $old = round((float) $order->price, 2);
        $price = $old;
        if ($reprice) {
            $rate = self::rate($db, (string) $order->currency);
            if ($rate === null || !isset($to['prices'][$period])) {
                throw new InformationException('The new product has no price for the billing cycle of this service, so the price cannot be recalculated.');
            }
            $price = round(($to['prices'][$period] + $options['price']) * $rate, 2);
        }
        // The stock first: out of stock refuses before the panel changes; a panel failure gives it back.
        $quantity = max(1, (int) ($order->quantity ?? 1));
        $productService = $di['mod_service']('product');
        $productService->reduceStock($productId, $quantity);
        if ($changePackage && $order->service_type === 'hosting') {
            try {
                self::moveHosting($di, $order, $to);
            } catch (\Throwable $e) {
                $productService->returnStock($productId, $quantity);

                throw $e;
            }
        }
        $productService->returnStock((int) $order->product_id, $quantity);
        // A changed price must not keep charging the old gateway subscription.
        if ($price !== $old) {
            $di['mod_service']('Invoice', 'Subscription')->cancelForOrder($order);
        }

        $config = json_decode((string) $order->config, true);
        $config = self::productConfig($db, $order, $to, is_array($config) ? $config : []);
        if ($options['choice'] === []) {
            unset($config['options'], $config['option_lines']);
        } else {
            $config['options'] = $options['choice'];
            $config['option_lines'] = $options['lines'];
        }
        $config['product_id'] = $productId;
        $config['title'] = $to['title'];
        $config = self::hostingConfig($order, $to, $config);
        $order->product_id = $productId;
        $order->title = self::newTitle((string) $order->title, $from['title'], $to['title'], $from['title']);
        $order->price = $price;
        $order->config = json_encode($config);
        $order->updated_at = date('Y-m-d H:i:s');
        $di['db']->store($order);
        $note = sprintf('Moved by staff from %s to %s', $from['title'], $to['title']) . ($price !== $old ? sprintf('; price %s to %s %s', number_format($old, 2, '.', ''), number_format($price, 2, '.', ''), $order->currency) : '')
            . ($changePackage && $order->service_type === 'hosting' ? '; hosting plan changed on the panel' : '');
        $di['mod_service']('order')->saveStatusChange($order, $note);
        self::log($di, sprintf('Order #%d: %s', $orderId, $note));
    }

    private static function moveHosting(\Pimple\Container $di, \Model_ClientOrder $order, array $to): void
    {
        $hp = $di['db']->getExistingModelById('ServiceHostingHp', (int) ($to['config']['hosting_plan_id'] ?? 0), 'The hosting plan of the new product was not found');
        $service = $di['mod_service']('order')->getOrderService($order);
        if (!$service instanceof \Model_ServiceHosting) {
            throw new InformationException('The hosting account of this service was not found.');
        }
        $di['mod_service']('servicehosting')->changeAccountPlan($order, $service, $hp);
    }

    private static function orderTotal(\Model_ClientOrder $order): float
    {
        return round((float) $order->price * max(1, (int) ($order->quantity ?? 1)), 2);
    }

    /**
     * ClientFence core patch: the days left are credited at the service's recurring amount (its price less a recurring
     * promotion), per day of its cycle, as WHMCS does.
     */
    private static function recurringTotal(\Pimple\Container $di, \Model_ClientOrder $order): float
    {
        $promo = $order->promo_recurring && $order->promo_id
            ? $di['mod_service']('Product')->getRenewalPromoAdjustment($order, (float) $order->price, (float) max(1, (int) ($order->quantity ?? 1)))
            : null;

        return max(0.0, round(self::orderTotal($order) - (float) ($promo['discount_amount'] ?? 0), 2));
    }

    private static function rate(Connection $db, string $currency): ?float
    {
        $rate = $db->fetchOne('SELECT conversion_rate FROM currency WHERE code = ?', [$currency]);

        return $rate === false || (float) $rate <= 0 ? null : (float) $rate;
    }

    private static function periodDays(string $period): float
    {
        [$qty, $unit] = Proration::parsePeriod($period);

        return $qty * ['D' => 1, 'W' => 7, 'M' => 30.436875, 'Y' => 365.2425][$unit];
    }

    /** @param array<string, mixed> $chosen */
    private static function invoiceTitle(\Model_ClientOrder $order, array $chosen): string
    {
        $text = !empty($chosen['options_change'])
            ? sprintf('%s of order #%d: %s, options changed to %s', ucfirst((string) $chosen['kind']), (int) $order->id, $order->title, Options::summary($chosen['option_lines']) ?: 'none')
            : sprintf('%s of order #%d: %s (%s) to %s (%s)', ucfirst((string) $chosen['kind']), (int) $order->id, $order->title, $order->period, $chosen['title'], $chosen['period']);
        if ($chosen['credit'] > 0) {
            $text .= sprintf(', less %s %s unused (%d of %d days)', number_format((float) $chosen['credit'], 2, '.', ''), $order->currency, $chosen['days_left'], $chosen['days_total']);
        }

        return mb_substr($text, 0, 255);
    }

    private static function log(\Pimple\Container $di, string $message): void
    {
        try {
            $di['logger']->info('Upgrades: %s', $message);
        } catch (\Throwable) {
            // Logging never stops an upgrade.
        }
    }

    private static function db(\Pimple\Container $di): Connection
    {
        return $di['em']->getConnection();
    }
}
