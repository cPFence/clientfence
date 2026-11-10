<?php

declare(strict_types=1);

use ClientFence\Mod\Formbuilder\Api\Guest;
use ClientFence\Mod\Formbuilder\Service;

use function Tests\Helpers\container;

// ClientFence: order form questions per language (Product/Translations.php, kind `field`).
test('clients read the questions in the page language; staff pages and blank texts keep the default', function (): void {
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE product_translation (id INTEGER PRIMARY KEY, kind TEXT, ref_id INTEGER, lang TEXT, title TEXT, description TEXT, created_at TEXT, updated_at TEXT)');
    $db->insert('product_translation', ['kind' => 'field', 'ref_id' => 7, 'lang' => 'de_DE', 'title' => 'Paketgröße', 'description' => null]);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di = container();
    $di['em'] = $em;
    $service = Mockery::mock(Service::class);
    $service->shouldReceive('getForm')->with(3)->andReturn(['id' => 3, 'fields' => [
        ['id' => 7, 'name' => 'size', 'label' => 'Plan size', 'description' => 'Pick one'],
        ['id' => 8, 'name' => 'notes', 'label' => 'Notes', 'description' => null],
    ]]);
    $api = apiEndpoint(new Guest());
    $api->setService($service);
    $api->setDi($di);
    $texts = fn (array $form): array => array_map(fn (array $f): array => [$f['label'], $f['description']], $form['fields']);

    expect($texts(ClientFence\Translate::with('de_DE', fn (): array => $api->get(['id' => 3]))))->toBe([['Paketgröße', 'Pick one'], ['Notes', null]])
        ->and($texts(ClientFence\Translate::with('de_DE', fn (): array => $api->get(['id' => 3, 'default_texts' => 1]))))->toBe([['Plan size', 'Pick one'], ['Notes', null]]);
});
