<?php

namespace App\Service;

use App\Entity\WhitelistedItem;
use App\Repository\InventoryRepository;
use App\Repository\SaleHistoryRepository;
use App\Repository\SalesStatsRepository;
use App\Repository\WhitelistedItemRepository;
use App\Service\DTO\BuyOpportunity;
use Psr\Log\LoggerInterface;

/**
 * Evaluates market opportunities using the 8-step buy algorithm
 */
class BuyDecisionEngine
{
    public function __construct(
        private readonly WhitelistedItemRepository $whitelistRepo,
        private readonly SalesStatsRepository $statsRepo,
        private readonly SaleHistoryRepository $saleHistoryRepo,
        private readonly InventoryRepository $inventoryRepo,
        private readonly BudgetManager $budgetManager,
        private readonly RiskScorer $riskScorer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Evaluate a single listing for purchase
     * 
     * @param array $listing Listing data from SkinBaron API
     * @return BuyOpportunity|null Opportunity if all checks pass, null otherwise
     */
    public function evaluateListing(array $listing): ?BuyOpportunity
    {
        $marketHashName = $listing['market_name'] ?? null;
        $saleId = $listing['id'] ?? $listing['saleId'] ?? null;
        $currentPrice = $listing['price'] ?? null;

        if (!$marketHashName || !$saleId || !$currentPrice) {
            $this->logger->warning('Invalid listing data', ['listing' => $listing]);
            return null;
        }

        $currentPrice = (string) $currentPrice;

        $this->logger->debug('Evaluating listing', [
            'market_hash_name' => $marketHashName,
            'sale_id' => $saleId,
            'current_price' => $currentPrice,
        ]);

        // Step 1: Whitelist Check
        $whitelistItem = $this->checkWhitelist($marketHashName);
        if (!$whitelistItem) {
            return null;
        }

        // Step 2: Discount Check
        if (!$this->checkDiscount($marketHashName, $currentPrice, $whitelistItem)) {
            return null;
        }

        // Step 3: Spread Check
        if (!$this->checkSpread($listing, $whitelistItem)) {
            return null;
        }

        // Step 4: Budget Check
        if (!$this->checkBudget($currentPrice)) {
            return null;
        }

        // Step 5: Portfolio Check
        if (!$this->checkPortfolio($marketHashName, $whitelistItem)) {
            return null;
        }

        // Step 6: Historical Viability Check
        $targetSellPrice = $this->calculateTargetPrice($currentPrice, $whitelistItem);
        if (!$this->checkHistoricalViability($marketHashName, $targetSellPrice)) {
            return null;
        }

        // Step 7: Risk Assessment
        $riskScore = $this->riskScorer->calculateRiskScore($marketHashName, $currentPrice);
        if (!$this->checkRiskScore($riskScore)) {
            return null;
        }

        // Step 8: Create Opportunity
        $expectedProfit = $this->calculateExpectedProfit($currentPrice, $targetSellPrice);

        $opportunity = new BuyOpportunity(
            saleId: $saleId,
            marketHashName: $marketHashName,
            currentPrice: $currentPrice,
            targetSellPrice: $targetSellPrice,
            expectedProfit: $expectedProfit,
            riskScore: $riskScore,
            tier: $whitelistItem->getTier()
        );

        $this->logger->info('Buy opportunity identified', [
            'market_hash_name' => $marketHashName,
            'sale_id' => $saleId,
            'current_price' => $currentPrice,
            'target_price' => $targetSellPrice,
            'expected_profit' => $expectedProfit,
            'risk_score' => $riskScore,
        ]);

        return $opportunity;
    }

    /**
     * Step 1: Check if item is whitelisted and active
     */
    private function checkWhitelist(string $marketHashName): ?WhitelistedItem
    {
        $item = $this->whitelistRepo->findByMarketHashName($marketHashName);

        if (!$item || !$item->isActive()) {
            $this->logger->debug('Whitelist check failed', [
                'market_hash_name' => $marketHashName,
                'reason' => !$item ? 'not_whitelisted' : 'inactive',
            ]);
            return null;
        }

        return $item;
    }

    /**
     * Step 2: Check if discount meets minimum threshold
     */
    private function checkDiscount(string $marketHashName, string $currentPrice, WhitelistedItem $whitelistItem): bool
    {
        $stats = $this->statsRepo->findByItem($marketHashName);

        if (!$stats || !$stats->getAvgPrice7d()) {
            $this->logger->debug('Discount check failed - no statistics', [
                'market_hash_name' => $marketHashName,
            ]);
            return false;
        }

        $avgPrice = $stats->getAvgPrice7d();
        
        // Calculate discount percentage
        $discountPct = bcdiv(
            bcsub($avgPrice, $currentPrice, 2),
            $avgPrice,
            4
        );
        $discountPct = bcmul($discountPct, '100', 2);

        $minDiscountPct = $whitelistItem->getMinDiscountPct();

        if (bccomp($discountPct, $minDiscountPct, 2) < 0) {
            $this->logger->debug('Discount check failed', [
                'market_hash_name' => $marketHashName,
                'discount_pct' => $discountPct,
                'min_required' => $minDiscountPct,
                'avg_price_7d' => $avgPrice,
                'current_price' => $currentPrice,
            ]);
            return false;
        }

        $this->logger->debug('Discount check passed', [
            'market_hash_name' => $marketHashName,
            'discount_pct' => $discountPct,
        ]);

        return true;
    }

    /**
     * Step 3: Check spread to next listing
     * 
     * @param array $listing Current listing with potential next_price
     */
    private function checkSpread(array $listing, WhitelistedItem $whitelistItem): bool
    {
        // If API provides next price directly
        if (isset($listing['next_price'])) {
            $currentPrice = (string) $listing['price'];
            $nextPrice = (string) $listing['next_price'];
            
            $spreadPct = bcdiv(
                bcsub($nextPrice, $currentPrice, 2),
                $currentPrice,
                4
            );
            $spreadPct = bcmul($spreadPct, '100', 2);

            $minSpreadPct = $whitelistItem->getMinSpreadPct();

            if (bccomp($spreadPct, $minSpreadPct, 2) < 0) {
                $this->logger->debug('Spread check failed', [
                    'current_price' => $currentPrice,
                    'next_price' => $nextPrice,
                    'spread_pct' => $spreadPct,
                    'min_required' => $minSpreadPct,
                ]);
                return false;
            }
        }

        // Note: If next_price not available, we skip this check
        // In production, you'd need to fetch next listing via separate API call

        return true;
    }

    /**
     * Step 4: Check if we can afford this purchase
     */
    private function checkBudget(string $currentPrice): bool
    {
        if (!$this->budgetManager->canAfford($currentPrice)) {
            $this->logger->debug('Budget check failed', [
                'price' => $currentPrice,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Step 5: Check portfolio limits (max holdings per item)
     */
    private function checkPortfolio(string $marketHashName, WhitelistedItem $whitelistItem): bool
    {
        $currentHoldings = $this->inventoryRepo->countHoldingsForItem($marketHashName);
        $maxHoldings = $whitelistItem->getMaxHoldings();

        if ($currentHoldings >= $maxHoldings) {
            $this->logger->debug('Portfolio check failed', [
                'market_hash_name' => $marketHashName,
                'current_holdings' => $currentHoldings,
                'max_holdings' => $maxHoldings,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Step 6: Check if item historically reaches target price
     */
    private function checkHistoricalViability(string $marketHashName, string $targetSellPrice): bool
    {
        // Count how many times item sold at or above target price in last 30 days
        $timesReached = $this->saleHistoryRepo->countTimesReachedPrice(
            $marketHashName,
            $targetSellPrice,
            30
        );

        if ($timesReached < 3) {
            $this->logger->debug('Historical viability check failed', [
                'market_hash_name' => $marketHashName,
                'target_price' => $targetSellPrice,
                'times_reached' => $timesReached,
                'min_required' => 3,
            ]);
            return false;
        }

        $this->logger->debug('Historical viability check passed', [
            'market_hash_name' => $marketHashName,
            'target_price' => $targetSellPrice,
            'times_reached' => $timesReached,
        ]);

        return true;
    }

    /**
     * Step 7: Check if risk score is acceptable
     */
    private function checkRiskScore(float $riskScore): bool
    {
        $maxRiskScore = 7.0;

        if ($riskScore > $maxRiskScore) {
            $this->logger->debug('Risk score check failed', [
                'risk_score' => $riskScore,
                'max_allowed' => $maxRiskScore,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Calculate target sell price to achieve desired profit after fees
     * Formula: (purchasePrice * (1 + profitMargin)) / (1 - feeRate)
     */
    private function calculateTargetPrice(string $purchasePrice, WhitelistedItem $whitelistItem): string
    {
        $profitMargin = bcdiv($whitelistItem->getTargetProfitPct(), '100', 4); // 10% = 0.10
        $feeRate = '0.15'; // 15% marketplace fee

        // purchasePrice * (1 + profitMargin)
        $withProfit = bcmul($purchasePrice, bcadd('1', $profitMargin, 4), 2);

        // / (1 - feeRate)
        $targetPrice = bcdiv($withProfit, bcsub('1', $feeRate, 4), 2);

        return $targetPrice;
    }

    /**
     * Calculate expected profit after fees
     */
    private function calculateExpectedProfit(string $purchasePrice, string $targetSellPrice): string
    {
        $feeRate = '0.15';
        
        // Net from sale: targetSellPrice * (1 - feeRate)
        $netFromSale = bcmul($targetSellPrice, bcsub('1', $feeRate, 4), 2);
        
        // Profit: net - purchase
        $profit = bcsub($netFromSale, $purchasePrice, 2);

        return $profit;
    }
}