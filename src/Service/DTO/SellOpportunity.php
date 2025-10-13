<?php

namespace App\Service\DTO;

/**
 * Represents a validated sell opportunity
 */
class SellOpportunity
{
    public function __construct(
        public readonly int $inventoryId,
        public readonly string $saleId,
        public readonly string $marketHashName,
        public readonly string $action, // 'list' or 'adjust'
        public readonly string $listPrice,
        public readonly string $reason,
        public readonly \DateTimeImmutable $timestamp = new \DateTimeImmutable(),
    ) {
    }

    public function toArray(): array
    {
        return [
            'inventory_id' => $this->inventoryId,
            'sale_id' => $this->saleId,
            'market_hash_name' => $this->marketHashName,
            'action' => $this->action,
            'list_price' => $this->listPrice,
            'reason' => $this->reason,
            'timestamp' => $this->timestamp->format('Y-m-d H:i:s'),
        ];
    }
}