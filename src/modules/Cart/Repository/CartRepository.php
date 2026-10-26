<?php

declare(strict_types=1);

namespace ClientFence\Mod\Cart\Repository;

use ClientFence\Mod\Cart\Entity\Cart;
use Doctrine\ORM\EntityRepository;

class CartRepository extends EntityRepository
{
    public function findBySessionId(string $sessionId): ?Cart
    {
        $cart = $this->findOneBy(['sessionId' => $sessionId]);

        return $cart instanceof Cart ? $cart : null;
    }

    public function getSearchQueryBuilder(array $data): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('cart')
            ->leftJoin(\ClientFence\Mod\Currency\Entity\Currency::class, 'currency', 'WITH', 'currency.id = cart.currencyId')
            ->leftJoin(\ClientFence\Mod\Product\Entity\Promo::class, 'promo', 'WITH', 'promo.id = cart.promoId');
    }
}
