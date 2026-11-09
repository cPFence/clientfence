<?php

declare(strict_types=1);
/**
 * Enom registrar adapter (ClientFence).
 *
 * Based on the Enom adapter for FOSSBilling by TAJ IT Solutions (https://github.com/TAJ-IT-Solutions-Limited/fossbilling-enom,
 * Apache-2.0), reworked for ClientFence. Uses the Enom reseller API (https://api.enom.com/docs): POST to interface.asp
 * with the reseller ID and API token in the body, XML replies; the registrar's test mode uses resellertest.enom.com with
 * an Enom test account. The client's contact is sent for all four roles. .us names ask the nexus questions on the order
 * form (getDomainFields()), sent as Enom's extended attributes.
 */
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

class Registrar_Adapter_Enom extends Registrar_AdapterAbstract
{
    private const LIVE_URL = 'https://reseller.enom.com/interface.asp';
    private const TEST_URL = 'https://resellertest.enom.com/interface.asp';

    public $config = [
        'uid' => null,
        'pw' => null,
    ];

    public function __construct($options)
    {
        if (empty($options['uid']) || empty($options['pw'])) {
            throw new Registrar_Exception('The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing', [':domain_registrar' => 'Enom', ':missing' => 'reseller ID and API token'], 3001);
        }

        $this->config['uid'] = trim((string) $options['uid']);
        $this->config['pw'] = trim((string) $options['pw']);
    }

    public static function getConfig(): array
    {
        return [
            'label' => __trans('Manages domains on Enom through its reseller API. Allow this server\'s IP address in the Enom API settings and keep enough account funds for registrations and renewals. Enom auto-renewal is off for every domain: ClientFence renews a domain when its renewal invoice is paid. With test mode on, enter the reseller ID and API token of your Enom test account (resellertest.enom.com).'),
            'form' => [
                'uid' => ['text', [
                    'label' => __trans('Reseller ID'),
                    'description' => __trans('Your Enom account ID (login name).'),
                    'required' => true,
                ]],
                'pw' => ['password', [
                    'label' => __trans('API token'),
                    'description' => __trans('From Enom: Resellers > API settings.'),
                    'required' => true,
                    'secret' => true,
                ]],
            ],
        ];
    }

    public static function getSecretFields(): array
    {
        return ['pw'];
    }

    public static function getDomainFields(string $tld, string $action = 'register'): array
    {
        // Enom's extended attributes for .us (us_nexus, global_cc_us, us_purpose), for registrations and transfers.
        return strtolower($tld) === '.us' ? self::usNexusFields() : [];
    }

    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        $reply = $this->check($domain);
        if ((int) $reply->RRPCode === 210 && $this->premium($reply)) {
            throw new Registrar_Exception('Premium domains cannot be registered.');
        }

        return (int) $reply->RRPCode === 210;
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        $reply = $this->check($domain);
        // A premium name costs more than the TLD's transfer price.
        if ($this->premium($reply)) {
            throw new Registrar_Exception('Premium domains cannot be transferred.');
        }

        return (int) $reply->RRPCode === 211;
    }

    public function modifyNs(Registrar_Domain $domain): bool
    {
        $this->request('ModifyNS', $this->name($domain) + $this->nameservers($domain));

        return true;
    }

    public function modifyContact(Registrar_Domain $domain): bool
    {
        $this->request('Contacts', $this->name($domain) + $this->contacts($domain, ['Registrant', 'Admin', 'Tech', 'AuxBilling']) + $this->eligibility($domain));

        return true;
    }

    public function transferDomain(Registrar_Domain $domain): bool
    {
        $reply = $this->request('TP_CreateOrder', [
            'OrderType' => 'Autoverification',
            'DomainCount' => 1,
            'SLD1' => $domain->getSld(),
            'TLD1' => ltrim((string) $domain->getTld(), '.'),
            // No Enom auto-renewal: ClientFence renews on the paid invoice.
            'Renew' => 0,
            'Lock' => 1,
        // The code is optional: .uk transfers have none (the owner changes the IPS tag instead).
        ] + array_filter(['AuthInfo1' => trim((string) $domain->getEpp())]) + $this->contacts($domain, ['Registrant']) + $this->eligibility($domain));
        $this->getLog()->info(sprintf('Enom transfer order %s for %s: %s', $reply->transferorder->transferorderid ?? '', $domain->getName(), $reply->transferorder->statusdesc ?? ''));

        return true;
    }

    public function getDomainDetails(Registrar_Domain $domain): Registrar_Domain
    {
        $info = $this->request('GetDomainInfo', $this->name($domain))->GetDomainInfo;

        $details = new Registrar_Domain();
        $details->setSld($domain->getSld());
        $details->setTld($domain->getTld());
        $details->setExpirationTime($this->time((string) ($info->status->expiration ?? '')) ?? $domain->getExpirationTime());
        $details->setEpp($domain->getEpp());
        $i = 1;
        foreach ($info->services->entry ?? [] as $entry) {
            if ((string) $entry['name'] === 'dnsserver') {
                foreach ($entry->configuration->dns ?? [] as $host) {
                    if ($i <= 4 && trim((string) $host) !== '') {
                        $details->{'setNs' . $i++}(strtolower(trim((string) $host)));
                    }
                }
            }
        }
        // Enom returns no registration date or contacts here: keep ClientFence's copy.
        if ($domain->getContactRegistrar() instanceof Registrar_Domain_Contact) {
            foreach (['Registrar', 'Admin', 'Tech', 'Billing'] as $role) {
                $details->{'setContact' . $role}($domain->getContactRegistrar());
            }
        }

        return $details;
    }

    public function getEpp(Registrar_Domain $domain): string
    {
        // Enom gives the code over the API for some accounts and TLDs only.
        try {
            $code = trim((string) ($this->request('GetAuthCode', $this->name($domain))->AuthCode ?? ''));
        } catch (Registrar_Exception $e) {
            $this->getLog()->err(sprintf('Enom GetAuthCode for %s failed: %s', $domain->getName(), $e->getMessage()));
            $code = '';
        }
        if ($code === '') {
            throw new Registrar_Exception('The transfer code could not be retrieved automatically. Please contact support to receive it.');
        }

        return $code;
    }

    public function registerDomain(Registrar_Domain $domain): bool
    {
        $reply = $this->request('Purchase', $this->name($domain) + [
            'NumYears' => min(10, max(1, (int) $domain->getRegistrationPeriod())),
            // No Enom auto-renewal: ClientFence renews on the paid invoice.
            'RenewName' => 0,
        ] + $this->nameservers($domain) + $this->contacts($domain, ['Registrant', 'Admin', 'Tech', 'AuxBilling']) + $this->eligibility($domain));
        $this->done($reply, [200, 1300]);

        return true;
    }

    public function renewDomain(Registrar_Domain $domain): bool
    {
        $years = max(1, (int) $domain->getRegistrationPeriod());
        // Extend refuses an expired name: a renewal paid in the grace period goes through UpdateExpiredDomains.
        if ($domain->getExpirationTime() && $domain->getExpirationTime() < time()) {
            $this->done($this->request('UpdateExpiredDomains', ['DomainName' => $domain->getName(), 'NumYears' => $years]), [200]);
        } else {
            $this->done($this->request('Extend', $this->name($domain) + ['NumYears' => $years]), [200]);
        }

        return true;
    }

    public function deleteDomain(Registrar_Domain $domain): bool
    {
        // A cancelled domain is left to expire unrenewed (as WHMCS), never deleted from the registry.
        $this->getLog()->info(sprintf('%s was cancelled; Enom keeps it until it expires.', $domain->getName()));

        return true;
    }

    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        // Enom sells WHOIS privacy (ID Protect) as a paid service: turning it on here would buy it without a charge to the client.
        throw new Registrar_Exception('Enom sells WHOIS privacy as a paid service: turn it on in the Enom control panel.');
    }

    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->request('DisableServices', $this->name($domain) + ['Service' => 'WPPS']);

        return true;
    }

    public function lock(Registrar_Domain $domain): bool
    {
        $this->request('SetRegLock', $this->name($domain) + ['UnlockRegistrar' => 0]);

        return true;
    }

    public function unlock(Registrar_Domain $domain): bool
    {
        $this->request('SetRegLock', $this->name($domain) + ['UnlockRegistrar' => 1]);

        return true;
    }

    /** One API call; returns the XML reply. ErrCount above 0 is a registrar error with Enom's messages. */
    private function request(string $command, array $params): SimpleXMLElement
    {
        try {
            $body = $this->getHttpClient()->request('POST', $this->_testMode ? self::TEST_URL : self::LIVE_URL, [
                'body' => ['command' => $command, 'uid' => $this->config['uid'], 'pw' => $this->config['pw'], 'responsetype' => 'xml'] + $params,
                'timeout' => 90,
            ])->getContent(false);
        } catch (ExceptionInterface $e) {
            $this->getLog()->err('Enom ' . $command . ' HTTP error: ' . $e->getMessage());

            throw new Registrar_Exception('Could not reach :registrar: :error', [':registrar' => 'Enom', ':error' => $command]);
        }

        $xml = @simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        if (!$xml instanceof SimpleXMLElement || !isset($xml->ErrCount)) {
            // Usually the server's IP address is not allowed in the Enom API settings.
            throw new Registrar_Exception('Enom returned an unreadable response for :command', [':command' => $command]);
        }
        if ((int) $xml->ErrCount > 0) {
            $errors = [];
            foreach ($xml->errors->children() ?? [] as $error) {
                $errors[] = trim((string) $error);
            }
            $error = implode('; ', array_filter($errors)) ?: (string) $xml->ErrCount;
            $this->getLog()->err(sprintf('Enom %s refused: %s', $command, $error));

            throw new Registrar_Exception(':registrar refused the request: :error', [':registrar' => 'Enom', ':error' => $error]);
        }

        return $xml;
    }

    /** Purchase and Extend succeed only with these RRP codes (1300: a queued registration). */
    private function done(SimpleXMLElement $reply, array $codes): void
    {
        if (isset($reply->RRPCode) && !in_array((int) $reply->RRPCode, $codes, true)) {
            throw new Registrar_Exception(':registrar refused the request: :error', [':registrar' => 'Enom', ':error' => trim((string) ($reply->RRPText ?? $reply->RRPCode))]);
        }
    }

    private function name(Registrar_Domain $domain): array
    {
        return ['SLD' => $domain->getSld(), 'TLD' => ltrim((string) $domain->getTld(), '.')];
    }

    private function check(Registrar_Domain $domain): SimpleXMLElement
    {
        $reply = $this->request('Check', $this->name($domain));
        if (!isset($reply->RRPCode)) {
            throw new Registrar_Exception('Enom returned an unreadable response for :command', [':command' => 'Check']);
        }

        return $reply;
    }

    private function premium(SimpleXMLElement $reply): bool
    {
        // An Early Access (EAP) name also carries a fee above the TLD price.
        foreach (['IsPremiumName', 'IsPremium', 'IsEAP'] as $flag) {
            if (strcasecmp((string) ($reply->{$flag} ?? ''), 'true') === 0) {
                return true;
            }
        }

        return false;
    }

    /** Enom dates are "m/d/Y h:i:s A" in its own clock: noon UTC keeps the calendar day. */
    private function time(string $value): ?int
    {
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', trim($value), $m) === 1 && checkdate((int) $m[1], (int) $m[2], (int) $m[3])) {
            return gmmktime(12, 0, 0, (int) $m[1], (int) $m[2], (int) $m[3]);
        }

        return null;
    }

    private function nameservers(Registrar_Domain $domain): array
    {
        $hosts = array_values(array_filter(array_map(fn ($ns) => trim((string) $ns), [$domain->getNs1(), $domain->getNs2(), $domain->getNs3(), $domain->getNs4()])));
        if (count($hosts) < 2) {
            throw new Registrar_Exception('At least two name servers are required.');
        }
        $params = [];
        foreach ($hosts as $i => $host) {
            $params['NS' . ($i + 1)] = $host;
        }

        return $params;
    }

    /** The order's .us answers as Enom extended attributes; none for other TLDs. */
    private function eligibility(Registrar_Domain $domain): array
    {
        $f = $domain->getFields();

        return $domain->getTld() === '.us' ? array_filter([
            'us_nexus' => $f['nexus_category'] ?? '',
            'global_cc_us' => in_array($f['nexus_category'] ?? '', ['C31', 'C32'], true) ? strtoupper((string) ($f['nexus_country'] ?? '')) : '',
            'us_purpose' => $f['app_purpose'] ?? '',
        ]) : [];
    }

    /** The client's contact for each of the given Enom roles (Registrant, Admin, Tech, AuxBilling). */
    private function contacts(Registrar_Domain $domain, array $roles): array
    {
        $c = $domain->getContactRegistrar();
        if (!$c instanceof Registrar_Domain_Contact) {
            throw new Registrar_Exception('The registrant contact is missing.');
        }
        $cc = (string) preg_replace('/\D+/', '', (string) $c->getTelCc());
        $tel = (string) preg_replace('/\D+/', '', (string) $c->getTel());
        // The international form drops the national trunk 0 (Italian numbers keep it).
        $phone = '+' . $cc . '.' . ($cc === '39' ? $tel : ltrim($tel, '0'));
        $fields = array_filter([
            'FirstName' => (string) $c->getFirstName(),
            'LastName' => (string) $c->getLastName(),
            'OrganizationName' => (string) $c->getCompany(),
            'Address1' => (string) $c->getAddress1(),
            'Address2' => (string) $c->getAddress2(),
            'City' => (string) $c->getCity(),
            // Enom requires a state or province: the city stands in where there is none.
            'StateProvince' => trim((string) $c->getState()) !== '' ? (string) $c->getState() : (string) $c->getCity(),
            'PostalCode' => (string) $c->getZip(),
            'Country' => strtoupper((string) $c->getCountry()),
            'EmailAddress' => (string) $c->getEmail(),
            'Phone' => $phone,
            'Fax' => $phone,
        ], fn (string $value) => trim($value) !== '');
        // Enom requires a job title with an organisation name.
        if (isset($fields['OrganizationName'])) {
            $fields['JobTitle'] = 'N/A';
        }

        $params = [];
        foreach ($roles as $role) {
            foreach ($fields as $key => $value) {
                $params[$role . $key] = $value;
            }
        }

        return $params;
    }
}
