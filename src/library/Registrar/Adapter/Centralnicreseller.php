<?php

declare(strict_types=1);
/**
 * CentralNic Reseller registrar adapter (ClientFence), for RRPproxy accounts and the former HEXONET accounts.
 *
 * Based on the CentralNic Reseller module for Blesta (https://github.com/centralnicgroup-opensource/rtldev-middleware-blesta
 * v6.1.0, Copyright 2018-2026 HEXONET, MIT), reworked for ClientFence. Calls the API directly
 * (https://kb.centralnicreseller.com/api/api-commands): POST https://api.rrpproxy.net/api/call.cgi with s_login, s_pw and
 * s_command ("KEY=value" lines); the registrar's test mode uses the OT&E system (api-ote.rrpproxy.net). The contact goes
 * inline with each domain command, as the Blesta module sends it. .us, .ca and .eu names ask for the registry's details
 * on the order form (getDomainFields()).
 */
use ClientFence\Mod\Invoice\EuVat;
use Symfony\Component\Intl\Countries;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

class Registrar_Adapter_Centralnicreseller extends Registrar_AdapterAbstract implements Registrar_TldPrices
{
    private const LIVE_URL = 'https://api.rrpproxy.net/api/call.cgi';
    private const OTE_URL = 'https://api-ote.rrpproxy.net/api/call.cgi';

    public $config = [
        'username' => null,
        'password' => null,
    ];

    public function __construct($options)
    {
        if (empty($options['username']) || empty($options['password'])) {
            throw new Registrar_Exception('The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing', [':domain_registrar' => 'CentralNic Reseller', ':missing' => 'username and password'], 3001);
        }

        $this->config['username'] = trim((string) $options['username']);
        $this->config['password'] = (string) $options['password'];
    }

    public static function getConfig(): array
    {
        return [
            'label' => __trans('Manages domains on CentralNic Reseller (RRPproxy, and the former HEXONET accounts) through its API. If your account limits API access by IP address, allow this server\'s IP address, and keep enough account funds for registrations and renewals. New registrations are set to expire unless renewed (renewal mode AUTOEXPIRE): ClientFence renews a domain when its renewal invoice is paid. Set your account\'s default renewal mode to AUTOEXPIRE as well, for transferred domains. With test mode on, ClientFence uses the OT&E test system: enter your OT&E password.'),
            'form' => [
                'username' => ['text', [
                    'label' => __trans('Username'),
                    'description' => __trans('Your CentralNic Reseller login.'),
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
        $tld = strtolower($tld);
        // The registry takes the citizenship with registrations and transfers alike; the .us and .ca details with registrations only.
        if ($tld === '.eu') {
            return ['eu_citizenship' => ['label' => __trans('EU country of citizenship of the registrant (only for EU citizens living outside the EU)'),
                'options' => array_combine($eu = [...EuVat::COUNTRIES, 'IS', 'LI', 'NO'], array_map(fn (string $code) => Countries::getName($code), $eu))]];
        }
        if ($action !== 'register') {
            return [];
        }

        return match ($tld) {
            '.us' => self::usNexusFields(),
            '.ca' => ['ca_legal_type' => ['label' => __trans('Canadian presence of the registrant (CIRA legal type)'), 'required' => true, 'options' => [
                'CCT' => __trans('Canadian citizen'), 'RES' => __trans('Permanent resident of Canada'),
                'CCO' => __trans('Corporation registered in Canada'), 'PRT' => __trans('Partnership registered in Canada'),
                'TRS' => __trans('Trust established in Canada'), 'ASS' => __trans('Canadian unincorporated association'),
                'TRD' => __trans('Canadian trade union'), 'PLT' => __trans('Canadian political party'),
                'EDU' => __trans('Canadian educational institution'), 'HOP' => __trans('Canadian hospital'),
                'LAM' => __trans('Canadian library, archive or museum'), 'GOV' => __trans('Government or government entity in Canada'),
                'MAJ' => __trans('His Majesty the King'), 'ABO' => __trans('Aboriginal peoples indigenous to Canada'),
                'INB' => __trans('Indian band recognised by the Indian Act of Canada'), 'LGR' => __trans('Legal representative of a Canadian citizen or permanent resident'),
                'TDM' => __trans('Owner of a trademark registered in Canada'), 'OMK' => __trans('Owner of an official mark registered in Canada'),
            ]]],
            default => [],
        };
    }

    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        $p = $this->fees($domain, 'create');
        $check = (string) ($p['DOMAINCHECK'][0] ?? '');
        $code = substr($check, 0, 3);
        // A premium name shows as available; only the fee extension (or the premium channel) tells it apart.
        if (in_array($code, ['210', '211'], true) && $this->premium($p)) {
            throw new Registrar_Exception('Premium domains cannot be registered.');
        }
        if ($code === '549') {
            throw new Registrar_Exception(':registrar refused the request: :error', [':registrar' => 'CentralNic Reseller', ':error' => $check]);
        }

        return $code === '210';
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        $auth = trim((string) $domain->getEpp());
        $zone = $this->zone($domain);
        // As Blesta: the pre-check only where the zone checks the transfer code; elsewhere (.co.uk) a registered name is transferable.
        $precheck = $auth !== '' && (strtolower($zone['AUTHCODE'][0] ?? '') === 'required' || ($zone['FOAEMAIL'][0] ?? '') === '1');
        if ($precheck) {
            $r = $this->request(['COMMAND' => 'CheckDomainTransfer', 'DOMAIN' => $domain->getName(), 'AUTH' => $auth], ['200', '218', '219']);
            if ($r['CODE'] === '219') {
                return false;
            }
            if (($r['PROPERTY']['AUTHISVALID'][0] ?? '') === 'NO') {
                throw new Registrar_Exception('The transfer code of :domain is not valid.', [':domain' => $domain->getName()]);
            }
            if (($r['PROPERTY']['TRANSFERLOCK'][0] ?? '') === '1') {
                throw new Registrar_Exception(':domain has a transfer lock: unlock it at the current registrar first.', [':domain' => $domain->getName()]);
            }
        }
        $p = $this->fees($domain, 'transfer');
        if (!$precheck && !str_starts_with((string) ($p['DOMAINCHECK'][0] ?? ''), '211')) {
            return false;
        }
        // A premium name costs more than the TLD's transfer price.
        if ($this->premium($p)) {
            throw new Registrar_Exception('Premium domains cannot be transferred.');
        }

        return true;
    }

    public function modifyNs(Registrar_Domain $domain): bool
    {
        $this->request(['COMMAND' => 'ModifyDomain', 'DOMAIN' => $domain->getName()] + $this->nameservers($domain));

        return true;
    }

    public function modifyContact(Registrar_Domain $domain): bool
    {
        $this->request(['COMMAND' => 'ModifyDomain', 'DOMAIN' => $domain->getName()] + $this->contacts($domain, ['OWNERCONTACT0', 'ADMINCONTACT0', 'TECHCONTACT0', 'BILLINGCONTACT0']) + $this->extensions($domain));

        return true;
    }

    public function transferDomain(Registrar_Domain $domain): bool
    {
        $label = strtolower(substr((string) strrchr($domain->getName(), '.'), 1));
        // Blesta's rules: gTLDs and these registries take the contacts from the losing registrar, .fr no owner or billing contact.
        $roles = match (true) {
            strlen($label) >= 3, in_array($label, ['nu', 'dk', 'ca', 'us', 'pt', 'no', 'se', 'es'], true) => [],
            $label === 'fr' => ['ADMINCONTACT0', 'TECHCONTACT0'],
            default => ['OWNERCONTACT0', 'ADMINCONTACT0', 'TECHCONTACT0', 'BILLINGCONTACT0'],
        };
        // FORCEREQUEST lets the API pick the transfer type: from another registrar, or between CentralNic Reseller accounts.
        $this->request(['COMMAND' => 'TransferDomain', 'DOMAIN' => $domain->getName(), 'ACTION' => 'REQUEST', 'FORCEREQUEST' => 1]
            + array_filter(['AUTH' => trim((string) $domain->getEpp())]) + $this->transferPeriod($domain)
            + $this->nameservers($domain, false) + $this->contacts($domain, $roles) + $this->extensions($domain));

        return true;
    }

    public function getDomainDetails(Registrar_Domain $domain): Registrar_Domain
    {
        $p = $this->request(['COMMAND' => 'StatusDomain', 'DOMAIN' => $domain->getName()])['PROPERTY'];
        $time = static fn (string $key): ?int => trim((string) ($p[$key][0] ?? '')) !== '' ? (strtotime($p[$key][0] . ' UTC') ?: null) : null;

        $details = new Registrar_Domain();
        $details->setSld($domain->getSld());
        $details->setTld($domain->getTld());
        $details->setRegistrationTime($time('CREATEDDATE'));
        // As Blesta: the paid-until date, or the renewal date when that comes first (the registrar acts on it).
        $expiry = array_filter([$time('PAIDUNTILDATE') ?? $time('REGISTRATIONEXPIRATIONDATE'), $time('RENEWALDATE')]);
        $details->setExpirationTime($expiry !== [] ? min($expiry) : $domain->getExpirationTime());
        $details->setLocked(($p['TRANSFERLOCK'][0] ?? '') === '1');
        $details->setPrivacyEnabled(($p['X-WHOIS-PRIVACY'][0] ?? '') === '1');
        $details->setEpp($domain->getEpp());
        foreach (array_slice($p['NAMESERVER'] ?? [], 0, 4) as $i => $host) {
            // A glue entry reads "ns1.example.com 192.0.2.1".
            $details->{'setNs' . ($i + 1)}(strtolower(strtok(trim($host), ' ') ?: ''));
        }
        // StatusDomain returns contact handles, not contacts: keep ClientFence's copy.
        if ($domain->getContactRegistrar() instanceof Registrar_Domain_Contact) {
            foreach (['Registrar', 'Admin', 'Tech', 'Billing'] as $role) {
                $details->{'setContact' . $role}($domain->getContactRegistrar());
            }
        }

        return $details;
    }

    public function getEpp(Registrar_Domain $domain): string
    {
        $name = $domain->getName();
        $status = ['COMMAND' => 'StatusDomain', 'DOMAIN' => $name];
        if (preg_match('/\.(de|be|no|eu)$/i', $name)) {
            // These registries' codes expire: a new one is set on request.
            $r = $this->request(['COMMAND' => 'SetAuthCode', 'DOMAIN' => $name]);
        } elseif (preg_match('/\.cn$/i', $name)) {
            $this->request(['COMMAND' => 'ModifyDomain', 'DOMAIN' => $name, 'AUTH' => 'AUTO']);
            $r = $this->request($status);
        } else {
            $r = $this->request($status);
        }

        if (!isset($r['PROPERTY']['AUTH'][0])) {
            return __trans('CentralNic Reseller has emailed the transfer code to the domain\'s registrant.');
        }
        if ($r['PROPERTY']['AUTH'][0] === '') {
            throw new Registrar_Exception('No transfer code is assigned to :domain: contact CentralNic Reseller support.', [':domain' => $name]);
        }

        return $r['PROPERTY']['AUTH'][0];
    }

    public function registerDomain(Registrar_Domain $domain): bool
    {
        $this->request(['COMMAND' => 'AddDomain', 'DOMAIN' => $domain->getName(), 'PERIOD' => min(10, max(1, (int) $domain->getRegistrationPeriod()))]
            + $this->nameservers($domain) + $this->contacts($domain, ['OWNERCONTACT0', 'ADMINCONTACT0', 'TECHCONTACT0', 'BILLINGCONTACT0']) + $this->extensions($domain));

        // The registration is done: a failed follow-up is logged, never thrown (the order must not fail and re-register).
        try {
            $this->renewalMode($domain, 'AUTOEXPIRE');
        } catch (Registrar_Exception $e) {
            $this->getLog()->err(sprintf('CentralNic Reseller did not set %s to expire unless renewed, set its renewal mode by hand: %s', $domain->getName(), $e->getMessage()));
        }

        return true;
    }

    public function renewDomain(Registrar_Domain $domain): bool
    {
        $years = max(1, (int) $domain->getRegistrationPeriod());
        // Zones without an explicit renewal command renew one year at expiry through the renewal mode.
        if (($this->zone($domain)['RRPSUPPORTSRENEWAL'][0] ?? '1') === '0') {
            if ($years > 1) {
                throw new Registrar_Exception(':domain can only be renewed one year at a time.', [':domain' => $domain->getName()]);
            }
            $this->renewalMode($domain, 'RENEWONCE');

            return true;
        }

        $paidUntil = (string) ($this->request(['COMMAND' => 'StatusDomain', 'DOMAIN' => $domain->getName()])['PROPERTY']['PAIDUNTILDATE'][0] ?? '');
        if (!preg_match('/^\d{4}-/', $paidUntil)) {
            throw new Registrar_Exception('CentralNic Reseller returned no paid-until date for :domain.', [':domain' => $domain->getName()]);
        }
        // EXPIRATION guards against renewing twice: the registry takes it only for the year the domain is paid until.
        $this->request(['COMMAND' => 'RenewDomain', 'DOMAIN' => $domain->getName(), 'PERIOD' => $years, 'EXPIRATION' => (int) substr($paidUntil, 0, 4)]);

        return true;
    }

    public function deleteDomain(Registrar_Domain $domain): bool
    {
        // As Blesta: a cancelled domain is left to expire unrenewed, never deleted from the registry.
        try {
            $this->renewalMode($domain, 'AUTOEXPIRE');
            $this->getLog()->info(sprintf('%s was cancelled; CentralNic Reseller lets it expire.', $domain->getName()));
        } catch (Registrar_Exception $e) {
            // A domain no longer in the account (moved away, failed transfer, expired) must still be cancellable.
            $this->getLog()->warn(sprintf('CentralNic Reseller did not set %s to expire: %s', $domain->getName(), $e->getMessage()));
        }

        return true;
    }

    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->request(['COMMAND' => 'ModifyDomain', 'DOMAIN' => $domain->getName(), 'X-WHOIS-PRIVACY' => 1]);

        return true;
    }

    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->request(['COMMAND' => 'ModifyDomain', 'DOMAIN' => $domain->getName(), 'X-WHOIS-PRIVACY' => 0]);

        return true;
    }

    public function lock(Registrar_Domain $domain): bool
    {
        $this->request(['COMMAND' => 'ModifyDomain', 'DOMAIN' => $domain->getName(), 'TRANSFERLOCK' => 1]);

        return true;
    }

    public function unlock(Registrar_Domain $domain): bool
    {
        $this->request(['COMMAND' => 'ModifyDomain', 'DOMAIN' => $domain->getName(), 'TRANSFERLOCK' => 0]);

        return true;
    }

    /**
     * The account's one-year cost per action (QueryZoneList, as Blesta's getTlds/getPrices): registration is the setup fee
     * plus the annual fee, renewal the annual fee, transfer the transfer fee. Zones in another currency than the account's
     * (StatusAccount) are left out, since one price list has one currency.
     */
    public function getTldPrices(array $tlds = []): array
    {
        $currency = strtoupper((string) ($this->request(['COMMAND' => 'StatusAccount'])['PROPERTY']['CURRENCY'][0] ?? ''));
        if ($currency === '') {
            throw new Registrar_Exception('CentralNic Reseller returned an unreadable response for :command', [':command' => 'StatusAccount']);
        }
        $p = $this->request(['COMMAND' => 'QueryZoneList'])['PROPERTY'];
        $fee = static fn (string $column, int $i): ?float => is_numeric($p[$column][$i] ?? null) ? (float) $p[$column][$i] : null;

        $prices = [];
        foreach ($p['ZONE'] ?? [] as $i => $zone) {
            // Blesta's filters: active zones billed by the year with a price, without the premium tiers and the zones that are no TLD.
            if (empty($p['ACTIVE'][$i]) || ($p['PERIODTYPE'][$i] ?? '') !== 'YEAR' || ($fee('ANNUAL', $i) ?? 0.0) <= 0
                || preg_match('/^(nameemail|nuidn|itregional|.*_prem\d+)$/i', $zone)
                || (strtoupper((string) ($p['CURRENCY'][$i] ?? '')) ?: $currency) !== $currency) {
                continue;
            }
            // 3RDS lists the TLDs the zone sells; IDN TLDs are left out, as Blesta does.
            foreach (preg_grep('/^[0-9a-z.-]+$/i', array_map('trim', explode(',', (string) ($p['3RDS'][$i] ?? '')))) ?: [] as $tld) {
                $tld = '.' . strtolower(ltrim($tld, '.'));
                if (!str_starts_with($tld, '.xn--')) {
                    $prices[$tld] ??= ['register' => ($fee('SETUP', $i) ?? 0.0) + $fee('ANNUAL', $i), 'renew' => $fee('ANNUAL', $i), 'transfer' => $fee('TRANSFER', $i)];
                }
            }
        }
        if ($tlds !== []) {
            $prices = array_intersect_key($prices, array_flip($tlds));
        }

        return ['currency' => $currency, 'prices' => $prices, 'remaining' => []];
    }

    /**
     * One API command. Returns CODE, DESCRIPTION and PROPERTY (name => list of values); a code outside $ok is a registrar
     * error with the API's description.
     */
    private function request(array $command, array $ok = ['200']): array
    {
        $lines = [];
        foreach ($command as $key => $value) {
            // A line break would start another command parameter.
            $lines[] = $key . '=' . str_replace(["\r", "\n"], '', (string) $value);
        }

        try {
            $body = $this->getHttpClient()->request('POST', $this->_testMode ? self::OTE_URL : self::LIVE_URL, [
                'body' => ['s_login' => $this->config['username'], 's_pw' => $this->config['password'], 's_command' => implode("\n", $lines)],
                'timeout' => 60,
            ])->getContent();
        } catch (ExceptionInterface $e) {
            $this->getLog()->err('CentralNic Reseller ' . $command['COMMAND'] . ' HTTP error: ' . $e->getMessage());

            throw new Registrar_Exception('Could not reach :registrar: :error', [':registrar' => 'CentralNic Reseller', ':error' => $command['COMMAND']]);
        }

        $reply = ['CODE' => '', 'DESCRIPTION' => '', 'PROPERTY' => []];
        foreach (explode("\n", str_replace("\r", '', $body)) as $line) {
            if (!preg_match('/^([^=]*[^\t= ])[\t ]*=[\t ]*(.*?)[\t ]*$/', $line, $m)) {
                continue;
            }
            if (preg_match('/^property\[([^\]]*)\]/i', $m[1], $name)) {
                // Property names come with spaces or underscores ("created date", "PAIDUNTIL_DATE"): keyed without them.
                $reply['PROPERTY'][strtoupper((string) preg_replace('/[\s_]+/', '', $name[1]))][] = $m[2];
            } else {
                $reply[strtoupper($m[1])] = $m[2];
            }
        }
        if ($reply['CODE'] === '') {
            throw new Registrar_Exception('CentralNic Reseller returned an unreadable response for :command', [':command' => $command['COMMAND']]);
        }
        if (!in_array($reply['CODE'], $ok, true)) {
            $this->getLog()->err(sprintf('CentralNic Reseller %s refused: %s %s', $command['COMMAND'], $reply['CODE'], $reply['DESCRIPTION']));

            throw new Registrar_Exception(':registrar refused the request: :error', [':registrar' => 'CentralNic Reseller', ':error' => $reply['CODE'] . ' ' . $reply['DESCRIPTION']]);
        }

        return $reply;
    }

    /** CheckDomains with the fee extension: without it, a premium name looks like a standard one. */
    private function fees(Registrar_Domain $domain, string $action): array
    {
        return $this->request(['COMMAND' => 'CheckDomains', 'DOMAIN0' => $domain->getName(), 'X-FEE-COMMAND0' => $action,
            'X-FEE-DOMAIN0' => $domain->getName(), 'X-FEE-PERIOD0' => 1, 'X-FEE-PERIODTYPE0' => 'YEAR'])['PROPERTY'];
    }

    private function premium(array $p): bool
    {
        return strcasecmp((string) ($p['X-FEE-CLASS'][0] ?? ''), 'premium') === 0 || !empty($p['PREMIUMCHANNEL'][0]);
    }

    /** The zone's rules; [] when the API has none for it, so the domain command runs with the API's defaults (as Blesta). */
    private function zone(Registrar_Domain $domain): array
    {
        try {
            return $this->request(['COMMAND' => 'GetZoneInfo', 'DOMAIN' => $domain->getName()])['PROPERTY'];
        } catch (Registrar_Exception $e) {
            $this->getLog()->warn(sprintf('CentralNic Reseller has no zone information for %s: %s', $domain->getName(), $e->getMessage()));

            return [];
        }
    }

    private function renewalMode(Registrar_Domain $domain, string $mode): void
    {
        $this->request(['COMMAND' => 'SetDomainRenewalMode', 'DOMAIN' => $domain->getName(), 'RENEWALMODE' => $mode]);
    }

    /** As Blesta: the ordered term when the zone allows it for transfers, else 0 where a transfer adds no year (.es, .no, .nu). */
    private function transferPeriod(Registrar_Domain $domain): array
    {
        $zone = $this->zone($domain);
        if (!isset($zone['TRANSFERPERIODS'][0])) {
            return [];
        }
        $years = max(1, (int) $domain->getRegistrationPeriod());
        $allowed = [];
        foreach (explode(',', $zone['TRANSFERPERIODS'][0]) as $period) {
            // "1Y,2Y" or reset periods "R1Y"; month terms are not years.
            if (preg_match('/^R?(10|\d)Y$/i', trim($period), $m)) {
                $allowed[] = (int) $m[1];
            }
        }
        if (in_array($years, $allowed, true)) {
            return ['PERIOD' => $years];
        }
        if (empty($zone['RENEWALATTRANSFER'][0]) && empty($zone['RENEWALAFTERTRANSFER'][0])) {
            return ['PERIOD' => 0];
        }

        throw new Registrar_Exception('CentralNic Reseller does not offer a :years-year transfer for :tld.', [':years' => $years, ':tld' => $domain->getTld()]);
    }

    /** The order's registry answers as the API's X- parameters. */
    private function extensions(Registrar_Domain $domain): array
    {
        $f = $domain->getFields();
        $category = (string) ($f['nexus_category'] ?? '');

        return array_filter(match (strtolower((string) $domain->getTld())) {
            '.us' => ['X-US-NEXUS-CATEGORY' => $category, 'X-US-NEXUS-APPPURPOSE' => (string) ($f['app_purpose'] ?? ''),
                // The validator (the organisation's country) is only for C31 and C32.
                'X-US-NEXUS-VALIDATOR' => in_array($category, ['C31', 'C32'], true) ? strtoupper((string) ($f['nexus_country'] ?? '')) : ''],
            '.ca' => ['X-CA-LEGAL-TYPE' => (string) ($f['ca_legal_type'] ?? '')],
            '.eu' => ['X-EU-REGISTRANT-CITIZENSHIP' => strtolower((string) ($f['eu_citizenship'] ?? ''))],
            default => [],
        }, fn (string $value) => $value !== '');
    }

    private function nameservers(Registrar_Domain $domain, bool $required = true): array
    {
        $hosts = array_values(array_filter(array_map(fn ($ns) => trim((string) $ns), [$domain->getNs1(), $domain->getNs2(), $domain->getNs3(), $domain->getNs4()])));
        if ($required && count($hosts) < 2) {
            throw new Registrar_Exception('At least two name servers are required.');
        }
        $params = [];
        foreach ($hosts as $i => $host) {
            $params['NAMESERVER' . $i] = $host;
        }

        return $params;
    }

    /** The client's contact inline for each role (OWNERCONTACT0FIRSTNAME=...), as the Blesta module sends it. */
    private function contacts(Registrar_Domain $domain, array $roles): array
    {
        if ($roles === []) {
            return [];
        }
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
        $fields = array_filter([
            'FIRSTNAME' => (string) $c->getFirstName(),
            'LASTNAME' => (string) $c->getLastName(),
            'ORGANIZATION' => (string) $c->getCompany(),
            'STREET' => implode(' , ', array_filter([trim((string) $c->getAddress1()), trim((string) $c->getAddress2())])),
            'CITY' => (string) $c->getCity(),
            'STATE' => (string) $c->getState(),
            'ZIP' => (string) $c->getZip(),
            'COUNTRY' => strtoupper((string) $c->getCountry()),
            // +CC.NUMBER, the form Blesta sends.
            'PHONE' => $tel === '' ? '' : '+' . $cc . '.' . $tel,
            'EMAIL' => (string) $c->getEmail(),
        ], fn (string $value) => $value !== '');

        $params = [];
        foreach ($roles as $role) {
            foreach ($fields as $key => $value) {
                $params[$role . $key] = $value;
            }
        }

        return $params;
    }
}
