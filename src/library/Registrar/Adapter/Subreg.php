<?php

declare(strict_types=1);
/**
 * Subreg.CZ registrar adapter (ClientFence).
 *
 * Based on the Subreg.CZ adapter for FOSSBilling by insxa (https://github.com/insxa/Subreg-Fossbilling).
 * Licensed under the Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0).
 * Changed for ClientFence: built in as a core registrar, every call checked against the Subreg API manual
 * (https://subreg.cz/manual/), fixed endpoints, price list and transfer status.
 *
 * Uses the Subreg SOAP API (non-WSDL, needs the PHP soap extension): Login gives a session token (ssid) that every
 * other call carries; registrations, transfers, renewals and changes are Make_Order orders. Test mode sends every call
 * to the Subreg OT&E system (demoreg.net), never to the live registry.
 */
class Registrar_Adapter_Subreg extends Registrar_AdapterAbstract implements Registrar_TldPrices, Registrar_TransferStatus
{
    private const URL = 'https://soap.subreg.cz/cmd.php';
    private const TEST_URL = 'https://soap.demoreg.net/cmd.php';
    private const LOCK = 'clientTransferProhibited';
    // Subreg lists these WHOIS protection exclusions at https://subreg.cz/en/.
    private const PRIVACY_UNSUPPORTED_TLDS = ['.cz', '.eu'];

    public $config = [
        'login' => null,
        'password' => null,
    ];

    private ?string $ssid = null;

    public function __construct($options)
    {
        foreach (['login' => 'Username', 'password' => 'Password'] as $key => $label) {
            if (trim((string) ($options[$key] ?? '')) === '') {
                throw new Registrar_Exception(':domain_registrar is not fully configured. Please configure the :missing', [':domain_registrar' => 'Subreg', ':missing' => $label], 3001);
            }
            $this->config[$key] = $key === 'password' ? (string) $options[$key] : trim((string) $options[$key]);
        }
    }

    public static function getUnsupportedActions(): array
    {
        return [];
    }

    public static function getConfig(): array
    {
        return [
            'label' => __trans('Registers and manages domains through your Subreg.CZ account over the Subreg API (needs the PHP soap extension). Test mode sends every call to the Subreg OT&E test system (demoreg.net) instead of the live registry.'),
            'form' => [
                'login' => ['text', [
                    'label' => __trans('Username'),
                    'description' => __trans('Your Subreg login. For an account admin, enter admin#username and the admin\'s password; the admin needs API access.'),
                    'required' => true,
                ]],
                'password' => ['password', [
                    'label' => __trans('Password'),
                    'required' => true,
                    'secret' => true,
                ]],
            ],
        ];
    }

    public static function getSecretFields(): array
    {
        return ['password'];
    }

    public static function getDomainFields(string $tld, string $action = 'register'): array
    {
        // Subreg's .us TLD Kit takes the nexus category and purpose (no country).
        return strtolower($tld) === '.us' && $action === 'register' ? array_diff_key(self::usNexusFields(), ['nexus_country' => true]) : [];
    }

    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        $data = $this->check($domain);
        if ((int) $data['avail'] === 1 && trim((string) ($data['existing_claim_id'] ?? '')) !== '') {
            // The manual: a name with a trademark claim cannot be registered through the API.
            throw new Registrar_Exception(':domain has a trademark claim and cannot be registered through :registrar.', [':registrar' => 'Subreg', ':domain' => $domain->getName()]);
        }
        // A premium name costs more than the TLD's price the client pays.
        if ((int) $data['avail'] === 1 && (int) ($data['price']['premium'] ?? 0) === 1) {
            throw new Registrar_Exception('Premium domains cannot be registered.');
        }

        return (int) $data['avail'] === 1;
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        $data = $this->check($domain);
        if ((int) $data['avail'] === 1) {
            return false;
        }
        if ((int) ($data['price_transfer']['premium'] ?? 0) === 1) {
            throw new Registrar_Exception('Premium domains cannot be transferred.');
        }

        // In_Subreg answers "error" for a name that is not at Subreg at all: transferable.
        $owner = $this->inSubreg($domain);
        if ($owner === 'no') {
            // Another Subreg account: only Subreg's internal transfer moves it, which a Transfer_Domain order cannot do.
            throw new Registrar_Exception(':domain is in another :registrar account and needs an internal transfer at :registrar.', [':registrar' => 'Subreg', ':domain' => $domain->getName()]);
        }

        return $owner === null;
    }

    public function registerDomain(Registrar_Domain $domain): bool
    {
        $this->order($domain, 'Create_Domain', [
            'period' => $this->period($domain),
        ] + $this->contacts($domain) + [
            'ns' => ['hosts' => $this->nameservers($domain)],
        ] + $this->tldParams($domain));

        return true;
    }

    public function transferDomain(Registrar_Domain $domain): bool
    {
        $this->order($domain, 'Transfer_Domain', ['authid' => (string) $domain->getEpp(), 'new' => ['registrant' => ['new' => $this->contact($domain, 'registrant')], 'admin' => ['new' => $this->contact($domain, 'admin')]]]);

        return true;
    }

    public function renewDomain(Registrar_Domain $domain): bool
    {
        // curExpDate from the registry: Subreg refuses the renewal when it differs (506/1008), so a retry never renews twice.
        $this->order($domain, 'Renew_Domain', ['period' => $this->period($domain)] + array_filter(['curExpDate' => trim((string) ($this->info($domain)['exDate'] ?? ''))]));

        return true;
    }

    public function getDomainDetails(Registrar_Domain $domain): Registrar_Domain
    {
        $info = $this->info($domain);
        // $domain holds ClientFence's stored expiry, which the renewal check would take as the registrar's: no expiry, no answer.
        $expiry = $this->date($info['exDate'] ?? null);
        if ($expiry === null) {
            throw new Registrar_Exception(':registrar did not report an expiry date for :domain.', [':registrar' => 'Subreg', ':domain' => $domain->getName()]);
        }
        $domain->setExpirationTime($expiry);
        if (($created = $this->date($info['crDate'] ?? null)) !== null) {
            $domain->setRegistrationTime($created);
        }
        if (isset($info['status'])) {
            $domain->setLocked(in_array(self::LOCK, (array) $info['status'], true));
        }
        if (isset($info['whoisproxy'])) {
            $domain->setPrivacyEnabled((int) $info['whoisproxy'] === 1);
        }
        if (isset($info['hosts'])) {
            $hosts = array_values(array_filter(array_map(static fn (mixed $h): string => is_scalar($h) ? trim((string) $h) : '', (array) $info['hosts'])));
            foreach ([1, 2, 3, 4] as $i) {
                $domain->{'setNs' . $i}($hosts[$i - 1] ?? '');
            }
        }

        return $domain;
    }

    public function modifyNs(Registrar_Domain $domain): bool
    {
        $this->order($domain, 'ModifyNS_Domain', ['ns' => ['hosts' => $this->nameservers($domain)]]);

        return true;
    }

    public function modifyContact(Registrar_Domain $domain): bool
    {
        $this->order($domain, 'Modify_Domain', $this->contacts($domain));

        return true;
    }

    public function getEpp(Registrar_Domain $domain): string
    {
        $code = trim((string) ($this->info($domain)['authid'] ?? ''));
        if ($code !== '') {
            return $code;
        }

        // Subreg's WHMCS module provides the undocumented SendAuthID_Domain order and whoisproxy parameter.
        $this->order($domain, 'SendAuthID_Domain', ['params' => ['whmcs' => 1]]);

        return self::EPP_EMAILED;
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
        return $this->setPrivacyProtection($domain, true);
    }

    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        return $this->setPrivacyProtection($domain, false);
    }

    public function deleteDomain(Registrar_Domain $domain): bool
    {
        // Subreg requires a reason.
        $this->order($domain, 'Delete_Domain', ['reason' => 'Order cancelled']);

        return true;
    }

    /** The account's price list (Pricelist): keyed by TLD without the dot, each with its currency. */
    public function getTldPrices(array $tlds = []): array
    {
        $wanted = array_flip(array_map(static fn (mixed $tld): string => '.' . ltrim(strtolower((string) $tld), '.'), $tlds));
        $currency = '';
        $prices = [];
        foreach ((array) ($this->request('Pricelist', [])['data'] ?? []) as $tld => $row) {
            $tld = '.' . ltrim(strtolower((string) $tld), '.');
            if (!is_array($row) || ($wanted !== [] && !isset($wanted[$tld]))) {
                continue;
            }
            // One currency prices the whole import: mixed currencies would mis-price it.
            $code = strtoupper(trim((string) ($row['currency'] ?? '')));
            if ($code !== '' && $currency !== '' && $code !== $currency) {
                throw new Registrar_Exception(':registrar returned prices in more than one currency (:a and :b), which cannot be imported as one list.', [':registrar' => 'Subreg', ':a' => $currency, ':b' => $code]);
            }
            $currency = $code ?: $currency;
            $price = static fn (mixed $v): ?float => is_numeric($v) && (float) $v > 0 ? (float) $v : null;
            // A transfer can be free (.cz); PriceSync keeps a 0.00 transfer and drops a 0 register or renew.
            $transfer = $row['prices']['transfer'] ?? null;
            $prices[$tld] = ['register' => $price($row['prices']['register'] ?? null), 'renew' => $price($row['prices']['renew'] ?? null), 'transfer' => is_numeric($transfer) && (float) $transfer >= 0 ? (float) $transfer : null];
        }

        return ['currency' => $currency, 'prices' => $prices, 'remaining' => []];
    }

    /** Done once the name is in this account; a failed transfer is not reported by name (DomainSync flags a stuck one). */
    public function getTransferStatus(Registrar_Domain $domain): array
    {
        return $this->inSubreg($domain) === 'yes'
            ? ['status' => Registrar_TransferStatus::COMPLETED, 'message' => '']
            : ['status' => Registrar_TransferStatus::PENDING, 'message' => 'Not yet in the Subreg account.'];
    }

    /** The SOAP client; a test replaces it. */
    protected function client(string $location): object
    {
        if (!class_exists('SoapClient')) {
            throw new Registrar_Exception(':registrar needs the PHP soap extension.', [':registrar' => 'Subreg']);
        }

        return new SoapClient(null, ['location' => $location, 'uri' => 'http://' . parse_url($location, PHP_URL_HOST) . '/soap', 'connection_timeout' => 30, 'exceptions' => true]);
    }

    /** One API call with the session token; a status other than "ok" throws unless $check is false. */
    private function request(string $command, array $data, bool $check = true): array
    {
        if ($command !== 'Login') {
            $data = ['ssid' => $this->ssid ??= $this->login()] + $data;
        }

        try {
            $response = $this->client($this->_testMode ? self::TEST_URL : self::URL)->__soapCall($command, ['data' => $data]);
        } catch (SoapFault $e) {
            $this->getLog()->err(sprintf('Subreg %s failed: %s', $command, $e->getMessage()));

            throw new Registrar_Exception('Could not reach :registrar: :error', [':registrar' => 'Subreg', ':error' => $e->getMessage()]);
        }
        if (!is_array($response)) {
            throw new Registrar_Exception(':registrar returned an unreadable response.', [':registrar' => 'Subreg']);
        }
        if ($check && ($response['status'] ?? null) !== 'ok') {
            // The manual puts the error under "error", and in one sample (Info_Domain) under "data".
            $error = $response['error'] ?? $response['data'] ?? [];
            $message = trim((string) (is_array($error) ? ($error['errormsg'] ?? '') : '')) ?: 'unknown error';
            $this->getLog()->err(sprintf('Subreg %s refused: %s', $command, $message));

            throw new Registrar_Exception(':registrar refused the request: :error', [':registrar' => 'Subreg', ':error' => $message]);
        }

        return $response;
    }

    private function login(): string
    {
        $ssid = trim((string) ($this->request('Login', ['login' => $this->config['login'], 'password' => $this->config['password']])['data']['ssid'] ?? ''));
        if ($ssid === '') {
            throw new Registrar_Exception(':registrar refused the request: :error', [':registrar' => 'Subreg', ':error' => 'no session token']);
        }

        return $ssid;
    }

    /** A Make_Order order for $domain. */
    private function order(Registrar_Domain $domain, string $type, array $params): void
    {
        $this->request('Make_Order', ['order' => ['domain' => $domain->getName(), 'type' => $type, 'params' => $params]]);
    }

    /** Check_Domain data; no answer is not "taken", or a client would be told a free name is unavailable. */
    private function check(Registrar_Domain $domain): array
    {
        $data = (array) ($this->request('Check_Domain', ['domain' => $domain->getName()])['data'] ?? []);
        if (!isset($data['avail'])) {
            throw new Registrar_Exception(':registrar did not say whether :domain is available.', [':registrar' => 'Subreg', ':domain' => $domain->getName()]);
        }

        return $data;
    }

    private function info(Registrar_Domain $domain): array
    {
        return (array) ($this->request('Info_Domain', ['domain' => $domain->getName()])['data'] ?? []);
    }

    /** In_Subreg: "yes" in this account, "no" in another Subreg account, null when "error" (not at Subreg). */
    private function inSubreg(Registrar_Domain $domain): ?string
    {
        $reply = $this->request('In_Subreg', ['domain' => $domain->getName()], false);

        return ($reply['status'] ?? null) === 'ok' ? (($reply['data']['myaccount'] ?? null) === 'yes' ? 'yes' : 'no') : null;
    }

    /** The order's .us answers as Create_Domain params (Subreg TLD Kit). */
    private function tldParams(Registrar_Domain $domain): array
    {
        $f = $domain->getFields();
        $params = $domain->getTld() === '.us' ? array_filter(['us-nexus-category' => trim((string) ($f['nexus_category'] ?? '')), 'us-nexus-apppurpose' => trim((string) ($f['app_purpose'] ?? ''))]) : [];

        return $params === [] ? [] : ['params' => $params];
    }

    /** Modify_Domain statuses: the client statuses the domain has, with the transfer lock added or removed. */
    private function setLock(Registrar_Domain $domain, bool $locked): bool
    {
        // Only client* statuses: "ok" and the registry's server* statuses cannot be set by a registrar.
        $statuses = array_values(array_filter(array_map('strval', (array) ($this->info($domain)['status'] ?? [])), static fn (string $s): bool => str_starts_with($s, 'client') && $s !== self::LOCK));
        $this->order($domain, 'Modify_Domain', ['statuses' => $locked ? [...$statuses, self::LOCK] : $statuses]);

        return true;
    }

    private function setPrivacyProtection(Registrar_Domain $domain, bool $enabled): bool
    {
        if (in_array(strtolower($domain->getTld()), self::PRIVACY_UNSUPPORTED_TLDS, true)) {
            throw new Registrar_Exception(':registrar does not offer privacy protection for :tld domains.', [':registrar' => 'Subreg', ':tld' => $domain->getTld()]);
        }

        $this->order($domain, 'Modify_Domain', ['params' => ['whoisproxy' => (int) $enabled, 'whmcs' => 1]]);

        return true;
    }

    private function period(Registrar_Domain $domain): int
    {
        $years = (int) $domain->getRegistrationPeriod();
        if ($years < 1) {
            throw new Registrar_Exception('No registration period was given for :domain.', [':domain' => $domain->getName()]);
        }

        return $years;
    }

    /** Type_Host: one {hostname} per name server. */
    private function nameservers(Registrar_Domain $domain): array
    {
        $hosts = array_filter(array_map(static fn (mixed $ns): string => trim((string) $ns), [$domain->getNs1(), $domain->getNs2(), $domain->getNs3(), $domain->getNs4()]));

        return array_map(static fn (string $host): array => ['hostname' => $host], array_values($hosts));
    }

    /** The registrant and the admin, tech and billing contacts of Create_Domain and Modify_Domain. */
    private function contacts(Registrar_Domain $domain): array
    {
        $new = fn (string $role): array => ['new' => $this->contact($domain, $role)];

        return ['registrant' => $new('registrant'), 'contacts' => ['admin' => $new('admin'), 'tech' => $new('tech'), 'billing' => $new('billing')]];
    }

    /** A role's Type_Contact "new": name, surname, org, street, city, pc, sp, cc, phone (+cc.number), email. */
    private function contact(Registrar_Domain $domain, string $role): array
    {
        if (!$domain->getContactRegistrar() instanceof Registrar_Domain_Contact) {
            throw new Registrar_Exception('No registrant contact was given for :domain.', [':domain' => $domain->getName()]);
        }
        $c = $this->roleContact($domain, $role);

        return array_filter([
            'name' => trim((string) $c->getFirstName()),
            'surname' => trim((string) $c->getLastName()),
            'org' => trim((string) $c->getCompany()),
            'street' => trim(trim((string) $c->getAddress1()) . ', ' . trim((string) $c->getAddress2()), ', '),
            'city' => trim((string) $c->getCity()),
            'pc' => trim((string) $c->getZip()),
            'sp' => trim((string) $c->getState()),
            'cc' => strtoupper(trim((string) $c->getCountry())),
            'phone' => '+' . preg_replace('/\D/', '', (string) $c->getTelCc()) . '.' . preg_replace('/\D/', '', (string) $c->getTel()),
            'email' => trim((string) $c->getEmail()),
        ], static fn (string $v): bool => $v !== '');
    }

    /** A YYYY-MM-DD date at noon UTC (no day shift in any time zone); anything else is null. */
    private function date(mixed $value): ?int
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($value), $m) || (int) $m[1] < 1970 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return gmmktime(12, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]) ?: null;
    }
}
