<?php

declare(strict_types=1);

/**
 * ClientFence: the email accounts of one website on an Enhance control panel (panel feature
 * "email"). Every call is on the website's own email API, and an address is acted on only when
 * the website's listing has it, so nothing reaches another website's mail. Enhance sizes are in
 * bytes; this class speaks MB.
 *
 * Mail apps get the website's mail server (its emailServerName) with the ports Enhance's mail
 * servers listen on; Enhance's own client-conf answer is a placeholder until the panel owner
 * sets it, so it is not used.
 *
 * Webmail: a one-time sign-in link on mail.<domain> (Enhance's /sso). With the "global webmail
 * domain" option, the panel's global webmail domain (Enhance's branding `roundcubeDomain`) opens
 * with the address filled in instead: Enhance's global webmail only signs in with the mailbox
 * password (its Roundcube plugin takes no sign-in token), so the client types it there.
 */

namespace ClientFence\Mod\Servicehosting\Panel\Enhance;

use ClientFence\Mod\Servicehosting\Panel\Mailboxes;

final class Mail implements Mailboxes
{
    // Website domain kinds an address can be made on (not Enhance's preview domain).
    public const DOMAIN_KINDS = ['primary', 'alias', 'addon', 'subdomain'];

    private const MB = 1_000_000;

    private readonly string $base;

    public function __construct(
        private readonly Api $api,
        private readonly string $orgId,
        private readonly array $website,
        private readonly bool $globalWebmail = false,
    ) {
        $this->base = '/orgs/' . rawurlencode($orgId) . '/websites/' . rawurlencode((string) $website['id']);
    }

    public function list(): array
    {
        $answer = $this->api->get("{$this->base}/emails", ['sortBy' => 'address']);
        $accounts = [];
        foreach (is_array($answer) ? ($answer['items'] ?? []) : [] as $email) {
            if (!is_array($email) || empty($email['address']) || ($email['status'] ?? '') === 'deleted') {
                continue;
            }
            $accounts[] = [
                'address' => strtolower((string) $email['address']),
                'mailbox' => !empty($email['hasMailbox']),
                'status' => (string) ($email['status'] ?? 'active'),
                'used_mb' => self::mb($email['quota']['usage'] ?? 0) ?? 0,
                'quota_mb' => self::quota($email['quota']['total'] ?? null),
                'forwarders' => (int) ($email['forwardersCount'] ?? 0),
                'catch_all' => !empty($email['isCatchAll']),
                'webmail' => !empty($email['hasMailbox']) && (!empty($email['ssoAvailable']) || $this->globalWebmail),
                'sso' => !empty($email['ssoAvailable']),
            ];
        }

        return $accounts;
    }

    public function domains(): array
    {
        $answer = $this->api->get("{$this->base}/domains");
        $domains = [];
        foreach (is_array($answer) ? ($answer['items'] ?? []) : [] as $domain) {
            if (is_array($domain) && !empty($domain['domainId']) && !empty($domain['domain']) && in_array($domain['mappingKind'] ?? '', self::DOMAIN_KINDS, true)) {
                $domains[] = ['id' => (string) $domain['domainId'], 'domain' => strtolower((string) $domain['domain']), 'primary' => $domain['mappingKind'] === 'primary'];
            }
        }
        usort($domains, static fn (array $a, array $b): int => [(int) $b['primary'], $a['domain']] <=> [(int) $a['primary'], $b['domain']]);

        return array_map(static fn (array $d): array => ['id' => $d['id'], 'domain' => $d['domain']], $domains);
    }

    public function allowance(): array
    {
        // The plan's limit covers every website of the subscription, so its own count is used; the
        // website's mailboxes only when the subscription does not say.
        $used = null;
        $limit = null;
        $subscriptionId = Panel::subscriptionOf($this->website);
        if ($subscriptionId !== null) {
            $subscription = $this->api->find('/orgs/' . rawurlencode($this->orgId) . "/subscriptions/{$subscriptionId}");
            foreach (is_array($subscription) ? ($subscription['resources'] ?? []) : [] as $resource) {
                if (is_array($resource) && ($resource['name'] ?? '') === 'mailboxes') {
                    $used = is_numeric($resource['usage'] ?? null) ? (int) $resource['usage'] : null;
                    $limit = is_numeric($resource['total'] ?? null) ? (int) $resource['total'] : null;
                }
            }
        }

        return ['used' => $used ?? count(array_filter($this->list(), static fn (array $a): bool => $a['mailbox'])), 'limit' => $limit];
    }

    public function get(string $address): ?array
    {
        $account = $this->account($address);
        if ($account === null) {
            return null;
        }
        $detail = $this->api->get($this->path($account['address']));
        $autoresponder = $account['mailbox'] ? $this->api->find($this->path($account['address']) . '/autoresponder') : null;

        return array_merge($account, [
            'forwarders' => array_values(array_map('strval', is_array($detail['forwarders'] ?? null) ? $detail['forwarders'] : [])),
            'autoresponder' => is_array($autoresponder) && isset($autoresponder['subject']) ? [
                'enabled' => !empty($autoresponder['enabled']),
                'start_date' => substr((string) ($autoresponder['startDate'] ?? ''), 0, 10),
                'end_date' => !empty($autoresponder['endDate']) ? substr((string) $autoresponder['endDate'], 0, 10) : null,
                'subject' => (string) $autoresponder['subject'],
                'body' => (string) ($autoresponder['body'] ?? ''),
            ] : null,
        ]);
    }

    public function create(string $domainId, string $username, ?string $password, ?int $quotaMb, array $forwarders): string
    {
        $domain = null;
        foreach ($this->domains() as $d) {
            if ($d['id'] === $domainId) {
                $domain = $d['domain'];
            }
        }
        if ($domain === null) {
            throw new \Server_Exception('Choose one of the website\'s domains.');
        }
        $address = strtolower($username) . '@' . $domain;
        if ($this->account($address) !== null) {
            throw new \Server_Exception('The email address :address: already exists.', [':address:' => $address]);
        }
        $body = ['username' => strtolower($username), 'forwarders' => $forwarders, 'aliases' => []];
        if ($password !== null) {
            $body['mailboxName'] = $address;
            $body['mailboxPassword'] = $password;
            if ($quotaMb !== null) {
                $body['quota'] = $quotaMb * self::MB;
            }
        }
        $this->api->post("{$this->base}/domains/" . rawurlencode($domainId) . '/emails', $body);

        return $address;
    }

    public function update(string $address, ?int $quotaMb, array $forwarders): void
    {
        $account = $this->required($address);
        $body = ['forwarders' => $forwarders];
        if ($account['mailbox']) {
            // 0: no limit (Enhance's unlimited quota).
            $body['quota'] = $quotaMb === null ? 0 : $quotaMb * self::MB;
        }
        $this->api->patch($this->path($account['address']), $body);
    }

    public function changePassword(string $address, string $password): void
    {
        $account = $this->required($address);
        if (!$account['mailbox']) {
            throw new \Server_Exception('This address has no mailbox.');
        }
        $this->api->patch($this->path($account['address']), ['mailboxPassword' => $password]);
    }

    public function delete(string $address): void
    {
        $this->api->delete($this->path($this->required($address)['address']));
    }

    public function webmailUrl(string $address): string
    {
        $account = $this->required($address);
        if (!$account['webmail']) {
            throw new \Server_Exception('Webmail is not available for this address.');
        }
        $global = $this->globalWebmail ? self::globalDomain($this->api, $this->orgId) : null;
        if ($global !== null) {
            return 'https://' . $global . '/?_user=' . rawurlencode($account['address']);
        }
        // No global webmail domain in the panel: the one-time link, when the mailbox has one.
        if (!$account['sso']) {
            throw new \Server_Exception('Webmail is not available for this address.');
        }

        return $this->api->location($this->path($account['address']) . '/sso');
    }

    /**
     * The panel's global webmail domain for an organization's clients (its reseller's branding,
     * `roundcubeDomain`), or null when none is set. Without an organization: the panel owner's.
     */
    public static function globalDomain(Api $api, ?string $orgId = null): ?string
    {
        $branding = $api->find('/branding', $orgId !== null ? ['orgId' => $orgId] : []);
        $domain = strtolower(trim((string) (is_array($branding) ? ($branding['roundcubeDomain'] ?? '') : '')));

        return str_contains($domain, '.') && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false ? $domain : null;
    }

    public function appSettings(string $address): array
    {
        $account = $this->required($address);
        $host = (string) ($this->website['emailServerName'] ?? '');
        if ($host === '' || !$account['mailbox']) {
            throw new \Server_Exception('Mail app settings are not available for this address.');
        }

        return [
            'username' => $account['address'],
            'incoming' => [
                ['protocol' => 'IMAP', 'host' => $host, 'port' => 993, 'security' => 'SSL/TLS'],
                ['protocol' => 'POP3', 'host' => $host, 'port' => 995, 'security' => 'SSL/TLS'],
            ],
            'outgoing' => [
                ['protocol' => 'SMTP', 'host' => $host, 'port' => 465, 'security' => 'SSL/TLS'],
                ['protocol' => 'SMTP', 'host' => $host, 'port' => 587, 'security' => 'STARTTLS'],
            ],
        ];
    }

    public function setAutoresponder(string $address, ?array $autoresponder): void
    {
        $account = $this->required($address);
        if (!$account['mailbox']) {
            throw new \Server_Exception('This address has no mailbox.');
        }
        $path = $this->path($account['address']) . '/autoresponder';
        // Enhance has one automatic reply per mailbox and no update call: replace it. (Deleting one
        // that does not exist answers 500, so only an existing one is deleted.)
        if ($this->api->find($path) !== null) {
            $this->api->delete($path);
        }
        if ($autoresponder === null) {
            return;
        }
        // Enhance reads the dates as timestamps: the start and end of the chosen days (UTC).
        $body = [
            'enabled' => $autoresponder['enabled'],
            'startDate' => $autoresponder['start_date'] . 'T00:00:00Z',
            'subject' => $autoresponder['subject'],
            'body' => $autoresponder['body'],
        ];
        if ($autoresponder['end_date'] !== null) {
            $body['endDate'] = $autoresponder['end_date'] . 'T23:59:59Z';
        }
        $this->api->post($path, $body);
    }

    /**
     * The listed account with this address (any case), or null.
     */
    private function account(string $address): ?array
    {
        foreach ($this->list() as $account) {
            if (strcasecmp($account['address'], trim($address)) === 0) {
                return $account;
            }
        }

        return null;
    }

    private function required(string $address): array
    {
        return $this->account($address) ?? throw new \Server_Exception('The email address :address: was not found on this hosting account.', [':address:' => $address]);
    }

    private function path(string $address): string
    {
        return "{$this->base}/emails/" . rawurlencode($address);
    }

    private static function mb(mixed $bytes): ?int
    {
        return is_numeric($bytes) ? (int) round((float) $bytes / self::MB) : null;
    }

    private static function quota(mixed $bytes): ?int
    {
        // Enhance reports no limit as 0 (or nothing).
        $mb = self::mb($bytes);

        return $mb !== null && $mb > 0 ? $mb : null;
    }
}
