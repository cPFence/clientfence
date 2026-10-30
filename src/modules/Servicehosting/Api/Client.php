<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Servicehosting\Api;

use ClientFence\Mod\Client\Users;
use ClientFence\Mod\Servicehosting\Panel\Dns;
use ClientFence\Mod\Servicehosting\Panel\Mailboxes;
use ClientFence\Mod\Servicehosting\Panel\PanelManager;
use ClientFence\Mod\Servicehosting\Panel\Panels;
use ClientFence\InformationException;

/**
 * Hosting service management.
 */
class Client extends \ClientFence\Api\AbstractApi
{
    /**
     * Change hosting account username.
     *
     * @return bool
     */
    public function change_username($data)
    {
        // ClientFence core patch: staff change hosting usernames (also refused by .htaccess and the Enhance adapter).
        throw new \ClientFence\InformationException('Hosting usernames are changed by our staff. Please open a support ticket.', null, 403);

        [$order, $s] = $this->_getService($data);

        return $this->getService()->changeAccountUsername($order, $s, $data);
    }

    /**
     * Change hosting account domain.
     *
     * @return bool
     */
    public function change_domain($data)
    {
        // ClientFence core patch: staff change hosting domains (also refused by .htaccess and the Enhance adapter).
        throw new \ClientFence\InformationException('Domain changes are handled by our staff. Please open a support ticket.', null, 403);

        [$order, $s] = $this->_getService($data);

        return $this->getService()->changeAccountDomain($order, $s, $data);
    }

    /**
     * Change hosting account password.
     *
     * @return bool
     */
    public function change_password($data)
    {
        [$order, $s] = $this->_getService($data);

        return $this->getService()->changeAccountPassword($order, $s, $data);
    }

    /**
     * Get hosting plans pairs. Usually for select box.
     *
     * Scoped to plans referenced by at least one enabled hosting product, so
     * clients can only enumerate plans that are actually orderable. Use the
     * admin API for the full list.
     *
     * @return array
     */
    public function hp_get_pairs($data)
    {
        return $this->getService()->getOrderableHpPairs();
    }

    /**
     * Returns the login URL for a given order ID.
     * If the associated server manager supports SSO, an SSO link will be given.
     * Will automatically return either a reseller URL or a standard URL depending on the order config.
     *
     * @param array $data An array containing the API request data. Should have a key named `order_id` containing the order's ID.
     */
    public function get_login_url(array $data): string
    {
        [$order, $s] = $this->_getService($data);

        return $this->getService()->generateLoginUrl($s);
    }

    public function _getService($data): array
    {
        if (!isset($data['order_id'])) {
            throw new \ClientFence\Exception('Order ID is required');
        }
        $identity = $this->getIdentity();
        $order = $this->getDi()['db']->findOne('ClientOrder', 'id = ? and client_id = ?', [$data['order_id'], $identity->id]);
        if (!$order instanceof \Model_ClientOrder) {
            throw new \ClientFence\InformationException('Order not found');
        }

        $orderService = $this->getDi()['mod_service']('order');
        $orderService->assertOrderUsable($order);
        $s = $orderService->getOrderService($order);
        if (!$s instanceof \Model_ServiceHosting || $order->status !== \Model_ClientOrder::STATUS_ACTIVE) {
            throw new \ClientFence\InformationException('Order is not activated');
        }

        return [$order, $s];
    }

    /**
     * ClientFence: the client's hosting accounts (active and suspended), for the Hosting accounts page. No panel
     * calls.
     *
     * @return list<array{order_id: int, title: string, domain: string, plan: string, status: string, expires_at: ?string}>
     */
    public function accounts(): array
    {
        return Panels::anyOn($this->getDi()) ? Panels::clientAccounts($this->getDi(), (int) $this->getIdentity()->id) : [];
    }

    /**
     * What one of the client's hosting accounts uses, read live from its panel. Never fails: when the
     * panel cannot answer it returns `['error' => true]` (the reason goes to the log).
     */
    public function usage($data): array
    {
        return $this->panelFeature($data, 'usage', static fn (PanelManager $m, \Server_Account $a): array => $m->clientfenceUsage($a));
    }

    // ClientFence core patch: backup restores require the account owner and the product's current permission.
    public function backups($data): array
    {
        return $this->panelFeature($data, 'backups', function (PanelManager $manager, \Server_Account $account, array $order): array {
            $config = json_decode((string) $this->getDi()['em']->getConnection()->fetchOne('SELECT config FROM product WHERE id = ?', [$order['product_id']]), true);

            return [
                'websites' => $manager->clientfenceBackups($account),
                'can_restore' => $order['status'] === 'active' && Users::context($this->getDi()) === null && (string) ($config['client_backup_restore'] ?? '0') === '1',
            ];
        });
    }

    public function restore($data): bool
    {
        $di = $this->getDi();
        if (Users::context($di) !== null) {
            throw new InformationException('Only the account owner can restore backups.');
        }
        ['order' => $order, 'manager' => $manager, 'account' => $account] = Panels::forClientOrder($di, (int) ($data['order_id'] ?? 0), (int) $this->getIdentity()->id, 'backups');
        $config = json_decode((string) $di['em']->getConnection()->fetchOne('SELECT config FROM product WHERE id = ?', [$order['product_id']]), true);
        if ((string) ($config['client_backup_restore'] ?? '0') !== '1') {
            throw new InformationException('Backup restores are not enabled for this product.');
        }
        if ($order['status'] !== 'active') {
            throw new InformationException('This hosting account is suspended, so backups cannot be restored.');
        }
        if (empty($data['confirm'])) {
            throw new InformationException('Confirm that you want to restore this backup.');
        }

        try {
            $restored = $manager->clientfenceRestore($account, (string) ($data['website_id'] ?? ''), (int) ($data['backup_id'] ?? 0), !empty($data['include_emails']));
        } catch (\Server_Exception $e) {
            throw new InformationException($e->getMessage());
        }
        $message = sprintf('Started restoring the backup from %s for %s on hosting order #%d', $restored['startedAt'], $restored['domain'], $order['id']);
        Users::activity($di, [(int) $this->getIdentity()->id], $message);
        $di['logger']->info('Client #%s: %s', (int) $this->getIdentity()->id, $message);

        return true;
    }

    /**
     * The server one of the client's hosting accounts really runs on (`hostname`, `ip`), cached for an
     * hour (it changes only when staff move the website). `['error' => true]` when unknown.
     */
    public function server($data): array
    {
        $di = $this->getDi();

        return $this->panelFeature($data, 'server', static function (PanelManager $m, \Server_Account $a) use ($di, $data): array {
            $key = 'servicehosting_server_' . (int) ($data['order_id'] ?? 0) . '_' . md5((string) $a->getDomain());

            return $di['cache']->get($key, static function (\Symfony\Contracts\Cache\ItemInterface $item) use ($m, $a): array {
                $item->expiresAfter(3600);

                return $m->clientfenceServer($a);
            });
        });
    }

    /**
     * Whether one of the client's hosting accounts' domains point to its server, for its Domains
     * tab: each domain's website and mail status (Panel/Dns.php) and the addresses to use. Kept
     * for 5 minutes; `refresh=1` looks again (20 times an hour). Never fails: `['error' => true]`
     * when the panel cannot answer.
     */
    public function domains($data): array
    {
        $di = $this->getDi();
        $orderId = (int) (is_array($data) ? ($data['order_id'] ?? 0) : 0);
        $refreshed = false;
        if (is_array($data) && !empty($data['refresh'])) {
            try {
                $this->limit('dns_refresh', 20);
                $refreshed = true;
            } catch (InformationException) {
                // Too many: the kept answer is shown.
            }
        }

        $result = $this->panelFeature($data, 'dns', static function (PanelManager $m, \Server_Account $a) use ($di, $orderId, $refreshed): array {
            $key = 'servicehosting_dns_' . $orderId . '_' . md5((string) $a->getDomain());
            if ($refreshed) {
                $di['cache']->deleteItem($key);
            }

            return $di['cache']->get($key, static function (\Symfony\Contracts\Cache\ItemInterface $item) use ($m, $a): array {
                $item->expiresAfter(300);
                $site = $m->clientfenceDomains($a);

                return [
                    'checked_at' => time(),
                    'domains' => Dns::check($site),
                    'expected' => ['web_ips' => $site['web_ips'], 'mail_ips' => $site['mail_ips'], 'mail_host' => $site['mail_host']],
                ];
            });
        });

        return $result + ['refresh_refused' => is_array($data) && !empty($data['refresh']) && !$refreshed];
    }

    /**
     * The email accounts of one of the client's hosting accounts, for its Email tab: the accounts,
     * the domains a new one can use, the plan's mailbox allowance and whether changes are allowed
     * (not while the account is suspended).
     */
    public function emails($data): array
    {
        return $this->mail($data, false, static fn (Mailboxes $mail): array => [
            'accounts' => $mail->list(),
            'domains' => $mail->domains(),
            'allowance' => $mail->allowance(),
        ]);
    }

    /**
     * One email account: forwarders and automatic reply too.
     */
    public function email_get($data): array
    {
        return $this->mail($data, false, static fn (Mailboxes $mail): array => $mail->get(self::address($data))
            ?? throw new InformationException('The email address was not found on this hosting account.'));
    }

    /**
     * Creates an email account: `domain_id` (one of the website's domains), `username`, then a
     * mailbox (`password`, optional `quota_mb`) or forwarders only (`forwarders`, one per line).
     */
    public function email_create($data): array
    {
        $username = strtolower(trim((string) ($data['username'] ?? '')));
        if (preg_match('/^[a-z0-9](?:[a-z0-9._-]{0,62}[a-z0-9])?$/', $username) !== 1 || str_contains($username, '..')) {
            throw new InformationException('The name before @ may use letters, numbers, dots, dashes and underscores, and must start and end with a letter or number.');
        }
        $password = (string) ($data['password'] ?? '');
        $forwarders = self::forwarders($data);
        if ($password === '' && $forwarders === []) {
            throw new InformationException('Give a password for a mailbox, or at least one address to forward to.');
        }
        if ($password !== '') {
            self::checkPassword($password);
        }
        $quota = self::quota($data);

        return $this->mail($data, true, function (Mailboxes $mail) use ($data, $username, $password, $forwarders, $quota): array {
            $this->limit('email_create', 20);
            if ($password !== '') {
                $allowance = $mail->allowance();
                if ($allowance['limit'] !== null && $allowance['used'] >= $allowance['limit']) {
                    throw new InformationException('Your plan allows :limit mailboxes, and all are in use.', [':limit' => $allowance['limit']]);
                }
            }
            $address = $mail->create((string) ($data['domain_id'] ?? ''), $username, $password !== '' ? $password : null, $quota, $forwarders);
            $this->logChange('created the email account %s', $address);

            return ['address' => $address];
        });
    }

    /**
     * Changes an email account's quota (`quota_mb`, empty: no limit) and forwarders.
     */
    public function email_update($data): bool
    {
        $forwarders = self::forwarders($data);
        $quota = self::quota($data);

        return $this->mail($data, true, function (Mailboxes $mail) use ($data, $forwarders, $quota): bool {
            $this->limit('email_update', 60);
            $mail->update(self::address($data), $quota, $forwarders);
            $this->logChange('changed the email account %s', self::address($data));

            return true;
        });
    }

    public function email_password($data): bool
    {
        $password = (string) ($data['password'] ?? '');
        self::checkPassword($password);

        return $this->mail($data, true, function (Mailboxes $mail) use ($data, $password): bool {
            $this->limit('email_password', 10);
            $mail->changePassword(self::address($data), $password);
            $this->logChange('changed the password of the email account %s', self::address($data));

            return true;
        });
    }

    public function email_delete($data): bool
    {
        return $this->mail($data, true, function (Mailboxes $mail) use ($data): bool {
            $this->limit('email_delete', 30);
            $mail->delete(self::address($data));
            $this->logChange('deleted the email account %s', self::address($data));

            return true;
        });
    }

    /**
     * A one-time webmail sign-in link for a mailbox.
     */
    public function email_webmail($data): string
    {
        return $this->mail($data, true, function (Mailboxes $mail) use ($data): string {
            $this->limit('email_webmail', 30);

            return $mail->webmailUrl(self::address($data));
        });
    }

    /**
     * The mail app settings of a mailbox.
     */
    public function email_settings($data): array
    {
        return $this->mail($data, false, static fn (Mailboxes $mail): array => $mail->appSettings(self::address($data)));
    }

    /**
     * Sets a mailbox's automatic reply (`enabled`, `start_date`, optional `end_date`, `subject`,
     * `body`), or removes it with `remove=1`.
     */
    public function email_autoresponder($data): bool
    {
        $reply = null;
        if (empty($data['remove'])) {
            $start = self::date($data['start_date'] ?? '') ?? throw new InformationException('Choose the date the automatic reply starts.');
            $end = trim((string) ($data['end_date'] ?? '')) === '' ? null : (self::date($data['end_date']) ?? throw new InformationException('The end date is not a valid date.'));
            if ($end !== null && $end < $start) {
                throw new InformationException('The automatic reply must end on or after the day it starts.');
            }
            $subject = trim((string) ($data['subject'] ?? ''));
            $body = trim((string) ($data['body'] ?? ''));
            if ($subject === '' || mb_strlen($subject) > 200 || $body === '' || mb_strlen($body) > 5000) {
                throw new InformationException('Give a subject (up to 200 characters) and a message (up to 5000 characters).');
            }
            $reply = ['enabled' => !empty($data['enabled']), 'start_date' => $start, 'end_date' => $end, 'subject' => $subject, 'body' => $body];
        }

        return $this->mail($data, true, function (Mailboxes $mail) use ($data, $reply): bool {
            $this->limit('email_autoresponder', 30);
            $mail->setAutoresponder(self::address($data), $reply);
            $this->logChange($reply === null ? 'removed the automatic reply of %s' : 'set the automatic reply of %s', self::address($data));

            return true;
        });
    }

    /**
     * Runs an email call on one of the client's hosting accounts (feature "email"). Changes need an
     * active account. Panel errors reach the client as their (safe) message.
     */
    private function mail(mixed $data, bool $change, callable $call): mixed
    {
        $di = $this->getDi();
        $orderId = (int) (is_array($data) ? ($data['order_id'] ?? 0) : 0);
        ['order' => $order, 'manager' => $manager, 'account' => $account] = Panels::forClientOrder($di, $orderId, (int) $this->getIdentity()->id, 'email');
        if ($change && $order['status'] !== 'active') {
            throw new InformationException('This hosting account is suspended, so its email accounts cannot be changed.');
        }

        try {
            return $call($manager->clientfenceMailboxes($account));
        } catch (\Server_Exception $e) {
            throw new InformationException($e->getMessage());
        }
    }

    /**
     * At most $max calls of an action an hour for each client. The window is stored with the count
     * and set as the expiry on every save (the pool's own lifetime is a day).
     */
    private function limit(string $action, int $max): void
    {
        $cache = $this->getDi()['rate_limit_cache'];
        $item = $cache->getItem('clientfence_' . $action . '_' . (int) $this->getIdentity()->id);
        $window = $item->isHit() ? $item->get() : null;
        if (!is_array($window) || (int) ($window['until'] ?? 0) <= time()) {
            $window = ['count' => 0, 'until' => time() + 3600];
        }
        if ((int) $window['count'] >= $max) {
            throw new InformationException('Too many changes in a short time. Please wait a while and try again.', null, 429);
        }
        ++$window['count'];
        $cache->save($item->set($window)->expiresAt(new \DateTimeImmutable('@' . $window['until'])));
    }

    private function logChange(string $what, string $address): void
    {
        $this->getDi()['logger']->info('Client #%s ' . $what, (int) $this->getIdentity()->id, $address);
    }

    private static function address(mixed $data): string
    {
        $address = strtolower(trim((string) (is_array($data) ? ($data['address'] ?? '') : '')));
        if ($address === '' || strlen($address) > 254 || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new InformationException('Choose an email address.');
        }

        return $address;
    }

    /**
     * @return list<string>
     */
    private static function forwarders(mixed $data): array
    {
        $raw = is_array($data) ? ($data['forwarders'] ?? '') : '';
        $list = is_array($raw) ? $raw : preg_split('/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
        $forwarders = [];
        foreach ($list as $address) {
            $address = strtolower(trim((string) $address));
            if ($address === '') {
                continue;
            }
            if (strlen($address) > 254 || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw new InformationException(':address is not a valid email address to forward to.', [':address' => mb_substr($address, 0, 80)]);
            }
            $forwarders[$address] = $address;
        }
        if (count($forwarders) > 20) {
            throw new InformationException('An email account can forward to at most 20 addresses.');
        }

        return array_values($forwarders);
    }

    private static function quota(mixed $data): ?int
    {
        $raw = trim((string) (is_array($data) ? ($data['quota_mb'] ?? '') : ''));
        if ($raw === '' || $raw === '0') {
            return null;
        }
        if (!ctype_digit($raw) || (int) $raw > 1_000_000) {
            throw new InformationException('The mailbox size is a whole number of MB (empty for no limit).');
        }

        return (int) $raw;
    }

    private static function checkPassword(string $password): void
    {
        if (strlen($password) > 128 || !\ClientFence\Mod\Servicehosting\Panel\Enhance\Panel::passwordAccepted($password)) {
            throw new InformationException('At least 10 characters, with lowercase and uppercase letters, numbers and special characters.');
        }
    }

    private static function date(mixed $value): ?string
    {
        $value = trim((string) $value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function panelFeature(mixed $data, string $feature, callable $read): array
    {
        $orderId = (int) (is_array($data) ? ($data['order_id'] ?? 0) : 0);

        try {
            ['order' => $order, 'manager' => $manager, 'account' => $account] = Panels::forClientOrder($this->getDi(), $orderId, (int) $this->getIdentity()->id, $feature);

            return $read($manager, $account, $order);
        } catch (\ClientFence\InformationException) {
            // Not the client's hosting account, or not on a panel that is on: nothing to log.
            return ['error' => true];
        } catch (\Throwable $e) {
            $this->getDi()['logger']->warning('ClientFence: hosting %s for order #%s is unavailable: %s', $feature, $orderId, $e->getMessage());

            return ['error' => true];
        }
    }
}
