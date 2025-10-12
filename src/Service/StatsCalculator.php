<?php

namespace App\Service;

use App\Entity\SalesStats;
use App\Repository\SaleHistoryRepository;
use App\Repository\SalesStatsRepository;
use Psr\Log\LoggerInterface;

/**
 * Calculate market statistics from sales history
 */
class StatsCalculator
{
    public function __construct(
        private readonly SaleHistoryRepository $saleHistoryRepo,
        private readonly SalesStatsRepository $statsRepo,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Calculate and update stats for a specific item
     */
    public function calculateStatsForItem(string $marketHashName): SalesStats
    {
        // Use the repository's comprehensive stats method
        $rawStats = $this->saleHistoryRepo->calculateCompleteStats($marketHashName);

        // Find or create stats entity
        $stats = $this->statsRepo->findOrCreate($marketHashName);

        // Update all fields
        $stats->setAvgPrice7d($rawStats['avg7d']);
        $stats->setAvgPrice30d($rawStats['avg30d']);
        $stats->setMedianPrice30d($rawStats['median30d']);
        
        $minMax = $rawStats['minMax30d'];
        $stats->setMinPrice30d($minMax['min']);
        $stats->setMaxPrice30d($minMax['max']);
        
        $stats->setPriceVolatility($rawStats['volatility']);
        $stats->setSalesCount7d($rawStats['count7d']);
        $stats->setSalesCount30d($rawStats['count30d']);

        // Calculate daily sales velocity
        if ($rawStats['count30d'] > 0) {
            $velocity = bcdiv((string)$rawStats['count30d'], '30', 2);
            $stats->setAvgSalesPerDay($velocity);
        }

        // Set data points (same as 30-day count)
        $stats->setDataPoints($rawStats['count30d']);

        // Set last sale info
        $lastSale = $rawStats['lastSale'];
        if ($lastSale) {
            $stats->setLastSaleDate($lastSale->getDateSold());
            $stats->setLastSalePrice($lastSale->getPrice());
        }

        // Persist to database
        $em = $this->statsRepo->getEntityManager();
        $em->persist($stats);
        $em->flush();

        $this->logger->info('Calculated stats for item', [
            'market_hash_name' => $marketHashName,
            'avg_7d' => $stats->getAvgPrice7d(),
            'avg_30d' => $stats->getAvgPrice30d(),
            'sales_7d' => $stats->getSalesCount7d(),
            'sales_30d' => $stats->getSalesCount30d(),
            'volatility' => $stats->getPriceVolatility(),
            'avg_sales_per_day' => $stats->getAvgSalesPerDay(),
        ]);

        return $stats;
    }

    /**
     * Get stats for item (from cache or calculate if missing/stale)
     */
    public function getStats(string $marketHashName): ?SalesStats
    {
        $stats = $this->statsRepo->findByItem($marketHashName);

        // If stats don't exist or are older than 12 hours, recalculate
        if (!$stats || $this->isStale($stats)) {
            return $this->calculateStatsForItem($marketHashName);
        }

        return $stats;
    }

    /**
     * Check if stats are stale (older than 12 hours)
     */
    private function isStale(SalesStats $stats): bool
    {
        $now = new \DateTimeImmutable();
        $age = $now->getTimestamp() - $stats->getUpdatedAt()->getTimestamp();
        
        return $age > 43200; // 12 hours in seconds
    }

    /**
     * Calculate stats quality score (0-100)
     * Based on data points and recency
     */
    public function calculateQualityScore(SalesStats $stats): int
    {
        $score = 0;

        // Data points (max 50 points)
        $dataPoints = $stats->getDataPoints();
        if ($dataPoints >= 30) {
            $score += 50;
        } elseif ($dataPoints >= 15) {
            $score += 35;
        } elseif ($dataPoints >= 10) {
            $score += 20;
        } elseif ($dataPoints >= 5) {
            $score += 10;
        }

        // Recency (max 30 points)
        $lastSaleDate = $stats->getLastSaleDate();
        if ($lastSaleDate) {
            $daysSinceLastSale = (new \DateTimeImmutable())->diff($lastSaleDate)->days;
            if ($daysSinceLastSale <= 1) {
                $score += 30;
            } elseif ($daysSinceLastSale <= 3) {
                $score += 20;
            } elseif ($daysSinceLastSale <= 7) {
                $score += 10;
            }
        }

        // Sales velocity (max 20 points)
        $avgSalesPerDay = $stats->getAvgSalesPerDay();
        if ($avgSalesPerDay !== null) {
            $velocity = (float)$avgSalesPerDay;
            if ($velocity >= 10) {
                $score += 20;
            } elseif ($velocity >= 5) {
                $score += 15;
            } elseif ($velocity >= 2) {
                $score += 10;
            } elseif ($velocity >= 1) {
                $score += 5;
            }
        }

        return min(100, $score);
    }
}