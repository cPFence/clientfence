<?php

declare(strict_types=1);

/**
 * ClientFence: the WHMCS import's PayPal billing agreements (Whmcs/Money.php, step agreements): a live WHMCS
 * agreement of the PayPal Billing Agreements gateway becomes the client's saved PayPal Auto-Pay method, only when the
 * import setting asks for it. In-memory SQLite for WHMCS and ClientFence.
 */

use ClientFence\Mod\Clientfence\Whmcs\Map;
use ClientFence\Mod\Clientfence\Whmcs\Money;
use ClientFence\Mod\Clientfence\Whmcs\Source;
use Doctrine\DBAL\DriverManager;

function whmcsAgreementsRun(bool $setting): array
{
    $whmcs = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $whmcs->executeStatement('CREATE TABLE tblpaymethods (id INTEGER PRIMARY KEY, userid INTEGER, gateway_name TEXT, deleted_at TEXT)');
    $whmcs->executeStatement('CREATE TABLE mod_lcwsoftpaypalbillingagreements (userid INTEGER, agreementID TEXT)');
    $whmcs->insert('tblpaymethods', ['id' => 1, 'userid' => 12, 'gateway_name' => Money::AGREEMENT_GATEWAY]);
    $whmcs->insert('tblpaymethods', ['id' => 2, 'userid' => 13, 'gateway_name' => Money::AGREEMENT_GATEWAY]);
    // Removed in WHMCS, and another gateway's card: neither is brought.
    $whmcs->insert('tblpaymethods', ['id' => 3, 'userid' => 14, 'gateway_name' => Money::AGREEMENT_GATEWAY, 'deleted_at' => '2026-11-01 00:00:00']);
    $whmcs->insert('tblpaymethods', ['id' => 4, 'userid' => 12, 'gateway_name' => 'stripe']);
    $whmcs->insert('mod_lcwsoftpaypalbillingagreements', ['userid' => 12, 'agreementID' => 'B-12']);
    $whmcs->insert('mod_lcwsoftpaypalbillingagreements', ['userid' => 13, 'agreementID' => 'B-13OLD']);
    $whmcs->insert('mod_lcwsoftpaypalbillingagreements', ['userid' => 13, 'agreementID' => 'B-13NEW']);
    $whmcs->insert('mod_lcwsoftpaypalbillingagreements', ['userid' => 14, 'agreementID' => 'B-14']);

    $target = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $target->executeStatement('CREATE TABLE ' . Map::TABLE . ' (source TEXT, entity TEXT, source_id TEXT, target_id INTEGER, created_at TEXT, PRIMARY KEY (source, entity, source_id))');
    $target->executeStatement('CREATE TABLE pay_gateway (id INTEGER PRIMARY KEY, gateway TEXT, name TEXT, test_mode INTEGER)');
    $target->executeStatement('CREATE TABLE pay_gateway_customer (id INTEGER PRIMARY KEY, pay_gateway_id INTEGER, client_id INTEGER, test_mode INTEGER, external_customer_id TEXT, saved_cards INTEGER DEFAULT 0, default_payment_method TEXT, default_card TEXT, auto_pay_at TEXT, created_at TEXT, updated_at TEXT)');
    // Still in test mode while importing: WHMCS's agreements are live ones all the same.
    $target->insert('pay_gateway', ['id' => 4, 'gateway' => 'PayPalAutoPay', 'name' => 'PayPal', 'test_mode' => 1]);
    $map = new Map($target);
    foreach ([12, 13, 14] as $client) {
        $map->put('client', $client, $client + 100);
    }
    $money = new Money(new Source($whmcs), $target, $map, '2026-11-08 12:00:00', $setting);
    [$after] = $money->batch('agreements', 0, 50);

    return [$target, $money, $after];
}

test('brings live WHMCS billing agreements as saved PayPal Auto-Pay methods when asked', function (): void {
    [$target, $money] = whmcsAgreementsRun(true);

    expect($target->fetchAllKeyValue('SELECT client_id, default_payment_method FROM pay_gateway_customer WHERE pay_gateway_id = 4 AND saved_cards = 1 AND test_mode = 0 ORDER BY client_id'))
        ->toBe([112 => 'B-12', 113 => 'B-13NEW'])
        ->and($money->total('agreements'))->toBe(2)
        ->and(implode(' ', $money->warnings()))->toContain('Client #13 had 2 PayPal billing agreements in WHMCS: the newest, B-13NEW, was kept.');
    // The invoices of WHMCS's gateway go to PayPal Auto-Pay.
    expect((fn () => $this->gateway(Money::AGREEMENT_GATEWAY))->call($money))->toBe(4);
});

test('brings no billing agreements unless the import setting asks for them', function (): void {
    [$target, $money, $after] = whmcsAgreementsRun(false);

    expect((int) $target->fetchOne('SELECT COUNT(*) FROM pay_gateway_customer'))->toBe(0)
        ->and($after)->toBeNull()
        ->and($money->total('agreements'))->toBe(0);
});
