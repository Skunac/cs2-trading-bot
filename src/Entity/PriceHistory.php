<?php

namespace App\Entity;

use App\Repository\PriceHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PriceHistoryRepository::class)]
#[ORM\Table(name: 'price_history')]
#[ORM\Index(columns: ['market_hash_name', 'timestamp'], name: 'idx_market_timestamp')]
#[ORM\Index(columns: ['timestamp'], name: 'idx_timestamp')]
class PriceHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $marketHashName;

    /**
     * Current lowest listing price at time of scraping
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $price;

    /**
     * Number of active listings at this price point (if available from API)
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $listingsCount = null;

    /**
     * Timestamp when this price was recorded
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $timestamp;

    public function __construct()
    {
        $this->timestamp = new \DateTimeImmutable();
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

    public function getListingsCount(): ?int
    {
        return $this->listingsCount;
    }

    public function setListingsCount(?int $listingsCount): self
    {
        $this->listingsCount = $listingsCount;
        return $this;
    }

    public function getTimestamp(): \DateTimeImmutable
    {
        return $this->timestamp;
    }

    public function setTimestamp(\DateTimeImmutable $timestamp): self
    {
        $this->timestamp = $timestamp;
        return $this;
    }
}