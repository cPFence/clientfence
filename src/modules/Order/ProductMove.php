<?php

declare(strict_types=1);

namespace ClientFence\Mod\Order;

use ClientFence\InformationException;
use ClientFence\Mod\Product\Entity\Product;
use ClientFence\Mod\Servicehosting\Panel\Enhance\Panel;
use ClientFence\Mod\Servicehosting\Service as HostingService;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Connection;

/**
 * ClientFence: Orders > Move services, for services that sit under custom products (an import whose products had no
 * server). Staff move a custom product's services to another product, as WHMCS's "import existing account": to a
 * hosting product, a service is linked by its recorded domain through panel lookup or a server account list.
 * To another custom product it is the staff Change Package move (Upgrades::staffMove(), price kept). The order keeps its id,
 * client, price, billing period, due date, invoices, notes, options and status. Staff preview a page of services
 * first; the move checks each one again and moves it in its own transaction, so one failure stops no other.
 * Choosing the same hosting product fills in its missing logins so renewals and panel actions work.
 */
final class ProductMove
{
    public const STATUSES = BulkPrices::STATUSES;
    public const DEFAULT_STATUSES = BulkPrices::DEFAULT_STATUSES;
    // Keep panel lookups within the request budget.
    public const LIMIT = 25;
    // A service's order with its client and, for a hosting service, its account.
    private const ORDERS = "SELECT o.id, o.title, o.status, o.client_id, o.product_id, o.service_type, o.service_id, c.first_name, c.last_name, c.company,
        sh.sld, sh.tld, sh.username, sh.service_hosting_server_id, sh.service_hosting_hp_id, sh.panel_org_id, sh.panel_subscription_id
        FROM client_order o JOIN client c ON c.id = o.client_id LEFT JOIN service_hosting sh ON o.service_type = 'hosting' AND sh.id = o.service_id";
    private const NO_LOGIN = "(sh.username IS NULL OR sh.username = '')";

    /**
     * What the form offers: the custom products with services to move and the hosting products with services without
     * a login (and how many), and the products they can go to (hosting products with a server and plan, with the servers
     * their accounts can be on, and custom products).
     *
     * @return array{sources: list<array{id: int, title: string, type: string, orders: int}>, targets: list<array{id: int, title: string, type: string, server: ?string, servers: list<array{id: int, name: string}>}>, statuses: list<string>, default_statuses: list<string>}
     */
    public static function options(\Pimple\Container $di): array
    {
        $db = self::db($di);
        $sources = $db->fetchAllAssociative(
            "SELECT p.id, p.title, p.type, COUNT(o.id) AS orders FROM product p JOIN client_order o ON o.product_id = p.id AND o.service_type = p.type AND o.status IN (" . self::quoteList($db, self::STATUSES) . ")
             LEFT JOIN service_hosting sh ON o.service_type = 'hosting' AND sh.id = o.service_id
             WHERE p.is_addon = 0 AND (p.type = 'custom' OR (p.type = 'hosting' AND " . self::NO_LOGIN . ' AND sh.id IS NOT NULL))
             GROUP BY p.id, p.title, p.type ORDER BY p.type, p.title, p.id'
        );
        $servers = $db->fetchAllKeyValue('SELECT id, name FROM service_hosting_server');
        $groups = $db->fetchAllKeyValue('SELECT id, servers FROM service_hosting_server_group');
        $targets = [];
        foreach ($db->fetchFirstColumn("SELECT id FROM product WHERE type IN ('custom', 'hosting') AND is_addon = 0 ORDER BY type DESC, title, id") as $id) {
            $product = self::product($db, (int) $id);
            if ($product !== null) {
                // The servers the accounts can be on: the product's server, then those of its group (as products() accepts).
                $ids = $product['type'] === 'hosting'
                    ? array_values(array_unique([(int) $product['config']['server_id'], ...array_map('intval', json_decode((string) ($groups[(int) ($product['config']['server_group_id'] ?? 0)] ?? '[]'), true) ?? [])]))
                    : [];
                $targets[] = [
                    'id' => $product['id'], 'title' => $product['title'], 'type' => $product['type'],
                    'server' => $product['type'] === 'hosting' ? (string) ($servers[$product['config']['server_id']] ?? '') : null,
                    'servers' => array_map(static fn (int $sid): array => ['id' => $sid, 'name' => (string) ($servers[$sid] ?? '#' . $sid)], $ids),
                ];
            }
        }

        return [
            'sources' => array_map(static fn (array $p): array => ['id' => (int) $p['id'], 'title' => (string) $p['title'], 'type' => (string) $p['type'], 'orders' => (int) $p['orders']], $sources),
            'targets' => $targets,
            'statuses' => self::STATUSES,
            'default_statuses' => self::DEFAULT_STATUSES,
        ];
    }

    /**
     * A page of services of the product after order `after`, with the proposed account or refusal.
     *
     * @param array<string, mixed> $data from_product_id, to_product_id, statuses, after, server_id, accounts
     *
     * @return array{total: int, rows: list<array<string, mixed>>, movable: int, next: ?int}
     */
    public static function preview(\Pimple\Container $di, array $data): array
    {
        $db = self::db($di);
        [$from, $to, $destination] = self::products($di, $data);
        $limit = self::limit($destination);
        $statuses = array_values(array_intersect(self::STATUSES, array_map('strval', (array) ($data['statuses'] ?? []))));
        if ($statuses === []) {
            throw new InformationException('Choose at least one order status.');
        }
        // Linking in place lists only the services without a login.
        $where = ' WHERE o.product_id = ? AND o.status IN (' . self::quoteList($db, $statuses) . ')' . ($from['id'] === $to['id'] ? ' AND ' . self::NO_LOGIN : '');
        $total = (int) $db->fetchOne('SELECT COUNT(*) FROM (' . self::ORDERS . $where . ') t', [$from['id']]);
        $orders = $db->fetchAllAssociative(
            self::ORDERS . $where . ' AND o.id > ? ORDER BY o.id LIMIT ' . ($limit + 1),
            [$from['id'], max(0, (int) ($data['after'] ?? 0))],
        );
        $next = count($orders) > $limit ? (int) $orders[$limit - 1]['id'] : null;
        $domains = self::domains($db, $from['id']);
        $rows = [];
        foreach (array_slice($orders, 0, $limit) as $order) {
            $found = self::check($di, $order, $from, $to, $domains, $destination);
            $rows[] = [
                'id' => (int) $order['id'],
                'title' => (string) $order['title'],
                'status' => (string) $order['status'],
                'client_id' => (int) $order['client_id'],
                'client' => trim($order['first_name'] . ' ' . $order['last_name']) ?: (string) $order['company'],
                'domain' => $found['domain'] ?? '',
                'link' => $found['link'] ?? null,
                'account' => isset($found['username']) ? ['server' => $destination['server'], 'username' => $found['username']] : null,
                'reason' => $found['reason'],
            ];
        }

        return ['total' => $total, 'rows' => $rows, 'movable' => count(array_filter($rows, static fn (array $r): bool => $r['reason'] === null)), 'next' => $next];
    }

    /**
     * Moves the services, each checked again as the preview does.
     *
     * @param array<string, mixed> $data from_product_id, to_product_id, order_ids, server_id, accounts
     *
     * @return array{moved: int, results: list<array{id: int, moved: bool, note: ?string}>}
     */
    public static function move(\Pimple\Container $di, array $data): array
    {
        $db = self::db($di);
        [$from, $to, $destination] = self::products($di, $data);
        $limit = self::limit($destination);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($data['order_ids'] ?? [])), static fn (int $id): bool => $id > 0)));
        if ($ids === [] || count($ids) > $limit) {
            throw new InformationException('Choose between 1 and :max services to move.', [':max' => $limit]);
        }
        $domains = self::domains($db, $from['id']);
        $results = [];
        foreach ($ids as $id) {
            try {
                $order = $db->fetchAssociative(self::ORDERS . ' WHERE o.id = ?', [$id]);
                if ($order === false || (int) $order['product_id'] !== $from['id'] || !in_array($order['status'], self::STATUSES, true)) {
                    throw new InformationException('This service is no longer on :product.', [':product' => $from['title']]);
                }
                $found = self::check($di, $order, $from, $to, $domains, $destination);
                if ($found['reason'] !== null) {
                    $results[] = ['id' => $id, 'moved' => false, 'note' => $found['reason']];

                    continue;
                }
                if ($to['type'] === 'custom') {
                    Upgrades::staffMove($di, $id, $to['id'], false, false);
                } elseif ($from['id'] === $to['id']) {
                    self::attach($di, $id, $order, $found, $destination['server_ip']);
                } else {
                    self::link($di, $id, $order, $from, $to, $found, $destination['server']);
                }
                $results[] = ['id' => $id, 'moved' => true, 'note' => null];
            } catch (\Throwable $e) {
                $results[] = ['id' => $id, 'moved' => false, 'note' => $e->getMessage()];
            }
        }

        return ['moved' => count(array_filter($results, static fn (array $r): bool => $r['moved'])), 'results' => $results];
    }

    /**
     * Why the service cannot move (a text), or null with the hosting destination and any account link.
     *
     * @param array<string, mixed>                                                $order  client_order row
     * @param array{of: array<int, string>, count: array<string, int>}            $domains
     *
     * @return array{reason: ?string, domain?: string, link?: array{org: string, subscription: int}, adapter?: object, account?: \Server_Account, server_id?: int, plan_id?: int, username?: string}
     */
    private static function check(\Pimple\Container $di, array $order, array $from, array $to, array $domains, ?array $destination): array
    {
        $inPlace = $from['id'] === $to['id'];
        if ($inPlace) {
            if ($order['service_type'] !== 'hosting' || empty($order['service_id'])) {
                return ['reason' => __trans('Not a hosting service.')];
            }
            if (trim((string) $order['username']) !== '') {
                return ['reason' => __trans('Already linked to its account.')];
            }
            $domain = HostingService::asciiDomain($order['sld'] . $order['tld']);
        } else {
            if ($order['service_type'] === 'hosting') {
                return ['reason' => __trans('Already a hosting service.')];
            }
            if ($order['service_type'] !== 'custom' || empty($order['service_id'])) {
                return ['reason' => __trans('Not a custom service, or it has no service yet.')];
            }
            if (self::renewalPromoChanges($di, (int) $order['id'], $to['id'])) {
                return ['reason' => __trans('An automatic promotion of the new product would change the renewal price of this service.')];
            }
            if ($to['type'] === 'custom') {
                return ['reason' => Upgrades::staffMoveRefusal($di, $di['db']->getExistingModelById('ClientOrder', (int) $order['id'], 'Order not found'), $to['id'])];
            }
            $domain = $domains['of'][(int) $order['id']] ?? '';
        }
        if (!str_contains($domain, '.')) {
            return ['reason' => __trans('No domain was recorded for this service.')];
        }
        $serverId = $destination['server_id'];
        $lookup = $destination['lookup'];
        $savedServerId = $inPlace ? (int) $order['service_hosting_server_id'] : 0;
        // An imported service may still be on a server outside the product's group.
        if ($savedServerId > 0 && $savedServerId !== $serverId) {
            try {
                $server = $di['db']->load('ServiceHostingServer', $savedServerId);
                if ($server !== null && method_exists($di['mod_service']('servicehosting')->getServerManager($server), 'linkAccount')) {
                    $serverId = $savedServerId;
                    $lookup = true;
                }
            } catch (\Throwable $e) {
                return ['reason' => $e->getMessage(), 'domain' => $domain];
            }
        }
        $username = null;
        if (!$lookup) {
            $accounts = $destination['accounts'];
            if ($accounts === null) {
                return ['reason' => __trans('Paste the account list of this server.'), 'domain' => $domain];
            }
            if (!array_key_exists($domain, $accounts)) {
                return ['reason' => __trans('Not in the account list of this server.'), 'domain' => $domain];
            }
            $username = $accounts[$domain];
            if ($username === null) {
                return ['reason' => __trans('This domain is twice in the account list.'), 'domain' => $domain];
            }
            if ($username === false) {
                return ['reason' => __trans('This username has more than one domain in the account list.'), 'domain' => $domain];
            }
        }
        if (!$inPlace && ($domains['count'][$domain] ?? 0) > 1) {
            return ['reason' => __trans('Several services of this product have this domain.'), 'domain' => $domain];
        }
        $db = self::db($di);
        // The same domain test as a new hosting order (Servicehosting assertSubdomainAvailable()).
        $taken = $db->fetchOne(
            "SELECT co.id FROM service_hosting sh JOIN client_order co ON co.service_type = 'hosting' AND co.service_id = sh.id
             WHERE LOWER(" . $db->getDatabasePlatform()->getConcatExpression('sh.sld', 'sh.tld') . ') IN (' . self::quoteList($db, self::spellings([$domain])) . ") AND co.status <> 'canceled' AND co.id <> ?",
            [(int) $order['id']],
        );
        if ($taken !== false) {
            return ['reason' => __trans('Order #:id is already a hosting service for this domain.', [':id' => $taken]), 'domain' => $domain];
        }

        $plan = $destination['plan'];
        $planId = (int) ($order['service_hosting_hp_id'] ?: $plan->id);
        try {
            if ($planId !== (int) $plan->id) {
                $plan = $di['db']->getExistingModelById('ServiceHostingHp', $planId, 'Hosting plan not found');
                if ((!$lookup || $serverId !== $savedServerId) && !HostingService::planFitsServer($plan->config, $serverId)) {
                    return ['reason' => __trans('This hosting plan belongs to another server.'), 'domain' => $domain];
                }
            }
            if ($username !== null) {
                HostingService::assertUsernameAvailable($db, $serverId, $username, $inPlace ? (int) $order['service_id'] : 0);

                return ['reason' => null, 'domain' => $domain, 'server_id' => $serverId, 'plan_id' => $planId, 'username' => $username];
            }
            $model = $di['db']->dispense('ServiceHosting');
            $model->client_id = (int) $order['client_id'];
            $model->service_hosting_server_id = $serverId;
            $model->service_hosting_hp_id = $planId;
            [$model->sld, $model->tld] = self::split($domain);
            // The account as an activation sees it, never linked or stored here.
            [$adapter, $account] = $di['mod_service']('servicehosting')->_getAM($model, $plan, false);
            // As a create: an unconfirmed email could be another customer's, whose panel account must not be linked.
            if ($account->getClient()?->isEmailTrusted() !== true) {
                return ['reason' => __trans("The client's email address is not confirmed."), 'domain' => $domain];
            }
            // A stored panel link belongs to its original server.
            $link = $order['panel_subscription_id'] !== null && (int) ($order['service_hosting_server_id'] ?: $serverId) === $serverId
                ? ['org' => (string) $order['panel_org_id'], 'subscription' => (int) $order['panel_subscription_id']]
                : $adapter->linkAccount($account);
        } catch (\Server_Exception $e) {
            return ['reason' => $e->getCode() === Panel::ACCOUNT_NOT_FOUND ? __trans('No account of this client with this domain was found on the server.') : $e->getMessage(), 'domain' => $domain];
        } catch (\Throwable $e) {
            return ['reason' => $e->getMessage(), 'domain' => $domain];
        }
        $linked = $db->fetchOne('SELECT id FROM service_hosting WHERE panel_org_id = ? AND panel_subscription_id = ? AND id <> ?', [$link['org'], $link['subscription'], $inPlace ? (int) $order['service_id'] : 0]);
        if ($linked !== false) {
            return ['reason' => __trans('This account is already linked to hosting service #:id.', [':id' => $linked]), 'domain' => $domain];
        }
        $account->setPanelOrgId($link['org'])->setPanelSubscriptionId($link['subscription']);
        // Another open service for a website of this account, not linked yet (of any client: a transfer keeps the account), would share it.
        try {
            $sites = array_values(array_diff(array_map(HostingService::asciiDomain(...), method_exists($adapter, 'clientfenceWebsites') ? $adapter->clientfenceWebsites($account) : []), [$domain]));
        } catch (\Throwable $e) {
            return ['reason' => $e->getMessage(), 'domain' => $domain];
        }
        $sibling = $sites === [] ? false : $db->fetchAssociative(
            "SELECT co.id, LOWER(" . $db->getDatabasePlatform()->getConcatExpression('sh.sld', 'sh.tld') . ") AS domain FROM service_hosting sh JOIN client_order co ON co.service_type = 'hosting' AND co.service_id = sh.id
             WHERE sh.service_hosting_server_id = ? AND sh.panel_subscription_id IS NULL AND co.status <> 'canceled' AND co.id <> ?
             AND LOWER(" . $db->getDatabasePlatform()->getConcatExpression('sh.sld', 'sh.tld') . ') IN (' . self::quoteList($db, self::spellings($sites)) . ')',
            [$serverId, (int) $order['id']],
        );
        if ($sibling !== false) {
            return ['reason' => __trans('Order #:id (:domain) is a website of this account too: two services cannot share one account.', [':id' => $sibling['id'], ':domain' => $sibling['domain']]), 'domain' => $domain];
        }

        return ['reason' => null, 'domain' => $domain, 'link' => $link, 'adapter' => $adapter, 'account' => $account, 'server_id' => $serverId, 'plan_id' => $planId];
    }

    /**
     * The order becomes a hosting order of $to and activates as already set up.
     *
     * @param array{domain: string, link?: array{org: string, subscription: int}, adapter?: object, account?: \Server_Account, server_id: int, plan_id: int, username?: string} $found
     */
    private static function link(\Pimple\Container $di, int $orderId, array $matched, array $from, array $to, array $found, string $server): void
    {
        $domain = $found['domain'];
        $link = $found['link'] ?? null;
        $login = self::login($found);
        $hosting = $di['mod_service']('servicehosting');
        [$sld, $tld] = self::split($domain);
        // Only the hosting keys of the product (as Upgrades::hostingConfig()): the order keeps its own billing settings
        // (prorata_day and the like), so its renewals fall as before.
        $hostingConfig = array_intersect_key($to['config'], ['reseller' => true, 'server_group_id' => true]) + [
            'server_id' => $found['server_id'],
            'hosting_plan_id' => $found['plan_id'],
            'product_id' => $to['id'],
            'domain' => ['action' => 'owndomain', 'owndomain_sld' => $sld, 'owndomain_tld' => $tld],
            'sld' => $sld,
            'tld' => $tld,
            'import' => true,
            'username' => $login,
        ];
        $note = $link === null
            ? sprintf('Moved by staff from %s to %s, account %s on server %s', $from['title'], $to['title'], $login, $server)
            : sprintf('Moved by staff from %s to %s, linked to the existing account %s on the server', $from['title'], $to['title'], $domain);

        $db = self::db($di);
        $release = $link === null ? null : self::lock($db, $link);
        $db->beginTransaction();
        try {
            self::recheck($db, $matched, $link, 0);
            $order = $di['db']->getExistingModelById('ClientOrder', $orderId, 'Order not found');
            $customId = (int) $order->service_id;
            $config = json_decode((string) $order->config, true);
            $config = array_merge(is_array($config) ? $config : [], $hostingConfig);
            $order->product_id = $to['id'];
            $order->title = mb_substr($hosting->getCartProductTitle($di['em']->find(Product::class, $to['id']), $config) ?? $to['title'], 0, 255);
            $order->config = json_encode($config);
            $service = $hosting->action_create($order);
            if ($link !== null) {
                $service->panel_org_id = $link['org'];
                $service->panel_subscription_id = $link['subscription'];
                $di['db']->store($service);
            }
            $order->service_type = 'hosting';
            $order->service_id = $service->id;
            $order->updated_at = date('Y-m-d H:i:s');
            $di['db']->store($order);
            $hosting->action_activate($order);
            $db->delete('service_custom', ['id' => $customId]);
            $di['mod_service']('order')->saveStatusChange($order, $note);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }

            throw $e;
        } finally {
            $release?->__invoke();
        }
        $di['logger']->info('Order #%s: %s', $orderId, $note);
    }

    /**
     * A hosting service without a login gets the found account in place.
     *
     * @param array{domain: string, link?: array{org: string, subscription: int}, adapter?: object, account?: \Server_Account, server_id: int, plan_id: int, username?: string} $found
     */
    private static function attach(\Pimple\Container $di, int $orderId, array $matched, array $found, ?string $serverIp): void
    {
        $login = self::login($found);
        $link = $found['link'] ?? null;
        $note = sprintf('Linked by staff to the existing account %s on the server', $found['domain']);
        $db = self::db($di);
        [$sld, $tld] = self::split($found['domain']);
        $release = $link === null ? null : self::lock($db, $link);
        try {
            $db->transactional(static function () use ($di, $db, $orderId, $matched, $login, $found, $link, $note, $sld, $tld, $serverIp): void {
                self::recheck($db, $matched, $link, (int) $matched['service_id']);
                $order = $di['db']->getExistingModelById('ClientOrder', $orderId, 'Order not found');
                // With the server, plan and (ASCII) domain the lookup used: an imported row may lack or spell them otherwise,
                // and every panel action reads them.
                $data = [
                    'username' => $login,
                    'service_hosting_server_id' => $found['server_id'], 'service_hosting_hp_id' => $found['plan_id'], 'sld' => $sld, 'tld' => $tld,
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
                $serverChanged = $found['server_id'] !== (int) $matched['service_hosting_server_id'];
                if ($link !== null || $serverChanged) {
                    $data += ['panel_org_id' => $link['org'] ?? null, 'panel_subscription_id' => $link['subscription'] ?? null];
                }
                if ($serverChanged) {
                    $ips = $db->fetchAssociative('SELECT sh.ip, s.ip AS server_ip FROM service_hosting sh JOIN service_hosting_server s ON s.id = sh.service_hosting_server_id WHERE sh.id = ?', [(int) $order->service_id]);
                    if ($ips !== false && $ips['ip'] === $ips['server_ip']) {
                        $data['ip'] = $serverIp;
                    }
                }
                $db->update('service_hosting', $data, ['id' => (int) $order->service_id]);
                $di['mod_service']('order')->saveStatusChange($order, $note);
            });
        } finally {
            $release?->__invoke();
        }
        $di['logger']->info('Order #%s: %s', $orderId, $note);
    }

    /**
     * Inside the move's transaction, check the order still matches and any linked account is still available.
     *
     * @param array<string, mixed> $matched the order row check() matched
     * @param array{org: string, subscription: int}|null $link
     */
    private static function recheck(Connection $db, array $matched, ?array $link, int $serviceId): void
    {
        $lock = $db->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ' FOR UPDATE' : '';
        $now = $db->fetchAssociative('SELECT client_id, product_id, status, service_type, service_id FROM client_order WHERE id = ?' . $lock, [(int) $matched['id']]);
        foreach ($now === false ? ['id' => null] : $now as $key => $value) {
            if ((string) $value !== (string) ($matched[$key] ?? '')) {
                throw new InformationException('This service changed while it was being moved. Preview it again.');
            }
        }
        if ($link !== null) {
            $linked = $db->fetchOne('SELECT id FROM service_hosting WHERE panel_org_id = ? AND panel_subscription_id = ? AND id <> ?', [$link['org'], $link['subscription'], $serviceId]);
            if ($linked !== false) {
                throw new InformationException('This account is already linked to hosting service #:id.', [':id' => $linked]);
            }
        }
    }

    /**
     * One move or link of a panel account at a time (a MySQL named lock on its organization and subscription, so also
     * when two server records point at one panel); returns the release.
     *
     * @param array{org: string, subscription: int} $link
     */
    private static function lock(Connection $db, array $link): \Closure
    {
        if (!$db->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return static function (): void {};
        }
        $name = 'cf:panel:' . sha1($link['org'] . '/' . $link['subscription']);
        if ((int) $db->fetchOne('SELECT GET_LOCK(?, 10)', [$name]) !== 1) {
            throw new InformationException('Another move or link of this account is running. Try again in a moment.');
        }

        return static function () use ($db, $name): void {
            $db->fetchOne('SELECT RELEASE_LOCK(?)', [$name]);
        };
    }

    /**
     * Whether the automatic promotions for renewals would discount the order on $toId other than on its own product:
     * the move keeps the price, so it must not change what a renewal bills.
     */
    private static function renewalPromoChanges(\Pimple\Container $di, int $orderId, int $toId): bool
    {
        $order = $di['db']->getExistingModelById('ClientOrder', $orderId, 'Order not found');
        $client = $di['db']->getExistingModelById('Client', (int) $order->client_id, 'Client not found');
        $products = $di['mod_service']('product');
        $discount = static fn (): float => round((float) ($products->getAutoRenewalPromoAdjustment($order, $client, (float) $order->price, (float) max(1, (int) $order->quantity), true)['discount_amount'] ?? 0), 2);
        $now = $discount();
        $order->product_id = $toId; // in memory only, never stored

        return $discount() !== $now;
    }

    /**
     * Each domain in both spellings a hosting row may hold (ASCII and Unicode), to compare stored rows with.
     *
     * @param list<string> $domains ASCII domains
     *
     * @return list<string>
     */
    private static function spellings(array $domains): array
    {
        $out = [];
        foreach ($domains as $domain) {
            $out[] = $domain;
            $out[] = mb_strtolower((string) (idn_to_utf8($domain, IDNA_NONTRANSITIONAL_TO_UNICODE) ?: $domain));
        }

        return array_values(array_unique($out));
    }

    /** Read a panel login before any writes so a panel failure leaves the service as it was. */
    private static function login(array $found): string
    {
        return $found['username'] ?? ((string) $found['adapter']->synchronizeAccount($found['account'])->getUsername()
            ?: throw new InformationException('The server did not give the login of this account.'));
    }

    /**
     * Each order's domain, as the import recorded it in the order history ("Imported from WHMCS (Service #12
     * (example.com), status Active)", every importer), and how many open orders of the product have it.
     *
     * @return array{of: array<int, string>, count: array<string, int>}
     */
    private static function domains(Connection $db, int $productId): array
    {
        $of = [];
        $count = [];
        $rows = $db->fetchAllAssociative(
            "SELECT s.client_order_id, s.notes, o.status FROM client_order_status s JOIN client_order o ON o.id = s.client_order_id
             WHERE o.product_id = ? AND s.notes LIKE 'Imported from %' ORDER BY s.id",
            [$productId],
        );
        foreach ($rows as $row) {
            $id = (int) $row['client_order_id'];
            if (!isset($of[$id]) && preg_match('/^Imported from .+? \(Service #\d+ \(([^\s()]+\.[^\s()]+)\), status /', (string) $row['notes'], $m) === 1) {
                // As a hosting service stores it (bücher.example as xn--bcher-kva.example).
                $of[$id] = HostingService::asciiDomain($m[1]);
                if ($row['status'] !== 'canceled') {
                    $count[$of[$id]] = ($count[$of[$id]] ?? 0) + 1;
                }
            }
        }

        return ['of' => $of, 'count' => $count];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{0: array, 1: array, 2: ?array{server_id: int, server: string, server_ip: ?string, plan: \Model_ServiceHostingHp, lookup: bool, accounts: ?array<string, string|false|null>}}
     */
    private static function products(\Pimple\Container $di, array $data): array
    {
        $db = self::db($di);
        $from = self::product($db, (int) ($data['from_product_id'] ?? 0));
        if ($from === null) {
            throw new InformationException('Choose the product to move the services from.');
        }
        $to = self::product($db, (int) ($data['to_product_id'] ?? 0));
        // A hosting product goes only to itself: its services are linked in place.
        if ($to === null || ($from['type'] === 'hosting') !== ($to['id'] === $from['id'])) {
            throw new InformationException('Choose another custom product, or a hosting product with a server and hosting plan, to move the services to. To link the services of a hosting product to their accounts, choose that product in both.');
        }

        if ($to['type'] !== 'hosting') {
            return [$from, $to, null];
        }

        $productServerId = (int) $to['config']['server_id'];
        $serverId = filter_var($data['server_id'] ?? $productServerId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($serverId !== $productServerId) {
            $group = empty($to['config']['server_group_id']) ? false : $db->fetchOne('SELECT servers FROM service_hosting_server_group WHERE id = ?', [(int) $to['config']['server_group_id']]);
            if (!in_array($serverId, json_decode((string) $group, true) ?? [], true)) {
                throw new InformationException('Choose the product\'s server or a server of its group.');
            }
        }
        $server = $di['db']->load('ServiceHostingServer', $serverId) ?? throw new InformationException('Server not found');
        $plan = $di['db']->load('ServiceHostingHp', (int) $to['config']['hosting_plan_id']) ?? throw new InformationException('Hosting plan not found');
        if (!HostingService::planFitsServer($plan->config, $serverId)) {
            throw new InformationException('This hosting plan belongs to another server.');
        }
        try {
            $lookup = method_exists($di['mod_service']('servicehosting')->getServerManager($server), 'linkAccount');
        } catch (\Server_Exception $e) {
            throw new InformationException($e->getMessage());
        }
        $accounts = null;
        if (!$lookup && ($list = trim((string) ($data['accounts'] ?? ''))) !== '') {
            $accounts = [];
            $domainsByUsername = [];
            foreach (preg_split('/\R/', $list) as $line) {
                $parts = preg_split('/[\s,:]+/', $line, -1, PREG_SPLIT_NO_EMPTY);
                if (count($parts) === 2) {
                    $domain = HostingService::asciiDomain($parts[0]);
                    $accounts[$domain] = array_key_exists($domain, $accounts) ? null : $parts[1];
                    $domainsByUsername[strtolower($parts[1])][] = $domain;
                }
            }
            // Cancelling one service would remove the shared account.
            foreach ($domainsByUsername as $domains) {
                foreach (count($domains) > 1 ? $domains : [] as $domain) {
                    $accounts[$domain] = false;
                }
            }
        }

        return [$from, $to, ['server_id' => $serverId, 'server' => (string) $server->name, 'server_ip' => $server->ip, 'plan' => $plan, 'lookup' => $lookup, 'accounts' => $accounts]];
    }

    private static function limit(?array $destination): int
    {
        return $destination !== null && !$destination['lookup'] ? 250 : self::LIMIT;
    }

    /** A custom product, or a hosting product with its server and plan; null for anything else. */
    private static function product(Connection $db, int $id): ?array
    {
        $row = $db->fetchAssociative("SELECT id, title, type, config FROM product WHERE id = ? AND is_addon = 0 AND type IN ('custom', 'hosting')", [$id]);
        $config = $row === false ? null : json_decode((string) $row['config'], true);
        $config = is_array($config) ? $config : [];
        if ($row === false || ($row['type'] === 'hosting' && ((int) ($config['server_id'] ?? 0) <= 0 || (int) ($config['hosting_plan_id'] ?? 0) <= 0))) {
            return null;
        }

        return ['id' => (int) $row['id'], 'title' => (string) $row['title'], 'type' => (string) $row['type'], 'config' => $config];
    }

    /** @return array{0: string, 1: string} the first label and the rest (".com", ".co.uk"), as a hosting service stores them */
    private static function split(string $domain): array
    {
        $dot = (int) strpos($domain, '.');

        return [substr($domain, 0, $dot), substr($domain, $dot)];
    }

    private static function quoteList(Connection $db, array $values): string
    {
        return implode(',', array_map(static fn (string $v): string => (string) $db->quote($v), $values));
    }

    private static function db(\Pimple\Container $di): Connection
    {
        return $di['em']->getConnection();
    }
}
