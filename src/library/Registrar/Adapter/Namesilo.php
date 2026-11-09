<?php

declare(strict_types=1);
/**
 * NameSilo registrar adapter (ClientFence).
 *
 * Based on the NameSilo adapter for FOSSBilling by insxa (https://github.com/insxa/Namesilo-Fossbilling,
 * Copyright 2026 FOSSBilling, Apache-2.0), reworked for ClientFence. Uses the NameSilo API
 * (https://www.namesilo.com/api-reference): GET https://www.namesilo.com/api/OPERATION?version=1&type=xml&key=...;
 * the registrar's test mode uses sandbox.namesilo.com with the sandbox key. Contact changes create a NameSilo
 * contact and assign it to the domain, since a NameSilo contact can be shared by many domains. .us names ask for the
 * nexus questions and .eu names the registrant's EU citizenship on the order form (getDomainFields()).
 */
use ClientFence\Mod\Invoice\EuVat;
use Symfony\Component\Intl\Countries;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

class Registrar_Adapter_Namesilo extends Registrar_AdapterAbstract
{
    private const LIVE_URL = 'https://www.namesilo.com/api/';
    private const SANDBOX_URL = 'https://sandbox.namesilo.com/api/';

    public $config = [
        'api_key' => null,
        'sandbox_api_key' => null,
        'payment_id' => '',
        'auto_renew' => false,
    ];

    public function __construct($options)
    {
        if (empty($options['api_key']) && empty($options['sandbox_api_key'])) {
            throw new Registrar_Exception('The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing', [':domain_registrar' => 'NameSilo', ':missing' => 'API key'], 3001);
        }

        $this->config['api_key'] = trim((string) ($options['api_key'] ?? ''));
        $this->config['sandbox_api_key'] = trim((string) ($options['sandbox_api_key'] ?? ''));
        $this->config['payment_id'] = trim((string) ($options['payment_id'] ?? ''));
        $this->config['auto_renew'] = (string) ($options['auto_renew'] ?? '0') === '1';
    }

    public static function getConfig(): array
    {
        return [
            'label' => __trans('Manages domains on NameSilo through its API. Create the API key in NameSilo (Account > API Manager), allow this server\'s IP address there, and keep enough account funds for registrations and renewals. In the API Manager, also turn off restorations by renewDomain: ClientFence refuses to renew a domain in its restore period, but NameSilo would otherwise restore it at the restoration fee.'),
            'form' => [
                'api_key' => ['password', [
                    'label' => __trans('API key'),
                    'description' => __trans('From NameSilo: Account > API Manager.'),
                    'required' => false,
                    'secret' => true,
                ]],
                'sandbox_api_key' => ['password', [
                    'label' => __trans('Sandbox API key'),
                    'description' => __trans('Separate key for sandbox.namesilo.com, used only while test mode is on.'),
                    'required' => false,
                    'secret' => true,
                ]],
                'payment_id' => ['text', [
                    'label' => __trans('Payment ID'),
                    'description' => __trans('Optional verified NameSilo payment profile. Empty: account funds pay.'),
                    'required' => false,
                ]],
                'auto_renew' => ['radio', [
                    'label' => __trans('NameSilo auto-renewal'),
                    'description' => __trans('Keep this off: ClientFence renews a domain when its renewal invoice is paid. On, NameSilo also renews it from your account.'),
                    'multiOptions' => ['1' => __trans('Yes'), '0' => __trans('No')],
                    'default' => '0',
                ]],
            ],
        ];
    }

    public static function getSecretFields(): array
    {
        return ['api_key', 'sandbox_api_key'];
    }

    public static function getDomainFields(string $tld, string $action = 'register'): array
    {
        // NameSilo takes the nexus category and purpose only (usnc, usap), and for .eu the citizenship (eucs), for registrations and transfers.
        return match (strtolower($tld)) {
            '.us' => array_diff_key(self::usNexusFields(), ['nexus_country' => true]),
            '.eu' => ['eu_citizenship' => ['label' => __trans('EU country of citizenship of the registrant'), 'required' => true,
                'options' => array_combine(EuVat::COUNTRIES, array_map(fn (string $code) => Countries::getName($code), EuVat::COUNTRIES))]],
            default => [],
        };
    }

    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        foreach ($this->request('checkRegisterAvailability', ['domains' => $domain->getName()])->reply->available->domain ?? [] as $found) {
            if (strcasecmp(trim((string) $found), $domain->getName()) === 0) {
                if ((string) ($found['premium'] ?? '0') === '1') {
                    throw new Registrar_Exception('Premium domains cannot be registered.');
                }

                return true;
            }
        }

        return false;
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        foreach ($this->request('checkTransferAvailability', ['domains' => $domain->getName()])->reply->available->domain ?? [] as $found) {
            if (strcasecmp(trim((string) $found), $domain->getName()) === 0) {
                // A premium name costs more than the TLD's transfer price.
                if ((string) ($found['premium'] ?? '0') === '1') {
                    throw new Registrar_Exception('Premium domains cannot be transferred.');
                }

                return true;
            }
        }

        return false;
    }

    public function modifyNs(Registrar_Domain $domain): bool
    {
        $this->request('changeNameServers', ['domain' => $domain->getName()] + $this->nameservers($domain));

        return true;
    }

    public function modifyContact(Registrar_Domain $domain): bool
    {
        $id = trim((string) ($this->request('contactAdd', $this->contact($domain->getContactRegistrar()) + $this->eligibility($domain))->reply->contact_id ?? ''));
        if ($id === '') {
            throw new Registrar_Exception('NameSilo did not return a contact ID.');
        }
        $this->request('contactDomainAssociate', ['domain' => $domain->getName(), 'registrant' => $id, 'administrative' => $id, 'billing' => $id, 'technical' => $id]);

        return true;
    }

    public function transferDomain(Registrar_Domain $domain): bool
    {
        // The code is optional: .uk transfers have none (the owner changes the IPS tag instead).
        $this->ordered($domain, $this->request('transferDomain', ['domain' => $domain->getName()] + array_filter(['auth' => trim((string) $domain->getEpp())]) + ['private' => $domain->getPrivacyEnabled() ? 1 : 0, 'auto_renew' => (int) $this->config['auto_renew']]
            + $this->contact($domain->getContactRegistrar()) + $this->eligibility($domain) + $this->payment()));

        return true;
    }

    public function getDomainDetails(Registrar_Domain $domain): Registrar_Domain
    {
        $info = $this->request('getDomainInfo', ['domain' => $domain->getName()])->reply;

        $details = new Registrar_Domain();
        $details->setSld($domain->getSld());
        $details->setTld($domain->getTld());
        $details->setRegistrationTime(strtotime((string) ($info->created ?? '')) ?: null);
        $details->setExpirationTime(strtotime((string) ($info->expires ?? '')) ?: $domain->getExpirationTime());
        $details->setLocked(strcasecmp((string) ($info->locked ?? ''), 'Yes') === 0);
        $details->setPrivacyEnabled(strcasecmp((string) ($info->private ?? ''), 'Yes') === 0);
        $details->setEpp($domain->getEpp());
        $i = 1;
        foreach ($info->nameservers->nameserver ?? [] as $host) {
            if ($i <= 4 && trim((string) $host) !== '') {
                $details->{'setNs' . $i++}(strtolower(trim((string) $host)));
            }
        }
        // NameSilo shows its privacy service as the contact when privacy is on: keep ClientFence's copy.
        if ($domain->getContactRegistrar() instanceof Registrar_Domain_Contact) {
            foreach (['Registrar', 'Admin', 'Tech', 'Billing'] as $role) {
                $details->{'setContact' . $role}($domain->getContactRegistrar());
            }
        }

        return $details;
    }

    public function getEpp(Registrar_Domain $domain): string
    {
        $this->request('retrieveAuthCode', ['domain' => $domain->getName()]);

        // NameSilo does not return the code: it emails it to the domain's administrative contact.
        return __trans('NameSilo has emailed the transfer code to the domain\'s administrative contact.');
    }

    public function registerDomain(Registrar_Domain $domain): bool
    {
        $params = [
            'domain' => $domain->getName(),
            'years' => min(10, max(1, (int) $domain->getRegistrationPeriod())),
            'private' => $domain->getPrivacyEnabled() ? 1 : 0,
            'auto_renew' => (int) $this->config['auto_renew'],
        ] + $this->contact($domain->getContactRegistrar()) + $this->eligibility($domain) + $this->nameservers($domain) + $this->payment();
        $this->ordered($domain, $this->request('registerDomain', $params));

        return true;
    }

    public function renewDomain(Registrar_Domain $domain): bool
    {
        // NameSilo's renewDomain restores a domain in its restore period, at the restoration fee: never pay that automatically.
        if (preg_match('/restor|redemption/i', (string) ($this->request('getDomainInfo', ['domain' => $domain->getName()])->reply->status ?? ''))) {
            throw new Registrar_Exception(':domain is in its restore period: restore it at the registrar if it should be kept.', [':domain' => $domain->getName()]);
        }
        $this->request('renewDomain', ['domain' => $domain->getName(), 'years' => max(1, (int) $domain->getRegistrationPeriod())] + $this->payment());

        return true;
    }

    public function deleteDomain(Registrar_Domain $domain): bool
    {
        // The API cannot delete a name: a cancelled domain stays at NameSilo until it expires unrenewed (as WHMCS).
        $this->getLog()->info(sprintf('%s was cancelled; NameSilo keeps it until it expires.', $domain->getName()));

        return true;
    }

    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->request('addPrivacy', ['domain' => $domain->getName()]);

        return true;
    }

    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->request('removePrivacy', ['domain' => $domain->getName()]);

        return true;
    }

    public function lock(Registrar_Domain $domain): bool
    {
        $this->request('domainLock', ['domain' => $domain->getName()]);

        return true;
    }

    public function unlock(Registrar_Domain $domain): bool
    {
        $this->request('domainUnlock', ['domain' => $domain->getName()]);

        return true;
    }

    /**
     * After a registration or transfer: reply 302 means NameSilo used the account's default contact profile, 301 its own
     * name servers, so set the client's contact or the order's name servers now.
     */
    private function ordered(Registrar_Domain $domain, SimpleXMLElement $reply): void
    {
        $code = (int) $reply->reply->code;
        // NameSilo has taken the order: a failed follow-up is logged, never thrown (the order must not fail and re-register).
        try {
            if ($code === 302) {
                $this->getLog()->warn(sprintf('NameSilo used the account contact for %s: assigning the client\'s contact.', $domain->getName()));
                $this->modifyContact($domain);
            } elseif ($code === 301) {
                $this->getLog()->warn(sprintf('NameSilo did not set the name servers of %s: setting them again.', $domain->getName()));
                $this->modifyNs($domain);
            }
        } catch (Registrar_Exception $e) {
            $this->getLog()->err(sprintf('NameSilo follow-up for %s failed, fix it in the domain\'s page: %s', $domain->getName(), $e->getMessage()));
        }
    }

    /** The order's .us answers (usnc, usap) or .eu citizenship (eucs); none for other TLDs. */
    private function eligibility(Registrar_Domain $domain): array
    {
        $f = $domain->getFields();

        return array_filter(match ($domain->getTld()) {
            '.us' => ['usnc' => $f['nexus_category'] ?? '', 'usap' => $f['app_purpose'] ?? ''],
            '.eu' => ['eucs' => $f['eu_citizenship'] ?? ''],
            default => [],
        });
    }

    /** One API call; reply codes 300-302 are success (301/302: registered, but name servers or contacts were not set). */
    private function request(string $operation, array $params): SimpleXMLElement
    {
        $key = $this->_testMode ? $this->config['sandbox_api_key'] : $this->config['api_key'];
        if (empty($key)) {
            throw new Registrar_Exception('The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing', [':domain_registrar' => 'NameSilo', ':missing' => $this->_testMode ? 'sandbox API key' : 'API key'], 3001);
        }

        try {
            $body = $this->getHttpClient()->request('GET', ($this->_testMode ? self::SANDBOX_URL : self::LIVE_URL) . $operation, [
                'query' => ['version' => 1, 'type' => 'xml', 'key' => $key] + $params,
                'timeout' => 60,
            ])->getContent();
        } catch (ExceptionInterface $e) {
            // The message can contain the request URL, which carries the key.
            $this->getLog()->err('NameSilo ' . $operation . ' HTTP error: ' . str_replace($key, '***', $e->getMessage()));

            throw new Registrar_Exception('Could not reach :registrar: :error', [':registrar' => 'NameSilo', ':error' => $operation]);
        }

        $xml = @simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        if (!$xml instanceof SimpleXMLElement || !isset($xml->reply->code)) {
            throw new Registrar_Exception('NameSilo returned an unreadable response for :command', [':command' => $operation]);
        }
        if (!in_array((int) $xml->reply->code, [300, 301, 302], true)) {
            $this->getLog()->err(sprintf('NameSilo %s refused: %s %s', $operation, $xml->reply->code, $xml->reply->detail));

            throw new Registrar_Exception(':registrar refused the request: :error', [':registrar' => 'NameSilo', ':error' => (string) $xml->reply->detail]);
        }

        return $xml;
    }

    private function nameservers(Registrar_Domain $domain): array
    {
        $hosts = array_values(array_filter(array_map(fn ($ns) => trim((string) $ns), [$domain->getNs1(), $domain->getNs2(), $domain->getNs3(), $domain->getNs4()])));
        if (count($hosts) < 2) {
            throw new Registrar_Exception('At least two name servers are required.');
        }
        $params = [];
        foreach ($hosts as $i => $host) {
            $params['ns' . ($i + 1)] = $host;
        }

        return $params;
    }

    private function contact(?Registrar_Domain_Contact $c): array
    {
        if (!$c instanceof Registrar_Domain_Contact) {
            throw new Registrar_Exception('The registrant contact is missing.');
        }
        $tel = (string) preg_replace('/\D+/', '', (string) $c->getTel());

        return array_filter([
            'fn' => (string) $c->getFirstName(),
            'ln' => (string) $c->getLastName(),
            'cp' => (string) $c->getCompany(),
            'ad' => (string) $c->getAddress1(),
            'ad2' => (string) $c->getAddress2(),
            'cy' => (string) $c->getCity(),
            'st' => (string) $c->getState(),
            'zp' => (string) $c->getZip(),
            'ct' => strtoupper((string) $c->getCountry()),
            'em' => (string) $c->getEmail(),
            // Digits only, without the country dialing code (NameSilo's contact rules).
            'ph' => $tel,
        ], fn (string $value) => $value !== '');
    }

    private function payment(): array
    {
        return $this->config['payment_id'] === '' ? [] : ['payment_id' => $this->config['payment_id']];
    }
}
