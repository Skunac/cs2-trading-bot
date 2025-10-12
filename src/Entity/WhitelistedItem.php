<?php

namespace App\Entity;

use App\Repository\WhitelistedItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WhitelistedItemRepository::class)]
#[ORM\Table(name: 'whitelisted_items')]
#[ORM\Index(columns: ['market_hash_name'], name: 'idx_whitelisted_items_market_hash_name')]
#[ORM\Index(columns: ['is_active'], name: 'idx_is_active')]
#[ORM\Index(columns: ['tier'], name: 'idx_tier')]
#[ORM\HasLifecycleCallbacks]
class WhitelistedItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private string $marketHashName;

    /**
     * Tier 1: High liquidity (30+ sales/day), €0.50-€20
     * Tier 2: Medium liquidity (10+ sales/day), €20-€100
     */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $tier = 1;

    /**
     * Minimum discount percentage required to consider buying
     * Default: 20% for Tier 1, 25% for Tier 2
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $minDiscountPct = '20.00';

    /**
     * Minimum spread between current listing and next cheapest
     * Default: 15%
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $minSpreadPct = '15.00';

    /**
     * Target profit margin after fees (default: 10%)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $targetProfitPct = '10.00';

    /**
     * Maximum number of this item we can hold at once
     */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $maxHoldings = 3;

    /**
     * Whether this item is currently approved for trading
     */
    #[ORM\Column]
    private bool $isActive = true;

    /**
     * Optional notes about this item (why it's whitelisted, special considerations)
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

    // Getters and Setters

    public function getId(): ?int
    {
        return $this->id;
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

    public function getTier(): int
    {
        return $this->tier;
    }

    public function setTier(int $tier): self
    {
        if ($tier < 1 || $tier > 2) {
            throw new \InvalidArgumentException('Tier must be 1 or 2');
        }
        $this->tier = $tier;
        return $this;
    }

    public function getMinDiscountPct(): string
    {
        return $this->minDiscountPct;
    }

    public function setMinDiscountPct(string $minDiscountPct): self
    {
        $this->minDiscountPct = $minDiscountPct;
        return $this;
    }

    public function getMinSpreadPct(): string
    {
        return $this->minSpreadPct;
    }

    public function setMinSpreadPct(string $minSpreadPct): self
    {
        $this->minSpreadPct = $minSpreadPct;
        return $this;
    }

    public function getTargetProfitPct(): string
    {
        return $this->targetProfitPct;
    }

    public function setTargetProfitPct(string $targetProfitPct): self
    {
        $this->targetProfitPct = $targetProfitPct;
        return $this;
    }

    public function getMaxHoldings(): int
    {
        return $this->maxHoldings;
    }

    public function setMaxHoldings(int $maxHoldings): self
    {
        $this->maxHoldings = $maxHoldings;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
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