<?php

namespace App\MessageHandler;

use App\Message\BuyOpportunityMessage;
use App\Service\BuyExecutor;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles buy opportunity messages from the queue
 */
class BuyOpportunityHandler
{
    public function __construct(
        private readonly BuyExecutor $buyExecutor,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(BuyOpportunityMessage $message): void
    {
        $this->logger->info('Processing buy opportunity message', [
            'sale_id' => $message->saleId,
            'market_hash_name' => $message->marketHashName,
            'price' => $message->currentPrice,
        ]);

        try {
            $this->buyExecutor->execute(
                saleId: $message->saleId,
                marketHashName: $message->marketHashName,
                currentPrice: $message->currentPrice,
                targetSellPrice: $message->targetSellPrice,
                expectedProfit: $message->expectedProfit,
                riskScore: $message->riskScore,
                tier: $message->tier
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to process buy opportunity', [
                'sale_id' => $message->saleId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw to trigger Messenger retry
            throw $e;
        }
    }
}