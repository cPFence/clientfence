<?php

declare(strict_types=1);

// An invoice number such as FOSS00123 stays "#FOSS00123" in a right-to-left (Arabic) email summary.
test('an invoice email shows the invoice number left to right in its summary', function (string $code): void {
    expect(file_get_contents(PATH_MODS . "/Invoice/templates/email/{$code}.html.twig"))
        ->toContain('<span dir="ltr">#{{ invoice.serie_nr|default(\'\') }}</span>');
})->with(['mod_invoice_card_failed', 'mod_invoice_created', 'mod_invoice_due_after', 'mod_invoice_paid', 'mod_invoice_payment_reminder']);
