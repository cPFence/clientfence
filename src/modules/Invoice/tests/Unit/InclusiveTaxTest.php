<?php

declare(strict_types=1);

/**
 * ClientFence: prices that include tax, as WHMCS Inclusive Tax (Invoice\Service::applyInclusiveTax()), on an in-memory
 * SQLite copy of the tables.
 */

use ClientFence\Mod\Invoice\Service;
use Doctrine\DBAL\DriverManager;

/**
 * Invoice 1 of client 5: a 12.00 order line, a -2.40 promo line and a 50.00 deposit line.
 *
 * @return array{Service, Doctrine\DBAL\Connection, Model_Invoice}
 */
function inclusiveTaxInvoice(bool $enabled, bool $inclusive, float $invoiceRate, float $locationRate): array
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE setting (param TEXT PRIMARY KEY, value TEXT)');
    $db->executeStatement('CREATE TABLE invoice_item (id INTEGER PRIMARY KEY, invoice_id INTEGER, type TEXT, price REAL, quantity INTEGER DEFAULT 1, taxed INTEGER DEFAULT 0)');
    $db->insert('setting', ['param' => 'tax_enabled', 'value' => $enabled ? '1' : '0']);
    $db->insert('setting', ['param' => 'tax_inclusive', 'value' => $inclusive ? '1' : '0']);
    foreach ([['order', 12.0], ['custom', -2.4], ['deposit', 50.0]] as [$type, $price]) {
        $db->insert('invoice_item', ['invoice_id' => 1, 'type' => $type, 'price' => $price, 'taxed' => (int) ($invoiceRate > 0 && $type !== 'deposit')]);
    }
    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $tax = Mockery::mock(ClientFence\Mod\Invoice\ServiceTax::class);
    $tax->shouldReceive('getLocationTaxRate')->andReturn($locationRate);
    $legacy = Mockery::mock(ClientFence\Database::class);
    $legacy->shouldReceive('load')->andReturn($client);
    $di = new Pimple\Container();
    $di['dbal'] = $db;
    $di['db'] = $legacy;
    $di['mod_service'] = $di->protect(fn (): object => $tax);
    $service = new Service();
    $service->setDi($di);
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    $invoice->id = 1;
    $invoice->client_id = 5;
    $invoice->taxrate = (string) $invoiceRate;

    return [$service, $db, $invoice];
}

test('catalog prices that include tax become net lines; an exempt client pays the net', function (bool $enabled, bool $inclusive, float $invoiceRate, float $locationRate, array $prices): void {
    [$service, $db, $invoice] = inclusiveTaxInvoice($enabled, $inclusive, $invoiceRate, $locationRate);

    $service->applyInclusiveTax($invoice);

    expect(array_map('floatval', $db->fetchFirstColumn('SELECT price FROM invoice_item ORDER BY id')))->toBe($prices);
})->with([
    'taxed client, 20%' => [true, true, 20.0, 20.0, [10.0, -2.0, 50.0]],
    'exempt client, location 20%' => [true, true, 0.0, 20.0, [10.0, -2.0, 50.0]],
    'no tax rule for the client' => [true, true, 0.0, 0.0, [12.0, -2.4, 50.0]],
    'setting off' => [true, false, 20.0, 20.0, [12.0, -2.4, 50.0]],
    'tax switched off' => [false, true, 20.0, 20.0, [12.0, -2.4, 50.0]],
]);

test('the cent the net lines round away goes on one line, so the total is the shown price', function (): void {
    [$service, $db, $invoice] = inclusiveTaxInvoice(true, true, 20.0, 20.0);
    $db->executeStatement('DELETE FROM invoice_item');
    foreach ([20.0, 20.0, 20.0] as $price) {
        $db->insert('invoice_item', ['invoice_id' => 1, 'type' => 'order', 'price' => $price, 'taxed' => 1]);
    }

    $service->applyInclusiveTax($invoice);

    $net = (float) $db->fetchOne('SELECT SUM(price) FROM invoice_item');
    expect(round($net + round($net * 0.2, 2), 2))->toBe(60.0)
        ->and(array_map('floatval', $db->fetchFirstColumn('SELECT price FROM invoice_item ORDER BY id')))->toBe([16.66, 16.67, 16.67]);
});
