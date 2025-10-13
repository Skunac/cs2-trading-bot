<?php

namespace App\Service;

use App\Entity\BalanceSnapshot;
use App\Entity\SystemConfig;
use App\Repository\BalanceSnapshotRepository;
use App\Repository\InventoryRepository;
use App\Repository\SystemConfigRepository;
use App\Service\SkinBaron\SkinBaronClient;
use Psr\Log\LoggerInterface;

class BudgetManager
{
    private const HARD_FLOOR_KEY = 'budget.hard_floor';
    private const SOFT_FLOOR_KEY = 'budget.soft_floor';
    private const MAX_RISK_PER_TRADE_KEY = 'budget.max_risk_per_trade';
    private const MAX_TOTAL_EXPOSURE_KEY = 'budget.max_total_exposure';
    private const MIN_RESERVE_PCT_KEY = 'budget.min_reserve_pct';

    // In-memory cache of reserved amounts
    private array $reservations = [];

    public function __construct(
        private readonly SkinBaronClient $skinBaronClient,
        private readonly BalanceSnapshotRepository $snapshotRepo,
        private readonly InventoryRepository $inventoryRepo,
        private readonly SystemConfigRepository $configRepo,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Check if we can afford a purchase
     * Considers: balance, floor, reserves, exposure limits
     */
    public function canAfford(string $price): bool
    {
        $balance = $this->getCurrentBalance();
        $hardFloor = $this->getHardFloor();
        $maxRiskPerTrade = $this->getMaxRiskPerTrade();
        $maxTotalExposure = $this->getMaxTotalExposure();
        $minReserve = $this->getMinReserve();

        // Calculate available balance
        $reserved = $this->getTotalReserved();
        $invested = $this->getTotalInvested();
        $requiredReserve = bcmul($balance, $minReserve, 2);
        
        $available = bcsub($balance, $reserved, 2);
        $available = bcsub($available, $requiredReserve, 2);

        $this->logger->debug('Budget check', [
            'balance' => $balance,
            'price' => $price,
            'reserved' => $reserved,
            'invested' => $invested,
            'required_reserve' => $requiredReserve,
            'available' => $available,
        ]);

        // Check 1: Would this breach hard floor?
        $balanceAfterPurchase = bcsub($balance, $price, 2);
        if (bccomp($balanceAfterPurchase, $hardFloor, 2) <= 0) {
            $this->logger->info('Purchase rejected: would breach hard floor', [
                'price' => $price,
                'balance_after' => $balanceAfterPurchase,
                'hard_floor' => $hardFloor,
            ]);
            return false;
        }

        // Check 2: Exceeds per-trade limit?
        $maxPerTrade = bcmul($balance, $maxRiskPerTrade, 2);
        if (bccomp($price, $maxPerTrade, 2) > 0) {
            $this->logger->info('Purchase rejected: exceeds per-trade limit', [
                'price' => $price,
                'max_per_trade' => $maxPerTrade,
            ]);
            return false;
        }

        // Check 3: Exceeds total exposure?
        $newTotalExposure = bcadd($invested, $price, 2);
        $maxExposure = bcmul($balance, $maxTotalExposure, 2);
        if (bccomp($newTotalExposure, $maxExposure, 2) > 0) {
            $this->logger->info('Purchase rejected: exceeds total exposure limit', [
                'price' => $price,
                'new_total_exposure' => $newTotalExposure,
                'max_exposure' => $maxExposure,
            ]);
            return false;
        }

        // Check 4: Available balance sufficient?
        if (bccomp($price, $available, 2) > 0) {
            $this->logger->info('Purchase rejected: insufficient available balance', [
                'price' => $price,
                'available' => $available,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Reserve budget for a pending purchase
     * Prevents concurrent purchases from overspending
     */
    public function reserve(string $amount, string $identifier): void
    {
        if (isset($this->reservations[$identifier])) {
            throw new \LogicException("Reservation '{$identifier}' already exists");
        }

        $this->reservations[$identifier] = [
            'amount' => $amount,
            'timestamp' => time(),
        ];

        $this->logger->debug('Budget reserved', [
            'identifier' => $identifier,
            'amount' => $amount,
            'total_reserved' => $this->getTotalReserved(),
        ]);
    }

    /**
     * Release a budget reservation
     */
    public function release(string $identifier): void
    {
        if (!isset($this->reservations[$identifier])) {
            $this->logger->warning('Attempted to release non-existent reservation', [
                'identifier' => $identifier,
            ]);
            return;
        }

        $amount = $this->reservations[$identifier]['amount'];
        unset($this->reservations[$identifier]);

        $this->logger->debug('Budget released', [
            'identifier' => $identifier,
            'amount' => $amount,
            'total_reserved' => $this->getTotalReserved(),
        ]);
    }

    /**
     * Get current trading state based on balance and floors
     */
    public function getTradingState(): string
    {
        $balance = $this->getCurrentBalance();
        $hardFloor = $this->getHardFloor();
        $softFloor = $this->getSoftFloor();
        $conservativeThreshold = bcmul($softFloor, '1.2', 2);

        // At or below hard floor = LOCKDOWN
        if (bccomp($balance, $hardFloor, 2) <= 0) {
            return 'lockdown';
        }

        // At or below soft floor = EMERGENCY
        if (bccomp($balance, $softFloor, 2) <= 0) {
            return 'emergency';
        }

        // Between soft floor and soft floor * 1.2 = CONSERVATIVE
        if (bccomp($balance, $conservativeThreshold, 2) <= 0) {
            return 'conservative';
        }

        // Above conservative threshold = NORMAL
        return 'normal';
    }

    /**
     * Refresh balance from API and create snapshot
     */
    public function refreshBalance(): BalanceSnapshot
    {
        $this->logger->info('Refreshing balance from API');

        // Fetch from API
        $balance = (string) $this->skinBaronClient->getBalance();
        
        // Get current data
        $investedAmount = $this->getTotalInvested();
        $inventoryCount = $this->inventoryRepo->count(['status' => ['holding', 'listed']]);
        $tradingState = $this->getTradingState();

        // Calculate realized profits
        $today = new \DateTimeImmutable('today');
        $weekAgo = new \DateTimeImmutable('-7 days');
        $monthAgo = new \DateTimeImmutable('-30 days');

        $realizedProfitToday = $this->inventoryRepo->getTotalProfitInDateRange($today, new \DateTimeImmutable());
        $realizedProfitWeek = $this->inventoryRepo->getTotalProfitInDateRange($weekAgo, new \DateTimeImmutable());
        $realizedProfitMonth = $this->inventoryRepo->getTotalProfitInDateRange($monthAgo, new \DateTimeImmutable());
        
        // Total profit is sum of all sold items
        $realizedProfitTotal = $this->inventoryRepo->createQueryBuilder('i')
            ->select('SUM(i.netProfit)')
            ->where('i.status = :status')
            ->setParameter('status', 'sold')
            ->getQuery()
            ->getSingleScalarResult() ?? '0.00';

        // Calculate available balance
        $reserved = $this->getTotalReserved();
        $minReserve = bcmul($balance, $this->getMinReserve(), 2);
        $availableBalance = bcsub($balance, $reserved, 2);
        $availableBalance = bcsub($availableBalance, $minReserve, 2);

        // Create snapshot
        $snapshot = new BalanceSnapshot();
        $snapshot->setBalance($balance);
        $snapshot->setAvailableBalance($availableBalance);
        $snapshot->setReservedAmount($reserved);
        $snapshot->setInvestedAmount($investedAmount);
        $snapshot->setInventoryCount($inventoryCount);
        $snapshot->setRealizedProfitToday($realizedProfitToday);
        $snapshot->setRealizedProfitWeek($realizedProfitWeek);
        $snapshot->setRealizedProfitMonth($realizedProfitMonth);
        $snapshot->setRealizedProfitTotal($realizedProfitTotal);
        $snapshot->setTradingState($tradingState);
        $snapshot->setHardFloor($this->getHardFloor());
        $snapshot->setSoftFloor($this->getSoftFloor());

        // Note: inventoryMarketValue and unrealizedProfit would require fetching current prices
        // We'll skip that for now to avoid API calls. Can be calculated separately if needed.
        $snapshot->setInventoryMarketValue('0.00');
        $snapshot->setUnrealizedProfit('0.00');

        $em = $this->snapshotRepo->getEntityManager();
        $em->persist($snapshot);
        $em->flush();

        $this->logger->info('Balance refreshed', [
            'balance' => $balance,
            'available' => $availableBalance,
            'invested' => $investedAmount,
            'trading_state' => $tradingState,
        ]);

        return $snapshot;
    }

    /**
     * Get current balance (from latest snapshot or API)
     */
    public function getCurrentBalance(): string
    {
        $latestSnapshot = $this->snapshotRepo->findLatest();
        
        // If snapshot is recent (< 5 minutes), use it
        if ($latestSnapshot) {
            $age = (new \DateTimeImmutable())->getTimestamp() - $latestSnapshot->getSnapshotDate()->getTimestamp();
            if ($age < 300) { // 5 minutes
                return $latestSnapshot->getBalance();
            }
        }

        // Otherwise, fetch from API
        return (string) $this->skinBaronClient->getBalance();
    }

    /**
     * Get total amount currently reserved
     */
    private function getTotalReserved(): string
    {
        $total = '0.00';
        foreach ($this->reservations as $reservation) {
            $total = bcadd($total, $reservation['amount'], 2);
        }
        return $total;
    }

    /**
     * Get total amount currently invested in inventory
     */
    private function getTotalInvested(): string
    {
        return $this->inventoryRepo->getTotalInvested();
    }

    // Configuration getters with defaults

    private function getHardFloor(): string
    {
        return $this->configRepo->getStringValue(self::HARD_FLOOR_KEY, '10.00');
    }

    private function getSoftFloor(): string
    {
        return $this->configRepo->getStringValue(self::SOFT_FLOOR_KEY, '12.00');
    }

    private function getMaxRiskPerTrade(): string
    {
        return $this->configRepo->getStringValue(self::MAX_RISK_PER_TRADE_KEY, '0.05');
    }

    private function getMaxTotalExposure(): string
    {
        return $this->configRepo->getStringValue(self::MAX_TOTAL_EXPOSURE_KEY, '0.70');
    }

    private function getMinReserve(): string
    {
        return $this->configRepo->getStringValue(self::MIN_RESERVE_PCT_KEY, '0.20');
    }
}