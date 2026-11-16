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
    $db->executeStatement('CREATE TABLE invoice (id INTEGER PRIMARY KEY, tax_adjust REAL DEFAULT 0)');
    $db->insert('invoice', ['id' => 1]);
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

test('the tax is the shown total less the net, so the total is the shown price (WHMCS)', function (float $rate, array $prices, float $adjust): void {
    [$service, $db, $invoice] = inclusiveTaxInvoice(true, true, $rate, $rate);
    $db->executeStatement('DELETE FROM invoice_item');
    foreach ($prices as $price) {
        $db->insert('invoice_item', ['invoice_id' => 1, 'type' => 'order', 'price' => $price, 'taxed' => 1]);
    }

    $service->applyInclusiveTax($invoice);

    $net = (float) $db->fetchOne('SELECT SUM(price) FROM invoice_item');
    expect(round($net + Service::taxOf($net, $rate, (float) $invoice->tax_adjust), 2))->toBe(round(array_sum($prices), 2))
        ->and((float) $invoice->tax_adjust)->toBe($adjust)
        ->and((float) $db->fetchOne('SELECT tax_adjust FROM invoice WHERE id = 1'))->toBe($adjust);
})->with([
    '15.00 at 19% (no 2-decimal net)' => [19.0, [15.0], -0.01],
    '9.99 at 20% (no 2-decimal net)' => [20.0, [9.99], 0.01],
    'three 20.00 lines at 20%' => [20.0, [20.0, 20.0, 20.0], -0.01],
    '12.00 at 20% (exact)' => [20.0, [12.0], 0.0],
]);

test('a full refund copies the cents negated and gives back exactly what was paid', function (): void {
    expect(round(-12.61 + Service::taxOf(-12.61, 19.0, 0.01), 2))->toBe(-15.0)
        ->and(Service::taxOf(12.61, 19.0, -0.01))->toBe(2.39)
        ->and(Service::taxOf(0.0, 19.0, 0.01))->toBe(0.0)
        ->and(Service::taxOf(10.0, 0.0, 0.01))->toBe(0.0);
});

test('a price staff type on a taxed line includes the tax and the total is the typed price (CF-137)', function (float $rate, array $typed, array $nets, float $adjust): void {
    [$service, $db, $invoice] = inclusiveTaxInvoice(true, true, $rate, $rate);
    $db->executeStatement('DELETE FROM invoice_item');
    $netOf = new ReflectionMethod($service, 'staffLineNet');
    $typedGross = [];
    foreach ($typed as $price) {
        $line = $netOf->invoke($service, ['price' => $price, 'taxed' => 1], $rate);
        $db->insert('invoice_item', ['invoice_id' => 1, 'type' => 'custom', 'price' => $line['price'], 'taxed' => 1]);
        $typedGross[(int) $db->lastInsertId()] = $price;
    }

    $cents = (new ReflectionMethod($service, 'inclusiveCents'))->invoke($service, $invoice, $typedGross);

    $net = round((float) $db->fetchOne('SELECT SUM(price) FROM invoice_item'), 2);
    expect(array_map('floatval', $db->fetchFirstColumn('SELECT price FROM invoice_item ORDER BY id')))->toBe($nets)
        ->and($cents)->toBe($adjust)
        ->and(round($net + Service::taxOf($net, $rate, $cents), 2))->toBe(round(array_sum($typed), 2))
        ->and($netOf->invoke($service, ['price' => '120', 'taxed' => 0], $rate)['price'])->toBe('120');
})->with([
    '120.00 at 20%' => [20.0, ['120'], [100.0], 0.0],
    '9.99 at 20% (no 2-decimal net)' => [20.0, ['9.99'], [8.33], -0.01],
    '15.00 at 19% twice' => [19.0, ['15', '15'], [12.61, 12.61], -0.01],
]);

test('a taxed line saved as the form shows it keeps its net price (CF-137)', function (float $rate): void {
    [$service] = inclusiveTaxInvoice(true, true, $rate, $rate);
    $netOf = new ReflectionMethod($service, 'staffLineNet');
    for ($cents = 1; $cents <= 50000; ++$cents) {
        $net = round($cents / 100, 2);
        $shown = number_format(round($net * (100 + $rate) / 100, 2), 2, '.', '');
        if ($netOf->invoke($service, ['price' => $shown, 'taxed' => 1], $rate)['price'] !== $net) {
            throw new RuntimeException("net {$net} at {$rate}% comes back changed");
        }
    }
    expect(true)->toBeTrue();
})->with([5.0, 7.5, 19.0, 20.0, 21.0, 25.0]);
