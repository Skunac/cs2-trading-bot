<?php

namespace App\Entity;

use App\Repository\AlertRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AlertRepository::class)]
#[ORM\Table(name: 'alerts')]
#[ORM\Index(columns: ['alert_type'], name: 'idx_alert_type')]
#[ORM\Index(columns: ['severity'], name: 'idx_severity')]
#[ORM\Index(columns: ['resolved'], name: 'idx_resolved')]
#[ORM\Index(columns: ['created_at'], name: 'idx_created_at')]
#[ORM\HasLifecycleCallbacks]
class Alert
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Type of alert:
     * - balance_floor: Approaching or at balance floor
     * - api_error: API communication error
     * - circuit_breaker: Circuit breaker opened
     * - profitable_trade: Successful sale with good profit
     * - unprofitable_trade: Sale with loss or low profit
     * - system_error: Internal system error
     * - rate_limit: Rate limit exceeded
     * - high_risk: High-risk trade executed
     * - inventory_full: Inventory limits reached
     * - maintenance: Maintenance mode activated
     */
    #[ORM\Column(length: 50)]
    private string $alertType;

    /**
     * Severity level:
     * - low: Informational (profitable trade, etc.)
     * - medium: Warning (conservative zone, rate limit)
     * - high: Urgent (emergency zone, unprofitable trade)
     * - critical: Requires immediate action (lockdown, circuit breaker)
     */
    #[ORM\Column(length: 20)]
    private string $severity;

    /**
     * Alert title (short summary)
     */
    #[ORM\Column(length: 255)]
    private string $title;

    /**
     * Detailed message
     */
    #[ORM\Column(type: Types::TEXT)]
    private string $message;

    /**
     * Related entity type (e.g., 'inventory', 'transaction', 'balance')
     */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $relatedEntityType = null;

    /**
     * Related entity ID (for linking to specific records)
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $relatedEntityId = null;

    /**
     * Additional context data (JSON)
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $context = null;

    /**
     * Has this alert been resolved?
     */
    #[ORM\Column]
    private bool $resolved = false;

    /**
     * When the alert was resolved (if applicable)
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    /**
     * Resolution notes
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $resolutionNotes = null;

    /**
     * Was notification sent? (webhook, email, etc.)
     */
    #[ORM\Column]
    private bool $notificationSent = false;

    /**
     * Notification channels used (e.g., ['webhook', 'email'])
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $notificationChannels = null;

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
     * Mark alert as resolved
     */
    public function markResolved(string $notes = null): void
    {
        $this->resolved = true;
        $this->resolvedAt = new \DateTimeImmutable();
        $this->resolutionNotes = $notes;
    }

    /**
     * Mark notification as sent
     */
    public function markNotificationSent(array $channels): void
    {
        $this->notificationSent = true;
        $this->notificationChannels = $channels;
    }

    /**
     * Check if alert is critical
     */
    public function isCritical(): bool
    {
        return $this->severity === 'critical';
    }

    /**
     * Check if alert requires immediate action
     */
    public function requiresImmediateAction(): bool
    {
        return in_array($this->severity, ['critical', 'high'], true) && !$this->resolved;
    }

    // Static factory methods for common alerts

    public static function balanceFloor(string $state, string $balance, string $floor): self
    {
        $alert = new self();
        $alert->alertType = 'balance_floor';
        $alert->severity = $state === 'lockdown' ? 'critical' : 'high';
        $alert->title = match($state) {
            'lockdown' => 'LOCKDOWN: Balance at hard floor',
            'emergency' => 'EMERGENCY: Balance at soft floor',
            'conservative' => 'WARNING: Balance in conservative zone',
            default => 'Balance warning',
        };
        $alert->message = sprintf(
            'Balance is €%s, %s floor is €%s. Trading restrictions in effect.',
            $balance,
            $state === 'lockdown' ? 'hard' : 'soft',
            $floor
        );
        $alert->context = [
            'state' => $state,
            'balance' => $balance,
            'floor' => $floor,
        ];
        return $alert;
    }

    public static function profitableTrade(string $marketHashName, string $profit, string $profitPct): self
    {
        $alert = new self();
        $alert->alertType = 'profitable_trade';
        $alert->severity = 'low';
        $alert->title = 'Profitable sale completed';
        $alert->message = sprintf(
            'Sold %s with €%s profit (%s%%)',
            $marketHashName,
            $profit,
            $profitPct
        );
        $alert->context = [
            'market_hash_name' => $marketHashName,
            'profit' => $profit,
            'profit_pct' => $profitPct,
        ];
        return $alert;
    }

    public static function apiError(string $endpoint, string $error): self
    {
        $alert = new self();
        $alert->alertType = 'api_error';
        $alert->severity = 'medium';
        $alert->title = 'API Error';
        $alert->message = sprintf(
            'Error calling %s: %s',
            $endpoint,
            $error
        );
        $alert->context = [
            'endpoint' => $endpoint,
            'error' => $error,
        ];
        return $alert;
    }

    public static function circuitBreakerOpen(): self
    {
        $alert = new self();
        $alert->alertType = 'circuit_breaker';
        $alert->severity = 'critical';
        $alert->title = 'Circuit Breaker Opened';
        $alert->message = 'Circuit breaker opened due to repeated API failures. Trading paused.';
        return $alert;
    }

    // Getters and Setters

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAlertType(): string
    {
        return $this->alertType;
    }

    public function setAlertType(string $alertType): self
    {
        $this->alertType = $alertType;
        return $this;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function setSeverity(string $severity): self
    {
        $validSeverities = ['low', 'medium', 'high', 'critical'];
        if (!in_array($severity, $validSeverities, true)) {
            throw new \InvalidArgumentException('Invalid severity: ' . $severity);
        }
        $this->severity = $severity;
        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): self
    {
        $this->message = $message;
        return $this;
    }

    public function getRelatedEntityType(): ?string
    {
        return $this->relatedEntityType;
    }

    public function setRelatedEntityType(?string $relatedEntityType): self
    {
        $this->relatedEntityType = $relatedEntityType;
        return $this;
    }

    public function getRelatedEntityId(): ?int
    {
        return $this->relatedEntityId;
    }

    public function setRelatedEntityId(?int $relatedEntityId): self
    {
        $this->relatedEntityId = $relatedEntityId;
        return $this;
    }

    public function getContext(): ?array
    {
        return $this->context;
    }

    public function setContext(?array $context): self
    {
        $this->context = $context;
        return $this;
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }

    public function setResolved(bool $resolved): self
    {
        $this->resolved = $resolved;
        return $this;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function setResolvedAt(?\DateTimeImmutable $resolvedAt): self
    {
        $this->resolvedAt = $resolvedAt;
        return $this;
    }

    public function getResolutionNotes(): ?string
    {
        return $this->resolutionNotes;
    }

    public function setResolutionNotes(?string $resolutionNotes): self
    {
        $this->resolutionNotes = $resolutionNotes;
        return $this;
    }

    public function isNotificationSent(): bool
    {
        return $this->notificationSent;
    }

    public function setNotificationSent(bool $notificationSent): self
    {
        $this->notificationSent = $notificationSent;
        return $this;
    }

    public function getNotificationChannels(): ?array
    {
        return $this->notificationChannels;
    }

    public function setNotificationChannels(?array $notificationChannels): self
    {
        $this->notificationChannels = $notificationChannels;
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