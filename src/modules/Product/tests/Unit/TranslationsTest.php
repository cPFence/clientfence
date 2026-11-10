<?php

declare(strict_types=1);

use ClientFence\Mod\Product\Entity\Product;
use ClientFence\Mod\Product\Translations;

use function Tests\Helpers\container;
use function Tests\Helpers\createEntity;

// ClientFence: names and descriptions of products, add-ons and categories per language (Product/Translations.php).
function productTranslationsDi(): Pimple\Container
{
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE product_translation (id INTEGER PRIMARY KEY, kind TEXT, ref_id INTEGER, lang TEXT, title TEXT, description TEXT, created_at TEXT, updated_at TEXT)');
    $db->insert('product_translation', ['kind' => 'product', 'ref_id' => 1, 'lang' => 'de_DE', 'title' => 'Webhosting', 'description' => null]);
    $db->insert('product_translation', ['kind' => 'category', 'ref_id' => 1, 'lang' => 'de_DE', 'title' => 'Pakete', 'description' => 'Alle Pakete']);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di = container();
    $di['em'] = $em;

    return $di;
}

test('a text is the page language\'s when that language is turned on and has it, else the default', function (string $kind, string $field, ?string $locale, ?string $expected): void {
    expect(Translations::text(productTranslationsDi(), $kind, 1, $field, 'Default', $locale))->toBe($expected);
})->with([
    'a German name' => ['product', 'title', 'de_DE', 'Webhosting'],
    'a blank German description' => ['product', 'description', 'de_DE', 'Default'],
    'a German category description' => ['category', 'description', 'de_DE', 'Alle Pakete'],
    'no French texts' => ['product', 'title', 'fr_FR', 'Default'],
    'a language that is not installed' => ['product', 'title', 'xx_XX', 'Default'],
]);

test('without a language the page language is used', function (): void {
    $di = productTranslationsDi();
    $product = createEntity(Product::class, ['id' => 1, 'title' => 'Web Hosting']);

    expect(ClientFence\Translate::with('de_DE', fn (): string => Translations::title($di, $product)))->toBe('Webhosting')
        ->and(ClientFence\Translate::with('fr_FR', fn (): string => Translations::title($di, $product)))->toBe('Web Hosting');
});

test('a client\'s texts are in their language when it is turned on, else in the default one', function (?string $lang, ?string $expected): void {
    expect(Translations::locale($lang))->toBe($expected ?? (string) ClientFence\Config::getProperty('i18n.locale', 'en_US'));
})->with([
    'turned on' => ['fr_FR', 'fr_FR'],
    'not installed' => ['ja_JP', null],
    'none' => [null, null],
]);

test('save keeps the filled texts, drops blank languages and refuses unknown ones; forget drops an item\'s texts', function (): void {
    $di = productTranslationsDi();
    $rows = fn (): array => $di['em']->getConnection()->fetchAllAssociative("SELECT lang, title, description FROM product_translation WHERE kind = 'product' AND ref_id = 1 ORDER BY lang");

    Translations::save($di, 'product', 1, [
        'fr_FR' => ['title' => ' Hébergement ', 'description' => ''],
        'de_DE' => ['title' => '', 'description' => ' '],
        'ar_SA' => ['title' => '', 'description' => 'وصف'],
    ]);
    expect($rows())->toBe([
        ['lang' => 'ar_SA', 'title' => null, 'description' => 'وصف'],
        ['lang' => 'fr_FR', 'title' => 'Hébergement', 'description' => null],
    ])
        ->and(fn () => Translations::save($di, 'product', 1, ['xx_XX' => ['title' => 'X']]))->toThrow(ClientFence\InformationException::class, 'Invalid locale code')
        ->and($rows())->toHaveCount(2);

    Translations::forget($di, 'product', 1);
    expect($rows())->toBe([])
        ->and(Translations::forItem($di, 'category', 1))->toBe(['de_DE' => ['title' => 'Pakete', 'description' => 'Alle Pakete']]);
});
