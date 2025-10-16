<?php

namespace App\MessageHandler;

use App\Message\SellOpportunityMessage;
use App\Service\SellExecutor;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles sell opportunity messages from the queue
 */
class SellOpportunityHandler
{
    public function __construct(
        private readonly SellExecutor $sellExecutor,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SellOpportunityMessage $message): void
    {
        $this->logger->info('Processing sell opportunity message', [
            'inventory_id' => $message->inventoryId,
            'sale_id' => $message->saleId,
            'action' => $message->action,
            'list_price' => $message->listPrice,
        ]);

        try {
            $this->sellExecutor->execute(
                inventoryId: $message->inventoryId,
                saleId: $message->saleId,
                marketHashName: $message->marketHashName,
                action: $message->action,
                listPrice: $message->listPrice,
                reason: $message->reason
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to process sell opportunity', [
                'inventory_id' => $message->inventoryId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw to trigger Messenger retry
            throw $e;
        }
    }
}