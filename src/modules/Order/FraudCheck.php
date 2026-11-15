<?php

declare(strict_types=1);

namespace ClientFence\Mod\Order;

use ClientFence\Config;
use ClientFence\InformationException;
use ClientFence\Mod\Clientfence\Notifications;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * ClientFence: the fraud check of new orders (CF-116; System > Settings > Orders > Fraud check, Super Administrators
 * only, `security.fraud_check` in config.php), as WHMCS's MaxMind and FraudLabs Pro modules. Off by default.
 *
 * A client's cart checkout (never a staff order) is scored by MaxMind minFraud Score or FraudLabs Pro before the
 * orders are made (Cart\Service::checkoutCart()). A score above the limit, or the provider's own rules saying review
 * or reject, holds the checkout for staff (Order\Holds, reason `fraud`). The email is sent hashed with its domain,
 * never card data. Clients with an active order are skipped (setting, on by default). A verdict is kept an hour per
 * client, address and cart total, so a double submit pays once. When the provider fails, times out or has no credit,
 * the order goes through and staff hear of it in the bell (an outside outage never stops sales). Off costs one
 * config read.
 */
final class FraudCheck
{
    public const OFF = 'off';
    public const MAXMIND = 'maxmind';
    public const FRAUDLABS = 'fraudlabs';
    public const NAMES = [self::MAXMIND => 'MaxMind', self::FRAUDLABS => 'FraudLabs Pro'];
    /** WHMCS's default for both services. */
    public const LIMIT = 20.0;

    private const URLS = [
        self::MAXMIND => 'https://minfraud.maxmind.com/minfraud/v2.0/score',
        self::FRAUDLABS => 'https://api.fraudlabspro.com/v2/order/screen',
    ];
    private const TIMEOUT = 5;
    private const CACHE_SECONDS = 3600;

    /** @return array{provider: string, account_id: string, key: string, limit: float, skip_active: bool} the key encrypted */
    public static function settings(): array
    {
        $saved = (array) Config::getProperty('security.fraud_check', []);
        $provider = (string) ($saved['provider'] ?? '');

        return [
            'provider' => isset(self::NAMES[$provider]) ? $provider : self::OFF,
            'account_id' => (string) ($saved['account_id'] ?? ''),
            'key' => (string) ($saved['key'] ?? ''),
            'limit' => (float) ($saved['limit'] ?? self::LIMIT),
            'skip_active' => (bool) ($saved['skip_active'] ?? true),
        ];
    }

    /** The settings for the page: never the key, only whether one is saved. */
    public static function forPage(): array
    {
        $settings = self::settings();

        return ['has_key' => $settings['key'] !== ''] + array_diff_key($settings, ['key' => true]);
    }

    /**
     * Saves the settings into config.php: provider, account_id (MaxMind), key (empty keeps the saved one of the same
     * provider), limit, skip_active. With $test it checks the typed details with one sample call instead and
     * answers what the service said; nothing is saved.
     */
    public static function save(\Pimple\Container $di, array $data, bool $test = false): ?string
    {
        $old = self::settings();
        $provider = (string) ($data['provider'] ?? self::OFF);
        if ($provider !== self::OFF && !isset(self::NAMES[$provider])) {
            throw new InformationException('Choose the fraud check service.');
        }
        $limit = filter_var($data['limit'] ?? '', FILTER_VALIDATE_FLOAT);
        if ($limit === false || $limit <= 0 || $limit >= 100) {
            throw new InformationException('Enter a score limit above 0 and below 100, such as 20.');
        }
        $accountId = trim((string) ($data['account_id'] ?? ''));
        if ($provider === self::MAXMIND && preg_match('/\A\d{1,10}\z/', $accountId) !== 1) {
            throw new InformationException('Enter the MaxMind account ID (a number).');
        }
        $key = trim((string) ($data['key'] ?? ''));
        if ($key !== '' && preg_match('/\A[\x21-\x7e]{6,200}\z/', $key) !== 1) {
            throw new InformationException('The licence key or API key is not valid.');
        }
        // A saved key belongs to its service; Off forgets it.
        $stored = $key !== '' ? (string) $di['crypt']->encrypt($key) : ($old['provider'] === $provider ? $old['key'] : '');
        if ($provider !== self::OFF && $stored === '') {
            throw new InformationException('Enter the licence key or API key.');
        }
        $settings = [
            'provider' => $provider,
            'account_id' => $provider === self::MAXMIND ? $accountId : '',
            'key' => $provider === self::OFF ? '' : $stored,
            'limit' => round((float) $limit, 2),
            'skip_active' => !empty($data['skip_active']),
        ];
        if ($test) {
            if ($provider === self::OFF) {
                throw new InformationException('Choose the fraud check service to test.');
            }
            // Sample data, as the services' own examples: nothing is held or kept.
            $sample = ['id' => 0, 'email' => 'test@example.com', 'first_name' => 'Test', 'last_name' => 'Client', 'country' => 'US'];
            try {
                $verdict = self::screen($di, $settings, $sample, ['ip' => '81.2.69.160', 'user_agent' => '', 'accept_language' => ''], 10.0, 'USD');
            } catch (\Throwable $e) {
                throw new InformationException('The service did not answer: :error', [':error' => self::error($e)]);
            }

            return __trans('The service answered: score :score, credit left: :credit.', [':score' => $verdict['score'], ':credit' => $verdict['credit']]);
        }
        $config = Config::getConfig();
        $config['security']['fraud_check'] = $settings;
        // Read from config.php on every request: no cache to clear.
        Config::setConfig($config, false);
        $di['logger']->info('Fraud check settings saved (%s)', $provider);

        return null;
    }

    /**
     * Checkout: why the orders wait for staff (Order\Holds), or null. `$cart` gives [total, currency] only when the
     * check runs.
     *
     * @param \Closure(): array{0: float, 1: string} $cart
     *
     * @return array{reason: string, note: string}|null
     */
    public static function hold(\Pimple\Container $di, int $clientId, string $ip, \Closure $cart): ?array
    {
        $settings = self::settings();
        if ($settings['provider'] === self::OFF) {
            return null;
        }
        $db = $di['dbal'];
        if ($settings['skip_active'] && $db->fetchOne("SELECT 1 FROM client_order WHERE client_id = ? AND status = 'active' LIMIT 1", [$clientId]) !== false) {
            return null;
        }
        [$total, $currency] = $cart();
        $client = $db->fetchAssociative('SELECT id, email, first_name, last_name, company, address_1, address_2, city, state, postcode, country, phone_cc, phone FROM client WHERE id = ?', [$clientId]) ?: ['id' => $clientId];
        $headers = $di['request']->headers;
        $device = ['ip' => $ip, 'user_agent' => (string) $headers->get('User-Agent', ''), 'accept_language' => (string) $headers->get('Accept-Language', '')];
        $key = 'fraud_check_' . hash('sha256', implode('|', [$settings['provider'], $clientId, $ip, number_format($total, 2, '.', ''), $currency]));
        try {
            // A failure throws out of the callback, so it is not kept: the next checkout asks again.
            $verdict = $di['cache']->get($key, static function (ItemInterface $item) use ($di, $settings, $client, $device, $total, $currency): array {
                $item->expiresAfter(self::CACHE_SECONDS);

                return self::screen($di, $settings, $client, $device, $total, $currency);
            });
        } catch (\Throwable $e) {
            $error = self::error($e);
            $name = self::NAMES[$settings['provider']];
            $di['logger']->warning('Fraud check (%s) could not run, the order goes through unchecked: %s', $name, $error);
            Notifications::staff($di, 'fraud_check_failed', ['provider' => $name, 'error' => $error], 'extension/settings/order#tab-fraud-check', 'order', null, 'fraud_check_failed:' . date('Y-m-d') . ':' . md5($error));

            return null;
        }
        if ($verdict['score'] <= $settings['limit'] && !$verdict['review']) {
            return null;
        }
        // Staff read it in Orders > Waiting for review: "MaxMind 45.2 / 20", then the provider's status and rules.
        $note = self::NAMES[$settings['provider']] . ' ' . $verdict['score'] . ' / ' . $settings['limit'] . ($verdict['reasons'] !== '' ? ': ' . $verdict['reasons'] : '');

        return ['reason' => Holds::REASON_FRAUD, 'note' => $note];
    }

    /**
     * One call to the provider: the score, whether its own rules ask for review, its reasons and the credit left.
     * Throws with the provider's error code when it refuses, fails or has no credit.
     *
     * @return array{score: float, review: bool, reasons: string, credit: string}
     */
    public static function screen(\Pimple\Container $di, array $settings, array $client, array $device, float $total, string $currency): array
    {
        $secret = (string) $di['crypt']->decrypt($settings['key']);
        $provider = $settings['provider'];
        $options = ['timeout' => self::TIMEOUT, 'max_duration' => self::TIMEOUT, 'max_redirects' => 0];
        $options += $provider === self::MAXMIND
            ? ['auth_basic' => [$settings['account_id'], $secret], 'json' => self::maxmindBody($client, $device, $total, $currency)]
            : ['body' => ['key' => $secret] + self::fraudlabsBody($client, $device, $total, $currency)];
        $response = $di['http_client']->request('POST', self::URLS[$provider], $options);
        $status = $response->getStatusCode();
        $data = json_decode($response->getContent(false), true);
        $data = is_array($data) ? $data : [];
        if ($provider === self::MAXMIND) {
            // Errors answer {code, error}: INSUFFICIENT_FUNDS, AUTHORIZATION_INVALID, LICENSE_KEY_REQUIRED...
            if ($status !== 200 || !isset($data['risk_score'])) {
                throw new \RuntimeException((string) ($data['code'] ?? 'HTTP ' . $status));
            }

            return [
                'score' => (float) $data['risk_score'],
                'review' => in_array($data['disposition']['action'] ?? null, ['reject', 'manual_review'], true),
                'reasons' => (string) ($data['disposition']['reason'] ?? ''),
                'credit' => 'USD ' . ($data['funds_remaining'] ?? '?'),
            ];
        }
        // FraudLabs Pro errors: {error: {error_code, error_message}}, e.g. 10007 INSUFFICIENT CREDIT.
        $error = $data['error'] ?? null;
        if ($status !== 200 || is_array($error) || !isset($data['fraudlabspro_score'])) {
            throw new \RuntimeException(is_array($error) ? trim(($error['error_code'] ?? '') . ' ' . ($error['error_message'] ?? '')) : 'HTTP ' . $status);
        }
        $flpStatus = (string) ($data['fraudlabspro_status'] ?? '');
        $rules = $data['fraudlabspro_rules'] ?? '';

        return [
            'score' => (float) $data['fraudlabspro_score'],
            'review' => in_array($flpStatus, ['REVIEW', 'REJECT'], true),
            'reasons' => trim($flpStatus . ' ' . (is_array($rules) ? implode(', ', $rules) : (string) $rules)),
            'credit' => (string) ($data['remaining_credits'] ?? '?'),
        ];
    }

    /** The minFraud request (minfraud/v2.0/score): the email as MD5 with its domain, no card data. */
    public static function maxmindBody(array $client, array $device, float $total, string $currency): array
    {
        [$email, $domain] = self::email($client);
        $hash = $email === '' ? '' : md5($email);
        $country = strtoupper((string) ($client['country'] ?? ''));
        // MaxMind takes only an ISO 3166-2 subdivision code ("NY" or "US-NY"); the state is free text.
        // shortcut: the code's form is checked, not that the subdivision exists; a bad code only gives a warning.
        $region = preg_match('/\A(?:' . preg_quote($country, '/') . '-)?([A-Z0-9]{1,3})\z/', trim((string) ($client['state'] ?? '')), $m) === 1 ? $m[1] : '';

        return [
            'device' => self::filled(['ip_address' => $device['ip'], 'user_agent' => $device['user_agent'], 'accept_language' => $device['accept_language']]),
            'event' => ['type' => 'purchase'],
            'account' => ['user_id' => (string) $client['id']],
            'email' => self::filled(['address' => $hash, 'domain' => $domain]),
            'billing' => self::filled([
                'first_name' => $client['first_name'] ?? '',
                'last_name' => $client['last_name'] ?? '',
                'company' => $client['company'] ?? '',
                'address' => $client['address_1'] ?? '',
                'address_2' => $client['address_2'] ?? '',
                'city' => $client['city'] ?? '',
                'region' => $region,
                'postal' => $client['postcode'] ?? '',
                'country' => strlen($country) === 2 ? $country : '',
                'phone_number' => $client['phone'] ?? '',
                'phone_country_code' => substr((string) preg_replace('/\D/', '', (string) ($client['phone_cc'] ?? '')), 0, 4),
            ]),
            'order' => ['amount' => round($total, 2), 'currency' => $currency],
        ];
    }

    /** The FraudLabs Pro request (v2/order/screen, without the key): the email hashed as its SDK does, with its domain. */
    public static function fraudlabsBody(array $client, array $device, float $total, string $currency): array
    {
        [$email, $domain] = self::email($client);
        // FraudLabs Pro's own email hash (fraudlabspro-php Order::doHash): 65536 rounds of SHA-1 with its prefix.
        $hash = $email === '' ? '' : 'fraudlabspro_' . $email;
        for ($i = 0; $email !== '' && $i < 65536; ++$i) {
            $hash = sha1('fraudlabspro_' . $hash);
        }

        return ['format' => 'json'] + self::filled([
            'ip' => $device['ip'],
            'first_name' => $client['first_name'] ?? '',
            'last_name' => $client['last_name'] ?? '',
            'email_hash' => $hash,
            'email_domain' => $domain,
            'user_phone' => (string) preg_replace('/\D/', '', ($client['phone_cc'] ?? '') . ($client['phone'] ?? '')),
            'bill_addr' => trim(($client['address_1'] ?? '') . ' ' . ($client['address_2'] ?? '')),
            'bill_city' => $client['city'] ?? '',
            'bill_state' => $client['state'] ?? '',
            'bill_country' => strtoupper((string) ($client['country'] ?? '')),
            'bill_zip_code' => $client['postcode'] ?? '',
            'amount' => number_format($total, 2, '.', ''),
            'currency' => $currency,
        ]);
    }

    /** @return array{0: string, 1: string} the address (lower case) and its domain ('' without an email) */
    private static function email(array $client): array
    {
        $email = strtolower(trim((string) ($client['email'] ?? '')));
        if (!str_contains($email, '@')) {
            return ['', ''];
        }

        return [$email, substr($email, strrpos($email, '@') + 1)];
    }

    /** Leaves out the empty values: the services refuse some empty fields. */
    private static function filled(array $values): array
    {
        return array_filter(array_map(static fn ($v): string => trim((string) $v), $values), static fn (string $v): bool => $v !== '');
    }

    /** A short error for the bell and the log (the key is never in the address, so never in it). */
    private static function error(\Throwable $e): string
    {
        return mb_substr(trim($e->getMessage()) ?: $e::class, 0, 120);
    }
}
