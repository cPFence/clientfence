<?php

declare(strict_types=1);

use ClientFence\Mod\Invoice\Service;

// GitHub #53: the seller and buyer blocks of the invoice.
test('the PDF seller block prints the company number', function (): void {
    $lines = 0;
    $seller = (new ReflectionMethod(Service::class, 'getSellerData'))->invokeArgs(new Service(), [[
        'seller' => ['company' => 'Example Hosting', 'company_vat' => 'GB123', 'company_number' => '09876543'],
    ], &$lines]);

    expect($seller)->toBe(['Name' => 'Example Hosting', 'VAT Number' => 'GB123', 'Company Number' => '09876543'])
        ->and($lines)->toBe(3);
});

test('the PDF buyer block names the country in the PDF\'s language; an unknown code stays as it is', function (): void {
    $buyer = new ReflectionMethod(Service::class, 'getBuyerData');
    $country = function (string $code, string $locale) use ($buyer): ?string {
        $lines = 0;

        return $buyer->invokeArgs(new Service(), [['buyer' => ['country' => $code]], &$lines, $locale])['Country'] ?? null;
    };

    expect($country('GR', 'en_US'))->toBe('Greece')
        ->and($country('GR', 'de_DE'))->toBe('Griechenland')
        ->and($country('RS', 'sr_RS'))->toBe('Srbija')
        ->and($country('XX', 'en_US'))->toBe('XX')
        ->and($country('', 'en_US'))->toBeNull();
});

test('the PDF footer prints the VAT and company numbers the invoice stored', function (): void {
    $footer = (new ReflectionMethod(Service::class, 'getFooterInfo'))->invoke(new Service(),
        ['vat_number' => 'GB999', 'number' => '12345678'], ['company_vat' => 'GB123', 'company_number' => '']);

    expect($footer['company_vat'])->toBe('GB123')
        ->and($footer['company_number'])->toBe('12345678');
});

test('the seller address keeps its lines; an older space-joined copy that matches the settings gets them too', function (): void {
    $address = new ReflectionMethod(Service::class, 'sellerAddress');
    $company = ['address_1' => '1 Main Street', 'address_2' => '', 'address_3' => ' London '];

    expect($address->invoke(null, $company))->toBe("1 Main Street\nLondon")
        ->and($address->invoke(null, $company, '1 Main Street   London'))->toBe("1 Main Street\nLondon")
        // The WHMCS import left the empty line out.
        ->and($address->invoke(null, $company, '1 Main Street London'))->toBe("1 Main Street\nLondon")
        ->and($address->invoke(null, $company, 'Old Road 5'))->toBe('Old Road 5')
        ->and($address->invoke(null, $company, "Edited 1\nEdited 2"))->toBe("Edited 1\nEdited 2")
        // A copy with line breaks is never an older one: staff may have moved them.
        ->and($address->invoke(null, $company, "1 Main\nStreet London"))->toBe("1 Main\nStreet London");
});
