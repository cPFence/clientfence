<?php

declare(strict_types=1);

// ClientFence: Translate::with() renders an email in its recipient's language and gives the page its own back.
test('Translate::with switches the language for the callback and restores it, also after an error', function (): void {
    $previous = Locale::getDefault();
    (new ClientFence\Translate())->setLocale('en_US')->setup();
    try {
        expect(ClientFence\Translate::with('de_DE', static fn (): string => _gettext('Invoice Number')))->toBe('Rechnungsnummer')
            ->and(Locale::getDefault())->toBe('en_US')
            ->and(static fn () => ClientFence\Translate::with('de_DE', static fn () => throw new RuntimeException('render failed')))->toThrow(RuntimeException::class, 'render failed')
            ->and(Locale::getDefault())->toBe('en_US')
            ->and(_gettext('Invoice Number'))->toBe('Invoice Number');
    } finally {
        (new ClientFence\Translate())->setLocale($previous ?: 'en_US')->setup();
    }
});
