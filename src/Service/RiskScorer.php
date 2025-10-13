<?php

namespace App\Service;

use App\Entity\SalesStats;
use App\Repository\InventoryRepository;
use App\Repository\SalesStatsRepository;
use Psr\Log\LoggerInterface;

/**
 * Calculate risk score (0-10 scale) for trading opportunities
 * Higher score = higher risk
 */
class RiskScorer
{
    // Risk thresholds
    private const HIGH_VOLATILITY_THRESHOLD = '2.0';
    private const LOW_LIQUIDITY_THRESHOLD = '2.0'; // sales per day
    private const NEAR_LOW_PERCENTAGE = '5.0'; // within 5% of 30-day low
    
    // Risk weights
    private const VOLATILITY_WEIGHT = 3.0;
    private const NEAR_LOW_WEIGHT = 2.0;
    private const LOW_LIQUIDITY_WEIGHT = 2.0;
    private const PORTFOLIO_CONCENTRATION_WEIGHT = 1.5;
    private const INSUFFICIENT_DATA_WEIGHT = 2.0;

    public function __construct(
        private readonly SalesStatsRepository $statsRepo,
        private readonly InventoryRepository $inventoryRepo,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Calculate risk score for a potential purchase
     * 
     * @param string $marketHashName Item being evaluated
     * @param string $currentPrice Current listing price
     * @return float Risk score (0-10, higher = riskier)
     */
    public function calculateRiskScore(string $marketHashName, string $currentPrice): float
    {
        $riskScore = 0.0;
        $factors = [];

        // Get market statistics
        $stats = $this->statsRepo->findByItem($marketHashName);
        
        if (!$stats) {
            // No statistics available = very high risk
            $this->logger->warning('No statistics available for risk scoring', [
                'market_hash_name' => $marketHashName,
            ]);
            return 10.0;
        }

        // Factor 1: High volatility (price swings)
        $volatilityRisk = $this->assessVolatilityRisk($stats);
        if ($volatilityRisk > 0) {
            $riskScore += $volatilityRisk;
            $factors['high_volatility'] = $volatilityRisk;
        }

        // Factor 2: Near 30-day low (might drop further)
        $nearLowRisk = $this->assessNearLowRisk($stats, $currentPrice);
        if ($nearLowRisk > 0) {
            $riskScore += $nearLowRisk;
            $factors['near_30d_low'] = $nearLowRisk;
        }

        // Factor 3: Low liquidity (hard to sell)
        $liquidityRisk = $this->assessLiquidityRisk($stats);
        if ($liquidityRisk > 0) {
            $riskScore += $liquidityRisk;
            $factors['low_liquidity'] = $liquidityRisk;
        }

        // Factor 4: Portfolio concentration (already own multiple)
        $concentrationRisk = $this->assessConcentrationRisk($marketHashName);
        if ($concentrationRisk > 0) {
            $riskScore += $concentrationRisk;
            $factors['portfolio_concentration'] = $concentrationRisk;
        }

        // Factor 5: Insufficient historical data
        $dataQualityRisk = $this->assessDataQualityRisk($stats);
        if ($dataQualityRisk > 0) {
            $riskScore += $dataQualityRisk;
            $factors['insufficient_data'] = $dataQualityRisk;
        }

        // Cap at 10.0
        $riskScore = min(10.0, $riskScore);

        $this->logger->debug('Risk score calculated', [
            'market_hash_name' => $marketHashName,
            'current_price' => $currentPrice,
            'risk_score' => round($riskScore, 1),
            'factors' => $factors,
        ]);

        return round($riskScore, 1);
    }

    /**
     * Assess risk from price volatility
     * High standard deviation = unpredictable prices
     */
    private function assessVolatilityRisk(SalesStats $stats): float
    {
        $volatility = $stats->getPriceVolatility();
        
        if ($volatility === null) {
            return 0.0;
        }

        // If volatility exceeds threshold, add risk
        if (\bccomp($volatility, self::HIGH_VOLATILITY_THRESHOLD, 2) >= 0) {
            // Scale: volatility of 2.0 = +3 points, 4.0 = +6 points (capped)
            $multiplier = bcdiv($volatility, self::HIGH_VOLATILITY_THRESHOLD, 2);
            return min(self::VOLATILITY_WEIGHT * (float) $multiplier, self::VOLATILITY_WEIGHT * 2);
        }

        return 0.0;
    }

    /**
     * Assess risk from buying near 30-day low
     * If price is near historical low, it might drop further
     */
    private function assessNearLowRisk(SalesStats $stats, string $currentPrice): float
    {
        $minPrice = $stats->getMinPrice30d();
        
        if ($minPrice === null) {
            return 0.0;
        }

        // Calculate how close current price is to 30-day low
        if (bccomp($minPrice, '0', 2) === 0) {
            return 0.0; // Avoid division by zero
        }

        $percentageAboveLow = bcdiv(
            bcsub($currentPrice, $minPrice, 2),
            $minPrice,
            4
        );
        $percentageAboveLow = bcmul($percentageAboveLow, '100', 2);

        // If within 5% of 30-day low, add risk
        if (bccomp($percentageAboveLow, self::NEAR_LOW_PERCENTAGE, 2) <= 0) {
            return self::NEAR_LOW_WEIGHT;
        }

        return 0.0;
    }

    /**
     * Assess risk from low liquidity
     * Few sales = hard to resell quickly
     */
    private function assessLiquidityRisk(SalesStats $stats): float
    {
        $avgSalesPerDay = $stats->getAvgSalesPerDay();
        
        if ($avgSalesPerDay === null) {
            return self::LOW_LIQUIDITY_WEIGHT; // No data = risky
        }

        // If below threshold (e.g., less than 2 sales/day), add risk
        if (bccomp($avgSalesPerDay, self::LOW_LIQUIDITY_THRESHOLD, 2) < 0) {
            // Scale: 1 sale/day = +2 points, 0.5 sales/day = +4 points
            $ratio = bcdiv(self::LOW_LIQUIDITY_THRESHOLD, $avgSalesPerDay, 2);
            return min(self::LOW_LIQUIDITY_WEIGHT * (float) $ratio, self::LOW_LIQUIDITY_WEIGHT * 2);
        }

        return 0.0;
    }

    /**
     * Assess risk from portfolio concentration
     * Already owning multiple of same item = less diversified
     */
    private function assessConcentrationRisk(string $marketHashName): float
    {
        $currentHoldings = $this->inventoryRepo->countHoldingsForItem($marketHashName);
        
        if ($currentHoldings === 0) {
            return 0.0; // First one, no concentration risk
        }

        // Each existing holding adds 1.5 points
        // 1 holding = +1.5, 2 holdings = +3.0, 3 holdings = +4.5
        return self::PORTFOLIO_CONCENTRATION_WEIGHT * $currentHoldings;
    }

    /**
     * Assess risk from insufficient historical data
     * Less than 10 sales = unreliable statistics
     */
    private function assessDataQualityRisk(SalesStats $stats): float
    {
        if (!$stats->hasReliableData()) {
            // Less than 10 sales in 30 days
            return self::INSUFFICIENT_DATA_WEIGHT;
        }

        return 0.0;
    }

    /**
     * Get risk level description
     */
    public function getRiskLevel(float $score): string
    {
        return match(true) {
            $score >= 8.0 => 'CRITICAL',
            $score >= 6.0 => 'HIGH',
            $score >= 4.0 => 'MEDIUM',
            $score >= 2.0 => 'LOW',
            default => 'MINIMAL',
        };
    }

    /**
     * Check if risk is acceptable (below threshold)
     */
    public function isAcceptableRisk(float $score, float $threshold = 7.0): bool
    {
        return $score <= $threshold;
    }

    /**
     * Get detailed risk breakdown for logging/debugging
     */
    public function getDetailedRiskAssessment(string $marketHashName, string $currentPrice): array
    {
        $stats = $this->statsRepo->findByItem($marketHashName);
        
        if (!$stats) {
            return [
                'score' => 10.0,
                'level' => 'CRITICAL',
                'acceptable' => false,
                'reason' => 'No statistics available',
                'factors' => [],
            ];
        }

        $score = $this->calculateRiskScore($marketHashName, $currentPrice);
        $level = $this->getRiskLevel($score);
        $acceptable = $this->isAcceptableRisk($score);

        $factors = [
            'volatility' => [
                'value' => $stats->getPriceVolatility(),
                'threshold' => self::HIGH_VOLATILITY_THRESHOLD,
                'risky' => $stats->getPriceVolatility() !== null && 
                          bccomp($stats->getPriceVolatility(), self::HIGH_VOLATILITY_THRESHOLD, 2) >= 0,
            ],
            'liquidity' => [
                'value' => $stats->getAvgSalesPerDay(),
                'threshold' => self::LOW_LIQUIDITY_THRESHOLD,
                'risky' => $stats->getAvgSalesPerDay() !== null && 
                          bccomp($stats->getAvgSalesPerDay(), self::LOW_LIQUIDITY_THRESHOLD, 2) < 0,
            ],
            'current_holdings' => [
                'value' => $this->inventoryRepo->countHoldingsForItem($marketHashName),
                'max' => 3,
            ],
            'data_points' => [
                'value' => $stats->getSalesCount30d(),
                'min_reliable' => 10,
                'reliable' => $stats->hasReliableData(),
            ],
        ];

        return [
            'score' => $score,
            'level' => $level,
            'acceptable' => $acceptable,
            'factors' => $factors,
        ];
    }
}