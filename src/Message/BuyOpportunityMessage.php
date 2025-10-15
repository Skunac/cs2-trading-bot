<?php

namespace App\Message;

/**
 * Message for async execution of buy opportunities
 */
class BuyOpportunityMessage
{
    public function __construct(
        public readonly string $saleId,
        public readonly string $marketHashName,
        public readonly string $currentPrice,
        public readonly string $targetSellPrice,
        public readonly string $expectedProfit,
        public readonly float $riskScore,
        public readonly int $tier,
    ) {
    }
}