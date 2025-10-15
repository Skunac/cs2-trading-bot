<?php

namespace App\Message;

/**
 * Message for async execution of sell opportunities
 */
class SellOpportunityMessage
{
    public function __construct(
        public readonly int $inventoryId,
        public readonly string $saleId,
        public readonly string $marketHashName,
        public readonly string $action, // 'list' or 'adjust'
        public readonly string $listPrice,
        public readonly string $reason,
    ) {
    }
}