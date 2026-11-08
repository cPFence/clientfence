<?php

declare(strict_types=1);
/**
 * Openprovider registrar adapter (ClientFence).
 *
 * Based on the OpenProvider adapter for FOSSBilling by Devife (https://github.com/Devife/fossbilling-registrar-openprovider,
 * Apache-2.0), rewritten for ClientFence. Uses the Openprovider REST API (https://docs.openprovider.com):
 * POST /v1beta/auth/login gives a bearer token for the other calls; the registrar's test mode uses the sandbox
 * (http://api.sandbox.openprovider.nl:8480) with a sandbox account. Each registration, transfer and contact change
 * creates an Openprovider customer (contact handle) from the client's details. .us names ask the nexus questions on the
 * order form (getDomainFields()), sent as the customer's extension data.
 */
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

class Registrar_Adapter_Openprovider extends Registrar_AdapterAbstract
{
    private const LIVE_URL = 'https://api.openprovider.eu/v1beta';
    private const SANDBOX_URL = 'http://api.sandbox.openprovider.nl:8480/v1beta';

    public $config = [
        'username' => null,
        'password' => null,
    ];

    private ?string $token = null;

    public function __construct($options)
    {
        if (empty($options['username']) || empty($options['password'])) {
            throw new Registrar_Exception('The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing', [':domain_registrar' => 'Openprovider', ':missing' => 'username and password'], 3001);
        }

        $this->config['username'] = trim((string) $options['username']);
        $this->config['password'] = (string) $options['password'];
    }

    public static function getConfig(): array
    {
        return [
            'label' => 'Manages domains on Openprovider through its API. Use an Openprovider API user, allow this server\'s IP address for it, and keep enough account funds for registrations and renewals. Openprovider auto-renewal is turned off for every domain: ClientFence renews a domain when its renewal invoice is paid. With test mode on, enter the username and password of your Openprovider sandbox account.',
            'form' => [
                'username' => ['text', [
                    'label' => 'Username',
                    'description' => 'Your Openprovider API username.',
                    'required' => true,
                ]],
                'password' => ['password', [
                    'label' => 'Password',
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
        // Openprovider keeps the .us answers on the owner's handle, which registrations and transfers both create.
        return strtolower($tld) === '.us' ? self::usNexusFields() : [];
    }

    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        $result = $this->check($domain);
        if ($result['status'] === 'free' && !empty($result['is_premium'])) {
            throw new Registrar_Exception('Premium domains cannot be registered.');
        }

        return $result['status'] === 'free';
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        $result = $this->check($domain);
        // A premium name costs more than the TLD's transfer price.
        if ($result['status'] === 'active' && !empty($result['is_premium'])) {
            throw new Registrar_Exception('Premium domains cannot be transferred.');
        }

        return $result['status'] === 'active';
    }

    public function modifyNs(Registrar_Domain $domain): bool
    {
        $this->request('PUT', '/domains/' . $this->domainId($domain), ['name_servers' => $this->nameservers($domain)]);

        return true;
    }

    public function modifyContact(Registrar_Domain $domain): bool
    {
        $this->request('PUT', '/domains/' . $this->domainId($domain), $this->handles($domain));

        return true;
    }

    public function transferDomain(Registrar_Domain $domain): bool
    {
        // The code is optional: .uk transfers have none (the owner changes the IPS tag instead).
        $this->request('POST', '/domains/transfer', ['domain' => $this->name($domain)] + array_filter(['auth_code' => trim((string) $domain->getEpp())]) + [
            'autorenew' => 'off',
            'is_private_whois_enabled' => (bool) $domain->getPrivacyEnabled(),
            'name_servers' => $this->nameservers($domain),
        ] + $this->handles($domain));

        return true;
    }

    public function getDomainDetails(Registrar_Domain $domain): Registrar_Domain
    {
        $info = $this->request('GET', '/domains/' . $this->domainId($domain));

        $details = new Registrar_Domain();
        $details->setSld($domain->getSld());
        $details->setTld($domain->getTld());
        $details->setRegistrationTime(strtotime((string) ($info['creation_date'] ?? '')) ?: null);
        $details->setExpirationTime(strtotime((string) ($info['expiration_date'] ?? '')) ?: $domain->getExpirationTime());
        $details->setLocked(!empty($info['is_locked']));
        $details->setPrivacyEnabled(!empty($info['is_private_whois_enabled']));
        $details->setEpp($domain->getEpp());
        $i = 1;
        foreach ($info['name_servers'] ?? [] as $host) {
            if ($i <= 4 && trim((string) ($host['name'] ?? '')) !== '') {
                $details->{'setNs' . $i++}(strtolower(trim((string) $host['name'])));
            }
        }
        // Openprovider returns contact handles, not contacts: keep ClientFence's copy.
        if ($domain->getContactRegistrar() instanceof Registrar_Domain_Contact) {
            foreach (['Registrar', 'Admin', 'Tech', 'Billing'] as $role) {
                $details->{'setContact' . $role}($domain->getContactRegistrar());
            }
        }

        return $details;
    }

    public function getEpp(Registrar_Domain $domain): string
    {
        return (string) ($this->request('GET', '/domains/' . $this->domainId($domain) . '/authcode')['auth_code'] ?? '');
    }

    public function registerDomain(Registrar_Domain $domain): bool
    {
        $this->request('POST', '/domains', [
            'domain' => $this->name($domain),
            'period' => min(10, max(1, (int) $domain->getRegistrationPeriod())),
            'unit' => 'y',
            'autorenew' => 'off',
            'is_private_whois_enabled' => (bool) $domain->getPrivacyEnabled(),
            'name_servers' => $this->nameservers($domain),
        ] + $this->handles($domain));

        return true;
    }

    public function renewDomain(Registrar_Domain $domain): bool
    {
        $this->request('POST', '/domains/' . $this->domainId($domain) . '/renew', ['period' => max(1, (int) $domain->getRegistrationPeriod())]);

        return true;
    }

    public function deleteDomain(Registrar_Domain $domain): bool
    {
        // A cancelled domain is left to expire unrenewed (as WHMCS), never deleted from the registry.
        $this->getLog()->info(sprintf('%s was cancelled; Openprovider keeps it until it expires.', $domain->getName()));

        return true;
    }

    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->request('PUT', '/domains/' . $this->domainId($domain), ['is_private_whois_enabled' => true]);

        return true;
    }

    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->request('PUT', '/domains/' . $this->domainId($domain), ['is_private_whois_enabled' => false]);

        return true;
    }

    public function lock(Registrar_Domain $domain): bool
    {
        $this->request('PUT', '/domains/' . $this->domainId($domain), ['is_locked' => true]);

        return true;
    }

    public function unlock(Registrar_Domain $domain): bool
    {
        $this->request('PUT', '/domains/' . $this->domainId($domain), ['is_locked' => false]);

        return true;
    }

    /** One API call; returns its data. A refusal (code other than 0) is a registrar error with Openprovider's reason. */
    private function request(string $method, string $path, array $body = [], bool $login = false): array
    {
        $options = ['timeout' => 60];
        if (!$login) {
            $options['auth_bearer'] = $this->token ??= (string) ($this->request('POST', '/auth/login', ['username' => $this->config['username'], 'password' => $this->config['password'], 'ip' => '0.0.0.0'], true)['token'] ?? '');
        }
        $options[$method === 'GET' ? 'query' : 'json'] = $body;

        try {
            $content = $this->getHttpClient()->request($method, ($this->_testMode ? self::SANDBOX_URL : self::LIVE_URL) . $path, $options)->getContent(false);
        } catch (ExceptionInterface $e) {
            $this->getLog()->err('Openprovider ' . $method . ' ' . $path . ' HTTP error: ' . $e->getMessage());

            throw new Registrar_Exception('Could not reach :registrar: :error', [':registrar' => 'Openprovider', ':error' => $path]);
        }

        $reply = json_decode($content, true);
        if (!is_array($reply) || !isset($reply['code'])) {
            throw new Registrar_Exception('Openprovider returned an unreadable response for :command', [':command' => $method . ' ' . $path]);
        }
        if ((int) $reply['code'] !== 0) {
            $this->getLog()->err(sprintf('Openprovider %s %s refused: %s %s', $method, $path, $reply['code'], $reply['desc'] ?? ''));

            throw new Registrar_Exception(':registrar refused the request: :error', [':registrar' => 'Openprovider', ':error' => (string) ($reply['desc'] ?? $reply['code'])]);
        }
        if ($login && empty($reply['data']['token'])) {
            throw new Registrar_Exception('Openprovider returned an unreadable response for :command', [':command' => 'auth/login']);
        }

        return is_array($reply['data'] ?? null) ? $reply['data'] : [];
    }

    private function name(Registrar_Domain $domain): array
    {
        return ['name' => $domain->getSld(), 'extension' => ltrim((string) $domain->getTld(), '.')];
    }

    private function check(Registrar_Domain $domain): array
    {
        $result = $this->request('POST', '/domains/check', ['domains' => [$this->name($domain)]])['results'][0] ?? null;
        if (!is_array($result) || !isset($result['status'])) {
            throw new Registrar_Exception('Openprovider returned an unreadable response for :command', [':command' => 'domains/check']);
        }

        return $result;
    }

    private function domainId(Registrar_Domain $domain): int
    {
        foreach ($this->request('GET', '/domains', ['full_name' => $domain->getName(), 'limit' => 10])['results'] ?? [] as $found) {
            if (strcasecmp(($found['domain']['name'] ?? '') . '.' . ($found['domain']['extension'] ?? ''), $domain->getName()) === 0) {
                return (int) $found['id'];
            }
        }

        throw new Registrar_Exception(':domain was not found in the Openprovider account.', [':domain' => $domain->getName()]);
    }

    private function nameservers(Registrar_Domain $domain): array
    {
        $hosts = array_values(array_filter(array_map(fn ($ns) => trim((string) $ns), [$domain->getNs1(), $domain->getNs2(), $domain->getNs3(), $domain->getNs4()])));
        if (count($hosts) < 2) {
            throw new Registrar_Exception('At least two name servers are required.');
        }

        return array_map(fn (string $host, int $i) => ['name' => $host, 'seq_nr' => $i + 1], $hosts, array_keys($hosts));
    }

    /** A new customer handle from the client's contact, used for all four roles. */
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
        // Openprovider requires the house number apart: the last one in line 1 ("12 Main St", "Hauptstr. 12"), else in line 2.
        $pattern = '/^(.*)(?<!\S)(\d+[[:alpha:]]?)(?!\S)(.*)$/su';
        [$line1, $line2] = [trim((string) $c->getAddress1()), trim((string) $c->getAddress2())];
        if (preg_match($pattern, $line1, $m)) {
            $m[3] .= $line2 === '' ? '' : ', ' . $line2;
        } elseif (!preg_match($pattern, implode(', ', array_filter([$line1, $line2])), $m)) {
            throw new Registrar_Exception('Openprovider needs a house number in the client\'s address.');
        }
        [$street, $number] = [trim((string) preg_replace(['/\s+/u', '/ ,/'], [' ', ','], $m[1] . $m[3]), ' ,'), $m[2]];

        $customer = [
            'name' => ['first_name' => (string) $c->getFirstName(), 'last_name' => (string) $c->getLastName()],
            'company_name' => (string) $c->getCompany(),
            'email' => (string) $c->getEmail(),
            'phone' => ['country_code' => '+' . $cc, 'area_code' => substr($tel, 0, 3), 'subscriber_number' => substr($tel, 3)],
            'address' => ['street' => $street, 'number' => $number, 'zipcode' => (string) $c->getZip(), 'city' => (string) $c->getCity(),
                'state' => (string) $c->getState(), 'country' => strtoupper((string) $c->getCountry())],
        ];
        $f = $domain->getFields();
        if ($domain->getTld() === '.us') {
            // Openprovider writes C31 and C32 with the organisation's country: C31/DE.
            $category = (string) ($f['nexus_category'] ?? '');
            if (in_array($category, ['C31', 'C32'], true) && !empty($f['nexus_country'])) {
                $category .= '/' . strtoupper((string) $f['nexus_country']);
            }
            $customer['extension_additional_data'] = [['name' => 'us', 'data' => ['nexus_category' => $category, 'applicant_purpose' => (string) ($f['app_purpose'] ?? '')]]];
        }

        $handle = (string) ($this->request('POST', '/customers', $customer)['handle'] ?? '');
        if ($handle === '') {
            throw new Registrar_Exception('Openprovider did not return a contact handle.');
        }

        return ['owner_handle' => $handle, 'admin_handle' => $handle, 'tech_handle' => $handle, 'billing_handle' => $handle];
    }
}
