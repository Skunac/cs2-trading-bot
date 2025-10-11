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
     * Find or create a SalesStats record for an item
     * 
     * @param string $marketHashName
     * @return SalesStats
     */
    public function findOrCreate(string $marketHashName): SalesStats
    {
        $stats = $this->findOneBy(['marketHashName' => $marketHashName]);

        if ($stats === null) {
            $stats = new SalesStats();
            $stats->setMarketHashName($marketHashName);
            $this->getEntityManager()->persist($stats);
        }

        return $stats;
    }

    /**
     * Get statistics for a specific item
     * 
     * @param string $marketHashName
     * @return SalesStats|null
     */
    public function findByItem(string $marketHashName): ?SalesStats
    {
        return $this->findOneBy(['marketHashName' => $marketHashName]);
    }

    /**
     * Get all items with liquid markets (2+ sales per day)
     * 
     * @return SalesStats[]
     */
    public function findLiquidItems(): array
    {
        return $this->createQueryBuilder('ss')
            ->where('ss.avgSalesPerDay >= :minSales')
            ->setParameter('minSales', '2.0')
            ->orderBy('ss.avgSalesPerDay', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get all items with highly liquid markets (10+ sales per day)
     * 
     * @return SalesStats[]
     */
    public function findHighlyLiquidItems(): array
    {
        return $this->createQueryBuilder('ss')
            ->where('ss.avgSalesPerDay >= :minSales')
            ->setParameter('minSales', '10.0')
            ->orderBy('ss.avgSalesPerDay', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get items with low volatility (stable prices)
     * 
     * @param string $maxVolatility Maximum standard deviation (e.g., '2.0')
     * @return SalesStats[]
     */
    public function findLowVolatilityItems(string $maxVolatility = '2.0'): array
    {
        return $this->createQueryBuilder('ss')
            ->where('ss.priceVolatility IS NOT NULL')
            ->andWhere('ss.priceVolatility <= :maxVol')
            ->setParameter('maxVol', $maxVolatility)
            ->orderBy('ss.priceVolatility', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get items with high volatility (risky but potential for profit)
     * 
     * @param string $minVolatility Minimum standard deviation (e.g., '3.0')
     * @return SalesStats[]
     */
    public function findHighVolatilityItems(string $minVolatility = '3.0'): array
    {
        return $this->createQueryBuilder('ss')
            ->where('ss.priceVolatility IS NOT NULL')
            ->andWhere('ss.priceVolatility >= :minVol')
            ->setParameter('minVol', $minVolatility)
            ->orderBy('ss.priceVolatility', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get items with sufficient data for reliable trading decisions
     * 
     * @param int $minSales Minimum number of sales in 30 days (default: 10)
     * @return SalesStats[]
     */
    public function findItemsWithReliableData(int $minSales = 10): array
    {
        return $this->createQueryBuilder('ss')
            ->where('ss.salesCount30d >= :minSales')
            ->setParameter('minSales', $minSales)
            ->orderBy('ss.salesCount30d', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get items with insufficient data (for monitoring/removal)
     * 
     * @param int $maxSales Maximum number of sales to be considered insufficient
     * @return SalesStats[]
     */
    public function findItemsWithInsufficientData(int $maxSales = 5): array
    {
        return $this->createQueryBuilder('ss')
            ->where('ss.salesCount30d < :maxSales')
            ->setParameter('maxSales', $maxSales)
            ->orderBy('ss.salesCount30d', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get the most recently updated statistics
     * 
     * @param int $limit Number of records to return
     * @return SalesStats[]
     */
    public function findRecentlyUpdated(int $limit = 50): array
    {
        return $this->createQueryBuilder('ss')
            ->orderBy('ss.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get statistics that haven't been updated in a while (stale data)
     * 
     * @param int $hours Number of hours to consider stale (default: 24)
     * @return SalesStats[]
     */
    public function findStaleStats(int $hours = 24): array
    {
        $cutoffTime = new \DateTimeImmutable("-{$hours} hours");

        return $this->createQueryBuilder('ss')
            ->where('ss.updatedAt < :cutoff')
            ->setParameter('cutoff', $cutoffTime)
            ->orderBy('ss.updatedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get items sorted by best trading opportunity metrics
     * (high liquidity + low volatility + reliable data)
     * 
     * @param int $limit Number of items to return
     * @return SalesStats[]
     */
    public function findBestTradingOpportunities(int $limit = 20): array
    {
        return $this->createQueryBuilder('ss')
            ->where('ss.avgSalesPerDay >= :minSales')
            ->andWhere('ss.priceVolatility IS NOT NULL')
            ->andWhere('ss.priceVolatility <= :maxVol')
            ->andWhere('ss.salesCount30d >= :minCount')
            ->setParameter('minSales', '2.0')  // At least 2 sales/day
            ->setParameter('maxVol', '3.0')     // Volatility below 3
            ->setParameter('minCount', 15)       // At least 15 sales in 30 days
            ->orderBy('ss.avgSalesPerDay', 'DESC')
            ->addOrderBy('ss.priceVolatility', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get items in a specific price range
     * 
     * @param string $minPrice Minimum average price
     * @param string $maxPrice Maximum average price
     * @return SalesStats[]
     */
    public function findByPriceRange(string $minPrice, string $maxPrice): array
    {
        return $this->createQueryBuilder('ss')
            ->where('ss.avgPrice30d IS NOT NULL')
            ->andWhere('ss.avgPrice30d >= :minPrice')
            ->andWhere('ss.avgPrice30d <= :maxPrice')
            ->setParameter('minPrice', $minPrice)
            ->setParameter('maxPrice', $maxPrice)
            ->orderBy('ss.avgPrice30d', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Calculate percentage change between 7-day and 30-day averages
     * Returns items with significant price trends
     * 
     * @param float $minChangePercent Minimum percentage change (e.g., 10.0 for 10%)
     * @param bool $increasing If true, find increasing prices; if false, find decreasing
     * @return array Array of ['item' => SalesStats, 'changePercent' => float]
     */
    public function findItemsWithPriceTrends(float $minChangePercent = 10.0, bool $increasing = true): array
    {
        $items = $this->createQueryBuilder('ss')
            ->where('ss.avgPrice7d IS NOT NULL')
            ->andWhere('ss.avgPrice30d IS NOT NULL')
            ->andWhere('ss.avgPrice30d != :zero')
            ->setParameter('zero', '0.00')
            ->getQuery()
            ->getResult();

        $results = [];

        foreach ($items as $item) {
            $avg7d = (float)$item->getAvgPrice7d();
            $avg30d = (float)$item->getAvgPrice30d();
            
            $changePercent = (($avg7d - $avg30d) / $avg30d) * 100;

            if ($increasing && $changePercent >= $minChangePercent) {
                $results[] = [
                    'item' => $item,
                    'changePercent' => round($changePercent, 2)
                ];
            } elseif (!$increasing && $changePercent <= -$minChangePercent) {
                $results[] = [
                    'item' => $item,
                    'changePercent' => round($changePercent, 2)
                ];
            }
        }

        // Sort by change percentage
        usort($results, function($a, $b) use ($increasing) {
            return $increasing 
                ? $b['changePercent'] <=> $a['changePercent']
                : $a['changePercent'] <=> $b['changePercent'];
        });

        return $results;
    }

    /**
     * Get summary statistics across all items
     * 
     * @return array Summary data
     */
    public function getSummaryStatistics(): array
    {
        $qb = $this->createQueryBuilder('ss');

        return [
            'total_items' => (int) $qb->select('COUNT(ss.id)')->getQuery()->getSingleScalarResult(),
            
            'liquid_items' => (int) $this->createQueryBuilder('ss')
                ->select('COUNT(ss.id)')
                ->where('ss.avgSalesPerDay >= :minSales')
                ->setParameter('minSales', '2.0')
                ->getQuery()
                ->getSingleScalarResult(),
            
            'highly_liquid_items' => (int) $this->createQueryBuilder('ss')
                ->select('COUNT(ss.id)')
                ->where('ss.avgSalesPerDay >= :minSales')
                ->setParameter('minSales', '10.0')
                ->getQuery()
                ->getSingleScalarResult(),
            
            'avg_volatility' => $this->createQueryBuilder('ss')
                ->select('AVG(ss.priceVolatility)')
                ->where('ss.priceVolatility IS NOT NULL')
                ->getQuery()
                ->getSingleScalarResult(),
            
            'items_with_reliable_data' => (int) $this->createQueryBuilder('ss')
                ->select('COUNT(ss.id)')
                ->where('ss.salesCount30d >= :minSales')
                ->setParameter('minSales', 10)
                ->getQuery()
                ->getSingleScalarResult(),
        ];
    }

    /**
     * Delete statistics for items no longer in whitelist
     * 
     * @param array $whitelistedNames Array of market hash names to keep
     * @return int Number of records deleted
     */
    public function deleteNonWhitelistedStats(array $whitelistedNames): int
    {
        if (empty($whitelistedNames)) {
            return 0;
        }

        return $this->createQueryBuilder('ss')
            ->delete()
            ->where('ss.marketHashName NOT IN (:names)')
            ->setParameter('names', $whitelistedNames)
            ->getQuery()
            ->execute();
    }

    /**
     * Bulk update statistics for multiple items
     * Useful for batch processing in StatsCalculatorCommand
     * 
     * @param array $statsData Array of ['marketHashName' => string, 'stats' => array]
     * @return int Number of records updated
     */
    public function bulkUpdateStats(array $statsData): int
    {
        $em = $this->getEntityManager();
        $updated = 0;

        foreach ($statsData as $data) {
            $stats = $this->findOrCreate($data['marketHashName']);
            
            $statsArray = $data['stats'];
            
            if (isset($statsArray['avg7d'])) {
                $stats->setAvgPrice7d($statsArray['avg7d']);
            }
            if (isset($statsArray['avg30d'])) {
                $stats->setAvgPrice30d($statsArray['avg30d']);
            }
            if (isset($statsArray['median30d'])) {
                $stats->setMedianPrice30d($statsArray['median30d']);
            }
            if (isset($statsArray['minMax30d'])) {
                $stats->setMinPrice30d($statsArray['minMax30d']['min']);
                $stats->setMaxPrice30d($statsArray['minMax30d']['max']);
            }
            if (isset($statsArray['volatility'])) {
                $stats->setPriceVolatility($statsArray['volatility']);
            }
            if (isset($statsArray['count7d'])) {
                $stats->setSalesCount7d($statsArray['count7d']);
            }
            if (isset($statsArray['count30d'])) {
                $stats->setSalesCount30d($statsArray['count30d']);
                
                // Calculate avg sales per day
                if ($statsArray['count30d'] > 0) {
                    $avgPerDay = bcdiv((string)$statsArray['count30d'], '30', 2);
                    $stats->setAvgSalesPerDay($avgPerDay);
                }
            }
            if (isset($statsArray['lastSale'])) {
                $lastSale = $statsArray['lastSale'];
                if ($lastSale !== null) {
                    $stats->setLastSalePrice($lastSale->getPrice());
                    $stats->setLastSaleDate($lastSale->getDateSold());
                }
            }
            if (isset($statsArray['dataPoints'])) {
                $stats->setDataPoints($statsArray['dataPoints']);
            }

            $updated++;

            // Flush every 20 records
            if ($updated % 20 === 0) {
                $em->flush();
            }
        }

        // Flush remaining
        if ($updated % 20 !== 0) {
            $em->flush();
        }

        return $updated;
    }

    /**
     * Find items where current market price is significantly below 30-day average
     * (Potential buy opportunities)
     * 
     * @param string $minDiscountPct Minimum discount percentage (e.g., '20.0' for 20%)
     * @return array Array of ['stats' => SalesStats, 'discountPct' => string]
     */
    public function findUndervaluedItems(string $minDiscountPct = '20.0'): array
    {
        // Note: This requires lastSalePrice to be populated
        // In practice, you'd compare against current market price from Search API
        $items = $this->createQueryBuilder('ss')
            ->where('ss.avgPrice30d IS NOT NULL')
            ->andWhere('ss.lastSalePrice IS NOT NULL')
            ->andWhere('ss.avgPrice30d != :zero')
            ->setParameter('zero', '0.00')
            ->getQuery()
            ->getResult();

        $results = [];

        foreach ($items as $item) {
            $avg30d = $item->getAvgPrice30d();
            $lastPrice = $item->getLastSalePrice();
            
            $discountPct = bcdiv(
                bcsub($avg30d, $lastPrice, 2),
                $avg30d,
                4
            );
            $discountPct = bcmul($discountPct, '100', 2);

            if (bccomp($discountPct, $minDiscountPct, 2) >= 0) {
                $results[] = [
                    'stats' => $item,
                    'discountPct' => $discountPct
                ];
            }
        }

        // Sort by discount percentage (highest first)
        usort($results, function($a, $b) {
            return bccomp($b['discountPct'], $a['discountPct'], 2);
        });

        return $results;
    }

    /**
     * Get items that haven't been sold recently (potentially illiquid)
     * 
     * @param int $days Number of days since last sale
     * @return SalesStats[]
     */
    public function findItemsWithNoRecentSales(int $days = 7): array
    {
        $cutoffDate = new \DateTimeImmutable("-{$days} days");

        return $this->createQueryBuilder('ss')
            ->where('ss.lastSaleDate IS NOT NULL')
            ->andWhere('ss.lastSaleDate < :cutoff')
            ->setParameter('cutoff', $cutoffDate)
            ->orderBy('ss.lastSaleDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}