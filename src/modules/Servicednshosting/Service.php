<?php

declare(strict_types=1);

/*
 * Copyright (c) 2023-2026 Taras Kondratyuk
 * Copyright (c) 2025-2026 Namingo contributors
 * Copyright (c) 2026 Terbora Ltd.
 *
 * Licensed under the Apache License, Version 2.0.
 * See the LICENSE file for the full license text.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace ClientFence\Mod\Servicednshosting;

use ClientFence\InformationException;
use ClientFence\InjectionAwareInterface;
use ClientFence\Mod\Order\Entity\Order;
use ClientFence\Mod\Product\Entity\Product;
use ClientFence\Mod\Servicednshosting\Entity\ServiceDns;
use ClientFence\Mod\Servicednshosting\Provider\ClouDNS;
use ClientFence\Mod\Servicednshosting\Provider\Cloudflare;
use ClientFence\Mod\Servicednshosting\Provider\Desec;
use ClientFence\Mod\Servicednshosting\Provider\DigitalOcean;
use ClientFence\Mod\Servicednshosting\Provider\DnsHostingProviderInterface;
use ClientFence\Mod\Servicednshosting\Provider\Hetzner;
use ClientFence\Mod\Servicednshosting\Provider\PowerDNS;
use ClientFence\Mod\Servicednshosting\Provider\Provider;
use ClientFence\SecretConfig;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Pimple\Container;

class Service implements InjectionAwareInterface
{
    private const PROVIDERS = [
        'cloudflare' => Cloudflare::class,
        'powerdns' => PowerDNS::class,
        'hetzner' => Hetzner::class,
        'cloudns' => ClouDNS::class,
        'digitalocean' => DigitalOcean::class,
        'desec' => Desec::class,
    ];

    private const SECRET_FIELDS = ['token', 'auth_id', 'password'];

    private ?Container $di = null;

    public function setDi(Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Container
    {
        return $this->di;
    }

    public function getModulePermissions(): array
    {
        return ['view' => [
            'type' => 'bool',
            'display_name' => __trans('View DNS records'),
            'description' => __trans('Allows staff to view DNS zones and records.'),
        ]];
    }

    public function prepareProductConfig(mixed $data, array $saved): array
    {
        $data = is_array($data) ? $data : [];
        $field = static fn (string $key): string => is_string($data[$key] ?? null) ? trim($data[$key]) : '';
        $provider = $field('provider');
        if (!isset(self::PROVIDERS[$provider])) {
            throw new InformationException('Choose a DNS provider.');
        }
        $previous = ($saved['provider'] ?? null) === $provider ? $saved : [];
        $config = ['provider' => $provider];
        foreach ($provider === 'cloudns' ? ['auth_id', 'password'] : ['token'] as $key) {
            $config[$key] = $field($key) !== '' ? $field($key) : ($previous[$key] ?? '');
            if ($config[$key] === '') {
                throw new InformationException('Enter the DNS provider credentials.', null, 0, true);
            }
        }

        if ($provider === 'cloudflare') {
            $config['account_id'] = $field('account_id');
            if (!preg_match('/^[a-f0-9]{32}$/iD', $config['account_id'])) {
                throw new InformationException('Enter a valid Cloudflare account ID.', null, 0, true);
            }
            if ($field('token') !== '' && str_contains($field('token'), ':')) {
                [$email, $key] = explode(':', $field('token'), 2);
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $key === '') {
                    throw new InformationException('Enter a Cloudflare API token or an email address and global API key separated by a colon.', null, 0, true);
                }
            }
        }

        $nameservers = preg_split('/\s+/', strtolower($field('nameservers')), -1, PREG_SPLIT_NO_EMPTY);
        $config['nameservers'] = array_values(array_unique(array_map($this->normalizeDomain(...), $nameservers)));
        if ($provider === 'powerdns') {
            $url = rtrim($field('api_url'), '/');
            $parts = parse_url($url);
            if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
                throw new InformationException('Enter a full HTTP or HTTPS address for the PowerDNS API.', null, 0, true);
            }
            if ($config['nameservers'] === []) {
                throw new InformationException('Enter the authoritative nameservers for PowerDNS.');
            }
            $config['api_url'] = $url;
            $config['soa_email'] = $field('soa_email');
            if ($config['soa_email'] !== '' && !filter_var($config['soa_email'], FILTER_VALIDATE_EMAIL)) {
                throw new InformationException('Enter a valid SOA email address.', null, 0, true);
            }
        }
        if ($provider === 'cloudns' && $field('auth_id') !== '' && !ctype_digit($field('auth_id'))) {
            throw new InformationException('Enter a numeric ClouDNS authentication ID.', null, 0, true);
        }

        return SecretConfig::encryptSecretFields($config, self::SECRET_FIELDS, $this->di['crypt']);
    }

    public function clientSettableConfigKeys(): array
    {
        return ['domain_name', 'period', 'quantity'];
    }

    public function attachOrderConfig(Product $product, array $data): array
    {
        $config = json_decode($product->getConfig() ?? '', true)['dns'] ?? [];
        if (!isset(self::PROVIDERS[$config['provider'] ?? ''])) {
            throw new InformationException('This DNS hosting product is not configured.');
        }

        return ['domain_name' => $this->normalizeDomain($data['domain_name'] ?? null)]
            + array_intersect_key($data, array_flip(['period', 'quantity', 'options', '_start_date']));
    }

    public function validateOrderData(array &$data): void
    {
        // One order is one zone (the cart, staff Add New Order and checkout read the quantity from here).
        $data['quantity'] = 1;
        $this->assertDomainFree($data['domain_name']);
    }

    public function getCartProductTitle(Product $product, array $data): string
    {
        return isset($data['domain_name']) ? $this->generateOrderTitle($data) : $product->getTitle();
    }

    public function generateOrderTitle(array $data): string
    {
        return __trans('DNS hosting for :domain', [':domain' => $data['domain_name']]);
    }

    public function getNameservers(Product $product): array
    {
        return json_decode($product->getConfig() ?? '', true)['dns']['nameservers'] ?? [];
    }

    public function action_create(\Model_ClientOrder $order): ServiceDns
    {
        $id = (int) $order->id;
        $repository = $this->di['em']->getRepository(ServiceDns::class);
        $existing = $repository->findOneBy(['orderId' => $id]);
        if ($existing !== null) {
            return $existing;
        }
        $product = $this->di['mod_service']('product')->findProductById((int) $order->product_id);
        $data = $this->attachOrderConfig($product, json_decode($order->config ?? '', true) ?? []);
        $config = json_decode($product->getConfig(), true, flags: JSON_THROW_ON_ERROR)['dns'];

        $this->di['dbal']->insert('service_dnshosting', [
            'order_id' => $id,
            'domain_name' => $data['domain_name'],
            'config' => json_encode($config, JSON_THROW_ON_ERROR),
            'nameservers' => '[]',
        ]);

        return $repository->findOneBy(['orderId' => $id]);
    }

    public function action_activate(\Model_ClientOrder $order): bool
    {
        $this->withZone((int) $order->id, function (?ServiceDns $zone) use ($order): void {
            if ($zone === null) {
                throw new InformationException('DNS zone not found.');
            }
            if ($zone->zoneId === null) {
                // Checked again here: another order may have taken the zone since this one was placed or cancelled.
                $this->assertDomainFree($zone->domain, (int) $order->id);
                $created = $this->provider($zone)->createZone($zone->domain);
                $zone->zoneId = $created['id'];
                $zone->nameservers = $created['nameservers'] ?: $zone->getConfig()['nameservers'];
            }
        });

        return true;
    }

    public function action_suspend(\Model_ClientOrder $order): bool
    {
        // Leave the zone resolving while the order status blocks access to its records.
        return true;
    }

    public function action_unsuspend(\Model_ClientOrder $order): bool
    {
        return true;
    }

    public function action_renew(\Model_ClientOrder $order): bool
    {
        return true;
    }

    public function action_cancel(\Model_ClientOrder $order): bool
    {
        $this->removeZone((int) $order->id, false);

        return true;
    }

    public function action_uncancel(\Model_ClientOrder $order): bool
    {
        return $this->action_activate($order);
    }

    public function action_delete(\Model_ClientOrder $order): void
    {
        $this->removeZone((int) $order->id, true);
    }

    public function toApiArray(ServiceDns $zone, $deep = true, $identity = null): array
    {
        return ['id' => $zone->getId(), 'domain_name' => $zone->domain, 'nameservers' => $zone->nameservers];
    }

    public function getZone(array $data, ?int $clientId): array
    {
        return $this->manageZone((int) $data['order_id'], $clientId, function (ServiceDns $zone, DnsHostingProviderInterface $provider): array {
            $types = $this->recordTypes($zone->getConfig()['provider']);
            try {
                $records = $provider->records($zone->zoneId, $zone->domain);
            } catch (InformationException $e) {
                if ($e->getCode() !== 502) {
                    throw $e;
                }

                return ['error' => $e->getMessage()];
            }
            $nameservers = $zone->nameservers;
            if ($nameservers === []) {
                $nameservers = array_column(array_filter($records, static fn (array $r): bool => $r['name'] === '' && $r['type'] === 'NS'), 'value');
            }

            $publicFields = array_flip(['id', 'name', 'type', 'value', 'ttl', 'priority']);

            return [
                'nameservers' => array_values($nameservers),
                'types' => $types,
                'records' => array_map(static fn (array $record): array => array_intersect_key($record, $publicFields), $records),
            ] + $this->toApiArray($zone);
        });
    }

    public function changeRecord(string $action, array $data, ?int $clientId): bool
    {
        return $this->manageZone((int) $data['order_id'], $clientId, function (ServiceDns $zone, DnsHostingProviderInterface $provider) use ($action, $data): bool {
            $providerName = $zone->getConfig()['provider'];
            if ($action === 'add') {
                $record = $this->recordData($data, $zone->domain, $providerName);
                $provider->addRecord($zone->zoneId, $zone->domain, $record);

                return true;
            }

            foreach ($provider->records($zone->zoneId, $zone->domain) as $old) {
                if ($old['id'] !== (string) ($data['record_id'] ?? '') || !in_array($old['type'], $this->recordTypes($providerName), true)
                    || ($old['type'] === 'DS' && $old['name'] === '')) {
                    continue;
                }
                if ($action === 'del') {
                    $provider->deleteRecord($zone->zoneId, $zone->domain, $old);
                } else {
                    $record = $this->recordData(['record_name' => $old['name'], 'record_type' => $old['type']] + $data + ['record_priority' => $old['priority']], $zone->domain, $providerName);
                    $provider->updateRecord($zone->zoneId, $zone->domain, $old, $record);
                }

                return true;
            }

            throw new InformationException('DNS record not found. Refresh the page and try again.');
        });
    }

    public function getDnssec(array $data, int $clientId): array
    {
        return $this->manageZone((int) $data['order_id'], $clientId, static function (ServiceDns $zone, DnsHostingProviderInterface $provider): array {
            try {
                return $provider->dnssec($zone->zoneId);
            } catch (InformationException $e) {
                if ($e->getCode() !== 502) {
                    throw $e;
                }

                return ['error' => $e->getMessage()];
            }
        });
    }

    public function setDnssec(array $data, int $clientId, bool $enabled): bool
    {
        return $this->manageZone((int) $data['order_id'], $clientId, static function (ServiceDns $zone, DnsHostingProviderInterface $provider) use ($enabled): bool {
            $provider->setDnssec($zone->zoneId, $enabled);

            return true;
        });
    }

    protected function provider(ServiceDns $zone): DnsHostingProviderInterface
    {
        // The product's current settings, so fixed or rotated credentials reach every zone; a zone stays with its provider if the product moved.
        $config = $zone->getConfig();
        $product = json_decode((string) $this->di['dbal']->fetchOne('SELECT p.config FROM client_order o JOIN product p ON p.id = o.product_id WHERE o.id = ?', [$zone->orderId]), true)['dns'] ?? [];
        if (($product['provider'] ?? null) === $config['provider']) {
            $config = $product;
        }
        $config = SecretConfig::decryptMarkedValues($config, $this->di['crypt']);
        $class = self::PROVIDERS[$config['provider']];

        return new $class($this->di['http_client'], $config);
    }

    private function removeZone(int $orderId, bool $delete): void
    {
        $this->withZone($orderId, function (?ServiceDns $zone) use ($delete): void {
            if ($zone === null) {
                return;
            }
            if ($zone->zoneId !== null) {
                $this->provider($zone)->deleteZone($zone->zoneId);
                $zone->zoneId = null;
                $zone->nameservers = [];
            }
            if ($delete) {
                $this->di['em']->remove($zone);
            }
        });
    }

    private function withZone(int $orderId, callable $operation): mixed
    {
        $em = $this->di['em'];
        $result = $operation($em->getRepository(ServiceDns::class)->findOneBy(['orderId' => $orderId]));
        $em->flush();

        return $result;
    }

    private function manageZone(int $orderId, ?int $clientId, callable $operation): mixed
    {
        return $this->withZone($orderId, function (?ServiceDns $zone) use ($orderId, $clientId, $operation): mixed {
            $order = $this->di['dbal']->fetchAssociative('SELECT client_id, service_id, service_type, status FROM client_order WHERE id = ?', [$orderId]);
            if ($zone === null || !$order || (int) $order['service_id'] !== $zone->getId() || $order['service_type'] !== \ClientFence\Mod\Product\Service::DNS
                || ($clientId !== null && (int) $order['client_id'] !== $clientId)) {
                throw new InformationException('DNS order not found.', null, 404);
            }
            if ($zone->zoneId === null || ($clientId !== null && $order['status'] !== Order::STATUS_ACTIVE)) {
                throw new InformationException('DNS order is not active.');
            }

            return $operation($zone, $this->provider($zone));
        });
    }

    /** A zone, or its parent or child, held by another order that is not cancelled (a shared name server answers from the most specific zone). */
    private function assertDomainFree(string $domain, int $orderId = 0): void
    {
        $labels = explode('.', $domain);
        $parents = array_map(static fn (int $i): string => implode('.', array_slice($labels, $i)), range(0, count($labels) - 2));
        $taken = $this->di['dbal']->fetchOne(
            'SELECT z.id FROM service_dnshosting z JOIN client_order o ON o.id = z.order_id WHERE o.status <> ? AND z.order_id <> ? AND (z.domain_name IN (?) OR z.domain_name LIKE ?)',
            [Order::STATUS_CANCELED, $orderId, $parents, '%.' . $domain],
            [ParameterType::STRING, ParameterType::INTEGER, ArrayParameterType::STRING, ParameterType::STRING],
        );
        // Orders not set up yet have no zone row: their domain is in the order config.
        $pending = $this->di['dbal']->fetchFirstColumn(
            'SELECT config FROM client_order WHERE service_type = ? AND status IN (?) AND id <> ?',
            [\ClientFence\Mod\Product\Service::DNS, [Order::STATUS_PENDING_SETUP, Order::STATUS_FAILED_SETUP], $orderId],
            [ParameterType::STRING, ArrayParameterType::STRING, ParameterType::INTEGER],
        );
        foreach ($pending as $config) {
            $other = (string) (json_decode((string) $config, true)['domain_name'] ?? '');
            $taken = $taken ?: ($other !== '' && ($other === $domain || str_ends_with($other, '.' . $domain) || str_ends_with($domain, '.' . $other)));
        }
        if ($taken) {
            throw new InformationException('This DNS zone, or a zone above or below it, already has an order.');
        }
    }

    private function normalizeDomain(mixed $input, bool $target = false): string
    {
        $name = is_string($input) ? rtrim(trim($input), '.') : '';
        $domain = $name === '' ? false : idn_to_ascii($name, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
        // A record target may hold underscores (selector1._domainkey...), which a host name may not.
        if (!is_string($domain) || !str_contains($domain, '.') || !filter_var($target ? str_replace('_', 'x', $domain) : $domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || filter_var($domain, FILTER_VALIDATE_IP)) {
            throw new InformationException('Enter a valid domain name.');
        }

        return strtolower($domain);
    }

    private function recordTypes(string $provider): array
    {
        $types = ['A', 'AAAA', 'CNAME', 'MX', 'TXT'];

        return match ($provider) {
            'digitalocean' => $types,
            'cloudflare', 'hetzner' => [...$types, 'DS'],
            default => [...$types, 'SPF', 'DS'],
        };
    }

    private function recordData(array $data, string $domain, string $provider): array
    {
        $type = strtoupper(is_string($data['record_type'] ?? null) ? $data['record_type'] : '');
        if (!in_array($type, $this->recordTypes($provider), true)) {
            throw new InformationException('Unsupported DNS record type.');
        }

        $name = is_string($data['record_name'] ?? '') ? strtolower(trim($data['record_name'] ?? '')) : '!';
        if ($name === '@' || rtrim($name, '.') === $domain) {
            $name = '';
        } elseif (str_ends_with(rtrim($name, '.'), '.' . $domain)) {
            $name = substr(rtrim($name, '.'), 0, -strlen($domain) - 1);
        }
        if ($name !== '' && (!preg_match('/^(?:\*|[a-z0-9_-]{1,63})(?:\.[a-z0-9_-]{1,63})*$/D', $name) || strlen($name . '.' . $domain) > 253)) {
            throw new InformationException('Enter a relative record name such as www, or @ for the zone apex.');
        }

        $ttl = filter_var($data['record_ttl'] ?? 3600, FILTER_VALIDATE_INT);
        $min = match ($provider) { 'hetzner' => 60, 'digitalocean' => 30, default => 1 };
        if ($ttl === false || $ttl < $min || $ttl > 2147483647) {
            throw new InformationException('Enter a TTL between :min and :max seconds.', [':min' => $min, ':max' => 2147483647]);
        }
        if ($provider === 'cloudns' && !in_array($ttl, [60, 300, 900, 1800, 3600, 21600, 43200, 86400, 172800, 259200, 604800, 1209600, 2592000], true)) {
            throw new InformationException('ClouDNS does not support this TTL.');
        }

        $value = $data['record_value'] ?? null;
        if (!is_string($value) || !preg_match('/^[^\x00-\x1f\x7f]*$/uD', $value)) {
            throw new InformationException('Enter a single-line DNS record value.');
        }
        $priority = null;
        if ($type === 'MX') {
            $priority = $data['record_priority'] ?? '';
            $priority = filter_var($priority === '' ? 0 : $priority, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 65535]]);
            if ($priority === false) {
                throw new InformationException('Enter an MX priority between 0 and 65535.');
            }
        }

        if ($type === 'MX' && $priority === 0 && trim($value) === '.') {
            $value = '.';
        } elseif (in_array($type, ['CNAME', 'MX'], true)) {
            $value = $this->normalizeDomain($value, true) . (str_ends_with(trim($value), '.') ? '.' : '');
        } elseif (in_array($type, ['A', 'AAAA'], true)) {
            $value = trim($value);
            if (!filter_var($value, FILTER_VALIDATE_IP, $type === 'A' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6)) {
                throw new InformationException('Enter a valid IP address for a :type record.', [':type' => $type]);
            }
            $value = inet_ntop(inet_pton($value));
        } elseif (in_array($type, ['TXT', 'SPF'], true)) {
            $value = Provider::txtValue($value);
            if (strlen($value) > 2048 || ($type === 'SPF' && !preg_match('/^v=spf1(?: |$)/i', $value))) {
                throw new InformationException('Enter a valid :type value of up to :max bytes.', [':type' => $type, ':max' => 2048]);
            }
        } elseif ($type === 'DS') {
            $parts = preg_split('/\s+/', trim($value));
            if ($name === '' || count($parts) !== 4 || !ctype_digit($parts[0]) || (int) $parts[0] > 65535
                || !ctype_digit($parts[1]) || (int) $parts[1] < 1 || (int) $parts[1] > 255
                || !ctype_xdigit($parts[3]) || strlen($parts[3]) !== ([1 => 40, 2 => 64, 3 => 64, 4 => 96][$parts[2]] ?? 0)) {
                throw new InformationException('Enter a DS record for a subdomain with key tag, algorithm, digest type, and hexadecimal digest.');
            }
            $value = implode(' ', [(int) $parts[0], (int) $parts[1], (int) $parts[2], strtolower($parts[3])]);
        }

        return compact('name', 'type', 'value', 'ttl', 'priority');
    }
}
