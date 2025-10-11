<?php

namespace App\Repository;

use App\Entity\WhitelistedItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WhitelistedItem>
 */
class WhitelistedItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WhitelistedItem::class);
    }

    /**
     * Get all active items ready for trading
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('w')
            ->where('w.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('w.tier', 'ASC')
            ->addOrderBy('w.marketHashName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get active items by tier
     */
    public function findActiveByTier(int $tier): array
    {
        return $this->createQueryBuilder('w')
            ->where('w.isActive = :active')
            ->andWhere('w.tier = :tier')
            ->setParameter('active', true)
            ->setParameter('tier', $tier)
            ->orderBy('w.marketHashName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find item by market hash name
     */
    public function findByMarketHashName(string $marketHashName): ?WhitelistedItem
    {
        return $this->findOneBy(['marketHashName' => $marketHashName]);
    }

    /**
     * Check if item is whitelisted and active
     */
    public function isWhitelistedAndActive(string $marketHashName): bool
    {
        return $this->count([
            'marketHashName' => $marketHashName,
            'isActive' => true
        ]) > 0;
    }
}