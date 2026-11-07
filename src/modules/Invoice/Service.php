<?php

declare(strict_types=1);
/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Invoice;

use ClientFence\Mod\Client\Users;
use ClientFence\Mod\Currency\Entity\Currency;
use Dompdf\Dompdf;
use Dompdf\Options;
use ClientFence\Environment;
use ClientFence\Http\ResponseFactory;
use ClientFence\i18n;
use ClientFence\InformationException;
use ClientFence\InjectionAwareInterface;
use ClientFence\Tools;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Twig\Loader\FilesystemLoader;

class Service implements InjectionAwareInterface
{
    // ClientFence: the line task of a credit note for account credit (creditNote()); the sales tax report counts these.
    public const string CREDIT_NOTE_TASK = 'account_credit';
    // ClientFence: the task of such a line once a refund took its credit back (it leaves the sales tax report).
    public const string CREDIT_NOTE_TAKEN_BACK_TASK = 'account_credit_taken_back';

    /**
     * Columns on the `invoice` table permitted in CSV exports.
     * The `hash` column (bearer token for public invoice access) is excluded.
     */
    private const array EXPORTABLE_COLUMNS = [
        'id', 'client_id', 'serie', 'nr', 'currency', 'currency_rate',
        'credit', 'base_income', 'base_refund', 'refund', 'notes',
        'text_1', 'text_2', 'status', 'seller_company', 'seller_company_vat',
        'seller_company_number', 'seller_address', 'seller_phone', 'seller_email',
        'buyer_first_name', 'buyer_last_name', 'buyer_company', 'buyer_company_vat',
        'buyer_company_number', 'buyer_address', 'buyer_city', 'buyer_state',
        'buyer_country', 'buyer_zip', 'buyer_phone', 'buyer_phone_cc',
        'buyer_email', 'gateway_id', 'approved', 'taxname', 'taxrate',
        'due_at', 'reminded_at', 'paid_at', 'created_at', 'updated_at',
    ];

    /** Subset of EXPORTABLE_COLUMNS used when the caller passes no headers. */
    private const array DEFAULT_EXPORT_COLUMNS = [
        'id', 'client_id', 'nr', 'currency', 'credit', 'base_income', 'base_refund',
        'refund', 'notes', 'status', 'buyer_first_name', 'buyer_last_name',
        'buyer_company', 'buyer_company_vat', 'buyer_company_number', 'buyer_address',
        'buyer_city', 'buyer_state', 'buyer_country', 'buyer_zip', 'buyer_phone',
        'buyer_phone_cc', 'buyer_email', 'approved', 'taxname', 'taxrate',
        'due_at', 'reminded_at', 'paid_at',
    ];

    protected ?\Pimple\Container $di = null;
    private Filesystem $filesystem;
    private ?int $invoiceNumberPadding = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
        if (isset($di['filesystem'])) {
            $this->filesystem = $di['filesystem'];
        }
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function getModulePermissions(): array
    {
        return [
            'view' => [
                'type' => 'bool',
                'display_name' => __trans('View invoices'),
                'description' => __trans('Allows the staff member to view invoices and invoice details.'),
            ],
            'manage_invoices' => [
                'type' => 'bool',
                'display_name' => __trans('Manage invoices'),
                'description' => __trans('Allows the staff member to create, update, delete, and manage invoices.'),
            ],
            'manage_transactions' => [
                'type' => 'bool',
                'display_name' => __trans('Manage transactions'),
                'description' => __trans('Allows the staff member to view, create, update, delete, and process transactions.'),
            ],
            'manage_gateways' => [
                'type' => 'bool',
                'display_name' => __trans('Manage payment gateways'),
                'description' => __trans('Allows the staff member to install, configure, and remove payment gateways.'),
            ],
            'manage_subscriptions' => [
                'type' => 'bool',
                'display_name' => __trans('Manage subscriptions'),
                'description' => __trans('Allows the staff member to view, create, update, and delete subscriptions.'),
            ],
            'manage_tax' => [
                'type' => 'bool',
                'display_name' => __trans('Manage tax rules'),
                'description' => __trans('Allows the staff member to create, update, and delete tax rules.'),
            ],
            'export' => [
                'type' => 'bool',
                'display_name' => __trans('Export invoice data'),
                'description' => __trans('Allows the staff member to export invoice data as CSV.'),
            ],
            'manage_settings' => [],
        ];
    }

    public function __construct()
    {
        $this->filesystem = new Filesystem();
    }

    public function getSearchQuery($data): array
    {
        $select = 'p.*';
        if (!empty($data['summary'])) {
            $select .= ',
                (SELECT COALESCE(SUM(COALESCE(invoice_totals.price, 0) * COALESCE(invoice_totals.quantity, 1)), 0)
                    FROM invoice_item invoice_totals WHERE invoice_totals.invoice_id = p.id) AS list_subtotal,
                (SELECT COALESCE(SUM(CASE WHEN invoice_taxable.taxed = 1 THEN COALESCE(invoice_taxable.price, 0) * COALESCE(invoice_taxable.quantity, 1) ELSE 0 END), 0)
                    FROM invoice_item invoice_taxable WHERE invoice_taxable.invoice_id = p.id) AS list_taxable_subtotal';
        }

        $sql = 'SELECT ' . $select . '
            FROM invoice p
            LEFT JOIN invoice_item pi ON (p.id = pi.invoice_id)
            LEFT JOIN client cl ON (cl.id = p.client_id)
            WHERE 1 ';

        $params = [];

        $search = $data['search'] ?? null;
        $order_id = $data['order_id'] ?? null;
        $id = $data['id'] ?? null;
        $id_nr = $data['nr'] ?? null;
        $client_id = $data['client_id'] ?? null;
        $client = $data['client'] ?? null;
        $created_at = $data['created_at'] ?? null;
        $date_from = $data['date_from'] ?? null;
        $date_to = $data['date_to'] ?? null;
        $paid_at = $data['paid_at'] ?? null;
        $status = $data['status'] ?? null;
        $approved = $data['approved'] ?? null;
        $currency = $data['currency'] ?? null;

        if ($order_id) {
            $sql .= ' AND pi.type = :item_type AND pi.rel_id = :order_id';
            $params['item_type'] = \Model_InvoiceItem::TYPE_ORDER;
            $params['order_id'] = $order_id;
        }

        if ($id) {
            $sql .= ' AND p.id = :id';
            $params['id'] = $id;
        }

        if ($id_nr) {
            $sql .= ' AND (p.id = :id_nr OR p.nr = :id_nr)';
            $params['id_nr'] = $id_nr;
        }

        if ($approved) {
            $sql .= ' AND p.approved = :approved';
            $params['approved'] = (int) $approved;
        }

        if ($status) {
            $sql .= ' AND p.status = :status';
            $params['status'] = $status;
        }

        if ($currency) {
            $sql .= ' AND p.currency = :currency';
            $params['currency'] = $currency;
        }

        if ($client_id) {
            $sql .= ' AND p.client_id = :client_id';
            $params['client_id'] = $client_id;
        }

        if ($client) {
            $sql .= ' AND (cl.first_name LIKE :client_search OR cl.last_name LIKE :client_search OR cl.id = :client OR cl.email = :client)';
            $params['client_search'] = $client . '%';
            $params['client'] = $client;
        }

        if ($created_at) {
            $sql .= " AND DATE_FORMAT(p.created_at, '%Y-%m-%d') = :created_at";
            $params['created_at'] = date('Y-m-d', (int) strtotime((string) $created_at));
        }

        if ($date_from) {
            $sql .= ' AND UNIX_TIMESTAMP(p.created_at) >= :date_from';
            $params['date_from'] = strtotime((string) $date_from);
        }

        if ($date_to) {
            $sql .= ' AND UNIX_TIMESTAMP(p.created_at) <= :date_to';
            $params['date_to'] = strtotime((string) $date_to);
        }

        if ($paid_at) {
            $sql .= " AND DATE_FORMAT(p.paid_at, '%Y-%m-%d') = :paid_at";
            $params['paid_at'] = date('Y-m-d', (int) strtotime((string) $paid_at));
        }

        if ($search) {
            $sql .= ' AND (p.id = :search_numeric_id OR p.nr LIKE :search_like OR p.id LIKE :search OR pi.title LIKE :search_like';
            // ClientFence core patch: also the printed number, series and padded number (FOSS00040 is serie FOSS, nr 40).
            if (preg_match('/^\s*(.*?)0*(\d+)\s*$/', (string) $search, $printed) === 1) {
                $sql .= ' OR (COALESCE(p.serie, \'\') = :search_serie AND p.nr = :search_nr)';
                $params['search_serie'] = $printed[1];
                $params['search_nr'] = $printed[2];
            }
            $sql .= ')';
            $params['search_numeric_id'] = (int) preg_replace('/[^0-9]/', '', (string) $search);
            $params['search_like'] = '%' . $search . '%';
            $params['search'] = $search;
        }

        // ClientFence core patch: a count that skips the totals and the columns (Pagination's third
        // argument): counting the full list worked out every invoice's totals, 200 ms at 12,000 invoices.
        $countSql = 'SELECT COUNT(1) FROM (SELECT p.id' . substr($sql, strlen('SELECT ' . $select)) . ' GROUP BY p.id) AS sub';
        $sql .= ' GROUP BY p.id ORDER BY p.id DESC';

        return [$sql, $params, $countSql];
    }

    /**
     * Convert an aggregated invoice search result into the fields needed by list views.
     *
     * Unlike toApiArray(), this does not load invoice items, orders, products, the client,
     * company details, or subscription information for every invoice in the result set.
     */
    public function toApiSummaryArray(array $row): array
    {
        $subtotal = (float) ($row['list_subtotal'] ?? 0);
        $taxableSubtotal = (float) ($row['list_taxable_subtotal'] ?? 0);
        $taxRate = (float) ($row['taxrate'] ?? 0);
        $tax = self::taxOf($taxableSubtotal, $taxRate, (float) ($row['tax_adjust'] ?? 0));
        $invoiceNumber = is_numeric($row['nr'] ?? null) ? (int) $row['nr'] : (int) $row['id'];
        $clientId = $row['client_id'] ?? null;

        return [
            'id' => $row['id'],
            'serie' => $row['serie'],
            'nr' => $row['nr'],
            'serie_nr' => $row['serie'] . sprintf('%0' . $this->getInvoiceNumberPadding() . 's', $invoiceNumber),
            'client_id' => $clientId,
            'client' => $clientId === null ? null : ['id' => $clientId],
            'currency' => $row['currency'],
            'tax' => $tax,
            'subtotal' => $subtotal,
            'total' => $subtotal + $tax,
            'status' => $row['status'],
            'due_at' => $row['due_at'],
            'paid_at' => $row['paid_at'] ?? null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'buyer' => [
                'first_name' => $row['buyer_first_name'],
                'last_name' => $row['buyer_last_name'],
                'email' => $row['buyer_email'],
            ],
            'approved' => (bool) ($row['approved'] ?? false),
        ];
    }

    private function getInvoiceNumberPadding(): int
    {
        if ($this->invoiceNumberPadding === null) {
            $padding = $this->di['mod_service']('system')->getParamValue('invoice_number_padding');
            $this->invoiceNumberPadding = $padding !== null && $padding !== '' ? (int) $padding : 5;
        }

        return $this->invoiceNumberPadding;
    }

    public function toApiArray(\Model_Invoice $invoice, $deep = true, $identity = null, bool $includeClientBillingEmail = false): array
    {
        $this->ensureValidHash($invoice);
        $row = $this->di['db']->toArray($invoice);

        $items = $this->di['db']->find('InvoiceItem', 'invoice_id = :iid', ['iid' => $row['id']]);
        $lines = [];
        $total = 0;
        $taxable_subtotal = 0;

        foreach ($items as $item) {
            $order_id = ($item->type == \Model_InvoiceItem::TYPE_ORDER) ? $item->rel_id : null;

            $line_total = ($item->price ?? 0) * ($item->quantity ?? 1);
            $total += $line_total;

            if ($item->taxed) {
                $taxable_subtotal += $line_total;
            }

            $line = [
                'id' => $item->id,
                'title' => $item->title,
                'period' => $item->period,
                'quantity' => $item->quantity ?? 1,
                'unit' => $item->unit,
                'price' => $item->price ?? 0,
                'tax' => 0, // Tax will be calculated on the total taxable subtotal
                'taxed' => $item->taxed,
                'charged' => $item->charged,
                'total' => $line_total,
                'order_id' => $order_id,
                'type' => $item->type,
                'rel_id' => $item->rel_id,
                'task' => $item->task,
                'status' => $item->status,
            ];
            $lines[] = $line;
        }

        // taxrate is a varchar column that can hold any admin-supplied value - see getTax().
        $current_invoice_tax_rate = (float) ($row['taxrate'] ?? 0);
        $tax = self::taxOf((float) $taxable_subtotal, $current_invoice_tax_rate, (float) ($row['tax_adjust'] ?? 0));

        $result = [];
        $result['id'] = $row['id'];
        $result['serie'] = $row['serie'];
        $result['nr'] = $row['nr'];
        $result['client_id'] = $invoice->client_id;

        $nr = is_numeric($row['nr']) ? intval($row['nr']) : $result['id'];
        $result['serie_nr'] = $result['serie'] . sprintf('%0' . $this->getInvoiceNumberPadding() . 's', $nr);

        $result['hash'] = $row['hash'];
        $result['hash_expires_at'] = $row['hash_expires_at'] ?? null;
        $result['gateway_id'] = $row['gateway_id'] ?? null;
        $result['taxname'] = $row['taxname'];
        $result['taxrate'] = $row['taxrate'];
        $result['currency'] = $row['currency'];
        $result['currency_rate'] = $row['currency_rate'] ?? 1;
        $result['tax'] = $tax;
        $result['subtotal'] = $total;
        $result['total'] = $total + $tax;
        $result['status'] = $row['status'];
        // ClientFence core patch: what an unpaid invoice has received and its balance due (WHMCS).
        $unpaid = $row['status'] === \Model_Invoice::STATUS_UNPAID;
        $result['amount_paid'] = $unpaid ? min($this->getAmountPaid($invoice), round($result['total'], 2)) : (in_array($row['status'], [\Model_Invoice::STATUS_PAID, \Model_Invoice::STATUS_REFUNDED], true) ? $result['total'] : 0.0);
        $result['balance_due'] = $unpaid ? max(0.0, round($result['total'] - $result['amount_paid'], 2)) : 0.0;
        $result['credit_applied'] = $unpaid ? round((float) $this->di['dbal']->fetchOne('SELECT COALESCE(SUM(amount), 0) FROM invoice_credit WHERE invoice_id = ?', [$invoice->id]), 2) : 0.0;
        $result['notes'] = $row['notes'] ?? null;
        $result['text_1'] = $row['text_1'] ?? null;
        $result['text_2'] = $row['text_2'] ?? null;
        $result['due_at'] = $row['due_at'];
        $result['paid_at'] = $row['paid_at'] ?? null;
        $result['created_at'] = $row['created_at'];
        $result['updated_at'] = $row['updated_at'];
        $result['lines'] = $lines;

        $result['buyer'] = [
            'first_name' => $row['buyer_first_name'],
            'last_name' => $row['buyer_last_name'],
            'company' => $row['buyer_company'],
            'company_vat' => $row['buyer_company_vat'],
            'company_number' => $row['buyer_company_number'],
            'address' => $row['buyer_address'],
            'city' => $row['buyer_city'],
            'state' => $row['buyer_state'],
            'country' => $row['buyer_country'],
            'phone' => $row['buyer_phone'],
            'phone_cc' => $row['buyer_phone_cc'] ?? '',
            'email' => $row['buyer_email'],
            'zip' => $row['buyer_zip'],
        ];

        $systemService = $this->di['mod_service']('system');
        $c = $systemService->getCompany();
        $result['seller'] = [
            'company' => !empty($row['seller_company']) ? $row['seller_company'] : ($c['name'] ?? ''),
            'company_vat' => $row['seller_company_vat'] ?? '',
            'company_number' => $row['seller_company_number'] ?? '',
            'address' => !empty($row['seller_address']) ? $row['seller_address'] : trim(($c['address_1'] ?? '') . ' ' . ($c['address_2'] ?? '') . ' ' . ($c['address_3'] ?? '')),
            'address_1' => !empty($row['seller_address_1']) ? $row['seller_address_1'] : ($c['address_1'] ?? ''),
            'address_2' => !empty($row['seller_address_2']) ? $row['seller_address_2'] : ($c['address_2'] ?? ''),
            'address_3' => !empty($row['seller_address_3']) ? $row['seller_address_3'] : ($c['address_3'] ?? ''),
            'phone' => !empty($row['seller_phone']) ? $row['seller_phone'] : ($c['tel'] ?? ''),
            'email' => !empty($row['seller_email']) ? $row['seller_email'] : ($c['email'] ?? ''),
            'account_number' => $c['account_number'] ?? null,
            'bank_name' => $c['bank_name'] ?? null,
            'bic' => $c['bic'] ?? null,
        ];

        /**
         * Removed if($identity instanceof \Model_Admin) {}
         * Generates error when this function is called by cron.
         */
        $client = isset($row['client_id']) ? $this->di['db']->load('Client', $row['client_id']) : null;
        $clientService = $this->di['mod_service']('client');
        if ($client instanceof \Model_Client) {
            $result['client'] = $clientService->toApiArray($client);
            if ($includeClientBillingEmail) {
                $result['client']['billing_email'] = $client->billing_email;
            }
        } else {
            $result['client'] = null;
        }
        $result['reminded_at'] = $row['reminded_at'] ?? null;
        $result['approved'] = (bool) ($row['approved'] ?? false);
        $result['income'] = ($row['base_income'] ?? 0) - ($row['base_refund'] ?? 0);
        $result['refund'] = $row['refund'] ?? 0;
        $result['credit'] = $row['credit'] ?? 0;

        $subscriptionService = $this->di['mod_service']('Invoice', 'Subscription');
        $subscriptionPeriod = $subscriptionService->getSubscriptionPeriod($invoice);
        $result['subscribable'] = $subscriptionPeriod !== null;
        if ($deep && $result['subscribable']) {
            $period = $this->di['period']($subscriptionPeriod);
            $result['subscription'] = [
                'unit' => $period->getUnit(),
                'cycle' => $period->getQty(),
                'period' => $subscriptionPeriod,
            ];
        }

        // Add order information for email templates
        $result['orders'] = [];
        $orderIds = array_unique(array_filter(array_column($lines, 'order_id')));

        // Ensure order IDs are safe integers before using in SQL
        $orderIds = array_map(intval(...), $orderIds);
        $orderIds = array_filter($orderIds, static fn ($id): bool => $id > 0);
        $orderIds = array_values($orderIds);

        if (!empty($orderIds)) {
            // Batch load orders
            $orderIdPlaceholders = [];
            $orderIdParams = [];
            foreach ($orderIds as $idx => $id) {
                $placeholder = ':order_id_' . $idx;
                $orderIdPlaceholders[] = $placeholder;
                $orderIdParams['order_id_' . $idx] = $id;
            }
            $orders = $this->di['db']->find('ClientOrder', 'id IN (' . implode(',', $orderIdPlaceholders) . ')', $orderIdParams);

            // Batch load related products
            $rawProductIds = array_map(static fn ($order): int => isset($order->product_id) ? (int) $order->product_id : 0, $orders);
            $nonEmptyProductIds = array_filter($rawProductIds);
            $productIds = array_unique($nonEmptyProductIds);

            // Ensure product IDs are safe integers before using in SQL
            $productIds = array_values(array_filter($productIds, static fn ($id): bool => $id > 0));

            $productService = $this->di['mod_service']('product');
            $productsById = !empty($productIds) ? $productService->getProductSnapshotMap($productIds) : [];

            foreach ($orders as $order) {
                $productId = isset($order->product_id) ? (int) $order->product_id : 0;
                $product = $productsById[$productId] ?? null;
                $orderData = [
                    'id' => $order->id,
                    'title' => $order->title,
                    'expires_at' => $order->expires_at,
                ];

                if ($product) {
                    $orderData['product_name'] = $product['title'];
                    $orderData['product_type'] = $product['type'];
                }

                $result['orders'][] = $orderData;
            }
        }

        return $result;
    }

    public static function onAfterAdminInvoicePaymentReceived(\ClientFence\Event $event): bool
    {
        $params = $event->getParameters();
        $di = $event->getDi();
        $service = $di['mod_service']('invoice');

        try {
            $invoiceModel = $di['db']->load('Invoice', $params['id'] ?? 0);
            $invoice = $service->toApiArray($invoiceModel, true, null, true);
            if (($invoice['total'] ?? 0) > 0) {
                $service->sendInvoiceEmail($invoiceModel, $invoice, 'mod_invoice_paid');
            }
        } catch (\Exception $exc) {
            $di['logger']->setChannel('email')->error('Failed to send email for invoice payment', ['exception' => $exc->getMessage()]);
        }

        return true;
    }

    public static function onAfterInvoiceCreate(\ClientFence\Event $event): bool
    {
        $params = $event->getParameters();
        $di = $event->getDi();
        $service = $di['mod_service']('invoice');

        try {
            $invoiceModel = $di['db']->load('Invoice', $params['id']);
            $invoice = $service->toApiArray($invoiceModel, true, null, true);
            $service->sendInvoiceEmail($invoiceModel, $invoice, 'mod_invoice_created');
        } catch (\Exception $exc) {
            $di['logger']->setChannel('email')->error('Failed to send email for invoice creation', ['exception' => $exc->getMessage()]);
        }

        return true;
    }

    public static function onAfterAdminInvoiceApprove(\ClientFence\Event $event): bool
    {
        $params = $event->getParameters();
        $di = $event->getDi();
        $service = $di['mod_service']('invoice');

        try {
            $invoiceModel = $di['db']->load('Invoice', $params['id'] ?? 0);

            if (($params['total'] ?? 0) > 0
                && ($params['status'] ?? null) !== \Model_Invoice::STATUS_PAID
                && isset($params['client']['id'])
            ) {
                if ($invoiceModel instanceof \Model_Invoice) {
                    $service->sendInvoiceEmail($invoiceModel, $params, 'mod_invoice_created', (int) $params['client']['id']);
                }
            }

            // Sending the created-email extends the hash lifetime so the
            // recipient has a fresh window to act on the link.
            if ($invoiceModel instanceof \Model_Invoice) {
                $service->extendInvoiceHashLifetime($invoiceModel);
            }
        } catch (\Exception $exc) {
            $di['logger']->setChannel('email')->error('Failed to send email for invoice approval', ['exception' => $exc->getMessage()]);
        }

        return true;
    }

    private function sendInvoiceEmail(\Model_Invoice $invoice, array $invoiceData, string $templateCode, ?int $clientId = null): void
    {
        $email = [
            'to_client' => $clientId ?? $invoice->client_id,
            'code' => $templateCode,
            'invoice' => $invoiceData,
        ];
        $email = $this->withBillingRecipient($email, $invoiceData);

        $attachment = $this->getInvoicePdfAttachment($invoice);
        if ($attachment !== null) {
            $email['attachment'] = $attachment;
        }

        $this->di['mod_service']('email')->sendTemplate($email);
    }

    public static function onAfterAdminInvoiceReminderSent(\ClientFence\Event $event): void
    {
        $params = $event->getParameters();
        $di = $event->getDi();
        $service = $di['mod_service']('invoice');

        try {
            $invoiceModel = $di['db']->load('Invoice', $params['id'] ?? 0);
            if (!$invoiceModel instanceof \Model_Invoice) {
                return;
            }

            $invoice = $service->toApiArray($invoiceModel, true, null, true);
            $email = [];
            $email['to_client'] = $invoiceModel->client_id;
            $email['code'] = 'mod_invoice_payment_reminder';
            $email['invoice'] = $invoice;
            $email = $service->withBillingRecipient($email, $invoice);
            $attachment = $service->getInvoicePdfAttachment($invoiceModel);
            if ($attachment !== null) {
                $email['attachment'] = $attachment;
            }
            $emailService = $di['mod_service']('email');
            $emailService->sendTemplate($email);

            // Sending a payment reminder also re-extends the hash lifetime
            // since the recipient is being re-engaged via the same link.
            $service->extendInvoiceHashLifetime($invoiceModel);
        } catch (\Exception $exc) {
            $di['logger']->setChannel('email')->error('Failed to send invoice reminder email', ['exception' => $exc->getMessage()]);
        }
    }

    public static function onEventBeforeInvoiceIsDue(\ClientFence\Event $event): void
    {
        $params = $event->getParameters();
        $di = $event->getDi();
        $service = $di['mod_service']('invoice');
        $claimed = false;

        try {
            if (!$service->isInvoiceReminderIntervalEnabled('invoice_reminder_before_due_days', (int) ($params['days_left'] ?? 0), '', $params['reminder_intervals'] ?? null)) {
                return;
            }

            // Atomically claim the invoice before sending anything: this is what stops the same
            // reminder being sent twice when this event is dispatched more than once for the
            // same invoice (overlapping cron runs, the once-daily batch and the pending-reminder
            // fallback both firing it, etc).
            $claimed = (bool) $di['db']->exec(
                "UPDATE invoice SET reminded_at = NOW(), updated_at = NOW() WHERE id = :id AND status = 'unpaid' AND approved = 1 AND due_at > NOW() AND (reminded_at IS NULL OR DATE(reminded_at) < CURDATE())",
                [':id' => $params['id'] ?? 0]
            );
            if (!$claimed) {
                return;
            }

            $invoiceModel = $di['db']->load('Invoice', $params['id'] ?? 0);
            if ($invoiceModel instanceof \Model_Invoice) {
                $service->sendInvoiceReminder($invoiceModel);
            }
        } catch (\Exception $exc) {
            if ($claimed) {
                // sendInvoiceReminder()'s downstream send handler (onAfterAdminInvoiceReminderSent)
                // catches its own failures internally, so any exception reaching here means the
                // email was never queued. Release the claim so a later cron run retries it instead
                // of the reminder being silently lost for the day.
                $di['db']->exec('UPDATE invoice SET reminded_at = NULL WHERE id = :id', [':id' => $params['id'] ?? 0]);
            }
            $di['logger']->setChannel('email')->error('Failed to send invoice reminder email', ['id' => $params['id'] ?? null, 'exception' => $exc->getMessage()]);
        }
    }

    public static function onAfterAdminCronRun(\ClientFence\Event $event): void
    {
        $di = $event->getDi();
        $systemService = $di['mod_service']('System');
        $remove_after_days = $systemService->getParamValue('remove_after_days');
        if (isset($remove_after_days) && $remove_after_days) {
            // removing old unpaid invoices, through rmInvoice() so related
            // orders, invoice items, and reserved resources stay consistent
            $days = (int) $remove_after_days;
            $service = $di['mod_service']('invoice');
            $invoices = $service->findUnpaidOlderThan($days);
            foreach ($invoices as $invoiceModel) {
                $id = $invoiceModel->id;
                $service->rmInvoice($invoiceModel);
                $di['logger']->info("Removed expired unpaid invoice #{$id}.");
            }
        }
        // ClientFence core patch: saved cards are charged for the invoices that are due (Invoice/SavedCards.php).
        try {
            SavedCards::runDue($di);
        } catch (\Throwable $e) {
            $di['logger']->error('Invoice: charging saved cards failed: %s', $e->getMessage());
        }
    }

    public static function onEventAfterInvoiceIsDue(\ClientFence\Event $event): void
    {
        $params = $event->getParameters();
        $di = $event->getDi();
        $service = $di['mod_service']('invoice');
        $claimed = false;

        try {
            if (!$service->isInvoiceReminderIntervalEnabled('invoice_reminder_after_due_days', (int) ($params['days_passed'] ?? 0), '5', $params['reminder_intervals'] ?? null)) {
                return;
            }

            // Atomically claim the invoice before sending anything: this is what stops the same
            // reminder being sent twice when this event is dispatched more than once for the
            // same invoice (overlapping cron runs, the once-daily batch and the pending-reminder
            // fallback both firing it, etc). The claim UPDATE already persists reminded_at and
            // updated_at, so there's no need to store the loaded model again once sent below.
            $claimed = (bool) $di['db']->exec(
                "UPDATE invoice SET reminded_at = NOW(), updated_at = NOW() WHERE id = :id AND status = 'unpaid' AND approved = 1 AND ((due_at < NOW()) OR (ABS(DATEDIFF(due_at, NOW())) = 0)) AND (reminded_at IS NULL OR DATE(reminded_at) < CURDATE())",
                [':id' => $params['id'] ?? 0]
            );
            if (!$claimed) {
                return;
            }

            $invoiceModel = $di['db']->load('Invoice', $params['id']);
            if (!$invoiceModel instanceof \Model_Invoice) {
                return;
            }

            $invoice = $service->toApiArray($invoiceModel, true, null, true);
            if (!isset($invoice['client']) || !is_array($invoice['client']) || !isset($invoice['client']['id'])) {
                throw new \ClientFence\Exception('Invoice client data is unavailable.');
            }

            $email = [];
            $email['to_client'] = $invoice['client']['id'];
            $email['code'] = 'mod_invoice_due_after';
            $email['days_passed'] = $params['days_passed'];
            $email['invoice'] = $invoice;
            $email = $service->withBillingRecipient($email, $invoice);
            $attachment = $service->getInvoicePdfAttachment($invoiceModel);
            if ($attachment !== null) {
                $email['attachment'] = $attachment;
            }

            $emailService = $di['mod_service']('email');
            $emailService->sendTemplate($email);
        } catch (\Exception $exc) {
            if ($claimed) {
                // Nothing past sendTemplate() can throw, so reaching here with a claim already
                // made means the email was never confirmed queued. Release the claim so a later
                // cron run retries this invoice instead of losing the reminder.
                $di['db']->exec('UPDATE invoice SET reminded_at = NULL WHERE id = :id', [':id' => $params['id'] ?? 0]);
            }
            $di['logger']->setChannel('email')->error('Failed to send overdue invoice email', ['id' => $params['id'] ?? null, 'exception' => $exc->getMessage()]);
        }
    }

    /**
     * Route invoice notifications to the client's optional billing address while retaining
     * to_client so templates, timezone handling, and client email history keep working.
     */
    public function withBillingRecipient(array $email, array $invoice): array
    {
        $billingEmail = trim((string) ($invoice['client']['billing_email'] ?? ''));
        if ($billingEmail !== '' && filter_var($billingEmail, FILTER_VALIDATE_EMAIL) !== false) {
            $email['client_billing_email'] = $billingEmail;
        }

        return $email;
    }

    // ClientFence core patch: payment tasks run immediately unless the caller runs them after releasing its lock.
    public function markAsPaid(\Model_Invoice $invoice, $charge = true, $execute = true, bool $deferEvents = false): bool
    {
        // ClientFence core patch: settlement must see payments completed while it waited for the client's credit lock.
        return Tools::withLock($this->di, 'fb:credit_payment:client:' . (int) $invoice->client_id, function () use ($invoice, $charge, $execute, $deferEvents): bool {
            $current = $this->di['dbal']->fetchAssociative('SELECT * FROM invoice WHERE id = ?', [$invoice->id]);
            if ($current === false) {
                throw new InformationException('Invoice not found');
            }
            foreach ($current as $column => $value) {
                $invoice->{$column} = $value;
            }

            return $this->markAsPaidLocked($invoice, $charge, $execute, $deferEvents);
        });
    }

    private function markAsPaidLocked(\Model_Invoice $invoice, $charge, $execute, bool $deferEvents): bool
    {
        if ($invoice->status == \Model_Invoice::STATUS_PAID) {
            return true;
        }

        $invoiceItems = $this->di['db']->find('InvoiceItem', 'invoice_id = ?', [$invoice->id]);
        $invoiceItemService = $this->di['mod_service']('Invoice', 'InvoiceItem');
        $amounts = [];
        if ($charge) {
            // ClientFence core patch: line tax uses the extended price; the last debit reconciles subtotal rounding.
            $remainder = round($this->getTotalWithTax($invoice), 2);
            $last = null;
            foreach ($invoiceItems as $key => $item) {
                $net = $invoiceItemService->getTotal($item);
                $amounts[$key] = round($net + ($item->taxed && (float) $invoice->taxrate > 0 ? round($net * (float) $invoice->taxrate / 100, 2) : 0.0), 2);
                $remainder = round($remainder - $amounts[$key], 2);
                if (!$item->charged) {
                    $last = $key;
                }
            }
            if ($last !== null) {
                $amounts[$last] = round($amounts[$last] + $remainder, 2);
            }
        }
        foreach ($invoiceItems as $key => $item) {
            if ($charge && !$item->charged) {
                $invoiceItemService->creditInvoiceItem($item, $amounts[$key]);
                $item->charged = true;
            }
            $invoiceItemService->markAsPaid($item, false);
        }

        $systemService = $this->di['mod_service']('system');

        $currencyService = $this->di['mod_service']('currency');
        /** @var \ClientFence\Mod\Currency\Repository\CurrencyRepository $currencyRepository */
        $currencyRepository = $currencyService->getCurrencyRepository();

        // ClientFence core patch: the paid series only when one is set; an empty one dropped the number's prefix (FOSS00020 became 00020).
        $paidSeries = (string) $systemService->getParamValue('invoice_series_paid');
        if ($paidSeries !== '') {
            $invoice->serie = $paidSeries;
        }
        $invoice->approved = true;

        $currencyRate = $currencyRepository->getRateByCode((string) $invoice->currency);
        if ($currencyRate === null) {
            throw new \ClientFence\Exception("Currency rate for code '{$invoice->currency}' is not configured.");
        }
        $invoice->currency_rate = $currencyRate;

        $invoice->status = \Model_Invoice::STATUS_PAID;
        $invoice->paid_at = date('Y-m-d H:i:s');
        $invoice->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($invoice);

        $this->countIncome($invoice);
        $productService = $this->di['mod_service']('Product');
        $productService->commitReservedPromoRedemptionsForInvoice($invoice);

        // Listeners render PDFs and send email, so a caller holding a payment lock defers this
        // until after it has released it, rather than holding it for the duration of an SMTP send.
        if (!$deferEvents) {
            $this->firePaymentReceivedEvent($invoice);
        }

        if ($execute) {
            foreach ($invoiceItems as $item) {
                try {
                    $invoiceItemService->executeTask($item);
                } catch (\Exception $e) {
                    $this->di['logger']->warning($e->getMessage());
                }
            }
        }

        $this->di['logger']->info("Marked invoice {$invoice->id} as paid.");

        return true;
    }

    private function firePaymentReceivedEvent(\Model_Invoice $invoice): void
    {
        $this->di['events_manager']->fire(['event' => 'onAfterAdminInvoicePaymentReceived', 'params' => ['id' => $invoice->id]]);
    }

    /**
     * The task execution markAsPaid() performs with $execute, for callers that must run it after
     * their own lock has been released.
     */
    private function executeInvoiceItemTasks(\Model_Invoice $invoice): void
    {
        $invoiceItemService = $this->di['mod_service']('Invoice', 'InvoiceItem');
        $items = $this->di['db']->find('InvoiceItem', 'invoice_id = ?', [$invoice->id]);
        foreach ($items as $item) {
            try {
                $invoiceItemService->executeTask($item);
            } catch (\Exception $e) {
                $this->di['logger']->warning($e->getMessage());
            }
        }
    }

    public function markAsPaidByAdmin(\Model_Invoice $invoice, array $data = []): bool
    {
        if ($invoice->status === \Model_Invoice::STATUS_PAID) {
            return true;
        }

        // ClientFence core patch: under the client's credit lock, and the status is read again there: two
        // clicks, or a click during a credit payment, paid the invoice and credited an Add funds twice; a
        // refunded invoice was paid (and credited) again.
        return Tools::withLock($this->di, 'fb:credit_payment:client:' . (int) $invoice->client_id, function () use ($invoice, $data): bool {
            $status = $this->di['dbal']->fetchOne('SELECT status FROM invoice WHERE id = ?', [$invoice->id]);
            if ($status === \Model_Invoice::STATUS_PAID) {
                return true;
            }
            if ($status === \Model_Invoice::STATUS_REFUNDED) {
                throw new InformationException('A paid or refunded invoice keeps its status, and only Refund marks an invoice refunded.');
            }

            return $this->markAsPaidByAdminLocked($invoice, $data);
        });
    }

    private function markAsPaidByAdminLocked(\Model_Invoice $invoice, array $data): bool
    {
        $payGateway = $this->validateAdminMarkAsPaidRequest($data, $invoice);
        $transactionId = isset($data['transactionId']) ? trim((string) $data['transactionId']) : null;
        $invoiceTotal = $this->getTotalWithTax($invoice);
        $isDeposit = $this->isInvoiceTypeDeposit($invoice);
        // What is above the total (staff lowered it) stays on the balance as credit.
        $credited = min($this->getAmountPaid($invoice), round($invoiceTotal, 2));
        // ClientFence core patch: processed transfers have already credited the client's balance (imported WHMCS payments did not:
        // the import subtracts them on the invoice itself).
        if ($isDeposit) {
            $invoiceTotal = max(0.0, round($invoiceTotal - $credited, 2));
        } elseif ($credited > 0) {
            // ClientFence core patch: a partly paid invoice is paid with what was recorded plus the rest received now.
            $invoiceTotal = max(0.0, round($invoiceTotal - $credited, 2));
        }

        if ((int) $payGateway->id !== (int) $invoice->gateway_id) {
            $invoice->gateway_id = (int) $payGateway->id;
            $invoice->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($invoice);
        }

        // ClientFence core patch: the Egyptian methods use CustomEgp, which extends Custom. Without a
        // transaction their payments were missing from the transaction list and income by method.
        // getAdapterClassName loads the adapter file: CustomEgp is not autoloaded.
        $gatewayCode = (string) ($payGateway->gateway ?? '');
        if ((!$isDeposit || $invoiceTotal > 0) && ($gatewayCode === 'Custom' || (preg_match('/^[A-Za-z0-9_]+$/', $gatewayCode) === 1 && is_subclass_of($this->di['mod_service']('Invoice', 'PayGateway')->getAdapterClassName($payGateway), \Payment_Adapter_Custom::class)))) {
            $transactionService = $this->di['mod_service']('Invoice', 'Transaction');
            $newtx = $transactionService->create([
                'invoice_id' => $invoice->id,
                'gateway_id' => $invoice->gateway_id,
                'currency' => $invoice->currency,
                'status' => 'received',
                'source' => 'admin',
                'post' => [
                    'invoice_id' => $invoice->id,
                    'txn_id' => $transactionId,
                ],
                'txn_id' => $transactionId,
            ]);
            $transaction = $this->di['db']->getExistingModelById('Transaction', $newtx, 'Transaction not found');
            if ((int) $transaction->invoice_id !== (int) $invoice->id) {
                throw new InformationException('Transaction ID is already associated with another invoice.');
            }

            if (!$isDeposit) {
                $this->useRecordedPayments($invoice, $credited);
            }
            $result = $this->markAsPaid($invoice, false);
            if ($result) {
                if ($isDeposit) {
                    $this->creditDepositBalanceIfNeeded($invoice, $payGateway, $transactionId, $invoiceTotal);
                }
                $transaction->amount = $invoiceTotal;
                $transaction->currency = $invoice->currency;
                $transaction->status = \Model_Transaction::STATUS_PROCESSED;
                // ClientFence core patch: pay_gateway has no title column; name is the method's label.
                $gatewayTitle = $payGateway->name ?: $payGateway->gateway;
                $transaction->note = sprintf('%s transaction No: %s', $gatewayTitle, $transactionId);
                $transaction->updated_at = date('Y-m-d H:i:s');
                $this->di['db']->store($transaction);
            }

            return $result;
        }

        if (!$isDeposit) {
            $this->useRecordedPayments($invoice, $credited);
        }
        $result = $this->markAsPaid($invoice, false);
        if ($result && $isDeposit) {
            $this->creditDepositBalanceIfNeeded($invoice, $payGateway, $transactionId, $invoiceTotal);
        }

        return $result;
    }

    /**
     * ClientFence core patch: payments recorded on an unpaid invoice wait in the client's balance; an invoice
     * marked paid by staff takes them from there, so they are not left spendable as well.
     */
    private function useRecordedPayments(\Model_Invoice $invoice, float $credited): void
    {
        if ($credited <= 0) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $this->di['dbal']->insert('client_balance', [
            'client_id' => (int) $invoice->client_id,
            'type' => 'invoice',
            'rel_id' => (string) $invoice->id,
            'description' => 'Payments recorded on the invoice, applied to it',
            'amount' => -$credited,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Credit the client balance for an Add Funds (deposit) invoice paid by an admin.
     *
     * Must only be called for a deposit invoice when the payment
     * happened in this call (i.e. markAsPaid() just returned true): the
     * admin flow marks items paid without charging them, so unlike gateway
     * payments nothing else credits the balance. Only deposit lines are
     * credited: a draft deposit invoice can gain non-deposit lines before
     * issuance, and those charges must not become spendable balance.
     */
    private function creditDepositBalanceIfNeeded(\Model_Invoice $invoice, \Model_PayGateway $payGateway, ?string $transactionId, float $invoiceTotal): void
    {
        if ($invoiceTotal <= 0) {
            return;
        }

        $items = $this->di['db']->find('InvoiceItem', 'invoice_id = ?', [$invoice->id]);
        $hasOtherItems = false;
        foreach ($items as $item) {
            if ($item->type != \Model_InvoiceItem::TYPE_DEPOSIT) {
                $hasOtherItems = true;

                break;
            }
        }

        if ($hasOtherItems) {
            $invoiceItemService = $this->di['mod_service']('Invoice', 'InvoiceItem');
            $amount = 0.0;
            foreach ($items as $item) {
                if ($item->type == \Model_InvoiceItem::TYPE_DEPOSIT) {
                    $amount += $invoiceItemService->getTotalWithTax($item);
                }
            }
        } else {
            $amount = $invoiceTotal;
        }
        $amount = min($amount, $invoiceTotal);

        $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id, 'Client not found');

        // ClientFence core patch: pay_gateway has no title column; name is the label clients see.
        $gatewayTitle = $payGateway->name ?: $payGateway->gateway;
        $reference = trim((string) $transactionId);
        $description = $reference !== ''
            ? sprintf('%s transaction No: %s', $gatewayTitle, $reference)
            : sprintf('%s manual payment', $gatewayTitle);

        $this->di['mod_service']('Client')->addFunds($client, $amount, $description, [
            'type' => 'invoice',
            'rel_id' => (string) $invoice->id,
        ]);
    }

    public function validateAdminMarkAsPaidRequest(array $data, ?\Model_Invoice $invoice = null): \Model_PayGateway
    {
        $gatewayId = isset($data['gateway_id']) && !empty($data['gateway_id']) ? (int) $data['gateway_id'] : (int) ($invoice->gateway_id ?? 0);
        if ($gatewayId <= 0) {
            throw new InformationException('Payment gateway is required when marking an invoice as paid.');
        }

        $payGateway = $this->di['db']->getExistingModelById('PayGateway', $gatewayId, 'Payment gateway not found');
        if ((int) ($payGateway->enabled ?? 0) !== 1) {
            throw new InformationException('Payment gateway is not enabled');
        }
        // ClientFence core patch: CustomEgp extends Custom, so its payments need the receipt reference too.
        $gatewayCode = (string) ($payGateway->gateway ?? '');
        if ($gatewayCode === 'Custom' || (preg_match('/^[A-Za-z0-9_]+$/', $gatewayCode) === 1 && is_subclass_of($this->di['mod_service']('Invoice', 'PayGateway')->getAdapterClassName($payGateway), \Payment_Adapter_Custom::class))) {
            $transactionId = trim((string) ($data['transactionId'] ?? ''));
            if ($transactionId === '') {
                throw new InformationException('Transaction ID is required when using the Custom payment gateway.');
            }
        }

        return $payGateway;
    }

    /**
     * Finds all paid invoices associated with a given client order.
     *
     * @param \Model_ClientOrder $order the client order for which to find paid invoices
     *
     * @return array An array of paid invoices. Each element in the array represents an invoice record
     *               as returned by the database, typically as an associative array or an object.
     */
    public function findPaidInvoicesForOrder(\Model_ClientOrder $order): array
    {
        $bindings = [
            ':rel_id' => $order->id,
            ':status' => \Model_Invoice::STATUS_PAID,
        ];

        return $this->di['db']->find('Invoice', 'id IN (SELECT invoice_id FROM invoice_item WHERE rel_id = :rel_id) AND status = :status', $bindings);
    }

    public function getNextInvoiceNumber()
    {
        $systemService = $this->di['mod_service']('system');

        // Claimed and advanced in one locked step, otherwise two concurrent approvals take the
        // same number and issue two invoices sharing an invoice number.
        $next_nr = $systemService->reserveNextNumericParamValue('invoice_starting_number');

        if ($next_nr === null) {
            // In theory this code should never need to be called, but is provided as a fallback
            $r = $this->di['db']->findOne('Invoice', 'nr is not null order by id desc');
            if (!$r instanceof \Model_Invoice || !is_numeric($r->nr)) {
                throw new \ClientFence\Exception('Unable to determine the next invoice number');
            }

            // Seeding the counter and reserving from it has to be one locked step too, otherwise
            // two callers deriving the same seed both write it and both reserve the same number.
            $next_nr = $systemService->reserveNextNumericParamValue('invoice_starting_number', intval($r->nr) + 1);
            if ($next_nr === null) {
                throw new \ClientFence\Exception('Unable to determine the next invoice number');
            }
        }

        return $next_nr;
    }

    public function countIncome(\Model_Invoice $invoice): void
    {
        $table = $this->di['mod_service']('currency');

        // ClientFence core patch: money received before this invoice came to ClientFence (a payment
        // a WHMCS import found on a partly paid invoice: a negative line of unit "received", which
        // keeps the amount due right) is income too, though not due again.
        $received = 0.0;
        foreach ($this->di['db']->find('InvoiceItem', 'invoice_id = ? AND unit = ?', [$invoice->id, \ClientFence\Mod\Clientfence\Whmcs\Money::RECEIVED_UNIT]) ?? [] as $item) {
            $received -= (float) $item->price * (float) $item->quantity;
        }
        $invoice->base_income = $table->toBaseCurrency($invoice->currency, $this->getTotal($invoice) + $received);
        if ($invoice->refund !== null) {
            $invoice->base_refund = $table->toBaseCurrency($invoice->currency, $invoice->refund);
        } else {
            $invoice->base_refund = null;
        }

        $this->di['db']->store($invoice);
    }

    public function prepareInvoice(\Model_Client $client, array $data)
    {
        if (!$client->currency) {
            $currencyService = $this->di['mod_service']('currency');
            /** @var \ClientFence\Mod\Currency\Repository\CurrencyRepository $currencyRepository */
            $currencyRepository = $currencyService->getCurrencyRepository();
            $currency = $currencyRepository->findDefault();

            if (!$currency instanceof Currency) {
                throw new \ClientFence\Exception('Default currency not found');
            }

            $currencyCode = $currency->getCode();
            $client->currency = $currencyCode;
            $this->di['db']->store($client);
            if (isset($this->di['logger'])) {
                $this->di['logger']->info('Client #%s currency was not defined. Set default currency %s.', $client->id, $currencyCode);
            }
        }

        $model = $this->di['db']->dispense('Invoice');
        $model->client_id = $client->id;
        $model->status = \Model_Invoice::STATUS_UNPAID;
        $model->currency = $client->currency;
        $model->approved = 0;

        $model->gateway_id = $data['gateway_id'] ?? $model->gateway_id;
        $model->text_1 = $data['text_1'] ?? $model->text_1;
        $model->text_2 = $data['text_2'] ?? $model->text_2;
        $model->created_at = date('Y-m-d H:i:s');
        $model->updated_at = date('Y-m-d H:i:s');
        $invoiceId = $this->di['db']->store($model);

        $this->setInvoiceDefaults($model);

        if (isset($data['items']) && is_array($data['items'])) {
            $invoiceItemService = $this->di['mod_service']('Invoice', 'InvoiceItem');
            foreach ($data['items'] as $d) {
                $invoiceItemService->addNew($model, $d);
            }
        }
        // ClientFence core patch: catalog prices that include tax (the cart's lines; WHMCS Inclusive Tax).
        if (!empty($data['prices_include_tax'])) {
            $this->applyInclusiveTax($model);
        }

        $this->di['logger']->info("Prepared new invoice {$invoiceId}.");

        if (isset($data['approve']) && $data['approve']) {
            try {
                $this->approveInvoice($model, ['id' => $invoiceId]);
                $this->di['logger']->info("Approved invoice {$invoiceId} instantly.");
            } catch (\Exception $e) {
                $this->di['logger']->warning($e->getMessage());
            }
        }

        return $model;
    }

    public function setInvoiceDefaults(\Model_Invoice $model): void
    {
        $clientService = $this->di['mod_service']('Client');
        $systemService = $this->di['mod_service']('system');
        $client = $this->di['db']->load('Client', $model->client_id);
        $seller = $systemService->getCompany();

        $buyer = $clientService->toApiArray($client);

        $model->seller_company = $seller['name'] ?? null;
        $model->seller_company_vat = $seller['vat_number'] ?? null;
        $model->seller_company_number = $seller['number'] ?? null;
        $model->seller_address = trim(($seller['address_1'] ?? '') . ' ' . ($seller['address_2'] ?? '') . ' ' . ($seller['address_3'] ?? ''));
        $model->seller_phone = $seller['tel'] ?? null;
        $model->seller_email = $seller['email'] ?? null;

        $model->buyer_first_name = $buyer['first_name'] ?? null;
        $model->buyer_last_name = $buyer['last_name'] ?? null;
        $model->buyer_company = $buyer['company'] ?? null;
        $model->buyer_company_vat = $buyer['company_vat'] ?? null;
        $model->buyer_company_number = $buyer['company_number'] ?? null;
        $model->buyer_address = trim(($buyer['address_1'] ?? '') . ' ' . ($buyer['address_2'] ?? ''));
        $model->buyer_city = $buyer['city'] ?? null;
        $model->buyer_state = $buyer['state'] ?? null;
        $model->buyer_country = $buyer['country'] ?? null;
        $model->buyer_phone = trim(($buyer['phone_cc'] ?? '') . ' ' . ($buyer['phone'] ?? ''));
        $model->buyer_email = $buyer['email'] ?? null;
        $model->buyer_zip = $buyer['postcode'] ?? null;

        $invoice_due_days = $systemService->getParamValue('invoice_due_days');
        if (!is_numeric($invoice_due_days)) {
            $invoice_due_days = 1;
        }
        $due_time = strtotime("+{$invoice_due_days} day");
        $model->due_at = date('Y-m-d H:i:s', $due_time);

        $model->serie = $systemService->getParamValue('invoice_series');
        $model->nr = $this->getNextInvoiceNumber();
        $model->hash = bin2hex(random_bytes(random_int(15, 30)));
        $model->hash_expires_at = $this->computeHashExpiration();

        $taxtitle = '';
        $taxService = $this->di['mod_service']('Invoice', 'Tax');
        $tax = $taxService->getTaxRateForClient($client, $taxtitle);
        $model->taxname = $taxtitle;
        $model->taxrate = $tax;

        // ClientFence core patch: the default note is for the client: shown on the invoice page and PDF
        // (text_2), not in the staff-only notes.
        $defaultNote = (string) $this->di['mod_service']('system')->getParamValue('invoice_default_note');
        if ($defaultNote !== '' && empty($model->text_2)) {
            $model->text_2 = $defaultNote;
        }

        $this->di['db']->store($model);
    }

    public function approveInvoice(\Model_Invoice $invoice, array $data): bool
    {
        $this->di['events_manager']->fire(['event' => 'onBeforeAdminInvoiceApprove', 'params' => $this->toApiArray($invoice)]);

        // ClientFence core patch (QA r6 M-08): only these columns are written. Storing the whole invoice wrote back the
        // "unpaid" status read before another request paid it, and the invoice was paid from credit again.
        $invoice->approved = 1;
        $invoice->updated_at = date('Y-m-d H:i:s');
        $this->di['dbal']->executeStatement('UPDATE invoice SET approved = 1, updated_at = ? WHERE id = ?', [$invoice->updated_at, $invoice->id]);

        if (isset($data['use_credits']) && $data['use_credits']) {
            $this->tryPayWithCredits($invoice);
        }

        $this->di['events_manager']->fire(['event' => 'onAfterAdminInvoiceApprove', 'params' => $this->toApiArray($invoice, true, null, true)]);

        $this->di['logger']->info("Approved invoice {$invoice->id}.");

        return true;
    }

    /**
     * ClientFence core patch: as WHMCS, a gateway payment below the balance due is a part payment (the invoice keeps the
     * rest due); only an Add funds invoice, which credits its whole amount when paid, refuses it.
     */
    public function validatePaymentAmount(float $received, float $expected, \Model_Invoice $invoice): void
    {
        $epsilon = 0.01;
        if ($received < $expected - $epsilon && ($received <= 0 || $this->isInvoiceTypeDeposit($invoice))) {
            throw new \ClientFence\Exception('Payment amount does not match the expected invoice total. Expected :expected, received :received.', [':expected' => number_format($expected, 2, '.', ''), ':received' => number_format($received, 2, '.', '')]);
        }

        // Warn on significant overpayments — this can indicate a misdirected
        // payment applied to the wrong invoice.
        $overpaymentTolerance = 1.00;
        if ($received > $expected + $overpaymentTolerance) {
            $this->di['logger']->warning(
                'Payment amount significantly exceeds the expected invoice total. Expected :expected, received :received.',
                [':expected' => number_format($expected, 2, '.', ''), ':received' => number_format($received, 2, '.', '')]
            );
        }
    }

    public function tryPayWithCredits(\Model_Invoice $invoice): bool
    {
        if (!$invoice->approved) {
            return false;
        }
        if ($invoice->status == \Model_Invoice::STATUS_PAID) {
            if (DEBUG) {
                $this->di['logger']->setChannel('billing')->info("Skipping credit payment for already paid invoice {$invoice->id}.");
            }

            return false;
        }

        // Reading the balance and marking the invoice paid without a lock lets two concurrent
        // requests for the same client each pass the balance check and each spend the same
        // credit. Serialize with a named lock scoped to the client rather than a row lock, since
        // the balance (Doctrine) and the invoice (RedBeanPHP) are written through separate
        // database connections here, and holding a row lock open on one while writing through the
        // other would block that write on itself for the lifetime of the request.
        $clientId = (int) $invoice->client_id;
        $lockName = 'fb:credit_payment:client:' . $clientId;
        $acquired = (int) $this->di['dbal']->fetchOne('SELECT GET_LOCK(:lock_name, 10)', ['lock_name' => $lockName]);
        if ($acquired !== 1) {
            throw new \ClientFence\Exception('Timed out waiting to process this credit payment.');
        }

        try {
            // Another request could have paid this invoice while we waited for the lock. A plain
            // read of the bean passed in could still reflect the state from before we waited, so
            // re-read it directly rather than trusting it.
            // ClientFence core patch (QA r7 X-02): the whole row, not only the status: a staff save that took the lock
            // first (19% tax) was charged and stored at the rate loaded before it (100 paid for 119, rate back to 0%).
            $current = $this->di['dbal']->fetchAssociative('SELECT * FROM invoice WHERE id = :id', ['id' => $invoice->id]);
            // ClientFence core patch: only an unpaid invoice is paid from credit (a refunded one was paid again).
            if (!is_array($current) || $current['status'] !== \Model_Invoice::STATUS_UNPAID) {
                return false;
            }
            foreach ($current as $column => $value) {
                $invoice->{$column} = $value;
            }

            // ClientFence core patch (QA r6 M-02): account credit never pays an Add funds invoice; it took the credit
            // and added none (InvoiceItem TYPE_DEPOSIT "cannot be charged with credits").
            if ($this->di['dbal']->fetchOne('SELECT 1 FROM invoice_item WHERE invoice_id = ? AND type = ? LIMIT 1', [$invoice->id, \Model_InvoiceItem::TYPE_DEPOSIT]) !== false) {
                return false;
            }

            $client = $this->di['db']->load('Client', $clientId);
            // ClientFence core patch: the balance is in the client's currency; it never pays an invoice in another one (30 EGP paid 22.80 USD).
            if (strtoupper((string) $client->currency) !== strtoupper((string) $invoice->currency)) {
                return false;
            }
            $cbrepo = $this->di['mod_service']('Client', 'Balance');
            // ClientFence core patch: what other unpaid invoices have received waits for them (WHMCS keeps it on the invoice).
            $balance = $cbrepo->getClientBalance($client) - $this->getReservedCredit($clientId, (int) $invoice->id);
            $required = $this->getTotalWithTax($invoice);
            // Compare at two-decimal monetary scale: balances are DECIMAL(18,2) sums while the
            // total is float arithmetic, so e.g. 0.30 and 0.1 * 3 differ as raw floats.
            if (round($balance, 2) < round($required, 2)) {
                // @phpstan-ignore if.alwaysFalse (DEBUG is a runtime constant that may be true during debugging)
                if (DEBUG) {
                    $this->di['logger']->setChannel('billing')->info("Invoice {$invoice->id} could not be paid with credits. Money in balance {$balance} Required: {$required}.");
                }

                return false;
            }

            // @phpstan-ignore if.alwaysFalse
            if (DEBUG) {
                $this->di['logger']->setChannel('billing')->info("Setting invoice {$invoice->id} as paid with credits for the amount of {$required}.");
            }

            // ClientFence core patch: the debit and the paid status are written together. A database failure between
            // them kept the money without paying the invoice, and the next cron took it again.
            $this->di['dbal']->transactional(function () use ($invoice, $clientId, $required): void {
                if ($required > 0.0) {
                    // Nothing is charged against the client's balance for a zero or negative invoice,
                    // so don't record a $0 credit transaction.
                    $balanceTransaction = $this->di['db']->dispense('ClientBalance');
                    $balanceTransaction->client_id = $clientId;
                    $balanceTransaction->type = 'invoice';
                    $balanceTransaction->rel_id = $invoice->id;

                    $invoice_identifier = $invoice->nr ?: $invoice->id;
                    $balanceTransaction->description = "Payment for invoice #{$invoice_identifier} using account credit.";

                    $balanceTransaction->amount = -$required;
                    $balanceTransaction->created_at = date('Y-m-d H:i:s');
                    $balanceTransaction->updated_at = date('Y-m-d H:i:s');
                    $this->di['db']->store($balanceTransaction);
                }

                // Events and tasks run after the lock is released below, so neither notifications nor
                // provisioning hold up a concurrent credit payment for the same client.
                $this->markAsPaid($invoice, false, false, true);
            });
        } finally {
            $this->di['dbal']->fetchOne('SELECT RELEASE_LOCK(:lock_name)', ['lock_name' => $lockName]);
        }

        $this->firePaymentReceivedEvent($invoice);
        $this->executeInvoiceItemTasks($invoice);

        return true;
    }

    /** The payments and applied credit of an invoice (imported WHMCS payments are netted on the invoice itself). */
    private const PAID_SQL = "((SELECT COALESCE(SUM(tx.amount), 0) FROM `transaction` tx WHERE tx.invoice_id = m.id AND tx.status = 'processed' AND COALESCE(tx.txn_status, '') <> 'imported')
            + (SELECT COALESCE(SUM(ic.amount), 0) FROM invoice_credit ic WHERE ic.invoice_id = m.id))";

    /**
     * ClientFence core patch: what an invoice has received (WHMCS "amount paid"). Payments and applied credit wait in
     * the client's balance, set aside for the invoice, until it is paid.
     */
    public function getAmountPaid(\Model_Invoice $invoice): float
    {
        return round((float) $this->di['dbal']->fetchOne('SELECT ' . self::PAID_SQL . ' FROM invoice m WHERE m.id = ?', [$invoice->id]), 2);
    }

    public function getBalanceDue(\Model_Invoice $invoice): float
    {
        return max(0.0, round($this->getTotalWithTax($invoice) - $this->getAmountPaid($invoice), 2));
    }

    /** The part of the client's balance set aside for their unpaid invoices (other than $exceptInvoiceId). */
    public function getReservedCredit(int $clientId, int $exceptInvoiceId = 0): float
    {
        return round((float) $this->di['dbal']->fetchOne(
            'SELECT COALESCE(SUM(LEAST(paid, total)), 0) FROM (SELECT ' . self::PAID_SQL . ' AS paid, ' . self::TOTAL_WITH_TAX_SQL . ' AS total
             FROM invoice m WHERE m.client_id = ? AND m.status = ? AND m.id <> ?
             AND NOT EXISTS (SELECT 1 FROM invoice_item d WHERE d.invoice_id = m.id AND d.type = ?)) x',
            [$clientId, \Model_Invoice::STATUS_UNPAID, $exceptInvoiceId, \Model_InvoiceItem::TYPE_DEPOSIT],
        ), 2);
    }

    /** WHMCS "credit balance": the balance less what is set aside for unpaid invoices. */
    public function getFreeCredit(int $clientId): float
    {
        return round((float) $this->di['dbal']->fetchOne('SELECT COALESCE(SUM(amount), 0) FROM client_balance WHERE client_id = ?', [$clientId]) - $this->getReservedCredit($clientId), 2);
    }

    /** ClientFence core patch: WHMCS "Apply Credit": any part of the client's free credit, up to the balance due. */
    public function applyCredit(\Model_Invoice $invoice, float $amount): bool
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new InformationException('Enter an amount greater than zero.');
        }
        $covered = Tools::withLock($this->di, 'fb:credit_payment:client:' . (int) $invoice->client_id, function () use ($invoice, $amount): bool {
            $current = $this->di['dbal']->fetchAssociative('SELECT * FROM invoice WHERE id = ?', [$invoice->id]);
            if ($current === false || $current['status'] !== \Model_Invoice::STATUS_UNPAID) {
                throw new InformationException('Credit can only be applied to an unpaid invoice.');
            }
            foreach ($current as $column => $value) {
                $invoice->{$column} = $value;
            }
            if ($this->isInvoiceTypeDeposit($invoice)) {
                throw new InformationException('You may not pay a deposit invoice with this payment gateway.');
            }
            $client = $this->di['db']->getExistingModelById('Client', (int) $invoice->client_id, 'Client not found');
            if (strtoupper((string) $client->currency) !== strtoupper((string) $invoice->currency)) {
                throw new InformationException('The credit is in :currency and cannot pay an invoice in another currency.', [':currency' => $client->currency]);
            }
            $due = $this->getBalanceDue($invoice);
            $max = min($due, $this->getFreeCredit((int) $client->id));
            if ($amount > $max) {
                throw new InformationException('You can apply at most :amount.', [':amount' => number_format(max(0.0, $max), 2) . ' ' . $invoice->currency]);
            }
            $this->di['dbal']->insert('invoice_credit', ['invoice_id' => $invoice->id, 'client_id' => $client->id, 'amount' => $amount, 'created_at' => date('Y-m-d H:i:s')]);

            return $amount >= $due;
        });
        if ($covered) {
            $this->tryPayWithCredits($invoice);
        }

        return true;
    }

    /** Staff take the credit applied to an unpaid invoice back to the client's free credit. */
    public function removeCredit(\Model_Invoice $invoice): bool
    {
        return Tools::withLock($this->di, 'fb:credit_payment:client:' . (int) $invoice->client_id, function () use ($invoice): bool {
            if ($this->di['dbal']->fetchOne('SELECT status FROM invoice WHERE id = ?', [$invoice->id]) !== \Model_Invoice::STATUS_UNPAID) {
                throw new InformationException('Credit can only be removed from an unpaid invoice.');
            }
            $this->di['dbal']->delete('invoice_credit', ['invoice_id' => $invoice->id]);

            return true;
        });
    }

    public function getTotalWithTax(\Model_Invoice $invoice): float
    {
        return $this->getTotal($invoice) + $this->getTax($invoice);
    }

    /** ClientFence core patch: tax once on the taxed subtotal, plus the cents that make a tax-inclusive total the shown price (applyInclusiveTax()). */
    public static function taxOf(float $taxable, float $rate, float $adjust): float
    {
        return $rate > 0 && $taxable != 0 ? round(round($taxable * $rate / 100, 2) + $adjust, 2) : 0.0;
    }

    public function getTax(\Model_Invoice $invoice): float
    {
        // taxrate is a varchar column that can hold any admin-supplied value,
        // so cast it: invalid rates behave as 0% instead of throwing.
        $taxRate = (float) $invoice->taxrate;
        if ($taxRate <= 0) {
            return 0.0;
        }

        $items = $this->di['db']->find('InvoiceItem', 'invoice_id = :iid', [':iid' => $invoice->id]);

        if (empty($items)) {
            return 0.0;
        }

        $taxable_subtotal = 0.0;
        foreach ($items as $item) {
            if ($item->taxed) {
                $taxable_subtotal += ($item->price * $item->quantity);
            }
        }

        return self::taxOf($taxable_subtotal, $taxRate, (float) $invoice->tax_adjust);
    }

    public function getTotal(\Model_Invoice $invoice): float
    {
        $total = 0;
        $invoiceItems = $this->di['db']->find('InvoiceItem', 'invoice_id = ?', [$invoice->id]) ?? [];
        $invoiceItemService = $this->di['mod_service']('Invoice', 'InvoiceItem');
        foreach ($invoiceItems as $item) {
            $total += $invoiceItemService->getTotal($item);
        }

        return (float) $total;
    }

    // ClientFence core patch (QA r9 P-02): unrelated invoice lines cannot fund this service's downgrade credit.
    private function paidForOrder(\Model_Invoice $invoice, int $orderId): float
    {
        $total = 0.0;
        $taxable = 0.0;
        $items = $this->di['db']->getAll(
            'SELECT type, rel_id, price, quantity, taxed FROM invoice_item WHERE invoice_id = ? AND ((type = ? AND rel_id = ?) OR type = ?)',
            [$invoice->id, \Model_InvoiceItem::TYPE_ORDER, (string) $orderId, \Model_InvoiceItem::TYPE_HOOK_CALL],
        );
        foreach ($items as $item) {
            if ($item['type'] === \Model_InvoiceItem::TYPE_HOOK_CALL) {
                $change = json_decode((string) $item['rel_id'], true);
                if (!is_array($change) || empty($change['upgrade_id']) || !$this->di['db']->getCell(
                    'SELECT 1 FROM ' . \ClientFence\Mod\Order\Upgrades::TABLE . ' WHERE id = ? AND order_id = ?',
                    [(int) $change['upgrade_id'], $orderId],
                )) {
                    continue;
                }
            }
            $amount = (float) $item['price'] * (float) $item['quantity'];
            $total += $amount;
            if ($item['taxed']) {
                $taxable += $amount;
            }
        }
        $rate = (float) $invoice->taxrate;

        return $total + ($rate > 0 ? round($taxable * $rate / 100, 2) : 0.0);
    }

    /**
     * ClientFence core patch: WHMCS refunds: an amount (default: what is left to refund) and, optionally, to the
     * client's credit or back through the gateway that took the payment (PayPal Email with its REST API keys).
     * A part refund keeps the invoice paid until its refunds reach the total.
     */
    public function refundInvoice(\Model_Invoice $invoice, $note = null, ?float $amount = null, bool $toCredit = false, bool $throughGateway = false): ?int
    {
        if ($toCredit && $throughGateway) {
            throw new InformationException('A refund to account credit cannot also be sent back through the payment gateway.');
        }
        // ClientFence core patch: under the client's credit lock, and the status is read again there: a
        // refund sent twice made two credit notes and debited a refunded Add funds twice.
        $record = function () use ($invoice, $note, $amount, $toCredit): ?int {
            if ($this->di['dbal']->fetchOne('SELECT status FROM invoice WHERE id = ?', [$invoice->id]) === \Model_Invoice::STATUS_REFUNDED) {
                throw new InformationException('Only a paid invoice can be refunded, and only once.');
            }
            $refunded = round((float) $this->di['dbal']->fetchOne('SELECT refund FROM invoice WHERE id = ?', [$invoice->id]), 2);
            if (($toCredit || $refunded > 0 || ($amount !== null && round($amount, 2) !== round($this->getTotalWithTax($invoice), 2))) && $this->di['mod_service']('system')->getParamValue('invoice_refund_logic', 'manual') === 'manual') {
                throw new InformationException('Refunds are handled by hand (Invoice settings), so this refund cannot be recorded.');
            }
            if ($refunded <= 0 && ($amount === null || round($amount, 2) === round($this->getTotalWithTax($invoice), 2))) {
                $result = $this->refundInvoiceLocked($invoice, $note);
                if ($toCredit && $result !== null) {
                    $this->refundToCredit($invoice, round($this->getTotalWithTax($invoice), 2), $result);
                }

                return $result;
            }

            return $this->refundPartLocked($invoice, $amount ?? round($this->getTotalWithTax($invoice) - $refunded, 2), $refunded, (string) $note, $toCredit);
        };
        $creditNote = Tools::withLock($this->di, 'fb:credit_payment:client:' . (int) $invoice->client_id, !$throughGateway ? $record : fn (): ?int => $this->di['dbal']->transactional(function () use ($record, $invoice): int {
            // Recorded first and kept only when the gateway refunds it: a refused refund leaves no credit note.
            $creditNote = $record() ?? throw new InformationException('Refunds are handled by hand (Invoice settings), so this refund cannot be recorded.');
            $this->refundThroughGateway($invoice, $creditNote);

            return $creditNote;
        }));
        if ($creditNote !== null) {
            // An Add funds refund takes credit back; it never adds any (refundToCredit()).
            $this->sendRefundEmail($invoice, $creditNote, $toCredit && !$this->isInvoiceTypeDeposit($invoice));
        }

        return $creditNote;
    }

    /**
     * ClientFence core patch: a completed payment of the invoice through a gateway that refunds by API (PayPal Email
     * with its REST API keys), as WHMCS refunds a transaction: the newest one with at least $amount left to refund,
     * or without $amount the one with the most left. {transaction, adapter, gateway, left}, or null.
     */
    public function gatewayRefundPayment(\Model_Invoice $invoice, ?float $amount = null): ?array
    {
        $rows = $this->di['dbal']->fetchAllAssociative(
            "SELECT id, txn_id, gateway_id, amount FROM transaction WHERE invoice_id = ? AND status = ? AND txn_status = 'Completed' AND amount > 0 AND txn_id IS NOT NULL AND txn_id <> '' ORDER BY id DESC",
            [(int) $invoice->id, \Model_Transaction::STATUS_PROCESSED],
        );
        $most = null;
        foreach ($rows as $row) {
            $gateway = $this->di['db']->load('PayGateway', (int) $row['gateway_id']);
            if (!$gateway instanceof \Model_PayGateway || !$gateway->id) {
                continue;
            }
            $adapter = $this->di['mod_service']('Invoice', 'PayGateway')->getPaymentAdapter($gateway);
            if (!ServiceSubscription::gatewayCan($adapter, 'refundPayment')) {
                continue;
            }
            $payment = ['transaction' => (string) $row['txn_id'], 'adapter' => $adapter, 'gateway' => (string) $gateway->name];
            $payment['left'] = round((float) $row['amount'] - $this->refundedThroughGateway($payment['transaction']), 2);
            if ($amount !== null && $payment['left'] >= round($amount, 2)) {
                return $payment;
            }
            if ($amount === null && $payment['left'] > ($most['left'] ?? 0)) {
                $most = $payment;
            }
        }

        return $most;
    }

    /** ClientFence core patch: what ClientFence refunded of a gateway payment: its credit notes carry "of payment <ID>." (refundThroughGateway()). */
    private function refundedThroughGateway(string $transaction): float
    {
        $refunded = 0.0;
        foreach ($this->di['dbal']->fetchFirstColumn('SELECT id FROM invoice WHERE notes LIKE ?', ['% of payment ' . $transaction . '.%']) as $id) {
            $refunded += abs($this->getTotalWithTax($this->di['db']->getExistingModelById('Invoice', (int) $id)));
        }

        return $refunded;
    }

    /** ClientFence core patch: sends the credit note's amount back through the invoice's gateway payment and notes the gateway's refund ID on it. */
    private function refundThroughGateway(\Model_Invoice $invoice, int $creditNoteId): void
    {
        $creditNote = $this->di['db']->getExistingModelById('Invoice', $creditNoteId);
        $amount = round(abs($this->getTotalWithTax($creditNote)), 2);
        $payment = $this->gatewayRefundPayment($invoice, $amount);
        if ($payment === null) {
            $left = $this->gatewayRefundPayment($invoice)['left'] ?? 0;
            if ($left > 0) {
                throw new InformationException('One payment of this invoice can be refunded through the payment gateway up to :amount: refund that, then record the rest.', [':amount' => number_format($left, 2) . ' ' . $invoice->currency]);
            }

            throw new InformationException('This invoice has no payment that can be refunded through the payment gateway.');
        }
        $refundId = $payment['adapter']->refundPayment($payment['transaction'], $amount, (string) $invoice->currency);
        // The PayPal IPN of this refund finds this note and records nothing more (PayPalEmail::refundedFromClientFence()).
        $this->addNote($creditNote, 'PayPal refund ' . $refundId . ' of payment ' . $payment['transaction'] . '.');
        $this->di['logger']->info('Invoice #%s: refunded %s %s through %s (refund %s).', $invoice->id, number_format($amount, 2), $invoice->currency, $payment['gateway'], $refundId);
    }

    /** ClientFence core patch: as WHMCS "Invoice Refund Confirmation", the client hears of each refund (the credit note's amount). */
    private function sendRefundEmail(\Model_Invoice $invoice, int $creditNote, bool $toCredit): void
    {
        try {
            $data = $this->toApiArray($this->di['db']->getExistingModelById('Invoice', (int) $invoice->id), true, null, true);
            $amount = abs($this->getTotalWithTax($this->di['db']->getExistingModelById('Invoice', $creditNote)));
            $this->di['mod_service']('email')->sendTemplate($this->withBillingRecipient([
                'to_client' => (int) $invoice->client_id,
                'code' => 'mod_invoice_refunded',
                'invoice' => $data,
                'refund' => ['amount' => $amount, 'to_credit' => $toCredit],
            ], $data));
        } catch (\Throwable $e) {
            $this->di['logger']->warning(sprintf('Invoice #%d: refund email not sent: %s', $invoice->id, $e->getMessage()));
        }
    }

    private function refundPartLocked(\Model_Invoice $invoice, float $amount, float $refunded, string $note, bool $toCredit): int
    {
        if ($invoice->status !== \Model_Invoice::STATUS_PAID) {
            throw new InformationException('Only a paid invoice can be refunded, and only once.');
        }
        $total = round($this->getTotalWithTax($invoice), 2);
        $amount = round($amount, 2);
        if ($amount <= 0 || $amount > round($total - $refunded, 2)) {
            throw new InformationException('You can refund at most :amount.', [':amount' => number_format(max(0.0, $total - $refunded), 2) . ' ' . $invoice->currency]);
        }
        // A downgrade credit of the service is settled by the full refund (or by hand), not by a part refund.
        if ($this->di['db']->getCell(
            "SELECT 1 FROM invoice cn JOIN invoice_item ci ON ci.invoice_id = cn.id JOIN invoice_item ii ON ii.rel_id = ci.rel_id AND ii.type = 'order'
             WHERE ii.invoice_id = ? AND ci.task = ? AND cn.created_at >= ? LIMIT 1",
            [$invoice->id, self::CREDIT_NOTE_TASK, (string) ($invoice->paid_at ?: $invoice->created_at)],
        )) {
            throw new InformationException('A downgrade gave account credit for this service. Refund this invoice by hand.');
        }
        $deposit = $this->isInvoiceTypeDeposit($invoice);
        if ($deposit && ($toCredit || $this->getFreeCredit((int) $invoice->client_id) < $amount)) {
            throw new InformationException('An Add funds refund takes its amount back from the client\'s credit, and the client has spent it. Refund this invoice by hand.');
        }

        $label = $invoice->serie . $invoice->nr;
        $rate = (float) $invoice->taxrate;
        // shortcut: the refund is taxed as a whole when the invoice has tax; mixed taxed and untaxed lines need a refund by hand.
        $taxed = $rate > 0 && $this->getTax($invoice) > 0;
        $creditNote = $this->creditNote((int) $invoice->id, 'Refund of invoice ' . $label, $taxed ? $amount / (1 + $rate / 100) : $amount, $taxed, $note !== '' ? $note : "Refund for #{$invoice->id} invoice.", null, $amount);
        if ($deposit) {
            $now = date('Y-m-d H:i:s');
            $this->di['dbal']->insert('client_balance', ['client_id' => (int) $invoice->client_id, 'type' => 'invoice', 'rel_id' => (string) $creditNote, 'description' => 'Refund of invoice ' . $label, 'amount' => -$amount, 'created_at' => $now, 'updated_at' => $now]);
        } elseif ($toCredit) {
            $this->refundToCredit($invoice, $amount, $creditNote);
        }

        $invoice->refund = round($refunded + $amount, 2);
        if ($invoice->refund >= $total) {
            $invoice->status = \Model_Invoice::STATUS_REFUNDED;
        }
        $invoice->updated_at = date('Y-m-d H:i:s');
        $this->countIncome($invoice);
        $this->addNote($invoice, 'Refunded ' . number_format($amount, 2) . ' ' . $invoice->currency . ($toCredit ? ' to account credit' : '') . ", credit note #{$creditNote}.");

        return $creditNote;
    }

    private function refundToCredit(\Model_Invoice $invoice, float $amount, int $creditNote): void
    {
        if ($this->isInvoiceTypeDeposit($invoice)) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $this->di['dbal']->insert('client_balance', ['client_id' => (int) $invoice->client_id, 'type' => 'invoice', 'rel_id' => (string) $creditNote, 'description' => 'Refund of invoice ' . $invoice->serie . $invoice->nr . ' to account credit', 'amount' => $amount, 'created_at' => $now, 'updated_at' => $now]);
    }

    private function refundInvoiceLocked(\Model_Invoice $invoice, $note = null): ?int
    {
        $this->di['events_manager']->fire(['event' => 'onBeforeAdminInvoiceRefund', 'params' => $this->toApiArray($invoice)]);

        $systemService = $this->di['mod_service']('system');
        $logic = $systemService->getParamValue('invoice_refund_logic', 'manual');
        $result = null;

        switch ($logic) {
            case 'credit_note':
            case 'negative_invoice':
                // ClientFence core patch: only a paid invoice, once; it is marked refunded below (refunded twice made two credit notes).
                if ($invoice->status !== \Model_Invoice::STATUS_PAID) {
                    throw new InformationException('Only a paid invoice can be refunded, and only once.');
                }
                $total = $this->getTotalWithTax($invoice);
                if ($total <= 0) {
                    throw new InformationException('Cannot refund invoice with negative amount');
                }
                // ClientFence core patch: a downgrade already gave part of what this invoice paid for back as account credit
                // (its credit notes name the service): the refund takes back the credit of the period this invoice paid
                // (from its payment to the next paid order or renewal invoice of the service), or the client got the
                // same money twice.
                $orders = array_unique(array_map('intval', array_column($this->di['db']->getAll(
                    "SELECT rel_id AS id FROM invoice_item WHERE invoice_id = ? AND type = 'order' UNION SELECT order_id AS id FROM " . \ClientFence\Mod\Order\Upgrades::TABLE . ' WHERE invoice_id = ?',
                    [$invoice->id, $invoice->id],
                ), 'id')));
                $creditNotes = [];
                $takeBack = 0.0;
                $from = (string) $invoice->paid_at;
                // ClientFence core patch (QA r10 D-04): with no payment date the period it paid is unknown, so a downgrade
                // credit of the service (not taken back yet) cannot be told apart: staff settle it. QA r10 P-02: so is one
                // given between the invoice's issue and its payment date (a paid date moved past it; a service is not
                // changed while its invoice is unpaid).
                foreach ($orders as $orderId) {
                    $open = 0.0;
                    $sql = 'SELECT DISTINCT cn.id FROM invoice cn JOIN invoice_item ci ON ci.invoice_id = cn.id WHERE ci.task = ? AND ci.rel_id = ?';
                    $params = [self::CREDIT_NOTE_TASK, (string) $orderId];
                    if ($from !== '') {
                        $sql .= ' AND cn.created_at >= ? AND cn.created_at < ?';
                        array_push($params, (string) $invoice->created_at, $from);
                    }
                    foreach ($this->di['db']->getAll($sql, $params) as $row) {
                        $cn = $this->di['db']->load('Invoice', (int) $row['id']);
                        $open += $cn instanceof \Model_Invoice ? abs($this->getTotalWithTax($cn)) : 0.0;
                    }
                    if ($open > 0) {
                        throw new InformationException('A downgrade already gave :amount back as account credit. Refund this invoice by hand.', [':amount' => number_format($open, 2) . ' ' . $invoice->currency]);
                    }
                }
                foreach ($from === '' ? [] : $orders as $orderId) {
                    $orderTakeBack = 0.0;
                    // A paid upgrade invoice also ends the period (QA r6 U-02): the credit after it is that invoice's money.
                    $until = $this->di['db']->getCell(
                        "SELECT MIN(p) FROM (SELECT i.paid_at AS p FROM invoice i JOIN invoice_item ii ON ii.invoice_id = i.id
                         WHERE ii.type = 'order' AND ii.rel_id = ? AND i.id <> ? AND i.status IN ('paid', 'refunded') AND i.paid_at > ?
                         UNION ALL SELECT i.paid_at FROM " . \ClientFence\Mod\Order\Upgrades::TABLE . " u JOIN invoice i ON i.id = u.invoice_id
                         WHERE u.order_id = ? AND i.id <> ? AND i.status IN ('paid', 'refunded') AND i.paid_at > ?) x",
                        [(string) $orderId, $invoice->id, $from, (int) $orderId, $invoice->id, $from],
                    );
                    $rows = $this->di['db']->getAll(
                        'SELECT DISTINCT cn.id FROM invoice cn JOIN invoice_item ci ON ci.invoice_id = cn.id
                         WHERE ci.task = ? AND ci.rel_id = ? AND cn.created_at >= ?' . ($until ? ' AND cn.created_at < ?' : ''),
                        array_merge([self::CREDIT_NOTE_TASK, (string) $orderId, $from], $until ? [$until] : []),
                    );
                    foreach ($rows as $row) {
                        $cn = $this->di['db']->load('Invoice', (int) $row['id']);
                        if ($cn instanceof \Model_Invoice && !in_array((int) $cn->id, $creditNotes, true)) {
                            $creditNotes[] = (int) $cn->id;
                            $credit = abs($this->getTotalWithTax($cn));
                            $orderTakeBack += $credit;
                            $takeBack += $credit;
                        }
                    }
                    if ($orderTakeBack > 0 && round($orderTakeBack, 2) > round($this->paidForOrder($invoice, $orderId), 2)) {
                        throw new InformationException('A downgrade already gave :amount back as account credit. Refund this invoice by hand.', [':amount' => number_format($orderTakeBack, 2) . ' ' . $invoice->currency]);
                    }
                    // ClientFence core patch (QA r8 P-02): later credit can include this payment when later invoices cannot fund it.
                    if ($until) {
                        $laterCredit = 0.0;
                        $credits = [];
                        $rows = $this->di['db']->getAll(
                            'SELECT DISTINCT cn.id FROM invoice cn JOIN invoice_item ci ON ci.invoice_id = cn.id
                             WHERE ci.task = ? AND ci.rel_id = ? AND cn.created_at >= ?',
                            [self::CREDIT_NOTE_TASK, (string) $orderId, $until],
                        );
                        foreach ($rows as $row) {
                            $cn = $this->di['db']->load('Invoice', (int) $row['id']);
                            if ($cn instanceof \Model_Invoice) {
                                $laterCredit += abs($this->getTotalWithTax($cn));
                                $credits[] = [(string) $cn->created_at, abs($this->getTotalWithTax($cn))];
                            }
                        }
                        if ($laterCredit > 0) {
                            $laterPaid = 0.0;
                            $payments = [];
                            $rows = $this->di['db']->getAll(
                                "SELECT i.id FROM invoice i JOIN invoice_item ii ON ii.invoice_id = i.id
                                 WHERE ii.type = 'order' AND ii.rel_id = ? AND i.status IN ('paid', 'refunded') AND i.paid_at >= ?
                                 UNION SELECT i.id FROM " . \ClientFence\Mod\Order\Upgrades::TABLE . " u JOIN invoice i ON i.id = u.invoice_id
                                 WHERE u.order_id = ? AND i.status IN ('paid', 'refunded') AND i.paid_at >= ?",
                                [(string) $orderId, $until, (int) $orderId, $until],
                            );
                            foreach ($rows as $row) {
                                $laterInvoice = $this->di['db']->load('Invoice', (int) $row['id']);
                                if ($laterInvoice instanceof \Model_Invoice) {
                                    // ClientFence core patch (QA r8 P-02 R3): refund invoices copy order lines but are not payments.
                                    $laterPaid += max(0.0, $this->paidForOrder($laterInvoice, $orderId));
                                    $payments[] = [(string) $laterInvoice->paid_at, max(0.0, $this->paidForOrder($laterInvoice, $orderId))];
                                }
                            }
                            if (round($laterCredit, 2) > round($laterPaid, 2)) {
                                throw new InformationException('A downgrade already gave :amount back as account credit. Refund this invoice by hand.', [':amount' => number_format($laterCredit, 2) . ' ' . $invoice->currency]);
                            }
                            // ClientFence core patch (QA r10 D-03): a credit is funded only by payments made before it (a renewal
                            // paid after a downgrade did not pay for that downgrade's credit), so the credit up to each note
                            // must not exceed what was paid up to it.
                            usort($credits, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
                            $creditSoFar = 0.0;
                            foreach ($credits as [$at, $amount]) {
                                $creditSoFar += $amount;
                                $paidSoFar = array_sum(array_map(static fn (array $p): float => $p[0] !== '' && $p[0] <= $at ? $p[1] : 0.0, $payments));
                                if (round($creditSoFar, 2) > round($paidSoFar, 2)) {
                                    throw new InformationException('A downgrade already gave :amount back as account credit. Refund this invoice by hand.', [':amount' => number_format($laterCredit, 2) . ' ' . $invoice->currency]);
                                }
                            }
                        }
                    }
                }
                // ClientFence core patch (QA r8 P-02): taking back only part of a credit note cannot safely settle it.
                $takeBack = round($takeBack, 2);
                if ($takeBack > round($total, 2)) {
                    throw new InformationException('A downgrade already gave :amount back as account credit. Refund this invoice by hand.', [':amount' => number_format($takeBack, 2) . ' ' . $invoice->currency]);
                }
                if ($takeBack > 0) {
                    $client = $this->di['db']->getExistingModelById('Client', (int) $invoice->client_id, 'Client not found');
                    if (round($this->di['mod_service']('Client', 'Balance')->getClientBalance($client) - $this->getReservedCredit((int) $invoice->client_id), 2) < $takeBack) {
                        throw new InformationException('A downgrade already gave :amount of this invoice back as account credit, and the client has spent it. Refund this invoice by hand.', [':amount' => number_format($takeBack, 2) . ' ' . $invoice->currency]);
                    }
                }

                // ClientFence core patch: the credit an Add funds invoice added may be spent or set aside for unpaid invoices.
                $deposit = 0.0;
                if ($this->isInvoiceTypeDeposit($invoice)) {
                    $depositItemService = $this->di['mod_service']('Invoice', 'InvoiceItem');
                    foreach ($this->di['db']->find('InvoiceItem', 'invoice_id = ?', [$invoice->id]) as $item) {
                        if ($item->type == \Model_InvoiceItem::TYPE_DEPOSIT) {
                            $deposit += $depositItemService->getTotalWithTax($item);
                        }
                    }
                    if ($deposit > 0 && $this->getFreeCredit((int) $invoice->client_id) < round($deposit, 2)) {
                        throw new InformationException('An Add funds refund takes its amount back from the client\'s credit, and the client has spent it. Refund this invoice by hand.');
                    }
                }

                $new = $this->di['db']->dispense('Invoice');
                $new->client_id = $invoice->client_id;
                $new->hash = bin2hex(random_bytes(random_int(15, 30)));
                $new->hash_expires_at = $this->computeHashExpiration();
                $new->status = \Model_Invoice::STATUS_REFUNDED;
                $new->currency = $invoice->currency;
                $new->approved = true;
                $new->taxname = $invoice->taxname;
                $new->taxrate = $invoice->taxrate;
                $new->tax_adjust = -(float) $invoice->tax_adjust;

                $new->seller_company = $invoice->seller_company;
                $new->seller_address = $invoice->seller_address;
                $new->seller_phone = $invoice->seller_phone;
                $new->seller_email = $invoice->seller_email;

                $new->buyer_first_name = $invoice->buyer_first_name;
                $new->buyer_last_name = $invoice->buyer_last_name;
                $new->buyer_company = $invoice->buyer_company;
                $new->buyer_address = $invoice->buyer_address;
                $new->buyer_city = $invoice->buyer_city;
                $new->buyer_state = $invoice->buyer_state;
                $new->buyer_country = $invoice->buyer_country;
                $new->buyer_phone = $invoice->buyer_phone;
                $new->buyer_email = $invoice->buyer_email;
                $new->buyer_zip = $invoice->buyer_zip;

                $new->paid_at = date('Y-m-d H:i:s');
                $new->created_at = date('Y-m-d H:i:s');
                $new->updated_at = date('Y-m-d H:i:s');
                $this->di['db']->store($new);

                $invoiceItems = $this->di['db']->find('InvoiceItem', 'invoice_id = ?', [$invoice->id]);
                foreach ($invoiceItems as $item) {
                    $pi = $this->di['db']->dispense('InvoiceItem');
                    $pi->invoice_id = $new->id;
                    $pi->type = $item->type;
                    $pi->rel_id = $item->rel_id;
                    $pi->task = $item->task;
                    $pi->status = \Model_InvoiceItem::STATUS_EXECUTED; // Mark refund invoice as executed
                    $pi->title = $item->title;
                    $pi->period = $item->period;
                    $pi->quantity = $item->quantity;
                    $pi->unit = $item->unit;
                    $pi->charged = 1;
                    $pi->price = -$item->price;
                    $pi->taxed = $item->taxed;
                    $pi->created_at = date('Y-m-d H:i:s');
                    $pi->updated_at = date('Y-m-d H:i:s');
                    $this->di['db']->store($pi);
                }

                $this->countIncome($new);

                // ClientFence core patch: refunding a paid wallet top-up (Add funds) left its amount on the
                // client's balance, so the client could still spend money that was paid back.
                if ($deposit > 0) {
                    $refundBalance = $this->di['db']->dispense('ClientBalance');
                    $refundBalance->client_id = $invoice->client_id;
                    $refundBalance->type = 'invoice';
                    $refundBalance->rel_id = $new->id;
                    $refundBalance->description = 'Refund of invoice ' . $invoice->serie . $invoice->nr;
                    $refundBalance->amount = -round($deposit, 2);
                    $refundBalance->created_at = date('Y-m-d H:i:s');
                    $refundBalance->updated_at = date('Y-m-d H:i:s');
                    $this->di['db']->store($refundBalance);
                }

                if ($takeBack > 0) {
                    $back = $this->di['db']->dispense('ClientBalance');
                    $back->client_id = $invoice->client_id;
                    $back->type = 'invoice';
                    $back->rel_id = $new->id;
                    $back->description = 'Downgrade credit taken back with the refund of invoice ' . $invoice->serie . $invoice->nr;
                    $back->amount = -$takeBack;
                    $back->created_at = $back->updated_at = date('Y-m-d H:i:s');
                    $this->di['db']->store($back);
                }
                if ($creditNotes !== []) {
                    $this->di['db']->exec('UPDATE invoice_item SET task = ? WHERE task = ? AND invoice_id IN (' . implode(',', $creditNotes) . ')', [self::CREDIT_NOTE_TAKEN_BACK_TASK, self::CREDIT_NOTE_TASK]);
                }

                $invoice->status = \Model_Invoice::STATUS_REFUNDED;
                $invoice->updated_at = date('Y-m-d H:i:s');
                $this->di['db']->store($invoice);

                $this->addNote($invoice, "Refund invoice #{$new->id} generated.");
                $this->addNote($new, "Refund for #{$invoice->id} invoice.");
                if (!empty($note)) {
                    $this->addNote($new, $note);
                }

                if ($logic == 'negative_invoice') {
                    $new->serie = (string) $systemService->getParamValue('invoice_series_paid') ?: $invoice->serie;
                    // ClientFence core patch: refund documents share the invoice series and must reserve its next number.
                    $new->nr = $this->getNextInvoiceNumber();
                    $this->di['db']->store($new);
                }

                if ($logic == 'credit_note') {
                    $this->numberCreditNote($new);
                }
                $result = (int) $new->id;

                break;

            case 'manual':
                // @phpstan-ignore if.alwaysFalse
                if (DEBUG) {
                    $this->di['logger']->warning('Refunds are managed manually. No actions performed.');
                }

                break;
            default:
                break;
        }

        $this->di['events_manager']->fire(['event' => 'onAfterAdminInvoiceRefund', 'params' => ['id' => $invoice->id]]);

        $this->di['logger']->info("Refunded invoice #{$invoice->id}.");

        return $result;
    }

    // ClientFence core patch: one number per credit note. The counter is read from the database
    // under a lock (getParamValue reads this request's cache); parallel refunds shared a number.
    // FOR UPDATE: a downgrade numbers its credit note inside a transaction that commits after the lock
    // is released; a plain read in another request would still see the old counter until then.
    private function numberCreditNote(\Model_Invoice $new): void
    {
        $systemService = $this->di['mod_service']('system');
        Tools::withLock($this->di, 'fb:invoice_cn_number', function () use ($systemService, $new): void {
            $next_nr = $this->di['dbal']->fetchOne('SELECT value FROM setting WHERE param = ? FOR UPDATE', ['invoice_cn_starting_number']) ?: 1;
            $new->serie = $systemService->getParamValue('invoice_cn_series', 'CN-');
            $new->nr = $next_nr;
            $this->di['db']->store($new);

            // update next credit note starting number
            $systemService->setParamValue('invoice_cn_starting_number', ++$next_nr, true);
        });
    }

    /**
     * ClientFence: a credit note for credit given back to the account (a downgrade's unused time), with the seller,
     * buyer and tax of the paid invoice it reverses, so the sales tax report shows the tax given back. No income is
     * counted: the money stays as account credit.
     */
    public function creditNote(int $sourceId, string $title, float $net, bool $taxed, string $note, ?int $orderId = null, ?float $total = null): int
    {
        $source = $this->di['db']->getExistingModelById('Invoice', $sourceId, 'Invoice not found');
        $new = $this->di['db']->dispense('Invoice');
        foreach (['client_id', 'currency', 'taxname', 'taxrate', 'seller_company', 'seller_company_vat', 'seller_company_number', 'seller_address', 'seller_phone', 'seller_email',
            'buyer_first_name', 'buyer_last_name', 'buyer_company', 'buyer_company_vat', 'buyer_company_number', 'buyer_address', 'buyer_city', 'buyer_state', 'buyer_country', 'buyer_phone', 'buyer_email', 'buyer_zip'] as $field) {
            $new->{$field} = $source->{$field};
        }
        $new->hash = bin2hex(random_bytes(random_int(15, 30)));
        $new->hash_expires_at = $this->computeHashExpiration();
        $new->status = \Model_Invoice::STATUS_REFUNDED;
        $new->approved = true;
        $new->paid_at = $new->created_at = $new->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($new);

        $item = $this->di['db']->dispense('InvoiceItem');
        $item->invoice_id = $new->id;
        $item->type = \Model_InvoiceItem::TYPE_CUSTOM;
        $item->task = self::CREDIT_NOTE_TASK;
        $item->rel_id = $orderId; // the first refund of an invoice of this order takes the credit back
        $item->status = \Model_InvoiceItem::STATUS_EXECUTED;
        $item->title = $title;
        $item->quantity = 1;
        $item->charged = 1;
        $item->price = -round($net, 2);
        $item->taxed = $taxed;
        $item->created_at = $item->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($item);
        // ClientFence core patch: a refund's credit note totals the amount refunded (its tax is that amount less the net).
        if ($total !== null && $taxed && (float) $new->taxrate > 0) {
            $tax = round(-$total - (float) $item->price, 2);
            $this->di['dbal']->update('invoice', ['tax_adjust' => round($tax - round((float) $item->price * (float) $new->taxrate / 100, 2), 2)], ['id' => (int) $new->id]);
        }

        $this->numberCreditNote($new);
        $this->addNote($new, $note);

        return (int) $new->id;
    }

    public function updateInvoice(\Model_Invoice $model, array $data): bool
    {
        // ClientFence core patch: under the client's credit lock, with the status and paid date as they are now. A save
        // that raced a credit payment wrote back what it loaded before it: the paid invoice was unpaid again (and
        // charged again later), or showed a payment it never had.
        return Tools::withLock($this->di, 'fb:credit_payment:client:' . (int) $model->client_id, function () use ($model, $data): bool {
            $this->refreshStatus($model);
            return $this->updateInvoiceLocked($model, $data);
        });
    }

    /**
     * ClientFence core patch (QA r10 P-01): the Manage form sends the date alone; the same day keeps the stored time (midnight
     * moved a renewal paid after a downgrade before it, so a refund counted it as funding that credit).
     */
    private function formTime(string $posted, ?string $stored): string
    {
        $at = date('Y-m-d H:i:s', (int) strtotime($posted));

        return strlen(trim($posted)) === 10 && $stored !== null && substr($stored, 0, 10) === substr($at, 0, 10) ? $stored : $at;
    }

    private function updateInvoiceLocked(\Model_Invoice $model, array $data): bool
    {
        $invoiceItemService = $this->di['mod_service']('Invoice', 'InvoiceItem');
        $previousStatus = $model->status;
        $amountChanged = $this->changesAmount($model, $data);
        if ($this->isBooked($model) && $amountChanged) {
            throw new InformationException('The lines and tax rate of a paid invoice cannot be changed. Refund it and make a new invoice.');
        }
        // ClientFence core patch: the status cannot undo a payment or fake a refund (a paid invoice set to
        // unpaid or canceled lost its locks); a refund goes through Refund, which makes the credit note.
        $newStatus = $data['status'] ?? $previousStatus;
        $markPaid = $newStatus === \Model_Invoice::STATUS_PAID && $previousStatus !== \Model_Invoice::STATUS_PAID;
        // ClientFence core patch: Manage cannot record the payment that credits an Add Funds invoice.
        if ($markPaid && $this->isInvoiceTypeDeposit($model)) {
            throw new InformationException('Use Mark as Paid to record payment for an Add Funds invoice.');
        }
        // ClientFence core patch: Manage uses the recorded payments of a partly paid invoice it sets to Paid (the rest is written off).
        $credited = $markPaid ? $this->getAmountPaid($model) : 0.0;
        if ($newStatus !== $previousStatus && ($this->isBooked($model) || $newStatus === \Model_Invoice::STATUS_REFUNDED)) {
            throw new InformationException('A paid or refunded invoice keeps its status, and only Refund marks an invoice refunded.');
        }
        // ClientFence core patch (QA r10 C-6): the issue and paid dates of a paid invoice are booked too (moved, they changed
        // which period a refund takes downgrade credit back from: R1-01, R1-02).
        if ($this->isBooked($model) && $this->movesDates($model, $data)) {
            throw new InformationException('The issue and payment dates of a paid invoice cannot be changed.');
        }

        $this->di['events_manager']->fire(['event' => 'onBeforeAdminInvoiceUpdate', 'params' => $data]);

        if (!empty($data['gateway_id'])) {
            $gateway = $this->di['db']->load('PayGateway', $data['gateway_id']);
            if (!$gateway instanceof \Model_PayGateway) {
                throw new InformationException('Payment gateway not found');
            }
            if (!$gateway->enabled) {
                throw new InformationException('Payment gateway is not enabled');
            }
            $model->gateway_id = intval($data['gateway_id']);
        } elseif (array_key_exists('gateway_id', $data) && $data['gateway_id'] === null) {
            $model->gateway_id = null;
        }
        $model->text_1 = $data['text_1'] ?? (empty($model->text_1) ? null : $model->text_1);
        $model->text_2 = $data['text_2'] ?? (empty($model->text_2) ? null : $model->text_2);
        $model->seller_company = $data['seller_company'] ?? (empty($model->seller_company) ? null : $model->seller_company);
        $model->seller_company_vat = $data['seller_company_vat'] ?? (empty($model->seller_company_vat) ? null : $model->seller_company_vat);
        $model->seller_company_number = $data['seller_company_number'] ?? (empty($model->seller_company_number) ? null : $model->seller_company_number);
        $model->seller_address = $data['seller_address'] ?? (empty($model->seller_address) ? null : $model->seller_address);
        $model->seller_phone = $data['seller_phone'] ?? (empty($model->seller_phone) ? null : $model->seller_phone);
        $model->seller_email = $data['seller_email'] ?? (empty($model->seller_email) ? null : $model->seller_email);
        $model->buyer_first_name = $data['buyer_first_name'] ?? (empty($model->buyer_first_name) ? null : $model->buyer_first_name);
        $model->buyer_last_name = $data['buyer_last_name'] ?? (empty($model->buyer_last_name) ? null : $model->buyer_last_name);
        $model->buyer_company = $data['buyer_company'] ?? (empty($model->buyer_company) ? null : $model->buyer_company);
        $model->buyer_company_vat = $data['buyer_company_vat'] ?? (empty($model->buyer_company_vat) ? null : $model->buyer_company_vat);
        $model->buyer_company_number = $data['buyer_company_number'] ?? (empty($model->buyer_company_number) ? null : $model->buyer_company_number);
        $model->buyer_address = $data['buyer_address'] ?? (empty($model->buyer_address) ? null : $model->buyer_address);
        $model->buyer_city = $data['buyer_city'] ?? (empty($model->buyer_city) ? null : $model->buyer_city);
        $model->buyer_state = $data['buyer_state'] ?? (empty($model->buyer_state) ? null : $model->buyer_state);
        $model->buyer_country = $data['buyer_country'] ?? (empty($model->buyer_country) ? null : $model->buyer_country);
        $model->buyer_zip = $data['buyer_zip'] ?? (empty($model->buyer_zip) ? null : $model->buyer_zip);
        $model->buyer_phone = $data['buyer_phone'] ?? (empty($model->buyer_phone) ? null : $model->buyer_phone);
        $model->buyer_email = $data['buyer_email'] ?? (empty($model->buyer_email) ? null : $model->buyer_email);

        $paid_at = $data['paid_at'] ?? $model->paid_at;
        if (empty($paid_at)) {
            $model->paid_at = null;
        } else {
            $model->paid_at = $this->formTime((string) $paid_at, $model->paid_at);
        }

        $due_at = $data['due_at'] ?? $model->due_at;
        if (empty($due_at)) {
            $model->due_at = null;
        } else {
            $model->due_at = date('Y-m-d H:i:s', strtotime((string) $due_at));
        }

        $model->serie = $data['serie'] ?? (empty($model->serie) ? null : $model->serie);
        $model->nr = $data['nr'] ?? (empty($model->nr) ? null : $model->nr);
        $model->status = $markPaid ? $previousStatus : ($data['status'] ?? (empty($model->status) ? null : $model->status));
        // ClientFence core patch (QA r10 C-6): a payment recorded here is booked when it is recorded, as Mark as paid does (a
        // date before it put a renewal paid after a downgrade before that credit, so a refund counted it as funding it).
        if ($markPaid) {
            $model->paid_at = date('Y-m-d H:i:s');
        }
        $model->taxrate = $data['taxrate'] ?? (empty($model->taxrate) ? null : $model->taxrate);
        $model->taxname = $data['taxname'] ?? (empty($model->taxname) ? null : $model->taxname);
        // ClientFence core patch: lines and rates staff type are net, so the inclusive rounding cents no longer apply.
        // shortcut: when lines change (here, or an order's lines are deleted) the cents are dropped and the total can be a cent off the
        // shown price; lines added or deleted through the item API keep them. Recompute from stored gross prices if that matters.
        if ($amountChanged) {
            $model->tax_adjust = 0.0;
        }
        $model->approved = (int) ($data['approved'] ?? (empty($model->approved) ? null : $model->approved));
        $model->notes = $data['notes'] ?? (empty($model->notes) ? null : $model->notes);

        $created_at = $data['created_at'] ?? '';
        if (!empty($created_at)) {
            $model->created_at = $this->formTime((string) $created_at, $model->created_at);
        }

        $ni = $data['new_item'] ?? [];
        if (isset($ni['title']) && !empty($ni['title'])) {
            $invoiceItemService->addNew($model, $ni);
        }

        $items = $data['items'] ?? [];
        foreach ($items as $id => $d) {
            $item = $this->di['db']->load('InvoiceItem', $id);
            // ClientFence core patch: only this invoice's own lines.
            if ($item instanceof \Model_InvoiceItem && (int) $item->invoice_id === (int) $model->id) {
                $invoiceItemService->update($item, $d);
            }
        }

        $model->updated_at = date('Y-m-d H:i:s');

        $this->di['db']->store($model);

        // ClientFence core patch: a paid status saved on Manage needs the same payment event and tasks.
        if ($markPaid) {
            // Capped at the total this save leaves (it can change the lines and the tax rate).
            $this->useRecordedPayments($model, min($credited, round($this->getTotalWithTax($model), 2)));
            $this->markAsPaid($model, false);
        }

        // ClientFence core patch: cancelling an invoice gave its applied credit back; a reopened one starts without it.
        if ($previousStatus === \Model_Invoice::STATUS_CANCELED && $model->status === \Model_Invoice::STATUS_UNPAID) {
            $this->di['dbal']->delete('invoice_credit', ['invoice_id' => $model->id]);
        }

        if ($previousStatus === \Model_Invoice::STATUS_UNPAID && $model->status === \Model_Invoice::STATUS_CANCELED) {
            $productService = $this->di['mod_service']('Product');
            $productService->releaseReservedPromoRedemptionsForInvoice($model, 'invoice_canceled');
        }

        $this->di['events_manager']->fire(['event' => 'onAfterAdminInvoiceUpdate', 'params' => $this->toApiArray($model)]);

        $this->di['logger']->info("Updated invoice {$model->id}.");

        return true;
    }

    public function rmInvoice(\Model_Invoice $model): bool
    {
        $productService = $this->di['mod_service']('Product');
        $productService->releaseReservedPromoRedemptionsForInvoice($model, 'invoice_deleted');

        // remove related invoice from orders
        $sql = '
            UPDATE client_order
            SET unpaid_invoice_id = NULL
            WHERE unpaid_invoice_id = :id';
        $this->di['db']->exec($sql, ['id' => $model->id]);

        $invoiceItems = $this->di['db']->find('InvoiceItem', 'invoice_id = ?', [$model->id]);
        foreach ($invoiceItems as $item) {
            $this->di['db']->trash($item);
        }
        $this->di['db']->trash($model);

        return true;
    }

    /** ClientFence: whether catalog prices include tax ("Prices include tax" in the Tax settings, WHMCS Inclusive Tax). */
    public function pricesIncludeTax(): bool
    {
        return (int) $this->di['dbal']->fetchOne("SELECT COUNT(*) FROM setting WHERE param IN ('tax_enabled', 'tax_inclusive') AND value = '1'") === 2;
    }

    /**
     * ClientFence: with prices that include tax, the lines of a new invoice priced from the catalog (orders, renewals)
     * hold the net price, so its total is the price the client was shown. A client exempt from tax pays the net price,
     * at the rate of their location (WHMCS "deduct tax amount for exempt clients"). A deposit line is left alone.
     * As WHMCS, the tax is the shown total less the net: the cents its rounding needs are kept in tax_adjust.
     */
    public function applyInclusiveTax(\Model_Invoice $invoice): void
    {
        if (!$this->pricesIncludeTax()) {
            return;
        }
        $rate = (float) $invoice->taxrate;
        if ($rate <= 0) {
            $client = $this->di['db']->load('Client', (int) $invoice->client_id);
            $rate = $client instanceof \Model_Client ? (float) $this->di['mod_service']('Invoice', 'Tax')->getLocationTaxRate($client) : 0.0;
        }
        if ($rate <= 0) {
            return;
        }
        $db = $this->di['dbal'];
        $taxedSql = 'SELECT COALESCE(SUM(price * quantity), 0) FROM invoice_item WHERE invoice_id = ? AND type <> ? AND taxed = 1';
        $args = [(int) $invoice->id, \Model_InvoiceItem::TYPE_DEPOSIT];
        $gross = round((float) $db->fetchOne($taxedSql, $args), 2);
        $db->executeStatement('UPDATE invoice_item SET price = ROUND(price * 100 / (100 + ?), 2) WHERE invoice_id = ? AND type <> ?', [$rate, ...$args]);
        $taxRate = (float) $invoice->taxrate;
        $net = round((float) $db->fetchOne($taxedSql, $args), 2);
        $invoice->tax_adjust = $taxRate > 0 && $gross != 0 ? round($gross - $net - round($net * $taxRate / 100, 2), 2) : 0.0;
        $db->update('invoice', ['tax_adjust' => $invoice->tax_adjust], ['id' => (int) $invoice->id]);
    }

    /** ClientFence: a paid or refunded invoice has its payment and income booked, so its amount is fixed. */
    public function isBooked(\Model_Invoice $model): bool
    {
        return in_array($model->status, [\Model_Invoice::STATUS_PAID, \Model_Invoice::STATUS_REFUNDED], true);
    }

    /** QA r10 C-6: whether an update moves or clears the issue or paid date (a date-only value on the same day does not). */
    private function movesDates(\Model_Invoice $model, array $data): bool
    {
        foreach (['created_at', 'paid_at'] as $field) {
            $posted = $data[$field] ?? null;
            if ($posted === null || ($field === 'created_at' && empty($posted))) {
                continue;
            }
            $stored = $model->{$field} === null || $model->{$field} === '' ? null : (string) $model->{$field};
            if (empty($posted) ? $stored !== null : $this->formTime((string) $posted, $stored) !== $stored) {
                return true;
            }
        }

        return false;
    }

    /** ClientFence: whether an update changes the tax rate, adds a line, or changes a line's price, quantity or tax. */
    private function changesAmount(\Model_Invoice $model, array $data): bool
    {
        if (isset($data['taxrate']) && round((float) $data['taxrate'], 4) !== round((float) $model->taxrate, 4)) {
            return true;
        }
        if (!empty($data['new_item']['title'])) {
            return true;
        }
        foreach ($data['items'] ?? [] as $id => $d) {
            $item = $this->di['db']->load('InvoiceItem', $id);
            if (!$item instanceof \Model_InvoiceItem || (int) $item->invoice_id !== (int) $model->id || !is_array($d)) {
                continue;
            }
            if ((isset($d['price']) && round((float) $d['price'], 2) !== round((float) $item->price, 2))
                || (array_key_exists('quantity', $d) && (int) $d['quantity'] !== (int) $item->quantity)
                || !empty($d['taxed']) !== (bool) $item->taxed) {
                return true;
            }
        }

        return false;
    }

    public function deleteInvoiceByAdmin(\Model_Invoice $model): bool
    {
        // ClientFence core patch: under the client's credit lock, with the status as it is now (a delete racing a credit
        // payment removed the invoice after the payment took the money).
        return Tools::withLock($this->di, 'fb:credit_payment:client:' . (int) $model->client_id, function () use ($model): bool {
            $this->refreshStatus($model);

            return $this->deleteInvoiceByAdminLocked($model);
        });
    }

    /** ClientFence: the status and paid date as they are in the database now (another request may have paid it). */
    public function refreshStatus(\Model_Invoice $model): void
    {
        $now = $this->di['dbal']->fetchAssociative('SELECT status, paid_at FROM invoice WHERE id = ?', [(int) $model->id]);
        if (is_array($now) && isset($now['status'])) {
            $model->status = $now['status'];
            $model->paid_at = $now['paid_at'];
        }
    }

    private function deleteInvoiceByAdminLocked(\Model_Invoice $model): bool
    {
        // ClientFence core patch: a paid or refunded invoice stays (its payment and income would be left pointing at nothing).
        if ($this->isBooked($model)) {
            throw new InformationException('A paid or refunded invoice cannot be deleted. Refund it instead.');
        }
        $this->di['events_manager']->fire(['event' => 'onBeforeAdminInvoiceDelete', 'params' => ['id' => $model->id]]);

        $id = $model->id;
        $this->rmInvoice($model);

        $this->di['events_manager']->fire(['event' => 'onAfterAdminInvoiceDelete', 'params' => ['id' => $id]]);

        $this->di['logger']->info('Removed invoice #%s', $id);

        return true;
    }

    public function renewInvoice(\Model_ClientOrder $model, array $data)
    {
        $this->di['events_manager']->fire(['event' => 'onBeforeAdminGenerateRenewalInvoice', 'params' => ['order_id' => $model->id]]);

        $due_days = isset($data['due_days']) ? (int) $data['due_days'] : null;
        // ClientFence core patch (QA r6 M-08): made and paid under the order's lock, as the client's Renew Now. A second
        // request within a minute of a paid renewal (a double click, or staff and the client at once) is refused: it
        // charged the client twice for one period.
        $invoice = Tools::withLock($this->di, 'fb:renewal:order:' . (int) $model->id, function () use ($model, $due_days): \Model_Invoice {
            $paidAt = $this->di['dbal']->fetchOne(
                'SELECT MAX(i.paid_at) FROM invoice i JOIN invoice_item ii ON ii.invoice_id = i.id WHERE ii.type = ? AND ii.rel_id = ? AND i.status = ?',
                [\Model_InvoiceItem::TYPE_ORDER, (string) $model->id, \Model_Invoice::STATUS_PAID]
            );
            if (is_string($paidAt) && strtotime($paidAt) > time() - 60) {
                throw new \ClientFence\InformationException('This order was renewed a moment ago. Reload the page to see it.');
            }
            $invoice = $this->generateForOrder($model, $due_days);
            $this->approveInvoice($invoice, ['id' => $invoice->id, 'use_credits' => true]);

            return $invoice;
        });

        $this->di['events_manager']->fire(['event' => 'onAfterAdminGenerateRenewalInvoice', 'params' => ['order_id' => $model->id, 'id' => $invoice->id]]);

        $this->di['logger']->info("Generated renewal invoice #{$invoice->id}.");

        return $invoice->id;
    }

    public function doBatchPayWithCredits(array $data): bool
    {
        $unpaid = $this->findAllUnpaid($data);
        foreach ($unpaid as $proforma) {
            try {
                $model = $this->di['db']->getExistingModelById('Invoice', $proforma['id'] ?? null);
                $this->tryPayWithCredits($model);
            } catch (\Exception $e) {
                // @phpstan-ignore if.alwaysFalse
                if (DEBUG) {
                    $this->di['logger']->warning($e->getMessage());
                }
            }
        }
        $this->di['logger']->info('Executed action to try cover unpaid invoices with client credits.');

        return true;
    }

    public function payInvoiceWithCredits(\Model_Invoice $model): bool
    {
        $this->tryPayWithCredits($model);
        $this->di['logger']->info('Cover invoice with client credits.');

        return true;
    }

    /**
     * @param int $due_days
     *
     * @return \Model_Invoice
     */
    public function generateForOrder(\Model_ClientOrder $order, $due_days = null)
    {
        // ClientFence core patch: one renewal invoice per order. Under the order's lock the unpaid invoice is
        // read again: "Renew Now" clicked twice made two invoices, each paid from credit.
        return Tools::withLock($this->di, 'fb:renewal:order:' . (int) $order->id, function () use ($order, $due_days) {
            $unpaid = $this->di['dbal']->fetchOne('SELECT unpaid_invoice_id FROM client_order WHERE id = ?', [$order->id]);
            if ($unpaid) {
                $order->unpaid_invoice_id = (int) $unpaid;
            }

            return $this->generateForOrderLocked($order, $due_days);
        });
    }

    private function generateForOrderLocked(\Model_ClientOrder $order, $due_days = null)
    {
        // check if we do have invoice prepared already
        if ($order->unpaid_invoice_id !== null) {
            $p = $this->di['db']->load('Invoice', $order->unpaid_invoice_id);
            if ($p instanceof \Model_Invoice && $p->status === \Model_Invoice::STATUS_UNPAID) {
                return $p;
            }

            $orderService = $this->di['mod_service']('Order');
            $orderService->unsetUnpaidInvoice($order);
        }

        $price = $order->price;
        $line = [
            'price' => $order->price,
            'quantity' => $order->quantity,
        ];

        // Domain renewal pricing is resolved from the registrar/config rather than
        // the order, since it legitimately changes between registration and renewal.
        // Other products keep the order's own price so admin-edited prices are respected.
        if (in_array($order->status, [
            \Model_ClientOrder::STATUS_ACTIVE,
            \Model_ClientOrder::STATUS_FAILED_RENEW,
            \Model_ClientOrder::STATUS_SUSPENDED,
        ], true)) {
            $productService = $this->di['mod_service']('Product');
            $product = $productService->findProductById((int) $order->product_id);

            if ($productService instanceof \ClientFence\Mod\Product\Service && $product->getType() === \ClientFence\Mod\Product\Service::DOMAIN) {
                $config = json_decode($order->config ?? '', true) ?? [];
                $currencyService = $this->di['mod_service']('Currency');
                $currencyRepository = $currencyService->getCurrencyRepository();
                $rate = $currencyRepository->getRateByCode($order->currency);
                if ($rate === null) {
                    throw new \ClientFence\Exception("Currency rate for '{$order->currency}' is not configured");
                }

                // ClientFence core patch: fixed currency prices (Product/CurrencyPrices.php).
                $renewalLine = $productService->inCurrency((string) $order->currency)->getProductRenewalLineConfig($product, $config);
                $price = $renewalLine['price'] * $rate;
                // ClientFence core patch: a free domain renews free while its hosting lasts (WHMCS free domain renewal).
                if ($productService->hasFreeDomainRenewal((int) $order->id)) {
                    $price = 0.0;
                }
                $line = [
                    'price' => $price,
                    'quantity' => $renewalLine['quantity'],
                ];
            }
        }

        if (($price * ($line['quantity'] ?? 1)) < 0) {
            throw new InformationException('Invoices are not generated for negative amount orders.');
        }

        $client = $this->di['db']->getExistingModelById('Client', $order->client_id, 'Client not found');

        if (!in_array($order->status, [\Model_ClientOrder::STATUS_PENDING_SETUP, \Model_ClientOrder::STATUS_FAILED_SETUP], true)) {
            \ClientFence\Mod\Order\Upgrades::cancelWaiting($this->di, (int) $order->id);
        }

        // generate proforma after validating the resolved renewal amount
        $proforma = $this->di['db']->dispense('Invoice');
        $proforma->client_id = $client->id;
        $proforma->status = \Model_Invoice::STATUS_UNPAID;
        $proforma->currency = $order->currency;
        $proforma->approved = false;
        $proforma->created_at = date('Y-m-d H:i:s');
        $proforma->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($proforma);

        $this->setInvoiceDefaults($proforma);

        $invoiceItemService = $this->di['mod_service']('Invoice', 'InvoiceItem');
        // ClientFence core patch: an order that was never activated (staff create orders with an invoice,
        // sign-up is off) is activated by this invoice. As a renewal, paying it failed with "Order
        // has no active service" and nothing was provisioned (found in the Enhance end-to-end test).
        $task = in_array($order->status, [\Model_ClientOrder::STATUS_PENDING_SETUP, \Model_ClientOrder::STATUS_FAILED_SETUP], true)
            ? \Model_InvoiceItem::TASK_ACTIVATE
            : \Model_InvoiceItem::TASK_RENEW;
        $invoiceItemService->generateFromOrder($proforma, $order, $task, $price, $line);
        // ClientFence core patch: catalog prices that include tax (WHMCS Inclusive Tax).
        $this->applyInclusiveTax($proforma);

        // invoice due date
        if ($due_days > 0) {
            $proforma->due_at = date('Y-m-d H:i:s', strtotime('+' . $due_days . ' days'));
            $this->di['db']->store($proforma);
        } elseif ($order->expires_at) {
            $proforma->due_at = $order->expires_at;
            $this->di['db']->store($proforma);
        }

        return $proforma;
    }

    // ClientFence core patch: selected renewals preserve the single-order generation and locking path.
    public function batchRenewalInvoice(mixed $ids): array
    {
        if (!is_array($ids) || $ids === []) {
            throw new InformationException('Order IDs are required');
        }
        $orders = [];
        $clientId = null;
        foreach ($ids as $id) {
            $order = $this->di['db']->getExistingModelById('ClientOrder', (int) $id, 'Order not found');
            if ($clientId !== null && $clientId !== (int) $order->client_id) {
                throw new InformationException('Selected orders must belong to one client');
            }
            $clientId = (int) $order->client_id;
            $orders[(int) $id] = $order;
        }
        $created = $skipped = [];
        foreach ($orders as $id => $order) {
            $invoiceId = Tools::withLock($this->di, 'fb:renewal:order:' . $id, function () use ($order): ?int {
                $unpaid = $this->di['dbal']->fetchOne(
                    'SELECT i.id FROM client_order co JOIN invoice i ON i.id = co.unpaid_invoice_id WHERE co.id = ? AND i.status = ?',
                    [(int) $order->id, \Model_Invoice::STATUS_UNPAID]
                );
                if ($unpaid !== false) {
                    return null;
                }

                return (int) $this->renewInvoice($order, []);
            });
            if ($invoiceId === null) {
                $skipped[] = $id;
            } else {
                $created[] = $invoiceId;
            }
        }

        return ['invoice_ids' => $created, 'skipped_order_ids' => $skipped];
    }

    public function generateInvoicesForExpiringOrders(?int $clientId = null): bool
    {
        $orderService = $this->di['mod_service']('Order');
        $orders = $orderService->getSoonExpiringActiveOrders($clientId === null ? [] : ['client_id' => $clientId]);

        if (Tools::safeCount($orders) == 0) {
            return true;
        }

        foreach ($orders as $order) {
            try {
                // ClientFence core patch: due-order selection can change before the renewal lock is acquired.
                Tools::withLock($this->di, 'fb:renewal:order:' . (int) $order['id'], function () use ($order, $orderService): void {
                    if ($orderService->getSoonExpiringActiveOrders(['id' => $order['id']]) === []) {
                        return;
                    }
                    $model = $this->di['db']->getExistingModelById('ClientOrder', $order['id']);
                    $invoice = $this->generateForOrder($model);
                    $this->approveInvoice($invoice, ['id' => $invoice->id, 'use_credits' => true]);
                });
            } catch (\Exception $e) {
                $this->di['logger']->warning($e->getMessage());
            }
        }

        $this->di['logger']->info('Executed action to generate new invoices for expiring orders.');

        return true;
    }

    public function doBatchPaidInvoiceActivation(): bool
    {
        $invoiceItemService = $this->di['mod_service']('Invoice', 'InvoiceItem');

        $invoiceItems = (array) $invoiceItemService->getAllNotExecutePaidItems();
        foreach ($invoiceItems as $item) {
            try {
                $model = $this->di['db']->getExistingModelById('InvoiceItem', $item['id'] ?? 0);
                $invoiceItemService->executeTask($model);
            } catch (\Exception $e) {
                $this->di['logger']->error($e->getMessage());
            }
        }
        $this->di['logger']->info('Executed action to activate paid invoices.');

        return true;
    }

    public function doBatchRemindersSend(): bool
    {
        $this->di['events_manager']->fire(['event' => 'onBeforeAdminInvoiceSendReminders']);
        $result = $this->doBatchInvokeDueEvent(['once_per_day' => true]);
        if (!$result) {
            // Pick up invoices that became reminder-eligible after today's due-event batch ran.
            $result = $this->doBatchInvokePendingReminderEvents();
        }
        if ($result) {
            $this->di['logger']->info('Executed action to send invoice payment reminders.');
        }

        return $result;
    }

    public function doBatchInvokeDueEvent(array $data): bool
    {
        $once_per_day = isset($data['once_per_day']) ? (bool) $data['once_per_day'] : true;
        $key = 'invoice_overdue_invoked';

        // do not use api call to get system param to avoid invoking system module event hooks
        $ss = $this->di['mod_service']('System');
        $last_time = $ss->getParamValue($key);
        if ($once_per_day && $last_time && (time() - strtotime((string) $last_time)) < 86400) {
            // error_log('Already executed today.');
            return false;
        }

        $this->fireDueReminderEvents();

        $ss->setParamValue($key, date('Y-m-d H:i:s'));
        $this->di['logger']->info('Executed action to invoke invoice due event');

        return true;
    }

    protected function doBatchInvokePendingReminderEvents(): bool
    {
        $this->fireDueReminderEvents();

        return true;
    }

    /**
     * Fires the before/after due-date events for every unpaid, approved invoice in range, same
     * as before this class started reminder-throttling. Listeners (built-in or third-party
     * extensions) decide for themselves whether a given invoice is actionable; this method does
     * not filter by reminder interval so it does not narrow what extensions can observe. The
     * built-in reminder handlers (onEventBeforeInvoiceIsDue, onEventAfterInvoiceIsDue) are the
     * ones responsible for matching the configured interval and atomically claiming the invoice
     * via reminded_at before actually sending anything, which is what keeps overlapping cron
     * runs and repeated dispatch of this same event from sending duplicate reminders.
     */
    private function fireDueReminderEvents(): void
    {
        $ss = $this->di['mod_service']('System');
        $beforeDueReminderIntervals = $this->parseInvoiceReminderIntervals($ss->getParamValue('invoice_reminder_before_due_days', ''));
        $afterDueReminderIntervals = $this->parseInvoiceReminderIntervals($ss->getParamValue('invoice_reminder_after_due_days', '5'));

        $beforeDueList = $this->di['db']->getAll("SELECT id, DATEDIFF(due_at, NOW()) as days_left FROM invoice WHERE status = 'unpaid' AND approved = 1 AND due_at > NOW()");
        foreach ($beforeDueList as $params) {
            $params['reminder_intervals'] = $beforeDueReminderIntervals;
            $this->di['events_manager']->fire(['event' => 'onEventBeforeInvoiceIsDue', 'params' => $params]);
        }

        $afterDueList = $this->di['db']->getAll("SELECT id, ABS(DATEDIFF(due_at, NOW())) as days_passed FROM invoice WHERE status = 'unpaid' AND approved = 1 AND ((due_at < NOW()) OR (ABS(DATEDIFF(due_at, NOW())) = 0))");
        foreach ($afterDueList as $params) {
            $params['reminder_intervals'] = $afterDueReminderIntervals;
            $this->di['events_manager']->fire(['event' => 'onEventAfterInvoiceIsDue', 'params' => $params]);
        }
    }

    public function sendInvoiceReminder(\Model_Invoice $invoice): bool
    {
        // do not send accidental reminder for paid invoices
        if ($invoice->status == \Model_Invoice::STATUS_PAID) {
            return true;
        }

        $this->di['events_manager']->fire(['event' => 'onBeforeAdminInvoiceSendReminder', 'params' => ['id' => $invoice->id]]);

        $invoice->reminded_at = date('Y-m-d H:i:s');
        $invoice->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($invoice);

        $this->di['events_manager']->fire(['event' => 'onAfterAdminInvoiceReminderSent', 'params' => ['id' => $invoice->id]]);

        $this->di['logger']->info('Invoice payment reminder sent');

        return true;
    }

    public function counter(): array
    {
        $sql = 'SELECT status, count(id) as counter
                 FROM invoice
                 group by status';
        $rows = $this->di['db']->getAll($sql);
        $data = [];
        foreach ($rows as $row) {
            $data[$row['status']] = $row['counter'];
        }

        return [
            'total' => array_sum($data),
            \Model_Invoice::STATUS_PAID => $data[\Model_Invoice::STATUS_PAID] ?? 0,
            \Model_Invoice::STATUS_UNPAID => $data[\Model_Invoice::STATUS_UNPAID] ?? 0,
            \Model_Invoice::STATUS_REFUNDED => $data[\Model_Invoice::STATUS_REFUNDED] ?? 0,
            \Model_Invoice::STATUS_CANCELED => $data[\Model_Invoice::STATUS_CANCELED] ?? 0,
        ];
    }

    public function isFundsEnabled(): bool
    {
        $systemService = $this->di['mod_service']('system');

        return (bool) $systemService->getParamValue('funds_enabled', true);
    }

    /**
     * ClientFence: the smallest and largest wallet top-up in a currency. The settings are in the
     * default currency (an EGP client was held to EGP 200, about 4 USD); null when not set.
     *
     * @return array{min: ?float, max: ?float}
     */
    public function fundsLimits(?string $currency = null): array
    {
        $systemService = $this->di['mod_service']('system');
        $rate = 1.0;
        if ($currency !== null && $currency !== '') {
            $rate = (float) ($this->di['mod_service']('currency')->getCurrencyRepository()->getRateByCode($currency) ?: 1.0);
        }
        $limit = static fn ($value): ?float => is_numeric($value) && (float) $value > 0 ? round((float) $value * $rate, 2) : null;

        return [
            'min' => $limit($systemService->getParamValue('funds_min_amount', null)),
            'max' => $limit($systemService->getParamValue('funds_max_amount', null)),
        ];
    }

    public function generateFundsInvoice(\Model_Client $client, $amount)
    {
        if (!$client->currency) {
            throw new InformationException('You must have at least one active order before you can add funds so you cannot proceed at the current time!');
        }

        if (!$this->isFundsEnabled()) {
            throw new InformationException('Adding funds to the account balance is currently disabled', null, 980);
        }

        ['min' => $min_amount, 'max' => $max_amount] = $this->fundsLimits((string) $client->currency);

        if ($min_amount && $amount < $min_amount) {
            throw new InformationException('Amount must be at least :min_amount', [':min_amount' => $min_amount], 981);
        }

        if ($max_amount && $amount > $max_amount) {
            throw new InformationException('Amount cannot exceed :max_amount', [':max_amount' => $max_amount], 982);
        }

        $proforma = $this->di['db']->dispense('Invoice');
        $proforma->client_id = $client->id;
        $proforma->status = \Model_Invoice::STATUS_UNPAID;
        $proforma->currency = $client->currency;
        $proforma->approved = $this->_isAutoApproved();
        $proforma->created_at = date('Y-m-d H:i:s');
        $proforma->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($proforma);

        $this->setInvoiceDefaults($proforma);

        $invoiceItemService = $this->di['mod_service']('Invoice', 'InvoiceItem');
        $invoiceItemService->generateForAddFunds($proforma, $amount);

        return $proforma;
    }

    public function processInvoice(array $data): array
    {
        $allowSubscribe = $data['allow_subscription'] ?? true;
        $subscribe = false;

        $invoice = $this->di['db']->findOne('Invoice', 'hash = ?', [$data['hash']]);
        if (!$invoice instanceof \Model_Invoice) {
            throw new InformationException('Invoice not found', null, 812);
        }

        $this->checkInvoiceAuth($invoice, InvoiceOperation::PAYMENT);

        $gtw = $this->di['db']->load('PayGateway', $data['gateway_id']);
        if (!$gtw instanceof \Model_PayGateway) {
            throw new InformationException('Payment method not found', null, 813);
        }

        if (!$gtw->enabled) {
            throw new \ClientFence\Exception('Payment method not enabled', null, 814);
        }

        $subscribeService = $this->di['mod_service']('Invoice', 'Subscription');
        $payGatewayService = $this->di['mod_service']('Invoice', 'PayGateway');
        if ($subscribeService->isSubscribable($invoice->id) && $payGatewayService->canPerformRecurrentPayment($gtw) && $allowSubscribe) {
            $subscribe = true;
        }

        if (!$subscribe && !$payGatewayService->canPerformSinglePayment($gtw)) {
            throw new \ClientFence\Exception('One-time payments are not enabled for the selected payment gateway', null, 815);
        }

        $adapter = $payGatewayService->getPaymentAdapter($gtw, $invoice, $data);
        if (method_exists($adapter, 'setDi')) {
            $adapter->setDi($this->di);
        }

        if (method_exists($adapter, 'setLog')) {
            $adapter->setLog($this->di['logger']);
        }

        $pgc = $adapter->getConfig();

        // @since v2.9.15
        if (method_exists($adapter, 'getHtml')) {
            $html = $adapter->getHtml($this->di['api_system'], (int) $invoice->id, $subscribe);

            return [
                'iframe' => isset($pgc['can_load_in_iframe']) && (bool) $pgc['can_load_in_iframe'],
                'type' => 'html',
                'service_url' => '',
                'subscription' => $subscribe,
                'result' => $html,
            ];
        }

        $i = clone $invoice;
        $mpi = $this->getPaymentInvoice($i, $subscribe);
        $r = ($subscribe) ? $adapter->recurrentPayment($mpi) : $adapter->singlePayment($mpi);
        $this->di['logger']->info('Went to pay for invoice #%s via %s', $invoice->id, $gtw->gateway);

        // @bug https://github.com/boxbilling/boxbilling/issues/108
        if ($adapter->getType() != 'html') {
            $r = (array) $r;
        }

        return [
            'type' => $adapter->getType(),
            'service_url' => $adapter->getServiceURL(),
            'subscription' => $subscribe,
            'result' => $r,
        ];
    }

    public function generatePDF($hash, $identity, ?string $locale = null): Response
    {
        $invoiceModel = $this->di['db']->findOne('Invoice', 'hash = :hash', [':hash' => $hash]);

        if (!$invoiceModel instanceof \Model_Invoice) {
            throw new InformationException('Invoice not found');
        }

        $this->checkInvoiceAuth($invoiceModel, InvoiceOperation::READ);

        $invoice = $this->toApiArray($invoiceModel, false, $identity);
        // ClientFence: a staff session's language can differ from the invoice recipient's.
        $locale ??= $identity instanceof \Model_Admin ? (($invoice['client']['lang'] ?? '') ?: \Locale::getDefault()) : \Locale::getDefault();
        $content = $this->renderInvoicePdfContent($invoiceModel, $invoice, $locale);

        return $this->createPdfResponse($content, $invoice['serie_nr']);
    }

    /**
     * Build the PDF invoice as an email attachment, provided the admin has opted into it via
     * the "Attach PDF invoice to invoice emails" setting. Returns null if the setting is off
     * or the PDF could not be generated, so callers can skip attaching without failing the send.
     *
     * @return array{content: string, name: string, mime: string}|null
     */
    public function getInvoicePdfAttachment(\Model_Invoice $invoiceModel): ?array
    {
        $systemService = $this->di['mod_service']('system');
        if (!$systemService->getParamValue('invoice_email_attach_pdf')) {
            return null;
        }

        try {
            $invoice = $this->toApiArray($invoiceModel, false);
            $content = $this->renderInvoicePdfContent($invoiceModel, $invoice);

            return [
                'content' => $content,
                'name' => $this->sanitizePdfFileName((string) $invoice['serie_nr']) . '.pdf',
                'mime' => 'application/pdf',
            ];
        } catch (\Exception $e) {
            $this->di['logger']->setChannel('email')->error('Failed to generate PDF invoice attachment: ' . $e->getMessage());

            return null;
        }
    }

    protected function renderInvoicePdfContent(\Model_Invoice $invoiceModel, array $invoice, ?string $locale = null): string
    {
        $systemService = $this->di['mod_service']('system');
        $locale ??= ($invoice['client']['lang'] ?? '') ?: \Locale::getDefault();
        $c = $systemService->getCompany();

        if (isset($invoiceModel->currency)) {
            $currencyCode = $invoiceModel->currency;
        } else {
            $client = $this->di['db']->getExistingModelById('Client', $invoiceModel->client_id, 'Client not found');
            $currencyCode = $client->currency;
        }

        $sellerLines = 0;
        $buyerLines = 0;
        $logoSource = '';

        if (!empty($c['logo_url'])) {
            [$logoSource] = $this->getPdfLogoSource($c['logo_url']);
        }

        $vars = [
            'currency_code' => $currencyCode,
            'logo_source' => $logoSource,
            'seller' => $this->getSellerData($invoice, $sellerLines),
            'seller_lines' => $sellerLines,
            'footer' => $this->getFooterInfo($c),
            'buyer' => $this->getBuyerData($invoice, $buyerLines),
            'buyer_lines' => $buyerLines,
            'invoice' => $invoice,
            'locale' => $locale,
        ];

        $template = $this->getPdfTemplate();
        if ($template !== 'custom-invoice.twig') {
            return InvoicePdf::render($this->di, $vars, $locale);
        }

        // ClientFence: custom templates retain Dompdf so existing layouts keep working.
        $previousPdfLocale = \Locale::getDefault();
        (new \ClientFence\Translate())->setLocale($locale)->setup();
        try {
            $vars['css'] = $this->getPdfCss();
            $pdf = $this->createPdfGenerator();
            $pdf->setPaper($systemService->getParamValue('invoice_document_format', 'Letter'), 'portrait');
            $pdf->setBasePath(Path::join(__DIR__, 'templates', 'pdf'));
            $options = $pdf->getOptions();
            $options->set('isRemoteEnabled', false);

            $twigFactory = $this->di['twig_factory'];
            $twig = $twigFactory->createBaseEnvironment();
            $loader = new FilesystemLoader(Path::join(__DIR__, 'templates', 'pdf'));
            $twig->setLoader($loader);
            $html = $twig->render($template, $vars);

            $pdf->setOptions($options);
            $pdf->loadHtml($html);
            $pdf->render();

            return $pdf->output();
        } finally {
            (new \ClientFence\Translate())->setLocale($previousPdfLocale ?: 'en_US')->setup();
        }
    }

    public function addNote(\Model_Invoice $model, $note): bool
    {
        $n = $model->notes;
        $model->notes = $n . date('Y-m-d H:i:s') . ': ' . $note . '       ' . PHP_EOL;
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);

        return true;
    }

    /**
     * Return list of unpaid invoices which can be covered from client balance.
     * Deposit invoices are excluded as they cannot be covered from client balance.
     *
     * @return array
     */
    /**
     * ClientFence core patch: getTotalWithTax() of invoice `m` in SQL (taxed subtotal and rate as
     * DECIMAL, so ROUND takes half-cents up as PHP's round() does; on doubles it took some down).
     * findAllUnpaid() keeps only invoices the client's whole credit covers, with two cents of slack
     * (the payment re-checks): one credit row larger than one item let every unpaid
     * invoice of anyone who ever added funds through, and the cron tried to pay each on every run.
     * The summed credit is the only credit check: a per-row check skipped credit split over several rows.
     */
    private const TOTAL_WITH_TAX_SQL = '(SELECT COALESCE(SUM(t.price * t.quantity), 0)
            + IF(CAST(m.taxrate AS DECIMAL(20, 10)) > 0, ROUND(CAST(COALESCE(SUM(CASE WHEN t.taxed <> 0 THEN t.price * t.quantity ELSE 0 END), 0) AS DECIMAL(20, 2)) * CAST(m.taxrate AS DECIMAL(20, 10)) / 100, 2) + CAST(m.tax_adjust AS DECIMAL(20, 2)), 0)
            FROM invoice_item t WHERE t.invoice_id = m.id)';

    public function findAllUnpaid(?array $filter = null)
    {
        $sql = 'SELECT m.*
                FROM invoice as m
                    LEFT JOIN client as cl on m.client_id = cl.id
                    LEFT JOIN invoice_item as pi on pi.invoice_id = m.id
                WHERE m.status = :status
                    AND m.approved = 1
                    AND pi.type != :type
                    AND (SELECT COALESCE(SUM(b.amount), 0) FROM client_balance b WHERE b.client_id = m.client_id) >= ' . self::TOTAL_WITH_TAX_SQL . ' - 0.02';
        $params = ['status' => \Model_Invoice::STATUS_UNPAID, 'type' => \Model_InvoiceItem::TYPE_DEPOSIT];

        $client_id = isset($filter['client_id']) ? (int) $filter['client_id'] : null;

        if ($client_id) {
            $sql .= ' AND m.client_id = :client_id ';
            $params['client_id'] = $client_id;
        }

        $sql .= ' GROUP BY m.id, cl.id
                 ORDER BY m.id DESC';

        return $this->di['db']->getAll($sql, $params);
    }

    public function findAllPaid()
    {
        return $this->di['db']->find('Invoice', 'status = ? order by id desc', [\Model_Invoice::STATUS_PAID]);
    }

    /**
     * Unpaid invoices whose due date is more than the given number of days
     * in the past. Used by the cron cleanup that expires stale unpaid
     * invoices.
     *
     * @return \Model_Invoice[]
     */
    public function findUnpaidOlderThan(int $days): array
    {
        // ClientFence core patch: live renewal invoices must survive overdue cleanup to avoid repeated billing emails.
        $conditions = 'status = ? and due_at is not null and DATEDIFF(NOW(), due_at) > ?
            AND NOT EXISTS (SELECT 1 FROM client_order co WHERE co.unpaid_invoice_id = invoice.id AND co.status IN (?, ?))';

        return $this->di['db']->find('Invoice', $conditions, [\Model_Invoice::STATUS_UNPAID, $days, \Model_ClientOrder::STATUS_ACTIVE, \Model_ClientOrder::STATUS_SUSPENDED]);
    }

    public function getUnpaidInvoicesLateFor($days_after_issue = 2)
    {
        $conditions = 'status = ? and approved = 1 and reminded_at is null and DATEDIFF(NOW(), created_at) > ?';

        return $this->di['db']->find('Invoice', $conditions, [\Model_Invoice::STATUS_UNPAID, $days_after_issue]);
    }

    public function isInvoiceReminderIntervalEnabled(string $param, int $days, string $default = '', mixed $intervals = null): bool
    {
        if ($days < 1) {
            return false;
        }

        if ($intervals === null) {
            $systemService = $this->di['mod_service']('system');
            $intervals = $systemService->getParamValue($param, $default);
        }

        return in_array($days, $this->parseInvoiceReminderIntervals($intervals), true);
    }

    public function parseInvoiceReminderIntervals(mixed $value): array
    {
        if (is_array($value)) {
            $parts = $value;
        } else {
            $parts = preg_split('/[,\s]+/', (string) $value) ?: [];
        }

        $days = [];
        foreach ($parts as $part) {
            if ($part === '' || !is_numeric($part)) {
                continue;
            }

            $day = (int) $part;
            if ($day > 0) {
                $days[] = $day;
            }
        }

        $days = array_values(array_unique($days));
        sort($days);

        return $days;
    }

    private function _isAutoApproved(): bool
    {
        /**
         * @var \ClientFence\Mod\System\Service $systemService
         */
        $systemService = $this->di['mod_service']('system');

        return (bool) $systemService->getParamValue('invoice_auto_approval', true);
    }

    /**
     * @param bool $subscribe
     */
    public function getPaymentInvoice(\Model_Invoice $invoice, $subscribe = false): \Payment_Invoice
    {
        $proforma = $this->toApiArray($invoice);
        $client = $this->getBuyer($invoice);

        $buyer = new \Payment_Invoice_Buyer();
        $buyer
            ->setEmail($client['email'])
            ->setFirstName($client['first_name'])
            ->setLastName($client['last_name'])
            ->setCompany($client['company'])
            ->setAddress($client['address'])
            ->setCity($client['city'])
            ->setState($client['state'])
            ->setZip($client['zip'])
            ->setPhone($client['phone'])
            ->setPhoneCountryCode($client['phone_cc'])
            ->setCountry($client['country']);

        $first_title = null;
        $items = [];
        foreach ($proforma['lines'] as $item) {
            $pi = new \Payment_Invoice_Item();
            $pi
                ->setId($item['id'])
                ->setTitle($item['title'])
                ->setDescription($item['title'])
                ->setPrice($item['price'])
                ->setTax($item['tax'])
                ->setQuantity($item['quantity']);
            $items[] = $pi;
            if (is_null($first_title) && Tools::safeCount($proforma['lines']) == 1) {
                $first_title = $item['title'];
            }
        }

        $invoice_number_padding = $this->di['mod_service']('system')->getParamValue('invoice_number_padding');
        $invoice_number_padding = $invoice_number_padding !== null && $invoice_number_padding !== '' ? $invoice_number_padding : 5;

        $params = [
            ':id' => sprintf('%0' . $invoice_number_padding . 's', $proforma['nr']),
            ':serie' => $proforma['serie'],
            ':title' => $first_title,
        ];
        if ($first_title) {
            $title = __trans('Payment for invoice :serie:id [:title]', $params);
        } else {
            $title = __trans('Payment for invoice :serie:id', $params);
        }

        $mpi = new \Payment_Invoice();
        $mpi->setId($invoice->id);
        $mpi->setNumber($proforma['nr']);
        $mpi->setBuyer($buyer);
        $mpi->setCurrency($proforma['currency']);
        $mpi->setTitle($title);
        $mpi->setItems($items);

        $subscribeService = $this->di['mod_service']('Invoice', 'Subscription');
        // can subscribe only if proforma has one item with defined period
        if ($subscribe && $subscribeService->isSubscribable($invoice->id)) {
            $subitem = $invoice->InvoiceItem->getFirst();
            $period = $this->di['period']($subitem->period);

            $bs = new \Payment_Invoice_Subscription();
            $bs->setId($proforma['id']);
            $bs->setAmount($mpi->getTotalWithTax());
            $bs->setCycle($period->getQty());
            $bs->setUnit($period->getUnit());

            $mpi->setSubscription($bs);
            $mpi->setTitle('Subscription for ' . $subitem->title);
        }

        return $mpi;
    }

    public function getBuyer(\Model_Invoice $invoice): array
    {
        return [
            'first_name' => $invoice->buyer_first_name,
            'last_name' => $invoice->buyer_last_name,
            'company' => $invoice->buyer_company,
            'address' => $invoice->buyer_address,
            'city' => $invoice->buyer_city,
            'state' => $invoice->buyer_state,
            'country' => $invoice->buyer_country,
            'phone' => $invoice->buyer_phone,
            'phone_cc' => '',
            'email' => $invoice->buyer_email,
            'zip' => $invoice->buyer_zip,
        ];
    }

    public function rmByClient(\Model_Client $client): void
    {
        $invoices = $this->di['db']->find('Invoice', 'client_id = ?', [$client->id]);
        foreach ($invoices as $invoice) {
            if ($invoice instanceof \Model_Invoice) {
                $this->rmInvoice($invoice);
            }
        }
        // ClientFence core patch: the client's subscriptions go too (one left behind broke the Subscriptions list).
        // They are cancelled at the gateway first, so it stops charging; a refusal is logged with the gateway id.
        $subscriptionService = $this->di['mod_service']('Invoice', 'Subscription');
        foreach ($this->di['db']->find('Subscription', 'client_id = ?', [$client->id]) as $subscription) {
            if ($subscription->status !== 'canceled') {
                try {
                    $subscriptionService->cancel($subscription);
                } catch (\Exception $e) {
                    $this->di['logger']->warning(sprintf('Client #%s removed: cancel subscription %s (%s) at the gateway by hand. %s', $client->id, $subscription->sid, $subscription->gateway_id, $e->getMessage()));
                }
            }
            $subscriptionService->delete($subscription);
        }
    }

    public function isInvoiceTypeDeposit(\Model_Invoice $invoice): bool
    {
        $invoiceItems = $this->di['db']->find('InvoiceItem', 'invoice_id = ?', [$invoice->id]);

        foreach ($invoiceItems as $item) {
            if ($item->type == \Model_InvoiceItem::TYPE_DEPOSIT) {
                return true;
            }
        }

        return false;
    }

    public function exportCSV(array $headers): Response
    {
        if ($headers) {
            $headers = array_values(array_intersect(self::EXPORTABLE_COLUMNS, $headers));
        }

        if (!$headers) {
            $headers = self::DEFAULT_EXPORT_COLUMNS;
        }

        return $this->di['csv_response_factory']->create('invoice', 'invoices.csv', $headers);
    }

    public function checkInvoiceAuth(\Model_Invoice $invoice, InvoiceOperation $operation = InvoiceOperation::READ): void
    {
        // ClientFence core patch: guest invoice routes must respect the signed-in staff member's invoice access.
        if (Environment::isCLI() || ($this->di['auth']->isAdminLoggedIn() && $this->di['mod_service']('staff')->hasPermission($this->di['loggedin_admin'], 'invoice', 'view'))) {
            return;
        }

        $invoiceClientId = $invoice->client_id;
        $systemService = $this->di['mod_service']('system');
        $hash_access = $systemService->getParamValue('invoice_accessible_from_hash', '0');
        $hashAccessAllowed = $hash_access === '1' && in_array($operation, [InvoiceOperation::READ, InvoiceOperation::PAYMENT], true);

        $client = null;
        if ($this->di['auth']->isClientLoggedIn()) {
            $client = $this->di['loggedin_client'];
        }
        $isOwner = $client !== null && (int) $invoiceClientId === (int) $client->id;
        // ClientFence core patch (QA r8 A-01): authorized users need invoice permission to use the account's owner access.
        $context = $isOwner ? Users::context($this->di) : null;
        $isOwner = $isOwner && ($context === null || Users::allows($context, 'invoices'));

        if (!$isOwner && $this->isHashExpired($invoice)) {
            throw new InformationException('This invoice link has expired', [], 403);
        }

        if (!$hashAccessAllowed && !$isOwner) {
            throw new InformationException('You do not have permission to perform this action', [], 403);
        }
    }

    /**
     * Computes the hash_expires_at timestamp. Returns null when the admin
     * has disabled hash expiration (invoice_hash_lifetime_days = 0).
     */
    private function computeHashExpiration(): ?string
    {
        $days = (int) $this->di['mod_service']('system')->getParamValue('invoice_hash_lifetime_days', '90');
        if ($days <= 0) {
            return null;
        }

        return date('Y-m-d H:i:s', strtotime("+{$days} days"));
    }

    /**
     * Re-stamps hash_expires_at on an existing invoice using the current
     * invoice_hash_lifetime_days setting. Also self-heals invoices whose
     * hash is empty or in a legacy format by generating a fresh modern
     * hash. Called when an admin re-sends an invoice or payment reminder.
     */
    public function extendInvoiceHashLifetime(\Model_Invoice $invoice): void
    {
        $hash = $invoice->hash ?? null;
        $isModern = is_string($hash) && preg_match('/^[a-f0-9]{30,60}$/', $hash) === 1;
        if (!$isModern) {
            $invoice->hash = bin2hex(random_bytes(random_int(15, 30)));
        }
        $invoice->hash_expires_at = $this->computeHashExpiration();
        $this->di['db']->store($invoice);
    }

    /**
     * Regenerates the hash if it is missing or in a legacy format. No-op
     * for valid hashes, making it safe to call from read paths.
     */
    public function ensureValidHash(\Model_Invoice $invoice): void
    {
        $hash = $invoice->hash ?? null;
        $isModern = is_string($hash) && preg_match('/^[a-f0-9]{30,60}$/', $hash) === 1;
        if ($isModern) {
            return;
        }

        $invoice->hash = bin2hex(random_bytes(random_int(15, 30)));
        $invoice->hash_expires_at = $this->computeHashExpiration();
        $this->di['db']->store($invoice);
    }

    private function isHashExpired(\Model_Invoice $invoice): bool
    {
        $expires = $invoice->hash_expires_at ?? null;
        if (empty($expires)) {
            return false;
        }

        return strtotime((string) $expires) < time();
    }

    // Start of PDF related functions
    protected function createPdfGenerator(): Dompdf
    {
        $fontCachePath = Path::join(PATH_CACHE, 'dompdf');
        $this->filesystem->mkdir($fontCachePath);

        $options = new Options();
        $options->setFontDir($fontCachePath);
        $options->setFontCache($fontCachePath);
        $options->setChroot(PATH_ROOT);

        return new Dompdf($options);
    }

    protected function createPdfResponse(string $content, string $fileName): Response
    {
        $response = (new ResponseFactory())->html($content);
        $safeFileName = str_replace(['/', '\\', '%'], '-', trim($fileName));
        if ($safeFileName === '') {
            $safeFileName = 'invoice';
        }

        $fallbackFileName = $this->sanitizePdfFileName($fileName);

        $disposition = $response->headers->makeDisposition(
            HeaderUtils::DISPOSITION_INLINE,
            $safeFileName . '.pdf',
            $fallbackFileName . '.pdf'
        );

        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    /**
     * Reduce a file name to a plain ASCII form safe for both HTTP fallback
     * Content-Disposition names and MIME attachment part headers.
     */
    private function sanitizePdfFileName(string $fileName): string
    {
        $fallbackFileName = preg_replace('/[^A-Za-z0-9._-]/', '-', trim($fileName));
        $fallbackFileName = trim((string) $fallbackFileName, '.-');

        return $fallbackFileName !== '' ? $fallbackFileName : 'invoice';
    }

    protected function getPdfCss(): string
    {
        $basePath = Path::join(__DIR__, 'templates', 'pdf');
        $customCssPath = Path::join($basePath, 'custom-invoice.css');
        $defaultCssPath = Path::join($basePath, 'default-invoice.css');

        if ($this->filesystem->exists($customCssPath)) {
            $CSS = $this->filesystem->readFile($customCssPath);
        } else {
            $CSS = $this->filesystem->readFile($defaultCssPath);
        }

        if (empty($CSS)) {
            $CSS = $this->filesystem->readFile($defaultCssPath);
        }

        return $CSS;
    }

    protected function getPdfTemplate(): string
    {
        if ($this->filesystem->exists(Path::join(__DIR__, 'templates', 'pdf', 'custom-invoice.twig'))) {
            return 'custom-invoice.twig';
        }

        return 'default-invoice.twig';
    }

    protected function getPdfLogoSource(string $originalUrl): array
    {
        $source = parse_url($originalUrl, PHP_URL_PATH);
        $remote = false;
        if (!is_string($source) || $source === '') {
            return [$this->fetchPdfLogo($originalUrl), false];
        }

        // prevent openbasedir error from preventing pdf creation when debug mode is enabled
        if (@!$this->filesystem->exists($source)) {
            $source = Path::join($this->di['request']->server->get('DOCUMENT_ROOT', ''), $source);
            if (!$this->filesystem->exists($source)) {
                // Assume the URL points to an image not hosted on this server
                $source = $originalUrl;
                $remote = true;
            }
        }

        if (!$remote) {
            $canonicalPath = Path::canonicalize($source);
            $canonicalRoot = Path::canonicalize(PATH_ROOT);
            if (!Path::isBasePath($canonicalRoot, $canonicalPath)) {
                $source = $originalUrl;
                $remote = true;
            } elseif ($canonicalPath !== $source) {
                $source = $canonicalPath;
            }
        }

        if ($remote) {
            return [$this->fetchPdfLogo($originalUrl), false];
        }

        if (str_ends_with($source, '.svg')) {
            $source = 'data:image/svg+xml;base64,' . base64_encode($this->filesystem->readFile($source));
            $remote = false;
        }

        return [$source, $remote];
    }

    // ClientFence core patch (0.8.9 port): logo requests use a bounded client that rejects private networks.
    private function fetchPdfLogo(string $url): string
    {
        if (!in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return '';
        }

        try {
            $client = new \Symfony\Component\HttpClient\NoPrivateNetworkHttpClient($this->di['http_client']);
            $bytes = $client->request('GET', $url, [
                'max_redirects' => 3,
                'timeout' => 5,
                'max_duration' => 10,
                'on_progress' => static function (int $downloaded): void {
                    if ($downloaded > 2 * 1024 * 1024) {
                        throw new \Symfony\Component\HttpClient\Exception\TransportException('Invoice logo is too large');
                    }
                },
            ])->getContent();
        } catch (\Symfony\Contracts\HttpClient\Exception\ExceptionInterface) {
            return '';
        }
        // Raster images only: a remote SVG could load further resources.
        $image = @getimagesizefromstring($bytes);

        return $image === false ? '' : 'data:' . $image['mime'] . ';base64,' . base64_encode($bytes);
    }

    private function getSellerData(array $invoice, int &$lines): array
    {
        $sourceData = [
            'Name' => $invoice['seller']['company'] ?? null,
            'Address 1' => $invoice['seller']['address_1'] ?? null,
            'Address 2' => $invoice['seller']['address_2'] ?? null,
            'Address 3' => $invoice['seller']['address_3'] ?? null,
            'Phone' => $invoice['seller']['phone'] ?? null,
            'Email' => $invoice['seller']['email'] ?? null,
            'VAT Number' => $invoice['seller']['company_vat'] ?? null,
        ];

        foreach ($sourceData as $label => $data) {
            if ($data === null || empty(trim((string) $data))) {
                unset($sourceData[$label]);
            } else {
                ++$lines;
            }
        }

        return $sourceData;
    }

    private function getBuyerData(array $invoice, int &$lines): array
    {
        $sourceData = [
            'Company' => $invoice['buyer']['company'] ?? null,
            'Name' => trim(($invoice['buyer']['first_name'] ?? '') . ' ' . ($invoice['buyer']['last_name'] ?? '')),
            'Address' => $invoice['buyer']['address'] ?? null,
            'City' => $invoice['buyer']['city'] ?? null,
            'State' => $invoice['buyer']['state'] ?? null,
            'Zip' => $invoice['buyer']['zip'] ?? null,
            'Country' => $invoice['buyer']['country'] ?? null,
            'Phone' => $invoice['buyer']['phone'] ?? null,
            'VAT Number' => $invoice['buyer']['company_vat'] ?? null,
        ];

        foreach ($sourceData as $label => $data) {
            if ($data === null || empty(trim((string) $data))) {
                unset($sourceData[$label]);
            } else {
                ++$lines;
            }
        }

        return $sourceData;
    }

    private function getFooterInfo(array $company): array
    {
        // Keep all keys defined so PDF templates rendered with strict_variables don't fail on missing optional company details.
        return [
            'company_name' => $company['name'] ?? null,
            'bank_name' => $company['bank_name'] ?? null,
            'account_number' => $company['account_number'] ?? null,
            'bic' => $company['bic'] ?? null,
            'display_bank_info' => $company['display_bank_info'] ?? null,
            'company_vat' => $company['vat_number'] ?? null,
            'company_number' => $company['number'] ?? null,
            'www' => $company['www'] ?? null,
            'email' => $company['email'] ?? null,
            'phone' => $company['tel'] ?? null,
            'signature' => $company['signature'] ?? null,
            'address_1' => $company['address_1'] ?? null,
            'address_2' => $company['address_2'] ?? null,
            'address_3' => $company['address_3'] ?? null,
        ];
    }

    /**
     * Get the order ID from an invoice's items.
     * Returns the first order ID found in the invoice items.
     *
     * @param int $invoiceId The invoice ID to search
     *
     * @return int|null The order ID or null if not found
     */
    public function getOrderIdFromInvoice(int $invoiceId): ?int
    {
        $item = $this->di['db']->findOne(
            'InvoiceItem',
            'invoice_id = :invoice_id AND type = :type',
            ['invoice_id' => $invoiceId, 'type' => \Model_InvoiceItem::TYPE_ORDER]
        );

        if ($item instanceof \Model_InvoiceItem) {
            return (int) $item->rel_id;
        }

        return null;
    }

    /**
     * Generate a renewal invoice for a subscription payment that arrived without an invoice.
     * This handles the case where PayPal/Stripe sends a subscription payment before
     * the cron job generates the renewal invoice.
     *
     * @param string $subscriptionSid The subscription ID from the payment gateway
     * @param int    $clientId        The client ID
     *
     * @return \Model_Invoice|null The generated invoice or null if unable to generate
     */
    public function generateRenewalInvoiceForSubscriptionPayment(string $subscriptionSid, int $clientId): ?\Model_Invoice
    {
        $subscriptionService = $this->di['mod_service']('Invoice', 'Subscription');
        $orderService = $this->di['mod_service']('Order');

        try {
            $subscription = $this->di['db']->findOne('Subscription', 'sid = :sid', ['sid' => $subscriptionSid]);
            if (!$subscription instanceof \Model_Subscription) {
                return null;
            }

            if ($subscription->rel_type !== 'invoice') {
                return null;
            }

            $originalOrderId = $this->getOrderIdFromInvoice((int) $subscription->rel_id);
            if ($originalOrderId === null) {
                return null;
            }

            $originalOrder = $this->di['db']->load('ClientOrder', $originalOrderId);
            if (!$originalOrder instanceof \Model_ClientOrder) {
                return null;
            }

            // Use the original order directly. A previous approach searched for
            // any active order with the same product_id, but that is broken for
            // products like domain registrations where multiple orders share
            // the same product — it would find an unrelated order and generate
            // a renewal invoice for the wrong service.
            //
            // Accept the same "still renewable" statuses generateForOrder() itself
            // recognizes below, not just active: the batch-suspend cron can suspend
            // an order (on expiry) before a delayed gateway subscription-payment IPN
            // for that same renewal arrives. generateForOrder() already reuses any
            // unpaid invoice the cron generated ahead of time, so this lets that
            // invoice be paid and the order un-suspended/renewed as normal.
            if (!in_array($originalOrder->status, [
                \Model_ClientOrder::STATUS_ACTIVE,
                \Model_ClientOrder::STATUS_SUSPENDED,
                \Model_ClientOrder::STATUS_FAILED_RENEW,
            ], true)) {
                return null;
            }
            // ClientFence core patch: a service set to end at its period end is not renewed by a payment the gateway still
            // takes (PayPal Email cannot be stopped by API): the payment goes to the client's credit.
            if ($this->di['dbal']->fetchOne('SELECT 1 FROM client_order_meta WHERE client_order_id = ? AND name = ? AND value = ?', [(int) $originalOrder->id, \ClientFence\Mod\Order\Service::META_CANCEL_AT_PERIOD_END, '1'])) {
                return null;
            }

            $invoice = $this->generateForOrder($originalOrder);
            // ClientFence core patch: the cron's renewal invoice is approved and emailed already.
            if (!$invoice->approved) {
                $this->approveInvoice($invoice, ['use_credits' => false]);
            }

            $this->di['logger']->info("Generated renewal invoice #{$invoice->id} for subscription payment (SID: {$subscriptionSid}).");

            return $invoice;
        } catch (\Exception $e) {
            $this->di['logger']->warning('Failed to generate renewal invoice for subscription payment: ' . $e->getMessage());

            return null;
        }
    }

    // End of PDF related functions
}
