<?php

namespace App\Entity;

use App\Repository\TransactionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TransactionRepository::class)]
#[ORM\Table(name: 'transactions')]
#[ORM\Index(columns: ['transaction_type'], name: 'idx_transaction_type')]
#[ORM\Index(columns: ['market_hash_name'], name: 'idx_transactions_market_hash_name')]
#[ORM\Index(columns: ['transaction_date'], name: 'idx_transaction_date')]
#[ORM\Index(columns: ['status'], name: 'idx_transactions_status')]
#[ORM\HasLifecycleCallbacks]
class Transaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Type of transaction: 'buy' or 'sell'
     */
    #[ORM\Column(length: 10)]
    private string $transactionType;

    #[ORM\Column(length: 255)]
    private string $marketHashName;

    /**
     * SkinBaron sale ID (buy) or item ID (sell)
     */
    #[ORM\Column(type: Types::BIGINT)]
    private string $externalId;

    /**
     * Reference to inventory record (nullable for failed transactions)
     */
    #[ORM\ManyToOne(targetEntity: Inventory::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Inventory $inventory = null;

    /**
     * Transaction price (before fees)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $price;

    /**
     * Marketplace fee charged (0 for buys, 15% for sells)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $fee = '0.00';

    /**
     * Net amount:
     * - For buys: negative (money spent)
     * - For sells: positive (money received after fee)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $netAmount;

    /**
     * Balance before this transaction
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $balanceBefore;

    /**
     * Balance after this transaction
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $balanceAfter;

    /**
     * Transaction status:
     * - pending: Created but not confirmed
     * - completed: Successfully executed
     * - failed: Failed to execute
     * - cancelled: Manually cancelled
     */
    #[ORM\Column(length: 20)]
    private string $status = 'pending';

    /**
     * When the transaction occurred
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $transactionDate;

    /**
     * Error message if transaction failed
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    /**
     * Additional metadata (API response, debug info, etc.)
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->transactionDate = new \DateTimeImmutable();
    }

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
     * Create a buy transaction
     */
    public static function createBuy(
        string $marketHashName,
        string $saleId,
        string $price,
        string $balanceBefore,
        ?Inventory $inventory = null
    ): self {
        $transaction = new self();
        $transaction->transactionType = 'buy';
        $transaction->marketHashName = $marketHashName;
        $transaction->externalId = $saleId;
        $transaction->inventory = $inventory;
        $transaction->price = $price;
        $transaction->fee = '0.00';
        $transaction->netAmount = '-' . $price; // Negative = money spent
        $transaction->balanceBefore = $balanceBefore;
        $transaction->balanceAfter = bcsub($balanceBefore, $price, 2);
        
        return $transaction;
    }

    /**
     * Create a sell transaction
     */
    public static function createSell(
        string $marketHashName,
        string $itemId,
        string $soldPrice,
        string $fee,
        string $balanceBefore,
        ?Inventory $inventory = null
    ): self {
        $transaction = new self();
        $transaction->transactionType = 'sell';
        $transaction->marketHashName = $marketHashName;
        $transaction->externalId = $itemId;
        $transaction->inventory = $inventory;
        $transaction->price = $soldPrice;
        $transaction->fee = $fee;
        $transaction->netAmount = bcsub($soldPrice, $fee, 2); // Positive = money received
        $transaction->balanceBefore = $balanceBefore;
        $transaction->balanceAfter = bcadd($balanceBefore, $transaction->netAmount, 2);
        
        return $transaction;
    }

    /**
     * Mark transaction as completed
     */
    public function markCompleted(): void
    {
        $this->status = 'completed';
    }

    /**
     * Mark transaction as failed with error message
     */
    public function markFailed(string $errorMessage): void
    {
        $this->status = 'failed';
        $this->errorMessage = $errorMessage;
    }

    // Getters and Setters

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTransactionType(): string
    {
        return $this->transactionType;
    }

    public function setTransactionType(string $transactionType): self
    {
        if (!in_array($transactionType, ['buy', 'sell'], true)) {
            throw new \InvalidArgumentException('Invalid transaction type: ' . $transactionType);
        }
        $this->transactionType = $transactionType;
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

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function setExternalId(string $externalId): self
    {
        $this->externalId = $externalId;
        return $this;
    }

    public function getInventory(): ?Inventory
    {
        return $this->inventory;
    }

    public function setInventory(?Inventory $inventory): self
    {
        $this->inventory = $inventory;
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

    public function getFee(): string
    {
        return $this->fee;
    }

    public function setFee(string $fee): self
    {
        $this->fee = $fee;
        return $this;
    }

    public function getNetAmount(): string
    {
        return $this->netAmount;
    }

    public function setNetAmount(string $netAmount): self
    {
        $this->netAmount = $netAmount;
        return $this;
    }

    public function getBalanceBefore(): string
    {
        return $this->balanceBefore;
    }

    public function setBalanceBefore(string $balanceBefore): self
    {
        $this->balanceBefore = $balanceBefore;
        return $this;
    }

    public function getBalanceAfter(): string
    {
        return $this->balanceAfter;
    }

    public function setBalanceAfter(string $balanceAfter): self
    {
        $this->balanceAfter = $balanceAfter;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $validStatuses = ['pending', 'completed', 'failed', 'cancelled'];
        if (!in_array($status, $validStatuses, true)) {
            throw new \InvalidArgumentException('Invalid status: ' . $status);
        }
        $this->status = $status;
        return $this;
    }

    public function getTransactionDate(): \DateTimeImmutable
    {
        return $this->transactionDate;
    }

    public function setTransactionDate(\DateTimeImmutable $transactionDate): self
    {
        $this->transactionDate = $transactionDate;
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;
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