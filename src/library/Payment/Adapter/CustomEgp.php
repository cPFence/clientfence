<?php

declare(strict_types=1);
/**
 * Manual payment instructions with the invoice total in Egyptian pounds, for
 * ClientFence. Invoices stay in USD; only the instructions show the EGP
 * equivalent at the current rate.
 *
 * Works like FOSSBilling's "Custom" gateway (an admin confirms the payment), and adds
 * an `egp` variable to the instruction template:
 *   egp.total    invoice total in EGP, rounded up to a whole pound, e.g. "5,075"
 *   egp.rate     EGP per one unit of the invoice currency, e.g. "50.7432"
 *   egp.updated  date of the rate, e.g. "2026-10-08"
 * `egp` is null when no rate is available, so templates should use {% if egp %}.
 *
 * Rates come from ExchangeRate-API's open endpoint (the same source FOSSBilling's
 * currency module uses), cached until the provider's next daily update. Their terms
 * ask for attribution: keep a "Rates by Exchange Rate API" link in the template.
 *
 * Optional and off by default: a new install has no Custom EGP gateway. Staff add it under
 * Payment gateways only if they take payments in Egyptian pounds.
 */
use Symfony\Contracts\Cache\ItemInterface;

// FOSSBilling requires adapter files directly, so load the parent adapter here.
require_once __DIR__ . '/Custom.php';

class Payment_Adapter_CustomEgp extends Payment_Adapter_Custom
{
    private const RATES_URL = 'https://open.er-api.com/v6/latest/';

    public function __construct(private $egpConfig)
    {
        parent::__construct($egpConfig);
    }

    public static function getConfig(): array
    {
        $config = parent::getConfig();
        $config['supports_subscriptions'] = false;
        $config['description'] = 'Manual payment instructions with the invoice total converted to Egyptian pounds at the current rate (ExchangeRate-API, refreshed daily). Use {{ egp.total }}, {{ egp.rate }} and {{ egp.updated }} inside {% if egp %} ... {% endif %}, with a "Rates by Exchange Rate API" link. HTML is supported.';

        return $config;
    }

    public function getHtml(ClientFence\Api\Proxy $api_admin, int $invoice_id, bool $subscription): string
    {
        $invoiceModel = $this->di['db']->load('Invoice', $invoice_id);
        $invoice = $this->di['mod_service']('Invoice')->toApiArray($invoiceModel, true);

        $tpl = $subscription ? ($this->egpConfig['recurrent'] ?? '') : ($this->egpConfig['single'] ?? '');
        if (trim((string) $tpl) === '') {
            $tpl = '"Custom EGP" payment adapter is not fully configured.';
        }

        return $this->di['mod_service']('System')->renderAdapterTplString($tpl, [
            'invoice' => $invoice,
            'egp' => $this->egpAmount((float) $invoice['total'], (string) $invoice['currency']),
        ]);
    }

    /**
     * The invoice total in EGP, or null when the rate cannot be fetched.
     */
    public function egpAmount(float $total, string $currency): ?array
    {
        $currency = strtoupper($currency);
        $rates = $this->rates($currency);
        $rate = $currency === 'EGP' ? 1.0 : ($rates['rates']['EGP'] ?? null);
        if (!is_numeric($rate) || (float) $rate <= 0) {
            return null;
        }

        return [
            'total' => number_format(ceil($total * (float) $rate), 0, '.', ','),
            'rate' => number_format((float) $rate, 4, '.', ''),
            'updated' => isset($rates['time_last_update_unix']) ? gmdate('Y-m-d', (int) $rates['time_last_update_unix']) : '',
        ];
    }

    /**
     * Latest rates for one base currency, cached until the provider's next update.
     * A failed fetch is cached for 15 minutes so invoices never wait on a dead API.
     */
    protected function rates(string $base): array
    {
        if (!preg_match('/^[A-Z]{3}$/', $base)) {
            return [];
        }

        return $this->di['cache']->get('clientfence.egp_rates.' . $base, function (ItemInterface $item) use ($base): array {
            try {
                $data = $this->di['http_client']->request('GET', self::RATES_URL . $base, ['timeout' => 10])->toArray();
            } catch (Throwable $e) {
                error_log('CustomEgp: rate fetch failed: ' . $e->getMessage());
                $item->expiresAfter(15 * 60);

                return [];
            }

            if (($data['result'] ?? '') !== 'success' || !isset($data['rates']['EGP'])) {
                error_log('CustomEgp: rate provider returned ' . ($data['error-type'] ?? 'no EGP rate'));
                $item->expiresAfter(15 * 60);

                return [];
            }

            try {
                $next = new DateTime((string) ($data['time_next_update_utc'] ?? ''));
                $item->expiresAt($next > new DateTime() ? $next : new DateTime('+1 hour'));
            } catch (Exception) {
                $item->expiresAfter(6 * 60 * 60);
            }

            return [
                'rates' => ['EGP' => (float) $data['rates']['EGP']],
                'time_last_update_unix' => (int) ($data['time_last_update_unix'] ?? time()),
            ];
        });
    }
}
