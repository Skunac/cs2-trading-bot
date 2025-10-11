<?php

namespace App\Entity;

use App\Repository\SaleHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SaleHistoryRepository::class)]
#[ORM\Table(name: 'sale_history')]
#[ORM\Index(columns: ['market_hash_name', 'date_sold'], name: 'idx_market_date')]
#[ORM\Index(columns: ['date_sold'], name: 'idx_date_sold')]
#[ORM\Index(columns: ['fetched_at'], name: 'idx_fetched_at')]
#[ORM\UniqueConstraint(name: 'unique_sale', columns: ['market_hash_name', 'date_sold', 'price'])]
class SaleHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Full market hash name including wear category
     * e.g., "AK-47 | Redline (Field-Tested)"
     */
    #[ORM\Column(length: 255)]
    private string $marketHashName;

    /**
     * Actual sale price (what someone paid)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $price;

    /**
     * When this item was sold (from API)
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dateSold;

    /**
     * When we fetched this data (for deduplication)
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $fetchedAt;

    public function __construct()
    {
        $this->fetchedAt = new \DateTimeImmutable();
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

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): self
    {
        $this->price = $price;
        return $this;
    }

    public function getDateSold(): \DateTimeImmutable
    {
        return $this->dateSold;
    }

    public function setDateSold(\DateTimeImmutable $dateSold): self
    {
        $this->dateSold = $dateSold;
        return $this;
    }

    public function getFetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    public function setFetchedAt(\DateTimeImmutable $fetchedAt): self
    {
        $this->fetchedAt = $fetchedAt;
        return $this;
    }
}