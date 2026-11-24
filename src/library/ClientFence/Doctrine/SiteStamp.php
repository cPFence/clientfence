<?php

declare(strict_types=1);

namespace ClientFence\Doctrine;

use ClientFence\Mod\Extension\Entity\Extension;
use ClientFence\Site;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\Persistence\Event\LifecycleEventArgs;

/**
 * ClientFence: a client, order, ticket or queued email made through Doctrine keeps the site it was made on
 * (Site::stamp()), written once the flush that made it is done. An extension activated, deactivated, removed or
 * upgraded (all saved through Doctrine) makes the site modules' list be read again (Site::forgetProviders()).
 */
final class SiteStamp
{
    /** @var list<array{string, int}> table and id of each row the running flush made */
    private array $made = [];

    private bool $extensions = false;

    public function __construct(private readonly \Pimple\Container $di)
    {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        $metadata = $args->getObjectManager()->getClassMetadata($entity::class);
        $this->made[] = [$metadata->getTableName(), (int) ($metadata->getIdentifierValues($entity)['id'] ?? 0)];
        $this->postUpdate($args);
    }

    public function postUpdate(LifecycleEventArgs $args): void
    {
        $this->extensions = $this->extensions || $args->getObject() instanceof Extension;
    }

    public function postRemove(LifecycleEventArgs $args): void
    {
        $this->postUpdate($args);
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        [$made, $this->made, $extensions, $this->extensions] = [$this->made, [], $this->extensions, false];
        if ($extensions) {
            Site::forgetProviders($this->di);
        }
        foreach ($made as [$table, $id]) {
            Site::stamp($this->di, $table, $id);
        }
    }
}
