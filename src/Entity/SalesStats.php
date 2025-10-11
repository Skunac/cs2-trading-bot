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

    #[ORM\Column(length: 255, unique: true)]
    private string $marketHashName;

    /**
     * Average price over last 7 days
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $avgPrice7d = null;

    /**
     * Average price over last 30 days
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $avgPrice30d = null;

    /**
     * Median price over last 30 days (more resistant to outliers)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $medianPrice30d = null;

    /**
     * Lowest price in last 30 days
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $minPrice30d = null;

    /**
     * Highest price in last 30 days
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $maxPrice30d = null;

    /**
     * Standard deviation of price (volatility indicator)
     * Higher = more volatile = higher risk
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $priceVolatility = null;

    /**
     * Number of price data points used for calculations
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $dataPoints = 0;

    /**
     * Most recent price from price_history
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $currentPrice = null;

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

    public function getDataPoints(): int
    {
        return $this->dataPoints;
    }

    public function setDataPoints(int $dataPoints): self
    {
        $this->dataPoints = $dataPoints;
        return $this;
    }

    public function getCurrentPrice(): ?string
    {
        return $this->currentPrice;
    }

    public function setCurrentPrice(?string $currentPrice): self
    {
        $this->currentPrice = $currentPrice;
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