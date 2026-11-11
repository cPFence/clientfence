<?php

declare(strict_types=1);
/**
 * Synergy Wholesale registrar adapter (ClientFence).
 *
 * Uses the Synergy Wholesale SOAP API (https://api.synergywholesale.com, needs the PHP soap extension) with the
 * reseller ID and API key from the Synergy Wholesale portal (Account Settings > API Information), which also lists the
 * IP addresses allowed to call it. .au names ask for the registry's eligibility details on the order form
 * (getDomainFields()). Synergy Wholesale has no test system, so test mode refuses every call.
 */
class Registrar_Adapter_Synergywholesale extends Registrar_AdapterAbstract implements Registrar_TldPrices
{
    private const URL = 'https://api.synergywholesale.com/?wsdl';

    /** The .au eligibility types the registry knows, as Synergy Wholesale expects them. */
    private const AU_ELIGIBILITY = ['Charity', 'Citizen/Resident', 'Club', 'Commercial Statutory Body', 'Company',
        'Incorporated Association', 'Industry Body', 'Non-profit Organisation', 'Other', 'Partnership', 'Pending TM Owner',
        'Political Party', 'Registered Business', 'Religious/Church Group', 'Sole Trader', 'Trade Union', 'Trademark Owner',
        'Child Care Centre', 'Government School', 'Higher Education Institution', 'National Body', 'Non-Government School',
        'Pre-school', 'Research Organisation', 'Training Organisation'];

    private const AU_STATES = ['NEWSOUTHWALES' => 'NSW', 'VICTORIA' => 'VIC', 'QUEENSLAND' => 'QLD', 'SOUTHAUSTRALIA' => 'SA',
        'WESTERNAUSTRALIA' => 'WA', 'TASMANIA' => 'TAS', 'NORTHERNTERRITORY' => 'NT', 'AUSTRALIANCAPITALTERRITORY' => 'ACT'];

    public $config = [
        'reseller_id' => null,
        'api_key' => null,
    ];

    public function __construct($options)
    {
        foreach (['reseller_id' => 'Reseller ID', 'api_key' => 'API key'] as $key => $label) {
            if (trim((string) ($options[$key] ?? '')) === '') {
                throw new Registrar_Exception('The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing', [':domain_registrar' => 'Synergy Wholesale', ':missing' => $label], 3001);
            }
            $this->config[$key] = trim((string) $options[$key]);
        }
    }

    public static function getConfig(): array
    {
        return [
            'label' => __trans('Manages domains on Synergy Wholesale through its API. Add this server\'s IP address to the allowed IP addresses in the Synergy Wholesale portal (Account Settings > API Information) and keep enough account balance for registrations and renewals.'),
            'form' => [
                'reseller_id' => ['text', [
                    'label' => __trans('Reseller ID'),
                    'description' => __trans('From the Synergy Wholesale portal: Account Settings > API Information.'),
                    'required' => true,
                ]],
                'api_key' => ['password', [
                    'label' => __trans('API key'),
                    'description' => __trans('From the same page.'),
                    'required' => true,
                    'secret' => true,
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
        $tld = strtolower($tld);
        // Synergy Wholesale's transfers take no eligibility details (its WHMCS module sends none).
        if ($action !== 'register') {
            return [];
        }
        if ($tld === '.us') {
            return self::usNexusFields();
        }
        if (!str_ends_with($tld, '.au')) {
            return [];
        }

        $types = self::AU_ELIGIBILITY;
        if ($tld === '.id.au') {
            $types = ['Citizen/Resident'];
        } elseif (in_array($tld, ['.com.au', '.net.au', '.org.au', '.asn.au'], true)) {
            $types = array_diff($types, ['Citizen/Resident']);
        }
        $fields = [
            'registrant_name' => ['label' => __trans('Registrant name (business or person)'), 'required' => true],
            'eligibility_type' => ['label' => __trans('Eligibility type'), 'required' => true, 'options' => array_combine($types, array_map(self::eligibilityLabel(...), $types))],
        ];
        if ($tld === '.id.au') {
            return $fields;
        }

        // Every entity allowed under .com.au, .net.au, .org.au and .asn.au registers with its ABN, ACN or similar number.
        $idRequired = in_array($tld, ['.com.au', '.net.au', '.org.au', '.asn.au'], true);

        return $fields + [
            'registrant_id_type' => ['label' => __trans('Registrant ID type'), 'required' => $idRequired, 'options' => ['ABN' => 'ABN', 'ACN' => 'ACN', 'OTHER' => __trans('Business registration number')]],
            'registrant_id' => ['label' => __trans('Registrant ID number'), 'required' => $idRequired],
            'eligibility_name' => ['label' => __trans('Eligibility name (if not the registrant)')],
            'eligibility_id_type' => ['label' => __trans('Eligibility ID type'), 'options' => [
                'ABN' => 'ABN', 'ACN' => 'ACN', 'ACT BN' => __trans(':state business number', [':state' => 'ACT']),
                'NSW BN' => __trans(':state business number', [':state' => 'NSW']), 'NT BN' => __trans(':state business number', [':state' => 'NT']),
                'QLD BN' => __trans(':state business number', [':state' => 'QLD']), 'SA BN' => __trans(':state business number', [':state' => 'SA']),
                'TAS BN' => __trans(':state business number', [':state' => 'TAS']), 'VIC BN' => __trans(':state business number', [':state' => 'VIC']),
                'WA BN' => __trans(':state business number', [':state' => 'WA']), 'TM' => __trans('Trademark'),
                'OTHER' => __trans('Other (such as an incorporated association number)'),
            ]],
            'eligibility_id' => ['label' => __trans('Eligibility ID number')],
        ];
    }

    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        return $this->check($domain, 'create');
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        return $this->check($domain, 'transfer');
    }

    public function modifyNs(Registrar_Domain $domain): bool
    {
        $this->request('updateNameServers', ['domainName' => $domain->getName(), 'dnsConfigType' => 1, 'nameServers' => $this->nameservers($domain)]);

        return true;
    }

    public function modifyContact(Registrar_Domain $domain): bool
    {
        $request = ['domainName' => $domain->getName()];
        foreach (['registrant', 'admin', 'technical', 'billing'] as $role) {
            $request += $this->contact($domain->getContactRegistrar(), $role . '_');
        }
        $this->request('updateContact', $request);

        return true;
    }

    public function transferDomain(Registrar_Domain $domain): bool
    {
        $request = ['domainName' => $domain->getName(), 'authInfo' => (string) $domain->getEpp()];
        // A transfer renews the name when the registry allows it; .au transfers do not (as the WHMCS module's default).
        $renew = !str_ends_with($domain->getTld(), '.au')
            && ($this->request('domainRenewRequired', $request, false)->status ?? '') === 'OK_RENEWAL';
        $this->request('transferDomain', $request + ['doRenewal' => $renew, 'idProtect' => (bool) $domain->getPrivacyEnabled()]
            + $this->contact($domain->getContactRegistrar(), ''));

        return true;
    }

    public function getDomainDetails(Registrar_Domain $domain): Registrar_Domain
    {
        $info = $this->request('domainInfo', ['domainName' => $domain->getName()]);

        $details = new Registrar_Domain();
        $details->setSld($domain->getSld());
        $details->setTld($domain->getTld());
        $details->setRegistrationTime(strtotime((string) ($info->createdDate ?? '')) ?: null);
        $details->setExpirationTime(strtotime((string) ($info->domain_expiry ?? '')) ?: $domain->getExpirationTime());
        $details->setLocked(($info->domain_status ?? '') === 'clientTransferProhibited');
        $details->setPrivacyEnabled(($info->idProtect ?? '') === 'Enabled');
        $details->setEpp((string) ($info->domainPassword ?? $domain->getEpp()));
        foreach (array_slice(array_values((array) ($info->nameServers ?? [])), 0, 4) as $i => $host) {
            $details->{'setNs' . ($i + 1)}(strtolower((string) $host));
        }
        // The API returns contacts with their own call; keep ClientFence's copy.
        if ($domain->getContactRegistrar() instanceof Registrar_Domain_Contact) {
            foreach (['Registrar', 'Admin', 'Tech', 'Billing'] as $role) {
                $details->{'setContact' . $role}($domain->getContactRegistrar());
            }
        }

        return $details;
    }

    public function getEpp(Registrar_Domain $domain): string
    {
        return (string) ($this->request('domainInfo', ['domainName' => $domain->getName()])->domainPassword ?? '');
    }

    public function registerDomain(Registrar_Domain $domain): bool
    {
        $request = [
            'domainName' => $domain->getName(),
            'years' => max(1, (int) $domain->getRegistrationPeriod()),
            'nameServers' => $this->nameservers($domain),
            'idProtect' => (bool) $domain->getPrivacyEnabled(),
            'specialConditionsAgree' => true,
        ] + $this->contact($domain->getContactRegistrar(), 'registrant_');

        if (str_ends_with($domain->getTld(), '.au')) {
            $f = $domain->getFields();
            $request['eligibility'] = json_encode(array_filter([
                'registrantName' => $f['registrant_name'] ?? '',
                'registrantID' => $f['registrant_id'] ?? '',
                'registrantIDType' => $f['registrant_id_type'] ?? '',
                'eligibilityType' => $f['eligibility_type'] ?? '',
                'eligibilityName' => $f['eligibility_name'] ?? '',
                'eligibilityID' => $f['eligibility_id'] ?? '',
                'eligibilityIDType' => $f['eligibility_id_type'] ?? '',
            ], fn (string $value) => $value !== ''));
        }
        if ($domain->getTld() === '.us') {
            $f = $domain->getFields();
            $request['eligibility'] = json_encode(array_filter([
                'nexusCategory' => $f['nexus_category'] ?? '',
                'nexusCountry' => strtoupper($f['nexus_country'] ?? ''),
                'appPurpose' => $f['app_purpose'] ?? '',
            ], fn (string $value) => $value !== ''));
        }
        $this->request('domainRegister', $request);

        return true;
    }

    public function renewDomain(Registrar_Domain $domain): bool
    {
        $this->request('renewDomain', ['domainName' => $domain->getName(), 'years' => max(1, (int) $domain->getRegistrationPeriod())]);

        return true;
    }

    public function deleteDomain(Registrar_Domain $domain): bool
    {
        // The API cannot delete a name: a cancelled domain stays at Synergy Wholesale until it expires unrenewed (as WHMCS).
        $this->getLog()->info(sprintf('%s was cancelled; Synergy Wholesale keeps it until it expires.', $domain->getName()));

        return true;
    }

    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->request('enableIDProtection', ['domainName' => $domain->getName()]);

        return true;
    }

    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->request('disableIDProtection', ['domainName' => $domain->getName()]);

        return true;
    }

    public function lock(Registrar_Domain $domain): bool
    {
        $this->request($this->lockCommand($domain, 'lockDomain'), ['domainName' => $domain->getName()]);

        return true;
    }

    public function unlock(Registrar_Domain $domain): bool
    {
        $this->request($this->lockCommand($domain, 'unlockDomain'), ['domainName' => $domain->getName()]);

        return true;
    }

    /** The reseller's one-year cost per action (getDomainPricing); Synergy Wholesale bills in AUD and the reply names no currency. */
    public function getTldPrices(array $tlds = []): array
    {
        $year = static fn (mixed $p): ?float => is_numeric($p) ? (float) $p : null;
        $prices = [];
        foreach ((array) ($this->request('getDomainPricing', [])->pricing ?? []) as $row) {
            $row = (array) $row;
            $prices['.' . strtolower(ltrim((string) ($row['tld'] ?? ''), '.'))] = [
                'register' => $year($row['register_1_year'] ?? null),
                'renew' => $year($row['renew'] ?? null),
                'transfer' => $year($row['transfer'] ?? null),
            ];
        }
        if ($tlds !== []) {
            $prices = array_intersect_key($prices, array_flip($tlds));
        }

        return ['currency' => 'AUD', 'prices' => $prices, 'remaining' => []];
    }

    /** The SOAP client; a test replaces it. */
    protected function client(): object
    {
        if (!class_exists('SoapClient')) {
            throw new Registrar_Exception(':registrar needs the PHP soap extension.', [':registrar' => 'Synergy Wholesale']);
        }

        return new SoapClient(null, ['location' => self::URL, 'uri' => '', 'connection_timeout' => 30, 'exceptions' => true]);
    }

    /** One API call; a status other than OK… or AVAILABLE is an error unless $check is false. */
    private function request(string $command, array $request, bool $check = true): object
    {
        if ($this->_testMode) {
            throw new Registrar_Exception(':registrar has no test system: turn test mode off for this registrar.', [':registrar' => 'Synergy Wholesale']);
        }

        try {
            $response = $this->client()->{$command}(['resellerID' => $this->config['reseller_id'], 'apiKey' => $this->config['api_key']] + $request);
        } catch (SoapFault $e) {
            $this->getLog()->err(sprintf('Synergy Wholesale %s failed: %s', $command, $e->getMessage()));

            throw new Registrar_Exception('Could not reach :registrar: :error', [':registrar' => 'Synergy Wholesale', ':error' => $e->getMessage()]);
        }
        $response = is_object($response) ? $response : (object) ['status' => '', 'errorMessage' => (string) $response];
        if ($check && !preg_match('/^(OK|AVAILABLE)/', (string) ($response->status ?? ''))) {
            throw new Registrar_Exception(':registrar refused the request: :error', [':registrar' => 'Synergy Wholesale', ':error' => (string) ($response->errorMessage ?? $response->status ?? '')]);
        }

        return $response;
    }

    private function check(Registrar_Domain $domain, string $command): bool
    {
        $result = $this->request('bulkCheckDomain', ['domainList' => [$domain->getName()], 'command' => $command]);

        return (bool) (((array) ($result->domainList ?? []))[0]->available ?? false);
    }

    private function lockCommand(Registrar_Domain $domain, string $command): string
    {
        if (preg_match('/\.(au|uk)$/', $domain->getTld())) {
            throw new Registrar_Exception(':tld domains have no registrar lock.', [':tld' => $domain->getTld()]);
        }

        return $command;
    }

    private function nameservers(Registrar_Domain $domain): array
    {
        $hosts = array_values(array_filter(array_map(fn ($ns) => trim((string) $ns), [$domain->getNs1(), $domain->getNs2(), $domain->getNs3(), $domain->getNs4()])));
        if (count($hosts) < 2) {
            throw new Registrar_Exception('At least two name servers are required.');
        }

        return $hosts;
    }

    /** A contact as the API names its fields, each with $prefix (registrant_, admin_, … or none). */
    private function contact(Registrar_Domain_Contact $c, string $prefix): array
    {
        $country = strtoupper((string) $c->getCountry());
        $state = (string) $c->getState();
        if ($country === 'AU') {
            $key = (string) preg_replace('/[^A-Z]/', '', strtoupper($state));
            $state = self::AU_STATES[$key] ?? $key;
        }
        $cc = (string) preg_replace('/\D+/', '', (string) $c->getTelCc());
        $tel = (string) preg_replace('/\D+/', '', (string) $c->getTel());
        // "+61.299999999": an Australian number loses its leading 0 (02 9999 9999).
        $phone = $cc === '' ? $tel : '+' . $cc . '.' . ($cc === '61' ? ltrim($tel, '0') : $tel);

        return [
            $prefix . 'firstname' => (string) $c->getFirstName(),
            $prefix . 'lastname' => (string) $c->getLastName(),
            $prefix . 'organisation' => (string) $c->getCompany(),
            $prefix . 'address' => array_values(array_filter([(string) $c->getAddress1(), (string) $c->getAddress2()])),
            $prefix . 'suburb' => (string) $c->getCity(),
            $prefix . 'state' => $state,
            $prefix . 'postcode' => (string) $c->getZip(),
            $prefix . 'country' => $country,
            $prefix . 'phone' => $phone,
            $prefix . 'email' => (string) $c->getEmail(),
        ];
    }

    /** The translated name of a .au eligibility type (the value sent stays English). */
    private static function eligibilityLabel(string $type): string
    {
        return match ($type) {
            'Charity' => __trans('Charity'),
            'Citizen/Resident' => __trans('Australian citizen or resident'),
            'Club' => __trans('Club'),
            'Commercial Statutory Body' => __trans('Commercial statutory body'),
            'Company' => __trans('Company'),
            'Incorporated Association' => __trans('Incorporated association'),
            'Industry Body' => __trans('Industry body'),
            'Non-profit Organisation' => __trans('Non-profit organisation'),
            'Other' => __trans('Other'),
            'Partnership' => __trans('Partnership'),
            'Pending TM Owner' => __trans('Pending trademark owner'),
            'Political Party' => __trans('Political party'),
            'Registered Business' => __trans('Registered business'),
            'Religious/Church Group' => __trans('Religious or church group'),
            'Sole Trader' => __trans('Sole trader'),
            'Trade Union' => __trans('Trade union'),
            'Trademark Owner' => __trans('Trademark owner'),
            'Child Care Centre' => __trans('Child care centre'),
            'Government School' => __trans('Government school'),
            'Higher Education Institution' => __trans('Higher education institution'),
            'National Body' => __trans('National body'),
            'Non-Government School' => __trans('Non-government school'),
            'Pre-school' => __trans('Pre-school'),
            'Research Organisation' => __trans('Research organisation'),
            'Training Organisation' => __trans('Training organisation'),
            default => $type,
        };
    }
}
