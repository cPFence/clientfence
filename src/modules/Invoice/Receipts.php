<?php

declare(strict_types=1);

/**
 * ClientFence: payment method cards and payment receipts.
 *
 * Payment method cards: the theme shows the methods a client can use for an amount as cards
 * (paymentMethods(); guest API invoice_payment_methods), and a manual method's instructions on
 * the invoice page (client API invoice_payment_details).
 *
 * Payment receipts: on an unpaid invoice the client picks a manual payment method (Custom and
 * adapters built on it), sees its instructions and uploads the transfer receipt (client API
 * invoice_receipt_send). The file is kept outside the web root (dir()) and a support ticket in
 * the receipt_helpdesk help desk (Invoices settings page, mod_invoice) tells staff, with a link
 * to open it. The link is in the client area (/receipt/<id>, Controller/Client.php), so the
 * client's ticket never shows the admin area's address: it opens for the client who sent the
 * receipt and for staff with support permission (one session holds both).
 * /<admin prefix>/invoice/receipt/<id> opens it for staff too.
 */

namespace ClientFence\Mod\Invoice;

use ClientFence\InformationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

class Receipts
{
    // Types by content (not by name or browser claim), size cap, uploads per client per hour.
    public const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const PER_HOUR = 5;

    /**
     * Folder of the payment receipts, outside the web root (next to the FOSSBilling folder:
     * ~/.clientfence-receipts by default). CLIENTFENCE_RECEIPT_DIR overrides it.
     */
    public static function dir(): string
    {
        $dir = getenv('CLIENTFENCE_RECEIPT_DIR') ?: dirname(PATH_ROOT) . '/.clientfence-receipts';

        return rtrim($dir, '/');
    }

    /**
     * The payment methods a client can use for an amount, as the theme shows them: online
     * methods first, then manual ones (Custom and adapters built on it) in the order they were
     * added. Each has a two-letter mark and a colour (tone 0-4) for its card, and manual methods
     * that show the amount in another currency (CustomEgp) carry that quote and its rate.
     * single and recurrent: whether the method takes a one-time payment and, for a subscribable
     * invoice, a subscription (the theme's "one-time or subscription" choice). fee: the method's gateway fee
     * (GatewayFees.php), and total what the client pays with it: for $invoice, its balance without its fee line plus
     * this method's fee and its tax.
     */
    public static function paymentMethods(\Pimple\Container $di, float $amount, string $currency, bool $subscribable = false, ?\Model_Invoice $invoice = null): array
    {
        $gatewayService = $di['mod_service']('Invoice', 'PayGateway');
        $methods = [];
        $clientId = $invoice !== null ? (int) $invoice->client_id : ((int) ($di['session']->get('client_id') ?? 0) ?: null);
        $amount = $invoice !== null ? GatewayFees::base($di, $invoice) : $amount;
        $deposit = $invoice !== null && $di['mod_service']('Invoice')->isInvoiceTypeDeposit($invoice);
        foreach ($di['db']->find('PayGateway', 'enabled = 1 ORDER BY id') as $gateway) {
            if (!in_array($currency, $gatewayService->getAcceptedCurrencies($gateway), true)
                || !($gateway->allow_single || ($subscribable && $gateway->allow_recurrent))) {
                continue;
            }
            $class = $gatewayService->getAdapterClassName($gateway);
            if (!class_exists($class)) {
                continue;
            }
            $manual = is_a($class, \Payment_Adapter_Custom::class, true);
            // Add funds invoices carry no fee (GatewayFees::apply()).
            $fee = $deposit ? 0.0 : GatewayFees::fee($di, $gateway, $amount, $currency, $clientId);
            $total = round($amount + $fee + ($invoice !== null && $fee > 0 && GatewayFees::settings($gateway)['taxable'] ? GatewayFees::tax($di, $invoice, $fee) : 0.0), 2);
            $quote = null;
            if ($manual && method_exists($class, 'egpAmount')) {
                try {
                    $egp = $gatewayService->getPaymentAdapter($gateway)->egpAmount($total, $currency);
                    if (is_array($egp)) {
                        $quote = ['currency' => 'EGP', 'total' => $egp['total'], 'rate' => (float) $egp['rate']];
                    }
                } catch (\Throwable $e) {
                    $di['logger']->error('Invoice: no quote for payment method #%s: %s', $gateway->id, $e->getMessage());
                }
            }
            $methods[] = [
                'id' => (int) $gateway->id,
                'code' => (string) $gateway->gateway,
                'title' => (string) $gateway->name,
                'manual' => $manual,
                'converts' => $manual && method_exists($class, 'egpAmount'),
                'quote' => $quote,
                'mark' => self::paymentMark((string) $gateway->name),
                'single' => (bool) $gateway->allow_single,
                'recurrent' => $subscribable && (bool) $gateway->allow_recurrent,
                'fee' => $fee,
                'total' => $total,
            ];
        }
        usort($methods, static fn (array $a, array $b): int => [$a['manual'], $a['id']] <=> [$b['manual'], $b['id']]);
        foreach ($methods as $i => &$method) {
            $method['tone'] = $i % 5;
        }

        return $methods;
    }

    /**
     * Two letters for a payment method card: a currency code in brackets ("Bank transfer (USD)"
     * gives US), else the capitals of a one-word name ("InstaPay" gives IP) or its first two
     * letters ("Stripe" gives ST), else the first letters of the first two words ("E-wallet"
     * gives EW).
     */
    public static function paymentMark(string $name): string
    {
        if (preg_match('/\(([A-Z]{3})\)/', $name, $m)) {
            return substr($m[1], 0, 2);
        }
        $plain = trim((string) preg_replace('/\(.*?\)/', '', $name));
        $words = preg_split('/[\s\-]+/u', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) === 1) {
            preg_match_all('/\p{Lu}/u', $words[0], $caps);

            return count($caps[0]) >= 2 ? mb_substr(implode('', $caps[0]), 0, 2) : mb_strtoupper(mb_substr($words[0], 0, 2));
        }
        $letters = implode('', array_map(static fn (string $w): string => mb_substr($w, 0, 1), array_slice($words, 0, 2)));

        return mb_strtoupper($letters !== '' ? $letters : '?');
    }

    /**
     * An unpaid invoice of this client, by its hash; throws when there is none.
     */
    public static function clientUnpaidInvoice(\Pimple\Container $di, int $clientId, string $hash): \Model_Invoice
    {
        $invoice = preg_match('/^[a-z0-9]{1,64}$/', $hash) === 1
            ? $di['db']->findOne('Invoice', 'hash = ? AND client_id = ?', [$hash, $clientId])
            : null;
        if (!$invoice instanceof \Model_Invoice) {
            throw new InformationException('Invoice not found');
        }
        if ($invoice->status !== \Model_Invoice::STATUS_UNPAID) {
            throw new InformationException('This invoice is not waiting for payment.');
        }

        return $invoice;
    }

    /**
     * An enabled manual payment method (Custom or built on it) by id; throws otherwise.
     */
    public static function manualGateway(\Pimple\Container $di, int $gatewayId): \Model_PayGateway
    {
        $gateway = $di['db']->findOne('PayGateway', 'id = ? AND enabled = 1', [$gatewayId]);
        if (!$gateway instanceof \Model_PayGateway
            || !is_a($di['mod_service']('Invoice', 'PayGateway')->getAdapterClassName($gateway), \Payment_Adapter_Custom::class, true)) {
            throw new InformationException('Choose a payment method that takes transfers.');
        }

        return $gateway;
    }

    /**
     * The help desk payment receipts open a ticket in (receipt_helpdesk; '' for the first one),
     * checked for the Invoices settings page.
     */
    public static function helpdeskSetting(\Pimple\Container $di, mixed $value): string
    {
        $helpdesk = trim((string) $value);
        if ($helpdesk === '') {
            return '';
        }
        if (!ctype_digit($helpdesk) || $di['em']->getConnection()->fetchOne('SELECT 1 FROM support_helpdesk WHERE id = ?', [(int) $helpdesk]) === false) {
            throw new InformationException('The help desk for payment receipts was not found.');
        }

        return (string) (int) $helpdesk;
    }

    /**
     * Stores a payment receipt and tells staff in a support ticket. Returns the ticket id.
     */
    public static function send(\Pimple\Container $di, \Model_Client $client, \Model_Invoice $invoice, \Model_PayGateway $gateway, ?UploadedFile $file): int
    {
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new InformationException('Choose the receipt file (an image or a PDF).');
        }
        $size = (int) $file->getSize();
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new InformationException('The receipt must be smaller than 5 MB.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
        if (!isset(self::TYPES[$mime])) {
            throw new InformationException('The receipt must be a JPG, PNG or WebP image or a PDF.');
        }

        $dir = self::dir() . '/' . (int) $client->id;
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \ClientFence\Exception('The receipt could not be stored.');
        }
        $recent = 0;
        foreach (glob($dir . '/*.json') ?: [] as $meta) {
            if (filemtime($meta) > time() - 3600) {
                ++$recent;
            }
        }
        if ($recent >= self::PER_HOUR) {
            throw new InformationException('You sent several receipts in the last hour. Please try again later or send it from Support.');
        }

        $id = bin2hex(random_bytes(16));
        $name = preg_replace('/[^A-Za-z0-9 ._-]+/', '_', basename((string) $file->getClientOriginalName())) ?: 'receipt';
        $name = mb_substr(trim($name, ' .'), 0, 80) ?: 'receipt';
        $file->move($dir, $id . '.bin');
        chmod($dir . '/' . $id . '.bin', 0600);
        file_put_contents($dir . '/' . $id . '.json', json_encode([
            'client_id' => (int) $client->id,
            'invoice_id' => (int) $invoice->id,
            'gateway_id' => (int) $gateway->id,
            'name' => $name,
            'mime' => $mime,
            'size' => $size,
            'at' => date('c'),
        ]));
        chmod($dir . '/' . $id . '.json', 0600);

        try {
            $invoiceArray = $di['mod_service']('Invoice')->toApiArray($invoice);
            $number = (string) ($invoiceArray['serie_nr'] ?? $invoice->id);
            $amount = number_format((float) $invoiceArray['total'], 2) . ' ' . $invoice->currency;
            $link = $di['url']->link('receipt/' . $id);
            $support = $di['mod_service']('support');
            $helpdeskId = (int) ($di['mod_config']('invoice')['receipt_helpdesk'] ?? 0);
            $helpdesk = $helpdeskId > 0 ? $support->getHelpdeskRepository()->find($helpdeskId) : null;
            $helpdesk ??= $support->getHelpdeskRepository()->findOneBy([], ['id' => 'ASC']);
            if ($helpdesk === null) {
                throw new \ClientFence\Exception('No help desk for payment receipts.');
            }
            $ticketId = $support->ticketCreateForClient($client, $helpdesk, [
                'subject' => 'Payment receipt: invoice #' . $number,
                'support_helpdesk_id' => $helpdesk->getId(),
                'content' => "I paid invoice #{$number} ({$amount}) by {$gateway->name}. The receipt is attached.\n\n"
                    . "Receipt: [{$name}]({$link})",
            ]);
        } catch (\Throwable $e) {
            @unlink($dir . '/' . $id . '.bin');
            @unlink($dir . '/' . $id . '.json');

            throw $e;
        }
        // The client pays this invoice by transfer: as in WHMCS, the invoice takes that payment method, so the saved-card cron skips it.
        // Only these columns of an invoice still unpaid: a full store would write back the status loaded before a payment made meanwhile.
        $di['dbal']->executeStatement('UPDATE invoice SET gateway_id = ?, updated_at = ? WHERE id = ? AND status = ?', [(int) $gateway->id, date('Y-m-d H:i:s'), (int) $invoice->id, \Model_Invoice::STATUS_UNPAID]);
        // The client was asked for the balance with this method's fee (paymentMethods()): the invoice now carries it.
        GatewayFees::apply($di, $invoice, (int) $gateway->id);
        $di['logger']->info('Invoice: client #%s sent a receipt for invoice #%s (ticket #%s)', $client->id, $invoice->id, $ticketId);

        return (int) $ticketId;
    }

    /**
     * The HTTP response for a stored receipt (find()): images inline, PDFs as a download, never
     * run or sniffed by the browser.
     */
    public static function response(array $receipt): Response
    {
        $meta = $receipt['meta'];
        $extension = self::TYPES[$meta['mime']];
        $name = $meta['name'] . (str_ends_with(strtolower($meta['name']), '.' . $extension) ? '' : '.' . $extension);
        $inline = str_starts_with($meta['mime'], 'image/');

        return new Response((string) file_get_contents($receipt['path']), 200, [
            'Content-Type' => $meta['mime'],
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $name) . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; sandbox",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * A stored receipt by id: ['path' => ..., 'meta' => [...]], or null.
     */
    public static function find(string $id): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
            return null;
        }
        foreach (glob(self::dir() . '/*/' . $id . '.json') ?: [] as $metaFile) {
            $meta = json_decode((string) file_get_contents($metaFile), true);
            $path = substr($metaFile, 0, -5) . '.bin';
            if (is_array($meta) && isset(self::TYPES[$meta['mime'] ?? '']) && is_file($path)) {
                return ['path' => $path, 'meta' => $meta];
            }
        }

        return null;
    }
}
