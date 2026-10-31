<?php

declare(strict_types=1);

namespace ClientFence\Mod\Invoice;

use ClientFence\InformationException;
use Doctrine\DBAL\Connection;

/**
 * ClientFence: quotes, as WHMCS's. Staff write a quote for a client or for a prospect (name and
 * email, no account yet), send it by email with its PDF (QuotePdf) and a link, and the client
 * accepts it in the client area or by the link. Accepting makes the client account of a prospect
 * and an unpaid invoice with the quote's lines, which is emailed at once.
 *
 * Tables invoice_quote and invoice_quote_item (Clientfence/schema.json). Stages as WHMCS's: draft,
 * delivered (sent), on hold, accepted, lost, dead; "accepted" is set only by accept(). Quantities
 * are whole numbers (invoice lines have no fractions); a line's discount is a percentage of its
 * price, as in WHMCS. Tax follows the invoice rules: the client's tax rule (for a prospect, the rule
 * of the prospect's country), per unit on the taxed lines.
 */
final class Quotes
{
    public const STAGES = ['draft', 'delivered', 'on_hold', 'accepted', 'lost', 'dead'];

    /** Stages from which a quote can still be accepted. */
    public const OPEN_STAGES = ['draft', 'delivered', 'on_hold'];

    public const MAX_ITEMS = 100;

    public const TEXT_MAX = 20000;

    /** @return array<string, string> stage => title */
    public static function stages(): array
    {
        return [
            'draft' => __trans('Draft'),
            'delivered' => __trans('Delivered'),
            'on_hold' => __trans('On Hold'),
            'accepted' => __trans('Accepted'),
            'lost' => __trans('Lost'),
            'dead' => __trans('Dead'),
        ];
    }

    /**
     * Creates (no id) or updates a quote from the staff form. An accepted quote keeps everything but
     * its admin notes. Returns the quote id.
     */
    public static function save(\Pimple\Container $di, array $data, ?int $adminId = null): int
    {
        $db = self::db($di);
        $id = (int) ($data['id'] ?? 0);
        $now = date('Y-m-d H:i:s');
        $current = null;
        if ($id > 0) {
            $current = $db->fetchAssociative('SELECT * FROM invoice_quote WHERE id = ?', [$id]);
            if ($current === false) {
                throw new InformationException('Quote not found', [], 404);
            }
            if ($current['stage'] === 'accepted') {
                $db->update('invoice_quote', ['admin_notes' => self::longText($data['admin_notes'] ?? '', 'admin notes'), 'updated_at' => $now], ['id' => $id]);

                return $id;
            }
        }

        $row = self::recipient($di, $data);
        $subject = trim((string) ($data['subject'] ?? ''));
        if ($subject === '') {
            throw new InformationException('Enter the subject of the quote.');
        }
        if (mb_strlen($subject) > 255) {
            throw new InformationException('The subject can be at most :max characters.', [':max' => 255]);
        }
        $stage = (string) ($data['stage'] ?? ($current['stage'] ?? 'draft'));
        if (!in_array($stage, self::OPEN_STAGES, true) && !in_array($stage, ['lost', 'dead'], true)) {
            throw new InformationException('Choose a stage: Draft, Delivered, On Hold, Lost or Dead. A quote becomes Accepted when it is accepted.');
        }
        $validUntil = trim((string) ($data['valid_until'] ?? ''));
        if ($validUntil !== '' && !self::isDate($validUntil)) {
            throw new InformationException('Enter the dates as year-month-day.');
        }
        $items = self::items($data['items'] ?? []);
        [$taxname, $taxrate] = self::taxRule($di, $row['client_id'], $row['country']);

        $row += [
            'subject' => $subject,
            'stage' => $stage,
            'valid_until' => $validUntil === '' ? null : $validUntil,
            'proposal' => self::longText($data['proposal'] ?? '', 'proposal'),
            'customer_notes' => self::longText($data['customer_notes'] ?? '', 'notes'),
            'admin_notes' => self::longText($data['admin_notes'] ?? '', 'admin notes'),
            'taxname' => $taxname,
            'taxrate' => $taxrate,
            'updated_at' => $now,
        ];

        $db->beginTransaction();
        try {
            if ($current === null) {
                $db->insert('invoice_quote', $row + [
                    'hash' => bin2hex(random_bytes(20)),
                    'created_by' => $adminId,
                    'created_at' => $now,
                ]);
                $id = (int) $db->lastInsertId();
            } else {
                // ClientFence core patch: the stage is read again under the row lock acceptance takes. An acceptance
                // that landed after the check above was reopened (lines rewritten, accepted again: a second
                // invoice); now the save keeps only its admin notes, as for any accepted quote.
                $lock = $db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform ? ' FOR UPDATE' : '';
                if ($db->fetchOne('SELECT stage FROM invoice_quote WHERE id = ?' . $lock, [$id]) === 'accepted') {
                    $db->update('invoice_quote', ['admin_notes' => $row['admin_notes'], 'updated_at' => $now], ['id' => $id]);
                    $db->commit();

                    return $id;
                }
                // ClientFence core patch: a new recipient gets a new link; the old one would let the first
                // recipient see and accept the quote (or confirm a prospect account with the new email).
                if ((int) $current['client_id'] !== (int) $row['client_id'] || strtolower((string) $current['email']) !== $row['email']) {
                    $row['hash'] = bin2hex(random_bytes(20));
                }
                $db->update('invoice_quote', $row, ['id' => $id]);
                $db->delete('invoice_quote_item', ['quote_id' => $id]);
            }
            foreach ($items as $position => $item) {
                $db->insert('invoice_quote_item', $item + ['quote_id' => $id, 'position' => $position]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();

            throw $e;
        }

        return $id;
    }

    /**
     * A quote with its lines, totals and recipient (the client's current details, or the prospect's).
     *
     * @return array<string, mixed>
     */
    public static function get(\Pimple\Container $di, int $id): array
    {
        $row = self::db($di)->fetchAssociative('SELECT * FROM invoice_quote WHERE id = ?', [$id]);
        if ($row === false) {
            throw new InformationException('Quote not found', [], 404);
        }

        return self::toArray($di, $row);
    }

    /** The quote of a link, or null (the hash is 40 hexadecimal characters). */
    public static function findByHash(\Pimple\Container $di, string $hash): ?array
    {
        if (preg_match('/^[a-f0-9]{40}$/', $hash) !== 1) {
            return null;
        }
        $row = self::db($di)->fetchAssociative('SELECT * FROM invoice_quote WHERE hash = ?', [$hash]);

        return $row === false ? null : self::toArray($di, $row);
    }

    /**
     * Quotes for the staff list, newest first: `stage`, `client_id` and `search` (number, subject,
     * name, company or email) filter it.
     *
     * @return array{list: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public static function getList(\Pimple\Container $di, array $filters, int $page = 1, int $perPage = 30): array
    {
        $db = self::db($di);
        $where = ['1 = 1'];
        $params = [];
        $stage = (string) ($filters['stage'] ?? '');
        if (in_array($stage, self::STAGES, true)) {
            $where[] = 'q.stage = ?';
            $params[] = $stage;
        }
        if ((int) ($filters['client_id'] ?? 0) > 0) {
            $where[] = 'q.client_id = ?';
            $params[] = (int) $filters['client_id'];
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $platform = $db->getDatabasePlatform();
            $where[] = '(q.id = ? OR q.subject LIKE ? OR q.email LIKE ? OR q.company LIKE ? OR ' . $platform->getConcatExpression('q.first_name', "' '", 'q.last_name') . ' LIKE ?'
                . ' OR c.email LIKE ? OR c.company LIKE ? OR ' . $platform->getConcatExpression('c.first_name', "' '", 'c.last_name') . ' LIKE ?)';
            array_push($params, ctype_digit(ltrim($search, '#')) ? (int) ltrim($search, '#') : 0, $like, $like, $like, $like, $like, $like, $like);
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) $db->fetchOne('SELECT COUNT(*) FROM invoice_quote q LEFT JOIN client c ON c.id = q.client_id WHERE ' . $sqlWhere, $params);
        $perPage = max(1, min(100, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $rows = $db->fetchAllAssociative(
            'SELECT q.* FROM invoice_quote q LEFT JOIN client c ON c.id = q.client_id WHERE ' . $sqlWhere . ' ORDER BY q.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $params
        );

        return [
            'list' => array_map(static fn (array $row): array => self::toArray($di, $row), $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    /**
     * The quotes of a client for the client area: sent ones (not drafts), newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function forClient(\Pimple\Container $di, int $clientId): array
    {
        $rows = self::db($di)->fetchAllAssociative("SELECT * FROM invoice_quote WHERE client_id = ? AND stage <> 'draft' ORDER BY id DESC", [$clientId]);

        return array_map(static fn (array $row): array => self::forViewer(self::toArray($di, $row)), $rows);
    }

    public static function delete(\Pimple\Container $di, int $id): void
    {
        self::get($di, $id);
        $db = self::db($di);
        $db->delete('invoice_quote_item', ['quote_id' => $id]);
        $db->delete('invoice_quote', ['id' => $id]);
    }

    /**
     * Emails the quote with its PDF and link to the client or prospect; a draft becomes delivered.
     */
    public static function send(\Pimple\Container $di, int $id): void
    {
        $quote = self::get($di, $id);
        if (!in_array($quote['stage'], self::OPEN_STAGES, true)) {
            throw new InformationException('Only a quote that is not accepted, lost or dead can be sent.');
        }
        if ($quote['items'] === []) {
            throw new InformationException('Add at least one line to the quote.');
        }
        $email = [
            'code' => 'mod_invoice_quote',
            'quote' => self::emailVars($quote),
            'quote_link' => self::link($di, $quote),
            'send_now' => true,
            'throw_exceptions' => true,
            'attachment' => [
                'content' => QuotePdf::render($di, $quote, self::recipientLocale($di, $quote)),
                'name' => QuotePdf::fileName($quote),
                'mime' => 'application/pdf',
            ],
        ];
        if ($quote['client_id'] !== null) {
            $email['to_client'] = $quote['client_id'];
        } else {
            $email['to'] = $quote['recipient']['email'];
            $email['to_name'] = $quote['recipient']['name'];
        }
        $di['mod_service']('email')->sendTemplate($email);
        if ($quote['client_id'] !== null) {
            \ClientFence\Mod\Clientfence\Notifications::client($di, (int) $quote['client_id'], 'quote_sent', ['number' => '#' . $id], 'invoice/quotes');
        }
        self::db($di)->update('invoice_quote', [
            'stage' => $quote['stage'] === 'draft' ? 'delivered' : $quote['stage'],
            'sent_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    /**
     * Accepts a quote: a prospect gets a client account (or the one that has its email now), then
     * the unpaid invoice is made with the quote's lines and approved, which emails it. `$by` is
     * 'staff', 'client' or 'link'; clients and links cannot accept an expired quote, and a link
     * shows the email works, so a new account made by it has its email confirmed. Returns the
     * invoice id.
     */
    public static function accept(\Pimple\Container $di, int $id, string $by): int
    {
        $db = self::db($di);
        $db->beginTransaction();
        try {
            // The row lock makes a second accept (double click, two tabs) wait and then see "accepted".
            $lock = $db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform ? ' FOR UPDATE' : '';
            $row = $db->fetchAssociative('SELECT * FROM invoice_quote WHERE id = ?' . $lock, [$id]);
            if ($row === false) {
                throw new InformationException('Quote not found', [], 404);
            }
            if (!in_array($row['stage'], self::OPEN_STAGES, true)) {
                throw new InformationException($row['stage'] === 'accepted' ? 'This quote is already accepted.' : 'This quote can no longer be accepted.');
            }
            if ($by !== 'staff' && $row['valid_until'] !== null && (string) $row['valid_until'] < date('Y-m-d')) {
                throw new InformationException('This quote has expired. Please contact us for a new one.');
            }
            $quote = self::toArray($di, $row);
            if ($quote['items'] === []) {
                throw new InformationException('Add at least one line to the quote.');
            }
            $newAccount = $quote['client_id'] === null && $db->fetchOne('SELECT id FROM client WHERE email = ?', [$quote['recipient']['email']]) === false;
            $clientId = $quote['client_id'] ?? self::accountFor($di, $quote, $by === 'link');
            $client = $di['db']->load('Client', $clientId);
            if (!$client instanceof \Model_Client) {
                throw new InformationException('Client not found');
            }
            if ($client->currency && $client->currency !== $quote['currency']) {
                throw new InformationException('This quote is in :quote, but the account uses :account. Please contact us.', [':quote' => $quote['currency'], ':account' => $client->currency]);
            }
            if (!$client->currency) {
                $client->currency = $quote['currency'];
                $di['db']->store($client);
            }

            $invoiceService = $di['mod_service']('invoice');
            $invoice = $invoiceService->prepareInvoice($client, [
                'text_1' => __trans('Quote #:id: :subject', [':id' => $id, ':subject' => $quote['subject']]),
                'items' => array_map(static fn (array $item): array => [
                    'title' => $item['description'],
                    'price' => $item['unit_price'],
                    'quantity' => $item['quantity'],
                    'taxed' => $item['taxed'],
                    'type' => \Model_InvoiceItem::TYPE_CUSTOM,
                ], $quote['items']),
            ]);
            $invoice->taxname = $quote['taxname'];
            $invoice->taxrate = $quote['taxrate'];
            $di['db']->store($invoice);
            $db->update('invoice_quote', [
                'client_id' => $clientId,
                'stage' => 'accepted',
                'invoice_id' => (int) $invoice->id,
                'accepted_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $id]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }

            throw $e;
        }
        // The emails go after the commit, so a failed acceptance sends none: the welcome email of a
        // new account (its link sets the password), then the invoice, which approving emails
        // (FOSSBilling's invoice events).
        if ($newAccount) {
            $di['mod_service']('client')->sendAdminCreatedWelcomeEmailForClient($client);
        }
        $invoiceService->approveInvoice($invoice, ['id' => $invoice->id]);
        if ($by !== 'staff') {
            \ClientFence\Mod\Clientfence\Notifications::staff($di, 'quote_accepted', ['number' => '#' . $id], 'invoice/quote/' . $id, 'invoice');
        }
        $di['logger']->info('Quote #%s accepted (%s): invoice #%s', $id, $by, $invoice->id);

        return (int) $invoice->id;
    }

    /**
     * The answer on the quote's page (its link): accept or decline. The signed-in client the quote
     * is for answers as the client, anyone else with the link as the link. The client's activity
     * log keeps who answered and from where, and the staff member who made the quote is emailed.
     * Returns the link to the new invoice when accepted.
     */
    public static function answer(\Pimple\Container $di, string $hash, bool $accept, ?int $signedInClient, string $ip): ?string
    {
        $quote = self::findByHash($di, $hash);
        if ($quote === null) {
            throw new InformationException('Quote not found', [], 404);
        }
        $by = $signedInClient !== null && $signedInClient === $quote['client_id'] ? 'client' : 'link';
        $invoiceUrl = null;
        if ($accept) {
            $invoiceId = self::accept($di, $quote['id'], $by);
            $invoiceHash = self::db($di)->fetchOne('SELECT hash FROM invoice WHERE id = ?', [$invoiceId]);
            $invoiceUrl = $invoiceHash !== false && $invoiceHash !== null ? (string) $di['url']->link('invoice/' . $invoiceHash) : null;
        } else {
            self::decline($di, $quote['id']);
        }
        $quote = self::get($di, $quote['id']);
        if ($quote['client_id'] !== null) {
            \ClientFence\Mod\Client\Users::activity($di, [$quote['client_id']], sprintf(
                '%s quote #%d (%s) %s, from %s',
                $accept ? 'Accepted' : 'Declined',
                $quote['id'],
                $quote['subject'],
                $by === 'client' ? 'signed in' : 'by the quote link',
                $ip
            ));
        }
        self::notifyStaff($di, $quote, $accept);

        return $invoiceUrl;
    }

    /** The client declines a quote: it becomes lost. */
    public static function decline(\Pimple\Container $di, int $id): void
    {
        self::get($di, $id);
        // Only while open, in one statement, so it cannot undo an acceptance made at the same time.
        $now = date('Y-m-d H:i:s');
        $changed = self::db($di)->executeStatement(
            'UPDATE invoice_quote SET stage = ?, declined_at = ?, updated_at = ? WHERE id = ? AND stage IN (?)',
            ['lost', $now, $now, $id, self::OPEN_STAGES],
            [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::STRING]
        );
        if ($changed === 0) {
            throw new InformationException('This quote can no longer be declined.');
        }
    }

    /**
     * Amounts of the lines (unit price after the discount, rounded to cents, times the whole
     * quantity), each taxed line's tax for display, and the totals with the tax counted on the
     * taxed subtotal, as invoices count it.
     *
     * @param list<array{quantity: int, price: float, discount: float, taxed: bool}> $items
     *
     * @return array{items: list<array<string, mixed>>, subtotal: float, tax: float, total: float}
     */
    public static function totals(array $items, float $taxrate): array
    {
        $subtotal = $taxable = 0.0;
        $lines = [];
        foreach ($items as $item) {
            $unit = round($item['price'] * (100 - $item['discount']) / 100, 2);
            $amount = round($unit * $item['quantity'], 2);
            $lineTax = $item['taxed'] && $taxrate > 0 ? round($amount * $taxrate / 100, 2) : 0.0;
            $subtotal += $amount;
            $taxable += $item['taxed'] ? $amount : 0.0;
            $lines[] = $item + ['unit_price' => $unit, 'amount' => $amount, 'tax' => $lineTax];
        }
        // Tax once on the taxed subtotal, as Invoice\Service::getTax(), so the invoice made from the quote has the same total.
        $tax = $taxrate > 0 ? round($taxable * $taxrate / 100, 2) : 0.0;

        return ['items' => $lines, 'subtotal' => round($subtotal, 2), 'tax' => $tax, 'total' => round($subtotal + $tax, 2)];
    }

    /** The link to the quote in the client area (it opens without signing in). */
    public static function link(\Pimple\Container $di, array $quote): string
    {
        return (string) $di['url']->link('quote/' . $quote['hash']);
    }

    /** The language of the recipient: the client's, else the request's. */
    public static function recipientLocale(\Pimple\Container $di, array $quote): string
    {
        $lang = $quote['client_id'] !== null ? (string) self::db($di)->fetchOne('SELECT lang FROM client WHERE id = ?', [$quote['client_id']]) : '';

        return $lang !== '' ? $lang : \Locale::getDefault();
    }

    /**
     * Emails staff that a client accepted or declined a quote: the staff member who made it, or
     * the staff of the email template's groups. A mail problem does not undo the answer.
     */
    private static function notifyStaff(\Pimple\Container $di, array $quote, bool $accepted): void
    {
        $email = [
            'code' => 'mod_staff_quote_answered',
            'quote' => self::emailVars($quote),
            'accepted' => $accepted,
            'quote_admin_url' => (string) $di['url']->adminLink('invoice/quote/' . $quote['id']),
        ];
        $createdBy = self::db($di)->fetchOne('SELECT a.id FROM invoice_quote q JOIN admin a ON a.id = q.created_by WHERE q.id = ?', [$quote['id']]);
        if ($createdBy !== false) {
            $email['to_admin'] = (int) $createdBy;
        } else {
            $email['to_staff'] = true;
        }
        try {
            $di['mod_service']('email')->sendTemplate($email);
        } catch (\Throwable $e) {
            $di['logger']->setChannel('email')->error('Quote #%s: the staff email could not be sent: %s', $quote['id'], $e->getMessage());
        }
    }

    // ---- Helpers -----------------------------------------------------------------------------

    /**
     * The recipient columns: a client (`client_id`), or a prospect (name, company, email, country,
     * currency). A prospect whose email has an account must be quoted as that client.
     *
     * @return array{client_id: int|null, first_name: string, last_name: string, company: string, email: string, country: string, currency: string}
     */
    private static function recipient(\Pimple\Container $di, array $data): array
    {
        $db = self::db($di);
        $clientId = (int) ($data['client_id'] ?? 0);
        if ($clientId > 0) {
            $currency = $db->fetchOne('SELECT currency FROM client WHERE id = ?', [$clientId]);
            if ($currency === false) {
                throw new InformationException('Client not found');
            }

            return ['client_id' => $clientId, 'first_name' => '', 'last_name' => '', 'company' => '', 'email' => '', 'country' => '', 'currency' => self::currency($db, (string) $currency)];
        }

        $first = trim((string) ($data['first_name'] ?? ''));
        $last = trim((string) ($data['last_name'] ?? ''));
        $company = trim((string) ($data['company'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if ($first === '') {
            throw new InformationException('Choose a client, or enter the first name and email of the prospect.');
        }
        foreach (['first_name' => $first, 'last_name' => $last, 'company' => $company] as $value) {
            if (mb_strlen($value) > 100) {
                throw new InformationException('Names can be at most :max characters.', [':max' => 100]);
            }
        }
        if ($email === '' || mb_strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InformationException('Enter a valid email for the prospect.');
        }
        $existing = $db->fetchOne('SELECT id FROM client WHERE email = ?', [$email]);
        if ($existing !== false) {
            throw new InformationException('Client #:id has this email: choose that client instead.', [':id' => $existing]);
        }
        $country = strtoupper(trim((string) ($data['country'] ?? '')));
        if ($country !== '' && (preg_match('/^[A-Z]{2}$/', $country) !== 1 || !\Symfony\Component\Intl\Countries::exists($country))) {
            throw new InformationException('Choose a country from the list.');
        }

        return [
            'client_id' => null,
            'first_name' => $first,
            'last_name' => $last,
            'company' => $company,
            'email' => $email,
            'country' => $country,
            'currency' => self::currency($db, strtoupper(trim((string) ($data['currency'] ?? '')))),
        ];
    }

    /** A currency the portal has, else its default one. */
    private static function currency(Connection $db, string $code): string
    {
        if ($code !== '' && $db->fetchOne('SELECT code FROM currency WHERE code = ?', [$code]) !== false) {
            return $code;
        }
        $default = $db->fetchOne('SELECT code FROM currency ORDER BY is_default DESC, code');

        return $default === false ? 'USD' : (string) $default;
    }

    /**
     * The quote's lines from the form: rows with no description and no price are left out.
     *
     * @return list<array{description: string, quantity: int, price: float, discount: float, taxed: int}>
     */
    private static function items(mixed $rows): array
    {
        if (!is_array($rows)) {
            throw new InformationException('Add at least one line to the quote.');
        }
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $description = trim((string) ($row['description'] ?? ''));
            $price = trim((string) ($row['price'] ?? ''));
            if ($description === '' && ($price === '' || (float) $price == 0.0)) {
                continue;
            }
            $line = count($items) + 1;
            if ($description === '' || mb_strlen($description) > 255) {
                throw new InformationException('Line :line: enter a description of at most :max characters.', [':line' => $line, ':max' => 255]);
            }
            $quantity = trim((string) ($row['quantity'] ?? '1'));
            if (preg_match('/^[0-9]{1,6}$/', $quantity) !== 1 || (int) $quantity < 1) {
                throw new InformationException('Line :line: the quantity must be a whole number from 1.', [':line' => $line]);
            }
            if (preg_match('/^-?[0-9]{1,12}(\.[0-9]{1,2})?$/', $price) !== 1) {
                throw new InformationException('Line :line: enter the price as a number with at most two decimals.', [':line' => $line]);
            }
            $discount = trim((string) ($row['discount'] ?? ''));
            if ($discount !== '' && (preg_match('/^[0-9]{1,3}(\.[0-9]{1,2})?$/', $discount) !== 1 || (float) $discount > 100)) {
                throw new InformationException('Line :line: the discount is a percentage from 0 to 100.', [':line' => $line]);
            }
            $items[] = [
                'description' => $description,
                'quantity' => (int) $quantity,
                'price' => (float) $price,
                'discount' => $discount === '' ? 0.0 : (float) $discount,
                'taxed' => !empty($row['taxed']) ? 1 : 0,
            ];
            if (count($items) > self::MAX_ITEMS) {
                throw new InformationException('A quote can have at most :max lines.', [':max' => self::MAX_ITEMS]);
            }
        }
        if ($items === []) {
            throw new InformationException('Add at least one line to the quote.');
        }

        return $items;
    }

    /**
     * The tax rule as invoices find it: the client's (Invoice ServiceTax), or for a prospect the
     * rule of its country, else the rule for all countries, when the portal charges tax. A prospect
     * without a country gets the account's default country on acceptance (Client createClient()),
     * so its quote is taxed as that country is.
     *
     * @return array{0: string, 1: float}
     */
    private static function taxRule(\Pimple\Container $di, ?int $clientId, string $country): array
    {
        if ($clientId !== null) {
            $client = $di['db']->load('Client', $clientId);
            $title = '';
            $rate = $client instanceof \Model_Client ? (float) $di['mod_service']('Invoice', 'Tax')->getTaxRateForClient($client, $title) : 0.0;

            return [(string) $title, $rate];
        }
        if (!$di['mod_service']('system')->getParamValue('tax_enabled', false)) {
            return ['', 0.0];
        }
        if ($country === '') {
            $country = (string) ($di['mod']('system')->getConfig()['default_country'] ?? '');
        }
        $db = self::db($di);
        $rule = $country !== '' ? $db->fetchAssociative("SELECT name, taxrate FROM tax WHERE country = ? AND (state IS NULL OR state = '') LIMIT 1", [$country]) : false;
        if ($rule === false) {
            $rule = $db->fetchAssociative("SELECT name, taxrate FROM tax WHERE (state IS NULL OR state = '') AND (country IS NULL OR country = '') LIMIT 1");
        }

        return $rule === false ? ['', 0.0] : [(string) $rule['name'], (float) $rule['taxrate']];
    }

    /**
     * The account for a prospect who accepts: the client that has the email by now, else a new one
     * made as staff make clients (accept() then sends FOSSBilling's welcome email with the link to
     * set a password).
     */
    private static function accountFor(\Pimple\Container $di, array $quote, bool $emailShown): int
    {
        $db = self::db($di);
        $email = (string) $quote['recipient']['email'];
        $existing = $db->fetchOne('SELECT id FROM client WHERE email = ?', [$email]);
        if ($existing !== false) {
            return (int) $existing;
        }
        $clientId = $di['mod_service']('client')->adminCreateClient([
            'email' => $email,
            'first_name' => $quote['recipient']['first_name'],
            'last_name' => $quote['recipient']['last_name'],
            'company' => $quote['recipient']['company'],
            'country' => $quote['recipient']['country'] !== '' ? $quote['recipient']['country'] : null,
            'currency' => $quote['currency'],
            'send_welcome_email' => false,
        ]);
        if ($emailShown) {
            $db->update('client', ['email_approved' => 1], ['id' => $clientId]);
        }

        return $clientId;
    }

    /** @return array<string, mixed> */
    private static function toArray(\Pimple\Container $di, array $row): array
    {
        $db = self::db($di);
        $items = array_map(static fn (array $item): array => [
            'description' => (string) $item['description'],
            'quantity' => (int) $item['quantity'],
            'price' => (float) $item['price'],
            'discount' => (float) $item['discount'],
            'taxed' => (bool) $item['taxed'],
        ], $db->fetchAllAssociative('SELECT * FROM invoice_quote_item WHERE quote_id = ? ORDER BY position, id', [(int) $row['id']]));
        $clientId = $row['client_id'] !== null ? (int) $row['client_id'] : null;
        $client = $clientId !== null ? $db->fetchAssociative('SELECT id, first_name, last_name, company, email, country FROM client WHERE id = ?', [$clientId]) : false;
        $source = $client !== false ? $client : $row;
        $totals = self::totals($items, (float) $row['taxrate']);

        return [
            'id' => (int) $row['id'],
            'hash' => (string) $row['hash'],
            'client_id' => $clientId,
            'recipient' => [
                'first_name' => (string) $source['first_name'],
                'last_name' => (string) $source['last_name'],
                'name' => trim($source['first_name'] . ' ' . $source['last_name']),
                'company' => (string) $source['company'],
                'email' => (string) $source['email'],
                'country' => (string) $source['country'],
                'prospect' => $clientId === null,
            ],
            'currency' => (string) $row['currency'],
            'subject' => (string) $row['subject'],
            'stage' => (string) $row['stage'],
            'stage_title' => self::stages()[$row['stage']] ?? (string) $row['stage'],
            'open' => in_array($row['stage'], self::OPEN_STAGES, true),
            'expired' => $row['valid_until'] !== null && (string) $row['valid_until'] < date('Y-m-d'),
            'valid_until' => $row['valid_until'] !== null ? (string) $row['valid_until'] : '',
            'proposal' => (string) $row['proposal'],
            'customer_notes' => (string) $row['customer_notes'],
            'admin_notes' => (string) $row['admin_notes'],
            'taxname' => (string) $row['taxname'],
            'taxrate' => (float) $row['taxrate'],
            'invoice_id' => $row['invoice_id'] !== null ? (int) $row['invoice_id'] : null,
            'sent_at' => $row['sent_at'],
            'accepted_at' => $row['accepted_at'],
            'declined_at' => $row['declined_at'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ] + $totals;
    }

    /** What the client area shows: no admin notes. */
    public static function forViewer(array $quote): array
    {
        unset($quote['admin_notes']);

        return $quote;
    }

    /** What the quote email may show: no admin notes or link hash. */
    private static function emailVars(array $quote): array
    {
        unset($quote['admin_notes'], $quote['hash']);

        return $quote;
    }

    private static function longText(mixed $value, string $what): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", is_scalar($value) ? (string) $value : ''));
        if (mb_strlen($text) > self::TEXT_MAX) {
            throw new InformationException('The :what can be at most :max characters.', [':what' => __trans($what), ':max' => self::TEXT_MAX]);
        }

        return $text;
    }

    private static function isDate(string $date): bool
    {
        return preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $date, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * The tables, made on first use (Clientfence\Service::ensureTables()), once a request. The API
     * calls it before using the quotes.
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
