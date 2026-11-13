<?php

declare(strict_types=1);
/**
 * Realtime Register registrar adapter (ClientFence).
 *
 * Uses the Realtime Register REST API v2 (https://dm.realtimeregister.com/docs/api): https://api.yoursrs.com with the
 * header "Authorization: ApiKey <key>"; the registrar's test mode uses the OT&E system (https://api.yoursrs-ote.com)
 * with an OT&E key. Each registration and transfer creates new contacts (registrant, admin, billing, tech) under the
 * customer handle; a contact change replaces the admin, billing and tech contacts. The order form asks for the registry
 * properties of .us, .eu, .ca, .uk and .fr names (getDomainFields()), added to the registrant contact.
 */
use ClientFence\Mod\Invoice\EuVat;
use Symfony\Component\Intl\Countries;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

class Registrar_Adapter_Realtimeregister extends Registrar_AdapterAbstract implements Registrar_TldPrices, Registrar_TransferStatus
{
    private const LIVE_URL = 'https://api.yoursrs.com/v2';
    private const OTE_URL = 'https://api.yoursrs-ote.com/v2';

    /** The Nominet extensions Realtime Register sells (its TLD metadata). */
    private const UK_TLDS = ['.uk', '.co.uk', '.ltd.uk', '.me.uk', '.net.uk', '.org.uk', '.plc.uk'];
    // TLDs whose registry requires (or for some registrants requires) contact properties ClientFence does not ask for
    // (Realtime Register's TLD metadata): refused and not priced, so no paid order fails at activation.
    private const UNASKED = [
        '.ac.cn', '.ac.xn--fiqs8s', '.ae', '.aero', '.ah.cn', '.ah.xn--fiqs8s', '.barcelona', '.be', '.bj.cn',
        '.bj.xn--fiqs8s', '.by', '.cat', '.cn', '.co.th', '.com.by', '.com.cn', '.com.ee', '.com.hk', '.com.jm',
        '.com.py', '.com.ru', '.com.ua', '.com.xn--fiqs8s', '.cq.cn', '.cq.xn--fiqs8s', '.dk', '.ee', '.eus', '.fi',
        '.fie.ee', '.fj.cn', '.fj.xn--fiqs8s', '.gd.cn', '.gd.xn--fiqs8s', '.gm', '.gs.cn', '.gs.xn--fiqs8s', '.gw',
        '.gx.cn', '.gx.xn--fiqs8s', '.gz.cn', '.gz.xn--fiqs8s', '.ha.cn', '.ha.xn--fiqs8s', '.hb.cn',
        '.hb.xn--fiqs8s', '.he.cn', '.he.xn--fiqs8s', '.hi.cn', '.hi.xn--fiqs8s', '.hk', '.hk.cn', '.hk.xn--fiqs8s',
        '.hl.cn', '.hl.xn--fiqs8s', '.hn.cn', '.hn.xn--fiqs8s', '.ie', '.in.th', '.jl.cn', '.jl.xn--fiqs8s', '.js.cn',
        '.js.xn--fiqs8s', '.jx.cn', '.jx.xn--fiqs8s', '.kg', '.ln.cn', '.ln.xn--fiqs8s', '.lt', '.med.ee', '.mo.cn',
        '.mo.xn--fiqs8s', '.moscow', '.msk.ru', '.music', '.net.cn', '.net.ru', '.net.xn--fiqs8s', '.nm.cn',
        '.nm.xn--fiqs8s', '.nx.cn', '.nx.xn--fiqs8s', '.org.cn', '.org.jm', '.org.ru', '.org.sk', '.org.xn--fiqs8s',
        '.pp.ru', '.pri.ee', '.qh.cn', '.qh.xn--fiqs8s', '.ru', '.sc.cn', '.sc.xn--fiqs8s', '.scot', '.sd.cn',
        '.sd.xn--fiqs8s', '.sh.cn', '.sh.xn--fiqs8s', '.sk', '.sn.cn', '.sn.xn--fiqs8s', '.spb.ru', '.sport', '.su',
        '.sx.cn', '.sx.xn--fiqs8s', '.tatar', '.tj.cn', '.tj.xn--fiqs8s', '.tw.cn', '.tw.xn--fiqs8s', '.ua', '.xj.cn',
        '.xj.xn--fiqs8s', '.xn--80adxhks', '.xn--90ais', '.xn--d1acj3b', '.xn--fiqs8s', '.xn--p1acf', '.xn--p1ai',
        '.xz.cn', '.xz.xn--fiqs8s', '.yn.cn', '.yn.xn--fiqs8s', '.zj.cn', '.zj.xn--fiqs8s',
    ];

    /** Order form field => the registry's contact property name. */
    private const PROPERTIES = ['nexus_category' => 'NexusCategory', 'app_purpose' => 'AppPurpose', 'eu_citizenship' => 'countryOfCitizenship',
        'ca_presence' => 'CPR', 'uk_type' => 'type', 'uk_company_number' => 'co-no', 'fr_legal_status' => 'legalStatusType',
        'it_entity_type' => 'entityType', 'it_reg_code' => 'regCode', 'es_id_number' => 'identificationNumber', 'pt_vat' => 'vatno',
        'org_number' => 'orgno'];

    public $config = [
        'customer' => null,
        'api_key' => null,
    ];

    public function __construct($options)
    {
        foreach (['customer' => 'customer handle', 'api_key' => 'API key'] as $key => $label) {
            if (trim((string) ($options[$key] ?? '')) === '') {
                throw new Registrar_Exception('The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing', [':domain_registrar' => 'Realtime Register', ':missing' => $label], 3001);
            }
            $this->config[$key] = trim((string) $options[$key]);
        }
    }

    public static function getConfig(): array
    {
        return [
            'label' => __trans('Manages domains on Realtime Register through its API. Create the API key in the Realtime Register portal (your profile page, or Account details > Users) and keep enough account credit for registrations and renewals. Realtime Register auto-renewal is turned off for every domain: ClientFence renews a domain when its renewal invoice is paid. With test mode on, enter the customer handle and API key of your OT&E account.'),
            'form' => [
                'customer' => ['text', [
                    'label' => __trans('Customer handle'),
                    'description' => __trans('Your Realtime Register customer handle.'),
                    'required' => true,
                ]],
                'api_key' => ['password', [
                    'label' => __trans('API key'),
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
        // The registry properties sit on the registrant contact, which registrations and transfers both create.
        $tld = strtolower($tld);
        if (in_array($tld, self::UK_TLDS, true)) {
            return [
                'uk_type' => ['label' => __trans('Registrant type'), 'options' => [
                    'IND' => __trans('UK individual'), 'FIND' => __trans('Non-UK individual'), 'LTD' => __trans('UK limited company'),
                    'PLC' => __trans('UK public limited company'), 'PTNR' => __trans('UK partnership'), 'LLP' => __trans('UK limited liability partnership'),
                    'IP' => __trans('UK industrial or provident registered company'), 'STRA' => __trans('UK sole trader'), 'SCH' => __trans('UK school'),
                    'RCHAR' => __trans('UK registered charity'), 'GOV' => __trans('UK government body'), 'CRC' => __trans('UK corporation by royal charter'),
                    'STAT' => __trans('UK statutory body'), 'OTHER' => __trans('Other UK organisation'), 'FCORP' => __trans('Non-UK corporation'),
                    'FOTHER' => __trans('Other non-UK organisation'),
                ]],
                'uk_company_number' => ['label' => __trans('Company registration number')],
            ];
        }
        // EEA citizens living outside the EU also qualify (the registry's list).
        $citizenships = [...EuVat::COUNTRIES, 'IS', 'LI', 'NO'];

        return match ($tld) {
            '.us' => array_diff_key(self::usNexusFields(), ['nexus_country' => true]),
            '.eu' => ['eu_citizenship' => ['label' => __trans('EU country of citizenship of the registrant'),
                'options' => array_combine($citizenships, array_map(fn (string $code) => Countries::getName($code), $citizenships))]],
            '.ca' => ['ca_presence' => ['label' => __trans('Canadian presence requirement'), 'required' => true, 'options' => [
                'CCT' => __trans('Canadian citizen'), 'RES' => __trans('Permanent resident of Canada'),
                'CCO' => __trans('Corporation (Canada or a Canadian province or territory)'), 'PRT' => __trans('Partnership registered in Canada'),
                'ASS' => __trans('Canadian unincorporated association'), 'TRD' => __trans('Canadian trade union'), 'PLT' => __trans('Canadian political party'),
                'TRS' => __trans('Trust established in Canada'), 'EDU' => __trans('Canadian educational institution'), 'HOP' => __trans('Canadian hospital'),
                'LAM' => __trans('Canadian library, archive or museum'), 'GOV' => __trans('Government or government entity in Canada'),
                'ABO' => __trans('Aboriginal people indigenous to Canada'), 'INB' => __trans('Indian band recognised by the Indian Act of Canada'),
                'LGR' => __trans('Legal representative of a Canadian citizen or permanent resident'), 'OMK' => __trans('Official mark registered in Canada'),
                'TDM' => __trans('Trademark registered in Canada (non-Canadian owner)'), 'MAJ' => __trans('His Majesty the King'),
            ]]],
            '.fr' => ['fr_legal_status' => ['label' => __trans('Legal status (organisations only)'), 'options' => [
                'company' => __trans('Company'), 'association' => __trans('Association'), 'other' => __trans('Other'),
            ]]],
            '.it' => [
                'it_entity_type' => ['label' => __trans('Registrant type'), 'required' => true, 'options' => [
                    '1' => __trans('Person (Italian or foreign)'), '2' => __trans('Italian company or one-person company'),
                    '3' => __trans('Italian freelancer or professional'), '4' => __trans('Italian non-profit organisation'),
                    '5' => __trans('Italian public organisation'), '6' => __trans('Other Italian organisation'),
                    '7' => __trans('Foreign organisation (EU, not Italy)'),
                ]],
                'it_reg_code' => ['label' => __trans('Codice Fiscale, VAT number or ID document number'), 'required' => true],
            ],
            '.es', '.com.es', '.nom.es', '.org.es' => ['es_id_number' => ['label' => __trans('NIF, NIE or organisation number'), 'required' => true]],
            '.pt', '.com.pt' => ['pt_vat' => ['label' => __trans('Portuguese tax number (NIF)'), 'required' => true]],
            '.se', '.nu' => ['org_number' => ['label' => __trans('Personal or organisation number'), 'required' => true]],
            default => [],
        };
    }

    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        $this->asked($domain);
        $result = $this->check($domain);
        if ($result['available'] === true && !empty($result['premium'])) {
            throw new Registrar_Exception('Premium domains cannot be registered.');
        }

        return $result['available'] === true;
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        // A registered name is a transfer candidate; a premium one costs more than the TLD's transfer price.
        $this->asked($domain);
        $result = $this->check($domain);
        if ($result['available'] === false && !empty($result['premium'])) {
            throw new Registrar_Exception('Premium domains cannot be transferred.');
        }

        return $result['available'] === false;
    }

    public function modifyNs(Registrar_Domain $domain): bool
    {
        $this->request('POST', $this->domainPath($domain) . '/update', ['ns' => $this->nameservers($domain)]);

        return true;
    }

    public function modifyContact(Registrar_Domain $domain): bool
    {
        // The registrant stays: changing it can start the registry's owner-change approval.
        $old = $this->request('GET', $this->domainPath($domain))['contacts'] ?? [];
        $handles = $this->contacts($domain, ['ADMIN', 'BILLING', 'TECH']);
        $this->request('POST', $this->domainPath($domain) . '/update', ['contacts' => $this->roles($handles)]);
        foreach (is_array($old) ? $old : [] as $contact) {
            // The change is done: an old contact still used elsewhere stays, logged only.
            try {
                $this->request('DELETE', $this->customerPath('/contacts/' . rawurlencode((string) ($contact['handle'] ?? ''))));
            } catch (Registrar_Exception $e) {
                $this->getLog()->warn(sprintf('Realtime Register kept the old contact of %s: %s', $domain->getName(), $e->getMessage()));
            }
        }

        return true;
    }

    public function transferDomain(Registrar_Domain $domain): bool
    {
        // The code is optional: .uk transfers have none (the owner changes the IPS tag instead).
        $reply = $this->request('POST', $this->domainPath($domain) . '/transfer', $this->order($domain) + array_filter(['authcode' => trim((string) $domain->getEpp())]));
        if (in_array($reply['status'] ?? '', ['cancelled', 'rejected', 'failed'], true)) {
            throw new Registrar_Exception(':registrar refused the request: :error', [':registrar' => 'Realtime Register', ':error' => 'transfer ' . $reply['status']]);
        }

        return true;
    }

    public function getTransferStatus(Registrar_Domain $domain): array
    {
        $status = (string) ($this->request('GET', $this->domainPath($domain) . '/transfer')['status'] ?? '');

        // pendingwhois, pendingfoa, pendingvalidation, pending and approved are still under way.
        return ['status' => match ($status) {
            'completed' => Registrar_TransferStatus::COMPLETED,
            'cancelled', 'rejected', 'failed' => Registrar_TransferStatus::FAILED,
            default => Registrar_TransferStatus::PENDING,
        }, 'message' => $status];
    }

    public function getDomainDetails(Registrar_Domain $domain): Registrar_Domain
    {
        $info = $this->request('GET', $this->domainPath($domain));

        $details = new Registrar_Domain();
        $details->setSld($domain->getSld());
        $details->setTld($domain->getTld());
        $details->setRegistrationTime(strtotime((string) ($info['createdDate'] ?? '')) ?: null);
        $details->setExpirationTime(strtotime((string) ($info['expiryDate'] ?? '')) ?: $domain->getExpirationTime());
        $details->setLocked(in_array('CLIENT_TRANSFER_PROHIBITED', (array) ($info['status'] ?? []), true));
        $details->setPrivacyEnabled(!empty($info['privacyProtect']));
        $details->setEpp((string) ($info['authcode'] ?? $domain->getEpp()));
        foreach (array_slice(array_values(array_filter(array_map(fn ($ns) => strtolower(trim((string) $ns)), (array) ($info['ns'] ?? [])))), 0, 4) as $i => $host) {
            $details->{'setNs' . ($i + 1)}($host);
        }
        // The API returns contact handles, not contacts: keep ClientFence's copy.
        if ($domain->getContactRegistrar() instanceof Registrar_Domain_Contact) {
            foreach (['Registrar', 'Admin', 'Tech', 'Billing'] as $role) {
                $details->{'setContact' . $role}($domain->getContactRegistrar());
            }
        }

        return $details;
    }

    public function getEpp(Registrar_Domain $domain): string
    {
        return (string) ($this->request('GET', $this->domainPath($domain))['authcode'] ?? '');
    }

    public function registerDomain(Registrar_Domain $domain): bool
    {
        $this->request('POST', $this->domainPath($domain), $this->order($domain));

        return true;
    }

    public function renewDomain(Registrar_Domain $domain): bool
    {
        $this->request('POST', $this->domainPath($domain) . '/renew', ['period' => 12 * min(10, max(1, (int) $domain->getRegistrationPeriod()))]);

        return true;
    }

    public function deleteDomain(Registrar_Domain $domain): bool
    {
        // A cancelled domain is left to expire unrenewed (as WHMCS), never deleted from the registry.
        $this->getLog()->info(sprintf('%s was cancelled; Realtime Register keeps it until it expires.', $domain->getName()));

        return true;
    }

    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->request('POST', $this->domainPath($domain) . '/update', ['privacyProtect' => true]);

        return true;
    }

    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->request('POST', $this->domainPath($domain) . '/update', ['privacyProtect' => false]);

        return true;
    }

    public function lock(Registrar_Domain $domain): bool
    {
        return $this->setTransferLock($domain, true);
    }

    public function unlock(Registrar_Domain $domain): bool
    {
        return $this->setTransferLock($domain, false);
    }

    /** The customer's one-year price per action of every domain product (GET pricelist; prices in cents). */
    public function getTldPrices(array $tlds = []): array
    {
        $actions = ['CREATE' => 'register', 'RENEW' => 'renew', 'TRANSFER' => 'transfer'];
        $currency = '';
        $prices = [];
        foreach ((array) ($this->request('GET', $this->customerPath('/pricelist'))['prices'] ?? []) as $row) {
            $product = strtolower((string) ($row['product'] ?? ''));
            $action = $actions[$row['action'] ?? ''] ?? null;
            if ($action === null || !str_contains($product, 'domain') || !is_numeric($row['price'] ?? null) || $row['price'] <= 0) {
                continue;
            }
            $currency = $currency !== '' ? $currency : strtoupper((string) ($row['currency'] ?? ''));
            // shortcut: one currency per list; a product priced in another currency is left out, ask for ?currency= if that happens.
            if (strtoupper((string) ($row['currency'] ?? '')) !== $currency) {
                continue;
            }
            // The product name carries the TLD: domain_com, domain_co_uk, centralnic_domain_uk_com, domain_nl_legacy.
            $tld = '.' . str_replace('_', '.', trim(str_replace(['domain_', 'centralnic_', '_legacy'], '_', $product), '_'));
            $prices[$tld] ??= ['register' => null, 'renew' => null, 'transfer' => null];
            $prices[$tld][$action] = round((float) $row['price'] / 100, 2);
        }
        $prices = array_diff_key($prices, array_flip(self::UNASKED));
        if ($tlds !== []) {
            $prices = array_intersect_key($prices, array_flip($tlds));
        }

        return ['currency' => $currency !== '' ? $currency : 'EUR', 'prices' => $prices, 'remaining' => []];
    }

    /** One API call; returns its JSON. Any status from 300 is a registrar error with Realtime Register's message and violations. */
    private function request(string $method, string $path, array $body = []): array
    {
        $options = ['headers' => ['Authorization' => 'ApiKey ' . $this->config['api_key']], 'timeout' => 60];
        if ($body !== []) {
            $options[$method === 'GET' ? 'query' : 'json'] = $body;
        }

        try {
            $response = $this->getHttpClient()->request($method, ($this->_testMode ? self::OTE_URL : self::LIVE_URL) . $path, $options);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            $this->getLog()->err('Realtime Register ' . $method . ' ' . $path . ' HTTP error: ' . $e->getMessage());

            throw new Registrar_Exception('Could not reach :registrar: :error', [':registrar' => 'Realtime Register', ':error' => $path]);
        }

        $reply = json_decode($content, true);
        $reply = is_array($reply) ? $reply : [];
        if ($status >= 300) {
            $violations = array_map(fn ($v) => trim(($v['field'] ?? '') . ': ' . ($v['message'] ?? ''), ': '), is_array($reply['violations'] ?? null) ? $reply['violations'] : []);
            $error = implode('; ', array_filter([(string) ($reply['message'] ?? ''), ...$violations])) ?: 'HTTP ' . $status;
            $this->getLog()->err(sprintf('Realtime Register %s %s refused: %s %s', $method, $path, $reply['type'] ?? $status, $error));

            throw new Registrar_Exception(':registrar refused the request: :error', [':registrar' => 'Realtime Register', ':error' => $error]);
        }

        return $reply;
    }

    private function check(Registrar_Domain $domain): array
    {
        $result = $this->request('GET', $this->domainPath($domain) . '/check');
        if (!is_bool($result['available'] ?? null)) {
            throw new Registrar_Exception('Realtime Register returned an unreadable response for :command', [':command' => 'domains/check']);
        }

        return $result;
    }

    /** The register or transfer body: new contacts, the order's period and name servers, no Realtime Register auto-renewal. */
    private function order(Registrar_Domain $domain): array
    {
        $ns = $this->nameservers($domain);
        $handles = $this->contacts($domain, ['REGISTRANT', 'ADMIN', 'BILLING', 'TECH']);
        $body = [
            'customer' => $this->config['customer'],
            'registrant' => $handles['REGISTRANT'],
            'privacyProtect' => (bool) $domain->getPrivacyEnabled(),
            'autoRenew' => false,
            'period' => 12 * min(10, max(1, (int) $domain->getRegistrationPeriod())),
            'ns' => $ns,
            'contacts' => $this->roles(array_diff_key($handles, ['REGISTRANT' => true])),
        ];
        // OT&E orders use Realtime Register's test name servers and a basic zone (as Blesta's module does).
        if ($this->_testMode) {
            $body['ns'] = ['ns1.realtimeregister-ote.com', 'ns2.realtimeregister-ote.com'];
            $body['zone'] = ['service' => 'BASIC', 'template' => 'standard-template'];
        }

        return $body;
    }

    /**
     * A new contact per role from the client's details (each with its own handle, so a later change of one role touches
     * no other); the registrant also gets the order's registry properties.
     *
     * @return array<string, string> role => handle
     */
    private function contacts(Registrar_Domain $domain, array $roles): array
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
        $contact = array_filter([
            'name' => trim((string) $c->getFirstName() . ' ' . (string) $c->getLastName()),
            'organization' => trim((string) $c->getCompany()),
            'addressLine' => array_values(array_filter([trim((string) $c->getAddress1()), trim((string) $c->getAddress2())], fn (string $line) => $line !== '')),
            'postalCode' => trim((string) $c->getZip()),
            'city' => trim((string) $c->getCity()),
            'state' => trim((string) $c->getState()),
            'country' => strtoupper(trim((string) $c->getCountry())),
            'email' => trim((string) $c->getEmail()),
            'voice' => '+' . $cc . '.' . $tel,
        ], fn ($value) => $value !== '' && $value !== []);

        $handles = [];
        foreach ($roles as $role) {
            $handles[$role] = strtolower($role) . '-' . bin2hex(random_bytes(6));
            // Only the registrant may be a company: some registries refuse organisation admin, billing or tech contacts (.ca admin).
            $this->request('POST', $this->customerPath('/contacts/' . $handles[$role]), $role === 'REGISTRANT' ? $contact : array_diff_key($contact, ['organization' => true]));
        }
        $registry = $this->registry($domain->getTld());
        $properties = [];
        foreach ($domain->getFields() as $field => $value) {
            if (isset(self::PROPERTIES[$field]) && trim((string) $value) !== '') {
                $properties[self::PROPERTIES[$field]] = trim((string) $value);
            }
        }
        if (isset($handles['REGISTRANT']) && $registry !== null && $properties !== []) {
            $this->request('POST', $this->customerPath('/contacts/' . $handles['REGISTRANT'] . '/' . $registry), ['properties' => $properties]);
        }

        return $handles;
    }

    /** The registry (provider) name the contact properties of a TLD are stored under, from Realtime Register's TLD metadata. */
    /** Refuses a TLD whose registry needs details the order form does not ask, before the client can pay for it. */
    private function asked(Registrar_Domain $domain): void
    {
        if (in_array(strtolower((string) $domain->getTld()), self::UNASKED, true)) {
            throw new Registrar_Exception(':tld domains need registry details ClientFence does not ask for: register them at :registrar directly.', [':tld' => $domain->getTld(), ':registrar' => 'Realtime Register']);
        }
    }

    private function registry(string $tld): ?string
    {
        $tld = strtolower($tld);

        return in_array($tld, self::UK_TLDS, true) ? 'Nominet' : match ($tld) {
            '.us' => 'NeustarEnhanced',
            '.eu' => 'EurId',
            '.ca' => 'Cira',
            '.fr' => 'Afnic',
            '.it' => 'Nicit',
            '.es', '.com.es', '.nom.es', '.org.es' => 'Esnic',
            '.pt', '.com.pt' => 'DnsPt',
            '.se' => 'IisSe',
            '.nu' => 'IisNu',
            default => null,
        };
    }

    private function roles(array $handles): array
    {
        return array_map(fn (string $role, string $handle) => ['role' => $role, 'handle' => $handle], array_keys($handles), $handles);
    }

    /** Adds or removes CLIENT_TRANSFER_PROHIBITED in the domain's status list (only CLIENT_* statuses may change). */
    private function setTransferLock(Registrar_Domain $domain, bool $lock): bool
    {
        $status = array_values(array_diff((array) ($this->request('GET', $this->domainPath($domain))['status'] ?? []), ['CLIENT_TRANSFER_PROHIBITED']));
        if ($lock) {
            $status[] = 'CLIENT_TRANSFER_PROHIBITED';
        }
        $this->request('POST', $this->domainPath($domain) . '/update', ['status' => $status]);

        return true;
    }

    private function domainPath(Registrar_Domain $domain): string
    {
        return '/domains/' . rawurlencode(strtolower($domain->getName()));
    }

    private function customerPath(string $path): string
    {
        return '/customers/' . rawurlencode((string) $this->config['customer']) . $path;
    }

    private function nameservers(Registrar_Domain $domain): array
    {
        $hosts = array_values(array_filter(array_map(fn ($ns) => strtolower(trim((string) $ns)), [$domain->getNs1(), $domain->getNs2(), $domain->getNs3(), $domain->getNs4()])));
        if (count($hosts) < 2) {
            throw new Registrar_Exception('At least two name servers are required.');
        }

        return $hosts;
    }
}
