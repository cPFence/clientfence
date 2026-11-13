<?php

declare(strict_types=1);
/**
 * OVHcloud registrar adapter (ClientFence).
 *
 * Uses the OVHcloud API (https://eu.api.ovh.com/console, or ca.api.ovh.com for OVHcloud Canada) with an application
 * key, application secret and consumer key; every call is signed with the application secret and the API's clock
 * (GET /auth/time). Registrations and transfers are ordered through a cart: the domain item, the holder contact,
 * name servers and transfer code as item configurations, checkout, then payment from the OVHcloud prepaid account.
 * Renewals are a renewal order for the domain service. OVHcloud has no test system, so test mode refuses every call.
 */
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

class Registrar_Adapter_Ovh extends Registrar_AdapterAbstract implements Registrar_TldPrices
{
    private const ENDPOINTS = ['ovh-eu' => 'https://eu.api.ovh.com/1.0', 'ovh-ca' => 'https://ca.api.ovh.com/1.0'];

    // Catalog extensions per page (about 950 in all).
    private const PRICE_PAGE = 500;

    public $config = [
        'application_key' => null,
        'secret_key' => null,
        'consumer_key' => null,
        'endpoint' => 'ovh-eu',
    ];

    private ?int $timeOffset = null;

    public function __construct($options)
    {
        if (empty($options['application_key']) || empty($options['secret_key']) || empty($options['consumer_key'])) {
            throw new Registrar_Exception('The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing', [':domain_registrar' => 'OVHcloud', ':missing' => 'application key, application secret and consumer key'], 3001);
        }

        $this->config['application_key'] = trim((string) $options['application_key']);
        $this->config['secret_key'] = trim((string) $options['secret_key']);
        $this->config['consumer_key'] = trim((string) $options['consumer_key']);
        $this->config['endpoint'] = isset(self::ENDPOINTS[$options['endpoint'] ?? '']) ? $options['endpoint'] : 'ovh-eu';
    }

    public static function getConfig(): array
    {
        return [
            'label' => __trans('Manages domains on OVHcloud through its API. Create an application and a consumer key (rights on /domain, /order, /me and /service) at the API endpoint of your OVHcloud account, and keep enough funds in your OVHcloud prepaid account: orders and renewals are paid from it. Set the renewal mode of your OVHcloud account to manual: ClientFence turns OVHcloud auto-renewal off for each domain once it exists and renews a domain when its renewal invoice is paid. OVHcloud has no test system: keep test mode off.'),
            'form' => [
                'endpoint' => ['radio', [
                    'label' => __trans('API endpoint'),
                    'description' => __trans('The OVHcloud region of your account.'),
                    'multiOptions' => ['ovh-eu' => __trans('OVHcloud Europe'), 'ovh-ca' => __trans('OVHcloud Canada')],
                    'default' => 'ovh-eu',
                ]],
                'application_key' => ['text', [
                    'label' => __trans('Application key'),
                    'required' => true,
                ]],
                'secret_key' => ['password', [
                    'label' => __trans('Application secret'),
                    'required' => true,
                    'secret' => true,
                ]],
                'consumer_key' => ['password', [
                    'label' => __trans('Consumer key'),
                    'required' => true,
                    'secret' => true,
                ]],
            ],
        ];
    }

    public static function getSecretFields(): array
    {
        return ['secret_key', 'consumer_key'];
    }

    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        $mode = $this->mode($this->item($domain));
        if (str_starts_with($mode, 'create-') && $mode !== 'create-default') {
            throw new Registrar_Exception('Premium domains cannot be registered.');
        }

        return $mode === 'create-default';
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        $mode = $this->mode($this->item($domain));
        if ($mode === 'transfer-premium') {
            throw new Registrar_Exception('Premium domains cannot be transferred.');
        }

        return $mode === 'transfer-default';
    }

    public function modifyNs(Registrar_Domain $domain): bool
    {
        // A domain on OVHcloud's own DNS refuses other name servers until its type is external (OVHcloud DNS API guide).
        $this->request('PUT', '/domain/' . $domain->getName(), ['nameServerType' => 'external']);
        $this->request('POST', '/domain/' . $domain->getName() . '/nameServers/update', ['nameServers' => array_map(fn (string $host) => ['host' => $host], $this->nameservers($domain))]);

        return true;
    }

    public function modifyContact(Registrar_Domain $domain): bool
    {
        // Each domain has its own holder contact at OVHcloud: update it, keeping the fields ClientFence does not know.
        $owner = (string) ($this->request('GET', '/domain/' . $domain->getName())['whoisOwner'] ?? '');
        if ($owner === '') {
            throw new Registrar_Exception(':domain was not found in the OVHcloud account.', [':domain' => $domain->getName()]);
        }
        $current = (array) $this->request('GET', '/domain/contact/' . $owner);
        unset($current['id']);
        $this->request('PUT', '/domain/contact/' . $owner, array_replace($current, $this->contact($domain->getContactRegistrar())));

        return true;
    }

    public function transferDomain(Registrar_Domain $domain): bool
    {
        $this->order($domain, 'transfer');

        return true;
    }

    public function getDomainDetails(Registrar_Domain $domain): Registrar_Domain
    {
        $info = (array) $this->request('GET', '/domain/' . $domain->getName());
        $service = (array) $this->request('GET', '/domain/' . $domain->getName() . '/serviceInfos');
        // Runs on the activation sync and every later sync: the domain exists by then.
        $this->manualRenewal($domain, $service);

        $details = new Registrar_Domain();
        $details->setSld($domain->getSld());
        $details->setTld($domain->getTld());
        $details->setRegistrationTime(strtotime((string) ($service['creation'] ?? '')) ?: null);
        $details->setExpirationTime(strtotime((string) ($service['expiration'] ?? '')) ?: $domain->getExpirationTime());
        $details->setLocked(in_array($info['transferLockStatus'] ?? '', ['locked', 'locking'], true));
        $details->setEpp($domain->getEpp());
        $i = 1;
        foreach ($info['nameServers'] ?? [] as $host) {
            if ($i <= 4 && trim((string) ($host['nameServer'] ?? '')) !== '') {
                $details->{'setNs' . $i++}(strtolower(trim((string) $host['nameServer'])));
            }
        }
        // OVHcloud returns a holder contact ID, not the contact: keep ClientFence's copy.
        if ($domain->getContactRegistrar() instanceof Registrar_Domain_Contact) {
            foreach (['Registrar', 'Admin', 'Tech', 'Billing'] as $role) {
                $details->{'setContact' . $role}($domain->getContactRegistrar());
            }
        }

        return $details;
    }

    public function getEpp(Registrar_Domain $domain): string
    {
        return (string) $this->request('GET', '/domain/' . $domain->getName() . '/authInfo');
    }

    public function registerDomain(Registrar_Domain $domain): bool
    {
        $this->order($domain, 'create');

        return true;
    }

    public function renewDomain(Registrar_Domain $domain): bool
    {
        // A restorable domain would be renewed at the restore fee: never pay that automatically.
        if (($this->request('GET', '/domain/' . $domain->getName())['state'] ?? '') === 'restorable') {
            throw new Registrar_Exception(':domain is in its restore period: restore it at the registrar if it should be kept.', [':domain' => $domain->getName()]);
        }
        $service = (array) $this->request('GET', '/domain/' . $domain->getName() . '/serviceInfos');
        $this->manualRenewal($domain, $service);
        // OVHcloud already renewed it (auto-renewal was on): a second paid renewal would add a year nobody bought.
        $expires = strtotime((string) ($service['expiration'] ?? '')) ?: 0;
        if ($domain->getExpirationTime() && $expires > (int) $domain->getExpirationTime() + 30 * 86400) {
            $this->getLog()->warn(sprintf('OVHcloud already renewed %s (expires %s): no new renewal ordered.', $domain->getName(), $service['expiration']));

            return true;
        }
        $serviceId = (int) ($service['serviceId'] ?? 0);
        $order = $this->request('POST', '/service/' . $serviceId . '/renew', ['dryRun' => false, 'duration' => 'P' . max(1, (int) $domain->getRegistrationPeriod()) . 'Y', 'services' => [$serviceId]]);
        $this->pay((int) ($order['orderId'] ?? 0));

        return true;
    }

    public function deleteDomain(Registrar_Domain $domain): bool
    {
        // OVHcloud deletes the domain when it expires instead of renewing it.
        try {
            $this->request('PUT', '/domain/' . $domain->getName() . '/serviceInfos', ['renew' => ['automatic' => false, 'forced' => false, 'deleteAtExpiration' => true]]);
        } catch (Registrar_Exception $e) {
            // A domain no longer at OVHcloud (moved away, failed transfer, expired) must still be cancellable.
            $this->getLog()->warn(sprintf('OVHcloud did not set %s to expire: %s', $domain->getName(), $e->getMessage()));
        }

        return true;
    }

    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        throw new Registrar_Exception(':registrar does not support this action.', [':registrar' => 'OVHcloud']);
    }

    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        throw new Registrar_Exception(':registrar does not support this action.', [':registrar' => 'OVHcloud']);
    }

    public function lock(Registrar_Domain $domain): bool
    {
        $this->request('PUT', '/domain/' . $domain->getName(), ['transferLockStatus' => 'locked']);

        return true;
    }

    public function unlock(Registrar_Domain $domain): bool
    {
        $this->request('PUT', '/domain/' . $domain->getName(), ['transferLockStatus' => 'unlocked']);

        return true;
    }

    /**
     * The one-year costs per TLD from OVHcloud's catalog, excluding tax. Not Blesta's cart item: its TOTAL is the
     * registration total, while a transfer often costs far more (.tech: 7.99 to register, 55.09 to transfer).
     */
    public function getTldPrices(array $tlds = []): array
    {
        $want = array_flip(array_map('strtolower', $tlds));
        $currency = '';
        $prices = [];
        for ($offset = 0; ; $offset += self::PRICE_PAGE) {
            $page = (array) ($this->request('GET', '/order/catalog/formatted/domain?' . http_build_query(['ovhSubsidiary' => $this->subsidiary(), 'count' => self::PRICE_PAGE, 'offset' => $offset]), null, false)['extensions'] ?? []);
            foreach ($page as $e) {
                $tld = '.' . strtolower((string) ($e['planCode'] ?? ''));
                if ($tld === '.' || ($want !== [] && !isset($want[$tld]))) {
                    continue;
                }
                // Catalog values are in hundred-millionths of the currency unit.
                $year = static fn (string $action): ?float => is_numeric($e['prices'][$action]['value'] ?? null) ? $e['prices'][$action]['value'] / 1e8 : null;
                $prices[$tld] = ['register' => $year('creation'), 'renew' => $year('renewal'), 'transfer' => $year('transfer')];
                $currency = $currency !== '' ? $currency : (string) ($e['currency'] ?? '');
            }
            if (count($page) < self::PRICE_PAGE) {
                break;
            }
        }

        return ['currency' => $currency !== '' ? $currency : ($this->subsidiary() === 'CA' ? 'CAD' : 'EUR'), 'prices' => $prices, 'remaining' => []];
    }

    /** Turns OVHcloud's own auto-renewal off (its default for new services): ClientFence renews on paid invoices. */
    private function manualRenewal(Registrar_Domain $domain, array $service): void
    {
        $renew = is_array($service['renew'] ?? null) ? $service['renew'] : [];
        if (!empty($renew['automatic']) && empty($renew['deleteAtExpiration'])) {
            $this->request('PUT', '/domain/' . $domain->getName() . '/serviceInfos', ['renew' => ['automatic' => false, 'forced' => false, 'deleteAtExpiration' => false]]);
        }
    }

    /** Orders a registration ($action create) or transfer: cart item, configurations, checkout, payment. */
    private function order(Registrar_Domain $domain, string $action): void
    {
        $years = $action === 'create' ? min(10, max(1, (int) $domain->getRegistrationPeriod())) : 1;
        $item = $this->item($domain, 'P' . $years . 'Y');
        $mode = $this->mode($item);
        if ($mode === $action . '-premium') {
            throw new Registrar_Exception($action === 'create' ? 'Premium domains cannot be registered.' : 'Premium domains cannot be transferred.');
        }
        if ($mode !== $action . '-default') {
            throw new Registrar_Exception('OVHcloud cannot order :domain: :error', [':domain' => $domain->getName(), ':error' => $mode]);
        }
        $path = '/order/cart/' . $item['cartId'] . '/item/' . $item['itemId'];
        $this->request('POST', '/order/cart/' . $item['cartId'] . '/assign');

        $hosts = $action === 'create' ? $this->nameservers($domain) : array_values(array_filter(array_map(fn ($ns) => trim((string) $ns), [$domain->getNs1(), $domain->getNs2(), $domain->getNs3(), $domain->getNs4()])));
        $wanted = array_column((array) $this->request('GET', $path . '/requiredConfiguration'), 'label');
        $values = [
            'OWNER_LEGAL_AGE' => 'true',
            'DNS' => implode(';', $hosts),
            // The code is optional: .uk transfers have none (the owner changes the IPS tag instead).
            'AUTH_INFO' => $action === 'transfer' ? trim((string) $domain->getEpp()) : '',
        ];
        foreach ($wanted as $label) {
            $value = $label === 'OWNER_CONTACT' ? '/me/contact/' . $this->newContact($domain) : ($values[$label] ?? '');
            if ($value !== '') {
                $this->request('POST', $path . '/configuration', ['label' => $label, 'value' => $value]);
            }
        }

        $order = $this->request('POST', '/order/cart/' . $item['cartId'] . '/checkout', ['autoPayWithPreferredPaymentMethod' => false, 'waiveRetractationPeriod' => true]);
        $this->pay((int) ($order['orderId'] ?? 0));
    }

    /** Pays an order from the OVHcloud prepaid account and checks that OVHcloud is delivering it. */
    private function pay(int $orderId): void
    {
        if ($orderId <= 0) {
            throw new Registrar_Exception('OVHcloud returned an unreadable response for :command', [':command' => 'order']);
        }
        $this->request('POST', '/me/order/' . $orderId . '/payWithRegisteredPaymentMean', ['paymentMean' => 'ovhAccount']);
        $status = (string) $this->request('GET', '/me/order/' . $orderId . '/status');
        if (!in_array($status, ['delivered', 'delivering', 'checking'], true)) {
            throw new Registrar_Exception('OVHcloud order :order is :status: check it in your OVHcloud account.', [':order' => $orderId, ':status' => $status]);
        }
    }

    private function cart(): string
    {
        $cart = (string) ($this->request('POST', '/order/cart', ['ovhSubsidiary' => $this->subsidiary()])['cartId'] ?? '');
        if ($cart === '') {
            throw new Registrar_Exception('OVHcloud returned an unreadable response for :command', [':command' => 'order/cart']);
        }

        return $cart;
    }

    /** The domain as an item of a new cart; OVHcloud's pricing mode on it tells whether it can be registered or transferred. */
    private function item(Registrar_Domain $domain, ?string $duration = null): array
    {
        $item = (array) $this->request('POST', '/order/cart/' . $this->cart() . '/domain', array_filter(['domain' => $domain->getName(), 'duration' => $duration]));
        if (empty($item['cartId']) || empty($item['itemId'])) {
            throw new Registrar_Exception('OVHcloud returned an unreadable response for :command', [':command' => 'order/cart/domain']);
        }

        return $item;
    }

    private function mode(array $item): string
    {
        return (string) ($item['settings']['pricingMode'] ?? '');
    }

    /** A new holder contact for this domain only (POST /domain/contact); returns its ID. */
    private function newContact(Registrar_Domain $domain): string
    {
        $id = (string) ($this->request('POST', '/domain/contact', $this->contact($domain->getContactRegistrar()) + ['language' => 'en_GB'])['id'] ?? '');
        if ($id === '') {
            throw new Registrar_Exception('OVHcloud did not return a contact ID.');
        }

        return $id;
    }

    private function subsidiary(): string
    {
        return $this->config['endpoint'] === 'ovh-ca' ? 'CA' : 'FR';
    }

    /** One signed API call; returns the decoded reply. HTTP 4xx/5xx is a registrar error with OVHcloud's message. */
    private function request(string $method, string $path, ?array $body = null, bool $signed = true): mixed
    {
        if ($this->_testMode) {
            throw new Registrar_Exception(':registrar has no test system: turn test mode off for this registrar.', [':registrar' => 'OVHcloud']);
        }
        $url = self::ENDPOINTS[$this->config['endpoint']] . $path;
        $json = $body === null ? '' : (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $headers = ['Content-Type' => 'application/json', 'X-Ovh-Application' => $this->config['application_key']];
        if ($signed) {
            // OVHcloud refuses a timestamp off its own clock: ask it once per adapter.
            $this->timeOffset ??= (int) $this->request('GET', '/auth/time', null, false) - time();
            $time = (string) (time() + $this->timeOffset);
            $headers += [
                'X-Ovh-Consumer' => $this->config['consumer_key'],
                'X-Ovh-Timestamp' => $time,
                'X-Ovh-Signature' => '$1$' . sha1(implode('+', [$this->config['secret_key'], $this->config['consumer_key'], $method, $url, $json, $time])),
            ];
        }

        try {
            $response = $this->getHttpClient()->request($method, $url, ['headers' => $headers, 'body' => $json, 'timeout' => 60]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            $this->getLog()->err('OVHcloud ' . $method . ' ' . $path . ' HTTP error: ' . $e->getMessage());

            throw new Registrar_Exception('Could not reach :registrar: :error', [':registrar' => 'OVHcloud', ':error' => $path]);
        }

        $reply = json_decode($content, true);
        if ($status >= 400) {
            $message = is_array($reply) && isset($reply['message']) ? (string) $reply['message'] : 'HTTP ' . $status;
            $this->getLog()->err(sprintf('OVHcloud %s %s refused: %s', $method, $path, $message));

            throw new Registrar_Exception(':registrar refused the request: :error', [':registrar' => 'OVHcloud', ':error' => $message]);
        }
        if ($reply === null && trim($content) !== '' && trim($content) !== 'null') {
            throw new Registrar_Exception('OVHcloud returned an unreadable response for :command', [':command' => $method . ' ' . $path]);
        }

        return $reply;
    }

    /** @return list<string> */
    private function nameservers(Registrar_Domain $domain): array
    {
        $hosts = array_values(array_filter(array_map(fn ($ns) => trim((string) $ns), [$domain->getNs1(), $domain->getNs2(), $domain->getNs3(), $domain->getNs4()])));
        if (count($hosts) < 2) {
            throw new Registrar_Exception('At least two name servers are required.');
        }

        return $hosts;
    }

    /** The client's contact as an OVHcloud domain contact. */
    private function contact(?Registrar_Domain_Contact $c): array
    {
        if (!$c instanceof Registrar_Domain_Contact) {
            throw new Registrar_Exception('The registrant contact is missing.');
        }
        $cc = (string) preg_replace('/\D+/', '', (string) $c->getTelCc());
        $tel = (string) preg_replace('/\D+/', '', (string) $c->getTel());
        // The international form drops the national trunk 0 (Italian numbers keep it).
        if ($cc !== '39') {
            $tel = ltrim($tel, '0');
        }
        $company = trim((string) $c->getCompany());

        return array_filter([
            'firstName' => (string) $c->getFirstName(),
            'lastName' => (string) $c->getLastName(),
            'email' => (string) $c->getEmail(),
            // OVHcloud's phone form: +33.612345678.
            'phone' => '+' . $cc . '.' . $tel,
            'legalForm' => $company === '' ? 'individual' : 'corporation',
            'organisationName' => $company,
            'address' => array_filter([
                'line1' => (string) $c->getAddress1(),
                'line2' => (string) $c->getAddress2(),
                'city' => (string) $c->getCity(),
                'province' => (string) $c->getState(),
                'zip' => (string) $c->getZip(),
                'country' => strtoupper((string) $c->getCountry()),
            ], fn (string $value) => $value !== ''),
        ], fn ($value) => $value !== '' && $value !== []);
    }
}
