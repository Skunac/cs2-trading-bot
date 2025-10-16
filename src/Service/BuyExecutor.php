<?php

namespace App\Service;

use App\Entity\Inventory;
use App\Entity\Transaction;
use App\Repository\InventoryRepository;
use App\Repository\TransactionRepository;
use App\Service\SkinBaron\SkinBaronClient;
use App\Service\SkinBaron\Exception\SkinBaronApiException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Executes buy orders
 */
class BuyExecutor
{
    public function __construct(
        private readonly SkinBaronClient $client,
        private readonly BudgetManager $budgetManager,
        private readonly EntityManagerInterface $em,
        private readonly InventoryRepository $inventoryRepo,
        private readonly TransactionRepository $transactionRepo,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(
        string $saleId,
        string $marketHashName,
        string $currentPrice,
        string $targetSellPrice,
        string $expectedProfit,
        float $riskScore,
        int $tier
    ): void {
        $this->logger->info('Executing buy order', [
            'sale_id' => $saleId,
            'market_hash_name' => $marketHashName,
            'price' => $currentPrice,
            'target_sell_price' => $targetSellPrice,
            'expected_profit' => $expectedProfit,
            'risk_score' => $riskScore,
            'tier' => $tier,
        ]);

        if (!$this->budgetManager->canAfford($currentPrice)) {
            $this->logger->warning('Cannot afford purchase anymore - skipping', [
                'sale_id' => $saleId,
                'price' => $currentPrice,
                'trading_state' => $this->budgetManager->getTradingState(),
            ]);
            return;
        }

        $reservationId = "buy_{$saleId}_" . time();
        $this->budgetManager->reserve($currentPrice, $reservationId);
        
        $transaction = null;
        
        try {
            $balanceBefore = $this->budgetManager->getCurrentBalance();

            $transaction = Transaction::createBuy(
                marketHashName: $marketHashName,
                saleId: $saleId,
                price: $currentPrice,
                balanceBefore: $balanceBefore
            );
            $transaction->setStatus('pending');
            $transaction->setMetadata([
                'target_sell_price' => $targetSellPrice,
                'expected_profit' => $expectedProfit,
                'risk_score' => $riskScore,
                'tier' => $tier,
            ]);
            
            $this->em->persist($transaction);
            $this->em->flush();

            // Call API to purchase
            try {
                $result = $this->client->buyItems([$saleId]);
            } catch (SkinBaronApiException $e) {
                throw new \RuntimeException("API error: {$e->getMessage()}", 0, $e);
            }

            // Validate response
            if (!isset($result['itemsBought']) || empty($result['itemsBought'])) {
                throw new \RuntimeException('Purchase failed: No items bought');
            }

            $apiData = $result['itemsBought'][0] ?? [];
            
            // Create inventory record
            $inventory = new Inventory();
            $inventory->setSaleId($saleId);
            $inventory->setMarketHashName($marketHashName);
            $inventory->setPurchasePrice($currentPrice);
            $inventory->setPurchaseDate(new \DateTimeImmutable());
            $inventory->setTargetSellPrice($targetSellPrice);
            $inventory->setStatus('holding');
            $inventory->setRiskScore((string) $riskScore);
            
            if (isset($apiData['wearValue'])) {
                $inventory->setNotes("Wear: {$apiData['wearValue']}");
            }
            
            $this->em->persist($inventory);
            
            $transaction->setInventory($inventory);
            $transaction->markCompleted();
            
            $this->em->flush();

            $this->budgetManager->release($reservationId);
            $this->budgetManager->refreshBalance();

            $this->logger->info('Purchase completed successfully', [
                'sale_id' => $saleId,
                'market_hash_name' => $marketHashName,
                'price' => $currentPrice,
                'inventory_id' => $inventory->getId(),
                'new_balance' => $this->budgetManager->getCurrentBalance(),
            ]);

        } catch (\Exception $e) {
            $this->budgetManager->release($reservationId);
            
            if ($transaction && $this->em->isOpen()) {
                try {
                    $transaction->markFailed($e->getMessage());
                    $this->em->flush();
                } catch (\Exception $flushException) {
                    $this->logger->error('Failed to update transaction status', [
                        'error' => $flushException->getMessage(),
                    ]);
                }
            }

            $this->logger->error('Purchase failed', [
                'sale_id' => $saleId,
                'market_hash_name' => $marketHashName,
                'price' => $currentPrice,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}