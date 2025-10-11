<?php

namespace App\Entity;

use App\Repository\SalesStatsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SalesStatsRepository::class)]
#[ORM\Table(name: 'sales_stats')]
#[ORM\UniqueConstraint(name: 'unique_market_hash_name', columns: ['market_hash_name'])]
#[ORM\Index(columns: ['updated_at'], name: 'idx_updated_at')]
#[ORM\HasLifecycleCallbacks]
class SalesStats
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Full market hash name including wear category
     * e.g., "AK-47 | Redline (Field-Tested)"
     */
    #[ORM\Column(length: 255, unique: true)]
    private string $marketHashName;

    /**
     * Average price over last 7 days (from actual sales)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $avgPrice7d = null;

    /**
     * Average price over last 30 days (from actual sales)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $avgPrice30d = null;

    /**
     * Median price over last 30 days (more resistant to outliers)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $medianPrice30d = null;

    /**
     * Lowest sale price in last 30 days
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $minPrice30d = null;

    /**
     * Highest sale price in last 30 days
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $maxPrice30d = null;

    /**
     * Standard deviation of sale prices (volatility indicator)
     * Higher = more volatile = higher risk
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $priceVolatility = null;

    /**
     * Number of sales in last 7 days (liquidity indicator)
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $salesCount7d = 0;

    /**
     * Number of sales in last 30 days (liquidity indicator)
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $salesCount30d = 0;

    /**
     * Average sales per day (30-day basis)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $avgSalesPerDay = null;

    /**
     * Number of sale data points used for calculations
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $dataPoints = 0;

    /**
     * Most recent sale price from sale_history
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $lastSalePrice = null;

    /**
     * Date of most recent sale
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSaleDate = null;

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
     * Check if item is liquid enough (minimum 2 sales per day)
     */
    public function isLiquid(): bool
    {
        return $this->avgSalesPerDay !== null && bccomp($this->avgSalesPerDay, '2.0', 2) >= 0;
    }

    /**
     * Check if item is highly liquid (10+ sales per day)
     */
    public function isHighlyLiquid(): bool
    {
        return $this->avgSalesPerDay !== null && bccomp($this->avgSalesPerDay, '10.0', 2) >= 0;
    }

    /**
     * Check if we have enough data for reliable statistics (min 10 sales)
     */
    public function hasReliableData(): bool
    {
        return $this->salesCount30d >= 10;
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

    public function getAvgPrice7d(): ?string
    {
        return $this->avgPrice7d;
    }

    public function setAvgPrice7d(?string $avgPrice7d): self
    {
        $this->avgPrice7d = $avgPrice7d;
        return $this;
    }

    public function getAvgPrice30d(): ?string
    {
        return $this->avgPrice30d;
    }

    public function setAvgPrice30d(?string $avgPrice30d): self
    {
        $this->avgPrice30d = $avgPrice30d;
        return $this;
    }

    public function getMedianPrice30d(): ?string
    {
        return $this->medianPrice30d;
    }

    public function setMedianPrice30d(?string $medianPrice30d): self
    {
        $this->medianPrice30d = $medianPrice30d;
        return $this;
    }

    public function getMinPrice30d(): ?string
    {
        return $this->minPrice30d;
    }

    public function setMinPrice30d(?string $minPrice30d): self
    {
        $this->minPrice30d = $minPrice30d;
        return $this;
    }

    public function getMaxPrice30d(): ?string
    {
        return $this->maxPrice30d;
    }

    public function setMaxPrice30d(?string $maxPrice30d): self
    {
        $this->maxPrice30d = $maxPrice30d;
        return $this;
    }

    public function getPriceVolatility(): ?string
    {
        return $this->priceVolatility;
    }

    public function setPriceVolatility(?string $priceVolatility): self
    {
        $this->priceVolatility = $priceVolatility;
        return $this;
    }

    public function getSalesCount7d(): int
    {
        return $this->salesCount7d;
    }

    public function setSalesCount7d(int $salesCount7d): self
    {
        $this->salesCount7d = $salesCount7d;
        return $this;
    }

    public function getSalesCount30d(): int
    {
        return $this->salesCount30d;
    }

    public function setSalesCount30d(int $salesCount30d): self
    {
        $this->salesCount30d = $salesCount30d;
        return $this;
    }

    public function getAvgSalesPerDay(): ?string
    {
        return $this->avgSalesPerDay;
    }

    public function setAvgSalesPerDay(?string $avgSalesPerDay): self
    {
        $this->avgSalesPerDay = $avgSalesPerDay;
        return $this;
    }

    public function getDataPoints(): int
    {
        return $this->dataPoints;
    }

    public function setDataPoints(int $dataPoints): self
    {
        $this->dataPoints = $dataPoints;
        return $this;
    }

    public function getLastSalePrice(): ?string
    {
        return $this->lastSalePrice;
    }

    public function setLastSalePrice(?string $lastSalePrice): self
    {
        $this->lastSalePrice = $lastSalePrice;
        return $this;
    }

    public function getLastSaleDate(): ?\DateTimeImmutable
    {
        return $this->lastSaleDate;
    }

    public function setLastSaleDate(?\DateTimeImmutable $lastSaleDate): self
    {
        $this->lastSaleDate = $lastSaleDate;
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