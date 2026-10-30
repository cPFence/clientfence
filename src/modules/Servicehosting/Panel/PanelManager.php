<?php

declare(strict_types=1);

/**
 * ClientFence: what a hosting panel's server manager offers the ClientFence pages (Panels::PANELS
 * lists the panels and the features each one has). Implemented by the server managers ClientFence
 * ships, such as Server_Manager_Enhance.
 */

namespace ClientFence\Mod\Servicehosting\Panel;

interface PanelManager
{
    /**
     * The panel's plans (Plans::list() matches them to hosting plans).
     *
     * @return list<array<string, mixed>>
     */
    public function listPackages(): array;

    /**
     * What the account uses (feature "usage").
     */
    public function clientfenceUsage(\Server_Account $account): array;

    /**
     * The server the account's website runs on (feature "server").
     *
     * @return array{hostname: string, ip: ?string}
     */
    public function clientfenceServer(\Server_Account $account): array;

    /**
     * The email accounts of the account's website (feature "email").
     */
    public function clientfenceMailboxes(\Server_Account $account): Mailboxes;

    /**
     * The website's domains and the addresses its DNS should point to (feature "dns").
     *
     * @return array{domains: list<array{domain: string, kind: string}>, web_ips: list<string>, mail_ips: list<string>, mail_host: string}
     */
    public function clientfenceDomains(\Server_Account $account): array;

    public function clientfenceBackups(\Server_Account $account): array;

    /** @return array{domain: string, startedAt: string} */
    public function clientfenceRestore(\Server_Account $account, string $websiteId, int $backupId, bool $includeEmails): array;
}
