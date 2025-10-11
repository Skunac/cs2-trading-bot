<?php

namespace App\Entity;

use App\Repository\SystemConfigRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SystemConfigRepository::class)]
#[ORM\Table(name: 'system_config')]
#[ORM\UniqueConstraint(name: 'unique_config_key', columns: ['config_key'])]
#[ORM\HasLifecycleCallbacks]
class SystemConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Configuration key (unique identifier)
     */
    #[ORM\Column(name: 'config_key', length: 100, unique: true)]
    private string $key;

    /**
     * Configuration value (stored as string, cast as needed)
     */
    #[ORM\Column(name: 'config_value', type: Types::TEXT)]
    private string $value;

    /**
     * Data type: string, integer, float, boolean, json
     */
    #[ORM\Column(length: 20)]
    private string $type = 'string';

    /**
     * Human-readable description of this config
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Is this config editable via UI/API? (false = code-only)
     */
    #[ORM\Column]
    private bool $isEditable = true;

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

    // Helper methods for type casting

    /**
     * Get value as string
     */
    public function getStringValue(): string
    {
        return $this->value;
    }

    /**
     * Get value as integer
     */
    public function getIntValue(): int
    {
        return (int) $this->value;
    }

    /**
     * Get value as float
     */
    public function getFloatValue(): float
    {
        return (float) $this->value;
    }

    /**
     * Get value as boolean
     */
    public function getBoolValue(): bool
    {
        return filter_var($this->value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Get value as JSON-decoded array
     */
    public function getJsonValue(): array
    {
        return json_decode($this->value, true) ?? [];
    }

    /**
     * Get typed value based on type field
     */
    public function getTypedValue(): mixed
    {
        return match($this->type) {
            'integer' => $this->getIntValue(),
            'float' => $this->getFloatValue(),
            'boolean' => $this->getBoolValue(),
            'json' => $this->getJsonValue(),
            default => $this->getStringValue(),
        };
    }

    /**
     * Set value with automatic type detection
     */
    public function setTypedValue(mixed $value): self
    {
        if (is_bool($value)) {
            $this->type = 'boolean';
            $this->value = $value ? '1' : '0';
        } elseif (is_int($value)) {
            $this->type = 'integer';
            $this->value = (string) $value;
        } elseif (is_float($value)) {
            $this->type = 'float';
            $this->value = (string) $value;
        } elseif (is_array($value)) {
            $this->type = 'json';
            $this->value = json_encode($value);
        } else {
            $this->type = 'string';
            $this->value = (string) $value;
        }
        
        return $this;
    }

    // Getters and Setters

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): self
    {
        $this->key = $key;
        return $this;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): self
    {
        $this->value = $value;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $validTypes = ['string', 'integer', 'float', 'boolean', 'json'];
        if (!in_array($type, $validTypes, true)) {
            throw new \InvalidArgumentException('Invalid type: ' . $type);
        }
        $this->type = $type;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function isEditable(): bool
    {
        return $this->isEditable;
    }

    public function setIsEditable(bool $isEditable): self
    {
        $this->isEditable = $isEditable;
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