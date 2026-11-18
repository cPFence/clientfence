<?php

declare(strict_types=1);
/**
 * Porkbun registrar adapter (ClientFence).
 *
 * Uses the Porkbun API v3 (https://porkbun.com/api/json/v3/documentation): https://api.porkbun.com/api/json/v3 with the
 * key pair in the X-API-Key and X-Secret-API-Key headers, and in the JSON body of writes (apikey, secretapikey) as
 * Porkbun's request schemas require; the registrar's test mode needs a sandbox key pair (pk1_sb_),
 * which Porkbun simulates on the same address without charges. A registration, renewal or transfer is first quoted with
 * a dry run (nothing charged), then bought at exactly the quoted cost with an Idempotency-Key from the order. A retry
 * that finds the name already in the account stops for staff: it may be another order's. Porkbun buys only the registry's minimum term
 * and registers with the account's default contact and name servers: the order's are applied right after. The API has
 * no transfer lock, privacy switch, transfer code or delete calls. Porkbun auto-renewal is turned off on every domain
 * the sync reads: ClientFence renews a domain when its renewal invoice is paid.
 */
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

class Registrar_Adapter_Porkbun extends Registrar_AdapterAbstract implements Registrar_TldPrices, Registrar_TransferStatus
{
    private const URL = 'https://api.porkbun.com/api/json/v3';

    public $config = [
        'api_key' => null,
        'secret_api_key' => null,
    ];

    public function __construct($options)
    {
        foreach (['api_key' => 'API key', 'secret_api_key' => 'secret API key'] as $key => $label) {
            if (trim((string) ($options[$key] ?? '')) === '') {
                throw new Registrar_Exception('The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing', [':domain_registrar' => 'Porkbun', ':missing' => $label], 3001);
            }
            $this->config[$key] = trim((string) $options[$key]);
        }
    }

    public static function getConfig(): array
    {
        return [
            'label' => __trans('Manages domains on Porkbun through its API. Create the API key at porkbun.com/account/api, verify the account\'s email address and phone number, opt all domains in to API access (account settings), keep enough account credit for registrations and renewals, and raise the monthly API spend limit (100 USD by default). Porkbun registers, renews and transfers for the registry\'s minimum term only (usually one year): orders for more years are refused before any charge. Porkbun auto-renewal is turned off for every domain: ClientFence renews a domain when its renewal invoice is paid. With test mode on, enter a sandbox key pair (it starts with pk1_sb_).'),
            'form' => [
                'api_key' => ['text', [
                    'label' => __trans('API key'),
                    'description' => __trans('From Porkbun: Account > API Access (it starts with pk1_).'),
                    'required' => true,
                ]],
                'secret_api_key' => ['password', [
                    'label' => __trans('Secret API key'),
                    'description' => __trans('The key\'s secret (it starts with sk1_).'),
                    'required' => true,
                    'secret' => true,
                ]],
            ],
        ];
    }

    public static function getSecretFields(): array
    {
        return ['secret_api_key'];
    }

    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        $result = $this->check($domain);
        if ($result['avail'] !== 'yes') {
            return false;
        }
        if (($result['premium'] ?? '') === 'yes') {
            throw new Registrar_Exception('Premium domains cannot be registered.');
        }
        // An IDN needs its language (idnLanguage), which the order does not ask: refused before the client pays.
        if (!empty($result['idnLanguages'])) {
            throw new Registrar_Exception('Internationalized domain names cannot be registered through :registrar.', [':registrar' => 'Porkbun']);
        }
        // Refused before the client pays: some TLDs (registry eligibility rules) can only be registered on Porkbun's website.
        $rules = $this->request('GET', '/domain/getRegistrationRequirements/' . rawurlencode(ltrim(strtolower($domain->getTld()), '.')));
        if (empty($rules['apiRegisterable'])) {
            throw new Registrar_Exception(':tld domains cannot be registered through the :registrar API: :reason', [':tld' => $domain->getTld(), ':registrar' => 'Porkbun', ':reason' => (string) ($rules['notApiRegisterableReason'] ?? '')]);
        }

        return true;
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        // Porkbun's API transfers no .uk names: refused before the client pays.
        if (str_ends_with(strtolower($domain->getTld()), '.uk')) {
            throw new Registrar_Exception(':tld domains cannot be transferred through the :registrar API.', [':tld' => $domain->getTld(), ':registrar' => 'Porkbun']);
        }
        $result = $this->check($domain);
        if ($result['avail'] === 'no' && ($result['premium'] ?? '') === 'yes') {
            throw new Registrar_Exception('Premium domains cannot be transferred.');
        }

        // A name already in this Porkbun account cannot be transferred in.
        return $result['avail'] === 'no' && empty($result['inYourAccount']);
    }

    public function modifyNs(Registrar_Domain $domain): bool
    {
        $hosts = array_values(array_unique(array_filter(array_map(fn ($ns) => strtolower(rtrim(trim((string) $ns), '.')), [$domain->getNs1(), $domain->getNs2(), $domain->getNs3(), $domain->getNs4()]))));
        if (count($hosts) < 2) {
            throw new Registrar_Exception('At least two name servers are required.');
        }
        $this->request('POST', '/domain/updateNs/' . $this->name($domain), ['ns' => $hosts]);

        return true;
    }

    public function modifyContact(Registrar_Domain $domain): bool
    {
        $path = '/domain/updateContacts/' . $this->name($domain);
        $body = ['contact' => $this->contact($domain)];
        $reply = $this->call('POST', $path, $body);
        // Address-checked TLDs (.us, .uk, .eu, .de...) ask once to confirm the address: keep it as the client entered it.
        if (($reply['code'] ?? '') === 'ADDRESS_VALIDATION_REQUIRED') {
            $reply = $this->call('POST', $path, $body + ['addressValidationChoice' => 'use_as_entered']);
        }
        $this->checked($reply, $path);

        return true;
    }

    public function transferDomain(Registrar_Domain $domain): bool
    {
        $epp = trim((string) $domain->getEpp());
        if ($epp === '') {
            throw new Registrar_Exception('The transfer authorization code is required.');
        }
        $this->purchase('transfer', $domain, ['authCode' => $epp]);

        return true;
    }

    public function getTransferStatus(Registrar_Domain $domain): array
    {
        $transfer = $this->request('GET', '/domain/getTransfer/' . $this->name($domain))['transfer'] ?? [];
        $status = (string) ($transfer['status'] ?? '');

        // NEW, INIT, PENDINGAUTH, PENDINGSUBMIT and PENDINGTRANSFER are still under way.
        return ['status' => match ($status) {
            'DONE' => Registrar_TransferStatus::COMPLETED,
            'CANCELED' => Registrar_TransferStatus::FAILED,
            default => Registrar_TransferStatus::PENDING,
        }, 'message' => trim($status . ' ' . (string) ($transfer['statusDescription'] ?? ''))];
    }

    public function getDomainDetails(Registrar_Domain $domain): Registrar_Domain
    {
        $info = $this->request('GET', '/domain/get/' . $this->name($domain))['domain'] ?? [];
        // Porkbun would renew from the account credit on its own: ClientFence renews when the client pays.
        if ((int) ($info['autoRenew'] ?? 0) === 1) {
            try {
                $this->request('POST', '/domain/updateAutoRenew/' . $this->name($domain), ['status' => 'off']);
            } catch (Registrar_Exception $e) {
                $this->getLog()->warn(sprintf('Porkbun auto-renewal of %s is still on: %s', $domain->getName(), $e->getMessage()));
            }
        }

        $details = new Registrar_Domain();
        $details->setSld($domain->getSld());
        $details->setTld($domain->getTld());
        $details->setRegistrationTime($this->utc($info['createDate'] ?? null));
        $details->setExpirationTime($this->utc($info['expireDate'] ?? null) ?? $domain->getExpirationTime());
        $details->setLocked((int) ($info['securityLock'] ?? 0) === 1);
        $details->setPrivacyEnabled((int) ($info['whoisPrivacy'] ?? 0) === 1);
        $details->setEpp($domain->getEpp());
        foreach (array_slice(array_values(array_filter(array_map(fn ($ns) => strtolower(trim((string) $ns)), (array) ($this->request('GET', '/domain/getNs/' . $this->name($domain))['ns'] ?? [])))), 0, 4) as $i => $host) {
            $details->{'setNs' . ($i + 1)}($host);
        }
        $p = $this->request('GET', '/domain/getContacts/' . $this->name($domain))['contacts']['registrant'] ?? [];
        $contact = (new Registrar_Domain_Contact())->setFirstName((string) ($p['firstName'] ?? ''))->setLastName((string) ($p['lastName'] ?? ''))
            ->setCompany((string) ($p['organization'] ?? ''))->setEmail((string) ($p['email'] ?? ''))->setTelCc((string) ($p['phoneCountryCode'] ?? ''))
            ->setTel((string) ($p['phone'] ?? ''))->setAddress1((string) ($p['address1'] ?? ''))->setAddress2((string) ($p['address2'] ?? ''))
            ->setCity((string) ($p['city'] ?? ''))->setState((string) ($p['state'] ?? ''))->setZip((string) ($p['postalCode'] ?? ''))
            ->setCountry(strtoupper((string) ($p['country'] ?? '')));
        // No registrant in the reply: keep ClientFence's copy.
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
        throw new Registrar_Exception(':registrar does not support this action.', [':registrar' => 'Porkbun']);
    }

    public function registerDomain(Registrar_Domain $domain): bool
    {
        // No whoisPrivacy: the order never sets it, and false would publish the client's contact; the account default applies.
        $this->purchase('create', $domain, ['agreeToTerms' => 'yes']);

        // Porkbun has taken the order: a failed follow-up is logged, never thrown (the order must not fail and re-register).
        foreach (['modifyContact' => 'the contact', 'modifyNs' => 'the name servers'] as $step => $what) {
            try {
                $this->{$step}($domain);
            } catch (Registrar_Exception $e) {
                $this->getLog()->err(sprintf('Porkbun registered %s, but setting %s failed; fix it in the domain\'s page: %s', $domain->getName(), $what, $e->getMessage()));
            }
        }

        return true;
    }

    public function renewDomain(Registrar_Domain $domain): bool
    {
        $this->purchase('renew', $domain, []);

        return true;
    }

    public function deleteDomain(Registrar_Domain $domain): bool
    {
        // The API cannot delete a name: auto-renewal off, the cancelled domain expires unrenewed (as WHMCS).
        try {
            $this->request('POST', '/domain/updateAutoRenew/' . $this->name($domain), ['status' => 'off']);
        } catch (Registrar_Exception $e) {
            // A domain no longer at Porkbun must still be cancellable.
            $this->getLog()->warn(sprintf('Porkbun did not turn off the auto-renewal of %s: %s', $domain->getName(), $e->getMessage()));
        }

        return true;
    }

    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        throw new Registrar_Exception(':registrar does not support this action.', [':registrar' => 'Porkbun']);
    }

    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        throw new Registrar_Exception(':registrar does not support this action.', [':registrar' => 'Porkbun']);
    }

    public function lock(Registrar_Domain $domain): bool
    {
        throw new Registrar_Exception(':registrar does not support this action.', [':registrar' => 'Porkbun']);
    }

    public function unlock(Registrar_Domain $domain): bool
    {
        throw new Registrar_Exception(':registrar does not support this action.', [':registrar' => 'Porkbun']);
    }

    /** Porkbun's one-year price per action in USD (pricing/get, the same for every account). */
    public function getTldPrices(array $tlds = []): array
    {
        $year = static fn (mixed $p): ?float => is_numeric($p) ? (float) $p : null;
        $prices = [];
        foreach ((array) ($this->request('GET', '/pricing/get')['pricing'] ?? []) as $tld => $p) {
            // Handshake names (specialType) are not DNS TLDs.
            if (is_array($p) && !isset($p['specialType'])) {
                $prices['.' . strtolower((string) $tld)] = ['register' => $year($p['registration'] ?? null), 'renew' => $year($p['renewal'] ?? null), 'transfer' => $year($p['transfer'] ?? null)];
            }
        }
        if ($tlds !== []) {
            $prices = array_intersect_key($prices, array_flip($tlds));
        }

        return ['currency' => 'USD', 'prices' => $prices, 'remaining' => []];
    }

    /**
     * A registration, renewal or transfer: a dry run quotes it (nothing charged, checks funds and spend limit), then
     * the purchase at exactly that cost.
     */
    private function purchase(string $action, Registrar_Domain $domain, array $body): void
    {
        $path = '/domain/' . $action . '/' . $this->name($domain);
        try {
            $quote = $this->request('POST', $path, $body + ['cost' => 0, 'dryRun' => true]);
        } catch (Registrar_Exception $e) {
            // Maybe a retry after a lost reply. Not taken over automatically: the name may be another order's, so staff check.
            if ($action !== 'renew' && !empty($this->check($domain)['inYourAccount'])) {
                throw new Registrar_Exception(':domain is already in your :registrar account. If this order\'s earlier try registered or transferred it, activate the order by hand.', [':domain' => $domain->getName(), ':registrar' => 'Porkbun']);
            }

            throw $e;
        }
        if (empty($quote['wouldSucceed'])) {
            throw new Registrar_Exception(':registrar refused the request: :error', [':registrar' => 'Porkbun', ':error' => (string) ($quote['message'] ?? $action)]);
        }
        // Porkbun buys only the registry's minimum term: never fewer (or more) years than the client paid for.
        $years = max(1, (int) $domain->getRegistrationPeriod());
        if ((int) ($quote['duration'] ?? 0) !== $years) {
            throw new Registrar_Exception(':registrar sells :domain only for the registry\'s minimum term (in years: :duration), not for the ordered term (in years: :years).', [':registrar' => 'Porkbun', ':domain' => $domain->getName(), ':duration' => (int) ($quote['duration'] ?? 0), ':years' => $years]);
        }
        $cost = (int) ($quote['cost'] ?? 0);
        // The same key on a retry of this purchase: Porkbun answers it from its first reply for 24 hours instead of charging again.
        // A renewal also keys on the expiry it starts from, so next year's renewal of the same order is a new purchase; a
        // transfer on a 10-minute window, so retrying a cancelled transfer starts a new one.
        $key = 'clientfence-' . hash('sha256', implode('|', [$action, strtolower($domain->getName()), (string) ($this->_order->id ?? ''), $cost, match ($action) {
            'renew' => (string) $domain->getExpirationTime(),
            'transfer' => (string) intdiv(time(), 600),
            default => '',
        }]));
        $this->request('POST', $path, $body + ['cost' => $cost], $key);
    }

    private function check(Registrar_Domain $domain): array
    {
        $result = $this->request('POST', '/domain/checkDomain/' . $this->name($domain))['response'] ?? null;
        if (!is_array($result) || !in_array($result['avail'] ?? null, ['yes', 'no'], true)) {
            throw new Registrar_Exception('Porkbun returned an unreadable response for :command', [':command' => 'checkDomain']);
        }

        return $result;
    }

    /** One API call; any reply but status SUCCESS is a registrar error with Porkbun's message. */
    private function request(string $method, string $path, array $body = [], ?string $idempotencyKey = null): array
    {
        return $this->checked($this->call($method, $path, $body, $idempotencyKey), $path);
    }

    private function checked(array $reply, string $path): array
    {
        if ($reply['status'] !== 'SUCCESS') {
            $this->getLog()->err(sprintf('Porkbun %s refused: %s %s', $path, $reply['code'] ?? '', $reply['message'] ?? ''));

            throw new Registrar_Exception(':registrar refused the request: :error', [':registrar' => 'Porkbun', ':error' => (string) ($reply['message'] ?? $reply['code'] ?? 'unknown error')]);
        }

        return $reply;
    }

    /** One API call; returns the decoded reply, refused or not (errors come with HTTP 4xx and a JSON body). */
    private function call(string $method, string $path, array $body = [], ?string $idempotencyKey = null): array
    {
        // A live key in test mode would buy for real: the sandbox keys start with pk1_sb_.
        if ($this->_testMode && !str_starts_with((string) $this->config['api_key'], 'pk1_sb_')) {
            throw new Registrar_Exception(':registrar test mode needs a sandbox API key (it starts with pk1_sb_).', [':registrar' => 'Porkbun']);
        }
        $options = ['headers' => array_filter(['X-API-Key' => $this->config['api_key'], 'X-Secret-API-Key' => $this->config['secret_api_key'], 'Idempotency-Key' => $idempotencyKey]), 'timeout' => 60];
        if ($method === 'POST') {
            // Porkbun's write schemas name the key pair in the body (it then takes precedence over the headers).
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = (string) json_encode(['apikey' => $this->config['api_key'], 'secretapikey' => $this->config['secret_api_key']] + $body);
        }

        try {
            $content = $this->getHttpClient()->request($method, self::URL . $path, $options)->getContent(false);
        } catch (ExceptionInterface $e) {
            $this->getLog()->err('Porkbun ' . $method . ' ' . $path . ' HTTP error: ' . $e->getMessage());

            throw new Registrar_Exception('Could not reach :registrar: :error', [':registrar' => 'Porkbun', ':error' => $path]);
        }

        $reply = json_decode($content, true);
        if (!is_array($reply) || !is_string($reply['status'] ?? null)) {
            throw new Registrar_Exception('Porkbun returned an unreadable response for :command', [':command' => $path]);
        }

        return $reply;
    }

    /** The client's contact in Porkbun's form, applied to all four roles. */
    private function contact(Registrar_Domain $domain): array
    {
        $c = $domain->getContactRegistrar();
        if (!$c instanceof Registrar_Domain_Contact) {
            throw new Registrar_Exception('The registrant contact is missing.');
        }
        $cc = (string) preg_replace('/\D+/', '', (string) $c->getTelCc());
        $tel = (string) preg_replace('/\D+/', '', (string) $c->getTel());
        // Porkbun wants the national number: drop the trunk 0 (Italian numbers keep it).
        if ($cc !== '39') {
            $tel = ltrim($tel, '0');
        }

        return [
            'firstName' => trim((string) $c->getFirstName()),
            'lastName' => trim((string) $c->getLastName()),
            'organization' => trim((string) $c->getCompany()),
            'address1' => trim((string) $c->getAddress1()),
            'address2' => trim((string) $c->getAddress2()),
            'city' => trim((string) $c->getCity()),
            'state' => trim((string) $c->getState()),
            'postalCode' => trim((string) $c->getZip()),
            'country' => strtoupper(trim((string) $c->getCountry())),
            'phone' => $tel,
            'phoneCountryCode' => $cc,
            'email' => trim((string) $c->getEmail()),
        ];
    }

    private function name(Registrar_Domain $domain): string
    {
        return rawurlencode(strtolower($domain->getName()));
    }

    /** Porkbun's dates are UTC ("2027-01-15 10:00:00"). */
    private function utc(mixed $date): ?int
    {
        return is_string($date) && trim($date) !== '' ? (strtotime($date . ' UTC') ?: null) : null;
    }
}
