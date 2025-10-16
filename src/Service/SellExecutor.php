<?php

namespace App\Service;

use App\Entity\Inventory;
use App\Entity\Transaction;
use App\Repository\InventoryRepository;
use App\Service\SkinBaron\SkinBaronClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Executes sell orders (list or adjust prices)
 */
class SellExecutor
{
    public function __construct(
        private readonly SkinBaronClient $client,
        private readonly BudgetManager $budgetManager,
        private readonly EntityManagerInterface $em,
        private readonly InventoryRepository $inventoryRepo,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Execute a sell action (list or adjust)
     * 
     * @throws \Exception if action fails
     */
    public function execute(
        int $inventoryId,
        string $saleId,
        string $marketHashName,
        string $action,
        string $listPrice,
        string $reason
    ): void {
        $this->logger->info('Executing sell action', [
            'inventory_id' => $inventoryId,
            'sale_id' => $saleId,
            'market_hash_name' => $marketHashName,
            'action' => $action,
            'list_price' => $listPrice,
            'reason' => $reason,
        ]);

        $inventory = $this->inventoryRepo->find($inventoryId);
        
        if (!$inventory) {
            $this->logger->error('Inventory item not found', ['inventory_id' => $inventoryId]);
            return;
        }

        try {
            if ($action === 'list') {
                $this->executeListAction($inventory, $listPrice, $reason);
            } elseif ($action === 'adjust') {
                $this->executeAdjustAction($inventory, $saleId, $listPrice, $reason);
            } else {
                throw new \InvalidArgumentException("Unknown action: {$action}");
            }

            $this->logger->info('Sell action completed successfully', [
                'inventory_id' => $inventoryId,
                'action' => $action,
                'price' => $listPrice,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Sell action failed', [
                'inventory_id' => $inventoryId,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            throw $e; // Re-throw for Messenger retry
        }
    }

    /**
     * List item for sale (status = holding → listed)
     */
    private function executeListAction(Inventory $inventory, string $listPrice, string $reason): void
    {
        // Fetch item from SkinBaron inventory to get itemId/appId
        $sbInventory = $this->client->getInventory();
        
        $sbItem = null;
        foreach ($sbInventory as $item) {
            if (($item['saleId'] ?? null) === $inventory->getSaleId()) {
                $sbItem = $item;
                break;
            }
        }

        if (!$sbItem) {
            throw new \RuntimeException("Item not found in SkinBaron inventory: {$inventory->getSaleId()}");
        }

        // Extract appId (needed for listing)
        $appId = $sbItem['appId'] ?? $sbItem['itemId'] ?? null;
        if (!$appId) {
            throw new \RuntimeException("No appId/itemId found for sale {$inventory->getSaleId()}");
        }

        // List item on marketplace
        $result = $this->client->listItems([
            [
                'appId' => $appId,
                'price' => (float) $listPrice,
            ]
        ]);

        if (empty($result) || !($result[0]['success'] ?? false)) {
            $errorMsg = $result[0]['msg'] ?? 'Unknown error';
            throw new \RuntimeException("List failed: {$errorMsg}");
        }

        // Store itemId if returned by API
        if (isset($result[0]['itemId'])) {
            $inventory->setItemId((string) $result[0]['itemId']);
        }

        // Update inventory status
        $inventory->setStatus('listed');
        $inventory->setListedPrice($listPrice);
        $inventory->setListedDate(new \DateTimeImmutable());

        // Add reason to notes
        $currentNotes = $inventory->getNotes() ?? '';
        $newNote = sprintf(
            "[%s] Listed at %s - Reason: %s",
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $listPrice,
            $reason
        );
        $inventory->setNotes($currentNotes ? $currentNotes . "\n" . $newNote : $newNote);

        $this->em->flush();

        $this->logger->info('Item listed successfully', [
            'inventory_id' => $inventory->getId(),
            'sale_id' => $inventory->getSaleId(),
            'list_price' => $listPrice,
            'reason' => $reason,
        ]);
    }

    /**
     * Adjust existing listing price
     */
    private function executeAdjustAction(Inventory $inventory, string $saleId, string $newPrice, string $reason): void
    {
        // Verify item is actually listed
        if ($inventory->getStatus() !== 'listed') {
            throw new \RuntimeException("Cannot adjust price - item not listed (status: {$inventory->getStatus()})");
        }

        $oldPrice = $inventory->getListedPrice();

        // Call API to adjust price
        $result = $this->client->editPriceMulti([
            [
                'saleId' => $saleId,
                'price' => (float) $newPrice,
            ]
        ]);

        if (empty($result) || !($result[0]['success'] ?? false)) {
            $errorMsg = $result[0]['msg'] ?? 'Unknown error';
            throw new \RuntimeException("Price adjustment failed: {$errorMsg}");
        }

        // Update inventory
        $inventory->setListedPrice($newPrice);

        // Add adjustment to notes
        $currentNotes = $inventory->getNotes() ?? '';
        $newNote = sprintf(
            "[%s] Price adjusted: %s → %s - Reason: %s",
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $oldPrice,
            $newPrice,
            $reason
        );
        $inventory->setNotes($currentNotes ? $currentNotes . "\n" . $newNote : $newNote);

        $this->em->flush();

        $this->logger->info('Price adjusted successfully', [
            'inventory_id' => $inventory->getId(),
            'sale_id' => $saleId,
            'old_price' => $oldPrice,
            'new_price' => $newPrice,
            'reason' => $reason,
        ]);
    }
}