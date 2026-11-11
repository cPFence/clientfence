<?php

declare(strict_types=1);
/**
 * INWX registrar adapter (ClientFence).
 *
 * Based on the INWX adapter for FOSSBilling by insxa (https://github.com/insxa/Inwx-Fossbilling, Apache-2.0) and on the
 * parameters of INWX's own WHMCS module (https://github.com/inwx/WHMCS, MIT). Uses the INWX DomRobot JSON-RPC API
 * (https://www.inwx.com/en/help/apidoc): account.login opens a session (the domrobot cookie) for the other calls; the
 * registrar's test mode uses the OT&E system with an OT&E account. Each registration, transfer and contact change creates
 * an INWX contact from the client's details. .us names ask the nexus questions and .eu names the registrant's EU
 * citizenship on the order form (getDomainFields()), sent as extData.
 */
use ClientFence\Mod\Invoice\EuVat;
use Symfony\Component\Intl\Countries;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

class Registrar_Adapter_Inwx extends Registrar_AdapterAbstract implements Registrar_TldPrices
{
    private const LIVE_URL = 'https://api.domrobot.com/jsonrpc/';
    private const OTE_URL = 'https://api.ote.domrobot.com/jsonrpc/';

    public $config = [
        'username' => null,
        'password' => null,
    ];

    private ?string $session = null;

    public function __construct($options)
    {
        if (empty($options['username']) || empty($options['password'])) {
            throw new Registrar_Exception('The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing', [':domain_registrar' => 'INWX', ':missing' => 'username and password'], 3001);
        }

        $this->config['username'] = trim((string) $options['username']);
        $this->config['password'] = (string) $options['password'];
    }

    public static function getConfig(): array
    {
        return [
            'label' => __trans('Manages domains on INWX through its DomRobot API. Use an INWX account (or sub-account) without two-factor sign-in, and keep enough account funds for registrations and renewals. Domains are registered to expire unless ClientFence renews them, which it does when the renewal invoice is paid. With test mode on, enter the username and password of your INWX OT&E test account.'),
            'form' => [
                'username' => ['text', [
                    'label' => __trans('Username'),
                    'description' => __trans('Your INWX username.'),
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
        // INWX takes the nexus category and purpose (no country) for .us, and an optional citizenship for .eu (residents qualify by address).
        return match (strtolower($tld)) {
            '.us' => array_diff_key(self::usNexusFields(), ['nexus_country' => true]),
            '.eu' => ['eu_citizenship' => ['label' => __trans('EU country of citizenship of the registrant'),
                'options' => array_combine(EuVat::COUNTRIES, array_map(fn (string $code) => Countries::getName($code), EuVat::COUNTRIES))]],
            default => [],
        };
    }

    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        $result = $this->check($domain);
        if ((int) ($result['avail'] ?? 0) === 1 && !empty($result['premium'])) {
            throw new Registrar_Exception('Premium domains cannot be registered.');
        }

        return (int) ($result['avail'] ?? 0) === 1;
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        $result = $this->check($domain);
        // A premium name costs more than the TLD's transfer price.
        if (!empty($result['premium'])) {
            throw new Registrar_Exception('Premium domains cannot be transferred.');
        }

        return (int) ($result['avail'] ?? 1) === 0;
    }

    public function modifyNs(Registrar_Domain $domain): bool
    {
        $this->request('domain.update', ['domain' => $domain->getName(), 'ns' => $this->nameservers($domain)]);

        return true;
    }

    public function modifyContact(Registrar_Domain $domain): bool
    {
        $this->request('domain.update', ['domain' => $domain->getName()] + $this->handles($domain));

        return true;
    }

    public function transferDomain(Registrar_Domain $domain): bool
    {
        // The code is optional: .uk transfers have none (the owner changes the IPS tag instead).
        $this->request('domain.transfer', ['domain' => $domain->getName()] + array_filter(['authCode' => trim((string) $domain->getEpp())])
            + ['ns' => $this->nameservers($domain), 'renewalMode' => $this->renewalMode($domain)] + $this->handles($domain) + $this->extData($domain));

        return true;
    }

    public function getDomainDetails(Registrar_Domain $domain): Registrar_Domain
    {
        $info = $this->request('domain.info', ['domain' => $domain->getName(), 'wide' => 1]);

        $details = new Registrar_Domain();
        $details->setSld($domain->getSld());
        $details->setTld($domain->getTld());
        $details->setRegistrationTime($this->time($info['crDate'] ?? null));
        $details->setExpirationTime($this->time($info['exDate'] ?? null) ?? $domain->getExpirationTime());
        $details->setLocked(!empty($info['transferLock']));
        $details->setPrivacyEnabled(!empty($info['extData']['WHOIS-PROTECTION']));
        $details->setEpp($domain->getEpp());
        $i = 1;
        foreach ((array) ($info['ns'] ?? []) as $host) {
            if ($i <= 4 && trim((string) $host) !== '') {
                $details->{'setNs' . $i++}(strtolower(trim((string) $host)));
            }
        }
        // INWX returns contact IDs, not the contacts: keep ClientFence's copy.
        if ($domain->getContactRegistrar() instanceof Registrar_Domain_Contact) {
            foreach (['Registrar', 'Admin', 'Tech', 'Billing'] as $role) {
                $details->{'setContact' . $role}($domain->getContactRegistrar());
            }
        }

        return $details;
    }

    public function getEpp(Registrar_Domain $domain): string
    {
        return (string) ($this->request('domain.info', ['domain' => $domain->getName(), 'wide' => 1])['authCode'] ?? '');
    }

    public function registerDomain(Registrar_Domain $domain): bool
    {
        $this->request('domain.create', [
            'domain' => $domain->getName(),
            'period' => min(10, max(1, (int) $domain->getRegistrationPeriod())) . 'Y',
            'ns' => $this->nameservers($domain),
            'renewalMode' => $this->renewalMode($domain),
        ] + $this->handles($domain) + $this->extData($domain));

        return true;
    }

    public function renewDomain(Registrar_Domain $domain): bool
    {
        // INWX renews only with the registry's current expiry date (as INWX writes it), which also guards against a double renewal.
        $expires = $this->request('domain.info', ['domain' => $domain->getName()])['exDate'] ?? null;
        $date = match (true) {
            is_string($expires) && preg_match('/^\d{4}-\d{2}-\d{2}/', $expires, $m) === 1 => $m[0],
            is_array($expires) && isset($expires['timestamp']) => date('Y-m-d', (int) $expires['timestamp']),
            default => throw new Registrar_Exception('INWX returned an unreadable response for :command', [':command' => 'domain.info']),
        };
        $this->request('domain.renew', ['domain' => $domain->getName(), 'period' => max(1, (int) $domain->getRegistrationPeriod()) . 'Y', 'expiration' => $date]);

        return true;
    }

    public function deleteDomain(Registrar_Domain $domain): bool
    {
        // A cancelled domain is left to expire unrenewed (as WHMCS), never deleted from the registry.
        $this->getLog()->info(sprintf('%s was cancelled; INWX keeps it until it expires.', $domain->getName()));

        return true;
    }

    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->request('domain.update', ['domain' => $domain->getName(), 'extData' => ['WHOIS-PROTECTION' => true]]);

        return true;
    }

    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->request('domain.update', ['domain' => $domain->getName(), 'extData' => ['WHOIS-PROTECTION' => false]]);

        return true;
    }

    public function lock(Registrar_Domain $domain): bool
    {
        $this->request('domain.update', ['domain' => $domain->getName(), 'transferLock' => 1]);

        return true;
    }

    public function unlock(Registrar_Domain $domain): bool
    {
        $this->request('domain.update', ['domain' => $domain->getName(), 'transferLock' => 0]);

        return true;
    }

    /** The account's price per year without VAT (domain.getPrices); promotions are left out, they end. */
    public function getTldPrices(array $tlds = []): array
    {
        $year = static fn (mixed $p): ?float => is_numeric($p) ? (float) $p : null;
        $currency = '';
        $prices = [];
        foreach ((array) ($this->request('domain.getPrices', ['vat' => false])['price'] ?? []) as $row) {
            $tld = strtolower(ltrim((string) ($row['tld'] ?? ''), '.'));
            if ($tld !== '') {
                $prices['.' . $tld] = ['register' => $year($row['createPrice'] ?? null), 'renew' => $year($row['renewalPrice'] ?? null), 'transfer' => $year($row['transferPrice'] ?? null)];
                $currency = $currency !== '' ? $currency : strtoupper((string) ($row['currency'] ?? ''));
            }
        }
        if ($tlds !== []) {
            $prices = array_intersect_key($prices, array_flip($tlds));
        }

        return ['currency' => $currency !== '' ? $currency : 'EUR', 'prices' => $prices, 'remaining' => []];
    }

    /** One API call; returns its resData. Codes 1000 and 1001 (accepted, pending at the registry) are success. */
    private function request(string $method, array $params): array
    {
        if ($this->session === null && $method !== 'account.login') {
            $login = $this->request('account.login', ['user' => $this->config['username'], 'pass' => $this->config['password']]);
            if (!empty($login['tfa'])) {
                throw new Registrar_Exception('The INWX account uses two-factor sign-in: use an account without it for the API.');
            }
        }

        try {
            $response = $this->getHttpClient()->request('POST', $this->_testMode ? self::OTE_URL : self::LIVE_URL, [
                'json' => ['method' => $method, 'params' => $params],
                'headers' => $this->session === null ? [] : ['Cookie' => $this->session],
                'timeout' => 60,
            ]);
            $reply = json_decode($response->getContent(false), true);
            if ($method === 'account.login') {
                // The session is the cookie the login sets (domrobot).
                $this->session = implode('; ', array_map(fn (string $cookie) => (string) strtok($cookie, ';'), $response->getHeaders(false)['set-cookie'] ?? [])) ?: null;
            }
        } catch (ExceptionInterface $e) {
            $this->getLog()->err('INWX ' . $method . ' HTTP error: ' . $e->getMessage());

            throw new Registrar_Exception('Could not reach :registrar: :error', [':registrar' => 'INWX', ':error' => $method]);
        }

        if (!is_array($reply) || !isset($reply['code'])) {
            throw new Registrar_Exception('INWX returned an unreadable response for :command', [':command' => $method]);
        }
        if (!in_array((int) $reply['code'], [1000, 1001], true)) {
            $reason = trim(($reply['msg'] ?? '') . (isset($reply['reason']) ? ': ' . $reply['reason'] : ''));
            $this->getLog()->err(sprintf('INWX %s refused: %s %s', $method, $reply['code'], $reason));

            throw new Registrar_Exception(':registrar refused the request: :error', [':registrar' => 'INWX', ':error' => $reason !== '' ? $reason : (string) $reply['code']]);
        }
        if ($method === 'account.login' && $this->session === null) {
            throw new Registrar_Exception('INWX returned an unreadable response for :command', [':command' => $method]);
        }

        return is_array($reply['resData'] ?? null) ? $reply['resData'] : [];
    }

    private function check(Registrar_Domain $domain): array
    {
        foreach ((array) ($this->request('domain.check', ['domain' => [$domain->getName()]])['domain'] ?? []) as $found) {
            if (strcasecmp((string) ($found['domain'] ?? ''), $domain->getName()) === 0) {
                return $found;
            }
        }

        throw new Registrar_Exception('INWX returned an unreadable response for :command', [':command' => 'domain.check']);
    }

    /** INWX dates: a "timestamp" struct (as XML-RPC clients see them) or a date string. */
    private function time(mixed $value): ?int
    {
        if (is_array($value) && isset($value['timestamp'])) {
            return (int) $value['timestamp'];
        }

        return is_string($value) && $value !== '' ? (strtotime($value) ?: null) : null;
    }

    /** No INWX auto-renewal: ClientFence renews on the paid invoice. .at names (and .co.at, .or.at) must be AUTODELETE. */
    private function renewalMode(Registrar_Domain $domain): string
    {
        return str_ends_with((string) $domain->getTld(), '.at') ? 'AUTODELETE' : 'AUTOEXPIRE';
    }

    private function nameservers(Registrar_Domain $domain): array
    {
        $hosts = array_values(array_filter(array_map(fn ($ns) => trim((string) $ns), [$domain->getNs1(), $domain->getNs2(), $domain->getNs3(), $domain->getNs4()])));
        if (count($hosts) < 2) {
            throw new Registrar_Exception('At least two name servers are required.');
        }

        return $hosts;
    }

    /** The order's .us/.eu answers and the privacy choice, as INWX extData. */
    private function extData(Registrar_Domain $domain): array
    {
        $f = $domain->getFields();
        $ext = array_filter(match ($domain->getTld()) {
            '.us' => ['US-NEXUS-CATEGORY' => $f['nexus_category'] ?? '', 'US-NEXUS-APPPURPOSE' => $f['app_purpose'] ?? ''],
            '.eu' => ['EU-COUNTRY-OF-CITIZENSHIP' => $f['eu_citizenship'] ?? ''],
            default => [],
        }) + ($domain->getPrivacyEnabled() ? ['WHOIS-PROTECTION' => true] : []);

        return $ext === [] ? [] : ['extData' => $ext];
    }

    /** A new INWX contact from the client's contact, used for all four roles. */
    private function handles(Registrar_Domain $domain): array
    {
        $c = $domain->getContactRegistrar();
        if (!$c instanceof Registrar_Domain_Contact) {
            throw new Registrar_Exception('The registrant contact is missing.');
        }
        $cc = (string) preg_replace('/\D+/', '', (string) $c->getTelCc());
        $tel = (string) preg_replace('/\D+/', '', (string) $c->getTel());
        // The international form drops the national trunk 0 (Italian numbers keep it).
        if ($cc !== '39') {
            $tel = ltrim($tel, '0');
        }

        $id = (int) ($this->request('contact.create', array_filter([
            'type' => 'PERSON',
            'name' => trim($c->getFirstName() . ' ' . $c->getLastName()),
            'org' => (string) $c->getCompany(),
            'street' => (string) $c->getAddress1(),
            'street2' => (string) $c->getAddress2(),
            'city' => (string) $c->getCity(),
            'sp' => (string) $c->getState(),
            'pc' => (string) $c->getZip(),
            'cc' => strtoupper((string) $c->getCountry()),
            'email' => (string) $c->getEmail(),
            'voice' => '+' . $cc . '.' . $tel,
        ], fn (string $value) => $value !== '') + ['extData' => ['PARSE-VOICE' => true]])['id'] ?? 0);
        if ($id === 0) {
            throw new Registrar_Exception('INWX did not return a contact ID.');
        }

        return ['registrant' => $id, 'admin' => $id, 'tech' => $id, 'billing' => $id];
    }
}
