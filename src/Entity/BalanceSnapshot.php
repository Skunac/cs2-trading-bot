<?php

namespace App\Entity;

use App\Repository\BalanceSnapshotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BalanceSnapshotRepository::class)]
#[ORM\Table(name: 'balance_snapshots')]
#[ORM\Index(columns: ['snapshot_date'], name: 'idx_snapshot_date')]
#[ORM\HasLifecycleCallbacks]
class BalanceSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Total balance from SkinBaron API
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $balance;

    /**
     * Available balance (balance - reserved - invested)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $availableBalance;

    /**
     * Amount currently reserved for pending orders
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $reservedAmount = '0.00';

    /**
     * Total value of current inventory (purchase prices)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $investedAmount = '0.00';

    /**
     * Current market value of inventory (if sold at current prices)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $inventoryMarketValue = '0.00';

    /**
     * Unrealized profit: (inventoryMarketValue * 0.85) - investedAmount
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $unrealizedProfit = '0.00';

    /**
     * Number of items in inventory (holding + listed)
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $inventoryCount = 0;

    /**
     * Realized profit from completed sales today
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $realizedProfitToday = '0.00';

    /**
     * Realized profit from completed sales this week
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $realizedProfitWeek = '0.00';

    /**
     * Realized profit from completed sales this month
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $realizedProfitMonth = '0.00';

    /**
     * Total realized profit since bot started
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $realizedProfitTotal = '0.00';

    /**
     * Current trading state: normal, conservative, emergency, lockdown
     */
    #[ORM\Column(length: 20)]
    private string $tradingState = 'normal';

    /**
     * Hard floor threshold (absolute minimum)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $hardFloor = '10.00';

    /**
     * Soft floor threshold (warning level)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $softFloor = '12.00';

    /**
     * When this snapshot was taken
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $snapshotDate;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->snapshotDate = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // Helper methods

    /**
     * Calculate ROI (Return on Investment) as percentage
     */
    public function getRoi(): string
    {
        // Total portfolio value = balance + unrealized profit
        $portfolioValue = bcadd($this->balance, $this->unrealizedProfit, 2);
        
        // Starting capital = balance - realized profit total
        $startingCapital = bcsub($this->balance, $this->realizedProfitTotal, 2);
        
        if (bccomp($startingCapital, '0', 2) === 0) {
            return '0.00';
        }
        
        // ROI = ((portfolio value - starting capital) / starting capital) * 100
        $profit = bcsub($portfolioValue, $startingCapital, 2);
        return bcmul(
            bcdiv($profit, $startingCapital, 4),
            '100',
            2
        );
    }

    /**
     * Check if we're approaching or at floor
     */
    public function isAtHardFloor(): bool
    {
        return bccomp($this->balance, $this->hardFloor, 2) <= 0;
    }

    public function isAtSoftFloor(): bool
    {
        return bccomp($this->balance, $this->softFloor, 2) <= 0;
    }

    public function isInConservativeZone(): bool
    {
        $conservativeThreshold = bcmul($this->softFloor, '1.2', 2);
        return bccomp($this->balance, $this->softFloor, 2) > 0 
            && bccomp($this->balance, $conservativeThreshold, 2) <= 0;
    }

    // Getters and Setters

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBalance(): string
    {
        return $this->balance;
    }

    public function setBalance(string $balance): self
    {
        $this->balance = $balance;
        return $this;
    }

    public function getAvailableBalance(): string
    {
        return $this->availableBalance;
    }

    public function setAvailableBalance(string $availableBalance): self
    {
        $this->availableBalance = $availableBalance;
        return $this;
    }

    public function getReservedAmount(): string
    {
        return $this->reservedAmount;
    }

    public function setReservedAmount(string $reservedAmount): self
    {
        $this->reservedAmount = $reservedAmount;
        return $this;
    }

    public function getInvestedAmount(): string
    {
        return $this->investedAmount;
    }

    public function setInvestedAmount(string $investedAmount): self
    {
        $this->investedAmount = $investedAmount;
        return $this;
    }

    public function getInventoryMarketValue(): string
    {
        return $this->inventoryMarketValue;
    }

    public function setInventoryMarketValue(string $inventoryMarketValue): self
    {
        $this->inventoryMarketValue = $inventoryMarketValue;
        return $this;
    }

    public function getUnrealizedProfit(): string
    {
        return $this->unrealizedProfit;
    }

    public function setUnrealizedProfit(string $unrealizedProfit): self
    {
        $this->unrealizedProfit = $unrealizedProfit;
        return $this;
    }

    public function getInventoryCount(): int
    {
        return $this->inventoryCount;
    }

    public function setInventoryCount(int $inventoryCount): self
    {
        $this->inventoryCount = $inventoryCount;
        return $this;
    }

    public function getRealizedProfitToday(): string
    {
        return $this->realizedProfitToday;
    }

    public function setRealizedProfitToday(string $realizedProfitToday): self
    {
        $this->realizedProfitToday = $realizedProfitToday;
        return $this;
    }

    public function getRealizedProfitWeek(): string
    {
        return $this->realizedProfitWeek;
    }

    public function setRealizedProfitWeek(string $realizedProfitWeek): self
    {
        $this->realizedProfitWeek = $realizedProfitWeek;
        return $this;
    }

    public function getRealizedProfitMonth(): string
    {
        return $this->realizedProfitMonth;
    }

    public function setRealizedProfitMonth(string $realizedProfitMonth): self
    {
        $this->realizedProfitMonth = $realizedProfitMonth;
        return $this;
    }

    public function getRealizedProfitTotal(): string
    {
        return $this->realizedProfitTotal;
    }

    public function setRealizedProfitTotal(string $realizedProfitTotal): self
    {
        $this->realizedProfitTotal = $realizedProfitTotal;
        return $this;
    }

    public function getTradingState(): string
    {
        return $this->tradingState;
    }

    public function setTradingState(string $tradingState): self
    {
        $validStates = ['normal', 'conservative', 'emergency', 'lockdown'];
        if (!in_array($tradingState, $validStates, true)) {
            throw new \InvalidArgumentException('Invalid trading state: ' . $tradingState);
        }
        $this->tradingState = $tradingState;
        return $this;
    }

    public function getHardFloor(): string
    {
        return $this->hardFloor;
    }

    public function setHardFloor(string $hardFloor): self
    {
        $this->hardFloor = $hardFloor;
        return $this;
    }

    public function getSoftFloor(): string
    {
        return $this->softFloor;
    }

    public function setSoftFloor(string $softFloor): self
    {
        $this->softFloor = $softFloor;
        return $this;
    }

    public function getSnapshotDate(): \DateTimeImmutable
    {
        return $this->snapshotDate;
    }

    public function setSnapshotDate(\DateTimeImmutable $snapshotDate): self
    {
        $this->snapshotDate = $snapshotDate;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}