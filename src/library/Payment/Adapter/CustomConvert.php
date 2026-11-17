<?php

declare(strict_types=1);
/**
 * Manual payment instructions with the invoice total converted to another currency, for
 * ClientFence. Invoices keep their currency; only the instructions show the amount in the
 * gateway's currency (its "currency" setting; EUR when empty) at the current rate.
 *
 * Works like FOSSBilling's "Custom" gateway (an admin confirms the payment), and adds
 * a `converted` variable to the instruction template (`egp` is the same, for templates written before):
 *   converted.total     invoice total in that currency, rounded up to a whole unit, e.g. "5,075"
 *   converted.currency  the currency code, e.g. "EGP"
 *   converted.rate      units per one unit of the invoice currency, e.g. "50.7432" (converted.factor: the same, unrounded)
 *   converted.updated   date of the rate, e.g. "2026-10-08"
 * `converted` is null when no rate is available, so templates should use {% if converted %}.
 *
 * Rates come from ExchangeRate-API's open endpoint (the same source FOSSBilling's
 * currency module uses), cached until the provider's next daily update. Their terms
 * ask for attribution: keep a "Rates by Exchange Rate API" link in the template.
 *
 * Optional and off by default: a new install has no CustomConvert gateway. Staff add it under
 * Payment gateways only if they take payments in another currency.
 */
use Symfony\Contracts\Cache\ItemInterface;

// FOSSBilling requires adapter files directly, so load the parent adapter here.
require_once __DIR__ . '/Custom.php';

class Payment_Adapter_CustomConvert extends Payment_Adapter_Custom
{
    private const RATES_URL = 'https://open.er-api.com/v6/latest/';

    public function __construct(private $convertConfig)
    {
        parent::__construct($convertConfig);
    }

    public static function getConfig(): array
    {
        $config = parent::getConfig();
        $config['supports_subscriptions'] = false;
        $config['description'] = 'Manual payment instructions with the invoice total converted to another currency at the current rate (ExchangeRate-API, refreshed daily). Use {{ converted.total }} {{ converted.currency }}, {{ converted.rate }} and {{ converted.updated }} inside {% if converted %} ... {% endif %}, with a "Rates by Exchange Rate API" link. HTML is supported.';
        $config['form']['currency'] = ['text', [
            'label' => __trans('Show the total in this currency:'),
            'placeholder' => 'EUR',
            'description' => __trans('A three-letter currency code, such as EUR or EGP. Empty: EUR.'),
        ]];

        return $config;
    }

    public function getHtml(ClientFence\Api\Proxy $api_admin, int $invoice_id, bool $subscription): string
    {
        $invoiceModel = $this->di['db']->load('Invoice', $invoice_id);
        $invoice = $this->di['mod_service']('Invoice')->toApiArray($invoiceModel, true);

        $tpl = $subscription ? ($this->convertConfig['recurrent'] ?? '') : ($this->convertConfig['single'] ?? '');
        if (trim((string) $tpl) === '') {
            $tpl = '"CustomConvert" payment adapter is not fully configured.';
        }

        $converted = $this->convertedAmount((float) $invoice['total'], (string) $invoice['currency']);

        return $this->di['mod_service']('System')->renderAdapterTplString($tpl, [
            'invoice' => $invoice,
            'converted' => $converted,
            'egp' => $converted,
        ]);
    }

    /** The currency the instructions show: the setting, EUR when empty or not a currency code. */
    public function targetCurrency(): string
    {
        $code = strtoupper(trim((string) ($this->convertConfig['currency'] ?? '')));

        return preg_match('/^[A-Z]{3}$/', $code) ? $code : 'EUR';
    }

    /**
     * The invoice total in the gateway's currency, or null when the rate cannot be fetched.
     */
    public function convertedAmount(float $total, string $currency): ?array
    {
        $currency = strtoupper($currency);
        $target = $this->targetCurrency();
        $rates = $this->rates($currency);
        $rate = $currency === $target ? 1.0 : ($rates['rates'][$target] ?? null);
        if (!is_numeric($rate) || (float) $rate <= 0) {
            return null;
        }

        return [
            'total' => number_format(ceil($total * (float) $rate), 0, '.', ','),
            'currency' => $target,
            // Small rates (into a strong currency) keep four significant digits; factor is the exact rate for sums.
            'rate' => number_format((float) $rate, max(4, 3 - (int) floor(log10((float) $rate))), '.', ''),
            'factor' => (float) $rate,
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

        return $this->di['cache']->get('clientfence.rates.' . $base, function (ItemInterface $item) use ($base): array {
            try {
                $data = $this->di['http_client']->request('GET', self::RATES_URL . $base, ['timeout' => 10])->toArray();
            } catch (Throwable $e) {
                error_log('CustomConvert: rate fetch failed: ' . $e->getMessage());
                $item->expiresAfter(15 * 60);

                return [];
            }

            if (($data['result'] ?? '') !== 'success' || !is_array($data['rates'] ?? null)) {
                error_log('CustomConvert: rate provider returned ' . ($data['error-type'] ?? 'no rates'));
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
                'rates' => array_map('floatval', array_filter($data['rates'], 'is_numeric')),
                'time_last_update_unix' => (int) ($data['time_last_update_unix'] ?? time()),
            ];
        });
    }
}
