<?php

declare(strict_types=1);

/**
 * ClientFence: FOSSBilling server manager for the Enhance control panel.
 *
 * Installed as library/Server/Manager/Enhance.php (FOSSBilling loads server managers only
 * from there); the panel logic is in the Servicehosting module (modules/Servicehosting/Panel/Enhance/).
 * It acts only while ClientFence is on and its Enhance panel is switched on (module settings,
 * Hosting panels). Server settings: the panel's host name, the organization ID (UUID of the
 * organization the API token belongs to) and the API token.
 */

use ClientFence\Mod\Servicehosting\Panel\Enhance\Api;
use ClientFence\Mod\Servicehosting\Panel\Enhance\Mail;
use ClientFence\Mod\Servicehosting\Panel\Enhance\Panel;
use ClientFence\Mod\Servicehosting\Panel\Mailboxes;
use ClientFence\Mod\Servicehosting\Panel\PanelManager;
use ClientFence\Mod\Servicehosting\Panel\Panels;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class Server_Manager_Enhance extends Server_Manager implements PanelManager
{
    public const PANEL = 'enhance';
    private const DEFAULT_PORT = 443;

    /** @var array<string, Api> one API client (and its answers) per panel and token, for the request */
    private static array $apis = [];

    private ?HttpClientInterface $httpClient = null;
    private ?string $createdLogin = null;

    public static function getForm(): array
    {
        return [
            'label' => __trans('Enhance (ClientFence)'),
            'form' => [
                'credentials' => [
                    'fields' => [
                        [
                            'name' => 'username',
                            'type' => 'text',
                            'label' => __trans('Organization ID'),
                            'placeholder' => __trans('UUID of the organization the API token belongs to (in the panel address)'),
                            'required' => true,
                        ],
                        [
                            'name' => 'accesshash',
                            'type' => 'text',
                            'label' => __trans('API token'),
                            'placeholder' => __trans('An access token from the panel; allow this server\'s IP address on it'),
                            'required' => true,
                            'secret' => true,
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function init(): void
    {
        foreach (['host' => 'host name', 'username' => 'organization ID', 'accesshash' => 'API token'] as $key => $label) {
            if (trim((string) ($this->_config[$key] ?? '')) === '') {
                throw new Server_Exception('The Enhance (ClientFence) server is missing its :field. Complete the server settings.', [':field' => $label], 2001);
            }
        }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $this->_config['username']) !== 1) {
            throw new Server_Exception('The Enhance (ClientFence) organization ID must be the organization\'s UUID, not the API token.', null, 2001);
        }
        $this->_config['port'] = ClientFence\Tools::normalizePort($this->_config['port'] ?? null, self::DEFAULT_PORT);
    }

    public function getPort(): int
    {
        return (int) ($this->_config['port'] ?? self::DEFAULT_PORT);
    }

    public static function supportsPackageSync(): bool
    {
        return true;
    }

    /**
     * A test answers the panel's requests itself.
     */
    public function setHttpClient(HttpClientInterface $client): static
    {
        $this->httpClient = $client;

        return $this;
    }

    /**
     * FOSSBilling's shared HTTP client (bound to the server's interface, like the default one);
     * tests can replace it with a fake panel.
     */
    public function getHttpClient(): HttpClientInterface
    {
        if ($this->httpClient !== null) {
            return $this->httpClient;
        }
        $di = $GLOBALS['di'] ?? null;

        return $di instanceof Pimple\Container && isset($di['http_client']) ? $di['http_client'] : parent::getHttpClient();
    }

    /**
     * The panel logic for this server. Only while its Enhance panel is on.
     */
    public function panel(): Panel
    {
        $di = $GLOBALS['di'] ?? null;
        if (!$di instanceof Pimple\Container || !Panels::on($di, self::PANEL)) {
            throw new Server_Exception('Enhance (ClientFence) is switched off. Turn it on under System → Hosting Plans and Servers (Hosting panels).');
        }

        $verifyTls = ClientFence\Tools::normalizeBoolean($this->_config['config']['tls_verify'] ?? true, true);
        $make = fn (): Api => new Api($this->getHttpClient(), $this->baseUrl(), (string) $this->_config['accesshash'], $verifyTls, $this->getLog());
        // A client set by a test gets its own API object; otherwise one is shared for the request.
        $api = $this->httpClient !== null ? $make() : (self::$apis[$this->baseUrl() . '|' . hash('sha256', (string) $this->_config['accesshash'])] ??= $make());

        return new Panel($api, (string) $this->_config['username'], $this->getLog());
    }

    public function testConnection(): bool
    {
        return $this->panel()->testConnection();
    }

    public function getLoginUrl(?Server_Account $account = null): string
    {
        if ($account === null) {
            return $this->baseUrl() . '/';
        }

        try {
            return $this->panel()->forAccount($account->getPanelOrgId(), $account->getPanelSubscriptionId())->signInUrl($this->clientEmail($account), (string) $account->getDomain(), $this->loginEmail($account));
        } catch (Server_Exception $e) {
            $this->getLog()?->error('No control panel sign-in link for ' . $account->getDomain() . ': ' . $e->getMessage());

            return $this->baseUrl() . '/';
        }
    }

    public function getResellerLoginUrl(?Server_Account $account = null): string
    {
        return $this->baseUrl() . '/';
    }

    public function listPackages(): array
    {
        return $this->panel()->plans();
    }

    public function createAccount(Server_Account $account): bool
    {
        $panel = $this->panel()->forAccount($account->getPanelOrgId(), $account->getPanelSubscriptionId());
        $email = $this->clientEmail($account);
        $client = $account->getClient();
        $fullName = trim((string) $client?->getFullName());
        $package = $account->getPackage();

        try {
            $link = $panel->create(
                $email,
                trim((string) $client?->getCompany()) ?: ($fullName ?: $email),
                $fullName ?: $email,
                (string) $account->getPassword(),
                (string) $account->getDomain(),
                $panel->planId($package->getCustomValue('plan_id'), (string) $package->getName()),
                (string) $package->getName(),
                $client?->isEmailTrusted() ?? false,
            );
        } finally {
            $this->createdLogin = $panel->createdLogin();
        }
        $account->setPanelOrgId($link['org'])->setPanelSubscriptionId($link['subscription']);
        // Per hosting plan (custom value send_setup_email): Enhance emails the client a "set your password" link.
        if (ClientFence\Tools::normalizeBoolean($package->getCustomValue('send_setup_email'), false)) {
            $panel->sendPasswordSetup($email);
        }

        return true;
    }

    /**
     * The panel login the last createAccount() made, also when it failed: a retry of the same service may reuse it.
     */
    public function createdLogin(): ?string
    {
        return $this->createdLogin;
    }

    /** @return array{org: string, subscription: int} */
    public function linkAccount(Server_Account $account): array
    {
        ['org' => $org, 'website' => $website] = $this->panel()->find($this->clientEmail($account), (string) $account->getDomain(), $this->loginEmail($account));
        $subscriptionId = Panel::subscriptionOf($website) ?? throw new Server_Exception('The website :domain: has no panel subscription.', [':domain:' => $account->getDomain()], Panel::ACCOUNT_NOT_FOUND);

        return ['org' => (string) $org['id'], 'subscription' => $subscriptionId];
    }

    public function synchronizeAccount(Server_Account $account): Server_Account
    {
        $found = $this->panel()->forAccount($account->getPanelOrgId(), $account->getPanelSubscriptionId())->sync($this->clientEmail($account), (string) $account->getDomain(), $this->loginEmail($account));
        $synced = clone $account;
        $synced->setUsername($found['username']);
        if ($found['ip'] !== null) {
            $synced->setIp($found['ip']);
        }

        return $synced;
    }

    public function suspendAccount(Server_Account $account): bool
    {
        $this->panel()->forAccount($account->getPanelOrgId(), $account->getPanelSubscriptionId())->setSuspended($this->clientEmail($account), (string) $account->getDomain(), true, $this->loginEmail($account));

        return true;
    }

    public function unsuspendAccount(Server_Account $account): bool
    {
        $this->panel()->forAccount($account->getPanelOrgId(), $account->getPanelSubscriptionId())->setSuspended($this->clientEmail($account), (string) $account->getDomain(), false, $this->loginEmail($account));

        return true;
    }

    public function cancelAccount(Server_Account $account): bool
    {
        $this->panel()->forAccount($account->getPanelOrgId(), $account->getPanelSubscriptionId())->cancel($this->clientEmail($account), (string) $account->getDomain(), $this->loginEmail($account));

        return true;
    }

    public function changeAccountPackage(Server_Account $account, Server_Package $package): bool
    {
        $panel = $this->panel()->forAccount($account->getPanelOrgId(), $account->getPanelSubscriptionId());
        $panel->changePlan($this->clientEmail($account), (string) $account->getDomain(), $panel->planId($package->getCustomValue('plan_id'), (string) $package->getName()), $this->loginEmail($account));

        return true;
    }

    public function changeAccountPassword(Server_Account $account, string $newPassword): bool
    {
        $this->panel()->forAccount($account->getPanelOrgId(), $account->getPanelSubscriptionId())->changePassword($this->clientEmail($account), (string) $account->getDomain(), $this->loginEmail($account), $newPassword);

        return true;
    }

    /**
     * Staff only: FOSSBilling lets clients call servicehosting/change_domain and has no setting or
     * event for it, so a change that does not come from the admin API or the command line is
     * refused. The route is read as FOSSBilling's router reads it (`_url`, else the path).
     */
    public function changeAccountDomain(Server_Account $account, string $newDomain): bool
    {
        if (PHP_SAPI !== 'cli') {
            $route = is_string($_GET['_url'] ?? null) ? $_GET['_url'] : (string) ($_SERVER['PATH_INFO'] ?? $_SERVER['REQUEST_URI'] ?? '');
            if (stripos($route, '/api/admin/') !== 0) {
                throw new Server_Exception('Domain changes are made by our staff. Please open a support ticket.');
            }
        }
        $this->panel()->forAccount($account->getPanelOrgId(), $account->getPanelSubscriptionId())->changeDomain($this->clientEmail($account), (string) $account->getDomain(), $newDomain, $this->loginEmail($account));

        return true;
    }

    public function changeAccountUsername(Server_Account $account, string $newUsername): never
    {
        throw new Server_Exception('Enhance logins are email addresses and cannot be renamed here.');
    }

    public function changeAccountIp(Server_Account $account, string $newIp): never
    {
        throw new Server_Exception('The IP address of an Enhance website is set on the panel.');
    }

    /**
     * ClientFence panel feature "usage".
     */
    public function clientfenceUsage(Server_Account $account): array
    {
        return $this->panel()->forAccount($account->getPanelOrgId(), $account->getPanelSubscriptionId())->usage($this->clientEmail($account), (string) $account->getDomain(), $this->loginEmail($account));
    }

    public function clientfenceSubscriptionState(Server_Account $account): string
    {
        return $this->panel()->forAccount($account->getPanelOrgId(), $account->getPanelSubscriptionId())->subscriptionState();
    }

    public function clientfenceBackups(Server_Account $account): array
    {
        return $this->panel()->forAccount($account->getPanelOrgId(), $account->getPanelSubscriptionId())->backups();
    }

    public function clientfenceRestore(Server_Account $account, string $websiteId, int $backupId, bool $includeEmails): array
    {
        return $this->panel()->forAccount($account->getPanelOrgId(), $account->getPanelSubscriptionId())->restore($websiteId, $backupId, $includeEmails);
    }

    /**
     * ClientFence panel feature "server": the server the website really runs on (FOSSBilling's
     * server record is the panel's API host).
     *
     * @return array{hostname: string, ip: ?string}
     */
    public function clientfenceServer(Server_Account $account): array
    {
        return $this->panel()->forAccount($account->getPanelOrgId(), $account->getPanelSubscriptionId())->server($this->clientEmail($account), (string) $account->getDomain(), $this->loginEmail($account));
    }

    /**
     * ClientFence panel feature "email": the email accounts of the account's website.
     */
    public function clientfenceMailboxes(Server_Account $account): Mailboxes
    {
        $panel = $this->panel()->forAccount($account->getPanelOrgId(), $account->getPanelSubscriptionId());
        ['org' => $org, 'website' => $website] = $panel->find($this->clientEmail($account), (string) $account->getDomain(), $this->loginEmail($account));
        // The full record names the website's mail server.
        $full = $panel->api()->get('/orgs/' . rawurlencode((string) $org['id']) . '/websites/' . rawurlencode((string) $website['id']));

        $di = $GLOBALS['di'] ?? null;
        $global = $di instanceof Pimple\Container && Panels::option($di, self::PANEL, 'enhance_global_webmail');

        return new Mail($panel->api(), (string) $org['id'], is_array($full) ? $full + $website : $website, $global);
    }

    /**
     * The panel's global webmail domain (Enhance: Settings, Platform, Webmail), or null when none
     * is set; for the settings page.
     */
    public function clientfenceWebmailDomain(): ?string
    {
        return Mail::globalDomain($this->panel()->api());
    }

    /**
     * ClientFence panel feature "dns": the website's domains and the addresses they should use
     * (Panel/Dns.php looks them up).
     *
     * @return array{domains: list<array{domain: string, kind: string}>, web_ips: list<string>, mail_ips: list<string>, mail_host: string}
     */
    public function clientfenceDomains(Server_Account $account): array
    {
        $panel = $this->panel()->forAccount($account->getPanelOrgId(), $account->getPanelSubscriptionId());
        ['org' => $org, 'website' => $website] = $panel->find($this->clientEmail($account), (string) $account->getDomain(), $this->loginEmail($account));

        return $panel->dnsSite((string) $org['id'], (string) $website['id']);
    }

    private function clientEmail(Server_Account $account): string
    {
        $email = trim((string) $account->getClient()?->getEmail());
        if ($email === '') {
            throw new Server_Exception('The hosting account has no client email.');
        }

        return $email;
    }

    /**
     * The login the account signs in with: its username when that is an email (as staff or WHMCS
     * recorded it), else the client email.
     */
    private function loginEmail(Server_Account $account): string
    {
        $username = trim((string) $account->getUsername());

        return filter_var($username, FILTER_VALIDATE_EMAIL) !== false ? $username : $this->clientEmail($account);
    }

    private function baseUrl(): string
    {
        return 'https://' . $this->_config['host'] . ($this->getPort() === self::DEFAULT_PORT ? '' : ':' . $this->getPort());
    }
}
