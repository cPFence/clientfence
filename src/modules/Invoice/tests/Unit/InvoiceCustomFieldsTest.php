<?php

declare(strict_types=1);

/**
 * ClientFence: the custom client fields with Show on Invoice (Client/CustomFields.php) are copied onto a new
 * invoice in the client's language, so a later profile change leaves it as it was, and the invoice PDF's buyer
 * block prints them and the company number (InvoicePdfTest: escaped).
 */

use ClientFence\Mod\Invoice\Service;

use function Tests\Helpers\container;

/** setInvoiceDefaults() for a client with $custom answers, under the client settings $config. */
function invoiceWithCustomFields(array $config, array $custom): Model_Invoice
{
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->lang = 'es_ES';
    foreach ($custom as $name => $value) {
        $client->{$name} = $value;
    }

    $clientService = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $clientService->shouldReceive('toApiArray')->andReturn([]);
    $system = Mockery::mock(ClientFence\Mod\System\Service::class);
    $system->shouldReceive('getCompany')->andReturn([]);
    $system->shouldReceive('getParamValue')->andReturn(1);
    $system->shouldReceive('reserveNextNumericParamValue')->andReturn(1);
    $system->shouldReceive('setParamValue')->zeroOrMoreTimes();
    $tax = Mockery::mock(ClientFence\Mod\Invoice\ServiceTax::class);
    $tax->shouldReceive('getTaxRateForClient');
    $db = Mockery::mock('ClientFence\Database');
    $db->shouldReceive('load')->andReturn($client);
    $db->shouldReceive('store');

    $di = container();
    $di['db'] = $db;
    $di['mod_config'] = $di->protect(fn (string $name): array => $name === 'client' ? $config : []);
    $di['mod_service'] = $di->protect(fn (string $name, string $sub = '') => match (true) {
        $sub === 'Tax' => $tax,
        $name === 'Client' => $clientService,
        default => $system,
    });
    $service = new Service();
    $service->setDi($di);
    $service->setInvoiceDefaults($invoice);

    return $invoice;
}

test('a new invoice copies the Show on Invoice answers in the client\'s language', function (): void {
    $config = ['custom_fields' => [
        'custom_1' => ['title' => 'IVA status', 'active' => '1', 'type' => 'dropdown', 'options' => 'Exento,Monotributista', 'show_invoice' => '1',
            'translations' => ['es_ES' => ['title' => 'Condición IVA', 'options' => 'Exenta,Monotributo']]],
        'custom_2' => ['title' => 'Internal ref', 'active' => '1', 'show_invoice' => '0'],
    ]];

    $invoice = invoiceWithCustomFields($config, ['custom_1' => 'Exento', 'custom_2' => 'R-9']);

    expect(ClientFence\Mod\Client\CustomFields::fromInvoice($invoice->buyer_custom))->toBe([['title' => 'Condición IVA', 'value' => 'Exenta']]);
});

test('an install without Show on Invoice fields writes no copy (the column may not exist before Finalize update)', function (): void {
    $invoice = invoiceWithCustomFields(['custom_fields' => ['custom_1' => ['title' => 'Ref', 'active' => '1']]], ['custom_1' => 'R-9']);

    expect($invoice->buyer_custom)->toBeNull();
});

test('the PDF buyer block prints the company number and the copied fields, one line each', function (): void {
    $lines = 0;
    $buyer = (new ReflectionMethod(Service::class, 'getBuyerData'))->invokeArgs(new Service(), [[
        'buyer' => ['company' => 'Acme SA', 'company_number' => '30-71234567-1', 'first_name' => 'Ana', 'last_name' => 'Paz',
            'custom' => [['title' => 'Condición IVA', 'value' => 'Exenta']]],
    ], &$lines, 'en_US']);

    expect($buyer)->toBe(['Company' => 'Acme SA', 'Company Number' => '30-71234567-1', 'Name' => 'Ana Paz', 'custom:0' => 'Condición IVA: Exenta'])
        ->and($lines)->toBe(4);
});
