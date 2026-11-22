<?php

declare(strict_types=1);
/**
 * Spaceship registrar adapter (ClientFence).
 *
 * Written from the Spaceship API documentation (https://docs.spaceship.dev/): REST/JSON on https://spaceship.dev/api
 * with the key pair in the X-API-Key and X-API-Secret headers; errors are HTTP 4xx/5xx with a problem+json "detail".
 * Domains take contacts as contact IDs (PUT /v1/contacts gives one for the details). Registrations, transfers and
 * renewals answer 202 with an async operation ID: the adapter polls it briefly, fails the order when it failed, and
 * otherwise reports it done (a transfer is then tracked through getTransferStatus()). Spaceship auto-renewal is off
 * for every domain it registers or transfers: ClientFence renews a domain when its renewal invoice is paid. The API
 * cannot delete a domain yet (HTTP 501): cancelling turns auto-renewal off. Spaceship has no test system, so test mode
 * refuses registrations, transfers and renewals.
 */
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

class Registrar_Adapter_Spaceship extends Registrar_AdapterAbstract implements Registrar_TldPrices, Registrar_TransferStatus
{
    private const URL = 'https://spaceship.dev/api';
    private const LOCK = 'clientTransferProhibited';
    /** Status checks of an accepted operation (the docs allow 60 per user in 300 seconds). */
    private const POLLS = 5;
    /** The most TLDs one price query takes. */
    private const PRICE_SLICE = 25;

    public $config = [
        'api_key' => null,
        'api_secret' => null,
    ];

    public function __construct($options)
    {
        foreach (['api_key' => 'API key', 'api_secret' => 'API secret'] as $key => $label) {
            if (trim((string) ($options[$key] ?? '')) === '') {
                throw new Registrar_Exception(':domain_registrar is not fully configured. Please configure the :missing', [':domain_registrar' => 'Spaceship', ':missing' => $label], 3001);
            }
            $this->config[$key] = trim((string) $options[$key]);
        }
    }

    public static function getConfig(): array
    {
        return [
            'label' => __trans('Manages domains on Spaceship through its API. Create the API key in the Spaceship API Manager with the permissions domains:read, domains:write, domains:transfer, domains:billing, contacts:write and asyncoperations:read. Registrations, transfers and renewals are charged to your Spaceship account. Spaceship auto-renewal is off for every domain registered or transferred here: ClientFence renews a domain when its renewal invoice is paid. Spaceship has no test system: keep test mode off.'),
            'form' => [
                'api_key' => ['text', [
                    'label' => __trans('API key'),
                    'description' => __trans('From Spaceship: API Manager > New API key.'),
                    'required' => true,
                ]],
                'api_secret' => ['password', [
                    'label' => __trans('API secret'),
                    'description' => __trans('The secret shown with the new API key.'),
                    'required' => true,
                    'secret' => true,
                ]],
            ],
        ];
    }

    public static function getSecretFields(): array
    {
        return ['api_secret'];
    }

    public static function getDomainFields(string $tld, string $action = 'register'): array
    {
        // Spaceship's "us" contact attributes take the nexus category and purpose (no country).
        return strtolower($tld) === '.us' && $action === 'register' ? array_diff_key(self::usNexusFields(), ['nexus_country' => true]) : [];
    }

    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        if ($this->availability($domain) !== 'available') {
            return false;
        }
        // A premium name costs more than the TLD's price the client pays.
        if (($this->pricing($domain)['priceClass'] ?? '') !== 'standard') {
            throw new Registrar_Exception('Premium domains cannot be registered.');
        }

        return true;
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        // "taken": registered, and so a transfer candidate.
        if ($this->availability($domain) !== 'taken') {
            return false;
        }
        $pricing = $this->pricing($domain);
        if (($pricing['priceClass'] ?? '') !== 'standard') {
            throw new Registrar_Exception('Premium domains cannot be transferred.');
        }

        // The docs: an operation Spaceship does not offer for the name (or has paused) has no price.
        return isset($pricing['prices']['transfer']);
    }

    public function registerDomain(Registrar_Domain $domain): bool
    {
        $this->refuseInTestMode();
        $years = $this->period($domain);
        $contacts = $this->contacts($domain);
        $attributes = $this->usAttributes($domain);
        if ($attributes !== null) {
            $contacts['attributes'] = [$attributes];
        }
        $status = $this->await($this->request('POST', $this->path($domain), ['autoRenew' => false, 'years' => $years, 'privacyProtection' => $this->privacy($domain), 'contacts' => $contacts]));

        // Spaceship registers with its own name servers: the order's go on once the name exists. Logged, never thrown (the order must not register again).
        if ($status !== 'success') {
            $this->getLog()->err(sprintf('Spaceship accepted the registration of %s, still in progress: set its name servers in the domain\'s page once it is registered.', $domain->getName()));

            return true;
        }

        try {
            $this->modifyNs($domain);
        } catch (Registrar_Exception $e) {
            $this->getLog()->err(sprintf('Spaceship registered %s, but setting the name servers failed; fix it in the domain\'s page: %s', $domain->getName(), $e->getMessage()));
        }

        return true;
    }

    public function transferDomain(Registrar_Domain $domain): bool
    {
        $this->refuseInTestMode();
        // The docs: the code is optional for some ccTLDs (.uk).
        $this->await($this->request('POST', $this->path($domain) . '/transfer', ['autoRenew' => false, 'privacyProtection' => $this->privacy($domain), 'contacts' => $this->contacts($domain)]
            + array_filter(['authCode' => trim((string) $domain->getEpp())], static fn (string $v): bool => $v !== '')));

        return true;
    }

    public function renewDomain(Registrar_Domain $domain): bool
    {
        $this->refuseInTestMode();
        $years = $this->period($domain);
        // The registry's current expiry: Spaceship renews from that date only, so a retry after a renewal does not renew twice.
        $expiry = (string) ($this->info($domain)['expirationDate'] ?? '');
        $this->await($this->request('POST', $this->path($domain) . '/renew', ['years' => $years, 'currentExpirationDate' => $expiry]));

        return true;
    }

    public function getDomainDetails(Registrar_Domain $domain): Registrar_Domain
    {
        $info = $this->info($domain);
        // $domain holds ClientFence's stored expiry, which the renewal check would take as the registrar's: no expiry, no answer.
        $expiry = $this->date($info['expirationDate'] ?? null);
        if ($expiry === null) {
            throw new Registrar_Exception(':registrar did not report an expiry date for :domain.', [':registrar' => 'Spaceship', ':domain' => $domain->getName()]);
        }
        $domain->setExpirationTime($expiry);
        if (($created = $this->date($info['registrationDate'] ?? null)) !== null) {
            $domain->setRegistrationTime($created);
        }
        if (isset($info['eppStatuses'])) {
            $domain->setLocked(in_array(self::LOCK, (array) $info['eppStatuses'], true));
        }
        if (isset($info['privacyProtection']['level'])) {
            $domain->setPrivacyEnabled($info['privacyProtection']['level'] === 'high');
        }
        if (isset($info['nameservers']['hosts'])) {
            $hosts = array_values(array_filter(array_map(static fn (mixed $h): string => is_scalar($h) ? strtolower(trim((string) $h)) : '', (array) $info['nameservers']['hosts'])));
            foreach ([1, 2, 3, 4] as $i) {
                $domain->{'setNs' . $i}($hosts[$i - 1] ?? '');
            }
        }

        return $domain;
    }

    public function modifyNs(Registrar_Domain $domain): bool
    {
        $hosts = array_values(array_unique(array_filter(array_map(static fn (mixed $ns): string => strtolower(rtrim(trim((string) $ns), '.')), [$domain->getNs1(), $domain->getNs2(), $domain->getNs3(), $domain->getNs4()]))));
        if (count($hosts) < 2) {
            throw new Registrar_Exception('At least two name servers are required.');
        }
        $this->request('PUT', $this->path($domain) . '/nameservers', ['provider' => 'custom', 'hosts' => $hosts]);

        return true;
    }

    public function modifyContact(Registrar_Domain $domain): bool
    {
        // The docs do not say whether a change without "attributes" drops them (the .us nexus): the domain's are kept.
        $attributes = (array) ($this->info($domain)['contacts']['attributes'] ?? []);
        $this->request('PUT', $this->path($domain) . '/contacts', $this->contacts($domain) + ($attributes === [] ? [] : ['attributes' => array_values($attributes)]));

        return true;
    }

    public function getEpp(Registrar_Domain $domain): string
    {
        $code = trim((string) ($this->request('GET', $this->path($domain) . '/transfer/auth-code')['authCode'] ?? ''));
        if ($code === '') {
            throw new Registrar_Exception('The transfer code could not be retrieved automatically. Please contact support to receive it.');
        }

        return $code;
    }

    public function lock(Registrar_Domain $domain): bool
    {
        $this->request('PUT', $this->path($domain) . '/transfer/lock', ['isLocked' => true]);

        return true;
    }

    public function unlock(Registrar_Domain $domain): bool
    {
        $this->request('PUT', $this->path($domain) . '/transfer/lock', ['isLocked' => false]);

        return true;
    }

    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        // userConsent: the docs say the change is made only when it is true.
        $this->request('PUT', $this->path($domain) . '/privacy/preference', ['privacyLevel' => 'high', 'userConsent' => true]);

        return true;
    }

    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->request('PUT', $this->path($domain) . '/privacy/preference', ['privacyLevel' => 'public', 'userConsent' => true]);

        return true;
    }

    public function deleteDomain(Registrar_Domain $domain): bool
    {
        // The delete call answers 501 (not built yet, the docs say): auto-renewal off, the cancelled domain expires unrenewed.
        try {
            $this->request('PUT', $this->path($domain) . '/autorenew', ['isEnabled' => false]);
        } catch (Registrar_Exception $e) {
            // A domain no longer at Spaceship must still be cancellable.
            $this->getLog()->warn(sprintf('Spaceship did not turn off the auto-renewal of %s: %s', $domain->getName(), $e->getMessage()));
        }

        return true;
    }

    /**
     * The one-year regular price (without promotions, which can run out) per action, PRICE_SLICE TLDs per call
     * (Query TLDs prices); [] asks for every TLD Spaceship sells (Get TLD list).
     */
    public function getTldPrices(array $tlds = []): array
    {
        if ($tlds === []) {
            for ($skip = 0; ; $skip += 100) {
                $page = $this->request('GET', '/v1/tlds?' . http_build_query(['take' => 100, 'skip' => $skip]));
                $items = (array) ($page['items'] ?? []);
                foreach ($items as $item) {
                    $tlds[] = '.' . strtolower((string) ($item['name'] ?? ''));
                }
                if ($items === [] || $skip + 100 >= (int) ($page['total'] ?? 0)) {
                    break;
                }
            }
        }
        $now = array_slice($tlds, 0, self::PRICE_SLICE);
        $currency = '';
        $prices = [];
        $reply = $now === [] ? [] : $this->request('POST', '/v1/tlds/pricing/query', ['tlds' => array_map(static fn (string $tld): string => ltrim(strtolower($tld), '.'), $now)]);
        foreach ((array) ($reply['tlds'] ?? []) as $item) {
            // An item without prices carries an errorCode instead.
            if (!is_array($item['prices'] ?? null)) {
                continue;
            }
            $row = [];
            foreach (['register', 'renew', 'transfer'] as $action) {
                $row[$action] = null;
                foreach ((array) ($item['prices'][$action] ?? []) as $price) {
                    $period = $price['period'] ?? null;
                    $regular = $price['priceInDefaultCurrency']['regularPrice'] ?? [];
                    // One year; an operation with no period (a transfer may have none) is priced as it is.
                    if (($period === null || (($period['value'] ?? 0) === 1 && ($period['unit'] ?? '') === 'y')) && is_numeric($regular['amount'] ?? null)) {
                        $code = strtoupper((string) ($regular['currency'] ?? ''));
                        if ($currency !== '' && $code !== $currency) {
                            throw new Registrar_Exception(':registrar returned prices in more than one currency (:a and :b), which cannot be imported as one list.', [':registrar' => 'Spaceship', ':a' => $currency, ':b' => $code]);
                        }
                        $currency = $code;
                        $row[$action] = (float) $regular['amount'];

                        break;
                    }
                }
            }
            $prices['.' . strtolower((string) ($item['tld'] ?? ''))] = $row;
        }

        return ['currency' => $currency ?: 'USD', 'prices' => $prices, 'remaining' => array_slice($tlds, self::PRICE_SLICE)];
    }

    public function getTransferStatus(Registrar_Domain $domain): array
    {
        $status = (string) ($this->request('GET', $this->path($domain) . '/transfer')['status'] ?? '');

        return ['status' => match ($status) {
            'completed' => Registrar_TransferStatus::COMPLETED,
            'cancelled' => Registrar_TransferStatus::FAILED,
            default => Registrar_TransferStatus::PENDING,
        }, 'message' => $status];
    }

    /** The pause between two status checks of an accepted operation; a test replaces it. */
    protected function pause(): void
    {
        sleep(2);
    }

    /**
     * Polls an accepted (202) operation: "failed" throws with Spaceship's details; returns "success", or "pending"
     * when it is still running or cannot be checked (a false failure would leave a paid order that cannot complete).
     */
    private function await(array $accepted): string
    {
        $id = (string) ($accepted['operation'] ?? '');
        for ($i = 0; $i < self::POLLS; ++$i) {
            $this->pause();
            try {
                $operation = $this->request('GET', '/v1/async-operations/' . rawurlencode($id));
            } catch (Registrar_Exception $e) {
                $this->getLog()->warn(sprintf('Spaceship operation %s could not be checked: %s', $id, $e->getMessage()));

                return 'pending';
            }
            if (($operation['status'] ?? '') === 'failed') {
                $error = isset($operation['details']) ? (string) json_encode($operation['details'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) ($operation['type'] ?? 'failed');
                $this->getLog()->err(sprintf('Spaceship operation %s failed: %s', $id, $error));

                throw new Registrar_Exception(':registrar refused the request: :error', [':registrar' => 'Spaceship', ':error' => $error]);
            }
            if (($operation['status'] ?? '') === 'success') {
                return 'success';
            }
        }

        return 'pending';
    }

    /**
     * One API call; returns the decoded reply, or {operation: ID} for an accepted (202) async operation. HTTP 4xx/5xx is
     * a registrar error with Spaceship's detail.
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $options = ['headers' => ['X-API-Key' => $this->config['api_key'], 'X-API-Secret' => $this->config['api_secret']], 'timeout' => 60];
        if ($body !== null) {
            $options['json'] = $body;
        }

        try {
            $response = $this->getHttpClient()->request($method, self::URL . $path, $options);
            $code = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $content = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            $this->getLog()->err('Spaceship ' . $method . ' ' . $path . ' HTTP error: ' . $e->getMessage());

            throw new Registrar_Exception('Could not reach :registrar: :error', [':registrar' => 'Spaceship', ':error' => $path]);
        }
        $reply = $content === '' ? [] : json_decode($content, true);
        if ($code >= 400) {
            // problem+json: "detail", and for 422 a "data" list of {field, details}.
            $fields = is_array($reply['data'] ?? null) ? array_map(static fn (mixed $f): string => is_array($f) ? trim(($f['field'] ?? '') . ': ' . ($f['details'] ?? ''), ': ') : '', $reply['data']) : [];
            $error = trim(implode(' ', array_filter([is_string($reply['detail'] ?? null) ? $reply['detail'] : '', ...$fields]))) ?: 'HTTP ' . $code;
            $this->getLog()->err(sprintf('Spaceship %s %s refused (HTTP %d %s): %s', $method, $path, $code, $headers['spaceship-error-code'][0] ?? '', $error));

            throw new Registrar_Exception(':registrar refused the request: :error', [':registrar' => 'Spaceship', ':error' => $error]);
        }
        if ($code === 202) {
            $operation = trim((string) ($headers['spaceship-async-operationid'][0] ?? ''));
            if ($operation === '') {
                throw new Registrar_Exception(':registrar returned an unreadable response.', [':registrar' => 'Spaceship']);
            }

            return ['operation' => $operation];
        }
        if (!is_array($reply)) {
            throw new Registrar_Exception(':registrar returned an unreadable response.', [':registrar' => 'Spaceship']);
        }

        return $reply;
    }

    private function refuseInTestMode(): void
    {
        if ($this->_testMode) {
            throw new Registrar_Exception(':registrar has no test system: turn test mode off for this registrar.', [':registrar' => 'Spaceship']);
        }
    }

    /** available or taken; anything else (invalid name, TLD not sold, unexpected error) is no answer, not "taken". */
    private function availability(Registrar_Domain $domain): string
    {
        $result = (string) ($this->request('GET', $this->path($domain) . '/available')['result'] ?? '');
        if (!in_array($result, ['available', 'taken'], true)) {
            throw new Registrar_Exception(':registrar did not say whether :domain is available.', [':registrar' => 'Spaceship', ':domain' => $domain->getName()]);
        }

        return $result;
    }

    /** Get single domain prices: priceClass (only "standard" is the TLD's price, a new class is not) and prices. */
    private function pricing(Registrar_Domain $domain): array
    {
        return $this->request('GET', $this->path($domain) . '/pricing');
    }

    private function info(Registrar_Domain $domain): array
    {
        return $this->request('GET', $this->path($domain));
    }

    /** Each role's contact saved at Spaceship (the same details give the same ID). */
    private function contacts(Registrar_Domain $domain): array
    {
        if (!$domain->getContactRegistrar() instanceof Registrar_Domain_Contact) {
            throw new Registrar_Exception('No registrant contact was given for :domain.', [':domain' => $domain->getName()]);
        }

        return $this->roleHandles($domain, fn (Registrar_Domain_Contact $c): string => $this->contactIdOf($c));
    }

    private function contactIdOf(Registrar_Domain_Contact $c): string
    {
        // Spaceship's contact fields take ASCII only, each its own characters (ContactDetails patterns): "José St. Müller"
        // goes as "Jose St Muller"; any other character becomes a space.
        $ascii = static fn (mixed $v, string $allowed = '\x20-\x7E'): string => trim((string) preg_replace(['~[^' . $allowed . ']~', '~\s+~'], ' ',
            (string) transliterator_transliterate('Any-Latin; Latin-ASCII; [:^ASCII:] Remove', (string) $v)), " `");
        $name = "A-Za-z'`\\s-";
        return $this->contactId($this->request('PUT', '/v1/contacts', array_filter([
            'firstName' => $ascii($c->getFirstName(), $name),
            'lastName' => $ascii($c->getLastName(), $name),
            'organization' => $ascii($c->getCompany()),
            'email' => trim((string) $c->getEmail()),
            'address1' => $ascii($c->getAddress1(), "\\d#&'()./:;A-Za-z\\s,\\\\-"),
            'address2' => $ascii($c->getAddress2(), "\\d#&'()./:;A-Za-z\\s,\\\\-"),
            'city' => $ascii($c->getCity(), "A-Za-z'.\\s-"),
            'country' => strtoupper(trim((string) $c->getCountry())),
            'stateProvince' => $ascii($c->getState(), $name),
            'postalCode' => $ascii($c->getZip(), '\\dA-Za-z\\s-'),
            'phone' => '+' . preg_replace('/\D/', '', (string) $c->getTelCc()) . '.' . preg_replace('/\D/', '', (string) $c->getTel()),
        ], static fn (string $v): bool => $v !== '')));
    }

    /** The order's .us answers saved as "us" contact attributes; null for other TLDs. */
    private function usAttributes(Registrar_Domain $domain): ?string
    {
        if ($domain->getTld() !== '.us') {
            return null;
        }
        $f = $domain->getFields();

        return $this->contactId($this->request('PUT', '/v1/contacts/attributes', ['type' => 'us', 'nexusCategory' => trim((string) ($f['nexus_category'] ?? '')), 'appPurpose' => trim((string) ($f['app_purpose'] ?? ''))]));
    }

    private function contactId(array $reply): string
    {
        $id = trim((string) ($reply['contactId'] ?? ''));
        if ($id === '') {
            throw new Registrar_Exception(':registrar returned an unreadable response.', [':registrar' => 'Spaceship']);
        }

        return $id;
    }

    /** "high" hides the contact details; "public" shows them, which Spaceship takes only with userConsent. */
    private function privacy(Registrar_Domain $domain): array
    {
        return ['level' => $domain->getPrivacyEnabled() ? 'high' : 'public', 'userConsent' => true];
    }

    private function period(Registrar_Domain $domain): int
    {
        $years = (int) $domain->getRegistrationPeriod();
        if ($years < 1) {
            throw new Registrar_Exception('No registration period was given for :domain.', [':domain' => $domain->getName()]);
        }

        return $years;
    }

    private function path(Registrar_Domain $domain): string
    {
        // The docs take the A-label of an internationalised name; non-transitional keeps ß (straße.de is not strasse.de).
        $name = strtolower($domain->getName());

        return '/v1/domains/' . rawurlencode(idn_to_ascii($name, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46) ?: $name);
    }

    /** An ISO date at noon UTC (no day shift in any time zone); anything else is null. */
    private function date(mixed $value): ?int
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($value), $m) || (int) $m[1] < 1970 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return gmmktime(12, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]) ?: null;
    }
}
