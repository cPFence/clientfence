<?php

declare(strict_types=1);

/**
 * ClientFence (forum #181): the invoice texts staff enter in the settings reach the client in their language: the default
 * invoice note on a new invoice, and the bank transfer instructions on the invoice page.
 */

use ClientFence\Mod\Invoice\Service;

use function Tests\Helpers\container;

test('a new invoice gets the default note in its client\'s language, else the default one', function (?string $lang, string $expected): void {
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->lang = $lang;

    $settings = ['invoice_default_note' => 'Thank you', 'invoice_default_note_de_de' => 'Vielen Dank', 'invoice_default_note_fr_fr' => ''];
    $system = Mockery::mock(ClientFence\Mod\System\Service::class);
    $system->shouldReceive('getCompany')->andReturn([]);
    $system->shouldReceive('getParamValue')->andReturnUsing(fn (string $param) => $settings[$param] ?? 1);
    $system->shouldReceive('reserveNextNumericParamValue')->andReturn(1);
    $system->shouldReceive('setParamValue')->zeroOrMoreTimes();
    $clientService = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $clientService->shouldReceive('toApiArray')->andReturn([]);
    $tax = Mockery::mock(ClientFence\Mod\Invoice\ServiceTax::class);
    $tax->shouldReceive('getTaxRateForClient');
    $db = Mockery::mock('ClientFence\Database');
    $db->shouldReceive('load')->andReturn($client);
    $db->shouldReceive('store');

    $di = container();
    $di['db'] = $db;
    $di['mod_config'] = $di->protect(fn (): array => []);
    $di['mod_service'] = $di->protect(fn (string $name, string $sub = '') => match (true) {
        $sub === 'Tax' => $tax,
        $name === 'Client' => $clientService,
        default => $system,
    });
    $service = new Service();
    $service->setDi($di);
    $service->setInvoiceDefaults($invoice);

    expect($invoice->text_2)->toBe($expected);
})->with([
    'German client' => ['de_DE', 'Vielen Dank'],
    'blank French version' => ['fr_FR', 'Thank you'],
    'a language that is not turned on' => ['ja_JP', 'Thank you'],
    'no language' => [null, 'Thank you'],
]);

test('the bank transfer instructions are in the page language when they have a version', function (string $locale, bool $subscription, string $expected): void {
    $model = new Model_Invoice();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $db = Mockery::mock('ClientFence\Database');
    $db->shouldReceive('load')->andReturn($model);
    $invoices = Mockery::mock();
    $invoices->shouldReceive('toApiArray')->andReturn(['id' => 3]);
    $system = Mockery::mock();
    $system->shouldReceive('renderAdapterTplString')->andReturnUsing(fn (string $tpl): string => $tpl);
    $di = new Pimple\Container();
    $di['db'] = $db;
    $di['mod_service'] = $di->protect(fn (string $name): object => $name === 'Invoice' ? $invoices : $system);
    $adapter = new Payment_Adapter_Custom(['single' => 'Pay to IBAN X', 'single_de_de' => 'Zahlen Sie an IBAN X', 'recurrent' => 'Standing order', 'recurrent_fr_fr' => ' ']);
    $adapter->setDi($di);
    $api = (new ReflectionClass(ClientFence\Api\Proxy::class))->newInstanceWithoutConstructor();

    expect(ClientFence\Translate::with($locale, fn (): string => $adapter->getHtml($api, 3, $subscription)))->toBe($expected);
})->with([
    'German page' => ['de_DE', false, 'Zahlen Sie an IBAN X'],
    'English page' => ['en_US', false, 'Pay to IBAN X'],
    'blank French subscription text' => ['fr_FR', true, 'Standing order'],
]);
