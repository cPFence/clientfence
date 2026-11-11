<?php

declare(strict_types=1);

use ClientFence\Mod\Email\Translations;

use function Tests\Helpers\container;

// ClientFence: an email template's language versions (Email/Translations.php).
function emailTranslationsDi(?ClientFence\Mod\System\Service $system = null): Pimple\Container
{
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE email_template_translation (id INTEGER PRIMARY KEY, email_template_id INTEGER, lang TEXT, subject TEXT, content TEXT, created_at TEXT, updated_at TEXT)');
    $db->insert('email_template_translation', ['email_template_id' => 7, 'lang' => 'de_DE', 'subject' => 'Betreff', 'content' => 'Text']);
    $db->insert('email_template_translation', ['email_template_id' => 8, 'lang' => 'de_DE', 'subject' => 'Andere', 'content' => 'Vorlage']);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di = container();
    $di['em'] = $em;
    $system ??= Mockery::mock(ClientFence\Mod\System\Service::class)->shouldIgnoreMissing();
    $di['mod_service'] = $di->protect(fn (): object => $system);

    return $di;
}

test('save replaces a template\'s versions and forget drops them, other templates untouched', function (): void {
    $di = emailTranslationsDi();

    Translations::save($di, 7, ['fr_FR' => ['subject' => 'Sujet', 'content' => 'Corps'], 'ar_SA' => ['subject' => 'موضوع', 'content' => 'نص']]);
    expect(Translations::forTemplate($di, 7))->toBe([
        'ar_SA' => ['subject' => 'موضوع', 'content' => 'نص'],
        'fr_FR' => ['subject' => 'Sujet', 'content' => 'Corps'],
    ]);

    Translations::forget($di, 7);
    expect(Translations::forTemplate($di, 7))->toBe([])
        ->and(Translations::forTemplate($di, 8))->toBe(['de_DE' => ['subject' => 'Andere', 'content' => 'Vorlage']]);
});

test('save refuses a version it cannot send and keeps the stored ones', function (array $versions, string $error): void {
    $system = Mockery::mock(ClientFence\Mod\System\Service::class);
    $system->shouldReceive('checkEmailTplSyntax')->andReturnUsing(function (string $tpl): void {
        if ($tpl === '{{ broken') {
            throw new ClientFence\InformationException('Email template syntax error: broken');
        }
    });
    $di = emailTranslationsDi($system);

    expect(fn () => Translations::save($di, 7, $versions))->toThrow(ClientFence\InformationException::class, $error)
        ->and(Translations::forTemplate($di, 7))->toBe(['de_DE' => ['subject' => 'Betreff', 'content' => 'Text']]);
})->with([
    'a language that is not installed' => [['xx_XX' => ['subject' => 'S', 'content' => 'C']], 'Invalid locale code'],
    'an empty message' => [['fr_FR' => ['subject' => 'Sujet', 'content' => ' ']], 'Enter a subject and a message for every language version.'],
    'a syntax error' => [['fr_FR' => ['subject' => 'Sujet', 'content' => '{{ broken']], 'Email template syntax error: broken'],
]);

test('a template has no versions before the table exists', function (): void {
    $di = container();
    $di['em'] = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $di['em']->shouldReceive('getConnection')->andReturn(Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));

    expect(Translations::forTemplate($di, 7))->toBe([]);
});

test('localize writes a template out in a language: plain texts as text, texts with values keep their filter', function (): void {
    $previous = Locale::getDefault();
    (new ClientFence\Translate())->setLocale('en_US')->setup();
    try {
        $source = "<p>{{ 'Invoice Number'|trans }}: {{ invoice.nr }}</p>{% set p = {'%id%': quote.id} %}{{ 'Quote #%id% accepted: %subject%'|trans(p) }} {{ 'A & B'|trans }} {{ 'a {b}'|trans }}";

        expect(Translations::localize($source, 'de_DE'))->toBe("<p>Rechnungsnummer: {{ invoice.nr }}</p>{% set p = {'%id%': quote.id} %}{{ 'Angebot #%id% angenommen: %subject%'|trans(p) }} A &amp; B {{ 'a {b}'|trans }}")
            ->and(Locale::getDefault())->toBe('en_US');

        // An inline image: a long text without quotes must not empty the version.
        $logo = '<img src="data:image/png;base64,' . str_repeat('A', 200000) . '">';
        expect(Translations::localize($logo . "{{ 'Invoice Number'|trans }}", 'de_DE'))->toBe($logo . 'Rechnungsnummer');
    } finally {
        (new ClientFence\Translate())->setLocale($previous ?: 'en_US')->setup();
    }
});
