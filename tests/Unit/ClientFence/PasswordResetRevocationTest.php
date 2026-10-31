<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;

test('password reset redemption revokes siblings and rejects a previously loaded token', function (string $kind): void {
    $identityClass = $kind === 'Client' ? ClientFence\Mod\Client\Entity\Client::class : ClientFence\Mod\Staff\Entity\Admin::class;
    $resetClass = $kind === 'Client' ? ClientFence\Mod\Client\Entity\ClientPasswordReset::class : ClientFence\Mod\Staff\Entity\AdminPasswordReset::class;
    $config = ORMSetup::createAttributeMetadataConfig([PATH_MODS . '/' . $kind . '/Entity'], true);
    $config->setProxyDir(sys_get_temp_dir());
    $config->setProxyNamespace('ClientFence\\Tests\\CredentialProxies');
    $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
    $metadata = $em->getClassMetadata($identityClass);
    if ($kind === 'Client') {
        $metadata->fieldMappings['gender']['columnDefinition'] = null;
        $metadata->fieldMappings['gender']['type'] = 'string';
    }
    (new SchemaTool($em))->createSchema([$metadata, $em->getClassMetadata($resetClass)]);
    $account = (new $identityClass())->setPass('old')->setStatus('active');
    $em->persist($account);
    $em->flush();
    $repository = $em->getRepository($resetClass);
    if ($kind === 'Client') {
        $repository->createRequest($account, 'first', null);
        $repository->createRequest($account, 'second', null);
    } else {
        $repository->replaceReset($account, 'first', null);
        $repository->replaceReset($account, 'second', null);
    }
    expect($repository->findOneByHash('first'))->toBeNull();
    $stale = $repository->findOneByHash('second');
    $sibling = (new $resetClass())->setHash('sibling');
    $kind === 'Client' ? $sibling->setClientId($account->getId()) : $sibling->setAdminId($account->getId());
    $em->persist($sibling);
    $em->flush();
    $repository->changePassword($account, 'new', 'second');
    expect($repository->findOneByHash('sibling'))->toBeNull()
        ->and($em->getConnection()->fetchOne('SELECT pass FROM ' . strtolower($kind === 'Client' ? 'client' : 'admin')))->toBe('new');
    expect(fn () => $repository->changePassword($account, 'replayed', $stale->getHash()))
        ->toThrow(ClientFence\InformationException::class, 'The link has expired');
    expect($em->getConnection()->fetchOne('SELECT pass FROM ' . strtolower($kind === 'Client' ? 'client' : 'admin')))->toBe('new');
})->with(['Client', 'Staff']);

test('changing a sign-in email removes outstanding reset records', function (string $kind, string $editor): void {
    $identityClass = $kind === 'Client' ? ClientFence\Mod\Client\Entity\Client::class : ClientFence\Mod\Staff\Entity\Admin::class;
    $resetClass = $kind === 'Client' ? ClientFence\Mod\Client\Entity\ClientPasswordReset::class : ClientFence\Mod\Staff\Entity\AdminPasswordReset::class;
    $config = ORMSetup::createAttributeMetadataConfig([PATH_MODS . '/' . $kind . '/Entity'], true);
    $config->setProxyDir(sys_get_temp_dir());
    $config->setProxyNamespace('ClientFence\\Tests\\CredentialProxies');
    $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
    $metadata = $em->getClassMetadata($identityClass);
    if ($kind === 'Client') {
        $metadata->fieldMappings['gender']['columnDefinition'] = null;
        $metadata->fieldMappings['gender']['type'] = 'string';
    }
    (new SchemaTool($em))->createSchema([$metadata, $em->getClassMetadata($resetClass)]);
    $account = (new $identityClass())->setEmail('old@example.com')->setPass('password-hash')->setStatus('active');
    $em->persist($account);
    $em->flush();
    $repository = $em->getRepository($resetClass);
    $kind === 'Client' ? $repository->createRequest($account, 'reset-token', null) : $repository->replaceReset($account, 'reset-token', null);

    $di = Tests\Helpers\container();
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['em'] = $em;
    $di['dbal'] = $em->getConnection();
    $clientService = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $clientService->shouldReceive('emailAlreadyRegistered')->andReturn(false);
    $clientService->shouldReceive('revokeEmailConfirmations');
    $di['mod_service'] = $di->protect(Tests\Helpers\moduleService(['client' => $clientService]));
    $data = ['email' => 'new@example.com'];
    if ($editor === 'profile') {
        $service = new ClientFence\Mod\Profile\Service();
        $service->setDi($di);
        $kind === 'Client' ? $service->updateClient($account, $data) : $service->updateAdmin($account, $data);
    } elseif ($kind === 'Client') {
        $api = new ClientFence\Mod\Client\Api\Admin();
        $api->setDi($di);
        $api->update(['id' => $account->getId()] + $data);
    } else {
        $legacy = new Model_Admin();
        $legacy->loadBean(new Tests\Helpers\DummyBean());
        $legacy->id = $account->getId();
        $legacy->email = $account->getEmail();
        $legacy->status = 'active';
        $actor = new Model_Admin();
        $actor->loadBean(new Tests\Helpers\DummyBean());
        $actor->id = 99;
        $di['loggedin_admin'] = $actor;
        $di['db']->shouldReceive('store')->with($legacy)->once()->andReturnUsing(
            fn (Model_Admin $admin): int => $em->getConnection()->update('admin', ['email' => $admin->email], ['id' => $admin->id])
        );
        $service = Mockery::mock(ClientFence\Mod\Staff\Service::class)->makePartial();
        $service->shouldReceive('hasPermission')->andReturn(true);
        $service->shouldReceive('isSuperAdministrator')->andReturn(true);
        $service->setDi($di);
        $service->update($legacy, $data);
    }

    $table = $kind === 'Client' ? 'client' : 'admin';
    expect($em->getConnection()->fetchOne("SELECT email FROM {$table} WHERE id = ?", [$account->getId()]))->toBe('new@example.com')
        ->and((int) $em->getConnection()->fetchOne("SELECT COUNT(*) FROM {$table}_password_reset"))->toBe(0);
})->with([
    'client profile' => ['Client', 'profile'],
    'client administration' => ['Client', 'admin'],
    'staff profile' => ['Staff', 'profile'],
    'staff administration' => ['Staff', 'admin'],
]);
