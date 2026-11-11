<?php

declare(strict_types=1);
/**
 * Dynadot registrar adapter for FOSSBilling (ClientFence).
 *
 * Uses the Dynadot API3 JSON interface (https://www.dynadot.com/domain/api3.html):
 * GET https://api.dynadot.com/api3.json?key=...&command=...
 * The registrar's test mode switches to api-sandbox.dynadot.com with the sandbox key.
 *
 * Dynadot keeps WHOIS data as reusable contact records, so registrations and contact
 * changes create a contact from the FOSSBilling registrant and assign it to all four
 * roles. Dynadot only accepts name servers that exist in the account, so the adapter
 * adds them first.
 *
 * Install: copy to library/Registrar/Adapter/Dynadot.php in the FOSSBilling root.
 */
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

class Registrar_Adapter_Dynadot extends Registrar_AdapterAbstract implements Registrar_TldPrices
{
    private const LIVE_URL = 'https://api.dynadot.com/api3.json';
    private const SANDBOX_URL = 'https://api-sandbox.dynadot.com/api3.json';

    public $config = [
        'api_key' => null,
        'sandbox_api_key' => null,
        'currency' => 'USD',
    ];

    public function __construct($options)
    {
        if (empty($options['api_key']) && empty($options['sandbox_api_key'])) {
            throw new Registrar_Exception('The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing', [':domain_registrar' => 'Dynadot', ':missing' => 'API key'], 3001);
        }

        $this->config['api_key'] = trim((string) ($options['api_key'] ?? ''));
        $this->config['sandbox_api_key'] = trim((string) ($options['sandbox_api_key'] ?? ''));
        if (!empty($options['currency'])) {
            $this->config['currency'] = strtoupper(trim((string) $options['currency']));
        }
    }

    public static function getConfig(): array
    {
        return [
            'label' => __trans('Manages domains on Dynadot through API3. Add this server\'s IP address to the API allow list in Dynadot (Tools > API) and keep enough account balance for registrations and renewals.'),
            'form' => [
                'api_key' => ['password', [
                    'label' => __trans('API key'),
                    'description' => __trans('Production key from Dynadot: Tools > API.'),
                    'required' => false,
                    'secret' => true,
                ]],
                'sandbox_api_key' => ['password', [
                    'label' => __trans('Sandbox API key'),
                    'description' => __trans('Separate key for api-sandbox.dynadot.com, used only while test mode is on.'),
                    'required' => false,
                    'secret' => true,
                ]],
                'currency' => ['text', [
                    'label' => __trans('Currency'),
                    'description' => __trans('Currency for Dynadot orders, for example USD.'),
                    'required' => false,
                ]],
            ],
        ];
    }

    public static function getSecretFields(): array
    {
        return ['api_key', 'sandbox_api_key'];
    }

    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        $result = $this->search($domain);

        if (strtolower((string) ($result['Available'] ?? '')) !== 'yes') {
            return false;
        }

        // e.g. "Registration Price: 10.88 in USD and ... Domain is not a Premium Domain"
        $price = strtolower((string) ($result['Price'] ?? ''));
        if (str_contains($price, 'premium') && !preg_match('/\bnot (a )?premium\b/', $price)) {
            throw new Registrar_Exception('Premium domains cannot be registered.');
        }

        return true;
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        // A registered name is what can be transferred; the registry checks the code.
        return strtolower((string) ($this->search($domain)['Available'] ?? '')) === 'no';
    }

    public function modifyNs(Registrar_Domain $domain): bool
    {
        $nameservers = $this->nameservers($domain);
        if (count($nameservers) < 2) {
            throw new Registrar_Exception('At least two name servers are required.');
        }

        $this->ensureNameservers($nameservers);
        $params = ['domain' => $domain->getName()];
        foreach ($nameservers as $i => $host) {
            $params['ns' . $i] = $host;
        }
        $this->request('set_ns', $params);

        return true;
    }

    public function modifyContact(Registrar_Domain $domain): bool
    {
        $contactId = $this->createContact($this->registrantOf($domain));
        $this->request('set_whois', [
            'domain' => $domain->getName(),
            'registrant_contact' => $contactId,
            'admin_contact' => $contactId,
            'technical_contact' => $contactId,
            'billing_contact' => $contactId,
        ]);

        return true;
    }

    public function transferDomain(Registrar_Domain $domain): bool
    {
        $epp = trim((string) $domain->getEpp());
        if ($epp === '') {
            throw new Registrar_Exception('The transfer authorization code is required.');
        }

        $params = [
            'domain' => $domain->getName(),
            'auth' => $epp,
            'currency' => $this->config['currency'],
        ];
        $nameservers = $this->nameservers($domain);
        if (count($nameservers) >= 2) {
            $params['name_servers'] = implode(',', $nameservers);
        }
        if ($domain->getContactRegistrar() instanceof Registrar_Domain_Contact) {
            $contactId = $this->createContact($domain->getContactRegistrar());
            foreach (['registrant_contact', 'admin_contact', 'technical_contact', 'billing_contact'] as $role) {
                $params[$role] = $contactId;
            }
        }

        $this->request('transfer', $params);

        return true;
    }

    public function getDomainDetails(Registrar_Domain $domain): Registrar_Domain
    {
        $info = $this->request('domain_info', ['domain' => $domain->getName()])['DomainInfo'] ?? null;
        if (!is_array($info)) {
            throw new Registrar_Exception('Dynadot returned no details for :domain', [':domain' => $domain->getName()]);
        }

        $details = new Registrar_Domain();
        $details->setSld($domain->getSld());
        $details->setTld($domain->getTld());
        $details->setRegistrationTime($this->seconds($info['Registration'] ?? null));
        $details->setExpirationTime($this->seconds($info['Expiration'] ?? null));
        $details->setLocked(strtolower((string) ($info['Locked'] ?? '')) === 'yes');
        $details->setPrivacyEnabled(in_array(strtolower((string) ($info['Privacy'] ?? 'none')), ['full', 'partial'], true));
        $details->setEpp($domain->getEpp());

        // NsContent is {"Host0": "...", "Host1": "...", "NsName": "Name Servers"}.
        $nameservers = [];
        foreach ((array) ($this->request('get_ns', ['domain' => $domain->getName()])['NsContent'] ?? []) as $field => $host) {
            if (preg_match('/^Host(\d+)$/', (string) $field, $m) && is_string($host) && $host !== '') {
                $nameservers[(int) $m[1]] = $host;
            }
        }
        ksort($nameservers);
        foreach (array_slice(array_values($nameservers), 0, 4) as $i => $host) {
            $details->{'setNs' . ($i + 1)}(strtolower($host));
        }

        $contact = new Registrar_Domain_Contact();
        $contactId = $info['Whois']['Registrant']['ContactId'] ?? null;
        if ($contactId !== null && $contactId !== '') {
            $contact = $this->contactFromDynadot($this->request('get_contact', ['contact_id' => $contactId])['GetContact'] ?? []);
        }
        // The registrar may withhold the record; keep FOSSBilling's copy in that case.
        if ($contact->getEmail() == '' && $domain->getContactRegistrar() instanceof Registrar_Domain_Contact) {
            $contact = $domain->getContactRegistrar();
        }
        foreach (['Registrar', 'Admin', 'Tech', 'Billing'] as $role) {
            $details->{'setContact' . $role}($contact);
        }

        return $details;
    }

    public function getEpp(Registrar_Domain $domain): string
    {
        $code = (string) ($this->request('get_transfer_auth_code', ['domain' => $domain->getName()])['AuthCode'] ?? '');
        if ($code === '') {
            throw new Registrar_Exception('Dynadot returned no transfer code. Unlock the domain first.');
        }

        return $code;
    }

    public function registerDomain(Registrar_Domain $domain): bool
    {
        $contactId = $this->createContact($this->registrantOf($domain));
        $this->request('register', [
            'domain' => $domain->getName(),
            'duration' => max(1, (int) $domain->getRegistrationPeriod()),
            'currency' => $this->config['currency'],
            'registrant_contact' => $contactId,
            'admin_contact' => $contactId,
            'technical_contact' => $contactId,
            'billing_contact' => $contactId,
        ]);

        // Dynadot registers with the account defaults; apply the order's name servers.
        if (count($this->nameservers($domain)) >= 2) {
            try {
                $this->modifyNs($domain);
            } catch (Registrar_Exception $e) {
                $this->getLog()->err('Dynadot: ' . $domain->getName() . ' registered, but setting name servers failed: ' . $e->getMessage());
            }
        }

        return true;
    }

    public function renewDomain(Registrar_Domain $domain): bool
    {
        $this->request('renew', [
            'domain' => $domain->getName(),
            'duration' => max(1, (int) $domain->getRegistrationPeriod()),
            'currency' => $this->config['currency'],
            // Never pay a redemption or late fee automatically; those need a person.
            'no_renew_if_late_renew_fee_needed' => 1,
        ]);

        return true;
    }

    public function deleteDomain(Registrar_Domain $domain): bool
    {
        // Dynadot only deletes registrations that are still in the add grace period.
        $this->request('delete', ['domain' => $domain->getName()]);

        return true;
    }

    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->request('set_privacy', ['domain' => $domain->getName(), 'option' => 'full', 'whois_privacy_option' => 'yes']);
        $domain->setPrivacyEnabled(true);

        return true;
    }

    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->request('set_privacy', ['domain' => $domain->getName(), 'option' => 'off', 'whois_privacy_option' => 'no']);
        $domain->setPrivacyEnabled(false);

        return true;
    }

    public function lock(Registrar_Domain $domain): bool
    {
        $this->request('lock_domain', ['domain' => $domain->getName()]);
        $domain->setLocked(true);

        return true;
    }

    public function unlock(Registrar_Domain $domain): bool
    {
        // API3 has no plain unlock command; Dynadot unlocks through the auth code request,
        // which needs the "API skip lock" agreement on the Dynadot account.
        $this->request('get_transfer_auth_code', ['domain' => $domain->getName(), 'unlock_domain_for_transfer' => 1]);
        $domain->setLocked(false);

        return true;
    }

    public function isTestEnv(): bool
    {
        return $this->_testMode;
    }

    /**
     * Whether the API answers with this key (ClientFence system health): the read-only
     * account_info command. Throws Registrar_Exception when it does not.
     */
    public function testConnection(): bool
    {
        $this->request('account_info');

        return true;
    }

    /** The account's one-year price per action (tld_price, at the account's price level) in the configured currency. */
    public function getTldPrices(array $tlds = []): array
    {
        $result = $this->request('tld_price', ['currency' => $this->config['currency']]);
        $year = static fn (mixed $p): ?float => is_numeric($p) ? (float) $p : null;
        $prices = [];
        foreach (is_array($result['TldPrice'] ?? null) ? $result['TldPrice'] : [] as $row) {
            $price = is_array($row['Price'] ?? null) ? $row['Price'] : [];
            $prices['.' . strtolower(ltrim((string) ($row['Tld'] ?? ''), '.'))] = [
                'register' => $year($price['Register'] ?? null),
                'renew' => $year($price['Renew'] ?? null),
                'transfer' => $year($price['Transfer'] ?? null),
            ];
        }
        if ($tlds !== []) {
            $prices = array_intersect_key($prices, array_flip($tlds));
        }
        $currency = strtoupper(trim((string) ($result['Currency'] ?? '')));

        return ['currency' => $currency !== '' ? $currency : $this->config['currency'], 'prices' => $prices, 'remaining' => []];
    }

    /**
     * Calls one API3 command and returns the body of its "<Command>Response" object.
     *
     * @throws Registrar_Exception when the request fails or Dynadot reports an error
     */
    protected function request(string $command, array $params = []): array
    {
        $key = $this->isTestEnv() ? $this->config['sandbox_api_key'] : $this->config['api_key'];
        if (empty($key)) {
            throw new Registrar_Exception('The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing', [':domain_registrar' => 'Dynadot', ':missing' => $this->isTestEnv() ? 'sandbox API key' : 'API key'], 3001);
        }

        $url = $this->isTestEnv() ? self::SANDBOX_URL : self::LIVE_URL;
        $this->getLog()->debug('Dynadot request: ' . $command . ' ' . json_encode($params));

        try {
            $response = $this->getHttpClient()->request('GET', $url, [
                'query' => ['key' => $key, 'command' => $command, ...$params],
                'timeout' => 60,
            ]);
            $body = $response->getContent();
        } catch (ExceptionInterface $e) {
            // The message can contain the request URL, which carries the key.
            $this->getLog()->err('Dynadot ' . $command . ' HTTP error: ' . str_replace($key, '***', $e->getMessage()));

            throw new Registrar_Exception('Could not reach Dynadot for :command', [':command' => $command]);
        }

        // Auth codes are secret; the price list is hundreds of kilobytes.
        if (!in_array($command, ['get_transfer_auth_code', 'tld_price'], true)) {
            $this->getLog()->debug('Dynadot response: ' . $command . ' ' . $body);
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || $decoded === []) {
            throw new Registrar_Exception('Dynadot returned an unreadable response for :command', [':command' => $command]);
        }

        // Every reply is wrapped in one object, e.g. {"RegisterResponse": {...}}.
        $result = reset($decoded);
        if (!is_array($result)) {
            throw new Registrar_Exception('Dynadot returned an unreadable response for :command', [':command' => $command]);
        }

        $code = (string) ($result['ResponseCode'] ?? $result['SuccessCode'] ?? '');
        $status = strtolower((string) ($result['Status'] ?? ''));
        if ($code !== '0' || $status === 'error' || $status === 'failure') {
            $error = (string) ($result['Error'] ?? $result['Status'] ?? 'unknown error');
            $this->getLog()->err('Dynadot ' . $command . ' failed: ' . $error);

            throw new Registrar_Exception('Dynadot :command failed: :error', [':command' => $command, ':error' => $error]);
        }

        return $result;
    }

    /**
     * Returns the search result row for one domain.
     */
    private function search(Registrar_Domain $domain): array
    {
        $result = $this->request('search', ['domain0' => $domain->getName(), 'show_price' => 1, 'currency' => $this->config['currency']]);
        $row = $result['SearchResults'][0] ?? null;
        if (!is_array($row)) {
            throw new Registrar_Exception('Dynadot returned no search result for :domain', [':domain' => $domain->getName()]);
        }
        if (!empty($row['Error'])) {
            throw new Registrar_Exception('Dynadot search failed: :error', [':error' => (string) $row['Error']]);
        }

        return $row;
    }

    /**
     * Name servers of the order in order, lower case, without blanks or duplicates.
     *
     * @return string[]
     */
    private function nameservers(Registrar_Domain $domain): array
    {
        $hosts = [];
        foreach ([$domain->getNs1(), $domain->getNs2(), $domain->getNs3(), $domain->getNs4()] as $host) {
            $host = strtolower(rtrim(trim((string) $host), '.'));
            if ($host !== '' && !in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    /**
     * Adds name server hosts to the Dynadot account so set_ns accepts them.
     *
     * @param string[] $hosts
     */
    private function ensureNameservers(array $hosts): void
    {
        $known = [];
        foreach ((array) ($this->request('server_list')['ServerList'] ?? []) as $server) {
            if (is_array($server) && isset($server['ServerName'])) {
                $known[] = strtolower((string) $server['ServerName']);
            }
        }

        foreach (array_diff($hosts, $known) as $host) {
            try {
                $this->request('add_ns', ['host' => $host]);
            } catch (Registrar_Exception $e) {
                // Most often the host is already there; set_ns reports real problems.
                $this->getLog()->info('Dynadot add_ns ' . $host . ': ' . $e->getMessage());
            }
        }
    }

    private function registrantOf(Registrar_Domain $domain): Registrar_Domain_Contact
    {
        $contact = $domain->getContactRegistrar();
        if (!$contact instanceof Registrar_Domain_Contact) {
            throw new Registrar_Exception('The registrant contact is missing for :domain', [':domain' => $domain->getName()]);
        }

        return $contact;
    }

    /**
     * Creates a Dynadot contact record and returns its ID.
     */
    private function createContact(Registrar_Domain_Contact $c): string
    {
        $name = trim($c->getFirstName() . ' ' . $c->getLastName());
        $params = [
            'name' => $name !== '' ? $name : (string) $c->getName(),
            'email' => (string) $c->getEmail(),
            'phonecc' => preg_replace('/\D+/', '', (string) $c->getTelCc()),
            'phonenum' => preg_replace('/\D+/', '', (string) $c->getTel()),
            'address1' => (string) $c->getAddress1(),
            'city' => (string) $c->getCity(),
            'zip' => (string) $c->getZip(),
            'country' => strtoupper((string) $c->getCountry()),
        ];
        foreach (['organization' => $c->getCompany(), 'address2' => $c->getAddress2(), 'state' => $c->getState()] as $field => $value) {
            if (trim((string) $value) !== '') {
                $params[$field] = (string) $value;
            }
        }

        foreach (['name', 'email', 'phonecc', 'phonenum', 'address1', 'city', 'zip', 'country'] as $field) {
            if (trim((string) $params[$field]) === '') {
                throw new Registrar_Exception('The registrant contact is missing the :field field', [':field' => $field]);
            }
        }

        $id = $this->request('create_contact', $params)['CreateContactContent']['ContactId'] ?? null;
        if ($id === null || $id === '') {
            throw new Registrar_Exception('Dynadot did not return a contact ID.');
        }

        return (string) $id;
    }

    private function contactFromDynadot(array $data): Registrar_Domain_Contact
    {
        $contact = new Registrar_Domain_Contact();
        $name = trim((string) ($data['Name'] ?? ''));
        $parts = preg_split('/\s+/', $name, 2) ?: [''];
        $contact->setName($name);
        $contact->setFirstName($parts[0]);
        $contact->setLastName($parts[1] ?? '');
        $contact->setCompany((string) ($data['Organization'] ?? ''));
        $contact->setEmail((string) ($data['Email'] ?? ''));
        $contact->setTelCc((string) ($data['PhoneCc'] ?? ''));
        $contact->setTel(preg_replace('/\D+/', '', (string) ($data['PhoneNum'] ?? '')));
        $contact->setAddress1((string) ($data['Address1'] ?? ''));
        $contact->setAddress2((string) ($data['Address2'] ?? ''));
        $contact->setCity((string) ($data['City'] ?? ''));
        $contact->setState((string) ($data['State'] ?? ''));
        $contact->setZip((string) ($data['ZipCode'] ?? ''));
        $contact->setCountry(strtoupper((string) ($data['Country'] ?? '')));

        return $contact;
    }

    /**
     * Dynadot reports times in milliseconds; FOSSBilling stores seconds.
     */
    private function seconds(mixed $milliseconds): ?int
    {
        if (!is_numeric($milliseconds) || (int) $milliseconds <= 0) {
            return null;
        }

        return intdiv((int) $milliseconds, 1000);
    }
}
