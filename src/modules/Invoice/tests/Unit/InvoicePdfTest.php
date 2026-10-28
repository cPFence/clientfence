<?php

declare(strict_types=1);

use ClientFence\Mod\Invoice\Controller\Admin;
use ClientFence\Mod\Invoice\InvoicePdf;
use Symfony\Component\HttpFoundation\Request;

function invoicePdfFixture(): array
{
    $di = new Pimple\Container();
    $system = Mockery::mock(ClientFence\Mod\System\Service::class);
    $system->shouldReceive('getCompany')->andReturn(['name' => 'Example Hosting']);
    $system->shouldReceive('getParamValue')->with('invoice_document_format', 'A4')->andReturn('A4');
    $currency = Mockery::mock(ClientFence\Mod\Currency\Service::class);
    $currency->shouldReceive('formatCurrency')->andReturnUsing(static fn (mixed $amount, string $code): string => number_format((float) $amount, 2) . ' ' . $code);
    $theme = Mockery::mock(ClientFence\Mod\Theme\Service::class);
    $theme->shouldReceive('getDefaultMarkdownAttributes')->andReturn([]);
    $di['mod_service'] = $di->protect(static fn (string $module): object => match ($module) {
        'system' => $system,
        'currency' => $currency,
        'theme' => $theme,
    });

    return [$di, [
        'currency_code' => 'USD',
        'logo_source' => '',
        'seller' => ['Name' => 'Example Hosting', 'Address 1' => '1 Example Street'],
        'buyer' => ['Company' => 'شركة قمة للمقاولات', 'Name' => 'عميل לקוח', 'Address' => 'الرياض'],
        'invoice' => [
            'serie_nr' => 'CF-00001',
            'created_at' => '2026-10-01 12:00:00',
            'due_at' => '2026-10-15 12:00:00',
            'status' => 'unpaid',
            'text_1' => '**خدمات الاستضافة**',
            'text_2' => 'شكراً لكم',
            'lines' => [['title' => 'استضافة אתר CF-12', 'quantity' => 2, 'price' => 10.0, 'total' => 20.0]],
            'taxname' => 'VAT',
            'taxrate' => 15,
            'tax' => 3.0,
            'total' => 23.0,
        ],
        'footer' => [
            'company_name' => 'Example Hosting',
            'display_bank_info' => 1,
            'bank_name' => 'Example Bank',
            'account_number' => '12345678',
            'bic' => 'EXAMPLE',
            'address_1' => '1 Example Street',
            'address_2' => null,
            'address_3' => null,
            'email' => 'billing@example.com',
            'phone' => '+20 100 000 0000',
            'company_vat' => 'VAT-123',
            'company_number' => 'REG-123',
            'www' => 'https://example.com',
            'signature' => 'شكراً لكم',
        ],
    ]];
}

test('invoice PDFs render Arabic buyers in the chosen language and restore the request locale', function (string $locale, bool $rtl): void {
    $previous = Locale::getDefault();
    (new ClientFence\Translate())->setLocale('de_DE')->setup();
    try {
        [$di, $data] = invoicePdfFixture();
        $pdf = InvoicePdf::render($di, $data, $locale);

        expect($pdf)->toStartWith('%PDF-')
            ->toContain($rtl ? '/Direction /R2L' : '/Direction /L2R')
            ->and(Locale::getDefault())->toBe('de_DE');
    } finally {
        (new ClientFence\Translate())->setLocale($previous)->setup();
    }
})->with([
    'Arabic' => ['ar_SA', true],
    'English' => ['en_US', false],
]);

test('invoice PDF failures restore the request locale', function (): void {
    $previous = Locale::getDefault();
    (new ClientFence\Translate())->setLocale('de_DE')->setup();
    try {
        [$di, $data] = invoicePdfFixture();
        $di['mod_service'] = $di->protect(static function (): never {
            throw new RuntimeException('PDF rendering failed');
        });

        expect(fn () => InvoicePdf::render($di, $data, 'ar_SA'))->toThrow(RuntimeException::class, 'PDF rendering failed');
        expect(Locale::getDefault())->toBe('de_DE');
    } finally {
        (new ClientFence\Translate())->setLocale($previous)->setup();
    }
});

test('admin invoice PDFs reject a language that is not installed', function (): void {
    $app = Mockery::mock(ClientFence\App::class);
    $app->shouldReceive('getRequest')->once()->andReturn(Request::create('/invoice/pdf/' . str_repeat('a', 32), 'GET', ['lang' => 'zz_ZZ']));
    $controller = new Admin();
    $controller->setDi(new Pimple\Container(['is_admin_logged' => true]));

    expect(fn () => $controller->get_pdf($app, str_repeat('a', 32)))
        ->toThrow(ClientFence\InformationException::class, 'Language not found');
});
