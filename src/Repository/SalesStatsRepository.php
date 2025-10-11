<?php

namespace App\Repository;

use App\Entity\SalesStats;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SalesStats>
 */
class SalesStatsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SalesStats::class);
    }

    /**
     * Find stats by market hash name
     */
    public function findByMarketHashName(string $marketHashName): ?SalesStats
    {
        return $this->findOneBy(['marketHashName' => $marketHashName]);
    }

    /**
     * Get stats for multiple items
     */
    public function findByMarketHashNames(array $marketHashNames): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.marketHashName IN (:names)')
            ->setParameter('names', $marketHashNames)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find items with high volatility (risky)
     */
    public function findHighVolatility(string $threshold = '2.0'): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.priceVolatility >= :threshold')
            ->setParameter('threshold', $threshold)
            ->orderBy('s.priceVolatility', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find stale stats (not updated recently)
     */
    public function findStale(\DateTimeInterface $olderThan): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.updatedAt < :date')
            ->setParameter('date', $olderThan)
            ->getQuery()
            ->getResult();
    }
}