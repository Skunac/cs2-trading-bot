<?php

namespace App\Repository;

use App\Entity\Inventory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Inventory>
 */
class InventoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Inventory::class);
    }

    /**
     * Get all holdings (not yet sold)
     */
    public function findHoldings(): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.status IN (:statuses)')
            ->setParameter('statuses', ['holding', 'listed'])
            ->orderBy('i.purchaseDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get items ready to list (still holding)
     */
    public function findReadyToList(): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.status = :status')
            ->setParameter('status', 'holding')
            ->orderBy('i.purchaseDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get currently listed items
     */
    public function findListed(): array
    {
        return $this->findBy(['status' => 'listed'], ['listedDate' => 'DESC']);
    }

    /**
     * Count holdings of specific item
     */
    public function countHoldingsForItem(string $marketHashName): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.marketHashName = :name')
            ->andWhere('i.status IN (:statuses)')
            ->setParameter('name', $marketHashName)
            ->setParameter('statuses', ['holding', 'listed'])
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get items held too long
     */
    public function findHeldTooLong(int $days = 7): array
    {
        $cutoffDate = new \DateTimeImmutable("-{$days} days");
        
        return $this->createQueryBuilder('i')
            ->where('i.status = :status')
            ->andWhere('i.purchaseDate <= :cutoff')
            ->setParameter('status', 'holding')
            ->setParameter('cutoff', $cutoffDate)
            ->orderBy('i.purchaseDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find by sale ID
     */
    public function findBySaleId(string $saleId): ?Inventory
    {
        return $this->findOneBy(['saleId' => $saleId]);
    }

    /**
     * Calculate total invested amount
     */
    public function getTotalInvested(): string
    {
        $result = $this->createQueryBuilder('i')
            ->select('SUM(i.purchasePrice)')
            ->where('i.status IN (:statuses)')
            ->setParameter('statuses', ['holding', 'listed'])
            ->getQuery()
            ->getSingleScalarResult();
        
        return $result ?? '0.00';
    }

    /**
     * Get sold items for date range
     */
    public function findSoldInDateRange(
        \DateTimeInterface $startDate,
        \DateTimeInterface $endDate
    ): array {
        return $this->createQueryBuilder('i')
            ->where('i.status = :status')
            ->andWhere('i.soldDate >= :start')
            ->andWhere('i.soldDate <= :end')
            ->setParameter('status', 'sold')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->orderBy('i.soldDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Calculate total profit for date range
     */
    public function getTotalProfitInDateRange(
        \DateTimeInterface $startDate,
        \DateTimeInterface $endDate
    ): string {
        $result = $this->createQueryBuilder('i')
            ->select('SUM(i.netProfit)')
            ->where('i.status = :status')
            ->andWhere('i.soldDate >= :start')
            ->andWhere('i.soldDate <= :end')
            ->setParameter('status', 'sold')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->getQuery()
            ->getSingleScalarResult();
        
        return $result ?? '0.00';
    }

    /**
     * Get performance stats (win rate, avg profit)
     */
    public function getPerformanceStats(): array
    {
        $qb = $this->createQueryBuilder('i')
            ->select([
                'COUNT(i.id) as totalTrades',
                'SUM(CASE WHEN i.netProfit > 0 THEN 1 ELSE 0 END) as wins',
                'SUM(CASE WHEN i.netProfit <= 0 THEN 1 ELSE 0 END) as losses',
                'AVG(i.netProfit) as avgProfit',
                'AVG(i.profitPct) as avgProfitPct',
                'SUM(i.netProfit) as totalProfit'
            ])
            ->where('i.status = :status')
            ->setParameter('status', 'sold');
        
        $result = $qb->getQuery()->getSingleResult();
        
        $totalTrades = (int) $result['totalTrades'];
        $wins = (int) $result['wins'];
        
        return [
            'total_trades' => $totalTrades,
            'wins' => $wins,
            'losses' => (int) $result['losses'],
            'win_rate' => $totalTrades > 0 ? ($wins / $totalTrades) * 100 : 0,
            'avg_profit' => $result['avgProfit'] ?? '0.00',
            'avg_profit_pct' => $result['avgProfitPct'] ?? '0.00',
            'total_profit' => $result['totalProfit'] ?? '0.00',
        ];
    }
}