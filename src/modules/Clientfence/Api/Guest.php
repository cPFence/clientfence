<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Api;

use ClientFence\Mod\Client\Google;
use ClientFence\Mod\Clientfence\Branding;
use ClientFence\Mod\Clientfence\Service;

class Guest extends \ClientFence\Api\AbstractApi
{
    /**
     * The brand for the themes (Branding.php): the sign-in page style and the brand stylesheet
     * (null with the default color). Public: colors only.
     */
    public function brand(): array
    {
        $brand = Branding::get($this->getDi());

        return ['login_style' => $brand['login_style'], 'css_url' => $brand['css_url']];
    }

    /**
     * Whether the store is open to the visitor or signed-in client (the theme shows its link).
     */
    public function store_open(): bool
    {
        return Service::storeOpenTo($this->getDi(), (int) ($this->getDi()['session']->get('client_id') ?? 0));
    }

    /**
     * What the store page shows above the products, whether this session is a preview, and
     * whether a new customer can sign up from the checkout page.
     */
    public function store_info(): array
    {
        $di = $this->getDi();
        $config = Service::config($di);

        return [
            'title' => (string) $config['store_title'],
            'subtitle' => (string) $config['store_subtitle'],
            'cycle' => (string) $config['store_cycle'],
            'preview' => Service::previewActive($di),
            'signup' => Service::registrationOpen($di),
        ];
    }

    /**
     * The sign-up page and the sign-in page's "Create an account" link: whether a visitor may
     * sign up now (Service::registrationOpen()), and the Terms and Conditions and Privacy Policy
     * addresses of the Google sign-in settings the sign-up notice links to (empty unless both are set).
     */
    public function signup_info(): array
    {
        $di = $this->getDi();
        $config = Google::config($di);
        $terms = (string) ($config['google_terms_url'] ?? '');
        $privacy = (string) ($config['google_privacy_url'] ?? '');
        $pages = $terms !== '' && $privacy !== '';

        return [
            'open' => Service::registrationOpen($di),
            'terms_url' => $pages ? $terms : '',
            'privacy_url' => $pages ? $privacy : '',
        ];
    }

    /** Whether invoices get tax (the checkout tells guests tax may be added). */
    public function tax_enabled(): bool
    {
        return (bool) $this->getDi()['mod_service']('system')->getParamValue('tax_enabled', false);
    }

    /** Whether EU VAT numbers are checked (sign-up forms then ask for one, Invoice/EuVat.php). */
    public function eu_vat(): bool
    {
        return \ClientFence\Mod\Invoice\EuVat::settings($this->getDi())['check'];
    }

    /**
     * The smallest and largest wallet top-up (FOSSBilling's funds_min_amount and funds_max_amount,
     * in `currency` when given; null when not set).
     */
    public function wallet_limits($data = []): array
    {
        $currency = is_array($data) && is_string($data['currency'] ?? null) ? strtoupper($data['currency']) : null;

        return $this->getDi()['mod_service']('invoice')->fundsLimits($currency);
    }

    /**
     * Opens the store preview for this browser session when the token is the current one
     * (the theme calls it for /order?preview=...).
     */
    public function store_preview($data): bool
    {
        $token = $data['token'] ?? '';

        return is_string($token) && preg_match('/^[a-f0-9]{32}$/', $token) === 1
            && Service::enterPreview($this->getDi(), $token);
    }
}
