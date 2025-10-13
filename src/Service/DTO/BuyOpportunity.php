<?php

namespace App\Service\DTO;

/**
 * Represents a validated buy opportunity
 * This is a pure data object (DTO) - no logic, just data
 */
class BuyOpportunity
{
    public function __construct(
        public readonly string $saleId,
        public readonly string $marketHashName,
        public readonly string $currentPrice,
        public readonly string $targetSellPrice,
        public readonly string $expectedProfit,
        public readonly float $riskScore,
        public readonly int $tier,
        public readonly \DateTimeImmutable $timestamp = new \DateTimeImmutable(),
    ) {
    }

    /**
     * Calculate expected profit percentage
     */
    public function getExpectedProfitPct(): string
    {
        if (bccomp($this->currentPrice, '0', 2) === 0) {
            return '0.00';
        }

        return bcmul(
            bcdiv($this->expectedProfit, $this->currentPrice, 4),
            '100',
            2
        );
    }

    /**
     * Check if this is a high-value opportunity (>€20 purchase)
     */
    public function isHighValue(): bool
    {
        return bccomp($this->currentPrice, '20.00', 2) > 0;
    }

    /**
     * Check if this is a low-risk opportunity
     */
    public function isLowRisk(): bool
    {
        return $this->riskScore <= 3.0;
    }

    /**
     * Convert to array for logging/serialization
     */
    public function toArray(): array
    {
        return [
            'sale_id' => $this->saleId,
            'market_hash_name' => $this->marketHashName,
            'current_price' => $this->currentPrice,
            'target_sell_price' => $this->targetSellPrice,
            'expected_profit' => $this->expectedProfit,
            'expected_profit_pct' => $this->getExpectedProfitPct(),
            'risk_score' => $this->riskScore,
            'tier' => $this->tier,
            'timestamp' => $this->timestamp->format('Y-m-d H:i:s'),
        ];
    }
}