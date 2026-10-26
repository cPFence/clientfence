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
