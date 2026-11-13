<?php

declare(strict_types=1);
/**
 * ConnectReseller registrar adapter (ClientFence).
 *
 * Uses the ConnectReseller reseller API (https://www.connectreseller.com/integration-options/#api, "Domain API
 * Documentation"): GET https://api.connectreseller.com/ConnectReseller/ESHOP/COMMAND?APIKey=...; each reply carries
 * responseMsg.statusCode (200 = done) and responseData. Domains belong to a ConnectReseller customer: the client's
 * customer is found by email address (ViewClient) or created (AddClient) on the first registration or transfer.
 * .us names ask the nexus questions on the order form (getDomainFields()). ConnectReseller has no test system, so
 * test mode refuses every call.
 */
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

class Registrar_Adapter_Connectreseller extends Registrar_AdapterAbstract implements Registrar_TldPrices, Registrar_TransferStatus
{
    private const URL = 'https://api.connectreseller.com/ConnectReseller/ESHOP/';

    public $config = [
        'api_key' => null,
    ];

    public function __construct($options)
    {
        if (trim((string) ($options['api_key'] ?? '')) === '') {
            throw new Registrar_Exception('The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing', [':domain_registrar' => 'ConnectReseller', ':missing' => 'API key'], 3001);
        }

        $this->config['api_key'] = trim((string) $options['api_key']);
    }

    public static function getConfig(): array
    {
        return [
            'label' => __trans('Manages domains on ConnectReseller through its API. Find the API key in the ConnectReseller reseller panel (Tools > Profile Settings > API) and keep enough account funds for registrations and renewals. Each client gets a ConnectReseller customer account under their email address. ConnectReseller has no test system: keep test mode off.'),
            'form' => [
                'api_key' => ['password', [
                    'label' => __trans('API key'),
                    'description' => __trans('From the ConnectReseller reseller panel: Tools > Profile Settings > API.'),
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
        // ConnectReseller takes the .us answers on domainorder only; TransferOrder has no such parameters.
        return strtolower($tld) === '.us' && $action === 'register' ? self::usNexusFields() : [];
    }

    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        $check = $this->check($domain);
        if ($check['available'] && $check['premium']) {
            throw new Registrar_Exception('Premium domains cannot be registered.');
        }

        return $check['available'];
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        $check = $this->check($domain);
        // A premium name costs more than the TLD's transfer price.
        if (!$check['available'] && $check['premium']) {
            throw new Registrar_Exception('Premium domains cannot be transferred.');
        }

        return !$check['available'];
    }

    public function modifyNs(Registrar_Domain $domain): bool
    {
        $params = ['domainNameId' => $this->info($domain)['domainNameId'], 'websiteName' => $domain->getName()];
        foreach ($this->nameservers($domain) as $i => $host) {
            $params['nameServer' . ($i + 1)] = $host;
        }
        $this->request('UpdateNameServer', $params);

        return true;
    }

    public function modifyContact(Registrar_Domain $domain): bool
    {
        $info = $this->info($domain);
        // A new contact for this domain only: changing the current one would also change the customer's other domains.
        $id = (string) ($this->request('AddRegistrantContact', $this->contact($domain) + ['Id' => $info['customerId'] ?? ''])['responseMsg']['id'] ?? '');
        if ($id === '' || $id === '0') {
            throw new Registrar_Exception('ConnectReseller did not return a contact ID.');
        }
        $this->request('updatecontact', ['domainNameId' => $info['domainNameId'], 'websiteName' => $domain->getName(),
            'registrantContactId' => $id, 'adminContactId' => $id, 'technicalContactId' => $id, 'billingContactId' => $id]);

        return true;
    }

    public function transferDomain(Registrar_Domain $domain): bool
    {
        $this->request('TransferOrder', [
            'OrderType' => 4,
            'Websitename' => $domain->getName(),
            'IsWhoisProtection' => $domain->getPrivacyEnabled() ? 'true' : 'false',
            'Authcode' => trim((string) $domain->getEpp()),
            'Id' => $this->customerId($domain),
        ]);

        return true;
    }

    public function getTransferStatus(Registrar_Domain $domain): array
    {
        $data = $this->request('syncTransfer', ['domainName' => $domain->getName()])['responseData'] ?? [];
        $status = trim((string) ($data['status'] ?? $data['Status'] ?? ''));
        $reason = trim((string) ($data['reason'] ?? $data['Reason'] ?? ''));

        return ['status' => match (true) {
            // The API documents no list of these texts; a stalled transfer (locked, wrong code) can still go through.
            preg_match('/\b(completed?|success(ful)?)\b/i', $status) === 1 => Registrar_TransferStatus::COMPLETED,
            preg_match('/\b(cancell?ed|rejected|failed|denied)\b/i', $status) === 1 => Registrar_TransferStatus::FAILED,
            default => Registrar_TransferStatus::PENDING,
        }, 'message' => trim($status . ' ' . $reason)];
    }

    public function getDomainDetails(Registrar_Domain $domain): Registrar_Domain
    {
        $info = $this->info($domain);
        // Blesta reads the dates as milliseconds since 1970; a date text works too.
        $time = static fn (mixed $v): ?int => is_numeric($v) ? intdiv((int) $v, 1000) : (strtotime((string) $v) ?: null);

        $details = new Registrar_Domain();
        $details->setSld($domain->getSld());
        $details->setTld($domain->getTld());
        $details->setRegistrationTime($time($info['creationDate'] ?? null));
        $details->setExpirationTime($time($info['expirationDate'] ?? null) ?? $domain->getExpirationTime());
        // ConnectReseller's transfer lock is its "theft protection".
        $details->setLocked(!empty($info['isThiefProtected']));
        $details->setPrivacyEnabled(!empty($info['isPrivacyProtection']));
        $details->setEpp($domain->getEpp());
        $i = 1;
        foreach (range(1, 13) as $n) {
            $host = strtolower(trim((string) ($info['nameserver' . $n] ?? '')));
            if ($i <= 4 && $host !== '') {
                $details->{'setNs' . $i++}($host);
            }
        }
        // ConnectReseller returns contact IDs, not contacts: keep ClientFence's copy.
        if ($domain->getContactRegistrar() instanceof Registrar_Domain_Contact) {
            foreach (['Registrar', 'Admin', 'Tech', 'Billing'] as $role) {
                $details->{'setContact' . $role}($domain->getContactRegistrar());
            }
        }

        return $details;
    }

    public function getEpp(Registrar_Domain $domain): string
    {
        $code = trim((string) ($this->info($domain)['authCode'] ?? ''));
        if ($code === '') {
            throw new Registrar_Exception('ConnectReseller did not return the transfer code of :domain.', [':domain' => $domain->getName()]);
        }

        return $code;
    }

    public function registerDomain(Registrar_Domain $domain): bool
    {
        $params = [
            'ProductType' => 1,
            'Websitename' => $domain->getName(),
            'Duration' => min(10, max(1, (int) $domain->getRegistrationPeriod())),
            'IsWhoisProtection' => $domain->getPrivacyEnabled() ? 'true' : 'false',
            // The registry refuses a premium name without this flag, at its premium price.
            'isEnablePremium' => 0,
        ];
        foreach ($this->nameservers($domain) as $i => $host) {
            $params['ns' . ($i + 1)] = $host;
        }
        if ($domain->getTld() === '.us') {
            $f = $domain->getFields();
            $category = (string) ($f['nexus_category'] ?? '');
            // ConnectReseller writes C31 and C32 with the organisation's country: C31/DE.
            if (in_array($category, ['C31', 'C32'], true) && !empty($f['nexus_country'])) {
                $category .= '/' . strtoupper((string) $f['nexus_country']);
            }
            $params += ['isUs' => 1, 'nexusCategory' => $category, 'appPurpose' => (string) ($f['app_purpose'] ?? '')];
        }
        $this->request('domainorder', $params + ['Id' => $this->customerId($domain)]);

        return true;
    }

    public function renewDomain(Registrar_Domain $domain): bool
    {
        $this->request('RenewalOrder', [
            'OrderType' => 2,
            'Websitename' => $domain->getName(),
            'Duration' => max(1, (int) $domain->getRegistrationPeriod()),
            // The domain's own customer, also for domains transferred in or moved to another customer.
            'Id' => $this->info($domain)['customerId'] ?? '',
        ]);

        return true;
    }

    public function deleteDomain(Registrar_Domain $domain): bool
    {
        // The API cannot delete a name: a cancelled domain stays at ConnectReseller until it expires unrenewed (as WHMCS).
        $this->getLog()->info(sprintf('%s was cancelled; ConnectReseller keeps it until it expires.', $domain->getName()));

        return true;
    }

    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->request('ManageDomainPrivacyProtection', ['domainNameId' => $this->info($domain)['domainNameId'], 'iswhoisprotected' => 'true']);

        return true;
    }

    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->request('ManageDomainPrivacyProtection', ['domainNameId' => $this->info($domain)['domainNameId'], 'iswhoisprotected' => 'false']);

        return true;
    }

    public function lock(Registrar_Domain $domain): bool
    {
        $this->request('ManageTheftProtection', ['domainNameId' => $this->info($domain)['domainNameId'], 'websiteName' => $domain->getName(), 'isTheftProtection' => 'true']);

        return true;
    }

    public function unlock(Registrar_Domain $domain): bool
    {
        $this->request('ManageTheftProtection', ['domainNameId' => $this->info($domain)['domainNameId'], 'websiteName' => $domain->getName(), 'isTheftProtection' => 'false']);

        return true;
    }

    /** The account's one-year cost per action (tldsync: one row per TLD with its currency). */
    public function getTldPrices(array $tlds = []): array
    {
        $reply = $this->request('tldsync', []);
        // Blesta reads the list as the whole reply; other calls put their data in responseData.
        $rows = array_is_list($reply) ? $reply : (array) ($reply['responseData'] ?? []);
        $year = static fn (mixed $p): ?float => is_numeric($p) ? (float) $p : null;
        $currency = '';
        $prices = [];
        foreach ($rows as $row) {
            if (is_array($row) && trim((string) ($row['tld'] ?? '')) !== '') {
                $prices['.' . strtolower(ltrim(trim((string) $row['tld']), '.'))] = ['register' => $year($row['registrationPrice'] ?? null),
                    'renew' => $year($row['renewalPrice'] ?? null), 'transfer' => $year($row['transferPrice'] ?? null)];
                $currency = $currency !== '' ? $currency : strtoupper(trim((string) ($row['currencyCode'] ?? '')));
            }
        }
        if ($tlds !== []) {
            $prices = array_intersect_key($prices, array_flip($tlds));
        }

        return ['currency' => $currency !== '' ? $currency : 'USD', 'prices' => $prices, 'remaining' => []];
    }

    /**
     * One API call; returns the reply. A responseMsg.statusCode (or, without one, an HTTP status) outside $ok is a
     * registrar error with ConnectReseller's message.
     */
    private function request(string $command, array $params, array $ok = [200]): array
    {
        if ($this->_testMode) {
            throw new Registrar_Exception(':registrar has no test system: turn test mode off for this registrar.', [':registrar' => 'ConnectReseller']);
        }

        $key = $this->config['api_key'];
        try {
            $response = $this->getHttpClient()->request('GET', self::URL . $command, [
                'query' => $params + ['APIKey' => $key],
                'timeout' => 60,
            ]);
            $body = $response->getContent(false);
            $http = $response->getStatusCode();
        } catch (ExceptionInterface $e) {
            // The message can contain the request URL, whose query carries the key, contact data and codes.
            $this->getLog()->err('ConnectReseller ' . $command . ' HTTP error: ' . preg_replace('/\?\S*/', '?***', str_replace($key, '***', $e->getMessage())));

            throw new Registrar_Exception('Could not reach :registrar: :error', [':registrar' => 'ConnectReseller', ':error' => $command]);
        }

        $reply = json_decode($body, true);
        if (!is_array($reply)) {
            throw new Registrar_Exception('ConnectReseller returned an unreadable response for :command', [':command' => $command]);
        }
        $status = (int) ($reply['responseMsg']['statusCode'] ?? $http);
        if (!in_array($status, $ok, true)) {
            $message = (string) ($reply['responseMsg']['message'] ?? $status);
            $this->getLog()->err(sprintf('ConnectReseller %s refused: %s %s', $command, $status, $message));

            throw new Registrar_Exception(':registrar refused the request: :error', [':registrar' => 'ConnectReseller', ':error' => $message]);
        }

        return $reply;
    }

    /** The availability check: statusCode 200 = free (with its domain type), 400 = taken. */
    private function check(Registrar_Domain $domain): array
    {
        $reply = $this->request('checkdomainavailable', ['websiteName' => $domain->getName()], [200, 400]);
        $data = is_array($reply['responseData'] ?? null) ? $reply['responseData'] : [];

        return [
            'available' => (int) ($reply['responseMsg']['statusCode'] ?? 0) === 200 && !empty($data['available']),
            'premium' => stripos((string) ($data['domainType'] ?? ''), 'premium') !== false,
        ];
    }

    /** ViewDomain's responseData: the domain's ID, customer, dates, name servers, lock, privacy and code. */
    private function info(Registrar_Domain $domain): array
    {
        $data = $this->request('ViewDomain', ['websiteName' => $domain->getName()])['responseData'] ?? null;
        if (!is_array($data) || empty($data['domainNameId'])) {
            throw new Registrar_Exception(':domain was not found in the ConnectReseller account.', [':domain' => $domain->getName()]);
        }

        return $data;
    }

    /** The client's ConnectReseller customer, found by email address, or created with the client's contact. */
    private function customerId(Registrar_Domain $domain): int
    {
        $c = $this->contact($domain);
        $find = fn (): int => (int) ($this->request('ViewClient', ['UserName' => $c['EmailAddress']], [200, 400])['responseData']['clientId'] ?? 0);
        $id = $find();
        if ($id === 0) {
            $this->request('AddClient', ['FirstName' => $c['Name'], 'UserName' => $c['EmailAddress'],
                // The panel wants a password; the client never signs in there.
                'Password' => bin2hex(random_bytes(6)) . 'A#', 'Address1' => $c['Address']]
                + array_diff_key($c, ['Name' => true, 'EmailAddress' => true, 'Address' => true]));
            $id = $find();
        }
        if ($id === 0) {
            throw new Registrar_Exception('ConnectReseller did not return a customer ID.');
        }

        return $id;
    }

    private function nameservers(Registrar_Domain $domain): array
    {
        $hosts = array_values(array_filter(array_map(fn ($ns) => trim((string) $ns), [$domain->getNs1(), $domain->getNs2(), $domain->getNs3(), $domain->getNs4()])));
        if (count($hosts) < 2) {
            throw new Registrar_Exception('At least two name servers are required.');
        }

        return $hosts;
    }

    /** The registrant in AddRegistrantContact's fields. */
    private function contact(Registrar_Domain $domain): array
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

        return [
            'Name' => trim($c->getFirstName() . ' ' . $c->getLastName()),
            'EmailAddress' => (string) $c->getEmail(),
            // The company is required: "NA" for a person.
            'CompanyName' => trim((string) $c->getCompany()) !== '' ? trim((string) $c->getCompany()) : 'NA',
            'Address' => implode(', ', array_filter([trim((string) $c->getAddress1()), trim((string) $c->getAddress2())])),
            'City' => (string) $c->getCity(),
            'StateName' => (string) $c->getState(),
            'CountryName' => strtoupper((string) $c->getCountry()),
            'Zip' => (string) $c->getZip(),
            'PhoneNo_cc' => $cc,
            'PhoneNo' => $tel,
        ];
    }
}
