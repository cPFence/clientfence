<?php

declare(strict_types=1);

/**
 * ClientFence: a free domain renews free while its hosting lasts, as WHMCS (Product\Service::hasFreeDomainRenewal()),
 * on an in-memory SQLite copy of the tables.
 */

use ClientFence\Mod\Product\Service;
use Doctrine\DBAL\DriverManager;

/**
 * Domain order 20 (example.com, registered) and hosting order 10 in group g1 of client 5.
 */
function freeRenewalService(array $settings, array $hosting = [], array $domain = []): Service
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE client_order (id INTEGER PRIMARY KEY, client_id INTEGER, group_id TEXT, product_id INTEGER, period TEXT, status TEXT, config TEXT)');
    $db->executeStatement('CREATE TABLE product (id INTEGER PRIMARY KEY, type TEXT, config TEXT)');
    $db->insert('product', ['id' => 1, 'type' => 'hosting', 'config' => json_encode($settings + ['free_domain' => true, 'free_tlds' => ['.com'], 'free_domain_periods' => ['1Y'], 'free_domain_renewals' => true])]);
    $db->insert('product', ['id' => 2, 'type' => 'domain', 'config' => '{}']);
    $db->insert('client_order', $hosting + ['id' => 10, 'client_id' => 5, 'group_id' => 'g1', 'product_id' => 1, 'period' => '1Y', 'status' => 'active',
        'config' => json_encode(['domain' => ['action' => 'register', 'register_sld' => 'example', 'register_tld' => '.com']])]);
    $db->insert('client_order', $domain + ['id' => 20, 'client_id' => 5, 'group_id' => 'g1', 'product_id' => 2, 'period' => '1Y', 'status' => 'active',
        'config' => json_encode(['action' => 'register', 'register_sld' => 'Example', 'register_tld' => '.com', 'period' => '1Y'])]);
    $di = new Pimple\Container();
    $di['dbal'] = $db;
    $service = new Service();
    $service->setDi($di);

    return $service;
}

test('a domain renews free only while the hosting it came with gives it free', function (array $settings, array $hosting, array $domain, bool $free): void {
    expect(freeRenewalService($settings, $hosting, $domain)->hasFreeDomainRenewal(20))->toBe($free);
})->with([
    'hosting active' => [[], [], [], true],
    'hosting suspended' => [[], ['status' => 'suspended'], [], true],
    'hosting canceled' => [[], ['status' => 'canceled'], [], false],
    'free renewals off' => [['free_domain_renewals' => false], [], [], false],
    'TLD not free' => [['free_tlds' => ['.net']], [], [], false],
    'hosting billed monthly, free for yearly only' => [[], ['period' => '1M'], [], false],
    'domain ordered on its own' => [[], [], ['group_id' => 'g2'], false],
    'hosting for another domain' => [[], ['config' => json_encode(['domain' => ['action' => 'register', 'register_sld' => 'other', 'register_tld' => '.com']])], [], false],
    'free transfer' => [['free_transfer' => true], ['config' => json_encode(['domain' => ['action' => 'transfer', 'transfer_sld' => 'example', 'transfer_tld' => '.com']])],
        ['config' => json_encode(['action' => 'transfer', 'transfer_sld' => 'example', 'transfer_tld' => '.com'])], true],
]);
