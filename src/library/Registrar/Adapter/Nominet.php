<?php

declare(strict_types=1);
/**
 * Nominet registrar adapter (ClientFence), for .uk names on a Nominet registrar (IPS) tag.
 *
 * Speaks Nominet's EPP (RFC 5730-5733 with Nominet's extensions,
 * https://registrars.nominet.uk/uk-namespace/registration-and-domain-management/registration-systems/epp/epp-commands/)
 * over TLS with the tag and its EPP password. Until 9 February 2027 it uses epp.nominet.org.uk:700
 * (OT&E: ote-epp.nominet.org.uk:700), the current registrar moves the domain onto this tag, and .uk registrations
 * ask for the registrant type. From the cutover it uses uk.epp.nominet.uk:700 (OT&E: ote-uk.epp.nominet.uk:700)
 * and pulls transfers with a code and one year. A transfer completes once domain:info confirms this tag. The price list uses
 * the cost price from the settings, in GBP.
 */
class Registrar_Adapter_Nominet extends Registrar_AdapterAbstract implements Registrar_TldPrices, Registrar_TransferStatus
{
    private const LIVE_HOST = 'epp.nominet.org.uk';
    private const TEST_HOST = 'ote-epp.nominet.org.uk';
    private const PORT = 700;
    private const EPP = 'urn:ietf:params:xml:ns:epp-1.0';
    private const DOMAIN = 'urn:ietf:params:xml:ns:domain-1.0';
    private const CONTACT = 'urn:ietf:params:xml:ns:contact-1.0';
    private const RGP = 'urn:ietf:params:xml:ns:rgp-1.0';
    private const HOST = 'urn:ietf:params:xml:ns:host-1.0';
    private const CONTACT_EXT = 'http://www.nominet.org.uk/epp/xml/contact-nom-ext-1.0';
    /** The extensions sold through the tag, as Blesta's module lists them. */
    private const TLDS = ['.uk', '.co.uk', '.org.uk', '.me.uk', '.sch.uk', '.wales', '.cymru'];
    // Registrations Nominet refers for manual checking and answers later in its poll queue, which ClientFence does not read.
    private const REFERRED = ['.ltd.uk', '.net.uk', '.plc.uk'];
    private const TRANSFER_LOCK = 'clientTransferProhibited';
    // RFC 9154 transfer codes: Nominet's new .uk platform needs this extension at login.
    private const TAC_EXT = 'urn:ietf:params:xml:ns:epp:secure-authinfo-transfer-1.0';
    // Nominet's .uk platform change on 9 February 2027.
    private const STANDARD_FROM = 1802131200;

    public $config = [
        'username' => null,
        'password' => null,
        'cost_price' => '',
        'certificate_file' => '',
    ];

    /** @var resource|null */
    private $socket;
    private bool $loggedIn = false;

    public function __construct($options)
    {
        if (trim((string) ($options['username'] ?? '')) === '' || (string) ($options['password'] ?? '') === '') {
            throw new Registrar_Exception(':domain_registrar is not fully configured. Please configure the :missing', [':domain_registrar' => 'Nominet', ':missing' => 'IPS tag and EPP password'], 3001);
        }

        $this->config['username'] = trim((string) $options['username']);
        $this->config['password'] = (string) $options['password'];
        $this->config['cost_price'] = trim((string) ($options['cost_price'] ?? ''));
        $this->config['certificate_file'] = trim((string) ($options['certificate_file'] ?? ''));
    }

    public function __destruct()
    {
        if ($this->loggedIn) {
            $this->loggedIn = false;

            try {
                $this->exchange('<?xml version="1.0" encoding="UTF-8"?><epp xmlns="' . self::EPP . '"><command><logout/></command></epp>');
            } catch (Registrar_Exception) {
                // Nominet ends the session with the connection anyway; a destructor must not throw.
            }
        }
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    public static function ukStandardised(): bool
    {
        return time() >= self::STANDARD_FROM;
    }

    public static function getUnsupportedActions(): array
    {
        $actions = ['enablePrivacyProtection', 'disablePrivacyProtection'];
        if (!static::ukStandardised()) {
            $actions[] = 'getEpp';
        }

        return $actions;
    }

    public static function getConfig(): array
    {
        return [
            'label' => __trans('Manages .uk domains on your Nominet tag through Nominet\'s EPP service. In Nominet Online Services, turn on EPP for the tag, set its EPP password (at least 14 characters) and allow this server\'s IP address. Test mode uses Nominet\'s OT&E server with the EPP testbed details (Online Services > Tag Settings > EPP Testbed). Transfers in: until 9 February 2027 the current registrar moves the domain to your tag; from then on Nominet\'s new .uk platform is used and a transfer needs the domain\'s transfer code and adds one year.'),
            'form' => [
                'username' => ['text', [
                    'label' => __trans('IPS tag'),
                    'description' => __trans('Your Nominet registrar tag, also the EPP login name.'),
                    'required' => true,
                ]],
                'password' => ['password', [
                    'label' => __trans('EPP password'),
                    'description' => __trans('The EPP password of the tag, from Nominet Online Services.'),
                    'required' => true,
                    'secret' => true,
                ]],
                'certificate_file' => ['text', [
                    'label' => __trans('Client certificate file'),
                    'description' => __trans('Only if you uploaded a client certificate to Nominet instead of allowing this server\'s IP address: the full path on this server of a PEM file with the certificate and its private key.'),
                    'required' => false,
                ]],
                'cost_price' => ['text', [
                    'label' => __trans('Cost price (GBP)'),
                    'description' => __trans('What Nominet charges you for one year of a domain. Nominet has no price list to import, so the price import uses this for every extension and action.'),
                    'required' => false,
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
        // A transfer keeps the registrant Nominet already has: only a new .uk registrant account takes these details.
        if (static::ukStandardised() || $action !== 'register' || !str_ends_with(strtolower($tld), '.uk')) {
            return [];
        }

        return [
            'registrant_type' => ['label' => __trans('Registrant type'), 'required' => true, 'options' => [
                'IND' => __trans('UK individual'), 'STRA' => __trans('UK sole trader'), 'LTD' => __trans('UK limited company'),
                'PLC' => __trans('UK public limited company'), 'PTNR' => __trans('UK partnership'), 'LLP' => __trans('UK limited liability partnership'),
                'IP' => __trans('UK industrial/provident registered company'), 'SCH' => __trans('UK school'), 'RCHAR' => __trans('UK registered charity'),
                'GOV' => __trans('UK government body'), 'CRC' => __trans('UK corporation by Royal Charter'), 'STAT' => __trans('UK statutory body'),
                'OTHER' => __trans('Other UK organisation (such as a club or association)'), 'FIND' => __trans('Individual outside the UK'),
                'FCORP' => __trans('Company outside the UK'), 'FOTHER' => __trans('Other organisation outside the UK'),
            ]],
            'company_number' => ['label' => __trans('Company, charity or school number (UK companies, charities and schools)')],
            'trading_name' => ['label' => __trans('Trading name, if different from the registrant name')],
        ];
    }

    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        if (in_array(strtolower((string) $domain->getTld()), self::REFERRED, true)) {
            throw new Registrar_Exception(':tld registrations are checked by hand at Nominet: register them in Nominet Online Services.', [':tld' => $domain->getTld()]);
        }

        return $this->available($domain);
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        // A transfer needs a registered name that is not already on this tag.
        return !$this->available($domain) && !$this->onOurTag($domain);
    }

    public function modifyNs(Registrar_Domain $domain): bool
    {
        $current = $this->hosts($this->info($domain));
        $wanted = $this->nameservers($domain);
        $add = array_diff($wanted, $current);
        $remove = array_diff($current, $wanted);
        // An update with nothing to add or remove is a syntax error at the registry.
        if ($add === [] && $remove === []) {
            return true;
        }
        $this->ensureHosts(array_values($add));
        $this->command('<update><domain:update xmlns:domain="' . self::DOMAIN . '"><domain:name>' . $this->x($domain->getName()) . '</domain:name>'
            . ($add !== [] ? '<domain:add>' . $this->hostObjs($add) . '</domain:add>' : '')
            . ($remove !== [] ? '<domain:rem>' . $this->hostObjs($remove) . '</domain:rem>' : '')
            . '</domain:update></update>');

        return true;
    }

    public function modifyContact(Registrar_Domain $domain): bool
    {
        $registrant = $this->values($this->info($domain), '//domain:infData/domain:registrant')[0] ?? '';
        if ($registrant === '') {
            throw new Registrar_Exception(':registrar returned an unreadable response for :command', [':registrar' => 'Nominet', ':command' => 'domain:info']);
        }
        $contact = $this->contact($domain);
        // Nominet changes the registrant account in place; its name (contact:org) cannot change this way.
        $this->command('<update><contact:update xmlns:contact="' . self::CONTACT . '"><contact:id>' . $this->x($registrant) . '</contact:id><contact:chg>'
            . $this->postalInfo($contact, false) . $this->voiceEmail($contact) . '</contact:chg></contact:update></update>', $this->contactExt($domain, 'update'));

        return true;
    }

    public function transferDomain(Registrar_Domain $domain): bool
    {
        $standardised = static::ukStandardised();
        $code = $standardised ? (string) $domain->getEpp() : '';
        if ($standardised && trim($code) === '') {
            throw new Registrar_Exception('A transfer code is required to transfer :domain.', [':domain' => $domain->getName()]);
        }

        if ($this->onOurTag($domain)) {
            $this->getLog()->info(sprintf('%s is already on the Nominet tag %s.', $domain->getName(), $this->config['username']));
        } elseif ($standardised) {
            $this->command('<transfer op="request"><domain:transfer xmlns:domain="' . self::DOMAIN . '"><domain:name>' . $this->x($domain->getName()) . '</domain:name>'
                . '<domain:period unit="y">1</domain:period><domain:authInfo><domain:pw>' . $this->x($code) . '</domain:pw></domain:authInfo></domain:transfer></transfer>');
        } else {
            $this->getLog()->warn(sprintf('%s waits for its current registrar to move it to the Nominet tag %s.', $domain->getName(), $this->config['username']));
        }

        return true;
    }

    public function getTransferStatus(Registrar_Domain $domain): array
    {
        return $this->onOurTag($domain)
            ? ['status' => Registrar_TransferStatus::COMPLETED, 'message' => '']
            : ['status' => Registrar_TransferStatus::PENDING, 'message' => static::ukStandardised()
                ? __trans('Waiting for Nominet to complete the transfer of :domain.', [':domain' => $domain->getName()])
                : sprintf('Waiting for the current registrar to change the IPS tag of %s to %s.', $domain->getName(), $this->config['username'])];
    }

    public function getDomainDetails(Registrar_Domain $domain): Registrar_Domain
    {
        $info = $this->info($domain);

        $details = new Registrar_Domain();
        $details->setSld($domain->getSld());
        $details->setTld($domain->getTld());
        $details->setRegistrationTime(strtotime($this->values($info, '//domain:infData/domain:crDate')[0] ?? '') ?: null);
        $details->setExpirationTime(strtotime($this->values($info, '//domain:infData/domain:exDate')[0] ?? '') ?: $domain->getExpirationTime());
        $details->setLocked(in_array(self::TRANSFER_LOCK, $this->values($info, '//domain:infData/domain:status/@s'), true));
        $details->setEpp($domain->getEpp());
        foreach (array_slice($this->hosts($info), 0, 4) as $i => $host) {
            $details->{'setNs' . ($i + 1)}($host);
        }
        // domain:info returns the registrant's handle only: keep ClientFence's copy of the contact.
        if ($domain->getContactRegistrar() instanceof Registrar_Domain_Contact) {
            foreach (['Registrar', 'Admin', 'Tech', 'Billing'] as $role) {
                $details->{'setContact' . $role}($domain->getContactRegistrar());
            }
        }

        return $details;
    }

    public function getEpp(Registrar_Domain $domain): string
    {
        if (!static::ukStandardised()) {
            throw new Registrar_Exception('.uk domains have no transfer code: to move :domain, give us the IPS tag of the new registrar.', [':domain' => $domain->getName()]);
        }

        // Nominet wants a lower and upper case letter, a digit and a special character in every code.
        $code = bin2hex(random_bytes(16)) . 'X-';
        $this->command('<update><domain:update xmlns:domain="' . self::DOMAIN . '"><domain:name>' . $this->x($domain->getName()) . '</domain:name>'
            . '<domain:chg><domain:authInfo><domain:pw>' . $code . '</domain:pw></domain:authInfo></domain:chg></domain:update></update>');

        return $code;
    }

    public function registerDomain(Registrar_Domain $domain): bool
    {
        $contact = $this->contact($domain);
        $nameservers = $this->nameservers($domain);
        $id = 'CF' . strtoupper(bin2hex(random_bytes(6)));
        // An individual or sole trader is the registrant in person: Nominet takes the registrant's name from <contact:org>.
        $withOrg = !in_array($domain->getFields()['registrant_type'] ?? '', ['IND', 'FIND', 'STRA'], true);
        // The standard platform refuses an auth code on contact and domain creation.
        $standardised = static::ukStandardised();
        $this->command('<create><contact:create xmlns:contact="' . self::CONTACT . '"><contact:id>' . $id . '</contact:id>' . $this->postalInfo($contact, $withOrg)
            . $this->voiceEmail($contact) . '<contact:authInfo>' . ($standardised ? '<contact:pw/>' : '<contact:pw>' . bin2hex(random_bytes(8)) . '</contact:pw>') . '</contact:authInfo></contact:create></create>',
            $this->contactExt($domain, 'create'));
        $this->ensureHosts($nameservers);
        $this->command('<create><domain:create xmlns:domain="' . self::DOMAIN . '"><domain:name>' . $this->x($domain->getName()) . '</domain:name>'
            . '<domain:period unit="y">' . min(10, max(1, (int) $domain->getRegistrationPeriod())) . '</domain:period>' . $this->hostObjs($nameservers)
            . '<domain:registrant>' . $id . '</domain:registrant><domain:authInfo>' . ($standardised ? '<domain:pw/>' : '<domain:pw>' . bin2hex(random_bytes(8)) . '</domain:pw>') . '</domain:authInfo></domain:create></create>');

        return true;
    }

    public function renewDomain(Registrar_Domain $domain): bool
    {
        // Nominet renews only against the current expiry date it holds.
        $info = $this->info($domain);
        $expires = $this->values($info, '//domain:infData/domain:exDate')[0] ?? '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $expires) !== 1) {
            throw new Registrar_Exception(':registrar returned an unreadable response for :command', [':registrar' => 'Nominet', ':command' => 'domain:info']);
        }
        $expires = substr($expires, 0, 10);
        // The standard platform auto-renews an expired name for a year: a renewal in that period back-dates to the previous expiry, so that year is not bought twice.
        if (static::ukStandardised() && in_array('autoRenewPeriod', $this->values($info, '//rgp:infData/rgp:rgpStatus/@s'), true)) {
            $expires = (new DateTimeImmutable($expires))->modify('-1 year')->format('Y-m-d');
        }
        $this->command('<renew><domain:renew xmlns:domain="' . self::DOMAIN . '"><domain:name>' . $this->x($domain->getName()) . '</domain:name>'
            . '<domain:curExpDate>' . $expires . '</domain:curExpDate><domain:period unit="y">' . min(10, max(1, (int) $domain->getRegistrationPeriod())) . '</domain:period></domain:renew></renew>');

        return true;
    }

    public function deleteDomain(Registrar_Domain $domain): bool
    {
        // A cancelled domain is left to expire unrenewed (as Blesta and WHMCS), never deleted from the registry.
        $this->getLog()->info(sprintf('%s was cancelled; Nominet keeps it until it expires.', $domain->getName()));

        return true;
    }

    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        throw new Registrar_Exception(':registrar has no privacy service: the .uk WHOIS shows the registrant\'s name and address only with their consent.', [':registrar' => 'Nominet']);
    }

    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        throw new Registrar_Exception(':registrar has no privacy service: the .uk WHOIS shows the registrant\'s name and address only with their consent.', [':registrar' => 'Nominet']);
    }

    public function lock(Registrar_Domain $domain): bool
    {
        $this->status($domain, 'add');

        return true;
    }

    public function unlock(Registrar_Domain $domain): bool
    {
        $this->status($domain, 'rem');

        return true;
    }

    /** One year of every action costs the cost price from the settings (Nominet has no price call). */
    public function getTldPrices(array $tlds = []): array
    {
        if (!is_numeric($this->config['cost_price']) || (float) $this->config['cost_price'] <= 0) {
            throw new Registrar_Exception('Enter the cost price in the Nominet registrar settings: Nominet has no price list to import.');
        }
        $price = (float) $this->config['cost_price'];
        $list = $tlds === [] ? self::TLDS : array_values(array_intersect(self::TLDS, array_map('strtolower', $tlds)));

        // A legacy .uk transfer is a free tag change; the standard platform transfers with one year (.wales and .cymru are gTLDs).
        $free = !static::ukStandardised();
        $prices = array_combine($list, array_map(static fn (string $tld): array => ['register' => $price, 'renew' => $price, 'transfer' => $free && str_ends_with($tld, '.uk') ? 0.0 : $price], $list));

        return ['currency' => 'GBP', 'prices' => $prices, 'remaining' => []];
    }

    /**
     * Opens the TLS connection and returns Nominet's greeting frame.
     *
     * @throws Registrar_Exception
     */
    protected function connect(): string
    {
        $host = static::ukStandardised()
            ? ($this->_testMode ? 'ote-uk.epp.nominet.uk' : 'uk.epp.nominet.uk')
            : ($this->_testMode ? self::TEST_HOST : self::LIVE_HOST);
        $options = ['ssl' => ['crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT, 'peer_name' => $host]];
        // A registrar with a certificate uploaded to Nominet signs in with it instead of an allowed IP address.
        if ($this->config['certificate_file'] !== '') {
            if (!is_readable($this->config['certificate_file'])) {
                throw new Registrar_Exception('The Nominet client certificate file cannot be read: :file', [':file' => $this->config['certificate_file']]);
            }
            $options['ssl']['local_cert'] = $this->config['certificate_file'];
        }
        // The same outgoing address as the HTTP clients (Tools::getDefaultInterface()).
        if (defined('BIND_TO') && filter_var(BIND_TO, FILTER_VALIDATE_IP) !== false) {
            $options['socket'] = ['bindto' => (str_contains((string) BIND_TO, ':') ? '[' . BIND_TO . ']' : BIND_TO) . ':0'];
        }
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $errno = 0;
        $error = '';
        $socket = @stream_socket_client('tls://' . $host . ':' . self::PORT, $errno, $error, 60, STREAM_CLIENT_CONNECT, stream_context_create($options));
        if (!is_resource($socket)) {
            $this->getLog()->err(sprintf('Nominet EPP connection to %s failed: %s', $host, $error));

            throw new Registrar_Exception('Could not reach :registrar: :error', [':registrar' => 'Nominet', ':error' => $error !== '' ? $error : $host]);
        }
        stream_set_timeout($socket, 60);
        $this->socket = $socket;

        return $this->readFrame();
    }

    /**
     * Sends one EPP frame (4-byte length, RFC 5734) and returns the reply frame.
     *
     * @throws Registrar_Exception
     */
    protected function exchange(string $xml): string
    {
        $data = pack('N', strlen($xml) + 4) . $xml;
        while ($data !== '') {
            $written = is_resource($this->socket) ? @fwrite($this->socket, $data) : false;
            if ($written === false || $written === 0) {
                throw new Registrar_Exception('Could not reach :registrar: :error', [':registrar' => 'Nominet', ':error' => 'write']);
            }
            $data = substr($data, $written);
        }

        return $this->readFrame();
    }

    private function readFrame(): string
    {
        $length = unpack('N', $this->read(4))[1];
        // A frame is at least its header; anything near this cap is a broken stream, not a reply.
        if ($length <= 4 || $length > 4194304) {
            throw new Registrar_Exception(':registrar returned an unreadable response for :command', [':registrar' => 'Nominet', ':command' => 'EPP frame']);
        }

        return $this->read($length - 4);
    }

    private function read(int $bytes): string
    {
        $data = '';
        while (strlen($data) < $bytes) {
            $chunk = is_resource($this->socket) ? @fread($this->socket, $bytes - strlen($data)) : false;
            if ($chunk === false || $chunk === '') {
                throw new Registrar_Exception('Could not reach :registrar: :error', [':registrar' => 'Nominet', ':error' => 'read']);
            }
            $data .= $chunk;
        }

        return $data;
    }

    /** One command in the session, logging in first; a result code of 2000 or more becomes a Registrar_Exception with that code. */
    private function command(string $body, string $extension = ''): SimpleXMLElement
    {
        if (!$this->loggedIn) {
            $greeting = $this->parse($this->connect(), 'greeting');
            if ($this->values($greeting, '/e:epp/e:greeting/e:svID') === []) {
                throw new Registrar_Exception(':registrar returned an unreadable response for :command', [':registrar' => 'Nominet', ':command' => 'greeting']);
            }
            $tag = $this->config['username'];
            $extensions = static::ukStandardised()
                ? '<extURI>' . self::TAC_EXT . '</extURI><extURI>' . self::RGP . '</extURI>'
                : '<extURI>http://www.nominet.org.uk/epp/xml/std-notifications-1.2</extURI><extURI>' . self::CONTACT_EXT . '</extURI>';
            // EPP needs 3 to 16 characters: Nominet logs 2-character tags in with a leading #.
            $this->send('<login><clID>' . $this->x(strlen($tag) === 2 ? '#' . $tag : $tag) . '</clID><pw>' . $this->x($this->config['password']) . '</pw>'
                . '<options><version>1.0</version><lang>en</lang></options><svcs><objURI>' . self::DOMAIN . '</objURI><objURI>' . self::CONTACT . '</objURI>'
                . '<objURI>urn:ietf:params:xml:ns:host-1.0</objURI><svcExtension>' . $extensions
                . '<extURI>urn:ietf:params:xml:ns:secDNS-1.1</extURI></svcExtension></svcs></login>');
            $this->loggedIn = true;
        }

        return $this->send($body, $extension);
    }

    private function send(string $body, string $extension = ''): SimpleXMLElement
    {
        // The command's name for logs and errors (login, domain:create, ...); the XML itself is never logged (password, contact data).
        preg_match('/^<(\w+)(?:\s[^>]*)?>(?:<(\w+:\w+))?/', $body, $m);
        $name = $m[2] ?? $m[1] ?? 'command';
        try {
            $reply = $this->parse($this->exchange('<?xml version="1.0" encoding="UTF-8"?><epp xmlns="' . self::EPP . '"><command>' . $body
                . ($extension !== '' ? '<extension>' . $extension . '</extension>' : '') . '<clTRID>CF-' . bin2hex(random_bytes(8)) . '</clTRID></command></epp>'), $name);
        } catch (Registrar_Exception $e) {
            // After a failed or timed-out exchange a late reply may still arrive: the next command starts a new session.
            $this->loggedIn = false;
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
            $this->socket = null;

            throw $e;
        }
        $code = (int) ($this->values($reply, '/e:epp/e:response/e:result/@code')[0] ?? 0);
        if ($code === 0) {
            throw new Registrar_Exception(':registrar returned an unreadable response for :command', [':registrar' => 'Nominet', ':command' => $name]);
        }
        if ($code >= 2000) {
            $msg = $this->values($reply, '//e:result/e:msg')[0] ?? '';
            $error = implode(': ', array_filter([$msg, $this->values($reply, '//e:result/e:extValue/e:reason')[0] ?? '']));
            // The reason can quote the value sent (V029 "Invalid postcode: ..."): contact data stays out of the log.
            $this->getLog()->err(sprintf('Nominet %s refused: %d %s', $name, $code, $msg));

            throw new Registrar_Exception(':registrar refused the request: :error', [':registrar' => 'Nominet', ':error' => $error !== '' ? $error : (string) $code], $code);
        }

        return $reply;
    }

    private function parse(string $xml, string $command): SimpleXMLElement
    {
        $reply = @simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        if (!$reply instanceof SimpleXMLElement) {
            throw new Registrar_Exception(':registrar returned an unreadable response for :command', [':registrar' => 'Nominet', ':command' => $command]);
        }

        return $reply;
    }

    /** @return list<string> */
    private function values(SimpleXMLElement $xml, string $path): array
    {
        foreach (['e' => self::EPP, 'domain' => self::DOMAIN, 'contact' => self::CONTACT, 'rgp' => self::RGP, 'host' => self::HOST] as $prefix => $namespace) {
            $xml->registerXPathNamespace($prefix, $namespace);
        }

        return array_map(fn (SimpleXMLElement $node) => trim((string) $node), $xml->xpath($path) ?: []);
    }

    private function x(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function available(Registrar_Domain $domain): bool
    {
        $reply = $this->command('<check><domain:check xmlns:domain="' . self::DOMAIN . '"><domain:name>' . $this->x($domain->getName()) . '</domain:name></domain:check></check>');
        $avail = $this->values($reply, '//domain:chkData/domain:cd/domain:name/@avail');
        if ($avail === []) {
            throw new Registrar_Exception(':registrar returned an unreadable response for :command', [':registrar' => 'Nominet', ':command' => 'domain:check']);
        }

        return in_array(strtolower($avail[0]), ['1', 'true'], true);
    }

    private function info(Registrar_Domain $domain): SimpleXMLElement
    {
        return $this->command('<info><domain:info xmlns:domain="' . self::DOMAIN . '"><domain:name hosts="all">' . $this->x($domain->getName()) . '</domain:name></domain:info></info>');
    }

    /** Whether the name is on this tag: Nominet answers domain:info only for its own tag's names. */
    private function onOurTag(Registrar_Domain $domain): bool
    {
        try {
            $info = $this->info($domain);
        } catch (Registrar_Exception $e) {
            // 2201: registered on another tag (or not registered); 2303: no such domain.
            if (in_array($e->getCode(), [2201, 2303], true)) {
                return false;
            }

            throw $e;
        }

        return strcasecmp($this->values($info, '//domain:infData/domain:clID')[0] ?? '', $this->config['username']) === 0;
    }

    /** @return list<string> */
    private function hosts(SimpleXMLElement $info): array
    {
        return array_values(array_filter(array_map(fn (string $host) => strtolower(rtrim($host, '.')), $this->values($info, '//domain:infData/domain:ns/domain:hostObj'))));
    }

    private function status(Registrar_Domain $domain, string $op): void
    {
        // clientTransferProhibited stops a release (tag change) of the name.
        $this->command('<update><domain:update xmlns:domain="' . self::DOMAIN . '"><domain:name>' . $this->x($domain->getName()) . '</domain:name>'
            . '<domain:' . $op . '><domain:status s="' . self::TRANSFER_LOCK . '"/></domain:' . $op . '></domain:update></update>');
    }

    /** The standard platform links only name servers that exist as host objects, out-of-zone ones included: create the missing ones. */
    private function ensureHosts(array $hosts): void
    {
        if (!static::ukStandardised() || $hosts === []) {
            return;
        }
        $reply = $this->command('<check><host:check xmlns:host="' . self::HOST . '">'
            . implode('', array_map(fn (string $host) => '<host:name>' . $this->x($host) . '</host:name>', $hosts)) . '</host:check></check>');
        foreach ($this->values($reply, '//host:chkData/host:cd/host:name[@avail="1" or @avail="true"]') as $host) {
            $this->command('<create><host:create xmlns:host="' . self::HOST . '"><host:name>' . $this->x($host) . '</host:name></host:create></create>');
        }
    }

    /** @return list<string> */
    private function nameservers(Registrar_Domain $domain): array
    {
        $hosts = array_values(array_unique(array_filter(array_map(fn ($ns) => strtolower(rtrim(trim((string) $ns), '.')), [$domain->getNs1(), $domain->getNs2(), $domain->getNs3(), $domain->getNs4()]))));
        if (count($hosts) < 2) {
            throw new Registrar_Exception('At least two name servers are required.');
        }

        return $hosts;
    }

    private function hostObjs(array $hosts): string
    {
        return '<domain:ns>' . implode('', array_map(fn (string $host) => '<domain:hostObj>' . $this->x($host) . '</domain:hostObj>', $hosts)) . '</domain:ns>';
    }

    private function contact(Registrar_Domain $domain): Registrar_Domain_Contact
    {
        $contact = $domain->getContactRegistrar();
        if (!$contact instanceof Registrar_Domain_Contact) {
            throw new Registrar_Exception('The registrant contact is missing.');
        }

        return $contact;
    }

    /** The registrant's postal details; an organisation is the registrant with the person as its contact (Nominet's account rules). */
    private function postalInfo(Registrar_Domain_Contact $c, bool $withOrg): string
    {
        $company = trim((string) $c->getCompany());
        $streets = '';
        foreach (array_filter([trim((string) $c->getAddress1()), trim((string) $c->getAddress2()), trim((string) $c->getAddress3())]) as $street) {
            $streets .= '<contact:street>' . $this->x($street) . '</contact:street>';
        }
        $state = trim((string) $c->getState());
        $zip = trim((string) $c->getZip());

        return '<contact:postalInfo type="loc"><contact:name>' . $this->x(trim($c->getFirstName() . ' ' . $c->getLastName())) . '</contact:name>'
            . ($withOrg && $company !== '' ? '<contact:org>' . $this->x($company) . '</contact:org>' : '')
            . '<contact:addr>' . $streets . '<contact:city>' . $this->x(trim((string) $c->getCity())) . '</contact:city>'
            . ($state !== '' ? '<contact:sp>' . $this->x($state) . '</contact:sp>' : '') . ($zip !== '' ? '<contact:pc>' . $this->x($zip) . '</contact:pc>' : '')
            . '<contact:cc>' . $this->x(strtoupper(trim((string) $c->getCountry()))) . '</contact:cc></contact:addr></contact:postalInfo>';
    }

    private function voiceEmail(Registrar_Domain_Contact $c): string
    {
        $cc = (string) preg_replace('/\D+/', '', (string) $c->getTelCc());
        $tel = (string) preg_replace('/\D+/', '', (string) $c->getTel());
        // EPP numbers are +CC.NUMBER without the national trunk 0 (Italian numbers keep it).
        if (strtoupper((string) $c->getCountry()) !== 'IT') {
            $tel = ltrim($tel, '0');
        }

        return ($cc !== '' && $tel !== '' ? '<contact:voice>+' . $cc . '.' . $tel . '</contact:voice>' : '')
            . '<contact:email>' . $this->x(trim((string) $c->getEmail())) . '</contact:email>';
    }

    /** Nominet's registrant account extension (trading name, type, company number) from the order form; none when empty. */
    private function contactExt(Registrar_Domain $domain, string $op): string
    {
        if (static::ukStandardised()) {
            return '';
        }

        $fields = $domain->getFields();
        $ext = '';
        // Nominet's schema order: trad-name, type, co-no.
        foreach (['trad-name' => 'trading_name', 'type' => 'registrant_type', 'co-no' => 'company_number'] as $element => $key) {
            $value = trim((string) ($fields[$key] ?? ''));
            if ($value !== '') {
                $ext .= '<contact-ext:' . $element . '>' . $this->x($value) . '</contact-ext:' . $element . '>';
            }
        }

        return $ext === '' ? '' : '<contact-ext:' . $op . ' xmlns:contact-ext="' . self::CONTACT_EXT . '">' . $ext . '</contact-ext:' . $op . '>';
    }
}
