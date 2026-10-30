<?php

declare(strict_types=1);

/**
 * ClientFence: hosting accounts on an Enhance control panel.
 *
 * Enhance has a customer organization per client, a subscription per hosting plan and a website
 * per domain. Nothing about them is stored in FOSSBilling: an account is found by the client's
 * email (the owner of the customer organization) and the order's domain (the website), so sites
 * made by hand on the panel can be taken over and a failed create can be retried. A website whose
 * organization is owned by another email is still found by its domain.
 */

namespace ClientFence\Mod\Servicehosting\Panel\Enhance;

final class Panel
{
    public const OWNER = 'Owner';
    public const ACCOUNT_NOT_FOUND = 2002;

    // Website kinds clients see; the control panel, webmail and hostname sites are Enhance's own.
    public const CLIENT_SITE_KINDS = ['normal', 'staging'];

    // Plan resources FOSSBilling has a limit for: Enhance name => hosting plan field.
    public const PLAN_LIMITS = [
        'addonDomains' => 'max_addon',
        'subdomains' => 'max_sub',
        'domainAliases' => 'max_park',
        'ftpUsers' => 'max_ftp',
        'mysqlDbs' => 'max_sql',
        'mailboxes' => 'max_pop',
    ];

    // Subscription resources shown as counts: Enhance name => usage key.
    public const COUNTS = [
        'websites' => 'websites',
        'addonDomains' => 'addon_domains',
        'subdomains' => 'subdomains',
        'domainAliases' => 'domain_aliases',
        'mailboxes' => 'mailboxes',
        'mysqlDbs' => 'databases',
        'ftpUsers' => 'ftp_users',
    ];

    public const STATS_DAYS = 30;

    // Enhance's password rule for logins.
    public const PASSWORD_RULE = 'The password must be at least 10 characters long and contain lowercase and uppercase letters, numbers and special characters.';

    private ?string $orgId = null;
    private ?int $subscriptionId = null;

    public function __construct(
        private readonly Api $api,
        private readonly string $masterOrg,
        private readonly ?\ClientFence\Log $log = null,
    ) {
    }

    public static function passwordAccepted(string $password): bool
    {
        return mb_strlen($password) >= 10
            && preg_match('/\p{Ll}/u', $password) === 1
            && preg_match('/\p{Lu}/u', $password) === 1
            && preg_match('/\d/u', $password) === 1
            && preg_match('/[^\p{L}\d]/u', $password) === 1;
    }

    public function api(): Api
    {
        return $this->api;
    }

    public function forAccount(?string $orgId, ?int $subscriptionId): self
    {
        $panel = clone $this;
        $panel->orgId = $orgId;
        $panel->subscriptionId = $subscriptionId;

        return $panel;
    }

    public function testConnection(): bool
    {
        $org = $this->api->get("/orgs/{$this->masterOrg}");
        if (!is_array($org) || ($org['id'] ?? null) !== $this->masterOrg || ($org['status'] ?? '') === 'deleted') {
            throw new \Server_Exception('The :panel: server did not recognise the organization ID. Check the server settings.', [':panel:' => Api::PANEL]);
        }

        return true;
    }

    /**
     * The panel's plans with the limits FOSSBilling hosting plans have (sizes in MB, null = unlimited).
     *
     * @return list<array<string, mixed>>
     */
    public function plans(): array
    {
        $plans = [];
        foreach ($this->api->each("/orgs/{$this->masterOrg}/plans") as $plan) {
            if (!isset($plan['id'], $plan['name'])) {
                continue;
            }
            $totals = self::resources($plan['resources'] ?? []);
            $row = [
                'id' => (string) $plan['id'],
                'name' => (string) $plan['name'],
                'quota' => self::mb($totals['diskspace']['total'] ?? null),
                'bandwidth' => self::mb($totals['transfer']['total'] ?? null),
            ];
            foreach (self::PLAN_LIMITS as $name => $field) {
                $row[$field] = self::number($totals[$name]['total'] ?? null);
            }
            $row['config'] = ['plan_id' => (string) $plan['id']];
            $plans[] = $row;
        }

        return $plans;
    }

    /**
     * The plan ID for a hosting plan: its `plan_id` custom value, else the panel plan of the same name.
     */
    public function planId(?string $customPlanId, string $planName): int
    {
        if ($customPlanId !== null && ctype_digit(trim($customPlanId))) {
            return (int) $customPlanId;
        }
        foreach ($this->api->each("/orgs/{$this->masterOrg}/plans") as $plan) {
            if (isset($plan['id']) && strcasecmp((string) ($plan['name'] ?? ''), $planName) === 0) {
                return (int) $plan['id'];
            }
        }

        throw new \Server_Exception('No :panel: plan matches the hosting plan ":plan:". Set the plan_id custom value on the hosting plan.', [':panel:' => Api::PANEL, ':plan:' => $planName]);
    }

    /**
     * The customer organization and website of an account: in an organization of the client email, else
     * (the billing email changed) found by domain in an organization of the client email or the account's
     * login.
     *
     * @return array{org: array, website: array}
     */
    public function find(string $clientEmail, string $domain, string $loginEmail = ''): array
    {
        if ($this->subscriptionId !== null) {
            $this->resolveSubscription();
            $first = null;
            foreach ($this->api->each("/orgs/{$this->orgId}/websites", ['subscriptionId' => $this->subscriptionId]) as $website) {
                if (($website['status'] ?? '') === 'deleted') {
                    continue;
                }
                $found = ['org' => ['id' => $this->orgId], 'website' => $website];
                if (strcasecmp((string) ($website['domain']['domain'] ?? ''), $domain) === 0) {
                    return $found;
                }
                $first ??= $found;
            }

            return $first ?? throw new \Server_Exception('The panel subscription :id: of :domain: was not found.', [':id:' => $this->subscriptionId, ':domain:' => $domain]);
        }

        foreach ($this->customerOrgs($clientEmail) as $org) {
            $website = $this->websiteIn((string) $org['id'], $domain);
            if ($website !== null) {
                return ['org' => $org, 'website' => $website];
            }
        }

        $website = $this->pickWebsite($this->api->each("/orgs/{$this->masterOrg}/websites", ['search' => $domain, 'recursion' => 'directCustomers']), $domain);
        if ($website !== null && !empty($website['orgId'])) {
            $org = $this->api->find('/orgs/' . rawurlencode((string) $website['orgId']));
            // ClientFence: never another customer's website with the same domain (it was suspended, re-planned
            // and deleted for this account).
            if (is_array($org) && !empty($org['id']) && $this->ownedBy($org, [$clientEmail, $loginEmail])) {
                $this->log?->debug(sprintf('Website %s was found by its domain: no customer organization on the panel is owned by %s', $domain, $clientEmail));

                return ['org' => $org, 'website' => $website];
            }
        }

        throw new \Server_Exception('No website for :domain: belonging to :email: was found on the :panel: server.', [':domain:' => $domain, ':email:' => $clientEmail, ':panel:' => Api::PANEL], self::ACCOUNT_NOT_FOUND);
    }

    public function subscriptionState(): string
    {
        $subscription = $this->resolveSubscription(true);

        return $subscription === null ? 'gone' : (empty($subscription['suspendedBy']) ? 'active' : 'suspended');
    }

    /** @return list<array{id: string, domain: string, backups: list<array<string, mixed>>}> */
    public function backups(): array
    {
        $this->resolveSubscription();
        $websites = [];
        foreach ($this->api->each("/orgs/{$this->orgId}/websites", ['subscriptionId' => $this->subscriptionId]) as $website) {
            if (($website['status'] ?? '') === 'deleted') {
                continue;
            }
            $answer = $this->api->get("/orgs/{$this->orgId}/websites/" . rawurlencode((string) $website['id']) . '/backups');
            $backups = $answer['items'];
            usort($backups, static fn (array $a, array $b): int => strtotime($b['startedAt']) <=> strtotime($a['startedAt']));
            $websites[] = [
                'id' => (string) $website['id'],
                'domain' => (string) $website['domain']['domain'],
                'backups' => array_map(static fn (array $backup): array => [
                    'id' => (int) $backup['id'],
                    'startedAt' => $backup['startedAt'],
                    'finishedAt' => $backup['finishedAt'] ?? null,
                    'size' => $backup['size'] ?? null,
                    'files' => ($backup['homeDirStatus'] ?? '') === 'successful',
                    'databases' => ($backup['mysqlDbsStatus'] ?? '') === 'successful',
                    'mailboxes' => ($backup['emailsStatus'] ?? '') === 'successful',
                ], array_slice($backups, 0, 10)),
            ];
        }

        return $websites;
    }

    /** @return array{domain: string, startedAt: string} */
    public function restore(string $websiteId, int $backupId, bool $includeEmails): array
    {
        $this->resolveSubscription();
        $path = "/orgs/{$this->orgId}/websites/" . rawurlencode($websiteId);
        $website = $this->api->find($path);
        if ($website === null || ($website['status'] ?? '') === 'deleted' || self::subscriptionOf($website) !== $this->subscriptionId) {
            throw new \Server_Exception('The website was not found on this hosting account.');
        }
        $backup = $this->api->find($path . '/backups/' . $backupId);
        if ($backup === null || ($backup['homeDirStatus'] ?? '') !== 'successful') {
            throw new \Server_Exception('Choose a backup with a successful files backup for this website.');
        }
        $this->api->put($path . '/backups/' . $backupId, ['restoreFiles' => true, 'restoreAllEmails' => $includeEmails]);

        return ['domain' => (string) $website['domain']['domain'], 'startedAt' => (string) $backup['startedAt']];
    }

    /** @return ($allowMissing is true ? array|null : array) */
    private function resolveSubscription(bool $allowMissing = false): ?array
    {
        $subscription = $this->api->find("/orgs/{$this->orgId}/subscriptions/{$this->subscriptionId}");
        if ($subscription === null || $subscription['status'] === 'deleted') {
            if ($allowMissing) {
                return null;
            }

            throw new \Server_Exception('The panel subscription :id: no longer exists.', [':id:' => $this->subscriptionId]);
        }
        if (strcasecmp((string) $subscription['subscriberId'], (string) $this->orgId) !== 0 || strcasecmp((string) $subscription['vendorId'], $this->masterOrg) !== 0) {
            throw new \Server_Exception('The panel subscription :id: belongs to another organization or vendor.', [':id:' => $this->subscriptionId]);
        }

        return $subscription;
    }

    /**
     * Creates what an account needs and reuses what exists: the customer organization, its owner
     * login, the subscription and the website. What this call created is removed again when a later
     * step fails, so a retry starts clean.
     */
    public function create(string $clientEmail, string $orgName, string $loginName, string $password, string $domain, int $planId, string $subscriptionName, bool $emailTrusted = false): array
    {
        if ($this->isStaff('', $clientEmail)) {
            throw new \Server_Exception('The client email :email: is a staff login on the :panel: server. Give the client a different email address.', [':email:' => $clientEmail, ':panel:' => Api::PANEL]);
        }

        $orgs = $this->customerOrgs($clientEmail);
        // An existing organization or login of this email is used only when the client proved the email: anyone
        // could change their billing email to another customer's old address and get that customer's panel.
        if (!$emailTrusted && ($orgs !== [] || $this->loginId($clientEmail) !== null)) {
            throw new \Server_Exception('The :panel: server already has a login for :email:, and the client has not confirmed this email address. Check that the client owns it, mark the email as confirmed on the client, then try again.', [':email:' => $clientEmail, ':panel:' => Api::PANEL]);
        }
        if (count($orgs) > 1) {
            $this->log?->warning(sprintf('%d customer organizations on the panel are owned by %s; the first is used', count($orgs), $clientEmail));
        }
        $orgId = isset($orgs[0]['id']) ? (string) $orgs[0]['id'] : null;
        $newOrg = $orgId === null;
        if ($newOrg) {
            $orgId = Api::id($this->api->post("/orgs/{$this->masterOrg}/customers", ['name' => $orgName]), 'create the customer organization');
        }

        $subscriptionId = null;

        try {
            $this->ensureOwner($orgId, $clientEmail, $loginName, $password);
            $website = $this->websiteIn($orgId, $domain);
            if ($website !== null) {
                $existingId = self::subscriptionOf($website);
                $subscription = $existingId === null ? null : $this->api->get("/orgs/{$orgId}/subscriptions/{$existingId}");
                $reusable = $existingId !== null && (int) ($subscription['planId'] ?? 0) === $planId;
                if ($reusable) {
                    foreach ($this->api->each("/orgs/{$orgId}/websites") as $other) {
                        if (($other['status'] ?? '') !== 'deleted' && $other['id'] !== $website['id'] && self::subscriptionOf($other) === $existingId) {
                            $reusable = false;

                            break;
                        }
                    }
                }
                if (!$reusable) {
                    throw new \Server_Exception('The domain :domain: already exists on the :panel: panel under another subscription and must be moved or removed first.', [':domain:' => $domain, ':panel:' => Api::PANEL]);
                }
                $this->log?->info(sprintf('Website %s already exists on the panel and is used for the account', $domain));

                return ['org' => $orgId, 'subscription' => (int) $existingId];
            }
            $subscriptionId = (int) Api::id($this->api->post("/orgs/{$this->masterOrg}/customers/{$orgId}/subscriptions", ['planId' => $planId, 'friendlyName' => $subscriptionName]), 'create the subscription');
            $this->api->post("/orgs/{$orgId}/websites", ['domain' => $domain, 'subscriptionId' => $subscriptionId]);

            return ['org' => $orgId, 'subscription' => $subscriptionId];
        } catch (\Throwable $e) {
            $this->undoCreate($orgId, $subscriptionId, $newOrg);

            throw $e;
        }
    }

    /**
     * Asks Enhance to send the client its "set your password" email.
     */
    public function sendPasswordSetup(string $clientEmail): void
    {
        try {
            $this->api->put('/login/password-recovery', ['email' => $clientEmail]);
        } catch (\Server_Exception $e) {
            $this->log?->warning('The panel password setup email was not sent: ' . $e->getMessage());
        }
    }

    /**
     * Suspends or reactivates the subscription and its websites (a website can outlive its
     * subscription, so it is always flagged too).
     */
    public function setSuspended(string $clientEmail, string $domain, bool $suspended, string $loginEmail = ''): void
    {
        if ($this->subscriptionId !== null) {
            $subscription = $this->resolveSubscription();
            if (!empty($subscription['suspendedBy']) !== $suspended) {
                $this->api->patch("/orgs/{$this->orgId}/subscriptions/{$this->subscriptionId}", ['isSuspended' => $suspended]);
            }
            foreach ($this->api->each("/orgs/{$this->orgId}/websites", ['subscriptionId' => $this->subscriptionId]) as $website) {
                if (($website['status'] ?? '') !== 'deleted') {
                    $this->api->patch("/orgs/{$this->orgId}/websites/{$website['id']}", ['isSuspended' => $suspended]);
                }
            }

            return;
        }

        ['org' => $org, 'website' => $website] = $this->find($clientEmail, $domain, $loginEmail);
        $subscriptionId = self::subscriptionOf($website);
        if ($subscriptionId !== null) {
            $this->api->patch("/orgs/{$org['id']}/subscriptions/{$subscriptionId}", ['isSuspended' => $suspended], [404]);
        }
        $this->api->patch("/orgs/{$org['id']}/websites/{$website['id']}", ['isSuspended' => $suspended]);
    }

    /**
     * Removes the subscription (and so every website on it); the customer organization stays for
     * the client's other and later services.
     */
    public function cancel(string $clientEmail, string $domain, string $loginEmail = ''): void
    {
        if ($this->orgId === null || $this->subscriptionId === null) {
            throw new \Server_Exception('This hosting account has no stored panel subscription. Run Sync on the service first.');
        }
        if ($this->resolveSubscription(true) !== null) {
            $this->api->delete("/orgs/{$this->orgId}/subscriptions/{$this->subscriptionId}");
        }
    }

    public function changePlan(string $clientEmail, string $domain, int $planId, string $loginEmail = ''): void
    {
        if ($this->subscriptionId !== null) {
            $subscription = $this->resolveSubscription();
            if ((int) $subscription['planId'] !== $planId) {
                $this->api->patch("/orgs/{$this->orgId}/subscriptions/{$this->subscriptionId}", ['planId' => $planId]);
            }

            return;
        }

        ['org' => $org, 'website' => $website] = $this->find($clientEmail, $domain, $loginEmail);
        $subscriptionId = self::subscriptionOf($website) ?? throw new \Server_Exception('The website :domain: has no subscription on the :panel: server.', [':domain:' => $domain, ':panel:' => Api::PANEL]);
        $this->api->patch("/orgs/{$org['id']}/subscriptions/{$subscriptionId}", ['planId' => $planId]);
    }

    /**
     * Maps the new domain onto the website and makes it the primary one; the old one stays an alias.
     */
    public function changeDomain(string $clientEmail, string $domain, string $newDomain, string $loginEmail = ''): void
    {
        ['org' => $org, 'website' => $website] = $this->find($clientEmail, $domain, $loginEmail);
        $domainId = Api::id($this->api->post("/orgs/{$org['id']}/websites/{$website['id']}/domains", ['domain' => $newDomain, 'kind' => 'alias']), 'add the domain');
        $this->api->put("/orgs/{$org['id']}/websites/{$website['id']}/domains/primary", ['domainId' => $domainId]);
    }

    public function changePassword(string $clientEmail, string $domain, string $loginEmail, string $password): void
    {
        if (!self::passwordAccepted($password)) {
            throw new \Server_Exception(self::PASSWORD_RULE);
        }
        ['org' => $org] = $this->find($clientEmail, $domain, $loginEmail);
        $member = $this->accountMember((string) $org['id'], $loginEmail, $clientEmail);
        if ($member === null || empty($member['loginId'])) {
            throw new \Server_Exception('No :panel: login for :email: was found.', [':panel:' => Api::PANEL, ':email:' => $loginEmail]);
        }
        $this->api->put('/v2/logins/' . rawurlencode((string) $member['loginId']) . '/password', ['newPassword' => $password]);
    }

    /**
     * A one-time sign-in link to the control panel as the account's login.
     */
    public function signInUrl(string $clientEmail, string $domain, string $loginEmail): string
    {
        ['org' => $org] = $this->find($clientEmail, $domain, $loginEmail);
        $member = $this->accountMember((string) $org['id'], $loginEmail, $clientEmail)
            ?? throw new \Server_Exception('No :panel: login for :email: was found.', [':panel:' => Api::PANEL, ':email:' => $loginEmail]);
        $url = $this->api->get("/orgs/{$org['id']}/members/{$member['id']}/sso");
        if (!is_string($url) || !str_starts_with($url, 'http')) {
            throw new \Server_Exception('The :panel: server did not give a sign-in link. Please try again later.', [':panel:' => Api::PANEL]);
        }

        return $url;
    }

    /**
     * The login and IP an account really has: the login it signs in with (a member of its own
     * organization, else the client email) and the website's primary IP.
     *
     * @return array{username: string, ip: ?string}
     */
    public function sync(string $clientEmail, string $domain, string $loginEmail): array
    {
        ['org' => $org, 'website' => $website] = $this->find($clientEmail, $domain, $loginEmail);
        $fresh = $this->api->get("/orgs/{$org['id']}/websites/{$website['id']}");
        $username = $clientEmail;
        if (strcasecmp($loginEmail, $clientEmail) !== 0 && $this->member((string) $org['id'], $loginEmail) !== null) {
            $username = $loginEmail;
        }

        return ['username' => $username, 'ip' => self::primaryIp(is_array($fresh) ? $fresh : $website)];
    }

    /**
     * The server the account's website runs on: its name and primary IP.
     *
     * @return array{hostname: string, ip: ?string}
     */
    public function server(string $clientEmail, string $domain, string $loginEmail = ''): array
    {
        ['org' => $org, 'website' => $website] = $this->find($clientEmail, $domain, $loginEmail);
        $website = $this->api->get("/orgs/{$org['id']}/websites/{$website['id']}");
        $hostname = (string) ($website['appServerName'] ?? '');
        $ip = null;
        if (!empty($website['appServerId'])) {
            $server = $this->api->find('/servers/' . rawurlencode((string) $website['appServerId']));
            if (is_array($server)) {
                $ip = self::primaryIp(['serverIps' => $server['ips'] ?? []]);
                $hostname = $hostname !== '' ? $hostname : (string) ($server['friendlyName'] ?? $server['hostname'] ?? '');
            }
        }

        return ['hostname' => $hostname, 'ip' => $ip];
    }

    /**
     * What the account's subscription uses, with every website on it (sizes in MB, null = unlimited).
     * A website's statistics or backups that cannot be read are left out for that site only.
     *
     * @return array<string, mixed>
     */
    public function usage(string $clientEmail, string $domain, string $loginEmail = ''): array
    {
        ['org' => $org, 'website' => $website] = $this->find($clientEmail, $domain, $loginEmail);
        $orgId = (string) $org['id'];
        $subscriptionId = self::subscriptionOf($website) ?? throw new \Server_Exception('The website :domain: has no subscription on the :panel: server.', [':domain:' => $domain, ':panel:' => Api::PANEL]);
        $subscription = $this->api->get("/orgs/{$orgId}/subscriptions/{$subscriptionId}");
        $resources = self::resources(is_array($subscription) ? ($subscription['resources'] ?? []) : []);

        $counts = [];
        foreach (self::COUNTS as $name => $key) {
            if (isset($resources[$name])) {
                $counts[$key] = ['used' => (int) ($resources[$name]['usage'] ?? 0), 'limit' => self::number($resources[$name]['total'] ?? null)];
            }
        }

        return [
            'plan_name' => (string) ($subscription['planName'] ?? ''),
            'suspended' => ($subscription['status'] ?? '') === 'suspended' || self::suspended($website),
            'disk_used_mb' => self::mb($resources['diskspace']['usage'] ?? 0) ?? 0,
            'disk_quota_mb' => self::mb($resources['diskspace']['total'] ?? null),
            'bandwidth_used_mb' => self::mb($resources['transfer']['usage'] ?? 0) ?? 0,
            'bandwidth_limit_mb' => self::mb($resources['transfer']['total'] ?? null),
            'counts' => $counts,
            'websites' => $this->websitesOf($orgId, $subscriptionId, $domain),
        ];
    }

    /**
     * A website's domains (not Enhance's preview domain) and the addresses they should resolve to:
     * the website's server for the web, its mail server for MX hosts.
     *
     * @return array{domains: list<array{domain: string, kind: string}>, web_ips: list<string>, mail_ips: list<string>, mail_host: string}
     */
    public function dnsSite(string $orgId, string $websiteId): array
    {
        $base = '/orgs/' . rawurlencode($orgId) . '/websites/' . rawurlencode($websiteId);
        $website = $this->api->get($base);
        $website = is_array($website) ? $website : [];
        $domains = [];
        $answer = $this->api->get("{$base}/domains");
        foreach (is_array($answer) ? ($answer['items'] ?? []) : [] as $domain) {
            if (is_array($domain) && !empty($domain['domain']) && in_array($domain['mappingKind'] ?? '', Mail::DOMAIN_KINDS, true)) {
                $domains[] = ['domain' => strtolower((string) $domain['domain']), 'kind' => (string) $domain['mappingKind']];
            }
        }
        usort($domains, static fn (array $a, array $b): int => [(int) ($b['kind'] === 'primary'), $a['domain']] <=> [(int) ($a['kind'] === 'primary'), $b['domain']]);
        $ips = static function (array $list, mixed $ipv6): array {
            $out = [];
            foreach ($list as $entry) {
                if (is_array($entry) && !empty($entry['ip'])) {
                    $out[] = (string) $entry['ip'];
                }
            }
            if (is_string($ipv6) && $ipv6 !== '') {
                $out[] = $ipv6;
            }

            return array_values(array_unique($out));
        };

        return [
            'domains' => $domains,
            'web_ips' => $ips($website['serverIps'] ?? [], $website['appServerIpv6'] ?? null),
            'mail_ips' => $ips($website['emailServerIps'] ?? [], $website['emailServerIpv6'] ?? null),
            'mail_host' => (string) ($website['emailServerName'] ?? ''),
        ];
    }

    /**
     * Whether a login (by ID, or email when there is no ID) is staff: a member of the master organization.
     */
    public function isStaff(string $loginId, string $email): bool
    {
        foreach ($this->api->each("/orgs/{$this->masterOrg}/members") as $staff) {
            if (($loginId !== '' && (string) ($staff['loginId'] ?? '') === $loginId)
                || ($email !== '' && strcasecmp((string) ($staff['email'] ?? ''), $email) === 0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array>
     */
    public function customerOrgs(string $email): array
    {
        $orgs = [];
        foreach ($this->api->each("/orgs/{$this->masterOrg}/customers") as $org) {
            if (($org['status'] ?? '') !== 'deleted' && strcasecmp((string) ($org['ownerEmail'] ?? ''), $email) === 0) {
                $orgs[] = $org;
            }
        }

        return $orgs;
    }

    /**
     * Whether one of the emails owns the organization or is a member of it.
     *
     * @param list<string> $emails
     */
    private function ownedBy(array $org, array $emails): bool
    {
        foreach (array_filter($emails) as $email) {
            if (strcasecmp((string) ($org['ownerEmail'] ?? ''), $email) === 0 || $this->member((string) $org['id'], $email) !== null) {
                return true;
            }
        }

        return false;
    }

    public function websiteIn(string $orgId, string $domain): ?array
    {
        return $this->pickWebsite($this->api->each("/orgs/{$orgId}/websites", ['search' => $domain]), $domain);
    }

    /**
     * The organization member an account signs in as: its recorded login (the account username when
     * it is an email, as staff or WHMCS recorded it), else the client email. A login that is also
     * staff is never handed to a client.
     */
    public function accountMember(string $orgId, string $loginEmail, string $clientEmail): ?array
    {
        $member = $this->member($orgId, $loginEmail);
        if ($member === null && strcasecmp($loginEmail, $clientEmail) !== 0) {
            $member = $this->member($orgId, $clientEmail);
        }
        if ($member !== null && $this->isStaff((string) ($member['loginId'] ?? ''), (string) ($member['email'] ?? ''))) {
            $this->log?->error(sprintf('The login %s was refused for a client action: it is staff on the panel', (string) ($member['email'] ?? '')));

            return null;
        }

        return $member;
    }

    public function member(string $orgId, string $email): ?array
    {
        $other = null;
        foreach ($this->api->each("/orgs/{$orgId}/members") as $member) {
            if (strcasecmp((string) ($member['email'] ?? ''), $email) !== 0) {
                continue;
            }
            if (in_array(self::OWNER, $member['roles'] ?? [], true)) {
                return $member;
            }
            $other ??= $member;
        }

        return $other;
    }

    public static function subscriptionOf(array $website): ?int
    {
        return isset($website['subscriptionId']) && is_numeric($website['subscriptionId']) ? (int) $website['subscriptionId'] : null;
    }

    public static function suspended(array $website): bool
    {
        // Enhance shows a suspended website as "disabled", with the organization that suspended it.
        return ($website['status'] ?? '') === 'disabled' || !empty($website['suspendedBy']);
    }

    public static function primaryIp(array $website): ?string
    {
        $first = null;
        foreach ($website['serverIps'] ?? [] as $entry) {
            $ip = is_array($entry) ? trim((string) ($entry['ip'] ?? '')) : '';
            if ($ip === '') {
                continue;
            }
            if (!empty($entry['isPrimary'])) {
                return $ip;
            }
            $first ??= $ip;
        }

        return $first;
    }

    private function ensureOwner(string $orgId, string $email, string $name, string $password): void
    {
        if ($this->member($orgId, $email) !== null) {
            return;
        }
        // The login may already exist (another organization, or an earlier try): 409, then look it up.
        $login = $this->api->post('/logins', ['email' => $email, 'name' => $name, 'password' => $password], ['orgId' => $orgId], [409]);
        $loginId = is_array($login) && !empty($login['id']) ? (string) $login['id'] : $this->loginId($email);
        if ($loginId === null) {
            throw new \Server_Exception('The :panel: login for :email: could not be created.', [':panel:' => Api::PANEL, ':email:' => $email]);
        }
        $this->api->post("/orgs/{$orgId}/members", ['loginId' => $loginId, 'roles' => [self::OWNER]]);
    }

    private function loginId(string $email): ?string
    {
        foreach ($this->api->each("/v2/orgs/{$this->masterOrg}/customers/logins") as $login) {
            if (!empty($login['id']) && strcasecmp((string) ($login['email'] ?? ''), $email) === 0) {
                return (string) $login['id'];
            }
        }

        return null;
    }

    private function undoCreate(string $orgId, ?int $subscriptionId, bool $newOrg): void
    {
        try {
            if ($subscriptionId !== null) {
                $this->api->delete("/orgs/{$orgId}/subscriptions/{$subscriptionId}");
            }
            if ($newOrg) {
                $this->api->delete("/orgs/{$orgId}");
            }
        } catch (\Throwable $e) {
            $this->log?->error(sprintf('After a failed create, organization %s (subscription %s) could not be removed: %s', $orgId, $subscriptionId ?? '-', $e->getMessage()));
        }
    }

    private function pickWebsite(iterable $websites, string $domain): ?array
    {
        $inactive = null;
        foreach ($websites as $website) {
            if (($website['status'] ?? '') === 'deleted' || strcasecmp((string) ($website['domain']['domain'] ?? ''), $domain) !== 0) {
                continue;
            }
            if (($website['status'] ?? '') === 'active') {
                return $website;
            }
            $inactive ??= $website;
        }

        return $inactive;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function websitesOf(string $orgId, int $subscriptionId, string $primaryDomain): array
    {
        $since = gmdate('Y-m-d\TH:i:s\Z', time() - self::STATS_DAYS * 86400);
        $sites = [];
        foreach ($this->api->each("/orgs/{$orgId}/websites", ['subscriptionId' => $subscriptionId]) as $site) {
            if (empty($site['id']) || ($site['status'] ?? '') === 'deleted' || !in_array($site['kind'] ?? 'normal', self::CLIENT_SITE_KINDS, true)) {
                continue;
            }
            $domain = (string) ($site['domain']['domain'] ?? '');
            $aliases = [];
            foreach ($site['aliases'] ?? [] as $alias) {
                if (is_array($alias) && !empty($alias['domain']) && ($alias['kind'] ?? 'alias') === 'alias') {
                    $aliases[] = (string) $alias['domain'];
                }
            }
            $sites[] = [
                'domain' => $domain,
                'primary' => strcasecmp($domain, $primaryDomain) === 0,
                'aliases' => $aliases,
                'kind' => (string) ($site['kind'] ?? 'normal'),
                'suspended' => self::suspended($site),
                'disk_used_bytes' => (int) ($site['size'] ?? 0),
                'php_version' => (string) preg_replace('/^php(\d)(\d+)$/', '$1.$2', (string) ($site['phpVersion'] ?? '')),
                'server' => (string) ($site['appServerName'] ?? ''),
                'last_backup_at' => $this->lastBackup($orgId, (string) $site['id'], $domain),
                'stats' => $this->stats($orgId, (string) $site['id'], $domain, $since),
            ];
        }
        usort($sites, static fn (array $a, array $b): int => (int) $b['primary'] <=> (int) $a['primary']);

        return $sites;
    }

    private function stats(string $orgId, string $websiteId, string $domain, string $since): ?array
    {
        try {
            $metrics = $this->api->get("/orgs/{$orgId}/websites/{$websiteId}/metrics", ['start' => $since, 'granularity' => 'day']);
        } catch (\Server_Exception $e) {
            $this->log?->warning(sprintf('Statistics of %s could not be read: %s', $domain, $e->getMessage()));

            return null;
        }
        $sum = ['days' => self::STATS_DAYS, 'visitors' => 0, 'requests' => 0, 'bytes_sent' => 0, 'bytes_received' => 0];
        foreach (is_array($metrics) ? ($metrics['items'] ?? []) : [] as $day) {
            if (is_array($day)) {
                $sum['visitors'] += (int) ($day['uniqueHits'] ?? 0);
                $sum['requests'] += (int) ($day['totalHits'] ?? 0);
                $sum['bytes_sent'] += (int) ($day['bytesSent'] ?? 0);
                $sum['bytes_received'] += (int) ($day['bytesReceived'] ?? 0);
            }
        }

        return $sum;
    }

    private function lastBackup(string $orgId, string $websiteId, string $domain): ?string
    {
        try {
            $backups = $this->api->get("/orgs/{$orgId}/websites/{$websiteId}/backups");
        } catch (\Server_Exception $e) {
            $this->log?->warning(sprintf('Backups of %s could not be read: %s', $domain, $e->getMessage()));

            return null;
        }
        $latest = null;
        foreach (is_array($backups) ? ($backups['items'] ?? []) : [] as $backup) {
            // Only runs whose files were stored count.
            if (is_array($backup) && ($backup['homeDirStatus'] ?? '') === 'successful' && !empty($backup['startedAt'])) {
                $latest = $latest === null || strcmp((string) $backup['startedAt'], $latest) > 0 ? (string) $backup['startedAt'] : $latest;
            }
        }

        return $latest;
    }

    /**
     * @return array<string, array>
     */
    private static function resources(mixed $list): array
    {
        $byName = [];
        foreach (is_array($list) ? $list : [] as $resource) {
            if (is_array($resource) && isset($resource['name'])) {
                $byName[(string) $resource['name']] = $resource;
            }
        }

        return $byName;
    }

    private static function mb(mixed $bytes): ?int
    {
        return is_numeric($bytes) ? (int) round((float) $bytes / 1_000_000) : null;
    }

    private static function number(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
