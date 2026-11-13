<?php

declare(strict_types=1);
/**
 * OpenSRS (Tucows) registrar adapter (ClientFence).
 *
 * Based on the OpenSRS module for Blesta (https://github.com/blesta/module-opensrs, Copyright 2021 Blesta, MIT),
 * reworked for ClientFence. Uses the OpenSRS Domains API (https://domains.opensrs.guide/docs): XML (OPS envelope)
 * POSTed to https://rr-n1-tor.opensrs.net:55443 with the headers X-Username and X-Signature = md5(md5(xml + key) + key);
 * the registrar's test mode uses the test environment (https://horizon.opensrs.net:55443) with a test account. The
 * client's contact is sent for all four roles. The order form asks for the registry details of .us, .ca, .uk, .fr,
 * .it, .law, .abogado and .xxx names (getDomainFields()), sent as OpenSRS's tld_data or registrant_extra_info.
 */
use Symfony\Component\Intl\Countries;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

class Registrar_Adapter_Opensrs extends Registrar_AdapterAbstract implements Registrar_TldPrices, Registrar_TransferStatus
{
    private const LIVE_URL = 'https://rr-n1-tor.opensrs.net:55443';
    private const TEST_URL = 'https://horizon.opensrs.net:55443';

    /** TLDs priced per getTldPrices() call: OpenSRS prices one TLD and action per request. */
    private const PRICE_SLICE = 5;

    private const UK_TLDS = ['.uk', '.co.uk', '.ltd.uk', '.me.uk', '.net.uk', '.org.uk', '.plc.uk'];
    // The .au registry requires eligibility data (au_registrant_info) the order form does not ask: refused and not priced.
    private const UNASKED = ['.au', '.com.au', '.net.au', '.org.au', '.asn.au', '.id.au'];

    /** The TLDs OpenSRS sells (the Blesta module's list): the API has no call that lists them. */
    private const TLDS = 'abogado ac ac.mu academy accountant accountants actor adult ae aero af africa ag agency ai airforce alsace am '
        . 'amsterdam apartments app ar archi army art as asia asn.au associates at attorney au auction audio auto autos '
        . 'baby band bar barcelona bargains bayern be beauty beer berlin best bet bid bike bingo bio biz biz.ki biz.pl '
        . 'black blackfriday blog blue boats bond boo boston bot boutique br brussels build builders business buzz bz bzh '
        . 'ca cab cafe camera camp capetown capital car cards care careers cars casa case cash casino cat catering cc cd '
        . 'center ceo cfd ch channel charity chat cheap christmas church city cl claims cleaning click clinic clothing '
        . 'cloud club cm cn co co.ag co.at co.cm co.gl co.gg co.gy co.hu co.il co.im co.in co.je co.kr co.lc co.ma co.mu '
        . 'co.nz co.uk co.ve co.za coach codes coffee college cologne com com.af com.ag com.ai com.ar com.au com.br com.cm '
        . 'com.co com.ec com.es com.gl com.gr com.gy com.hn com.hr com.ht com.im com.ki com.lc com.lv com.ly com.mu com.my '
        . 'com.pe com.ph com.pl com.pr com.pt com.ro com.ru com.sb com.sc com.so com.tw com.ua com.uy com.vc com.ve '
        . 'community company computer condos construction consulting contact contractors cooking cool coop corsica country '
        . 'coupons courses credit creditcard cricket cruises cx cymru cyou cz dad dance date dating day de deal dealer '
        . 'deals degree delivery democrat dental dentist desi design dev diamonds diet digital direct directory discount '
        . 'diy dk doctor dog domains download durban earth ec eco edu.gr edu.pl education email energy engineer '
        . 'engineering enterprises equipment es esq estate eu events exchange expert exposed express fail faith family fan '
        . 'fans farm fashion fast feedback fi film finance financial firm.in fish fishing fit fitness flights florist '
        . 'flowers fm foo food football forsale forum foundation fr free frl fun fund furniture futbol fyi gallery game '
        . 'games garden gay gd gen.in gg gift gifts gives giving gl glass global gmbh gold golf gr graphics gratis green '
        . 'gripe group gs guide guitars guru gy hair hamburg haus health healthcare help hiphop hiv hk hm hn hockey '
        . 'holdings holiday homes horse hospital host hosting hot house how hr ht hu icu id.au idv.tw ie il im immo '
        . 'immobilien in inc ind.in industries info info.ec info.ht info.ki info.pl ing ink institute insure international '
        . 'investments io irish is ist istanbul it je jetzt jewelry jobs joburg jp juegos kaufen kg ki kids kim kitchen '
        . 'kiwi koeln kr l.lc la land lat law lawyer lc lease legal lgbt li life lifestyle lighting limited limo link live '
        . 'living llc loan loans locker lol london love lt ltd ltd.uk ltda lu luxury lv ly ma maison makeup management '
        . 'market marketing mba md me me.uk med med.ec media melbourne meme memorial men menu miami mn mobi mobi.ki moda '
        . 'moe moi mom money monster mortgage motorcycles mov movie ms mu music mx my nagoya name navy net net.af net.ag '
        . 'net.ai net.au net.cm net.co net.ec net.gg net.gl net.gr net.gy net.hn net.ht net.im net.in net.ki net.lc net.my '
        . 'net.nz net.pe net.ph net.pl net.sb net.sc net.so net.uk net.vc net.za network new news nexus ngo ninja nl no '
        . 'nom.ag nom.co nom.es nom.pl now nrw nu nyc nz observer off.ai one ong onl online or.at org org.af org.ag org.ai '
        . 'org.au org.bz org.es org.gg org.gl org.gr org.hn org.ht org.il org.im org.in org.lc org.mu org.my org.nz org.ph '
        . 'org.pl org.sb org.sc org.so org.tw org.uk org.vc org.za organic p.lc page paris partners parts party pe pet ph '
        . 'phd photo photography photos physio pics pictures pink pizza pl place plc.uk plumbing plus pm poker porn pr '
        . 'press pro pro.ec productions prof promo properties property protection pt pub pw qa quebec quest racing re '
        . 'realestate realty recipes red rehab reise reisen rent rentals repair report republican rest restaurant review '
        . 'reviews rich rip ro rocks rodeo rsvp ru ruhr run sale salon sarl sb sbs sc school schule science scot se '
        . 'security services sex sexy sg sh shiksha shoes shop shop.pl shopping show si singles site ski skin so soccer '
        . 'social software solar solutions soy spa space sport spot sr srl st storage store stream studio study style '
        . 'sucks supplies supply support surf surgery sx sydney systems talk tattoo tax taxi tc team tech technology tel '
        . 'tel.ki tennis tf theater theatre tickets tienda tips tires tirol tk tl tm to today tokyo tools top tours town '
        . 'trade training travel tube tv tw ua uk university uno us uy vacations vana vc ve vegas ventures vet vg viajes '
        . 'video villas vin vip vision vlaanderen vodka vote voto voyage wales watch watches waw.pl webcam web.za website '
        . 'wedding wf wien wiki win wine work works world ws wtf xxx xyz yachts yoga yokohama you yt za zip zone ';

    public $config = [
        'username' => null,
        'api_key' => null,
    ];

    public function __construct($options)
    {
        foreach (['username' => 'reseller username', 'api_key' => 'API key'] as $key => $label) {
            if (trim((string) ($options[$key] ?? '')) === '') {
                throw new Registrar_Exception('The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing', [':domain_registrar' => 'OpenSRS', ':missing' => $label], 3001);
            }
            $this->config[$key] = trim((string) $options[$key]);
        }
    }

    public static function getConfig(): array
    {
        return [
            'label' => __trans('Manages domains on OpenSRS (Tucows) through its API. Copy the API key from the OpenSRS Reseller Control Panel (Account Settings > API settings), add this server\'s IP address under IP Access Rules there, and keep enough account funds for registrations and renewals. OpenSRS auto-renewal is turned off for every domain: ClientFence renews a domain when its renewal invoice is paid. With test mode on, enter the username and API key of your OpenSRS test account.'),
            'form' => [
                'username' => ['text', [
                    'label' => __trans('Reseller username'),
                    'description' => __trans('Your OpenSRS reseller username.'),
                    'required' => true,
                ]],
                'api_key' => ['password', [
                    'label' => __trans('API key'),
                    'description' => __trans('From the OpenSRS Reseller Control Panel: Account Settings > API settings.'),
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
        // OpenSRS takes the same registry details for registrations and transfers (sw_register for both).
        $tld = strtolower($tld);
        if (in_array($tld, self::UK_TLDS, true)) {
            return [
                'registrant_type' => ['label' => __trans('Registrant type'), 'required' => true, 'options' => [
                    'IND' => __trans('UK individual'), 'FIND' => __trans('Non-UK individual'), 'LTD' => __trans('UK limited company'),
                    'PLC' => __trans('UK public limited company'), 'PTNR' => __trans('UK partnership'), 'LLP' => __trans('UK limited liability partnership'),
                    'IP' => __trans('UK industrial or provident registered company'), 'STRA' => __trans('UK sole trader'), 'SCH' => __trans('UK school'),
                    'RCHAR' => __trans('UK registered charity'), 'GOV' => __trans('UK government body'), 'CRC' => __trans('UK corporation by royal charter'),
                    'STAT' => __trans('UK statutory body'), 'OTHER' => __trans('Other UK organisation'), 'FCORP' => __trans('Non-UK corporation'),
                    'FOTHER' => __trans('Other non-UK organisation'),
                ]],
                'registration_number' => ['label' => __trans('Company registration number')],
                'trading_name' => ['label' => __trans('Trading name (organisations)')],
            ];
        }

        return match ($tld) {
            '.us' => self::usNexusFields(),
            '.ca' => ['legal_type' => ['label' => __trans('Canadian presence requirement'), 'required' => true, 'options' => [
                'CCT' => __trans('Canadian citizen'), 'RES' => __trans('Permanent resident of Canada'),
                'CCO' => __trans('Corporation (Canada or a Canadian province or territory)'), 'PRT' => __trans('Partnership registered in Canada'),
                'ASS' => __trans('Canadian unincorporated association'), 'TRD' => __trans('Canadian trade union'), 'PLT' => __trans('Canadian political party'),
                'TRS' => __trans('Trust established in Canada'), 'EDU' => __trans('Canadian educational institution'), 'HOP' => __trans('Canadian hospital'),
                'LAM' => __trans('Canadian library, archive or museum'), 'GOV' => __trans('Government or government entity in Canada'),
                'ABO' => __trans('Aboriginal people indigenous to Canada'), 'INB' => __trans('Indian band recognised by the Indian Act of Canada'),
                'LGR' => __trans('Legal representative of a Canadian citizen or permanent resident'), 'OMK' => __trans('Official mark registered in Canada'),
                'TDM' => __trans('Trademark registered in Canada (non-Canadian owner)'), 'MAJ' => __trans('His Majesty the King'),
            ]]],
            '.fr' => [
                'registrant_type' => ['label' => __trans('Registrant type'), 'required' => true, 'options' => ['individual' => __trans('Individual'), 'organization' => __trans('Organisation')]],
                'registrant_vat_id' => ['label' => __trans('VAT number (organisations)')],
                'siren_siret' => ['label' => __trans('SIREN or SIRET number (organisations in France)')],
                'trademark_number' => ['label' => __trans('Trademark number')],
            ],
            '.it' => [
                'entity_type' => ['label' => __trans('Registrant type'), 'required' => true, 'options' => [
                    '1' => __trans('Person (Italian or foreign)'), '2' => __trans('Italian company or one-person company'), '3' => __trans('Italian freelancer or professional'),
                    '4' => __trans('Italian non-profit organisation'), '5' => __trans('Italian public organisation'), '6' => __trans('Other Italian organisation'),
                    '7' => __trans('Foreign organisation (EU, not Italy)'),
                ]],
                'reg_code' => ['label' => __trans('Codice Fiscale, VAT number or ID document number'), 'required' => true],
            ],
            '.law', '.abogado' => [
                'qli_accreditation_id' => ['label' => __trans('Lawyer accreditation ID'), 'required' => true],
                'qli_accreditation_body' => ['label' => __trans('Accreditation body'), 'required' => true],
                'qli_jurisdiction_country' => ['label' => __trans('Accreditation country'), 'required' => true, 'options' => Countries::getNames()],
                'qli_jurisdiction_state' => ['label' => __trans('Accreditation state or province')],
                'qli_accreditation_year' => ['label' => __trans('Accreditation year'), 'required' => true],
            ],
            '.xxx' => ['icm_membership_id' => ['label' => __trans('ICM membership ID (Sponsored Community members)')]],
            default => [],
        };
    }

    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        $this->asked($domain);
        $lookup = $this->request('lookup', ['domain' => $domain->getName()]);
        $available = ($lookup['status'] ?? '') === 'available';
        if ($available && ($lookup['reason'] ?? '') === 'Premium Name') {
            throw new Registrar_Exception('Premium domains cannot be registered.');
        }

        return $available;
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        $this->asked($domain);
        $lookup = $this->request('lookup', ['domain' => $domain->getName()]);
        // A premium name costs more than the TLD's transfer price.
        if (($lookup['reason'] ?? '') === 'Premium Name') {
            throw new Registrar_Exception('Premium domains cannot be transferred.');
        }

        try {
            return ($this->request('check_transfer', ['domain' => $domain->getName()])['transferrable'] ?? '0') === '1';
        } catch (Registrar_Exception $e) {
            // Not every registry answers check_transfer: a taken name is then a transfer candidate (as Blesta).
            $this->getLog()->warn(sprintf('OpenSRS check_transfer for %s failed, using the lookup: %s', $domain->getName(), $e->getMessage()));

            return ($lookup['status'] ?? '') === 'taken';
        }
    }

    public function modifyNs(Registrar_Domain $domain): bool
    {
        $hosts = $this->hosts($domain);
        if (count($hosts) < 2) {
            throw new Registrar_Exception('At least two name servers are required.');
        }
        $this->notDk($domain);
        $this->request('advanced_update_nameservers', ['domain' => $domain->getName(), 'op_type' => 'assign', 'assign_ns' => $hosts]);

        return true;
    }

    public function modifyContact(Registrar_Domain $domain): bool
    {
        $this->notDk($domain);
        $contacts = $this->contacts($domain);
        // .it: the owner carries the registry's identification again (the API's contact_info rules).
        if ($domain->getTld() === '.it') {
            $contacts['owner'] += $this->tldData($domain, (string) ($contacts['owner']['country'] ?? ''))['tld_data']['it_registrant_info'];
        }
        $this->request('modify', ['domain' => $domain->getName(), 'data' => 'contact_info', 'affect_domains' => 0, 'contact_set' => $contacts]);

        return true;
    }

    public function transferDomain(Registrar_Domain $domain): bool
    {
        $hosts = $this->hosts($domain);
        // The code is optional: .uk transfers have none (the owner changes the IPS tag instead).
        $this->order($domain, 'transfer', array_filter(['auth_info' => trim((string) $domain->getEpp())])
            + (count($hosts) >= 2 ? ['custom_transfer_nameservers' => 1, 'nameserver_list' => $this->nameserverList($hosts)] : ['custom_transfer_nameservers' => 0]));

        return true;
    }

    public function getTransferStatus(Registrar_Domain $domain): array
    {
        $reply = $this->request('check_transfer', ['domain' => $domain->getName(), 'check_status' => 1]);
        $status = (string) ($reply['status'] ?? '');

        return ['status' => match ($status) {
            'completed' => Registrar_TransferStatus::COMPLETED,
            'cancelled' => Registrar_TransferStatus::FAILED,
            default => Registrar_TransferStatus::PENDING,
        }, 'message' => trim($status . ' ' . (is_string($reply['reason'] ?? null) ? $reply['reason'] : ''))];
    }

    public function getDomainDetails(Registrar_Domain $domain): Registrar_Domain
    {
        $info = $this->info($domain);
        $status = $this->request('get', ['domain' => $domain->getName(), 'type' => 'status']);
        $privacy = $this->request('get', ['domain' => $domain->getName(), 'type' => 'whois_privacy_state']);

        $details = new Registrar_Domain();
        $details->setSld($domain->getSld());
        $details->setTld($domain->getTld());
        $details->setRegistrationTime(strtotime((string) ($info['registry_createdate'] ?? '')) ?: null);
        $details->setExpirationTime(strtotime((string) ($info['expiredate'] ?? '')) ?: $domain->getExpirationTime());
        $details->setLocked(($status['lock_state'] ?? '0') === '1');
        $details->setPrivacyEnabled(($privacy['state'] ?? '') === 'enabled');
        $details->setEpp($domain->getEpp());
        $i = 1;
        foreach (is_array($info['nameserver_list'] ?? null) ? $info['nameserver_list'] : [] as $ns) {
            $host = strtolower(trim((string) (is_array($ns) ? ($ns['name'] ?? '') : '')));
            if ($i <= 4 && $host !== '') {
                $details->{'setNs' . $i++}($host);
            }
        }
        // OpenSRS shows its privacy service as the contact when privacy is on: keep ClientFence's copy.
        if ($domain->getContactRegistrar() instanceof Registrar_Domain_Contact) {
            foreach (['Registrar', 'Admin', 'Tech', 'Billing'] as $role) {
                $details->{'setContact' . $role}($domain->getContactRegistrar());
            }
        }

        return $details;
    }

    public function getEpp(Registrar_Domain $domain): string
    {
        $this->request('send_authcode', ['domain_name' => $domain->getName()]);

        // OpenSRS does not return the code here: it emails it to the domain's administrative contact.
        return __trans('OpenSRS has emailed the transfer code to the domain\'s administrative contact.');
    }

    public function registerDomain(Registrar_Domain $domain): bool
    {
        $hosts = $this->hosts($domain);
        if (count($hosts) < 2) {
            throw new Registrar_Exception('At least two name servers are required.');
        }
        $this->order($domain, 'new', [
            'period' => min(10, max(1, (int) $domain->getRegistrationPeriod())),
            'custom_nameservers' => 1,
            'nameserver_list' => $this->nameserverList($hosts),
            'f_whois_privacy' => $domain->getPrivacyEnabled() ? 1 : 0,
        ]);

        return true;
    }

    public function renewDomain(Registrar_Domain $domain): bool
    {
        // OpenSRS refuses a renewal whose current expiry year differs from the registry's: read it there (as Blesta).
        $year = substr((string) ($this->info($domain)['expiredate'] ?? ''), 0, 4);
        $this->request('renew', [
            'domain' => $domain->getName(),
            'handle' => 'process',
            'auto_renew' => 0,
            'currentexpirationyear' => ctype_digit($year) ? $year : date('Y', (int) $domain->getExpirationTime() ?: time()),
            'period' => max(1, (int) $domain->getRegistrationPeriod()),
        ]);

        return true;
    }

    public function deleteDomain(Registrar_Domain $domain): bool
    {
        // As Blesta: a cancelled domain is set to expire unrenewed, never deleted (revoked) at the registry.
        // 220: the domain was already set so.
        try {
            $this->request('modify', ['domain' => $domain->getName(), 'data' => 'expire_action', 'affect_domains' => 0, 'auto_renew' => 0, 'let_expire' => 1], [220]);
        } catch (Registrar_Exception $e) {
            // A domain no longer at OpenSRS (moved away, failed transfer, deleted after expiry) must still be cancellable.
            $this->getLog()->warn(sprintf('OpenSRS did not set %s to expire: %s', $domain->getName(), $e->getMessage()));
        }

        return true;
    }

    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->request('modify', ['domain' => $domain->getName(), 'data' => 'whois_privacy_state', 'affect_domains' => 0, 'state' => 'enable']);

        return true;
    }

    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->request('modify', ['domain' => $domain->getName(), 'data' => 'whois_privacy_state', 'affect_domains' => 0, 'state' => 'disable']);

        return true;
    }

    public function lock(Registrar_Domain $domain): bool
    {
        $this->request('modify', ['domain' => $domain->getName(), 'data' => 'status', 'affect_domains' => 0, 'lock_state' => 1]);

        return true;
    }

    public function unlock(Registrar_Domain $domain): bool
    {
        $this->request('modify', ['domain' => $domain->getName(), 'data' => 'status', 'affect_domains' => 0, 'lock_state' => 0]);

        return true;
    }

    /**
     * The one-year cost in USD (get_price for reg_type new, renewal and transfer, as Blesta), PRICE_SLICE TLDs per
     * call. A TLD whose register and renew costs both fail is left out.
     */
    public function getTldPrices(array $tlds = []): array
    {
        if ($tlds === []) {
            $tlds = array_map(fn (string $tld) => '.' . $tld, explode(' ', trim(self::TLDS)));
        }
        $tlds = array_values(array_diff($tlds, self::UNASKED));
        // shortcut: one request at a time (15 per slice); send a slice's requests together if OpenSRS proves slow.
        $now = array_slice($tlds, 0, self::PRICE_SLICE);
        $prices = [];
        foreach ($now as $tld) {
            $row = [];
            foreach (['register' => 'new', 'renew' => 'renewal', 'transfer' => 'transfer'] as $action => $type) {
                $row[$action] = $this->price($tld, $type);
            }
            if ($row['register'] !== null || $row['renew'] !== null) {
                $prices[$tld] = $row;
            }
        }

        return ['currency' => 'USD', 'prices' => $prices, 'remaining' => array_slice($tlds, count($now))];
    }

    private function price(string $tld, string $type): ?float
    {
        try {
            $price = $this->request('get_price', ['domain' => 'clientfencepricecheck' . $tld, 'period' => 1, 'reg_type' => $type])['price'] ?? null;
        } catch (Registrar_Exception) {
            return null;
        }

        return is_numeric($price) && (float) $price > 0 ? (float) $price : null;
    }

    /** A registration or transfer (sw_register): the client's contact for all four roles, no OpenSRS auto-renewal. */
    private function order(Registrar_Domain $domain, string $type, array $params): void
    {
        $contacts = $this->contacts($domain);
        $tld = $domain->getTld();
        // These registries mail the owner to confirm (.be also for new names).
        if ($tld === '.be' || ($type === 'transfer' && in_array($tld, ['.de', '.eu', '.it'], true))) {
            $params['owner_confirm_address'] = $contacts['owner']['email'] ?? '';
        }
        $reply = $this->request('sw_register', [
            'domain' => $domain->getName(),
            'reg_type' => $type,
            'handle' => 'process',
            'auto_renew' => 0,
            'custom_tech_contact' => 1,
            // The registrant's OpenSRS profile login (3-20 letters and digits; password 10-20), never used by ClientFence.
            'reg_username' => 'usr' . ($this->_order->client_id ?? random_int(10000, 99999)),
            'reg_password' => bin2hex(random_bytes(8)),
            'contact_set' => $contacts,
        ] + $params + $this->tldData($domain, $contacts['owner']['country'] ?? ''));
        // OpenSRS puts an order it cannot process (account funds, a taken name) in its pending queue instead of failing it.
        if (!empty($reply['forced_pending'])) {
            throw new Registrar_Exception(':registrar refused the request: :error', [':registrar' => 'OpenSRS', ':error' => is_string($reply['error'] ?? null) ? $reply['error'] : 'forced_pending']);
        }
        $this->getLog()->info(sprintf('OpenSRS %s order %s for %s', $type, is_string($reply['id'] ?? null) ? $reply['id'] : '', $domain->getName()));
    }

    /** The order's registry details, placed where OpenSRS's TLD data guide puts them for each TLD. */
    private function tldData(Registrar_Domain $domain, string $country): array
    {
        $f = $domain->getFields();
        $pick = fn (array $keys): array => array_filter(array_map(fn ($v) => trim((string) $v), array_intersect_key($f, array_flip($keys))), fn (string $v) => $v !== '');
        $tld = $domain->getTld();

        return match (true) {
            $tld === '.us' => ['tld_data' => ['nexus' => array_filter([
                'category' => $f['nexus_category'] ?? '',
                'app_purpose' => $f['app_purpose'] ?? '',
                'validator' => in_array($f['nexus_category'] ?? '', ['C31', 'C32'], true) ? strtoupper((string) ($f['nexus_country'] ?? '')) : '',
            ])]],
            $tld === '.ca' => ['registrant_extra_info' => $pick(['legal_type'])],
            in_array($tld, self::UK_TLDS, true) => ['tld_data' => ['registrant_extra_info' => $pick(['registrant_type', 'registration_number', 'trading_name'])]],
            $tld === '.fr' => ['tld_data' => ['registrant_extra_info' => $pick(['registrant_type', 'registrant_vat_id', 'siren_siret', 'trademark_number'])]],
            // The registry wants IT as nationality for Italian organisations (types 2-6), the owner's for persons and foreign ones.
            $tld === '.it' => ['tld_data' => ['it_registrant_info' => $pick(['entity_type', 'reg_code'])
                + ['nationality_code' => in_array($f['entity_type'] ?? '', ['1', '7'], true) ? $country : 'IT']]],
            in_array($tld, ['.law', '.abogado'], true) => ['tld_data' => ['registrant_extra_info' => $pick(['qli_accreditation_id', 'qli_accreditation_body', 'qli_jurisdiction_country', 'qli_jurisdiction_state', 'qli_accreditation_year'])]],
            $tld === '.xxx' => ['tld_data' => ['ipr_data' => $pick(['icm_membership_id'])]],
            default => [],
        };
    }

    private function info(Registrar_Domain $domain): array
    {
        return $this->request('get', ['domain' => $domain->getName(), 'type' => 'all_info']);
    }

    /**
     * One API call; returns the reply's attributes. A reply without is_success 1 (or one of $ok response codes) is a
     * registrar error with OpenSRS's text.
     */
    private function request(string $action, array $attributes, array $ok = []): array
    {
        $xml = "<?xml version='1.0' encoding='UTF-8' standalone='no' ?><!DOCTYPE OPS_envelope SYSTEM 'ops.dtd'><OPS_envelope><header><version>0.9</version></header><body><data_block>"
            . self::encode(['protocol' => 'XCP', 'action' => strtoupper($action), 'object' => 'DOMAIN', 'attributes' => $attributes])
            . '</data_block></body></OPS_envelope>';
        $key = $this->config['api_key'];

        try {
            $body = $this->getHttpClient()->request('POST', $this->_testMode ? self::TEST_URL : self::LIVE_URL, [
                'headers' => ['Content-Type' => 'text/xml', 'X-Username' => $this->config['username'], 'X-Signature' => md5(md5($xml . $key) . $key)],
                'body' => $xml,
                'timeout' => 60,
            ])->getContent(false);
        } catch (ExceptionInterface $e) {
            $this->getLog()->err('OpenSRS ' . $action . ' HTTP error: ' . $e->getMessage());

            throw new Registrar_Exception('Could not reach :registrar: :error', [':registrar' => 'OpenSRS', ':error' => $action]);
        }

        $reply = @simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        $data = $reply instanceof SimpleXMLElement && isset($reply->body->data_block) ? self::decode($reply->body->data_block) : null;
        if (!is_array($data) || !isset($data['is_success'])) {
            // Usually the server's IP address is not allowed under IP Access Rules.
            throw new Registrar_Exception('OpenSRS returned an unreadable response for :command', [':command' => $action]);
        }
        $attributes = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];
        if ($data['is_success'] !== '1' && !in_array((int) ($data['response_code'] ?? 0), $ok, true)) {
            $error = (is_string($attributes['error'] ?? null) && $attributes['error'] !== '' ? $attributes['error'] : null) ?? (string) (is_string($data['response_text'] ?? null) ? $data['response_text'] : ($data['response_code'] ?? ''));
            $this->getLog()->err(sprintf('OpenSRS %s refused: %s %s', $action, is_string($data['response_code'] ?? null) ? $data['response_code'] : '', $error));

            throw new Registrar_Exception(':registrar refused the request: :error', [':registrar' => 'OpenSRS', ':error' => $error]);
        }

        return $attributes;
    }

    /** PHP array to OPS data: a list becomes a dt_array, a map a dt_assoc; arrays with nothing in them are left out. */
    private static function encode(array $data): string
    {
        $tag = array_is_list($data) ? 'dt_array' : 'dt_assoc';
        $xml = '<' . $tag . '>';
        foreach ($data as $key => $value) {
            $inner = is_array($value) ? self::encode($value) : htmlspecialchars((string) $value, ENT_XML1);
            if (!is_array($value) || str_contains($inner, '<item')) {
                $xml .= '<item key="' . htmlspecialchars((string) $key, ENT_XML1 | ENT_QUOTES) . '">' . $inner . '</item>';
            }
        }

        return $xml . '</' . $tag . '>';
    }

    /** OPS data to PHP: a dt_assoc or dt_array becomes an array keyed by the items' keys, a plain item its text. */
    private static function decode(SimpleXMLElement $node): array|string
    {
        $list = $node->dt_assoc->count() > 0 ? $node->dt_assoc : $node->dt_array;
        if ($list->count() === 0) {
            return trim((string) $node);
        }
        $out = [];
        foreach ($list->item as $item) {
            $out[(string) $item['key']] = self::decode($item);
        }

        return $out;
    }

    private function hosts(Registrar_Domain $domain): array
    {
        return array_values(array_filter(array_map(fn ($ns) => trim((string) $ns), [$domain->getNs1(), $domain->getNs2(), $domain->getNs3(), $domain->getNs4()])));
    }

    private function nameserverList(array $hosts): array
    {
        return array_map(fn (string $host, int $i) => ['name' => $host, 'sortorder' => $i + 1], $hosts, array_keys($hosts));
    }

    /** The client's contact as OpenSRS's contact set (owner, admin, billing, tech). */
    private function asked(Registrar_Domain $domain): void
    {
        if (in_array(strtolower((string) $domain->getTld()), self::UNASKED, true)) {
            throw new Registrar_Exception(':tld domains need registry details ClientFence does not ask for: register them at :registrar directly.', [':tld' => $domain->getTld(), ':registrar' => 'OpenSRS']);
        }
    }

    private function notDk(Registrar_Domain $domain): void
    {
        // OpenSRS changes .dk name servers and contacts only with update_all_info.
        if ($domain->getTld() === '.dk') {
            throw new Registrar_Exception('Change the name servers and contacts of .dk domains in the OpenSRS control panel.');
        }
    }

    private function contacts(Registrar_Domain $domain): array
    {
        $c = $domain->getContactRegistrar();
        if (!$c instanceof Registrar_Domain_Contact) {
            throw new Registrar_Exception('The registrant contact is missing.');
        }
        $cc = (string) preg_replace('/\D+/', '', (string) $c->getTelCc());
        $tel = (string) preg_replace('/\D+/', '', (string) $c->getTel());
        // +CC.NUMBER; the international form drops the national trunk 0 (Italian numbers keep it).
        $phone = '+' . $cc . '.' . ($cc === '39' ? $tel : ltrim($tel, '0'));
        $contact = array_filter([
            'first_name' => (string) $c->getFirstName(),
            'last_name' => (string) $c->getLastName(),
            // .uk: OpenSRS wants the individual's full name here when there is no company.
            'org_name' => trim((string) $c->getCompany()) !== '' || !in_array($domain->getTld(), self::UK_TLDS, true) ? (string) $c->getCompany() : trim($c->getFirstName() . ' ' . $c->getLastName()),
            'address1' => (string) $c->getAddress1(),
            'address2' => (string) $c->getAddress2(),
            'city' => (string) $c->getCity(),
            'state' => (string) $c->getState(),
            'postal_code' => (string) $c->getZip(),
            'country' => strtoupper((string) $c->getCountry()),
            'phone' => $phone,
            'email' => (string) $c->getEmail(),
        ], fn (string $value) => trim($value) !== '');

        return array_fill_keys(['owner', 'admin', 'billing', 'tech'], $contact);
    }
}
