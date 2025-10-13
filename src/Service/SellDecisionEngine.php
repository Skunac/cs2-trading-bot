<?php

namespace App\Service;

use App\Entity\Inventory;
use App\Service\DTO\SellOpportunity;
use App\Service\SkinBaron\SkinBaronClient;
use Psr\Log\LoggerInterface;

/**
 * Decides when to sell inventory items based on actual market listings
 */
class SellDecisionEngine
{
    private const MAX_HOLD_DAYS = 7;
    private const STOP_LOSS_THRESHOLD_PCT = '10.0';
    private const UNDERCUT_AMOUNT = '0.01';
    private const MIN_PROFIT_MARGIN_PCT = '3.0';

    /**
     * Cache of market listings fetched during this run
     * Key: marketHashName, Value: array of listings
     */
    private array $listingsCache = [];

    public function __construct(
        private readonly SkinBaronClient $skinBaronClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Evaluate an inventory item for selling
     * 
     * @param array|null $marketListings Optional pre-fetched market listings to avoid API calls
     * @return SellOpportunity|null Opportunity if action needed, null to hold
     */
    public function evaluateItem(Inventory $item, ?array $marketListings = null): ?SellOpportunity
    {
        $this->logger->debug('Evaluating item for selling', [
            'inventory_id' => $item->getId(),
            'market_hash_name' => $item->getMarketHashName(),
            'status' => $item->getStatus(),
            'purchase_price' => $item->getPurchasePrice(),
            'target_sell_price' => $item->getTargetSellPrice(),
            'hold_days' => $item->getHoldingDays(),
        ]);

        // Route based on status
        if ($item->getStatus() === 'holding') {
            return $this->evaluateHoldingItem($item, $marketListings);
        }

        if ($item->getStatus() === 'listed') {
            return $this->evaluateListedItem($item, $marketListings);
        }

        return null;
    }

    /**
     * Evaluate item that's being held (not yet listed)
     */
    private function evaluateHoldingItem(Inventory $item, ?array $marketListings): ?SellOpportunity
    {
        $holdDays = $item->getHoldingDays();
        $purchasePrice = $item->getPurchasePrice();
        $targetPrice = $item->getTargetSellPrice();
        $marketHashName = $item->getMarketHashName();

        // Use provided listings or fetch from cache/API
        $listings = $marketListings ?? $this->getListings($marketHashName);

        if (empty($listings)) {
            $this->logger->warning('No market listings found for item', [
                'inventory_id' => $item->getId(),
                'market_hash_name' => $marketHashName,
            ]);
            return null;
        }

        // Get cheapest listing (excluding our own if already listed)
        $cheapestListing = $this->getCheapestListing($listings, $item->getSaleId());
        
        if (!$cheapestListing) {
            $this->logger->warning('Could not determine cheapest listing', [
                'inventory_id' => $item->getId(),
            ]);
            return null;
        }

        $cheapestPrice = (string) $cheapestListing['price'];
        $minProfitablePrice = $this->calculateMinProfitablePrice($purchasePrice);

        $this->logger->debug('Market analysis', [
            'inventory_id' => $item->getId(),
            'cheapest_market_price' => $cheapestPrice,
            'our_target_price' => $targetPrice,
            'min_profitable_price' => $minProfitablePrice,
            'purchase_price' => $purchasePrice,
        ]);

        // Check 1: Can we undercut and still be profitable?
        $undercutPrice = bcsub($cheapestPrice, self::UNDERCUT_AMOUNT, 2);
        
        if (bccomp($undercutPrice, $targetPrice, 2) >= 0) {
            $this->logger->info('Target price achievable by undercutting', [
                'inventory_id' => $item->getId(),
                'market_hash_name' => $marketHashName,
                'undercut_price' => $undercutPrice,
                'target_price' => $targetPrice,
            ]);

            return new SellOpportunity(
                inventoryId: $item->getId(),
                saleId: $item->getSaleId(),
                marketHashName: $marketHashName,
                action: 'list',
                listPrice: $undercutPrice,
                reason: 'Profit target achievable by undercutting'
            );
        }

        if (bccomp($undercutPrice, $minProfitablePrice, 2) >= 0) {
            $netProfit = $this->calculateNetProfit($purchasePrice, $undercutPrice);
            $profitPct = bcmul(bcdiv($netProfit, $purchasePrice, 4), '100', 2);

            $this->logger->info('Undercut price is still profitable', [
                'inventory_id' => $item->getId(),
                'market_hash_name' => $marketHashName,
                'undercut_price' => $undercutPrice,
                'net_profit' => $netProfit,
                'profit_pct' => $profitPct,
            ]);

            return new SellOpportunity(
                inventoryId: $item->getId(),
                saleId: $item->getSaleId(),
                marketHashName: $marketHashName,
                action: 'list',
                listPrice: $undercutPrice,
                reason: "Profitable opportunity ({$profitPct}% profit)"
            );
        }

        // Check 2: Held too long? List at minimum profitable price if market allows
        if ($holdDays >= self::MAX_HOLD_DAYS) {
            $breakEvenPrice = $this->calculateBreakEvenPrice($purchasePrice);

            if (bccomp($cheapestPrice, $breakEvenPrice, 2) >= 0) {
                $listPrice = max(
                    (float) $undercutPrice,
                    (float) $breakEvenPrice
                );
                $listPrice = number_format($listPrice, 2, '.', '');

                $this->logger->info('Held too long, listing at break-even or better', [
                    'inventory_id' => $item->getId(),
                    'market_hash_name' => $marketHashName,
                    'hold_days' => $holdDays,
                    'list_price' => $listPrice,
                    'break_even_price' => $breakEvenPrice,
                ]);

                return new SellOpportunity(
                    inventoryId: $item->getId(),
                    saleId: $item->getSaleId(),
                    marketHashName: $marketHashName,
                    action: 'list',
                    listPrice: $listPrice,
                    reason: "Held too long ({$holdDays} days), cutting losses"
                );
            }

            $this->logger->warning('Held too long but market below break-even', [
                'inventory_id' => $item->getId(),
                'market_hash_name' => $marketHashName,
                'hold_days' => $holdDays,
                'cheapest_price' => $cheapestPrice,
                'break_even_price' => $breakEvenPrice,
            ]);
        }

        // Check 3: Stop-loss - has purchase price dropped significantly in market?
        $marketAvgPrice = $this->getAverageListingPrice($listings);
        $priceDrop = bcdiv(
            bcsub($purchasePrice, $marketAvgPrice, 2),
            $purchasePrice,
            4
        );
        $priceDropPct = bcmul($priceDrop, '100', 2);

        if (bccomp($priceDropPct, self::STOP_LOSS_THRESHOLD_PCT, 2) >= 0) {
            $stopLossPrice = $undercutPrice;

            $this->logger->error('Stop-loss triggered - market crashed', [
                'inventory_id' => $item->getId(),
                'market_hash_name' => $marketHashName,
                'purchase_price' => $purchasePrice,
                'market_avg_price' => $marketAvgPrice,
                'price_drop_pct' => $priceDropPct,
                'stop_loss_price' => $stopLossPrice,
            ]);

            return new SellOpportunity(
                inventoryId: $item->getId(),
                saleId: $item->getSaleId(),
                marketHashName: $marketHashName,
                action: 'list',
                listPrice: $stopLossPrice,
                reason: "STOP-LOSS: Market crashed ({$priceDropPct}% drop)"
            );
        }

        // Default: HOLD
        $this->logger->debug('Holding item - market not favorable', [
            'inventory_id' => $item->getId(),
            'market_hash_name' => $marketHashName,
            'cheapest_price' => $cheapestPrice,
            'min_profitable_price' => $minProfitablePrice,
            'target_price' => $targetPrice,
            'hold_days' => $holdDays,
        ]);

        return null;
    }

    /**
     * Evaluate item that's already listed
     */
    private function evaluateListedItem(Inventory $item, ?array $marketListings): ?SellOpportunity
    {
        $listedPrice = $item->getListedPrice();
        $holdDays = $item->getHoldingDays();
        $marketHashName = $item->getMarketHashName();
        $purchasePrice = $item->getPurchasePrice();

        // Use provided listings or fetch from cache/API
        $listings = $marketListings ?? $this->getListings($marketHashName);

        if (empty($listings)) {
            $this->logger->warning('No market listings found for listed item', [
                'inventory_id' => $item->getId(),
            ]);
            return null;
        }

        $cheapestListing = $this->getCheapestListing($listings, $item->getSaleId());
        
        if (!$cheapestListing) {
            $this->logger->debug('We are cheapest or only listing', [
                'inventory_id' => $item->getId(),
                'our_price' => $listedPrice,
            ]);
            return null;
        }

        $cheapestPrice = (string) $cheapestListing['price'];
        $minProfitablePrice = $this->calculateMinProfitablePrice($purchasePrice);

        $this->logger->debug('Listed item market analysis', [
            'inventory_id' => $item->getId(),
            'our_listed_price' => $listedPrice,
            'cheapest_competitor_price' => $cheapestPrice,
            'min_profitable_price' => $minProfitablePrice,
        ]);

        // Check 1: Are we still cheapest?
        $priceDiff = bcsub($listedPrice, $cheapestPrice, 2);
        
        if (bccomp($priceDiff, '0.01', 2) <= 0) {
            $this->logger->debug('Still cheapest or competitive', [
                'inventory_id' => $item->getId(),
                'our_price' => $listedPrice,
                'cheapest_price' => $cheapestPrice,
            ]);
            return null;
        }

        // Check 2: Should we adjust to undercut?
        $undercutPrice = bcsub($cheapestPrice, self::UNDERCUT_AMOUNT, 2);
        $significantUndercut = bccomp($priceDiff, '0.50', 2) > 0;

        if ($holdDays >= 3 || $significantUndercut) {
            if (bccomp($undercutPrice, $minProfitablePrice, 2) >= 0) {
                $netProfit = $this->calculateNetProfit($purchasePrice, $undercutPrice);
                $profitPct = bcmul(bcdiv($netProfit, $purchasePrice, 4), '100', 2);

                $this->logger->info('Adjusting price to undercut competition', [
                    'inventory_id' => $item->getId(),
                    'market_hash_name' => $marketHashName,
                    'old_price' => $listedPrice,
                    'new_price' => $undercutPrice,
                    'competitor_price' => $cheapestPrice,
                    'profit_pct' => $profitPct,
                    'reason' => $significantUndercut ? 'significant_undercut' : 'held_too_long',
                ]);

                return new SellOpportunity(
                    inventoryId: $item->getId(),
                    saleId: $item->getSaleId(),
                    marketHashName: $marketHashName,
                    action: 'adjust',
                    listPrice: $undercutPrice,
                    reason: $significantUndercut 
                        ? 'Competitor undercut us significantly'
                        : 'Undercutting after 3+ days listed'
                );
            }

            $breakEvenPrice = $this->calculateBreakEvenPrice($purchasePrice);
            
            if (bccomp($undercutPrice, $breakEvenPrice, 2) >= 0 && $holdDays >= 5) {
                $this->logger->warning('Adjusting to break-even to sell quickly', [
                    'inventory_id' => $item->getId(),
                    'market_hash_name' => $marketHashName,
                    'old_price' => $listedPrice,
                    'new_price' => $undercutPrice,
                    'hold_days' => $holdDays,
                ]);

                return new SellOpportunity(
                    inventoryId: $item->getId(),
                    saleId: $item->getSaleId(),
                    marketHashName: $marketHashName,
                    action: 'adjust',
                    listPrice: number_format(max((float) $undercutPrice, (float) $breakEvenPrice), 2, '.', ''),
                    reason: 'Cutting losses after 5+ days'
                );
            }
        }

        $this->logger->debug('Holding listed item', [
            'inventory_id' => $item->getId(),
            'our_price' => $listedPrice,
            'cheapest_price' => $cheapestPrice,
            'hold_days' => $holdDays,
        ]);

        return null;
    }

    /**
     * Get listings from cache or fetch from API
     * This method uses internal cache to avoid repeated API calls
     */
    private function getListings(string $marketHashName): array
    {
        // Check cache first
        if (isset($this->listingsCache[$marketHashName])) {
            $this->logger->debug('Using cached listings', [
                'market_hash_name' => $marketHashName,
            ]);
            return $this->listingsCache[$marketHashName];
        }

        // Fetch from API
        try {
            $listings = $this->skinBaronClient->search(
                marketHashName: $marketHashName,
                limit: 20
            );

            // Cache the result
            $this->listingsCache[$marketHashName] = $listings;

            $this->logger->debug('Fetched and cached listings', [
                'market_hash_name' => $marketHashName,
                'count' => count($listings),
            ]);

            return $listings;

        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch market listings', [
                'market_hash_name' => $marketHashName,
                'error' => $e->getMessage(),
            ]);
            
            // Cache empty result to avoid retrying
            $this->listingsCache[$marketHashName] = [];
            
            return [];
        }
    }

    /**
     * Clear the listings cache (useful between scan runs)
     */
    public function clearCache(): void
    {
        $this->listingsCache = [];
    }

    /**
     * Get the cheapest listing (excluding our own)
     */
    private function getCheapestListing(array $listings, string $ourSaleId): ?array
    {
        $cheapest = null;

        foreach ($listings as $listing) {
            $listingSaleId = (string) ($listing['id'] ?? $listing['saleId'] ?? '');
            
            if ($listingSaleId === $ourSaleId) {
                continue;
            }

            $price = (float) ($listing['price'] ?? 0);
            
            if ($price > 0 && ($cheapest === null || $price < (float) $cheapest['price'])) {
                $cheapest = $listing;
            }
        }

        return $cheapest;
    }

    /**
     * Calculate average price from listings
     */
    private function getAverageListingPrice(array $listings): string
    {
        if (empty($listings)) {
            return '0.00';
        }

        $total = 0;
        $count = 0;

        foreach ($listings as $listing) {
            $price = (float) ($listing['price'] ?? 0);
            if ($price > 0) {
                $total += $price;
                $count++;
            }
        }

        if ($count === 0) {
            return '0.00';
        }

        return number_format($total / $count, 2, '.', '');
    }

    /**
     * Calculate minimum profitable price
     */
    private function calculateMinProfitablePrice(string $purchasePrice): string
    {
        $minMargin = bcdiv(self::MIN_PROFIT_MARGIN_PCT, '100', 4);
        $feeRate = '0.15';

        $minProfit = bcmul($purchasePrice, bcadd('1', $minMargin, 4), 2);
        $minPrice = bcdiv($minProfit, bcsub('1', $feeRate, 4), 2);

        return $minPrice;
    }

    /**
     * Calculate break-even price
     */
    private function calculateBreakEvenPrice(string $purchasePrice): string
    {
        return bcdiv($purchasePrice, '0.85', 2);
    }

    /**
     * Calculate net profit after fees
     */
    private function calculateNetProfit(string $purchasePrice, string $sellPrice): string
    {
        $feeRate = '0.15';
        $netFromSale = bcmul($sellPrice, bcsub('1', $feeRate, 4), 2);
        return bcsub($netFromSale, $purchasePrice, 2);
    }
}