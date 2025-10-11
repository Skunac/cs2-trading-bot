<?php

namespace App\Repository;

use App\Entity\SaleHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SaleHistory>
 */
class SaleHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SaleHistory::class);
    }

    /**
     * Get sales for a specific item in the last N days
     * 
     * @param string $marketHashName Full item name including wear
     * @param int $days Number of days to look back
     * @return SaleHistory[]
     */
    public function findSalesByItemAndDays(string $marketHashName, int $days): array
    {
        $cutoffDate = new \DateTimeImmutable("-{$days} days");

        return $this->createQueryBuilder('sh')
            ->where('sh.marketHashName = :name')
            ->andWhere('sh.dateSold >= :cutoffDate')
            ->setParameter('name', $marketHashName)
            ->setParameter('cutoffDate', $cutoffDate)
            ->orderBy('sh.dateSold', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get all prices for an item in the last N days (for statistics calculation)
     * 
     * @param string $marketHashName
     * @param int $days
     * @return array Array of price strings
     */
    public function getPricesByItemAndDays(string $marketHashName, int $days): array
    {
        $cutoffDate = new \DateTimeImmutable("-{$days} days");

        $result = $this->createQueryBuilder('sh')
            ->select('sh.price')
            ->where('sh.marketHashName = :name')
            ->andWhere('sh.dateSold >= :cutoffDate')
            ->setParameter('name', $marketHashName)
            ->setParameter('cutoffDate', $cutoffDate)
            ->getQuery()
            ->getScalarResult();

        return array_column($result, 'price');
    }

    /**
     * Calculate average price for an item over N days
     * 
     * @param string $marketHashName
     * @param int $days
     * @return string|null Average price or null if no data
     */
    public function calculateAveragePrice(string $marketHashName, int $days): ?string
    {
        $cutoffDate = new \DateTimeImmutable("-{$days} days");

        $result = $this->createQueryBuilder('sh')
            ->select('AVG(sh.price) as avgPrice')
            ->where('sh.marketHashName = :name')
            ->andWhere('sh.dateSold >= :cutoffDate')
            ->setParameter('name', $marketHashName)
            ->setParameter('cutoffDate', $cutoffDate)
            ->getQuery()
            ->getSingleScalarResult();

        return $result !== null ? number_format((float)$result, 2, '.', '') : null;
    }

    /**
     * Calculate median price for an item over N days
     * 
     * @param string $marketHashName
     * @param int $days
     * @return string|null Median price or null if no data
     */
    public function calculateMedianPrice(string $marketHashName, int $days): ?string
    {
        $prices = $this->getPricesByItemAndDays($marketHashName, $days);

        if (empty($prices)) {
            return null;
        }

        sort($prices, SORT_NUMERIC);
        $count = count($prices);
        $middle = floor($count / 2);

        if ($count % 2 === 0) {
            // Even number of elements - average the two middle values
            $median = (floatval($prices[$middle - 1]) + floatval($prices[$middle])) / 2;
        } else {
            // Odd number of elements - take the middle value
            $median = floatval($prices[$middle]);
        }

        return number_format($median, 2, '.', '');
    }

    /**
     * Get min and max prices for an item over N days
     * 
     * @param string $marketHashName
     * @param int $days
     * @return array ['min' => string|null, 'max' => string|null]
     */
    public function getMinMaxPrices(string $marketHashName, int $days): array
    {
        $cutoffDate = new \DateTimeImmutable("-{$days} days");

        $result = $this->createQueryBuilder('sh')
            ->select('MIN(sh.price) as minPrice', 'MAX(sh.price) as maxPrice')
            ->where('sh.marketHashName = :name')
            ->andWhere('sh.dateSold >= :cutoffDate')
            ->setParameter('name', $marketHashName)
            ->setParameter('cutoffDate', $cutoffDate)
            ->getQuery()
            ->getSingleResult();

        return [
            'min' => $result['minPrice'] !== null ? number_format((float)$result['minPrice'], 2, '.', '') : null,
            'max' => $result['maxPrice'] !== null ? number_format((float)$result['maxPrice'], 2, '.', '') : null,
        ];
    }

    /**
     * Calculate price volatility (standard deviation) for an item
     * 
     * @param string $marketHashName
     * @param int $days
     * @return string|null Standard deviation or null if insufficient data
     */
    public function calculateVolatility(string $marketHashName, int $days): ?string
    {
        $prices = $this->getPricesByItemAndDays($marketHashName, $days);

        if (count($prices) < 2) {
            return null;
        }

        // Calculate mean
        $sum = array_sum(array_map('floatval', $prices));
        $mean = $sum / count($prices);

        // Calculate variance
        $variance = 0;
        foreach ($prices as $price) {
            $variance += pow(floatval($price) - $mean, 2);
        }
        $variance /= count($prices);

        // Standard deviation is square root of variance
        $stdDev = sqrt($variance);

        return number_format($stdDev, 2, '.', '');
    }

    /**
     * Count sales for an item in the last N days
     * 
     * @param string $marketHashName
     * @param int $days
     * @return int Number of sales
     */
    public function countSalesByDays(string $marketHashName, int $days): int
    {
        $cutoffDate = new \DateTimeImmutable("-{$days} days");

        return (int) $this->createQueryBuilder('sh')
            ->select('COUNT(sh.id)')
            ->where('sh.marketHashName = :name')
            ->andWhere('sh.dateSold >= :cutoffDate')
            ->setParameter('name', $marketHashName)
            ->setParameter('cutoffDate', $cutoffDate)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get the most recent sale for an item
     * 
     * @param string $marketHashName
     * @return SaleHistory|null
     */
    public function findMostRecentSale(string $marketHashName): ?SaleHistory
    {
        return $this->createQueryBuilder('sh')
            ->where('sh.marketHashName = :name')
            ->setParameter('name', $marketHashName)
            ->orderBy('sh.dateSold', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Count how many times an item reached or exceeded a target price in the last N days
     * 
     * @param string $marketHashName
     * @param string $targetPrice
     * @param int $days
     * @return int Number of times target was reached
     */
    public function countTimesReachedPrice(string $marketHashName, string $targetPrice, int $days): int
    {
        $cutoffDate = new \DateTimeImmutable("-{$days} days");

        return (int) $this->createQueryBuilder('sh')
            ->select('COUNT(sh.id)')
            ->where('sh.marketHashName = :name')
            ->andWhere('sh.price >= :targetPrice')
            ->andWhere('sh.dateSold >= :cutoffDate')
            ->setParameter('name', $marketHashName)
            ->setParameter('targetPrice', $targetPrice)
            ->setParameter('cutoffDate', $cutoffDate)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Check if a sale record already exists (for deduplication)
     * 
     * @param string $marketHashName
     * @param \DateTimeImmutable $dateSold
     * @param string $price
     * @return bool
     */
    public function saleExists(string $marketHashName, \DateTimeImmutable $dateSold, string $price): bool
    {
        $count = $this->createQueryBuilder('sh')
            ->select('COUNT(sh.id)')
            ->where('sh.marketHashName = :name')
            ->andWhere('sh.dateSold = :date')
            ->andWhere('sh.price = :price')
            ->setParameter('name', $marketHashName)
            ->setParameter('date', $dateSold)
            ->setParameter('price', $price)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Delete old sales data (for cleanup - keep only last 90 days)
     * 
     * @param int $daysToKeep Number of days of data to retain
     * @return int Number of records deleted
     */
    public function deleteOldSales(int $daysToKeep = 90): int
    {
        $cutoffDate = new \DateTimeImmutable("-{$daysToKeep} days");

        return $this->createQueryBuilder('sh')
            ->delete()
            ->where('sh.dateSold < :cutoffDate')
            ->setParameter('cutoffDate', $cutoffDate)
            ->getQuery()
            ->execute();
    }

    /**
     * Get all unique item names that have sales data
     * 
     * @return array Array of market hash names
     */
    public function getAllItemsWithSales(): array
    {
        $result = $this->createQueryBuilder('sh')
            ->select('DISTINCT sh.marketHashName')
            ->getQuery()
            ->getScalarResult();

        return array_column($result, 'marketHashName');
    }

    /**
     * Get daily sales count for an item over the last N days (for trend analysis)
     * 
     * @param string $marketHashName
     * @param int $days
     * @return array Array of ['date' => DateTimeImmutable, 'count' => int]
     */
    public function getDailySalesCounts(string $marketHashName, int $days): array
    {
        $cutoffDate = new \DateTimeImmutable("-{$days} days");

        return $this->createQueryBuilder('sh')
            ->select('sh.dateSold as date', 'COUNT(sh.id) as count')
            ->where('sh.marketHashName = :name')
            ->andWhere('sh.dateSold >= :cutoffDate')
            ->setParameter('name', $marketHashName)
            ->setParameter('cutoffDate', $cutoffDate)
            ->groupBy('sh.dateSold')
            ->orderBy('sh.dateSold', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Calculate complete statistics for an item
     * Returns all stats needed for SalesStats entity in one query
     * 
     * @param string $marketHashName
     * @return array Statistics array with keys: avg7d, avg30d, median30d, min30d, max30d, volatility, count7d, count30d, lastSale
     */
    public function calculateCompleteStats(string $marketHashName): array
    {
        return [
            'avg7d' => $this->calculateAveragePrice($marketHashName, 7),
            'avg30d' => $this->calculateAveragePrice($marketHashName, 30),
            'median30d' => $this->calculateMedianPrice($marketHashName, 30),
            'minMax30d' => $this->getMinMaxPrices($marketHashName, 30),
            'volatility' => $this->calculateVolatility($marketHashName, 30),
            'count7d' => $this->countSalesByDays($marketHashName, 7),
            'count30d' => $this->countSalesByDays($marketHashName, 30),
            'lastSale' => $this->findMostRecentSale($marketHashName),
        ];
    }

    /**
     * Bulk insert sales data (for efficient fetching from API)
     * 
     * @param array $salesData Array of ['marketHashName' => string, 'price' => string, 'dateSold' => DateTimeImmutable]
     * @return int Number of records inserted (excluding duplicates)
     */
    public function bulkInsertSales(array $salesData): int
    {
        $em = $this->getEntityManager();
        $inserted = 0;

        foreach ($salesData as $saleData) {
            // Check for duplicates
            if ($this->saleExists(
                $saleData['marketHashName'],
                $saleData['dateSold'],
                $saleData['price']
            )) {
                continue;
            }

            $sale = new SaleHistory();
            $sale->setMarketHashName($saleData['marketHashName']);
            $sale->setPrice($saleData['price']);
            $sale->setDateSold($saleData['dateSold']);

            $em->persist($sale);
            $inserted++;

            // Flush every 50 records to avoid memory issues
            if ($inserted % 50 === 0) {
                $em->flush();
                $em->clear();
            }
        }

        // Flush remaining
        if ($inserted % 50 !== 0) {
            $em->flush();
            $em->clear();
        }

        return $inserted;
    }
}