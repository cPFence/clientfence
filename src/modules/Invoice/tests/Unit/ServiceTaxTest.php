<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use ClientFence\Mod\Client\Service as ClientService;
use ClientFence\Mod\Invoice\ServiceInvoiceItem;
use ClientFence\Mod\Invoice\ServiceTax;

use function Tests\Helpers\container;

test('gets dependency injection container', function (): void {
    $service = new ServiceTax();
    $di = container();
    $service->setDi($di);
    $getDi = $service->getDi();
    expect($getDi)->toBe($di);
});

test('gets tax rate for client by country and state', function (): void {
    $service = new ServiceTax();
    $taxRateExpected = 0.21;
    $clientModel = new Model_Client();
    $clientModel->loadBean(new Tests\Helpers\DummyBean());

    $clientServiceMock = Mockery::mock(ClientService::class);
    $clientServiceMock->shouldReceive('isClientTaxable')
        ->atLeast()->once()
        ->andReturn(true);

    $taxModel = new Model_Tax();
    $taxModel->loadBean(new Tests\Helpers\DummyBean());
    $taxModel->taxrate = $taxRateExpected;

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('findOne')
        ->atLeast()->once()
        ->andReturn($taxModel);

    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $clientServiceMock);
    $di['db'] = $dbMock;
    $service->setDi($di);

    $result = $service->getTaxRateForClient($clientModel);
    expect($result)->toBeFloat();
    expect($result)->toBe($taxRateExpected);
});

test('gets tax rate for client by country', function (): void {
    $service = new ServiceTax();
    $taxRateExpected = 0.21;
    $clientModel = new Model_Client();
    $clientModel->loadBean(new Tests\Helpers\DummyBean());

    $clientServiceMock = Mockery::mock(ClientService::class);
    $clientServiceMock->shouldReceive('isClientTaxable')
        ->atLeast()->once()
        ->andReturn(true);

    $taxModel = new Model_Tax();
    $taxModel->loadBean(new Tests\Helpers\DummyBean());
    $taxModel->taxrate = $taxRateExpected;

    $dbMock = Mockery::mock('ClientFence\Database');
    $callCount = 0;
    $dbMock->shouldReceive('findOne')
        ->atLeast()->once()
        ->andReturnUsing(function () use (&$callCount, $taxModel) {
            ++$callCount;
            if ($callCount == 1) {
                return null;
            }

            return $taxModel;
        });

    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $clientServiceMock);
    $di['db'] = $dbMock;
    $service->setDi($di);

    $result = $service->getTaxRateForClient($clientModel);
    expect($result)->toBeFloat();
    expect($result)->toBe($taxRateExpected);
});

test('gets tax rate for client', function (): void {
    $service = new ServiceTax();
    $taxRateExpected = 0.21;
    $clientModel = new Model_Client();
    $clientModel->loadBean(new Tests\Helpers\DummyBean());

    $clientServiceMock = Mockery::mock(ClientService::class);
    $clientServiceMock->shouldReceive('isClientTaxable')
        ->atLeast()->once()
        ->andReturn(true);

    $taxModel = new Model_Tax();
    $taxModel->loadBean(new Tests\Helpers\DummyBean());
    $taxModel->taxrate = $taxRateExpected;

    $dbMock = Mockery::mock('ClientFence\Database');
    $callCount = 0;
    $dbMock->shouldReceive('findOne')
        ->atLeast()->once()
        ->andReturnUsing(function () use (&$callCount, $taxModel) {
            ++$callCount;
            if ($callCount <= 2) {
                return null;
            }

            return $taxModel;
        });

    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $clientServiceMock);
    $di['db'] = $dbMock;
    $service->setDi($di);

    $result = $service->getTaxRateForClient($clientModel);
    expect($result)->toBeFloat();
    expect($result)->toBe($taxRateExpected);
});

test('returns zero tax rate when tax not found', function (): void {
    $service = new ServiceTax();
    $clientModel = new Model_Client();
    $clientModel->loadBean(new Tests\Helpers\DummyBean());

    $clientServiceMock = Mockery::mock(ClientService::class);
    $clientServiceMock->shouldReceive('isClientTaxable')
        ->atLeast()->once()
        ->andReturn(true);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('findOne')
        ->atLeast()->once()
        ->andReturn(null);

    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $clientServiceMock);
    $di['db'] = $dbMock;
    $service->setDi($di);

    $taxRateExpected = 0;
    $result = $service->getTaxRateForClient($clientModel);
    expect($result)->toBeInt();
    expect($result)->toBe($taxRateExpected);
});

test('returns zero tax rate when client is not taxable', function (): void {
    $service = new ServiceTax();
    $clientModel = new Model_Client();
    $clientModel->loadBean(new Tests\Helpers\DummyBean());

    $clientServiceMock = Mockery::mock(ClientService::class);
    $clientServiceMock->shouldReceive('isClientTaxable')
        ->atLeast()->once()
        ->andReturn(false);

    $taxModel = new Model_Tax();
    $taxModel->loadBean(new Tests\Helpers\DummyBean());

    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $clientServiceMock);
    $service->setDi($di);

    $taxRateExpected = 0;
    $result = $service->getTaxRateForClient($clientModel);
    expect($result)->toBeInt();
    expect($result)->toBe($taxRateExpected);
});

test('returns zero tax when tax rate is zero', function (): void {
    $service = new ServiceTax();
    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceModel->taxrate = 0;

    $result = $service->getTax($invoiceModel);
    expect($result)->toBeInt();
    expect($result)->toBe(0);
});

test('gets tax', function (): void {
    $service = new ServiceTax();
    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceModel->taxrate = 15;

    $invoiceItemModel = new Model_InvoiceItem();
    $invoiceItemModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceItemModel->quantity = 1;

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('find')
        ->atLeast()->once()
        ->andReturn([$invoiceItemModel]);

    $invoiceItemService = Mockery::mock(ServiceInvoiceItem::class);
    $invoiceItemService->shouldReceive('getTax')
        ->atLeast()->once()
        ->andReturn(21);

    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $invoiceItemService);
    $di['db'] = $dbMock;

    $service->setDi($di);
    $result = $service->getTax($invoiceModel);
    expect($result)->toBeInt();
});

test('deletes a tax', function (): void {
    $service = new ServiceTax();
    $taxModel = new Model_Tax();
    $taxModel->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('trash')
        ->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $service->setDi($di);

    $result = $service->delete($taxModel);
    expect($result)->toBeTrue();
});

test('creates a tax', function (): void {
    $service = new ServiceTax();

    $taxModel = new Model_Tax();
    $taxModel->loadBean(new Tests\Helpers\DummyBean());
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('dispense')
        ->atLeast()->once()
        ->andReturn($taxModel);
    $newId = 2;
    $dbMock->shouldReceive('store')
        ->atLeast()->once()
        ->andReturn($newId);

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $service->setDi($di);

    $data = [
        'name' => 'tax',
        'taxrate' => '0.18',
    ];
    $result = $service->create($data);
    expect($result)->toBeInt()->toBe($newId);
});

test('updates a tax', function (): void {
    $service = new ServiceTax();
    $taxModel = new Model_Tax();
    $taxModel->loadBean(new Tests\Helpers\DummyBean());
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')
        ->atLeast()->once()
        ->andReturn(2);

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $service->setDi($di);

    $data = [
        'name' => 'tax',
        'taxrate' => '0.18',
    ];
    $result = $service->update($taxModel, $data);
    expect($result)->toBeBool()->toBeTrue();
});

test('gets search query', function (): void {
    $service = new ServiceTax();
    $result = $service->getSearchQuery([]);
    expect($result[0])->toBeString();
    expect($result[1])->toBeArray();
    expect($result[1])->toBe([]);
});

test('converts to api array', function (): void {
    $service = new ServiceTax();
    $taxModel = new Model_Tax();
    $taxModel->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('toArray')
        ->with($taxModel)
        ->atLeast()->once()
        ->andReturn([]);

    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    $result = $service->toApiArray($taxModel);
    expect($result)->toBeArray();
});

test('a state rule is not charged in another state of the same country', function (): void {
    $service = new ClientFence\Mod\Invoice\ServiceTax();
    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->country = 'DE';
    $client->state = 'Berlin';

    $clientServiceMock = Mockery::mock(ClientService::class);
    $clientServiceMock->shouldReceive('isClientTaxable')->andReturn(true);

    $conditions = [];
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('findOne')->andReturnUsing(function (string $type, string $where) use (&$conditions) {
        $conditions[] = $where;

        return null;
    });

    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $clientServiceMock);
    $di['db'] = $dbMock;
    $service->setDi($di);

    expect($service->getTaxRateForClient($client))->toBe(0)
        ->and($conditions[1])->toContain('state is NULL or state = ""');
});

test('a tax rate is a number from 0 to 100, with a decimal comma read as a point', function (): void {
    expect(ServiceTax::validRate('14,5'))->toBe('14.5')
        ->and(ServiceTax::validRate(' 19 '))->toBe('19')
        ->and(ServiceTax::validRate('0'))->toBe('0');
    foreach (['abc', '-5', '150', ''] as $bad) {
        expect(fn () => ServiceTax::validRate($bad))->toThrow(ClientFence\InformationException::class);
    }
});

// Forum #172: an invoice's tax name is shown in its reader's language when its rule has one; the invoice keeps the saved name.
test('an invoice tax name shows as its rule\'s own name in the language, else as saved', function (string $name, float $rate, string $country, string $locale, string $shown): void {
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE product_translation (id INTEGER PRIMARY KEY, kind TEXT, ref_id INTEGER, lang TEXT, title TEXT, description TEXT, created_at TEXT, updated_at TEXT)');
    $db->insert('product_translation', ['kind' => 'tax', 'ref_id' => 3, 'lang' => 'de_DE', 'title' => 'MwSt.']);
    $db->insert('product_translation', ['kind' => 'tax', 'ref_id' => 5, 'lang' => 'de_DE', 'title' => 'USt. AT']);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $rules = [];
    foreach ([[3, 'VAT', 19, 'DE'], [4, 'Sales Tax', 19, ''], [5, 'VAT', 19, 'AT']] as [$id, $saved, $taxrate, $at]) {
        $tax = new Model_Tax();
        $tax->loadBean(new Tests\Helpers\DummyBean());
        [$tax->id, $tax->name, $tax->taxrate, $tax->country, $tax->state] = [$id, $saved, $taxrate, $at, ''];
        $rules[] = $tax;
    }
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('find')->with('Tax')->andReturn($rules);
    $di = container();
    $di['db'] = $dbMock;
    $di['em'] = $em;
    $service = new ClientFence\Mod\Invoice\ServiceTax();
    $service->setDi($di);
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    [$invoice->taxname, $invoice->taxrate, $invoice->buyer_country, $invoice->buyer_state] = [$name, $rate, $country, ''];

    expect($service->displayName($invoice, $locale))->toBe($shown);
})->with([
    'its German name' => ['VAT', 19.0, 'DE', 'de_DE', 'MwSt.'],
    'the rule of the buyer\'s country among rules of one name' => ['VAT', 19.0, 'AT', 'de_DE', 'USt. AT'],
    'only other countries\' rules of that name: the saved name' => ['VAT', 19.0, 'FR', 'de_DE', 'VAT'],
    'another language: the saved name' => ['VAT', 19.0, 'DE', 'fr_FR', 'VAT'],
    'a rule of the same rate without a translation' => ['Sales Tax', 19.0, 'DE', 'de_DE', 'Sales Tax'],
    'another rate (the rule changed since): the saved name' => ['VAT', 20.0, 'DE', 'de_DE', 'VAT'],
    'no rule of that name (imported)' => ['Tax', 19.0, 'DE', 'de_DE', 'Tax'],
]);
