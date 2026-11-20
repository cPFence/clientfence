<?php

declare(strict_types=1);
/**
 * WebHosting|4U registrar adapter (ClientFence).
 *
 * Copyright 2026 webhosting4u.gr
 * Licensed under the Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0).
 * Changed for ClientFence: built in as a core registrar (the module wrapper and its HTTPS client folded into this file).
 *
 * Sells .gr, .ελ and about 295 other extensions through a WebHosting|4U domain reseller account, over the
 * DomainsReseller HTTPS API on WebHosting|4U's billing system (no EPP: WebHosting|4U talks to the registry). Every call
 * carries the account email and a token for the current UTC hour (headers username, token, timestamp). Test mode uses
 * the test API endpoint when one is set; without one it refuses registrations, transfers and renewals. The Greek
 * registry sells two-year terms only, has no privacy service, and takes optional registrant details from the order
 * form (getDomainFields()).
 */
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

class Registrar_Adapter_Webhosting4u extends Registrar_AdapterAbstract implements Registrar_TldPrices, Registrar_TransferStatus
{
    private const URL = 'https://webhosting4u.gr/customers/modules/addons/DomainsReseller/api/index.php';

    /** The Greek extensions in ASCII: ClientFence stores .ελ as .xn--qxam. */
    private const GREEK_TLDS = ['.gr', '.com.gr', '.net.gr', '.org.gr', '.edu.gr', '.gov.gr', '.xn--qxam'];

    /** Order form field => the registrant declaration name WebHosting|4U reads. */
    private const OWNER_FIELDS = [
        'owner_first_name' => 'Owner First Name',
        'owner_last_name' => 'Owner Last Name',
        'owner_organization' => 'Owner Organization',
        'owner_email' => 'Owner Email',
        'owner_address' => 'Owner Address',
        'owner_city' => 'Owner City',
        'owner_state' => 'Owner State/Province',
        'owner_postal_code' => 'Owner Postal Code',
        'owner_country' => 'Owner Country',
        'owner_phone' => 'Owner Phone',
    ];

    /** WebHosting|4U's refusal for a name that is not (or not yet) in the reseller account. */
    private const NOT_IN_ACCOUNT = 'not been found in reseller account';

    public $config = [
        'username' => null,
        'api_key' => null,
        'api_url' => self::URL,
        'test_api_url' => '',
    ];

    public function __construct($options)
    {
        foreach (['username' => 'API username', 'api_key' => 'API key'] as $key => $label) {
            if (trim((string) ($options[$key] ?? '')) === '') {
                throw new Registrar_Exception('The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing', [':domain_registrar' => 'WebHosting|4U', ':missing' => $label], 3001);
            }
            $this->config[$key] = trim((string) $options[$key]);
        }
        foreach (['api_url' => 'API endpoint', 'test_api_url' => 'Test API endpoint'] as $key => $label) {
            $url = trim((string) ($options[$key] ?? ''));
            if ($url !== '') {
                $this->config[$key] = self::endpointUrl($url, $label);
            }
        }
    }

    public static function getConfig(): array
    {
        return [
            'label' => __trans('Sells .gr, .ελ and the other WebHosting|4U extensions through your WebHosting|4U domain reseller account. You need a reseller account and its API key: no registry accreditation and no EPP details are required, because WebHosting|4U talks to the registry for you. Ask WebHosting|4U to confirm which extensions your account may sell before you put them on sale. The Greek registry sells .gr and .ελ for two years at a time: give those TLDs a minimum of 2 years and the allowed periods 2,4,6,8.'),
            'form' => [
                'username' => ['text', [
                    'label' => __trans('API username'),
                    'description' => __trans('The email address of your WebHosting|4U reseller account.'),
                    'required' => true,
                ]],
                'api_key' => ['password', [
                    'label' => __trans('API key'),
                    'description' => __trans('The API key issued to your reseller account.'),
                    'required' => true,
                    'secret' => true,
                ]],
                'api_url' => ['text', [
                    'label' => __trans('API endpoint'),
                    'description' => __trans('Leave empty unless WebHosting|4U gave you a different address.'),
                    'required' => false,
                ]],
                'test_api_url' => ['text', [
                    'label' => __trans('Test API endpoint'),
                    'description' => __trans('Used only while this registrar is in test mode. Leave it empty and test mode will refuse registrations, transfers and renewals rather than send them to the live registry.'),
                    'required' => false,
                ]],
            ],
        ];
    }

    public static function getSecretFields(): array
    {
        return ['api_key'];
    }

    public static function getDomainFields(string $tld, string $action = 'register'): array
    {
        if (!in_array(self::tld($tld), self::GREEK_TLDS, true)) {
            return [];
        }

        // All optional: left empty, the registry uses the account's contact details.
        return [
            'owner_first_name' => ['label' => __trans('Registrant first name (leave empty to use the contact details)')],
            'owner_last_name' => ['label' => __trans('Registrant last name')],
            'owner_organization' => ['label' => __trans('Organisation or company name')],
            'owner_email' => ['label' => __trans('Registrant email address')],
            'owner_address' => ['label' => __trans('Registrant street address')],
            'owner_city' => ['label' => __trans('Registrant city')],
            'owner_state' => ['label' => __trans('Registrant state or province')],
            'owner_postal_code' => ['label' => __trans('Registrant postal code')],
            'owner_country' => ['label' => __trans('Registrant country'), 'options' => [
                'GR' => __trans('Greece'), 'CY' => __trans('Cyprus'), 'DE' => __trans('Germany'),
                'FR' => __trans('France'), 'GB' => __trans('United Kingdom'), 'IT' => __trans('Italy'),
                'US' => __trans('United States'), 'Other' => __trans('Other'),
            ]],
            'owner_phone' => ['label' => __trans('Registrant phone, as +30.2101234567')],
        ];
    }

    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        $result = $this->lookup($domain);
        // No answer is not "taken": a client would be told a free name is unavailable.
        if (!array_key_exists('isAvailable', $result)) {
            throw new Registrar_Exception(':registrar did not say whether :domain is available.', [':registrar' => 'WebHosting|4U', ':domain' => $domain->getName()]);
        }

        return (bool) $result['isAvailable'];
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        // The lookup does not say whether the name is already in this account; WebHosting|4U refuses that transfer.
        return (bool) ($this->lookup($domain)['isRegistered'] ?? false);
    }

    public function registerDomain(Registrar_Domain $domain): bool
    {
        $this->order('register', $domain, $this->period($domain), $this->orderDetails($domain));

        // A charged registration comes back as 200 without JSON: confirm the account holds the name. Only a clear "not
        // found" fails the order; a failed check does not, as a false failure leaves a paid order that cannot complete.
        try {
            $this->request('GET', $this->path($domain, 'information'));
        } catch (Registrar_Exception $e) {
            if (stripos($e->getMessage(), self::NOT_IN_ACCOUNT) !== false) {
                throw new Registrar_Exception(':registrar accepted the registration of :domain but does not hold it.', [':registrar' => 'WebHosting|4U', ':domain' => $domain->getName()]);
            }
        }

        return true;
    }

    public function transferDomain(Registrar_Domain $domain): bool
    {
        // No two-year rule: ClientFence orders every transfer as one year, and a transfer carries no registry term.
        $this->order('transfer', $domain, $this->period($domain, false), ['eppcode' => (string) $domain->getEpp()] + $this->orderDetails($domain));

        return true;
    }

    public function renewDomain(Registrar_Domain $domain): bool
    {
        $this->order('renew', $domain, $this->period($domain));

        return true;
    }

    public function getDomainDetails(Registrar_Domain $domain): Registrar_Domain
    {
        $info = $this->request('GET', $this->path($domain, 'information'));
        $this->sameDomain($domain, $info['domain'] ?? '');
        // $domain holds ClientFence's stored expiry, which the renewal check would take as the registrar's: no expiry, no answer.
        $expiry = $this->date($info['expiryDate'] ?? null);
        if ($expiry === null) {
            throw new Registrar_Exception(':registrar did not report an expiry date for :domain.', [':registrar' => 'WebHosting|4U', ':domain' => $domain->getName()]);
        }
        $domain->setExpirationTime($expiry);
        // An unknown lock stays null (the core skips it); an unknown privacy is not set, as setPrivacyEnabled() casts to bool.
        if (array_key_exists('transferLock', $info)) {
            $domain->setLocked($this->flag($info['transferLock']));
        }
        $privacy = $this->flag($info['idProtectionStatus'] ?? null);
        if ($privacy !== null) {
            $domain->setPrivacyEnabled($privacy);
        }
        // Only when the reply has them, and scalars only: the field's shape is undocumented.
        if (is_array($info['nameservers'] ?? null)) {
            $hosts = array_values(array_filter(array_map(static fn (mixed $h): string => is_scalar($h) ? trim((string) $h) : '', $info['nameservers'])));
            foreach ([1, 2, 3, 4] as $i) {
                $domain->{'setNs' . $i}($hosts[$i - 1] ?? '');
            }
        }

        return $domain;
    }

    public function modifyNs(Registrar_Domain $domain): bool
    {
        $this->request('POST', $this->path($domain, 'nameservers'), ['domain' => $domain->getName()] + $this->nameservers($domain));

        return true;
    }

    public function modifyContact(Registrar_Domain $domain): bool
    {
        $registrant = $this->registrant($domain);
        $path = $this->path($domain, 'contact');
        $this->request('POST', $path, ['domain' => $domain->getName(), 'contactdetails' => ['Registrant' => $registrant]]);

        // A registrant change can wait at the registry (up to five days for a gTLD email change): a read-back that does
        // not show the new email yet is logged, never thrown, or the change would also be lost in ClientFence.
        $email = strtolower(trim($registrant['email']));
        if ($email === '') {
            return true;
        }
        try {
            // The contact reply's shape is undocumented: look for the email anywhere in it.
            $shown = stripos((string) json_encode($this->request('GET', $path), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $email) !== false;
        } catch (Registrar_Exception) {
            $shown = false;
        }
        if (!$shown) {
            $this->note(sprintf('contact change for %s accepted but not yet visible in the registrant record; it may be pending at the registry', $domain->getName()));
        }

        return true;
    }

    public function getEpp(Registrar_Domain $domain): string
    {
        $result = $this->request('GET', $this->path($domain, 'eppcode'));
        // Not "code": a numeric status code in the reply would be handed out as the transfer code.
        foreach (['eppcode', 'eppCode', 'authcode', 'authCode'] as $key) {
            if (is_scalar($result[$key] ?? null) && trim((string) $result[$key]) !== '') {
                return trim((string) $result[$key]);
            }
        }
        // An empty eppcode is a registry (such as .eu) that does not hand the code out through this call.
        if (array_key_exists('eppcode', $result)) {
            throw new Registrar_Exception(':registrar holds no transfer code for :domain. Request it from WebHosting|4U.', [':registrar' => 'WebHosting|4U', ':domain' => $domain->getName()]);
        }

        throw new Registrar_Exception(':registrar did not return a transfer code for :domain.', [':registrar' => 'WebHosting|4U', ':domain' => $domain->getName()]);
    }

    public function lock(Registrar_Domain $domain): bool
    {
        return $this->setLock($domain, true);
    }

    public function unlock(Registrar_Domain $domain): bool
    {
        return $this->setLock($domain, false);
    }

    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        return $this->setPrivacy($domain, true);
    }

    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        return $this->setPrivacy($domain, false);
    }

    public function deleteDomain(Registrar_Domain $domain): bool
    {
        // No test-mode refusal: the core cancels and deletes domain orders through this call, and refusing it would leave
        // staff unable to remove the order (or the registrar).
        $this->request('POST', $this->path($domain, 'delete'), ['domain' => $domain->getName()]);

        return true;
    }

    /** WebHosting|4U's price per year (its "years" lists the terms a TLD is sold for); 0.00 and -1.00 are "not offered". */
    public function getTldPrices(array $tlds = []): array
    {
        $wanted = array_flip(array_map(static fn (mixed $tld): string => self::tld((string) $tld), $tlds));
        $currency = '';
        $prices = [];
        foreach ($this->request('GET', 'tlds/pricing') as $row) {
            if (!is_array($row) || !isset($row['tld'])) {
                continue;
            }
            $tld = self::tld((string) $row['tld']);
            if ($tld === '.' || ($wanted !== [] && !isset($wanted[$tld]))) {
                continue;
            }
            // One currency prices the whole import: mixed currencies would mis-price it.
            $code = is_string($row['currencyCode'] ?? null) ? strtoupper($row['currencyCode']) : '';
            if ($code !== '') {
                if ($currency !== '' && $currency !== $code) {
                    throw new Registrar_Exception(':registrar returned prices in more than one currency (:a and :b), which cannot be imported as one list.', [':registrar' => 'WebHosting|4U', ':a' => $currency, ':b' => $code]);
                }
                $currency = $code;
            }
            $prices[$tld] = ['register' => $this->price($row['registrationPrice'] ?? null), 'renew' => $this->price($row['renewalPrice'] ?? null), 'transfer' => $this->price($row['transferPrice'] ?? null)];
        }

        // No default currency: an empty one makes the price import say so instead of converting at a guessed rate.
        return ['currency' => $currency, 'prices' => $prices, 'remaining' => []];
    }

    public function getTransferStatus(Registrar_Domain $domain): array
    {
        try {
            $result = $this->request('POST', $this->path($domain, 'transfersync'), ['domain' => $domain->getName()]);
        } catch (Registrar_Exception $e) {
            // A transfer the losing registrar still holds is not in the account yet: that refusal is the answer.
            if (stripos($e->getMessage(), self::NOT_IN_ACCOUNT) !== false) {
                return ['status' => Registrar_TransferStatus::PENDING, 'message' => 'Not yet in the reseller account; awaiting the registry.'];
            }

            throw $e;
        }
        $text = static fn (string $key): string => is_scalar($result[$key] ?? null) ? trim((string) $result[$key]) : '';
        $message = $text('reason') ?: $text('status') ?: $text('message');
        if ($this->flag($result['completed'] ?? null) === true) {
            return ['status' => Registrar_TransferStatus::COMPLETED, 'message' => $message];
        }
        if ($this->flag($result['failed'] ?? null) === true) {
            return ['status' => Registrar_TransferStatus::FAILED, 'message' => $message];
        }
        // Every field in turn, not only the first one present: a prose "reason" can sit beside a status "completed".
        foreach (['status', 'state', 'reason', 'message'] as $key) {
            $word = strtolower($text($key));
            if (in_array($word, ['completed', 'complete', 'done', 'success', 'ok'], true)) {
                return ['status' => Registrar_TransferStatus::COMPLETED, 'message' => $message];
            }
            if (in_array($word, ['failed', 'failure', 'rejected', 'cancelled', 'canceled'], true)) {
                return ['status' => Registrar_TransferStatus::FAILED, 'message' => $message];
            }
        }

        // Anything else is asked again later rather than settling an order that may not have completed.
        return ['status' => Registrar_TransferStatus::PENDING, 'message' => $message];
    }

    /** A registration, transfer or renewal (charged): never sent to the live registry in test mode. */
    private function order(string $action, Registrar_Domain $domain, int $years, array $fields = []): void
    {
        if ($this->_testMode && $this->config['test_api_url'] === '') {
            throw new Registrar_Exception('This registrar is in test mode and no test API endpoint is set, so it does not send registrations, transfers or renewals to the live registry. Turn test mode off, or enter a test API endpoint.');
        }
        $this->request('POST', 'order/domains/' . $action, [
            'domain' => $domain->getName(),
            'regperiod' => $years,
            // The registry needs the script of an internationalised name: .ελ is Greek, the rest are ASCII.
            'idnLanguage' => self::tld((string) $domain->getTld()) === '.xn--qxam' ? 'gr' : '',
            'addons' => ['dnsmanagement' => 0, 'emailforwarding' => 0, 'idprotection' => $domain->getPrivacyEnabled() ? 1 : 0],
        ] + $fields);
    }

    /** What a registration and a transfer send beside the order: registrant declarations, name servers, contact. */
    private function orderDetails(Registrar_Domain $domain): array
    {
        $answers = $domain->getFields();
        $declarations = [];
        foreach (self::OWNER_FIELDS as $field => $name) {
            if (is_scalar($answers[$field] ?? null) && (string) $answers[$field] !== '') {
                $declarations[$name] = (string) $answers[$field];
            }
        }

        // The declarations as WebHosting|4U reads them, base64 of a PHP-serialized array; none: the account's contact applies.
        return ['domainfields' => base64_encode(serialize($declarations)), 'nameservers' => $this->nameservers($domain), 'contacts' => ['registrant' => $this->registrant($domain)]];
    }

    /** The ordered years; the Greek registry sells 2, 4, 6... years only (ClientFence's TLD periods do not enforce it). */
    private function period(Registrar_Domain $domain, bool $twoYearSteps = true): int
    {
        $years = (int) $domain->getRegistrationPeriod();
        if ($years < 1) {
            throw new Registrar_Exception('No registration period was given for :domain.', [':domain' => $domain->getName()]);
        }
        if ($twoYearSteps && $years % 2 !== 0 && in_array(self::tld((string) $domain->getTld()), self::GREEK_TLDS, true)) {
            throw new Registrar_Exception(':tld is sold in two-year terms only, not for the ordered term (in years: :years).', [':tld' => $domain->getTld(), ':years' => $years]);
        }

        return $years;
    }

    /** The lock, then read back: WebHosting|4U answers 200 to a write it ignores, and only a contradiction is a failure. */
    private function setLock(Registrar_Domain $domain, bool $locked): bool
    {
        $path = $this->path($domain, 'lock');
        $this->request('POST', $path, ['domain' => $domain->getName(), 'lockstatus' => $locked ? 1 : 0]);
        $reply = $this->request('GET', $path);
        $state = null;
        foreach (['lockstatus', 'lockStatus', 'locked', 'transferLock'] as $key) {
            if (array_key_exists($key, $reply)) {
                $state = $this->flag($reply[$key]);

                break;
            }
        }
        $vars = [':registrar' => 'WebHosting|4U', ':domain' => $domain->getName()];
        if ($state === !$locked) {
            throw $locked ? new Registrar_Exception(':registrar accepted the lock, but :domain is still unlocked. The change has not been applied.', $vars)
                : new Registrar_Exception(':registrar accepted the unlock, but :domain is still locked. The change has not been applied.', $vars);
        }
        if ($state === null) {
            $this->note(sprintf('lock state of %s could not be confirmed after the change', $domain->getName()));
        }

        return true;
    }

    /** The Greek registry has no privacy service (a client would pay and stay in WHOIS); the other TLDs sell it. */
    private function setPrivacy(Registrar_Domain $domain, bool $enabled): bool
    {
        if (in_array(self::tld((string) $domain->getTld()), self::GREEK_TLDS, true)) {
            throw new Registrar_Exception('The registry of :tld does not offer privacy protection.', [':tld' => $domain->getTld()]);
        }
        $this->request('POST', $this->path($domain, 'protectid'), ['domain' => $domain->getName(), 'status' => $enabled ? 1 : 0]);

        return true;
    }

    /** One name, one result: searchTerm is the SLD alone, as WebHosting|4U appends each of tldsToInclude. */
    private function lookup(Registrar_Domain $domain): array
    {
        $first = $this->request('POST', 'domains/lookup', ['searchTerm' => $domain->getSld(), 'punyCodeSearchTerm' => '', 'tldsToInclude' => [$domain->getTld()], 'isIdnDomain' => '', 'premiumEnabled' => ''])[0] ?? null;
        if (!is_array($first)) {
            throw new Registrar_Exception(':registrar returned no result for :domain.', [':registrar' => 'WebHosting|4U', ':domain' => $domain->getName()]);
        }
        $this->sameDomain($domain, $first['domainName'] ?? '');
        if (($first['isValidDomain'] ?? true) === false) {
            throw new Registrar_Exception(':domain was refused: :error', [':domain' => $domain->getName(), ':error' => (string) ($first['domainErrorMessage'] ?? '') ?: 'invalid domain name']);
        }

        return $first;
    }

    /** A reply about another name would sell a taken name or write a wrong expiry onto a paid service. */
    private function sameDomain(Registrar_Domain $domain, mixed $named): void
    {
        $named = strtolower(trim(is_scalar($named) ? (string) $named : ''));
        if ($named !== '' && $named !== strtolower($domain->getName())) {
            throw new Registrar_Exception(':registrar answered about :other when asked about :domain.', [':registrar' => 'WebHosting|4U', ':other' => $named, ':domain' => $domain->getName()]);
        }
    }

    /**
     * One API call: the decoded reply, or [] for a 2xx without JSON (how WebHosting|4U answers some accepted writes, a
     * charged registration among them). A refusal ("error", or "result" error) or any other HTTP error throws.
     */
    private function request(string $method, string $path, array $fields = []): array
    {
        // The token is for one UTC hour and the clocks may differ: a call refused as unauthorised is signed again for the
        // hours either side, except orders and deletes, which must never be sent twice.
        $offsets = str_starts_with($path, 'order/') || str_ends_with($path, '/delete') ? [0] : [0, -3600, 3600];
        $refusal = '';
        foreach ($offsets as $offset) {
            $time = time();
            // The key is the HMAC message and "email:hour" (two-digit year) its key, as WebHosting|4U computes it.
            $token = base64_encode(hash_hmac('sha256', (string) $this->config['api_key'], $this->config['username'] . ':' . gmdate('y-m-d H', $time + $offset)));
            // No redirects: they would carry the token headers to another host.
            $options = ['headers' => ['username' => $this->config['username'], 'token' => $token, 'timestamp' => (string) $time, 'User-Agent' => 'wh4u-domains-reseller (ClientFence)'], 'timeout' => 60, 'max_redirects' => 0];
            if ($method === 'POST') {
                $options['body'] = $fields;
            }

            try {
                $response = $this->getHttpClient()->request($method, rtrim($this->endpoint(), '/') . '/' . $path, $options);
                $status = $response->getStatusCode();
                $reply = json_decode($response->getContent(false), true);
            } catch (ExceptionInterface $e) {
                // The class only: the message can carry the URL and the headers, which hold the token.
                $this->note(sprintf('transport failure on %s %s: %s', $method, $path, $e::class));

                throw new Registrar_Exception('Could not reach :registrar. Please try again shortly.', [':registrar' => 'WebHosting|4U']);
            }
            if ($status === 401 || $status === 403) {
                $refusal = is_array($reply) ? (string) $this->error($reply) : '';

                continue;
            }
            if (!is_array($reply)) {
                $this->note(sprintf('non-JSON reply from %s %s (HTTP %d)', $method, $path, $status));
                if ($status >= 200 && $status < 300) {
                    return [];
                }

                throw new Registrar_Exception(':registrar returned an unreadable response (HTTP :status).', [':registrar' => 'WebHosting|4U', ':status' => (string) $status]);
            }
            $error = $this->error($reply) ?? ($status >= 300 ? 'HTTP ' . $status : null);
            if ($error !== null) {
                $this->note(sprintf('%s %s refused: %s', $method, $path, $error));

                throw new Registrar_Exception(':registrar refused the request: :error', [':registrar' => 'WebHosting|4U', ':error' => $error]);
            }

            return $reply;
        }

        $this->note(sprintf('%s %s: refused as unauthorised (%s)', $method, $path, $refusal !== '' ? $refusal : 'no reason given'));
        // A stated reason (an IP allowlist, a suspended account) is not a wrong key.
        if ($refusal !== '') {
            throw new Registrar_Exception(':registrar refused the request: :error', [':registrar' => 'WebHosting|4U', ':error' => $refusal]);
        }

        throw new Registrar_Exception(':registrar rejected the credentials. Check the API username and key.', [':registrar' => 'WebHosting|4U']);
    }

    /** The refusal in a reply: "error", or "message" only when "result" says it failed ("message" alone comes with successes). */
    private function error(array $reply): ?string
    {
        if (is_scalar($reply['error'] ?? null) && (string) $reply['error'] !== '') {
            return (string) $reply['error'];
        }
        if (is_scalar($reply['result'] ?? null) && in_array(strtolower(trim((string) $reply['result'])), ['error', 'fail', 'failed'], true)) {
            return is_scalar($reply['message'] ?? null) && (string) $reply['message'] !== '' ? (string) $reply['message'] : 'the request was reported as failed without a reason';
        }

        return null;
    }

    /** The test endpoint in test mode when one is set (without one, order() refuses the charged calls). */
    private function endpoint(): string
    {
        return $this->_testMode && $this->config['test_api_url'] !== '' ? $this->config['test_api_url'] : $this->config['api_url'];
    }

    /** The token replays any call for the rest of its hour: https only (http on a loopback host, for development). */
    private static function endpointUrl(string $url, string $label): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        if ($host === '' || !in_array($scheme, ['http', 'https'], true)) {
            throw new Registrar_Exception('The :label is not a valid URL.', [':label' => $label], 3001);
        }
        if ($scheme === 'http' && !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new Registrar_Exception('The :label must use https: an http endpoint would send the API token and the registrant details in clear.', [':label' => $label], 3001);
        }

        return $url;
    }

    /** An audit line: never a key, a token, a contact or a request body. */
    private function note(string $message): void
    {
        $this->getLog()->info('WebHosting|4U reseller API: ' . $message);
    }

    private function path(Registrar_Domain $domain, string $action): string
    {
        return 'domains/' . rawurlencode($domain->getName()) . '/' . $action;
    }

    /** ns1 to ns4, and an empty ns5 (WebHosting|4U takes five; ClientFence stores four). */
    private function nameservers(Registrar_Domain $domain): array
    {
        return ['ns1' => (string) $domain->getNs1(), 'ns2' => (string) $domain->getNs2(), 'ns3' => (string) $domain->getNs3(), 'ns4' => (string) $domain->getNs4(), 'ns5' => ''];
    }

    private function registrant(Registrar_Domain $domain): array
    {
        $c = $domain->getContactRegistrar();
        if (!$c instanceof Registrar_Domain_Contact) {
            throw new Registrar_Exception('No registrant contact was given for :domain.', [':domain' => $domain->getName()]);
        }

        return [
            'firstname' => (string) $c->getFirstName(),
            'lastname' => (string) $c->getLastName(),
            'companyname' => (string) $c->getCompany(),
            'email' => (string) $c->getEmail(),
            'address1' => (string) $c->getAddress1(),
            'address2' => (string) $c->getAddress2(),
            'city' => (string) $c->getCity(),
            'state' => (string) $c->getState(),
            'postcode' => (string) $c->getZip(),
            'country' => (string) $c->getCountry(),
            'phonenumber' => trim($c->getTelCc() . '.' . $c->getTel(), '.'),
            'tax_id' => (string) $c->getCompanyNumber(),
        ];
    }

    /** Lower case with the leading dot, in ASCII: .ελ arrives as .ελ or as .xn--qxam, and both must compare equal. */
    private static function tld(string $tld): string
    {
        $tld = ltrim(strtolower(trim($tld)), '.');
        if (preg_match('/[^\x00-\x7F]/', $tld)) {
            $tld = idn_to_ascii($tld, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $tld;
        }

        return '.' . $tld;
    }

    private function price(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    /** An ISO date at noon UTC (no day shift in any time zone); "0000-00-00", impossible dates and other formats are null. */
    private function date(mixed $value): ?int
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($value), $m) || (int) $m[1] < 1970 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return gmmktime(12, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]) ?: null;
    }

    /** A yes or no from the API; null when it does not say (an unknown word such as "pending" is not "no"). */
    private function flag(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }
        $word = is_string($value) ? strtolower(trim($value)) : '';

        return match (true) {
            in_array($word, ['true', 'yes', 'on', 'locked', 'enabled', 'active'], true) => true,
            in_array($word, ['false', 'no', 'off', 'unlocked', 'disabled', 'inactive'], true) => false,
            default => null,
        };
    }
}
