<?php

declare(strict_types=1);

/**
 * ClientFence: sends the books to an accounting program, one way (owner decisions 2026-11-13): clients as contacts,
 * approved invoices issued on or after the start date (or the earlier "send older invoices" date), their payments
 * and refunds as credit notes. One program at a time (PROGRAMS; adapters in Program.php). By default an invoice goes
 * when it is issued and its payment once it is paid; with "paid invoices only" an invoice goes once paid, with it.
 *
 * Runs from the admin cron and from "Send now", BATCH records a run, one run at a time. It scans the records instead
 * of listening to events, so every way of paying (gateway, account credit, staff Mark as paid) and every refund is
 * seen. Table invoice_accounting_sync keeps one row per record and program: its remote id, status, attempts, last
 * error and the fingerprint of what was sent; a changed unpaid invoice is sent again, a paid one is final once its
 * payment is there. A failed record is tried on the next runs and stops after MAX_ATTEMPTS, with one staff
 * notice; "Send again" clears its attempts. An HTTP 429 stops the run until the program's Retry-After has passed.
 * A deleted or cancelled invoice is voided at the program unless it has a payment there.
 *
 * How ClientFence records the money, and what goes over:
 * - an invoice's lines are net (discounts are negative lines, a gateway fee is a line, an Add funds line goes to the
 *   account credit account), its tax is taxOf() of the taxed lines at the invoice's one rate, spread over the taxed
 *   lines so the program's total is ClientFence's; the tax rate is chosen per tax name and rate, "No tax" and the EU
 *   reverse charge;
 * - a gateway payment is a processed transaction that credits the client's balance, which then pays the invoice
 *   ("using account credit"); staff Mark as paid records a transaction only for Custom methods. So a paid invoice's
 *   payments are its transactions, then account credit for the rest when credit paid it (or the credit applied to it
 *   when staff marked it paid), then the rest through the invoice's payment method;
 * - a refund is a refunded invoice row with negative lines (credit note or negative invoice), noted on the invoice it
 *   refunds; the money went back through that invoice's payment method, or to account credit (a positive balance
 *   row for it). A downgrade's credit note refunds nothing: it all goes to account credit.
 */

namespace ClientFence\Mod\Invoice\Accounting;

use ClientFence\InformationException;
use ClientFence\Mod\Clientfence\Notifications;
use ClientFence\Mod\Invoice\EuVat;
use ClientFence\Mod\Invoice\Service;
use ClientFence\Http\RequestFactory;
use ClientFence\Tools;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class Sync
{
    public const TABLE = 'invoice_accounting_sync';
    public const PROGRAMS = ['xero' => Xero::class];
    // Records per run: Xero allows 60 calls a minute and an invoice with its client and payment takes up to six.
    public const BATCH = 10;
    public const MAX_ATTEMPTS = 10;
    public const SETTINGS_URL = 'extension/settings/invoice#accounting';
    private const STATE = 'invoice_accounting_state';
    private const NOTICE = 'invoice_accounting_notice';
    private const STATE_SECONDS = 600;
    private const LOCK = 'cf:accounting-sync';
    /** A transaction (alias tx) that brought money in, as payments() counts it. */
    private const MONEY_IN = "CAST(tx.amount AS DECIMAL(18,2)) > 0 AND COALESCE(tx.txn_status, '') <> 'imported' AND COALESCE(tx.type, '') <> 'refund'";
    /** About an invoice's (alias i) total, to the cent; only to narrow the scan, payments() has the exact one. */
    private const TOTAL = '((SELECT COALESCE(SUM(it.price * it.quantity), 0) FROM invoice_item it WHERE it.invoice_id = i.id)
        + (SELECT COALESCE(SUM(it.price * it.quantity), 0) FROM invoice_item it WHERE it.invoice_id = i.id AND it.taxed = 1) * CAST(i.taxrate AS DECIMAL(10,4)) / 100
        + COALESCE(i.tax_adjust, 0))';

    private int $limit = 0;
    private array $done = ['sent' => 0, 'failed' => 0, 'waiting' => false];
    /** Records that failed in this run: a client refused once is not tried again for each of its invoices. */
    private array $failedNow = [];

    public function __construct(private readonly \Pimple\Container $di, private ?Program $program = null)
    {
    }

    /** The admin cron: a run when a program is connected. */
    public static function cron(\Pimple\Container $di): void
    {
        $sync = new self($di);
        if ($sync->param('program') !== '' && ($sync->tokens()['refresh_token'] ?? '') !== '') {
            $sync->run();
        }
    }

    /** The settings without secrets, the connection, the local tax keys and gateways to map, the last 50 rows and the counts. */
    public function get(): array
    {
        $tokens = $this->tokens();
        $notice = $this->di['session']->get(self::NOTICE);
        if ($notice !== null) {
            $this->di['session']->delete(self::NOTICE);
        }
        $db = $this->di['dbal'];
        $program = (string) $this->param('program');
        $taxes = ['none' => __trans('No tax'), 'reverse_charge' => __trans('EU reverse charge')];
        foreach ($db->fetchAllAssociative('SELECT name, taxrate FROM tax UNION SELECT taxname, taxrate FROM invoice WHERE CAST(taxrate AS DECIMAL(10,4)) > 0') as $tax) {
            if ((float) $tax['taxrate'] > 0) {
                $taxes[self::taxKey((string) $tax['name'], (float) $tax['taxrate'])] = trim($tax['name'] . ' ' . self::rate((float) $tax['taxrate']) . '%');
            }
        }
        $padding = $this->padding();
        $rows = array_map(static function (array $row) use ($padding): array {
            $row['number'] = match ($row['entity']) {
                'client' => '#' . $row['local_id'],
                'surplus' => self::surplusNumber((int) $row['local_id']),
                default => $row['id_invoice'] === null ? null : self::format(['id' => $row['id_invoice'], 'serie' => $row['serie'], 'nr' => $row['nr']], $padding),
            };
            unset($row['serie'], $row['nr'], $row['id_invoice']);

            return $row;
        }, $db->fetchAllAssociative(
            'SELECT s.id, s.entity, s.local_id, s.remote_id, s.status, s.attempts, s.last_error, s.synced_at, s.updated_at, i.id AS id_invoice, i.serie, i.nr
               FROM ' . self::TABLE . " s LEFT JOIN invoice i ON s.entity IN ('invoice', 'payment', 'credit') AND i.id = s.local_id
              WHERE s.program = ? AND NOT (s.entity = 'surplus' AND s.status = 'sent' AND s.remote_id IS NULL)
              ORDER BY CASE WHEN s.status IN ('stopped', 'failed') THEN 0 ELSE 1 END, s.updated_at DESC, s.id DESC LIMIT 50",
            [$program],
        ));

        $connected = ($tokens['refresh_token'] ?? '') !== '';
        // The mapping lists come from the program; a refused call shows on the card instead of breaking the page.
        $lists = ['accounts' => [], 'tax_rates' => [], 'error' => null];
        if ($connected) {
            try {
                $lists = $this->lists() + ['error' => null];
            } catch (\ClientFence\Exception $e) {
                $lists['error'] = $e->getMessage();
            }
        }

        return [
            'program' => $program,
            'programs' => array_map(static fn (string $class): string => $class::label(), self::PROGRAMS),
            'client_id' => (string) $this->param('client_id'),
            'has_secret' => $this->param('client_secret') !== '',
            'connected' => $connected,
            'lists' => $lists,
            'organisation' => (string) ($tokens['tenant_name'] ?? ''),
            'redirect_uri' => $this->redirectUri(),
            'start_date' => (string) $this->param('start_date'),
            'backfill_from' => (string) $this->param('backfill_from'),
            'paid_only' => (bool) $this->param('paid_only'),
            'paused' => (int) $this->param('wait_until', 0) > time() ? (string) $this->param('wait_reason') : '',
            'sales_account' => (string) $this->param('sales_account'),
            'bank_accounts' => $this->json('bank_accounts'),
            'tax_map' => $this->json('tax_map'),
            'taxes' => $taxes,
            // A list, not id => name: Twig's merge renumbers numeric keys.
            'gateways' => $db->fetchAllAssociative('SELECT id, name FROM pay_gateway ORDER BY id'),
            'counts' => array_map('intval', $db->fetchAllKeyValue('SELECT status, COUNT(*) FROM ' . self::TABLE . ' WHERE program = ? GROUP BY status', [$program])),
            'rows' => $rows,
            'notice' => $notice,
        ];
    }

    /** Saves the settings that are given; the client secret only when one is typed. A new program or app disconnects. */
    public function save(array $data): void
    {
        $program = (string) ($data['program'] ?? $this->param('program'));
        if ($program !== '' && !isset(self::PROGRAMS[$program])) {
            throw new InformationException('Choose an accounting program from the list.');
        }
        $clientId = trim((string) ($data['client_id'] ?? $this->param('client_id')));
        $start = trim((string) ($data['start_date'] ?? $this->param('start_date')));
        if ($start === '' && $program !== '') {
            $start = date('Y-m-d');
        }
        if ($start !== '') {
            self::date($start);
        }
        if (mb_strlen($clientId) > 255) {
            throw new InformationException('The client ID is too long.');
        }
        if ($program !== $this->param('program') || $clientId !== $this->param('client_id')) {
            $this->setParam('tokens', '');
        }
        $this->setParam('program', $program);
        $this->setParam('client_id', $clientId);
        $this->setParam('start_date', $start);
        $secret = trim((string) ($data['client_secret'] ?? ''));
        if ($secret !== '') {
            $this->setParam('client_secret', $this->di['crypt']->encrypt($secret));
        }
        if (array_key_exists('paid_only', $data)) {
            $this->setParam('paid_only', empty($data['paid_only']) ? '0' : '1');
        }
        if (array_key_exists('sales_account', $data)) {
            $this->setParam('sales_account', self::value($data['sales_account']));
        }
        foreach (['bank_accounts' => '/^(credit|[0-9]+)$/', 'tax_map' => '/^.{1,300}$/u'] as $name => $keys) {
            if (!array_key_exists($name, $data)) {
                continue;
            }
            $map = [];
            foreach (is_array($data[$name]) ? $data[$name] : [] as $key => $value) {
                if (preg_match($keys, (string) $key) !== 1) {
                    throw new InformationException('The account and tax choices could not be read. Reload the page and try again.');
                }
                if (self::value($value) !== '') {
                    $map[(string) $key] = self::value($value);
                }
            }
            $this->setParam($name, (string) json_encode($map, JSON_UNESCAPED_UNICODE));
        }
        $this->program = null;
        $this->di['logger']->info('Invoice: accounting sync settings saved (%s).', $program === '' ? 'off' : $program);
    }

    /** The program's sign-in address; the state waits in the staff session, once, for STATE_SECONDS. */
    public function connectUrl(): string
    {
        $state = bin2hex(random_bytes(32));
        $url = $this->program()->authorizeUrl($state, $this->redirectUri());
        $this->di['session']->set(self::STATE, ['state' => $state, 'expires' => time() + self::STATE_SECONDS]);

        return $url;
    }

    /** The program sent the staff member back (`state`, `code` or `error`): the code becomes the stored tokens. */
    public function finishConnect(array $query): void
    {
        $kept = $this->di['session']->get(self::STATE);
        $this->di['session']->delete(self::STATE);
        $state = is_string($query['state'] ?? null) ? $query['state'] : '';
        if (!is_array($kept) || $state === '' || (int) ($kept['expires'] ?? 0) < time() || !hash_equals((string) ($kept['state'] ?? ''), $state)) {
            throw new InformationException('This connection request has expired or was not started here. Click Connect again.');
        }
        $program = $this->program();
        if (isset($query['error'])) {
            throw new InformationException(':program did not connect: :error', [':program' => $program::label(), ':error' => mb_substr((string) $query['error'], 0, 200)]);
        }
        $code = is_string($query['code'] ?? null) ? $query['code'] : '';
        if ($code === '') {
            throw new InformationException('This connection request has expired or was not started here. Click Connect again.');
        }
        $tokens = $program->connect($code, $this->redirectUri(), $query);
        // Another organisation has none of the records sent so far: they all go again, to it.
        $name = (string) $this->param('program');
        if ((string) $this->param('tenant_' . $name) !== (string) ($tokens['tenant_id'] ?? '')) {
            $this->di['dbal']->delete(self::TABLE, ['program' => $name]);
            $this->setParam('tenant_' . $name, (string) ($tokens['tenant_id'] ?? ''));
        }
        $this->saveTokens($tokens);
        $this->setParam('wait_until', '0');
        $this->di['logger']->info('Invoice: connected to %s for the accounting sync.', $program::label());
    }

    /**
     * The program sends the browser back from another site, so the SameSite=Strict staff cookie is left out; a session
     * started for that request would replace the staff one. index.php answers it before any session with a page that
     * moves on to the same address (`moved`), a same-site request that carries the session (as Http\PaymentReturn).
     */
    public static function returnHop(Request $request, \ClientFence\Url $url): ?Response
    {
        $query = $request->query->all();
        if (RequestFactory::getRoutePath($request) !== ADMIN_PREFIX . '/invoice/accounting/callback' || isset($query['moved'])) {
            return null;
        }
        $next = $url->adminLink('invoice/accounting/callback', array_filter(array_intersect_key($query, array_flip(['state', 'code', 'error', 'realmId'])), 'is_string') + ['moved' => 1]);
        $href = htmlspecialchars($next, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $js = json_encode($next, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $nonce = \ClientFence\Csp::nonce();

        return new Response(
            '<!doctype html><html><head><meta charset="utf-8"><title>Continue</title></head><body><p><a href="' . $href . '">Continue</a></p>'
            . '<script nonce="' . $nonce . '">location.replace(' . $js . ');</script></body></html>',
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store',
                'Referrer-Policy' => 'no-referrer',
                'Content-Security-Policy' => "default-src 'none'; script-src 'nonce-$nonce'; base-uri 'none'; frame-ancestors 'none'",
                'X-Content-Type-Options' => 'nosniff',
                'X-Robots-Tag' => 'noindex',
            ],
        );
    }

    /** The callback's outcome for the settings page, shown once (get()). */
    public function notice(string $text): void
    {
        $this->di['session']->set(self::NOTICE, $text);
    }

    public function disconnect(): void
    {
        $this->setParam('tokens', '');
        $this->program = null;
        $this->di['logger']->info('Invoice: the accounting program was disconnected.');
    }

    /** The program's accounts and tax rates, for the mapping lists. */
    public function lists(): array
    {
        return Tools::withLock($this->di, self::LOCK, fn (): array => ['accounts' => $this->program()->accounts(), 'tax_rates' => $this->program()->taxRates()], 30);
    }

    /** "Send again": the row's attempts start over and it goes on the next run, as if it had changed. */
    public function resend(int $id): void
    {
        $updated = $this->di['dbal']->update(self::TABLE, ['status' => 'queued', 'attempts' => 0, 'fingerprint' => null, 'last_error' => null, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
        if ($updated === 0) {
            throw new InformationException('Sync record not found.');
        }
    }

    /** "Send all again": every failed or stopped row starts over, after a fix in the settings or at the program. */
    public function resendAll(): int
    {
        return (int) $this->di['dbal']->executeStatement(
            'UPDATE ' . self::TABLE . " SET status = 'queued', attempts = 0, fingerprint = NULL, last_error = NULL, updated_at = ? WHERE program = ? AND status IN ('failed', 'stopped')",
            [date('Y-m-d H:i:s'), (string) $this->param('program')],
        );
    }

    /** "Send older invoices": invoices issued from $from, before the start date, are sent too. */
    public function backfill(string $from): void
    {
        self::date($from);
        if ($from >= (string) $this->param('start_date')) {
            throw new InformationException('Choose a date before the start date.');
        }
        $this->setParam('backfill_from', $from);
    }

    /** One run: changed clients, then invoices with their client and payment, credit notes, voids. */
    public function run(int $limit = self::BATCH): array
    {
        return Tools::withLock($this->di, self::LOCK, function () use ($limit): array {
            $this->limit = $limit;
            $this->done = ['sent' => 0, 'failed' => 0, 'waiting' => false];
            $this->failedNow = [];
            $start = (string) $this->param('start_date');
            $from = min($start, (string) $this->param('backfill_from') ?: $start);
            if ($from === '' || (int) $this->param('wait_until', 0) > time()) {
                $this->done['waiting'] = $from !== '';

                return $this->done;
            }
            // A setting every record needs stops the run here instead of failing each record.
            $program = $this->program();
            if ((string) $this->param('sales_account') === '') {
                throw new InformationException('Choose the :program sales account in the accounting settings.', [':program' => $program::label()]);
            }
            try {
                foreach (['clients' => 'contact', 'invoices' => 'invoice', 'credits' => 'credit', 'voids' => 'void', 'surpluses' => 'surplus'] as $what => $method) {
                    foreach ($this->due($what, $from) as $id) {
                        if ($this->done['sent'] + $this->done['failed'] >= $this->limit) {
                            break 2;
                        }
                        $this->{$method}($id);
                    }
                }
            } catch (RateLimited $e) {
                $this->setParam('wait_until', (string) (time() + $e->retryAfter));
                $this->setParam('wait_reason', $e->getMessage());
                $this->done['waiting'] = true;
                $this->di['logger']->info('Invoice: accounting sync paused: %s', $e->getMessage());
                if ($e->outage !== null) {
                    Notifications::staff($this->di, 'accounting_paused', ['program' => $program::label()], self::SETTINGS_URL, 'invoice', null, 'accounting_paused:' . $this->param('program') . ':' . date('Y-m-d'));
                }
            }

            return $this->done;
        }, 0, static fn (): array => ['sent' => 0, 'failed' => 0, 'waiting' => false, 'busy' => true]);
    }

    /**
     * @param 'clients'|'invoices'|'credits'|'voids'|'surpluses' $what
     *
     * @return list<int> the ids of what this run looks at
     */
    private function due(string $what, string $from): array
    {
        $t = self::TABLE;
        $p = (string) $this->param('program');
        $statuses = $this->param('paid_only') ? "'paid', 'refunded'" : "'unpaid', 'paid', 'refunded'";
        $lines = '(SELECT COALESCE(SUM(t.price * t.quantity), 0) FROM invoice_item t WHERE t.invoice_id = i.id)';
        // A client that stopped holds its documents back without trying them (they would fill every run).
        $clientOk = "NOT EXISTS (SELECT 1 FROM {$t} sc WHERE sc.program = ? AND sc.entity = 'client' AND sc.local_id = i.client_id AND sc.status = 'stopped')";
        [$sql, $params] = match ($what) {
            'clients' => ["SELECT s.local_id FROM {$t} s JOIN client c ON c.id = s.local_id WHERE s.program = ? AND s.entity = 'client'
                AND (s.status IN ('queued', 'failed') OR (s.status = 'sent' AND c.updated_at >= s.synced_at)) ORDER BY s.local_id", [$p]],
            // New, changed, or paid with its payment still to send; a sent payment makes the invoice final. A voided one
            // comes back when the invoice is open again (invoice()).
            'invoices' => ["SELECT i.id FROM invoice i
                LEFT JOIN {$t} si ON si.program = ? AND si.entity = 'invoice' AND si.local_id = i.id
                LEFT JOIN {$t} sp ON sp.program = ? AND sp.entity = 'payment' AND sp.local_id = i.id
                WHERE i.approved = 1 AND i.created_at >= ? AND i.status IN ({$statuses}) AND {$lines} > 0 AND {$clientOk}
                  AND COALESCE(si.status, '') <> 'stopped' AND COALESCE(sp.status, '') NOT IN ('stopped', 'sent')
                  AND (si.id IS NULL OR si.status <> 'sent' OR i.status <> 'unpaid' OR i.updated_at >= si.synced_at
                       OR (SELECT MAX(t.updated_at) FROM invoice_item t WHERE t.invoice_id = i.id) >= si.synced_at)
                ORDER BY i.id", [$p, $p, $from, $p]],
            // A sent downgrade credit that a refund took back goes again, to be voided (credit()).
            'credits' => ["SELECT i.id FROM invoice i LEFT JOIN {$t} s ON s.program = ? AND s.entity = 'credit' AND s.local_id = i.id
                WHERE i.approved = 1 AND i.status = 'refunded' AND i.created_at >= ? AND {$lines} < 0 AND {$clientOk}
                  AND (s.id IS NULL OR s.status IN ('queued', 'failed')
                       OR (s.status = 'sent' AND EXISTS (SELECT 1 FROM invoice_item tb WHERE tb.invoice_id = i.id AND tb.task = ?)))
                ORDER BY i.id", [$p, $from, $p, Service::CREDIT_NOTE_TAKEN_BACK_TASK]],
            'voids' => ["SELECT s.local_id FROM {$t} s LEFT JOIN invoice i ON i.id = s.local_id
                WHERE s.program = ? AND s.entity = 'invoice' AND s.status IN ('sent', 'queued', 'failed')
                  AND (i.id IS NULL OR i.status = 'canceled' OR (i.status = 'unpaid' AND {$lines} <= 0))
                ORDER BY s.local_id", [$p]],
            // Money that became account credit instead of paying an invoice (Service::validatePaymentAmount() only logs an
            // overpayment): a payment on an invoice whose payment was already sent, more than an invoice's total, or a
            // gateway payment without an invoice that credited the client (client_balance type transaction).
            'surpluses' => ["SELECT tx.id FROM `transaction` tx
                LEFT JOIN {$t} s ON s.program = ? AND s.entity = 'surplus' AND s.local_id = tx.id
                LEFT JOIN invoice i ON i.id = tx.invoice_id
                LEFT JOIN {$t} sp ON sp.program = ? AND sp.entity = 'payment' AND sp.local_id = i.id AND sp.status = 'sent'
                WHERE tx.status = 'processed' AND " . self::MONEY_IN . " AND tx.created_at >= ? AND (s.id IS NULL OR s.status IN ('queued', 'failed'))
                  AND ((i.id IS NULL AND EXISTS (SELECT 1 FROM client_balance b WHERE b.type = 'transaction' AND b.rel_id = tx.id))
                    OR (sp.id IS NOT NULL AND (tx.created_at > sp.synced_at OR (SELECT SUM(CAST(t2.amount AS DECIMAL(18,2))) FROM `transaction` t2
                        WHERE t2.invoice_id = i.id AND t2.status = 'processed' AND " . str_replace('tx.', 't2.', self::MONEY_IN) . ") > " . self::TOTAL . ' + 0.05)))
                ORDER BY tx.id', [$p, $p, $from]],
        };

        return array_map('intval', $this->di['dbal']->fetchFirstColumn($sql . ' LIMIT ' . $this->limit, $params));
    }

    private function contact(int $clientId): ?string
    {
        return $this->ensure('client', $clientId, function () use ($clientId): array {
            $c = $this->di['dbal']->fetchAssociative('SELECT * FROM client WHERE id = ?', [$clientId]) ?: throw new InformationException('Client #:id no longer exists.', [':id' => $clientId]);
            $person = trim($c['first_name'] . ' ' . $c['last_name']);

            return [
                'id' => $clientId,
                'name' => trim((string) $c['company']) !== '' ? trim((string) $c['company']) : ($person !== '' ? $person : (string) $c['email']),
                'first_name' => (string) $c['first_name'],
                'last_name' => (string) $c['last_name'],
                'company' => (string) $c['company'],
                'email' => (string) ($c['billing_email'] ?: $c['email']),
                'vat' => (string) $c['company_vat'],
                'phone' => (string) $c['phone'],
                'phone_cc' => (string) $c['phone_cc'],
                'address_1' => (string) $c['address_1'],
                'address_2' => (string) $c['address_2'],
                'city' => (string) $c['city'],
                'state' => (string) $c['state'],
                'zip' => (string) $c['postcode'],
                'country' => (string) $c['country'],
            ];
        }, fn (array $data, ?string $remote): string => $this->program()->contact($data + ['remote_id' => $remote]));
    }

    private function invoice(int $id): void
    {
        $invoice = $this->di['dbal']->fetchAssociative('SELECT * FROM invoice WHERE id = ?', [$id]);
        $contact = $invoice === false ? null : $this->contact((int) $invoice['client_id']);
        if ($contact === null) {
            return;
        }
        $row = $this->row('invoice', $id);
        // Cancelled (voided there) and open again: a voided invoice cannot come back, so it goes as a new one, "-2" on its number.
        if ($row !== null && $row['status'] === 'voided') {
            $this->write($row, 'invoice', $id, ['status' => 'queued', 'remote_id' => null, 'fingerprint' => null, 'attempts' => 0, 'last_error' => null, 'revision' => (int) $row['revision'] + 1]);
            $row = $this->row('invoice', $id);
        }
        $suffix = (int) ($row['revision'] ?? 0) > 0 ? '-' . ((int) $row['revision'] + 1) : '';
        $remote = $this->ensure('invoice', $id, function () use ($invoice, $contact, $suffix): array {
            $doc = $this->document($invoice, 1) + ['contact' => $contact];
            $doc['number'] .= $suffix;

            return $doc;
        }, fn (array $doc, ?string $remote): string => $this->program()->invoice($doc + ['remote_id' => $remote]));
        if ($remote === null || $invoice['status'] === \Model_Invoice::STATUS_UNPAID) {
            return;
        }
        // A gateway marks the invoice paid before its transaction is processed (the email and order setup run in between):
        // until then its money would look like account credit.
        if ($this->di['dbal']->fetchOne("SELECT 1 FROM `transaction` WHERE invoice_id = ? AND status IN ('received', 'approved', 'processing') AND created_at >= ?", [$id, date('Y-m-d H:i:s', time() - 3600)]) !== false) {
            return;
        }
        $this->ensure('payment', $id, fn (): array => $this->payments($invoice), function (array $parts) use ($remote, $invoice): string {
            $ids = [];
            foreach ($parts as $part) {
                $ids[] = $this->program()->payment($part + ['invoice' => $remote, 'currency' => (string) $invoice['currency']]);
            }

            return implode(' ', $ids);
        }, true);
    }

    private function credit(int $id): void
    {
        // A downgrade credit that a full refund took back (Service::refundInvoiceLocked()) is gone: it is voided there too.
        if ($this->di['dbal']->fetchOne('SELECT 1 FROM invoice_item WHERE invoice_id = ? AND task = ?', [$id, Service::CREDIT_NOTE_TAKEN_BACK_TASK]) !== false) {
            $row = $this->row('credit', $id);
            try {
                if (($row['remote_id'] ?? null) !== null) {
                    $this->program()->voidCreditNote((string) $row['remote_id']);
                }
            } catch (RateLimited $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->fail($row, 'credit', $id, $e);

                return;
            }
            $this->write($row, 'credit', $id, ['status' => 'voided', 'last_error' => null, 'synced_at' => date('Y-m-d H:i:s')]);
            ++$this->done['sent'];

            return;
        }
        $note = $this->di['dbal']->fetchAssociative('SELECT * FROM invoice WHERE id = ?', [$id]);
        $contact = $note === false ? null : $this->contact((int) $note['client_id']);
        if ($contact === null) {
            return;
        }
        $this->ensure('credit', $id, function () use ($note, $contact): array {
            $doc = $this->document($note, -1) + ['contact' => $contact];
            // The refunded invoice notes its credit notes (Service::refundInvoiceLocked(), refundPartLocked()).
            $source = $this->di['dbal']->fetchAssociative('SELECT * FROM invoice WHERE notes LIKE ? OR notes LIKE ? ORDER BY id LIMIT 1', ['%credit note #' . $note['id'] . '.%', '%Refund invoice #' . $note['id'] . ' generated.%']);
            // A part refund's line is Custom (Service::creditNote()): an Add funds invoice's refund still comes out of account credit.
            if ($source !== false && (int) $this->di['dbal']->fetchOne('SELECT COUNT(*) FROM invoice_item WHERE invoice_id = ? AND type <> ?', [(int) $source['id'], \Model_InvoiceItem::TYPE_DEPOSIT]) === 0) {
                $doc['lines'] = array_map(fn (array $line): array => ['account' => $this->creditAccount()] + $line, $doc['lines']);
            }
            $toCredit = $source === false ? $doc['total'] : min($doc['total'], round((float) $this->di['dbal']->fetchOne(
                "SELECT COALESCE(SUM(amount), 0) FROM client_balance WHERE type = 'invoice' AND rel_id = ? AND amount > 0",
                [(string) $note['id']],
            ), 2));
            $date = substr((string) $note['created_at'], 0, 10);
            $refunds = [];
            if ($toCredit > 0) {
                $refunds[] = ['account' => $this->creditAccount(), 'amount' => $toCredit, 'date' => $date, 'reference' => 'Account credit'];
            }
            if ($doc['total'] - $toCredit > 0) {
                $refunds[] = ['account' => $this->bank($source['gateway_id'], self::format($source, $this->padding())), 'amount' => round($doc['total'] - $toCredit, 2), 'date' => $date, 'reference' => 'Refund ' . $doc['number']];
            }

            return $doc + ['refunds' => $refunds, 'reference' => $source === false ? '' : self::format($source, $this->padding())];
        }, fn (array $doc, ?string $remote): string => $this->program()->creditNote($doc + ['remote_id' => $remote]), true);
    }

    private function void(int $id): void
    {
        $db = $this->di['dbal'];
        $row = $this->row('invoice', $id);
        if ($db->fetchOne('SELECT status FROM ' . self::TABLE . " WHERE program = ? AND entity = 'payment' AND local_id = ?", [$row['program'], $id]) === 'sent') {
            $this->write($row, 'invoice', $id, ['status' => 'stopped', 'last_error' => __trans('Deleted or cancelled here, but kept at :program because it has a payment there.', [':program' => $this->program()::label()])]);
            $this->di['logger']->warning('Invoice: invoice #%s is gone or cancelled but has a payment at %s, so it was not voided there.', $id, $this->program()::label());

            return;
        }
        try {
            if ($row['remote_id'] !== null) {
                $this->program()->void((string) $row['remote_id']);
            }
        } catch (RateLimited $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->fail($row, 'invoice', $id, $e);

            return;
        }
        $this->write($row, 'invoice', $id, ['status' => 'voided', 'last_error' => null, 'synced_at' => date('Y-m-d H:i:s')]);
        ++$this->done['sent'];
    }

    /**
     * Money that became account credit (the 'surpluses' scan): an "Account credit" invoice into the account credit
     * account, paid from the transaction's bank account, as an Add funds invoice is.
     */
    private function surplus(int $id): void
    {
        $db = $this->di['dbal'];
        $tx = $db->fetchAssociative('SELECT * FROM `transaction` WHERE id = ?', [$id]);
        $invoice = $tx === false ? false : $db->fetchAssociative('SELECT * FROM invoice WHERE id = ?', [(int) $tx['invoice_id']]);
        $clientId = $invoice !== false ? (int) $invoice['client_id'] : (int) $db->fetchOne("SELECT client_id FROM client_balance WHERE type = 'transaction' AND rel_id = ?", [(string) $id]);
        if ($tx === false || $clientId <= 0) {
            return;
        }
        $amount = round((float) $tx['amount'], 2);
        if ($invoice !== false) {
            // What payments() took of it when the invoice's payment was sent; a later one is all extra.
            $sent = (string) $db->fetchOne('SELECT synced_at FROM ' . self::TABLE . " WHERE program = ? AND entity = 'payment' AND local_id = ? AND status = 'sent'", [(string) $this->param('program'), (int) $invoice['id']]);
            if ((string) $tx['created_at'] <= $sent) {
                $left = $this->document($invoice, 1)['total'];
                foreach ($this->transactions((int) $invoice['id'], $sent) as $earlier) {
                    $used = round(min((float) $earlier['amount'], max($left, 0.0)), 2);
                    $left = round($left - $used, 2);
                    if ((int) $earlier['id'] === $id) {
                        $amount = round($amount - $used, 2);

                        break;
                    }
                }
            }
        }
        if ($amount <= 0) {
            // Nothing beyond the invoice: noted so the scan does not look at it again.
            $this->write($this->row('surplus', $id), 'surplus', $id, ['status' => 'sent', 'synced_at' => date('Y-m-d H:i:s')]);

            return;
        }
        $contact = $this->contact($clientId);
        if ($contact === null) {
            return;
        }
        $this->ensure('surplus', $id, function () use ($tx, $invoice, $clientId, $amount, $contact, $id): array {
            $client = $this->di['dbal']->fetchAssociative('SELECT * FROM client WHERE id = ?', [$clientId]) ?: [];
            $date = substr((string) $tx['created_at'], 0, 10);
            $number = self::surplusNumber($id);

            return [
                'invoice' => ['number' => $number, 'date' => $date, 'due_date' => $date, 'contact' => $contact, 'total' => $amount,
                    'currency' => (string) ($tx['currency'] ?: ($invoice['currency'] ?? '') ?: ($client['currency'] ?? '')),
                    'lines' => [['description' => 'Account credit', 'quantity' => 1.0, 'unit_price' => $amount, 'amount' => $amount, 'account' => $this->creditAccount(),
                        'tax' => (string) ($this->json('tax_map')['none'] ?? throw new InformationException('Choose the :program tax rate for ":tax" in the accounting settings.', [':program' => $this->program()::label(), ':tax' => 'none'])), 'tax_amount' => 0.0]]],
                'payment' => ['account' => $this->bank($tx['gateway_id'], $number), 'amount' => $amount, 'date' => $date, 'reference' => 'Transaction ' . $id],
            ];
        }, function (array $data, ?string $remote): string {
            $remote = $this->program()->invoice($data['invoice'] + ['remote_id' => $remote]);
            $this->program()->payment($data['payment'] + ['invoice' => $remote, 'currency' => $data['invoice']['currency']]);

            return $remote;
        }, true);
    }

    /** The transactions that paid an invoice, as payments() reads them; only those made by $until when given. */
    private function transactions(int $invoiceId, ?string $until = null): array
    {
        return $this->di['dbal']->fetchAllAssociative(
            "SELECT id, gateway_id, amount, created_at FROM `transaction` tx WHERE invoice_id = ? AND status = 'processed' AND " . self::MONEY_IN
            . ($until === null ? '' : ' AND created_at <= ?') . ' ORDER BY id',
            $until === null ? [$invoiceId] : [$invoiceId, $until],
        );
    }

    private static function surplusNumber(int $transactionId): string
    {
        return 'CREDIT-' . $transactionId;
    }

    /**
     * Sends a record unless what $build makes is what was sent last ($once: once sent, never again). Returns the
     * program's id, or null when it is not there (failed, stopped).
     */
    private function ensure(string $entity, int $localId, callable $build, callable $send, bool $once = false): ?string
    {
        $row = $this->row($entity, $localId);
        if (($row !== null && in_array($row['status'], ['stopped', 'voided'], true)) || isset($this->failedNow[$entity . ':' . $localId])) {
            return null;
        }
        if ($once && $row !== null && $row['status'] === 'sent') {
            return $row['remote_id'];
        }
        try {
            $data = $build();
            $fingerprint = sha1((string) json_encode($data));
            if ($row !== null && $row['status'] === 'sent' && $row['fingerprint'] === $fingerprint) {
                $this->write($row, $entity, $localId, ['synced_at' => date('Y-m-d H:i:s')]);

                return $row['remote_id'];
            }
            $remote = $send($data, $row['remote_id'] ?? null);
        } catch (RateLimited $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->failedNow[$entity . ':' . $localId] = true;
            $this->fail($row, $entity, $localId, $e);

            return null;
        }
        $this->write($row, $entity, $localId, ['remote_id' => $remote, 'status' => 'sent', 'attempts' => 0, 'last_error' => null, 'fingerprint' => $fingerprint, 'synced_at' => date('Y-m-d H:i:s')]);
        ++$this->done['sent'];

        return $remote;
    }

    /** A failed attempt: kept with its error (never in the log: it can name the client), stopped at MAX_ATTEMPTS with one staff notice. */
    private function fail(?array $row, string $entity, int $localId, \Throwable $e): void
    {
        $attempts = (int) ($row['attempts'] ?? 0) + 1;
        $stopped = $attempts >= self::MAX_ATTEMPTS;
        $this->write($row, $entity, $localId, ['status' => $stopped ? 'stopped' : 'failed', 'attempts' => $attempts, 'last_error' => mb_substr($e->getMessage(), 0, 2000)]);
        ++$this->done['failed'];
        $program = (string) $this->param('program');
        $label = isset(self::PROGRAMS[$program]) ? self::PROGRAMS[$program]::label() : $program;
        $this->di['logger']->warning('Invoice: %s #%s was not sent to %s (attempt %s of %s).', $entity, $localId, $label, $attempts, self::MAX_ATTEMPTS);
        if ($stopped) {
            $number = '#' . $localId;
            if ($entity !== 'client') {
                $invoice = $this->di['dbal']->fetchAssociative('SELECT id, serie, nr FROM invoice WHERE id = ?', [$localId]);
                $number = $invoice === false ? $number : self::format($invoice, $this->padding());
            }
            Notifications::staff($this->di, 'accounting_stopped', ['program' => $label, 'number' => $number], self::SETTINGS_URL, 'invoice', null, 'accounting_stopped:' . $program . ':' . $entity . ':' . $localId);
        }
    }

    private function row(string $entity, int $localId): ?array
    {
        $row = $this->di['dbal']->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE program = ? AND entity = ? AND local_id = ?', [(string) $this->param('program'), $entity, $localId]);

        return $row === false ? null : $row;
    }

    private function write(?array $row, string $entity, int $localId, array $values): void
    {
        $now = date('Y-m-d H:i:s');
        if ($row !== null) {
            $this->di['dbal']->update(self::TABLE, $values + ['updated_at' => $now], ['id' => (int) $row['id']]);

            return;
        }
        $this->di['dbal']->insert(self::TABLE, $values + ['program' => (string) $this->param('program'), 'entity' => $entity, 'local_id' => $localId, 'status' => 'failed', 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now]);
    }

    /**
     * An invoice ($sign 1) or credit note (-1, amounts made positive) as the program gets it: net lines with their
     * account, tax rate and tax amount, the tax of each taxed line rounded and the cents left on the last one.
     */
    private function document(array $invoice, int $sign): array
    {
        $items = $this->di['dbal']->fetchAllAssociative('SELECT title, price, quantity, taxed, type FROM invoice_item WHERE invoice_id = ? ORDER BY id', [(int) $invoice['id']]);
        $rate = (float) $invoice['taxrate'];
        $subtotal = $taxable = 0.0;
        foreach ($items as $item) {
            $amount = (float) $item['price'] * (float) $item['quantity'];
            $subtotal += $amount;
            $taxable += $item['taxed'] ? $amount : 0.0;
        }
        $tax = Service::taxOf($taxable, $rate, (float) $invoice['tax_adjust']);
        $reverse = EuVat::reverseCharge($this->di, $invoice, $tax);
        $map = $this->json('tax_map');
        $program = $this->program()::label();
        $lines = [];
        $last = null;
        $spread = 0.0;
        foreach ($items as $item) {
            if ((float) $item['quantity'] == 0) {
                continue;
            }
            $amount = round((float) $item['price'] * (float) $item['quantity'], 2);
            $key = $reverse ? 'reverse_charge' : ($item['taxed'] && $rate > 0 ? self::taxKey((string) $invoice['taxname'], $rate) : 'none');
            $taxed = $item['taxed'] && $tax != 0.0;
            $lines[] = [
                'description' => (string) $item['title'],
                'quantity' => (float) $item['quantity'],
                'unit_price' => $sign * (float) $item['price'],
                'amount' => $sign * $amount,
                'account' => $item['type'] === \Model_InvoiceItem::TYPE_DEPOSIT ? $this->creditAccount() : ((string) $this->param('sales_account') ?: throw new InformationException('Choose the :program sales account in the accounting settings.', [':program' => $program])),
                'tax' => (string) ($map[$key] ?? throw new InformationException('Choose the :program tax rate for ":tax" in the accounting settings.', [':program' => $program, ':tax' => $key])),
                'tax_amount' => $taxed ? $sign * round($amount * $rate / 100, 2) : 0.0,
            ];
            if ($taxed) {
                $last = array_key_last($lines);
                $spread += $lines[$last]['tax_amount'];
            }
        }
        if ($last !== null) {
            $lines[$last]['tax_amount'] = round($lines[$last]['tax_amount'] + $sign * $tax - $spread, 2);
        }

        return [
            'number' => self::format($invoice, $this->padding()),
            'date' => substr((string) $invoice['created_at'], 0, 10),
            'due_date' => substr((string) ($invoice['due_at'] ?: $invoice['created_at']), 0, 10),
            'currency' => (string) $invoice['currency'],
            'total' => round($sign * ($subtotal + $tax), 2),
            'lines' => $lines,
        ];
    }

    /** The payments of a paid invoice: its transactions, then account credit, then the rest by its payment method. */
    private function payments(array $invoice): array
    {
        $db = $this->di['dbal'];
        $id = (int) $invoice['id'];
        $number = self::format($invoice, $this->padding());
        $left = $this->document($invoice, 1)['total'];
        $paidOn = substr((string) ($invoice['paid_at'] ?: $invoice['updated_at']), 0, 10);
        $parts = [];
        foreach ($this->transactions($id) as $tx) {
            $amount = round(min((float) $tx['amount'], $left), 2);
            if ($amount <= 0) {
                break;
            }
            $parts[] = ['account' => $this->bank($tx['gateway_id'], $number), 'amount' => $amount, 'date' => substr((string) $tx['created_at'], 0, 10), 'reference' => 'Transaction ' . $tx['id']];
            $left = round($left - $amount, 2);
        }
        // Gateways write their own type (paypal_rest, eway, PayPal's web_accept...): every positive one but a refund is money in.
        // Service::tryPayWithCredits() pays from the balance: what the transactions above did not bring was credit.
        $byCredit = $db->fetchOne("SELECT 1 FROM client_balance WHERE type = 'invoice' AND rel_id = ? AND amount < 0 AND description LIKE ?", [(string) $id, 'Payment for invoice #% using account credit.']) !== false;
        $credit = $byCredit ? $left : min($left, round((float) $db->fetchOne('SELECT COALESCE(SUM(amount), 0) FROM invoice_credit WHERE invoice_id = ?', [$id]), 2));
        if ($credit > 0) {
            $parts[] = ['account' => $this->creditAccount(), 'amount' => $credit, 'date' => $paidOn, 'reference' => 'Account credit'];
            $left = round($left - $credit, 2);
        }
        if ($left > 0) {
            $parts[] = ['account' => $this->bank($invoice['gateway_id'], $number), 'amount' => $left, 'date' => $paidOn, 'reference' => 'Payment ' . $number];
        }

        return $parts;
    }

    private function bank(mixed $gatewayId, string $number): string
    {
        $program = $this->program()::label();
        if ((int) $gatewayId <= 0) {
            throw new InformationException('Invoice :number has no payment method, so its :program bank account is not known.', [':number' => $number, ':program' => $program]);
        }

        return (string) ($this->json('bank_accounts')[(string) (int) $gatewayId] ?? '') ?: throw new InformationException('Choose the :program account for payments by :gateway in the accounting settings.', [
            ':program' => $program,
            ':gateway' => (string) ($this->di['dbal']->fetchOne('SELECT name FROM pay_gateway WHERE id = ?', [(int) $gatewayId]) ?: '#' . (int) $gatewayId),
        ]);
    }

    private function creditAccount(): string
    {
        return (string) ($this->json('bank_accounts')['credit'] ?? '') ?: throw new InformationException('Choose the :program account for account credit in the accounting settings.', [':program' => $this->program()::label()]);
    }

    private function program(): Program
    {
        if ($this->program !== null) {
            return $this->program;
        }
        $code = (string) $this->param('program');
        $class = self::PROGRAMS[$code] ?? throw new InformationException('Choose an accounting program first.');
        $secret = $this->secret('client_secret');
        if ($this->param('client_id') === '' || $secret === '') {
            throw new InformationException('Enter the client ID and client secret of your :program app first.', [':program' => $class::label()]);
        }

        return $this->program = new $class($this->di['http_client'], (string) $this->param('client_id'), $secret, $this->tokens(), function (array $tokens): void {
            $this->saveTokens($tokens);
        });
    }

    private function tokens(): array
    {
        $tokens = json_decode($this->secret('tokens'), true);

        return is_array($tokens) ? $tokens : [];
    }

    private function saveTokens(array $tokens): void
    {
        $this->setParam('tokens', $this->di['crypt']->encrypt((string) json_encode($tokens)));
    }

    private function redirectUri(): string
    {
        return $this->di['url']->adminLink('invoice/accounting/callback');
    }

    private function param(string $name, mixed $default = ''): mixed
    {
        return $this->di['mod_service']('system')->getParamValue('accounting_' . $name, $default) ?? $default;
    }

    private function setParam(string $name, string $value): void
    {
        $this->di['mod_service']('system')->setParamValue('accounting_' . $name, $value);
    }

    private function json(string $name): array
    {
        $value = json_decode((string) $this->param($name), true);

        return is_array($value) ? $value : [];
    }

    private function secret(string $name): string
    {
        $value = (string) $this->param($name);

        return $value === '' ? '' : (string) ($this->di['crypt']->decrypt($value) ?: '');
    }

    private function padding(): int
    {
        $padding = $this->di['mod_service']('system')->getParamValue('invoice_number_padding');

        return $padding !== null && $padding !== '' ? (int) $padding : 5;
    }

    /** The number as the client sees it (Service::toApiArray() serie_nr). */
    private static function format(array $invoice, int $padding): string
    {
        return $invoice['serie'] . sprintf('%0' . $padding . 's', is_numeric($invoice['nr']) ? (int) $invoice['nr'] : (int) $invoice['id']);
    }

    /** The tax map key of a tax name and rate ("VAT|20"). */
    public static function taxKey(string $name, float $rate): string
    {
        return trim($name) . '|' . self::rate($rate);
    }

    private static function rate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.');
    }

    private static function value(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        if (mb_strlen($value) > 100) {
            throw new InformationException('The account and tax choices could not be read. Reload the page and try again.');
        }

        return $value;
    }

    private static function date(string $value): void
    {
        $date = \DateTime::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InformationException('Enter the date as YYYY-MM-DD.');
        }
    }
}
