<?php
/**
 * DomainNameApi REST adapter for ClientFence.
 *
 * Install: library/Registrar/Adapter/DomainNameApi.php
 * Requires PHP 8.1+ and ClientFence's Registrar_AdapterAbstract.
 *
 * API documentation: https://www.domainnameapi.com/docs/
 */
declare(strict_types=1);

class Registrar_Adapter_Domainnameapi extends Registrar_AdapterAbstract implements Registrar_TldPrices, Registrar_TransferStatus
{
    private const LIVE_URL = 'https://api.domainresellerapi.com/api/v1/';

    /** @var array<string,mixed> */
    private array $options;

    private string $baseUrl = self::LIVE_URL;

    /** @var array<string,array<string,mixed>> */
    private array $tldCatalogue = [];

    private ?string $resellerGroupName = null;

    private ?string $catalogueCurrency = null;

    public function __construct(array $options)
    {
        $this->options = $options;

        if (empty($options['reseller_id']) || empty($options['api_key'])) {
            throw new Registrar_Exception('Enter your DomainNameApi Reseller ID and API Key.');
        }
    }

    public static function getConfig(): array
    {
        return [
            'label' => 'DomainNameApi REST registrar. Uses a Reseller ID and API key.',
            'form' => [
                'reseller_id' => ['text', [
                    'label' => 'Reseller ID',
                    'required' => true,
                ]],
                'api_key' => ['password', [
                    'label' => 'API Key',
                    'required' => true,
                ]],
            ],
        ];
    }

    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        $result = $this->request('POST', 'domains/search', [
            'domainName' => $domain->getName(),
        ]);

        $status = strtoupper((string)($result['info']['status'] ?? ''));

        if ($status === 'AVAILABLE' && !empty($result['info']['isPremium'])) {
            throw new Registrar_Exception('Premium domains are not supported by this adapter checkout flow.');
        }

        return $status === 'AVAILABLE';
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        $tld = strtolower((string)$domain->getTld());
        $catalogue = $this->getTldEntry($tld);

        if ($catalogue !== null && empty($catalogue['isSupportTransfer'])) {
            return false;
        }

        // Some TLDs do not support a pre-transfer check.
        // In that case, rely on the transfer request itself.
        if ($catalogue !== null && empty($catalogue['isTransferCheckSupported'])) {
            return true;
        }

        try {
            $this->request('POST', 'domains/transfers/check', [
                'domainName' => $domain->getName(),
                'authCode' => (string)$domain->getEpp(),
            ]);

            return true;
        } catch (Registrar_Exception $e) {
            if (str_contains($e->getMessage(), '10025')) {
                return true;
            }

            return false;
        }
    }

    public function registerDomain(Registrar_Domain $domain): bool
    {
        $this->request('POST', 'domains/register-with-contacts', [
            'domainName' => $domain->getName(),
            'period' => max(1, (int)$domain->getRegistrationPeriod()),
            'nameServers' => $this->getNameservers($domain),
            'contacts' => $this->getContacts($domain),
            'useTrusteeContact' => false,
        ]);

        return true;
    }

    public function transferDomain(Registrar_Domain $domain): bool
    {
        $authCode = trim((string)$domain->getEpp());

        if ($authCode === '') {
            throw new Registrar_Exception('An EPP/auth code is required to transfer this domain.');
        }

        $tld = strtolower((string)$domain->getTld());
        $catalogue = $this->getTldEntry($tld);

        if ($catalogue !== null && !empty($catalogue['isTransferCheckSupported'])) {
            $this->request('POST', 'domains/transfers/check', [
                'domainName' => $domain->getName(),
                'authCode' => $authCode,
            ]);
        }

        $this->request('POST', 'domains/transfer', [
            'domainName' => $domain->getName(),
            'authCode' => $authCode,
            'period' => max(1, (int)$domain->getRegistrationPeriod()),
        ]);

        // The transfer is normally accepted as pending.
        // ClientFence should poll getTransferStatus().
        return true;
    }

    public function renewDomain(Registrar_Domain $domain): bool
    {
        $payload = [
            'domainName' => $domain->getName(),
            'period' => max(1, (int)$domain->getRegistrationPeriod()),
        ];

        $this->request('POST', 'domains/renew/check', $payload);
        $this->request('POST', 'domains/renew', $payload);

        return true;
    }

    public function getDomainDetails(Registrar_Domain $domain): Registrar_Domain
    {
        $details = $this->request('GET', 'domains/info', null, [
            'domainName' => $domain->getName(),
        ]);

        if (!empty($details['expirationDate'])) {
            $expires = strtotime((string)$details['expirationDate']);

            if ($expires !== false) {
                $domain->setExpirationTime($expires);
            }
        }

        if (array_key_exists('authCode', $details) && method_exists($domain, 'setEpp')) {
            $domain->setEpp((string)$details['authCode']);
        }

        if (array_key_exists('lockStatus', $details) && method_exists($domain, 'setLocked')) {
            $domain->setLocked((bool)$details['lockStatus']);
        }

        $nameservers = $details['nameservers'] ?? [];

        if (is_array($nameservers)) {
            foreach (array_slice($nameservers, 0, 4) as $index => $nameserver) {
                $name = is_array($nameserver) ? ($nameserver['name'] ?? '') : $nameserver;
                $setter = 'setNs' . ($index + 1);

                if ($name !== '' && method_exists($domain, $setter)) {
                    $domain->{$setter}((string)$name);
                }
            }
        }

        return $domain;
    }

    public function modifyNs(Registrar_Domain $domain): bool
    {
        $this->request('PUT', 'domains/dns/name-server', [
            'domainName' => $domain->getName(),
            'nameServers' => $this->getNameservers($domain),
        ]);

        return true;
    }

    public function getEpp(Registrar_Domain $domain): string
    {
        $result = $this->request('PUT', 'domains/auth', [
            'domainName' => $domain->getName(),
        ]);

        $code = $result['authCode'] ?? $result['info']['authCode'] ?? null;

        if (!is_string($code) || $code === '') {
            throw new Registrar_Exception('DomainNameApi did not return an EPP/auth code.');
        }

        return $code;
    }

    public function modifyContact(Registrar_Domain $domain): bool
    {
        $this->request('PUT', 'domains/contacts/update', [
            'domainName' => $domain->getName(),
            'contacts' => $this->getContacts($domain),
        ]);

        return true;
    }

    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->setPrivacy($domain, true);

        return true;
    }

    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->setPrivacy($domain, false);

        return true;
    }

    public function lock(Registrar_Domain $domain): bool
    {
        $this->request('POST', 'domains/lock', [
            'domainName' => $domain->getName(),
        ]);

        return true;
    }

    public function unlock(Registrar_Domain $domain): bool
    {
        $this->request('POST', 'domains/unlock', [
            'domainName' => $domain->getName(),
        ]);

        return true;
    }

    public function deleteDomain(Registrar_Domain $domain): bool
    {
        throw new Registrar_Exception('Domain deletion is not supported by DomainNameApi.');
    }

    /**
     * ClientFence optional price-list interface.
     *
     * The reseller price group is read automatically from the account API.
     * Catalogue currency is taken from the returned catalogue price data.
     */
    public function getTldPrices(array $tlds = []): array
    {
        $account = $this->request('GET', 'deposit/accounts/me');

        $groupName = trim((string)($account['resellerGroupName'] ?? ''));

        if ($groupName === '') {
            throw new Registrar_Exception(
                'DomainNameApi did not return the reseller price group.'
            );
        }

        $this->resellerGroupName = $groupName;

        // Currency is not forced through a configuration field or query
        // parameter. The API returns the currency with each catalogue price.
        $result = $this->request('GET', 'products/tlds', null, [
            'maxResultCount' => 500,
        ]);

        $items = $result['items'] ?? [];

        $wanted = [];

        foreach ($tlds as $tld) {
            $wanted[] = strtolower(ltrim((string)$tld, '.'));
        }

        $prices = [];
        $notPriced = [];

        foreach ($items as $item) {
            $name = strtolower((string)($item['name'] ?? ''));

            if ($name === '' || ($wanted !== [] && !in_array($name, $wanted, true))) {
                continue;
            }

            $group = null;

            foreach (($item['prices'] ?? []) as $candidate) {
                if (strcasecmp((string)($candidate['priceGroup'] ?? ''), $groupName) === 0) {
                    $group = $candidate;
                    break;
                }
            }

            if ($group === null) {
                $notPriced[] = '.' . $name;
                continue;
            }

            if ($this->catalogueCurrency === null) {
                $this->catalogueCurrency = $this->findPriceCurrency($group);
            }

            $prices['.' . $name] = [
                'register' => $this->getOneYearPrice($group['register'] ?? []),
                'renew' => $this->getOneYearPrice($group['renew'] ?? []),
                'transfer' => $this->getOneYearPrice($group['transfer'] ?? []),
            ];
        }

        if ($wanted !== []) {
            $found = array_map(
                static fn($key) => ltrim($key, '.'),
                array_keys($prices)
            );

            foreach ($wanted as $requested) {
                if (
                    !in_array($requested, $found, true)
                    && !in_array('.' . $requested, $notPriced, true)
                ) {
                    $notPriced[] = '.' . $requested;
                }
            }
        }

        return [
            'currency' => $this->catalogueCurrency ?? 'USD',
            'prices' => $prices,
            'remaining' => array_values(array_unique($notPriced)),
        ];
    }

    /**
     * ClientFence optional transfer-status interface.
     */
    public function getTransferStatus(Registrar_Domain $domain): array
    {
        $result = $this->request('POST', 'domains/transfers/query', [
            'domainName' => $domain->getName(),
        ]);

        $status = (string)($result['status'] ?? 'pending');
        $normalised = strtolower($status);

        // Confirm the domain is readable before reporting completion.
        if (in_array($normalised, ['clientapproved', 'serverapproved'], true)) {
            try {
                $this->getDomainDetails($domain);
                $mapped = Registrar_TransferStatus::COMPLETED;
            } catch (Throwable $e) {
                $mapped = Registrar_TransferStatus::PENDING;
            }
        } elseif (
            in_array(
                $normalised,
                ['clientrejected', 'clientcancelled', 'servercancelled'],
                true
            )
        ) {
            $mapped = Registrar_TransferStatus::FAILED;
        } else {
            $mapped = Registrar_TransferStatus::PENDING;
        }

        $message = $status;

        if (!empty($result['finalDate'])) {
            $message .= ' (registry auto-approval date: ' . $result['finalDate'] . ')';
        }

        return [
            'status' => $mapped,
            'message' => $message,
        ];
    }

    private function setPrivacy(Registrar_Domain $domain, bool $enabled): void
    {
        $this->request('POST', 'domains/privacy', [
            'domainName' => $domain->getName(),
            'enable' => $enabled,
        ]);
    }

    /** @return list<string> */
    private function getNameservers(Registrar_Domain $domain): array
    {
        return array_values(array_filter([
            $domain->getNs1(),
            $domain->getNs2(),
            $domain->getNs3(),
            $domain->getNs4(),
        ], static fn($value) => is_string($value) && trim($value) !== ''));
    }

    /** @return list<array<string,string>> */
    private function getContacts(Registrar_Domain $domain): array
    {
        $contact = $domain->getContactRegistrar();

        $registrant = [
            'contactType' => 'Registrant',
            'firstName' => (string)$contact->getFirstName(),
            'lastName' => (string)$contact->getLastName(),
            'companyName' => (string)$contact->getCompany(),
            'eMail' => (string)$contact->getEmail(),
            'address' => trim(
                (string)$contact->getAddress1() . ' ' . (string)$contact->getAddress2()
            ),
            'city' => (string)$contact->getCity(),
            'state' => (string)$contact->getState(),
            'postalCode' => (string)$contact->getZip(),
            'country' => strtoupper((string)$contact->getCountry()),
            'phoneCountryCode' => ltrim((string)$contact->getTelCc(), '+'),
            'phone' => (string)$contact->getTel(),
        ];

        // DNA supports using one contact for all four roles when its
        // contact auto-fill behavior is enabled.
        return [
            array_filter(
                $registrant,
                static fn($value) => $value !== ''
            ),
        ];
    }

    private function getOneYearPrice(array $periodPrices): ?float
    {
        foreach ($periodPrices as $entry) {
            if ((int)($entry['period'] ?? 0) === 1 && isset($entry['price'])) {
                return (float)$entry['price'];
            }
        }

        return null;
    }

    private function findPriceCurrency(array $priceGroup): ?string
    {
        foreach (['register', 'renew', 'transfer'] as $operation) {
            foreach (($priceGroup[$operation] ?? []) as $entry) {
                $currency = strtoupper(trim((string)($entry['currency'] ?? '')));

                if ($currency !== '') {
                    return $currency;
                }
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function getTldEntry(string $tld): ?array
    {
        $name = strtolower(ltrim($tld, '.'));

        if (!array_key_exists($name, $this->tldCatalogue)) {
            $result = $this->request('GET', 'products/tlds', null, [
                'filterText' => $name,
                'maxResultCount' => 500,
            ]);

            foreach (($result['items'] ?? []) as $item) {
                $itemName = strtolower((string)($item['name'] ?? ''));

                if ($itemName !== '') {
                    $this->tldCatalogue[$itemName] = $item;
                }
            }

            if (!array_key_exists($name, $this->tldCatalogue)) {
                return null;
            }
        }

        return $this->tldCatalogue[$name];
    }

    /**
     * Make one authenticated DNA REST request.
     *
     * @param array<string,mixed>|null $body
     * @param array<string,scalar> $query
     * @return array<string,mixed>
     */
    private function request(
        string $method,
        string $path,
        ?array $body = null,
        array $query = []
    ): array {
        $url = $this->baseUrl . ltrim($path, '/');

        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $requestOptions = [
            'headers' => [
                'X-API-KEY' => (string)$this->options['api_key'],
                '__reseller' => (string)$this->options['reseller_id'],
                'Accept' => 'application/json',
            ],
        ];

        if ($body !== null) {
            $requestOptions['json'] = $body;
        }

        try {
            $response = $this->getHttpClient()->request(
                $method,
                $url,
                $requestOptions
            );

            $statusCode = $response->getStatusCode();
            $raw = $response->getContent(false);
            $data = $raw !== '' ? json_decode($raw, true) : [];

            if (!is_array($data)) {
                throw new Registrar_Exception(
                    'DomainNameApi returned an invalid JSON response.'
                );
            }

            if ($statusCode >= 400 || (($data['success'] ?? true) === false)) {
                $code = (string)($data['code'] ?? '');
                $reason = (string)(
                    $data['reason']
                    ?? $data['message']
                    ?? $data['error']
                    ?? $raw
                );

                throw new Registrar_Exception(
                    'DomainNameApi [' . $code . ']: '
                    . ($reason !== '' ? $reason : 'HTTP ' . $statusCode)
                );
            }

            return $data;
        } catch (Registrar_Exception $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new Registrar_Exception(
                'DomainNameApi request failed: ' . $e->getMessage()
            );
        }
    }
}