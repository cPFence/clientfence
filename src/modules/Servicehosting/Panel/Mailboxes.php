<?php

declare(strict_types=1);

/**
 * ClientFence: the email accounts of one hosting account's website, as a panel offers them
 * (panel feature "email"). The client API (Api/Client.php) checks the order, the account status
 * and the input limits, then calls this; a panel's server manager returns its own implementation
 * from clientfenceMailboxes(). Sizes are in MB (null: unlimited). Errors are \Server_Exception with
 * a message that is safe to show the client.
 */

namespace ClientFence\Mod\Servicehosting\Panel;

interface Mailboxes
{
    /**
     * The website's email accounts (`webmail`: the Webmail button works; `sso`: the panel has a
     * one-time sign-in link for the mailbox).
     *
     * @return list<array{address: string, mailbox: bool, status: string, used_mb: int, quota_mb: ?int, forwarders: int, catch_all: bool, webmail: bool, sso: bool}>
     */
    public function list(): array;

    /**
     * The domains of the website an email account can be made on.
     *
     * @return list<array{id: string, domain: string}>
     */
    public function domains(): array;

    /**
     * Mailboxes the plan allows: how many there are and the limit (null: unlimited).
     *
     * @return array{used: int, limit: ?int}
     */
    public function allowance(): array;

    /**
     * One email account of the website, with its forwarders and autoresponder; null when the
     * website has no such account.
     *
     * @return ?array{address: string, mailbox: bool, status: string, used_mb: int, quota_mb: ?int, forwarders: list<string>, catch_all: bool, webmail: bool, sso: bool, autoresponder: ?array{enabled: bool, start_date: string, end_date: ?string, subject: string, body: string}}
     */
    public function get(string $address): ?array;

    /**
     * Creates an account on one of the website's domains: a mailbox when a password is given,
     * else forwarders only. Returns the new address.
     *
     * @param list<string> $forwarders
     */
    public function create(string $domainId, string $username, ?string $password, ?int $quotaMb, array $forwarders): string;

    /**
     * Changes the quota (a mailbox) and the forwarders of an account.
     *
     * @param list<string> $forwarders
     */
    public function update(string $address, ?int $quotaMb, array $forwarders): void;

    public function changePassword(string $address, string $password): void;

    public function delete(string $address): void;

    /**
     * Where the Webmail button goes for a mailbox: a one-time sign-in link, or the panel's global
     * webmail sign-in page with the address filled in when that option is on.
     */
    public function webmailUrl(string $address): string;

    /**
     * What a mail app needs (incoming and outgoing servers, ports, security).
     *
     * @return array{username: string, incoming: list<array{protocol: string, host: string, port: int, security: string}>, outgoing: list<array{protocol: string, host: string, port: int, security: string}>}
     */
    public function appSettings(string $address): array;

    /**
     * Sets (replacing any earlier one) or removes the automatic reply of a mailbox.
     *
     * @param ?array{enabled: bool, start_date: string, end_date: ?string, subject: string, body: string} $autoresponder null removes it
     */
    public function setAutoresponder(string $address, ?array $autoresponder): void;
}
