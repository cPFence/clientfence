<?php

declare(strict_types=1);

use ClientFence\Mod\Invoice\Service;

// GitHub #53: the seller block of the invoice.
test('the PDF seller block prints the company number', function (): void {
    $lines = 0;
    $seller = (new ReflectionMethod(Service::class, 'getSellerData'))->invokeArgs(new Service(), [[
        'seller' => ['company' => 'Example Hosting', 'company_vat' => 'GB123', 'company_number' => '09876543'],
    ], &$lines]);

    expect($seller)->toBe(['Name' => 'Example Hosting', 'VAT Number' => 'GB123', 'Company Number' => '09876543'])
        ->and($lines)->toBe(3);
});

test('the PDF footer prints the VAT and company numbers the invoice stored', function (): void {
    $footer = (new ReflectionMethod(Service::class, 'getFooterInfo'))->invoke(new Service(),
        ['vat_number' => 'GB999', 'number' => '12345678'], ['company_vat' => 'GB123', 'company_number' => '']);

    expect($footer['company_vat'])->toBe('GB123')
        ->and($footer['company_number'])->toBe('12345678');
});
