<?php

namespace App\Entity;

use App\Repository\InventoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InventoryRepository::class)]
#[ORM\Table(name: 'inventory')]
#[ORM\Index(columns: ['market_hash_name'], name: 'idx_inventory_market_hash_name')]
#[ORM\Index(columns: ['status'], name: 'idx_inventory_status')]
#[ORM\Index(columns: ['purchase_date'], name: 'idx_purchase_date')]
#[ORM\Index(columns: ['sold_date'], name: 'idx_sold_date')]
#[ORM\HasLifecycleCallbacks]
class Inventory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * SkinBaron sale ID from when we purchased
     */
    #[ORM\Column(type: Types::BIGINT, unique: true)]
    private string $saleId;

    /**
     * SkinBaron item ID (used for listing operations)
     */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?string $itemId = null;

    #[ORM\Column(length: 255)]
    private string $marketHashName;

    /**
     * Price we paid for this item
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $purchasePrice;

    /**
     * When we purchased this item
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $purchaseDate;

    /**
     * Target selling price to achieve desired profit
     * Formula: (purchasePrice * 1.10) / 0.85
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $targetSellPrice;

    /**
     * Current status:
     * - holding: Purchased, waiting to list
     * - listed: Currently listed on marketplace
     * - sold: Successfully sold
     * - failed: Failed to list or other error
     */
    #[ORM\Column(length: 20)]
    private string $status = 'holding';

    /**
     * Price at which item is currently listed (null if not listed)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $listedPrice = null;

    /**
     * When item was listed (null if never listed)
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $listedDate = null;

    /**
     * Actual sale price (null if not sold)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $soldPrice = null;

    /**
     * When item was sold (null if not sold)
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $soldDate = null;

    /**
     * Marketplace fee charged on sale
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $fee = null;

    /**
     * Net profit/loss after fees (null if not sold)
     * Positive = profit, Negative = loss
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $netProfit = null;

    /**
     * Profit percentage (null if not sold)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $profitPct = null;

    /**
     * Risk score at time of purchase (0-10)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 3, scale: 1)]
    private string $riskScore = '0.0';

    /**
     * Additional notes (e.g., reason for stop-loss, manual intervention)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // Helper methods

    /**
     * Calculate how many days we've been holding this item
     */
    public function getHoldingDays(): int
    {
        $endDate = $this->soldDate ?? new \DateTimeImmutable();
        return $this->purchaseDate->diff($endDate)->days;
    }

    /**
     * Check if item has been held too long (default: 7 days)
     */
    public function isHeldTooLong(int $maxDays = 7): bool
    {
        return $this->status === 'holding' && $this->getHoldingDays() >= $maxDays;
    }

    /**
     * Mark item as sold and calculate profit
     */
    public function markAsSold(string $soldPrice, string $fee): void
    {
        $this->status = 'sold';
        $this->soldPrice = $soldPrice;
        $this->soldDate = new \DateTimeImmutable();
        $this->fee = $fee;
        
        // Calculate net profit: (soldPrice - fee) - purchasePrice
        $netAmount = bcsub($soldPrice, $fee, 2);
        $this->netProfit = bcsub($netAmount, $this->purchasePrice, 2);
        
        // Calculate profit percentage
        if (bccomp($this->purchasePrice, '0', 2) !== 0) {
            $this->profitPct = bcmul(
                bcdiv($this->netProfit, $this->purchasePrice, 4),
                '100',
                2
            );
        }
    }

    // Getters and Setters

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSaleId(): string
    {
        return $this->saleId;
    }

    public function setSaleId(string $saleId): self
    {
        $this->saleId = $saleId;
        return $this;
    }

    public function getItemId(): ?string
    {
        return $this->itemId;
    }

    public function setItemId(?string $itemId): self
    {
        $this->itemId = $itemId;
        return $this;
    }

    public function getMarketHashName(): string
    {
        return $this->marketHashName;
    }

    public function setMarketHashName(string $marketHashName): self
    {
        $this->marketHashName = $marketHashName;
        return $this;
    }

    public function getPurchasePrice(): string
    {
        return $this->purchasePrice;
    }

    public function setPurchasePrice(string $purchasePrice): self
    {
        $this->purchasePrice = $purchasePrice;
        return $this;
    }

    public function getPurchaseDate(): \DateTimeImmutable
    {
        return $this->purchaseDate;
    }

    public function setPurchaseDate(\DateTimeImmutable $purchaseDate): self
    {
        $this->purchaseDate = $purchaseDate;
        return $this;
    }

    public function getTargetSellPrice(): string
    {
        return $this->targetSellPrice;
    }

    public function setTargetSellPrice(string $targetSellPrice): self
    {
        $this->targetSellPrice = $targetSellPrice;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $validStatuses = ['holding', 'listed', 'sold', 'failed'];
        if (!in_array($status, $validStatuses, true)) {
            throw new \InvalidArgumentException('Invalid status: ' . $status);
        }
        $this->status = $status;
        return $this;
    }

    public function getListedPrice(): ?string
    {
        return $this->listedPrice;
    }

    public function setListedPrice(?string $listedPrice): self
    {
        $this->listedPrice = $listedPrice;
        return $this;
    }

    public function getListedDate(): ?\DateTimeImmutable
    {
        return $this->listedDate;
    }

    public function setListedDate(?\DateTimeImmutable $listedDate): self
    {
        $this->listedDate = $listedDate;
        return $this;
    }

    public function getSoldPrice(): ?string
    {
        return $this->soldPrice;
    }

    public function setSoldPrice(?string $soldPrice): self
    {
        $this->soldPrice = $soldPrice;
        return $this;
    }

    public function getSoldDate(): ?\DateTimeImmutable
    {
        return $this->soldDate;
    }

    public function setSoldDate(?\DateTimeImmutable $soldDate): self
    {
        $this->soldDate = $soldDate;
        return $this;
    }

    public function getFee(): ?string
    {
        return $this->fee;
    }

    public function setFee(?string $fee): self
    {
        $this->fee = $fee;
        return $this;
    }

    public function getNetProfit(): ?string
    {
        return $this->netProfit;
    }

    public function setNetProfit(?string $netProfit): self
    {
        $this->netProfit = $netProfit;
        return $this;
    }

    public function getProfitPct(): ?string
    {
        return $this->profitPct;
    }

    public function setProfitPct(?string $profitPct): self
    {
        $this->profitPct = $profitPct;
        return $this;
    }

    public function getRiskScore(): string
    {
        return $this->riskScore;
    }

    public function setRiskScore(string $riskScore): self
    {
        $this->riskScore = $riskScore;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}